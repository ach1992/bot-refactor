<?php
/** AChBot current payment route and shared receipt presentation. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../../messages/loader.php'; // ACHBOT_TEXT_CATALOG

require_once __DIR__ . '/../../Admin/Access.php';
if (!achbotAdminRequestAllowed(is_string($data??null)?$data:null,is_string($text??null)?$text:null,(string)($userInfo['step']??''))) {achbotAdminDeny();return;}


require_once __DIR__ . '/../PaymentLifecycle.php';

if (is_string($data) && strlen($data) <= 64 && preg_match('/\ApayCustomWithWallet([A-Za-z0-9_-]{1,50})\z/D', $data, $match)) {
    setUser();

    $hashId = trim($match[1] ?? '');
    require_once __DIR__.'/../CheckoutRoutes.php';
    achbotCheckoutWalletCallback($connection,$hashId,(int)$from_id,'custom',$botState);
    return;
}

if (is_string($data) && strlen($data)<=64 &&
    preg_match('/^showQr(Sub|Config)([1-9][0-9]{0,9})$/D', $data, $match)) {
    $qrSetting=$match[1]==='Sub'?'qrSubState':'qrConfigState';
    if (($botState[$qrSetting]??'off')!=='on') {
        alert(\achbotMessage('payment.checkout.alert.baff5f2488'),true);
        return;
    }
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `userid`=? AND `id`=?");
    $stmt->bind_param("ii", $from_id, $match[2]);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$order || !achbotSupportsOrderObservation($connection, (int)$order['id'],(int)$from_id)) {
        alert(\achbotMessage('payment.checkout.alert.7f1f245514'), true);
        return;
    }

    $stmt=$connection->prepare('SELECT * FROM server_config WHERE id=?');
    $stmt->bind_param('i',$order['server_id']);$stmt->execute();$panel=$stmt->get_result()->fetch_assoc();$stmt->close();
    $presentation=achbotOrderPresentation($connection,$order,$panel?:[],$botState);
    if ($match[1] == "Sub") {
        $subLink=$presentation['qr_subscription_url'];
        if ($subLink==='') {alert(\achbotMessage('payment.checkout.alert.bfa5553444'),true);return;}
        $keys=json_encode(['inline_keyboard'=>[[['text'=>\achbotMessage('legacy_buttons.back_to_main'),'callback_data'=>'mainMenu']]]]);
        if (!sendQrFromLink($subLink,\AchBot\Subscription\Presentation\codeLinks([$subLink]),
            $keys,(string)$botUrl,(int)$from_id))
            alert(\achbotMessage('subscription.presentation.config_qr_delivery_unconfirmed'),true);
    }
    elseif ($match[1] == "Config") {

        $vraylink = $presentation['qr_config_links'];
        if (!$vraylink) {alert(\achbotMessage('payment.checkout.alert.baff5f2488'),true);return;}

        $complete = true;
        foreach ($vraylink as $vray_link) {
            $sent = sendQrFromLink(
                $vray_link,
                \AchBot\Subscription\Presentation\configQrText($vray_link),
                '{}', (string)$botUrl, (int)$from_id, '', null,
                \AchBot\Subscription\Presentation\configQrCaption($vray_link, $presentation)
            );
            $complete = $sent && $complete;
        }
        if (!$complete) alert(\achbotMessage('subscription.presentation.config_qr_delivery_unconfirmed'), true);

    }
}

if (is_string($data) && (str_contains($data,'WithManualUsdt') ? ($GLOBALS['achbotManualCryptoVerifiedDispatch'] ?? null) === $data : strlen($data) <= 64) && preg_match('/\ApayCustomWith(?:CartToCart|GiftCard|ManualUsdt)([A-Za-z0-9_-]{1,50})\z/D', $data, $match)) {

    $payInfo = dbFetchPayByHash($connection, $match[1]);
    if (!$payInfo) {
        prepareStartFallback();
        return '__ACHBOT_V8_GOTO_START__';
    }

    $method = str_starts_with($data, 'payCustomWithManualUsdt') ? (($GLOBALS['achbotManualCryptoChoice'] ?? '') === 'TRON' ? 'manual_trx' : 'manual_usdt') :
        (str_starts_with($data, 'payCustomWithGiftCard') ? 'gift_card' : 'bank_transfer');
    if (!achbotManualClaim($connection, $payInfo, $method, (int)$from_id,
                           $GLOBALS['achbotManualCryptoChoice'] ?? null,'custom')) {
        alert(\achbotMessage('payment.checkout.alert.1a885aaca4'), true);
        return;
    }

	$msg = buildCartToCartInfoMessage($paymentKeys, (int)$payInfo['price'], (int)$payInfo['user_id'], $method, (string)$payInfo['hash_id']);
    if ($msg === "") {
        alert(\achbotMessage('payment.checkout.alert.07ee06b4bc'), true);
        return;
    }
    $keyboard = kbSendReceiptAndCancel(in_array($method, ['manual_usdt','manual_trx'], true) ? "sendCustomUsdtReceipt" : ($method === 'gift_card' ? "sendCustomGiftReceipt" : "sendCustomCartReceipt"), "cancelCustomCartReceipt", $match[1], $method);

    $delivered = sendMessage($msg, $keyboard, "HTML");
    require_once __DIR__ . '/../PaymentLifecycle.php';
    achbotPayUxTrackByHash($connection,(string)$payInfo['hash_id'],(int)$from_id,$delivered);
    if (function_exists('achbotApiPaymentTrackInvoice')) achbotApiPaymentTrackInvoice($connection,(string)$payInfo['hash_id'],(int)$from_id,$delivered);
    if (is_object($delivered) && !empty($delivered->ok)) {
    setUser("payCustomWith" . (in_array($method, ['manual_usdt','manual_trx'], true) ? 'ManualUsdt' : ($method === 'gift_card' ? 'GiftCard' : 'CartToCart')) . $match[1]);
        delMessage();
    } else {
        // The pinned attempt may be reopened. Never erase the selection menu
        // if Telegram failed to deliver the replacement instructions.
        alert(\achbotMessage('payment.checkout.alert.7a9c30e938'), true);
    }
    exit;
}

if (preg_match('/\Asend(?:CustomCart|CustomGift|CustomUsdt)Receipt(?<hashId>[A-Za-z0-9_-]{1,50})\z/D', $data, $match)) {

    $payInfo = dbFetchPayByHash($connection, $match['hashId']);
    if (!$payInfo) {
        prepareStartFallback();
        return '__ACHBOT_V8_GOTO_START__';
    }

    $method = strpos($data, 'UsdtReceipt') !== false ? achbotManualCryptoReceiptProviderByHash($connection, (string)$match['hashId']) :
        (strpos($data, 'GiftReceipt') !== false ? 'gift_card' : 'bank_transfer');
    if (!achbotManualReceiptAllowed($connection, $payInfo, $method, (int)$from_id,'custom')) {
        alert(\achbotMessage('payment.checkout.alert.d06bc731eb'), true);
        return;
    }
    $guardMsg = duplicateReceiptGuard($connection, $payInfo, time());
    if ($guardMsg) { alert($guardMsg, true); exit; }

    // Explicit user intent to send a receipt, not a claim of paid funds.
    // Serialize with the six-hour unstarted-invoice expiry sweep.
    require_once __DIR__ . '/../PaymentLifecycle.php';
    if (!achbotPayUxStartManualReceipt($connection,(int)$payInfo['id'],
                                      $method,(int)$from_id,'custom')) {
        alert(\achbotMessage('payment.checkout.alert.560f05f822'),true);
        return;
    }

    delMessage();
    setUser((in_array($method, ['manual_usdt','manual_trx'], true) ? "waitCustomUsdtReceipt" : ($method === 'gift_card' ? "waitCustomGiftReceipt" : "waitCustomCartReceipt")) . $match['hashId']);

    $keyboard = kbOnlyCancel("cancelCustomCartReceipt", $match['hashId']);
    $sent=sendMessage(achbotManualEvidencePrompt($connection,$payInfo,$method), $keyboard, "HTML");
    require_once __DIR__ . '/../PaymentLifecycle.php';
    achbotPayUxTrackByHash($connection,(string)$match['hashId'],(int)$from_id,$sent);
    exit;
}

if (preg_match('/\AcancelCustomCartReceipt(?<hashId>[A-Za-z0-9_-]{1,50})\z/D', $data, $match)) {
    handleCancelFlow( $removeKeyboard);
}

if (preg_match('/\Await(?:CustomCart|CustomGift|CustomUsdt)Receipt([A-Za-z0-9_-]{1,50})\z/D', $userInfo['step'], $match)) {

    $method = strpos($userInfo['step'], 'UsdtReceipt') !== false ? achbotManualCryptoReceiptProviderByHash($connection, (string)$match[1]) :
        (strpos($userInfo['step'], 'GiftReceipt') !== false ? 'gift_card' : 'bank_transfer');
    $receiptPay = dbFetchPayByHash($connection, $match[1]);
    if (!$receiptPay || !achbotManualReceiptAllowed($connection, $receiptPay, $method, (int)$from_id,'custom')) {
        setUser();
        alert(\achbotMessage('payment.checkout.alert.882596d0f1'), true);
        return;
    }
    $fileid = achbotManualEvidenceInput($update, $connection, $receiptPay, $method);
    if ($fileid === null) {
        $keyboard = kbOnlyCancel("cancelCustomCartReceipt", $match[1]);
        sendMessage(achbotManualEvidencePrompt($connection,$receiptPay,$method,true), $keyboard, "HTML");
        exit;
    }


    $payInfo=$receiptPay;
    $original=achbotManualOriginalServiceQuote($connection,$receiptPay,(int)$from_id,'custom');
    if (!$original) {alert(achbotMessage('payment.checkout.alert.882596d0f1'),true);return;}
    $volume=$original['volume'];$days=$original['days'];
    $username=$userInfo['username'];$fileprice=$original['price'];$remark=$original['remark'];

    $msg = str_replace(
        ['TYPE', 'USER-ID', 'USERNAME', 'NAME', 'PRICE', 'REMARK', 'VOLUME', 'DAYS'],
        [\achbotMessage('payment.checkout.text.04029e7ea7'), $from_id, $username, $first_name, $fileprice, $remark, $volume, $days],
        achbotManualCaption($connection, $receiptPay, \achbotMessage('legacy_messages.buy_custom_account_request'))
    );

    // The code is checked against this ORIGINAL pinned invoice before any
    // staff message or durable receipt lease. A definite mismatch stays here.
    $giftScreen=achbotManualGiftSubmissionPreflight($connection,$receiptPay,$method,$fileid);
    if (!$giftScreen['accepted']) {
        sendMessage(achbotManualGiftRetryMessage((string)$giftScreen['reason']),
            kbOnlyCancel('cancelCustomCartReceipt', $match[1]), 'HTML');
        exit;
    }
    if(achbotManualTypedGift($connection,$receiptPay,$method) &&
        !achbotManualGiftReviewFits($msg,$fileid,$giftScreen)){
        sendMessage(achbotMessage('payment.api_payment.code_too_long'),null,'HTML');return;
    }
    $deliveryToken = achbotManualBeginEvidence($connection, $receiptPay, $method, (int)$from_id, $fileid,'custom');
    if ($deliveryToken === null) {
        alert(\achbotMessage('payment.checkout.alert.795c4bddb8'), true);
        return;
    }

    $adminKeyboard = buildAdminKeyboardWithStats(
        $connection,
        "accCustom{$match[1]}",
        "declineAction_{$match[1]}",
        $match[1],
        (int)$payInfo['user_id']
    );

    if (sendReceiptToAdminsAndUpdatePay($connection, $fileid,
        $msg, $adminKeyboard, $admin, $match[1],
        $deliveryToken,$giftScreen)) {
        setUser();
        achbotManualAcknowledgeAndVerify($connection,$receiptPay,$method,\achbotMessage('legacy_buttons.start_bot'),$botUsername);
    } else {
        sendMessage(\achbotMessage('payment.checkout.message.c346a0d62e'), null, 'HTML');
    }
    exit;
}

if (preg_match('/^accCustom(.+)$/D', $data, $match) && $text != \achbotMessage('legacy_buttons.cancel')) {
    require_once __DIR__.'/../CheckoutRoutes.php';
    achbotCheckoutApprovalCallback($connection,(string)$match[1],(int)$from_id,(int)$admin,'custom');
    return;
}

if (is_string($data) && strlen($data) <= 64 && preg_match('/\ApayBuyWithWallet([A-Za-z0-9_-]{1,50})\z/D', $data, $match)) {

    setUser();

    $hashId = trim($match[1] ?? '');
    require_once __DIR__.'/../CheckoutRoutes.php';
    achbotCheckoutWalletCallback($connection,$hashId,(int)$from_id,'standard',$botState);
    return;
}

if (is_string($data) && (str_contains($data,'WithManualUsdt') ? ($GLOBALS['achbotManualCryptoVerifiedDispatch'] ?? null) === $data : strlen($data) <= 64) && preg_match('/\ApayBuyWith(?:CartToCart|GiftCard|ManualUsdt)([A-Za-z0-9_-]{1,50})\z/D', $data, $match)) {

    $payInfo = dbFetchPayByHash($connection, $match[1]);
    if (!$payInfo) {
        prepareStartFallback();
        return '__ACHBOT_V8_GOTO_START__';
    }

    $method = str_starts_with($data, 'payBuyWithManualUsdt') ? (($GLOBALS['achbotManualCryptoChoice'] ?? '') === 'TRON' ? 'manual_trx' : 'manual_usdt') :
        (str_starts_with($data, 'payBuyWithGiftCard') ? 'gift_card' : 'bank_transfer');
    if (!achbotManualClaim($connection, $payInfo, $method, (int)$from_id,
                           $GLOBALS['achbotManualCryptoChoice'] ?? null,'standard')) {
        alert(\achbotMessage('payment.checkout.alert.1a885aaca4'), true);
        return;
    }

	$msg = buildCartToCartInfoMessage($paymentKeys, (int)$payInfo['price'], (int)$payInfo['user_id'], $method, (string)$payInfo['hash_id']);
    if ($msg === "") {
        alert(\achbotMessage('payment.checkout.alert.07ee06b4bc'), true);
        return;
    }
    $keyboard = kbSendReceiptAndCancel(in_array($method, ['manual_usdt','manual_trx'], true) ? "sendBuyUsdtReceipt" : ($method === 'gift_card' ? "sendBuyGiftReceipt" : "sendBuyCartReceipt"), "cancelBuyCartReceipt", $match[1], $method);

    $delivered = sendMessage($msg, $keyboard, "HTML");
    require_once __DIR__ . '/../PaymentLifecycle.php';
    achbotPayUxTrackByHash($connection,(string)$payInfo['hash_id'],(int)$from_id,$delivered);
    if (function_exists('achbotApiPaymentTrackInvoice')) achbotApiPaymentTrackInvoice($connection,(string)$payInfo['hash_id'],(int)$from_id,$delivered);
    if (is_object($delivered) && !empty($delivered->ok)) {
    setUser("payBuyWith" . (in_array($method, ['manual_usdt','manual_trx'], true) ? 'ManualUsdt' : ($method === 'gift_card' ? 'GiftCard' : 'CartToCart')) . $match[1]);
        delMessage();
    } else {
        // The pinned attempt may be reopened. Never erase the selection menu
        // if Telegram failed to deliver the replacement instructions.
        alert(\achbotMessage('payment.checkout.alert.7a9c30e938'), true);
    }
    exit;
}

if (preg_match('/\Asend(?:BuyCart|BuyGift|BuyUsdt)Receipt(?<hashId>[A-Za-z0-9_-]{1,50})\z/D', $data, $match)) {

    $payInfo = dbFetchPayByHash($connection, $match['hashId']);
    if (!$payInfo) {
        prepareStartFallback();
        return '__ACHBOT_V8_GOTO_START__';
    }

    $method = strpos($data, 'UsdtReceipt') !== false ? achbotManualCryptoReceiptProviderByHash($connection, (string)$match['hashId']) :
        (strpos($data, 'GiftReceipt') !== false ? 'gift_card' : 'bank_transfer');
    if (!achbotManualReceiptAllowed($connection, $payInfo, $method, (int)$from_id,'standard')) {
        alert(\achbotMessage('payment.checkout.alert.d06bc731eb'), true);
        return;
    }
    $guardMsg = duplicateReceiptGuard($connection, $payInfo, time());
    if ($guardMsg) { alert($guardMsg, true); exit; }

    // Explicit user intent to send a receipt, not a claim of paid funds.
    // Serialize with the six-hour unstarted-invoice expiry sweep.
    require_once __DIR__ . '/../PaymentLifecycle.php';
    if (!achbotPayUxStartManualReceipt($connection,(int)$payInfo['id'],
                                      $method,(int)$from_id,'standard')) {
        alert(\achbotMessage('payment.checkout.alert.560f05f822'),true);
        return;
    }

    delMessage();
    setUser((in_array($method, ['manual_usdt','manual_trx'], true) ? "waitBuyUsdtReceipt" : ($method === 'gift_card' ? "waitBuyGiftReceipt" : "waitBuyCartReceipt")) . $match['hashId']);

    $keyboard = kbOnlyCancel("cancelBuyCartReceipt", $match['hashId']);
    $sent=sendMessage(achbotManualEvidencePrompt($connection,$payInfo,$method), $keyboard, "HTML");
    require_once __DIR__ . '/../PaymentLifecycle.php';
    achbotPayUxTrackByHash($connection,(string)$match['hashId'],(int)$from_id,$sent);
    exit;
}

if (preg_match('/\AcancelBuyCartReceipt(?<hashId>[A-Za-z0-9_-]{1,50})\z/D', $data, $match)) {
    handleCancelFlow( $removeKeyboard);
}

if (preg_match('/\Await(?:BuyCart|BuyGift|BuyUsdt)Receipt([A-Za-z0-9_-]{1,50})\z/D', $userInfo['step'], $match)) {

    $method = strpos($userInfo['step'], 'UsdtReceipt') !== false ? achbotManualCryptoReceiptProviderByHash($connection, (string)$match[1]) :
        (strpos($userInfo['step'], 'GiftReceipt') !== false ? 'gift_card' : 'bank_transfer');
    $receiptPay = dbFetchPayByHash($connection, $match[1]);
    if (!$receiptPay || !achbotManualReceiptAllowed($connection, $receiptPay, $method, (int)$from_id,'standard')) {
        setUser();
        alert(\achbotMessage('payment.checkout.alert.882596d0f1'), true);
        return;
    }
    $fileid = achbotManualEvidenceInput($update, $connection, $receiptPay, $method);
    if ($fileid === null) {
        $keyboard = kbOnlyCancel("cancelBuyCartReceipt", $match[1]);
        sendMessage(achbotManualEvidencePrompt($connection,$receiptPay,$method,true), $keyboard, "HTML");
        exit;
    }


    $payInfo=$receiptPay;
    $original=achbotManualOriginalServiceQuote($connection,$receiptPay,(int)$from_id,'standard');
    if (!$original) {alert(achbotMessage('payment.checkout.alert.882596d0f1'),true);return;}
    $days=$original['days'];$volume=$original['volume'];$fileprice=$original['price'];
    $name=$userInfo['name'];$username=$userInfo['username'];
    $serverTitle=$original['labels']['server'];
    $filename=$original['labels']['category'].' '.$original['labels']['plan'];

    if ((int)$payInfo['agent_count'] != 0) {
        $msg = str_replace(
            ['ACCOUNT-COUNT', 'TYPE', 'USER-ID', "USERNAME", "NAME", "PRICE", "REMARK"],
            [$payInfo['agent_count'], achbotManualLabel($method), $from_id, $username, $name, $fileprice, $filename],
            achbotManualCaption($connection, $receiptPay, \achbotMessage('legacy_messages.buy_new_much_account_request'))
        );
    } else {
        $msg = str_replace(
            ['SERVERNAME', 'TYPE', 'USER-ID', "USERNAME", "NAME", "PRICE", "REMARK", "VOLUME", "DAYS"],
            [$serverTitle, achbotManualLabel($method), $from_id, $username, $name, $fileprice, $filename, $volume, $days],
            achbotManualCaption($connection, $receiptPay, \achbotMessage('legacy_messages.buy_new_account_request'))
        );
    }

    // The code is checked against this ORIGINAL pinned invoice before any
    // staff message or durable receipt lease. A definite mismatch stays here.
    $giftScreen=achbotManualGiftSubmissionPreflight($connection,$receiptPay,$method,$fileid);
    if (!$giftScreen['accepted']) {
        sendMessage(achbotManualGiftRetryMessage((string)$giftScreen['reason']),
            kbOnlyCancel('cancelBuyCartReceipt', $match[1]), 'HTML');
        exit;
    }
    if(achbotManualTypedGift($connection,$receiptPay,$method) &&
        !achbotManualGiftReviewFits($msg,$fileid,$giftScreen)){
        sendMessage(achbotMessage('payment.api_payment.code_too_long'),null,'HTML');return;
    }
    $deliveryToken = achbotManualBeginEvidence($connection, $receiptPay, $method, (int)$from_id, $fileid,'standard');
    if ($deliveryToken === null) {
        alert(\achbotMessage('payment.checkout.alert.795c4bddb8'), true);
        return;
    }

    $adminKeyboard = buildAdminKeyboardWithStats(
        $connection,
        "accept{$match[1]}",
        "declineAction_{$match[1]}",
        $match[1],
        (int)$payInfo['user_id']
    );

if (sendReceiptToAdminsAndUpdatePay($connection, $fileid,
        $msg, $adminKeyboard, $admin, $match[1],
        $deliveryToken,$giftScreen)) {
        setUser();
        achbotManualAcknowledgeAndVerify($connection,$receiptPay,$method,\achbotMessage('legacy_buttons.start_bot'),$botUsername);
    } else {
        sendMessage(\achbotMessage('payment.checkout.message.c346a0d62e'), null, 'HTML');
    }
    exit;
}

