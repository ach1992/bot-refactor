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
