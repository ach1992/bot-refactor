<?php
/**
 * AChBot wallet movement journal shared by administrator adjustments and
 * user transfers. Callers own the transaction; never log after committing.
 * Migration 005 is an RC9-to-current addition, not an RC10 upgrade script.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

if (!function_exists('achbotWalletAdjustmentsReady')) {
    function achbotWalletAdjustmentsReady(mysqli $db): bool
    {
        try {
            $schema = $db->query("SELECT DATABASE() AS db")->fetch_assoc()['db'] ?? null;
            if (!is_string($schema) || $schema === '') {
                return false;
            }
            $stmt = $db->prepare("SELECT ENGINE FROM information_schema.TABLES
                WHERE TABLE_SCHEMA=? AND TABLE_NAME='wallet_adjustments'");
            $stmt->bind_param('s', $schema);
            $stmt->execute();
            $engine = $stmt->get_result()->fetch_assoc()['ENGINE'] ?? null;
            $stmt->close();
            if (strtoupper((string)$engine) !== 'INNODB') {
                return false;
            }

            $stmt = $db->prepare("SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE,
                CHARACTER_MAXIMUM_LENGTH, COLLATION_NAME, EXTRA, COLUMN_DEFAULT
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA=? AND TABLE_NAME='wallet_adjustments'");
            $stmt->bind_param('s', $schema);
            $stmt->execute();
            $columns = [];
            foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $column) {
                $columns[$column['COLUMN_NAME']] = $column;
            }
            $stmt->close();
            $required = [
                'id'=>'bigint', 'actor_userid'=>'bigint', 'source_type'=>'varchar',
                'source_id'=>'varchar', 'entry_no'=>'tinyint', 'target_userid'=>'bigint',
                'telegram_update_id'=>'bigint', 'source_chat_id'=>'bigint',
                'source_message_id'=>'bigint', 'adjustment_type'=>'varchar',
                'amount_toman'=>'int', 'before_balance'=>'int',
                'after_balance'=>'int', 'reason'=>'varchar', 'created_at'=>'datetime'
            ];
            if (count($columns) !== count($required)) {
                return false;
            }
            foreach ($required as $name => $type) {
                if (!isset($columns[$name]) ||
                    strtolower((string)$columns[$name]['DATA_TYPE']) !== $type ||
                    $columns[$name]['IS_NULLABLE'] !== 'NO') {
                    return false;
                }
            }
            if (!str_contains(strtolower($columns['id']['COLUMN_TYPE']), 'unsigned') ||
                !str_contains(strtolower($columns['amount_toman']['COLUMN_TYPE']), 'unsigned') ||
                str_contains(strtolower($columns['before_balance']['COLUMN_TYPE']), 'unsigned') ||
                str_contains(strtolower($columns['after_balance']['COLUMN_TYPE']), 'unsigned')) {
                return false;
            }
            foreach (['actor_userid','target_userid','telegram_update_id',
                'source_chat_id','source_message_id'] as $signedBigint) {
                if (str_contains(strtolower($columns[$signedBigint]['COLUMN_TYPE']), 'unsigned')) {
                    return false;
                }
            }
            if (!str_contains(strtolower($columns['entry_no']['COLUMN_TYPE']), 'unsigned') ||
                (int)$columns['source_type']['CHARACTER_MAXIMUM_LENGTH'] !== 32 ||
                $columns['source_type']['COLLATION_NAME'] !== 'utf8mb4_bin' ||
                (int)$columns['source_id']['CHARACTER_MAXIMUM_LENGTH'] !== 64 ||
                $columns['source_id']['COLLATION_NAME'] !== 'utf8mb4_bin' ||
                (int)$columns['adjustment_type']['CHARACTER_MAXIMUM_LENGTH'] !== 16 ||
                $columns['adjustment_type']['COLLATION_NAME'] !== 'utf8mb4_bin' ||
                (int)$columns['reason']['CHARACTER_MAXIMUM_LENGTH'] !== 500 ||
                !str_contains(strtolower((string)$columns['id']['EXTRA']), 'auto_increment') ||
                !str_contains(strtolower((string)$columns['created_at']['COLUMN_DEFAULT']), 'current_timestamp')) {
                return false;
            }
            $stmt = $db->prepare("SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
                FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA=? AND TABLE_NAME='wallet_adjustments'
                ORDER BY INDEX_NAME, SEQ_IN_INDEX");
            $stmt->bind_param('s', $schema);
            $stmt->execute();
            $indexes = [];
            foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $idx) {
                $indexes[$idx['INDEX_NAME']][] = [
                    (int)$idx['NON_UNIQUE'], (int)$idx['SEQ_IN_INDEX'], $idx['COLUMN_NAME']
                ];
            }
            $stmt->close();
            if (($indexes['PRIMARY'] ?? null) !== [[0, 1, 'id']] ||
                ($indexes['uq_wallet_source_entry'] ?? null) !==
                    [[0, 1, 'source_type'], [0, 2, 'source_id'], [0, 3, 'entry_no']] ||
                ($indexes['uq_wallet_telegram_entry'] ?? null) !==
                    [[0, 1, 'telegram_update_id'], [0, 2, 'entry_no']]) {
                return false;
            }
            // Audit rows must survive deletion of the original bot user.
            $stmt = $db->prepare("SELECT COUNT(*) AS n
                FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA=? AND TABLE_NAME='wallet_adjustments'
                  AND REFERENCED_TABLE_NAME IS NOT NULL");
            $stmt->bind_param('s', $schema);
            $stmt->execute();
            $foreignKeys = (int)($stmt->get_result()->fetch_assoc()['n'] ?? -1);
            $stmt->close();
            if ($foreignKeys !== 0) {
                return false;
            }
            // Financial balance and step must roll back WITH the 005 ledger.
            // The historical users table is InnoDB, but an installed tenant
            // may drift independently. Never assume the frozen dump is live.
            $stmt = $db->prepare("SELECT ENGINE FROM information_schema.TABLES
                WHERE TABLE_SCHEMA=? AND TABLE_NAME='users'");
            $stmt->bind_param('s', $schema);
            $stmt->execute();
            $usersEngine = $stmt->get_result()->fetch_assoc()['ENGINE'] ?? null;
            $stmt->close();
            if (strtoupper((string)$usersEngine) !== 'INNODB') {
                return false;
            }

            $stmt = $db->prepare("SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE,
                IS_NULLABLE, CHARACTER_MAXIMUM_LENGTH
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA=? AND TABLE_NAME='users'
                  AND COLUMN_NAME IN ('userid','wallet','step','isAdmin')");
            $stmt->bind_param('s', $schema);
            $stmt->execute();
            $userColumns = [];
            foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $column) {
                $userColumns[$column['COLUMN_NAME']] = $column;
            }
            $stmt->close();
            $userTypes = ['userid'=>'bigint', 'wallet'=>'int',
                          'step'=>'varchar', 'isAdmin'=>'tinyint'];
            if (count($userColumns) !== count($userTypes)) {
                return false;
            }
            foreach ($userTypes as $column => $type) {
                if (!isset($userColumns[$column]) ||
                    strtolower((string)$userColumns[$column]['DATA_TYPE']) !== $type ||
                    $userColumns[$column]['IS_NULLABLE'] !== 'NO' ||
                    str_contains(strtolower((string)$userColumns[$column]['COLUMN_TYPE']),
                        'unsigned')) {
                    return false;
                }
            }
            if ((int)$userColumns['step']['CHARACTER_MAXIMUM_LENGTH'] !== 1000) {
                return false;
            }
            return true;
        } catch (Throwable $error) {
            error_log('achbot_wallet_adjustments_preflight_unavailable');
            return false;
        }
    }
}

/**
 * Record one Telegram-sourced leg inside the caller's balance transaction.
 * The generic source identity permits multiple legs and future non-Telegram
 * writers without treating Telegram update IDs as globally unique row IDs.
 * Telegram update/leg uniqueness additionally spans all operation types:
 * both current operations own entry 1, and only a transfer adds entry 2.
 * Current callers use source_type admin/transfer, source_id = update ID.
 */
function achbotInsertTelegramWalletAdjustment(
    mysqli $db, string $sourceType, int $updateId, int $entryNo,
    int $actorId, int $targetId, int $chatId, int $messageId,
    string $kind, int $amount, int $before, int $after, string $reason
): void {
    // Every supported Telegram operation owns leg 1. Only its transfer
    // credit may use leg 2; callers cannot evade event consumption via a
    // different leg number or an inconsistent kind.
    $validLeg = ($sourceType === 'admin' && $entryNo === 1 &&
        in_array($kind, ['credit', 'debit', 'debt'], true)) ||
        ($sourceType === 'transfer' &&
         (($entryNo === 1 && $kind === 'transfer_out') ||
          ($entryNo === 2 && $kind === 'transfer_in')));
    $delta = in_array($kind, ['credit', 'transfer_in'], true) ? $amount : -$amount;
    if (!$validLeg || !in_array($sourceType, ['admin', 'transfer'], true) ||
        !in_array($kind, ['credit', 'debit', 'debt', 'transfer_in', 'transfer_out'], true) ||
        $updateId <= 0 || $entryNo < 1 || $entryNo > 255 ||
        $actorId <= 0 || $targetId <= 0 || $chatId === 0 || $messageId <= 0 ||
        $amount < 1 || $amount > 2147483647 ||
        $before < -2147483648 || $before > 2147483647 ||
        $after < -2147483648 || $after > 2147483647 ||
        $after !== $before + $delta || $reason === '' || strlen($reason) > 500) {
        throw new RuntimeException('wallet_journal_invalid_entry');
    }
    $sourceId = (string)$updateId;
    $stmt = $db->prepare("INSERT INTO wallet_adjustments
        (source_type,source_id,entry_no,actor_userid,target_userid,
         telegram_update_id,source_chat_id,source_message_id,
         adjustment_type,amount_toman,before_balance,after_balance,reason)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
    if (!$stmt) {
        throw new RuntimeException('wallet_journal_insert_unavailable');
    }
    $stmt->bind_param('ssiiiiiisiiis', $sourceType, $sourceId, $entryNo,
        $actorId, $targetId, $updateId, $chatId, $messageId,
        $kind, $amount, $before, $after, $reason);
    if (!$stmt->execute() || $stmt->affected_rows !== 1) {
        $stmt->close();
        throw new RuntimeException('wallet_journal_insert_failed');
    }
    $stmt->close();
}
