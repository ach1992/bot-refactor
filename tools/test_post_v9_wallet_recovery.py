#!/usr/bin/env python3
"""Issue #117: source-real wallet BUY_SUB original-effect continuation.

Only disposable MariaDB, PHP 8.5, synthetic native GET and Telegram spies.
No live provider or customer. Missing original ordinal must never POST.
"""
from __future__ import annotations

import argparse
import contextlib
import copy
import json
import subprocess
import threading
from http.server import ThreadingHTTPServer
from urllib.parse import urlsplit, unquote
from pathlib import Path

import test_access_state as owned
import test_v6_native as native
import test_v8_native as purchase
import test_post_v9_wallet_creation as wallet
import test_post_v9_pasarguard_client as pg
import provider_storage_fixture as provider_storage
import notification_fixture

ROOT=Path(__file__).resolve().parents[1]
DB=purchase.SIDE[1]

RECOVERY=r'''<?php
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(static function($n,$m) {throw new ErrorException($m,0,$n);});
$_SERVER['SCRIPT_FILENAME']=__FILE__;
$root=$argv[1];$spec=json_decode($argv[4],true,512,JSON_THROW_ON_ERROR);
define('ACHBOT_PG_TEST_LOOPBACK',true);
$connection=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$connection->set_charset('utf8mb4');
$GLOBALS['telegramWebhookSecret']='fixture-bank-webhook-secret-32-byte-valid';
$admin=9001;$botUrl='https://fixture.invalid';$botState=[];
$buttonValues=['start_bot'=>'Start'];$GLOBALS['events']=[];
function sendQrFromLink(...$args) {
    $GLOBALS['events'][]=['event'=>'TELEGRAM','uid'=>$args[4]];
    return ($GLOBALS['spec']['telegram']??'ack')!=='unknown';
}
function sendMessage(...$args) {throw new RuntimeException('unexpected_customer_text');}
function answerCallbackQuery(...$args) {
    $GLOBALS['events'][]=['event'=>'CALLBACK','text'=>$args[1]??null];
    return (object)['ok'=>true];
}
function editText(...$args) {
    $GLOBALS['events'][]=['event'=>'OWNER_EDIT',
        'body'=>(string)($args[1]??''),
        'markup'=>json_decode((string)($args[2]??''),true)];
    return (object)['ok'=>true];
}
function createServerAccount(...$args) {throw new RuntimeException('forbidden_native_post');}
function getMarzbanUser(...$args) {throw new RuntimeException('unexpected_unpinned_native_get');}
function buildLinksAndSendQr(...$args) {
    if (($args[24]??null)!==false) throw new RuntimeException('premature_delivery');
    $GLOBALS['events'][]=['event'=>'RENDER','uid'=>$args[16]];
    return ['fixture-token','["vless://fixture.invalid"]','fixture-uuid',
        'مشخصات اشتراک','{}','vless://fixture.invalid'];
}
require_once $root.'/src/Purchase/Core.php';
require_once $root.'/src/Purchase/OrderWrites.php';
require_once $root.'/src/Finance/ReceiptHelpers.php';
require_once $root.'/src/Finance/PaidAccountRecovery.php';
require_once $root.'/src/Finance/DeliveryLedger.php';
$pay=(int)$spec['pay'];$paymentId=$pay;$result=null;$error=null;
$reader=static function(string $url,string $user,string $pass,string $name) use($connection,$pay): ?object {
    $GLOBALS['events'][]=['event'=>'GET','name'=>$name];
    $q=$connection->prepare("SELECT operation_id,remote_username,requested_expire,requested_bytes
        FROM service_effects WHERE pay_row_id=? AND effect_kind='create' AND remote_username=?");
    $q->bind_param('is',$pay,$name);$q->execute();$e=$q->get_result()->fetch_assoc();$q->close();
    if (!$e) return null;
    $note='achbot:v1:'.$e['operation_id'];
    if (($GLOBALS['spec']['native']??'')==='wrong_note') $note='foreign:'.$e['operation_id'];
    if (($GLOBALS['spec']['native']??'')==='missing') return null;
    return (object)['username'=>$e['remote_username'],'note'=>$note,
        'expire'=>(int)$e['requested_expire'],'data_limit'=>(int)$e['requested_bytes'],
        'status'=>'active','subscription_url'=>'/sub/fixture-token',
        'links'=>['vless://fixture.invalid']];
};
try {
    if (($spec['op']??'')==='eligible') $result=achbotWalletBuyRecoverable($connection,$pay);
    elseif (($spec['op']??'')==='route') {
        $from_id=(int)($spec['actor']??9001);
        $chat_id=(int)($spec['chat']??$from_id);
        $data=(string)($spec['callback']??('walletBuyResume_'.$pay));
        $text='';$callbackId='owned-callback';$message_id=991;
        $userInfo=['step'=>'none'];
        require $root.'/src/Finance/Routes/ManualReceipts.php';
        $result=['handled'=>true];
    }
    elseif (($spec['op']??'')==='case') {
        require_once $root.'/src/Finance/RecoveryInspection.php';
        $result=achbotRecoveryTechnicalCase($connection,$pay)!==null;
    }
    elseif (($spec['op']??'')==='check') {
        $ordinal=(int)($spec['ordinal']??1);
        // DeliveryRecovery's route-scoped UI helper is not loaded by the
        // source-real fixture. Exercise its real sealed-packet validator.
        $connection->begin_transaction();
        $p=$connection->query('SELECT * FROM pays WHERE id='.$pay.' FOR UPDATE')->fetch_assoc();
        $data=$connection->query('SELECT * FROM payment_delivery_intents WHERE pay_row_id='.$pay.
            ' AND ordinal='.$ordinal.' FOR UPDATE')->fetch_assoc();
        $packet=$p&&$data ? achbotDeliveryValidateLocked($connection,$p,$data):null;
        $result=$packet!==null?(string)$data['state']:'identity_or_payload_invalid';
        $connection->rollback();
        if ($result!=='acknowledged' && $result!=='unknown') {
            $data=$connection->query('SELECT * FROM payment_delivery_intents WHERE pay_row_id='.$pay.' AND ordinal='.$ordinal)->fetch_assoc();
            $p=$connection->query('SELECT * FROM pays WHERE id='.$pay)->fetch_assoc();
            $a=$connection->query('SELECT * FROM payment_attempts WHERE pay_row_id='.$pay)->fetch_assoc();
            $e=$connection->query("SELECT * FROM service_effects WHERE pay_row_id=$pay AND effect_kind='create' AND ordinal=$ordinal")->fetch_assoc();
            $h=$connection->query("SELECT * FROM service_effects WHERE pay_row_id=$pay AND effect_kind='wallet_hold' AND ordinal=0")->fetch_assoc();
            $digest=$data&&$p&&$a&&$e&&$h ? achbotDeliveryWalletBuyDigest($p,$a,$e,$h,$ordinal) : null;
            $GLOBALS['diagnostics']=[
                'schema'=>achbotDeliverySchemaReady($connection),
                'wallet_digest_nonnull'=>$digest!==null,
                'wallet_digest_equal'=>$data&&$digest!==null&&hash_equals((string)$data['source_digest'],$digest),
                'pay_digest_equal'=>$data&&$p&&hash_equals((string)$data['pay_fingerprint'],achbotPaymentEvidencePayFingerprint($p)),
                'sealed_sha_equal'=>$data&&hash_equals((string)$data['payload_sha256'],hash('sha256',(string)$data['sealed_payload'])),
                'e_state'=>$e['state']??null,'h_state'=>$h['state']??null,
                'attempt_status'=>$a['provider_status']??null,'delivery_state'=>$data['state']??null,
                'payload_decryptable'=>$data&&achbotPaymentEvidenceUnprotect(achbotDeliveryPurpose($pay,$ordinal,(int)$data['effect_id'],(int)$data['order_id']),(string)$data['sealed_payload'])!==null
            ];
        }
    }
    else $result=achbotResumeWalletBuy($connection,$pay,$botState,$buttonValues,
        $botUrl,$reader);
} catch (Throwable $e) {$error=$e->getMessage();}
// Included real routes may reuse the $pay variable for an associative row.
// The fixture's original request ID must not be overwritten by route scope.
$state=$connection->query('SELECT state FROM pays WHERE id='.$paymentId)->fetch_row()[0]??null;
$orders=(int)$connection->query('SELECT COUNT(*) FROM orders_list o JOIN service_effects e
    ON e.order_id=o.id WHERE e.pay_row_id='.$paymentId)->fetch_row()[0];
$attempt=$connection->query('SELECT provider,stage,provider_status FROM payment_attempts WHERE pay_row_id='.$paymentId)
    ->fetch_assoc();
$delivery=$connection->query('SELECT ordinal,state,attempt_count FROM payment_delivery_intents
    WHERE pay_row_id='.$paymentId.' ORDER BY ordinal')->fetch_all(MYSQLI_ASSOC);
echo json_encode(['result'=>$result,'error'=>$error,'state'=>$state,'orders'=>$orders,
    'attempt'=>$attempt,'delivery'=>$delivery,'events'=>$GLOBALS['events'],
    'diagnostics'=>$GLOBALS['diagnostics']??null],
    JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
'''


# This is the ACTUAL source wallet claim + actual 007 CREATE implementation.
# The simulated failure occurs only after every native CREATE was committed,
# before any local order. Recovery has no permission to POST again.
ORIGINAL=r'''<?php
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(static function($n,$m){throw new ErrorException($m,0,$n);});
if (PHP_VERSION_ID<80500 || PHP_VERSION_ID>=80600)
    throw new RuntimeException('php85_required');
define('ACHBOT_PG_TEST_LOOPBACK',true);
$_SERVER['SCRIPT_FILENAME']=__FILE__;
$root=$argv[1];$spec=json_decode($argv[4],true,64,JSON_THROW_ON_ERROR);
$connection=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$connection->set_charset('utf8mb4');$db=$connection;
$GLOBALS['telegramWebhookSecret']='fixture-bank-webhook-secret-32-byte-valid';
$from_id=1001;$admin=9001;$GLOBALS['events']=[];
function alert(...$args) {$GLOBALS['events'][]=['event'=>'ALERT'];}
function sendMessage(...$args) {return (object)['ok'=>true];}
function createServerAccount(...$args) {
    if (($GLOBALS['spec']['provider']??'')!=='marzban')
        throw new RuntimeException('pasarguard_must_use_real_native_adapter');
    $GLOBALS['events'][]=['event'=>'POST','name'=>$args[4]];
    $GLOBALS['nativeMarzban'][$args[4]]=(object)[
        'username'=>(string)$args[4],
        'note'=>'achbot:v1:'.(string)$args[14],
        'expire'=>(int)$args[15],
        'data_limit'=>(int)$args[5]*1073741824,
        'status'=>'active','subscription_url'=>'/sub/fixture-token',
        'links'=>['vless://fixture.invalid']];
    return [(object)['success'=>true],(string)$args[4],12345];
}
function getMarzbanUser(...$args) {
    if (($args[3]??false)!==true) throw new RuntimeException('non_strict_marzban');
    return $GLOBALS['nativeMarzban'][$args[1]]??null;
}
require_once $root.'/src/Purchase/Core.php';
require_once $root.'/src/Purchase/OrderWrites.php';
require_once $root.'/src/Finance/ReceiptHelpers.php';
require_once $root.'/src/Finance/ServiceEffects.php';
require_once $root.'/src/Finance/WalletSettlement.php';
$hash=(string)$spec['hash'];$provider=(string)$spec['provider'];
// In the actual PasarGuard checkout this immutable original quote is stored
// before any wallet debit; use the same source API in this fixture.
$q=$db->prepare('SELECT id FROM pays WHERE hash_id=? LIMIT 1');
$q->bind_param('s',$hash);$q->execute();$row=$q->get_result()->fetch_assoc();$q->close();
if (!$row) throw new RuntimeException('fixture_original_quote_not_found');
achbotPgPinPayment($db,(int)$row['id']);
$out=processWalletPayment($db,$hash,static function(array $pay,array $owner)
    use($db,$provider) {
    $uid=(int)$pay['user_id'];
    $count=max(1,(int)$pay['agent_count']);
    for ($ordinal=1;$ordinal<=$count;$ordinal++) {
        achbotCreatePaidAccount($db,(int)$pay['id'],$ordinal,[
            'user_id'=>$uid,'server_type'=>$provider,
            'server_id'=>(int)$pay['server_id'],'inbound_id'=>0,
            'plan_id'=>(int)$pay['plan_id'],
            'remark'=>'walletoriginal_'.$pay['id'].'_'.$ordinal,
            'volume'=>2,'days'=>30,'uniqid'=>'fixture-uuid',
            'protocol'=>'vless','port'=>0,'expire_microdate'=>0,
            'net_type'=>'normal','rahgozar'=>0,'limitip'=>0]);
    }
    throw new RuntimeException('fixture_lost_local_finality_after_original_native');
},'standard');
echo json_encode(['result'=>$out,'events'=>$GLOBALS['events']],JSON_THROW_ON_ERROR);
'''

ORIGINAL=notification_fixture.with_router(ORIGINAL)

class WalletPanel(pg.Handler):
    """Reusable real HTTP PasarGuard API, but distinct native identities/ordinals."""
    def handle_request(self):
        server=self.server
        path=urlsplit(self.path).path
        if self.command=='POST' and path=='/api/user':
            size=int(self.headers.get('Content-Length','0'))
            req=json.loads(self.rfile.read(size))
            server.trace.append(('POST',path,req))
            assert self.headers.get('X-Api-Key')=='fixture-private-key'
            if server.block_posts:
                status=409;value={'detail':'test forbids recovery POST'}
            else:
                name=req['username'];assert name not in server.users
                user=copy.deepcopy(pg.USER)
                user.update(req)
                user['id']=41+len(server.users)
                user['created_at']='2026-10-26T05:00:00.123456+00:00'
                server.users[name]=user
                server.write_count+=1
                status=201;value=user
            self.reply(status,value);return
        if self.command=='GET' and (path.startswith('/api/user/by-username/') or
                                    path.startswith('/api/user/by-id/')):
            server.trace.append(('GET',path,None))
            assert self.headers.get('X-Api-Key')=='fixture-private-key'
            if path.startswith('/api/user/by-username/'):
                name=unquote(path.split('/by-username/',1)[1]);user=server.users.get(name)
            else:
                native_id=int(path.rsplit('/',1)[-1]);user=next(
                    (u for u in server.users.values() if u['id']==native_id),None)
            if user is None or server.mode=='missing' and (
                    server.bad_username is None or user['username']==server.bad_username):
                self.reply(404,{'detail':'not found'});return
            value=copy.deepcopy(user)
            if server.mode=='wrong_note' and (
                    server.bad_username is None or user['username']==server.bad_username):
                value['note']='other_origin'
            if server.mode=='wrong_id' and (
                    server.bad_username is None or user['username']==server.bad_username):
                value['id']=999
            self.reply(200,value);return
        super().handle_request()

    def reply(self,status,value):
        body=json.dumps(value).encode()
        self.send_response(status)
        self.send_header('Content-Type','application/json')
        self.send_header('Content-Length',str(len(body)))
        self.end_headers()
        self.wfile.write(body)

@contextlib.contextmanager
def wallet_panel():
    server=ThreadingHTTPServer(('127.0.0.1',0),WalletPanel)
    server.trace=[];server.user=copy.deepcopy(pg.USER)
    server.users={};server.mode='ok';server.bad_username=None
    server.write_count=0;server.links_requested=False;server.block_posts=False
    thread=threading.Thread(target=server.serve_forever,daemon=True)
    thread.start()
    try:yield server,f'http://127.0.0.1:{server.server_port}'
    finally:
        server.shutdown();thread.join(timeout=5);server.server_close()


def sql(work,env,command):
    return native.sql(work,env,command,DB).strip()

def recover(work,env,driver,*,pg_native=False,**spec):
    extensions=['-d','extension=curl','-d','extension=ctype'] if pg_native else []
    denied='exec,shell_exec,system,passthru,popen,proc_open,mail'
    if not pg_native:denied='curl_init,curl_exec,'+denied
    p=subprocess.run(['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
        *extensions,'-d','disable_functions='+denied,
        str(driver),str(ROOT),str(work/'mysql.sock'),DB,json.dumps(spec)],
        cwd=work,env=env,capture_output=True,text=True,timeout=35)
    if p.returncode:
        raise RuntimeError('wallet recovery PHP fatal '+repr((p.returncode,p.stderr[-1500:],p.stdout[-400:])))
    try:
        result=json.loads(p.stdout)
        # mysqli returns integer SQL columns as strings without MYSQLI_OPT_INT_AND_FLOAT_NATIVE.
        for row in result['delivery']:
            row['ordinal']=int(row['ordinal'])
            row['attempt_count']=int(row['attempt_count'])
        return result
    except Exception as error:
        raise RuntimeError('wallet recovery JSON '+p.stdout[-600:]) from error

def check():
    cases=[]
    with owned.owned_database() as (work,env):
        purchase.init(work,env,'http://127.0.0.1:9')
        for filename in ('001_marzban_panel_group_id.sql','003_payment_attempts.sql',
                         '007_service_effects.sql','018_payment_delivery_intents.sql'):
            sql(work,env,(ROOT/'migrations/post-v9'/filename).read_text())
        init=work/'wallet-initial.php';init.write_text(wallet.PHP)
        driver=work/'wallet-recovery.php';driver.write_text(RECOVERY)
        def seed(name,*,count=1,capacity=10):
            sql(work,env,f'UPDATE server_info SET ucount={capacity} WHERE id=2')
            sql(work,env,'UPDATE users SET wallet=50000 WHERE userid=1001')
            sql(work,env,"DELETE FROM setting WHERE type='BOT_STATES'")
            sql(work,env,"INSERT INTO setting(type,value) VALUES "
                "('BOT_STATES','{\"walletState\":\"on\",\"gatewayAudience\":{\"wallet\":\"all\"}}')")
            sql(work,env,'INSERT INTO pays (hash_id,user_id,type,plan_id,server_id,agent_count,'
                'price,day,volume,request_date,state) '
                f"VALUES('{name}',1001,'BUY_SUB',7,2,{count},100,0,0,UNIX_TIMESTAMP(),'pending')")
            return int(sql(work,env,f"SELECT id FROM pays WHERE hash_id='{name}'"))
        def funds():
            return int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001')), int(
                sql(work,env,'SELECT ucount FROM server_info WHERE id=2'))
        def attempts(pay):
            return int(sql(work,env,f'SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id={pay}'))
        def test(name,*,initial,expected='ok',native=None,telegram='ack'):
            pay=seed(name)
            old=wallet.run(work,env,init,hash=name,**initial)
            assert old['result']['status']=='error',old
            assert funds()==(49900,9) and attempts(pay)==0,(name,funds())
            current=recover(work,env,driver,pay=pay,native=native or '',telegram=telegram)
            return pay,current

        pay,a=test('wallet_original_dispatch_lost',initial={'remote':'lost'})
        assert a['error'] is None and a['result']['committed'] is True,a
        assert a['state']=='paid_with_wallet' and a['orders']==1,a
        assert a['attempt']=={'provider':'wallet','stage':'completed','provider_status':'wallet_debited'},a
        assert a['delivery']==[{'ordinal':1,'state':'acknowledged','attempt_count':1}],a
        assert [e['event'] for e in a['events']]==['GET','RENDER','TELEGRAM'],a
        assert funds()==(49900,9) and attempts(pay)==1
        cases.append('lost_native_response_reuses_original_verified_account_without_post_or_second_debit')
        twice=recover(work,env,driver,pay=pay)
        assert twice['error']=='wallet_recovery_manifest_unavailable' and not twice['events'],twice
        assert twice['orders']==1 and attempts(pay)==1 and funds()==(49900,9)
        cases.append('repeated_owner_resume_never_relinks_or_resends')
        checked=recover(work,env,driver,pay=pay,op='check')
        assert checked['result']=='acknowledged',checked
        cases.append('sealed_wallet_packet_passes_original_delivery_integrity_check')

        pay,b=test('wallet_original_verified_unlinked',initial={'order_failure':True})
        assert b['error'] is None and b['orders']==1 and b['state']=='paid_with_wallet',b
        assert [e['event'] for e in b['events']]==['GET','RENDER','TELEGRAM']
        cases.append('verified_native_unlinked_finalizes_without_create')

        pay,c=test('wallet_original_foreign_native',initial={'remote':'lost'},native='wrong_note')
        assert c['error'] is not None and c['state']=='processing_wallet',c
        assert c['orders']==0 and attempts(pay)==0 and funds()==(49900,9),c
        assert [e['event'] for e in c['events']]==['GET'],c
        cases.append('foreign_native_marker_keeps_original_wallet_hold_for_review')
        good=recover(work,env,driver,pay=pay)
        assert good['error'] is None and good['orders']==1 and funds()==(49900,9),good
        cases.append('exact_original_native_proof_can_complete_after_mismatch')

        pay,d=test('wallet_original_unknown_telegram',initial={'remote':'lost'},telegram='unknown')
        assert d['error'] is None and d['result']['committed'] is True,d
        assert d['delivery']==[{'ordinal':1,'state':'unknown','attempt_count':1}],d
        assert recover(work,env,driver,pay=pay,op='check')['result']=='unknown'
        replay=recover(work,env,driver,pay=pay)
        assert replay['error'] and not replay['events'] and replay['delivery']==d['delivery'],replay
        cases.append('telegram_unknown_is_durable_and_not_automatically_resent')

        pay=seed('wallet_original_missing_bulk',count=2)
        old=wallet.run(work,env,init,hash='wallet_original_missing_bulk',order_failure=True)
        assert old['result']['status']=='error' and funds()==(49900,8),old
        no=recover(work,env,driver,pay=pay)
        assert no['error']=='wallet_recovery_manifest_unavailable' and not no['events'],no
        assert no['orders']==0 and attempts(pay)==0
        cases.append('missing_original_bulk_ordinal_cannot_invoke_new_post')

        pay=seed('wallet_original_corrupt_hold')
        wallet.run(work,env,init,hash='wallet_original_corrupt_hold',remote='lost')
        sql(work,env,f"UPDATE service_effects SET reserved_count=0 WHERE pay_row_id={pay} "
            "AND effect_kind='wallet_hold'")
        no=recover(work,env,driver,pay=pay)
        assert no['error']=='wallet_recovery_manifest_unavailable' and not no['events'],no
        assert no['orders']==0 and attempts(pay)==0
        cases.append('tampered_wallet_capacity_hold_fails_before_remote_get')

        pay=seed('wallet_original_foreign_merchant')
        wallet.run(work,env,init,hash='wallet_original_foreign_merchant',remote='lost')
        sql(work,env,"INSERT INTO payment_attempts (pay_row_id,provider,order_hash,"
            "quote_amount,quote_currency,local_amount_toman,stage) "
            f"VALUES ({pay},'nextpay','wallet_original_foreign_merchant',100,'toman',100,'review')")
        no=recover(work,env,driver,pay=pay)
        assert no['error']=='wallet_recovery_manifest_unavailable' and not no['events'],no
        assert no['orders']==0 and attempts(pay)==1
        cases.append('foreign_provider_attempt_cannot_be_wallet_recovery_proof')
    cases.extend(check_pasarguard_and_bulk())
    return {'complete':True,'cases':len(cases),'source_real':True,
        'php85_required':True,'disposable_mariadb':True,'original_native_post_replays':0,
        'original_wallet_duplicate_debits':0,'scenarios':cases}


def check_pasarguard_and_bulk():
    """Actual wallet claim, 007 native CREATE and PG HTTP; separate PHP GET/link/018.

    Resetting fixture wallet/server capacity before each synthetic order is
    deliberate; no recovery request itself may change either account value.
    """
    scenarios=[]
    with owned.owned_database() as (work,env),wallet_panel() as (server,url):
        purchase.init(work,env,url)
        for filename in ('001_marzban_panel_group_id.sql',
                         '003_payment_attempts.sql','007_service_effects.sql',
                         '011_provider_identity.sql','012_shared_provider_storage.sql'):
            sql(work,env,(ROOT/'migrations/post-v9'/filename).read_text())
        sql(work,env,provider_storage.connection(2,1,url)+
            "UPDATE server_plans SET provider_group_ids='[2,5]' WHERE id IN (7,8)")
        for filename in ('016_remove_pasarguard_server_groups.sql',
                         '017_shared_plan_changes.sql',
                         '018_payment_delivery_intents.sql'):
            sql(work,env,(ROOT/'migrations/post-v9'/filename).read_text())
        driver=work/'wallet-recovery-real.php';driver.write_text(RECOVERY)
        original=work/'wallet-original-real.php';original.write_text(ORIGINAL)
        source_argv=['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
                     '-d','extension=curl','-d','extension=ctype',
                     '-d','disable_functions=exec,shell_exec,system,passthru,popen,proc_open,mail',
                     str(original),str(ROOT),str(work/'mysql.sock'),DB]

        def origin(name):
            run=subprocess.run([*source_argv,json.dumps({'hash':name,'provider':'pasarguard'})],
                cwd=work,env=env,text=True,capture_output=True,timeout=45)
            # The original source intentionally reports the held-debit review
            # when the fixture loses local finality AFTER native CREATE.
            # Only this exact bounded diagnostic is expected; never discard
            # unrelated PHP warnings, native/API errors or stack traces.
            expected='achbot_wallet_held_debit_review hash='+name
            if run.returncode or run.stderr.strip()!=expected:
                raise AssertionError(('source_real_pg_origin_failed',run.returncode,
                    run.stderr[-1300:],run.stdout[-600:]))
            return json.loads(run.stdout)
        def balances():
            return (int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001')),
                int(sql(work,env,'SELECT ucount FROM server_info WHERE id=2')))
        def count(table,pay):
            if table=='orders_list':
                return int(sql(work,env,'SELECT COUNT(*) FROM orders_list o '
                    'JOIN service_effects e ON e.order_id=o.id '
                    f'WHERE e.pay_row_id={pay}'))
            return int(sql(work,env,f'SELECT COUNT(*) FROM {table} WHERE pay_row_id={pay}'))
        def physical_orders(pay):
            return int(sql(work,env,'SELECT COUNT(*) FROM orders_list WHERE userid=1001 '
                f"AND remark REGEXP '^walletoriginal_{pay}_[0-9]+$'"))
        def lines(pay):
            return sql(work,env,'SELECT ordinal,state,provider_state,provider_remote_user_id,'
                f'remote_username FROM service_effects WHERE pay_row_id={pay} '
                "AND effect_kind='create' ORDER BY ordinal")
        def native_trace():
            return [(method,urlsplit(path).path) for method,path,_ in server.trace]
        def clean_recovery():
            server.block_posts=True;server.trace=[];server.mode='ok';server.bad_username=None
        def seed(label,count_value=1):
            # Every requested case starts with an ACTUAL original wallet debit
            # and native CREATE. The fixture stops before local order linking.
            server.block_posts=False;server.mode='ok';server.bad_username=None
            sql(work,env,'UPDATE users SET wallet=50000 WHERE userid=1001;'
                'UPDATE server_info SET ucount=10,active=1,state=1 WHERE id=2;'
                "DELETE FROM setting WHERE type='BOT_STATES';"
                "INSERT INTO setting(type,value) VALUES ('BOT_STATES',"
                "'{\"walletState\":\"on\",\"gatewayAudience\":{\"wallet\":\"all\"}}')")
            sql(work,env,'INSERT INTO pays '
                '(hash_id,user_id,type,plan_id,server_id,agent_count,price,day,volume,request_date,state) '
                f"VALUES('{label}',1001,'BUY_SUB',7,2,{count_value},100,0,0,UNIX_TIMESTAMP(),'pending')")
            pay=int(sql(work,env,f"SELECT id FROM pays WHERE hash_id='{label}'"))
            server.trace=[]
            result=origin(label)
            created=sum(m=='POST' and path=='/api/user' for m,path in native_trace())
            assert result['result']['status']=='error' and created==count_value,(label,result,server.trace,lines(pay))
            assert count('service_effects',pay)==count_value+1 and balances()==(49900,10-count_value),(label,lines(pay),balances())
            assert count('payment_attempts',pay)==0 and count('payment_delivery_intents',pay)==0
            assert count('orders_list',pay)==0 and physical_orders(pay)==0
            assert all(row.split('\t')[1:3]==['verified','verified'] for row in lines(pay).splitlines()),(label,lines(pay))
            clean_recovery()
            return pay
        def run(pay,**kwargs):
            return recover(work,env,driver,pg_native=True,pay=pay,**kwargs)
        def check_terminal(pay,count_value):
            assert sql(work,env,f'SELECT state FROM pays WHERE id={pay}')=='paid_with_wallet'
            assert count('orders_list',pay)==count_value and physical_orders(pay)==count_value
            assert count('payment_attempts',pay)==1
            assert count('payment_delivery_intents',pay)==count_value
            assert balances()==(49900,10-count_value)
            ids=sql(work,env,'SELECT effect_id,order_id,source_digest FROM payment_delivery_intents '
                f'WHERE pay_row_id={pay} ORDER BY ordinal').splitlines()
            assert len(ids)==count_value and len(set(ids))==count_value,(pay,ids)
            assert len({line.split('\t')[0] for line in ids})==count_value,(pay,ids)
            for ordinal in range(1,count_value+1):
                check=run(pay,op='check',ordinal=ordinal)
                assert check['error'] is None and check['result']=='acknowledged',(pay,ordinal,check)
            assert not any(m=='POST' for m,_ in native_trace()),(pay,server.trace)

        pay=seed('pg_wallet_username')
        sql(work,env,"UPDATE service_effects SET state='dispatching',"
            "provider_state='dispatching',provider_remote_user_id=NULL,"
            'provider_remote_created_at=NULL,provider_verified_at=NULL '
            f"WHERE pay_row_id={pay} AND effect_kind='create'")
        name=f'walletoriginal_{pay}_1'
        success=run(pay)
        assert success['error'] is None and success['result']['committed'] is True,success
        assert ('GET','/api/user/by-username/'+name) in native_trace(),server.trace
        check_terminal(pay,1)
        assert 'verified\tlinked' not in lines(pay) or count('orders_list',pay)==1
        scenarios.append('pg_original_by_username_native_get_seals_original_wallet_order')

        pay=seed('pg_wallet_byid')
        success=run(pay)
        assert success['error'] is None and success['orders']==1,success
        native_id=server.users[f'walletoriginal_{pay}_1']['id']
        assert ('GET','/api/user/by-id/'+str(native_id)) in native_trace(),server.trace
        check_terminal(pay,1)
        scenarios.append('pg_original_pinned_by_id_readback_never_posts_again')

        pay=seed('pg_wallet_mismatch')
        sql(work,env,"UPDATE service_effects SET state='dispatching',"
            "provider_state='dispatching',provider_remote_user_id=NULL,"
            "provider_remote_created_at=NULL,provider_verified_at=NULL "
            f"WHERE pay_row_id={pay} AND effect_kind='create'")
        server.mode='wrong_note'
        mismatch=run(pay)
        assert mismatch['error'] and mismatch['state']=='processing_wallet',mismatch
        assert mismatch['orders']==0 and mismatch['delivery']==[] and count('payment_attempts',pay)==0
        assert not any(m=='POST' for m,_ in native_trace()) and balances()==(49900,9)
        server.mode='missing';server.trace=[]
        missing=run(pay)
        assert missing['error'] and missing['state']=='processing_wallet',missing
        assert missing['orders']==0 and not any(m=='POST' for m,_ in native_trace())
        scenarios.append('pg_missing_and_foreign_original_native_hold_without_repost_or_finance')
        clean_recovery()
        recovered=run(pay)
        assert recovered['error'] is None and recovered['orders']==1,recovered
        check_terminal(pay,1)
        scenarios.append('pg_verified_original_continuation_after_resolving_native_mismatch')

        pay=seed('pg_wallet_bulk',2)
        result=run(pay)
        assert result['error'] is None and result['result']['committed'] is True,result
        assert result['orders']==2 and len(result['delivery'])==2,result
        assert len([e for e in result['events'] if e['event']=='TELEGRAM'])==2,result
        check_terminal(pay,2)
        repeat=run(pay)
        assert repeat['error']=='wallet_recovery_manifest_unavailable' and not repeat['events'],repeat
        scenarios.append('pg_two_complete_original_accounts_atomically_link_and_seal_both_packets')

        pay=seed('pg_wallet_bulk_later_mismatch',2)
        server.mode='wrong_note';server.bad_username=f'walletoriginal_{pay}_2'
        refused=run(pay)
        assert refused['error'] and refused['orders']==0 and refused['delivery']==[],refused
        assert physical_orders(pay)==0
        assert refused['state']=='processing_wallet' and count('payment_attempts',pay)==0
        assert not any(m=='POST' for m,_ in native_trace()) and balances()==(49900,8)
        scenarios.append('pg_complete_bulk_second_native_mismatch_cannot_partially_finalize')
        clean_recovery()
        # A fault on the *second* insertion must rollback even the first order
        # and the new 003/018 rows; original native accounts remain untouched.
        pay=seed('pg_wallet_bulk_later_sql',2)
        sql(work,env,"DELIMITER //\n"
            "CREATE TRIGGER owned_wallet_second_order_fail BEFORE INSERT ON orders_list "
            "FOR EACH ROW BEGIN IF RIGHT(NEW.remark,2)='_2' THEN "
            "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture_order_second_failure'; "
            "END IF; END//\nDELIMITER ;")
        try:
            failed=run(pay)
        finally:
            sql(work,env,'DROP TRIGGER owned_wallet_second_order_fail')
        assert failed['error'] and failed['state']=='processing_wallet',failed
        assert failed['orders']==0 and failed['delivery']==[] and physical_orders(pay)==0,failed
        assert count('payment_attempts',pay)==0 and balances()==(49900,8)
        assert not any(m=='POST' for m,_ in native_trace())
        scenarios.append('pg_complete_bulk_second_sql_failure_rolls_back_all_local_finality')
        clean_recovery()
        result=run(pay)
        assert result['error'] is None and result['orders']==2,result
        check_terminal(pay,2)
        scenarios.append('pg_complete_bulk_post_rollback_reconciles_original_without_duplicate_post')

        pay=seed('pg_wallet_callback')
        denied=run(pay,op='route',actor=1001,chat=1001)
        assert denied['error'] is None and denied['state']=='processing_wallet',denied
        assert denied['orders']==0 and not any(e['event']=='GET' for e in denied['events'])
        assert not server.trace and balances()==(49900,9)
        scenarios.append('owner_route_rejects_foreign_actor_without_get_finance_or_delivery')
        denied=run(pay,op='route',actor=9001,chat=9002)
        assert denied['error'] is None and denied['state']=='processing_wallet',denied
        assert denied['orders']==0 and not server.trace and balances()==(49900,9)
        scenarios.append('owner_route_rejects_nonprivate_chat_without_get_finance_or_delivery')
        accepted=run(pay,op='route',actor=9001,chat=9001)
        assert accepted['error'] is None and accepted['state']=='paid_with_wallet',accepted
        edits=[e for e in accepted['events'] if e['event']=='OWNER_EDIT']
        assert len(edits)==1 and edits[0]['markup'],accepted
        actions=[button['callback_data'] for row in edits[0]['markup']['inline_keyboard']
                 for button in row]
        assert actions==['deliveryCase_'+str(pay),'serviceRecoveryInbox'],(actions,accepted)
        assert run(pay,op='case')['result'] is False
        assert accepted['orders']==1 and balances()==(49900,9)
        # Follow both advertised links through the actual ManualReceipts route,
        # and assert they remain Owner-visible with no second native operation.
        for destination in actions:
            response=run(pay,op='route',actor=9001,chat=9001,callback=destination)
            pages=[e for e in response['events'] if e['event']=='OWNER_EDIT']
            assert response['error'] is None and len(pages)==1,(destination,response)
            body=pages[0]['body']
            if destination.startswith('deliveryCase_'):
                assert ('بازیابی تحویل پرداخت' in body and
                    f'#{pay}' in body and 'paid_with_wallet' in body), (destination,response)
            else:
                assert 'پرونده‌های نیازمند بررسی ساخت یا تمدید سرویس' in body, (destination,response)
            # A generic unavailable/error screen would still edit successfully;
            # assert the actual requested destination is rendered, not merely
            # the presence of an editText call.
            assert response['orders']==1 and balances()==(49900,9)
            assert not any(m=='POST' for m,_ in native_trace())
        scenarios.append('owner_route_success_offers_only_valid_delivery_and_inbox_navigation')

    return scenarios

if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('--report')
    args=parser.parse_args();result=check()
    if args.report:Path(args.report).write_text(json.dumps(result))
    print(json.dumps(result))
