<?php
/** AChBot V7: immutable V6.1 purchase or plan route. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

// A cached admin keyboard may still send a former X-UI plan callback.
if (in_array((string)$data, ['addNewPlan','addNewRahgozarPlan'], true) &&
    ($from_id == $admin || ($userInfo['isAdmin'] ?? false) == true)) {
    alert("ثبت پلن پنل قدیمی حذف شده است؛ از پلن مرزبان استفاده کنید.", true);
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
    $msg = '❗️یه عنوان برا پلن انتخاب کن:';
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
        $msg = '🔰 لطفا قیمت پلن رو به تومان وارد کنید!';
        if(strlen($text)>1){
            $stmt = $connection->prepare("UPDATE `server_plans` SET `title`=?,`step`=2 WHERE `active`=0 and `step`=1");
            $stmt->bind_param("s", $text);
            $stmt->execute();
            $stmt->close();
            sendMessage($msg,$cancelKey);
        }
    }
    if($step==2 and $text!=$buttonValues['cancel']){
        $msg = '🔰 لطفا یه دسته از لیست زیر برا پلن انتخاب کن ';
        if(is_numeric($text)){
            $stmt = $connection->prepare("UPDATE `server_plans` SET `price`=?,`step`=3 WHERE `active`=0");
            $stmt->bind_param("s", $text);
            $stmt->execute();
            $stmt->close();
            sendMessage($msg,json_encode(['keyboard'=>$catkey,'resize_keyboard'=>true]));
        }else{
            $msg = '‼️ لطفا یک مقدار عددی وارد کنید.';
            sendMessage($msg,$cancelKey);
        }
    }
    if($step==3 and $text!=$buttonValues['cancel']){
        $srvkey = [];

        $stmt = $connection->prepare("SELECT `id` FROM `server_config` WHERE `type` = 'marzban'");
        $stmt->execute();
        $info = $stmt->get_result()->fetch_all();
        $stmt->close();



        $marzbanList = array_column($info, 0);
        if(count($marzbanList) > 0) $condition  = " AND `id` " .($userInfo['step'] == "addNewMarzbanPlan"?"IN":"NOT IN") . " (" . implode(", ", $marzbanList) . ")";
        else $condition = "";


        $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `active`=1 $condition");
        $stmt->execute();

        $srvs = $stmt->get_result();
        $stmt->close();
        sendMessage($mainValues['please_wait_message'],$cancelKey);
        while($srv = $srvs->fetch_assoc()){
            $id = $srv['id'];
            $title = $srv['title'];
            $srvkey[] = ['text' => "$title", 'callback_data' => "selectNewPlanServer$id"];
        }
        $srvkey = array_chunk($srvkey,2);
        sendMessage("لطفا یکی از سرورها رو انتخاب کن 👇 ", json_encode([
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
            $msg = '‼️ لطفا فقط یکی از گزینه های پیشنهادی زیر را انتخاب کنید.';
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
        editText($message_id, "📅 لطفا تعداد روز های اعتبار این پلن را وارد کنید:");
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

        sendMessage("🔋 لطفا مقدار حجم به GB این پلن را وارد کنید:");
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
        sendMessage('🔻یه توضیح برای پلن مورد نظرت بنویس:');
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
            sendMessage("لطفا نوع شبکه های این پلن را انتخاب کنید.",$networkType);

        $stmt->bind_param("s", $text);
        $stmt->execute();
        $stmt->close();

    }
    elseif($step == 5 and $text != $buttonValues['cancel'] && preg_match('/^planNetworkType(?<protocol>.+)\*_\*(?<tag>.*)/',$data,$match)){
        $saveBtn = "ذخیره ✅";
        if($markup[count($markup)-1][0]['text'] == $saveBtn) unset($markup[count($markup)-1]);

        foreach($markup as $key => $keyboard){
            if($keyboard[0]['callback_data'] == $data) $markup[$key][0]['text'] = $keyboard['0']['text'] == $match['tag'] . " ✅" ? $match['tag']:$match['tag'] . " ✅";
        }

        if(strstr(json_encode($markup,JSON_UNESCAPED_UNICODE), "✅") && !strstr(json_encode($markup,JSON_UNESCAPED_UNICODE), $saveBtn)){
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
            $tag = trim(str_replace("✅", "", $value[0]['text'], $state));
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

        $imgtxt = '☑️ پنل با موفقیت ثبت و ایجاد شد.';
        sendMessage($imgtxt,$removeKeyboard);
        sendMessage($mainValues['reached_main_menu'],getAdminKeys());
        setUser();
    }
}
if($data == 'backplan' and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `active`=1");
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
    $keyboard[] = [['text'=>"➖➖➖",'callback_data'=>"wizwizch"]];
    $keyboard[] = [['text'=>"➕ افزودن پلن مرزبان",'callback_data'=>"addNewMarzbanPlan"]];
    $keyboard[] = [['text'=>'➕ افزودن پلن حجمی','callback_data'=>"volumePlanSettings"],['text'=>'➕ افزودن پلن زمانی','callback_data'=>"dayPlanSettings"]];
    $keyboard[] = [['text' => "➕ افزودن پلن دلخواه", 'callback_data' => "editCustomPlan"]];
    $keyboard[] = [['text' => $buttonValues['back_button'], 'callback_data' => "managePanel"]];

    $msg = '☑️ مدیریت پلن ها:';

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
            sendMessage("فقط عدد ارسال کن");
            exit();
        }
    }
    $gbPrice=number_format($botState['gbPrice']??0) . " تومان";
    $dayPrice=number_format($botState['dayPrice']??0) . " تومان";

    $keys = json_encode(['inline_keyboard'=>[
        [
            ['text'=>$gbPrice,'callback_data'=>"editCustomgbPrice"],
            ['text'=>"هزینه هر گیگ",'callback_data'=>"wizwizch"]
            ],
        [
            ['text'=>$dayPrice,'callback_data'=>"editCustomdayPrice"],
            ['text'=>"هزینه هر روز",'callback_data'=>"wizwizch"]
            ],
        [
            ['text'=>$buttonValues['back_button'],'callback_data'=>"backplan"]
            ]

        ]]);
    if(!isset($data)){
        sendMessage("تنظیمات پلن دلخواه",$keys);
        setUser();
    }else{
        editText($message_id,"تنظیمات پلن دلخواه",$keys);
    }
}
if(preg_match('/^editCustom(gbPrice|dayPrice)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    $title = $match[1] == "dayPrice"?"هر روز":"هر گیگ";
    sendMessage("لطفا هزینه " . $title . " را به تومان وارد کنید.",$cancelKey);
    setUser($data);
}
if(preg_match('/plansList(\d+)/', $data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `server_id`=? ORDER BY`id` ASC");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    if($res->num_rows==0){
        alert("متاسفانه، هیچ پلنی براش انتخاب نکردی 😑");
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
        $msg = ' ▫️ یه پلن رو انتخاب کن بریم برای ویرایش:';
        editText($message_id, $msg, json_encode(['inline_keyboard'=>$keyboard]), "HTML");
    }
    exit();
}
if(preg_match('/planDetails(\d+)/', $data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $keys = getPlanDetailsKeys($match[1]);
    if($keys == null){
        alert("موردی یافت نشد.");
        exit;
    }else editText($message_id, "ویرایش تنظیمات پلن:", $keys, "HTML");
}
if(preg_match('/^wizwizplanacclist(\d+)/',$data,$match) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `status`=1 AND `fileid`=?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    if($res->num_rows == 0){
        alert('لیست خالی است.');
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
        $sold = " 🚀 ".$uname. " ($date)";
        $accid = $order['id'];
        $orderLink = json_decode($order['link'],true);
        $txt = "$sold \n  ☑️ $remark ";
        foreach($orderLink as $link){
            $txt .= $botState['configLinkState'] != "off"?"<code>".$link."</code> \n":"";
        }
        $txt .= "\n ❗ $channelLock \n";
        sendMessage($txt, null, "HTML");
    }
}
if(preg_match('/^wizwizplandelete(\d+)/',$data,$match) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("DELETE FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();
    alert("پلن رو برات حذفش کردم ☹️☑️");

    editText($message_id,"لطفا یکی از کلید های زیر را انتخاب کنید.",getMainKeys());
}
if(preg_match('/^wizwizplanname(\d+)/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setUser($data);
    delMessage();
    sendMessage("🔅 یه اسم برا پلن جدید انتخاب کن:",$cancelKey);exit;
}
if(preg_match('/^wizwizplanname(\d+)/',$userInfo['step'], $match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("UPDATE `server_plans` SET `title`=? WHERE `id`=?");
    $stmt->bind_param("si", $text, $match[1]);
    $stmt->execute();
    $stmt->close();

    sendMessage("☑️ با موفقیت برات تغییر دادم.");
    setUser();

    $keys = getPlanDetailsKeys($match[1]);
    if($keys == null){
        alert("موردی یافت نشد.");
        exit;
    }else sendMessage("ویرایش تنظیمات پلن:", $keys);
}
if(preg_match('/^wizwizplanslimit(\d+)/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setUser($data);
    delMessage();
    sendMessage("🔅 ظرفیت جدید برای پلن انتخاب کن:",$cancelKey);exit;
}
if(preg_match('/^wizwizplanslimit(\d+)/',$userInfo['step'], $match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("UPDATE `server_plans` SET `acount`=? WHERE `id`=?");
    $stmt->bind_param("ii", $text, $match[1]);
    $stmt->execute();
    $stmt->close();

    sendMessage("☑️ با موفقیت برات تغییر دادم.");
    setUser();

    $keys = getPlanDetailsKeys($match[1]);
    if($keys == null){
        alert("موردی یافت نشد.");
        exit;
    }else sendMessage("ویرایش تنظیمات پلن:", $keys, "HTML");
}
if(preg_match('/^wizwizplansinobundid(\d+)/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setUser($data);
    delMessage();
    sendMessage("🔅 سطر جدید برای پلن انتخاب کن:",$cancelKey);exit;
}
if(preg_match('/^wizwizplansinobundid(\d+)/',$userInfo['step'], $match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    $stmt = $connection->prepare("UPDATE `server_plans` SET `inbound_id`=? WHERE `id`=?");
    $stmt->bind_param("ii", $text, $match[1]);
    $stmt->execute();
    $stmt->close();

    sendMessage("☑️ با موفقیت برات تغییر دادم.");
    setUser();

    $keys = getPlanDetailsKeys($match[1]);
    if($keys == null){
        alert("موردی یافت نشد.");
        exit;
    }else sendMessage("ویرایش تنظیمات پلن:", $keys, "HTML");
}
if(preg_match('/^wizwizplaneditdes(\d+)/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setUser($data);
    delMessage();
    sendMessage("🎯 توضیحاتت رو برام وارد کن:",$cancelKey);exit;
}
if(preg_match('/^wizwizplaneditdes(\d+)/',$userInfo['step'], $match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    $stmt = $connection->prepare("UPDATE `server_plans` SET `descr`=? WHERE `id`=?");
    $stmt->bind_param("si", $text, $match[1]);
    $stmt->execute();
    $stmt->close();


    sendMessage("☑️ با موفقیت برات تغییر دادم.");
    setUser();

    $keys = getPlanDetailsKeys($match[1]);
    if($keys == null){
        alert("موردی یافت نشد.");
        exit;
    }else sendMessage("ویرایش تنظیمات پلن:", $keys, "HTML");
}
if(preg_match('/^editDestName(\d+)/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setUser($data);
    delMessage();
    sendMessage("🎯 dest رو برام وارد کن:\nبرای حذف کردن متن /empty رو وارد کن",$cancelKey);exit;
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


    sendMessage("☑️ با موفقیت برات تغییر دادم.");
    setUser();

    $keys = getPlanDetailsKeys($match[1]);
    if($keys == null){
        alert("موردی یافت نشد.");
        exit;
    }else sendMessage("ویرایش تنظیمات پلن:", $keys, "HTML");
}
if(preg_match('/^editSpiderX(\d+)/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setUser($data);
    delMessage();
    sendMessage("🎯 spiderX رو برام وارد کن\nبرای حذف کردن متن /empty رو وارد کن",$cancelKey);exit;
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


    sendMessage("☑️ با موفقیت برات تغییر دادم.");
    setUser();

    $keys = getPlanDetailsKeys($match[1]);
    if($keys == null){
        alert("موردی یافت نشد.");
        exit;
    }else sendMessage("ویرایش تنظیمات پلن:", $keys, "HTML");
}
if(preg_match('/^editServerNames(\d+)/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setUser($data);
    delMessage();
    sendMessage("🎯 serverNames رو به صورت زیر برام وارد کن:\n
`[
  \"yahoo.com\",
  \"www.yahoo.com\"
]`
    \n\nبرای حذف کردن متن /empty رو وارد کن",$cancelKey);exit;
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


    sendMessage("☑️ با موفقیت برات تغییر دادم.");
    setUser();

    $keys = getPlanDetailsKeys($match[1]);
    if($keys == null){
        alert("موردی یافت نشد.");
        exit;
    }else sendMessage("ویرایش تنظیمات پلن:", $keys, "HTML");
}
if(preg_match('/^editFlow(\d+)/',$data, $match) and ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setUser($data);
    delMessage();
    $keys = json_encode(['inline_keyboard'=>[
        [['text'=>"None", 'callback_data'=>"editPFlow" . $match[1] . "_None"]],
        [['text'=>"xtls-rprx-vision", 'callback_data'=>"editPFlow" . $match[1] . "_xtls-rprx-vision"]],
        ]]);
    sendMessage("🎯 لطفا یکی از موارد زیر رو انتخاب کن",$keys);exit;
}
if(preg_match('/^editPFlow(\d+)_(.*)/',$data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    $stmt = $connection->prepare("UPDATE `server_plans` SET `flow`=? WHERE `id`=?");
    $stmt->bind_param("si", $match[2], $match[1]);
    $stmt->execute();
    $stmt->close();

    alert("☑️ با موفقیت برات تغییر دادم.");
    setUser();

    $keys = getPlanDetailsKeys($match[1]);
    editText($message_id, "ویرایش تنظیمات پلن:", $keys, "HTML");
}
if(preg_match('/^wizwizplanrial(\d+)/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setUser($data);
    delMessage();
    sendMessage("🎯 لطفا قیمت جدید را وارد کنید:",$cancelKey);exit;
}
if(preg_match('/^wizwizplanrial(\d+)/',$userInfo['step'], $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)&& $text != $buttonValues['cancel']){
    if(is_numeric($text)){
        $stmt = $connection->prepare("UPDATE `server_plans` SET `price`=? WHERE `id`=?");
        $stmt->bind_param("ii", $text, $match[1]);
        $stmt->execute();
        $stmt->close();

        sendMessage("☑️ با موفقیت برات تغییر دادم.");
        setUser();

        $keys = getPlanDetailsKeys($match[1]);
        if($keys == null){
            alert("موردی یافت نشد.");
            exit;
        }else sendMessage("ویرایش تنظیمات پلن:", $keys, "HTML");
    }else{
        sendMessage("لطفا قیمت را به صورت عددی وارد کنید.");
    }
}

// ✅ تابع کمکی کیبورد لغو برای حالت "یافت نشد"
