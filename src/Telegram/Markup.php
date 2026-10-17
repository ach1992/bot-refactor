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

function receiptAndCancel(string $sendPrefix, string $cancelPrefix, string $hashId,
                          $messageType = 'bank_transfer', ?string $giftCardUrl = null): string {
    if ($messageType === 'gift_card') {
        $giftCardUrl = $giftCardUrl ?: 'https://blinko.ir/gift-card/#giftcard-products';
        $firstRow = [
            ['text' => '📤 ارسال تصویر گیفت‌کارت', 'callback_data' => $sendPrefix . $hashId],
            ['text' => '🎁 خرید گیفت‌کارت', 'url' => $giftCardUrl],
        ];
    } elseif ($messageType === 'manual_usdt') {
        $firstRow = [
            ['text' => '💎 ارسال رسید تتر', 'callback_data' => $sendPrefix . $hashId],
        ];
    } else {
        $firstRow = [
            ['text' => '📤 ارسال رسید بانکی', 'callback_data' => $sendPrefix . $hashId],
        ];
    }
    return inlineKeyboard([
        $firstRow,
        [['text' => '🔄 بررسی وضعیت', 'callback_data' => 'ps_' . $hashId]],
        [['text' => '❌ لغو عملیات', 'callback_data' => $cancelPrefix . $hashId]]
    ]);
}

function onlyCancel(string $cancelPrefix, string $hashId): string {
    return inlineKeyboard([
        [
            ['text' => '❌ لغو عملیات', 'callback_data' => $cancelPrefix . $hashId],
        ]
    ]);
}
