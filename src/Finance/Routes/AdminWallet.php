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

if (!function_exists('achbotApplyAdminWalletAdjustment')) {
    /**
     * Exactly one ledger row and wallet effect per authentic Telegram update.
     * Returns applied, already_applied, stale, invalid, unavailable, or review.
     */
    function achbotApplyAdminWalletAdjustment(
        mysqli $db, int $operatorId, int $targetId, int $updateId,
        int $chatId, int $messageId, string $kind, int $amount,
        string $reason, string $expectedStep, int $primaryAdmin
    ): string {
        if ($operatorId <= 0 || $targetId <= 0 || $updateId <= 0 ||
            $messageId <= 0 || $chatId === 0 ||
            !in_array($kind, ['credit', 'debit', 'debt'], true) ||
            $amount < 1 || $amount > 2147483647 ||
            $reason === '' || strlen($reason) > 500 ||
            !achbotWalletAdjustmentsReady($db)) {
            return 'unavailable';
        }
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
            if ($after < -2147483648 || $after > 2147483647 ||
                ($kind === 'debit' && $after < 0)) {
                $db->rollback();
                return 'invalid';
            }
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
            $stmt = $db->prepare("UPDATE users SET step='none'
                WHERE userid=? AND step=?");
            $stmt->bind_param('is', $operatorId, $expectedStep);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();
            if ($affected !== 1) {
                throw new RuntimeException('operator_claim_failed');
            }
            if (!$db->commit()) {
                throw new RuntimeException('journal_commit_unconfirmed');
            }
            return 'applied';
        } catch (Throwable $error) {
            try { $db->rollback(); } catch (Throwable $ignored) {}
            // COMMIT may have succeeded even if its acknowledgement was lost.
            // Reconcile only this unique immutable update identity; never
            // automatically redo an ambiguous balance effect.
            try {
                $stmt = $db->prepare("SELECT actor_userid,target_userid,
                    source_chat_id,source_message_id,reason,
                    adjustment_type,amount_toman FROM wallet_adjustments
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
            str_starts_with($step, 'achbotWalletReason:');
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

$achbotWalletCanAdmin = ($from_id == $admin || ($userInfo['isAdmin'] ?? false) == true);
$achbotWalletModes = [
    'increaseUserWallet' => ['credit', 'increaseWalletUser', 'enter_increase_amount'],
    'decreaseUserWallet' => ['debit', 'decreaseWalletUser', 'enter_decrease_amount'],
    'createUserWalletDebt' => ['debt', 'debtWalletUser', 'enter_debt_amount']
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
        sendMessage('⚠️ ثبت تغییر موجودی فعلاً آماده نیست؛ مدیر سیستم باید دفتر ثبت مالی را بررسی کند.');
    } else {
        delMessage();
        sendMessage($mainValues['send_user_id'], $cancelKey);
        setUser($data);
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
                '⚠️ شناسه کاربری چند رکورد دارد؛ قبل از تغییر موجودی بررسی کنید.' :
                $mainValues['user_not_found'], $removeKeyboard);
            sendMessage($mainValues['reached_main_menu'], getMainKeys());
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
             'debtWalletUser'=>'debt'][$match[1]];
    if (!preg_match('/^[1-9][0-9]{0,9}$/D', $achbotWalletInput) ||
        (float)$achbotWalletInput > 2147483647) {
        sendMessage('⚠️ فقط مبلغ صحیح، مثبت و بدون اعشار تا سقف مجاز وارد کنید.');
    } else {
        $target = (int)$match[2];
        $amount = (int)$achbotWalletInput;
        // Bind the final message to the actual amount-submission update.
        // Older/out-of-order private messages cannot provide its reason.
        setUser('achbotWalletReason:' . $kind . ':' . $target . ':' . $amount
            . ':' . $update->message->message_id . ':' . $update->update_id);
        sendMessage('📝 دلیل این تغییر موجودی را در یک پیام جداگانه وارد کنید. '
            . 'تغییر فقط پس از ثبت دلیل اعمال می‌شود.'
            . ($kind === 'debt' ? ' این کسر مجاز است موجودی را منفی کند.' : ''));
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
        sendMessage('⚠️ دلیل باید غیرخالی و حداکثر ۵۰۰ بایت باشد. لطفاً دوباره وارد کنید.');
    } else {
        $updateId = filter_var($update->update_id ?? null, FILTER_VALIDATE_INT);
        $message = filter_var($message_id ?? null, FILTER_VALIDATE_INT);
        $chat = filter_var($chat_id ?? null, FILTER_VALIDATE_INT);
        $result = achbotApplyAdminWalletAdjustment(
            $connection, (int)$from_id, (int)$match[2], (int)($updateId ?: 0),
            (int)($chat ?: 0), (int)($message ?: 0), $match[1],
            (int)$match[3], $reason, $achbotWalletStep, (int)$admin
        );
        if ($result === 'applied' || $result === 'already_applied') {
            $verb = $match[1] === 'credit' ? 'افزوده' :
                'کسر';
            $notices = [
                ['operator_confirmation', ['✅ مبلغ ' . number_format((int)$match[3]) .
                    ' تومان ' . $verb . ' شد. شناسه پیگیری: ' . (int)$updateId,
                    $removeKeyboard]],
                ['operator_menu', [$mainValues['reached_main_menu'], getMainKeys()]],
                ['target_notice', ['اطلاعیه: موجودی کیف پول شما توسط مدیر تغییر کرد.',
                    null, null, (int)$match[2]]]
            ];
            foreach ($notices as [$stage, $args]) {
                try {
                    $delivery = sendMessage(...$args);
                    if (!is_object($delivery) || ($delivery->ok ?? false) !== true) {
                        error_log('achbot_admin_wallet_notification_deferred update_id=' .
                            (int)$updateId . ' stage=' . $stage);
                    }
                } catch (Throwable $error) {
                    error_log('achbot_admin_wallet_notification_deferred update_id=' .
                        (int)$updateId . ' stage=' . $stage);
                }
            }
        } elseif ($result === 'stale') {
            sendMessage('⚠️ این درخواست قبلاً پردازش یا لغو شده است؛ وضعیت کیف پول را بررسی کنید.');
        } elseif ($result === 'invalid') {
            sendMessage('⚠️ موجودی، مبلغ یا دسترسی معتبر نیست. تغییری اعمال نشد.');
        } else {
            sendMessage('⚠️ نتیجه عملیات مشخص نیست؛ تا بررسی شناسه پیگیری ' .
                (int)($updateId ?: 0) . ' آن را تکرار نکنید.');
        }
    }
}
