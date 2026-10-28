#!/usr/bin/env python3
"""PR #60 F1: real three-route customer wallet/draft race on disposable MariaDB.

All routes execute real staged PHP/MySQLi. Only the stage contains test hooks;
the production sources contain no artificial pause or mock network calls.
The exact reviewed route version is RED under READ COMMITTED, while corrected
owner-first routes block superseded spending under both READ COMMITTED and RR.
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
import test_post_v9_manual_payments as manual

ROOT=Path(__file__).resolve().parents[1]
DB=purchase.SIDE[1]
HEAD='9774a93c1f98204ce8373f467c62f62a3660903b'
ROUTES=(
    ('renewal','payRenewWithWallet','RENEW_ACCOUNT','src/Finance/Routes/RenewalCheckout.php'),
    ('day','payIncreaseDayWithWallet','INCREASE_DAY','src/Finance/Routes/Extensions.php'),
    ('volume','payIncreaseWithWallet','INCREASE_VOLUME','src/Finance/Routes/Extensions.php'),
)
HOOK=r"""        if (($GLOBALS['f1_pause']??false)===true) {
            file_put_contents((string)$GLOBALS['f1_ready'],'paused');
            $until=microtime(true)+12;
            while (!is_file((string)$GLOBALS['f1_release']) && microtime(true)<$until)
                usleep(10000);
            if (!is_file((string)$GLOBALS['f1_release']))
                throw new RuntimeException('f1_test_release_timeout');
        }
"""

AFTER_OWNER=HOOK.replace("$GLOBALS['f1_pause']??false", "$GLOBALS['f1_after_owner']??false").replace(
    "$GLOBALS['f1_ready']", "$GLOBALS['f1_after_ready']").replace(
    "$GLOBALS['f1_release']", "$GLOBALS['f1_after_release']")

WRITER=r"""<?php
error_reporting(E_ALL);
$root=$argv[1];$dbname=$argv[2];$socket=$argv[3];
$actor=(int)$argv[4];$hash=$argv[5];$typ=$argv[6];$oid=(int)$argv[7];
$db=new mysqli('localhost','root','',$dbname,0,$socket);
$db->set_charset('utf8mb4');
require $root.'/src/Finance/PaymentLifecycle.php';
$stmt=$db->prepare("INSERT INTO pays(hash_id,user_id,type,plan_id,server_id,
    order_id,volume,day,price,request_date,state)
    VALUES (?,?,?,7,2,?,0,0,100000,UNIX_TIMESTAMP(),'pending')");
$stmt->bind_param('sisi',$hash,$actor,$typ,$oid);
$id=achbotPayUxInsertDraft($db,$actor,$stmt);
$stmt->close();
if ($id<=0) throw new RuntimeException('real_draft_write_failed');
echo json_encode(['new_id'=>$id],JSON_THROW_ON_ERROR);
"""

def sql(w,e,q):return checkout.sql(w,e,q)

def stage_source(w,previous):
    stage=w/('f1-old-source' if previous else 'f1-current-source')
    stages.stage_source(stage,True)
    for path in set(r[3] for r in ROUTES):
        p=stage/path
        source=(subprocess.check_output(['git','show',HEAD+':'+path],cwd=ROOT)
                if previous else p.read_bytes())
        marker=HOOK.encode()
        for _,callback,_,where in ROUTES:
            if where!=path:continue
            begin=(b"if(preg_match('/"+callback.encode()+b"(.*)/', $data, $match)){"
                   if previous else
                   b"if(is_string($data) && strlen($data) <= 64 && preg_match('/\\A"+
                   callback.encode()+b"([A-Za-z0-9_-]{1,50})\\z/D', $data, $match)){")
            assert source.count(begin)==1,(where,callback,source.count(begin))
            start=source.index(begin)
            anchor=(b"        if (!achbotSupportsPaymentSubject($connection, "+
                (b"$payInfo" if callback=='payRenewWithWallet' else b"$payParam")+b")) {"
                if previous else b"        achbotPayUxLockDraftOwner($connection,(int)$from_id);")
            pos=source.index(anchor,start)
            assert pos-start<2500,(where,callback,pos-start)
            source=source[:pos]+marker+source[pos:]
            if not previous:
                end=source.index(anchor,pos+len(marker))+len(anchor)
                source=source[:end]+AFTER_OWNER.encode()+source[end:]
        p.write_bytes(source)
    return stage

def driver_source():
    driver=manual.PHP_ROUTE
    # The shared manual fixture now exposes a safe diagnostic helper itself.
    # Keep exactly that one definition; duplicating it in this derived race
    # driver fatally errors before the source-real owner-lock interleaving.
    assert driver.count("function achbotFinanceDiagnosticReason(")==1
    old="function achbotSupportsPaymentSubject(...$args){return true;}"
    assert driver.count(old)==1
    driver=driver.replace(old,"require_once $root.'/src/Purchase/Core.php';")
    old="""function getMarzbanUser($serverId,$remark){
    $GLOBALS['events'][]=['name'=>'remote_get','username'=>$remark];
    $found=$GLOBALS['remote_accounts'][$remark]??null;
    return $found ? (object)$found : null;
}"""
    assert driver.count(old)==1
    driver=driver.replace(old,"""function getMarzbanUser($serverId,$remark){
    $GLOBALS['events'][]=['name'=>'remote_get','username'=>$remark];
    $found=$GLOBALS['remote_accounts'][$remark]??null;
    if($found)return(object)$found;
    $existing=$GLOBALS['spec']['existing_native']??null;
    if(!is_array($existing) || (int)$serverId!==(int)$existing['server_id'] ||
       $remark!==$existing['username'])return null;
    return(object)$existing;
}""")
    old="$GLOBALS['spec']=$spec;"
    assert driver.count(old)==1
    driver=driver.replace(old,"""$mainValues=['renew_account_request_message'=>
    'synthetic TYPE USER-ID USERNAME NAME PRICE REMARK VOLUME DAYS',
    'config_not_found'=>'not_found'];
$username='synthetic';$first_name='synthetic';$botState['rewardChannel']='';
$GLOBALS['f1_pause']=$spec['f1_pause']??false;
$GLOBALS['f1_ready']=$spec['f1_ready']??'';
$GLOBALS['f1_release']=$spec['f1_release']??'';
$GLOBALS['f1_after_owner']=$spec['f1_after_owner']??false;
$GLOBALS['f1_after_ready']=$spec['f1_after_ready']??'';
$GLOBALS['f1_after_release']=$spec['f1_after_release']??'';
$GLOBALS['spec']=$spec;""")
    old="$connection->set_charset('utf8mb4');"
    assert driver.count(old)==1
    driver=driver.replace(old,old+"""
if (($spec['isolation']??'')==='READ-COMMITTED')
    $connection->query('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
""")
    return driver

def existing_native(n):
    return dict(server_id=2,username='f1serv'+str(n),expire=2000000000,
                data_limit=10*1073741824,used_traffic=1073741824,status='active')

def customer_action(w,e,stage,driver,route,hash_,actor,iso,dir_,n,include_native=True):
    spec=dict(data=route[1]+hash_,step='none',actor=actor,
        route='renewal' if route[0]=='renewal' else 'extension',
        remote_mode='success',isolation=iso,
        existing_native=existing_native(n) if include_native else None,
        f1_pause=True,f1_ready=str(dir_/'action-ready'),
        f1_release=str(dir_/'action-release'))
    return subprocess.Popen(['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
         '-d','display_errors=0','-d','log_errors=1','-d','error_log=/dev/stderr',
         '-d','allow_url_fopen=0',
         '-d','disable_functions=exec,shell_exec,system,passthru,popen,proc_open,mail,dl',
         str(driver),str(stage),str(w/'mysql.sock'),DB,json.dumps(spec)],
         cwd=w,env=e,stdout=subprocess.PIPE,stderr=subprocess.PIPE)

def wait(path,proc,timeout=5):
    until=time.monotonic()+timeout
    while not path.exists() and time.monotonic()<until:
        if proc.poll() is not None:raise AssertionError(('worker_exited',proc.communicate()))
        time.sleep(.01)
    assert path.exists(),str(path)

def setup_case(w,e,route,actor,n):
    checkout.fresh_actor(w,e,actor)
    sql(w,e,f'UPDATE users SET wallet=700000 WHERE userid={actor}')
    name='f1serv'+str(n);uuid='f1uuid'+str(n)
    sql(w,e,("INSERT INTO orders_list (userid,token,transid,fileid,server_id,"
             "inbound_id,remark,uuid,protocol,expire_date,link,amount,status,date)"
             f" VALUES({actor},'synthetic-token','',7,2,0,'{name}','{uuid}',"
             "'vless',2000000000,'[]',100,1,'synthetic')"))
    oid=int(sql(w,e,f"SELECT id FROM orders_list WHERE remark='{name}'"))
    if route[0]=='renewal':typ='RENEW_ACCOUNT'
    elif route[0]=='day':typ='INCREASE_DAY_'+str(oid)+'_901'
    else:typ='INCREASE_VOLUME_'+str(oid)+'_902'
    # These races start from valid NEW quotes; retain the exact existing
    # debit/provider-count expectations after a competing invoice rewrite.
    volume=8 if route[0]=='volume' else 0
    days=7 if route[0]=='day' else 0
    hash_='f1route'+str(n)
    sql(w,e,("INSERT INTO pays(hash_id,user_id,type,plan_id,server_id,order_id,"
             "volume,day,price,request_date,state)"
             f" VALUES('{hash_}',{actor},'{typ}',7,2,{oid},{volume},{days},100000,"
             "UNIX_TIMESTAMP(),'pending')"))
    return hash_,typ,oid

def run_case(w,e,stage,driver,writer,route,n,previous=False,iso='READ-COMMITTED'):
    actor=11800+n
    hash_,typ,oid=setup_case(w,e,route,actor,n)
    dir_=w/('f1-case-'+str(n));dir_.mkdir()
    action=customer_action(w,e,stage,driver,route,hash_,actor,iso,dir_,n)
    try:
        wait(dir_/'action-ready',action)
        before=sql(w,e,f'SELECT wallet FROM users WHERE userid={actor}')
        changed=typ if route[0]=='renewal' else (
            'INCREASE_'+('DAY' if route[0]=='day' else 'VOLUME')+'_999')
        # Same owner/family/target, with a later committed pays.id.
        result=subprocess.run(['php','-n','-d','extension=mysqlnd',
           '-d','extension=mysqli',str(writer),str(stage),DB,str(w/'mysql.sock'),
           str(actor),'f1new'+str(n),changed,str(oid)],
           cwd=w,env=e,capture_output=True,timeout=9)
        assert result.returncode==0 and not result.stderr,result.stderr
        (dir_/'action-release').write_text('resume')
        out,err=action.communicate(timeout=12)
        assert action.returncode==0 and not err,(out[-600:],err[-1300:])
        response=json.loads(out)
        events=response['events']
        assert response['fatal'] is None,response['fatal']
        effects=sum(x['name']=='remote_edit' for x in events)
        expected=1 if previous else 0
        assert effects==expected,(route[0],iso,effects,events)
        state=sql(w,e,f"SELECT state FROM pays WHERE hash_id='{hash_}'")
        wallet=sql(w,e,f'SELECT wallet FROM users WHERE userid={actor}')
        assert state==('paid_with_wallet' if previous else 'pending'),(route,state)
        assert int(wallet)==int(before)-(100000 if previous else 0),(route,wallet)
        return 'f1_'+route[0]+'_'+iso.lower().replace('-','_')+('_old_red' if previous else '_new_green')
    finally:
        (dir_/'action-release').write_text('done')
        if action.poll() is None:action.kill();action.communicate(timeout=4)

def run_a_first(w,e,stage,driver,writer,route,n,iso):
    actor=11800+n
    hash_,typ,oid=setup_case(w,e,route,actor,n)
    dir_=w/('f1-case-'+str(n));dir_.mkdir()
    spec=dict(data=route[1]+hash_,step='none',actor=actor,
        route='renewal' if route[0]=='renewal' else 'extension',
        remote_mode='success',isolation=iso,existing_native=existing_native(n),
        f1_after_owner=True, f1_after_ready=str(dir_/'a-ready'),
        f1_after_release=str(dir_/'a-release'))
    action=subprocess.Popen(['php','-n','-d','extension=mysqlnd','-d',
        'extension=mysqli','-d','display_errors=0',
        '-d','allow_url_fopen=0',
        '-d','disable_functions=exec,shell_exec,system,passthru,popen,proc_open,mail,dl',
        str(driver),str(stage),str(w/'mysql.sock'),DB,json.dumps(spec)],
        cwd=w,env=e,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    writer_process=None
    try:
        wait(dir_/'a-ready',action)
        changed=typ if route[0]=='renewal' else (
            'INCREASE_'+('DAY' if route[0]=='day' else 'VOLUME')+'_999')
        writer_process=subprocess.Popen(['php','-n','-d','extension=mysqlnd',
            '-d','extension=mysqli',str(writer),str(stage),DB,str(w/'mysql.sock'),
            str(actor),'f1new'+str(n),changed,str(oid)],
            cwd=w,env=e,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        # The new draft writer must wait at the *same owner lock*, not
        # allocate its pays ID while A is inside the authorized transaction.
        time.sleep(.4)
        assert writer_process.poll() is None,('draft_did_not_serialize',route,iso)
        assert sql(w,e,f"SELECT COUNT(*) FROM pays WHERE hash_id='f1new{n}'")=='0'
        (dir_/'a-release').write_text('allow-financial-effect')
        out,err=action.communicate(timeout=12)
        assert action.returncode==0 and not err,(out,err)
        response=json.loads(out)
        assert response['fatal'] is None
        assert sum(x['name']=='remote_edit' for x in response['events'])==1,response
        wout,werr=writer_process.communicate(timeout=8)
        assert writer_process.returncode==0 and not werr,(wout,werr)
        assert int(json.loads(wout)['new_id'])>0
        assert sql(w,e,f"SELECT state FROM pays WHERE hash_id='{hash_}'")=='paid_with_wallet'
        assert sql(w,e,f"SELECT wallet FROM users WHERE userid={actor}")=='600000'
        return 'f1_'+route[0]+'_'+iso.lower().replace('-','_')+'_wallet_first_draft_waits'
    finally:
        (dir_/'a-release').write_text('done')
        if action.poll() is None:action.kill();action.communicate(timeout=4)
        if writer_process and writer_process.poll() is None:
            writer_process.kill();writer_process.communicate(timeout=4)

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('--run-disposable',action='store_true',required=True)
    ap.add_argument('--report',type=Path)
    args=ap.parse_args()
    scenarios=[]
    with owned.owned_database() as (w,e):
        current=stage_source(w,False)
        old=stage_source(w,True)
        purchase.init(w,e,'http://127.0.0.1:9')
        sql(w,e,(ROOT/'migrations/post-v9/003_payment_attempts.sql').read_text())
        sql(w,e,"INSERT INTO setting (type,value) VALUES ('BOT_STATES','{\"walletState\":\"on\",\"cartToCartState\":\"on\",\"giftCardState\":\"on\",\"manualUsdtState\":\"on\",\"nowPaymentWallet\":\"on\",\"nowPaymentOther\":\"on\",\"zarinpal\":\"on\",\"nextpay\":\"on\"}')")
        sql(w,e,'INSERT INTO increase_day (id,volume,price) VALUES (901,7,100)')
        sql(w,e,'INSERT INTO increase_plan (id,volume,price) VALUES (902,8,100)')
        writer=w/'f1-draft-writer.php';writer.write_text(WRITER)
        driver=w/'f1-action-driver.php';driver.write_text(driver_source())
        n=0
        for route in ROUTES:
            scenarios.append(run_case(w,e,old,driver,writer,route,n,True))
            n+=1
            for iso in ('READ-COMMITTED','REPEATABLE-READ'):
                scenarios.append(run_case(w,e,current,driver,writer,route,n,False,iso))
                n+=1
                scenarios.append(run_a_first(w,e,current,driver,writer,route,n,iso))
                n+=1
        route=ROUTES[2];n=99;actor=11800+n
        hash_,typ,oid=setup_case(w,e,route,actor,n)
        dir_=w/'f1-unknown-native';dir_.mkdir()
        (dir_/'action-release').write_text('no-pause')
        before=purchase.snapshot(w,e,DB)
        action=customer_action(w,e,current,driver,route,hash_,actor,'READ-COMMITTED',dir_,n,False)
        out,err=action.communicate(timeout=15)
        assert action.returncode==0 and not err,(out,err)
        response=json.loads(out)
        assert response['fatal'] is None and any(v['name']=='remote_get'for v in response['events']),response
        assert not any(v['name']=='remote_edit'for v in response['events']),response
        assert purchase.snapshot(w,e,DB)==before
        scenarios.append('f1_unknown_native_refuses_before_wallet_claim_or_effect')
    result={'complete':True,'cases':len(scenarios),'scenarios':scenarios,
            'reviewed_source_red':True,'current_source_green':True,
            'real_php_mysqli':True,'owned_mariadb_only':True,'live_effects':0,
            'explicit_existing_native_baseline':True,'unknown_native_refuses_preclaim':True}
    if args.report:args.report.write_text(json.dumps(result,indent=2)+'\n')
    print(json.dumps(result))
if __name__=='__main__':main()
