<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/../Panel/ProviderStorage.php';
require_once __DIR__.'/../Telegram/Response.php';
require_once __DIR__.'/PaymentEvidence.php';
require_once __DIR__.'/FollowupOutbox.php';
require_once __DIR__.'/../Admin/Access.php';

/** Actor- and exact-version-bound duplicate-risk preview, not authorization. */
function achbotFollowupRedriveToken(array $r,int $actor): string {
    if ($actor<=0 || !achbotFollowupJournaled($r) || (int)$r['id']<=0 ||
        !in_array($r['state'],['rejected','unknown'],true) ||
        !preg_match('/^[a-f0-9]{64}$/D',(string)$r['payload_sha256']))
        throw new RuntimeException('followup_redrive_preview_invalid');
    return substr(hash_hmac('sha256',implode('|',['service-followup-redrive-v1',
        $actor,(int)$r['id'],(int)$r['attempt_count'],$r['state'],$r['payload_sha256']]),
        achbotPaymentEvidenceKey()),0,20);
}

/** The existing sender holds the original source/intent lock. Every new
 * attempt is committed with the original source's unchanged packet. */
function achbotFollowupAttemptClaimLocked(mysqli $db,array $r,int $actor,?string $confirmation): array {
    $id=(int)$r['id'];$count=(int)$r['attempt_count'];$state=$r['state'];
    if (!achbotFollowupJournaled($r) || !array_key_exists('next_attempt_at',$r))
        throw new RuntimeException('followup_attempt_schema_missing');
    $last=$count>0?achbotProviderRow($db,'SELECT * FROM payment_followup_attempts
        WHERE intent_id=? AND attempt_no=? FOR UPDATE','ii',[$id,$count]):null;
    if ($actor>0) {
        if (!in_array($state,['unknown','rejected'],true) || !is_string($confirmation))
            return ['status'=>'stale'];
        $primary=(int)($GLOBALS['admin']??0);
        if ($primary<=0 || !achbotAdminCanLocked($db,$actor,$primary,'payments')) return ['status'=>'denied'];
        if (!hash_equals(achbotFollowupRedriveToken($r,$actor),$confirmation) ||
            !$last || $last['result']!==$state) return ['status'=>'stale'];
        // Preserve the existing one explicitly authorized unknown resend
        // rule. A known rejection can be corrected without guessing delivery.
        if ($state==='unknown' && achbotProviderRow($db,
            "SELECT id FROM payment_followup_attempts WHERE intent_id=? AND claim_state='unknown' AND actor_userid>0 LIMIT 1",
            'i',[$id])) return ['status'=>'stale'];
    } else {
        if ($confirmation!==null || $state!=='pending') return ['status'=>'stale'];
        if ($count>0 && (!$last || $last['result']!=='rejected' ||
            (int)$last['telegram_error_code']!==429 || (int)$last['retry_after_seconds']<=0 ||
            $r['last_result']!=='rejected' || $r['next_attempt_at']===null))
            throw new RuntimeException('followup_attempt_history_invalid');
        if ($r['next_attempt_at']!==null) {
            $due=achbotProviderRow($db,'SELECT next_attempt_at<=NOW(6) AS due
                FROM payment_followup_intents WHERE id=?','i',[$id]);
            if ((int)($due['due']??0)!==1) return ['status'=>'not_due'];
        }
    }
    $token=bin2hex(random_bytes(16));$next=$count+1;
    $s=achbotProviderStatement($db,"UPDATE payment_followup_intents SET state='dispatching',
        attempt_count=?,dispatch_token=?,dispatched_at=NOW(),last_result=NULL,next_attempt_at=NULL
        WHERE id=? AND state=? AND attempt_count=?",'isisi',[$next,$token,$id,$state,$count]);
    $n=$s->affected_rows;$s->close();
    if ($n!==1) throw new RuntimeException('followup_attempt_claim_conflict');
    $s=achbotProviderStatement($db,'INSERT INTO payment_followup_attempts
        (intent_id,attempt_no,dispatch_token,actor_userid,claim_state) VALUES (?,?,?,?,?)',
        'iisis',[$id,$next,$token,$actor,$state]);
    $n=$s->affected_rows;$s->close();
    if ($n!==1) throw new RuntimeException('followup_attempt_insert_failed');
    return ['status'=>'claimed','token'=>$token];
}

/** The caller owns a fresh transaction and the same original session lease.
 * Atomic result + attempt evidence. Only 429 with a verified retry_after
 * returns to PENDING. Never turn uncertainty into non-application. */
function achbotFollowupAttemptFinishLocked(mysqli $db,int $id,string $token,array $outcome,bool $historicalAbandoned=false): string {
    $result=$outcome['result'];$code=$outcome['code'];$delay=$outcome['retry_after'];
    $state=$result==='rejected' && $delay!==null?'pending':$result;
    $a=achbotProviderRow($db,'SELECT id,result FROM payment_followup_attempts
        WHERE intent_id=? AND dispatch_token=? FOR UPDATE','is',[$id,$token]);
    if (!$a && !$historicalAbandoned)
        throw new RuntimeException('followup_attempt_finish_missing');
    if ($a) {
        if ($a['result']!=='dispatching') throw new RuntimeException('followup_attempt_finish_conflict');
        $s=achbotProviderStatement($db,'UPDATE payment_followup_attempts SET result=?,
            telegram_error_code=?,retry_after_seconds=?,finished_at=NOW(6)
            WHERE id=? AND result=\'dispatching\'','siii',[$result,$code,$delay,(int)$a['id']]);
        $n=$s->affected_rows;$s->close();
        if ($n!==1) throw new RuntimeException('followup_attempt_result_failed');
    } elseif ($result!=='unknown') throw new RuntimeException('followup_attempt_historical_result');
    $s=achbotProviderStatement($db,"UPDATE payment_followup_intents SET state=?,last_result=?,
        acknowledged_at=CASE WHEN ?='acknowledged' THEN NOW() ELSE NULL END,
        dispatch_token=NULL,next_attempt_at=CASE WHEN ?='pending'
            THEN DATE_ADD(NOW(6),INTERVAL ? SECOND) ELSE NULL END
        WHERE id=? AND dispatch_token=? AND state='dispatching'",
        'ssssiis',[$state,$result,$result,$state,$delay,$id,$token]);
    $n=$s->affected_rows;$s->close();
    if ($n!==1) throw new RuntimeException('followup_attempt_result_conflict');
    return $state;
}
