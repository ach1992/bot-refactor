<?php
/** AChBot V7: immutable V6.1 purchase or plan route. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG


// A cached admin keyboard may still send a former X-UI plan callback.
if (in_array((string)$data, ['addNewPlan','addNewRahgozarPlan'], true) &&
    ($from_id == $admin || ($userInfo['isAdmin'] ?? false) == true)) {
    alert(\achbotMessage('plans.administration.alert.f785716e8a'), true);
    return;
}
// Only active Marzban plan onboarding is accepted.
if ($data == "addNewMarzbanPlan" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setUser($data);
    $stmt = $connection->prepare("DELETE FROM `server_plans` WHERE `active`=0");
    $stmt->execute();
    $stmt->close();
    {
        $sql = "INSERT INTO `server_plans` (`fileid`, `catid`, `server_id`, `inbound_id`, `acount`, `limitip`, `title`, `protocol`, `days`, `volume`, `type`, `price`, `descr`, `pic`, `active`, `step`, `date`)
                                            VALUES ('', 0,0,0,0, 1, '', '', 0, 0, '', 0, '', '',0,1, ?);";
    }
    $stmt = $connection->prepare($sql);
    $stmt->bind_param("i", $time);
    $stmt->execute();
    $stmt->close();
    delMessage();
    $msg = \achbotMessage('plans.administration.add_new_marzban_plan.msg.ead3145756');
    sendMessage($msg,$cancelKey);
    exit;
}
if(($userInfo['step'] ?? '') === 'addNewMarzbanPlan' and $text!=$buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $catkey = [];
    $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `parent` =0 and `active`=1");
    $stmt->execute();
    $cats = $stmt->get_result();
    $stmt->close();

    while ($cat = $cats->fetch_assoc()){
        $id = $cat['id'];
        $name = $cat['title'];
        $catkey[] = ["$id - $name"];
    }
    $catkey[] = [$buttonValues['cancel']];

    $step = checkStep('server_plans');

    if($step==1 and $text!=$buttonValues['cancel']){
        $msg = \achbotMessage('plans.administration.add_new_marzban_plan.msg.061cc2cc53');
        if(strlen($text)>1){
            $stmt = $connection->prepare("UPDATE `server_plans` SET `title`=?,`step`=2 WHERE `active`=0 and `step`=1");
            $stmt->bind_param("s", $text);
            $stmt->execute();
            $stmt->close();
            sendMessage($msg,$cancelKey);
        }
    }
    if($step==2 and $text!=$buttonValues['cancel']){
        $msg = \achbotMessage('plans.administration.add_new_marzban_plan.msg.b2ff03bdc3');
        if(is_numeric($text)){
            $stmt = $connection->prepare("UPDATE `server_plans` SET `price`=?,`step`=3 WHERE `active`=0");
            $stmt->bind_param("s", $text);
            $stmt->execute();
            $stmt->close();
            sendMessage($msg,json_encode(['keyboard'=>$catkey,'resize_keyboard'=>true]));
        }else{
            $msg = \achbotMessage('plans.administration.add_new_marzban_plan.msg.78dabb5a74');
            sendMessage($msg,$cancelKey);
        }
    }
    if($step==3 and $text!=$buttonValues['cancel']){
        $srvkey = [];

        $stmt = $connection->prepare("SELECT `id` FROM `server_config` WHERE `type` IN ('marzban','pasarguard')");
        $stmt->execute();
        $info = $stmt->get_result()->fetch_all();
        $stmt->close();



        $stmt = $connection->prepare(
            "SELECT si.* FROM `server_info` si
             INNER JOIN `server_config` sc ON sc.`id`=si.`id` AND sc.`type` IN ('marzban','pasarguard')
             WHERE si.`active`=1 ORDER BY si.`id`"
        );
        $stmt->execute();

        $srvs = $stmt->get_result();
        $stmt->close();
        sendMessage($mainValues['please_wait_message'],$cancelKey);
        while($srv = $srvs->fetch_assoc()){
            $id = $srv['id'];
            $title = $srv['title'];
            $srvkey[] = ['text' => "$title", 'callback_data' => "selectNewPlanServer$id"];
        }
        if (!$srvkey) {
            sendMessage(\achbotMessage('plans.administration.add_new_marzban_plan.message.7c72687cf5'), $cancelKey);
            return;
        }
        $srvkey = array_chunk($srvkey,2);
        sendMessage(\achbotMessage('plans.administration.add_new_marzban_plan.message.80b8a92b1d'), json_encode([
                'inline_keyboard' => $srvkey]), "HTML");
        $inarr = 0;
        foreach ($catkey as $op) {
            if (in_array($text, $op) and $text != $buttonValues['cancel']) {
                $inarr = 1;
            }
        }
        if( $inarr==1 ){
            $input = explode(' - ',$text);
            $catid = $input[0];
            $stmt = $connection->prepare("UPDATE `server_plans` SET `catid`=?,`step`=50 WHERE `active`=0");
            $stmt->bind_param("i", $catid);
            $stmt->execute();
            $stmt->close();

            sendMessage($msg,$cancelKey);
        }else{
            $msg = \achbotMessage('plans.administration.add_new_marzban_plan.msg.8410eb0e5a');
            sendMessage($msg,$catkey);
        }
    }
    if ($step == 50 && $text != $buttonValues['cancel'] &&
        preg_match('/^selectNewPlanServer(\d+)$/D', (string)$data, $match)) {
        $newStep = 53; // Marzban-only plan wizard
        $stmt = $connection->prepare("UPDATE `server_plans` SET `server_id`=?,`step`=? WHERE `active`=0");
        $stmt->bind_param("ii", $match[1], $newStep);
        $stmt->execute();
        $stmt->close();
        editText($message_id, \achbotMessage('plans.administration.add_new_marzban_plan.message.07a98a2748'));
    }
    if($step==53 and $text!=$buttonValues['cancel']){
        if(!is_numeric($text)){
            sendMessage($mainValues['send_only_number']);
            exit();
        }

        $stmt = $connection->prepare("UPDATE `server_plans` SET `days`=?,`step`=54 WHERE `active`=0");
        $stmt->bind_param("i", $text);
        $stmt->execute();
        $stmt->close();

        sendMessage(\achbotMessage('plans.administration.add_new_marzban_plan.message.ad385328ac'));
    }
    if ($step == 54 && $text != $buttonValues['cancel']) {
        if (!is_numeric($text)) {
            sendMessage($mainValues['send_only_number']);
            exit();
        }
        $sql = "UPDATE `server_plans` SET `volume`=?, `type`='ws', `step`=4 WHERE `active`=0";
        $stmt = $connection->prepare($sql);
        $stmt->bind_param("d", $text);
        $stmt->execute();
        $stmt->close();
        sendMessage(\achbotMessage('plans.administration.add_new_marzban_plan.message.323da64e58'));
    }
    if($step==4 and $text!=$buttonValues['cancel']){


            $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `active` = 0 AND `step` = 4");
            $stmt->execute();
            $serverId = $stmt->get_result()->fetch_assoc()['server_id'];
            $stmt->close();

            $hosts = getMarzbanHosts($serverId)->inbounds;
            $networkType = array();
            foreach($hosts as $key => $inbound){
                $networkType[] = [['text'=>$inbound->tag, 'callback_data'=>"planNetworkType{$inbound->protocol}*_*{$inbound->tag}"]];
            }
            $networkType = json_encode(['inline_keyboard'=>$networkType]);

            $stmt = $connection->prepare("UPDATE `server_plans` SET `descr`=?, `step` = 5 WHERE `step` = 4");
            sendMessage(\achbotMessage('plans.administration.add_new_marzban_plan.message.2c4ec0c4cc'),$networkType);

        $stmt->bind_param("s", $text);
        $stmt->execute();
        $stmt->close();

    }
    elseif($step == 5 and $text != $buttonValues['cancel'] && preg_match('/^planNetworkType(?<protocol>.+)\*_\*(?<tag>.*)/',$data,$match)){
        $saveBtn = \achbotMessage('plans.administration.add_new_marzban_plan.save_btn.b3a82cd642');
        if($markup[count($markup)-1][0]['text'] == $saveBtn) unset($markup[count($markup)-1]);

        foreach($markup as $key => $keyboard){
            if($keyboard[0]['callback_data'] == $data) $markup[$key][0]['text'] = $keyboard['0']['text'] == $match['tag'] . \achbotMessage('plans.administration.add_new_marzban_plan.text.64554c955e') ? $match['tag']:$match['tag'] . \achbotMessage('plans.administration.add_new_marzban_plan.text.64554c955e');
        }

        if(strstr(json_encode($markup,JSON_UNESCAPED_UNICODE), \achbotMessage('plans.administration.add_new_marzban_plan.text.1d456424c3')) && !strstr(json_encode($markup,JSON_UNESCAPED_UNICODE), $saveBtn)){
            $markup[] = [['text'=>$saveBtn,'callback_data'=>"savePlanNetworkType"]];
        }
        $markup = json_encode(['inline_keyboard'=>array_values($markup)]);

        editKeys($markup);
    }
    elseif($step == 5 && $text != $buttonValues['cancel'] && $data == "savePlanNetworkType"){
        delMessage();
        $inbounds = array();
        $proxies = array();
        unset($markup[count($markup)-1]);

        foreach($markup as $key=>$value){
            $tag = trim(str_replace(\achbotMessage('plans.administration.add_new_marzban_plan.text.1d456424c3'), "", $value[0]['text'], $state));
            if($state > 0){
                preg_match('/^planNetworkType(?<protocol>.+)\*_\*(?<tag>.*)/',$value[0]['callback_data'],$info);
                $inbounds[$info['protocol']][] = $tag;
                $proxies[$info['protocol']] = array();

                if($info['protocol'] == "vless"){
                    $proxies["vless"] = ["flow" => ""];
                }
                elseif($info['protocol'] == "shadowsocks"){
                    $proxies["shadowsocks"] = ['method' => "chacha20-ietf-poly1305"];
                }
            }
        }

        $info = json_encode(['inbounds'=>$inbounds, 'proxies'=>$proxies]);
        $stmt = $connection->prepare("UPDATE `server_plans` SET `custom_sni`=?, `active`=1,`step`=10 WHERE `step`=5");
        $stmt->bind_param("s", $info);
        $stmt->execute();
        $stmt->close();

        $imgtxt = \achbotMessage('plans.administration.save_plan_network_type.imgtxt.057d44c004');
        sendMessage($imgtxt,$removeKeyboard);
        sendMessage($mainValues['reached_main_menu'],getAdminKeys());
        setUser();
    }
}
if($data == 'backplan' and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT si.* FROM `server_info` si INNER JOIN `server_config` sc ON sc.`id`=si.`id` AND sc.`type` IN ('marzban','pasarguard') WHERE si.`active`=1");
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    $keyboard = [];
    while($cat = $res->fetch_assoc()){
        $id = $cat['id'];
        $title = $cat['title'];
        $keyboard[] = ['text' => "$title", 'callback_data' => "plansList$id"];
    }
    $keyboard = array_chunk($keyboard,2);
    $keyboard[] = [['text'=>\achbotMessage('plans.administration.backplan.button.9708693ebe'),'callback_data'=>"wizwizch"]];
    $keyboard[] = [['text'=>\achbotMessage('plans.administration.backplan.button.89eacb5309'),'callback_data'=>"addNewMarzbanPlan"]];
    $keyboard[] = [['text'=>\achbotMessage('plans.administration.backplan.button.2abde87b2a'),'callback_data'=>"volumePlanSettings"],['text'=>\achbotMessage('plans.administration.backplan.button.92c1307fe6'),'callback_data'=>"dayPlanSettings"]];
    $keyboard[] = [['text' => \achbotMessage('plans.administration.backplan.button.d9172c2e21'), 'callback_data' => "editCustomPlan"]];
    $keyboard[] = [['text' => $buttonValues['back_button'], 'callback_data' => "managePanel"]];

    $msg = \achbotMessage('plans.administration.backplan.msg.20bb3eca34');

    if(isset($data) and $data=='backplan') {
        editText($message_id, $msg, json_encode(['inline_keyboard'=>$keyboard]));
    }else { sendAction('typing');
        sendmessage($msg, json_encode(['inline_keyboard'=>$keyboard]));
    }


    exit;
}
if(($data=="editCustomPlan" || preg_match('/^editCustom(gbPrice|dayPrice)/',$userInfo['step'],$match)) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if(!isset($data)){
        if(is_numeric($text)){
            setSettings($match[1], $text);
            sendMessage($mainValues['saved_successfuly'],$removeKeyboard);
        }else{
            sendMessage(\achbotMessage('plans.administration.edit_custom_plan.message.be59f23713'));
            exit();
        }
    }
    $gbPrice=number_format($botState['gbPrice']??0) . \achbotMessage('plans.administration.edit_custom_plan.text.2cc98f7f7c');
    $dayPrice=number_format($botState['dayPrice']??0) . \achbotMessage('plans.administration.edit_custom_plan.text.2cc98f7f7c');

    $keys = json_encode(['inline_keyboard'=>[
        [
            ['text'=>$gbPrice,'callback_data'=>"editCustomgbPrice"],
            ['text'=>\achbotMessage('plans.administration.edit_custom_plan.button.7a96416b62'),'callback_data'=>"wizwizch"]
            ],
        [
            ['text'=>$dayPrice,'callback_data'=>"editCustomdayPrice"],
            ['text'=>\achbotMessage('plans.administration.edit_custom_plan.button.31bc93a08d'),'callback_data'=>"wizwizch"]
            ],
        [
            ['text'=>$buttonValues['back_button'],'callback_data'=>"backplan"]
            ]

        ]]);
    if(!isset($data)){
        sendMessage(\achbotMessage('plans.administration.edit_custom_plan.message.e3b03f6c8e'),$keys);
        setUser();
    }else{
        editText($message_id,\achbotMessage('plans.administration.edit_custom_plan.message.e3b03f6c8e'),$keys);
    }
}
if(preg_match('/^editCustom(gbPrice|dayPrice)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    $title = $match[1] == "dayPrice"?\achbotMessage('plans.administration.edit_custom_plan.text.b641937e16'):\achbotMessage('plans.administration.edit_custom_plan.text.c84922eddf');
    sendMessage(\achbotMessage('plans.administration.edit_custom_plan.message.207313a746') . $title . \achbotMessage('plans.administration.edit_custom_plan.message.ae9d133be8'),$cancelKey);
    setUser($data);
}
if(preg_match('/plansList(\d+)/', $data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT p.* FROM `server_plans` p INNER JOIN `server_config` sc ON sc.`id`=p.`server_id` AND sc.`type` IN ('marzban','pasarguard') WHERE p.`server_id`=? AND p.`inbound_id`=0 ORDER BY p.`id` ASC");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    if($res->num_rows==0){
        alert(\achbotMessage('plans.administration.edit_custom_plan.alert.1633521d7d'));
        exit;
    }else {
        $keyboard = [];
        while($cat = $res->fetch_assoc()){
            $id = $cat['id'];
            $title = $cat['title'];
            $keyboard[] = ['text' => "#$id $title", 'callback_data' => "planDetails$id"];
        }
        $keyboard = array_chunk($keyboard,2);
        $keyboard[] = [['text' => $buttonValues['back_button'], 'callback_data' => "backplan"],];
        $msg = \achbotMessage('plans.administration.edit_custom_plan.msg.537f60e05a');
        editText($message_id, $msg, json_encode(['inline_keyboard'=>$keyboard]), "HTML");
    }
    exit();
}
if(preg_match('/planDetails(\d+)/', $data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $keys = getPlanDetailsKeys($match[1]);
    if($keys == null){
        alert(\achbotMessage('plans.administration.edit_custom_plan.alert.756c3005d3'));
        exit;
    }else editText($message_id, \achbotMessage('plans.administration.edit_custom_plan.message.06d5c646c1'), $keys, "HTML");
}
if(preg_match('/^wizwizplanacclist(\d+)/',$data,$match) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `status`=1 AND `fileid`=?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    if($res->num_rows == 0){
        alert(\achbotMessage('plans.administration.edit_custom_plan.alert.7aeabddffe'));
        exit;
    }
    $txt = '';
    while($order = $res->fetch_assoc()){
		$suid = $order['userid'];
		$stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid`=?");
        $stmt->bind_param("i", $suid);
        $stmt->execute();
        $ures = $stmt->get_result()->fetch_assoc();
        $stmt->close();


        $date = $order['date'];
        $remark = $order['remark'];
        $date = jdate('Y-m-d H:i', $date);
        $uname = $ures['name'];
        $sold = \achbotMessage('plans.administration.edit_custom_plan.sold.caead5bf3e').$uname. " ($date)";
        $accid = $order['id'];
        $orderLink = json_decode($order['link'],true);
        $txt = \achbotMessage('plans.administration.edit_custom_plan.sold_remark.908883cc30', ['sold' => $sold, 'remark' => $remark]);
        $server=getServerConfigById((int)$order['server_id']);
        $presentation=achbotOrderPresentation($connection,$order,$server,$botState);
        $txt .= "\n".$presentation['config_html']."\n".$presentation['subscription_html'].
            $presentation['backup_html']."\n".$presentation['notice_html'];
        $txt .= \achbotMessage('plans.administration.edit_custom_plan.channel_lock.5d72dee564', ['channelLock' => $channelLock]);
        sendMessage($txt, null, "HTML");
    }
}
if(preg_match('/^wizwizplandelete(\d+)/',$data,$match) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("DELETE FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();
    alert(\achbotMessage('plans.administration.edit_custom_plan.alert.3064bbd794'));

    editText($message_id,\achbotMessage('plans.administration.edit_custom_plan.message.b059640ccc'),getMainKeys());
}
if(preg_match('/^wizwizplanname(\d+)/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setUser($data);
    delMessage();
    sendMessage(\achbotMessage('plans.administration.edit_custom_plan.message.0253b1982e'),$cancelKey);exit;
}
if(preg_match('/^wizwizplanname(\d+)/',$userInfo['step'], $match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("UPDATE `server_plans` SET `title`=? WHERE `id`=?");
    $stmt->bind_param("si", $text, $match[1]);
    $stmt->execute();
    $stmt->close();

    sendMessage(\achbotMessage('plans.administration.edit_custom_plan.message.43afd491f6'));
    setUser();

    $keys = getPlanDetailsKeys($match[1]);
    if($keys == null){
        alert(\achbotMessage('plans.administration.edit_custom_plan.alert.756c3005d3'));
        exit;
    }else sendMessage(\achbotMessage('plans.administration.edit_custom_plan.message.06d5c646c1'), $keys);
}
if(preg_match('/^wizwizplanslimit(\d+)/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setUser($data);
    delMessage();
    sendMessage(\achbotMessage('plans.administration.edit_custom_plan.message.323e773e36'),$cancelKey);exit;
}
if(preg_match('/^wizwizplanslimit(\d+)/',$userInfo['step'], $match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("UPDATE `server_plans` SET `acount`=? WHERE `id`=?");
    $stmt->bind_param("ii", $text, $match[1]);
    $stmt->execute();
    $stmt->close();

    sendMessage(\achbotMessage('plans.administration.edit_custom_plan.message.43afd491f6'));
    setUser();

    $keys = getPlanDetailsKeys($match[1]);
    if($keys == null){
        alert(\achbotMessage('plans.administration.edit_custom_plan.alert.756c3005d3'));
        exit;
    }else sendMessage(\achbotMessage('plans.administration.edit_custom_plan.message.06d5c646c1'), $keys, "HTML");
}
if(preg_match('/^wizwizplansinobundid(\d+)/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setUser($data);
    delMessage();
    sendMessage(\achbotMessage('plans.administration.edit_custom_plan.message.4ec681ba73'),$cancelKey);exit;
}
if(preg_match('/^wizwizplansinobundid(\d+)/',$userInfo['step'], $match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    $stmt = $connection->prepare("UPDATE `server_plans` SET `inbound_id`=? WHERE `id`=?");
    $stmt->bind_param("ii", $text, $match[1]);
    $stmt->execute();
    $stmt->close();

    sendMessage(\achbotMessage('plans.administration.edit_custom_plan.message.43afd491f6'));
    setUser();

    $keys = getPlanDetailsKeys($match[1]);
    if($keys == null){
        alert(\achbotMessage('plans.administration.edit_custom_plan.alert.756c3005d3'));
        exit;
    }else sendMessage(\achbotMessage('plans.administration.edit_custom_plan.message.06d5c646c1'), $keys, "HTML");
}
if(preg_match('/^wizwizplaneditdes(\d+)/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setUser($data);
    delMessage();
    sendMessage(\achbotMessage('plans.administration.edit_custom_plan.message.090fe8ddfd'),$cancelKey);exit;
}
if(preg_match('/^wizwizplaneditdes(\d+)/',$userInfo['step'], $match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    $stmt = $connection->prepare("UPDATE `server_plans` SET `descr`=? WHERE `id`=?");
    $stmt->bind_param("si", $text, $match[1]);
    $stmt->execute();
    $stmt->close();


    sendMessage(\achbotMessage('plans.administration.edit_custom_plan.message.43afd491f6'));
    setUser();

    $keys = getPlanDetailsKeys($match[1]);
    if($keys == null){
        alert(\achbotMessage('plans.administration.edit_custom_plan.alert.756c3005d3'));
        exit;
    }else sendMessage(\achbotMessage('plans.administration.edit_custom_plan.message.06d5c646c1'), $keys, "HTML");
}
if(preg_match('/^editDestName(\d+)/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setUser($data);
    delMessage();
    sendMessage(\achbotMessage('plans.administration.edit_custom_plan.message.2c3264a209'),$cancelKey);exit;
}
if(preg_match('/^editDestName(\d+)/',$userInfo['step'], $match) && ($from_id == $admin || $userInfo['isAdmin'] == true) &&  $text != $buttonValues['cancel']){
    if($text == "/empty"){
        $stmt = $connection->prepare("UPDATE `server_plans` SET `dest` = NULL WHERE `id`=?");
        $stmt->bind_param("i", $match[1]);
    }else{
        $stmt = $connection->prepare("UPDATE `server_plans` SET `dest`=? WHERE `id`=?");
        $stmt->bind_param("si", $text, $match[1]);
    }
    $stmt->execute();
    $stmt->close();


    sendMessage(\achbotMessage('plans.administration.edit_custom_plan.message.43afd491f6'));
    setUser();

    $keys = getPlanDetailsKeys($match[1]);
    if($keys == null){
        alert(\achbotMessage('plans.administration.edit_custom_plan.alert.756c3005d3'));
        exit;
    }else sendMessage(\achbotMessage('plans.administration.edit_custom_plan.message.06d5c646c1'), $keys, "HTML");
}
if(preg_match('/^editSpiderX(\d+)/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setUser($data);
    delMessage();
    sendMessage(\achbotMessage('plans.administration.edit_custom_plan.message.bd5882ea41'),$cancelKey);exit;
}
if(preg_match('/^editSpiderX(\d+)/',$userInfo['step'], $match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if($text == "/empty"){
        $stmt = $connection->prepare("UPDATE `server_plans` SET `spiderX`=NULL WHERE `id`=?");
        $stmt->bind_param("s", $match[1]);
    }else{
        $stmt = $connection->prepare("UPDATE `server_plans` SET `spiderX`=? WHERE `id`=?");
        $stmt->bind_param("si", $text, $match[1]);
    }
    $stmt->execute();
    $stmt->close();


    sendMessage(\achbotMessage('plans.administration.edit_custom_plan.message.43afd491f6'));
    setUser();

    $keys = getPlanDetailsKeys($match[1]);
    if($keys == null){
        alert(\achbotMessage('plans.administration.edit_custom_plan.alert.756c3005d3'));
        exit;
    }else sendMessage(\achbotMessage('plans.administration.edit_custom_plan.message.06d5c646c1'), $keys, "HTML");
}
if(preg_match('/^editServerNames(\d+)/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setUser($data);
    delMessage();
    sendMessage(\achbotMessage('plans.administration.edit_custom_plan.message.7b314b58c9'),$cancelKey);exit;
}
if(preg_match('/^editServerNames(\d+)/',$userInfo['step'], $match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if($text == "/empty"){
        $stmt = $connection->prepare("UPDATE `server_plans` SET `serverNames`=NULL WHERE `id`=?");
        $stmt->bind_param("s", $match[1]);
    }else{
        $stmt = $connection->prepare("UPDATE `server_plans` SET `serverNames`=? WHERE `id`=?");
        $stmt->bind_param("si", $text, $match[1]);
    }
    $stmt->execute();
    $stmt->close();


    sendMessage(\achbotMessage('plans.administration.edit_custom_plan.message.43afd491f6'));
    setUser();

    $keys = getPlanDetailsKeys($match[1]);
    if($keys == null){
        alert(\achbotMessage('plans.administration.edit_custom_plan.alert.756c3005d3'));
        exit;
    }else sendMessage(\achbotMessage('plans.administration.edit_custom_plan.message.06d5c646c1'), $keys, "HTML");
}
if(preg_match('/^editFlow(\d+)/',$data, $match) and ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setUser($data);
    delMessage();
    $keys = json_encode(['inline_keyboard'=>[
        [['text'=>\achbotMessage('plans.administration.edit_custom_plan.none.dc937b5989'), 'callback_data'=>"editPFlow" . $match[1] . "_None"]],
        [['text'=>\achbotMessage('plans.administration.edit_custom_plan.xtls_rprx_vision.848b11de44'), 'callback_data'=>"editPFlow" . $match[1] . "_xtls-rprx-vision"]],
        ]]);
    sendMessage(\achbotMessage('plans.administration.edit_custom_plan.message.946863c2fa'),$keys);exit;
}
if(preg_match('/^editPFlow(\d+)_(.*)/',$data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    $stmt = $connection->prepare("UPDATE `server_plans` SET `flow`=? WHERE `id`=?");
    $stmt->bind_param("si", $match[2], $match[1]);
    $stmt->execute();
    $stmt->close();

    alert(\achbotMessage('plans.administration.edit_custom_plan.message.43afd491f6'));
    setUser();

    $keys = getPlanDetailsKeys($match[1]);
    editText($message_id, \achbotMessage('plans.administration.edit_custom_plan.message.06d5c646c1'), $keys, "HTML");
}
if(preg_match('/^wizwizplanrial(\d+)/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setUser($data);
    delMessage();
    sendMessage(\achbotMessage('plans.administration.edit_custom_plan.message.031fdf541a'),$cancelKey);exit;
}
if(preg_match('/^wizwizplanrial(\d+)/',$userInfo['step'], $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)&& $text != $buttonValues['cancel']){
    if(is_numeric($text)){
        $stmt = $connection->prepare("UPDATE `server_plans` SET `price`=? WHERE `id`=?");
        $stmt->bind_param("ii", $text, $match[1]);
        $stmt->execute();
        $stmt->close();

        sendMessage(\achbotMessage('plans.administration.edit_custom_plan.message.43afd491f6'));
        setUser();

        $keys = getPlanDetailsKeys($match[1]);
        if($keys == null){
            alert(\achbotMessage('plans.administration.edit_custom_plan.alert.756c3005d3'));
            exit;
        }else sendMessage(\achbotMessage('plans.administration.edit_custom_plan.message.06d5c646c1'), $keys, "HTML");
    }else{
        sendMessage(\achbotMessage('plans.administration.edit_custom_plan.message.923b819fc4'));
    }
}

// ✅ تابع کمکی کیبورد لغو برای حالت "یافت نشد"
