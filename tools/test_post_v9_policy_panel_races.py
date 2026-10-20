#!/usr/bin/env python3
"""PR #60 F2/F4: authoritative wallet policy and panel/plan linearization.

Real PHP/MySQLi transactions on an owned socket-only MariaDB. RED stages the
exact previous HEAD source; GREEN exercises current source. Barriers exist
ONLY in disposable source copies, not in shipping PHP. No real provider.
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
import test_post_v9_wallet_authorization_freshness as r3

ROOT=Path(__file__).resolve().parents[1]
DB=purchase.SIDE[1]
PREVIOUS='9774a93c1f98204ce8373f467c62f62a3660903b'
N1_HEAD='e3a189b589b1df0651a25092f6ac7ef17218ad28'

BEFORE_OWNER=r"""            if (($GLOBALS['r3_ready']??'')!=='') {
                file_put_contents((string)$GLOBALS['r3_ready'],'post-preview');
                $until=microtime(true)+12;
                while (!is_file((string)$GLOBALS['r3_release']) && microtime(true)<$until)
                    usleep(10000);
                if (!is_file((string)$GLOBALS['r3_release']))
                    throw new RuntimeException('boundary_release_timeout');
            }
"""
AFTER_SUBJECT=BEFORE_OWNER.replace('post-preview','post-final-authorization')

REVOKER=r"""<?php
error_reporting(E_ALL);
$db=new mysqli('localhost','root','',$argv[1],0,$argv[2]);
$db->set_charset('utf8mb4');
$field=$argv[3];$value=$argv[4];$id=(int)$argv[5];
if ($field==='type') {
    $stmt=$db->prepare('UPDATE server_config SET type=? WHERE id=?');
    $stmt->bind_param('si',$value,$id);
} elseif ($field==='active') {
    $new=(int)$value;
    $stmt=$db->prepare('UPDATE server_plans SET active=? WHERE id=?');
    $stmt->bind_param('ii',$new,$id);
} else throw new RuntimeException('unexpected_test_revoke');
if (!$stmt->execute()) throw new RuntimeException('revocation_failed');
$stmt->close();
echo 'REVOKED';
"""

def sql(w,e,q):return checkout.sql(w,e,q)

def staged(w,which,previous=False,baseline=PREVIOUS):
    stage=w/('final-'+which+(
        '-n1-old' if previous and baseline!=PREVIOUS else
        ('-old' if previous else '-new')))
    stages.stage_source(stage,True)
    wallet=stage/'src/Finance/WalletSettlement.php'
    if previous:
        source=subprocess.check_output(['git','show',
               baseline+':src/Finance/WalletSettlement.php'],cwd=ROOT)
        (stage/'src/Finance/PaymentLifecycle.php').write_bytes(
               subprocess.check_output(['git','show',
                   baseline+':src/Finance/PaymentLifecycle.php'],cwd=ROOT))
        (stage/'src/Purchase/Core.php').write_bytes(
               subprocess.check_output(['git','show',
                   baseline+':src/Purchase/Core.php'],cwd=ROOT))
        (stage/'src/Finance/ReceiptHelpers.php').write_bytes(
               subprocess.check_output(['git','show',
                   baseline+':src/Finance/ReceiptHelpers.php'],cwd=ROOT))
    else:source=wallet.read_bytes()
    if which=='policy':
        anchor=b"            achbotPayUxLockDraftOwner($connection,(int)$from_id);"
        marker=BEFORE_OWNER.encode()
    else:
        # Both the previous and corrected central route have completed
        # definitive subject authorization by the time they reach user debit.
        anchor=b"            $uid   = (int)($payInfo['user_id'] ?: $from_id);"
        marker=AFTER_SUBJECT.encode()
    assert source.count(anchor)==1,(which,source.count(anchor))
    wallet.write_bytes(source.replace(anchor,marker+anchor))
    return stage

def make_actor(w,e,actor,tag,typ):
    checkout.fresh_actor(w,e,actor)
    sql(w,e,f'UPDATE users SET wallet=700000,is_agent=1 WHERE userid={actor}')
    hash_='f24'+tag+str(actor)
    payid=checkout.seed_pay(w,e,hash_,actor,typ=typ)
    if typ=='BUY_SUB':
        sql(w,e,f'UPDATE pays SET plan_id=7,server_id=2 WHERE id={payid}')
    return hash_,payid

def run_customer(w,e,stage,driver,actor,hash_,folder,iso='REPEATABLE-READ'):
    spec={'actor':actor,'hash':hash_,'audience':'all','isolation':iso,
          'ready':str(folder/'ready'),'release':str(folder/'release')}
    return subprocess.Popen(r3.action_cmd(w,stage,driver,spec),cwd=w,env=e,
                            stdout=subprocess.PIPE,stderr=subprocess.PIPE)

def wait(path,proc):
    deadline=time.monotonic()+5
    while not path.exists() and time.monotonic()<deadline:
        if proc.poll() is not None:raise AssertionError(('php_ended',proc.communicate()))
        time.sleep(.012)
    assert path.exists(),str(path)

def get_result(proc):
    out,err=proc.communicate(timeout=14)
    assert proc.returncode==0 and not err,(out,err)
    return json.loads(out)

def policy_race(w,e,stage,driver,index,previous,iso,variant='audience'):
    actor=12400+index
    hash_,pid=make_actor(w,e,actor,'policy','INCREASE_WALLET')
    current={'walletState':'off' if variant=='initially_off' else 'on',
             'gatewayAudience':{'wallet':'all'}}
    sql(w,e,"UPDATE setting SET value='"+json.dumps(current)+
        "' WHERE type='BOT_STATES'")
    folder=w/('policy-'+str(index));folder.mkdir()
    p=run_customer(w,e,stage,driver,actor,hash_,folder,iso)
    try:
        wait(folder/'ready',p)
        if variant=='audience':
            current['gatewayAudience']['wallet']='off'
        elif variant=='primary':
            current['walletState']='off'
        else:
            assert variant in ('positive','initially_off')
        sql(w,e,"UPDATE setting SET value='"+json.dumps(current)+
            "' WHERE type='BOT_STATES'")
        (folder/'release').write_text('after-revocation')
        observed=get_result(p)
        effects=1 if previous or variant=='positive' else 0
        status='ok' if effects else 'claimed_provider'
        assert observed['status']==status and observed['effects']==effects,(previous,iso,observed)
        assert sql(w,e,f'SELECT wallet FROM users WHERE userid={actor}')==str(700000-effects*100000)
        assert sql(w,e,f'SELECT state FROM pays WHERE id={pid}')==(
            'paid_with_wallet' if effects else 'pending')
        if variant!='audience':
            return ('n1_wallet_'+variant+
                ('_old_red' if previous else '_new_green')+
                '_'+iso.lower().replace('-','_'))
        return 'f2_wallet_'+('old_red' if previous else 'new_green')+'_'+iso.lower().replace('-','_')
    finally:
        (folder/'release').write_text('done')
        if p.poll() is None:p.kill();p.communicate(timeout=4)

def panel_race(w,e,stage,driver,index,previous,iso,field):
    sql(w,e,"UPDATE server_config SET type='marzban' WHERE id=2")
    sql(w,e,"UPDATE server_plans SET active=1 WHERE id=7")
    sql(w,e,"UPDATE setting SET value='{\"walletState\":\"on\"}' WHERE type='BOT_STATES'")
    actor=12500+index
    hash_,pid=make_actor(w,e,actor,'panel','BUY_SUB')
    folder=w/('panel-'+str(index));folder.mkdir()
    p=run_customer(w,e,stage,driver,actor,hash_,folder,iso)
    update=None
    try:
        wait(folder/'ready',p)
        choice=['type','sanaei',2] if field=='type' else ['active','0',7]
        update=subprocess.Popen(['php','-n','-d','extension=mysqlnd','-d',
                    'extension=mysqli',str(w/'final-revoker.php'),DB,
                    str(w/'mysql.sock'),*map(str,choice)],cwd=w,env=e,
                    stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        # Old source has no locked dependency, new source holds it to COMMIT.
        deadline=time.monotonic()+1.0
        while update.poll() is None and time.monotonic()<deadline:time.sleep(.01)
        if previous:
            assert update.poll()==0,('old_revocation_not_visible',field)
        else:
            assert update.poll() is None,('revocation_escaped_lock',field,iso)
        (folder/'release').write_text('settle')
        observed=get_result(p)
        out,err=update.communicate(timeout=8)
        assert update.returncode==0 and out==b'REVOKED' and not err,(out,err)
        assert observed['status']=='ok' and observed['effects']==1,(field,previous,observed)
        assert sql(w,e,f'SELECT wallet FROM users WHERE userid={actor}')=='600000'
        assert sql(w,e,f'SELECT state FROM pays WHERE id={pid}')=='paid_with_wallet'
        return 'f4_'+field+'_'+('old_red_revocation_precedes_effect' if previous
            else 'new_green_revocation_serialized')+'_'+iso.lower().replace('-','_')
    finally:
        (folder/'release').write_text('done')
        if p.poll() is None:p.kill();p.communicate(timeout=4)
        if update is not None and update.poll() is None:update.kill();update.communicate(timeout=4)

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('--run-disposable',action='store_true',required=True)
    ap.add_argument('--report',type=Path)
    args=ap.parse_args()
    scenarios=[]
    with owned.owned_database() as (w,e):
        # source-real RED/GREEN stages: one for policy, one for late revocation.
        src={(which,old):staged(w,which,old)
             for which in ('policy','panel') for old in (False,True)}
        n1_old=staged(w,'policy',True,N1_HEAD)
        driver=w/'f24-wallet-driver.php';driver.write_text(r3.PHP)
        revoker=w/'final-revoker.php';revoker.write_text(REVOKER)
        purchase.init(w,e,'http://127.0.0.1:9')
        sql(w,e,(ROOT/'migrations/post-v9/003_payment_attempts.sql').read_text())
        sql(w,e,"INSERT INTO setting (type,value) VALUES ('BOT_STATES','{\"walletState\":\"on\",\"cartToCartState\":\"on\",\"giftCardState\":\"on\",\"manualUsdtState\":\"on\",\"nowPaymentWallet\":\"on\",\"nowPaymentOther\":\"on\",\"zarinpal\":\"on\",\"nextpay\":\"on\"}')")
        index=0
        for iso in ('READ-COMMITTED','REPEATABLE-READ'):
            if iso=='READ-COMMITTED':
                scenarios.append(policy_race(w,e,src[('policy',True)],driver,index,True,iso));index+=1
            scenarios.append(policy_race(w,e,src[('policy',False)],driver,index,False,iso));index+=1
        for variant in ('primary','initially_off'):
            scenarios.append(policy_race(w,e,n1_old,driver,index,True,
                                         'READ-COMMITTED',variant));index+=1
            for iso in ('READ-COMMITTED','REPEATABLE-READ'):
                scenarios.append(policy_race(w,e,src[('policy',False)],driver,
                                             index,False,iso,variant));index+=1
        scenarios.append(policy_race(w,e,src[('policy',False)],driver,index,
                                     False,'READ-COMMITTED','positive'));index+=1
        for field in ('type','active'):
            for iso in ('READ-COMMITTED','REPEATABLE-READ'):
                if iso=='READ-COMMITTED':
                    scenarios.append(panel_race(w,e,src[('panel',True)],driver,index,True,iso,field));index+=1
                scenarios.append(panel_race(w,e,src[('panel',False)],driver,index,False,iso,field));index+=1
    output={'complete':True,'cases':len(scenarios),'scenarios':scenarios,
        'old_source_red':True,'current_source_green':True,'mysqli_sessions':True,
        'owned_mariadb_only':True,'external_provider_requests':0,'production_mutations':0}
    if args.report:args.report.write_text(json.dumps(output,indent=2)+'\n')
    print(json.dumps(output))
if __name__=='__main__':main()
