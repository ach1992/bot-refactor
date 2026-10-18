#!/usr/bin/env python3
"""Source-only native admin-wallet journal tests on disposable, socket-only MariaDB.

Executes the actual current post-V9 PHP route; fake Telegram functions and
local PHP/MariaDB worker processes only. No live account, payment, or provider.
"""
from __future__ import annotations
import argparse
import json
import subprocess
import time
from pathlib import Path

import test_access_state as owned
import test_v6_native as native

ROOT = Path(__file__).resolve().parents[1]
DB = "achbot_v4_new"
MIGRATION = ROOT / "migrations/post-v9/005_admin_wallet_adjustments.sql"

PHP = r"""<?php
if (PHP_SAPI!=='cli' || !extension_loaded('mysqli')) exit(3);
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(function($n,$m){ throw new ErrorException($m,0,$n); });
$_SERVER['SCRIPT_FILENAME']='owned-wallet-driver.php';
$root=$argv[1];
$spec=json_decode($argv[4],true,512,JSON_THROW_ON_ERROR);
$connection=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$connection->set_charset('utf8mb4');
if (!empty($spec['mysqli_report_off'])) {
    mysqli_report(MYSQLI_REPORT_OFF);
    set_error_handler(function($n,$m){return true;});
}
$admin=9001;
$from_id=(int)($spec['actor']??9001);
$chat_id=$from_id;
$message_id=(int)($spec['message_id']??91);
$update=(object)['update_id'=>(int)($spec['update_id']??701)];
$data=$spec['data']??'';
$text=$spec['text']??'';
$events=[];
$stmt=$connection->prepare('SELECT userid,step,isAdmin,wallet FROM users WHERE userid=?');
$stmt->bind_param('i',$from_id);
$stmt->execute();
$userInfo=$stmt->get_result()->fetch_assoc() ?: ['step'=>'none','isAdmin'=>0];
$stmt->close();
$mainValues=[
 'send_user_id'=>'send-user', 'send_only_number'=>'send-number',
 'user_not_found'=>'user-not-found', 'reached_main_menu'=>'main',
 'enter_increase_amount'=>'enter-increase', 'enter_decrease_amount'=>'enter-decrease',
 'enter_debt_amount'=>'enter-debt',
];
$buttonValues=['cancel'=>'cancel'];
$cancelKey='cancel';
$removeKeyboard='remove';
function sendMessage(...$args) {
    $GLOBALS['events'][]=['kind'=>'sendMessage','args'=>$args];
    return !($GLOBALS['spec']['notification_failure']??false) ? (object)['ok'=>true] : null;
}
function delMessage(...$args) {$GLOBALS['events'][]=['kind'=>'delMessage'];}
function getMainKeys() {return 'main-keys';}
function setUser($step='none') {
    $stmt=$GLOBALS['connection']->prepare('UPDATE users SET step=? WHERE userid=?');
    $stmt->bind_param('si',$step,$GLOBALS['from_id']);
    $stmt->execute();$stmt->close();
    $GLOBALS['events'][]=['kind'=>'setUser','step'=>$step];
}
if (!empty($spec['barrier'])) {
    $barrier=(string)$spec['barrier'];
    file_put_contents($barrier.'.'.$update->update_id,'ready');
    $end=microtime(true)+8;
    while (!file_exists($barrier.'.go')) {
        if (microtime(true)>$end) throw new RuntimeException('barrier_expired');
        usleep(10000);
    }
}
require $root.'/src/Finance/Routes/AdminWallet.php';
echo json_encode(['events'=>$events],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
"""

def sql(work, env, query):
    return native.sql(work, env, query, DB).strip()

def balance(work, env, who=1001):
    return int(sql(work,env,f"SELECT wallet FROM users WHERE userid={who}"))

def step(work, env, who=9001):
    return sql(work,env,f"SELECT step FROM users WHERE userid={who}")

def nledger(work, env):
    return int(sql(work,env,"SELECT COUNT(*) FROM admin_wallet_adjustments"))

def journal(work, env, update_id):
    return sql(work,env,"SELECT CONCAT(operator_userid,':',target_userid,':',"
        "adjustment_type,':',amount_toman,':',before_balance,':',after_balance) "
        "FROM admin_wallet_adjustments WHERE telegram_update_id="+str(update_id))

def setstep(work,env,act,expected):
    safe=expected.replace("'","''")
    sql(work,env,f"UPDATE users SET step='{safe}' WHERE userid={act}")

def cmd(work, spec):
    return ['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
        '-d','allow_url_fopen=0','-d','log_errors=1',
        '-d','disable_functions=exec,shell_exec,system,passthru,popen,proc_open,mail',
        str(work/'owned-wallet-driver.php'),str(ROOT),str(work/'mysql.sock'),
        DB,json.dumps(spec)]

def execute(work,env,**spec):
    p=subprocess.run(cmd(work,spec),env=env,cwd=work,capture_output=True,timeout=18)
    if p.returncode or not p.stdout:
        raise RuntimeError(f'wallet PHP failed rc={p.returncode}: {p.stderr[-1000:]!r}')
    try: obj=json.loads(p.stdout)
    except ValueError as ex: raise RuntimeError(repr(p.stdout[-400:])) from ex
    obj['stderr']=p.stderr.decode(errors='replace')
    return obj

def messages(result):
    return [str(e['args'][0]) for e in result['events'] if e['kind']=='sendMessage']

def move_to_reason(work,env,kind,amount,actor=9001,target=1001):
    setstep(work,env,actor,f'achbotWalletReason:{kind}:{target}:{amount}')

def assert_state(work,env,expected_wallet,expected_rows):
    assert balance(work,env)==expected_wallet,(balance(work,env),expected_wallet)
    assert nledger(work,env)==expected_rows,(nledger(work,env),expected_rows)

def main():
    a=argparse.ArgumentParser()
    a.add_argument('--run-disposable',action='store_true',required=True)
    a.add_argument('--report',type=Path)
    args=a.parse_args()
    cases=[]
    with owned.owned_database() as (work,env):
        (work/'owned-wallet-driver.php').write_text(PHP)
        # Migration is deliberately absent on legacy installation.
        before=balance(work,env)
        out=execute(work,env,data='increaseUserWallet')
        assert step(work,env)=='none' and balance(work,env)==before
        assert any('آماده نیست' in m for m in messages(out))
        cases.append('missing_005_fails_closed_at_ui')

        move_to_reason(work,env,'credit',300)
        out=execute(work,env,text='reason',update_id=700)
        assert balance(work,env)==before
        assert any('مشخص نیست' in m for m in messages(out))
        cases.append('missing_005_fails_closed_at_commit')

        sql(work,env,MIGRATION.read_text())
        assert nledger(work,env)==0
        # Native three-stage Telegram flow and durable final transaction.
        setstep(work,env,9001,'none')
        start=execute(work,env,data='increaseUserWallet')
        assert step(work,env)=='increaseUserWallet' and 'send-user' in messages(start)
        choose=execute(work,env,text='1001')
        assert step(work,env)=='increaseWalletUser1001'
        assert 'enter-increase' in messages(choose)
        amount=execute(work,env,text='350')
        assert step(work,env)=='achbotWalletReason:credit:1001:350'
        assert any('دلیل' in m for m in messages(amount))
        final=execute(work,env,text='synthetic operator reason',update_id=701)
        assert_state(work,env,50350,1)
        assert step(work,env)=='none'
        assert journal(work,env,701)=='9001:1001:credit:350:50000:50350'
        assert any('✅' in m for m in messages(final))
        cases.append('credit_full_ui_and_single_atomic_audit')

        replay=execute(work,env,text='synthetic operator reason',update_id=701)
        assert_state(work,env,50350,1)
        assert not any('✅' in m for m in messages(replay))
        cases.append('replayed_update_no_second_credit')

        # Even after another admin starts a distinct operation, old identity
        # can never authorize a second wallet effect.
        move_to_reason(work,env,'credit',350)
        blocked=execute(work,env,text='different reason',update_id=701)
        assert_state(work,env,50350,1)
        assert not any('✅' in m for m in messages(blocked))
        cases.append('cross_operation_id_reuse_refused')

        # mysqli_REPORT_OFF must NOT allow a duplicate-key insert to
        # commit money without the corresponding audit entry.
        move_to_reason(work,env,'credit',350)
        off=execute(work,env,text='different reason',update_id=701,
                    mysqli_report_off=True)
        assert_state(work,env,50350,1)
        assert not any('✅' in m for m in messages(off))
        cases.append('nonthrowing_mysqli_duplicate_key_cannot_credit')

        # Ordinary debit does not create debt.
        move_to_reason(work,env,'debit',50351)
        out=execute(work,env,text='routine debit',update_id=702)
        assert_state(work,env,50350,1)
        assert any('معتبر نیست' in m for m in messages(out))
        cases.append('routine_debit_cannot_create_debt')

        move_to_reason(work,env,'debit',50)
        execute(work,env,text='routine debit',update_id=703)
        assert_state(work,env,50300,2)
        assert journal(work,env,703)=='9001:1001:debit:50:50350:50300'
        cases.append('routine_debit_guarded_and_audited')

        # Explicit privileged debt mode may cross below zero.
        move_to_reason(work,env,'debt',50400)
        execute(work,env,text='contractually approved debt',update_id=704)
        assert_state(work,env,-100,3)
        assert journal(work,env,704)=='9001:1001:debt:50400:50300:-100'
        cases.append('explicit_privileged_negative_debt')

        move_to_reason(work,env,'debit',10)
        execute(work,env,text='cannot deepen debt',update_id=705)
        assert_state(work,env,-100,3)
        cases.append('routine_debit_cannot_deepen_debt')

        move_to_reason(work,env,'credit',200)
        execute(work,env,text='repay debt',update_id=706)
        assert_state(work,env,100,4)
        cases.append('credit_offsets_signed_debt')

        # Unauthorized user with forged stored step cannot move money.
        move_to_reason(work,env,'credit',500,actor=1001,target=9002)
        original=balance(work,env,9002)
        out=execute(work,env,actor=1001,text='forged operator',update_id=707)
        assert balance(work,env,9002)==original and nledger(work,env)==4
        assert not any('✅' in m for m in messages(out))
        cases.append('nonadmin_step_forgery_no_effect')

        # Validation of raw amount input, including PHP numeric traps.
        for bad in ('-1','0','3e2','1.5','2147483648','999999999999','+1'):
            setstep(work,env,9001,'increaseWalletUser1001')
            out=execute(work,env,text=bad,update_id=710)
            assert step(work,env)=='increaseWalletUser1001'
            assert nledger(work,env)==4
            assert not any('✅' in m for m in messages(out)),bad
        cases.append('strict_amount_input_no_decimal_exponent_or_overflow')

        # Signed INT overflow and underflow do not mutate either side.
        sql(work,env,'UPDATE users SET wallet=2147483647 WHERE userid=1001')
        move_to_reason(work,env,'credit',1)
        execute(work,env,text='overflow attempt',update_id=720)
        assert_state(work,env,2147483647,4)
        sql(work,env,'UPDATE users SET wallet=-2147483648 WHERE userid=1001')
        move_to_reason(work,env,'debt',1)
        execute(work,env,text='underflow attempt',update_id=721)
        assert_state(work,env,-2147483648,4)
        cases.append('int32_signed_bounds_preserved')
        sql(work,env,'UPDATE users SET wallet=1000 WHERE userid=1001')

        # Actual two-worker race against one operator step, different IDs.
        move_to_reason(work,env,'credit',100)
        barrier=work/'admin-wallet-race'
        ids=(730,731)
        procs=[]
        try:
            for uid in ids:
                spec=dict(text='race',update_id=uid,barrier=str(barrier))
                procs.append(subprocess.Popen(cmd(work,spec),env=env,cwd=work,
                    stdout=subprocess.PIPE,stderr=subprocess.PIPE))
            until=time.monotonic()+8
            while not all(Path(str(barrier)+'.'+str(uid)).exists() for uid in ids):
                if any(p.poll() is not None for p in procs):
                    raise RuntimeError('Race worker failed before barrier')
                if time.monotonic()>until: raise RuntimeError('Race barrier timed out')
                time.sleep(.02)
            Path(str(barrier)+'.go').write_text('go')
            results=[]
            for p in procs:
                out,err=p.communicate(timeout=15)
                assert p.returncode==0,(p.returncode,out[-500:],err[-500:])
                results.append(json.loads(out))
            assert_state(work,env,1100,5)
            assert sum(any('✅' in m for m in messages(x)) for x in results)==1
        finally:
            for p in procs:
                if p.poll() is None:p.kill();p.wait(timeout=4)
        cases.append('concurrent_admin_updates_exactly_once')

        # Failed wallet update cannot leave orphan ledger entry.
        sql(work,env, "CREATE TRIGGER achbot_admin_wallet_owned_failure "
            "BEFORE UPDATE ON users FOR EACH ROW "
            "SET NEW.wallet=IF(NEW.userid=1001 AND NEW.wallet>OLD.wallet,"
            "999999999999999,NEW.wallet)")
        try:
            move_to_reason(work,env,'credit',5)
            out=execute(work,env,text='failure fixture',update_id=740)
            assert_state(work,env,1100,5)
            assert not any('✅' in m for m in messages(out))
        finally:
            sql(work,env,"DROP TRIGGER achbot_admin_wallet_owned_failure")
        cases.append('wallet_sql_failure_rolls_back_audit')

        # Different owned messages remain separately auditable.
        move_to_reason(work,env,'credit',5)
        notice=execute(work,env,text='later independent credit',update_id=741,
                notification_failure=True)
        assert_state(work,env,1105,6)
        assert 'stage=operator_confirmation' in notice['stderr']
        assert 'stage=operator_menu' in notice['stderr']
        assert 'stage=target_notice' in notice['stderr']
        cases.append('postcommit_telegram_failure_preserves_effect')

        # Missing crucial unique key => no permission to spend.
        sql(work,env,'ALTER TABLE admin_wallet_adjustments DROP INDEX uq_admin_wallet_update')
        move_to_reason(work,env,'credit',7)
        execute(work,env,text='schema broken',update_id=750)
        assert_state(work,env,1105,6)
        sql(work,env,'ALTER TABLE admin_wallet_adjustments '
            'ADD UNIQUE KEY uq_admin_wallet_update (telegram_update_id)')
        cases.append('schema_without_unique_identity_fails_closed')

        sql(work,env,'ALTER TABLE admin_wallet_adjustments MODIFY '
            'adjustment_type VARCHAR(16) COLLATE utf8mb4_unicode_ci NOT NULL')
        move_to_reason(work,env,'credit',7)
        execute(work,env,text='schema wrong collation',update_id=752)
        assert_state(work,env,1105,6)
        sql(work,env,'ALTER TABLE admin_wallet_adjustments MODIFY '
            'adjustment_type VARCHAR(16) COLLATE utf8mb4_bin NOT NULL')
        cases.append('schema_wrong_binary_identity_collation_fails_closed')

        sql(work,env,'ALTER TABLE admin_wallet_adjustments MODIFY '
            'reason VARCHAR(200) NOT NULL')
        move_to_reason(work,env,'credit',7)
        execute(work,env,text='schema wrong reason length',update_id=753)
        assert_state(work,env,1105,6)
        sql(work,env,'ALTER TABLE admin_wallet_adjustments MODIFY '
            'reason VARCHAR(500) NOT NULL')
        cases.append('schema_incompatible_reason_contract_fails_closed')

        # Engine mismatch cannot turn ledger+wallet writes into nontransactional
        # nonrollbackable partial state; test in disposable database only.
        sql(work,env,'ALTER TABLE admin_wallet_adjustments ENGINE=MyISAM')
        move_to_reason(work,env,'credit',7)
        execute(work,env,text='schema unsafe engine',update_id=751)
        assert_state(work,env,1105,6)
        cases.append('schema_nontransactional_engine_fails_closed')
        sql(work,env,'ALTER TABLE admin_wallet_adjustments ENGINE=InnoDB')

        # The frozen original users.userid index is not UNIQUE. A duplicated
        # Telegram identity is unsafe even when the balances differ.
        sql(work,env,"INSERT INTO users (userid,name,username,refcode,wallet,date) "
            "VALUES (1001,'Fixture duplicate','ambiguous-target','0',99,'1700000000')")
        setstep(work,env,9001,'increaseUserWallet')
        selection=execute(work,env,text='1001',update_id=760)
        assert step(work,env,9001)=='none'
        assert any('چند رکورد' in m for m in messages(selection))
        cases.append('duplicate_target_refused_at_selection')

        move_to_reason(work,env,'credit',10)
        result=execute(work,env,text='ambiguous target',update_id=761)
        assert sql(work,env,'SELECT wallet FROM users WHERE userid=1001 ORDER BY id').splitlines()==['1105','99']
        assert nledger(work,env)==6
        assert any('معتبر نیست' in m for m in messages(result))
        sql(work,env,"DELETE FROM users WHERE username='ambiguous-target'")
        cases.append('duplicate_target_rejected_before_financial_change')

        sql(work,env,"INSERT INTO users (userid,name,username,refcode,wallet,date,isAdmin) "
            "VALUES (9001,'Fixture admin duplicate','ambiguous-admin','0',1,'1700000000',1)")
        move_to_reason(work,env,'credit',10,target=9002)
        target_before=balance(work,env,9002)
        result=execute(work,env,text='ambiguous operator',update_id=762)
        assert balance(work,env,9002)==target_before and nledger(work,env)==6
        assert any('معتبر نیست' in m for m in messages(result))
        sql(work,env,"DELETE FROM users WHERE username='ambiguous-admin'")
        cases.append('duplicate_operator_rejected_before_financial_change')

        move_to_reason(work,env,'credit',10,target=9001)
        result=execute(work,env,text='self-credit',update_id=763)
        assert balance(work,env,9001)==10 and nledger(work,env)==7
        assert step(work,env,9001)=='none'
        cases.append('operator_and_target_same_identity_atomic')

    report=dict(complete=True,cases=len(cases),scenarios=cases,
                real_php_workers=2,disposable_db=True,
                production_mutations=0,external_provider_calls=0)
    if args.report:args.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps({k:v for k,v in report.items() if k!='scenarios'}))

if __name__=='__main__':main()
