<?php
/** AChBot V7: immutable V6.1 purchase or plan route. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG


/** Read-only menu admission; use the same configured methods as checkout. */
function achbotCustomerHasPaymentMethod(mysqli $db,int $userId,array $state): bool {
    foreach (['bank_transfer','gift_card'] as $method)
        if (achbotManualEnabled($method) && achbotPayUxAudienceAllowed($db,$method,$userId)) return true;
    if (achbotManualCryptoEnabled($userId)) {
        foreach (['manual_usdt','manual_trx'] as $method)
            if (achbotManualEnabled($method) && achbotPayUxAudienceAllowed($db,$method,$userId)) return true;
    }
    foreach (['wallet'=>'walletState','nowpayments'=>'nowPaymentOther','zarinpal'=>'zarinpal','nextpay'=>'nextpay'] as $method=>$flag) {
        if (($state[$flag]??'off')!=='on' || !achbotPayUxAudienceAllowed($db,$method,$userId)) continue;
        if ($method!=='nowpayments' || achbotUsdtRateForUser($db,$userId)!==null) return true;
    }
    return false;
}

if(($data == "agentOneBuy" || $data=='buySubscription' || $data == "agentMuchBuy") && ($botState['sellState']=="on" || ($from_id == $admin || $userInfo['isAdmin'] == true))){
    if(!achbotCustomerHasPaymentMethod($connection,(int)$from_id,$botState)){
        alert($mainValues['selling_is_off']);
        exit();
    }
    if($data=="buySubscription") $buyType = "none";
    elseif($data=="agentOneBuy") $buyType = "one";
    elseif($data== "agentMuchBuy") $buyType = "much";
    
    $stmt = $connection->prepare("SELECT si.* FROM `server_info` si INNER JOIN `server_config` sc ON sc.`id`=si.`id` AND sc.`type` IN (".achbotProviderSqlTypes().") WHERE si.`active`=1 and si.`state`=1 and si.`ucount` > 0 ORDER BY si.`id` ASC");
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();
    if($respd->num_rows==0){
        alert($mainValues['no_server_available']);
        exit;
    }
    $keyboard = [];
    while($cat = $respd->fetch_assoc()){
        $id = $cat['id'];
        $name = $cat['title'];
        $flag = $cat['flag'];
        $keyboard[] = ['text' => "$flag $name", 'callback_data' => "selectServer{$id}_{$buyType}"];
    }
    $keyboard = array_chunk($keyboard,1);
    $keyboard[] = [['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]];
    editText($message_id, $mainValues['buy_sub_select_location'], json_encode(['inline_keyboard'=>$keyboard]));
}

if($data=='createMultipleAccounts' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT si.* FROM `server_info` si INNER JOIN `server_config` sc ON sc.`id`=si.`id` AND sc.`type` IN (".achbotProviderSqlTypes().") WHERE si.`active`=1 and si.`ucount` > 0 ORDER BY si.`id` ASC");
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();
    if($respd->num_rows==0){
        sendMessage($mainValues['no_server_available']);
        exit;
    }
    $keyboard = [];
    while($cat = $respd->fetch_assoc()){
        $id = $cat['id'];
        $name = $cat['title'];
        $flag = $cat['flag'];
        $keyboard[] = ['text' => "$flag $name", 'callback_data' => "createAccServer$id"];
    }
    $keyboard[] = ['text'=>$buttonValues['back_to_main'],'callback_data'=>"managePanel"];
    $keyboard = array_chunk($keyboard,1);
    editText($message_id, $mainValues['buy_sub_select_location'], json_encode(['inline_keyboard'=>$keyboard]));
    

}
if(preg_match('/createAccServer(\d+)/',$data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true) ) {
    $sid = $match[1];
        
    $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `parent`=0 order by `id` asc");
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();
    if($respd->num_rows == 0){
        alert(\achbotMessage('purchase.entry_and_bulk.create_multiple_accounts.alert.de33518bf8'));
    }else{
        
        $keyboard = [];
        while ($file = $respd->fetch_assoc()){
            $id = $file['id'];
            $name = $file['title'];
            $stmt = $connection->prepare("SELECT p.* FROM `server_plans` p INNER JOIN `server_config` sc ON sc.`id`=p.`server_id` AND sc.`type` IN (".achbotProviderSqlTypes().") WHERE p.`server_id`=? and p.`catid`=? and p.`active`=1 AND p.`inbound_id`=0");
            $stmt->bind_param("ii", $sid, $id);
            $stmt->execute();
            $rowcount = $stmt->get_result()->num_rows; 
            $stmt->close();
            if($rowcount>0) $keyboard[] = ['text' => "$name", 'callback_data' => "createAccCategory{$id}_{$sid}"];
        }
        if(empty($keyboard)){
            alert(\achbotMessage('purchase.entry_and_bulk.create_multiple_accounts.alert.de33518bf8'));exit;
        }
        alert(\achbotMessage('purchase.entry_and_bulk.create_multiple_accounts.alert.174c6a61d7'));
        $keyboard[] = ['text' => $buttonValues['back_to_main'], 'callback_data' => "createMultipleAccounts"];
        $keyboard = array_chunk($keyboard,1);
        editText($message_id, \achbotMessage('purchase.entry_and_bulk.create_multiple_accounts.message.2f3dfcbe8e'), json_encode(['inline_keyboard'=>$keyboard]));
    }

}
if(preg_match('/createAccCategory(\d+)_(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $call_id = $match[1];
    $sid = $match[2];
    $stmt = $connection->prepare("SELECT p.* FROM `server_plans` p INNER JOIN `server_config` sc ON sc.`id`=p.`server_id` AND sc.`type` IN (".achbotProviderSqlTypes().") WHERE p.`server_id`=? and p.`catid`=? and p.`active`=1 AND p.`inbound_id`=0 order by p.`id` asc");
    $stmt->bind_param("ii", $sid, $call_id);
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();
    if($respd->num_rows==0){
        alert(\achbotMessage('purchase.entry_and_bulk.create_multiple_accounts.alert.5223e5a747'));
    }else{
        alert(\achbotMessage('purchase.entry_and_bulk.create_multiple_accounts.alert.465c2a0af9'));
        $keyboard = [];
        while($file = $respd->fetch_assoc()){
            $id = $file['id'];
            $name = $file['title'];
            $keyboard[] = ['text' => "$name", 'callback_data' => "createAccPlan{$id}"];
        }
        $keyboard[] = ['text' => $buttonValues['back_to_main'], 'callback_data' => "createAccServer$sid"];
        $keyboard = array_chunk($keyboard,1);
        editText($message_id, \achbotMessage('purchase.entry_and_bulk.create_multiple_accounts.message.7f4d98ab9b'), json_encode(['inline_keyboard'=>$keyboard]));
    }

}
if(preg_match('/^createAccPlan(\d+)/',$data,$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if (!achbotSupportsPlan($connection, (int)$match[1])) {
        alert(\achbotMessage('purchase.entry_and_bulk.create_multiple_accounts.alert.d3fb64c4d9'), true);
        return;
    }
    delMessage();
    sendMessage(\achbotMessage('purchase.entry_and_bulk.create_multiple_accounts.message.c754e347bf'),$cancelKey);
    setUser('createAccDate' . $match[1]);
}
if(preg_match('/^createAccDate(\d+)/',$userInfo['step'],$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(is_numeric($text)){
        if($text >0){
            sendMessage(\achbotMessage('purchase.entry_and_bulk.create_multiple_accounts.message.f018216ab3'));
            setUser('createAccVolume' . $match[1] . "_" . $text);
        }else{
            sendMessage(\achbotMessage('purchase.entry_and_bulk.create_multiple_accounts.message.9cc4de866d'));
        }
    }else{
        sendMessage(\achbotMessage('purchase.entry_and_bulk.create_multiple_accounts.message.141dd787b4'));
    }
}
if(preg_match('/^createAccVolume(\d+)_(\d+)/',$userInfo['step'],$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(!is_numeric($text)){
        sendMessage($mainValues['send_only_number']);
        exit();
    }elseif($text <=0){
        sendMessage(\achbotMessage('purchase.entry_and_bulk.create_multiple_accounts.message.f30d6ad265'));
        exit();
    }
    sendMessage($mainValues['enter_account_amount']);
    setUser("createAccAmount" . $match[1] . "_" . $match[2] . "_" . $text);
}

if (
    preg_match('/^createAccAmount(\d+)_(\d+)_(\d+)/', $userInfo['step'], $match) &&
    $text != $buttonValues['cancel'] &&
    ($from_id == $admin || ($userInfo['isAdmin'] ?? false) == true)
) {

    require_once __DIR__.'/../Finance/ServiceEffects.php';
    try {
        $bulkSource=achbotNonPaymentSourceRef(
            $update,(int)$from_id,'admin_bulk',(string)$text,
            (int)$from_id===(int)$admin);
    } catch (Throwable $error) {
        alert(\achbotMessage('purchase.entry_and_bulk.create_multiple_accounts.alert.dd52ddf02f'),true);
        return;
    }

    setUser();

    if (!is_string($text) || preg_match('/^[1-9][0-9]*$/D',$text)!==1 ||
        filter_var($text,FILTER_VALIDATE_INT)===false) {
        sendMessage($mainValues['send_only_number']);
        exit();
    }
    $count = (int)$text;
    if ($count <= 0) {
        sendMessage(\achbotMessage('purchase.entry_and_bulk.create_multiple_accounts.message.f30d6ad265'));
        exit();
    }

    $uid = (int)$from_id;
    $fidFromStep    = (int)$match[1];
    $daysFromStep   = (int)$match[2];
    $volumeFromStep = (int)$match[3];

    if (!achbotSupportsPlan($connection, $fidFromStep)) {
        alert(\achbotMessage('purchase.entry_and_bulk.create_multiple_accounts.alert.d3fb64c4d9'), true);
        return;
    }
    $planDetails = getPlanDetails($connection, $fidFromStep);
    $plan        = $planDetails['plan'];

    $days   = $daysFromStep;
    $volume = $volumeFromStep;

    $date             = time();
    $expire_microdate = floor(microtime(true) * 1000) + (864000 * $days * 100);
    $expire_date      = $date + (86400 * $days);

    $price      = isset($plan['price']) ? (int)$plan['price'] : 0;
    $server_id  = (int)$plan['server_id'];
    $inbound_id = (int)$plan['inbound_id'];
    $acount     = (int)$plan['acount'];

    $protocol   = $plan['protocol'];
    $netType    = $plan['type'];
    $rahgozar   = $plan['rahgozar'];
    $customPath = $plan['custom_path'];
    $customPort = $plan['custom_port'];
    $customSni  = $plan['custom_sni'];
    $limitip    = $plan['limitip'];

    ensureServerCapacity($connection, $server_id, $inbound_id, $acount, $mainValues);

    if ($inbound_id != 0 && $acount < $count) {
        sendMessage(str_replace("AMOUNT", $acount, $mainValues['can_create_specific_account']));
        exit();
    }

    [$serverInfo, $serverConfig] = getServerInfoAndConfig($connection, $server_id);

    $srv_remark = $serverInfo['remark'] ?? '';
    $serverType = $serverConfig['type'] ?? '';
    $portType   = $serverConfig['port_type'] ?? 'rand';

    alert($mainValues['sending_config_to_user']);

    $lastRemark = '';
    $pendingDeliveries=[];
    try {
    for ($i = 1; $i <= $count; $i++) {

        $uniqid = generateRandomString(42, $protocol);

        [$port] = getNextPortAndIncrease('settings/temp.txt', $portType);

        $remark = buildRemark(
            $botState,
            $srv_remark,
            $plan['descr'] ?? '',
            $uid,
            null
        );

        // Bind uniqueness to the verified Telegram update, not to the
        // manually configured (possibly identical) plan description.
        $remark=achbotAdminBulkAccountRemark(
            (string)$remark,$bulkSource,$i);
        [$response, $remark, $port, $effectId] = achbotCreateNonPaymentAccount(
            $connection,'admin_bulk',$bulkSource,$uid,$i,$count,[
                'is_primary_admin'=>($uid===(int)$admin),
                'user_id'=>$uid,'server_type'=>$serverType,
                'server_id'=>$server_id,'inbound_id'=>$inbound_id,
                'plan_id'=>$fidFromStep,'remark'=>$remark,'volume'=>$volume,
                'days'=>$days,'uniqid'=>$uniqid,'protocol'=>$protocol,
                'port'=>$port,'expire_microdate'=>$expire_microdate,
                'net_type'=>$netType,'rahgozar'=>$rahgozar,
                'limitip'=>$limitip
            ]
        );

        ensureAccountCreationSucceeded($response, $serverInfo, $inbound_id, $admin);

        $lastRemark = $remark;

        [$token, $vray_link, $uniqidReturned, $caption, $keys, $qrLink] = buildLinksAndSendQr(
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
            [],
            false,
            '',
            null,
            false
        );

        if ($connection->begin_transaction()!==true)
            throw new RuntimeException('admin_bulk_order_begin');
        try {
            $orderId=insertOrder(
                $connection,$uid,$token,$fidFromStep,$server_id,
                $inbound_id,$remark,$uniqidReturned?:$uniqid,$expire_date,$vray_link,$price,
                $date,0,true);
            achbotLinkNonPaymentAccountEffect($connection,$effectId,$orderId);
            if ($connection->commit()!==true)
                throw new RuntimeException('admin_bulk_order_commit_unknown');
        } catch (Throwable $error) {
            try {$connection->rollback();} catch (Throwable $ignored) {}
            throw $error;
        }
        $pendingDeliveries[]=['qr'=>$qrLink,'caption'=>$caption,'keys'=>$keys];
    }

    // Capacity was atomically held with first journal dispatch.
    } catch (Throwable $error) {
        error_log('achbot_admin_bulk_effect_needs_review source='.
            preg_replace('/[^A-Za-z0-9_:]/','',$bulkSource).
            ' class='.get_class($error));
        try {
            sendMessage(\achbotMessage('purchase.entry_and_bulk.create_multiple_accounts.message.76499f65f3').
                \achbotMessage('purchase.entry_and_bulk.create_multiple_accounts.message.fbde023d57'),null,null,$admin);
        } catch (Throwable $ignored) {}
        alert(\achbotMessage('purchase.entry_and_bulk.create_multiple_accounts.alert.c7ce33a4eb'),true);
        return;
    }
    foreach ($pendingDeliveries as $packet) {
        try {
            if (!sendQrFromLink($packet['qr'],$packet['caption'],$packet['keys'],
                    $botUrl,$uid,'',null))
                throw new RuntimeException('bulk_telegram_unconfirmed');
        } catch (Throwable $error) {
            error_log('achbot_admin_bulk_delivery_review source='.
                preg_replace('/[^A-Za-z0-9_:]/','',$bulkSource).
                ' class='.get_class($error));
        }
    }
    sendMessage(\achbotMessage('purchase.entry_and_bulk.create_multiple_accounts.message.62d6e89b94'), getMainKeys());
    setUser();
}

