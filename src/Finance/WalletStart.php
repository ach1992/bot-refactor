<?php
/** AChBot: wallet-top-up workflow and its atomic draft persistence boundary. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/PaymentLifecycle.php';

/** All transient stages fit the existing users.step column; no migration. */
function achbotWalletTopupStage(string $kind, int $updateId, int $messageId = 0): string {
    // Timestamp handles Telegram's random update_id reset after >=7 idle days.
    return 'achbotWalletTopup:' . $kind . ':' . $updateId . ':' . $messageId . ':' . time();
}

/**
 * Compare-and-swap a single unambiguous user row under InnoDB row lock.
 * A menu callback may replace another workflow, but an older/replayed wallet
 * event cannot supersede an existing wallet event with a higher update_id.
 * For subsequent transitions the exact previously persisted stage is required.
 */
function achbotWalletTopupAdvance(mysqli $db, int $actorId, ?string $expected,
                                  string $next, int $eventUpdateId): string {
    if ($actorId <= 0 || $eventUpdateId <= 0 || strlen($next) > 1000) {
        return 'invalid';
    }
    $started = false;
    try {
        if (!$db->begin_transaction()) throw new RuntimeException('topup_begin_failed');
        $started = true;
        $stmt = $db->prepare('SELECT id,step FROM users WHERE userid=? FOR UPDATE');
        if (!$stmt) throw new RuntimeException('topup_user_lookup_unavailable');
        $stmt->bind_param('i', $actorId);
        if (!$stmt->execute()) throw new RuntimeException('topup_user_lookup_failed');
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if (count($rows) !== 1) {
            $db->rollback();
            return 'stale';
        }
        $row = $rows[0];
        $stored = (string)$row['step'];
        if ($expected !== null && $stored !== $expected) {
            $db->rollback();
            return 'stale';
        }
        if ($expected === null && preg_match(
            '/\AachbotWalletTopup:(?:opening|choose|prompting|amount|done):([1-9][0-9]*):[0-9]+:([1-9][0-9]*)\z/D',
            $stored, $match
        )) {
            $age = time() - (int)$match[2];
            // Within an active update sequence, reject older callbacks.
            // After a week idle, Telegram may reset update_id randomly.
            if ($eventUpdateId === (int)$match[1] ||
                ($eventUpdateId < (int)$match[1] && $age < 7 * 86400)) {
                $db->rollback();
                return 'stale';
            }
        }
        $stmt = $db->prepare('UPDATE users SET step=? WHERE id=? AND step=?');
        if (!$stmt) throw new RuntimeException('topup_stage_update_unavailable');
        $id = (int)$row['id'];
        $stmt->bind_param('sis', $next, $id, $stored);
        if (!$stmt->execute()) throw new RuntimeException('topup_stage_update_failed');
        $updated = $stmt->affected_rows;
        $stmt->close();
        if ($updated !== 1) throw new RuntimeException('topup_stage_claim_failed');
        if (!$db->commit()) throw new RuntimeException('topup_stage_commit_unknown');
        $started = false;
        return 'applied';
    } catch (Throwable $failure) {
        if ($started) {
            try { $db->rollback(); } catch (Throwable $ignored) {}
        }
        error_log('achbot_wallet_topup_stage_review update_id=' . $eventUpdateId);
        return 'review';
    }
}

/**
 * Exactly one authorized amount submission creates a draft. Keep the user
 * stage claim, unclaimed-draft cleanup and INSERT inside ONE transaction.
 * Telegram messages are sent only after a verified COMMIT, never inside locks.
 */
function startWalletPayment(mysqli $connection, $botState, $buttonValues, $botUrl,
                            $from_id, $removeKeyboard, $amount,
                            string $expectedStep, int $updateId, int $sourceMessageId): string {
    $actor = (int)$from_id;
    if (!is_int($amount) || $amount < 100000 || $amount > 10000000 ||
        $amount % 50000 !== 0 || $actor <= 0 || $updateId <= 0 ||
        $sourceMessageId <= 0 ||
        !preg_match('/\AachbotWalletTopup:(?:choose|amount):[1-9][0-9]*:[1-9][0-9]*:[1-9][0-9]*\z/D',
                    $expectedStep)) {
        return 'invalid';
    }
    $started = false;
    try {
        if (!$connection->begin_transaction()) throw new RuntimeException('topup_begin_failed');
        $started = true;
        $stmt = $connection->prepare('SELECT id,step FROM users WHERE userid=? FOR UPDATE');
        if (!$stmt) throw new RuntimeException('topup_user_lookup_unavailable');
        $stmt->bind_param('i', $actor);
        if (!$stmt->execute()) throw new RuntimeException('topup_user_lookup_failed');
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        // users.userid has a non-unique historical index. Refuse ambiguity.
        if (count($rows) !== 1 || (string)$rows[0]['step'] !== $expectedStep) {
            $connection->rollback();
            return 'stale';
        }
        $id = (int)$rows[0]['id'];
        $done = achbotWalletTopupStage('done', $updateId, $sourceMessageId);
        $stmt = $connection->prepare('UPDATE users SET step=? WHERE id=? AND step=?');
        if (!$stmt) throw new RuntimeException('topup_claim_unavailable');
        $stmt->bind_param('sis', $done, $id, $expectedStep);
        if (!$stmt->execute()) throw new RuntimeException('topup_claim_failed');
        $claimed = $stmt->affected_rows;
        $stmt->close();
        if ($claimed !== 1) throw new RuntimeException('topup_claim_unconfirmed');

        $hash_id = RandomString();
        achbotRemoveUnclaimedPendingPayments($connection, $actor, 'INCREASE_WALLET');
        $time = time();
        $stmt = $connection->prepare("INSERT INTO pays
            (hash_id,user_id,type,plan_id,volume,day,price,request_date,state)
            VALUES (?,?,'INCREASE_WALLET','0','0','0',?,?,'pending')");
        if (!$stmt) throw new RuntimeException('topup_insert_unavailable');
        $stmt->bind_param('siii', $hash_id, $actor, $amount, $time);
        if (!$stmt->execute()) throw new RuntimeException('topup_insert_failed');
        if ($stmt->affected_rows !== 1) throw new RuntimeException('topup_insert_unconfirmed');
        $stmt->close();
        if (!$connection->commit()) throw new RuntimeException('topup_commit_unknown');
        $started = false;
    } catch (Throwable $failure) {
        if ($started) {
            try { $connection->rollback(); } catch (Throwable $ignored) {}
        }
        // COMMIT acknowledgment can be lost; never retry a potentially
        // successful draft replacement on an ambiguous outcome.
        error_log('achbot_wallet_topup_creation_review update_id=' . $updateId);
        return 'review';
    }

    $keyboard = [];
    if (achbotManualEnabled('bank_transfer') && achbotPayUxAudienceAllowed($connection,'bank_transfer',(int)$actor))
        $keyboard[] = [['text' => $buttonValues['cart_to_cart'], 'callback_data' => "payIncreaseWalletWithCartToCart$hash_id"]];
    if (achbotManualEnabled('gift_card') && achbotPayUxAudienceAllowed($connection,'gift_card',(int)$actor))
        $keyboard[] = [['text' => $buttonValues['gift_card'], 'callback_data' => "payIncreaseWalletWithGiftCard$hash_id"]];
    if (achbotManualCryptoEnabled((int)$actor) && (achbotPayUxAudienceAllowed($connection,'manual_usdt',(int)$actor) || achbotPayUxAudienceAllowed($connection,'manual_trx',(int)$actor)))
        $keyboard[] = [['text' => '💎 پرداخت رمزارزی دستی', 'callback_data' => "mcm_$hash_id"]];
    if (($botState['nowPaymentWallet'] == 'on' && achbotPayUxAudienceAllowed($connection,'nowpayments',(int)$actor) && achbotUsdtRateForUser($connection,(int)$actor)!==null))
        $keyboard[] = [['text' => $buttonValues['now_payment_gateway'], 'url' => $botUrl . 'pay/?nowpayment&hash_id=' . $hash_id]];
    if (($botState['zarinpal'] == 'on' && achbotPayUxAudienceAllowed($connection,'zarinpal',(int)$actor)))
        $keyboard[] = [['text' => $buttonValues['zarinpal_gateway'], 'url' => $botUrl . 'pay/?zarinpal&hash_id=' . $hash_id]];
    if (($botState['nextpay'] == 'on' && achbotPayUxAudienceAllowed($connection,'nextpay',(int)$actor)))
        $keyboard[] = [['text' => $buttonValues['nextpay_gateway'], 'url' => $botUrl . 'pay/?nextpay&hash_id=' . $hash_id]];
    $keyboard[] = [['text' => $buttonValues['cancel'], 'callback_data' => 'mainMenu']];
    $keys = json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE);
    try {
        $reply = sendMessage("اطلاعات شارژ:\nمبلغ " . number_format($amount) .
            " تومان\n\nلطفا روش پرداخت را انتخاب کنید:", $keys);
        require_once __DIR__ . '/PaymentLifecycle.php';
        achbotPayUxTrackByHash($connection,$hash_id,$actor,$reply);
        if (!is_object($reply) || ($reply->ok ?? false) !== true) {
            error_log('achbot_wallet_topup_delivery_deferred update_id=' . $updateId);
        }
    } catch (Throwable $failure) {
        // The durable draft is already committed. Never create another one.
        error_log('achbot_wallet_topup_delivery_deferred update_id=' . $updateId);
    }
    return 'created';
}
