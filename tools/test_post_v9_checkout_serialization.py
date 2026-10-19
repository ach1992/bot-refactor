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
    assert len(sql(w,e,'SHOW TABLES').splitlines())==23
    sql(w,e,(ROOT/'migrations/post-v9/003_payment_attempts.sql').read_text())
    sql(w,e,(ROOT/'migrations/post-v9/006_payment_message_refs.sql').read_text())
    assert len(sql(w,e,'SHOW TABLES').splitlines())==26
    keys=json.dumps({'nowpayment':'SYNTHETIC-LOCAL-ONLY',
                     'nextpay':'SYNTHETIC-LOCAL-ONLY',
                     'zarinpal':'SYNTHETIC-LOCAL-ONLY',
                     'nowpayment_ipn_secret':'LOCAL-IPN-SECRET'})
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
    args=ap.parse_args()
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
        for n,provider in enumerate(('nowpayment','nextpay','zarinpal')):
            assert exercise_raced_issuer(w,e,stage,provider,n)
            cases.append('f1_raced_old_'+provider+'_cannot_issue_after_committed_successor')
        if not args.red_baseline:
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
