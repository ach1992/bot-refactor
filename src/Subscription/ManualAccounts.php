<?php
/** AChBot V6: original Telegram account/subscription routes; keep caller scope. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

//Added by ACh

if (preg_match("/^addSubscriptionToCustomer(\d+)/", $data, $match)) {
    $serverId = intval($match[1]);
    $tempData = json_decode($userInfo['temp'], true) ?: [];
    
    if ($from_id != $admin && $userInfo['isAdmin'] != true) {
        alert("❌ شما دسترسی لازم برای این عملیات را ندارید.");
        exit();
    }

    $tempData['awaiting_userid_for_add'] = true;
    $tempData['selected_server_id'] = $serverId;
    $tempData['addToCustomerClicked'] = true;

    $stmt = $connection->prepare("UPDATE `users` SET `temp` = ? WHERE `userid` = ?");
    $tempJson = json_encode($tempData, JSON_UNESCAPED_UNICODE);
    $stmt->bind_param("si", $tempJson, $from_id);
    $stmt->execute();
    $stmt->close();

    $msg = "لطفاً شناسه عددی کاربر (user_id) را وارد کنید:";
    $keyboard = [
        [['text' => $buttonValues['back_to_main'], 'callback_data' => 'mainMenu']]
    ];

    sendMessage($msg, json_encode(['inline_keyboard' => $keyboard]), "html", $from_id);
    setUser("awaiting_userid_input", "step");
    exit();
}

$tempData = json_decode($userInfo['temp'], true) ?: [];

/**
 * Consume the legacy admin user-id selection atomically on the exact actor PK.
 * Cached config.php userInfo is only a routing hint; neither step nor temp
 * may be overwritten after a newer direct gift (or another legacy wizard)
 * has acquired this user row. No schema change or provider operation.
 */
function achbotManualAccountConsumeSelection(mysqli $db,int $actorId,
        int $primary,array $cached,string $newTemp): bool {
    if ($actorId<=0 || ($cached['step']??null)!=='awaiting_userid_input' ||
        !isset($cached['id'],$cached['userid']) ||
        (int)$cached['userid']!==$actorId ||
        !filter_var((string)$cached['id'],FILTER_VALIDATE_INT,
            ['options'=>['min_range'=>1,'max_range'=>2147483647]]))
        return false;
    $id=(int)$cached['id'];
    $stmt=$db->prepare('SELECT id FROM users WHERE userid=? LIMIT 2');
    if (!$stmt) throw new RuntimeException('manual_account_pk_prepare');
    $stmt->bind_param('i',$actorId);
    $ok=$stmt->execute();$ids=$ok?$stmt->get_result()->fetch_all(MYSQLI_ASSOC):[];
    $stmt->close();
    if (!$ok || count($ids)!==1 || (int)$ids[0]['id']!==$id)
        throw new RuntimeException('manual_account_actor_ambiguous');
    if (!$db->begin_transaction())
        throw new RuntimeException('manual_account_begin_failed');
    try {
        $stmt=$db->prepare('SELECT id,userid,step,temp,isAdmin
                            FROM users WHERE id=? FOR UPDATE');
        if (!$stmt) throw new RuntimeException('manual_account_lock_prepare');
        $stmt->bind_param('i',$id);
        $ok=$stmt->execute();
        $current=$ok?$stmt->get_result()->fetch_assoc():null;
        $stmt->close();
        if (!$ok) throw new RuntimeException('manual_account_lock_failed');
        if (!$current || (int)$current['userid']!==$actorId ||
            $current['step']!=='awaiting_userid_input' ||
            $current['temp']!==($cached['temp']??null) ||
            ($actorId!==$primary && (int)$current['isAdmin']!==1)) {
            $db->rollback();
            return false;
        }
        // A post-lock identity lookup closes the nonunique userid gap.
        $stmt=$db->prepare('SELECT id FROM users WHERE userid=? LIMIT 2');
        if (!$stmt) throw new RuntimeException('manual_account_identity_prepare');
        $stmt->bind_param('i',$actorId);
        $ok=$stmt->execute();
        $ids=$ok?$stmt->get_result()->fetch_all(MYSQLI_ASSOC):[];
        $stmt->close();
        if (!$ok || count($ids)!==1 || (int)$ids[0]['id']!==$id)
            throw new RuntimeException('manual_account_identity_drift');
        $next='';
        $expected='awaiting_userid_input';
        $expectedTemp=$current['temp'];
        $stmt=$db->prepare('UPDATE users SET temp=?,step=?
             WHERE id=? AND userid=? AND step=? AND temp <=> ?');
        if (!$stmt) throw new RuntimeException('manual_account_cas_prepare');
        $stmt->bind_param('ssiiss',$newTemp,$next,$id,$actorId,$expected,$expectedTemp);
        $ok=$stmt->execute();$affected=$stmt->affected_rows;$stmt->close();
        if (!$ok || $affected!==1)
            throw new RuntimeException('manual_account_cas_unconfirmed');
        if (!$db->commit())
            throw new RuntimeException('manual_account_commit_unknown');
        return true;
    } catch (Throwable $e) {
        try {$db->rollback();} catch (Throwable $ignored) {}
        throw $e;
    }
}

if ($userInfo['step'] == "awaiting_userid_input" && is_numeric($text)) {
    // Never treat old bot text embedded in a callback as a newly authored
    // numeric step, even when the current actor is still an administrator.
    try {
        require_once __DIR__.'/GiftBatch.php';
        achbotGiftTelegramSource($update,$from_id,$chat_id,$message_id,$text);
    } catch (Throwable $e) {
        error_log('manual_account_source_unconfirmed');
        exit();
    }
    $customUserId = intval($text);
    $tempData = json_decode($userInfo['temp'], true) ?: [];
    $tempData['custom_userid'] = $customUserId;
    unset($tempData['awaiting_userid_for_add']);
    try {
        $tempJson = json_encode($tempData,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $confirmed = achbotManualAccountConsumeSelection(
            $connection,(int)$from_id,(int)$admin,$userInfo,$tempJson);
        if (!$confirmed) {
            error_log('manual_account_stale_step_or_role');
            exit();
        }
    } catch (Throwable $e) {
        // Lost COMMIT acknowledgments are uncertain, NOT proof of failure.
        error_log('manual_account_selection_commit_unconfirmed');
        exit();
    }

    $serverId = intval($tempData['selected_server_id'] ?? 0);
    $msg = "✅ شناسه عددی <b>$customUserId</b> با موفقیت ثبت شد.";
    $keyboard = [
        [['text' => "➕ افزودن به اشتراک های کاربر ➕", 'callback_data' => "addToOrders" . $serverId]],
        [['text' => "🔍 جستجوی اشتراک کاربر", 'callback_data' => "searchUsersConfig"]],
        [['text' => $buttonValues['back_to_main'], 'callback_data' => "mainMenu"]]
    ];
    sendMessage($msg, json_encode(['inline_keyboard' => $keyboard]), "html", $from_id);
    exit();
}

function detectServerIdFromRemark($connection, $remark, $defaultServerId = 0) {
    $remark = mb_strtolower(trim((string)$remark));
    if ($remark === '') {
        return $defaultServerId;
    }

    $stmt = $connection->prepare("
        SELECT `id`, `remark`
        FROM `server_info`
        WHERE `active` = 1
        ORDER BY CHAR_LENGTH(`remark`) DESC, `id` ASC
    ");
    $stmt->execute();
    $result = $stmt->get_result();

    $firstActiveServerId = 0;

    while ($row = $result->fetch_assoc()) {
        $serverId = (int)$row['id'];
        $prefixRaw = trim((string)$row['remark']);

        if ($firstActiveServerId === 0) {
            $firstActiveServerId = $serverId;
        }

        if ($prefixRaw === '') {
            continue;
        }

        $prefixes = preg_split('/[\s,|]+/u', $prefixRaw, -1, PREG_SPLIT_NO_EMPTY);

        foreach ($prefixes as $prefix) {
            $prefix = mb_strtolower(trim($prefix));
            if ($prefix === '') {
                continue;
            }

            if (mb_substr($remark, 0, mb_strlen($prefix)) === $prefix) {
                $stmt->close();
                return $serverId;
            }
        }
    }

    $stmt->close();

    if ($firstActiveServerId > 0) {
        return $firstActiveServerId;
    }

    return $defaultServerId;
}

if (preg_match("/^addToOrders(\d+)/", $data, $match)) {
    $tempData = json_decode($userInfo['temp'], true) ?: [];

    $uuid = trim($tempData['uuid'] ?? '');
    $remark = trim($tempData['remark'] ?? '');
    $serverIdFromTemp = intval($tempData['server_id'] ?? 0);

    $finalUserId = !empty($tempData['custom_userid']) ? intval($tempData['custom_userid']) : $from_id;

    if (empty($remark)) {
        alert("❌ اطلاعات اشتراک یافت نشد.");
        exit();
    }

    $serverId = detectServerIdFromRemark(
        $connection,
        $remark,
        $serverIdFromTemp > 0 ? $serverIdFromTemp : 2
    );

    $stmt = $connection->prepare("SELECT `id` FROM `orders_list` WHERE `userid` = ? AND `remark` = ? LIMIT 1");
    $stmt->bind_param("is", $finalUserId, $remark);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    if ($exists) {
        alert("✅ این اشتراک قبلاً اضافه شده است.");
        exit();
    }

    $stmt = $connection->prepare("
        SELECT `token`
        FROM `add_link`
        WHERE `added_by` = ? AND `remark` = ?
        ORDER BY `created_at` DESC
        LIMIT 1
    ");
    $stmt->bind_param("is", $from_id, $remark);
    $stmt->execute();
    $result = $stmt->get_result();

    $token = '';
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $token = $row['token'];
    }
    $stmt->close();

    if (empty($token)) {
        alert("❌ توکن یافت نشد.");
        exit();
    }

    $connection->begin_transaction();

    try {
        $now = time();
        $expire_date = ($serverId == 3) ? $now + (5 * 365 * 86400) : $now + (30 * 86400);

        $userid = strval($finalUserId);
        $transid = "";
        $fileid = 0;
        $inbound_id = 0;
        $protocol = "";
        $link = '[""]';
        $amount = 100;
        $status = 1;
        $date = time() - (1 * 30 * 86400);
        $notif = 0;
        $rahgozar = 0;
        $agent_bought = 0;

        $stmt = $connection->prepare("
            INSERT INTO `orders_list`
            (`userid`, `token`, `transid`, `fileid`, `server_id`, `inbound_id`, `remark`, `uuid`, `protocol`, `expire_date`, `link`, `amount`, `status`, `date`, `notif`, `rahgozar`, `agent_bought`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->bind_param(
            "sssisississsiiiii",
            $userid,
            $token,
            $transid,
            $fileid,
            $serverId,
            $inbound_id,
            $remark,
            $uuid,
            $protocol,
            $expire_date,
            $link,
            $amount,
            $status,
            $date,
            $notif,
            $rahgozar,
            $agent_bought
        );

        $stmt->execute();
        $stmt->close();

        $connection->commit();

        alert("✅ اشتراک با موفقیت به لیست اضافه شد.");

        $msg = "⚠️ اشتراک  <code>$remark</code>  با موفقیت برای شما در ربات ثبت شد. از پایین بزنید روی دکمه:

 ← 🔄 <b>مشاهده اشتراک ها</b> 

🔰 از لیست اشتراک خودتان را انتخاب کنید و در صفحه بعد اگر قصد تمدید دارید بزنید روی دکمه: 

← ♻️ <b>تمدید اشتراک</b>

💰 وقتی مبلغ نمایش داده شد روی دکمه زیر بزنید:

← 💳 <b>کارت به کارت</b>

⬆️ حالا بروید <b>مبلغ را کارت به کارت کنید</b> و بیایید رسید آن را در داخل ربات بفرستید.";

        $keyboard = [
            [
                ['text' => $buttonValues['my_subscriptions'], 'callback_data' => 'mySubscriptions'],
                ['text' => $buttonValues['back_to_main'], 'callback_data' => 'mainMenu']
            ]
        ];

        sendMessage($msg, json_encode(['inline_keyboard' => $keyboard]), "html", $finalUserId);

        $stmt = $connection->prepare("SELECT `id` FROM `orders_list` WHERE `userid` = ? AND `remark` = ? ORDER BY `id` DESC LIMIT 1");
        $stmt->bind_param("is", $finalUserId, $remark);
        $stmt->execute();
        $result = $stmt->get_result();
        $orderRow = $result->fetch_assoc();
        $orderId = $orderRow['id'] ?? 0;
        $stmt->close();

        if ($orderId > 0) {
            $inlineKeyboard = json_encode([
                'inline_keyboard' => [
                    [['text' => "💡مشاهده مشخصات اشتراک", 'callback_data' => "quickUserOrderDetails_{$orderId}"]]
                ]
            ]);

            $adminMessage = "
➕ اشتراک جدید اضافه شد

👤 شناسه عددی: <code>$finalUserId</code>
👨 اسم کاربر: $first_name
⚡️ نام کاربری: @$username
🎈 نام کاربری اشتراک: <code>$remark</code>
";

            sendMessage($adminMessage, $inlineKeyboard, "html", $admin);
            sendToAllAdmins('sendMessage', [
                'text' => $adminMessage,
                'parse_mode' => "html",
                'reply_markup' => $inlineKeyboard
            ]);
        }

        unset($tempData['custom_userid']);
        unset($tempData['awaiting_userid_for_add']);
        unset($tempData['selected_server_id']);
        unset($tempData['addToCustomerClicked']);

        $stmt = $connection->prepare("UPDATE `users` SET `temp` = ? WHERE `userid` = ?");
        $tempJson = json_encode($tempData, JSON_UNESCAPED_UNICODE);
        $stmt->bind_param("si", $tempJson, $from_id);
        $stmt->execute();
        $stmt->close();

    } catch (Exception $e) {
        $connection->rollback();
        alert("❌ ثبت اشتراک با خطا مواجه شد.");
        exit();
    }

    exit();
}

