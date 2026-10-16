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
