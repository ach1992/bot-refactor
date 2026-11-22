#!/usr/bin/env python3
"""#227 source-real original Wallet BUY_SUB/Marzban recovery: no live data.

The original Wallet CREATE is actually issued through WalletSettlement and
ServiceEffects against an isolated MariaDB. Only the later Native GET uses
an owned HTTP Marzban emulator. Recovery SQL, authorization, evidence
fingerprints, notification inserts and concurrency are current PHP source.
"""
from __future__ import annotations
import argparse, json, subprocess, threading
from concurrent.futures import ThreadPoolExecutor
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import unquote

import test_access_state as owned
import test_v6_native as native
import test_v8_native as purchase
import test_post_v9_wallet_creation as creation

ROOT=Path(__file__).resolve().parents[1]
DB=purchase.SIDE[1]

class NativeHandler(BaseHTTPRequestHandler):
    def log_message(self,*args): pass
    def _reply(self,code,obj):
        body=json.dumps(obj).encode()
        self.send_response(code)
        self.send_header('Content-Type','application/json')
        self.send_header('Content-Length',str(len(body)))
        self.end_headers();self.wfile.write(body)
    def do_POST(self):
        if self.path!='/api/admin/token':
            self.server.other_posts+=1
            return self._reply(404,{'error':'no native write allowed'})
        self.server.token_logins+=1
        self._reply(200,{'access_token':'fixturereadtoken','token_type':'bearer'})
    def do_GET(self):
        if not self.path.startswith('/api/user/'):
            return self._reply(404,{'detail':'not found'})
        self.server.gets+=1
        username=unquote(self.path[len('/api/user/'):])
        if username==getattr(self.server,'pause_username',None):
            # Pause an authenticated Native GET while the *real* original
            # completion owns the per-payment lease. Another DB session
            # must not archive that in-flight payment.
            self.server.pause_started.set()
            if not self.server.pause_release.wait(timeout=10):
                return self._reply(503,{'detail':'fixture_native_get_timeout'})
        info=self.server.accounts.get(username)
        if not info:return self._reply(404,{'detail':'not found'})
        data=dict(info)
        if self.server.wrong_note:data['note']='other-application-created'
        self._reply(200,data)

PHP=r'''<?php
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(static function($errno,$msg){
    if ($errno===E_DEPRECATED) return true;
    throw new ErrorException($msg,0,$errno);
});
if (PHP_VERSION_ID<80500 || PHP_VERSION_ID>=80600)
    throw new RuntimeException('php85_required');
$_SERVER['SCRIPT_FILENAME']=__FILE__;
$root=$argv[1];$spec=json_decode($argv[4],true,64,JSON_THROW_ON_ERROR);
$telegramWebhookSecret='fixture-wallet-original-secret-32-bytes-ok';
$botState=['subLinkState'=>'on','configLinkState'=>'off'];
$botUrl='https://fixture.invalid';$admin=9001;$connection=null;
$GLOBALS['events']=[];$GLOBALS['spec']=$spec;
function achbotNotifyAdmins($method,array $data,array $options=[]):array {
    $GLOBALS['events'][]=['type'=>'admin_report','method'=>$method,
        'text'=>(string)($data['text']??'')];
    return [['chat_id'=>9001,'response'=>(object)['ok'=>(($GLOBALS['spec']['report_ack']??true)===true)]]];
}
function achbotAdminFanoutAcknowledged(array $notices):bool {
    return $notices!==[] && array_reduce($notices,
        fn($all,$n)=>$all && ($n['response']->ok??false)===true,true);
}
function buildLinksAndSendQr(...$args) {
    if (end($args)!==false) throw new RuntimeException('early_telegram_send');
    $GLOBALS['events'][]=['type'=>'native_delivery_render'];
    return ['fixture-sub','[]','fixture-uuid',
        'اصلی لینک تاییدشده','{"inline_keyboard":[]}','vless://fixture-only'];
}
function editText(...$args) {
    $GLOBALS['events'][]=['type'=>'ui_edit','text'=>(string)$args[1],
        'keyboard'=>(string)($args[2]??'')];
    return (object)['ok'=>true];
}
function sendMessage(...$args) {
    $GLOBALS['events'][]=['type'=>'ui_send','text'=>(string)$args[0]];
    return (object)['ok'=>true];
}
function answerCallbackQuery(...$args) {
    $GLOBALS['events'][]=['type'=>'ui_callback'];
}
function sendQrFromLink(...$args) {
    $GLOBALS['events'][]=['type'=>'first_customer_delivery','user'=>$args[4]??null];
    return true;
}
require_once $root.'/src/Purchase/Core.php';
require_once $root.'/src/Finance/WalletOriginalRecovery.php';
class OwnedOriginalDB extends mysqli {
    public function commit(int $flags=0,?string $name=null): bool {
        $saved=parent::commit($flags,$name);
        if ($saved && ($GLOBALS['spec']['commit_ack_lost']??false)===true) {
            $id=(int)($GLOBALS['spec']['pay']??0);
            $q=$this->prepare('SELECT state FROM pays WHERE id=?');
            $q->bind_param('i',$id);$q->execute();
            $row=$q->get_result()->fetch_assoc();$q->close();
            if (($row['state']??null)==='paid_with_wallet')
                throw new RuntimeException('fixture_commit_ack_lost_after_sql_commit');
        }
        return $saved;
    }
}
$db=new OwnedOriginalDB('localhost','root','',$argv[3],0,$argv[2]);
$db->set_charset('utf8mb4');$connection=$db;
try {
    $op=$spec['op'];$payId=(int)($spec['pay']??0);
    $actor=(int)($spec['actor']??9001);
    $primary=9001;
    if ($op==='ui') {
        $data=(string)$spec['data'];
        $from_id=$actor;$chat_id=$actor;$callbackId='fixture-callback';
        $message_id=77;
        require $root.'/src/Finance/Routes/ManualReceipts.php';
        $result=['handled'=>true];
    } elseif ($op==='archive'){
        require_once $root.'/src/Finance/GatewayReview.php';
        $result=achbotWalletReviewArchive($db,$actor,$payId,$primary);
    } elseif ($op==='inspect'){
        $e=achbotWalletOriginalBuyInspect($db,$payId,$actor,$primary);
        unset($e['_claim'],$e['_prepared']);
        $result=$e;
    } elseif ($op==='token') {
        $e=achbotWalletOriginalBuyInspect($db,$payId,$actor,$primary);
        $result=($e['status']??null)==='ready'
            ?achbotWalletOriginalBuyToken($e,$actor):null;
    } elseif ($op==='complete') {
        $result=achbotWalletOriginalBuyComplete($db,$payId,$actor,$primary,
            (string)($spec['token']??''));
    } elseif ($op==='deliver') {
        $result=achbotDeliveryDispatch($db,$payId,1);
    } elseif ($op==='report_case') {
        $c=achbotWalletAddonReportCase($db,$payId);
        $result=['case'=>$c?array_diff_key($c,['value'=>true]):null,
            'inbox'=>achbotGatewayAttentionCaseOpen($db,$payId)];
    } elseif ($op==='reports') {
        $result=achbotWalletAddonMaintainReports($db);
    } elseif ($op==='report_token') {
        $c=achbotWalletAddonReportCase($db,$payId);
        $result=$c?achbotWalletAddonReportToken($c,$actor):null;
    } elseif ($op==='report_manual') {
        $result=achbotWalletAddonReportManualSend($db,$payId,
            $actor,$primary,(string)($spec['token']??''));
    } elseif ($op==='marzban') {
        $result=achbotWalletOriginalMarzbanInspect($db,$payId,$actor,$primary);
    } elseif ($op==='inbox') {
        $result=achbotGatewayAttentionCaseOpen($db,$payId);
    } else throw new RuntimeException('unknown_fixture_operation');
    echo json_encode(['ok'=>true,'result'=>$result,
        'events'=>$GLOBALS['events']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
} catch(Throwable $error) {
    echo json_encode(['ok'=>false,'error'=>$error->getMessage(),
        'events'=>$GLOBALS['events']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
}
'''

def test() -> dict:
    checks=[]
    server=ThreadingHTTPServer(('127.0.0.1',0),NativeHandler)
    server.accounts={};server.wrong_note=False
    server.token_logins=0;server.gets=0;server.other_posts=0
    server.pause_username=None
    server.pause_started=threading.Event()
    server.pause_release=threading.Event()
    thread=threading.Thread(target=server.serve_forever,daemon=True)
    thread.start()
    try:
        with owned.owned_database() as (work,env):
            url=f'http://127.0.0.1:{server.server_port}'
            purchase.init(work,env,url)
            def sql(q):return native.sql(work,env,q,DB).strip()
            for m in ('001_marzban_panel_group_id.sql','003_payment_attempts.sql',
                      '007_service_effects.sql','014_api_payment_evidence.sql',
                      '022_gateway_review_archive.sql',
                      '018_payment_delivery_intents.sql'):
                sql((ROOT/'migrations/post-v9'/m).read_text())
            original=work/'original-wallet-create.php'
            # The older wallet-creation mini-fixture deliberately exercises
            # legacy extra PHP arguments; this recovery case must instead
            # mirror the REAL Checkout.php insertOrder argument signature.
            creation_php=creation.PHP
            old="""            $realName,'testuuid','vless',time()+30*86400,
            'fixture-link',(int)$pay['price'],time(),0,0,true);"""
            new="""            $realName,'testuuid',time()+30*86400,
            'fixture-link',(int)$pay['price'],time(),0,true);"""
            assert creation_php.count(old)==1
            creation_php=creation_php.replace(old,new)
            first_quote="(int)$pay['price'],time(),0,true);"
            assert creation_php.count(first_quote)==1
            creation_php=creation_php.replace(first_quote,
                "achbotAllocateOrderAmount((int)$pay['price'],max(1,(int)$pay['agent_count']),1),time(),0,true);")
            original.write_text(creation_php)
            # Isolated second-ordinal original PHP variant: uses the SAME
            # production CREATE, insertOrder and effect-link functions.
            multi=work/'original-wallet-multi.php'
            end="    return true;\n}, 'standard');"
            assert creation_php.count(end)==1
            extra=r'''    if (($spec['multi']??false)===true) {
        [$remote2,$name2,$port2,$effect2]=achbotCreatePaidAccount(
            $db,$pid,2,['user_id'=>$uid,'server_type'=>'marzban',
            'server_id'=>$server,'inbound_id'=>0,'plan_id'=>$plan,
            'remark'=>substr($name,0,23).'_second','volume'=>2,'days'=>30,
            'uniqid'=>'second-original-uuid','protocol'=>'vless',
            'port'=>12346,'expire_microdate'=>0,'net_type'=>'normal',
            'rahgozar'=>0,'limitip'=>0]);
        if (!$db->begin_transaction())
            throw new RuntimeException('fixture_second_order_begin');
        try {
            if (($spec['order_failure_second']??false)===true)
                throw new RuntimeException('fixture_second_order_failed');
            $orderId2=insertOrder($db,$uid,'second-native-token',$plan,$server,0,
                $name2,'second-original-uuid',time()+30*86400,
                'second-original-link',
                achbotAllocateOrderAmount((int)$pay['price'],(int)$pay['agent_count'],2),
                time(),0,true);
            achbotLinkPaidAccountEffect($db,$effect2,$orderId2);
            if (!$db->commit())throw new RuntimeException('fixture_second_order_commit');
        } catch(Throwable $error) {
            try {$db->rollback();}catch(Throwable $ignored){}
            throw $error;
        }
    }
'''
            multi.write_text(creation_php.replace(end,extra+end))
            driver=work/'original-wallet-recover.php'
            driver.write_text(PHP)
            def execute(op,**opts):
                cp=subprocess.run([
                    'php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
                    '-d','extension=curl','-d','extension=mbstring','-d','log_errors=1','-d','error_log=/dev/stderr',
                    str(driver),str(ROOT),str(work/'mysql.sock'),DB,
                    json.dumps({'op':op,**opts})],
                    cwd=work,env=env,capture_output=True,text=True,timeout=35)
                if cp.returncode or not cp.stdout:
                    raise AssertionError(('original_php_failed',op,cp.returncode,
                                          cp.stdout[-1000:],cp.stderr[-2300:]))
                if 'fixture-wallet-original-secret' in cp.stderr:
                    raise AssertionError('wallet original fixture secret leaked')
                return json.loads(cp.stdout)
            def payment_seed(name,count=1):
                sql('UPDATE users SET wallet=50000 WHERE userid=1001')
                sql("DELETE FROM setting WHERE type='BOT_STATES'")
                sql("INSERT INTO setting(type,value) VALUES "
                    "('BOT_STATES','{\"walletState\":\"on\",\"gatewayAudience\":{\"wallet\":\"all\"}}') "
                    "ON DUPLICATE KEY UPDATE value=VALUES(value)")
                sql("INSERT INTO pays(hash_id,user_id,type,plan_id,server_id,"
                    "agent_count,price,day,volume,request_date,state) "
                    f"VALUES('{name}',1001,'BUY_SUB',7,2,{count},100,0,0,UNIX_TIMESTAMP(),'pending')")
                return int(sql(f"SELECT id FROM pays WHERE hash_id='{name}'"))
            def original_unlinked_fixture(name):
                original_pay=payment_seed(name)
                failed=creation.run(work,env,original,hash=name,order_failure=True)
                assert failed['result']['status']=='error',failed
                q=sql(f"SELECT remote_username,operation_id,requested_expire,"
                      f"requested_bytes FROM service_effects "
                      f"WHERE pay_row_id={original_pay} AND effect_kind='create'")
                uname,op,expiry,quota=q.split('\t')
                server.accounts[uname]={
                    'username':uname,'note':'achbot:v1:'+op,
                    'expire':int(expiry),'data_limit':int(quota),
                    'status':'active','used_traffic':0,
                    'subscription_url':url+'/sub/fixture-'+uname,
                    'links':['vless://fixture.invalid']}
                return original_pay,uname,int(expiry)
            pay=payment_seed('wallet-original-227')
            created=creation.run(work,env,original,hash='wallet-original-227')
            assert created['result']['status']=='ok',created
            assert sql('SELECT state FROM pays WHERE id='+str(pay))=='paid_with_wallet'
            account=sql(f"SELECT remote_username,operation_id,requested_expire,requested_bytes "
                        f"FROM service_effects WHERE pay_row_id={pay} AND effect_kind='create'")
            name,operation,expiry,bytes_=account.split('\t')
            server.accounts[name]={
                'username':name,'note':'achbot:v1:'+operation,
                'expire':int(expiry),'data_limit':int(bytes_),
                'status':'active','used_traffic':0,
                'subscription_url':url+'/sub/fixture-'+name,
                'links':['vless://fixture.invalid']}
            # This is the source-real crash window after original order+CREATE
            # commit, before the separate Wallet final state COMMIT.
            sql(f"UPDATE pays SET state='processing_wallet',request_date=UNIX_TIMESTAMP()-900 "
                f"WHERE id={pay} AND state='paid_with_wallet'")
            initial_wallet=sql('SELECT wallet FROM users WHERE userid=1001')
            initial_capacity=sql('SELECT ucount FROM server_info WHERE id=2')
            initial_order=sql('SELECT COUNT(*) FROM orders_list')
            denied=execute('inspect',pay=pay,actor=999999)
            assert denied['result']['status']=='denied',denied
            checks.append('original_buy_denies_nonstaff_source_inspection')
            server.wrong_note=True
            drift=execute('inspect',pay=pay)
            assert drift['result']['status']=='review',drift
            assert not drift['events'],drift
            assert server.other_posts==0
            checks.append('wrong_native_create_operation_note_cannot_authorize_local_finality')
            server.wrong_note=False
            preview=execute('inspect',pay=pay)
            assert preview['result']['status']=='ready',preview
            assert preview['result']['count']==1,preview
            assert preview['result']['lines'][0]['operation']==operation,preview
            checks.append('read_only_native_create_matches_exact_original_operation')
            ui=execute('ui',data='attWOriginalI_'+str(pay))
            assert ui['ok'],ui
            edits=[x for x in ui['events'] if x['type']=='ui_edit']
            assert len(edits)==1,ui
            assert '✅' in edits[0]['text'] and 'کیف پول' in edits[0]['text'],ui
            assert 'attWOriginalC_'+str(pay)+'_' in edits[0]['keyboard'],ui
            denied_ui=execute('ui',data='attWOriginalI_'+str(pay),actor=999999)
            assert not [x for x in denied_ui['events'] if x['type']=='ui_edit'],denied_ui
            checks.append('source_real_persian_operator_inspect_callback_and_restricted_confirm')
            token=execute('token',pay=pay)['result']
            assert len(token)==20,token
            forged=execute('complete',pay=pay,token='0'*20)
            assert forged['result']['status']=='review',forged
            assert sql('SELECT state FROM pays WHERE id='+str(pay))=='processing_wallet'
            assert sql('SELECT COUNT(*) FROM payment_delivery_intents')=='0'
            checks.append('original_buy_forged_token_cannot_allocate_or_settle')
            with ThreadPoolExecutor(max_workers=2) as pool:
                completed=[f.result() for f in (
                    pool.submit(execute,'complete',pay=pay,token=token),
                    pool.submit(execute,'complete',pay=pay,token=token))]
            states=[x['result']['status'] for x in completed]
            assert states.count('settled_delivery_pending')==1,completed
            assert set(states)<=set(['settled_delivery_pending','already_paid','review']),completed
            assert sql('SELECT state FROM pays WHERE id='+str(pay))=='paid_with_wallet'
            assert sql('SELECT COUNT(*) FROM payment_attempts')=='1'
            assert sql('SELECT COUNT(*) FROM payment_delivery_intents')=='1'
            assert sql('SELECT COUNT(*) FROM orders_list')==initial_order
            assert sql("SELECT COUNT(*) FROM setting WHERE type='WALLET_ADDON_REPORT:"+str(pay)+"'")=='1'
            report=execute('report_case',pay=pay)
            assert report['result']['case']['state']=='pending' and report['result']['inbox'],report
            checks.append('linked_original_wallet_customer_and_admin_reports_are_atomic')
            assert sql('SELECT wallet FROM users WHERE userid=1001')==initial_wallet
            assert sql('SELECT ucount FROM server_info WHERE id=2')==initial_capacity
            assert server.other_posts==0
            checks.append('concurrent_staff_confirmations_commit_one_payment_and_first_send')
            checks.append('original_wallet_and_capacity_order_and_native_unchanged')
            initial=execute('deliver',pay=pay)
            assert initial['result']=='acknowledged' and len(initial['events'])==1,initial
            again=execute('deliver',pay=pay)
            assert again['events']==[] and again['result']!='acknowledged',again
            assert execute('complete',pay=pay,token=token)['result']['status']=='already_paid'
            assert server.other_posts==0
            checks.append('customer_first_send_telegram_ack_once_no_replay')
            # First-send report is independent from customer delivery, and
            # an ambiguous fanout is NOT a license for Cron to resend.
            first=execute('reports',report_ack=False)
            assert first['result']['unknown']==1,first
            assert [e['type'] for e in first['events']]==['admin_report'],first
            case=execute('report_case',pay=pay)['result']
            assert case['case']['state']=='unknown_or_partial' and case['inbox'],case
            assert execute('reports')['events']==[]
            denied=execute('report_manual',pay=pay,actor=999999,token='0'*20)
            assert denied['result'] in ('denied','stale'),denied
            report_token=execute('report_token',pay=pay)['result']
            with ThreadPoolExecutor(max_workers=2) as pool:
                repeats=[f.result() for f in (
                    pool.submit(execute,'report_manual',pay=pay,token=report_token),
                    pool.submit(execute,'report_manual',pay=pay,token=report_token))]
            assert sum(len([e for e in x['events'] if e['type']=='admin_report']) for x in repeats)==1,repeats
            assert sql("SELECT JSON_UNQUOTE(JSON_EXTRACT(value,'$.state')) FROM setting WHERE type='WALLET_ADDON_REPORT:"+str(pay)+"'")=='acknowledged'
            assert execute('report_manual',pay=pay,token=report_token)['result']=='stale'
            assert execute('report_case',pay=pay)['result']['inbox'] is False
            checks.append('original_buy_report_unknown_stays_inbox_and_one_explicit_staff_resend')
            # A genuinely original Native CREATE may have verified but its
            # per-account local order SQL failed. Local restoration must
            # reuse ONLY that original evidence, never another CREATE.
            partial=payment_seed('wallet-original-unlinked-227')
            failed=creation.run(work,env,original,hash='wallet-original-unlinked-227',
                                order_failure=True)
            assert failed['result']['status']=='error',failed
            part=sql(f"SELECT remote_username,operation_id,requested_expire,requested_bytes "
                     f"FROM service_effects WHERE pay_row_id={partial} "
                     "AND effect_kind='create'")
            pname,poperation,pexpiry,pbytes=part.split('\t')
            assert sql(f"SELECT state FROM service_effects WHERE pay_row_id={partial} "
                       "AND effect_kind='create'")=='verified'
            server.accounts[pname]={
                'username':pname,'note':'achbot:v1:'+poperation,
                'expire':int(pexpiry),'data_limit':int(pbytes),
                'status':'active','used_traffic':0,
                'subscription_url':url+'/sub/fixture-'+pname,
                'links':['vless://fixture.invalid']}
            # The original crash left its verified CREATE without a local
            # record. An authorized operator may repair only that local row.
            unlinked=execute('inspect',pay=partial)
            assert unlinked['result']['status']=='ready',unlinked
            assert unlinked['result']['missing_orders']==1,unlinked
            assert unlinked['result']['lines'][0]['status']==(
                'matching_original_unlinked_recoverable'),unlinked
            assert server.other_posts==0
            before_order=sql('SELECT COUNT(*) FROM orders_list')
            before_wallet=sql('SELECT wallet FROM users WHERE userid=1001')
            before_capacity=sql('SELECT ucount FROM server_info WHERE id=2')
            token=execute('token',pay=partial)['result']
            assert len(token)==20,token
            denied=execute('complete',pay=partial,token=token,actor=999999)
            assert denied['result']['status']=='review',denied
            forged=execute('complete',pay=partial,token='0'*20)
            assert forged['result']['status']=='review',forged
            assert sql('SELECT COUNT(*) FROM orders_list')==before_order
            checks.append('unlinked_buy_actor_bound_confirmation_denies_forged_or_different_staff')
            with ThreadPoolExecutor(max_workers=2) as pool:
                done=[f.result() for f in (
                    pool.submit(execute,'complete',pay=partial,token=token),
                    pool.submit(execute,'complete',pay=partial,token=token))]
            statuses=[x['result']['status'] for x in done]
            assert statuses.count('settled_delivery_pending')==1,done
            assert set(statuses)<=set(['settled_delivery_pending','already_paid','review']),done
            assert sql(f"SELECT state FROM pays WHERE id={partial}")=='paid_with_wallet'
            assert sql(f"SELECT state FROM service_effects WHERE pay_row_id={partial} AND effect_kind='create'")=='local_recorded'
            assert sql('SELECT COUNT(*) FROM orders_list')==str(int(before_order)+1)
            assert sql('SELECT wallet FROM users WHERE userid=1001')==before_wallet
            assert sql('SELECT ucount FROM server_info WHERE id=2')==before_capacity
            assert sql(f"SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id={partial}")=='1'
            assert sql(f"SELECT COUNT(*) FROM payment_delivery_intents WHERE pay_row_id={partial}")=='1'
            assert sql(f"SELECT COUNT(*) FROM setting WHERE type='WALLET_ADDON_REPORT:{partial}'")=='1'
            assert server.other_posts==0
            checks.append('verified_native_create_restores_one_local_order_without_remote_or_finance_replay')
            checks.append('competing_original_buy_confirmations_are_idempotent')
            delivered=execute('deliver',pay=partial)
            assert delivered['result']=='acknowledged' and len(delivered['events'])==1,delivered
            assert execute('deliver',pay=partial)['events']==[]
            assert execute('complete',pay=partial,token=token)['result']['status']=='already_paid'
            checks.append('restored_buy_customer_delivery_is_once_only')
            unlinked_report=execute('reports')
            assert unlinked_report['result']['acknowledged']==1,unlinked_report
            assert len([e for e in unlinked_report['events'] if e['type']=='admin_report'])==1
            assert execute('reports')['events']==[]
            checks.append('unlinked_original_buy_admin_first_send_once')
            # Two original CREATE ordinals: first order committed, second
            # Native CREATE verified but second local order insertion crashed.
            multi_id=payment_seed('wallet-original-multi-227',count=2)
            multi_failed=creation.run(work,env,multi,
                hash='wallet-original-multi-227',multi=True,
                order_failure_second=True)
            assert len([e for e in multi_failed['events'] if e['event']=='POST'])==2,multi_failed
            assert multi_failed['result']['status']=='error',multi_failed

            originals=sql(f"SELECT ordinal,state,remote_username,operation_id,"
                          f"requested_expire,requested_bytes FROM service_effects "
                          f"WHERE pay_row_id={multi_id} AND effect_kind='create' "
                          "ORDER BY ordinal").splitlines()
            assert len(originals)==2,originals
            for entry in originals:
                ord_,state,uname,operation,expiry,bytes_=entry.split('\t')
                assert state==('local_recorded' if ord_=='1' else 'verified'),originals
                server.accounts[uname]={
                    'username':uname,'note':'achbot:v1:'+operation,
                    'expire':int(expiry),'data_limit':int(bytes_),
                    'status':'active','used_traffic':0,
                    'subscription_url':url+'/sub/fixture-'+uname,
                    'links':['vless://fixture.invalid']}
            assert sql(f"SELECT state FROM pays WHERE id={multi_id}")=='processing_wallet'
            assert sql(f"SELECT reserved_count FROM service_effects WHERE pay_row_id={multi_id} "
                       "AND effect_kind='wallet_hold'")=='2'
            before=sql('SELECT COUNT(*) FROM orders_list')
            before_balance=sql('SELECT wallet FROM users WHERE userid=1001')
            before_capacity=sql('SELECT ucount FROM server_info WHERE id=2')
            inspected=execute('inspect',pay=multi_id)
            assert inspected['result']['status']=='ready',inspected
            assert inspected['result']['missing_orders']==1,inspected
            assert [r['status'] for r in inspected['result']['lines']]==[
                'matching_original_linked','matching_original_unlinked_recoverable'],inspected
            checks.append('multi_original_buy_mixed_linked_and_verified_unlinked_preflight')
            second_name=originals[1].split('\t')[2]
            server.accounts[second_name]['note']='foreign-operation'
            foreign=execute('inspect',pay=multi_id)
            assert foreign['result']['status']=='review',foreign
            assert execute('token',pay=multi_id)['result'] is None
            server.accounts[second_name]['note']='achbot:v1:'+originals[1].split('\t')[3]
            token_multi=execute('token',pay=multi_id)['result']
            assert len(token_multi)==20,token_multi
            result=execute('complete',pay=multi_id,token=token_multi)
            assert result['result']['status']=='settled_delivery_pending',result
            assert sql('SELECT COUNT(*) FROM orders_list')==str(int(before)+1)
            assert sql(f"SELECT state FROM pays WHERE id={multi_id}")=='paid_with_wallet'
            assert sql(f"SELECT state FROM service_effects WHERE pay_row_id={multi_id} "
                       "AND effect_kind='create' ORDER BY ordinal")==(
                       'local_recorded\nlocal_recorded')
            assert sql(f"SELECT COUNT(*) FROM payment_delivery_intents WHERE pay_row_id={multi_id}")=='2'
            assert sql(f"SELECT GROUP_CONCAT(ordinal ORDER BY ordinal) "
                       f"FROM payment_delivery_intents WHERE pay_row_id={multi_id}")=='1,2'
            assert sql(f"SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id={multi_id}")=='1'
            assert sql(f"SELECT COUNT(*) FROM setting WHERE type='WALLET_ADDON_REPORT:{multi_id}'")=='1'
            assert sql(f"SELECT COUNT(DISTINCT order_id) FROM service_effects "
                       f"WHERE pay_row_id={multi_id} AND effect_kind='create'")=='2'
            assert sql(f"SELECT SUM(amount) FROM orders_list WHERE id IN "
                       f"(SELECT order_id FROM service_effects WHERE pay_row_id={multi_id} "
                       "AND effect_kind='create')")=='100'
            assert sql('SELECT wallet FROM users WHERE userid=1001')==before_balance
            assert sql('SELECT ucount FROM server_info WHERE id=2')==before_capacity
            assert server.other_posts==0
            checks.append('multi_original_buy_recovers_one_missing_local_without_cross_ordinal_borrow')
            dispatch=execute('reports')
            assert dispatch['result']['acknowledged']==1,dispatch
            assert len([e for e in dispatch['events'] if e['type']=='admin_report'])==1,dispatch
            text=[e['text'] for e in dispatch['events'] if e['type']=='admin_report'][0]
            assert originals[0].split('\t')[2] in text and second_name in text,text
            assert execute('reports')['events']==[]
            assert execute('complete',pay=multi_id,token=token_multi)['result']['status']=='already_paid'
            checks.append('multi_original_wallet_one_admin_summary_and_two_customer_intents')
            # Original CREATE HTTP reply was lost after Native applied:
            # effect remained dispatching and no local order was written.
            # Authorized staff may verify the SAME operation via strict GET
            # before restoring local finality; untrusted callback cannot.
            lost_id=payment_seed('wallet-original-lost-ack-227')
            lost=creation.run(work,env,original,
                hash='wallet-original-lost-ack-227',remote='lost')
            assert lost['result']['status']=='error',lost
            line=sql(f"SELECT state,remote_username,operation_id,requested_expire,"
                     f"requested_bytes FROM service_effects WHERE pay_row_id={lost_id} "
                     "AND effect_kind='create'").split('\t')
            assert line[0]=='dispatching',line
            server.accounts[line[1]]={
                'username':line[1],'note':'achbot:v1:'+line[2],
                'expire':int(line[3]),'data_limit':int(line[4]),
                'status':'active','used_traffic':0,
                'subscription_url':url+'/sub/fixture-'+line[1],
                'links':['vless://fixture.invalid']}
            proof=execute('inspect',pay=lost_id)
            assert proof['result']['status']=='ready' and proof['result']['missing_orders']==1,proof
            assert sql(f"SELECT state FROM service_effects WHERE pay_row_id={lost_id} "
                       "AND effect_kind='create'")=='dispatching'
            forged=execute('complete',pay=lost_id,token='0'*20)
            assert forged['result']['status']=='review',forged
            assert sql(f"SELECT state FROM service_effects WHERE pay_row_id={lost_id} "
                       "AND effect_kind='create'")=='dispatching'
            actual=execute('complete',pay=lost_id,token=execute('token',pay=lost_id)['result'])
            assert actual['result']['status']=='settled_delivery_pending',actual
            assert sql(f"SELECT state FROM service_effects WHERE pay_row_id={lost_id} "
                       "AND effect_kind='create'")=='local_recorded'
            assert sql(f"SELECT state FROM pays WHERE id={lost_id}")=='paid_with_wallet'
            assert sql(f"SELECT COUNT(*) FROM payment_delivery_intents WHERE pay_row_id={lost_id}")=='1'
            assert sql(f"SELECT COUNT(*) FROM setting WHERE type='WALLET_ADDON_REPORT:{lost_id}'")=='1'
            assert server.other_posts==0
            checks.append('lost_native_create_ack_verified_by_staff_no_second_post_or_debit')

            # A reversible SQL failure during restoration must roll back
            # the inserted LOCAL record and ALL original delivery/report
            # obligations, leaving the held claim available for a retry.
            fail_id=payment_seed('wallet-original-fault-227')
            failed=creation.run(work,env,original,
                hash='wallet-original-fault-227',order_failure=True)
            assert failed['result']['status']=='error',failed
            line=sql(f"SELECT remote_username,operation_id,requested_expire,"
                     f"requested_bytes FROM service_effects WHERE pay_row_id={fail_id} "
                     "AND effect_kind='create'").split('\t')
            server.accounts[line[0]]={
                'username':line[0],'note':'achbot:v1:'+line[1],
                'expire':int(line[2]),'data_limit':int(line[3]),
                'status':'active','used_traffic':0,
                'subscription_url':url+'/sub/fixture-'+line[0],
                'links':['vless://fixture.invalid']}
            held_token=execute('token',pay=fail_id)['result']
            assert len(held_token)==20,held_token
            start_orders=sql('SELECT COUNT(*) FROM orders_list')
            sql("CREATE TRIGGER wallet_original_order_insertion_fault "
                "BEFORE INSERT ON orders_list FOR EACH ROW "
                "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture_order_fault'")
            blocked=execute('complete',pay=fail_id,token=held_token)
            assert blocked['result']['status']=='review',blocked
            assert sql(f"SELECT state FROM pays WHERE id={fail_id}")=='processing_wallet'
            assert sql('SELECT COUNT(*) FROM orders_list')==start_orders
            assert sql(f"SELECT state FROM service_effects WHERE pay_row_id={fail_id} "
                       "AND effect_kind='create'")=='verified'
            assert sql(f"SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id={fail_id}")=='0'
            assert sql(f"SELECT COUNT(*) FROM payment_delivery_intents WHERE pay_row_id={fail_id}")=='0'
            assert sql(f"SELECT COUNT(*) FROM setting WHERE type='WALLET_ADDON_REPORT:{fail_id}'")=='0'
            sql('DROP TRIGGER wallet_original_order_insertion_fault')
            success=execute('complete',pay=fail_id,token=held_token)
            assert success['result']['status']=='settled_delivery_pending',success
            checks.append('sql_local_order_failure_rolls_back_payment_customer_admin_atomic_finality')
            # A lost final COMMIT ACK is not permission for a second order,
            # invoice or Telegram report; the already-committed pay is proof.
            ack_id=payment_seed('wallet-original-commit-227')
            failed=creation.run(work,env,original,
                hash='wallet-original-commit-227',order_failure=True)
            assert failed['result']['status']=='error',failed
            line=sql(f"SELECT remote_username,operation_id,requested_expire,"
                     f"requested_bytes FROM service_effects WHERE pay_row_id={ack_id} "
                     "AND effect_kind='create'").split('\t')
            server.accounts[line[0]]={
                'username':line[0],'note':'achbot:v1:'+line[1],
                'expire':int(line[2]),'data_limit':int(line[3]),
                'status':'active','used_traffic':0,
                'subscription_url':url+'/sub/fixture-'+line[0],
                'links':['vless://fixture.invalid']}
            token_ack=execute('token',pay=ack_id)['result']
            ambiguous=execute('complete',pay=ack_id,token=token_ack,commit_ack_lost=True)
            assert ambiguous['result']['status']=='already_paid',ambiguous
            assert sql(f"SELECT state FROM pays WHERE id={ack_id}")=='paid_with_wallet'
            assert sql(f"SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id={ack_id}")=='1'
            assert sql(f"SELECT COUNT(*) FROM setting WHERE type='WALLET_ADDON_REPORT:{ack_id}'")=='1'
            orders_before=sql('SELECT COUNT(*) FROM orders_list')
            assert execute('complete',pay=ack_id,token=token_ack)['result']['status']=='already_paid'
            assert sql('SELECT COUNT(*) FROM orders_list')==orders_before
            checks.append('original_buy_final_commit_ack_lost_no_duplicate_local_or_admin')
            # Another order with the same physical account identity may
            # predate this operator callback; do not adopt it as the BUY_SUB's
            # missing local order or ignore the competing finality.
            foreign_id,foreign_name,foreign_expiry=original_unlinked_fixture(
                'wallet-original-foreign-227')
            foreign_token=execute('token',pay=foreign_id)['result']
            assert len(foreign_token)==20
            sql("INSERT INTO orders_list "
                "(userid,token,transid,fileid,server_id,inbound_id,remark,uuid,"
                "expire_date,link,amount,status,date,notif,agent_bought,autoRenew) "
                "VALUES (887788,'other-owner','','7',2,0,'"+foreign_name+
                "','other',"+str(foreign_expiry)+
                ",'foreign-link',0,1,UNIX_TIMESTAMP(),0,0,'off')")
            foreign=execute('inspect',pay=foreign_id)
            assert foreign['result']['status']=='review',foreign
            assert execute('token',pay=foreign_id)['result'] is None
            denied=execute('complete',pay=foreign_id,token=foreign_token)
            assert denied['result']['status']=='review',denied
            assert sql(f"SELECT state FROM pays WHERE id={foreign_id}")=='processing_wallet'
            assert sql(f"SELECT state FROM service_effects WHERE pay_row_id={foreign_id} "
                       "AND effect_kind='create'")=='verified'
            assert sql(f"SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id={foreign_id}")=='0'
            checks.append('foreign_equivalent_local_order_blocks_stale_confirm_and_cross_link')
            # If the original administrator report marker is occupied, the
            # successful local account link and customer intent MUST both
            # roll back, not leave a silently paid invoice without staff.
            report_id,_,_=original_unlinked_fixture(
                'wallet-adminfault-227')
            original_token=execute('token',pay=report_id)['result']
            before_orders=sql('SELECT COUNT(*) FROM orders_list')
            marker='WALLET_ADDON_REPORT:'+str(report_id)
            sql("INSERT INTO setting(type,value) VALUES ('"+marker+
                "','fixture-conflicting-original')")
            blocked=execute('complete',pay=report_id,token=original_token)
            assert blocked['result']['status']=='review',blocked
            assert sql(f"SELECT state FROM pays WHERE id={report_id}")=='processing_wallet'
            assert sql('SELECT COUNT(*) FROM orders_list')==before_orders
            assert sql(f"SELECT state FROM service_effects WHERE pay_row_id={report_id} "
                       "AND effect_kind='create'")=='verified'
            assert sql(f"SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id={report_id}")=='0'
            assert sql(f"SELECT COUNT(*) FROM payment_delivery_intents WHERE pay_row_id={report_id}")=='0'
            sql("DELETE FROM setting WHERE type='"+marker+"'")
            done=execute('complete',pay=report_id,token=original_token)
            assert done['result']['status']=='settled_delivery_pending',done
            checks.append('admin_original_report_failure_rolls_back_new_local_and_customer')
            # Original unknown CREATE: a separate invoice cannot borrow the
            # previous payment's remotely applied account or delivery proof.
            unknown=payment_seed('wallet-second-unknown-227')
            sql(f"UPDATE pays SET state='processing_wallet',request_date=UNIX_TIMESTAMP()-900 WHERE id={unknown}")
            assert execute('inspect',pay=unknown)['result']['status']=='review'
            assert execute('inbox',pay=unknown)['result'] is True
            assert sql('SELECT COUNT(*) FROM payment_delivery_intents')=='8'
            checks.append('separate_wallet_claim_has_no_borrowed_original_create_proof')
            sql("INSERT INTO pays(hash_id,user_id,type,plan_id,server_id,"
                "order_id,price,day,volume,description,request_date,state) "
                "VALUES('wallet-marzban-original-227',1001,'RENEW_SCONFIG',7,2,"
                "0,123,30,2,'{\"marzban\":true,\"server_id\":2,\"remark\":\""+name+"\"}',"
                "UNIX_TIMESTAMP()-3600,'processing_wallet')")
            marzban=int(sql("SELECT id FROM pays WHERE hash_id='wallet-marzban-original-227'"))
            reviewed=execute('marzban',pay=marzban)
            assert reviewed['ok'] and reviewed['result']['status']=='review',reviewed
            assert reviewed['result']['local_finality_allowed'] is False,reviewed
            assert reviewed['result']['observation']=='current_native_observed_not_original_proof',reviewed
            assert reviewed['result']['original_entitlement_snapshot'] is False
            assert server.other_posts==0
            checks.append('original_marzban_current_get_never_proves_unknown_additive_renewal')
            # Fresh disposable capacity for the new, independent #232 race
            # acceptance. Existing original cases have finished, and these
            # originals legitimately reserve one capacity slot each.
            sql('UPDATE server_info SET ucount=3 WHERE id=2')
            # Review #232 REQUIRED-1: the old staff-confirmation token
            # remains cryptographically valid after local Archive, but must
            # no longer mutate the dispatching original Native effect row.
            stale_id=payment_seed('warchive-stale-232')
            lost_stale=creation.run(work,env,original,
                hash='warchive-stale-232',remote='lost')
            assert lost_stale['result']['status']=='error',lost_stale
            se=sql(f"SELECT state,remote_username,operation_id,requested_expire,"
                   f"requested_bytes FROM service_effects WHERE pay_row_id={stale_id} "
                   "AND effect_kind='create'").split('	')
            assert se[0]=='dispatching',(se,lost_stale,
                sql(f'SELECT state,price FROM pays WHERE id={stale_id}'),
                sql('SELECT ucount FROM server_info WHERE id=2'))
            server.accounts[se[1]]={
                'username':se[1],'note':'achbot:v1:'+se[2],
                'expire':int(se[3]),'data_limit':int(se[4]),
                'status':'active','used_traffic':0,
                'subscription_url':url+'/sub/fixture-'+se[1],
                'links':['vless://fixture.invalid']}
            sql(f'UPDATE pays SET request_date=UNIX_TIMESTAMP()-900 WHERE id={stale_id}')
            stale_proof=execute('inspect',pay=stale_id)
            assert stale_proof['result']['status']=='ready',stale_proof
            stale_token=execute('token',pay=stale_id)['result']
            assert len(stale_token)==20
            original_state=sql(f"SELECT id,state,verified_at FROM service_effects "
                               f"WHERE pay_row_id={stale_id} AND effect_kind='create'")
            initial_wallet=sql('SELECT wallet FROM users WHERE userid=1001')
            initial_gets=server.gets
            archived=execute('archive',pay=stale_id)
            assert archived['ok'] and archived['result']=='archived',archived
            assert sql(f'SELECT review_archived_by FROM payment_attempts WHERE pay_row_id={stale_id}')=='9001'
            arch_at=sql(f'SELECT review_archived_at FROM payment_attempts WHERE pay_row_id={stale_id}')
            old_confirm=execute('complete',pay=stale_id,token=stale_token)
            assert old_confirm['result']['status']=='archived',old_confirm
            assert server.gets==initial_gets,old_confirm
            old_ui=execute('ui',pay=stale_id,
                data='attWOriginalC_'+str(stale_id)+'_'+stale_token)
            assert old_ui['ok'] and all(
                item['type'] not in ('first_customer_delivery','admin_report')
                for item in old_ui['events']),old_ui
            assert execute('inspect',pay=stale_id)['result']['status']=='archived'
            assert sql(f'SELECT review_archived_at FROM payment_attempts WHERE pay_row_id={stale_id}')==arch_at
            assert sql(f"SELECT id,state,verified_at FROM service_effects "
                       f"WHERE pay_row_id={stale_id} AND effect_kind='create'")==original_state
            assert sql(f'SELECT state FROM pays WHERE id={stale_id}')=='processing_wallet'
            assert sql('SELECT wallet FROM users WHERE userid=1001')==initial_wallet
            assert sql(f'SELECT COUNT(*) FROM payment_delivery_intents '
                       f'WHERE pay_row_id={stale_id}')=='0'
            assert server.other_posts==0
            checks.append('archived_original_dispatching_staff_token_cannot_mutate_native_effect_or_finance')

            # Review #232: the operator can finish an original still-active
            # Native-verification operation while Archive must return BUSY.
            racing_id=payment_seed('warchive-busy-232')
            racing=creation.run(work,env,original,
                hash='warchive-busy-232',remote='lost')
            assert racing['result']['status']=='error',racing
            re=sql(f"SELECT state,remote_username,operation_id,requested_expire,"
                   f"requested_bytes FROM service_effects WHERE pay_row_id={racing_id} "
                   "AND effect_kind='create'").split('	')
            assert re[0]=='dispatching'
            server.accounts[re[1]]={
                'username':re[1],'note':'achbot:v1:'+re[2],
                'expire':int(re[3]),'data_limit':int(re[4]),
                'status':'active','used_traffic':0,
                'subscription_url':url+'/sub/fixture-'+re[1],
                'links':['vless://fixture.invalid']}
            token_racing=execute('token',pay=racing_id)['result']
            assert len(token_racing)==20
            sql(f'UPDATE pays SET request_date=UNIX_TIMESTAMP()-900 WHERE id={racing_id}')
            server.pause_username=re[1]
            server.pause_started.clear()
            server.pause_release.clear()
            with ThreadPoolExecutor(max_workers=1) as pool:
                current=pool.submit(execute,'complete',pay=racing_id,token=token_racing)
                try:
                    assert server.pause_started.wait(timeout=7), 'real_staff_native_get_not_reached'
                    busy=execute('archive',pay=racing_id)
                    assert busy['ok'] and busy['result']=='busy',busy
                    assert sql(f'SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id={racing_id}')=='0'
                finally:
                    server.pause_release.set()
                    server.pause_username=None
                finished=current.result(timeout=35)
            assert finished['result']['status']=='settled_delivery_pending',finished
            assert sql(f'SELECT state FROM pays WHERE id={racing_id}')=='paid_with_wallet'
            assert sql(f'SELECT state FROM service_effects WHERE pay_row_id={racing_id} '
                       "AND effect_kind='create'")=='local_recorded'
            assert sql(f'SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id={racing_id}')=='1'
            assert server.other_posts==0
            checks.append('active_staff_native_get_holds_original_lease_archive_busy_until_finality')
            assert int(initial_wallet)==49900
            return {'complete':True,'cases':len(checks),'checks':len(checks),'scenarios':checks,
                'php85':True,'disposable_mariadb':True,
                'native_real_http_get':server.gets>0,
                'native_write_requests':server.other_posts,
                'production_mutations':0}
    finally:
        server.shutdown();server.server_close();thread.join(timeout=5)

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--run-disposable',required=True,action='store_true')
    parser.add_argument('--report',type=Path)
    args=parser.parse_args()
    result=test()
    if args.report:args.report.write_text(json.dumps(result,indent=2)+'\n')
    print(json.dumps(result))

if __name__=='__main__':main()
