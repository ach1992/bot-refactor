<?php
/** AChBot V8: source-pinned financial compatibility module. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

if(preg_match('/sConfigRenewPlan(\d+)_(\d+)/',$data, $match) && ($botState['sellState']=="on" ||$from_id ==$admin) && $text != $buttonValues['cancel']){
    $id = $match[1];
	$inbound_id = $match[2];
    if (!achbotSupportsMarzbanPlan($connection, (int)$id, (int)$inbound_id)) {
        alert("⛔️ تمدید این اشتراک در حال حاضر امکان‌پذیر نیست.", true);
        return;
    }



    if(empty($userInfo['temp'])){delMessage(); exit();}

    $configInfo = json_decode($userInfo['temp'], true);
    // A stale plan callback can point at a different Marzban host. Bind
    // the external account to the verified origin rather than trusting the
    // plan id or the user-supplied callback alone.
    $accountServer = (int)($configInfo['server_id'] ?? 0);
    if (!is_array($configInfo) || ($configInfo['marzban'] ?? null) !== true ||
        empty($configInfo['remark']) || $accountServer <= 0 ||
        !achbotSupportsMarzbanPlan($connection, (int)$id, 0, $accountServer)) {
        alert("⛔️ طرح انتخابی با اشتراک شما سازگار نیست.", true);
        return;
    }
    $uuid = $configInfo['uuid'] ?? '';
    $remark = $configInfo['remark'];

    alert($mainValues['receving_information']);
    delMessage();
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=? and `active`=1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $respd = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `id`=?");
    $stmt->bind_param("i", $respd['catid']);
    $stmt->execute();
    $catname = $stmt->get_result()->fetch_assoc()['title'];
    $stmt->close();

    $name = $catname." ".$respd['title'];
    $desc = $respd['descr'];
	$sid = $respd['server_id'];
	$keyboard = array();
    $price =  $respd['price'];
    $token = base64_encode("{$from_id}.{$id}");

    $hash_id = RandomString();
    achbotRemoveUnclaimedPendingPayments($connection,$from_id,'RENEW_SCONFIG');

    setUser('', 'temp');
    $description = json_encode(["uuid"=>$uuid, "remark"=>$remark,
                                "server_id"=>$accountServer, "marzban"=>true],488);
    $time = time();
    $stmt = $connection->prepare("INSERT INTO `pays` (`hash_id`, `description`, `user_id`, `type`, `plan_id`, `server_id`, `volume`, `day`, `price`, `request_date`, `state`)
                                VALUES (?, ?, ?, 'RENEW_SCONFIG', ?, ?, ?, '0', ?, ?, 'pending')");
    $stmt->bind_param("ssiiiiii", $hash_id, $description, $from_id, $id, $accountServer, $inbound_id, $price, $time);
    $stmt->execute();
    $rowId = $stmt->insert_id;
    $stmt->close();


    if(achbotManualEnabled('bank_transfer')) $keyboard[] = [['text' => $buttonValues['cart_to_cart'],  'callback_data' => "payBuyWithCartToCart$hash_id"]];
    if(achbotManualEnabled('gift_card')) $keyboard[] = [['text' => $buttonValues['gift_card'], 'callback_data' => "payBuyWithGiftCard$hash_id"]];
    if (achbotManualCryptoEnabled()) $keyboard[] = [['text' => '💎 پرداخت رمزارزی دستی', 'callback_data' => "mcm_$hash_id"]];
    if($botState['nowPaymentOther'] == "on") $keyboard[] = [['text' => $buttonValues['now_payment_gateway'],  'url' => $botUrl . "pay/?nowpayment&hash_id=" . $hash_id]];
    if($botState['zarinpal'] == "on") $keyboard[] = [['text' => $buttonValues['zarinpal_gateway'],  'url' => $botUrl . "pay/?zarinpal&hash_id=" . $hash_id]];
    if($botState['nextpay'] == "on") $keyboard[] = [['text' => $buttonValues['nextpay_gateway'],  'url' => $botUrl . "pay/?nextpay&hash_id=" . $hash_id]];
    if($botState['walletState'] == "on") $keyboard[] = [['text' => $buttonValues['pay_with_wallet'],  'callback_data' => "payBuyWithWallet$hash_id"]];

	$keyboard[] = [['text' => $buttonValues['back_to_main'], 'callback_data' => "mainMenu"]];
    sendMessage(str_replace(['PLAN-NAME', 'PRICE', 'DESCRIPTION'], [$name, $price, $desc], $mainValues['buy_subscription_detail']), json_encode(['inline_keyboard'=>$keyboard]), "HTML");
}

