<?php
/** AChBot V6: original Telegram account/subscription routes; keep caller scope. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

// هندل دکمه نمایش اشتراک های کاربر
if (preg_match('/^showUserSubscriptions(\d+)/', $data, $match)) {
    $userId = $match[1];
    $userInfo = getUserInfoKeys($userId);
    
    if (isset($userInfo['subscriptionMessages']) && count($userInfo['subscriptionMessages']) > 0) {
        $subscriptionMessages = $userInfo['subscriptionMessages'];
        
        $title = "🔄 <b>لیست اشتراک های کاربر</b>\n🔐 شناسه عددی: <code>$userId</code>";
        $keyboard = json_encode([
            'inline_keyboard' => [
                [['text' => "🔍 جستجوی اشتراک کاربر", 'callback_data' => "searchUsersConfig"]]
            ]
        ]);

        foreach ($subscriptionMessages as $message) {
            $fullMessage = $title . "\n\n" . $message;
            sendMessage($fullMessage, $keyboard, 'HTML', $callback_query['message']['chat']['id']);
        }
    } else {
        sendMessage("هیچ اشتراکی برای این کاربر موجود نیست.", null, 'HTML', $callback_query['message']['chat']['id']);
    }
}

// Begin of Manage a specific order
if ($data == "manageorderdatabase" && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    delMessage();
    sendMessage("✏️ لطفا `remark` مربوط به سفارش را وارد کنید:", $cancelKey, "Markdown");
    setUser("awaitingOrderRemarkOnly");
}
if ($userInfo['step'] == "awaitingOrderRemarkOnly" && $text != $buttonValues['cancel']) {
    $remark = trim($text);
    $remark = mysqli_real_escape_string($connection, $remark);

    // بررسی کاراکترهای معتبر
    if (!preg_match('/^[a-zA-Z0-9\s\-_]+$/', $remark)) {
        sendMessage("❌ ریمارک شامل کاراکترهای نامعتبر است. لطفاً ریمارک معتبر وارد کنید.", $cancelKey);
        setUser();
        return;
    }

    $stmt = $connection->prepare("SELECT * FROM orders_list WHERE remark = ?");
    if (!$stmt) {
        sendMessage("❌ خطایی در ارتباط با دیتابیس رخ داد.", $cancelKey);
        setUser();
        return;
    }
    $stmt->bind_param("s", $remark);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();

    $count = $result->num_rows;

    if ($count == 0) {
        $keyboard = json_encode([
            'inline_keyboard' => [
                [['text' => "🔂 ارسال remark جدید", 'callback_data' => "manageorderdatabase"]]
            ]
        ]);
        sendMessage("❌ سفارشی با این remark یافت نشد.", $keyboard);
        setUser();
        return;
    }

    if ($count == 1) {
        $order = $result->fetch_assoc();
        setUser("manageOrderById_" . $order['id']);
        showOrderManagementPanel($order);
        return;
    }

    $keyboard = ['inline_keyboard' => []];
    $useridList = [];
    $maxResults = 10;
    $counter = 0;

    $tempRemarkId = uniqid(); // شناسه موقت برای ریمارک
    while ($row = $result->fetch_assoc()) {
        if (!in_array($row['userid'], $useridList)) {
            if ($counter >= $maxResults) {
                sendMessage("⚠️ تعداد نتایج زیاد است. لطفاً ریمارک دقیق‌تری وارد کنید.", $cancelKey);
                setUser();
                return;
            }
            $useridList[] = $row['userid'];
            $callbackData = "selectOrderByRemark_userid_{$row['userid']}_tempId_" . $tempRemarkId;
            if (strlen($callbackData) > 64) {
                sendMessage("❌ خطا: داده‌های callback خیلی طولانی هستند.", $cancelKey);
                setUser();
                return;
            }
            $keyboard['inline_keyboard'][] = [['text' => "{$row['userid']}", 'callback_data' => $callbackData]];
            $counter++;
        }
    }
    $keyboard['inline_keyboard'][] = [['text' => "🔂 ارسال remark جدید", 'callback_data' => "manageorderdatabase"]];

    // ارسال پیام با مدیریت خطا
    $response = sendMessage("👤 چند سفارش با این remark یافت شد. لطفاً یکی از userID‌ها را انتخاب کنید:", json_encode($keyboard));
    if (!$response) {
        sendMessage("❌ خطایی در ارسال پیام رخ داد. لطفاً دوباره تلاش کنید.", $cancelKey);
        setUser();
        return;
    }

    // ذخیره حالت کاربر و ریمارک موقت
    setUser("awaitingUserIdSelection_" . $tempRemarkId . "_" . $remark);
}
// مدیریت انتخاب userID
if (preg_match('/^selectOrderByRemark_userid_(\d+)_tempId_(.+)/', $data, $match)) {
    $userid = intval($match[1]);
    $tempRemarkId = $match[2];

    // بازیابی ریمارک از حالت کاربر یا دیتابیس
    if (preg_match('/^awaitingUserIdSelection_' . $tempRemarkId . '_(.+)/', $userInfo['step'], $remarkMatch)) {
        $remark = $remarkMatch[1];
    } else {
        sendMessage("❌ خطا: ریمارک موقت نامعتبر است.", $cancelKey);
        setUser();
        return;
    }

    $stmt = $connection->prepare("SELECT * FROM orders_list WHERE userid = ? AND remark = ? LIMIT 1");
    if (!$stmt) {
        sendMessage("❌ خطایی در ارتباط با دیتابیس رخ داد.", $cancelKey);
        setUser();
        return;
    }
    $stmt->bind_param("is", $userid, $remark);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();

    if ($result->num_rows == 0) {
        $keyboard = json_encode([
            'inline_keyboard' => [
                [['text' => "🔂 ارسال remark جدید", 'callback_data' => "manageorderdatabase"]]
            ]
        ]);
        sendMessage("❌ سفارشی با این مشخصات پیدا نشد.", $keyboard);
        setUser();
        return;
    }

    $order = $result->fetch_assoc();

    setUser("manageOrderById_" . $order['id']);
    showOrderManagementPanel($order);
}

function showOrderManagementPanel($order, $msgId = null){
	
    $notifStatus = $order['notif'] == 0 ? "🔘 ارسال نشده" : "✅ ارسال شده";
	$agentStatus = $order['agent_bought'] == 0 ? "🔘 نیست" : "✅ هست";

    $keyboard = [
        [["text" => "🗑 حذف سفارش", "callback_data" => "deleteOrder_{$order['id']}"]],
        [["text" => "📅 تغییر تاریخ انقضا", "callback_data" => "changeExpireDate_{$order['id']}"]],
        [["text" => "🔑 تغییر Token", "callback_data" => "changeToken_{$order['id']}"]],
        [["text" => "🖥 تغییر Server ID", "callback_data" => "changeServerId_{$order['id']}"]],
        [["text" => "📦 تغییر Plan", "callback_data" => "changeFileId_{$order['id']}_{$order['server_id']}"]],
        [["text" => "🧬 تغییر UUID", "callback_data" => "changeUUID_{$order['id']}"]],
		[["text" => "💎 خرید همکار ←  $agentStatus", "callback_data" => "toggleAgent_{$order['id']}"]],
        [["text" => "🔔 وضعیت Notif ←  $notifStatus", "callback_data" => "toggleNotif_{$order['id']}"]],
		[["text" => "💡 مشاهده مشخصات اشتراک", "callback_data" => "quickUserOrderDetails_{$order['id']}"]],
        [["text" => "⚙️ بازگشت به مدیریت", "callback_data" => "managePanel"]],
    ];

    $msg = "🔧 مدیریت سفارش مربوط به:\n\n👤 UserID: <code>{$order['userid']}</code>\n📛 Remark: <code>{$order['remark']}</code>";

    if ($msgId) {
        editText($msgId, $msg, json_encode(['inline_keyboard' => $keyboard]), "html");
    } else {
        sendMessage($msg, json_encode(['inline_keyboard' => $keyboard]), "html");
    }
}

if (preg_match('/^returnToPanel_(\\d+)/', $data, $match)) {
    $id = intval($match[1]);
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
    if ($result->num_rows > 0) {
        $order = $result->fetch_assoc();
        showOrderManagementPanel($order, $message_id);
    }
    setUser("manageOrderById_" . $id);
}

if (preg_match('/^deleteOrder_(\\d+)/', $data, $match)) {
    $id = intval($match[1]);

    // دریافت اطلاعات قبل از حذف
    $stmt = $connection->prepare("SELECT userid, remark FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();

    if ($result->num_rows == 0) {
        alert("❌ سفارشی برای حذف پیدا نشد.");
        return;
    }

    $row = $result->fetch_assoc();
    $userid = $row['userid'];
    $remark = $row['remark'];

    // حذف رکورد
    $stmt = $connection->prepare("DELETE FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    // پیام برای کاربر
    alert("✅ سفارش با موفقیت حذف شد.");
    sendMessage("✅ سفارش مربوط به:\n\n👤 UserID: <code>$userid</code>\n📛 Remark: <code>$remark</code>\n\nبا موفقیت حذف شد.\nبرای مدیریت سفارش دیگری، دوباره دکمه مدیریت سفارش در دیتابیس را فشار دهید.", null, "html");

    // پیام برای ادمین ها
    $adminMessage = "🚨 سفارش حذف شد:\n\n👤 UserID: <code>$userid</code>\n📛 Remark: <code>$remark</code>\n\n🗑 توسط: <code>$from_id</code>";
    //sendToAllAdmins('sendMessage', ['text' => $adminMessage,'parse_mode' => 'html']);
	
	// ارسال پیام به کانال گزارشات
	$rewardChannel = $botState['rewardChannel'];
		if (!empty($rewardChannel)) {
		sendMessage($adminMessage, null, "html", $rewardChannel);
	}

    setUser();
}

if (preg_match('/^toggleAgent_(\\d+)/', $data, $match)) {
    $id = intval($match[1]);
    $stmt = $connection->prepare("SELECT `agent_bought` FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $newAgent = $row['agent_bought'] == 0 ? 1 : 0;
        $stmt = $connection->prepare("UPDATE `orders_list` SET `agent_bought` = ? WHERE `id` = ?");
        $stmt->bind_param("ii", $newAgent, $id);
        $stmt->execute();
        $stmt->close();

        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();
        if ($result->num_rows > 0) {
            $order = $result->fetch_assoc();
            showOrderManagementPanel($order, $message_id);
        }
    }
}

if (preg_match('/^toggleNotif_(\\d+)/', $data, $match)) {
    $id = intval($match[1]);
    $stmt = $connection->prepare("SELECT `notif` FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $newNotif = $row['notif'] == 0 ? -1 : 0;
        $stmt = $connection->prepare("UPDATE `orders_list` SET `notif` = ? WHERE `id` = ?");
        $stmt->bind_param("ii", $newNotif, $id);
        $stmt->execute();
        $stmt->close();

        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();
        if ($result->num_rows > 0) {
            $order = $result->fetch_assoc();
            showOrderManagementPanel($order, $message_id);
        }
    }
}

if (preg_match('/^changeExpireDate_(\\d+)/', $data, $match)) {
    $id = intval($match[1]);
    sendMessage("🔁 لطفا تعداد روزی که می‌خواهید به تاریخ انقضا اضافه یا کم شود را وارد کنید.\n\nمثال: `+10` یا `-5`", $cancelKey, "Markdown");
    setUser("awaitingExpireChange_$id");
}

if (preg_match('/^awaitingExpireChange_(\\d+)/', $userInfo['step'], $match) && is_numeric($text)) {
    $id = intval($match[1]);
    $days = intval($text);
    $stmt = $connection->prepare("UPDATE `orders_list` SET `expire_date` = `expire_date` + (? * 86400) WHERE `id` = ?");
    $stmt->bind_param("ii", $days, $id);
    $stmt->execute();
    $stmt->close();

    sendMessage("✅ تاریخ انقضا با موفقیت ویرایش شد.");
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
    $order = $result->fetch_assoc();
    showOrderManagementPanel($order, $message_id);
    setUser();
}

if (preg_match('/^changeToken_(\\d+)/', $data, $match)) {
    $id = intval($match[1]);
    sendMessage("🔐 لطفا مقدار جدید Token را وارد کنید:", $cancelKey);
    setUser("awaitingToken_$id");
}

if (preg_match('/^awaitingToken_(\\d+)/', $userInfo['step'], $match) && $text != $buttonValues['cancel']) {
    $id = intval($match[1]);
    $stmt = $connection->prepare("UPDATE `orders_list` SET `token` = ? WHERE `id` = ?");
    $stmt->bind_param("si", $text, $id);
    $stmt->execute();
    $stmt->close();
    sendMessage("✅ مقدار Token با موفقیت تغییر یافت.");
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
    $order = $result->fetch_assoc();
    showOrderManagementPanel($order, $message_id);
    setUser();
}

if (preg_match('/^changeUUID_(\\d+)/', $data, $match)) {
    $id = intval($match[1]);
    sendMessage("🧬 لطفا مقدار جدید UUID را وارد کنید:", $cancelKey);
    setUser("awaitingUUID_$id");
}

if (preg_match('/^awaitingUUID_(\\d+)/', $userInfo['step'], $match) && $text != $buttonValues['cancel']) {
    $id = intval($match[1]);
    $stmt = $connection->prepare("UPDATE `orders_list` SET `uuid` = ? WHERE `id` = ?");
    $stmt->bind_param("si", $text, $id);
    $stmt->execute();
    $stmt->close();
    sendMessage("✅ مقدار UUID با موفقیت تغییر یافت.");
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
    $order = $result->fetch_assoc();
    showOrderManagementPanel($order, $message_id);
    setUser();
}

if (preg_match('/^changeServerId_(\\d+)/', $data, $match)) {
    $id = intval($match[1]);
    $stmt = $connection->query("SELECT * FROM server_info");
    $keyboard = [];
    while ($row = $stmt->fetch_assoc()) {
        $keyboard[] = [["text" => $row['title'], "callback_data" => "selectServerId_{$id}_{$row['id']}"]];
    }
    $keyboard[] = [["text" => "🔙 بازگشت", "callback_data" => "returnToPanel_$id"]];
    sendMessage("🌐 لطفا سرور جدید را انتخاب کنید:", json_encode(['inline_keyboard' => $keyboard]));
}

if (preg_match('/^selectServerId_(\\d+)_(\\d+)/', $data, $match)) {
    $id = intval($match[1]);
    $newServerId = intval($match[2]);
    $stmt = $connection->prepare("UPDATE `orders_list` SET `server_id` = ? WHERE `id` = ?");
    $stmt->bind_param("ii", $newServerId, $id);
    $stmt->execute();
    $stmt->close();
    sendMessage("✅ مقدار Server ID با موفقیت تغییر یافت.");
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
    $order = $result->fetch_assoc();
    showOrderManagementPanel($order, $message_id);
    setUser();
}

if (preg_match('/^changeFileId_(\\d+)_(\\d+)/', $data, $match)) {
    $id = intval($match[1]);
    $serverId = intval($match[2]);
    $stmt = $connection->prepare("SELECT * FROM server_plans WHERE server_id = ?");
    $stmt->bind_param("i", $serverId);
    $stmt->execute();
    $result = $stmt->get_result();
    $keyboard = [];
    while ($row = $result->fetch_assoc()) {
        $keyboard[] = [["text" => $row['title'], "callback_data" => "selectFileId_{$id}_{$row['id']}"]];
    }
    $keyboard[] = [["text" => "🔙 بازگشت", "callback_data" => "returnToPanel_$id"]];
    sendMessage("📦 لطفا پلن جدید را انتخاب کنید:", json_encode(['inline_keyboard' => $keyboard]));
    $stmt->close();
}

if (preg_match('/^selectFileId_(\\d+)_(\\d+)/', $data, $match)) {
    $id = intval($match[1]);
    $newFileId = intval($match[2]);
    $stmt = $connection->prepare("UPDATE `orders_list` SET `fileid` = ? WHERE `id` = ?");
    $stmt->bind_param("ii", $newFileId, $id);
    $stmt->execute();
    $stmt->close();
    sendMessage("✅ مقدار Fileid (پلن) با موفقیت تغییر یافت.");
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
    $order = $result->fetch_assoc();
    showOrderManagementPanel($order, $message_id);
    setUser();
}

if (preg_match('/^quickUserOrderDetails_(\d+)/', $data, $match)) {
    $orderId = intval($match[1]);

    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();

    if ($result->num_rows == 0) {
        alert("❌ اطلاعات سفارش یافت نشد.");
        return;
    }

    $order = $result->fetch_assoc();
    $keys = getUserOrderDetailKeys($orderId, 0); // فرض: 0 یعنی admin دیده

    if ($keys == null) {
        alert("❌ جزئیاتی برای این سفارش یافت نشد.");
        return;
    }

    sendMessage($keys['msg'], $keys['keyboard'], "HTML");
}

// End of Manage a specific order
//End by ACh

