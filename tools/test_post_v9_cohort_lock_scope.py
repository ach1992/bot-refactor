#!/usr/bin/env python3
"""PR #60 R1: customer-scoped CURRENT locking reads, real PHP/MySQLi on owned MariaDB.

Two unrelated customer transactions are held concurrently. A locks its user,
paid BUY_SUB and active order; B must pass its own owner, pays and final cohort
authorization WITHOUT waiting for A's records. Exercise positive/absent matches,
both isolation levels and small/populated original-schema data. EXPLAIN checks
all four affected index paths. No live provider, Telegram or wallet effects.
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

ROOT=Path(__file__).resolve().parents[1]
DB=purchase.SIDE[1]
MODES=('agents','non_agents','buyers','non_buyers','has_active_order','no_active_order')
ISOLATIONS=('READ-COMMITTED','REPEATABLE-READ')
FLAGS={'walletState':'on','nowPaymentWallet':'on','nowPaymentOther':'on'}

HOLDER=r"""<?php
error_reporting(E_ALL);
set_error_handler(static function($l,$m) {
    if ($l===E_DEPRECATED) return true;
    throw new ErrorException($m,0,$l);
});
$db=new mysqli('localhost','root','',$argv[1],0,$argv[2]);
$db->set_charset('utf8mb4');
$db->query('SET SESSION innodb_lock_wait_timeout=4');
$uid=(int)$argv[3];$pay=(int)$argv[4];$order=(int)$argv[5];
$db->begin_transaction();
$stmt=$db->prepare('SELECT id FROM users FORCE INDEX (idx_users_userid_v1) WHERE userid=? FOR UPDATE');
$stmt->bind_param('i',$uid);$stmt->execute();
$rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
if (count($rows)!==1) throw new RuntimeException('unrelated_owner_missing');
$stmt=$db->prepare('SELECT id FROM pays WHERE id=? FOR UPDATE');
$stmt->bind_param('i',$pay);$stmt->execute();
if (!$stmt->get_result()->fetch_assoc()) throw new RuntimeException('unrelated_pay_missing');
$stmt->close();
$stmt=$db->prepare('SELECT id FROM orders_list WHERE id=? FOR UPDATE');
$stmt->bind_param('i',$order);$stmt->execute();
if (!$stmt->get_result()->fetch_assoc()) throw new RuntimeException('unrelated_order_missing');
$stmt->close();
file_put_contents($argv[6],'all-unrelated-rows-locked');
$until=microtime(true)+12;
while (!is_file($argv[7]) && microtime(true)<$until) usleep(9000);
if (!is_file($argv[7])) throw new RuntimeException('unrelated_holder_timeout');
if (!$db->commit()) throw new RuntimeException('unrelated_commit_failed');
echo 'COMMITTED';
"""

PAYMENT=r"""<?php
error_reporting(E_ALL);
set_error_handler(static function($l,$m) {
    if ($l===E_DEPRECATED) return true;
    throw new ErrorException($m,0,$l);
});
$root=$argv[1];$name=$argv[2];$socket=$argv[3];
$spec=json_decode($argv[4],true,512,JSON_THROW_ON_ERROR);
$db=new mysqli('localhost','root','',$name,0,$socket);
$db->set_charset('utf8mb4');
$db->query('SET SESSION innodb_lock_wait_timeout=4');
if ($spec['isolation']==='READ-COMMITTED')
    $db->query('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
require_once $root.'/src/Finance/PaymentLifecycle.php';
$result=['allowed'=>null,'error'=>null,'errno'=>0,'owner_and_pay_locked'=>false];
$started=false;
try {
    if (!$db->begin_transaction()) throw new RuntimeException('payment_begin');
    $started=true;
    $uid=(int)$spec['actor'];$pid=(int)$spec['pay'];
    achbotPayUxLockDraftOwner($db,$uid);
    $stmt=$db->prepare('SELECT * FROM pays WHERE id=? FOR UPDATE');
    $stmt->bind_param('i',$pid);
    if (!$stmt->execute()) throw new RuntimeException('payment_pay_lock');
    $pay=$stmt->get_result()->fetch_assoc();$stmt->close();
    if (!$pay || (int)$pay['user_id']!==$uid) throw new RuntimeException('payment_owner');
    $result['owner_and_pay_locked']=true;
    $result['allowed']=achbotPayUxAudienceAllowed(
        $db,'wallet',$uid,true,(string)$pay['type']);
    file_put_contents($spec['ready'],$result['allowed']?'allowed':'denied');
    if ($result['allowed']!==(bool)$spec['expected']) throw new RuntimeException('cohort_mismatch');
    $until=microtime(true)+12;
    while (!is_file($spec['release']) && microtime(true)<$until) usleep(9000);
    if (!is_file($spec['release'])) throw new RuntimeException('payment_release_timeout');
    if (!$db->commit()) throw new RuntimeException('payment_commit');
    $started=false;
} catch(Throwable $err) {
    if ($started) $db->rollback();
    $result['error']=$err->getMessage();
    $result['errno']=(int)$err->getCode();
}
echo json_encode($result,JSON_THROW_ON_ERROR);
"""

def sql(w,e,q):return checkout.sql(w,e,q)

def order(w,e,uid,number):
    name='r1-order-'+str(number)
    sql(w,e,("INSERT INTO orders_list "
        "(userid,token,transid,fileid,server_id,inbound_id,remark,uuid,"
        "protocol,expire_date,link,amount,status,date) "
        f"VALUES({uid},'synthetic','',7,2,0,'{name}',"
        f"'r1-uuid-{number}','vless',2000000000,'[]',100,1,'synthetic')"))
    return int(sql(w,e,f"SELECT id FROM orders_list WHERE remark='{name}'"))

def process(w,e,script,args):
    return subprocess.Popen(['php','-n','-d','extension=mysqlnd',
        '-d','extension=mysqli','-d','allow_url_fopen=0',
        '-d','disable_functions=exec,shell_exec,system,passthru,popen,proc_open,mail,dl',
        str(script),*map(str,args)],cwd=w,env=e,
        stdout=subprocess.PIPE,stderr=subprocess.PIPE)

def await_file(path,proc,seconds=3.5):
    deadline=time.monotonic()+seconds
    while not path.exists() and time.monotonic()<deadline:
        if proc.poll() is not None:
            raise AssertionError(('premature_php_exit',proc.returncode,proc.communicate()))
        time.sleep(.012)
    assert path.exists(),('cross_customer_unauthorized_wait',str(path),proc.poll())

def finish(proc,seconds=12):
    out,err=proc.communicate(timeout=seconds)
    assert proc.returncode==0 and not err,(proc.returncode,out[-1000:],err[-1000:])
    return out

def plan(w,e,query,expected):
    result=sql(w,e,'EXPLAIN '+query)
    rows=result.splitlines()
    assert len(rows)==1,('unexpected_join_plan',result)
    cols=rows[0].split('\t')
    assert len(cols)>5 and cols[5]==expected and cols[3] not in ('ALL','index'),(
        'unbounded_financial_current_read',expected,result)
    return True

def check_plans(w,e,uid,distribution):
    tests={
        'owner':("SELECT id FROM users FORCE INDEX (idx_users_userid_v1) "
                 f"WHERE userid={uid} FOR UPDATE",'idx_users_userid_v1'),
        'cohort_user':("SELECT id,is_agent FROM users FORCE INDEX (idx_users_userid_v1) "
                       f"WHERE userid={uid} LIMIT 2 FOR UPDATE",'idx_users_userid_v1'),
        'buyer':("SELECT id FROM pays FORCE INDEX (idx_pays_user_v1) "
                 f"WHERE user_id={uid} AND type='BUY_SUB' AND state IN "
                 "('paid','approved','auto_cart_approved','paid_with_wallet') "
                 "LIMIT 1 FOR UPDATE",'idx_pays_user_v1'),
        'order':("SELECT id FROM orders_list FORCE INDEX (idx_orders_userid_v1) "
                 f"WHERE userid={uid} AND status=1 AND expire_date>1000000000 "
                 "LIMIT 1 FOR UPDATE",'idx_orders_userid_v1'),
    }
    labels=[]
    for name,(query,index) in tests.items():
        plan(w,e,query,index)
        labels.append('r1_'+distribution+'_plan_'+name+'_indexed')
    return labels

def case(w,e,holder,worker,dist,iso,mode,positive,index):
    a=150000+index*2;b=a+1
    checkout.fresh_actor(w,e,a)
    checkout.fresh_actor(w,e,b)
    sql(w,e,f'UPDATE users SET is_agent=1 WHERE userid={a}')
    a_pay=checkout.seed_pay(w,e,'r1-a-'+str(index),a,typ='BUY_SUB',state='paid')
    a_order=order(w,e,a,index*3)
    b_pay=checkout.seed_pay(w,e,'r1-b-'+str(index),b,typ='INCREASE_WALLET')
    is_agent=(mode=='agents' and positive) or (mode=='non_agents' and not positive)
    sql(w,e,f"UPDATE users SET is_agent={1 if is_agent else 0} WHERE userid={b}")
    bought=(mode=='buyers' and positive) or (mode=='non_buyers' and not positive)
    if bought:checkout.seed_pay(w,e,'r1-history-'+str(index),b,typ='BUY_SUB',state='paid')
    active=(mode=='has_active_order' and positive) or (mode=='no_active_order' and not positive)
    if active:order(w,e,b,index*3+1)
    settings={**FLAGS,'gatewayAudience':{'wallet':mode}}
    sql(w,e,"UPDATE setting SET value='"+json.dumps(settings).replace("'","''")+
            "' WHERE type='BOT_STATES'")
    base=w/('r1-case-'+str(index));base.mkdir()
    a_ready=base/'a.ready';a_release=base/'a.release'
    b_ready=base/'b.ready';b_release=base/'b.release'
    h=process(w,e,holder,[DB,w/'mysql.sock',a,a_pay,a_order,a_ready,a_release])
    p=None
    try:
        await_file(a_ready,h)
        spec={'actor':b,'pay':b_pay,'isolation':iso,'ready':str(b_ready),
              'release':str(b_release),'expected':positive}
        p=process(w,e,worker,[ROOT,DB,w/'mysql.sock',json.dumps(spec)])
        # Until B reaches FINAL current cohort authorization, all of A's
        # distinct customer rows remain X-locked. A broad scan blocks here.
        await_file(b_ready,p)
        assert h.poll() is None,'unrelated_transaction_finished_too_early'
        assert b_ready.read_text()==('allowed' if positive else 'denied')
        b_release.write_text('commit')
        report=json.loads(finish(p))
        assert report['owner_and_pay_locked'] is True and report['allowed'] is positive
        assert report['error'] is None,(mode,iso,dist,positive,report)
        a_release.write_text('commit')
        assert finish(h)==b'COMMITTED'
        expected='eligible' if positive else 'ineligible'
        return 'r1_'+dist+'_'+iso.lower().replace('-','_')+'_'+mode+'_'+expected+'_independent'
    finally:
        a_release.write_text('cleanup')
        b_release.write_text('cleanup')
        if p is not None and p.poll() is None:
            p.kill();p.communicate(timeout=5)
        if h.poll() is None:
            h.kill();h.communicate(timeout=5)

def populate(w,e):
    for i in range(28):
        uid=170000+i
        checkout.fresh_actor(w,e,uid)
        checkout.seed_pay(w,e,'r1-filler-'+str(i),uid,
                          typ='BUY_SUB' if i%2 else 'INCREASE_WALLET',
                          state='paid' if i%2 else 'pending')
        if i%3==0:order(w,e,uid,3000+i)

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('--run-disposable',action='store_true',required=True)
    ap.add_argument('--report',type=Path)
    args=ap.parse_args()
    results=[]
    with owned.owned_database() as (w,e):
        purchase.init(w,e,'http://127.0.0.1:9')
        sql(w,e,"INSERT INTO setting(type,value) VALUES('BOT_STATES','"+
            json.dumps(FLAGS)+"')")
        h=w/'r1-holder.php';h.write_text(HOLDER)
        p=w/'r1-payment.php';p.write_text(PAYMENT)
        version=sql(w,e,'SELECT VERSION()')
        idx=0
        for distribution in ('small','populated'):
            if distribution=='populated':populate(w,e)
            # Plan assertions exercise the original schema's actual index
            # names in both data shapes, not just the source text.
            results.extend(check_plans(w,e,9002,distribution))
            for iso in ISOLATIONS:
                for mode in MODES:
                    for positive in (True,False):
                        results.append(case(w,e,h,p,distribution,iso,mode,positive,idx))
                        idx+=1
    report={'complete':True,'cases':len(results),'scenarios':results,
            'source_real_php':True,'two_actual_mysqli_sessions':True,
            'current_lock_plan_checked':True,'original_schema_only':True,
            'owned_mariadb_only':True,'isolation_modes_verified':True,
            'absent_and_positive_matches':True,'small_and_populated_plans':True,
            'mariadb_version':version,'production_mutations':0,
            'remote_provider_calls':0}
    if args.report:args.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps(report))
if __name__=='__main__':main()
