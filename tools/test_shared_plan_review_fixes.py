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
import test_unified_management_wizard as management
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
 } elseif($op==='review_commerce'){
  require_once $root.'/src/Panel/ProviderRuntime.php';
  require_once $root.'/src/Subscription/AccountDetails.php';
  $server=(int)$spec['server'];$plan=(int)$spec['plan'];
  $q=$connection->prepare('SELECT * FROM orders_list WHERE id=?');
  $oid=(int)$spec['order'];$q->bind_param('i',$oid);$q->execute();
  $order=$q->get_result()->fetch_assoc();$q->close();
  $out=['buy'=>achbotServerCommerceAvailable($connection,$server,true),
    'renew'=>achbotServerCommerceAvailable($connection,$server,false),
    'new_plan'=>achbotSupportsPlan($connection,$plan,0,$server),
    'saved_plan'=>achbotSupportsPlan($connection,$plan,0,$server,false,true),
    'selected_renew_plan'=>\\AchBot\\Subscription\\AccountDetails\\selectedPaidPlan($connection,$order)!==null];
 } elseif($op==='review_api_state'){
  require_once $root.'/src/Finance/ApiPayments.php';
  $pay=achbotApiPaymentRow($connection,(int)$spec['pay']);
  $seen=achbotApiPaymentSubjectPreflight($connection,$pay);
  $other=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
  if (array_key_exists('new_state',$spec)) {
   $state=(int)$spec['new_state'];
   $q=$other->prepare('UPDATE server_info SET state=? WHERE id=2');
   $q->bind_param('i',$state);$q->execute();$q->close();
  }
  $other->close();
  $connection->begin_transaction();
  $locked=achbotApiPaymentRow($connection,(int)$spec['pay'],true);
  $out=['preflight'=>$seen['valid'],
   'allowed'=>achbotApiPaymentLocalSubjectAllowed($connection,$locked,$seen)];
  $connection->rollback();
 } elseif($op==='review_invoice'){
  require_once $root.'/src/Finance/PaymentLifecycle.php';
  $hash='state'.bin2hex(random_bytes(10));$actor=(int)$spec['actor'];
  $type=(string)$spec['type'];$plan=(int)$spec['plan'];$server=(int)$spec['server'];
  $order=$type==='BUY_SUB'?null:(int)$spec['order'];$now=time();$price=137;
  $q=$connection->prepare("INSERT INTO pays(hash_id,user_id,type,plan_id,server_id,order_id,price,request_date,state)
    VALUES(?,?,?,?,?,?,?,?,'pending')");
  $q->bind_param('sisiiiii',$hash,$actor,$type,$plan,$server,$order,$price,$now);
  $id=achbotPayUxInsertDraft($connection,$actor,$q);$q->close();
  $out=$id>0?'issued':'denied';
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
        admin_driver = work / 'review-admin-confirmed-delete.php'
        admin_driver.write_text(management.PHP)
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
                'achbot_existing_subscription_import_deferred',
                'achbot_plan_selection_unavailable',
                'achbot_shared_plan_requires_review',
                'achbot_shared_plan_committed',
                'achbot_customer_draft_creation_deferred',
                'achbot_order_plan_actions_unverified')
            if run.returncode or not all(line.startswith(allowed)
                for line in run.stderr.splitlines()):
                raise AssertionError((provider, op, run.returncode, run.stdout, run.stderr))
            output = json.loads(run.stdout)
            if 'fixture_error' in output:
                raise AssertionError((provider, op, output))
            return output['out']
        def admin(spec):
            run = subprocess.run(['php', '-d', 'display_errors=0', '-d', 'log_errors=0',
                str(admin_driver), str(ROOT), str(work / 'mysql.sock'), DB,
                json.dumps(spec)], cwd=work, env=env, capture_output=True,
                text=True, timeout=30)
            if run.returncode or any(not re.fullmatch(
                    r'achbot_management_field_deferred reason=[a-z_]+', line)
                    for line in run.stderr.splitlines()):
                raise AssertionError(('admin_intent', provider, run.returncode, run.stdout, run.stderr))
            result = json.loads(run.stdout)
            if 'exception' in result:
                raise AssertionError(('admin_intent', provider, result))
            return result
        def warn(server: int = 2, actor: int = 9001, message_id: int = 575):
            nonlocal counter
            counter += 1
            result = admin(dict(actor=actor, source=counter, message_id=message_id,
                callback=f'wizwizdeleteserver{server}', issue_delete='server', entity=server))
            issued = result['issued']
            if not isinstance(issued,str) or not re.fullmatch(
                    rf'yesDeleteServer{server}_[a-f0-9]{{16}}', issued):
                raise AssertionError(('server_warning_missing', provider, result))
            return issued
        def delete(server: int = 2, callback: str | None = None, actor: int = 9001,
                   message_id: int = 575):
            nonlocal counter
            if callback is None: callback=warn(server, actor, message_id)
            counter += 1
            return admin(dict(actor=actor, callback=callback, source=counter,
                message_id=message_id, field=True))
        def attach(actor: int):
            call('external', actor=actor, data='showUUIDLeft')
            call('external', actor=actor, text=credential)
            call('manual', actor=actor, data='addToOrders2')
            return int(db('SELECT id FROM orders_list WHERE userid=' + str(actor)
                    + " AND BINARY remark=BINARY '" + remark + "'"))
        yield dict(db=db, work=work, env=env, call=call, delete=delete, warn=warn, attach=attach, vendor=vendor, url=url,
                   remark=remark, credential=credential, writes=writes, provider=provider)


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

            # R2: payment decisions already final, never stale forever.
            # The native account is unchanged, and both owners' plans move
            # together without mutating a payment, wallet or provider.
            for state in ('declined','mismatched_plan','auto_cart_approved',
                          'canceled','rejected','expired'):
                historical=invoice(h,'terminal'+state,state)
                money=finance(h);puts=len(h['writes']())
                plan(h,7)
                plan(h,119)
                yes(finance(h)==money and len(h['writes']())==puts and
                    outstanding(h)=='committed' and
                    db(f'SELECT state FROM pays WHERE id={historical}')==state,
                    provider+'_terminal_'+state+'_historical_payment_allows_noop_plan')
                db(f'DELETE FROM pays WHERE id={historical}')

            for state, label in (('declined','declined_receipt'),
                                 ('mismatched_plan','unclaimed_renewal')):
                issued=invoice(h,'issued'+state,state)
                db(f"UPDATE pays SET payid='provider-issued' WHERE id={issued}")
                denied(h,label+'_issued_id_remains_uncertain')
                db(f'UPDATE pays SET payid=NULL WHERE id={issued}')
                db('INSERT INTO payment_attempts(pay_row_id,provider,order_hash,'
                   'quote_amount,quote_currency,stage)'
                   f" VALUES({issued},'nowpayments','held-{provider}-{state}',137,'USD','review')")
                denied(h,label+'_unfinished_gateway_attempt_still_blocks')
                db(f"UPDATE payment_attempts SET stage='expired' WHERE pay_row_id={issued}")
                denied(h,label+'_expired_attempt_not_sufficient_to_prove_nonpayable')
                # A recorded child provider transaction cannot be ignored
                # even when an invoice looks like a terminal declined row.
                attempt_id=int(db(f'SELECT id FROM payment_attempts WHERE pay_row_id={issued}'))
                db('INSERT INTO payment_transactions(attempt_id,provider,payment_id)'
                   f" VALUES({attempt_id},'nowpayments','child-{provider}-{state}')")
                denied(h,label+'_provider_child_transaction_blocks')
                db(f'DELETE FROM payment_transactions WHERE attempt_id={attempt_id};'
                   f'DELETE FROM payment_attempts WHERE id={attempt_id};'
                   f'DELETE FROM pays WHERE id={issued}')
            invalid=invoice(h,'othermismatch','mismatched_plan','BUY_SUB')
            denied(h,'mismatched_nonrenewal_state_not_proven_terminal')
            db(f'DELETE FROM pays WHERE id={invalid}')
            sent=invoice(h,'sentexpired','expired_have_sent')
            denied(h,'expired_sent_receipt_still_needs_review')
            db(f'DELETE FROM pays WHERE id={sent}')

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

            # A Panel Group is the administrator's independent declaration
            # of physical-panel membership. Neither two active customer
            # orders nor the provider/plan/finance rows constrain its edit.
            # Different connection addresses are also permitted, since one
            # physical backend may be reachable through distinct endpoints.
            group=int(db("INSERT INTO setting(type,value) VALUES"
                "('ACHBOT_MARZBAN_PANEL_GROUP','Owned Review Group');"
                "SELECT id FROM setting WHERE type='ACHBOT_MARZBAN_PANEL_GROUP' ORDER BY id DESC LIMIT 1"))
            other=int(db("INSERT INTO setting(type,value) VALUES"
                "('ACHBOT_MARZBAN_PANEL_GROUP','Owned Review Group B');"
                "SELECT id FROM setting WHERE type='ACHBOT_MARZBAN_PANEL_GROUP' ORDER BY id DESC LIMIT 1"))
            before=subject(h);money=finance(h);puts=len(h['writes']())
            # The current server may use entirely different API and admin
            # credentials from another logical entry in the same declared
            # physical panel. Group editing must not validate those values.
            original_connection=db('SELECT HEX(panel_url),HEX(username),HEX(password) '
                                   'FROM server_config WHERE id=2').split('\t')
            yes(len(original_connection)==3 and all(original_connection),
                provider+'_synthetic_connection_metadata_available')
            db("UPDATE server_config SET panel_url='https://another-panel-alias.invalid',"
               "username='another-admin',password='another-password' WHERE id=2")
            changed_connection=db('SELECT HEX(panel_url),HEX(username),HEX(password) '
                                  'FROM server_config WHERE id=2')
            for chosen in (group,other,0,group):
                outcome=h['call']('review_group',server=2,group=chosen)
                expected=str(chosen)
                yes(outcome=='assigned' and
                    db('SELECT IFNULL(panel_group_id,0) FROM server_info WHERE id=2')==expected and
                    subject(h)==before and finance(h)==money and
                    db('SELECT HEX(panel_url),HEX(username),HEX(password) '
                       'FROM server_config WHERE id=2')==changed_connection and
                    len(h['writes']())==puts,
                    provider+'_panel_group_is_local_admin_metadata_with_active_orders_'+str(chosen))
            db("UPDATE server_config SET panel_url=UNHEX('"+original_connection[0]+"'),"
               "username=UNHEX('"+original_connection[1]+"'),"
               "password=UNHEX('"+original_connection[2]+"') WHERE id=2")
            # The callback must still reject an unregistered group, missing
            # server and unsupported provider type without making changes.
            invalid=h['call']('review_group',server=2,group=2147483647)
            missing=h['call']('review_group',server=999999,group=group)
            yes(invalid=='denied:unknown_panel_group' and
                missing=='denied:panel_group_server_unavailable' and
                db('SELECT IFNULL(panel_group_id,0) FROM server_info WHERE id=2')==str(group) and
                subject(h)==before and finance(h)==money and
                len(h['writes']())==puts,
                provider+'_panel_group_invalid_target_denied_without_side_effect')
            db("UPDATE server_config SET type='other-provider' WHERE id=2")
            unsupported=h['call']('review_group',server=2,group=other)
            db("UPDATE server_config SET type='"+provider+"' WHERE id=2")
            yes(unsupported=='denied:panel_group_server_unavailable' and
                db('SELECT IFNULL(panel_group_id,0) FROM server_info WHERE id=2')==str(group) and
                subject(h)==before and finance(h)==money and
                len(h['writes']())==puts,
                provider+'_panel_group_unsupported_provider_denied')
            h['group']=group
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
            # Even an outstanding review journal cannot freeze the independent
            # admin group editor. Endpoint/API-key mutation remains a separate,
            # stricter operation and is still rejected while the journal holds.
            db(f"UPDATE orders_list SET status=0 WHERE id IN ({h['oid']},{h['peer']})")
            before=subject(h);money=finance(h);puts=len(h['writes']())
            held=h['call']('review_group',server=2,group=0)
            yes(held=='assigned' and
                db('SELECT IFNULL(panel_group_id,0) FROM server_info WHERE id=2')=='0' and
                before==subject(h) and money==finance(h) and
                len(h['writes']())==puts and outstanding(h)!='committed',
                provider+'_admin_group_unassign_allowed_with_held_plan_journal_no_native_put')
            if provider=='marzban':
                denial=h['call']('review_url',server=2)
                yes(denial=='denied:physical_rebind_pending_journal',
                    'marzban_held_journal_still_blocks_endpoint_change')
            else:
                denial=h['call']('review_pg_key',server=2)
                yes(denial=='denied:physical_rebind_pending_journal',
                    'pasarguard_held_journal_still_blocks_key_rotation')
            db(f"UPDATE orders_list SET status=1 WHERE id IN ({h['oid']},{h['peer']})")

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

        # Assignment stays independent, but an in-flight Native operation
        # must not silently continue with a stale physical-panel identity.
        # Change only the local Group after its first pinned provider read.
        with harness(provider) as h:
            seeded(h)
            db=h['db']
            assigned=int(db("INSERT INTO setting(type,value) VALUES"
                "('ACHBOT_MARZBAN_PANEL_GROUP','Concurrent physical panel');"
                "SELECT id FROM setting WHERE type='ACHBOT_MARZBAN_PANEL_GROUP' ORDER BY id DESC LIMIT 1"))
            def change_group():db(f"UPDATE server_info SET panel_group_id={assigned} WHERE id=2")
            if provider=='marzban':h['vendor'].on_account_get=change_group
            else:h['vendor'].on_read=change_group
            before=subject(h);money=finance(h);puts=len(h['writes']())
            plan(h,7)
            yes(db('SELECT panel_group_id FROM server_info WHERE id=2')==str(assigned) and
                before==subject(h) and money==finance(h) and
                len(h['writes']())==puts and outstanding(h)!='committed',
                provider+'_group_changed_during_native_read_holds_old_intent_without_put')
            plan(h,7)
            yes(before==subject(h) and len(h['writes']())==puts and
                outstanding(h)!='committed',
                provider+'_group_changed_held_journal_replay_has_no_native_put')

        # Specific PasarGuard connection incarnation, not just its URL:
        # changing the consolidated server_config provider_instance_key during either observation
        # window must hold the original journal with zero Native write.
        if provider=='pasarguard':
            for variant in ('noop','changed'):
                with harness(provider) as h:
                    seeded(h)
                    db=h['db']
                    def change_incarnation():
                        db("UPDATE server_config SET provider_instance_key=REPEAT('c',32) WHERE id=2")
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

    for provider in ('marzban','pasarguard'):
        # Every destructive intent is private, one-use, latest-warning-only,
        # and tied to the exact server snapshot and Telegram message.
        with harness(provider) as h:
            seeded(h)
            db=h['db']; initial=subject(h);puts=len(h['writes']())
            cancelled=h['warn']()
            back=h['delete'](callback=cancelled.replace('yesDeleteServer','cancelDeleteServer'))
            yes(db('SELECT COUNT(*) FROM server_info WHERE id=2')=='1' and
                any(e.get('method')=='editMessageText' for e in back['events']),
                provider+'_server_back_cancels_pending_warning')
            after_back=h['delete'](callback=cancelled)
            yes(db('SELECT COUNT(*) FROM server_info WHERE id=2')=='1' and
                not any(e.get('method')=='editMessageText' for e in after_back['events']),
                provider+'_server_old_warning_after_back_denied')
            first=h['warn']()
            old=h['delete'](callback=first,message_id=576)
            yes(db('SELECT COUNT(*) FROM server_info WHERE id=2')=='1' and
                not any(e.get('method')=='editMessageText' for e in old['events']),
                provider+'_server_delete_wrong_message_denied')
            second=h['warn']()
            old=h['delete'](callback=first)
            yes(db('SELECT COUNT(*) FROM server_info WHERE id=2')=='1' and
                not any(e.get('method')=='editMessageText' for e in old['events']),
                provider+'_server_delete_older_warning_denied')
            db("UPDATE server_info SET title=CONCAT(title,' altered') WHERE id=2")
            changed=h['delete'](callback=second)
            yes(db('SELECT COUNT(*) FROM server_info WHERE id=2')=='1' and
                not any(e.get('method')=='editMessageText' for e in changed['events']),
                provider+'_server_delete_after_edit_denied')
            fresh=h['warn']()
            foreign=h['delete'](callback=fresh,actor=9002)
            yes(db('SELECT COUNT(*) FROM server_info WHERE id=2')=='1' and
                not any(e.get('method')=='editMessageText' for e in foreign['events']),
                provider+'_server_delete_wrong_actor_denied')
            yes(subject(h)==initial and len(h['writes']())==puts,
                provider+'_invalid_server_confirmations_no_financial_or_native_effect')
        # Owner Rev 4 explicitly chooses hard LOCAL deletion, accepting broken
        # order details and unresolved payment/effect recovery. No bot ledger
        # or Native data may be rewritten as an unintended side effect.
        with harness(provider) as h:
            seeded(h)
            db=h['db']
            before=subject(h);puts=len(h['writes']())
            for variant in ('yesDeleteServer02','yesDeleteServer2suffix','yesDeleteServer0'):
                bad=h['delete'](callback=variant)
                yes(db('SELECT COUNT(*) FROM server_info WHERE id=2')=='1' and
                    not any(e.get('method')=='editMessageText' for e in bad['events']),
                    provider+'_malformed_confirmation_denied_'+variant)
            issued=invoice(h,'deletepending','processing','BUY_SUB')
            db('INSERT INTO payment_attempts(pay_row_id,provider,order_hash,quote_amount,quote_currency,stage)'
               f" VALUES({issued},'nowpayments','server-delete-{provider}',137,'USD','review')")
            db("INSERT INTO service_effects(source_kind,source_ref,ordinal,operation_id,"
               "target_server_id,target_key,endpoint_sha256,plan_id,remote_username,"
               "requested_expire,requested_bytes,request_sha256,state) VALUES("
               f"'review','force-remove-{provider}',91,'{'d'*32}',2,'remove-{provider}',"
               f"'{'e'*64}',119,'{h['remark']}',1800000000,1024,'{'f'*64}','dispatching')")
            journal=int(db('SELECT MAX(id) FROM service_plan_changes'))
            db(f"UPDATE service_plan_changes SET state='review' WHERE id={journal}")
            stored=db('SELECT id,state FROM pays ORDER BY id;'
                      'SELECT id,stage FROM payment_attempts ORDER BY id;'
                      'SELECT id,source_kind,source_ref,state FROM service_effects ORDER BY id;'
                      'SELECT id,state,intent_json FROM service_plan_changes ORDER BY id;'
                      'SELECT * FROM server_plans ORDER BY id')
            valid_confirmation=h['warn']()
            result=h['delete'](callback=valid_confirmation)
            edits=[e for e in result['events'] if e.get('method')=='editMessageText']
            yes(db('SELECT COUNT(*) FROM server_info WHERE id=2')=='0' and
                db('SELECT COUNT(*) FROM server_config WHERE id=2')=='0' and
                subject(h)==before and len(h['writes']())==puts and
                db('SELECT id,state FROM pays ORDER BY id;'
                   'SELECT id,stage FROM payment_attempts ORDER BY id;'
                   'SELECT id,source_kind,source_ref,state FROM service_effects ORDER BY id;'
                   'SELECT id,state,intent_json FROM service_plan_changes ORDER BY id;'
                   'SELECT * FROM server_plans ORDER BY id')==stored and
                len(edits)==1 and 'حذف شد' in edits[0]['body'],
                provider+'_admin_hard_delete_preserves_all_order_finance_native_journal_evidence')
            replay=h['delete'](callback=valid_confirmation)
            yes(not any(e.get('method')=='editMessageText' for e in replay['events']),
                provider+'_deleted_server_old_confirmation_is_stale')
            plan(h,120)
            yes(len(h['writes']())==puts and outstanding(h)=='review',
                provider+'_pending_plan_change_after_deleted_connection_never_replays_put')

        # Full local deletion is allowed even with noncommitted Native change.
        # The real loopback PUT may have reached Native already, so its journal
        # must remain reviewable rather than pretending a successful commit.
        with harness(provider) as h:
            seeded(h)
            attempts=[]
            def concurrent_delete():
                if not attempts:
                    attempts.append(h['delete']())
            baseline_handler=h['vendor'].RequestHandlerClass
            class ReviewerPutHook(baseline_handler):
                def handle_request(self):
                    if self.command=='PUT' and getattr(self.server,'on_reviewer_put',None):
                        hook=self.server.on_reviewer_put
                        self.server.on_reviewer_put=None
                        hook()
                    return super().handle_request()
            h['vendor'].RequestHandlerClass=ReviewerPutHook
            h['vendor'].on_reviewer_put=concurrent_delete
            puts=len(h['writes']())
            plan(h,120)
            yes(len(attempts)==1 and len(h['writes']())==puts+1 and
                h['db']('SELECT COUNT(*) FROM server_config WHERE id=2')=='0' and
                h['db']('SELECT COUNT(*) FROM server_info WHERE id=2')=='0' and
                outstanding(h)!='committed' and
                any(e.get('method')=='editMessageText' for e in attempts[0]['events']),
                provider+'_admin_delete_during_native_put_requires_review_no_false_commit')
            plan(h,120)
            yes(len(h['writes']())==puts+1 and outstanding(h)!='committed',
                provider+'_deleted_native_target_never_replayed_after_ambiguous_put')

        with harness(provider) as h:
            seeded(h)
            db=h['db']
            yes(h['call']('review_invoice',server=2,plan=119,
                         order=h['oid'],type='BUY_SUB')=='issued',
                provider+'_state1_allows_new_purchase_invoice')
            yes(h['call']('review_invoice',server=2,plan=119,
                         order=h['oid'],type='RENEW_ACCOUNT')=='issued',
                provider+'_state1_allows_existing_renewal_invoice')

        with harness(provider) as h:
            seeded(h)
            db=h['db']
            db("UPDATE server_info SET state=0 WHERE id=2")
            status=h['call']('review_commerce',server=2,plan=119,order=h['oid'])
            yes(status['buy'] is False and status['renew'] is False and
                status['selected_renew_plan'] is False,
                provider+'_state0_disables_sales_and_existing_service_actions')
            yes(h['call']('review_invoice',server=2,plan=119,
                         order=h['oid'],type='BUY_SUB')=='denied' and
                h['call']('review_invoice',server=2,plan=119,
                          order=h['oid'],type='RENEW_ACCOUNT')=='denied',
                provider+'_state0_financial_draft_admission_denied')

            db("UPDATE server_info SET state=2 WHERE id=2;UPDATE server_plans SET active=0 WHERE id=119")
            status=h['call']('review_commerce',server=2,plan=119,order=h['oid'])
            yes(status['buy'] is False and status['renew'] is True and
                status['new_plan'] is False and status['saved_plan'] is True and
                status['selected_renew_plan'] is True,
                provider+'_state2_inactive_saved_plan_remains_renewable_only')
            yes(h['call']('review_invoice',server=2,plan=119,
                         order=h['oid'],type='BUY_SUB')=='denied' and
                h['call']('review_invoice',server=2,plan=119,
                          order=h['oid'],type='RENEW_ACCOUNT')=='issued',
                provider+'_state2_inactive_plan_blocks_new_invoice_but_allows_own_renewal')
            db("UPDATE server_info SET state=1 WHERE id=2")
            status=h['call']('review_commerce',server=2,plan=119,order=h['oid'])
            yes(status['buy'] is True and status['new_plan'] is False and
                status['selected_renew_plan'] is True,
                provider+'_inactive_plan_does_not_block_existing_on_enabled_server')
            db("UPDATE server_plans SET active=1 WHERE id=119")
            status=h['call']('review_commerce',server=2,plan=119,order=h['oid'])
            yes(status['new_plan'] is True and status['selected_renew_plan'] is True,
                provider+'_reactivated_plan_restores_new_selection')

    for provider in ('marzban','pasarguard'):
        # A third customer obtains a valid imported-subscription preview before
        # the administrator's Native plan change. Attempt the real import
        # *during* the native account GET while the physical lease is owned
        # by the plan transaction. This must not create a new active peer
        # between the journal snapshot and its outbound access PUT.
        with harness(provider) as h:
            seeded(h)
            h['db']("INSERT INTO users(userid,name,username,refcode,wallet,date,isAdmin,first_start,step)"
                    " VALUES(1003,'Late peer','latepeer','0',50000,'1700000000',0,'sent','none')")
            h['call']('external',actor=1003,data='showUUIDLeft')
            h['call']('external',actor=1003,text=h['credential'])
            attempts=[]
            def late_import():
                attempts.append(h['call']('manual',actor=1003,data='addToOrders2'))
            if provider=='pasarguard':h['vendor'].on_read=late_import
            else:h['vendor'].on_account_get=late_import
            before=len(h['writes']())
            plan(h,120)
            yes(len(attempts)==1 and
                h['db']('SELECT COUNT(*) FROM orders_list WHERE userid=1003')=='0' and
                len(h['writes']())==before+1 and outstanding(h)=='committed' and
                h['db'](f"SELECT fileid FROM orders_list WHERE id IN ({h['oid']},{h['peer']}) ORDER BY id")=='120\n120',
                provider+'_concurrent_real_import_cannot_attach_during_native_plan_singleflight')
            # The same valid customer can attach after the completed plan has
            # released its lock: a historical successful journal is NOT a ban.
            h['call']('manual',actor=1003,data='addToOrders2')
            yes(h['db']('SELECT COUNT(*) FROM orders_list WHERE userid=1003')=='1' and
                h['db']('SELECT fileid,amount FROM orders_list WHERE userid=1003')=='0\t0',
                provider+'_completed_plan_allows_late_valid_planless_import')
            # A fresh fourth customer cannot bypass a deliberately held
            # review journal after the original callback released its lease.
            h['db']("INSERT INTO users(userid,name,username,refcode,wallet,date,isAdmin,first_start,step)"
                    " VALUES(1004,'Held peer','heldpeer','0',50000,'1700000000',0,'sent','none')")
            h['call']('external',actor=1004,data='showUUIDLeft')
            h['call']('external',actor=1004,text=h['credential'])
            h['db']("UPDATE service_plan_changes SET state='review' WHERE id=("
                    "SELECT latest FROM (SELECT MAX(id) latest FROM service_plan_changes) x)")
            h['call']('manual',actor=1004,data='addToOrders2')
            yes(h['db']('SELECT COUNT(*) FROM orders_list WHERE userid=1004')=='0' and
                outstanding(h)=='review',
                provider+'_held_review_journal_blocks_late_import_after_lock_released')

    # F3: independent real Marzban API admission evidence complements
    # PasarGuard's authenticated native preflight exact-SQL-claim proof.
    for state,label in [(0,'disabled'),(2,'existing_only')]:
        with harness('marzban') as h:
            before=len(h['writes']());db=h['db']
            yes(h['call']('review_invoice',server=2,plan=7,order=0,type='BUY_SUB')=='issued',
                'marzban_api_buy_invoice_issued_at_state1_'+label)
            pay=int(db('SELECT MAX(id) FROM pays'))
            result=h['call']('review_api_state',pay=pay,new_state=state)
            yes(result=={'preflight':True,'allowed':False} and
                db(f'SELECT state FROM pays WHERE id={pay}')=='pending' and
                len(h['writes']())==before,
                'marzban_api_final_locked_buy_claim_denies_1_to_'+str(state))
    bot=(ROOT/'bot.php').read_text()
    yes("if(preg_match('/^yesDeleteServer(\\d+)/'" not in bot,
        'legacy_unanchored_server_delete_fallback_removed_at_bot_entry')
    yes('achbotPhysicalAssertRebindSafe($connection,$entity,true,$endpointChanged)' in
        (ROOT/'src/Admin/ManagementFlow.php').read_text(),
        'production_admin_marzban_login_editor_uses_same_safe_rebind_fence')
    result=dict(complete=True,cases=len(checks),checks=checks,
        actual_php=True,disposable_mariadb=True,
        native_providers=['marzban','pasarguard'],
        panel_group_admin_independent=True,
        f2_cross_ledger_and_shared_owner=True,
        f3_native_get_to_commit_and_pre_put_races=True,
        f4_usage_preservation_positive_and_negative=True,
        import_singleflight_and_held_journal_admission=True,
        owner_rev4_hard_delete_and_state_policy=True,
        r1_legacy_delete_callback_bypass_removed=True,
        r2_terminal_payment_attempt_and_child_fences=True,
        held_journal_replay_blocked=True,production_mutations=0,live_provider_calls=0)
    if args.report:
        args.report.write_text(json.dumps(result,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps({key:value for key,value in result.items() if key!='checks'}))

if __name__=='__main__':main()
