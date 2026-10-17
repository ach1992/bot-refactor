<?php
/** AChBot V8: source-pinned financial compatibility module. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

function startWalletPayment($connection, $botState, $buttonValues, $botUrl, $from_id, $removeKeyboard, $amount){
    sendMessage("🪄 لطفا صبور باشید ...", $removeKeyboard);

    $hash_id = RandomString();

    // حذف موارد pending قبلی
    achbotRemoveUnclaimedPendingPayments($connection,$from_id,'INCREASE_WALLET');

    $time = time();
    $stmt = $connection->prepare("INSERT INTO `pays` 
        (`hash_id`, `user_id`, `type`, `plan_id`, `volume`, `day`, `price`, `request_date`, `state`)
        VALUES (?, ?, 'INCREASE_WALLET', '0', '0', '0', ?, ?, 'pending')");
    $stmt->bind_param("siii", $hash_id, $from_id, $amount, $time);
    $stmt->execute();
    $stmt->close();

    $keyboard = [];

    if(achbotManualEnabled('bank_transfer'))
        $keyboard[] = [['text' => $buttonValues['cart_to_cart'], 'callback_data' => "payIncreaseWalletWithCartToCart$hash_id"]];
    if(achbotManualEnabled('gift_card'))
        $keyboard[] = [['text' => $buttonValues['gift_card'], 'callback_data' => "payIncreaseWalletWithGiftCard$hash_id"]];
    if (achbotManualEnabled('manual_usdt'))
        $keyboard[] = [['text' => $buttonValues['manual_usdt'], 'callback_data' => "payIncreaseWalletWithManualUsdt$hash_id"]];
    if($botState['nowPaymentWallet'] == "on") 
        $keyboard[] = [['text' => $buttonValues['now_payment_gateway'], 'url' => $botUrl . "pay/?nowpayment&hash_id=" . $hash_id]];
    if($botState['zarinpal'] == "on") 
        $keyboard[] = [['text' => $buttonValues['zarinpal_gateway'], 'url' => $botUrl . "pay/?zarinpal&hash_id=" . $hash_id]];
    if($botState['nextpay'] == "on") 
        $keyboard[] = [['text' => $buttonValues['nextpay_gateway'], 'url' => $botUrl . "pay/?nextpay&hash_id=" . $hash_id]];
    if($botState['tronWallet'] == "on") 
        $keyboard[] = [['text' => $buttonValues['tron_gateway'], 'callback_data' => "payWithTronWallet$hash_id"]];

    $keyboard[] = [['text' => $buttonValues['cancel'], 'callback_data'=> "mainMenu"]];

    $keys = json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE);
    
    sendMessage("اطلاعات شارژ:\nمبلغ ". number_format($amount) . " تومان\n\nلطفا روش پرداخت را انتخاب کنید:", $keys);
}

