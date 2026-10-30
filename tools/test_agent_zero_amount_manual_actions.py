#!/usr/bin/env python3
"""Actual fully-discounted agent purchase -> native creation -> manual renewal/add-on UI.

Task-owned socket MariaDB, loopback providers and synthetic Telegram delivery.
No imported origin or positive historical amount is fabricated.
"""
from __future__ import annotations
import provider_storage_fixture as shared_storage
import argparse,contextlib,copy,hashlib,json,subprocess,threading,time
from http.server import BaseHTTPRequestHandler,ThreadingHTTPServer
from pathlib import Path
from urllib.parse import unquote,urlsplit
import test_access_state as owned
import test_v6_native as native
import test_v8_native as purchase
import test_v6_panels as bodies
import test_post_v9_pasarguard_client as pg
import test_unified_existing_subscriptions as existing

ROOT=Path(__file__).resolve().parents[1]
DB=purchase.SIDE[1]
REGRESSION_REF='4458f3d9f7b7f1e8a7e9e09bca4b97300650eb5f'
LINK='vless://aaaaaaaa-1111-4111-8111-111111111111@host.invalid:443?type=ws#Agent'
class MarzbanHandler(BaseHTTPRequestHandler):
    def log_message(self,*args):pass
    def do_POST(self):self.handle_request()
    def do_GET(self):self.handle_request()
    def handle_request(self):
        path=unquote(urlsplit(self.path).path)
        raw=self.rfile.read(int(self.headers.get('Content-Length','0')))
        self.server.trace.append((self.command,path,raw.decode()))
        if path=='/api/admin/token' and self.command=='POST':
            value={'access_token':'fixture-token'}
        elif path=='/api/user' and self.command=='POST':
            request=json.loads(raw);self.server.user={
                **request,'status':'active','used_traffic':0,
                'subscription_url':'/sub/agent-token','links':[LINK],
                'created_at':'2026-10-28T05:00:00.123456',
                'data_limit_reset_strategy':'no_reset','next_plan':None,
                'auto_delete_in_days':None,'on_hold_expire_duration':None,'on_hold_timeout':None}
            value=copy.deepcopy(self.server.user)
        elif path.startswith('/api/user/') and self.command=='GET':
            assert self.headers.get('Authorization')=='Bearer fixture-token'
            assert path.rsplit('/',1)[1]==self.server.user['username']
            value=copy.deepcopy(self.server.user)
        else:raise AssertionError((self.command,path))
        encoded=json.dumps(value).encode()
        self.send_response(200);self.send_header('Content-Type','application/json')
        self.send_header('Content-Length',str(len(encoded)));self.end_headers();self.wfile.write(encoded)
@contextlib.contextmanager
def marzban():
    server=ThreadingHTTPServer(('127.0.0.1',0),MarzbanHandler);server.trace=[];server.user=None
    thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
    try:yield server,'http://127.0.0.1:'+str(server.server_address[1])
    finally:server.shutdown();thread.join(5);server.server_close()

# The free selector has no invoice hash; preserve the webhook's empty ambient value.
DRIVER=existing.PHP.replace("$botState['rewardChannel']='';",
    "$botState['rewardChannel']='';$botState['remark']='digits';$hash_id='';",1)
DRIVER=DRIVER.replace("try {\n $out=null;$op=$spec['op'];",r"""function sendQrFromLink(...$args){
 $GLOBALS['events'][]=['kind'=>'delivery','owner'=>$args[4]??null];return true;
}
require_once $root.'/src/Purchase/Core.php';
require_once $root.'/src/Purchase/AgentPricing.php';
try {
 $out=null;$op=$spec['op'];""",1)
DRIVER=DRIVER.replace(" if($op==='external')",r""" if($op==='select')require $root.'/src/Purchase/SelectPlan.php';
 elseif($op==='trial')require $root.'/src/Purchase/TrialProvision.php';
 elseif($op==='toggle')require __DIR__.'/consent.php';
 elseif($op==='external')""",1)
DRIVER=DRIVER.replace("  require_once $root.'/src/Subscription/AccountDetails.php';\n  $out=\\AchBot\\Subscription\\AccountDetails\\getOrderDetailKeys",
    "  require_once ($spec['detail_root']??$root).'/src/Subscription/AccountDetails.php';\n  $out=\\AchBot\\Subscription\\AccountDetails\\getOrderDetailKeys",1)

def main():
    ap=argparse.ArgumentParser();ap.add_argument('--run-disposable',action='store_true',required=True)
    ap.add_argument('--report',type=Path);args=ap.parse_args();checks=[];red=[]
    def yes(ok,label):
        if not ok:raise AssertionError(label)
        checks.append(label)
    for provider in ['marzban','pasarguard']:
        with owned.owned_database() as(work,env),(marzban()if provider=='marzban'else pg.panel())as(panel,url):
            purchase.init(work,env,url)
            def db(query):return native.sql(work,env,query,DB).strip()
            for migration in ['001_marzban_panel_group_id.sql','003_payment_attempts.sql',
                '004_automatic_renewal_attempts.sql','006_payment_message_refs.sql',
                '007_service_effects.sql','011_provider_identity.sql','012_shared_provider_storage.sql']:
                db((ROOT/'migrations/post-v9'/migration).read_text())
            db("UPDATE server_plans SET price=137,days=30,volume=3 WHERE id=7;"
               "UPDATE users SET is_agent=1,discount_percent='{\"normal\":100,\"plans\":{},\"servers\":{}}' WHERE userid=1001;"
               "INSERT INTO users(userid,name,username,refcode,wallet,date,isAdmin,first_start,step)"
               " VALUES(1002,'Foreign','foreign','0',50000,'1700000000',0,'sent','none');"
               "DELETE FROM setting WHERE type='BOT_STATES';"
               "INSERT INTO setting(type,value)VALUES('BOT_STATES','{\"walletState\":\"on\",\"gatewayAudience\":{\"wallet\":\"all\"}}');"
               "INSERT INTO increase_day(id,volume,price)VALUES(21,2,31);"
               "INSERT INTO increase_plan(id,volume,price)VALUES(22,1,47)")
            if provider=='pasarguard':
                db("UPDATE server_info SET provider_group_ids='[2,5]' WHERE id=2;"+shared_storage.connection(2,1,url))
            facades=bodies.function_bodies((ROOT/'config.php').read_bytes(),[
                'buildSubscriptionPresentation','buildConfigMessage','setUser','sumerize',
                'getOrderDetailKeys','getUserOrderDetailKeys','getMarzbanToken','addMarzbanUser',
                'generateRandomString','generateUID','RandomString'])
            (work/'facades.php').write_bytes(facades.replace(b'__DIR__',repr(str(ROOT)).encode()))
            source=(ROOT/'config.php').read_text()
            begin=source.index("if (preg_match('/^(toggleAutoRenew|adminToggleAutoRenew)")
            end=source.index('// END create user and admin get order detail',begin)
            (work/'consent.php').write_text('<?php\n'+source[begin:end].replace('__DIR__',json.dumps(str(ROOT))))
            (work/'settings').mkdir()
            driver=work/'agent-route.php';driver.write_text(DRIVER);seq=800000
            baseline=work/'baseline';(baseline/'src/Subscription').mkdir(parents=True)
            (baseline/'messages').symlink_to(ROOT/'messages',target_is_directory=True)
            for directory in ['Purchase','Panel','Presentation']:
                (baseline/'src'/directory).symlink_to(ROOT/'src'/directory,target_is_directory=True)
            archived=subprocess.check_output(['git','show',REGRESSION_REF+':src/Subscription/AccountDetails.php'],cwd=ROOT)
            (baseline/'src/Subscription/AccountDetails.php').write_bytes(archived)
            for f in (ROOT/'src/Subscription').iterdir():
                if f.name!='AccountDetails.php':(baseline/'src/Subscription'/f.name).symlink_to(f)
            def call(op,**spec):
                nonlocal seq
                seq+=1
                result=subprocess.run(['php','-d','display_errors=0','-d','log_errors=0',str(driver),
                    str(ROOT),str(work/'mysql.sock'),DB,json.dumps(dict(op=op,source=seq,**({'actor':1001}|spec)))],
                    cwd=work,env=env,capture_output=True,text=True,timeout=25)
                allowed=('achbot_order_plan_actions_unverified','achbot_renew_consent_unverified',
                    'achbot_plan_selection_unavailable','achbot_plan_selection_unverified','achbot_customer_draft_creation_deferred')
                if result.returncode or not all(line.startswith(allowed)for line in result.stderr.splitlines()):
                    raise AssertionError((provider,op,result.returncode,result.stdout,result.stderr))
                parsed=json.loads(result.stdout)
                if 'fixture_error'in parsed:raise AssertionError((provider,op,parsed))
                return parsed
            def native_writes():return len([row for row in panel.trace if row[0]!='GET' and row[1]!='/api/admin/token'])
            def stable():return db('SELECT * FROM users ORDER BY id;SELECT * FROM orders_list ORDER BY id;SELECT * FROM pays ORDER BY id;'
                'SELECT * FROM server_info ORDER BY id;SELECT * FROM service_effects ORDER BY id;SELECT * FROM service_effects WHERE provider_reference_id IS NOT NULL ORDER BY id;SELECT * FROM setting ORDER BY id')
            offered=call('select',data='selectPlan7_1_one')
            yes('freeTrial7_one'in json.dumps(offered)and db('SELECT COUNT(*) FROM pays')=='0'and native_writes()==0,
                provider+'_actual_agent100_SelectPlan_offers_existing_freeTrial_route_without_invoice_or_native_write')
            before_wallet=db('SELECT wallet FROM users WHERE userid=1001')
            call('trial',data='freeTrial7_one')
            oid=int(db('SELECT id FROM orders_list WHERE userid=1001'))
            yes(db(f'SELECT fileid,amount,agent_bought,status FROM orders_list WHERE id={oid}')=='7\t0\t1\t1'and
                db('SELECT ucount FROM server_info WHERE id=2')=='9'and db('SELECT COUNT(*) FROM pays')=='0'and
                db('SELECT wallet FROM users WHERE userid=1001')==before_wallet and native_writes()==1,
                provider+'_actual_TrialProvision_creates_finite_priced_agent_service_at_amount0_without_payment')
            yes(db(f"SELECT effect_kind,source_kind,reserved_count,state FROM service_effects WHERE order_id={oid}")=='create\tfree_trial\t1\tlocal_recorded'and
                db("SELECT COUNT(*) FROM setting WHERE type LIKE 'ACHBOT_SERVICE_ORIGIN:%'")=='0',
                provider+'_real_create_origin_is_not_import_or_fabricated_paid_history')
            db("UPDATE users SET discount_percent='{\"normal\":0,\"plans\":{},\"servers\":{}}' WHERE userid=1001")
            before=stable();writes=native_writes()
            screen=call('view',owner=1001,order=oid)['out']['keyboard']
            manual=['renewAccountAsk','increaseADay','increaseAVolume']
            yes(all(callback+str(oid)in screen for callback in manual)and
                all(callback+str(oid)not in screen for callback in ['changePlan_','toggleAutoRenew'])and stable()==before and native_writes()==writes,
                provider+'_discount_reduction_preserves_manual_renewal_day_volume_buttons_but_not_new_consent_or_plan_choice')
            old=call('view',owner=1001,order=oid,detail_root=str(baseline))['out']['keyboard']
            yes(all(callback+str(oid)not in old for callback in manual),provider+'_actual4458_renderer_RED_hides_the_previous_agent_manual_path')
            red.append(provider)
            for op,data in [('toggle','toggleAutoRenew'+str(oid)),('extensions','changePlan_'+str(oid)),
                ('extensions','confirmChangePlan_'+str(oid)+'_7')]:
                before=stable();writes=native_writes();call(op,data=data)
                yes(stable()==before and native_writes()==writes,provider+'_amount0_agent_does_not_gain_'+op+'_'+data.split('_')[0])
            before=stable();writes=native_writes()
            foreign=call('view',actor=1002,owner=1001,order=oid)
            yes(foreign['out']is None and stable()==before and native_writes()==writes,provider+'_foreign_actor_cannot_disclose_agent_order')
            for mutation,undo,label in [
                ('UPDATE server_plans SET active=0 WHERE id=7','UPDATE server_plans SET active=1 WHERE id=7','retired_plan'),
                ('UPDATE server_plans SET days=0.5 WHERE id=7','UPDATE server_plans SET days=30 WHERE id=7','invalid_plan_units'),
                (f'UPDATE orders_list SET fileid=0 WHERE id={oid}',f'UPDATE orders_list SET fileid=7 WHERE id={oid}','missing_selected_plan'),
                (f'UPDATE orders_list SET agent_bought=0 WHERE id={oid}',f'UPDATE orders_list SET agent_bought=1 WHERE id={oid}','ordinary_zero_trial'),
            ]:
                db(mutation);before=stable();writes=native_writes()
                keys=call('view',owner=1001,order=oid)['out']['keyboard']
                yes(all(callback+str(oid)not in keys for callback in manual+['changePlan_','toggleAutoRenew'])and
                    stable()==before and native_writes()==writes,provider+'_'+label+'_does_not_gain_paid_action_visibility')
                db(undo)
            saved=copy.deepcopy(panel.user)
            for patch,time_action,volume_action,label in [
                ({'expire':None,'data_limit':None},False,False,'unlimited_native_time_and_quota'),
                ({'data_limit':None},True,False,'unlimited_native_quota_only'),
                ({'used_traffic':saved['data_limit']},True,True,'finite_zero_remaining_quota'),
                ({'expire':'malformed'},False,False,'unverified_native_observation'),
            ]:
                panel.user=copy.deepcopy(saved);panel.user.update(patch)
                before=stable();writes=native_writes()
                keys=call('view',owner=1001,order=oid)['out']['keyboard']
                yes(('increaseADay'+str(oid)in keys)==time_action and
                    ('increaseAVolume'+str(oid)in keys)==volume_action and
                    ('renewAccountAsk'+str(oid)in keys)==(label!='unverified_native_observation')and
                    stable()==before and native_writes()==writes,
                    provider+'_agent_manual_relative_actions_preserve_'+label)
            panel.user=saved
            before_wallet=db('SELECT wallet FROM users WHERE userid=1001');writes=native_writes()
            call('renewal',data='renewAccountAsk'+str(oid));quoted=call('renewal',data='renewAccount'+str(oid))
            yes(db(f"SELECT user_id,plan_id,price,state FROM pays WHERE order_id={oid} AND type='RENEW_ACCOUNT'")=='1001\t7\t137\tpending'and
                'prePayCheck_Renew_Wallet_'in json.dumps(quoted),provider+'_actual_renewal_controller_quotes_current_positive_price_after_discount_reduction')
            for callback,expected in [('selectPlanDayIncrease'+str(oid)+'_21','INCREASE_DAY_'+str(oid)+'_21\t2\t0\t31'),
                ('increaseVolumePlan'+str(oid)+'_22','INCREASE_VOLUME_'+str(oid)+'_22\t0\t1\t47')]:
                call('extensions',data=callback)
                row=db(f"SELECT type,day,volume,price FROM pays WHERE order_id={oid} AND type LIKE 'INCREASE_%' ORDER BY id DESC LIMIT 1")
                yes(row==expected,provider+'_actual_manual_addon_controller_quotes_'+callback.split(str(oid))[0])
            yes(db(f'SELECT amount,agent_bought,autoRenew FROM orders_list WHERE id={oid}')=='0\t1\toff'and
                db('SELECT wallet FROM users WHERE userid=1001')==before_wallet and native_writes()==writes and
                db('SELECT ucount FROM server_info WHERE id=2')=='9'and db("SELECT COUNT(*) FROM pays WHERE state<>'pending'")=='0',
                provider+'_all_manual_quotes_preserve_amount0_wallet_capacity_consent_and_native_account')
    result=dict(complete=True,cases=len(checks),checks=checks,actual_SelectPlan_TrialProvision=True,
        actual_agent_pricing=True,actual_native_CREATE_and_readback=True,actual_renderer=True,
        actual_manual_renewal_day_volume_quotes=True,zero_amount_agent_not_import_or_new_consent=True,
        regression_ref=REGRESSION_REF,archived_renderer_sha256=hashlib.sha256(archived).hexdigest(),
        actual_archived_renderer_RED=red,production_mutations=0,live_provider_calls=0,synthetic_Telegram_delivery=True)
    if args.report:args.report.write_text(json.dumps(result,indent=2,ensure_ascii=False)+'\n')
    print(json.dumps({k:v for k,v in result.items()if k!='checks'}))
if __name__=='__main__':main()
