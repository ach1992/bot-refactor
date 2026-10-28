#!/usr/bin/env python3
"""Actual imported-origin plan selection and consent controllers, with native loopback providers."""
from __future__ import annotations
import provider_storage_fixture as shared_storage
import argparse,copy,json,subprocess,time
from pathlib import Path
import test_access_state as owned
import test_v6_native as native
import test_v8_native as purchase
import test_v6_panels as bodies
import test_post_v9_marzban_edits as mb
import test_unified_existing_subscriptions as imports

ROOT=Path(__file__).resolve().parents[1]
DB=purchase.SIDE[1]
DRIVER=imports.PHP.replace("$botState['rewardChannel']='';", "$botState['rewardChannel']='';$botState['renewAutoState']=$spec['renew_auto_state']??'on';",1).replace(" } elseif($op==='copy'){", """ } elseif($op==='toggle'){
  require __DIR__.'/consent-source.php';
 } elseif($op==='admin_view'){
  require_once $root.'/src/Subscription/AccountDetails.php';
  $out=\\AchBot\\Subscription\\AccountDetails\\getUserOrderDetailKeys((int)$spec['order']);
 } elseif($op==='copy'){""",1)

def main():
    parser=argparse.ArgumentParser();parser.add_argument('--run-disposable',action='store_true',required=True)
    parser.add_argument('--report',type=Path);args=parser.parse_args();checks=[]
    def yes(ok,name):
        if not ok:raise AssertionError(name)
        checks.append(name)
    for provider in ['marzban','pasarguard']:
        fixture=imports.existing_marzban if provider=='marzban'else imports.existing_panel
        with owned.owned_database()as(work,env),fixture()as(vendor,url):
            purchase.init(work,env,url)
            def db(query):return native.sql(work,env,query,DB).strip()
            for migration in ['001_marzban_panel_group_id.sql','003_payment_attempts.sql',
                '004_automatic_renewal_attempts.sql','006_payment_message_refs.sql',
                '007_service_effects.sql','011_provider_identity.sql','012_shared_provider_storage.sql']:
                db((ROOT/'migrations/post-v9'/migration).read_text())
            db("INSERT INTO users(userid,name,username,refcode,wallet,date,isAdmin,first_start,step)"
               " VALUES(1002,'Foreign','foreign','0',50000,'1700000000',0,'sent','none');"
               "UPDATE server_plans SET price=137,days=30,volume=3 WHERE id=7;"
               "DELETE FROM setting WHERE type='BOT_STATES';"
               "INSERT INTO setting(type,value)VALUES('BOT_STATES','{\"walletState\":\"on\",\"gatewayAudience\":{\"wallet\":\"all\"}}')")
            if provider=='pasarguard':
                db("UPDATE server_info SET provider_group_ids='[2,5]' WHERE id=2;"+shared_storage.connection(2,1,url))
                remark='existing_user_41';credential=vendor.users[41]['subscription_url']
                def writes():return [r for r in vendor.trace if r[0]!='GET']
            else:
                vendor.mode='edit-reset-verified';vendor.edit_put_count=0;vendor.edit_reset_count=0
                vendor.edit_account=copy.deepcopy(mb.INITIAL);remark='existing_plan_user'
                token=imports.mb_mint(remark,int(time.time())-60)
                vendor.edit_account.update(username=remark,created_at='2026-10-26T05:00:00.123456',
                    subscription_url='/sub/'+token,proxies={'vless':{'id':'aaaaaaaa-1111-4111-8111-111111111111','flow':'xtls-rprx-vision'}},
                    links=['vless://aaaaaaaa-1111-4111-8111-111111111111@host.invalid:443'],
                    excluded_inbounds={},next_plan=None,auto_delete_in_days=None,
                    on_hold_expire_duration=None,on_hold_timeout=None)
                credential=url+'/sub/'+token
                def writes():
                    return [r for r in vendor.trace if r['method']in('PUT','DELETE')or
                        (r['method']=='POST'and not r['path'].endswith('/api/admin/token'))]
            facade=bodies.function_bodies((ROOT/'config.php').read_bytes(),[
                'buildSubscriptionPresentation','buildConfigMessage','setUser','sumerize',
                'getOrderDetailKeys','getUserOrderDetailKeys','getMarzbanToken',
                'renewMarzbanUUID','deleteMarzban','changeMarzbanState','RandomString'])
            (work/'facades.php').write_bytes(facade.replace(b'__DIR__',repr(str(ROOT)).encode()))
            config=(ROOT/'config.php').read_text()
            begin=config.index("if (preg_match('/^(toggleAutoRenew|adminToggleAutoRenew)")
            end=config.index('// END create user and admin get order detail',begin)
            consent=config[begin:end].replace('__DIR__',json.dumps(str(ROOT)))
            (work/'consent-source.php').write_text('<?php\n'+consent)
            script=work/'plan-controller.php';script.write_text(DRIVER);source=500000
            def call(op,**spec):
                nonlocal source
                source+=1
                result=subprocess.run(['php','-d','display_errors=0','-d','log_errors=0',str(script),
                    str(ROOT),str(work/'mysql.sock'),DB,json.dumps(dict(op=op,source=source,**({'actor':1001}|spec)))],
                    cwd=work,env=env,capture_output=True,text=True,timeout=25)
                allowed=('achbot_existing_subscription_import_deferred reason=',
                    'achbot_existing_subscription_lookup_deferred','achbot_server_display_unavailable',
                    'achbot_plan_selection_unavailable','achbot_plan_selection_unverified',
                    'achbot_order_plan_actions_unverified','achbot_renew_consent_unverified',
                    'achbot_customer_draft_creation_deferred')
                if result.returncode or not all(line.startswith(allowed) for line in result.stderr.splitlines()):
                    raise AssertionError((provider,op,result.returncode,result.stdout,result.stderr))
                parsed=json.loads(result.stdout)
                if 'fixture_error'in parsed:raise AssertionError((provider,op,parsed))
                return parsed
            def snapshot():
                return db('SELECT * FROM users ORDER BY id;SELECT * FROM orders_list ORDER BY id;'
                    'SELECT * FROM pays ORDER BY id;SELECT * FROM setting ORDER BY id;'
                    'SELECT * FROM server_info ORDER BY id;SELECT * FROM service_effects ORDER BY id;'
                    'SELECT * FROM service_effects WHERE provider_reference_id IS NOT NULL ORDER BY id')
            def finances():
                return db('SELECT userid,wallet FROM users ORDER BY id;SELECT * FROM pays ORDER BY id;'
                    'SELECT id,ucount FROM server_info ORDER BY id')
            def attach(actor):
                call('external',actor=actor,data='showUUIDLeft')
                call('external',actor=actor,text=credential)
                call('manual',actor=actor,data='addToOrders2')
                return int(db("SELECT id FROM orders_list WHERE userid="+str(actor)+" AND remark='"+remark+"'"))
            oid=attach(1001);foreign_oid=attach(1002)
            origin_key='ACHBOT_SERVICE_ORIGIN:'+str(oid)
            origin=db("SELECT value FROM setting WHERE type='"+origin_key+"'")
            yes(origin and json.loads(origin)['kind']=='external_import',provider+'_actual_URL_import_records_genuine_shared_origin')
            yes(db(f'SELECT fileid,amount FROM orders_list WHERE id={oid}')=='0\t0',
                provider+'_import_has_no_fabricated_plan_or_paid_amount')
            # Current destination catalog: only same logical server, active, priced,
            # supported native profile and positive whole service units are eligible.
            db("INSERT INTO server_info(id,title,ucount,remark,flag,active,state) VALUES(5,'Other',10,'other','',1,1);"
               +shared_storage.clone(5,2))
            if provider=='pasarguard':db("UPDATE server_info SET provider_group_ids='[2,5]' WHERE id=5")
            for pid in range(110,118):
                db("INSERT INTO server_plans(id,fileid,catid,server_id,inbound_id,acount,limitip,title,protocol,days,volume,type,price,descr,pic,active,step,date,custom_sni,provider_group_ids)"
                   f" SELECT {pid},fileid,catid,server_id,inbound_id,acount,limitip,'Plan{pid}',protocol,days,volume,type,price,descr,pic,active,step,date,custom_sni,provider_group_ids FROM server_plans WHERE id=7")
            db("UPDATE server_plans SET active=0 WHERE id=110;"
               "UPDATE server_plans SET price=0 WHERE id=111;"
               "UPDATE server_plans SET server_id=5 WHERE id=112;"
               "UPDATE server_plans SET inbound_id=9 WHERE id=113;"
               "UPDATE server_plans SET days=0 WHERE id=114;"
               "UPDATE server_plans SET volume=0 WHERE id=115;"
               "UPDATE server_plans SET days=0.5 WHERE id=116;"
               "UPDATE server_plans SET volume=0.5 WHERE id=117")
            db("INSERT INTO server_plans(id,fileid,catid,server_id,inbound_id,acount,limitip,title,protocol,days,volume,type,price,descr,pic,active,step,date,custom_sni,provider_group_ids)"
               " SELECT 119,fileid,catid,server_id,inbound_id,acount,limitip,'Second valid plan',protocol,days,volume,type,197,descr,pic,active,step,date,custom_sni,provider_group_ids FROM server_plans WHERE id=7")
            before=finances();remote=len(writes())
            menu=call('extensions',data='changePlan_'+str(oid))
            shown=json.dumps(menu)
            yes('confirmChangePlan_'+str(oid)+'_7'in shown and
                all('confirmChangePlan_'+str(oid)+'_'+str(pid)not in shown for pid in range(110,118)),
                provider+'_actual_change_plan_menu_filters_invalid_catalogue_choices')
            yes(finances()==before and len(writes())==remote,
                provider+'_opening_plan_list_has_no_financial_capacity_or_native_effect')
            for target in [110,111,112,113,114,115,116,117,999999]:
                before=snapshot();remote=len(writes())
                call('extensions',data='confirmChangePlan_'+str(oid)+'_'+str(target))
                yes(snapshot()==before and len(writes())==remote,provider+'_invalid_destination_'+str(target)+'_no_effect')
            for data in ['changePlan_'+str(oid),'confirmChangePlan_'+str(oid)+'_7']:
                before=snapshot();remote=len(writes())
                call('extensions',actor=1002,data=data)
                yes(snapshot()==before and len(writes())==remote,
                    provider+'_another_verified_shared_owner_cannot_modify_foreign_local_order_'+data.split('_')[0])
            # Preserve the familiar common administrator selector. Authority
            # comes from the current persisted role, never a cached admin flag.
            db("UPDATE users SET isAdmin=0 WHERE userid=9002")
            for data in ['changePlan_'+str(oid),'confirmChangePlan_'+str(oid)+'_119']:
                before=snapshot();remote=len(writes())
                denied=call('extensions',actor=9002,cached_admin=True,data=data)
                yes(snapshot()==before and len(writes())==remote and
                    'confirmChangePlan_'+str(oid)+'_119'not in json.dumps(denied),
                    provider+'_revoked_cached_administrator_refused_'+data.split('_')[0])
            db("UPDATE users SET isAdmin=1 WHERE userid=9002")
            before=finances();remote=len(writes())
            offered=call('extensions',actor=9001,data='changePlan_'+str(oid))
            yes('confirmChangePlan_'+str(oid)+'_119'in json.dumps(offered) and
                finances()==before and len(writes())==remote,
                provider+'_fresh_primary_administrator_opens_familiar_foreign_order_plan_selector')
            call('extensions',actor=9001,data='confirmChangePlan_'+str(oid)+'_119')
            yes(db(f'SELECT userid,fileid,amount FROM orders_list WHERE id={oid}')=='1001\t119\t0' and
                finances()==before and len(writes())==remote and
                db("SELECT value FROM setting WHERE type='"+origin_key+"'")==origin,
                provider+'_fresh_primary_administrator_confirmation_updates_only_foreign_order_fileid')
            before=finances();remote=len(writes())
            call('extensions',actor=9002,data='confirmChangePlan_'+str(foreign_oid)+'_119')
            yes(db(f'SELECT userid,fileid,amount FROM orders_list WHERE id={foreign_oid}')=='1002\t119\t0' and
                finances()==before and len(writes())==remote,
                provider+'_fresh_delegated_administrator_can_confirm_foreign_order_without_funds_or_native_effect')
            for changed,restore,label in [
                ("UPDATE orders_list SET server_id=5 WHERE id="+str(oid),
                 "UPDATE orders_list SET server_id=2 WHERE id="+str(oid),'stale_logical_server'),
                ("UPDATE server_config SET type='retired' WHERE id=2",
                 "UPDATE server_config SET type='"+provider+"' WHERE id=2",'retired_current_provider'),
                ("UPDATE server_info SET active=0 WHERE id=2",
                 "UPDATE server_info SET active=1 WHERE id=2",'inactive_current_server')
            ]:
                db(changed)
                before=snapshot();remote=len(writes())
                call('extensions',data='confirmChangePlan_'+str(oid)+'_7')
                yes(snapshot()==before and len(writes())==remote,
                    provider+'_'+label+'_rejects_old_confirmation_without_any_effect')
                db(restore)
            before=finances();remote=len(writes())
            call('extensions',data='confirmChangePlan_'+str(oid)+'_7')
            yes(db(f'SELECT fileid,amount FROM orders_list WHERE id={oid}')=='7\t0' and
                finances()==before and len(writes())==remote and
                db("SELECT value FROM setting WHERE type='"+origin_key+"'")==origin,
                provider+'_actual_confirm_changes_only_fileid_and_preserves_nonfinancial_origin')
            screen=call('view',owner=1001,order=oid)['out'];keys=screen['keyboard']
            yes(all(action+str(oid)in keys for action in ['renewAccountAsk','increaseADay','increaseAVolume','toggleAutoRenew']),
                provider+'_selected_import_exposes_paid_renewal_finite_addons_and_owner_consent')
            admin_screen=call('admin_view',actor=9001,order=oid)['out'];keys=admin_screen['keyboard']
            yes('adminToggleAutoRenew'+str(oid)in keys and 'changePlan_'+str(oid)in keys and
                'changeFileId_'+str(oid)+'_2'not in keys,provider+'_administrator_preserves_familiar_common_plan_selection_route')
            before=snapshot();remote=len(writes())
            call('extensions',data='confirmChangePlan_'+str(oid)+'_7')
            yes(snapshot()==before and len(writes())==remote,provider+'_same_plan_confirmation_replay_is_read_only')
            # Origin absence is not permission for an amount0 trial, even if
            # the local/native reference and a real selected plan are valid.
            db("DELETE FROM setting WHERE type='"+origin_key+"'")
            for data in ['changePlan_'+str(oid),'confirmChangePlan_'+str(oid)+'_7']:
                before=snapshot();remote=len(writes())
                call('extensions',data=data)
                yes(snapshot()==before and len(writes())==remote,provider+'_amount0_without_verified_import_refuses_'+data.split('_')[0])
            trial_screen=call('view',owner=1001,order=oid)['out']
            yes(all(action+str(oid)not in trial_screen['keyboard']for action in
                ['changePlan_','renewAccountAsk','increaseADay','increaseAVolume','toggleAutoRenew']),
                provider+'_amount0_without_origin_never_gets_paid_action_visibility')
            before=snapshot();call('toggle',data='toggleAutoRenew'+str(oid))
            yes(snapshot()==before,provider+'_amount0_trial_cannot_enable_automatic_renewal')
            origin_hex=origin.encode().hex()
            db("INSERT INTO setting(type,value)VALUES('"+origin_key+"',CONVERT(UNHEX('"+origin_hex+"')USING utf8mb4))")
            # Fresh role/ownership/private framing controls the real config route.
            for spec in [dict(actor=1002,data='toggleAutoRenew'+str(oid)),
                         dict(actor=1002,data='adminToggleAutoRenew'+str(oid),cached_admin=True),
                         dict(actor=1001,data='toggleAutoRenew'+str(oid),chat_type='group')]:
                before=snapshot();remote=len(writes());call('toggle',**spec)
                yes(snapshot()==before and len(writes())==remote,provider+'_foreign_or_unprivileged_consent_refused_'+str(len(checks)))
            before=finances();remote=len(writes())
            call('toggle',data='toggleAutoRenew'+str(oid))
            yes(db(f'SELECT autoRenew,amount FROM orders_list WHERE id={oid}')=='on\t0' and
                finances()==before and len(writes())==remote,provider+'_real_config_route_enables_import_consent_without_debit_or_paid_rewrite')
            consent_labels={}
            for op,actor,callback in [('view',1001,'toggleAutoRenew'),('admin_view',9001,'adminToggleAutoRenew')]:
                screen=call(op,actor=actor,owner=1001,order=oid)['out']
                consent_labels[op]=next(button['text']for row in json.loads(screen['keyboard'])['inline_keyboard']
                    for button in row if button.get('callback_data')==callback+str(oid))
            db("UPDATE server_plans SET active=0 WHERE id=7")
            for op,actor,callback in [('view',1001,'toggleAutoRenew'),('admin_view',9001,'adminToggleAutoRenew')]:
                before=snapshot();remote=len(writes())
                screen=call(op,actor=actor,owner=1001,order=oid)['out']
                shown=[button for row in json.loads(screen['keyboard'])['inline_keyboard']
                    for button in row if button.get('callback_data')==callback+str(oid)]
                yes(len(shown)==1 and shown[0]['text']==consent_labels[op] and
                    snapshot()==before and len(writes())==remote,
                    provider+'_'+op+'_actual_retired_plan_screen_retains_same_consent_withdrawal_button')
            before=finances();remote=len(writes())
            call('toggle',data='toggleAutoRenew'+str(oid))
            yes(db(f'SELECT autoRenew FROM orders_list WHERE id={oid}')=='off' and
                finances()==before and len(writes())==remote,
                provider+'_owner_can_withdraw_consent_after_plan_is_retired')
            before=snapshot();call('toggle',data='toggleAutoRenew'+str(oid))
            yes(snapshot()==before,provider+'_retired_selected_plan_cannot_reenable_automatic_renewal')
            db("UPDATE server_plans SET active=1 WHERE id=7")
            for op,actor,callback in [('view',1001,'toggleAutoRenew'),('admin_view',9001,'adminToggleAutoRenew')]:
                call('toggle',actor=actor,data=callback+str(oid))
                before=snapshot();remote=len(writes())
                screen=call(op,actor=actor,owner=1001,order=oid,renew_auto_state='off')['out']
                shown=[button for row in json.loads(screen['keyboard'])['inline_keyboard']
                    for button in row if button.get('callback_data')==callback+str(oid)]
                yes(db(f'SELECT autoRenew FROM orders_list WHERE id={oid}')=='on' and len(shown)==1 and
                    shown[0]['text']==consent_labels[op] and snapshot()==before and len(writes())==remote,
                    provider+'_'+op+'_actual_global_off_screen_retains_same_withdrawal_button')
                before=finances();remote=len(writes())
                call('toggle',actor=actor,data=callback+str(oid),renew_auto_state='off')
                yes(db(f'SELECT autoRenew,amount FROM orders_list WHERE id={oid}')=='off\t0' and
                    finances()==before and len(writes())==remote,
                    provider+'_'+op+'_real_controller_withdraws_global_off_consent_without_financial_or_native_effect')
                before=snapshot();remote=len(writes())
                call('toggle',actor=actor,data=callback+str(oid),renew_auto_state='off')
                yes(snapshot()==before and len(writes())==remote,
                    provider+'_'+op+'_global_off_cannot_reenable_automatic_renewal')
            before=snapshot();remote=len(writes())
            db("CREATE TRIGGER owned_plan_update_reject BEFORE UPDATE ON orders_list FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='owned fixture rejection'")
            call('extensions',data='confirmChangePlan_'+str(foreign_oid)+'_7',actor=1002)
            db("DROP TRIGGER owned_plan_update_reject")
            yes(snapshot()==before and len(writes())==remote,provider+'_failed_local_plan_write_rolls_back_without_premature_effects')
            # A real renewal quote may now be issued from the assigned paid
            # product, while no payment or native change has happened yet.
            before_wallet=db('SELECT userid,wallet FROM users ORDER BY id');remote=len(writes())
            call('renewal',data='renewAccountAsk'+str(oid))
            quoted=call('renewal',data='renewAccount'+str(oid))
            quote=db(f'SELECT user_id,plan_id,order_id,price,state FROM pays WHERE order_id={oid} ORDER BY id DESC LIMIT 1')
            yes(quote==f'1001\t7\t{oid}\t137\tpending' and
                db('SELECT userid,wallet FROM users ORDER BY id')==before_wallet and len(writes())==remote and
                'prePayCheck_Renew_Wallet_'in json.dumps(quoted),
                provider+'_actual_import_to_plan_confirmation_to_owned_priced_renewal_quote')
    result=dict(complete=True,cases=len(checks),checks=checks,actual_imports=True,
        actual_change_plan_controller=True,actual_config_consent_controller=True,
        actual_native_provider_eligibility=True,existing_consent_withdrawal_visible_and_controller_checked=True,amount0_preserved_before_payment=True,
        shared_native_different_bot_owners=True,fresh_administrator_common_plan_override=True,revoked_cached_admin_refused=True,production_mutations=0,live_provider_calls=0)
    if args.report:args.report.write_text(json.dumps(result,indent=2,ensure_ascii=False)+'\n')
    print(json.dumps({key:value for key,value in result.items()if key!='checks'}))
if __name__=='__main__':main()
