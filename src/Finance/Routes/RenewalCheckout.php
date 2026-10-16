<?php
/** AChBot V8: source-pinned financial compatibility module. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

if (preg_match('/^renewAccountAsk(\d+)$/', $data, $match)) {
    $oid = (int)$match[1];

    $keyboard = json_encode([
        'inline_keyboard' => [
            [
                ['text' => "✅ تأیید تمدید", 'callback_data' => "renewAccount{$oid}"]
            ],
            [
                ['text' => "❌ انصراف", 'callback_data' => "mySubscriptions"]
            ]
        ]
    ]);

    sendMessage("⚠️ دقت کنید با تمدید اشتراک، در صورتی که حجم باقی مانده داشته باشید منتقل نمی شود.\n✅ می توانید کیف پول خود را شارژ کنید و گزینه تمدید خودکار را روشن کنید تا هر زمان اشتراک شما تمام شد خودکار تمدید شود.\n🔰 در صورت تمایل به ادامه روی دکمه «تایید تمدید» بزنید.", $keyboard);
    exit();
}

if (
    (
        preg_match('/^discountRenew(\d+)_(\d+)$/', $userInfo['step'], $match) ||
        preg_match('/^renewAccount(\d+)$/', $data, $match)
    ) &&
    $text != $buttonValues['cancel']
) {
    
    // اضافه کردن بررسی برای ستون fileid
    $oid = $match[1];
    
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
    
    $order = $order->fetch_assoc();
    $fid = $order['fileid'];
    if (!achbotSupportsMarzbanOrder($connection, (int)$oid, (int)$fid, (int)$order['userid'])) {
        alert("⛔️ تمدید این اشتراک در حال حاضر امکان‌پذیر نیست.", true);
        return;
    }
    // بررسی اگر fileid برابر 0 باشد
    if($fid == 0){
        sendMessage("✋ لطفاً ابتدا با زدن دکمه: 
        ← 🔄 تغییر پلن
یک پلن برای اشتراک خود انتخاب کنید و بعد اقدام به تمدید نمایید.");
        exit();
    }
	
	$serverId = $order['server_id'];
    $agentBought = $order['agent_bought'];

    if(preg_match('/^discountRenew/', $userInfo['step'])){
        $rowId = $match[2];
        
        // استفاده از تابع مشترک تخفیف
        $discountResult = applyDiscountToPay(
            $connection,
            $rowId,
            $text,
            $from_id,
            $username,
            $first_name,
            $admin,
            $mainValues,
            $botState
        );

        // قیمت بعد از تخفیف (یا همان قیمت قبلی در صورت نامعتبر بودن کد)
        $afterDiscount = $discountResult['price'];
		$hash_id = $discountResult['hash_id'];

        setUser();
    }else delMessage();

    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ? AND `active` = 1");
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $respd = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $price = $respd['price'];
    if($agentBought == true){
        $discounts = json_decode($userInfo['discount_percent'],true);
        if($botState['agencyPlanDiscount']=="on") $discount = $discounts['plans'][$fid]?? $discounts['normal'];
        else $discount = $discounts['servers'][$serverId]?? $discounts['normal'];
        $price -= floor($price * $discount / 100);
    }
    if(!preg_match('/^discountRenew/', $userInfo['step'])){
        $hash_id = RandomString();
        $stmt = $connection->prepare("DELETE FROM `pays` WHERE `user_id` = ? AND `type` = 'RENEW_ACCOUNT' AND `state` = 'pending'");
        $stmt->bind_param("i", $from_id);
        $stmt->execute();
        $stmt->close();
        
        $time = time();
		$stmt = $connection->prepare("INSERT INTO `pays` (`hash_id`, `user_id`, `type`, `plan_id`, `server_id`, `volume`, `day`, `price`, `request_date`, `state`, `order_id`)
									VALUES (?, ?, 'RENEW_ACCOUNT', ?, ?, '0', '0', ?, ?, 'pending', ?)");
		$stmt->bind_param("siiiiii", $hash_id, $from_id, $fid, $serverId, $price, $time, $oid);
        $stmt->execute();
        $rowId = $stmt->insert_id;
        $stmt->close();
    }else $price = $afterDiscount;

    if($price == 0) $price = "رایگان";
    else $price .= " تومان";
	
	$keyboard = [];
	$actionType = "Renew";
	if ($botState['cartToCartState'] == "on") $keyboard[] = [['text' => "💳 کارت به کارت مبلغ $price", 'callback_data' => "prePayCheck_{$actionType}_CartToCart_{$hash_id}"]];
	if ($botState['nowPaymentOther'] == "on") $keyboard[] = [['text' => $buttonValues['now_payment_gateway'], 'callback_data' => "prePayCheck_{$actionType}_nowpayment_{$hash_id}"]];
	if ($botState['zarinpal'] == "on") $keyboard[] = [['text' => $buttonValues['zarinpal_gateway'], 'callback_data' => "prePayCheck_{$actionType}_zarinpal_{$hash_id}"]];
	if ($botState['nextpay'] == "on") $keyboard[] = [['text' => $buttonValues['nextpay_gateway'], 'callback_data' => "prePayCheck_{$actionType}_nextpay_{$hash_id}"]];
	if ($botState['weSwapState'] == "on") $keyboard[] = [['text' => $buttonValues['weswap_gateway'], 'callback_data' => "prePayCheck_{$actionType}_WeSwap_{$hash_id}"]];
	if ($botState['walletState'] == "on") $keyboard[] = [['text' => "💰پرداخت با موجودی مبلغ $price", 'callback_data' => "prePayCheck_{$actionType}_Wallet_{$hash_id}"]];
	if ($botState['tronWallet'] == "on") $keyboard[] = [['text' => $buttonValues['tron_gateway'], 'callback_data' => "prePayCheck_{$actionType}_TronWallet_{$hash_id}"]];
	if (!preg_match('/^discountRenew/', $userInfo['step'])) $keyboard[] = [['text' => " 🎟 افزودن کد تخفیف ", 'callback_data' => "haveDiscountRenew_{$match[1]}_{$rowId}"]];
	
	$keyboard[] = [['text' => $buttonValues['cancel'], 'callback_data' => "mainMenu"]];
	
	sendMessage("🔰 لطفا یکی از روش های زیر را انتخاب کنید و روی آن بزنید:", json_encode(['inline_keyboard' => $keyboard]));
}

if (preg_match('/payRenewWithCartToCart(.*)/', $data, $match)) {

    $payInfo = dbFetchPayByHash($connection, $match[1]);
    if (!$payInfo) {
        prepareStartFallback();
        delMessage();
        return '__ACHBOT_V8_GOTO_START__';
    }

    $oid = (int)$payInfo['order_id'];

    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $orderRes = $stmt->get_result();
    $stmt->close();

    if (!$orderRes || $orderRes->num_rows == 0) {
        delMessage();
        sendMessage($mainValues['config_not_found'], getMainKeys());
        exit;
    }

    delMessage();
    setUser("payRenewWithCartToCart{$match[1]}");

	// $msg = buildCartToCartInfoMessage($paymentKeys, $mainValues, (int)$payInfo['price']);
	$msg = buildCartToCartInfoMessage($paymentKeys, $mainValues, (int)$payInfo['price'], (int)$payInfo['user_id']);
    $keyboard = kbSendReceiptAndCancel("sendRenewCartReceipt", "cancelRenewCartReceipt", $match[1]);

    sendMessage($msg, $keyboard, "HTML");
    exit;
}

if (preg_match('/sendRenewCartReceipt(?<hashId>.*)/', $data, $match)) {

    $payInfo = dbFetchPayByHash($connection, $match['hashId']);
    if (!$payInfo) {
        prepareStartFallback();
        delMessage();
        return '__ACHBOT_V8_GOTO_START__';
    }

    $guardMsg = duplicateReceiptGuard($connection, $payInfo, time());
    if ($guardMsg) { alert($guardMsg, true); exit; }

    delMessage();
    setUser("waitRenewCartReceipt{$match['hashId']}");

    $keyboard = kbOnlyCancel("cancelRenewCartReceipt", $match['hashId']);
    sendMessage($mainValues['please_send_receipt_image'], $keyboard, "HTML");
    exit;
}

if (preg_match('/cancelRenewCartReceipt(?<hashId>.*)/', $data, $match)) {
    handleCancelFlow($mainValues, $removeKeyboard);
}

if (preg_match('/waitRenewCartReceipt(.*)/', $userInfo['step'], $match)) {

    $fileid = extractPhotoFileId($update);
    if (!$fileid) {
        $keyboard = kbOnlyCancel("cancelRenewCartReceipt", $match[1]);
        sendMessage($mainValues['please_send_only_image'], $keyboard, "HTML");
        exit;
    }

    $payInfo = dbFetchPayByHash($connection, $match[1]);
    if (!$payInfo) {
        prepareStartFallback();
        delMessage();
        return '__ACHBOT_V8_GOTO_START__';
    }

    $hash_id = $payInfo['hash_id'];

    $now = time();
    $requestTime = (int)$payInfo['request_date'];
    if ($now - $requestTime > 10800) {
        delMessage();
        sendMessage("⏳ مهلت این خرید به پایان رسیده است.\n♻️ لطفاً مجدداً روی دکمه تمدید اشتراک کلیک کرده و خرید جدیدی ثبت کنید.");
        setUser();
        sendMessage($mainValues['reached_main_menu'], getMainKeys());
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
        sendMessage($mainValues['config_not_found'], getMainKeys());
        exit;
    }

    $order = $orderRes->fetch_assoc();

    $fid = (int)$order['fileid'];
    $savedFid = (int)$payInfo['plan_id'];

    if ($fid != $savedFid) {
        delMessage();
        sendMessage("❗️پلن شما از زمان شروع پرداخت تا ارسال رسید تغییر کرده است.\n♻️ لطفاً دوباره روی دکمه تمدید اشتراک کلیک کرده و پرداخت جدید انجام دهید.");
        setUser();
        sendMessage($mainValues['reached_main_menu'], getMainKeys());
        exit;
    }

    $remark = $order['remark'];

    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ? AND `active` = 1");
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $respd = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $price  = $payInfo['price'];
    $volume = $respd['volume'];
    $days   = $respd['days'];

    sendWithStartButton($mainValues['order_submitted'], $buttonValues['start_bot'], $botUsername, 'mainMenu', 'HTML');

    $msg = str_replace(
        ['TYPE', "USER-ID", "USERNAME", "NAME", "PRICE", "REMARK", "VOLUME", "DAYS"],
        ['کارت به کارت', $from_id, $username, $first_name, $price, $remark, $volume, $days],
        $mainValues['renew_account_request_message']
    );

    $adminKeyboard = buildAdminKeyboardWithStats(
        $connection,
        $buttonValues,
        "approveRenewAcc{$hash_id}",
        "declineAction_{$hash_id}",
        $hash_id,
        (int)$payInfo['user_id']
    );

    $resPhoto = sendPhoto($fileid, $msg, $adminKeyboard, "HTML", $admin);
    sendToAllAdmins('sendPhoto', [
        'photo' => $fileid,
        'caption' => $msg,
        'reply_markup' => $adminKeyboard,
        'parse_mode' => "HTML"
    ], $match[1]);

    $msgId = $resPhoto->result->message_id ?? null;
    setUser();

    if ($msgId) {
        $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'have_sent', `message_id` = ?, `chat_id` = ? WHERE `hash_id` = ?");
        $stmt->bind_param("iis", $msgId, $admin, $match[1]);
        $stmt->execute();
        $stmt->close();
    }

    exit;
}

if(preg_match('/approveRenewAcc(.*)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){ 
    $connection->begin_transaction();
    try {
        $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? FOR UPDATE"); 
        $stmt->bind_param("s", $match[1]); 
        $stmt->execute(); 
        $payInfo = $stmt->get_result()->fetch_assoc();
        if (!achbotSupportsPaymentSubject($connection, $payInfo)) {
            $connection->rollback();
            alert("⛔️ تأیید پرداخت این سرویس در مرزبان مجاز نیست.", true);
            return;
        }
        $stmt->close(); 

        if (!$payInfo || ($payInfo['state'] === 'expired_have_sent')) { 
            $connection->rollback();
            alert(!$payInfo ? "⛔️ اطلاعات پرداخت پیدا نشد." : "⛔️ این درخواست منقضی شده است."); 
            return; 
        } 

        if ($payInfo['state'] === 'approved' || $payInfo['state'] === 'declined') { 
            $connection->rollback();
            alert("⛔️ این درخواست قبلاً بررسی شده است."); 
            return; 
        } 

        $hash_id = $payInfo['hash_id']; 
        $uid     = intval($payInfo['user_id']); 
        $oid     = intval($payInfo['order_id']); 
        $price   = intval($payInfo['price']);

        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?"); 
        $stmt->bind_param("i", $oid); 
        $stmt->execute(); 
        $order = $stmt->get_result()->fetch_assoc(); 
        $stmt->close(); 

        if (!$order){
            $connection->rollback();
            delMessage();
            sendMessage($mainValues['config_not_found'], getMainKeys(), null, $uid);
            return;
        }

        $fid        = intval($order['fileid']); 
        $remark     = $order['remark']; 
        $uuid       = $order['uuid'] ?? "0"; 
        $server_id  = intval($order['server_id']); 
        $inbound_id = intval($order['inbound_id']); 

        $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ? AND `active` = 1"); 
        $stmt->bind_param("i", $fid); 
        $stmt->execute(); 
        $respd = $stmt->get_result()->fetch_assoc(); 
        $stmt->close(); 
        if(!$respd){
            $connection->rollback();
            sendMessage($mainValues['config_not_found'], getMainKeys(), null, $uid);
            return;
        }
        $name   = $respd['title']; 
        $days   = intval($respd['days']); 
        $volume = intval($respd['volume']); 

        $stmt = $connection->prepare("SELECT * FROM server_config WHERE id = ?"); 
        $stmt->bind_param("i", $server_id); 
        $stmt->execute(); 
        $server_info = $stmt->get_result()->fetch_assoc(); 
        $stmt->close(); 
        if(!$server_info){
            throw new Exception("server_config_missing");
        }
        $serverType = $server_info['type']; 

        $deferredAdminKeys = [
            'chat_id'    => $payInfo['chat_id'],
            'message_id' => $payInfo['message_id'],
            'keys'       => json_encode([
                'inline_keyboard' => [
                    [['text' => "✅ تایید شد توسط $from_id", 'callback_data' => "wizwizch"]],
                    [['text' => "🆔 $hash_id", 'callback_data' => "noop_$hash_id"]]
                ]
            ], 488),
            'admins_messages' => $payInfo['admins_messages']
        ];

        if ($serverType == "marzban") { 
            $response = editMarzbanConfig($server_id, ['remark'=>$remark, 'days'=>$days, 'volume' => $volume]); 
        } else {
            throw new \UnexpectedValueException('unsupported_panel');
        } 

        if (is_null($response)) { 
            throw new Exception('server_connection_null'); 
        } 
        if (!is_object($response) && !is_array($response)) {
            throw new Exception('invalid_response_type');
        }
        $success = (is_object($response) && !empty($response->success))
                || (is_array($response)  && !empty($response['success']));
        if (!$success) {
            $msg = is_object($response) ? ($response->msg ?? 'no_msg')
                 : ($response['msg'] ?? 'no_msg');
            throw new Exception("server_response_failed: ".$msg);
        }

        // آپدیت expire + notif
        $newExpire = time() + $days * 86400; 
        $stmt = $connection->prepare("UPDATE `orders_list` SET `expire_date` = ?, `notif` = 0 WHERE `id` = ?"); 
        $stmt->bind_param("ii", $newExpire, $oid); 
        $stmt->execute(); 
        $stmt->close(); 

        // ثبت در increase_order
        $now = time();
        $stmt = $connection->prepare("INSERT INTO `increase_order` (`userid`, `server_id`, `inbound_id`, `remark`, `amount`, `date`) VALUES (?, ?, ?, ?, ?, ?)"); 
        $stmt->bind_param("iiisii", $uid, $server_id, $inbound_id, $remark, $price, $now); 
        $stmt->execute(); 
        $stmt->close(); 

        // پرداخت را نهایتاً approved کن (فقط پس از موفقیت همه مراحل)
        $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'approved', `approved_by` = ? WHERE `hash_id` = ?"); 
        $stmt->bind_param("is", $from_id, $hash_id); 
        $stmt->execute(); 
        $stmt->close(); 

        // COMMIT
        $connection->commit();

        editKeys($deferredAdminKeys['keys'], $deferredAdminKeys['message_id'], $deferredAdminKeys['chat_id']); 
        // ویرایش پیام سایر ادمین‌ها
        $adminMessages = json_decode($deferredAdminKeys['admins_messages'], true); 
        if (is_array($adminMessages)) { 
            foreach ($adminMessages as $msgInfo) { 
                if (isset($msgInfo['chat_id'], $msgInfo['message_id']) && $msgInfo['chat_id'] != $deferredAdminKeys['chat_id']) { 
                    editKeys($deferredAdminKeys['keys'], $msgInfo['message_id'], $msgInfo['chat_id']); 
                } 
            } 
        } 

        // پیام موفقیت به کاربر
        sendMessage(str_replace(["USERID", "REMARK", "VOLUME", "DAYS", "PRICE", "HASHID"],
                    [$uid, $remark, $volume, $days, $price, $hash_id], $mainValues['renewed_config_to_user']),
                    getMainKeys(), null, null); 
        sendMessage("✅ اشتراک <code>$remark</code> با موفقیت تمدید شد.", null, "HTML", $uid); 
        return;

    } catch (Exception $e) {
        $connection->rollback();

        $err = "⚠️ خطا در تایید تمدید کارت‌به‌کارت\n\n"
             . "🆔 Hash ID: {$match[1]}\n"
             . "👤 Admin: {$from_id}\n"
             . "📄 Error: " . $e->getMessage();
        sendMessage($err, null, null, $admin);

        alert('❌ تمدید انجام نشد. لطفاً وضعیت اشتراک را بررسی کنید و در صورت نیاز با پشتیبانی تماس بگیرید.', true);
        return;
    }
}

if(preg_match('/payRenewWithWallet(.*)/', $data, $match)){
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

        $payInfo = $payRes->fetch_assoc();
        if (!achbotSupportsPaymentSubject($connection, $payInfo)) {
            $connection->rollback();
            alert("⛔️ تمدید این اشتراک انجام نشد؛ لطفاً با پشتیبانی تماس بگیرید.", true);
            return;
        }
        $hash_id   = $payInfo['hash_id'];
        $oid       = intval($payInfo['order_id']);
        $price     = intval($payInfo['price']);
        $uid       = intval($payInfo['user_id']);

        if ($payInfo['state'] === "paid_with_wallet") {
            $connection->rollback();
            return;
        }

        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
        $stmt->bind_param("i", $oid);
        $stmt->execute();
        $orderRes = $stmt->get_result();
        $stmt->close();

        if ($orderRes->num_rows == 0){
            $connection->rollback();
            delMessage();
            sendMessage($mainValues['config_not_found'], getMainKeys(), null, $uid);
            return;
        }

        $order = $orderRes->fetch_assoc();
        $fid         = intval($order['fileid']);
        $remark      = $order['remark'];
        $uuid        = $order['uuid'] ?? "0";
        $server_id   = intval($order['server_id']);
        $inbound_id  = intval($order['inbound_id']);

        $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ? AND `active` = 1");
        $stmt->bind_param("i", $fid);
        $stmt->execute();
        $plan = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$plan){
            $connection->rollback();
            sendMessage($mainValues['config_not_found'], getMainKeys(), null, $uid);
            return;
        }

        $name   = $plan['title'];
        $days   = intval($plan['days']);
        $volume = intval($plan['volume']);

        $stmt = $connection->prepare("SELECT `wallet` FROM `users` WHERE `userid` = ? FOR UPDATE");
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $wRes = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $userwallet = intval($wRes['wallet'] ?? 0);
        if ($userwallet < $price) {
            $needamount = $price - $userwallet;

            $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'low_wallet' WHERE `hash_id` = ?");
            $stmt->bind_param("s", $hash_id);
            $stmt->execute();
            $stmt->close();

            $connection->commit(); // همین تغییر state ثبت شود
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
            $response = editMarzbanConfig($server_id, ['remark'=>$remark, 'days'=>$days, 'volume' => $volume]);
        } else {
            throw new \UnexpectedValueException('unsupported_panel');
        }

        if (is_null($response)) {
            throw new Exception("server_null_response");
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

        $timeNow  = time();
        $newExpire = $timeNow + ($days * 86400);
        $stmt = $connection->prepare("UPDATE `orders_list` SET `expire_date` = ?, `notif` = 0 WHERE `id` = ?");
        $stmt->bind_param("ii", $newExpire, $oid);
        $stmt->execute();
        $stmt->close();

        $stmt = $connection->prepare("INSERT INTO `increase_order` (`userid`, `server_id`, `inbound_id`, `remark`, `amount`, `date`) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iiisii", $uid, $server_id, $inbound_id, $remark, $price, $timeNow);
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
        editText($message_id, "✅ اشتراک $remark با موفقیت تمدید شد.", getMainKeys());

        $keys = json_encode(['inline_keyboard'=>[
            [
                ['text'=>"♻️ تمدید اشتراک",'callback_data'=>"wizwizch"]
            ],
            [
                ['text'=>"🆔 $hash_id", 'callback_data'=>"noop_$hash_id"]
            ]
        ]]);

        $msg = str_replace(
            ['TYPE', "USER-ID", "USERNAME", "NAME", "PRICE", "REMARK", "VOLUME", "DAYS"],
            ['کیف پول', $from_id, $username, $first_name, $price, $remark, $volume, $days],
            $mainValues['renew_account_request_message']
        );
        sendMessage($msg, $keys, "html", $admin);
		//sendToAllAdmins('sendMessage', ['text' => $msg, 'reply_markup' => $keys, 'parse_mode' => "html"]);
		
		// ارسال پیام به کانال گزارشات
        $rewardChannel = $botState['rewardChannel'];
        if (!empty($rewardChannel)) {
            sendMessage($msg, $keys, "html", $rewardChannel);
        }

        return;

    } catch (Exception $e) {
        $connection->rollback();

        // پیام فنی به ادمین
        $adminErr = "⚠️ خطا در تمدید با کیف پول\n\n"
                  . "🆔 Hash ID: {$match[1]}\n"
                  . "👤 User: {$from_id}\n"
                  . "📄 Error: ".$e->getMessage();
        sendMessage($adminErr, null, null, $admin);

        sendMessage("❌ مشکلی پیش اومد.\n🔁 لطفاً ۳۰ ثانیه بعد دوباره تلاش کنید.\nاگر مشکل ادامه داشت، به مدیریت اطلاع دهید.", null, null, $uid);

        return;
    }
}