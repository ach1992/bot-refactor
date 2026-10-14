<?php
/** AChBot V8: source-pinned financial compatibility module. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
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
            throw new \UnexpectedValueException('unsupported_panel');
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