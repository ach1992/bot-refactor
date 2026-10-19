<?php
/**
 * AChBot #51: guarded, source-only payment message lifecycle.
 *
 * No payment/provider/credit/fulfillment operation may depend on Telegram edits.
 * The optional additive 006 registry is installed only after its own approval.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

function achbotPayUxTable(mysqli $db, string $name): bool {
    if (!in_array($name, ['payment_attempts','payment_transactions',
                          'payment_message_refs'], true)) return false;
    try {
        $result = $db->query("SHOW TABLES LIKE '".$name."'");
        return $result instanceof mysqli_result && $result->num_rows === 1;
    } catch (Throwable $ignored) {
        return false;
    }
}

/**
 * BOT_STATES stores bounded, per-instance policy in minutes/hours.
 * Invalid/missing values retain the last safe defaults. Never use zero to
 * disable a financial safety control or interpret a provider claim as paid.
 */
function achbotPayUxPolicyNumber(string $field, int $default, int $min, int $max): int {
    $states=$GLOBALS['botState'] ?? [];
    if (!is_array($states)) return $default;
    $raw=$states[$field] ?? null;
    if (!(is_int($raw) || is_string($raw)) ||
        !preg_match('/^[0-9]{1,5}$/D',(string)$raw)) return $default;
    $value=(int)$raw;
    return ($value >= $min && $value <= $max) ? $value : $default;
}
function achbotPayUxDraftDeadlineSeconds(): int {
    return 60*achbotPayUxPolicyNumber('paymentDraftMinutes',30,5,180);
}
function achbotPayUxManualDeadlineSeconds(): int {
    return 3600*achbotPayUxPolicyNumber('paymentManualHours',6,1,72);
}
function achbotPayUxHostedAttentionSeconds(): int {
    // This is a UI/link review deadline, NOT evidence that upstream is unpaid.
    return 3600*achbotPayUxPolicyNumber('paymentHostedHours',6,1,72);
}
function achbotPayUxManualProvider(string $provider): bool {
    return in_array($provider,['bank_transfer','gift_card','manual_usdt','manual_trx'],true);
}
function achbotPayUxHostedProvider(string $provider): bool {
    return in_array($provider,['nowpayments','zarinpal','nextpay'],true);
}

/**
 * Expire ONE unissued, unclaimed draft; serialize against the existing provider
 * reservation by locking pays first, then checking its attempt under the lock.
 * A newer active draft supersedes older, same-user/same-type/same-target drafts.
 * Never delete pays; never erase financial evidence or pending provider claims.
 */
function achbotPayUxExpireDraft(mysqli $db, int $payId, ?int $clock = null): bool {
    // Partial 003 cannot establish that an invoice was never issued.
    if ($payId <= 0 || !achbotPayUxTable($db,'payment_attempts') ||
        !achbotPayUxTable($db,'payment_transactions')) return false;
    $now = $clock ?? time();
    $started = false;
    try {
        if (!$db->begin_transaction()) return false;
        $started = true;
        $stmt = $db->prepare(
            "SELECT id,user_id,type,order_id,request_date,state,payid
             FROM pays WHERE id=? FOR UPDATE"
        );
        $stmt->bind_param('i', $payId);
        if (!$stmt->execute()) throw new RuntimeException('draft_lock_unavailable');
        $pay = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$pay || $pay['state'] !== 'pending' ||
            (string)($pay['payid'] ?? '') !== '' || (int)$pay['user_id'] <= 0) {
            $db->rollback(); return false;
        }
        $stmt = $db->prepare(
            "SELECT id FROM payment_attempts WHERE pay_row_id=? LIMIT 1 FOR UPDATE"
        );
        $stmt->bind_param('i', $payId);
        if (!$stmt->execute()) throw new RuntimeException('draft_attempt_unavailable');
        $attempt = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($attempt) { $db->rollback(); return false; }

        $overdue = $now - (int)$pay['request_date'] >= achbotPayUxDraftDeadlineSeconds();

        // Same order for renewals/extensions; other purchase/wallet drafts
        // replace the prior still-unclaimed customer quote of the same type.
        $userId = (int)$pay['user_id'];
        $family = achbotPayUxPaymentFamily((string)$pay['type']);
        $orderId = (int)($pay['order_id'] ?? 0);
        $stmt = $db->prepare(
            "SELECT id FROM pays
             WHERE user_id=? AND SUBSTRING_INDEX(type,'_',2)=? AND id>? AND request_date>=?
               AND COALESCE(order_id,0)=?
               AND state NOT IN ('expired','expired_have_sent','mismatched_plan','declined')
             ORDER BY id DESC LIMIT 1"
        );
        $then = (int)$pay['request_date'];
        $stmt->bind_param('isiii', $userId, $family, $payId, $then, $orderId);
        if (!$stmt->execute()) throw new RuntimeException('draft_successor_unavailable');
        $newer = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$overdue && !$newer) { $db->rollback(); return false; }

        $stmt = $db->prepare(
            "UPDATE pays SET state='expired',updated_at=NOW()
             WHERE id=? AND state='pending' AND (payid IS NULL OR payid='')
             AND NOT EXISTS (SELECT 1 FROM payment_attempts a
                             WHERE a.pay_row_id=pays.id)"
        );
        $stmt->bind_param('i', $payId);
        if (!$stmt->execute()) throw new RuntimeException('draft_expiry_failed');
        $changed = $stmt->affected_rows;
        $stmt->close();
        if ($changed !== 1 || !$db->commit()) {
            throw new RuntimeException('draft_expiry_commit_uncertain');
        }
        return true;
    } catch (Throwable $error) {
        if ($started) {
            try { $db->rollback(); } catch (Throwable $ignored) {}
        }
        error_log('achbot_payment_draft_expiry_deferred pay_id='.$payId);
        return false; // fail closed: never promise the draft was cancelled.
    }
}

/**
 * Lock the owning user before any unclaimed-draft INSERT or hosted-provider
 * reservation. users.userid is historically indexed but NOT unique: refuse
 * missing/ambiguous identity. Never perform a remote operation under this lock.
 *
 * Lock order: users(userid) -> pays(id) -> payment_attempts(pay_row_id).
 * Replacement INSERTs hold the SAME owner lock until COMMIT, preventing
 * phantom successor drafts between a reservation's recheck and COMMIT.
 */
function achbotPayUxLockDraftOwner(mysqli $db, int $userId): void {
    if ($userId <= 0) throw new RuntimeException('draft_owner_invalid');
    $stmt=$db->prepare('SELECT id FROM users WHERE userid=? FOR UPDATE');
    if (!$stmt) throw new RuntimeException('draft_owner_lock_unavailable');
    $stmt->bind_param('i',$userId);
    if (!$stmt->execute()) throw new RuntimeException('draft_owner_lock_failed');
    $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    if (count($rows)!==1) throw new RuntimeException('draft_owner_missing_or_ambiguous');
}

/** Execute a NEW customer draft under the same lock held by all issuers. */
function achbotPayUxInsertDraft(mysqli $db, int $actor, mysqli_stmt $insert): int {
    $started=false;
    try {
        if (!$db->begin_transaction()) throw new RuntimeException('draft_begin_failed');
        $started=true;
        achbotPayUxLockDraftOwner($db,$actor);
        if (!$insert->execute() || $insert->affected_rows!==1)
            throw new RuntimeException('draft_insert_failed');
        $draftId=(int)$insert->insert_id;
        if ($draftId<=0 || !$db->commit())
            throw new RuntimeException('draft_commit_unknown');
        return $draftId;
    } catch (Throwable $error) {
        if ($started) {try{$db->rollback();}catch(Throwable $ignored){}}
        error_log('achbot_customer_draft_creation_deferred');
        // A lost COMMIT ack is ambiguous. The caller must not retry this
        // potentially committed invoice or advertise an unverified hash.
        return 0;
    }
}

/** A payment family groups only related recurring price/quantity variants. */
function achbotPayUxPaymentFamily(string $type): string {
    return implode('_',array_slice(explode('_',$type),0,2));
}

/** A later persisted payment draft makes an older *UI link* stale.
 * Issued provider invoices remain financial claims and are NEVER auto-cancelled.
 */
function achbotPayUxHasNewer(mysqli $db, array $pay, bool $lockingRead=false): bool {
    $payId=(int)($pay['id']??0);$userId=(int)($pay['user_id']??0);
    if ($payId<=0 || $userId<=0) return false;
    try {
        $family=achbotPayUxPaymentFamily((string)($pay['type']??''));
        $orderId=(int)($pay['order_id']??0);
        $then=(int)($pay['request_date']??0);
        $stmt=$db->prepare(
            "SELECT id FROM pays WHERE user_id=? AND SUBSTRING_INDEX(type,'_',2)=? AND id>?
               AND request_date>=? AND COALESCE(order_id,0)=?
               AND state NOT IN ('expired','expired_have_sent','mismatched_plan','declined')
             LIMIT 1".($lockingRead ? ' FOR UPDATE' : '')
        );
        $stmt->bind_param('isiii',$userId,$family,$payId,$then,$orderId);
        if (!$stmt->execute()) throw new RuntimeException('successor_read_failed');
        $row=$stmt->get_result()->fetch_assoc();$stmt->close();
        return (bool)$row;
    } catch (Throwable $ignored) {
        // The checkout URL must fail closed if newer status is unreadable.
        throw new RuntimeException('payment_successor_check_unavailable');
    }
}

/**
 * Check age and committed successors AGAIN inside the exact reservation
 * transaction after the parent-user and target-pay locks. A regular SELECT
 * can see an earlier REPEATABLE READ snapshot; the successor query must be
 * a CURRENT locking read. A failed read rejects issuance without side effects.
 */
function achbotPayUxLockedCheckoutUsable(mysqli $db, array $pay): bool {
    if ((int)($pay['id']??0)<=0 || (int)($pay['user_id']??0)<=0 ||
        (string)($pay['state']??'')!=='pending' ||
        (string)($pay['payid']??'')!=='' ||
        (int)($pay['request_date']??0)<=0 ||
        time()-(int)$pay['request_date']>=achbotPayUxDraftDeadlineSeconds())
        return false;
    return !achbotPayUxHasNewer($db,$pay,true);
}

/**
 * Call only with a freshly locked pays row when selecting a NEW payment
 * method or debiting a wallet. Existing manual/provider claims must use their
 * separate evidence/reconciliation path, never be invalidated by this check.
 */
function achbotPayUxUnclaimedDraftUsable(mysqli $db, array $pay): bool {
    if ((int)($pay['id']??0)<=0 ||
        !in_array((string)($pay['state']??''),['pending','send','low_wallet'],true) ||
        (string)($pay['payid']??'')!=='' ||
        (int)($pay['request_date']??0)<=0 ||
        time()-(int)$pay['request_date'] >= achbotPayUxDraftDeadlineSeconds()) {
        return false;
    }
    try {
        return !achbotPayUxHasNewer($db,$pay);
    } catch (Throwable $ignored) {
        return false;
    }
}

/**
 * Under pays FOR UPDATE followed by the exact 003 attempt FOR UPDATE, mark a
 * never-started manual quote expired only after its configured idle window.
 * The pinned destination remains untouched; real out-of-band funds, if any,
 * require human review and must never be presumed absent/refunded.
 */
function achbotPayUxExpireUnstartedManual(mysqli $db, int $payId): bool {
    if ($payId<=0 || !achbotPayUxTable($db,'payment_attempts') ||
        !achbotPayUxTable($db,'payment_transactions')) return false;
    $started=false;
    try {
        if (!$db->begin_transaction()) return false;
        $started=true;
        $stmt=$db->prepare('SELECT id,user_id,state,payid,message_id FROM pays WHERE id=? FOR UPDATE');
        $stmt->bind_param('i',$payId);
        if (!$stmt->execute()) throw new RuntimeException('manual_expiry_pay_lock');
        $pay=$stmt->get_result()->fetch_assoc();$stmt->close();
        if (!$pay || $pay['state']!=='pending' || (int)$pay['user_id']<=0 ||
            (string)($pay['payid']??'')!=='' || (int)($pay['message_id']??0)>0) {
            $db->rollback();return false;
        }
        $stmt=$db->prepare('SELECT id,provider,stage,provider_status,checkout_url,
            payment_id,review_notice_claim_token,
            TIMESTAMPDIFF(SECOND,created_at,NOW()) AS age_seconds
            FROM payment_attempts WHERE pay_row_id=? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('i',$payId);
        if (!$stmt->execute()) throw new RuntimeException('manual_expiry_attempt_lock');
        $a=$stmt->get_result()->fetch_assoc();$stmt->close();
        if (!$a || !achbotPayUxManualProvider((string)$a['provider']) ||
            $a['stage']!=='pending' || (string)($a['provider_status']??'')!=='' ||
            (string)($a['payment_id']??'')!=='' ||
            (string)($a['review_notice_claim_token']??'')!=='' ||
            (int)$a['age_seconds']<achbotPayUxManualDeadlineSeconds()) {
            $db->rollback();return false;
        }
        $intent=achbotManualIntentDecode((string)$a['checkout_url'],(string)$a['provider']);
        if (!is_array($intent) || !empty($intent['receipt_file_id'])) {
            $db->rollback();return false;
        }
        $attemptId=(int)$a['id'];
        $stmt=$db->prepare('SELECT id FROM payment_transactions WHERE attempt_id=? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('i',$attemptId);
        if (!$stmt->execute()) throw new RuntimeException('manual_expiry_child_lookup');
        $child=$stmt->get_result()->fetch_assoc();$stmt->close();
        if ($child) {$db->rollback();return false;}
        $stmt=$db->prepare("UPDATE payment_attempts SET stage='expired'
            WHERE id=? AND stage='pending' AND (provider_status IS NULL OR provider_status='')
              AND payment_id IS NULL AND review_notice_claim_token IS NULL
              AND NOT EXISTS (SELECT 1 FROM payment_transactions t WHERE t.attempt_id=payment_attempts.id)");
        $stmt->bind_param('i',$attemptId);
        if (!$stmt->execute()) throw new RuntimeException('manual_expiry_attempt_write');
        $changed=$stmt->affected_rows;$stmt->close();
        if ($changed!==1) throw new RuntimeException('manual_expiry_attempt_race');
        $stmt=$db->prepare("UPDATE pays SET state='expired',updated_at=NOW()
            WHERE id=? AND state='pending' AND (payid IS NULL OR payid='')
              AND (message_id IS NULL OR message_id=0)");
        $stmt->bind_param('i',$payId);
        if (!$stmt->execute()) throw new RuntimeException('manual_expiry_pay_write');
        $changed=$stmt->affected_rows;$stmt->close();
        if ($changed!==1 || !$db->commit())
            throw new RuntimeException('manual_expiry_commit_uncertain');
        return true;
    } catch (Throwable $error) {
        if ($started) {try{$db->rollback();}catch(Throwable $ignored){}}
        error_log('achbot_manual_quote_expiry_deferred pay_id='.$payId);
        return false;
    }
}

/**
 * A receipt button is a durable intent, not financial settlement. This is
 * the point where the six-hour idle timer is stopped. The photo's receipt
 * lease/review steps remain separately guarded and require real evidence.
 */
function achbotPayUxStartManualReceipt(mysqli $db, int $payId,
                                       string $method, int $actor): bool {
    if ($payId<=0 || $actor<=0 || !achbotPayUxManualProvider($method) ||
        !achbotPayUxTable($db,'payment_attempts') ||
        !achbotPayUxTable($db,'payment_transactions')) return false;
    $started=false;
    try {
        if (!$db->begin_transaction()) return false;
        $started=true;
        $stmt=$db->prepare('SELECT user_id,state,payid FROM pays WHERE id=? FOR UPDATE');
        $stmt->bind_param('i',$payId);
        if (!$stmt->execute()) throw new RuntimeException('manual_click_pay_lock');
        $pay=$stmt->get_result()->fetch_assoc();$stmt->close();
        if (!$pay || (int)$pay['user_id']!==$actor ||
            $pay['state']!=='pending' || (string)($pay['payid']??'')!=='') {
            $db->rollback();return false;
        }
        $stmt=$db->prepare('SELECT id,provider,stage,provider_status,checkout_url,
            TIMESTAMPDIFF(SECOND,created_at,NOW()) AS age_seconds
            FROM payment_attempts WHERE pay_row_id=? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('i',$payId);
        if (!$stmt->execute()) throw new RuntimeException('manual_click_attempt_lock');
        $a=$stmt->get_result()->fetch_assoc();$stmt->close();
        if (!$a || (string)$a['provider']!==$method || $a['stage']!=='pending' ||
            !is_array(achbotManualIntentDecode((string)$a['checkout_url'],$method))) {
            $db->rollback();return false;
        }
        if ((string)($a['provider_status']??'')==='receipt_started') {
            $db->commit();return true;
        }
        if ((string)($a['provider_status']??'')!=='' ||
            (int)$a['age_seconds']>=achbotPayUxManualDeadlineSeconds()) {
            $db->rollback();return false;
        }
        $aid=(int)$a['id'];
        $stmt=$db->prepare("UPDATE payment_attempts SET provider_status='receipt_started'
            WHERE id=? AND stage='pending' AND provider_status IS NULL");
        $stmt->bind_param('i',$aid);
        if (!$stmt->execute()) throw new RuntimeException('manual_click_write');
        $changed=$stmt->affected_rows;$stmt->close();
        if ($changed!==1 || !$db->commit())
            throw new RuntimeException('manual_click_commit_uncertain');
        return true;
    } catch (Throwable $error) {
        if ($started) {try{$db->rollback();}catch(Throwable $ignored){}}
        error_log('achbot_manual_receipt_click_deferred pay_id='.$payId);
        return false;
    }
}

/** Exact provider-claim age from the database clock (not PHP timezone). */
function achbotPayUxHostedAttentionElapsed(mysqli $db, int $payId): bool {
    if ($payId<=0 || !achbotPayUxTable($db,'payment_attempts')) return false;
    $stmt=$db->prepare("SELECT provider,stage,
        TIMESTAMPDIFF(SECOND,created_at,NOW()) AS age_seconds
        FROM payment_attempts WHERE pay_row_id=? LIMIT 1");
    $stmt->bind_param('i',$payId);
    if (!$stmt->execute()) throw new RuntimeException('hosted_attention_read_failed');
    $a=$stmt->get_result()->fetch_assoc();$stmt->close();
    return $a && achbotPayUxHostedProvider((string)$a['provider'])
        && (int)$a['age_seconds'] >= achbotPayUxHostedAttentionSeconds();
}

/**
 * Payment audience policy is an *additional* owner restriction, never a
 * replacement for payment state, global on/off flags, authorization or proof.
 * A missing policy means legacy 'all'; invalid explicit policy FAILS CLOSED.
 */
function achbotPayUxAudienceModes(): array {
    return ['all'=>'همه','agents'=>'همکاران','non_agents'=>'کاربران عادی',
            'buyers'=>'خریداران','non_buyers'=>'بدون خرید',
            'has_active_order'=>'دارای سرویس فعال',
            'no_active_order'=>'بدون سرویس فعال','off'=>'هیچ‌کس'];
}
function achbotPayUxAudienceMethods(): array {
    return [
        'nowpayments'=>'NOWPayments','zarinpal'=>'زرین‌پال','nextpay'=>'نکست‌پی',
        'bank_transfer'=>'کارت‌به‌کارت','gift_card'=>'گیفت‌کارت',
        'manual_usdt'=>'USDT دستی','manual_trx'=>'TRX دستی','wallet'=>'کیف پول'
    ];
}
function achbotPayUxAudienceAllowed(mysqli $db, string $method, int $userId): bool {
    if ($userId<=0 || !array_key_exists($method,achbotPayUxAudienceMethods()))
        return false;
    $states=$GLOBALS['botState']??[];
    if (!is_array($states)) return false;
    // Distinguish genuinely absent legacy keys from explicitly malformed
    // restrictions, including explicit NULL at either map or method level.
    $configured=array_key_exists('gatewayAudience',$states)
        ? $states['gatewayAudience'] : [];
    if (!is_array($configured)) return false;
    $mode=array_key_exists($method,$configured) ? $configured[$method] : 'all';
    if (!is_string($mode) || !array_key_exists($mode,achbotPayUxAudienceModes()))
        return false;
    if ($mode==='off') return false;
    if ($mode==='all') return true; // exact legacy behavior if unset
    try {
        $stmt=$db->prepare('SELECT is_agent FROM users WHERE userid=? LIMIT 1');
        $stmt->bind_param('i',$userId);
        if (!$stmt->execute()) throw new RuntimeException('audience_user_lookup');
        $person=$stmt->get_result()->fetch_assoc();$stmt->close();
        if (!$person) return false;
        if ($mode==='agents') return (int)$person['is_agent']===1;
        if ($mode==='non_agents') return (int)$person['is_agent']!==1;
        if ($mode==='buyers' || $mode==='non_buyers') {
            $stmt=$db->prepare("SELECT id FROM pays WHERE user_id=?
                AND type='BUY_SUB'
                AND state IN ('paid','approved','auto_cart_approved','paid_with_wallet')
                LIMIT 1");
            $stmt->bind_param('i',$userId);
            if (!$stmt->execute()) throw new RuntimeException('audience_buyer_lookup');
            $bought=$stmt->get_result()->fetch_assoc()!==null;$stmt->close();
            return $mode==='buyers' ? $bought : !$bought;
        }
        $now=time();
        $stmt=$db->prepare("SELECT id FROM orders_list WHERE userid=?
            AND status=1 AND expire_date>? LIMIT 1");
        $stmt->bind_param('ii',$userId,$now);
        if (!$stmt->execute()) throw new RuntimeException('audience_orders_lookup');
        $hasOrder=$stmt->get_result()->fetch_assoc()!==null;$stmt->close();
        return $mode==='has_active_order' ? $hasOrder : !$hasOrder;
    } catch (Throwable $error) {
        error_log('achbot_gateway_audience_guard_deferred method='.$method);
        return false;
    }
}
/** Register only messages successfully sent by the bot to the actual pay owner. */
function achbotPayUxTrackMessage(mysqli $db, int $payId, int $chatId,
                                 $telegramResult, string $kind = 'text'): bool {
    if ($payId <= 0 || $chatId <= 0 ||
        !achbotPayUxTable($db, 'payment_message_refs')) return false;
    $messageId = 0;
    if (is_object($telegramResult) && ($telegramResult->ok ?? false) === true) {
        $messageId = (int)($telegramResult->result->message_id ?? 0);
    } elseif (is_array($telegramResult) && ($telegramResult['ok'] ?? false) === true) {
        $messageId = (int)($telegramResult['result']['message_id'] ?? 0);
    }
    if ($messageId <= 0 || !in_array($kind,['text','media'],true)) return false;
    try {
        $stmt = $db->prepare(
            "INSERT INTO payment_message_refs (pay_row_id,chat_id,message_id,kind)
             SELECT id,?, ?,? FROM pays WHERE id=? AND user_id=?
             ON DUPLICATE KEY UPDATE synced_digest=payment_message_refs.synced_digest"
        );
        $stmt->bind_param('iisii', $chatId, $messageId, $kind, $payId, $chatId);
        $ok = $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        return $ok === true && $affected === 1;
    } catch (Throwable $ignored) {
        error_log('achbot_payment_message_registration_deferred pay_id='.$payId.
            ' code='.(int)$ignored->getCode().' class='.get_class($ignored));
        return false;
    }
}

/** Map an already-confirmed order hash to its exact current pays row. */
function achbotPayUxTrackByHash(mysqli $db, string $hash, int $chatId,
                                $telegramResult = null, int $clickedMessageId = 0): void {
    if ($chatId <= 0 || !preg_match('/^[A-Za-z0-9_-]{1,50}$/D',$hash)) return;
    try {
        $stmt=$db->prepare('SELECT id FROM pays WHERE hash_id=? AND user_id=? LIMIT 1');
        $stmt->bind_param('si',$hash,$chatId);
        $stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();
        if (!$row) return;
        if ($clickedMessageId>0) {
            achbotPayUxTrackClicked($db,(int)$row['id'],$chatId,$clickedMessageId);
        } else {
            achbotPayUxTrackMessage($db,(int)$row['id'],$chatId,$telegramResult);
        }
    } catch (Throwable $ignored) {}
}

/** A clicked, owner-verified bot message is another safe recoverable reference. */
function achbotPayUxTrackClicked(mysqli $db, int $payId, int $chatId, int $messageId): void {
    if ($payId <= 0 || $chatId <= 0 || $messageId <= 0 ||
        !achbotPayUxTable($db,'payment_message_refs')) return;
    try {
        $stmt = $db->prepare(
            "INSERT INTO payment_message_refs (pay_row_id,chat_id,message_id,kind)
             SELECT id,?,?,'text' FROM pays WHERE id=? AND user_id=?
             ON DUPLICATE KEY UPDATE synced_digest=payment_message_refs.synced_digest"
        );
        $stmt->bind_param('iiii', $chatId, $messageId, $payId, $chatId);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $ignored) {}
}

/**
 * Conservative semantic projection from durable local facts. 'paid' by itself
 * NEVER proves that Marzban delivery or a top-up effect was completed.
 */
function achbotPayUxState(array $pay, ?array $attempt): string {
    $state = (string)($pay['state'] ?? '');
    $stage = (string)($attempt['stage'] ?? '');
    if ($state === 'expired' || $state === 'mismatched_plan' ||
        $state === 'expired_have_sent') return 'expired';
    if ($state === 'declined') return 'declined';
    if (in_array($state, ['paid','approved','auto_cart_approved',
                          'paid_with_wallet'],true)) return 'payment_recorded';
    if ($state === 'have_sent' || $stage === 'receiving') return 'receipt_review';
    if ($stage === 'review' || $stage === 'checking' || $stage === 'fulfilling' ||
        $stage === 'creating') return 'needs_review';
    // Issued/manual quotes have a DB-created timestamp independent of the
    // original unissued draft; read their age from the SQL server clock.
    $provider=(string)($attempt['provider']??'');
    $age=isset($attempt['age_seconds']) ? (int)$attempt['age_seconds'] : -1;
    if (achbotPayUxManualProvider($provider) && $stage==='pending') {
        if ((string)($attempt['provider_status']??'')==='receipt_started')
            return 'awaiting_receipt';
        if ($age>=achbotPayUxManualDeadlineSeconds())
            return 'needs_review'; // Never claim cancellation before COMMIT.
    }
    if (achbotPayUxHostedProvider($provider) &&
        $stage==='pending' && $age>=achbotPayUxHostedAttentionSeconds())
        return 'needs_review'; // Provider remains financially unsettled.
    if (achbotPayUxManualProvider($provider) && $stage==='expired')
        return 'expired';
    if (!in_array($state,['pending','send'],true)) return 'needs_review';
    if (!empty($pay['superseded'])) {
        // A claimed provider/manual invoice is NOT financially cancelled by
        // a newer quote; keep its own outstanding claim under review.
        return $attempt===null && (string)($pay['payid']??'')===''
            ? 'superseded' : 'needs_review';
    }
    if ((string)($attempt['payment_id'] ?? '') !== '' ||
        (string)($attempt['provider_status'] ?? '') !== '') return 'needs_review';
    if ((string)($pay['payid'] ?? '') !== '' && !$attempt) return 'needs_review';
    return 'awaiting_payment';
}

/** Public-facing messages cannot include a raw provider link or secret. */
function achbotPayUxStatusText(array $pay, ?array $attempt): string {
    $state = achbotPayUxState($pay,$attempt);
    $subject = function_exists('payTypeToDescription')
        ? (string)payTypeToDescription((string)$pay['type'])
        : 'پرداخت سفارش';
    $title = match ($state) {
        'expired' => '⌛️ مهلت این درخواست پرداخت پایان یافته است',
        'superseded' => '🧾 درخواست جدیدتری جایگزین این پیش‌نویس شده است',
        'declined' => '❌ درخواست پرداخت تأیید نشد',
        'receipt_review' => '📥 رسید دریافت شده و در انتظار بررسی مدیر است',
        'awaiting_receipt' => '📤 در انتظار تصویر رسید یا گیفت‌کارت شما',
        'needs_review' => '⚠️ پرداخت یا تحویل نیازمند بررسی است',
        'payment_recorded' => '✅ وضعیت پرداخت ثبت شده است',
        default => '🧾 فاکتور در انتظار پرداخت است',
    };
    $safe = static fn($v) => htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $hash = $safe($pay['hash_id'] ?? '');
    $amount = number_format((int)($pay['price'] ?? 0));
    $next = match ($state) {
        'expired' => achbotPayUxManualProvider((string)($attempt['provider']??''))
            ? 'مهلت ارسال رسید از طریق این فاکتور پایان یافته است. اگر وجهی انتقال داده‌اید، شواهد را فقط از طریق پشتیبانی ارائه کنید؛ انقضا به معنی برگشت وجه نیست.'
            : 'مهلت این درخواست پایان یافته است. برای پرداخت جدید از منوی ربات اقدام کنید.',
        'awaiting_receipt' => 'درخواست ارسال رسید ثبت شد. تصویر معتبر را در گفت‌وگوی ربات بفرستید؛ صرف این دکمه به معنی پرداخت یا شروع بررسی مدیر نیست.',
        'superseded' => 'از فاکتور جدید استفاده کنید. لینک قبلی دیگر برای پرداخت قابل استفاده نیست؛ اگر وجهی فرستاده‌اید با پشتیبانی هماهنگ کنید.',
        'declined' => 'جزئیات بررسی را از پشتیبانی پیگیری کنید.',
        'receipt_review' => 'ارسال رسید به معنی تأیید مالی نیست. وجه یا رسید را دوباره ارسال نکنید.',
        'payment_recorded' => 'ثبت وجه به‌تنهایی به معنی تحویل سرویس نیست؛ وضعیت سفارش را در ربات بررسی کنید.',
        'needs_review' => 'فاکتور یا وجه را دوباره پرداخت نکنید؛ با شناسه سفارش از پشتیبانی پیگیری کنید.',
        default => 'تنها تا زمانی که وضعیت سفارش اجازه دهد از دکمه پرداخت استفاده کنید.',
    };
    return "<b>".$safe($title)."</b>\n🆔 سفارش: <code>{$hash}</code>\n"
        ."موضوع: ".$safe($subject)."\nمبلغ: {$amount} تومان\n\n".$safe($next);
}

function achbotPayUxStatusKeyboard(string $hash): string {
    $rows = [];
    if (preg_match('/^[A-Za-z0-9_-]{1,50}$/D',$hash)) {
        $rows[] = [['text'=>'🔄 بررسی وضعیت سفارش','callback_data'=>'ps_'.$hash]];
    }
    $rows[] = [['text'=>'🏠 منوی اصلی','callback_data'=>'mainMenu']];
    return json_encode(['inline_keyboard'=>$rows],JSON_UNESCAPED_UNICODE);
}

/**
 * Best-effort Telegram clean-up: each registered payment message is rewritten
 * to its current status, or at minimum has its old clickable URL removed.
 * A failed edit never rewinds or changes a financial transition.
 */
function achbotPayUxSyncMessages(mysqli $db, int $payId): int {
    if ($payId <= 0 || !function_exists('bot') ||
        !achbotPayUxTable($db,'payment_message_refs')) return 0;
    try {
        $stmt = $db->prepare('SELECT * FROM pays WHERE id=? LIMIT 1');
        $stmt->bind_param('i',$payId);
        $stmt->execute();
        $pay=$stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$pay) return 0;
        $attempt=null;
        if (achbotPayUxTable($db,'payment_attempts')) {
            $stmt=$db->prepare('SELECT provider,stage,payment_id,provider_status,
                TIMESTAMPDIFF(SECOND,created_at,NOW()) AS age_seconds
                FROM payment_attempts WHERE pay_row_id=? LIMIT 1');
            $stmt->bind_param('i',$payId);$stmt->execute();
            $attempt=$stmt->get_result()->fetch_assoc()?:null;$stmt->close();
        }
        try { $pay['superseded']=achbotPayUxHasNewer($db,$pay); }
        catch (Throwable $ignored) { $pay['superseded']=true; }
        $state=achbotPayUxState($pay,$attempt);
        // Still-live payment CTAs must not be needlessly removed.
        if ($state==='awaiting_payment') return 0;
        $text=achbotPayUxStatusText($pay,$attempt);
        $keyboard=achbotPayUxStatusKeyboard((string)$pay['hash_id']);
        $digest=hash('sha256',$state."\n".$text."\n".$keyboard);
        $stmt=$db->prepare('SELECT id,chat_id,message_id,kind,synced_digest
                            FROM payment_message_refs WHERE pay_row_id=?
                              AND (synced_digest IS NULL OR synced_digest<>?)
                            ORDER BY updated_at ASC,id ASC LIMIT 10');
        $stmt->bind_param('is',$payId,$digest);$stmt->execute();
        $messages=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
        $changed=0;
        foreach ($messages as $item) {
            if ((string)($item['synced_digest'] ?? '')===$digest) continue;
            $chat=(int)$item['chat_id'];$message=(int)$item['message_id'];
            if ($chat <= 0 || $chat !== (int)$pay['user_id'] || $message <= 0) {
                // Never edit a foreign/bad reference; rotate it out so other
                // messages remain eligible rather than starving indefinitely.
                $rowId=(int)$item['id'];
                $stmt=$db->prepare('UPDATE payment_message_refs SET updated_at=NOW(6)
                                    WHERE id=? AND pay_row_id=?');
                $stmt->bind_param('ii',$rowId,$payId);
                $stmt->execute();$stmt->close();
                continue;
            }
            // Text first changes the instructions AND removes stale provider URL.
            $ok=false;
            $fullTextEdited=false;
            if ($item['kind']==='text') {
                try {
                    $res=bot('editMessageText',[
                        'chat_id'=>$chat,'message_id'=>$message,
                        'text'=>$text,'parse_mode'=>'HTML',
                        'reply_markup'=>$keyboard,
                        'disable_web_page_preview'=>true
                    ]);
                    $ok=is_object($res) && ($res->ok ?? false)===true;
                    $fullTextEdited=$ok;
                } catch (Throwable $ignored) {}
            }
            if (!$ok && $item['kind']==='media') {
                try {
                    $res=bot('editMessageCaption',[
                        'chat_id'=>$chat,'message_id'=>$message,
                        'caption'=>$text,'parse_mode'=>'HTML',
                        'reply_markup'=>$keyboard
                    ]);
                    $ok=is_object($res) && ($res->ok ?? false)===true;
                    $fullTextEdited=$ok;
                } catch (Throwable $ignored) {}
            }
            if (!$ok) {
                try {
                    $res=bot('editMessageReplyMarkup',[
                        'chat_id'=>$chat,'message_id'=>$message,
                        'reply_markup'=>$keyboard,
                    ]);
                    $ok=is_object($res) && ($res->ok ?? false)===true;
                } catch (Throwable $ignored) {}
            }
            if (!$ok) {
                error_log('achbot_payment_telegram_edit_deferred pay_id='.$payId);
            }
            if (!$fullTextEdited) {
                // Even a successful markup-only action is not a truthful text
                // synchronization. Keep it retryable; rotate this attempted
                // row behind not-yet-attempted messages for this pay ID.
                $rowId=(int)$item['id'];
                $stmt=$db->prepare('UPDATE payment_message_refs SET updated_at=NOW(6)
                                    WHERE id=? AND pay_row_id=?');
                $stmt->bind_param('ii',$rowId,$payId);
                $stmt->execute();$stmt->close();
                continue;
            }
            // Only stamp after an acknowledged full text update.
            $rowId=(int)$item['id'];
            $stmt=$db->prepare(
                'UPDATE payment_message_refs SET synced_digest=?,updated_at=NOW(6)
                 WHERE id=? AND pay_row_id=?'
            );
            $stmt->bind_param('sii',$digest,$rowId,$payId);
            $stmt->execute();$stmt->close();
            ++$changed;
        }
        return $changed;
    } catch (Throwable $error) {
        error_log('achbot_payment_message_sync_deferred pay_id='.$payId);
        return 0;
    }
}

/** Run under the existing one-minute warning job; never create a fifth Cron. */
function achbotPayUxMaintain(mysqli $db, int $maxRows=45): array {
    $maxRows=max(1,min($maxRows,100));
    $expired=0;$updated=0;
    if (achbotPayUxTable($db,'payment_attempts') &&
        achbotPayUxTable($db,'payment_transactions')) {
        try {
            $cutoff=time()-achbotPayUxDraftDeadlineSeconds();
            $stmt=$db->prepare(
                "SELECT p.id FROM pays p WHERE p.state='pending'
                   AND (p.payid IS NULL OR p.payid='')
                   AND NOT EXISTS (
                       SELECT 1 FROM payment_attempts a WHERE a.pay_row_id=p.id
                   )
                   AND (p.request_date<=? OR EXISTS (
                       SELECT 1 FROM pays n WHERE n.user_id=p.user_id
                         AND SUBSTRING_INDEX(n.type,'_',2)=SUBSTRING_INDEX(p.type,'_',2)
                         AND n.id>p.id
                         AND n.request_date>=p.request_date
                         AND COALESCE(n.order_id,0)=COALESCE(p.order_id,0)
                         AND n.state NOT IN
                             ('expired','expired_have_sent','mismatched_plan','declined')
                   ))
                 ORDER BY p.request_date ASC LIMIT {$maxRows}"
            );
            $stmt->bind_param('i',$cutoff);$stmt->execute();
            $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
            foreach ($rows as $r) {
                if (achbotPayUxExpireDraft($db,(int)$r['id'])) ++$expired;
            }
        } catch (Throwable $ignored) {
            error_log('achbot_payment_expiry_batch_deferred');
        }
    }
    // Invoice-specific idle expiry is separate from unissued draft expiry.
    // Provider-issued online claims remain immutable; only no-click manual
    // receipts can transition, with pinned evidence retained.
    if (achbotPayUxTable($db,'payment_attempts') &&
        achbotPayUxTable($db,'payment_transactions')) {
        try {
            $seconds=achbotPayUxManualDeadlineSeconds();
            $stmt=$db->prepare("SELECT p.id FROM pays p
                INNER JOIN payment_attempts a ON a.pay_row_id=p.id
                WHERE p.state='pending' AND (p.payid IS NULL OR p.payid='')
                  AND (p.message_id IS NULL OR p.message_id=0)
                  AND a.provider IN ('bank_transfer','gift_card','manual_usdt','manual_trx')
                  AND a.stage='pending'
                  AND (a.provider_status IS NULL OR a.provider_status='')
                  AND a.payment_id IS NULL
                  AND a.review_notice_claim_token IS NULL
                  AND TIMESTAMPDIFF(SECOND,a.created_at,NOW())>=?
                  AND NOT EXISTS (SELECT 1 FROM payment_transactions t
                                  WHERE t.attempt_id=a.id)
                ORDER BY a.created_at ASC LIMIT {$maxRows}");
            $stmt->bind_param('i',$seconds);
            if (!$stmt->execute()) throw new RuntimeException('manual_idle_scan');
            $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
            foreach ($rows as $row) {
                if (achbotPayUxExpireUnstartedManual($db,(int)$row['id']))
                    ++$expired;
            }
        } catch (Throwable $ignored) {
            error_log('achbot_manual_idle_batch_deferred');
        }
    }
    if (achbotPayUxTable($db,'payment_message_refs')) {
        // Telegram edits are network calls. Bound each Cron tick separately
        // from the 45-row local expiry scan to avoid delaying worker duties.
        $messageBatch=min($maxRows,8);
        try {
            $stmt=$db->prepare(
                "SELECT pay_row_id FROM payment_message_refs
                 GROUP BY pay_row_id ORDER BY MIN(updated_at) ASC LIMIT {$messageBatch}"
            );
            $stmt->execute();
            $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
            foreach ($rows as $r) {
                $id=(int)$r['pay_row_id'];
                $updated+=achbotPayUxSyncMessages($db,$id);
                // Fair scanning; a failed Telegram edit remains eligible later.
                $stmt=$db->prepare(
                    'UPDATE payment_message_refs SET updated_at=NOW(6)
                     WHERE pay_row_id=?'
                );
                $stmt->bind_param('i',$id);$stmt->execute();$stmt->close();
            }
        } catch (Throwable $ignored) {
            error_log('achbot_payment_message_batch_deferred');
        }
    }
    return ['expired'=>$expired,'edited'=>$updated];
}
