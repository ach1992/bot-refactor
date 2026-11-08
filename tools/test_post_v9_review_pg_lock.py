#!/usr/bin/env python3
"""Issue #117: actual PasarGuard quote GET never runs under owner financial SQL locks.

Source-real PHP / owned MariaDB; real local PasarGuard HTTP adapter and isolated
127.0.0.1 HTTP server. No real provider, customer or Telegram effects.
"""
from __future__ import annotations

import argparse
import json
import subprocess
import threading
import time
from pathlib import Path
from urllib.parse import urlsplit

import test_access_state as owned
import test_post_v9_pasarguard_client as pg
import test_v6_native as native
import test_v8_native as purchase
import provider_storage_fixture as shared_storage

ROOT=Path(__file__).resolve().parents[1]
PHP=r'''<?php
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(static function($n,$m){if($n===E_DEPRECATED)return true;throw new ErrorException($m,0,$n);});
$_SERVER['SCRIPT_FILENAME']=__FILE__;
define('ACHBOT_PG_TEST_LOOPBACK',true);
$root=$argv[1];
require_once $root.'/src/Purchase/Core.php';
require_once $root.'/src/Finance/ReviewDecisions.php';
function achbotNpChildEvidenceRank($status): int {
 return match((string)$status) {'waiting','failed','expired'=>0,'confirming'=>1,
  'confirmed'=>2,'sending'=>3,'partially_paid'=>4,'finished'=>5,'refunded'=>6,default=>0};
}
function doAction($payId,$provider){throw new RuntimeException('forbidden_financial_replay');}
$db=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$db->set_charset('utf8mb4');
$mode=$argv[4];
if($mode==='pin'){
 $db->begin_transaction();
 achbotPgPinPayment($db,301);
 if(!$db->commit())throw new RuntimeException('pin_commit_unconfirmed');
 echo json_encode(['pin'=>true],JSON_THROW_ON_ERROR);exit;
}
$a=$db->query("SELECT id FROM payment_attempts WHERE pay_row_id=301")->fetch_assoc();
$remote=['payment_id'=>'pg-review-child',
 'invoice_id'=>'pg-review-invoice','order_id'=>'pg-review-claim',
 'payment_status'=>'finished','price_amount'=>'1.00000000','price_currency'=>'usd',
 'pay_amount'=>'1','actually_paid'=>'1','pay_currency'=>'usdttrc20',
 'outcome_amount'=>'0.98','outcome_currency'=>'usdttrc20'];
if($mode==='probe'){
 $pre=achbotGatewayReviewEligible($db,(int)$a['id'],'approve');
 $stamp=$pre?achbotGatewayReviewSubjectStamp($db,$pre['pay']):null;
 $supports=$pre?achbotSupportsPaymentSubject($db,$pre['pay']):false;
 echo json_encode(['eligible'=>$pre!==null,'stamp'=>$stamp!==null,
   'supports'=>$supports],JSON_THROW_ON_ERROR);
 exit;
}
$out=achbotGatewayReviewCommit($db,9001,(int)$a['id'],'approve');
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
'''
ORIGINAL=pg.Handler.handle_request

def block_remote_read(self):
    server=self.server
    if (self.command=='GET' and '/api/user/' in urlsplit(self.path).path
        and getattr(server,'block_pg_read',False)):
        server.waiting.set()
        if not server.release.wait(12):
            raise RuntimeError('loopback_read_timeout')
    return ORIGINAL(self)

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--run-disposable',action='store_true',required=True)
    parser.add_argument('--report',type=Path)
    args=parser.parse_args()
    pg.Handler.handle_request=block_remote_read
    checks=[]
    with owned.owned_database() as (work,env),pg.panel() as (server,url):
        purchase.init(work,env,url)
        db=purchase.SIDE[1]
        def sql(stmt):
            return native.sql(work,env,stmt,db).strip()
        for name in [
            '001_marzban_panel_group_id.sql',
            '003_payment_attempts.sql',
            '007_service_effects.sql',
            '011_provider_identity.sql',
            '012_shared_provider_storage.sql',
            '016_remove_pasarguard_server_groups.sql',
            '017_shared_plan_changes.sql',
            ]:
            sql((ROOT/'migrations/post-v9'/name).read_text())
        sql(shared_storage.connection(2,1,url)+
            "UPDATE server_plans SET provider_group_ids='[2,5]' WHERE id IN (7,8);")
        sql("INSERT INTO orders_list(id,userid,token,transid,fileid,server_id,inbound_id,"
            "remark,uuid,protocol,expire_date,link,amount,status,date)"
            " VALUES(91,1001,'','',7,2,0,'fixture_user','native-placeholder',"
            "'vless',2000000000,'[]',100,1,'fixture')")
        sql(shared_storage.identity(777,91,1,url,17,pg.USER['created_at'],
            'fixture_user',pg.MARKER,
            {'group_ids':[2,5],'expire':2000000000,'data_limit':2147483648},
            server_id=2,plan_id=7))
        sql("INSERT INTO pays(id,hash_id,user_id,type,price,request_date,state,payid,"
            "server_id,plan_id,order_id)"
            " VALUES(301,'pg-review-claim',1001,'RENEW_ACCOUNT',100000,"
            "1700000000,'pending','pg-review-invoice',2,7,91)")
        sql("INSERT INTO payment_attempts(pay_row_id,provider,order_hash,provider_reference,"
            "payment_id,quote_amount,quote_currency,local_amount_toman,stage,"
            "provider_status,minimum_settlement_amount)"
            " VALUES(301,'nowpayments','pg-review-claim','pg-review-invoice',"
            "'pg-review-child',1,'usd',100000,'review','partial',0)")
        sql("INSERT INTO payment_transactions(attempt_id,provider,payment_id,observed_status)"
            " SELECT id,'nowpayments','pg-review-child','finished'"
            " FROM payment_attempts WHERE pay_row_id=301")
        script=work/'review-pg-lock.php'
        script.write_text(PHP)
        def php(mode):
            return ['php','-d','display_errors=0',str(script),str(ROOT),
                    str(work/'mysql.sock'),db,mode]
        pinned=subprocess.run(php('pin'),capture_output=True,text=True,timeout=20)
        if pinned.returncode or pinned.stderr or json.loads(pinned.stdout)!={'pin':True}:
            raise RuntimeError('source_real_pg_payment_pin_unavailable: '+
                repr((pinned.stdout[-800:],pinned.stderr[-1800:])))
        probe=subprocess.run(php('probe'),capture_output=True,text=True,timeout=20)
        if probe.returncode or probe.stderr or json.loads(probe.stdout)!={
            'eligible':True,'stamp':True,'supports':True}:
            raise RuntimeError('pg_actual_eligible_probe_failed: '+
                repr((probe.stdout[-600:],probe.stderr[-1000:])))
        checks.append('source_real_pasar_guard_account_identity_pinned')
        original_title=sql("SELECT title FROM server_plans WHERE id=7")
        for case in ('pay_terminal_drift','plan_target_drift'):
            sql("UPDATE pays SET state='pending' WHERE id=301")
            if case=='plan_target_drift':
                sql("UPDATE server_plans SET title=CONVERT(UNHEX('"+
                    original_title.encode().hex()+"') USING utf8mb4) WHERE id=7")
            server.waiting=threading.Event()
            server.release=threading.Event()
            server.block_pg_read=True
            proc=subprocess.Popen(php('decision'),stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,text=True)
            try:
                if not server.waiting.wait(9):
                    stdout,stderr=proc.communicate(timeout=3)
                    raise RuntimeError('source_real_pasar_guard_get_not_reached: '+
                        repr((case,stdout[-500:],stderr[-1100:])))
                start=time.monotonic()
                if case=='pay_terminal_drift':
                    sql("SET SESSION innodb_lock_wait_timeout=1;"
                        "UPDATE pays SET state='declined' WHERE id=301")
                else:
                    sql("SET SESSION innodb_lock_wait_timeout=1;"
                        "UPDATE server_plans SET title='changed_during_remote_GET' WHERE id=7")
                elapsed=time.monotonic()-start
                if elapsed>2:
                    raise RuntimeError('financial_or_target_lock_held_during_provider_GET')
            finally:
                server.release.set()
                server.block_pg_read=False
            stdout,stderr=proc.communicate(timeout=12)
            if proc.returncode or stderr:
                raise RuntimeError('source_real_pg_review_php_failure: '+
                    repr((case,proc.returncode,stdout[-700:],stderr[-1200:])))
            result=json.loads(stdout)
            if result.get('status') not in ('stale','unsupported'):
                raise RuntimeError('changed_financial_or_quote_subject_not_fenced: '+
                    repr((case,result)))
            if sql("SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id=301 AND provider_status LIKE 'operator_%'")!='0':
                raise RuntimeError('remote_drift_committed_unsafe_financial_claim')
            if server.write_count:
                raise RuntimeError('remote_mutation_occurred_during_review_preflight')
            checks.append('source_real_pg_GET_no_SQL_lock_'+case)
            checks.append('source_real_pg_GET_drift_fails_closed_'+case)
        out={'complete':True,'source_real_php':True,'owned_mariadb':True,
             'actual_pasar_guard_http_get':True,'financial_locks_released_during_GET':True,
             'target_drift_fails_closed':True,'native_writes':server.write_count,
             'checks':checks}
    if args.report:args.report.write_text(json.dumps(out,indent=2)+'\n')
    print(json.dumps(out))

if __name__=='__main__':main()
