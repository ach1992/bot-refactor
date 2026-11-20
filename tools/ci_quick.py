#!/usr/bin/env python3
"""Current Stable PHP 8.5 functional acceptance without pre-Stable execution.

The test roster covers representative *current* customer, operator, money,
subscription, Native and Telegram workflows. Tests use isolated disposable
socket-only MariaDB and loopback providers, not production resources.
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

import package_current

ROOT = Path(__file__).resolve().parents[1]

# Expensive cases go first to minimize the tail of a finite parallel pool.
# Each native suite asserts against current PHP source using its own fixtures.
NATIVE_TESTS = (
    ("api_payments", "test_post_v9_scoped_api_pg.py"),
    ("postcutover_recovery", "test_post_v9_pg_cutover_recovery.py"),
    ("delete_reconfirmation", "test_post_v9_delete_recovery.py"),
    ("nowpayments_finality", "test_stable_nowpayments.py"),
    ("direct_gift_native", "test_post_v9_direct_gift_native.py"),
    ("automatic_renewal", "test_post_v9_automatic_renewal.py"),
    ("bank_purchase", "test_post_v9_bank_buy.py"),
    ("gateway_rules", "test_post_v9_gateway_rules.py"),
    ("wallet_checkout", "test_post_v9_wallet_checkout.py"),
    ("wallet_sconfig_finality", "test_post_v9_wallet_sconfig.py"),
    ("wallet_marzban_target", "test_post_v9_wallet_marzban_target.py"),
    ("wallet_pg_recovery", "test_post_v9_wallet_edit_recovery.py"),
    ("service_effects", "test_post_v9_service_effects.py"),
    ("pasarguard_http", "test_post_v9_pasarguard_client.py"),
    ("low_wallet_reentry", "test_post_v9_low_wallet_reentry.py"),
    ("admin_wallet", "test_post_v9_admin_wallet.py"),
    ("payment_ux", "test_post_v9_payment_ux_lifecycle.py"),
    ("nonpaid_trial_bulk", "test_post_v9_nonpaid_routes.py"),
    ("provider_registry", "test_unified_provider_registry.py"),
    ("payment_api_admin", "test_post_v9_payment_api_admin.py"),
    ("scoped_pg_payments", "test_post_v9_scoped_pg_payments.py"),
    ("onboarding", "test_post_v9_onboarding.py"),
    ("agency_current", "test_post_v9_agency.py"),
    ("api_staff_permissions", "test_post_v9_api_staff_digest.py"),
    ("browser_feedback", "test_post_v9_browser_feedback.py"),
)

OTHER_TESTS = (
    ("evidence_http_privacy", ("python3", "tools/test_post_v9_evidence_http_diagnostics.py"), "report"),
    ("manual_unknown_redrive",
     ("python3", "tools/test_owner_r6_api_flow.py", "--run-disposable", "--ci-redrive"),
     "report"),
    ("cron_runner", ("python3", "tools/test_post_v9_cron_runner.py"), "report"),
    ("payment_reference", ("python3", "tools/test_post_v9_payment_identity.py"), "stdout"),
    ("telegram_log_privacy", ("php", "-n", "tools/test_post_v9_telegram_log_privacy.php"), "stdout"),
    ("telegram_presentation", ("php", "-n", "tools/test_post_v9_telegram_presentation.php"), "stdout"),
    ("telegram_html_callsite_parity", ("python3", "tools/test_post_v9_telegram_html_callsite_parity.py"), "stdout"),
    ("current_copy_ownership", ("python3", "tools/test_post_v9_current_copy_ownership.py"), "stdout"),
    ("current_web_copy_parity", ("python3", "tools/test_post_v9_web_copy_parity.py"), "stdout"),
    ("current_copy_relocation_parity", ("python3", "tools/test_post_v9_copy_relocation_parity.py"), "stdout"),
    ("telegram_html_discount_compositions",
     ("python3", "tools/test_post_v9_telegram_html_discount_compositions.py"), "stdout"),
    ("telegram_html_wallet_reports",
     ("python3", "tools/test_post_v9_telegram_html_wallet_reports.py"), "stdout"),
    ("telegram_html_composed_notices",
     ("python3", "tools/test_post_v9_telegram_html_composed_notices.py"), "stdout"),
    ("telegram_html_management_gift",
     ("python3", "tools/test_post_v9_telegram_html_management_gift.py"), "stdout"),
    ("telegram_html_plan_prompts",
     ("python3", "tools/test_post_v9_telegram_html_plan_prompts.py"), "stdout"),
    ("marzban_bearer", ("python3", "tools/test_post_v9_marzban_bearer.py"), "stdout"),
)


def git(*args: str) -> str:
    return subprocess.check_output(["git", *args], cwd=ROOT, text=True).strip()


def check_source_syntax(workers: int) -> dict:
    started = time.monotonic()
    names = package_current.members()

    def lint(name: str) -> str | None:
        proc = subprocess.run(["php", "-l", str(ROOT / name)],
                              capture_output=True, text=True, errors="replace")
        if proc.returncode:
            return name + ": " + (proc.stderr or proc.stdout)[-2000:]
        return None

    with ThreadPoolExecutor(max_workers=workers) as executor:
        results = list(executor.map(lint, names))
    errors = [x for x in results if x is not None]
    result = {"name": "current_source_syntax",
              "status": "PASS" if not errors else "FAIL",
              "elapsed_seconds": round(time.monotonic() - started, 2),
              "cases": len(names)}
    if errors:
        result["error"] = "\n".join(errors[:12])
    return result


def validate_proof(name: str, raw: str) -> dict:
    try:
        proof = json.loads(raw)
    except (ValueError, TypeError) as error:
        raise ValueError(f"{name}: missing/invalid JSON test evidence") from error
    if not isinstance(proof, dict) or not proof:
        raise ValueError(f"{name}: empty test evidence")
    if (proof.get("complete") is not True and proof.get("pass") is not True and
            proof.get("status") != "PASS" and proof.get("result") != "PASS"):
        raise ValueError(f"{name}: no affirmative current functional test result")
    cases = proof.get("cases")
    if type(cases) is int and cases <= 0:
        raise ValueError(f"{name}: empty test cases")
    return {"cases": cases if type(cases) is int else None}


def run_case(spec, output_dir: Path) -> dict:
    name, arguments, mode = spec
    started = time.monotonic()
    report = output_dir / (name + ".json")
    command = list(arguments)
    if mode != "stdout":
        command.extend(["--report", str(report)])
    environment = dict(os.environ)
    environment["TMPDIR"] = str(output_dir)
    try:
        proc = subprocess.run(command, cwd=ROOT, env=environment,
                              capture_output=True, text=True, errors="replace")
        if proc.returncode:
            raise RuntimeError(f"exit {proc.returncode}: {(proc.stderr or proc.stdout)[-7500:]}")
        proof = proc.stdout if mode == "stdout" else report.read_text(encoding="utf-8")
        details = validate_proof(name, proof)
        return {"name": name, "status": "PASS",
                "elapsed_seconds": round(time.monotonic() - started, 2), **details}
    except (OSError, ValueError, RuntimeError) as error:
        return {"name": name, "status": "FAIL",
                "elapsed_seconds": round(time.monotonic() - started, 2),
                "error": str(error)}


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--report", type=Path, required=True)
    args = parser.parse_args()
    if git("status", "--porcelain"):
        raise RuntimeError("CI requires the exact committed, clean checkout")
    php_id = int(subprocess.check_output(
        ["php", "-r", "echo PHP_VERSION_ID;"], text=True))
    if not 80500 <= php_id < 80600:
        raise RuntimeError("Current Stable acceptance requires PHP 8.5")
    if args.report.resolve().is_relative_to(ROOT):
        raise ValueError("Report must live outside source checkout")

    workers = int(os.getenv("ACHBOT_CI_WORKERS", "4"))
    if workers < 1:
        raise ValueError("ACHBOT_CI_WORKERS must be positive")

    started = time.monotonic()
    source = check_source_syntax(workers)
    results = [source]
    if source["status"] == "PASS":
        tests = [(name, ("python3", "tools/" + script, "--run-disposable"), "report")
                 for name, script in NATIVE_TESTS]
        tests.extend(OTHER_TESTS)
        if len({spec[0] for spec in tests}) != len(tests):
            raise RuntimeError("Duplicate Stable regression test name")
        temp_base = Path(os.environ.get("RUNNER_TEMP") or tempfile.gettempdir())
        with tempfile.TemporaryDirectory(prefix="achbot-ci-stable-", dir=temp_base) as folder:
            work = Path(folder)
            # The bank-purchase fixture seeds both original-schema databases.
            # Running that seed concurrently with the long PHP/API MariaDB
            # fixture loop can starve one genuine SQL import beyond its
            # existing 30-second deadline on a small CI runner. Exercise the
            # bank case once, alone, then retain parallelism for the rest.
            # Never turn a timed-out/failed fixture into a synthetic PASS.
            isolated = next((case for case in tests if case[0] == "bank_purchase"), None)
            if isolated is None:
                raise RuntimeError("bank_purchase acceptance missing")
            early = run_case(isolated, work)
            remaining = [case for case in tests if case[0] != isolated[0]]
            with ThreadPoolExecutor(max_workers=min(workers, len(remaining))) as pool:
                futures = [pool.submit(run_case, spec, work) for spec in remaining]
                executed = {x["name"]: x for task in as_completed(futures)
                            for x in [task.result()]}
            executed[isolated[0]] = early
            results.extend(executed[spec[0]] for spec in tests)

    success = all(item["status"] == "PASS" for item in results)
    data = {
        "mode": "stable-current-functional",
        "pre_stable_execution": False,
        "exhaustive_suite_claim": False,
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
    for case in results:
        print(f"{case['status']:4} {case['name']:23} {case['elapsed_seconds']:7.2f}s",
              flush=True)
        if case["status"] != "PASS":
            print(case.get("error", "Unknown failure"), file=sys.stderr, flush=True)
    print(json.dumps({"status": data["status"], "mode": data["mode"],
                      "elapsed_seconds": data["elapsed_seconds"],
                      "checks": len(results)}), flush=True)
    return 0 if success else 1


if __name__ == "__main__":
    raise SystemExit(main())
