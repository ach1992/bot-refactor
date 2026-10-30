#!/usr/bin/env python3
"""Post-V9 current-runtime acceptance; does NOT redefine immutable V9 parity."""
from __future__ import annotations

import argparse
from collections import Counter
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


def owner_r5_proof_mismatches(name: str, proof: dict, minimum: int, required: dict) -> dict:
    """Keep exact proof fields; accept only both native-asserted safe RR outcomes."""
    def bounded(value):
        if value is None or type(value) in (bool, int):
            return value
        if isinstance(value, str) and re.fullmatch(r"[A-Za-z0-9_.:-]{1,80}", value):
            return value
        return '<unexpected '+type(value).__name__+'>'

    mismatches={key:{'expected':bounded(value),'actual':bounded(proof.get(key))}
                for key,value in required.items() if proof.get(key)!=value}
    cases=proof.get('cases')
    if type(cases) is not int or cases<minimum:
        mismatches['cases']={'expected_minimum':minimum,'actual':bounded(cases)}
    if name=='admin_fanout':
        # MariaDB snapshot-isolation versions can either defer a conflicting
        # current read or see the new member. The native probe proves safety
        # and next-normal delivery in both cases; every other field stays exact.
        modes=('deferred_current_read_conflict','current_member_observed')
        mode=proof.get('old_snapshot_membership_mode')
        if mode not in modes:
            mismatches['old_snapshot_membership_mode']={
                'expected_one_of':list(modes),'actual':bounded(mode)}
    return mismatches


def exact_current_proof_mismatches(proof: dict, required: dict,
                                   scenario_key: str, expected: list[str],
                                   count_key: str | None = 'cases') -> dict:
    """Bind new current-source suites to named proofs and typed risk fields."""
    fields={**required,**({count_key:len(expected)} if count_key else {})}
    mismatches={key:'required exact '+repr(value)
                for key,value in fields.items()
                if type(proof.get(key)) is not type(value) or proof.get(key)!=value}
    scenarios=proof.get(scenario_key)
    # Notification checks deliberately repeat per-recipient payload/transaction
    # assertions; preserve their multiplicity as well as every named scenario.
    if not (type(scenarios) is list and all(type(item) is str for item in scenarios)
            and Counter(scenarios)==Counter(expected)):
        mismatches[scenario_key]='required exact known checks, including multiplicity'
    return mismatches


def source_checks():
    if cmd("rev-parse", V9 + "^{tree}").decode().strip() != V9_TREE:
        raise RuntimeError("Frozen historical V9 reference changed")
    for field in ("database/legacy-schema.sql",):
        if (ROOT / field).read_bytes() != cmd("show", V9+":"+field):
            raise RuntimeError("Changed historical schema/private-template: " + field)
    files=package.members()
    if len(files)!=package.EXPECTED_RUNTIME_COUNT or len(set(files))!=len(files):
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
        if rel in {'src/Admin/Access.php','config.php'} and re.search(
                r'\$(?:admins_list|special_admins)\b',php_source):
            raise RuntimeError('Retired private staff variable remains in authority/delivery source: '+rel)
        if ("admins_list_check.txt" in php_source or
                re.search(r"print_r\s*\(\s*\$admins_list\b", php_source)):
            raise RuntimeError("Unsafe administrator-list debug dump in runtime: "+rel)
    module=(ROOT/"src/Panel/Accounts.php").read_text()
    definitions=re.findall(r"(?m)^function (\w+)\s*\(",module)
    if sorted(definitions)!=sorted(MARZBAN_NAMES):
        raise RuntimeError("Panel module still exposes legacy or lacks required Marzban methods: "+str(definitions))
    source_paths=[
        ROOT/"bot.php", ROOT/"config.php",
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
    support = re.search(r"function achbotSupportsPanel\(\$type\)\s*\{([^}]+)\}", check)
    if support is None:
        raise RuntimeError("Missing supported-provider predicate")
    probe = "require "+json.dumps(str(ROOT/"src/Panel/ProviderRuntime.php"))+";function achbotSupportsPanel($type){" + support.group(1) + "}"
    probe += "echo json_encode(array_map('achbotSupportsPanel', ['marzban','pasarguard','xui','sanaei','alireza','normal','',null,0,true,[], 'Marzban']));"
    supported = json.loads(subprocess.check_output(['php','-r',probe],text=True))
    if supported != [True,True] + [False]*10:
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
    if (ROOT/'settings/subLink.php').exists() or 'settings/subLink.php' in files:
        raise RuntimeError('Unused retired XUI subscription stub retained')
    return {"source_files":len(files),"php_lint":len(php_files),
            "original_historical_schema_file_unchanged":True,
            "current_additive_migration_separately_tested":True,
            "synthetic_baseinfo_example_current_settings_removed":True,
            "obsolete_private_staff_authority_absent":True,
            "historic_contract_changed":False,"retired_provider_api_removed":True,
            "admin_list_debug_artifact_writer_absent":True,
            "pasarguard_native_implemented":True, "new_xui_implemented":False}


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
        # Current Owner R5 uses real merged source; prior immutable stages remain separate.
        owner_r5={}
        r5_suites=[
            ('roles','test_owner_r5_roles.py',635,
             {'complete':True,'cases':635,'real_disposable_mariadb':True,'actual_admin_keyboard':True,
              'db_only_staff_authority':True,'legacy_roleless_operator':True,
              'obsolete_staff_arrays_ignored':True,'obsolete_array_denials':3,
              'no_obsolete_array_import':True,'explicit_manager_assignment':True,
              'archived_private_manager_fallback_red':True,
              'staff_configuration_baseline_source':'f570db225beac9e429f11fbc73d84acaf3750cf6',
              'financial_boolean_preserved':True,'no_network':True,
              'actual_role_management_route':True,'actual_readonly_attention_hub':True,'baseline_red':True,
              'actual_approval_denials':True,'manager_operator_approval':True,
              'support_receipt_approval':True,'support_other_operations_denied':True,
              'direct_receipt_helper_levels':['manager','operator','support'],
              'unauthorized_approval_actors':[1001,9005,9006,9999],
              'support_unclassified_prefix_denials':3,'notification_action_independence':True,
              'notification_ui_owner_only':True,'notification_on_cannot_grant_staff':True,
              'approved_notification_off_levels':['manager','operator','support'],
              'support_payment_family_coverage':['accCustom','approveRenewAcc','approveIncreaseDay','approveIncreaseVolume','accept','approvePayment','declineAction','declineActionReason'],
              'baseline_approval_callback_red':True,'baseline_decline_escape_red':True,
              'approval_family_coverage':['accCustom','approveRenewAcc','approveIncreaseDay','approveIncreaseVolume','accept','approvePayment','declineAction','declineActionReason'],
              'forbidden_financial_effects':{'financial_reads':0,'sql_writes':0,'transactions':0,'native_calls':0},
              'actual_positive_approval_levels':['manager','operator','support'],
              'actual_replay_protected_levels':['manager','operator','support'],
              'approval_baseline_source':'82861ac0b17aa7a0c494c564cdf4674cb6eb505d',
              'actual_production_bot_ingress':True,'actual_config_bootstrap_ingress':True,
              'actual_customer_app_downloads':True,'actual_customer_pagination_reply':True,
              'actual_ticket_attachment_authorization':True,'public_callback_collision_baseline_red':True,
              'public_collision_baseline_source':'d6a2ce01626fb3e707e062a099d051bce4c5ae63',
              'public_customer_journey_families':['buy','bulk','trial','own_orders','import','renew','addon','wallet','status','support','agency','downloads'],
              'public_pending_stage_families':['buy','bulk','own_orders','renew','addon','wallet','support'],
              'actual_customer_wallet_draft_paths':['1001:increaseWallet_100000','1001:increaseWalletCustom','1002:increaseWallet_100000','1002:increaseWalletCustom'],
              'actual_customer_ticket_actors':[1001,1002,9004],
              'forbidden_admin_wallet_effects':{'financial_reads':0,'sql_writes':0,'transactions':0,'native_calls':0}}),
            ('staff_example','test_owner_db_staff_example.py',7,
             {'complete':True,'cases':7,'actual_package_members':True,'source_real':True,
              'authorized_assignment_removals':9,'invitation_assignment_removals':1,'additional_staff_assignment_removals':2,
              'baseline_source':'f570db225beac9e429f11fbc73d84acaf3750cf6',
              'archived_two_staff_assignments_red':True,
              'staff_reintroduction_red':['admins_list','special_admins'],
              'unrelated_template_mutation_red':['primary_owner','synthetic_database_user'],
              'runtime_members':119,'private_config_read':False,'external_calls':0}),
            ('instance_content','test_owner_instance_content.py',99,
             {'result':'PASS','cases':99,'source_real':True,'socket_only_original_schema':True,'no_external_calls':True,
              'lock_join_baseline_red':True,'lock_join_baseline_source':'4548705609518f741d14375c9d47739eb302c3b6'}),
            ('display_contexts','test_server_display_contexts.py',216,
             {'complete':True,'cases':216,'source_real':True,'owned_original_schema_mariadb':True,
              'actual_canonical_delivery':True,'actual_order_presentation':True,
              'actual_checkout_config_qr':True,'loopback_native_pasarguard_readonly':True,
              'native_providers':['pasarguard','marzban'],'disjoint_delivery_view':True,
              'explicit_none_isolated':True,'shared_selector_fallback':True,'absent_selectors_first_three':True,
              'context_all_preserves_other_effective_selection':True,'single_existing_setting_row':True,
              'other_rows_and_financial_state_unchanged':True,'current_authority_revision_guards':True,
              'native_uri_bytes_preserved':True,'legacy_marzban_indices_unchanged':True,
              'archived_shared_selection_red':True,'archived_context_document_red':True,
              'baseline_source':'748994636f9fc2cf4c0ea4c7c810093c0dd108ef',
              'real_provider_calls':0,'real_telegram_calls':0,'native_mutations':0,
              'runtime_schema_mutations':0,'runtime_automatic_data_writes':0}),
            ('user_info','test_owner_r5_user_info.py',46,
             {'result':'PASS','source_real':True,'owned_socket_mariadb':True,'baseline_missing_refresh_red':True}),
            ('receipt_presentation','test_owner_r5_receipt_presentation.py',13,
             {'complete':True,'source_real':True,'owned_original_schema_mariadb':True,
              'baseline_red':True,'external_calls':0,'financial_mutations_by_caption':0}),
            ('admin_fanout','test_owner_r5_admin_fanout.py',264,
             {'complete':True,'cases':264,'source_real':True,'owned_original_schema_mariadb':True,
              'network_calls':0,'recipient_matrix':True,'raw_text_photo_markup_preserved':True,
              'notification_recipient_matrix':True,'receiving_preference_independent_of_role':True,
              'general_reports_manager_only':True,'manual_review_all_staff':True,
              'notification_default_on':True,'owner_only_notification_ui':True,
              'notification_preference_preserved':True,'uniform_receipt_authority_all_tiers':True,
              'notification_never_grants_or_revokes_actions':True,
              'notification_off_suppresses_new_copies':True,
              'stored_receipt_maintenance_ignores_notification_preference':True,
              'stored_receipt_maintenance_requires_current_staff':True,
              'archived_notification_coupling_red':True,
              'receipt_maintenance_baseline_source':'add6948fd0720c0fed454d5c2db2f06d1d5d8b24',
              'malformed_duplicate_notifications_denied':True,'support_receipt_approval_replay':True,
              'support_payment_followups':True,'support_operations_still_denied':True,
              'db_only_staff_authority':True,'legacy_roleless_operator':True,
              'obsolete_staff_arrays_ignored':True,'explicit_manager_assignment':True,
              'actual_admin_ui_membership_delivery':True,'old_snapshot_membership_safe':True,
              'next_normal_delivery_includes_added_staff':True,'no_private_bootstrap_import':True,
              'db_only_baseline_red':True,'private_staff_baseline_source':'4a28474fdba41e17ebadab29ece1c17d18038437',
              'finance_source_ref':'current-source','catalog_source_ref':'current-source',
              'persisted_tiers_preserved':True,'primary_owner_once':True,'options_recipient_integrity':True,
              'recorded_refs_match_delivery':True,'fresh_role_changes':True,
              'repeatable_read_notification_off_denied':True,'no_new_transaction_across_delivery':True,
              'actual_primary_receipt_evidence':True,'original_evidence_retry_preserved':True,
              'approval_replay_preserved':True,'stored_followup_loops':9,
              'stored_followup_markup_preserved':True,'historical_refs_preserved':True,
              'atomic_history_append':True,'malformed_history_preserved':True,
              'concurrent_confirmed_append':True,'archived_fanout_red':True,
              'archived_stored_refs_red':True,
              'baseline_source':'a070a47c5796e8244a3fe7fbca21e301977c3dcf'}),
            ('destination_escaping','test_owner_destination_escaping.py',289,
             {'result':'PASS','cases':289,'source_real':True,'actual_transport_payloads':True,
              'read_only_original_schema':True,'no_external_calls':True,
              'no_money_or_settings_mutation':True,'baseline_red':True,
              'baseline_red_source':'a070a47c5796e8244a3fe7fbca21e301977c3dcf',
              'baseline_red_sinks':['app','join','plain','manual','gateway','channel']}),
        ]
        for name,script,minimum,required in r5_suites:
            report=Path(tmp)/('owner-r5-'+name+'.json')
            subprocess.run(['python3',str(ROOT/'tools'/script),'--run-disposable','--report',str(report)],
                           cwd=ROOT,check=True,timeout=180,capture_output=True)
            proof=json.loads(report.read_text())
            mismatches=owner_r5_proof_mismatches(name,proof,minimum,required)
            if mismatches:
                raise RuntimeError('Owner R5 merged source proof incomplete: '+name+' '+
                                   json.dumps(mismatches,sort_keys=True))
            if name=='destination_escaping':
                fragments=proof.get('source_fragment_sha256')
                expected_fragments={'initialization','facades','sink-app','sink-join',
                    'sink-banned','sink-import','sink-plain','sink-alert','sink-manual',
                    'sink-gateway','sink-channel','main-keys'}
                if not (isinstance(fragments,dict) and set(fragments)=={'current','archived'}
                        and all(isinstance(hashes,dict) and set(hashes)==expected_fragments
                            and all(isinstance(digest,str) and re.fullmatch('[0-9a-f]{64}',digest)
                                for digest in hashes.values()) for hashes in fragments.values())):
                    raise RuntimeError('Owner R5 destination source-fragment evidence incomplete')
            owner_r5[name]=proof
        copy_run=subprocess.run(['python3',str(ROOT/'tools/test_owner_r5_copy.py')],
                                cwd=ROOT,check=True,timeout=180,capture_output=True,text=True)
        copy_proof=json.loads(copy_run.stdout)
        required_copy={'status':'PASS','changes':273,'retired':10,'added':60,'current_keys':2771,
                       'r5_negative_controls':5,'persian_prose_start_violations':0,
                       'duplicate_heading_markers':0,'guide_provider_cases':2,'guide_JSON_examples':3,
                       'actual_HTML_transport_cases':2,'archived_wrong_parse_RED':True,
                       'receipt_PRICE_template_cases':6,'real_Telegram_rendering':'NOT_TESTED',
                       'composed_catalog_correction_keys':22,'composed_heading_cases':42,
                       'settings_navigation_label_amendments':3,'settings_navigation_retired_keys':4,
                       'display_context_catalog_additions':7,'display_context_catalog_negative_controls':9,'simple_display_catalog_additions':14,'simple_display_label_changes':2,
                       'notification_catalog_additions':4,'staff_role_description_amendments':1,
                       'staff_catalog_negative_controls':6,
                       'telegram_update_id_label_cases':1,'telegram_update_id_numeric_value_preserved':True,
                       'archived_wrong_telegram_update_label_RED':True,
                       'composed_heading_source_paths':16,'composed_heading_archived_RED':18,
                       'composed_heading_negative_controls':6,'identical_heading_reuse_cases':1,
                       'raw_code_heading_exclusions':1,'numeric_id_variant_negative_controls':5,
                       'preserved_telegram_object_id_cases':3,'preserved_numeric_id_cases':1,
                       'composition_scope':'source_expression_wallet_finance_receipt_support_admin_stats_matrix'}
        if any(copy_proof.get(key)!=value for key,value in required_copy.items()):
            raise RuntimeError('Owner R5 Persian copy/HTML proof incomplete')
        required_api_copy={
            'r5_current_keys':2685,
            'api_added_keys':86,
            'api_added_user_keys':22,
            'api_added_admin_keys':64,
            'api_baseline_keys':2685,
            'api_current_keys':2771,
            'api_retained_unreferenced_keys':4,
            'api_catalog_negative_controls':14,
            'routed_message_expression_cases':6,
            'routed_message_expression_negative_controls':12,
            'routed_checkout_expression_preserved':True,
        }
        if any(type(copy_proof.get(key)) is not type(value) or copy_proof.get(key)!=value
               for key,value in required_api_copy.items()):
            raise RuntimeError('Current API catalog additions or retained R5 copy proof incomplete')
        owner_r5['copy']=copy_proof
        # Issue #57: current event-aware notification routing and existing
        # receipt convergence, after every unaffected Owner R5 proof above.
        notifications_path=Path(tmp)/'owner-r6-notifications.json'
        subprocess.run(['python3',str(ROOT/'tools/test_owner_r6_notifications.py'),
                        '--report',str(notifications_path)],
                       cwd=ROOT,check=True,timeout=180,capture_output=True)
        notifications=json.loads(notifications_path.read_text())
        required_notification_checks=[
            'general helper delivers Owner Manager channel, excludes Operator and Support',
            *['general raw body and markup preserved']*3,
            'full actionable review preserves Owner and staff without adding a report-channel receipt',
            'secondary helper defaults to informational Manager-only event',
            'actionable manual receipt reaches valid enabled Operator Manager Support',
            *['notification matrix preserves existing receipt action authority '+str(actor)
              for actor in [9001,9002,9003,9004,9005,9006]],
            'Support receives manual review without gaining independent operations',
            *['invalid or suppressed recipient excluded '+actor for actor in
              ['9007','9008','9009','9010','9011','9012','9013','1001','-1','"9003bad"','true']],
            *['unsupported '+kind+' event has no delivery '+event
              for event in ['unknown','backup'] for kind in ['full','secondary']],
            'backup document cannot enter generic fanout',
            'caller cannot redirect primary identity',
            'primary channel identity deduplicated and payload cannot redirect Owner',
            'Manager channel identity deduplicated',
            'private report adds Managers without exposing channel',
            'existing per-channel parse mode is preserved',
            'primary ACK failure remains observable and does not suppress independent copies',
            'throwing secondary delivery preserves primary ACK and continues to report channel',
            'throwing primary delivery fails primary ACK and continues every independent copy',
            *['router holds no transaction across outbound delivery']*3,
            'recipient preference refreshed after earlier target delivery',
            'later recipient role refreshed after candidate list and earlier staff delivery',
            'contended customer row cannot block Owner or channel delivery',
            'incomplete recipient discovery remains observable after primary acknowledgement',
            'next ordinary delivery retries fresh membership after contention clears',
            'receipt pins existing original quote',
            'receipt acquires original evidence lease',
            'actual receipt helper preserves primary evidence confirmation',
            'actual actionable receipt Owner once plus eligible staff, no report channel',
            'receipt refs store every successful secondary copy only',
            'receipt lease cannot replay',
            'Support OFF only suppresses new delivery',
            'muted Support retains actual receipt approval',
            'receipt approval still credits exactly once',
            'new automatic success report excludes all Operator Support',
            *[prefix+' '+file+' '+str(index) for index,file in enumerate([
                'Routes/Checkout.php','Routes/RenewalCheckout.php',
                'Routes/Extensions.php','Routes/Extensions.php',
                'Routes/ManualApproval.php','Routes/ManualApproval.php',
                'Routes/ManualReceipts.php','Routes/ManualReceipts.php',
                'AutomaticReceipts.php'],1) for prefix in [
                'stored receipt converges for current muted staff and excludes revoked invalid',
                'stored receipt rechecks current membership']],
            'all nine real stored receipt loops exercised',
            'receipt convergence preserves historical financial rows',
            'actual thrown transport continues to independent targets with sanitized structured metadata',
            'exception diagnostics contain no outgoing payload or raw exception text',
        ]
        notification_mismatches=exact_current_proof_mismatches(notifications,
            {'complete':True,'owned_original_schema_mariadb':True,'network_calls':0,
             'stored_receipt_loops':9,'source_ref':'current-source',
             'sanitized_exception_metadata':True,'exception_continuation':True},
            'checks',required_notification_checks)
        if notification_mismatches:
            raise RuntimeError('Owner R6 notification source proof incomplete: '+
                               json.dumps(notification_mismatches,sort_keys=True))
        # Issue #57 existing-API proof: use this integrated source only, with
        # actual guarded routes and owned socket/loopback fixtures, never live APIs.
        api_settings_path=Path(tmp)/'payment-api-settings.json'
        subprocess.run(['python3',str(ROOT/'tools/test_post_v9_payment_api_admin.py'),
                        '--run-disposable','--report',str(api_settings_path)],
                       cwd=ROOT,check=True,timeout=180,capture_output=True)
        api_settings=json.loads(api_settings_path.read_text())
        required_api_settings=[
            'off_existing_manual_bank_and_gift_photo_prompts_and_buttons_preserved',
            'absent_modes_off_adjacent_rows_incomplete_activation_denied',
            'encrypted_token_explicit_horizon_guarded_enable_disable_preserves_other_flags',
            'gift_canonical_amount_only_explicit_unit_required_optional_scope_unset',
            'actual_private_pages_mask_secrets_and_bound_callbacks',
            'cancel_one_action_returns_to_api_page_without_deleting_admin_input',
            'real_normalizer_rejects_bad_money_horizon_urls_clear_blocks_enable',
            'current_manager_authority_required_revocation_forged_frames_malformed_denied',
            'field_revision_conflict_and_replayed_message_cannot_overwrite',
            'clear_secret_unsets_status_no_payment_rows_or_external_effects',
            'malformed_stored_secret_invalid_owner_allowed_duplicate_toggle_unknown_flag_denied',
        ]
        api_settings_mismatches=exact_current_proof_mismatches(api_settings,
            {'status':'PASS','evidence_module':str((ROOT/'src/Finance/PaymentEvidence.php').resolve()),
             'evidence_sha256':hashlib.sha256((ROOT/'src/Finance/PaymentEvidence.php').read_bytes()).hexdigest()},
            'checks',required_api_settings,count_key=None)
        if api_settings_mismatches:
            raise RuntimeError('Existing API settings source proof incomplete: '+
                               json.dumps(api_settings_mismatches,sort_keys=True))
        api_evidence_run=subprocess.run(
            ['python3',str(ROOT/'tools/test_post_v9_api_payment_evidence.py')],
            cwd=ROOT,check=True,timeout=180,capture_output=True,text=True)
        api_evidence=json.loads(api_evidence_run.stdout)
        required_api_evidence=[
            'partial_schema',
            'encrypted_config',
            'explicit_mapping',
            'stable_namespace',
            'full_exact_quote',
            'exact_rial_conversion',
            'ready_only',
            'original_time',
            'sql_collision_fence',
            'pre_dispatch_intent',
            'sms_same_key_recovery',
            'sms_expired_recovery',
            'manual_settled_evidence_only',
            'typed_review_authenticity',
            'gift_single_code',
            'gift_exact_redeem_amount',
            'gift_partial_commit',
            'gift_nonce_reference',
            'gift_uncertain_active_hold',
            'bounded_transport',
            'rotation_fails_closed',
        ]
        api_evidence_mismatches=exact_current_proof_mismatches(api_evidence,
            {'complete':True,'exact_quote':True,'replay_and_partial_commit':True,
             'no_http_transaction':True,'scope':'owned socket MariaDB and loopback only'},
            'contracts',required_api_evidence,count_key=None)
        # The suite proves a transaction-free native observation at each request;
        # require its meaningful coverage floor, not a timing-sensitive call total.
        if (type(api_evidence.get('loopback_dispatches')) is not int or
                api_evidence['loopback_dispatches']<10):
            api_evidence_mismatches['loopback_dispatches']='required at least 10 native observations'
        if api_evidence_mismatches:
            raise RuntimeError('Existing API evidence source proof incomplete: '+
                               json.dumps(api_evidence_mismatches,sort_keys=True))
        api_evidence_red_run=subprocess.run(
            ['python3',str(ROOT/'tools/test_post_v9_api_payment_evidence.py'),'--negative-controls'],
            cwd=ROOT,check=True,timeout=240,capture_output=True,text=True)
        api_evidence_red=json.loads(api_evidence_red_run.stdout)
        api_evidence_red_mismatches=exact_current_proof_mismatches(api_evidence_red,
            {'complete':True,'source_unchanged':True},'expected_RED',
            ['suffix_only','fresh_retry_key','gift_active_retry'],count_key=None)
        if api_evidence_red_mismatches:
            raise RuntimeError('Existing API evidence regression controls incomplete: '+
                               json.dumps(api_evidence_red_mismatches,sort_keys=True))
        api_flow_path=Path(tmp)/'payment-api-flow.json'
        subprocess.run(['python3',str(ROOT/'tools/test_owner_r6_api_flow.py'),
                        '--run-disposable','--report',str(api_flow_path)],
                       cwd=ROOT,check=True,timeout=240,capture_output=True)
        api_flow=json.loads(api_flow_path.read_text())
        required_api_flow=[
            'off_manual_original_unpinned',
            'full_exact_quote_only_and_wallet_replay',
            'complete_report_uses_original_random_quote_and_durable_dedup',
            'proven_terminal_excluded_new_same_quote_distinct_sms',
            'full_quote_collision_no_first_match_or_consumption',
            'exact600_fallback_original_reference_and_no_unpaid_timeout',
            'offline_bank_keeps_genuine_manual_receipt',
            'bank_submitted_receipt_text_retains_check_without_new_receipt_invitation',
            'late_ready_sms_manual_settlement_consumption_only',
            'concurrent_real_human_api_wallet_at_most_once',
            'proof_backed_standard_existing_create_journal',
            'proof_backed_custom_existing_create_journal',
            'proof_backed_bulk_existing_create_journal',
            'proof_backed_renew_existing_edit_finisher',
            'proof_backed_day_existing_edit_finisher',
            'proof_backed_volume_existing_edit_finisher',
            'proof_backed_external_existing_edit_finisher',
            'concurrent_real_human_api_rstandard_at_most_once',
            'concurrent_real_human_api_rcustom_at_most_once',
            'concurrent_real_human_api_rbulk_at_most_once',
            'concurrent_real_human_api_rrenew_at_most_once',
            'concurrent_real_human_api_rday_at_most_once',
            'concurrent_real_human_api_rvolume_at_most_once',
            'concurrent_real_human_api_rexternal_at_most_once',
            'restart_after_local_claim_reuses_capacity_hold_once',
            'restart_verified_edit_finishes_without_native_redispatch',
            'admitted_maintenance_recovers_proven_processing_reserved_and_verified',
            'uncertain_create_journal_never_redispatched',
            'configured_horizon_stops_new_scans_without_financial_expiry',
            'queued_invoice_edit_human_winner_converges_final',
            'queued_photo_copy_converges_after_api_settlement',
            'actual_ingress_review_owner_operator_support_current_revoked_customer',
            'staff_final_copy_retry_after_failure_ignores_receiving_off',
            'bounded_projection_fairness_more_than_one_batch',
            'gift_canonical_redeem_exact_single_code_replacement',
            'giftstandard_proof_uses_genuine_create_without_bank009',
            'giftcustom_proof_uses_genuine_create_without_bank009',
            'giftbulk_proof_uses_genuine_create_without_bank009',
            'gift_used_distinct_same_code_reads_state',
            'gift_revoked_distinct_same_code_reads_state',
            'gift_expired_distinct_same_code_reads_state',
            'gift_inflight_review_status_has_no_fresh_code_invitation',
            'unknown_code_encrypted_review_different_code_fenced',
            'actual_gift_issuer_binds_initial_code_prompt',
            'gift_navigation_start_back_cancel_and_callbacks_never_become_code',
            'callback_ack_precedes_slow_api_and_has_no_expired_second_answer',
            'monotonic_expired_and_remaining_api_budget_no_new_http_context_restored',
        ]
        current_api_head=cmd('rev-parse','HEAD').decode().strip()
        api_flow_mismatches=exact_current_proof_mismatches(api_flow,
            {'complete':True,'source_real_php':True,'socket_mariadb':True,
             'live_requests':0,'installed_tested':False,
             'migrations_executed':['001','003','006','007','008','009','014'],
             'flow_head':current_api_head,'evidence_head':current_api_head,'settings_head':current_api_head},
            'scenarios',required_api_flow)
        if (type(api_flow.get('authenticated_loopback_requests')) is not int or
                api_flow['authenticated_loopback_requests']<80):
            api_flow_mismatches['authenticated_loopback_requests']='required at least 80 authenticated fixture requests'
        if api_flow_mismatches:
            raise RuntimeError('Existing API payment flow source proof incomplete: '+
                               json.dumps(api_flow_mismatches,sort_keys=True))
        unified={}
        suites=[
            ('management_wizard','test_unified_management_wizard.py','cases',362,
                {'actual_php':True,'owned_socket_mariadb':True,'loopback_provider_http':True,'provider_mutations':0,'production_mutations':0,'actual_rc13_cancel_RED':True,'older_same_flow_cancel':True,'unique_actor_primary_writes':True,'busy_delete_preserves_editor':True,'administrator_input_history_preserved':True,'same_message_group_selection':True,'actual_rc14_group_message_flood_RED':True,'actual_rc14_admin_input_deletion_RED':True}),
            ('financial_live_state','test_financial_live_state.py','cases',86,
                {'native_two_provider': True, 'agent_discount': True, 'absent_agent_discount_configuration_compatible': True, 'explicit_invalid_agent_discount_rejected': True, 'own_order_only': True, 'fresh_addon_eligibility': True, 'statistics_compatibility': True, 'external_requests': 0, 'production_mutations': 0}),
            ('config_qr_delivery','test_config_qr_delivery.py','cases',25,
                {'actual_php': True, 'owned_socket_mariadb': True, 'actual_Checkout_dispatch': True, 'actual_sendQrFromLink': True, 'provider_neutral_native_names_both': True, 'actual_PasarGuard_identity_HTTP_here': False, 'archived_route_RED': True, 'unchanged_URI_bytes': True, 'temp_cleanup': True, 'financial_mutations': 0, 'external_requests': 0, 'production_mutations': 0, 'real_telegram_rendering_tested': False}),
            ('shared_provider_storage','test_post_v9_shared_provider_storage.py','cases',91,
                {'populated_migration':True,'actual_backup_restore':True,'disposable_socket_mariadb':True,'native_runtime':True,'real_provider_calls':0,'tenant_mutation':False}),
            ('order_schema','test_order_schema_simplification.py','cases',10,
                {'actual_php':True,'owned_socket_mariadb':True,'populated_migration':True,'previous_writer_RED':True,'production_mutations':0,'external_calls':0}),
            ('management_menus','test_unified_management_menus.py','checks',322,
                {'database':'owned socket MariaDB','network_calls':0,'optional_011_missing_cancel_proven':True}),
            ('customer_services','test_unified_customer_services.py','cases',596,
                {'actual_php':True,'obsolete_order_columns_absent':True,'native_disposable_mariadb':True,'external_provider_calls':0,'production_mutations':0,'real_JDF_no_date_stubs':True,'fresh_each_render_same_process':True,'same_packet_observation_and_presentation':True,'genuine_import_origin_writer_fixture':True,'actual_service_unit_helpers':True,'native_plan_admission_seam':'controlled_read_only_SQL_fixture','actual_native_plan_eligibility_checked_here':False,'actual_URL_import_checked_here':False,'imported_actions_hide_ineligible_autorenew':True,'existing_consent_withdrawal_visible_when_plan_global_or_observation_unavailable':True,'unlimited_actions_hide_unsupported_relative_changes':True,'native_HTTP_count_checked_here':False,'actual_composed_ASCII_Jalali_Tehran_proofs':{key:['marzban','pasarguard'] for key in ['customer_remote_expiry','admin_remote_expiry','admin_recorded_expiry','admin_warning_grace']},'baseline_date_ref':'2408982797af15bc99e1df2909c13be3976cb1f4','baseline_date_jdf_sha256':'e32da43aafb6548fa746492a9d3181ef796083fa618af2c84f6a7bb4e857337d','baseline_dates_already_ASCII':True,'baseline_date_RED':False,'archived_warning_toggle_RED':2}),
            ('service_lifecycle','test_unified_service_lifecycle.py','cases',118,
                {'native_php':True,'disposable_mariadb':True,'actual_delete_transports':True,'import_warning_planless_and_assigned':True,'import_warning_retry_once':True,'amount0_trial_warning_policy_preserved':True,'malformed_origin_warning_row_isolated':True,'automatic_known_refusal_predebit':True,'scheduled_known_refusal_prefence':True,'production_mutations':0,'external_provider_calls':0,'notification_delivery_regression_red':True,'grace_integrity':True,'deletion_fairness_red':True,'deletion_fairness_source':'5568e5e24ecc595c371c4710bf41db51bb78125d','scheduled_deletion_cursor':'deleteLastOrderId','deletion_fairness_deferred_batch':50,'deletion_fairness_cross_provider_directions':2,'deletion_fairness_financial_hold':True}),
            ('runtime_cleanup','test_unified_runtime_cleanup.py','assertions',144,
                {'actual_php':True,'owned_socket_mariadb':True,'provider_mutations':0,'production_mutations':0,'composed_native_reader_unique':True,'domain_independent_verified_token_import':True}),
            ('provider_registry','test_unified_provider_registry.py','cases',89,
                {'actual_php':True,'owned_socket_mariadb':True,'closed_native_adapters':2,'fixture_adapter_only':True,'external_requests':0,'production_mutations':0,'new_schema':False,'authoritative_subject_resolution':True,'sid_only_dispatch_RED':True,'explicit_subject_server_mismatch_rejected':True,'pasarguard_native_quote_pins_preserved':True,'subject_preflight_readonly':True,'action_preflight_readonly':True,'quote_current_read_after_RR_snapshot':True,'quote_lock_forwarding_RED':True,'network_functions_disabled':False,'uncontrolled_network_functions_disabled':True,'native_quote_loopback_readonly':True,'native_quote_read_requests':17,'native_quote_write_requests':0}),
            ('existing_subscriptions','test_unified_existing_subscriptions.py','cases',202,
                {'actual_php':True,'owned_socket_mariadb':True,'loopback_native_pasar_guard':True,
                 'real_telegram_tested':False,'real_provider_tested':False,
                 'official_source_pin':'eba70d68eaa5a89e34ffebb47c8fd5ce054d0e43',
                 'URL_only_common_admin_and_customer':True,'unconfigured_alias_domain_and_prefix_both_providers':True,'arbitrary_input_host_never_fetched':True,'actual_common_import_dispatcher_both_providers':True,'broken_dispatcher_177605_RED':True,'rotating_native_token_verified':True,
                 'genuine_local_adoption':True,'import_remote_mutations':0,'import_financial_mutations':0,
                 'no_new_schema':True,'optional_import_pair_all_or_none':True,
                 'missing_import_capability_preserves_core':True,'marzban_usable_without011':True,
                 'direct_module_http404':True,'original23_marzban_import_verified':True,
                 'marzban007_without011_adoption_verified':True,'marzban_adopt_assigned_plan_capacity_preserved':True,
                 'native_quote_and_delete_identity':True,'both_provider_unlimited_preview_and_view':True,
                 'exact_native_page_request_count':True,'external_native_expiry_refresh':True,
                 'legacy_native_token_forms':True,'adoption_rollback_atomic':True,
                 'actual_planless_customer_regenerate_and_delete':True,
                 'verified_marzban_revoke_credential_negatives':True,
                 'unlimited_addon_entry_neutral_and_effect_free':True,'adopted_capacity_origin_preserved':True,
                 'delete_replay_safe':True,'native_origin_alias_and_ambiguity_verified':True,
                 'created_marker_policy_preserved':True,'malformed_capacity_ledger_reconciliation_hold':True,
                 'marzban_legacy_JWT_native_signature_verified':True,'initial_import_controls_match_supported_actions':True,'actual_planless_update_link_controller_verified':True,'shared_native_registration_for_multiple_users':True,'genuine_import_plan_confirm_paid_renewal':True,'shared_native_auto_renew_debits_once':True,'concurrent_shared_native_auto_renew_verified':True,'shared_payment_owner_and_replay_verified':True,'shared_native_views_coherent':True,'automatic_renewal_updates_only_paying_order':True,'fresh_shared_views_without_peer_sync':True,'shared_physical_anchor_history_preserved':True,'shared_native_action_views_coherent':True,'known_native_guard_preclaim_no_poison':True,'actual_native_URL_import_warnings':True,'zero_amount_import_warning_delivery_retry':True,'planless_and_selected_plan_native_warning_verified':True,'actual_native_auto_renew_refusal_before_reservation':True,'actual_native_delete_controllers_refuse_before_local_fence':True,'actual_scheduled_native_delete_refusal_before_local_fence':True,'malformed_replay_exhausted_history_no_HTTP_or_claim':True,'external_requests':0,'production_mutations':0}),
            ('service_origin','test_service_order_origin.py','cases',46,
                {'actual_php':True,'owned_socket_mariadb':True,'original23':True,'no_new_schema':True,
                 'no_provider_or_telegram_calls':True,'shared_reference_metadata':True,
                 'malformed_origin_never_legacy':True,'atomic_order_origin_rollback':True,
                 'locked_origin_current_read':True,'payment_wallet_capacity_mutations':0,'production_mutations':0}),
            ('imported_plan_selection','test_imported_plan_selection.py','cases',94,
                {'actual_imports':True,'actual_change_plan_controller':True,
                 'actual_config_consent_controller':True,'actual_native_provider_eligibility':True,'existing_consent_withdrawal_visible_and_controller_checked':True,
                 'amount0_preserved_before_payment':True,'shared_native_different_bot_owners':True,
                 'production_mutations':0,'live_provider_calls':0}),
            ('agent_zero_amount_manual_actions','test_agent_zero_amount_manual_actions.py','cases',42,
                {'actual_SelectPlan_TrialProvision':True,'actual_agent_pricing':True,
                 'actual_native_CREATE_and_readback':True,'actual_renderer':True,
                 'actual_manual_renewal_day_volume_quotes':True,'zero_amount_agent_not_import_or_new_consent':True,
                 'regression_ref':'4458f3d9f7b7f1e8a7e9e09bca4b97300650eb5f',
                 'archived_renderer_sha256':'4a34ed56370842e75ddb16563908c7c0497736851bbbc0da81c17b686e29fb9c',
                 'actual_archived_renderer_RED':['marzban','pasarguard'],'synthetic_Telegram_delivery':True,
                 'production_mutations':0,'live_provider_calls':0}),
            ('shared_account_lifecycle','test_shared_account_lifecycle.py','cases',24,
                {'actual_php':True,'owned_socket_mariadb':True,'two_distinct_users':True,
                 'concurrent_workers':2,'shared_cycle_one_payer':True,'paying_order_only':True,'full_peer_row_unchanged':True,'fresh_native_shared_cycle_refusal':True,'stale_callback_safe':True,
                 'pending_peer_work_held':True,'explicit_panel_group_authority':True,
                 'native_provider_HTTP':False,'external_requests':0,'production_mutations':0,'new_schema':False}),
            ('allowance_admin','test_unified_allowance_admin.py','cases',89,
                {'actual_plan_screen':True,'actual_admin_subscription_report':True,'actual_order_management_screen':True,'real_disposable_mariadb':True,'real_JDF':True,'finite_remaining_zero_not_unlimited':True,'read_only_screens':True,'archived_current_baseline_RED':3,'network_calls':0,'production_mutations':0,'display_baseline':'f4731de2922adb96d005e523f75ec067907b2e05'}),
            ('canonical_checkout','test_canonical_checkout.py','cases',143,
                {'native_http':True,'actual_public_controller':True,'owned_socket_mariadb':True,'complete_issuance_body_preserved':True,'pay_back_only_canonical_import_changed':True,'retired_routes_absent':True,'GET_only':True,'no_database_failure_private':True,'indirect_include_preserved':True,'external_requests':0,'production_mutations':0}),
        ]
        for name,script,counter,minimum,proofs in suites:
            path=Path(tmp)/(name+'.json')
            subprocess.run(['python3',str(ROOT/'tools'/script),'--run-disposable','--report',str(path)],
                cwd=ROOT,check=True,timeout=300,capture_output=True)
            evidence=json.loads(path.read_text())
            if evidence.get('complete') is not True or evidence.get(counter,0)<minimum or any(evidence.get(k)!=v for k,v in proofs.items()):
                raise RuntimeError('Unified service-management evidence incomplete: '+name)
            unified[name]=evidence
        result['unified_service_management']=unified
        webhook_path=Path(tmp)/"webhook-file-config.json"
        subprocess.run(["python3",str(ROOT/"tools/test_post_v9_webhook_config.py"),
                        "--run-disposable","--report",str(webhook_path)],
                       cwd=ROOT,check=True,timeout=30,capture_output=True)
        webhook_config=json.loads(webhook_path.read_text())
        if not (webhook_config.get("complete") is True
                and webhook_config.get("cases")==7
                and webhook_config.get("baseline_red") is True
                and webhook_config.get("real_php_http") is True
                and webhook_config.get("loopback_only") is True
                and webhook_config.get("production_mutations")==0
                and webhook_config.get("external_provider_calls")==0):
            raise RuntimeError("Per-install baseInfo webhook authentication incomplete")
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
        if not (cron.get("complete") and cron.get("cases")>=50
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
        if not (marzban.get("complete") and
                marzban.get("marzban_http_wire_sql_parity") and
                marzban.get("minimal_service_edit_payload_verified")):
            raise RuntimeError("Current candidate Marzban native source proof incomplete")
        edit_path=Path(tmp)/"service-edit-native.json"
        subprocess.run(["python3",str(ROOT/"tools/test_post_v9_marzban_edits.py"),
                        "--run-disposable","--report",str(edit_path)],
                       cwd=ROOT,check=True,timeout=240,capture_output=True)
        edits=json.loads(edit_path.read_text())
        required_edits={
            'edit-verified_source_native_verified',
            'edit-lost-applied_source_native_reconciled',
            'edit-repair_source_native_repaired',
            'edit-lost-repair_source_native_repaired',
            'edit-badbody-repair_source_native_repaired',
            'edit-noop_source_native_unverified',
            'edit-bad-number-baseline_source_native_unverified',
            'edit-bad-number-readback_source_native_unverified',
            'edit-foreign_source_native_unverified',
            'edit-reset-verified_source_native_verified',
            'edit-reset-lie_source_native_reset_unverified',
            'review_b1_foreign_disabled_no_corrective_put',
            'review_b1_foreign_expired_no_corrective_put',
            'review_b1_foreign_inbounds_no_corrective_put',
            'review_b1_foreign_proxies_no_corrective_put',
            'review_b1_foreign_reset_strategy_no_corrective_put',
            'review_b1_foreign_note_no_corrective_put',
            'review_b1_disabled_original_not_reactivated',
            'review_upstream_next_plan_quota_preserved_before_remote',
            'review_upstream_next_plan_combined_renew_preserved_before_remote',
            'review_upstream_next_plan_quota_detected_before_first_effect',
            'review_upstream_next_plan_combined_renew_detected_before_first_effect',
            'review_b1_quota_edit_preserves_foreign_expiry',
            'review_b1_day_edit_preserves_foreign_quota',
            'review_b1_foreign_before_initial_put_disabled_not_overwritten',
            'review_b1_foreign_before_initial_put_inbounds_not_overwritten',
            'review_b1_foreign_before_initial_put_proxies_not_overwritten',
            'review_b1_foreign_before_initial_put_reset_strategy_not_overwritten',
            'review_b1_foreign_before_initial_put_note_not_overwritten',
            'review_b1_authorized_expired_days_reactivation',
            'review_b1_authorized_expired_full_renew_reactivation',
            'review_b1_authorized_limited_quota_reactivation',
            'review_b1_disabled_duration_does_not_override_operator',
            'review_b2_endpoint_drift_before_reset_no_foreign_request',
            'review_b2_endpoint_drift_after_reset_no_foreign_request',
            'review_r3_reset_partial_must_stay_under_review',
            'review_r3_reset_zero_to_nonzero_must_stay_under_review',
            'review_r3_reset_fractional_must_stay_under_review',
            'review_r3_reset_exponent_must_stay_under_review',
            'review_r3_reset_overflow_must_stay_under_review',
            'review_r3_reset_missing_must_stay_under_review',
            'review_b1_after_reset_foreign_status_no_put',
            'review_b1_after_reset_foreign_inbounds_no_put',
            'review_r4_signed_expiry_max',
            'review_r4_signed_expiry_beyond',
            'review_r4_signed_expiry_long_renew',
            'panel_neutral_dispatch_to_current_verified_marzban_adapter',
            'pasarguard_missing_migration_identity_denied_without_remote_dispatch',
            'unknown_provider_denied_without_remote_dispatch',
            'two_concurrent_distinct_edits_serialized_no_lost_increment',
            'physical_panel_alias_different_urls_group_lock_no_lost_increment',
            'missing_physical_panel_group_rejects_before_remote_dispatch',
            'fractional_volume_delta_never_truncated_or_approved',
            'fractional_day_delta_never_truncated_or_approved',
            'fractional_plan_quota_never_truncated_or_approved',
            # R6 canonical provider unlimited vs missing/invalid quota.
            'review_r6_unlimited_existing_null_days_exact_verified_readback',
            'review_r6_unlimited_existing_zero_days_exact_verified_readback',
            'review_r6_unlimited_unlimited_renewal_exact_verified_readback',
            'review_r6_unlimited_unlimited_to_finite_exact_verified_readback',
            'review_r6_unlimited_finite_to_unlimited_exact_verified_readback',
            'review_r6_unlimited_increment_refused_without_downgrade_null',
            'review_r6_unlimited_increment_refused_without_downgrade_zero',
            'review_r6_invalid_missing_no_remote_effect',
            'review_r6_invalid_fractional_no_remote_effect',
            'review_r6_invalid_negative_no_remote_effect',
            'review_r6_invalid_exponent_no_remote_effect',
            'review_r6_invalid_malformed_no_remote_effect',
            'review_r6_invalid_overflow_no_remote_effect',
        }
        # Explicit nullable-expiry full-renewal proof; all prior 68 cases remain.
        required_edits.update({
            'null_expiry_boolean_days_rejected_without_partial_remote_effect',
            'null_expiry_boolean_expiry_rejected_without_partial_remote_effect',
            'null_expiry_exact_240_adapter_RED_full_renewal_rejected_before_reset_or_put',
            'null_expiry_existing_zero_api_semantics_preserved_against_exact_240',
            'null_expiry_exponent_expiry_rejected_without_partial_remote_effect',
            'null_expiry_fractional_days_rejected_without_partial_remote_effect',
            'null_expiry_fractional_expiry_rejected_without_partial_remote_effect',
            'null_expiry_fractional_volume_rejected_without_partial_remote_effect',
            'null_expiry_invalid_relative_precedence_rejected_without_partial_remote_effect',
            'null_expiry_malformed_expiry_rejected_without_partial_remote_effect',
            'null_expiry_missing_expiry_rejected_without_partial_remote_effect',
            'null_expiry_negative_days_rejected_without_partial_remote_effect',
            'null_expiry_negative_expiry_rejected_without_partial_remote_effect',
            'null_expiry_null_days_rejected_without_partial_remote_effect',
            'null_expiry_overflow_expiry_rejected_without_partial_remote_effect',
            'null_expiry_overflow_local_days_rejected_without_partial_remote_effect',
            'null_expiry_positive_whole_days_bounded_original_NULL_correction_finite_verified_without_unrelated_changes',
            'null_expiry_positive_whole_days_days_replacement_only_finite_verified_without_unrelated_changes',
            'null_expiry_positive_whole_days_full_active_finite_verified_without_unrelated_changes',
            'null_expiry_positive_whole_days_full_limited_finite_verified_without_unrelated_changes',
            'null_expiry_positive_whole_days_no_effective_plus_day_finite_verified_without_unrelated_changes',
            'null_expiry_post_reset_missing_drift_never_puts_or_repeats_reset',
            'null_expiry_post_reset_zero_drift_never_puts_or_repeats_reset',
            'null_expiry_pre_effect_finite_drift_blocks_first_remote_effect',
            'null_expiry_pre_effect_fractional_drift_blocks_first_remote_effect',
            'null_expiry_pre_effect_missing_drift_blocks_first_remote_effect',
            'null_expiry_pre_effect_zero_drift_blocks_first_remote_effect',
            'null_expiry_relative_day_only_rejected_without_partial_remote_effect',
            'null_expiry_relative_day_precedence_rejected_without_partial_remote_effect',
            'null_expiry_unsupported_field_rejected_without_partial_remote_effect',
            'null_expiry_volume_delta_only_rejected_without_partial_remote_effect',
            'null_expiry_volume_only_rejected_without_partial_remote_effect',
            'null_expiry_zero_days_rejected_without_partial_remote_effect',
        })
        if not (edits.get('complete') and edits.get('cases')==len(required_edits)
                and required_edits.issubset(edits.get('scenarios',[]))
                and edits.get('actual_php_mysqli_curl')
                and edits.get('real_provider_requests')==0
                and edits.get('absolute_correction_not_relative_replay')
                and edits.get('null_expiry_full_replacement_verified') is True
                and edits.get('null_expiry_baseline_red') is True
                and edits.get('null_expiry_baseline_source')=='2408982797af15bc99e1df2909c13be3976cb1f4'
                and edits.get('null_expiry_baseline_adapter_sha256')=='a4fedde0b77ffb6a18995eda0b9309364fb9ee853a1cc951ee3e55d79d86c188'
                and edits.get('null_expiry_invalid_no_partial_effect') is True
                and edits.get('null_expiry_zero_semantics_preserved') is True
                and edits.get('verified_native_before_expire') is True
                and edits.get('native_before_expire_negative_controls') is True):
            raise RuntimeError('Provider-neutral service edit native assurance incomplete')
        purchase_path=Path(tmp)/"marzban-purchase.json"
        subprocess.run(["python3",str(ROOT/"tools/test_post_v9_purchases.py"),
                        "--run-disposable","--report",str(purchase_path)],
                       cwd=ROOT,check=True,timeout=300,capture_output=True)
        purchases=json.loads(purchase_path.read_text())
        # Seven scenarios retain exact financial/provider parity; one has the explicit R5 denial notice.
        # The owner-requested actor draft replaces the old global scratch row;
        # require its actual historical/current proof instead of claiming parity.
        draft_amendments=purchases.get("intentional_behavior_amendments",[])
        draft_proof={
            "case":"admin_plan_create_draft","historical_v9_executed":True,
            "historical_global_inactive_draft_reproduced":True,"frozen_driver_unchanged":True,
            "frozen_driver_sha256":"09f6618ec2e2e130c446997604475e08de721df9a9a709cb568e155f8858829c",
            "actual_candidate_dispatcher":True,"candidate_private_fresh_frame":True,
            "actor_owned_draft_persisted":True,"no_candidate_server_plans_mutation":True,
            "unrelated_inactive_plan_preserved":True,"unrelated_admin_state_preserved":True,
            "financial_snapshot_unchanged":True,"provider_calls":0,
        }
        if not (purchases.get("complete") and purchases.get("scenarios")==8 and
                purchases.get("historical_v9")=="7eb077fd8e6d0a303421626d1500f69e295a6664" and
                purchases.get("schema_tables")==23 and purchases.get("mysql_socket_only") is True and
                purchases.get("php_sql_telegram_fake_provider_parity") is False and
                purchases.get("sql_provider_wire_exact") is False and
                purchases.get("unchanged_differential_scenarios")==7 and
                purchases.get("unchanged_differential_sql_provider_wire_exact") is True and
                purchases.get("unchanged_differential_telegram_comparison")=="exact_except_authorized_literal_UI_and_one_actor_local_denial_notice" and
                purchases.get("telegram_comparison")=="six_style_scoped_cases_one_actor_local_denial_notice_and_one_actor_draft_amendment" and
                purchases.get("actor_owned_plan_draft_entry_verified") is True and
                draft_amendments==[draft_proof] and
                purchases.get("ui_projection_baseline")=="7faac9b067f8a171e5e26d6aea5aaf9f4088a3e7" and
                purchases.get("verified_literal_style_pairs")==582 and
                purchases.get("authorized_ui_field_differences")==3 and
                purchases.get("ui_projection_negative_controls")==17 and
                len(purchases.get("rejected_projection_mutations",[]))==17 and
                purchases.get("r5_ui_lineage_negative_controls")==5 and
                purchases.get("r5_denial_notice_amendment_count")==1 and
                purchases.get("r5_denial_notice_negative_controls")==12 and
                purchases.get("r5_denial_notice_amendments")==[{
                    "case":"nonadmin_bulk_denied","step":0,"route":"entryandbulk",
                    "catalog_key":"admin.roles.denied",
                    "delivery":"one_actor_local_sendMessage_null_keys_HTML",
                    "archived_events_empty":True,"sql_provider_wire_exact":True}] and
                {(f.get("case"),f.get("step"),f.get("event_name"),f.get("argument_index"),tuple(f.get("field_path",[])))
                 for f in purchases.get("authorized_ui_fields",[])}=={
                    ("admin_plan_price_edit",1,"sendMessage",0,()),
                    ("interval_plan_manage",0,"editText",1,()),
                    ("interval_plan_manage",0,"editText",2,("inline_keyboard",0,0,"text"))} and
                len(purchases.get("changed_paid_nonpaid_source_contracts",[]))==9 and
                purchases.get("new_source_contracts_mandatory_in_full_aggregate")):
            raise RuntimeError("Current purchasing financial/protocol and authorized UI parity incomplete")
        transport_cli=subprocess.run(
            ["php","-n",str(ROOT/"tools/test_post_v9_telegram_log_privacy.php")],
            cwd=ROOT,check=True,timeout=20,capture_output=True,text=True)
        transport_privacy=json.loads(transport_cli.stdout)
        if not (transport_privacy.get("complete")
                and transport_privacy.get("cases")==7
                and transport_privacy.get("network_requests")==0
                and transport_privacy.get("raw_payload_log_records")==0):
            raise RuntimeError("Telegram transport failure logs exposed private data")
        wallet_guard_path=Path(tmp)/"native-wallet-preflight.json"
        subprocess.run(["python3",str(ROOT/"tools/test_post_v9_wallet_guard.py"),
                        "--run-disposable","--report",str(wallet_guard_path)],
                       cwd=ROOT,check=True,timeout=180,capture_output=True,text=True)
        wallet_guard=json.loads(wallet_guard_path.read_text())
        if not (wallet_guard.get("complete") and wallet_guard.get("cases")==7
                and wallet_guard.get("manual_cases")==7
                and wallet_guard.get("http_calls")==0
                and wallet_guard.get("committed_sql_mutations")==0
                and wallet_guard.get("total_cases")==15
                and wallet_guard.get("actual_php_mysqli") is True
                and wallet_guard.get("select_only_account_enforced") is True
                and wallet_guard.get("original_23_table_snapshots_unchanged") is True
                and wallet_guard.get("wallet_rejects_before_transaction") is True
                and wallet_guard.get("manual_rejects_single_rollback") is True):
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
        if not (finance_privacy.get("complete") and finance_privacy.get("cases")==29
                and finance_privacy.get("fresh_native_addon_admission") is True
                and finance_privacy.get("unknown_native_refuses_preclaim") is True
                and finance_privacy.get("mutant_privacy_failures_proved")==2
                and finance_privacy.get("external_requests")==0):
            raise RuntimeError("Executed finance error privacy/uncertain-effects proof incomplete")
        required_transfer_cases={
            "transfer_positive_atomic", "transfer_replay_claim_consumed",
            "transfer_baseline_red_money_moves_without_journal",
            "transfer_paired_immutable_journal_exact_evidence",
            "transfer_journal_redelivery_after_step_restart_no_effect",
            "transfer_missing_or_incompatible_journal_fails_closed",
            "transfer_second_journal_leg_failure_rolls_back_everything",
            "transfer_source_rejects_callback", "transfer_source_rejects_group",
            "transfer_source_rejects_actor", "transfer_source_rejects_chat",
            "transfer_source_rejects_message", "transfer_source_rejects_text",
            "transfer_source_rejects_missing_update",
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
        replay_path=Path(tmp)/"wallet-replay-r1-r2.json"
        subprocess.run(["python3",str(ROOT/"tools/test_post_v9_wallet_replay.py"),
                        "--run-disposable","--report",str(replay_path)],
                       cwd=ROOT,check=True,timeout=120,capture_output=True)
        wallet_replay=json.loads(replay_path.read_text())
        required_wallet_replay={
            'r1_reviewed_source_red_accepts_older_amount',
            'r2_reviewed_source_red_admin_then_transfer',
            'r2_reviewed_source_red_transfer_then_admin',
            'r1_reject_older_prior_stage',
            'r1_reject_recipient_selection_redelivery',
            'r1_reject_older_update_newer_message',
            'r1_reject_newer_update_older_message',
            'r1_reject_equal_update', 'r1_reject_equal_message',
            'r1_amount_redelivery_after_real_restart_rejected',
            'r1_stale_selection_snapshot_cannot_overwrite_new_stage',
            'r1_selection_sql_failure_rolls_back_and_separates_diagnostics',
            'r1_genuinely_newer_amount_commits_exact_pair',
            'r1_legacy_unmarked_and_malformed_steps_require_restart',
            'r1_recipient_selection_requires_authentic_private_source',
            'r2_current_admin_then_transfer_rejected',
            'r2_current_transfer_then_admin_rejected',
        }
        if not (wallet_replay.get('complete') is True
                and wallet_replay.get('reviewed_source') ==
                    '388e06358bef9f62dec75a7ebfe7db13a669e282'
                and set(wallet_replay.get('scenarios',[]))==required_wallet_replay
                and wallet_replay.get('cases')==len(required_wallet_replay)
                and wallet_replay.get('baseline_red') is True
                and wallet_replay.get('current_green') is True
                and wallet_replay.get('real_php_mysqli') is True
                and wallet_replay.get('owned_mariadb_only') is True
                and wallet_replay.get('production_mutations')==0
                and wallet_replay.get('real_telegram_or_provider_calls')==0):
            raise RuntimeError('Transfer source freshness/cross-operation replay proof incomplete')
        generation_path=Path(tmp)/"wallet-transfer-generation-r3.json"
        subprocess.run(["python3",str(ROOT/"tools/test_post_v9_transfer_generation.py"),
                        "--run-disposable","--report",str(generation_path)],
                       cwd=ROOT,check=True,timeout=180,capture_output=True)
        transfer_generation=json.loads(generation_path.read_text())
        required_transfer_generation={
            'r3_reviewed_red_old_selection_hijacks_new_generation',
            'r3_old_selection_after_new_start_preserves_entire_state',
            'r3_old_duplicate_and_out_of_order_starts_preserve_recipient_stage',
            'r3_stale_cached_selection_cannot_claim_new_generation',
            'r3_fresh_b_selection_and_amount_use_recipient_y',
            'r3_completed_flow_rejects_old_start_callbacks',
            'r3_navigation_reset_retains_cursor_and_new_flow_still_completes',
            'r3_old_menu_reuse_never_lowers_private_message_floor',
            'r3_two_start_workers_claim_exactly_once',
            'r3_reordered_start_workers_converge_to_newest_generation',
            'r3_start_requires_authentic_private_callback',
            'r3_duplicate_cursor_fails_closed',
            'r3_malformed_cursor_fails_closed',
            'r3_nontransactional_cursor_fails_closed',
            'r3_cursor_write_failure_rolls_back_balances_legs_and_step',
            'r3_replaced_recipient_requires_new_selection',
        }
        if not (transfer_generation.get('complete') is True
                and transfer_generation.get('reviewed_source') ==
                    '18aa3629290db60a1a6e7c9be9c9f47464dcf333'
                and set(transfer_generation.get('scenarios',[]))==required_transfer_generation
                and transfer_generation.get('cases')==len(required_transfer_generation)
                and transfer_generation.get('baseline_red') is True
                and transfer_generation.get('current_green') is True
                and transfer_generation.get('real_php_mysqli') is True
                and transfer_generation.get('owned_mariadb_only') is True
                and transfer_generation.get('production_mutations')==0
                and transfer_generation.get('external_requests')==0):
            raise RuntimeError('Transfer initiation/generation replay proof incomplete')
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
            "schema_without_cross_operation_event_key_fails_closed",
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
        if not (admin_wallet.get("complete") and admin_wallet.get("cases")==len(required_admin_wallet)
                and required_admin_wallet.issubset(admin_wallet.get("scenarios",[]))
                and admin_wallet.get("real_php_workers")==2
                and admin_wallet.get("disposable_db") is True
                and admin_wallet.get("production_mutations")==0
                and admin_wallet.get("external_provider_calls")==0):
            raise RuntimeError("Administrator wallet journal and debt proof incomplete")
        wallet_ux_path=Path(tmp)/"admin-wallet-ux.json"
        subprocess.run(["python3",str(ROOT/"tools/test_post_v9_admin_wallet_ux.py"),
                        "--run-disposable","--report",str(wallet_ux_path)],
                       cwd=ROOT,check=True,timeout=180,capture_output=True)
        wallet_ux=json.loads(wallet_ux_path.read_text())
        if not (wallet_ux.get("complete") is True and wallet_ux.get("cases")==33
                and wallet_ux.get("source_native_php") is True
                and wallet_ux.get("disposable_db") is True
                and wallet_ux.get("production_mutations")==0
                and wallet_ux.get("external_provider_calls")==0):
            raise RuntimeError("Two-control wallet consent/committed-notice proof incomplete")
        next_payment_path=Path(tmp)/"next-payment-ux.json"
        subprocess.run(["python3",str(ROOT/"tools/test_next_payment_ux.py"),
                        "--run-disposable","--report",str(next_payment_path)],
                       cwd=ROOT,check=True,timeout=180,capture_output=True)
        next_payment=json.loads(next_payment_path.read_text())
        if not (next_payment.get("complete") is True and next_payment.get("cases")==13
                and next_payment.get("fresh_native_addon_admission") is True
                and next_payment.get("unknown_native_refuses_without_draft") is True):
            raise RuntimeError("Durable payment messages, volume entry and attention proof incomplete")
        catalog_output=subprocess.run(
            ["python3",str(ROOT/"tools/test_next_text_catalog.py")],
            cwd=ROOT,check=True,timeout=180,capture_output=True,text=True)
        text_catalog=json.loads(catalog_output.stdout)
        if not (text_catalog.get("status")=="PASS"
                and text_catalog.get("source_files")==78
                and text_catalog.get("pre_style_source_equivalent_occurrences")==3202
                and text_catalog.get("presentation",{}).get("owner_followup_changes")==7
                and text_catalog.get("presentation",{}).get("owner_followup_negative_controls")==7
                and text_catalog.get("native",{}).get("current_owner_followup_changes")==7
                and text_catalog.get("static_ui_violations")==0
                and text_catalog.get("ascii_ui_violations")==0
                and text_catalog.get("ascii_audit_negative_controls")==8
                and text_catalog.get("management_ascii_audit_controls")==2
                and text_catalog.get("machine_predicate_audit_controls")==2
                and text_catalog.get("presentation",{}).get("management_provider_label_cases")==11
                and text_catalog.get("migrated_machine_fields_or_sql_arguments")==0
                and text_catalog.get("direct_http_guards")==4
                and text_catalog.get("native",{}).get("pre_style_templates")==3066
                and text_catalog.get("native",{}).get("pre_style_interpolated_templates")==293
                and text_catalog.get("native",{}).get("pre_style_byte_assertions")==4839
                and text_catalog.get("authorized_source_dependency_files")==3
                and text_catalog.get("authorized_bootstrap_flow_changes")==1
                and text_catalog.get("approved_public_copy_corrections")==3
                and text_catalog.get("native",{}).get("pre_style_byte_preserved_templates")==3063
                and text_catalog.get("native",{}).get("pre_style_privacy_corrections")==3
                and text_catalog.get("native",{}).get("actual_traffic_helper_parity_cases")==18
                and text_catalog.get("editable_catalog_files")==2
                and text_catalog.get("logical_topics")==26
                and text_catalog.get("current_authorized_source_edits")==26
                and text_catalog.get("retired_parser_tokens")==23
                and text_catalog.get("relocation_source_ref")=="ecd30ba9192ed374703118a6c131be8809cc7b06"
                and text_catalog.get("native",{}).get("current_retired_feature_keys")==3
                and text_catalog.get("catalog_keys")==2771
                and text_catalog.get("native",{}).get("editable_catalog_files")==2
                and text_catalog.get("native",{}).get("logical_topics")==26
                and text_catalog.get("native",{}).get("current_catalog_keys")==2771
                and text_catalog.get("native",{}).get("current_management_added_keys")==112
                and text_catalog.get("native",{}).get("current_catalog_binding_paths")==2
                and text_catalog.get("native",{}).get("current_catalog_binding_entries")==4
                and text_catalog.get("native",{}).get("current_catalog_binding_negative_controls")==4
                and text_catalog.get("native",{}).get("retired_current_facade_absent") is True
                and text_catalog.get("native",{}).get("current_traffic_shared_observation_cases")==28
                and text_catalog.get("native",{}).get("current_retired_implementation_keys")==23
                and text_catalog.get("native",{}).get("current_added_admin_keys")==1
                and text_catalog.get("native",{}).get("current_parameter_privacy_changes")==3
                and text_catalog.get("native",{}).get("current_style_changes",0)>0
                and text_catalog["native"]["current_byte_preserved_keys"]
                    +text_catalog["native"]["current_style_changes"]
                    +text_catalog["native"]["current_added_admin_keys"]==2523
                and text_catalog.get("native",{}).get("current_native_template_assertions")==4101
                and text_catalog.get("native",{}).get("current_object_conversion_templates")==266
                and text_catalog.get("native",{}).get("current_shared_multi_parameter_templates")==65
                and text_catalog.get("native",{}).get("current_one_pass_templates")==266
                and text_catalog.get("native",{}).get("current_r5_changes")==273
                and text_catalog.get("native",{}).get("current_r5_retired_keys")==10
                and text_catalog.get("native",{}).get("current_r5_added_keys")==60
                and text_catalog.get("presentation",{}).get("r5_negative_controls")==5
                and text_catalog.get("native",{}).get("current_non_ascii_digit_templates")==0
                and text_catalog.get("presentation",{}).get("status")=="PASS"
                and text_catalog.get("presentation",{}).get("editable_files")==2
                and text_catalog.get("presentation",{}).get("current_keys")==2771
                and text_catalog.get("presentation",{}).get("numeric_template_changes")==23
                and text_catalog.get("presentation",{}).get("adjacent_emoji_violations")==0
                and text_catalog.get("presentation",{}).get("parameter_exception_count")==3
                and text_catalog.get("presentation",{}).get("semantic_label_icon_cases")==12
                and text_catalog.get("presentation",{}).get("semantic_context_icon_cases")==16
                and text_catalog.get("presentation",{}).get("native_compositions")==6
                and text_catalog.get("presentation",{}).get("reviewed_semantic_context_cases")==9
                and text_catalog.get("presentation",{}).get("archived_semantic_context_RED")==7
                and text_catalog.get("presentation",{}).get("recipient_placement_cases")==12
                and text_catalog.get("presentation",{}).get("archived_recipient_placement_RED")==12
                and text_catalog.get("presentation",{}).get("recipient_payloads_byte_unchanged") is True
                and text_catalog.get("presentation",{}).get("recipient_source_files")==3
                and text_catalog.get("presentation",{}).get("recipient_authorized_role_layers")==1
                and text_catalog.get("presentation",{}).get("recipient_source_layer_negative_controls")==3
                and text_catalog.get("presentation",{}).get("recipient_authorized_ticket_layers")==2
                and text_catalog.get("presentation",{}).get("recipient_ticket_layer_negative_controls")==7
                and text_catalog.get("presentation",{}).get("recipient_staff_capability_conditions")==2
                and text_catalog.get("presentation",{}).get("recipient_staff_layer_negative_controls")==4
                and text_catalog.get("presentation",{}).get("recipient_total_source_layer_negative_controls")==14):
            raise RuntimeError("Pre-style relocation or current two-file/audience/presentation proof incomplete")
        required_api_catalog={
            'runtime_references':2458,
            'api_referenced_keys':82,
            'api_dynamic_source_paths':3,
            'api_reference_source_commit':'259598e70b82bf1f144175b5fef4dd78ace25819',
            'api_retained_unreferenced_keys':4,
            'api_reference_negative_controls':6,
        }
        required_api_native_catalog={
            'current_api_additions':86,
            'current_api_user_additions':22,
            'current_api_admin_additions':64,
            'current_r5_catalog_keys':2685,
        }
        for section,proof,required in [('references',text_catalog,required_api_catalog),
                ('native',text_catalog.get('native',{}),required_api_native_catalog)]:
            if any(type(proof.get(key)) is not type(value) or proof.get(key)!=value
                   for key,value in required.items()):
                raise RuntimeError('Current API catalog source proof incomplete: '+section)
        copy_audience_output=subprocess.run(
            ["python3",str(ROOT/"tools/test_next_copy_audience.py")],
            cwd=ROOT,check=True,timeout=60,capture_output=True,text=True)
        copy_audience=json.loads(copy_audience_output.stdout)
        if not (copy_audience.get("complete") is True
                and copy_audience.get("cases")==16
                and copy_audience.get("archived_disclosure_RED")==2
                and copy_audience.get("actual_customer_admin_separation") is True
                and copy_audience.get("actual_stats_parser_equivalence") is True
                and copy_audience.get("actual_ascii_date_output") is True
                and copy_audience.get("database_provider_network_calls")==0
                and copy_audience.get("presentation_only") is True):
            raise RuntimeError("Actual copy audience, parser and ASCII date evidence incomplete")
        bootstrap_output=subprocess.run(
            ["python3",str(ROOT/"tools/test_next_bootstrap_privacy.py")],
            cwd=ROOT,check=True,timeout=60,capture_output=True,text=True)
        bootstrap_privacy=json.loads(bootstrap_output.stdout)
        if not (bootstrap_privacy.get("status")=="PASS" and bootstrap_privacy.get("cases")==16
                and bootstrap_privacy.get("archived_disclosure_RED")==2
                and bootstrap_privacy.get("actual_bootstrap_prefix") is True
                and bootstrap_privacy.get("exception_and_nonthrowing_connection_charset") is True
                and bootstrap_privacy.get("owned_http_only") is True
                and bootstrap_privacy.get("real_database_or_provider_calls")==0):
            raise RuntimeError("Public bootstrap throwing/nonthrowing failure privacy proof incomplete")
        gateway_output=subprocess.run(
            ["python3",str(ROOT/"tools/test_next_gateway_privacy.py"),"--run-disposable"],
            cwd=ROOT,check=True,timeout=180,capture_output=True,text=True)
        gateway_privacy=json.loads(gateway_output.stdout)
        if not (gateway_privacy.get("status")=="PASS" and gateway_privacy.get("cases")==12
                and gateway_privacy.get("actual_public_pay_entry") is True
                and gateway_privacy.get("actual_initiation_and_renderer") is True
                and gateway_privacy.get("archived_unsafe_copy_RED")==3
                and gateway_privacy.get("static_audience_negative_controls")==3
                and gateway_privacy.get("native_public_paths")==3
                and gateway_privacy.get("http_public_paths")==3
                and gateway_privacy.get("financial_snapshot_unchanged") is True
                and gateway_privacy.get("provider_or_telegram_calls")==0
                and gateway_privacy.get("owned_mariadb_and_loopback_only") is True):
            raise RuntimeError("Public gateway source/HTTP audience privacy proof incomplete")
        unit_path=Path(tmp)/"service-unit-guards.json"
        subprocess.run(["python3",str(ROOT/"tools/test_post_v9_service_unit_guards.py"),
                        "--run-disposable","--report",str(unit_path)],
                       cwd=ROOT,check=True,timeout=180,capture_output=True)
        unit_guards=json.loads(unit_path.read_text())
        if not (unit_guards.get("complete") is True and unit_guards.get("cases")==29
                and unit_guards.get("actual_php_mysqli") is True
                and unit_guards.get("owned_socket_mariadb") is True
                and unit_guards.get("provider_requests")==0
                and unit_guards.get("production_mutations")==0):
            raise RuntimeError("Whole-unit immutable add-on quote/claim proof incomplete")
        pg_client_path=Path(tmp)/"pasarguard-client.json"
        subprocess.run(["python3",str(ROOT/"tools/test_post_v9_pasarguard_client.py"),
                        "--run-disposable","--report",str(pg_client_path)],
                       cwd=ROOT,check=True,timeout=240,capture_output=True)
        pg_client=json.loads(pg_client_path.read_text())
        if not (pg_client.get("complete") is True and pg_client.get("cases",0)>=99
                and pg_client.get("real_php_curl") is True
                and pg_client.get("disposable_socket_mariadb") is True):
            raise RuntimeError("Native PasarGuard transport and persistence proof incomplete")
        cleanup_path=Path(tmp)/"cleanup.json"
        subprocess.run(["python3",str(ROOT/"tools/test_post_v9_cleanup.py"),
                        "--run-disposable","--report",str(cleanup_path)],
                       cwd=ROOT,check=True,timeout=180,capture_output=True)
        cleanup=json.loads(cleanup_path.read_text())
        if not (cleanup.get("complete") is True and cleanup.get("count")==16
                and cleanup.get("live_database_touched") is False
                and cleanup.get("live_files_touched") is False):
            raise RuntimeError("Optional cleanup safety proof incomplete")
        presentation_output=subprocess.check_output(
            ["php",str(ROOT/"tools/test_subscription_presentation.php")],
            cwd=ROOT,text=True,timeout=60)
        if not re.search(r"PASS subscription presentation: 251 checks \(158 context checks\);",presentation_output):
            raise RuntimeError("Native subscription presentation proof incomplete")
        presentation={"complete":True,"checks":251,"retained_checks":93,"context_checks":158,"real_network":False}
        simple_display=json.loads(subprocess.check_output(
            ["php",str(ROOT/"tools/test_simple_server_display.php")],cwd=ROOT,text=True,timeout=30))
        if simple_display!={"complete":True,"cases":157,"source_real":True,"two_native_providers":True,
                "whole_message_budget":True,"all_explicit_config_qrs":True,"production_effects":0}:
            raise RuntimeError("Simple display/context/complete-message boundary proof incomplete")
        # Reproduce the new behavioral RED against the unchanged RC18/main source.
        import io,tarfile
        simple_baseline="ec171d5bdd2d4487b8bc2c5782015f25c703cce6"
        simple_old=Path(tmp)/"simple-display-baseline";simple_old.mkdir()
        with tarfile.open(fileobj=io.BytesIO(cmd("archive",simple_baseline))) as archive:
            archive.extractall(simple_old,filter="data")
        simple_red=subprocess.run(["php",str(ROOT/"tools/test_simple_server_display.php"),str(simple_old)],
                                  cwd=ROOT,capture_output=True,text=True,timeout=30)
        if simple_red.returncode==0 or "marzbandelivery_default_first_three" not in simple_red.stderr:
            raise RuntimeError("Prior unconfigured-display regression RED missing")
        simple_display.update({"baseline":simple_baseline,"baseline_red":True})
        provider_path=Path(tmp)/"provider-integration.json"
        subprocess.run(["python3",str(ROOT/"tools/test_post_v9_provider_integration.py"),
                        "--run-disposable","--report",str(provider_path)],
                       cwd=ROOT,check=True,timeout=240,capture_output=True)
        provider_integration=json.loads(provider_path.read_text())
        native_renewal_common={
            'complete':True,'actual_php':True,'owned_socket_mariadb':True,
            'actual_financial_dispatch':True,'baseline_red':True,
            'baseline_source':'2408982797af15bc99e1df2909c13be3976cb1f4',
            'current_green':True,'null_expiry_native_renewal_verified':True,
            'consent_off_verified':True,'wallet_debit_once':True,
            'completed_payment_attempt_and_order':True,'no_tick_replay':True,
            'external_requests':0,'production_mutations':0,
        }
        native_renewal_proofs=[provider_integration.get(key,{}) for key in [
            'native_unlimited_renewal','native_unlimited_renewal_marzban',
            'native_unlimited_renewal_both_providers']]
        native_renewal_complete=all(isinstance(proof,dict) and all(
            proof.get(key)==value for key,value in native_renewal_common.items())
            for proof in native_renewal_proofs)
        if not (provider_integration.get("complete") is True and provider_integration.get("cases",0)>=283
                and provider_integration.get("actual_php_curl") is True
                and provider_integration.get("owned_socket_mariadb") is True
                and provider_integration.get("real_provider_tested") is False
                and provider_integration.get("lifecycle_cases")==243
                and provider_integration.get("admin_read_authorization_cases")==40
                and provider_integration.get("forbidden_order_queries")==0
                and provider_integration.get("forbidden_provider_requests")==0
                and provider_integration.get("baseline_red") is True
                and provider_integration.get("baseline_red_source")=="fb138eacdc5817922cc1e1c9c200323d24c79838"
                and provider_integration.get("original_provider_integration_cases")==220
                and native_renewal_complete
                and [proof.get('cases') for proof in native_renewal_proofs]==[43,20,63]
                and native_renewal_proofs[0].get('loopback_native_pasar_guard') is True
                and native_renewal_proofs[0].get('null_expiry_extension_scope_preserved') is True
                and native_renewal_proofs[0].get('native_identity_groups_and_quote_pins_preserved') is True
                and native_renewal_proofs[1].get('loopback_native_marzban') is True
                and native_renewal_proofs[1].get('native_configuration_preserved') is True
                and native_renewal_proofs[2].get('both_adapters_verified') is True):
            raise RuntimeError("Native provider lifecycle and durable intent proof incomplete")
        currency_path=Path(tmp)/"currency-settings.json"
        subprocess.run(["python3",str(ROOT/"tools/test_next_currency_settings.py"),
                        "--run-disposable","--report",str(currency_path)],
                       cwd=ROOT,check=True,timeout=180,capture_output=True)
        currency=json.loads(currency_path.read_text())
        required_currency={
            'no_settings_ignores_private_globals','shared_normal',
            'minimum_normal_rate','maximum_normal_rate',
            'retired_agent_override_ignored','invalid_retired_agent_ignored',
            'cleared_retired_agent_ignored','nonscalar_retired_agent_ignored',
            'retired_agent_cannot_rescue_clear_normal','clear_normal_no_fallback',
            *('invalid_explicit_normal_'+str(i) for i in range(8)),
            'archived_rc12_override_RED_current_shared_GREEN',
            *('rc12_agent_quote_preserved_'+network for network in ['BEP20','TRC20','ERC20']),
            *('shared_quote_'+network+'_'+str(user)
              for network in ['BEP20','TRC20','ERC20'] for user in [1001,9002]),
            'normalquote_pinned_after_settings_clear','agentquote_pinned_after_settings_clear',
            'agentfallback_pinned_after_settings_clear',
            'invalidagentclaim_no_attempt_no_money','missingnormalclaim_no_attempt_no_money',
            'rc12_hosted_agent_quote_preserved_no_new_request',
            *(name+'_audience_quote_and_privacy' for name in [
                'hostnormal','hostagent','hostfallback','hostmissing','hostbadagent',
                'hostagentmissingnormal']),
            'no_wallet_or_settlement_effect','private_config_unread_and_unchanged',
        }
        if not (currency.get("pass") is True and len(required_currency)==42
                and len(currency.get("cases",[]))==len(required_currency)
                and set(currency["cases"])==required_currency):
            raise RuntimeError("Shared managed exchange-rate proof incomplete")
        display_path=Path(tmp)/"server-display-settings.json"
        subprocess.run(["python3",str(ROOT/"tools/test_server_display_settings.py"),
                        "--run-disposable","--report",str(display_path)],
                       cwd=ROOT,check=True,timeout=180,capture_output=True)
        server_display=json.loads(display_path.read_text())
        if not (server_display.get("pass") is True and server_display.get("checks")==673
                and server_display.get("database")=="owned socket only"
                and server_display.get("network_calls")==0
                and server_display.get("simple_editor")=={'providers':['marzban','pasarguard'],'checks':154}
                and server_display.get("native_context_editor")=={
                    'providers':['marzban','pasarguard'],'checks':377}
                and server_display.get("summary_baseline_source")=="748994636f9fc2cf4c0ea4c7c810093c0dd108ef"):
            raise RuntimeError("Per-server managed display and admin authorization proof incomplete")
        subject_path=Path(tmp)/"payment-subjects.json"
        subprocess.run(["python3",str(ROOT/"tools/test_post_v9_subjects.py"),
                        "--run-disposable","--report",str(subject_path)],
                       cwd=ROOT,check=True,timeout=180,capture_output=True)
        subject=json.loads(subject_path.read_text())
        if not (subject.get("complete") and subject.get("cases")>=67
                and subject.get("schema_tables")==23
                and subject.get("financial_sql_mutations")==0
                and subject.get("payment_provider_requests")==0
                and subject.get("vpn_provider_requests")==0
                and subject.get("omitted_server_identity_verified") is True
                and subject.get("explicit_server_mismatch_rejected") is True
                and subject.get("locked_subject_resolution_readonly") is True):
            raise RuntimeError("Actual original-schema payment subject preflight proof incomplete")
        catalogue_path=Path(tmp)/"catalogue-and-qr.json"
        subprocess.run(["python3",str(ROOT/"tools/test_post_v9_catalogue_qr.py"),
                        "--run-disposable","--report",str(catalogue_path)],
                       cwd=ROOT,check=True,timeout=180,capture_output=True)
        catalogue=json.loads(catalogue_path.read_text())
        if not (catalogue.get("complete") and catalogue.get("cases")>=15
                and catalogue.get("original_schema_tables")==23
                and catalogue.get("financial_mutations")==0
                and catalogue.get("real_current_audience_helper") is True
                and catalogue.get("actual_private_update_bindings") is True
                and catalogue.get("persisted_audience_role_cases")==4
                and catalogue.get("frozen_driver_unchanged") is True
                and catalogue.get("frozen_driver_sha256")=="09f6618ec2e2e130c446997604475e08de721df9a9a709cb568e155f8858829c"):
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
            "caller_confirmed_fulfillment_has_positive_presentation",
            "payment_positive_legacy_true_unconfirmed_delivery_never_green",
            "verified_provider_payment_unconfirmed_delivery_needs_review",
            "nextpay_authoritative_rejected_is_distinct_definitive_failure",
            "nextpay_uncertain_verification_review_not_failure",
            "zarinpal_uncertain_verification_review_not_failure",
            "zarinpal_transport_uncertainty_no_false_decline",
            "nowpayments_pending_return_requires_future_confirmation",
            "nowpayments_paid_browser_without_delivery_proof_needs_review",
            "verified_committed_wallet_credit_can_present_completion",
            "verified_service_renewal_can_present_completion",
            "paid_but_unresolved_service_effect_stays_review",
            "unknown_payment_code_is_not_authoritative_terminal_failure",
            "untyped_invoice_information_not_inferred_as_paid_or_pending",
            "uncertain_reconciliation_retains_no_repayment_guidance",
            "wallet_effect_uncertain_never_presents_completion",
            "unknown_presentation_enum_fails_closed",
            "injected_heading_and_message_escaped",
            "persian_mixed_latin_and_numeric_reference_preserved",
            "valid_username_generates_only_verified_telegram_bot_link",
            "documented_and_known_unconfigured_bot_placeholders_blocked",
            "invalid_or_missing_bot_username_has_safe_no_dead_link",
            "page_get_has_no_checkout_or_side_effect_action",
            "long_mixed_direction_messages_wrap_and_preserve_content",
            "real_http_private_page_noindex_no_referrer_no_cache_no_frame",
            "responsive_accessible_states_keyboard_and_forced_contrast",
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
        required_legacy_wallet.add("f1_unknown_native_refuses_before_wallet_claim_or_effect")
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
                                     'explicit_existing_native_baseline','unknown_native_refuses_preclaim',
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
            # Live-looking admin payment policy callbacks must not trust
            # revoked cached admin roles or stale BOT_STATES snapshots.
            ('gateway_rules','test_post_v9_gateway_rules.py',
             {
                 'legacy_wallet',
                 'legacy_nowpayments',
                 'legacy_zarinpal',
                 'legacy_nextpay',
                 'legacy_bank_transfer',
                 'legacy_gift_card',
                 'legacy_manual_usdt',
                 'legacy_manual_trx',
                 'legacy_category_agents',
                 'legacy_category_non_agents',
                 'legacy_category_buyers',
                 'legacy_category_non_buyers',
                 'legacy_category_has_active_order',
                 'legacy_category_no_active_order',
                 'legacy_category_off',
                 'legacy_category_all',
                 'b1_read_committed_zero_success_unrestricted_buyer_not_nonbuyer_dated_denied',
                 'b1_read_committed_future_success_unrestricted_buyer_not_nonbuyer_dated_denied',
                 'b1_repeatable_read_zero_success_unrestricted_buyer_not_nonbuyer_dated_denied',
                 'b1_repeatable_read_future_success_unrestricted_buyer_not_nonbuyer_dated_denied',
                 'or_and_categories_READ-COMMITTED',
                 'or_and_categories_REPEATABLE-READ',
                 'new_category_all',
                 'new_category_agents',
                 'new_category_non_agents',
                 'new_category_buyers',
                 'new_category_non_buyers',
                 'new_category_has_active_order',
                 'new_category_no_active_order',
                 'new_active_order_positive_and_inverse',
                 'unlimited_local_order_category_unlimited_preview_and_locked_final',
                 'unlimited_local_order_category_disabled_unlimited_preview_and_locked_final',
                 'unlimited_local_order_category_expired_preview_and_locked_final',
                 'unlimited_local_order_category_negative_preview_and_locked_final',
                 'metric_dynamic_buy',
                 'metric_dynamic_renew',
                 'metric_dynamic_volume',
                 'metric_dynamic_time',
                 'metric_dynamic_topup',
                 'registration_age_rolling_days',
                 'empty_rules_deny',
                 'off_gateway_deny',
                 'rule_independent_method_nowpayments',
                 'rule_independent_method_zarinpal',
                 'rule_independent_method_nextpay',
                 'rule_independent_method_bank_transfer',
                 'rule_independent_method_gift_card',
                 'rule_independent_method_manual_usdt',
                 'rule_independent_method_manual_trx',
                 'rule_independent_method_wallet',
                 'nowpayments_subject_split',
                 'explicit_invalid_document_denies_0',
                 'explicit_invalid_document_denies_1',
                 'explicit_invalid_document_denies_2',
                 'explicit_invalid_document_denies_3',
                 'explicit_invalid_document_denies_4',
                 'negative_days_denied',
                 'bad_registration_garbled',
                 'bad_registration_future',
                 'bad_registration_negative',
                 'bad_registration_zero',
                 'duplicate_customer_rejected',
                 'inclusive_exact_cutoff_plus_minus_one_second',
                 'future_timestamp_not_recent_success',
                 'rolling_window_9_10_110_111_date_only_boundaries',
                 'all_history_count_vs_disabled_zero_zero',
                 'method_omission_retains_legacy_explicit_off',
                 'intentional_explicit_new_rule_overrides_legacy_off',
                 'nowpayments_topup_flag_independent',
                 'operator_all_methods_longest_category_callbacks_under_64_bytes',
                 'operator_preview_does_not_persist',
                 'operator_explicit_add',
                 'operator_private_integer_confirm_commit',
                 'operator_invalid_and_cancel',
                 'two_admins_independent_fields',
                 'unauthenticated_group_sender_rejected',
                 'stale_or_revoked_admin_rejected',
                 'operator_arbitrary_17_and_overflow_rejected',
                 'operator_64_bit_integer_not_32_bit_preset',
                 'two_admin_same_field_stale_editor_cas_rejected',
                 'two_admin_distinct_field_interleaving_both_persist',
                 'b2_old_clear_new_count_higher_update_id_denied',
                 'b2_old_clear_new_days_higher_update_id_denied',
                 'b2_old_toggle_new_toggle_higher_update_id_denied',
                 'b2_old_direction_new_direction_higher_update_id_denied',
                 'b2_old_numeric_edit_after_new_commit_does_not_open_wizard',
                 'b2_legacy_unversioned_clear_callback_denied',
                 'operator_delete_requires_confirmation_cancel_safe',
                 'delete_fingerprint_conflict_with_other_admin',
                 'operator_confirmed_delete_single_use',
                 'no_financial_side_effects',
             },('source_real','owned_original_mariadb','php_runtime')),
            ('gateway_rules_financial_routes',
             'test_post_v9_gateway_rules_financial_routes.py',
             {
                 'r1_wallet_repeatable_read_revoked_no_debit',
                 'r1_hosted_repeatable_read_revoked_no_attempt',
                 'r1_wallet_read_committed_revoked_no_debit',
                 'r1_hosted_read_committed_revoked_no_attempt',
                 'r1_wallet_repeatable_read_allowed_one_debit_no_replay',
                 'r1_hosted_repeatable_read_eligible_one_invoice_no_replay',
                 'r1_wallet_read_committed_allowed_one_debit_no_replay',
                 'r1_hosted_read_committed_eligible_one_invoice_no_replay',
             },('source_real_wallet_and_hosted_routes',
                'two_independent_mysql_clients','original_23_table_fixture',
                'synthetic_provider_issuer_only')),
            ('gateway_admin_source','test_post_v9_gateway_admin_source.py',
             {
                 'red_revoked_delegate_changes_gateway_enabled',
                 'green_revoked_delegate_cannot_change_gateway_enabled',
                 'red_stale_admin_snapshot_loses_toggle_intent',
                 'green_current_persisted_flag_toggle',
                 'red_revoked_delegate_cycles_cohort',
                 'green_revoked_delegate_cannot_cycle_cohort',
                 'global_update_watermark_rejects_late_replay_across_admins',
                 'global_update_watermark_rejects_older_cohort_mutation',
                 'signed_private_callback_denies_group',
                 'signed_private_callback_denies_token',
                 'signed_private_callback_denies_message',
                 'primary_admin_can_cycle_with_source_record',
                 'ambiguous_delegate_identity_fail_closed',
                 'duplicate_source_marker_fails_closed',
                 'setting_sql_failure_rolls_back_source_and_state',
                 'after_failed_transaction_fresh_retry_can_commit',
                 'unsupported_retired_gateway_never_enabled',
                 'unsupported_cohort_name_never_persisted',
             },('baseline_red','new_source_green','actual_bot_callback_extraction',
                'actual_config_writer_extraction','real_original_schema_mariadb')),
            ('payment_key_admin','test_post_v9_payment_key_admin.py',
             {
                 'baseline_raw_merchant_secret_menu_red_current_masked_green',
                 'baseline_revoked_cached_delegate_writer_red',
                 'revoked_cached_delegate_cannot_open_payment_menu',
                 'current_admin_private_menu_safe_and_editable',
                 'revoked_delegate_cannot_open_merchant_editor',
                 'signed_private_text_saves_field_without_credential_echo',
                 'replayed_credential_message_has_no_write',
                 'revocation_during_wizard_prevents_write',
                 'forged_private_message_token_group_sender_text_denied',
                 'stale_message_source_marker_denied',
                 'signed_cancel_preserves_existing_values',
                 'two_admins_independent_fields_both_persist',
                 'two_admins_same_field_stale_baseline_denied',
                 'same_field_aba_reversion_does_not_reopen_stale_editor',
                 'public_bank_fields_editable_hmac_baseline_nonreversible',
                 'malformed_and_retired_payment_keys_cannot_be_edited',
                 'unverified_admin_key_callback_never_opens_step',
                 'legacy_unbound_payment_key_step_denied',
                 'ambiguous_payment_key_setting_type_denied',
                 'malformed_payment_key_document_denied',
                 'duplicate_administrator_userid_denied',
                 'sql_write_failure_rollback_no_false_success',
                 'retriable_authentic_save_after_sql_rollback',
                 'gift_card_legacy_https_validation',
                 'manual_trx_rate_positive_and_clear',
                 'manual_usdt_rate_positive_range',
                 'shared_usdt_rate_upper_boundary_and_clear',
                 'retired_agent_rate_menu_and_callback_removed',
                 'rc12_retired_revision_preserved_other_fields_editable',
                 'retired_pending_editor_cannot_write',
                 'chain_address_manualUsdtWalletBEP20_validated_and_clear',
                 'chain_address_manualUsdtWalletERC20_validated_and_clear',
                 'chain_address_manualUsdtWalletTRC20_validated_and_clear',
                 'chain_address_manualTrxWallet_validated_and_clear',
                 'missing_payment_keys_row_created_once_under_lock',
                 'duplicate_admin_source_marker_fails_closed',
                 'independent_writers_read_committed_preserve_fields',
                 'independent_writers_repeatable_read_preserve_fields',
                 'operator_crypto_destination_visible_merchant_secret_hidden',
                 'hosted_secret_labels_masked_public_fields_preserved',
                 'invitation_amount_managed_Toman_pair_immediately_after_wallet',
                 'invitation_save_preserves_peer_payment_keys_and_ignores_private_amount',
                 "invitation_invalid_amount_no_write_'-1'",
                 "invitation_invalid_amount_no_write_'1.5'",
                 "invitation_invalid_amount_no_write_'1e4'",
                 "invitation_invalid_amount_no_write_'1,000'",
                 "invitation_invalid_amount_no_write_'۱۰۰۰'",
                 "invitation_invalid_amount_no_write_'1000000000'",
                 "invitation_invalid_amount_no_write_'true'",
                 "invitation_invalid_amount_no_write_''",
                 'invitation_amount_boundary_999999999',
                 'invitation_amount_boundary_1',
                 'invitation_amount_boundary_0',
                 'invitation_amount_boundary_/clear',
                 'invitation_same_field_stale_editor_denied',
                 'invitation_revoked_editor_cannot_change_gift',
                 'invitation_operator_cannot_edit',
                 'invitation_support_cannot_edit',
                 'invitation_invalid_stored_amount_disables_None',
                 'invitation_invalid_stored_amount_disables_False',
                 'invitation_invalid_stored_amount_disables_True',
                 'invitation_invalid_stored_amount_disables_1.5',
                 'invitation_invalid_stored_amount_disables_{}',
                 'invitation_invalid_stored_amount_disables_[]',
                 "invitation_invalid_stored_amount_disables_'-1'",
                 "invitation_invalid_stored_amount_disables_'01'",
                 "invitation_invalid_stored_amount_disables_'1000000000'",
                 'invitation_missing_or_duplicate_document_never_uses_private_fallback',
                 'invitation_absent_document_disables_gift',
                 'invitation_editor_no_financial_effect_and_synthetic_private_assignment_retired',
                 'operational_log_excludes_merchant_values',
             },('source_real','original_schema_mariadb','baseline_red',
                'current_source_green','two_authenticated_admins',
                'no_real_provider_telegram_or_money')),
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
            if key=='gateway_rules_financial_routes' and not (
                evidence.get('real_provider_calls')==0 and
                evidence.get('customer_payment_or_deployment_effects')==0):
                raise RuntimeError('Gateway rule financial route test used real side effects')
            reviewer_f1_f4[key]=evidence

        # Issue #51 installed/payment-readiness boundary. This never touches
        # installed tenants or real providers: native PHP runs with a REAL
        # SELECT-only account against disposable original+sidecar MariaDB,
        # and verifies all 104 source members against a synthetic manifest.
        preflight_path=Path(tmp)/"payment-operator-readiness-preflight.json"
        subprocess.run(
            ["python3",str(ROOT/"tools/test_post_v9_payment_readiness.py"),
             "--run-disposable","--report",str(preflight_path)],
            cwd=ROOT,check=True,timeout=90,capture_output=True)
        readiness=json.loads(preflight_path.read_text())
        required_readiness={
            'missing_ledger_and_settings_fail_closed',
            'complete_migration_shapes_and_wallet_only_pass_no_sql_mutation',
            'malformed_explicit_rules_block',
            'unknown_legacy_audience_blocks',
            'duplicate_payment_document_blocks',
            'enabled_hosted_gateway_without_merchant_blocks',
            'json_list_not_accepted_as_settings_object',
            'usdt_only_manual_network_is_not_blocked_by_missing_trx',
            'trx_only_manual_network_is_not_blocked_by_missing_usdt',
            'shared_manual_switch_without_any_destination_blocks',
            'non_string_merchant_credential_not_treated_as_configured',
            'usdt_explicit_clear_overrides_legacy',
            'usdt_invalid_explicit_rate',
            'usdt_explicit_rate_below_minimum',
            'usdt_explicit_rate_above_maximum',
            'usdt_explicit_infinite_rate',
            'usdt_explicit_rate_valid',
            'usdt_explicit_rate_upper_boundary',
            'usdt_legacy_bep20_default',
            'usdt_valid_trc20',
            'usdt_valid_erc20',
            'usdt_missing_explicit_settings_rate',
            'usdt_cleared_explicit_settings_rate',
            'usdt_agent_only_settings_rate',
            'normal_shared_rate_audiences',
            'retired_invalid_agent_rate_ignored',
            'explicit_agent_rate_without_normal',
            'hosted_merchant_without_explicit_rate_unavailable',
            'hosted_merchant_explicit_rate_available',
            'usdt_invalid_rate_remains_blocking_when_trx_is_valid',
            'legacy_off_wallet_only',
            'empty_explicit_new_rule_denies_wallet',
            'all_disabled_rules_deny_wallet',
            'enabled_new_rule_allows_wallet',
            'new_enabled_rule_overrides_legacy_off',
            'legacy_off_when_new_document_omits_wallet',
            'denied_wallet_with_other_potentially_available_method',
            'gift_url_matches_source_checkout_validation',
            'bank_details_match_checkout_length_guard',
            'trx_rate_follows_source_nine_digit_limit',
            'untrusted_manifest_cannot_add_private_member',
            'helper_source_mismatch_with_manifest_blocks',
            'unverified_source_identity_blocks',
            'runtime_byte_drift_blocks',
            'retired_runtime_member_blocks',
            *('retired_runtime_member_blocks__'+name.replace('/','_').replace('.','_') for name in package.RETIRED_RUNTIME_MEMBERS-{'settings/tronChecker.php'}),
            'partial_003_unique_index_missing_blocks',
            'wallet_telegram_event_key_missing_blocks',
            'wallet_telegram_event_key_nonunique_blocks',
            'wallet_cursor_nontransactional_storage_blocks',
            'wallet_cursor_incompatible_storage_blocks',
            'missing_required_final_authorization_index_blocks',
            'database_connection_failure_sanitized',
            'private_sentinel_and_financial_rows_untouched',
            'optional_cleanup_and_provider_schema_strict_read_only',
        }
        required_readiness.update({
            'mismatched_helper_stops_before_database_and_execution',
            'mismatched_catalog_stops_before_database_and_execution',
            'missing_manifest_stops_before_database_and_execution',
            'mismatched_source_precedes_invalid_database_environment',
            'missing_manifest_precedes_invalid_database_environment',
        })
        required_readiness.update([
            'api014_missing_switches_off_legacy_pass',
            'api014_missing_bank_on_blocks',
            'api014_missing_gift_on_blocks',
            'api014_partial_switches_off_legacy_warn',
            'api014_partial_bank_on_blocks',
            'api014_partial_gift_on_blocks',
            'api014_complete_switches_off_detected',
            'api014_complete_bank_on_credentials_not_verified',
            'api014_complete_gift_on_credentials_not_verified',
            'api014_missing_unique_switches_off_legacy_warn',
            'api014_missing_unique_bank_on_blocks',
            'api014_missing_fulfillment_switches_off_legacy_warn',
            'api014_missing_fulfillment_gift_on_blocks',
            'api014_restored_complete_both_switches_on',
            'api014_non_on_switch_values_remain_off',
            'api_inventory_omission_blocks__src_Finance_PaymentEvidence_php',
            'api_inventory_substitution_blocks__src_Finance_PaymentEvidence_php',
            'api_inventory_omission_blocks__src_Finance_PaymentApiAdmin_php',
            'api_inventory_substitution_blocks__src_Finance_PaymentApiAdmin_php',
            'api_inventory_omission_blocks__src_Finance_ApiPayments_php',
            'api_inventory_substitution_blocks__src_Finance_ApiPayments_php',
            'api_inventory_omission_blocks__src_Finance_Routes_ApiPayments_php',
            'api_inventory_substitution_blocks__src_Finance_Routes_ApiPayments_php',
            'api_readiness_historical_financial_rows_and_private_sentinel_unchanged',
        ])
        if not (readiness.get('complete') is True and
                readiness.get('cases')==len(required_readiness) and
                set(readiness.get('details',[]))==required_readiness and
                readiness.get('readonly_sql_account_enforced') is True and
                readiness.get('original_cases_preserved')==54 and
                readiness.get('archived_source_failure_fallthrough_red') is True and
                readiness.get('source_failure_stops_before_database_and_helpers') is True and
                readiness.get('source_identity_only_on_failure') is True and
                readiness.get('source_failure_all_rows_unchanged') is True and
                readiness.get('source_usdt_production_helper_compared') is True and
                readiness.get('source_policy_production_helper_compared') is True and
                readiness.get('source_members_verified')==package.EXPECTED_RUNTIME_COUNT and
                readiness.get('all_eight_additive_shapes_exercised') is True and
                readiness.get('api_schema_capability_compared') is True and
                readiness.get('api_credentials_not_decrypted') is True and
                readiness.get('api_schema_014_disposable_only') is True and
                type(readiness.get('api_readiness_cases')) is int and readiness['api_readiness_cases']==24 and
                readiness.get('no_real_provider_or_telegram_calls') is True and
                readiness.get('installed_operator_acceptance') is False):
            raise RuntimeError('Payment readiness read-only native preflight incomplete')

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
            "f2_distinct_active_references_same_account_two_workers_one_debit",
            "f2_explicit_same_panel_references_share_one_renewal_cycle",
            "f2_shared_alias_stale_workers_no_second_debit",
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
            "remote_absolute_expiry_persisted_not_preclaimed_estimate",
            "remote_expiry_outside_signed_int_review_no_double_debit",
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
        finance_run=subprocess.run(
            ["python3",str(ROOT/"tools/test_post_v9_finance_routes.py"),
             "--run-disposable","--report",str(finance_path)],
            cwd=ROOT,timeout=180,capture_output=True,text=True)
        if finance_run.returncode!=0:
            raise RuntimeError("Financial native source regression failed:\n"+
                (finance_run.stderr or finance_run.stdout)[-5000:])
        finance=json.loads(finance_path.read_text())
        # Current R10 financial contract: preserve authenticated settlement,
        # durable compensation proof and concurrency-safe operator recovery.
        mandatory_finance = {
            # Reviewer R2: required real Controller->Renderer dispositions,
            # not text-classifier assertions or isolated page-only mocks.
            "r2_nowpayments_existing_attempt_pending",
            "r2_nowpayments_quarantined_attempt_review",
            "r2_nowpayments_historical_quote_review",
            "r2_zarinpal_scoped_amount_mismatch_review",
            "r2_zarinpal_ambiguous_historical_authority_review",
            "r2_nextpay_scoped_amount_mismatch_review",
            "r2_nextpay_external_reference_collision_review",
            "r2_nowpayments_issuance_already_creating_pending",
            "r2_nowpayments_seen_child_under_review",
            "r2_nowpayments_paid_but_delivery_unproven_review",
            "r2_nowpayments_uncertain_invoice_creation_review",
            "r2_nextpay_uncertain_invoice_creation_review",
            "r2_zarinpal_uncertain_invoice_creation_review",
            "r2_missing_initiation_provider_neutral",
            "r2_missing_return_provider_neutral",
            "r2_unknown_zarinpal_order_neutral",
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
            "nowpayments_finished_insufficient_capacity_missing_user_review_no_refund",
            "nowpayments_finished_insufficient_capacity_verified_user_review_no_refund",
            "hosted_nextpay_two_accounts_one_slot_no_create_review_no_refund",
            "hosted_zarinpal_two_accounts_one_slot_no_create_review_no_refund",
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
        mandatory_finance.update({
            'r2_exact_hosted_nextpay_100_3_no_replay',
            'r2_exact_hosted_zarinpal_101_2_no_replay',
        })
        mandatory_finance.add('hosted_order_and_effect_link_atomic_rollback')
        # R7 real hosted claim, Marzban adapter, order CAS and local SQL faults.
        mandatory_finance.update({
            'r7_hosted_day_verified_atomic_remote_absolute_no_replay',
            'r7_hosted_day_sql_throwing_atomic_remote_absolute_no_replay',
            'r7_hosted_day_sql_permissive_atomic_remote_absolute_no_replay',
            'r7_hosted_day_concurrent_order_drift_atomic_remote_absolute_no_replay',
        })
        mandatory_finance.add("fresh_addon_unknown_native_refused_before_claim_or_effect")
        cases_finance = {case.get("case") for case in finance.get("scenarios",[])
                         if case.get("pass_")}
        if not (finance.get("complete") and finance.get("cases")>=153 and
                mandatory_finance.issubset(cases_finance) and
                finance.get("schema_tables")==23 and
                finance.get("current_schema_tables")==26 and
                finance.get("shared_payment_attempt_ledger_tested") and
                finance.get("fresh_addon_existing_native_source") is True and
                finance.get("unknown_native_addon_admission_refused") is True and
                finance.get("original_mariadb_socket_only") and
                finance.get("synthetic_payment_spy") and
                finance.get("real_provider_requests")==0):
            raise RuntimeError("Original-schema finance and NOWPayments finality assurance incomplete")
        # #26 distinct account identity: verified by source-native PHP and
        # independent socket-only MariaDB, not by changes in account totals.
        effect_path=Path(tmp)/"account-service-effects.json"
        subprocess.run(
            ["python3",str(ROOT/"tools/test_post_v9_service_effects.py"),
             "--run-disposable","--report",str(effect_path)],
            cwd=ROOT,check=True,timeout=125,capture_output=True)
        effects=json.loads(effect_path.read_text())
        required_effects={
            "review_O1_missing_uq_effect_order_no_remote_post",
            "review_O1_missing_uq_effect_operation_no_remote_post",
            "review_R2_type_drift_blocks_both_manual_multi_link_and_hosted_link",
            "review_R2_group_drift_blocks_both_manual_multi_link_and_hosted_link",
            "review_R2_endpoint_drift_blocks_both_manual_multi_link_and_hosted_link",
            "review_R1_plan_reassignment_original_paid_target_no_remote_effect",
            "r1_terminal_legacy_paid_states_cannot_authorize_new_post",
            "missing_007_no_remote_post",
            "malformed_007_unique_index_no_remote_post",
            "one_reserved_unique_operation_verified_by_exact_note_username_quota_expiry",
            "same_pay_line_replay_stopped_before_post",
            "two_concurrent_requests_one_remote_post",
            "unknown_post_lost_frozen_no_replay",
            "unknown_get_missing_frozen_no_replay",
            "unknown_get_unavailable_frozen_no_replay",
            "unknown_wrong_expiry_fraction_frozen_no_replay",
            "unknown_wrong_quota_fraction_frozen_no_replay",
            "unknown_wrong_expiry_float_integral_frozen_no_replay",
            "unknown_wrong_quota_float_integral_frozen_no_replay",
            "unknown_wrong_expiry_exponent_frozen_no_replay",
            "unknown_wrong_quota_padded_frozen_no_replay",
            "unknown_wrong_expiry_overflow_frozen_no_replay",
            "canonical_unsigned_decimal_string_limits_verified_once",
            "unknown_other_note_frozen_no_replay",
            "unknown_target_change_frozen_no_replay",
            "unknown_endpoint_change_frozen_no_replay",
            "cross_payment_same_target_username_rejected_before_post",
            "explicit_physical_group_alias_username_collision_before_post",
            "same_total_after_unrelated_delete_create_never_used_as_success_signal",
            "two_account_partial_remote_no_bulk_replay",
            "r1_old_007_missing_capacity_owner_schema_blocks_post",
            "r1_hosted_two_requested_one_slot_zero_create",
            "r1_hosted_negative_quantity_rejected_before_post",
            "r1_hosted_two_exact_slots_held_before_post_once",
            "r1_independent_post_reader_excludes_uncommitted_capacity_and_hold",
            "r1_hosted_distinct_payments_one_slot_single_create",
            "r1_hosted_vs_real_manual_capacity_helper_no_overbooking",
            "r1_two_account_partial_unknown_keeps_full_capacity_hold",
            "r1_reservation_insert_sql_failure_rolls_back_all_slots",
            "exact_local_order_fk_join_and_no_second_finalize",
            "historical_effect_retained_after_local_subscription_deletion",
        }
        required_effects.update({
            "paid_link_outside_caller_transaction_refused_without_write",
            "nonpaid_link_outside_caller_transaction_refused_without_write",
        })
        if not (effects.get("complete") and effects.get("cases")>=61
                and required_effects.issubset(effects.get("scenarios",[]))
                and effects.get("source_real_php") is True
                and effects.get("owned_mariadb_only") is True
                and effects.get("real_provider_requests")==0
                and effects.get("real_financial_effects")==0
                and effects.get("external_account_count_not_used") is True
                and effects.get("installed_operator_tested") is False):
            raise RuntimeError("Per-account service operation ledger contract incomplete")
        locking_path=Path(tmp)/"create-verification-locking.json"
        try:
            subprocess.run(["python3",str(ROOT/"tools/test_post_v9_create_verification_locking.py"),
                            "--run-disposable","--report",str(locking_path)],
                           cwd=ROOT,check=True,timeout=180,capture_output=True)
        except (subprocess.CalledProcessError,subprocess.TimeoutExpired) as error:
            # Owned synthetic fixtures only: retain the actionable child cause
            # instead of hiding it behind a bare CalledProcessError in CI.
            detail=(error.stderr or error.stdout or b'').decode(errors='replace')[-6000:]
            raise RuntimeError("Native CREATE locking regression failed:\n"+detail) from error
        verification_locking=json.loads(locking_path.read_text())
        required_locking={
            "prior_011c_lock_inversion_expected_RED",
            "corrected_config_first_native_purchase_GREEN",
            "nonpaid_prior_7dca_expected_RED", "nonpaid_canonical_target_locks_GREEN",
            "linker_prior_7dca_expected_RED", "linker_canonical_target_locks_GREEN",
            "active_caller_transaction_refused_without_implicit_commit_or_rollback",
        }
        required_locking.update(fault+"_held_no_refund_or_repeat_create" for fault in (
            "password_drift","username_drift","endpoint_drift","group_drift",
            "operation_drift","journal_endpoint_drift","verified_write_failure",
            "commit_false","commit_ack_lost"))
        locking_cases=verification_locking.get("cases_detail",[])
        if not (verification_locking.get("complete") is True
                and verification_locking.get("cases")==len(required_locking)
                and {r.get("case") for r in locking_cases}==required_locking
                and all(r.get("pass") is True for r in locking_cases)
                and verification_locking.get("native_php_mysqli") is True
                and verification_locking.get("native_pk_lock_witness") is True
                and verification_locking.get("atomic_ready_marker_publication") is True
                and verification_locking.get("owned_socket_only") is True
                and verification_locking.get("isolation_changed") is False
                and verification_locking.get("live_provider_requests")==0
                and verification_locking.get("financial_http_retries")==0):
            raise RuntimeError("Deterministic CREATE claim/link lock-order and unknown-commit proof incomplete")
        # Full PR #66 high-assurance R2: actual global PHP facades, original
        # Core/007 dispatcher and pinned cURL transport on two local panels.
        # This proves route+credential checks survive the real facade boundary.
        pin_path=Path(tmp)/"effect-physical-target-pin.json"
        pin_run=subprocess.run(
            ["python3",str(ROOT/"tools/test_post_v9_effect_transport_pin.py"),
             "--run-disposable","--report",str(pin_path)],
            cwd=ROOT,check=True,timeout=180,capture_output=True)
        pinned_effects=json.loads(pin_path.read_text())
        required_pin={
            "strict_effect_normal_auth_post_get_exact_same_panel_verified",
            "journal_reservation_endpoint_changed_no_outbound_effect",
            "journal_reservation_credentials_changed_no_outbound_effect",
            "switch_at_login_no_wrong_panel_or_false_verified",
            "switch_credentials_at_login_no_wrong_panel_or_false_verified",
            "redirect_login_no_wrong_panel_or_false_verified",
            "redirect_create_no_wrong_panel_or_false_verified",
            "switch_after_post_no_wrong_panel_or_false_verified",
            "switch_credentials_after_post_no_wrong_panel_or_false_verified",
            "switch_at_get_no_wrong_panel_or_false_verified",
            "switch_credentials_at_get_no_wrong_panel_or_false_verified",
            "lost_create_no_wrong_panel_or_false_verified",
            "same_group_different_endpoint_auth_post_get_remains_pinned",
        }
        if not (pinned_effects.get("complete") is True and
                set(pinned_effects.get("scenarios",[]))==required_pin and
                pinned_effects.get("cases")==len(required_pin) and
                pinned_effects.get("actual_007_journal") is True and
                pinned_effects.get("actual_php_curl") is True and
                pinned_effects.get("physical_synthetic_panels")==2 and
                pinned_effects.get("owned_mariadb_only") is True and
                pinned_effects.get("external_network_requests")==0 and
                pinned_effects.get("installed_changes")==0):
            raise RuntimeError("Pinned physical CREATE source/HTTP assurance incomplete")
        # High-assurance R1-A2: source PHP -> Core -> Accounts, real cURL
        # over two isolated loopback Marzban account stores.
        physical_gift_path=Path(tmp)/"gift-physical-target.json"
        subprocess.run(
            ["python3",str(ROOT/"tools/test_post_v9_gift_physical.py"),
             "--run-disposable","--report",str(physical_gift_path)],
            cwd=ROOT,check=True,timeout=180,capture_output=True)
        physical_gift=json.loads(physical_gift_path.read_text())
        required_gift_physical={
            "original_panel_real_php_two_members_one_edit_each",
            "same_username_on_two_panels_continuation_never_targets_b",
            "original_panel_restored_held_effect_never_replayed",
            "credential_identity_change_before_list_denied",
            "panel_swap_after_login_no_wrong_panel_edit_hold",
            "panel_swap_after_list_no_wrong_panel_edit_hold",
            "panel_swap_after_user_get_no_wrong_panel_edit_hold",
            "panel_swap_after_put_no_wrong_panel_edit_hold",
            "credentials_changed_after_authenticated_user_get_no_put",
            "ambiguous_original_panel_put_and_get_failure_no_replay",
            "same_logical_group_does_not_authorize_physical_change",
            "credential_drift_during_put_holds_original_effect",
            "real_http_list_missing_total_no_snapshot_or_edit",
            "real_http_list_wrong_total_no_snapshot_or_edit",
            "real_http_list_redirect_to_other_panel_no_snapshot_or_edit",
        }
        if not (
            physical_gift.get("complete") is True and
            physical_gift.get("cases")==len(required_gift_physical) and
            set(physical_gift.get("scenarios",[]))==required_gift_physical and
            physical_gift.get("actual_php_list_edit_http") is True and
            physical_gift.get("separate_loopback_panels")==2 and
            physical_gift.get("owned_mariadb_only") is True and
            physical_gift.get("real_provider_requests")==0
        ):
            raise RuntimeError("Bulk gift physical endpoint assurance incomplete")

        # Fresh F1/F2: REAL bot.php final Telegram text-step, unmodified
        # UpdateBindings and GiftBatch on disposable original MariaDB.
        # Neither duplicate update IDs nor distinct concurrent messages
        # may consume an original administrator wizard step twice.
        gift_source_path=Path(tmp)/"gift-source-idempotency.json"
        subprocess.run(
            ["python3",str(ROOT/"tools/test_post_v9_gift_enqueue_source.py"),
             "--run-disposable","--report",str(gift_source_path)],
            cwd=ROOT,check=True,timeout=180,capture_output=True)
        gift_source=json.loads(gift_source_path.read_text())
        required_gift_source={
            "f1_first_real_bot_message_one_job_claim_and_receipt",
            "f1_replayed_identical_update_one_existing_batch",
            "f1_two_actual_php_mysql_sessions_concurrent_one_source",
            "f1_crash_after_commit_before_telegram_notice_no_replay",
            "f2_postcommit_crash_preserves_job_claim_receipt_consumed_step",
            "f1_source_claim_sql_failure_rolls_back_queue_and_receipt",
            "f1_receipt_sql_failure_rolls_back_new_queue",
            "f1_uncertain_commit_ack_recovered_by_source_claim",
            "f2_uncertain_commit_preserves_step_claim_consistency",
            "f1_source_claim_survives_completed_cron_and_blocks_reenqueue",
            "f2_completed_cron_late_duplicate_preserves_rearmed_step",
            "f1_distinct_authorized_messages_create_distinct_batches",
            "f1_different_actor_same_global_update_id_conflicts",
            "f1_two_concurrent_actors_same_update_id_one_global_claim",
            "f1_postcommit_ui_does_not_erase_newer_admin_step",
            "f1_corrupted_existing_source_claim_fails_closed",
            "f1_invalid_telegram_envelope_bound_actor",
            "f1_invalid_telegram_envelope_bound_chat",
            "f1_invalid_telegram_envelope_bound_message",
            "f1_invalid_telegram_envelope_bound_text",
            "f1_invalid_telegram_envelope_callback",
            "f1_invalid_telegram_envelope_mixed",
            "f1_invalid_telegram_envelope_edited",
            "f1_invalid_telegram_envelope_channel_post",
            "f1_invalid_telegram_envelope_missing_update",
            "f1_invalid_telegram_envelope_string_update",
            "f1_invalid_telegram_envelope_negative_update",
            "f1_invalid_telegram_envelope_group_chat",
            "f1_invalid_telegram_envelope_other_chat",
            "f1_invalid_telegram_envelope_bot_sender",
            "f1_invalid_telegram_envelope_zero_message_id",
            "f1_nonadmin_source_denied",
            "f1_delegated_admin_fresh_private_update_allowed",
            "f1_fresh_actor_role_rechecked_under_lock",
            "f1_fresh_actor_step_rechecked_under_lock",
            "f1_duplicate_actor_userid_fails_closed",
            "f1_duplicate_setting_claim_fails_closed",
            "f1_telegram_notice_failure_preserves_committed_job",
            "f1_same_update_id_changed_message_denied",
            "f2_parallel_distinct_updates_one_active_step_one_batch",
            "f2_second_distinct_update_after_first_commit_before_ui_denied",
            "f2_old_update_replay_does_not_consume_new_same_literal_step",
            "f2_new_update_after_late_replay_consumes_new_step_once",
            "f2_failed_step_sql_update_rolls_back_all_while_step_remains",
            "f2_zero_row_step_transition_rolls_back_all_records",
            "f2_delegated_admin_consumes_only_own_step",
            "f2_parallel_distinct_actors_consumed_only_respective_steps",
            "gift_source_completed_worker_private_owner_manager_once_excludes_operator_support",
            "gift_source_uncertain_worker_private_manager_hold_preserves_source_and_no_replay",
        }
        if not (gift_source.get("complete") is True and
                gift_source.get("cases")==len(required_gift_source) and
                set(gift_source.get("scenarios",[]))==required_gift_source and
                gift_source.get("real_bot_php_route") is True and
                gift_source.get("real_telegram_update_bindings") is True and
                gift_source.get("real_gift_batch_helper") is True and
                gift_source.get("owned_original_schema_mariadb") is True and
                gift_source.get("parallel_php_sessions") is True and
                gift_source.get("atomic_admin_step_and_source_commit") is True and
                gift_source.get("actual_notification_router") is True and
                gift_source.get("private_owner_manager_gift_notices") is True and
                gift_source.get("operator_support_gift_excluded") is True and
                gift_source.get("production_mutations")==0 and
                gift_source.get("real_provider_requests")==0):
            raise RuntimeError("Gift Telegram source idempotency contract incomplete")
        # Issue #78: real single-admin gift bot.php source, fresh Telegram
        # bindings, strict verified service-edit seam and disposable MariaDB.
        direct_gift_path=Path(tmp)/"direct-gift-source.json"
        direct_gift_run=subprocess.run(
            ["python3",str(ROOT/"tools/test_post_v9_direct_gift.py"),
             "--run-disposable","--report",str(direct_gift_path)],
            cwd=ROOT,timeout=240,capture_output=True,text=True)
        if direct_gift_run.returncode!=0:
            # Do not swallow the disposable child exception: without it a
            # PHP-version-specific failure is opaque in official CI logs.
            # Fixture transport uses synthetic secrets; redact them anyway.
            diagnostic=(direct_gift_run.stderr or direct_gift_run.stdout)[-2400:]
            diagnostic=diagnostic.replace('fixture-password','[fixture-redacted]')
            diagnostic=diagnostic.replace('fixture-direct-gift-token-NotReal_2026',
                                          '[fixture-redacted]')
            raise RuntimeError('Issue #78 direct gift child failed (source-real fixture): '+
                               diagnostic)
        direct_gift=json.loads(direct_gift_path.read_text())
        required_direct_gift={
            'real_bot_route_verified_combined_gift_success',
            'real_bot_route_only_days','real_bot_route_only_volume',
            'unknown_claim_commit_holds_no_provider_dispatch',
            'postcommit_notice_failure_no_remote_replay',
            'provider_or_local_failure_hold_provider_false',
            'provider_or_local_failure_hold_provider_unknown',
            'provider_or_local_failure_hold_provider_ambiguous_success',
            'provider_or_local_failure_hold_sql_drift',
            'provider_or_local_failure_hold_config_drift',
            'delegated_admin_revoked_before_step',
            'fresh_delegated_admin_private_gift',
            'duplicate_remark_order_rejected',
            'changed_order_identity_before_claim_denied',
            'callback_text_never_used_as_gift_input',
            'concurrent_workers_one_unique_durable_claim',
            'old_callback_cannot_override_new_random_nonce',
            'old_callback_cannot_interrupt_volume_stage',
            'new_callback_restarts_distinct_nonce',
            'physical_account_alias_cross_order_cross_admin_held',
            'source_and_account_hold_rollback_when_step_consume_fails',
            'journal_sql_insert_failure_prevents_provider_dispatch',
            'original_23_table_schema_unmodified',
            # Required independent-review F1/F2 race/cancel controls.
            'repeatable_read_two_admins_two_orders_lockwait_before_claim_commit',
            'read_committed_two_admins_alias_lockwait_safe',
            'shared_panel_group_two_urls_cross_admin_hold',
            'fresh_cancel_remark','fresh_cancel_day','fresh_cancel_volume',
            'old_cancel_cannot_erase_new_start_nonce',
            'old_cancel_preserves_day','old_cancel_preserves_volume',
            'cancel_checks_last_message_as_well_as_update',
            'cancel_lost_commit_ack_then_redelivery_fails_closed',
            'revoked_delegated_admin_cannot_cancel',
            'concurrent_cancel_and_progress_generation_cas',
            'verified_prior_source_envelope_duplicate_no_effect',
            'corrupt_source_provenance_fail_closed_$.actor_id',
            'corrupt_source_provenance_fail_closed_$.source.actor_id',
            'completed_marker_reuse_only_after_distinct_new_wizard',
            # F2-R1: real final bot.php global CANCEL + config.php setUser
            # with a cached userInfo snapshot before a newer gift starts.
            'global_cancel_stale_cached_legacy_preserves_remark',
            'global_cancel_stale_cached_legacy_preserves_day',
            'global_cancel_stale_cached_legacy_preserves_volume',
            'global_cancel_fresh_legacy_flow_unchanged',
            'global_cancel_preserves_unrelated_inactive_plan_without_plan_writes',
            'global_cancel_stale_revoked_delegate_does_not_clobber',
            'global_cancel_stale_legacy_setuser_cannot_clobber',
            'global_cancel_unknown_ack_replay_preserves_new_gift',
            # B1/F2-R2: ingress, authorized moderation and stale legacy
            # SQL step writes on the exact original-schema PHP runtime.
            'moderation_rejects_absent_header',
            'moderation_rejects_wrong_header',
            'moderation_rejects_wrong_method',
            'moderation_rejects_group_chat',
            'moderation_rejects_mixed_update',
            'moderation_rejects_bot_sender',
            'moderation_rejects_malformed_callback',
            'moderation_rejects_forged_actor_binding',
            'moderation_current_admin_required',
            'moderation_revoked_delegate_denied',
            'moderation_fresh_admin_agent_idempotence',
            'moderation_fresh_admin_ban_succeeds',
            'moderation_old_callback_cannot_erase_gift_nonce',
            'moderation_fresh_authorized_ban_preempts_gift',
            'moderation_nonunique_target_identity_rejected',
            'moderation_uncertain_commit_never_replays_toggle',
            'manual_accounts_fresh_current_authorized_succeeds',
            'manual_accounts_stale_cached_step_temp_preserves_new_gift',
            'manual_accounts_current_delegate_role_required',
            'manual_accounts_unknown_commit_replay_preserves_gift',
            'manual_accounts_rejects_callback_old_bot_text',
            'manual_accounts_rejects_mixed_update',
            'manual_accounts_rejects_missing_ingress_secret',
            'legacy_text_ban_cannot_erase_active_gift',
            'legacy_receipt_ban_cannot_erase_active_gift',
            'broadcast_bot_blocked_preserves_active_gift',
            'broadcast_bot_blocked_ordinary_behavior_preserved',
            # B1-R1/B1-R2: signed exemption + locked policy/step, stale
            # mainMenu versus newer ban, explicit unban and nonce guards.
            'exemption_authorized_current_start_and_atomic_completion',
            'exemption_durable_source_replay_denied',
            'exemption_ordinary_customer_start_denied',
            'exemption_revoked_delegate_start_denied',
            'exemption_revoked_delegate_completion_denied',
            'exemption_start_rejects_unsigned',
            'exemption_start_rejects_wrong_secret',
            'exemption_start_rejects_group_callback',
            'exemption_start_rejects_mixed_callback',
            'exemption_start_rejects_bot_sender',
            'exemption_start_rejects_wrong_actor',
            'exemption_finish_rejects_callback_bot_text',
            'exemption_finish_rejects_mixed_message',
            'exemption_finish_rejects_stale_message_id',
            'exemption_finish_rejects_stale_update_id',
            'exemption_finish_rejects_wrong_chat',
            'exemption_stale_cached_worker_preserves_new_gift_and_policy',
            'exemption_unknown_commit_no_success_or_replay',
            'exemption_nonunique_setting_fail_closed',
            'legacy_main_menu_cannot_erase_newer_authorized_ban',
            'legacy_stale_cancel_cannot_erase_newer_ban',
            'legacy_explicit_authorized_unban_still_operates',
            'legacy_nonbanned_main_menu_works',
            'exemption_old_main_menu_cannot_erase_current_nonce',
            'exemption_old_moderation_ban_cannot_erase_nonce',
            'exemption_legacy_text_ban_cannot_erase_nonce',
            'exemption_newer_authorized_moderation_ban_preempts_wizard',
            'exemption_policy_and_step_atomic_rollback_on_sql_failure',
            'exemption_existing_member_preserved_and_wizard_completed',
            'exemption_new_update_from_old_bot_menu_still_works',
            'moderation_new_update_from_old_bot_menu_still_works',
            # R3: current banned CANCEL, cross-actor source ordering and
            # revoked/stale legacy Ban/Unban/receipt transitions.
            'r3_cancel_preserves_current_ban_primary',
            'r3_cancel_preserves_current_ban_delegated',
            'r3_cached_banned_cancel_does_not_cleanup',
            'r3_legacy_unban_rejected_after_delegated_revocation',
            'r3_legacy_unban_rejected_after_newer_other_admin_ban',
            'r3_fresh_source_verified_legacy_unban_still_works',
            'r3_legacy_unban_replay_denied',
            'r3_legacy_start_rejects_missing_secret',
            'r3_legacy_start_rejects_group',
            'r3_legacy_start_rejects_mixed',
            'r3_legacy_start_rejects_bot',
            'r3_legacy_start_rejects_forged_actor',
            'r3_legacy_start_rejects_revoked_delegate',
            'r3_legacy_ban_revoked_worker_denied',
            'r3_legacy_receipt_ban_revoked_worker_denied',
            'r3_receipt_ban_fresh_authenticated_transition',
            'r3_receipt_ban_source_replay_denied',
            'r3_receipt_ban_unknown_commit_no_false_notice',
            'r3_legacy_target_nonunique_userid_denied',
            'r3_newer_verified_moderation_outorders_legacy_ban',
            # R6: native before-expiry is mandatory and must prove an increase.
            *['provider_or_local_failure_hold_'+mode for mode in [
                'provider_missing_before','provider_null_before','provider_string_before',
                'provider_negative_before','provider_float_before','provider_overflow_before',
                'provider_unchanged_before','provider_decreased_after']],
        }
        if not (direct_gift.get('complete') is True and
                direct_gift.get('cases')>=161 and
                required_direct_gift.issubset(set(direct_gift.get('scenarios',[]))) and
                direct_gift.get('real_bot_php_route') is True and
                direct_gift.get('actual_global_cancel_fallback') is True and
                direct_gift.get('global_cancel_preserves_unrelated_inactive_plan') is True and
                direct_gift.get('global_cancel_plan_mutation_trace') is True and
                direct_gift.get('actual_config_set_user') is True and
                direct_gift.get('actual_webhook_pre_config_auth') is True and
                direct_gift.get('actual_config_moderation') is True and
                direct_gift.get('actual_manual_account_handler') is True and
                direct_gift.get('actual_channel_exemption_handler') is True and
                direct_gift.get('actual_legacy_main_menu') is True and
                direct_gift.get('actual_legacy_moderation_transactions') is True and
                direct_gift.get('real_mysqli_original_schema') is True and
                direct_gift.get('shared_real_service_seam_with_synthetic_adapter') is True and
                direct_gift.get('owned_mariadb_only') is True and
                direct_gift.get('real_telegram_or_marzban_calls')==0 and
                direct_gift.get('tenant_migrations')==0 and
                direct_gift.get('production_operations')==0):
            raise RuntimeError('Issue #78 direct administrator gift safety assurance incomplete')
        # R6 native proof complements the original synthetic service seam:
        # actual bot route, actual two-provider adapters, socket/loopback only.
        native_direct_path=Path(tmp)/'direct-gift-native.json'
        subprocess.run(['python3',str(ROOT/'tools/test_post_v9_direct_gift_native.py'),
                        '--run-disposable','--report',str(native_direct_path)],
                       cwd=ROOT,check=True,timeout=240,capture_output=True)
        native_direct_gift=json.loads(native_direct_path.read_text())
        native_successes=['combined','days_only','volume_only',
            'volume_stale_local_future','volume_stale_local_past',
            'days_stale_local_future','combined_stale_local_future']
        native_negatives={
            'marzban':['credentials_rotated','endpoint_rotated','native_expiry_drift',
                       'readback_lost','next_plan','unlimited_time','unlimited_volume'],
            'pasarguard':['native_identity_drift','readback_unverified','write_lost',
                         'scheduled_reset','unlimited_time','unlimited_volume'],
        }
        required_native_direct=[provider+'_'+scenario+suffix
            for provider,negative in native_negatives.items()
            for scenario in native_successes+negative
            for suffix in (['','_replay','_new_source_hold']
                           if scenario in negative else ['','_replay'])]
        native_direct_mismatches=exact_current_proof_mismatches(native_direct_gift,
            {'complete':True,'actual_bot_route':True,'actual_native_adapters':True,
             'owned_mariadb_only':True,'loopback_only':True,
             'real_provider_requests':0,'production_operations':0},
            'scenarios',required_native_direct)
        if native_direct_mismatches:
            raise RuntimeError('Owner R6 native direct gift proof incomplete: '+
                               json.dumps(native_direct_mismatches,sort_keys=True))
        # Whole-main review R1: run actual owned gift worker on disposable
        # original 23-table MariaDB, no live Telegram/Marzban service.
        gift_path=Path(tmp)/"gift-worker-verified-state.json"
        subprocess.run(
            ["python3",str(ROOT/"tools/test_post_v9_gift_worker.py"),
             "--run-disposable","--report",str(gift_path)],
            cwd=ROOT,check=True,timeout=160,capture_output=True)
        gift=json.loads(gift_path.read_text())
        required_gift={
            "gift_verified_volume_2048_days_2",
            "gift_refused_volume_2048_days_2",
            "gift_uncertain_volume_2048_days_2",
            "gift_throw_volume_2048_days_2",
            "gift_unverified_volume_2048_days_2",
            "gift_bad_expire_volume_2048_days_2",
            "gift_verified_volume_0_days_2",
            "gift_verified_volume_2048_days_0",
            "gift_notify_fail_volume_2048_days_2",
            "verified_remote_local_sql_failure_stays_held_no_replay",
            "gift_verified_volume_1536_days_2",
            "gift_verified_volume_0_days_0",
            "inactive_recipient_never_inherits_previous_success_or_edit",
            "r1a_interrupt_after_first_commit_durable_username_cursor",
            "r1a_insert_prepend_only_original_pending_username_edited",
            "r1a_late_added_user_explicitly_excluded",
            "r1a_reordered_final_cleanup_no_replay_and_no_orphans",
            "r1a_reorder_without_insertion_exactly_once_per_username",
            "r1a_deleted_pending_member_holds_without_substitution",
            "r1a_deleted_member_return_requires_operator_reconciliation",
            "r1a_legacy_cursor_reset_detected_by_independent_roster_progress",
            "r1a_legacy_inflight_without_snapshot_fails_closed",
            "r1a_ambiguous_provider_usernames_never_edited",
            "r1a_corrupt_roster_chunk_digest_no_external_replay",
            "r1a_large_roster_bounded_chunks_preserve_all_original_members",
            "r1a_parallel_cron_workers_one_remote_increment",
            "r1a_parallel_completion_lifecycle_consistent",
            "r1a_incomplete_provider_list_never_frozen",
            "r1a_nontransactional_installed_table_fails_closed",
            "r1a_volume_only_local_identity_lost_after_provider_held",
            "r1a1_original_main_worker_exited_after_effect_zero_offset_no_replay",
            "r1a1_unmarked_untouched_zero_offset_requires_operator",
            "r1a1_new_format_enqueue_identity_allows_first_gift",
            "r1a1_deleted_provenance_cannot_rearm",
            "r1a1_provenance_sql_failure_atomically_rolls_back_enqueue",
            "r1a1_corrupt_provenance_never_authorizes_provider_edit",
            "r1a1_bot_admin_enqueue_uses_durable_provenance_path",
            "r1a2_drift_before_initial_list_no_remote_effect",
            "r1a2_restoring_panel_does_not_auto_rearm",
            "r1a2_change_between_list_and_edit_fails_closed",
            "r1a2_changed_credential_before_list_no_edit",
            "r1a3_missing_total_no_roster_no_false_completion",
            "r1a3_invalid_total_string_no_roster_no_false_completion",
            "r1a3_invalid_total_negative_no_roster_no_false_completion",
            "r1a3_invalid_total_float_no_roster_no_false_completion",
            "r1a3_empty_bad_total_no_roster_no_false_completion",
            "gift_private_start_completion_owner_manager_once_no_operator_support_channel",
            "gift_private_hold_owner_manager_no_replay",
            "gift_private_hold_manager_receiving_off_owner_only",
            "gift_private_manager_notice_failure_preserves_effect_cursor_and_cleanup",
        }
        if not (gift.get("complete") is True and
                set(gift.get("scenarios",[]))==required_gift and
                gift.get("cases")==len(required_gift) and
                gift.get("actual_gift_worker") is True and
                gift.get("actual_notification_router") is True and
                gift.get("actual_admin_access") is True and
                gift.get("private_owner_manager_gift_notices") is True and
                gift.get("operator_support_gift_excluded") is True and
                gift.get("owned_mariadb_only") is True and
                gift.get("real_provider_requests")==0 and
                gift.get("customer_money_effects")==0):
            raise RuntimeError("Gift-worker verified outcome/hold assurance incomplete")
        # Source-real one-payment/one-award journal with atomic SQL credit and
        # no automatic retry after ambiguity. Migration 008 is test-only here.
        referral_path=Path(tmp)/"referral-award-journal.json"
        proc=subprocess.run(
            ["python3",str(ROOT/"tools/test_post_v9_referral_awards.py"),
             "--run-disposable","--report",str(referral_path)],
            cwd=ROOT,capture_output=True,text=True,timeout=120)
        if proc.returncode!=0:
            raise RuntimeError("Referral journal acceptance failed:\n"+proc.stderr[-4500:])
        referral=json.loads(referral_path.read_text())
        required_referral={
            "missing_008_no_wallet_mutation",
            "disabled_award_snapshot_never_retroactively_recredits",
            "one_pay_exactly_one_confirmed_credit_no_repeat_notice",
            "two_line_paid_order_one_award_not_per_line",
            "concurrent_duplicate_callbacks_one_credit",
            "postcredit_false_does_not_reissue_credit",
            "postcredit_throw_does_not_reissue_credit",
            "postcredit_null_does_not_reissue_credit",
            "paid_state_without_verified_delivery_cannot_credit",
            "nonterminal_payment_cannot_credit",
            "buyer_relationship_verified_not_callback",
            "mysqlireportoff_sql_failure_retained_review_no_blind_retry",
            "credit_and_confirmation_atomic_rollback",
            "signed_debt_credit_arithmetic_preserved",
            "missing_unique_pay_index_fails_closed",
        }
        if not (referral.get("complete") is True and
                set(referral.get("scenarios",[]))==required_referral and
                referral.get("native_php_mariadb") is True and
                referral.get("socket_only") is True and
                referral.get("production_mutations")==0):
            raise RuntimeError("Referral award source evidence incomplete")
        # Independent review PR #72 REQUIRED-1: award registration must
        # survive failures of earlier post-COMMIT QR/Telegram/markup calls.
        route_award_path=Path(tmp)/"referral-postcommit-routes.json"
        route_award_proc=subprocess.run(
            ["python3",str(ROOT/"tools/test_post_v9_referral_route_order.py"),
             "--run-disposable","--report",str(route_award_path)],
            cwd=ROOT,capture_output=True,text=True,timeout=120)
        if route_award_proc.returncode!=0:
            raise RuntimeError("Referral postcommit source routes failed:\n"+
                (route_award_proc.stderr or route_award_proc.stdout)[-4500:])
        route_award=json.loads(route_award_path.read_text())
        required_route_award={
            "real_standard_receipt_qr_throw_award_confirmed_no_replay",
            "real_standard_receipt_customer_message_throw_award_confirmed_no_replay",
            "real_standard_receipt_admin_markup_throw_award_confirmed_no_replay",
            "real_custom_receipt_qr_throw_award_confirmed_no_replay",
            "real_custom_receipt_customer_message_throw_award_confirmed_no_replay",
            "real_custom_receipt_admin_markup_throw_award_confirmed_no_replay",
        }
        if not (route_award.get('complete') is True and
                set(route_award.get('scenarios',[]))==required_route_award and
                route_award.get('native_php_mariadb') is True and
                route_award.get('financial_effects_production')==0 and
                route_award.get('real_telegram_or_provider_calls')==0):
            raise RuntimeError('Referral postcommit route identity incomplete')

        # The formerly byte-parity-bound paid wallet and unpaid trial/bulk
        # CREATE routes intentionally changed financial safety semantics. Run
        # real current handlers and durable reservation against owned MariaDB.
        wallet_path=Path(tmp)/"wallet-create-source.json"
        native_wallet=subprocess.run(
            ["python3",str(ROOT/"tools/test_post_v9_wallet_creation.py"),
             "--run-disposable","--report",str(wallet_path)],
            cwd=ROOT,capture_output=True,text=True,timeout=120)
        if native_wallet.returncode!=0:
            raise RuntimeError("Wallet pre-POST journal acceptance failed:\n"
                +native_wallet.stderr[-4500:])
        wallet_create=json.loads(wallet_path.read_text())
        required_wallet={
            "wallet_confirmed_debit_and_capacity_once_before_post",
            "wallet_unknown_post_keeps_paid_hold_and_no_replay",
            "wallet_remote_success_local_order_failure_no_recredit_or_create",
            "wallet_capacity_rejected_before_debit",
            "wallet_missing_007_declines_without_financial_mutation",
            "wallet_sql_debit_failure_rolls_back_before_post",
            "wallet_two_concurrent_callbacks_single_debit_and_post"
        }
        if not (wallet_create.get('complete') is True and
                set(wallet_create.get('scenarios',[]))==required_wallet and
                wallet_create.get('php_mysqli') is True and
                wallet_create.get('provider_real_requests')==0 and
                wallet_create.get('production_mutations')==0):
            raise RuntimeError('Wallet creation replay/debit safety incomplete')
        nonpaid_path=Path(tmp)/"trial-bulk-native.json"
        native_nonpaid=subprocess.run(
            ["python3",str(ROOT/"tools/test_post_v9_nonpaid_routes.py"),
             "--run-disposable","--report",str(nonpaid_path)],
            cwd=ROOT,capture_output=True,text=True,timeout=120)
        if native_nonpaid.returncode!=0:
            raise RuntimeError("Nonpaid source journal acceptance failed:\n"
                +native_nonpaid.stderr[-4500:])
        nonpaid=json.loads(nonpaid_path.read_text())
        required_nonpaid={
            "actual_trial_route_reserved_once_with_real_order_and_delivery",
            "actual_trial_unknown_remote_retained_fence_and_capacity",
            "forged_trial_update_no_dispatch",
            "actual_admin_bulk_two_accounts_full_reservation_one_batch",
            "actual_admin_bulk_partial_retains_capacity_and_audit",
            "two_real_admin_batches_race_one_available_server_slot",
            "real_manual_remark_two_independent_single_and_multi_batches_unique_names_no_replay",
            "invalid_manual_remark_rejected_without_remote_or_capacity_effect",
            "nonpaid_source_db_insert_failure_before_post_no_capacity_loss",
            "trial_postcommit_telegram_failure_no_recreate"
        }
        if not (nonpaid.get('complete') is True and
                set(nonpaid.get('scenarios',[]))==required_nonpaid and
                nonpaid.get('source_real_php') is True and
                nonpaid.get('socket_only') is True and
                nonpaid.get('production_mutations')==0 and
                nonpaid.get('provider_requests')==0):
            raise RuntimeError("Trial/bulk exact handler safety incomplete")

        # Actual customer-facing checkout handlers (not just the wallet
        # settlement callback) must prove multi-account and custom BUY_SUB
        # finality, source identity, and postcommit delivery ordering.
        wallet_checkout_path=Path(tmp)/"wallet-checkout-source.json"
        native_checkout=subprocess.run(
            ["python3",str(ROOT/"tools/test_post_v9_wallet_checkout.py"),
             "--run-disposable","--report",str(wallet_checkout_path)],
            cwd=ROOT,capture_output=True,text=True,timeout=120)
        if native_checkout.returncode!=0:
            raise RuntimeError("Wallet Checkout actual source failed:\n"
                +native_checkout.stderr[-4500:])
        wallet_checkout=json.loads(wallet_checkout_path.read_text())
        required_wallet_checkout={
            # R4 independent-required reporting: no editable plan/template
            # may replace the locked entitlement in an admin/channel report.
            "r4_unchanged_standard_wallet_report_matches_fulfillment",
            "r4_locked_plan_report_admin_and_channel_exact_entitlement_no_replay",
            "r4_custom_operator_report_retains_persisted_dimensions",
            # R3 source-real post-COMMIT capacity ownership and locked paid
            # plan snapshot, including independent MariaDB session edits.
            "r3_exact_owned_last_slots_r3_final_standard",
            "r3_exact_owned_last_slots_r3_final_custom",
            "r3_exact_owned_last_slots_r3_final_multi",
            "r3_excess_quantity_denied_before_wallet_hold",
            "r3_independent_buyers_one_slot_no_double_debit",
            "r3_owned_wallet_hold_survives_postcommit_rival",
            "r3_locked_plan_dimensions_survive_postcommit_admin_edit",
            "r3_persisted_custom_dimensions_survive_postcommit_plan_edit",
            # R2 source-real controller/handler mode binding, including two
            # simultaneous PHP workers racing the same locked payment.
            "r2_custom_quote_standard_callback_predebit_denied",
            "r2_standard_quote_custom_callback_predebit_denied",
            "r2_partial_custom_quote_predebit_denied",
            "r2_zero_template_exact_custom_dimensions_one_effect",
            "r2_standard_single_multi_plan_dimensions_no_replay",
            "r2_concurrent_wrong_right_mode_exactly_one_effect",
            "actual_catalogue_much_draft_rejects_invalid_remote_usernames",
            "real_custom_wallet_persisted_bad_name_rejected_before_debit_hold_and_create",
            "real_catalogue_much_valid_draft_to_wallet_one_effect_order",
            "real_custom_zero_template_dimensions_use_persisted_quote",
            "real_custom_persisted_multicount_rejected_predebit",
            "real_standard_manual_remark_invalid_rejected_predebit",
            "real_standard_invalid_plan_day_predebit_no_hold",
            "real_standard_invalid_generated_prefix_predebit_no_hold",
            "r2_exact_wallet_order_allocation_100_3_no_replay",
            "r2_exact_wallet_order_allocation_101_2_no_replay",
            "real_multi_wallet_checkout_full_hold_order_delivery_after_finality",
            "real_multi_wallet_second_line_ambiguous_stays_paid_hold_no_replay",
            "real_custom_wallet_checkout_single_effect_after_commit",
            "real_custom_wallet_postcommit_telegram_unconfirmed_never_reissues",
            "real_multi_wallet_postcommit_delivery_throw_owner_review_no_replay",
            "real_custom_wallet_verified_remote_failed_order_retained_review"
        }
        if not (wallet_checkout.get("complete") is True and
                set(wallet_checkout.get("scenarios",[]))==required_wallet_checkout and
                wallet_checkout.get("actual_checkout_php") is True and
                wallet_checkout.get("isolated_mariadb") is True and
                wallet_checkout.get("remote_calls")==0 and
                wallet_checkout.get("production_financial_mutations")==0):
            raise RuntimeError("Wallet full route acceptance incomplete")

        # Exact callback identity across all five direct wallet-charge routes;
        # execute actual PHP/MySQLi and fail if a malformed button can debit.
        wallet_frame_path=Path(tmp)/"wallet-callback-framing.json"
        framed=subprocess.run(
            ["python3",str(ROOT/"tools/test_post_v9_wallet_callback_framing.py"),
             "--run-disposable","--report",str(wallet_frame_path)],
            cwd=ROOT,capture_output=True,text=True,timeout=90)
        if framed.returncode!=0:
            raise RuntimeError("Wallet callback framing source acceptance failed:\n"
                +framed.stderr[-4500:])
        wallet_frame=json.loads(wallet_frame_path.read_text())
        expected_framing={
            f'{subject}_{kind}_no_wallet_or_service_effect'
            for subject in ('buy','custom','renew','day','volume')
            for kind in ('embedded_action','trailing_separator','multibyte_suffix')
        } | {
            f'{subject}_{kind}'
            for subject in ('buy','custom','renew','day','volume')
            for kind in ('valid_owned_callback_still_pays_once','paid_callback_no_replay')
        } | {'wallet_exact_64_byte_callback_accepted',
             'wallet_65_byte_callback_without_side_effects',
             'unrelated_callback_not_blocked_by_wallet_guard'} | {
            f'{subject}_{kind}'
            for subject in ('buy','custom','renew','day','volume')
            for kind in ('full_bot_invalid_wallet_namespace_consumed',
                         'valid_frame_reaches_dispatch')
        }
        if not (wallet_frame.get('complete') is True
                and wallet_frame.get('cases')==38
                and set(wallet_frame.get('scenarios',[]))==expected_framing
                and wallet_frame.get('real_php_mariadb') is True
                and wallet_frame.get('original_payment_schema_preserved') is True
                and wallet_frame.get('provider_requests')==0
                and wallet_frame.get('live_wallet_mutations')==0):
            raise RuntimeError('Direct wallet callback framing matrix incomplete')

        # Six current manual-method choices must have exact callback framing,
        # explicit method binding and no fallthrough into a second action.
        manual_frame_path=Path(tmp)/"manual-choice-callback-framing.json"
        framed_manual=subprocess.run(
            ["python3",str(ROOT/"tools/test_post_v9_manual_choice_framing.py"),
             "--run-disposable","--report",str(manual_frame_path)],
            cwd=ROOT,capture_output=True,text=True,timeout=120)
        if framed_manual.returncode!=0:
            raise RuntimeError("Manual choice callback framing source acceptance failed:\n"
                +framed_manual.stderr[-4500:])
        manual_frame=json.loads(manual_frame_path.read_text())
        manual_subjects=('buy','custom','renew','day','volume','topup')
        manual_methods=('CartToCart','GiftCard')
        expected_manual_frame={
            f'{subject}_{method}_{kind}_denied'
            for subject in manual_subjects for method in manual_methods
            for kind in ('prefixed','trailing','unicode')
        } | {
            f'{subject}_{method}_{kind}'
            for subject in manual_subjects for method in manual_methods
            for kind in ('valid_choice_pins_correct_provider',
                         'reclick_keeps_one_claim',
                         'hash_text_cannot_change_method')
        } | {
            f'{subject}_crypto_malformed_no_intent' for subject in manual_subjects
        } | {
            f'{subject}_{method}_{kind}'
            for subject in manual_subjects
            for method in (*manual_methods,'ManualUsdt')
            for kind in ('full_bot_invalid_consumed','full_bot_valid_continues')
        } | {
            f'{subject}_{tag}_{kind}'
            for subject in manual_subjects for tag in ('c','t')
            for kind in ('long_hash_untrusted_external_denied',
                         'long_hash_verified_network_choice_pinned')
        } | {'topup_manual_choice_exact_64_bytes_valid',
             'topup_manual_choice_65_bytes_denied',
             'manual_choice_guard_unrelated_namespace_continues'}
        if not (manual_frame.get('complete') is True
                and manual_frame.get('cases')==141
                and set(manual_frame.get('scenarios',[]))==expected_manual_frame
                and manual_frame.get('real_php_mariadb') is True
                and manual_frame.get('socket_only') is True
                and manual_frame.get('source_payment_tables_unchanged') is True
                and manual_frame.get('provider_calls')==0
                and manual_frame.get('live_financial_mutations')==0):
            raise RuntimeError('Manual payment choice callback framing matrix incomplete')

        # Issue #26: native real bank-Cron PHP on disposable MariaDB.
        bank_path=Path(tmp)/"bank-buy-effects.json"
        bank_run=subprocess.run(
            ["python3",str(ROOT/"tools/test_post_v9_bank_buy.py"),
             "--run-disposable","--report",str(bank_path)],
            cwd=ROOT,timeout=140,capture_output=True,text=True)
        if bank_run.returncode!=0:
            raise RuntimeError("Bank BUY_SUB source-native acceptance failed:\n"
                               +bank_run.stderr[-5500:])
        bank_buy=json.loads(bank_path.read_text())
        required_bank={
            "buy_sub_nonterminal_exclusive_claim",
            "one_verified_account_atomic_order_before_telegram_no_replay",
            "unknown_lost_no_refund_success_or_replay",
            "unknown_exists_no_refund_success_or_replay",
            "unknown_wrong_note_no_refund_success_or_replay",
            "unknown_fraction_no_refund_success_or_replay",
            "multi_line_atomic_orders_and_integer_amount_allocation",
            "multi_line_partial_unknown_requires_review_not_refund",
            "sql_failure_after_remote_verified_fails_closed",
            "failed_local_commit_operator_receives_payment_identity",
            "postcommit_telegram_failure_alerts_operator_without_recreate",
            "postcommit_telegram_unconfirmed_alerts_operator_without_recreate",
            "nonbank_attempt_never_auto_provisions",
            "two_simultaneous_bank_workers_one_remote_create",
            "postverified_plan_dimensions_edit_keeps_standard_quote_and_order",
            "postverified_plan_dimensions_edit_keeps_custom_quote_and_order",
            "postverified_plan_physical_target_edit_stays_under_review",
            "r1_postverified_invoice_amount_drift_keeps_original_invoice_nonterminal",
            "r1_postverified_invoice_mode_drift_keeps_original_invoice_nonterminal",
            "r1_postverified_invoice_custom_to_standard_keeps_original_invoice_nonterminal",
            "r1_postverified_invoice_coordinated_drift_keeps_original_invoice_nonterminal",
            "r1_postverified_bank_intent_amount_drift_keeps_original_invoice_nonterminal",
            "r1_issued_bank_amount_differs_before_claim_no_new_original",
            "r1_bank_method_drift_after_claim_no_remote_post",
            "r1_bank_random_transfer_quote_keeps_original_nominal_price",
            "r1_postclaim_preflight_mutation_never_posts",
            "r1_missing_invoice_contract_before_claim_fails_closed",
            "r1a_real_photo_receipt_only_extends_003_evidence_original_009_approved_once",
            "r1a_original_bank_destination_changed_before_cron_denied",
            "r1a_real_manual_issue_snapshots_original_quantity_entitlement_and_attempt",
            "r1a_original_standard_entitlement_survives_precron_template_edit_no_replay",
            "r1a_precron_quantity_changed_denied_without_remote_post",
            "r1a_precron_standard_to_custom_denied_without_remote_post",
            "r1a_precron_description_changed_denied_without_remote_post",
            "r1a_precron_custom_dimensions_changed_denied_without_remote_post",
            "r1a_precron_custom_to_standard_denied_without_remote_post",
            "r1a_precron_original_server_changed_denied_without_remote_post",
            "r1a_original_random_transfer_quote_changed_before_cron_denied",
            "r1b_009_fk_restrict_protects_original_003_attempt",
            "r1a_legacy_no_attempt_no_issued_quote_operator_only",
            "r1a_legacy_003_without_009_never_backfilled_by_cron",
            "r1b_original_attempt_deleted_after_claim_blocks_preflight",
            "r1b_original_attempt_replaced_after_claim_blocks_preflight",
            "r1b_original_attempt_deleted_after_verified_get_nonterminal",
            "r1b_original_attempt_replaced_after_verified_get_nonterminal",
            "r1b_deleted_original_attempt_before_first_cron_claim_denied",
            "r1a_existing_003_without_issued_009_not_reopened_or_backfilled",
            "r1a_failed_009_issue_rolls_back_original_003_atomically",
            "r1a_missing_009_refuses_new_bank_intent_without_half_commit",
        }
        if not (bank_buy.get("complete") and bank_buy.get("cases")>=49
                and required_bank.issubset(bank_buy.get("scenarios",[]))
                and bank_buy.get("source_real_php")
                and bank_buy.get("owned_mariadb_only")
                and bank_buy.get("real_provider_requests")==0
                and bank_buy.get("real_financial_effects")==0
                and bank_buy.get("installed_operator_tested") is False):
            raise RuntimeError("Bank BUY_SUB finality/remote-effects contract incomplete")
        # Owner-private BUY_SUB crash inspection is read-only even with
        # an inconclusive authenticated provider GET. Exercise actual
        # Telegram route, exact ledger/endpoint and SQL on owned MariaDB.
        recovery_path=Path(tmp)/"recovery-inspection.json"
        recovery_run=subprocess.run(
            ["python3",str(ROOT/"tools/test_post_v9_recovery_inspection.py"),
             "--run-disposable","--report",str(recovery_path)],
            cwd=ROOT,timeout=140,capture_output=True,text=True)
        if recovery_run.returncode!=0:
            raise RuntimeError("BUY_SUB recovery workbench acceptance failed:\n"
                               +recovery_run.stderr[-6000:])
        recovery=json.loads(recovery_path.read_text())
        required_recovery={
            "unresolved_bank_case_reads_exact_local_identity_without_sql_writes",
            "owner_private_wallet_debit_capacity_hold_visible_no_unissued_get",
            "strict_one_account_get_only_matching_observation_no_replay",
            "no_financial_write_remote_missing",
            "no_financial_write_remote_timeout",
            "no_financial_write_remote_wrong_note",
            "no_financial_write_remote_float_bytes",
            "no_financial_write_remote_float_expiry",
            "missing_ordinal_never_infers_remote_absence_or_gets",
            "nonowner_or_nonprivate_inspection_denied_9002_9001",
            "nonowner_or_nonprivate_inspection_denied_9001_7777",
            "owner_private_readback_button_real_callback_no_sql_mutations",
            "endpoint_drift_fences_remote_get_without_guessing",
            "concurrent_endpoint_change_disqualifies_readback_evidence",
            "15_line_keyset_pagination_and_exact_late_account_get",
            "twelve_account_page_telegram_length_and_button_bounds",
            "bank_payment_without_effect_visible_and_13th_case_reachable",
            "terminal_payment_not_reattributed_to_pending_case",
            "missing_ledger_schema_fails_closed_without_get_or_mutation",
            "corrupted_operation_fingerprint_blocks_remote_get",
            "logical_group_drift_blocks_remote_get",
            "deleted_historical_order_preserved_without_inferred_refund",
            "hosted_paid_state_not_assumed_settled_or_mutated",
            "owner_private_full_inbox_page_and_case_ui_navigation",
            "missing_unique_effect_index_blocks_remote_selection",
            "changed_plan_inbound_rejected_before_any_remote_get",
            "invalid_payment_account_count_fails_closed",
            "zero_quota_with_matching_fingerprint_never_gets",
            "ordinal_exceeding_mutated_payment_account_count_no_remote_get",
            "fifteen_requested_two_recorded_clear_owner_private_operation_ids",
            "terminal_after_get_owner_sees_uncertainty_once_ack",
            "telegram_edit_failure_falls_back_to_owner_private_send",
            "mysqlireport_off_sql_failure_after_get_ends_owner_visible_uncertain",
        }
        if not (recovery.get("complete")
                and recovery.get("cases")>=36
                and required_recovery.issubset(recovery.get("scenarios",[]))
                and recovery.get("actual_php_admin_route") is True
                and recovery.get("owned_socket_mariadb") is True
                and recovery.get("max_account_get_per_callback")==1
                and recovery.get("remote_writes")==0
                and recovery.get("money_mutations")==0
                and recovery.get("installed_operator_tested") is False):
            raise RuntimeError("Owner-only read-only recovery contract incomplete")
        # The actual production curl adapter (rather than a getMarzbanUser
        # mock) must authenticate and GET on one pinned endpoint and IP.
        transport_path=Path(tmp)/"recovery-transport.json"
        transport_run=subprocess.run(
            ["python3",str(ROOT/"tools/test_post_v9_recovery_transport.py"),
             "--run-disposable","--report",str(transport_path)],
            cwd=ROOT,timeout=140,capture_output=True,text=True)
        if transport_run.returncode!=0:
            raise RuntimeError("Pinned HTTP recovery acceptance failed:\n"
                               +transport_run.stderr[-6000:])
        transport=json.loads(transport_path.read_text())
        if not (transport.get("complete") is True and
                transport.get("cases")>=27 and
                transport.get("real_curl_login_and_get") is True and
                transport.get("loopback_only") is True and
                transport.get("max_account_get_per_inspection")==1 and
                transport.get("redirects_followed")==0 and
                transport.get("credential_leak_to_redirect") is False and
                transport.get("remote_writes")==0 and
                transport.get("money_mutations")==0 and
                transport.get("installed_operator_tested") is False):
            raise RuntimeError("Actual pinned login/GET assurance incomplete")
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
            "agent_explicit_settings_price_and_trc20_validated",
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
            # PR #66: execute the real standard purchase and renewal admin
            # paths, including post-COMMIT messaging and SQL-off failures.
            "real_standard_ambiguous_remote_and_failed_admin_notice_no_fatal_no_replay",
            "real_standard_concurrent_admins_one_remote_create",
            "real_standard_explicit_remote_failure_kept_for_review_without_local_order",
            "real_standard_hosted_provider_conflict_denied",
            "real_standard_missing_operator_delivery_denied",
            "real_standard_mysqlireportoff_capacity_reservation_failure_prevents_create",
            "real_standard_mysqlireportoff_unconfirmed_order_never_approved",
            "real_standard_nonadmin_callback_no_financial_effect",
            "real_standard_operator_buttons_failure_does_not_leak_raw_exception",
            "real_standard_operator_unreviewed_receipt_denied",
            "real_standard_partial_second_account_stays_reconcile_no_replay",
            "real_standard_postcommit_telegram_failure_approved_no_replay_truthful_review",
            "real_standard_remote_success_local_write_failed_no_automatic_replay",
            "real_standard_renewal_both_telegram_messages_fail_without_fatal_or_replay",
            "real_standard_renewal_explicit_remote_failure_no_local_order_no_replay",
            "real_standard_renewal_mysqlireportoff_unconfirmed_order_never_approved",
            "real_standard_renewal_postcommit_notification_outage_preserves_approved_no_replay",
            "real_standard_renewal_receipt_one_remote_change_no_replay",
            "real_standard_renewal_remote_success_local_failure_needs_review",
            "real_standard_renewal_uncertain_remote_change_no_replay",
            "real_standard_uncertain_remote_effect_stays_reconciliation_and_no_replay",
            "real_standard_verified_receipt_one_delivery_no_replay",
            "real_standard_wrong_subject_denied",
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
        required_manual.update({
            'r2_exact_manual_order_allocation_100_3_no_replay',
            'r2_exact_manual_order_allocation_101_2_no_replay',
            'auto_bank_day_service_only_after_verified_remote_and_atomic_local_effect',
            'auto_bank_volume_service_only_after_verified_remote_and_atomic_local_effect',
            'auto_bank_renew_service_only_after_verified_remote_and_atomic_local_effect',
            'auto_bank_standard_service_only_after_verified_remote_and_atomic_local_effect',
            'auto_bank_local_sql_failure_does_not_claim_service_delivered',
            'review_r4_bank_lastint_sql_strict',
            'review_r4_bank_overflow_sql_strict',
            'review_r4_bank_lastint_sql_permissive',
            'review_r4_bank_overflow_sql_permissive',
            'review_r5_remote_a_then_b_local_b_then_a_fenced',
            'review_r5_stale_bank_after_auto_renew_no_completion',
            'review_r5_stale_bank_after_manual_edit_no_completion',
            'review_r5_stale_bank_after_volume_after_renew_no_completion',
            'native_real_bank_scheduler_day_verified_verified_remote_required_no_duplicate',
            'native_real_bank_scheduler_day_unconfirmed_verified_remote_required_no_duplicate',
            # R7 manually approved, direct wallet day and wallet full-renewal flows.
            'review_r7_wallet_walletdayfuture_verified_or_review_no_replay',
            'review_r7_wallet_walletdayexpired_verified_or_review_no_replay',
            'review_r7_wallet_walletdayunchanged_verified_or_review_no_replay',
            'review_r7_wallet_walletdayunknown_verified_or_review_no_replay',
            'review_r7_wallet_walletdaystale_verified_or_review_no_replay',
            'review_r7_wallet_walletdayoverflow_verified_or_review_no_replay',
            'review_r7_wallet_renew_walletrenewfuture_remote_absolute_or_review_no_replay',
            'review_r7_wallet_renew_walletrenewexpired_remote_absolute_or_review_no_replay',
            'review_r7_wallet_renew_walletrenewunknown_remote_absolute_or_review_no_replay',
            'review_r7_wallet_renew_walletrenewstale_remote_absolute_or_review_no_replay',
            'review_r7_wallet_renew_walletrenewoverflow_remote_absolute_or_review_no_replay',
            'review_r7_wallet_renew_grouped_physical_panel_alias_authorized_once',
            'review_r7_wallet_renew_cross_panel_alias_denied_predebit',
            # PR #68 R8: actual bank scheduler + real Core group authority,
            # post-remote locked authorization, identity CAS and no replay.
            'review_r8_bank_grouped_renew_scheduler_verified_once',
            'review_r8_bank_grouped_day_scheduler_verified_once',
            'review_r8_bank_grouped_volume_scheduler_verified_once',
            'review_r8_bank_foreign_renew_blocked_before_remote',
            'review_r8_bank_foreign_day_blocked_before_remote',
            'review_r8_bank_foreign_volume_blocked_before_remote',
            'review_r8_bank_group_revoked_after_preflight_nonterminal',
            'review_r8_bank_renew_identity_drift_nonterminal',
            'review_r8_bank_day_identity_drift_nonterminal',
            'review_r8_bank_volume_identity_drift_nonterminal',
            'review_r8_bank_original_paid_plan_pinned_after_coherent_drift',
            'review_r8_bank_original_paid_quote_pinned_after_type_drift',
            'review_r8_bank_grouped_uncertain_review_no_retry_refund',
            'review_r8_bank_grouped_sql_failure_review_no_retry_refund',
            'review_r7_manual_manualdayremoteahead_atomic_expiry_and_no_replay',
            'review_r7_manual_manualdayexpired_atomic_expiry_and_no_replay',
            'review_r7_manual_manualdayunknown_atomic_expiry_and_no_replay',
            'review_r7_manual_manualdaystale_atomic_expiry_and_no_replay',
            'review_r7_manual_manualdayoverflow_atomic_expiry_and_no_replay',
            'review_r7_manual_manualdaysqlstrict_atomic_expiry_and_no_replay',
            'review_r7_manual_manualdaysqlpermissive_atomic_expiry_and_no_replay',
        })
        required_manual.update({"pinned_addon_unknown_native_refuses_before_claim_or_effect",
            "bank_scheduler_unknown_native_refuses_before_claim_or_effect"})
        if not (manual.get("complete") and manual.get("cases")>=len(required_manual)
                and required_manual.issubset(manual.get("scenarios",[]))
                and manual.get("database")=="owned_socket_only"
                and manual.get("live_provider_calls")==0
                and manual.get("production_data_modified") is False
                and all(manual.get(flag) is True for flag in (
                    "current_order_writer_argument_contract", "pinned_addon_fresh_native_baseline",
                    "unknown_native_pinned_addon_refused", "bank_addon_fresh_native_baseline",
                    "unknown_native_bank_addon_refused"))):
            raise RuntimeError("Four-network manual crypto payment isolation incomplete")
        # PR #66 independent reviewer R1–R6: actual financial/purchase PHP
        # helpers and original MariaDB, not author-only approval route doubles.
        integrity_path=Path(tmp)/"manual-standard-real-helpers.json"
        trial=subprocess.run(
            ["python3",str(ROOT/"tools/test_post_v9_standard_integrity.py"),
             "--run-disposable","--report",str(integrity_path)],
            cwd=ROOT,timeout=180,capture_output=True,text=True)
        if trial.returncode!=0:
            raise RuntimeError("Manual standard R1-R6 real-helper acceptance failed:\n"+
                               (trial.stderr or trial.stdout)[-6500:])
        integrity=json.loads(integrity_path.read_text())
        required_integrity={
            'r1_insufficient_total_count_prevents_any_remote_create',
            'r1_exact_two_units_reserved_once_and_replay_fenced',
            'r2_actual_manual_helper_integer_allocation_100_3_no_replay',
            'r2_actual_manual_helper_integer_allocation_101_2_no_replay',
            'r1_remote_unknown_keeps_pre_reserved_capacity_no_replay',
            'r1_after_two_remote_creates_SQL_failure_keeps_reserved_capacity_review_only',
            'r1_mysqlireportoff_conditional_reservation_SQL_failure_no_create',
            'r1_manual_hold_insert_failure_rollback_claim_and_capacity',
            'r1_real_conditional_capacity_helper_cannot_go_negative',
            'r1_two_real_php_buyers_one_capacity_unit_only_one_external_create',
            'r2_plan_reassigned_to_other_remote_with_same_remark_zero_wrong_edit',
            'r2_persisted_payment_server_pinned_on_successful_renewal',
            'r2_renewal_adapter_unverified_success_never_records_payment',
            'r3_nonthrowing_renewal_customer_notice_renew_user_false_already_approved',
            'r3_nonthrowing_renewal_customer_notice_renew_user_null_already_approved',
            'r3_nonthrowing_or_thrown_telegram_qr_false_approved_no_replay',
            'r3_nonthrowing_or_thrown_telegram_qr_null_approved_no_replay',
            'r3_nonthrowing_or_thrown_telegram_qr_throw_approved_no_replay',
            'r3_nonthrowing_or_thrown_telegram_operator_false_approved_no_replay',
            'r3_nonthrowing_or_thrown_telegram_edit_false_approved_no_replay',
            'r3_nonthrowing_or_thrown_telegram_edit_null_approved_no_replay',
            'r3_real_telegram_editKeys_propagates_false_response',
            'r4_referral_confirmed_one_wallet_update_no_callback_recredit',
            'r4_mysqlireportoff_reward_write_failure_no_false_credit_notice',
            'r4_wallet_overflow_fails_without_false_ack',
            'r4_postcredit_telegram_failure_never_recredits',
            'r4_confirmed_credit_independent_of_referral_notice_throw',
            'r4_confirmed_credit_independent_of_referral_notice_false',
            'r4_confirmed_credit_independent_of_referral_admin_false',
            'r4_confirmed_credit_independent_of_referral_channel_null',
            'r4_unconfirmed_sql_credit_returns_false_without_notice',
            'r4_missing_inviter_no_false_credit_notice',
            'r5_original_receipt_without_003_with_007_can_fulfill_once',
            'r5_missing_007_operation_unique_index_refuses_preclaim_reservation',
            'r5_original_23_tables_missing_007_keeps_receipt_and_capacity_unclaimed',
            'r5_absent_original_operator_evidence_is_rejected',
            'r5_partially_installed_003_denied_under_mysqlireportoff',
            'r5_incompatible_ledger_column_SQL_failure_not_legacy_receipt',
            'r5_complete_003_empty_attempts_historical_receipt_allowed',
            'r2_after_claim_renewal_plan_inactive_no_remote_edit',
            'r2_after_claim_renewal_plan_deleted_no_remote_edit',
            'r2_after_claim_purchase_plan_inactive_no_remote_create',
            'r3_real_qr_helper_photo_ok_account_text_qr_text_false_reported',
            'r3_real_qr_helper_photo_ok_account_text_qr_text_null_reported',
            'r3_real_qr_helper_photo_ok_account_text_qr_text_throw_reported',
            'r3_three_accounts_second_text_failed_third_still_attempted_and_reward_credited',
            'r3_multiple_admin_refs_one_edit_fails_later_edit_still_attempted',
            'r4_referral_decision_not_skipped_when_QR_photo_fails',
            'r5_valid_attempts_only_no_child_table_denied',
            'r5_valid_attempts_only_with_reviewed_attempt_denied',
        }
        if not (integrity.get('complete') is True and
                integrity.get('cases')==len(required_integrity) and
                set(integrity.get('scenarios',[]))==required_integrity and
                integrity.get('actual_php_core_helpers') is True and
                integrity.get('actual_mariadb_capacity') is True and
                integrity.get('actual_mysqlireportoff_failure') is True and
                integrity.get('isolated_marzban_telegram_spies') is True and
                integrity.get('production_mutations')==0 and
                integrity.get('installed_acceptance') is False):
            raise RuntimeError("Mandatory real-helper manual standard evidence incomplete")
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
            'managed_display_replaces_only_two_legacy_global_toggles',
        }
        if not (crypto_guard.get('complete')
                and crypto_guard.get('cases')>=135
                and required_crypto_guard.issubset(crypto_guard.get('scenarios',[]))
                and crypto_guard.get('official_tron_test_vectors_verified')
                and crypto_guard.get('independent_base58check_oracle')
                and crypto_guard.get('real_bot_admin_callbacks_executed')
                and crypto_guard.get('real_config_setting_writers_extracted')
                and crypto_guard.get('actual_payment_editor_executed')
                and crypto_guard.get('retired_flags_preserved_not_mutated')
                and crypto_guard.get('retired_keys_denied_at_persistence_boundary')
                and crypto_guard.get('all_advertised_current_admin_toggles_work')
                and crypto_guard.get('moved_display_global_toggles')==['configLinkState','subLinkState']
                and crypto_guard.get('retained_original_bot_toggles')==23
                and crypto_guard.get('retained_original_gateway_toggles')==8
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
        if not (logical.get("complete") and logical.get("cases")>=45
                and logical.get("administrator_only_switching") is True
                and logical.get("schema_tables")==23
                and logical.get("additive_server_info_column_verified")
                and logical.get("original_historical_schema_file_unchanged")
                and logical.get("same_panel_group_not_derived_from_url_or_username")
                and logical.get("concurrent_two_admin_group_creation_verified")
                and logical.get("pinned_v9_source_on_migrated_schema_verified")
                and logical.get("unicode_group_name_contract_verified")
                and logical.get("no_real_provider_or_payment_calls")
                and logical.get("current_unified_onboarding")=={
                    "actual_management_entry":True,"private_fresh_frames":True,
                    "nonce_revision_group_callback":True,"unknown_group_refused_without_writes":True,
                    "invalid_frame_and_stale_input_refused_without_writes":True,
                    "explicit_confirmation_required":True,"provider_admission_calls":2,
                    "remote_account_writes":0}):
            raise RuntimeError("Same-Marzban-panel logical reassignment contract incomplete")
        overlay_report=Path(tmp)/"source-overlay.json"
        subprocess.run(["python3",str(ROOT/"tools/test_post_v9_swap.py"),
                        "--run-disposable","--report",str(overlay_report)],
                       cwd=ROOT,check=True,timeout=60,capture_output=True)
        swap=json.loads(overlay_report.read_text())
        if not (swap.get("complete") and swap.get("phase_count")==4
                and swap.get("runtime_members")==package.EXPECTED_RUNTIME_COUNT
                and swap.get("historical_runtime_members")==92
                and swap.get("retired_old_payment_files_absent_after_source_upgrade")
                and swap.get("source_roll_forward_and_back_byte_identical")
                and swap.get("unchanged_original_sql_ddl")
                and swap.get("synthetic_private_files_untouched")):
            raise RuntimeError("Physical post-V9/V9 source-only overlay or private-state check failed")
        if a.report:a.report.write_text(json.dumps({"complete":True,
                    "source":result,"owner_ux_r5":owner_r5,
                    "owner_r6_notifications":notifications,"marzban":marzban,"purchases":purchases,
                    "existing_payment_apis":{"settings":api_settings,"evidence":api_evidence,
                        "evidence_negative_controls":api_evidence_red,"flow":api_flow},
                     "unsupported_wallet_preflight":wallet_guard,
                     "wallet_transfer_safety":wallet_transfer,
                     "wallet_replay_freshness_and_operation_identity":wallet_replay,
                     "wallet_transfer_generation":transfer_generation,
                     "webhook_file_configuration":webhook_config,
                     "admin_wallet_adjustment_safety":admin_wallet,
                     "admin_wallet_ux":wallet_ux,
                     "next_payment_ux":next_payment,
                     "central_text_catalog":text_catalog,
                     "copy_audience_and_numeric_source":copy_audience,
                     "public_bootstrap_privacy":bootstrap_privacy,
                     "public_gateway_privacy":gateway_privacy,
                     "service_unit_quote_guards":unit_guards,
                     "pasarguard_client":pg_client,
                     "provider_integration":provider_integration,
                     "currency_settings":currency,
                     "server_display_settings":server_display,
                     "optional_cleanup":cleanup,
                     "subscription_presentation":presentation,"simple_server_display":simple_display,
                     "customer_error_privacy":audience,
                     "telegram_transport_log_privacy":transport_privacy,
                    "source_only_overlays":swap,
                    "financial_subject_preflight":subject,
                    "catalogue_and_subscription_qr":catalogue,
                    "financial_native_route_guards":finance,
                    "automatic_renewal_ledger":auto_renew,
                    "payment_ux_lifecycle":payment_ux,
                    "payment_browser_feedback":browser_feedback,
                    "payment_readiness_preflight":readiness,
                    "checkout_serialization":checkout_race,
                    "customer_method_serialization":method_race,
                    "wallet_authorization_freshness":wallet_freshness,
                    "latest_review_f1_f4":reviewer_f1_f4,
                    "service_effects":effects,
                    "create_verification_lock_order":verification_locking,
                    "strict_effect_transport_pin":pinned_effects,
                    "gift_source_enqueue_idempotency":gift_source,
                    "direct_admin_gift_verified":direct_gift,
                    "direct_admin_gift_native":native_direct_gift,
                    "gift_worker_safe_continuation":gift,
                    "gift_physical_target_continuity":physical_gift,
                    "wallet_paid_create":wallet_create,
                    "wallet_checkout_routes":wallet_checkout,
                    "wallet_callback_framing":wallet_frame,
                    "manual_choice_callback_framing":manual_frame,
                    "nonpaid_trial_bulk":nonpaid,
                    "referral_awards":referral,
                    "referral_postcommit_routes":route_award,
                    "bank_auto_buy_sub":bank_buy,
                    "bank_recovery_inspection":recovery,
                    "bank_recovery_transport":transport,
                    "manual_payment_method_isolation":manual,
                    "manual_standard_real_helper_review_remediation":integrity,
                    "manual_crypto_destination_and_admin_guards":crypto_guard,
                    "payment_reference_assurance":payment_identity,
                    "shared_payment_ledger_rc4_import":migration,
                     "agency_state_regression":agency,
                     "optional_cron_runner":cron,
                    "marzban_service_modifications":edits,
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
                       "wallet_transfer_generation_scenarios":transfer_generation["cases"],
                       "admin_wallet_adjustment_scenarios":admin_wallet["cases"],
                       "admin_wallet_ux_scenarios":wallet_ux["cases"],
                       "next_payment_ux_scenarios":next_payment["cases"],
                       "pasarguard_client_scenarios":pg_client["cases"],
                       "provider_integration_scenarios":provider_integration["cases"],
                       "currency_settings_scenarios":len(currency["cases"]),
                       "server_display_checks":server_display["checks"],
                       "optional_cleanup_scenarios":cleanup["count"],
                       "subscription_presentation_checks":presentation["checks"],
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
                      "payment_readiness_preflight_cases":readiness["cases"],
                      "checkout_serialization_cases":checkout_race["cases"],
                      "customer_method_serialization_cases":method_race["cases"],
                      "wallet_authorization_freshness_cases":wallet_freshness["cases"],
                      "service_effect_cases":effects["cases"],
                      "strict_effect_transport_pin_cases":pinned_effects["cases"],
                      "gift_source_enqueue_cases":gift_source["cases"],
                      "direct_admin_gift_cases":direct_gift["cases"],
                      "direct_admin_gift_native_cases":native_direct_gift["cases"],
                      "owner_r6_notification_cases":notifications["cases"],
                      "payment_api_settings_cases":len(api_settings["checks"]),
                      "payment_api_evidence_contracts":len(api_evidence["contracts"]),
                      "payment_api_evidence_negative_controls":len(api_evidence_red["expected_RED"]),
                      "payment_api_flow_cases":api_flow["cases"],
                      "gift_worker_reconciliation_cases":gift["cases"],
                      "gift_physical_target_cases":physical_gift["cases"],
                      "wallet_paid_create_cases":wallet_create["cases"],
                      "wallet_checkout_cases":wallet_checkout["cases"],
                      "wallet_callback_framing_cases":wallet_frame["cases"],
                      "manual_choice_callback_framing_cases":manual_frame["cases"],
                      "nonpaid_trial_bulk_cases":nonpaid["cases"],
                      "referral_award_cases":referral["cases"],
                      "referral_postcommit_route_cases":route_award["cases"],
                      "bank_auto_buy_sub_cases":bank_buy["cases"],
                       "bank_recovery_inspection_cases":recovery["cases"],
                      "bank_recovery_transport_cases":transport["cases"],
                      "manual_payment_isolation_cases":manual["cases"],
                      "manual_standard_real_helper_cases":integrity["cases"],
                      "manual_crypto_guard_cases":crypto_guard["cases"],
                      "payment_reference_length":payment_identity["default_length"],
                      "historical_rc4_payment_import_verified":migration["preserved_rc4_rows_and_ids"],
                       "agency_state_cases":agency["cases"],
                       "optional_cron_runner_cases":cron["cases"],
                      "marzban_service_modification_cases":edits["cases"],
                      "marzban_logical_transfer_cases":logical["cases"],
                      "current_php_version":subprocess.check_output(["php","-r","echo PHP_VERSION_ID;"]).decode()}))


if __name__=="__main__":
    try:
        main()
    except subprocess.CalledProcessError as error:
        print("Acceptance child failed (exit %s). Captured source-only fixture diagnostics:" % error.returncode, file=sys.stderr)
        for label, output in (("stdout", error.stdout), ("stderr", error.stderr)):
            if output:
                if isinstance(output, bytes): output=output.decode("utf-8", "replace")
                print(label+":\n"+output[-16000:], file=sys.stderr)
        raise
