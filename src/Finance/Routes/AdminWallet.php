<?php
/**
 * AChBot post-V9: audited operator wallet adjustments in the shared movement journal.
 *
 * Source-only: migration 005 must be separately authorized and applied
 * before this route can alter balances. Never fall back to legacy writes.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../../messages/loader.php'; // ACHBOT_TEXT_CATALOG


require_once dirname(__DIR__) . '/WalletAdjustments.php';

if (!function_exists('achbotAdminWalletPrivateCallback')) {
    /** Enter the privileged wallet flow only via the operator's own bot DM. */
    function achbotAdminWalletPrivateCallback(
        $update, $actorId, $chatId, $messageId, $boundData
    ): bool {
        if (!is_object($update) || !isset($update->callback_query) ||
            !is_object($update->callback_query) ||
            isset($update->message) ||
            !is_int($update->update_id ?? null) || $update->update_id <= 0) {
            return false;
        }
        $keys = array_keys(get_object_vars($update));
        sort($keys);
        if ($keys !== ['callback_query', 'update_id']) {
            return false;
        }
        $callback = $update->callback_query;
        $sender = $callback->from ?? null;
        $message = $callback->message ?? null;
        $chat = is_object($message) ? ($message->chat ?? null) : null;
        return is_object($sender) && is_object($chat) &&
            is_int($sender->id ?? null) && $sender->id > 0 &&
            ($sender->is_bot ?? false) !== true &&
            is_int($chat->id ?? null) &&
            ($chat->type ?? null) === 'private' &&
            $chat->id === $sender->id &&
            $chat->id === (int)$actorId &&
            $chat->id === (int)$chatId &&
            is_int($message->message_id ?? null) &&
            $message->message_id === (int)$messageId &&
            is_string($callback->data ?? null) &&
            $callback->data === $boundData;
    }
}

if (!function_exists('achbotAdminWalletFreshReason')) {
    /**
     * Require the actual NEW Telegram text-message envelope, not the shared
     * $text binding: callbacks bind it to the referenced BOT message text.
     * Read-only validation; callbacks cannot consume steps in this module.
     * The later real managePanel handler intentionally cancels on navigation.
     */
    function achbotAdminWalletFreshReason(
        $update, $actorId, $chatId, $messageId, $boundText
    ): ?string {
        if (!is_object($update)) {
            return null;
        }
        $keys = array_keys(get_object_vars($update));
        sort($keys);
        if ($keys !== ['message', 'update_id'] ||
            !is_object($update->message) ||
            !is_int($update->update_id) || $update->update_id <= 0) {
            return null;
        }
        $message = $update->message;
        if (!isset($message->from, $message->chat) ||
            !is_object($message->from) || !is_object($message->chat) ||
            !is_int($message->from->id ?? null) || $message->from->id <= 0 ||
            ($message->from->is_bot ?? false) === true ||
            !is_int($message->chat->id ?? null) ||
            ($message->chat->type ?? null) !== 'private' ||
            $message->chat->id !== $message->from->id ||
            !is_int($message->message_id ?? null) || $message->message_id <= 0 ||
            !is_string($message->text ?? null) ||
            !is_string($boundText)) {
            return null;
        }
        if ($message->from->id !== (int)$actorId ||
            $message->chat->id !== (int)$chatId ||
            $message->message_id !== (int)$messageId ||
            $boundText !== $message->text) {
            return null;
        }
        return trim($message->text);
    }
}

if (!function_exists('achbotAdminWalletClaimStep')) {
    /** Called only while the unique, authorized operator row is locked. */
    function achbotAdminWalletClaimStep(
        mysqli $db, int $operatorId, string $expectedStep, string $nextStep
    ): void {
        $stmt = $db->prepare('UPDATE users SET step=? WHERE userid=? AND step=?');
        $stmt->bind_param('sis', $nextStep, $operatorId, $expectedStep);
        if (!$stmt->execute() || $stmt->affected_rows !== 1) {
            $stmt->close();
            throw new RuntimeException('operator_claim_failed');
        }
        $stmt->close();
    }
}

if (!function_exists('achbotAdminWalletOperation')) {
    /** Facts captured under the wallet lock, exposed only after COMMIT. */
    function achbotAdminWalletOperation(
        int $targetId, string $kind, int $amount, int $before, int $after,
        int $updateId
    ): array {
        return ['target' => $targetId, 'kind' => $kind, 'amount' => $amount,
            'before' => $before, 'after' => $after, 'correlation' => $updateId];
    }
}

if (!function_exists('achbotApplyAdminWalletAdjustment')) {
    /**
     * Exactly one ledger row and wallet effect per authentic Telegram update.
     * Returns applied/already_applied with committed operation facts, or a
     * nonfinancial debt_required, balance_changed, stale/invalid/review result.
     */
    function achbotApplyAdminWalletAdjustment(
        mysqli $db, int $operatorId, int $targetId, int $updateId,
        int $chatId, int $messageId, string $kind, int $amount,
        string $reason, string $expectedStep, int $primaryAdmin,
        ?array &$operation = null, ?int $expectedBefore = null
    ): string {
        $operation = null;
        if ($operatorId <= 0 || $targetId <= 0 || $updateId <= 0 ||
            $messageId <= 0 || $chatId === 0 ||
            !in_array($kind, ['credit', 'debit', 'debt'], true) ||
            $amount < 1 || $amount > 2147483647 ||
            $reason === '' || strlen($reason) > 500 ||
            !achbotWalletAdjustmentsReady($db)) {
            return 'unavailable';
        }
        $moneyAttempted = false;
        try {
            if (!$db->begin_transaction()) {
                throw new RuntimeException('journal_transaction_start_failed');
            }
            // Lock in a stable order, including when operator == customer.
            $stmt = $db->prepare("SELECT userid, wallet, step, isAdmin FROM users
                WHERE userid IN (?, ?) ORDER BY userid FOR UPDATE");
            $stmt->bind_param('ii', $operatorId, $targetId);
            $stmt->execute();
            $users = [];
            $duplicateIdentity = false;
            foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
                $identity = (int)$row['userid'];
                if (isset($users[$identity])) {
                    $duplicateIdentity = true;
                    break;
                }
                $users[$identity] = $row;
            }
            $stmt->close();
            // Historical userid is indexed but NOT guaranteed UNIQUE.
            // Never select an arbitrary wallet or operator from duplicates.
            if ($duplicateIdentity || !isset($users[$operatorId], $users[$targetId]) ||
                ($operatorId !== $primaryAdmin &&
                 (int)$users[$operatorId]['isAdmin'] !== 1)) {
                $db->rollback();
                return 'invalid';
            }
            if ((string)$users[$operatorId]['step'] !== $expectedStep) {
                $db->rollback();
                return 'stale';
            }

            $before = (int)$users[$targetId]['wallet'];
            $delta = $kind === 'credit' ? $amount : -$amount;
            $after = $before + $delta;
            if ($after < -2147483648 || $after > 2147483647) {
                $db->rollback();
                return 'invalid';
            }
            if ($expectedBefore !== null && $before !== $expectedBefore) {
                // Consent was to this exact signed balance result. A concurrent
                // credit/debit must never silently change the accepted debt.
                achbotAdminWalletClaimStep($db, $operatorId, $expectedStep, 'none');
                if (!$db->commit()) {
                    throw new RuntimeException('journal_commit_unconfirmed');
                }
                return 'balance_changed';
            }
            if ($kind === 'debit' && $after < 0) {
                // No movement/journal row yet. Persist the original private
                // reason and this locked preview in the SAME operator claim.
                // A fresh nonce prevents replayed source messages from
                // reconstructing a previously canceled flow's consent.
                $nextStep = 'achbotWalletDebt:' . $targetId . ':' . $amount . ':' .
                    $before . ':' . $messageId . ':' . $updateId . ':' .
                    bin2hex(random_bytes(16)) . ':' . base64_encode($reason);
                achbotAdminWalletClaimStep($db, $operatorId, $expectedStep, $nextStep);
                if (!$db->commit()) {
                    throw new RuntimeException('journal_commit_unconfirmed');
                }
                $operation = achbotAdminWalletOperation($targetId, 'debt',
                    $amount, $before, $after, $updateId);
                $operation['pending_step'] = $nextStep;
                return 'debt_required';
            }
            $moneyAttempted = true;
            achbotInsertTelegramWalletAdjustment($db, 'admin', $updateId, 1,
                $operatorId, $targetId, $chatId, $messageId, $kind, $amount,
                $before, $after, $reason);

            $stmt = $db->prepare("UPDATE users SET wallet=? WHERE userid=? AND wallet=?");
            $stmt->bind_param('iii', $after, $targetId, $before);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();
            if ($affected !== 1) {
                throw new RuntimeException('balance_guard_failed');
            }
            achbotAdminWalletClaimStep($db, $operatorId, $expectedStep, 'none');
            if (!$db->commit()) {
                throw new RuntimeException('journal_commit_unconfirmed');
            }
            $operation = achbotAdminWalletOperation($targetId, $kind, $amount,
                $before, $after, $updateId);
            return 'applied';
        } catch (Throwable $error) {
            try { $db->rollback(); } catch (Throwable $ignored) {}
            // COMMIT may have succeeded even if its acknowledgement was lost.
            // Reconcile only this unique immutable update identity; never
            // automatically redo an ambiguous balance effect.
            try {
                if (!$moneyAttempted) {
                    throw new RuntimeException('nonfinancial_commit_unconfirmed');
                }
                $stmt = $db->prepare("SELECT actor_userid,target_userid,
                    source_chat_id,source_message_id,reason,
                    adjustment_type,amount_toman,before_balance,after_balance FROM wallet_adjustments
                    WHERE source_type='admin' AND source_id=?
                      AND entry_no=1 AND telegram_update_id=?");
                $sourceId = (string)$updateId;
                $stmt->bind_param('si', $sourceId, $updateId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row && (int)$row['actor_userid'] === $operatorId &&
                    (int)$row['target_userid'] === $targetId &&
                    (int)$row['source_chat_id'] === $chatId &&
                    (int)$row['source_message_id'] === $messageId &&
                    (string)$row['reason'] === $reason &&
                    (string)$row['adjustment_type'] === $kind &&
                    (int)$row['amount_toman'] === $amount) {
                    $operation = achbotAdminWalletOperation(
                        (int)$row['target_userid'], (string)$row['adjustment_type'],
                        (int)$row['amount_toman'], (int)$row['before_balance'],
                        (int)$row['after_balance'], $updateId);
                    return 'already_applied';
                }
            } catch (Throwable $ignored) {}
            error_log('achbot_admin_wallet_adjustment_review update_id=' . $updateId);
            return 'review';
        }
    }
}


if (!function_exists('achbotAdminWalletIsPendingStep')) {
    /** Identify only the administrative wallet UI steps, not generic bot steps. */
    function achbotAdminWalletIsPendingStep(string $step): bool
    {
        return in_array($step, [
            'increaseUserWallet', 'decreaseUserWallet', 'createUserWalletDebt'
        ], true) ||
            preg_match('/^(increaseWalletUser|decreaseWalletUser|debtWalletUser)[1-9][0-9]{0,18}$/D', $step) === 1 ||
            str_starts_with($step, 'achbotWalletReason:') ||
            str_starts_with($step, 'achbotWalletDebt:');
    }
}

if (!function_exists('achbotCancelAdminWalletPendingStep')) {
    /**
     * A verified cancellation must win the SAME users.step transition as the
     * financial journal transaction. A cached $userInfo step is not proof.
     *
     * canceled: this transaction atomically consumed the exact pending step.
     * stale: another worker changed the step before cancellation acquired it.
     * review: DB/identity/authorization/commit outcome cannot be confirmed.
     *
     * All non-canceled outcomes require a neutral operator status message.
     */
    function achbotCancelAdminWalletPendingStep(
        mysqli $db, int $operatorId, string $expectedStep, int $primaryAdmin
    ): string {
        if ($operatorId <= 0 ||
            !achbotAdminWalletIsPendingStep($expectedStep) ||
            !achbotWalletAdjustmentsReady($db)) {
            return 'review';
        }

        $transactionStarted = false;
        try {
            if (!$db->begin_transaction()) {
                throw new RuntimeException('admin_wallet_cancel_begin_unconfirmed');
            }
            $transactionStarted = true;
            // Finance reasons lock the same operator row FOR UPDATE before
            // journal/balance/step commit; whichever worker locks first wins.
            $stmt = $db->prepare(
                'SELECT userid, step, isAdmin FROM users WHERE userid=? FOR UPDATE'
            );
            if (!$stmt) {
                throw new RuntimeException('admin_wallet_cancel_select_unavailable');
            }
            $stmt->bind_param('i', $operatorId);
            if (!$stmt->execute()) {
                throw new RuntimeException('admin_wallet_cancel_select_failed');
            }
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            // Legacy users.userid is indexed but not UNIQUE. Never claim
            // successful cancellation for an ambiguous operator identity.
            if (count($rows) !== 1 ||
                (int)$rows[0]['userid'] !== $operatorId ||
                ($operatorId !== $primaryAdmin &&
                 (int)$rows[0]['isAdmin'] !== 1)) {
                throw new RuntimeException('admin_wallet_cancel_identity_unconfirmed');
            }
            if ((string)$rows[0]['step'] !== $expectedStep) {
                $db->rollback();
                return 'stale';
            }

            $stmt = $db->prepare(
                "UPDATE users SET step='none' WHERE userid=? AND step=?"
            );
            if (!$stmt) {
                throw new RuntimeException('admin_wallet_cancel_update_unavailable');
            }
            $stmt->bind_param('is', $operatorId, $expectedStep);
            if (!$stmt->execute()) {
                throw new RuntimeException('admin_wallet_cancel_update_failed');
            }
            $affected = $stmt->affected_rows;
            $stmt->close();
            if ($affected !== 1) {
                throw new RuntimeException('admin_wallet_cancel_claim_unconfirmed');
            }
            if (!$db->commit()) {
                throw new RuntimeException('admin_wallet_cancel_commit_unconfirmed');
            }
            return 'canceled';
        } catch (Throwable $error) {
            if ($transactionStarted) {
                try { $db->rollback(); } catch (Throwable $ignored) {}
            }
            // A lost COMMIT acknowledgment may mean cancellation succeeded.
            // Never claim either success or no financial effect in that case.
            error_log('achbot_admin_wallet_cancellation_review');
            return 'review';
        }
    }
}

if (!function_exists('achbotAdminWalletDebtCallback')) {
    function achbotAdminWalletDebtCallback(int $operatorId, string $step): string
    {
        return substr(hash('sha256', $operatorId . ':' . $step), 0, 32);
    }
}

if (!function_exists('achbotAdminWalletReportResult')) {
    function achbotAdminWalletReportResult(
        string $result, ?array $operation, int $updateId
    ): void {
        global $mainValues, $removeKeyboard, $from_id;
        if ($result === 'debt_required' && $operation !== null) {
            $token = achbotAdminWalletDebtCallback((int)$from_id,
                $operation['pending_step']);
            sendMessage(\achbotMessage('wallet.admin_wallet.admin_wallet_report_result.message.eb04c96492') .
                \achbotMessage('wallet.admin_wallet.admin_wallet_report_result.message.9573fb5f6d') . $operation['target'] . "\n" .
                \achbotMessage('wallet.admin_wallet.admin_wallet_report_result.message.ed76c199b7') . number_format($operation['amount']) . \achbotMessage('wallet.admin_wallet.admin_wallet_report_result.text.81a62ff6c6') .
                \achbotMessage('wallet.admin_wallet.admin_wallet_report_result.text.b2b0f14f77') . number_format($operation['before']) . \achbotMessage('wallet.admin_wallet.admin_wallet_report_result.text.81a62ff6c6') .
                \achbotMessage('wallet.admin_wallet.admin_wallet_report_result.text.1228d2c9db') . number_format($operation['after']) . \achbotMessage('wallet.admin_wallet.admin_wallet_report_result.text.81a62ff6c6') .
                \achbotMessage('wallet.admin_wallet.admin_wallet_report_result.text.bacd083a7f'),
                json_encode(['inline_keyboard' => [
                    [['text' => \achbotMessage('wallet.admin_wallet.admin_wallet_report_result.button.5b56135d41'),
                      'callback_data' => 'walletDebtYes:' . $token]],
                    [['text' => \achbotMessage('wallet.admin_wallet.admin_wallet_report_result.button.aad0953301'),
                      'callback_data' => 'walletDebtNo:' . $token]]
                ]], JSON_UNESCAPED_UNICODE));
        } elseif (($result === 'applied' || $result === 'already_applied') &&
            $operation !== null) {
            // Never re-read users.wallet after COMMIT: another operation may
            // already have changed it. Reconciled outcomes use journal facts.
            $verb = $operation['kind'] === 'credit' ? \achbotMessage('wallet.admin_wallet.admin_wallet_report_result.text.82c078bc71') : \achbotMessage('wallet.admin_wallet.admin_wallet_report_result.text.e818e354c3');
            $customer = \achbotMessage('wallet.admin_wallet.admin_wallet_report_result.customer.a5b15e454e') . number_format($operation['amount']) .
                \achbotMessage('wallet.admin_wallet.admin_wallet_report_result.text.bfb7221456') . ($operation['kind'] === 'credit' ?
                    \achbotMessage('wallet.admin_wallet.admin_wallet_report_result.text.cac43b05c7') : \achbotMessage('wallet.admin_wallet.admin_wallet_report_result.text.587ace91f5')) .
                \achbotMessage('wallet.admin_wallet.admin_wallet_report_result.text.25f7017bbb') .
                number_format($operation['after']) . \achbotMessage('wallet.admin_wallet.admin_wallet_report_result.text.2cc98f7f7c');
            $notices = [
                ['operator_confirmation', [\achbotMessage('wallet.admin_wallet.admin_wallet_report_result.customer.a5b15e454e') .
                    number_format($operation['amount']) . \achbotMessage('wallet.admin_wallet.admin_wallet_report_result.text.bfb7221456') . $verb .
                    \achbotMessage('wallet.admin_wallet.admin_wallet_report_result.text.a3e148be80') . $operation['target'] .
                    \achbotMessage('wallet.admin_wallet.admin_wallet_report_result.text.dff0fbb433') . number_format($operation['before']) .
                    \achbotMessage('wallet.admin_wallet.admin_wallet_report_result.text.a3e128be40') . number_format($operation['after']) .
                    \achbotMessage('wallet.admin_wallet.admin_wallet_report_result.text.4a74d658e8') . $operation['correlation'], $removeKeyboard]],
                ['operator_menu', [$mainValues['reached_main_menu'], getMainKeys(), 'HTML']],
                ['target_notice', [$customer, null, null, $operation['target']]]
            ];
            foreach ($notices as [$stage, $args]) {
                try {
                    $delivery = sendMessage(...$args);
                    if (!is_object($delivery) || ($delivery->ok ?? false) !== true) {
                        error_log('achbot_admin_wallet_notification_deferred update_id=' .
                            $updateId . ' stage=' . $stage);
                    }
                } catch (Throwable $error) {
                    error_log('achbot_admin_wallet_notification_deferred update_id=' .
                        $updateId . ' stage=' . $stage);
                }
            }
        } elseif ($result === 'balance_changed') {
            sendMessage(\achbotMessage('wallet.admin_wallet.admin_wallet_report_result.message.b996aa6de3') .
                \achbotMessage('wallet.admin_wallet.admin_wallet_report_result.message.8e316dfa3e'),
                $removeKeyboard);
        } elseif ($result === 'stale') {
            sendMessage(\achbotMessage('wallet.admin_wallet.admin_wallet_report_result.message.ce5d096603'));
        } elseif ($result === 'invalid') {
            sendMessage(\achbotMessage('wallet.admin_wallet.admin_wallet_report_result.message.c3cca2f742'));
        } else {
            sendMessage(\achbotMessage('wallet.admin_wallet.admin_wallet_report_result.message.472c52debf') .
                $updateId . \achbotMessage('wallet.admin_wallet.admin_wallet_report_result.message.1601e8a95b'));
        }
    }
}

$achbotWalletCanAdmin = ($from_id == $admin || ($userInfo['isAdmin'] ?? false) == true);
$achbotWalletModes = [
    'increaseUserWallet' => ['credit', 'increaseWalletUser', 'enter_increase_amount'],
    'decreaseUserWallet' => ['debit', 'decreaseWalletUser', 'enter_decrease_amount'],
    // Old inline buttons restart the safe Decrease flow, never debt consent.
    'createUserWalletDebt' => ['debit', 'decreaseWalletUser', 'enter_decrease_amount']
];
$achbotWalletStep = (string)($userInfo['step'] ?? 'none');
$achbotWalletInput = trim((string)($text ?? ''));
$achbotWalletCancel = (string)($buttonValues['cancel'] ?? '');
// All stages of a privileged adjustment are private DM-only. The binding
// helper also rejects a genuine message from an unrelated group chat.
$achbotWalletFreshText = achbotAdminWalletFreshReason(
    $update ?? null, $from_id ?? null, $chat_id ?? null,
    $message_id ?? null, $text ?? null);
if ($achbotWalletCanAdmin && isset($achbotWalletModes[$data]) &&
    achbotAdminWalletPrivateCallback($update ?? null, $from_id ?? null,
        $chat_id ?? null, $message_id ?? null, $data)) {
    if (!achbotWalletAdjustmentsReady($connection)) {
        sendMessage(\achbotMessage('wallet.admin_wallet.admin_wallet_report_result.message.7f5443f0df'));
    } else {
        delMessage();
        sendMessage($mainValues['send_user_id'], $cancelKey);
        setUser($data === 'createUserWalletDebt' ? 'decreaseUserWallet' : $data);
    }
}

if ($achbotWalletCanAdmin && isset($achbotWalletModes[$achbotWalletStep]) &&
    $achbotWalletFreshText !== null &&
    $achbotWalletInput !== $achbotWalletCancel && $achbotWalletInput !== '') {
    if (!preg_match('/^[1-9][0-9]{0,18}$/D', $achbotWalletInput) ||
        filter_var($achbotWalletInput, FILTER_VALIDATE_INT) === false) {
        sendMessage($mainValues['send_only_number']);
    } else {
        $achbotWalletTarget = (int)$achbotWalletInput;
        $stmt = $connection->prepare('SELECT userid FROM users WHERE userid=?');
        $stmt->bind_param('i', $achbotWalletTarget);
        $stmt->execute();
        $matches = $stmt->get_result()->num_rows;
        $stmt->close();
        if ($matches !== 1) {
            setUser();
            sendMessage($matches > 1 ?
                \achbotMessage('wallet.admin_wallet.admin_wallet_report_result.message.840dca2362') :
                $mainValues['user_not_found'], $removeKeyboard);
            sendMessage($mainValues['reached_main_menu'], getMainKeys(), 'HTML');
        } else {
            [$kind, $prefix, $prompt] = $achbotWalletModes[$achbotWalletStep];
            setUser($prefix . $achbotWalletTarget);
            sendMessage($mainValues[$prompt]);
        }
    }
}

if ($achbotWalletCanAdmin && $achbotWalletFreshText !== null &&
    $achbotWalletInput !== $achbotWalletCancel &&
    preg_match('/^(increaseWalletUser|decreaseWalletUser|debtWalletUser)([1-9][0-9]{0,18})$/D',
        $achbotWalletStep, $match)) {
    $kind = ['increaseWalletUser'=>'credit', 'decreaseWalletUser'=>'debit',
             'debtWalletUser'=>'debit'][$match[1]];
    if (!preg_match('/^[1-9][0-9]{0,9}$/D', $achbotWalletInput) ||
        (float)$achbotWalletInput > 2147483647) {
        sendMessage(\achbotMessage('wallet.admin_wallet.admin_wallet_report_result.message.5022bf056a'));
    } else {
        $target = (int)$match[2];
        $amount = (int)$achbotWalletInput;
        // Bind the final message to the actual amount-submission update.
        // Older/out-of-order private messages cannot provide its reason.
        setUser('achbotWalletReason:' . $kind . ':' . $target . ':' . $amount
            . ':' . $update->message->message_id . ':' . $update->update_id);
        sendMessage(\achbotMessage('wallet.admin_wallet.admin_wallet_report_result.message.b890d4f5e3')
            . \achbotMessage('wallet.admin_wallet.admin_wallet_report_result.message.e8ac1326af')
            . ($kind === 'debt' ? \achbotMessage('wallet.admin_wallet.admin_wallet_report_result.message.ff1e51077f') : ''));
    }
}

if ($achbotWalletCanAdmin && $achbotWalletFreshText !== null &&
    $achbotWalletInput !== $achbotWalletCancel &&
    preg_match('/^achbotWalletReason:(credit|debit|debt):([1-9][0-9]{0,18}):([1-9][0-9]{0,9}):([1-9][0-9]{0,18}):([1-9][0-9]{0,18})$/D',
        $achbotWalletStep, $match) &&
    $update->message->message_id > (int)$match[4] &&
    $update->update_id > (int)$match[5]) {
    $reason = $achbotWalletFreshText;
    if ($reason === '' || strlen($reason) > 500) {
        sendMessage(\achbotMessage('wallet.admin_wallet.admin_wallet_report_result.message.a4b902d78b'));
    } else {
        $updateId = filter_var($update->update_id ?? null, FILTER_VALIDATE_INT);
        $message = filter_var($message_id ?? null, FILTER_VALIDATE_INT);
        $chat = filter_var($chat_id ?? null, FILTER_VALIDATE_INT);
        // Pre-upgrade saved debt reasons carry no current consent.
        // Treat them as Decrease and require a fresh negative preview.
        $result = achbotApplyAdminWalletAdjustment(
            $connection, (int)$from_id, (int)$match[2], (int)($updateId ?: 0),
            (int)($chat ?: 0), (int)($message ?: 0),
            $match[1] === 'debt' ? 'debit' : $match[1],
            (int)$match[3], $reason, $achbotWalletStep, (int)$admin, $operation
        );
        achbotAdminWalletReportResult($result, $operation, (int)($updateId ?: 0));
    }
}


if ($achbotWalletCanAdmin &&
    preg_match('/^walletDebt(Yes|No):([a-f0-9]{32})$/D', (string)$data, $consent) &&
    achbotAdminWalletPrivateCallback($update ?? null, $from_id ?? null,
        $chat_id ?? null, $message_id ?? null, $data)) {
    if (!preg_match('/^achbotWalletDebt:([1-9][0-9]{0,18}):([1-9][0-9]{0,9}):(-?[0-9]{1,10}):([1-9][0-9]{0,18}):([1-9][0-9]{0,18}):([a-f0-9]{32}):([A-Za-z0-9+\/=]+)$/D',
            $achbotWalletStep, $pending) ||
        !hash_equals(achbotAdminWalletDebtCallback((int)$from_id, $achbotWalletStep),
            $consent[2]) ||
        $update->update_id <= (int)$pending[5] ||
        $message_id <= (int)$pending[4]) {
        achbotAdminWalletReportResult('stale', null, (int)$update->update_id);
    } elseif ($consent[1] === 'No') {
        $cancelResult = achbotCancelAdminWalletPendingStep($connection,
            (int)$from_id, $achbotWalletStep, (int)$admin);
        if ($cancelResult === 'canceled') {
            sendMessage(\achbotMessage('wallet.admin_wallet.admin_wallet_report_result.message.2f08aabfdd'),
                $removeKeyboard);
        } else {
            achbotAdminWalletReportResult($cancelResult, null, (int)$update->update_id);
        }
    } else {
        $reason = base64_decode($pending[7], true);
        $operation = null;
        $result = $reason === false ? 'invalid' : achbotApplyAdminWalletAdjustment(
            $connection, (int)$from_id, (int)$pending[1], (int)$update->update_id,
            (int)$chat_id, (int)$message_id, 'debt', (int)$pending[2], $reason,
            $achbotWalletStep, (int)$admin, $operation, (int)$pending[3]);
        achbotAdminWalletReportResult($result, $operation, (int)$update->update_id);
    }
}
