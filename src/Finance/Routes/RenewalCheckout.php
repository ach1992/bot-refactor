<?php
/** AChBot V8: source-pinned financial compatibility module. */
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

    sendMessage(\achbotMessage('renewal.renewal_checkout.message.7159ade06f'), $keyboard);
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
    if (!achbotSupportsOrder($connection, (int)$oid, (int)$fid, (int)$order['userid'])) {
        alert(\achbotMessage('renewal.renewal_checkout.alert.6aa152a253'), true);
        return;
    }
    // بررسی اگر fileid برابر 0 باشد
    if($fid == 0){
        sendMessage(\achbotMessage('renewal.renewal_checkout.message.4c2448e6fa'));
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
            alert(\achbotMessage('renewal.renewal_checkout.alert.201380d8bf'),true);
            return;
        }
    }else $price = $afterDiscount;

    if($price == 0) $price = \achbotMessage('renewal.extensions.price.dda7ff2974');
    else $price .= \achbotMessage('renewal.extensions.text.2cc98f7f7c');
	
	$keyboard = [];
	$actionType = "Renew";
	if (achbotManualEnabled('bank_transfer') && achbotPayUxAudienceAllowed($connection,'bank_transfer',(int)$from_id)) $keyboard[] = [['text' => \achbotMessage('renewal.renewal_checkout.button.5b3252c790', ['price' => $price]), 'callback_data' => "prePayCheck_{$actionType}_CartToCart_{$hash_id}"]];
	if (achbotManualEnabled('gift_card') && achbotPayUxAudienceAllowed($connection,'gift_card',(int)$from_id)) $keyboard[] = [['text' => \achbotMessage('renewal.renewal_checkout.button.cf580700a3', ['price' => $price]), 'callback_data' => "prePayCheck_{$actionType}_GiftCard_{$hash_id}"]];
	if (achbotManualCryptoEnabled((int)$from_id) && (achbotPayUxAudienceAllowed($connection,'manual_usdt',(int)$from_id) || achbotPayUxAudienceAllowed($connection,'manual_trx',(int)$from_id))) $keyboard[] = [['text' => \achbotMessage('renewal.renewal_checkout.button.73f7c8deeb', ['price' => $price]), 'callback_data' => "prePayCheck_{$actionType}_ManualUsdt_{$hash_id}"]];
	if (($botState['nowPaymentOther'] == "on" && achbotPayUxAudienceAllowed($connection,'nowpayments',(int)$from_id) && achbotUsdtRateForUser($connection,(int)$from_id)!==null)) $keyboard[] = [['text' => $buttonValues['now_payment_gateway'], 'callback_data' => "prePayCheck_{$actionType}_nowpayment_{$hash_id}"]];
	if (($botState['zarinpal'] == "on" && achbotPayUxAudienceAllowed($connection,'zarinpal',(int)$from_id))) $keyboard[] = [['text' => $buttonValues['zarinpal_gateway'], 'callback_data' => "prePayCheck_{$actionType}_zarinpal_{$hash_id}"]];
	if (($botState['nextpay'] == "on" && achbotPayUxAudienceAllowed($connection,'nextpay',(int)$from_id))) $keyboard[] = [['text' => $buttonValues['nextpay_gateway'], 'callback_data' => "prePayCheck_{$actionType}_nextpay_{$hash_id}"]];
	if (($botState['walletState'] == "on" && achbotPayUxAudienceAllowed($connection,'wallet',(int)$from_id))) $keyboard[] = [['text' => \achbotMessage('renewal.renewal_checkout.button.0eed1dd8e8', ['price' => $price]), 'callback_data' => "prePayCheck_{$actionType}_Wallet_{$hash_id}"]];
	if (!preg_match('/^discountRenew/', $userInfo['step'])) $keyboard[] = [['text' => \achbotMessage('renewal.renewal_checkout.button.05c3a1e3f4'), 'callback_data' => "haveDiscountRenew_{$match[1]}_{$rowId}"]];
	
	$keyboard[] = [['text' => $buttonValues['cancel'], 'callback_data' => "mainMenu"]];
	
    $sent=sendMessage(\achbotMessage('renewal.renewal_checkout.message.24ae8c4399'), json_encode(['inline_keyboard' => $keyboard]));
    require_once __DIR__ . '/../PaymentLifecycle.php';
    achbotPayUxTrackByHash($connection,(string)$hash_id,(int)$from_id,$sent);
}

if (is_string($data) && (strlen($data) <= 64 || ($GLOBALS['achbotManualCryptoVerifiedDispatch'] ?? null) === $data) && preg_match('/\ApayRenewWith(?:CartToCart|GiftCard|ManualUsdt)([A-Za-z0-9_-]{1,50})\z/D', $data, $match)) {

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

    $method = str_starts_with($data, 'payRenewWithManualUsdt') ? (($GLOBALS['achbotManualCryptoChoice'] ?? '') === 'TRON' ? 'manual_trx' : 'manual_usdt') :
        (str_starts_with($data, 'payRenewWithGiftCard') ? 'gift_card' : 'bank_transfer');
    if (!achbotManualClaim($connection, $payInfo, $method, (int)$from_id,
                           $GLOBALS['achbotManualCryptoChoice'] ?? null)) {
        alert(\achbotMessage('renewal.extensions.alert.1a885aaca4'), true);
        return;
    }

	$msg = buildCartToCartInfoMessage($paymentKeys, $mainValues, (int)$payInfo['price'], (int)$payInfo['user_id'], $method, (string)$payInfo['hash_id']);
    if ($msg === "") {
        alert(\achbotMessage('renewal.extensions.alert.07ee06b4bc'), true);
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
        alert(\achbotMessage('renewal.extensions.alert.7a9c30e938'), true);
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
        alert(\achbotMessage('renewal.extensions.alert.882596d0f1'), true);
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
        alert(\achbotMessage('renewal.extensions.alert.795c4bddb8'), true);
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
        sendMessage(\achbotMessage('renewal.renewal_checkout.message.2c9a5e948c'));

        sendMessage($mainValues['reached_main_menu'], getMainKeys(), 'HTML');
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
        sendMessage(\achbotMessage('renewal.renewal_checkout.message.aaa858e3e6'));

        sendMessage($mainValues['reached_main_menu'], getMainKeys(), 'HTML');
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
        achbotManualCaption($connection, $receiptPay, $mainValues['renew_account_request_message'])
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
        $msg, $adminKeyboard, $admin, $match[1],
        $deliveryToken)) {
        setUser();
        sendWithStartButton(achbotManualAcknowledgement($receiptPay, $method), $buttonValues['start_bot'], $botUsername, 'mainMenu', 'HTML', (string)$receiptPay['hash_id']);
    } else {
        sendMessage(\achbotMessage('renewal.extensions.message.c346a0d62e'));
    }

    exit;
}

if(preg_match('/^approveRenewAcc(.*)/',$data,$match) && achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'payments')){
    $connection->begin_transaction();
    $localCommitted=false;
    try {
        $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? FOR UPDATE"); 
        $stmt->bind_param("s", $match[1]); 
        $stmt->execute(); 
        $payInfo = $stmt->get_result()->fetch_assoc();
        if (!achbotSupportsPaymentSubject($connection, $payInfo, true)) {
            $connection->rollback();
            alert(\achbotMessage('renewal.renewal_checkout.alert.fbaa266905'), true);
            return;
        }
        $stmt->close(); 

        if (!achbotManualServiceApprovalAllowedLocked($connection, $payInfo, 'renewal')) {
            $connection->rollback();
            alert(\achbotMessage('renewal.renewal_checkout.alert.259244b81a'), true);
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
                    [['text' => \achbotMessage('renewal.extensions.button.139c829462', ['from_id' => $from_id]), 'callback_data' => "wizwizch"]],
                    [['text' => \achbotMessage('renewal.extensions.hash_id.2899228604', ['hash_id' => $hash_id]), 'callback_data' => "noop_$hash_id"]]
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
            $response = achbotApplyServiceEdit($connection,$server_id, ['pay_id'=>(int)$payId, 'remark'=>$remark, 'days'=>$days, 'volume' => $volume]);
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
                if (isset($msgInfo['chat_id'], $msgInfo['message_id']) && $msgInfo['chat_id'] != $deferredAdminKeys['chat_id'] &&
                    achbotAdminRecipientAllowed($connection,$msgInfo['chat_id'],(int)$admin,'payments')) {
                    editKeys($deferredAdminKeys['keys'], $msgInfo['message_id'], $msgInfo['chat_id']); 
                } 
            } 
        } 

        // پیام موفقیت به کاربر
        sendMessage(str_replace(["USERID", "REMARK", "VOLUME", "DAYS", "PRICE", "HASHID"],
                    [$uid, $remark, $volume, $days, $price, $hash_id], $mainValues['renewed_config_to_user']),
                    getMainKeys(), null, null); 
        sendMessage(\achbotMessage('renewal.renewal_checkout.message.47b1149fb0', ['remark' => $remark]), null, "HTML", $uid);
        return;

    } catch (Throwable $e) {
        if ($localCommitted) {
            error_log('achbot_manual_renew_notice_unconfirmed pay_id='.(int)($payId??0));
            return;
        }
        try {$connection->rollback();} catch (Throwable $ignored) {}

        $err = \achbotMessage('renewal.renewal_checkout.err.a6df2ab8a6')
             . \achbotMessage('renewal.extensions.hash__i_d_match_1.3abb834966', ['match_1' => $match[1]])
             . \achbotMessage('renewal.extensions.admin_from_id.748897de00', ['from_id' => $from_id])
             . \achbotMessage('renewal.extensions.external_effect_unverified_verify_before.10e2ccf8a3') . achbotFinanceDiagnosticReason($e);
        sendMessage($err, null, null, $admin);

        alert(\achbotMessage('renewal.renewal_checkout.alert.3955ffc5de'), true);
        return;
    }
}

if(is_string($data) && strlen($data) <= 64 && preg_match('/\ApayRenewWithWallet([A-Za-z0-9_-]{1,50})\z/D', $data, $match)){
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
            alert(\achbotMessage('renewal.extensions.alert.9b6f1fa91f'), true);
            return;
        }

        if (!achbotSupportsPaymentSubject($connection, $payInfo, true)) {
            $connection->rollback();
            alert(\achbotMessage('renewal.renewal_checkout.alert.0e312f38cb'), true);
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
            alert(\achbotMessage('renewal.extensions.alert.39c72d8044') . number_format($userwallet) . \achbotMessage('renewal.extensions.alert.71a2507661') . number_format($needamount) . \achbotMessage('renewal.extensions.text.2cc98f7f7c'), true);
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
            $response = achbotApplyServiceEdit($connection,$server_id, ['pay_id'=>(int)$payId, 'remark'=>$remark, 'days'=>$days, 'volume' => $volume]);
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
        editText($message_id, \achbotMessage('renewal.renewal_checkout.message.cca1075329', ['remark' => $remark]), getMainKeys());

        $keys = json_encode(['inline_keyboard'=>[
            [
                ['text'=>\achbotMessage('renewal.renewal_checkout.button.7deb81ef97'),'callback_data'=>"wizwizch"]
            ],
            [
                ['text'=>\achbotMessage('renewal.extensions.hash_id.2899228604', ['hash_id' => $hash_id]), 'callback_data'=>"noop_$hash_id"]
            ]
        ]]);

        $msg = str_replace(
            ['TYPE', "USER-ID", "USERNAME", "NAME", "PRICE", "REMARK", "VOLUME", "DAYS"],
            [\achbotMessage('renewal.renewal_checkout.text.6295c35212'), $from_id, $username, $first_name, $price, $remark, $volume, $days],
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
        $adminErr = \achbotMessage('renewal.renewal_checkout.admin_err.7f953ff1fb')
                  . \achbotMessage('renewal.extensions.hash__i_d_match_1.3abb834966', ['match_1' => $match[1]])
                  . \achbotMessage('renewal.extensions.user_from_id.45aa9ea684', ['from_id' => $from_id])
                  . \achbotMessage('renewal.extensions.external_effect_unverified_verify_before.10e2ccf8a3').achbotFinanceDiagnosticReason($e);
        sendMessage($adminErr, null, null, $admin);

        sendMessage(\achbotMessage('renewal.extensions.message.ba8adc7121'), null, null, $uid);

        return;
    }
}
