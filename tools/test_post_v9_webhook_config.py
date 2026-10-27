#!/usr/bin/env python3
"""Per-install baseInfo webhook auth using actual bot.php bytes over loopback HTTP."""
from __future__ import annotations
import argparse
import http.client
import json
import os
from pathlib import Path
import socket
import subprocess
import tempfile
import time

ROOT = Path(__file__).resolve().parents[1]
BASE = "ea6834cb0612b5fa687ec492fbd9c6e1fd0b6a50"
SECRET_A = "OwnedBotAlpha_2026_secret_001"
SECRET_B = "OwnedBotBeta_2026_secret_002"
SHARED = "OwnedSharedProcess_secret_003"


def require(ok, why):
    if not ok:
        raise RuntimeError(why)


def prefix(source):
    end = source.index("require_once 'config.php';") + len("require_once 'config.php';")
    require(source.index("if (!achbotWebhookIngressAuthenticated())") < end,
            "Actual auth no longer precedes configuration bootstrap")
    return source[:end]


def request(port, path, secret=None, method="POST"):
    conn = http.client.HTTPConnection("127.0.0.1", port, timeout=5)
    try:
        headers = {} if secret is None else {"X-Telegram-Bot-Api-Secret-Token": secret}
        conn.request(method, path, body="{}", headers=headers)
        resp = conn.getresponse()
        return resp.status, resp.read().decode()
    finally:
        conn.close()


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--run-disposable", action="store_true", required=True)
    ap.add_argument("--report", type=Path)
    opts = ap.parse_args()
    scenarios = []
    source = (ROOT / "bot.php").read_text()
    baseline = subprocess.check_output(
        ["git", "-C", str(ROOT), "show", BASE + ":bot.php"], text=True)
    with tempfile.TemporaryDirectory(prefix="achbot-webhook-owned-") as temp:
        root = Path(temp)
        def stage(name, config, bot=source):
            folder = root / name
            folder.mkdir()
            (folder / "bot.php").write_text(prefix(bot))
            if config is not None:
                (folder / "baseInfo.php").write_text(
                    "<?php\n$dbName='owned_" + name + "';\n" + config + "\n")
            # Simulate the actual later require_once and verify the first
            # include's private variable bindings survived in global scope.
            (folder / "config.php").write_text(
                "<?php\nrequire_once __DIR__.'/baseInfo.php';\n"
                "if (($dbName??null)!=='owned_" + name + "') {http_response_code(500);exit;}\n"
                "file_put_contents(__DIR__.'/bootstrap.marker','entered');\n"
                "echo 'owned_" + name + "';\nexit;\n")
        stage("alpha", "$telegramWebhookSecret='" + SECRET_A + "';")
        stage("beta", "$telegramWebhookSecret='" + SECRET_B + "';")
        stage("baseline", "$telegramWebhookSecret='" + SECRET_A + "';", baseline)
        for name, value in [
            ("absent", ""), ("short", "'tiny'"), ("long", "'" + "a" * 257 + "'"),
            ("array", "[]"), ("null", "null"), ("integer", "12345"),
            ("spaces", "'invalid secret value 2026'"), ("unicode", "'سکرت_آزمایشی_۲۰۲۶'"),
        ]:
            stage(name, "" if value == "" else "$telegramWebhookSecret=" + value + ";")
        stage("missing_file", None)
        with socket.socket() as sock:
            sock.bind(("127.0.0.1", 0))
            port = sock.getsockname()[1]
        log_path = root / "server.log"
        with log_path.open("w") as log:
            child = subprocess.Popen([
                "php", "-n", "-d", "display_errors=0", "-d", "log_errors=1",
                "-d", "allow_url_fopen=0", "-S", "127.0.0.1:" + str(port),
                "-t", str(root)
            ], cwd=root, env={**os.environ, "ACHBOT_TELEGRAM_WEBHOOK_SECRET": SHARED},
                stdin=subprocess.DEVNULL, stdout=log, stderr=log)
            try:
                deadline = time.monotonic() + 8
                while True:
                    if child.poll() is not None:
                        raise RuntimeError("Owned loopback PHP server exited early")
                    try:
                        with socket.create_connection(("127.0.0.1", port), timeout=.1):
                            break
                    except OSError:
                        require(time.monotonic() < deadline, "Owned server startup timeout")
                        time.sleep(.025)
                # Previous runtime ignores the private field and fails even
                # though the installation has the correct Telegram header.
                require(request(port, "/baseline/bot.php", SECRET_A)[0] == 403,
                        "RC10 baseline no longer demonstrates the file-secret defect")
                scenarios.append("baseline_red_file_secret_ignored")
                for name, secret in [("alpha", SECRET_A), ("beta", SECRET_B)]:
                    require(request(port, "/" + name + "/bot.php", secret) ==
                            (200, "owned_" + name), "Per-instance valid secret rejected")
                    require((root / name / "bootstrap.marker").exists(),
                            "Valid header failed to reach globally bound config")
                    scenarios.append(name + "_own_file_secret_global_config_pass")
                # Same process and environment serve both bots. Each accepts
                # ONLY its own file secret. Shared environment cannot grant access.
                for name, secret in [("alpha", SECRET_B), ("beta", SECRET_A),
                                     ("alpha", SHARED), ("beta", SHARED),
                                     ("alpha", None), ("beta", "wrong-secret")]:
                    marker = root / name / "bootstrap.marker"
                    marker.unlink(missing_ok=True)
                    status, body = request(port, "/" + name + "/bot.php", secret)
                    require(status == 403 and not marker.exists() and body == "",
                            "Rejected ingress initialized downstream configuration")
                scenarios.append("cross_bot_shared_env_missing_wrong_denied_before_config")
                for method in ("GET", "HEAD", "PUT"):
                    marker = root / "alpha/bootstrap.marker"
                    marker.unlink(missing_ok=True)
                    require(request(port, "/alpha/bot.php", SECRET_A, method)[0] == 403
                            and not marker.exists(), "Non-POST initialized config")
                scenarios.append("non_post_denied_before_config")
                for name in ("absent", "short", "long", "array", "null", "integer",
                             "spaces", "unicode", "missing_file"):
                    status, body = request(port, "/" + name + "/bot.php", SHARED)
                    require(status == 403 and body == "" and
                            not (root / name / "bootstrap.marker").exists(),
                            "Missing/invalid local secret allowed environment fallback")
                scenarios.append("invalid_or_absent_local_secret_fails_closed")
            finally:
                child.terminate()
                try:
                    child.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    child.kill()
                    child.wait(timeout=5)
        logs = log_path.read_text()
        require(all(secret not in logs for secret in (SECRET_A, SECRET_B, SHARED)),
                "Ingress operational log exposed configured/header secret")
        scenarios.append("operational_log_excludes_secrets")
    report = dict(complete=True, scenarios=scenarios, cases=len(scenarios),
                  baseline_red=True, real_php_http=True, loopback_only=True,
                  production_mutations=0, external_provider_calls=0)
    if opts.report:
        opts.report.write_text(json.dumps(report, indent=2) + "\n")
    print(json.dumps(report))


if __name__ == "__main__":
    main()
