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
    $stage = null;
    $attempt = null;
    try {
        $stmt = $connection->prepare('SELECT provider,stage,payment_id,provider_status,
            TIMESTAMPDIFF(SECOND,created_at,NOW()) AS age_seconds
            FROM payment_attempts WHERE pay_row_id=? LIMIT 1');
        $id = (int)$pay['id'];
        $stmt->bind_param('i',$id);
        $stmt->execute();
        $attempt = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $provider = $attempt['provider'] ?? null;
        $stage = $attempt['stage'] ?? null;
    } catch (Throwable $ignored) {
        // No attempt evidence: do not manufacture a verified status.
    }
    $manual = in_array($provider, ['bank_transfer','gift_card','manual_usdt','manual_trx'], true);
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
    $body = achbotPayUxStatusText($pay,$attempt) .
        \achbotMessage('payment.pre_payment.text.c8886e46d5') . htmlspecialchars($methodName,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $uiState = achbotPayUxState($pay,$attempt);
    if (in_array((string)$pay['state'],['approved','auto_cart_approved'],true) &&
        preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D',
                   (string)($pay['updated_at'] ?? ''))) {
        $body .= \achbotMessage('payment.pre_payment.body.ff6ceb47bf') .
            htmlspecialchars((string)$pay['updated_at'],ENT_QUOTES,'UTF-8') . "</code>";
    }
    $actions=[];
    if (!in_array($uiState,['expired','declined','payment_recorded'],true)) {
        $actions[]=[['text'=>\achbotMessage('payment.pre_payment.button.ea1db2132d'),'callback_data'=>'ps_'.$hashId]];
    }
    $actions[]=[['text'=>\achbotMessage('payment.payment_lifecycle.pay_ux_status_keyboard.button.1121ffbf88'),'callback_data'=>'ph_'.$hashId]];
    $keyboard=inlineKeyboard($actions);
    answerCallbackQuery($callbackId, \achbotMessage('payment.pre_payment.alert.fdf0d1d6f9'));
    // The clicked Telegram message is our canonical status card; never
    // manufacture a second status message on every refresh.
    editText($message_id, $body, $keyboard, 'HTML', $from_id);
    return;
}

// Renewal's existing hosted link card is navigation only. The public
// checkout controller owns all admission and issuance, like other purchases.
if (preg_match('/^renewPay_(nowpayment|zarinpal|nextpay)_([A-Za-z0-9_-]{1,50})$/D',
               (string)$data,$match)) {
    $payMethod=$match[1];$hash_id=$match[2];
    $method=$payMethod==='nowpayment'?'nowpayments':$payMethod;
    try {
        $s=achbotProviderStatement($connection,"SELECT p.*,a.provider AS attempt_provider,
            a.stage AS attempt_stage,a.review_archived_at AS attempt_archived
            FROM pays p LEFT JOIN payment_attempts a ON a.pay_row_id=p.id
            WHERE p.hash_id=? AND p.user_id=? LIMIT 2",'si',[$hash_id,(int)$from_id]);
        $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
        $payInfo=count($rows)===1?$rows[0]:null;
        if (!$payInfo || !achbotPayUxCurrentDraft($payInfo) || $payInfo['type']!=='RENEW_ACCOUNT' ||
            !in_array($payInfo['state'],['pending','send'],true) ||
            ($payInfo['attempt_provider']===null
                ? (!achbotPayUxUnclaimedDraftUsable($connection,$payInfo) ||
                   !achbotPayUxAudienceAllowed($connection,$method,(int)$from_id))
                : ($payInfo['attempt_provider']!==$method ||
                   $payInfo['attempt_archived']!==null ||
                   !in_array($payInfo['attempt_stage'],['creating','pending','checking'],true)))) {
            prepareStartFallback();
            return '__ACHBOT_V8_GOTO_START__';
        }
    } catch (Throwable $e) {
        error_log('achbot_renewal_checkout_link_deferred class='.get_class($e));
        prepareStartFallback();
        return '__ACHBOT_V8_GOTO_START__';
    }
    $redirectUrl = $botUrl . "pay/checkout.php?$payMethod&hash_id=$hash_id";
    $button = [
        'inline_keyboard'=>[
            [['text'=>\achbotMessage('payment.pre_payment.button.f81a8cfcdd'),'url'=>$redirectUrl]],
            [['text'=>\achbotMessage('payment.payment_lifecycle.pay_ux_status_keyboard.button.9e25b02e86'),'callback_data'=>'ps_'.$hash_id]]
        ]
    ];
    answerCallbackQuery($callbackId,\achbotMessage('payment.pre_payment.alert.093b359bab'));
    $extra = $payMethod==='nowpayment'
        ? \achbotMessage('payment.pre_payment.text.52bdfa0dce')
        : \achbotMessage('payment.pre_payment.text.64b1e46fd3');
    $sent = sendMessage(
        \achbotMessage('payment.pre_payment.message.b3b3cf5aae').achbotPayUxReference($payInfo).$extra.\achbotMessage('payment.pre_payment.message.69b0efb715'),
        json_encode($button,JSON_UNESCAPED_UNICODE),'HTML',$from_id
    );
    achbotPayUxTrackMessage($connection,(int)$payInfo['id'],(int)$from_id,$sent);
    return;
}
return 1;
