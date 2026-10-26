<?php
/**
 * AchBot Access: User ban/unban request and ban authorization.
 * Scoped, re-entrant, byte-compatible route at original bot.php position.
 * Internal include only. Do not execute via HTTP directly.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

if ($data === "banUser") {
    $status=achbotLegacyModerationTransition(
        $connection,$update,(int)$from_id,(int)$chat_id,(int)$message_id,
        $userInfo,(int)$admin,'startBan');
    if ($status==='started') {
        delMessage();
        sendMessage("😡 کی باز شلوغی کرده شناسه عددی شو بفرس تا برم ...... آرهههه:", $cancelKey);
    }
}
if ($data === "unbanUser") {
    $status=achbotLegacyModerationTransition(
        $connection,$update,(int)$from_id,(int)$chat_id,(int)$message_id,
        $userInfo,(int)$admin,'startUnban');
    if ($status==='started') {
        delMessage();
        sendMessage("شناسه عددیشو بفرست تا آزادش کنم", $cancelKey);
    }
}
if (($userInfo['step']??null)==='banUser' && $text!==$buttonValues['cancel']) {
    if (is_string($text) && preg_match('/\A[1-9][0-9]{0,18}\z/D',$text)===1) {
        $status=achbotLegacyModerationTransition(
            $connection,$update,(int)$from_id,(int)$chat_id,(int)$message_id,
            $userInfo,(int)$admin,'ban',$text);
        if ($status==='changed') {
            sendMessage("❌ خب خب برید کنار که مسدودش کردم 😎😂",$removeKeyboard);
        } elseif ($status==='already') {
            sendMessage("☑️ این کاربر از قبل مسدود بود.",$removeKeyboard);
        } else {
            sendMessage('درخواست مسدودسازی تأیید نشد؛ وضعیت و دسترسی فعلی را بررسی کنید.');
        }
        if ($status==='changed' || $status==='already')
            sendMessage($mainValues['reached_main_menu'],getAdminKeys());
    } else {
        sendMessage($mainValues['send_only_number']);
    }
}
