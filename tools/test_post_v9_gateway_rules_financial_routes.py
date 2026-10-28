#!/usr/bin/env python3
"""Issue #51 R1. Real WalletSettlement and GatewayInitiation source-real races.
Test-only pauses in disposable source copies; no network/tenant effects.
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
import test_post_v9_wallet_authorization_freshness as wallet
import test_post_v9_checkout_serialization as checkout
import test_post_v9_finance_routes as finance

ROOT=Path(__file__).resolve().parents[1]
DB=purchase.SIDE[1]
FLAGS={'walletState':'on','nowPaymentOther':'on','nowPaymentWallet':'on',
       'nextpay':'on','zarinpal':'on','cartToCartState':'on',
       'giftCardState':'on','manualUsdtState':'on'}
def sql(w,e,q): return checkout.sql(w,e,q)
def rule(on):
    return {'category':'non_agents','enabled':on,'registration_age_days':0,
            'metrics':{k:{'count':0,'days':0,'direction':'older'}
                       for k in ('buy','renew','volume','time','topup')}}
def policy(method,on=True,other=False):
    methods={method:[rule(on)]}
    if other:methods['wallet' if method!='wallet' else 'nextpay']=[rule(False)]
    return {**FLAGS,'gatewayRules':{'version':1,'methods':methods}}
def put(w,e,p):
    raw=json.dumps(p,ensure_ascii=False,separators=(',',':'))
    sql(w,e,"UPDATE setting SET value='"+raw.replace("'","''")+"' WHERE type='BOT_STATES'")
    assert json.loads(sql(w,e,"SELECT value FROM setting WHERE type='BOT_STATES'"))==p
def wait_ready(path,p):
    until=time.monotonic()+8
    while not path.exists() and time.monotonic()<until:
        if p.poll() is not None:
            raise AssertionError(('route_exited_before_post_preview_barrier',p.communicate()))
        time.sleep(.015)
    assert path.exists(),'source-real post-preview boundary not reached'
def read(p):
    out,err=p.communicate(timeout=20)
    assert p.returncode==0 and not err,(p.returncode,out[-1200:],err[-1200:])
    x=json.loads(out)
    assert not x.get('error') and not x.get('fatal'),x
    return x
def spawn(w,e,args):
    return subprocess.Popen(args,cwd=w,env=e,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
def user(w,e,id):
    checkout.fresh_actor(w,e,id)
    sql(w,e,f'UPDATE users SET wallet=700000,is_agent=0 WHERE userid={id}')
def wallet_stage(w):
    stage,driver=wallet.stage_source(w)
    php=wallet.PHP
    old="$botState=['gatewayAudience'=>['wallet'=>$spec['audience']]];"
    assert php.count(old)==1
    php=php.replace(old,'''$botState=json_decode(
        (string)$connection->query("SELECT value FROM setting WHERE type='BOT_STATES'")->fetch_assoc()['value'],
        true,512,JSON_THROW_ON_ERROR);''',1)
    old="$payment=processWalletPayment($connection,(string)$spec['hash'],"
    assert php.count(old)==1
    php=php.replace(old,'''require_once $root.'/src/Finance/PaymentLifecycle.php';
$GLOBALS['r1_initial_allowed']=
        achbotPayUxAudienceAllowed($connection,'wallet',$from_id,false,'BUY_SUB');
$payment=processWalletPayment($connection,(string)$spec['hash'],''',1)
    old="'remote_posts'=>$GLOBALS['r3_remote_posts'],"
    assert php.count(old)==1
    php=php.replace(old,old+"\n                  'initial_allowed'=>$GLOBALS['r1_initial_allowed'],",1)
    driver.write_text(php)
    return stage,driver
def wallet_case(w,e,stage,driver,n,iso,revoke):
    actor=12500+n;user(w,e,actor)
    h='r1-new-rule-wallet-'+str(n)
    pid=checkout.seed_pay(w,e,h,actor,typ='BUY_SUB')
    sql(w,e,f'UPDATE pays SET plan_id=7,server_id=2 WHERE id={pid}')
    put(w,e,policy('wallet'))
    balance=sql(w,e,f'SELECT wallet FROM users WHERE userid={actor}')
    folder=w/('r1-wallet-'+str(n));folder.mkdir()
    spec={'hash':h,'actor':actor,'audience':'non_agents','isolation':iso,
          'ready':str(folder/'ready'),'release':str(folder/'release')}
    p=spawn(w,e,wallet.action_cmd(w,stage,driver,spec))
    try:
        wait_ready(folder/'ready',p)
        put(w,e,policy('wallet',not revoke,not revoke))
        (folder/'release').write_text('committed')
        result=read(p)
    finally:
        (folder/'release').write_text('finish')
        if p.poll() is None:p.kill();p.communicate(timeout=5)
    assert result['initial_allowed'] is True and result['isolation']==iso,result
    assert result['status']==('claimed_provider' if revoke else 'ok'),result
    assert result['effects']==int(not revoke),result
    assert result['remote_posts']==int(not revoke),result
    assert sql(w,e,f'SELECT state FROM pays WHERE id={pid}')==('pending' if revoke else 'paid_with_wallet')
    assert sql(w,e,f'SELECT wallet FROM users WHERE userid={actor}')==(
        balance if revoke else str(int(balance)-100000))
    assert sql(w,e,f'SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id={pid}')=='0'
    later=read(spawn(w,e,wallet.action_cmd(w,stage,driver,{
        'hash':h,'actor':actor,'audience':'non_agents','isolation':iso})))
    assert later['status']==('claimed_provider' if revoke else 'already_paid'),later
    assert later['effects']==0 and later['remote_posts']==0,later
    assert sql(w,e,f'SELECT wallet FROM users WHERE userid={actor}')==(
        balance if revoke else str(int(balance)-100000))
    return 'r1_wallet_'+iso.replace('-','_').lower()+('_revoked_no_debit' if revoke else '_allowed_one_debit_no_replay')

PAUSE=r"""
        if (($GLOBALS['r1_ready']??'')!=='') {
            file_put_contents($GLOBALS['r1_ready'],'post-preview');
            $deadline=microtime(true)+15;
            while (!is_file($GLOBALS['r1_release']) && microtime(true)<$deadline)
                usleep(12000);
            if (!is_file($GLOBALS['r1_release']))
                throw new RuntimeException('r1_pause_expired');
        }
"""
def hosted_stage(w):
    stage=w/'r1-hosted-stage';stages.stage_source(stage,True)
    p=stage/'pay/checkout.php';src=p.read_text()
    anchor='        achbotPayUxLockDraftOwner($db,$ownerId);'
    assert src.count(anchor)==1
    p.write_text(src.replace(anchor,PAUSE+anchor,1))
    php=finance.PHP_DRIVER
    needle='$spec = json_decode($argv[4], true, 512, JSON_THROW_ON_ERROR);'
    assert php.count(needle)==1
    php=php.replace(needle,needle+"""
$GLOBALS['r1_ready']=(string)($spec['ready']??'');
$GLOBALS['r1_release']=(string)($spec['release']??'');""",1)
    needle="$connection->set_charset('utf8mb4');"
    assert php.count(needle)==1
    php=php.replace(needle,needle+"""
    if (($spec['isolation']??'')==='READ-COMMITTED')
        $connection->query('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');""",1)
    driver=w/'r1-hosted-driver.php';driver.write_text(php)
    return stage,driver
def hosted_cmd(w,stage,driver,spec):
    return ['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
            '-d','extension=curl','-d','allow_url_fopen=0',
            '-d','disable_functions=curl_init,curl_setopt,curl_setopt_array,curl_exec,curl_close,curl_error,curl_getinfo,exec,shell_exec,system,passthru,popen,proc_open,mail',
            str(driver),str(stage),DB,str(w/'mysql.sock'),json.dumps(spec)]
def hosted_case(w,e,stage,driver,n,iso,revoke):
    actor=12600+n;user(w,e,actor)
    h='r1-new-rule-hosted-'+str(n)
    pid=checkout.seed_pay(w,e,h,actor,typ='INCREASE_WALLET')
    put(w,e,policy('nextpay'))
    balance=sql(w,e,f'SELECT wallet FROM users WHERE userid={actor}')
    folder=w/('r1-hosted-'+str(n));folder.mkdir()
    spec={'route':'initiate','get':{'nextpay':'','hash_id':h},
          'states':policy('nextpay'),'actor':actor,'isolation':iso,
          'ready':str(folder/'ready'),'release':str(folder/'release')}
    p=spawn(w,e,hosted_cmd(w,stage,driver,spec))
    try:
        wait_ready(folder/'ready',p)
        put(w,e,policy('nextpay',not revoke,not revoke))
        (folder/'release').write_text('committed')
        result=read(p)
    finally:
        (folder/'release').write_text('finish')
        if p.poll() is None:p.kill();p.communicate(timeout=5)
    assert sql(w,e,f'SELECT wallet FROM users WHERE userid={actor}')==balance
    assert len(result['network_calls'])==int(not revoke),result
    assert sql(w,e,f'SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id={pid}')==str(int(not revoke))
    if revoke:
        assert sql(w,e,f'SELECT COALESCE(payid,"NULL") FROM pays WHERE id={pid}')=='NULL'
        assert sql(w,e,f'SELECT state FROM pays WHERE id={pid}')=='pending'
    else:
        assert sql(w,e,f'SELECT payid FROM pays WHERE id={pid}').startswith('next-')
    spec.pop('ready');spec.pop('release')
    spec['states']=policy('nextpay',not revoke,not revoke)
    again=read(spawn(w,e,hosted_cmd(w,stage,driver,spec)))
    assert not again['network_calls'],again
    assert sql(w,e,f'SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id={pid}')==str(int(not revoke))
    assert sql(w,e,f'SELECT wallet FROM users WHERE userid={actor}')==balance
    return 'r1_hosted_'+iso.replace('-','_').lower()+('_revoked_no_attempt' if revoke else '_eligible_one_invoice_no_replay')
def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('--run-disposable',action='store_true',required=True)
    ap.add_argument('--report',type=Path)
    args=ap.parse_args();checks=[]
    with owned.owned_database() as (w,e):
        purchase.init(w,e,'http://127.0.0.1:9')
        assert len(sql(w,e,'SHOW TABLES').splitlines())==23
        sql(w,e,"INSERT INTO setting(type,value) VALUES ('BOT_STATES','{\"walletState\":\"on\",\"nextpay\":\"on\"}')")
        private=json.dumps({'nextpay':'SYNTHETIC-LOCAL-ONLY',
                            'zarinpal':'SYNTHETIC-LOCAL-ONLY',
                            'nowpayment':'SYNTHETIC-LOCAL-ONLY',
                            'nowpayment_ipn_secret':'LOCAL-IPN-SECRET'})
        sql(w,e,"INSERT INTO setting(type,value) VALUES ('PAYMENT_KEYS','"+
            private.replace("'","''")+"')")
        for m in ('003_payment_attempts','006_payment_message_refs',
                  '001_marzban_panel_group_id','007_service_effects'):
            sql(w,e,(ROOT/'migrations/post-v9'/(m+'.sql')).read_text())
        assert len(sql(w,e,'SHOW TABLES').splitlines())==27
        wallet_src,wallet_driver=wallet_stage(w)
        hosted_src,hosted_driver=hosted_stage(w)
        for i,(iso,revoke) in enumerate((
             ('REPEATABLE-READ',True),('READ-COMMITTED',True),
             ('REPEATABLE-READ',False),('READ-COMMITTED',False))):
            checks.append(wallet_case(w,e,wallet_src,wallet_driver,i,iso,revoke))
            checks.append(hosted_case(w,e,hosted_src,hosted_driver,i,iso,revoke))
    out={'complete':True,'cases':len(checks),'scenarios':checks,
         'source_real_wallet_and_hosted_routes':True,
         'two_independent_mysql_clients':True,'original_23_table_fixture':True,
         'synthetic_provider_issuer_only':True,
         'real_provider_calls':0,'customer_payment_or_deployment_effects':0}
    if args.report:args.report.write_text(json.dumps(out,indent=2)+'\n')
    print(json.dumps(out))
if __name__=='__main__':main()
