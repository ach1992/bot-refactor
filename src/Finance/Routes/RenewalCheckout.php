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

if (preg_match('/^renewAccountAsk(\d+)$/', $data, $match)) {
    $oid = (int)$match[1];

    $keyboard = json_encode([
        'inline_keyboard' => [
            [
                ['text' => \achbotMessage('renewal.renewal_checkout.button.faa57a99b5'), 'callback_data' => "renewAccount{$oid}"]
            ],
            [
                ['text' => \achbotMessage('renewal.renewal_checkout.button.1fe862ea18'), 'callback_data' => "mySubscriptions"]
            ]
        ]
    ]);

    sendMessage(\achbotMessage('renewal.renewal_checkout.message.7159ade06f'), $keyboard, 'HTML');
    exit();
}

if (
    preg_match('/^renewAccount(\d+)$/', $data, $match) &&
    $text != \achbotMessage('legacy_buttons.cancel')
) {

    // اضافه کردن بررسی برای ستون fileid
    $oid = $match[1];

    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ? AND userid=?");
    $stmt->bind_param("ii", $oid,$from_id);
    $stmt->execute();
    $order = $stmt->get_result();
    $stmt->close();

    if($order->num_rows == 0){
        delMessage();
        sendMessage(\achbotMessage('legacy_messages.config_not_found'), getMainKeys(), 'HTML');
        exit();
    }

    $order = $order->fetch_assoc();
    $fid = $order['fileid'];
    if (!achbotSupportsOrder($connection, (int)$oid, (int)$fid, (int)$order['userid'])) {
        alert(\achbotMessage('renewal.renewal_checkout.alert.6aa152a253'), true);
        return;
    }
    // بررسی اگر fileid برابر 0 باشد
    if($fid == 0){
        sendMessage(\achbotMessage('renewal.renewal_checkout.message.4c2448e6fa'), null, 'HTML');
        exit();
    }
	
	$serverId = $order['server_id'];
    $agentBought = $order['agent_bought'];

    delMessage();

    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ?");
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $respd = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $price = $respd['price'];
    if($agentBought == true){
        $discounts = json_decode($userInfo['discount_percent'],true);
        if(($botState['agencyPlanDiscount']??'off')=="on") $discount = $discounts['plans'][$fid]?? $discounts['normal'];
        else $discount = $discounts['servers'][$serverId]?? $discounts['normal'];
        $price -= floor($price * $discount / 100);
    }
        $hash_id = RandomString();

        
        $time = time();
		$stmt = $connection->prepare("INSERT INTO `pays` (`hash_id`, `user_id`, `type`, `plan_id`, `server_id`, `volume`, `day`, `price`, `request_date`, `state`, `order_id`)
									VALUES (?, ?, 'RENEW_ACCOUNT', ?, ?, '0', '0', ?, ?, 'pending', ?)");
		$stmt->bind_param("siiiiii", $hash_id, $from_id, $fid, $serverId, $price, $time, $oid);
        $rowId=achbotPayUxInsertDraft($connection,(int)$from_id,$stmt,false,$respd,$botState);
        $stmt->close();
        if ($rowId<=0) {
            alert(\achbotMessage('renewal.renewal_checkout.alert.201380d8bf'),true);
            return;
        }
    require_once __DIR__.'/../../Purchase/CheckoutPresentation.php';
    if (!achbotRenderNewCheckoutDraft($connection,$rowId,(int)$from_id,$botState,$botUrl))
        alert(achbotMessage('renewal.renewal_checkout.alert.201380d8bf'),true);
    return;
}

if (is_string($data) && (str_contains($data,'WithManualUsdt') ? ($GLOBALS['achbotManualCryptoVerifiedDispatch'] ?? null) === $data : strlen($data) <= 64) && preg_match('/\ApayRenewWith(?:CartToCart|GiftCard|ManualUsdt)([A-Za-z0-9_-]{1,50})\z/D', $data, $match)) {

    $payInfo = dbFetchPayByHash($connection, $match[1]);
    if (!$payInfo) {
        prepareStartFallback();
        return '__ACHBOT_V8_GOTO_START__';
    }

    $method = str_starts_with($data, 'payRenewWithManualUsdt') ? (($GLOBALS['achbotManualCryptoChoice'] ?? '') === 'TRON' ? 'manual_trx' : 'manual_usdt') :
        (str_starts_with($data, 'payRenewWithGiftCard') ? 'gift_card' : 'bank_transfer');
    if (!achbotManualClaim($connection, $payInfo, $method, (int)$from_id,
                           $GLOBALS['achbotManualCryptoChoice'] ?? null,'renew')) {
        alert(\achbotMessage('renewal.extensions.alert.1a885aaca4'), true);
        return;
    }

	$msg = buildCartToCartInfoMessage($paymentKeys, (int)$payInfo['price'], (int)$payInfo['user_id'], $method, (string)$payInfo['hash_id']);
    if ($msg === "") {
        alert(\achbotMessage('renewal.extensions.alert.07ee06b4bc'), true);
        return;
    }
    $keyboard = kbSendReceiptAndCancel(in_array($method, ['manual_usdt','manual_trx'], true) ? "sendRenewUsdtReceipt" : ($method === 'gift_card' ? "sendRenewGiftReceipt" : "sendRenewCartReceipt"), "cancelRenewCartReceipt", $match[1], $method);

    $delivered = sendMessage($msg, $keyboard, "HTML");
    require_once __DIR__ . '/../PaymentLifecycle.php';
    achbotPayUxTrackByHash($connection,(string)$payInfo['hash_id'],(int)$from_id,$delivered);
    if (function_exists('achbotApiPaymentTrackInvoice')) achbotApiPaymentTrackInvoice($connection,(string)$payInfo['hash_id'],(int)$from_id,$delivered);
    if (is_object($delivered) && !empty($delivered->ok)) {
    setUser("payRenewWith" . (in_array($method, ['manual_usdt','manual_trx'], true) ? 'ManualUsdt' : ($method === 'gift_card' ? 'GiftCard' : 'CartToCart')) . $match[1]);
        delMessage();
    } else {
        // The pinned attempt may be reopened. Never erase the selection menu
        // if Telegram failed to deliver the replacement instructions.
        alert(\achbotMessage('renewal.extensions.alert.7a9c30e938'), true);
    }
    exit;
}

if (preg_match('/send(?:RenewCart|RenewGift|RenewUsdt)Receipt(?<hashId>.*)/', $data, $match)) {

    $payInfo = dbFetchPayByHash($connection, $match['hashId']);
    if (!$payInfo) {
        prepareStartFallback();
        return '__ACHBOT_V8_GOTO_START__';
    }

    $method = strpos($data, 'UsdtReceipt') !== false ? achbotManualCryptoReceiptProviderByHash($connection, (string)$match['hashId']) :
        (strpos($data, 'GiftReceipt') !== false ? 'gift_card' : 'bank_transfer');
    if (!achbotManualReceiptAllowed($connection, $payInfo, $method, (int)$from_id)) {
        alert(\achbotMessage('renewal.extensions.alert.d06bc731eb'), true);
        return;
    }
    $guardMsg = duplicateReceiptGuard($connection, $payInfo, time());
    if ($guardMsg) { alert($guardMsg, true); exit; }

    // Explicit user intent to send a receipt, not a claim of paid funds.
    // Serialize with the six-hour unstarted-invoice expiry sweep.
    require_once __DIR__ . '/../PaymentLifecycle.php';
    if (!achbotPayUxStartManualReceipt($connection,(int)$payInfo['id'],
                                      $method,(int)$from_id)) {
        alert(\achbotMessage('renewal.extensions.alert.560f05f822'),true);
        return;
    }

    delMessage();
    setUser((in_array($method, ['manual_usdt','manual_trx'], true) ? "waitRenewUsdtReceipt" : ($method === 'gift_card' ? "waitRenewGiftReceipt" : "waitRenewCartReceipt")) . $match['hashId']);

    $keyboard = kbOnlyCancel("cancelRenewCartReceipt", $match['hashId']);
    $sent=sendMessage(achbotManualEvidencePrompt($connection,$payInfo,$method), $keyboard, "HTML");
    require_once __DIR__ . '/../PaymentLifecycle.php';
    achbotPayUxTrackByHash($connection,(string)$match['hashId'],(int)$from_id,$sent);
    exit;
}

if (preg_match('/cancelRenewCartReceipt(?<hashId>.*)/', $data, $match)) {
    handleCancelFlow( $removeKeyboard);
}

if (preg_match('/wait(?:RenewCart|RenewGift|RenewUsdt)Receipt(.*)/', $userInfo['step'], $match)) {

    $method = strpos($userInfo['step'], 'UsdtReceipt') !== false ? achbotManualCryptoReceiptProviderByHash($connection, (string)$match[1]) :
        (strpos($userInfo['step'], 'GiftReceipt') !== false ? 'gift_card' : 'bank_transfer');
    $receiptPay = dbFetchPayByHash($connection, $match[1]);
    if (!$receiptPay || !achbotManualReceiptAllowed($connection, $receiptPay, $method, (int)$from_id)) {
        setUser();
        alert(\achbotMessage('renewal.extensions.alert.882596d0f1'), true);
        return;
    }
    $fileid = achbotManualEvidenceInput($update, $connection, $receiptPay, $method);
    if ($fileid === null) {
        $keyboard = kbOnlyCancel("cancelRenewCartReceipt", $match[1]);
        sendMessage(achbotManualEvidencePrompt($connection,$receiptPay,$method,true), $keyboard, "HTML");
        exit;
    }


    $payInfo = dbFetchPayByHash($connection, $match[1]);
    if (!$payInfo) {
        prepareStartFallback();
        return '__ACHBOT_V8_GOTO_START__';
    }

    $hash_id = $payInfo['hash_id'];

    $now = time();
    $requestTime = (int)$payInfo['request_date'];
    if ($now - $requestTime > 10800 && !(function_exists('achbotApiPaymentPinned') && achbotApiPaymentPinned($connection,(int)$payInfo['id']))) {
        delMessage();
        sendMessage(\achbotMessage('renewal.renewal_checkout.message.2c9a5e948c'), null, 'HTML');

        sendMessage(\achbotCurrentMessage('reached_main_menu'), getMainKeys(), 'HTML');
        exit;
    }

    $oid = (int)$payInfo['order_id'];

    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $orderRes = $stmt->get_result();
    $stmt->close();

    if (!$orderRes || $orderRes->num_rows == 0) {
        delMessage();
        sendMessage(\achbotMessage('legacy_messages.config_not_found'), getMainKeys(), 'HTML');
        exit;
    }

    $order = $orderRes->fetch_assoc();

    $fid = (int)$order['fileid'];
    $savedFid = (int)$payInfo['plan_id'];

    if ($fid != $savedFid) {
        delMessage();
        sendMessage(\achbotMessage('renewal.renewal_checkout.message.aaa858e3e6'), null, 'HTML');

        sendMessage(\achbotCurrentMessage('reached_main_menu'), getMainKeys(), 'HTML');
        exit;
    }

    $remark = $order['remark'];

    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ?");
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $respd = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $price  = $payInfo['price'];
    $volume = $respd['volume'];
    $days   = $respd['days'];



    $msg = str_replace(
        ['TYPE', "USER-ID", "USERNAME", "NAME", "PRICE", "REMARK", "VOLUME", "DAYS"],
        [achbotManualLabel($method), $from_id, $username, $first_name, $price, $remark, $volume, $days],
        achbotManualCaption($connection, $receiptPay, \achbotMessage('legacy_messages.renew_account_request_message'))
    );

    // The code is checked against this ORIGINAL pinned invoice before any
    // staff message or durable receipt lease. A definite mismatch stays here.
    $giftScreen=achbotManualGiftSubmissionPreflight($connection,$receiptPay,$method,$fileid);
    if (!$giftScreen['accepted']) {
        sendMessage(achbotManualGiftRetryMessage((string)$giftScreen['reason']),
            kbOnlyCancel('cancelRenewCartReceipt', $match[1]), 'HTML');
        exit;
    }
    if(achbotManualTypedGift($connection,$receiptPay,$method) &&
        !achbotManualGiftReviewFits($msg,$fileid,$giftScreen)){
        sendMessage(achbotMessage('payment.api_payment.code_too_long'),null,'HTML');return;
    }
    $deliveryToken = achbotManualBeginEvidence($connection, $receiptPay, $method, (int)$from_id, $fileid);
    if ($deliveryToken === null) {
        alert(\achbotMessage('renewal.extensions.alert.795c4bddb8'), true);
        return;
    }

    $adminKeyboard = buildAdminKeyboardWithStats(
        $connection,
        "approveRenewAcc{$hash_id}",
        "declineAction_{$hash_id}",
        $hash_id,
        (int)$payInfo['user_id']
    );

    if (sendReceiptToAdminsAndUpdatePay($connection, $fileid,
        $msg, $adminKeyboard, $admin, $match[1],
        $deliveryToken,$giftScreen)) {
        setUser();
        achbotManualAcknowledgeAndVerify($connection,$receiptPay,$method,\achbotMessage('legacy_buttons.start_bot'),$botUsername);
    } else {
        sendMessage(\achbotMessage('renewal.extensions.message.c346a0d62e'), null, 'HTML');
    }

    exit;
}

if(preg_match('/^approveRenewAcc(.*)/',$data,$match) && achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'payments')){
    require_once __DIR__.'/../CheckoutRoutes.php';
    achbotCheckoutApprovalCallback($connection,(string)$match[1],(int)$from_id,(int)$admin,'renew');
    return;
}

if(is_string($data) && strlen($data) <= 64 && preg_match('/\ApayRenewWithWallet([A-Za-z0-9_-]{1,50})\z/D', $data, $match)){
    require_once __DIR__.'/../CheckoutRoutes.php';
    achbotCheckoutWalletCallback($connection,(string)$match[1],(int)$from_id,'renew',$botState);
    return;
}
