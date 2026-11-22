<?php
/** AChBot V8: source-pinned financial compatibility module. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__.'/../Diagnostics/EventLog.php';
/** MariaDB named locks are SERVER-wide, not scoped to the selected DB.
 * Independent bots have dedicated databases but may reuse payment IDs.
 * Scope the ONE hosted-worker lease by the connected database identity.
 */
function achbotHostedWorkerLockName(mysqli $db,int $payId): string {
    if ($payId<=0) throw new RuntimeException('hosted_worker_pay_invalid');
    $query=$db->query('SELECT DATABASE() AS schema_name');
    $schema=$query?$query->fetch_assoc()['schema_name']:null;
    if ($query) $query->free();
    if (!is_string($schema) || $schema==='')
        throw new RuntimeException('hosted_worker_schema_unknown');
    return 'achbot-hosted-'.substr(hash('sha256',$schema),0,20).'-'.$payId;
}

/** Dispatch workers borrow an already-held original payment session lease,
 * or acquire their own for the entire external request and its acknowledgement.
 * Archive checks the same lock. Never keep an InnoDB transaction across HTTP.
 * A lease borrowed from a hosted/API/manual parent must NOT be released here.
 */
function achbotOriginalDispatchLeaseBegin(mysqli $db,int $payId): string {
    if ($payId<=0) return 'unavailable';
    try {
        $name=achbotHostedWorkerLockName($db,$payId);
        $q=$db->prepare('SELECT IS_USED_LOCK(?) AS holder,CONNECTION_ID() AS self');
        if (!$q) throw new RuntimeException('dispatch_lease_probe_unavailable');
        $q->bind_param('s',$name);$q->execute();
        $state=$q->get_result()->fetch_assoc();$q->close();
        if (!$state) throw new RuntimeException('dispatch_lease_probe_missing');
        if ($state['holder']!==null)
            return (int)$state['holder']===(int)$state['self']?'borrowed':'busy';
        $q=$db->prepare('SELECT GET_LOCK(?,0) AS acquired');
        if (!$q) throw new RuntimeException('dispatch_lease_claim_unavailable');
        $q->bind_param('s',$name);$q->execute();
        $claimed=$q->get_result()->fetch_assoc();$q->close();
        return (int)($claimed['acquired']??0)===1?'owned':'busy';
    } catch (Throwable $error) {
        error_log('achbot_dispatch_lease_unavailable pay_id='.$payId.
            ' class='.get_class($error));
        return 'unavailable';
    }
}

function achbotOriginalDispatchLeaseEnd(mysqli $db,int $payId,string $lease): void {
    if ($lease!=='owned') return;
    try {
        $name=achbotHostedWorkerLockName($db,$payId);
        $q=$db->prepare('SELECT RELEASE_LOCK(?) AS released');
        if (!$q) throw new RuntimeException('dispatch_lease_release_unavailable');
        $q->bind_param('s',$name);$q->execute();
        $released=$q->get_result()->fetch_assoc();$q->close();
        if ((int)($released['released']??0)!==1)
            throw new RuntimeException('dispatch_lease_release_unconfirmed');
    } catch (Throwable $error) {
        // A disconnected session releases its named locks automatically.
        error_log('achbot_dispatch_lease_release_unavailable pay_id='.$payId.
            ' class='.get_class($error));
    }
}

/** One source of truth for active operator-attention cases. Archival is
 * a terminal LOCAL automation fence, not a merchant settlement or refund.
 * Late signed IPN evidence is separate from the immutable archived attempt.
 */
function achbotGatewayAttentionScope(?mysqli $db=null): string {
    // Hosted-only installations still have the original review schema.
    // API cases enter the shared inbox only when their optional evidence
    // and delivery tables are actually installed.
    $apiReady=false;$manualReady=false;$followupReady=false;
    if ($db instanceof mysqli) {
        try {
            $q=$db->query('SELECT pay_row_id,state FROM payment_delivery_intents LIMIT 0');
            if ($q) {$q->free();$manualReady=true;}
            $q=$db->query('SELECT pay_row_id,effect_kind,state FROM service_effects LIMIT 0');
            if ($q) $q->free();else $manualReady=false;
            $q=$db->query('SELECT evidence_profile,evidence_state,evidence_review_sent_at,
                evidence_fulfillment_state FROM payment_attempts LIMIT 0');
            if ($q) {$q->free();$apiReady=$manualReady;}
            $q=$db->query('SELECT pay_row_id,state FROM payment_followup_intents LIMIT 0');
            if ($q) {$q->free();$followupReady=true;}
        } catch (Throwable $error) {
            // A missing optional schema phase is not an unpaid invoice or
            // permission to fabricate evidence. Hosted cases remain visible.
        }
    }
    // Age, a never-paid invoice, or generic unknown verification is NOT
    // financial evidence. The inbox is for observed money, a genuine
    // verification transport outage, or a previously started local effect.
    $apiSql=$apiReady ? "            OR (a.provider IN ('bank_transfer','gift_card')
                AND a.evidence_profile IS NOT NULL
                AND (
                    (p.state='processing' AND p.approved_by=0)
                    OR (p.state IN ('pending','have_sent')
                        AND EXISTS (SELECT 1 FROM payment_transactions et
                            WHERE et.attempt_id=a.id AND et.evidence_state='proven'))
                    OR (p.state='have_sent'
                        AND a.evidence_review_sent_at IS NOT NULL
                        AND a.evidence_state IN ('review','uncertain','manual','invalid_code'))
                ))" : '';
    $followupSql=$followupReady
        ? " OR EXISTS (SELECT 1 FROM payment_followup_intents fu
                WHERE fu.pay_row_id=p.id
                  AND fu.state IN ('pending','dispatching','unknown'))"
        : '';
    $manualSql=$manualReady ? " OR (a.provider IN (
                'bank_transfer','gift_card','manual_usdt','manual_trx')
            AND p.state='processing'
            AND (p.approved_by>0 OR EXISTS (
                SELECT 1 FROM service_effects se WHERE se.pay_row_id=p.id)))" : '';
    // Terminal finance/native state is not notification completion.
    // Include PENDING/UNKNOWN original packets for any hosted/manual method,
    // but never invent a new payment or re-dispatch an UNKNOWN notification.
    $deliverySql=$manualReady ? " OR (p.state IN (
            'approved','paid','auto_cart_approved','paid_with_wallet')
            AND (EXISTS (SELECT 1 FROM payment_delivery_intents dl
                    WHERE dl.pay_row_id=p.id
                      AND dl.state IN ('pending','dispatching','unknown'))
                 $followupSql))" : '';
    // An original Wallet admin report is independent of customer delivery.
    // Preserve unresolved original fanout even when the finance pay and
    // customer acknowledgement have both become terminal. The JSON marker
    // is exact per payment; a plain old paid invoice is never admitted.
    $reportSql=$manualReady ? " OR (p.state='paid_with_wallet'
        AND a.provider='wallet'
        AND (p.type='RENEW_ACCOUNT' OR p.type='BUY_SUB'
            OR p.type LIKE 'INCREASE\\_DAY\\_%'
            OR p.type LIKE 'INCREASE\\_VOLUME\\_%')
        AND EXISTS (SELECT 1 FROM setting sr
            WHERE sr.type=CONCAT('WALLET_ADDON_REPORT:',p.id)
              AND JSON_VALID(sr.value)
              AND JSON_UNQUOTE(JSON_EXTRACT(sr.value,'$.pay_id'))=CAST(p.id AS CHAR)
              AND JSON_UNQUOTE(JSON_EXTRACT(sr.value,'$.state')) IN
                ('pending','dispatching','unknown_or_partial','manual_dispatching')))" : '';
    // Wallet debits are already committed before `processing_wallet`. Older
    // untouched invoices are NOT admitted. A wallet invoice may intentionally
    // have no hosted payment_attempt: INNER JOIN hid Hell #113346 forever.
    // Suppress a transient active callback for ten minutes; its original
    // Native/pay journals are not yet safe to interpret as terminal.
    $walletSql=" OR (p.state='processing_wallet'
        AND p.request_date<=UNIX_TIMESTAMP()-600
        AND (p.type IN ('BUY_SUB','RENEW_ACCOUNT','RENEW_SCONFIG')
            OR p.type LIKE 'INCREASE\_DAY\_%'
            OR p.type LIKE 'INCREASE\_VOLUME\_%')
        AND (a.id IS NULL OR a.provider='wallet'))";
    return "a.review_archived_at IS NULL
        AND (a.provider IN ('nowpayments','nextpay','zarinpal')
        AND (
            (p.state IN ('pending','send') AND (
                (a.stage='review' AND (
                    (a.provider='nowpayments' AND a.provider_status IN (
                        'partially_paid','confirming','confirmed','sending',
                        'finished','refunded','amount_mismatch','multiple_payments'))
                    OR (a.provider IN ('nextpay','zarinpal') AND
                        a.provider_status='verification_unavailable')
                    OR a.provider_status='operator_fulfillment_unconfirmed'
                    OR (a.provider_status IN (
                        'fulfillment_failed','fulfillment_unconfirmed',
                        'fulfillment_needs_review')
                        AND a.updated_at<=NOW()-INTERVAL 10 MINUTE)))
                OR (a.stage='fulfilling' AND
                    a.updated_at<=NOW()-INTERVAL 10 MINUTE)))
            OR (p.state IN ('processing','paid') AND
                a.stage IN ('review','fulfilling') AND
                a.provider_status IN ('fulfillment_started',
                    'fulfillment_unconfirmed','fulfillment_failed',
                    'fulfillment_needs_review','operator_fulfillment_unconfirmed')
                AND a.updated_at<=NOW()-INTERVAL 10 MINUTE)
            OR (p.state='service_superseded' AND a.provider='nowpayments'
                AND EXISTS (SELECT 1 FROM payment_transactions t
                    WHERE t.attempt_id=a.id AND t.provider=a.provider
                    AND t.observed_status IN (
                        'partially_paid','confirming','confirmed','sending','finished')))
            OR (p.state='service_superseded' AND
                a.provider IN ('nextpay','zarinpal') AND
                a.stage='review' AND a.provider_status='retired_verified_funds'
                AND EXISTS (SELECT 1 FROM payment_transactions t
                    WHERE t.attempt_id=a.id AND t.provider=a.provider
                      AND t.payment_id=a.provider_reference
                      AND t.observed_status='finished'))
        )
            $apiSql
            $manualSql
            $deliverySql
            $reportSql
            $walletSql
        )";
}

/** Same query powers the inbox and the original API receipt's review link.
 * Pure local read: no customer/provider side effects.
 */
function achbotGatewayAttentionCaseOpen(mysqli $db,int $payId): bool {
    if ($payId<=0) return false;
    try {
        // The original API evidence schema can be present before the additive
        // 022 Archive migration. Do not offer a case link whose action cannot
        // run, or emit an SQL warning on every legitimate older API receipt.
        // Only the known absent column is optional; other SQL failures remain
        // diagnosable instead of being mistaken for missing installation.
        try {
            $probe=$db->query('SELECT review_archived_at FROM payment_attempts LIMIT 0');
        } catch (mysqli_sql_exception $error) {
            if ((int)$error->getCode()===1054) return false;
            throw $error;
        }
        if (!($probe instanceof mysqli_result)) {
            if ((int)$db->errno===1054) return false;
            throw new RuntimeException('attention_archive_schema_probe_failed');
        }
        $probe->free();
        $scope=achbotGatewayAttentionScope($db);
        // Original wallet payments can have no hosted attempt; this read
        // must use the same LEFT JOIN as the inbox/detail renderer.
        $q=$db->prepare("SELECT p.id FROM pays p
            LEFT JOIN payment_attempts a ON a.pay_row_id=p.id
            WHERE p.id=? AND $scope LIMIT 1");
        $q->bind_param('i',$payId);$q->execute();
        $found=$q->get_result()->fetch_assoc();$q->close();
        return $found!==null;
    } catch (Throwable $error) {
        error_log('achbot_attention_case_read_unavailable pay_id='.$payId.
            ' class='.get_class($error));
        return false;
    }
}






/**
 * Classify only server-verified provider outcomes; browser redirects are not proof.
 * Do not conflate blockchain confirmation with merchant payout completion.
 * An unknown value remains reviewable rather than becoming a false negative.
 *
 * This small adapter decision is shared by the present callback routes;
 * durable payment/effect claims and external fulfillment need a later gate.
 */
/**
 * A partial 003 schema must not permit checkout issuance: a NOWPayments
 * invoice without child-evidence persistence would strand paid customers.
 * This is a basic availability probe, NOT approval of actual DDL/install.
 */
function achbotSharedPaymentTablesReady(mysqli $db): bool {
    try {
        foreach (['payment_attempts','payment_transactions'] as $table) {
            // Names are selected from a fixed internal allowlist only.
            $probe=$db->query('SELECT id FROM '.$table.' LIMIT 0');
            if (!($probe instanceof mysqli_result)) return false;
            $probe->free();
        }
        return true;
    } catch (Throwable $ignored) {
        return false;
    }
}

/**
 * Legacy pays.payid has no provider dimension or uniqueness constraint.
 * New provider-scoped attempts MAY reuse an ID held by a different NEW
 * provider, but must fail closed if an older unscoped row has that ID.
 * A database error is unknown identity, not permission to settle or redirect.
 * Called only when the new payment_attempts table is available.
 */
function achbotHasUnscopedPaymentReferenceCollision(mysqli $db,
                                                     string $reference,
                                                     int $ownerRowId): bool {
    if ($reference==='' || $ownerRowId<=0) return true;
    try {
        $stmt=$db->prepare("SELECT p.id FROM pays p
            WHERE p.payid=? AND p.id<>?
              AND NOT EXISTS (SELECT 1 FROM payment_attempts a
                              WHERE a.pay_row_id=p.id)
            LIMIT 1");
        $stmt->bind_param('si',$reference,$ownerRowId);
        $stmt->execute();
        $collision=$stmt->get_result()->num_rows>0;
        $stmt->close();
        return $collision;
    } catch (Throwable $error) {
        return true;
    }
}

function achbotVerifiedGatewayOutcome(string $provider, $status): string {
    if ($provider === 'nowpayment') {
        if (!is_string($status)) return 'review';
        return match ($status) {
            'finished' => 'verified',
            'partially_paid' => 'partial',
            'waiting', 'confirming', 'confirmed', 'sending' => 'pending',
            'failed', 'expired' => 'rejected',
            'refunded' => 'review',
            default => 'review',
        };
    }
    if ($provider === 'nextpay') {
        if ($status === 0 || $status === '0') return 'verified';
        if (in_array($status, [-2, '-2', -4, '-4'], true)) return 'rejected';
        if (in_array($status, [-1, '-1', -3, '-3'], true)) return 'pending';
    }
    return 'review';
}


/** Provider IPN payload signatures use recursively sorted JSON (SHA512). */
function achbotNpSortedPayload($value) {
    if (!is_array($value)) return $value;
    if (!array_is_list($value)) ksort($value, SORT_STRING);
    foreach ($value as $key => $nested) {
        $value[$key] = achbotNpSortedPayload($nested);
    }
    return $value;
}

function achbotNpValidIpn(string $raw, string $signature, string $secret): ?array {
    if ($secret === '' || strlen($raw) < 2 || strlen($raw) > 65536 ||
        !preg_match('/^[a-f0-9]{128}$/i', $signature)) return null;
    try {
        $payload = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $ignored) {
        return null;
    }
    if (!is_array($payload) || array_is_list($payload)) return null;
    $sorted = json_encode(achbotNpSortedPayload($payload),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($sorted === false) return null;
    $expected = hash_hmac('sha512', $sorted, trim($secret));
    return hash_equals($expected, strtolower($signature)) ? $payload : null;
}

/** New invoice URLs are kept for retry, but redirect only to provider HTTPS. */
function achbotNpValidInvoiceUrl(string $url): bool {
    if (strlen($url) > 2048 || strlen($url) < 15) return false;
    $parts = parse_url($url);
    if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' ||
        isset($parts['user']) || isset($parts['pass']) ||
        isset($parts['port'])) return false;
    $host = strtolower($parts['host'] ?? '');
    return $host === 'nowpayments.io' || str_ends_with($host, '.nowpayments.io');
}

/**
 * Compare provider decimal values exactly without relying on floating point
 * or an optional PHP extension. Numeric exponent notation is not accepted as
 * money, and negative, missing or malformed amounts are never settlement.
 */
function achbotNpDecimalAtLeast($amount, $minimum): bool {
    $valid = static fn($v): bool =>
        (is_string($v) || is_int($v) || is_float($v)) &&
        preg_match('/^\\d{1,18}(?:\\.\\d{1,18})?$/D',(string)$v) === 1;
    if (!$valid($amount) || !$valid($minimum)) return false;
    $parts = static function($v): array {
        $p=explode('.',(string)$v,2);
        $whole=ltrim($p[0],'0');
        return [$whole===''?'0':$whole,str_pad($p[1]??'',18,'0')];
    };
    [$i,$f]=$parts($amount);
    [$mi,$mf]=$parts($minimum);
    return strlen($i)>strlen($mi) ||
        (strlen($i)===strlen($mi) &&
         (strcmp($i,$mi)>0 || ($i===$mi && strcmp($f,$mf)>=0)));
}

/** Snapshot an explicit operator-approved minimum net payout per USD. */
function achbotNpMinimumPayout(string $quoteUsd, string $rate): ?string {
    if (!preg_match('/^\\d{1,12}\\.\\d{2}$/D',$quoteUsd) ||
        !preg_match('/^\\d{1,6}(?:\\.\\d{1,6})?$/D',$rate)) return null;
    [$dollars,$cents]=explode('.',$quoteUsd,2);
    [$units,$fraction]=array_pad(explode('.',$rate,2),2,'');
    $quoteCents=(int)$dollars*100+(int)$cents;
    $rateMicros=(int)$units*1000000+(int)str_pad($fraction,6,'0');
    if ($quoteCents<=0 || $rateMicros<=0 ||
        $quoteCents>intdiv(PHP_INT_MAX-99,$rateMicros)) return null;
    $micros=intdiv($quoteCents*$rateMicros+99,100); // ceil to 1e-6
    return intdiv($micros,1000000).'.'.str_pad((string)($micros%1000000),6,'0',STR_PAD_LEFT);
}

/** NOWPayments' authenticated FINAL status is the merchant settlement
 * decision. Crypto deposit/payout amounts, fees and assets are diagnostics,
 * never a secondary fulfillment threshold.
 *
 * The USD price is different: it identifies WHAT invoice was actually
 * settled. Require the immutable original USD quote, normalized exactly
 * as a decimal, in addition to the payment/invoice/order references enforced
 * by the original reconciliation/one-shot journals. A merchant FINISHED
 * response for a DIFFERENT USD invoice price cannot satisfy this original.
 */
function achbotNpValidFinalAmount(array $attempt,array $response): bool {
    $original=$attempt['quote_amount']??null;
    $remote=$response['price_amount']??null;
    return ($attempt['provider']??null)==='nowpayments' &&
        strtolower((string)($attempt['quote_currency']??''))==='usd' &&
        strtolower((string)($response['price_currency']??''))==='usd' &&
        ($response['payment_status']??null)==='finished' &&
        achbotNpDecimalAtLeast($original,$remote) &&
        achbotNpDecimalAtLeast($remote,$original);
}

function achbotNpGetVerifiedPayment(string $paymentId, string $apiKey): ?array {
    if ($apiKey === '' || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $paymentId)) return null;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.nowpayments.io/v1/payment/' . rawurlencode($paymentId));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['x-api-key: ' . $apiKey]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_TIMEOUT, 7);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = (int)curl_errno($ch);
    unset($ch); // PHP 8.5: curl_close() is a deprecated no-op.
    if ($raw === false || $code !== 200 || strlen((string)$raw) > 65536) {
        achbotDiagnosticEvent('achbot.finance.hosted_verify_http',[
            'provider'=>'nowpayments','http_status'=>$code,'curl_errno'=>$errno,
            'reason'=>$raw===false?'transport_unavailable':
                ($code!==200?'http_unexpected':'response_oversized')],'warning');
        return null;
    }
    $result = json_decode($raw, true);
    return is_array($result) ? $result : null;
}

function NOWPayments($method, $endpoint, $datas = []){
    global $paymentKeys;

    $base_url = 'https://api.nowpayments.io/v1/';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

    switch ($method) {
        case 'GET':
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-API-KEY: ' . $paymentKeys['nowpayment']]);
            if(!empty($datas)) {
                if(is_array($datas)) {
                    $parameters = http_build_query($datas);
                    curl_setopt($ch, CURLOPT_URL, $base_url . $endpoint . '?' . $parameters);
                } else {
                    if($endpoint == 'payment') curl_setopt($ch, CURLOPT_URL,$base_url . $endpoint . '/' . $datas);
                }
            } else {
                curl_setopt($ch, CURLOPT_URL, $base_url . $endpoint);
            }
            break;

        case 'POST':
            $datas = json_encode($datas);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-API-KEY: ' . $paymentKeys['nowpayment'], 'Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $datas);
            curl_setopt($ch, CURLOPT_URL, $base_url . $endpoint);
            break;

        default:
            break;
    }

    $res = curl_exec($ch);
    
    $errno=(int)curl_errno($ch);
    $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    unset($ch);
    if ($errno!==0 || $res===false) {
        achbotDiagnosticEvent('achbot.finance.hosted_request_http',[
            'provider'=>'nowpayments','method'=>(string)$method,
            'http_status'=>$http,'curl_errno'=>$errno,
            'reason'=>'transport_unavailable'],'warning');
        return null;
    }
    if ($http<200 || $http>=300)
        achbotDiagnosticEvent('achbot.finance.hosted_request_http',[
            'provider'=>'nowpayments','method'=>(string)$method,
            'http_status'=>$http,'reason'=>'http_unexpected'],'warning');
    return json_decode($res);
}
