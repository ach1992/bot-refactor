#!/usr/bin/env python3
"""Real AChBot PHP evidence HTTP diagnostics against a loopback-only responder.

Untrusted bank/gift response values must never escape into private PHP logs.
The original transport JSON and HTTP/business decision remain untouched.
"""
from __future__ import annotations

import argparse
import hashlib
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
from pathlib import Path
import re
import subprocess
import threading

import test_post_v9_diagnostics as sink_diagnostics

ROOT = Path(__file__).resolve().parents[1]
SENSITIVE = (
    "SENSITIVE_TOKEN_DO_NOT_LOG", "SENSITIVE_REQUEST_ID_DO_NOT_LOG",
    "SENSITIVE_BODY_DO_NOT_LOG", "SENSITIVE_EXCEPTION_DO_NOT_LOG",
)

PHP = r'''<?php
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(static function($level,$message){throw new ErrorException($message,0,$level);});
$_SERVER['SCRIPT_FILENAME']=__FILE__;
define('ACHBOT_PAYMENT_EVIDENCE_TEST_TRANSPORT',true);
require_once $argv[1].'/src/Finance/PaymentEvidence.php';
$spec=json_decode($argv[2],true,64,JSON_THROW_ON_ERROR);
$method=$spec['provider']==='bank_transfer'?'GET':'POST';
$base=$spec['base'].($spec['provider']==='bank_transfer'
    ?'/wp-json/ach-pay-manager/v1':'/wp-json/ach-gcw/v2');
$profile=['method'=>$spec['provider'],'base_url'=>$base,
    'token'=>'synthetic-bearer','key_id'=>'fixture-client',
    'secret'=>'synthetic-hmac-secret'];
$path=$method==='GET'?'/messages?status=ready&after_id=0&per_page=100':'/verify';
$result=achbotPaymentEvidenceHttp($profile,$method,$path,
    $method==='GET'?null:['code'=>'AG2-K1-FIXTURE']);
achbotDiagnosticEvent('achbot.finance.evidence_http_exception',
    ['class'=>'RuntimeException','reason'=>'SENSITIVE_EXCEPTION_DO_NOT_LOG'],'error');
echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
'''


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def do_GET(self):
        self.reply()

    def do_POST(self):
        self.reply()

    def reply(self):
        size = int(self.headers.get("Content-Length", "0"))
        if size:
            self.rfile.read(size)
        status, body = self.server.response
        encoded = json.dumps(body, separators=(",", ":")).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(encoded)))
        self.end_headers()
        self.wfile.write(encoded)


def test() -> dict:
    checks = []
    tests = [
        ("bank_known_success", "bank_transfer", 200,
         {"data": [], "pagination": {"page": 1}, "request_id": "0123456789abcdef01234567",
          "unrelated": SENSITIVE[2]},
         "none", "0123456789abcdef01234567"),
        ("bank_known_error", "bank_transfer", 400,
         {"code": "ach_pay_ambiguous_ingestion_time",
          "request_id": "abcdef0123456789abcdef01", "message": SENSITIVE[2]},
         "ach_pay_ambiguous_ingestion_time", "abcdef0123456789abcdef01"),
        ("bank_unknown_code_and_request", "bank_transfer", 200,
         {"data": [], "code": SENSITIVE[0], "request_id": SENSITIVE[1],
          "message": SENSITIVE[2]},
         "unrecognized_upstream_code", "sha256:"+hashlib.sha256(SENSITIVE[1].encode()).hexdigest()),
        ("bank_invalid_envelope", "bank_transfer", 200,
         {"code": "ach_pay_not_found", "request_id": "0123456789abcdef01234567",
          "message": SENSITIVE[2]},
         "unrecognized_upstream_code",
         "sha256:"+hashlib.sha256(b"0123456789abcdef01234567").hexdigest()),
        ("bank_error_code_on_success", "bank_transfer", 200,
         {"data": [], "code": "ach_pay_invalid_filter",
          "request_id": "0123456789abcdef01234567"},
         "unrecognized_upstream_code",
         "sha256:"+hashlib.sha256(b"0123456789abcdef01234567").hexdigest()),
        ("bank_noncanonical_producer_id", "bank_transfer", 200,
         {"data": [], "request_id": "achpm_6740ff.29101", "message": SENSITIVE[2]},
         "none", "sha256:"+hashlib.sha256(b"achpm_6740ff.29101").hexdigest()),
        ("gift_known_success", "gift_card", 200,
         {"api_version": "2", "ok": True, "code": "card_verified",
          "request_id": "req_"+"1a"*12, "data": {"message": SENSITIVE[2]}},
         "card_verified", "req_"+"1a"*12),
        ("gift_known_error", "gift_card", 404,
         {"api_version": "2", "ok": False, "code": "card_not_found",
          "request_id": "req_"+"2b"*12, "data": None,
          "message": SENSITIVE[2]},
         "card_not_found", "req_"+"2b"*12),
        ("gift_unknown_code_and_request", "gift_card", 200,
         {"api_version": "2", "ok": True, "code": SENSITIVE[0],
          "request_id": SENSITIVE[1], "data": {"message": SENSITIVE[2]}},
         "unrecognized_upstream_code",
         "sha256:"+hashlib.sha256(SENSITIVE[1].encode()).hexdigest()),
        ("gift_invalid_envelope", "gift_card", 200,
         {"api_version": "3", "ok": True, "code": "card_verified",
          "request_id": "req_"+"3c"*12, "data": {"message": SENSITIVE[2]}},
         "unrecognized_upstream_code",
         "sha256:"+hashlib.sha256(("req_"+"3c"*12).encode()).hexdigest()),
        ("gift_wrong_http_status", "gift_card", 200,
         {"api_version": "2", "ok": False, "code": "card_not_found",
          "request_id": "req_"+"4d"*12, "data": None,
          "message": SENSITIVE[2]},
         "unrecognized_upstream_code",
         "sha256:"+hashlib.sha256(("req_"+"4d"*12).encode()).hexdigest()),
        ("gift_error_code_on_success", "gift_card", 200,
         {"api_version": "2", "ok": True, "code": "card_not_found",
          "request_id": "req_"+"5e"*12, "data": None},
         "unrecognized_upstream_code",
         "sha256:"+hashlib.sha256(("req_"+"5e"*12).encode()).hexdigest()),
    ]
    server = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    try:
        from tempfile import TemporaryDirectory
        with TemporaryDirectory(prefix="achbot-evidence-http-diag-") as tmp:
            driver = Path(tmp)/"evidence-http.php"
            driver.write_text(PHP)
            for name, provider, status, body, code, rid in tests:
                server.response = status, body
                proc = subprocess.run([
                    "php", "-n", "-d", "extension=curl",
                    "-d", "log_errors=1", "-d", "error_log=/dev/stderr",
                    str(driver), str(ROOT),
                    json.dumps({"provider": provider,
                                "base": "http://127.0.0.1:"+str(server.server_port)}),
                ], cwd=ROOT, capture_output=True, text=True, timeout=18)
                assert proc.returncode == 0, (name, proc.stderr[-500:])
                for secret in SENSITIVE:
                    assert secret not in proc.stderr, (name, "untrusted_value_logged")
                result = json.loads(proc.stdout)
                assert result["json"] == body, (name, "HTTP result JSON changed")
                assert result["http"] == status and result["ok"] is (200 <= status < 300), (
                    name, "HTTP behavior changed", result["http"], result["ok"])
                events = []
                for raw in proc.stderr.splitlines():
                    i = raw.find("[AChBot] ")
                    assert i >= 0, (name, "unexpected_PHP_stderr", raw[:100])
                    events.append(json.loads(raw[i+len("[AChBot] "):]))
                assert len(events) == 2, (name, "event_count", events)
                e = events[0]
                assert e["event"] == "achbot.finance.evidence_http"
                assert e["http_reason"] == code, (name, "code", e)
                assert e.get("upstream_request_id") == rid, (name, "request_id", e)
                assert e["http_status"] == status, (name, "http_status")
                assert "reason" not in events[1] and events[1]["class"] == "RuntimeException"
                checks.append(name)
    finally:
        server.shutdown()
        server.server_close()
        thread.join(timeout=5)
    assert sink_diagnostics.run()["secrets_redacted"] is True
    checks.append("central_event_sink_rejects_untrusted_values")
    return {"complete": True, "cases": len(checks), "checks": checks,
            "source_real_http": True, "loopback_only": True,
            "untrusted_values_not_logged": True, "original_http_decisions_preserved": True}


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--report", type=Path)
    opts = parser.parse_args()
    report = test()
    if opts.report:
        opts.report.write_text(json.dumps(report, indent=2) + "\n")
    print(json.dumps(report))


if __name__ == "__main__":
    main()
