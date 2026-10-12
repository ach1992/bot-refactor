<?php

if (!function_exists('getPlanDetails')) {
    function getPlanDetails($connection, $planId)
    {
        $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
        $stmt->bind_param("i", $planId);
        $stmt->execute();
        $plan = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$plan) {
            alert('❌ پلن مورد نظر یافت نشد.');
            exit;
        }

        $days   = (int)$plan['days'];
        $vol    = (int)$plan['volume'];
        $price  = isset($plan['price']) ? (int)$plan['price'] : 0;
        $date   = time();
        $expire_microdate = floor(microtime(true) * 1000) + (864000 * $days * 100);
        $expire_date      = $date + (86400 * $days);

        return [
            'plan'             => $plan,
            'days'             => $days,
            'volume'           => $vol,
            'price'            => $price,
            'date'             => $date,
            'expire_microdate' => $expire_microdate,
            'expire_date'      => $expire_date,
        ];
    }
}

if (!function_exists('ensureServerCapacity')) {
    function ensureServerCapacity($connection, $server_id, $inbound_id, $acount, $mainValues)
    {
        if ((int)$acount === 0 && (int)$inbound_id !== 0) {
            alert($mainValues['out_of_connection_capacity']);
            exit;
        }

        $server_info = null;

        if ((int)$inbound_id === 0) {
            $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
            $stmt->bind_param("i", $server_id);
            $stmt->execute();
            $server_info = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$server_info || (int)$server_info['ucount'] <= 0) {
                alert($mainValues['out_of_server_capacity']);
                exit;
            }
        }
        return $server_info;
    }
}

if (!function_exists('getServerInfoAndConfig')) {
    function getServerInfoAndConfig($connection, $server_id)
    {
        $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $serverInfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id`=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $serverConfig = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$serverInfo || !$serverConfig) {
            alert('❌ اطلاعات سرور یا تنظیمات آن یافت نشد.');
            exit;
        }

        return [$serverInfo, $serverConfig];
    }
}

if (!function_exists('buildRemark')) {
    function buildRemark($botState, $srv_remark, $description, $userId, $forcedRemark = null)
    {
        if ($forcedRemark !== null && $forcedRemark !== '') {
            return $forcedRemark;
        }

        $remarkMode = $botState['remark'] ?? '';

        if ($remarkMode === "digits") {
            $rnd = rand(10000, 99999);
            return "{$srv_remark}-{$rnd}";
        } elseif ($remarkMode === "manual") {
            return $description;
        } else {
            $rnd = rand(1111, 99999);
            return "{$srv_remark}-{$userId}-{$rnd}";
        }
    }
}

if (!function_exists('getNextPortAndIncrease')) {
    function getNextPortAndIncrease($tempFilePath, $portType)
    {
        $port     = rand(1111, 65000);
        $last_num = 0;

        if (file_exists($tempFilePath)) {
            $savedinfo = file_get_contents($tempFilePath);
            $parts = explode('-', trim($savedinfo));

            if (count($parts) === 2) {
                $basePort = (int)$parts[0];
                $baseLast = (int)$parts[1];

                $port     = $basePort + 1;
                $last_num = $baseLast + 1;
            }
        }

        if ($portType === "auto") {
            @file_put_contents($tempFilePath, $port . '-' . $last_num);
        } else {
            $port = rand(1111, 65000);
        }

        return [$port, $last_num];
    }
}

if (!function_exists('createServerAccount')) {
    function createServerAccount(
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
    ) {
        // حالت بدون inbound (کاربر مستقل)
        if ((int)$inbound_id === 0) {
            if ($serverType === "marzban") {
                $response = addMarzbanUser($server_id, $remark, $volume, $days, $fid);

                if (is_object($response) && !$response->success && isset($response->msg) && $response->msg == "User already exists") {
                    $remark .= rand(1111, 99999);
                    $response = addMarzbanUser($server_id, $remark, $volume, $days, $fid);
                }
            } else {
                $response = addUser(
                    $server_id,
                    $uniqid,
                    $protocol,
                    $port,
                    $expire_microdate,
                    $remark,
                    $volume,
                    $netType,
                    'none',
                    $rahgozar,
                    $fid
                );

                if (is_object($response) && !$response->success && isset($response->msg)) {

                    if (strstr($response->msg, "Duplicate email")) {
                        $remark .= RandomString();
                    } elseif (strstr($response->msg, "Port already exists")) {
                        $port = rand(1111, 65000);
                    }

                    $response = addUser(
                        $server_id,
                        $uniqid,
                        $protocol,
                        $port,
                        $expire_microdate,
                        $remark,
                        $volume,
                        $netType,
                        'none',
                        $rahgozar,
                        $fid
                    );
                }
            }
        }
        // حالت با inbound
        else {
            $response = addInboundAccount(
                $server_id,
                $uniqid,
                $inbound_id,
                $expire_microdate,
                $remark,
                $volume,
                $limitip,
                null,
                $fid
            );

            if (is_object($response) && !$response->success && isset($response->msg) && strstr($response->msg, "Duplicate email")) {
                $remark .= RandomString();
                $response = addInboundAccount(
                    $server_id,
                    $uniqid,
                    $inbound_id,
                    $expire_microdate,
                    $remark,
                    $volume,
                    $limitip,
                    null,
                    $fid
                );
            }
        }

        return [$response, $remark, $port];
    }
}

if (!function_exists('buildLinksAndSendQr')) {
    function buildLinksAndSendQr(
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
        $qrPublicPrefix = '',
        $qrFsDir = null,
        $sendToUser = true
    ) {
        global $botUsername;

        // include qr lib
        $qrlibCandidates = [
            __DIR__ . '/phpqrcode/qrlib.php',
            __DIR__ . '/../phpqrcode/qrlib.php',
            'phpqrcode/qrlib.php',
            '../phpqrcode/qrlib.php',
        ];
        $qrlibIncluded = false;
        foreach ($qrlibCandidates as $p) {
            if (file_exists($p)) {
                include_once $p;
                $qrlibIncluded = true;
                break;
            }
        }
        if (!$qrlibIncluded && !class_exists('QRcode')) {
            @include_once 'phpqrcode/qrlib.php';
        }

        $panelUrl    = $serverConfig['panel_url']  ?? '';
        $subLinksRaw = $serverConfig['sub_links'] ?? '';

        if ($serverType === "marzban") {

            // sub_link مثال: /sub/xxxxx
            $uniqid = $token = str_replace("/sub/", "", $response->sub_link ?? '');

            $mainSubLink  = "";
            $subLinkArray = [];

            if (($botState['subLinkState'] ?? '') === "on") {
                if (!empty($panelUrl) && !empty($response->sub_link)) {
                    $mainSubLink = $panelUrl . $response->sub_link;
                }
                if (!empty($subLinksRaw) && !empty($response->sub_link)) {
                    $subDomains = array_filter(array_map('trim', explode(',', $subLinksRaw)));
                    foreach ($subDomains as $sub) {
                        $subLinkArray[] = $sub . $response->sub_link;
                    }
                }
            }

            if (isset($response->vray_links) && !empty($response->vray_links)) {
                $vraylink = $response->vray_links;
            } elseif (!empty($mainSubLink)) {
                $vraylink = [$mainSubLink];
            } else {
                $vraylink = [];
            }

            $vray_link = json_encode($response->vray_links ?? []);
        } else {

            $token = RandomString(30);

            $mainSubLink = (($botState['subLinkState'] ?? '') === "on")
                ? $botUrl . "settings/subLink.php?token=" . $token
                : "";

            $subLinkArray = [];

            $vraylink = getConnectionLink(
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
                $customSni
            );

            $vray_link = json_encode($vraylink);
        }

        if (empty($vraylink)) {
            if (!empty($mainSubLink)) {
                $vraylink = [$mainSubLink];
            } else {
                $vraylink = [''];
            }
        }

        $linkCount   = count($vraylink);
        $targetIndex = 1;
        $link        = $vraylink[$targetIndex] ?? $vraylink[0];

        $qrLink = ($linkCount <= 3 && !empty($link)) ? $link : $mainSubLink;
        if (empty($qrLink)) $qrLink = $vraylink[0];

        $selectedLinks = [];
        foreach ($linkIndices as $index) {
            $selectedLinks[] = $vraylink[$index] ?? $vraylink[0];
        }
        if (empty($selectedLinks)) $selectedLinks[] = $vraylink[0];

        $acc_text = "
🛍 <b>اطلاعات اشتراک جدید شما</b>

🎈 نام کاربری اشتراک:
<code>$remark</code>
🔋 حجم اشتراک: $volume گیگ
⏰ مدت اشتراک: $days روز\n
";

        if (
            (($botState['configLinkState'] ?? '') !== "off") &&
            !empty($link) &&
            (!$hideConfigIfMany || $linkCount <= 10)
        ) {
            $acc_text .= "🔌 لینک تکی کانفیگ:\n";
            foreach ($selectedLinks as $singleLink) {
                if (!empty($singleLink)) {
                    $acc_text .= "<code>$singleLink</code>\n\n";
                }
            }
        }

        if (!empty($mainSubLink)) {
            $acc_text .= "🌐 لینک اصلی اشتراک:
<code>$mainSubLink</code>";
        }

        if (!empty($subLinkArray)) {
            $acc_text .= "\n\n🌐 لینک کمکی اشتراک:\n";
            $acc_text .= "<code>" . implode("</code>\n\n<code>", $subLinkArray) . "</code>";
        }

        $acc_text .= "

💡 همیشه اکانت را با لینک اشتراک وارد کنید تا به همه کانفیگ ها دسترسی داشته باشد.
⚠️ در صورتی که لینک اصلی اشتراک کار نکرد از لینک کمکی اشتراک استفاده کنید.";

        $btnText = $buttonValues['start_bot'] ?? ($buttonValues['my_subscriptions'] ?? 'Start');
        $u = (string)($botUsername ?? '');

        if ($u !== '') {
            $keys = json_encode([
                'inline_keyboard' => [
                    [[
                        'text' => $btnText,
                        'url'  => "https://t.me/{$u}?start=mainMenu"
                    ]]
                ]
            ], JSON_UNESCAPED_UNICODE);
        } else {
            $keys = json_encode([
                'inline_keyboard' => [
                    [[
                        'text' => $btnText,
                        'callback_data' => "mySubscriptions"
                    ]]
                ]
            ], JSON_UNESCAPED_UNICODE);
        }

        if (!$sendToUser) {
            return [$token, $vray_link, $uniqid, $acc_text, $keys, $qrLink];
        }

        if (empty($qrFsDir)) $qrFsDir = getcwd();
        $qrFsDir = rtrim($qrFsDir, "/\\");
        $file = RandomString() . ".png";
        $fsPath = $qrFsDir . DIRECTORY_SEPARATOR . $file;

        $ecc        = 'L';
        $pixel_Size = 11;
        $frame_Size = 4;

        if (!class_exists('QRcode')) {
            sendMessage($acc_text, $keys, "HTML", $uid);
            return [$token, $vray_link, $uniqid];
        }

        QRcode::png($qrLink, $fsPath, $ecc, $pixel_Size, $frame_Size);

        $photo = class_exists('CURLFile')
            ? new CURLFile($fsPath, 'image/png', $file)
            : $fsPath;

        $resPhoto = sendPhoto($photo, null, null, null, $uid);

        if ($resPhoto && isset($resPhoto->ok) && $resPhoto->ok === true) {
            @unlink($fsPath);
        }
		
        sendMessage($acc_text, $keys, "HTML", $uid);
        return [$token, $vray_link, $uniqid];
    }
}

if (!function_exists('sendQrFromLink')) {
    function sendQrFromLink(
        string $qrLink,
        string $acc_text,
        string $keys,
        string $botUrl,
        int $uid,
        string $qrPublicPrefix = '',
        ?string $qrFsDir = null
    ): bool {
        $qrlibCandidates = [
            __DIR__ . '/phpqrcode/qrlib.php',
            __DIR__ . '/../phpqrcode/qrlib.php',
            'phpqrcode/qrlib.php',
            '../phpqrcode/qrlib.php',
        ];
        $qrlibIncluded = false;
        foreach ($qrlibCandidates as $p) {
            if (file_exists($p)) {
                include_once $p;
                $qrlibIncluded = true;
                break;
            }
        }
        if (!$qrlibIncluded && !class_exists('QRcode')) {
            @include_once 'phpqrcode/qrlib.php';
        }
        if (!class_exists('QRcode')) {
            sendMessage($acc_text, $keys, "HTML", $uid);
            return false;
        }

        if (empty($qrFsDir)) $qrFsDir = getcwd();
        $qrFsDir = rtrim($qrFsDir, "/\\");
        $file = RandomString() . ".png";
        $fsPath = $qrFsDir . DIRECTORY_SEPARATOR . $file;

        $ecc        = 'L';
        $pixel_Size = 11;
        $frame_Size = 4;

        QRcode::png($qrLink, $fsPath, $ecc, $pixel_Size, $frame_Size);

        $photo = class_exists('CURLFile')
            ? new CURLFile($fsPath, 'image/png', $file)
            : $fsPath;

        $resPhoto = sendPhoto($photo, null, null, null, $uid);

        $ok = ($resPhoto && isset($resPhoto->ok) && $resPhoto->ok === true);
        @unlink($fsPath);
        sendMessage($acc_text, $keys, "HTML", $uid);

        return $ok;
    }
}

if (!function_exists('insertOrder')) {
    function insertOrder(
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
        $amount,
        $date,
        $rahgozar,
        $agent_bought
    ) {
        $stmt = $connection->prepare("INSERT INTO `orders_list`  
            (`userid`, `token`, `transid`, `fileid`, `server_id`, `inbound_id`, `remark`, `uuid`, `protocol`, `expire_date`, `link`, `amount`, `status`, `date`, `notif`, `rahgozar`, `agent_bought`) 
            VALUES (?, ?, '', ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, 0, ?, ?);");
        $stmt->bind_param(
            "ssiiisssisiiii",
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
            $amount,
            $date,
            $rahgozar,
            $agent_bought
        );
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('decreaseServerCapacity')) {
    function decreaseServerCapacity($connection, $server_id, $inbound_id, $plan_id, $count = 1)
    {
        $count      = (int)$count;
        $server_id  = (int)$server_id;
        $inbound_id = (int)$inbound_id;
        $plan_id    = (int)$plan_id;

        if ($count <= 0) {
            return;
        }

        if ($inbound_id === 0) {
            $stmt = $connection->prepare(
                "UPDATE `server_info` SET `ucount` = `ucount` - ? WHERE `id` = ?"
            );
            $stmt->bind_param("ii", $count, $server_id);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $connection->prepare(
                "UPDATE `server_plans` SET `acount` = `acount` - ? WHERE `id` = ?"
            );
            $stmt->bind_param("ii", $count, $plan_id);
            $stmt->execute();
            $stmt->close();
        }
    }
}

if (!function_exists('ensureAccountCreationSucceeded')) {
    function ensureAccountCreationSucceeded($response, $serverInfo, $inbound_id, $admin, $throwOnError = false)
    {
        $hasError = false;
        $errorCode = '';
        $userMessage = '';
        $adminMessage = '';

        if (is_null($response)) {
            $hasError    = true;
            $errorCode   = 'server_connection_null';
            $userMessage = '❌ اتصال به سرور برقرار نیست. لطفا پشتیبانی را در جریان بگذارید.';

        } elseif ($response === "inbound not Found") {
            $hasError    = true;
            $errorCode   = 'inbound_not_found';
            $userMessage = "❌ سطر (inbound) با آیدی $inbound_id تو این سرور وجود ندارد، لطفا پشتیبانی را در جریان بگذارید.";

        } else {
            $success = false;
            $msg     = '';

            if (is_object($response)) {
                $success = !empty($response->success);
                $msg     = $response->msg ?? '';
            } elseif (is_array($response)) {
                $success = !empty($response['success']);
                $msg     = $response['msg'] ?? '';
            }

            if (!$success) {
                $hasError    = true;
                $errorCode   = 'server_response_failed';
                $userMessage = '❌ خطایی در ساخت/بروزرسانی اکانت رخ داد، لطفا پشتیبانی را در جریان بگذارید.';

                $title = $serverInfo['title'] ?? 'نامشخص';
                $adminMessage = "خطای سرور {$title}:\n\n" . ($msg ?: 'بدون پیام خطا');
            }
        }

        if (!$hasError) {
            return;
        }

        if ($throwOnError) {
            $fullMessage = $errorCode . ($adminMessage ? (' | ' . $adminMessage) : '');
            throw new Exception($fullMessage ?: $errorCode);
        }

        if (!empty($userMessage)) {
            alert($userMessage);
        }

        if (!empty($adminMessage) && !empty($admin)) {
            sendMessage($adminMessage, null, null, $admin);
            // sendToAllAdmins(...)
        }

        exit;
    }
}

if (!function_exists('giveReferralReward')) {
    function giveReferralReward($connection, $buyerUserId, $inviterId, $admin, $botState, $paymentMethod = null)
    {
        if ($paymentMethod !== null && strtolower((string)$paymentMethod) === 'wallet') {
            return;
        }

        $stmt = $connection->prepare("SELECT `value` FROM `setting` WHERE `type` = 'INVITE_BANNER_AMOUNT'");
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $inviteAmount = isset($row['value']) ? (int)$row['value'] : 0;
        if ($inviteAmount <= 0) {
            return;
        }

        $stmt = $connection->prepare("SELECT `wallet` FROM `users` WHERE `userid` = ?");
        $stmt->bind_param("i", $inviterId);
        $stmt->execute();
        $userRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $currentWallet = isset($userRow['wallet']) ? (int)$userRow['wallet'] : 0;
        $newWallet = $currentWallet + $inviteAmount;

        $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` + ? WHERE `userid` = ?");
        $stmt->bind_param("ii", $inviteAmount, $inviterId);
        $stmt->execute();
        $stmt->close();

        $userMessage = "🎁 تبریک! یکی از زیرمجموعه‌های شما خرید انجام داد. شما مبلغ " . number_format($inviteAmount) . " تومان جایزه دریافت کردید.\n\n"
            . "💰 موجودی قدیم: " . number_format($currentWallet) . " تومان\n"
            . "💵 موجودی جدید: " . number_format($newWallet) . " تومان";

        sendMessage($userMessage, null, null, $inviterId);

        $channelMessage = "👥 کاربر $buyerUserId زیرمجموعه کاربر $inviterId یک خرید انجام داد.\n\n"
            . "💰 موجودی قدیم: " . number_format($currentWallet) . " تومان\n"
            . "💵 موجودی جدید: " . number_format($newWallet) . " تومان";

        sendMessage($channelMessage, null, "html", $admin);

        $rewardChannel = $botState['rewardChannel'] ?? null;
        if (!empty($rewardChannel)) {
            sendMessage($channelMessage, null, "html", $rewardChannel);
        }
    }
}

if (!function_exists('renewConfigOnServer')) {
    function renewConfigOnServer($connection, $server_id, $inbound_id, $uuid, $remark, $days, $volume, $isMarzban)
    {
        list($serverInfo, $serverConfig) = getServerInfoAndConfig($connection, $server_id);
        $serverType = $serverConfig['type'] ?? '';

        if ($isMarzban || $serverType === 'marzban') {
            $response = editMarzbanConfig($server_id, [
                'remark' => $remark,
                'days'   => $days,
                'volume' => $volume,
            ]);
        } else {
            if ((int)$inbound_id > 0) {
                $response = editClientTraffic($server_id, $inbound_id, $uuid, $volume, $days, "renew");
            } else {
                $response = editInboundTraffic($server_id, $uuid, $volume, $days, "renew");
            }
        }

        return $response;
    }
}

if (!function_exists('processWalletPayment')) {
    function processWalletPayment($connection, $hashId, $handler)
    {
        global $from_id, $admin;

        $connection->begin_transaction();

        try {
            $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? FOR UPDATE");
            $stmt->bind_param("s", $hashId);
            $stmt->execute();
            $payRes = $stmt->get_result();
            $stmt->close();

            if ($payRes->num_rows == 0) {
                $connection->rollback();
                return [
                    'status'       => 'not_found',
                    'payInfo'      => null,
                    'userRow'      => null,
                    'userReferrer' => null,
                    'uid'          => null,
                    'price'        => null,
                ];
            }

            $payInfo = $payRes->fetch_assoc();

            if ($payInfo['state'] === 'paid_with_wallet') {
                $connection->rollback();
                return [
                    'status'       => 'already_paid',
                    'payInfo'      => $payInfo,
                    'userRow'      => null,
                    'userReferrer' => null,
                    'uid'          => null,
                    'price'        => null,
                ];
            }

            if ($payInfo['state'] === 'processing_wallet') {
                $connection->rollback();
                alert("⏳ درخواست شما در حال پردازش است. کمی بعد دوباره تلاش کنید.", true);
                return [
                    'status'       => 'in_progress',
                    'payInfo'      => $payInfo,
                    'userRow'      => null,
                    'userReferrer' => null,
                    'uid'          => null,
                    'price'        => null,
                ];
            }

            $blockedStates = ['approved', 'declined', 'expired_have_sent'];
			if (in_array($payInfo['state'], $blockedStates, true)) {
				$connection->rollback();
				alert("⛔️ این پرداخت قابل پرداخت با کیف پول نیست: " . $payInfo['state'], true);
				return [
					'status'       => 'invalid_state',
					'payInfo'      => $payInfo,
					'userRow'      => null,
					'userReferrer' => null,
					'uid'          => null,
					'price'        => null,
				];
			}

            $uid   = (int)($payInfo['user_id'] ?: $from_id);
            $price = (int)$payInfo['price'];

            $stmt = $connection->prepare("SELECT `wallet`, `refered_by` FROM `users` WHERE `userid` = ? FOR UPDATE");
            $stmt->bind_param("i", $uid);
            $stmt->execute();
            $userRow = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $userWallet   = isset($userRow['wallet']) ? (int)$userRow['wallet'] : 0;
            $userReferrer = $userRow['refered_by'] ?? null;

            if ($userWallet < $price) {
                $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'low_wallet' WHERE `hash_id` = ? LIMIT 1");
                $stmt->bind_param("s", $hashId);
                $stmt->execute();
                $stmt->close();

                $connection->commit();

                alert(
                    "❌ موجودی کیف پول شما کافی نیست.\n" .
                    "💵 موجودی فعلی: " . number_format($userWallet) . " تومان\n" .
                    "🔺 مورد نیاز: " . number_format($price - $userWallet) . " تومان",
                    true
                );

                return [
                    'status'       => 'low_wallet',
                    'payInfo'      => $payInfo,
                    'userRow'      => $userRow,
                    'userReferrer' => $userReferrer,
                    'uid'          => $uid,
                    'price'        => $price,
                ];
            }

            $stmt = $connection->prepare("UPDATE `pays` SET `state`='processing_wallet' WHERE `hash_id`=? LIMIT 1");
            $stmt->bind_param("s", $hashId);
            $stmt->execute();
            $stmt->close();

            $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` - ? WHERE `userid` = ? LIMIT 1");
            $stmt->bind_param("ii", $price, $uid);
            $stmt->execute();
            $stmt->close();

            $businessOk = call_user_func($handler, $payInfo, $userRow);

            if (!$businessOk) {
                $connection->rollback();
                return [
                    'status'       => 'business_failed',
                    'payInfo'      => $payInfo,
                    'userRow'      => $userRow,
                    'userReferrer' => $userReferrer,
                    'uid'          => $uid,
                    'price'        => $price,
                ];
            }

            $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'paid_with_wallet' WHERE `hash_id` = ? LIMIT 1");
            $stmt->bind_param("s", $hashId);
            $stmt->execute();
            $stmt->close();

            $connection->commit();

            $payInfo['state'] = 'paid_with_wallet';

            return [
                'status'       => 'ok',
                'payInfo'      => $payInfo,
                'userRow'      => $userRow,
                'userReferrer' => $userReferrer,
                'uid'          => $uid,
                'price'        => $price,
            ];

        } catch (Exception $e) {
            $connection->rollback();

            $adminErr = "⚠️ خطا در پرداخت با کیف پول\n\n"
                . "🆔 Hash ID: {$hashId}\n"
                . "👤 User: {$from_id}\n"
                . "📄 Error: " . $e->getMessage();
            sendMessage($adminErr, null, null, $admin);

            alert("❌ مشکلی پیش اومد.\n🔁 لطفاً کمی بعد دوباره تلاش کنید.\nاگر مشکل ادامه داشت، به مدیریت اطلاع دهید.", true);

            return [
                'status'       => 'error',
                'payInfo'      => null,
                'userRow'      => null,
                'userReferrer' => null,
                'uid'          => null,
                'price'        => null,
            ];
        }
    }
}

if (!function_exists('calculateAgentPrice')) {
    function calculateAgentPrice($userInfo, $botState, $planId, $serverId, $buyType, $basePrice)
    {
        $price           = (int)$basePrice;
        $agentBought     = false;
        $discountPercent = 0;

        if ($buyType === 'one' || $buyType === 'much') {
            $agentBought = true;
            $discountsRaw = $userInfo['discount_percent'] ?? '{}';
            $discounts    = json_decode($discountsRaw, true);
            if (!is_array($discounts)) {
                $discounts = [];
            }

            $normal = isset($discounts['normal']) ? (int)$discounts['normal'] : 0;

            if (($botState['agencyPlanDiscount'] ?? '') === "on") {
                if (isset($discounts['plans'][$planId])) {
                    $discountPercent = (int)$discounts['plans'][$planId];
                } else {
                    $discountPercent = $normal;
                }
            } else {
                if (isset($discounts['servers'][$serverId])) {
                    $discountPercent = (int)$discounts['servers'][$serverId];
                } else {
                    $discountPercent = $normal;
                }
            }

            if ($discountPercent > 0) {
                $price -= floor($price * $discountPercent / 100);
            }
        }

        return [$price, $agentBought, $discountPercent];
    }
}

if (!function_exists('createIncreaseOrder')) {
    function createIncreaseOrder(
        $connection,
        $uid,
        $server_id,
        $inbound_id,
        $remark,
        $amount,
        $date,
        $volume = null,
        $day = null
    ) {
        $stmt = $connection->prepare("
            INSERT INTO `increase_order`
                (`userid`, `server_id`, `inbound_id`, `remark`, `amount`, `date`, `volume`, `day`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            "iiisiiii",
            $uid,
            $server_id,
            $inbound_id,
            $remark,
            $amount,
            $date,
            $volume,
            $day
        );
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('payTypeToDescription')) {
    function payTypeToDescription($payType)
    {
        require_once __DIR__ . '/src/Presentation/Labels.php';
        return \AchBot\Presentation\Labels\paymentType($payType);
    }
}

if (!function_exists('addToWallet')) {
    function addToWallet($connection, $userId, $amount)
    {
        $amount = (int)$amount;
        $userId = (int)$userId;

        $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` + ? WHERE `userid` = ?");
        $stmt->bind_param("ii", $amount, $userId);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('notifyAllAdminsSimple')) {
    function notifyAllAdminsSimple($text, $admin, $parseMode = null, $replyMarkup = null, $rewardChannel = null)
    {
        if ($rewardChannel === null) {
            global $botState;
            $rewardChannel = $botState['rewardChannel'] ?? null;
        }

        if (!empty($admin)) {
            sendMessage($text, $replyMarkup, $parseMode, $admin);
        }

        // sendToAllAdmins('sendMessage', ['text' => $text, 'reply_markup' => $replyMarkup, 'parse_mode' => $parseMode]);

        if (!empty($rewardChannel)) {
            sendMessage($text, $replyMarkup, $parseMode, $rewardChannel);
        }
    }
}

if (!function_exists('refundToWalletAndNotify')) {
    function refundToWalletAndNotify(
        $connection,
        $userId,
        $amount,
        $gateType,
        $admin,
        $adminTextSuffix,
        $rewardChannel = null,
        $userReasonText = null
    ) {
        addToWallet($connection, $userId, $amount);

        $userMsg = "✅ مبلغ " . number_format($amount) . " تومان به حساب شما اضافه شد.";
        if (!empty($userReasonText)) {
            $userMsg .= "\n\n" . $userReasonText;
        }
        sendMessage($userMsg, null, null, $userId);

        $adminText = "✅ مبلغ " . number_format($amount) . " تومان به کیف پول کاربر $userId توسط درگاه $gateType اضافه شد. " . $adminTextSuffix;

        if ($rewardChannel === null) {
            global $botState;
            $rewardChannel = $botState['rewardChannel'] ?? null;
        }

        notifyAllAdminsSimple($adminText, $admin, null, null, $rewardChannel);
    }
}

function dbFetchPayByHash(mysqli $connection, string $hashId): ?array {
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? LIMIT 1");
    $stmt->bind_param("s", $hashId);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    if (!$res || $res->num_rows == 0) return null;
    return $res->fetch_assoc();
}

function prepareStartFallback() {
    global $text, $data;
    setUser("none");
    setUser("", "temp");
    $text = "/start";
    $data = "";
}

function inlineKeyboard(array $rows): string {
    require_once __DIR__ . '/src/Telegram/Markup.php';
    return \AchBot\Telegram\Markup\inlineKeyboard($rows);
}

function generateHigherRandomizedAmount(int $amountToman): array {
    $amountToman = max(1, $amountToman);

    do {
        $lastThree = rand(100, 999);
    } while ($lastThree % 10 == 0 || $lastThree % 100 == 0);

    $randomizedToman = $amountToman + $lastThree;
    $randomizedRial = $randomizedToman * 10;

    return [
        'toman' => $randomizedToman,
        'rial'  => $randomizedRial,
    ];
}

function fetchCartToCartUserEligibility(mysqli $connection, int $userId, int $cutoffTs): array {
    $default = [
        'exists' => false,
        'join_date' => 0,
        'is_agent' => 0,
        'approved_total' => 0,
        'approved_before_cutoff' => 0,
    ];

    if ($userId <= 0) {
        return $default;
    }

    $stmt = $connection->prepare("SELECT `date`, `is_agent` FROM `users` WHERE `userid` = ? LIMIT 1");
    if (!$stmt) {
        return $default;
    }

    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $userRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$userRow) {
        return $default;
    }

    $stats = [
        'exists' => true,
        'join_date' => (int)($userRow['date'] ?? 0),
        'is_agent' => (int)($userRow['is_agent'] ?? 0),
        'approved_total' => 0,
        'approved_before_cutoff' => 0,
    ];

    $stmt = $connection->prepare(
        "SELECT
            COUNT(*) AS approved_total,
            COALESCE(SUM(CASE WHEN `request_date` < ? THEN 1 ELSE 0 END), 0) AS approved_before_cutoff
         FROM `pays`
         WHERE `user_id` = ?
           AND `type` IN ('BUY_SUB', 'RENEW_ACCOUNT')
           AND `state` = 'approved'"
    );

    if (!$stmt) {
        return $stats;
    }

    $stmt->bind_param("ii", $cutoffTs, $userId);
    $stmt->execute();
    $payStats = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $stats['approved_total'] = (int)($payStats['approved_total'] ?? 0);
    $stats['approved_before_cutoff'] = (int)($payStats['approved_before_cutoff'] ?? 0);

    return $stats;
}

function resolveCartToCartMessageType(int $priceToman, int $userId = 0): string {
    global $connection;

    $detailMinPrice = 50000;
    $usdtMinPrice = 12000000;
    $cutoffTs = strtotime('2026-03-01 00:00:00');
    if ($cutoffTs === false) {
        $cutoffTs = 1772323200;
    }

    if ($priceToman >= $usdtMinPrice) {
        return 'usdt';
    }

    if ($priceToman < $detailMinPrice || $userId <= 0 || !($connection instanceof mysqli)) {
        return 'gift';
    }

    $stats = fetchCartToCartUserEligibility($connection, $userId, (int)$cutoffTs);
    $joinDate = (int)$stats['join_date'];
    $joinedBeforeCutoff = ($joinDate > 0 && $joinDate < (int)$cutoffTs);
    $joinedAtLeastTenDaysAgo = ($joinDate > 0 && $joinDate <= (time() - (10 * 86400)));

    if ((int)$stats['is_agent'] === 1) {
        return 'detail';
    }

    if ($joinedBeforeCutoff && (int)$stats['approved_before_cutoff'] >= 1) {
        return 'detail';
    }

    if ($joinedBeforeCutoff && (int)$stats['approved_total'] >= 2) {
        return 'detail';
    }

    if ($joinedAtLeastTenDaysAgo && (int)$stats['approved_total'] >= 3) {
        return 'detail';
    }

    return 'gift';
}

function getCartToCartMessageKey(string $messageType): string {
    require_once __DIR__ . '/src/Presentation/Labels.php';
    return \AchBot\Presentation\Labels\cardToCardMessageKey($messageType);
}

function buildCartToCartInfoMessage(array $paymentKeys, array $mainValues, int $priceToman, int $userId = 0): string {
    global $connection, $USDTPrice, $USDTPriceagent;

    $messageType = resolveCartToCartMessageType($priceToman, $userId);
    $GLOBALS['lastCartToCartMessageType'] = $messageType;

    $randomPrice = generateHigherRandomizedAmount($priceToman);
    $finalTomanAmount = (float)$randomPrice['toman'];

    $isAgent = 0;
    if ($userId > 0 && ($connection instanceof mysqli)) {
        $stmt = $connection->prepare("SELECT `is_agent` FROM `users` WHERE `userid` = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $userRow = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $isAgent = (int)($userRow['is_agent'] ?? 0);
        }
    }

    if ($isAgent === 1 && isset($USDTPriceagent) && trim($USDTPriceagent) !== '' && is_numeric($USDTPriceagent)) {
        $usdtPrice = (float)$USDTPriceagent;
    } elseif (isset($USDTPrice) && trim($USDTPrice) !== '' && is_numeric($USDTPrice)) {
        $usdtPrice = (float)$USDTPrice;
    } else {
        $usdtPrice = 0;
    }

    $usdtAmount = $usdtPrice > 0 ? round($finalTomanAmount / $usdtPrice, 2) : 0;
    $messageKey = getCartToCartMessageKey($messageType);
    $messageTemplate = $mainValues[$messageKey] ?? '';
    if ($messageTemplate === '' && $messageType === 'detail') {
        $messageTemplate = $mainValues['brw_cart_to_cart_detail'] ?? '';
    }

    return strtr($messageTemplate, [
        "PRICE-TOMAN-ROUND" => number_format($priceToman),
        "ACCOUNT-NUMBER" => $paymentKeys['bankAccount'] ?? '---',
        "HOLDER-NAME" => $paymentKeys['holderName'] ?? '---',
        "PRICE-TOMAN" => number_format($randomPrice['toman']),
        "PRICE-RIAL" => number_format($randomPrice['rial']),
        "PRICE-USDT" => number_format($usdtAmount, 2, '.', ''),
        "USDT-WALLET" => $paymentKeys['tronwallet'] ?? '---',
    ]);
}

function kbSendReceiptAndCancel(string $sendPrefix, string $cancelPrefix, string $hashId): string {
    require_once __DIR__ . '/src/Telegram/Markup.php';
    return \AchBot\Telegram\Markup\receiptAndCancel($sendPrefix, $cancelPrefix, $hashId, $GLOBALS['lastCartToCartMessageType'] ?? 'detail');
}

function kbOnlyCancel(string $cancelPrefix, string $hashId): string {
    require_once __DIR__ . '/src/Telegram/Markup.php';
    return \AchBot\Telegram\Markup\onlyCancel($cancelPrefix, $hashId);
}

function handleCancelFlow(array $mainValues, $removeKeyboard) {
    delMessage();
    setUser();
    sendMessage($mainValues['operation_canceled'], $removeKeyboard, "HTML");
    sendMessage($mainValues['reached_main_menu'], getMainKeys());
    exit;
}

function extractPhotoFileId($update): ?string {
    require_once __DIR__ . '/src/Telegram/Response.php';
    return \AchBot\Telegram\Response\photoFileId($update);
}

function duplicateReceiptGuard(mysqli $connection, array $payInfo, int $nowTs): ?string {
    $userId   = (int)$payInfo['user_id'];
    $type     = (string)$payInfo['type'];
    $planId   = (int)($payInfo['plan_id'] ?? 0);
    $serverId = (int)($payInfo['server_id'] ?? 0);
    $orderId  = (int)($payInfo['order_id'] ?? 0);
    $price    = (int)($payInfo['price'] ?? 0);
    $hashId   = (string)$payInfo['hash_id'];

    if (in_array($payInfo['state'], ['have_sent', 'approved'], true)) {
        return "⛔️ شما درخواست مشابه‌ای قبلا ثبت کرده‌اید.\n⚠️ لطفاً منتظر بررسی پشتیبانی بمانید.";
    }

    $isSpecial = ($type === 'BUY_SUB' || $type === 'INCREASE_WALLET');

    $windowApproved = $isSpecial ? 600  : 3600;
    $windowHaveSent = $isSpecial ? 21600 : 21600;
    $windowDeclined = 180;

    $lookback = max($windowApproved, $windowHaveSent, $windowDeclined);
    $minTs = $nowTs - $lookback;

    if ($type === 'INCREASE_WALLET') {
        $sql = "
            SELECT `state`, `request_date`
            FROM `pays`
            WHERE `user_id` = ?
              AND `hash_id` <> ?
              AND `type` = ?
              AND IFNULL(`price`,0) = ?
              AND `request_date` >= ?
            ORDER BY `request_date` DESC
            LIMIT 200
        ";
        $stmt = $connection->prepare($sql);
        $stmt->bind_param("issii", $userId, $hashId, $type, $price, $minTs);

    } elseif ($type === 'BUY_SUB') {
        $sql = "
            SELECT `state`, `request_date`
            FROM `pays`
            WHERE `user_id` = ?
              AND `hash_id` <> ?
              AND `type` = ?
              AND IFNULL(`plan_id`,0) = ?
              AND IFNULL(`server_id`,0) = ?
              AND IFNULL(`price`,0) = ?
              AND `request_date` >= ?
            ORDER BY `request_date` DESC
            LIMIT 200
        ";
        $stmt = $connection->prepare($sql);
        $stmt->bind_param("issiiii", $userId, $hashId, $type, $planId, $serverId, $price, $minTs);

    } else {
        $sql = "
            SELECT `state`, `request_date`
            FROM `pays`
            WHERE `user_id` = ?
              AND `hash_id` <> ?
              AND `type` = ?
              AND IFNULL(`plan_id`,0) = ?
              AND IFNULL(`server_id`,0) = ?
              AND IFNULL(`order_id`,0) = ?
              AND IFNULL(`price`,0) = ?
              AND `request_date` >= ?
            ORDER BY `request_date` DESC
            LIMIT 200
        ";
        $stmt = $connection->prepare($sql);
        $stmt->bind_param("issiiiii", $userId, $hashId, $type, $planId, $serverId, $orderId, $price, $minTs);
    }

    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    if (!$res || $res->num_rows == 0) return null;

    $latestHaveSentDiff = null;
    $latestApprovedDiff = null;
    $latestDeclinedDiff = null;

    while ($row = $res->fetch_assoc()) {
        $st = (string)$row['state'];
        $ts = (int)$row['request_date'];
        $diff = $nowTs - $ts;

        if ($st === 'have_sent' && $latestHaveSentDiff === null) $latestHaveSentDiff = $diff;
        if ($st === 'approved'  && $latestApprovedDiff === null) $latestApprovedDiff = $diff;
        if ($st === 'declined'  && $latestDeclinedDiff === null) $latestDeclinedDiff = $diff;

        if ($latestHaveSentDiff !== null && $latestApprovedDiff !== null && $latestDeclinedDiff !== null) break;
    }

    if ($latestHaveSentDiff !== null && $latestHaveSentDiff < $windowHaveSent) {
        $remain = $windowHaveSent - $latestHaveSentDiff;
        $mins = (int)ceil($remain / 60);
        return "⛔️ یک درخواست مشابه شما در حال بررسی است.\n⚠️ لطفاً حدود {$mins} دقیقه دیگر دوباره تلاش کنید.";
    }

			   
    if ($latestApprovedDiff !== null && $latestApprovedDiff < $windowApproved) {
        $remain = $windowApproved - $latestApprovedDiff;
        $mins = (int)ceil($remain / 60);
        return "⛔️ شما اخیراً یک درخواست مشابه با وضعیت تایید شده داشته‌اید.\n⚠️ لطفاً حدود {$mins} دقیقه دیگر دوباره تلاش کنید.";
    }

			   
    if ($latestDeclinedDiff !== null && $latestDeclinedDiff < $windowDeclined) {
        $remain = $windowDeclined - $latestDeclinedDiff;
        return "⛔️ درخواست مشابه قبلی شما اخیراً رد شده است.\n⚠️ لطفاً {$remain} ثانیه دیگر دوباره تلاش کنید.";
    }

    return null;
}

function countUserPaysByState(mysqli $connection, int $userId, string $state): int {
    $stmt = $connection->prepare("SELECT COUNT(*) AS c FROM `pays` WHERE `user_id`=? AND `state`=?");
    $stmt->bind_param("is", $userId, $state);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0);
}

function sumUserPaysByState(mysqli $connection, int $userId, string $state): int {
    $stmt = $connection->prepare(
        "SELECT COALESCE(SUM(`price`), 0) AS total 
         FROM `pays` 
         WHERE `user_id`=? AND `state`=?"
    );
    $stmt->bind_param("is", $userId, $state);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row['total'] ?? 0);
}

function buildAdminKeyboardWithStats(
    mysqli $connection,
    array $buttonValues,
    string $approveCb,
    string $declineCb,
    string $hashId,
    int $userId
): string {

    $approvedCount = countUserPaysByState($connection, $userId, 'approved');
    $declinedCount = countUserPaysByState($connection, $userId, 'declined');
    $approvedSum   = sumUserPaysByState($connection, $userId, 'approved');

    return inlineKeyboard([
        [
            ['text' => $buttonValues['decline'], 'callback_data' => $declineCb],
            ['text' => $buttonValues['approve'], 'callback_data' => $approveCb],
        ],
        [
            ['text' => $approvedCount . ' عدد', 'callback_data' => "noop_okc_{$hashId}"],
            ['text' => 'پرداخت موفق', 'callback_data' => "noop_ok_{$hashId}"],
        ],
        [
            ['text' => $declinedCount . ' عدد', 'callback_data' => "noop_badc_{$hashId}"],
            ['text' => 'پرداخت رد شده', 'callback_data' => "noop_bad_{$hashId}"],
        ],
        [
            ['text' => number_format($approvedSum) . ' تومان', 'callback_data' => "noop_sumv_{$hashId}"],
			['text' => 'مجموع', 'callback_data' => "noop_sum_{$hashId}"],
        ],
        [
            ['text' => "🆔 {$hashId}", 'callback_data' => "noop_{$hashId}"]
        ],
    ]);
}

function sendReceiptToAdminsAndUpdatePay(
    mysqli $connection,
    string $fileid,
    string $caption,
    string $adminKeyboard,
    int $adminChatId,
    string $hashId
): void {
    $res = sendPhoto($fileid, $caption, $adminKeyboard, "HTML", $adminChatId);

    sendToAllAdmins('sendPhoto', [
        'photo' => $fileid,
        'caption' => $caption,
        'reply_markup' => $adminKeyboard,
        'parse_mode' => "HTML"
    ], $hashId);

    $msgId = $res->result->message_id ?? null;
    if ($msgId) {
        $stmt = $connection->prepare("UPDATE `pays` SET `state`='have_sent', `message_id`=?, `chat_id`=? WHERE `hash_id`=?");
        $stmt->bind_param("iis", $msgId, $adminChatId, $hashId);
        $stmt->execute();
        $stmt->close();
    }
}

function sendWithStartButton($text, $buttonText, $botUsername, $startPayload = 'mainMenu', $parseMode = 'HTML')
{
    $keyboard = json_encode([
        'inline_keyboard' => [
            [[
                'text' => $buttonText,
                'url'  => "https://t.me/{$botUsername}?start={$startPayload}"
            ]]
        ]
    ], JSON_UNESCAPED_UNICODE);

    return sendMessage($text, $keyboard, $parseMode);
}

?>