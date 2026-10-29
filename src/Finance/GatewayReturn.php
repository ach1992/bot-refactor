<?php
/** AChBot V8: source-pinned financial compatibility module. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG


require_once __DIR__ . '/GatewayClient.php';
require_once __DIR__ . '/ServiceEffects.php';


/**
 * A provider child ID is not a payment. These authenticated provider
 * states distinguish an idle identifier from possible/actual incoming funds.
 * 'confirmed' is NOT final merchant settlement; no child can auto-credit
 * or auto-provision the original invoice a second time.
 */
function achbotNpChildEvidenceRank($status): int {
    return match ((string)$status) {
        'waiting', 'failed', 'expired' => 0,
        'confirming' => 1,
        'confirmed' => 2,
        'sending' => 3,
        'partially_paid' => 4,
        'finished' => 5,
        'refunded' => 6,
        default => 0,
    };
}

/** Shared operator-attention predicate for Cron notification and bot inbox. */
function achbotNpOperatorWorkSql(): string {
    $incoming="'confirming','confirmed','sending','partially_paid','finished','refunded'";
    $extra="EXISTS (SELECT 1 FROM payment_transactions c
        WHERE c.attempt_id=a.id AND c.provider='nowpayments'
          AND c.payment_id<>COALESCE(a.payment_id,'')
          AND c.observed_status IN ($incoming))";
    $any="EXISTS (SELECT 1 FROM payment_transactions c
        WHERE c.attempt_id=a.id AND c.provider='nowpayments'
          AND c.observed_status IN ($incoming))";
    // Some trusted provider verification/remote failures predate the child
    // transaction table; preserve them as possible funds requiring attention.
    $effect="a.provider_status IN ('fulfillment_unconfirmed',
        'fulfillment_failed','fulfillment_needs_review','amount_mismatch',
        'local_amount_changed','identity_mismatch','partially_paid','refunded')";
    return "a.provider='nowpayments' AND a.stage='review' AND (
        (a.provider_status IN ('additional_payment','multiple_payments')
         AND $extra)
        OR (COALESCE(a.provider_status,'') NOT IN ('additional_payment','multiple_payments')
            AND ($any OR $effect)))";
}

/**
 * Signed NOWPayments IPN only records a durable payment ID. It never
 * provisions anything or contacts external providers in the HTTP callback.
 * Return a non-2xx status on missing/untrusted data so the provider may retry.
 */
function achbotNpIngestIpn(mysqli $connection, string $ipnSecret,
                           string $raw, string $signature): int {
    $payload=achbotNpValidIpn($raw,$signature,$ipnSecret);
    if ($payload===null) return 401;
    foreach (['payment_id','invoice_id','order_id','payment_status',
              'price_amount','price_currency'] as $field) {
        if (!isset($payload[$field]) || !is_scalar($payload[$field])) return 422;
    }
    $paymentId=(string)$payload['payment_id'];
    $invoiceId=(string)$payload['invoice_id'];
    $orderHash=(string)$payload['order_id'];
    $status=(string)$payload['payment_status'];
    if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$paymentId) ||
        !preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$invoiceId) ||
        $orderHash==='' || strlen($orderHash)>50 || strlen($status)>32 ||
        strtolower((string)$payload['price_currency'])!=='usd') return 422;
    try {
        // Lock the entire local invoice decision, then claim provider child
        // identity in ONE UNIQUE ledger shared by primary and extra deposits.
        // No HTTP 200 is returned before child evidence and state commit.
        if (!$connection->begin_transaction())
            throw new RuntimeException('ipn_begin_unconfirmed');
        $stmt=$connection->prepare("SELECT a.*,p.payid AS original_invoice
            FROM payment_attempts a JOIN pays p ON p.id=a.pay_row_id
            WHERE a.provider='nowpayments' AND a.order_hash=? AND a.provider_reference=? LIMIT 2 FOR UPDATE");
        $stmt->bind_param('ss',$orderHash,$invoiceId);
        $stmt->execute();
        $rows=$stmt->get_result();
        $attempt=$rows->num_rows===1?$rows->fetch_assoc():null;
        $stmt->close();
        if (!$attempt || (string)$attempt['original_invoice']!==$invoiceId) {
            $connection->rollback();
            return 409;
        }
        if ((string)$attempt['provider']!=='nowpayments' ||
            (string)$attempt['quote_currency']!=='usd' ||
            !achbotNpDecimalAtLeast($payload['price_amount'],$attempt['quote_amount']) ||
            !achbotNpDecimalAtLeast($attempt['quote_amount'],$payload['price_amount'])) {
            $connection->rollback();
            return 422;
        }
        $stmt=$connection->prepare("SELECT attempt_id,observed_status
            FROM payment_transactions
            WHERE provider='nowpayments' AND payment_id=? LIMIT 1 FOR UPDATE");
        $stmt->bind_param('s',$paymentId);
        $stmt->execute();
        $existing=$stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($existing && (int)$existing['attempt_id']!==(int)$attempt['id']) {
            $connection->rollback();
            return 409;
        }
        $previous=(string)($existing['observed_status']??'');
        $improved=achbotNpChildEvidenceRank($status)>
                  achbotNpChildEvidenceRank($previous) ||
                  ($previous==='waiting' &&
                   in_array($status,['failed','expired'],true));
        if (!$existing) {
            $stmt=$connection->prepare("INSERT INTO payment_transactions
                (attempt_id,provider,payment_id,observed_status) VALUES (?,'nowpayments',?,?)");
            $stmt->bind_param('iss',$attempt['id'],$paymentId,$status);
            if (!$stmt->execute() || $stmt->affected_rows!==1)
                throw new RuntimeException('ipn_child_insert_unconfirmed');
            $stmt->close();
        } elseif ($improved) {
            // An out-of-order waiting/expired IPN must not erase already
            // authenticated incoming-funds evidence from a prior event.
            $stmt=$connection->prepare("UPDATE payment_transactions
                SET observed_status=? WHERE attempt_id=? AND provider='nowpayments'
                  AND payment_id=?");
            $stmt->bind_param('sis',$status,$attempt['id'],$paymentId);
            if (!$stmt->execute() || $stmt->affected_rows!==1)
                throw new RuntimeException('np_child_evidence_update_unconfirmed');
            $stmt->close();
        }
        if ($attempt['payment_id']===null) {
            $stmt=$connection->prepare("UPDATE payment_attempts SET
                payment_id=?, provider_status=CASE
                    WHEN stage='review' THEN provider_status ELSE ? END,
                stage=CASE WHEN stage='review' THEN 'review'
                           WHEN stage='checking' THEN 'checking'
                           ELSE 'pending' END WHERE id=?");
            $stmt->bind_param('ssi',$paymentId,$status,$attempt['id']);
            if (!$stmt->execute() || $stmt->affected_rows!==1)
                throw new RuntimeException('ipn_primary_link_unconfirmed');
            $stmt->close();
        } elseif ((string)$attempt['payment_id']!==$paymentId &&
                  $improved && achbotNpChildEvidenceRank($status)>0) {
            // Only NEW incoming-funds evidence (not creation of another
            // unpaid child ID) opens or refreshes review. Existing 003
            // children remain durable for future authenticated updates.
            $stmt=$connection->prepare("UPDATE payment_attempts SET
                provider_status=CASE WHEN stage='done' OR
                    provider_status='additional_payment' THEN 'additional_payment'
                    ELSE 'multiple_payments' END,
                stage='review',review_notified_at=NULL,
                review_notice_claim_token=NULL,review_notice_claimed_at=NULL WHERE id=?");
            $stmt->bind_param('i',$attempt['id']);
            // An additional funded child is already a durable INSERT or a
            // positive-state UPDATE under the locked attempt. Repeating the
            // same review label may affect zero rows; that is not a failed
            // transaction and must not cause a provider IPN 409.
            if (!$stmt->execute())
                throw new RuntimeException('np_additional_evidence_unconfirmed');
            $stmt->close();
        }
        if (!$connection->commit()) throw new RuntimeException('ipn_evidence_commit_failed');
        return 200;
    } catch (Throwable $error) {
        $connection->rollback();
        return 409;
    }
}

/**
 * Send a single Persian finance notice per meaningful evidence revision,
 * never daily reminders or notices for invoice/child-ID creation alone.
 * The operator can open the verified read-only case in the bot, never
 * credit/refund/provision merely by interacting with a notification.
 */
function achbotNpAlertOperator(mysqli $connection, $adminChatId,
                               int $maxItems=3): array {
    $counts=['examined'=>0,'notified'=>0,'failed'=>0];
    if (!is_scalar($adminChatId) ||
        !preg_match('/^[1-9][0-9]*$/D',(string)$adminChatId)) return $counts;
    $maxItems=max(1,min(5,$maxItems));
    $scope=achbotNpOperatorWorkSql();
    $sql="SELECT a.id,a.order_hash,a.provider_reference,a.payment_id,a.stage,
                 a.provider_status,a.quote_amount,a.quote_currency,
                 a.created_at,a.updated_at,p.user_id,p.type,p.plan_id,p.price,
                 p.state AS local_pay_state,
                 (SELECT u.name FROM users u WHERE u.userid=p.user_id
                  ORDER BY u.id DESC LIMIT 1) AS user_name,
                 (SELECT u.username FROM users u WHERE u.userid=p.user_id
                  ORDER BY u.id DESC LIMIT 1) AS user_handle,
                 (SELECT sp.title FROM server_plans sp WHERE sp.id=p.plan_id
                  LIMIT 1) AS plan_title,
                 (SELECT COUNT(*) FROM payment_transactions c
                  WHERE c.attempt_id=a.id AND c.provider='nowpayments') AS child_count,
                 (SELECT COUNT(*) FROM payment_transactions c
                  WHERE c.attempt_id=a.id AND c.provider='nowpayments'
                    AND c.payment_id<>COALESCE(a.payment_id,'')
                    AND c.observed_status IN
                       ('confirming','confirmed','sending','partially_paid',
                        'finished','refunded')) AS extra_evidence_count
          FROM payment_attempts a JOIN pays p ON p.id=a.pay_row_id
          WHERE $scope
            AND a.review_notified_at IS NULL
            AND (a.review_notice_claim_token IS NULL OR
                 a.review_notice_claimed_at < NOW()-INTERVAL 5 MINUTE)
          ORDER BY a.updated_at ASC LIMIT ".$maxItems;
    try {
        $rows=$connection->query($sql);
    } catch (Throwable $error) {
        // Preserve other Cron work on a degraded financial-reporting read.
        error_log('achbot_payment_operator_alert_query_unavailable');
        $counts['failed']++;
        return $counts;
    }
    while ($rows && ($a=$rows->fetch_assoc())) {
        $counts['examined']++;
        $id=(int)$a['id'];
        $stage=(string)$a['stage'];
        $childCount=(int)$a['child_count'];
        // CAS lease before calling Telegram: overlapping Cron processes
        // cannot both send the same snapshot of operator evidence.
        $token=bin2hex(random_bytes(16));
        $claimed=false;
        try {
            $stmt=$connection->prepare("UPDATE payment_attempts
                SET review_notice_claim_token=?,review_notice_claimed_at=NOW()
                WHERE id=? AND stage=?
                  AND (review_notice_claim_token IS NULL OR
                       review_notice_claimed_at < NOW()-INTERVAL 5 MINUTE)
                  AND review_notified_at IS NULL
                  AND (SELECT COUNT(*) FROM payment_transactions c
                       WHERE c.attempt_id=payment_attempts.id AND c.provider='nowpayments')=?");
            $stmt->bind_param('sisi',$token,$id,$stage,$childCount);
            $stmt->execute();
            $claimed=$stmt->affected_rows===1;
            $stmt->close();
        } catch (Throwable $error) {
            $counts['failed']++;
        }
        if (!$claimed) continue;

        $safe=static fn($value): string => htmlspecialchars(
            substr((string)($value ?? ''),0,128),
            ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $subject=match ((string)$a['type']) {
            'INCREASE_WALLET' => \achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.type.991ae08d64'),
            'BUY_SUB' => \achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.type.e6343571a4'),
            'RENEW_ACCOUNT' => \achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.type.df1c62b99e'),
            default => \achbotMessage('gateway.gateway_return.np_alert_operator.text.df83eb82dd'),
        };
        $reason=match ((string)$a['provider_status']) {
            'additional_payment','multiple_payments' =>
                \achbotMessage('gateway.gateway_return.np_alert_operator.text.cc4537b3c7'),
            'partially_paid' => \achbotMessage('gateway.gateway_return.np_alert_operator.text.ec28d8fba7'),
            'fulfillment_failed','fulfillment_unconfirmed',
            'fulfillment_needs_review' => \achbotMessage('gateway.gateway_return.np_alert_operator.text.84a1e5c442'),
            default => \achbotMessage('gateway.gateway_return.np_alert_operator.text.2d09871a1b'),
        };
        $username=trim((string)($a['user_handle']??''),'@');
        $identity=$safe($a['user_name'] ?: \achbotMessage('gateway.gateway_return.np_alert_operator.text.f07a81a062')).
            ($username!=='' ? ' (@'.$safe($username).')':'');
        $quote=$safe($a['quote_amount']).' '.$safe($a['quote_currency']);
        $message=\achbotMessage('gateway.gateway_return.np_alert_operator.message.00aa2f3ddb').
                 \achbotMessage('gateway.gateway_return.np_alert_operator.text.a5c241ee14').$identity."\n".
                 \achbotMessage('gateway.gateway_return.np_alert_operator.text.51de176d3c').(int)$a['user_id']."</code>\n".
                 \achbotMessage('gateway.gateway_return.np_alert_operator.text.8029d14042').$subject.
                 (!empty($a['plan_title']) ? \achbotMessage('gateway.gateway_return.np_alert_operator.text.3ecde6c863').$safe($a['plan_title']):'')."\n".
                 \achbotMessage('gateway.gateway_return.np_alert_operator.text.ff1755f857').number_format((int)$a['price']).\achbotMessage('gateway.gateway_return.np_alert_operator.text.81a62ff6c6').
                 \achbotMessage('gateway.gateway_return.np_alert_operator.text.7d4d150298').$quote."\n".
                 \achbotMessage('gateway.gateway_return.np_alert_operator.text.9d6887c013').
                 \achbotMessage('gateway.gateway_return.np_alert_operator.text.0a771c866b').$safe($a['order_hash'])."</code>\n".
                 \achbotMessage('gateway.gateway_return.np_alert_operator.text.9c71d4bcce').$safe($a['provider_reference'])."</code>\n".
                 \achbotMessage('gateway.gateway_return.np_alert_operator.text.64ce2f53c9').$safe($a['payment_id'] ?: \achbotMessage('gateway.gateway_return.np_alert_operator.text.5749a86140'))."</code>\n".
                 \achbotMessage('gateway.gateway_return.np_alert_operator.text.bc5958e0ae').$childCount.
                 \achbotMessage('gateway.gateway_return.np_alert_operator.text.daf218b959').(int)$a['extra_evidence_count'].")\n".
                 \achbotMessage('gateway.gateway_return.np_alert_operator.text.04d4b236f8').$reason."\n".
                 \achbotMessage('gateway.gateway_return.np_alert_operator.text.283ba2c09b').$safe($a['updated_at'])."\n\n".
                 \achbotMessage('gateway.gateway_return.np_alert_operator.text.515a991cf3').
                 \achbotMessage('gateway.gateway_return.np_alert_operator.text.30a0d023b8');
        $keyboard=json_encode(['inline_keyboard'=>[[
            ['text'=>\achbotMessage('gateway.gateway_return.np_alert_operator.button.3506f17600'),
             'callback_data'=>'npReviewCase_'.$id],
        ]]],JSON_UNESCAPED_UNICODE);
        try {
            $sent=sendMessage($message,$keyboard,'HTML',(int)$adminChatId);
            if (!is_object($sent) || empty($sent->ok)) {
                $counts['failed']++;
            } else {
                // The old notification must NOT acknowledge a later child
                // N+1 or any newly claimed generation during the send.
                $stmt=$connection->prepare("UPDATE payment_attempts
                    SET review_notified_at=NOW(),
                        review_notice_claim_token=NULL,
                        review_notice_claimed_at=NULL
                    WHERE id=? AND stage=? AND review_notice_claim_token=?
                      AND (SELECT COUNT(*) FROM payment_transactions c
                           WHERE c.attempt_id=payment_attempts.id AND c.provider='nowpayments')=?");
                $stmt->bind_param('issi',$id,$stage,$token,$childCount);
                $stmt->execute();
                $acknowledged=$stmt->affected_rows===1;
                $stmt->close();
                if ($acknowledged) $counts['notified']++;
            }
        } catch (Throwable $error) {
            $counts['failed']++;
        } finally {
            // Release only our own claim, never a later new-child/worker
            // claim. Failed sends stay retryable on the next Cron tick.
            try {
                $stmt=$connection->prepare("UPDATE payment_attempts
                    SET review_notice_claim_token=NULL,
                        review_notice_claimed_at=NULL
                    WHERE id=? AND review_notice_claim_token=?");
                $stmt->bind_param('is',$id,$token);
                $stmt->execute();
                $stmt->close();
            } catch (Throwable $ignored) {
                // A five-minute lease expires independently if DB is unwell.
            }
        }
    }
    return $counts;
}

/**
 * Called by the existing approved Cron filename. Bounded work per tick.
 * The provider lookup verifies a signed event, but does not trust it alone.
 * Unresolved/ambiguous remote fulfillment is left for operator reconciliation.
 */
function achbotNpReconcileBatch(mysqli $connection, array $paymentKeys,
                               int $maxItems = 3): array {
    $done = ['examined'=>0,'completed'=>0,'pending'=>0,'review'=>0];
    $apiKey = (string)($paymentKeys['nowpayment'] ?? '');
    if ($apiKey === '') return $done;
    $limit = max(1,min(5,$maxItems));
    $sql = "SELECT a.*,p.payid AS original_invoice,p.state AS pay_state,
                   p.price AS current_local_amount
            FROM payment_attempts a JOIN pays p ON p.id=a.pay_row_id
            WHERE a.provider='nowpayments' AND a.payment_id IS NOT NULL AND
            (a.stage='pending' OR
              (a.stage='checking' AND a.last_checked_at < NOW()-INTERVAL 10 MINUTE)
              OR a.stage='fulfilling')
            AND (a.last_checked_at IS NULL OR
                 a.last_checked_at < NOW()-INTERVAL 60 SECOND)
            ORDER BY a.last_checked_at ASC LIMIT ".$limit;
    $rows = $connection->query($sql);
    while ($rows && ($attempt = $rows->fetch_assoc())) {
        $done['examined']++;
        // NOWPayments invoices do not expire. A child payment can be created
        // long after invoice creation, so never quarantine by created_at.
        // Keep bounded polling of the known payment_id; terminal/unknown
        // provider statuses are assessed independently below.
        // A prior run may have exited inside the inherited doAction().
        // Never trigger remote provisioning again after an ambiguous result.
        if ($attempt['stage']==='fulfilling' ||
            !in_array($attempt['pay_state'],['pending','send'],true)) {
            $stmt=$connection->prepare("UPDATE payment_attempts
                SET stage='review',provider_status='fulfillment_needs_review'
                WHERE id=? AND stage NOT IN ('done','review')");
            $stmt->bind_param('i',$attempt['id']);
            $stmt->execute();
            $stmt->close();
            $done['review']++;
            continue;
        }
        $stmt = $connection->prepare("UPDATE payment_attempts
            SET stage='checking',last_checked_at=NOW()
            WHERE id=? AND
              (stage='pending' OR
                (stage='checking' AND last_checked_at < NOW()-INTERVAL 10 MINUTE))
              AND (last_checked_at IS NULL OR
                   last_checked_at < NOW()-INTERVAL 60 SECOND)");
        $stmt->bind_param('i',$attempt['id']);
        $stmt->execute();
        $claimed=$stmt->affected_rows;
        $stmt->close();
        if ($claimed!==1) continue;
        $remote=achbotNpGetVerifiedPayment((string)$attempt['payment_id'],$apiKey);
        if (!$remote) {
            $connection->query("UPDATE payment_attempts
                SET stage='pending' WHERE id=".(int)$attempt['id']." AND stage='checking'");
            $done['pending']++;
            continue;
        }
        $actualPaymentId=(string)($remote['payment_id'] ?? '');
        $actualInvoiceId=(string)($remote['invoice_id'] ?? '');
        $actualOrderHash=(string)($remote['order_id'] ?? '');
        if ($actualPaymentId !== (string)$attempt['payment_id'] ||
            $actualInvoiceId !== (string)$attempt['provider_reference'] ||
            $actualOrderHash !== (string)$attempt['order_hash'] ||
            (string)$attempt['original_invoice'] !== (string)$attempt['provider_reference']) {
            $stmt=$connection->prepare("UPDATE payment_attempts
                SET stage='review',provider_status='identity_mismatch' WHERE id=?");
            $stmt->bind_param('i',$attempt['id']);
            $stmt->execute();
            $stmt->close();
            $done['review']++;
            continue;
        }
        if ((int)$attempt['local_amount_toman'] <= 0 ||
            (int)$attempt['local_amount_toman'] !== (int)$attempt['current_local_amount']) {
            $stmt=$connection->prepare("UPDATE payment_attempts
                SET stage='review',provider_status='local_amount_changed'
                WHERE id=? AND stage='checking'");
            $stmt->bind_param('i',$attempt['id']);
            $stmt->execute();
            $stmt->close();
            $done['review']++;
            continue;
        }
        $status=(string)($remote['payment_status'] ?? '');
        $kind=achbotVerifiedGatewayOutcome('nowpayment',$status);
        if ($kind!=='verified') {
            // NOWPayments treats a partially_paid child payment as complete
            // but short-funded. It needs operator review, not endless polling.
            $nextStage=$kind==='pending'?'pending':'review';
            // Do not silently auto-expire a payment that may arrive late.
            $stmt=$connection->prepare("UPDATE payment_attempts
                SET stage=?,provider_status=? WHERE id=? AND stage='checking'");
            $stmt->bind_param('ssi',$nextStage,$status,$attempt['id']);
            $stmt->execute();
            $stmt->close();
            $done[$nextStage==='pending'?'pending':'review']++;
            continue;
        }
        if (!achbotNpValidFinalAmount($attempt,$remote)) {
            $stmt=$connection->prepare("UPDATE payment_attempts
                SET stage='review',provider_status='amount_mismatch' WHERE id=?");
            $stmt->bind_param('i',$attempt['id']);
            $stmt->execute();
            $stmt->close();
            $done['review']++;
            continue;
        }
        $stmt=$connection->prepare("UPDATE payment_attempts a
            JOIN pays p ON p.id=a.pay_row_id
            SET a.stage='fulfilling',a.provider_status='finished'
            WHERE a.id=? AND a.stage='checking'
              AND p.price=a.local_amount_toman
              AND p.state IN ('pending','send')");
        $stmt->bind_param('i',$attempt['id']);
        $stmt->execute();
        $lease=$stmt->affected_rows;
        $stmt->close();
        if ($lease!==1) continue;
        // The shared doAction() has an exact-row CAS claim. Never generate
        // a second service or wallet credit after that claim succeeds.
        ob_start();
        try {
            $effectOutcome=doAction((int)$attempt['pay_row_id'],'nowpayment');
            $stmt=$connection->prepare("SELECT state FROM pays WHERE id=? LIMIT 1");
            $stmt->bind_param('i',$attempt['pay_row_id']);
            $stmt->execute();
            $after=$stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (($after['state']??'')==='paid' &&
                in_array($effectOutcome,['delivered','compensated'],true)) {
                $status=$effectOutcome==='compensated'?'compensated':'finished';
                $stmt=$connection->prepare("UPDATE payment_attempts
                    SET stage='done',provider_status=? WHERE id=? AND stage='fulfilling'");
                $stmt->bind_param('si',$status,$attempt['id']);
                $stmt->execute();
                $stmt->close();
                $done['completed']++;
            } else {
                $stmt=$connection->prepare("UPDATE payment_attempts
                    SET stage='review',provider_status='fulfillment_unconfirmed' WHERE id=?");
                $stmt->bind_param('i',$attempt['id']);
                $stmt->execute();
                $stmt->close();
                $done['review']++;
            }
        } catch (Throwable $error) {
            // DB and remote Marzban are not one transaction. Keep a human
            // review marker; do NOT blindly replay creation after an error.
            $stmt=$connection->prepare("UPDATE payment_attempts
                SET stage='review',provider_status='fulfillment_failed' WHERE id=?");
            $stmt->bind_param('i',$attempt['id']);
            $stmt->execute();
            $stmt->close();
            $done['review']++;
        } finally {
            ob_end_clean();
        }
    }
    return $done;
}

if (defined('ACHBOT_NP_RECONCILE_ONLY')) {
    // Internal Cron and protected administrator-only read views do not
    // dispatch browser/IPN handlers from caller-supplied query strings.
} elseif (isset($_GET['nowpayment_ipn'])) {
    $method=$_SERVER['REQUEST_METHOD'] ?? '';
    $code=$method==='POST'
        ? achbotNpIngestIpn($connection, (string)($nowpaymentsIpnSecret ?? ''),
            (string)file_get_contents('php://input'),
            (string)($_SERVER['HTTP_X_NOWPAYMENTS_SIG']??''))
        : 405;
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['accepted'=>$code===200],JSON_UNESCAPED_SLASHES);
    return;
} elseif (isset($_GET['nowpayment'])) {

    if (isset($_GET['NP_id'])) {
        // Validate a local pending payment and its provider subject before
        // contacting NowPayments. An arbitrary/retired callback must not
        // cause even a payment-provider verification request.
        $incomingPaymentId = (string) $_GET['NP_id'];
        $callbackHash = (string) ($_GET['hash_id'] ?? '');
        if ($incomingPaymentId === '') {
            showForm(\achbotMessage('gateway.gateway_return.np_reconcile_batch.message.f8a82e5394'), \achbotMessage('gateway.gateway_return.np_reconcile_batch.message.f48a802b77'));
            return;
        }
        if ($callbackHash !== '') {
            $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? AND (`state` = 'pending' OR `state` = 'send') LIMIT 1");
            $stmt->bind_param('s', $callbackHash);
        } else {
            // Legacy callbacks without a hash can only be admitted when
            // their provider ID directly matches the stored invoice ID.
            $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `payid` = ? AND (`state` = 'pending' OR `state` = 'send') LIMIT 1");
            $stmt->bind_param('s', $incomingPaymentId);
        }
        $stmt->execute();
        $preflightPay = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$preflightPay || empty($preflightPay['payid']) ||
            !achbotSupportsPaymentSubject($connection, $preflightPay)) {
            showForm(\achbotMessage('gateway.gateway_return.np_reconcile_batch.message.e898fa59b8'), \achbotMessage('gateway.gateway_return.np_reconcile_batch.message.28954419df'));
            return;
        }
        // New signed-IPN invoices MUST finish only through the shared
        // quote-checked background worker. A browser query parameter NP_id
        // cannot bypass the durable attempt or complete remote fulfillment.
        $npTable = $connection->query("SHOW TABLES LIKE 'payment_attempts'");
        if ($npTable && $npTable->num_rows === 1) {
            $guard = $connection->prepare("SELECT stage FROM payment_attempts
                WHERE pay_row_id=? AND provider='nowpayments' LIMIT 1");
            $guard->bind_param('i', $preflightPay['id']);
            $guard->execute();
            $existing = $guard->get_result()->fetch_assoc();
            $guard->close();
            if ($existing) {
                // Stage is local evidence; never infer final settlement here.
                $presentation = in_array((string)$existing['stage'], ['pending','creating'], true)
                    ? 'pending' : 'review';
                showForm(\achbotMessage('gateway.gateway_return.np_reconcile_batch.message.678120fc1c'),
                         \achbotMessage('gateway.gateway_return.np_reconcile_batch.message.da0bc406d8'), false, $presentation);
                return;
            }
        }

        // Historical invoices lack an immutable source-captured quote and
        // payout policy. Even an authenticated 'finished' GET cannot prove
        // that this particular order's agreed fiat amount was settled.
        // Never auto-credit or auto-cancel from a legacy browser callback.
        showForm(\achbotMessage('gateway.gateway_return.np_reconcile_batch.message.bd2edf700f'),
                 \achbotMessage('gateway.gateway_return.np_reconcile_batch.message.da0bc406d8'), false, 'review');
        return;
    } else {
        // The success_url carries only the merchant order hash, not a trusted
        // payment ID; it is a read-only UX view of our locally verified state.
        $merchantHash=(string)($_GET['hash_id'] ?? '');
        $state=null;
        if ($merchantHash !== '') {
            $stmt=$connection->prepare("SELECT state FROM pays WHERE hash_id=? LIMIT 1");
            $stmt->bind_param('s',$merchantHash);
            $stmt->execute();
            $local=$stmt->get_result()->fetch_assoc();
            $stmt->close();
            $state=$local['state'] ?? null;
        }
        if ($state === 'paid') {
            showForm(\achbotMessage('gateway.gateway_return.np_reconcile_batch.message.0116c30fc4'),
                     \achbotMessage('gateway.gateway_return.np_reconcile_batch.message.5cf3e172d8'), true, 'review');
        } elseif ($state === 'pending' || $state === 'send') {
            showForm(\achbotMessage('gateway.gateway_return.np_reconcile_batch.message.e46087af54'),
                     \achbotMessage('gateway.gateway_return.np_reconcile_batch.message.f71cd91dad'), true, 'pending');
        } else {
            showForm(\achbotMessage('gateway.gateway_return.np_reconcile_batch.message.c4374c5a30'),
                     \achbotMessage('gateway.gateway_return.np_reconcile_batch.message.da0bc406d8'), false, 'review');
        }
    }

} elseif (isset($_GET['zarinpal'])) {

    $hash_id = $_GET['hash_id'];

    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? AND (`state` = 'pending' OR `state` = 'send')");
    $stmt->bind_param("s", $hash_id);
    $stmt->execute();
    $payInfo = $stmt->get_result();
    $stmt->close();

    if (mysqli_num_rows($payInfo) == 0) {
        showForm(\achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.message.2d0a6d7c50'), \achbotMessage('gateway.gateway_return.np_reconcile_batch.message.f48a802b77'));
    } else {
        $payParam = $payInfo->fetch_assoc();
        if (!achbotSupportsPaymentSubject($connection, $payParam)) {
            showForm(\achbotMessage('gateway.gateway_return.np_reconcile_batch.message.e898fa59b8'), \achbotMessage('gateway.gateway_return.np_reconcile_batch.message.28954419df'));
            return;
        }

        $rowId = (int)$payParam['id'];
        $amount = (int)$payParam['price'];

        $Authority = (string)($_GET['Authority'] ?? '');
        // A verified provider reply must belong to the AUTHORITY issued for
        // this exact order. Old orders without stored Authority need manual
        // review: there is no safe auto-settlement identity binding.
        if ($Authority === '' || (string)($payParam['payid'] ?? '') !== $Authority) {
            showForm(\achbotMessage('gateway.gateway_return.np_reconcile_batch.message.cf271d9662'), \achbotMessage('gateway.gateway_return.np_reconcile_batch.message.c0199d5243'), false, 'review');
            return;
        }
        // New attempts use a provider-scoped unique external identity. Old
        // orders lacking a shared attempt retain the inherited global
        // collision fail-closed rule instead of inventing provider ownership.
        $scoped=false;
        $ledger=$connection->query("SHOW TABLES LIKE 'payment_attempts'");
        if ($ledger && $ledger->num_rows===1) {
            $stmt=$connection->prepare("SELECT provider,provider_reference,
                       order_hash,quote_currency,quote_amount,local_amount_toman
                FROM payment_attempts WHERE pay_row_id=? LIMIT 2");
            $stmt->bind_param('i',$rowId);
            $stmt->execute();
            $attemptRows=$stmt->get_result();
            $attempt=$attemptRows->num_rows===1?$attemptRows->fetch_assoc():null;
            $ambiguous=$attemptRows->num_rows>1;
            $stmt->close();
            if ($ambiguous || ($attempt && (
                (string)$attempt['provider']!=='zarinpal' ||
                (string)$attempt['provider_reference']!==$Authority ||
                (string)$attempt['order_hash']!==(string)$payParam['hash_id'] ||
                (string)$attempt['quote_currency']!=='toman' ||
                (int)$attempt['local_amount_toman']!==$amount ||
                !achbotNpDecimalAtLeast($attempt['quote_amount'],(string)$amount) ||
                !achbotNpDecimalAtLeast((string)$amount,$attempt['quote_amount'])))) {
                showForm(\achbotMessage('gateway.gateway_return.np_reconcile_batch.message.e72296ca09'), \achbotMessage('gateway.gateway_return.np_reconcile_batch.message.da0bc406d8'), false, 'review');
                return;
            }
            $scoped=$attempt!==null;
            if ($scoped &&
                achbotHasUnscopedPaymentReferenceCollision($connection,$Authority,$rowId)) {
                showForm(\achbotMessage('gateway.gateway_return.np_reconcile_batch.message.67fa35fa63'),
                         \achbotMessage('gateway.gateway_return.np_reconcile_batch.message.da0bc406d8'), false, 'review');
                return;
            }
        }
        if (!$scoped) {
            // Unscoped historical pays.payid is NOT unique and may describe
            // different gateways. Never guess a provider for old records.
            $stmt=$connection->prepare("SELECT id FROM pays WHERE payid=? LIMIT 2");
            $stmt->bind_param('s',$Authority);
            $stmt->execute();
            $authorities=$stmt->get_result();
            $stmt->close();
            if ($authorities->num_rows!==1 ||
                (int)$authorities->fetch_assoc()['id']!==$rowId) {
                showForm(\achbotMessage('gateway.gateway_return.np_reconcile_batch.message.498c9fb2b1'), \achbotMessage('gateway.gateway_return.np_reconcile_batch.message.da0bc406d8'), false, 'review');
                return;
            }
        }

        // Browser Status is untrusted presentation metadata; ONLY verified
        // provider evidence can settle a locally bound unique Authority.
        try {
            $client = new SoapClient('https://www.zarinpal.com/pg/services/WebGate/wsdl',
                                     ['encoding'=>'UTF-8']);
            $result = $client->PaymentVerification([
                'MerchantID'=>($paymentKeys['zarinpal'] ?? ''),
                'Authority'=>$Authority,
                'Amount'=>$amount
            ]);
        } catch (\Throwable $error) {
            showForm(\achbotMessage('gateway.gateway_return.np_reconcile_batch.message.965bc59eaf'),
                     \achbotMessage('gateway.gateway_return.np_reconcile_batch.message.858b02ccfe'), false, 'review');
            return;
        }
        // SOAP Status is an exact protocol code, not a numeric amount:
        // is_numeric() plus an int cast would turn "100.9" into false success.
        // Only native integer 100 or the canonical SOAP string "100" settle.
        $verifiedStatus=is_object($result) && isset($result->Status) &&
            ($result->Status===100 || $result->Status==='100');
        if ($verifiedStatus) {
            // A forged/stale browser NOK cannot cancel trusted positive funds.
            doAction($rowId, "zarinpal");
        } else {
            // WebGate error-code lists vary by API generation. 101 is an
            // already-verified result, and other codes (including 52/-52)
            // are not documented here as irreversible nonpayment. Never
            // cancel an order without independently established, exact
            // provider-specific terminal-negative proof.
            showForm(\achbotMessage('gateway.gateway_return.np_reconcile_batch.message.26b2f2ff4c'),
                     \achbotMessage('gateway.gateway_return.np_reconcile_batch.message.858b02ccfe'), false, 'review');
        }
    }

} elseif (isset($_GET['nextpay'])) {

    $hash_id = (string)($_GET['trans_id'] ?? '');
    $payParam=null;
    if ($hash_id!=='') {
        $ledger=$connection->query("SHOW TABLES LIKE 'payment_attempts'");
        if ($ledger && $ledger->num_rows===1) {
            // The new issuer wrote the exact provider identity BEFORE
            // redirecting. Other providers may reuse this same external ID
            // without selecting a different user's payment.
            $stmt=$connection->prepare("SELECT p.*,a.order_hash AS attempt_order_hash,
                        a.quote_amount,a.quote_currency,
                        a.local_amount_toman AS attempt_local_amount
                FROM payment_attempts a JOIN pays p ON p.id=a.pay_row_id
                WHERE a.provider='nextpay' AND a.provider_reference=?
                LIMIT 2");
            $stmt->bind_param('s',$hash_id);
            $stmt->execute();
            $scopedRows=$stmt->get_result();
            if ($scopedRows->num_rows===1) $payParam=$scopedRows->fetch_assoc();
            $ambiguous=$scopedRows->num_rows>1;
            $stmt->close();
            if ($ambiguous || ($payParam && (
                (string)$payParam['payid']!==$hash_id ||
                (string)$payParam['attempt_order_hash']!==(string)$payParam['hash_id'] ||
                (string)$payParam['quote_currency']!=='toman' ||
                (int)$payParam['attempt_local_amount']!==(int)$payParam['price'] ||
                !achbotNpDecimalAtLeast($payParam['quote_amount'],(string)$payParam['price']) ||
                !achbotNpDecimalAtLeast((string)$payParam['price'],$payParam['quote_amount'])))) {
                showForm(\achbotMessage('gateway.gateway_return.np_reconcile_batch.message.2a57015571'), \achbotMessage('gateway.gateway_return.np_reconcile_batch.message.da0bc406d8'), false, 'review');
                return;
            }
            if ($payParam && achbotHasUnscopedPaymentReferenceCollision(
                $connection,$hash_id,(int)$payParam['id'])) {
                showForm(\achbotMessage('gateway.gateway_return.np_reconcile_batch.message.149dd8c934'),
                         \achbotMessage('gateway.gateway_return.np_reconcile_batch.message.da0bc406d8'), false, 'review');
                return;
            }
            if (!$payParam) {
                // Only true PRE-ledger rows can use the old callback lookup.
                // Do not accept a different new provider's reference through
                // the unscoped historical pays.payid route.
                $stmt=$connection->prepare("SELECT p.* FROM pays p WHERE p.payid=?
                    AND NOT EXISTS (SELECT 1 FROM payment_attempts a
                                    WHERE a.pay_row_id=p.id) LIMIT 2");
                $stmt->bind_param('s',$hash_id);
                $stmt->execute();
                $legacy=$stmt->get_result();
                if ($legacy->num_rows===1) $payParam=$legacy->fetch_assoc();
                $stmt->close();
                if ($payParam) {
                    // Preserve the historical GLOBAL uniqueness check,
                    // including new attempts owned by another provider.
                    // Excluding ledger rows in the first query alone would
                    // incorrectly turn a collision into a unique old order.
                    $stmt=$connection->prepare("SELECT id FROM pays
                        WHERE payid=? LIMIT 2");
                    $stmt->bind_param('s',$hash_id);
                    $stmt->execute();
                    $all=$stmt->get_result();
                    $unique=$all->num_rows===1 &&
                        (int)$all->fetch_assoc()['id']===(int)$payParam['id'];
                    $stmt->close();
                    if (!$unique) $payParam=null;
                }
            }
        } else {
            // Released RC3/RC4 callbacks that have not migrated the new
            // table must preserve their old, collision-rejecting behavior.
            $stmt=$connection->prepare("SELECT * FROM pays WHERE payid=? LIMIT 2");
            $stmt->bind_param('s',$hash_id);
            $stmt->execute();
            $legacy=$stmt->get_result();
            if ($legacy->num_rows===1) $payParam=$legacy->fetch_assoc();
            $stmt->close();
        }
    }

    if (!$payParam) {
        showForm(\achbotMessage('gateway.gateway_return.np_reconcile_batch.message.faca1dabd3'), \achbotMessage('gateway.gateway_return.np_reconcile_batch.message.f48a802b77'));
    } else {
        if (!in_array($payParam['state'], ['pending', 'send'], true)) {
            showForm(\achbotMessage('gateway.gateway_return.np_reconcile_batch.message.cef6ec0c00'), \achbotMessage('gateway.gateway_return.np_reconcile_batch.message.f48a802b77'));
            return;
        }
        if (!achbotSupportsPaymentSubject($connection, $payParam)) {
            showForm(\achbotMessage('gateway.gateway_return.np_reconcile_batch.message.e898fa59b8'), \achbotMessage('gateway.gateway_return.np_reconcile_batch.message.28954419df'));
            return;
        }

        $rowId  = (int)$payParam['id'];
        $amount = (int)$payParam['price'];
        $payid  = $payParam['payid'];

        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://nextpay.org/nx/gateway/verify',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => 'api_key=' . ($paymentKeys['nextpay'] ?? '') . '&amount=' . $amount . '&currency=IRT&trans_id=' . $payid,
        ));

        $rawResponse = curl_exec($curl);
        $httpStatus = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        // A transport/HTTP/JSON failure is NOT proof of a declined payment.
        $response = $rawResponse !== false && $httpStatus === 200
            ? json_decode($rawResponse) : null;
        $verifyCode = $response->code ?? null;

        $disposition = achbotVerifiedGatewayOutcome('nextpay', $verifyCode);
        if ($disposition === 'verified') {
            doAction($rowId, "nextpay");
        } elseif ($disposition === 'rejected') {
            // Official NextPay codes -2 (rejected) and -4 (canceled) are
            // definitive. -1/-3 are pending; other errors need reconciliation.
            // Cancel only the selected pending row; never clobber a replayed or
            // concurrently changed payment with an external payid.
            $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'canceled' WHERE `id` = ? AND `state` IN ('pending', 'send')");
            $stmt->bind_param("i", $rowId);
            $stmt->execute();
            $changed = $stmt->affected_rows === 1;
            $stmt->close();
            if (!$changed) {
                showForm(\achbotMessage('gateway.gateway_return.np_reconcile_batch.message.d9ed0f4231'), \achbotMessage('gateway.gateway_return.np_reconcile_batch.message.f48a802b77'), false, 'review');
                return;
            }
            showForm(\achbotMessage('gateway.gateway_return.np_reconcile_batch.message.4406d657a1'), \achbotMessage('gateway.gateway_return.np_reconcile_batch.message.26003a476f'), false, 'failed');
        } else {
            // Preserve the current payment for a later trusted verification.
            // Do not debit, credit or settle an unknown payment outcome.
            showForm(\achbotMessage('gateway.gateway_return.np_reconcile_batch.message.463d7338a5'), \achbotMessage('gateway.gateway_return.np_reconcile_batch.message.26003a476f'), false, 'review');
        }
    }

} else {
    showForm(\achbotMessage('gateway.gateway_return.np_reconcile_batch.message.1d15981671'), \achbotMessage('gateway.gateway_return.np_reconcile_batch.message.f48a802b77'));
    exit();
}

$rewardChannel = $botState['rewardChannel'] ?? null;

/**
 * A verified wallet top-up is entirely local to MariaDB. Persist the payment
 * claim and balance credit in ONE transaction, never a paid-but-uncredited
 * state. This does not replace a durable remote Marzban fulfillment ledger.
 */
function achbotClaimAndCreditWallet(mysqli $db, int $payId, int $userId,
                                    int $amount): bool {
    if ($payId <= 0 || $userId <= 0 || $amount <= 0) return false;
    $db->begin_transaction();
    try {
        $stmt = $db->prepare("UPDATE pays SET state='paid'
            WHERE id=? AND user_id=? AND price=? AND type='INCREASE_WALLET'
              AND state IN ('pending','send')");
        $stmt->bind_param('iii', $payId, $userId, $amount);
        $stmt->execute();
        $claimed = $stmt->affected_rows;
        $stmt->close();
        if ($claimed !== 1) {
            $db->rollback();
            return false;
        }

        // Preserve deliberate negative admin debt. A genuine credit reduces
        // debt, but may not overflow the existing signed INT wallet column.
        $stmt = $db->prepare("UPDATE users SET wallet=wallet+?
            WHERE userid=? AND wallet <= 2147483647 - ?");
        $stmt->bind_param('iii', $amount, $userId, $amount);
        $stmt->execute();
        $credited = $stmt->affected_rows;
        $stmt->close();
        if ($credited !== 1) {
            $db->rollback();
            return false;
        }
        if (!$db->commit()) {
            throw new RuntimeException('wallet_payment_commit_failed');
        }
        return true;
    } catch (Throwable $error) {
        $db->rollback();
        throw $error;
    }
}

/**
 * A zero-row SHOW TABLES result does not prove absence: MariaDB hides tables
 * from accounts with no privileges. Probe the specific ledger table instead.
 * Only error 1146 (ER_NO_SUCH_TABLE) permits the pre-003 legacy fallback;
 * privilege error 1142 and every uncertain SQL failure must fail closed.
 */
function achbotHostedPaymentAttemptsExists(mysqli $db): bool {
    try {
        $table=$db->query('SELECT 1 FROM payment_attempts LIMIT 0');
        if ($table===false) {
            // Under MYSQLI_REPORT_OFF, preserve errno before another query.
            $code=(int)$db->errno;
            if ($code===1146) return false;
            throw new RuntimeException('hosted_ledger_probe_failed');
        }
        $table->free();
        return true;
    } catch (mysqli_sql_exception $error) {
        if ((int)$error->getCode()===1146) return false;
        throw $error;
    }
}

/**
 * Preserve a recoverable service-fulfillment hold for *scoped* NextPay and
 * Zarinpal checkout attempts. The legacy pays row and its unique 003 attempt
 * move together in one local InnoDB transaction. A crash after commit but
 * before/during a remote call stays in review; it is NEVER safe to replay an
 * unverified Marzban side effect just because the attempt says review.
 *
 * Historical pre-ledger invoices remain on their existing guarded pays CAS
 * path. This cannot supply a per-account remote idempotency key, particularly
 * for multi-account purchases; Issue #26 tracks the eventual distinct effect
 * journal and operator-authorized readback/recovery boundary.
 */
function achbotClaimHostedServiceForReview(mysqli $db, int $payId, int $amount,
                                          string $provider, string $hash): bool {
    // Protected log correlation survives a rolled-back 003 transaction.
    // The provider label and code are bounded literals, never raw SQL errors
    // or untrusted payment credentials/callback payloads.
    $safeProvider=in_array($provider,['nextpay','zarinpal'],true)
        ? $provider : 'unknown';
    if ($safeProvider==='unknown' || $payId<=0 || $amount<=0 || $hash==='') {
        error_log('achbot_hosted_claim_unconfirmed pay_id='.(int)$payId.
            ' provider='.$safeProvider.' code=invalid_input');
        return false;
    }
    $failure='schema_inspection_failed';
    try {
        // A hidden but existing ledger must not select pays-only fulfillment.
        $hasLedger=achbotHostedPaymentAttemptsExists($db);

        $failure='transaction_begin_failed';
        if (!$db->begin_transaction()) throw new RuntimeException('transaction_begin_failed');

        $failure='paid_claim_failed';
        $stmt=$db->prepare("UPDATE pays SET state='paid'
            WHERE id=? AND price=? AND hash_id=? AND state IN ('pending','send')");
        $stmt->bind_param('iis',$payId,$amount,$hash);
        if (!$stmt->execute()) throw new RuntimeException('paid_claim_failed');
        $claimed=$stmt->affected_rows;
        $stmt->close();
        $failure='paid_claim_unavailable';
        if ($claimed!==1) throw new RuntimeException('paid_claim_unavailable');

        if ($hasLedger) {
            // Lock pays before the attempt, matching checkout's lock order.
            $failure='attempt_read_failed';
            $stmt=$db->prepare("SELECT id,provider,stage,order_hash,local_amount_toman
                FROM payment_attempts WHERE pay_row_id=? FOR UPDATE");
            $stmt->bind_param('i',$payId);
            if (!$stmt->execute()) throw new RuntimeException('attempt_read_failed');
            $attempt=$stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($attempt) {
                $failure='attempt_mismatch';
                if ((string)$attempt['provider']!==$provider ||
                    (string)$attempt['stage']!=='pending' ||
                    (string)$attempt['order_hash']!==$hash ||
                    (int)$attempt['local_amount_toman']!==$amount) {
                    throw new RuntimeException('attempt_mismatch');
                }
                $id=(int)$attempt['id'];
                $failure='ledger_stage_failed';
                $stmt=$db->prepare("UPDATE payment_attempts
                    SET stage='review',provider_status='fulfillment_started'
                    WHERE id=? AND pay_row_id=? AND provider=? AND stage='pending'");
                $stmt->bind_param('iis',$id,$payId,$provider);
                if (!$stmt->execute()) throw new RuntimeException('ledger_stage_failed');
                $changed=$stmt->affected_rows;
                $stmt->close();
                $failure='ledger_stage_unconfirmed';
                if ($changed!==1) throw new RuntimeException('ledger_stage_unconfirmed');
            }
        }
        $failure='commit_unconfirmed';
        if (!$db->commit()) throw new RuntimeException('commit_unconfirmed');
        return true;
    } catch (Throwable $ignored) {
        try { $db->rollback(); } catch (Throwable $alsoIgnored) {}
        // No durable review marker can be claimed on this error path.
        // A verified upstream charge still needs operator/provider matching.
        error_log('achbot_hosted_claim_unconfirmed pay_id='.$payId.
            ' provider='.$safeProvider.' code='.$failure);
        return false;
    }
}

/** A confirmed local outcome may close ONLY its own previously staged hold. */
function achbotFinishHostedServiceReview(mysqli $db, int $payId,
                                        string $provider, string $outcome): void {
    if (!in_array($provider,['nextpay','zarinpal'],true) ||
        !in_array($outcome,['delivered','compensated'],true)) return;
    $reason='closure_sql_unconfirmed';
    $sqlErrorCode=0;
    try {
        $status=$outcome==='compensated'?'compensated':'delivered';
        $stmt=$db->prepare("UPDATE payment_attempts a JOIN pays p ON p.id=a.pay_row_id
            SET a.stage='done',a.provider_status=?
            WHERE a.pay_row_id=? AND a.provider=? AND a.stage='review'
              AND a.provider_status='fulfillment_started' AND p.state='paid'");
        if ($stmt===false) {
            $sqlErrorCode=(int)$db->errno;
            throw new RuntimeException('hosted_review_finish_prepare_failed');
        }
        $stmt->bind_param('sis',$status,$payId,$provider);
        if (!$stmt->execute()) {
            $sqlErrorCode=(int)$stmt->errno;
            throw new RuntimeException('hosted_review_finish_failed');
        }
        $changed=$stmt->affected_rows;
        $stmt->close();
        if ($changed===1) return;
        if ($changed===0) {
            // A pre-003 service payment can have no attempt at all, and
            // hosted wallet top-ups do not use the service-review stage.
            // Neither is an unmatched service-closure defect. An existing
            // service attempt with the wrong stage still needs correlation.
            $stmt=$db->prepare("SELECT p.type,a.id AS attempt_id FROM pays p
                LEFT JOIN payment_attempts a ON a.pay_row_id=p.id
                WHERE p.id=? LIMIT 1");
            if ($stmt===false) {
                $sqlErrorCode=(int)$db->errno;
                throw new RuntimeException('hosted_finish_check_prepare_failed');
            }
            $stmt->bind_param('i',$payId);
            if (!$stmt->execute()) {
                $sqlErrorCode=(int)$stmt->errno;
                throw new RuntimeException('hosted_finish_check_failed');
            }
            $attempt=$stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($attempt && ($attempt['type']==='INCREASE_WALLET' ||
                $attempt['attempt_id']===null)) return;
        }
        $reason='closure_unmatched';
    } catch (Throwable $error) {
        // Preserve statement/database errno under MYSQLI_REPORT_OFF; throwing
        // MySQLi supplies the structured error code. A missing *joined* table
        // is not enough: probe payment_attempts itself before a silent no-op.
        $code=$error instanceof mysqli_sql_exception
            ? (int)$error->getCode() : $sqlErrorCode;
        if ($code===1146) {
            try {
                if (!achbotHostedPaymentAttemptsExists($db)) return;
            } catch (Throwable $ignored) {
                // Inaccessible/uncertain ledger cannot prove legacy absence.
            }
        }
        // No automatic replay, credit or refund on any uncertain SQL failure.
    }
    // For an existing service attempt, failure to confirm exactly one
    // final-state update needs correlation, never automatic fulfillment.
    error_log('achbot_hosted_fulfillment_finish_needs_review pay_id='.(int)$payId.
              ' provider='.$provider.' code='.$reason);
}

/**
 * A provider payment can be final while its remote Marzban side effect is
 * uncertain. Do not issue a compensating wallet credit that could give away
 * both an actually-created account and a refund. The worker records review;
 * provider/remote reconstruction is a separate recovery gate.
 */
function achbotNpDeferUncertainRemoteEffect($gateType, $payDescription): bool {
    // Provider settlement cannot prove remote Marzban success. A timeout
    // after remote success is NOT evidence that a refund is safe.
    if (!in_array($gateType,['nowpayment','nextpay','zarinpal'],true)) return false;
    showForm(\achbotMessage('gateway.gateway_return.np_defer_uncertain_remote_effect.message.adae4bb365'), $payDescription, false, 'review');
    return true;
}

function achbotPerformPaymentEffect($payRowId, $gateType, &$effectStage = null) {
    // Report the effect boundary to doAction's exception handler. No local
    // settlement/review row is assured before the paid claim succeeds.
    $effectStage='preclaim';
    global $connection, $admin, $userInfo, $from_id, $botUrl, $botState;
    global $buttonValues;
	
	$supportID = $botState['supportID'] ?? '';
    $rewardChannel = $botState['rewardChannel'] ?? null;
    $buttonValuesLocal = (isset($buttonValues) && is_array($buttonValues))
        ? $buttonValues
        : ['back_to_main' => \achbotMessage('gateway.gateway_return.perform_payment_effect.text.275b4903d9')];


    $time = time();

    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `id` = ? AND (`state` = 'pending' OR `state` = 'send')");
    $stmt->bind_param("i", $payRowId);
    $stmt->execute();
    $payInfo = $stmt->get_result();
    $stmt->close();

    if (mysqli_num_rows($payInfo) == 0) {
        showForm(\achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.message.2d0a6d7c50'), \achbotMessage('gateway.gateway_return.np_reconcile_batch.message.f48a802b77'));
        return false;
    }

    $payParam = $payInfo->fetch_assoc();
        if (!achbotSupportsPaymentSubject($connection, $payParam)) {
            showForm(\achbotMessage('gateway.gateway_return.np_reconcile_batch.message.e898fa59b8'), \achbotMessage('gateway.gateway_return.np_reconcile_batch.message.28954419df'));
            return false;
        }


    $rowId      = (int)$payParam['id'];
    $amount     = (int)$payParam['price'];
    $user_id    = (int)$payParam['user_id'];
    $payType    = $payParam['type'];
    $description= $payParam['description'];
    $hash_id    = $payParam['hash_id'];

    $plan_id    = (int)$payParam['plan_id'];
    $volume     = (int)$payParam['volume'];
    $days       = (int)$payParam['day'];
    $agentBought= (int)($payParam['agent_bought'] ?? 0);

    $payDescription = payTypeToDescription($payType);
    if ($gateType == "zarinpal" || $gateType == "nextpay") $payDescription = \achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.type.e6343571a4');

    if ($gateType === 'nowpayment') {
        $stmt=$connection->prepare("SELECT local_amount_toman FROM payment_attempts
            WHERE pay_row_id=? AND provider='nowpayments' AND stage='fulfilling' LIMIT 1");
        $stmt->bind_param('i',$rowId);
        $stmt->execute();
        $expected=$stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$expected || (int)$expected['local_amount_toman'] <= 0 ||
            (int)$expected['local_amount_toman'] !== $amount) {
            showForm(\achbotMessage('gateway.gateway_return.perform_payment_effect.message.c79ed0ecc0'), \achbotMessage('gateway.gateway_return.np_reconcile_batch.message.da0bc406d8'), false, 'review');
            return false;
        }
    }

    if ($payType === 'INCREASE_WALLET') {
        // The state claim and actual user credit commit together. Telegram
        // notification remains outside the DB transaction.
        $claimed = achbotClaimAndCreditWallet(
            $connection, $rowId, $user_id, $amount
        ) ? 1 : 0;
    } elseif (in_array($gateType,['nextpay','zarinpal'],true)) {
        // Stage a durable operator-review marker in the SAME local commit as
        // claiming paid, before any non-atomic remote Marzban service effect.
        $claimed=achbotClaimHostedServiceForReview(
            $connection,$rowId,$amount,$gateType,$hash_id
        ) ? 1 : 0;
    } else {
        // NOWPayments has its own fulfilling/review worker boundary.
        $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'paid'
            WHERE `id` = ? AND `price`=? AND `state` IN ('pending','send')");
        $stmt->bind_param('ii',$payRowId,$amount);
        $stmt->execute();
        $claimed=$stmt->affected_rows;
        $stmt->close();
    }
    if ($claimed !== 1) {
        if ($payType!=='INCREASE_WALLET' &&
            in_array($gateType,['nextpay','zarinpal'],true)) {
            // Provider verification has succeeded, but neither a local paid
            // claim nor an operator-review row is guaranteed to exist.
            showForm(\achbotMessage('gateway.gateway_return.perform_payment_effect.message.a64929caf4'),
                     \achbotMessage('gateway.gateway_return.np_reconcile_batch.message.da0bc406d8'), false, 'review');
            return 'ambiguous';
        }
        showForm(\achbotMessage('gateway.gateway_return.perform_payment_effect.message.e3ee1490e9'), \achbotMessage('gateway.gateway_return.np_reconcile_batch.message.da0bc406d8'), false, 'review');
        return false;
    }

    // From this point onward a hosted provider effect may be underway. A
    // later exception must never reopen a fulfilled or uncertain payment.
    $effectStage='postclaim';

    if ($payType == "BUY_SUB") {

        $fid = $plan_id;

        $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $userinfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $planDetails = getPlanDetails($connection, $fid);
        $file_detail = $planDetails['plan'];

        if ($volume == 0 && $days == 0) {
            $volume = (int)$file_detail['volume'];
            $days   = (int)$file_detail['days'];
        }

        $date = time();
        $expire_microdate = floor(microtime(true) * 1000) + (864000 * $days * 100);
        $expire_date = $date + (86400 * $days);

        $server_id  = (int)$file_detail['server_id'];
        $netType    = $file_detail['type'];
        $acount     = (int)$file_detail['acount'];
        $protocol   = $file_detail['protocol'];
        $inbound_id = (int)$file_detail['inbound_id'];
        $limitip    = $file_detail['limitip'];
        $rahgozar   = $file_detail['rahgozar'];
        $customPath = $file_detail['custom_path'];
        $customPort = $file_detail['custom_port'];
        $customSni  = $file_detail['custom_sni'];

        if ((int)($payParam['agent_count']??0)<0)
            throw new RuntimeException('hosted_invalid_account_quantity');
        $accountCount = (int)($payParam['agent_count']??0)>0
            ? (int)$payParam['agent_count'] : 1;
        if ($accountCount<=0) throw new RuntimeException('hosted_invalid_account_quantity');

        if ($acount == 0 && $inbound_id != 0) {


            if (!refundToWalletAndNotify(
                $connection,
                $user_id,
                $amount,
                $gateType,
                $admin,
                \achbotMessage('gateway.gateway_return.perform_payment_effect.text.97b1eb9941'),
				$rewardChannel
            )) {
                showForm(\achbotMessage('gateway.gateway_return.perform_payment_effect.message.bd952e9bc9'), $payDescription, false, 'review');
                return 'ambiguous';
            }
            showForm(\achbotMessage('gateway.gateway_return.perform_payment_effect.message.cf0e1ca461') . number_format($amount) . \achbotMessage('gateway.gateway_return.perform_payment_effect.message.606f350948'), $payDescription, false, 'review');

            return 'compensated';
        }

        // Never infer full-count inventory from a preflight ucount>0 read.
        // The first 007 CREATE marker atomically reserves ALL accountCount
        // slots on server_info inside its own committed payment-owned
        // transaction, shared with manually reviewed buyer reservations.
        // Insufficient/uncertain capacity after verified provider payment
        // must remain operator-reviewable, without a speculative refund.

        [$serverInfo, $serverConfig] = getServerInfoAndConfig($connection, $server_id);
        $srv_remark = $serverInfo['remark'] ?? '';
        $serverType = $serverConfig['type'] ?? '';
        $portType   = $serverConfig['port_type'] ?? 'manual';

        for ($i = 1; $i <= $accountCount; $i++) {

            $uniqid = generateRandomString(42, $protocol);

            [$port] = getNextPortAndIncrease(dirname(__DIR__, 2) . '/settings/temp.txt', $portType);

            $forcedRemark = (!empty($description)) ? $description : null;
            $remark = buildRemark($botState, $srv_remark, $description, $user_id, $forcedRemark);
            // An explicit repeated name across a multi-account order would
            // previously trigger random renames on 409. Pin each line first.
            if ($accountCount > 1 && $forcedRemark !== null) {
                $remark .= '_'.$i;
            }

            // First-use expiry staging was exclusive to never-deployed
            // Sanaei/Alireza inbound plans. Marzban keeps normal expiry.
            $expire_microdate_local = $expire_microdate;

            [$response, $remark, $port, $effectId] = achbotCreatePaidAccount(
                $connection, $rowId, $i, [
                    'user_id'=>$user_id,'server_type'=>$serverType,'server_id'=>$server_id,
                    'inbound_id'=>$inbound_id,'plan_id'=>$fid,
                    'remark'=>$remark,'volume'=>$volume,'days'=>$days,
                    'uniqid'=>$uniqid,'protocol'=>$protocol,'port'=>$port,
                    'expire_microdate'=>$expire_microdate_local,'net_type'=>$netType,
                    'rahgozar'=>$rahgozar,'limitip'=>$limitip
                ]
            );

            if (is_null($response)) {
                if (achbotNpDeferUncertainRemoteEffect($gateType, $payDescription)) return 'ambiguous';


                if (!refundToWalletAndNotify(
                    $connection,
                    $user_id,
                    $amount,
                    $gateType,
                    $admin,
                    \achbotMessage('gateway.gateway_return.perform_payment_effect.text.bcbe393f99'),
					$rewardChannel
                )) {
                    showForm(\achbotMessage('gateway.gateway_return.perform_payment_effect.message.bd952e9bc9'), $payDescription, false, 'review');
                    return 'ambiguous';
                }
                showForm(\achbotMessage('gateway.gateway_return.perform_payment_effect.message.6ae4e71c95') . number_format($amount) . \achbotMessage('gateway.gateway_return.perform_payment_effect.message.c042799f80'), $payDescription, false, 'review');

                return 'compensated';
            }

            if ($response == "inbound not Found") {
                if (achbotNpDeferUncertainRemoteEffect($gateType, $payDescription)) return 'ambiguous';


                if (!refundToWalletAndNotify(
                    $connection,
                    $user_id,
                    $amount,
                    $gateType,
                    $admin,
                    \achbotMessage('gateway.gateway_return.perform_payment_effect.text.ed588b3bc9', ['inbound_id' => $inbound_id]),
					$rewardChannel
                )) {
                    showForm(\achbotMessage('gateway.gateway_return.perform_payment_effect.message.bd952e9bc9'), $payDescription, false, 'review');
                    return 'ambiguous';
                }
                showForm(\achbotMessage('gateway.gateway_return.perform_payment_effect.message.3768e94d51') . number_format($amount) . \achbotMessage('gateway.gateway_return.perform_payment_effect.message.3828187c8e'), $payDescription, false, 'review');

                return 'compensated';
            }

            $ok = true;
            $msg = '';
            if (is_object($response)) {
                $ok = !empty($response->success);
                $msg = $response->msg ?? '';
            } elseif (is_array($response)) {
                $ok = !empty($response['success']);
                $msg = $response['msg'] ?? '';
            }

            if (!$ok) {
                if (achbotNpDeferUncertainRemoteEffect($gateType, $payDescription)) return 'ambiguous';


                $adminErr = \achbotMessage('gateway.gateway_return.perform_payment_effect.admin_err.7ac91c73da') . ($serverInfo['title'] ?? '-') . ":\n\n" . ($msg ?: \achbotMessage('gateway.gateway_return.perform_payment_effect.text.9d93e5efb8'));
				notifyAllAdminsSimple($adminErr, $admin, "html", null, $rewardChannel);

                if (!refundToWalletAndNotify(
                    $connection,
                    $user_id,
                    $amount,
                    $gateType,
                    $admin,
                    \achbotMessage('gateway.gateway_return.perform_payment_effect.text.e3675596f3'),
					$rewardChannel
                )) {
                    showForm(\achbotMessage('gateway.gateway_return.perform_payment_effect.message.bd952e9bc9'), $payDescription, false, 'review');
                    return 'ambiguous';
                }
                showForm(\achbotMessage('gateway.gateway_return.perform_payment_effect.message.7ea4d9ee52') . number_format($amount) . \achbotMessage('gateway.gateway_return.perform_payment_effect.message.a0b19d8503'), $payDescription, false, 'review');

                return 'compensated';
            }

            [$token, $vray_link, $uniqid] = buildLinksAndSendQr(
                $serverType,
                $serverConfig,
                $botState,
                $botUrl,
                $server_id,
                $uniqid,
                $protocol,
                $remark,
                $port,
                $netType,
                $inbound_id,
                $rahgozar,
                $customPath,
                $customPort,
                $customSni,
                $response,
                $user_id,
                $volume,
                $days,
                $buttonValuesLocal,
                [],
                false,
                "pay/",
                dirname(__DIR__, 2) . '/pay'
            );

            // Reserve remote identity before POST, but bind the resulting
            // order and its effect together in one SHORT local SQL commit.
            // Never leave an unlinked successful order after a link error.
            if ($connection->begin_transaction() !== true)
                throw new RuntimeException('hosted_effect_order_begin_unconfirmed');
            try {
                $orderId = insertOrder(
                    $connection,
                    $user_id,
                    $token,
                    $fid,
                    $server_id,
                    $inbound_id,
                    $remark,
                    $uniqid,
                    $expire_date,
                    $vray_link,
                    achbotAllocateOrderAmount((int)$amount,$accountCount,$i),
                    time(),
                    $agentBought,
                    true
                );
                achbotLinkPaidAccountEffect($connection,$effectId,$orderId);
                if ($connection->commit() !== true)
                    throw new RuntimeException('hosted_effect_order_commit_unconfirmed');
            } catch (Throwable $error) {
                try { $connection->rollback(); } catch (Throwable $ignored) {}
                // Local COMMIT acknowledgement might be lost: remote CREATE
                // is never retried, and the payment keeps its review fence.
                throw $error;
            }
        }

        // The whole count was reserved in a payment-scoped 007 transaction
        // BEFORE remote CREATE; never deduct the same capacity again.
        // The completed paid/remote operation is not proof that Telegram
        // subscription details reached the customer.
        if (!empty($userinfo['refered_by'])) {
            $inviterId=(int)$userinfo['refered_by'];
            if (giveReferralReward($connection,$user_id,$inviterId,
                    $admin,$botState,null,$rowId)!==true) {
                error_log('achbot_gateway_referral_needs_review pay_id='.$rowId);
                achbotReferralWarnOperator((int)$admin,(int)$rowId);
            }
        }

        showForm(\achbotMessage('gateway.gateway_return.perform_payment_effect.message.effdee0c2d'),
                 $payDescription, true, 'review');

        $user_info = Bot('getChat', ['chat_id' => $user_id])->result;
        $first_name = $user_info->first_name ?? '-';
        $username = $user_info->username ?? '-';

        $keys = json_encode(['inline_keyboard' => [
            [
                ['text' => \achbotMessage('gateway.gateway_return.perform_payment_effect.button.b9d052f74c', ['gateType' => $gateType]), 'callback_data' => 'wizwizch'],
            ],
            [
                ['text' => \achbotMessage('gateway.gateway_return.perform_payment_effect.hash_id.2899228604', ['hash_id' => $hash_id]), 'callback_data' => "noop_$hash_id"]
            ]
        ]]);

        $msg = \achbotMessage('gateway.gateway_return.perform_payment_effect.msg.8205ac68fa', ['gateType' => $gateType, 'user_id' => $user_id, 'first_name' => $first_name, 'username' => $username, 'amount' => $amount, 'remark' => $remark, 'volume' => $volume, 'days' => $days]);
		notifyAllAdminsSimple($msg, $admin, "html", $keys, $rewardChannel);
    }

    elseif ($payType == "INCREASE_WALLET") {

        // Credit was already committed atomically with pays.state='paid'.
        // Repeating it here would double-credit on the same verified event.
        showForm(\achbotMessage('gateway.gateway_return.perform_payment_effect.message.bbf27ebefd') . number_format($amount) . \achbotMessage('gateway.gateway_return.perform_payment_effect.message.606f350948'), $payDescription, true, 'completed');

        sendMessage(\achbotMessage('gateway.gateway_return.perform_payment_effect.message.a5b15e454e') . number_format($amount) . \achbotMessageHtml('gateway.gateway_return.perform_payment_effect.message.dd866497c7', ['supportID' => $supportID]), null, "html", $user_id);

        $adminText = \achbotMessage('gateway.gateway_return.perform_payment_effect.message.a5b15e454e') . number_format($amount) . \achbotMessage('gateway.gateway_return.perform_payment_effect.text.3908dcee07', ['user_id' => $user_id, 'gateType' => $gateType]);
		notifyAllAdminsSimple($adminText, $admin, "html", null, $rewardChannel);
    }

    elseif ($payType == "RENEW_ACCOUNT") {

        $oid = (int)$payParam['order_id'];

        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
        $stmt->bind_param("i", $oid);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$order || (int)$order['userid']!==$user_id ||
            (int)$order['status']!==1 ||
            !achbotSupportsOrder($connection,$oid))
            throw new RuntimeException('hosted_renew_order_ineligible');
        $fid = (int)$order['fileid'];
        $remark = $order['remark'];
        $uuid = $order['uuid'] ?? "0";
        $server_id = (int)$order['server_id'];
        $inbound_id = (int)$order['inbound_id'];

        $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ? AND `active` = 1");
        $stmt->bind_param("i", $fid);
        $stmt->execute();
        $respd = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $days = (int)$respd['days'];
        $volume = (int)$respd['volume'];

        [$srvInfo, $srvConfig] = getServerInfoAndConfig($connection, $server_id);
        $serverType = $srvConfig['type'] ?? '';

        $isMarzban = ($serverType === "marzban");

        $response = renewConfigOnServer($connection, $server_id, $inbound_id, $uuid, $remark, $days, $volume, $isMarzban, $rowId);

        if (is_null($response)) {
            // A lost provider response does not prove that renewal failed.
            achbotNpDeferUncertainRemoteEffect($gateType,$payDescription);
            return 'ambiguous';
        }

        // Every supported provider requires a positively verified remote
        // renewal. A non-null success=false response must never extend the
        // local account or announce successful delivery.
        if (!is_object($response) || empty($response->success)) {
            if (achbotNpDeferUncertainRemoteEffect($gateType, $payDescription)) return 'ambiguous';
            return 'ambiguous';
        }

        if (($response->verified??false)!==true)
            throw new RuntimeException('hosted_renew_provider_unverified');
        if ($connection->begin_transaction()!==true)
            throw new RuntimeException('hosted_renew_finish_begin_failed');
        try {
            $stmt=$connection->prepare("SELECT id,state,user_id,type,hash_id,price
                FROM pays WHERE id=? FOR UPDATE");
            $stmt->bind_param('i',$rowId);
            $ok=$stmt->execute();
            $locked=$ok?$stmt->get_result()->fetch_assoc():null;
            $stmt->close();
            if (!$locked || $locked['state']!=='paid' ||
                (int)$locked['user_id']!==$user_id ||
                (int)$locked['price']!==$amount ||
                (string)$locked['type']!==$payType ||
                (string)$locked['hash_id']!==$hash_id)
                throw new RuntimeException('hosted_renew_paid_claim_stale');
            achbotStoreVerifiedServiceExpiryLocked($connection,$order,$response);
            createIncreaseOrder($connection,$user_id,$server_id,$inbound_id,
                                $remark,$amount,$time,null,null,true);
            if ($connection->commit()!==true)
                throw new RuntimeException('hosted_renew_commit_unconfirmed');
        } catch (Throwable $error) {
            try {$connection->rollback();} catch (Throwable $ignored) {}
            throw $error;
        }

        showForm(\achbotMessage('gateway.gateway_return.perform_payment_effect.message.a3b46e7d37', ['remark' => $remark]), $payDescription, true, 'completed');
        sendMessage(\achbotMessage('gateway.gateway_return.perform_payment_effect.message.a3b46e7d37', ['remark' => $remark]), json_encode(['inline_keyboard' => [[['text' => \achbotMessage('gateway.gateway_return.perform_payment_effect.text.275b4903d9'), 'callback_data' => "mainMenu"]]]]), null, $user_id);

        $keys = json_encode(['inline_keyboard' => [
            [
                ['text' => \achbotMessage('gateway.gateway_return.perform_payment_effect.button.b9d052f74c', ['gateType' => $gateType]), 'callback_data' => 'wizwizch'],
            ],
            [
                ['text' => \achbotMessage('gateway.gateway_return.perform_payment_effect.hash_id.2899228604', ['hash_id' => $hash_id]), 'callback_data' => "noop_$hash_id"]
            ]
        ]]);

        $user_info = Bot('getChat', ['chat_id' => $user_id])->result;
        $first_name = $user_info->first_name ?? '-';
        $username = $user_info->username ?? '-';

        $msg = \achbotMessage('gateway.gateway_return.perform_payment_effect.msg.c281132242', ['gateType' => $gateType, 'user_id' => $user_id, 'first_name' => $first_name, 'username' => $username, 'amount' => $amount, 'remark' => $remark, 'volume' => $volume, 'days' => $days]);
		notifyAllAdminsSimple($msg, $admin, "html", $keys, $rewardChannel);
        return 'delivered';
    }

    elseif (preg_match('/^INCREASE_DAY_(\d+)_(\d+)/', $payType, $match)) {

        $orderId = (int)$match[1];

        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
        $stmt->bind_param("i", $orderId);
        $stmt->execute();
        $orderInfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // The pays claim was durably staged before entering this branch.
        // Preserve the exact pre-remote local identity for later CAS, not a
        // fresh order snapshot taken after the external effect.
        if (!$orderInfo || (int)$orderInfo['id']!==$orderId ||
            (int)$orderInfo['userid']!==$user_id ||
            (int)$orderInfo['status']!==1 ||
            !achbotSupportsOrder($connection,$orderId))
            throw new RuntimeException('hosted_day_order_ineligible');
        $server_id  = (int)$orderInfo['server_id'];
        $inbound_id = (int)$orderInfo['inbound_id'];
        $remark     = $orderInfo['remark'];
        $uuid       = $orderInfo['uuid'] ?? "0";
        $planid     = (int)$match[2];

        $stmt = $connection->prepare("SELECT * FROM `increase_day` WHERE `id` = ?");
        $stmt->bind_param("i", $planid);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $plusDays = achbotPinnedAddonUnits($payParam);
        if ($plusDays===null) throw new RuntimeException('service_quote_units_unverified');

        [$srvInfo, $srvConfig] = getServerInfoAndConfig($connection, $server_id);
        $serverType = $srvConfig['type'] ?? '';

        if (achbotSupportsPanel($serverType)) {
            $response = achbotApplyServiceEdit($connection,$server_id, ['pay_id'=>(int)$rowId, 'remark' => $remark, 'plus_day' => $plusDays]);
        } else {
            throw new \UnexpectedValueException('unsupported_panel');
        }

        if (!empty($response->success)) {
            if (!is_object($response) || ($response->verified??false)!==true)
                throw new RuntimeException('hosted_day_provider_unverified');
            // Finalize order + audit in one transaction, before returning an
            // eligible 'delivered' outcome to the hosted settlement manager.
            if ($connection->begin_transaction()!==true)
                throw new RuntimeException('hosted_day_finish_begin_failed');
            try {
                $stmt=$connection->prepare("SELECT id,state,user_id,type,hash_id,price
                    FROM pays WHERE id=? FOR UPDATE");
                $stmt->bind_param('i',$rowId);
                $ok=$stmt->execute();
                $locked=$ok?$stmt->get_result()->fetch_assoc():null;
                $stmt->close();
                if (!$locked || $locked['state']!=='paid' ||
                    (int)$locked['user_id']!==$user_id ||
                    (int)$locked['price']!==$amount ||
                    (string)$locked['type']!==$payType ||
                    (string)$locked['hash_id']!==$hash_id)
                    throw new RuntimeException('hosted_day_paid_claim_stale');
                achbotStoreVerifiedServiceExpiryLocked($connection,$orderInfo,$response);
                $time2=time();
                createIncreaseOrder($connection,$user_id,$server_id,
                    $inbound_id,$remark,$amount,$time2,null,$plusDays,true);
                if ($connection->commit()!==true)
                    throw new RuntimeException('hosted_day_finish_commit_unconfirmed');
            } catch (Throwable $error) {
                try {$connection->rollback();} catch (Throwable $ignored) {}
                throw $error; // previously staged paid/review claim persists
            }

            showForm(\achbotMessage('gateway.gateway_return.perform_payment_effect.message.8ab1d455cf', ['plusDays' => $plusDays]), $payDescription, true, 'completed');
            sendMessage(\achbotMessage('gateway.gateway_return.perform_payment_effect.message.8ab1d455cf', ['plusDays' => $plusDays]), json_encode(['inline_keyboard' => [[['text' => \achbotMessage('gateway.gateway_return.perform_payment_effect.text.275b4903d9'), 'callback_data' => "mainMenu"]]]]), null, $user_id);

            $keys = json_encode(['inline_keyboard' => [
                [
                    ['text' => \achbotMessage('gateway.gateway_return.perform_payment_effect.button.b9d052f74c', ['gateType' => $gateType]), 'callback_data' => 'wizwizch'],
                ],
                [
                    ['text' => \achbotMessage('gateway.gateway_return.perform_payment_effect.hash_id.2899228604', ['hash_id' => $hash_id]), 'callback_data' => "noop_$hash_id"]
                ]
            ]]);

            $user_info = Bot('getChat', ['chat_id' => $user_id])->result;
            $first_name = $user_info->first_name ?? '-';
            $username = $user_info->username ?? '-';

            $msg = \achbotMessage('gateway.gateway_return.perform_payment_effect.msg.3337e72606', ['gateType' => $gateType, 'user_id' => $user_id, 'first_name' => $first_name, 'username' => $username, 'amount' => $amount, 'remark' => $remark, 'plusDays' => $plusDays]);
            notifyAllAdminsSimple($msg, $admin, "html", $keys, $rewardChannel);
			return 'delivered';

        } else {
            if (achbotNpDeferUncertainRemoteEffect($gateType, $payDescription)) return 'ambiguous';


            if (!refundToWalletAndNotify(
                $connection,
                $user_id,
                $amount,
                $gateType,
                $admin,
                \achbotMessage('gateway.gateway_return.perform_payment_effect.text.1d7ceca732'),
				$rewardChannel
            )) {
                showForm(\achbotMessage('gateway.gateway_return.perform_payment_effect.message.bd952e9bc9'), $payDescription, false, 'review');
                return 'ambiguous';
            }
            showForm(\achbotMessage('gateway.gateway_return.perform_payment_effect.message.9f4f326fa4') . number_format($amount) . \achbotMessage('gateway.gateway_return.perform_payment_effect.message.606f350948'), $payDescription, false, 'review');

            return 'compensated';
        }
    }

    elseif (preg_match('/^INCREASE_VOLUME_(\d+)_(\d+)/', $payType, $match)) {

        $orderId = (int)$match[1];

        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
        $stmt->bind_param("i", $orderId);
        $stmt->execute();
        $orderInfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $server_id  = (int)$orderInfo['server_id'];
        $inbound_id = (int)$orderInfo['inbound_id'];
        $remark     = $orderInfo['remark'];
        $uuid       = $orderInfo['uuid'] ?? "0";
        $planid     = (int)$match[2];

        $stmt = $connection->prepare("SELECT * FROM `increase_plan` WHERE `id` = ?");
        $stmt->bind_param("i", $planid);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $plusVolume = achbotPinnedAddonUnits($payParam);
        if ($plusVolume===null) throw new RuntimeException('unsupported_service_units');

        [$srvInfo, $srvConfig] = getServerInfoAndConfig($connection, $server_id);
        $serverType = $srvConfig['type'] ?? '';

        if (achbotSupportsPanel($serverType)) {
            $response = achbotApplyServiceEdit($connection,$server_id, ['pay_id'=>(int)$rowId, 'remark' => $remark, 'plus_volume' => $plusVolume]);
        } else {
            throw new \UnexpectedValueException('unsupported_panel');
        }

        if (!empty($response->success)) {

            $stmt = $connection->prepare("UPDATE `orders_list` SET `notif` = 0 WHERE `uuid` = ?");
            $stmt->bind_param("s", $uuid);
            $saved=$stmt->execute();
            $stmt->close();
            if ($saved!==true)
                throw new RuntimeException('volume_extension_update_unconfirmed');

            $time2 = time();
            createIncreaseOrder($connection, $user_id, $server_id, $inbound_id, $remark, $amount, $time2, $plusVolume, null, true);

            showForm(\achbotMessage('gateway.gateway_return.perform_payment_effect.message.20ac3e8049', ['plusVolume' => $plusVolume]), $payDescription, true, 'completed');
            sendMessage(\achbotMessage('gateway.gateway_return.perform_payment_effect.message.20ac3e8049', ['plusVolume' => $plusVolume]), json_encode(['inline_keyboard' => [[['text' => \achbotMessage('gateway.gateway_return.perform_payment_effect.text.275b4903d9'), 'callback_data' => "mainMenu"]]]]), null, $user_id);

            $keys = json_encode(['inline_keyboard' => [
                [
                    ['text' => \achbotMessage('gateway.gateway_return.perform_payment_effect.button.b9d052f74c', ['gateType' => $gateType]), 'callback_data' => 'wizwizch'],
                ],
                [
                    ['text' => \achbotMessage('gateway.gateway_return.perform_payment_effect.hash_id.2899228604', ['hash_id' => $hash_id]), 'callback_data' => "noop_$hash_id"]
                ]
            ]]);

            $user_info = Bot('getChat', ['chat_id' => $user_id])->result;
            $first_name = $user_info->first_name ?? '-';
            $username = $user_info->username ?? '-';

            $msg = \achbotMessage('gateway.gateway_return.perform_payment_effect.msg.05d5688689', ['gateType' => $gateType, 'user_id' => $user_id, 'first_name' => $first_name, 'username' => $username, 'amount' => $amount, 'remark' => $remark, 'plusVolume' => $plusVolume]);
			notifyAllAdminsSimple($msg, $admin, "html", $keys, $rewardChannel);
            return 'delivered';

        } else {
            if (achbotNpDeferUncertainRemoteEffect($gateType, $payDescription)) return 'ambiguous';


            if (!refundToWalletAndNotify(
                $connection,
                $user_id,
                $amount,
                $gateType,
                $admin,
                \achbotMessage('gateway.gateway_return.perform_payment_effect.text.c7546a7b9b'),
				$rewardChannel
            )) {
                showForm(\achbotMessage('gateway.gateway_return.perform_payment_effect.message.bd952e9bc9'), $payDescription, false, 'review');
                return 'ambiguous';
            }
            showForm(\achbotMessage('gateway.gateway_return.perform_payment_effect.message.28ba281162') . number_format($amount) . \achbotMessage('gateway.gateway_return.perform_payment_effect.message.606f350948'), $payDescription, false, 'review');

            return 'compensated';
        }
    }
	
	elseif ($payType === "RENEW_SCONFIG") {

		$rewardChannel = $botState['rewardChannel'] ?? null;

		$fid = (int)$plan_id;
		$planDetails = getPlanDetails($connection, $fid);
		$plan   = $planDetails['plan'];
		$days   = $planDetails['days'];
		$volume = $planDetails['volume'];

		$server_id = (int)$plan['server_id'];

		$configInfo = json_decode($description, true) ?: [];
		$uuid      = $configInfo['uuid']   ?? '';
		$remark    = $configInfo['remark'] ?? '';
		$isMarzban = !empty($configInfo['marzban']);

		$inbound_id = (int)$volume;
		$inbound_id = (int)$payParam['volume'];

		try {
			[$serverInfo, $serverConfig] = getServerInfoAndConfig($connection, $server_id);

			$response = renewConfigOnServer(
				$connection,
				$server_id,
				$inbound_id,
				$uuid,
				$remark,
				$days,
				$volume,
				$isMarzban
			);

			ensureAccountCreationSucceeded($response, $serverInfo, $inbound_id, $admin, true);

			createIncreaseOrder(
				$connection,
				$user_id,
				$server_id,
				$inbound_id,
				$remark,
				$amount,
				$time,
				$volume,
				$days,
				true
			);

			showForm(\achbotMessage('gateway.gateway_return.perform_payment_effect.message.a3b46e7d37', ['remark' => $remark]), $payDescription, true, 'completed');
			sendMessage(\achbotMessage('gateway.gateway_return.perform_payment_effect.message.a3b46e7d37', ['remark' => $remark]),
				json_encode(['inline_keyboard' => [[['text' => \achbotMessage('gateway.gateway_return.perform_payment_effect.button.82f7af5445'), 'callback_data' => "mainMenu"]]]]),
				null,
				$user_id
			);

			$adminText = \achbotMessage('gateway.gateway_return.perform_payment_effect.admin_text.b660a2780f', ['gateType' => $gateType])
				. \achbotMessage('gateway.gateway_return.perform_payment_effect.user_i_d_user_id.8fd1cfbf5d', ['user_id' => $user_id])
				. \achbotMessage('gateway.gateway_return.perform_payment_effect.remark_remark.806b185dfd', ['remark' => $remark])
				. \achbotMessage('gateway.gateway_return.perform_payment_effect.plan_i_d_fid.1471242965', ['fid' => $fid])
				. \achbotMessage('gateway.gateway_return.perform_payment_effect.amount.ad7e6bbc81') . number_format($amount) . "\n"
				. \achbotMessage('gateway.gateway_return.perform_payment_effect.text.a918020ba1', ['volume' => $volume])
				. \achbotMessage('gateway.gateway_return.perform_payment_effect.text.dc6dc4aa59', ['days' => $days])
				. \achbotMessage('gateway.gateway_return.perform_payment_effect.hash_i_d_hash_id.89efdc649b', ['hash_id' => $hash_id]);

			notifyAllAdminsSimple($adminText, $admin, null, null, $rewardChannel);

			return 'delivered';

		} catch (Exception $e) {

            if (achbotNpDeferUncertainRemoteEffect($gateType, $payDescription)) return 'ambiguous';
			$reason = \achbotMessage('gateway.gateway_return.perform_payment_effect.reason.3b9fd32170')
				. \achbotMessage('gateway.gateway_return.perform_payment_effect.text.9eb6edd609')
				. \achbotMessage('gateway.gateway_return.perform_payment_effect.text.c957e6dfa7') . $e->getMessage();



			if (!refundToWalletAndNotify($connection, $user_id, $amount, $gateType, $reason, $admin, $rewardChannel)) {
			    showForm(\achbotMessage('gateway.gateway_return.perform_payment_effect.message.bd952e9bc9'), $payDescription, false, 'review');
			    return 'ambiguous';
			}
			showForm(\achbotMessage('gateway.gateway_return.perform_payment_effect.message.68e2abdadb') . number_format($amount) . \achbotMessage('gateway.gateway_return.perform_payment_effect.message.606f350948'), $payDescription, false, 'review');


			return 'compensated';
		}
	}

    sendMessage(\achbotMessage('gateway.gateway_return.perform_payment_effect.message.81c4bb3acb'), json_encode(['inline_keyboard' => [[['text' => \achbotMessage('gateway.gateway_return.perform_payment_effect.text.275b4903d9'), 'callback_data' => "mainMenu"]]]]), null, $user_id);
    return 'delivered';
}

/**
 * Keep the existing public payment action entrypoint stable. Never close
 * an attempt as delivered when the underlying effect throws or is uncertain.
 */
function doAction($payRowId, $gateType) {
    $effectStage='preclaim';
    try {
        $outcome=achbotPerformPaymentEffect($payRowId,$gateType,$effectStage);
    } catch (Throwable $error) {
        if (!in_array($gateType,['nextpay','zarinpal'],true)) throw $error;
        // Even an upstream-verified charge can fail on the *first* local
        // SELECT, before a durable review record exists. A post-claim throw
        // may instead follow a real remote side effect. Neither is safe to
        // retry, refund, or describe as an already-recorded review request.
        $afterClaim=$effectStage==='postclaim';
        $event=$afterClaim
            ? 'achbot_hosted_service_effect_needs_review'
            : 'achbot_hosted_claim_unconfirmed';
        $reason=$afterClaim
            ? 'postclaim_effect_exception'
            : 'preclaim_local_error';
        error_log($event.' pay_id='.(int)$payRowId.
                  ' provider='.$gateType.' code='.$reason);
        $message=$afterClaim
            ? \achbotMessage('gateway.gateway_return.do_action.text.83188c9e00')
            : \achbotMessage('gateway.gateway_return.do_action.text.30e9647ee1');
        showForm($message,\achbotMessage('gateway.gateway_return.np_reconcile_batch.message.da0bc406d8'),false,'review');
        return 'ambiguous';
    }
    if (in_array($gateType,['nextpay','zarinpal'],true) &&
        in_array($outcome,['delivered','compensated'],true)) {
        global $connection;
        achbotFinishHostedServiceReview($connection,(int)$payRowId,$gateType,$outcome);
    }
    return $outcome;
}

/**
 * Shared browser feedback surface for every supported redirect gateway.
 * Rendering a return page is never itself merchant settlement evidence.
 */
function showForm($msg, $type = "", $state = false, $presentation = null) {
    require_once __DIR__ . '/PaymentPage.php';
    // $presentation is derived from the caller's already-trusted outcome.
    // The legacy $state and browser-facing message are not proof of delivery.
    achbotRenderPaymentPage($msg, $type, $state, $presentation);
}
