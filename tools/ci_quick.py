#!/usr/bin/env python3
"""Current AChBot functional CI: representative real-source regression checks.

Runs independent, socket-only disposable fixtures in parallel. Historical
migration archaeology and exhaustive negative matrices remain available in
the separate manual full-regression workflow, not on every ordinary PR.
"""
from __future__ import annotations

import argparse
from concurrent.futures import ThreadPoolExecutor, as_completed
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import time

ROOT = Path(__file__).resolve().parents[1]

# Each script owns its disposable DB/loopback server and performs assertions
# against current PHP source. Do not encode historical case-count snapshots.
NATIVE_TESTS = (
    ("telegram_delivery", "test_owner_r6_api_flow.py"),
    ("bank_api_native", "test_post_v9_scoped_api_pg.py"),
    ("subscriptions", "test_unified_existing_subscriptions.py"),
    ("customer_services", "test_unified_customer_services.py"),
    ("service_lifecycle", "test_unified_service_lifecycle.py"),
    ("wallet", "test_post_v9_wallet_transfer.py"),
    ("manual_payments", "test_post_v9_manual_payments.py"),
    ("bank_purchase", "test_post_v9_bank_buy.py"),
    ("gateway_rules", "test_post_v9_gateway_rules.py"),
    ("pasarguard_http", "test_post_v9_pasarguard_client.py"),
    ("marzban_http", "test_post_v9_marzban.py"),
    ("scoped_pg_payments", "test_post_v9_scoped_pg_payments.py"),
    ("admin_permissions", "test_owner_r5_roles.py"),
    ("checkout_races", "test_post_v9_checkout_serialization.py"),
    ("onboarding", "test_post_v9_onboarding.py"),
)

OTHER_TESTS = (
    ("source_and_cron", ("python3", "tools/check_post_v9.py", "--suite", "cron"), "targeted"),
    ("telegram_log_privacy", ("php", "-n", "tools/test_post_v9_telegram_log_privacy.php"), "stdout"),
    ("payment_browser", ("python3", "tools/test_post_v9_browser_feedback.py", "--run-disposable"), "native"),
    ("text_catalog", ("python3", "tools/test_next_text_catalog.py"), "stdout"),
)


def git(*args: str) -> str:
    return subprocess.check_output(["git", *args], cwd=ROOT, text=True).strip()


def validate_proof(name: str, mode: str, raw: str) -> dict:
    try:
        proof = json.loads(raw)
    except (ValueError, TypeError) as error:
        raise ValueError(f"{name}: missing/invalid JSON test evidence") from error
    if not isinstance(proof, dict) or not proof:
        raise ValueError(f"{name}: empty test evidence")
    if mode == "targeted":
        if not (proof.get("targeted_complete") is True and
                proof.get("complete") is False and proof.get("scope") == "cron"):
            raise ValueError(f"{name}: source/Cron checks incomplete")
    elif (proof.get("complete") is not True and proof.get("pass") is not True and
          proof.get("status") != "PASS" and proof.get("result") != "PASS"):
        raise ValueError(f"{name}: no affirmative functional test result")
    cases = proof.get("cases")
    if type(cases) is int and cases <= 0:
        raise ValueError(f"{name}: empty test cases")
    return {"cases": cases if type(cases) is int else None, "result": "PASS"}


def run_case(spec, output_dir: Path) -> dict:
    name, arguments, mode = spec
    start = time.monotonic()
    report = output_dir / (name + ".json")
    command = list(arguments)
    if mode != "stdout":
        command.extend(["--report", str(report)])
    environment = dict(os.environ)
    environment["TMPDIR"] = str(output_dir)
    proc = subprocess.run(command, cwd=ROOT, env=environment,
                          capture_output=True, text=True, errors="replace")
    elapsed = round(time.monotonic() - start, 2)
    if proc.returncode:
        return {"name": name, "status": "FAIL", "elapsed_seconds": elapsed,
                "error": f"exit {proc.returncode}: {(proc.stderr or proc.stdout)[-8000:]}"}
    try:
        evidence = proc.stdout if mode == "stdout" else report.read_text(encoding="utf-8")
        details = validate_proof(name, mode, evidence)
        return {"name": name, "status": "PASS", "elapsed_seconds": elapsed, **details}
    except (OSError, ValueError) as error:
        return {"name": name, "status": "FAIL", "elapsed_seconds": elapsed,
                "error": str(error)}


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--report", type=Path, required=True)
    args = parser.parse_args()
    if git("status", "--porcelain"):
        raise RuntimeError("CI requires the exact committed, clean source")
    php_id = int(subprocess.check_output(
        ["php", "-r", "echo PHP_VERSION_ID;"], text=True))
    if not 80500 <= php_id < 80600:
        raise RuntimeError("AChBot CI requires PHP 8.5")
    if args.report.resolve().is_relative_to(ROOT):
        raise ValueError("CI report must be outside the checkout")
    specs = [((name, ("python3", "tools/" + script, "--run-disposable"), "native"))
             for name, script in NATIVE_TESTS]
    specs.extend(OTHER_TESTS)
    workers = int(os.getenv("ACHBOT_CI_WORKERS", "3"))
    if workers < 1:
        raise ValueError("ACHBOT_CI_WORKERS must be positive")
    started = time.monotonic()
    base = Path(os.environ.get("RUNNER_TEMP") or tempfile.gettempdir())
    with tempfile.TemporaryDirectory(prefix="achbot-ci-quick-", dir=base) as folder:
        with ThreadPoolExecutor(max_workers=min(workers, len(specs))) as pool:
            results = [task.result() for task in as_completed(
                [pool.submit(run_case, spec, Path(folder)) for spec in specs])]
    order = {spec[0]: index for index, spec in enumerate(specs)}
    results.sort(key=lambda result: order[result["name"]])
    success = all(result["status"] == "PASS" for result in results)
    data = {
        "mode": "current-functional-regression",
        "full_historical_or_exhaustive_coverage": False,
        "head": git("rev-parse", "HEAD"),
        "tree": git("rev-parse", "HEAD^{tree}"),
        "php_version_id": php_id,
        "status": "PASS" if success else "FAIL",
        "elapsed_seconds": round(time.monotonic() - started, 2),
        "checks": results,
    }
    args.report.parent.mkdir(parents=True, exist_ok=True)
    args.report.write_text(json.dumps(data, indent=2, ensure_ascii=False) + "\n",
                           encoding="utf-8")
    for result in results:
        print(f"{result['status']:4} {result['name']:23} {result['elapsed_seconds']:7.2f}s",
              flush=True)
        if result["status"] != "PASS":
            print(result["error"], file=sys.stderr, flush=True)
    print(json.dumps({"status": data["status"], "mode": data["mode"],
                      "elapsed_seconds": data["elapsed_seconds"],
                      "checks": len(results)}), flush=True)
    return 0 if success else 1


if __name__ == "__main__":
    raise SystemExit(main())
