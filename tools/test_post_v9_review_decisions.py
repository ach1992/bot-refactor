#!/usr/bin/env python3
"""#117 NOWPayments owner decision state-machine, isolated real PHP+MariaDB.

No merchant, Telegram, Cron, remote CREATE or real payment is contacted.
Exercises source ReviewDecisions.php, authentic amount validator, actual
InnoDB unique/lock boundaries, provider identity, forged/stale decisions,
unknown effect, and one-shot replay prevention. The original service/wallet
effect itself has a separate source-real finance test suite.
"""
from __future__ import annotations
import argparse, json, subprocess
from pathlib import Path

import test_access_state as fx

ROOT=Path(__file__).resolve().parents[1]
DB=fx.NAMES[1]
PHP=r'''<?php
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(static function($level,$text){
  if($level===E_DEPRECATED)return true;throw new ErrorException($text,0,$level);
});
$_SERVER['SCRIPT_FILENAME']=__FILE__;
$root=$argv[1];$db=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$db->set_charset('utf8mb4');$connection=$db;
$GLOBALS['telegramWebhookSecret']='syntheticReviewSecretForOwnedMariaDB2026';
$GLOBALS['events']=[];$checks=[];
function check($condition,$name){
 if(!$condition)throw new RuntimeException('test_failure:'.$name);
 $GLOBALS['checks'][]=$name;
}
function achbotSupportsPaymentSubject($db,$pay){
 return in_array((string)($pay['type']??''),['INCREASE_WALLET','BUY_SUB'],true);
}
function achbotNpChildEvidenceRank($status):int {
 return match((string)$status) {
  'waiting','failed','expired'=>0,'confirming'=>1,'confirmed'=>2,'sending'=>3,
  'partially_paid'=>4,'finished'=>5,'refunded'=>6,default=>0};
}
function doAction($payId,$provider) {
 $GLOBALS['events'][]=['effect',$payId,$provider];
 if($provider!=='nowpayment')throw new RuntimeException('invalid_provider');
 $db=$GLOBALS['connection'];
 $mode=$GLOBALS['effect_mode']??'complete';
 if($mode==='throw')throw new RuntimeException('synthetic_remote_unknown');
 if($mode==='leave_pending')return 'ambiguous';
 $db->begin_transaction();
 try {
  $q=$db->prepare("UPDATE pays SET state='paid'
      WHERE id=? AND state='pending' AND type='INCREASE_WALLET'");
  $q->bind_param('i',$payId);$q->execute();$claimed=$q->affected_rows;$q->close();
  if($claimed!==1)throw new RuntimeException('duplicate_pay');
  $q=$db->prepare('UPDATE users SET wallet=wallet+100000 WHERE userid=1001');
  $q->execute();$changed=$q->affected_rows;$q->close();
  if($changed!==1)throw new RuntimeException('no_wallet');
  $db->commit();
 } catch(Throwable $e) {$db->rollback();throw $e;}
 $GLOBALS['events'][]=['normal_result_report',$payId,'report_channel'];
 return 'delivered';
}
require_once $root.'/src/Finance/ReviewDecisions.php';
function seed(mysqli $db,string $hash,int $id) {
 $q=$db->prepare("INSERT INTO pays
    (id,hash_id,user_id,type,price,request_date,state,payid)
    VALUES (?,?,1001,'INCREASE_WALLET',100000,UNIX_TIMESTAMP(),'pending',?)");
 $invoice=$hash.'-invoice';$q->bind_param('iss',$id,$hash,$invoice);$q->execute();$q->close();
 $q=$db->prepare("INSERT INTO payment_attempts
   (pay_row_id,provider,order_hash,provider_reference,payment_id,quote_amount,
    quote_currency,local_amount_toman,stage,provider_status,minimum_settlement_amount)
   VALUES (?,'nowpayments',?,?,?,1,'usd',100000,'review','partial',0)");
 $child=$hash.'-child';$q->bind_param('isss',$id,$hash,$invoice,$child);$q->execute();
 $a=$db->insert_id;$q->close();
 $q=$db->prepare("INSERT INTO payment_transactions
   (attempt_id,provider,payment_id,observed_status)
   VALUES (?,'nowpayments',?,'finished')");
 $q->bind_param('is',$a,$child);$q->execute();$q->close();
 return (int)$a;
}
function remote(string $hash,string $state='finished'):array {
 return [
  'payment_id'=>$hash.'-child','invoice_id'=>$hash.'-invoice','order_id'=>$hash,
  'payment_status'=>$state,'price_amount'=>'1.00000000','price_currency'=>'usd',
  'pay_amount'=>'1','actually_paid'=>'1','pay_currency'=>'usdttrc20',
  'outcome_amount'=>'0.98','outcome_currency'=>'usdttrc20'];
}
function row(mysqli $db,string $hash):array {
 $q=$db->prepare("SELECT p.state AS pay_state,a.stage,a.provider_status,
         d.decision,d.state AS review_state,d.outcome
  FROM pays p JOIN payment_attempts a ON a.pay_row_id=p.id
  LEFT JOIN payment_review_decisions d ON d.pay_row_id=p.id
  WHERE p.hash_id=?");
 $q->bind_param('s',$hash);$q->execute();$r=$q->get_result()->fetch_assoc();$q->close();
 return $r;
}
check(achbotNpDecisionSchemaReady($db),'decision_schema_unique_innodb');
$a=seed($db,'approve-pass',201);
$beforeWallet=(int)$db->query('SELECT wallet FROM users WHERE userid=1001')->fetch_row()[0];
check(achbotNpDecisionEligible($db,$a,remote('approve-pass'),'approve')!==null,
  'verified_finished_complete_single_child_eligible');
check(achbotNpDecisionEligible($db,$a,remote('approve-pass','confirmed'),'approve')===null,
  'not_finished_must_not_fake_payout');
$wrong=remote('approve-pass');$wrong['order_id']='forged-order';
check(achbotNpDecisionEligible($db,$a,$wrong,'approve')===null,'wrong_order_identity_rejected');
$under=remote('approve-pass');$under['actually_paid']='0.50';
check(achbotNpDecisionEligible($db,$a,$under,'approve')===null,'underpaid_rejected');
check(achbotNpDecisionToken(9001,$a,'approve',1900000000,'abc')!==
      achbotNpDecisionToken(9002,$a,'approve',1900000000,'abc'),
      'decision_token_binds_actor');
check(achbotNpDecisionToken(9001,$a,'approve',1900000000,'abc')!==
      achbotNpDecisionToken(9001,$a,'reject',1900000000,'abc'),
      'decision_token_binds_action');
check(achbotNpDecisionToken(9001,$a,'approve',1900000000,'abc')!==
      achbotNpDecisionToken(9001,$a,'approve',1900000001,'abc'),
      'decision_token_binds_expiry');
check(achbotNpDecisionToken(9001,$a,'approve',1900000000,'abc')!==
      achbotNpDecisionToken(9001,$a,'approve',1900000000,'abd'),
      'decision_token_binds_provider_digest');
$complete=achbotNpDecisionCommit($db,9001,$a,'approve',remote('approve-pass'));
check($complete['status']==='completed','approve_effect_completes');
$r=row($db,'approve-pass');
check($r['pay_state']==='paid'&&$r['stage']==='done'&&
      $r['review_state']==='completed'&&$r['decision']==='approve',
      'exact_ledger_closes_after_effect');
check((int)$db->query('SELECT wallet FROM users WHERE userid=1001')->fetch_row()[0]
      ===$beforeWallet+100000,'exactly_one_wallet_credit');
check(count($GLOBALS['events'])===2&&$GLOBALS['events'][1][0]==='normal_result_report',
      'one_original_business_report');
$again=achbotNpDecisionCommit($db,9001,$a,'approve',remote('approve-pass'));
check($again['status']!=='completed'&&count($GLOBALS['events'])===2,
      'repeated_or_raced_approve_never_dispatches_second_effect');
$a=seed($db,'refund-verified',202);
check(achbotNpDecisionEligible($db,$a,remote('refund-verified','confirmed'),'reject')===null,
  'not_refunded_cannot_be_rejected');
$rejected=achbotNpDecisionCommit($db,9001,$a,'reject',remote('refund-verified','refunded'));
check($rejected['status']==='rejected','refunded_reject_commits');
$r=row($db,'refund-verified');
check($r['pay_state']==='declined'&&$r['stage']==='done'&&$r['review_state']==='completed'
      &&$r['decision']==='reject','refund_audit_terminal');
check(count($GLOBALS['events'])===2,'rejection_cannot_dispatch_service_or_wallet');
$a=seed($db,'ambiguous-effect',203);
$GLOBALS['effect_mode']='throw';
$result=achbotNpDecisionCommit($db,9001,$a,'approve',remote('ambiguous-effect'));
$r=row($db,'ambiguous-effect');
check($result['status']==='unknown'&&$r['stage']==='review'&&
    $r['review_state']==='unknown','ambiguous_remote_fenced_as_unknown');
$prior=count($GLOBALS['events']);$GLOBALS['effect_mode']='complete';
$result=achbotNpDecisionCommit($db,9001,$a,'approve',remote('ambiguous-effect'));
check($result['status']!=='completed'&&count($GLOBALS['events'])===$prior,
 'unknown_remote_effect_never_replayed');
$a=seed($db,'extra-funded-child',204);
$db->query("INSERT INTO payment_transactions(attempt_id,provider,payment_id,observed_status)
 VALUES($a,'nowpayments','extra-funded-child-new','confirmed')");
check(achbotNpDecisionEligible($db,$a,remote('extra-funded-child'),'approve')===null,
 'multiple_funded_child_not_fake_single_invoice');
$inflight=seed($db,'operator-inflight',205);
$db->query("UPDATE payment_attempts SET stage='fulfilling',
 provider_status='operator_verified_finished'
 WHERE id=".$inflight);
$db->query("INSERT INTO payment_review_decisions
 (pay_row_id,attempt_id,decision,actor_id,payment_id,provider_status,
 evidence_sha256,state)
 VALUES(205,".$inflight.",'approve',9001,'operator-inflight-child','finished',
 REPEAT('a',64),'dispatching')");
echo json_encode(['complete'=>true,'checks'=>$checks,
 'owned_mariadb'=>true,'real_review_decision_source'=>true,
 'test_effect_is_synthetic'=>true,'provider_http_calls'=>0,
 'telegram_calls'=>0,'financial_double_credit'=>false,
 'audit_exactly_once'=>true],JSON_THROW_ON_ERROR);
'''
def main():
 p=argparse.ArgumentParser()
 p.add_argument('--run-disposable',action='store_true')
 p.add_argument('--report',type=Path)
 args=p.parse_args()
 if not args.run_disposable:raise SystemExit('Use --run-disposable only')
 with fx.owned_database() as (d,env):
  client=['mariadb','--no-defaults','--protocol=SOCKET',
    '--socket='+str(d/'mysql.sock'),'-uroot',DB]
  for name in ['003_payment_attempts.sql','019_payment_review_decisions.sql']:
   sql=(ROOT/'migrations/post-v9'/name).read_bytes()
   subprocess.run(client,input=sql,env=env,capture_output=True,check=True,timeout=15)
  driver=d/'review-decisions.php';driver.write_text(PHP)
  proc=subprocess.run(['php','-d','allow_url_fopen=0',
      '-d','disable_functions=curl_init,curl_exec,exec,shell_exec,system,passthru,popen,proc_open,mail',
      str(driver),str(ROOT),str(d/'mysql.sock'),DB],
      env=env,capture_output=True,text=True,timeout=45)
  # The synthetic post-COMMIT failure must produce one sanitized
  # correlation code, never a raw payment secret or payload.
  stderr=proc.stderr.strip()
  allowed='achbot_review_effect_unknown pay_id=203 class=RuntimeException'
  if proc.returncode or (stderr and stderr!=allowed):
   raise RuntimeError(proc.stderr[-2500:]+' '+proc.stdout[-1000:])
  out=json.loads(proc.stdout)
  worker=d/'review-cron-worker.php';worker.write_text(WORKER)
  cron=subprocess.run(['php','-d','disable_functions=curl_init,curl_exec',
      str(worker),str(ROOT),str(d/'mysql.sock'),DB],
      env=env,capture_output=True,text=True,timeout=15)
  if cron.returncode or cron.stderr:
   raise RuntimeError('cron operator race: '+cron.stderr[-1800:])
  assert json.loads(cron.stdout)['cron_operator_claim_survived']
  assert json.loads(cron.stdout)['internal_payment_page_suppressed']
  out['cron_operator_claim_survived']=True
  out['internal_payment_page_suppressed']=True
  inbox=d/'review-inbox.php';inbox.write_text(INBOX)
  inc=subprocess.run(['php','-d','disable_functions=curl_init,curl_exec',
      str(inbox),str(ROOT),str(d/'mysql.sock'),DB],
      env=env,capture_output=True,text=True,timeout=15)
  if inc.returncode or inc.stderr:
   raise RuntimeError('review inbox selection: '+inc.stderr[-1500:])
  selected=json.loads(inc.stdout)
  assert selected['inbox_only_unfinalized_with_funds_or_receipt']
  assert selected['api_module_loaded_on_first_entry']
  out.update(selected)
 if args.report:args.report.write_text(json.dumps(out,ensure_ascii=False,indent=2)+'\n')
 print(json.dumps({k:v for k,v in out.items() if k!='checks'}))
WORKER=r'''<?php
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(static function($l,$t){if($l===E_DEPRECATED)return true;throw new ErrorException($t,0,$l);});
$_SERVER['SCRIPT_FILENAME']=__FILE__;
$connection=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$connection->set_charset('utf8mb4');
define('ACHBOT_NP_RECONCILE_ONLY',true);
require_once $argv[1].'/src/Finance/GatewayReturn.php';
$res=achbotNpReconcileBatch($connection,['nowpayment'=>'offline-synthetic'],5);
$q=$connection->query("SELECT a.stage,a.provider_status,p.state FROM payment_attempts a
 JOIN pays p ON p.id=a.pay_row_id WHERE a.order_hash='operator-inflight'");
$row=$q->fetch_assoc();
if(!$row || $row['stage']!=='fulfilling' ||
   $row['provider_status']!=='operator_verified_finished' ||
   $row['state']!=='pending' || $res['completed']!==0 || $res['review']!==0)
 throw new RuntimeException('concurrent_cron_clobbered_operator_fulfillment');
ob_start();
showForm('internal-agent-must-not-render','internal',true,'review');
$html=ob_get_clean();
if($html!=='' || headers_list())
 throw new RuntimeException('internal_payment_page_leaked_to_webhook');
echo json_encode(['cron_operator_claim_survived'=>true,
 'internal_payment_page_suppressed'=>true],JSON_THROW_ON_ERROR);
'''
INBOX=r'''<?php
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(static function($l,$t){if($l===E_DEPRECATED)return true;throw new ErrorException($t,0,$l);});
$_SERVER['SCRIPT_FILENAME']=__FILE__;
$root=$argv[1];$db=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$db->set_charset('utf8mb4');
require_once $root.'/src/Finance/RecoveryInspection.php';
// An authentically verified hosted charge can be pending local finalization.
$db->query("INSERT INTO pays(id,hash_id,user_id,type,price,request_date,state)
    VALUES(206,'nextpay-funds',1001,'INCREASE_WALLET',100000,UNIX_TIMESTAMP(),'pending'),
          (207,'manual-evidence',1001,'BUY_SUB',100000,UNIX_TIMESTAMP(),'have_sent'),
          (208,'np-unfunded',1001,'INCREASE_WALLET',100000,UNIX_TIMESTAMP(),'pending')");
$db->query("UPDATE pays SET message_id=112,chat_id=9001 WHERE id=207");
$db->query("INSERT INTO payment_attempts
   (pay_row_id,provider,order_hash,quote_amount,quote_currency,
    local_amount_toman,stage,provider_status)
    VALUES(206,'nextpay','nextpay-funds',100000,'irr',100000,'review','fulfillment_started'),
          (207,'bank_transfer','manual-evidence',100000,'irr',100000,'review','receipt_pending'),
          (208,'nowpayments','np-unfunded',1,'usd',100000,'review','waiting')");
$rows=achbotRecoveryAttentionCases($db);
$ids=array_map(static fn(array $r)=>(int)$r['id'],$rows);
foreach([203,204,205,206,207] as $id)
 if(!in_array($id,$ids,true))throw new RuntimeException('missing_funded_review_'.$id);
foreach([201,202,208] as $id)
 if(in_array($id,$ids,true))throw new RuntimeException('wrong_review_inclusion_'.$id);
echo json_encode(['inbox_only_unfinalized_with_funds_or_receipt'=>true,
  'api_module_loaded_on_first_entry'=>function_exists('achbotApiPaymentReady'),
  'examined'=>count($ids)],JSON_THROW_ON_ERROR);
'''
if __name__=='__main__':main()
