<?php
/** AChBot V7: immutable V6.1 purchase or plan route. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

if(($data == "agentOneBuy" || $data=='buySubscription' || $data == "agentMuchBuy") && ($botState['sellState']=="on" || ($from_id == $admin || $userInfo['isAdmin'] == true))){
    if($botState['cartToCartState'] == "off" && $botState['walletState'] == "off"){
        alert($mainValues['selling_is_off']);
        exit();
    }
    if($data=="buySubscription") $buyType = "none";
    elseif($data=="agentOneBuy") $buyType = "one";
    elseif($data== "agentMuchBuy") $buyType = "much";
    
    $stmt = $connection->prepare("SELECT si.* FROM `server_info` si INNER JOIN `server_config` sc ON sc.`id`=si.`id` AND sc.`type`='marzban' WHERE si.`active`=1 and si.`state`=1 and si.`ucount` > 0 ORDER BY si.`id` ASC");
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
    $stmt = $connection->prepare("SELECT si.* FROM `server_info` si INNER JOIN `server_config` sc ON sc.`id`=si.`id` AND sc.`type`='marzban' WHERE si.`active`=1 and si.`ucount` > 0 ORDER BY si.`id` ASC");
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
        alert("هیچ دسته بندی برای این سرور وجود ندارد.");
    }else{
        
        $keyboard = [];
        while ($file = $respd->fetch_assoc()){
            $id = $file['id'];
            $name = $file['title'];
            $stmt = $connection->prepare("SELECT p.* FROM `server_plans` p INNER JOIN `server_config` sc ON sc.`id`=p.`server_id` AND sc.`type`='marzban' WHERE p.`server_id`=? and p.`catid`=? and p.`active`=1 AND p.`inbound_id`=0");
            $stmt->bind_param("ii", $sid, $id);
            $stmt->execute();
            $rowcount = $stmt->get_result()->num_rows; 
            $stmt->close();
            if($rowcount>0) $keyboard[] = ['text' => "$name", 'callback_data' => "createAccCategory{$id}_{$sid}"];
        }
        if(empty($keyboard)){
            alert("هیچ دسته بندی برای این سرور وجود ندارد.");exit;
        }
        alert("♻️ دریافت دسته بندی ...");
        $keyboard[] = ['text' => $buttonValues['back_to_main'], 'callback_data' => "createMultipleAccounts"];
        $keyboard = array_chunk($keyboard,1);
        editText($message_id, "2️⃣ مرحله دو

دسته بندی مورد نظرت رو انتخاب کن 🤭", json_encode(['inline_keyboard'=>$keyboard]));
    }

}
if(preg_match('/createAccCategory(\d+)_(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $call_id = $match[1];
    $sid = $match[2];
    $stmt = $connection->prepare("SELECT p.* FROM `server_plans` p INNER JOIN `server_config` sc ON sc.`id`=p.`server_id` AND sc.`type`='marzban' WHERE p.`server_id`=? and p.`catid`=? and p.`active`=1 AND p.`inbound_id`=0 order by p.`id` asc");
    $stmt->bind_param("ii", $sid, $call_id);
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();
    if($respd->num_rows==0){
        alert("💡 پلنی در این دسته بندی وجود ندارد. ");
    }else{
        alert("📍 در حال دریافت لیست پلن ها");
        $keyboard = [];
        while($file = $respd->fetch_assoc()){
            $id = $file['id'];
            $name = $file['title'];
            $keyboard[] = ['text' => "$name", 'callback_data' => "createAccPlan{$id}"];
        }
        $keyboard[] = ['text' => $buttonValues['back_to_main'], 'callback_data' => "createAccServer$sid"];
        $keyboard = array_chunk($keyboard,1);
        editText($message_id, "3️⃣ مرحله سه

یکی از پلن هارو انتخاب کن و برو برای پرداختش 🤲 🕋", json_encode(['inline_keyboard'=>$keyboard]));
    }

}
if(preg_match('/^createAccPlan(\d+)/',$data,$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if (!achbotSupportsMarzbanPlan($connection, (int)$match[1])) {
        alert("⛔️ این پلن مرزبان معتبر نیست.", true);
        return;
    }
    delMessage();
    sendMessage("❗️ لطفا مدت زمان اکانت را به ( روز ) وارد کن:",$cancelKey);
    setUser('createAccDate' . $match[1]);
}
if(preg_match('/^createAccDate(\d+)/',$userInfo['step'],$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(is_numeric($text)){
        if($text >0){
            sendMessage("❕ حجم اکانت ها رو به گیگابایت ( GB ) وارد کن:");
            setUser('createAccVolume' . $match[1] . "_" . $text);
        }else{
            sendMessage("عدد باید بیشتر از 0 باشه");
        }
    }else{
        sendMessage('😡 مگه نمیگم فقط عدد بفرس نمیفهمی؟ یا خودتو زدی به نفهمی؟');
    }
}
if(preg_match('/^createAccVolume(\d+)_(\d+)/',$userInfo['step'],$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(!is_numeric($text)){
        sendMessage($mainValues['send_only_number']);
        exit();
    }elseif($text <=0){
        sendMessage("مقداری بزرگتر از 0 وارد کن");
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

    setUser();

    if (!is_numeric($text)) {
        sendMessage($mainValues['send_only_number']);
        exit();
    }
    $count = (int)$text;
    if ($count <= 0) {
        sendMessage("مقداری بزرگتر از 0 وارد کن");
        exit();
    }

    $uid = (int)$from_id;
    $fidFromStep    = (int)$match[1];
    $daysFromStep   = (int)$match[2];
    $volumeFromStep = (int)$match[3];

    if (!achbotSupportsMarzbanPlan($connection, $fidFromStep)) {
        alert("⛔️ این پلن مرزبان معتبر نیست.", true);
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

    for ($i = 1; $i <= $count; $i++) {

        $uniqid = generateRandomString(42, $protocol);

        [$port] = getNextPortAndIncrease('settings/temp.txt', $portType);

        $remark = buildRemark(
            $botState,
            $srv_remark,
            $plan['description'] ?? '',
            $uid,
            null
        );

        [$response, $remark, $port] = createServerAccount(
            $serverType,
            $server_id,
            $inbound_id,
            $fidFromStep,
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

        $lastRemark = $remark;

        [$token, $vray_link, $uniqidReturned] = buildLinksAndSendQr(
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

        // ثبت سفارش
        insertOrder(
            $connection,
            $uid,
            $token,
            $fidFromStep,
            $server_id,
            $inbound_id,
            $remark,
            $uniqidReturned,
            $protocol,
            $expire_date,
            $vray_link,
            $price,
            $date,
            $rahgozar,
            0
        );
    }

    decreaseServerCapacity($connection, $server_id, $inbound_id, $fidFromStep, $count);

    sendMessage("☑️|❤️ اکانت های جدید با موفقیت ساخته شد.", getMainKeys());
    setUser();
}

