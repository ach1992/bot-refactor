<?php
/** AChBot V5: shared worker checkpoints; legacy caller scope and side effects are intentional. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
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

/** Paused jobs may carry a future eligible-at checkpoint after rate limiting. */
function markPaused(mysqli $connection, string $table, int $id, int $offsetVal, int $delaySeconds=0): bool {
    $sql = "UPDATE `$table` SET `offset`=GREATEST(`offset`,?), `state`=1,
        `updated_at`=DATE_ADD(NOW(),INTERVAL ? SECOND) WHERE `id`=? AND `state`=2";
    $st = $connection->prepare($sql);
    if (!$st) return false;
    try {
        $st->bind_param("iii", $offsetVal, $delaySeconds, $id);
        return $st->execute();
    } finally {safeClose($st);}
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

function markFinished(mysqli $connection, string $table, int $id, int $offsetVal): bool {
    $sql = "UPDATE `$table` SET `offset`=GREATEST(`offset`,?), `state`=3, `updated_at`=NOW() WHERE `id`=? AND `state`=2";
    $st = $connection->prepare($sql);
    if (!$st) throw new RuntimeException('job_completion_prepare_failed',$connection->errno);
    try {
        $st->bind_param("ii", $offsetVal, $id);
        if (!$st->execute()) throw new RuntimeException('job_completion_write_failed',$st->errno);
        return $st->affected_rows===1;
    } finally {safeClose($st);}
}
