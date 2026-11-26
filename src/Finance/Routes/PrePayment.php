<?php
/** AChBot current payment route and shared receipt presentation. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../../messages/loader.php'; // ACHBOT_TEXT_CATALOG


require_once __DIR__ . '/../PaymentLifecycle.php';

// Read-only status inspection: never contacts a provider or changes money.
if (preg_match('/^ps_([A-Za-z0-9_-]{1,50})$/D', $data, $statusMatch)) {
    $hashId = $statusMatch[1];
    // Full local identity is required for same-family/same-order supersession.
    // A reduced projection silently treats nonzero order_id as zero.
    $stmt = $connection->prepare("SELECT * FROM pays WHERE hash_id=? LIMIT 1");
    $stmt->bind_param('s', $hashId);
    $stmt->execute();
    $pay = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$pay || (int)$pay['user_id'] !== (int)$from_id) {
        answerCallbackQuery($callbackId, \achbotMessage('payment.pre_payment.alert.8fbaa8aebc'));
        return;
    }
    achbotPayUxTrackClicked($connection,(int)$pay['id'],(int)$from_id,(int)$message_id);
    $provider = null;
    $attempt = null;
    try {
        $stmt = $connection->prepare('SELECT *
            FROM payment_attempts WHERE pay_row_id=? LIMIT 1');
        $id = (int)$pay['id'];
        $stmt->bind_param('i',$id);
        $stmt->execute();
        $attempt = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $provider = $attempt['provider'] ?? null;
    } catch (Throwable $ignored) {
        // No attempt evidence: do not manufacture a verified status.
    }
    $methodName = $provider === 'manual_trx' ? \achbotMessage('payment.pre_payment.text.ea9faa7b63') :
        ($provider === 'manual_usdt' ? \achbotMessage('payment.pre_payment.text.16b71e846a') : ($provider === 'gift_card' ? \achbotMessage('payment.payment_lifecycle.pay_ux_audience_methods.text.3904ef0f3d') :
        ($provider === 'bank_transfer' ? \achbotMessage('payment.pre_payment.text.ace4b5ad89') :
        ($provider === 'nowpayments' ? \achbotMessage('payment.pre_payment.text.46daee8b43') :
        ($provider === 'nextpay' ? \achbotMessage('payment.pre_payment.next_pay.68fdcf6af2') :
        ($provider === 'zarinpal' ? \achbotMessage('payment.pre_payment.zarinpal.4497ba648f') : \achbotMessage('payment.pre_payment.text.f07a81a062')))))));
    // A newer order invalidates the old call-to-action even before the next
    // one-minute maintenance tick. Keep any issued provider claim intact.
    try { $pay['superseded']=achbotPayUxHasNewer($connection,$pay); }
    catch (Throwable $ignored) { $pay['superseded']=true; }
    $pay['unadmitted_closed']=achbotPayUxUnadmittedClosed($connection,$pay,$attempt);
    if ($pay['unadmitted_closed']) {
        answerCallbackQuery($callbackId);
        editText($message_id,achbotPayUxStatusText($pay,$attempt),
            achbotPayUxStatusKeyboard($hashId,$pay,$attempt),'HTML',$from_id);
        return;
    }
    $body = achbotPayUxStatusText($pay,$attempt) .
        \achbotMessage('payment.pre_payment.text.c8886e46d5') . htmlspecialchars($methodName,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    if (in_array((string)$pay['state'],['approved','auto_cart_approved'],true) &&
        preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D',
                   (string)($pay['updated_at'] ?? ''))) {
        $body .= \achbotMessage('payment.pre_payment.body.ff6ceb47bf') .
            htmlspecialchars((string)$pay['updated_at'],ENT_QUOTES,'UTF-8') . "</code>";
    }
    $keyboard=achbotPayUxStatusKeyboard($hashId,$pay,$attempt);
    answerCallbackQuery($callbackId, \achbotMessage('payment.pre_payment.alert.fdf0d1d6f9'));
    // The clicked Telegram message is our canonical status card; never
    // manufacture a second status message on every refresh.
    editText($message_id, $body, $keyboard, 'HTML', $from_id);
    return;
}

return 1;
