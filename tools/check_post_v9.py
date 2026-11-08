#!/usr/bin/env python3
"""Post-V9 current-runtime acceptance; does NOT redefine immutable V9 parity."""
from __future__ import annotations

import argparse
import copy
from collections import Counter
import hashlib
import json
import os
import re
import subprocess
import sys
import tempfile
import time
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


def owner_gift_policy_scenarios() -> list[str]:
    """Owner revision5: one native gift, genuine references and separate notices."""
    providers=['marzban','pasarguard']
    names=['baseline_'+provider+'_'+label+'_RED'
           for provider in providers for label in ['shared','planless']]
    for provider in providers:
        names += [provider+'_'+label+suffix
            for label in ['shared','same_user_dedup','planless','planless_shared',
                          'archived_reference','large_cohort','customer_fail',
                          'report_fail','quota_only']
            for suffix in ['_one_native_gift_preserves_purchase',
                           '_authenticated_replay_no_second_native',
                           '_repeat_sql_finish_cannot_reopen']]
        names += [provider+'_'+label+'_no_native_write'
            for label in ['unproven_planless','foreign_current_reference',
                          'native_incarnation_changed','noncanonical_userid']]
    names += ['marzban_getter_baseline_race_RED']
    names += ['marzban_'+label+suffix
        for label in ['before_effect_incarnation_race','readback_incarnation_race',
                      'late_attachment'] for suffix in ['','_no_replay']]
    names += [provider+'_'+label for provider in providers
        for label in ['bulk_shared_once_dedup_native_journal',
                      'bulk_completion_and_replay_no_second_native']]
    names += [
        'baseline_pasarguard_bulk_lower_id_alias_anchor_RED',
        'pasarguard_bulk_lower_id_alias_prefers_batchserver_anchor',
        'pasarguard_bulk_lower_id_alias_completion_replay_no_second_write',
        'pasarguard_bulk_initial_alias_only_remains_outside_selected_server_roster',
        'pasarguard_bulk_frozen_member_moved_to_alias_preserves_missing_member_hold',
        'pasarguard_bulk_frozen_alias_hold_restored_reference_cannot_rearm',
    ]
    return names


def owner_gift_policy_mismatches(proof: dict, source_head: str,
                                 runtime_hashes: dict) -> dict:
    expected=owner_gift_policy_scenarios()
    mismatches=exact_current_proof_mismatches(proof,{
        'complete':True,'actual_bot_route':True,'actual_bulk_worker':True,
        'actual_native_adapters':True,'actual_notification_router':True,
        'original_source':'a0785dca3e46f031ca9a0a4500a71bf3ea11ee8e',
        'baseline_red_cases':6,
        'alias_regression_source':'a32ec0178b7e1d11a8e8191279b7ef932b93c725','real_provider_requests':0,
        'owned_mariadb_only':True,'loopback_only':True,'production_operations':0,
        'source_head':source_head,'source_head_after':source_head,
        'source_unchanged':True,'source_clean_at_start':True,'source_clean_at_end':True,
        'runtime_sha256':runtime_hashes,'runtime_sha256_after':runtime_hashes,
    },'scenarios',expected)
    if proof.get('scenarios')!=expected:
        mismatches['scenario_order']='required exact ordered Owner gift scenarios'
    observations=proof.get('observations')
    if type(observations) is not list or len(observations)!=len(expected):
        mismatches['observations']='required complete actual observations'
    else:
        for index,(name,observation) in enumerate(zip(expected,observations)):
            if not (type(observation) is dict
                    and set(observation)=={'scenario','passed','actual'}
                    and observation['scenario']==name
                    and observation['passed'] is True
                    and type(observation['actual']) is dict
                    and observation['actual']):
                mismatches['observation_'+str(index)]='required named successful actual observation'
    return mismatches


def owner_gift_policy_negative_controls(proof: dict, source_head: str,
                                        runtime_hashes: dict) -> int:
    if owner_gift_policy_mismatches(proof,source_head,runtime_hashes):
        raise RuntimeError('Gift proof positive admission failed before negative controls')
    controls=[]
    for key,value in [('complete',False),('cases',True),('actual_native_adapters',False),
                      ('baseline_red_cases',4),('real_provider_requests',1),
                      ('source_head_after','0'*40),('source_unchanged',False),
                      ('source_clean_at_end',False)]:
        bad=copy.deepcopy(proof);bad[key]=value;controls.append(bad)
    bad=copy.deepcopy(proof);bad['runtime_sha256_after'].pop('bot.php');controls.append(bad)
    bad=copy.deepcopy(proof);bad['runtime_sha256']['bot.php']='0'*64;controls.append(bad)
    bad=copy.deepcopy(proof);bad['scenarios'].reverse();controls.append(bad)
    bad=copy.deepcopy(proof);bad['observations'].pop();controls.append(bad)
    bad=copy.deepcopy(proof);bad['observations'][0]['passed']=1;controls.append(bad)
    bad=copy.deepcopy(proof);bad['observations'][0]['actual']=[];controls.append(bad)
    for bad in controls:
        if not owner_gift_policy_mismatches(bad,source_head,runtime_hashes):
            raise RuntimeError('Malformed Owner gift proof accepted')
    return len(controls)


def gift_lock_scenarios() -> list[str]:
    phases=['advance','claim','preeffect','complete','bulk']
    return ([prefix+'_pg_'+phase+'_lock_scope'
             for prefix in ['baseline','current'] for phase in phases]
        + ['baseline_direct_bulk_completion_order','current_direct_bulk_completion_order']
        + ['current_rr_'+mode+'_'+case for mode in ['on','off']
           for case in ['native_id','effect_marker','notif_only','effect_timestamp_only']])


def gift_lock_proof_mismatches(proof: dict, head: str, hashes: dict,
                               bulk_hash: str) -> dict:
    expected=gift_lock_scenarios()
    errors=exact_current_proof_mismatches(proof,{
        'complete':True,'actual_direct_phases':True,'actual_bulk_finish_closure':True,
        'owned_socket_mariadb':True,'owned_children_cleaned':True,
        'transports_disabled':True,'default_rr_preserved':True,
        'explicit_snapshot_modes':[1,0],'snapshot_settings_scope':'owned_fixture_session_only',
        'baseline_source':'a32ec0178b7e1d11a8e8191279b7ef932b93c725',
        'baseline_red_cases':6,'native_redispatches':0,'production_operations':0,
        'source_head':head,'source_head_after':head,'source_unchanged':True,
        'source_clean_at_start':True,'source_clean_at_end':True,
        'runtime_sha256':hashes,'runtime_sha256_after':hashes,
        'bulk_finish_sha256':bulk_hash},'scenarios',expected)
    if proof.get('scenarios')!=expected: errors['scenario_order']='required exact ordered lock cases'
    default=proof.get('default_observation')
    if not (type(default) is dict and type(default.get('server_version')) is str
            and default['server_version'] and type(default.get('snapshot_mode')) is int
            and default['snapshot_mode'] in [0,1] and default.get('tx_isolation')=='REPEATABLE-READ'
            and default.get('sql_error_codes')==[]):
        errors['default_observation']='required actual owned engine/isolation observation'
    observations=proof.get('observations')
    if type(observations) is not list or len(observations)!=len(expected):
        errors['observations']='required complete actual lock observations'
        return errors
    for index,(name,observation) in enumerate(zip(expected,observations)):
        if not (type(observation) is dict and set(observation)=={'scenario','passed','actual'}
                and observation['scenario']==name and observation['passed'] is True
                and type(observation['actual']) is dict):
            errors[name]='required named successful actual observation'
            continue
        actual=observation['actual']
        if index<10:
            probes=actual.get('probes',{});result=actual.get('result',{})
            current=index>=5;phase=index%5
            wanted_first=91 if phase==0 or (not current and phase<4) else 90
            valid=(actual.get('first_order')==wanted_first and result.get('ok') is True
                   and result.get('native_redispatches')==0 and result.get('tx_isolation')=='REPEATABLE-READ')
            for table in ['orders_list','service_effects','setting']:
                probe=probes.get(table,{})
                wanted_ok=current and table=='setting'
                valid=valid and probe.get('ok') is wanted_ok and type(probe.get('code')) is int
                valid=valid and probe.get('code')==(0 if wanted_ok else 1205)
            if not valid: errors[name]='required retained intended locks and unrelated-setting availability'
        elif index in [10,11]:
            direct=actual.get('direct',{});bulk=actual.get('bulk',{})
            valid=(actual.get('verified_native_results_supplied')==2
                   and direct.get('native_redispatches')==0 and bulk.get('native_redispatches')==0
                   and direct.get('snapshot_mode')==1 and bulk.get('snapshot_mode')==1)
            if index==11:
                valid=valid and direct.get('ok') is True and bulk.get('ok') is True
                valid=valid and actual.get('source_state')=='completed' and actual.get('cursor')=='1'
            else:
                valid=valid and (direct.get('code')==1213 or bulk.get('code')==1213)
            if not valid: errors[name]='required actual baseline deadlock or current two completed outcomes'
        else:
            mode=1 if index<16 else 0;material=index%4 in [0,1]
            deferred=(actual.get('ok') is False and actual.get('sql_error_codes')==[1020]
                      and ((actual.get('class')=='mysqli_sql_exception' and actual.get('code')==1020)
                           or (actual.get('class')=='RuntimeException'
                               and actual.get('reason')=='provider_storage_unavailable')))
            rejected=(actual.get('ok') is False and actual.get('reason') in [
                'gift_pg_current_order_changed','gift_pg_current_account_changed',
                'gift_pg_current_identity_changed'])
            valid=(actual.get('native_redispatches')==0 and actual.get('snapshot_mode')==mode
                   and actual.get('tx_isolation')=='REPEATABLE-READ')
            valid=valid and ((rejected or (mode==1 and deferred)) if material
                             else (actual.get('ok') is True or (mode==1 and deferred)))
            if not valid: errors[name]='required material rejection and compatible harmless-metadata handling'
    return errors


def gift_lock_negative_controls(proof: dict, head: str, hashes: dict, bulk_hash: str) -> int:
    if gift_lock_proof_mismatches(proof,head,hashes,bulk_hash):
        raise RuntimeError('Gift lock positive proof admission failed')
    controls=[]
    for key,value in [('native_redispatches',1),('owned_children_cleaned',False),
                      ('snapshot_settings_scope','global'),('bulk_finish_sha256','0'*64)]:
        bad=copy.deepcopy(proof);bad[key]=value;controls.append(bad)
    bad=copy.deepcopy(proof);bad['runtime_sha256_after'].pop('settings/gift2all.php');controls.append(bad)
    bad=copy.deepcopy(proof);bad['observations'][5]['actual']['probes']['setting']['ok']=False;controls.append(bad)
    bad=copy.deepcopy(proof);bad['observations'][11]['actual']['direct']['ok']=False;controls.append(bad)
    bad=copy.deepcopy(proof);bad['explicit_snapshot_modes']=[0,0];controls.append(bad)
    bad=copy.deepcopy(proof);bad['observations'][16]['actual']['ok']=True;controls.append(bad)
    for candidate in controls:
        if not gift_lock_proof_mismatches(candidate,head,hashes,bulk_hash):
            raise RuntimeError('Malformed gift lock proof accepted')
    return len(controls)


def api_native_observation_mismatches(proof: dict, expected: list[str]) -> dict:
    """Require successful named actual-native observations and SQL-free HTTP."""
    observations=proof.get('observations')
    if type(observations) is not list or len(observations)!=len(expected):
        return {'observations':'required one actual observation per named scenario'}
    replay={
        'volume_expired_replay_no_second_write',
        'volume_unreadable_replay_no_second_write',
        'volume_live_replay_no_second_write',
        'volume_slow_replay_no_second_write',
    }
    local_recovery={
        'volume_verified_recovery_after_expired_local_only',
        'renew_verified_recovery_after_unreadable_local_only',
        # The original human callback finishes ONLY the already VERIFIED
        # local mutation journal. Neither a retry nor a blocked proof may
        # perform a provider GET/PUT/reset or a second settlement.
        *[f'manual_{kind}_{phase}' for kind in ('renew','day','volume')
          for phase in ('resume_verified_local_only_once',
                        'repeat_never_reapplies_or_audits')],
        *[f'manual_{kind}_{phase}' for kind in ('renew','day','volume')
          for phase in ('postcommit_before_telegram_staged',
                        'delivery_only_resume_pending_once',
                        'delivery_only_repeat_never_resends')],
        'manual_volume_unknown_customer_ack_not_automatically_resent',
        'manual_day_missing_followup_schema_rolls_back_local_finish',
        'manual_edit_original_callback_delivers_pending_customer_once',
        'manual_edit_unknown_customer_telegram_ack_never_resent',
        'manual_edit_dispatching_original_mirror_no_duplicate',
        'manual_edit_all_acknowledged_no_recovery_or_replay',
        'manual_edit_61st_customer_pending_sent_despite_60_prior_receipts',
        'manual_edit_61st_customer_unknown_no_second_telegram',
        *[f'manual_volume_resume_refuses_{reason}' for reason in (
            'unverified','wrong_key','wrong_quote','wrong_identity',
            'nonmanual_claim')],
        *[f'manual_{kind}_resume_refuses_{reason}'
          for kind in ('renew','day','volume')
          for reason in ('changed_payment_amount','changed_attempt_amount',
                         'missing_original_actor')],
    }
    expected_review_stderr={
        name for name in local_recovery if '_resume_refuses_' in name or name=='manual_day_missing_followup_schema_rolls_back_local_finish'
    }
    expected_notice_stderr={'manual_volume_unknown_customer_ack_not_automatically_resent'}
    # BUY_SUB has no existing native account. Its SQL claim must not
    # contact a provider; only existing-account paths can require native
    # preflight HTTP. Bind these two specific new-buy race observations.
    local_buy_claim={
        'local_server_state_new_buy_1_to_0_after_preflight_before_locked_claim_denied',
        'local_server_state_new_buy_1_to_2_after_preflight_before_locked_claim_denied',
    }
    mismatches={}
    for name,row in zip(expected,observations):
        if (type(row) is not dict or row.get('scenario')!=name or
                row.get('passed') is not True or type(row.get('actual')) is not dict):
            mismatches[name]='required ordered successful actual observation'
            continue
        pending=[row['actual']]
        events=[]
        results=[]
        while pending:
            value=pending.pop()
            if type(value) is dict:
                if value.get('event')=='PG_HTTP':
                    events.append(value)
                if 'events' in value and 'fatal' in value:
                    results.append(value)
                pending.extend(value.values())
            elif type(value) is list:
                pending.extend(value)
        # Expected failed-proof callbacks emit one bounded diagnostic, while
        # other callbacks in the SAME multi-phase observation must remain
        # clean. Enforce the exact number AND content of warning streams,
        # reject any other stderr and all PHP fatals.
        def acceptable_stderr(stderr):
            if stderr=='':
                return True
            if type(stderr) is not str: return False
            if name in expected_notice_stderr:
                return re.fullmatch(
                    r'\[\d{2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2} UTC\] '
                    r'achbot_manual_pg_edit_notice_pending pay_id=[1-9][0-9]* count=[1-9][0-9]*\n',
                    stderr
                ) is not None
            if name not in expected_review_stderr: return False
            return re.fullmatch(
                r'\[\d{2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2} UTC\] '
                r'achbot_manual_pg_edit_recovery_needs_review '
                r'pay_id=[1-9][0-9]* kind=(?:renew|day|volume) actor=[1-9][0-9]* '
                r'reason=(?:manual_edit_recovery|pg|day_extension|service_expiry)_[a-z0-9_]{2,100} '
                r'class=[A-Za-z][A-Za-z0-9_]* code=[0-9]+\n',
                stderr
            ) is not None
        expected_warnings=1 if name in expected_review_stderr|expected_notice_stderr else 0
        actual_warnings=sum(isinstance(value.get('stderr'),str) and
                            bool(value['stderr']) for value in results)
        if (not results or actual_warnings!=expected_warnings or
                any(type(value['events']) is not list or
                    value['fatal'] is not None or
                    not acceptable_stderr(value.get('stderr')) for value in results)):
            mismatches[name+'.php_result']='required clean PHP results and exact bounded diagnostic count'
        if name not in replay|local_recovery|local_buy_claim and not events:
            mismatches[name+'.native_http']='required actual native preflight HTTP'
        if name in local_buy_claim and events:
            mismatches[name+'.new_buy_local_claim']='new buy claim must remain SQL-only'
        if any(type(event.get('tx')) is not int or event['tx']!=0 or
                event.get('method') not in ('GET','PUT','POST') or
                type(event.get('path')) is not str for event in events):
            mismatches[name+'.native_http_context']='required typed HTTP outside SQL'
        if name in replay and any(event.get('method')!='GET' for event in events):
            mismatches[name+'.native_replay']='replay must not repeat native writes'
        if name in local_recovery and events:
            mismatches[name+'.local_recovery']='verified recovery must perform no native HTTP'
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
            "current_additive_migration_separately_tested":False,
            "synthetic_baseinfo_example_current_settings_removed":True,
            "obsolete_private_staff_authority_absent":True,
            "historic_contract_changed":False,"retired_provider_api_removed":True,
            "admin_list_debug_artifact_writer_absent":True,
            "pasarguard_native_implemented":True, "new_xui_implemented":False}


def cron_checks(tmp: Path) -> dict:
    cron_path=Path(tmp)/"post-v9-cron-runner.json"
    subprocess.run(["python3",str(ROOT/"tools/test_post_v9_cron_runner.py"),
                    "--report",str(cron_path)],
                   cwd=ROOT,check=True,timeout=65,capture_output=True)
    cron=json.loads(cron_path.read_text())
    required_cron_cases = {
        "api_payment_maintenance_precedes_payux",
        "api_payment_failure_preserves_payux_finally",
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
        "missing_nested_external_directories_created_private_current_uid",
        "existing_directories_and_lock_reused_without_chmod_or_inode_repair",
        "missing_directory_with_mkdir_disabled_denied_without_creation",
        "valid_existing_directory_runs_with_mkdir_disabled_without_repair",
        "missing_below_world_writable_parent_denied_unchanged",
        "missing_below_symlink_parent_denied_without_target_creation",
        "missing_inside_installation_denied_without_directory_creation",
        "child_broadcast_cannot_create_missing_directories",
        "child_services_cannot_create_missing_directories",
        "cli_include_cannot_autoprovision_missing_directory",
        "http_cannot_autoprovision_missing_directory",
        "five_missing_directory_starts_admit_only_one_batch",
        "independent_bots_share_autocreated_directory_without_global_exclusion",
        "five_first_directory_starts_finish_one_exact_batch",
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
    if not (cron.get("complete") and cron.get("cases")>=95
            and required_cron_cases.issubset(cron.get("scenarios", []))
            and cron.get("actual_current_runner_executed")
            and cron.get("actual_directory_autoprovisioning") is True
            and cron.get("runner_sha256") == hashlib.sha256((ROOT/"settings/cronRunner.php").read_bytes()).hexdigest()
            and cron.get("isolated_child_php_processes")
            and cron.get("actual_api_maintenance_boundary") is True
            and cron.get("api_before_payux") is True
            and cron.get("api_failure_payux_finally") is True
            and cron.get("actual_cron_or_bot_job_executed") is False
            and cron.get("production_database_or_service_modified") is False):
        raise RuntimeError("Optional CLI Cron coordinator native execution assurance incomplete")
    return cron


def current_identity() -> dict:
    """Bind selected feedback to actual bytes, including a dirty development tree."""
    return {
        "head":cmd("rev-parse","HEAD").decode().strip(),
        "tree":cmd("rev-parse","HEAD^{tree}").decode().strip(),
        "dirty":bool(cmd("status","--porcelain")),
        "runtime_sha256":{
            path:hashlib.sha256((ROOT/path).read_bytes()).hexdigest()
            for path in package.members()
        },
        "validation_sha256":{
            path:hashlib.sha256((ROOT/path).read_bytes()).hexdigest()
            for path in ("tools/check_post_v9.py","tools/test_post_v9_cron_runner.py")
        },
    }


def selected_checks(suite: str) -> dict:
    """Selected success is never a complete current/historical acceptance report."""
    if suite not in ("source","cron"):
        raise ValueError("Unknown selected validation suite: "+suite)
    started=time.monotonic()
    before=current_identity()
    source=source_checks()
    source["current_additive_migration_separately_tested"]=False
    timings={"source_seconds":time.monotonic()-started}
    executed=["source"]
    omitted=["full_current_acceptance","current_domain_native_suites",
             "additive_migration_execution","source_overlay_execution",
             "frozen_historical_execution"]
    report={
        "complete":False,"targeted_complete":True,"scope":suite,
        "executed":executed,"omitted":omitted,"identity":before,
        "source":source,"timings":timings,
        "historical_suite_executed_by_this_script":False,
        "actual_operator_installed_acceptance":False,
        "real_financial_safety_approved":False,
    }
    if suite=="cron":
        cron_started=time.monotonic()
        with tempfile.TemporaryDirectory(prefix="achbot-selected-cron-") as tmp:
            report["optional_cron_runner"]=cron_checks(Path(tmp))
        timings["cron_seconds"]=time.monotonic()-cron_started
        executed.append("cron")
    else:
        omitted.append("optional_cron_runner")
    if current_identity()!=before:
        raise RuntimeError("Validation inputs changed during selected checks")
    timings["total_seconds"]=time.monotonic()-started
    return report


def main(argv=None):
    parser=argparse.ArgumentParser()
    parser.add_argument("--report",type=Path)
    parser.add_argument("--suite",choices=("full","source","cron"),default="full")
    a=parser.parse_args(argv)
    if a.suite=="full":
        full_checks(a,source_checks())
        return
    report=selected_checks(a.suite)
    if a.report:
        a.report.write_text(json.dumps(report,indent=2,ensure_ascii=False)+"\n")
    print(json.dumps(report))


def full_checks(a, result):
    """Current PHP8.5 full acceptance uses the same explicit eight-family
    native catalog as parallel CI; no duplicate monolithic test driver.
    """
    import post_v9_shards
    return post_v9_shards.full_checks(a, result)


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
