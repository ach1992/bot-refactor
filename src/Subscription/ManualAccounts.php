<?php
/** AChBot V6: original Telegram account/subscription routes; keep caller scope. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG


//Added by ACh

if (preg_match("/^addSubscriptionToCustomer(\d+)/", $data, $match)) {
    $serverId = intval($match[1]);
    $tempData = json_decode($userInfo['temp'], true) ?: [];
    
    if ($from_id != $admin && $userInfo['isAdmin'] != true) {
        alert(\achbotMessage('subscription.manual_accounts.alert.03aea25611'));
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

    $msg = \achbotMessage('subscription.manual_accounts.msg.a88437a461');
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
    $msg = \achbotMessage('subscription.manual_accounts.manual_account_consume_selection.msg.86a1eb982f', ['customUserId' => $customUserId]);
    $keyboard = [
        [['text' => \achbotMessage('subscription.manual_accounts.manual_account_consume_selection.button.0c983c9f0f'), 'callback_data' => "addToOrders" . $serverId]],
        [['text' => \achbotMessage('subscription.manual_accounts.manual_account_consume_selection.button.11b0188fcb'), 'callback_data' => "searchUsersConfig"]],
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
        alert(\achbotMessage('subscription.manual_accounts.detect_server_id_from_remark.alert.0e59a3d9a8'));
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
        alert(\achbotMessage('subscription.manual_accounts.detect_server_id_from_remark.alert.4c5aeb77e7'));
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
        alert(\achbotMessage('subscription.manual_accounts.detect_server_id_from_remark.alert.eade9d3428'));
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

        alert(\achbotMessage('subscription.manual_accounts.detect_server_id_from_remark.alert.ecf2df32d7'));

        $msg = \achbotMessage('subscription.manual_accounts.detect_server_id_from_remark.msg.10aa1e12ec', ['remark' => $remark]);

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
                    [['text' => \achbotMessage('subscription.manual_accounts.detect_server_id_from_remark.button.48f23552b7'), 'callback_data' => "quickUserOrderDetails_{$orderId}"]]
                ]
            ]);

            $adminMessage = \achbotMessage('subscription.manual_accounts.detect_server_id_from_remark.admin_message.7e7a367cc0', ['finalUserId' => $finalUserId, 'first_name' => $first_name, 'username' => $username, 'remark' => $remark]);

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
        alert(\achbotMessage('subscription.manual_accounts.detect_server_id_from_remark.alert.6b70d2424a'));
        exit();
    }

    exit();
}

