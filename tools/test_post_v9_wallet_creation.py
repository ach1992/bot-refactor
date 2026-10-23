#!/usr/bin/env python3
"""Exact real WalletSettlement.php with a 007-backed BUY_SUB fulfillment callback.

Own socket-only MariaDB and pure PHP Marzban/Telegram stubs. No external calls.
"""
from __future__ import annotations
import argparse,json,subprocess
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
import test_access_state as owned
import test_v6_native as native
import test_v8_native as purchase

ROOT=Path(__file__).resolve().parents[1]
DB=purchase.SIDE[1]
PHP=r'''<?php
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(static function($n,$m){throw new ErrorException($m,0,$n);});
$_SERVER['SCRIPT_FILENAME']=__FILE__;
$root=$argv[1];$spec=json_decode($argv[4],true,512,JSON_THROW_ON_ERROR);
$db=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$db->set_charset('utf8mb4');
$connection=$db;
$from_id=1001;$admin=9001;$events=[];$GLOBALS['spec']=$spec;
function alert(...$args){$GLOBALS['events'][]=['event'=>'ALERT','text'=>(string)($args[0]??'')];}
function sendMessage(...$args){$GLOBALS['events'][]=['event'=>'MESSAGE','to'=>$args[3]??null];return (object)['ok'=>true];}
function createServerAccount(...$args){
    $GLOBALS['events'][]=['event'=>'POST','user'=>$args[4]];
    $GLOBALS['remote']=[
        'username'=>(string)$args[4],'note'=>'achbot:v1:'.(string)$args[14],
        'expire'=>(int)$args[15],'data_limit'=>(int)$args[5]*1073741824];
    if (($GLOBALS['spec']['remote']??'ok')==='lost')
        throw new RuntimeException('fixture_response_lost');
    return [(object)['success'=>true],(string)$args[4],12345];
}
function getMarzbanUser($sid,$remark,$token=null,$strict=false) {
    if ($strict!==true) throw new RuntimeException('non_strict_read');
    $GLOBALS['events'][]=['event'=>'GET'];
    $remote=$GLOBALS['remote']??null;
    if (($GLOBALS['spec']['remote']??'')==='wrong_note' && $remote)
        $remote['note']='external_account';
    return $remote?(object)$remote:null;
}
require_once $root.'/src/Purchase/Core.php';
require_once $root.'/src/Purchase/OrderWrites.php';
require_once $root.'/src/Finance/ReceiptHelpers.php';
require_once $root.'/src/Finance/ServiceEffects.php';
require_once $root.'/src/Finance/WalletSettlement.php';
$hash=(string)($spec['hash']??'walletcreate');
// Actual standard BUY_SUB fulfillment is explicitly bound to its 0/0 quote.
$result=processWalletPayment($db,$hash,static function($pay,$owner) use($db,$spec) {
    $uid=(int)$pay['user_id'];
    $pid=(int)$pay['id'];
    $plan=(int)$pay['plan_id'];$server=(int)$pay['server_id'];
    $name='wallet_'.preg_replace('/[^a-z0-9]/','',$pay['hash_id']);
    [$remote,$realName,$port,$effect]=achbotCreatePaidAccount(
        $db,$pid,1,['user_id'=>$uid,'server_type'=>'marzban',
        'server_id'=>$server,'inbound_id'=>0,'plan_id'=>$plan,'remark'=>$name,
        'volume'=>2,'days'=>30,'uniqid'=>'testuuid','protocol'=>'vless',
        'port'=>12345,'expire_microdate'=>0,'net_type'=>'normal',
        'rahgozar'=>0,'limitip'=>0]);
    if ($db->begin_transaction()!==true)
        throw new RuntimeException('fixture_order_begin');
    try {
        if (!empty($spec['order_failure'])) throw new RuntimeException('fixture_order_fail');
        $orderId=insertOrder($db,$uid,'fixture-token',$plan,$server,0,
            $realName,'testuuid','vless',time()+30*86400,
            'fixture-link',(int)$pay['price'],time(),0,0,true);
        achbotLinkPaidAccountEffect($db,$effect,$orderId);
        if ($db->commit()!==true) throw new RuntimeException('fixture_order_commit');
    } catch (Throwable $e) {
        try {$db->rollback();} catch (Throwable $ignored) {}
        throw $e;
    }
    return true;
}, 'standard');
echo json_encode(['result'=>$result,'events'=>$GLOBALS['events']],
    JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
'''

def sql(work,env,command):
    return native.sql(work,env,command,DB).strip()

def run(work,env,driver,**spec):
    p=subprocess.run(['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
        '-d','disable_functions=curl_init,curl_exec,exec,shell_exec,system,passthru,popen,proc_open,mail',
        str(driver),str(ROOT),str(work/'mysql.sock'),DB,json.dumps(spec)],
        cwd=work,env=env,capture_output=True,text=True,timeout=25)
    if p.returncode:raise RuntimeError('wallet native PHP fatal '+repr((p.returncode,p.stderr[-1000:],p.stdout[-300:])))
    try:return json.loads(p.stdout)
    except Exception as e:raise RuntimeError('wallet JSON '+p.stdout[-600:]) from e

def check():
    cases=[]
    with owned.owned_database() as (work,env):
        purchase.init(work,env,'http://127.0.0.1:9')
        for f in ('001_marzban_panel_group_id.sql','003_payment_attempts.sql',
                  '007_service_effects.sql'):
            sql(work,env,(ROOT/'migrations/post-v9'/f).read_text())
        driver=work/'wallet-real.php';driver.write_text(PHP)
        def seed(name='walletcreate',*,capacity=10):
            sql(work,env,f'UPDATE server_info SET ucount={capacity} WHERE id=2')
            sql(work,env,"UPDATE users SET wallet=50000 WHERE userid=1001")
            sql(work,env,"DELETE FROM setting WHERE type='BOT_STATES'")
            sql(work,env,"INSERT INTO setting(type,value) VALUES "
                "('BOT_STATES','{\"walletState\":\"on\",\"gatewayAudience\":{\"wallet\":\"all\"}}')")
            sql(work,env,"INSERT INTO pays "
                "(hash_id,user_id,type,plan_id,server_id,agent_count,price,"
                "day,volume,request_date,state) "
                f"VALUES('{name}',1001,'BUY_SUB',7,2,1,100,0,0,UNIX_TIMESTAMP(),'pending')")
            return int(sql(work,env,f"SELECT id FROM pays WHERE hash_id='{name}'"))
        def status(pay):
            return sql(work,env,f'SELECT state FROM pays WHERE id={pay}')
        def capacity():
            return int(sql(work,env,'SELECT ucount FROM server_info WHERE id=2'))
        def wallet():
            return int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001'))
        def effect(pay):
            return sql(work,env,f"SELECT state,reserved_count FROM service_effects "
                f"WHERE pay_row_id={pay} AND effect_kind='create'")

        a=seed()
        out=run(work,env,driver)
        assert out['result']['status']=='ok',out
        assert len([e for e in out['events'] if e['event']=='POST'])==1
        assert status(a)=='paid_with_wallet' and wallet()==49900 and capacity()==9
        assert effect(a)=='local_recorded\t0'
        assert sql(work,env,f"SELECT state,reserved_count FROM service_effects "
            f"WHERE pay_row_id={a} AND effect_kind='wallet_hold'")==('consumed\t1')
        assert sql(work,env,f"SELECT COALESCE(SUM(reserved_count),0) "
            f"FROM service_effects WHERE pay_row_id={a}")=='1', (
            'wallet debit owns exactly one slot, not a duplicate CREATE hold')
        assert run(work,env,driver)['result']['status']=='already_paid'
        assert wallet()==49900
        cases.append('wallet_confirmed_debit_and_capacity_once_before_post')

        b=seed('walletunknown')
        result=run(work,env,driver,hash='walletunknown',remote='lost')
        assert result['result']['status']=='error'
        assert wallet()==49900 and capacity()==9 and status(b)=='processing_wallet'
        assert effect(b)=='dispatching\t0'
        assert sql(work,env,f"SELECT state FROM service_effects "
            f"WHERE pay_row_id={b} AND effect_kind='wallet_hold'")=='consumed'
        replay=run(work,env,driver,hash='walletunknown')
        assert replay['result']['status']=='in_progress'
        assert not any(e['event']=='POST' for e in replay['events'])
        assert wallet()==49900
        cases.append('wallet_unknown_post_keeps_paid_hold_and_no_replay')

        c=seed('walletbadorder')
        failed=run(work,env,driver,hash='walletbadorder',order_failure=True)
        assert failed['result']['status']=='error' and wallet()==49900
        assert capacity()==9 and status(c)=='processing_wallet'
        assert effect(c)=='verified\t0'
        assert not any(e['event']=='POST' for e in run(work,env,driver,hash='walletbadorder')['events'])
        cases.append('wallet_remote_success_local_order_failure_no_recredit_or_create')

        n=seed('walletnocap',capacity=0)
        refused=run(work,env,driver,hash='walletnocap')
        assert refused['result']['status']=='insufficient_capacity',refused
        assert wallet()==50000 and capacity()==0 and status(n)=='pending'
        assert not any(e['event']=='POST' for e in refused['events'])
        cases.append('wallet_capacity_rejected_before_debit')

        seed('walletmissing',capacity=10)
        sql(work,env,'RENAME TABLE service_effects TO fixture_removed_007')
        try:
            missing=run(work,env,driver,hash='walletmissing')
            assert missing['result']['status']=='error'
            assert wallet()==50000 and not any(e['event']=='POST' for e in missing['events'])
        finally:
            sql(work,env,'RENAME TABLE fixture_removed_007 TO service_effects')
        cases.append('wallet_missing_007_declines_without_financial_mutation')

        seed('walletsqlfail',capacity=10)
        sql(work,env,"CREATE TRIGGER owned_wallet_debit_failure BEFORE UPDATE ON users "
            "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='owned_failure'")
        try: failed=run(work,env,driver,hash='walletsqlfail')
        finally: sql(work,env,'DROP TRIGGER owned_wallet_debit_failure')
        assert failed['result']['status']=='error'
        assert wallet()==50000
        assert sql(work,env,"SELECT state FROM pays WHERE hash_id='walletsqlfail'")=='pending'
        cases.append('wallet_sql_debit_failure_rolls_back_before_post')

        pid=seed('walletrace',capacity=1)
        with ThreadPoolExecutor(max_workers=2) as ex:
            responses=list(ex.map(lambda _:run(work,env,driver,hash='walletrace'),range(2)))
        assert sum(sum(e['event']=='POST' for e in x['events']) for x in responses)==1,responses
        assert status(pid)=='paid_with_wallet' and wallet()==49900 and capacity()==0
        cases.append('wallet_two_concurrent_callbacks_single_debit_and_post')

    return dict(complete=True,cases=len(cases),scenarios=cases,php_mysqli=True,
                provider_real_requests=0,production_mutations=0)

if __name__=='__main__':
    p=argparse.ArgumentParser()
    p.add_argument('--run-disposable',action='store_true',required=True)
    p.add_argument('--report',type=Path)
    a=p.parse_args()
    r=check()
    if a.report:a.report.write_text(json.dumps(r,indent=2)+'\n')
    print(json.dumps(r))
