#!/usr/bin/env python3
"""PR #60 lock-order proof: no BOT_STATES/plan inversion between two customers.

The RED path uses an explicit plan-before-policy wallet mutant together with
the exact e3a189b exclusive BOT_STATES reader. This is NOT an unmodified
previous HEAD. InnoDB detects their inverse plan/policy lock ordering.
Corrected source uses compatible shared PK policy locks for different
customers and serializes only on genuinely shared plan dependencies. All
effects are isolated synthetic MariaDB, mocked provider and staged PHP.
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
import test_post_v9_wallet_authorization_freshness as r3

ROOT=Path(__file__).resolve().parents[1]
DB=purchase.SIDE[1]

WAIT=r"""        file_put_contents((string)$GLOBALS['cross_ready'],'holding-lock');
        $limit=microtime(true)+12;
        while (!is_file((string)$GLOBALS['cross_release']) && microtime(true)<$limit)
            usleep(10000);
        if (!is_file((string)$GLOBALS['cross_release']))
            throw new RuntimeException('cross_lock_wait_timeout');
"""
HOSTED_INJECT=r"""            (function () {
                file_put_contents((string)$GLOBALS['cross_ready'],'after-policy-before-plan');
                $limit=microtime(true)+12;
                while (!is_file((string)$GLOBALS['cross_release']) && microtime(true)<$limit)
                    usleep(10000);
                if (!is_file((string)$GLOBALS['cross_release']))
                    throw new RuntimeException('cross_hosted_wait_timeout');
                return false;
            })() ||
"""

def sql(w,e,q):return checkout.sql(w,e,q)

def stage(w,mutant):
    folder=w/('cross-mutant' if mutant else 'cross-current')
    stages.stage_source(folder,True)
    if mutant:
        # Reproduce a deliberately inverted plan/policy order using the
        # e3a189b exclusive policy scanner, not the current shared PK read.
        (folder/'src/Finance/PaymentLifecycle.php').write_bytes(
            subprocess.check_output(['git','show',
                'e3a189b589b1df0651a25092f6ac7ef17218ad28:src/Finance/PaymentLifecycle.php'],cwd=ROOT))
    a=folder/'src/Finance/WalletSettlement.php'
    src=a.read_bytes()
    if mutant:
        start=src.index(b'            // Reject retired or unknown panel plans before marking')
        end=src.index(b"            $uid   = (int)($payInfo['user_id'] ?: $from_id);",start)
        block=src[start:end]
        assert b"achbotSupportsPaymentSubject($connection, $payInfo, true)" in block
        src=src[:start]+src[end:]
        at=src.index(b"            $price = (int)($payInfo['price'] ?? 0);")
        src=src[:at]+block+src[at:]
    # Current holds policy + plan at this marker. Mutant has completed
    # ONLY the plan check before starting the later cached-policy guard.
    anchor=(b"            $price = (int)($payInfo['price'] ?? 0);"
        if mutant else b"            $uid   = (int)($payInfo['user_id'] ?: $from_id);")
    assert src.count(anchor)==1
    src=src.replace(anchor,WAIT.encode()+anchor)
    a.write_bytes(src)
    b=folder/'src/Finance/GatewayInitiation.php'
    data=b.read_bytes()
    anchor=b"            !achbotSupportsPaymentSubject($db,$row,true) ||"
    assert data.count(anchor)==1
    data=data.replace(anchor,HOSTED_INJECT.encode()+anchor)
    # Stage-only probes allow diagnosing a CI database-version-specific
    # block before the policy/plan boundary, without touching shipping PHP.
    probes=(
        (b"        achbotPayUxLockDraftOwner($db,$ownerId);",
         b"        file_put_contents($GLOBALS['cross_dir'].'/pre-owner','ready');\n"),
        (b'        $stmt = $db->prepare("SELECT * FROM pays WHERE id=? FOR UPDATE");',
         b"        file_put_contents($GLOBALS['cross_dir'].'/post-owner','ready');\n"),
        (b"            (!achbotPayUxLockedCheckoutUsable($db,$row)) ||",
         b"            (file_put_contents($GLOBALS['cross_dir'].'/post-pay','ready') && false) ||\n"),
        (b"            !achbotPayUxAudienceAllowed($db,$provider,$ownerId,true,(string)$row['type']) ||",
         b"            (file_put_contents($GLOBALS['cross_dir'].'/post-successor','ready') && false) ||\n"),
    )
    for probe,marker in probes:
        assert data.count(probe)==1,(probe,data.count(probe))
        data=data.replace(probe,marker+probe)
    b.write_bytes(data)
    return folder

def drivers(w):
    wallet=r3.PHP
    a="$spec=json_decode($argv[4],true,512,JSON_THROW_ON_ERROR);"
    assert wallet.count(a)==1
    wallet=wallet.replace(a,a+"""
$GLOBALS['cross_ready']=$spec['cross_ready']??'';
$GLOBALS['cross_release']=$spec['cross_release']??'';
""")
    a="$connection->set_charset('utf8mb4');"
    assert wallet.count(a)==1
    wallet=wallet.replace(a,a+"""
$connection->query('SET SESSION innodb_lock_wait_timeout=5');
""")
    wdriver=w/'cross-wallet.php';wdriver.write_text(wallet)
    hosted=finance.PHP_DRIVER
    hosted=hosted.replace("set_error_handler(function($level,$message,$file){",
        "set_error_handler(function($level,$message,$file,$line){")
    hosted=hosted.replace("throw new ErrorException($message, 0, $level);",
        "throw new ErrorException($message, 0, $level,$file,$line);")
    hosted=hosted.replace(
        "$GLOBALS['error'] = get_class($e) . ': ' . $e->getMessage();",
        "$GLOBALS['error'] = get_class($e) . ': ' . $e->getMessage() . "
        "' @ ' . $e->getFile() . ':' . $e->getLine();")
    a="$GLOBALS['events'] = [];"
    assert hosted.count(a)==1
    hosted=hosted.replace(a,a+"""
$GLOBALS['cross_ready']=$spec['cross_ready']??'';
$GLOBALS['cross_release']=$spec['cross_release']??'';
$GLOBALS['cross_dir']=$spec['cross_dir']??'';
""")
    a="$connection->set_charset('utf8mb4');"
    assert hosted.count(a)==1
    hosted=hosted.replace(a,a+"""
$connection->query('SET SESSION innodb_lock_wait_timeout=5');
""")
    hdriver=w/'cross-hosted.php';hdriver.write_text(hosted)
    return wdriver,hdriver

def wait(path,proc,seconds=5):
    deadline=time.monotonic()+seconds
    while not path.exists() and time.monotonic()<deadline:
        if proc.poll() is not None:raise AssertionError(('worker_exited',proc.communicate()))
        time.sleep(.01)
    assert path.exists(),str(path)

def diagnostic(w,e,sub,worker):
    observations=[]
    for label,query in (
        ('engine','SELECT VERSION()'),
        ('transactions','SELECT trx_mysql_thread_id,trx_state,trx_query FROM information_schema.INNODB_TRX'),
        ('waits','SELECT requesting_trx_id,blocking_trx_id FROM information_schema.INNODB_LOCK_WAITS'),
        ('processlist','SHOW FULL PROCESSLIST'),
    ):
        try:
            observations.append(label+'='+sql(w,e,query)[-2200:])
        except Exception as error:
            observations.append(label+'_unavailable='+repr(error)[-250:])
    checkpoints=[p.name for p in sub.iterdir() if p.is_file()]
    observations.append('markers='+repr(sorted(checkpoints)))
    observations.append('worker_poll='+repr(worker.poll()))
    return ' / '.join(observations)


def run_case(w,e,folder,wdriver,hdriver,mutant,index):
    actor_a=13500+index*2; actor_b=actor_a+1
    checkout.fresh_actor(w,e,actor_a);checkout.fresh_actor(w,e,actor_b)
    sql(w,e,f'UPDATE users SET wallet=700000 WHERE userid={actor_a}')
    a_hash='crosswallet'+str(index); b_hash='crosshosted'+str(index)
    pid_a=checkout.seed_pay(w,e,a_hash,actor_a,typ='BUY_SUB')
    # Hosted renewal exercises a real shared server_plans/server_config
    # dependency without BUY_SUB's unrelated initial quantity/UI lookup.
    sql(w,e,("INSERT INTO orders_list "
             "(userid,token,transid,fileid,server_id,inbound_id,remark,uuid,"
             "protocol,expire_date,link,amount,status,date) "
             f"VALUES({actor_b},'synthetic','',7,2,0,'cross-remote-{index}',"
             f"'cross-uuid-{index}','vless',2000000000,'[]',100,1,'synthetic')"))
    oid=int(sql(w,e,f"SELECT id FROM orders_list WHERE remark='cross-remote-{index}'"))
    pid_b=checkout.seed_pay(w,e,b_hash,actor_b,typ='RENEW_ACCOUNT',target=oid)
    sql(w,e,f'UPDATE pays SET plan_id=7,server_id=2 WHERE id IN ({pid_a},{pid_b})')
    sub=w/('cross-case-'+str(index));sub.mkdir()
    wallet_spec={'actor':actor_a,'hash':a_hash,'audience':'all',
                 'ready':'','release':'',
                 'cross_ready':str(sub/'wallet-ready'),
                 'cross_release':str(sub/'wallet-release')}
    a=subprocess.Popen(r3.action_cmd(w,folder,wdriver,wallet_spec),cwd=w,env=e,
                       stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    b=None
    try:
        wait(sub/'wallet-ready',a)
        hosted_spec={'route':'initiate','get':{'nextpay':'','hash_id':b_hash},
                     'actor':actor_b,'cross_ready':str(sub/'hosted-ready'),
                     'cross_release':str(sub/'hosted-release'),
                     'cross_dir':str(sub)}
        b=subprocess.Popen(['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
            '-d','extension=curl','-d','allow_url_fopen=0',
            '-d','disable_functions=curl_init,curl_setopt,curl_setopt_array,curl_exec,curl_close,curl_error,curl_getinfo,exec,shell_exec,system,passthru,popen,proc_open,mail',
            str(hdriver),str(folder),DB,str(w/'mysql.sock'),json.dumps(hosted_spec)],
            cwd=w,env=e,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        if mutant:
            # This marker is reachable ONLY if the hosted transaction
            # acquired BOT_STATES while wallet already held server plan.
            wait(sub/'hosted-ready',b)
            (sub/'wallet-release').write_text('try-policy')
            (sub/'hosted-release').write_text('try-plan')
            aout,aerr=a.communicate(timeout=10)
            bout,berr=b.communicate(timeout=10)
            try:ao=json.loads(aout)
            except ValueError:ao={}
            try:bo=json.loads(bout)
            except ValueError:bo={}
            # Deadlock victim may vary: at least one cannot report success;
            # both may not complete paid/debit + issued invoice.
            wallet_success=(ao.get('status')=='ok' and ao.get('effects')==1)
            invoice_success=bool(bo.get('network_calls'))
            assert not (wallet_success and invoice_success),(
                'mutant_inverse_lock_was_not_detected',ao,bo,aerr,berr)
            return 'f4_mutant_plan_then_policy_inverse_lock_conflict_detected'
        # Both customers may hold compatible shared policy locks. The
        # second reaches the pre-plan marker while wallet is still active;
        # they serialize only on an actually shared plan dependency.
        try:
            wait(sub/'hosted-ready',b)
        except AssertionError as error:
            raise AssertionError(('hosted_before_plan_probe_not_reached',
                                  diagnostic(w,e,sub,b))) from error
        assert a.poll() is None,'wallet ended before independent policy reader'
        (sub/'hosted-release').write_text('try-shared-plan')
        time.sleep(.35)
        assert b.poll() is None,'hosted skipped shared dependency lock'
        (sub/'wallet-release').write_text('commit')
        aout,aerr=a.communicate(timeout=12)
        assert a.returncode==0 and not aerr,(aout,aerr)
        ao=json.loads(aout)
        assert ao['status']=='ok' and ao['effects']==1,ao
        bout,berr=b.communicate(timeout=12)
        assert b.returncode==0 and not berr,(bout,berr)
        bo=json.loads(bout)
        assert bo.get('fatal') is None and not bo.get('error'),bo
        assert bo['network_calls'],bo
        assert sql(w,e,f"SELECT state FROM pays WHERE id={pid_a}")=='paid_with_wallet'
        assert int(sql(w,e,f"SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id={pid_b}"))==1
        return 'f4_current_shared_policy_allows_parallel_readers_and_serializes_plan'
    finally:
        (sub/'wallet-release').write_text('done')
        (sub/'hosted-release').write_text('done')
        if a.poll() is None:a.kill();a.communicate(timeout=4)
        if b is not None and b.poll() is None:b.kill();b.communicate(timeout=4)

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('--run-disposable',action='store_true',required=True)
    ap.add_argument('--report',type=Path)
    args=ap.parse_args()
    evidence=[]
    with owned.owned_database() as (w,e):
        corrected=stage(w,False);mutant=stage(w,True)
        wdriver,hdriver=drivers(w)
        purchase.init(w,e,'http://127.0.0.1:9')
        sql(w,e,(ROOT/'migrations/post-v9/003_payment_attempts.sql').read_text())
        sql(w,e,"INSERT INTO setting(type,value) VALUES('BOT_STATES','{\"walletState\":\"on\",\"cartToCartState\":\"on\",\"giftCardState\":\"on\",\"manualUsdtState\":\"on\",\"nowPaymentWallet\":\"on\",\"nowPaymentOther\":\"on\",\"zarinpal\":\"on\",\"nextpay\":\"on\"}')")
        sql(w,e,"INSERT INTO setting(type,value) VALUES"
          "('PAYMENT_KEYS','{\"nextpay\":\"SYNTHETIC-LOCAL-ONLY\","
          "\"nowpayment\":\"SYNTHETIC-LOCAL-ONLY\","
          "\"zarinpal\":\"SYNTHETIC-LOCAL-ONLY\"}')")
        evidence.append(run_case(w,e,mutant,wdriver,hdriver,True,0))
        evidence.append(run_case(w,e,corrected,wdriver,hdriver,False,1))
    report={'complete':True,'cases':len(evidence),'scenarios':evidence,
        'intermediate_mutant_detected':True,'current_source_serialization':True,
        'real_php_mysqli':True,'owned_mariadb_only':True,
        'synthetic_provider_only':True,'production_mutations':0}
    if args.report:args.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps(report))
if __name__=='__main__':main()
