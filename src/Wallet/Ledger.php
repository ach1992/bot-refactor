<?php
declare(strict_types=1);

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

/**
 * Cutover/route preflight for the shared monetary journal. Missing or
 * nontransactional storage cannot be used as a balance-write fallback.
 * Schema changes require quiescing financial writers.
 */
function achbotWalletLedgerReady(mysqli $db): bool {
    try {
        $r=$db->query("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('users','wallet_entries')");
        if (!$r) return false;
        $tables=array_column($r->fetch_all(MYSQLI_ASSOC),'ENGINE','TABLE_NAME');
        if (strtoupper((string)($tables['users']??''))!=='INNODB' ||
            strtoupper((string)($tables['wallet_entries']??''))!=='INNODB') return false;
        $r=$db->query("SELECT TABLE_NAME,COLUMN_NAME,DATA_TYPE,COLUMN_TYPE,IS_NULLABLE,
            CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND
            (TABLE_NAME='wallet_entries' OR (TABLE_NAME='users' AND COLUMN_NAME IN ('id','userid','wallet')))");
        if (!$r) return false;
        $columns=[];
        foreach ($r->fetch_all(MYSQLI_ASSOC) as $c)
            $columns[$c['TABLE_NAME']][$c['COLUMN_NAME']]=$c;
        $required=[
            'users'=>['id'=>'int','userid'=>'bigint','wallet'=>'int'],
            'wallet_entries'=>['id'=>'bigint unsigned','source_kind'=>'varbinary',
                'source_ref'=>'varbinary','leg'=>'int unsigned','user_row_id'=>'int',
                'userid'=>'bigint','delta_toman'=>'bigint','balance_before'=>'bigint',
                'balance_after'=>'bigint','actor_userid'=>'bigint','created_at'=>'datetime']
        ];
        foreach ($required as $table=>$fields) foreach ($fields as $name=>$type) {
            $c=$columns[$table][$name]??null;
            if (!$c || $c['IS_NULLABLE']!=='NO' ||
                ($c['DATA_TYPE'].(str_contains($c['COLUMN_TYPE'],'unsigned')?' unsigned':''))!==$type)
                return false;
        }
        if ((int)$columns['wallet_entries']['source_kind']['CHARACTER_MAXIMUM_LENGTH']!==32 ||
            (int)$columns['wallet_entries']['source_ref']['CHARACTER_MAXIMUM_LENGTH']!==128) return false;
        $r=$db->query("SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART
            FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND
            ((TABLE_NAME='wallet_entries' AND INDEX_NAME IN ('PRIMARY','uq_wallet_entry_source'))
            OR (TABLE_NAME='users' AND INDEX_NAME IN ('PRIMARY','idx_users_userid_v1')))
            ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX");
        if (!$r) return false;
        $keys=[];
        foreach ($r->fetch_all(MYSQLI_ASSOC) as $row) {
            if ($row['SUB_PART']!==null) return false;
            $keys[$row['TABLE_NAME']][$row['INDEX_NAME']][]=[(int)$row['NON_UNIQUE'],$row['COLUMN_NAME']];
        }
        return ($keys['wallet_entries']['PRIMARY']??null)===[[0,'id']] &&
            ($keys['wallet_entries']['uq_wallet_entry_source']??null)===
                [[0,'source_kind'],[0,'source_ref'],[0,'leg']] &&
            ($keys['users']['PRIMARY']??null)===[[0,'id']] &&
            in_array($keys['users']['idx_users_userid_v1']??null,[[[0,'userid']],[[1,'userid']]],true);
    } catch (Throwable $error) {
        return false;
    }
}

/**
 * A current unique owner row is the common lock for monetary commands.
 * Acquire it before invoice/operation rows in the new financial paths.
 */
function achbotWalletLockOwnerLocked(mysqli $db,int $userId): array {
    if ($userId<=0) throw new InvalidArgumentException('wallet_owner_invalid');
    $tx = $db->query('SELECT @@in_transaction AS tx_active');
    if (!$tx || (int)($tx->fetch_assoc()['tx_active'] ?? 0) !== 1) {
        throw new LogicException('wallet_transaction_required');
    }

    // Lock the real owner before checking the source. The cutover will also
    // require a unique userid; reject ambiguity rather than choosing LIMIT 1.
    $q = $db->prepare('SELECT id,wallet FROM users FORCE INDEX (idx_users_userid_v1) WHERE userid=? ORDER BY id LIMIT 2 FOR UPDATE');
    if (!$q) throw new RuntimeException('wallet_owner_prepare');
    $q->bind_param('i', $userId);
    if (!$q->execute() || !($rows = $q->get_result())) {
        $q->close();
        throw new RuntimeException('wallet_owner_read');
    }
    $users = $rows->fetch_all(MYSQLI_ASSOC);
    $q->close();
    if (count($users) !== 1) throw new RuntimeException('wallet_owner_not_unique');
    return ['id'=>(int)$users[0]['id'], 'wallet'=>(int)$users[0]['wallet']];
}

/**
 * Internal exact-source lookup. The caller owns the transaction and has
 * locked the unique user row. No INSERT or projection write on a miss.
 */
function achbotWalletFindEntryLocked(mysqli $db,int $rowId,int $userId,
    string $sourceKind,string $sourceRef,int $leg,int $delta): ?array {
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
    return null;
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
    $owner = achbotWalletLockOwnerLocked($db,$userId);
    $rowId = $owner['id'];
    $before = $owner['wallet'];

    $existing = achbotWalletFindEntryLocked($db,$rowId,$userId,$sourceKind,$sourceRef,$leg,$delta);
    if ($existing !== null) return $existing;

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
