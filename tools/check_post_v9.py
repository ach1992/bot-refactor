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
import package_post_v9 as package
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
    files=package.members()
    if len(files)!=93 or len(set(files))!=len(files):
        raise RuntimeError("Source-only runtime file inventory changed")
    if any((ROOT/name).exists() or name in files
           for name in package.RETIRED_RUNTIME_MEMBERS):
        raise RuntimeError("Retired automatic TRON / WeSwap PHP runtime retained")
    if 'CryptoAndExchange.php' in (ROOT/'bot.php').read_text():
        raise RuntimeError("Removed legacy crypto route still wired to bot")
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
    # Merchant payment references are untrusted URL/Telegram capabilities.
    # Verify current CSPRNG source and callback-data bounds, not test stubs.
    payment_identity_output=subprocess.run(
        ["python3",str(ROOT/"tools/test_post_v9_payment_identity.py")],
        cwd=ROOT,check=True,timeout=20,capture_output=True,text=True)
    payment_identity=json.loads(payment_identity_output.stdout)
    if not (payment_identity.get("complete") is True and
            payment_identity.get("crypto_source")=="random_int" and
            payment_identity.get("default_length")==20 and
            payment_identity.get("unique_generated")==500 and
            payment_identity.get("longest_callback_bytes",999)<=64):
        raise RuntimeError("Payment capability randomness/bounds incomplete")
    audience=customer_errors.verify(ROOT)
    with tempfile.TemporaryDirectory(prefix="achbot-post-v9-acceptance-") as tmp:
        cron_path=Path(tmp)/"post-v9-cron-runner.json"
        subprocess.run(["python3",str(ROOT/"tools/test_post_v9_cron_runner.py"),
                        "--report",str(cron_path)],
                       cwd=ROOT,check=True,timeout=65,capture_output=True)
        cron=json.loads(cron_path.read_text())
        required_cron_cases = {
            "per_installation_private_lock_created",
            "first_create_then_reuse_same_inode",
            "later_run_reuses_stable_lock_inode",
            "atomic_first_creation_does_not_start_second_batch",
            "five_simultaneous_first_invocations_finish_one_batch",
            "overlapping_tick_skips_without_extra_jobs",
            "different_installation_runs_while_first_locked",
            "first_tick_finishes_full_batch_after_overlap",
            "orphaned_worker_retains_lock_after_parent_kill",
            "new_run_recovers_after_orphan_worker_exit",
            "stale_lock_file_does_not_block_next_tick",
            "unavailable_lock_directory_fails_closed",
            "lock_directory_inside_webroot_fails_closed",
            "symlinked_lock_directory_alias_denied",
            "group_writable_unprotected_lock_directory_denied",
            "symlink_lock_path_fails_closed",
            "dangling_symlink_rejected_without_target_creation",
            "fifo_preexisting_lock_rejected_without_block",
            "shared_sticky_lock_directory_and_preempted_name_denied",
            "writable_nonsticky_ancestor_denied",
            "missing_posix_effective_uid_denied",
            "permissive_lock_file_fails_closed",
            "unavailable_php_flock_fails_closed",
        }
        if not (cron.get("complete") and cron.get("cases")>=35
                and required_cron_cases.issubset(cron.get("scenarios", []))
                and cron.get("actual_current_runner_executed")
                and cron.get("isolated_child_php_processes")
                and cron.get("actual_cron_or_bot_job_executed") is False
                and cron.get("production_database_or_service_modified") is False):
            raise RuntimeError("Optional CLI Cron coordinator native execution assurance incomplete")
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
        privacy_path=Path(tmp)/"finance-error-audience.json"
        subprocess.run(["python3",str(ROOT/"tools/test_post_v9_finance_error_privacy.py"),
                        "--run-disposable","--report",str(privacy_path)],
                       cwd=ROOT,check=True,timeout=180,capture_output=True)
        finance_privacy=json.loads(privacy_path.read_text())
        if not (finance_privacy.get("complete") and finance_privacy.get("cases")==23
                and finance_privacy.get("mutant_privacy_failures_proved")==2
                and finance_privacy.get("external_requests")==0):
            raise RuntimeError("Executed finance error privacy/uncertain-effects proof incomplete")
        required_transfer_cases={
            "transfer_positive_atomic", "transfer_replay_claim_consumed",
            "transfer_negative_debt_no_spend", "transfer_recipient_overflow_refused",
            "transfer_debit_failure_rolls_back_and_redacts_user_error",
            "transfer_postcommit_notification_failure_preserves_commit",
            "transfer_telegram_null_is_unconfirmed_not_success",
            "transfer_receiving_credit_offsets_debt", "transfer_two_workers_exactly_one",
            "transfer_opposing_lock_order_no_deadlock",
            "sender_duplicate_userid_fails_closed",
            "recipient_duplicate_userid_fails_closed",
            "transfer_concurrent_registration_duplicate_identity_rejected",
            "transfer_real_commit_lost_ack_outcome_unknown",
            "transfer_false_commit_return_is_unknown",
        }
        if not (wallet_transfer.get("complete") and wallet_transfer.get("cases")>=24
                and required_transfer_cases.issubset(wallet_transfer.get("scenarios",[]))
                and wallet_transfer.get("original_mariadb_socket_only")
                and wallet_transfer.get("external_requests")==0):
            raise RuntimeError("Current wallet transfer safety/diagnostic acceptance incomplete")
        admin_wallet_path=Path(tmp)/"admin-wallet-adjustment-current.json"
        subprocess.run(["python3",str(ROOT/"tools/test_post_v9_admin_wallet.py"),
                        "--run-disposable","--report",str(admin_wallet_path)],
                       cwd=ROOT,check=True,timeout=180,capture_output=True)
        admin_wallet=json.loads(admin_wallet_path.read_text())
        required_admin_wallet={
            "missing_005_fails_closed_at_ui",
            "missing_005_fails_closed_at_commit",
            "credit_full_ui_and_single_atomic_audit",
            "replayed_update_no_second_credit",
            "cross_operation_id_reuse_refused",
            "nonthrowing_mysqli_duplicate_key_cannot_credit",
            "routine_debit_cannot_create_debt",
            "routine_debit_guarded_and_audited",
            "explicit_privileged_negative_debt",
            "routine_debit_cannot_deepen_debt",
            "credit_offsets_signed_debt",
            "nonadmin_step_forgery_no_effect",
            "strict_amount_input_no_decimal_exponent_or_overflow",
            "int32_signed_bounds_preserved",
            "concurrent_admin_updates_exactly_once",
            "wallet_sql_failure_rolls_back_audit",
            "silent_wallet_update_error_rolls_back_journal",
            "postcommit_telegram_failure_preserves_effect",
            "schema_without_unique_identity_fails_closed",
            "schema_wrong_binary_identity_collation_fails_closed",
            "schema_incompatible_reason_contract_fails_closed",
            "schema_nontransactional_engine_fails_closed",
            "duplicate_target_refused_at_selection",
            "duplicate_target_rejected_before_financial_change",
            "duplicate_operator_rejected_before_financial_change",
            "operator_and_target_same_identity_atomic",
            "f1_old_inline_bot_text_cannot_submit_reason",
            "f1_managepanel_finance_module_never_commits",
            "f1_mixed_callback_message_envelope_refused",
            "f1_edited_service_channel_updates_refused",
            "f1_privileged_debt_needs_new_message_reason",
            "f1_bound_actor_chat_message_text_and_update_identity",
            "f1_real_new_text_commits_exactly_once_after_callbacks",
            "f2_users_myisam_with_innodb_005_refused",
            "f2_users_wallet_int32_contract_required",
            "f2_users_operator_step_contract_required",
            "f2_users_admin_identity_contract_required",
            "delegated_admin_revoked_before_commit_refused",
            "r1_group_callback_cannot_begin_admin_adjustment",
            "r1_group_user_selection_cannot_advance_adjustment",
            "r1_group_amount_cannot_advance_adjustment",
            "r1_genuine_admin_group_reason_no_financial_effect",
            "r1_private_chat_identity_must_equal_operator",
            "r1_group_reentry_cannot_override_pending_debt",
            "r1_genuine_private_reason_after_group_commits_once",
            "r1_delayed_or_out_of_order_reason_update_refused",
            "r1_monotonic_new_private_reason_can_commit",
            "r2_actual_bot_managepanel_cancels_and_announces_no_funds",
            "r2_actual_managepanel_cancels_every_financial_stage",
            "r2_unrelated_callback_not_financial_authorization",
            "r3_finance_commit_wins_stale_managepanel_no_false_cancel",
            "r3_navigation_wins_loaded_financial_reason_no_money",
            "r3_duplicate_navigation_only_one_confirmed_cancellation",
            "r3_nonthrowing_sql_error_preserves_step_and_neutral_notice",
            "r3_stale_navigation_does_not_clobber_new_operator_step",
            "r3_delegated_admin_revocation_guarded_cancellation",
        }
        if not (admin_wallet.get("complete") and admin_wallet.get("cases")==56
                and required_admin_wallet.issubset(admin_wallet.get("scenarios",[]))
                and admin_wallet.get("real_php_workers")==2
                and admin_wallet.get("disposable_db") is True
                and admin_wallet.get("production_mutations")==0
                and admin_wallet.get("external_provider_calls")==0):
            raise RuntimeError("Administrator wallet journal and debt proof incomplete")
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
        # Issue #51: source-real bot message/guarded-link lifecycle on
        # disposable original-schema MariaDB plus optional 003/006 overlays.
        payment_ux_path=Path(tmp)/"payment-ux-lifecycle.json"
        subprocess.run(["python3",str(ROOT/"tools/test_post_v9_payment_ux_lifecycle.py"),
                        "--run-disposable","--report",str(payment_ux_path)],
                       cwd=ROOT,check=True,timeout=140,capture_output=True)
        payment_ux=json.loads(payment_ux_path.read_text())
        required_payment_ux={
            "missing_003_fails_closed_and_preserves_draft",
            "uninstalled_006_cannot_overwrite_manual_receipt_id",
            "committed_newer_draft_expires_old_without_deleting_pays",
            "another_customer_draft_does_not_collide",
            "exact_thirty_minute_unclaimed_deadline",
            "provider_payid_never_cancelled",
            "provider_ledger_review_never_expired_or_deleted",
            "manual_receipt_not_expired",
            "fresh_and_superseded_payment_method_guard",
            "different_extension_variants_same_account_supersede",
            "different_extension_accounts_do_not_supersede",
            "newer_provider_claim_held_for_review_not_cancelled",
            "aged_unclaimed_payment_method_blocked",
            "zero_timestamp_unclaimed_quote_can_be_expired_safely",
            "paid_three_old_keyboards_removed_retry_after_edit_failure",
            "over_ten_messages_markup_failure_rotates_without_starvation",
            "markup_only_removes_links_but_does_not_claim_text_synced",
            "provider_review_removes_buttons_without_fake_settlement",
            "owner_qualified_clicked_message_tracked",
            "two_concurrent_expiry_workers_exactly_one_mutation",
            "media_caption_and_buttons_updated_after_receipt_review",
            "incomplete_003_schema_blocks_expiry",
            # Owner-accepted issued/unacted invoice lifecycle across methods.
            "idle_six_hour_bank_transfer_terminal_without_forgetting_claim",
            "clicked_bank_transfer_persists_upload_intent_not_paid",
            "idle_six_hour_gift_card_terminal_without_forgetting_claim",
            "clicked_gift_card_persists_upload_intent_not_paid",
            "idle_six_hour_manual_usdt_terminal_without_forgetting_claim",
            "clicked_manual_usdt_persists_upload_intent_not_paid",
            "idle_six_hour_manual_trx_terminal_without_forgetting_claim",
            "clicked_manual_trx_persists_upload_intent_not_paid",
            "manual_evidence_lease_not_expired",
            "bounded_manual_window_policy_changes_effective_deadline",
            "hosted_link_attention_not_provider_claim_cancellation",
            "hosted_attention_window_is_bounded_and_configurable",
            "manual_click_cannot_revive_overdue_quote_under_concurrency",
            "per_method_audience_all_agent_buyer_none_fail_closed",
            "configurable_duplicate_approved_cooldown_keeps_safe_default",
            "duplicate_receipt_review_window_configurable_only_inside_bounds",
            "f4_explicit_null_malformed_audience_denied_absent_legacy_compatible",
        }
        if not (payment_ux.get("complete")
                and required_payment_ux.issubset(payment_ux.get("scenarios",[]))
                and payment_ux.get("source_real_php")
                and payment_ux.get("original_schema_unchanged")
                and payment_ux.get("production_mutations")==0
                and payment_ux.get("external_provider_requests")==0):
            raise RuntimeError("Guarded payment-message lifecycle proof incomplete")

        # Issue #51: real HTTP output of the shared Persian browser page,
        # independent of return/callback settlement. No provider or DB calls.
        browser_path=Path(tmp)/"payment-browser-feedback.json"
        browser_check=subprocess.run(
            ["python3",str(ROOT/"tools/test_post_v9_browser_feedback.py"),
             "--run-disposable","--report",str(browser_path)],
            cwd=ROOT,capture_output=True,text=True,timeout=35)
        if browser_check.returncode!=0:
            raise RuntimeError("Real browser payment feedback test failed:\n"+
                (browser_check.stderr or browser_check.stdout)[-4000:])
        browser_feedback=json.loads(browser_path.read_text())
        browser_required={
            "verified_flag_positive_copy_clear_result_without_unearned_delivery",
            "warning_precedence_over_success_flag_for_partial_delivery",
            "definitive_failed_payment_not_neutral",
            "invalid_payment_and_error_heading_not_positive",
            "processing_reconciler_state_visible_without_fake_completion",
            "registered_payment_evidence_not_fulfillment",
            "failed_extension_after_payment_no_false_green_status",
            "legacy_true_without_completion_evidence_cannot_assert_finality",
            "uncertain_or_multiple_charge_no_repay_call_to_action",
            "wallet_effect_uncertain_not_paid_and_delivered",
            "injected_heading_and_message_escaped",
            "persian_mixed_latin_and_numeric_reference_preserved",
            "valid_username_generates_only_verified_telegram_bot_link",
            "invalid_or_missing_bot_username_has_safe_no_dead_link",
            "page_get_has_no_checkout_or_side_effect_action",
            "long_mixed_direction_messages_wrap_and_preserve_content",
            "real_http_private_page_noindex_no_referrer_no_cache_no_frame",
            "responsive_keyboard_and_forced_contrast_support",
        }
        if not (browser_feedback.get("complete") is True
                and set(browser_feedback.get("scenarios",[]))==browser_required
                and browser_feedback.get("cases")==len(browser_required)
                and browser_feedback.get("source_real_php_http") is True
                and browser_feedback.get("loopback_only") is True
                and browser_feedback.get("database_or_financial_calls")==0
                and browser_feedback.get("external_provider_calls")==0
                and browser_feedback.get("production_mutations")==0):
            raise RuntimeError("Issue #51 shared payment browser feedback acceptance incomplete")

        # Review F1/F2/F3/F4/R1: three provider issuance races, reverse
        # pre-lock timestamps, permanent supersession, SQL rollback, actual
        # renewal picker and full order-target status callback.
        checkout_race_path=Path(tmp)/"checkout-serialization.json"
        subprocess.run(["python3",str(ROOT/"tools/test_post_v9_checkout_serialization.py"),
                        "--run-disposable","--report",str(checkout_race_path)],
                       cwd=ROOT,check=True,timeout=130,capture_output=True)
        checkout_race=json.loads(checkout_race_path.read_text())
        required_checkout_race={
            "f1_raced_old_nowpayment_cannot_issue_after_committed_successor",
            "f1_raced_old_nextpay_cannot_issue_after_committed_successor",
            "f1_raced_old_zarinpal_cannot_issue_after_committed_successor",
            "f1_failed_reservation_rolls_back_before_nonidempotent_provider",
            "f1_interrupted_successor_locking_read_rolls_back_without_provider",
            "f4_real_gateway_rejects_explicit_invalid_audience_0",
            "f4_real_gateway_rejects_explicit_invalid_audience_1",
            "f4_real_gateway_rejects_explicit_invalid_audience_2",
            "f4_real_gateway_rejects_explicit_invalid_audience_3",
            "f4_real_gateway_rejects_explicit_invalid_audience_4",
            "f4_real_gateway_rejects_explicit_invalid_audience_5",
            "f2_real_renewal_shared_crypto_selector_trx_only",
            "f2_real_renewal_shared_crypto_selector_usdt_only",
            "f2_real_renewal_shared_crypto_selector_both",
            "f2_real_renewal_shared_crypto_selector_neither",
            "f2_forged_crypto_selector_cannot_start_other_users_claim",
            "f3_same_target_RENEW_ACCOUNT",
            "f3_same_target_INCREASE_DAY_11_4",
            "f3_same_target_INCREASE_VOLUME_11_4",
            "f3_unrelated_subscription_not_superseded",
            "f3_old_provider_claim_never_cancelled",
            # Current old-payment removal must reject unknown methods in
            # actual first-party callback dispatch before financial mutation.
            "generic_unsupported_payment_callback_no_effect_WeSwap",
            "generic_unsupported_payment_callback_no_effect_TronWallet",
            "generic_unsupported_payment_callback_no_effect_UnsupportedGateway",
            # Reviewer R1: two real source-serialized PHP draft workers
            # commit in reverse request_date order; pays.id is authoritative.
            "r1_reverse_prelock_clock_old_nowpayment_refused_after_later_id_commit",
            "r1_reverse_prelock_clock_only_latest_nowpayment_reserves",
            "r1_bot_owned_link_expires_superseded_old_id_nowpayment",
            "r1_direct_expiry_uses_authoritative_id_not_earlier_timestamp_nowpayment",
            "r1_reverse_prelock_clock_old_nextpay_refused_after_later_id_commit",
            "r1_reverse_prelock_clock_only_latest_nextpay_reserves",
            "r1_bot_owned_link_expires_superseded_old_id_nextpay",
            "r1_direct_expiry_uses_authoritative_id_not_earlier_timestamp_nextpay",
            "r1_reverse_prelock_clock_old_zarinpal_refused_after_later_id_commit",
            "r1_reverse_prelock_clock_only_latest_zarinpal_reserves",
            "r1_bot_owned_link_expires_superseded_old_id_zarinpal",
            "r1_direct_expiry_uses_authoritative_id_not_earlier_timestamp_zarinpal",
            "r1_maintenance_expires_older_id_when_latest_timestamp_is_earlier",
            # Permanent supersession: later terminal-state changes cannot
            # re-enable payment links or duplicate provider invoices.
            "r1_terminal_expired_cannot_reactivate_older_quote",
            "r1_terminal_declined_cannot_reactivate_older_quote",
            "r1_terminal_mismatched_plan_cannot_reactivate_older_quote",
        }
        if not (checkout_race.get("complete")
                and required_checkout_race.issubset(checkout_race.get("scenarios",[]))
                and checkout_race.get("cases")>=len(required_checkout_race)
                and checkout_race.get("source_real_php") is True
                and checkout_race.get("owned_mariadb_only") is True
                and checkout_race.get("real_provider_requests")==0
                and checkout_race.get("production_mutations")==0):
            raise RuntimeError("Reviewer F1/F2/F3/F4/R1 checkout and callback proof incomplete")

        # Independent review R2 BLOCKER: first-time manual destination
        # issue and direct wallet debit MUST share owner-row serial order with
        # replacement draft INSERTs. Test both commit orders, SQL/lock faults,
        # interrupted owner FOR UPDATE and deliberate inverted-lock deadlocks.
        method_race_path=Path(tmp)/"customer-method-serialization.json"
        subprocess.run(["python3",
                        str(ROOT/"tools/test_post_v9_method_selection_race.py"),
                        "--run-disposable","--report",str(method_race_path)],
                       cwd=ROOT,check=True,timeout=160,capture_output=True)
        method_race=json.loads(method_race_path.read_text())
        required_method_race=set()
        for method in ("bank_transfer","gift_card","manual_usdt","manual_trx","wallet"):
            required_method_race.update({
                "r2_"+method+"_replacement_commits_first_no_old_ledger_or_debit",
                "r2_"+method+"_old_commits_first_new_draft_waits_evidence_intact",
                "r2_"+method+"_nonthrowing_mysqli_failure_no_claim_debit_or_handler",
            })
        for method in ("bank_transfer","gift_card"):
            required_method_race.add(
                "r2_"+method+"_stale_telegram_callback_does_not_show_destination")
        for method in ("bank_transfer","wallet"):
            required_method_race.update({
                "r2_"+method+"_owner_lock_timeout_fails_closed_without_side_effect",
                "r2_"+method+"_interrupted_owner_lock_no_financial_effect",
                "r2_"+method+"_inverted_lock_deadlock_victim_safe_atomic_outcome",
                "r2_"+method+"_lost_commit_ack_only_durable_claim_may_be_recovered",
            })
        if not (method_race.get("complete") is True
                and required_method_race.issubset(method_race.get("scenarios",[]))
                and method_race.get("cases")==len(required_method_race)
                and method_race.get("source_real_php") is True
                and method_race.get("owned_mariadb_only") is True
                and method_race.get("external_effects")==0
                and method_race.get("production_mutations")==0):
            raise RuntimeError("Reviewer R2 manual/wallet side-effect serialization proof incomplete")

        # Independent R3 financial authorization: the reject-only panel
        # preview must complete OUTSIDE the wallet transaction. Under
        # REPEATABLE READ its first nonlocking SELECT otherwise pins stale
        # audience/plan data before the owner lock. Validate actual source
        # with independent PHP/MySQLi sessions and a timed preview barrier.
        r3_path=Path(tmp)/"wallet-authorization-freshness.json"
        subprocess.run(["python3",str(ROOT/"tools/test_post_v9_wallet_authorization_freshness.py"),
                        "--run-disposable","--report",str(r3_path)],
                       cwd=ROOT,check=True,timeout=125,capture_output=True)
        wallet_freshness=json.loads(r3_path.read_text())
        required_r3={
            "r3_repeatable_read_change_agents_authorization_fresh",
            "r3_repeatable_read_change_non_agents_authorization_fresh",
            "r3_repeatable_read_change_buyer_authorization_fresh",
            "r3_repeatable_read_change_non_buyer_authorization_fresh",
            "r3_repeatable_read_change_plan_authorization_fresh",
            "r3_repeatable_read_unchanged_agents_authorization_fresh",
            "r3_repeatable_read_unchanged_buyer_authorization_fresh",
            "r3_repeatable_read_unchanged_non_buyer_authorization_fresh",
            "r3_repeatable_read_unchanged_plan_authorization_fresh",
            "r3_read_committed_change_agents_authorization_fresh",
            "r3_read_committed_change_plan_authorization_fresh",
            "r3_read_committed_unchanged_plan_authorization_fresh",
        }
        if not (wallet_freshness.get("complete") is True
                and wallet_freshness.get("cases")==len(required_r3)
                and required_r3.issubset(wallet_freshness.get("scenarios",[]))
                and wallet_freshness.get("source_real_php") is True
                and wallet_freshness.get("owned_mariadb_only") is True
                and wallet_freshness.get("default_repeatable_read_verified") is True
                and wallet_freshness.get("other_isolation_read_committed_verified") is True
                and wallet_freshness.get("remote_provider_calls")==0
                and wallet_freshness.get("production_mutations")==0):
            raise RuntimeError("Independent R3 wallet final audience/panel freshness proof incomplete")

        # PR #60 latest independent review F1-F4 (separate from earlier
        # historical F1-F4): five source-real RED/GREEN suites for every
        # legacy wallet route, new claim policy, concurrent admin settings,
        # and late panel/plan revocation. Both InnoDB isolation modes and
        # both wallet/draft commit orders are mandatory.
        required_legacy_wallet=set()
        for route in ('renewal','day','volume'):
            required_legacy_wallet.add('f1_'+route+'_read_committed_old_red')
            for iso in ('read_committed','repeatable_read'):
                required_legacy_wallet.update({
                    'f1_'+route+'_'+iso+'_new_green',
                    'f1_'+route+'_'+iso+'_wallet_first_draft_waits',
                })
        required_hosted_audience=set()
        for provider in ('nowpayment','zarinpal','nextpay'):
            for mode in ('policy','cohort'):
                prefix='f2_hosted_'+provider+'_'+mode
                required_hosted_audience.update({
                    prefix+'_old_red_read_committed',
                    prefix+'_new_green_read_committed',
                    prefix+'_new_green_repeatable_read',
                })
        for provider,flag in (
            ('nowpayment','nowPaymentWallet'),
            ('nowpayment','nowPaymentOther'),
            ('zarinpal','zarinpal'),('nextpay','nextpay')):
            prefix='n1_hosted_'+provider+'_'+flag
            required_hosted_audience.update({
                prefix+'_primary_old_red_read_committed',
                prefix+'_primary_new_green_read_committed',
                prefix+'_primary_new_green_repeatable_read',
                prefix+'_positive_new_green_read_committed',
            })
        required_manual_audience={
            'f2_manual_'+method+suffix
            for method in ('bank_transfer','gift_card','manual_usdt','manual_trx')
            for suffix in ('_old_red','_new_green')
        }
        for method in ('bank_transfer','gift_card','manual_usdt','manual_trx'):
            for variant in ('primary','initially_off'):
                required_manual_audience.update({
                    'n1_manual_'+method+'_'+variant+'_old_red',
                    'n1_manual_'+method+'_'+variant+'_new_green',
                })
            required_manual_audience.add('n1_manual_'+method+'_positive_new_green')
        required_policy_panel={
            'f2_wallet_old_red_read_committed',
            'f2_wallet_new_green_read_committed',
            'f2_wallet_new_green_repeatable_read',
        }
        for variant in ('primary','initially_off'):
            required_policy_panel.add('n1_wallet_'+variant+'_old_red_read_committed')
            for iso in ('read_committed','repeatable_read'):
                required_policy_panel.add('n1_wallet_'+variant+'_new_green_'+iso)
        required_policy_panel.add('n1_wallet_positive_new_green_read_committed')
        for dependency in ('type','active'):
            required_policy_panel.add(
                'f4_'+dependency+'_old_red_revocation_precedes_effect_read_committed')
            for iso in ('read_committed','repeatable_read'):
                required_policy_panel.add(
                    'f4_'+dependency+'_new_green_revocation_serialized_'+iso)
        required_settings={
            'f3_red_cached_settings_lost_revocation',
            'f3_audience_timeout_atomic_distinct_admin_writes',
            'f3_timeout_audience_atomic_distinct_admin_writes',
            'f3_audience_reward_atomic_distinct_writes',
        }
        f1_f4_suites=(
            ('legacy_wallet_races','test_post_v9_legacy_wallet_races.py',
             required_legacy_wallet,('reviewed_source_red','current_source_green',
                                     'real_php_mysqli','owned_mariadb_only')),
            ('hosted_audience_races','test_post_v9_hosted_audience_races.py',
             required_hosted_audience,('old_source_red','current_source_green',
                                       'real_mysqli')),
            ('manual_audience_races','test_post_v9_manual_audience_races.py',
             required_manual_audience,('old_source_red','current_source_green',
                                       'real_php_mysqli','owned_mariadb_only')),
            ('wallet_policy_panel_races','test_post_v9_policy_panel_races.py',
             required_policy_panel,('old_source_red','current_source_green',
                                    'mysqli_sessions','owned_mariadb_only')),
            ('settings_concurrency','test_post_v9_settings_concurrency.py',
             required_settings,('old_source_discriminated','source_real_php',
                                'owned_mariadb_only')),
            ('cross_method_lock_order','test_post_v9_cross_method_lock_order.py',
             {
                 'f4_successor_current_read_uses_owner_index',
                 'f4_mutant_plan_then_policy_inverse_lock_conflict_detected',
                 'f4_current_shared_policy_allows_parallel_readers_and_serializes_plan',
             },('intermediate_mutant_detected','current_source_serialization',
                'real_php_mysqli','owned_mariadb_only','synthetic_provider_only')),
            ('setting_lock_scope','test_post_v9_setting_lock_scope.py',
             {
                 'n2_original_schema_primary_key_policy_plan_verified',
                 'n2_prior_unindexed_group_inverse_lock_detected_wallet',
                 'n2_prior_exclusive_policy_serializes_independent_customers',
                 'n2_group_admin_serializes_without_inverse_wallet_read_committed',
                 'n2_group_admin_serializes_without_inverse_wallet_repeatable_read',
                 'n2_group_admin_serializes_without_inverse_nowpayments_read_committed',
                 'n2_group_admin_serializes_without_inverse_nowpayments_repeatable_read',
                 'n2_group_admin_serializes_without_inverse_bank_transfer_read_committed',
                 'n2_group_admin_serializes_without_inverse_bank_transfer_repeatable_read',
                 'n2_shared_policy_two_customers_shared_plan',
                 'n2_shared_policy_two_customers_unrelated',
                 'n2_settings_revocation_waits_for_financial_shared_lock_read_committed',
                 'n2_settings_revocation_waits_for_financial_shared_lock_repeatable_read',
             },('previous_unindexed_scan_detected','current_primary_key_shared_lock',
                'real_php_mysqli','actual_panel_group_source','owned_mariadb_only')),
        )
        reviewer_f1_f4={}
        for key,script,required,truth_flags in f1_f4_suites:
            report_path=Path(tmp)/(key+'.json')
            try:
                subprocess.run(['python3',str(ROOT/'tools'/script),'--run-disposable',
                                '--report',str(report_path)],
                               cwd=ROOT,check=True,timeout=180,capture_output=True)
            except subprocess.CalledProcessError as exc:
                # Each suite uses only synthetic owned MariaDB/PHP. Do not
                # suppress its actionable child traceback in exact-HEAD CI.
                detail=(exc.stderr or exc.stdout or b'').decode(errors='replace')[-6000:]
                raise RuntimeError('Required source-real regression failed: '+
                                   key+' (exit='+str(exc.returncode)+'):\n'+detail) from exc
            except subprocess.TimeoutExpired as exc:
                detail=(exc.stderr or exc.stdout or b'').decode(errors='replace')[-3000:]
                raise RuntimeError('Required source-real regression timed out: '+
                                   key+' (180 seconds):\n'+detail) from exc
            evidence=json.loads(report_path.read_text())
            if not (evidence.get('complete') is True
                    and evidence.get('cases')==len(required)
                    and set(evidence.get('scenarios',()))==required
                    and all(evidence.get(field) is True for field in truth_flags)
                    and evidence.get('production_mutations',0)==0):
                raise RuntimeError('PR60 required independent F1-F4 evidence incomplete: '+key)
            reviewer_f1_f4[key]=evidence

        # Independent review R1 (after N2): every FINAL cohort current read
        # must stay owner-indexed on MariaDB 10.11 rather than implicitly
        # choosing a broad pays/orders/users locking scan. Hold customer A's
        # user, paid pays and active order rows while customer B reaches
        # eligibility across all six modes, both match outcomes, RC/RR and
        # small/populated source-real distributions.
        cohort_path=Path(tmp)/"r1-cohort-lock-scope.json"
        try:
            subprocess.run(['python3',
                            str(ROOT/'tools/test_post_v9_cohort_lock_scope.py'),
                            '--run-disposable','--report',str(cohort_path)],
                           cwd=ROOT,check=True,timeout=180,capture_output=True)
        except subprocess.CalledProcessError as exc:
            detail=(exc.stderr or exc.stdout or b'').decode(errors='replace')[-6000:]
            raise RuntimeError("Required R1 owner-indexed cohort regression failed:\n"+
                               detail) from exc
        except subprocess.TimeoutExpired as exc:
            detail=(exc.stderr or exc.stdout or b'').decode(errors='replace')[-3000:]
            raise RuntimeError("Required R1 cohort concurrency regression timed out:\n"+
                               detail) from exc
        cohort=json.loads(cohort_path.read_text())
        required_cohort={
            'r1_'+dist+'_plan_'+kind+'_indexed'
            for dist in ('small','populated')
            for kind in ('owner','cohort_user','buyer','order')
        }
        required_cohort.update({
            'r1_'+dist+'_'+iso+'_'+mode+'_'+outcome+'_independent'
            for dist in ('small','populated')
            for iso in ('read_committed','repeatable_read')
            for mode in ('agents','non_agents','buyers','non_buyers',
                         'has_active_order','no_active_order')
            for outcome in ('eligible','ineligible')
        })
        if not (cohort.get('complete') is True
                and cohort.get('cases')==len(required_cohort)
                and set(cohort.get('scenarios',()))==required_cohort
                and all(cohort.get(flag) is True for flag in (
                    'source_real_php','two_actual_mysqli_sessions',
                    'current_lock_plan_checked','original_schema_only',
                    'owned_mariadb_only','isolation_modes_verified',
                    'absent_and_positive_matches','small_and_populated_plans'))
                and cohort.get('production_mutations')==0
                and cohort.get('remote_provider_calls')==0):
            raise RuntimeError("Required R1 cohort indexed-scope evidence incomplete")
        reviewer_f1_f4['cohort_lock_scope']=cohort

        auto_renew_path=Path(tmp)/"automatic-renewal-ledger.json"
        try:
            subprocess.run(["python3",str(ROOT/"tools/test_post_v9_automatic_renewal.py"),
                            "--run-disposable","--report",str(auto_renew_path)],
                           cwd=ROOT,check=True,timeout=180,capture_output=True)
        except subprocess.CalledProcessError as exc:
            # Keep the exact synthetic fixture failure visible for root-cause
            # diagnosis; an isolated successful rerun alone cannot classify it.
            detail=(exc.stderr or exc.stdout or b'').decode(errors='replace')[-6000:]
            raise RuntimeError("Automatic renewal native acceptance failed "
                               f"(exit={exc.returncode}):\n"+detail) from exc
        except subprocess.TimeoutExpired as exc:
            detail=(exc.stderr or exc.stdout or b'').decode(errors='replace')[-3000:]
            raise RuntimeError("Automatic renewal native acceptance timed out "
                               "(180 s):\n"+detail) from exc
        auto_renew=json.loads(auto_renew_path.read_text())
        required_auto={
            "missing_schema_fails_closed",
            "disabled_flag_no_effect",
            "verified_remote_success_all_local_effects_atomic",
            "replayed_and_foreign_requests_no_extra_charge",
            # Independent reviewer F4: failed nonthrowing accounting INSERT
            # cannot finalize payment, order or journal or replay provider.
            "f4_nonthrowing_accounting_insert_failure_rolls_back_local_finalization_without_replay",
            "insufficient_funds_disables_auto_only",
            "signed_admin_debt_preserved_no_user_debit",
            "nonpositive_plan_fails_closed",
            "ambiguous_remote_result_never_replayed_or_refunded",
            "remote_success_database_drift_held_for_review",
            "crashed_worker_remote_marker_blocks_retry",
            "private_operator_notice_retry_and_once_per_day_ack",
            "two_real_php_mysql_clients_exactly_one_debit_and_remote",
            "nonunique_legacy_identity_cannot_fund_renewal",
            "real_cron_sweep_one_eligible_marzban_order",
            "telegram_failure_after_commit_does_not_revert_payment",
            # Independent reviewer F1: permanent audit, safe remote deletion.
            "f1_completed_renewal_order_deletable_history_permanently_retained",
            "f1_unresolved_renewal_remote_deletion_preflight_denied",
            "f1_sql_failure_after_remote_retains_inactive_record_then_recovers",
            "f1_remote_deletion_unknown_single_durable_local_fence",
            "f1_real_user_route_pending_renewal_prevents_remote_delete",
            "f1_real_customer_delete_after_done_journal_retained",
            "f1_real_admin_remote_delete_after_done_journal_retained",
            "f1_real_operator_local_only_delete_retains_paid_history",
            "f1_forged_nonadmin_operator_delete_callback_refused",
            # Independent reviewer F5: actual DB-backed delegated role.
            "f5_db_backed_delegated_admin_can_delete_local_order_once_preserving_financial_history",
            "f5_delegated_delete_failure_persists_inactive_fence_without_remote_replay",
            "f5_missing_actor_role_cannot_forge_local_delete_callback",
            "f1_real_user_remote_failure_fenced_without_false_success",
            "f1_real_expiry_cron_cleanup_blocks_unresolved_journal",
            "f1_real_expired_cron_cleanup_single_verified_delete",
            "f1_real_cron_remote_failure_does_not_repeat_destructive_effect",
            "f1_real_cron_entrypoint_warning_exception_cannot_skip_renewal_alert",
            "f1_real_marzban_delete_http_failure_not_falsely_successful",
            "f1_official_http_200_guarded_customer_once_full_evidence",
            "f1_official_http_200_guarded_admin_once_full_evidence",
            "f1_official_http_200_guarded_cron_once_full_evidence",
            "f1_real_guarded_customer_error_and_unexpected_200_fenced",
            "o1_user_delete_preview_ownership_and_privacy",
            # Independent reviewer F2: same remote user with two local IDs.
            "f2_distinct_active_orders_same_account_two_workers_no_debit",
            "f2_explicit_same_panel_server_alias_duplicate_refused",
            "f2_two_alias_workers_no_double_debit_even_under_race",
            "f2_distinct_remote_accounts_remain_renewable",
            # Independent reviewer F3: exact transactional schema preflight.
            "incompatible_004_missing_alert_field_blocks_debit_and_delete",
            "incompatible_004_missing_unique_cycle_blocks_debit_and_delete",
            "incompatible_004_missing_payment_fk_blocks_debit_and_delete",
            "incompatible_004_nontransactional_engine_blocks_debit_and_delete",
            "incompatible_004_wrong_stage_definition_blocks_debit_and_delete",
            "incompatible_004_old_restrictive_order_fk_is_rejected",
            "restored_full_004_contract_ready",
            "f3_shared_003_and_004_compatible_without_history_loss",
        }
        if not (auto_renew.get("complete") and
                required_auto.issubset(auto_renew.get("scenarios",[])) and
                auto_renew.get("cases")>=len(required_auto) and
                auto_renew.get("source_only_004") and
                auto_renew.get("original_schema_file_unchanged") and
                auto_renew.get("provider_requests")==0 and
                auto_renew.get("production_mutations")==0 and
                auto_renew.get("native_php_workers")==2 and
                auto_renew.get("synthetic_local_http_delete_cases")==11):
            raise RuntimeError("Automatic-renewal durable idempotency proof incomplete")
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
            # Owner #26/#51: preserve retry-on-send-failure but never
            # equate an unpaid second child ID with received funds or
            # send a daily unchanged-evidence finance warning.
            "nowpayments_evidenced_review_persian_inbox_retry_once",
            "nowpayments_review_without_24_hour_resends",
            "nowpayments_second_unpaid_child_is_not_payment",
            "nowpayments_unpaid_failed_child_no_operator_case",
            "nowpayments_same_child_funds_reopen_once_no_downgrade",
            "nowpayments_funded_secondary_one_actionable_alert",
            "nowpayments_operator_inbox_details_private_auth",
            "nowpayments_operator_provider_inquiry_readonly",
            "nowpayments_operator_unauthorized_callbacks_refused",
            "nowpayments_owner_inquiry_one_child_per_callback",
            "nowpayments_13th_funded_child_cursor_inquiry_safe",
            "nowpayments_ipn_child_insert_fail_retry_throwing",
            "nowpayments_ipn_child_insert_fail_retry_off",
            "nowpayments_ipn_primary_link_fail_retry_throwing",
            "nowpayments_ipn_primary_link_fail_retry_off",
            "nowpayments_ipn_cross_invoice_child_not_acknowledged",
            "nowpayments_owner_inbox_case_keyset_older_review_visible",
            "nowpayments_owner_inbox_case_cursor_private_readonly",
            "nowpayments_owner_inbox_case_cursor_stable_ties",
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
            "nowpayments_default_fees_invoice_without_private_policy",
            "nowpayments_default_provider_fees_finished_without_return",
            "nowpayments_no_payout_policy_issues_hosted_invoice",
            "nowpayments_explicit_floor_remains_enforced",
            "nowpayments_malformed_optional_floor_rejected_and_private",
            "nowpayments_explicit_null_policy_fails_before_reservation",
            "nowpayments_default_zero_payout_needs_review",
            "nowpayments_default_bad_asset_needs_review",
            "nowpayments_default_underpaid_needs_review",
            "nowpayments_default_pay_amount_zero_needs_review",
            "nowpayments_default_wrong_quote_needs_review",
            "nowpayments_default_missing_payout_needs_review",
            "nowpayments_default_tampered_floor_does_not_downgrade",
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
            "gateway_nextpay_shared_ledger_identity_and_quote",
            "gateway_zarinpal_shared_ledger_identity_and_quote",
            "shared_ledger_composite_provider_uniqueness_and_fk",
            "nextpay_callback_provider_scoped_colliding_external_reference",
            "zarinpal_callback_provider_scoped_colliding_external_reference",
        }
        cases_finance = {case.get("case") for case in finance.get("scenarios",[])
                         if case.get("pass_")}
        if not (finance.get("complete") and finance.get("cases")>=153 and
                mandatory_finance.issubset(cases_finance) and
                finance.get("schema_tables")==23 and
                finance.get("current_schema_tables")==25 and
                finance.get("shared_payment_attempt_ledger_tested") and
                finance.get("original_mariadb_socket_only") and
                finance.get("synthetic_payment_spy") and
                finance.get("real_provider_requests")==0):
            raise RuntimeError("Original-schema finance and NOWPayments finality assurance incomplete")
        manual_path=Path(tmp)/"manual-payment-methods.json"
        try:
            subprocess.run(["python3",str(ROOT/"tools/test_post_v9_manual_payments.py"),
                            "--run-disposable","--report",str(manual_path)],
                           cwd=ROOT,check=True,timeout=180,capture_output=True)
        except subprocess.CalledProcessError as exc:
            # This child contains only disposable fixtures. Without its
            # bounded traceback, cross-PHP-version failures are opaque.
            error=(exc.stderr or b'').decode(errors='replace')[-5500:]
            raise RuntimeError("Isolated three-method manual payment regression failed:\n"
                               + error) from exc
        manual=json.loads(manual_path.read_text())
        required_manual={
            "bank_claim_idempotent",
            "bank_random_exact_amount_persisted_and_rendered",
            "gift_distinct_quote_link_and_photo_action",
            "usdt_quote_network_wallet_photo_admin_distinct",
            "usdt_amount_network_destination_immutable_on_revisit",
            "no_manual_method_switch_replay_or_cross_receipt",
            # PR #60 R1: a formerly shown TRX menu becomes stale after a
            # later same-owner wallet quote, while a fresh menu still works.
            "real_telegram_stale_crypto_menu_never_issues_after_newer_quote",
            "real_telegram_manual_trx_choice_routes_to_wallet_topup_receipt",
            "wrong_user_denied",
            "manual_usdt_disabled_or_bad_configuration_fails_closed",
            "tron_checksum_invalid_destination_denied_before_claim_c",
            "tron_checksum_invalid_destination_denied_before_claim_t",
            "historical_invalid_tron_destination_requires_review_c",
            "historical_invalid_tron_destination_requires_review_t",
            "existing_valid_tron_intent_survives_bad_new_config_c",
            "existing_valid_tron_intent_survives_bad_new_config_t",
            "agent_existing_price_and_trc20_validated",
            "one_attempt_no_cross_method_claim",
            "single_receipt_claim_no_duplicate_photo_submission",
            "manual_usdt_no_automatic_wallet_credit",
            "gift_usdt_screenshots_never_auto_approved",
            "no_fulfillment_financial_provider_effect",
            "real_wallet_route_start_bank_transfer",
            "real_wallet_route_start_gift_card",
            "real_wallet_route_start_manual_usdt",
            "real_wallet_route_operator_review_bank_transfer",
            "real_wallet_route_operator_review_gift_card",
            "real_wallet_route_operator_review_manual_usdt",
            "real_wallet_route_photo_replay_denied_bank_transfer",
            "real_wallet_route_photo_replay_denied_gift_card",
            "real_wallet_route_photo_replay_denied_manual_usdt",
            "manual_wallet_nonadmin_callback_denied_bank_transfer",
            "manual_wallet_nonadmin_callback_denied_gift_card",
            "manual_wallet_nonadmin_callback_denied_manual_usdt",
            "manual_wallet_exactly_one_credit_bank_transfer",
            "manual_wallet_exactly_one_credit_gift_card",
            "manual_wallet_exactly_one_credit_manual_usdt",
            "manual_wallet_approval_replay_denied_bank_transfer",
            "manual_wallet_approval_replay_denied_gift_card",
            "manual_wallet_approval_replay_denied_manual_usdt",
            "manual_wallet_two_admin_concurrent_single_credit",
            "manual_wallet_privileged_debt_offset_preserved",
            "manual_wallet_not_for_other_payment_subjects",
            # PR #52 independent F1/F2 findings: mandatory real callback runs.
            "real_custom_nonadmin_cannot_approve_submitted_gift_receipt",
            "real_custom_authorized_review_state_approves_only_once",
            "real_custom_replayed_operator_callback_no_remote_effect",
            "real_custom_pending_without_photo_cannot_approve",
            "real_custom_wallet_paid_invoice_cannot_approve_again",
            "real_custom_wrong_subject_cannot_approve",
            "real_custom_callback_cannot_approve_standard_plan_subject",
            "real_custom_two_concurrent_admins_single_remote_create",
            "real_custom_hosted_provider_conflict_cannot_approve",
            "real_custom_unreviewed_attempt_cannot_approve",
            "real_custom_missing_admin_delivery_evidence_denied",
            "real_custom_stale_after_bank_auto_claim_denied",
            "real_renewal_stale_auto_bank_claim_cannot_refulfill",
            "real_day_stale_auto_bank_claim_cannot_refulfill",
            "real_volume_stale_auto_bank_claim_cannot_refulfill",
            "real_renewal_operator_before_bank_cron_one_remote_effect",
            "real_day_operator_before_bank_cron_one_remote_effect",
            "real_volume_operator_before_bank_cron_one_remote_effect",
            "real_custom_operator_and_bank_scheduler_race_single_service_claim",
            "legacy_operator_receipt_requires_persisted_delivery_evidence",
            "uncertain_custom_external_effect_operator_reconciliation_no_retry",
            # PR #55 R2: mandatory authenticated end-to-end top-up lifecycle:
            # freshness against the SENT bot prompt, one-use user row claim,
            # exactly one NEW draft after concurrency, with earlier rows preserved.
            "real_topup_origin_private_menu_start_accepted",
            "real_topup_replayed_private_amount_no_second_invoice",
            "real_topup_out_of_order_older_update_no_financial_effect",
            "real_topup_old_private_message_before_prompt_no_financial_effect",
            "real_topup_exact_prompt_message_id_not_new_no_financial_effect",
            "real_topup_week_idle_update_id_reset_fresh_private_amount",
            "real_topup_week_idle_random_reset_allows_new_menu",
            "real_topup_replaced_step_rejects_stale_input",
            "real_topup_menu_update_replay_no_reissue",
            "real_topup_old_fixed_button_wrong_prompt_denied",
            "real_topup_out_of_order_old_menu_cannot_replace_new_stage",
            "real_topup_fixed_callback_consumed_once",
            "real_topup_unconfirmed_bot_menu_fails_closed",
            "real_topup_ambiguous_customer_identity_fails_closed",
            "real_topup_insert_failure_rolls_back_claim_and_cleanup",
            "real_topup_never_deletes_historical_draft_under_nonthrowing_sql",
            "real_topup_postcommit_telegram_failure_never_repeats_invoice",
            "real_topup_concurrent_step_claim_single_atomic_invoice",
            # PR #55 R1: real Telegram UpdateBindings and private-chat source
            # authorization, with durable SQL/step preservation for denials.
            "real_topup_origin_group_message_denied",
            "real_topup_origin_supergroup_message_denied",
            "real_topup_origin_old_callback_text_denied",
            "real_topup_origin_foreign_chat_callback_denied",
            "real_topup_origin_group_custom_start_denied",
            "real_topup_origin_group_fixed_amount_denied",
            "real_topup_origin_other_actor_message_denied",
            "real_topup_origin_bot_authored_message_denied",
            "real_topup_origin_mixed_callback_message_denied",
            "real_topup_origin_private_custom_start_accepted",
            "real_topup_origin_private_customer_text_accepted",
            # PR #55 P1: actual raw Telegram parsing, SQL immutability,
            # and accepted top-up boundaries are required acceptance cases.
            "real_topup_raw_callback_rejected_0",   # numeric prefix + suffix
            "real_topup_raw_callback_rejected_2",   # exponent callback
            "real_topup_raw_callback_rejected_10",  # overflow callback
            "real_topup_raw_text_rejected_13",       # fractional text
            "real_topup_raw_text_rejected_15",       # exponent text
            "real_topup_raw_text_rejected_28",       # overflow text
            "real_topup_raw_callback_accepted_0",  # minimum callback
            "real_topup_raw_text_accepted_1",      # maximum custom text
            "real_topup_raw_text_accepted_2",      # ASCII leading zeros
            "real_topup_raw_callback_accepted_3",  # maximum callback
        }
        if not (manual.get("complete") and manual.get("cases")>=len(required_manual)
                and required_manual.issubset(manual.get("scenarios",[]))
                and manual.get("database")=="owned_socket_only"
                and manual.get("live_provider_calls")==0
                and manual.get("production_data_modified") is False):
            raise RuntimeError("Four-network manual crypto payment isolation incomplete")
        # Independent, exact-source R1/R2 contract: offline TRON Base58Check
        # and real bot.php/config.php administrator-setting writes. Keep the
        # positive network cases and invalid historical-intent refusals in the
        # finance-native manual test above as separate DB evidence.
        crypto_guard_path=Path(tmp)/"manual-crypto-source-guards.json"
        try:
            subprocess.run(["python3",str(ROOT/"tools/test_post_v9_manual_crypto_guards.py"),
                            "--run-disposable","--report",str(crypto_guard_path)],
                           cwd=ROOT,check=True,timeout=150,capture_output=True)
        except subprocess.CalledProcessError as exc:
            error=(exc.stderr or b'').decode(errors='replace')[-6500:]
            raise RuntimeError("Offline TRON checksum or real admin callback "
                               "contract failed:\n"+error) from exc
        crypto_guard=json.loads(crypto_guard_path.read_text())
        required_crypto_guard={
            'offline_canonical_tron_documented_trc20',
            'offline_canonical_tron_documented_native_trx',
            'offline_canonical_tron_invalid_checksum_single_character',
            'offline_canonical_tron_invalid_network_prefix_checksummed',
            'offline_canonical_tron_invalid_decoded_length_checksummed',
            'offline_canonical_tron_invalid_base58_character_zero',
            'offline_canonical_tron_noncanonical_leading_zero',
            'offline_canonical_tron_diverse_valid_checksum_0',
            'offline_canonical_tron_diverse_valid_checksum_31',
            'offline_canonical_tron_diverse_corrupted_checksum_0',
            'offline_canonical_tron_diverse_corrupted_checksum_31',
            'stale_admin_callback_denied_changeGateWaysweSwapState',
            'stale_admin_callback_denied_changeGateWaystronWallet',
            'stale_admin_callback_denied_changeBotweSwapState',
            'stale_admin_callback_denied_changeBottronWallet',
            'non_admin_cannot_change_current_payment_or_bot_flags',
            'delegated_admin_current_gateway_toggle_still_works',
            'actual_editor_rejects_checksum_corruption_manualUsdtWalletTRC20',
            'actual_editor_rejects_checksum_corruption_manualTrxWallet',
            'actual_editor_accepts_checksums_manualUsdtWalletTRC20',
            'actual_editor_accepts_checksums_manualTrxWallet',
            'no_financial_effects_from_admin_settings_or_editors',
            'final_persistence_denies_retired_key_weSwapState',
            'final_persistence_denies_retired_key_tronWallet',
        }
        if not (crypto_guard.get('complete')
                and crypto_guard.get('cases')>=136
                and required_crypto_guard.issubset(crypto_guard.get('scenarios',[]))
                and crypto_guard.get('official_tron_test_vectors_verified')
                and crypto_guard.get('independent_base58check_oracle')
                and crypto_guard.get('real_bot_admin_callbacks_executed')
                and crypto_guard.get('real_config_setting_writers_extracted')
                and crypto_guard.get('actual_payment_editor_executed')
                and crypto_guard.get('retired_flags_preserved_not_mutated')
                and crypto_guard.get('retired_keys_denied_at_persistence_boundary')
                and crypto_guard.get('all_advertised_current_admin_toggles_work')
                and crypto_guard.get('schema_tables')==23
                and crypto_guard.get('isolated_socket_only')
                and crypto_guard.get('live_provider_or_chain_calls')==0
                and crypto_guard.get('production_data_modified') is False):
            raise RuntimeError("Canonical TRON recipient and retired callback "
                               "acceptance evidence incomplete")
        migration_path=Path(tmp)/"shared-payment-ledger-rc4-import.json"
        subprocess.run(["python3",str(ROOT/"tools/test_post_v9_payment_migration.py"),
                        "--run-disposable","--report",str(migration_path)],
                       cwd=ROOT,check=True,timeout=180,capture_output=True)
        migration=json.loads(migration_path.read_text())
        if not (migration.get("complete")
                and migration.get("original_tables")==23
                and migration.get("old_rc4_tables")==2
                and migration.get("new_shared_tables")==2
                and migration.get("imported_attempts")==1
                and migration.get("imported_children")==2
                and migration.get("preserved_rc4_rows_and_ids")
                and migration.get("live_database_touched") is False):
            raise RuntimeError("Lossless RC4 payment ledger migration rehearsal incomplete")
        agency_path=Path(tmp)/"agency-state-regression.json"
        agency_command=["python3",str(ROOT/"tools/test_post_v9_agency.py"),
                        "--run-disposable","--report",str(agency_path)]
        try:
            subprocess.run(agency_command, cwd=ROOT, check=True,
                           timeout=180, capture_output=True)
        except subprocess.CalledProcessError as exc:
            # This fixture uses only generated identities and owned socket-only
            # MariaDB. Keep its bounded error output visible in CI: hiding it
            # makes intermittent child failures impossible to classify.
            error=(exc.stderr or b'').decode(errors='replace')[-6000:]
            if not error:
                error=(exc.stdout or b'').decode(errors='replace')[-2000:]
            raise RuntimeError('Isolated agency regression failed '
                               f'(exit={exc.returncode}):\n{error}') from exc
        except subprocess.TimeoutExpired as exc:
            error=(exc.stderr or b'').decode(errors='replace')[-3000:]
            raise RuntimeError('Isolated agency regression timed out '
                               f'(180 s):\n{error}') from exc
        agency=json.loads(agency_path.read_text())
        required_agency_v1={
            "concurrent_admin_approval_exactly_one",
            "v1_single_database_deadlock_victim_retries_exact_once_before_effect",
            "v1_repeated_database_deadlock_victim_fails_closed_no_role_or_notice",
            "v1_database_lock_wait_timeout_no_role_or_notice",
            "v1_synthetic_schema_restored_no_trigger_or_table_leak",
        }
        if not (agency.get("complete") and agency.get("cases")>=24
                and required_agency_v1.issubset(agency.get("details",[]))
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
                and swap.get("runtime_members")==93
                and swap.get("historical_runtime_members")==92
                and swap.get("retired_old_payment_files_absent_after_source_upgrade")
                and swap.get("source_roll_forward_and_back_byte_identical")
                and swap.get("unchanged_original_sql_ddl")
                and swap.get("synthetic_private_files_untouched")):
            raise RuntimeError("Physical post-V9/V9 source-only overlay or private-state check failed")
        if a.report:a.report.write_text(json.dumps({"complete":True,
                    "source":result,"marzban":marzban,"purchases":purchases,
                     "unsupported_wallet_preflight":wallet_guard,
                     "wallet_transfer_safety":wallet_transfer,
                     "admin_wallet_adjustment_safety":admin_wallet,
                     "customer_error_privacy":audience,
                     "telegram_transport_log_privacy":transport_privacy,
                    "source_only_overlays":swap,
                    "financial_subject_preflight":subject,
                    "catalogue_and_subscription_qr":catalogue,
                    "financial_native_route_guards":finance,
                    "automatic_renewal_ledger":auto_renew,
                    "payment_ux_lifecycle":payment_ux,
                    "payment_browser_feedback":browser_feedback,
                    "checkout_serialization":checkout_race,
                    "customer_method_serialization":method_race,
                    "wallet_authorization_freshness":wallet_freshness,
                    "latest_review_f1_f4":reviewer_f1_f4,
                    "manual_payment_method_isolation":manual,
                    "manual_crypto_destination_and_admin_guards":crypto_guard,
                    "payment_reference_assurance":payment_identity,
                    "shared_payment_ledger_rc4_import":migration,
                     "agency_state_regression":agency,
                     "optional_cron_runner":cron,
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
                       "admin_wallet_adjustment_scenarios":admin_wallet["cases"],
                       "customer_literal_first_argument_messages_inspected":audience["literal_first_argument_calls_inspected"],
                       "finance_error_audience_execution_cases":finance_privacy["cases"],
                       "telegram_transport_privacy_cases":transport_privacy["cases"],
                      "verified_source_swaps":swap["phase_count"],
                      "payment_subject_cases":subject["cases"],
                      "catalogue_qr_cases":catalogue["cases"],
                      "financial_native_route_cases":finance["cases"],
                      "automatic_renewal_cases":auto_renew["cases"],
                      "payment_ux_cases":payment_ux["cases"],
                      "payment_browser_feedback_cases":browser_feedback["cases"],
                      "checkout_serialization_cases":checkout_race["cases"],
                      "customer_method_serialization_cases":method_race["cases"],
                      "wallet_authorization_freshness_cases":wallet_freshness["cases"],
                      "manual_payment_isolation_cases":manual["cases"],
                      "manual_crypto_guard_cases":crypto_guard["cases"],
                      "payment_reference_length":payment_identity["default_length"],
                      "historical_rc4_payment_import_verified":migration["preserved_rc4_rows_and_ids"],
                       "agency_state_cases":agency["cases"],
                       "optional_cron_runner_cases":cron["cases"],
                      "marzban_logical_transfer_cases":logical["cases"],
                      "current_php_version":subprocess.check_output(["php","-r","echo PHP_VERSION_ID;"]).decode()}))


if __name__=="__main__":
    main()
