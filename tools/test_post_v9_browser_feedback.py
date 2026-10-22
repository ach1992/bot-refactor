#!/usr/bin/env python3
"""Issue #51: real HTTP PHP browser feedback semantics, safety and Persian RTL.

Uses only the shared page Renderer and an owned loopback PHP server.
No payment provider, Telegram, database, installed customer or payment route.
"""
from __future__ import annotations

import argparse
from contextlib import contextmanager
import html
import json
import os
from pathlib import Path
import signal
import socket
import subprocess
import tempfile
import time
from urllib.parse import urlencode
from urllib.request import urlopen

ROOT = Path(__file__).resolve().parents[1]
PAGE = ROOT / "src/Finance/PaymentPage.php"


@contextmanager
def owned_server():
    with tempfile.TemporaryDirectory(prefix="achbot-browser-feedback-") as temp:
        root = Path(temp)
        (root / "index.php").write_text(
            '<?php\n'
            "$botUsername=(string)($_GET['bot']??'AChBotTest');\n"
            "require " + repr(str(PAGE)).replace('\\\\', '/') + ";\n"
            "achbotRenderPaymentPage((string)($_GET['msg']??''),"
            "(string)($_GET['heading']??''),"
            "($_GET['state']??'0')==='1');\n",
            encoding="utf-8",
        )
        with socket.socket() as sock:
            sock.bind(("127.0.0.1", 0))
            port = sock.getsockname()[1]
        proc = subprocess.Popen(
            ["php", "-n", "-S", f"127.0.0.1:{port}", "-t", str(root)],
            stdout=subprocess.DEVNULL, stderr=subprocess.PIPE,
            start_new_session=True, text=True,
        )
        try:
            deadline = time.monotonic() + 4
            while time.monotonic() < deadline:
                if proc.poll() is not None:
                    raise RuntimeError("php fixture exited " + str(proc.returncode))
                try:
                    with socket.create_connection(("127.0.0.1", port), timeout=.2):
                        break
                except OSError:
                    time.sleep(.04)
            else:
                raise RuntimeError("isolated PHP fixture not ready")
            yield f"http://127.0.0.1:{port}/"
        finally:
            if proc.poll() is None:
                os.killpg(proc.pid, signal.SIGTERM)
                try:
                    proc.communicate(timeout=2)
                except subprocess.TimeoutExpired:
                    os.killpg(proc.pid, signal.SIGKILL)
                    proc.communicate(timeout=2)
            else:
                proc.communicate()


def exercise():
    names = []
    with owned_server() as url:
        def page(msg: str, heading: str = "", state: bool = False,
                 bot: str = "AChBotTest"):
            query = urlencode({"msg": msg, "heading": heading,
                               "state": "1" if state else "0", "bot": bot})
            with urlopen(url + "?" + query, timeout=4) as response:
                content = response.read().decode("utf-8")
                return response, content

        response, result = page("✅ سفارش با موفقیت تکمیل شد.", "درگاه نمونه", True)
        assert response.status == 200
        assert 'lang="fa" dir="rtl"' in result
        assert 'class="panel success"' in result
        assert "نتیجه ثبت شد" in result
        assert "تکمیل" in result
        names.append("verified_flag_positive_copy_clear_result_without_unearned_delivery")

        response, result = page(
            "⚠️ پرداخت تأیید شده است اما تحویل سرویس هنوز قطعی نیست.",
            "درگاه نمونه", True)
        assert 'class="panel caution"' in result
        assert "نیازمند پیگیری" in result
        assert "نتیجه ثبت شد" not in result
        names.append("warning_precedence_over_success_flag_for_partial_delivery")

        _, result = page("پرداخت شما انجام نشد!", "درگاه نکست پی")
        assert 'class="panel caution"' in result
        names.append("definitive_failed_payment_not_neutral")

        _, result = page("کد پرداخت یافت نشد.", "خطا!")
        assert 'class="panel caution"' in result
        names.append("invalid_payment_and_error_heading_not_positive")

        _, result = page("پرداخت در حال بررسی خودکار است؛ نیازی به بازگشت نیست.",
                         "وضعیت پرداخت")
        assert 'class="panel pending"' in result
        assert "در انتظار نتیجه" in result
        names.append("processing_reconciler_state_visible_without_fake_completion")

        _, result = page("درخواست شما ثبت شده است؛ نتیجه در ربات اعلام می‌شود.")
        assert 'class="panel pending"' in result
        names.append("registered_payment_evidence_not_fulfillment")

        _, result = page("پرداخت شما با موفقیت انجام شد ولی تمدید سرویس با مشکل مواجه شد.",
                         "درگاه", True)
        assert 'class="panel caution"' in result
        names.append("failed_extension_after_payment_no_false_green_status")

        _, result = page("اطلاعات اولیه نمایش داده شد.", "نتیجه پرداخت", True)
        assert 'class="panel neutral"' in result
        names.append("legacy_true_without_completion_evidence_cannot_assert_finality")

        _, result = page("تأیید پرداخت قطعی نیست؛ پرداخت مجدد انجام ندهید.",
                         "پیگیری پرداخت")
        assert 'class="panel caution"' in result
        names.append("uncertain_or_multiple_charge_no_repay_call_to_action")

        _, result = page("پرداخت تأیید شده است اما عملیات کیف پول هنوز تکمیل نشده.",
                         "افزایش کیف پول", True)
        assert 'class="panel caution"' in result
        names.append("wallet_effect_uncertain_not_paid_and_delivered")

        attack = '<img src=x onerror="alert(1)"> & "quote" <script>alert(1)</script>'
        _, result = page(attack, '<svg/onload=alert(1)>')
        assert html.escape(attack, quote=True) in result
        assert "&lt;svg/onload=alert(1)&gt;" in result
        assert "<script>" not in result and "<img src=x" not in result
        names.append("injected_heading_and_message_escaped")

        _, result = page("رسید ارسال شد. Order: AB-123/XY (مبلغ 100,000 تومان).")
        assert "AB-123/XY" in result and 'dir="rtl"' in result
        names.append("persian_mixed_latin_and_numeric_reference_preserved")

        _, result = page("پشتیبانی را در ربات دنبال کنید.", bot="@AChBot_Test")
        assert 'href="https://t.me/AChBot_Test"' in result
        assert 'rel="noopener noreferrer"' in result
        names.append("valid_username_generates_only_verified_telegram_bot_link")

        for invalid in ("x", "ACh!bot", "ab/evil", "aaa?redirect=x", ""):
            _, result = page("منتظر نتیجه بمانید.", bot=invalid)
            assert 'href=' not in result, invalid
            assert "از داخل ربات" in result
        names.append("invalid_or_missing_bot_username_has_safe_no_dead_link")

        _, result = page("در حال پردازش؛ لطفاً منتظر بمانید.")
        assert result.count("<a ") == 1
        assert 'href="https://t.me/AChBotTest"' in result
        assert "<form" not in result and "<script" not in result
        assert 'src="http' not in result
        assert "پرداخت مجدد" not in result
        names.append("page_get_has_no_checkout_or_side_effect_action")

        _, result = page("آزمون " + ("طولانی test123 (۴۵۶) " * 200))
        assert result.count("test123") == 200
        assert "overflow-wrap:anywhere" in result
        names.append("long_mixed_direction_messages_wrap_and_preserve_content")

        response, result = page("مورد نیاز به پیگیری.")
        header = response.headers
        assert "no-store" in header.get("Cache-Control", "")
        assert header.get("Referrer-Policy") == "no-referrer"
        assert header.get("X-Content-Type-Options") == "nosniff"
        assert "frame-ancestors 'none'" in header.get("Content-Security-Policy", "")
        assert '<meta name="robots" content="noindex,nofollow,noarchive">' in result
        names.append("real_http_private_page_noindex_no_referrer_no_cache_no_frame")

        assert "@media (max-width:420px)" in result
        assert "@media (forced-colors:active)" in result
        assert "focus-visible" in result
        names.append("responsive_keyboard_and_forced_contrast_support")

    return {
        "complete": True, "cases": len(names), "scenarios": names,
        "source_real_php_http": True, "loopback_only": True,
        "database_or_financial_calls": 0, "external_provider_calls": 0,
        "production_mutations": 0,
    }


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--run-disposable", action="store_true", required=True)
    parser.add_argument("--report", type=Path)
    args = parser.parse_args()
    output = exercise()
    if args.report:
        args.report.write_text(json.dumps(output, ensure_ascii=False, indent=2) + "\n")
    print(json.dumps(output, ensure_ascii=False))
