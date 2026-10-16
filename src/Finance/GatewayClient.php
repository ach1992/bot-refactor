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

/** Validate original USD quote and paid in full before fixed-price delivery. */
function achbotNpValidFinalAmount(array $attempt, array $response): bool {
    if (strtolower((string)($response['price_currency'] ?? '')) !== 'usd' ||
        !isset($response['price_amount'], $response['pay_amount'],
                $response['actually_paid'], $response['pay_currency'])) return false;
    foreach (['price_amount','pay_amount','actually_paid'] as $field) {
        if (!is_numeric($response[$field]) || !is_finite((float)$response[$field]) ||
            (float)$response[$field] <= 0) return false;
    }
    $quote = (float)$attempt['quote_usd'];
    if ($quote <= 0 ||
        number_format((float)$response['price_amount'], 2, '.', '') !==
        number_format($quote, 2, '.', '')) return false;
    // Payment covering or unexpected underpayments cannot auto-deliver.
    return (float)$response['actually_paid'] + 0.00000001 >=
           (float)$response['pay_amount'];
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
