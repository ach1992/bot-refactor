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
// Human decisions use previously recorded signed merchant evidence, without
// a fresh provider call or false 'finished' assertion. A second callback
// cannot charge again, even when original provider status was nonterminal.
$local=seed($db,'owner-confirmed',209);
$db->query("UPDATE payment_transactions SET observed_status='confirmed' WHERE attempt_id=$local");
$walletBefore=(int)$db->query('SELECT wallet FROM users WHERE userid=1001')->fetch_row()[0];
check(achbotNpDecisionEligible($db,$local,null,'approve')!==null,
  'owner_can_attest_saved_nonfinal_provider_evidence');
$localResult=achbotNpDecisionCommit($db,9001,$local,'approve',null);
$localRow=row($db,'owner-confirmed');
check($localResult['status']==='completed' && $localRow['pay_state']==='paid'
  && $localRow['decision']==='approve','owner_direct_approve_runs_original_effect');
check((int)$db->query('SELECT wallet FROM users WHERE userid=1001')->fetch_row()[0]
  ===$walletBefore+100000,'owner_direct_approve_wallet_once');
$detail=$db->query("SELECT provider_status FROM payment_review_decisions
 WHERE pay_row_id=209")->fetch_assoc();
check(($detail['provider_status']??'')==='operator_attested',
 'human_attestation_not_fabricated_provider_finished');
$priorEvents=count($GLOBALS['events']);
check(achbotNpDecisionCommit($db,9001,$local,'approve',null)['status']!=='completed'
 && count($GLOBALS['events'])===$priorEvents,'owner_repeat_callback_no_second_credit');

$reject=seed($db,'owner-reject',210);
$db->query("UPDATE payment_transactions SET observed_status='sending' WHERE attempt_id=$reject");
$beforeReject=(int)$db->query('SELECT wallet FROM users WHERE userid=1001')->fetch_row()[0];
check(achbotNpDecisionEligible($db,$reject,null,'reject')!==null,
  'owner_can_reject_without_provider_refunded');
$rejected=achbotNpDecisionCommit($db,9001,$reject,'reject',null);
check($rejected['status']==='rejected' && row($db,'owner-reject')['pay_state']==='declined',
  'owner_reject_closes_unfulfilled_order');
check((int)$db->query('SELECT wallet FROM users WHERE userid=1001')->fetch_row()[0]
  ===$beforeReject,'owner_reject_no_wallet_credit');
$observed=$db->query("SELECT observed_status FROM payment_transactions
 WHERE attempt_id=$reject")->fetch_assoc();
check(($observed['observed_status']??'')==='sending',
 'owner_rejection_preserves_financial_evidence');

// Another authentic primary reports finished but Owner rejects the ORDER.
// The merchant funds remain an exception until a real refund is observed.
$finishedReject=seed($db,'owner-finished-reject',215);
check(achbotNpDecisionCommit($db,9001,$finishedReject,'reject',null)['status']==='rejected',
  'owner_finished_primary_can_reject_business_without_fake_refund');
check(row($db,'owner-finished-reject')['provider_status']==='operator_rejected',
  'finished_primary_rejection_does_not_forge_provider_refund');
$absent=seed($db,'owner-no-funds',211);
$db->query("UPDATE payment_transactions SET observed_status='waiting' WHERE attempt_id=$absent");
check(achbotNpDecisionEligible($db,$absent,null,'approve')===null
  && achbotNpDecisionEligible($db,$absent,null,'reject')===null,
 'unfunded_invoice_not_accidentally_decidable');

$refunded=seed($db,'owner-refunded',214);
$db->query("UPDATE payment_transactions SET observed_status='refunded'
 WHERE attempt_id=$refunded");
check(achbotNpDecisionEligible($db,$refunded,null,'approve')===null,
 'owner_approve_refunded_only_funds_fails_closed');

$multiple=seed($db,'owner-multi-child',212);
$db->query("INSERT INTO payment_transactions(attempt_id,provider,payment_id,observed_status)
 VALUES ($multiple,'nowpayments','owner-multi-child-extra','confirmed')");
$beforeMulti=(int)$db->query('SELECT wallet FROM users WHERE userid=1001')->fetch_row()[0];
check(achbotNpDecisionCommit($db,9001,$multiple,'approve',null)['status']==='completed',
 'owner_multi_child_decides_original_order_once');
check((int)$db->query('SELECT wallet FROM users WHERE userid=1001')->fetch_row()[0]
  ===$beforeMulti+100000,'owner_multi_child_never_credits_twice');
$childCount=(int)$db->query("SELECT COUNT(*) FROM payment_transactions
 WHERE attempt_id=$multiple")->fetch_row()[0];
check($childCount===2,'additional_child_remains_in_financial_exception_ledger');

$unknown=seed($db,'owner-unknown',213);
$GLOBALS['effect_mode']='throw';
check(achbotNpDecisionCommit($db,9001,$unknown,'approve',null)['status']==='unknown',
 'owner_unknown_original_effect_held');
$beforeRetry=count($GLOBALS['events']);
$GLOBALS['effect_mode']='complete';
check(achbotNpDecisionCommit($db,9001,$unknown,'approve',null)['status']!=='completed'
 && count($GLOBALS['events'])===$beforeRetry,
 'owner_unknown_must_reconcile_not_repeat_effect');

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
  # Both synthetic post-COMMIT failures must emit their exact sanitized
  # correlation codes; never suppress arbitrary stderr or private payloads.
  stderr=proc.stderr.strip()
  allowed='\n'.join([
   'achbot_review_effect_unknown pay_id=203 class=RuntimeException',
   'achbot_review_effect_unknown pay_id=213 class=RuntimeException'])
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
  out['signed_ipn_operator_claim_survived']=True
  out['signed_ipn_postpaid_primary_preserved']=True
  out['signed_ipn_out_of_order_secondary_preserved']=True
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
  assert selected['additional_funds_paid_and_inflight_visible']
  assert selected['rejected_funded_primary_visible_without_reopening']
  assert selected['real_refund_evidence_closes_rejected_primary_exception']
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
function ipn(mysqli $db,string $hash,string $child,string $status): int {
 $payload=['payment_id'=>$child,'invoice_id'=>$hash.'-invoice',
   'order_id'=>$hash,'payment_status'=>$status,
   'price_amount'=>'1','price_currency'=>'usd'];
 $raw=json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
 $canonical=json_encode(achbotNpSortedPayload($payload),
   JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
 return achbotNpIngestIpn($db,'isolated-owned-review-ipn',
   $raw,hash_hmac('sha512',$canonical,'isolated-owned-review-ipn'));
}
// Signed funded child AFTER the operator journal COMMIT, BEFORE its original
// effect executes: no stage or provider-status clobber, no second execution.
if(ipn($connection,'operator-inflight','operator-inflight-extra','confirming')!==200 ||
   ipn($connection,'operator-inflight','operator-inflight-extra','finished')!==200 ||
   ipn($connection,'operator-inflight','operator-inflight-extra','waiting')!==200)
 throw new RuntimeException('signed_operator_inflight_ipn_not_accepted');
$claim=$connection->query("SELECT stage,provider_status FROM payment_attempts
 WHERE order_hash='operator-inflight'")->fetch_assoc();
if(($claim['stage']??'')!=='fulfilling' ||
   ($claim['provider_status']??'')!=='operator_verified_finished')
 throw new RuntimeException('signed_ipn_clobbered_operator_claim');
// Signed post-COMMIT child on a PAID terminal wallet remains independent
// financial evidence and never rearms the paid original.
if(ipn($connection,'approve-pass','approve-pass-second','finished')!==200 ||
   ipn($connection,'approve-pass','approve-pass-second','waiting')!==200)
 throw new RuntimeException('postpaid_signed_ipn_not_accepted');
$paid=$connection->query("SELECT a.stage,a.provider_status,p.state FROM payment_attempts a
 JOIN pays p ON p.id=a.pay_row_id WHERE a.order_hash='approve-pass'")->fetch_assoc();
if(($paid['state']??'')!=='paid' || ($paid['stage']??'')!=='done')
 throw new RuntimeException('signed_ipn_reopened_paid_wallet');
$child=$connection->query("SELECT observed_status FROM payment_transactions
 WHERE payment_id='approve-pass-second'")->fetch_assoc();
if(($child['observed_status']??'')!=='finished')
 throw new RuntimeException('out_of_order_ipn_erased_secondary_funds');
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
 'signed_ipn_operator_claim_survived'=>true,
 'signed_ipn_postpaid_primary_preserved'=>true,
 'signed_ipn_out_of_order_secondary_preserved'=>true,
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
$extra=achbotRecoveryAdditionalFundsCases($db);
$extraIds=array_map(static fn($r)=>(int)$r['id'],$extra);
foreach([201,205,210,215] as $id)
 if(!in_array($id,$extraIds,true))
   throw new RuntimeException('missing_quiet_funded_exception_'.$id);
if(in_array(202,$extraIds,true))
 throw new RuntimeException('refunded_primary_not_unresolved_exception');
foreach([210,215] as $id) {
 $case=achbotRecoveryAdditionalFundsCase($db,$id);
 if(!$case || $case['state']!=='declined' || $case['attempt']['provider']!=='nowpayments'
    || $case['attempt']['provider_status']!=='operator_rejected')
  throw new RuntimeException('rejected_primary_not_owner_inspectable_'.$id);
}
// Simulate a later authenticated refund as a saved UNIQUE merchant-child update.
// Once that primary is genuinely refunded, the exception must close without
// touching the rejected business order or unspent wallet balance.
$refundedAttempt=(int)$db->query('SELECT id FROM payment_attempts WHERE pay_row_id=210')
  ->fetch_row()[0];
$db->query("UPDATE payment_transactions SET observed_status='refunded'
 WHERE attempt_id=".$refundedAttempt." AND BINARY payment_id='owner-reject-child'");
$afterRefundIds=array_map(static fn($r)=>(int)$r['id'],
  achbotRecoveryAdditionalFundsCases($db));
$originalAfterRefund=$db->query('SELECT state FROM pays WHERE id=210')->fetch_row()[0];
if(achbotRecoveryAdditionalFundsCase($db,210)!==null ||
    in_array(210,$afterRefundIds,true) || $originalAfterRefund!=='declined')
 throw new RuntimeException('refund_evidence_must_close_original_financial_exception');
if(achbotRecoveryAdditionalFundsCase($db,215)===null)
 throw new RuntimeException('other_funded_exception_must_remain_open');
if(in_array(202,$extraIds,true))
 throw new RuntimeException('unfunded_not_exception');
$paidExtra=achbotRecoveryAdditionalFundsCase($db,201);
if(!$paidExtra || ($paidExtra['state']??'')!=='paid')
 throw new RuntimeException('terminal_primary_exception_not_inspectable');
echo json_encode(['inbox_only_unfinalized_with_funds_or_receipt'=>true,
  'api_module_loaded_on_first_entry'=>function_exists('achbotApiPaymentReady'),
  'additional_funds_paid_and_inflight_visible'=>true,
  'rejected_funded_primary_visible_without_reopening'=>true,
  'real_refund_evidence_closes_rejected_primary_exception'=>true,
  'examined'=>count($ids)],JSON_THROW_ON_ERROR);
'''
if __name__=='__main__':main()
