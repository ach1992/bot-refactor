#!/usr/bin/env python3
"""AChBot shared lifecycle over actual PHP, owned MariaDB and two loopback adapters.

No installed bot, customer data, real credentials, Telegram or external service.
The sweep's financial dispatcher is a spy; the existing full renewal suite owns
reservation/debit/remote/finalization correctness. Warnings exercise real reads,
deletion fences and native delete transports against task-owned fixtures.
"""
from __future__ import annotations
import provider_storage_fixture as shared_storage
import argparse, contextlib, copy, hashlib, json, subprocess, threading, time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import unquote, urlsplit
import test_access_state as owned
import test_v6_native as native
import test_v8_native as purchase
import test_v6_panels as bodies
import test_post_v9_pasarguard_client as pg

ROOT=Path(__file__).resolve().parents[1]
BASE='2c0e728dcd71c38e20d176fe2577bc264d3faa1a'
NOTIFICATION_REGRESSION='72bf5ae48b7d283da995b0a13bc9804827c5900a'
DELETION_FAIRNESS_REGRESSION='5568e5e24ecc595c371c4710bf41db51bb78125d'
DB=purchase.SIDE[1]

class MarzbanHandler(BaseHTTPRequestHandler):
    def log_message(self,*args): pass
    def do_POST(self):
        assert self.path=='/api/admin/token'
        self.server.trace.append(('POST',self.path))
        if self.server.on_auth:self.server.on_auth()
        self.rfile.read(int(self.headers.get('Content-Length','0')))
        self.reply(200,{'access_token':'owned-fixture-token'})
    def do_GET(self):
        assert self.headers.get('Authorization')=='Bearer owned-fixture-token'
        self.server.trace.append(('GET',self.path))
        remark=unquote(urlsplit(self.path).path.removeprefix('/api/user/'))
        self.server.reads[remark]=self.server.reads.get(remark,0)+1
        callback=self.server.on_read
        if callback: callback(remark,self.server.reads[remark])
        user=dict(self.server.user,username=remark)
        self.reply(self.server.status,user)
    def do_DELETE(self):
        assert self.headers.get('Authorization')=='Bearer owned-fixture-token'
        self.server.trace.append(('DELETE',self.path))
        self.reply(self.server.delete_status,{'detail':'User successfully deleted'})
    def reply(self,status,value):
        raw=json.dumps(value).encode()
        self.send_response(status);self.send_header('Content-Type','application/json')
        self.send_header('Content-Length',str(len(raw)));self.end_headers();self.wfile.write(raw)

@contextlib.contextmanager
def marzban():
    s=ThreadingHTTPServer(('127.0.0.1',0),MarzbanHandler)
    s.trace=[];s.reads={};s.status=200;s.delete_status=200;s.on_read=None;s.on_auth=None
    s.user={'status':'active','expire':None,'data_limit':None,'used_traffic':0}
    t=threading.Thread(target=s.serve_forever,daemon=True);t.start()
    try:yield s,'http://127.0.0.1:'+str(s.server_address[1])
    finally:s.shutdown();t.join(5);s.server_close()

PHP=r"""<?php
error_reporting(E_ALL);
set_error_handler(static function($n,$s){if($n===E_DEPRECATED)return true;throw new ErrorException($s,0,$n);});
$_SERVER['SCRIPT_FILENAME']=__FILE__;define('ACHBOT_PG_TEST_LOOPBACK',true);
$root=$argv[1];$spec=json_decode($argv[4],true,64,JSON_THROW_ON_ERROR);$events=[];
$connection=new mysqli('localhost','root','',$argv[3],0,$argv[2]);$connection->set_charset('utf8mb4');
require_once $root.'/src/Purchase/Core.php';
require_once $root.'/src/Panel/Accounts.php';
require_once $root.'/src/Finance/ReceiptHelpers.php';
require_once $root.'/src/Panel/ServiceLifecycle.php';
require_once __DIR__.'/sweep-under-test.php';
function getMarzbanUser(...$args){return \AchBot\Panel\Accounts\getMarzbanUser(...$args);}
require_once __DIR__.'/deletion-facade-under-test.php';
function achbotSupportsPanel($type){return in_array($type,['marzban','pasarguard'],true);}
function sendMessage($message,$keys=null,$parse=null,$to=null){
 $GLOBALS['events'][]=['type'=>'send','message'=>$message,'to'=>$to,'keys'=>$keys];
 return (object)['ok'=>$GLOBALS['spec']['telegram_ok']??true];
}
function handleAutoRenewCallback($data){
 $id=(int)explode('|',$data)[2];$GLOBALS['events'][]=['type'=>'renew_dispatch_spy','order'=>$id];
 $GLOBALS['connection']->query('UPDATE orders_list SET notif=0 WHERE id='.$id);
}
$buttonValues=['my_subscriptions'=>'subscriptions','sharj'=>'wallet','back_to_main'=>'main'];
$botState=['rewardChannel'=>'','renewAutoState'=>$spec['enabled']??'on'];
$action=$spec['action'];
if ($action==='policy') {
 $remote=json_decode(json_encode($spec['remote']));
 $o=achbotServiceObservation($remote,$spec['username']??'fixture_user');
 $out=['observation'=>$o,'policy'=>achbotServiceLifecycle($o,$spec['now'])];
} elseif ($action==='grace') {
 $out=achbotServiceGraceValid($spec['order']);
} elseif ($action==='observe') {
 $o=achbotServiceObserve((int)$spec['server'],$spec['username']);
 $out=['observation'=>$o,'policy'=>achbotServiceLifecycle($o,time())];
} elseif ($action==='sweep') {
 achbotAutoRenewSweep($connection,$botState);$out=$events;
} elseif ($action==='fence') {
 $row=$connection->query('SELECT * FROM orders_list WHERE id='.(int)$spec['order'])->fetch_assoc();
 if (!empty($spec['change_cycle'])) $connection->query('UPDATE orders_list SET expire_date=expire_date+1 WHERE id='.(int)$spec['order']);
 $out=achbotAutoRenewPrepareDeletion($connection,(int)$spec['order'],$row);
} elseif ($action==='auto_reserve') {
 require_once __DIR__.'/reserve-under-test.php';
 $out=achbotAutoRenewReserve($connection,(int)$spec['order'],1001);
} elseif ($action==='record_import_origin') {
 $connection->begin_transaction();
 $id=(int)$spec['order'];
 $row=$connection->query('SELECT * FROM orders_list WHERE id='.$id.' FOR UPDATE')->fetch_assoc();
 $provider=achbotProviderType($connection,(int)$row['server_id']);
 $identity=['remote_created_at'=>'2026-10-26T05:00:00.123456Z','remote_username'=>$row['remark']];$accountId=null;
 if ($provider==='pasarguard') {
  achbotPgLoad();$account=achbotProviderAccountByOrder($connection,$id,true);
  $accountId=(int)$account['id'];
  $identity=['remote_created_at'=>$account['remote_created_at'],'remote_username'=>$row['remark'],
   'remote_user_id'=>(int)$account['remote_user_id']];
 }
 $out=achbotRecordImportedOrderOriginLocked($connection,$id,(int)$row['userid'],9001,$provider,
  (int)$row['server_id'],$row['remark'],hash('sha256','owned-import-proof:'.$id),$identity,$accountId);
 $connection->commit();
} elseif (in_array($action,['warnings','baseline','notification_baseline','deletion_baseline'],true)) {
 require match($action) {
  'warnings'=>$root.'/src/Notifications/Warnings.php',
  'baseline'=>__DIR__.'/warnings-baseline.php',
  'notification_baseline'=>__DIR__.'/warnings-notification-regression.php',
  'deletion_baseline'=>__DIR__.'/warnings-deletion-fairness-regression.php'
 };
 $out=$events;
} else throw new RuntimeException('owned_action_missing');
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
"""

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('--run-disposable',action='store_true',required=True)
    ap.add_argument('--report',type=Path)
    args=ap.parse_args();checks=[]
    def yes(value,name):
        if not value:raise AssertionError(name)
        checks.append(name)
    with owned.owned_database() as (work,env),marzban() as (mb,mb_url),marzban() as (other,other_url),pg.panel() as (pguard,pg_url):
        purchase.init(work,env,mb_url)
        (work/'lifecycle.php').write_text(PHP)
        (work/'deletion-facade-under-test.php').write_bytes(bodies.function_bodies(
            (ROOT/'config.php').read_bytes(),['deleteMarzban']).replace(b'__DIR__',repr(str(ROOT)).encode()))
        source=(ROOT/'src/Finance/AutomaticRenewal.php').read_bytes()
        first=source.index(b'function achbotAutoRenewSweep(');last=source.index(b'\n}\n',first)+3
        (work/'sweep-under-test.php').write_bytes(b'<?php\n'+source[first:last])
        first=source.index(b'function achbotAutoRenewReserve(');last=source.index(b'\n}\n',first)+3
        (work/'reserve-under-test.php').write_bytes(b'<?php\n'+source[first:last])
        old=subprocess.check_output(['git','show',BASE+':src/Notifications/Warnings.php'],cwd=ROOT)
        old=old.replace(b'__DIR__',repr(str(ROOT/'src/Notifications')).encode())
        (work/'warnings-baseline.php').write_bytes(old)
        regression=subprocess.check_output(['git','show',NOTIFICATION_REGRESSION+':src/Notifications/Warnings.php'],cwd=ROOT)
        regression=regression.replace(b'__DIR__',repr(str(ROOT/'src/Notifications')).encode())
        (work/'warnings-notification-regression.php').write_bytes(regression)
        fairness=subprocess.check_output(['git','show',DELETION_FAIRNESS_REGRESSION+':src/Notifications/Warnings.php'],cwd=ROOT)
        fairness=fairness.replace(b'__DIR__',repr(str(ROOT/'src/Notifications')).encode())
        (work/'warnings-deletion-fairness-regression.php').write_bytes(fairness)
        def sql(s):return native.sql(work,env,s,DB).strip()
        def call(action,**spec):
            proc=subprocess.run(['php','-d','display_errors=0','-d','log_errors=1',
                str(work/'lifecycle.php'),str(ROOT),str(work/'mysql.sock'),DB,
                json.dumps(dict(action=action,**spec))],cwd=work,env=env,capture_output=True,text=True,timeout=30)
            allowed=('achbot_warning_delete_observation_changed order_id=',
                     'achbot_warning_remote_delete_deferred order_id=',
                     'achbot_warning_local_delete_deferred order_id=',
                     'achbot_warning_delete_target_changed order_id=',
                     'achbot_warning_delete_grace_unverified order_id=',
                     'achbot_warning_origin_unverified order_id=')
            unexpected=[s for s in proc.stderr.splitlines() if not s.startswith(allowed)]
            if proc.returncode or unexpected:raise AssertionError((proc.returncode,proc.stdout,unexpected))
            return json.loads(proc.stdout)
        for name in ['001_marzban_panel_group_id.sql','003_payment_attempts.sql',
                     '004_automatic_renewal_attempts.sql','007_service_effects.sql','011_provider_identity.sql','012_shared_provider_storage.sql']:
            sql((ROOT/'migrations/post-v9'/name).read_text())
        sql("DELETE FROM server_config WHERE id=3;INSERT INTO server_info(id,title,ucount,remark,flag,active,state,provider_group_ids)"
            " VALUES(3,'Native fixture',1000,'native','',1,1,'[2,5]');"
            "INSERT INTO server_config(id,panel_url,ip,sni,header_type,request_header,response_header,security,tlsSettings,username,password,type)"
            " VALUES(3,'"+pg_url+"','','','none','','','none','','','','pasarguard');"
            "UPDATE server_plans SET server_id=3 WHERE id=8;"+shared_storage.connection(3,1,pg_url))
        def seed(oid=101,server=2,remark=None,notif=0,expiry=None):
            # Independent scenarios must not reuse a physical native account:
            # status=0 retired references intentionally retain review fences.
            remark='mb_user_'+str(oid) if remark is None else remark
            expiry=int(time.time())+864000 if expiry is None else expiry
            plan=7 if server==2 else 8
            sql("INSERT INTO orders_list(id,userid,token,transid,fileid,server_id,inbound_id,remark,uuid,protocol,expire_date,link,amount,status,date,notif,autoRenew)"
                f" VALUES({oid},1001,'','',{plan},{server},0,'{remark}','shared-fixture-uuid','vless',{expiry},'[]',100,1,'1700000000',{notif},'on')")
        def retire():
            sql("UPDATE orders_list SET status=0;DELETE FROM setting WHERE type IN('warnLastOrderId','AutoRenewLastOrderId','deleteLastOrderId');")
            mb.reads={};mb.on_read=None;mb.on_auth=None;mb.status=200;mb.delete_status=200
        def deletes(server):return sum(t[0]=='DELETE' for t in server.trace)
        def pg_identity():
            endpoint=hashlib.sha256(pg_url.encode()).hexdigest()
            entitlement=json.dumps(dict(data_limit=2147483648,expire=2000000000,group_ids=[2,5],status='active'),separators=(',',':'))
            snapshot=json.dumps(dict(provider='pasarguard',panel_id=1,instance_key='b'*32,endpoint_sha256=endpoint,group_ids=[2,5]),separators=(',',':'))
            sql("INSERT INTO service_effects(id,source_kind,source_ref,actor_userid,ordinal,operation_id,target_server_id,target_key,endpoint_sha256,"
                "plan_id,remote_username,requested_expire,requested_bytes,request_sha256,state,order_id,provider_snapshot)"
                " VALUES(800,'admin_bulk','bulk:9001:1',9001,1,REPEAT('a',32),3,'pg:fixture','"+endpoint+"',8,'fixture_user',2000000000,2147483648,REPEAT('c',64),'local_recorded',102,'"+snapshot+"');"
                +shared_storage.identity(800,102,1,pg_url,17,'2026-10-26T05:00:00.123456Z','fixture_user',pg.MARKER,entitlement,server_id=3,plan_id=8,existing=True))
        # Pure policy boundaries include independent unlimited dimensions and
        # reject ambiguous types, usernames or state before any side effect.
        now=1900000000
        base=dict(username='fixture_user',status='active',expire=None,data_limit=None,used_traffic=0)
        matrix=[
            ('unlimited_both',{},dict(warning='',renew=False,ended=False),True),
            ('quota_only_low',dict(data_limit=1073741824,used_traffic=1000000000),dict(warning='quota',renew=True,ended=False),True),
            ('quota_only_ended',dict(data_limit=1073741824,used_traffic=1073741824),dict(warning='quota',renew=True,ended=True),True),
            ('time_only_soon',dict(expire=now+1000),dict(warning='time',renew=True,ended=False),True),
            ('time_only_ended',dict(expire=now-1),dict(warning='time',renew=True,ended=True),True),
            ('zero_unlimited',dict(expire=0,data_limit=0),dict(warning='',renew=False,ended=False),True),
            ('disabled_expired',dict(status='disabled',expire=now-1,data_limit=10,used_traffic=10),dict(warning='',renew=False,ended=False),True),
            ('held_expired',dict(status='on_hold',expire=now-1,data_limit=10,used_traffic=10),dict(warning='',renew=False,ended=False),True),
            ('unknown_status',dict(status='error'),dict(warning='',renew=False,ended=False),False),
            ('wrong_username',dict(username='foreign'),dict(warning='',renew=False,ended=False),False),
            ('negative_expiry',dict(expire=-1),dict(warning='',renew=False,ended=False),False),
            ('fractional_quota',dict(data_limit=1.5),dict(warning='',renew=False,ended=False),False),
            ('negative_usage',dict(used_traffic=-1),dict(warning='',renew=False,ended=False),False),
            ('bool_quota',dict(data_limit=True),dict(warning='',renew=False,ended=False),False),
            ('overflow_usage',dict(used_traffic='9223372036854775808'),dict(warning='',renew=False,ended=False),False),
        ]
        for name,change,expected,verified in matrix:
            out=call('policy',remote=dict(base,**change),now=now)
            yes(out['policy']==expected and out['observation']['verified']==verified,'policy_'+name)
        grace_matrix=[
            ('canonical_after_creation',{'date':'1700000000','notif':'1700000001'},True),
            ('positive_early_epoch_without_arbitrary_year',{'date':1,'notif':2},True),
            ('zero_creation',{'date':'0','notif':'1700000001'},False),
            ('missing_creation',{'notif':'1700000001'},False),
            ('noncanonical_creation',{'date':'01700000000','notif':'1700000001'},False),
            ('deadline_at_creation',{'date':'1700000000','notif':'1700000000'},False),
            ('invalid_deadline',{'date':'1700000000','notif':'unknown'},False),
        ]
        for name,order,expected in grace_matrix:
            yes(call('grace',order=order) is expected,'grace_integrity_'+name)
        seed(remark='mb_user');seed(102,3,'fixture_user');pg_identity()
        for provider,sid,remark in [('marzban',2,'mb_user'),('pasarguard',3,'fixture_user')]:
            if provider=='marzban':mb.user.update(expire=None,data_limit=1073741824,used_traffic=1000000000,status='active')
            else:pguard.user.update(expire=None,data_limit=1073741824,used_traffic=1000000000,status='active')
            before=deletes(mb)+deletes(pguard)
            out=call('observe',server=sid,username=remark)
            yes(out['observation']['verified'] and out['observation']['expire'] is None and out['policy']['renew'] and
                out['policy']['warning']=='quota' and deletes(mb)+deletes(pguard)==before,
                provider+'_actual_http_quota_only_observation')
        mb.status=500
        yes(not call('observe',server=2,username='mb_user')['observation']['verified'],
            'marzban_valid_looking_http500_is_unknown')
        mb.status=200;pguard.mode='http_500'
        yes(not call('observe',server=3,username='fixture_user')['observation']['verified'],
            'pasarguard_http500_is_unknown')
        pguard.mode='ok'
        retire();sql('UPDATE orders_list SET status=1 WHERE id=101')
        mb.user.update(expire=None,data_limit=1073741824,used_traffic=1000000000,status='active')
        old=call('baseline')
        yes(sql('SELECT notif FROM orders_list WHERE id=101')=='0' and not old,
            'baseline_RED_quota_only_service_missed_warning')
        new=call('warnings')
        yes(sql('SELECT notif FROM orders_list WHERE id=101')=='-1' and len(new)==1,
            'new_quota_only_service_warning_delivered')
        retire();sql('UPDATE orders_list SET status=1,notif=0 WHERE id=102')
        pguard.user.update(expire=None,data_limit=1073741824,used_traffic=1000000000,status='active')
        new=call('warnings')
        yes(sql('SELECT notif FROM orders_list WHERE id=102')=='-1' and len(new)==1,
            'pasarguard_quota_only_same_warning')
        retire();seed(103,notif=0)
        result=call('warnings',telegram_ok=False)
        yes(sql('SELECT notif FROM orders_list WHERE id=103')=='-2' and len(result)==1,
            'failed_telegram_pending_retry_not_marked_delivered')
        result=call('warnings')
        yes(sql('SELECT notif FROM orders_list WHERE id=103')=='-1' and len(result)==1,
            'pending_warning_retry_success_marks_delivered')
        for provider,sid,oid,remark in [('marzban',2,101,'mb_user'),('pasarguard',3,102,'fixture_user')]:
            retire();sql('UPDATE orders_list SET status=1,notif=0 WHERE id='+str(oid))
            remote=mb.user if sid==2 else pguard.user
            remote.update(status='active',expire=int(time.time())+1800,data_limit=None,used_traffic=0)
            before_delete=deletes(mb)+deletes(pguard)
            result=call('warnings',telegram_ok=False)
            yes(sql('SELECT notif FROM orders_list WHERE id='+str(oid))=='-2' and len(result)==1 and
                deletes(mb)+deletes(pguard)==before_delete,
                provider+'_failed_near_end_warning_retains_pending_retry')
            disabled=call('sweep',enabled='off')
            yes(disabled==[] and sql('SELECT notif FROM orders_list WHERE id='+str(oid))=='-2',
                provider+'_pending_warning_preserves_auto_renew_consent_off')
            wallet_before=sql('SELECT wallet FROM users WHERE userid=1001')
            dispatched=call('sweep')
            yes(dispatched==[{'type':'renew_dispatch_spy','order':oid}] and
                sql('SELECT wallet FROM users WHERE userid=1001')==wallet_before and
                deletes(mb)+deletes(pguard)==before_delete,
                provider+'_pending_warning_does_not_suppress_strict_auto_renew_dispatch')
        for provider,sid,oid in [('marzban',2,101),('pasarguard',3,102)]:
            for ended_reason in ['expired','exhausted']:
                retire()
                initial=-2 if ended_reason=='exhausted' else 0
                sql('UPDATE orders_list SET status=1,notif='+str(initial)+' WHERE id='+str(oid))
                remote=mb.user if sid==2 else pguard.user
                remote.update(status='expired' if ended_reason=='expired' else 'limited',
                    expire=int(time.time())-100 if ended_reason=='expired' else None,
                    data_limit=None if ended_reason=='expired' else 1073741824,
                    used_traffic=0 if ended_reason=='expired' else 1073741824)
                before_delete=deletes(mb)+deletes(pguard)
                if provider=='marzban' and ended_reason=='expired':
                    regression=call('notification_baseline',telegram_ok=False)
                    yes(sql('SELECT notif FROM orders_list WHERE id='+str(oid))=='0' and len(regression)==1 and
                        deletes(mb)+deletes(pguard)==before_delete,
                        'notification_delivery_regression_RED_expired_service_never_queued')
                started=int(time.time());result=call('warnings',telegram_ok=False);finished=int(time.time())
                deadline=int(sql('SELECT notif FROM orders_list WHERE id='+str(oid)))
                yes(started+10*86400<=deadline<=finished+10*86400 and len(result)==1 and
                    sql('SELECT status FROM orders_list WHERE id='+str(oid))=='1' and
                    deletes(mb)+deletes(pguard)==before_delete,
                    provider+'_failed_'+ended_reason+'_warning_starts_full_ten_day_grace')
                result=call('warnings',telegram_ok=False)
                yes(result==[] and sql('SELECT notif FROM orders_list WHERE id='+str(oid))==str(deadline) and
                    sql('SELECT status FROM orders_list WHERE id='+str(oid))=='1' and
                    deletes(mb)+deletes(pguard)==before_delete,
                    provider+'_failed_'+ended_reason+'_warning_cannot_reset_grace_or_delete_early')
        retire();seed(104,notif=0)
        mb.user.update(expire=int(time.time())-100,status='expired',data_limit=None,used_traffic=0)
        call('warnings')
        deadline=int(sql('SELECT notif FROM orders_list WHERE id=104'))
        yes(int(time.time())+10*86400-5<=deadline<=int(time.time())+10*86400,
            'expiry_starts_common_ten_day_grace')
        for status in ['disabled','on_hold']:
            retire();seed(105 if status=='disabled' else 106,notif=0)
            mb.user.update(status=status,expire=int(time.time())-100,data_limit=100,used_traffic=100)
            call('warnings')
            yes(sql('SELECT notif FROM orders_list WHERE id=' + str(105 if status=='disabled' else 106))=='0',
                status+'_does_not_start_delete_grace')
        # Unknown legacy/pre-order deadlines are not repaired or interpreted
        # as permission to delete. Both actual adapters remain uncalled and
        # the target's complete order/identity/financial state is unchanged.
        for provider,oid,created,deadline in [
            ('marzban',101,'1700000000',1),
            ('pasarguard',102,'unknown-created-at',int(time.time())-1),
        ]:
            retire()
            sql("UPDATE orders_list SET status=1,date='"+created+"',notif="+str(deadline)+" WHERE id="+str(oid))
            sql("INSERT INTO setting(type,value) VALUES('warnLastOrderId','0')")
            mb.user.update(status='expired',expire=int(time.time())-100,data_limit=None,used_traffic=0)
            pguard.user.update(status='expired',expire=int(time.time())-100,data_limit=None,used_traffic=0)
            def grace_snapshot():
                return sql('SELECT * FROM orders_list WHERE id='+str(oid)+
                    ';SELECT * FROM service_effects WHERE provider_reference_id IS NOT NULL AND order_id='+str(oid)+
                    ';SELECT userid,wallet FROM users ORDER BY userid;SELECT * FROM pays ORDER BY id'+
                    ";SELECT * FROM setting WHERE type='warnLastOrderId'")
            before=grace_snapshot();requests=(len(mb.trace),len(pguard.trace));before_delete=deletes(mb)+deletes(pguard)
            result=call('warnings')
            yes(result==[] and grace_snapshot()==before and requests==(len(mb.trace),len(pguard.trace)) and
                deletes(mb)+deletes(pguard)==before_delete,
                provider+('_pre_order_deadline' if deadline==1 else '_unknown_creation')+'_defers_without_remote_or_data_mutation')
            # Reset only this disposable fixture for later valid-deadline cases.
            sql("UPDATE orders_list SET date='1700000000' WHERE id="+str(oid))
        # All warning updates target one order, even when legacy UUIDs alias.
        retire()
        for i in range(200,301):seed(i,remark='page_'+str(i))
        mb.user.update(status='active',expire=None,data_limit=1073741824,used_traffic=1000000000)
        call('warnings')
        yes(sql('SELECT COUNT(*) FROM orders_list WHERE status=1 AND notif=0')=='1',
            'warning_keyset_first_batch_never_uuid_wide_update')
        call('warnings')
        yes(sql('SELECT COUNT(*) FROM orders_list WHERE status=1 AND notif=-1')=='101',
            'warning_keyset_no_skipped_mutating_rows')
        # The real sweep chooses identically from both adapters. Its dispatcher
        # is intentionally a spy so this suite never performs financial effects.
        retire();sql('UPDATE orders_list SET status=1,notif=-1 WHERE id IN(101,102)')
        before_wallet=sql('SELECT wallet FROM users WHERE userid=1001')
        dispatched=call('sweep')
        yes([e['order'] for e in dispatched]==[101,102] and sql('SELECT wallet FROM users WHERE userid=1001')==before_wallet,
            'same_quota_only_auto_renew_trigger_for_both_adapters')
        retire();sql('UPDATE orders_list SET status=1,notif=-1 WHERE id BETWEEN 200 AND 300')
        results=call('sweep')+call('sweep')+call('sweep')
        yes(len(results)==101 and len({e['order'] for e in results})==101 and
            sql('SELECT COUNT(*) FROM orders_list WHERE status=1 AND notif<>0')=='0',
            'auto_renew_keyset_no_skipped_fulfilled_rows')
        retire();seed(107,notif=int(time.time())-1)
        before=deletes(mb);mb.user.update(status='expired',expire=int(time.time())-100,data_limit=None,used_traffic=0)
        mb.on_read=lambda remark,count:sql('UPDATE orders_list SET expire_date=expire_date+1 WHERE id=107') if remark=='mb_user_107' and count==1 else None
        call('warnings')
        yes(sql('SELECT status FROM orders_list WHERE id=107')=='1' and deletes(mb)==before,
            'concurrent_local_renewal_cycle_blocks_delete_reservation')
        retire();seed(108,notif=int(time.time())-1)
        before=deletes(mb)
        def renewed_after_fence(remark,count):
            if count==2:mb.user.update(expire=int(time.time())+86400*30)
        mb.on_read=renewed_after_fence
        call('warnings')
        yes(sql('SELECT status FROM orders_list WHERE id=108')=='0' and deletes(mb)==before,
            'post_fence_remote_renewal_retains_review_fence_no_delete')
        # A server edit may point the same logical ID at another real panel.
        # Capture the original physical target before observation and refuse a
        # changed endpoint before reservation, after the fence, and after auth.
        for phase,oid in [('before_fence',113),('after_fence',114),('during_delete_auth',115)]:
            retire();seed(oid,remark='swap_'+phase,notif=int(time.time())-1)
            mb.user.update(status='expired',expire=int(time.time())-100,data_limit=None,used_traffic=0)
            prior_a=deletes(mb);prior_b=deletes(other)
            swap=lambda:sql("UPDATE server_config SET panel_url='"+other_url+"' WHERE id=2")
            if phase=='during_delete_auth':
                def on_auth():
                    if mb.reads.get('swap_'+phase,0)>=2:swap()
                mb.on_auth=on_auth
            else:
                threshold=1 if phase=='before_fence' else 2
                mb.on_read=lambda remark,count:swap() if count==threshold else None
            call('warnings')
            yes(deletes(mb)==prior_a and deletes(other)==prior_b and
                sql('SELECT status FROM orders_list WHERE id='+str(oid))==('1' if phase=='before_fence' else '0'),
                'endpoint_swap_'+phase+'_never_deletes_other_panel')
            sql("UPDATE server_config SET panel_url='"+mb_url+"' WHERE id=2")
        retire();seed(109,notif=int(time.time())-1)
        mb.user.update(status='expired',expire=int(time.time())-100)
        sql("INSERT INTO pays(hash_id,user_id,type,order_id,state,price,request_date) VALUES('pending-fixture',1001,'RENEW_ACCOUNT',109,'processing_wallet',100,1700000000)")
        before=deletes(mb);call('warnings')
        yes(sql('SELECT status FROM orders_list WHERE id=109')=='1' and deletes(mb)==before,
            'inflight_wallet_effect_blocks_scheduled_delete')
        retire();seed(110,notif=int(time.time())-1)
        sql("INSERT INTO service_effects(source_kind,source_ref,actor_userid,ordinal,operation_id,target_server_id,target_key,endpoint_sha256,"
            "plan_id,remote_username,requested_expire,requested_bytes,request_sha256,state)"
            " VALUES('admin_bulk','bulk:9001:2',9001,1,REPEAT('d',32),2,'server:2',REPEAT('e',64),7,'mb_user_110',2000000000,1073741824,REPEAT('f',64),'dispatching')")
        before=deletes(mb);call('warnings')
        yes(sql('SELECT status FROM orders_list WHERE id=110')=='1' and deletes(mb)==before,
            'unresolved_create_effect_same_account_blocks_scheduled_delete')
        sql("UPDATE service_effects SET state='local_recorded' WHERE source_ref='bulk:9001:2'")
        for sid,oid,remark in [(2,111,'delete_mb'),(3,102,'fixture_user')]:
            retire()
            if oid==102:sql('UPDATE orders_list SET status=1,notif='+str(int(time.time())-1)+' WHERE id=102')
            else:seed(oid,server=sid,remark=remark,notif=int(time.time())-1)
            mb.user.update(status='expired',expire=int(time.time())-100,data_limit=None,used_traffic=0)
            pguard.user.update(status='expired',expire=int(time.time())-100,data_limit=None,used_traffic=0)
            server=mb if sid==2 else pguard;before=deletes(server)
            call('warnings')
            yes(deletes(server)==before+1 and sql('SELECT COUNT(*) FROM orders_list WHERE id='+str(oid))=='0',
                ('marzban' if sid==2 else 'pasarguard')+'_real_native_delete_once_after_common_grace')
            call('warnings')
            yes(deletes(server)==before+1,'native_delete_replay_inert_'+str(sid))
        retire();seed(112,remark='delete_unknown',notif=int(time.time())-1);mb.delete_status=500
        before=deletes(mb);call('warnings');call('warnings')
        yes(deletes(mb)==before+1 and sql('SELECT status FROM orders_list WHERE id=112')=='0',
            'uncertain_native_delete_never_retried_automatically')
        yes(sql('SELECT wallet FROM users WHERE userid=1001')==before_wallet,'lifecycle_suite_no_wallet_mutations')
        # Source-bound RED: the previous oldest-first LIMIT50 repeatedly
        # selects the same deferred rows and never reaches a later valid target.
        # Cursor updates are permitted metadata; protected service/payment rows
        # and provider identities must remain unchanged while a row defers.
        def delete_cursor():
            return int(sql("SELECT value FROM setting WHERE type='deleteLastOrderId' LIMIT 1") or '0')
        def deferred_snapshot(first,last):
            return sql('SELECT * FROM orders_list WHERE id BETWEEN '+str(first)+' AND '+str(last)+' ORDER BY id;'+
                'SELECT * FROM service_effects WHERE provider_reference_id IS NOT NULL AND order_id BETWEEN '+str(first)+' AND '+str(last)+' ORDER BY id;'+
                'SELECT * FROM service_effects WHERE order_id BETWEEN '+str(first)+' AND '+str(last)+' ORDER BY id;'+
                'SELECT * FROM pays WHERE order_id BETWEEN '+str(first)+' AND '+str(last)+' ORDER BY id;'+
                'SELECT userid,wallet FROM users ORDER BY userid')
        def fair_pg_target(oid,remote_id,effect_id,hex_digit):
            remark='fair_pg_'+str(oid);marker=hex_digit*32
            seed(oid,server=3,remark=remark,notif=int(time.time())-1)
            pguard.user=copy.deepcopy(pg.USER)
            pguard.user.update(id=remote_id,username=remark,note='achbot:v1:'+marker,
                status='expired',expire=int(time.time())-100,data_limit=None,used_traffic=0)
            pguard.mode='ok'
            endpoint=hashlib.sha256(pg_url.encode()).hexdigest()
            entitlement=json.dumps(dict(data_limit=2147483648,expire=2000000000,group_ids=[2,5],status='active'),separators=(',',':'))
            snapshot=json.dumps(dict(provider='pasarguard',panel_id=1,instance_key='b'*32,endpoint_sha256=endpoint,group_ids=[2,5]),separators=(',',':'))
            sql("INSERT INTO service_effects(id,source_kind,source_ref,actor_userid,ordinal,operation_id,target_server_id,target_key,endpoint_sha256,"
                "plan_id,remote_username,requested_expire,requested_bytes,request_sha256,state,order_id,provider_snapshot)"
                f" VALUES({effect_id},'admin_bulk','bulk:9001:{oid}',9001,1,'{marker}',3,'pg:fixture','{endpoint}',8,'{remark}',2000000000,2147483648,"
                f"REPEAT('c',64),'local_recorded',{oid},'{snapshot}');"
                +shared_storage.identity(effect_id,oid,1,pg_url,remote_id,'2026-10-26T05:00:00.123456Z',remark,'achbot:v1:'+marker,entitlement,server_id=3,plan_id=8,existing=True))
        retire()
        for oid in range(400,450):seed(oid,remark='fair_mb_wait_'+str(oid),notif=1)
        fair_pg_target(500,50,1400,'1')
        held=deferred_snapshot(400,449)
        target=sql('SELECT * FROM orders_list WHERE id=500;SELECT * FROM service_effects WHERE provider_reference_id IS NOT NULL AND order_id=500')
        requests=(len(mb.trace),len(pguard.trace));before_delete=(deletes(mb),deletes(pguard))
        first=call('deletion_baseline');second=call('deletion_baseline')
        yes(first==[] and second==[] and deferred_snapshot(400,449)==held and
            sql('SELECT * FROM orders_list WHERE id=500;SELECT * FROM service_effects WHERE provider_reference_id IS NOT NULL AND order_id=500')==target and
            requests==(len(mb.trace),len(pguard.trace)) and before_delete==(deletes(mb),deletes(pguard)),
            'deletion_fairness_5568_RED_same_fifty_deferred_rows_starve_valid_native_target')
        first=call('warnings')
        yes(first==[] and delete_cursor()==449 and deferred_snapshot(400,449)==held and
            sql('SELECT * FROM orders_list WHERE id=500;SELECT * FROM service_effects WHERE provider_reference_id IS NOT NULL AND order_id=500')==target and
            requests==(len(mb.trace),len(pguard.trace)) and before_delete==(deletes(mb),deletes(pguard)),
            'deletion_fairness_marzban_deferred_batch_advances_without_target_or_payment_changes')
        call('warnings')
        yes(delete_cursor()==0 and deferred_snapshot(400,449)==held and
            sql('SELECT COUNT(*) FROM orders_list WHERE id=500')=='0' and
            deletes(mb)==before_delete[0] and deletes(pguard)==before_delete[1]+1,
            'deletion_fairness_next_tick_reaches_native_pasarguard_after_fifty_marzban_deferrals')
        requests=(len(mb.trace),len(pguard.trace));before_delete=(deletes(mb),deletes(pguard))
        call('warnings')
        yes(delete_cursor()==449 and deferred_snapshot(400,449)==held and
            requests==(len(mb.trace),len(pguard.trace)) and before_delete==(deletes(mb),deletes(pguard)),
            'deletion_fairness_wrap_revisits_deferred_rows_without_unsafe_effect')
        # Reverse direction: fifty PasarGuard rows with unknown creation dates
        # must not hide a later eligible Marzban account. Invalid grace means
        # neither adapter is called for those deferred records.
        retire()
        for oid in range(900,950):seed(oid,server=3,remark='fair_pg_wait_'+str(oid),notif=int(time.time())-1)
        sql("UPDATE orders_list SET date='unknown-created-at' WHERE id BETWEEN 900 AND 949")
        seed(1050,remark='fair_mb_1050',notif=int(time.time())-1)
        mb.user.update(status='expired',expire=int(time.time())-100,data_limit=None,used_traffic=0)
        held=deferred_snapshot(900,949);requests=(len(mb.trace),len(pguard.trace));before_delete=(deletes(mb),deletes(pguard))
        first=call('warnings')
        yes(first==[] and delete_cursor()==949 and deferred_snapshot(900,949)==held and
            sql('SELECT COUNT(*) FROM orders_list WHERE id=1050')=='1' and
            requests==(len(mb.trace),len(pguard.trace)) and before_delete==(deletes(mb),deletes(pguard)),
            'deletion_fairness_pasarguard_deferred_batch_advances_without_provider_calls')
        call('warnings')
        yes(delete_cursor()==0 and deferred_snapshot(900,949)==held and
            sql('SELECT COUNT(*) FROM orders_list WHERE id=1050')=='0' and
            deletes(mb)==before_delete[0]+1 and deletes(pguard)==before_delete[1],
            'deletion_fairness_next_tick_reaches_native_marzban_after_fifty_pasarguard_deferrals')
        # A real, valid-grace financial hold also remains untouched yet cannot
        # monopolize the queue. These rows reach the native GET and actual
        # pending-payment deletion guard, unlike the invalid-grace controls.
        retire()
        for oid in range(1200,1250):
            seed(oid,remark='fair_pending_'+str(oid),notif=int(time.time())-1)
            sql("INSERT INTO pays(hash_id,user_id,type,order_id,state,price,request_date)"
                f" VALUES('fairpending{oid}',1001,'RENEW_ACCOUNT',{oid},'processing_wallet',100,1700000000)")
        fair_pg_target(1300,51,1401,'2')
        mb.user.update(status='expired',expire=int(time.time())-100,data_limit=None,used_traffic=0)
        held=deferred_snapshot(1200,1249);before_delete=(deletes(mb),deletes(pguard))
        mb_gets=sum(t[0]=='GET' for t in mb.trace);pg_requests=len(pguard.trace)
        first=call('warnings')
        yes(first==[] and delete_cursor()==1249 and deferred_snapshot(1200,1249)==held and
            sql('SELECT COUNT(*) FROM orders_list WHERE id=1300')=='1' and len(pguard.trace)==pg_requests and
            sum(t[0]=='GET' for t in mb.trace)==mb_gets+50 and before_delete==(deletes(mb),deletes(pguard)),
            'deletion_fairness_fifty_actual_financial_deferrals_preserve_all_held_data_and_advance')
        call('warnings')
        yes(delete_cursor()==0 and deferred_snapshot(1200,1249)==held and
            sql('SELECT COUNT(*) FROM orders_list WHERE id=1300')=='0' and
            deletes(mb)==before_delete[0] and deletes(pguard)==before_delete[1]+1,
            'deletion_fairness_financial_hold_batch_cannot_starve_next_native_provider')
        # Genuine nonfinancial imports remain ordinary notification recipients
        # before and after plan selection. Use the real bounded origin writer;
        # actual URL/controller adoption is covered by the shared import suite.
        retire();seed(2000)
        fair_pg_target(2001,52,1402,'3')
        for provider,oid in [('marzban',2000),('pasarguard',2001)]:
            sql("UPDATE orders_list SET amount=0,fileid=0,autoRenew='off',notif=0 WHERE id="+str(oid))
            origin=call('record_import_origin',order=oid)
            yes(origin['kind']=='external_import' and origin['capacity_reserved'] is False,
                provider+'_warning_fixture_uses_real_import_origin_writer')
        def warning_financial_snapshot():
            return sql('SELECT * FROM users ORDER BY id;SELECT * FROM pays ORDER BY id;'+
                'SELECT * FROM automatic_renewal_attempts ORDER BY id;'+
                'SELECT * FROM service_effects ORDER BY id;SELECT * FROM service_effects WHERE provider_reference_id IS NOT NULL ORDER BY id;'+
                'SELECT id,ucount FROM server_info ORDER BY id')
        finance=warning_financial_snapshot()
        traces=(len(mb.trace),len(pguard.trace))
        for provider,sid,oid in [('marzban',2,2000),('pasarguard',3,2001)]:
            remote=mb.user if sid==2 else pguard.user
            for selection in ['planless','assigned_plan']:
                plan=0 if selection=='planless' else (7 if sid==2 else 8)
                for reason in ['time','quota']:
                    retire()
                    sql('UPDATE orders_list SET status=1,fileid='+str(plan)+',notif=0 WHERE id='+str(oid))
                    remote.update(status='active',expire=int(time.time())+1800 if reason=='time' else None,
                        data_limit=None if reason=='time' else 1073741824,
                        used_traffic=0 if reason=='time' else 1000000000)
                    native_before=copy.deepcopy(remote)
                    failed=call('warnings',telegram_ok=False)
                    yes(len(failed)==1 and sql('SELECT notif FROM orders_list WHERE id='+str(oid))=='-2' and
                        sql('SELECT amount FROM orders_list WHERE id='+str(oid))=='0',
                        provider+'_import_'+selection+'_'+reason+'_failed_warning_retries_without_paid_amount')
                    delivered=call('warnings')
                    yes(len(delivered)==1 and sql('SELECT notif FROM orders_list WHERE id='+str(oid))=='-1',
                        provider+'_import_'+selection+'_'+reason+'_retry_delivers_once')
                    yes(call('warnings')==[] and remote==native_before,
                        provider+'_import_'+selection+'_'+reason+'_delivered_warning_is_not_repeated_or_native_mutated')
        yes(warning_financial_snapshot()==finance,
            'import_warning_retry_never_writes_wallet_payment_journal_capacity_or_native_identity')
        # An amount0 account without import evidence keeps the original silent
        # trial policy. Malformed present evidence holds its row, never becoming
        # a trial marker or aborting a later valid warning in the same batch.
        retire();seed(2002)
        fair_pg_target(2003,53,1403,'4')
        finance=warning_financial_snapshot()
        for provider,sid,oid in [('marzban',2,2002),('pasarguard',3,2003)]:
            remote=mb.user if sid==2 else pguard.user
            for reason in ['time','quota']:
                retire();sql('UPDATE orders_list SET status=1,amount=0,notif=0 WHERE id='+str(oid))
                remote.update(status='active',expire=int(time.time())+1800 if reason=='time' else None,
                    data_limit=None if reason=='time' else 1073741824,
                    used_traffic=0 if reason=='time' else 1000000000)
                yes(call('warnings')==[] and sql('SELECT notif FROM orders_list WHERE id='+str(oid))=='-1' and
                    sql("SELECT COUNT(*) FROM setting WHERE type='ACHBOT_SERVICE_ORIGIN:"+str(oid)+"'")=='0',
                    provider+'_amount0_trial_'+reason+'_retains_original_no_warning_policy')
        # Malformed evidence must not touch immutable finance/native state.
        for provider,sid,oid in [('marzban',2,2002),('pasarguard',3,2003)]:
            remote=mb.user if sid==2 else pguard.user
            remote.update(status='active',expire=int(time.time())+1800,data_limit=None,used_traffic=0)
            for amount in [0,100]:
                retire()
                sql('UPDATE orders_list SET status=1,amount='+str(amount)+',notif=0 WHERE id='+str(oid))
                key='ACHBOT_SERVICE_ORIGIN:'+str(oid)
                sql("INSERT INTO setting(type,value) VALUES('"+key+"','{invalid-owned-origin')")
                seed(2100+oid+amount,remark='healthy_after_bad_'+str(oid)+'_'+str(amount))
                healthy=call('warnings')
                yes(len(healthy)==1 and 'healthy_after_bad_'+str(oid)+'_'+str(amount) in healthy[0]['message'] and
                    sql('SELECT notif FROM orders_list WHERE id='+str(oid))=='0',
                    provider+'_malformed_origin_amount'+str(amount)+'_holds_only_its_row_and_batch_continues')
                sql("DELETE FROM setting WHERE type='"+key+"'")
        yes(warning_financial_snapshot()==finance and
            not any(t[0] in ['PUT','PATCH','DELETE'] for t in mb.trace[traces[0]:]) and
            not any(t[0] in ['POST','PUT','PATCH','DELETE'] for t in pguard.trace[traces[1]:]),
            'import_and_trial_warning_paths_do_not_write_wallet_payment_journal_capacity_or_native_accounts')
        # Real automatic reservation must reject a known native/action refusal
        # before committing a payment, debit, renewal journal or physical claim.
        retire();fair_pg_target(2006,54,1404,'5')
        sql('UPDATE server_plans SET price=137,days=30,volume=2,active=1 WHERE id=8')
        def known_refusal_snapshot():
            return sql('SELECT * FROM orders_list WHERE id=2006;'+
                'SELECT * FROM users ORDER BY id;SELECT * FROM pays ORDER BY id;'+
                'SELECT * FROM automatic_renewal_attempts ORDER BY id;'+
                'SELECT * FROM payment_attempts ORDER BY id;'+
                'SELECT * FROM service_effects ORDER BY id;SELECT * FROM service_effects WHERE provider_reference_id IS NOT NULL ORDER BY id;'+
                'SELECT id,ucount FROM server_info ORDER BY id')
        for refusal in ['native_schedule','malformed_linked_history','exhausted_linked_history']:
            keys=['owned:'+str(i) for i in range(4096)]
            history=None if refusal=='native_schedule' else dict(version=1,
                key='owned:history' if refusal=='malformed_linked_history' else keys[-1],
                consumed_keys=False if refusal=='malformed_linked_history' else keys)
            history_sql='NULL' if history is None else "'"+json.dumps(history,separators=(',',':'))+"'"
            key_sql='NULL' if history is None else "'"+history['key']+"'"
            sql('UPDATE service_effects SET provider_mutation_key='+key_sql+',provider_mutation_snapshot='+history_sql+
                " WHERE order_id=2006;UPDATE orders_list SET status=1,autoRenew='on',notif=-1 WHERE id=2006")
            pguard.user.update(status='active',expire=int(time.time())+1800,
                data_limit=1073741824,used_traffic=1000000000,
                auto_delete_in_days=3 if refusal=='native_schedule' else None)
            before=known_refusal_snapshot();native_before=copy.deepcopy(pguard.user);prior=len(pguard.trace)
            result=call('auto_reserve',order=2006)
            yes(result=={'status':'ineligible'} and known_refusal_snapshot()==before and
                pguard.user==native_before and all(t[0]=='GET' for t in pguard.trace[prior:]) and
                (refusal!='native_schedule' or len(pguard.trace[prior:])>0),
                'pasarguard_auto_'+refusal+'_rejects_before_any_committed_payment_debit_journal_or_physical_claim')
            # A known refusal is also detected before the scheduled local fence.
            # Its due grace and consent remain intact for an operator to resolve.
            retire();sql("UPDATE orders_list SET status=1,autoRenew='on',notif="+str(int(time.time())-1)+' WHERE id=2006')
            pguard.user.update(status='expired',expire=int(time.time())-100,data_limit=None,used_traffic=0)
            before=known_refusal_snapshot();native_before=copy.deepcopy(pguard.user);prior=len(pguard.trace)
            result=call('warnings')
            yes(result==[] and known_refusal_snapshot()==before and pguard.user==native_before and
                all(t[0]=='GET' for t in pguard.trace[prior:]) and
                (refusal=='native_schedule' or pguard.trace[prior:]==[]),
                'pasarguard_scheduled_delete_'+refusal+'_preserves_live_reference_consent_and_all_evidence_before_fence')
        report={'complete':True,'cases':len(checks),'scenarios':checks,'baseline_red':True,'notification_delivery_regression_red':True,'grace_integrity':True,
            'deletion_fairness_red':True,'deletion_fairness_source':DELETION_FAIRNESS_REGRESSION,
            'scheduled_deletion_cursor':'deleteLastOrderId','deletion_fairness_deferred_batch':50,
            'deletion_fairness_cross_provider_directions':2,'deletion_fairness_financial_hold':True,
             'import_warning_planless_and_assigned':True,'import_warning_retry_once':True,
             'amount0_trial_warning_policy_preserved':True,'malformed_origin_warning_row_isolated':True,
             'automatic_known_refusal_predebit':True,'scheduled_known_refusal_prefence':True,
            'native_php':True,'disposable_mariadb':True,'loopback_adapters':['marzban','pasarguard'],
            'actual_delete_transports':True,'financial_dispatch':'spy_only_existing_finance_suite_separate',
            'production_mutations':0,'external_provider_calls':0}
    if args.report:args.report.write_text(json.dumps(report,indent=2))
    print(json.dumps(report))
if __name__=='__main__':main()
