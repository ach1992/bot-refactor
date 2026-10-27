#!/usr/bin/env python3
"""PR #60 independent reviewer F1/F2/F3/F4: source-real, socket-only MariaDB.

The real web checkout, Telegram prePayCheck/ps callback and manual crypto
selector execute against disposable databases with mocked provider transport.
Concurrent replacement commits are coordinated with explicit PHP/MySQLi barriers.
No customer money, real payment provider, Telegram, or deployed DB is accessed.
"""
from __future__ import annotations

import argparse
from concurrent.futures import ThreadPoolExecutor
import json
from pathlib import Path
import subprocess
import time

import test_access_state as owned
import test_v6_native as native
import test_v8_native as purchase
import test_post_v9_finance_routes as finance
import test_post_v9_purchases as stages

ROOT = Path(__file__).resolve().parents[1]
DB = purchase.SIDE[1]

# A REAL second independent PHP/MySQLi transaction holds the same owner row,
# then creates a newer draft only after the issuer has reached and is blocked
# on its own owner-row FOR UPDATE. This proves the race timing, not a sleep guess.
REPLACEMENT_HOLDER = r"""<?php
error_reporting(E_ALL);
$db=new mysqli('localhost','root','',$argv[1],0,$argv[2]);
$db->set_charset('utf8mb4');
$owner=(int)$argv[3]; $newHash=(string)$argv[4];
$ready=$argv[5]; $release=$argv[6];
if (!$db->begin_transaction()) throw new RuntimeException('holder_begin');
$stmt=$db->prepare('SELECT id FROM users WHERE userid=? FOR UPDATE');
$stmt->bind_param('i',$owner);
if (!$stmt->execute()) throw new RuntimeException('holder_lock');
$rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
if (count($rows)!==1) throw new RuntimeException('holder_owner');
file_put_contents($ready,'locked');
$limit=microtime(true)+15;
while (!is_file($release) && microtime(true)<$limit) usleep(12000);
if (!is_file($release)) throw new RuntimeException('holder_release_timeout');
$stmt=$db->prepare("INSERT INTO pays
    (hash_id,user_id,type,plan_id,server_id,volume,day,price,request_date,state)
    VALUES (?,?,'INCREASE_WALLET',0,0,0,0,100000,UNIX_TIMESTAMP(),'pending')");
$stmt->bind_param('si',$newHash,$owner);
if (!$stmt->execute() || $stmt->affected_rows!==1)
    throw new RuntimeException('holder_insert');
$stmt->close();
if (!$db->commit()) throw new RuntimeException('holder_commit');
echo 'COMMITTED';
"""

# A separate uncommitted successor forces the issuer's CURRENT-read successor
# SELECT FOR UPDATE to block. KILL QUERY proves SQL lookup failure rolls back
# the entire invoice reservation before any provider request.
UNCOMMITTED_HOLDER = r"""<?php
error_reporting(E_ALL);
$db=new mysqli('localhost','root','',$argv[1],0,$argv[2]);
$db->set_charset('utf8mb4');
$actor=(int)$argv[3];$name=(string)$argv[4];
$ready=$argv[5];$release=$argv[6];
if (!$db->begin_transaction()) throw new RuntimeException('begin');
$stmt=$db->prepare("INSERT INTO pays
    (hash_id,user_id,type,plan_id,server_id,volume,day,price,request_date,state)
    VALUES (?,?,'INCREASE_WALLET',0,0,0,0,100000,UNIX_TIMESTAMP(),'pending')");
$stmt->bind_param('si',$name,$actor);
if (!$stmt->execute() || $stmt->affected_rows!==1)
    throw new RuntimeException('insert');
$stmt->close();
file_put_contents($ready,'uncommitted');
$limit=microtime(true)+14;
while (!is_file($release) && microtime(true)<$limit) usleep(12000);
$db->rollback();
echo 'ROLLED_BACK';
"""

# Source-real INSERT entrypoint with request_date computed BEFORE the owner
# lock. Two independent workers can commit in the opposite timestamp order.
# Keep both timestamps fresh: request_date is independently the idle deadline.
PRELOCK_DRAFT_WORKER = r"""<?php
error_reporting(E_ALL);
$root=$argv[1];$name=$argv[2];$socket=$argv[3];
$actor=(int)$argv[4];$hash=(string)$argv[5];$offset=(int)$argv[6];
$ready=$argv[7];$release=$argv[8];
$db=new mysqli('localhost','root','',$name,0,$socket);
$db->set_charset('utf8mb4');
require_once $root.'/src/Finance/PaymentLifecycle.php';
$stamp=time()-$offset;
$stmt=$db->prepare("INSERT INTO pays
    (hash_id,user_id,type,plan_id,server_id,volume,day,price,request_date,state)
    VALUES (?,?,'INCREASE_WALLET',0,0,0,0,100000,?,'pending')");
$stmt->bind_param('sii',$hash,$actor,$stamp);
file_put_contents($ready,(string)$stamp);
$limit=microtime(true)+14;
while (!is_file($release) && microtime(true)<$limit) usleep(11000);
if (!is_file($release)) throw new RuntimeException('draft_release_timeout');
$id=achbotPayUxInsertDraft($db,$actor,$stmt);
$stmt->close();
if ($id<=0) throw new RuntimeException('draft_not_committed');
echo json_encode(['id'=>$id,'request_date'=>$stamp],JSON_THROW_ON_ERROR);
"""

def sql(w,e,query):
    return finance.sql(w,e,query).strip()

def seed_pay(w,e,name,owner,typ='INCREASE_WALLET',target=None,state='pending',payid=None):
    target_sql='NULL' if target is None else str(int(target))
    payid_sql='NULL' if payid is None else "'"+payid+"'"
    sql(w,e,("INSERT INTO pays (hash_id,user_id,type,plan_id,server_id,"
             "volume,day,price,request_date,state,payid,order_id) VALUES "
             f"('{name}',{owner},'{typ}',0,0,0,0,100000,UNIX_TIMESTAMP(),"
             f"'{state}',{payid_sql},{target_sql})"))
    return int(sql(w,e,f"SELECT id FROM pays WHERE hash_id='{name}'"))

def first_event(result, name):
    return [x for x in result['events'] if x.get('name')==name]

def setup(w,e):
    stage=w/'current-issue51'
    stages.stage_source(stage,True)
    purchase.init(w,e,'http://127.0.0.1:9')
    sql(w,e,"INSERT INTO setting (type,value) VALUES ('BOT_STATES','{\"walletState\":\"on\",\"cartToCartState\":\"on\",\"giftCardState\":\"on\",\"manualUsdtState\":\"on\",\"nowPaymentWallet\":\"on\",\"nowPaymentOther\":\"on\",\"zarinpal\":\"on\",\"nextpay\":\"on\"}')")
    assert len(sql(w,e,'SHOW TABLES').splitlines())==23
    sql(w,e,(ROOT/'migrations/post-v9/003_payment_attempts.sql').read_text())
    sql(w,e,(ROOT/'migrations/post-v9/006_payment_message_refs.sql').read_text())
    assert len(sql(w,e,'SHOW TABLES').splitlines())==26
    keys=json.dumps({'nowpayment':'SYNTHETIC-LOCAL-ONLY',
                     'nextpay':'SYNTHETIC-LOCAL-ONLY',
                     'zarinpal':'SYNTHETIC-LOCAL-ONLY',
                     'nowpayment_ipn_secret':'LOCAL-IPN-SECRET','manualUsdtRateToman':'100000'})
    sql(w,e,"INSERT INTO setting (type,value) VALUES ('PAYMENT_KEYS','"+
        keys.replace("'","''")+"')")
    return stage

def fresh_actor(w,e,actor):
    sql(w,e,"INSERT INTO users (userid,name,username,refcode,date) "
        f"VALUES ({actor},'Synthetic owner','synthetic','0','1700000000')")

def php_cmd(w,stage,spec):
    driver=w/'owned-finance-guard.php'
    if not driver.exists():driver.write_text(finance.PHP_DRIVER)
    return ['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
            '-d','extension=curl','-d','allow_url_fopen=0',
            '-d','disable_functions=curl_init,curl_setopt,curl_setopt_array,curl_exec,curl_close,curl_error,curl_getinfo,exec,shell_exec,system,passthru,popen,proc_open,mail',
            str(driver),str(stage),DB,str(w/'mysql.sock'),json.dumps(spec)]

def json_run(w,e,stage,spec):
    output=subprocess.run(php_cmd(w,stage,spec),cwd=w,env=e,capture_output=True,timeout=22)
    assert output.returncode==0,(output.returncode,output.stdout[-1800:],output.stderr[-550:])
    assert not output.stderr,output.stderr[-600:]
    data=json.loads(output.stdout)
    assert not data['error'] and not data['fatal'],data
    return data

def exercise_raced_issuer(w,e,stage,method,n,*,old_stage=False):
    actor=9600+n
    old=f'F1-old-{method}'
    latest=f'F1-new-{method}'
    fresh_actor(w,e,actor)
    old_id=seed_pay(w,e,old,actor)
    marker_dir=w/('gate-'+method)
    marker_dir.mkdir()
    script=w/'owned-replacement-holder.php'
    script.write_text(REPLACEMENT_HOLDER)
    held=subprocess.Popen(
        ['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
         '-d','allow_url_fopen=0',str(script),DB,str(w/'mysql.sock'),
         str(actor),latest,str(marker_dir/'ready'),str(marker_dir/'release')],
        cwd=w,env=e,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    try:
        until=time.monotonic()+5
        while not (marker_dir/'ready').exists() and time.monotonic()<until:
            if held.poll() is not None:raise AssertionError('holder exited early')
            time.sleep(.012)
        assert (marker_dir/'ready').exists(),'holder did not acquire owner lock'
        with ThreadPoolExecutor(max_workers=1) as pool:
            pending=pool.submit(json_run,w,e,stage,
                {'route':'initiate','get':{method:'','hash_id':old}})
            blocked=False
            until=time.monotonic()+7
            while time.monotonic()<until and not pending.done():
                infos=sql(w,e,"SELECT INFO FROM information_schema.PROCESSLIST "
                    f"WHERE DB='{DB}' AND INFO LIKE '%users WHERE userid%FOR UPDATE%'")
                if 'FOR UPDATE' in infos and 'users' in infos:
                    blocked=True
                    break
                time.sleep(.02)
            # The barrier establishes old-preliminary-check completed before
            # the replacement commit, on the actual source checkout code.
            assert blocked,('checkout did not wait on owner lock after preliminary '
                            'checks; risk of issuing old invoice before replacement')
            (marker_dir/'release').write_text('go')
            std,err=held.communicate(timeout=6)
            assert held.returncode==0 and std==b'COMMITTED',(std,err)
            outcome=pending.result(timeout=12)
        assert not outcome['network_calls'],outcome['network_calls']
        assert sql(w,e,f'SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id={old_id}')=='0'
        assert sql(w,e,f'SELECT COALESCE(payid,"NULL") FROM pays WHERE id={old_id}')=='NULL'
        assert sql(w,e,f"SELECT COUNT(*) FROM pays WHERE hash_id='{latest}'")=='1'
        assert sql(w,e,f"SELECT state FROM pays WHERE hash_id='{latest}'")=='pending'
        return True
    finally:
        if held.poll() is None:
            (marker_dir/'release').write_text('go')
            held.kill();held.communicate(timeout=5)

def exercise_r1_inverted_commit_dates(w,e,stage):
    # The authoritative order is the pays AUTO_INCREMENT ID allocated while
    # the actor's user-row transaction lock is held. A's earlier PHP clock
    # is intentionally committed AFTER B's later PHP clock.
    verified=[]
    for n,method in enumerate(('nowpayment','nextpay','zarinpal')):
        actor=9840+n
        fresh_actor(w,e,actor)
        claim='R1-issued-'+method
        claim_id=seed_pay(w,e,claim,actor,
            payid='r1-prior-provider-'+method)
        sql(w,e,"INSERT INTO payment_attempts "
            "(pay_row_id,provider,order_hash,provider_reference,quote_amount,quote_currency,stage) "
            f"VALUES ({claim_id},'nowpayments','{claim}',"
            f"'r1-prior-provider-{method}',1,'usd','pending')")
        preexisting=sql(w,e,"SELECT p.state,p.payid,a.provider_reference,a.stage "
            "FROM pays p JOIN payment_attempts a ON a.pay_row_id=p.id "
            f"WHERE p.id={claim_id}")
        folder=w/('r1-opposite-'+method)
        folder.mkdir()
        script=w/'owned-prelock-draft-writer.php'
        script.write_text(PRELOCK_DRAFT_WORKER)
        older_date_hash='R1-id-older-'+method
        newer_id_hash='R1-id-newer-'+method
        a_ready=folder/'a-prepared'
        a_release=folder/'a-release'
        b_ready=folder/'b-prepared'
        b_release=folder/'b-release'
        b_release.write_text('now')
        def worker(h,offset,ready,release):
            return ['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
                    str(script),str(stage),DB,str(w/'mysql.sock'),str(actor),
                    h,str(offset),str(ready),str(release)]
        delayed=subprocess.Popen(worker(newer_id_hash,13,a_ready,a_release),
            cwd=w,env=e,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        try:
            deadline=time.monotonic()+5
            while not a_ready.exists() and time.monotonic()<deadline:
                if delayed.poll() is not None:raise AssertionError('early-prepared worker exited')
                time.sleep(.012)
            assert a_ready.exists(),'PHP A did not precompute timestamp before owner lock'
            first=subprocess.run(worker(older_date_hash,2,b_ready,b_release),
                cwd=w,env=e,capture_output=True,timeout=12)
            assert first.returncode==0,(first.stdout,first.stderr)
            assert not first.stderr,first.stderr
            first_id=json.loads(first.stdout)
            assert b_ready.exists(),'PHP B did not prepare its later timestamp'
            a_release.write_text('resume A, acquire user-row lock and INSERT')
            stdout,stderr=delayed.communicate(timeout=12)
            assert delayed.returncode==0,(stdout,stderr)
            assert not stderr,stderr
            second_id=json.loads(stdout)
        finally:
            if delayed.poll() is None:
                a_release.write_text('go')
                delayed.kill();delayed.communicate(timeout=4)
        bid=int(first_id['id']); aid=int(second_id['id'])
        assert bid<aid and first_id['request_date']>second_id['request_date'], (
            'not the reviewer R1 interleaving',first_id,second_id)
        assert int(sql(w,e,f"SELECT COUNT(*) FROM pays WHERE id IN ({bid},{aid})"))==2
        assert sql(w,e,f"SELECT state FROM pays WHERE id={bid}")=='pending'
        assert sql(w,e,f"SELECT state FROM pays WHERE id={aid}")=='pending'
        # No provider may issue for B: the source-prepared A persisted AFTER
        # B, despite its lower old PHP clock value.
        refused=json_run(w,e,stage,dict(route='initiate',
            get={method:'','hash_id':older_date_hash}))
        assert not refused['network_calls'],(
            'R1 checkout issued non-authoritative older-ID invoice',
            method,first_id,second_id,refused['network_calls'])
        assert sql(w,e,f"SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id={bid}")=='0'
        assert sql(w,e,f"SELECT COALESCE(payid,'NULL') FROM pays WHERE id={bid}")=='NULL'
        verified.append('r1_reverse_prelock_clock_old_'+method+'_refused_after_later_id_commit')
        # Positive: only the larger persisted ID can issue one provider claim.
        issued=json_run(w,e,stage,dict(route='initiate',
            get={method:'','hash_id':newer_id_hash}))
        assert len(issued['network_calls'])==1,(
            'latest persisted invoice was incorrectly blocked',
            method,issued['network_calls'],issued['form'])
        assert sql(w,e,f"SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id={aid}")=='1'
        assert sql(w,e,f"SELECT COALESCE(payid,'NULL') FROM pays WHERE id={aid}")!='NULL'
        assert sql(w,e,"SELECT p.state,p.payid,a.provider_reference,a.stage "
            "FROM pays p JOIN payment_attempts a ON a.pay_row_id=p.id "
            f"WHERE p.id={claim_id}")==preexisting
        verified.append('r1_reverse_prelock_clock_only_latest_'+method+'_reserves')
        # Opening B's obsolete first-party link itself safely expires B,
        # before the next scheduled Cron. A second expire is idempotent and
        # must NOT return a new successful transition.
        from test_post_v9_payment_ux_lifecycle import run as run_ux
        assert sql(w,e,f"SELECT state FROM pays WHERE id={bid}")=='expired'
        assert run_ux(w,e,{'action':'expire','id':bid})['result'] is False
        assert sql(w,e,"SELECT p.state,p.payid,a.provider_reference,a.stage "
            "FROM pays p JOIN payment_attempts a ON a.pay_row_id=p.id "
            f"WHERE p.id={claim_id}")==preexisting
        verified.append('r1_bot_owned_link_expires_superseded_old_id_'+method)
        # Independently prove the DIRECT source-real expiry call, without
        # first opening any checkout URL that would expire the target early.
        direct_actor=9880+n
        fresh_actor(w,e,direct_actor)
        direct_old=seed_pay(w,e,'R1-direct-old-'+method,direct_actor)
        direct_new=seed_pay(w,e,'R1-direct-new-'+method,direct_actor)
        first_created=int(sql(w,e,
            f"SELECT request_date FROM pays WHERE id={direct_old}"))
        sql(w,e,f"UPDATE pays SET request_date={first_created-11} "
            f"WHERE id={direct_new}")
        assert direct_old<direct_new
        assert run_ux(w,e,{'action':'expire','id':direct_old})['result'] is True
        assert sql(w,e,f"SELECT state FROM pays WHERE id={direct_old}")=='expired'
        assert sql(w,e,f"SELECT state FROM pays WHERE id={direct_new}")=='pending'
        assert sql(w,e,"SELECT p.state,p.payid,a.provider_reference,a.stage "
            "FROM pays p JOIN payment_attempts a ON a.pay_row_id=p.id "
            f"WHERE p.id={claim_id}")==preexisting
        verified.append('r1_direct_expiry_uses_authoritative_id_not_earlier_timestamp_'+method)
    # The batch scanner must match the direct expiry and checkout rules.
    actor=9877
    fresh_actor(w,e,actor)
    older=seed_pay(w,e,'R1-maintain-id-old',actor)
    newer=seed_pay(w,e,'R1-maintain-id-new',actor)
    old_date=int(sql(w,e,f'SELECT request_date FROM pays WHERE id={older}'))
    sql(w,e,f'UPDATE pays SET request_date={old_date-12} WHERE id={newer}')
    from test_post_v9_payment_ux_lifecycle import run as run_ux
    assert sql(w,e,f"SELECT state FROM pays WHERE id={older}")=='pending'
    assert run_ux(w,e,{'action':'maintain','limit':100})['result']['expired']>=1
    assert sql(w,e,f"SELECT state FROM pays WHERE id={older}")=='expired'
    assert sql(w,e,f"SELECT state FROM pays WHERE id={newer}")=='pending'
    verified.append('r1_maintenance_expires_older_id_when_latest_timestamp_is_earlier')
    # A committed replacement permanently invalidates its predecessor.
    # Otherwise a newer short-lived or declined quote can *revive* an old
    # payable hosted link before that old row itself reaches its idle TTL.
    for n,terminal in enumerate(('expired','declined','mismatched_plan')):
        actor=9890+n
        fresh_actor(w,e,actor)
        oldhash='R1-no-revival-old-'+terminal
        newhash='R1-no-revival-new-'+terminal
        old=seed_pay(w,e,oldhash,actor)
        new=seed_pay(w,e,newhash,actor)
        sql(w,e,f"UPDATE pays SET state='{terminal}' WHERE id={new}")
        rejected=json_run(w,e,stage,dict(route='initiate',
            get={'nowpayment':'','hash_id':oldhash}))
        assert not rejected['network_calls'],(
            'obsolete provider link reactivated after the newer committed '
            'draft became terminal',terminal,rejected['network_calls'])
        assert sql(w,e,f"SELECT state FROM pays WHERE id={old}")=='expired'
        assert sql(w,e,f"SELECT state FROM pays WHERE id={new}")==terminal
        assert sql(w,e,f"SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id={old}")=='0'
        verified.append('r1_terminal_'+terminal+'_cannot_reactivate_older_quote')
    return verified


def exercise_successor_lookup_interrupted(w,e,stage):
    actor=9691
    name='F1lockfaultold'
    fresh_actor(w,e,actor)
    pid=seed_pay(w,e,name,actor)
    d=w/'lookup-failure'
    d.mkdir()
    script=w/'owned-lookup-holder.php'
    script.write_text(UNCOMMITTED_HOLDER)
    held=subprocess.Popen(
        ['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
         str(script),DB,str(w/'mysql.sock'),str(actor),'F1lockfaultnew',
         str(d/'ready'),str(d/'release')],
        cwd=w,env=e,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    try:
        cutoff=time.monotonic()+6
        while not (d/'ready').exists() and time.monotonic()<cutoff:
            time.sleep(.02)
        assert (d/'ready').exists(),'uncommitted successor holder failed'
        with ThreadPoolExecutor(max_workers=1) as pool:
            pending=pool.submit(json_run,w,e,stage,dict(route='initiate',
                get={'nowpayment':'','hash_id':name}))
            waiting_id=None
            cutoff=time.monotonic()+7
            while time.monotonic()<cutoff and not pending.done():
                rows=sql(w,e,"SELECT ID FROM information_schema.PROCESSLIST "
                    f"WHERE DB='{DB}' AND INFO LIKE '%SUBSTRING_INDEX%' AND INFO LIKE '%FOR UPDATE%' "
                    "AND INFO NOT LIKE '%PROCESSLIST%'")
                values=[t for t in rows.splitlines() if t.strip().isdigit()]
                if values:
                    waiting_id=int(values[0]);break
                time.sleep(.018)
            assert waiting_id is not None,'successor read did not reach blocked locking query'
            sql(w,e,f'KILL QUERY {waiting_id}')
            output=pending.result(timeout=9)
        assert not output['network_calls']
        assert sql(w,e,f'SELECT COALESCE(payid,"NULL") FROM pays WHERE id={pid}')=='NULL'
        assert sql(w,e,f'SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id={pid}')=='0'
        return 'f1_interrupted_successor_locking_read_rolls_back_without_provider'
    finally:
        (d/'release').write_text('go')
        if held.poll() is None:
            std,err=held.communicate(timeout=8)
            assert held.returncode==0 and std==b'ROLLED_BACK',(std,err)

def exercise_callback_identity(w,e,stage):
    cases=[]
    # Real ps_<hash> status route; old/new same family but matched order_id.
    for kind in ('RENEW_ACCOUNT','INCREASE_DAY_11_4','INCREASE_VOLUME_11_4'):
        target=23000+len(cases)*10
        old=f'F3-old-{len(cases)}'; newer=f'F3-new-{len(cases)}'
        seed_pay(w,e,old,1001,kind,target)
        time.sleep(.02)
        seed_pay(w,e,newer,1001,
                 'INCREASE_DAY_12_5' if kind.startswith('INCREASE_DAY')
                 else 'INCREASE_VOLUME_12_5' if kind.startswith('INCREASE_VOLUME')
                 else kind,target)
        seen=json_run(w,e,stage,dict(route='prepayment',get={},
                                    callback_data='ps_'+old,actor=1001))
        texts=[str(x['args'][1]) for x in first_event(seen,'editText')]
        assert len(texts)==1 and 'جایگزین' in texts[0],(kind,texts)
        cases.append('f3_same_target_'+kind)
    # Another subscription target cannot supersede an unrelated invoice.
    target=23440
    old='F3-unrelated-old';newer='F3-unrelated-new'
    seed_pay(w,e,old,1001,'RENEW_ACCOUNT',target)
    seed_pay(w,e,newer,1001,'RENEW_ACCOUNT',target+1)
    unrelated=json_run(w,e,stage,dict(route='prepayment',get={},
                                      callback_data='ps_'+old,actor=1001))
    assert 'جایگزین' not in str(first_event(unrelated,'editText')),unrelated
    cases.append('f3_unrelated_subscription_not_superseded')
    # An already issued provider invoice is never confused with an empty old
    # draft; its immutable payment attempt and upstream reference stay intact.
    issued='F3-issued-old';issued_new='F3-issued-new'
    eid=seed_pay(w,e,issued,1001,'RENEW_ACCOUNT',24580,payid='f3-real-claim')
    sql(w,e,"INSERT INTO payment_attempts "
        "(pay_row_id,provider,order_hash,provider_reference,quote_amount,quote_currency,stage) "
        f"VALUES ({eid},'nowpayments','{issued}','f3-real-claim',1,'usd','pending')")
    seed_pay(w,e,issued_new,1001,'RENEW_ACCOUNT',24580)
    claim=json_run(w,e,stage,dict(route='prepayment',get={},
                                    callback_data='ps_'+issued,actor=1001))
    assert 'نیازمند بررسی' in str(first_event(claim,'editText')),claim
    assert sql(w,e,f"SELECT payid,state FROM pays WHERE id={eid}")=='f3-real-claim\tpending'
    assert sql(w,e,f"SELECT provider_reference FROM payment_attempts WHERE pay_row_id={eid}")=='f3-real-claim'
    cases.append('f3_old_provider_claim_never_cancelled')
    # A removed payment method must not even READ an invoice, much less
    # reserve a gateway or mutate its saved evidence. The actual PrePayment
    # module (not a stub) executes on real synthetic PHP/MySQLi.
    name='LegacyDeniedMethod'
    pid=seed_pay(w,e,name,1001,'INCREASE_WALLET',state='pending')
    before=sql(w,e,f'SELECT user_id,state,payid FROM pays WHERE id={pid}')
    for method in ('WeSwap','TronWallet','UnsupportedGateway'):
        rejected=json_run(w,e,stage,dict(route='prepayment',get={},
            callback_data='prePayCheck_Buy_'+method+'_'+name,actor=1001))
        assert first_event(rejected,'callback'),(method,rejected)
        assert not rejected.get('network_calls'),(method,rejected)
        assert not first_event(rejected,'sendMessage'),(method,rejected)
        assert sql(w,e,f'SELECT user_id,state,payid FROM pays WHERE id={pid}')==before
        assert sql(w,e,f'SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id={pid}')=='0'
        cases.append('generic_unsupported_payment_callback_no_effect_'+method)
    return cases

def exercise_shared_crypto(w,e,stage):
    cases=[]
    # Exact RENEW_ACCOUNT callback advertised by the real RenewalCheckout.
    old='F2sharedcryptorenew'
    order_id=24883
    sql(w,e,"INSERT INTO orders_list "
        "(id,userid,token,transid,fileid,server_id,inbound_id,remark,uuid,"
        "protocol,expire_date,link,amount,status,date) VALUES "
        f"({order_id},1001,'fixture-token','',8,2,0,'f2-shared','f2-uuid',"
        "'vless',2000000000,'[]',100,1,'synthetic')")
    payment=seed_pay(w,e,old,1001,'RENEW_ACCOUNT',order_id)
    sql(w,e,f"UPDATE pays SET plan_id=8,server_id=2 WHERE id={payment}")
    default={'manualUsdtState':'on'}
    for title,usdt,trx,allowed in [
        ('trx_only','off','all','mc_t_'),
        ('usdt_only','all','off','mc_c_'),
        ('both','all','all','mc_t_'),
        ('neither','off','off','')]:
        state={**default,'gatewayAudience':{
            'manual_usdt':usdt,'manual_trx':trx}}
        result=json_run(w,e,stage,dict(route='prepayment',get={},
            callback_data='prePayCheck_Renew_ManualUsdt_'+old,
            states=state,actor=1001))
        rendered=json.dumps(first_event(result,'editText'),ensure_ascii=False)
        if allowed:
            assert allowed+old in rendered,(title,rendered,result)
            assert ('mc_t_'+old in rendered)==(trx=='all'),(title,rendered)
            assert ('mc_c_'+old in rendered)==(usdt=='all'),(title,rendered)
        else:
            assert 'mc_t_'+old not in rendered and 'mc_c_'+old not in rendered
            assert first_event(result,'callback'),result
        cases.append('f2_real_renewal_shared_crypto_selector_'+title)
        for tag,provider in [('t','manual_trx'),('c','manual_usdt')]:
            pick=json_run(w,e,stage,dict(route='prepayment',get={},
                callback_data='mc_'+tag+'_'+old,states=state,actor=1001))
            selected=first_event(pick,'selected_data')
            assert selected
            chosen=selected[-1]['data']
            if (trx=='all' if tag=='t' else usdt=='all'):
                assert chosen=='payRenewWithManualUsdt'+old,(title,tag,selected)
            else:
                assert chosen=='mc_'+tag+'_'+old,(title,tag,selected)
            assert sql(w,e,f'SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id={payment}')=='0'
    forged=json_run(w,e,stage,dict(route='prepayment',get={},
        callback_data='mc_t_'+old,states={**default,'gatewayAudience':{
            'manual_trx':'all','manual_usdt':'off'}},actor=9999))
    assert not any('payRenewWith' in str(x) for x in forged['events'])
    cases.append('f2_forged_crypto_selector_cannot_start_other_users_claim')
    return cases

def exercise_f4_real_gateway_restrictions(w,e,stage):
    cases=[]
    # These are real first-party checkout HTTP entrypoints, not only the
    # authorization helper. Denied policies must not reserve a provider claim.
    for n,(method,policy) in enumerate((
            ('nowpayment',None),
            ('nowpayment',{'nowpayments':None}),
            ('nextpay',{'nextpay':[]}),
            ('zarinpal',{'zarinpal':'off'}),
            ('nowpayment',{'nowpayments':'agents'}),
            ('zarinpal',{'zarinpal':{}}),
            )):
        actor=9710+n
        fresh_actor(w,e,actor)
        key='F4policy'+str(n)
        pid=seed_pay(w,e,key,actor)
        out=json_run(w,e,stage,dict(route='initiate',
            get={method:'','hash_id':key},
            states={'gatewayAudience':policy}))
        assert not out['network_calls'],(method,policy,out)
        assert sql(w,e,f'SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id={pid}')=='0'
        assert sql(w,e,f'SELECT COALESCE(payid,"NULL") FROM pays WHERE id={pid}')=='NULL'
        assert sql(w,e,f'SELECT state FROM pays WHERE id={pid}')=='pending'
        cases.append('f4_real_gateway_rejects_explicit_invalid_audience_'+str(n))
    return cases


def exercise_reservation_failure(w,e,stage):
    actor=9670
    fresh_actor(w,e,actor)
    name='F1-sql-rollback'
    pid=seed_pay(w,e,name,actor)
    sql(w,e,"CREATE TRIGGER f1_reject_invoice_before_insert BEFORE INSERT ON payment_attempts "
        "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='owned synthetic rejection'")
    try:
        for provider in ['nowpayment','nextpay','zarinpal']:
            resp=json_run(w,e,stage,dict(route='initiate',
                get={provider:'','hash_id':name},mysqli_report_off=True))
            assert not resp['network_calls'],resp
            assert sql(w,e,f'SELECT COALESCE(payid,"NULL") FROM pays WHERE id={pid}')=='NULL'
            assert sql(w,e,f'SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id={pid}')=='0'
    finally:
        sql(w,e,'DROP TRIGGER IF EXISTS f1_reject_invoice_before_insert')
    return 'f1_failed_reservation_rolls_back_before_nonidempotent_provider'

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('--run-disposable',action='store_true',required=True)
    ap.add_argument('--report',type=Path)
    ap.add_argument('--red-baseline',action='store_true',
                    help='Demonstrate old candidate failure, never run in CI')
    ap.add_argument('--r1-only',action='store_true',
                    help='Isolate reviewer R1 source-real inverted timestamp regression')
    ap.add_argument('--r1-reviewed-head',action='store_true',
                    help='Stage original reviewed d698 HEAD lifecycle for isolated RED evidence')
    args=ap.parse_args()
    if args.r1_reviewed_head and (not args.r1_only or args.red_baseline):
        ap.error('--r1-reviewed-head requires --r1-only and not --red-baseline')
    cases=[]
    with owned.owned_database() as (w,e):
        stage=setup(w,e)
        if args.red_baseline:
            # An isolated staged file snapshot, never the actual working tree.
            old=subprocess.check_output(['git','show',
                'bfd9da7d9198e866014519bcc01491e6dfd5fca4:src/Finance/GatewayInitiation.php'],
                cwd=ROOT)
            (stage/'src/Finance/GatewayInitiation.php').write_bytes(old)
            old_life=subprocess.check_output(['git','show',
                'bfd9da7d9198e866014519bcc01491e6dfd5fca4:src/Finance/PaymentLifecycle.php'],
                cwd=ROOT)
            (stage/'src/Finance/PaymentLifecycle.php').write_bytes(old_life)
        if args.r1_reviewed_head:
            old_life=subprocess.check_output(['git','show',
                'd698dd8e099b80861982e2cf579e7f564f73c5ad:src/Finance/PaymentLifecycle.php'],
                cwd=ROOT)
            (stage/'src/Finance/PaymentLifecycle.php').write_bytes(old_life)
        if not args.r1_only:
            for n,provider in enumerate(('nowpayment','nextpay','zarinpal')):
                assert exercise_raced_issuer(w,e,stage,provider,n)
                cases.append('f1_raced_old_'+provider+'_cannot_issue_after_committed_successor')
        if not args.red_baseline:
            cases.extend(exercise_r1_inverted_commit_dates(w,e,stage))
        if not args.red_baseline and not args.r1_only:
            cases.append(exercise_reservation_failure(w,e,stage))
            cases.extend(exercise_f4_real_gateway_restrictions(w,e,stage))
            cases.append(exercise_successor_lookup_interrupted(w,e,stage))
            cases.extend(exercise_callback_identity(w,e,stage))
            cases.extend(exercise_shared_crypto(w,e,stage))
    result={'complete':True,'cases':len(cases),'scenarios':cases,
            'source_real_php':True,'owned_mariadb_only':True,
            'real_provider_requests':0,'production_mutations':0}
    if args.report:args.report.write_text(json.dumps(result,indent=2,ensure_ascii=False))
    print(json.dumps(result,ensure_ascii=False))

if __name__=='__main__':main()
