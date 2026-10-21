#!/usr/bin/env python3
"""Exact current manual accept{hash}: REAL financial/purchase PHP helpers and
owned private socket-only MariaDB. Only Marzban and Telegram wire calls are
synthetic spies. No live tenant or financial operations.
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

PHP=r'''<?php
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(static function($s,$m){if ($s===E_DEPRECATED) return true; throw new ErrorException($m,0,$s);});
$_SERVER['SCRIPT_FILENAME']=__FILE__;
$root=$argv[1];$spec=json_decode($argv[4],true,512,JSON_THROW_ON_ERROR);
if (!empty($spec['nonthrowing'])) mysqli_report(MYSQLI_REPORT_OFF);
$connection=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$connection->set_charset('utf8mb4');
$admin=9001;$from_id=(int)($spec['actor']??9001);
$userInfo=['isAdmin'=>false,'userid'=>$from_id];
$time=1800000000;
$text='';$data='accept'.(string)($spec['hash']??'');
$buttonValues=['cancel'=>'cancel'];
$botState=['remark'=>'manual','rewardChannel'=>null];
$mainValues=[
    'renewed_config_to_user'=>'REMARK VOLUME DAYS',
    'sending_config_to_user'=>'Working',
    'sent_config_to_user'=>'USERID REMARK VOLUME DAYS PRICE HASHID'
];
$botUrl='https://fixture.invalid/';$linkIndices=[];$hideConfigIfMany=false;
$GLOBALS['events']=[];
$GLOBALS['spec']=$spec;
register_shutdown_function(static function(){
    echo json_encode(['events'=>$GLOBALS['events'],'fatal'=>error_get_last()],
        JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
});
function alert(...$a){$GLOBALS['events'][]=['name'=>'alert','text'=>(string)($a[0]??'')];}
function setUser(...$a){
    global $connection;
    $GLOBALS['events'][]=['name'=>'setUser'];
    if (!empty($GLOBALS['spec']['drift_plan_after_claim'])) {
        $connection->query('UPDATE server_plans SET server_id=3 WHERE id=7');
    }
}
function getMainKeys(){return '{}';}
function sendMessage($body=null,$keys=null,$parse=null,$to=null) {
    $GLOBALS['events'][]=['name'=>'MESSAGE','to'=>$to,'text'=>(string)$body];
    $mode=$GLOBALS['spec']['telegram']??'ok';
    if ($mode==='throw' && $to===1001)
        throw new RuntimeException('synthetic_telegram_error');
    if (($mode==='false' || $mode==='null') && $to===9002)
        return $mode==='false'?(object)['ok'=>false]:null;
    if ($mode==='renew_user_false' && $to===1001) return (object)['ok'=>false];
    if ($mode==='renew_user_null' && $to===1001) return null;
    if ($mode==='operator_false' && ($to===9001 || $to===null))
        return (object)['ok'=>false];
    return (object)['ok'=>true];
}
function editKeys(...$args) {
    $GLOBALS['events'][]=['name'=>'EDIT','to'=>$args[2]??null];
    if (($GLOBALS['spec']['telegram']??'')==='edit_false') return (object)['ok'=>false];
    if (($GLOBALS['spec']['telegram']??'')==='edit_null') return null;
    return (object)['ok'=>true];
}
function sendQrFromLink(...$args) {
    $GLOBALS['events'][]=['name'=>'QR','to'=>$args[4]??null];
    $mode=$GLOBALS['spec']['telegram']??'';
    if ($mode==='qr_throw') throw new RuntimeException('synthetic_qr_error');
    if ($mode==='qr_false') return false;
    if ($mode==='qr_null') return null;
    return true;
}
function createServerAccount(...$args) {
    $GLOBALS['events'][]=['name'=>'CREATE','server'=>$args[1], 'remark'=>$args[4]];
    if (($GLOBALS['spec']['remote']??'ok')==='unknown') throw new RuntimeException('lost_create_ack');
    return [(object)['success'=>true],'fixture-account',15001];
}
function achbotSupportsPanel($type){return $type==='marzban';}
function editMarzbanConfig($server,$opts) {
    $GLOBALS['events'][]=['name'=>'EDIT_REMOTE','server'=>$server,'remark'=>$opts['remark']??''];
    return (object)['success'=>true];
}
function generateRandomString(...$args){return 'fixture-alphanumeric-uuid';}
function getNextPortAndIncrease(...$args){return [15001,0];}
function buildLinksAndSendQr(...$args) {
    $GLOBALS['events'][]=['name'=>'LINKS'];
    return ['fixture-token','["vless://fixture.invalid"]','fixture-uuid',
        'fixture account','{}','vless://fixture.invalid'];
}
require_once $root.'/src/Finance/ReceiptHelpers.php';
require_once $root.'/src/Purchase/Core.php';
require_once $root.'/buy_helpers.php'; // actual insertOrder, createIncreaseOrder and capacity
require_once $root.'/src/Finance/ReferralReward.php';
require_once $root.'/src/Finance/RenewalHelper.php';
if (($spec['op']??'route')==='capacity_helper') {
    try {
        decreaseServerCapacity($connection,2,0,7,(int)$spec['count'],true);
        $GLOBALS['events'][]=['name'=>'HELPER_RESULT','value'=>'ok'];
    } catch(Throwable $x) {
        $GLOBALS['events'][]=['name'=>'HELPER_RESULT','value'=>'denied'];
    }
} elseif (($spec['op']??'route')==='telegram_edit_boundary') {
    require_once $root.'/src/Telegram/Messages.php';
    $reply=\AchBot\Telegram\Messages\editKeys(
        static fn($method,$payload)=>(object)['ok'=>false],
        9001,99,'{}',null,9001);
    $GLOBALS['events'][]=['name'=>'TELEGRAM_BOUNDARY','denied'=>is_object($reply)&&($reply->ok??true)===false];
} else {
    require $root.'/src/Finance/Routes/ManualApproval.php';
}
'''

def sql(work,env,query):
    return native.sql(work,env,query,DB).strip()

def php_call(work,env,driver,**spec):
    proc=subprocess.run([
        'php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
        '-d','extension=curl','-d','allow_url_fopen=0',
        '-d','disable_functions=exec,shell_exec,system,passthru,popen,proc_open,mail',
        str(driver),str(ROOT),str(work/'mysql.sock'),DB,
        json.dumps(spec,separators=(',',':'))],
        cwd=work,env=env,capture_output=True,text=True,timeout=32)
    if proc.returncode:
        raise RuntimeError(f'PHP status={proc.returncode} stderr={proc.stderr[-1500:]} stdout={proc.stdout[-750:]}')
    try: data=json.loads(proc.stdout)
    except Exception as e: raise RuntimeError(f'PHP response malformed {proc.stderr[-1200:]} {proc.stdout[-900:]}') from e
    if data['fatal']:raise RuntimeError('PHP fatal '+str(data['fatal'])[:1100])
    return data['events']

def named(events,name):
    return [ev for ev in events if ev.get('name')==name]

def seed(work,env,name,*,capacity=10,count=1,renew=False,inviter=False):
    purchase.init(work,env,'http://127.0.0.1:9')
    sql(work,env,"DELETE FROM setting WHERE type='INVITE_BANNER_AMOUNT'")
    if inviter:
        sql(work,env,"UPDATE users SET refered_by=9002 WHERE userid=1001")
        sql(work,env,"INSERT INTO setting(type,value) VALUES('INVITE_BANNER_AMOUNT','1000')")
    else:
        sql(work,env,"UPDATE users SET refered_by=NULL WHERE userid=1001")
    sql(work,env,f'UPDATE server_info SET ucount={capacity} WHERE id=2')
    desc='{"uuid":"fixture-uuid","remark":"fixture-account","server_id":2,"marzban":true}' if renew else 'fixture-account'
    desc=desc.replace("'","''")
    typ='RENEW_SCONFIG' if renew else 'BUY_SUB'
    sql(work,env,f"INSERT INTO pays (hash_id,user_id,type,plan_id,server_id,agent_count,"
        f"day,volume,price,request_date,state,message_id,chat_id,description) VALUES"
        f"('{name}',1001,'{typ}',7,2,{count},0,0,100,1700000000,'have_sent',"
        f"211,9001,'{desc}')")
    return name

def check():
    cases=[]
    with owned.owned_database() as (work,env):
        driver=work/'native-manual-standard-integrity.php'
        driver.write_text(PHP)

        def call(key,**spec):return php_call(work,env,driver,hash=key,**spec)
        def state(key):return sql(work,env,f"SELECT state FROM pays WHERE hash_id='{key}'")
        def capacity():return int(sql(work,env,'SELECT ucount FROM server_info WHERE id=2'))
        def orders():return int(sql(work,env,'SELECT COUNT(*) FROM orders_list'))

        key=seed(work,env,'too_many',capacity=1,count=2)
        ev=call(key)
        assert not named(ev,'CREATE') and state(key)=='have_sent' and capacity()==1
        cases.append('r1_insufficient_total_count_prevents_any_remote_create')

        key=seed(work,env,'capacity_exact',capacity=2,count=2)
        ev=call(key)
        assert len(named(ev,'CREATE'))==2 and orders()==2 and capacity()==0
        assert state(key)=='approved'
        assert not named(call(key),'CREATE') and capacity()==0
        cases.append('r1_exact_two_units_reserved_once_and_replay_fenced')

        key=seed(work,env,'lost_remote',capacity=2,count=2)
        ev=call(key,remote='unknown')
        assert len(named(ev,'CREATE'))==1 and state(key)=='processing'
        assert capacity()==0 and orders()==0
        assert not named(call(key),'CREATE') and capacity()==0
        cases.append('r1_remote_unknown_keeps_pre_reserved_capacity_no_replay')

        key=seed(work,env,'postcreate_sql',capacity=2,count=2)
        sql(work,env,"CREATE TRIGGER owned_order_insert_fail BEFORE INSERT ON orders_list "
            "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture_insert_failure'")
        try: ev=call(key,nonthrowing=True)
        finally:sql(work,env,'DROP TRIGGER owned_order_insert_fail')
        assert len(named(ev,'CREATE'))==2 and state(key)=='processing'
        assert capacity()==0 and orders()==0 and not named(call(key),'CREATE')
        cases.append('r1_after_two_remote_creates_SQL_failure_keeps_reserved_capacity_review_only')

        key=seed(work,env,'sql_capacity',capacity=3)
        sql(work,env,"CREATE TRIGGER owned_manual_capacity_fail BEFORE UPDATE ON server_info "
            "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture_sql_fail'")
        try: ev=call(key,nonthrowing=True)
        finally:sql(work,env,'DROP TRIGGER owned_manual_capacity_fail')
        assert state(key)=='have_sent' and capacity()==3 and not named(ev,'CREATE')
        cases.append('r1_mysqlireportoff_conditional_reservation_SQL_failure_no_create')

        seed(work,env,'capacity_helper',capacity=1)
        ev=call('capacity_helper',op='capacity_helper',count=2,nonthrowing=True)
        assert named(ev,'HELPER_RESULT')[0]['value']=='denied' and capacity()==1
        cases.append('r1_real_conditional_capacity_helper_cannot_go_negative')

        key=seed(work,env,'claim_a',capacity=1)
        sql(work,env,"INSERT INTO pays (hash_id,user_id,type,plan_id,server_id,"
            "agent_count,day,volume,price,request_date,state,message_id,chat_id,description)"
            "VALUES ('claim_b',1001,'BUY_SUB',7,2,1,0,0,100,1700000000,"
            "'have_sent',212,9001,'other-account')")
        with ThreadPoolExecutor(max_workers=2) as pool:
            ea,eb=list(pool.map(lambda k:call(k),['claim_a','claim_b']))
        creates=len(named(ea,'CREATE'))+len(named(eb,'CREATE'))
        assert creates==1 and capacity()==0 and orders()==1
        assert sorted([state('claim_a'),state('claim_b')])==['approved','have_sent']
        cases.append('r1_two_real_php_buyers_one_capacity_unit_only_one_external_create')

        key=seed(work,env,'renew_drift',renew=True)
        ev=call(key,drift_plan_after_claim=True)
        assert not named(ev,'EDIT_REMOTE') and state(key)=='processing'
        assert sql(work,env,'SELECT server_id FROM server_plans WHERE id=7')=='3'
        assert not named(call(key),'EDIT_REMOTE')
        cases.append('r2_plan_reassigned_to_other_remote_with_same_remark_zero_wrong_edit')

        key=seed(work,env,'renew_stable',renew=True)
        ev=call(key)
        assert len(named(ev,'EDIT_REMOTE'))==1 and named(ev,'EDIT_REMOTE')[0]['server']==2,(ev,state(key))
        assert state(key)=='approved' and int(sql(work,env,'SELECT COUNT(*) FROM increase_order'))==1,(ev,state(key),sql(work,env,'SELECT COUNT(*) FROM increase_order'))
        cases.append('r2_persisted_payment_server_pinned_on_successful_renewal')

        for mode in ['renew_user_false','renew_user_null']:
            key=seed(work,env,'renew_telegram_'+mode,renew=True)
            ev=call(key,telegram=mode)
            assert state(key)=='approved' and len(named(ev,'EDIT_REMOTE'))==1
            assert any('اطلاع‌رسانی' in x['text'] for x in named(ev,'MESSAGE') if x['to']==9001)
            assert not named(call(key),'EDIT_REMOTE')
            cases.append('r3_nonthrowing_renewal_customer_notice_'+mode+'_already_approved')

        for mode in ['qr_false','qr_null','qr_throw','operator_false','edit_false','edit_null']:
            key=seed(work,env,'telegram_'+mode)
            ev=call(key,telegram=mode)
            assert state(key)=='approved' and orders()==1 and capacity()==9
            assert len(named(ev,'CREATE'))==1
            alert=' '.join(str(x['text']) for x in named(ev,'MESSAGE') if x['to']==9001)
            assert ('ناقص' in alert or 'تأیید نشد' in alert),(mode,ev)
            assert not named(call(key),'CREATE')
            cases.append('r3_nonthrowing_or_thrown_telegram_'+mode+'_approved_no_replay')

        ev=call('no_payment',op='telegram_edit_boundary')
        assert named(ev,'TELEGRAM_BOUNDARY')[0]['denied'] is True
        cases.append('r3_real_telegram_editKeys_propagates_false_response')

        key=seed(work,env,'reward_ok',inviter=True)
        ev=call(key)
        assert state(key)=='approved' and sql(work,env,'SELECT wallet FROM users WHERE userid=9002')=='51000',(ev,state(key),sql(work,env,'SELECT wallet FROM users WHERE userid=9002'))
        assert any('جایزه' in x['text'] for x in named(ev,'MESSAGE') if x['to']==9002)
        assert not named(call(key),'CREATE')
        assert sql(work,env,'SELECT wallet FROM users WHERE userid=9002')=='51000'
        cases.append('r4_referral_confirmed_one_wallet_update_no_callback_recredit')

        key=seed(work,env,'reward_failed',inviter=True)
        sql(work,env,"CREATE TRIGGER owned_reward_fail BEFORE UPDATE ON users "
            "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture_reward_fail'")
        try: ev=call(key,nonthrowing=True)
        finally:sql(work,env,'DROP TRIGGER owned_reward_fail')
        assert state(key)=='approved' and sql(work,env,'SELECT wallet FROM users WHERE userid=9002')=='50000'
        assert not any('جایزه دریافت کردید' in x['text'] for x in named(ev,'MESSAGE'))
        assert not named(call(key),'CREATE')
        cases.append('r4_mysqlireportoff_reward_write_failure_no_false_credit_notice')

        key=seed(work,env,'reward_overflow',inviter=True)
        sql(work,env,'UPDATE users SET wallet=2147483647 WHERE userid=9002')
        ev=call(key,nonthrowing=True)
        assert state(key)=='approved' and sql(work,env,'SELECT wallet FROM users WHERE userid=9002')=='2147483647'
        assert not any('جایزه دریافت کردید' in x['text'] for x in named(ev,'MESSAGE'))
        cases.append('r4_wallet_overflow_fails_without_false_ack')

        key=seed(work,env,'reward_notice_fail',inviter=True)
        ev=call(key,telegram='false')
        assert state(key)=='approved' and sql(work,env,'SELECT wallet FROM users WHERE userid=9002')=='51000'
        assert not named(call(key),'CREATE')
        assert sql(work,env,'SELECT wallet FROM users WHERE userid=9002')=='51000'
        cases.append('r4_postcredit_telegram_failure_never_recredits')

        key=seed(work,env,'reward_missing',inviter=True)
        sql(work,env,'DELETE FROM users WHERE userid=9002')
        ev=call(key,nonthrowing=True)
        assert state(key)=='approved' and not any('جایزه دریافت کردید' in x['text'] for x in named(ev,'MESSAGE'))
        cases.append('r4_missing_inviter_no_false_credit_notice')

        key=seed(work,env,'old_23_schema')
        assert len(sql(work,env,'SHOW TABLES').splitlines())==23
        ev=call(key)
        assert state(key)=='approved' and len(named(ev,'CREATE'))==1
        cases.append('r5_historical_23_table_operator_delivered_receipt_remains_approvable')

        key=seed(work,env,'old_missing_evidence')
        sql(work,env,"UPDATE pays SET message_id=0 WHERE hash_id='old_missing_evidence'")
        ev=call(key)
        assert state(key)=='have_sent' and not named(ev,'CREATE')
        cases.append('r5_absent_original_operator_evidence_is_rejected')

        key=seed(work,env,'partial_003')
        sql(work,env,'CREATE TABLE payment_transactions (id INT PRIMARY KEY) ENGINE=InnoDB')
        try:
            ev=call(key,nonthrowing=True)
            assert state(key)=='have_sent' and not named(ev,'CREATE')
        finally:
            sql(work,env,'DROP TABLE payment_transactions')
        cases.append('r5_partially_installed_003_denied_under_mysqlireportoff')

        key=seed(work,env,'invalid_003_schema')
        sql(work,env,'CREATE TABLE payment_attempts (id INT PRIMARY KEY) ENGINE=InnoDB')
        try:
            ev=call(key,nonthrowing=True)
            assert state(key)=='have_sent' and not named(ev,'CREATE')
        finally:
            sql(work,env,'DROP TABLE payment_attempts')
        cases.append('r5_incompatible_ledger_column_SQL_failure_not_legacy_receipt')

        key=seed(work,env,'003_present')
        sql(work,env,(ROOT/'migrations/post-v9/003_payment_attempts.sql').read_text())
        ev=call(key)
        assert state(key)=='approved' and len(named(ev,'CREATE'))==1
        cases.append('r5_complete_003_empty_attempts_historical_receipt_allowed')

        return {'complete':True,'cases':len(cases),'scenarios':cases,
            'actual_php_core_helpers':True,'actual_mariadb_capacity':True,
            'actual_mysqlireportoff_failure':True,'isolated_marzban_telegram_spies':True,
            'production_mutations':0,'installed_acceptance':False}

def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--run-disposable',action='store_true',required=True)
    parser.add_argument('--report',type=Path)
    a=parser.parse_args()
    result=check()
    if a.report:a.report.write_text(json.dumps(result,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps(result,ensure_ascii=False))

if __name__=='__main__':main()
