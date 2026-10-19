<?php
/** AChBot V5: expiry and status notifications; legacy caller scope and side effects are intentional. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

$time = time(); 

// خواندن مقدار warnOffset از جدول setting
$stmt = $connection->prepare("SELECT `value` FROM `setting` WHERE `type` = 'warnOffset'");
$stmt->execute();
$result = $stmt->get_result();
$stmt->close();

if ($result && $result->num_rows > 0) {
    $warnOffset = $result->fetch_assoc()['value'];
} else {
    $warnOffset = 0;
    $stmt = $connection->prepare("INSERT INTO `setting` (`type`, `value`) VALUES ('warnOffset', ?)");
    $stmt->bind_param("i", $warnOffset);
    $stmt->execute();
    $stmt->close();
}

$limit = 100;

$stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `status`=1 AND (`notif`=0 OR `notif` = -1) ORDER BY `id` ASC LIMIT ? OFFSET ?");
$stmt->bind_param("ii", $limit, $warnOffset);
$stmt->execute();
$orders = $stmt->get_result();
$stmt->close();

if ($orders && $orders->num_rows > 0) { 
    while ($order = $orders->fetch_assoc()) { 
        $from_id = $order['userid']; 
        $remark = $order['remark']; 
        $uuid = $order['uuid'] ?? "0"; 
        $server_id = $order['server_id']; 
        $inbound_id = $order['inbound_id']; 
        $notif = $order['notif']; 
        $expiryTime = ""; 
        $found = false; 
        $enable = false; 

        $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id` = ?");
        $stmt->bind_param('i', $server_id);
        $stmt->execute();
        $serverConfig = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $serverType = $serverConfig['type'];

        if (achbotSupportsMarzbanAccount($serverType, $inbound_id)) {
            $info = getMarzbanUser($server_id, $remark);
            if (isset($info->username)) {
                $found = true;
                $total = $info->data_limit;
                $totalLeft = $total - $info->used_traffic;
                $expiryTime = $info->expire;
                $enable = $info->status == "active";
            }
        } else {
                continue; // Retired panel; do not notify/delete a different account.
            }

        if (!$found) continue;

        $leftgb = round($totalLeft / 1073741824, 2);

        // تبدیل تاریخ به timestamp در صورت نیاز
        if (!is_numeric($expiryTime)) {
            $expiryTime = strtotime($expiryTime);
        }

        if ($expiryTime && $notif == 0) {
            $send = "";
            if ($expiryTime < time() + 129600) {
                $send = "1 روز";
            }
            elseif ($leftgb < 0.5 && $total != null && $total > 0) {
                $send = "0.5 گیگ";
            }

            if ($send !== "") {
				if ((int)$order['amount'] !== 0) {
					$msg = "💡 کاربر گرامی، اشتراک  <code>$remark</code>  به زودی تمام می شود. کمتر از  <b>$send</b>  مانده است. 
🔰 می‌توانید از ← 🔄 <b>مشاهده اشتراک ها</b> آن را تمدید کنید یا اشتراک جدید خریداری کنید.  
🛑 بعد از اتمام در صورتی که <b>تا 3 روز</b> تمدید نکنید از لیست اشتراک های شما حذف می شود.
💳 می توانید <b>قبل از اتمام، کیف پول خودتان را شارژ کنید</b> و از بخش <b>مشاهده اشتراک ها تمدید خودکار را روشن کنید</b> تا به صورت خودکار وقتی اشتراک تمام شد تمدید شود."; 
					$keyboard = [ 
						[ 
							['text' => $buttonValues['my_subscriptions'], 'callback_data' => 'mySubscriptions'], 
							['text' => $buttonValues['sharj'], 'callback_data' => 'increaseMyWallet'] 
						] 
					];

					sendMessage($msg, json_encode(['inline_keyboard' => $keyboard]), "html", $from_id);
				}

                $stmt = $connection->prepare("UPDATE `orders_list` SET `notif`= -1 WHERE `uuid`=?");
                $stmt->bind_param("s", $uuid);
                $stmt->execute();
                $stmt->close();
            }
        } elseif (!$enable) {
            $newTIme = $time + 86400 * 10;
            $stmt = $connection->prepare("UPDATE `orders_list` SET `notif`= ? WHERE `uuid`=?");
            $stmt->bind_param("is", $newTIme, $uuid);
            $stmt->execute();
            $stmt->close();
        }
    }

	$totalOrdersQuery = "SELECT COUNT(*) FROM `orders_list` WHERE `status` = 1 AND (`notif` = 0 OR `notif` = -1)";
	$result = $connection->query($totalOrdersQuery);
	$totalOrders = $result->fetch_row()[0];

	if ($orders->num_rows < $limit || $totalOrders - $warnOffset <= $limit) {
		$newWarnOffset = 0;
	} else {
		$newWarnOffset = $warnOffset + $limit;
	}

	// به روز رسانی warnOffset در دیتابیس
	$stmt = $connection->prepare("UPDATE `setting` SET `value` = ? WHERE `type` = 'warnOffset'");
	$stmt->bind_param('i', $newWarnOffset);
	$stmt->execute();
	$stmt->close();
}

$stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `status`=1 AND `notif` > 0 AND `notif` < ? LIMIT 50");
$stmt->bind_param("i", $time);
$stmt->execute();
$orders = $stmt->get_result();
$stmt->close();

if ($orders) {
    if ($orders->num_rows > 0) {
        while ($order = $orders->fetch_assoc()) {
            $send = false;
            $from_id = $order['userid'];
            $token = $order['token'];
            $remark = $order['remark'];
            $uuid = $order['uuid'] ?? "0";
            $server_id = $order['server_id'];
            $inbound_id = $order['inbound_id'];
            $links_list = $order['link'];
            $notif = $order['notif'];

            $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id` = ?");
            $stmt->bind_param('i', $server_id);
            $stmt->execute();
            $serverConfig = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $serverType = $serverConfig['type'];
            $panel_url = $serverConfig['panel_url'];

            $found = false;
            $logedIn = false;

            if (achbotSupportsMarzbanAccount($serverType, $inbound_id)) {
                $info = getMarzbanUser($server_id, $remark);
                if (isset($info->username)) {
                    $found = true;
                    $logedIn = true;
                    $total = $info->data_limit;
                    $totalLeft = $total - $info->used_traffic;
                    $expiryTime = $info->expire;
                    $enable = $info->status == "active" ? true : false;
                } elseif (isset($info->detail)) {
                    if ($info->detail == "User not found") $logedIn = true;
                }
            } else {
                continue; // Retired panel; do not notify/delete a different account.
            }

            if (!$found && !$logedIn) continue;

            $leftgb = round(($totalLeft) / 1073741824, 2);
            if ($expiryTime <= time()) $send = true;
            elseif ($leftgb <= 0) $send = true;

            if ($send) {
                if (!achbotSupportsPanel($serverType)) continue;
                // Deletion reservation atomically blocks new renewal and
                // rejects unresolved payment effects BEFORE any remote call.
                if (!achbotAutoRenewPrepareDeletion($connection,(int)$order['id']))
                    continue;
                try {
                    $res = deleteMarzban($server_id, $remark);
                } catch (Throwable $error) {
                    error_log('achbot_warning_remote_delete_deferred order_id='.(int)$order['id']);
                    continue;
                }
                if (is_object($res) && ($res->success??false)===true &&
                    achbotAutoRenewFinalizeDeletion(
                        $connection,(int)$order['id'],(int)$server_id,false)) {
                    $msg = "💡 کاربر گرامی، اشتراک  <code>$remark</code>  تمام شده بود و از لیست اشتراک ها حذف گردید. لطفا اشتراک جدید خریداری کنید.";
                    $keyboard = [
                        [['text' => $buttonValues['back_to_main'], 'callback_data' => 'mainMenu']]
                    ];
                    sendMessage($msg, json_encode(['inline_keyboard' => $keyboard]), "html", $from_id);

                    // ارسال پیام به ادمین
                    $channelMessage = "
❌ اشتراک منقضی شده حذف شد.

👤 آیدی کاربر: $from_id
🎈 نام کاربری سرویس: $remark
";
                    //sendMessage($channelMessage, null, "html", $admin);
                    //sendToAllAdmins('sendMessage', ['text' => $channelMessage, 'parse_mode' => "html"]);
					
					// ارسال پیام به کانال گزارشات
					$rewardChannel = $botState['rewardChannel'];
					if (!empty($rewardChannel)) {
						sendMessage($channelMessage, null, "html", $rewardChannel);
					}

                    continue;
                }
                error_log('achbot_warning_local_delete_deferred order_id='.(int)$order['id']);
                // status=0 retains an operator-recoverable local record:
                // a later Cron tick never blindly repeats the remote DELETE.
                continue;
            } else {
                $stmt = $connection->prepare("UPDATE `orders_list` SET `notif`= 0 WHERE `uuid`=?");
                $stmt->bind_param("s", $uuid);
                $stmt->execute();
                $stmt->close();
            }
        }
    }
}

