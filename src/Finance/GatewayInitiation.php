<?php
/** AChBot V8: source-pinned financial compatibility module. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG


/**
 * Claim one gateway per local order before an external, non-idempotent API
 * request. A persisted payid (including a pending claim) cannot be replaced
 * by another gateway. The marker remains on an ambiguous provider response;
 * only an operator with independent provider evidence may release it.
 */
function achbotReserveCheckoutProvider(mysqli $db, int $orderId, string $provider,
                                        array $original): ?string {
    $ownerId=(int)($original['user_id']??0);
    if (!in_array($provider, ['nextpay','zarinpal'], true) || $orderId <= 0 ||
        $ownerId<=0 ||
        !achbotSharedPaymentTablesReady($db)) return null;
    $marker = 'achbot_' . $provider . '_creating_' . $orderId;
    try {
        if (!$db->begin_transaction()) return null;
        // Same lock order as all customer-draft INSERTs. A replacement
        // committed after an initial GET must be visible by this boundary.
        achbotPayUxLockDraftOwner($db,$ownerId);
        $stmt = $db->prepare("SELECT * FROM pays WHERE id=? FOR UPDATE");
        $stmt->bind_param('i',$orderId);
        if (!$stmt->execute()) throw new RuntimeException('checkout_pay_lock_failed');
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row || (int)$row['user_id']!==$ownerId ||
            (string)$row['hash_id']!==(string)$original['hash_id'] ||
            (string)$row['type']!==(string)$original['type'] ||
            (int)$row['price']!==(int)$original['price'] ||
            (int)$row['request_date']!==(int)$original['request_date'] ||
            (int)($row['order_id']??0)!==(int)($original['order_id']??0) ||
            (!achbotPayUxLockedCheckoutUsable($db,$row)) ||
            !achbotPayUxAudienceAllowed($db,$provider,$ownerId,true,(string)$row['type']) ||
            !achbotSupportsPaymentSubject($db,$row,true) ||
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
        $inserted=$stmt->execute();
        $insertCount=$stmt->affected_rows;
        $stmt->close();
        // mysqli_report(MYSQLI_REPORT_OFF) must never permit a payid marker
        // to commit without its required 003 provider-attempt record.
        if ($inserted!==true || $insertCount!==1)
            throw new RuntimeException('checkout_ledger_insert_failed');
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

require_once __DIR__ . '/PaymentLifecycle.php';

//====================//  Get  //==============================
$hash_id = (string)($_GET['hash_id'] ?? '');
if(!isset($_GET['zarinpal']) && !isset($_GET['nowpayment']) && !isset($_GET['nextpay'])){
    showForm(\achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.message.1f5b86dd86'));
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
            // A paid local row does not prove customer Telegram delivery.
            showForm(\achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.message.32264671e4'), 'review');
        } elseif ($existing) {
            // 'send' is in progress; other nonpending states require review.
            showForm(\achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.message.33526fc91c'),
                ($existing['state'] ?? '') === 'send' ? 'pending' : 'review');
        } else {
            showForm(\achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.message.2d0a6d7c50'));
        }
    } else {
        showForm(\achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.message.2d0a6d7c50'));
    }
}else{
    $payParam = $payInfo->fetch_assoc();
    // The URL is only a guarded *request*, never an irrevocable provider link.
    // Refuse stale drafts and older invoices before any checkout side effect.
    if (achbotPayUxExpireDraft($connection,(int)$payParam['id'])) {
        showForm(\achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.message.24fe31bc43'));
        exit;
    }
    try {
        if (achbotPayUxHasNewer($connection,$payParam)) {
            showForm(\achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.message.8cdb71a59f'), 'review');
            exit;
        }
    } catch (Throwable $ignored) {
        showForm(\achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.message.2186b9a9dd'), 'review');
        exit;
    }
    // Payment-audience restrictions apply at the exact first-party HTTP
    // checkout route, not merely in Telegram menus. A previously issued
    // provider claim remains available only for status/reconciliation.
    $checkoutMethod=isset($_GET['nowpayment']) ? 'nowpayments' :
        (isset($_GET['zarinpal']) ? 'zarinpal' : 'nextpay');
    $stateFlag=$checkoutMethod==='nowpayments'
        ? (((string)$payParam['type']==='INCREASE_WALLET') ? 'nowPaymentWallet' : 'nowPaymentOther')
        : $checkoutMethod;
    if (($botState[$stateFlag]??'off')!=='on' ||
        !achbotPayUxAudienceAllowed($connection,$checkoutMethod,(int)$payParam['user_id'])) {
        showForm(\achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.message.32573bc25a'));
        exit;
    }
    // A hosted invoice can remain payable upstream after a stale bot link
    // is removed. Stop showing/redirecting to it at the attention deadline,
    // but preserve the exact provider claim for asynchronous reconciliation.
    try {
        if (achbotPayUxHostedAttentionElapsed($connection,(int)$payParam['id'])) {
            showForm(\achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.message.2d6b333856'), 'review');
            exit;
        }
    } catch (Throwable $ignored) {
        showForm(\achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.message.eaf7a98f3e'), 'review');
        exit;
    }
    if (!achbotSupportsPaymentSubject($connection, $payParam)) {
        showForm(\achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.message.c2f74dfd7e'));
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
            showForm(\achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.message.c6fe64557e'));
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
        showForm(\achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.message.09d73ebed8'));
        exit;
    }

    if($inbound_id == 0 && $payType == "BUY_SUB") {
        $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $server_info = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if($server_info['ucount'] == 0) {
            showForm(\achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.message.bdc1d950b2'));
            exit;
        }
    }elseif($payType == "BUY_SUB"){
        if($acount != 0 && $acount < $text){
            showForm(\achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.message.091b17d805', ['acount' => $acount]));
            exit();
        }
    }
    
    if($payType == "BUY_SUB") $type = \achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.type.e6343571a4');
    elseif($payType == "RENEW_ACCOUNT"){
        $type = \achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.type.df1c62b99e');
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
    elseif($payType == "RENEW_SCONFIG") $type = \achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.type.df1c62b99e');
    elseif($payType == "INCREASE_WALLET") $type =\achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.type.991ae08d64');
    elseif(preg_match('/^INCREASE_DAY_(\d+)_(\d+)/',$payType)) $type = \achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.type.884cfdce97');
    elseif(preg_match('/^INCREASE_VOLUME_(\d+)_(\d+)/',$payType)) $type = \achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.type.160a34aa6d');
    
    
    
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
            showForm(\achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.message.71632f6c74'));
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
                showForm(\achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.message.f4702323f6'), 'pending');
                exit;
            }
            // A previously observed provider payment must not be reopened
            // as a checkout from an old "continue payment" link.
            if ($attempt['order_hash'] === $hash_id &&
                (string)($attempt['payment_id'] ?? '') !== '') {
                // An observed child payment is not necessarily in an active
                // state: an already-quarantined attempt requires review.
                showForm(\achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.message.7de7c0e3e6'),
                    $attempt['stage'] === 'pending' ? 'pending' : 'review');
                exit;
            }
            if ($attempt['order_hash'] === $hash_id &&
                $attempt['stage'] === 'pending' &&
                $attempt['provider_reference'] === (string)$payParam['payid'] &&
                achbotNpValidInvoiceUrl($attempt['checkout_url'])) {
                header('Location: '.$attempt['checkout_url'], true, 302);
                exit;
            }
            showForm(\achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.message.eda31ba620'), 'review');
            exit;
        }
        if ($stored->num_rows > 1 || !empty($payParam['payid'])) {
            showForm(\achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.message.cdfa4a6049'), 'review');
            exit;
        }
        if (empty($nowpaymentsIpnSecret)) {
            showForm(\achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.message.c4414cfacc'));
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
            showForm(\achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.message.3a572723c5'));
            exit();
        }

        if(empty($paymentKeys['nowpayment'])){
            showForm(\achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.message.df2a7c68c7'));
            exit();
        }

        $base_url = 'https://api.nowpayments.io/v1/invoice';

        $quoteUsd = number_format(round($amount / $dollarPrice, 2), 2, '.', '');
        if ((float)$quoteUsd <= 0 || (int)$amount <= 0) {
            showForm(\achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.message.868477ce4f'));
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
                showForm(\achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.message.d591bf832a'));
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
            if (!$connection->begin_transaction())
                throw new RuntimeException('checkout_reservation_begin_failed');
            achbotPayUxLockDraftOwner($connection,(int)$payParam['user_id']);
            $stmt = $connection->prepare(
                "SELECT * FROM pays WHERE id=? FOR UPDATE");
            $stmt->bind_param('i', $orderId);
            if (!$stmt->execute()) throw new RuntimeException('checkout_pay_lock_failed');
            $locked = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$locked || (int)$locked['user_id']!==(int)$payParam['user_id'] ||
                (string)$locked['hash_id']!==$hash_id ||
                (string)$locked['type']!==(string)$payParam['type'] ||
                (int)($locked['order_id']??0)!==(int)($payParam['order_id']??0) ||
                (int)$locked['request_date']!==(int)$payParam['request_date'] ||
                (int)$locked['price']!==(int)$amount ||
                !achbotPayUxLockedCheckoutUsable($connection,$locked) ||
                !achbotPayUxAudienceAllowed($connection,'nowpayments',
                    (int)$locked['user_id'],true,(string)$locked['type']) ||
                !achbotSupportsPaymentSubject($connection,$locked,true)) {
                throw new RuntimeException('checkout_draft_no_longer_eligible');
            }
            $stmt = $connection->prepare("INSERT INTO payment_attempts
                (pay_row_id,provider,order_hash,provider_reference,checkout_url,quote_amount,quote_currency,
                 local_amount_toman,settlement_currency,minimum_settlement_amount,stage)
                VALUES (?,'nowpayments',?,NULL,NULL,?,'usd',?,?,?,'creating')");
            $stmt->bind_param('ississ', $orderId, $hash_id, $quoteUsd,
                $amount, $payoutCurrency, $minimumOutcome);
            $inserted=$stmt->execute();
            $insertCount=$stmt->affected_rows;
            $stmt->close();
            if ($inserted!==true || $insertCount!==1)
                throw new RuntimeException('checkout_ledger_insert_failed');
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
            showForm(\achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.message.b0a8fa5e67'), 'review');
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
            showForm(\achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.message.f3414a91d3'), 'review');
            exit();
        }

        $res = json_decode($responseBody, true);

        if($httpCode < 200 || $httpCode >= 300){
            $markCheckoutUncertain();
            showForm(\achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.message.5e673fef71'), 'review');
            exit();
        }

        if(empty($res['id']) || empty($res['invoice_url'])){
            $markCheckoutUncertain();
            showForm(\achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.message.9c2d3b3672'), 'review');
            exit();
        }

        $payid = (string)$res['id'];
        $invoiceUrl = (string)$res['invoice_url'];
        if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $payid) ||
            !achbotNpValidInvoiceUrl($invoiceUrl)) {
            $markCheckoutUncertain();
            showForm(\achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.message.8196433946'), 'review');
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
            showForm(\achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.message.0c31b5ab6a'), 'review');
            exit;
        }

        // The merchant can recover this exact provider invoice on every
        // subsequent click; also preserve a direct button in Telegram.
        if ($userId > 0) {
            $keyboard = json_encode(['inline_keyboard'=>[
                [['text'=>\achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.button.f81a8cfcdd'),
                  'url'=>$botUrl.'pay/?nowpayment&hash_id='.urlencode($hash_id)]],
                [['text'=>\achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.button.f0137e1007'),'callback_data'=>'ps_'.$hash_id]]
            ]], JSON_UNESCAPED_UNICODE);
            try {
                $notice=sendMessage(\achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.message.cd27d14216').achbotPayUxReference($payParam)
                    .\achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.message.0c4a9612f7')
                    .\achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.message.0a2e0bc7bc'),
                    $keyboard, 'HTML', $userId);
                achbotPayUxTrackMessage($connection,(int)$orderId,(int)$userId,$notice);
            } catch (Throwable $ignored) {
                // Durable invoice persists even if a Telegram send fails.
            }
        }
        header('Location: ' . $invoiceUrl, true, 302);
        exit();
    }
    
    elseif(isset($_GET['zarinpal'])){
        $marker=achbotReserveCheckoutProvider($connection,(int)$orderId,'zarinpal',$payParam);
        if ($marker === null) {
            $sharedTablesReady = achbotSharedPaymentTablesReady($connection);
            showForm($sharedTablesReady
                ? \achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.message.9dfdbeaa81')
                : \achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.message.71632f6c74'),
                $sharedTablesReady ? 'review' : null);
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
            showForm(\achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.message.1f2efee2e5'), 'review');
            exit;
        }
    }

    elseif(isset($_GET['nextpay'])){
        $marker=achbotReserveCheckoutProvider($connection,(int)$orderId,'nextpay',$payParam);
        if ($marker === null) {
            $sharedTablesReady = achbotSharedPaymentTablesReady($connection);
            showForm($sharedTablesReady
                ? \achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.message.9dfdbeaa81')
                : \achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.message.71632f6c74'),
                $sharedTablesReady ? 'review' : null);
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
        showForm(\achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.message.afca7c1801'), 'review');
    }
}


// The controller alone can supply the payment disposition. Free-form
// customer text and failed checkout attempts cannot prove failed settlement.
function showForm($msg, $presentation = null){
    require_once __DIR__ . '/PaymentPage.php';
    achbotRenderPaymentPage($msg, '', false, $presentation);
}
