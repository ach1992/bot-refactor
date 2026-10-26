<?php
/** AChBot V5: broadcast recipient worker; legacy caller scope and side effects are intentional. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

// BEGIN send to all
$staleMinutes = 15;
$batchLimit   = 400;
$heartbeatN   = 10;
$hbSeconds    = 10;
$sleepUs      = 80000;

$sendId   = 0;
$offset   = 0;
$finished = false;

register_shutdown_function(function () use (&$connection, &$sendId, &$offset, &$finished) {
    if ($finished || $sendId <= 0) return;

    $stmt = $connection->prepare("SELECT `state` FROM `send_list` WHERE `id`=? LIMIT 1");
    if (!$stmt) return;
    $stmt->bind_param("i", $sendId);
    $stmt->execute();
    $r = $stmt->get_result();
    safeClose($stmt);

    if ($r && $r->num_rows > 0) {
        $st = (int)$r->fetch_assoc()['state'];
        if ($st === 2) {
            markPaused($connection, "send_list", $sendId, $offset);
        }
    }
});

$stmt = $connection->prepare("
    SELECT `id`
    FROM `send_list`
    WHERE
        `state` = 1
        OR (`state` = 2 AND `updated_at` < (NOW() - INTERVAL {$staleMinutes} MINUTE))
    ORDER BY `id` ASC
    LIMIT 1
");
$stmt->execute();
$res = $stmt->get_result();
safeClose($stmt);

if (!$res || $res->num_rows == 0) {
    return;
}

$sendId = (int)$res->fetch_assoc()['id'];

// lock / renew lock
$stmt = $connection->prepare("
    UPDATE `send_list`
    SET `state` = 2, `updated_at` = NOW()
    WHERE `id` = ?
      AND (
        `state` = 1
        OR (`state` = 2 AND `updated_at` < (NOW() - INTERVAL {$staleMinutes} MINUTE))
      )
");
$stmt->bind_param("i", $sendId);
$stmt->execute();
$affected = $stmt->affected_rows;
safeClose($stmt);

if ($affected == 0) {
    return;
}

// info
$stmt = $connection->prepare("SELECT * FROM `send_list` WHERE `id`=? LIMIT 1");
$stmt->bind_param("i", $sendId);
$stmt->execute();
$list = $stmt->get_result();
safeClose($stmt);

if (!$list || $list->num_rows == 0) {
    markPaused($connection, "send_list", $sendId, $offset);
    return;
}

$info = $list->fetch_assoc();

$offset     = (int)$info['offset']; // last_user_id
$type       = $info['type'];
$file_id    = $info['file_id'];
$chat_id    = $info['chat_id'];
$text       = $info['text'];
$message_id = $info['message_id'];
$group      = $info['target_group'] ?? 'all';

if ($offset == 0) {
    $msg = ($type == "forwardall") ? "عملیات فوروارد همگانی شروع شد." : "عملیات ارسال پیام همگانی شروع شد.";
    bot('sendMessage', ['chat_id' => $admin, 'text' => $msg]);
}

$keys = json_encode([
    'inline_keyboard' => [
        [[
            'text' => $buttonValues['start_bot'],
            'url'  => "https://t.me/$botUsername?start=mainMenu"
        ]]
    ]
]);

if ($group === 'has') {

    $stmt = $connection->prepare("
        SELECT u.*
        FROM `users` u
        WHERE u.id > ?
			AND (u.step IS NULL OR (u.step <> 'Bot_Blocked' AND u.step <> 'banned'))
			AND EXISTS (SELECT 1 FROM `orders_list` o WHERE o.userid = u.userid)
        ORDER BY u.id
        LIMIT {$batchLimit}
    ");

} elseif ($group === 'none') {

    $stmt = $connection->prepare("
        SELECT u.*
        FROM `users` u
        LEFT JOIN `orders_list` o ON o.userid = u.userid
		WHERE u.id > ?
			AND (u.step IS NULL OR (u.step <> 'Bot_Blocked' AND u.step <> 'banned'))
			AND o.userid IS NULL
        ORDER BY u.id
        LIMIT {$batchLimit}
    ");

} elseif ($group === 'agents') {

    $stmt = $connection->prepare("
        SELECT *
        FROM `users`
        WHERE id > ?
			AND is_agent = 1
			AND (step IS NULL OR (step <> 'Bot_Blocked' AND step <> 'banned'))
        ORDER BY id
        LIMIT {$batchLimit}
    ");

} else {

    $stmt = $connection->prepare("
        SELECT *
        FROM `users`
		WHERE id > ?
			AND (step IS NULL OR (step <> 'Bot_Blocked' AND step <> 'banned'))
        ORDER BY id
        LIMIT {$batchLimit}
    ");
}

$stmt->bind_param("i", $offset);
$stmt->execute();
$usersList = $stmt->get_result();
safeClose($stmt);

if (!$usersList || $usersList->num_rows == 0) {

    $processedCount = 0;
    if ($offset > 0) {
        if ($group === 'has') {
            $c = $connection->prepare("
                SELECT COUNT(*) AS c
                FROM `users` u
                WHERE u.id <= ?
                  AND EXISTS (SELECT 1 FROM `orders_list` o WHERE o.userid = u.userid)
            ");
        } elseif ($group === 'none') {
            $c = $connection->prepare("
                SELECT COUNT(*) AS c
                FROM `users` u
                LEFT JOIN `orders_list` o ON o.userid = u.userid
                WHERE u.id <= ? AND o.userid IS NULL
            ");
        } elseif ($group === 'agents') {
            $c = $connection->prepare("
                SELECT COUNT(*) AS c
                FROM `users`
                WHERE id <= ? AND is_agent = 1
            ");
        } else {
            $c = $connection->prepare("SELECT COUNT(*) AS c FROM `users` WHERE id <= ?");
        }

        if ($c) {
            $c->bind_param("i", $offset);
            $c->execute();
            $cr = $c->get_result();
            safeClose($c);
            if ($cr && $cr->num_rows > 0) {
                $processedCount = (int)$cr->fetch_assoc()['c'];
            }
        }
    }

    $msg = ($type == "forwardall")
        ? "✅ عملیات فوروارد همگانی با موفقیت انجام شد."
        : "✅ عملیات ارسال پیام همگانی با موفقیت انجام شد.";

    bot('sendMessage', [
        'chat_id' => $admin,
        'text'    => $msg . "\n❇️ تا اینجا روی حدود " . $processedCount . " نفر پردازش انجام شد."
    ]);

    markFinished($connection, "send_list", $sendId, $offset);

    $finished = true;
    return;
}

$sentInThisBatch = 0;
$lastHbTs = nowTs();

while ($user = $usersList->fetch_assoc()) {

    $offset = (int)$user['id'];

    $sendRes = null;

    if ($type == 'text') {
        $sendRes = sendMessage($text, $keys, null, $user['userid']);

    } elseif ($type == 'music') {
        $sendRes = bot('sendAudio', [
            'chat_id' => $user['userid'],
            'audio' => $file_id,
            'caption' => $text,
            'reply_markup' => $keys
        ]);

    } elseif ($type == 'video') {
        $sendRes = bot('sendVideo', [
            'chat_id' => $user['userid'],
            'video' => $file_id,
            'caption' => $text,
            'reply_markup' => $keys
        ]);

    } elseif ($type == 'voice') {
        $sendRes = bot('sendVoice', [
            'chat_id' => $user['userid'],
            'voice' => $file_id,
            'caption' => $text,
            'reply_markup' => $keys
        ]);

    } elseif ($type == 'document') {
        $sendRes = bot('sendDocument', [
            'chat_id' => $user['userid'],
            'document' => $file_id,
            'caption' => $text,
            'reply_markup' => $keys
        ]);

    } elseif ($type == 'photo') {
        $sendRes = bot('sendPhoto', [
            'chat_id' => $user['userid'],
            'photo' => $file_id,
            'caption' => $text,
            'reply_markup' => $keys
        ]);

    } elseif ($type == 'forwardall') {
        $sendRes = forwardmessage($user['userid'], $chat_id, $message_id);

    } else {
        $sendRes = bot('sendDocument', [
            'chat_id' => $user['userid'],
            'document' => $file_id,
            'caption' => $text,
            'reply_markup' => $keys
        ]);
    }

    if (isset($sendRes->ok) && $sendRes->ok === false) {
        $code = (int)($sendRes->error_code ?? 0);
        if ($code === 429) {
            hbUpdate($connection, "send_list", $sendId, $offset);
            $retry = 1;
            if (isset($sendRes->parameters) && isset($sendRes->parameters->retry_after)) {
                $retry = (int)$sendRes->parameters->retry_after;
            }
            sleep($retry + 1);
        }
    }

    if ($sendRes !== null && isset($sendRes->ok) && $sendRes->ok === false) {
        $code = isset($sendRes->error_code) ? (int)$sendRes->error_code : 0;
        if ($code === 400 || $code === 403) {
			if ($code === 403) {
				$u = $connection->prepare("UPDATE `users` SET `step`='Bot_Blocked' WHERE `userid`=? AND `step`<>'banned' AND `step` NOT LIKE 'achbotDirectGift%' AND (`step` IS NULL OR `step`<>'Bot_Blocked')");
				if ($u) {
					$uid = (int)$user['userid'];
					$u->bind_param("i", $uid);
					$u->execute();
					$u->close();
				}
			}

            $logFile = dirname(__DIR__, 2) . "/settings/error_log.txt";
            $maxSize = 1024 * 1024;
            if (file_exists($logFile) && filesize($logFile) > $maxSize) {
                file_put_contents($logFile, "");
            }

            $log  = "⚠️ Broadcast Send Failed: {$code}\n";
            $log .= "🕒 Time: " . date("Y-m-d H:i:s") . "\n";
            $log .= "👤 User: " . $user['userid'] . "\n";
            $log .= "📡 Type: " . $type . "\n";
            $log .= "📝 Desc: " . ($sendRes->description ?? '') . "\n";
            $log .= "---------------------------\n";
            file_put_contents($logFile, $log, FILE_APPEND);
        }
    }

    if (apiOk($sendRes)) {
        $sentMsgId = getApiMessageId($sendRes);
        if ($sentMsgId > 0) {
            logBroadcastUserMessage($connection, $sendId, (int)$user['userid'], $sentMsgId);

            $sp = $connection->prepare("
                UPDATE `send_list`
                SET `sample_userid`=?, `sample_message_id`=?
                WHERE `id`=? AND (`sample_message_id` IS NULL OR `sample_message_id`=0)
            ");
            if ($sp) {
                $su = (int)$user['userid'];
                $sm = (int)$sentMsgId;
                $sp->bind_param("iii", $su, $sm, $sendId);
                $sp->execute();
                safeClose($sp);
            }
        }
    }

    $sentInThisBatch++;
    usleep($sleepUs);

    // heartbeat بر اساس تعداد یا زمان
    if (($sentInThisBatch % $heartbeatN == 0) || (nowTs() - $lastHbTs >= $hbSeconds)) {
        hbUpdate($connection, "send_list", $sendId, $offset);
        $lastHbTs = nowTs();

        if (isCancelled($connection, "send_list", $sendId)) {
            markCancelled($connection, "send_list", $sendId, $offset);

            bot('sendMessage', [
                'chat_id' => $admin,
                'text'    => "⛔️ ارسال همگانی لغو شد.\nتا اینجا آخرین کاربر با id=$offset پردازش شده بود."
            ]);

            $finished = true;
            return;
        }
    }
}

markPaused($connection, "send_list", $sendId, $offset);

$finished = true;
return;
// END send to all
