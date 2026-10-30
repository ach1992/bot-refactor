#!/usr/bin/env python3
"""PR #60 F2 hosted issuance: persisted policy/cohort revocation at FINAL claim.

Three providers execute actual guarded HTTP PHP entrypoint with locally mocked
transport. Two real MySQLi sessions commit a revocation AFTER initial GET but
BEFORE owner-locked provider reservation. Reviewed source demonstrably RED;
new source must preserve no attempt and issue zero remote invoices. No real IO.
"""
from __future__ import annotations
import argparse
import ast
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
N1_HEAD='e3a189b589b1df0651a25092f6ac7ef17218ad28'
ACTIVE_FLAGS={'nowPaymentWallet':'on','nowPaymentOther':'on',
              'zarinpal':'on','nextpay':'on'}
PRIMARY_CASES=(('nowpayment','nowpayments','INCREASE_WALLET','nowPaymentWallet'),
               ('nowpayment','nowpayments','BUY_SUB','nowPaymentOther'),
               ('zarinpal','zarinpal','INCREASE_WALLET','zarinpal'),
               ('nextpay','nextpay','INCREASE_WALLET','nextpay'))

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

def stages_for(w,old,baseline=HEAD):
    stage=w/('hosted-n1-old' if old and baseline!=HEAD else
             ('hosted-old' if old else 'hosted-current'))
    stages.stage_source(stage,True)
    # The archived PHP driver keeps its original include path and bytes.
    # This old file exists ONLY in the owned RED fixture, never the runtime.
    p=stage/('src/Finance/GatewayInitiation.php' if old else 'pay/checkout.php')
    source=(subprocess.check_output(['git','show',baseline+':src/Finance/GatewayInitiation.php'],
             cwd=ROOT) if old else p.read_bytes())
    if old:
        life=subprocess.check_output(['git','show',
               baseline+':src/Finance/PaymentLifecycle.php'],cwd=ROOT)
        (stage/'src/Finance/PaymentLifecycle.php').write_bytes(life)
    for anchor in (
        b"        achbotPayUxLockDraftOwner($db,$ownerId);",
        b"            achbotPayUxLockDraftOwner($connection,(int)$payParam['user_id']);"
    ):
        assert source.count(anchor)==1,(anchor,source.count(anchor))
        source=source.replace(anchor,HOOK.encode()+anchor)
    p.write_bytes(source)
    return stage

def driver_text(baseline=None):
    t=finance.PHP_DRIVER
    if baseline is not None:
        # Pair each immutable post-V9 RED module with its own original
        # synthetic driver. Current source never receives legacy globals.
        raw=subprocess.check_output(['git','show',
            baseline+':tools/test_post_v9_finance_routes.py'],cwd=ROOT)
        constants=[ast.literal_eval(node.value) for node in ast.parse(raw).body
            if isinstance(node,ast.Assign) and any(
                isinstance(target,ast.Name) and target.id=='PHP_DRIVER'
                for target in node.targets)]
        assert len(constants)==1 and isinstance(constants[0],str),baseline
        t=constants[0]
        # Adapt only bootstrap imports in this disposable post-V9 RED driver.
        # Core now owns shared ReceiptHelpers; archived source/route assertions
        # remain exact, including every revocation race and original RED case.
        for module in ['src/Purchase/Core.php','src/Finance/ReceiptHelpers.php',
                       'src/Finance/GatewayClient.php']:
            old_import="require $root . '/"+module+"';"
            assert t.count(old_import)==1,(baseline,module)
            t=t.replace(old_import,"require_once $root . '/"+module+"';",1)
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

def one(w,e,stage,driver,provider,mode,old,index,iso,typ='INCREASE_WALLET',flag=None):
    name,policy_method=provider
    actor=12800+index
    checkout.fresh_actor(w,e,actor)
    sql(w,e,f'UPDATE users SET is_agent=1 WHERE userid={actor}')
    hash_='f2host'+str(index)
    pid=checkout.seed_pay(w,e,hash_,actor,typ=typ)
    if typ=='BUY_SUB':
        sql(w,e,f'UPDATE pays SET plan_id=7,server_id=2 WHERE id={pid}')
    policy=json.dumps({'nowPaymentWallet':'on','nowPaymentOther':'on','zarinpal':'on','nextpay':'on','gatewayAudience':{policy_method:(
        'agents' if mode=='cohort' else 'all')}},separators=(',',':'))
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
            new=json.dumps({'nowPaymentWallet':'on','nowPaymentOther':'on','zarinpal':'on','nextpay':'on','gatewayAudience':{policy_method:'off'}},separators=(',',':'))
            sql(w,e,"UPDATE setting SET value='"+new+"' WHERE type='BOT_STATES'")
        elif mode=='primary':
            assert flag in ACTIVE_FLAGS
            disabled={**ACTIVE_FLAGS,flag:'off',
                      'gatewayAudience':{policy_method:'all'}}
            new=json.dumps(disabled,separators=(',',':'))
            sql(w,e,"UPDATE setting SET value='"+new+"' WHERE type='BOT_STATES'")
        elif mode=='cohort':
            sql(w,e,f'UPDATE users SET is_agent=0 WHERE userid={actor}')
        else:
            assert mode=='positive'
        (folder/'release').write_text('after-revocation')
        out,err=proc.communicate(timeout=13)
        assert proc.returncode==0 and not err,(out[-1200:],err[-1200:])
        report=json.loads(out)
        assert report['fatal'] is None and not report['error'],report
        attempted=int(sql(w,e,f"SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id={pid}"))
        calls=report['network_calls']
        expected_claim=old or mode=='positive'
        assert (attempted>0 and len(calls)>0) if expected_claim else (attempted==0 and len(calls)==0),(
            name,mode,old,attempted,calls,report['form'][:200])
        if mode in ('primary','positive'):
            return ('n1_hosted_'+name+'_'+str(flag)+'_'+mode+
                ('_old_red' if old else '_new_green')+'_'+iso.lower().replace('-','_'))
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
        n1_old=stages_for(w,True,N1_HEAD)
        purchase.init(w,e,'http://127.0.0.1:9')
        sql(w,e,(ROOT/'migrations/post-v9/003_payment_attempts.sql').read_text())
        sql(w,e,"INSERT INTO setting (type,value) VALUES ('BOT_STATES','{\"walletState\":\"on\",\"cartToCartState\":\"on\",\"giftCardState\":\"on\",\"manualUsdtState\":\"on\",\"nowPaymentWallet\":\"on\",\"nowPaymentOther\":\"on\",\"zarinpal\":\"on\",\"nextpay\":\"on\"}')")
        keys=json.dumps({'nowpayment':'LOCAL','nextpay':'LOCAL','zarinpal':'LOCAL',
                         'nowpayment_ipn_secret':'LOCAL','manualUsdtRateToman':'100000'})
        sql(w,e,"INSERT INTO setting(type,value) VALUES('PAYMENT_KEYS','"+
            keys.replace("'","''")+"')")
        driver=w/'f2-hosted-driver.php';driver.write_text(driver_text())
        old_driver=w/'f2-hosted-old-driver.php';old_driver.write_text(driver_text(HEAD))
        n1_driver=w/'f2-hosted-n1-driver.php';n1_driver.write_text(driver_text(N1_HEAD))
        idx=0
        for provider in PROVIDERS:
            for mode in ('policy','cohort'):
                cases.append(one(w,e,old,old_driver,provider,mode,True,idx,'READ-COMMITTED'));idx+=1
                for iso in ('READ-COMMITTED','REPEATABLE-READ'):
                    cases.append(one(w,e,new,driver,provider,mode,False,idx,iso));idx+=1
        for name,policy_method,typ,flag in PRIMARY_CASES:
            provider=(name,policy_method)
            cases.append(one(w,e,n1_old,n1_driver,provider,'primary',True,idx,
                             'READ-COMMITTED',typ,flag));idx+=1
            for iso in ('READ-COMMITTED','REPEATABLE-READ'):
                cases.append(one(w,e,new,driver,provider,'primary',False,idx,iso,
                                 typ,flag));idx+=1
            cases.append(one(w,e,new,driver,provider,'positive',False,idx,
                             'READ-COMMITTED',typ,flag));idx+=1
    output={'complete':True,'cases':len(cases),'scenarios':cases,
        'old_source_red':True,'current_source_green':True,
        'real_mysqli':True,'provider_requests_mock_only':True,
        'production_mutations':0}
    if args.report:args.report.write_text(json.dumps(output,indent=2)+'\n')
    print(json.dumps(output))
if __name__=='__main__':main()
