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
from pathlib import Path
import test_access_state as owned
import test_v6_native as native
import test_v8_native as purchase
import test_post_v9_service_unit_guards as unit_routes

ROOT=Path(__file__).resolve().parents[1]
DB=purchase.SIDE[1]
PHP=r'''<?php
error_reporting(E_ALL);
set_error_handler(static function($n,$m){if($n===E_DEPRECATED)return true;throw new ErrorException($m,0,$n);});
if (PHP_VERSION_ID < 80500 || PHP_VERSION_ID >= 80600) throw new RuntimeException('requires_php85');
$_SERVER['SCRIPT_FILENAME']=__FILE__;
require_once $argv[1].'/src/Purchase/Core.php';
require_once $argv[1].'/src/Plans/Addons.php';
$db=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$db->set_charset('utf8mb4');
$spec=json_decode($argv[4],true,64,JSON_THROW_ON_ERROR);
$context=['server_id'=>(int)($spec['server']??2),'provider'=>$spec['provider']??'marzban',
 'groups'=>$spec['groups']??[],'pg_groups_checked'=>$spec['eligible_groups']??($spec['groups']??[])];
try {
switch($spec['op']) {
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
            def callback(data,actor_id=9001,seq=801,route='admin'):
                return actor(route=route,actor=actor_id,data=data,frame={
                    'update_id':seq,'callback_query':{'id':'testcallback','data':data,
                    'from':{'id':actor_id},'message':{'message_id':701,
                    'chat':{'id':actor_id,'type':'private'}}}})
            def message(text,actor_id=9001,seq=804,route='admin'):
                return actor(route=route,actor=actor_id,data='',text=text,frame={
                    'update_id':seq,'message':{'message_id':701,'text':text,
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
            callback('addonScopeServer',seq=802)
            yes(q("SELECT step FROM users WHERE userid=9001")=='addon:server',
                'admin_wizard_server_selection')
            callback('addonPickServer2',seq=803)
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
    return {'status':'PASS','cases':len(checks),'checks':checks,
            'sql_only':not php,'actual_provider_calls':0,'actual_payment_calls':0,
            'production_database_modified':False}

def main():
    parser=argparse.ArgumentParser()
    mode=parser.add_mutually_exclusive_group(required=True)
    mode.add_argument('--run-disposable',action='store_true')
    mode.add_argument('--sql-only',action='store_true')
    args=parser.parse_args()
    print(json.dumps(test(args.run_disposable),sort_keys=True))

if __name__=='__main__':main()
