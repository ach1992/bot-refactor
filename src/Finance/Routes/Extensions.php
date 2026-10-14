<?php
/** AChBot V8: source-pinned financial compatibility module. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

if(preg_match('/increaseADay(.*)/', $data, $match)){
    $stmt = $connection->prepare("SELECT * FROM `increase_day`");
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $orderInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $agentBought = $orderInfo['agent_bought'];

    if($res->num_rows == 0){
        alert("در حال حاضر هیچ پلنی برای افزایش مدت زمان اشتراک وجود ندارد.");
        exit;
    }
    $keyboard = [];
    while ($cat = $res->fetch_assoc()){
        $id = $cat['id'];
        $title = $cat['volume'];
        $price = $cat['price'];
        if($agentBought == true){
            $discounts = json_decode($userInfo['discount_percent'],true);
            if($botState['agencyPlanDiscount']=="on") $discount = $discounts['plans'][$orderInfo['fileid']]?? $discounts['normal'];
            else $discount = $discounts['servers'][$orderInfo['server_id']]?? $discounts['normal'];
            $price -= floor($price * $discount / 100);
        }
        if($price == 0) $price = "رایگان";
        else $price = number_format($price) . " تومان";
        $keyboard[] = ['text' => "$title روز $price", 'callback_data' => "selectPlanDayIncrease{$match[1]}_$id"];
    }
    $keyboard = array_chunk($keyboard,2);
    $keyboard[] = [['text' => $buttonValues['back_to_main'], 'callback_data' => "mainMenu"]];
    editText($message_id, "لطفا یکی از پلن های افزایشی را انتخاب کنید:", json_encode([
            'inline_keyboard' => $keyboard
        ]));
}
if(preg_match('/selectPlanDayIncrease(?<orderId>.+)_(?<dayId>.+)/',$data,$match)){
    $data = str_replace('selectPlanDayIncrease','',$data);
    $pid = $match['dayId'];
    $stmt = $connection->prepare("SELECT * FROM `increase_day` WHERE `id` = ?");
    $stmt->bind_param("i", $pid);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $planprice = $res['price'];
    
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $match['orderId']);
    $stmt->execute();
    $orderInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $agentBought = $orderInfo['agent_bought'];
    
    if($agentBought == true){
        $discounts = json_decode($userInfo['discount_percent'],true);
        if($botState['agencyPlanDiscount']=="on") $discount = $discounts['plans'][$orderInfo['fileid']]?? $discounts['normal'];
        else $discount = $discounts['servers'][$orderInfo['server_id']]?? $discounts['normal'];

        $planprice -= floor($planprice * $discount / 100);
    }
    
    
    $hash_id = RandomString();
    $stmt = $connection->prepare("DELETE FROM `pays` WHERE `user_id` = ? AND `type` LIKE '%INCREASE_DAY%' AND `state` = 'pending'");
    $stmt->bind_param("i", $from_id);
    $stmt->execute();
    $stmt->close();
	
    $time = time();
    $plan_id = $orderInfo['fileid'];
	$server_id = $orderInfo['server_id'];
	$order_id = $match['orderId'];

	$stmt = $connection->prepare("INSERT INTO `pays` (`hash_id`, `user_id`, `type`, `plan_id`, `server_id`, `order_id`, `volume`, `day`, `price`, `request_date`, `state`)
								VALUES (?, ?, ?, ?, ?, ?, '0', '0', ?, ?, 'pending')");
    $type = "INCREASE_DAY_$data";
	$stmt->bind_param("sisiiiii", $hash_id, $from_id, $type, $plan_id, $server_id, $order_id, $planprice, $time);
    $stmt->execute();
    $stmt->close();

    
    $keyboard = array();
    if($botState['cartToCartState'] == "on") $keyboard[] = [['text' => $buttonValues['cart_to_cart'],  'callback_data' => "payIncreaseDayWithCartToCart$hash_id"]];
    if($botState['nowPaymentOther'] == "on") $keyboard[] = [['text' => $buttonValues['now_payment_gateway'],  'url' => $botUrl . "pay/?nowpayment&hash_id=" . $hash_id]];
    if($botState['zarinpal'] == "on") $keyboard[] = [['text' => $buttonValues['zarinpal_gateway'],  'url' => $botUrl . "pay/?zarinpal&hash_id=" . $hash_id]];
    if($botState['nextpay'] == "on") $keyboard[] = [['text' => $buttonValues['nextpay_gateway'],  'url' => $botUrl . "pay/?nextpay&hash_id=" . $hash_id]];
    if($botState['weSwapState'] == "on") $keyboard[] = [['text' => $buttonValues['weswap_gateway'],  'callback_data' => "payWithWeSwap" . $hash_id]];
    if($botState['walletState'] == "on") $keyboard[] = [['text' => $buttonValues['pay_with_wallet'],  'callback_data' => "payIncreaseDayWithWallet$hash_id"]];
    if($botState['tronWallet'] == "on") $keyboard[] = [['text' => $buttonValues['tron_gateway'],  'callback_data' => "payWithTronWallet" . $hash_id]];

    $keyboard[] = [['text'=>$buttonValues['cancel'], 'callback_data'=> "mainMenu"]];
    editText($message_id, "🔰 لطفا یکی از روش های زیر را انتخاب کنید و روی آن بزنید:",json_encode(['inline_keyboard' => $keyboard]));
}

if (preg_match('/payIncreaseDayWithCartToCart(.*)/', $data, $match)) {

    $payInfo = dbFetchPayByHash($connection, $match[1]);
    if (!$payInfo) {
        prepareStartFallback();
        delMessage();
        return '__ACHBOT_V8_GOTO_START__';
    }

    delMessage();
    setUser("payIncreaseDayWithCartToCart{$match[1]}");

	// $msg = buildCartToCartInfoMessage($paymentKeys, $mainValues, (int)$payInfo['price']);
	$msg = buildCartToCartInfoMessage($paymentKeys, $mainValues, (int)$payInfo['price'], (int)$payInfo['user_id']);
    $keyboard = kbSendReceiptAndCancel("sendIncreaseDayReceipt", "cancelIncreaseDayReceipt", $match[1]);

    sendMessage($msg, $keyboard, "HTML");
    exit;
}

if (preg_match('/sendIncreaseDayReceipt(?<hashId>.*)/', $data, $match)) {

    $payInfo = dbFetchPayByHash($connection, $match['hashId']);
    if (!$payInfo) {
        prepareStartFallback();
        delMessage();
        return '__ACHBOT_V8_GOTO_START__';
    }

    $guardMsg = duplicateReceiptGuard($connection, $payInfo, time());
    if ($guardMsg) { alert($guardMsg, true); exit; }

    delMessage();
    setUser("waitIncreaseDayReceipt{$match['hashId']}");

    $keyboard = kbOnlyCancel("cancelIncreaseDayReceipt", $match['hashId']);
    sendMessage($mainValues['please_send_receipt_image'], $keyboard, "HTML");
    exit;
}

if (preg_match('/cancelIncreaseDayReceipt(?<hashId>.*)/', $data, $match)) {
    handleCancelFlow($mainValues, $removeKeyboard);
}

if (preg_match('/waitIncreaseDayReceipt(.*)/', $userInfo['step'], $match)) {

    $fileid = extractPhotoFileId($update);
    if (!$fileid) {
        $keyboard = kbOnlyCancel("cancelIncreaseDayReceipt", $match[1]);
        sendMessage($mainValues['please_send_only_image'], $keyboard, "HTML");
        exit;
    }

    $payInfoRes = dbFetchPayByHash($connection, $match[1]);
    if (!$payInfoRes) {
        prepareStartFallback();
        delMessage();
        return '__ACHBOT_V8_GOTO_START__';
    }

    $payType = $payInfoRes['type'];

    preg_match('/^INCREASE_DAY_(\d+)_(\d+)/', $payType, $increaseInfo);
    $orderId = (int)$increaseInfo[1];
    $planid  = (int)$increaseInfo[2];

    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $orderInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $remark = $orderInfo['remark'];

    $stmt = $connection->prepare("SELECT * FROM `increase_day` WHERE `id` = ?");
    $stmt->bind_param("i", $planid);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $price  = $payInfoRes['price'];
    $volume = $res['volume'];

    sendWithStartButton($mainValues['order_submitted'], $buttonValues['start_bot'], $botUsername, 'mainMenu', 'HTML');

    $msg = str_replace(
        ['INCREASE', 'TYPE', "USER-ID", "USERNAME", "NAME", "PRICE", "REMARK"],
        [$volume, 'زمان', $from_id, $username, $first_name, $price, $remark],
        $mainValues['increase_account_request_message']
    );

    $adminKeyboard = buildAdminKeyboardWithStats(
        $connection,
        $buttonValues,
        "approveIncreaseDay{$match[1]}",
        "declineAction_{$match[1]}",
        $match[1],
        (int)$payInfoRes['user_id']
    );

    sendReceiptToAdminsAndUpdatePay($connection, $fileid, $msg, $adminKeyboard, $admin, $match[1]);
    setUser();
    exit;
}

if (preg_match('/approveIncreaseDay(.*)/', $data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $connection->begin_transaction();
    try {
        $hash_id = $match[1];

        $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? FOR UPDATE");
        $stmt->bind_param("s", $hash_id);
        $stmt->execute();
        $payParam = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$payParam || ($payParam['state'] === 'expired_have_sent')) {
            $connection->rollback();
            alert(!$payParam ? "⛔️ اطلاعات پرداخت پیدا نشد." : "⛔️ این درخواست منقضی شده است.");
            return;
        }

        if ($payParam['state'] === 'approved' || $payParam['state'] === 'declined') {
            $connection->rollback();
            alert("⛔️ این درخواست قبلاً بررسی شده است.");
            return;
        }

        $payType = $payParam['type'];

        if (!preg_match('/^INCREASE_DAY_(\d+)_(\d+)/', $payType, $increaseInfo)) {
            throw new Exception("invalid_increase_type");
        }
        $orderId = intval($increaseInfo[1]);
        $planid  = intval($increaseInfo[2]);
        $uid     = intval($payParam['user_id']);

        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
        $stmt->bind_param("i", $orderId);
        $stmt->execute();
        $orderInfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$orderInfo) {
            throw new Exception("order_not_found");
        }

        $server_id  = intval($orderInfo['server_id']);
        $inbound_id = intval($orderInfo['inbound_id']);
        $remark     = $orderInfo['remark'];
        $uuid       = $orderInfo['uuid'] ?? "0";

        $stmt = $connection->prepare("SELECT * FROM `increase_day` WHERE `id` = ?");
        $stmt->bind_param("i", $planid);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$res) {
            throw new Exception("increase_day_plan_not_found");
        }

        $price  = intval($res['price']);
        $volume = intval($res['volume']); 

        $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $server_info = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$server_info) {
            throw new Exception("server_config_missing");
        }

        $serverType = $server_info['type'];

        unset($markup[count($markup)-1]);

        if ($serverType == "marzban") {
            $response = editMarzbanConfig($server_id, ['remark' => $remark, 'plus_day' => $volume]);
        } else {
            if ($inbound_id > 0) {
                $response = editClientTraffic($server_id, $inbound_id, $uuid, 0, $volume);
            } else {
                $response = editInboundTraffic($server_id, $uuid, 0, $volume);
            }
        }

        if (is_null($response)) {
            throw new Exception("server_connection_null");
        }
        if (!is_object($response) && !is_array($response)) {
            throw new Exception("invalid_response_type");
        }
        $success = (is_object($response) && !empty($response->success))
                || (is_array($response)  && !empty($response['success']));
        if (!$success) {
            $msg = is_object($response) ? ($response->msg ?? 'no_msg')
                 : ($response['msg'] ?? 'no_msg');
            throw new Exception("server_response_failed: ".$msg);
        }

        $addSeconds = $volume * 86400;
        $stmt = $connection->prepare("UPDATE `orders_list` SET `expire_date` = `expire_date` + ?, `notif` = 0 WHERE `uuid` = ?");
        $stmt->bind_param("is", $addSeconds, $uuid);
        $stmt->execute();
        $stmt->close();

        $timeNow = time();
        $stmt = $connection->prepare("INSERT INTO `increase_order` (`userid`, `server_id`, `inbound_id`, `remark`, `amount`, `date`, `day`) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iiisiii", $uid, $server_id, $inbound_id, $remark, $price, $timeNow, $volume);
        $stmt->execute();
        $stmt->close();

        $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'approved', `approved_by` = ? WHERE `hash_id` = ?");
        $stmt->bind_param("is", $from_id, $hash_id);
        $stmt->execute();
        $stmt->close();

        $connection->commit();

        $keys = json_encode([
            'inline_keyboard' => [
                [['text' => "✅ تایید شد توسط $from_id", 'callback_data' => "wizwizch"]],
                [['text' => "🆔 $hash_id", 'callback_data' => "noop_$hash_id"]]
            ]
        ], 488);

        // ویرایش پیام ادمین اصلی
        editKeys($keys, $payParam['message_id'], $payParam['chat_id']);

        // ویرایش پیام سایر ادمین‌ها
        $adminMessages = json_decode($payParam['admins_messages'], true);
        if (is_array($adminMessages)) {
            foreach ($adminMessages as $msgInfo) {
                if (isset($msgInfo['chat_id'], $msgInfo['message_id']) && $msgInfo['chat_id'] != $payParam['chat_id']) {
                    editKeys($keys, $msgInfo['message_id'], $msgInfo['chat_id']);
                }
            }
        }

        sendMessage("✅ <b>$volume</b> روز به مدت زمان اشتراک <code>$remark</code> اضافه شد.", null, "HTML", $uid);
        return;

    } catch (Exception $e) {
        $connection->rollback();

        // پیام فنی برای ادمین اقدام‌کننده
        $err = "⚠️ خطا در تایید افزایش زمان (کارت‌به‌کارت)\n\n"
             . "🆔 Hash ID: {$match[1]}\n"
             . "👤 Admin: {$from_id}\n"
             . "📄 Error: " . $e->getMessage();
        sendMessage($err, null, null, $admin);

        alert("❌ افزایش زمان انجام نشد. لطفاً وضعیت سرور/اطلاعات را بررسی کنید.", true);
        return;
    }
}

if(preg_match('/payIncreaseDayWithWallet(.*)/', $data, $match)){
    $connection->begin_transaction();
    try {
        $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? FOR UPDATE");
        $stmt->bind_param("s", $match[1]);
        $stmt->execute();
        $payRes = $stmt->get_result();
        $stmt->close();

        if($payRes->num_rows == 0){
            $connection->rollback();
            $text = "/start";
            $data = "";
            delMessage();
            return '__ACHBOT_V8_GOTO_START__';
        }

        $payParam = $payRes->fetch_assoc();
        $hash_id  = $payParam['hash_id'];

        if ($payParam['state'] === "paid_with_wallet") {
            $connection->rollback();
            return;
        }

        $payType = $payParam['type'];
        if (!preg_match('/^INCREASE_DAY_(\d+)_(\d+)/', $payType, $increaseInfo)) {
            throw new Exception("invalid_increase_type");
        }
        $orderId = intval($increaseInfo[1]);
        $planid  = intval($increaseInfo[2]);
        $uid     = intval($payParam['user_id'] ?? $from_id);

        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
        $stmt->bind_param("i", $orderId);
        $stmt->execute();
        $orderInfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$orderInfo){
            throw new Exception("order_not_found");
        }

        $server_id  = intval($orderInfo['server_id']);
        $inbound_id = intval($orderInfo['inbound_id']);
        $remark     = $orderInfo['remark'];
        $uuid       = $orderInfo['uuid'] ?? "0";

        $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id` = ?");
        $stmt->bind_param('i', $server_id);
        $stmt->execute();
        $serverConfig = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if(!$serverConfig){
            throw new Exception("server_config_missing");
        }
        $serverType = $serverConfig['type'];

        $stmt = $connection->prepare("SELECT * FROM `increase_day` WHERE `id` = ?");
        $stmt->bind_param("i", $planid);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if(!$res){
            throw new Exception("increase_day_plan_not_found");
        }

        $price  = intval($payParam['price']);
        $volume = intval($res['volume']);

        $stmt = $connection->prepare("SELECT `wallet` FROM `users` WHERE `userid` = ? FOR UPDATE");
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $uRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $userwallet = intval($uRow['wallet'] ?? 0);
        if($userwallet < $price) {
            $needamount = $price - $userwallet;

            $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'low_wallet' WHERE `hash_id` = ?");
            $stmt->bind_param("s", $hash_id);
            $stmt->execute();
            $stmt->close();

            $connection->commit();

            alert("❌ موجودی کیف پول کافی نیست.\n💵 موجودی فعلی: " . number_format($userwallet) . " تومان\n🔺 مورد نیاز: " . number_format($needamount) . " تومان", true);
            return;
        }

        if($serverType == "marzban"){
            $response = editMarzbanConfig($server_id, ['remark'=>$remark, 'plus_day'=>$volume]);
        } else {
            if($inbound_id > 0)
                $response = editClientTraffic($server_id, $inbound_id, $uuid, 0, $volume);
            else
                $response = editInboundTraffic($server_id, $uuid, 0, $volume);
        }

        if (is_null($response)) {
            throw new Exception("server_connection_null");
        }
        if (!is_object($response) && !is_array($response)) {
            throw new Exception("invalid_response_type");
        }
        $success = (is_object($response) && !empty($response->success))
                || (is_array($response)  && !empty($response['success']));
        if (!$success) {
            $msg = is_object($response) ? ($response->msg ?? 'no_msg')
                 : ($response['msg'] ?? 'no_msg');
            throw new Exception("server_response_failed: ".$msg);
        }

        $addSeconds = $volume * 86400;
        $stmt = $connection->prepare("UPDATE `orders_list` SET `expire_date` = `expire_date` + ?, `notif` = 0 WHERE `uuid` = ?");
        $stmt->bind_param("is", $addSeconds, $uuid);
        $stmt->execute();
        $stmt->close();

        $timeNow = time();
        $stmt = $connection->prepare("INSERT INTO `increase_order` (`userid`, `server_id`, `inbound_id`, `remark`, `amount`, `date`, `day`) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iiisiii", $uid, $server_id, $inbound_id, $remark, $price, $timeNow, $volume);
        $stmt->execute();
        $stmt->close();

        $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` - ? WHERE `userid` = ?");
        $stmt->bind_param("ii", $price, $uid);
        $stmt->execute();
        $stmt->close();

        $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'paid_with_wallet' WHERE `hash_id` = ?");
        $stmt->bind_param("s", $hash_id);
        $stmt->execute();
        $stmt->close();

        $connection->commit();

        editText($message_id, "✅ $volume روز به مدت زمان اشتراک شما اضافه شد.", getMainKeys());

        $keys = json_encode(['inline_keyboard'=>[
            [
                ['text'=>"⏳ افزایش زمان اشتراک",'callback_data'=>"wizwizch"]
            ],
            [
                ['text'=>"🆔 $hash_id", 'callback_data'=>"noop_$hash_id"]
            ]
        ]]);

        $msg = "
🔋|💰 افزایش زمان با (کیف پول)

🎫 شناسه عددی: $from_id
👨 اسم کاربر: $first_name
⚡️ نام کاربری: @$username
🎈 نام کاربری اشتراک: $remark
⏰ مدت افزایش: $volume روز
💰 قیمت: $price تومان
";
        sendMessage($msg, $keys,"html", $admin);
        //sendToAllAdmins('sendMessage', ['text' => $msg,'reply_markup' => $keys,'parse_mode' => "html"]);
		
		// ارسال پیام به کانال گزارشات
        $rewardChannel = $botState['rewardChannel'];
        if (!empty($rewardChannel)) {
            sendMessage($msg, $keys, "html", $rewardChannel);
        }

        return;

    } catch (Exception $e) {
        $connection->rollback();

        // پیام فنی برای ادمین
        $adminErr = "⚠️ خطا در افزایش زمان با کیف پول\n\n"
                  . "🆔 Hash ID: {$match[1]}\n"
                  . "👤 User: {$from_id}\n"
                  . "📄 Error: ".$e->getMessage();
        sendMessage($adminErr, null, null, $admin);

        sendMessage("❌ مشکلی پیش اومد.\n🔁 لطفاً ۳۰ ثانیه بعد دوباره تلاش کنید.\nاگر مشکل ادامه داشت، به مدیریت اطلاع دهید.", null, null, $uid);

        return;
    }
}

if (preg_match('/^changePlan_(\d+)/', $data, $match)) {
    $orderId = $match[1];

    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $orderInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
	
	if ((int)$orderInfo['amount'] === 0) {
		alert("⚠️ امکان تغییر پلن برای این نوع اشتراک وجود ندارد.", true);
		return;
	}

    $remark = $orderInfo['remark'];
    $serverId = $orderInfo['server_id'];

	$stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `server_id` = ? AND `price` > 0 ORDER BY `id` ASC");
    $stmt->bind_param("i", $serverId);
    $stmt->execute();
    $plans = $stmt->get_result();
    $stmt->close();

    $buttons = [];
    while ($plan = $plans->fetch_assoc()) {
        $buttons[] = [['text' => $plan['title'], 'callback_data' => "confirmChangePlan_" . $orderId . "_" . $plan['id']]];
    }

    $buttons[] = [['text' => $buttonValues['back_button'], 'callback_data' => "mainMenu"]];

    if (count($buttons) === 1) {
        sendMessage("هیچ پلنی برای این سرور وجود ندارد.");
    } else {
        sendMessage("🎲 لطفاً یکی از پلن‌های زیر را انتخاب کنید.
♻️ بعد از تغییر پلن می توانید اشتراک خودتان را مطابق پلن جدید تمدید کنید.", json_encode(['inline_keyboard' => $buttons]), "html");
    }

    exit;
}

if (preg_match('/^confirmChangePlan_(\d+)_(\d+)/', $data, $match)) {
    $orderId = $match[1];
    $newPlanId = $match[2];

    // Get current order info
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $orderInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($orderInfo['fileid'] == $newPlanId) {
        sendMessage("❗️پلن فعلی شما همین است. لطفاً پلن متفاوتی انتخاب کنید.");
        exit;
    }

    // Update plan in orders_list
    $stmt = $connection->prepare("UPDATE `orders_list` SET `fileid` = ? WHERE `id` = ?");
    $stmt->bind_param("ii", $newPlanId, $orderId);
    $stmt->execute();
    $stmt->close();

    editText($message_id, "✅ پلن اشتراک شما با موفقیت تغییر یافت.");

    // Get new plan title
    $stmt = $connection->prepare("SELECT title FROM `server_plans` WHERE `id` = ?");
    $stmt->bind_param("i", $newPlanId);
    $stmt->execute();
    $result = $stmt->get_result();
    $planInfo = $result->fetch_assoc();
    $stmt->close();

    $planTitle = $planInfo['title'];

    $channelMessage = "
📦 تغییر پلن اشتراک توسط کاربر

🔐 شناسه عددی: <code>{$orderInfo['userid']}</code>
👨 اسم کاربر: $first_name
⚡️ نام کاربری: @$username
🎈 نام کاربری اشتراک: <code>{$orderInfo['remark']}</code>
🔄 پلن جدید: $planTitle
🔖 آیدی پلن جدید: $newPlanId
";

    sendMessage($channelMessage, null, "html", $admin);
    //sendToAllAdmins('sendMessage', ['text' => $channelMessage,'parse_mode' => "html"]);
	
	// ارسال پیام به کانال گزارشات
	$rewardChannel = $botState['rewardChannel'];
		if (!empty($rewardChannel)) {
		sendMessage($channelMessage, null, "html", $rewardChannel);
	}
    exit;
}
if (preg_match('/^increaseAVolume(.*)/', $data, $match)) {
    // بررسی تاریخ انقضا
    $stmt = $connection->prepare("SELECT `expire_date` FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $orderInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $expireDate = (int) $orderInfo['expire_date']; // تبدیل به عدد صحیح
    $currentDate = time();

    // بررسی اینکه آیا تاریخ معتبر است یا خیر
    if ($expireDate === false) {
        alert("📆 تاریخ انقضا برای این سفارش نامعتبر است.");
        exit;
    }
	
    // اگر تاریخ انقضا کمتر از تاریخ و ساعت فعلی باشد
    if ($expireDate < $currentDate) {
        alert("📆 اشتراک شما منقضی شده است. لطفاً آن را تمدید کنید تا بتوانید افزایش حجم دهید.");
        exit;
    }
    
    $stmt = $connection->prepare("SELECT * FROM `increase_plan`");
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    
    if ($res->num_rows == 0) {
        alert("در حال حاضر هیچ پلن حجمی وجود ندارد.");
        exit;
    }
    $keyboard = [];
    while ($cat = $res->fetch_assoc()) {
        $id = $cat['id'];
        $title = $cat['volume'];
        $price = $cat['price'];
        if ($agentBought == true) {
            $discounts = json_decode($userInfo['discount_percent'], true);
            if ($botState['agencyPlanDiscount'] == "on") 
                $discount = $discounts['plans'][$orderInfo['fileid']] ?? $discounts['normal'];
            else 
                $discount = $discounts['servers'][$orderInfo['server_id']] ?? $discounts['normal'];
            $price -= floor($price * $discount / 100);
        }
        if ($price == 0) 
            $price = "رایگان";
        else 
            $price = number_format($price) .  ' تومان';
        
        $keyboard[] = ['text' => "$title گیگ $price", 'callback_data' => "increaseVolumePlan{$match[1]}_{$id}"];
    }
    $keyboard = array_chunk($keyboard, 2);
    $keyboard[] = [['text' => "⤵️ برگرد به منوی اصلی", 'callback_data' => "mainMenu"]];
    $res = editText($message_id, "لطفا یکی از پلن های حجمی را انتخاب کنید:", json_encode([
        'inline_keyboard' => $keyboard
    ]));
}

if(preg_match('/increaseVolumePlan(?<orderId>.+)_(?<volumeId>.+)/',$data,$match)){
    $data = str_replace('increaseVolumePlan','',$data);
    $stmt = $connection->prepare("SELECT * FROM `increase_plan` WHERE `id` = ?");
    $stmt->bind_param("i", $match['volumeId']);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $planprice = $res['price'];
    $plangb = $res['volume'];
    
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $match['orderId']);
    $stmt->execute();
    $orderInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $agentBought = $orderInfo['agent_bought'];
 
    if($agentBought == true){
        $discounts = json_decode($userInfo['discount_percent'],true);
        if($botState['agencyPlanDiscount']=="on") $discount = $discounts['plans'][$orderInfo['fileid']]?? $discounts['normal'];
        else $discount = $discounts['servers'][$orderInfo['server_id']]?? $discounts['normal'];
        
        $planprice -= floor($planprice * $discount / 100);
    }

    $hash_id = RandomString();
    $stmt = $connection->prepare("DELETE FROM `pays` WHERE `user_id` = ? AND `type` LIKE '%INCREASE_VOLUME%' AND `state` = 'pending'");
    $stmt->bind_param("i", $from_id);
    $stmt->execute();
    $stmt->close();
    
    $time = time();
    $plan_id = $orderInfo['fileid'];
	$server_id = $orderInfo['server_id'];
	$order_id = $match['orderId'];
	
	$stmt = $connection->prepare("INSERT INTO `pays` (`hash_id`, `user_id`, `type`, `plan_id`, `server_id`, `order_id`, `volume`, `day`, `price`, `request_date`, `state`)
								VALUES (?, ?, ?, ?, ?, ?, '0', '0', ?, ?, 'pending')");
	$type = "INCREASE_VOLUME_$data";
	$stmt->bind_param("sisiiiii", $hash_id, $from_id, $type, $plan_id, $server_id, $order_id, $planprice, $time);
	$stmt->execute();
	$stmt->close();
    
    $keyboard = array();
    
    if($planprice == 0) $planprice = ' رایگان';
    else $planprice = " " . number_format($planprice) . " تومان";
    
    
    if($botState['cartToCartState'] == "on") $keyboard[] = [['text' => $buttonValues['cart_to_cart'] . $planprice,  'callback_data' => "payIncreaseWithCartToCart$hash_id"]];
    if($botState['nowPaymentOther'] == "on") $keyboard[] = [['text' => $buttonValues['now_payment_gateway'],  'url' => $botUrl . "pay/?nowpayment&hash_id=" . $hash_id]];
    if($botState['zarinpal'] == "on") $keyboard[] = [['text' => $buttonValues['zarinpal_gateway'],  'url' => $botUrl . "pay/?zarinpal&hash_id=" . $hash_id]];
    if($botState['nextpay'] == "on") $keyboard[] = [['text' => $buttonValues['nextpay_gateway'],  'url' => $botUrl . "pay/?nextpay&hash_id=" . $hash_id]];
    if($botState['weSwapState'] == "on") $keyboard[] = [['text' => $buttonValues['weswap_gateway'],  'callback_data' => "payWithWeSwap" . $hash_id]];
    if($botState['walletState'] == "on") $keyboard[] = [['text' => "💰پرداخت با موجودی" . $planprice,  'callback_data' => "payIncreaseWithWallet$hash_id"]];
    if($botState['tronWallet'] == "on") $keyboard[] = [['text' => $buttonValues['tron_gateway'],  'callback_data' => "payWithTronWallet" . $hash_id]];

    $keyboard[] = [['text'=>$buttonValues['cancel'], 'callback_data'=> "mainMenu"]];
    editText($message_id, "🔰 لطفا یکی از روش های زیر را انتخاب کنید و روی آن بزنید:",json_encode(['inline_keyboard' => $keyboard]));
}

if (preg_match('/payIncreaseWithCartToCart(.*)/', $data, $match)) {

    $payInfo = dbFetchPayByHash($connection, $match[1]);
    if (!$payInfo) {
        prepareStartFallback();
        delMessage();
        return '__ACHBOT_V8_GOTO_START__';
    }

    delMessage();
    setUser("payIncreaseWithCartToCart{$match[1]}");

	// $msg = buildCartToCartInfoMessage($paymentKeys, $mainValues, (int)$payInfo['price']);
	$msg = buildCartToCartInfoMessage($paymentKeys, $mainValues, (int)$payInfo['price'], (int)$payInfo['user_id']);
    $keyboard = kbSendReceiptAndCancel("sendIncreaseVolumeReceipt", "cancelIncreaseVolumeReceipt", $match[1]);

    sendMessage($msg, $keyboard, "HTML");
    exit;
}

if (preg_match('/sendIncreaseVolumeReceipt(?<hashId>.*)/', $data, $match)) {

    $payInfo = dbFetchPayByHash($connection, $match['hashId']);
    if (!$payInfo) {
        prepareStartFallback();
        delMessage();
        return '__ACHBOT_V8_GOTO_START__';
    }

    $guardMsg = duplicateReceiptGuard($connection, $payInfo, time());
    if ($guardMsg) { alert($guardMsg, true); exit; }

    delMessage();
    setUser("waitIncreaseVolumeReceipt{$match['hashId']}");

    $keyboard = kbOnlyCancel("cancelIncreaseVolumeReceipt", $match['hashId']);
    sendMessage($mainValues['please_send_receipt_image'], $keyboard, "HTML");
    exit;
}

if (preg_match('/cancelIncreaseVolumeReceipt(?<hashId>.*)/', $data, $match)) {
    handleCancelFlow($mainValues, $removeKeyboard);
}

if (preg_match('/waitIncreaseVolumeReceipt(.*)/', $userInfo['step'], $match)) {

    $fileid = extractPhotoFileId($update);
    if (!$fileid) {
        $keyboard = kbOnlyCancel("cancelIncreaseVolumeReceipt", $match[1]);
        sendMessage($mainValues['please_send_only_image'], $keyboard, "HTML");
        exit;
    }

    $payParam = dbFetchPayByHash($connection, $match[1]);
    if (!$payParam) {
        prepareStartFallback();
        delMessage();
        return '__ACHBOT_V8_GOTO_START__';
    }

    $payType = $payParam['type'];

    preg_match('/^INCREASE_VOLUME_(\d+)_(\d+)/', $payType, $increaseInfo);
    $orderId = (int)$increaseInfo[1];
    $planid  = (int)$increaseInfo[2];

    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $orderInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $remark = $orderInfo['remark'];

    $stmt = $connection->prepare("SELECT * FROM `increase_plan` WHERE `id` = ?");
    $stmt->bind_param("i", $planid);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $price  = $payParam['price'];
    $volume = $res['volume'];

    sendWithStartButton($mainValues['order_submitted'], $buttonValues['start_bot'], $botUsername, 'mainMenu', 'HTML');

    $msg = str_replace(
        ['INCREASE', 'TYPE', "USER-ID", "USERNAME", "NAME", "PRICE", "REMARK"],
        [$volume, 'حجم', $from_id, $username, $first_name, $price, $remark],
        $mainValues['increase_account_request_message']
    );

    $adminKeyboard = buildAdminKeyboardWithStats(
        $connection,
        $buttonValues,
        "approveIncreaseVolume{$match[1]}",
        "declineAction_{$match[1]}",
        $match[1],
        (int)$payParam['user_id']
    );

    sendReceiptToAdminsAndUpdatePay($connection, $fileid, $msg, $adminKeyboard, $admin, $match[1]);
    setUser();
    exit;
}

if (preg_match('/approveIncreaseVolume(.*)/', $data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $connection->begin_transaction();
    try {
        $hash_id = $match[1];
        $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? FOR UPDATE");
        $stmt->bind_param("s", $hash_id);
        $stmt->execute();
        $payParam = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$payParam || ($payParam['state'] === 'expired_have_sent')) {
            $connection->rollback();
            alert(!$payParam ? "⛔️ اطلاعات پرداخت پیدا نشد." : "⛔️ این درخواست منقضی شده است.");
            return;
        }

        if ($payParam['state'] === 'approved' || $payParam['state'] === 'declined') {
            $connection->rollback();
            alert("⛔️ این درخواست قبلاً بررسی شده است.");
            return;
        }

        $payType = $payParam['type'];
        if (!preg_match('/^INCREASE_VOLUME_(\d+)_(\d+)/', $payType, $increaseInfo)) {
            throw new Exception("invalid_increase_type");
        }

        $orderId = intval($increaseInfo[1]);
        $planid  = intval($increaseInfo[2]);
        $uid     = intval($payParam['user_id']);

        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
        $stmt->bind_param("i", $orderId);
        $stmt->execute();
        $orderInfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$orderInfo) {
            throw new Exception("order_not_found");
        }

        $server_id  = intval($orderInfo['server_id']);
        $inbound_id = intval($orderInfo['inbound_id']);
        $remark     = $orderInfo['remark'];
        $uuid       = $orderInfo['uuid'] ?? "0";

        $stmt = $connection->prepare("SELECT * FROM `increase_plan` WHERE `id` = ?");
        $stmt->bind_param("i", $planid);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$res) {
            throw new Exception("increase_plan_not_found");
        }

        $price  = intval($res['price']);
        $volume = intval($res['volume']);

        $stmt = $connection->prepare("SELECT * FROM server_config WHERE id = ?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $server_info = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$server_info) {
            throw new Exception("server_config_missing");
        }

        $serverType = $server_info['type'];

        if ($serverType == "marzban") {
            $response = editMarzbanConfig($server_id, ['remark' => $remark, 'plus_volume' => $volume]);
        } else {
            if ($inbound_id > 0) {
                $response = editClientTraffic($server_id, $inbound_id, $uuid, $volume, 0);
            } else {
                $response = editInboundTraffic($server_id, $uuid, $volume, 0);
            }
        }

        if (is_null($response)) {
            throw new Exception("server_connection_null");
        }
        if (!is_object($response) && !is_array($response)) {
            throw new Exception("invalid_response_type");
        }
        $success = (is_object($response) && !empty($response->success))
                || (is_array($response)  && !empty($response['success']));
        if (!$success) {
            $msg = is_object($response) ? ($response->msg ?? 'no_msg')
                 : ($response['msg'] ?? 'no_msg');
            throw new Exception("server_response_failed: ".$msg);
        }

        $now = time();
        $stmt = $connection->prepare("INSERT INTO `increase_order` (`userid`, `server_id`, `inbound_id`, `remark`, `amount`, `date`, `volume`) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iiisiii", $uid, $server_id, $inbound_id, $remark, $price, $now, $volume);
        $stmt->execute();
        $stmt->close();

        $stmt = $connection->prepare("UPDATE `orders_list` SET `notif` = 0 WHERE `uuid` = ?");
        $stmt->bind_param("s", $uuid);
        $stmt->execute();
        $stmt->close();

        $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'approved', `approved_by` = ? WHERE `hash_id` = ?");
        $stmt->bind_param("is", $from_id, $hash_id);
        $stmt->execute();
        $stmt->close();

        $connection->commit();

        $keys = json_encode([
            'inline_keyboard' => [
                [['text' => "✅ تایید شد توسط $from_id", 'callback_data' => "wizwizch"]],
                [['text' => "🆔 $hash_id", 'callback_data' => "noop_$hash_id"]]
            ]
        ], 488);

        // ویرایش پیام ادمین اصلی
        editKeys($keys, $payParam['message_id'], $payParam['chat_id']);

        // ویرایش پیام سایر ادمین ها
        $adminMessages = json_decode($payParam['admins_messages'], true);
        if (is_array($adminMessages)) {
            foreach ($adminMessages as $msgInfo) {
                if (
                    isset($msgInfo['chat_id'], $msgInfo['message_id']) &&
                    $msgInfo['chat_id'] != $payParam['chat_id']
                ) {
                    editKeys($keys, $msgInfo['message_id'], $msgInfo['chat_id']);
                }
            }
        }

        sendMessage("✅ <b>$volume</b> گیگ به حجم اشتراک <code>$remark</code> اضافه شد.", null, "HTML", $uid);
        return;

    } catch (Exception $e) {
        $connection->rollback();

        // پیام فنی برای ادمین اقدام‌کننده
        $err = "⚠️ خطا در تایید افزایش حجم (کارت‌به‌کارت)\n\n"
             . "🆔 Hash ID: {$match[1]}\n"
             . "👤 Admin: {$from_id}\n"
             . "📄 Error: " . $e->getMessage();
        sendMessage($err, null, null, $admin);

        alert("❌ افزایش حجم انجام نشد. لطفاً وضعیت سرور/اطلاعات را بررسی کنید.", true);
        return;
    }
}

if(preg_match('/payIncreaseWithWallet(.*)/', $data, $match)){
    $connection->begin_transaction();
    try {
        $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? FOR UPDATE");
        $stmt->bind_param("s", $match[1]);
        $stmt->execute();
        $payRes = $stmt->get_result();
        $stmt->close();

        if ($payRes->num_rows == 0){
            $connection->rollback();
            $text = "/start";
            $data = "";
            delMessage();
            return '__ACHBOT_V8_GOTO_START__';
        }

        $payParam = $payRes->fetch_assoc();
        $hash_id  = $payParam['hash_id'];

        if ($payParam['state'] === "paid_with_wallet") {
            $connection->rollback();
            return;
        }

        $payType = $payParam['type'];
        if (!preg_match('/^INCREASE_VOLUME_(\d+)_(\d+)/', $payType, $increaseInfo)) {
            throw new Exception("invalid_increase_type");
        }
        $orderId = intval($increaseInfo[1]);
        $planid  = intval($increaseInfo[2]);

        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
        $stmt->bind_param("i", $orderId);
        $stmt->execute();
        $orderInfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$orderInfo){
            throw new Exception("order_not_found");
        }

        $server_id  = intval($orderInfo['server_id']);
        $inbound_id = intval($orderInfo['inbound_id']);
        $remark     = $orderInfo['remark'];
        $uuid       = $orderInfo['uuid'] ?? "0";

        $stmt = $connection->prepare("SELECT * FROM `increase_plan` WHERE `id` = ?");
        $stmt->bind_param("i", $planid);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$res){
            throw new Exception("increase_plan_not_found");
        }

        $price  = intval($payParam['price']);
        $volume = intval($res['volume']);

        $uid = intval($payParam['user_id'] ?? $from_id);
        $stmt = $connection->prepare("SELECT `wallet` FROM `users` WHERE `userid` = ? FOR UPDATE");
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $wRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $userwallet = intval($wRow['wallet'] ?? 0);
        if ($userwallet < $price) {
            $needamount = $price - $userwallet;

            // وضعیت پرداخت کمبود موجودی
            $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'low_wallet' WHERE `hash_id` = ?");
            $stmt->bind_param("s", $hash_id);
            $stmt->execute();
            $stmt->close();

            $connection->commit();
            alert("❌ موجودی کیف پول کافی نیست.\n💵 موجودی فعلی: " . number_format($userwallet) . " تومان\n🔺 مورد نیاز: " . number_format($needamount) . " تومان", true);
            return;
        }

        $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $server_info = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$server_info){
            throw new Exception("server_config_missing");
        }

        $serverType = $server_info['type'];

        if($serverType == "marzban"){
            $response = editMarzbanConfig($server_id, ['remark'=>$remark, 'plus_volume'=>$volume]);
        } else {
            if($inbound_id > 0)
                $response = editClientTraffic($server_id, $inbound_id, $uuid, $volume, 0);
            else
                $response = editInboundTraffic($server_id, $uuid, $volume, 0);
        }

        if (is_null($response)) {
            throw new Exception("server_connection_null");
        }
        if (!is_object($response) && !is_array($response)) {
            throw new Exception("invalid_response_type");
        }
        $success = (is_object($response) && !empty($response->success))
                || (is_array($response)  && !empty($response['success']));
        if (!$success) {
            $msg = is_object($response) ? ($response->msg ?? 'no_msg')
                 : ($response['msg'] ?? 'no_msg');
            throw new Exception("server_response_failed: ".$msg);
        }

        $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` - ? WHERE `userid` = ?");
        $stmt->bind_param("ii", $price, $uid);
        $stmt->execute();
        $stmt->close();

        $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'paid_with_wallet' WHERE `hash_id` = ?");
        $stmt->bind_param("s", $hash_id);
        $stmt->execute();
        $stmt->close();

        $stmt = $connection->prepare("UPDATE `orders_list` SET `notif` = 0 WHERE `uuid` = ?");
        $stmt->bind_param("s", $uuid);
        $stmt->execute();
        $stmt->close();

        $timeNow = time();
        $stmt = $connection->prepare("INSERT INTO `increase_order` (`userid`, `server_id`, `inbound_id`, `remark`, `amount`, `date`, `volume`) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iiisiii", $uid, $server_id, $inbound_id, $remark, $price, $timeNow, $volume);
        $stmt->execute();
        $stmt->close();

        // COMMIT
        $connection->commit();

        editText($message_id, "✅ $volume گیگ به حجم اشتراک شما اضافه شد.", getMainKeys());

        $keys = json_encode(['inline_keyboard'=>[
            [
                ['text'=>"🔋 افزایش حجم اشتراک",'callback_data'=>"wizwizch"]
            ],
            [
                ['text'=>"🆔 $hash_id", 'callback_data'=>"noop_$hash_id"]
            ]
        ]]);

        $msg = "
🔋|💰 افزایش حجم با (کیف پول)

🎫 شناسه عددی: $from_id
👨 اسم کاربر: $first_name
⚡️ نام کاربری: @$username
🎈 نام کاربری اشتراک: $remark
🔝 مقدار افزایش: $volume گیگ
💰 قیمت: $price تومان
";
        sendMessage($msg, $keys, "html", $admin);
        //sendToAllAdmins('sendMessage', ['text' => $msg, 'reply_markup' => $keys, 'parse_mode' => "html"]);
		
		// ارسال پیام به کانال گزارشات
        $rewardChannel = $botState['rewardChannel'];
        if (!empty($rewardChannel)) {
            sendMessage($msg, $keys, "html", $rewardChannel);
        }

        return;

    } catch (Exception $e) {
        // بازگردانی تغییرات
        $connection->rollback();

        // پیام فنی برای ادمین
        $adminErr = "⚠️ خطا در افزایش حجم با کیف پول\n\n"
                  . "🆔 Hash ID: {$match[1]}\n"
                  . "👤 User: {$from_id}\n"
                  . "📄 Error: ".$e->getMessage();
        sendMessage($adminErr, null, null, $admin);

        sendMessage("❌ مشکلی پیش اومد.\n🔁 لطفاً ۳۰ ثانیه بعد دوباره تلاش کنید.\nاگر مشکل ادامه داشت، به مدیریت اطلاع دهید.", null, null, $uid);

        return;
    }
}

