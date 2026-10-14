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
    
    $price = $payInfo['price'];
    $priceInTrx = round($price / $botState['TRXRate'],2);
    
    $stmt = $connection->prepare("UPDATE `pays` SET `tron_price` = ? WHERE `hash_id` = ?");
    $stmt->bind_param("ds", $priceInTrx, $match[1]);
    $stmt->execute();
    $stmt->close();
    
    sendMessage(str_replace(["AMOUNT", "TRON-WALLET"], [$priceInTrx, $paymentKeys['tronwallet']], $mainValues['pay_with_tron_wallet']), $cancelKey, "html");
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
        
        if($checkExist->num_rows == 0){
            $stmt = $connection->prepare("UPDATE `pays` SET `payid` = ?, `state` = '0' WHERE `hash_id` = ?");
            $stmt->bind_param("ss", $text, $match[1]);
            $stmt->execute();
            $stmt->close();
            
            sendMessage($mainValues['in_review_tax_id'], $removeKeyboard);
            setUser();
            sendMessage($mainValues['reached_main_menu'],getMainKeys());
        }else sendMessage($mainValues['used_tax_id']);
    }

}
if(preg_match('/payWithWeSwap(.*)/',$data,$match)) {
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
    sendMessage($mainValues['please_wait_message'],$removeKeyboard);
    
    
    $price = $payInfo['price'];
    $priceInUSD = round($price / $botState['USDRate'],2);
    $priceInTrx = round($price / $botState['TRXRate'],2);
    $pay = NOWPayments('POST', 'payment', [
        'price_amount' => $priceInUSD,
        'price_currency' => 'usd',
        'pay_currency' => 'trx'
    ]);
    if(isset($pay->pay_address)){
        $payAddress = $pay->pay_address;
        
        $payId = $pay->payment_id;
        
        $stmt = $connection->prepare("UPDATE `pays` SET `payid` = ? WHERE `hash_id` = ?");
        $stmt->bind_param("is", $payId, $match[1]);
        $stmt->execute();
        $stmt->close();
        
        $keys = json_encode(['inline_keyboard'=>[
            [['text'=>"پرداخت با درگاه ارزی ریالی",'url'=>"https://changeto.technology/quick?amount=$priceInTrx&currency=TRX&address=$payAddress"]],
            [['text'=>"پرداخت کردم ✅",'callback_data'=>"havePaiedWeSwap" . $match[1]]]
            ]]);
sendMessage("
✅ لینک پرداخت با موفقیت ایجاد شد

💰 مبلغ: " . $priceInTrx . " ترون

✔️ بعد از پرداخت حدود 1 الی 15 دقیقه صبر کنید تا پرداخت به صورت کامل انجام شود سپس روی پرداخت کردم کلیک کنید.
",$keys);
    }else{
        if($pay->statusCode == 400){
            sendMessage("مقدار انتخاب شده کمتر از حد مجاز است.");
        }else{
            sendMessage("مشکلی رخ داده است، لطفا به پشتیبانی اطلاع بدهید.");
        }
        sendMessage("لطفا یکی از کلید های زیر را انتخاب کنید.",getMainKeys());
    }
}

if (preg_match('/havePaiedWeSwap(.*)/', $data, $match)) {

    $hash_id = trim($match[1]);

    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $hash_id);
    $stmt->execute();
    $payRes = $stmt->get_result();
    $stmt->close();

    if ($payRes->num_rows == 0) {
        $text = "/start";
        $data = "";
        delMessage();
        return '__ACHBOT_V8_GOTO_START__';
    }

    $payInfo = $payRes->fetch_assoc();

    if ($payInfo['state'] != "pending") {
        alert("این لینک پرداخت منقضی شده است.");
        return;
    }

    $payid   = $payInfo['payid'];
    $payType = $payInfo['type'];
    $price   = (int)$payInfo['price'];

    $request_json = NOWPayments('GET', 'payment', $payid);

    if (
        $request_json->payment_status == 'finished' ||
        $request_json->payment_status == 'confirmed' ||
        $request_json->payment_status == 'sending'
    ) {
        $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'approved' WHERE `hash_id` = ?");
        $stmt->bind_param("s", $hash_id);
        $stmt->execute();
        $stmt->close();

        if ($payType == "INCREASE_WALLET") {

            $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` + ? WHERE `userid` = ?");
            $stmt->bind_param("ii", $price, $from_id);
            $stmt->execute();
            $stmt->close();

            sendMessage(
                "✅ افزایش حساب شما با موفقیت تایید شد.\n" .
                "✅ مبلغ " . number_format($price) . " تومان به حساب شما اضافه شد.\n\n" .
                "<blockquote>
⚠️ لطفا دقت کنید شما فقط کیف پول خودتان را شارژ کرده‌اید، برای خرید یا تمدید باید از دکمه های زیر استفاده کنید:

🛒 <b>خرید اشتراک جدید</b>
یا <b>🔄 تمدید یا مشاهده اشتراک ها</b>

و در مرحله پرداخت روی دکمه زیر بزنید:

💰 <b>پرداخت با موجودی</b>

</blockquote>
📨 پشتیبانی:

🆔 $supportID"
            );

            sendMessage(
                "✅ مبلغ " . number_format($price) . " تومان به کیف پول کاربر $from_id توسط درگاه ارزی ریالی اضافه شد.",
                null,
                null,
                $admin
            );
            sendToAllAdmins('sendMessage', [
                'text'        => "✅ مبلغ " . number_format($price) . " تومان به کیف پول کاربر $from_id توسط درگاه ارزی ریالی اضافه شد.",
                'reply_markup'=> null,
                'parse_mode'  => null
            ]);
        }

        elseif ($payType == "BUY_SUB") {

            $uid         = $from_id;
            $fid         = (int)$payInfo['plan_id'];
            $volume      = (int)$payInfo['volume'];
            $days        = (int)$payInfo['day'];
            $description = $payInfo['description'];

            $planDetails = getPlanDetails($connection, $fid);
            $plan        = $planDetails['plan'];

            if ($volume == 0 && $days == 0) {
                $volume = (int)$plan['volume'];
                $days   = (int)$plan['days'];
            }

            $date             = time();
            $expire_microdate = floor(microtime(true) * 1000) + (864000 * $days * 100);
            $expire_date      = $date + (86400 * $days);

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

            $accountCount = ($payInfo['agent_count'] ?? 0) != 0
                ? (int)$payInfo['agent_count']
                : 1;
            $eachPrice = $accountCount > 0 ? ($price / $accountCount) : $price;

            ensureServerCapacity($connection, $server_id, $inbound_id, $acount, $mainValues);

            [$serverInfo, $serverConfig] = getServerInfoAndConfig($connection, $server_id);
            $srv_remark = $serverInfo['remark'];
            $serverTitle = $serverInfo['title'];
            $serverType = $serverConfig['type'];
            $portType   = $serverConfig['port_type'];

            include_once 'phpqrcode/qrlib.php';

            alert($mainValues['sending_config_to_user']);

            $agent_bought = (int)($payInfo['agent_bought'] ?? 0);

            for ($i = 1; $i <= $accountCount; $i++) {

                $uniqid = generateRandomString(42, $protocol);

                [$port] = getNextPortAndIncrease('settings/temp.txt', $portType);

                $remark = buildRemark(
                    $botState,
                    $srv_remark,
                    $payInfo['description'],
                    $from_id
                );
                if (!empty($description)) {
                    $remark = $description;
                }

                [$response, $remark, $port] = createServerAccount(
                    $serverType,
                    $server_id,
                    $inbound_id,
                    $fid,
                    $remark,
                    $volume,
                    $days,
                    $uniqid,
                    $protocol,
                    $port,
                    $expire_microdate,
                    $netType,
                    $rahgozar,
                    $limitip
                );

                ensureAccountCreationSucceeded($response, $serverInfo, $inbound_id, $admin);

                [$token, $vray_link, $uniqid] = buildLinksAndSendQr(
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
                    $hideConfigIfMany
                );

                insertOrder(
                    $connection,
                    $uid,
                    $token,
                    $fid,
                    $server_id,
                    $inbound_id,
                    $remark,
                    $uniqid,
                    $protocol,
                    $expire_date,
                    $vray_link,
                    $eachPrice,
                    $date,
                    $rahgozar,
                    $agent_bought
                );
            }

            $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
            $stmt->bind_param("i", $uid);
            $stmt->execute();
            $user_detail = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!empty($user_detail['refered_by'])) {
                $inviterId = (int)$user_detail['refered_by'];

                giveReferralReward($connection, $user_detail['userid'], $inviterId, $admin, $botState);

                if ($admin != $from_id) {
                    $uname     = $user_detail['name'];
                    $user_name = $user_detail['username'];

                    $keysInvite = json_encode([
                        'inline_keyboard' => [
                            [
                                ['text' => "🛍 خرید اشتراک جدید", 'callback_data' => "wizwizch"]
                            ],
                        ]
                    ]);

                    $msgInvite = str_replace(
                        ['USER-ID', 'USERNAME', 'NAME', 'PRICE', 'REMARK', 'HASHID'],
                        [$uid, $user_name, $uname, $price, $remark, $hash_id],
                        $mainValues['invite_buy_new_account']
                    );

                    sendMessage($msgInvite, $keysInvite, "html", $admin);

                    $rewardChannel = $botState['rewardChannel'] ?? null;
                    if (!empty($rewardChannel)) {
                        sendMessage($msgInvite, $keysInvite, "html", $rewardChannel);
                    }
                }
            }

            decreaseServerCapacity($connection, $server_id, $inbound_id, $fid, $accountCount);

            $keysAdmin = json_encode([
                'inline_keyboard' => [
                    [
                        ['text' => "🛍 خرید اشتراک جدید", 'callback_data' => "wizwizch"]
                    ],
                    [
                        ['text' => "🆔 $hash_id", 'callback_data' => "noop_$hash_id"]
                    ]
                ]
            ]);

            $msgAdmin = str_replace(
                ['SERVERNAME', 'TYPE', 'USER-ID', 'USERNAME', 'NAME', 'PRICE', 'REMARK', 'VOLUME', 'DAYS'],
                [$serverTitle, 'ارزی ریالی', $from_id, $username, $first_name, $price, $remark, $volume, $days],
                $mainValues['buy_new_account_request']
            );

            sendMessage($msgAdmin, $keysAdmin, "html", $admin);
            sendToAllAdmins('sendMessage', [
                'text'        => $msgAdmin,
                'reply_markup'=> $keysAdmin,
                'parse_mode'  => "html"
            ]);
        }

        elseif ($payType == "RENEW_ACCOUNT") {

            $oid = (int)$payInfo['order_id'];

            $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
            $stmt->bind_param("i", $oid);
            $stmt->execute();
            $order = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$order) {
                alert("⛔️ سفارش مربوطه پیدا نشد.", true);
                return;
            }

            $fid        = (int)$order['fileid'];
            $remark     = $order['remark'];
            $uuid       = $order['uuid'] ?? "0";
            $server_id  = (int)$order['server_id'];
            $inbound_id = (int)$order['inbound_id'];

            $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ? AND `active` = 1");
            $stmt->bind_param("i", $fid);
            $stmt->execute();
            $respd = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$respd) {
                alert("⛔️ پلن برای تمدید پیدا نشد.", true);
                return;
            }

            $name   = $respd['title'];
            $days   = (int)$respd['days'];
            $volume = (int)$respd['volume'];
            $price  = (int)$payInfo['price'];

            $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id` = ?");
            $stmt->bind_param("i", $server_id);
            $stmt->execute();
            $server_info = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $serverType = $server_info['type'];

            if ($serverType == "marzban") {
                $response = editMarzbanConfig($server_id, [
                    'remark' => $remark,
                    'days'   => $days,
                    'volume' => $volume
                ]);
            } else {
                if ($inbound_id > 0) {
                    $response = editClientTraffic($server_id, $inbound_id, $uuid, $volume, $days, "renew");
                } else {
                    $response = editInboundTraffic($server_id, $uuid, $volume, $days, "renew");
                }
            }

            if (is_null($response)) {
                alert('⚠️ مشکل فنی در اتصال به سرور، لطفا به پشتیبانی اطلاع دهید.', true);
                return;
            }

            $newExpire = $time + $days * 86400;
            $stmt = $connection->prepare("UPDATE `orders_list` SET `expire_date` = ?, `notif` = 0 WHERE `id` = ?");
            $stmt->bind_param("ii", $newExpire, $oid);
            $stmt->execute();
            $stmt->close();

            $uid = $from_id;
            $stmt = $connection->prepare(
                "INSERT INTO `increase_order` (`userid`, `server_id`, `inbound_id`, `remark`, `amount`, `date`) 
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param("iiisii", $uid, $server_id, $inbound_id, $remark, $price, $time);
            $stmt->execute();
            $stmt->close();

            sendMessage("✅ اشتراک $remark با موفقیت تمدید شد.", getMainKeys());

            $keysRenew = json_encode([
                'inline_keyboard' => [
                    [
                        ['text' => "♻️ تمدید اشتراک", 'callback_data' => "wizwizch"]
                    ],
                    [
                        ['text' => "🆔 $hash_id", 'callback_data' => "noop_$hash_id"]
                    ]
                ]
            ]);

            $msg = str_replace(
                ['TYPE', "USER-ID", "USERNAME", "NAME", "PRICE", "REMARK", "VOLUME", "DAYS"],
                ['کیف پول', $from_id, $username, $first_name, $price, $remark, $volume, $days],
                $mainValues['renew_account_request_message']
            );

            sendMessage($msg, $keysRenew, "html", $admin);
            sendToAllAdmins('sendMessage', [
                'text'        => $msg,
                'reply_markup'=> $keysRenew,
                'parse_mode'  => "html"
            ]);

            $rewardChannel = $botState['rewardChannel'] ?? null;
            if (!empty($rewardChannel)) {
                sendMessage($msg, $keysRenew, "html", $rewardChannel);
            }
        }

        elseif (preg_match('/^INCREASE_DAY_(\d+)_(\d+)/', $payType, $increaseInfo)) {

            $orderId = (int)$increaseInfo[1];

            $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
            $stmt->bind_param("i", $orderId);
            $stmt->execute();
            $orderInfo = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$orderInfo) {
                alert("⛔️ سفارش برای افزایش زمان یافت نشد.", true);
                return;
            }

            $server_id  = (int)$orderInfo['server_id'];
            $inbound_id = (int)$orderInfo['inbound_id'];
            $remark     = $orderInfo['remark'];
            $uuid       = $orderInfo['uuid'] ?? "0";

            $planid = (int)$increaseInfo[2];

            $stmt = $connection->prepare("SELECT * FROM `increase_day` WHERE `id` = ?");
            $stmt->bind_param("i", $planid);
            $stmt->execute();
            $res = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$res) {
                alert("⛔️ پلن افزایش روز یافت نشد.", true);
                return;
            }

            $price  = (int)$payInfo['price'];
            $volume = (int)$res['volume']; // تعداد روز

            $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id` = ?");
            $stmt->bind_param("i", $server_id);
            $stmt->execute();
            $server_info = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $serverType = $server_info['type'];

            if ($serverType == "marzban") {
                $response = editMarzbanConfig($server_id, ['remark' => $remark, 'plus_day' => $volume]);
            } else {
                if ($inbound_id > 0) {
                    $response = editClientTraffic($server_id, $inbound_id, $uuid, 0, $volume);
                } else {
                    $response = editInboundTraffic($server_id, $uuid, 0, $volume);
                }
            }

            if (!is_null($response) && !empty($response->success)) {

                // افزایش expire_date
                $newVolume = $volume * 86400;
                $stmt = $connection->prepare(
                    "UPDATE `orders_list` SET `expire_date` = `expire_date` + ?, `notif` = 0 WHERE `uuid` = ?"
                );
                $stmt->bind_param("is", $newVolume, $uuid);
                $stmt->execute();
                $stmt->close();

                // ثبت در increase_order
                $stmt = $connection->prepare(
                    "INSERT INTO `increase_order` (`userid`, `server_id`, `inbound_id`, `remark`, `amount`, `date`, `day`) 
                     VALUES (?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->bind_param("iiisiii", $from_id, $server_id, $inbound_id, $remark, $price, $time, $volume);
                $stmt->execute();
                $stmt->close();

                sendMessage("✅ $volume روز به مدت زمان اشتراک شما اضافه شد", getMainKeys());

                $keys = json_encode([
                    'inline_keyboard' => [
                        [
                            ['text' => "⏳ افزایش زمان اشتراک", 'callback_data' => "wizwizch"]
                        ],
                        [
                            ['text' => "🆔 $hash_id", 'callback_data' => "noop_$hash_id"]
                        ]
                    ]
                ]);

                $msg = "
🔋|💰 افزایش زمان با (کیف پول)

▫️شناسه عددی: $from_id
👨 اسم کاربر: $first_name
⚡️ نام کاربری: @$username
🎈 نام کاربری اشتراک: $remark
⏰ مدت افزایش: $volume روز
💰 قیمت: $price تومان
";
                sendMessage($msg, $keys, "html", $admin);

                $rewardChannel = $botState['rewardChannel'] ?? null;
                if (!empty($rewardChannel)) {
                    sendMessage($msg, $keys, "html", $rewardChannel);
                }
            } else {
                alert("به دلیل مشکل فنی امکان افزایش حجم نیست. لطفا به مدیریت اطلاع بدید یا 5 دقیقه دیگر دوباره تست کنید.", true);
            }
        }

        elseif (preg_match('/^INCREASE_VOLUME_(\d+)_(\d+)/', $payType, $increaseInfo)) {

            $orderId = (int)$increaseInfo[1];

            $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
            $stmt->bind_param("i", $orderId);
            $stmt->execute();
            $orderInfo = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$orderInfo) {
                alert("⛔️ سفارش برای افزایش حجم یافت نشد.", true);
                return;
            }

            $server_id  = (int)$orderInfo['server_id'];
            $inbound_id = (int)$orderInfo['inbound_id'];
            $remark     = $orderInfo['remark'];
            $uuid       = $orderInfo['uuid'] ?? "0";

            $planid = (int)$increaseInfo[2];

            $stmt = $connection->prepare("SELECT * FROM `increase_plan` WHERE `id` = ?");
            $stmt->bind_param("i", $planid);
            $stmt->execute();
            $res = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$res) {
                alert("⛔️ پلن افزایش حجم یافت نشد.", true);
                return;
            }

            $price  = (int)$payInfo['price'];
            $volume = (int)$res['volume'];

            $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id` = ?");
            $stmt->bind_param("i", $server_id);
            $stmt->execute();
            $server_info = $stmt->get_result()->fetch_assoc();
            $stmt->close();
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

            if (!is_null($response) && !empty($response->success)) {

                $stmt = $connection->prepare("UPDATE `orders_list` SET `notif` = 0 WHERE `uuid` = ?");
                $stmt->bind_param("s", $uuid);
                $stmt->execute();
                $stmt->close();

                $stmt = $connection->prepare(
                    "INSERT INTO `increase_order` (`userid`, `server_id`, `inbound_id`, `remark`, `amount`, `date`, `volume`) 
                     VALUES (?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->bind_param("iiisiii", $from_id, $server_id, $inbound_id, $remark, $price, $time, $volume);
                $stmt->execute();
                $stmt->close();

                $keys = json_encode([
                    'inline_keyboard' => [
                        [
                            ['text' => "🔋 افزایش حجم اشتراک", 'callback_data' => "wizwizch"]
                        ],
                        [
                            ['text' => "🆔 $hash_id", 'callback_data' => "noop_$hash_id"]
                        ]
                    ]
                ]);

                $msg = "
🔋|💰 افزایش حجم با (کیف پول)

🎫 شناسه عددی: $from_id
👨 اسم کاربر: $first_name
⚡️ نام کاربری: @$username
🎈 نام کاربری اشتراک: $remark
⏰ مقدار افزایش: $volume گیگ
💰 قیمت: $price تومان
";
                sendMessage($msg, $keys, "html", $admin);

                $rewardChannel = $botState['rewardChannel'] ?? null;
                if (!empty($rewardChannel)) {
                    sendMessage($msg, $keys, "html", $rewardChannel);
                }

                sendMessage("✅ $volume گیگ به حجم اشتراک شما اضافه شد.", getMainKeys());
            } else {
                alert("به دلیل مشکل فنی امکان افزایش حجم نیست. لطفا به مدیریت اطلاع بدید یا 5 دقیقه دیگر دوباره تست کنید.", true);
            }
        }

        elseif ($payType == "RENEW_SCONFIG") {

            $uid = $from_id;
            $fid = (int)$payInfo['plan_id'];

            // پلن
            $planDetails = getPlanDetails($connection, $fid);
            $plan        = $planDetails['plan'];
            $volume      = $planDetails['volume'];
            $days        = $planDetails['days'];

            $price     = (int)$payInfo['price'];
            $server_id = (int)$plan['server_id'];

            $configInfo = json_decode($payInfo['description'], true) ?: [];
            $remark     = $configInfo['remark'] ?? '';
            $uuid       = $configInfo['uuid']   ?? '';
            $isMarzban  = !empty($configInfo['marzban']);

            $inbound_id = (int)$payInfo['volume'];

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

            if (is_null($response)) {
                alert('🔻مشکل فنی در اتصال به سرور، لطفا به مدیریت اطلاع بدید.', true);
                return;
            }

            $stmt = $connection->prepare(
                "INSERT INTO `increase_order` (`userid`, `server_id`, `inbound_id`, `remark`, `amount`, `date`) 
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param("iiisii", $uid, $server_id, $inbound_id, $remark, $price, $time);
            $stmt->execute();
            $stmt->close();

            $keys = json_encode([
                'inline_keyboard' => [
                    [
                        ['text' => "♻️ تمدید اشتراک", 'callback_data' => "wizwizch"]
                    ],
                    [
                        ['text' => "🆔 $hash_id", 'callback_data' => "noop_$hash_id"]
                    ]
                ]
            ]);

            $msg = "
🔋|💰 تمدید اشتراک با (کیف پول)

🎫 شناسه عددی: $from_id
👨 اسم کاربر: $first_name
⚡️ نام کاربری: @$username
🎈 نام کاربری اشتراک: $remark
⏰ مدت اشتراک: $days روز
⬇️ حجم اشتراک: $volume گیگ
💰 قیمت: $price تومان
";
            sendMessage($msg, $keys, "html", $admin);
            sendToAllAdmins('sendMessage', [
                'text'        => $msg,
                'reply_markup'=> $keys,
                'parse_mode'  => "html"
            ]);
        }

        editKeys(json_encode([
            'inline_keyboard' => [
                [
                    ['text' => "پرداخت انجام شد.", 'callback_data' => "wizwizch"]
                ]
            ]
        ]));
    } else {
        // پرداخت هنوز کامل نیست
        if ($request_json->payment_status == 'partially_paid') {
            $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'partiallyPaied' WHERE `hash_id` = ?");
            $stmt->bind_param("s", $hash_id);
            $stmt->execute();
            $stmt->close();

            alert("شما هزینه کمتری پرداخت کردید، لطفا به پشتیبانی پیام بدهید.");
        } else {
            alert("پرداخت مورد نظر هنوز تکمیل نشده!");
        }
    }
}

