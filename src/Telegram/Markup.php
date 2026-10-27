<?php
/**
 * AchBot presentation helpers. No bootstrap, database or network effects.
 * Legacy coercions, strings and edge behavior are intentional in V1.
 */
namespace AchBot\Telegram\Markup;
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG


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
        $firstRow = [
            ['text' => \achbotMessage('telegram.markup.receipt_and_cancel.button.063f65fc8d'), 'callback_data' => $sendPrefix . $hashId],
        ];
        if ($giftCardUrl !== null) {
            $firstRow[] = ['text' => \achbotMessage('telegram.markup.receipt_and_cancel.button.4595a70ab5'), 'url' => $giftCardUrl];
        }
    } elseif ($messageType === 'manual_usdt' || $messageType === 'manual_trx') {
        $firstRow = [
            ['text' => $messageType === 'manual_trx' ? \achbotMessage('telegram.markup.receipt_and_cancel.text.75b57f3066') : \achbotMessage('telegram.markup.receipt_and_cancel.text.3630d712b2'),
             'callback_data' => $sendPrefix . $hashId],
        ];
    } else {
        $firstRow = [
            ['text' => \achbotMessage('telegram.markup.receipt_and_cancel.button.ae15b282fd'), 'callback_data' => $sendPrefix . $hashId],
        ];
    }
    return inlineKeyboard([
        $firstRow,
        [['text' => \achbotMessage('telegram.markup.receipt_and_cancel.button.cc357a4c9f'), 'callback_data' => $cancelPrefix . $hashId]]
    ]);
}

function onlyCancel(string $cancelPrefix, string $hashId): string {
    return inlineKeyboard([
        [
            ['text' => \achbotMessage('telegram.markup.receipt_and_cancel.button.cc357a4c9f'), 'callback_data' => $cancelPrefix . $hashId],
        ]
    ]);
}
