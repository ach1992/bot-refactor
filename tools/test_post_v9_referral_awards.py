#!/usr/bin/env python3
"""Issue #26: source-real referral-award journal on owned socket-only MariaDB.

No installed schema, bank/merchant funds, Marzban, Telegram or customer data.
Run against current code, not frozen V0-V9 historical baselines.
"""
from __future__ import annotations
import argparse
import json
import subprocess
import time
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

import test_access_state as owned
import test_v6_native as native
import test_v8_native as purchase

ROOT=Path(__file__).resolve().parents[1]
DB=purchase.SIDE[1]
PHP=r"""<?php
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(static function($n,$message){throw new ErrorException($message,0,$n);});
$_SERVER['SCRIPT_FILENAME']=__FILE__;
$root=$argv[1];$spec=json_decode($argv[4],true,512,JSON_THROW_ON_ERROR);
if (!empty($spec['no_throw'])) {
    mysqli_report(MYSQLI_REPORT_OFF);
    set_error_handler(static function(){return true;});
}
$db=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$db->set_charset('utf8mb4');
$events=[];
function sendMessage($message=null,$keys=null,$format=null,$to=null) {
    global $events,$spec;
    $events[]=['event'=>'NOTICE','to'=>(int)$to];
    if (($spec['telegram']??'')==='throw' && $to===9002)
        throw new RuntimeException('fixture_telegram_no_ack');
    if (($spec['telegram']??'')==='false' && $to===9002)
        return (object)['ok'=>false];
    if (($spec['telegram']??'')==='null' && $to===9003)
        return null;
    return (object)['ok'=>true];
}
require $root.'/src/Finance/ReferralReward.php';
$pay=(int)($spec['pay']??0);
if (($spec['operation']??'')==='status') {
    $result=achbotReferralAwardStatus($db,$pay);
} else {
    $result=giveReferralReward($db,(int)($spec['buyer']??1001),
        (int)($spec['inviter']??9002),9001,
        ['rewardChannel'=>9003],$spec['method']??null,$pay);
}
echo json_encode(['result'=>$result,'events'=>$events],JSON_THROW_ON_ERROR);
"""

def sql(work,env,command,db=DB):
    return native.sql(work,env,command,db).strip()

def invoke(work,env,driver,**spec):
    cmd=['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
         '-d','allow_url_fopen=0',
         '-d','disable_functions=curl_init,curl_exec,exec,shell_exec,system,passthru,popen,proc_open,mail',
         str(driver),str(ROOT),str(work/'mysql.sock'),
         spec.pop('database',DB),json.dumps(spec)]
    p=subprocess.run(cmd,cwd=work,env=env,capture_output=True,text=True,timeout=25)
    if p.returncode: raise RuntimeError('PHP failure '+p.stderr[-1600:]+p.stdout[-600:])
    return json.loads(p.stdout)

def seed(work,env,key,*,state='approved',count=1,with_effect=True):
    pid=sql(work,env,f"""INSERT INTO pays
        (hash_id,user_id,type,plan_id,server_id,agent_count,
         day,volume,price,request_date,state)
        VALUES ('{key}',1001,'BUY_SUB',7,2,{count},0,0,100,
        {int(time.time())},'{state}');
        SELECT LAST_INSERT_ID();""").splitlines()[-1]
    pid=int(pid)
    if with_effect:
        for n in range(1,count+1):
            name=f'award_{pid}_{n}'
            sql(work,env,f"""INSERT INTO orders_list
                (userid,token,transid,fileid,server_id,inbound_id,remark,uuid,
                 protocol,expire_date,link,amount,status,date,notif,rahgozar,agent_bought)
                VALUES (1001,'token','','7',2,0,'{name}','fixture-uuid',
                    'vless',2000000000,'[]',100,1,'fixture',0,0,0);
                SET @oid=LAST_INSERT_ID();
                INSERT INTO service_effects
                (pay_row_id,ordinal,operation_id,effect_kind,target_server_id,
                 target_group_id,target_key,endpoint_sha256,plan_id,
                 remote_username,requested_expire,requested_bytes,request_sha256,
                 state,order_id,verified_at)
                VALUES ({pid},{n},'{pid:016x}{n:016x}','create',2,NULL,
                    'server:2',REPEAT('a',64),7,'{name}',2000000000,
                    1073741824,REPEAT('b',64),'local_recorded',@oid,NOW());""")
    return pid

def balance(work,env):
    return int(sql(work,env,'SELECT wallet FROM users WHERE userid=9002'))

def status(work,env,pid):
    return sql(work,env,f"SELECT state FROM referral_awards WHERE pay_row_id={pid}")

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--run-disposable',action='store_true',required=True)
    parser.add_argument('--report',type=Path)
    args=parser.parse_args()
    cases=[]
    with owned.owned_database() as (work,env):
        purchase.init(work,env,'http://127.0.0.1:9')
        sql(work,env,"UPDATE users SET refered_by=9002 WHERE userid=1001")
        sql(work,env,"DELETE FROM setting WHERE type='INVITE_BANNER_AMOUNT'; "
            "INSERT INTO setting(type,value) VALUES ('INVITE_BANNER_AMOUNT','1000')")
        sql(work,env,(ROOT/'migrations/post-v9/001_marzban_panel_group_id.sql').read_text())
        sql(work,env,(ROOT/'migrations/post-v9/007_service_effects.sql').read_text())
        driver=work/'referral-award.php';driver.write_text(PHP)

        missing=seed(work,env,'missing_migration')
        assert invoke(work,env,driver,pay=missing)['result'] is False
        assert balance(work,env)==50000
        cases.append('missing_008_no_wallet_mutation')

        sql(work,env,(ROOT/'migrations/post-v9/008_referral_awards.sql').read_text())
        off=seed(work,env,'disabled_snapshot')
        sql(work,env,"UPDATE setting SET value='0' WHERE type='INVITE_BANNER_AMOUNT'")
        assert invoke(work,env,driver,pay=off)['result'] is True
        assert status(work,env,off)=='DISABLED'
        assert balance(work,env)==50000
        sql(work,env,"UPDATE setting SET value='1000' WHERE type='INVITE_BANNER_AMOUNT'")
        assert invoke(work,env,driver,pay=off)['result'] is False
        assert balance(work,env)==50000 and status(work,env,off)=='DISABLED'
        cases.append('disabled_award_snapshot_never_retroactively_recredits')

        one=seed(work,env,'confirmed')
        result=invoke(work,env,driver,pay=one)
        assert result['result'] is True and [x['to'] for x in result['events']]==[9002,9001,9003],result
        assert balance(work,env)==51000 and status(work,env,one)=='CONFIRMED'
        assert invoke(work,env,driver,pay=one)['result'] is False
        assert balance(work,env)==51000
        cases.append('one_pay_exactly_one_confirmed_credit_no_repeat_notice')

        two=seed(work,env,'two_accounts',count=2)
        assert invoke(work,env,driver,pay=two)['result'] is True
        assert balance(work,env)==52000
        cases.append('two_line_paid_order_one_award_not_per_line')

        race=seed(work,env,'race')
        with ThreadPoolExecutor(max_workers=2) as ex:
            calls=list(ex.map(lambda _:invoke(work,env,driver,pay=race),range(2)))
        assert sum(x['result'] is True for x in calls)==1,calls
        assert balance(work,env)==53000 and status(work,env,race)=='CONFIRMED'
        cases.append('concurrent_duplicate_callbacks_one_credit')

        for mode in ['false','throw','null']:
            pid=seed(work,env,'telegram_'+mode)
            events=invoke(work,env,driver,pay=pid,telegram=mode)
            assert events['result'] is True and len(events['events'])==3,events
            assert status(work,env,pid)=='CONFIRMED'
            assert invoke(work,env,driver,pay=pid)['result'] is False
            cases.append('postcredit_'+mode+'_does_not_reissue_credit')

        no_effect=seed(work,env,'unfulfilled',with_effect=False)
        before=balance(work,env)
        assert invoke(work,env,driver,pay=no_effect)['result'] is False
        assert balance(work,env)==before
        cases.append('paid_state_without_verified_delivery_cannot_credit')

        invalid=seed(work,env,'pending',state='processing')
        assert invoke(work,env,driver,pay=invalid)['result'] is False
        assert balance(work,env)==before
        cases.append('nonterminal_payment_cannot_credit')

        wrong=seed(work,env,'wrong_inviter')
        assert invoke(work,env,driver,pay=wrong,inviter=9001)['result'] is False
        assert balance(work,env)==before
        cases.append('buyer_relationship_verified_not_callback')

        stopped=seed(work,env,'credit_sql_failure')
        sql(work,env,"CREATE TRIGGER owned_referral_credit_failure "
            "BEFORE UPDATE ON users FOR EACH ROW "
            "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture_no_credit'")
        try: result=invoke(work,env,driver,pay=stopped,no_throw=True)
        finally: sql(work,env,'DROP TRIGGER owned_referral_credit_failure')
        assert result['result'] is False
        assert [x['to'] for x in result['events']]==[9001],result
        assert status(work,env,stopped)=='NEEDS_REVIEW' and balance(work,env)==before
        assert invoke(work,env,driver,pay=stopped)['result'] is False
        assert balance(work,env)==before
        cases.append('mysqlireportoff_sql_failure_retained_review_no_blind_retry')

        broken=seed(work,env,'confirm_trigger_failure')
        sql(work,env,"CREATE TRIGGER owned_referral_confirm_failure "
            "BEFORE UPDATE ON referral_awards FOR EACH ROW "
            "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture_no_confirmation'")
        try: failed=invoke(work,env,driver,pay=broken,no_throw=True)
        finally: sql(work,env,'DROP TRIGGER owned_referral_confirm_failure')
        assert failed['result'] is False
        assert [x['to'] for x in failed['events']]==[9001],failed
        assert balance(work,env)==before and status(work,env,broken)=='NOT_ATTEMPTED'
        assert invoke(work,env,driver,pay=broken)['result'] is False
        assert balance(work,env)==before
        cases.append('credit_and_confirmation_atomic_rollback')

        debt=seed(work,env,'negative_admin_debt')
        sql(work,env,'UPDATE users SET wallet=-500 WHERE userid=9002')
        assert invoke(work,env,driver,pay=debt)['result'] is True
        assert balance(work,env)==500 and status(work,env,debt)=='CONFIRMED'
        cases.append('signed_debt_credit_arithmetic_preserved')

        malformed=seed(work,env,'malformed_index')
        sql(work,env,'ALTER TABLE referral_awards DROP FOREIGN KEY fk_referral_award_payment, DROP INDEX uq_referral_award_payment')
        assert invoke(work,env,driver,pay=malformed)['result'] is False
        assert balance(work,env)==500
        cases.append('missing_unique_pay_index_fails_closed')

        payload={'complete':True,'cases':len(cases),'scenarios':cases,
                 'native_php_mariadb':True,'socket_only':True,'production_mutations':0}
        if args.report:args.report.write_text(json.dumps(payload,indent=2)+'\n')
        print(json.dumps(payload))

if __name__=='__main__':
    main()
