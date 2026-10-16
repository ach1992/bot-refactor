<?php
/** AChBot V8: source-pinned financial compatibility module. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/GatewayClient.php';


/**
 * Signed NOWPayments IPN only records a durable payment ID. It never
 * provisions anything or contacts external providers in the HTTP callback.
 * Return a non-2xx status on missing/untrusted data so the provider may retry.
 */
function achbotNpIngestIpn(mysqli $connection, string $ipnSecret,
                           string $raw, string $signature): int {
    $payload = achbotNpValidIpn($raw, $signature, $ipnSecret);
    if ($payload === null) return 401;
    foreach (['payment_id','invoice_id','order_id','payment_status',
              'price_amount','price_currency'] as $field) {
        if (!isset($payload[$field]) || !is_scalar($payload[$field])) return 422;
    }
    $paymentId = (string)$payload['payment_id'];
    $invoiceId = (string)$payload['invoice_id'];
    $orderHash = (string)$payload['order_id'];
    $providerStatus = (string)$payload['payment_status'];
    if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $paymentId) ||
        !preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $invoiceId) ||
        strlen($orderHash) > 50 || $orderHash === '' ||
        strlen($providerStatus) > 32) return 422;
    $stmt = $connection->prepare("SELECT a.*,p.state AS pay_state,p.payid AS original_invoice
        FROM achbot_nowpayments_attempts a JOIN pays p ON p.id=a.pay_row_id
        WHERE a.order_hash=? AND a.invoice_id=? LIMIT 2");
    $stmt->bind_param('ss', $orderHash, $invoiceId);
    $stmt->execute();
    $match = $stmt->get_result();
    $stmt->close();
    if ($match->num_rows !== 1) return 409;
    $attempt = $match->fetch_assoc();
    if ((string)$attempt['original_invoice'] !== $invoiceId ||
        strtolower((string)$payload['price_currency']) !== 'usd' ||
        !is_numeric($payload['price_amount']) ||
        number_format((float)$payload['price_amount'],2,'.','') !==
        number_format((float)$attempt['quote_usd'],2,'.','')) return 422;
    // A single provider payment is currently supported per invoice; other
    // child payments or redemptions require explicit reconciliation, not reuse.
    if ($attempt['payment_id'] !== null &&
        (string)$attempt['payment_id'] !== $paymentId) {
        $stmt = $connection->prepare("UPDATE achbot_nowpayments_attempts
            SET stage='review',provider_status='multiple_payments'
            WHERE id=? AND stage NOT IN ('done','review')");
        $stmt->bind_param('i',$attempt['id']);
        $stmt->execute();
        $stmt->close();
        return 409;
    }
    if (!in_array($attempt['stage'],['pending','checking','review'],true)) return 200;
    $stmt = $connection->prepare("UPDATE achbot_nowpayments_attempts SET
        payment_id=COALESCE(payment_id,?), provider_status=?,
        stage=CASE WHEN stage='review' THEN 'review' ELSE 'pending' END
        WHERE id=? AND (payment_id IS NULL OR payment_id=?)");
    $stmt->bind_param('ssis',$paymentId,$providerStatus,$attempt['id'],$paymentId);
    try {
        $stmt->execute();
        $changed=$stmt->affected_rows;
    } catch (Throwable $ignored) {
        $stmt->close();
        return 409;
    }
    $stmt->close();
    return $changed === 1 || $changed === 0 ? 200 : 409;
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
    $sql = "SELECT a.*,p.payid AS original_invoice,p.state AS pay_state
            FROM achbot_nowpayments_attempts a JOIN pays p ON p.id=a.pay_row_id
            WHERE a.payment_id IS NOT NULL AND
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
            $stmt=$connection->prepare("UPDATE achbot_nowpayments_attempts
                SET stage='review',provider_status='fulfillment_needs_review'
                WHERE id=? AND stage NOT IN ('done','review')");
            $stmt->bind_param('i',$attempt['id']);
            $stmt->execute();
            $stmt->close();
            $done['review']++;
            continue;
        }
        $stmt = $connection->prepare("UPDATE achbot_nowpayments_attempts
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
            $connection->query("UPDATE achbot_nowpayments_attempts
                SET stage='pending' WHERE id=".(int)$attempt['id']." AND stage='checking'");
            $done['pending']++;
            continue;
        }
        $actualPaymentId=(string)($remote['payment_id'] ?? '');
        $actualInvoiceId=(string)($remote['invoice_id'] ?? '');
        $actualOrderHash=(string)($remote['order_id'] ?? '');
        if ($actualPaymentId !== (string)$attempt['payment_id'] ||
            $actualInvoiceId !== (string)$attempt['invoice_id'] ||
            $actualOrderHash !== (string)$attempt['order_hash'] ||
            (string)$attempt['original_invoice'] !== (string)$attempt['invoice_id']) {
            $stmt=$connection->prepare("UPDATE achbot_nowpayments_attempts
                SET stage='review',provider_status='identity_mismatch' WHERE id=?");
            $stmt->bind_param('i',$attempt['id']);
            $stmt->execute();
            $stmt->close();
            $done['review']++;
            continue;
        }
        $status=(string)($remote['payment_status'] ?? '');
        $kind=achbotVerifiedGatewayOutcome('nowpayment',$status);
        if ($kind!=='verified') {
            $nextStage=$kind==='pending'||$kind==='partial'?'pending':'review';
            // Do not silently auto-expire a payment that may arrive late.
            $stmt=$connection->prepare("UPDATE achbot_nowpayments_attempts
                SET stage=?,provider_status=? WHERE id=? AND stage='checking'");
            $stmt->bind_param('ssi',$nextStage,$status,$attempt['id']);
            $stmt->execute();
            $stmt->close();
            $done[$nextStage==='pending'?'pending':'review']++;
            continue;
        }
        if (!achbotNpValidFinalAmount($attempt,$remote)) {
            $stmt=$connection->prepare("UPDATE achbot_nowpayments_attempts
                SET stage='review',provider_status='amount_mismatch' WHERE id=?");
            $stmt->bind_param('i',$attempt['id']);
            $stmt->execute();
            $stmt->close();
            $done['review']++;
            continue;
        }
        $stmt=$connection->prepare("UPDATE achbot_nowpayments_attempts
            SET stage='fulfilling',provider_status='finished'
            WHERE id=? AND stage='checking'");
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
                $stmt=$connection->prepare("UPDATE achbot_nowpayments_attempts
                    SET stage='done',provider_status=? WHERE id=? AND stage='fulfilling'");
                $stmt->bind_param('si',$status,$attempt['id']);
                $stmt->execute();
                $stmt->close();
                $done['completed']++;
            } else {
                $stmt=$connection->prepare("UPDATE achbot_nowpayments_attempts
                    SET stage='review',provider_status='fulfillment_unconfirmed' WHERE id=?");
                $stmt->bind_param('i',$attempt['id']);
                $stmt->execute();
                $stmt->close();
                $done['review']++;
            }
        } catch (Throwable $error) {
            // DB and remote Marzban are not one transaction. Keep a human
            // review marker; do NOT blindly replay creation after an error.
            $stmt=$connection->prepare("UPDATE achbot_nowpayments_attempts
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

if (isset($_GET['nowpayment_ipn'])) {
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
} elseif (defined('ACHBOT_NP_RECONCILE_ONLY')) {
    // Included from the existing aaPanel Cron entrypoint, never a browser.
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
        $npTable = $connection->query("SHOW TABLES LIKE 'achbot_nowpayments_attempts'");
        if ($npTable && $npTable->num_rows === 1) {
            $guard = $connection->prepare("SELECT stage FROM achbot_nowpayments_attempts
                WHERE pay_row_id=? LIMIT 1");
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

        $base_url = 'https://api.nowpayments.io/v1/payment/' . rawurlencode($incomingPaymentId);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-API-KEY: ' . ($paymentKeys['nowpayment'] ?? '')]);
        curl_setopt($ch, CURLOPT_URL, $base_url);

        $rawResponse = curl_exec($ch);
        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        // A timeout, unexpected HTTP status or malformed provider response
        // is NOT evidence of a failed payment. Keep its order reconcilable.
        $res = $rawResponse !== false && $httpStatus === 200 && $curlError === ''
            ? json_decode($rawResponse) : null;
        if (!is_object($res)) {
            showForm("وضعیت پرداخت هنوز قابل تایید نیست؛ لطفا بعدا بررسی کنید.", "در حال بررسی");
            return;
        }
        // Match a provider payment ID when supplied by the verified API.
        $providerPaymentId = $res->payment_id ?? $res->id ?? null;
        if ($providerPaymentId !== null && (string) $providerPaymentId !== $incomingPaymentId) {
            showForm("شناسه تراکنش درگاه با درخواست مطابقت ندارد.", "خطا");
            return;
        }
        // The signed API may also carry the original merchant order_id.
        // Do not allow it to identify a different order than our local row.
        if (isset($res->order_id) && (string) $res->order_id !== (string) $preflightPay['hash_id']) {
            showForm("شناسه سفارش درگاه با فاکتور مطابقت ندارد.", "خطا");
            return;
        }

        $hash_id = (string) ($res->invoice_id ?? '');
        if ($hash_id === '' || $hash_id !== (string) $preflightPay['payid']) {
            showForm("شناسه فاکتور پرداخت با سفارش مطابقت ندارد.", "خطا");
            return;
        }

        // Provider invoice IDs are not UNIQUE in the inherited schema.
        // Do not credit an arbitrary matching row or cross another local order.
        $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `payid` = ? LIMIT 2");
        $stmt->bind_param("s", $hash_id);
        $stmt->execute();
        $payInfo = $stmt->get_result();
        $stmt->close();

        if (mysqli_num_rows($payInfo) !== 1) {
            showForm("شناسه فاکتور پرداخت یکتا نیست یا یافت نشد.", "خطا!");
            return;
        }
        $payParam = $payInfo->fetch_assoc();
        if ((int)$payParam['id'] !== (int)$preflightPay['id'] ||
            !in_array($payParam['state'], ['pending', 'send'], true) ||
            !achbotSupportsPaymentSubject($connection, $payParam)) {
            showForm("وضعیت یا مالک فاکتور پرداخت نامعتبر است؛ با پشتیبانی تماس بگیرید.", "خطا");
            return;
        }

        $rowId = (int)$payParam['id'];
        $payDescription = payTypeToDescription($payParam['type']);
        $disposition = achbotVerifiedGatewayOutcome('nowpayment', $res->payment_status ?? null);
        if ($disposition === 'verified') {
            // This still enters the inherited doAction() settlement path.
            // Whole-lifecycle idempotency/fulfillment is tracked in Issue #26.
            doAction($rowId, 'nowpayment');
        } elseif ($disposition === 'rejected') {
            // Reject only this still-pending row; do not overwrite a parallel
            // verification that may already have finalized the payment.
            $stmt = $connection->prepare("UPDATE `pays` SET `state`='canceled' WHERE `id`=? AND `state` IN ('pending','send')");
            $stmt->bind_param('i', $rowId);
            $stmt->execute();
            $changed = $stmt->affected_rows;
            $stmt->close();
            showForm($changed === 1 ? "پرداخت توسط درگاه ناموفق یا منقضی اعلام شد." :
                "وضعیت پرداخت تغییر کرده است؛ لطفا مجددا بررسی کنید.", $payDescription);
        } elseif ($disposition === 'partial') {
            // A partial receipt may later complete. Keep it pending so a
            // subsequent trusted verification can still settle this order.
            showForm("پرداخت ناقص ثبت شده است؛ تا تکمیل یا بررسی پشتیبانی، سفارش باز می‌ماند.", $payDescription);
        } else {
            // confirmed/sending are NOT finished. Unknown/refunded/transport
            // states require a later trusted check or manual reconciliation.
            showForm("پرداخت هنوز نهایی نشده است؛ وضعیت آن بعدا بررسی می‌شود.", $payDescription);
        }
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

        $Authority = $_GET['Authority'];

        //==============================================================
        $client = new SoapClient('https://www.zarinpal.com/pg/services/WebGate/wsdl', ['encoding' => 'UTF-8']);
        $result = $client->PaymentVerification([
            'MerchantID' => ($paymentKeys['zarinpal'] ?? ''),
            'Authority'  => $Authority,
            'Amount'     => $amount,
        ]);
        //==============================================================

        if ($_GET['Status'] == 'OK' and $result->Status == 100) {
            doAction($rowId, "zarinpal");
        } else {
            $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'canceled' WHERE `hash_id` = ?");
            $stmt->bind_param("s", $hash_id);
            $stmt->execute();
            $stmt->close();

            showForm("پرداخت شما انجام نشد!", "درگاه زرین پال");
        }
    }

} elseif (isset($_GET['nextpay'])) {

    $hash_id = $_GET['trans_id'];

    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `payid` = ? LIMIT 2");
    $stmt->bind_param("s", $hash_id);
    $stmt->execute();
    $payInfo = $stmt->get_result();
    $stmt->close();

    if (mysqli_num_rows($payInfo) !== 1) {
        // pays.payid is not UNIQUE in the original schema. Reject ambiguity.
        showForm("کد پرداخت یافت نشد یا یکتا نیست.", "خطا!");
    } else {
        $payParam = $payInfo->fetch_assoc();
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

function doAction($payRowId, $gateType) {
    global $connection, $admin, $userInfo, $from_id, $botUrl, $botState;
    global $buttonValues, $linkIndices, $hideConfigIfMany;
	
	$supportID = $botState['supportID'] ?? '';
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

    if ($payType === 'INCREASE_WALLET') {
        // The state claim and actual user credit commit together. Telegram
        // notification remains outside the DB transaction.
        $claimed = achbotClaimAndCreditWallet(
            $connection, $rowId, $user_id, $amount
        ) ? 1 : 0;
    } else {
        $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'paid'
            WHERE `id` = ? AND `state` IN ('pending','send')");
        $stmt->bind_param('i',$payRowId);
        $stmt->execute();
        $claimed=$stmt->affected_rows;
        $stmt->close();
    }
    if ($claimed !== 1) {
        showForm("این پرداخت در حال پردازش یا قبلاً نهایی شده است.", "پیگیری پرداخت");
        return false;
    }

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
            showForm('پرداخت شما انجام شد ولی ظرفیت این کانکشن پر شده است، مبلغ ' . number_format($amount) . " تومان به کیف پول شما اضافه شد.", $payDescription, false);

            refundToWalletAndNotify(
                $connection,
                $user_id,
                $amount,
                $gateType,
                $admin,
                "میخواست اشتراک بخره، ظرفیت پر بود.",
				$rewardChannel
            );
            return 'compensated';
        }

        if ($inbound_id == 0) {
            $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
            $stmt->bind_param("i", $server_id);
            $stmt->execute();
            $server_info = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (empty($server_info) || (int)$server_info['ucount'] <= 0) {
                showForm('پرداخت شما انجام شد ولی ظرفیت این سرور پر شده است، مبلغ ' . number_format($amount) . " تومان به کیف پول شما اضافه شد.", $payDescription, false);

                refundToWalletAndNotify(
                    $connection,
                    $user_id,
                    $amount,
                    $gateType,
                    $admin,
                    "میخواست اشتراک بخره، ظرفیت پر بود."
                );
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
                showForm('⚠️ پرداخت شما با موفقیت انجام شد. ولی اتصال به سرور برقرار نیست لطفا مدیر رو در جریان بزار ... مبلغ ' . number_format($amount) . " به کیف پولت اضافه شد.", $payDescription);

                refundToWalletAndNotify(
                    $connection,
                    $user_id,
                    $amount,
                    $gateType,
                    $admin,
                    "میخواست اشتراک بخره، اتصال به سرور برقرار نبود",
					$rewardChannel
                );
                return 'compensated';
            }

            if ($response == "inbound not Found") {
                showForm("پرداخت شما با موفقیت انجام شد. ولی ❌ | 🥺 سطر (inbound) با آیدی $inbound_id تو این سرور وجود نداره، مدیر رو در جریان بزار ... مبلغ " . number_format($amount) . " به کیف پول شما اضافه شد.", $payDescription);

                refundToWalletAndNotify(
                    $connection,
                    $user_id,
                    $amount,
                    $gateType,
                    $admin,
                    "میخواست اشتراک بخره، ولی اینباند پیدا نشد.",
					$rewardChannel
                );
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
                showForm('⚠️ پرداخت شما با موفقیت انجام شد. ولی خطا داد لطفا به مدیر اطلاع دهید ... مبلغ ' . number_format($amount) . " تومان به کیف پولت اضافه شد.", $payDescription);

                $adminErr = "خطای سرور " . ($serverInfo['title'] ?? '-') . ":\n\n" . ($msg ?: 'بدون پیام خطا');
				notifyAllAdminsSimple($adminErr, $admin, "html", null, $rewardChannel);

                refundToWalletAndNotify(
                    $connection,
                    $user_id,
                    $amount,
                    $gateType,
                    $admin,
                    "میخواست اشتراک بخره، ولی خطا داد.",
					$rewardChannel
                );
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
                $agentBought
            );
        }

        showForm('✅ پرداخت شما با موفقیت انجام شد.', $payDescription, true);

        decreaseServerCapacity($connection, $server_id, $inbound_id, $fid, $accountCount);

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
            showForm('⚠️ پرداخت شما با موفقیت انجام شد ولی مشکل فنی در اتصال به سرور، لطفا به مدیریت اطلاع بدید، مبلغ ' . number_format($amount) . " تومان به کیف پول شما اضافه شد.", $payDescription);

            refundToWalletAndNotify(
                $connection,
                $user_id,
                $amount,
                $gateType,
                $admin,
                "میخواست اشتراکش رو تمدید کنه، ولی اتصال به سرور برقرار نبود.",
				$rewardChannel
            );
            return 'compensated';
        }

        $newExpire = $time + $days * 86400;
        $stmt = $connection->prepare("UPDATE `orders_list` SET `expire_date` = ?, `notif` = 0 WHERE `id` = ?");
        $stmt->bind_param("ii", $newExpire, $oid);
        $stmt->execute();
        $stmt->close();

        createIncreaseOrder($connection, $user_id, $server_id, $inbound_id, $remark, $amount, $time, null, null);

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
            $stmt->execute();
            $stmt->close();

            $time2 = time();
            createIncreaseOrder($connection, $user_id, $server_id, $inbound_id, $remark, $amount, $time2, null, $plusDays);

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
            showForm("⚠️ پرداخت شما با موفقیت انجام شد ولی به دلیل مشکل فنی امکان افزایش حجم نیست. لطفا به مدیریت اطلاع بدید یا 5 دقیقه دیگر دوباره تست کنید مبلغ " . number_format($amount) . " تومان به کیف پول شما اضافه شد.", $payDescription, true);

            refundToWalletAndNotify(
                $connection,
                $user_id,
                $amount,
                $gateType,
                $admin,
                "میخواست زمان اشتراکشو افزایش بده",
				$rewardChannel
            );
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
            $stmt->execute();
            $stmt->close();

            $time2 = time();
            createIncreaseOrder($connection, $user_id, $server_id, $inbound_id, $remark, $amount, $time2, $plusVolume, null);

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
            showForm("⚠️ پرداخت شما با موفقیت انجام شد ولی مشکل فنی در ارتباط با سرور، لطفا سلامت سرور را بررسی کنید مبلغ " . number_format($amount) . " تومان به کیف پول شما اضافه شد.", $payDescription, true);

            refundToWalletAndNotify(
                $connection,
                $user_id,
                $amount,
                $gateType,
                $admin,
                "میخواست حجم اشتراکشو افزایش بده",
				$rewardChannel
            );
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
				$days
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

			$reason = "پرداخت انجام شد ولی تمدید به دلیل خطای سرور انجام نشد.\n"
				. "لطفاً به مدیریت اطلاع دهید.\n"
				. "کد خطا: " . $e->getMessage();

			showForm("پرداخت شما با موفقیت انجام شد ولی مشکل فنی در تمدید.\nمبلغ " . number_format($amount) . " تومان به کیف پول شما اضافه شد.", $payDescription, true);

			refundToWalletAndNotify($connection, $user_id, $amount, $gateType, $reason, $admin, $rewardChannel);

			return 'compensated';
		}
	}

    sendMessage("✅ پرداخت شما با موفقیت انجام شد.", json_encode(['inline_keyboard' => [[['text' => "↪️ بازگشت به منوی اصلی", 'callback_data' => "mainMenu"]]]]), null, $user_id);
    return 'delivered';
}

function showForm($msg, $type = "", $state = false) {
?>
    <html dir="rtl">
    <head>
      <meta charset="utf-8"><meta name="viewport" content="width=device-width">
        <title><?php echo $type; ?></title>
        <meta name="next-head-count" content="4">
        <link rel="stylesheet" href="../assets/20bb620751bbea45.css">
        <noscript data-n-css=""></noscript>
    </head>
    <body style="margin: 0 auto;">
        <div id="__next">
            <section class="ant-layout ant-layout-rtl PayPing-layout background--primary justify-center" style="min-height:100vh">
                <main class="ant-layout-content justify-center align-center flex-column">
                    <div class="ant-row ant-row-center ant-row-rtl PayPing-row w-100">
                        <div class="ant-col PayPing-col PayPing-error-card ant-col-xs-23 ant-col-rtl ant-col-sm-20 ant-col-md-16 ant-col-lg-12 ant-col-xl-8 ant-col-xxl-6">
                            <div class="py-2 align-center color--danger flex-column">
                                <?php if (!$state) { ?>
                                    <svg fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" class="PayPing-icon" stroke-width="1" width="100">
                                        <circle cx="12" cy="12" r="11"></circle>
                                        <path d="M15.3 8.7l-6.6 6.6M8.7 8.7l6.6 6.6"></path>
                                    </svg>
                                <?php } ?>
                                <div class="py-2"><?php echo $msg; ?></div>
                            </div>
                        </div>
                    </div>
                </main>
            </section>
        </div>
    </body>
</html>
<?php
}
?>
