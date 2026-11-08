#!/usr/bin/env python3
"""Isolated PHP 8.5 / MariaDB source-real automatic gateway decision tests.

Only synthetic data and one test-owned Unix-socket MariaDB. No merchant,
Telegram, Native API, live Chili, or real balance is ever contacted.
"""
from __future__ import annotations
import argparse
import json
import subprocess
from pathlib import Path
import test_access_state as fx

ROOT = Path(__file__).resolve().parents[1]
DB = fx.NAMES[1]

PHP = r'''<?php
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(static function($severity,$message) {
 if($severity===E_DEPRECATED)return true;
 throw new ErrorException($message,0,$severity);
});
$_SERVER['SCRIPT_FILENAME']=__FILE__;
$root=$argv[1];
$db=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$db->set_charset('utf8mb4');
$GLOBALS['connection']=$db;
$GLOBALS['events']=[];$checks=[];
function check($ok,$label) {
 if(!$ok)throw new RuntimeException('failed:'.$label);
 $GLOBALS['checks'][]=$label;
}
function achbotPayUxHostedAttentionSeconds(){return 3600;}
function achbotSupportsPaymentSubject($db,$pay) {
 return (string)($pay['type']??'')==='INCREASE_WALLET';
}
function doAction($payId,$provider) {
 if(!in_array($provider,['nowpayment','zarinpal','nextpay'],true))
   throw new RuntimeException('unexpected_provider');
 $GLOBALS['events'][]=['original_effect',$payId,$provider];
 if(($GLOBALS['fail_for']??0)===$payId)throw new RuntimeException('synthetic_unknown_effect');
 $db=$GLOBALS['connection'];
 $db->begin_transaction();
 try {
   $q=$db->prepare("UPDATE pays SET state='paid' WHERE id=? AND state='pending'");
   $q->bind_param('i',$payId);$q->execute();$changed=$q->affected_rows;$q->close();
   if($changed!==1)throw new RuntimeException('duplicate_financial_claim');
   $q=$db->prepare('UPDATE users SET wallet=wallet+100000 WHERE userid=1001');
   $q->execute();$changed=$q->affected_rows;$q->close();
   if($changed!==1)throw new RuntimeException('duplicate_wallet');
   $db->commit();
 } catch(Throwable $error) {$db->rollback();throw $error;}
 return 'delivered';
}
require_once $root.'/src/Finance/ReviewDecisions.php';
function seed($db,$id,$provider,$stage,$status=null,$child=null,$price=100000,$ageSeconds=0) {
 $hash='test-'.$id;$reference=$hash.'-invoice';
 $q=$db->prepare("INSERT INTO pays (id,hash_id,user_id,type,price,request_date,state,payid)
   VALUES (?,?,1001,'INCREASE_WALLET',?,UNIX_TIMESTAMP(),'pending',?)");
 $q->bind_param('isis',$id,$hash,$price,$reference);$q->execute();$q->close();
 $quote=$provider==='nowpayments'?'1.00000000':'100000.00000000';
 $currency=$provider==='nowpayments'?'usd':'toman';
 $payment=$provider==='nowpayments'?$hash.'-payment':null;
 $q=$db->prepare("INSERT INTO payment_attempts
   (pay_row_id,provider,order_hash,provider_reference,payment_id,quote_amount,
    quote_currency,local_amount_toman,stage,provider_status,created_at)
    VALUES (?,?,?,?,?,?,?,?,?,?,DATE_SUB(NOW(), INTERVAL ? SECOND))");
 $q->bind_param('issssssissi',$id,$provider,$hash,$reference,$payment,
   $quote,$currency,$price,$stage,$status,$ageSeconds);
 $q->execute();$attempt=(int)$db->insert_id;$q->close();
 if($child!==null) {
  $q=$db->prepare("INSERT INTO payment_transactions
     (attempt_id,provider,payment_id,observed_status) VALUES(?,?,?,?)");
  $q->bind_param('isss',$attempt,$provider,$payment,$child);$q->execute();$q->close();
 }
 return $attempt;
}
function state($db,$id){
 $q=$db->prepare('SELECT p.state AS pay_state,p.approved_by,a.stage,a.provider_status
  FROM pays p JOIN payment_attempts a ON a.pay_row_id=p.id WHERE p.id=?');
 $q->bind_param('i',$id);$q->execute();$r=$q->get_result()->fetch_assoc();$q->close();
 return $r;
}
check(achbotGatewayReviewReady($db),'existing_unique_attempt_schema_ready');
$balance=(int)$db->query('SELECT wallet FROM users WHERE userid=1001')->fetch_row()[0];

$a=seed($db,301,'nowpayments','review','confirmed','confirmed');
check(achbotGatewayReviewEligible($db,$a,'approve')!==null,'nowpayments_review_candidate');
check(achbotGatewayReviewCommit($db,9001,$a,'approve')['status']==='completed',
 'original_nowpayments_success');
$r=state($db,301);
check($r['pay_state']==='paid' && $r['stage']==='done' &&
 $r['provider_status']==='operator_fulfilled' && (int)$r['approved_by']===9001,
 'one_shot_original_attempt_and_admin_identity');
check(achbotGatewayReviewCommit($db,9001,$a,'approve')['status']!=='completed',
 'duplicate_approval_refused');

$b=seed($db,302,'zarinpal','review','verification_uncertain');
check(achbotGatewayReviewEligible($db,$b,'reject')!==null,'zarinpal_saved_uncertainty');
check(achbotGatewayReviewCommit($db,9001,$b,'reject')['status']==='rejected',
 'hosted_original_rejection');
$r=state($db,302);
check($r['pay_state']==='declined' && $r['stage']==='done' &&
 $r['provider_status']==='operator_rejected',
 'no_fake_merchant_refund');
check(achbotGatewayReviewEligible($db,$b,'approve')===null,
 'rejected_order_never_reapproved');

$c=seed($db,303,'nowpayments','review','finished','finished');
check(achbotGatewayReviewEligible($db,$c,'reject')===null,
 'funded_invoice_not_declined_without_refund');
$d=seed($db,304,'nextpay','pending',null,null,100000,4000);
check(achbotGatewayReviewEligible($db,$d,'approve')!==null,
 'aged_pending_invoice_can_be_manually_attested');
$e=seed($db,305,'nextpay','pending',null,null,100000,0);
check(achbotGatewayReviewEligible($db,$e,'approve')===null,
 'new_checkout_not_prematurely_reviewed');
$f=seed($db,306,'zarinpal','review','verification_uncertain',null,99999);
check(achbotGatewayReviewEligible($db,$f,'approve')===null,
 'changed_local_amount_fails_closed');
$g=seed($db,307,'nowpayments','review','finished','refunded');
check(achbotGatewayReviewEligible($db,$g,'approve')===null,
 'refunded_original_never_credited');

$h=seed($db,308,'nowpayments','review','confirmed','confirmed');
$GLOBALS['fail_for']=308;
check(achbotGatewayReviewCommit($db,9001,$h,'approve')['status']==='unknown',
 'unknown_original_effect_held');
$r=state($db,308);
check($r['pay_state']==='pending' && $r['stage']==='review' &&
 $r['provider_status']==='operator_fulfillment_unconfirmed',
 'durable_uncertain_hold');
check(achbotGatewayReviewEligible($db,$h,'approve')===null,
 'unknown_hold_not_replayed');

$j=seed($db,309,'nowpayments','review','confirmed','confirmed');
$q=$db->prepare("INSERT INTO payment_transactions
 (attempt_id,provider,payment_id,observed_status)
 VALUES (?,'nowpayments','different-child','finished')");
$q->bind_param('i',$j);$q->execute();$q->close();
check(achbotGatewayReviewEligible($db,$j,'approve')===null,
 'second_funded_child_blocks_single_order_claim');

$balanceAfter=(int)$db->query('SELECT wallet FROM users WHERE userid=1001')->fetch_row()[0];
check($balanceAfter===$balance+100000,
 'only_one_wallet_credit_despite_replays_and_failures');
check(count($GLOBALS['events'])===2,'only_approved_and_failed_original_effects_invoked');
check((int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_review_decisions'")
 ->fetch_row()[0]===0,'no_dedicated_review_table_created');
echo json_encode([
 'complete'=>true,'checks'=>$GLOBALS['checks'],'owned_mariadb'=>true,
 'real_review_decision_source'=>true,'test_effect_is_synthetic'=>true,
 'provider_http_calls'=>0,'telegram_calls'=>0,'audit_exactly_once'=>true,
 'financial_double_credit'=>false,'existing_attempt_only'=>true],
 JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
'''
def main():
    parser=argparse.ArgumentParser()
    parser.add_argument("--run-disposable",action="store_true",required=True)
    parser.add_argument("--report",type=Path)
    args=parser.parse_args()
    with fx.owned_database() as (d,env):
        client=["mariadb","--no-defaults","--protocol=SOCKET",
                "--socket="+str(d/"mysql.sock"),"-uroot",DB]
        for filename in ["003_payment_attempts.sql","007_service_effects.sql"]:
            sql=(ROOT/"migrations/post-v9"/filename).read_bytes()
            result=subprocess.run(client,input=sql,env=env,capture_output=True,timeout=15)
            if result.returncode:raise RuntimeError(result.stderr.decode(errors='replace')[-1400:])
        script=d/"gateway-review-fixture.php"
        script.write_text(PHP)
        proc=subprocess.run(["php","-d","allow_url_fopen=0",
            "-d","disable_functions=curl_init,curl_exec,exec,shell_exec,system,passthru,popen,proc_open,mail",
            str(script),str(ROOT),str(d/"mysql.sock"),DB],
            env=env,capture_output=True,text=True,timeout=50)
        if proc.returncode:
            raise RuntimeError("PHP gateway review fixture: "+
                proc.stderr[-2400:]+proc.stdout[-1200:])
        allowed="achbot_gateway_review_effect_unknown pay_id=308 class=RuntimeException"
        if proc.stderr.strip()!=allowed:
            raise RuntimeError("Unexpected PHP log: "+proc.stderr[-2400:])
        output=json.loads(proc.stdout)
        if not output.get("complete") or len(output["checks"])<16:
            raise RuntimeError("Incomplete original-attempt review checks")
        if args.report:args.report.write_text(json.dumps(output,indent=2,ensure_ascii=False)+"\n")
        print(json.dumps({k:v for k,v in output.items() if k!="checks"}))
if __name__=="__main__":
    main()
