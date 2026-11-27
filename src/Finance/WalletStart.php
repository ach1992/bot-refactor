<?php
/** AChBot: wallet-top-up workflow and its atomic draft persistence boundary. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG


require_once __DIR__ . '/PaymentLifecycle.php';
require_once __DIR__.'/../Telegram/Conversation.php';

/** All transient stages fit the existing users.step column; no migration. */
function achbotWalletTopupStage(string $kind, int $updateId, int $reference = 0): string {
    // Timestamp handles Telegram's random update_id reset after >=7 idle days.
    return 'achbotWalletTopup:' . $kind . ':' . $updateId . ':' . $reference . ':' . time();
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
        $stmt = $db->prepare('SELECT id,step,temp FROM users WHERE userid=? FOR UPDATE');
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
        if ($expected===null && !\AchBot\Telegram\Conversation\editorAcceptsSequence($row,$eventUpdateId,time())) {
            $db->rollback();return 'stale';
        }
        $stored = (string)$row['step'];
        if ($expected !== null && $stored !== $expected) {
            $db->rollback();
            return 'stale';
        }
        if ($expected === null && preg_match(
            '/\AachbotWalletTopup:(?:opening|choose|prompting|amount|committed):([1-9][0-9]*):[0-9]+:([1-9][0-9]*)\z/D',
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

/** Approved top-up amount policy, shared by validation and draft creation. */
function achbotWalletTopupAmountProblem($amount): ?string {
    if (!is_int($amount)) return \achbotMessage('wallet.wallet_recharge.wallet_topup_amount_problem.label.7fab54f38e');
    if ($amount < 100000) return \achbotMessage('wallet.wallet_recharge.wallet_topup_amount_problem.label.a5b8f4d84e');
    if ($amount > 10000000) return \achbotMessage('wallet.wallet_recharge.wallet_topup_amount_problem.label.2ee8d817fc');
    if ($amount % 50000 !== 0) return \achbotMessage('wallet.wallet_recharge.wallet_topup_amount_problem.label.9185f287a6');
    return null;
}

/**
 * Exactly one authorized amount submission creates a draft. Keep the user
 * stage claim, unclaimed-draft cleanup and INSERT inside ONE transaction.
 * Telegram messages are sent only after a verified COMMIT, never inside locks.
 */
function startWalletPayment(mysqli $connection, $botUrl,
                            $from_id, $amount,
                            string $expectedStep, int $updateId, int $sourceMessageId): string {
    $actor = (int)$from_id;
    if (achbotWalletTopupAmountProblem($amount)!==null || $actor <= 0 || $updateId <= 0 ||
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
        require_once __DIR__.'/CheckoutAdmission.php';
        achbotCheckoutRequireSchema($connection);
        $hash_id = RandomString();

        $time = time();
        $deadline=$time+achbotPayUxDraftDeadlineSeconds(achbotPayUxCurrentStatesLocked($connection)??[]);
        $stmt = $connection->prepare("INSERT INTO pays
            (hash_id,user_id,type,plan_id,volume,day,price,request_date,state,checkout_draft_context,draft_expires_at)
            VALUES (?,?,'INCREASE_WALLET','0','0','0',?,?,'pending',JSON_OBJECT('version',1,'topup_prompt',?),?)");
        if (!$stmt) throw new RuntimeException('topup_insert_unavailable');
        $promptId=(int)explode(':',$expectedStep)[3];
        $stmt->bind_param('siiiii', $hash_id, $actor, $amount, $time,$promptId,$deadline);
        if (!$stmt->execute()) throw new RuntimeException('topup_insert_failed');
        if ($stmt->affected_rows !== 1) throw new RuntimeException('topup_insert_unconfirmed');
        $draftId=(int)$stmt->insert_id;
        $stmt->close();
        $done = achbotWalletTopupStage('committed', $updateId, $draftId);
        $stmt = $connection->prepare('UPDATE users SET step=? WHERE id=? AND step=?');
        if (!$stmt) throw new RuntimeException('topup_claim_unavailable');
        $stmt->bind_param('sis', $done, $id, $expectedStep);
        if (!$stmt->execute()) throw new RuntimeException('topup_claim_failed');
        $claimed = $stmt->affected_rows;
        $stmt->close();
        if ($claimed !== 1) throw new RuntimeException('topup_claim_unconfirmed');

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

    return achbotWalletTopupPresentSelector($connection,$draftId,$actor,$botUrl);
}

/** A committed draft and its Telegram delivery are separate facts.
 * The original payment lease serializes presentation and explicit recovery.
 * No automatic resend after an ambiguous Telegram response. */
function achbotWalletTopupPresentSelector(mysqli $db,int $draftId,int $actor,string $botUrl): string {
    try {
        return achbotPayUxWithLease($db,$draftId,static function() use($db,$draftId,$actor,$botUrl): string {
            $pay=achbotProviderRow($db,"SELECT * FROM pays WHERE id=? AND user_id=? AND type='INCREASE_WALLET'",'ii',[$draftId,$actor]);
            if (!$pay || !achbotPayUxCurrentDraft($pay) || !achbotPayUxUnclaimedDraftUsable($db,$pay) ||
                !achbotPayUxDraftWithoutEvidence($db,$draftId,$actor)) return 'stale';
            $context=json_decode((string)$pay['checkout_draft_context'],true);
            $prompt=$context['topup_prompt']??null;
            if (!is_int($prompt) || $prompt<=0) return 'stale';
            $known=achbotProviderRow($db,"SELECT id FROM payment_message_refs
                WHERE pay_row_id=? AND chat_id=? AND purpose='selector' AND retired_reason IS NULL LIMIT 1",'ii',[$draftId,$actor]);
            if ($known) return 'created';
            require_once __DIR__.'/../Purchase/CheckoutPresentation.php';
            $amount=(int)$pay['price'];$hash=(string)$pay['hash_id'];
            $methods=array_keys(achbotCheckoutDraftPaymentOptions($db,$draftId,$actor));
            $keyboard=achbotCheckoutPaymentKeyboard('topup',$hash,$amount,$methods,$botUrl);
            $body=achbotMessageHtml('wallet.wallet_start.selector_amount',['amount'=>number_format($amount)])."\n\n".
                achbotMessage($keyboard?'payment.select_method':'payment.no_available_method');
            $keyboard[]=[['text'=>achbotMessage('legacy_buttons.cancel'),'callback_data'=>'dc_'.$hash]];
            $reply=sendMessage($body,json_encode(['inline_keyboard'=>$keyboard],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'HTML',$actor);
            $sentId=\AchBot\Telegram\Conversation\acknowledgedMessage($reply,$actor);
            if ($sentId===null || $sentId===$prompt || !achbotPayUxRegister($db,$draftId,$actor,$sentId,'selector')) {
                error_log('achbot_wallet_topup_delivery_unconfirmed pay_id='.$draftId);
                return 'delivery_pending';
            }
            // The exact stored bot prompt is now obsolete. Do not delete the
            // customer-authored amount or any retained financial evidence.
            try {delMessage($prompt,$actor);} catch (Throwable $ignored) {}
            return 'created';
        },'delivery_pending');
    } catch (Throwable $failure) {
        error_log('achbot_wallet_topup_delivery_deferred pay_id='.$draftId.' class='.get_class($failure));
        return 'delivery_pending';
    }
}

/** A deliberate fresh private click can recover only the same committed,
 * still-unissued draft. Known delivery allows an ordinary new menu entry.
 * Claim the fresh update before sending; a replay is not a retry. */
function achbotWalletTopupRecoverSelector(mysqli $db,int $actor,string $step,int $updateId,string $botUrl,?int $prompt=null,?int $amount=null): ?string {
    if (!preg_match('/\AachbotWalletTopup:committed:([1-9][0-9]*):([1-9][0-9]*):([1-9][0-9]*)\z/D',$step,$m))
        return null;
    $id=(int)$m[2];
    if ($updateId===(int)$m[1] || ($updateId<(int)$m[1] && time()-(int)$m[3]<7*86400)) return 'stale';
    try {
        return achbotPayUxWithLease($db,$id,static function() use($db,$actor,$step,$updateId,$botUrl,$prompt,$amount,$id): ?string {
            $pay=achbotProviderRow($db,"SELECT * FROM pays WHERE id=? AND user_id=? AND type='INCREASE_WALLET'",'ii',[$id,$actor]);
            if (!$pay || !achbotPayUxCurrentDraft($pay) || !achbotPayUxUnclaimedDraftUsable($db,$pay) ||
                !achbotPayUxDraftWithoutEvidence($db,$id,$actor)) return null;
            $context=json_decode((string)$pay['checkout_draft_context'],true);
            if (!is_int($context['topup_prompt']??null) ||
                ($prompt!==null && $context['topup_prompt']!==$prompt) ||
                ($amount!==null && (int)$pay['price']!==$amount)) return null;
            $known=achbotProviderRow($db,"SELECT id FROM payment_message_refs
                WHERE pay_row_id=? AND chat_id=? AND retired_reason IS NULL LIMIT 1",'ii',[$id,$actor]);
            if ($known) return null;
            $next=achbotWalletTopupStage('committed',$updateId,$id);
            $claimed=achbotWalletTopupAdvance($db,$actor,$step,$next,$updateId);
            if ($claimed!=='applied') return $claimed;
            return achbotWalletTopupPresentSelector($db,$id,$actor,$botUrl);
        },'delivery_pending');
    } catch (Throwable $failure) {
        error_log('achbot_wallet_topup_recovery_deferred pay_id='.$id.' class='.get_class($failure));
        return 'delivery_pending';
    }
}
