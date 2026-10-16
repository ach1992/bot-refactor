<?php
/** AChBot V6: original Telegram account/subscription routes; keep caller scope. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

// BEGIN Edit by ACh
if($data == "showUUIDLeft" && ($botState['searchState'] == "on" || $from_id == $admin || $userInfo['isAdmin'] == true)){ 
    delMessage(); 
    sendMessage($mainValues['send_config_uuid'], $cancelKey); 
    setUser('showAccount'); 
} 

function extractUsernameFromLink($text) {
    $text = trim($text);

    if (!preg_match('~^https?://[^/]+/sub/([^?#\s]+)~', $text, $match)) {
        return null;
    }

    $token = rawurldecode(trim($match[1]));
    $token = strtr($token, '-_', '+/');
    $token = preg_replace('/\s+/', '', $token);

    for ($cut = 0; $cut <= 4; $cut++) {
        $candidate = $cut > 0 ? substr($token, 0, -$cut) : $token;
        if ($candidate === '') {
            continue;
        }

        $mod = strlen($candidate) % 4;

        if ($mod == 1) {
            continue;
        }

        if ($mod > 0) {
            $candidate .= str_repeat('=', 4 - $mod);
        }

        $decoded = base64_decode($candidate, true);
        if ($decoded === false || $decoded === '') {
            continue;
        }

        if (strpos($decoded, ',') !== false) {
            $parts = explode(',', $decoded, 2);
            $username = trim($parts[0]);

            if ($username !== '') {
                return $username;
            }
        }

        // fallback
        $decoded = preg_replace('/[^\x20-\x7E]/', '', $decoded);
        if ($decoded !== '' && strpos($decoded, ',') !== false) {
            $parts = explode(',', $decoded, 2);
            $username = trim($parts[0]);

            if ($username !== '') {
                return $username;
            }
        }
    }

    return null;
}

if ($userInfo['step'] == "showAccount" && $text != $buttonValues['cancel']) { 
    date_default_timezone_set('Asia/Tehran'); 

    $originalLink = trim($text); 
    $addedBy = $from_id; 
    $currentTime = date('Y-m-d H:i:s'); 

    $username = extractUsernameFromLink($originalLink); 
    $token = null; 

    if (preg_match('~^https?://[^/]+/sub/([^?\s]+)~', $originalLink, $match)) {
		$token = trim(explode('#', explode('?', $match[1])[0])[0]);
	}

    if (!$username || !$token) { 
        sendMessage("❌ لینک نامعتبر است.", $cancelKey); 
        exit(); 
    } 

    $stmt = $connection->prepare("INSERT INTO `add_link` (`remark`, `token`, `added_by`, `created_at`) VALUES (?, ?, ?, ?)"); 
    $stmt->bind_param("ssis", $username, $token, $addedBy, $currentTime); 
    $stmt->execute(); 
    $stmt->close(); 

    sendMessage("🔍 در حال بررسی اکانت، لطفاً صبر کنید...", $removeKeyboard); 

    $stmt = $connection->prepare("SELECT * FROM server_config WHERE type = 'marzban'"); 
    $stmt->execute(); 
    $serversList = $stmt->get_result(); 
    $stmt->close(); 

    $found = false; 
    $config = null;
    $serverId = 0;

    while ($row = $serversList->fetch_assoc()) { 
        $currentServerId = (int)$row['id']; 
        $userData = getMarzbanUser($currentServerId, $username); 

        if (!empty($userData) && !isset($userData->detail) && !empty($userData->username)) { 
            $found = true; 
            $config = $userData; 
            $serverId = $currentServerId; 
            break; 
        } 
    } 

    if (!$found || empty($config)) { 
        sendMessage("❌ اشتراک موردنظر پیدا نشد. اگر اعتبار آن تمام شده و بیش از ۱۵ روز گذشته باشد، ممکن است حذف شده باشد.", $cancelKey);
        exit(); 
    } 

    $remark = $config->username ?? $username; 
    $state = (($config->status ?? '') == "active") ? $buttonValues['active'] : $buttonValues['deactive']; 

    $dataLimit = $config->data_limit ?? 0; 
    $usedTraffic = $config->used_traffic ?? 0; 
    $expire = $config->expire ?? 0; 

    $total = $dataLimit != 0 ? sumerize($dataLimit) : "نامحدود"; 
    $totalUsed = sumerize($usedTraffic); 
    $leftMb = $dataLimit != 0 ? sumerize(max(0, $dataLimit - $usedTraffic)) : "نامحدود"; 
    $expiryTime = $expire != 0 ? jdate("Y-m-d H:i:s", $expire) : "نامحدود"; 
    $expiryDay = $expire != 0 ? floor(($expire - time()) / (60 * 60 * 24)) : "نامحدود"; 

    if (is_numeric($expiryDay) && $expiryDay < 0) { 
        $expiryDay = 0; 
    } 

    // استخراج شناسه اتصال برای ذخیره موقت
    $uuid = ''; 

    if (!empty($config->proxies->vmess->id)) { 
        $uuid = $config->proxies->vmess->id; 
    } elseif (!empty($config->proxies->vless->id)) { 
        $uuid = $config->proxies->vless->id; 
    } elseif (!empty($config->proxies->trojan->password)) { 
        $uuid = $config->proxies->trojan->password; 
    } elseif (!empty($config->uuid)) { 
        $uuid = $config->uuid; 
    } 

    $configLocation = [
        "remark" => $remark,
        "uuid" => $uuid,
        "username" => $remark,
        "server_id" => $serverId,
        "marzban" => true
    ];

    $keyboard = [ 
        [ 
            ['text' => $state ?? " ", 'callback_data' => "wizwizch"], 
            ['text' => "🔘 وضعیت اکانت 🔘", 'callback_data' => "wizwizch"], 
        ], 
        [ 
            ['text' => $remark ?? " ", 'callback_data' => "wizwizch"], 
            ['text' => "👤 نام کاربری", 'callback_data' => "wizwizch"], 
        ], 
        [ 
            ['text' => $totalUsed ?? " ", 'callback_data' => "wizwizch"], 
            ['text' => "↕️ آپلود + دانلود", 'callback_data' => "wizwizch"], 
        ], 
        [ 
            ['text' => $total ?? " ", 'callback_data' => "wizwizch"], 
            ['text' => "🔋 حجم کلی", 'callback_data' => "wizwizch"], 
        ], 
        [ 
            ['text' => $leftMb ?? " ", 'callback_data' => "wizwizch"], 
            ['text' => "🪫 حجم باقیمانده ", 'callback_data' => "wizwizch"], 
        ], 
        [ 
            ['text' => $expiryTime ?? " ", 'callback_data' => "wizwizch"], 
            ['text' => "📆 تاریخ اتمام", 'callback_data' => "wizwizch"], 
        ], 
        [ 
            ['text' => $expiryDay ?? " ", 'callback_data' => "wizwizch"], 
            ['text' => "🔄 تعداد روز باقیمانده", 'callback_data' => "wizwizch"], 
        ], 
    ]; 

    /*
    // دکمه های مربوط به تمدید یا بروزرسانی لینک
    if ($botState['renewAccountState'] == "on") {
        $keyboard[] = [
            ['text' => $buttonValues['renew_config'], 'callback_data' => "sConfigRenew" . $serverId],
        ];
    } elseif ($botState['updateConfigLinkState'] == "on") {
        $keyboard[] = [
            ['text' => $buttonValues['update_config_connection'], 'callback_data' => "sConfigUpdate" . $serverId],
        ];
    }
    */

    if ($from_id == $admin || $userInfo['isAdmin'] == true) { 
        if (empty($userInfo['addToCustomerClicked']) || $userInfo['addToCustomerClicked'] != true) { 
            $keyboard[] = [['text' => "➕ افزودن به حساب مشتری ➕", 'callback_data' => "addSubscriptionToCustomer" . $serverId]]; 
        } 
    } else { 
        $keyboard[] = [['text' => "➕ افزودن به مشاهده اشتراک ها ➕", 'callback_data' => "addToOrders" . $serverId]]; 
    } 

    $keyboard[] = [['text' => "↪️ برگشت به منوی اصلی", 'callback_data' => "mainMenu"]]; 
    $keys = json_encode(['inline_keyboard' => $keyboard]); 

    setUser(); 
    setUser(json_encode($configLocation, JSON_UNESCAPED_UNICODE), "temp"); 
    $userInfo['temp'] = json_encode($configLocation, JSON_UNESCAPED_UNICODE); 

    sendMessage("🔰 مشخصات اشتراک ارسالی 🔰 
🔄 در اینجا می توانید اشتراکی که برای خودتان یا دیگران است را تمدید کنید. 
🛑 لطفا دقت کنید فقط اشتراکی که برای خودتان است را به لیست اشتراک ها اضافه کنید و اشتراک دیگران را اضافه نکنید. 
➕ <b>نکته مهم:</b> اگر اشتراک برای خودتان هست قبل از تمدید اول آن را به ربات اضافه کنید و بعد از بخش مشاهده اشتراک ها پلن خودتان را انتخاب و تمدید کنید. 
", $keys, "HTML"); 
}

