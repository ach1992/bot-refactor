<?php
/** AChBot V7: immutable V6.1 purchase or plan route. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG


if (preg_match('/freeTrial(\d+)_(?<buyType>\w+)/', $data, $match)) {
    $planId  = (int)$match[1];
    if (!achbotSupportsPlan($connection, $planId)) {
        alert(\achbotMessage('purchase.trial_provision.alert.efb178e861'), true);
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
        in_array(($userInfo['freetrial'] ?? ''), ['used','reserved'], true) &&
        $from_id != $admin &&
        $normalDiscount !== "100"
    ) {
        alert(\achbotMessage('purchase.trial_provision.alert.72ed718294'));
        exit;
    }

    require_once __DIR__.'/../Finance/ServiceEffects.php';
    try {
        $trialSource=achbotNonPaymentSourceRef(
            $update,(int)$from_id,'free_trial',(string)$data,
            (int)$from_id===(int)$admin);
    } catch (Throwable $error) {
        alert(\achbotMessage('purchase.trial_provision.alert.fc60efd929'),true);
        return;
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

    try {
        [$response, $remark, $port, $effectId] = achbotCreateNonPaymentAccount(
            $connection,'free_trial',$trialSource,(int)$from_id,1,1,[
                'is_primary_admin'=>((int)$from_id===(int)$admin),
                'user_id'=>(int)$from_id,'server_type'=>$serverType,
                'server_id'=>$server_id,'inbound_id'=>$inbound_id,
                'plan_id'=>$planId,'remark'=>$remark,'volume'=>$volume,
                'days'=>$days,'uniqid'=>$uniqid,'protocol'=>$protocol,
                'port'=>$port,'expire_microdate'=>$expire_microdate,
                'net_type'=>$netType,'rahgozar'=>$rahgozar,'limitip'=>$limitip
            ]);

    // چک موفقیت ساخت اکانت
    ensureAccountCreationSucceeded($response, $serverInfo, $inbound_id, $admin);

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
        $from_id,
        $volume,
        $days,
        $buttonValues,
        [],
        false,
        '',
        null,
        false
    );

    if ($connection->begin_transaction()!==true)
        throw new RuntimeException('trial_order_begin');
    try {
        $orderId=insertOrder(
            $connection,$from_id,$token,$planId,$server_id,$inbound_id,
            $remark,$uniqid,$protocol,$expire_date,$vray_link,
            $price,$date,$rahgozar,$agentBought?1:0,true);
        achbotLinkNonPaymentAccountEffect($connection,$effectId,$orderId);
        if ($connection->commit()!==true)
            throw new RuntimeException('trial_order_commit_unknown');
    } catch (Throwable $error) {
        try {$connection->rollback();} catch (Throwable $ignored) {}
        throw $error;
    }
    } catch (Throwable $error) {
        error_log('achbot_trial_effect_needs_review source='.
            preg_replace('/[^A-Za-z0-9_:]/','',$trialSource).
            ' class='.get_class($error));
        try {
            sendMessage(\achbotMessage('purchase.trial_provision.message.30b8933da5').
                \achbotMessage('purchase.trial_provision.message.922f2b9906'),
                null,null,$admin);
        } catch (Throwable $ignored) {}
        alert(\achbotMessage('purchase.trial_provision.alert.cf3893b9c0'),true);
        return;
    }

    try {
        if (!sendQrFromLink($qrLink,$caption,$keys,$botUrl,(int)$from_id,'',null))
            throw new RuntimeException('trial_delivery_unconfirmed');
    } catch (Throwable $error) {
        error_log('achbot_trial_delivery_review effect_id='.$effectId.
            ' class='.get_class($error));
    }

    $userId   = $userInfo['userid'] ?? $from_id;
    $fullName = $userInfo['name'] ?? '---';
    $username = $userInfo['username'] ?? '';

    $usernameText = !empty($username) ? '@' . $username : \achbotMessage('purchase.trial_provision.text.8c2d826d72');

    $channelMessage = \achbotMessage('purchase.trial_provision.channel_message.d22e3c7448', ['userId' => $userId, 'fullName' => $fullName, 'usernameText' => $usernameText, 'remark' => $remark, 'volume' => $volume, 'days' => $days]);

    // ارسال پیام به ادمین اصلی
    sendMessage($channelMessage, null, "html", $admin);

    // ارسال پیام به کانال گزارشات (در صورت تنظیم)
    $reportChannel = $botState['rewardChannel'] ?? null;
    if (!empty($reportChannel)) {
        sendMessage($channelMessage, null, "html", $reportChannel);
    }

    setUser('used', 'freetrial');
}

