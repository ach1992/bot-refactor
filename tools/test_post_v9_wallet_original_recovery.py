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
$GLOBALS['events']=[];
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
$db=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$db->set_charset('utf8mb4');$connection=$db;
try {
    $op=$spec['op'];$payId=(int)($spec['pay']??0);
    $actor=(int)($spec['actor']??9001);
    $primary=9001;
    if ($op==='ui') {
        $data=(string)$spec['data'];
        $from_id=$actor;$chat_id=$actor;$callbackId='fixture-callback';
        $message_id=77;
        require $root.'/src/Finance/Routes/AutomaticGatewayReview.php';
        $result=['handled'=>true];
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
            original.write_text(creation_php.replace(old,new))
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
            def payment_seed(name):
                sql('UPDATE users SET wallet=50000 WHERE userid=1001')
                sql("DELETE FROM setting WHERE type='BOT_STATES'")
                sql("INSERT INTO setting(type,value) VALUES "
                    "('BOT_STATES','{\"walletState\":\"on\",\"gatewayAudience\":{\"wallet\":\"all\"}}') "
                    "ON DUPLICATE KEY UPDATE value=VALUES(value)")
                sql("INSERT INTO pays(hash_id,user_id,type,plan_id,server_id,"
                    "agent_count,price,day,volume,request_date,state) "
                    f"VALUES('{name}',1001,'BUY_SUB',7,2,1,100,0,0,UNIX_TIMESTAMP(),'pending')")
                return int(sql(f"SELECT id FROM pays WHERE hash_id='{name}'"))
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
            # A genuinely original Native CREATE may have verified but its
            # per-account local order SQL failed. GET can observe the exact
            # account, but MUST NOT create a second order or infer finality.
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
            # Already processing_wallet from original source.
            unlinked=execute('inspect',pay=partial)
            assert unlinked['result']['status']=='review',unlinked
            assert unlinked['result']['lines'][0]['status']==(
                'native_matches_but_original_order_unlinked'),unlinked
            assert execute('token',pay=partial)['result'] is None
            assert server.other_posts==0
            assert sql('SELECT COUNT(*) FROM orders_list')==initial_order
            checks.append('native_verified_unlinked_original_create_reviewable_not_recreated')
            # Original unknown CREATE: a separate invoice cannot borrow the
            # previous payment's remotely applied account or delivery proof.
            unknown=payment_seed('wallet-second-unknown-227')
            sql(f"UPDATE pays SET state='processing_wallet',request_date=UNIX_TIMESTAMP()-900 WHERE id={unknown}")
            assert execute('inspect',pay=unknown)['result']['status']=='review'
            assert execute('inbox',pay=unknown)['result'] is True
            assert sql('SELECT COUNT(*) FROM payment_delivery_intents')=='1'
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
