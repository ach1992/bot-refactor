<?php
/** AChBot V5: message action worker; legacy caller scope and side effects are intentional. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG


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
    return;
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
    return;
}

if ($bc_offsetJ == 0) {
    bot('sendMessage', [
        'chat_id' => $admin,
        'text'    => \achbotMessage('jobs.message_actions.button.5d55dfe1f8', ['bc_jobId' => $bc_jobId, 'action' => $action, 'broadcastId' => $broadcastId])
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
                bot('sendMessage', ['chat_id'=>$admin,'text'=>\achbotMessage('jobs.message_actions.button.76bf7d1a9d', ['bc_jobId' => $bc_jobId])]);
                $bc_finished = true;
                return;
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
        'text'=>\achbotMessage('jobs.message_actions.button.8ece62920c', ['bc_jobId' => $bc_jobId, 'action' => $action, 'broadcastId' => $broadcastId])
    ]);
}

$bc_finished = true;

// END Processor: bc_jobs
