<?php
/**
 * AchBot presentation helpers. No bootstrap, database or network effects.
 * Legacy coercions, strings and edge behavior are intentional in V1.
 */
namespace AchBot\Telegram\Response;

function retryAfter($res){
    if (!is_object($res)) return null;
    if (isset($res->error_code) && (int)$res->error_code === 429) {
        if (isset($res->parameters->retry_after)) {
            return (int)$res->parameters->retry_after;
        }
        return 1;
    }
    return null;
}

function isSuccessful($res){
    return (is_object($res) && isset($res->ok) && $res->ok === true);
}

/**
 * Only a complete explicit Bot API rejection establishes non-application
 * of this one logical message. An absent/malformed response, timeout, or
 * server-side 5xx cannot prove that Telegram did not accept the message.
 * Keep legacy retryAfter/isSuccessful contracts unchanged.
 */
function deliveryOutcome($res): array {
    if ($res instanceof PreSendFailure)
        return ['result'=>'local_failed','code'=>null,'retry_after'=>120,'curl_errno'=>$res->errno];
    if (is_object($res)) $res=(array)$res;
    if (is_array($res) && ($res['ok']??null)===true)
        return ['result'=>'acknowledged','code'=>null,'retry_after'=>null];
    if (!is_array($res) || ($res['ok']??null)!==false ||
        !is_int($res['error_code']??null) || !is_string($res['description']??null) ||
        trim($res['description'])==='' ||
        !in_array($res['error_code'],[400,401,403,404,409,429],true))
        return ['result'=>'unknown','code'=>null,'retry_after'=>null];
    $params=$res['parameters']??null;
    if (is_object($params)) $params=(array)$params;
    $seconds=is_array($params)?($params['retry_after']??null):null;
    // Preserve the exact server hint; the caller owns retry scheduling.
    $retry=$res['error_code']===429 && is_int($seconds) &&
        $seconds>0 && $seconds<=2147483647?$seconds:null;
    return ['result'=>'rejected','code'=>$res['error_code'],'retry_after'=>$retry];
}

/** Shared bounded-backoff policy for a definitely rejected request.
 * An absent Retry-After is not a reason to require a human. */
function retryBackoff(int $attempt): int {
    return (int)min(3600,5*(2**min(max(0,$attempt-1),10)));
}
function rejectedRetryDelay(?int $code,?int $retryAfter,int $attempt): ?int {
    $backoff=retryBackoff($attempt);
    if ($code===429) return $retryAfter??$backoff;
    return in_array($code,[401,403,404,409],true)?max(60,$backoff):null;
}

/** Only a complete explicit Telegram no-op certifies an identical edit.
 * This is not an acknowledgement of sending or any other action. */
function isEditNoop($res): bool {
    return deliveryOutcome($res)['code']===400 &&
        str_contains(strtolower(((array)$res)['description']),'message is not modified');
}

/** A complete Bot API Message must prove its actual destination. */
function acknowledgedMessageIdentity($res,string $recipient,?int $expectedMessage=null): ?array {
    if (deliveryOutcome($res)['result']!=='acknowledged') return null;
    $envelope=(array)$res;
    if (array_key_exists('error_code',$envelope)) return null;
    $message=(array)($envelope['result']??[]);
    $chat=(array)($message['chat']??[]);
    $id=$chat['id']??null;$mid=$message['message_id']??null;
    if (!is_int($id) || $id===0 || !is_int($mid) || $mid<=0 ||
        ($expectedMessage!==null && $mid!==$expectedMessage)) return null;
    if (str_starts_with($recipient,'@')) {
        if (!is_string($chat['username']??null) ||
            strcasecmp('@'.$chat['username'],$recipient)!==0) return null;
    } elseif ((string)$id!==$recipient) return null;
    return ['chat_id'=>$id,'message_id'=>$mid];
}

/** A local transport failure with proof no Bot API request was sent.
 * This is not a Telegram response and cannot be supplied by JSON decoding. */
final class PreSendFailure {
    public function __construct(public readonly int $errno) {}
}

function editTargetFailure($res): ?string {
    if (deliveryOutcome($res)['code']!==400) return null;
    $description=strtolower((string)((array)$res)['description']);
    if (str_contains($description,'message to edit not found')) return 'followup_edit_target_missing';
    foreach (["message can't be edited",'message can not be edited','there is no text in the message to edit'] as $reason)
        if (str_contains($description,$reason)) return 'followup_edit_target_uneditable';
    return null;
}

/** Identity-bound message evidence. A malformed success is uncertain,
 * never an invitation to resend. An exact numeric edit may prove a no-op. */
function messageDeliveryOutcome($res,string $recipient,?int $targetMessage=null): array {
    if ($res instanceof PreSendFailure)
        return ['result'=>'rejected','code'=>null,'retry_after'=>null,'ack'=>null,
            'reason'=>'transport_not_sent','curl_errno'=>$res->errno];
    $outcome=deliveryOutcome($res);$identity=null;
    if ($targetMessage!==null && $targetMessage>0 && isEditNoop($res) &&
        (string)(int)$recipient===$recipient && (int)$recipient!==0) {
        $identity=['chat_id'=>(int)$recipient,'message_id'=>$targetMessage];
        $outcome=['result'=>'acknowledged','code'=>400,'retry_after'=>null];
    } elseif ($outcome['result']==='acknowledged') {
        $identity=acknowledgedMessageIdentity($res,$recipient,$targetMessage);
        if ($identity===null) $outcome=['result'=>'unknown','code'=>null,'retry_after'=>null,
            'reason'=>'followup_message_identity_unproven'];
    }
    if ($targetMessage!==null && $outcome['result']==='rejected' && ($reason=editTargetFailure($res))!==null)
        $outcome['reason']=$reason;
    return $outcome+['ack'=>$identity];
}

/** A contradictory HTTP envelope is not a proven ACK or rejection. */
function deliveryTransportResponse($res,int $http,int $errno) {
    if ($errno!==0) return null;
    $outcome=deliveryOutcome($res);
    if ($outcome['result']==='acknowledged' && $http>=200 && $http<300) return $res;
    if ($outcome['result']==='rejected' && $outcome['code']===$http) return $res;
    return null;
}

function messageId($res){
    if(is_object($res) && isset($res->result) && isset($res->result->message_id)){
        return (int)$res->result->message_id;
    }
    return 0;
}

function isForwardedMessage($msg){
    if (!is_object($msg)) return false;

    return isset($msg->forward_origin)
        || isset($msg->forward_from)
        || isset($msg->forward_from_chat)
        || isset($msg->forward_sender_name);
}

function photoFileId($update): ?string {
    if (!isset($update->message->photo)) return null;
    $photos = $update->message->photo;
    return end($photos)->file_id ?? null;
}
