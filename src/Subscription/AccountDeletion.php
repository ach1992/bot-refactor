<?php
/** AChBot V6: exact linked legacy account deletion branches. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG

require_once __DIR__ . '/../Finance/ReceiptHelpers.php';

if(preg_match('/^deleteMyConfig(\d+)/', $data, $match)){
    $oid = $match[1];
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    // The preview callback is user-controlled: do not expose another account.
    if (!$order || (int)$order['userid']!==(int)$from_id) {
        alert(\achbotMessage('subscription.account_deletion.alert.a370755120'), true);
        exit;
    }
    $remark = $order['remark'];

    editText($message_id,
        \achbotMessage('subscription.account_deletion.message.acb79596be', ['remark' => $remark]),
        json_encode([
            'inline_keyboard' => [
                [['text'=>\achbotMessage('subscription.account_deletion.button.13159f8c74'),'callback_data'=>"yesDeleteConfig" . $match[1]],['text'=>\achbotMessage('subscription.account_deletion.button.5f5c4c72bd'),'callback_data'=>"noDontDelete"]]
            ]
        ]),
        "HTML"
    );
}

elseif($data=="noDontDelete"){
    editText($message_id, \achbotMessage('subscription.account_deletion.no_dont_delete.message.4ca0ffd711'),json_encode([
        'inline_keyboard' => [
            [['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]]
            ]
    ]));
}
elseif(preg_match('/^yesDeleteConfig(\d+)/',$data,$match)){
    $oid = $match[1];
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$order || (int)$order['userid']!==(int)$from_id) {
        alert(\achbotMessage('subscription.account_deletion.alert.a370755120'), true);
        exit;
    }
    $inbound_id = $order['inbound_id'];
    $server_id = $order['server_id'];
    $remark = $order['remark'];
    $uuid = $order['uuid']??"0";
    $fileid = $order['fileid'];
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ?");
    $stmt->bind_param("i", $fileid);
    $stmt->execute();
    $planDetail = $stmt->get_result()->fetch_assoc();
    $stmt->close();
	$volume = $planDetail['volume'];
	$days = $planDetail['days'];
	
    $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id` = ?");
    $stmt->bind_param('i', $server_id);
    $stmt->execute();
    $serverConfig = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $serverType = $serverConfig['type'];

	
    if (!achbotSupportsPanel($serverType) || (int)$inbound_id !== 0) {
        alert(\achbotMessage('subscription.account_deletion.no_dont_delete.alert.739703d8ae'), true);
        exit;
    }
    // Fence the original order before the remote, non-idempotent DELETE.
    if (!achbotAutoRenewPrepareDeletion($connection,(int)$oid)) {
        alert(\achbotMessage('subscription.account_deletion.no_dont_delete.alert.fc1b571950'), true);
        exit;
    }
    try {
        $configInfo = achbotProviderUser($server_id, $remark);
        $deleted = achbotProviderDelete($server_id, $remark);
        if (!is_object($deleted) || ($deleted->success??false)!==true)
            throw new RuntimeException('delete_remote_unconfirmed');
        $leftMb = sumerize($configInfo->data_limit - $configInfo->used_traffic);
        $expiryDay = $configInfo->expire != 0?
            floor(($configInfo->expire - time()) / 86400):\achbotMessage('subscription.account_deletion.no_dont_delete.text.5e6ee5eaa1');
    } catch (Throwable $error) {
        error_log('achbot_account_delete_remote_deferred order_id='.(int)$oid);
        alert(\achbotMessage('subscription.account_deletion.no_dont_delete.alert.9347ccc8b0'), true);
        exit;
    }

    
    if(is_numeric($expiryDay)){
        if($expiryDay<0) $expiryDay = 0;
    }

    if (!achbotAutoRenewFinalizeDeletion($connection,(int)$oid,(int)$server_id,true)) {
        error_log('achbot_account_delete_local_deferred order_id='.(int)$oid);
        alert(\achbotMessage('subscription.account_deletion.no_dont_delete.alert.e2cbeb044b'), true);
        exit;
    }

    editText($message_id, \achbotMessage('subscription.account_deletion.no_dont_delete.message.ab50b51b1f', ['remark' => $remark]),json_encode([
            'inline_keyboard' => [
                [['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]]
                ]
        ]));
        
$channelMessage = \achbotMessage('subscription.account_deletion.no_dont_delete.channel_message.f5930d6ccb', ['from_id' => $from_id, 'first_name' => $first_name, 'username' => $username, 'remark' => $remark, 'volume' => $volume, 'days' => $days]);

// ارسال پیام به ادمین خاص
sendMessage($channelMessage, null, "html", $admin);

// ارسال پیام به تمام ادمین‌ها
//sendToAllAdmins('sendMessage', ['text' => $channelMessage,'reply_markup' => null,'parse_mode' => "html"]);
	// ارسال پیام به کانال گزارشات
	$rewardChannel = $botState['rewardChannel'];
		if (!empty($rewardChannel)) {
		sendMessage($channelMessage, null, "html", $rewardChannel);
	}
    exit();
}
elseif(preg_match('/^delUserConfig(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $oid = $match[1];
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $remark = $order['remark'];

    editText($message_id, \achbotMessage('subscription.account_deletion.no_dont_delete.message.2ddfbc22e4', ['remark' => $remark]),json_encode([
        'inline_keyboard' => [
            [['text'=>\achbotMessage('subscription.account_deletion.button.13159f8c74'),'callback_data'=>"yesDeleteUserConfig" . $match[1]],['text'=>\achbotMessage('subscription.account_deletion.button.5f5c4c72bd'),'callback_data'=>"noDontDelete"]]
            ]
    ]));
}
elseif(preg_match('/^yesDeleteUserConfig(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $oid = $match[1];
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$order) { alert(\achbotMessage('subscription.account_deletion.no_dont_delete.alert.bd8e953385'), true); exit; }
    $userId = $order['userid'];
    $inbound_id = $order['inbound_id'];
    $server_id = $order['server_id'];
    $remark = $order['remark'];
    $uuid = $order['uuid']??"0";
    
    $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id` = ?");
    $stmt->bind_param('i', $server_id);
    $stmt->execute();
    $serverConfig = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $serverType = $serverConfig['type'];
    
	
    if (!achbotSupportsPanel($serverType) || (int)$inbound_id !== 0) {
        alert(\achbotMessage('subscription.account_deletion.no_dont_delete.alert.739703d8ae'), true);
        exit;
    }
    if (!achbotAutoRenewPrepareDeletion($connection,(int)$oid)) {
        alert(\achbotMessage('subscription.account_deletion.no_dont_delete.alert.1186ae9204'), true);
        exit;
    }
    try {
        $res = achbotProviderDelete($server_id, $remark);
        if (!is_object($res) || ($res->success??false)!==true)
            throw new RuntimeException('admin_delete_remote_unconfirmed');
    } catch (Throwable $error) {
        error_log('achbot_admin_delete_remote_deferred order_id='.(int)$oid);
        alert(\achbotMessage('subscription.account_deletion.no_dont_delete.alert.3c93240b6e'), true);
        exit;
    }
    

    if (!achbotAutoRenewFinalizeDeletion($connection,(int)$oid,(int)$server_id,true)) {
        error_log('achbot_account_delete_local_deferred order_id='.(int)$oid);
        alert(\achbotMessage('subscription.account_deletion.no_dont_delete.alert.e2cbeb044b'), true);
        exit;
    }

    editText($message_id, \achbotMessage('subscription.account_deletion.no_dont_delete.message.ab50b51b1f', ['remark' => $remark]),json_encode([
            'inline_keyboard' => [
                [['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]]
                ]
	]));
	// ارسال پیام به ادمین
	$adminMessage = \achbotMessage('subscription.account_deletion.no_dont_delete.admin_message.53224aee55', ['userId' => $userId, 'remark' => $remark]);
	sendMessage($adminMessage, null, "html", $admin);
	sendToAllAdmins('sendMessage', ['text' => $adminMessage,'parse_mode' => "html"]);
        
    exit();
}
