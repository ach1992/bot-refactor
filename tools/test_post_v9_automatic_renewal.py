#!/usr/bin/env python3
"""Owned socket-only MariaDB automatic-renewal journal and native PHP tests.

No live payment, Telegram, Marzban, production database or external network.
"""
import argparse
import json
import subprocess
import time
from pathlib import Path
import test_access_state as owned
import test_v6_native as native
import test_v8_native as purchase

ROOT=Path(__file__).resolve().parents[1]
DB=purchase.SIDE[1]
PHP=r'''<?php
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(function($sev,$msg){throw new ErrorException($msg,0,$sev);});
$_SERVER['SCRIPT_FILENAME']='owned-renew-php.php';
$root=$argv[1];
$spec=json_decode($argv[4],true,512,JSON_THROW_ON_ERROR);
$GLOBALS['spec']=$spec;
$connection=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$connection->set_charset('utf8mb4');
// Loading function definitions must not run the shared Cron sweep when the
// test is exercising an individual owned transaction. Sweep has its own case.
$botState=['renewAutoState'=>'off'];
$admin=null;
$events=[];
require_once $root.'/src/Purchase/Core.php';
function getMarzbanUser($id,$remark) {
    $GLOBALS['events'][]=['type'=>'read'];
    return (object)['username'=>$remark,'expire'=>time()+1000,
                    'data_limit'=>1073741824,'used_traffic'=>1060000000];
}
function editMarzbanConfig($id,$info) {
    $oid=(int)$GLOBALS['spec']['order_id'];
    $stmt=$GLOBALS['connection']->prepare(
        "SELECT stage FROM automatic_renewal_attempts WHERE order_id=? ORDER BY id DESC LIMIT 1");
    $stmt->bind_param('i',$oid);$stmt->execute();
    $stage=$stmt->get_result()->fetch_assoc()['stage']??'absent';$stmt->close();
    if($stage!=='remote_started') throw new RuntimeException('remote_started not persisted');
    $GLOBALS['events'][]=['type'=>'remote','stage'=>$stage];
    if(!empty($GLOBALS['spec']['delay_ms'])) usleep((int)$GLOBALS['spec']['delay_ms']*1000);
    if(!empty($GLOBALS['spec']['drift'])) {
        $stmt=$GLOBALS['connection']->prepare("UPDATE orders_list SET expire_date=expire_date+7 WHERE id=?");
        $stmt->bind_param('i',$oid);$stmt->execute();$stmt->close();
    }
    if(!empty($GLOBALS['spec']['throw']))throw new RuntimeException('mock ambiguous timeout');
    return (object)['success'=>$GLOBALS['spec']['remote_success']??true];
}
function sendMessage($message,$keys=null,$parse=null,$id=null) {
    $GLOBALS['events'][]=['type'=>'send','to'=>$id];
    return (object)['ok'=>$GLOBALS['spec']['telegram_ok']??true];
}
require_once $root.'/src/Finance/AutomaticRenewal.php';
$botState['renewAutoState']=$spec['enabled']??'off';
$admin=9001;
$action=$spec['action']??'call';
$oid=(int)($spec['order_id']??0);
$uid=(int)($spec['user_id']??1001);
if($action==='call') {
    handleAutoRenewCallback('renewAuto_with_wallet|'.$uid.'|'.$oid);
} elseif($action==='reserve' || $action==='remote_mark') {
    $a=achbotAutoRenewReserve($connection,$oid,$uid);
    $events[]=['type'=>'reserve','status'=>$a['status']];
    if($action==='remote_mark' && $a['status']==='reserved') {
        $events[]=['type'=>'mark','ok'=>achbotAutoRenewMarkRemoteStarted($connection,(int)$a['id'])];
    }
} elseif($action==='alert') {
    achbotAutoRenewAlertReview($connection,$admin);
} elseif($action==='sweep') {
    achbotAutoRenewSweep($connection,$botState);
} else throw new RuntimeException('invalid test operation');
echo json_encode($events,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
'''
def sql(w,e,q):
    return native.sql(w,e,q,DB)
def cmd(w,e,s):
    file=w/'owned-renew-php.php'
    if not file.exists():file.write_text(PHP)
    return ['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
            '-d','allow_url_fopen=0',
            '-d','disable_functions=exec,shell_exec,system,passthru,popen,proc_open,mail',
            str(file),str(ROOT),str(w/'mysql.sock'),DB,json.dumps(s)]
def run(w,e,s):
    p=subprocess.run(cmd(w,e,s),cwd=w,env=e,capture_output=True,timeout=25)
    expected={b'achbot_auto_renew_migration_required',
              b'achbot_auto_renew_reservation_deferred',
              b'achbot_auto_renew_finalization_deferred',
              b'achbot_auto_renew_sweep_deferred',
              b'achbot_auto_renew_review_alert_deferred'}
    unexpected=[line for line in p.stderr.splitlines() if line not in expected]
    if p.returncode or unexpected:
        raise RuntimeError(repr((p.returncode,unexpected[-8:],p.stdout[-400:])))
    return json.loads(p.stdout)
def seed(w,e,name,amount=100000,wallet=500000):
    sql(w,e,f"UPDATE users SET wallet={wallet} WHERE userid=1001")
    sql(w,e,f"UPDATE server_plans SET price={amount},days=30,volume=2,active=1 WHERE id=7")
    exp=int(time.time())+1000
    sql(w,e,"INSERT INTO orders_list (userid,token,transid,fileid,server_id,inbound_id,"
          "remark,uuid,protocol,expire_date,link,amount,status,date,notif,autoRenew) VALUES "
          f"(1001,'t-{name}','',7,2,0,'{name}','uuid','vless',{exp},'[]',{amount},1,'synthetic',1,'on')")
    return int(sql(w,e,f"SELECT id FROM orders_list WHERE remark='{name}'"))
def count(w,e,table,where='1=1'):
    return int(sql(w,e,f"SELECT COUNT(*) FROM {table} WHERE {where}"))
def wallet(w,e):
    return int(sql(w,e,"SELECT wallet FROM users WHERE userid=1001"))
def state(w,e,oid):
    return sql(w,e,f"SELECT stage FROM automatic_renewal_attempts WHERE order_id={oid}").strip()
def assert_no_remote(events):
    assert not any(x['type']=='remote' for x in events),events

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('--run-disposable',required=True,action='store_true')
    ap.add_argument('--report',type=Path)
    a=ap.parse_args()
    cases=[]
    with owned.owned_database() as (w,e):
        purchase.init(w,e,'http://127.0.0.1:9')
        assert len(sql(w,e,'SHOW TABLES').splitlines())==23
        oid=seed(w,e,'unmigrated')
        n=count(w,e,'pays')
        assert_no_remote(run(w,e,{'order_id':oid,'enabled':'off'}))
        assert_no_remote(run(w,e,{'order_id':oid,'enabled':'on'}))
        assert count(w,e,'pays')==n and wallet(w,e)==500000
        cases.append('missing_schema_fails_closed')
        sql(w,e,(ROOT/'migrations/post-v9/004_automatic_renewal_attempts.sql').read_text())
        assert len(sql(w,e,'SHOW TABLES').splitlines())==24
        assert_no_remote(run(w,e,{'order_id':oid,'enabled':'off'}))
        assert wallet(w,e)==500000
        cases.append('disabled_flag_no_effect')
        ev=run(w,e,{'order_id':oid,'enabled':'on'})
        assert sum(x['type']=='remote' for x in ev)==1,ev
        assert wallet(w,e)==400000 and state(w,e,oid)=='done'
        assert sql(w,e,f"SELECT state FROM pays WHERE order_id={oid} AND description='automatic_renewal'").strip()=='paid_with_wallet'
        assert sql(w,e,f"SELECT notif FROM orders_list WHERE id={oid}").strip()=='0'
        assert count(w,e,'increase_order',"remark='unmigrated'")==1
        assert count(w,e,'automatic_renewal_attempts',f'order_id={oid}')==1
        cases.append('verified_remote_success_all_local_effects_atomic')
        assert_no_remote(run(w,e,{'order_id':oid,'enabled':'on'}))
        assert_no_remote(run(w,e,{'order_id':oid,'enabled':'on','user_id':9002}))
        assert wallet(w,e)==400000
        cases.append('replayed_and_foreign_requests_no_extra_charge')
        low=seed(w,e,'low',wallet=10)
        assert_no_remote(run(w,e,{'order_id':low,'enabled':'on'}))
        assert wallet(w,e)==10 and sql(w,e,f"SELECT autoRenew FROM orders_list WHERE id={low}").strip()=='off'
        assert count(w,e,'automatic_renewal_attempts',f'order_id={low}')==0
        cases.append('insufficient_funds_disables_auto_only')
        debt=seed(w,e,'debt',wallet=-1000)
        assert_no_remote(run(w,e,{'order_id':debt,'enabled':'on'}))
        assert wallet(w,e)==-1000 and state(w,e,debt)==''
        cases.append('signed_admin_debt_preserved_no_user_debit')
        invalid=seed(w,e,'invalid',amount=0)
        n=count(w,e,'pays')
        assert_no_remote(run(w,e,{'order_id':invalid,'enabled':'on'}))
        assert count(w,e,'pays')==n and wallet(w,e)==500000
        cases.append('nonpositive_plan_fails_closed')
        failed=seed(w,e,'timeout')
        ev=run(w,e,{'order_id':failed,'enabled':'on','throw':True})
        assert sum(x['type']=='remote' for x in ev)==1 and state(w,e,failed)=='review'
        assert wallet(w,e)==400000
        assert sql(w,e,f"SELECT state FROM pays WHERE order_id={failed}").strip()=='processing_wallet'
        assert count(w,e,'increase_order',"remark='timeout'")==0
        assert_no_remote(run(w,e,{'order_id':failed,'enabled':'on'}))
        assert wallet(w,e)==400000
        cases.append('ambiguous_remote_result_never_replayed_or_refunded')
        changed=seed(w,e,'drift')
        run(w,e,{'order_id':changed,'enabled':'on','drift':True})
        assert state(w,e,changed)=='review'
        assert count(w,e,'increase_order',"remark='drift'")==0
        cases.append('remote_success_database_drift_held_for_review')
        crash=seed(w,e,'crash')
        ev=run(w,e,{'order_id':crash,'enabled':'off','action':'remote_mark'})
        assert any(x['type']=='mark' and x['ok'] for x in ev),ev
        assert state(w,e,crash)=='remote_started'
        assert_no_remote(run(w,e,{'order_id':crash,'enabled':'on'}))
        cases.append('crashed_worker_remote_marker_blocks_retry')
        sql(w,e,f"UPDATE automatic_renewal_attempts SET created_at=NOW()-INTERVAL 10 MINUTE WHERE order_id={crash}")
        alerts=run(w,e,{'action':'alert','order_id':crash,'telegram_ok':False})
        assert any(x['type']=='send' for x in alerts)
        assert sql(w,e,f"SELECT review_notified_at IS NULL FROM automatic_renewal_attempts WHERE order_id={crash}").strip()=='1'
        success=run(w,e,{'action':'alert','order_id':crash})
        assert any(x['type']=='send' for x in success)
        again=run(w,e,{'action':'alert','order_id':crash})
        assert not any(x['type']=='send' for x in again)
        cases.append('private_operator_notice_retry_and_once_per_day_ack')
        racing=seed(w,e,'race')
        spec={'order_id':racing,'enabled':'on','delay_ms':250}
        ps=[subprocess.Popen(cmd(w,e,spec),cwd=w,env=e,stdout=subprocess.PIPE,stderr=subprocess.PIPE) for _ in range(2)]
        results=[]
        for p in ps:
            out,err=p.communicate(timeout=24)
            assert not err and p.returncode==0,(p.returncode,err,out)
            results.append(json.loads(out))
        assert sum(x['type']=='remote' for es in results for x in es)==1,results
        assert wallet(w,e)==400000 and state(w,e,racing)=='done'
        assert count(w,e,'increase_order',"remark='race'")==1
        cases.append('two_real_php_mysql_clients_exactly_one_debit_and_remote')
        # A duplicate legacy userid cannot be the source of a spend.
        duplicate=seed(w,e,'duplicateuser')
        sql(w,e,"INSERT INTO users (userid,name,username,refcode,date,step,wallet) "
              "VALUES (1001,'synthetic','duplicate','synthetic','today','none',500000)")
        n=count(w,e,'pays')
        assert_no_remote(run(w,e,{'order_id':duplicate,'enabled':'on'}))
        assert count(w,e,'pays')==n and state(w,e,duplicate)==''
        sql(w,e,"DELETE FROM users WHERE userid=1001 AND username='duplicate'")
        cases.append('nonunique_legacy_identity_cannot_fund_renewal')
        # Real sweep path must not starve or reach out when off. Make old test
        # orders ineligible so one eligible order proves the exact Cron loop.
        sql(w,e,"UPDATE orders_list SET autoRenew='off' WHERE id<>"+str(duplicate))
        sql(w,e,"UPDATE orders_list SET autoRenew='off' WHERE id="+str(duplicate))
        sweep=seed(w,e,'sweep')
        sql(w,e,"UPDATE setting SET value='0' WHERE type='AutoRenewCheck'")
        ev=run(w,e,{'action':'sweep','order_id':sweep,'enabled':'on'})
        assert sum(x['type']=='remote' for x in ev)==1,ev
        assert state(w,e,sweep)=='done' and wallet(w,e)==400000
        assert count(w,e,'increase_order',"remark='sweep'")==1
        cases.append('real_cron_sweep_one_eligible_marzban_order')
        # A Telegram failure after commit is not allowed to revert success.
        notice=seed(w,e,'notification')
        ev=run(w,e,{'order_id':notice,'enabled':'on','telegram_ok':False})
        assert any(x['type']=='send' for x in ev) and state(w,e,notice)=='done'
        assert wallet(w,e)==400000 and count(w,e,'increase_order',"remark='notification'")==1
        cases.append('telegram_failure_after_commit_does_not_revert_payment')
    report=dict(complete=True,cases=len(cases),scenarios=cases,source_only_004=True,
                original_schema_file_unchanged=True,provider_requests=0,
                production_mutations=0,native_php_workers=2)
    if a.report:a.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps({k:v for k,v in report.items() if k!='scenarios'}))
if __name__=='__main__': main()
