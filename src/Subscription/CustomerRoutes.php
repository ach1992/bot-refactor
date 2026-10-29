<?php
/** AChBot V6: original Telegram account/subscription routes; keep caller scope. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG
require_once __DIR__.'/AccountDetails.php';

require_once __DIR__ . '/../Admin/Access.php';
if (!achbotAdminRequestAllowed(is_string($data??null)?$data:null,is_string($text??null)?$text:null,(string)($userInfo['step']??''))) {achbotAdminDeny();return;}


function cancelSearchKeyboard() {
    return json_encode([
        'inline_keyboard' => [
            [
                ['text' => \achbotMessage('subscription.customer_routes.cancel_search_keyboard.button.cc357a4c9f'), 'callback_data' => 'cancelSearchConfig']
            ]
        ]
    ]);
}

// ✅ هندلر دکمه لغو عملیات برای سرچ
if ($data == "cancelSearchConfig") {
    delMessage();
    setUser();
    sendMessage($mainValues['reached_main_menu'], getMainKeys(), 'HTML');
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
            'text' => \achbotMessage('subscription.customer_routes.cancel_search_keyboard.button.0b8da1e098'),
            'callback_data' => $isAgentConfig ? "changeAgentOrder$prev" : "changeOrdersPage$prev"
        ];
    }

    if ($page < $number_of_page) {
        $buttons[] = [
            'text' => \achbotMessage('subscription.customer_routes.cancel_search_keyboard.button.0d56714222'),
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
    if ($data==='searchUsersConfig') {
        require_once __DIR__ . '/AccountDetails.php';
        if (!\AchBot\Subscription\AccountDetails\canReadAdminOrderDetails()) {
            alert($mainValues['no_order_found'],true);return;
        }
    }
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

$adminOrderDetailCallback=preg_match('/^userOrderDetails(\d+)_(\d+)$/D',(string)($data??''),$adminOrderDetailMatch)===1;
if(($userInfo['step'] == "searchUsersConfig" && $text != $buttonValues['cancel']) || $adminOrderDetailCallback){
    require_once __DIR__ . '/AccountDetails.php';
    if (!\AchBot\Subscription\AccountDetails\canReadAdminOrderDetails()) {
        alert($mainValues['no_order_found'],true);return;
    }
    if($adminOrderDetailCallback){
        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
        $stmt->bind_param("i", $adminOrderDetailMatch[1]);
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
        $keys = getUserOrderDetailKeys($orderId, $adminOrderDetailCallback?$adminOrderDetailMatch[2]:0);

        if($keys == null) sendMessage($mainValues['no_order_found'], cancelSearchKeyboard()); 
        else{
            if(!$adminOrderDetailCallback) sendMessage($keys['msg'], $keys['keyboard'], "HTML");
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
    alert(\achbotMessage('subscription.customer_routes.cancel_search_keyboard.alert.06fdc45418'));
    exit();
}
if(preg_match('/^changeCustomPort(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage(\achbotMessage('subscription.customer_routes.cancel_search_keyboard.message.3034785b83'), $cancelKey);
    setUser($data);
}
if(preg_match('/^changeCustomPort(\d+)/',$userInfo['step'],$match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if(is_numeric($text)){
        $stmt = $connection->prepare("UPDATE `server_plans` SET `custom_port`= ? WHERE `id` = ?");
        $stmt->bind_param("ii", $text, $match[1]);
        $stmt->execute();
        $stmt->close();  
        sendMessage($mainValues['saved_successfuly'],$removeKeyboard);
         
        sendMessage(\achbotMessage('subscription.customer_routes.cancel_search_keyboard.message.06d5c646c1'), getPlanDetailsKeys($match[1]));
        setUser();
    }else sendMessage($mainValues['send_only_number']);
}
if(preg_match('/^changeCustomSni(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage(\achbotMessage('subscription.customer_routes.cancel_search_keyboard.message.d4b5f70a92'), $cancelKey);
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
     
    sendMessage(\achbotMessage('subscription.customer_routes.cancel_search_keyboard.message.06d5c646c1'), getPlanDetailsKeys($match[1]));
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
    alert(\achbotMessage('subscription.customer_routes.cancel_search_keyboard.alert.77c239f956'), true);
    return;
}
if($data=="changeProtocolIsDisable"){
    alert(\achbotMessage('subscription.customer_routes.cancel_search_keyboard.alert.477e4570c3'));
}

if(preg_match('/updateConfigConnectionLink(\d+)/', $data,$match)){
    alert($mainValues['please_wait_message']);
    $oid = $match[1];

    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id`=?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$order || !achbotSupportsOrder($connection, (int)$oid, null, \AchBot\Subscription\AccountDetails\canReadAdminOrderDetails()?null:(int)$from_id)) {
        alert(\achbotMessage('subscription.customer_routes.cancel_search_keyboard.alert.724ff90245'), true);
        return;
    }
    $remark = $order['remark'];
    $inboundId=$order['inbound_id'];
    $server_id=$order['server_id'];

    $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id`=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_config = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $serverType=$server_config['type']??'';
    
    $success = false; // Flag برای موفقیت آمیز بودن عملیات
    
    if (achbotSupportsAccount($serverType, $inboundId)) {
        $info=achbotProviderRenderPacket($connection,(int)$server_id,(string)$remark);
        if (is_array($info->links??null) && $info->links!==[]) {
            $vraylink = $info->links;
            $success = true;
        } else {
            $vraylink = \achbotMessage('subscription.customer_routes.cancel_search_keyboard.vraylink.cde59051f4');
        }
    } else {
        alert(\achbotMessage('subscription.customer_routes.cancel_search_keyboard.alert.724ff90245'), true);
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
        $successMessage = \achbotMessage('subscription.customer_routes.cancel_search_keyboard.success_message.6d1ad0af39')
            . \achbotMessage('subscription.customer_routes.cancel_search_keyboard.text.02845d07de')
            . \achbotMessage('subscription.customer_routes.cancel_search_keyboard.text.672ff8ac41')
            . \achbotMessage('subscription.customer_routes.cancel_search_keyboard.text.caaf926e2a')
            . \achbotMessage('subscription.customer_routes.cancel_search_keyboard.text.0aa0ee1c1f')
            . \achbotMessage('subscription.customer_routes.cancel_search_keyboard.text.1ed8d0a6cf')
            . \achbotMessage('subscription.customer_routes.cancel_search_keyboard.text.47dcd18697')
			. \achbotMessage('subscription.customer_routes.cancel_search_keyboard.text.0bd7d3c032');
        
        // استفاده از parse_mode: "HTML" برای نمایش متن بولد شده
        sendMessage($successMessage, null, "HTML");
        
    } else {
        // ارسال پیام خطا
        $errorMessage = \achbotMessage('subscription.customer_routes.cancel_search_keyboard.error_message.496a7eede8')
            . \achbotMessage('subscription.customer_routes.cancel_search_keyboard.text.fd5bccf56e')
            . \achbotMessage('subscription.customer_routes.cancel_search_keyboard.text.0d78481f3e');
        
        sendMessage($errorMessage, null, "HTML");
    }
    
    $keys=(int)$order['userid']===(int)$from_id?getOrderDetailKeys($from_id,$oid):
        \AchBot\Subscription\AccountDetails\getUserOrderDetailKeys($oid);
    if (!is_array($keys)) {
        alert(\achbotMessage('subscription.customer_routes.cancel_search_keyboard.alert.001c06ff23'),true);return;
    }
    editText($message_id,$keys['msg'],$keys['keyboard'],'HTML');
}

if(preg_match('/changAccountConnectionLink(\d+)/', $data,$match)){ 
    alert($mainValues['please_wait_message']); 
    $oid = $match[1]; 
 
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id`=?"); 
    $stmt->bind_param("i", $oid); 
    $stmt->execute(); 
    $order = $stmt->get_result()->fetch_assoc(); 
    $stmt->close(); 
 
    if (!$order || !achbotSupportsOrder($connection, (int)$oid, null, \AchBot\Subscription\AccountDetails\canReadAdminOrderDetails()?null:(int)$from_id)) {
        alert(\achbotMessage('subscription.customer_routes.cancel_search_keyboard.alert.724ff90245'), true);
        return;
    }
    $remark = $order['remark']; 
    $uuid = $order['uuid']??"0"; 
    $inboundId = $order['inbound_id']; 
    $acc_link = $order['link']; 
    $server_id = $order['server_id']; 
     
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?"); 
    $stmt->bind_param("i", $server_id); 
    $stmt->execute(); 
    $server_info = $stmt->get_result()->fetch_assoc(); 
    $stmt->close(); 
     
    $serverType = $server_info['type']; 
 
    if (achbotSupportsAccount($serverType, $inboundId)) {
        $res = achbotProviderRevoke($server_id, $remark);
        if (($res->success??false)!==true || ($res->verified??false)!==true || !isset($res->links)) {
            alert(\achbotMessage('subscription.customer_routes.cancel_search_keyboard.alert.001c06ff23'),true);return;
        }
        $vraylink = $res->links;
        $newUuid=$order['uuid'];$newToken=$order['token'];
        if ($serverType==='marzban') {
            $nativeUrl=(string)$res->subscription_url;
            if (str_starts_with($nativeUrl,'/')) $nativeUrl=rtrim((string)$server_info['panel_url'],'/').$nativeUrl;
            $nativeParts=achbotProviderImportParts($nativeUrl);
            if ($nativeParts===null) {
                alert(\achbotMessage('subscription.customer_routes.cancel_search_keyboard.alert.001c06ff23'),true);return;
            }
            $newUuid=$newToken=$nativeParts['token'];
        }
    }else{
        alert(\achbotMessage('subscription.customer_routes.cancel_search_keyboard.alert.724ff90245'), true);
        return;
        } 
 
    $vray_link = json_encode($vraylink); 
    $stmt = $connection->prepare("UPDATE `orders_list` SET `link`=?, `uuid` = ?, `token` = ? WHERE `id`=?"); 
    $stmt->bind_param("sssi", $vray_link, $newUuid, $newToken, $oid); 
    $stmt->execute(); 
    $stmt->close(); 

    // ✅ ارسال پیام به کاربر بعد از تغییر لینک
    $successMessage = \achbotMessage('subscription.customer_routes.cancel_search_keyboard.success_message.eb1ae135ff')
        . \achbotMessage('subscription.customer_routes.cancel_search_keyboard.text.36244c21e0')
        . \achbotMessage('subscription.customer_routes.cancel_search_keyboard.text.f82d5bd5ad')
        . \achbotMessage('subscription.customer_routes.cancel_search_keyboard.text.97f8afa554')
        . \achbotMessage('subscription.customer_routes.cancel_search_keyboard.text.f3bed97abc')
        . \achbotMessage('subscription.customer_routes.cancel_search_keyboard.text.4267d2ca77')
        . \achbotMessage('subscription.customer_routes.cancel_search_keyboard.text.30d20d2a47')
        . \achbotMessage('subscription.customer_routes.cancel_search_keyboard.text.3c4d2c9dc6');

    sendMessage($successMessage, null, "HTML"); 
 
    $keys=(int)$order['userid']===(int)$from_id?getOrderDetailKeys($from_id,$oid):
        \AchBot\Subscription\AccountDetails\getUserOrderDetailKeys($oid);
    if (!is_array($keys)) {
        alert(\achbotMessage('subscription.customer_routes.cancel_search_keyboard.alert.001c06ff23'),true);return;
    }
    editText($message_id,$keys['msg'],$keys['keyboard'],'HTML');
}

if(preg_match('/changeUserConfigState(\d+)/', $data,$match)){
    alert($mainValues['please_wait_message']);
    $stateActorAdmin=((int)$from_id===(int)$admin || !empty($userInfo['isAdmin']));
    $oid = $match[1];

    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id`=?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$order || !achbotSupportsOrder($connection,(int)$oid,null,$stateActorAdmin?null:(int)$from_id)) {alert(\achbotMessage('subscription.customer_routes.cancel_search_keyboard.alert.627f7fad2f'),true);return;}
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
        alert(\achbotMessage('subscription.customer_routes.cancel_search_keyboard.alert.724ff90245'), true);
        return;
    }
    require_once __DIR__.'/../Panel/ServiceLifecycle.php';
    $observed=achbotServiceObserve((int)$server_id,(string)$remark);
    if (!$observed['verified'] || !in_array($observed['status'],['active','disabled'],true)) {
        alert(\achbotMessage('subscription.customer_routes.cancel_search_keyboard.alert.724ff90245'),true);
        return;
    }
    $update_response = achbotProviderToggle($server_id, $remark);
    
    if($update_response->success){
        alert($mainValues['please_wait_message']);
    
        $keys = $stateActorAdmin?getUserOrderDetailKeys($oid):getOrderDetailKeys((int)$from_id,$oid);
        editText($message_id, $keys['msg'], $keys['keyboard'], "HTML");
    }else sendMessage(\achbotMessage('subscription.customer_routes.cancel_search_keyboard.message.b63b579845'));
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

    if (!$order || !achbotSupportsOrder($connection,(int)$oid,null,
        ((int)$from_id===(int)$admin || !empty($userInfo['isAdmin']))?null:(int)$from_id) ||
        (!((int)$from_id===(int)$admin || !empty($userInfo['isAdmin'])) &&
         ((int)($userInfo['is_agent']??0)!==1 || (int)($order['agent_bought']??0)!==1))) {
        sendMessage(\achbotMessage('subscription.customer_routes.cancel_search_keyboard.message.f0a1fc755c'));
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
        sendMessage(\achbotMessage('subscription.customer_routes.cancel_search_keyboard.message.a7cb7bc569'));
        return;
    }

    $serverType = $server_info['type'];

    // Marzban-only: refuse obsolete inbound metadata instead of altering a
    // different panel/account by guesswork.
    if (!achbotSupportsPanel($serverType) || (int)$inboundId !== 0) {
        alert(\achbotMessage('subscription.customer_routes.cancel_search_keyboard.alert.724ff90245'), true);
        return;
    }
    require_once __DIR__.'/../Panel/ServiceLifecycle.php';
    $observed=achbotServiceObserve((int)$server_id,(string)$remark);
    if (!$observed['verified'] || !in_array($observed['status'],['active','disabled'],true)) {
        alert(\achbotMessage('subscription.customer_routes.cancel_search_keyboard.alert.724ff90245'),true);
        return;
    }
    $update_response = achbotProviderToggle($server_id, $remark);

    // بروزرسانی موفق بود
    if ($update_response->success) {
        alert($mainValues['please_wait_message']);

        $keys = getOrderDetailKeys($order['userid'], $oid);

        editText($message_id, $keys['msg'], $keys['keyboard'], "HTML");

    } else {
        sendMessage(\achbotMessage('subscription.customer_routes.cancel_search_keyboard.message.b63b579845'));
    }
}

// Old X-UI protocol mutation must not alter a Marzban order or its payment.
if(preg_match('/changeAccProtocol(\d+)_(\d+)_(.*)/', $data, $match)){
    alert(\achbotMessage('subscription.customer_routes.cancel_search_keyboard.alert.d6602f696a'), true);
    return;
}
