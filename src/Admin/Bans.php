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
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG


if ($data === "banUser") {
    $status=achbotLegacyModerationTransition(
        $connection,$update,(int)$from_id,(int)$chat_id,(int)$message_id,
        $userInfo,(int)$admin,'startBan');
    if ($status==='started') {
        delMessage();
        sendMessage(\achbotMessage('admin.bans.ban_user.message.e2ecb94a9c'), $cancelKey, 'HTML');
    }
}
if ($data === "unbanUser") {
    $status=achbotLegacyModerationTransition(
        $connection,$update,(int)$from_id,(int)$chat_id,(int)$message_id,
        $userInfo,(int)$admin,'startUnban');
    if ($status==='started') {
        delMessage();
        sendMessage(\achbotMessage('admin.bans.unban_user.message.450fb58659'), $cancelKey, 'HTML');
    }
}
if (($userInfo['step']??null)==='banUser' && $text!==\achbotMessage('legacy_buttons.cancel')) {
    if (is_string($text) && preg_match('/\A[1-9][0-9]{0,18}\z/D',$text)===1) {
        $status=achbotLegacyModerationTransition(
            $connection,$update,(int)$from_id,(int)$chat_id,(int)$message_id,
            $userInfo,(int)$admin,'ban',$text);
        if ($status==='changed') {
            sendMessage(\achbotMessage('admin.bans.unban_user.message.8921035044'),$removeKeyboard, 'HTML');
        } elseif ($status==='already') {
            sendMessage(\achbotMessage('admin.bans.unban_user.message.8b8e660e87'),$removeKeyboard, 'HTML');
        } else {
            sendMessage(\achbotMessage('admin.bans.unban_user.message.8764ea2164'), null, 'HTML');
        }
        if ($status==='changed' || $status==='already')
            sendMessage(\achbotCurrentMessage('reached_main_menu'), getAdminKeys(), 'HTML');
    } else {
        sendMessage(\achbotMessage('legacy_messages.send_only_number'), null, 'HTML');
    }
}
