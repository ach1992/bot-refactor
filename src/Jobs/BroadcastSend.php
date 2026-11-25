<?php
/** AChBot V5: broadcast recipient worker; legacy caller scope and side effects are intentional. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG
require_once __DIR__ . '/../Telegram/Response.php';


// BEGIN send to all
$staleMinutes = 15;
$batchLimit   = 400;
$sleepUs      = 80000;

$sendId   = 0;
$offset   = 0;
$finished = false;

$stmt = $connection->prepare("
    SELECT `id`
    FROM `send_list`
    WHERE (`type`='forwardall' OR `text_html` IS NOT NULL)
      AND (`state` = 1
        OR (`state` = 2 AND `updated_at` < (NOW() - INTERVAL {$staleMinutes} MINUTE)))
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
    // No trustworthy cursor was loaded; allow ordinary stale-job recovery.
    return;
}

$info = $list->fetch_assoc();

$offset     = (int)$info['offset']; // last_user_id
$type       = $info['type'];
$file_id    = $info['file_id'];
$chat_id    = $info['chat_id'];
$text       = $info['text_html'];
$message_id = $info['message_id'];
$group      = $info['target_group'] ?? 'all';

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


if ($offset == 0) {
    $msg = ($type == "forwardall") ? \achbotMessage('jobs.broadcast_send.text.5168e99d1c') : \achbotMessage('jobs.broadcast_send.text.afcd1bb579');
    achbotNotifyAdmins('sendMessage', ['text' => $msg], ['report_channel'=>null]);
}

$keys = json_encode([
    'inline_keyboard' => [
        [[
            'text' => \achbotMessage('legacy_buttons.start_bot'),
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
        ? \achbotMessage('jobs.broadcast_send.text.2c23963c7c')
        : \achbotMessage('jobs.broadcast_send.text.684e7d6efc');

    achbotNotifyAdmins('sendMessage', ['text'    => $msg . \achbotMessage('jobs.broadcast_send.text.f9f933d21b') . $processedCount . \achbotMessage('jobs.broadcast_send.text.2e434e30b2')
    ], ['report_channel'=>null]);

    markFinished($connection, "send_list", $sendId, $offset);

    $finished = true;
    return;
}

// The cursor is a durable dispatch claim, not an acknowledgement. Telegram
// cannot prove non-delivery after a lost response or a killed worker. Reserve
// each recipient before the first possible send and never replay that claim.
// The canonical Cron lease already serializes this worker; the comparison
// additionally prevents dispatch after cancellation or cursor movement.
$claim = $connection->prepare("UPDATE `send_list` SET `offset`=?, `updated_at`=NOW()
    WHERE `id`=? AND `state`=2 AND `offset`=?");
if (!$claim) return;

while ($user = $usersList->fetch_assoc()) {
    $next = (int)$user['id'];
    $claim->bind_param("iii", $next, $sendId, $offset);
    if (!$claim->execute() || $claim->affected_rows !== 1) {
        safeClose($claim);
        $finished = true;
        if (isCancelled($connection, "send_list", $sendId)) {
            markCancelled($connection, "send_list", $sendId, $offset);
            achbotNotifyAdmins('sendMessage', ['text' => \achbotMessage('jobs.broadcast_send.button.488a04a1e0', ['offset' => $offset])], ['report_channel'=>null]);
        } else {
            error_log('achbot_broadcast_claim_not_acquired broadcast_id='.$sendId.' user_id='.(int)$user['userid']);
        }
        return;
    }
    $offset = $next;

    $sendRes = null;

    if ($type == 'text') {
        $sendRes = sendMessage($text, $keys, 'HTML', $user['userid']);

    } elseif ($type == 'music') {
        $sendRes = bot('sendAudio', [
            'chat_id' => $user['userid'],
            'audio' => $file_id,
            'caption' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => $keys
        ]);

    } elseif ($type == 'video') {
        $sendRes = bot('sendVideo', [
            'chat_id' => $user['userid'],
            'video' => $file_id,
            'caption' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => $keys
        ]);

    } elseif ($type == 'voice') {
        $sendRes = bot('sendVoice', [
            'chat_id' => $user['userid'],
            'voice' => $file_id,
            'caption' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => $keys
        ]);

    } elseif ($type == 'document') {
        $sendRes = bot('sendDocument', [
            'chat_id' => $user['userid'],
            'document' => $file_id,
            'caption' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => $keys
        ]);

    } elseif ($type == 'photo') {
        $sendRes = bot('sendPhoto', [
            'chat_id' => $user['userid'],
            'photo' => $file_id,
            'caption' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => $keys
        ]);

    } elseif ($type == 'forwardall') {
        $sendRes = forwardmessage($user['userid'], $chat_id, $message_id);

    } else {
        $sendRes = bot('sendDocument', [
            'chat_id' => $user['userid'],
            'document' => $file_id,
            'caption' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => $keys
        ]);
    }

    if (isset($sendRes->ok) && $sendRes->ok === false) {
        $code = (int)($sendRes->error_code ?? 0);
        if ($code === 429) {
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
				$u = $connection->prepare("UPDATE `users` SET `step`='Bot_Blocked' WHERE `userid`=? AND `step`<>'banned' AND `step` NOT LIKE 'achbotDirectGift%' AND `step` NOT LIKE 'achbotExempt:%' AND (`step` IS NULL OR `step`<>'Bot_Blocked')");
				if ($u) {
					$uid = (int)$user['userid'];
					$u->bind_param("i", $uid);
					$u->execute();
					$u->close();
				}
			}

            // The PHP error sink is external to the public bot directory;
            // never keep raw Telegram rejection descriptions in webroot.
            $safeKind=preg_match('/^[A-Za-z0-9_-]{1,32}$/D',(string)$type)
                ?(string)$type:'other';
            error_log('achbot_broadcast_delivery_rejected code='.$code.
                ' user_id='.(int)$user['userid'].
                ' broadcast_id='.(int)$sendId.' kind='.$safeKind);
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

    if (!apiOk($sendRes) && \AchBot\Telegram\Response\deliveryOutcome($sendRes)['result'] === 'unknown') {
        error_log('achbot_broadcast_delivery_unknown broadcast_id='.$sendId.' user_id='.(int)$user['userid']);
    }
    usleep($sleepUs);
}
safeClose($claim);

markPaused($connection, "send_list", $sendId, $offset);

$finished = true;
return;
// END send to all
