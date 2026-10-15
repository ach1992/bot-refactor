<?php
/** AChBot V8: source-pinned financial compatibility module. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

if (isset($_GET['nowpayment'])) {

    if (isset($_GET['NP_id'])) {
        // Validate a local pending payment and its provider subject before
        // contacting NowPayments. An arbitrary/retired callback must not
        // cause even a payment-provider verification request.
        $incomingPaymentId = (string) $_GET['NP_id'];
        $callbackHash = (string) ($_GET['hash_id'] ?? '');
        if ($incomingPaymentId === '') {
            showForm("کد پرداخت نامعتبر است.", "خطا!");
            return;
        }
        if ($callbackHash !== '') {
            $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? AND (`state` = 'pending' OR `state` = 'send') LIMIT 1");
            $stmt->bind_param('s', $callbackHash);
        } else {
            // Legacy callbacks without a hash can only be admitted when
            // their provider ID directly matches the stored invoice ID.
            $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `payid` = ? AND (`state` = 'pending' OR `state` = 'send') LIMIT 1");
            $stmt->bind_param('s', $incomingPaymentId);
        }
        $stmt->execute();
        $preflightPay = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$preflightPay || empty($preflightPay['payid']) ||
            !achbotSupportsPaymentSubject($connection, $preflightPay)) {
            showForm("تایید این پرداخت برای سرویس غیرقابل پشتیبانی متوقف شد؛ با پشتیبانی تماس بگیرید.", "خطا");
            return;
        }

        $base_url = 'https://api.nowpayments.io/v1/payment/' . rawurlencode($incomingPaymentId);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-API-KEY: ' . ($paymentKeys['nowpayment'] ?? '')]);
        curl_setopt($ch, CURLOPT_URL, $base_url);

        $res = json_decode(curl_exec($ch));
        curl_close($ch);

        $hash_id = (string) ($res->invoice_id ?? '');
        if ($hash_id === '' || $hash_id !== (string) $preflightPay['payid']) {
            showForm("شناسه فاکتور پرداخت با سفارش مطابقت ندارد.", "خطا");
            return;
        }

        $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `payid` = ? AND (`state` = 'pending' OR `state` = 'send')");
        $stmt->bind_param("s", $hash_id);
        $stmt->execute();
        $payInfo = $stmt->get_result();
        $stmt->close();

        if (mysqli_num_rows($payInfo) == 0) {
            showForm("کد پرداخت یافت نشد.", "خطا!");
        } else {
            $payParam = $payInfo->fetch_assoc();
        if (!achbotSupportsPaymentSubject($connection, $payParam)) {
            showForm("تایید این پرداخت برای سرویس غیرقابل پشتیبانی متوقف شد؛ با پشتیبانی تماس بگیرید.", "خطا");
            return;
        }

            $rowId = (int)$payParam['id'];
            $payDescription = payTypeToDescription($payParam['type']);

            //==============================================================
            $st = $res->payment_status ?? '';
            if ($st == 'finished' || $st == 'confirmed' || $st == 'sending') {
                doAction($rowId, "nowpayment");
            } else {
                if ($st == 'partially_paid') {
                    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'low_payment' WHERE `payid` =?");
                    $stmt->bind_param("s", $hash_id);
                    $stmt->execute();
                    $stmt->close();

                    showForm("#$hash_id - شما هزینه کمتری واریز کردید، لطفا به پشتیبانی مراجعه کنید", $payDescription);
                } else {
                    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'canceled' WHERE `payid` =?");
					$stmt->bind_param("s", $hash_id);
                    $stmt->execute();
                    $stmt->close();

                    showForm("پرداخت انجام نشد.", $payDescription);
                }
            }
        }
    } else {
        showForm("پرداخت انجام نشد.", "خطا!");
    }

} elseif (isset($_GET['zarinpal'])) {

    $hash_id = $_GET['hash_id'];

    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? AND (`state` = 'pending' OR `state` = 'send')");
    $stmt->bind_param("s", $hash_id);
    $stmt->execute();
    $payInfo = $stmt->get_result();
    $stmt->close();

    if (mysqli_num_rows($payInfo) == 0) {
        showForm("کد پرداخت یافت نشد.", "خطا!");
    } else {
        $payParam = $payInfo->fetch_assoc();
        if (!achbotSupportsPaymentSubject($connection, $payParam)) {
            showForm("تایید این پرداخت برای سرویس غیرقابل پشتیبانی متوقف شد؛ با پشتیبانی تماس بگیرید.", "خطا");
            return;
        }

        $rowId = (int)$payParam['id'];
        $amount = (int)$payParam['price'];

        $Authority = $_GET['Authority'];

        //==============================================================
        $client = new SoapClient('https://www.zarinpal.com/pg/services/WebGate/wsdl', ['encoding' => 'UTF-8']);
        $result = $client->PaymentVerification([
            'MerchantID' => ($paymentKeys['zarinpal'] ?? ''),
            'Authority'  => $Authority,
            'Amount'     => $amount,
        ]);
        //==============================================================

        if ($_GET['Status'] == 'OK' and $result->Status == 100) {
            doAction($rowId, "zarinpal");
        } else {
            $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'canceled' WHERE `hash_id` = ?");
            $stmt->bind_param("s", $hash_id);
            $stmt->execute();
            $stmt->close();

            showForm("پرداخت شما انجام نشد!", "درگاه زرین پال");
        }
    }

} elseif (isset($_GET['nextpay'])) {

    $hash_id = $_GET['trans_id'];

    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `payid` = ? AND (`state` = 'pending' OR `state` = 'send')");
    $stmt->bind_param("s", $hash_id);
    $stmt->execute();
    $payInfo = $stmt->get_result();
    $stmt->close();

    if (mysqli_num_rows($payInfo) == 0) {
        showForm("کد پرداخت یافت نشد.", "خطا!");
    } else {
        $payParam = $payInfo->fetch_assoc();
        if (!achbotSupportsPaymentSubject($connection, $payParam)) {
            showForm("تایید این پرداخت برای سرویس غیرقابل پشتیبانی متوقف شد؛ با پشتیبانی تماس بگیرید.", "خطا");
            return;
        }

        $rowId  = (int)$payParam['id'];
        $amount = (int)$payParam['price'];
        $payid  = $payParam['payid'];

        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://nextpay.org/nx/gateway/verify',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => 'api_key=' . ($paymentKeys['nextpay'] ?? '') . '&amount=' . $amount . '&currency=IRT&trans_id=' . $payid,
        ));

        $response = curl_exec($curl);
        curl_close($curl);
        $response = json_decode($response);

        if (($response->code ?? '') == '0') {
            doAction($rowId, "nextpay");
        } else {
            $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'canceled' WHERE `hash_id` = ?");
            $stmt->bind_param("s", $hash_id);
            $stmt->execute();
            $stmt->close();

            showForm("پرداخت شما انجام نشد!", "درگاه نکست پی");
        }
    }

} else {
    showForm("درگاه پرداخت شناسایی نشد.", "خطا!");
    exit();
}

$rewardChannel = $botState['rewardChannel'] ?? null;

function doAction($payRowId, $gateType) {
    global $connection, $admin, $userInfo, $from_id, $botUrl, $botState;
    global $buttonValues, $linkIndices, $hideConfigIfMany;
	
	$supportID = $botState['supportID'] ?? '';
    $buttonValuesLocal = (isset($buttonValues) && is_array($buttonValues))
        ? $buttonValues
        : ['back_to_main' => "↪️ بازگشت به منوی اصلی"];

    $linkIndicesLocal = (isset($linkIndices) && is_array($linkIndices) && count($linkIndices))
        ? $linkIndices
        : [0];

    $hideConfigIfManyLocal = isset($hideConfigIfMany) ? (bool)$hideConfigIfMany : false;

    $time = time();

    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `id` = ? AND (`state` = 'pending' OR `state` = 'send')");
    $stmt->bind_param("i", $payRowId);
    $stmt->execute();
    $payInfo = $stmt->get_result();
    $stmt->close();

    if (mysqli_num_rows($payInfo) == 0) {
        showForm("کد پرداخت یافت نشد.", "خطا!");
        return;
    }

    $payParam = $payInfo->fetch_assoc();
        if (!achbotSupportsPaymentSubject($connection, $payParam)) {
            showForm("تایید این پرداخت برای سرویس غیرقابل پشتیبانی متوقف شد؛ با پشتیبانی تماس بگیرید.", "خطا");
            return;
        }


    $rowId      = (int)$payParam['id'];
    $amount     = (int)$payParam['price'];
    $user_id    = (int)$payParam['user_id'];
    $payType    = $payParam['type'];
    $description= $payParam['description'];
    $hash_id    = $payParam['hash_id'];

    $plan_id    = (int)$payParam['plan_id'];
    $volume     = (int)$payParam['volume'];
    $days       = (int)$payParam['day'];
    $agentBought= (int)($payParam['agent_bought'] ?? 0);

    $payDescription = payTypeToDescription($payType);
    if ($gateType == "zarinpal" || $gateType == "nextpay") $payDescription = "خرید اشتراک";

    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'paid' WHERE `id` =?");
    $stmt->bind_param("i", $payRowId);
    $stmt->execute();
    $stmt->close();

    if ($payType == "BUY_SUB") {

        $fid = $plan_id;

        $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $userinfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $planDetails = getPlanDetails($connection, $fid);
        $file_detail = $planDetails['plan'];

        if ($volume == 0 && $days == 0) {
            $volume = (int)$file_detail['volume'];
            $days   = (int)$file_detail['days'];
        }

        $date = time();
        $expire_microdate = floor(microtime(true) * 1000) + (864000 * $days * 100);
        $expire_date = $date + (86400 * $days);

        $server_id  = (int)$file_detail['server_id'];
        $netType    = $file_detail['type'];
        $acount     = (int)$file_detail['acount'];
        $protocol   = $file_detail['protocol'];
        $inbound_id = (int)$file_detail['inbound_id'];
        $limitip    = $file_detail['limitip'];
        $rahgozar   = $file_detail['rahgozar'];
        $customPath = $file_detail['custom_path'];
        $customPort = $file_detail['custom_port'];
        $customSni  = $file_detail['custom_sni'];

        $accountCount = ($payParam['agent_count'] ?? 0) != 0 ? (int)$payParam['agent_count'] : 1;
        $eachPrice = ($accountCount > 0) ? ($amount / $accountCount) : $amount;

        if ($acount == 0 && $inbound_id != 0) {
            showForm('پرداخت شما انجام شد ولی ظرفیت این کانکشن پر شده است، مبلغ ' . number_format($amount) . " تومان به کیف پول شما اضافه شد.", $payDescription, false);

            refundToWalletAndNotify(
                $connection,
                $user_id,
                $amount,
                $gateType,
                $admin,
                "میخواست اشتراک بخره، ظرفیت پر بود.",
				$rewardChannel
            );
            exit;
        }

        if ($inbound_id == 0) {
            $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
            $stmt->bind_param("i", $server_id);
            $stmt->execute();
            $server_info = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (empty($server_info) || (int)$server_info['ucount'] <= 0) {
                showForm('پرداخت شما انجام شد ولی ظرفیت این سرور پر شده است، مبلغ ' . number_format($amount) . " تومان به کیف پول شما اضافه شد.", $payDescription, false);

                refundToWalletAndNotify(
                    $connection,
                    $user_id,
                    $amount,
                    $gateType,
                    $admin,
                    "میخواست اشتراک بخره، ظرفیت پر بود."
                );
                exit;
            }
        }

        [$serverInfo, $serverConfig] = getServerInfoAndConfig($connection, $server_id);
        $srv_remark = $serverInfo['remark'] ?? '';
        $serverType = $serverConfig['type'] ?? '';
        $portType   = $serverConfig['port_type'] ?? 'manual';

        for ($i = 1; $i <= $accountCount; $i++) {

            $uniqid = generateRandomString(42, $protocol);

            [$port] = getNextPortAndIncrease('../settings/temp.txt', $portType);

            $forcedRemark = (!empty($description)) ? $description : null;
            $remark = buildRemark($botState, $srv_remark, $description, $user_id, $forcedRemark);

            // First-use expiry staging was exclusive to never-deployed
            // Sanaei/Alireza inbound plans. Marzban keeps normal expiry.
            $expire_microdate_local = $expire_microdate;

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
                $expire_microdate_local,
                $netType,
                $rahgozar,
                $limitip
            );

            if (is_null($response)) {
                showForm('⚠️ پرداخت شما با موفقیت انجام شد. ولی اتصال به سرور برقرار نیست لطفا مدیر رو در جریان بزار ... مبلغ ' . number_format($amount) . " به کیف پولت اضافه شد.", $payDescription);

                refundToWalletAndNotify(
                    $connection,
                    $user_id,
                    $amount,
                    $gateType,
                    $admin,
                    "میخواست اشتراک بخره، اتصال به سرور برقرار نبود",
					$rewardChannel
                );
                exit;
            }

            if ($response == "inbound not Found") {
                showForm("پرداخت شما با موفقیت انجام شد. ولی ❌ | 🥺 سطر (inbound) با آیدی $inbound_id تو این سرور وجود نداره، مدیر رو در جریان بزار ... مبلغ " . number_format($amount) . " به کیف پول شما اضافه شد.", $payDescription);

                refundToWalletAndNotify(
                    $connection,
                    $user_id,
                    $amount,
                    $gateType,
                    $admin,
                    "میخواست اشتراک بخره، ولی اینباند پیدا نشد.",
					$rewardChannel
                );
                exit;
            }

            $ok = true;
            $msg = '';
            if (is_object($response)) {
                $ok = !empty($response->success);
                $msg = $response->msg ?? '';
            } elseif (is_array($response)) {
                $ok = !empty($response['success']);
                $msg = $response['msg'] ?? '';
            }

            if (!$ok) {
                showForm('⚠️ پرداخت شما با موفقیت انجام شد. ولی خطا داد لطفا به مدیر اطلاع دهید ... مبلغ ' . number_format($amount) . " تومان به کیف پولت اضافه شد.", $payDescription);

                $adminErr = "خطای سرور " . ($serverInfo['title'] ?? '-') . ":\n\n" . ($msg ?: 'بدون پیام خطا');
				notifyAllAdminsSimple($adminErr, $admin, "html", null, $rewardChannel);

                refundToWalletAndNotify(
                    $connection,
                    $user_id,
                    $amount,
                    $gateType,
                    $admin,
                    "میخواست اشتراک بخره، ولی خطا داد.",
					$rewardChannel
                );
                exit;
            }

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
                $user_id,
                $volume,
                $days,
                $buttonValuesLocal,
                $linkIndicesLocal,
                $hideConfigIfManyLocal,
                "pay/",
                dirname(__DIR__, 2) . '/pay'
            );

            insertOrder(
                $connection,
                $user_id,
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
                time(),
                $rahgozar,
                $agentBought
            );
        }

        showForm('✅ پرداخت شما با موفقیت انجام شد.', $payDescription, true);

        decreaseServerCapacity($connection, $server_id, $inbound_id, $fid, $accountCount);

        if (!empty($userinfo['refered_by'])) {
            giveReferralReward($connection, $user_id, (int)$userinfo['refered_by'], $admin, $botState);
        }

        $user_info = Bot('getChat', ['chat_id' => $user_id])->result;
        $first_name = $user_info->first_name ?? '-';
        $username = $user_info->username ?? '-';

        $keys = json_encode(['inline_keyboard' => [
            [
                ['text' => "💳 خرید از درگاه $gateType", 'callback_data' => 'wizwizch'],
            ],
            [
                ['text' => "🆔 $hash_id", 'callback_data' => "noop_$hash_id"]
            ]
        ]]);

        $msg = "
➕ خرید جدید (درگاه $gateType)

🔐 آیدی کاربر: $user_id
🛡️ نام کاربر: $first_name
🔖 نام کاربری تلگرام: @$username
💰 مبلغ پرداختی: $amount تومان
🎈 نام اشتراک: $remark
🔋 حجم اشتراک: $volume گیگ
⏰ مدت اشتراک: $days روز
";
		notifyAllAdminsSimple($msg, $admin, "html", $keys, $rewardChannel);
    }

    elseif ($payType == "INCREASE_WALLET") {

        addToWallet($connection, $user_id, $amount);

        showForm("✅ پرداخت شما با موفقیت انجام شد، مبلغ " . number_format($amount) . " تومان به کیف پول شما اضافه شد.", $payDescription, true);

        sendMessage("✅ مبلغ " . number_format($amount) . " تومان به حساب شما اضافه شد.\n\n<blockquote>
⚠️ لطفا دقت کنید شما فقط کیف پول خودتان را شارژ کرده‌اید، برای خرید یا تمدید باید از دکمه های زیر استفاده کنید:

🛒 <b>خرید اشتراک جدید</b>
یا <b>🔄 تمدید یا مشاهده اشتراک ها</b>

و در مرحله پرداخت روی دکمه زیر بزنید:

💰 <b>پرداخت با موجودی</b>

</blockquote>
📨 پشتیبانی:

🆔 $supportID", null, "html", $user_id);

        $adminText = "✅ مبلغ " . number_format($amount) . " تومان به کیف پول کاربر $user_id توسط درگاه $gateType اضافه شد.";
		notifyAllAdminsSimple($adminText, $admin, "html", null, $rewardChannel);
    }

    elseif ($payType == "RENEW_ACCOUNT") {

        $oid = (int)$payParam['order_id'];

        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
        $stmt->bind_param("i", $oid);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $fid = (int)$order['fileid'];
        $remark = $order['remark'];
        $uuid = $order['uuid'] ?? "0";
        $server_id = (int)$order['server_id'];
        $inbound_id = (int)$order['inbound_id'];

        $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ? AND `active` = 1");
        $stmt->bind_param("i", $fid);
        $stmt->execute();
        $respd = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $days = (int)$respd['days'];
        $volume = (int)$respd['volume'];

        [$srvInfo, $srvConfig] = getServerInfoAndConfig($connection, $server_id);
        $serverType = $srvConfig['type'] ?? '';

        $isMarzban = ($serverType === "marzban");

        $response = renewConfigOnServer($connection, $server_id, $inbound_id, $uuid, $remark, $days, $volume, $isMarzban);

        if (is_null($response)) {
            showForm('⚠️ پرداخت شما با موفقیت انجام شد ولی مشکل فنی در اتصال به سرور، لطفا به مدیریت اطلاع بدید، مبلغ ' . number_format($amount) . " تومان به کیف پول شما اضافه شد.", $payDescription);

            refundToWalletAndNotify(
                $connection,
                $user_id,
                $amount,
                $gateType,
                $admin,
                "میخواست اشتراکش رو تمدید کنه، ولی اتصال به سرور برقرار نبود.",
				$rewardChannel
            );
            exit;
        }

        $newExpire = $time + $days * 86400;
        $stmt = $connection->prepare("UPDATE `orders_list` SET `expire_date` = ?, `notif` = 0 WHERE `id` = ?");
        $stmt->bind_param("ii", $newExpire, $oid);
        $stmt->execute();
        $stmt->close();

        createIncreaseOrder($connection, $user_id, $server_id, $inbound_id, $remark, $amount, $time, null, null);

        showForm("✅ پرداخت شما با موفقیت انجام شد. اشتراک $remark با موفقیت تمدید شد.", $payDescription, true);
        sendMessage("✅ پرداخت شما با موفقیت انجام شد. اشتراک $remark با موفقیت تمدید شد.", json_encode(['inline_keyboard' => [[['text' => "↪️ بازگشت به منوی اصلی", 'callback_data' => "mainMenu"]]]]), null, $user_id);

        $keys = json_encode(['inline_keyboard' => [
            [
                ['text' => "💳 خرید از درگاه $gateType", 'callback_data' => 'wizwizch'],
            ],
            [
                ['text' => "🆔 $hash_id", 'callback_data' => "noop_$hash_id"]
            ]
        ]]);

        $user_info = Bot('getChat', ['chat_id' => $user_id])->result;
        $first_name = $user_info->first_name ?? '-';
        $username = $user_info->username ?? '-';

        $msg = "
💚 تمدید اشتراک (درگاه $gateType)

🔐 آیدی کاربر: $user_id
🛡️ نام کاربر: $first_name
🔖 نام کاربری تلگرام: @$username
💰 مبلغ پرداختی: $amount تومان
🎈 نام اشتراک: $remark
🔋 حجم اشتراک: $volume گیگ
⏰ مدت اشتراک: $days روز

";
		notifyAllAdminsSimple($msg, $admin, "html", $keys, $rewardChannel);
        exit;
    }

    elseif (preg_match('/^INCREASE_DAY_(\d+)_(\d+)/', $payType, $match)) {

        $orderId = (int)$match[1];

        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
        $stmt->bind_param("i", $orderId);
        $stmt->execute();
        $orderInfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $server_id  = (int)$orderInfo['server_id'];
        $inbound_id = (int)$orderInfo['inbound_id'];
        $remark     = $orderInfo['remark'];
        $uuid       = $orderInfo['uuid'] ?? "0";
        $planid     = (int)$match[2];

        $stmt = $connection->prepare("SELECT * FROM `increase_day` WHERE `id` = ?");
        $stmt->bind_param("i", $planid);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $plusDays = (int)$res['volume'];

        [$srvInfo, $srvConfig] = getServerInfoAndConfig($connection, $server_id);
        $serverType = $srvConfig['type'] ?? '';

        if ($serverType == "marzban") {
            $response = editMarzbanConfig($server_id, ['remark' => $remark, 'plus_day' => $plusDays]);
        } else {
            throw new \UnexpectedValueException('unsupported_panel');
        }

        if (!empty($response->success)) {

            $stmt = $connection->prepare("UPDATE `orders_list` SET `expire_date` = `expire_date` + ?, `notif` = 0 WHERE `uuid` = ?");
            $delta = $plusDays * 86400;
            $stmt->bind_param("is", $delta, $uuid);
            $stmt->execute();
            $stmt->close();

            $time2 = time();
            createIncreaseOrder($connection, $user_id, $server_id, $inbound_id, $remark, $amount, $time2, null, $plusDays);

            showForm("✅ پرداخت شما با موفقیت انجام شد. $plusDays روز به مدت زمان اشتراک شما اضافه شد.", $payDescription, true);
            sendMessage("✅ پرداخت شما با موفقیت انجام شد. $plusDays روز به مدت زمان اشتراک شما اضافه شد.", json_encode(['inline_keyboard' => [[['text' => "↪️ بازگشت به منوی اصلی", 'callback_data' => "mainMenu"]]]]), null, $user_id);

            $keys = json_encode(['inline_keyboard' => [
                [
                    ['text' => "💳 خرید از درگاه $gateType", 'callback_data' => 'wizwizch'],
                ],
                [
                    ['text' => "🆔 $hash_id", 'callback_data' => "noop_$hash_id"]
                ]
            ]]);

            $user_info = Bot('getChat', ['chat_id' => $user_id])->result;
            $first_name = $user_info->first_name ?? '-';
            $username = $user_info->username ?? '-';

            $msg = "
💜 افزایش زمان اشتراک (درگاه $gateType)

🔐 آیدی کاربر: $user_id
🛡️ نام کاربر: $first_name
🔖 نام کاربری تلگرام: @$username
💰 مبلغ پرداختی: $amount تومان
🎈 نام اشتراک: $remark
🕚 زمان اضافه شده: $plusDays روز
";
            notifyAllAdminsSimple($msg, $admin, "html", $keys, $rewardChannel);
			exit;

        } else {
            showForm("⚠️ پرداخت شما با موفقیت انجام شد ولی به دلیل مشکل فنی امکان افزایش حجم نیست. لطفا به مدیریت اطلاع بدید یا 5 دقیقه دیگر دوباره تست کنید مبلغ " . number_format($amount) . " تومان به کیف پول شما اضافه شد.", $payDescription, true);

            refundToWalletAndNotify(
                $connection,
                $user_id,
                $amount,
                $gateType,
                $admin,
                "میخواست زمان اشتراکشو افزایش بده",
				$rewardChannel
            );
            exit;
        }
    }

    elseif (preg_match('/^INCREASE_VOLUME_(\d+)_(\d+)/', $payType, $match)) {

        $orderId = (int)$match[1];

        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
        $stmt->bind_param("i", $orderId);
        $stmt->execute();
        $orderInfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $server_id  = (int)$orderInfo['server_id'];
        $inbound_id = (int)$orderInfo['inbound_id'];
        $remark     = $orderInfo['remark'];
        $uuid       = $orderInfo['uuid'] ?? "0";
        $planid     = (int)$match[2];

        $stmt = $connection->prepare("SELECT * FROM `increase_plan` WHERE `id` = ?");
        $stmt->bind_param("i", $planid);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $plusVolume = (int)$res['volume'];

        [$srvInfo, $srvConfig] = getServerInfoAndConfig($connection, $server_id);
        $serverType = $srvConfig['type'] ?? '';

        if ($serverType == "marzban") {
            $response = editMarzbanConfig($server_id, ['remark' => $remark, 'plus_volume' => $plusVolume]);
        } else {
            throw new \UnexpectedValueException('unsupported_panel');
        }

        if (!empty($response->success)) {

            $stmt = $connection->prepare("UPDATE `orders_list` SET `notif` = 0 WHERE `uuid` = ?");
            $stmt->bind_param("s", $uuid);
            $stmt->execute();
            $stmt->close();

            $time2 = time();
            createIncreaseOrder($connection, $user_id, $server_id, $inbound_id, $remark, $amount, $time2, $plusVolume, null);

            showForm("✅ پرداخت شما با موفقیت انجام شد. $plusVolume گیگ به حجم اشتراک شما اضافه شد.", $payDescription, true);
            sendMessage("✅ پرداخت شما با موفقیت انجام شد. $plusVolume گیگ به حجم اشتراک شما اضافه شد.", json_encode(['inline_keyboard' => [[['text' => "↪️ بازگشت به منوی اصلی", 'callback_data' => "mainMenu"]]]]), null, $user_id);

            $keys = json_encode(['inline_keyboard' => [
                [
                    ['text' => "💳 خرید از درگاه $gateType", 'callback_data' => 'wizwizch'],
                ],
                [
                    ['text' => "🆔 $hash_id", 'callback_data' => "noop_$hash_id"]
                ]
            ]]);

            $user_info = Bot('getChat', ['chat_id' => $user_id])->result;
            $first_name = $user_info->first_name ?? '-';
            $username = $user_info->username ?? '-';

            $msg = "
🤎 افزایش حجم اشتراک (درگاه $gateType)

🔐 آیدی کاربر: $user_id
🛡️ نام کاربر: $first_name
🔖 نام کاربری تلگرام: @$username
💰 مبلغ پرداختی: $amount تومان
🎈 نام اشتراک: $remark
🩸 حجم اضافه شده: $plusVolume گیگ
";
			notifyAllAdminsSimple($msg, $admin, "html", $keys, $rewardChannel);
            exit;

        } else {
            showForm("⚠️ پرداخت شما با موفقیت انجام شد ولی مشکل فنی در ارتباط با سرور، لطفا سلامت سرور را بررسی کنید مبلغ " . number_format($amount) . " تومان به کیف پول شما اضافه شد.", $payDescription, true);

            refundToWalletAndNotify(
                $connection,
                $user_id,
                $amount,
                $gateType,
                $admin,
                "میخواست حجم اشتراکشو افزایش بده",
				$rewardChannel
            );
            exit;
        }
    }
	
	elseif ($payType === "RENEW_SCONFIG") {

		$rewardChannel = $botState['rewardChannel'] ?? null;

		$fid = (int)$plan_id;
		$planDetails = getPlanDetails($connection, $fid);
		$plan   = $planDetails['plan'];
		$days   = $planDetails['days'];
		$volume = $planDetails['volume'];

		$server_id = (int)$plan['server_id'];

		$configInfo = json_decode($description, true) ?: [];
		$uuid      = $configInfo['uuid']   ?? '';
		$remark    = $configInfo['remark'] ?? '';
		$isMarzban = !empty($configInfo['marzban']);

		$inbound_id = (int)$volume;
		$inbound_id = (int)$payParam['volume'];

		try {
			[$serverInfo, $serverConfig] = getServerInfoAndConfig($connection, $server_id);

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
				$user_id,
				$server_id,
				$inbound_id,
				$remark,
				$amount,
				$time,
				$volume,
				$days
			);

			showForm("✅ پرداخت شما با موفقیت انجام شد. اشتراک $remark با موفقیت تمدید شد.", $payDescription, true);
			sendMessage("✅ پرداخت شما با موفقیت انجام شد. اشتراک $remark با موفقیت تمدید شد.",
				json_encode(['inline_keyboard' => [[['text' => "صفحه اصلی 🏘", 'callback_data' => "mainMenu"]]]]),
				null,
				$user_id
			);

			$adminText = "✅ تمدید اشتراک (درگاه $gateType)\n\n"
				. "UserID: $user_id\n"
				. "Remark: $remark\n"
				. "PlanID: $fid\n"
				. "Amount: " . number_format($amount) . "\n"
				. "🔋 حجم اشتراک: $volume گیگ\n"
				. "⏰ مدت اشتراک: $days روز\n"
				. "HashID: $hash_id";

			notifyAllAdminsSimple($adminText, $admin, null, null, $rewardChannel);

			return;

		} catch (Exception $e) {

			$reason = "پرداخت انجام شد ولی تمدید به دلیل خطای سرور انجام نشد.\n"
				. "لطفاً به مدیریت اطلاع دهید.\n"
				. "کد خطا: " . $e->getMessage();

			showForm("پرداخت شما با موفقیت انجام شد ولی مشکل فنی در تمدید.\nمبلغ " . number_format($amount) . " تومان به کیف پول شما اضافه شد.", $payDescription, true);

			refundToWalletAndNotify($connection, $user_id, $amount, $gateType, $reason, $admin, $rewardChannel);

			return;
		}
	}

    sendMessage("✅ پرداخت شما با موفقیت انجام شد.", json_encode(['inline_keyboard' => [[['text' => "↪️ بازگشت به منوی اصلی", 'callback_data' => "mainMenu"]]]]), null, $user_id);
}

function showForm($msg, $type = "", $state = false) {
?>
    <html dir="rtl">
    <head>
      <meta charset="utf-8"><meta name="viewport" content="width=device-width">
        <title><?php echo $type; ?></title>
        <meta name="next-head-count" content="4">
        <link rel="stylesheet" href="../assets/20bb620751bbea45.css">
        <noscript data-n-css=""></noscript>
    </head>
    <body style="margin: 0 auto;">
        <div id="__next">
            <section class="ant-layout ant-layout-rtl PayPing-layout background--primary justify-center" style="min-height:100vh">
                <main class="ant-layout-content justify-center align-center flex-column">
                    <div class="ant-row ant-row-center ant-row-rtl PayPing-row w-100">
                        <div class="ant-col PayPing-col PayPing-error-card ant-col-xs-23 ant-col-rtl ant-col-sm-20 ant-col-md-16 ant-col-lg-12 ant-col-xl-8 ant-col-xxl-6">
                            <div class="py-2 align-center color--danger flex-column">
                                <?php if (!$state) { ?>
                                    <svg fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" class="PayPing-icon" stroke-width="1" width="100">
                                        <circle cx="12" cy="12" r="11"></circle>
                                        <path d="M15.3 8.7l-6.6 6.6M8.7 8.7l6.6 6.6"></path>
                                    </svg>
                                <?php } ?>
                                <div class="py-2"><?php echo $msg; ?></div>
                            </div>
                        </div>
                    </div>
                </main>
            </section>
        </div>
    </body>
</html>
<?php
}
?>
