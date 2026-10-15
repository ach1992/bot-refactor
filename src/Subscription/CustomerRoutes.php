<?php
/** AChBot V6: original Telegram account/subscription routes; keep caller scope. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

function cancelSearchKeyboard() {
    return json_encode([
        'inline_keyboard' => [
            [
                ['text' => '❌ لغو عملیات', 'callback_data' => 'cancelSearchConfig']
            ]
        ]
    ]);
}

// ✅ هندلر دکمه لغو عملیات برای سرچ
if ($data == "cancelSearchConfig") {
    delMessage();
    setUser();
    sendMessage($mainValues['reached_main_menu'], getMainKeys());
    exit;
}

if (
    $data == 'mySubscriptions' ||
    $data == 'agentConfigsList' ||
    preg_match('/(changeAgentOrder|changeOrdersPage)(\d+)/', $data, $match)
) {
    $results_per_page = 50;
    $action = $match[1] ?? null;
    $page = $match[2] ?? 1;

    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `userid`=? AND `status`=1");
    $stmt->bind_param("i", $from_id);
    $stmt->execute();
    $number_of_result = $stmt->get_result()->num_rows;
    $stmt->close();

    $number_of_page = ceil($number_of_result / $results_per_page);
    $page_first_result = ($page - 1) * $results_per_page;

    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `userid`=? AND `status`=1 ORDER BY `id` DESC LIMIT ?, ?");
    $stmt->bind_param("iii", $from_id, $page_first_result, $results_per_page);
    $stmt->execute();
    $orders = $stmt->get_result();
    $stmt->close();

    if ($orders->num_rows == 0) {
        alert($mainValues['you_dont_have_config']);
        exit;
    }

    $keyboard = [];
    while ($cat = $orders->fetch_assoc()) {
        $id = $cat['id'];
        $remark = $cat['remark'];
        $keyboard[] = ['text' => $remark, 'callback_data' => "orderDetails$id"];
    }

    $keyboard = array_chunk($keyboard, 1);

    $prev = $page - 1;
    $next = $page + 1;

    $isAgentConfig = ($data == "agentConfigsList" || $action == "changeAgentOrder");

    $buttons = [];
    if ($prev > 0) {
        $buttons[] = [
            'text' => "◀",
            'callback_data' => $isAgentConfig ? "changeAgentOrder$prev" : "changeOrdersPage$prev"
        ];
    }

    if ($page < $number_of_page) {
        $buttons[] = [
            'text' => "➡",
            'callback_data' => $isAgentConfig ? "changeAgentOrder$next" : "changeOrdersPage$next"
        ];
    }

    if (!empty($buttons)) {
        $keyboard[] = $buttons;
    }

    if ($isAgentConfig) {
        $keyboard[] = [[
            'text' => $buttonValues['search_agent_config'],
            'callback_data' => "searchAgentConfig"
        ]];
    }
    // else {
    //     $keyboard[] = [[
    //         'text' => $buttonValues['search_agent_config'],
    //         'callback_data' => "searchMyConfig"
    //     ]];
    // }

    $keyboard[] = [[
        'text' => $buttonValues['back_to_main'],
        'callback_data' => "mainMenu"
    ]];

    if (isset($data)) {
        editText(
            $message_id,
            $mainValues['select_one_to_show_detail'],
            json_encode(['inline_keyboard' => $keyboard])
        );
    } else {
        sendAction('typing');
        sendMessage(
            $mainValues['select_one_to_show_detail'],
            json_encode(['inline_keyboard' => $keyboard])
        );
    }

    exit;
}

if($data=="searchAgentConfig" || $data == "searchMyConfig" || $data=="searchUsersConfig"){
    //delMessage();
    sendMessage($mainValues['send_config_remark'],$cancelKey);
    setUser($data);
}


if(($userInfo['step'] == "searchAgentConfig" || $userInfo['step'] == "searchMyConfig") && $text != $buttonValues['cancel']){
    sendMessage($mainValues['please_wait_message'], $removeKeyboard);

    if($userInfo['step'] == "searchMyConfig") $condition = "AND `agent_bought` = 0";
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `userid` = ? AND `remark` LIKE CONCAT('%', ?, '%') $condition");
    $stmt->bind_param("is", $from_id, $text);
    $stmt->execute();
    $orderId = $stmt->get_result()->fetch_assoc()['id'];
    $stmt->close();
    
    $keys = getOrderDetailKeys($from_id, $orderId);

    if($keys == null) sendMessage($mainValues['no_order_found'], cancelSearchKeyboard()); 
    else {
        sendMessage($keys['msg'], $keys['keyboard'], "HTML");
        setUser();
    }
}

if(($userInfo['step'] == "searchUsersConfig" && $text != $buttonValues['cancel']) || preg_match('/^userOrderDetails(\d+)_(\d+)/',$data,$match)){
    if(isset($data)){
        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
        $stmt->bind_param("i", $match[1]);
    }
    else{
        sendMessage($mainValues['please_wait_message'], $removeKeyboard); 
        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `remark` LIKE CONCAT('%', ?, '%')");
        $stmt->bind_param("s", $text);
    }

    $stmt->execute();
    $orderInfo = $stmt->get_result();
    $stmt->close();

    if($orderInfo->num_rows == 0) sendMessage($mainValues['no_order_found'], cancelSearchKeyboard()); 
    else {
        $orderId = $orderInfo->fetch_assoc()['id'];
        $keys = getUserOrderDetailKeys($orderId, isset($data)?$match[2]:0);

        if($keys == null) sendMessage($mainValues['no_order_found'], cancelSearchKeyboard()); 
        else{
            if(!isset($data)) sendMessage($keys['msg'], $keys['keyboard'], "HTML");
            else editText($message_id, $keys['msg'], $keys['keyboard'], "HTML");
            setUser();
        }
    }
}

if(preg_match('/^orderDetails(\d+)(_|)(?<offset>\d+|)/', $data, $match)){
    $keys = getOrderDetailKeys(
        $from_id,
        $match[1],
        !empty($match['offset']) ? $match['offset'] : 0
    );

    if ($keys == null) {
        alert($mainValues['no_order_found'], true);
        exit;
    } else {
        editText($message_id, $keys['msg'], $keys['keyboard'], "HTML");
        exit;
    }
}

if($data=="cantEditGrpc"){
    alert("نمی توانید نوع این اشتراک را تغییر دهید.");
    exit();
}
if(preg_match('/^changeCustomPort(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("لطفا پورت مورد نظر خود را وارد کنید.\nبرای حذف پورت دلخواه عدد 0 را وارد کنید.", $cancelKey);
    setUser($data);
}
if(preg_match('/^changeCustomPort(\d+)/',$userInfo['step'],$match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if(is_numeric($text)){
        $stmt = $connection->prepare("UPDATE `server_plans` SET `custom_port`= ? WHERE `id` = ?");
        $stmt->bind_param("ii", $text, $match[1]);
        $stmt->execute();
        $stmt->close();  
        sendMessage($mainValues['saved_successfuly'],$removeKeyboard);
         
        sendMessage("ویرایش تنظیمات پلن:", getPlanDetailsKeys($match[1]));
        setUser();
    }else sendMessage($mainValues['send_only_number']);
}
if(preg_match('/^changeCustomSni(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("لطفا sni مورد نظر خود را وارد کنید.\nبرای حذف متن /empty را وارد کنید.", $cancelKey);
    setUser($data);
}
if(preg_match('/^changeCustomSni(\d+)/',$userInfo['step'],$match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if($text == "/empty"){
        $stmt = $connection->prepare("UPDATE `server_plans` SET `custom_sni`= NULL WHERE `id` = ?");
        $stmt->bind_param("i", $match[1]);
    }
    else {
        $stmt = $connection->prepare("UPDATE `server_plans` SET `custom_sni`= ? WHERE `id` = ?");
        $stmt->bind_param("si", $text, $match[1]);
    }
    $stmt->execute();
    $stmt->close();  
    sendMessage($mainValues['saved_successfuly'],$removeKeyboard);
     
    sendMessage("ویرایش تنظیمات پلن:", getPlanDetailsKeys($match[1]));
    setUser();
}
if(preg_match('/^changeCustomPath(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("UPDATE `server_plans` SET `custom_path` = IF(`custom_path` = 1, 0, 1) WHERE `id` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();
    
    editKeys(getPlanDetailsKeys($match[1]));
}
// Retired inbound/X-UI network-mode callbacks have no Marzban analogue.
if(preg_match('/changeNetworkType(\d+)_(\d+)/', $data, $match)){
    alert("تغییر نوع شبکه در پنل قدیمی دیگر پشتیبانی نمی‌شود.", true);
    return;
}
if($data=="changeProtocolIsDisable"){
    alert("تغییر پروتکل غیر فعال است.");
}

if(preg_match('/updateConfigConnectionLink(\d+)/', $data,$match)){
    alert($mainValues['please_wait_message']);
    $oid = $match[1];

    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id`=?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$order || !achbotSupportsMarzbanOrder($connection, (int)$oid)) {
        alert("⛔️ این سفارش در پنل مرزبان پشتیبانی نمی‌شود.", true);
        return;
    }
    $remark = $order['remark'];
    $uuid = $order['uuid']??"0";
    $inboundId = $order['inbound_id'];
    $server_id = $order['server_id'];
    $file_id = $order['fileid'];
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $file_id);
    $stmt->execute();
    $file_detail = $stmt->get_result()->fetch_assoc();

    $rahgozar = $order['rahgozar'];
    $customPath = $file_detail['custom_path'];
    $customPort = $file_detail['custom_port'];
    $customSni = $file_detail['custom_sni'];
    
    $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id`=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_config = $stmt->get_result()->fetch_assoc();
    $serverType = $server_config['type'];
    $netType = $file_detail['type'];
    $protocol = $file_detail['protocol'];
    $security = $server_config['security'];
    
    $success = false; // Flag برای موفقیت آمیز بودن عملیات
    
    if (achbotSupportsMarzbanAccount($serverType, $inboundId)) {
        $info = getMarzbanUser($server_id, $remark);
        if(isset($info->links) && !empty($info->links)) {
            $vraylink = $info->links;
            $success = true;
        } else {
            $vraylink = "خطا در دریافت لینک";
        }
    } else {
        alert("⛔️ پنل این اشتراک دیگر پشتیبانی نمی‌شود.", true);
        return;
        }
    
    if($success) {
        // ذخیره لینک در دیتابیس
        $vray_link = json_encode($vraylink);
        $stmt = $connection->prepare("UPDATE `orders_list` SET `link`=? WHERE `id`=?");
        $stmt->bind_param("si", $vray_link, $oid);
        $stmt->execute();
        $stmt->close();
        
        // ارسال پیام موفقیت با HTML
        $successMessage = "<b>✅ بروزرسانی موفق بود!</b>\n\n"
            . "⭕️ دقت کنید باید لینک اشتراک را دوباره وارد برنامه کنید یا داخل برنامه نیز بروزرسانی بزنید.\n\n"
            . "🔄 <b>در صورتی که کانفیگ ها با لینک اشتراک اصلی یا کمکی برای شما لود نمی شوند، لطفاً مراحل زیر را انجام دهید:</b>\n\n"
            . "1. کانفیگ های قدیمی را از نرم‌افزار خود حذف کنید.\n"
            . "2. کانفیگ های جدید را از قسمت بالا کپی کنید.\n"
            . "3. کانفیگ ها را به نرم‌افزار اضافه کنید.\n\n"
            . "📱 <i>برای اتصال موفق، حتماً از آخرین نسخه نرم‌افزار استفاده نمایید.</i>\n"
			. "⚠️ <b>بعد اتصال به کمک کانفیگ های تکی، برای اینکه همه کانفیگ ها را داشته باشید حتما دوباره با لینک اشتراک وارد کنید.</b>";
        
        // استفاده از parse_mode: "HTML" برای نمایش متن بولد شده
        sendMessage($successMessage, null, "HTML");
        
    } else {
        // ارسال پیام خطا
        $errorMessage = "<b>❌ خطا در بروزرسانی</b>\n\n"
            . "متأسفانه عملیات بروزرسانی با مشکل مواجه شد.\n"
            . "لطفاً مجدداً تلاش کنید یا با پشتیبانی تماس بگیرید.";
        
        sendMessage($errorMessage, null, "HTML");
    }
    
    $keys = getOrderDetailKeys($from_id, $oid);
    editText($message_id, $keys['msg'], $keys['keyboard'], "HTML");
}

if(preg_match('/changAccountConnectionLink(\d+)/', $data,$match)){ 
    alert($mainValues['please_wait_message']); 
    $oid = $match[1]; 
 
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id`=?"); 
    $stmt->bind_param("i", $oid); 
    $stmt->execute(); 
    $order = $stmt->get_result()->fetch_assoc(); 
    $stmt->close(); 
 
    if (!$order || !achbotSupportsMarzbanOrder($connection, (int)$oid)) {
        alert("⛔️ این سفارش در پنل مرزبان پشتیبانی نمی‌شود.", true);
        return;
    }
    $date = jdate("Y-m-d H:i",$order['date']); 
    $expire_date = jdate("Y-m-d H:i",$order['expire_date']); 
    $remark = $order['remark']; 
    $uuid = $order['uuid']??"0"; 
    $inboundId = $order['inbound_id']; 
    $acc_link = $order['link']; 
    $server_id = $order['server_id']; 
    $rahgozar = $order['rahgozar']; 
     
    $file_id = $order['fileid']; 
     
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?"); 
    $stmt->bind_param("i", $file_id); 
    $stmt->execute(); 
    $file_detail = $stmt->get_result()->fetch_assoc(); 
    $customPath = $file_detail['custom_path']; 
    $customPort = $file_detail['custom_port']; 
    $customSni = $file_detail['custom_sni']; 
    $stmt->close();
     
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?"); 
    $stmt->bind_param("i", $server_id); 
    $stmt->execute(); 
    $server_info = $stmt->get_result()->fetch_assoc(); 
    $stmt->close(); 
     
    $serverType = $server_info['type']; 
 
    if (achbotSupportsMarzbanAccount($serverType, $inboundId)) {
        $res = renewMarzbanUUID($server_id, $remark); 
        $vraylink = $res->links; 
        $newUuid = $newToken = str_replace("/sub/", "", $res->subscription_url); 
    }else{
        alert("⛔️ پنل این اشتراک دیگر پشتیبانی نمی‌شود.", true);
        return;
        } 
 
    $vray_link = json_encode($vraylink); 
    $stmt = $connection->prepare("UPDATE `orders_list` SET `link`=?, `uuid` = ?, `token` = ? WHERE `id`=?"); 
    $stmt->bind_param("sssi", $vray_link, $newUuid, $newToken, $oid); 
    $stmt->execute(); 
    $stmt->close(); 

    // ✅ ارسال پیام به کاربر بعد از تغییر لینک
    $successMessage = "<b>✅ لینک اشتراک شما با موفقیت تغییر کرد!</b>\n\n"
        . "♻️ لینک اشتراک و کانفیگ‌های شما مجدد ساخته شد.\n\n"
        . "🔄 <b>برای استفاده از لینک جدید این مراحل را انجام دهید:</b>\n\n"
        . "1) لینک اشتراک قبلی و کانفیگ‌های قبلی را از نرم‌افزار کامل حذف کنید.\n"
        . "2) لینک اشتراک جدید را از پیام بالا که شامل اطلاعات اشتراک هست کپی کنید.\n"
        . "3) لینک را وارد نرم افزار کنید تا کانفیگ ها لود شوند اگر لینک اصلی کار نکرد از لینک کمکی استفاده کنید.\n\n"
        . "📱 <i>پیشنهاد می کنیم از نرم‌افزار Karing استفاده کنید.</i>\n"
        . "⚠️ <b>اگر می‌خواهید همه کانفیگ‌ها را تک تک داشته باشید، بزنید روی دکمه کیوآر کانفیگ تا تک تک ارسال شود.</b>";

    sendMessage($successMessage, null, "HTML"); 
 
    $keys = getOrderDetailKeys($from_id, $oid); 
    editText($message_id, $keys['msg'], $keys['keyboard'],"HTML"); 
}

if(preg_match('/changeUserConfigState(\d+)/', $data,$match)){
    alert($mainValues['please_wait_message']);
    $oid = $match[1];

    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id`=?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $userId = $order['userid'];
    $uuid = $order['uuid']??"0";
    $inboundId = $order['inbound_id'];
    $server_id = $order['server_id'];
    $remark = $order['remark'];
    
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $serverType = $server_info['type'];

    
    if (!achbotSupportsPanel($serverType) || (int)$inboundId !== 0) {
        alert("پنل این اشتراک دیگر پشتیبانی نمی‌شود.", true);
        return;
    }
    $update_response = changeMarzbanState($server_id, $remark);
    
    if($update_response->success){
        alert($mainValues['please_wait_message']);
    
        $keys = getUserOrderDetailKeys($oid);
        editText($message_id, $keys['msg'], $keys['keyboard'], "HTML");
    }else sendMessage("عملیات مورد نظر با مشکل روبرو شد.\n" . $update_response->msg);
}

if (preg_match('/agentChangeUserConfigState(\d+)/', $data, $match)) {
    alert($mainValues['please_wait_message']);

    $oid = $match[1];

    // گرفتن اطلاعات سفارش
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id`=?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$order) {
        sendMessage("سفارش مورد نظر یافت نشد.");
        return;
    }

    $uuid = $order['uuid'] ?? "0";
    $inboundId = $order['inbound_id'];
    $server_id = $order['server_id'];
    $remark = $order['remark'];

    // گرفتن اطلاعات سرور
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$server_info) {
        sendMessage("اطلاعات سرور یافت نشد.");
        return;
    }

    $serverType = $server_info['type'];

    // Marzban-only: refuse obsolete inbound metadata instead of altering a
    // different panel/account by guesswork.
    if (!achbotSupportsPanel($serverType) || (int)$inboundId !== 0) {
        alert("پنل این اشتراک دیگر پشتیبانی نمی‌شود.", true);
        return;
    }
    $update_response = changeMarzbanState($server_id, $remark);

    // بروزرسانی موفق بود
    if ($update_response->success) {
        alert($mainValues['please_wait_message']);

        $keys = getOrderDetailKeys($order['userid'], $oid);

        editText($message_id, $keys['msg'], $keys['keyboard'], "HTML");

    } else {
        sendMessage("عملیات مورد نظر با مشکل روبرو شد.\n" . $update_response->msg);
    }
}

// Old X-UI protocol mutation must not alter a Marzban order or its payment.
if(preg_match('/changeAccProtocol(\d+)_(\d+)_(.*)/', $data, $match)){
    alert("تغییر پروتکل پنل قدیمی دیگر پشتیبانی نمی‌شود.", true);
    return;
}
