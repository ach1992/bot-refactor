#!/usr/bin/env python3
"""PR #60 F2: manual method policy revoked after owner-locked preflight.

Runs existing real source achbotManualClaim using two independent PHP/MySQLi
sessions and an injected stage-only pause just before authoritative check.
Earlier candidate fails RED (creates stale payment attempt); current must
reject without destination/evidence. No real manual/chain/provider operation.
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
import test_post_v9_manual_payments as manual

ROOT=Path(__file__).resolve().parents[1]
DB=purchase.SIDE[1]
HEAD='9774a93c1f98204ce8373f467c62f62a3660903b'
N1_HEAD='e3a189b589b1df0651a25092f6ac7ef17218ad28'
FLAGS={'bank_transfer':'cartToCartState','gift_card':'giftCardState',
       'manual_usdt':'manualUsdtState','manual_trx':'manualUsdtState'}
ACTIVE={'cartToCartState':'on','giftCardState':'on','manualUsdtState':'on'}
METHODS=('bank_transfer','gift_card','manual_usdt','manual_trx')
HOOK=r"""        if (($GLOBALS['f2_ready']??'')!=='') {
            file_put_contents((string)$GLOBALS['f2_ready'],'just-before-policy');
            $until=microtime(true)+12;
            while (!is_file((string)$GLOBALS['f2_release']) && microtime(true)<$until)
                usleep(10000);
            if (!is_file((string)$GLOBALS['f2_release']))
                throw new RuntimeException('f2_manual_release_timeout');
        }
"""

def sql(w,e,q):return checkout.sql(w,e,q)

def stage_source(w,old,baseline=HEAD):
    stage=w/('manual-n1-old' if old and baseline!=HEAD else
             ('manual-audience-old' if old else 'manual-audience-new'))
    stages.stage_source(stage,True)
    p=stage/'src/Finance/ReceiptHelpers.php'
    s=(subprocess.check_output(['git','show',baseline+':src/Finance/ReceiptHelpers.php'],
        cwd=ROOT) if old else p.read_bytes())
    if old:
        (stage/'src/Finance/PaymentLifecycle.php').write_bytes(
            subprocess.check_output(['git','show',baseline+':src/Finance/PaymentLifecycle.php'],
            cwd=ROOT))
    anchor=b"        if (!achbotManualEnabled($method) ||"
    assert s.count(anchor)==1
    p.write_bytes(s.replace(anchor,HOOK.encode()+anchor))
    return stage

def driver():
    d=manual.PHP
    anchor="$spec=json_decode($argv[4],true,512,JSON_THROW_ON_ERROR);"
    assert d.count(anchor)==1
    return d.replace(anchor,anchor+"""
$GLOBALS['f2_ready']=$spec['f2_ready']??'';
$GLOBALS['f2_release']=$spec['f2_release']??'';
""")

def run_one(w,e,stage,driver_file,method,old,idx,variant='policy'):
    actor=13000+idx
    checkout.fresh_actor(w,e,actor)
    h='f2manual'+str(idx)
    pid=checkout.seed_pay(w,e,h,actor)
    state={**ACTIVE,'gatewayAudience':{method:'all'}}
    if variant=='initially_off':state[FLAGS[method]]='off'
    policy=json.dumps(state,separators=(',',':'))
    sql(w,e,"UPDATE setting SET value='"+policy+"' WHERE type='BOT_STATES'")
    folder=w/('f2-manual-'+str(idx));folder.mkdir()
    cfg={
        'op':'claim','method':method,'user':actor,
        'f2_ready':str(folder/'ready'),'f2_release':str(folder/'release'),
        'states':{'cartToCartState':'on','giftCardState':'on','manualUsdtState':'on'},
        'keys':{
            'bankAccount':'6037999999999999','holderName':'Fixture',
            'giftCardUrl':'https://example.org/gift',
            'manualUsdtWallet':'0x'+'1'*40,'manualUsdtNetwork':'BEP20',
            'manualUsdtRateToman':'100000','manualTrxWallet':'TJRabPrwbZy45sbavfcjinPJC18kjpRTv8',
            'manualTrxRateToman':'15000'
        }
    }
    cmd=['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
         '-d','allow_url_fopen=0',
         '-d','disable_functions=exec,shell_exec,system,passthru,popen,proc_open,mail,dl',
         str(driver_file),str(stage),str(w/'mysql.sock'),h,json.dumps(cfg),DB]
    worker=subprocess.Popen(cmd,cwd=w,env=e,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    try:
        until=time.monotonic()+5
        while not (folder/'ready').exists() and time.monotonic()<until:
            if worker.poll() is not None:raise AssertionError(worker.communicate())
            time.sleep(.01)
        assert (folder/'ready').exists()
        if variant=='policy':
            state['gatewayAudience'][method]='off'
        elif variant=='primary':
            state[FLAGS[method]]='off'
        else:
            assert variant in ('positive','initially_off')
        policy=json.dumps(state,separators=(',',':'))
        sql(w,e,"UPDATE setting SET value='"+policy+"' WHERE type='BOT_STATES'")
        (folder/'release').write_text('committed')
        out,err=worker.communicate(timeout=12)
        assert worker.returncode==0 and not err,(out,err)
        result=json.loads(out)['result']
        expected=old or variant=='positive'
        assert result is expected,(method,old,variant,result)
        attempts=sql(w,e,f'SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id={pid}')
        assert attempts==('1' if expected else '0'),(method,old,variant,attempts)
        assert sql(w,e,f'SELECT state FROM pays WHERE id={pid}')=='pending'
        if variant=='positive' and not old:
            # Issued manual evidence is NOT retroactively revoked. Disabling
            # the gateway forbids new claims but preserves the pinned quote.
            original=sql(w,e,f'SELECT checkout_url FROM payment_attempts WHERE pay_row_id={pid}')
            state[FLAGS[method]]='off'
            policy=json.dumps(state,separators=(',',':'))
            sql(w,e,"UPDATE setting SET value='"+policy+"' WHERE type='BOT_STATES'")
            repeat=subprocess.run(cmd,cwd=w,env=e,capture_output=True,timeout=10)
            assert repeat.returncode==0 and not repeat.stderr,(method,repeat.stderr)
            assert json.loads(repeat.stdout)['result'] is True,(method,repeat.stdout)
            assert sql(w,e,f'SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id={pid}')=='1'
            assert sql(w,e,f'SELECT checkout_url FROM payment_attempts WHERE pay_row_id={pid}')==original
        if variant!='policy':
            return ('n1_manual_'+method+'_'+variant+
                ('_old_red' if old else '_new_green'))
        return 'f2_manual_'+method+('_old_red' if old else '_new_green')
    finally:
        (folder/'release').write_text('done')
        if worker.poll() is None:worker.kill();worker.communicate(timeout=4)

def main():
    a=argparse.ArgumentParser()
    a.add_argument('--run-disposable',required=True,action='store_true')
    a.add_argument('--report',type=Path)
    args=a.parse_args()
    cases=[]
    with owned.owned_database() as (w,e):
        old=stage_source(w,True);new=stage_source(w,False)
        n1_old=stage_source(w,True,N1_HEAD)
        purchase.init(w,e,'http://127.0.0.1:9')
        sql(w,e,(ROOT/'migrations/post-v9/003_payment_attempts.sql').read_text())
        sql(w,e,"INSERT INTO setting(type,value) VALUES('BOT_STATES','{\"walletState\":\"on\",\"cartToCartState\":\"on\",\"giftCardState\":\"on\",\"manualUsdtState\":\"on\",\"nowPaymentWallet\":\"on\",\"nowPaymentOther\":\"on\",\"zarinpal\":\"on\",\"nextpay\":\"on\"}')")
        d=w/'f2-manual-driver.php';d.write_text(driver())
        n=0
        for method in METHODS:
            cases.append(run_one(w,e,old,d,method,True,n));n+=1
            cases.append(run_one(w,e,new,d,method,False,n));n+=1
            for variant in ('primary','initially_off'):
                cases.append(run_one(w,e,n1_old,d,method,True,n,variant));n+=1
                cases.append(run_one(w,e,new,d,method,False,n,variant));n+=1
            cases.append(run_one(w,e,new,d,method,False,n,'positive'));n+=1
    report={'complete':True,'cases':len(cases),'scenarios':cases,
        'old_source_red':True,'current_source_green':True,'owned_mariadb_only':True,
        'real_php_mysqli':True,'external_effects':0,'production_mutations':0}
    if args.report:args.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps(report))
if __name__=='__main__':main()
