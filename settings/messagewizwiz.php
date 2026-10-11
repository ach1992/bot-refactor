<?php 
include_once __DIR__ . '/../baseInfo.php';
include_once __DIR__ . '/../config.php';
include_once __DIR__ . '/jdf.php';

@ignore_user_abort(true);
@set_time_limit(0);

$rateLimit = $botState['rateLimit']??0;
if(time() > $rateLimit){
    $rate = json_decode(curl_get_file_contents("https://api.pooleno.ir/v1/currency/short-name/trx?type=buy"),true);
    $botState['USDRate'] = round($rate['priceUsdt'],2);
    $botState['TRXRate'] = round($rate['priceFiat'] / 10,2);
    $botState['rateLimit'] = strtotime("+1 hour");
    
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'BOT_STATES'");
    $stmt->execute();
    $isExists = $stmt->get_result();
    $stmt->close();
    if($isExists->num_rows>0) $query = "UPDATE `setting` SET `value` = ? WHERE `type` = 'BOT_STATES'";
    else $query = "INSERT INTO `setting` (`type`, `value`) VALUES ('BOT_STATES', ?)";
    $newData = json_encode($botState);
    
    $stmt = $connection->prepare($query);
    $stmt->bind_param("s", $newData);
    $stmt->execute();
    $stmt->close();
}

/**
 * Helpers (local)
 */

function nowTs(): int { return time(); }

function safeClose($stmt) {
    if ($stmt) { try { $stmt->close(); } catch (\Throwable $e) {} }
}

function hbUpdate(mysqli $connection, string $table, int $id, int $offsetVal): void {
    $sql = "UPDATE `$table` SET `offset`=?, `updated_at`=NOW() WHERE `id`=? AND `state`=2";
    $st = $connection->prepare($sql);
    if ($st) {
        $st->bind_param("ii", $offsetVal, $id);
        $st->execute();
        safeClose($st);
    }
}

function markPaused(mysqli $connection, string $table, int $id, int $offsetVal): void {
    $sql = "UPDATE `$table` SET `offset`=?, `state`=1, `updated_at`=NOW() WHERE `id`=? AND `state`=2";
    $st = $connection->prepare($sql);
    if ($st) {
        $st->bind_param("ii", $offsetVal, $id);
        $st->execute();
        safeClose($st);
    }
}

function isCancelled(mysqli $connection, string $table, int $id): bool {
    $sql = "SELECT `state` FROM `$table` WHERE `id`=? LIMIT 1";
    $st = $connection->prepare($sql);
    if (!$st) return false;
    $st->bind_param("i", $id);
    $st->execute();
    $r = $st->get_result();
    safeClose($st);
    if ($r && $r->num_rows > 0) {
        return ((int)$r->fetch_assoc()['state'] === 4);
    }
    return false;
}

function markCancelled(mysqli $connection, string $table, int $id, int $offsetVal): void {
    $sql = "UPDATE `$table` SET `offset`=?, `state`=4, `updated_at`=NOW() WHERE `id`=?";
    $st = $connection->prepare($sql);
    if ($st) {
        $st->bind_param("ii", $offsetVal, $id);
        $st->execute();
        safeClose($st);
    }
}

function markFinished(mysqli $connection, string $table, int $id, int $offsetVal): void {
    $sql = "UPDATE `$table` SET `offset`=?, `state`=3, `updated_at`=NOW() WHERE `id`=? AND `state`=2";
    $st = $connection->prepare($sql);
    if ($st) {
        $st->bind_param("ii", $offsetVal, $id);
        $st->execute();
        safeClose($st);
    }
}

$inlineStartKey = json_encode([
    'inline_keyboard' => [
        [[
            'text' => $buttonValues['start_bot'],
            'url'  => "https://t.me/$botUsername?start=mainMenu"
        ]]
    ]
], JSON_UNESCAPED_UNICODE);

// BEGIN Processor: bc_jobs (pin / unpin / delete / edit)
$bc_staleMinutes = 30;
$bc_limit        = 200;
$bc_hbEvery      = 20;
$bc_hbSeconds    = 10;
$bc_sleepUs      = 40000;

$bc_jobId    = 0;
$bc_offsetJ  = 0;
$bc_finished = false;

register_shutdown_function(function () use (&$connection, &$bc_jobId, &$bc_offsetJ, &$bc_finished) {
    if ($bc_finished || $bc_jobId <= 0) return;

    $stmt = $connection->prepare("SELECT `state` FROM `bc_jobs` WHERE `id`=? LIMIT 1");
    if (!$stmt) return;
    $stmt->bind_param("i", $bc_jobId);
    $stmt->execute();
    $r = $stmt->get_result();
    safeClose($stmt);

    if ($r && $r->num_rows > 0 && (int)$r->fetch_assoc()['state'] === 2) {
        markPaused($connection, "bc_jobs", $bc_jobId, $bc_offsetJ);
    }
});

/* unlock stale jobs */
$fix = $connection->prepare("
    UPDATE `bc_jobs`
    SET `state`=1, `updated_at`=NOW()
    WHERE `state`=2 AND `updated_at` < (NOW() - INTERVAL {$bc_staleMinutes} MINUTE)
");
if ($fix) { $fix->execute(); safeClose($fix); }

/* fetch next job */
$stmt = $connection->prepare("SELECT * FROM `bc_jobs` WHERE `state`=1 ORDER BY `id` ASC LIMIT 1");
$stmt->execute();
$jr = $stmt->get_result();
safeClose($stmt);

if (!$jr || $jr->num_rows == 0) {
    goto CRON_BROADCAST_PART;
}

$job = $jr->fetch_assoc();
$bc_jobId    = (int)$job['id'];
$broadcastId = (int)$job['broadcast_id'];
$action      = (string)$job['action']; // pin | unpin | delete | edit
$bc_offsetJ  = (int)$job['offset'];
$newText     = isset($job['new_text']) ? (string)$job['new_text'] : '';
$parseMode   = isset($job['parse_mode']) ? (string)$job['parse_mode'] : null;

/* lock -> running */
$stmt = $connection->prepare("UPDATE `bc_jobs` SET `state`=2, `updated_at`=NOW() WHERE `id`=? AND `state`=1");
$stmt->bind_param("i", $bc_jobId);
$stmt->execute();
$aff = $stmt->affected_rows;
safeClose($stmt);

if ($aff == 0) {
    goto CRON_BROADCAST_PART;
}

if ($bc_offsetJ == 0) {
    bot('sendMessage', [
        'chat_id' => $admin,
        'text'    => "▶️ فرآیند با ID:$bc_jobId و عمل $action برای ارسال با ID:$broadcastId شروع شد."
    ]);
}

/* detect send type for edit */
$sendType = 'text';
if ($action === 'edit') {
    $st = $connection->prepare("SELECT `type` FROM `send_list` WHERE `id`=? LIMIT 1");
    if ($st) {
        $st->bind_param("i", $broadcastId);
        $st->execute();
        $r = $st->get_result();
        safeClose($st);
        if ($r && $r->num_rows > 0 && $r->fetch_assoc()['type'] !== 'text') {
            $sendType = 'media';
        }
    }
}

/* build query */
if ($action === 'pin') {
    $q = "
        SELECT id, userid, message_id
        FROM bc_messages
        WHERE broadcast_id=? AND deleted=0 AND pinned=0 AND id > ?
        ORDER BY id
        LIMIT $bc_limit
    ";
} elseif ($action === 'unpin') {
    $q = "
        SELECT id, userid, message_id
        FROM bc_messages
        WHERE broadcast_id=? AND deleted=0 AND pinned=1 AND id > ?
        ORDER BY id
        LIMIT $bc_limit
    ";
} else { // delete OR edit
    $q = "
        SELECT id, userid, message_id
        FROM bc_messages
        WHERE broadcast_id=? AND deleted=0 AND id > ?
        ORDER BY id
        LIMIT $bc_limit
    ";
}

$stmt = $connection->prepare($q);
$stmt->bind_param("ii", $broadcastId, $bc_offsetJ);
$stmt->execute();
$rows = $stmt->get_result();
safeClose($stmt);

if ($rows && $rows->num_rows > 0) {

    $doneThisBatch = 0;
    $lastHbTs = time();

    while ($r = $rows->fetch_assoc()) {
        $rowId = (int)$r['id'];
        $uid   = (int)$r['userid'];
        $mid   = (int)$r['message_id'];
        $bc_offsetJ = $rowId;

        if (($doneThisBatch % $bc_hbEvery == 0) || (time() - $lastHbTs >= $bc_hbSeconds)) {
            hbUpdate($connection, "bc_jobs", $bc_jobId, $bc_offsetJ);
            $lastHbTs = time();

            if (isCancelled($connection, "bc_jobs", $bc_jobId)) {
                markCancelled($connection, "bc_jobs", $bc_jobId, $bc_offsetJ);
                bot('sendMessage', ['chat_id'=>$admin,'text'=>"⛔️ فرآیند با ID:$bc_jobId لغو شد."]);
                $bc_finished = true;
                goto CRON_BROADCAST_PART;
            }
        }

        $res = null;

        if ($action === 'pin') {
            $res = pinOne($uid, $mid);
            if (apiOk($res)) {
                $u = $connection->prepare("UPDATE bc_messages SET pinned=1,pinned_at=NOW() WHERE id=?");
                if ($u) { $u->bind_param("i",$rowId); $u->execute(); safeClose($u); }
            }

        } elseif ($action === 'unpin') {
            $res = unpinOne($uid, $mid);
            if (apiOk($res)) {
                $u = $connection->prepare("UPDATE bc_messages SET pinned=0,pinned_at=NULL WHERE id=?");
                if ($u) { $u->bind_param("i",$rowId); $u->execute(); safeClose($u); }
            }

		} elseif ($action === 'edit') {

			if ($sendType === 'text') {

				$payload = [
					'chat_id'      => $uid,
					'message_id'   => $mid,
					'text'         => $newText,
					'reply_markup' => $inlineStartKey
				];
				if ($parseMode) $payload['parse_mode'] = $parseMode;

				$res = bot('editMessageText', $payload);

				if ($res && isset($res->description) && strpos($res->description, "can't parse entities") !== false) {
					unset($payload['parse_mode']);
					$res = bot('editMessageText', $payload);
				}

			} else {

				$cap = mb_substr($newText, 0, 1024, 'UTF-8');

				$payload = [
					'chat_id'      => $uid,
					'message_id'   => $mid,
					'caption'      => $cap,
					'reply_markup' => $inlineStartKey
				];
				if ($parseMode) $payload['parse_mode'] = $parseMode;

				$res = bot('editMessageCaption', $payload);

				if ($res && isset($res->description) && strpos($res->description, "can't parse entities") !== false) {
					unset($payload['parse_mode']);
					$res = bot('editMessageCaption', $payload);
				}
			}
		} else { // delete
            unpinOne($uid, $mid);
            $res = deleteOne($uid, $mid);
            if (apiOk($res)) {
                $u = $connection->prepare("UPDATE bc_messages SET deleted=1,deleted_at=NOW(),pinned=0,pinned_at=NULL WHERE id=?");
                if ($u) { $u->bind_param("i",$rowId); $u->execute(); safeClose($u); }
            }
        }

        if (isset($res->error_code) && (int)$res->error_code === 429) {
            hbUpdate($connection,"bc_jobs",$bc_jobId,$bc_offsetJ);
            sleep((int)($res->parameters->retry_after ?? 1) + 1);
        }
		
		if (apiOk($res)) {
			$cst = $connection->prepare("UPDATE `bc_jobs` SET `ok_cnt` = `ok_cnt` + 1 WHERE `id`=?");
			if ($cst) { $cst->bind_param("i", $bc_jobId); $cst->execute(); safeClose($cst); }
		} else {
			$cst = $connection->prepare("UPDATE `bc_jobs` SET `fail_cnt` = `fail_cnt` + 1 WHERE `id`=?");
			if ($cst) { $cst->bind_param("i", $bc_jobId); $cst->execute(); safeClose($cst); }
		}

        $doneThisBatch++;
        usleep($bc_sleepUs);
    }

    markPaused($connection, "bc_jobs", $bc_jobId, $bc_offsetJ);

} else {

    markFinished($connection, "bc_jobs", $bc_jobId, $bc_offsetJ);
    bot('sendMessage', [
        'chat_id'=>$admin,
        'text'=>"✅ فرآیند با ID:$bc_jobId و عمل $action برای ارسال با ID:$broadcastId تمام شد."
    ]);
}

$bc_finished = true;

CRON_BROADCAST_PART:
// END Processor: bc_jobs

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
				$u = $connection->prepare("UPDATE `users` SET `step`='Bot_Blocked' WHERE `userid`=? AND `step`<>'banned' AND (`step` IS NULL OR `step`<>'Bot_Blocked')");
				if ($u) {
					$uid = (int)$user['userid'];
					$u->bind_param("i", $uid);
					$u->execute();
					$u->close();
				}
			}

            $logFile = __DIR__ . "/error_log.txt";
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

?>
