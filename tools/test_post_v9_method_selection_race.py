#!/usr/bin/env python3
"""Issue #51 PR #60 R2: actual manual-claim / wallet-debit serialization.

Runs only with a task-owned socket-only disposable MariaDB and isolated PHP
source snapshots. Delays are inserted solely at the source-real side-effect
boundary in the temporary copy; no production source test hooks, real payouts,
Telegram messages or remote server effects are used.

Distinguishes TWO orderings:
  B commits the successor first -> old A must not create evidence or debit.
  A reserves/settles first -> B waits; already issued A evidence survives.
"""
from __future__ import annotations

import argparse
import json
from pathlib import Path
import subprocess
import time

import test_access_state as owned
import test_v8_native as purchase
import test_post_v9_purchases as stages
import test_post_v9_manual_payments as manual
import test_post_v9_checkout_serialization as checkout

ROOT=Path(__file__).resolve().parents[1]
DB=purchase.SIDE[1]

# A temporary, test-only barrier is spliced into source copies AFTER the actual
# manual eligibility read and BEFORE the ledger write; for the wallet it is
# AFTER the actual existing preflight and BEFORE the debit boundary. It does
# not replace or change any branch predicate or write in the production code.
# Deliberately INVERTED test-only lock sequence is an adversarial third-party
# transaction; production A always keeps users -> pays -> attempt ordering.
INVERTED_LOCK_HOLDER = r"""<?php
error_reporting(E_ALL);
$db=new mysqli('localhost','root','',$argv[1],0,$argv[2]);
$db->set_charset('utf8mb4');
$payId=(int)$argv[3];$actor=(int)$argv[4];
$ready=$argv[5];$release=$argv[6];
try {
    if (!$db->begin_transaction()) throw new RuntimeException('begin_failed');
    $stmt=$db->prepare('SELECT id FROM pays WHERE id=? FOR UPDATE');
    $stmt->bind_param('i',$payId);
    if (!$stmt->execute() || !$stmt->get_result()->fetch_assoc())
        throw new RuntimeException('pay_lock_failed');
    $stmt->close();
    file_put_contents($ready,'pays_locked');
    $until=microtime(true)+12;
    while (!is_file($release) && microtime(true)<$until) usleep(10000);
    if (!is_file($release)) throw new RuntimeException('release_timeout');
    $stmt=$db->prepare('SELECT id FROM users WHERE userid=? FOR UPDATE');
    $stmt->bind_param('i',$actor);
    if (!$stmt->execute()) throw new RuntimeException('owner_lock_failed');
    $stmt->close();
    $db->rollback();
    echo 'OWNER_LOCK_ACQUIRED';
} catch (Throwable $error) {
    try{$db->rollback();}catch(Throwable $ignored){}
    if ($error instanceof mysqli_sql_exception &&
        in_array((int)$error->getCode(),[1205,1213],true)) {
        echo 'LOCK_CONFLICT_VICTIM';
    } else {
        fwrite(STDERR,'unrelated_synthetic_lock_failure');
        exit(1);
    }
}
"""

BARRIER=r"""        if (($GLOBALS['r2_pause_at']??'')==='__POINT__') {
            file_put_contents((string)$GLOBALS['r2_ready'],'reached');
            $until=microtime(true)+12;
            while (!is_file((string)$GLOBALS['r2_release']) && microtime(true)<$until)
                usleep(10000);
            if (!is_file((string)$GLOBALS['r2_release']))
                throw new RuntimeException('r2_test_barrier_timeout');
        }
"""

def stage_and_driver(work,previous=False):
    root=work/'r2-reviewed' if previous else work/'r2-current'
    stages.stage_source(root,True)
    if previous:
        for name in ('src/Finance/ReceiptHelpers.php','src/Finance/WalletSettlement.php'):
            content=subprocess.check_output(['git','show',
                'ee029f1b8cd8fcd4f0a7cdb171a3b6793606036e:'+name],cwd=ROOT)
            (root/name).write_bytes(content)
    replacements=(
        ('src/Finance/ReceiptHelpers.php',
         "        $hash = (string)$current['hash_id'];","manual"),
        ('src/Finance/WalletSettlement.php',
         "            $uid   = (int)($payInfo['user_id'] ?: $from_id);","wallet"),
    )
    for rel,anchor,point in replacements:
        p=root/rel;s=p.read_bytes().decode()
        assert s.count(anchor)==1,(rel,s.count(anchor))
        # The wallet source is mixed-CRLF; preserve all unrelated bytes.
        entry=s.index(anchor)
        newline='\r\n' if '\r\n' in s[max(0,entry-500):entry] else '\n'
        hook=BARRIER.replace('__POINT__',point).replace('\n',newline)
        s=s[:entry]+hook+s[entry:]
        # Test-only lost acknowledgment occurs AFTER the actual COMMIT.
        # Keep all test hooks solely inside staged snapshots.
        if point=='manual':
            anchor2="            throw new RuntimeException('manual_claim_commit_unverified');"
            marker=anchor2+"\n        return true;"
            # Prior-reviewed ee029 has no hardened exact-one commit marker.
            # Keep that source unchanged to prove its actual original R2.
            assert s.count(marker)==(0 if previous else 1),(
                'manual_commit_hook',s.count(marker))
            s=s.replace(marker,anchor2+"""
        if ($GLOBALS['r2_ambiguous_commit']??false)
            throw new RuntimeException('r2_lost_manual_commit_ack');
        return true;""")
        else:
            anchors=[
                "            $payInfo['state'] = 'paid_with_wallet';",
                "            $payInfo['state']='paid_with_wallet';"
            ]
            candidates=[a for a in anchors if s.count(a)==1]
            assert len(candidates)==1,('wallet_commit_hook',candidates)
            anchor2=candidates[0]
            s=s.replace(anchor2,'''            if ($GLOBALS['r2_ambiguous_commit']??false)
                throw new RuntimeException('r2_lost_wallet_commit_ack');
'''+anchor2)
        p.write_bytes(s.encode())
    php=manual.PHP
    needle="$spec=json_decode($argv[4],true,512,JSON_THROW_ON_ERROR);"
    assert php.count(needle)==1
    php=php.replace(needle,needle+"""
$GLOBALS['r2_pause_at']=$spec['r2_pause_at']??'';
$GLOBALS['r2_ready']=$spec['r2_ready']??'';
$GLOBALS['r2_release']=$spec['r2_release']??'';
$GLOBALS['r2_effects']=0;
$GLOBALS['r2_ambiguous_commit']=$spec['r2_ambiguous_commit']??false;
""")
    db_ready="$connection->set_charset('utf8mb4');"
    assert php.count(db_ready)==1
    php=php.replace(db_ready,db_ready+"""
if (($spec['mysqli_off']??false)===true) mysqli_report(MYSQLI_REPORT_OFF);
if (isset($spec['r2_lock_timeout'])) {
    $timeout=(int)$spec['r2_lock_timeout'];
    if ($timeout<1 || $timeout>20) throw new RuntimeException('test_timeout_invalid');
    $connection->query('SET innodb_lock_wait_timeout='.$timeout);
}
""")
    needle="$value=processWalletPayment($connection,$hash,static function(){return true;},'standard');"
    assert php.count(needle)==1
    php=php.replace(needle,"""$value=processWalletPayment($connection,$hash,
        static function() {++$GLOBALS['r2_effects'];return true;},'standard');""")
    needle="echo json_encode(['result'=>$value],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);"
    assert php.count(needle)==1
    php=php.replace(needle,"""echo json_encode(['result'=>$value,'effects'=>$GLOBALS['r2_effects']],
    JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);""")
    driver=work/'r2-real-customer-action.php'
    driver.write_text(php)
    return root,driver

def sql(w,e,q):
    return checkout.sql(w,e,q)

def user(w,e,actor):
    checkout.fresh_actor(w,e,actor)
    sql(w,e,f"UPDATE users SET wallet=700000,is_agent=0 WHERE userid={actor}")

def seed(w,e,h,actor):
    return checkout.seed_pay(w,e,h,actor)

def action_cmd(w,stage,driver,h,actor,method,marker=None,**extra):
    cfg=dict(op='wallet' if method=='wallet' else 'claim',
             method=method,user=actor,
             keys={'bankAccount':'6037999999999999','holderName':'Fixture',
                   'giftCardUrl':'https://example.org/gift-card',
                   'manualUsdtWallet':'0x'+'1'*40,
                   'manualUsdtNetwork':'BEP20','manualUsdtRateToman':'100000',
                   'manualTrxWallet':'TJRabPrwbZy45sbavfcjinPJC18kjpRTv8',
                   'manualTrxRateToman':'15000'})
    cfg.update(extra)
    if marker:
        cfg.update(r2_pause_at='wallet' if method=='wallet' else 'manual',
                   r2_ready=str(marker/'action-ready'),
                   r2_release=str(marker/'action-release'))
    return ['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
            '-d','allow_url_fopen=0','-d','display_errors=0',
            '-d','log_errors=1','-d','error_log=/dev/stderr',
            '-d','disable_functions=exec,shell_exec,system,passthru,popen,proc_open,mail,dl',
            str(driver),str(stage),str(w/'mysql.sock'),h,json.dumps(cfg),DB]

def result(proc,timeout=14):
    out,err=proc.communicate(timeout=timeout)
    assert proc.returncode==0,(proc.returncode,out[-1400:],err[-1600:])
    assert not err,(out,err)
    return json.loads(out)

def start_action(w,e,stage,driver,h,actor,method,marker):
    return subprocess.Popen(action_cmd(w,stage,driver,h,actor,method,marker),
                            cwd=w,env=e,stdout=subprocess.PIPE,stderr=subprocess.PIPE)

def run_action(w,e,stage,driver,h,actor,method,**extra):
    proc=subprocess.run(action_cmd(w,stage,driver,h,actor,method,**extra),
                        cwd=w,env=e,capture_output=True,timeout=16)
    assert proc.returncode==0,(proc.stdout,proc.stderr)
    assert not proc.stderr,proc.stderr
    return json.loads(proc.stdout)

def wait_path(path,proc=None,timeout=5):
    until=time.monotonic()+timeout
    while time.monotonic()<until:
        if path.exists():return
        if proc is not None and proc.poll() is not None:
            raise AssertionError(('worker died before marker',str(path),
                                  proc.returncode,proc.communicate()))
        time.sleep(.012)
    raise AssertionError('missing worker marker '+str(path))

def draft_writer(w,e,stage,folder,h,actor):
    code=w/'r2-owned-draft-writer.php'
    if not code.exists():code.write_text(checkout.PRELOCK_DRAFT_WORKER)
    return subprocess.Popen(
        ['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
         str(code),str(stage),DB,str(w/'mysql.sock'),str(actor),h,'0',
         str(folder/'writer-ready'),str(folder/'writer-release')],
        cwd=w,env=e,stdout=subprocess.PIPE,stderr=subprocess.PIPE)

def b_first(w,e,stage,driver,method,index):
    # B first acquires the SAME user lock through the real existing writer
    # protocol; A arrives while B is inside the transaction. In the reviewed
    # source A can pass its nonlocking preflight, then the test-only barrier
    # releases AFTER B commits, permitting an obsolete manual claim/debit.
    actor=9820+index;user(w,e,actor)
    h='r2before'+str(index);new='r2replace'+str(index)
    pid=seed(w,e,h,actor)
    folder=w/('r2-b-first-'+str(index));folder.mkdir()
    holder=w/'r2-owned-replacement-holder.php'
    if not holder.exists():holder.write_text(checkout.REPLACEMENT_HOLDER)
    task=subprocess.Popen(
        ['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
         str(holder),DB,str(w/'mysql.sock'),str(actor),new,
         str(folder/'holder-ready'),str(folder/'holder-release')],
        cwd=w,env=e,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    try:
        wait_path(folder/'holder-ready',task)
        before_wallet=sql(w,e,f"SELECT wallet FROM users WHERE userid={actor}")
        action=start_action(w,e,stage,driver,h,actor,method,folder)
        try:
            # Current source blocks at the users-row lock before any final
            # method-selection preflight. Old source instead reaches barrier.
            until=time.monotonic()+2.5
            reached=False
            while time.monotonic()<until:
                if (folder/'action-ready').exists():reached=True;break
                if action.poll() is not None:break
                time.sleep(.015)
            (folder/'holder-release').write_text('commit newer')
            owner_stdout,owner_stderr=task.communicate(timeout=7)
            assert task.returncode==0 and owner_stdout==b'COMMITTED' and not owner_stderr,(
                task.returncode,owner_stdout,owner_stderr)
            (folder/'action-release').write_text('resume old action')
            done=result(action)
        finally:
            if action.poll() is None:
                (folder/'action-release').write_text('done')
                action.kill();action.communicate(timeout=4)
        if reached:
            ledger=sql(w,e,f"SELECT provider,stage FROM payment_attempts WHERE pay_row_id={pid}")
            wallet_after=sql(w,e,f"SELECT wallet FROM users WHERE userid={actor}")
            raise AssertionError(('R2: customer replacement committed BEFORE obsolete '
                'manual claim / wallet payment finished',method,done,
                'obsolete_claim',ledger,'wallet_before',before_wallet,
                'wallet_after',wallet_after))
        assert sql(w,e,f"SELECT COUNT(*) FROM pays WHERE hash_id='{new}'")=='1'
        assert sql(w,e,f"SELECT state FROM pays WHERE id={pid}")=='pending'
        assert sql(w,e,f"SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id={pid}")=='0'
        assert sql(w,e,f"SELECT wallet FROM users WHERE userid={actor}")==before_wallet
        assert done['effects']==0,(method,done)
        if method=='wallet':
            assert done['result']['status']=='claimed_provider',done
        else:
            assert done['result'] is False,done
            # The real existing Telegram top-up route consumes the returned
            # claim boolean before exposing a bank or gift-card destination.
            # An obsolete callback must not send money instructions.
            if method in ('bank_transfer','gift_card'):
                route_driver=w/'r2-telegram-customer-route.php'
                if not route_driver.exists():route_driver.write_text(manual.PHP_ROUTE)
                selector='CartToCart' if method=='bank_transfer' else 'GiftCard'
                events=manual.run_route(w,e,route_driver,
                    data='payIncreaseWalletWith'+selector+h,
                    route='wallet',actor=actor)
                assert not any(t['name'] in ('sendMessage','remote_create','remote_edit')
                               for t in events),(method,events)
                assert any(t['name']=='alert' for t in events),(method,events)
                assert sql(w,e,f"SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id={pid}")=='0'
        return 'r2_'+method+'_replacement_commits_first_no_old_ledger_or_debit'
    finally:
        if task.poll() is None:
            (folder/'holder-release').write_text('go')
            task.kill();task.communicate(timeout=4)

def a_first(w,e,stage,driver,method,index):
    # A already holds owner lock and reaches the test-only actual pre-INSERT/
    # pre-DEBIT barrier. B must NOT allocate its successor ID until A releases.
    actor=9870+index;user(w,e,actor)
    h='r2actionfirst'+str(index);new='r2draftsecond'+str(index)
    pid=seed(w,e,h,actor)
    folder=w/('r2-a-first-'+str(index));folder.mkdir()
    original_wallet=sql(w,e,f"SELECT wallet FROM users WHERE userid={actor}")
    action=start_action(w,e,stage,driver,h,actor,method,folder)
    writer=None
    try:
        wait_path(folder/'action-ready',action)
        writer=draft_writer(w,e,stage,folder,new,actor)
        wait_path(folder/'writer-ready',writer)
        (folder/'writer-release').write_text('go')
        # Observably blocked on user's FOR UPDATE. A mere process startup is
        # insufficient evidence and would miss the exact reviewed defect.
        until=time.monotonic()+2.0
        blocked=False
        while time.monotonic()<until:
            rows=sql(w,e,"SELECT INFO FROM information_schema.PROCESSLIST "
                f"WHERE DB='{DB}' AND INFO LIKE '%FROM users%userid%FOR UPDATE%'")
            if 'users' in rows and 'FOR UPDATE' in rows:blocked=True;break
            if writer.poll() is not None:break
            time.sleep(.015)
        assert blocked and writer.poll() is None,(
            'new draft committed before in-flight manual/wallet selection',method)
        (folder/'action-release').write_text('complete old valid action')
        done=result(action)
        newer=result(writer)
        assert int(newer['id'])>pid,(pid,newer)
        assert sql(w,e,f"SELECT wallet FROM users WHERE userid={actor}")==(
            str(int(original_wallet)-100000) if method=='wallet' else original_wallet)
        if method=='wallet':
            assert done['result']['status']=='ok' and done['effects']==1,done
            assert sql(w,e,f"SELECT state FROM pays WHERE id={pid}")=='paid_with_wallet'
            repeated=run_action(w,e,stage,driver,h,actor,method)
            assert repeated['result']['status']=='already_paid' and repeated['effects']==0
        else:
            assert done['result'] is True and done['effects']==0,done
            initial=sql(w,e,f"SELECT provider,checkout_url,stage FROM payment_attempts WHERE pay_row_id={pid}")
            assert initial and initial.split('\t')[0]==method,initial
            repeated=run_action(w,e,stage,driver,h,actor,method)
            assert repeated['result'] is True,repeated
            assert sql(w,e,f"SELECT provider,checkout_url,stage FROM payment_attempts WHERE pay_row_id={pid}")==initial
        assert sql(w,e,f"SELECT COUNT(*) FROM pays WHERE hash_id='{new}'")=='1'
        assert sql(w,e,f"SELECT state FROM pays WHERE hash_id='{new}'")=='pending'
        return 'r2_'+method+'_old_commits_first_new_draft_waits_evidence_intact'
    finally:
        (folder/'action-release').write_text('release')
        (folder/'writer-release').write_text('release')
        for proc in (action,writer):
            if proc is not None and proc.poll() is None:
                proc.kill();proc.communicate(timeout=4)

def sql_write_failure(w,e,stage,driver,method,index):
    # Real InnoDB trigger rejects mandatory financial write while mysqli is
    # non-throwing. No false-positive claim, debit or fulfillment may escape.
    actor=9930+index;user(w,e,actor)
    h='r2sqlfault'+str(index)
    pid=seed(w,e,h,actor)
    wallet_before=sql(w,e,f"SELECT wallet FROM users WHERE userid={actor}")
    name='r2_payment_attempt_reject' if method!='wallet' else 'r2_wallet_debit_reject'
    if method=='wallet':
        ddl=("CREATE TRIGGER "+name+" BEFORE UPDATE ON users FOR EACH ROW "
             "SIGNAL SQLSTATE '45000' "
             "SET MESSAGE_TEXT='owned_wallet_debit_reject'")
    else:
        ddl=("CREATE TRIGGER "+name+" BEFORE INSERT ON payment_attempts "
             "FOR EACH ROW SIGNAL SQLSTATE '45000' "
             "SET MESSAGE_TEXT='owned_attempt_insert_reject'")
    sql(w,e,ddl)
    try:
        done=run_action(w,e,stage,driver,h,actor,method,mysqli_off=True)
    finally:
        sql(w,e,'DROP TRIGGER IF EXISTS '+name)
    assert done['effects']==0,done
    assert sql(w,e,f"SELECT wallet FROM users WHERE userid={actor}")==wallet_before
    assert sql(w,e,f"SELECT state FROM pays WHERE id={pid}")=='pending'
    assert sql(w,e,f"SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id={pid}")=='0'
    if method=='wallet':
        assert done['result']['status']=='error',done
    else:
        assert done['result'] is False,done
    return 'r2_'+method+'_nonthrowing_mysqli_failure_no_claim_debit_or_handler'


def owner_lock_timeout(w,e,stage,driver,method,index):
    # When a valid writer holds users.userid too long, customer side effects
    # fail closed; never fall back to an earlier nonlocking SELECT.
    actor=9940+index;user(w,e,actor)
    h='r2timeout'+str(index);new='r2timeoutnew'+str(index)
    pid=seed(w,e,h,actor)
    folder=w/('r2-lock-timeout-'+str(index));folder.mkdir()
    holder=w/'r2-owned-replacement-holder.php'
    if not holder.exists():holder.write_text(checkout.REPLACEMENT_HOLDER)
    task=subprocess.Popen(
        ['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
         str(holder),DB,str(w/'mysql.sock'),str(actor),new,
         str(folder/'holder-ready'),str(folder/'holder-release')],
        cwd=w,env=e,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    before=sql(w,e,f"SELECT wallet FROM users WHERE userid={actor}")
    try:
        wait_path(folder/'holder-ready',task)
        done=run_action(w,e,stage,driver,h,actor,method,r2_lock_timeout=1)
        assert done['effects']==0,done
        if method=='wallet':
            assert done['result']['status']=='error',done
        else:
            assert done['result'] is False,done
        assert sql(w,e,f"SELECT wallet FROM users WHERE userid={actor}")==before
        assert sql(w,e,f"SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id={pid}")=='0'
        assert sql(w,e,f"SELECT state FROM pays WHERE id={pid}")=='pending'
        (folder/'holder-release').write_text('go')
        stdout,stderr=task.communicate(timeout=7)
        assert task.returncode==0 and stdout==b'COMMITTED' and not stderr,(stdout,stderr)
        return 'r2_'+method+'_owner_lock_timeout_fails_closed_without_side_effect'
    finally:
        if task.poll() is None:
            (folder/'holder-release').write_text('go')
            task.kill();task.communicate(timeout=4)


def interrupted_owner_lock(w,e,stage,driver,method,index):
    # KILL QUERY of the real blocked FOR UPDATE must not be interpreted as
    # authorization to select a method or spend customer credit.
    actor=9950+index;user(w,e,actor)
    h='r2killed'+str(index);new='r2killednew'+str(index)
    pid=seed(w,e,h,actor)
    folder=w/('r2-query-interrupt-'+str(index));folder.mkdir()
    holder=w/'r2-owned-replacement-holder.php'
    if not holder.exists():holder.write_text(checkout.REPLACEMENT_HOLDER)
    task=subprocess.Popen(
        ['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
         str(holder),DB,str(w/'mysql.sock'),str(actor),new,
         str(folder/'holder-ready'),str(folder/'holder-release')],
        cwd=w,env=e,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    action=None
    before=sql(w,e,f"SELECT wallet FROM users WHERE userid={actor}")
    try:
        wait_path(folder/'holder-ready',task)
        action=subprocess.Popen(action_cmd(w,stage,driver,h,actor,method),
            cwd=w,env=e,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        waiting=None
        deadline=time.monotonic()+6
        while time.monotonic()<deadline and action.poll() is None:
            rows=sql(w,e,"SELECT ID FROM information_schema.PROCESSLIST "
                f"WHERE DB='{DB}' AND INFO LIKE '%FROM users%userid%FOR UPDATE%' "
                "AND INFO NOT LIKE '%PROCESSLIST%'")
            ids=[int(t.strip()) for t in rows.splitlines() if t.strip().isdigit()]
            if ids:waiting=ids[0];break
            time.sleep(.015)
        assert waiting is not None,('actual action never blocked on owner FOR UPDATE',method)
        sql(w,e,'KILL QUERY '+str(waiting))
        finished=result(action)
        assert finished['effects']==0,finished
        if method=='wallet':assert finished['result']['status']=='error',finished
        else:assert finished['result'] is False,finished
        assert sql(w,e,f"SELECT wallet FROM users WHERE userid={actor}")==before
        assert sql(w,e,f"SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id={pid}")=='0'
        assert sql(w,e,f"SELECT state FROM pays WHERE id={pid}")=='pending'
        (folder/'holder-release').write_text('commit actual newer')
        stdout,stderr=task.communicate(timeout=7)
        assert task.returncode==0 and stdout==b'COMMITTED' and not stderr,(stdout,stderr)
        return 'r2_'+method+'_interrupted_owner_lock_no_financial_effect'
    finally:
        (folder/'holder-release').write_text('go')
        if action is not None and action.poll() is None:
            action.kill();action.communicate(timeout=4)
        if task.poll() is None:
            task.kill();task.communicate(timeout=4)


def deadlock_victim(w,e,stage,driver,method,index):
    # B locks the original pays row first (deliberately reverse order).
    # A holds users row and blocks on pays, B then requests users. MariaDB
    # chooses a victim. Either outcome must be safe, with at most one manual
    # claim or customer-wallet debit/fulfillment, and no shared-lock hang.
    actor=9970+index;user(w,e,actor)
    h='r2deadlock'+str(index);pid=seed(w,e,h,actor)
    wallet_before=int(sql(w,e,f"SELECT wallet FROM users WHERE userid={actor}"))
    folder=w/('r2-deadlock-'+str(index));folder.mkdir()
    holder=w/'r2-owned-inverse-lock-holder.php'
    if not holder.exists():holder.write_text(INVERTED_LOCK_HOLDER)
    b=subprocess.Popen(
        ['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
         str(holder),DB,str(w/'mysql.sock'),str(pid),str(actor),
         str(folder/'holder-ready'),str(folder/'holder-release')],
        cwd=w,env=e,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    a=None
    try:
        wait_path(folder/'holder-ready',b)
        a=subprocess.Popen(action_cmd(w,stage,driver,h,actor,method),
            cwd=w,env=e,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        blocked=False
        deadline=time.monotonic()+5
        while time.monotonic()<deadline and a.poll() is None:
            rows=sql(w,e,"SELECT INFO FROM information_schema.PROCESSLIST "
                f"WHERE DB='{DB}' AND INFO LIKE '%pays%' AND INFO LIKE '%FOR UPDATE%' "
                "AND INFO NOT LIKE '%PROCESSLIST%'")
            if 'pays' in rows and 'FOR UPDATE' in rows:blocked=True;break
            time.sleep(.012)
        assert blocked,('action never waited on inverted pays lock',method)
        (folder/'holder-release').write_text('request owner row')
        done=result(a,timeout=12)
        stdout,stderr=b.communicate(timeout=8)
        assert b.returncode==0 and not stderr,(b.returncode,stdout,stderr)
        assert stdout in (b'OWNER_LOCK_ACQUIRED',b'LOCK_CONFLICT_VICTIM'),stdout
        wallet_after=int(sql(w,e,f"SELECT wallet FROM users WHERE userid={actor}"))
        attempts=int(sql(w,e,f"SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id={pid}"))
        if method=='wallet':
            if done['result']['status']=='ok':
                assert done['effects']==1 and wallet_after==wallet_before-100000,done
                assert sql(w,e,f"SELECT state FROM pays WHERE id={pid}")=='paid_with_wallet'
            else:
                assert done['result']['status']=='error' and done['effects']==0,done
                assert wallet_after==wallet_before
                assert sql(w,e,f"SELECT state FROM pays WHERE id={pid}")=='pending'
            assert attempts==0
        else:
            if done['result'] is True:
                assert attempts==1 and done['effects']==0
            else:
                assert done['result'] is False and attempts==0 and done['effects']==0
            assert wallet_after==wallet_before
        return 'r2_'+method+'_inverted_lock_deadlock_victim_safe_atomic_outcome'
    finally:
        (folder/'holder-release').write_text('release')
        for proc in (a,b):
            if proc is not None and proc.poll() is None:
                proc.kill();proc.communicate(timeout=4)


def ambiguous_commit_ack(w,e,stage,driver,method,index):
    # Genuine disposable MariaDB COMMIT, followed by a test-only exception
    # simulating lost HTTP/Telegram acknowledgment.
    actor=9990+index;user(w,e,actor)
    h='r2ambig'+str(index);pid=seed(w,e,h,actor)
    before=int(sql(w,e,f"SELECT wallet FROM users WHERE userid={actor}"))
    first=run_action(w,e,stage,driver,h,actor,method,r2_ambiguous_commit=True)
    second=run_action(w,e,stage,driver,h,actor,method)
    if method=='wallet':
        assert first['result']['status']=='error' and first['effects']==1,first
        assert second['result']['status']=='already_paid' and second['effects']==0,second
        assert int(sql(w,e,f"SELECT wallet FROM users WHERE userid={actor}"))==before-100000
        assert sql(w,e,f"SELECT state FROM pays WHERE id={pid}")=='paid_with_wallet'
        assert sql(w,e,f"SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id={pid}")=='0'
    else:
        assert first['result'] is False and first['effects']==0,first
        assert second['result'] is True and second['effects']==0,second
        assert int(sql(w,e,f"SELECT wallet FROM users WHERE userid={actor}"))==before
        assert sql(w,e,f"SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id={pid}")=='1'
        alternate=run_action(w,e,stage,driver,h,actor,'gift_card')
        assert alternate['result'] is False,alternate
        assert sql(w,e,f"SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id={pid}")=='1'
    return 'r2_'+method+'_lost_commit_ack_only_durable_claim_may_be_recovered'


def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--run-disposable',action='store_true',required=True)
    parser.add_argument('--report',type=Path)
    parser.add_argument('--r2-reviewed-head',action='store_true',
        help='Stage independently reviewed ee029 source in disposable test files to prove RED')
    parser.add_argument('--only',choices=('all','wallet','bank_transfer','gift_card',
        'manual_usdt','manual_trx'),default='all')
    args=parser.parse_args()
    cases=[]
    with owned.owned_database() as (work,env):
        stage,driver=stage_and_driver(work,args.r2_reviewed_head)
        purchase.init(work,env,'http://127.0.0.1:9')
        # Real final authorization reads persisted policy, not PHP globals.
        sql(work,env,"INSERT INTO setting (type,value) VALUES ('BOT_STATES','{\"walletState\":\"on\",\"cartToCartState\":\"on\",\"giftCardState\":\"on\",\"manualUsdtState\":\"on\",\"nowPaymentWallet\":\"on\",\"nowPaymentOther\":\"on\",\"zarinpal\":\"on\",\"nextpay\":\"on\"}')")
        assert len(sql(work,env,'SHOW TABLES').splitlines())==23
        sql(work,env,(ROOT/'migrations/post-v9/003_payment_attempts.sql').read_text())
        sql(work,env,(ROOT/'migrations/post-v9/006_payment_message_refs.sql').read_text())
        assert len(sql(work,env,'SHOW TABLES').splitlines())==26
        for index,method in enumerate(('bank_transfer','gift_card','manual_usdt','manual_trx','wallet')):
            if args.only!='all' and args.only!=method: continue
            cases.append(b_first(work,env,stage,driver,method,index))
            if method in ('bank_transfer','gift_card') and not args.r2_reviewed_head:
                cases.append('r2_'+method+'_stale_telegram_callback_does_not_show_destination')
            cases.append(a_first(work,env,stage,driver,method,index))
            if not args.r2_reviewed_head:
                cases.append(sql_write_failure(work,env,stage,driver,method,index))
                if method in ('bank_transfer','wallet'):
                    cases.append(owner_lock_timeout(work,env,stage,driver,method,index))
                    cases.append(interrupted_owner_lock(work,env,stage,driver,method,index))
                    cases.append(deadlock_victim(work,env,stage,driver,method,index))
                    cases.append(ambiguous_commit_ack(work,env,stage,driver,method,index))
    out={'complete':True,'cases':len(cases),'scenarios':cases,
         'source_real_php':True,'owned_mariadb_only':True,
         'external_effects':0,'production_mutations':0}
    if args.report:args.report.write_text(json.dumps(out,indent=2,ensure_ascii=False))
    print(json.dumps(out,ensure_ascii=False))

if __name__=='__main__':main()
