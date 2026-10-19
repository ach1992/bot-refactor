#!/usr/bin/env python3
"""PR #60 R3: wallet audience/plan authorization survives preflight races.

Source-real PHP/MySQLi, disposable socket-only MariaDB. An isolated staged
source is paused precisely after the REJECT-ONLY wallet preview and before the
owner lock. Another session commits a policy-relevant change. Only the fresh
post-lock authorization may debit and invoke the synthetic fulfillment.
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

ROOT=Path(__file__).resolve().parents[1]
DB=purchase.SIDE[1]

PHP = r"""<?php
error_reporting(E_ALL);
set_error_handler(static function($level,$message) {
    if ($level===E_DEPRECATED) return true;
    throw new ErrorException($message,0,$level);
});
$_SERVER['SCRIPT_FILENAME']=__FILE__;
$root=$argv[1];$sock=$argv[2];$dbname=$argv[3];
$spec=json_decode($argv[4],true,512,JSON_THROW_ON_ERROR);
$connection=new mysqli('localhost','root','',$dbname,0,$sock);
$connection->set_charset('utf8mb4');
if (($spec['isolation']??'')==='READ-COMMITTED')
    $connection->query('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
$actualIsolation=$connection->query('SELECT @@tx_isolation')->fetch_row()[0];
$from_id=(int)$spec['actor'];$admin=9001;
$botState=['gatewayAudience'=>['wallet'=>$spec['audience']]];
$GLOBALS['r3_ready']=$spec['ready']??'';
$GLOBALS['r3_release']=$spec['release']??'';
$GLOBALS['r3_effects']=0;
$GLOBALS['r3_notice']='';
function alert(...$args){}
function sendMessage(...$args) {$GLOBALS['r3_notice']=(string)($args[0]??'');return (object)['ok'=>true];}
require $root.'/src/Purchase/Core.php';
require $root.'/src/Finance/ReceiptHelpers.php';
require $root.'/src/Finance/WalletSettlement.php';
$payment=processWalletPayment($connection,(string)$spec['hash'],
    static function() {++$GLOBALS['r3_effects'];return true;});
$match=[];
preg_match('/wallet_transaction_guard_error_(\d+)/',
           $GLOBALS['r3_notice'],$match);
echo json_encode(['status'=>$payment['status']??null,
                  'effects'=>$GLOBALS['r3_effects'],
                  'error_code'=>isset($match[1])?(int)$match[1]:null,
                  'isolation'=>$actualIsolation],
                 JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
"""

MARKER = """            if (($GLOBALS['r3_ready']??'')!=='') {
                file_put_contents((string)$GLOBALS['r3_ready'],'post-preview');
                $until=microtime(true)+12;
                while (!is_file((string)$GLOBALS['r3_release']) && microtime(true)<$until)
                    usleep(12000);
                if (!is_file((string)$GLOBALS['r3_release']))
                    throw new RuntimeException('r3_fixture_release_timeout');
            }
"""

def sql(w,e,statement):
    return checkout.sql(w,e,statement)

def stage_source(w,reviewed=False):
    stage=w/('r3-reviewed-569802' if reviewed else 'r3-current')
    stages.stage_source(stage,True)
    p=stage/'src/Finance/WalletSettlement.php'
    if reviewed:
        p.write_bytes(subprocess.check_output(['git','show',
            '569802ca48558560694e9e4f137c8375e59229ae:src/Finance/WalletSettlement.php'],
            cwd=ROOT))
    source=p.read_bytes()
    needle=b'            achbotPayUxLockDraftOwner($connection,(int)$from_id);'
    assert source.count(needle)==1,source.count(needle)
    nl=b'\r\n' if b'\r\n' in source else b'\n'
    source=source.replace(needle,MARKER.replace('\n',nl.decode()).encode()+needle)
    p.write_bytes(source)
    driver=w/'r3-real-wallet-customer.php'
    driver.write_text(PHP)
    return stage,driver

def action_cmd(w,stage,driver,spec):
    return ['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
            '-d','allow_url_fopen=0','-d',
            'disable_functions=exec,shell_exec,system,passthru,popen,proc_open,mail,dl',
            str(driver),str(stage),str(w/'mysql.sock'),DB,json.dumps(spec)]

def result(proc):
    stdout,stderr=proc.communicate(timeout=15)
    assert proc.returncode==0 and not stderr, (proc.returncode,stdout,stderr)
    return json.loads(stdout)

def scenario(w,e,stage,driver,kind,iso,n,reviewed=False):
    actor=9800+n
    checkout.fresh_actor(w,e,actor)
    audience='agents'
    is_agent=1
    if kind=='change_non_agents':
        audience='non_agents';is_agent=0
    if kind in ('change_buyer','unchanged_buyer'):audience='buyers'
    if kind in ('change_non_buyer','unchanged_non_buyer'):audience='non_buyers'
    policy=json.dumps({'gatewayAudience':{'wallet':audience}})
    sql(w,e,"UPDATE setting SET value='"+policy+"' WHERE type='BOT_STATES'")
    sql(w,e,f"UPDATE users SET wallet=700000,is_agent={is_agent} WHERE userid={actor}")
    h='r3-wallet-'+str(n)
    typ='BUY_SUB' if kind in ('change_plan','unchanged_plan') else 'INCREASE_WALLET'
    pid=checkout.seed_pay(w,e,h,actor,typ=typ)
    if typ=='BUY_SUB':
        sql(w,e,f"UPDATE pays SET plan_id=7,server_id=2 WHERE id={pid}")
        assert sql(w,e,'SELECT p.server_id,p.inbound_id,c.type FROM server_plans p '
            'JOIN server_config c ON c.id=p.server_id WHERE p.id=7')=='2\t0\tmarzban'
    buyer_id=None
    if kind in ('change_buyer','unchanged_buyer'):
        buyer_id=checkout.seed_pay(w,e,'r3-buyer-'+str(n),actor,
                                  typ='BUY_SUB',state='paid')
    initial=sql(w,e,f'SELECT wallet FROM users WHERE userid={actor}')
    folder=w/('r3-case-'+str(n));folder.mkdir()
    spec={'hash':h,'actor':actor,'audience':audience,'isolation':iso,
          'ready':str(folder/'prepared'),'release':str(folder/'release')}
    proc=subprocess.Popen(action_cmd(w,stage,driver,spec),cwd=w,env=e,
        stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    try:
        until=time.monotonic()+5
        while not (folder/'prepared').exists() and time.monotonic()<until:
            if proc.poll() is not None:
                raise AssertionError(('PHP exited before preview',proc.communicate()))
            time.sleep(.014)
        assert (folder/'prepared').exists(),'wallet preview barrier missed'
        # Actual second MariaDB connection commits a mutation after preview.
        if kind=='change_agents':
            sql(w,e,f'UPDATE users SET is_agent=0 WHERE userid={actor}')
        elif kind=='change_non_agents':
            sql(w,e,f'UPDATE users SET is_agent=1 WHERE userid={actor}')
        elif kind=='change_plan':
            sql(w,e,"UPDATE server_config SET type='sanaei' WHERE id=2")
        elif kind=='change_buyer':
            sql(w,e,f"UPDATE pays SET state='declined' WHERE id={buyer_id}")
        elif kind=='change_non_buyer':
            checkout.seed_pay(w,e,'r3-new-buyer-'+str(n),actor,
                              typ='BUY_SUB',state='paid')
        (folder/'release').write_text('commit-visible')
        outcome=result(proc)
    finally:
        (folder/'release').write_text('done')
        if proc.poll() is None:
            proc.kill();proc.communicate(timeout=4)
    assert outcome['isolation']==(
        'READ-COMMITTED' if iso=='READ-COMMITTED' else 'REPEATABLE-READ'),outcome
    paid=kind in ('unchanged_agents','unchanged_plan',
                  'unchanged_buyer','unchanged_non_buyer')
    expected='ok' if paid else ('unsupported_panel' if kind=='change_plan' else 'claimed_provider')
    if outcome['status']!=expected or outcome['effects']!=int(paid):
        raise AssertionError(('R3 unsafe stale wallet authorisation',kind,iso,
            'expected_status',expected,'actual',outcome,
            'wallet_before',initial,
            'wallet_after',sql(w,e,f'SELECT wallet FROM users WHERE userid={actor}')))
    assert sql(w,e,f'SELECT state FROM pays WHERE id={pid}')==(
        'paid_with_wallet' if paid else 'pending'),outcome
    assert sql(w,e,f'SELECT wallet FROM users WHERE userid={actor}')==(
        str(int(initial)-100000) if paid else initial)
    if paid:
        again=result(subprocess.Popen(action_cmd(w,stage,driver,{
            'hash':h,'actor':actor,'audience':audience,'isolation':iso}),
            cwd=w,env=e,stdout=subprocess.PIPE,stderr=subprocess.PIPE))
        assert again['status']=='already_paid' and again['effects']==0,again
        assert sql(w,e,f'SELECT wallet FROM users WHERE userid={actor}')==str(int(initial)-100000)
    # Terminal state, existing claim and fulfillment are not inferred from
    # a stale pay button; R2 has the separate pinned-claim regression.
    assert sql(w,e,f'SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id={pid}')=='0'
    return 'r3_'+iso.lower().replace('-','_')+'_'+kind+'_authorization_fresh'

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('--run-disposable',action='store_true',required=True)
    ap.add_argument('--report',type=Path)
    ap.add_argument('--r3-reviewed-head',action='store_true',
                    help='Stage exact independently reviewed 569802 wallet and show RED')
    ap.add_argument('--only',choices=('all','change_agents','change_non_agents',
        'change_buyer','change_non_buyer','change_plan','unchanged_agents',
        'unchanged_buyer','unchanged_non_buyer','unchanged_plan'),default='all')
    args=ap.parse_args()
    checks=[]
    with owned.owned_database() as (w,e):
        stage,driver=stage_source(w,args.r3_reviewed_head)
        purchase.init(w,e,'http://127.0.0.1:9')
        sql(w,e,"INSERT INTO setting (type,value) VALUES ('BOT_STATES','{}')")
        sql(w,e,(ROOT/'migrations/post-v9/003_payment_attempts.sql').read_text())
        sql(w,e,(ROOT/'migrations/post-v9/006_payment_message_refs.sql').read_text())
        assert len(sql(w,e,'SHOW TABLES').splitlines())==26
        for index,(kind,iso) in enumerate(
                (('change_agents','REPEATABLE-READ'),
                 ('change_non_agents','REPEATABLE-READ'),
                 ('change_buyer','REPEATABLE-READ'),
                 ('change_non_buyer','REPEATABLE-READ'),
                 ('change_plan','REPEATABLE-READ'),
                 ('unchanged_agents','REPEATABLE-READ'),
                 ('unchanged_buyer','REPEATABLE-READ'),
                 ('unchanged_non_buyer','REPEATABLE-READ'),
                 ('unchanged_plan','REPEATABLE-READ'),
                 ('change_agents','READ-COMMITTED'),
                 ('change_plan','READ-COMMITTED'),
                 ('unchanged_plan','READ-COMMITTED'))):
            if args.only!='all' and kind!=args.only:continue
            if kind in ('change_plan','unchanged_plan'):
                sql(w,e,"UPDATE server_config SET type='marzban' WHERE id=2")
            checks.append(scenario(w,e,stage,driver,kind,iso,index))
    report={'complete':True,'cases':len(checks),'scenarios':checks,
            'source_real_php':True,'owned_mariadb_only':True,
            'default_repeatable_read_verified':True,
            'other_isolation_read_committed_verified':True,
            'remote_provider_calls':0,'production_mutations':0}
    if args.report:args.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps(report))

if __name__=='__main__':main()
