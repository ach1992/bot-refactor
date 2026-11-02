#!/usr/bin/env python3
"""Issue #107 additive SQL and scoped pricing guards on disposable socket MariaDB.

--sql-only: migration/unique/legacy ID regression, no PHP.
--run-disposable: same plus actual current-runtime PHP 8.5 quote, catalogue
and immutable financial guard checks. Never calls live providers or Telegram.
"""
from __future__ import annotations
import argparse
import hashlib
import json
import subprocess
import time
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
import test_access_state as owned
import test_v6_native as native
import test_v8_native as purchase
import test_post_v9_service_unit_guards as unit_routes
import test_post_v9_pasarguard_client as pg
import test_post_v9_finance_routes as hosted_routes
import test_post_v9_purchases as source_stages

ROOT=Path(__file__).resolve().parents[1]
DB=purchase.SIDE[1]
PHP=r'''<?php
error_reporting(E_ALL);
set_error_handler(static function($n,$m){throw new ErrorException($m,0,$n);});
if (PHP_VERSION_ID < 80500 || PHP_VERSION_ID >= 80600) throw new RuntimeException('requires_php85');
$_SERVER['SCRIPT_FILENAME']=__FILE__;
define('ACHBOT_PG_TEST_LOOPBACK',true);
require_once $argv[1].'/src/Purchase/Core.php';
require_once $argv[1].'/src/Plans/Addons.php';
$spec=json_decode($argv[4],true,64,JSON_THROW_ON_ERROR);
$db=new mysqli('localhost','root','',$spec['database']??$argv[3],0,$argv[2]);
$db->set_charset('utf8mb4');
$context=['server_id'=>(int)($spec['server']??2),'provider'=>$spec['provider']??'marzban',
 'groups'=>$spec['groups']??[],'pg_groups_checked'=>$spec['eligible_groups']??($spec['groups']??[])];
try {
switch($spec['op']) {
case 'schema': $value=achbotAddonSchema($db);break;
case 'groups':
  require_once $argv[1].'/src/Panel/PasarGuard.php';
  $value=achbotPgEligibleGroupIds($spec['check']);break;
case 'pg_http':
  require_once $argv[1].'/src/Panel/PasarGuard.php';
  $panel=['id'=>1,'instance_key'=>str_repeat('b',32),'api_url'=>$spec['url'],
    'api_key'=>'fixture-private-key','version'=>'5.5.1'];
  $check=achbotPgCheck($panel);
  $read=achbotPgRead($panel,$spec['identity']);
  $value=['eligible'=>($check['success']??false)?
    achbotPgEligibleGroupIds($check):null,
    'check_success'=>$check['success'],'read_success'=>$read['success'],
    'native_groups'=>$read['user']['group_ids']??null];break;
case 'catalog': $value=achbotAddonCatalog($db,$spec['kind']??'volume',$context);break;
case 'quote':
  $order=['id'=>41,'server_id'=>2];$context['order_id']=41;
  $context['remark']='owned-native-fixture';$context['identity']='identity-41';
  $value=achbotAddonQuote($order,$context,'volume',$spec['offer'],(int)$spec['final_price']);break;
case 'local':
case 'eligible':
case 'draft':
  $q=$db->prepare("SELECT * FROM pays WHERE hash_id=?");
  $q->bind_param('s',$spec['hash']);$q->execute();$pay=$q->get_result()->fetch_assoc();$q->close();
  $q=$db->prepare("SELECT * FROM orders_list WHERE id=41");
  $q->execute();$order=$q->get_result()->fetch_assoc();$q->close();
  $context['order_id']=41;$context['remark']='owned-native-fixture';
  $context['identity']='identity-41';
  $value=$spec['op']==='local'?achbotAddonPayLocal($db,$pay,$order):
        ($spec['op']==='eligible'?achbotAddonPayEligible($db,$pay,$order,$context):
           achbotAddonDraftCurrent($db,(int)$pay['id']));
  break;
case 'row':
  $q=$db->prepare('SELECT * FROM increase_plan WHERE id=?');$q->bind_param('i',$spec['offer_id']);
  $q->execute();$row=$q->get_result()->fetch_assoc();$q->close();
  $value=achbotAddonScopeRow($row,true);break;
default:throw new RuntimeException('invalid_fixture_op');
}
echo json_encode(['ok'=>true,'value'=>$value],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
echo json_encode(['ok'=>false,'reason'=>get_class($e).':'.$e->getMessage()],JSON_THROW_ON_ERROR);
}
'''
def sha(text:str)->str:return hashlib.sha256(text.encode()).hexdigest()

def test(php:bool)->dict:
    checks=[]
    def yes(ok:bool,label:str):
        if not ok:raise AssertionError(label)
        checks.append(label)
    with owned.owned_database() as (w,e):
        purchase.init(w,e,'http://127.0.0.1:9')
        def q(sql:str):return native.sql(w,e,sql,DB).strip()
        # This test never accesses installed databases; owned_database binds an
        # isolated socket under a disposable temporary directory.
        q("""INSERT INTO increase_day(id,volume,price) VALUES(101,20,1000);
              INSERT INTO increase_plan(id,volume,price) VALUES(201,10,100);""")
        before=q('SELECT id,volume,price FROM increase_day ORDER BY id; SELECT id,volume,price FROM increase_plan ORDER BY id')
        q((ROOT/'migrations/post-v9/015_scoped_addons.sql').read_text())
        yes(q('SELECT id,volume,price FROM increase_day ORDER BY id; SELECT id,volume,price FROM increase_plan ORDER BY id')==before,
            'migration_preserves_global_offer_ids_units_prices')
        yes(q("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() "
              "AND TABLE_NAME='pays' AND COLUMN_NAME='addon_scope_snapshot'")=='1',
            'migration_installs_immutable_quote_column')
        for kind,offer,scope,price in [
            ('volume',301,'S:2',120),('volume',302,'P:2:1',130),
            ('volume',303,'P:2:1,2',150),('volume',304,'S:3',160),
            ('day',401,'S:2',1100)]:
            table='increase_plan' if kind=='volume' else 'increase_day'
            server=int(scope.split(':')[1])
            groups='NULL' if scope.startswith('S:') else "'[" +','.join(scope.split(':')[2].split(','))+"]'"
            q(f"INSERT INTO {table}(id,volume,price,server_id,group_ids,scope_hash) "
              f"VALUES({offer},{10 if kind=='volume' else 20},{price},{server},{groups},'{sha(scope)}')")
        yes(q("SELECT COUNT(*) FROM increase_plan WHERE volume=10")=='5',
            'global_and_scoped_identical_units_coexist')
        def race_insert(_):
            try:
                native.sql(w,e,"INSERT INTO increase_plan(volume,price,server_id,scope_hash)"
                    f" VALUES(777,42,2,'{sha('S:2')}')",DB)
                return True
            except Exception:return False
        with ThreadPoolExecutor(max_workers=2) as pool:
            claims=list(pool.map(race_insert,range(2)))
        yes(claims.count(True)==1 and q("SELECT COUNT(*) FROM increase_plan "
                "WHERE volume=777 AND server_id=2")=='1',
            'concurrent_duplicate_scoped_offer_insert_one_unique_winner')
        # The concurrency case is not a customer offer fixture. Keep the
        # subsequent exact per-quantity catalog assertions independent.
        q("DELETE FROM increase_plan WHERE volume=777 AND server_id=2")
        yes(q("SELECT COUNT(*) FROM increase_plan WHERE volume=777")=='0',
            'concurrent_offer_fixture_isolation_restored')
        try:
            q("INSERT INTO increase_plan(volume,price,server_id,scope_hash) "
              f"VALUES(10,121,2,'{sha('S:2')}')")
        except Exception:checks.append('duplicate_unit_scope_rejected_by_db')
        else:raise AssertionError('duplicate_unit_scope_accepted')
        # Reapplying migration is deliberately blocked before any DDL.
        try:q((ROOT/'migrations/post-v9/015_scoped_addons.sql').read_text())
        except Exception:checks.append('repeated_migration_rejected')
        else:raise AssertionError('repeated_migration_accepted')
        if php:
            detected=int(subprocess.check_output(['php','-r','echo PHP_VERSION_ID;'],text=True))
            yes(80500<=detected<80600,'php85_native_only')
            driver=w/'scoped-addon.php';driver.write_text(PHP)
            def call(**spec):
                result=subprocess.run(['php','-d','display_errors=0',str(driver),str(ROOT),
                  str(w/'mysql.sock'),DB,json.dumps(spec)],capture_output=True,text=True,timeout=15)
                if result.returncode or result.stderr:raise RuntimeError(
                    f'fixture error {spec}: {result.returncode}, {result.stderr}, {result.stdout}')
                return json.loads(result.stdout)
            # Migration readiness must reject drift in the actual database
            # protection, not just check that columns happen to exist.
            yes(call(op='schema',database=purchase.SIDE[0])=={'ok':True,'value':False},
                'unmigrated_legacy_schema_remains_global_compatible')
            yes(call(op='schema')=={'ok':True,'value':True},
                'complete_015_schema_indexes_and_engine_ready')
            for table,key in [('increase_day','uq_addon_day_units_scope'),
                              ('increase_plan','uq_addon_volume_units_scope')]:
                q(f"ALTER TABLE {table} DROP INDEX {key}")
                yes(call(op='schema')['ok'] is False,
                    table+'_missing_unique_index_rejected')
                q(f"ALTER TABLE {table} ADD INDEX {key} (volume,scope_hash)")
                yes(call(op='schema')['ok'] is False,
                    table+'_nonunique_replacement_rejected')
                q(f"ALTER TABLE {table} DROP INDEX {key}")
                q(f"ALTER TABLE {table} ADD UNIQUE KEY {key} (scope_hash,volume)")
                yes(call(op='schema')['ok'] is False,
                    table+'_wrong_index_order_rejected')
                q(f"ALTER TABLE {table} DROP INDEX {key}")
                q(f"ALTER TABLE {table} ADD UNIQUE KEY {key} (volume,scope_hash)")
            q('ALTER TABLE increase_day ENGINE=MyISAM')
            yes(call(op='schema')['ok'] is False,
                'nontransactional_catalog_engine_rejected')
            q('ALTER TABLE increase_day ENGINE=InnoDB')
            q('ALTER TABLE pays DROP COLUMN addon_scope_snapshot')
            yes(call(op='schema')['ok'] is False,
                'interrupted_015_payment_column_rejected')
            q('ALTER TABLE pays ADD COLUMN addon_scope_snapshot LONGTEXT COLLATE utf8mb4_bin DEFAULT NULL')
            yes(call(op='schema')=={'ok':True,'value':True},
                'restored_015_schema_ready_after_disposable_repair')
            def pg_groups(allowed,disabled=None):
                return call(op='groups',check={'success':True,'allowed_group_ids':allowed,
                    'groups':[{'id':i,'is_disabled':i in (disabled or [])} for i in (1,2,3)]})
            yes(pg_groups([1,2])=={'ok':True,'value':[1,2]},
                'native_group_authority_enforces_allowed_subset')
            yes(pg_groups([2,3],[2])=={'ok':True,'value':[3]},
                'native_group_authority_enforces_active_and_allowed')
            yes(pg_groups([])=={'ok':True,'value':[]},
                'native_group_authority_empty_allowed_is_not_unrestricted')
            yes(pg_groups(None)=={'ok':True,'value':[1,2,3]},
                'native_group_authority_null_unrestricted')
            yes(pg_groups('invalid')['ok'] is False,
                'malformed_group_permissions_fail_closed')
            # Real current PasarGuard HTTP adapter, but an owned local-only
            # server. This also proves native account identity readback.
            with pg.panel() as (server,url):
                def provider_check():
                    return call(op='pg_http',url=url,identity=pg.IDENTITY)
                proof=provider_check()
                yes(proof['ok'] and proof['value']=={
                    'eligible':[2,5],'check_success':True,'read_success':True,
                    'native_groups':[2,5]},
                    'local_pasarguard_http_readback_checks_native_identity_and_groups')
                server.allowed_group_ids=[5]
                proof=provider_check()
                yes(proof['ok'] and proof['value']['eligible']==[5] and
                    proof['value']['native_groups']==[2,5],
                    'current_http_allowed_groups_override_still_active_native_groups')
                server.disabled_group_ids=[5]
                yes(provider_check()['value']['eligible']==[],
                    'current_http_disabled_allowed_group_is_rejected')
                server.available_group_ids=[5]
                yes(provider_check()['value']['eligible']==[],
                    'current_http_deleted_group_fails_current_authority')
                yes(server.write_count==0 and all(t[0]=='GET' for t in server.trace),
                    'group_authority_and_identity_readback_never_dispatch_provider_writes')
            def ids(**spec):
                ret=call(op='catalog',kind='volume',**spec)
                yes(ret['ok'],'catalog_fail_'+str(spec))
                return {int(row['volume']):int(row['id']) for row in ret['value']}
            yes(ids(server=2,provider='marzban')=={10:301},'marzban_server_scope_beats_global')
            yes(ids(server=2,provider='pasarguard',groups=[1])=={10:302},
                'native_group_x_beats_server')
            yes(ids(server=2,provider='pasarguard',groups=[1,2])=={10:303},
                'exact_multi_group_beats_single_group')
            yes(ids(server=2,provider='pasarguard',groups=[3])=={10:301},
                'other_group_falls_back_to_server')
            yes(ids(server=3,provider='pasarguard',groups=[1])=={10:304},
                'another_server_never_inherits_group_price')
            yes(ids(server=4,provider='marzban')=={10:201},
                'unconfigured_server_inherits_global')
            yes(ids(server=2,provider='pasarguard',groups=[1],eligible_groups=[])=={10:301},
                'deleted_native_group_uses_safe_server_fallback')
            yes(ids(server=2,provider='pasarguard',groups=[1],eligible_groups=[2])=={10:301},
                'active_but_credential_disallowed_group_uses_safe_server_fallback')
            for offer,scope in [(201,{'server_id':None,'groups':None,'scope_hash':sha('G')}),
                                (303,{'server_id':2,'groups':[1,2],'scope_hash':sha('P:2:1,2')})]:
                check=call(op='row',offer_id=offer)
                yes(check['ok'] and check['value']==scope,'canonical_scope_row_'+str(offer))
            ctx={'version':1,'kind':'volume','offer_id':303,'order_id':41,'server_id':2,
                 'provider':'pasarguard','remark':'owned-native-fixture','native_identity':'identity-41',
                 'native_groups':[1,2],'scope_hash':sha('P:2:1,2'),'units':10,
                 'base_price':150,'final_price':135}
            q("INSERT INTO orders_list(id,userid,token,transid,fileid,server_id,inbound_id,remark,expire_date,link,amount,status,date) "
              "VALUES (41,1001,'','','7',2,0,'owned-native-fixture',2147483647,'[]',1,1,'fixture')")
            snap=json.dumps(ctx,separators=(',',':'))
            q("UPDATE server_config SET type='pasarguard' WHERE id=2")
            # SQL insertion uses fully controlled fixture bytes only.
            q("INSERT INTO pays(hash_id,user_id,type,plan_id,server_id,order_id,volume,day,price,request_date,state,addon_scope_snapshot) "
              "VALUES ('scoped-fixture',1001,'INCREASE_VOLUME_41_303',7,2,41,10,0,135,123,'pending',"
              "'"+snap+"')")
            yes(call(op='local',hash='scoped-fixture')['value'] is True,
                'exact_order_server_offer_units_price_snapshot_local')
            yes(call(op='eligible',hash='scoped-fixture',server=2,provider='pasarguard',groups=[1,2])['value'] is True,
                'same_native_group_set_eligible')
            yes(call(op='eligible',hash='scoped-fixture',server=2,provider='pasarguard',groups=[1])['value'] is False,
                'changed_native_group_set_rejected')
            yes(call(op='eligible',hash='scoped-fixture',server=3,provider='pasarguard',groups=[1,2])['value'] is False,
                'changed_logical_server_rejected')
            yes(call(op='draft',hash='scoped-fixture')['value'] is True,
                'draft_current_base_price_consistent')
            q("UPDATE increase_plan SET price=999 WHERE id=303")
            yes(call(op='local',hash='scoped-fixture')['value'] is True,
                'issued_price_immutable_after_admin_price_change')
            yes(call(op='draft',hash='scoped-fixture')['value'] is False,
                'new_quote_issuance_rejects_admin_price_race')
            q("UPDATE increase_plan SET scope_hash='"+sha('P:2:3')+"',group_ids='[3]' WHERE id=303")
            yes(call(op='local',hash='scoped-fixture')['value'] is False,
                'reassigned_offer_scope_rejected_for_pending_quote')

            # Source-real customer routes and admin Telegram wizard, on the
            # same isolated SQL fixture and a Marzban read-only test adapter.
            # The fixture has zero real provider/Telegram/payment transports.
            q("UPDATE server_config SET type='marzban' WHERE id=2")
            actor_driver=w/'scoped-addon-telegram.php'
            actor_driver.write_text(unit_routes.PHP)
            def actor(**spec):
                # This fixture deliberately produces local simulation events;
                # every invocation uses the exact current PHP route source.
                frame=spec.pop('frame',None)
                if frame is not None:spec['frame']=frame
                result=subprocess.run(['php','-d','display_errors=0','-d','log_errors=0',
                    str(actor_driver),str(ROOT),str(w/'mysql.sock'),DB,json.dumps(spec)],
                    text=True,capture_output=True,timeout=20)
                if result.returncode or result.stderr:raise RuntimeError(
                    f'current route failed {spec}: {result.returncode} {result.stderr} {result.stdout}')
                return json.loads(result.stdout)
            def key(events,prefix):
                for event in events:
                    for line in (event.get('keys') or {}).get('inline_keyboard',[]):
                        for button in line:
                            data=button.get('callback_data','')
                            if data.startswith(prefix):
                                return data
                raise AssertionError('no_callback_'+prefix)
            def callback(data,actor_id=9001,seq=801,route='admin'):
                return actor(route=route,actor=actor_id,data=data,frame={
                    'update_id':seq,'callback_query':{'id':'testcallback','data':data,
                    'from':{'id':actor_id},'message':{'message_id':701,
                    'chat':{'id':actor_id,'type':'private'}}}})
            def message(text,actor_id=9001,seq=804,route='admin'):
                return actor(route=route,actor=actor_id,data='',text=text,frame={
                    'update_id':seq,'message':{'message_id':702,'text':text,
                    'from':{'id':actor_id},'chat':{'id':actor_id,'type':'private'}}})
            listing=callback('increaseAVolume41',actor_id=1001,route='extensions')
            events=json.dumps(listing['events'])
            yes('increaseVolumePlan41_301' in events and
                'increaseVolumePlan41_201' not in events and
                'increaseVolumePlan41_302' not in events,
                'owned_customer_sees_server_offer_not_global_or_wrong_provider_group')
            qbefore=q("SELECT COUNT(*) FROM pays WHERE type='INCREASE_VOLUME_41_201'")
            deny=callback('increaseVolumePlan41_201',actor_id=1001,route='extensions')
            yes(q("SELECT COUNT(*) FROM pays WHERE type='INCREASE_VOLUME_41_201'")==qbefore
                and deny['provider_writes']==0,
                'forged_global_offer_selection_has_zero_financial_effect')
            issued=callback('increaseVolumePlan41_301',actor_id=1001,route='extensions')
            yes(q("SELECT COUNT(*) FROM pays WHERE type='INCREASE_VOLUME_41_301'")=='1'
                and issued['provider_writes']==0,
                'customer_checkout_issues_server_scoped_quote_with_no_remote_write')
            pinned=q("SELECT CONCAT(volume,':',day,':',price,':',IF(addon_scope_snapshot IS NULL,'0','1')) "
                "FROM pays WHERE type='INCREASE_VOLUME_41_301' ORDER BY id DESC LIMIT 1")
            yes(pinned=='10:0:120:1','new_customer_quote_pins_units_amount_and_scope')

            # The same issued quote must reach the real shared financial
            # subject validator used by wallet, hosted, manual and API paths.
            # These checks do not debit or call a provider; they verify the
            # exact subject gate against the installed scoped SQL fields.
            scoped_hash=q("SELECT hash_id FROM pays WHERE type='INCREASE_VOLUME_41_301' "
                "ORDER BY id DESC LIMIT 1")
            subject=actor(route='subject',hash=scoped_hash)
            yes(any(ev.get('event')=='SUBJECT' and ev.get('value') is True
                for ev in subject['events']) and subject['provider_writes']==0,
                'scoped_customer_quote_passes_shared_finance_subject_gate')
            q("UPDATE increase_plan SET server_id=4,scope_hash='"+sha('S:4')+"' WHERE id=301")
            stale_subject=actor(route='subject',hash=scoped_hash)
            yes(any(ev.get('event')=='SUBJECT' and ev.get('value') is False
                for ev in stale_subject['events']) and stale_subject['provider_writes']==0,
                'shared_finance_subject_rejects_reassigned_server_scope')
            q("UPDATE increase_plan SET server_id=2,scope_hash='"+sha('S:2')+"' WHERE id=301")
            restored_subject=actor(route='subject',hash=scoped_hash)
            yes(any(ev.get('event')=='SUBJECT' and ev.get('value') is True
                for ev in restored_subject['events']),
                'shared_finance_subject_accepts_only_restored_original_scope')

            # Stop users from forging an administrative scope edit.
            pre=q('SELECT COUNT(*) FROM increase_day')
            callback('addonScopeGlobal',actor_id=1001)
            yes(q('SELECT COUNT(*) FROM increase_day')==pre,
                'customer_cannot_replay_admin_scope_callback')
            first=callback('addNewDayPlan')
            yes(q("SELECT step FROM users WHERE userid=9001")=='addon:scope'
                and 'addonScopeServer' in json.dumps(first['events']),
                'admin_wizard_starts_with_scope_selection')
            blocked_filter=callback('addonFilterDayGlobal',seq=802)
            yes(q("SELECT step FROM users WHERE userid=9001")=='addon:scope' and
                'deleteDayPlan101' not in json.dumps(blocked_filter['events']) and
                any('ویرایش فعال' in (ev.get('text') or '') for ev in blocked_filter['events']) and
                blocked_filter['provider_writes']==0,
                'read_only_filter_prompts_to_finish_active_editor_without_mutation')
            server_choose=callback(key(first['events'],'addonScopeServer_'),seq=802)
            yes(q("SELECT step FROM users WHERE userid=9001")=='addon:server',
                'admin_wizard_server_selection')
            old_picker=key(server_choose['events'],'addonPickServer2_')
            callback(old_picker,seq=803)
            yes(q("SELECT step FROM users WHERE userid=9001")=='addon:units',
                'admin_wizard_persists_verified_server_choice')
            stale=message('15-1234',seq=803)
            yes(q("SELECT COUNT(*) FROM increase_day WHERE volume=15")=='0'
                and q("SELECT step FROM users WHERE userid=9001")=='addon:units',
                'replayed_telegram_update_cannot_add_scoped_price')
            final=message('15-1234',seq=804)
            yes(q("SELECT COUNT(*) FROM increase_day WHERE volume=15 AND price=1234 "
                  "AND server_id=2 AND group_ids IS NULL AND scope_hash='"+sha('S:2')+"'")=='1'
                and q("SELECT step FROM users WHERE userid=9001")=='none',
                'admin_wizard_persists_server_scoped_day_offer_and_clears_editor')
            customer_days=callback('increaseADay41',actor_id=1001,route='extensions')
            yes('selectPlanDayIncrease41_' in json.dumps(customer_days['events'])
                and customer_days['provider_writes']==0,
                'customer_day_menu_uses_scoped_catalogue_without_remote_edit')
            yes(any('inline_keyboard' in ev.get('keys',{}) for ev in final['events']) and
                not any('تغییر مالی اعمال نشده' in (ev.get('text') or '') for ev in final['events']),
                'successful_scoped_offer_save_renders_list_without_false_failure')
            yes(final['provider_writes']==0 and stale['provider_writes']==0,
                'admin_and_stale_telegram_frames_have_zero_provider_writes')

            # Read-only list filters for BOTH kinds; no stale global offer
            # or other server is exposed in a selected server's admin list.
            q("INSERT INTO increase_day (id,volume,price,server_id,group_ids,scope_hash) "
              "VALUES (503,15,1400,3,NULL,'"+sha('S:3')+"')")
            filter_picker=callback('addonFilterDayServers',seq=805)
            yes('addonFilterDayServer2' in json.dumps(filter_picker['events']) and
                'addonFilterDayAll' in json.dumps(filter_picker['events']),
                'admin_can_choose_server_filter_for_day_offers')
            filtered_day=callback('addonFilterDayServer2',seq=806)
            filtered_text=json.dumps(filtered_day['events'])
            yes('deleteDayPlan401' in filtered_text and 'deleteDayPlan101' not in filtered_text and
                'deleteDayPlan503' not in filtered_text and
                'addonFilterDayGlobal' in filtered_text,
                'admin_server_filter_shows_only_this_servers_day_scopes')
            global_day=callback('addonFilterDayGlobal',seq=807)
            global_text=json.dumps(global_day['events'])
            yes('deleteDayPlan101' in global_text and 'deleteDayPlan401' not in global_text and
                'deleteDayPlan503' not in global_text,
                'admin_global_day_filter_excludes_server_scopes')
            all_day=callback('addonFilterDayAll',seq=808)
            all_text=json.dumps(all_day['events'])
            yes('deleteDayPlan101' in all_text and 'deleteDayPlan401' in all_text and
                'deleteDayPlan503' in all_text,
                'admin_all_day_filter_restores_both_scope_classes')
            filtered_volume=callback('addonFilterVolumeServer2',seq=809)
            vol_text=json.dumps(filtered_volume['events'])
            yes('deleteVolumePlan301' in vol_text and 'deleteVolumePlan302' in vol_text and
                'deleteVolumePlan201' not in vol_text and
                'addonFilterVolumeAll' in vol_text,
                'admin_server_volume_filter_includes_scoped_excludes_global')
            rejected_filter=callback('addonFilterDayServer2',actor_id=1001,seq=810)
            yes('deleteDayPlan401' not in json.dumps(rejected_filter['events']) and
                rejected_filter['provider_writes']==0,
                'customer_cannot_use_read_only_admin_scope_filter')
            # Old keyboards are not current editor sessions, even with a NEW
            # Telegram update_id and exactly the same editor step.
            newer=callback('addNewVolumePlan',seq=811)
            new_scope=key(newer['events'],'addonScopeServer_')
            stale_scope=callback(key(first['events'],'addonScopeServer_'),seq=812)
            yes(q("SELECT step FROM users WHERE userid=9001")=='addon:scope' and
                not any('addonPickServer' in json.dumps(e) for e in stale_scope['events']),
                'old_scope_prompt_new_update_does_not_advance_new_session')
            stale_group=callback(key(first['events'],'addonScopeGroups_'),seq=813)
            yes(q("SELECT step FROM users WHERE userid=9001")=='addon:scope' and
                not any('addonPickServer' in json.dumps(e) for e in stale_group['events']),
                'old_group_scope_prompt_cannot_change_new_editor')
            new_servers=callback(new_scope,seq=814)
            stale_picker=callback(old_picker,seq=815)
            yes(q("SELECT step FROM users WHERE userid=9001")=='addon:server' and
                not any('addon_scopes.units_price' in json.dumps(e) for e in stale_picker['events']),
                'old_server_picker_new_update_does_not_select_new_offer')
            callback(key(new_servers['events'],'addonPickServer2_'),seq=816)
            yes(q("SELECT step FROM users WHERE userid=9001")=='addon:units',
                'current_session_server_picker_still_works')
            message('Cancel',seq=817)
            yes(q("SELECT step FROM users WHERE userid=9001")=='none',
                'cancel_current_session_clears_editor')

            # Row-level CAS is independent of editor-session protection.
            # Another administrator can change the visible offer between
            # rendering and a subsequent Telegram update.
            old_delete=key(filtered_day['events'],'deleteDayPlan401_')
            old_price=key(filtered_day['events'],'changeDayPlanPrice401_')
            q("UPDATE increase_day SET server_id=4,scope_hash='"+sha('S:4')+"' WHERE id=401")
            stale_delete=callback(old_delete,seq=818)
            yes(q("SELECT COUNT(*) FROM increase_day WHERE id=401 AND server_id=4")=='1',
                'stale_day_delete_after_rescope_cannot_remove_new_offer')
            stale_start=callback(old_price,seq=819)
            yes(q("SELECT step FROM users WHERE userid=9001")=='none',
                'stale_day_price_button_after_server_change_cannot_start_edit')
            q("UPDATE increase_day SET server_id=2,scope_hash='"+sha('S:2')+"' WHERE id=401")
            fresh_view=callback('addonFilterDayServer2',seq=820)
            fresh_price=key(fresh_view['events'],'changeDayPlanPrice401_')
            callback(fresh_price,seq=821)
            q("UPDATE increase_day SET server_id=4,scope_hash='"+sha('S:4')+"' WHERE id=401")
            typed=message('5555',seq=822)
            yes(q("SELECT price FROM increase_day WHERE id=401")=='1100' and
                q("SELECT step FROM users WHERE userid=9001")!='none',
                'stale_day_price_completion_does_not_overwrite_changed_scope')
            message('Cancel',seq=823)
            q("UPDATE increase_day SET server_id=2,scope_hash='"+sha('S:2')+"' WHERE id=401")
            fresh_day=callback('addonFilterDayServer2',seq=824)
            callback(key(fresh_day['events'],'changeDayPlanPrice401_'),seq=825)
            message('5555',seq=826)
            yes(q("SELECT price FROM increase_day WHERE id=401")=='5555',
                'fresh_day_price_edit_updates_exact_current_revision')
            fresh_day=callback('addonFilterDayServer2',seq=827)
            callback(key(fresh_day['events'],'deleteDayPlan401_'),seq=828)
            yes(q("SELECT COUNT(*) FROM increase_day WHERE id=401")=='0',
                'fresh_day_delete_removes_only_current_revision')
            fresh_volume=callback('addonFilterVolumeServer2',seq=829)
            volume_update=key(fresh_volume['events'],'changeVolumePlanVolume301_')
            callback(volume_update,seq=830)
            q("UPDATE increase_plan SET price=777 WHERE id=301")
            message('22',seq=831)
            yes(q("SELECT volume FROM increase_plan WHERE id=301")=='10',
                'stale_volume_quantity_after_offer_repricing_is_refused')
            message('Cancel',seq=832)
            fresh_volume=callback('addonFilterVolumeServer2',seq=833)
            callback(key(fresh_volume['events'],'changeVolumePlanVolume301_'),seq=834)
            message('22',seq=835)
            yes(q("SELECT volume FROM increase_plan WHERE id=301")=='22',
                'fresh_volume_quantity_edit_succeeds')
            fresh_volume=callback('addonFilterVolumeServer2',seq=836)
            callback(key(fresh_volume['events'],'deleteVolumePlan301_'),seq=837)
            yes(q("SELECT COUNT(*) FROM increase_plan WHERE id=301")=='0',
                'fresh_volume_delete_succeeds')
            # Another administrator can reassign a native Group-set between
            # message rendering and a stale deletion, without touching the ID.
            old_group_delete=key(filtered_volume['events'],'deleteVolumePlan302_')
            q("UPDATE increase_plan SET group_ids='[9]',scope_hash='"+sha('P:2:9')+"' "
                "WHERE id=302")
            rejected_group=callback(old_group_delete,seq=838)
            yes(q("SELECT COUNT(*) FROM increase_plan WHERE id=302 AND group_ids='[9]'")=='1' and
                rejected_group['provider_writes']==0,
                'stale_group_set_delete_after_other_admin_reassignment_rejected')
            q("UPDATE increase_plan SET group_ids='[1]',scope_hash='"+sha('P:2:1')+"' "
                "WHERE id=302")
            # Full current financial route: real owner/claim/debit/payment and
            # audit/terminal SQL, real common provider-dispatch guard, fake ONLY
            # the final Marzban HTTP adapter. No network is reachable.
            # Never substitute a green eligibility-only check for this effect.
            stub="function achbotApplyServiceEdit(...$args){++$GLOBALS['writes'];throw new RuntimeException('forbidden_provider_effect');}"
            adapter=r'''function editMarzbanConfig($sid,array $change,$gift=null){
  ++$GLOBALS['writes'];
  global $connection;
  if (!achbotServiceEditMatchesPay($connection,(int)($change['pay_id']??0),$change))
    throw new RuntimeException('owned_effect_quote_mismatch');
  if (($GLOBALS['spec']['remote_mode']??'ok')==='lost_ack')
    throw new RuntimeException('owned_remote_ack_lost');
  $s=$connection->prepare('SELECT expire_date FROM orders_list WHERE server_id=? AND remark=?');
  $remark=$change['remark'];$s->bind_param('is',$sid,$remark);$s->execute();
  $row=$s->get_result()->fetch_assoc();$s->close();
  if (!$row) throw new RuntimeException('owned_remote_subject_missing');
  $days=(int)($change['plus_day']??0);$volume=(int)($change['plus_volume']??0);
  return (object)['success'=>true,'verified'=>true,'uncertain'=>false,
    'applied_expire'=>(int)$row['expire_date']+$days*86400,
    'applied_bytes'=>2147483648+$volume*1073741824];
}'''
            yes(stub in unit_routes.PHP,'current_finance_adapter_spy_contract_identified')
            settle_driver=w/'scoped-addon-settlement.php'
            strict=unit_routes.PHP.replace(
                'if($n===E_DEPRECATED)return true;','')
            delivery_spy='''function editKeys(...$args){$GLOBALS['events'][]=['event'=>'DELIVERY_KEYS'];}
function achbotNotifyAdmins(...$args){$GLOBALS['events'][]=['event'=>'ADMIN_NOTICE'];}'''
            settle_driver.write_text(strict.replace(stub,adapter+"\n"+delivery_spy))
            def settle(kind,method,key_name,mode='ok'):
                cb=('payIncrease'+('Day' if kind=='day' else '')+'WithWallet'
                    if method=='wallet' and kind=='day' else
                    'payIncreaseWithWallet' if method=='wallet' else
                    'approveIncrease'+('Day' if kind=='day' else 'Volume'))+key_name
                who=1001 if method=='wallet' else 9001
                spec={'route':'extensions','actor':who,'data':cb,'remote_mode':mode,
                    'frame':{'update_id':1000,'callback_query':{
                        'id':'settle-fixture','data':cb,'from':{'id':who},
                        'message':{'message_id':721,'chat':{'id':who,'type':'private'}}}}}
                proc=subprocess.run(['php','-d','display_errors=0','-d','log_errors=0',
                    '-d','disable_functions=curl_init,curl_exec',
                    str(settle_driver),str(ROOT),str(w/'mysql.sock'),DB,
                    json.dumps(spec)],capture_output=True,text=True,timeout=30)
                if proc.returncode or proc.stderr:
                    raise AssertionError(('scoped_finance_route_php85',proc.returncode,
                        proc.stderr[-1200:],proc.stdout[-1200:]))
                return json.loads(proc.stdout)
            q((ROOT/'migrations/post-v9/003_payment_attempts.sql').read_text())
            # Financial callbacks do not trust preview-only $botState. The
            # definitive current policy is the owner-locked, unique persisted
            # BOT_STATES row. Seed it explicitly on the disposable database.
            finance_states={
                'walletState':'on','cartToCartState':'on',
                'giftCardState':'on','manualUsdtState':'on',
                'nowPaymentOther':'on','nowPaymentWallet':'on',
                'nextpay':'on','zarinpal':'on',
            }
            q("INSERT INTO setting(type,value) VALUES ('BOT_STATES','"+
                json.dumps(finance_states,separators=(',',':'))+"')")
            yes(q("SELECT JSON_UNQUOTE(JSON_EXTRACT(value,'$.walletState')) "
                  "FROM setting WHERE type='BOT_STATES'")=='on' and
                q("SELECT COUNT(*) FROM setting WHERE type='BOT_STATES'")=='1',
                'owned_finance_fixture_persists_authoritative_wallet_policy')
            now=int(time.time())
            q(f"UPDATE orders_list SET expire_date={now+2*86400} WHERE id=41")
            for kind,offer,units,price in [('day',701,4,120),('volume',702,5,220)]:
                table='increase_day' if kind=='day' else 'increase_plan'
                q(f"INSERT INTO {table}(id,volume,price,server_id,group_ids,scope_hash) "
                  f"VALUES({offer},{units},{price},2,NULL,'{sha('S:2')}')")
            def issue(kind,method,key_name,offer=None,mode='ok',price=None,groups=None):
                offer=offer or (701 if kind=='day' else 702)
                units=4 if kind=='day' else 5
                final_price=price or (120 if kind=='day' else 220)
                snapshot={'version':1,'kind':kind,'offer_id':offer,'order_id':41,
                    'server_id':2,'provider':'marzban','remark':'owned-native-fixture',
                    'native_identity':None,'native_groups':groups or [],'scope_hash':sha('S:2'),
                    'units':units,'base_price':final_price,'final_price':final_price}
                snap=json.dumps(snapshot,separators=(',',':'))
                statement=("INSERT INTO pays(hash_id,user_id,type,plan_id,server_id,order_id,volume,"
                    "day,price,request_date,state,message_id,chat_id,addon_scope_snapshot) "
                    f"VALUES('{key_name}',1001,'INCREASE_{kind.upper()}_41_{offer}',7,2,")
                statement+=f"41,{units if kind=='volume' else 0},{units if kind=='day' else 0},"
                statement+=f"{final_price},{now},'{ 'pending' if method in ('wallet','hosted') else 'have_sent' }',"
                statement+=f"703,'9001','{snap}')"
                q(statement)
            finance_checked=0
            provider_dispatches=0
            for kind in ('day','volume'):
                for method in ('wallet','manual'):
                    key_name='scoped_'+kind+'_'+method
                    issue(kind,method,key_name)
                    wallet_before=int(q('SELECT wallet FROM users WHERE userid=1001'))
                    before=int(q('SELECT COUNT(*) FROM increase_order'))
                    result=settle(kind,method,key_name)
                    expected_state='paid_with_wallet' if method=='wallet' else 'approved'
                    expected_amount=120 if kind=='day' else 220
                    expected_wallet=wallet_before-expected_amount if method=='wallet' else wallet_before
                    observed={
                        'state':q(f"SELECT state FROM pays WHERE hash_id='{key_name}'"),
                        'wallet':int(q('SELECT wallet FROM users WHERE userid=1001')),
                        'audit_count':int(q('SELECT COUNT(*) FROM increase_order')),
                        'provider_writes':result['provider_writes'],
                        # Isolated fake Telegram transport only; no actual
                        # user text, payment credentials or provider URLs.
                        'events':[{'kind':event.get('event'),
                                   'text':str(event.get('text',''))[:160]}
                                  for event in result['events'][-8:]],
                    }
                    terminal=(observed['state']==expected_state and
                        observed['wallet']==expected_wallet and
                        observed['audit_count']==before+1 and
                        observed['provider_writes']==1)
                    if not terminal:
                        raise AssertionError('actual_'+method+'_'+kind+
                            '_claim_dispatch_verify_audit_terminal observed='+
                            repr(observed)+' expected='+repr({
                                'state':expected_state,'wallet':expected_wallet,
                                'audit_count':before+1,'provider_writes':1}))
                    yes(True,'actual_'+method+'_'+kind+
                        '_claim_dispatch_verify_audit_terminal')
                    second=settle(kind,method,key_name)
                    yes(q(f"SELECT state FROM pays WHERE hash_id='{key_name}'")==expected_state and
                        int(q('SELECT wallet FROM users WHERE userid=1001'))==expected_wallet and
                        int(q('SELECT COUNT(*) FROM increase_order'))==before+1 and
                        second['provider_writes']==0,
                        'settled_'+method+'_'+kind+'_replay_never_double_applies')
                    finance_checked+=1;provider_dispatches+=result['provider_writes']
            # An ambiguous remote acknowledgement is NOT grounds to replay or
            # refund an already durable local wallet reservation.
            for kind in ('day','volume'):
                key_name='scoped_'+kind+'_uncertain'
                issue(kind,'wallet',key_name)
                wallet_before=int(q('SELECT wallet FROM users WHERE userid=1001'))
                first=settle(kind,'wallet',key_name,'lost_ack')
                held_wallet=int(q('SELECT wallet FROM users WHERE userid=1001'))
                held_state=q(f"SELECT state FROM pays WHERE hash_id='{key_name}'")
                second=settle(kind,'wallet',key_name,'ok')
                yes(held_state=='processing_wallet' and
                    held_wallet==wallet_before-(120 if kind=='day' else 220) and
                    int(q('SELECT wallet FROM users WHERE userid=1001'))==held_wallet and
                    q(f"SELECT state FROM pays WHERE hash_id='{key_name}'")=='processing_wallet' and
                    first['provider_writes']==1 and second['provider_writes']==0,
                    'ambiguous_'+kind+'_remote_ack_held_without_refund_or_replay')
                finance_checked+=1;provider_dispatches+=first['provider_writes']
            # A changed admin scope can never turn a pending quote into a
            # different effective offer, even when the user has funds.
            issue('day','wallet','scoped_scope_drift')
            before=int(q('SELECT wallet FROM users WHERE userid=1001'))
            q("UPDATE increase_day SET server_id=4,scope_hash='"+sha('S:4')+"' WHERE id=701")
            drift=settle('day','wallet','scoped_scope_drift')
            yes(drift['provider_writes']==0 and
                int(q('SELECT wallet FROM users WHERE userid=1001'))==before and
                q("SELECT state FROM pays WHERE hash_id='scoped_scope_drift'")=='pending',
                'scope_drift_before_financial_claim_refuses_debit_effect')
            q("UPDATE increase_day SET server_id=2,scope_hash='"+sha('S:2')+"' WHERE id=701")
            # Concurrent claims operate on the same real locked pays and
            # wallet rows. At most one can reach the fake network boundary.
            issue('volume','wallet','scoped_race_claim')
            before_wallet=int(q('SELECT wallet FROM users WHERE userid=1001'))
            before_records=int(q('SELECT COUNT(*) FROM increase_order'))
            with ThreadPoolExecutor(max_workers=2) as pool:
                racing=list(pool.map(lambda _:settle('volume','wallet','scoped_race_claim'),
                    range(2)))
            yes(sum(r['provider_writes'] for r in racing)==1 and
                q("SELECT state FROM pays WHERE hash_id='scoped_race_claim'")=='paid_with_wallet' and
                int(q('SELECT wallet FROM users WHERE userid=1001'))==before_wallet-220 and
                int(q('SELECT COUNT(*) FROM increase_order'))==before_records+1,
                'simultaneous_wallet_claim_only_one_effect_and_single_debit')
            finance_checked+=1;provider_dispatches+=1

            # Run the REAL hosted doAction payment/claim/service-effect/
            # audit/ledger-closure path. Its existing owned finance driver
            # intercepts only payment/provider/Telegram transports. The
            # native account is resolved through the controlled Marzban HTTP
            # adapter; there is no real payment or remote network traffic.
            stage=w/'scoped-hosted-source'
            source_stages.stage_source(stage,True)
            hosted_driver=w/'owned-finance-guard.php'
            hosted_source=hosted_routes.PHP_DRIVER.replace(
                'error_reporting(E_ALL & ~E_DEPRECATED);','error_reporting(E_ALL);')
            yes(hosted_source!=hosted_routes.PHP_DRIVER,
                'hosted_scoped_native_driver_deprecations_strict')
            hosted_driver.write_text(hosted_source)
            def hosted_call(hash_name,provider,**options):
                pay_id=int(q(f"SELECT id FROM pays WHERE hash_id='{hash_name}'"))
                return hosted_routes.run_php(w,e,stage,dict(route='do_action',get={},
                    pay_row_id=pay_id,provider=provider,
                    marzban_username='owned-native-fixture',
                    existing_native_accounts=['owned-native-fixture'],
                    **options))
            def hosted_invoice(kind,provider,ref):
                issue(kind,'hosted',ref)
                q(f"UPDATE pays SET payid='{ref}-external' WHERE hash_id='{ref}'")
                q("INSERT INTO payment_attempts "
                    "(pay_row_id,provider,order_hash,provider_reference,"
                    "checkout_url,quote_amount,quote_currency,local_amount_toman,stage) "
                    f"SELECT id,'{provider}',hash_id,'{ref}-external',"
                    f"'https://fixture.invalid/pay/{ref}',price,'toman',price,'pending' "
                    f"FROM pays WHERE hash_id='{ref}'")
            for kind,provider in [('day','nextpay'),('volume','zarinpal')]:
                ref='scoped_'+kind+'_'+provider+'_hosted'
                hosted_invoice(kind,provider,ref)
                before_wallet=int(q("SELECT wallet FROM users WHERE userid=1001"))
                before_audit=int(q("SELECT COUNT(*) FROM increase_order"))
                res=hosted_call(ref,provider)
                result=[v['result'] for v in res['events']
                        if v['name']=='do_action_result']
                row=q("SELECT p.state,a.stage,a.provider_status FROM pays p "
                    "JOIN payment_attempts a ON a.pay_row_id=p.id "
                    f"WHERE p.hash_id='{ref}'")
                yes(result==['delivered'] and row=='paid\tdone\tdelivered' and
                    int(q("SELECT COUNT(*) FROM increase_order"))==before_audit+1 and
                    int(q("SELECT wallet FROM users WHERE userid=1001"))==before_wallet and
                    any('/api/user/' in str(path) for path in res['network_calls']),
                    'scoped_hosted_'+provider+'_'+kind+'_verified_effect_terminal_ledger')
                repeated=hosted_call(ref,provider)
                yes(not repeated['network_calls'] and
                    int(q("SELECT COUNT(*) FROM increase_order"))==before_audit+1,
                    'scoped_hosted_'+provider+'_'+kind+'_replay_does_not_dispatch')
                finance_checked+=1
            # A verified upstream hosted charge whose remote effect loses
            # acknowledgement remains PAID / REVIEW, not silently retried.
            held_ref='scoped_day_hosted_uncertain'
            hosted_invoice('day','nextpay',held_ref)
            pre_audit=int(q("SELECT COUNT(*) FROM increase_order"))
            result=hosted_call(held_ref,'nextpay',
                marzban_transport_failure=True)
            state=q("SELECT p.state,a.stage FROM pays p JOIN payment_attempts a "
                f"ON a.pay_row_id=p.id WHERE p.hash_id='{held_ref}'")
            repeated=hosted_call(held_ref,'nextpay')
            yes(state=='paid\treview' and
                int(q("SELECT COUNT(*) FROM increase_order"))==pre_audit and
                not repeated['network_calls'],
                'hosted_unknown_remote_ack_preserves_durable_paid_review_hold')
            finance_checked+=1
            # A newly invalid offer is rejected *before* a hosted claim,
            # while preserving all original frozen quote accounting fields.
            drift_ref='scoped_volume_hosted_scope_drift'
            hosted_invoice('volume','zarinpal',drift_ref)
            q("UPDATE increase_plan SET server_id=4,scope_hash='"+sha('S:4')+"' WHERE id=702")
            blocked=hosted_call(drift_ref,'zarinpal')
            drift_state=q("SELECT p.state,a.stage FROM pays p JOIN payment_attempts a "
                f"ON a.pay_row_id=p.id WHERE p.hash_id='{drift_ref}'")
            yes(drift_state=='pending\tpending' and
                not any('/api/user/' in str(path) for path in blocked['network_calls']),
                'hosted_offer_scope_drift_stops_before_paid_claim_or_provider_edit')
            q("UPDATE increase_plan SET server_id=2,scope_hash='"+sha('S:2')+"' WHERE id=702")
            finance_checked+=1
    return {'status':'PASS','cases':len(checks),'checks':checks,
            'sql_only':not php,'actual_provider_calls':0,'actual_payment_calls':0,
            'local_financial_claims_tested':finance_checked if php else 0,
            'controlled_provider_dispatches_tested':provider_dispatches if php else 0,
            'production_database_modified':False}

def main():
    parser=argparse.ArgumentParser()
    mode=parser.add_mutually_exclusive_group(required=True)
    mode.add_argument('--run-disposable',action='store_true')
    mode.add_argument('--sql-only',action='store_true')
    args=parser.parse_args()
    print(json.dumps(test(args.run_disposable),sort_keys=True))

if __name__=='__main__':main()
