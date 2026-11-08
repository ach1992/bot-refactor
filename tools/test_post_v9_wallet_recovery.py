#!/usr/bin/env python3
"""Issue #117: source-real wallet BUY_SUB original-effect continuation.

Only disposable MariaDB, PHP 8.5, synthetic native GET and Telegram spies.
No live provider or customer. Missing original ordinal must never POST.
"""
from __future__ import annotations

import argparse
import json
import subprocess
from pathlib import Path

import test_access_state as owned
import test_v6_native as native
import test_v8_native as purchase
import test_post_v9_wallet_creation as wallet

ROOT=Path(__file__).resolve().parents[1]
DB=purchase.SIDE[1]

RECOVERY=r'''<?php
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(static function($n,$m) {throw new ErrorException($m,0,$n);});
$_SERVER['SCRIPT_FILENAME']=__FILE__;
$root=$argv[1];$spec=json_decode($argv[4],true,512,JSON_THROW_ON_ERROR);
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
$pay=(int)$spec['pay'];$result=null;$error=null;
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
    elseif (($spec['op']??'')==='check') $result=achbotDeliveryCheck($connection,$pay,1);
    else $result=achbotResumeWalletBuy($connection,$pay,$botState,$buttonValues,
        $botUrl,$reader);
} catch (Throwable $e) {$error=$e->getMessage();}
$state=$connection->query('SELECT state FROM pays WHERE id='.$pay)->fetch_row()[0]??null;
$orders=(int)$connection->query('SELECT COUNT(*) FROM orders_list o JOIN service_effects e
    ON e.order_id=o.id WHERE e.pay_row_id='.$pay)->fetch_row()[0];
$attempt=$connection->query('SELECT provider,stage,provider_status FROM payment_attempts WHERE pay_row_id='.$pay)
    ->fetch_assoc();
$delivery=$connection->query('SELECT ordinal,state,attempt_count FROM payment_delivery_intents
    WHERE pay_row_id='.$pay.' ORDER BY ordinal')->fetch_all(MYSQLI_ASSOC);
echo json_encode(['result'=>$result,'error'=>$error,'state'=>$state,'orders'=>$orders,
    'attempt'=>$attempt,'delivery'=>$delivery,'events'=>$GLOBALS['events']],
    JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
'''

def sql(work,env,command):
    return native.sql(work,env,command,DB).strip()

def recover(work,env,driver,**spec):
    p=subprocess.run(['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
        '-d','extension=openssl',
        '-d','disable_functions=curl_init,curl_exec,exec,shell_exec,system,passthru,popen,proc_open,mail',
        str(driver),str(ROOT),str(work/'mysql.sock'),DB,json.dumps(spec)],
        cwd=work,env=env,capture_output=True,text=True,timeout=35)
    if p.returncode:
        raise RuntimeError('wallet recovery PHP fatal '+repr((p.returncode,p.stderr[-1500:],p.stdout[-400:])))
    try:return json.loads(p.stdout)
    except Exception as error:raise RuntimeError('wallet recovery JSON '+p.stdout[-600:]) from error

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
        assert recover(work,env,driver,pay=pay,op='check')['result']=='acknowledged'
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
    return {'complete':True,'cases':len(cases),'source_real':True,
        'php85_required':True,'disposable_mariadb':True,'original_native_post_replays':0,
        'original_wallet_duplicate_debits':0,'scenarios':cases}

if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('--report')
    args=parser.parse_args();result=check()
    if args.report:Path(args.report).write_text(json.dumps(result))
    print(json.dumps(result))
