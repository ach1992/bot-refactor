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
require_once $root.'/src/Finance/GatewayReview.php';
require_once $root.'/src/Finance/ServiceEffects.php';
require_once $root.'/src/Finance/DeliveryLedger.php';
$GLOBALS['telegramWebhookSecret']='AChBotTestSecretFixtureOnly_123';
$GLOBALS['qr_sends']=0;
function sendQrFromLink(...$args) {$GLOBALS['qr_sends']++;return true;}
function reserveEffect($db,$payId,$ordinal,$label) {
 $label.='_'.$payId;
 $operation=bin2hex(random_bytes(16));
 $endpoint=hash('sha256','fixture-endpoint');
 $digest=hash('sha256',$label);
 $q=$db->prepare("INSERT INTO service_effects
  (pay_row_id,ordinal,operation_id,effect_kind,target_server_id,target_key,
   endpoint_sha256,plan_id,remote_username,requested_expire,requested_bytes,
   request_sha256,reserved_count,state)
  VALUES (?,?,?,'create',2,'server:2',?,7,?,1800000000,1073741824,?,0,'reserved')");
 $q->bind_param('iissss',$payId,$ordinal,$operation,$endpoint,$label,$digest);
 $q->execute();$id=(int)$db->insert_id;$q->close();
 return $id;
}
function stalePaid($db,$payId,$attemptId) {
 $db->query("UPDATE pays SET state='paid' WHERE id=$payId");
 $db->query("UPDATE payment_attempts SET updated_at=NOW()-INTERVAL 11 MINUTE
  WHERE id=$attemptId");
}
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
check(achbotGatewayReviewCommit($db,9001,$a,'approve',9001)['status']==='completed',
 'original_nowpayments_success');
$r=state($db,301);
check($r['pay_state']==='paid' && $r['stage']==='done' &&
 $r['provider_status']==='operator_fulfilled' && (int)$r['approved_by']===9001,
 'one_shot_original_attempt_and_admin_identity');
check(achbotGatewayReviewCommit($db,9001,$a,'approve',9001)['status']!=='completed',
 'duplicate_approval_refused');

$b=seed($db,302,'zarinpal','review','verification_uncertain');
check(achbotGatewayReviewEligible($db,$b,'reject')!==null,'zarinpal_saved_uncertainty');
check(achbotGatewayReviewCommit($db,9001,$b,'reject',9001)['status']==='rejected',
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
check(achbotGatewayReviewCommit($db,9001,$h,'approve',9001)['status']==='unknown',
 'unknown_original_effect_held');
$r=state($db,308);
check($r['pay_state']==='pending' && $r['stage']==='review' &&
 $r['provider_status']==='operator_fulfillment_unconfirmed',
 'durable_uncertain_hold');
check(achbotGatewayReviewEligible($db,$h,'approve')===null,
 'unknown_hold_not_replayed');
check(achbotGatewayReviewArchive($db,9001,$h,9001)==='archived',
 'previously_unknown_effect_can_be_archived_without_replaying');
$held=$db->query("SELECT review_archived_by,review_archived_at,
 stage,provider_status FROM payment_attempts WHERE id=$h")->fetch_assoc();
check((int)$held['review_archived_by']===9001 &&
 is_string($held['review_archived_at']) && $held['review_archived_at']!=='' &&
 $held['stage']==='review' &&
 $held['provider_status']==='operator_fulfillment_unconfirmed' &&
 state($db,308)['pay_state']==='pending',
 'original_uncertain_payment_untouched_when_archived');
check(achbotGatewayReviewArchive($db,9002,$h,9001)==='stale',
 'archival_is_once_only');
check(achbotGatewayReviewEligible($db,$h,'approve')===null &&
 achbotGatewayReviewEligible($db,$h,'reject')===null,
 'archived_original_never_gets_new_financial_buttons');
$oldArchivedAt=$held['review_archived_at'];

$k=seed($db,310,'nowpayments','review','confirmed','confirmed');
check(achbotGatewayReviewArchive($db,9002,$k,9001)==='archived',
 'fresh_open_case_can_be_archived_without_claiming_funds');
$before=$db->query("SELECT review_archived_by,review_archived_at FROM payment_attempts
 WHERE id=$k")->fetch_assoc();
check((int)$before['review_archived_by']===9002 &&
 $before['review_archived_at']!==null &&
 state($db,310)['pay_state']==='pending',
 'original_quote_and_payment_remain_unsettled');
check(achbotGatewayReviewCommit($db,9001,$k,'approve',9001)['status']==='stale' &&
 achbotGatewayReviewCommit($db,9001,$k,'reject',9001)['status']==='stale',
 'late_approve_reject_cannot_change_archived_original');
$after=$db->query("SELECT review_archived_by,review_archived_at
 FROM payment_attempts WHERE id=$k")->fetch_assoc();
check($after===$before && state($db,310)['stage']==='review' &&
 state($db,310)['provider_status']==='confirmed' &&
 achbotGatewayReviewEligible($db,$k,'approve')===null &&
 achbotGatewayReviewArchive($db,9001,$k,9001)==='stale',
 'archive_keeps_original_financial_state_and_cannot_be_reopened');

$m=seed($db,311,'nowpayments','review','fulfillment_failed');
$db->query("UPDATE pays SET state='processing' WHERE id=311");
$db->query("UPDATE payment_attempts SET updated_at=NOW()-INTERVAL 11 MINUTE
 WHERE id=$m");
check(achbotGatewayReviewArchive($db,9001,$m,9001)==='archived',
 'stale_processing_case_can_be_closed_from_attention_only');
check(state($db,311)['pay_state']==='processing' &&
 state($db,311)['provider_status']==='fulfillment_failed',
 'archived_processing_hold_still_requires_native_forensics_elsewhere');

// Immutable historical fingerprints and 018 digest constants are frozen from
// main/RC26, not calculated from the code under test.
$oldPay=[
 'id'=>770,'hash_id'=>'rc26-770','user_id'=>1001,'type'=>'BUY_SUB',
 'plan_id'=>7,'server_id'=>2,'order_id'=>0,'price'=>100000,'day'=>30,
 'volume'=>10,'agent_count'=>2,'description'=>'rc26','agent_bought'=>0,
 'state'=>'paid','payid'=>'merchant-770',
 'issued_entitlement_days'=>30,'issued_entitlement_gb'=>10,
];
$oldAttempt=[
 'id'=>1770,'pay_row_id'=>770,'provider'=>'nextpay','order_hash'=>'rc26-770',
 'quote_amount'=>'100000.00000000','quote_currency'=>'toman',
 'local_amount_toman'=>100000,'provider_reference'=>'merchant-770',
 'payment_id'=>null,'evidence_profile'=>null,'settlement_currency'=>null,
];
$oldEffect=[
 'id'=>2770,'pay_row_id'=>770,'effect_kind'=>'create','ordinal'=>1,
 'state'=>'local_recorded','order_id'=>3770,'reserved_count'=>2,
 'target_server_id'=>2,'plan_id'=>7,'operation_id'=>str_repeat('a',32),
 'request_sha256'=>str_repeat('b',64),'endpoint_sha256'=>str_repeat('c',64),
 'target_key'=>'server:2','remote_username'=>'rc26_user_1',
 'requested_expire'=>1800000000,'requested_bytes'=>10737418240,
];
check(achbotPaymentEvidencePayFingerprint($oldPay)===
 'b164d72900a5cbfa9c5c94960ce09f3982d3b4f463eab14df1cc5151a24822d8',
 'rc26_021_persisted_payment_fingerprint_byte_identical');
check(achbotDeliveryHostedBuyDigest($oldPay,$oldAttempt,$oldEffect,1)===
 '6835fd8869082ce9704afe54b5bb98cd64e296696c0c8253e17dd66bfa3d3444',
 'rc26_nextpay_018_hosted_digest_byte_identical');
$oldAttempt['provider']='zarinpal';
check(achbotDeliveryHostedBuyDigest($oldPay,$oldAttempt,$oldEffect,1)===
 '3d0e9f213220268359467b6ac4709e1c46865a8f4ce20452522d9e86876ed96e',
 'rc26_zarinpal_018_hosted_digest_byte_identical');
$oldReplacement=$oldEffect;
$oldReplacement['effect_kind']='replacement';
$oldReplacement['reserved_count']=0;
$oldReplacement['replaces_effect_id']=2770;
$oldReplacement['reconciliation_fingerprint']=str_repeat('d',64);
$oldReplacement['reconciliation_reason']='original_unavailable';
$oldReplacement['actor_userid']=9001;
check(achbotDeliveryHostedBuyDigest($oldPay,$oldAttempt,$oldReplacement,1)===
 '7d83e9c58c3b575c833a7efb54964d1c2176475fb48b743983bd6a6bad3695ac',
 'rc26_replacement_018_digest_read_only_compatible');
unset($oldPay['issued_entitlement_days'],$oldPay['issued_entitlement_gb']);
check(achbotPaymentEvidencePayFingerprint($oldPay)===
 '84469a36aee3c463e0311374e9d0a4f8cd72c03e934548a186f9092ae4ac8e02',
 'fresh_022_fingerprint_without_021_columns');

// Real sealed 018 packet validation: historical hosted delivery is read-side
// only and sends one original packet without a second Native or payment claim.
function historicalHostedPacket($db,$payId,$provider,$kind='create') {
 $a=seed($db,$payId,$provider,'done','delivered');
 $db->query("UPDATE pays SET state='paid',type='BUY_SUB',plan_id=7,
  server_id=2,agent_count=2,issued_entitlement_days=30,
  issued_entitlement_gb=10 WHERE id=$payId");
 $effect=reserveEffect($db,$payId,1,'rc26_hosted_'.$provider);
 if ($kind==='replacement') {
  $db->query("UPDATE service_effects SET effect_kind='replacement',
    actor_userid=9001,reconciliation_reason='original_unavailable',
    reconciliation_fingerprint=REPEAT('d',64) WHERE id=$effect");
  $original=reserveEffect($db,$payId,1,'historical_original');
  $db->query("UPDATE service_effects SET replaces_effect_id=$original
    WHERE id=$effect");
 }
 $db->query("INSERT INTO orders_list
  (userid,token,transid,fileid,server_id,inbound_id,remark,uuid,protocol,
   expire_date,link,amount,status,date,notif,rahgozar,agent_bought)
  SELECT 1001,'token','',7,2,0,remote_username,'fixture-uuid','vless',
   1800000000,'[]',100,1,'fixture',0,0,0
  FROM service_effects WHERE id=$effect");
 $orderId=(int)$db->insert_id;
 if ($orderId<=0) throw new RuntimeException('historical_order_fixture_missing');
 $db->query("UPDATE service_effects SET state='local_recorded',
  order_id=$orderId,reserved_count=2 WHERE id=$effect");
 $pay=$db->query("SELECT * FROM pays WHERE id=$payId")->fetch_assoc();
 $attempt=$db->query("SELECT * FROM payment_attempts WHERE id=$a")->fetch_assoc();
 $effectRow=$db->query("SELECT * FROM service_effects WHERE id=$effect")->fetch_assoc();
 $digest=achbotDeliveryHostedBuyDigest($pay,$attempt,$effectRow,1);
 if (!is_string($digest)) throw new RuntimeException('historical_digest_unavailable');
 $packet=['kind'=>'qr','qr'=>'fixture-synthetic-qr','caption'=>'Historic RC26 delivery',
  'keys'=>'{}','bot_url'=>'https://example.invalid','uid'=>1001];
 $db->begin_transaction();
 try {
  achbotDeliveryInsertLocked($db,$pay,$attempt,1,$effect,$orderId,2,
    $digest,$effectRow['operation_id'],$effectRow['target_key'],$packet);
  $db->commit();
 } catch(Throwable $error) {$db->rollback();throw $error;}
 return ['attempt'=>$a,'effect'=>$effect,'order'=>$orderId];
}
// Test-only historical 021 columns simulate an already-upgraded RC26 tenant.
// The prospective fresh-install migrations still do not ship 021.
$db->query("ALTER TABLE pays ADD issued_entitlement_days INT UNSIGNED DEFAULT NULL,
 ADD issued_entitlement_gb BIGINT UNSIGNED DEFAULT NULL");
$db->query("ALTER TABLE service_effects
 ADD replaces_effect_id BIGINT UNSIGNED DEFAULT NULL,
 ADD reconciliation_reason VARCHAR(40) DEFAULT NULL,
 ADD reconciliation_fingerprint CHAR(64) DEFAULT NULL");
$np=historicalHostedPacket($db,317,'nextpay');
$zp=historicalHostedPacket($db,318,'zarinpal');
$rep=historicalHostedPacket($db,320,'zarinpal','replacement');
$npDelivered=achbotDeliveryDispatch($db,317,1);
$zpDelivered=achbotDeliveryDispatch($db,318,1);
$replacementDelivered=achbotDeliveryDispatch($db,320,1);
check($npDelivered==='acknowledged' && $zpDelivered==='acknowledged' &&
 $GLOBALS['qr_sends']===3 &&
 $db->query("SELECT COUNT(*) FROM payment_delivery_intents
 WHERE pay_row_id IN (317,318) AND state='acknowledged' AND attempt_count=1")
 ->fetch_row()[0]==='2','historical_hosted_018_once_only_pending_delivery');
check($replacementDelivered==='acknowledged' &&
 $db->query("SELECT state FROM payment_delivery_intents WHERE pay_row_id=320")
 ->fetch_row()[0]==='acknowledged' && $GLOBALS['qr_sends']===3,
 'historical_replacement_018_read_only_delivery');
// Archive must also stop a queued historical packet whose original payment
// has subsequently been archived, without destroying the packet itself.
$pending=historicalHostedPacket($db,321,'nextpay');
$db->query("UPDATE payment_attempts SET review_archived_at=UTC_TIMESTAMP(),
 review_archived_by=9001 WHERE id=".$pending['attempt']);
$db->begin_transaction();
try {
 $p=$db->query('SELECT * FROM pays WHERE id=321 FOR UPDATE')->fetch_assoc();
 $d=$db->query('SELECT * FROM payment_delivery_intents
  WHERE pay_row_id=321 AND ordinal=1 FOR UPDATE')->fetch_assoc();
 $blocked=achbotDeliveryValidateLocked($db,$p,$d);
 $db->rollback();
} catch(Throwable $error) {$db->rollback();throw $error;}
check($blocked===null &&
 $db->query('SELECT state FROM payment_delivery_intents WHERE pay_row_id=321')
 ->fetch_row()[0]==='pending' && $GLOBALS['qr_sends']===3,
 'archived_original_does_not_dispatch_sealed_018_packet');

// Permission must be current inside the write transaction, not only at UI admission.
$revoked=seed($db,312,'nowpayments','review','confirmed','confirmed');
check(achbotAdminCanFor($db,9002,9001,'payments'),
 'delegated_staff_initially_allowed');
$db->query('UPDATE users SET isAdmin=0 WHERE userid=9002');
check(achbotGatewayReviewCommit($db,9002,$revoked,'approve',9001)['status']==='denied' &&
 achbotGatewayReviewArchive($db,9002,$revoked,9001)==='denied' &&
 state($db,312)['pay_state']==='pending',
 'revoked_staff_cannot_finalize_old_callback');
$db->query("UPDATE users SET isAdmin=1,step='banned' WHERE userid=9002");
check(achbotGatewayReviewArchive($db,9002,$revoked,9001)==='denied',
 'banned_staff_cannot_archive');
$db->query("UPDATE users SET isAdmin=1,step='none' WHERE userid=9002");
check(achbotGatewayReviewArchive($db,9002,$revoked,9001)==='archived',
 'authorized_staff_can_archive_after_role_restored');

// A missing/incorrect unique original-attempt index must never close one of
// several rows or appear to finish the actual payment.
$broken=seed($db,313,'zarinpal','review','verification_uncertain');
$db->query('ALTER TABLE payment_attempts ADD INDEX idx_fixture_pay_row(pay_row_id)');
$db->query('ALTER TABLE payment_attempts DROP INDEX uq_payment_attempt_pay_row');
check(!achbotGatewayReviewReady($db) &&
 achbotGatewayReviewArchive($db,9001,$broken,9001)==='unavailable',
 'archive_denies_missing_original_unique_index');
$db->query("INSERT INTO payment_attempts
  (pay_row_id,provider,order_hash,provider_reference,quote_amount,quote_currency,
   local_amount_toman,stage,provider_status)
  SELECT pay_row_id,provider,CONCAT(order_hash,'-other'),
   CONCAT(provider_reference,'-other'),quote_amount,
   quote_currency,local_amount_toman,stage,provider_status
  FROM payment_attempts WHERE id=$broken");
check(achbotGatewayReviewArchive($db,9001,$broken,9001)==='unavailable' &&
 (int)$db->query('SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id=313')->fetch_row()[0]===2,
 'archive_denies_duplicate_original_attempts');
$db->query("DELETE FROM payment_attempts WHERE pay_row_id=313 AND id<>$broken");
$db->query('ALTER TABLE payment_attempts ADD UNIQUE KEY uq_payment_attempt_pay_row(pay_row_id)');
$db->query('ALTER TABLE payment_attempts DROP INDEX idx_fixture_pay_row');

// True reservation is not an external send. Archive may close it; later
// dispatch admission must fail and cannot call either Native provider.
$reserved=seed($db,314,'nowpayments','review','fulfillment_failed');
stalePaid($db,314,$reserved);
$first=reserveEffect($db,314,1,'native_first_reserved');
check(achbotGatewayReviewArchive($db,9001,$reserved,9001)==='archived',
 'archive_closes_not_dispatched_first_line');
try {achbotEffectAdmitPaidDispatch($db,$first);$denied=false;}
catch(RuntimeException $error) {$denied=$error->getMessage()==='effect_original_archived';}
check($denied &&
 $db->query("SELECT state FROM service_effects WHERE id=$first")->fetch_row()[0]==='reserved',
 'archived_first_line_never_admitted_for_remote_create');

// The original line was completed; a later reserved line must still be
// blocked after Archive, without duplicating first-line Native effects.
$multi=seed($db,315,'nowpayments','review','fulfillment_failed');
stalePaid($db,315,$multi);
$db->query('UPDATE pays SET agent_count=2 WHERE id=315');
$old=reserveEffect($db,315,1,'native_first_recorded');
$db->query("UPDATE service_effects SET state='local_recorded',order_id=998876
 WHERE id=$old");
$later=reserveEffect($db,315,2,'native_later_reserved');
check(achbotGatewayReviewArchive($db,9001,$multi,9001)==='archived',
 'archive_closes_reserved_multi_account_followup');
try {achbotEffectAdmitPaidDispatch($db,$later);$denied=false;}
catch(RuntimeException $error) {$denied=$error->getMessage()==='effect_original_archived';}
check($denied &&
 $db->query("SELECT state FROM service_effects WHERE id=$later")->fetch_row()[0]==='reserved',
 'archived_later_line_never_admitted_for_remote_create');

// Persisted dispatching and verified rows may outlive the remote worker.
// Archive must close the original AFTER worker termination without changing
// the 007 evidence, paying again, or pretending the remote account is absent.
$admitted=seed($db,316,'zarinpal','review','fulfillment_failed');
stalePaid($db,316,$admitted);
$active=reserveEffect($db,316,1,'native_already_admitted');
achbotEffectAdmitPaidDispatch($db,$active);
check($db->query("SELECT state FROM service_effects WHERE id=$active")->fetch_row()[0]==='dispatching' &&
 achbotGatewayReviewArchive($db,9001,$admitted,9001)==='archived',
 'stale_dispatching_original_reaches_terminal_archive_without_replay');
check($db->query("SELECT state FROM service_effects WHERE id=$active")->fetch_row()[0]==='dispatching' &&
 achbotGatewayReviewArchive($db,9001,$admitted,9001)==='stale',
 'terminal_archive_immutable_stale_dispatch_evidence_retained');
$verified=seed($db,325,'nowpayments','review','fulfillment_failed');
stalePaid($db,325,$verified);
$verifiedEffect=reserveEffect($db,325,1,'native_verified_unlinked');
$db->query("UPDATE service_effects SET state='verified',verified_at=NOW()
 WHERE id=$verifiedEffect");
check(achbotGatewayReviewArchive($db,9001,$verified,9001)==='archived' &&
 $db->query("SELECT state FROM service_effects WHERE id=$verifiedEffect")->fetch_row()[0]==='verified',
 'verified_unlinked_original_reaches_archive_without_order_synthesis');
// A live same-session dispatch lease, unlike a stale database journal, must
// still prevent terminal Archive until the worker releases its connection.
$live=seed($db,324,'nextpay','review','fulfillment_failed');
stalePaid($db,324,$live);
$liveEffect=reserveEffect($db,324,1,'native_active_lease');
achbotEffectAdmitPaidDispatch($db,$liveEffect);

// Valid persisted near-5000-character description and five long Native
// evidence rows must not hide the original review actions in Telegram.
$long=seed($db,326,'nowpayments','review','fulfillment_failed');
stalePaid($db,326,$long);
$description=mb_substr(str_repeat('شرح <>& 😀 و توضیح فارسی ',350),0,4990,'UTF-8');
$q=$db->prepare("UPDATE pays SET type='BUY_SUB',agent_count=5,
 description=?,plan_id=7 WHERE id=326");
$q->bind_param('s',$description);$q->execute();$q->close();
$q=$db->prepare("UPDATE users SET name=? WHERE userid=1001");
$longName=str_repeat('نام <>&😀 ',8);
$q->bind_param('s',$longName);$q->execute();$q->close();
for($i=1;$i<=5;$i++) {
 $letter=chr(96+$i);
 $e=reserveEffect($db,326,$i,str_repeat($letter,82));
 $db->query("UPDATE service_effects SET state='dispatching',
   provider_state=REPEAT('u',32) WHERE id=$e");
}
for($i=1;$i<=2;$i++) {
 $q=$db->prepare("INSERT INTO payment_transactions
 (attempt_id,provider,payment_id,observed_status)
 VALUES (?,'nowpayments',?,'unconfirmed')");
 $payment='provider-payment-'.$i.'-'.str_repeat('z',90);
 $q->bind_param('is',$long,$payment);$q->execute();$q->close();
}
$pendingLong=seed($db,327,'nowpayments','pending',null,null,100000,30000);
$q=$db->prepare('UPDATE pays SET description=? WHERE id=327');
$q->bind_param('s',$description);$q->execute();$q->close();

$j=seed($db,309,'nowpayments','review','confirmed','confirmed');
$q=$db->prepare("INSERT INTO payment_transactions
 (attempt_id,provider,payment_id,observed_status)
 VALUES (?,'nowpayments','different-child','finished')");
$q->bind_param('i',$j);$q->execute();$q->close();
check(achbotGatewayReviewEligible($db,$j,'approve')===null,
 'second_funded_child_blocks_single_order_claim');

// Set up the isolated two-session Archive-vs-admission barrier test.
$raceAttempt=seed($db,322,'nextpay','review','fulfillment_failed');
stalePaid($db,322,$raceAttempt);
$raceEffect=reserveEffect($db,322,1,'native_waits_before_dispatch');
check($db->query("SELECT state FROM service_effects WHERE id=$raceEffect")
 ->fetch_row()[0]==='reserved',
 'real_race_fixture_reserved_before_archive');
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
 'financial_double_credit'=>false,'existing_attempt_only'=>true,
 'race_effect_id'=>$raceEffect,'race_attempt_id'=>$raceAttempt,
 'live_effect_id'=>$liveEffect,'live_attempt_id'=>$live,
 'long_case_id'=>326,'pending_case_id'=>327],
 JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
'''
PHP_CASE = r"""<?php
$_SERVER['SCRIPT_FILENAME']=__FILE__;
$root=$argv[1];$socket=$argv[2];$dbName=$argv[3];
$payId=(int)$argv[4];$mode=$argv[5];
$db=new mysqli('localhost','root','',$dbName,0,$socket);
$db->set_charset('utf8mb4');
$connection=$db;$GLOBALS['connection']=$db;
$admin=9001;$from_id=9001;$chat_id=9001;$message_id=73;
$callbackId='fixture-gateway-detail';$botState=[];
$data='attentionCase_'.$payId;
$GLOBALS['case_edits']=[];$GLOBALS['case_sent']=[];
function editText($id,$text,$markup,$mode,$chat) {
 $GLOBALS['case_edits'][]=['text'=>$text,'markup'=>$markup,'parse'=>$mode];
 return (object)['ok'=>($GLOBALS['case_mode']??'')!=='fallback'];
}
function sendMessage($text,$markup,$parse,$chat) {
 $GLOBALS['case_sent'][]=['text'=>$text,'markup'=>$markup,'parse'=>$parse];
 return (object)['ok'=>true];
}
function answerCallbackQuery(...$args) {return true;}
$GLOBALS['case_mode']=$mode;
require $root.'/src/Finance/Routes/AutomaticGatewayReview.php';
$edits=$GLOBALS['case_edits'];$sent=$GLOBALS['case_sent'];
$final=$mode==='fallback' ? ($sent[0]??null):($edits[0]??null);
if (!$final)throw new RuntimeException('case_not_delivered');
$plain=html_entity_decode(strip_tags($final['text']),ENT_QUOTES|ENT_HTML5,'UTF-8');
$chars=preg_match_all('/./us',$plain);
$astral=preg_match_all('/[\x{10000}-\x{10FFFF}]/u',$plain);
$keyboard=json_decode($final['markup'],true,64,JSON_THROW_ON_ERROR);
$callbacks=[];
foreach($keyboard['inline_keyboard'] as $line)
 foreach($line as $button)$callbacks[]=$button['callback_data'];
echo json_encode([
 'complete'=>true,'units'=>$chars+$astral,
 'max'=>4096,'valid_utf8'=>preg_match('//u',$plain)===1,
 'balanced_html'=>substr_count($final['text'],'<code>')===
   substr_count($final['text'],'</code>'),
 'truncated'=>str_contains($plain,'بخش‌هایی از متن نمایشی طولانی کوتاه'),
 'description_too_long_unmodified_in_storage'=>
   (int)$db->query("SELECT CHAR_LENGTH(description) FROM pays WHERE id=$payId")->fetch_row()[0]>=4900,
 'text'=>$plain,'callbacks'=>$callbacks,
 'edit_count'=>count($edits),'send_count'=>count($sent),'parse'=>$final['parse']
],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
"""

PHP_RACE = r"""<?php
$_SERVER['SCRIPT_FILENAME']=__FILE__;
$root=$argv[1];$socket=$argv[2];$dbName=$argv[3];
$effectId=(int)$argv[4];$attemptId=(int)$argv[5];$mode=$argv[6];
$ready=$argv[7];$release=$argv[8];
$db=new mysqli('localhost','root','',$dbName,0,$socket);
$db->set_charset('utf8mb4');
function achbotPayUxHostedAttentionSeconds(){return 3600;}
require_once $root.'/src/Finance/GatewayReview.php';
require_once $root.'/src/Finance/ServiceEffects.php';
if ($mode==='hold') {
 $q=$db->prepare('SELECT pay_row_id FROM service_effects WHERE id=?');
 $q->bind_param('i',$effectId);$q->execute();
 $pay=$q->get_result()->fetch_assoc();$q->close();
 if (!$pay || (int)$pay['pay_row_id']<=0)
  throw new RuntimeException('no_fixture_hosted_owner');
 $name='achbot-hosted-payment-'.(int)$pay['pay_row_id'];
 $q=$db->prepare('SELECT GET_LOCK(?,0) AS acquired');
 $q->bind_param('s',$name);$q->execute();
 $acquired=$q->get_result()->fetch_assoc();$q->close();
 if ((int)($acquired['acquired']??0)!==1)
  throw new RuntimeException('effect_live_lock_unavailable');
 file_put_contents($ready,'live_worker_owns_dispatch_lease');
 $limit=microtime(true)+15;
 while (!file_exists($release) && microtime(true)<$limit) usleep(10000);
 // Termination/crash also releases this session lock automatically.
 $result='worker_finished';
} elseif ($mode==='admit') {
 file_put_contents($ready,'reserved_before_http');
 $limit=microtime(true)+15;
 while (!file_exists($release) && microtime(true)<$limit) usleep(10000);
 if (!file_exists($release)) throw new RuntimeException('barrier_release_timeout');
 try {achbotEffectAdmitPaidDispatch($db,$effectId);$result='admitted';}
 catch(Throwable $error) {$result=$error->getMessage();}
} elseif ($mode==='archive') {
 $result=achbotGatewayReviewArchive($db,9001,$attemptId,9001);
} else throw new RuntimeException('invalid_race_role');
echo json_encode(['result'=>$result],JSON_THROW_ON_ERROR);
"""

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument("--run-disposable",action="store_true",required=True)
    parser.add_argument("--report",type=Path)
    args=parser.parse_args()
    with fx.owned_database() as (d,env):
        client=["mariadb","--no-defaults","--protocol=SOCKET",
                "--socket="+str(d/"mysql.sock"),"-uroot",DB]
        for filename in ["003_payment_attempts.sql","007_service_effects.sql", "018_payment_delivery_intents.sql", "022_gateway_review_archive.sql"]:
            sql=(ROOT/"migrations/post-v9"/filename).read_bytes()
            result=subprocess.run(client,input=sql,env=env,capture_output=True,timeout=15)
            if result.returncode:raise RuntimeError(result.stderr.decode(errors='replace')[-1400:])
        # Current 012 provider schema has a nullable 007 status display field.
        # Only that read-only field is necessary for the isolated case fixture.
        provider_col=subprocess.run(client+["-e",
            "ALTER TABLE service_effects ADD provider_state VARCHAR(32) DEFAULT NULL"],
            env=env,capture_output=True,text=True,timeout=10)
        if provider_col.returncode:raise RuntimeError(provider_col.stderr)
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
        # The actual source gate waits after a durable line reservation.
        # Another connection Archives while the worker is paused before
        # dispatch. The resumed worker must not admit a new Native operation.
        race_script=d/"gateway-race.php"
        race_script.write_text(PHP_RACE)
        ready=d/"race-ready";release=d/"race-release"
        common=["php","-d","allow_url_fopen=0",
            "-d","disable_functions=curl_init,curl_exec,exec,shell_exec,system,passthru,popen,proc_open,mail",
            str(race_script),str(ROOT),str(d/"mysql.sock"),DB,
            str(output["race_effect_id"]),str(output["race_attempt_id"])]
        worker=subprocess.Popen(common+["admit",str(ready),str(release)],
            env=env,cwd=ROOT,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
        try:
            import time
            until=time.monotonic()+8
            while not ready.exists() and time.monotonic()<until:
                if worker.poll() is not None: raise RuntimeError("Admission worker exited early")
                time.sleep(0.02)
            if not ready.exists():raise RuntimeError("Admission barrier missing")
            archived=subprocess.run(common+["archive",str(ready),str(release)],
                env=env,cwd=ROOT,capture_output=True,text=True,timeout=10)
            if archived.returncode or archived.stderr or json.loads(archived.stdout)["result"]!="archived":
                raise RuntimeError("Concurrent Archive did not commit: "+archived.stderr+archived.stdout)
            release.write_text("go")
            stdout,stderr=worker.communicate(timeout=12)
            if worker.returncode or stderr or json.loads(stdout)["result"]!="effect_original_archived":
                raise RuntimeError("Post-Archive Native admission was not denied: "+stderr+stdout)
            check=subprocess.run(client+["-e","SELECT state FROM service_effects WHERE id="+str(output["race_effect_id"])],
                env=env,capture_output=True,text=True,timeout=5)
            if check.returncode or "reserved" not in check.stdout:
                raise RuntimeError("Archive race changed reserved original effect")
        finally:
            if worker.poll() is None:
                worker.terminate()
                try:worker.wait(timeout=3)
                except subprocess.TimeoutExpired:worker.kill();worker.wait(timeout=3)
        # A second real MariaDB session holds the dispatch lease while its
        # worker is alive. Archive must report BUSY, not terminal success.
        # Killing only our own synthetic worker proves that session teardown
        # releases the lease and a stale 007 dispatching row can be archived.
        liveReady=d/"live-ready";liveRelease=d/"live-release"
        live=["php","-d","allow_url_fopen=0",
            "-d","disable_functions=curl_init,curl_exec,exec,shell_exec,system,passthru,popen,proc_open,mail",
            str(race_script),str(ROOT),str(d/"mysql.sock"),DB,
            str(output["live_effect_id"]),str(output["live_attempt_id"])]
        liveWorker=subprocess.Popen(live+["hold",str(liveReady),str(liveRelease)],
            env=env,cwd=ROOT,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
        try:
            until=time.monotonic()+8
            while not liveReady.exists() and time.monotonic()<until:
                if liveWorker.poll() is not None:
                    raise RuntimeError("Live dispatch lease worker exited early")
                time.sleep(0.02)
            if not liveReady.exists():raise RuntimeError("Live lease barrier missing")
            blocked=subprocess.run(live+["archive",str(liveReady),str(liveRelease)],
                env=env,cwd=ROOT,capture_output=True,text=True,timeout=10)
            if blocked.returncode or blocked.stderr or json.loads(blocked.stdout)["result"]!="busy":
                raise RuntimeError("True active dispatch was not guarded: "+blocked.stderr+blocked.stdout)
            liveWorker.terminate()
            liveWorker.communicate(timeout=8)
            closed=subprocess.run(live+["archive",str(liveReady),str(liveRelease)],
                env=env,cwd=ROOT,capture_output=True,text=True,timeout=10)
            if closed.returncode or closed.stderr or json.loads(closed.stdout)["result"]!="archived":
                raise RuntimeError("Stale dispatch remained unarchivable: "+closed.stderr+closed.stdout)
            verify=subprocess.run(client+["-N","-e",
                "SELECT CONCAT(p.state,'|',a.stage,'|',a.provider_status,'|',e.state,'|',"
                "IFNULL(e.order_id,'NULL'),'|',IF(a.review_archived_at IS NULL,'open','archived')) "
                "FROM pays p JOIN payment_attempts a ON a.pay_row_id=p.id "
                "JOIN service_effects e ON e.pay_row_id=p.id "
                "WHERE e.id="+str(output["live_effect_id"])],
                env=env,capture_output=True,text=True,timeout=5)
            if verify.returncode or verify.stdout.strip()!="paid|review|fulfillment_failed|dispatching|NULL|archived":
                raise RuntimeError("Archive modified original money/Native evidence: "+verify.stdout+verify.stderr)
            output["checks"].extend([
                "true_active_native_dispatch_refuses_archive_without_mutation",
                "worker_exit_releases_session_lease_and_allows_terminal_archive",
                "unknown_native_effect_and_original_financial_status_immutable_after_archive",
            ])
        finally:
            if liveWorker.poll() is None:
                liveWorker.terminate()
                try:liveWorker.wait(timeout=3)
                except subprocess.TimeoutExpired:liveWorker.kill();liveWorker.wait(timeout=3)
        # Execute the ACTUAL Telegram case route, with the real original
        # 003/007 rows and a near-maximal persisted description. Use the same
        # full HTML body and buttons for edit success and send fallback.
        case_script=d/"gateway-case.php"
        case_script.write_text(PHP_CASE)
        for pay_id,mode in [(output["long_case_id"],"edit"),
                            (output["long_case_id"],"fallback"),
                            (output["pending_case_id"],"edit")]:
            result=subprocess.run(["php","-d","allow_url_fopen=0",
                "-d","disable_functions=curl_init,curl_exec,exec,shell_exec,system,passthru,popen,proc_open,mail",
                str(case_script),str(ROOT),str(d/"mysql.sock"),DB,str(pay_id),mode],
                env=env,cwd=ROOT,capture_output=True,text=True,timeout=25)
            if result.returncode or result.stderr:
                raise RuntimeError("Actual Telegram case route failed: "+
                    result.stderr[-2400:]+result.stdout[-1200:])
            case=json.loads(result.stdout)
            if not (case["complete"] and case["valid_utf8"] and
                    case["balanced_html"] and case["truncated"] and
                    case["description_too_long_unmodified_in_storage"] and
                    0<case["units"]<=4096 and case["parse"]=="HTML" and
                    "attentionInbox" in case["callbacks"]):
                raise RuntimeError("Bounded case failed: "+str(case)[:1000])
            if pay_id==output["long_case_id"]:
                if not (any(key.startswith("gatewayReview_H_") for key in case["callbacks"]) and
                        all(part in case["text"] for part in [
                            "شناسه عددی مشتری", "مبلغ سفارش", "شناسه صورتحساب",
                            "آخرین وضعیت","عملیات سرویس", "اثر", "ردیف 5"])):
                    raise RuntimeError("Case lost original/Native evidence: "+str(case)[:1000])
            else:
                if not (all(any(key.startswith(prefix) for key in case["callbacks"])
                            for prefix in ("gatewayReview_A_","gatewayReview_R_","gatewayReview_H_"))):
                    raise RuntimeError("Original approve/reject/archive buttons missing")
            if mode=="fallback":
                if case["edit_count"]!=1 or case["send_count"]!=1:
                    raise RuntimeError("Edit failure did not send one bounded fallback")
            elif case["edit_count"]!=1 or case["send_count"]!=0:
                raise RuntimeError("Edit successful but fallback was incorrectly used")
        output["checks"].extend([
            "maximal_original_description_kept_in_database_html_case_bounded",
            "five_native_lines_and_original_payment_evidence_kept",
            "real_telegram_edit_and_fallback_both_deliver_legal_html_text",
            "approve_reject_archive_back_buttons_survive_original_case_budget",
        ])
        if not output.get("complete") or len(output["checks"])<16:
            raise RuntimeError("Incomplete original-attempt review checks")
        if args.report:args.report.write_text(json.dumps(output,indent=2,ensure_ascii=False)+"\n")
        print(json.dumps({k:v for k,v in output.items() if k!="checks"}))
if __name__=="__main__":
    main()
