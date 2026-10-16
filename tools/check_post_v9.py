#!/usr/bin/env python3
"""Post-V9 Marzban-only acceptance; does NOT redefine immutable V9 parity."""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import subprocess
import sys
import tempfile
from pathlib import Path

import v9_contract as historical
import test_post_v9_customer_errors as customer_errors

ROOT = Path(__file__).resolve().parents[1]
V9 = "7eb077fd8e6d0a303421626d1500f69e295a6664"
V9_TREE = "7da17725c1e7f0448119b7e41c8b11b21f3e093d"
RETIRED_NAMES = """deleteClient editInboundRemark editInboundTraffic changeInboundState
renewInboundUuid changeClientState renewClientUuid editClientRemark editClientTraffic
deleteInbound resetIpLog resetClientTraffic addInboundAccount getNewHeaders
getConnectionLink updateConfig editInbound getJson getNewCert addUser""".split()
MARZBAN_NAMES = """getMarzbanToken getMarzbanJson getMarzbanUserInfo getMarzbanUser
getMarzbanHosts addMarzbanUser editMarzbanConfig resetMarzbanTraffic
renewMarzbanUUID deleteMarzban changeMarzbanState""".split()


def cmd(*args: str) -> bytes:
    return subprocess.check_output(["git", "-C", str(ROOT), *args])


def source_checks():
    if cmd("rev-parse", V9 + "^{tree}").decode().strip() != V9_TREE:
        raise RuntimeError("Frozen historical V9 reference changed")
    for field in ("database/legacy-schema.sql", "baseInfo.example.php"):
        if (ROOT / field).read_bytes() != cmd("show", V9+":"+field):
            raise RuntimeError("Changed historical schema/private-template: " + field)
    files=historical.members()
    if len(files)!=92 or len(set(files))!=len(files):
        raise RuntimeError("Source-only runtime file inventory changed")
    if (ROOT / "baseInfo.php").exists() or "baseInfo.php" in files:
        raise RuntimeError("Private runtime config surfaced in candidate")
    for rel in files:
        if not (ROOT / rel).is_file() or (ROOT / rel).is_symlink():
            raise RuntimeError("Missing/bad current runtime file: " + rel)
    php_files=[rel for rel in files if rel.endswith(".php")]
    for rel in php_files:
        p=subprocess.run(["php","-l",str(ROOT/rel)],capture_output=True,text=True,timeout=15)
        if p.returncode:raise RuntimeError("PHP syntax failed: "+rel+"\n"+p.stdout+p.stderr)
    # Current runtime must never write administrator identifiers to a web-root debug artifact.
    for rel in php_files:
        php_source=(ROOT/rel).read_text()
        if ("admins_list_check.txt" in php_source or
                re.search(r"print_r\s*\(\s*\$admins_list\b", php_source)):
            raise RuntimeError("Unsafe administrator-list debug dump in runtime: "+rel)
    module=(ROOT/"src/Panel/Accounts.php").read_text()
    definitions=re.findall(r"(?m)^function (\w+)\s*\(",module)
    if sorted(definitions)!=sorted(MARZBAN_NAMES):
        raise RuntimeError("Panel module still exposes legacy or lacks required Marzban methods: "+str(definitions))
    source_paths=[
        ROOT/"bot.php", ROOT/"config.php", ROOT/"buy_helpers.php",
        *ROOT.joinpath("src").rglob("*.php"),
        *ROOT.joinpath("settings").glob("*.php"),
        *ROOT.joinpath("pay").glob("*.php"),
    ]
    pattern=re.compile(r"\b("+"|".join(RETIRED_NAMES)+r")\s*\(")
    for path in source_paths:
        match=pattern.search(path.read_text())
        if match:
            raise RuntimeError("Retired provider callable referenced by active source: "+
                               str(path.relative_to(ROOT))+" "+match.group(0))
    check=(ROOT/"config.php").read_text()
    if not re.search(r"function achbotSupportsPanel\(\$type\)",check):
        raise RuntimeError("Missing explicit current supported-panel seam")
    if "return $type === 'marzban';" not in check:
        raise RuntimeError("Unexpected implicit panel support")
    bot=(ROOT/"bot.php").read_text()
    config=(ROOT/"config.php").read_text()
    if '"addNewServer"' in config:
        raise RuntimeError("Legacy XUI server registration still offered")
    if "SET `type` = ? WHERE `id`=?" in bot:
        raise RuntimeError("Old arbitrary-type selector still mutates server config")
    if "addNewMarzbanPanel" not in bot or 'addNewMarzbanPlan' not in (ROOT/"src/Plans/Administration.php").read_text():
        raise RuntimeError("Marzban setup or plan creation disappeared")
    if "/xui/inbound/" in module or "/panel/inbound/" in module or "/login" in module:
        raise RuntimeError("Obsolete XUI provider HTTP transport retained")
    old_link=(ROOT/"settings/subLink.php").read_text()
    if any(x in old_link for x in ("baseInfo.php","config.php","mysqli","curl_","getJson(")):
        raise RuntimeError("Retired old-panel subscription link still accesses real state")
    return {"source_files":len(files),"php_lint":len(php_files),
            "original_historical_schema_file_unchanged":True,
            "current_additive_migration_separately_tested":True,
            "synthetic_baseinfo_example_unchanged":True,
            "historic_contract_changed":False,"retired_provider_api_removed":True,
            "admin_list_debug_artifact_writer_absent":True,
            "future_pasarguard_or_new_xui_implemented":False}


def main():
    parser=argparse.ArgumentParser()
    parser.add_argument("--report",type=Path)
    a=parser.parse_args()
    result=source_checks()
    audience=customer_errors.verify(ROOT)
    with tempfile.TemporaryDirectory(prefix="achbot-post-v9-acceptance-") as tmp:
        native=Path(tmp)/"marzban-native.json"
        subprocess.run(["python3",str(ROOT/"tools/test_post_v9_marzban.py"),
                        "--run-disposable","--report",str(native)],
                       cwd=ROOT,check=True,timeout=300,capture_output=True)
        marzban=json.loads(native.read_text())
        if not marzban.get("complete") or not marzban.get("marzban_http_wire_sql_parity"):
            raise RuntimeError("Current candidate Marzban native source proof incomplete")
        purchase_path=Path(tmp)/"marzban-purchase.json"
        subprocess.run(["python3",str(ROOT/"tools/test_post_v9_purchases.py"),
                        "--run-disposable","--report",str(purchase_path)],
                       cwd=ROOT,check=True,timeout=300,capture_output=True)
        purchases=json.loads(purchase_path.read_text())
        if not (purchases.get("complete") and purchases.get("scenarios")==17 and
                purchases.get("php_sql_telegram_fake_provider_parity")):
            raise RuntimeError("Actual post-V9 purchase/renewal/agent SQL/Telegram parity incomplete")
        transport_cli=subprocess.run(
            ["php","-n",str(ROOT/"tools/test_post_v9_telegram_log_privacy.php")],
            cwd=ROOT,check=True,timeout=20,capture_output=True,text=True)
        transport_privacy=json.loads(transport_cli.stdout)
        if not (transport_privacy.get("complete")
                and transport_privacy.get("cases")==7
                and transport_privacy.get("network_requests")==0
                and transport_privacy.get("raw_payload_log_records")==0):
            raise RuntimeError("Telegram transport failure logs exposed private data")
        wallet_cli=subprocess.run(["php",str(ROOT/"tools/test_post_v9_wallet_guard.php")],
                                  cwd=ROOT,check=True,timeout=25,
                                  capture_output=True,text=True)
        wallet_guard=json.loads(wallet_cli.stdout)
        if not (wallet_guard.get("complete") and wallet_guard.get("cases")==7
                and wallet_guard.get("manual_cases")==7
                and wallet_guard.get("http_calls")==0
                and wallet_guard.get("committed_sql_mutations")==0):
            raise RuntimeError("Unsupported wallet checkout mutated money or failed to reject panel")
        wallet_transfer_path=Path(tmp)/"wallet-transfer-current.json"
        subprocess.run(["python3",str(ROOT/"tools/test_post_v9_wallet_transfer.py"),
                        "--run-disposable","--report",str(wallet_transfer_path)],
                       cwd=ROOT,check=True,timeout=180,capture_output=True)
        wallet_transfer=json.loads(wallet_transfer_path.read_text())
        required_transfer_cases={
            "transfer_positive_atomic", "transfer_replay_claim_consumed",
            "transfer_negative_debt_no_spend", "transfer_recipient_overflow_refused",
            "transfer_debit_failure_rolls_back_and_redacts_user_error",
            "transfer_postcommit_notification_failure_preserves_commit",
            "transfer_telegram_null_is_unconfirmed_not_success",
            "transfer_receiving_credit_offsets_debt", "transfer_two_workers_exactly_one",
            "transfer_opposing_lock_order_no_deadlock",
        }
        if not (wallet_transfer.get("complete") and wallet_transfer.get("cases")>=19
                and required_transfer_cases.issubset(wallet_transfer.get("scenarios",[]))
                and wallet_transfer.get("original_mariadb_socket_only")
                and wallet_transfer.get("external_requests")==0):
            raise RuntimeError("Current wallet transfer safety/diagnostic acceptance incomplete")
        subject_path=Path(tmp)/"payment-subjects.json"
        subprocess.run(["python3",str(ROOT/"tools/test_post_v9_subjects.py"),
                        "--run-disposable","--report",str(subject_path)],
                       cwd=ROOT,check=True,timeout=180,capture_output=True)
        subject=json.loads(subject_path.read_text())
        if not (subject.get("complete") and subject.get("cases")>=23
                and subject.get("schema_tables")==23
                and subject.get("financial_sql_mutations")==0
                and subject.get("payment_provider_requests")==0):
            raise RuntimeError("Actual original-schema payment subject preflight proof incomplete")
        catalogue_path=Path(tmp)/"catalogue-and-qr.json"
        subprocess.run(["python3",str(ROOT/"tools/test_post_v9_catalogue_qr.py"),
                        "--run-disposable","--report",str(catalogue_path)],
                       cwd=ROOT,check=True,timeout=180,capture_output=True)
        catalogue=json.loads(catalogue_path.read_text())
        if not (catalogue.get("complete") and catalogue.get("cases")>=11
                and catalogue.get("original_schema_tables")==23
                and catalogue.get("financial_mutations")==0):
            raise RuntimeError("Marzban-only catalogue, forged callback or QR proof incomplete")
        finance_path=Path(tmp)/"finance-native-routes.json"
        subprocess.run(["python3",str(ROOT/"tools/test_post_v9_finance_routes.py"),
                        "--run-disposable","--report",str(finance_path)],
                       cwd=ROOT,check=True,timeout=180,capture_output=True)
        finance=json.loads(finance_path.read_text())
        # Current R10 financial contract: preserve authenticated settlement,
        # durable compensation proof and concurrency-safe operator recovery.
        mandatory_finance = {
            "legacy_nowpayment_browser_only_read_only",
            "legacy_nowpayment_finished_read_only",
            "legacy_nowpayment_confirmed_read_only",
            "legacy_nowpayment_sending_read_only",
            "legacy_nowpayment_failed_read_only",
            "legacy_nowpayment_duplicate_invoice_refused",
            "nowpayments_checkout_url_reused_without_new_invoice",
            "nowpayments_inflight_reservation_no_double_invoice",
            "nowpayments_unknown_creation_prevents_orphan_reissue",
            "gateway_now_to_nextpay_blocked",
            "gateway_now_to_zarinpal_blocked",
            "gateway_nextpay_to_now_blocked",
            "gateway_zarinpal_to_now_blocked",
            "all_gateway_switches_blocked_on_inflight_claim",
            "nowpayments_review_alert_retry_and_throttle",
            "nowpayments_new_attempt_browser_bypass_closed",
            "nowpayments_invalid_signature_no_effect",
            "nowpayments_real_loopback_http_ipn_minimal_bootstrap",
            "nowpayments_authenticated_order_binding",
            "nowpayments_signed_ipn_durable_without_browser",
            "nowpayments_signed_confirmed_background_nonfinal",
            "nowpayments_browser_only_readonly_paid_status",
            "nowpayments_no_browser_return_final_telegram_credit",
            "nowpayments_finished_webhook_replay_no_double_credit",
            "nowpayments_multiple_children_durably_stored",
            "nowpayments_extra_child_replay_is_idempotent",
            "nowpayments_underpayment_quarantined",
            "nowpayments_terminal_partial_review_no_credit",
            "nowpayments_uncertain_marzban_no_automatic_refund",
            "nowpayments_late_payment_on_timeless_invoice",
            "nowpayments_output_short_needs_review",
            "nowpayments_output_wrong_asset_needs_review",
            "nowpayments_output_fee_short_needs_review",
            "nowpayments_locked_local_toman_price",
            "nowpayments_missing_payout_policy_fails_before_invoice",
            "nextpay_http_200_code_zero_actual_wallet_fulfillment",
            "nextpay_mid_verify_state_change_cas_preserves_paid",
            "nextpay_missing_wallet_user_atomic_rollback",
            "nextpay_signed_debt_offset_transaction",
            "nextpay_wallet_overflow_atomic_rollback",
            "zarinpal_mismatched_authority_refused",
            "zarinpal_duplicated_authority_refused",
            "zarinpal_unproven_52_retains_pending",
            "zarinpal_mid_verify_cas_preserves_paid",
            "zarinpal_authenticated_positive_wallet_credit",
            "zarinpal_browser_nok_provider_100_settled",
            "zarinpal_ok_provider_101_review",
            "zarinpal_nok_provider_101_review",
            "zarinpal_transport_preserves_pending",
            "zarinpal_malformed_preserves_pending",
            "marzban_put_valid_update_native_contract",
            "marzban_put_http_rejection_native_contract",
            "marzban_put_http_503_native_contract",
            "marzban_put_lost_response_native_contract",
            "marzban_put_bad_json_native_contract",
            "marzban_put_wrong_get_account_native_contract",
            "marzban_reset_reset_rejected_native_contract",
            "wallet_compensation_user_missing_no_claim",
            "wallet_compensation_overflow_no_claim",
            "wallet_compensation_verified_credit_and_debt_offset",
            "nowpayments_capacity_missing_user_never_done",
            "nowpayments_capacity_verified_refund_done",
            "renewal_nowpayment_explicit_failure_is_ambiguous",
            "renewal_nextpay_explicit_failure_is_ambiguous",
            "renewal_zarinpal_explicit_failure_is_ambiguous",
            "prepayment_stale_nextpay_preserves_np_invoice",
            "prepayment_stale_zarinpal_preserves_np_invoice",
            "prepayment_stale_button_allows_later_ipn",
            "prepayment_stale_button_preserves_creating",
            "nowpayments_concurrent_alert_child_highwater_cas",
            "nowpayments_concurrent_alert_worker_lease_exclusive",
            "prepayment_renewal_terminal_paid_immutable",
            "prepayment_renewal_terminal_canceled_immutable",
            "prepayment_renewal_terminal_expired_immutable",
            "prepayment_renewal_terminal_mismatched_plan_immutable",
            "prepayment_renewal_unclaimed_mismatch_cas",
            "prepayment_renewal_pending_to_paid_midflight_cas",
            "prepayment_renewal_issued_external_claim_preserved",
            "prepayment_renewal_np_invoice_nowpayment_preserved",
            "prepayment_renewal_np_invoice_zarinpal_preserved",
            "prepayment_renewal_unchanged_plan_checkout_compatible",
            "zarinpal_strict_status_canonical_int",
            "zarinpal_strict_status_canonical_string",
            "zarinpal_strict_status_fractional_string",
            "zarinpal_strict_status_fractional_float",
            "zarinpal_strict_status_scientific_string",
            "zarinpal_strict_status_scientific_float",
            "zarinpal_strict_status_float_whole",
            "zarinpal_strict_status_boolean_true",
            "zarinpal_strict_status_boolean_false",
            "zarinpal_strict_status_null",
            "zarinpal_strict_status_plus_prefix",
            "zarinpal_strict_status_zero_prefix",
            "zarinpal_strict_status_space_prefix",
            "zarinpal_strict_status_decimal_string",
            "zarinpal_strict_status_status101",
            "zarinpal_strict_status_status52",
            "zarinpal_strict_status_negative52",
        }
        cases_finance = {case.get("case") for case in finance.get("scenarios",[])
                         if case.get("pass_")}
        if not (finance.get("complete") and finance.get("cases")>=148 and
                mandatory_finance.issubset(cases_finance) and
                finance.get("schema_tables")==23 and
                finance.get("current_schema_tables")==25 and
                finance.get("additive_nowpayments_attempts_tested") and
                finance.get("original_mariadb_socket_only") and
                finance.get("synthetic_payment_spy") and
                finance.get("real_provider_requests")==0):
            raise RuntimeError("Original-schema finance and NOWPayments finality assurance incomplete")
        agency_path=Path(tmp)/"agency-state-regression.json"
        subprocess.run(["python3",str(ROOT/"tools/test_post_v9_agency.py"),
                        "--run-disposable","--report",str(agency_path)],
                       cwd=ROOT,check=True,timeout=180,capture_output=True)
        agency=json.loads(agency_path.read_text())
        if not (agency.get("complete") and agency.get("cases")>=20
                and agency.get("schema_tables")==23
                and agency.get("original_setUser_extracted")
                and agency.get("actual_current_handler_executed")
                and agency.get("admin_decline_targeted")
                and agency.get("approval_stale_and_replay_guarded")
                and agency.get("concurrent_approval_guarded")
                and agency.get("real_telegram_marzban_payment_calls")==0):
            raise RuntimeError("Post-V9 agency authorization/state regression incomplete")
        logical_path=Path(tmp)/"marzban-logical-transfer.json"
        try:
            subprocess.run(["python3",str(ROOT/"tools/test_post_v9_logical_transfer.py"),
                            "--run-disposable","--report",str(logical_path)],
                           cwd=ROOT,check=True,timeout=180,capture_output=True)
        except subprocess.CalledProcessError as exc:
            # A failed isolated child previously hid its useful traceback.
            # Surface only the bounded synthetic test stderr in Actions.
            error=(exc.stderr or b'').decode(errors='replace')[-6000:]
            raise RuntimeError("Marzban logical-group regression failed:\n"+error) from exc
        logical=json.loads(logical_path.read_text())
        if not (logical.get("complete") and logical.get("cases")>=38
                and logical.get("schema_tables")==23
                and logical.get("additive_server_info_column_verified")
                and logical.get("original_historical_schema_file_unchanged")
                and logical.get("same_panel_group_not_derived_from_url_or_username")
                and logical.get("concurrent_two_admin_group_creation_verified")
                and logical.get("pinned_v9_source_on_migrated_schema_verified")
                and logical.get("unicode_group_name_contract_verified")
                and logical.get("no_real_provider_or_payment_calls")):
            raise RuntimeError("Same-Marzban-panel logical reassignment contract incomplete")
        overlay_report=Path(tmp)/"source-overlay.json"
        subprocess.run(["python3",str(ROOT/"tools/test_post_v9_swap.py"),
                        "--run-disposable","--report",str(overlay_report)],
                       cwd=ROOT,check=True,timeout=60,capture_output=True)
        swap=json.loads(overlay_report.read_text())
        if not (swap.get("complete") and swap.get("phase_count")==4
                and swap.get("runtime_members")==92
                and swap.get("source_roll_forward_and_back_byte_identical")
                and swap.get("unchanged_original_sql_ddl")
                and swap.get("synthetic_private_files_untouched")):
            raise RuntimeError("Physical post-V9/V9 source-only overlay or private-state check failed")
        if a.report:a.report.write_text(json.dumps({"complete":True,
                    "source":result,"marzban":marzban,"purchases":purchases,
                     "unsupported_wallet_preflight":wallet_guard,
                     "wallet_transfer_safety":wallet_transfer,
                     "customer_error_privacy":audience,
                     "telegram_transport_log_privacy":transport_privacy,
                    "source_only_overlays":swap,
                    "financial_subject_preflight":subject,
                    "catalogue_and_subscription_qr":catalogue,
                    "financial_native_route_guards":finance,
                     "agency_state_regression":agency,
                    "marzban_logical_transfer":logical,
                    "historical_suite_executed_by_this_script":False,
                    "historical_suite_requires_separate_pinned_ci_execution":True,
                    "actual_operator_installed_acceptance":False,
                    "real_financial_safety_approved":False},
                    indent=2,ensure_ascii=False)+"\n")
    print(json.dumps({"complete":True,"source":result,
                      "marzban_positive":marzban["marzban_positive_cases"],
                      "legacy_negative":marzban["retired_provider_negative_cases"],
                      "purchase_scenarios":purchases["scenarios"],
                      "purchase_steps":purchases["steps"],
                       "unsupported_wallet_negatives":wallet_guard["cases"],
                       "unsupported_manual_negatives":wallet_guard["manual_cases"],
                       "wallet_transfer_scenarios":wallet_transfer["cases"],
                       "customer_messages_privacy_checked":audience["explicit_customer_messages_checked"],
                       "telegram_transport_privacy_cases":transport_privacy["cases"],
                      "verified_source_swaps":swap["phase_count"],
                      "payment_subject_cases":subject["cases"],
                      "catalogue_qr_cases":catalogue["cases"],
                      "financial_native_route_cases":finance["cases"],
                       "agency_state_cases":agency["cases"],
                      "marzban_logical_transfer_cases":logical["cases"],
                      "current_php_version":subprocess.check_output(["php","-r","echo PHP_VERSION_ID;"]).decode()}))


if __name__=="__main__":
    main()
