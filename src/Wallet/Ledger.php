<?php
declare(strict_types=1);

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

/**
 * Wallet-first financial primitive. The authenticated domain caller owns the
 * transaction and ALL other state (receipt, funding, transfer, etc.). On ANY
 * exception it must roll back the entire transaction, not commit a partial
 * result. No provider calls, autocommit, or fallback direct balance writes.
 *
 * The row identity and signed delta define replay equivalence. Actor is the
 * original accountable actor (0 = automatic), not the latest callback actor.
 * A caller that authorizes debt must prove the existing admin permission.
 * Existing signed INT users.wallet is the synchronized balance projection;
 * no rounding, silently clamped SQL values, or arbitrary new product limits.
 */
function achbotWalletApplyLocked(
    mysqli $db,
    int $userId,
    string $sourceKind,
    string $sourceRef,
    int $leg,
    int $delta,
    int $actor = 0,
    bool $allowDebt = false
): array {
    if ($userId <= 0 || $actor < 0 || $delta === 0 ||
        $sourceKind === 'opening' ||
        preg_match('/\\A[a-z][a-z0-9_]{0,31}\\z/D', $sourceKind) !== 1 ||
        $sourceRef === '' || strlen($sourceRef) > 128 ||
        $leg < 0 || $leg > 4294967295 ||
        ($allowDebt && $actor <= 0)) {
        throw new InvalidArgumentException('wallet_entry_invalid');
    }
    $tx = $db->query('SELECT @@in_transaction AS tx_active');
    if (!$tx || (int)($tx->fetch_assoc()['tx_active'] ?? 0) !== 1) {
        throw new LogicException('wallet_transaction_required');
    }

    // Lock the real owner before checking the source. The cutover will also
    // require a unique userid; reject ambiguity rather than choosing LIMIT 1.
    $q = $db->prepare('SELECT id,wallet FROM users WHERE userid=? ORDER BY id LIMIT 2 FOR UPDATE');
    if (!$q) throw new RuntimeException('wallet_owner_prepare');
    $q->bind_param('i', $userId);
    if (!$q->execute() || !($rows = $q->get_result())) {
        $q->close();
        throw new RuntimeException('wallet_owner_read');
    }
    $users = $rows->fetch_all(MYSQLI_ASSOC);
    $q->close();
    if (count($users) !== 1) throw new RuntimeException('wallet_owner_not_unique');
    $rowId = (int)$users[0]['id'];
    $before = (int)$users[0]['wallet'];

    // A current locking read (not a stale consistent-read snapshot) allows
    // original source replay after any subsequent valid balance movements.
    $q = $db->prepare('SELECT id,user_row_id,userid,delta_toman,balance_before,balance_after,actor_userid
        FROM wallet_entries WHERE source_kind=? AND source_ref=? AND leg=? FOR UPDATE');
    if (!$q) throw new RuntimeException('wallet_entry_prepare');
    $q->bind_param('ssi', $sourceKind, $sourceRef, $leg);
    if (!$q->execute() || !($rows = $q->get_result())) {
        $q->close();
        throw new RuntimeException('wallet_entry_read');
    }
    $existing = $rows->fetch_assoc();
    $q->close();
    if ($existing !== null) {
        if ((int)$existing['user_row_id'] !== $rowId ||
            (int)$existing['userid'] !== $userId ||
            (int)$existing['delta_toman'] !== $delta) {
            throw new RuntimeException('wallet_entry_source_conflict');
        }
        return [
            'id'=>(int)$existing['id'],
            'before'=>(int)$existing['balance_before'],
            'after'=>(int)$existing['balance_after'],
            'actor'=>(int)$existing['actor_userid'],
            'replayed'=>true,
        ];
    }

    // Bounds belong to the actual existing signed INT schema, not a business
    // ceiling. Check before addition even for extreme PHP int inputs.
    if ($before < -2147483648 || $before > 2147483647 ||
        $delta < -2147483648 - $before || $delta > 2147483647 - $before) {
        throw new RuntimeException('wallet_balance_out_of_range');
    }
    $after = $before + $delta;
    if ($delta < 0 && !$allowDebt && $after < 0) {
        throw new RuntimeException('wallet_insufficient_funds');
    }

    $q = $db->prepare('INSERT INTO wallet_entries
        (source_kind,source_ref,leg,user_row_id,userid,delta_toman,balance_before,balance_after,actor_userid,created_at)
        VALUES (?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))');
    if (!$q) throw new RuntimeException('wallet_entry_insert_prepare');
    $q->bind_param('ssiiiiiii', $sourceKind,$sourceRef,$leg,$rowId,$userId,$delta,$before,$after,$actor);
    if (!$q->execute() || $q->affected_rows !== 1) {
        $q->close();
        throw new RuntimeException('wallet_entry_insert_failed');
    }
    $entryId = (int)$q->insert_id;
    $q->close();
    $q = $db->prepare('UPDATE users SET wallet=? WHERE id=? AND userid=? AND wallet=?');
    if (!$q) throw new RuntimeException('wallet_projection_prepare');
    $q->bind_param('iiii', $after,$rowId,$userId,$before);
    if (!$q->execute() || $q->affected_rows !== 1) {
        $q->close();
        throw new RuntimeException('wallet_projection_write_failed');
    }
    $q->close();

    return ['id'=>$entryId,'before'=>$before,'after'=>$after,'actor'=>$actor,'replayed'=>false];
}
