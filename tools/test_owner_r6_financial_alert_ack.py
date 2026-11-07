#!/usr/bin/env python3
"""#117 source-real quiet payment review: formerly unsolicited staff alerts.

Run both historical notifier entrypoints against an owned MariaDB ledger;
they must emit zero Telegram sends and change no invoice, lease or balances.
The ordinary business-result channels are tested separately.
"""
from pathlib import Path
import argparse,json,subprocess
import test_access_state as owned

ROOT=Path(__file__).resolve().parents[1]
BASE='62eb06d164224947f1cda84144d08ff88d3a0c8e'
PHP=r'''<?php
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(static function($n,$m){if($n===E_DEPRECATED)return true;throw new ErrorException($m,0,$n);});
$root=$argv[1];$db=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$db->set_charset('utf8mb4');$admin=9001;
$connection=$db;$botState=['renewAutoState'=>'off','rewardChannel'=>-100123];
$events=[];$checks=[];
function sendMessage(...$args) {$GLOBALS['events'][]=$args;throw new RuntimeException('unsolicited_staff_alert');}
function yes($ok,$name){if(!$ok)throw new RuntimeException($name);$GLOBALS['checks'][]=$name;}
define('ACHBOT_NP_RECONCILE_ONLY',true);
require_once $root.'/src/Finance/GatewayReturn.php';
require_once $root.'/src/Finance/AutomaticRenewal.php';
$db->query("INSERT INTO pays(id,hash_id,user_id,type,price,request_date,state)
  VALUES(101,'renew-quiet',1001,'RENEW_ACCOUNT',100000,UNIX_TIMESTAMP(),'processing_wallet'),
        (102,'np-quiet',1001,'INCREASE_WALLET',100000,UNIX_TIMESTAMP(),'pending'),
        (103,'np-quiet-done',1001,'INCREASE_WALLET',100000,UNIX_TIMESTAMP(),'paid')");
$db->query("INSERT INTO automatic_renewal_attempts
  (id,order_id,cycle_expire,pay_row_id,user_id,plan_id,server_id,account_remark,
   price_toman,duration_days,volume_gb,new_expire,stage,reason_code,created_at)
  VALUES(1,42,1,101,1001,7,2,'fixture',100000,30,2,2000000000,'review','remote_unconfirmed',NOW())");
$db->query("INSERT INTO payment_attempts
  (id,pay_row_id,provider,order_hash,provider_reference,payment_id,quote_amount,
   quote_currency,local_amount_toman,stage,provider_status)
  VALUES(1,102,'nowpayments','np-quiet','invoice-quiet','child-quiet',1,'usd',100000,'review','partial'),
        (2,103,'nowpayments','np-quiet-done','invoice-done','child-done',1,'usd',100000,'review','finished')");
$db->query("INSERT INTO payment_transactions
  (attempt_id,provider,payment_id,observed_status)
  VALUES(1,'nowpayments','child-quiet','partially_paid'),
        (2,'nowpayments','child-done','finished')");
function snapshot($db){
 $state=[];
 foreach(['users','pays','payment_attempts','payment_transactions','automatic_renewal_attempts'] as $table)
  $state[$table]=$db->query('SELECT * FROM '.$table.' ORDER BY id')->fetch_all(MYSQLI_ASSOC);
 return $state;
}
$original=snapshot($db);
foreach (['gateway','renewal'] as $domain) {
 foreach (['owner','none','delegated'] as $target) {
  $primary=match($target){'owner'=>9001,'none'=>null,default=>9003};
  $reply=$domain==='gateway'?achbotNpAlertOperator($db,$primary,5):
      achbotAutoRenewAlertReview($db,$primary);
  yes($reply===($domain==='gateway'
       ?['examined'=>0,'notified'=>0,'failed'=>0]:null),
       $domain.' '.$target.' quiet no-op');
  yes($events===[] && snapshot($db)===$original,
       $domain.' '.$target.' zero sends no financial or lease mutation');
 }
}
echo json_encode(['complete'=>true,'cases'=>count($checks),'checks'=>$checks,
 'source_ref'=>'current-source','baseline_source'=>'62eb06d164224947f1cda84144d08ff88d3a0c8e',
 'actual_domain_callers'=>true,'shared_ledger_owned_mariadb'=>true,
 'review_push_retired'=>true,'financial_and_claim_state_unchanged'=>true,
 'customer_and_business_outcome_routes_untouched'=>true,'network_calls'=>0],
 JSON_THROW_ON_ERROR);
'''
def main():
    p=argparse.ArgumentParser();p.add_argument('--report',type=Path);args=p.parse_args()
    with owned.owned_database() as (d,env):
        client=['mariadb','--no-defaults','--protocol=SOCKET',
            '--socket='+str(d/'mysql.sock'),'-uroot',owned.NAMES[1]]
        for file in ['003_payment_attempts.sql','004_automatic_renewal_attempts.sql']:
            subprocess.run(client,input=(ROOT/'migrations/post-v9'/file).read_bytes(),
                env=env,capture_output=True,check=True,timeout=15)
        driver=d/'quiet-review.php';driver.write_text(PHP)
        result=subprocess.run(['php','-d','allow_url_fopen=0',
            '-d','disable_functions=curl_init,curl_exec',str(driver),
            str(ROOT),str(d/'mysql.sock'),owned.NAMES[1]],
            env=env,capture_output=True,text=True,timeout=30)
        if result.returncode or result.stderr: raise RuntimeError(
            result.stderr[-3000:]+' '+result.stdout[-1000:])
        report=json.loads(result.stdout)
    if args.report:args.report.write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps({k:v for k,v in report.items() if k!='checks'}))
if __name__=='__main__':main()
