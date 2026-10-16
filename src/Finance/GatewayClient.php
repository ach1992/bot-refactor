<?php
/** AChBot V8: source-pinned financial compatibility module. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
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

/**
 * Server-verified fixed-price funds are insufficient on status alone.
 * An operator-approved payout policy is captured at invoice issuance, and
 * the ACTUAL net merchant outcome must cover that immutable threshold in
 * exactly the same crypto asset/network; otherwise the case stays in review.
 */
function achbotNpValidFinalAmount(array $attempt, array $response): bool {
    $expectedCurrency=strtolower((string)($attempt['settlement_currency']??''));
    if ($expectedCurrency==='' ||
        (string)($attempt['provider']??'')!=='nowpayments' ||
        (string)($attempt['quote_currency']??'')!=='usd' ||
        strtolower((string)($response['price_currency']??''))!=='usd' ||
        strtolower((string)($response['outcome_currency']??''))!==$expectedCurrency ||
        !preg_match('/^[a-z0-9]{2,32}$/D', $expectedCurrency) ||
        !isset($response['price_amount'],$response['pay_amount'],
               $response['actually_paid'],$response['pay_currency'],
               $response['outcome_amount']) ||
        !preg_match('/^[a-z0-9]{2,32}$/Di',(string)$response['pay_currency']))
        return false;
    if (!achbotNpDecimalAtLeast($attempt['minimum_settlement_amount']??null,'0.000001') ||
        !achbotNpDecimalAtLeast($response['outcome_amount'],$attempt['minimum_settlement_amount']) ||
        !achbotNpDecimalAtLeast($response['actually_paid'],$response['pay_amount']) ||
        !achbotNpDecimalAtLeast($response['pay_amount'],'0.000000000000000001'))
        return false;
    $quote = (string)($attempt['quote_amount']??'');
    if (!preg_match('/^\\d{1,12}\\.\\d{2}(?:0{0,6})?$/D',$quote) ||
        !achbotNpDecimalAtLeast($response['price_amount'],$quote) ||
        !achbotNpDecimalAtLeast($quote,$response['price_amount']))
        return false;
    return true;
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
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false || $code !== 200 || strlen((string)$raw) > 65536) return null;
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
    
    if(curl_error($ch)) var_dump(curl_error($ch));
    else return json_decode($res);
}
