<?php
/** AChBot V7: immutable V6.1 purchase or plan route. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

if (preg_match('/freeTrial(\d+)_(?<buyType>\w+)/', $data, $match)) {
    $planId  = (int)$match[1];
    if (!achbotSupportsMarzbanPlan($connection, $planId)) {
        alert("⛔️ فعال‌سازی سرویس آزمایشی در حال حاضر امکان‌پذیر نیست.", true);
        return;
    }
    $buyType = $match['buyType'] ?? '';
    $discountsRaw  = $userInfo['discount_percent'] ?? '{}';
    $discountsData = json_decode($discountsRaw, true);
    if (!is_array($discountsData)) {
        $discountsData = [];
    }
    $normalDiscount = isset($discountsData['normal']) ? (string)$discountsData['normal'] : null;

    if (
        ($userInfo['freetrial'] ?? '') === 'used' &&
        $from_id != $admin &&
        $normalDiscount !== "100"
    ) {
        alert('⚠️ شما قبلا هدیه رایگان خود را دریافت کردید.');
        exit;
    }

    delMessage();

    $planDetails = getPlanDetails($connection, $planId);
    $plan        = $planDetails['plan'];

    $days             = $planDetails['days'];
    $volume           = $planDetails['volume'];
    $date             = $planDetails['date'];
    $expire_microdate = $planDetails['expire_microdate'];
    $expire_date      = $planDetails['expire_date'];

    $type       = $plan['type'];
    $protocol   = $plan['protocol'];
    $price      = (int)$plan['price'];
    $server_id  = (int)$plan['server_id'];
    $acount     = (int)$plan['acount'];
    $inbound_id = (int)$plan['inbound_id'];
    $limitip    = $plan['limitip'];
    $netType    = $plan['type'];
    $rahgozar   = $plan['rahgozar'];
    $customPath = $plan['custom_path'];
    $customPort = $plan['custom_port'];
    $customSni  = $plan['custom_sni'];

    [$price, $agentBought, $discountPercent] = calculateAgentPrice(
        $userInfo,
        $botState,
        $planId,
        $server_id,
        $buyType,
        $price
    );

    ensureServerCapacity($connection, $server_id, $inbound_id, $acount, $mainValues);
    $uniqid = generateRandomString(42, $protocol);

    [$serverInfo, $serverConfig] = getServerInfoAndConfig($connection, $server_id);
    $srv_remark = $serverInfo['remark'];

    $serverType = $serverConfig['type'];
    $portType   = $serverConfig['port_type'];

    // تعیین remark (با امکان override توسط ادمین از temp)
    $forcedRemark = null;
    if ($from_id == $admin && !empty($userInfo['temp'])) {
        $forcedRemark = $userInfo['temp'];
        setUser('', 'temp');
    }

    $remarkUserId = $from_id;
    $remark       = buildRemark($botState, $srv_remark, '', $remarkUserId, $forcedRemark);

    // گرفتن پورت (auto / random) از temp.txt
    [$port, $last_num] = getNextPortAndIncrease('settings/temp.txt', $portType);

    [$response, $remark, $port] = createServerAccount(
        $serverType,
        $server_id,
        $inbound_id,
        $planId,
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

    // چک موفقیت ساخت اکانت
    ensureAccountCreationSucceeded($response, $serverInfo, $inbound_id, $admin);

    alert($mainValues['sending_config_to_user']);

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
        $from_id,
        $volume,
        $days,
        $buttonValues,
        $linkIndices,
        $hideConfigIfMany
    );

    insertOrder(
        $connection,
        $from_id,     // userid
        $token,
        $planId,
        $server_id,
        $inbound_id,
        $remark,
        $uniqid,
        $protocol,
        $expire_date,
        $vray_link,
        $price,
        $date,
        $rahgozar,
        $agentBought ? 1 : 0
    );

    decreaseServerCapacity($connection, $server_id, $inbound_id, $planId, 1);

    $userId   = $userInfo['userid'] ?? $from_id;
    $fullName = $userInfo['name'] ?? '---';
    $username = $userInfo['username'] ?? '';

    $usernameText = !empty($username) ? '@' . $username : 'ندارد';

    $channelMessage = "
🔄 اشتراک تست ایجاد شد.

🎫 شناسه عددی: <code>{$userId}</code>
👨 اسم کاربر: {$fullName}
⚡️ نام کاربری: {$usernameText}
🎈 نام کاربری اشتراک: <code>{$remark}</code>
🔋 حجم اولیه اشتراک: {$volume} گیگ
⏰ مدت اشتراک: {$days} روز
";

    // ارسال پیام به ادمین اصلی
    sendMessage($channelMessage, null, "html", $admin);

    // ارسال پیام به کانال گزارشات (در صورت تنظیم)
    $reportChannel = $botState['rewardChannel'] ?? null;
    if (!empty($reportChannel)) {
        sendMessage($channelMessage, null, "html", $reportChannel);
    }

    setUser('used', 'freetrial');
}

