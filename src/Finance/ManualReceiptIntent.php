<?php
declare(strict_types=1);

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

// Original manual receipt/destination format; no panel or service dependencies.
function achbotManualUsdtWalletValid(string $wallet, string $network): bool {
    if (!in_array($network, ['BEP20','ERC20','TRC20'], true)) return false;
    if ($network === 'TRC20') return achbotManualTronAddressValid($wallet);
    return preg_match('/^0x[0-9a-fA-F]{40}$/D', $wallet) === 1;
}

/**
 * Offline, dependency-free TRON Base58Check validation shared by TRC20 USDT
 * and native TRX. A T-shaped string is not necessarily a usable destination.
 * Decode with fixed-width base-256 arithmetic (no GMP/BCMath dependency),
 * verify the 0x41 + 20-byte payload and double-SHA256 checksum, then require
 * canonical Base58 round-trip. No chain lookup or ownership claim is made.
 */
function achbotManualTronAddressValid(string $wallet): bool {
    if (strlen($wallet) !== 34 || $wallet[0] !== 'T') return false;
    $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
    $decoded = array_fill(0, 25, 0);
    for ($i = 0; $i < 34; $i++) {
        $digit = strpos($alphabet, $wallet[$i]);
        if ($digit === false) return false;
        $carry = $digit;
        for ($j = 24; $j >= 0; $j--) {
            $carry += $decoded[$j] * 58;
            $decoded[$j] = $carry & 0xff;
            $carry = intdiv($carry, 256);
        }
        // More than 25 decoded bytes is never a TRON Base58Check address.
        if ($carry !== 0) return false;
    }
    if ($decoded[0] !== 0x41) return false;
    $binary = pack('C*', ...$decoded);
    $payload = substr($binary, 0, 21);
    $checksum = substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4);
    if (!hash_equals($checksum, substr($binary, 21, 4))) return false;

    // Only one canonical Base58 spelling of these exact 25 bytes is allowed.
    // The verified nonzero 0x41 prefix means no leading '1' zero-byte padding.
    $roundTrip = $decoded;
    $canonical = '';
    do {
        $remainder = 0;
        $nonzero = false;
        foreach ($roundTrip as $index => $byte) {
            $dividend = $remainder * 256 + $byte;
            $roundTrip[$index] = intdiv($dividend, 58);
            $remainder = $dividend % 58;
            if ($roundTrip[$index] !== 0) $nonzero = true;
        }
        $canonical = $alphabet[$remainder] . $canonical;
    } while ($nonzero);
    return hash_equals($wallet, $canonical);
}

function achbotManualIntentEncode(array $intent): ?string {
    $encoded = json_encode(['v' => 1] + $intent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($encoded) && strlen($encoded) <= 2048 ? $encoded : null;
}
function achbotManualIntentDecode(string $saved, string $method): ?array {
    $intent = json_decode($saved, true);
    if (is_array($intent) && ($intent['v'] ?? null) === 1 &&
        ($intent['method'] ?? null) === $method) {
        if (in_array($method, ['manual_usdt','manual_trx'], true)) {
            if (!is_string($intent['network'] ?? null) ||
                !is_string($intent['wallet'] ?? null)) return null;
            if ($method === 'manual_trx' &&
                ($intent['network'] !== 'TRON' ||
                 !achbotManualTronAddressValid($intent['wallet']))) return null;
            if ($method === 'manual_usdt' &&
                !achbotManualUsdtWalletValid($intent['wallet'], $intent['network'])) return null;
        }
        return $intent;
    }
    return null;
}

/** Method-specific destination and commercial quote, shared with the original
 * issuance path during refactoring. The existing validated configuration
 * readers are supplied by ReceiptHelpers; no SQL transaction or Native I/O
 * is owned here. */
function achbotManualBuildQuote(int $price,string $method,?string $chosenNetwork,array $keys): array {
    $options=achbotManualConfigurations($method,$keys);
    $config=$chosenNetwork!==null?($options[$chosenNetwork]??null):
        (count($options)===1?reset($options):null);
    if ($price<=0 || $config===null) throw new DomainException('manual_quote_unavailable');
    $quote=(string)$price;
    $currency='toman';
    $settlementNetwork=null;
    $intent=['method'=>$method];
    if ($method==='bank_transfer') {
        $intent+=$config;
        $quote=(string)generateHigherRandomizedAmount($price)['toman'];
    } elseif ($method==='gift_card') {
        $intent+=$config;
    } else {
        $rate=$config['rate'];
        $precision=$method==='manual_trx'?6:2;
        $factor=10**$precision;
        $rawQuote=$price/$rate;
        // Price is stored as INT and the validated rate is positive and
        // >= 1; the former arbitrary 10^12 ceiling was unreachable.
        if (!is_finite($rawQuote) || $rawQuote<=0)
            throw new DomainException('manual_quote_unavailable');
        $quote=number_format(ceil($rawQuote*$factor)/$factor,$precision,'.','');
        $settlementNetwork=$config['network'];
        $intent['network']=$config['network'];
        $intent['wallet']=$config['wallet'];
        $intent['rate_toman']=(string)$rate;
        $currency=$method==='manual_trx'?'TRX':'USDT';
    }
    $destination=achbotManualIntentEncode($intent);
    if ($destination===null) throw new DomainException('manual_quote_unavailable');
    return ['amount'=>$quote,'currency'=>$currency,'settlement_currency'=>$settlementNetwork,'destination'=>$destination];
}
