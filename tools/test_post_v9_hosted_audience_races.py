#!/usr/bin/env python3
"""PR #60 F2 hosted issuance: persisted policy/cohort revocation at FINAL claim.

Three providers execute actual guarded HTTP PHP entrypoint with locally mocked
transport. Two real MySQLi sessions commit a revocation AFTER initial GET but
BEFORE owner-locked provider reservation. Reviewed source demonstrably RED;
new source must preserve no attempt and issue zero remote invoices. No real IO.
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
import test_post_v9_checkout_serialization as checkout
import test_post_v9_finance_routes as finance

ROOT=Path(__file__).resolve().parents[1]
DB=purchase.SIDE[1]
HEAD='9774a93c1f98204ce8373f467c62f62a3660903b'
PROVIDERS=(('nowpayment','nowpayments'),('zarinpal','zarinpal'),('nextpay','nextpay'))

HOOK=r"""        if (($GLOBALS['f2_ready']??'')!=='') {
            file_put_contents((string)$GLOBALS['f2_ready'],'about-to-reserve');
            $until=microtime(true)+12;
            while (!is_file((string)$GLOBALS['f2_release']) && microtime(true)<$until)
                usleep(10000);
            if (!is_file((string)$GLOBALS['f2_release']))
                throw new RuntimeException('f2_test_release_timeout');
        }
"""

def sql(w,e,q):return checkout.sql(w,e,q)

def stages_for(w,old):
    stage=w/('hosted-old' if old else 'hosted-current')
    stages.stage_source(stage,True)
    p=stage/'src/Finance/GatewayInitiation.php'
    source=(subprocess.check_output(['git','show',HEAD+':src/Finance/GatewayInitiation.php'],
             cwd=ROOT) if old else p.read_bytes())
    if old:
        life=subprocess.check_output(['git','show',
               HEAD+':src/Finance/PaymentLifecycle.php'],cwd=ROOT)
        (stage/'src/Finance/PaymentLifecycle.php').write_bytes(life)
    for anchor in (
        b"        achbotPayUxLockDraftOwner($db,$ownerId);",
        b"            achbotPayUxLockDraftOwner($connection,(int)$payParam['user_id']);"
    ):
        assert source.count(anchor)==1,(anchor,source.count(anchor))
        source=source.replace(anchor,HOOK.encode()+anchor)
    p.write_bytes(source)
    return stage

def driver_text():
    t=finance.PHP_DRIVER
    a="$GLOBALS['events'] = [];"
    assert t.count(a)==1
    t=t.replace(a,a+"""
$GLOBALS['f2_ready']=$spec['f2_ready']??'';
$GLOBALS['f2_release']=$spec['f2_release']??'';
""")
    charset="$connection->set_charset('utf8mb4');"
    assert t.count(charset)==1
    t=t.replace(charset,charset+"""
    if (($spec['isolation']??'')==='READ-COMMITTED')
        $connection->query('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    """)
    return t

def wait(path,proc):
    deadline=time.monotonic()+5
    while not path.exists() and time.monotonic()<deadline:
        if proc.poll() is not None:raise AssertionError(('PHP_no_marker',proc.communicate()))
        time.sleep(.012)
    assert path.exists(),str(path)

def one(w,e,stage,driver,provider,mode,old,index,iso):
    name,policy_method=provider
    actor=12800+index
    checkout.fresh_actor(w,e,actor)
    sql(w,e,f'UPDATE users SET is_agent=1 WHERE userid={actor}')
    hash_='f2host'+str(index)
    pid=checkout.seed_pay(w,e,hash_,actor)
    policy=json.dumps({'gatewayAudience':{policy_method:(
        'all' if mode=='policy' else 'agents')}},separators=(',',':'))
    sql(w,e,"UPDATE setting SET value='"+policy+"' WHERE type='BOT_STATES'")
    folder=w/('f2-hosted-'+str(index));folder.mkdir()
    spec={'route':'initiate','get':{name:'','hash_id':hash_},
          'actor':actor,'f2_ready':str(folder/'ready'),
          'f2_release':str(folder/'release'),'isolation':iso}
    proc=subprocess.Popen(['php','-n','-d','extension=mysqlnd','-d',
           'extension=mysqli','-d','extension=curl','-d','allow_url_fopen=0',
           '-d','disable_functions=curl_init,curl_setopt,curl_setopt_array,curl_exec,curl_close,curl_error,curl_getinfo,exec,shell_exec,system,passthru,popen,proc_open,mail',
           str(driver),str(stage),DB,str(w/'mysql.sock'),json.dumps(spec)],
           cwd=w,env=e,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    try:
        wait(folder/'ready',proc)
        if mode=='policy':
            new=json.dumps({'gatewayAudience':{policy_method:'off'}},separators=(',',':'))
            sql(w,e,"UPDATE setting SET value='"+new+"' WHERE type='BOT_STATES'")
        else:
            sql(w,e,f'UPDATE users SET is_agent=0 WHERE userid={actor}')
        (folder/'release').write_text('after-revocation')
        out,err=proc.communicate(timeout=13)
        assert proc.returncode==0 and not err,(out[-1200:],err[-1200:])
        report=json.loads(out)
        assert report['fatal'] is None and not report['error'],report
        attempted=int(sql(w,e,f"SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id={pid}"))
        calls=report['network_calls']
        assert (attempted>0 and len(calls)>0) if old else (attempted==0 and len(calls)==0),(
            name,mode,old,attempted,calls,report['form'][:200])
        return 'f2_hosted_'+name+'_'+mode+('_old_red' if old else '_new_green')+'_'+iso.lower().replace('-','_')
    finally:
        (folder/'release').write_text('done')
        if proc.poll() is None:proc.kill();proc.communicate(timeout=4)

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('--run-disposable',action='store_true',required=True)
    ap.add_argument('--report',type=Path)
    args=ap.parse_args()
    cases=[]
    with owned.owned_database() as (w,e):
        old=stages_for(w,True);new=stages_for(w,False)
        purchase.init(w,e,'http://127.0.0.1:9')
        sql(w,e,(ROOT/'migrations/post-v9/003_payment_attempts.sql').read_text())
        sql(w,e,"INSERT INTO setting (type,value) VALUES ('BOT_STATES','{}')")
        keys=json.dumps({'nowpayment':'LOCAL','nextpay':'LOCAL','zarinpal':'LOCAL',
                         'nowpayment_ipn_secret':'LOCAL'})
        sql(w,e,"INSERT INTO setting(type,value) VALUES('PAYMENT_KEYS','"+
            keys.replace("'","''")+"')")
        driver=w/'f2-hosted-driver.php';driver.write_text(driver_text())
        idx=0
        for provider in PROVIDERS:
            for mode in ('policy','cohort'):
                cases.append(one(w,e,old,driver,provider,mode,True,idx,'READ-COMMITTED'));idx+=1
                for iso in ('READ-COMMITTED','REPEATABLE-READ'):
                    cases.append(one(w,e,new,driver,provider,mode,False,idx,iso));idx+=1
    output={'complete':True,'cases':len(cases),'scenarios':cases,
        'old_source_red':True,'current_source_green':True,
        'real_mysqli':True,'provider_requests_mock_only':True,
        'production_mutations':0}
    if args.report:args.report.write_text(json.dumps(output,indent=2)+'\n')
    print(json.dumps(output))
if __name__=='__main__':main()
