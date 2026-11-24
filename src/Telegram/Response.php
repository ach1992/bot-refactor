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
    // Only an explicit, representable Telegram wait instruction schedules
    // automatic redelivery. A definite but undiagnosed rejection stays visible.
    $retry=$res['error_code']===429 && is_int($seconds) &&
        $seconds>0 && $seconds<=2147483647?$seconds:null;
    return ['result'=>'rejected','code'=>$res['error_code'],'retry_after'=>$retry];
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
