<?php
/** AChBot V8: source-pinned financial compatibility module. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/GatewayClient.php';


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
        $connection->begin_transaction();
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
            $stmt->execute();
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
            $stmt->execute();
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
            'INCREASE_WALLET' => 'شارژ کیف پول',
            'BUY_SUB' => 'خرید اشتراک',
            'RENEW_ACCOUNT' => 'تمدید اشتراک',
            default => 'پرداخت سفارش',
        };
        $reason=match ((string)$a['provider_status']) {
            'additional_payment','multiple_payments' =>
                'شواهد پرداخت دیگری برای همین فاکتور دریافت شده است',
            'partially_paid' => 'مبلغی دریافت شده اما پرداخت کامل نیست',
            'fulfillment_failed','fulfillment_unconfirmed',
            'fulfillment_needs_review' => 'نتیجهٔ تحویل یا اعتباردهی قطعی نیست',
            default => 'وضعیت دریافت وجه یا سفارش نیاز به تطبیق دارد',
        };
        $username=trim((string)($a['user_handle']??''),'@');
        $identity=$safe($a['user_name'] ?: 'نامشخص').
            ($username!=='' ? ' (@'.$safe($username).')':'');
        $quote=$safe($a['quote_amount']).' '.$safe($a['quote_currency']);
        $message="⚠️ <b>پرداخت نیازمند بررسی مالی</b>\n\n".
                 "👤 <b>کاربر:</b> ".$identity."\n".
                 "🆔 <b>شناسه تلگرام:</b> <code>".(int)$a['user_id']."</code>\n".
                 "🧾 <b>موضوع:</b> ".$subject.
                 (!empty($a['plan_title']) ? ' ـ '.$safe($a['plan_title']):'')."\n".
                 "💰 <b>مبلغ سفارش:</b> ".number_format((int)$a['price'])." تومان\n".
                 "💱 <b>مبلغ فاکتور:</b> ".$quote."\n".
                 "💳 <b>درگاه:</b> NOWPayments\n".
                 "🔖 <b>سفارش:</b> <code>".$safe($a['order_hash'])."</code>\n".
                 "🔗 <b>فاکتور درگاه:</b> <code>".$safe($a['provider_reference'])."</code>\n".
                 "📌 <b>شناسه اصلی:</b> <code>".$safe($a['payment_id'] ?: 'ثبت نشده')."</code>\n".
                 "📊 <b>شناسه‌های گزارش‌شده:</b> ".$childCount.
                 " (شواهد وجه اضافی: ".(int)$a['extra_evidence_count'].")\n".
                 "⚠️ <b>علت:</b> ".$reason."\n".
                 "🕓 <b>آخرین تغییر:</b> ".$safe($a['updated_at'])."\n\n".
                 "برای تصمیم مالی ابتدا مبلغ و وضعیت واقعی وجوه و ".
                 "نتیجهٔ تحویل را تطبیق دهید؛ این اعلان به‌معنی پرداخت موفق دوم نیست.";
        $keyboard=json_encode(['inline_keyboard'=>[[
            ['text'=>'🔍 مشاهده و پیگیری پرونده',
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
            showForm("کد پرداخت نامعتبر است.", "خطا!");
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
            showForm("تایید این پرداخت برای سرویس غیرقابل پشتیبانی متوقف شد؛ با پشتیبانی تماس بگیرید.", "خطا");
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
                showForm("درخواست پرداخت ثبت شده و بدون نیاز به این صفحه، خودکار بررسی می‌شود.",
                         "پیگیری پرداخت");
                return;
            }
        }

        // Historical invoices lack an immutable source-captured quote and
        // payout policy. Even an authenticated 'finished' GET cannot prove
        // that this particular order's agreed fiat amount was settled.
        // Never auto-credit or auto-cancel from a legacy browser callback.
        showForm("این فاکتور قدیمی نیازمند بررسی تایید مبلغ توسط پشتیبانی است؛ وضعیت آن بدون تغییر محفوظ ماند.",
                 "پیگیری پرداخت");
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
            showForm("پرداخت شما ثبت شده است؛ نتیجه و لینک سرویس در ربات ارسال می‌شود.",
                     "پرداخت ثبت‌شده", true);
        } elseif ($state === 'pending' || $state === 'send') {
            showForm("پرداخت در حال بررسی خودکار است؛ برای دریافت سرویس نیازی به زدن دکمه بازگشت نیست.",
                     "در حال بررسی", true);
        } else {
            showForm("نتیجه پرداخت در این صفحه قابل تایید نیست؛ وضعیت سفارش را در ربات پیگیری کنید.",
                     "پیگیری پرداخت");
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
        showForm("کد پرداخت یافت نشد.", "خطا!");
    } else {
        $payParam = $payInfo->fetch_assoc();
        if (!achbotSupportsPaymentSubject($connection, $payParam)) {
            showForm("تایید این پرداخت برای سرویس غیرقابل پشتیبانی متوقف شد؛ با پشتیبانی تماس بگیرید.", "خطا");
            return;
        }

        $rowId = (int)$payParam['id'];
        $amount = (int)$payParam['price'];

        $Authority = (string)($_GET['Authority'] ?? '');
        // A verified provider reply must belong to the AUTHORITY issued for
        // this exact order. Old orders without stored Authority need manual
        // review: there is no safe auto-settlement identity binding.
        if ($Authority === '' || (string)($payParam['payid'] ?? '') !== $Authority) {
            showForm("شناسه پرداخت زرین پال با سفارش مطابقت ندارد؛ نیازمند بررسی است.", "درگاه زرین پال");
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
                showForm("این پرداخت نیازمند تطبیق امن با سفارش است.", "پیگیری پرداخت");
                return;
            }
            $scoped=$attempt!==null;
            if ($scoped &&
                achbotHasUnscopedPaymentReferenceCollision($connection,$Authority,$rowId)) {
                showForm("شناسه پرداخت نیازمند بررسی پشتیبانی است؛ سفارش محفوظ ماند.",
                         "پیگیری پرداخت");
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
                showForm("شناسه تراکنش یکتا نیست؛ تسویه برای بررسی متوقف شد.", "پیگیری پرداخت");
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
            showForm("وضعیت تراکنش از پذیرنده قابل تایید نبود؛ سفارش محفوظ است.",
                     "پیگیری زرین پال");
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
            showForm("نتیجه تراکنش نیازمند تطبیق با پذیرنده است؛ سفارش لغو نشد.",
                     "پیگیری زرین پال");
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
                showForm("هویت یا مبلغ این پرداخت نیازمند بررسی است.", "پیگیری پرداخت");
                return;
            }
            if ($payParam && achbotHasUnscopedPaymentReferenceCollision(
                $connection,$hash_id,(int)$payParam['id'])) {
                showForm("شناسه تراکنش نیازمند تطبیق پشتیبانی است؛ سفارش محفوظ ماند.",
                         "پیگیری پرداخت");
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
        showForm("کد پرداخت یافت نشد یا یکتا نیست.", "خطا!");
    } else {
        if (!in_array($payParam['state'], ['pending', 'send'], true)) {
            showForm("این پرداخت قبلاً بررسی شده است.", "خطا!");
            return;
        }
        if (!achbotSupportsPaymentSubject($connection, $payParam)) {
            showForm("تایید این پرداخت برای سرویس غیرقابل پشتیبانی متوقف شد؛ با پشتیبانی تماس بگیرید.", "خطا");
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
                showForm("وضعیت پرداخت تغییر کرده است؛ با پشتیبانی تماس بگیرید.", "خطا!");
                return;
            }
            showForm("پرداخت شما انجام نشد!", "درگاه نکست پی");
        } else {
            // Preserve the current payment for a later trusted verification.
            // Do not debit, credit or settle an unknown payment outcome.
            showForm("وضعیت پرداخت هنوز قطعی نیست یا استعلام در دسترس نیست؛ لطفاً بعداً دوباره بررسی کنید.", "درگاه نکست پی");
        }
    }

} else {
    showForm("درگاه پرداخت شناسایی نشد.", "خطا!");
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
    showForm('پرداخت تایید شده است اما نتیجه عملیات سرویس هنوز قطعی نیست. وضعیت برای بررسی امن ثبت می‌شود و بازپرداخت خودکار انجام نمی‌شود.', $payDescription);
    return true;
}

function achbotPerformPaymentEffect($payRowId, $gateType, &$effectStage = null) {
    // Report the effect boundary to doAction's exception handler. No local
    // settlement/review row is assured before the paid claim succeeds.
    $effectStage='preclaim';
    global $connection, $admin, $userInfo, $from_id, $botUrl, $botState;
    global $buttonValues, $linkIndices, $hideConfigIfMany;
	
	$supportID = $botState['supportID'] ?? '';
    $rewardChannel = $botState['rewardChannel'] ?? null;
    $buttonValuesLocal = (isset($buttonValues) && is_array($buttonValues))
        ? $buttonValues
        : ['back_to_main' => "↪️ بازگشت به منوی اصلی"];

    $linkIndicesLocal = (isset($linkIndices) && is_array($linkIndices) && count($linkIndices))
        ? $linkIndices
        : [0];

    $hideConfigIfManyLocal = isset($hideConfigIfMany) ? (bool)$hideConfigIfMany : false;

    $time = time();

    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `id` = ? AND (`state` = 'pending' OR `state` = 'send')");
    $stmt->bind_param("i", $payRowId);
    $stmt->execute();
    $payInfo = $stmt->get_result();
    $stmt->close();

    if (mysqli_num_rows($payInfo) == 0) {
        showForm("کد پرداخت یافت نشد.", "خطا!");
        return false;
    }

    $payParam = $payInfo->fetch_assoc();
        if (!achbotSupportsPaymentSubject($connection, $payParam)) {
            showForm("تایید این پرداخت برای سرویس غیرقابل پشتیبانی متوقف شد؛ با پشتیبانی تماس بگیرید.", "خطا");
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
    if ($gateType == "zarinpal" || $gateType == "nextpay") $payDescription = "خرید اشتراک";

    if ($gateType === 'nowpayment') {
        $stmt=$connection->prepare("SELECT local_amount_toman FROM payment_attempts
            WHERE pay_row_id=? AND provider='nowpayments' AND stage='fulfilling' LIMIT 1");
        $stmt->bind_param('i',$rowId);
        $stmt->execute();
        $expected=$stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$expected || (int)$expected['local_amount_toman'] <= 0 ||
            (int)$expected['local_amount_toman'] !== $amount) {
            showForm("مبلغ سفارش پس از صدور فاکتور تغییر کرده است؛ تحویل متوقف شد.", "پیگیری پرداخت");
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
            showForm('پرداخت تأیید شده است اما ثبت نتیجهٔ سفارش تکمیل نشده است. لطفاً دوباره پرداخت نکنید، مشخصات پرداخت را نگه دارید و با پشتیبانی تماس بگیرید.',
                     'پیگیری پرداخت');
            return 'ambiguous';
        }
        showForm("این پرداخت در حال پردازش یا قبلاً نهایی شده است.", "پیگیری پرداخت");
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

        $accountCount = ($payParam['agent_count'] ?? 0) != 0 ? (int)$payParam['agent_count'] : 1;
        $eachPrice = ($accountCount > 0) ? ($amount / $accountCount) : $amount;

        if ($acount == 0 && $inbound_id != 0) {


            if (!refundToWalletAndNotify(
                $connection,
                $user_id,
                $amount,
                $gateType,
                $admin,
                "میخواست اشتراک بخره، ظرفیت پر بود.",
				$rewardChannel
            )) {
                showForm('⚠️ پرداخت ثبت شده ولی بازگشت اعتبار کیف پول تأیید نشد؛ پشتیبانی باید بررسی کند.', $payDescription);
                return 'ambiguous';
            }
            showForm('پرداخت شما انجام شد ولی ظرفیت این کانکشن پر شده است، مبلغ ' . number_format($amount) . " تومان به کیف پول شما اضافه شد.", $payDescription, false);

            return 'compensated';
        }

        if ($inbound_id == 0) {
            $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
            $stmt->bind_param("i", $server_id);
            $stmt->execute();
            $server_info = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (empty($server_info) || (int)$server_info['ucount'] <= 0) {


                if (!refundToWalletAndNotify(
                    $connection,
                    $user_id,
                    $amount,
                    $gateType,
                    $admin,
                    "میخواست اشتراک بخره، ظرفیت پر بود."
                )) {
                    showForm('⚠️ پرداخت ثبت شده ولی بازگشت اعتبار کیف پول تأیید نشد؛ پشتیبانی باید بررسی کند.', $payDescription);
                    return 'ambiguous';
                }
                showForm('پرداخت شما انجام شد ولی ظرفیت این سرور پر شده است، مبلغ ' . number_format($amount) . " تومان به کیف پول شما اضافه شد.", $payDescription, false);

                return 'compensated';
            }
        }

        [$serverInfo, $serverConfig] = getServerInfoAndConfig($connection, $server_id);
        $srv_remark = $serverInfo['remark'] ?? '';
        $serverType = $serverConfig['type'] ?? '';
        $portType   = $serverConfig['port_type'] ?? 'manual';

        for ($i = 1; $i <= $accountCount; $i++) {

            $uniqid = generateRandomString(42, $protocol);

            [$port] = getNextPortAndIncrease(dirname(__DIR__, 2) . '/settings/temp.txt', $portType);

            $forcedRemark = (!empty($description)) ? $description : null;
            $remark = buildRemark($botState, $srv_remark, $description, $user_id, $forcedRemark);

            // First-use expiry staging was exclusive to never-deployed
            // Sanaei/Alireza inbound plans. Marzban keeps normal expiry.
            $expire_microdate_local = $expire_microdate;

            [$response, $remark, $port] = createServerAccount(
                $serverType,
                $server_id,
                $inbound_id,
                $fid,
                $remark,
                $volume,
                $days,
                $uniqid,
                $protocol,
                $port,
                $expire_microdate_local,
                $netType,
                $rahgozar,
                $limitip
            );

            if (is_null($response)) {
                if (achbotNpDeferUncertainRemoteEffect($gateType, $payDescription)) return 'ambiguous';


                if (!refundToWalletAndNotify(
                    $connection,
                    $user_id,
                    $amount,
                    $gateType,
                    $admin,
                    "میخواست اشتراک بخره، اتصال به سرور برقرار نبود",
					$rewardChannel
                )) {
                    showForm('⚠️ پرداخت ثبت شده ولی بازگشت اعتبار کیف پول تأیید نشد؛ پشتیبانی باید بررسی کند.', $payDescription);
                    return 'ambiguous';
                }
                showForm('⚠️ پرداخت شما با موفقیت انجام شد. ولی اتصال به سرور برقرار نیست لطفا مدیر رو در جریان بزار ... مبلغ ' . number_format($amount) . " به کیف پولت اضافه شد.", $payDescription);

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
                    "میخواست اشتراک بخره، ولی اینباند پیدا نشد.",
					$rewardChannel
                )) {
                    showForm('⚠️ پرداخت ثبت شده ولی بازگشت اعتبار کیف پول تأیید نشد؛ پشتیبانی باید بررسی کند.', $payDescription);
                    return 'ambiguous';
                }
                showForm("پرداخت شما با موفقیت انجام شد. ولی ❌ | 🥺 سطر (inbound) با آیدی $inbound_id تو این سرور وجود نداره، مدیر رو در جریان بزار ... مبلغ " . number_format($amount) . " به کیف پول شما اضافه شد.", $payDescription);

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


                $adminErr = "خطای سرور " . ($serverInfo['title'] ?? '-') . ":\n\n" . ($msg ?: 'بدون پیام خطا');
				notifyAllAdminsSimple($adminErr, $admin, "html", null, $rewardChannel);

                if (!refundToWalletAndNotify(
                    $connection,
                    $user_id,
                    $amount,
                    $gateType,
                    $admin,
                    "میخواست اشتراک بخره، ولی خطا داد.",
					$rewardChannel
                )) {
                    showForm('⚠️ پرداخت ثبت شده ولی بازگشت اعتبار کیف پول تأیید نشد؛ پشتیبانی باید بررسی کند.', $payDescription);
                    return 'ambiguous';
                }
                showForm('⚠️ پرداخت شما با موفقیت انجام شد. ولی خطا داد لطفا به مدیر اطلاع دهید ... مبلغ ' . number_format($amount) . " تومان به کیف پولت اضافه شد.", $payDescription);

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
                $linkIndicesLocal,
                $hideConfigIfManyLocal,
                "pay/",
                dirname(__DIR__, 2) . '/pay'
            );

            insertOrder(
                $connection,
                $user_id,
                $token,
                $fid,
                $server_id,
                $inbound_id,
                $remark,
                $uniqid,
                $protocol,
                $expire_date,
                $vray_link,
                $eachPrice,
                time(),
                $rahgozar,
                $agentBought,
                true
            );
        }

        decreaseServerCapacity($connection, $server_id, $inbound_id, $fid, $accountCount, true);

        // A failed local capacity write after remote creation is an
        // unresolved business effect, not a completed delivery notice.
        showForm('✅ پرداخت شما با موفقیت انجام شد.', $payDescription, true);

        if (!empty($userinfo['refered_by'])) {
            giveReferralReward($connection, $user_id, (int)$userinfo['refered_by'], $admin, $botState);
        }

        $user_info = Bot('getChat', ['chat_id' => $user_id])->result;
        $first_name = $user_info->first_name ?? '-';
        $username = $user_info->username ?? '-';

        $keys = json_encode(['inline_keyboard' => [
            [
                ['text' => "💳 خرید از درگاه $gateType", 'callback_data' => 'wizwizch'],
            ],
            [
                ['text' => "🆔 $hash_id", 'callback_data' => "noop_$hash_id"]
            ]
        ]]);

        $msg = "
➕ خرید جدید (درگاه $gateType)

🔐 آیدی کاربر: $user_id
🛡️ نام کاربر: $first_name
🔖 نام کاربری تلگرام: @$username
💰 مبلغ پرداختی: $amount تومان
🎈 نام اشتراک: $remark
🔋 حجم اشتراک: $volume گیگ
⏰ مدت اشتراک: $days روز
";
		notifyAllAdminsSimple($msg, $admin, "html", $keys, $rewardChannel);
    }

    elseif ($payType == "INCREASE_WALLET") {

        // Credit was already committed atomically with pays.state='paid'.
        // Repeating it here would double-credit on the same verified event.
        showForm("✅ پرداخت شما با موفقیت انجام شد، مبلغ " . number_format($amount) . " تومان به کیف پول شما اضافه شد.", $payDescription, true);

        sendMessage("✅ مبلغ " . number_format($amount) . " تومان به حساب شما اضافه شد.\n\n<blockquote>
⚠️ لطفا دقت کنید شما فقط کیف پول خودتان را شارژ کرده‌اید، برای خرید یا تمدید باید از دکمه های زیر استفاده کنید:

🛒 <b>خرید اشتراک جدید</b>
یا <b>🔄 تمدید یا مشاهده اشتراک ها</b>

و در مرحله پرداخت روی دکمه زیر بزنید:

💰 <b>پرداخت با موجودی</b>

</blockquote>
📨 پشتیبانی:

🆔 $supportID", null, "html", $user_id);

        $adminText = "✅ مبلغ " . number_format($amount) . " تومان به کیف پول کاربر $user_id توسط درگاه $gateType اضافه شد.";
		notifyAllAdminsSimple($adminText, $admin, "html", null, $rewardChannel);
    }

    elseif ($payType == "RENEW_ACCOUNT") {

        $oid = (int)$payParam['order_id'];

        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
        $stmt->bind_param("i", $oid);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();
        $stmt->close();

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

        $response = renewConfigOnServer($connection, $server_id, $inbound_id, $uuid, $remark, $days, $volume, $isMarzban);

        if (is_null($response)) {
            if (achbotNpDeferUncertainRemoteEffect($gateType, $payDescription)) return 'ambiguous';


            if (!refundToWalletAndNotify(
                $connection,
                $user_id,
                $amount,
                $gateType,
                $admin,
                "میخواست اشتراکش رو تمدید کنه، ولی اتصال به سرور برقرار نبود.",
				$rewardChannel
            )) {
                showForm('⚠️ پرداخت ثبت شده ولی بازگشت اعتبار کیف پول تأیید نشد؛ پشتیبانی باید بررسی کند.', $payDescription);
                return 'ambiguous';
            }
            showForm('⚠️ پرداخت شما با موفقیت انجام شد ولی مشکل فنی در اتصال به سرور، لطفا به مدیریت اطلاع بدید، مبلغ ' . number_format($amount) . " تومان به کیف پول شما اضافه شد.", $payDescription);

            return 'compensated';
        }

        // Every supported provider requires a positively verified remote
        // renewal. A non-null success=false response must never extend the
        // local account or announce successful delivery.
        if (!is_object($response) || empty($response->success)) {
            if (achbotNpDeferUncertainRemoteEffect($gateType, $payDescription)) return 'ambiguous';
            return 'ambiguous';
        }

        $newExpire = $time + $days * 86400;
        $stmt = $connection->prepare("UPDATE `orders_list` SET `expire_date` = ?, `notif` = 0 WHERE `id` = ?");
        $stmt->bind_param("ii", $newExpire, $oid);
        $saved=$stmt->execute();
        $changed=$stmt->affected_rows;
        $stmt->close();
        if ($saved!==true || $changed!==1)
            throw new RuntimeException('renewal_order_update_unconfirmed');

        createIncreaseOrder($connection, $user_id, $server_id, $inbound_id, $remark, $amount, $time, null, null, true);

        showForm("✅ پرداخت شما با موفقیت انجام شد. اشتراک $remark با موفقیت تمدید شد.", $payDescription, true);
        sendMessage("✅ پرداخت شما با موفقیت انجام شد. اشتراک $remark با موفقیت تمدید شد.", json_encode(['inline_keyboard' => [[['text' => "↪️ بازگشت به منوی اصلی", 'callback_data' => "mainMenu"]]]]), null, $user_id);

        $keys = json_encode(['inline_keyboard' => [
            [
                ['text' => "💳 خرید از درگاه $gateType", 'callback_data' => 'wizwizch'],
            ],
            [
                ['text' => "🆔 $hash_id", 'callback_data' => "noop_$hash_id"]
            ]
        ]]);

        $user_info = Bot('getChat', ['chat_id' => $user_id])->result;
        $first_name = $user_info->first_name ?? '-';
        $username = $user_info->username ?? '-';

        $msg = "
💚 تمدید اشتراک (درگاه $gateType)

🔐 آیدی کاربر: $user_id
🛡️ نام کاربر: $first_name
🔖 نام کاربری تلگرام: @$username
💰 مبلغ پرداختی: $amount تومان
🎈 نام اشتراک: $remark
🔋 حجم اشتراک: $volume گیگ
⏰ مدت اشتراک: $days روز

";
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

        $plusDays = (int)$res['volume'];

        [$srvInfo, $srvConfig] = getServerInfoAndConfig($connection, $server_id);
        $serverType = $srvConfig['type'] ?? '';

        if ($serverType == "marzban") {
            $response = editMarzbanConfig($server_id, ['remark' => $remark, 'plus_day' => $plusDays]);
        } else {
            throw new \UnexpectedValueException('unsupported_panel');
        }

        if (!empty($response->success)) {

            $stmt = $connection->prepare("UPDATE `orders_list` SET `expire_date` = `expire_date` + ?, `notif` = 0 WHERE `uuid` = ?");
            $delta = $plusDays * 86400;
            $stmt->bind_param("is", $delta, $uuid);
            $saved=$stmt->execute();
            $changed=$stmt->affected_rows;
            $stmt->close();
            if ($saved!==true || $changed<1)
                throw new RuntimeException('day_extension_update_unconfirmed');

            $time2 = time();
            createIncreaseOrder($connection, $user_id, $server_id, $inbound_id, $remark, $amount, $time2, null, $plusDays, true);

            showForm("✅ پرداخت شما با موفقیت انجام شد. $plusDays روز به مدت زمان اشتراک شما اضافه شد.", $payDescription, true);
            sendMessage("✅ پرداخت شما با موفقیت انجام شد. $plusDays روز به مدت زمان اشتراک شما اضافه شد.", json_encode(['inline_keyboard' => [[['text' => "↪️ بازگشت به منوی اصلی", 'callback_data' => "mainMenu"]]]]), null, $user_id);

            $keys = json_encode(['inline_keyboard' => [
                [
                    ['text' => "💳 خرید از درگاه $gateType", 'callback_data' => 'wizwizch'],
                ],
                [
                    ['text' => "🆔 $hash_id", 'callback_data' => "noop_$hash_id"]
                ]
            ]]);

            $user_info = Bot('getChat', ['chat_id' => $user_id])->result;
            $first_name = $user_info->first_name ?? '-';
            $username = $user_info->username ?? '-';

            $msg = "
💜 افزایش زمان اشتراک (درگاه $gateType)

🔐 آیدی کاربر: $user_id
🛡️ نام کاربر: $first_name
🔖 نام کاربری تلگرام: @$username
💰 مبلغ پرداختی: $amount تومان
🎈 نام اشتراک: $remark
🕚 زمان اضافه شده: $plusDays روز
";
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
                "میخواست زمان اشتراکشو افزایش بده",
				$rewardChannel
            )) {
                showForm('⚠️ پرداخت ثبت شده ولی بازگشت اعتبار کیف پول تأیید نشد؛ پشتیبانی باید بررسی کند.', $payDescription);
                return 'ambiguous';
            }
            showForm("⚠️ پرداخت شما با موفقیت انجام شد ولی به دلیل مشکل فنی امکان افزایش حجم نیست. لطفا به مدیریت اطلاع بدید یا 5 دقیقه دیگر دوباره تست کنید مبلغ " . number_format($amount) . " تومان به کیف پول شما اضافه شد.", $payDescription, true);

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

        $plusVolume = (int)$res['volume'];

        [$srvInfo, $srvConfig] = getServerInfoAndConfig($connection, $server_id);
        $serverType = $srvConfig['type'] ?? '';

        if ($serverType == "marzban") {
            $response = editMarzbanConfig($server_id, ['remark' => $remark, 'plus_volume' => $plusVolume]);
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

            showForm("✅ پرداخت شما با موفقیت انجام شد. $plusVolume گیگ به حجم اشتراک شما اضافه شد.", $payDescription, true);
            sendMessage("✅ پرداخت شما با موفقیت انجام شد. $plusVolume گیگ به حجم اشتراک شما اضافه شد.", json_encode(['inline_keyboard' => [[['text' => "↪️ بازگشت به منوی اصلی", 'callback_data' => "mainMenu"]]]]), null, $user_id);

            $keys = json_encode(['inline_keyboard' => [
                [
                    ['text' => "💳 خرید از درگاه $gateType", 'callback_data' => 'wizwizch'],
                ],
                [
                    ['text' => "🆔 $hash_id", 'callback_data' => "noop_$hash_id"]
                ]
            ]]);

            $user_info = Bot('getChat', ['chat_id' => $user_id])->result;
            $first_name = $user_info->first_name ?? '-';
            $username = $user_info->username ?? '-';

            $msg = "
🤎 افزایش حجم اشتراک (درگاه $gateType)

🔐 آیدی کاربر: $user_id
🛡️ نام کاربر: $first_name
🔖 نام کاربری تلگرام: @$username
💰 مبلغ پرداختی: $amount تومان
🎈 نام اشتراک: $remark
🩸 حجم اضافه شده: $plusVolume گیگ
";
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
                "میخواست حجم اشتراکشو افزایش بده",
				$rewardChannel
            )) {
                showForm('⚠️ پرداخت ثبت شده ولی بازگشت اعتبار کیف پول تأیید نشد؛ پشتیبانی باید بررسی کند.', $payDescription);
                return 'ambiguous';
            }
            showForm("⚠️ پرداخت شما با موفقیت انجام شد ولی مشکل فنی در ارتباط با سرور، لطفا سلامت سرور را بررسی کنید مبلغ " . number_format($amount) . " تومان به کیف پول شما اضافه شد.", $payDescription, true);

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

			showForm("✅ پرداخت شما با موفقیت انجام شد. اشتراک $remark با موفقیت تمدید شد.", $payDescription, true);
			sendMessage("✅ پرداخت شما با موفقیت انجام شد. اشتراک $remark با موفقیت تمدید شد.",
				json_encode(['inline_keyboard' => [[['text' => "صفحه اصلی 🏘", 'callback_data' => "mainMenu"]]]]),
				null,
				$user_id
			);

			$adminText = "✅ تمدید اشتراک (درگاه $gateType)\n\n"
				. "UserID: $user_id\n"
				. "Remark: $remark\n"
				. "PlanID: $fid\n"
				. "Amount: " . number_format($amount) . "\n"
				. "🔋 حجم اشتراک: $volume گیگ\n"
				. "⏰ مدت اشتراک: $days روز\n"
				. "HashID: $hash_id";

			notifyAllAdminsSimple($adminText, $admin, null, null, $rewardChannel);

			return 'delivered';

		} catch (Exception $e) {

            if (achbotNpDeferUncertainRemoteEffect($gateType, $payDescription)) return 'ambiguous';
			$reason = "پرداخت انجام شد ولی تمدید به دلیل خطای سرور انجام نشد.\n"
				. "لطفاً به مدیریت اطلاع دهید.\n"
				. "کد خطا: " . $e->getMessage();



			if (!refundToWalletAndNotify($connection, $user_id, $amount, $gateType, $reason, $admin, $rewardChannel)) {
			    showForm('⚠️ پرداخت ثبت شده ولی بازگشت اعتبار کیف پول تأیید نشد؛ پشتیبانی باید بررسی کند.', $payDescription);
			    return 'ambiguous';
			}
			showForm("پرداخت شما با موفقیت انجام شد ولی مشکل فنی در تمدید.\nمبلغ " . number_format($amount) . " تومان به کیف پول شما اضافه شد.", $payDescription, true);


			return 'compensated';
		}
	}

    sendMessage("✅ پرداخت شما با موفقیت انجام شد.", json_encode(['inline_keyboard' => [[['text' => "↪️ بازگشت به منوی اصلی", 'callback_data' => "mainMenu"]]]]), null, $user_id);
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
            ? 'پرداخت تأیید شده است اما نتیجهٔ تکمیل سفارش هنوز قطعی نیست. لطفاً دوباره پرداخت نکنید، مشخصات پرداخت را نگه دارید و با پشتیبانی تماس بگیرید.'
            : 'پرداخت تأیید شده است اما ثبت نتیجهٔ سفارش هنوز قطعی نیست. لطفاً دوباره پرداخت نکنید، مشخصات پرداخت را نگه دارید و با پشتیبانی تماس بگیرید.';
        showForm($message,'پیگیری پرداخت');
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
function showForm($msg, $type = "", $state = false) {
    require_once __DIR__ . '/PaymentPage.php';
    achbotRenderPaymentPage($msg, $type, $state);
}
