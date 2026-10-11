<?php

include_once __DIR__ . '/../baseInfo.php';
include_once __DIR__ . '/../config.php';

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

        if ($serverType == "marzban") {
            $info = getMarzbanUser($server_id, $remark);
            if (isset($info->username)) {
                $found = true;
                $total = $info->data_limit;
                $totalLeft = $total - $info->used_traffic;
                $expiryTime = $info->expire;
                $enable = $info->status == "active";
            }
        } else {
            $response = getJson($server_id);
            if ($response->success) {
                foreach ($response->obj as $row) {
                    if ($row->id == $inbound_id || $inbound_id == 0) {
                        $clients = json_decode($row->settings)->clients ?? [];
                        foreach ($clients as $client) {
                            if ($client->id == $uuid || $client->password == $uuid) {
                                $found = true;
                                $total = $row->total ?? ($client->totalGB * 1073741824);
                                $up = $row->up ?? 0;
                                $down = $row->down ?? 0;
                                $totalLeft = $total - $up - $down;
                                $expiryTime = substr($row->expiryTime ?? '', 0, -3);
                                $enable = $row->enable ?? false;
                                break 2;
                            }
                        }
                    }
                }
            }
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

            if ($serverType == "marzban") {
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
                $response = getJson($server_id);
                if ($response->success) {
                    $logedIn = true;
                    $response = $response->obj;
                    foreach ($response as $row) {
                        if ($inbound_id == 0) {
                            $clients = json_decode($row->settings)->clients;
                            if ($clients[0]->id == $uuid || $clients[0]->password == $uuid) {
                                $total = $row->total;
                                $up = $row->up;
                                $down = $row->down;
                                $expiryTime = substr($row->expiryTime, 0, -3);
                                $enable = $row->enable;
                                $found = true;
                                break;
                            }
                        } else {
                            if ($row->id == $inbound_id) {
                                $settings = json_decode($row->settings, true);
                                $clients = $settings['clients'];

                                $clientsStates = $row->clientStats;
                                foreach ($clients as $key => $client) {
                                    if ($client['id'] == $uuid || $client['password'] == $uuid) {
                                        $email = $client['email'];
                                        $emails = array_column($clientsStates, 'email');
                                        $emailKey = array_search($email, $emails);

                                        $total = $client['totalGB'];
                                        $up = $clientsStates[$emailKey]->up;
                                        $enable = $clientsStates[$emailKey]->enable;
                                        $down = $clientsStates[$emailKey]->down;
                                        $expiryTime = substr($clientsStates[$emailKey]->expiryTime, 0, -3);
                                        $found = true;
                                        break;
                                    }
                                }
                            }
                        }
                    }
                    $totalLeft = $total - $up - $down;
                }
            }

            if (!$found && !$logedIn) continue;

            $leftgb = round(($totalLeft) / 1073741824, 2);
            if ($expiryTime <= time()) $send = true;
            elseif ($leftgb <= 0) $send = true;

            if ($send) {
                if ($serverType == "marzban") $res = deleteMarzban($server_id, $remark);
                else {
                    if ($inbound_id > 0) $res = deleteClient($server_id, $inbound_id, $uuid, 1);
                    else $res = deleteInbound($server_id, $uuid, 1);
                }

                if (!is_null($res)) {
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

                    $stmt = $connection->prepare("DELETE FROM `orders_list` WHERE `uuid`=?");
                    $stmt->bind_param("s", $uuid);
                    $stmt->execute();
                    $stmt->close();
                    continue;
                }
            } else {
                $stmt = $connection->prepare("UPDATE `orders_list` SET `notif`= 0 WHERE `uuid`=?");
                $stmt->bind_param("s", $uuid);
                $stmt->execute();
                $stmt->close();
            }
        }
    }
}

global $connection, $botState;

// دریافت یا ایجاد سطر مربوط به AutoRenewCheck (افست)
$stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'AutoRenewCheck'");
$stmt->execute();
$result = $stmt->get_result();
$stmt->close();

if ($result->num_rows > 0) {
    $setting = $result->fetch_assoc();
    $offset = $setting['value'];
} else {
    $offset = 0;
    $stmt = $connection->prepare("INSERT INTO `setting` (`type`, `value`) VALUES ('AutoRenewCheck', ?)");
    $stmt->bind_param("i", $offset);
    $stmt->execute();
    $stmt->close();
}

if ($botState['renewAutoState'] == "on") {
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `status`=1 AND `notif` != 0 AND `autoRenew` = 'on' ORDER BY `id` ASC LIMIT 50 OFFSET ?");
    $stmt->bind_param("i", $offset);
    $stmt->execute();
    $orders = $stmt->get_result();
    $stmt->close();

    $numChecked = 0;
    if ($orders && $orders->num_rows > 0) {
        while ($order = $orders->fetch_assoc()) {
            $from_id = $order['userid'];
            $remark = $order['remark'];
            $oid = $order['id'];
            $server_id = $order['server_id'];
            $notif = $order['notif'];
            $expiryTime = "";
            $found = false;
            $enable = false;

            // دریافت اطلاعات سرور
            $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id` = ?");
            $stmt->bind_param('i', $server_id);
            $stmt->execute();
            $serverConfig = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $serverType = $serverConfig['type'];

            if ($serverType == "marzban") {
                $info = getMarzbanUser($server_id, $remark);
                if (isset($info->username)) {
                    $found = true;
                    $total = $info->data_limit;
                    $totalLeft = $total - $info->used_traffic;
                    $expiryTime = $info->expire;
                    $enable = $info->status == "active";
                }
            }

            if (!$found) continue;

            $leftgb = round($totalLeft / 1073741824, 2);

            if (!is_numeric($expiryTime)) {
                $expiryTime = strtotime($expiryTime);
            }

            if ($expiryTime) {
                if ($expiryTime < time() + 7200) {
                    $data = "renewAuto_with_wallet|$from_id|$oid";
					handleAutoRenewCallback($data);
                }
                elseif ($leftgb < 0.2 && $total != null && $total > 0) {
                    $data = "renewAuto_with_wallet|$from_id|$oid";
					handleAutoRenewCallback($data);
                }
            }

            $numChecked++;
        }

        $newOffset = $offset + $numChecked;
        $stmt = $connection->prepare("UPDATE `setting` SET `value` = ? WHERE `type` = 'AutoRenewCheck'");
        $stmt->bind_param("i", $newOffset);
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $connection->prepare("UPDATE `setting` SET `value` = 0 WHERE `type` = 'AutoRenewCheck'");
        $stmt->execute();
        $stmt->close();
    }
}

// Auto Renewal Process
function handleAutoRenewCallback($data) {
    global $connection, $userInfo, $from_id, $admin, $botState, $buttonValues, $time, $mainValues;

    if (preg_match('/renewAuto_with_wallet\|(\d+)\|(\d+)/', $data, $match)) {
        $from_id = intval($match[1]);
        $oid = intval($match[2]);

        // دریافت اطلاعات سفارش
        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
        $stmt->bind_param("i", $oid);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$order) return;

        $remark = $order['remark'];
        $server_id = $order['server_id'];
        $inbound_id = $order['inbound_id'];
        $expire_date = $order['expire_date'];
        $expire_date = ($expire_date > $time) ? $expire_date : $time;
        $fid = (int)$order['fileid'];

        // دریافت اطلاعات پلن سرور
        $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ? AND `active` = 1");
        $stmt->bind_param("i", $fid);
        $stmt->execute();
        $serverPlan = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$serverPlan) return;

        if ((float)$serverPlan['price'] <= 0) {

            // غیرفعال سازی تمدید خودکار تا دوباره تلاش نکند
            $stmt = $connection->prepare("UPDATE `orders_list` SET `autoRenew` = 'off' WHERE `id` = ?");
            $stmt->bind_param("i", $oid);
            $stmt->execute();
            $stmt->close();

            $message = "⚠️ امکان تمدید خودکار برای این اشتراک وجود ندارد چون مبلغ پلن صفر است.\n\n"
                     . "✏️ نام کاربری اشتراک: <code>$remark</code>\n"
                     . "⚠️ تمدید خودکار برای شما غیرفعال شد.";
            sendMessage($message, $mainValues['user_keys'], "html", $from_id);

            return;
        }

        $days = (int)$serverPlan['days'];
        $volume = $serverPlan['volume'];
        $price = (int)$serverPlan['price'];

        // بررسی کیف پول کاربر (قبل از کم شدن)
        $stmt = $connection->prepare("SELECT wallet FROM `users` WHERE `userid` = ?");
        $stmt->bind_param("i", $from_id);
        $stmt->execute();
        $userInfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $userwallet = (int)($userInfo['wallet'] ?? 0);
        $beforeWallet = $userwallet;
        $afterWallet = $userwallet - $price;

        if ($userwallet < $price) {
            // غیرفعال سازی تمدید خودکار
            $stmt = $connection->prepare("UPDATE `orders_list` SET `autoRenew` = 'off' WHERE `id` = ?");
            $stmt->bind_param("i", $oid);
            $stmt->execute();
            $stmt->close();

            $message = "❌ موجودی کیف پول برای <b>تمدید خودکار</b> کافی نیست.\n\n"
                . "✏️ نام کاربری اشتراک: $remark\n"
                . "💵 موجودی فعلی: " . number_format($userwallet) . " تومان\n"
                . "🔺 مورد نیاز: " . number_format($price - $userwallet) . " تومان\n"
                . "⚠️ نکته: <b>تمدید خودکار برای شما غیرفعال شد.</b> کیف پول خود را شارژ کنید و دوباره تمدید خودکار را از بخش <b>مشاهده اشتراک ها</b> فعال کنید.";

            $keyboard = [
                [
                    ['text' => $buttonValues['my_subscriptions'], 'callback_data' => 'mySubscriptions'],
                    ['text' => $buttonValues['sharj'], 'callback_data' => 'increaseMyWallet']
                ]
            ];
            sendMessage($message, json_encode(['inline_keyboard' => $keyboard]), "html", $from_id);
            return;
        }

        // دریافت اطلاعات کانفیگ سرور
        $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id` = ?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $server_info = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $serverType = $server_info['type'] ?? '';

        // تمدید روی سرور
        if ($serverType == "marzban") {
            $response = editMarzbanConfig($server_id, ['remark' => $remark, 'days' => $days, 'volume' => $volume]);
        } else {
            if ($inbound_id > 0) {
                $response = editClientTraffic($server_id, $inbound_id, $order['uuid'], $volume, $days, "renew");
            } else {
                $response = editInboundTraffic($server_id, $order['uuid'], $volume, $days, "renew");
            }
        }

        if (is_null($response)) {
            alert('🔻مشکل فنی در اتصال به سرور هنگام تمدید خودکار، لطفا به مدیریت اطلاع دهید.', true);
            return;
        }

        // بروزرسانی سفارش
        $newExpire = $time + $days * 86400;
        $stmt = $connection->prepare("UPDATE `orders_list` SET `expire_date` = ?, `notif` = 0 WHERE `id` = ?");
        $stmt->bind_param("ii", $newExpire, $oid);
        $stmt->execute();
        $stmt->close();

        // ثبت افزایش اشتراک
        $stmt = $connection->prepare("INSERT INTO `increase_order` (`userid`, `server_id`, `inbound_id`, `remark`, `amount`, `date`) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iiisii", $from_id, $server_id, $inbound_id, $remark, $price, $time);
        $stmt->execute();
        $stmt->close();

        // کم کردن از کیف پول
        $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` - ? WHERE `userid` = ?");
        $stmt->bind_param("ii", $price, $from_id);
        $stmt->execute();
        $stmt->close();

        // ارسال پیام کامل به کاربر
        $message = "✅ اشتراک <code>{$remark}</code> به صورت خودکار تمدید شد.\n\n"
            . "🔼 موجودی قبل از تمدید: " . number_format($beforeWallet) . " تومان\n"
            . "🔽 موجودی بعد از تمدید: " . number_format($afterWallet) . " تومان";
        sendMessage($message, $mainValues['user_keys'], "html", $from_id);

        // ثبت تراکنش در جدول pays
        $hash_id = uniqid('pay_');
        $description = "autoRenew_with_wallet";
        $payid = "";
        $type = "RENEW_ACCOUNT";
        $plan_id = isset($serverPlan['id']) ? (int)$serverPlan['id'] : 0;
        $state = "paid_with_wallet";
        $user_id = $from_id;
        $server_id = (int)$server_id;
        $volume = isset($serverPlan['volume']) ? $serverPlan['volume'] : 0;
        $days = isset($serverPlan['days']) ? (int)$serverPlan['days'] : 0;
        $price = isset($serverPlan['price']) ? (int)$serverPlan['price'] : 0;
        $request_date = (int)$time;
        $order_id = (int)$oid;

        $stmt = $connection->prepare("INSERT INTO `pays` (
          hash_id, description, payid, user_id, type, plan_id, server_id, volume, day, price, request_date, state, order_id
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $stmt->bind_param(
            "sssissiiiiisi",
            $hash_id,
            $description,
            $payid,
            $user_id,
            $type,
            $plan_id,
            $server_id,
            $volume,
            $days,
            $price,
            $request_date,
            $state,
            $order_id
        );
        $stmt->execute();
        $stmt->close();

        // ارسال پیام به ادمین و کانال با ذکر موجودی قبل و بعد
        $channelMessage = "✅ تمدید خودکار اشتراک برای کاربر <code>$from_id</code> انجام شد.\n\n"
            . "👤 نام کاربری اشتراک: <code>$remark</code>\n"
            . "💰 مبلغ کسر شده: " . number_format($price) . " تومان\n"
            . "🔼 موجودی قبل از تمدید: " . number_format($beforeWallet) . " تومان\n"
            . "🔽 موجودی بعد از تمدید: " . number_format($afterWallet) . " تومان\n"
            . "📅 مدت اشتراک: $days روز\n"
            . "🔋 حجم اشتراک: $volume گیگ\n"
            . "💰 شناسه پرداخت: <code>$hash_id</code>";

        sendMessage($channelMessage, null, "html", $admin);

        $rewardChannel = $botState['rewardChannel'];
        if (!empty($rewardChannel)) {
            sendMessage($channelMessage, null, "html", $rewardChannel);
        }
    }
}

?>