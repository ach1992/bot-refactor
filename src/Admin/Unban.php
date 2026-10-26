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

if (($userInfo['step']??null)==='unbanUser' && $text!==$buttonValues['cancel']) {
    if (is_string($text) && preg_match('/\A[1-9][0-9]{0,18}\z/D',$text)===1) {
        $status=achbotLegacyModerationTransition(
            $connection,$update,(int)$from_id,(int)$chat_id,(int)$message_id,
            $userInfo,(int)$admin,'unban',$text);
        if ($status==='changed') {
            sendMessage("✅ آزاد شدم خوشحالم ننه، ایشالا آزادی همه 😂",$removeKeyboard);
        } elseif ($status==='already') {
            sendMessage("☑️ این کاربر از قبل مسدود نبود.",$removeKeyboard);
        } else {
            sendMessage('درخواست رفع مسدودی تأیید نشد؛ وضعیت و دسترسی فعلی را بررسی کنید.');
        }
        if ($status==='changed' || $status==='already')
            sendMessage($mainValues['reached_main_menu'],getAdminKeys());
    } else {
        sendMessage($mainValues['send_only_number']);
    }
}
