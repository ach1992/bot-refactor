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
            "($_GET['state']??'0')==='1',"
            "(string)($_GET['outcome']??''));\n",
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
    cases = []
    with owned_server() as url:
        def page(msg: str, heading: str = "", state: bool = False,
                 bot: str = "AChBotTest", outcome: str = ""):
            query = urlencode({"msg": msg, "heading": heading,
                               "state": "1" if state else "0",
                               "bot": bot, "outcome": outcome})
            with urlopen(url + "?" + query, timeout=4) as response:
                return response, response.read().decode("utf-8")

        response, content = page("✅ سفارش با موفقیت تکمیل شد.",
                                 "درگاه نمونه", True, outcome="completed")
        assert response.status == 200
        assert 'lang="fa" dir="rtl"' in content
        assert 'class="panel completed"' in content
        assert "عملیات ثبت و تکمیل شد" in content
        cases.append("caller_confirmed_fulfillment_has_positive_presentation")

        _, content = page("✅ پرداخت شما با موفقیت انجام شد.",
                          "پرداخت جدید", True)
        assert 'class="panel neutral"' in content
        assert "عملیات ثبت و تکمیل شد" not in content
        cases.append("payment_positive_legacy_true_unconfirmed_delivery_never_green")

        _, content = page("✅ پرداخت شما با موفقیت انجام شد.",
                          "درگاه نمونه", True, outcome="review")
        assert 'class="panel review"' in content
        assert "نیازمند بررسی" in content
        assert "عملیات ثبت و تکمیل شد" not in content
        cases.append("verified_provider_payment_unconfirmed_delivery_needs_review")

        _, content = page("پرداخت شما انجام نشد!", "درگاه نکست پی", outcome="failed")
        assert 'class="panel failed"' in content
        assert 'پرداخت انجام نشد' in content
        assert "نیازمند بررسی" not in content
        cases.append("nextpay_authoritative_rejected_is_distinct_definitive_failure")

        _, content = page(
            "وضعیت پرداخت هنوز قطعی نیست یا استعلام در دسترس نیست؛ لطفاً بعداً دوباره بررسی کنید.",
            "درگاه نکست پی", outcome="review")
        assert 'class="panel review"' in content
        assert "پرداخت انجام نشد" not in content
        cases.append("nextpay_uncertain_verification_review_not_failure")

        _, content = page(
            "نتیجه تراکنش نیازمند تطبیق با پذیرنده است؛ سفارش لغو نشد.",
            "پیگیری زرین پال", outcome="review")
        assert 'class="panel review"' in content
        assert "پرداخت انجام نشد" not in content
        cases.append("zarinpal_uncertain_verification_review_not_failure")

        _, content = page("وضعیت تراکنش از پذیرنده قابل تایید نبود؛ سفارش محفوظ است.",
                          "پیگیری زرین پال", outcome="review")
        assert 'class="panel review"' in content
        cases.append("zarinpal_transport_uncertainty_no_false_decline")

        _, content = page(
            "پرداخت در حال بررسی خودکار است؛ برای دریافت سرویس نیازی به زدن دکمه بازگشت نیست.",
            "در حال بررسی", True, outcome="pending")
        assert 'class="panel pending"' in content
        assert "در انتظار نتیجه" in content
        cases.append("nowpayments_pending_return_requires_future_confirmation")

        _, content = page(
            "پرداخت شما ثبت شده است؛ نتیجه و لینک سرویس در ربات ارسال می‌شود.",
            "پرداخت ثبت‌شده", True, outcome="review")
        assert 'class="panel review"' in content
        cases.append("nowpayments_paid_browser_without_delivery_proof_needs_review")

        _, content = page(
            "✅ پرداخت شما با موفقیت انجام شد، مبلغ ۱۰۰۰ تومان به کیف پول شما اضافه شد.",
            "شارژ کیف پول", True, outcome="completed")
        assert 'class="panel completed"' in content
        assert "عملیات ثبت و تکمیل شد" in content
        cases.append("verified_committed_wallet_credit_can_present_completion")

        _, content = page("✅ پرداخت شما با موفقیت انجام شد. اشتراک نمونه با موفقیت تمدید شد.",
                          "تمدید اشتراک", True, outcome="completed")
        assert 'class="panel completed"' in content
        cases.append("verified_service_renewal_can_present_completion")

        _, content = page(
            "پرداخت شما با موفقیت انجام شد ولی تمدید سرویس با مشکل مواجه شد.",
            "درگاه نمونه", True, outcome="review")
        assert 'class="panel review"' in content
        assert "عملیات ثبت و تکمیل شد" not in content
        cases.append("paid_but_unresolved_service_effect_stays_review")

        _, content = page("کد پرداخت یافت نشد.", "خطا!")
        assert 'class="panel neutral"' in content
        assert "پرداخت انجام نشد" not in content
        cases.append("unknown_payment_code_is_not_authoritative_terminal_failure")

        _, content = page("درخواست شما ثبت شده است؛ نتیجه در ربات اعلام می‌شود.")
        assert 'class="panel neutral"' in content
        cases.append("untyped_invoice_information_not_inferred_as_paid_or_pending")

        _, content = page("تأیید پرداخت قطعی نیست؛ پرداخت مجدد انجام ندهید.",
                          "پیگیری پرداخت", outcome="review")
        assert 'class="panel review"' in content
        assert "پرداخت مجدد" in content
        cases.append("uncertain_reconciliation_retains_no_repayment_guidance")

        _, content = page("پرداخت تأیید شده است اما عملیات کیف پول هنوز تکمیل نشده.",
                          "افزایش کیف پول", True, outcome="review")
        assert 'class="panel review"' in content
        cases.append("wallet_effect_uncertain_never_presents_completion")

        _, content = page("✅ سفارش با موفقیت تکمیل شد.",
                          "نمونه", True, outcome="unexpected_success")
        assert 'class="panel neutral"' in content
        cases.append("unknown_presentation_enum_fails_closed")

        attack = '<img src=x onerror="alert(1)"> & "quote" <script>alert(1)</script>'
        _, content = page(attack, '<svg/onload=alert(1)>')
        assert html.escape(attack, quote=True) in content
        assert "&lt;svg/onload=alert(1)&gt;" in content
        assert "<script>" not in content and "<img src=x" not in content
        cases.append("injected_heading_and_message_escaped")

        _, content = page("رسید ارسال شد. Order: AB-123/XY (مبلغ 100,000 تومان).")
        assert "AB-123/XY" in content and 'dir="rtl"' in content
        cases.append("persian_mixed_latin_and_numeric_reference_preserved")

        _, content = page("پشتیبانی را در ربات دنبال کنید.", bot="@AChBot_Test")
        assert 'href="https://t.me/AChBot_Test"' in content
        assert 'rel="noopener noreferrer"' in content
        cases.append("valid_username_generates_only_verified_telegram_bot_link")

        for placeholder in ("REPLACE_WITH_BOT_USERNAME",
                            "@REPLACE_WITH_BOT_USERNAME",
                            "your_bot_username", "BOT_USERNAME",
                            "YOUR_BOT_NAME", "CHANGE_ME"):
            _, content = page("پیگیری سفارش", bot=placeholder)
            assert 'href=' not in content, placeholder
            assert "از داخل ربات" in content, placeholder
        cases.append("documented_and_known_unconfigured_bot_placeholders_blocked")

        for invalid in ("x", "ACh!bot", "ab/evil", "aaa?redirect=x", ""):
            _, content = page("منتظر نتیجه بمانید.", bot=invalid)
            assert 'href=' not in content, invalid
            assert "از داخل ربات" in content
        cases.append("invalid_or_missing_bot_username_has_safe_no_dead_link")

        _, content = page("در حال پردازش؛ لطفاً منتظر بمانید.")
        assert content.count("<a ") == 1
        assert 'href="https://t.me/AChBotTest"' in content
        assert "<form" not in content and "<script" not in content
        assert 'src="http' not in content
        cases.append("page_get_has_no_checkout_or_side_effect_action")

        _, content = page("آزمون " + ("طولانی test123 (۴۵۶) " * 200))
        assert content.count("test123") == 200
        assert "overflow-wrap:anywhere" in content
        cases.append("long_mixed_direction_messages_wrap_and_preserve_content")

        response, content = page("مورد نیاز به پیگیری.", outcome="review")
        headers = response.headers
        assert "no-store" in headers.get("Cache-Control", "")
        assert headers.get("Referrer-Policy") == "no-referrer"
        assert headers.get("X-Content-Type-Options") == "nosniff"
        assert "frame-ancestors 'none'" in headers.get("Content-Security-Policy", "")
        assert '<meta name="robots" content="noindex,nofollow,noarchive">' in content
        cases.append("real_http_private_page_noindex_no_referrer_no_cache_no_frame")

        assert "@media (max-width:420px)" in content
        assert "@media (forced-colors:active)" in content
        assert "focus-visible" in content
        assert ".failed .dot" in content
        cases.append("responsive_accessible_states_keyboard_and_forced_contrast")

    return {
        "complete": True, "cases": len(cases), "scenarios": cases,
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
