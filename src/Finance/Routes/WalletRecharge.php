<?php
/** AChBot V8: source-pinned financial compatibility module. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

if($data == "increaseMyWallet") { 
    delMessage(); 

    $textMsg = 
"✅ با شارژ کیف پول می‌توانید هنگام خرید از <b>دکمه پرداخت با موجودی</b> استفاده کنید و منتظر تأیید ادمین <b>نمانید</b>.

❇️ لطفاً مبلغ شارژ کیف پول را انتخاب کنید:";

    $keyboard = [
        [
            ['text' => '100,000 تومان',  'callback_data' => 'increaseWallet_100000'],
            ['text' => '200,000 تومان', 'callback_data' => 'increaseWallet_200000'],
        ],
        [
            ['text' => '300,000 تومان', 'callback_data' => 'increaseWallet_300000'],
            ['text' => '500,000 تومان', 'callback_data' => 'increaseWallet_500000'],
        ],
        [
            ['text' => '1,000,000 تومان',  'callback_data' => 'increaseWallet_1000000'],
            ['text' => '2,000,000 تومان',  'callback_data' => 'increaseWallet_2000000'],
        ],
        [
            ['text' => '5,000,000 تومان',  'callback_data' => 'increaseWallet_5000000'],
        ],
        [
            ['text' => '✏️ مبلغ دلخواه', 'callback_data' => 'increaseWalletCustom'],
        ],
        [
            ['text' => $buttonValues['cancel'], 'callback_data' => 'mainMenu'],
        ],
    ];

    $keys = json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE);

    sendMessage($textMsg, $keys, 'HTML');
}

if(strpos($data, "increaseWallet_") === 0){ 
    delMessage();

    $amount = (int)str_replace("increaseWallet_", "", $data);

    if($amount > 10000000){
        sendMessage("⚠️ حداکثر مبلغ قابل شارژ کیف پول 10,000,000 تومان می‌باشد.");
        exit();
    }

    startWalletPayment($connection, $botState, $buttonValues, $botUrl, $from_id, $removeKeyboard, $amount);
    setUser();
}

if($data == "increaseWalletCustom"){ 
    delMessage();

    sendMessage("💰 لطفاً مبلغ شارژ را وارد کنید.
✅ حداقل مبلغ قابل شارژ <b>100,000 تومان</b> می باشد.
✅ حداکثر مبلغ قابل شارژ <b>10,000,000 تومان</b> می باشد.
✅ مبلغ باید فقط مضربی از <b>50,000 تومان</b> باشد.
🔢 لطفاً فقط اعداد انگلیسی وارد کنید.", $cancelKey, 'HTML');

    setUser("increaseMyWallet");
}

if($userInfo['step'] == "increaseMyWallet" && $text != $buttonValues['cancel']){ 
    
    if(!is_numeric($text)){ 
        sendMessage($mainValues['send_only_number']); 
        exit(); 
    }

    $amount = (int)$text;

    if($amount < 100000){ 
        sendMessage("⚠️ لطفا مقداری بیشتر یا مساوی 100,000 تومان وارد کنید."); 
        exit(); 
    }

    if($amount > 10000000){
        sendMessage("⚠️ حداکثر مبلغ قابل شارژ کیف پول 10,000,000 تومان می‌باشد.");
        exit();
    }

    if($amount % 50000 != 0){
        sendMessage("⚠️ مبلغ واردشده باید مضربی از 50,000 تومان باشد.");
        exit();
    }

    startWalletPayment($connection, $botState, $buttonValues, $botUrl, $from_id, $removeKeyboard, $amount);

    setUser();
}

if (preg_match('/payIncreaseWalletWith(?:CartToCart|GiftCard|ManualUsdt)(?<hashId>.*)/', $data, $match)) {

    $payInfo = dbFetchPayByHash($connection, $match['hashId']);
    if (!$payInfo) {
        prepareStartFallback();
        delMessage();
        return '__ACHBOT_V8_GOTO_START__';
    }

    delMessage();
    $method = strpos($data, 'WithManualUsdt') !== false ? 'manual_usdt' :
        (strpos($data, 'WithGiftCard') !== false ? 'gift_card' : 'bank_transfer');
    if (!achbotManualClaim($connection, $payInfo, $method, (int)$from_id)) {
        alert("⚠️ پرداخت این سفارش با این روش مجاز نیست یا قبلاً به روش دیگری آغاز شده است.", true);
        return;
    }
    setUser("payIncreaseWalletWith" . ($method === 'manual_usdt' ? 'ManualUsdt' : ($method === 'gift_card' ? 'GiftCard' : 'CartToCart')) . $match['hashId']);

	$msg = buildCartToCartInfoMessage($paymentKeys, $mainValues, (int)$payInfo['price'], (int)$payInfo['user_id'], $method, (string)$payInfo['hash_id']);
    if ($msg === "") {
        alert("⚠️ اطلاعات این پرداخت قابل بازیابی نیست؛ با پشتیبانی تماس بگیرید.", true);
        return;
    }
    $keyboard = kbSendReceiptAndCancel($method === 'manual_usdt' ? "sendIncreaseWalletUsdtReceipt" : ($method === 'gift_card' ? "sendIncreaseWalletGiftReceipt" : "sendIncreaseWalletReceipt"), "cancelIncreaseWalletReceipt", $match['hashId'], $method);

    sendMessage($msg, $keyboard, "HTML");
    exit;
}

if (preg_match('/send(?:IncreaseWallet|IncreaseWalletGift|IncreaseWalletUsdt)Receipt(?<hashId>.*)/', $data, $match)) {

    $payInfo = dbFetchPayByHash($connection, $match['hashId']);
    if (!$payInfo) {
        prepareStartFallback();
        delMessage();
        return '__ACHBOT_V8_GOTO_START__';
    }

    $method = strpos($data, 'UsdtReceipt') !== false ? 'manual_usdt' :
        (strpos($data, 'GiftReceipt') !== false ? 'gift_card' : 'bank_transfer');
    if (!achbotManualReceiptAllowed($connection, $payInfo, $method, (int)$from_id)) {
        alert("⚠️ این رسید با روش پرداخت انتخاب‌شده مطابقت ندارد یا سفارش در انتظار پرداخت نیست.", true);
        return;
    }
    $guardMsg = duplicateReceiptGuard($connection, $payInfo, time());
    if ($guardMsg) { alert($guardMsg, true); exit; }

    delMessage();
    setUser(($method === 'manual_usdt' ? "waitIncreaseWalletUsdtReceipt" : ($method === 'gift_card' ? "waitIncreaseWalletGiftReceipt" : "waitIncreaseWalletReceipt")) . $match['hashId']);

    $keyboard = kbOnlyCancel("cancelIncreaseWalletReceipt", $match['hashId']);
    sendMessage(achbotManualReceiptPrompt($mainValues, $method), $keyboard, "HTML");
    exit;
}

if (preg_match('/cancelIncreaseWalletReceipt(?<hashId>.*)/', $data, $match)) {
    handleCancelFlow($mainValues, $removeKeyboard);
}

if (preg_match('/wait(?:IncreaseWallet|IncreaseWalletGift|IncreaseWalletUsdt)Receipt(.*)/', $userInfo['step'], $match)) {

    $method = strpos($userInfo['step'], 'UsdtReceipt') !== false ? 'manual_usdt' :
        (strpos($userInfo['step'], 'GiftReceipt') !== false ? 'gift_card' : 'bank_transfer');
    $receiptPay = dbFetchPayByHash($connection, $match[1]);
    if (!$receiptPay || !achbotManualReceiptAllowed($connection, $receiptPay, $method, (int)$from_id)) {
        setUser();
        alert("⚠️ این سفارش قابل ثبت رسید نیست؛ برای بررسی با پشتیبانی تماس بگیرید.", true);
        return;
    }
    $fileid = extractPhotoFileId($update);
    if (!$fileid) {
        $keyboard = kbOnlyCancel("cancelIncreaseWalletReceipt", $match[1]);
        sendMessage($mainValues['please_send_only_image'], $keyboard, "HTML");
        exit;
    }

    if (!achbotManualBeginEvidence($connection, $receiptPay, $method, (int)$from_id)) {
        setUser();
        alert("⚠️ رسید این سفارش قبلاً ثبت شده یا در حال بررسی است.", true);
        return;
    }

    setUser();
    $uid = $userInfo['userid'];
    $name = $userInfo['name'];
    $username = $userInfo['username'];

    $payInfo = dbFetchPayByHash($connection, $match[1]);
    if (!$payInfo) {
        prepareStartFallback();
        delMessage();
        return '__ACHBOT_V8_GOTO_START__';
    }

	// $price = (int)$payInfo['price'];
	$rawPrice = (int)$payInfo['price'];
	$price = number_format($rawPrice);

    sendWithStartButton(achbotManualAcknowledgement($receiptPay, $method), $buttonValues['start_bot'], $botUsername, 'mainMenu', 'HTML', (string)$receiptPay['hash_id']);

    $msg = str_replace(
        ['PRICE', 'USERNAME', 'NAME', 'USER-ID'],
        [$price, $username, $name, $from_id],
        $mainValues['increase_wallet_request_message']
    );

    $adminKeyboard = buildAdminKeyboardWithStats(
        $connection,
        $buttonValues,
        "approvePayment{$match[1]}",
        "declineAction_{$match[1]}",
        $match[1],
        (int)$payInfo['user_id']
    );

    sendReceiptToAdminsAndUpdatePay($connection, $fileid, achbotManualCaption($connection, $receiptPay, $msg), $adminKeyboard, $admin, $match[1]);
    exit;
}

