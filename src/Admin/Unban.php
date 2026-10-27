<?php
/**
 * AchBot Access: User unban completion, notifications and admin menu.
 * Scoped, re-entrant, byte-compatible route at original bot.php position.
 * Internal include only. Do not execute via HTTP directly.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG


if (($userInfo['step']??null)==='unbanUser' && $text!==$buttonValues['cancel']) {
    if (is_string($text) && preg_match('/\A[1-9][0-9]{0,18}\z/D',$text)===1) {
        $status=achbotLegacyModerationTransition(
            $connection,$update,(int)$from_id,(int)$chat_id,(int)$message_id,
            $userInfo,(int)$admin,'unban',$text);
        if ($status==='changed') {
            sendMessage(\achbotMessage('admin.unban.message.b111983f26'),$removeKeyboard);
        } elseif ($status==='already') {
            sendMessage(\achbotMessage('admin.unban.message.718eb73755'),$removeKeyboard);
        } else {
            sendMessage(\achbotMessage('admin.unban.message.ee9604a51b'));
        }
        if ($status==='changed' || $status==='already')
            sendMessage($mainValues['reached_main_menu'],getAdminKeys());
    } else {
        sendMessage($mainValues['send_only_number']);
    }
}
