<?php
/** AChBot V6: exact linked legacy account deletion branches. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../Finance/ReceiptHelpers.php';

if(preg_match('/^deleteMyConfig(\d+)/', $data, $match)){
    $oid = $match[1];
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $remark = $order['remark'];

    editText($message_id,
        "⚠️ آیا از حذف اشتراک <code>$remark</code> مطمئن هستید؟
🛑 دقت کنید با انتخاب گزینه <b>بله</b> اشتراک شما برای همیشه حذف می‌شود، این عمل غیرقابل برگشت بوده و در صورت فعال بودن اشتراک هزینه آن به شما برگشت داده نمی‌شود.",
        json_encode([
            'inline_keyboard' => [
                [['text'=>"بله",'callback_data'=>"yesDeleteConfig" . $match[1]],['text'=>"خیر",'callback_data'=>"noDontDelete"]]
            ]
        ]),
        "HTML"
    );
}

elseif($data=="noDontDelete"){
    editText($message_id, "عملیات مورد نظر لغو شد.",json_encode([
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
        alert("⛔️ این اشتراک متعلق به حساب شما نیست.", true);
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
        alert("⛔️ حذف این اشتراک در حال حاضر امکان‌پذیر نیست.", true);
        exit;
    }
    // Fence the original order before the remote, non-idempotent DELETE.
    if (!achbotAutoRenewPrepareDeletion($connection,(int)$oid)) {
        alert("⚠️ این اشتراک در حال بررسی است؛ حذف نیازمند پیگیری پشتیبانی است.", true);
        exit;
    }
    try {
        $configInfo = getMarzbanUser($server_id, $remark);
        $deleted = deleteMarzban($server_id, $remark);
        if (!is_object($deleted) || ($deleted->success??false)!==true)
            throw new RuntimeException('delete_remote_unconfirmed');
        $leftMb = sumerize($configInfo->data_limit - $configInfo->used_traffic);
        $expiryDay = $configInfo->expire != 0?
            floor(($configInfo->expire - time()) / 86400):"نامحدود";
    } catch (Throwable $error) {
        error_log('achbot_account_delete_remote_deferred');
        alert("⚠️ نتیجه حذف اشتراک مشخص نیست؛ لطفاً با پشتیبانی تماس بگیرید.", true);
        exit;
    }

    
    if(is_numeric($expiryDay)){
        if($expiryDay<0) $expiryDay = 0;
    }

    if (!achbotAutoRenewFinalizeDeletion($connection,(int)$oid,(int)$server_id,true)) {
        error_log('achbot_account_delete_local_deferred');
        alert("⚠️ حذف اشتراک نیازمند بررسی است؛ لطفاً با پشتیبانی تماس بگیرید.", true);
        exit;
    }

    editText($message_id, "اشتراک $remark با موفقیت حذف شد.",json_encode([
            'inline_keyboard' => [
                [['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]]
                ]
        ]));
        
$channelMessage = "🔋 حذف اشتراک توسط کاربر

🎫 شناسه عددی: <code>$from_id</code>
👨 اسم کاربر: $first_name
⚡️ نام کاربری: @$username
🎈 نام کاربری اشتراک: $remark
🔋 حجم اولیه اشتراک: $volume گیگ
⏰ مدت اشتراک: $days روز
";

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

    editText($message_id, "آیا از حذف اشتراک $remark مطمئن هستید؟",json_encode([
        'inline_keyboard' => [
            [['text'=>"بله",'callback_data'=>"yesDeleteUserConfig" . $match[1]],['text'=>"خیر",'callback_data'=>"noDontDelete"]]
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
    if (!$order) { alert("⛔️ سفارش یافت نشد.", true); exit; }
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
        alert("⛔️ حذف این اشتراک در حال حاضر امکان‌پذیر نیست.", true);
        exit;
    }
    if (!achbotAutoRenewPrepareDeletion($connection,(int)$oid)) {
        alert("⚠️ این اشتراک در حال بررسی است؛ حذف نیازمند پیگیری است.", true);
        exit;
    }
    try {
        $res = deleteMarzban($server_id, $remark);
        if (!is_object($res) || ($res->success??false)!==true)
            throw new RuntimeException('admin_delete_remote_unconfirmed');
    } catch (Throwable $error) {
        error_log('achbot_admin_delete_remote_deferred');
        alert("⚠️ نتیجه حذف اشتراک مشخص نیست؛ لطفاً بررسی دستی انجام دهید.", true);
        exit;
    }
    

    if (!achbotAutoRenewFinalizeDeletion($connection,(int)$oid,(int)$server_id,true)) {
        error_log('achbot_account_delete_local_deferred');
        alert("⚠️ حذف اشتراک نیازمند بررسی است؛ لطفاً با پشتیبانی تماس بگیرید.", true);
        exit;
    }

    editText($message_id, "اشتراک $remark با موفقیت حذف شد.",json_encode([
            'inline_keyboard' => [
                [['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]]
                ]
	]));
	// ارسال پیام به ادمین
	$adminMessage = "
❌ اشتراک توسط ادمین حذف شد.

👤 شناسه عددی: $userId
🎈 نام کاربری اشتراک: $remark
";
	sendMessage($adminMessage, null, "html", $admin);
	sendToAllAdmins('sendMessage', ['text' => $adminMessage,'parse_mode' => "html"]);
        
    exit();
}
