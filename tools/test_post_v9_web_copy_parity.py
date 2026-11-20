#!/usr/bin/env python3
"""Exactly pin the existing standalone login page and JSON response copy.

Reference is the prior current Stable index.php at exact accepted main SHA.
No pre-Stable bot, network, payment gateway or live instance is executed.
"""
from __future__ import annotations

import hashlib
import json
from pathlib import Path
import re
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[1]
CURRENT_STABLE_BASE = "270840a854b06bbe899b76249db68e4748d93739"
EXPECTED_REFERENCE_SHA256 = {
    "get": "2d557ea3f6486090db3647133bda5c35075ef3134466a9ad8ef378e5ec96c098",
    "post-csrf": "c874a286b81f1cf90b42e2bff362a9d8a367b2ea4c560c919d862a6503819989",
    "post-login": "4dde3cf87243259fe425d64f3adbb562d0e408e4574a9c97b37c39b72842820d",
    "post-register": "15abdfc0bd9603a02d17c70fd898154237d3c9b69c5e480f0725065f8a1b0389",
    "post-forgot": "de38c548a70343204917b8f2bff4e4f63c0b17ac094cdb192780400c58430bd9",
    "post-invalid": "9e58b34f21dbe749770db6c3a70b4f19b0193d5cea671d791379d8a6baf8d207",
}
SAMPLES = (
    ("get", None, False),
    ("post-csrf", "login", False),
    ("post-login", "login", True),
    ("post-register", "register", True),
    ("post-forgot", "forgot", True),
    ("post-invalid", "unknown", True),
)
JS_VISIBLE_STRING = re.compile(r"""(?P<q>['"])(?P<value>[\u0600-\u06ff][^'"\\\r\n]*)(?P=q)""")


def check(value: bool, message: str) -> None:
    if not value:
        raise AssertionError(message)


def render(source: Path, sessions: Path, action: str | None,
           valid_csrf: bool) -> bytes:
    php = ('session_id("fixture");session_start();'
           '$_SESSION["csrf_token"]="stable_fixture";session_write_close();')
    if action is None:
        php += '$_SERVER["REQUEST_METHOD"]="GET";'
    else:
        php += ('$_SERVER["REQUEST_METHOD"]="POST";'
                '$_SERVER["HTTP_X_REQUESTED_WITH"]="XMLHttpRequest";'
                '$_POST=["action"=>' + json.dumps(action) +
                ',"csrf_token"=>' +
                json.dumps("stable_fixture" if valid_csrf else "invalid") +
                '];')
    php += 'require ' + json.dumps(str(source)) + ';'
    result = subprocess.run([
        "php", "-n", "-d", "display_errors=0",
        "-d", "session.save_path=" + str(sessions), "-r", php
    ], cwd=ROOT, capture_output=True, timeout=20)
    check(result.returncode == 0 and result.stderr == b"",
          "PHP_login_runtime_failed:" + str(result.returncode) + ":" +
          result.stderr.decode(errors="replace")[-350:])
    return result.stdout


def js_normalize(body: str) -> str:
    # Codegen uses a JSON-quoted JS literal instead of the old single quotes.
    # Evaluate the literal-equivalent Unicode contents, not quote style.
    return JS_VISIBLE_STRING.sub(
        lambda m: "'" + m.group("value") + "'", body
    )


def assert_rendered(before: bytes, after: bytes, case: str) -> None:
    if case != "get":
        check(before == after, "browser_json_copy_changed:" + case)
        return
    old = before.decode("utf-8")
    new = after.decode("utf-8")
    check("<script>" in old and "<script>" in new,
          "login_script_missing")
    old_html, old_script = old.split("<script>", 1)
    new_html, new_script = new.split("<script>", 1)
    check(old_html == new_html, "browser_HTML_markup_or_visible_copy_changed")
    check(js_normalize(old_script) == js_normalize(new_script),
          "browser_JS_behavior_or_visible_copy_changed")


def main() -> None:
    baseline = subprocess.check_output([
        "git", "show", CURRENT_STABLE_BASE + ":index.php"
    ], cwd=ROOT)
    with tempfile.TemporaryDirectory(prefix="achbot-current-browser-copy-") as tmp:
        root = Path(tmp)
        reference = root / "reference-index.php"
        reference.write_bytes(baseline)
        checks = 0
        for case, action, valid in SAMPLES:
            original = render(reference, root, action, valid)
            check(hashlib.sha256(original).hexdigest() ==
                  EXPECTED_REFERENCE_SHA256[case],
                  "frozen_stable_browser_reference_drift:" + case)
            candidate = render(ROOT / "index.php", root, action, valid)
            assert_rendered(original, candidate, case)
            checks += 1
    original_source = baseline.decode()
    current_source = (ROOT / "index.php").read_text()
    check("web_login." not in original_source and
          "require_once __DIR__ . '/messages/loader.php'" in current_source,
          "browser_catalog_binding_missing")
    check(not re.search(r"[\u0600-\u06ff]", current_source),
          "uncatalogued_login_page_copy")
    check("JSON_HEX_TAG" in current_source and
          "ENT_QUOTES" in current_source,
          "browser_context_escaping_missing")
    print(json.dumps({
        "complete": True, "status": "PASS",
        "current_stable_baseline": CURRENT_STABLE_BASE,
        "cases": checks, "HTML_prefix_bytes_exact": True,
        "JS_user_visible_literals_equivalent": True,
        "response_payload_bytes_exact": True,
        "two_catalog_source": True, "network_calls": 0,
    }, ensure_ascii=False))


if __name__ == "__main__":
    main()
