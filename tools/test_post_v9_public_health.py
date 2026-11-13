#!/usr/bin/env python3
"""Source-real, no-network HTTP health acceptance under the current PHP runtime.

Temporary PHP server uses no extensions, a fake local mysqli API, and no bot or
WordPress credentials. Historical V0-V9 fixtures are not modified or executed.
"""
from __future__ import annotations

import json
import os
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def request(url: str, method: str = "GET") -> tuple[int, dict[str, str], bytes]:
    req = urllib.request.Request(url, method=method)
    try:
        response = urllib.request.urlopen(req, timeout=3)
    except urllib.error.HTTPError as exc:
        response = exc
    with response:
        return response.status, dict(response.headers), response.read()


def main() -> dict:
    assert not (ROOT / "index.html").exists(), "old root HTML was not retired"
    index = ROOT / "index.php"
    assert index.is_file() and index.stat().st_size > 12000
    index_data = index.read_text(encoding="utf-8")
    for expected in ("session_start()", "csrf_token", '<html lang="fa" dir="rtl">',
                     'id="login"', 'id="register"', 'id="forgot"'):
        assert expected in index_data, expected

    with tempfile.TemporaryDirectory(prefix="achbot-http-health-") as td:
        root = Path(td)
        shutil.copyfile(ROOT / "health.php", root / "health.php")
        (root / "baseInfo.php").write_text(
            "<?php\n$botToken=isset($_GET['no_token'])?'':'fixture-token';\n"
            "$dbUserName='fixture';$dbPassword='fixture';$dbName='fixture';\n",
            encoding="utf-8",
        )
        (root / "fixture.php").write_text(
            """<?php
define('MYSQLI_OPT_CONNECT_TIMEOUT', 0);
define('MYSQLI_OPT_READ_TIMEOUT', 1);
class TestResult {
    public function fetch_row() { return ['1']; }
}
class TestDb {
    public function options($key, $value) {
        if (!in_array($key,[0,1],true) || $value > 2 || $value < 1) {
            throw new Exception('unbounded_db_timeout');
        }
        return true;
    }
    public function real_connect($host,$user,$pass,$name) {
        if ($host !== 'localhost' || isset($_GET['db_fail'])) {
            throw new Exception('database_unavailable');
        }
        return true;
    }
    public function query($sql) {
        if ($sql !== 'SELECT 1') throw new Exception('unexpected_query');
        return new TestResult();
    }
    public function close() {}
}
function mysqli_init() { return new TestDb(); }
""",
            encoding="utf-8",
        )
        (root / "health_cache.json").write_text(
            '{"app":"fail","checks":{"db":"fail"}}', encoding="utf-8")
        with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as sock:
            sock.bind(("127.0.0.1", 0))
            port = sock.getsockname()[1]
        php = os.environ.get("PHP_BINARY", "php")
        proc = subprocess.Popen(
            [php, "-n", "-d", "auto_prepend_file=" + str(root / "fixture.php"),
             "-S", f"127.0.0.1:{port}", "-t", str(root)],
            stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
        )
        try:
            for attempt in range(45):
                if proc.poll() is not None:
                    raise AssertionError("PHP fixture server did not start")
                try:
                    with socket.create_connection(("127.0.0.1", port), timeout=.15):
                        break
                except OSError:
                    time.sleep(.08)
            else:
                raise AssertionError("PHP fixture server startup timeout")
            url = f"http://127.0.0.1:{port}/health.php"
            started = time.monotonic()
            status, headers, body = request(url)
            assert status == 200
            assert headers["Content-Type"].startswith("application/json")
            assert "no-store" in headers["Cache-Control"]
            assert json.loads(body)["app"] == "ok"
            assert json.loads(body)["checks"] == {"db": "ok"}
            assert (time.monotonic() - started) < 2.5

            status, _, body = request(url, "HEAD")
            assert status == 200 and body == b""

            status, _, body = request(url + "?db_fail=1")
            assert status == 503
            assert json.loads(body)["checks"]["db"] == "fail"
            assert json.loads(body)["error"] == "db_unavailable"

            status, _, body = request(url + "?no_token=1")
            assert status == 503
            assert json.loads(body)["error"] == "no_bot_token"

            status, headers, body = request(url, "POST")
            assert status == 405
            assert "GET" in headers.get("Allow", "")
            assert json.loads(body)["error"] == "method_not_allowed"

            status, _, body = request(url)
            assert status == 200 and json.loads(body)["app"] == "ok"
        finally:
            proc.terminate()
            try:
                proc.wait(timeout=4)
            except subprocess.TimeoutExpired:
                proc.kill()
                proc.wait(timeout=4)
    return {"complete": True, "cases": 6, "local_http_only": True,
            "no_telegram_calls": True, "no_cache_writes": True,
            "current_index_php": True, "historical_source_unchanged": True}


if __name__ == "__main__":
    print(json.dumps(main(), sort_keys=True))
