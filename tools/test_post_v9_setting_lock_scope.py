#!/usr/bin/env python3
"""PR #60 N2: SOURCE-REAL narrow BOT_STATES locks vs panel-group administration.

Runs actual current (and prior exact e3a189b) PHP authorization and
achbotSetMarzbanGroup against disposable socket-only original MariaDB + 001.
No production provider, wallet debit, Telegram or installed mutation.
"""
from __future__ import annotations
import argparse
import json
from pathlib import Path
import subprocess
import time

import test_access_state as owned
import test_v8_native as purchase
import test_post_v9_checkout_serialization as checkout
import test_post_v9_purchases as stages
import test_post_v9_settings_concurrency as settings_suite

ROOT=Path(__file__).resolve().parents[1]
DB=purchase.SIDE[1]
BASE='e3a189b589b1df0651a25092f6ac7ef17218ad28'
LATENCIES=[]
FLAGS={k:'on' for k in ('walletState','cartToCartState','giftCardState',
    'manualUsdtState','nowPaymentWallet','nowPaymentOther','zarinpal','nextpay')}

PAYMENT=r"""<?php
error_reporting(E_ALL);
set_error_handler(static function($level,$message) {
    if ($level===E_DEPRECATED) return true;
    throw new ErrorException($message,0,$level);
});
$_SERVER['SCRIPT_FILENAME']=__FILE__;
$root=$argv[1];$socket=$argv[2];$database=$argv[3];
$spec=json_decode($argv[4],true,512,JSON_THROW_ON_ERROR);
$db=new mysqli('localhost','root','',$database,0,$socket);
$db->set_charset('utf8mb4');
$db->query("SET SESSION innodb_lock_wait_timeout=3");
if ($spec['iso']==='READ-COMMITTED')
    $db->query('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
require $root.'/src/Finance/PaymentLifecycle.php';
require_once $root.'/src/Purchase/Core.php';
$result=['authorized'=>false,'subject'=>false,'error'=>null,'errno'=>0];
$started=false;
try {
    if (!$db->begin_transaction()) throw new RuntimeException('begin');
    $started=true;
    $uid=(int)$spec['actor'];
    $stmt=$db->prepare("SELECT id FROM users WHERE userid=? FOR UPDATE");
    $stmt->bind_param('i',$uid);$stmt->execute();
    $users=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
    if (count($users)!==1) throw new RuntimeException('owner_not_unique');
    $id=(int)$spec['pay'];
    $stmt=$db->prepare('SELECT * FROM pays WHERE id=? FOR UPDATE');
    $stmt->bind_param('i',$id);$stmt->execute();
    $pay=$stmt->get_result()->fetch_assoc();$stmt->close();
    if (!$pay || (int)$pay['user_id']!==$uid)
        throw new RuntimeException('pay_invalid');
    $result['authorized']=achbotPayUxAudienceAllowed(
        $db,$spec['method'],$uid,true,(string)$pay['type']);
    if (!$result['authorized']) throw new RuntimeException('policy_rejected');
    file_put_contents($spec['ready'],'policy-read');
    $end=microtime(true)+12;
    while (!is_file($spec['release']) && microtime(true)<$end) usleep(9000);
    if (!is_file($spec['release'])) throw new RuntimeException('payment_release_timeout');
    $result['subject']=achbotSupportsPaymentSubject($db,$pay,true);
    if (!$result['subject']) throw new RuntimeException('subject_rejected');
    if (!$db->commit()) throw new RuntimeException('commit');
    $started=false;
} catch(Throwable $e) {
    if ($started) {$db->rollback();}
    $result['error']=$e->getMessage();
    $result['errno']=(int)$e->getCode();
}
echo json_encode($result,JSON_THROW_ON_ERROR);
"""

ADMIN=r"""<?php
error_reporting(E_ALL);
set_error_handler(static function($level,$message) {
    if ($level===E_DEPRECATED) return true;
    throw new ErrorException($message,0,$level);
});
$_SERVER['SCRIPT_FILENAME']=__FILE__;
$root=$argv[1];$socket=$argv[2];$database=$argv[3];
$spec=json_decode($argv[4],true,512,JSON_THROW_ON_ERROR);
$db=new mysqli('localhost','root','',$database,0,$socket);
$db->set_charset('utf8mb4');
$db->query("SET SESSION innodb_lock_wait_timeout=3");
require_once $root.'/src/Purchase/Core.php';
$result=['committed'=>false,'error'=>null,'errno'=>0];
$started=false;
try {
    $db->begin_transaction();$started=true;
    $stmt=$db->prepare("SELECT type FROM server_config WHERE id=? FOR UPDATE");
    $sid=(int)$spec['server'];$stmt->bind_param('i',$sid);
    $stmt->execute();$server=$stmt->get_result()->fetch_assoc();$stmt->close();
    if (($server['type']??null)!=='marzban') throw new RuntimeException('panel_invalid');
    file_put_contents($spec['ready'],'server-config-locked');
    $end=microtime(true)+12;
    while (!is_file($spec['release']) && microtime(true)<$end) usleep(9000);
    if (!is_file($spec['release'])) throw new RuntimeException('admin_release_timeout');
    achbotSetMarzbanGroup($db,$sid,(int)$spec['group']);
    if (!$db->commit()) throw new RuntimeException('admin_commit');
    $started=false;$result['committed']=true;
} catch(Throwable $e) {
    if ($started) {$db->rollback();}
    $result['error']=$e->getMessage();
    $result['errno']=(int)$e->getCode();
}
echo json_encode($result,JSON_THROW_ON_ERROR);
"""

SETTINGS_WRITER=r"""<?php
error_reporting(E_ALL);
set_error_handler(static function($level,$message) {
    if ($level===E_DEPRECATED) return true;
    throw new ErrorException($message,0,$level);
});
$_SERVER['SCRIPT_FILENAME']=__FILE__;
$connection=new mysqli('localhost','root','',$argv[2],0,$argv[1]);
$connection->set_charset('utf8mb4');
$connection->query('SET SESSION innodb_lock_wait_timeout=5');
$botState=['walletState'=>'on'];
require $argv[3];
$result=setSettings('walletState','off');
echo json_encode(['saved'=>$result],JSON_THROW_ON_ERROR);
"""

def sql(w,e,q):
    return checkout.sql(w,e,q)

def staged(w,old):
    stage=w/('n2-prior-e3' if old else 'n2-current')
    stages.stage_source(stage,True)
    if old:
        (stage/'src/Finance/PaymentLifecycle.php').write_bytes(
            subprocess.check_output(['git','show',BASE+':src/Finance/PaymentLifecycle.php'],
                                    cwd=ROOT))
    return stage

def user_pay(w,e,index,typ='BUY_SUB'):
    uid=14200+index
    checkout.fresh_actor(w,e,uid)
    name='n2pay'+str(index)
    pid=checkout.seed_pay(w,e,name,uid,typ=typ)
    if typ=='BUY_SUB':
        sql(w,e,f'UPDATE pays SET plan_id=7,server_id=2 WHERE id={pid}')
    return uid,pid

def call(w,e,stage,driver,spec):
    return subprocess.Popen([
        'php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
        '-d','allow_url_fopen=0',
        '-d','disable_functions=exec,shell_exec,system,passthru,popen,proc_open,mail,dl',
        str(driver),str(stage),str(w/'mysql.sock'),DB,json.dumps(spec)],
        cwd=w,env=e,stdout=subprocess.PIPE,stderr=subprocess.PIPE)

def wait(marker,worker,timeout=6):
    end=time.monotonic()+timeout
    while not marker.exists() and time.monotonic()<end:
        if worker.poll() is not None:
            raise AssertionError(('worker_stopped',worker.communicate()))
        time.sleep(.009)
    assert marker.exists(),str(marker)

def get(worker,timeout=12):
    out,err=worker.communicate(timeout=timeout)
    assert worker.returncode==0 and not err,(worker.returncode,out,err)
    return json.loads(out)

def run_group(w,e,stage,pdrv,adrv,method,iso,old,index,group_id):
    uid,pid=user_pay(w,e,index)
    folder=w/('n2-admin-'+str(index));folder.mkdir()
    ar=folder/'admin.ready';ap=folder/'admin.release'
    pr=folder/'pay.ready';pp=folder/'pay.release'
    admin=call(w,e,stage,adrv,dict(server=2,group=group_id,
                                    ready=str(ar),release=str(ap)))
    payment=None
    try:
        wait(ar,admin)
        payment=call(w,e,stage,pdrv,dict(actor=uid,pay=pid,iso=iso,method=method,
                                        ready=str(pr),release=str(pp)))
        wait(pr,payment)
        # Admin owns server_config first and must be able to lock the
        # unrelated Marzban-group setting record, despite payment's policy S.
        ap.write_text('go')
        if old:
            time.sleep(.3)
            assert admin.poll() is None, 'old unindexed scan did not block admin'
            pp.write_text('try-server-config')
            a=get(admin);p=get(payment)
            assert a['error'] is not None or p['error'] is not None,(method,a,p)
            assert not (a['committed'] and p['subject'] and p['error'] is None),(a,p)
            return 'n2_prior_unindexed_group_inverse_lock_detected_'+method
        a=get(admin)
        assert a['committed'] and a['error'] is None,(method,iso,a)
        assert payment.poll() is None, 'payment left staged policy boundary'
        pp.write_text('commit_after_group')
        p=get(payment)
        assert p['authorized'] and p['subject'] and p['error'] is None,(method,iso,p)
        assert sql(w,e,f'SELECT panel_group_id FROM server_info WHERE id=2')==str(group_id)
        return 'n2_group_admin_serializes_without_inverse_'+method+'_'+iso.lower().replace('-','_')
    finally:
        ap.write_text('finish');pp.write_text('finish')
        if admin.poll() is None:admin.kill();admin.communicate(timeout=4)
        if payment and payment.poll() is None:payment.kill();payment.communicate(timeout=4)

def run_two_readers(w,e,stage,pdrv,kind,index,old=False):
    first_actor,first_pay=user_pay(w,e,index)
    second_actor,second_pay=user_pay(w,e,index+1,
                                     typ='INCREASE_WALLET' if kind=='unrelated' else 'BUY_SUB')
    folder=w/('n2-reader-'+str(index));folder.mkdir()
    def spec(n,actor,pid,method):
        return dict(actor=actor,pay=pid,iso='REPEATABLE-READ',method=method,
                    ready=str(folder/(n+'.ready')),release=str(folder/(n+'.release')))
    a=call(w,e,stage,pdrv,spec('a',first_actor,first_pay,'wallet'))
    b=None
    try:
        wait(folder/'a.ready',a)
        started=time.monotonic()
        b=call(w,e,stage,pdrv,spec('b',second_actor,second_pay,'nowpayments'))
        if old:
            # Previous exclusive BOT_STATES locks force B to wait, although
            # the two customers are independent and neither has hit a plan.
            time.sleep(.45)
            assert not (folder/'b.ready').exists(), 'old reader was not exclusive'
            assert b.poll() is None
            (folder/'a.release').write_text('allow-second')
            wait(folder/'b.ready',b,timeout=4)
        else:
            # Shared PRIMARY-key locks allow B to read while A remains live.
            wait(folder/'b.ready',b,timeout=3)
            assert a.poll() is None
            (folder/'a.release').write_text('continue')
        LATENCIES.append({'kind':kind,'prior_exclusive':old,
                          'second_policy_ready_seconds':round(time.monotonic()-started,3)})
        (folder/'b.release').write_text('continue')
        ra=get(a);rb=get(b)
        assert all(r['authorized'] and r['subject'] and r['error'] is None
                   for r in (ra,rb)),(ra,rb)
        return ('n2_prior_exclusive_policy_serializes_independent_customers' if old
                else 'n2_shared_policy_two_customers_'+kind)
    finally:
        (folder/'a.release').write_text('cleanup')
        (folder/'b.release').write_text('cleanup')
        if a.poll() is None:a.kill();a.communicate(timeout=4)
        if b is not None and b.poll() is None:b.kill();b.communicate(timeout=4)

def run_writer_guard(w,e,stage,pdrv,sdriver,func,iso,index):
    # An existing payment has authorized the enabled method and still holds
    # a shared PRIMARY-key lock. The actual setting writer must wait until
    # the financial transaction commits, then revoke future claims.
    state=json.dumps(FLAGS,separators=(',',':'))
    sql(w,e,"UPDATE setting SET value='"+state+"' WHERE type='BOT_STATES'")
    uid,pid=user_pay(w,e,index)
    folder=w/('n2-settings-'+str(index));folder.mkdir()
    pr=folder/'pay.ready';pp=folder/'pay.release'
    p=call(w,e,stage,pdrv,dict(actor=uid,pay=pid,iso=iso,
                                method='wallet',ready=str(pr),release=str(pp)))
    writer=None
    try:
        wait(pr,p)
        writer=subprocess.Popen(
            ['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
             str(sdriver),str(w/'mysql.sock'),DB,str(func)],
            cwd=w,env=e,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        time.sleep(.3)
        assert writer.poll() is None,'writer ignored shared-policy financial lock'
        pp.write_text('finish-authorized-claim')
        result=get(p)
        assert result['authorized'] and result['subject'] and result['error'] is None,result
        changed=get(writer)
        assert changed['saved'] is True,changed
        saved=json.loads(sql(w,e,"SELECT value FROM setting WHERE type='BOT_STATES'"))
        assert saved['walletState']=='off',saved
        # A subsequent independent transaction must be denied (no marker).
        nextuid,nextpid=user_pay(w,e,index+1)
        second=call(w,e,stage,pdrv,dict(actor=nextuid,pay=nextpid,iso=iso,
                   method='wallet',ready=str(folder/'second.ready'),
                   release=str(folder/'second.release')))
        declined=get(second)
        assert declined['authorized'] is False and declined['subject'] is False
        assert declined['error']=='policy_rejected',declined
        assert not (folder/'second.ready').exists()
        return 'n2_settings_revocation_waits_for_financial_shared_lock_'+iso.lower().replace('-','_')
    finally:
        pp.write_text('cleanup')
        if p.poll() is None:p.kill();p.communicate(timeout=4)
        if writer is not None and writer.poll() is None:
            writer.kill();writer.communicate(timeout=4)


def main():
    p=argparse.ArgumentParser()
    p.add_argument('--run-disposable',required=True,action='store_true')
    p.add_argument('--report',type=Path)
    a=p.parse_args()
    cases=[]
    with owned.owned_database() as (w,e):
        stage=staged(w,False);previous=staged(w,True)
        payment=w/'n2-payment-driver.php';payment.write_text(PAYMENT)
        admin=w/'n2-admin-driver.php';admin.write_text(ADMIN)
        swriter=w/'n2-settings-writer.php';swriter.write_text(SETTINGS_WRITER)
        sfunctions=w/'n2-actual-settings.php'
        sfunctions.write_bytes(settings_suite.source_functions())
        purchase.init(w,e,'http://127.0.0.1:9')
        sql(w,e,(ROOT/'migrations/post-v9/001_marzban_panel_group_id.sql').read_text())
        state=json.dumps(FLAGS,separators=(',',':'))
        sql(w,e,"INSERT INTO setting(type,value) VALUES('BOT_STATES','"+state+"')")
        sql(w,e,"INSERT INTO setting(type,value) VALUES('ACHBOT_MARZBAN_PANEL_GROUP','N2 Synthetic')")
        group_id=int(sql(w,e,"SELECT id FROM setting WHERE type='ACHBOT_MARZBAN_PANEL_GROUP'"))
        assert int(sql(w,e,"SELECT COUNT(*) FROM information_schema.statistics "
                         "WHERE table_schema=DATABASE() AND table_name='setting' "
                         "AND column_name='type'"))==0
        policy_id=int(sql(w,e,"SELECT id FROM setting WHERE type='BOT_STATES'"))
        plan=sql(w,e,"EXPLAIN SELECT type,value FROM setting WHERE id="+str(policy_id)+" LOCK IN SHARE MODE")
        assert 'PRIMARY' in plan,plan
        cases.append('n2_original_schema_primary_key_policy_plan_verified')
        cases.append(run_group(w,e,previous,payment,admin,'wallet','REPEATABLE-READ',True,0,group_id))
        j=10
        for method in ('wallet','nowpayments','bank_transfer'):
            for iso in ('READ-COMMITTED','REPEATABLE-READ'):
                cases.append(run_group(w,e,stage,payment,admin,method,iso,False,j,group_id))
                j+=1
        cases.append(run_two_readers(w,e,previous,payment,'shared_plan',78,True))
        cases.append(run_two_readers(w,e,stage,payment,'shared_plan',80))
        cases.append(run_two_readers(w,e,stage,payment,'unrelated',90))
        for i,iso in enumerate(('READ-COMMITTED','REPEATABLE-READ')):
            cases.append(run_writer_guard(w,e,stage,payment,swriter,sfunctions,
                                          iso,110+i*2))
    output={'complete':True,'cases':len(cases),'scenarios':cases,
            'previous_unindexed_scan_detected':True,'current_primary_key_shared_lock':True,
            'measured_policy_reader_wait_seconds':LATENCIES,
            'real_php_mysqli':True,'actual_panel_group_source':True,
            'owned_mariadb_only':True,'production_mutations':0,'external_effects':0}
    if a.report:a.report.write_text(json.dumps(output,indent=2)+'\n')
    print(json.dumps(output))
if __name__=='__main__':main()
