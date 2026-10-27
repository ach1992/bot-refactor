<?php
/** AChBot V6: original Telegram account/subscription routes; keep caller scope. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG

require_once __DIR__ . '/../Finance/ReceiptHelpers.php';

// هندل دکمه نمایش اشتراک های کاربر
if (preg_match('/^showUserSubscriptions(\d+)/', $data, $match)) {
    $userId = $match[1];
    $userInfo = getUserInfoKeys($userId);
    
    if (isset($userInfo['subscriptionMessages']) && count($userInfo['subscriptionMessages']) > 0) {
        $subscriptionMessages = $userInfo['subscriptionMessages'];
        
        $title = \achbotMessage('subscription.order_management.title.b4f29204c1', ['userId' => $userId]);
        $keyboard = json_encode([
            'inline_keyboard' => [
                [['text' => \achbotMessage('subscription.manual_accounts.manual_account_consume_selection.button.11b0188fcb'), 'callback_data' => "searchUsersConfig"]]
            ]
        ]);

        foreach ($subscriptionMessages as $message) {
            $fullMessage = $title . "\n\n" . $message;
            sendMessage($fullMessage, $keyboard, 'HTML', $callback_query['message']['chat']['id']);
        }
    } else {
        sendMessage(\achbotMessage('subscription.order_management.message.6e1cf97901'), null, 'HTML', $callback_query['message']['chat']['id']);
    }
}

// Begin of Manage a specific order
if ($data == "manageorderdatabase" && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    delMessage();
    sendMessage(\achbotMessage('subscription.order_management.manageorderdatabase.message.e16b131103'), $cancelKey, "Markdown");
    setUser("awaitingOrderRemarkOnly");
}
if ($userInfo['step'] == "awaitingOrderRemarkOnly" && $text != $buttonValues['cancel']) {
    $remark = trim($text);
    $remark = mysqli_real_escape_string($connection, $remark);

    // بررسی کاراکترهای معتبر
    if (!preg_match('/^[a-zA-Z0-9\s\-_]+$/', $remark)) {
        sendMessage(\achbotMessage('subscription.order_management.awaiting_order_remark_only.message.a94b913d39'), $cancelKey);
        setUser();
        return;
    }

    $stmt = $connection->prepare("SELECT * FROM orders_list WHERE remark = ?");
    if (!$stmt) {
        sendMessage(\achbotMessage('subscription.order_management.awaiting_order_remark_only.message.99b2e0169a'), $cancelKey);
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
                [['text' => \achbotMessage('subscription.order_management.awaiting_order_remark_only.button.76f3daea16'), 'callback_data' => "manageorderdatabase"]]
            ]
        ]);
        sendMessage(\achbotMessage('subscription.order_management.awaiting_order_remark_only.message.2ae43a0263'), $keyboard);
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
                sendMessage(\achbotMessage('subscription.order_management.awaiting_order_remark_only.message.f8c9916b9c'), $cancelKey);
                setUser();
                return;
            }
            $useridList[] = $row['userid'];
            $callbackData = "selectOrderByRemark_userid_{$row['userid']}_tempId_" . $tempRemarkId;
            if (strlen($callbackData) > 64) {
                sendMessage(\achbotMessage('subscription.order_management.awaiting_order_remark_only.message.45a8b2af73'), $cancelKey);
                setUser();
                return;
            }
            $keyboard['inline_keyboard'][] = [['text' => "{$row['userid']}", 'callback_data' => $callbackData]];
            $counter++;
        }
    }
    $keyboard['inline_keyboard'][] = [['text' => \achbotMessage('subscription.order_management.awaiting_order_remark_only.button.76f3daea16'), 'callback_data' => "manageorderdatabase"]];

    // ارسال پیام با مدیریت خطا
    $response = sendMessage(\achbotMessage('subscription.order_management.awaiting_order_remark_only.message.e02c72df1d'), json_encode($keyboard));
    if (!$response) {
        sendMessage(\achbotMessage('subscription.order_management.awaiting_order_remark_only.message.b345c0eb1e'), $cancelKey);
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
        sendMessage(\achbotMessage('subscription.order_management.awaiting_order_remark_only.message.7ebcf50a27'), $cancelKey);
        setUser();
        return;
    }

    $stmt = $connection->prepare("SELECT * FROM orders_list WHERE userid = ? AND remark = ? LIMIT 1");
    if (!$stmt) {
        sendMessage(\achbotMessage('subscription.order_management.awaiting_order_remark_only.message.99b2e0169a'), $cancelKey);
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
                [['text' => \achbotMessage('subscription.order_management.awaiting_order_remark_only.button.76f3daea16'), 'callback_data' => "manageorderdatabase"]]
            ]
        ]);
        sendMessage(\achbotMessage('subscription.order_management.awaiting_order_remark_only.message.d11ef21837'), $keyboard);
        setUser();
        return;
    }

    $order = $result->fetch_assoc();

    setUser("manageOrderById_" . $order['id']);
    showOrderManagementPanel($order);
}

function showOrderManagementPanel($order, $msgId = null){
	
    $notifStatus = $order['notif'] == 0 ? \achbotMessage('subscription.order_management.show_order_management_panel.text.06915a0abe') : \achbotMessage('subscription.order_management.show_order_management_panel.text.159100fdd2');
	$agentStatus = $order['agent_bought'] == 0 ? \achbotMessage('subscription.order_management.show_order_management_panel.text.44f1a0048c') : \achbotMessage('subscription.order_management.show_order_management_panel.text.e0b20b49a2');

    $keyboard = [
        [["text" => \achbotMessage('subscription.order_management.show_order_management_panel.button.76ac22f123'), "callback_data" => "deleteOrder_{$order['id']}"]],
        [["text" => \achbotMessage('subscription.order_management.show_order_management_panel.button.febe263cbd'), "callback_data" => "changeExpireDate_{$order['id']}"]],
        [["text" => \achbotMessage('subscription.order_management.show_order_management_panel.button.7256e018bd'), "callback_data" => "changeToken_{$order['id']}"]],
        [["text" => \achbotMessage('subscription.order_management.show_order_management_panel.button.8824464622'), "callback_data" => "changeServerId_{$order['id']}"]],
        [["text" => \achbotMessage('subscription.order_management.show_order_management_panel.button.fb28941abf'), "callback_data" => "changeFileId_{$order['id']}_{$order['server_id']}"]],
        [["text" => \achbotMessage('subscription.order_management.show_order_management_panel.button.8851b5ce4f'), "callback_data" => "changeUUID_{$order['id']}"]],
		[["text" => \achbotMessage('subscription.order_management.show_order_management_panel.button.72e5322e9a', ['agentStatus' => $agentStatus]), "callback_data" => "toggleAgent_{$order['id']}"]],
        [["text" => \achbotMessage('subscription.order_management.show_order_management_panel.button.8c85c7815c', ['notifStatus' => $notifStatus]), "callback_data" => "toggleNotif_{$order['id']}"]],
		[["text" => \achbotMessage('subscription.order_management.show_order_management_panel.button.bf169ddbf3'), "callback_data" => "quickUserOrderDetails_{$order['id']}"]],
        [["text" => \achbotMessage('subscription.order_management.show_order_management_panel.button.087a5a43b1'), "callback_data" => "managePanel"]],
    ];

    if (achbotProviderType($GLOBALS['connection'],(int)$order['server_id'])==='pasarguard')
        array_splice($keyboard,1,0,[[['text'=>\achbotMessage('subscription.order_management.show_order_management_panel.button.198b410113'),'callback_data'=>'pgResetAccount'.(int)$order['id']]]]);

    $msg = \achbotMessage('subscription.order_management.show_order_management_panel.msg.4292e04017', ['order_userid' => $order['userid'], 'order_remark' => $order['remark']]);

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
    // Telegram callback_data is arbitrary user input, not proof of role.
    // users.isAdmin is a MariaDB TINYINT, returned as 1/"1", not true.
    // Reject any value other than the explicit delegated-admin flag.
    if ((int)$from_id!==(int)$admin &&
        (int)($userInfo['isAdmin']??0)!==1) {
        alert(\achbotMessage('subscription.order_management.show_order_management_panel.alert.c9b07798db'), true);
        return;
    }
    $id = intval($match[1]);

    // دریافت اطلاعات قبل از حذف
    $stmt = $connection->prepare("SELECT userid, remark FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();

    if ($result->num_rows == 0) {
        alert(\achbotMessage('subscription.order_management.show_order_management_panel.alert.0ffc3a1ad2'));
        return;
    }

    $row = $result->fetch_assoc();
    $userid = $row['userid'];
    $remark = $row['remark'];

    // A local-only operator deletion still needs the same durable fence.
    // A historical renewal journal MUST remain intact after order deletion.
    if (!achbotAutoRenewPrepareDeletion($connection,(int)$id)) {
        alert(\achbotMessage('subscription.order_management.show_order_management_panel.alert.abcbb638e7'), true);
        return;
    }
    $stmt = $connection->prepare("SELECT server_id FROM orders_list WHERE id=?");
    $stmt->bind_param('i',$id);
    $stmt->execute();
    $server=(int)($stmt->get_result()->fetch_assoc()['server_id']??0);
    $stmt->close();
    if (!achbotAutoRenewFinalizeDeletion($connection,(int)$id,$server,false)) {
        error_log('achbot_operator_local_order_delete_deferred order_id='.(int)$id);
        alert(\achbotMessage('subscription.order_management.show_order_management_panel.alert.3781a25fa3'), true);
        return;
    }

    // پیام برای کاربر
    alert(\achbotMessage('subscription.order_management.show_order_management_panel.alert.1340fd2124'));
    sendMessage(\achbotMessage('subscription.order_management.show_order_management_panel.message.606600ccca', ['userid' => $userid, 'remark' => $remark]), null, "html");

    // پیام برای ادمین ها
    $adminMessage = \achbotMessage('subscription.order_management.show_order_management_panel.admin_message.c7c4748cee', ['userid' => $userid, 'remark' => $remark, 'from_id' => $from_id]);
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
    sendMessage(\achbotMessage('subscription.order_management.show_order_management_panel.message.27a65c7f2c'), $cancelKey, "Markdown");
    setUser("awaitingExpireChange_$id");
}

if (preg_match('/^awaitingExpireChange_(\\d+)/', $userInfo['step'], $match) && is_numeric($text)) {
    $id = intval($match[1]);
    $days = intval($text);
    $stmt = $connection->prepare("UPDATE `orders_list` SET `expire_date` = `expire_date` + (? * 86400) WHERE `id` = ?");
    $stmt->bind_param("ii", $days, $id);
    $stmt->execute();
    $stmt->close();

    sendMessage(\achbotMessage('subscription.order_management.show_order_management_panel.message.86c15dd0ed'));
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
    sendMessage(\achbotMessage('subscription.order_management.show_order_management_panel.message.f6d2b05a49'), $cancelKey);
    setUser("awaitingToken_$id");
}

if (preg_match('/^awaitingToken_(\\d+)/', $userInfo['step'], $match) && $text != $buttonValues['cancel']) {
    $id = intval($match[1]);
    $stmt = $connection->prepare("UPDATE `orders_list` SET `token` = ? WHERE `id` = ?");
    $stmt->bind_param("si", $text, $id);
    $stmt->execute();
    $stmt->close();
    sendMessage(\achbotMessage('subscription.order_management.show_order_management_panel.message.18947492b0'));
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
    sendMessage(\achbotMessage('subscription.order_management.show_order_management_panel.message.1ea9c7448e'), $cancelKey);
    setUser("awaitingUUID_$id");
}

if (preg_match('/^awaitingUUID_(\\d+)/', $userInfo['step'], $match) && $text != $buttonValues['cancel']) {
    $id = intval($match[1]);
    $stmt = $connection->prepare("UPDATE `orders_list` SET `uuid` = ? WHERE `id` = ?");
    $stmt->bind_param("si", $text, $id);
    $stmt->execute();
    $stmt->close();
    sendMessage(\achbotMessage('subscription.order_management.show_order_management_panel.message.3688781e63'));
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
    $order = $result->fetch_assoc();
    showOrderManagementPanel($order, $message_id);
    setUser();
}

// An admin may change the logical service label only within one verified
// Marzban panel. Cached callbacks are revalidated against current SQL state.
if (preg_match('/^changeServerId_(\d+)$/D', (string)($data ?? ''), $match)) {
    if (!($from_id == $admin || ($userInfo['isAdmin'] ?? false))) {
        alert(\achbotMessage('subscription.order_management.show_order_management_panel.alert.057ec5448c'), true);
        return;
    }
    $id = (int)$match[1];
    $stmt = $connection->prepare(
        "SELECT `server_id` FROM `orders_list` WHERE `id` = ? AND `status` = 1"
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$order || !achbotSupportsOrder($connection, $id)) {
        alert(\achbotMessage('subscription.order_management.show_order_management_panel.alert.3e801d365f'), true);
        return;
    }
    $targets = achbotProviderRelocationTargets($connection, (int)$order['server_id']);
    if (!$targets) {
        alert(\achbotMessage('subscription.order_management.show_order_management_panel.alert.7a6ebab798'), true);
        return;
    }
    $keyboard = [];
    foreach ($targets as $server) {
        $keyboard[] = [['text'=>$server['title'],
            'callback_data'=>'selectServerId_' . $id . '_' . (int)$server['id']]];
    }
    $keyboard[] = [['text'=>\achbotMessage('subscription.order_management.show_order_management_panel.button.b35a2352f5'), 'callback_data'=>'returnToPanel_' . $id]];
    sendMessage(\achbotMessage('subscription.order_management.show_order_management_panel.message.557fb66856'),
        json_encode(['inline_keyboard'=>$keyboard], JSON_UNESCAPED_UNICODE));
    return;
}

if (preg_match('/^selectServerId_(\d+)_(\d+)$/D', (string)($data ?? ''), $match)) {
    if (!($from_id == $admin || ($userInfo['isAdmin'] ?? false))) {
        alert(\achbotMessage('subscription.order_management.show_order_management_panel.alert.057ec5448c'), true);
        return;
    }
    $id = (int)$match[1];
    $targetId = (int)$match[2];
    $result = achbotReassignLogicalServer($connection, $id, $targetId, $from_id, true);
    if (!$result) {
        alert(\achbotMessage('subscription.order_management.show_order_management_panel.alert.c5dae93802'), true);
        return;
    }
    sendMessage(\achbotMessage('subscription.order_management.show_order_management_panel.message.88e0b88b0d'));
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    showOrderManagementPanel($order, $message_id);
    setUser();
    return;
}

if (preg_match('/^changeFileId_(\\d+)_(\\d+)$/', (string) ($data ?? ''), $match)) {
    $id = (int)$match[1];
    $serverId = (int)$match[2];
    // A cached plan-selector callback cannot supply another server id.
    if (!achbotSupportsOrder($connection, $id) || $serverId <= 0) {
        alert(\achbotMessage('subscription.order_management.show_order_management_panel.alert.3e801d365f'), true);
        return;
    }
    $stmt = $connection->prepare(
        "SELECT p.`id`, p.`title` FROM `server_plans` p
         INNER JOIN `server_config` c ON c.`id` = p.`server_id`
         INNER JOIN `orders_list` o ON o.`id` = ? AND o.`server_id` = p.`server_id`
         WHERE p.`server_id` = ? AND p.`inbound_id` = 0 AND c.`type` IN ('marzban','pasarguard')"
    );
    $stmt->bind_param("ii", $id, $serverId);
    $stmt->execute();
    $result = $stmt->get_result();
    $keyboard = [];
    while ($row = $result->fetch_assoc()) {
        $keyboard[] = [["text" => $row['title'], "callback_data" => "selectFileId_{$id}_{$row['id']}"]];
    }
    $stmt->close();
    $keyboard[] = [["text" => \achbotMessage('subscription.order_management.show_order_management_panel.button.b35a2352f5'), "callback_data" => "returnToPanel_$id"]];
    sendMessage(\achbotMessage('subscription.order_management.show_order_management_panel.message.877fdebc48'), json_encode(['inline_keyboard' => $keyboard]));
}

if (preg_match('/^selectFileId_(\\d+)_(\\d+)$/', (string) ($data ?? ''), $match)) {
    $id = (int)$match[1];
    $newFileId = (int)$match[2];
    // Lock the order before rechecking its server and destination plan:
    // no forged/stale callback may attach an obsolete inbound or another
    // server's plan to an otherwise supported Marzban account.
    $connection->begin_transaction();
    try {
        $stmt = $connection->prepare("SELECT `server_id` FROM `orders_list` WHERE `id` = ? FOR UPDATE");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $locked = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$locked || !achbotSupportsOrder($connection, $id) ||
            !achbotSupportsPlan($connection, $newFileId, 0, $locked['server_id'])) {
            $connection->rollback();
            alert(\achbotMessage('subscription.order_management.show_order_management_panel.alert.f19b5510c6'), true);
            return;
        }
        $stmt = $connection->prepare("UPDATE `orders_list` SET `fileid` = ? WHERE `id` = ?");
        $stmt->bind_param("ii", $newFileId, $id);
        $stmt->execute();
        $stmt->close();
        $connection->commit();
    } catch (\Throwable $e) {
        $connection->rollback();
        alert(\achbotMessage('subscription.order_management.show_order_management_panel.alert.99d247fc18'), true);
        return;
    }
    sendMessage(\achbotMessage('subscription.order_management.show_order_management_panel.message.75785b37ae'));
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
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
        alert(\achbotMessage('subscription.order_management.show_order_management_panel.alert.ace28d61b3'));
        return;
    }

    $order = $result->fetch_assoc();
    $keys = getUserOrderDetailKeys($orderId, 0); // فرض: 0 یعنی admin دیده

    if ($keys == null) {
        alert(\achbotMessage('subscription.order_management.show_order_management_panel.alert.afe3a5b10e'));
        return;
    }

    sendMessage($keys['msg'], $keys['keyboard'], "HTML");
}

// End of Manage a specific order
//End by ACh

