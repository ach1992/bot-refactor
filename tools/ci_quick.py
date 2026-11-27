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
    ("current_database_isolation", "test_current_database_isolation.py"),
    ("current_storage_transfer", "test_current_storage_transfer.py"),
    ("current_payment_readiness", "test_post_v9_payment_readiness.py"),
    ("current_sales_discount", "test_current_sales_discount.py"),
    ("current_customer_routes", "test_current_customer_routes.py"),
    ("current_support", "test_current_support.py"),
    ("current_referral_settings", "test_current_referral_settings.py"),
    ("current_subscription_actions", "test_current_subscription_actions.py"),
    ("manual_checkout_conversation", "test_manual_checkout_conversation.py"),
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
    ("broadcast_delivery", "test_post_v9_broadcast_delivery.py"),
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
    ("telegram_presentation", ("php", "tools/test_post_v9_telegram_presentation.php"), "stdout"),
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
    "current_storage_transfer": ("current_groups_and_original_sealed_identity",),
    "current_database_isolation": (
        "bot_states_cross_db_same_db","payment_keys_cross_db_same_db","panel_groups_cross_db_same_db",
        "server_display_cross_db_same_db","start_cross_db_same_db","writer_failure_release",
        "physical_journal_independence","selected_database_required",
        "actual_persisted_writers_own_scoped_lock","existing_execution_and_projection_lease_scope"),
    "current_subscription_actions": (
        "exact_owned","ambiguous_partial","literal_percent","literal_underscore",
        "my_scope","inactive","unauthorized_staff","staff_duplicate_exact",
        "stale_step","stale_temp","current_transition","protected_transition",
        "changed_subscription_url_not_original_success","revoked_provider_editor_can_cancel_own_draft",
    ),
    "agency_current": (
        "request_commits_before_send","independent_original_audience","request_known_failure_recovery_only",
        "callback_text_not_typed","decision_committed_with_original_notice","approval_notice_retry_only",
        "old_application_cannot_decide_new","unknown_not_blindly_resent","concurrent_exactly_one_decision",
        "request_outbox_failure_atomic","discount_preserves_other_scope","old_role_controls_cannot_cancel_pending",
        "same_actor_decline_retires_editor_independent_cleanup","decline_replay_no_second_notice_or_cleanup",
        "approval_cleanup_failure_does_not_block_result","stale_decline_retires_editor_without_false_rejection",
        "decline_preserves_unrelated_editor","stale_approval_preserves_newer_editor",
    ),
    "current_support": (
        "atomic_ticket_body_and_outbox_rollback",
        "durable_private_independent_audience_unknown_not_resent",
        "original_message_identity_and_private_ownership",
        "staff_reply_photo_ownership_and_rate_aware_retry",
        "closed_ticket_cannot_reopen_or_duplicate_rating_prompt",
        "customer_close_retires_matching_editor_despite_cleanup_failure",
        "other_actor_close_retires_obsolete_staff_reply_on_input",
        "staff_close_and_customer_obsolete_reply_only",
        "closed_ticket_preserves_unrelated_and_unavailable_editor",
        "bounded_stable_personal_and_staff_pagination",
        "current_recipient_revocation_skips_only_original_copy",
        "concurrent_submit_single_ticket",
        "concurrent_close_reply_linearization",
        "category_private_current_role_and_concurrent_create",
        "support_cancel_and_menu_do_not_clear_newer_editor",
        "legacy_attachment_and_long_history_preserved",
    ),
    "current_referral_settings": (
        "invalid_amount_cannot_enter_financial_policy","canonical_zero_positive_and_existing_money_bound",
        "cancel_and_reentry_fence_delayed_input","current_role_and_navigation",
        "delayed_old_update_cannot_consume_fresh_generation","idle_update_sequence_reset_does_not_expire_editor",
        "delayed_banner_input_preserves_fresh_generation",
        "concurrent_singleton_creation_one_setting","concurrent_same_editor_one_winner",
        "write_failure_and_ambiguous_existing_setting_not_success","banner_text_photo_and_stale_cancel_ownership",
        "old_cancel_home_and_panel_preserve_reentered_editors",
        "fresh_navigation_and_cleanup_failure_are_independent",
        "idle_reset_and_malformed_editor_escape","demoted_actor_can_leave_but_cannot_save",
        "replacement_editor_starts_share_opening_generation",
        "actual_ordinary_starts_require_admission_before_prompt",
        "failed_ordinary_start_never_publishes_prompt",
        "actual_typed_advances_require_current_editor",
        "panel_terminal_cleanup_exact_actor_and_idle_truth",
    ),
    "wallet_first_service_execution": (
        "missing_current_report_attempt_no_legacy_fallback",
        "actual_long_checkout_and_auto_renew_storage_bound",
        "current_schema_source_execution_without_migrations",
        "new_delivery_due_immediately_and_failed_preflight_yields",
        "customer_photo_text_ack_identity_and_independent_sibling",
        "five_real_wallet_callbacks_owner_schema_and_replay_fences",
        "single_wallet_and_staff_dispatch_all_original_subjects",
        "actual_low_wallet_callback_switch_to_manual",
        "invalid_native_names_never_debit_or_allocate",
        "retired_external_renewal_and_provider_denied",
        "actual_bulk_checkout_draft",
        "zero_commercial_actual_checkout_native_delivery_reports",
        "physical_generated_name_collision_before_dispatch_only",
        "imported_physical_names_paid_explicit_generated_bulk_deleted_and_attachment",
        "import_create_physical_lock_read_committed_and_original_fence",
        "actual_addon_current_agency_pricing_and_draft_guard",
        "positive_addons_shared_configured_method_eligibility",
        "ordinary_zero_commercial_origin_survives_demotion",
        "discounted_and_zero_allocated_bulk_commercial_origin",
        "managed_addon_policy_revocation_blocks_new_funding",
        "zero_managed_free_confirmation_no_monetary_methods",
        "actual_auto_renew_current_agency_policy",
        "manual_receipt_original_frozen_quote",
        "actual_custom_draft_and_review_completion_truth",
        "receipt_projection_original_funding_service_and_delivery",
        "service_report_original_receipt_and_wallet_debit",
        "logical_report_final_before_first_send",
        "logical_report_exact_edit_recovers_and_keeps_same_identity",
        "logical_report_deleted_message_replaced_once",
        "logical_report_unknown_send_isolated_from_service_and_peer",
        "logical_report_rate_limit_survives_multiple_revisions",
        "logical_report_transport_lease_independent_of_native",
        "part_temporary_rejection_auto_retries_without_replaying_siblings",
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
        "gateway_history_counts_only_actual_completed_automatic_renewal",
        "actual_auto_renewal_sales_once_despite_delivery_and_report_retries",
        "actual_external_sale_not_double_counted_as_wallet_or_delivery",
        "actual_full_refund_not_service_sale",
        "actual_partial_checkout_sale_uses_exact_original_refund",
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
        "referral_actual_setting_route_purchase_completion",
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
        "retired_timer_cannot_credit",
        "actual_bank_scan_without_staff_ack_service_invalidated",
        "receipt_outbox_atomic_rollback",
        "receipt_concurrent_original_winner",
        "receipt_late_ack_after_financial_commit",
        "receipt_rate_limit_and_independent_customer",
        "superseded_submitted_api_receipt_credits_without_service",
        "superseded_receiving_ack_and_recovery_all_photo_methods",
        "superseded_receipt_requires_verified_or_staff_approval",
        "typed_gift_claim_atomic_and_superseded_recovery",
        "superseded_unstarted_late_evidence_wallet_only_never_reopens",
        "pinned_deadline_nonextendable_grace_late_wallet_once",
        "source_issued_expiry_delayed_sms_exact_transfer_boundary_and_review",
        "superseded_started_receipt_preserves_original_family_and_money",
        "current_manual_approval_cooldown_preserves_configured_policy",
        "current_review_cooldown_does_not_block_original_recovery",
        "historical_payments_do_not_create_manual_cooldown",
        "api_maintenance_rotates_broken_pin_and_skips_historical_projection",
        "same_exact_sms_amount_with_competing_or_corrupt_original_never_auto_credits",
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
        "actual_public_nextpay_checkout_expiry_and_late_return",
        "actual_public_zarinpal_checkout_expiry_and_late_return",
        "actual_public_nowpayment_checkout_expiry_and_late_return",
        "actual_browser_nowpayments_local_policy_validation_and_original_reopen",
        "actual_browser_current_config_not_request_snapshot_and_original_receipt",
        "signed_child_verified_exact_quote_once",
        "actual_admission_lost_creation_original_secret_ipn_recovery_and_poll_once",
    ),
    "wallet_first_funding": (
        "duration_storage_boundary_before_wallet_debit",
        "custom_duration_overflow_refused",
        "trial_base_price_refused_at_issue_and_funding",
        "registered_native_gateway_same_engines_once",
        "registered_native_nonpaid_opaque_name_namespace",
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

# These suites expose single-scenario evidence rather than strict/off pairs.
REQUIRED_SCENARIOS = {
    "current_storage_transfer": ("unmapped_nonzero_inbound_refused","group_collision_and_dangling_membership_refused",
        "complete_source_dump_restores_original_evidence","all_table_common_values_and_generated_columns_preserved",
        "ambiguous_or_malformed_payment_setting_refused","identity_allocation_counters_preserved",
        "all_declared_foreign_key_relationships_verified"),

    "broadcast_delivery": (
        "broadcast_edit_stale_or_replayed_generation_never_queues",
        "broadcast_edit_job_content_and_editor_commit_or_rollback_together",
        "broadcast_edit_current_role_and_real_caption_limit",
        "broadcast_edit_competing_staff_one_current_job",
        "broadcast_management_concurrent_same_action_one_job",
        "broadcast_title_and_exact_message_editor_commit_or_rollback_together",
        "broadcast_initial_content_and_editor_commit_or_rollback_together",
        "broadcast_original_media_forward_and_cancelled_draft_preserved",
        "killed_after_outbound_before_ack_never_replays_recipient",
        "actual_html_text_and_raw_history_unchanged",
        "actual_media_caption_unknown_ack_not_retried_and_logged",
        "cancelled_job_cannot_claim_or_dispatch",
        "pause_cannot_rewind_durable_cursor",
        "all_ack_completion_committed_before_one_truthful_report",
        "zero_ack_forward_completion_not_misreported_as_success",
        "recipient_rejections_continue_with_only_recorded_ack_count",
        "recipient_exception_does_not_interrupt_other_recipients",
        "recipient_malformed_ack_does_not_interrupt_other_recipients",
        "recipient_malformed_id_does_not_interrupt_other_recipients",
        "admin_notice_exception_cannot_stop_or_reopen_dispatch",
        "cancellation_during_dispatch_preserves_claim_and_no_completion",
        "rate_limit_checkpoint_survives_ticks_and_resumes_fifo_without_replay",
        "explicit_rate_limit_without_wait_yields_one_tick_not_entire_job",
        "ack_write_failure_isolated_strict",
        "database_claim_failure_recovers_without_unclaimed_send_strict",
        "failed_ack_count_cannot_report_zero_or_replay_strict",
        "ack_write_failure_isolated_nonthrowing",
        "database_claim_failure_recovers_without_unclaimed_send_nonthrowing",
        "failed_ack_count_cannot_report_zero_or_replay_nonthrowing",
        "completion_count_independent_of_current_audience_and_invalid_or_other_job_rows",
        "all_supported_media_use_original_file_caption_and_html",
        "shared_current_audience_progress_and_dispatch_all_groups",
        "actual_batch_boundary_resumes_all_405_recipients_once",
        "only_actual_blocked_user_marked_without_erasing_protected_steps",
        "all_current_message_actions_use_exact_original_target_and_html",
        "failed_unpin_never_falls_back_to_latest_message_and_continues",
        "edit_ack_must_identify_original_message",
        "confirmed_identical_edit_is_not_a_failure_or_a_resend",
        "message_action_killed_after_claim_recovers_next_without_replay",
        "message_action_cancellation_fences_every_recipient",
        "message_action_rate_limit_durable_pause_and_fifo_recovery",
        "message_action_failed_local_projection_isolated_strict",
        "message_action_failed_local_projection_isolated_nonthrowing",
        "invalid_or_historical_action_does_not_execute_or_starve_current_job",
        "message_action_failed_audience_read_recovers_strict",
        "message_action_failed_audience_read_recovers_nonthrowing",
        "message_action_inexact_claim_never_dispatches_strictsql_throwing",
        "message_action_full_unsigned_cursor_pause_finish_fifo_strictsql_throwing",
        "message_action_unsigned_kill_recovery_no_replay_strictsql_throwing",
        "message_action_inexact_claim_never_dispatches_strictsql_nonthrowing",
        "message_action_full_unsigned_cursor_pause_finish_fifo_strictsql_nonthrowing",
        "message_action_unsigned_kill_recovery_no_replay_strictsql_nonthrowing",
        "message_action_inexact_claim_never_dispatches_loosesql_throwing",
        "message_action_full_unsigned_cursor_pause_finish_fifo_loosesql_throwing",
        "message_action_unsigned_kill_recovery_no_replay_loosesql_throwing",
        "message_action_inexact_claim_never_dispatches_loosesql_nonthrowing",
        "message_action_full_unsigned_cursor_pause_finish_fifo_loosesql_nonthrowing",
        "message_action_unsigned_kill_recovery_no_replay_loosesql_nonthrowing",
        "message_action_unsigned_progress_routes_preserve_exact_identity",
    ),
    "nonpaid_trial_bulk": (
        "commercial_positive_price_denied_at_nonpaid_reservation",
        "trial_current_policy_and_entitlement_rechecked_before_reservation",
        "consumed_trial_entitlement_denied_at_reservation",
        "restricted_or_revoked_bulk_staff_denied_at_reservation",
        "actual_nonpaid_bulk_physical_name_collision_resolved_before_dispatch",
        "actual_trial_generated_name_collision_resolved_before_dispatch",
        "strict_actual_nonpaid_imported_name_occupancy_alias_trial_and_bulk",
        "off_actual_nonpaid_imported_name_occupancy_alias_trial_and_bulk",
    ),
    "current_payment_readiness": (
        "fresh_empty_structure_ready_not_payment_configured",
        "select_only_account_cannot_write",
        "configured_current_methods_and_no_private_output_or_database_writes",
        "explicit_exemptions_are_potential_not_actual_authorization",
        "missing_check_nonunique_command_and_missing_foreign_key_block",
        "untrusted_manifest_commit_or_private_member_rejected_before_db",
        "tampered_and_symlinked_runtime_member_blocked",
        "unexpected_executables_and_index_shadow_rejected_without_reading_contents",
    ),
    "gateway_rules": (
        "current_service_history_funding_only_excluded_auto_renew_counted_once",
    ),
    "manual_checkout_conversation": (
        "committed_topup_selector_recovery_exact_owner_no_second_draft",
        "completed_topup_is_not_cancelled_editor",
        "staff_monitoring_actions_and_original_evidence_negative_fence",
        "unified_method_issue_exact_owner_and_retained_invoice",
        "prompt_reuse_and_exact_cancel_no_financial_cancellation",
        "chronological_evidence_feedback_exact_ack_retires_only_previous_prompt",
        "owner_waiting_copy_survives_receipt_state_projection",
        "same_receipt_status_and_separate_actual_credit_notification",
        "definite_receipt_target_loss_explicit_recovery_no_blind_replay",
        "draft_cancel_retires_selector_only",
        "original_receipt_routes_to_staff_without_credit_or_human_delete",
        "committed_invoice_recovered_in_place_and_original_facts_preserved",
        "rate_limit_respected_by_cron_and_explicit_status_replay",
        "ambiguous_home_edit_cannot_revive_selector_or_admit_stale_payment",
        "status_send_unknown_replay_fenced_and_new_click_recovers",
        "contradictory_credit_keeps_original_recovery_and_never_reissues",
        "navigation_orders_after_concurrent_browser_admission",
    ),
    "current_customer_routes": (
        "actual_public_content_namespace_exact_and_no_private_values",
        "actual_content_deletion_scope_and_staff_capability",
        "actual_category_actor_owned_drafts_concurrent_submit_and_revocation",
        "actual_category_delete_preserves_inactive_plan_references",
        "actual_current_agency_discount_standard_bulk_custom_and_renewal",
        "actual_bulk_quantity_and_amount_fit_storage_without_coercion",
        "actual_capacity_entry_catalogue_share_current_native_saleability",
        "actual_standard_agent_single_and_bulk_discount_to_wallet_admission",
        "actual_custom_discount_uses_original_plan_units_and_name",
        "actual_full_discount_zero_price_wallet_admission_and_no_replay",
        "actual_invalid_discount_preserves_card_and_stale_target_consumes_nothing",
        "actual_shortcuts_and_callback_share_purchase_methods_and_entry_gates",
        "terminal_reply_keyboard_removed_and_idle_cancel_not_false_success",
        "discount_admin_current_editor_atomic_commit_and_single_code",
        "disposable_home_reuses_screen_retained_home_preserves_record",
        "exemption_cancel_consumes_current_nonce_without_policy_change",
        "order_agent_callback_namespace_and_idempotent_desired_state",
        "actual_owned_renewal_discount_and_subscription_shortcut",
        "managed_trial_policy_and_revoked_draft_denied_before_effect",
        "actual_trial_only_and_mixed_catalogue_purpose_and_custom_gate",
        "actual_trial_conversion_refuses_existing_unfunded_standard_and_custom_drafts",
        "actual_zero_agent_single_bulk_and_primary_commercial_no_money_or_trial",
        "zero_commercial_catalogue_without_any_monetary_method",
        "zero_commercial_current_agency_authorization_rechecked_before_reservation",
        "zero_commercial_synchronized_role_plan_policy_revocation_before_reservation",
        "zero_custom_configured_rates_and_agency_without_monetary_methods",
        "sales_closure_denies_unissued_positive_quote_preserves_independent_trial_switch",
        "full_discount_zero_confirmation_without_wallet_or_gateway",
        "fresh_ordinary_agent_bulk_custom_discount_only_routes_atomic_partial_refusal",
        "current_audience_flag_and_credential_not_stale_request_snapshot",
        "fresh_renewal_full_discount_without_monetary_methods",
        "all_eight_configured_methods_positive_discount_and_crypto_audience_exactness",
        "malformed_payment_destinations_do_not_block_free_or_consume_partial_discount",
        "configured_methods_shared_by_independent_topup_no_wallet_self_payment",
        "topup_invalid_config_audience_and_quote_unavailable",
        "nowpayments_local_policy_quote_validation_atomic_discount_and_zero",
        "actual_crypto_submenu_and_manual_issuance_current_config_original_receipt",
        "actual_renewal_hosted_navigation_current_method_configuration",
    ),
    "current_sales_discount": (
        "actual_sales_keyboard_net_completed_value_wallet_origin_and_original_windows",
        "actual_payment_reports_preserve_incoming_receipts",
        "actual_custom_date_sales_and_receipt_count_are_distinct",
        "actual_scheduled_sales_report_uses_same_completed_value",
        "actual_discount_buyer_inverse_matches_completed_history_and_no_ineligible_consumption",
        "actual_discount_target_policy_preserved_at_consumption",
        "actual_discount_revalidates_buyer_history_under_owner_lock",
        "actual_shared_discount_concurrent_owners_both_eligible",
        "actual_discount_editor_stale_and_atomic_rollback",
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
    if name in REQUIRED_SCENARIOS:
        reported = proof.get("scenarios")
        if not isinstance(reported, list):
            raise ValueError(f"{name}: missing named scenarios")
        for case in REQUIRED_SCENARIOS[name]:
            if case not in reported:
                raise ValueError(f"{name}: missing {case}")
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
