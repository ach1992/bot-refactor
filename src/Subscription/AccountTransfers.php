<?php
/** AChBot V6: original Telegram account/subscription routes; keep caller scope. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}



if(preg_match('/switchLocation(.+)_(.+)_(.+)_(.+)/', $data,$match)){
    $order_id = $match[1];
    $server_id = $match[2];
    $leftgp = $match[3];
    $expire = $match[4]; 
    /*if($expire < time() or $leftgp <= 0) {
        alert("سرویس شما غیرفعال است. لطفا ابتدا آن را تمدید کنید.",true);exit;
    }*/
    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `active` = 1 and `state` = 1 and ucount > 0 AND `id` != ?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();
    if($respd->num_rows == 0){
        alert('در حال حاضر هیچ سرور فعالی برای تغییر سرویس وجود ندارد.',true);
        exit;
    }
    $keyboard = [];
    while($cat = $respd->fetch_assoc()){
        $sid = $cat['id'];
        $name = $cat['title'];
        $keyboard[] = ['text' => "$name", 'callback_data' => "switchServer{$sid}_{$order_id}"];
    }
    $keyboard = array_chunk($keyboard,2);
    $keyboard[] = [['text' => $buttonValues['back_button'], 'callback_data' => "mainMenu"]];
    editText($message_id, ' 📍 لطفا برای تغییر سرویس فعلی، یکی از سرورها را انتخاب کنید👇',json_encode([
            'inline_keyboard' => $keyboard
        ]));
}
if(preg_match('/switchLocation(.+)_(.+)_(.+)_(.+)/', $data,$match)){
    $order_id = $match[1];
    $server_id = $match[2];
    $leftgp = $match[3];
    $expire = $match[4]; 
    /*if($expire < time() or $leftgp <= 0) {
        alert("سرویس شما غیرفعال است. لطفا ابتدا آن را تمدید کنید.",true);exit;
    }*/
    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `active` = 1 and `state` = 1 and ucount > 0 AND `id` != ?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();
    if($respd->num_rows == 0){
        alert('در حال حاضر هیچ سرور فعالی برای تغییر لوکیشن وجود ندارد.',true);
        exit;
    }
    $keyboard = [];
    while($cat = $respd->fetch_assoc()){
        $sid = $cat['id'];
        $name = $cat['title'];
        $keyboard[] = ['text' => "$name", 'callback_data' => "switchServer{$sid}_{$order_id}"];
    }
    $keyboard = array_chunk($keyboard,2);
    $keyboard[] = [['text' => $buttonValues['back_button'], 'callback_data' => "mainMenu"]];
    editText($message_id, ' 📍 لطفا برای تغییر لوکیشن سرویس فعلی، یکی از سرورها را انتخاب کنید👇',json_encode([
            'inline_keyboard' => $keyboard
        ]));
}

if(preg_match('/switchServer(.+)_(.+)/', $data, $match)){
    $sid = $match[1];  // ID جدید سرور
    $oid = $match[2];  // ID سفارش

    // گرفتن اطلاعات سفارش
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // گرفتن آی‌دی یوزر و ریمارک
    $user_id = $order['userid'];
    $remark = $order['remark'];

    // فقط تغییر آی‌دی سرور در جدول سفارش
    $stmt = $connection->prepare("UPDATE `orders_list` SET `server_id` = ? WHERE `id` = ?");
    $stmt->bind_param("ii", $sid, $oid);
    $stmt->execute();
    $stmt->close();

    // در صورتی که آی‌دی جدید سرور به درستی ذخیره شد
    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id` = ?");
    $stmt->bind_param("i", $sid);
    $stmt->execute();
    $server_title = $stmt->get_result()->fetch_assoc()['title'];
    $stmt->close();

    // ارسال پیام تایید تغییرات
    $msg = " 📍لوکیشن سرویس $remark به سرور جدید با نام $server_title تغییر یافت.\n";
    $adminMessage = "
🌐 تغییر نوع سرویس توسط ادمین

🔐 شناسه عددی: $user_id
🎈 نام کاربری اشتراک: $remark
🔄 سرور جدید: $server_title
";
    sendMessage($adminMessage, null, "html", $admin);
    sendToAllAdmins('sendMessage', ['text' => $adminMessage,'parse_mode' => "html"]);
    editText($message_id, $msg);
    exit();
}
