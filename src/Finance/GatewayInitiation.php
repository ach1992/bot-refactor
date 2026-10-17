<?php
/** AChBot V8: source-pinned financial compatibility module. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

/**
 * Claim one gateway per local order before an external, non-idempotent API
 * request. A persisted payid (including a pending claim) cannot be replaced
 * by another gateway. The marker remains on an ambiguous provider response;
 * only an operator with independent provider evidence may release it.
 */
function achbotReserveCheckoutProvider(mysqli $db, int $orderId, string $provider): ?string {
    if (!in_array($provider, ['nextpay','zarinpal'], true) || $orderId <= 0 ||
        !achbotSharedPaymentTablesReady($db)) return null;
    $marker = 'achbot_' . $provider . '_creating_' . $orderId;
    try {
        $db->begin_transaction();
        $stmt = $db->prepare("SELECT payid,state,hash_id,price FROM pays WHERE id=? FOR UPDATE");
        $stmt->bind_param('i',$orderId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row || $row['state'] !== 'pending' || !empty($row['payid']) ||
            (int)$row['price'] <= 0 || empty($row['hash_id'])) {
            $db->rollback();
            return null;
        }
        // Every gateway reserves the SAME durable, provider-scoped attempt
        // before non-idempotent remote issuance. No provider-specific tables.
        $amount=(int)$row['price'];
        $quoted=(string)$amount;
        $stmt=$db->prepare("INSERT INTO payment_attempts
            (pay_row_id,provider,order_hash,quote_amount,quote_currency,
             local_amount_toman,stage)
            VALUES (?,?,?,?,'toman',?,'creating')");
        $stmt->bind_param('isssi',$orderId,$provider,$row['hash_id'],$quoted,$amount);
        $stmt->execute();
        $stmt->close();
        $stmt = $db->prepare("UPDATE pays SET payid=? WHERE id=? AND state='pending'
            AND (payid IS NULL OR payid='')");
        $stmt->bind_param('si',$marker,$orderId);
        $stmt->execute();
        $changed = $stmt->affected_rows;
        $stmt->close();
        if ($changed !== 1 || !$db->commit()) throw new RuntimeException('checkout_claim_failed');
        return $marker;
    } catch (Throwable $error) {
        $db->rollback();
        return null;
    }
}

/**
 * Persist the provider reference and the historical pays.payid IN THE SAME
 * transaction. If either write fails, preserve the creating reservation:
 * network success may have happened and blindly retrying could charge twice.
 */
function achbotRecordCheckoutReference(mysqli $db, int $orderId, string $provider,
                                       string $marker, string $reference,
                                       string $checkoutUrl): bool {
    if (!in_array($provider,['nextpay','zarinpal'],true) ||
        !preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$reference) ||
        $checkoutUrl==='' || $orderId<=0) return false;
    try {
        $db->begin_transaction();
        $stmt=$db->prepare("SELECT payid,state FROM pays WHERE id=? FOR UPDATE");
        $stmt->bind_param('i',$orderId);
        $stmt->execute();
        $order=$stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$order || $order['state']!=='pending' ||
            (string)$order['payid']!==$marker) {
            throw new RuntimeException('checkout_order_changed');
        }
        // Provider-scoped uniqueness cannot certify an older payid whose
        // gateway was never recorded. Never expose a colliding checkout URL.
        if (achbotHasUnscopedPaymentReferenceCollision($db,$reference,$orderId)) {
            throw new RuntimeException('legacy_checkout_reference_ambiguous');
        }
        $stmt=$db->prepare("UPDATE payment_attempts
            SET provider_reference=?,checkout_url=?,stage='pending'
            WHERE pay_row_id=? AND provider=? AND stage='creating'
              AND provider_reference IS NULL");
        $stmt->bind_param('ssis',$reference,$checkoutUrl,$orderId,$provider);
        $stmt->execute();
        $saved=$stmt->affected_rows;
        $stmt->close();
        if ($saved!==1) throw new RuntimeException('checkout_reference_collision');
        $stmt=$db->prepare("UPDATE pays SET payid=?
            WHERE id=? AND state='pending' AND payid=?");
        $stmt->bind_param('sis',$reference,$orderId,$marker);
        $stmt->execute();
        $changed=$stmt->affected_rows;
        $stmt->close();
        if ($changed!==1 || !$db->commit()) {
            throw new RuntimeException('checkout_binding_failed');
        }
        return true;
    } catch (Throwable $error) {
        $db->rollback();
        return false;
    }
}

/** A potentially issued provider checkout must stay reserved for review. */
function achbotMarkCheckoutUncertain(mysqli $db, int $orderId, string $provider): void {
    if (!in_array($provider,['nextpay','zarinpal'],true)) return;
    try {
        $stmt=$db->prepare("UPDATE payment_attempts
            SET stage='review',provider_status='invoice_creation_uncertain'
            WHERE pay_row_id=? AND provider=? AND stage='creating'");
        $stmt->bind_param('is',$orderId,$provider);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $ignored) {
        // The durable creating reservation and pays.payid claim remain.
    }
}

//====================//  Get  //==============================
$hash_id = (string)($_GET['hash_id'] ?? '');
if(!isset($_GET['zarinpal']) && !isset($_GET['nowpayment']) && !isset($_GET['nextpay'])){
    showForm("درگاه پرداخت شناسایی نشد!");
    exit();
}

$stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? AND `state` = 'pending'");
$stmt->bind_param("s", $hash_id);
$stmt->execute();
$payInfo = $stmt->get_result();
$stmt->close();

if(mysqli_num_rows($payInfo)==0){
    if (isset($_GET['nowpayment']) && $hash_id !== '') {
        $stmt = $connection->prepare("SELECT state FROM pays WHERE hash_id=? LIMIT 1");
        $stmt->bind_param('s',$hash_id);
        $stmt->execute();
        $existing=$stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (($existing['state'] ?? '')==='paid') {
            showForm("این سفارش قبلاً پرداخت شده؛ لینک یا تاییدیه در ربات ارسال می‌شود.");
        } elseif ($existing) {
            showForm("این سفارش در حال پردازش یا نیازمند پیگیری است؛ فاکتور جدید صادر نشد.");
        } else {
            showForm("کد پرداخت یافت نشد.");
        }
    } else {
        showForm("کد پرداخت یافت نشد.");
    }
}else{
    $payParam = $payInfo->fetch_assoc();
    if (!achbotSupportsPaymentSubject($connection, $payParam)) {
        showForm("این سرویس در پنل مرزبان قابل پرداخت نیست.");
        exit();
    }

    
    // Only purchases depend on the plan's capacity and inbound fields.
    // In particular, an independent INCREASE_WALLET has no plan_id and
    // must not dereference a nonexistent plan to initiate payment.
    $fid = (int) ($payParam['plan_id'] ?? 0);
    $server_id = 0;
    $acount = 0;
    $inbound_id = 0;
    if ($payParam['type'] === 'BUY_SUB') {
        $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
        $stmt->bind_param("i", $fid);
        $stmt->execute();
        $file_detail = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$file_detail) {
            showForm("پلن مورد نظر یافت نشد.");
            exit;
        }
        $server_id = $file_detail['server_id'];
        $acount = $file_detail['acount'];
        $inbound_id = $file_detail['inbound_id'];
    }
    
    
    $orderId= $payParam['id'];
    $amount = $payParam['price'];
    $payType = $payParam['type'];
    //========================== // config // ==============================
    
    
    if($acount == 0 and $inbound_id != 0 && $payType == "BUY_SUB"){
        showForm('ظرفیت این کانکشن پر شده است.');
        exit;
    }

    if($inbound_id == 0 && $payType == "BUY_SUB") {
        $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $server_info = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if($server_info['ucount'] == 0) {
            showForm('ظرفیت این سرور پر شده است.');
            exit;
        }
    }elseif($payType == "BUY_SUB"){
        if($acount != 0 && $acount < $text){
            showForm("روی این پلن فقط $acount اشتراک میشه ساخت.");
            exit();
        }
    }
    
    if($payType == "BUY_SUB") $type = "خرید اشتراک";
    elseif($payType == "RENEW_ACCOUNT"){
        $type = "تمدید اشتراک";
        $oid = $payParam['order_id'];
        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
        $stmt->bind_param("i", $oid);
        $stmt->execute();
        $order = $stmt->get_result();
        $stmt->close();

        if($order->num_rows == 0){
            showForm($mainValues['config_not_found']);
            exit();
        }

    }
    elseif($payType == "RENEW_SCONFIG") $type = "تمدید اشتراک";
    elseif($payType == "INCREASE_WALLET") $type ="شارژ کیف پول";
    elseif(preg_match('/^INCREASE_DAY_(\d+)_(\d+)/',$payType)) $type = "افزایش زمان اشتراک";
    elseif(preg_match('/^INCREASE_VOLUME_(\d+)_(\d+)/',$payType)) $type = "افزایش حجم اشتراک";
    
    
    
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'PAYMENT_KEYS'");
    $stmt->execute();
    $paymentKeys = $stmt->get_result()->fetch_assoc()['value'];
    if(!is_null($paymentKeys)) $paymentKeys = json_decode($paymentKeys,true);
    else $paymentKeys = array();
    $stmt->close();
	
    if(isset($_GET['nowpayment'])){
        // The additive attempt table is required for safe invoice continuity.
        // Old payments with an invoice ID but no attempt are not reissued
        // automatically: creating a second provider invoice may double charge.
        if (!achbotSharedPaymentTablesReady($connection)) {
            showForm("پیکربندی پیگیری امن پرداخت هنوز تکمیل نشده است.");
            exit;
        }
        $stmt = $connection->prepare("SELECT * FROM payment_attempts WHERE pay_row_id=? AND provider='nowpayments' LIMIT 2");
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $stored = $stmt->get_result();
        $stmt->close();
        if ($stored->num_rows === 1) {
            $attempt = $stored->fetch_assoc();
            if ($attempt['stage'] === 'creating' && $attempt['order_hash'] === $hash_id) {
                showForm("فاکتور این سفارش در حال صدور است؛ از ایجاد درخواست تکراری جلوگیری شد.");
                exit;
            }
            // A previously observed provider payment must not be reopened
            // as a checkout from an old "continue payment" link.
            if ($attempt['order_hash'] === $hash_id &&
                (string)($attempt['payment_id'] ?? '') !== '') {
                showForm("پرداخت برای این فاکتور شناسایی شده است و در حال بررسی است. برای مشاهده وضعیت به ربات مراجعه کنید.");
                exit;
            }
            if ($attempt['order_hash'] === $hash_id &&
                $attempt['stage'] === 'pending' &&
                $attempt['provider_reference'] === (string)$payParam['payid'] &&
                achbotNpValidInvoiceUrl($attempt['checkout_url'])) {
                header('Location: '.$attempt['checkout_url'], true, 302);
                exit;
            }
            showForm("لینک فاکتور نیاز به بررسی پشتیبانی دارد.");
            exit;
        }
        if ($stored->num_rows > 1 || !empty($payParam['payid'])) {
            showForm("برای این سفارش قبلاً فاکتور صادر شده است؛ از ایجاد فاکتور تکراری جلوگیری شد.");
            exit;
        }
        if (empty($nowpaymentsIpnSecret)) {
            showForm("کلید امن اعلان NOWPayments هنوز توسط مدیر ثبت نشده است.");
            exit;
        }
        $userId = (int)($payParam['user_id'] ?? 0);
        $isAgent = 0;

        if($userId > 0){
            $stmt = $connection->prepare("SELECT `is_agent` FROM `users` WHERE `userid` = ? LIMIT 1");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $userRow = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $isAgent = (int)($userRow['is_agent'] ?? 0);
        }

        if($isAgent === 1 && isset($USDTPriceagent) && trim($USDTPriceagent) !== '' && is_numeric($USDTPriceagent)){
            $dollarPrice = (float)$USDTPriceagent;
        } elseif(isset($USDTPrice) && trim($USDTPrice) !== '' && is_numeric($USDTPrice)){
            $dollarPrice = (float)$USDTPrice;
        } else {
            $tlResponse = @file_get_contents('https://api.tetherland.com/currencies');
            $tlData = json_decode($tlResponse, true);

            $dollarPrice = isset($tlData['data']['currencies']['USDT']['price'])
                ? (float)$tlData['data']['currencies']['USDT']['price']
                : 0;
        }

        if($dollarPrice <= 0){
            showForm("قیمت تتر نامعتبر است.");
            exit();
        }

        if(empty($paymentKeys['nowpayment'])){
            showForm("کلید درگاه NowPayments تنظیم نشده است.");
            exit();
        }

        $base_url = 'https://api.nowpayments.io/v1/invoice';

        $quoteUsd = number_format(round($amount / $dollarPrice, 2), 2, '.', '');
        if ((float)$quoteUsd <= 0 || (int)$amount <= 0) {
            showForm("مبلغ فاکتور معتبر نیست.");
            exit;
        }
        // Default merchant behavior accepts the provider's ordinary payout
        // conversion/network fees, as the original hosted checkout did.
        // An explicitly configured stricter NET payout floor is still honored
        // and snapshotted per invoice; malformed explicit policy fails closed.
        // A missing policy enables normal provider fees; an explicitly
        // defined NULL is malformed and must NOT downgrade a strict choice.
        $hasSettlementPolicy = array_key_exists(
            'nowpaymentsSettlementPolicy', get_defined_vars());
        $settlementPolicy = $hasSettlementPolicy
            ? $nowpaymentsSettlementPolicy : null;
        $payoutCurrency = null;
        $minimumOutcome = '0';
        if ($hasSettlementPolicy) {
            $payoutCurrency = is_array($settlementPolicy)
                ? strtolower((string)($settlementPolicy['outcome_currency'] ?? '')) : '';
            $payoutRate = is_array($settlementPolicy)
                ? (string)($settlementPolicy['minimum_outcome_per_usd'] ?? '') : '';
            $minimumOutcome = preg_match('/^[a-z0-9]{2,32}$/D',$payoutCurrency)
                ? achbotNpMinimumPayout($quoteUsd,$payoutRate) : null;
            if ($minimumOutcome === null) {
                showForm("این روش پرداخت فعلاً در دسترس نیست؛ لطفاً روش دیگری را انتخاب کنید.");
                exit;
            }
        }
        $payload = [
            'price_amount' => (float)$quoteUsd,
            'price_currency' => 'usd',
            'order_id' => $hash_id,
            'order_description' => $type,
            'ipn_callback_url' => $botUrl . 'pay/back.php?nowpayment_ipn=1',
            'success_url' => $botUrl . 'pay/back.php?nowpayment&hash_id=' . urlencode($hash_id),
            'is_fee_paid_by_user' => false
        ];

        // Reserve the exact order and quote in MariaDB BEFORE calling the
        // non-idempotent invoice endpoint. An overlapping click cannot
        // create an orphan provider invoice. A crashed/uncertain request
        // remains visibly reserved until safe operator reconciliation.
        $checkoutMarker = 'achbot_nowpayment_creating_' . (int)$orderId;
        try {
            $connection->begin_transaction();
            $stmt = $connection->prepare(
                "SELECT payid,state FROM pays WHERE id=? FOR UPDATE");
            $stmt->bind_param('i', $orderId);
            $stmt->execute();
            $locked = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$locked || $locked['state'] !== 'pending' || !empty($locked['payid'])) {
                throw new RuntimeException('checkout_already_reserved');
            }
            $stmt = $connection->prepare("INSERT INTO payment_attempts
                (pay_row_id,provider,order_hash,provider_reference,checkout_url,quote_amount,quote_currency,
                 local_amount_toman,settlement_currency,minimum_settlement_amount,stage)
                VALUES (?,'nowpayments',?,NULL,NULL,?,'usd',?,?,?,'creating')");
            $stmt->bind_param('ississ', $orderId, $hash_id, $quoteUsd,
                $amount, $payoutCurrency, $minimumOutcome);
            $stmt->execute();
            $stmt->close();
            $stmt = $connection->prepare("UPDATE pays SET payid=? WHERE id=? AND state='pending'
                AND (payid IS NULL OR payid='')");
            $stmt->bind_param('si',$checkoutMarker,$orderId);
            $stmt->execute();
            $reserved = $stmt->affected_rows;
            $stmt->close();
            if ($reserved !== 1 || !$connection->commit()) {
                throw new RuntimeException('reservation_commit_failed');
            }
        } catch (Throwable $error) {
            $connection->rollback();
            showForm("فاکتور این سفارش در حال صدور یا نیازمند بررسی است؛ درخواست تکراری ایجاد نشد.");
            exit;
        }

        $markCheckoutUncertain = static function () use ($connection, $orderId): void {
            try {
                $stmt = $connection->prepare("UPDATE payment_attempts
                    SET stage='review',provider_status='invoice_creation_uncertain'
                    WHERE pay_row_id=? AND provider='nowpayments' AND stage='creating'");
                $stmt->bind_param('i',$orderId);
                $stmt->execute();
                $stmt->close();
            } catch (Throwable $ignored) {
                // Keep at least the pre-existing durable 'creating' claim;
                // never silently reissue a possibly accepted provider invoice.
            }
        };

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $base_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-API-KEY: ' . $paymentKeys['nowpayment'],
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $responseBody = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if($responseBody === false || !empty($curlError)){
            $markCheckoutUncertain();
            showForm("وضعیت صدور فاکتور درگاه نامشخص است؛ درخواست جدید صادر نمی‌شود و به بررسی نیاز دارد.");
            exit();
        }

        $res = json_decode($responseBody, true);

        if($httpCode < 200 || $httpCode >= 300){
            $markCheckoutUncertain();
            showForm("صدور فاکتور درگاه تأیید نشد؛ برای جلوگیری از فاکتور تکراری بررسی لازم است.");
            exit();
        }

        if(empty($res['id']) || empty($res['invoice_url'])){
            $markCheckoutUncertain();
            showForm("پاسخ صدور فاکتور ناقص بود؛ درخواست جدید خودکار صادر نمی‌شود.");
            exit();
        }

        $payid = (string)$res['id'];
        $invoiceUrl = (string)$res['invoice_url'];
        if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $payid) ||
            !achbotNpValidInvoiceUrl($invoiceUrl)) {
            $markCheckoutUncertain();
            showForm("هویت یا لینک فاکتور درگاه نامعتبر است و به بررسی نیاز دارد.");
            exit;
        }
        try {
            $connection->begin_transaction();
            $stmt = $connection->prepare("SELECT payid,state FROM pays WHERE id=? FOR UPDATE");
            $stmt->bind_param('i', $orderId);
            $stmt->execute();
            $locked = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$locked || $locked['state'] !== 'pending' ||
                (string)$locked['payid'] !== $checkoutMarker) {
                throw new RuntimeException('checkout_order_changed');
            }
            // OLD pays.payid rows have no provider identity. An invoice may
            // already be live remotely: keep our durable reservation for
            // operator review but NEVER publish a conflicting hosted URL.
            // Fully provider-scoped attempts remain independently valid.
            if (achbotHasUnscopedPaymentReferenceCollision(
                $connection, $payid, (int)$orderId)) {
                throw new RuntimeException('legacy_invoice_reference_ambiguous');
            }
            $stmt = $connection->prepare("UPDATE payment_attempts
                SET provider_reference=?,checkout_url=?,stage='pending'
                WHERE pay_row_id=? AND provider='nowpayments' AND order_hash=? AND stage='creating'
                  AND provider_reference IS NULL AND quote_amount=?");
            $stmt->bind_param('ssiss', $payid, $invoiceUrl, $orderId, $hash_id, $quoteUsd);
            $stmt->execute();
            $saved = $stmt->affected_rows;
            $stmt->close();
            if ($saved !== 1) throw new RuntimeException('checkout_reservation_changed');
            $stmt = $connection->prepare("UPDATE pays SET payid=? WHERE id=? AND state='pending' AND payid=?");
            $stmt->bind_param('sis', $payid, $orderId, $checkoutMarker);
            $stmt->execute();
            $changed = $stmt->affected_rows;
            $stmt->close();
            if ($changed !== 1) throw new RuntimeException('checkout_payment_row_changed');
            if (!$connection->commit()) throw new RuntimeException('checkout_commit_failed');
        } catch (Throwable $error) {
            $connection->rollback();
            $markCheckoutUncertain();
            showForm("فاکتور صادر شد اما اتصال ایمن آن به سفارش نیازمند بررسی است؛ درخواست جدید صادر نمی‌شود.");
            exit;
        }

        // The merchant can recover this exact provider invoice on every
        // subsequent click; also preserve a direct button in Telegram.
        if ($userId > 0) {
            $keyboard = json_encode(['inline_keyboard'=>[
                [['text'=>'🔄 باز کردن فاکتور یا پیگیری',
                  'url'=>$botUrl.'pay/?nowpayment&hash_id='.urlencode($hash_id)]],
                [['text'=>'🧾 بررسی وضعیت سفارش','callback_data'=>'ps_'.$hash_id]]
            ]], JSON_UNESCAPED_UNICODE);
            try {
                sendMessage("💳 لینک پرداخت سفارش $hash_id ثبت شد.\nپرداخت پس از تایید درگاه به صورت خودکار در ربات بررسی می‌شود؛ نیازی به زدن دکمه بازگشت نیست.",
                    $keyboard, null, $userId);
            } catch (Throwable $ignored) {
                // Durable invoice persists even if a Telegram send fails.
            }
        }
        header('Location: ' . $invoiceUrl, true, 302);
        exit();
    }
    
    elseif(isset($_GET['zarinpal'])){
        $marker=achbotReserveCheckoutProvider($connection,(int)$orderId,'zarinpal');
        if ($marker === null) {
            showForm(achbotSharedPaymentTablesReady($connection)
                ? "یک درخواست پرداخت برای این سفارش قبلاً صادر شده است؛ تغییر درگاه نیازمند بررسی است."
                : "پیکربندی پیگیری امن پرداخت هنوز تکمیل نشده است.");
            exit;
        }
        $CallbackURL = $botUrl . "pay/back.php?zarinpal&hash_id=" . urlencode($hash_id);
        try {
            $client = new SoapClient('https://www.zarinpal.com/pg/services/WebGate/wsdl', ['encoding' => 'UTF-8']);
            $result = $client->PaymentRequest([
                'MerchantID' => $paymentKeys['zarinpal'],
                'Amount' => $amount,
                'Description' => $type,
                'Email' => $Email ?? '',
                'Mobile' => $Mobile ?? '',
                'CallbackURL' => $CallbackURL,
            ]);
            $authority=(string)($result->Authority ?? '');
            if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$authority) ||
                !in_array((int)($result->Status ?? -1),[100,0],true)) {
                throw new RuntimeException('zarinpal_checkout_unconfirmed');
            }
            $checkoutUrl='https://www.zarinpal.com/pg/StartPay/' . rawurlencode($authority) . '/ZarinGate';
            if (!achbotRecordCheckoutReference($connection,(int)$orderId,'zarinpal',
                                               $marker,$authority,$checkoutUrl)) {
                throw new RuntimeException('zarinpal_checkout_write_conflict');
            }
            header('Location: '.$checkoutUrl);
            exit;
        } catch (Throwable $error) {
            // Do not reissue when provider may already have accepted a request.
            achbotMarkCheckoutUncertain($connection,(int)$orderId,'zarinpal');
            showForm("وضعیت صدور فاکتور نامشخص است؛ برای جلوگیری از پرداخت تکراری نیاز به بررسی دارد.");
            exit;
        }
    }

    elseif(isset($_GET['nextpay'])){
        $marker=achbotReserveCheckoutProvider($connection,(int)$orderId,'nextpay');
        if ($marker === null) {
            showForm(achbotSharedPaymentTablesReady($connection)
                ? "یک درخواست پرداخت برای این سفارش قبلاً صادر شده است؛ تغییر درگاه نیازمند بررسی است."
                : "پیکربندی پیگیری امن پرداخت هنوز تکمیل نشده است.");
            exit;
        }
        $Description = $type;
        $CallbackURL = $botUrl . "pay/back.php?nextpay&hash_id=$hash_id";
    
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://nextpay.org/nx/gateway/token',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => 'api_key='.$paymentKeys['nextpay'] .'&amount='.$amount.'&order_id='.$orderId.'&currency=IRT&callback_uri='.$CallbackURL,
        ));
        $response = curl_exec($curl);
        curl_close($curl);
        $response = json_decode($response);

        $transId=(string)($response->trans_id ?? '');
        if ((string)($response->code ?? '') === '-1' &&
            preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$transId)) {
            $checkoutUrl='https://nextpay.org/nx/gateway/payment/' . rawurlencode($transId);
            if (achbotRecordCheckoutReference($connection,(int)$orderId,'nextpay',
                                              $marker,$transId,$checkoutUrl)) {
                header('Location: '.$checkoutUrl);
                exit;
            }
        }
        achbotMarkCheckoutUncertain($connection,(int)$orderId,'nextpay');
        showForm("وضعیت ایجاد تراکنش نامشخص است؛ درخواست پرداخت جدید خودکار صادر نمی‌شود.");
    }
}


function showForm($msg){
    ?>
    <html dir="rtl">
        <head>
            <script>
          (function(w,d,s,l,i){w[l]=w[l]||[];
            w[l].push({'gtm.start': new Date().getTime(),event:'gtm.js', });
            var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';
            j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl+'&gtm_auth=&gtm_preview=&gtm_cookies_win=x';
            f.parentNode.insertBefore(j,f);
          })(window,document,'script','dataLayer','GTM-MSN6P6G');</script>
          <meta charset="utf-8"><meta name="viewport" content="width=device-width">
            <title><?php echo $msg;?></title>
            <meta name="next-head-count" content="4">
            <link rel="stylesheet" href="../assets/20bb620751bbea45.css">
            <noscript data-n-css=""></noscript>
    
        </head>
        <body>
            <div id="__next">
                <section class="ant-layout ant-layout-rtl PayPing-layout background--primary justify-center" style="min-height:100vh">
                    <header class="ant-layout-header PayPing-header-logo justify-center align-center"></header>
                    <main class="ant-layout-content justify-center align-center flex-column">
                        <div class="ant-row ant-row-center ant-row-rtl PayPing-row w-100">
                            <div class="ant-col PayPing-col PayPing-error-card ant-col-xs-23 ant-col-rtl ant-col-sm-20 ant-col-md-16 ant-col-lg-12 ant-col-xl-8 ant-col-xxl-6">
                                <div class="py-2 align-center color--danger flex-column">
                                    <svg fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" class="PayPing-icon" stroke-width="1" width="100">
                                        <circle cx="12" cy="12" r="11"></circle>
                                        <path d="M15.3 8.7l-6.6 6.6M8.7 8.7l6.6 6.6"></path>
                                    </svg>
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