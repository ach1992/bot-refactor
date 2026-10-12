<?php
/**
 * AchBot Telegram boundary. Dependencies and request context are explicit.
 * Preserve legacy payloads, transport policies, return values and diagnostics.
 */
namespace AchBot\Telegram\Messages;

use CURLFile;

require_once __DIR__ . '/Response.php';
require_once __DIR__ . '/Markup.php';

function sendMessage(callable $request, $from_id, $txt, $key = null, $parse ="MarkDown", $ci= null, $msg = null){
    $ci = $ci ?? $from_id;

    $payload = [
        'chat_id' => $ci,
        'text' => $txt,
        'reply_to_message_id' => $msg,
        'reply_markup' => \AchBot\Telegram\Markup\normalize($key),
    ];

    if ($parse !== null) {
        $payload['parse_mode'] = $parse;
    }

    $res = $request('sendMessage', $payload);

    if ($res && isset($res->ok) && $res->ok === false && isset($res->description)) {
        if (strpos($res->description, "can't parse entities") !== false) {
            unset($payload['parse_mode']);
            $res = $request('sendMessage', $payload);
        }
    }

    return $res;
}

function sendDocument(callable $fileRequest, $from_id, $filePath, $caption = "", $ci = null){
    $ci = $ci ?? $from_id;

    if (!file_exists($filePath)) return false;

    $response = $fileRequest('sendDocument', [
        'chat_id' => $ci,
        'caption' => $caption,
        'document' => new CURLFile(realpath($filePath))
    ]);

    return is_array($response) && isset($response['ok']) && $response['ok'] === true;
}

function answerCallbackQuery(callable $readUrl, $botUrl, $callbackId, $text = "", $show_alert = false) {
    $url = $botUrl . "answerCallbackQuery";
    $post = [
        'callback_query_id' => $callbackId,
        'text' => $text,
        'show_alert' => $show_alert
    ];
    $readUrl($url . "?" . http_build_query($post));
}

function editKeys(callable $request, $from_id, $message_id, $keys = null, $msgId = null, $ci = null){
    $ci = $ci??$from_id;
    $msgId = $msgId??$message_id;

    $request('editMessageReplyMarkup',[
		'chat_id' => $ci,
		'message_id' => $msgId,
		'reply_markup' => $keys
    ]);
}

function editText(callable $request, $from_id, $msgId, $txt, $key = null, $parse = 'HTML', $ci = null) {
    $ci = $ci??$from_id;

    return $request('editMessageText', [
        'chat_id' => $ci,
        'message_id' => $msgId,
        'text' => $txt,
        'parse_mode' => $parse,
        'reply_markup' =>  $key
    ]);
}

function deleteMessage(callable $request, $from_id, $message_id, $msg = null, $chat_id = null){
    $msg = $msg??$message_id;
    $chat_id = $chat_id??$from_id;

    return $request('deleteMessage',[
        'chat_id'=>$chat_id,
        'message_id'=>$msg
        ]);
}

function sendAction(callable $request, $from_id, $action, $ci= null){
    $ci = $ci??$from_id;

    return $request('sendChatAction',[
        'chat_id'=>$ci,
        'action'=>$action
    ]);
}

function forwardMessage(callable $request, $tochatId, $fromchatId, $message_id){
    return $request('forwardMessage',[
        'chat_id'=>$tochatId,
        'from_chat_id'=>$fromchatId,
        'message_id'=>$message_id
    ]);
}

function pinOne(callable $request, $chatId, $messageId){
    return $request('pinChatMessage', [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'disable_notification' => true
    ]);
}

function unpinOne(callable $request, $chatId, $messageId){
    $res = $request('unpinChatMessage', [
        'chat_id' => $chatId,
        'message_id' => $messageId
    ]);

    if(\AchBot\Telegram\Response\isSuccessful($res)) return $res;

    // fallback: unpin آخرین پین
    return $request('unpinChatMessage', [
        'chat_id' => $chatId
    ]);
}

function deleteOne(callable $request, $chatId, $messageId){
    return $request('deleteMessage', [
        'chat_id' => $chatId,
        'message_id' => $messageId
    ]);
}

function sendPhoto(callable $request, $from_id, $photo, $caption = null, $keyboard = null, $parse = "MarkDown", $ci = null, $replyTo = null){
    $ci = $ci ?? $from_id;

    $data = [
        'chat_id' => $ci,
        'caption' => $caption,
        'reply_markup' => \AchBot\Telegram\Markup\normalize($keyboard),
        'photo' => $photo,
        'parse_mode' => $parse
    ];

    if($replyTo != null){
        $data['reply_to_message_id'] = $replyTo;
        $data['allow_sending_without_reply'] = true;
    }

    $res = $request('sendPhoto', $data);

    if ($res && isset($res->ok) && $res->ok === false && isset($res->description)) {
        if (strpos($res->description, "can't parse entities") !== false) {
            unset($data['parse_mode']);
            $res = $request('sendPhoto', $data);
        }
    }

    return $res;
}

function getFileUrl(callable $request, $botToken, $fileid){

    $r = $request('getFile', [
        'file_id' => $fileid
    ]);

    if(!$r || !isset($r->ok) || $r->ok !== true) return null;

    $filePath = $r->result->file_path ?? null;
    if(!$filePath) return null;

    return "https://api.telegram.org/file/bot{$botToken}/{$filePath}";
}

function alert(callable $request, $callbackId, $txt, $type = false, $callid = null){
    $callid = $callid??$callbackId;
    return $request('answercallbackquery', [
        'callback_query_id' => $callid,
        'text' => $txt,
        'show_alert' => $type
    ]);
}
