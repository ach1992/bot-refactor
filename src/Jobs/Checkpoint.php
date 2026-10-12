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

