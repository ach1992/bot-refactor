<?php
/** AChBot wallet transfer: fresh consent, atomic balances and journal legs. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../../messages/loader.php'; // ACHBOT_TEXT_CATALOG


require_once dirname(__DIR__) . '/WalletTransferFlow.php';

if (!function_exists('achbotWalletTransferRestart')) {
function achbotWalletTransferRestart(bool $unavailable = false): void
{
    sendMessage($unavailable
        ? \achbotMessage('wallet.wallet_transfer.wallet_transfer_restart.message.1286cd29ed')
        : \achbotMessage('wallet.wallet_transfer.wallet_transfer_restart.message.03d3c27bc2'),
        json_encode(['inline_keyboard'=>[[[
            'text'=>\achbotMessage('wallet.wallet_transfer.wallet_transfer_restart.button.79f89c9b92'), 'callback_data'=>'transferMyWallet'
        ]]]], JSON_UNESCAPED_UNICODE), 'HTML');
}

}

if ($data === 'transferMyWallet') {
    $start=achbotWalletTransferSource($update ?? null,$from_id ?? null,
        $chat_id ?? null,$message_id ?? null,$data,'callback');
    if ($start===null) { achbotWalletTransferRestart(); return; }
    $status=achbotWalletTransferAdvance($connection,$start,'start');
    if ($status==='ready') {
        delMessage();
        sendMessage(\achbotMessage('wallet.wallet_transfer.wallet_transfer_restart.message.60862c92c3'),$cancelKey, 'HTML');
    } elseif ($status==='insufficient') {
        alert(\achbotMessage('wallet.wallet_transfer.wallet_transfer_restart.alert.e05ab0cee3'));
    } else achbotWalletTransferRestart($status==='review');
    return;
}

$transferStep=(string)$userInfo['step'];
$pendingTransfer=achbotWalletTransferPending($transferStep);
if ($text != \achbotMessage('legacy_buttons.cancel') &&
    (($pendingTransfer['stage'] ?? null)==='recipient')) {
    $selection=achbotWalletTransferSource($update ?? null,$from_id ?? null,
        $chat_id ?? null,$message_id ?? null,$text,'message');
    if ($selection===null ||
        $selection['update_id']<=$pendingTransfer['start_update_id'] ||
        $selection['message_id']<=$pendingTransfer['start_message_floor']) {
        achbotWalletTransferRestart(); return;
    }
    if (preg_match('/^[1-9][0-9]*$/D',(string)$text)!==1 ||
        strlen((string)$text)>19 || (string)(int)$text!==(string)$text) {
        sendMessage(\achbotMessage('legacy_messages.send_only_number'), null, 'HTML'); return;
    }
    if ((int)$text===(int)$from_id) {
        sendMessage(\achbotMessage('wallet.wallet_transfer.wallet_transfer_restart.message.9d368ab3bb'), null, 'HTML'); return;
    }
    $status=achbotWalletTransferAdvance($connection,$selection,'select',(int)$text,$transferStep);
    if ($status==='ready')
        sendMessage(\achbotMessage('wallet.wallet_transfer.wallet_transfer_restart.message.8bd13292ba'), null, 'HTML');
    elseif ($status==='missing')
        sendMessage(\achbotMessage('wallet.wallet_transfer.wallet_transfer_restart.message.71963e9012'), null, 'HTML');
    else achbotWalletTransferRestart($status==='review');
    return;
}

if ((str_starts_with($transferStep,'achbotTransferAmount:') ||
     str_starts_with($transferStep,'achbotTransferRecipient:') ||
     str_starts_with($transferStep,'tranfserUserAmount') ||
     $transferStep==='transferMyWallet') && $text != \achbotMessage('legacy_buttons.cancel')) {
    if (($pendingTransfer['stage'] ?? null)!=='amount') {
        // All unversioned/old generations require an authenticated fresh start.
        achbotWalletTransferRestart(); exit;
    }

    if (!preg_match('/^[0-9]+$/D', (string)$text)) {
        sendMessage(\achbotMessage('legacy_messages.send_only_number'), null, 'HTML');
        exit;
    }

    $digits = ltrim((string)$text, '0');
    if ($digits === '' || strlen($digits) > 10 ||
        (strlen($digits) === 10 && strcmp($digits, '2147483647') > 0)) {
        sendMessage(\achbotMessage('wallet.wallet_transfer.wallet_transfer_restart.message.dcff0b471b'), null, 'HTML');
        exit;
    }
    $amount = (int)$digits;
    $to_id = $pendingTransfer['recipient_id'];

    if ($amount <= 0) {
        sendMessage(\achbotMessage('wallet.wallet_transfer.wallet_transfer_restart.message.a04cf98a41'), null, 'HTML');
        exit;
    }

    if ($to_id === (int)$from_id) {
        sendMessage(\achbotMessage('wallet.wallet_transfer.wallet_transfer_restart.message.9d368ab3bb'), null, 'HTML');
        exit;
    }

    $amountSource = achbotWalletTransferSource($update ?? null, $from_id ?? null,
        $chat_id ?? null, $message_id ?? null, $text, 'message');
    if ($amountSource === null ||
        $amountSource['update_id'] <= $pendingTransfer['selection_update_id'] ||
        $amountSource['message_id'] <= $pendingTransfer['selection_message_id']) {
        achbotWalletTransferRestart();
        exit;
    }
    $transferUpdateId = $amountSource['update_id'];
    $transferChatId = $amountSource['chat_id'];
    $transferMessageId = $amountSource['message_id'];

    $transactionActive = false;
    $commitStarted = false;
    try {
        if (!achbotWalletTransferFlowReady($connection)) {
            throw new RuntimeException('transfer_journal_unavailable');
        }
        if ($connection->begin_transaction() !== true) {
            throw new RuntimeException('transfer_begin_failed');
        }
        $transactionActive = true;

        // Stable lock order avoids inverse-direction transfer deadlocks.
        $userIds = [(int)$from_id, $to_id];
        sort($userIds, SORT_NUMERIC);
        $locked = [];
        foreach ($userIds as $userId) {
            // userid has only a NON-UNIQUE legacy index. Lock all matching
            // records, reject ambiguity and bind monetary writes to the PK.
            $stmt = $connection->prepare(
                "SELECT id,userid,name,username,wallet,step FROM users WHERE userid = ? FOR UPDATE"
            );
            $stmt->bind_param("i", $userId);
            if ($stmt->execute() !== true) {
                throw new RuntimeException('transfer_identity_lookup_failed');
            }
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            if (count($rows) > 1) {
                throw new RuntimeException($userId === (int)$from_id
                    ? 'transfer_sender_identity_ambiguous'
                    : 'transfer_recipient_identity_ambiguous');
            }
            $locked[$userId] = $rows[0] ?? null;
        }
        $sender = $locked[(int)$from_id] ?? null;
        $receiver = $locked[$to_id] ?? null;
        if (!$sender) {
            throw new RuntimeException('transfer_sender_missing');
        }
        if (!$receiver) {
            $connection->rollback();
            $transactionActive = false;
            sendMessage(\achbotMessage('wallet.wallet_transfer.wallet_transfer_restart.message.71963e9012'), null, 'HTML');
            exit;
        }

        // A Telegram transfer step is a one-use claim, verified under lock.
        $expectedStep = (string)$userInfo['step'];
        if ((string)$sender['step'] !== $expectedStep) {
            $connection->rollback();
            $transactionActive = false;
            sendMessage(\achbotMessage('wallet.wallet_transfer.wallet_transfer_restart.message.ecde76fc7f'), null, 'HTML');
            exit;
        }

        $transferCursor=achbotWalletTransferOwned($connection,$sender,$receiver,
            $expectedStep,$amountSource);

        $sender_before = (int)$sender['wallet'];
        $receiver_before = (int)$receiver['wallet'];
        if ($sender_before < $amount) {
            $connection->rollback();
            $transactionActive = false;
            sendMessage(\achbotMessage('wallet.wallet_transfer.wallet_transfer_restart.message.bf9e4dcf22'), null, 'HTML');
            exit;
        }
        // The original wallet is signed INT(11): incoming genuine credit may
        // reduce recipient debt but must never overflow the positive bound.
        if ($receiver_before > 2147483647 - $amount) {
            $connection->rollback();
            $transactionActive = false;
            sendMessage(\achbotMessage('wallet.wallet_transfer.wallet_transfer_restart.message.8fb7125cfb'), null, 'HTML');
            exit;
        }

        // Both users are already locked in stable userid order. The two
        // shared ledger entries, original 005 evidence and step/cursor claim
        // commit together; a replay or either-leg failure rolls back all.
        $senderPk=(int)$sender['id'];
        $senderUserId=(int)$from_id;
        $sender_after=$sender_before-$amount;
        $receiver_after=$receiver_before+$amount;
        $credit=achbotWalletApplyLocked($connection,$to_id,'transfer',
            (string)$transferUpdateId,2,$amount,$senderUserId);
        $debit=achbotWalletApplyLocked($connection,$senderUserId,'transfer',
            (string)$transferUpdateId,1,-$amount,$senderUserId);
        if ($credit['replayed'] || $debit['replayed'] ||
            $credit['before']!==$receiver_before || $credit['after']!==$receiver_after ||
            $debit['before']!==$sender_before || $debit['after']!==$sender_after)
            throw new RuntimeException('transfer_wallet_entry_conflict');

        $stmt=$connection->prepare("UPDATE users SET step='none'
            WHERE id=? AND userid=? AND step=?");
        $stmt->bind_param('iis',$senderPk,$senderUserId,$expectedStep);
        $claimed=$stmt->execute() && $stmt->affected_rows===1;
        $stmt->close();
        if (!$claimed) throw new RuntimeException('transfer_sender_debit_claim_missing');

        // Both immutable legs commit WITH balances and the consumed step.
        // A failure on either INSERT rolls all of them back.
        achbotInsertTelegramWalletAdjustment($connection, 'transfer',
            $transferUpdateId, 1, (int)$from_id, (int)$from_id,
            $transferChatId, $transferMessageId, 'transfer_out', $amount,
            $sender_before, $sender_after, 'wallet_transfer');
        achbotInsertTelegramWalletAdjustment($connection, 'transfer',
            $transferUpdateId, 2, (int)$from_id, $to_id,
            $transferChatId, $transferMessageId, 'transfer_in', $amount,
            $receiver_before, $receiver_after, 'wallet_transfer');

        // Keep replay ownership after users.step is consumed, including
        // after later menu navigation/cancellation or a lost COMMIT reply.
        achbotWalletTransferComplete($connection,$transferCursor,$amountSource);
        require_once dirname(__DIR__).'/WalletReports.php';
        achbotWalletReportStageLocked($connection,$credit['id'],(int)$admin);

        $commitStarted = true;
        // A false or lost COMMIT acknowledgement is NOT evidence of rollback.
        if ($connection->commit() !== true) {
            throw new RuntimeException('transfer_commit_unconfirmed');
        }
        $transactionActive = false;
    } catch (Throwable $e) {
        $rollbackApi = 'not_called';
        $transactionOutcome = $commitStarted ? 'unknown' : 'not_started';
        if ($transactionActive) {
            try {
                $rollbackApi = $connection->rollback() === true
                    ? 'returned_success' : 'returned_failure';
            } catch (Throwable $rollbackError) {
                $rollbackApi = 'raised_exception';
            }
            // A successful rollback call AFTER an ambiguous COMMIT cannot
            // prove that the server never committed. Never label it rolled back.
            if (!$commitStarted) {
                $transactionOutcome = $rollbackApi === 'returned_success'
                    ? 'confirmed_rolled_back' : 'unknown';
            }
        }

        // Never expose SQL, panel, API or exception details to customers.
        // Use a short reference for a separate, access-controlled operator
        // report. Only known internal identifiers are allowed as reason codes.
        $reference = strtoupper(bin2hex(random_bytes(6)));
        $knownReasons = [
            'transfer_sender_missing',
            'transfer_sender_identity_ambiguous',
            'transfer_recipient_identity_ambiguous',
            'transfer_identity_lookup_failed',
            'transfer_begin_failed',
            'transfer_journal_unavailable',
            'transfer_state_ownership_mismatch',
            'transfer_state_ambiguous', 'transfer_state_missing', 'transfer_state_invalid',
            'transfer_state_read_unavailable', 'transfer_state_read_failed',
            'transfer_state_write_unavailable', 'transfer_state_write_failed',
            'wallet_journal_invalid_entry',
            'wallet_journal_insert_unavailable',
            'wallet_journal_insert_failed',
            'transfer_commit_unconfirmed',
            'transfer_recipient_credit_execution_failed',
            'transfer_sender_debit_execution_failed',
            'transfer_recipient_credit_missing',
            'transfer_sender_debit_claim_missing',
        ];
        $reason = in_array($e->getMessage(), $knownReasons, true)
            ? $e->getMessage() : 'transfer_database_or_runtime_error';
        $sqlstate = $e instanceof mysqli_sql_exception ? $e->getSqlState() : 'n/a';
        $adminReport = \achbotMessage('wallet.wallet_transfer.wallet_transfer_restart.a_ch_bot_wallet_transfer_failure.2e028e487d')
            . \achbotMessage('wallet.wallet_transfer.wallet_transfer_restart.reference_reference.f5ec5fabf8', ['reference' => $reference])
            . \achbotMessage('wallet.wallet_transfer.wallet_transfer_restart.telegram_update_transfer_update_id.9ccad7d18e', ['transferUpdateId' => $transferUpdateId])
            . \achbotMessage('wallet.wallet_transfer.wallet_transfer_restart.reason_reason.2ef4e18461', ['reason' => $reason])
            . \achbotMessage('wallet.wallet_transfer.wallet_transfer_restart.exception_class.bed555cfab') . htmlspecialchars(get_class($e), ENT_QUOTES, 'UTF-8') . "</code>\n"
            . \achbotMessage('wallet.wallet_transfer.wallet_transfer_restart.s_q_l_s_t_a_t_e.e54fa53e31') . htmlspecialchars((string)$sqlstate, ENT_QUOTES, 'UTF-8') . "</code>\n"
            . \achbotMessage('wallet.wallet_transfer.wallet_transfer_restart.commit_attempted.48f79f5883') . ($commitStarted ? 'yes' : 'no') . "</code>\n"
            . \achbotMessage('wallet.wallet_transfer.wallet_transfer_restart.transaction_outcome_transaction_outcome.e7b94c824a', ['transactionOutcome' => $transactionOutcome])
            . \achbotMessage('wallet.wallet_transfer.wallet_transfer_restart.rollback__a_p_i_rollback_api_not_proof.d4dbeb853e', ['rollbackApi' => $rollbackApi])
            . \achbotMessage('wallet.wallet_transfer.wallet_transfer_restart.sender.3d56306cdd') . (int)$from_id . "</code>\n"
            . \achbotMessage('wallet.wallet_transfer.wallet_transfer_restart.recipient_to_id.5b5f69f5c8', ['to_id' => $to_id])
            . \achbotMessage('wallet.wallet_transfer.wallet_transfer_restart.amount__toman_amount.615f9f4c22', ['amount' => $amount]);
        $reported = false;
        if (isset($admin) && (int)$admin > 0) {
            try {
                $notices=achbotNotifyAdmins('sendMessage', ['text'=>$adminReport,'parse_mode'=>'HTML'], ['report_channel'=>null]);
                $reply=$notices[0]['response']??null;
                $reported = is_object($reply) && isset($reply->ok) && $reply->ok === true;
            } catch (Throwable $notifyError) {
                // A missing Telegram notification must not hide the failure.
            }
        }
        if (!$reported) {
            error_log("ACHBOT_WALLET_TRANSFER_FAILURE reference={$reference} "
                . "update_id={$transferUpdateId} reason={$reason} sqlstate={$sqlstate} "
                . "commit_attempted=" . ($commitStarted ? 'yes' : 'no')
                . " outcome={$transactionOutcome} rollback_api={$rollbackApi}");
        }
        sendMessage(\achbotMessage('wallet.wallet_transfer.wallet_transfer_restart.message.6924ce253f')
            . \achbotMessage('wallet.wallet_transfer.wallet_transfer_restart.message.f933fbcd9d')
            . \achbotMessage('wallet.wallet_transfer.wallet_transfer_restart.message.e9940862a9', ['reference' => $reference]));
        exit;
    }

    // All notifications happen strictly AFTER the wallet transaction commits.
    // A failed Telegram request cannot roll back a completed banked transfer.
    $deliveryProblems = [];
    $sendTransferNotice = static function ($message, $keyboard, $parse, $recipient, $role)
        use (&$deliveryProblems) {
        try {
            $result = sendMessage($message, $keyboard, $parse, $recipient);
            if (!is_object($result) || !isset($result->ok) || $result->ok !== true) {
                $deliveryProblems[] = $role;
            }
        } catch (Throwable $noticeError) {
            $deliveryProblems[] = $role;
        }
    };

        // پیام برای مقصد
        $sendTransferNotice(
            \achbotMessage('wallet.admin_wallet.admin_wallet_report_result.customer.a5b15e454e') . number_format($amount) . \achbotMessage('wallet.wallet_transfer.wallet_transfer_restart.text.ecbce64eed', ['from_id' => $from_id]),
            null, null, $to_id, 'recipient'
        );

        // پیام برای مبدأ؛ قدم قبلاً در همان تراکنش مصرف شده است.
        $sendTransferNotice(\achbotMessage('wallet.admin_wallet.admin_wallet_report_result.customer.a5b15e454e') . number_format($amount) .
            \achbotMessage('wallet.wallet_transfer.wallet_transfer_restart.text.8e5e1ee663'),
            $removeKeyboard, null, $from_id, 'sender');
        $sendTransferNotice(\achbotMessage('wallet.wallet_transfer.wallet_transfer_restart.text.b059640ccc'),
            getMainKeys(), null, $from_id, 'sender_menu');

    // A committed transfer is never undone because an outbound Telegram
    // notification failed. Surface precise destination-level diagnostics
    // to the private operator, not to the customer or public report channel.
    if ($deliveryProblems !== []) {
        $noticeReference = strtoupper(bin2hex(random_bytes(6)));
        $failedRoles = implode(',', array_unique($deliveryProblems));
        $notice = \achbotMessage('wallet.wallet_transfer.wallet_transfer_restart.a_ch_bot_wallet_transfer_notification_failure.9a285d7145')
            . \achbotMessage('wallet.wallet_transfer.wallet_transfer_restart.reference_notice_reference.f81164ea0b', ['noticeReference' => $noticeReference])
            . \achbotMessage('wallet.wallet_transfer.wallet_transfer_restart.reason_reason_code.a18553cad8', ['reason_code' => 'transfer_notification_unconfirmed'])
            . \achbotMessage('wallet.wallet_transfer.wallet_transfer_restart.failed_roles_failed_roles.dbaa3d3e0e', ['failedRoles' => $failedRoles])
            . \achbotMessage('wallet.wallet_transfer.wallet_transfer_restart.transfer_already_committed_status_code.abb8981186', ['status_code' => 'yes'])
            . \achbotMessage('wallet.wallet_transfer.wallet_transfer_restart.sender.3d56306cdd') . (int)$from_id . "</code>\n"
            . \achbotMessage('wallet.wallet_transfer.wallet_transfer_restart.recipient_to_id.5b5f69f5c8', ['to_id' => $to_id])
            . \achbotMessage('wallet.wallet_transfer.wallet_transfer_restart.amount__toman_amount.615f9f4c22', ['amount' => $amount]);
        $operatorNotified = false;
        if (!in_array('admin', $deliveryProblems, true) && isset($admin) && (int)$admin > 0) {
            try {
                $notices=achbotNotifyAdmins('sendMessage', ['text'=>$notice,'parse_mode'=>'HTML'], ['report_channel'=>null]);
                $reply=$notices[0]['response']??null;
                $operatorNotified = is_object($reply) && isset($reply->ok) && $reply->ok === true;
            } catch (Throwable $noticeError) {
                // Non-financial notification failure must not invalidate the payment.
            }
        }
        if (!$operatorNotified) {
            error_log("ACHBOT_WALLET_TRANSFER_NOTIFICATION reference={$noticeReference} "
                . "roles={$failedRoles} committed=yes");
        }
    }
}

