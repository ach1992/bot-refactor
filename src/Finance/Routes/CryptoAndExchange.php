<?php
/**
 * AChBot: retired automatic TRON and WeSwap callbacks.
 * Historical local payment evidence is preserved; no on-chain checking,
 * token-hash mutation or automatic wallet/service effect may be triggered.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
if (preg_match('/^(?:payWithTronWallet|payWithWeSwap|havePaiedWeSwap)[A-Za-z0-9_-]*$/D',
               (string)$data)) {
    answerCallbackQuery($callbackId, 'این روش قدیمی غیرفعال است؛ از منوی پرداخت جدید استفاده کنید.', true);
    return;
}
if (preg_match('/^payWithTronWallet[A-Za-z0-9_-]*$/D',
               (string)($userInfo['step'] ?? ''))) {
    setUser();
    sendMessage('⛔️ ثبت هش تراکنش در روش قدیمی غیرفعال شده است. ' .
                'اگر قبلاً وجهی ارسال کرده‌اید، شناسه سفارش را به پشتیبانی بدهید. ' .
                'لطفاً پرداخت دیگری انجام ندهید.');
    return;
}
