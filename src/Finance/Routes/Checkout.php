<?php
/** AChBot V8: source-pinned financial compatibility module. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../PaymentLifecycle.php';

if (is_string($data) && strlen($data) <= 64 && preg_match('/\ApayCustomWithWallet([A-Za-z0-9_-]{1,50})\z/D', $data, $match)) {
    setUser();

    $hashId = trim($match[1] ?? '');
    $pendingCustomDelivery=null;

    $result = processWalletPayment($connection, $hashId, function($payInfo, $userRow) use (
        $connection,
        $mainValues,
        $botState,
        $botUrl,
        $buttonValues,
        $linkIndices,
        $hideConfigIfMany,
        $admin,
        $userInfo,
        $username,
        $first_name,
        $from_id,
        &$pendingCustomDelivery
    ) {
        $uid    = (int)($payInfo['user_id'] ?: $from_id);
        $fid    = (int)$payInfo['plan_id'];
        $volume = (int)$payInfo['volume'];
        $days   = (int)$payInfo['day'];
        $remark = $payInfo['description'];
        $price  = (int)$payInfo['price'];
        $hashIdLocal = $payInfo['hash_id'];

        // The locked pre-debit plan is this wallet order's authorization.
        // Do not reread mutable plan settings after its held debit COMMIT.
        $plan=$payInfo['_achbot_wallet_locked_plan']??null;
        if (!is_array($plan) || (int)($plan['id']??0)!==$fid ||
            (int)($plan['server_id']??0)!==(int)$payInfo['server_id'])
            throw new RuntimeException('wallet_custom_locked_plan_missing');

        $date             = time();
        $expire_microdate = floor(microtime(true) * 1000) + (864000 * $days * 100);
        $expire_date      = $date + (86400 * $days);

        $type       = $plan['type'];
        $protocol   = $plan['protocol'];
        $server_id  = (int)$plan['server_id'];
        $netType    = $plan['type'];
        $acount     = (int)$plan['acount'];
        $inbound_id = (int)$plan['inbound_id'];
        $limitip    = $plan['limitip'];
        $rahgozar   = $plan['rahgozar'];
        $customPath = $plan['custom_path'];
        $customPort = $plan['custom_port'];
        $customSni  = $plan['custom_sni'];

        // Full-count capacity was already committed in this payment's
        // durable 007 wallet_hold, before the first external CREATE.
        $uniqid = generateRandomString(42, $protocol);

        // اطلاعات سرور + تنظیمات پنل
        [$serverInfo, $serverConfig] = getServerInfoAndConfig($connection, $server_id);
        $srv_remark  = $serverInfo['remark'];
        $serverType  = $serverConfig['type'];
        $portType    = $serverConfig['port_type'];

        [$port, $last_num] = getNextPortAndIncrease('settings/temp.txt', $portType);

        [$response, $remark, $port, $effectId] = achbotCreatePaidAccount(
            $connection,(int)$payInfo['id'],1,[
                'user_id'=>$uid,'server_type'=>$serverType,
                'server_id'=>$server_id,'inbound_id'=>$inbound_id,
                'plan_id'=>$fid,'remark'=>$remark,'volume'=>$volume,
                'days'=>$days,'uniqid'=>$uniqid,'protocol'=>$protocol,
                'port'=>$port,'expire_microdate'=>$expire_microdate,
                'net_type'=>$netType,'rahgozar'=>$rahgozar,'limitip'=>$limitip
            ]
        );

        ensureAccountCreationSucceeded($response, $serverInfo, $inbound_id, $admin);

        alert($mainValues['sending_config_to_user']);

        [$token, $vray_link, $uniqid, $accText, $userKeys, $qrLink] = buildLinksAndSendQr(
            $serverType,
            $serverConfig,
            $botState,
            $botUrl,
            $server_id,
            $uniqid,
            $protocol,
            $remark,
            $port,
            $netType,
            $inbound_id,
            $rahgozar,
            $customPath,
            $customPort,
            $customSni,
            $response,
            $uid,
            $volume,
            $days,
            $buttonValues,
            $linkIndices,
            $hideConfigIfMany,
            '',
            null,
            false
        );

        $inviterId = $userRow['refered_by'] ?? null;
        if (!empty($inviterId)) {
			giveReferralReward($connection, $uid, $inviterId, $admin, $botState, 'wallet');
        }

        $agentBought = (int)($payInfo['agent_bought'] ?? 0);

        if ($connection->begin_transaction()!==true)
            throw new RuntimeException('custom_wallet_order_begin');
        try {
            $orderId=insertOrder(
                $connection,$uid,$token,$fid,$server_id,$inbound_id,
                $remark,$uniqid,$protocol,$expire_date,$vray_link,
                $price,$date,$rahgozar,$agentBought,true);
            achbotLinkPaidAccountEffect($connection,$effectId,$orderId);
            if ($connection->commit()!==true)
                throw new RuntimeException('custom_wallet_order_commit_unknown');
        } catch (Throwable $error) {
            try {$connection->rollback();} catch (Throwable $ignored) {}
            throw $error;
        }
        $pendingCustomDelivery=[
            'uid'=>$uid,'qr'=>$qrLink,'caption'=>$accText,'keys'=>$userKeys
        ];

        $keys = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => "🛍 خرید اشتراک جدید", 'callback_data' => "wizwizch"]
                ],
                [
                    ['text' => "🆔 $hashIdLocal", 'callback_data' => "noop_$hashIdLocal"]
                ]
            ]
        ]);

        $msg = str_replace(
            ['TYPE', 'USER-ID', 'USERNAME', 'NAME', 'PRICE', 'REMARK', 'VOLUME', 'DAYS'],
            ['کیف پول', $uid, $username, $first_name, $price, $remark, $volume, $days],
            $mainValues['buy_custom_account_request']
        );

        $pendingCustomDelivery['admin_msg']=$msg;
        $pendingCustomDelivery['admin_keys']=$keys;
        return true;
    }, 'custom');

    if ($result['status'] === 'not_found') {
        $text = "/start";
        $data = "";
        delMessage();
        return '__ACHBOT_V8_GOTO_START__';
    }

    if ($result['status'] === 'already_paid') {
        return;
    }
    if ($result['status']==='ok' && is_array($pendingCustomDelivery)) {
        try {
            $sent=sendQrFromLink(
                $pendingCustomDelivery['qr'],$pendingCustomDelivery['caption'],
                $pendingCustomDelivery['keys'],$botUrl,
                (int)$pendingCustomDelivery['uid'],'',null);
            if (!$sent) throw new RuntimeException('custom_wallet_qr_unconfirmed');
        } catch (Throwable $error) {
            $reviewPay=(int)($result['payInfo']['id']??0);
            error_log('achbot_custom_wallet_delivery_review pay_id='.
                $reviewPay.' class='.get_class($error));
            try {
                sendMessage('⚠️ تحویل خرید سفارشی کیف پول نیازمند بررسی است. '.
                    'پرداخت و سرویس ثبت شده‌اند؛ ساخت مجدد خودکار ممنوع است.'.
                    "\n🆔 Pay ID: ".$reviewPay,null,null,$admin);
            } catch (Throwable $ignored) {
                error_log('achbot_custom_wallet_owner_notice_failed pay_id='.$reviewPay);
            }
        }
        try {
            sendMessage($pendingCustomDelivery['admin_msg'],
                $pendingCustomDelivery['admin_keys'],"html",$admin);
        } catch (Throwable $error) {
            error_log('achbot_custom_wallet_admin_notice_failed pay_id='.
                (int)($result['payInfo']['id']??0));
        }
    }
    return;
}

if (preg_match('/^showQr(Sub|Config)(\d+)/', $data, $match)) {
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `userid`=? AND `id`=?");
    $stmt->bind_param("ii", $from_id, $match[2]);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$order || !achbotSupportsMarzbanOrder($connection, (int)$order['id'])) {
        alert("⛔️ اطلاعات این اشتراک در حال حاضر قابل نمایش نیست.", true);
        return;
    }

    include_once (stream_resolve_include_path('phpqrcode/qrlib.php') ?: dirname(__DIR__, 3) . '/phpqrcode/qrlib.php');

    if (!defined('IMAGE_WIDTH'))  define('IMAGE_WIDTH', 540);
    if (!defined('IMAGE_HEIGHT')) define('IMAGE_HEIGHT', 540);

    if ($match[1] == "Sub") {
        $stmt = $connection->prepare("SELECT `panel_url` FROM `server_config` WHERE `id` = ? AND `type` = 'marzban' LIMIT 1");
        $stmt->bind_param("i", $order['server_id']);
        $stmt->execute();
        $panel = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$panel || empty($panel['panel_url']) || empty($order['token'])) {
            alert("⛔️ لینک این اشتراک در حال حاضر در دسترس نیست.", true);
            return;
        }
        $subLink = rtrim($panel['panel_url'], '/') . "/sub/" . $order['token'];

        $file = RandomString() . ".png";
        $ecc = 'L';
        $pixel_Size = 11;
        $frame_Size = 4;

        QRcode::png($subLink, $file, $ecc, $pixel_Size, $frame_Size);

        sendPhoto(
            $botUrl . $file,
            ($acc_text ?? ''),
            json_encode(['inline_keyboard' => [[['text' => $buttonValues['back_to_main'], 'callback_data' => "mainMenu"]]]]),
            "HTML",
            ($uid ?? $from_id)
        );
        unlink($file);
    }
    elseif ($match[1] == "Config") {

        $vraylink = json_decode($order['link'], true);

        foreach ($vraylink as $vray_link) {
            $file = RandomString() . ".png";
            $ecc = 'L';
            $pixel_Size = 11;
            $frame_Size = 4;

            QRcode::png($vray_link, $file, $ecc, $pixel_Size, $frame_Size);

            sendPhoto(
                $botUrl . $file,
                $acc_text,
                json_encode(['inline_keyboard' => [[['text' => $buttonValues['back_to_main'], 'callback_data' => "mainMenu"]]]]),
                "HTML",
                $uid
            );
            unlink($file);
        }
    }
}

if (preg_match('/payCustomWith(?:CartToCart|GiftCard|ManualUsdt)(.*)/', $data, $match)) {

    $payInfo = dbFetchPayByHash($connection, $match[1]);
    if (!$payInfo) {
        prepareStartFallback();
        delMessage();
        return '__ACHBOT_V8_GOTO_START__';
    }

    $fid = (int)$payInfo['plan_id'];

    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $file_detail = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $server_id  = (int)$file_detail['server_id'];
    $acount     = (int)$file_detail['acount'];
    $inbound_id = (int)$file_detail['inbound_id'];

    if ($acount == 0 && $inbound_id != 0) {
        alert($mainValues['out_of_connection_capacity'], true);
        exit;
    }

    if ($inbound_id == 0) {
        $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $server_info = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ((int)$server_info['ucount'] <= 0) {
            alert($mainValues['out_of_server_capacity'], true);
            exit;
        }
    } else {
        if ($acount != 0 && $acount <= 0) {
            sendMessage(str_replace("AMOUNT", $acount, $mainValues['can_create_specific_account']));
            exit;
        }
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
    $keyboard = kbSendReceiptAndCancel(in_array($method, ['manual_usdt','manual_trx'], true) ? "sendCustomUsdtReceipt" : ($method === 'gift_card' ? "sendCustomGiftReceipt" : "sendCustomCartReceipt"), "cancelCustomCartReceipt", $match[1], $method);

    $delivered = sendMessage($msg, $keyboard, "HTML");
    require_once __DIR__ . '/../PaymentLifecycle.php';
    achbotPayUxTrackByHash($connection,(string)$payInfo['hash_id'],(int)$from_id,$delivered);
    if (is_object($delivered) && !empty($delivered->ok)) {
    setUser("payCustomWith" . (in_array($method, ['manual_usdt','manual_trx'], true) ? 'ManualUsdt' : ($method === 'gift_card' ? 'GiftCard' : 'CartToCart')) . $match[1]);
        delMessage();
    } else {
        // The pinned attempt may be reopened. Never erase the selection menu
        // if Telegram failed to deliver the replacement instructions.
        alert("⚠️ نمایش اطلاعات پرداخت تأیید نشد. از همان دکمه دوباره تلاش کنید و وجهی ارسال نکنید.", true);
    }
    exit;
}

if (preg_match('/send(?:CustomCart|CustomGift|CustomUsdt)Receipt(?<hashId>.*)/', $data, $match)) {

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
    setUser((in_array($method, ['manual_usdt','manual_trx'], true) ? "waitCustomUsdtReceipt" : ($method === 'gift_card' ? "waitCustomGiftReceipt" : "waitCustomCartReceipt")) . $match['hashId']);

    $keyboard = kbOnlyCancel("cancelCustomCartReceipt", $match['hashId']);
    $sent=sendMessage(achbotManualReceiptPrompt($mainValues, $method), $keyboard, "HTML");
    require_once __DIR__ . '/../PaymentLifecycle.php';
    achbotPayUxTrackByHash($connection,(string)$match['hashId'],(int)$from_id,$sent);
    exit;
}

if (preg_match('/cancelCustomCartReceipt(?<hashId>.*)/', $data, $match)) {
    handleCancelFlow($mainValues, $removeKeyboard);
}

if (preg_match('/wait(?:CustomCart|CustomGift|CustomUsdt)Receipt(.*)/', $userInfo['step'], $match)) {

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
        $keyboard = kbOnlyCancel("cancelCustomCartReceipt", $match[1]);
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

    $fid    = (int)$payInfo['plan_id'];
    $volume = $payInfo['volume'];
    $days   = $payInfo['day'];


    $uid = $userInfo['userid'];
    $name = $userInfo['name'];
    $username = $userInfo['username'];

    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `id`=?");
    $stmt->bind_param("i", $res['catid']);
    $stmt->execute();
    $catname = $stmt->get_result()->fetch_assoc()['title'];
    $stmt->close();

    $filename = $catname . " " . $res['title'];
    $fileprice = $payInfo['price'];
    $remark = $payInfo['description'];



    $msg = str_replace(
        ['TYPE', 'USER-ID', 'USERNAME', 'NAME', 'PRICE', 'REMARK', 'VOLUME', 'DAYS'],
        ["کارت به کارت", $from_id, $username, $first_name, $fileprice, $remark, $volume, $days],
        $mainValues['buy_custom_account_request']
    );

    $adminKeyboard = buildAdminKeyboardWithStats(
        $connection,
        $buttonValues,
        "accCustom{$match[1]}",
        "declineAction_{$match[1]}",
        $match[1],
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

if (preg_match('/^accCustom(.+)$/D', $data, $match) && $text != $buttonValues['cancel']) {
    if ((int)$from_id <= 0 || ((int)$from_id !== (int)$admin && empty($userInfo['isAdmin']))) {
        alert('⛔️ تأیید پرداخت فقط توسط مدیر مجاز است.', true);
        return;
    }

    $hash_id = trim($match[1]);

    require_once __DIR__.'/../ServiceEffects.php';
    $claimed=false;
    $claimOpen=false;
    try {
        if ($connection->begin_transaction()!==true)
            throw new RuntimeException('custom_receipt_claim_begin');
        $claimOpen=true;
        $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? FOR UPDATE");
        $stmt->bind_param("s", $hash_id);
        $stmt->execute();
        $payInfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!achbotManualServiceApprovalAllowedLocked($connection, $payInfo, 'custom') ||
            !achbotSupportsPaymentSubject($connection, $payInfo)) {
            $connection->rollback();
            $claimOpen=false;
            alert('⛔️ این خرید رسید دستی معتبر و در انتظار تأیید ندارد.', true);
            return;
        }

        $payRowId = (int)$payInfo['id'];
        // A custom manual BUY_SUB is an operator-approved CREATE as well.
        // Reserve its complete quantity and own it in 007 in this SAME
        // original receipt transaction, before any external operation.
        achbotEffectRequireUniqueLine($connection);
        $reservedCount=achbotManualCustomReserveCapacityLocked(
            $connection,$payInfo);
        achbotEffectRecordManualCapacityLocked(
            $connection,$payInfo,$reservedCount);
        $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'processing', `approved_by` = ? WHERE `id` = ? AND `state` = 'have_sent'");
        $stmt->bind_param('ii', $from_id, $payRowId);
        $stmt->execute();
        $changed = $stmt->affected_rows;
        $stmt->close();
        if ($changed !== 1) throw new Exception('custom_receipt_claim_failed');
        // Preserve the original reviewed receipt before any remote action.
        if ($connection->commit()!==true)
            throw new RuntimeException('custom_receipt_claim_commit_unknown');
        $claimOpen=false;
        $claimed=true;

        $fid    = (int)$payInfo['plan_id'];
        $uid    = (int)$payInfo['user_id'];
        $days   = (int)$payInfo['day'];
        $volume = (int)$payInfo['volume'];
        $price  = (int)$payInfo['price'];
        $remark = $payInfo['description'];

        $planDetails = getPlanDetails($connection, $fid);
        $plan        = $planDetails['plan'];

        $date = time();
        $expire_microdate = floor(microtime(true) * 1000) + (864000 * $days * 100);
        $expire_date      = $date + (86400 * $days);

        $type       = $plan['type'];
        $protocol   = $plan['protocol'];
        $server_id  = (int)$plan['server_id'];
        $netType    = $plan['type'];
        $acount     = (int)$plan['acount'];
        $inbound_id = (int)$plan['inbound_id'];
        $limitip    = $plan['limitip'];
        $rahgozar   = $plan['rahgozar'];
        $customPath = $plan['custom_path'];
        $customPort = $plan['custom_port'];
        $customSni  = $plan['custom_sni'];

        ensureServerCapacity($connection, $server_id, $inbound_id, $acount, $mainValues);

        [$serverInfo, $serverConfig] = getServerInfoAndConfig($connection, $server_id);
        $srv_remark  = $serverInfo['remark'];
        $serverType  = $serverConfig['type'];
        $portType    = $serverConfig['port_type'];

        $uniqid = generateRandomString(42, $protocol);
        [$port] = getNextPortAndIncrease('settings/temp.txt', $portType);

        // اگر خواستی fallback برای remark داشته باشی:
        // $remark = buildRemark($botState, $srv_remark, $payInfo['description'], $uid, $payInfo['description']);

        [$response, $remark, $port, $effectId] = achbotCreatePaidAccount(
            $connection,$payRowId,1,[
                'user_id'=>$uid,'server_type'=>$serverType,
                'server_id'=>$server_id,'inbound_id'=>$inbound_id,
                'plan_id'=>$fid,'remark'=>$remark,'volume'=>$volume,'days'=>$days,
                'uniqid'=>$uniqid,'protocol'=>$protocol,'port'=>$port,
                'expire_microdate'=>$expire_microdate,'net_type'=>$netType,
                'rahgozar'=>$rahgozar,'limitip'=>$limitip
            ]
        );

        ensureAccountCreationSucceeded($response, $serverInfo, $inbound_id, $admin, true);

        alert($mainValues['sending_config_to_user']);

        [$token, $vray_link, $uniqid, $caption, $keys, $qrLink] = buildLinksAndSendQr(
            $serverType,
            $serverConfig,
            $botState,
            $botUrl,
            $server_id,
            $uniqid,
            $protocol,
            $remark,
            $port,
            $netType,
            $inbound_id,
            $rahgozar,
            $customPath,
            $customPort,
            $customSni,
            $response,
            $uid,
            $volume,
            $days,
            $buttonValues,
            $linkIndices,
            $hideConfigIfMany,
            '',
            null,
            false
        );

        $agentBought = (int)($payInfo['agent_bought'] ?? 0);
        if ($connection->begin_transaction()!==true)
            throw new RuntimeException('custom_receipt_finish_begin');
        try {
            $orderId=insertOrder(
                $connection,$uid,$token,$fid,$server_id,$inbound_id,
                $remark,$uniqid,$protocol,$expire_date,$vray_link,
                $price,$date,$rahgozar,$agentBought,true);
            achbotLinkPaidAccountEffect($connection,$effectId,$orderId);
            $fin=$connection->prepare("UPDATE pays SET state='approved'
                WHERE id=? AND type='BUY_SUB' AND state='processing' AND approved_by=?");
            if (!$fin) throw new RuntimeException('custom_receipt_finish_prepare');
            $fin->bind_param('ii',$payRowId,$from_id);
            if (!$fin->execute() || $fin->affected_rows!==1)
                throw new RuntimeException('custom_receipt_finish_unconfirmed');
            $fin->close();
            if ($connection->commit()!==true)
                throw new RuntimeException('custom_receipt_finish_commit_unknown');
        } catch (Throwable $error) {
            try {$connection->rollback();} catch (Throwable $ignored) {}
            throw $error;
        }

    } catch (Throwable $e) {
        if ($claimOpen) {
            try { $connection->rollback(); } catch (Throwable $ignored) {}
        }
        error_log('achbot_custom_receipt_review pay_id='.(int)($payRowId??0).
            ' class='.get_class($e));
        // A remote operation could have succeeded before the local rollback.
        // Never retry it blindly; an operator must reconcile the real service.
        sendMessage('⚠️ تأیید خرید دستی نیازمند بررسی سرویس و پرداخت است. از اجرای دوباره خودکار خودداری کنید.' . "\n🆔 " . $hash_id,
            null, null, $admin);
        alert('⚠️ نتیجه تأیید نیاز به بررسی دستی دارد؛ وضعیت سرویس را پیش از اقدام مجدد کنترل کنید.', true);
        return;
    }

    // Register the payment-keyed referral award before any possible
    // Telegram/UI throw. Never make inviter credit contingent on QR or
    // administrator message delivery succeeding.
    $user_detail=achbotReferralAfterCompletedPurchase(
        $connection,(int)$uid,(int)$payRowId,(int)$admin,$botState);

    setUser();
    // Both payment and local order now have verified COMMITs.
    // Delivery failure does not undo a paid service or rearm CREATE.
    try {
        $sent=sendQrFromLink($qrLink,$caption,$keys,$botUrl,$uid,'',null);
        if (!$sent) throw new RuntimeException('custom_receipt_qr_unconfirmed');
    } catch (Throwable $error) {
        error_log('achbot_custom_receipt_delivery_review pay_id='.$payRowId.
            ' class='.get_class($error));
        try {
            sendMessage('⚠️ تحویل خرید دستی بعد از ثبت سفارش نیاز به بررسی دارد.'.
                "\n🆔 Pay ID: ".$payRowId.
                "\nسرویس را بدون تطبیق مجدد ایجاد نکنید.",null,null,$admin);
        } catch (Throwable $ignored) {
            error_log('achbot_custom_receipt_owner_notice_failed pay_id='.$payRowId);
        }
    }
    // Presentation failures after committed financial/referral outcomes must
    // remain separate owner-visible delivery problems, never reopen CREATE.
    try {
    sendMessage('✅ اشتراک ثبت شده است.', getMainKeys());

    $keys = json_encode([
        'inline_keyboard' => [
            [['text' => "✅ تایید شد توسط $from_id", 'callback_data' => "wizwizch"]],
            [['text' => "🔄 $remark",                'callback_data' => "noop"]],
            [['text' => "🆔 $hash_id",              'callback_data' => "noop_$hash_id"]],
        ]
    ], 488);

    editKeys($keys, $payInfo['message_id'], $payInfo['chat_id']);

    $adminMessages = json_decode($payInfo['admins_messages'], true);
    if (is_array($adminMessages)) {
        foreach ($adminMessages as $msgInfo) {
            if (
                isset($msgInfo['chat_id'], $msgInfo['message_id']) &&
                $msgInfo['chat_id'] != $payInfo['chat_id']
            ) {
                editKeys($keys, $msgInfo['message_id'], $msgInfo['chat_id']);
            }
        }
    }

    if (!empty($user_detail['refered_by'])) {
        // Invitee fanout is presentation-only; 008 award is already decided.
        if ($admin != $from_id) {
            $uname     = $user_detail['name'];
            $user_name = $user_detail['username'];

            $keys2 = json_encode([
                'inline_keyboard' => [
                    [
                        ['text' => "🛍 خرید اشتراک جدید", 'callback_data' => "wizwizch"]
                    ],
                ]
            ]);

            $msg = str_replace(
                ['USER-ID', 'USERNAME', 'NAME', 'PRICE', 'REMARK', 'HASHID'],
                [$uid, $user_name, $uname, $price, $remark, $hash_id],
                $mainValues['invite_buy_new_account']
            );

            sendMessage($msg, $keys2, "html", $admin);

            $rewardChannel = $botState['rewardChannel'] ?? null;
            if (!empty($rewardChannel)) {
                sendMessage($msg, $keys2, "html", $rewardChannel);
            }
        }
    }
    } catch (Throwable $error) {
        error_log('achbot_custom_receipt_postcommit_notice_review pay_id='.$payRowId.
            ' class='.get_class($error));
        try {
            sendMessage('⚠️ پرداخت و سفارش ثبت شده‌اند، اما اطلاع‌رسانی نیازمند بررسی است.'.
                "\n🆔 Pay ID: ".$payRowId.
                "\nسرویس یا پاداش را بدون تطبیق دوباره صادر نکنید.",
                null,null,$admin);
        } catch (Throwable $ignored) {
            error_log('achbot_custom_receipt_postcommit_owner_notice_failed pay_id='.$payRowId);
        }
    }
}

if (is_string($data) && strlen($data) <= 64 && preg_match('/\ApayBuyWithWallet([A-Za-z0-9_-]{1,50})\z/D', $data, $match)) {

    setUser();

    $hashId = trim($match[1] ?? '');
    $deferredRenewSuccess = null;
    $pendingUserQrs = [];
    $msgToDeleteAfterOk = null;
    $deferredReferral = null;

    $lastRemark = '';
    $serverTitle = '-';

    $result = processWalletPayment(
        $connection,
        $hashId,
        function($payInfo, $userRow) use (
            $connection,
            $mainValues,
            $botState,
            $botUrl,
            $buttonValues,
            $linkIndices,
            $hideConfigIfMany,
            $admin,
            $from_id,
            $message_id,
            $time,
            &$deferredRenewSuccess,
            &$pendingUserQrs,
            &$msgToDeleteAfterOk,
            &$deferredReferral,
            &$lastRemark,
            &$serverTitle
        ) {

            $uid     = (int)($payInfo['user_id'] ?: $from_id);
            $fid     = (int)$payInfo['plan_id'];
            $price   = (int)$payInfo['price'];
            $payType = $payInfo['type'];
            $userReferrer = $userRow['refered_by'] ?? null;
            $hashIdLocal  = $payInfo['hash_id'];

            if ($payType === "RENEW_SCONFIG") {

                $planDetails = getPlanDetails($connection, $fid);
                $plan        = $planDetails['plan'];

                $days   = $planDetails['days'];
                $volume = $planDetails['volume'];

                $server_id  = (int)$plan['server_id'];
                $inbound_id = (int)$payInfo['volume'];

                $configInfo = json_decode($payInfo['description'], true) ?: [];
                $uuid       = $configInfo['uuid'] ?? '';
                $remark     = $configInfo['remark'] ?? '';
                $isMarzban  = !empty($configInfo['marzban']);

                [$serverInfo] = getServerInfoAndConfig($connection, $server_id);

                $response = renewConfigOnServer(
                    $connection,
                    $server_id,
                    $inbound_id,
                    $uuid,
                    $remark,
                    $days,
                    $volume,
                    $isMarzban
                );

                ensureAccountCreationSucceeded($response, $serverInfo, $inbound_id, $admin, true);

                createIncreaseOrder(
                    $connection,
                    $uid,
                    $server_id,
                    $inbound_id,
                    $remark,
                    $price,
                    $time,
                    $volume,
                    $days
                );

                $keys = json_encode([
                    'inline_keyboard' => [
                        [ ['text' => $buttonValues['back_to_main'], 'callback_data' => "mainMenu"] ]
                    ]
                ], JSON_UNESCAPED_UNICODE);

                $deferredRenewSuccess = [
                    $message_id,
                    "✅ اشتراک $remark با موفقیت تمدید شد.",
                    $keys
                ];

                $lastRemark = $remark;

                if (!empty($userReferrer)) {
                    $deferredReferral = [$uid, (int)$userReferrer];
                }

                return true;
            }

            $accountCount = (int)($payInfo['agent_count'] ?? 1);
            $accountCount = max(1, $accountCount);

            // The locked plan snapshot pins exactly the dimensions that
            // authorized wallet debit. A post-COMMIT plan edit cannot
            // silently reduce (or change) the customer's paid entitlement.
            $plan=$payInfo['_achbot_wallet_locked_plan']??null;
            if (!is_array($plan) || (int)($plan['id']??0)!==$fid ||
                (int)($plan['server_id']??0)!==(int)$payInfo['server_id'])
                throw new RuntimeException('wallet_standard_locked_plan_missing');
            $days=(int)$plan['days'];
            $volume=(int)$plan['volume'];
            $date=time();
            $expire_microdate=floor(microtime(true)*1000)+(864000*$days*100);
            $expire_date=$date+(86400*$days);

            $server_id  = (int)$plan['server_id'];
            $acount     = (int)$plan['acount'];
            $inbound_id = (int)$plan['inbound_id'];
            $protocol   = $plan['protocol'];
            $limitip    = $plan['limitip'];
            $netType    = $plan['type'];
            $rahgozar   = $plan['rahgozar'];
            $customPath = $plan['custom_path'];
            $customPort = $plan['custom_port'];
            $customSni  = $plan['custom_sni'];

            // This order owns the durable reservation already. The server's
            // currently UNreserved capacity must not be tested again.
            // The effect dispatcher verifies the exact owned 007 hold.
            [$serverInfo, $serverConfig] = getServerInfoAndConfig($connection, $server_id);
            $serverTitle = $serverInfo['title'] ?? '-';
            $srv_remark  = $serverInfo['remark'];
            $serverType  = $serverConfig['type'];
            $portType    = $serverConfig['port_type'];

            $agent_bought = (int)($payInfo['agent_bought'] ?? 0);

            alert($mainValues['sending_config_to_user']);

            $msgToDeleteAfterOk = $message_id;

            for ($i = 1; $i <= $accountCount; $i++) {

                $uniqid = generateRandomString(42, $protocol);
                [$port] = getNextPortAndIncrease('settings/temp.txt', $portType);

                $remark = buildRemark(
                    $botState,
                    $srv_remark,
                    $payInfo['description'],
                    $uid
                );

                // Each line owns a durable 007 operation identity.
                // The first line atomically reserves the FULL quantity.
                [$response, $remark, $port, $effectId] = achbotCreatePaidAccount(
                    $connection,(int)$payInfo['id'],$i,[
                        'user_id'=>$uid,'server_type'=>$serverType,
                        'server_id'=>$server_id,'inbound_id'=>$inbound_id,
                        'plan_id'=>$fid,'remark'=>$remark,
                        'volume'=>$volume,'days'=>$days,'uniqid'=>$uniqid,
                        'protocol'=>$protocol,'port'=>$port,
                        'expire_microdate'=>$expire_microdate,
                        'net_type'=>$netType,'rahgozar'=>$rahgozar,
                        'limitip'=>$limitip
                    ]
                );

                ensureAccountCreationSucceeded($response, $serverInfo, $inbound_id, $admin, true);

                $lastRemark = $remark;

                [$token, $vray_link, $uniqid2, $acc_text, $userKeys, $qrLink] = buildLinksAndSendQr(
                    $serverType,
                    $serverConfig,
                    $botState,
                    $botUrl,
                    $server_id,
                    $uniqid,
                    $protocol,
                    $remark,
                    $port,
                    $netType,
                    $inbound_id,
                    $rahgozar,
                    $customPath,
                    $customPort,
                    $customSni,
                    $response,
                    $uid,
                    $volume,
                    $days,
                    $buttonValues,
                    $linkIndices,
                    $hideConfigIfMany,
                    '',
                    null,
                    false
                );

                // Never keep a local transaction open during HTTP. A
                // partial multi-account order remains linked for recovery.
                if ($connection->begin_transaction()!==true)
                    throw new RuntimeException('wallet_buy_order_begin');
                try {
                    $orderId=insertOrder(
                        $connection,$uid,$token,$fid,$server_id,$inbound_id,
                        $remark,$uniqid2?:$uniqid,$protocol,$expire_date,
                        $vray_link,
                        achbotAllocateOrderAmount((int)$price,$accountCount,$i),
                        $date,$rahgozar,$agent_bought,true);
                    achbotLinkPaidAccountEffect($connection,$effectId,$orderId);
                    if ($connection->commit()!==true)
                        throw new RuntimeException('wallet_buy_order_commit_unknown');
                } catch (Throwable $error) {
                    try {$connection->rollback();} catch (Throwable $ignored) {}
                    throw $error;
                }

                $pendingUserQrs[] = [
                    'uid'      => $uid,
                    'qrLink'   => $qrLink,
                    'acc_text' => $acc_text,
                    'keys'     => $userKeys,
                ];
            }

            // No second capacity deduction: the initial 007 dispatch
            // reservation committed all slots before its remote call.
            if (!empty($userReferrer)) {
                $deferredReferral = [$uid, (int)$userReferrer];
            }

            return true;
        },
        'standard'
    );

    if ($result['status'] === 'not_found') {
        $text = "/start";
        $data = "";
        delMessage();
        return '__ACHBOT_V8_GOTO_START__';
    }

    if ($result['status'] === 'already_paid') {
        return;
    }

    if ($result['status'] === 'in_progress') {
        return;
    }

    if ($result['status'] !== 'ok') {
        return;
    }

    $payInfo = $result['payInfo'];
    $uid     = $result['uid'];
    $price   = $result['price'];
    $payType = $payInfo['type'];
    $fid     = (int)$payInfo['plan_id'];
    $hashId  = $payInfo['hash_id'];

    if (($payInfo['state'] ?? '') !== 'paid_with_wallet') {
		$uidForLog = (int)($payInfo['user_id'] ?? $from_id);

		sendMessage(
			"⚠️ پرداخت هنوز نهایی نشده؛ ارسال انجام نشد.\n" .
			"👤 UserID: {$uidForLog}\n" .
			"🆔 $hashId",
			null,
			null,
			$admin
		);
		return;
	}

    if (!empty($deferredRenewSuccess)) {
        editText(
            $deferredRenewSuccess[0],
            $deferredRenewSuccess[1],
            $deferredRenewSuccess[2]
        );
    }

    if (!empty($pendingUserQrs)) {
        $deliveryReview=[];
        foreach ($pendingUserQrs as $index=>$pq) {
            try {
                $sent=sendQrFromLink(
                    $pq['qrLink'],$pq['acc_text'],$pq['keys'],
                    $botUrl,(int)$pq['uid'],'',null);
                if ($sent!==true)
                    throw new RuntimeException('wallet_checkout_qr_unconfirmed');
            } catch (Throwable $error) {
                $deliveryReview[]=$index+1;
                error_log('achbot_wallet_checkout_delivery_review pay_id='.
                    (int)$payInfo['id'].' ordinal='.($index+1).
                    ' class='.get_class($error));
            }
        }
        if ($deliveryReview) {
            try {
                sendMessage('⚠️ تحویل اشتراک کیف پول نیاز به بررسی دارد.'.
                    "\n🆔 Pay ID: ".(int)$payInfo['id'].
                    "\n🔢 موارد: ".implode(',',$deliveryReview).
                    "\nپرداخت و سرویس ثبت شده‌اند؛ ساخت مجدد انجام نشود.",
                    null,null,$admin);
            } catch (Throwable $error) {
                error_log('achbot_wallet_checkout_owner_notice_failed pay_id='.
                    (int)$payInfo['id'].' class='.get_class($error));
            }
        }
    }

    if (!empty($msgToDeleteAfterOk)) {
        delMessage($msgToDeleteAfterOk);
    }

    if (!empty($deferredReferral) && is_array($deferredReferral) && count($deferredReferral) === 2) {
        // giveReferralReward($connection, (int)$deferredReferral[0], (int)$deferredReferral[1], $admin, $botState);
		giveReferralReward($connection, (int)$deferredReferral[0], (int)$deferredReferral[1], $admin, $botState, 'wallet');
    }

    if ($payType === 'BUY_SUB') {
        // Report the SAME paid entitlement authorized under the wallet/plan
        // lock and used for CREATE/readback, never a mutable plan template
        // reread after settlement. This snapshot is mandatory for paid BUY_SUB
        // and intentionally not required by the legacy renewal flow.
        $paidPlan=$payInfo['_achbot_wallet_locked_plan']??null;
        if (!is_array($paidPlan) ||
            (int)($paidPlan['id']??0)!==$fid ||
            (int)($paidPlan['server_id']??0)!==(int)$payInfo['server_id']) {
            error_log('achbot_wallet_paid_report_quote_unavailable pay_id='.
                (int)$payInfo['id']);
            try {
                sendMessage('⚠️ پرداخت و سرویس ثبت شده‌اند، اما گزارش مشخصات خرید نیازمند بررسی است.'.
                    "\n🆔 Pay ID: ".(int)$payInfo['id'].
                    "\nسفارش را با سرویس صادرشده تطبیق دهید؛ آن را دوباره ایجاد نکنید.",
                    null,null,$admin);
            } catch (Throwable $ignored) {
                error_log('achbot_wallet_paid_report_owner_notice_failed pay_id='.
                    (int)$payInfo['id']);
            }
            return;
        }
        $days=(int)$paidPlan['days'];
        $volume=(int)$paidPlan['volume'];
    } else {
        // Non-BUY_SUB renewal retains its existing plan/notice contract.
        $planDetails=getPlanDetails($connection,$fid);
        $days=$planDetails['days'];
        $volume=$planDetails['volume'];
    }

    $keys = json_encode([
        'inline_keyboard' => [
            [ ['text' => "🛍 خرید اشتراک جدید", 'callback_data' => "wizwizch"] ],
            [ ['text' => "🆔 $hashId",          'callback_data' => "noop_$hashId"] ]
        ]
    ], JSON_UNESCAPED_UNICODE);

    if ($payType === "RENEW_SCONFIG") {
        $msg = str_replace(
            ['TYPE', 'USER-ID', 'USERNAME', 'NAME', 'PRICE', 'REMARK', 'VOLUME', 'DAYS'],
            ['کیف پول', $from_id, $username, $first_name, $price, $lastRemark, $volume, $days],
            $mainValues['renew_account_request_message']
        );
    } else {
        $msg = str_replace(
            ['SERVERNAME', 'TYPE', 'USER-ID', 'USERNAME', 'NAME', 'PRICE', 'REMARK', 'VOLUME', 'DAYS'],
            [$serverTitle, 'کیف پول', $from_id, $username, $first_name, $price, $lastRemark, $volume, $days],
            $mainValues['buy_new_account_request']
        );
    }

    sendMessage($msg, $keys, "html", $admin);

    $rewardChannel = $botState['rewardChannel'] ?? null;
    if (!empty($rewardChannel)) {
        sendMessage($msg, $keys, "html", $rewardChannel);
    }
}

if (preg_match('/payBuyWith(?:CartToCart|GiftCard|ManualUsdt)(.*)/', $data, $match)) {

    $payInfo = dbFetchPayByHash($connection, $match[1]);
    if (!$payInfo) {
        prepareStartFallback();
        delMessage();
        return '__ACHBOT_V8_GOTO_START__';
    }

    $fid = (int)$payInfo['plan_id'];

    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $file_detail = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $server_id  = (int)$file_detail['server_id'];
    $acount     = (int)$file_detail['acount'];
    $inbound_id = (int)$file_detail['inbound_id'];

    if ($payInfo['type'] != "RENEW_SCONFIG") {

        if ($acount == 0 && $inbound_id != 0) {
            alert($mainValues['out_of_connection_capacity'], true);
            exit;
        }

        if ($inbound_id == 0) {
            $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
            $stmt->bind_param("i", $server_id);
            $stmt->execute();
            $server_info = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ((int)$server_info['ucount'] <= 0) {
                alert($mainValues['out_of_server_capacity'], true);
                exit;
            }
        } else {
            if ($acount <= 0) {
                alert(str_replace("AMOUNT", $acount, $mainValues['can_create_specific_account']), true);
                exit;
            }
        }
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
    $keyboard = kbSendReceiptAndCancel(in_array($method, ['manual_usdt','manual_trx'], true) ? "sendBuyUsdtReceipt" : ($method === 'gift_card' ? "sendBuyGiftReceipt" : "sendBuyCartReceipt"), "cancelBuyCartReceipt", $match[1], $method);

    $delivered = sendMessage($msg, $keyboard, "HTML");
    require_once __DIR__ . '/../PaymentLifecycle.php';
    achbotPayUxTrackByHash($connection,(string)$payInfo['hash_id'],(int)$from_id,$delivered);
    if (is_object($delivered) && !empty($delivered->ok)) {
    setUser("payBuyWith" . (in_array($method, ['manual_usdt','manual_trx'], true) ? 'ManualUsdt' : ($method === 'gift_card' ? 'GiftCard' : 'CartToCart')) . $match[1]);
        delMessage();
    } else {
        // The pinned attempt may be reopened. Never erase the selection menu
        // if Telegram failed to deliver the replacement instructions.
        alert("⚠️ نمایش اطلاعات پرداخت تأیید نشد. از همان دکمه دوباره تلاش کنید و وجهی ارسال نکنید.", true);
    }
    exit;
}

if (preg_match('/send(?:BuyCart|BuyGift|BuyUsdt)Receipt(?<hashId>.*)/', $data, $match)) {

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
    setUser((in_array($method, ['manual_usdt','manual_trx'], true) ? "waitBuyUsdtReceipt" : ($method === 'gift_card' ? "waitBuyGiftReceipt" : "waitBuyCartReceipt")) . $match['hashId']);

    $keyboard = kbOnlyCancel("cancelBuyCartReceipt", $match['hashId']);
    $sent=sendMessage(achbotManualReceiptPrompt($mainValues, $method), $keyboard, "HTML");
    require_once __DIR__ . '/../PaymentLifecycle.php';
    achbotPayUxTrackByHash($connection,(string)$match['hashId'],(int)$from_id,$sent);
    exit;
}

if (preg_match('/cancelBuyCartReceipt(?<hashId>.*)/', $data, $match)) {
    handleCancelFlow($mainValues, $removeKeyboard);
}

if (preg_match('/wait(?:BuyCart|BuyGift|BuyUsdt)Receipt(.*)/', $userInfo['step'], $match)) {

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
        $keyboard = kbOnlyCancel("cancelBuyCartReceipt", $match[1]);
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

    $fid = (int)$payInfo['plan_id'];


    $uid = $userInfo['userid'];
    $name = $userInfo['name'];
    $username = $userInfo['username'];

    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $days = $res['days'];
    $volume = $res['volume'];

    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
    $stmt->bind_param("i", $res['server_id']);
    $stmt->execute();
    $serverInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $serverTitle = $serverInfo['title'];

    if ($payInfo['type'] == "RENEW_SCONFIG") {
        $configInfo = json_decode($payInfo['description'], true);
        $filename = $configInfo['remark'];
    } else {
        $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `id`=?");
        $stmt->bind_param("i", $res['catid']);
        $stmt->execute();
        $catname = $stmt->get_result()->fetch_assoc()['title'];
        $stmt->close();
        $filename = $catname . " " . $res['title'];
    }

    $fileprice = $payInfo['price'];



    if ((int)$payInfo['agent_count'] != 0) {
        $msg = str_replace(
            ['ACCOUNT-COUNT', 'TYPE', 'USER-ID', "USERNAME", "NAME", "PRICE", "REMARK"],
            [$payInfo['agent_count'], achbotManualLabel($method), $from_id, $username, $name, $fileprice, $filename],
            $mainValues['buy_new_much_account_request']
        );
    } else {
        $msg = str_replace(
            ['SERVERNAME', 'TYPE', 'USER-ID', "USERNAME", "NAME", "PRICE", "REMARK", "VOLUME", "DAYS"],
            [$serverTitle, achbotManualLabel($method), $from_id, $username, $name, $fileprice, $filename, $volume, $days],
            $mainValues['buy_new_account_request']
        );
    }

    $adminKeyboard = buildAdminKeyboardWithStats(
        $connection,
        $buttonValues,
        "accept{$match[1]}",
        "declineAction_{$match[1]}",
        $match[1],
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

