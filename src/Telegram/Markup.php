<?php
/**
 * AchBot presentation helpers. No bootstrap, database or network effects.
 * Legacy coercions, strings and edge behavior are intentional in V1.
 */
namespace AchBot\Telegram\Markup;

function normalize($key){
    if ($key === null) return null;
    if (is_array($key)) return json_encode($key, JSON_UNESCAPED_UNICODE);
    return $key;
}

function inlineKeyboard(array $rows): string {
    return json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE);
}

function receiptAndCancel(string $sendPrefix, string $cancelPrefix, string $hashId, $messageType = 'detail'): string {


    if ($messageType === 'gift') {
        return inlineKeyboard([
            [
                //['text' => '📤 ارسال رسید', 'callback_data' => $sendPrefix . $hashId],
				['text' => '📤 ارسال گیفت کارت', 'callback_data' => $sendPrefix . $hashId],
                ['text' => '🎁 خرید گیفت کارت', 'url' => 'https://blinko.ir/gift-card/#giftcard-products'],
            ]
        ]);
    }

    return inlineKeyboard([
        [
            ['text' => '📤 ارسال رسید', 'callback_data' => $sendPrefix . $hashId],
            ['text' => '❌ لغو عملیات', 'callback_data' => $cancelPrefix . $hashId],
        ]
    ]);
}

function onlyCancel(string $cancelPrefix, string $hashId): string {
    return inlineKeyboard([
        [
            ['text' => '❌ لغو عملیات', 'callback_data' => $cancelPrefix . $hashId],
        ]
    ]);
}
