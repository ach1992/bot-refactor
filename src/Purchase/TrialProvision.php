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

    $protocol   = $plan['protocol'];
    $price      = (int)$plan['price'];
    $server_id  = (int)$plan['server_id'];
    $acount     = (int)$plan['acount'];
    $inbound_id = (int)$plan['inbound_id'];

    [$price, $agentBought, $discountPercent] = calculateAgentPrice(
        $userInfo,
        $botState,
        $planId,
        $server_id,
        $buyType,
        $price
    );

    ensureServerCapacity($connection, $server_id, $inbound_id, $acount);
    $uniqid = generateRandomString(42, $protocol);

    [$serverInfo, $serverConfig] = getServerInfoAndConfig($connection, $server_id);
    $srv_remark = $serverInfo['remark'];

    $serverType = $serverConfig['type'];

    // تعیین remark (با امکان override توسط ادمین از temp)
    $forcedRemark = null;
    if ($from_id == $admin && !empty($userInfo['temp'])) {
        $forcedRemark = $userInfo['temp'];
        setUser('', 'temp');
    }

    $remarkUserId = $from_id;
    $remark       = buildRemark($botState, $srv_remark, '', $remarkUserId, $forcedRemark);


    try {
        [$response, $remark, , $effectId] = achbotCreateNonPaymentAccount(
            $connection,'free_trial',$trialSource,(int)$from_id,1,1,[
                'is_primary_admin'=>((int)$from_id===(int)$admin),
                'user_id'=>(int)$from_id,'server_type'=>$serverType,
                'server_id'=>$server_id,'inbound_id'=>$inbound_id,
                'plan_id'=>$planId,'remark'=>$remark,'volume'=>$volume,
                'days'=>$days
            ]);

    // چک موفقیت ساخت اکانت
    ensureAccountCreationSucceeded($response, $serverInfo, $inbound_id, $admin);
    $expire_date=(int)$response->expire;

    alert(\achbotMessage('legacy_messages.sending_config_to_user'));

    require_once __DIR__.'/../Subscription/Delivery.php';
    $delivery=achbotNonpaidPurchasePacket($connection,$serverType,$server_id,$serverConfig,
        $botState,(string)($botUsername??''),(string)$botUrl,$remark,$response,$volume,$days,(int)$from_id);

    if ($connection->begin_transaction()!==true)
        throw new RuntimeException('trial_order_begin');
    try {
        $orderId=insertOrder(
            $connection,$from_id,'',$planId,$server_id,$inbound_id,
            $remark,$uniqid,$expire_date,$delivery['link'],
            $price,$date,$agentBought?1:0,true);
        achbotLinkNonPaymentAccountEffect($connection,$effectId,$orderId);
        $deliveryId=achbotNonpaidDeliveryInsertLocked($connection,$effectId,$orderId,$delivery['packet']);
        require_once __DIR__.'/../Finance/OperationalReports.php';
        $userId   = $userInfo['userid'] ?? $from_id;
        $fullName = $userInfo['name'] ?? '---';
        $username = $userInfo['username'] ?? '';

        $usernameText = !empty($username) ? '@' . $username : \achbotMessage('purchase.trial_provision.text.8c2d826d72');

        $channelMessage = \achbotMessageHtml('purchase.trial_provision.channel_message.d22e3c7448', ['userId' => $userId, 'fullName' => $fullName, 'usernameText' => $usernameText, 'remark' => $remark, 'volume' => $volume, 'days' => $days]);

        $channelMessage.="\n".achbotMessageHtml('operational_reports.service_order',['order'=>$orderId])."\n".
            achbotMessageHtml('operational_reports.source_reference',['reference'=>'trial:'.$effectId]);
        $reportRef='trial:'.$effectId;
        achbotOperationalReportStageLocked($connection,$reportRef,(int)$from_id,
            (int)$from_id===(int)$admin?(int)$admin:0,'operations',$channelMessage);
        $s=achbotProviderStatement($connection,"UPDATE users SET freetrial='used' WHERE userid=?",'i',[(int)$from_id]);$s->close();
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
            (achbotNotifyAdmins('sendMessage', ['text'=>\achbotMessage('purchase.trial_provision.message.30b8933da5').
                \achbotMessage('purchase.trial_provision.message.922f2b9906'),
                    'reply_markup'=>null,'parse_mode'=>null],
                    ['primary'=>$admin,'report_channel'=>null])[0]['response']??null);
        } catch (Throwable $ignored) {}
        alert(\achbotMessage('purchase.trial_provision.alert.cf3893b9c0'),true);
        return;
    }

    achbotOperationalReportDispatch($connection,$reportRef);
    try {
        if (achbotServiceDeliveryDispatch($connection,$deliveryId)!=='acknowledged')
            throw new RuntimeException('trial_delivery_unconfirmed');
    } catch (Throwable $error) {
        error_log('achbot_trial_delivery_review effect_id='.$effectId.
            ' class='.get_class($error));
    }

}

