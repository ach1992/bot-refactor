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

/** The actual wire action, distinct from the immutable logical intent.
 * A summary revision can edit the ACK of its earlier revision. */
function achbotFollowupDefaultWire(array $r): array {
    return ['action'=>$r['kind']==='edit'?'edit_keys':'send','recipient'=>(string)$r['recipient'],
        'message'=>$r['message_id']===null?null:(int)$r['message_id']];
}
function achbotFollowupLastAttemptLocked(mysqli $db,array $r): ?array {
    $count=(int)$r['attempt_count'];
    if ($count===0) return null;
    $a=achbotProviderRow($db,'SELECT * FROM payment_followup_attempts
        WHERE intent_id=? AND attempt_no=? FOR UPDATE','ii',[(int)$r['id'],$count]);
    if (!$a) throw new RuntimeException('followup_attempt_history_missing');
    return $a;
}
function achbotFollowupRecordedWire(array $a): array {
    return ['action'=>(string)$a['wire_action'],'recipient'=>(string)$a['wire_recipient'],
        'message'=>$a['wire_message_id']===null?null:(int)$a['wire_message_id']];
}
function achbotFollowupEditUnavailable(?string $reason): bool {
    return in_array($reason,['followup_edit_target_missing','followup_edit_target_uneditable'],true);
}
function achbotFollowupRetryWire(array $a): array {
    $wire=achbotFollowupRecordedWire($a);
    if ($wire['action']==='edit_text' && $a['result']==='rejected' &&
        achbotFollowupEditUnavailable($a['failure_reason']))
        return ['action'=>'send','recipient'=>$wire['recipient'],'message'=>null];
    return $wire;
}
/** Backoff is scheduling, not a retry-count limit. Only proven nonapplication
 * or idempotent exact-message edits are automatically retried. */
function achbotFollowupRetryDelay(array $a,array $outcome): ?int {
    $result=$outcome['result'];$code=$outcome['code'];$reason=$outcome['reason']??null;
    $backoff=\AchBot\Telegram\Response\retryBackoff((int)$a['attempt_no']);
    if ($result==='unknown') return $a['wire_action']==='send'?null:$backoff;
    if ($result!=='rejected') return null;
    if ($reason==='transport_not_sent') return $backoff;
    if ($a['wire_action']==='edit_text' && achbotFollowupEditUnavailable($reason)) return 0;
    return \AchBot\Telegram\Response\rejectedRetryDelay($code,$outcome['retry_after'],(int)$a['attempt_no']);
}
function achbotFollowupAttemptClaimLocked(mysqli $db,array $r,int $actor,?string $confirmation,array $wire): array {
    $id=(int)$r['id'];$count=(int)$r['attempt_count'];$state=$r['state'];
    if (!achbotFollowupJournaled($r) || !array_key_exists('next_attempt_at',$r))
        throw new RuntimeException('followup_attempt_schema_missing');
    $last=achbotFollowupLastAttemptLocked($db,$r);
    if (!in_array($wire['action']??null,['send','edit_keys','edit_text'],true) ||
        !is_string($wire['recipient']??null) ||
        preg_match('/^(?:-?[0-9]{1,19}|@[A-Za-z0-9_]{3,64})$/D',$wire['recipient'])!==1 ||
        ($wire['action']==='send'?$wire['message']!==null:
            (!is_int($wire['message']??null) || $wire['message']<=0 ||
             (string)(int)$wire['recipient']!==$wire['recipient'] || (int)$wire['recipient']===0)) ||
        ($r['kind']==='edit' && $wire!==achbotFollowupDefaultWire($r)) ||
        ($last!==null && $wire!==achbotFollowupRetryWire($last)))
        throw new RuntimeException('followup_attempt_wire_invalid');
    if ($actor>0) {
        if (!in_array($state,['unknown','rejected'],true) || !is_string($confirmation))
            return ['status'=>'stale'];
        $primary=(int)($GLOBALS['admin']??0);
        if ($primary<=0 || !achbotAdminCanLocked($db,$actor,$primary,'payments')) return ['status'=>'denied'];
        if (!hash_equals(achbotFollowupRedriveToken($r,$actor),$confirmation) ||
            !$last || $last['result']!==$state) return ['status'=>'stale'];
    } else {
        if ($confirmation!==null || $state!=='pending') return ['status'=>'stale'];
        if ($last!==null && ($r['last_result']!==$last['result'] || $r['next_attempt_at']===null ||
            achbotFollowupRetryDelay($last,['result'=>$last['result'],
                'code'=>$last['telegram_error_code']===null?null:(int)$last['telegram_error_code'],
                'retry_after'=>$last['retry_after_seconds']===null?null:(int)$last['retry_after_seconds'],
                'reason'=>$last['failure_reason']])===null))
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
        (intent_id,attempt_no,dispatch_token,actor_userid,claim_state,wire_action,wire_recipient,wire_message_id)
        VALUES (?,?,?,?,?,?,?,?)',
        'iisisssi',[$id,$next,$token,$actor,$state,$wire['action'],$wire['recipient'],$wire['message']]);
    $n=$s->affected_rows;$s->close();
    if ($n!==1) throw new RuntimeException('followup_attempt_insert_failed');
    return ['status'=>'claimed','token'=>$token];
}

/** The result and exact wire/ACK evidence are atomic. An abandoned send
 * remains unknown; an abandoned exact edit can safely retry its own target. */
function achbotFollowupAttemptFinishLocked(mysqli $db,int $id,string $token,array $outcome): string {
    $result=$outcome['result'];$code=$outcome['code'];$retry=$outcome['retry_after'];
    $a=achbotProviderRow($db,'SELECT * FROM payment_followup_attempts
        WHERE intent_id=? AND dispatch_token=? FOR UPDATE','is',[$id,$token]);
    if (!$a) throw new RuntimeException('followup_attempt_finish_missing');
    if ($a['result']!=='dispatching') throw new RuntimeException('followup_attempt_finish_conflict');
    $reason=$outcome['reason']??($result==='acknowledged'?null:
        ($result==='unknown'?'transport_unknown':'followup_telegram_rejected'));
    $delay=achbotFollowupRetryDelay($a,$outcome);
    $state=$delay!==null?'pending':$result;
    $projection=$result;
    if ($a['wire_action']==='edit_keys' && $result==='rejected' && achbotFollowupEditUnavailable($reason)) {
        // A vanished/uneditable retained receipt is not a failed payment.
        // Never create a new receipt or claim a missing edit acknowledged.
        $state='skipped';$projection=$reason;
    }
    $ack=$outcome['ack']??null;
    if ($result==='acknowledged') {
        if (!is_array($ack) || !is_int($ack['chat_id']??null) || $ack['chat_id']===0 ||
            !is_int($ack['message_id']??null) || $ack['message_id']<=0 ||
            (!str_starts_with($a['wire_recipient'],'@') && (string)$ack['chat_id']!==$a['wire_recipient']) ||
            ($a['wire_action']!=='send' && $ack['message_id']!==(int)$a['wire_message_id']))
            throw new RuntimeException('followup_ack_identity_invalid');
    } elseif ($ack!==null) throw new RuntimeException('followup_ack_without_success');
    $s=achbotProviderStatement($db,'UPDATE payment_followup_attempts SET result=?,
        telegram_error_code=?,retry_after_seconds=?,failure_reason=?,ack_chat_id=?,ack_message_id=?,finished_at=NOW(6)
        WHERE id=? AND result=\'dispatching\'','siisiii',
        [$result,$code,$retry,$reason,$ack['chat_id']??null,$ack['message_id']??null,(int)$a['id']]);
    $n=$s->affected_rows;$s->close();
    if ($n!==1) throw new RuntimeException('followup_attempt_result_failed');
    $s=achbotProviderStatement($db,"UPDATE payment_followup_intents SET state=?,last_result=?,
        acknowledged_at=CASE WHEN ?='acknowledged' THEN NOW() ELSE NULL END,
        dispatch_token=NULL,next_attempt_at=CASE WHEN ?='pending'
            THEN DATE_ADD(NOW(6),INTERVAL ? SECOND) ELSE NULL END
        WHERE id=? AND dispatch_token=? AND state='dispatching'",
        'ssssiis',[$state,$projection,$result,$state,$delay,$id,$token]);
    $n=$s->affected_rows;$s->close();
    if ($n!==1) throw new RuntimeException('followup_attempt_result_conflict');
    return $state;
}
