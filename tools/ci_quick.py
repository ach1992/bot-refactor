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
    ("wallet_first_ledger", "test_wallet_first_ledger.py"),
    ("wallet_first_cutover_openings", "test_wallet_first_cutover.py"),
    ("wallet_first_credit", "test_wallet_first_credit.py"),
    ("wallet_first_funding", "test_wallet_first_funding.py"),
    ("wallet_first_retirement", "test_wallet_first_retirement.py"),
    ("wallet_first_fresh_schema", "test_wallet_first_fresh_schema.py"),
    ("current_payment_readiness", "test_post_v9_payment_readiness.py"),
    ("wallet_first_hosted", "test_wallet_first_hosted.py"),
    ("wallet_first_manual", "test_wallet_first_manual.py"),
    ("wallet_first_service_execution", "test_wallet_first_service_execution.py"),
    # PASS characterizes the current unsafe remote ordering; not acceptance.
    ("native_write_counterexample", "test_wallet_first_native_write_fence.py"),
    ("native_import_name_reuse", "test_post_v9_import_name_reuse.py"),
    ("native_import_alias_duplicate", "test_post_v9_import_alias_duplicate.py"),
    ("delete_reconfirmation", "test_post_v9_delete_recovery.py"),
    ("operational_report_fanout", "test_operational_report_fanout.py"),
    ("operational_report_outbox", "test_operational_report_outbox.py"),
    ("direct_gift_native", "test_post_v9_direct_gift_native.py"),
    ("scheduled_gift_native", "test_post_v9_scheduled_gift_native.py"),
    ("gateway_rules", "test_post_v9_gateway_rules.py"),
    ("indexed_payment_locks", "test_post_v9_payment_lock_scope.py"),
    ("pasarguard_http", "test_post_v9_pasarguard_client.py"),
    ("admin_wallet", "test_post_v9_admin_wallet.py"),
    ("payment_ux", "test_post_v9_payment_ux_lifecycle.py"),
    ("nonpaid_trial_bulk", "test_post_v9_nonpaid_routes.py"),
    ("subscription_status_copy", "test_post_v9_subscription_status_detail.py"),
    ("management_menu_context", "test_unified_management_menus.py"),
    ("payment_api_admin", "test_post_v9_payment_api_admin.py"),
    ("onboarding", "test_post_v9_onboarding.py"),
    ("agency_current", "test_post_v9_agency.py"),
    ("api_staff_permissions", "test_post_v9_api_staff_digest.py"),
    ("browser_feedback", "test_post_v9_browser_feedback.py"),
)

OTHER_TESTS = (
    ("simple_server_display", ("php", "tools/test_simple_server_display.php"), "stdout"),
    ("wallet_transfer", ("python3", "tools/test_post_v9_wallet_transfer.py",
        "--run-disposable", "--current-only"), "report"),
    ("evidence_http_privacy", ("python3", "tools/test_post_v9_evidence_http_diagnostics.py"), "report"),
    ("cron_runner", ("python3", "tools/test_post_v9_cron_runner.py"), "report"),
    ("payment_reference", ("python3", "tools/test_post_v9_payment_identity.py"), "stdout"),
    ("wallet_callback_dispatch", ("python3", "tools/test_post_v9_wallet_callback_dispatch.py"), "stdout"),
    ("telegram_log_privacy", ("php", "-n", "tools/test_post_v9_telegram_log_privacy.php"), "stdout"),
    ("telegram_presentation", ("php", "-n", "tools/test_post_v9_telegram_presentation.php"), "stdout"),
    ("telegram_html_callsite_parity", ("python3", "tools/test_post_v9_telegram_html_callsite_parity.py"), "stdout"),
    ("current_copy_ownership", ("python3", "tools/test_post_v9_current_copy_ownership.py"), "stdout"),
    ("current_context_parity", ("python3", "tools/test_post_v9_context_catalog_parity.py"), "stdout"),
    ("main_menu_wire_parity", ("python3", "tools/test_post_v9_main_menu.py"), "stdout"),
    ("telegram_wire_parity", ("python3", "tools/test_post_v9_telegram_wire_parity.py"), "stdout"),
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
)


# Replaced execution families must be covered by affirmative named cases,
# not merely removed from the roster. See docs/post-v9/CI-VALIDATION.md.
REQUIRED_CURRENT_CASES = {
    "wallet_first_service_execution": (
        "missing_current_report_attempt_no_legacy_fallback",
        "actual_long_checkout_and_auto_renew_storage_bound",
        "current_schema_source_execution_without_migrations",
        "five_real_wallet_callbacks_owner_schema_and_replay_fences",
        "actual_low_wallet_callback_switch_to_manual",
        "invalid_native_names_never_debit_or_allocate",
        "retired_external_renewal_and_provider_denied",
        "actual_bulk_checkout_draft",
        "actual_custom_draft_and_review_completion_truth",
        "receipt_projection_original_funding_service_and_delivery",
        "service_report_original_receipt_and_wallet_debit",
        "receipt_projection_resolved_original_never_reopened",
        "physical_change_retires_current_draft_not_historical_receipt",
        "current_draft_scoped_to_own_plan_journal",
        "plan_prewrite_outage_and_abandoned_preparation_recover_without_manual_hold",
        "native_mutation_replay_history_has_no_lifetime_count_ceiling",
        "plan_lost_ack_recovers_with_current_rotated_credential",
        "plan_correction_prewrite_outage_does_not_consume_dispatch",
        "funded_buy_allows_actual_credential_rotation",
        "resolution_refund_after_logical_server_delete",
        "pre_dispatch_native_target_equality_not_our_effect",
        "actual_renew_checkout_draft",
        "actual_scoped_day_manual_checkout_draft",
        "actual_scoped_volume_manual_checkout_draft",
        "historical_prepaid_claim_no_adoption_or_new_effect",
        "automatic_renewal_without_historical_table",
        "automatic_renewal_alias_single_charge_original_notice",
        "alias_fence_and_lost_put_absolute_addons",
        "local_commit_failure_recovery",
        "reset_ack_with_new_traffic_no_repeat_on_put_outage",
        "manual_rejection_cancels_original_and_recovers_notice_without_money",
        "actual_private_operator_delivery_and_report_confirmation_once",
        "resolution_full_refund_once_and_reports",
        "stalled_funded_worker_visible_without_failure_code",
        "unknown_delivery_part_visible_even_with_pending_aggregate",
        "resolution_partial_buy_exact_allocation_and_delivery",
        "resolution_existing_native_blocks_refund_and_immediate_retry",
        "resolution_independent_native_state_and_unrelated_field",
        "resolution_close_releases_account_and_next_operation",
        "resolution_edit_refund_and_known_applied_fence",
        "resolution_auto_renewal_service_only_and_reenable",
        "referral_excludes_current_agent",
        "referral_excludes_agent_bulk",
        "referral_customer_purchase_agent_inviter_eligible",
        "referral_actual_funding_actor_common_audience",
    ),
    "wallet_first_manual": (
        "bank_transfer_actual_issue_and_manual_approval",
        "actual_approver_owner_channel_reports_without_duplicate_money",
        "report_channel_malformed_financial_finality",
        "configured_username_case_normalization",
        "manual_rejection_dedup_owner_actor_channel_9001",
        "manual_rejection_dedup_owner_actor_channel_9002",
        "superseded_api_wallet_report_searchable_and_delivered",
        "gift_card_actual_issue_and_manual_approval",
        "manual_usdt_actual_issue_and_manual_approval",
        "manual_trx_actual_issue_and_manual_approval",
        "actual_explicit_timer",
        "actual_bank_scan_lost_remote_ack_service_invalidated",
        "superseded_submitted_api_receipt_credits_without_service",
        "superseded_receiving_ack_and_recovery_all_photo_methods",
        "superseded_receipt_preserves_configured_timer_policy",
        "typed_gift_claim_atomic_and_superseded_recovery",
        "superseded_unstarted_receipt_not_reopened",
        "current_manual_approval_cooldown_preserves_configured_policy",
        "current_review_cooldown_does_not_block_original_recovery",
        "historical_and_automatic_payments_do_not_create_manual_cooldown",
        "api_maintenance_rotates_broken_pin_and_skips_historical_projection",
        "bank_filtered_search_resumes_after_page_budget",
        "superseded_unfunded_receipt_reject_without_archive",
        "actual_gift_proven_local_rollback_recovery_reject_denied",
        "bank_transfer_lost_receipt_ack_recovery_and_independent_report",
        "gift_card_lost_receipt_ack_recovery_and_independent_report",
        "manual_usdt_lost_receipt_ack_recovery_and_independent_report",
        "manual_trx_lost_receipt_ack_recovery_and_independent_report",
        "rejected_receipt_cannot_reopen_archive_callback_inert",
        "typed_gift_lost_receipt_ack_recovery",
    ),
    "wallet_first_hosted": (
        "hosted_rejection_shared_outbox_late_verified_credit_no_service",
        "hosted_rejection_actual_actor_owner_channel",
        "hosted_rejection_preserves_independently_funded_service",
        "actual_public_nextpay_checkout_and_return",
        "actual_public_zarinpal_checkout_and_return",
        "actual_public_nowpayment_checkout_and_return",
        "signed_child_verified_exact_quote_once",
        "actual_admission_lost_creation_original_secret_ipn_recovery_and_poll_once",
    ),
    "wallet_first_funding": (
        "duration_storage_boundary_before_wallet_debit",
        "custom_duration_overflow_refused",
        "registered_native_gateway_same_engines_once",
        "registered_gateway_foreign_evidence_rejected_by_core",
        "registered_new_manual_method_same_credit_once",
    ),
    "wallet_first_retirement": (
        "checkout_command_schema_missing:wallet_schema_gate",
        "checkout_command_schema_partial:wallet_schema_gate",
        "checkout_command_schema_invalid:wallet_schema_gate",
        "accepted:schema_accepted",
    ),
}

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
    if name in REQUIRED_CURRENT_CASES:
        reported = proof.get("checks", proof.get("case_names", []))
        if not isinstance(reported, list):
            raise ValueError(f"{name}: missing named current cases")
        for case in REQUIRED_CURRENT_CASES[name]:
            for mode in ("strict", "off"):
                if f"{mode}:{case}" not in reported and f"{mode}_{case}" not in reported:
                    raise ValueError(f"{name}: missing {mode}:{case}")
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
            # The obsolete bank-purchase double-schema fixture no longer
            # runs. Schedule the current service/Native suites directly.
            with ThreadPoolExecutor(max_workers=min(workers, len(tests))) as pool:
                futures = [pool.submit(run_case, spec, work) for spec in tests]
                executed = {x["name"]: x for task in as_completed(futures)
                            for x in [task.result()]}
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
