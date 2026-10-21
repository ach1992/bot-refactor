#!/usr/bin/env python3
"""AChBot #26: real current bank-Cron PHP + socket-only disposable MariaDB.

Real SQL, source routes and journal are exercised against synthetic remote
CREATE/GET and Telegram spies. No actual bot, paid provider or customer.
"""
from __future__ import annotations

import argparse
import json
import subprocess
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

import test_access_state as owned
import test_v6_native as native
import test_v8_native as purchase

ROOT=Path(__file__).resolve().parents[1]
DB=purchase.SIDE[1]
PHP=r"""<?php
error_reporting(E_ALL);
set_error_handler(static function($level,$msg) {
    if ($level===E_DEPRECATED) return true;
    throw new ErrorException($msg,0,$level);
});
$_SERVER['SCRIPT_FILENAME']=__FILE__;
$root=$argv[1];$socket=$argv[2];$dbName=$argv[3];
$spec=json_decode($argv[4],true,512,JSON_THROW_ON_ERROR);
$connection=new mysqli('localhost','root','',$dbName,0,$socket);
$connection->set_charset('utf8mb4');
if (!empty($spec['nonthrowing'])) mysqli_report(MYSQLI_REPORT_OFF);
$botState=['cartToCartAutoAcceptState'=>'on','cartToCartAutoAcceptTime'=>1,
    'cartToCartAutoAcceptType'=>2,'remark'=>'default','subLinkState'=>'on',
    'configLinkState'=>'off'];
$admin=9001;$botUrl='https://fixture.invalid';
$buttonValues=['start_bot'=>'Start'];$linkIndices=[0];$hideConfigIfMany=false;
$GLOBALS['events']=[];$GLOBALS['spec']=$spec;
function fixtureState() {
    global $connection;
    $id=(int)($GLOBALS['spec']['pay']??0);
    $result=$connection->query('SELECT state FROM pays WHERE id='.$id);
    return $result?($result->fetch_row()[0]??null):null;
}
function createServerAccount(...$args) {
    $mode=$GLOBALS['spec']['mode']??'ok';
    $n=($GLOBALS['posts']??0)+1;$GLOBALS['posts']=$n;
    $name=(string)$args[4];$operation=(string)$args[14];
    $GLOBALS['events'][]=['event'=>'POST','line'=>$n,
        'username'=>$name,'state'=>fixtureState()];
    $GLOBALS['remote']=['username'=>$name,'note'=>'achbot:v1:'.$operation,
        'expire'=>(int)$args[15],'data_limit'=>(int)$args[5]*1073741824];
    if ($mode==='lost' || ($mode==='partial' && $n===2))
        throw new RuntimeException('synthetic_post_response_lost');
    if ($mode==='exists')
        return [(object)['success'=>false,'msg'=>'User already exists'],$name,12345];
    return [(object)['success'=>true,'sub_link'=>'/sub/fixture',
        'vray_links'=>['vless://fixture.invalid']],$name,12345];
}
function getMarzbanUser($sid,$name,$token=null,$strict=false) {
    if ($strict!==true) throw new RuntimeException('non_strict_remote_get');
    $GLOBALS['events'][]=['event'=>'GET','username'=>$name];
    $remote=$GLOBALS['remote']??null;
    if (!$remote) return null;
    $mode=$GLOBALS['spec']['mode']??'ok';
    if ($mode==='wrong_note') $remote['note']='other-party';
    if ($mode==='fraction') $remote['data_limit']+=0.5;
    return (object)$remote;
}
function buildLinksAndSendQr(...$args) {
    if (end($args)!==false) throw new RuntimeException('premature_qr_delivery');
    $GLOBALS['events'][]=['event'=>'RENDER','state'=>fixtureState()];
    return ['fixture-token','["vless://fixture.invalid"]','fixture-uuid',
        'مشخصات اشتراک','{}','vless://fixture.invalid'];
}
function sendQrFromLink(...$args) {
    $GLOBALS['events'][]=['event'=>'DELIVER','state'=>fixtureState()];
    if (($GLOBALS['spec']['mode']??'')==='telegram_failure')
        throw new RuntimeException('synthetic_telegram_failure');
    return true;
}
function sendMessage($text=null,$keys=null,$mode=null,$to=null) {
    $GLOBALS['events'][]=['event'=>'MESSAGE','state'=>fixtureState(),
        'success'=>strpos((string)$text,'موفق')!==false,'to'=>$to];
    return (object)['ok'=>true];
}
function editKeys(...$args) {
    $GLOBALS['events'][]=['event'=>'EDIT_KEYS','state'=>fixtureState()];
}
function getMainKeys() {return '{}';}
function sendToAllAdmins(...$args) {return null;}
function giveReferralReward(...$args) {$GLOBALS['events'][]=['event'=>'REFERRAL'];}
function generateRandomString($count,$protocol=null) {return str_repeat('a',max(1,(int)$count));}
function achbotApplyServiceEdit(...$args) {throw new RuntimeException('unexpected_edit');}
function achbotSupportsPanel($type) {return $type==='marzban';}
require_once $root.'/src/Purchase/Core.php';
require_once $root.'/src/Purchase/OrderWrites.php';
require_once $root.'/src/Finance/ReceiptHelpers.php';
require_once $root.'/src/Finance/ServiceEffects.php';
$op=$spec['op']??'auto';
if ($op==='claim') {
    $id=(int)($spec['pay']??0);
    $pay=$connection->query('SELECT * FROM pays WHERE id='.$id)->fetch_assoc();
    $value=achbotAutoBankClaim($connection,$pay);
} else {
    require $root.'/src/Finance/AutomaticReceipts.php';
    $value=true;
}
echo json_encode(['result'=>$value,'events'=>$GLOBALS['events']],
    JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
"""

def query(work,env,sql,db=DB):
    return native.sql(work,env,sql,db).strip()

def run(work,env,driver,**spec):
    args=['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
          '-d','allow_url_fopen=0','-d','display_errors=0',
          '-d','disable_functions=curl_init,curl_exec,exec,shell_exec,system,passthru,popen,proc_open,mail',
          str(driver),str(ROOT),str(work/'mysql.sock'),DB,json.dumps(spec)]
    p=subprocess.run(args,cwd=work,env=env,capture_output=True,text=True,timeout=25)
    if p.returncode or not p.stdout.strip():
        raise RuntimeError('native bank PHP failed '+repr((p.returncode,p.stderr[-1250:])))
    try: return json.loads(p.stdout)
    except ValueError as e: raise RuntimeError('unexpected PHP output '+p.stdout[-700:]) from e

def seed(work,env,key,count=1,plan=7,method=None):
    # Only this due payment is processed in the current synthetic Cron scan.
    query(work,env,"UPDATE pays SET request_date=UNIX_TIMESTAMP() WHERE state='have_sent'")
    query(work,env,
        "INSERT INTO pays (hash_id,user_id,type,plan_id,server_id,volume,day,"
        "price,request_date,state,agent_count,message_id,chat_id) VALUES "
        f"('{key}',1001,'BUY_SUB',{plan},2,0,0,101,UNIX_TIMESTAMP()-120,"
        f"'have_sent',{count},550,9001)")
    pid=int(query(work,env,f"SELECT id FROM pays WHERE hash_id='{key}'"))
    if method:
        query(work,env,f"INSERT INTO payment_attempts "
           "(pay_row_id,provider,order_hash,quote_amount,quote_currency,local_amount_toman) "
           f"VALUES ({pid},'{method}','{key}',101,'toman',101)")
    return pid

def state(work,env,pid):
    return query(work,env,f"SELECT state FROM pays WHERE id={pid}")

def amount(work,env,pid):
    return int(query(work,env,
        "SELECT COALESCE(SUM(o.amount),0) FROM orders_list o "
        "JOIN service_effects e ON e.order_id=o.id "
        f"WHERE e.pay_row_id={pid}"))

def effects(work,env,pid):
    return query(work,env,f"SELECT GROUP_CONCAT(state ORDER BY ordinal) "
               f"FROM service_effects WHERE pay_row_id={pid}")

def total(work,env,table,pid):
    if table=='orders_list':
        return int(query(work,env,
           "SELECT COUNT(*) FROM service_effects e JOIN orders_list o "
           f"ON o.id=e.order_id WHERE e.pay_row_id={pid}"))
    return int(query(work,env,
        f"SELECT COUNT(*) FROM {table} WHERE pay_row_id={pid}"))

def check():
    cases=[]
    with owned.owned_database() as (work,env):
        purchase.init(work,env,'http://127.0.0.1:9')
        assert len(query(work,env,'SHOW TABLES').splitlines())==23
        for f in ('001_marzban_panel_group_id.sql',
                  '003_payment_attempts.sql','007_service_effects.sql'):
            query(work,env,(ROOT/'migrations/post-v9'/f).read_text())
        driver=work/'bank-buy-driver.php';driver.write_text(PHP)
        claim=seed(work,env,'bankbuynonterminal')
        assert run(work,env,driver,op='claim',pay=claim)['result'] is True
        assert state(work,env,claim)=='processing'
        assert run(work,env,driver,op='claim',pay=claim)['result'] is False
        cases.append('buy_sub_nonterminal_exclusive_claim')

        good=seed(work,env,'bankbuygood')
        ok=run(work,env,driver,pay=good)
        assert state(work,env,good)=='auto_cart_approved',ok
        assert [e['event'] for e in ok['events']
            if e['event'] in ('POST','GET','DELIVER')]==['POST','GET','DELIVER'],ok
        assert all(e['state']=='auto_cart_approved' for e in ok['events']
            if e['event']=='DELIVER')
        assert total(work,env,'orders_list',good)==1
        assert amount(work,env,good)==101
        assert effects(work,env,good)=='local_recorded'
        assert not any(e['event']=='POST' for e in run(work,env,driver,pay=good)['events'])
        cases.append('one_verified_account_atomic_order_before_telegram_no_replay')

        for mode in ('lost','exists','wrong_note','fraction'):
            pid=seed(work,env,'bankbuy'+mode)
            result=run(work,env,driver,pay=pid,mode=mode)
            assert state(work,env,pid)=='processing',(mode,result)
            assert total(work,env,'orders_list',pid)==0
            assert effects(work,env,pid)=='dispatching'
            assert not any(e['event']=='DELIVER' or
                (e['event']=='MESSAGE' and e['success']) for e in result['events'])
            assert not any(e['event']=='POST' for e in run(work,env,driver,pay=pid)['events'])
            cases.append('unknown_'+mode+'_no_refund_success_or_replay')

        multi=seed(work,env,'bankbuymulti',count=2)
        success=run(work,env,driver,pay=multi)
        assert state(work,env,multi)=='auto_cart_approved',success
        assert effects(work,env,multi)=='local_recorded,local_recorded'
        assert total(work,env,'orders_list',multi)==2 and amount(work,env,multi)==101
        assert len([e for e in success['events'] if e['event']=='DELIVER'])==2
        cases.append('multi_line_atomic_orders_and_integer_amount_allocation')

        partial=seed(work,env,'bankbuypartial',count=2)
        mixed=run(work,env,driver,pay=partial,mode='partial')
        assert state(work,env,partial)=='processing',mixed
        assert effects(work,env,partial)=='verified,dispatching'
        assert total(work,env,'orders_list',partial)==0
        assert not any(e['event']=='DELIVER' for e in mixed['events'])
        cases.append('multi_line_partial_unknown_requires_review_not_refund')

        sqlfail=seed(work,env,'bankbuysqlfail')
        query(work,env,"CREATE TRIGGER fixture_bank_order_fail BEFORE INSERT ON orders_list "
            "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture_sql_failure'")
        try: failed=run(work,env,driver,pay=sqlfail,nonthrowing=True)
        finally: query(work,env,'DROP TRIGGER fixture_bank_order_fail')
        assert state(work,env,sqlfail)=='processing',failed
        assert effects(work,env,sqlfail)=='verified'
        assert total(work,env,'orders_list',sqlfail)==0
        assert not any(e['event']=='DELIVER' for e in failed['events'])
        cases.append('sql_failure_after_remote_verified_fails_closed')

        notice=seed(work,env,'bankbuytelegram')
        result=run(work,env,driver,pay=notice,mode='telegram_failure')
        assert state(work,env,notice)=='auto_cart_approved',result
        assert total(work,env,'orders_list',notice)==1
        assert not any(e['event']=='POST' for e in run(work,env,driver,pay=notice)['events'])
        cases.append('telegram_postcommit_exception_never_recreates')

        wrong=seed(work,env,'bankbuyotherprovider',method='nowpayments')
        blocked=run(work,env,driver,pay=wrong)
        assert state(work,env,wrong)=='have_sent',blocked
        assert total(work,env,'service_effects',wrong)==0
        assert not any(e['event']=='POST' for e in blocked['events'])
        cases.append('nonbank_attempt_never_auto_provisions')

        race=seed(work,env,'bankbuyrace')
        with ThreadPoolExecutor(max_workers=2) as pool:
            results=list(pool.map(lambda _:run(work,env,driver,pay=race),range(2)))
        assert sum(e['event']=='POST' for result in results
            for e in result['events'])==1,results
        assert state(work,env,race)=='auto_cart_approved'
        cases.append('two_simultaneous_bank_workers_one_remote_create')
    return dict(complete=True,cases=len(cases),scenarios=cases,
        source_real_php=True,owned_mariadb_only=True,
        real_provider_requests=0,real_financial_effects=0,
        installed_operator_tested=False)

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--run-disposable',action='store_true',required=True)
    parser.add_argument('--report',type=Path)
    args=parser.parse_args()
    result=check()
    if args.report:args.report.write_text(json.dumps(result,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps(result,ensure_ascii=False))

if __name__=='__main__':main()
