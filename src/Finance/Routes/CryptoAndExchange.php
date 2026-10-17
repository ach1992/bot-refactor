<?php
/** AChBot V8: source-pinned financial compatibility module. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

if(preg_match('/payWithTronWallet(.*)/',$data,$match)) {
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result();
    $stmt->close();

    if($payInfo->num_rows == 0){
        $text = "/start";
        $data = "";
        delMessage();
        return '__ACHBOT_V8_GOTO_START__';
    }
    $payInfo = $payInfo->fetch_assoc();
    if (!achbotSupportsPaymentSubject($connection, $payInfo)) {
        alert("⛔️ پرداخت برای این سرویس پشتیبانی نمی‌شود.", true);
        return;
    }


    if ((int)$payInfo['user_id'] !== (int)$from_id ||
        (string)$payInfo['state'] !== 'pending') {
        alert("⛔️ این سفارش قابل پرداخت با روش جدید نیست؛ وضعیت آن را بررسی کنید.", true);
        return;
    }

    $fid = $payInfo['plan_id'];
    $type = $payInfo['type'];
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $file_detail = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $server_id = $file_detail['server_id'];
    $acount = $file_detail['acount'];
    $inbound_id = $file_detail['inbound_id'];

    if($type != "INCREASE_WALLET" && $type != "RENEW_ACCOUNT"){
        if($acount <= 0 and $inbound_id != 0){
            alert($mainValues['out_of_connection_capacity']);
            exit;
        }
        if($inbound_id == 0) {
            $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
            $stmt->bind_param("i", $server_id);
            $stmt->execute();
            $server_info = $stmt->get_result()->fetch_assoc();
            $stmt->close();
    
            if($server_info['ucount'] <= 0) {
                alert($mainValues['out_of_server_capacity']);
                exit; 
            }
        }else{
            if($acount <= 0){
                alert($mainValues['out_of_server_capacity']);
                exit();
            }
        }
    }
    
    if($type == "RENEW_ACCOUNT"){
        $oid = $payInfo['order_id'];
        
        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
        $stmt->bind_param("i", $oid);
        $stmt->execute();
        $order = $stmt->get_result();
        $stmt->close();
        if($order->num_rows == 0){
            delMessage();
            sendMessage($mainValues['config_not_found'], getMainKeys());
            exit();
        }

    }
    
    delMessage();
    
    $price = (int)$payInfo['price'];
    $rate = (float)($botState['TRXRate'] ?? 0);
    if ($rate <= 0 || !is_finite($rate) || $price <= 0) {
        alert("⛔️ این روش پرداخت فعلاً قابل استفاده نیست؛ با پشتیبانی تماس بگیرید.", true);
        return;
    }
    $priceInTrx = round($price / $rate, 2);
    $claim = achbotTronClaim($connection, $payInfo, (int)$from_id,
                            $priceInTrx, (string)($paymentKeys['tronwallet'] ?? ''));
    if ($claim === null) {
        alert("⛔️ این سفارش قبلاً با روش دیگری آغاز شده است؛ پرداخت مجدد انجام ندهید.", true);
        return;
    }
    sendMessage(str_replace(["AMOUNT", "TRON-WALLET"], [$claim['quote'], $claim['wallet']],
        $mainValues['pay_with_tron_wallet']), $cancelKey, "html");

    setUser($data);
}
if(preg_match('/^payWithTronWallet(.*)/',$userInfo['step'], $match) && $text != $buttonValues['cancel']){
    if(!preg_match('/^[0-9a-f]{64}$/i',$text)){
        sendMessage($mainValues['incorrect_tax_id']);
        exit(); 
    }else{
        $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `payid` = ?");
        $stmt->bind_param("s", $text);
        $stmt->execute();
        $checkExist = $stmt->get_result();
        $stmt->close();
        
        if ($checkExist->num_rows == 0) {
            $currentPayment = dbFetchPayByHash($connection, (string)$match[1]);
            if (!$currentPayment || (int)$currentPayment['user_id'] !== (int)$from_id ||
                !achbotSupportsPaymentSubject($connection, $currentPayment)) {
                alert("⛔️ ثبت تراکنش این سفارش امکان‌پذیر نیست؛ برای بررسی با پشتیبانی تماس بگیرید.", true);
                return;
            }
            if (!achbotTronSubmitHash($connection, (string)$match[1], (int)$from_id, $text)) {
                sendMessage("⛔️ این سفارش با روش دیگری در جریان است یا شناسهٔ تراکنش قبلاً ثبت شده؛ برای بررسی با پشتیبانی تماس بگیرید.");
                setUser();
                return;
            }
            sendMessage($mainValues['in_review_tax_id'], $removeKeyboard);
            setUser();
            sendMessage($mainValues['reached_main_menu'], getMainKeys());

        }else sendMessage($mainValues['used_tax_id']);
    }

}
// Retired WeSwap callbacks from previously sent keyboards must fail closed.
if (preg_match('/^(?:payWithWeSwap|havePaiedWeSwap)[A-Za-z0-9]*$/D', $data)) {
    answerCallbackQuery($callbackId, "این روش پرداخت دیگر پشتیبانی نمی‌شود.");
    sendMessage("⛔️ این روش پرداخت غیرفعال شده است. برای بررسی سفارش قبلی با پشتیبانی تماس بگیرید.", getMainKeys(), "HTML", $from_id);
    return;
}
