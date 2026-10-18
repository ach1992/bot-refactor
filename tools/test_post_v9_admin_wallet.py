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
$actor=(int)($spec['actor']??9001);
$wireType=(string)($spec['update_kind']??(
    !empty($spec['data'])?'callback':'message'));
$wireActor=(int)($spec['wire_actor']??$actor);
$wireChat=(int)($spec['wire_chat']??$actor);
$wireChatType=(string)($spec['wire_chat_type']??'private');
$wireMessageId=(int)($spec['wire_message_id']??($spec['message_id']??91));
$wireUpdateId=(int)($spec['wire_update_id']??($spec['update_id']??701));
$wireText=(string)($spec['wire_text']??($spec['text']??''));
$sender=(object)['id'=>$wireActor,'is_bot'=>false,
    'username'=>'fixture_admin','first_name'=>'Fixture'];
$chat=(object)['id'=>$wireChat,'type'=>$wireChatType];
$message=(object)['from'=>$sender,'chat'=>$chat,
    'message_id'=>$wireMessageId];
$update=(object)['update_id'=>$wireUpdateId];
if ($wireType==='message') {
    $message->text=$wireText;
    $update->message=$message;
} elseif ($wireType==='service') {
    $message->new_chat_members=[$sender];
    $update->message=$message;
} elseif ($wireType==='callback' || $wireType==='mixed') {
    $message->text=$wireText!==''?$wireText:'Old synthetic bot menu';
    $message->reply_markup=(object)['inline_keyboard'=>[]];
    $update->callback_query=(object)[
        'id'=>'fixture-callback-id',
        'data'=>(string)($spec['data']??'managePanel'),
        'from'=>$sender,
        'message'=>$message
    ];
    if ($wireType==='mixed') {
        $update->message=(object)['from'=>$sender,'chat'=>$chat,
            'message_id'=>$wireMessageId,'text'=>'unexpected second message'];
    }
} elseif ($wireType==='edited_message') {
    $message->text=$wireText;
    $update->edited_message=$message;
} elseif ($wireType==='channel_post') {
    $message->text=$wireText;
    $update->channel_post=$message;
} else {
    throw new RuntimeException('Unknown test update type');
}
// Use the REAL binding implementation; callback binds $text to the OLD
// bot message, which must never satisfy a new financial reason.
$from_id=$actor;
$chat_id=$actor;
$message_id=$wireMessageId;
$data='';
$text=null;
require $root.'/src/Telegram/UpdateBindings.php';
if (array_key_exists('bound_actor_override',$spec)) {
    $from_id=(int)$spec['bound_actor_override'];
}
if (array_key_exists('bound_chat_override',$spec)) {
    $chat_id=(int)$spec['bound_chat_override'];
}
if (array_key_exists('bound_message_override',$spec)) {
    $message_id=(int)$spec['bound_message_override'];
}
if (array_key_exists('bound_text_override',$spec)) {
    $text=$spec['bound_text_override'];
}
$events=[];
$stmt=$connection->prepare('SELECT userid,step,isAdmin,wallet FROM users WHERE userid=?');
$stmt->bind_param('i',$from_id);
$stmt->execute();
$userInfo=$stmt->get_result()->fetch_assoc() ?: ['step'=>'none','isAdmin'=>0];
$stmt->close();
if (!empty($spec['cached_admin_override'])) {
    $userInfo['isAdmin']=1;
}
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
function getAdminKeys() {return 'admin-keys';}
function editText(...$args) {
    $GLOBALS['events'][]=['kind'=>'editText','args'=>$args];
    return (object)['ok'=>true];
}
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
// Execute the EXACT existing managePanel handler extracted from the real
// bot.php source. The binding + finance module run before this handler,
// matching their actual order in bot.php. Do not recreate a test-only
// approximation of the cancellation behavior.
if (!empty($spec['run_real_manage_panel_route'])) {
    $botSource=file_get_contents($root.'/bot.php');
    $marker='if($data == "managePanel"';
    $following="if(\$data == 'reciveApplications')";
    if (substr_count($botSource,$marker)!==1 ||
        substr_count($botSource,$following)!==1 ||
        strpos($botSource,"src/Finance/Routes/AdminWallet.php") >=
            strpos($botSource,$marker)) {
        throw new RuntimeException('Real managePanel handler shape changed');
    }
    $begin=strpos($botSource,$marker);
    $end=strpos($botSource,$following,$begin);
    if ($end===false || $end<=$begin) {
        throw new RuntimeException('Real managePanel route missing');
    }
    eval(substr($botSource,$begin,$end-$begin));
}
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

def move_to_reason(work,env,kind,amount,actor=9001,target=1001,
                   amount_message_id=90,amount_update_id=1):
    setstep(work,env,actor,
        f'achbotWalletReason:{kind}:{target}:{amount}:'
        f'{amount_message_id}:{amount_update_id}')

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

        # F1 regression uses real UpdateBindings.php, NOT a fabricated
        # $text independent of the Telegram update. Old inline bot text
        # must never be mistaken for a newly submitted financial reason.
        move_to_reason(work,env,'credit',350)
        pending=step(work,env)
        old_inline=execute(work,env,update_kind='callback',data='wizwizch',
            wire_text='Old bot message text',update_id=681)
        assert_state(work,env,50000,0)
        assert step(work,env)==pending
        assert not any('✅' in m for m in messages(old_inline))
        cases.append('f1_old_inline_bot_text_cannot_submit_reason')

        manage_panel=execute(work,env,update_kind='callback',data='managePanel',
            wire_text='Previous administration menu',update_id=682)
        assert_state(work,env,50000,0)
        assert step(work,env)==pending
        assert not any('✅' in m for m in messages(manage_panel))
        cases.append('f1_managepanel_finance_module_never_commits')

        mixed=execute(work,env,update_kind='mixed',data='managePanel',
            wire_text='Bot message not operator intent',update_id=683)
        assert_state(work,env,50000,0)
        assert step(work,env)==pending
        assert not any('✅' in m for m in messages(mixed))
        cases.append('f1_mixed_callback_message_envelope_refused')

        for kind in ('edited_message','channel_post','service'):
            old=execute(work,env,update_kind=kind,
                wire_text='preexisting text',update_id=684)
            assert_state(work,env,50000,0)
            assert step(work,env)==pending
            assert not any('✅' in m for m in messages(old)),kind
        cases.append('f1_edited_service_channel_updates_refused')

        # This module-only callback proof does not execute the main bot
        # menu handler. The ACTUAL managePanel cancellation is tested below;
        # the normal genuine text-message test must still succeed.
        # Native three-stage Telegram flow and durable final transaction.
        setstep(work,env,9001,'none')
        start=execute(work,env,data='increaseUserWallet',update_id=695,message_id=88)
        assert step(work,env)=='increaseUserWallet' and 'send-user' in messages(start)
        choose=execute(work,env,text='1001',update_id=696,message_id=89)
        assert step(work,env)=='increaseWalletUser1001'
        assert 'enter-increase' in messages(choose)
        amount=execute(work,env,text='350',update_id=697,message_id=90)
        assert step(work,env)=='achbotWalletReason:credit:1001:350:90:697'
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
        debt_callback=execute(work,env,update_kind='callback',data='managePanel',
            wire_text='Old admin debt confirmation',update_id=704)
        assert_state(work,env,50300,2)
        assert step(work,env)=='achbotWalletReason:debt:1001:50400:90:1'
        assert not any('✅' in m for m in messages(debt_callback))
        cases.append('f1_privileged_debt_needs_new_message_reason')
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

        # No mysqli exception mode: the same failed balance UPDATE must
        # still roll back the previously inserted InnoDB journal.
        sql(work,env, "CREATE TRIGGER achbot_admin_wallet_owned_failure "
            "BEFORE UPDATE ON users FOR EACH ROW "
            "SET NEW.wallet=IF(NEW.userid=1001 AND NEW.wallet>OLD.wallet,"
            "999999999999999,NEW.wallet)")
        try:
            move_to_reason(work,env,'credit',5)
            pending=step(work,env)
            silent=execute(work,env,text='silent failure fixture',update_id=742,
                           mysqli_report_off=True)
            assert_state(work,env,1100,5)
            assert step(work,env)==pending
            assert not any('✅' in m for m in messages(silent))
        finally:
            sql(work,env,"DROP TRIGGER achbot_admin_wallet_owned_failure")
        cases.append('silent_wallet_update_error_rolls_back_journal')

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

        # F1: strict binding identity — no arbitrarily rebound fields or
        # stale callback text, even if the stored operator step is valid.
        for name,sp in [
            ('actor',dict(wire_actor=9002,bound_actor_override=9001)),
            ('chat',dict(wire_chat=9002,bound_chat_override=9001)),
            ('message_id',dict(wire_message_id=190,bound_message_override=91)),
            ('text',dict(wire_text='real body',bound_text_override='forged body')),
            ('update_id',dict(wire_update_id=0)),
        ]:
            move_to_reason(work,env,'credit',17)
            attempt=execute(work,env,text='real body',update_id=770,**sp)
            assert_state(work,env,1105,7)
            assert step(work,env)=='achbotWalletReason:credit:1001:17:90:1',name
            assert not any('✅' in m for m in messages(attempt)),name
        cases.append('f1_bound_actor_chat_message_text_and_update_identity')

        # F1: an actual NEW user-originated Telegram message after unrelated
        # callbacks applies once; the same update cannot apply twice.
        confirmed=execute(work,env,text='explicit new operator explanation',
                          update_id=775)
        assert_state(work,env,1122,8)
        assert step(work,env)=='none'
        assert journal(work,env,775)=='9001:1001:credit:17:1105:1122'
        audit=sql(work,env,
            'SELECT CONCAT(source_chat_id,":",source_message_id,":",reason) '
            'FROM admin_wallet_adjustments WHERE telegram_update_id=775')
        assert audit=='9001:91:explicit new operator explanation',audit
        replay=execute(work,env,text='explicit new operator explanation',update_id=775)
        assert_state(work,env,1122,8)
        assert not any('✅' in m for m in messages(replay))
        cases.append('f1_real_new_text_commits_exactly_once_after_callbacks')

        # F2: source-only negative drift fixtures for original users table.
        # Journal 005 remains InnoDB; unsupported users storage must not
        # consume step or modify wallet, even with transaction APIs called.
        sql(work,env,'ALTER TABLE users ENGINE=MyISAM')
        move_to_reason(work,env,'credit',9)
        pending=step(work,env)
        invalid_engine=execute(work,env,text='cannot transact MyISAM',update_id=780)
        assert_state(work,env,1122,8)
        assert step(work,env)==pending
        assert not any('✅' in m for m in messages(invalid_engine))
        sql(work,env,'ALTER TABLE users ENGINE=InnoDB')
        cases.append('f2_users_myisam_with_innodb_005_refused')

        # A wrong signed-wallet type can invalidate source arithmetic
        # while the 005 table looks perfectly compatible.
        sql(work,env,'ALTER TABLE users MODIFY wallet BIGINT NOT NULL DEFAULT 0')
        move_to_reason(work,env,'credit',9)
        pending=step(work,env)
        invalid_wallet=execute(work,env,text='wrong wallet width',update_id=781)
        assert_state(work,env,1122,8)
        assert step(work,env)==pending
        assert not any('✅' in m for m in messages(invalid_wallet))
        sql(work,env,'ALTER TABLE users MODIFY wallet INT NOT NULL DEFAULT 0')
        cases.append('f2_users_wallet_int32_contract_required')

        sql(work,env,
            "ALTER TABLE users MODIFY step VARCHAR(128) NOT NULL DEFAULT 'none'")
        move_to_reason(work,env,'credit',9)
        pending=step(work,env)
        invalid_step=execute(work,env,text='wrong step contract',update_id=782)
        assert_state(work,env,1122,8)
        assert step(work,env)==pending
        sql(work,env,
            "ALTER TABLE users MODIFY step VARCHAR(1000) NOT NULL DEFAULT 'none'")
        cases.append('f2_users_operator_step_contract_required')

        sql(work,env,
            'ALTER TABLE users MODIFY isAdmin TINYINT UNSIGNED NOT NULL DEFAULT 0')
        move_to_reason(work,env,'credit',9)
        pending=step(work,env)
        invalid_admin=execute(work,env,text='unsigned delegated role',update_id=783)
        assert_state(work,env,1122,8)
        assert step(work,env)==pending
        sql(work,env,
            'ALTER TABLE users MODIFY isAdmin TINYINT NOT NULL DEFAULT 0')
        cases.append('f2_users_admin_identity_contract_required')

        # Explicit revocation while old in-memory UI eligibility remains
        # true must fail at the locked transaction authorization boundary.
        move_to_reason(work,env,'credit',9,actor=9002)
        sql(work,env,'UPDATE users SET isAdmin=0 WHERE userid=9002')
        revoked=execute(work,env,actor=9002,text='previously authorized',
            cached_admin_override=True,update_id=784)
        assert_state(work,env,1122,8)
        assert step(work,env,9002)=='achbotWalletReason:credit:1001:9:90:1'
        assert not any('✅' in m for m in messages(revoked))
        sql(work,env,'UPDATE users SET isAdmin=1 WHERE userid=9002')
        cases.append('delegated_admin_revoked_before_commit_refused')

        # R1: authentic administrator updates must be restricted to the
        # operator's OWN Telegram DM for every financial input stage.
        base_wallet=balance(work,env)
        base_rows=nledger(work,env)
        group_chat=-1001234005678
        setstep(work,env,9001,'none')
        group_entry=execute(work,env,update_kind='callback',
            data='increaseUserWallet',update_id=810,
            wire_chat=group_chat,wire_chat_type='supergroup')
        assert step(work,env)=='none'
        assert_state(work,env,base_wallet,base_rows)
        assert 'send-user' not in messages(group_entry)
        cases.append('r1_group_callback_cannot_begin_admin_adjustment')

        setstep(work,env,9001,'increaseUserWallet')
        group_target=execute(work,env,text='1001',update_id=811,
            wire_chat=group_chat,wire_chat_type='supergroup')
        assert step(work,env)=='increaseUserWallet'
        assert_state(work,env,base_wallet,base_rows)
        assert 'enter-increase' not in messages(group_target)
        cases.append('r1_group_user_selection_cannot_advance_adjustment')

        setstep(work,env,9001,'increaseWalletUser1001')
        group_amount=execute(work,env,text='10',update_id=812,
            wire_chat=group_chat,wire_chat_type='supergroup')
        assert step(work,env)=='increaseWalletUser1001'
        assert_state(work,env,base_wallet,base_rows)
        assert not any('دلیل' in m for m in messages(group_amount))
        cases.append('r1_group_amount_cannot_advance_adjustment')

        move_to_reason(work,env,'debt',10)
        pending=step(work,env)
        group_reason=execute(work,env,text='unrelated genuine group message',
            update_id=813,wire_chat=group_chat,wire_chat_type='supergroup')
        assert step(work,env)==pending
        assert_state(work,env,base_wallet,base_rows)
        assert not any('✅' in m for m in messages(group_reason))
        cases.append('r1_genuine_admin_group_reason_no_financial_effect')

        # A consistent sender/chat binding is NOT enough: Telegram private
        # chat ID must equal the authentic operator's Telegram identity.
        other_private=execute(work,env,text='wrong DM session',
            update_id=814,wire_chat=9003,wire_chat_type='private')
        assert step(work,env)==pending
        assert_state(work,env,base_wallet,base_rows)
        assert not any('✅' in m for m in messages(other_private))
        cases.append('r1_private_chat_identity_must_equal_operator')

        # An unrelated callback must not restart/corrupt a pending debt,
        # even though it carries a real callback and a valid update ID.
        group_reentry=execute(work,env,update_kind='callback',
            data='createUserWalletDebt',update_id=815,
            wire_chat=group_chat,wire_chat_type='supergroup')
        assert step(work,env)==pending
        assert_state(work,env,base_wallet,base_rows)
        assert not any('✅' in m for m in messages(group_reentry))
        cases.append('r1_group_reentry_cannot_override_pending_debt')

        # The same genuine administrator in the correct DM can still
        # intentionally commit negative debt with a NEW, explicit reason.
        real_private=execute(work,env,text='Explicit fresh debt reason',
            update_id=816,message_id=91)
        assert step(work,env)=='none'
        assert_state(work,env,base_wallet-10,base_rows+1)
        assert journal(work,env,816)==(
            f'9001:1001:debt:10:{base_wallet}:{base_wallet-10}')
        assert any('✅' in m for m in messages(real_private))
        repeat=execute(work,env,text='Explicit fresh debt reason',
            update_id=816,message_id=91)
        assert_state(work,env,base_wallet-10,base_rows+1)
        assert not any('✅' in m for m in messages(repeat))
        cases.append('r1_genuine_private_reason_after_group_commits_once')

        # Freshness characterization: after the amount message, BOTH the
        # DM-local Telegram message ID and global update ID must increase.
        # Delayed older private messages must not authorize a new transfer.
        current_wallet=balance(work,env)
        current_rows=nledger(work,env)
        move_to_reason(work,env,'credit',13,
            amount_message_id=120,amount_update_id=900)
        pending=step(work,env)
        for invalid in [
            dict(text='older message ID',message_id=119,update_id=901),
            dict(text='older update ID',message_id=121,update_id=899),
            dict(text='equal update/message',message_id=120,update_id=900),
        ]:
            ignored=execute(work,env,**invalid)
            assert_state(work,env,current_wallet,current_rows)
            assert step(work,env)==pending
            assert not any('✅' in m for m in messages(ignored)),invalid
        cases.append('r1_delayed_or_out_of_order_reason_update_refused')
        fresh=execute(work,env,text='New later operator reason',
            message_id=121,update_id=902)
        assert_state(work,env,current_wallet+13,current_rows+1)
        assert step(work,env)=='none'
        assert any('✅' in m for m in messages(fresh))
        cases.append('r1_monotonic_new_private_reason_can_commit')

        # R2: run the ACTUAL managePanel handler extracted from bot.php,
        # in the correct order AFTER real bindings and AdminWallet.php.
        current_wallet=balance(work,env)
        current_rows=nledger(work,env)
        move_to_reason(work,env,'credit',20)
        canceled=execute(work,env,update_kind='callback',
            data='managePanel',update_id=820,
            wire_text='Old bot admin menu',run_real_manage_panel_route=True)
        assert_state(work,env,current_wallet,current_rows)
        assert step(work,env)=='none'
        edited=[e['args'][1] for e in canceled['events'] if e['kind']=='editText']
        assert len(edited)==1 and 'لغو شد' in edited[0],edited
        assert not any('✅' in m for m in messages(canceled))
        stale_reason=execute(work,env,text='Should be canceled',
            update_id=821,message_id=91)
        assert_state(work,env,current_wallet,current_rows)
        assert step(work,env)=='none'
        assert not any('✅' in m for m in messages(stale_reason))
        cases.append('r2_actual_bot_managepanel_cancels_and_announces_no_funds')

        # The full main-route cancellation contract applies at all stages
        # and does NOT require activating any historical direct wallet write.
        for idx,pending_step in enumerate([
            'increaseUserWallet',
            'decreaseUserWallet',
            'createUserWalletDebt',
            'increaseWalletUser1001',
            'decreaseWalletUser1001',
            'debtWalletUser1001',
            'achbotWalletReason:debt:1001:99:90:1'
        ]):
            setstep(work,env,9001,pending_step)
            out=execute(work,env,update_kind='callback',
                data='managePanel',update_id=830+idx,
                run_real_manage_panel_route=True)
            assert_state(work,env,current_wallet,current_rows)
            assert step(work,env)=='none'
            edits=[e['args'][1] for e in out['events'] if e['kind']=='editText']
            assert len(edits)==1 and 'لغو شد' in edits[0],pending_step
            assert not any('✅' in m for m in messages(out))
        cases.append('r2_actual_managepanel_cancels_every_financial_stage')

        # An old unrelated callback is NOT managePanel; when only the
        # finance route is executed it does not consume its pending step.
        move_to_reason(work,env,'credit',20)
        pending=step(work,env)
        inert=execute(work,env,update_kind='callback',
            data='wizwizch',update_id=840,
            wire_text='Old inline menu')
        assert_state(work,env,current_wallet,current_rows)
        assert step(work,env)==pending
        assert not any('✅' in m for m in messages(inert))
        cases.append('r2_unrelated_callback_not_financial_authorization')

    report=dict(complete=True,cases=len(cases),scenarios=cases,
                real_php_workers=2,disposable_db=True,
                production_mutations=0,external_provider_calls=0)
    if args.report:args.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps({k:v for k,v in report.items() if k!='scenarios'}))

if __name__=='__main__':main()
