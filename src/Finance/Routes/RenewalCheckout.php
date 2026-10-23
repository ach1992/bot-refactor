<?php
/** AChBot V8: source-pinned financial compatibility module. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../PaymentLifecycle.php';

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
        achbotRemoveUnclaimedPendingPayments($connection,$from_id,'RENEW_ACCOUNT');
        
        $time = time();
		$stmt = $connection->prepare("INSERT INTO `pays` (`hash_id`, `user_id`, `type`, `plan_id`, `server_id`, `volume`, `day`, `price`, `request_date`, `state`, `order_id`)
									VALUES (?, ?, 'RENEW_ACCOUNT', ?, ?, '0', '0', ?, ?, 'pending', ?)");
		$stmt->bind_param("siiiiii", $hash_id, $from_id, $fid, $serverId, $price, $time, $oid);
        $rowId=achbotPayUxInsertDraft($connection,(int)$from_id,$stmt);
        $stmt->close();
        if ($rowId<=0) {
            alert('⚠️ صدور پیش‌نویس به‌طور قطعی تأیید نشد؛ برای پیگیری از پشتیبانی کمک بگیرید.',true);
            return;
        }
    }else $price = $afterDiscount;

    if($price == 0) $price = "رایگان";
    else $price .= " تومان";
	
	$keyboard = [];
	$actionType = "Renew";
	if (achbotManualEnabled('bank_transfer') && achbotPayUxAudienceAllowed($connection,'bank_transfer',(int)$from_id)) $keyboard[] = [['text' => "💳 کارت به کارت مبلغ $price", 'callback_data' => "prePayCheck_{$actionType}_CartToCart_{$hash_id}"]];
	if (achbotManualEnabled('gift_card') && achbotPayUxAudienceAllowed($connection,'gift_card',(int)$from_id)) $keyboard[] = [['text' => "🎁 گیفت‌کارت مبلغ $price", 'callback_data' => "prePayCheck_{$actionType}_GiftCard_{$hash_id}"]];
	if (achbotManualCryptoEnabled() && (achbotPayUxAudienceAllowed($connection,'manual_usdt',(int)$from_id) || achbotPayUxAudienceAllowed($connection,'manual_trx',(int)$from_id))) $keyboard[] = [['text' => "💎 پرداخت رمزارزی دستی مبلغ $price", 'callback_data' => "prePayCheck_{$actionType}_ManualUsdt_{$hash_id}"]];
	if (($botState['nowPaymentOther'] == "on" && achbotPayUxAudienceAllowed($connection,'nowpayments',(int)$from_id))) $keyboard[] = [['text' => $buttonValues['now_payment_gateway'], 'callback_data' => "prePayCheck_{$actionType}_nowpayment_{$hash_id}"]];
	if (($botState['zarinpal'] == "on" && achbotPayUxAudienceAllowed($connection,'zarinpal',(int)$from_id))) $keyboard[] = [['text' => $buttonValues['zarinpal_gateway'], 'callback_data' => "prePayCheck_{$actionType}_zarinpal_{$hash_id}"]];
	if (($botState['nextpay'] == "on" && achbotPayUxAudienceAllowed($connection,'nextpay',(int)$from_id))) $keyboard[] = [['text' => $buttonValues['nextpay_gateway'], 'callback_data' => "prePayCheck_{$actionType}_nextpay_{$hash_id}"]];
	if (($botState['walletState'] == "on" && achbotPayUxAudienceAllowed($connection,'wallet',(int)$from_id))) $keyboard[] = [['text' => "💰پرداخت با موجودی مبلغ $price", 'callback_data' => "prePayCheck_{$actionType}_Wallet_{$hash_id}"]];
	if (!preg_match('/^discountRenew/', $userInfo['step'])) $keyboard[] = [['text' => " 🎟 افزودن کد تخفیف ", 'callback_data' => "haveDiscountRenew_{$match[1]}_{$rowId}"]];
	
	$keyboard[] = [['text' => $buttonValues['cancel'], 'callback_data' => "mainMenu"]];
	
    $sent=sendMessage("🧾 روش پرداخت این تمدید را انتخاب کنید. فقط یک روش فعال را ادامه دهید.", json_encode(['inline_keyboard' => $keyboard]));
    require_once __DIR__ . '/../PaymentLifecycle.php';
    achbotPayUxTrackByHash($connection,(string)$hash_id,(int)$from_id,$sent);
}

if (preg_match('/payRenewWith(?:CartToCart|GiftCard|ManualUsdt)(.*)/', $data, $match)) {

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

    $method = strpos($data, 'WithManualUsdt') !== false ? (($GLOBALS['achbotManualCryptoChoice'] ?? '') === 'TRON' ? 'manual_trx' : 'manual_usdt') :
        (strpos($data, 'WithGiftCard') !== false ? 'gift_card' : 'bank_transfer');
    if (!achbotManualClaim($connection, $payInfo, $method, (int)$from_id,
                           $GLOBALS['achbotManualCryptoChoice'] ?? null)) {
        alert("⚠️ پرداخت این سفارش با این روش مجاز نیست یا قبلاً به روش دیگری آغاز شده است.", true);
        return;
    }

	$msg = buildCartToCartInfoMessage($paymentKeys, $mainValues, (int)$payInfo['price'], (int)$payInfo['user_id'], $method, (string)$payInfo['hash_id']);
    if ($msg === "") {
        alert("⚠️ اطلاعات این پرداخت قابل بازیابی نیست؛ با پشتیبانی تماس بگیرید.", true);
        return;
    }
    $keyboard = kbSendReceiptAndCancel(in_array($method, ['manual_usdt','manual_trx'], true) ? "sendRenewUsdtReceipt" : ($method === 'gift_card' ? "sendRenewGiftReceipt" : "sendRenewCartReceipt"), "cancelRenewCartReceipt", $match[1], $method);

    $delivered = sendMessage($msg, $keyboard, "HTML");
    require_once __DIR__ . '/../PaymentLifecycle.php';
    achbotPayUxTrackByHash($connection,(string)$payInfo['hash_id'],(int)$from_id,$delivered);
    if (is_object($delivered) && !empty($delivered->ok)) {
    setUser("payRenewWith" . (in_array($method, ['manual_usdt','manual_trx'], true) ? 'ManualUsdt' : ($method === 'gift_card' ? 'GiftCard' : 'CartToCart')) . $match[1]);
        delMessage();
    } else {
        // The pinned attempt may be reopened. Never erase the selection menu
        // if Telegram failed to deliver the replacement instructions.
        alert("⚠️ نمایش اطلاعات پرداخت تأیید نشد. از همان دکمه دوباره تلاش کنید و وجهی ارسال نکنید.", true);
    }
    exit;
}

if (preg_match('/send(?:RenewCart|RenewGift|RenewUsdt)Receipt(?<hashId>.*)/', $data, $match)) {

    $payInfo = dbFetchPayByHash($connection, $match['hashId']);
    if (!$payInfo) {
        prepareStartFallback();
        delMessage();
        return '__ACHBOT_V8_GOTO_START__';
    }

    $method = strpos($data, 'UsdtReceipt') !== false ? achbotManualCryptoReceiptProviderByHash($connection, (string)$match['hashId']) :
        (strpos($data, 'GiftReceipt') !== false ? 'gift_card' : 'bank_transfer');
    if (!achbotManualReceiptAllowed($connection, $payInfo, $method, (int)$from_id)) {
        alert("⚠️ این رسید با روش پرداخت انتخاب‌شده مطابقت ندارد یا سفارش در انتظار پرداخت نیست.", true);
        return;
    }
    $guardMsg = duplicateReceiptGuard($connection, $payInfo, time());
    if ($guardMsg) { alert($guardMsg, true); exit; }

    // Explicit user intent to send a receipt, not a claim of paid funds.
    // Serialize with the six-hour unstarted-invoice expiry sweep.
    require_once __DIR__ . '/../PaymentLifecycle.php';
    if (!achbotPayUxStartManualReceipt($connection,(int)$payInfo['id'],
                                      $method,(int)$from_id)) {
        alert('⌛️ مهلت شروع ارسال رسید پایان یافته یا فاکتور در حال بررسی است. اگر وجهی فرستاده‌اید با پشتیبانی تماس بگیرید.',true);
        return;
    }

    delMessage();
    setUser((in_array($method, ['manual_usdt','manual_trx'], true) ? "waitRenewUsdtReceipt" : ($method === 'gift_card' ? "waitRenewGiftReceipt" : "waitRenewCartReceipt")) . $match['hashId']);

    $keyboard = kbOnlyCancel("cancelRenewCartReceipt", $match['hashId']);
    $sent=sendMessage(achbotManualReceiptPrompt($mainValues, $method), $keyboard, "HTML");
    require_once __DIR__ . '/../PaymentLifecycle.php';
    achbotPayUxTrackByHash($connection,(string)$match['hashId'],(int)$from_id,$sent);
    exit;
}

if (preg_match('/cancelRenewCartReceipt(?<hashId>.*)/', $data, $match)) {
    handleCancelFlow($mainValues, $removeKeyboard);
}

if (preg_match('/wait(?:RenewCart|RenewGift|RenewUsdt)Receipt(.*)/', $userInfo['step'], $match)) {

    $method = strpos($userInfo['step'], 'UsdtReceipt') !== false ? achbotManualCryptoReceiptProviderByHash($connection, (string)$match[1]) :
        (strpos($userInfo['step'], 'GiftReceipt') !== false ? 'gift_card' : 'bank_transfer');
    $receiptPay = dbFetchPayByHash($connection, $match[1]);
    if (!$receiptPay || !achbotManualReceiptAllowed($connection, $receiptPay, $method, (int)$from_id)) {
        setUser();
        alert("⚠️ این سفارش قابل ثبت رسید نیست؛ برای بررسی با پشتیبانی تماس بگیرید.", true);
        return;
    }
    $fileid = extractPhotoFileId($update);
    if (!$fileid) {
        $keyboard = kbOnlyCancel("cancelRenewCartReceipt", $match[1]);
        sendMessage($mainValues['please_send_only_image'], $keyboard, "HTML");
        exit;
    }

    $deliveryToken = achbotManualBeginEvidence($connection, $receiptPay, $method, (int)$from_id, $fileid);
    if ($deliveryToken === null) {
        alert("⚠️ ثبت یا ارسال رسید در جریان است. همان تصویر را دوباره ارسال کنید؛ در صورت تداوم مشکل با پشتیبانی تماس بگیرید.", true);
        return;
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



    $msg = str_replace(
        ['TYPE', "USER-ID", "USERNAME", "NAME", "PRICE", "REMARK", "VOLUME", "DAYS"],
        [achbotManualLabel($method), $from_id, $username, $first_name, $price, $remark, $volume, $days],
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

    if (sendReceiptToAdminsAndUpdatePay($connection, $fileid,
        achbotManualCaption($connection, $receiptPay, $msg), $adminKeyboard, $admin, $match[1],
        $deliveryToken)) {
        setUser();
        sendWithStartButton(achbotManualAcknowledgement($receiptPay, $method), $buttonValues['start_bot'], $botUsername, 'mainMenu', 'HTML', (string)$receiptPay['hash_id']);
    } else {
        sendMessage("📥 تصویر برای پیگیری ثبت شده اما تأیید ارسال به مدیر دریافت نشده است. لطفاً همان تصویر را دوباره بفرستید یا با پشتیبانی تماس بگیرید. پرداخت مجدد انجام ندهید.");
    }

    exit;
}

if(preg_match('/approveRenewAcc(.*)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){ 
    $connection->begin_transaction();
    $localCommitted=false;
    try {
        $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? FOR UPDATE"); 
        $stmt->bind_param("s", $match[1]); 
        $stmt->execute(); 
        $payInfo = $stmt->get_result()->fetch_assoc();
        if (!achbotSupportsPaymentSubject($connection, $payInfo, true)) {
            $connection->rollback();
            alert("⛔️ تأیید پرداخت این سرویس در مرزبان مجاز نیست.", true);
            return;
        }
        $stmt->close(); 

        if (!achbotManualServiceApprovalAllowedLocked($connection, $payInfo, 'renewal')) {
            $connection->rollback();
            alert('⛔️ این تمدید رسید دستی معتبر و در انتظار تأیید ندارد.', true);
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

        if ($uid!==(int)$order['userid'] || (int)$order['status']!==1 ||
            (int)$payInfo['plan_id']!==$fid ||
            (int)$payInfo['server_id']!==$server_id ||
            $price<=0 || (string)$payInfo['type']!=='RENEW_ACCOUNT')
            throw new RuntimeException('manual_renew_subject_invalid');
        if (!achbotSupportsPanel($serverType))
            throw new UnexpectedValueException('unsupported_panel');
        // Durable single-consumer receipt claim before remote mutation.
        $payId=(int)$payInfo['id'];
        $stmt=$connection->prepare("UPDATE pays SET state='processing'
            WHERE id=? AND user_id=? AND hash_id=? AND type='RENEW_ACCOUNT'
              AND price=? AND state='have_sent'");
        $stmt->bind_param('iisi',$payId,$uid,$hash_id,$price);
        $claimed=$stmt->execute() && $stmt->affected_rows===1;
        $stmt->close();
        if (!$claimed || $connection->commit()!==true)
            throw new RuntimeException('manual_renew_claim_unconfirmed');
        if (achbotSupportsPanel($serverType)) {
            $response = achbotApplyServiceEdit($connection,$server_id, ['remark'=>$remark, 'days'=>$days, 'volume' => $volume]);
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
            throw new Exception("provider_rejected");
        }

        if (!is_object($response) || ($response->verified??false)!==true)
            throw new RuntimeException('manual_renew_provider_unverified');
        if ($connection->begin_transaction()!==true)
            throw new RuntimeException('manual_renew_finish_begin_failed');
        $stmt=$connection->prepare("SELECT state,user_id,hash_id,type,price
            FROM pays WHERE id=? FOR UPDATE");
        $stmt->bind_param('i',$payId);
        $ok=$stmt->execute();
        $locked=$ok?$stmt->get_result()->fetch_assoc():null;
        $stmt->close();
        if (!$locked || $locked['state']!=='processing' ||
            (int)$locked['user_id']!==$uid || (int)$locked['price']!==$price ||
            (string)$locked['type']!=='RENEW_ACCOUNT' ||
            (string)$locked['hash_id']!==$hash_id)
            throw new RuntimeException('manual_renew_claim_stale');
        achbotStoreVerifiedServiceExpiryLocked($connection,$order,$response);
        $now=time();
        $stmt=$connection->prepare("INSERT INTO increase_order
            (userid,server_id,inbound_id,remark,amount,date)
            VALUES (?,?,?,?,?,?)");
        $stmt->bind_param('iiisii',$uid,$server_id,$inbound_id,$remark,$price,$now);
        $audited=$stmt->execute() && $stmt->affected_rows===1;
        $stmt->close();
        if (!$audited) throw new RuntimeException('manual_renew_audit_unconfirmed');
        $stmt=$connection->prepare("UPDATE pays SET state='approved',approved_by=?
            WHERE id=? AND user_id=? AND hash_id=? AND state='processing'");
        $stmt->bind_param('iiis',$from_id,$payId,$uid,$hash_id);
        $finished=$stmt->execute() && $stmt->affected_rows===1;
        $stmt->close();
        if (!$finished || $connection->commit()!==true)
            throw new RuntimeException('manual_renew_finish_unconfirmed');
        $localCommitted=true;

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

    } catch (Throwable $e) {
        if ($localCommitted) {
            error_log('achbot_manual_renew_notice_unconfirmed pay_id='.(int)($payId??0));
            return;
        }
        try {$connection->rollback();} catch (Throwable $ignored) {}

        $err = "⚠️ خطا در تایید تمدید کارت‌به‌کارت\n\n"
             . "🆔 Hash ID: {$match[1]}\n"
             . "👤 Admin: {$from_id}\n"
             . "⚠️ External effect: unverified; verify before retry or compensation\n📄 Reason (sanitized): " . achbotFinanceDiagnosticReason($e);
        sendMessage($err, null, null, $admin);

        alert('⚠️ نتیجه تمدید نیاز به بررسی دارد. وضعیت اشتراک و پرداخت را کنترل کنید؛ پیش از اقدام مجدد با پشتیبانی هماهنگ شوید.', true);
        return;
    }
}

if(preg_match('/payRenewWithWallet(.*)/', $data, $match)){
    $connection->begin_transaction();
    $localCommitted=false;
    try {
        // Customer-wallet issuance shares the same owner-first lock as drafts
        // and manual/hosted claims. Never lock pays before users.userid.
        achbotPayUxLockDraftOwner($connection,(int)$from_id);
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
        // Same guarded claim policy as purchase wallet callbacks, while
        // this exact pays row is already locked in the current transaction.
        if (!achbotWalletDirectAllowedLocked($connection, $payInfo, (int)$from_id)) {
            $connection->rollback();
            alert("⛔️ این سفارش متعلق به شما نیست یا روش پرداخت دیگری برای آن ثبت شده است؛ دوباره پرداخت نکنید.", true);
            return;
        }

        if (!achbotSupportsPaymentSubject($connection, $payInfo, true)) {
            $connection->rollback();
            alert("⚠️ وضعیت این تمدید نیاز به بررسی دارد. لطفاً وضعیت اشتراک و پرداخت را با پشتیبانی بررسی کنید.", true);
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

        $stmt=$connection->prepare("SELECT id,wallet FROM users
            WHERE userid=? FOR UPDATE");
        $stmt->bind_param('i',$uid);
        if (!$stmt->execute()) throw new RuntimeException('wallet_renew_owner_read_failed');
        $owners=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if (count($owners)!==1)
            throw new RuntimeException('wallet_renew_owner_ambiguous');
        $wRes=$owners[0];
        $userwallet=(int)$wRes['wallet'];
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

        if ((int)$order['userid']!==$uid || (int)$order['status']!==1 ||
            (int)$payInfo['plan_id']!==$fid ||
            (int)$payInfo['server_id']!==$server_id ||
            // The real payment-subject guard above verifies this order's
            // plan against the original server OR an explicitly authorized
            // shared Marzban panel group. Do not narrow it here to equality:
            // legitimate logical reassignment can retain the old plan ID.
            (int)$plan['inbound_id']!==0 || $inbound_id!==0 ||
            (string)$payInfo['type']!=='RENEW_ACCOUNT' || $price<=0)
            throw new RuntimeException('wallet_renew_subject_invalid');
        if (!achbotSupportsPanel($serverType))
            throw new UnexpectedValueException('unsupported_panel');
        // Debit reservation + claim commit atomically BEFORE the external PUT.
        $payId=(int)$payInfo['id'];
        $ownerPk=(int)$wRes['id'];
        $stmt=$connection->prepare("UPDATE users SET wallet=wallet-?
            WHERE id=? AND userid=? AND wallet>=?");
        $stmt->bind_param('iiii',$price,$ownerPk,$uid,$price);
        $reserved=$stmt->execute() && $stmt->affected_rows===1;
        $stmt->close();
        if (!$reserved) throw new RuntimeException('wallet_renew_reservation_unconfirmed');
        $payState=(string)$payInfo['state'];
        $stmt=$connection->prepare("UPDATE pays SET state='processing_wallet'
            WHERE id=? AND user_id=? AND hash_id=? AND type='RENEW_ACCOUNT'
              AND price=? AND state=?");
        $stmt->bind_param('iisis',$payId,$uid,$hash_id,$price,$payState);
        $claimed=$stmt->execute() && $stmt->affected_rows===1;
        $stmt->close();
        if (!$claimed || $connection->commit()!==true)
            throw new RuntimeException('wallet_renew_claim_unconfirmed');

        if (achbotSupportsPanel($serverType)){
            $response = achbotApplyServiceEdit($connection,$server_id, ['remark'=>$remark, 'days'=>$days, 'volume' => $volume]);
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
            throw new Exception("provider_rejected");
        }

        if (!is_object($response) || ($response->verified??false)!==true)
            throw new RuntimeException('wallet_renew_provider_unverified');
        if ($connection->begin_transaction()!==true)
            throw new RuntimeException('wallet_renew_finish_begin_failed');
        $stmt=$connection->prepare("SELECT state,user_id,hash_id,type,price
            FROM pays WHERE id=? FOR UPDATE");
        $stmt->bind_param('i',$payId);
        $ok=$stmt->execute();
        $locked=$ok?$stmt->get_result()->fetch_assoc():null;
        $stmt->close();
        if (!$locked || $locked['state']!=='processing_wallet' ||
            (int)$locked['user_id']!==$uid ||
            (int)$locked['price']!==$price ||
            (string)$locked['hash_id']!==$hash_id ||
            (string)$locked['type']!=='RENEW_ACCOUNT')
            throw new RuntimeException('wallet_renew_claim_stale');
        achbotStoreVerifiedServiceExpiryLocked($connection,$order,$response);
        $timeNow=time();
        $stmt=$connection->prepare("INSERT INTO increase_order
            (userid,server_id,inbound_id,remark,amount,date)
            VALUES (?,?,?,?,?,?)");
        $stmt->bind_param('iiisii',$uid,$server_id,$inbound_id,
                          $remark,$price,$timeNow);
        $audited=$stmt->execute() && $stmt->affected_rows===1;
        $stmt->close();
        if (!$audited) throw new RuntimeException('wallet_renew_audit_unconfirmed');
        $stmt=$connection->prepare("UPDATE pays SET state='paid_with_wallet'
            WHERE id=? AND user_id=? AND hash_id=? AND state='processing_wallet'");
        $stmt->bind_param('iis',$payId,$uid,$hash_id);
        $finished=$stmt->execute() && $stmt->affected_rows===1;
        $stmt->close();
        if (!$finished || $connection->commit()!==true)
            throw new RuntimeException('wallet_renew_finish_unconfirmed');
        $localCommitted=true;
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

    } catch (Throwable $e) {
        if ($localCommitted) {
            error_log('achbot_wallet_renew_notice_unconfirmed pay_id='.(int)($payId??0));
            return;
        }
        try {$connection->rollback();} catch (Throwable $ignored) {}

        // پیام فنی به ادمین
        $adminErr = "⚠️ خطا در تمدید با کیف پول\n\n"
                  . "🆔 Hash ID: {$match[1]}\n"
                  . "👤 User: {$from_id}\n"
                  . "⚠️ External effect: unverified; verify before retry or compensation\n📄 Reason (sanitized): ".achbotFinanceDiagnosticReason($e);
        sendMessage($adminErr, null, null, $admin);

        sendMessage("⚠️ وضعیت درخواست مشخص نیست. لطفاً وضعیت اشتراک و پرداخت را بررسی کنید و پیش از ثبت دوباره با پشتیبانی هماهنگ شوید.", null, null, $uid);

        return;
    }
}