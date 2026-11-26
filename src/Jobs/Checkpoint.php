<?php
/** Shared durable checkpoints for the two broadcast queues under the Cron lease. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__.'/../Broadcast/Storage.php';

function safeClose($stmt) {
    if ($stmt) { try { $stmt->close(); } catch (Throwable $error) {} }
}

/** Recover a running job as soon as the exclusive Cron lease is reacquired.
 * Paused updated_at is the earliest eligible time; preserve FIFO per queue.
 * The predicates select current executable work, not historical raw text. */
function achbotJobTake(mysqli $db,string $table): ?array {
    $eligible=match($table) {
        'send_list'=>"(type='forwardall' OR text_html IS NOT NULL)",
        'bc_jobs'=>"action IN ('pin','unpin','delete','edit') AND (action<>'edit' OR new_html IS NOT NULL)
            AND EXISTS (SELECT 1 FROM send_list s WHERE s.id=bc_jobs.broadcast_id AND s.state=3
                AND (bc_jobs.action<>'edit' OR s.type<>'forwardall'))",
    };
    $result=achbotBroadcastQuery($db,"SELECT id,`offset`,state,updated_at,
        (state=2 OR updated_at IS NULL OR updated_at<=NOW()) AS ready
        FROM `$table` WHERE state IN (1,2) AND $eligible ORDER BY id LIMIT 1");
    $candidate=$result->fetch_assoc();$result->free();
    if (!$candidate || !(int)$candidate['ready']) return null;
    $id=(int)$candidate['id'];$offset=(int)$candidate['offset'];
    achbotBroadcastQuery($db,"UPDATE `$table` SET state=2,updated_at=NOW()
        WHERE id=? AND `offset`=?
        AND (state=2 OR (state=1 AND (updated_at IS NULL OR updated_at<=NOW())))",[$id,$offset]);
    // Recovered state=2 may already have the same timestamp. Read its
    // guarded state rather than treating zero changed rows as failure.
    $result=achbotBroadcastQuery($db,"SELECT * FROM `$table`
        WHERE id=? AND state=2 AND `offset`=?",[$id,$offset]);
    $job=$result->fetch_assoc();$result->free();
    if (!$job) return null;
    $job['first_state']=(int)$candidate['state'];
    $job['first_updated_at']=$candidate['updated_at'];
    return $job;
}

/** A durable compare-and-set claim is required before any external effect. */
function achbotJobClaimStatement(mysqli $db,string $table,int &$next,int $id,int &$offset): mysqli_stmt {
    $stmt=$db->prepare("UPDATE `$table` SET `offset`=?,updated_at=NOW()
        WHERE id=? AND state=2 AND `offset`=?");
    if (!$stmt) throw new RuntimeException('job_claim_prepare_failed',$db->errno);
    if (!$stmt->bind_param('iii',$next,$id,$offset)) {
        $errno=$stmt->errno;safeClose($stmt);
        throw new RuntimeException('job_claim_bind_failed',$errno);
    }
    return $stmt;
}
function achbotJobClaim(mysqli_stmt $stmt): bool {
    if (!$stmt->execute()) throw new RuntimeException('job_claim_write_failed',$stmt->errno);
    return $stmt->affected_rows===1;
}

function markPaused(mysqli $db,string $table,int $id,int $offset,int $delaySeconds=0): void {
    achbotBroadcastQuery($db,"UPDATE `$table` SET `offset`=GREATEST(`offset`,?),state=1,
        updated_at=DATE_ADD(NOW(),INTERVAL ? SECOND) WHERE id=? AND state=2",[$offset,$delaySeconds,$id]);
}

function isCancelled(mysqli $db,string $table,int $id): bool {
    $result=achbotBroadcastQuery($db,"SELECT state FROM `$table` WHERE id=?",[$id]);
    $row=$result->fetch_assoc();$result->free();
    return $row && (int)$row['state']===4;
}

function markFinished(mysqli $db,string $table,int $id,int $offset): bool {
    achbotBroadcastQuery($db,"UPDATE `$table` SET `offset`=GREATEST(`offset`,?),state=3,
        updated_at=NOW() WHERE id=? AND state=2",[$offset,$id]);
    return $db->affected_rows===1;
}

/** Reports are independent of the persisted processing state. */
function achbotJobNotice(string $kind,int $id,string $phase,string $text): void {
    try {
        achbotNotifyAdmins('sendMessage',['text'=>$text],
            ['report_channel'=>null,'report_ref'=>$kind.':'.$id.':'.$phase]);
    } catch (Throwable $error) {
        error_log('achbot_'.$kind.'_notice_failed '.$kind.'_id='.$id.' phase='.$phase.
            ' class='.get_class($error).' code='.(int)$error->getCode());
    }
}

/** Pause cannot rewind or revive cancelled/completed work. If persistence
 * fails, the running state is still recoverable under the next Cron lease. */
function achbotJobPause(mysqli $db,string $table,string $kind,int $id,int $offset,int $delay): void {
    try {
        markPaused($db,$table,$id,$offset,$delay);
    } catch (Throwable $error) {
        error_log('achbot_'.$kind.'_checkpoint_deferred '.$kind.'_id='.$id.' offset='.$offset.
            ' class='.get_class($error).' code='.(int)$error->getCode());
        throw new RuntimeException($kind.'_checkpoint_deferred',(int)$error->getCode());
    }
}

/** Bound one dispatch slice, not the audience or total job duration. Both
 * queues get their own turn; a slow in-flight request is allowed to finish. */
function achbotJobSliceDeadline(): int { return hrtime(true)+45_000_000_000; }
