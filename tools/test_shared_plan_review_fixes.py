#!/usr/bin/env python3
"""Reviewer F1-F4: source-real shared plan safety on disposable SQL/native fixtures.

Exercises the actual post-V9 controllers and both native provider wire paths.
Only socket MariaDB and local 127.0.0.1 test HTTP are used; no real tenant.
"""
from __future__ import annotations
import argparse
import contextlib
import copy
import json
import re
import subprocess
import time
from pathlib import Path

import provider_storage_fixture as shared_storage
import test_access_state as owned
import test_imported_plan_selection as plans
import test_post_v9_marzban_edits as mb
import test_unified_existing_subscriptions as imports
import test_v6_native as native
import test_v6_panels as bodies
import test_v8_native as purchase

ROOT = Path(__file__).resolve().parents[1]
DB = purchase.SIDE[1]

# Test-only invocation of the exact production group setter and the guard
# installed in the actual Marzban administrator login editor.
DRIVER = plans.DRIVER.replace(" } elseif($op==='toggle'){", """ } elseif($op==='review_group'){
  $connection->begin_transaction();
  try {achbotSetPanelGroup($connection,(int)$spec['server'],(int)$spec['group']);
   $connection->commit();$out='assigned';}
  catch(Throwable $error){$connection->rollback();$out='denied:'.$error->getMessage();}
 } elseif($op==='review_url'){
  require_once $root.'/src/Subscription/PhysicalService.php';
  $connection->begin_transaction();
  try {achbotPhysicalAssertRebindSafe($connection,(int)$spec['server'],true);
   $connection->commit();$out='allowed';}
  catch(Throwable $error){$connection->rollback();$out='denied:'.$error->getMessage();}
 } elseif($op==='review_lock'){
  require_once $root.'/src/Subscription/PhysicalService.php';
  $record=$connection->query('SELECT * FROM orders_list WHERE id='.(int)$spec['order'])->fetch_assoc();
  $out=achbotPhysicalLockName($connection,$record);
 } elseif($op==='review_pg_key'){
  require_once $root.'/src/Subscription/PhysicalService.php';
  $connection->begin_transaction();
  try {achbotPhysicalAssertRebindSafe($connection,(int)$spec['server'],false,false);
   $connection->commit();$out='allowed';}
  catch(Throwable $error){$connection->rollback();$out='denied:'.$error->getMessage();}
 } elseif($op==='toggle'){""", 1)
assert DRIVER != plans.DRIVER, 'review test route not added'

@contextlib.contextmanager
def harness(provider: str):
    fixture = imports.existing_marzban if provider == 'marzban' else imports.existing_panel
    with owned.owned_database() as (work, env), fixture() as (vendor, url):
        purchase.init(work, env, url)
        def db(query: str) -> str:
            return native.sql(work, env, query, DB).strip()
        for name in ('001_marzban_panel_group_id.sql', '003_payment_attempts.sql',
                     '004_automatic_renewal_attempts.sql', '006_payment_message_refs.sql',
                     '007_service_effects.sql', '011_provider_identity.sql',
                     '012_shared_provider_storage.sql'):
            db((ROOT / 'migrations/post-v9' / name).read_text())
        db("INSERT INTO users(userid,name,username,refcode,wallet,date,isAdmin,first_start,step)"
           " VALUES(1002,'Foreign','foreign','0',50000,'1700000000',0,'sent','none');"
           "UPDATE server_plans SET price=137,days=30,volume=3 WHERE id=7;"
           "DELETE FROM setting WHERE type='BOT_STATES';"
           "INSERT INTO setting(type,value)VALUES('BOT_STATES',"
           "'{\"walletState\":\"on\",\"gatewayAudience\":{\"wallet\":\"all\"}}')")
        if provider == 'pasarguard':
            db("UPDATE server_plans SET provider_group_ids='[2,5]' WHERE server_id=2;"
               + shared_storage.connection(2, 1, url))
            remark = 'existing_user_41'
            credential = vendor.users[41]['subscription_url']
            def writes():
                return [entry for entry in vendor.trace if entry[0] == 'PUT']
        else:
            vendor.mode = 'edit-reset-verified'
            vendor.edit_put_count = vendor.edit_reset_count = 0
            db("UPDATE server_plans SET custom_sni='" + json.dumps(
                {'inbounds': {'vless': ['inbound']}, 'proxies': {'vless': {'flow': ''}}})
                + "' WHERE id=7")
            vendor.edit_account = copy.deepcopy(mb.INITIAL)
            remark = 'review_native_user'
            credential = url + '/sub/' + imports.mb_mint(remark, int(time.time())-60)
            vendor.edit_account.update(username=remark,
                created_at='2026-10-26T05:00:00.123456',
                subscription_url=credential,
                proxies={'vless': {'id': 'aaaaaaaa-1111-4111-8111-111111111111',
                                    'flow': 'xtls-rprx-vision'}},
                inbounds={'vless': ['inbound']},
                links=['vless://aaaaaaaa-1111-4111-8111-111111111111@host.invalid:443'],
                excluded_inbounds={}, next_plan=None, auto_delete_in_days=None,
                on_hold_expire_duration=None, on_hold_timeout=None)
            def writes():
                return [entry for entry in vendor.trace if entry['method'] == 'PUT']
        facade = bodies.function_bodies((ROOT / 'config.php').read_bytes(), [
            'buildSubscriptionPresentation', 'buildConfigMessage', 'setUser', 'sumerize',
            'getOrderDetailKeys', 'getUserOrderDetailKeys', 'getMarzbanToken',
            'getMarzbanHosts', 'renewMarzbanUUID', 'deleteMarzban',
            'changeMarzbanState', 'RandomString'])
        (work / 'facades.php').write_bytes(facade.replace(b'__DIR__', repr(str(ROOT)).encode()))
        for name in ('016_remove_pasarguard_server_groups.sql', '017_shared_plan_changes.sql'):
            db((ROOT / 'migrations/post-v9' / name).read_text())
        driver = work / 'review-plan-controller.php'
        driver.write_text(DRIVER)
        counter = 900000
        def call(op: str, **spec):
            nonlocal counter
            counter += 1
            args = dict(op=op, source=counter, **({'actor': 9001} | spec))
            run = subprocess.run(['php', '-d', 'display_errors=0', '-d', 'log_errors=0',
                str(driver), str(ROOT), str(work / 'mysql.sock'), DB,
                json.dumps(args)], cwd=work, env=env, capture_output=True,
                text=True, timeout=40)
            allowed = ('achbot_plan_selection_unverified',
                'achbot_plan_selection_unavailable',
                'achbot_shared_plan_requires_review',
                'achbot_shared_plan_committed')
            if run.returncode or not all(line.startswith(allowed)
                for line in run.stderr.splitlines()):
                raise AssertionError((provider, op, run.returncode, run.stdout, run.stderr))
            output = json.loads(run.stdout)
            if 'fixture_error' in output:
                raise AssertionError((provider, op, output))
            return output['out']
        def attach(actor: int):
            call('external', actor=actor, data='showUUIDLeft')
            call('external', actor=actor, text=credential)
            call('manual', actor=actor, data='addToOrders2')
            return int(db('SELECT id FROM orders_list WHERE userid=' + str(actor)
                    + " AND BINARY remark=BINARY '" + remark + "'"))
        yield dict(db=db, call=call, attach=attach, vendor=vendor, url=url,
                   remark=remark, writes=writes, provider=provider)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--run-disposable', action='store_true', required=True)
    parser.add_argument('--report', type=Path)
    args = parser.parse_args()
    checks = []
    def yes(condition, name):
        if not condition:
            raise AssertionError(name)
        checks.append(name)
    def seeded(h):
        db, call = h['db'], h['call']
        oid = h['attach'](1001)
        peer = h['attach'](1002)
        yes(oid > 0 and peer > oid,
            h['provider']+'_same_native_account_has_two_distinct_owners')
        for number in (119, 120):
            db('INSERT INTO server_plans(id,fileid,catid,server_id,inbound_id,acount,limitip,title,protocol,days,volume,type,price,descr,pic,active,step,date,custom_sni,provider_group_ids)'
               f" SELECT {number},fileid,catid,server_id,inbound_id,acount,limitip,'Review{number}',protocol,days,volume,type,price,descr,pic,active,step,date,custom_sni,provider_group_ids FROM server_plans WHERE id=7")
        if h['provider']=='pasarguard':
            db("UPDATE server_plans SET provider_group_ids='[2]' WHERE id=120")
        else:
            db("UPDATE server_plans SET custom_sni='" + json.dumps(
                {'inbounds': {'vless': ['fake-inbound']},
                 'proxies': {'vless': {'flow': ''}}}) + "' WHERE id=120")
        h.update(oid=oid, peer=peer)
        before = len(h['writes']())
        call('extensions', data=f'confirmChangePlan_{oid}_119')
        yes(len(h['writes']())==before and
            db(f'SELECT fileid FROM orders_list WHERE id IN ({oid},{peer}) ORDER BY id')=='119\n119',
            h['provider']+'_setup_same_native_access_one_verified_zero_put_plan')
        return h
    def plan(h, target=120):
        h['call']('extensions', data=f"confirmChangePlan_{h['oid']}_{target}")
    def subject(h):
        db=h['db'];ids=f"{h['oid']},{h['peer']}"
        return db(f'SELECT id,server_id,fileid,amount,autoRenew,status FROM orders_list WHERE id IN ({ids}) ORDER BY id')
    def finance(h):
        db=h['db']
        return db('SELECT userid,wallet FROM users ORDER BY userid;'
                  'SELECT id,price,state FROM pays ORDER BY id;'
                  'SELECT id,ucount FROM server_info ORDER BY id')
    def outstanding(h):
        return h['db']("SELECT state FROM service_plan_changes ORDER BY id DESC LIMIT 1")
    def denied(h, label):
        before = subject(h); money=finance(h); puts=len(h['writes']())
        journals=int(h['db']('SELECT COUNT(*) FROM service_plan_changes'))
        plan(h)
        yes(before==subject(h) and money==finance(h) and puts==len(h['writes']()) and
            journals==int(h['db']('SELECT COUNT(*) FROM service_plan_changes')),
            h['provider']+'_'+label+'_no_reassignment_payment_or_native_put')
    def invoice(h, name, state='paid', payment_type='RENEW_ACCOUNT', order=None):
        peer = h['peer'] if order is None else order
        hashid='f'+h['provider'][0]+name
        h['db']('INSERT INTO pays(hash_id,user_id,type,plan_id,server_id,order_id,price,request_date,state)'
                f" VALUES('{hashid}',1002,'{payment_type}',119,2,{peer},137,UNIX_TIMESTAMP(),'{state}')")
        return int(h['db'](f"SELECT id FROM pays WHERE hash_id='{hashid}'"))

    for provider in ('marzban', 'pasarguard'):
        # F2 financial cases plus F1 before and after a real F3 held journal.
        with harness(provider) as h:
            seeded(h)
            db=h['db']
            # Fully historical and settled is a positive control; never block
            # an otherwise healthy physical subscription forever.
            approved=invoice(h,'history','approved')
            before=len(h['writes']())
            plan(h,7)
            yes(subject(h).count('\t7\t')==2 and len(h['writes']())==before,
                provider+'_completed_historical_payment_allows_verified_no_put_plan')
            plan(h,119)
            db(f'DELETE FROM pays WHERE id={approved}')
            settled=invoice(h,'donehistorical','paid')
            db('INSERT INTO payment_attempts(pay_row_id,provider,order_hash,quote_amount,quote_currency,stage)'
               f" VALUES({settled},'nowpayments','settled-{provider}',137,'USD','done')")
            puts=len(h['writes']())
            plan(h,7)
            plan(h,119)
            yes(len(h['writes']())==puts and outstanding(h)=='committed' and
                db(f"SELECT fileid FROM orders_list WHERE id IN ({h['oid']},{h['peer']}) ORDER BY id")=='119\n119',
                provider+'_paid_with_done_fulfillment_evidence_allows_noop_plan_changes')
            db(f'DELETE FROM payment_attempts WHERE pay_row_id={settled};DELETE FROM pays WHERE id={settled}')

            paid=invoice(h,'paidunfinished')
            denied(h,'paid_unfinished_without_payment_attempt')
            db(f'DELETE FROM pays WHERE id={paid}')

            paid=invoice(h,'attempt')
            db('INSERT INTO payment_attempts(pay_row_id,provider,order_hash,quote_amount,quote_currency,stage)'
               f" VALUES({paid},'nowpayments','review-attempt-{provider}',137,'USD','fulfilling')")
            denied(h,'paid_gateway_fulfilling_attempt')
            db(f"UPDATE payment_attempts SET stage='review' WHERE pay_row_id={paid}")
            denied(h,'paid_gateway_review_attempt')
            db(f'DELETE FROM payment_attempts WHERE pay_row_id={paid};DELETE FROM pays WHERE id={paid}')

            approved=invoice(h,'approvedreview','approved')
            db('INSERT INTO payment_attempts(pay_row_id,provider,order_hash,quote_amount,quote_currency,stage)'
               f" VALUES({approved},'nowpayments','approved-review-{provider}',137,'USD','review')")
            denied(h,'approved_but_gateway_fulfillment_unfinished')
            db(f'DELETE FROM payment_attempts WHERE pay_row_id={approved};DELETE FROM pays WHERE id={approved}')

            renewed=invoice(h,'autorenew','approved')
            db('INSERT INTO automatic_renewal_attempts(order_id,cycle_expire,pay_row_id,user_id,plan_id,server_id,'
               'account_remark,price_toman,duration_days,volume_gb,new_expire,stage)'
               f" VALUES({h['peer']},1700000100,{renewed},1002,119,2,'{h['remark']}',137,30,3,1800000100,'review')")
            denied(h,'other_owner_automatic_renewal_review_attempt')
            db(f'DELETE FROM automatic_renewal_attempts WHERE pay_row_id={renewed};DELETE FROM pays WHERE id={renewed}')

            # Inject an independent, in-flight service effect tied to this
            # exact physical identity. Do not mutate the imported account's
            # actual creation/adoption provenance to model a foreign job.
            db("INSERT INTO service_effects(source_kind,source_ref,ordinal,operation_id,"
               "target_server_id,target_key,endpoint_sha256,plan_id,remote_username,"
               "requested_expire,requested_bytes,request_sha256,state) VALUES("
               f"'review','held-{provider}',99,'{'a'*32}',2,'review-f2-{provider}',"
               f"'{'b'*64}',119,'{h['remark']}',1800000000,1024,'{'c'*64}','dispatching')")
            effects=db(f"SELECT id FROM service_effects WHERE source_ref='held-{provider}'")
            yes(bool(effects),provider+'_cross_account_native_service_effect_fixture_present')
            denied(h,'other_owner_native_service_effect_dispatching')
            db(f'DELETE FROM service_effects WHERE id={effects}')

            legacy=invoice(h,'legacyaddon','pending',f'INCREASE_DAY_{h["peer"]}_3',0)
            denied(h,'legacy_type_encoded_addon_unclaimed')
            db(f'DELETE FROM pays WHERE id={legacy}')

            if provider=='marzban':
                group=db("INSERT INTO setting(type,value) VALUES('ACHBOT_MARZBAN_PANEL_GROUP','Owned Review Group');"
                         "SELECT id FROM setting WHERE type='ACHBOT_MARZBAN_PANEL_GROUP' ORDER BY id DESC LIMIT 1")
                outcome=h['call']('review_group',server=2,group=int(group))
                yes(outcome.startswith('denied:group_change_would_strand_order') and
                    db('SELECT IFNULL(panel_group_id,0) FROM server_info WHERE id=2')=='0',
                    'marzban_null_to_group_active_account_denied_without_rebinding')
                h['group']=int(group)
            # F3 no-op: actual provider GET completes, then an administrator
            # connection is swapped before the SQL local finalization.
            current_lock=h['call']('review_lock',order=h['oid'])
            def rebind():db("UPDATE server_config SET panel_url=CONCAT(panel_url,'/raced') WHERE id=2")
            if provider=='marzban':h['vendor'].on_account_get=rebind
            else:h['vendor'].on_read=rebind
            before=subject(h); money=finance(h); puts=len(h['writes']())
            plan(h,7)
            yes(before==subject(h) and money==finance(h) and len(h['writes']())==puts and
                outstanding(h)!='committed',
                provider+'_same_native_access_connection_rebind_after_get_holds_journal')
            # Test-only controlled restoration of a malicious DB edit, not an
            # automatic reconciliation of the held journal.
            db("UPDATE server_config SET panel_url='"+h['url']+"' WHERE id=2")
            yes(h['call']('review_lock',order=h['oid'])==current_lock,
                provider+'_durable_physical_lock_identity_independent_of_editable_endpoint')
            plan(h,7)
            yes(before==subject(h) and len(h['writes']())==puts and
                outstanding(h)!='committed',
                provider+'_held_journal_cannot_retry_after_restored_connection')
            if provider=='marzban':
                db(f"UPDATE orders_list SET status=0 WHERE id IN ({h['oid']},{h['peer']})")
                held=h['call']('review_group',server=2,group=h['group'])
                url_denial=h['call']('review_url',server=2)
                yes(held=='denied:group_change_requires_review' and
                    url_denial=='denied:physical_rebind_pending_journal' and
                    db('SELECT IFNULL(panel_group_id,0) FROM server_info WHERE id=2')=='0',
                    'marzban_old_held_journal_blocks_group_and_admin_url_change_even_without_active_rows')
                db(f"UPDATE orders_list SET status=1 WHERE id IN ({h['oid']},{h['peer']})")
            else:
                yes(h['call']('review_pg_key',server=2)=='denied:physical_rebind_pending_journal',
                    'pasarguard_key_rotation_rejects_held_plan_journal_even_when_active')

        # F3 actual-access-change: mutate the original endpoint only after
        # the adapter's second strict Native GET and BEFORE the single PUT.
        with harness(provider) as h:
            seeded(h)
            db=h['db']
            def change_endpoint():db("UPDATE server_config SET panel_url=CONCAT(panel_url,'/raced') WHERE id=2")
            def defer_to_second_get():
                if provider=='marzban':h['vendor'].on_account_get=change_endpoint
                else:h['vendor'].on_read=change_endpoint
            if provider=='marzban':h['vendor'].on_account_get=defer_to_second_get
            else:h['vendor'].on_read=defer_to_second_get
            before=subject(h); money=finance(h); puts=len(h['writes']())
            plan(h,120)
            yes(before==subject(h) and money==finance(h) and len(h['writes']())==puts and
                outstanding(h)!='committed',
                provider+'_changed_access_connection_rebind_before_put_denied_with_durable_hold')
            # A new selection after the malformed admin rebind cannot bypass
            # the journal or dispatch a second PUT.
            plan(h,120)
            yes(before==subject(h) and len(h['writes']())==puts,
                provider+'_changed_access_rebind_replay_never_admits_native_put')

        # Specific PasarGuard connection incarnation, not just its URL:
        # changing provider_panels.instance_key during either observation
        # window must hold the original journal with zero Native write.
        if provider=='pasarguard':
            for variant in ('noop','changed'):
                with harness(provider) as h:
                    seeded(h)
                    db=h['db']
                    def change_incarnation():
                        db("UPDATE provider_panels SET instance_key=REPEAT('c',32) WHERE id=1")
                    if variant=='noop':
                        h['vendor'].on_read=change_incarnation
                    else:
                        def delay_change():
                            h['vendor'].on_read=change_incarnation
                        h['vendor'].on_read=delay_change
                    before=subject(h);money=finance(h);puts=len(h['writes']())
                    plan(h,7 if variant=='noop' else 120)
                    yes(before==subject(h) and finance(h)==money and
                        len(h['writes']())==puts and outstanding(h)!='committed',
                        'pasarguard_'+variant+'_provider_instance_change_during_native_get_fails_closed')
                    plan(h,7 if variant=='noop' else 120)
                    yes(subject(h)==before and len(h['writes']())==puts,
                        'pasarguard_'+variant+'_provider_instance_mismatch_cannot_retry_native_put')

        # F4 real Native wire PUT with legitimate usage growth (positive)
        # followed by the faulty vendor resetting usage (negative).
        with harness(provider) as h:
            seeded(h)
            db=h['db']
            if provider=='marzban':
                h['vendor'].edit_account['used_traffic']=1073741824
                baseline=1073741824
            else:
                h['vendor'].users[41]['used_traffic']=42000
                baseline=42000
            h['vendor'].access_put_usage_override=baseline+137
            puts=len(h['writes']())
            plan(h,120)
            yes(len(h['writes']())==puts+1 and
                db(f"SELECT fileid FROM orders_list WHERE id IN ({h['oid']},{h['peer']}) ORDER BY id")=='120\n120' and
                outstanding(h)=='committed',
                provider+'_native_access_only_change_accepts_legitimate_usage_growth')
            plan(h,119)
            yes(outstanding(h)=='committed' and
                db(f"SELECT fileid FROM orders_list WHERE id IN ({h['oid']},{h['peer']}) ORDER BY id")=='119\n119',
                provider+'_access_reversion_preserves_verified_usage_and_linked_rows')
            h['vendor'].access_put_usage_override=0
            before=subject(h);money=finance(h);puts=len(h['writes']())
            plan(h,120)
            yes(len(h['writes']())==puts+1 and subject(h)==before and finance(h)==money and
                outstanding(h)!='committed',
                provider+'_vendor_usage_reset_after_group_host_put_requires_durable_review')
            plan(h,120)
            yes(len(h['writes']())==puts+1 and subject(h)==before and
                outstanding(h)!='committed',
                provider+'_usage_reset_held_journal_never_repeats_native_put')

    yes('achbotPhysicalAssertRebindSafe($connection,$entity,true,$endpointChanged)' in
        (ROOT/'src/Admin/ManagementFlow.php').read_text(),
        'production_admin_marzban_login_editor_uses_same_safe_rebind_fence')
    result=dict(complete=True,cases=len(checks),checks=checks,
        actual_php=True,disposable_mariadb=True,
        native_providers=['marzban','pasarguard'],
        f1_mutable_lock_and_reclassification=True,
        f2_cross_ledger_and_shared_owner=True,
        f3_native_get_to_commit_and_pre_put_races=True,
        f4_usage_preservation_positive_and_negative=True,
        held_journal_replay_blocked=True,production_mutations=0,live_provider_calls=0)
    if args.report:
        args.report.write_text(json.dumps(result,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps({key:value for key,value in result.items() if key!='checks'}))

if __name__=='__main__':main()
