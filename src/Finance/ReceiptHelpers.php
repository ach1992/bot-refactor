<?php
/** AChBot V8: source-pinned financial compatibility module. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

if (!function_exists('payTypeToDescription')) {
    function payTypeToDescription($payType)
    {
        require_once dirname(__DIR__, 2) . '/src/Presentation/Labels.php';
        return \AchBot\Presentation\Labels\paymentType($payType);
    }
}

if (!function_exists('addToWallet')) {
    function addToWallet($connection, $userId, $amount)
    {
        $amount = (int)$amount;
        $userId = (int)$userId;

        // Confirm the exact row and avoid INT overflow while retaining
        // intentional negative administrator debt.
        if ($userId<=0 || $amount<=0 || $amount>2147483647) return false;
        $maximum=2147483647-$amount;
        try {
            $stmt=$connection->prepare("UPDATE users SET wallet=wallet+?
                WHERE userid=? AND wallet<=?");
            $stmt->bind_param('iii',$amount,$userId,$maximum);
            $saved=$stmt->execute();
            $changed=$stmt->affected_rows;
            $stmt->close();
            return $saved && $changed===1;
        } catch (\Throwable $error) {
            return false;
        }
    }
}

if (!function_exists('notifyAllAdminsSimple')) {
    function notifyAllAdminsSimple($text, $admin, $parseMode = null, $replyMarkup = null, $rewardChannel = null)
    {
        if ($rewardChannel === null) {
            global $botState;
            $rewardChannel = $botState['rewardChannel'] ?? null;
        }

        if (!empty($admin)) {
            sendMessage($text, $replyMarkup, $parseMode, $admin);
        }

        // sendToAllAdmins('sendMessage', ['text' => $text, 'reply_markup' => $replyMarkup, 'parse_mode' => $parseMode]);

        if (!empty($rewardChannel)) {
            sendMessage($text, $replyMarkup, $parseMode, $rewardChannel);
        }
    }
}

if (!function_exists('refundToWalletAndNotify')) {
    function refundToWalletAndNotify(
        $connection,
        $userId,
        $amount,
        $gateType,
        $admin,
        $adminTextSuffix,
        $rewardChannel = null,
        $userReasonText = null
    ) {
        if (!addToWallet($connection, $userId, $amount)) return false;

        $userMsg = "✅ مبلغ " . number_format($amount) . " تومان به حساب شما اضافه شد.";
        if (!empty($userReasonText)) {
            $userMsg .= "\n\n" . $userReasonText;
        }
        sendMessage($userMsg, null, null, $userId);

        $adminText = "✅ مبلغ " . number_format($amount) . " تومان به کیف پول کاربر $userId توسط درگاه $gateType اضافه شد. " . $adminTextSuffix;

        if ($rewardChannel === null) {
            global $botState;
            $rewardChannel = $botState['rewardChannel'] ?? null;
        }

        notifyAllAdminsSimple($adminText, $admin, null, null, $rewardChannel);
        return true;
    }
}

function dbFetchPayByHash(mysqli $connection, string $hashId): ?array {
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? LIMIT 1");
    $stmt->bind_param("s", $hashId);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    if (!$res || $res->num_rows == 0) return null;
    return $res->fetch_assoc();
}

function prepareStartFallback() {
    global $text, $data;
    setUser("none");
    setUser("", "temp");
    $text = "/start";
    $data = "";
}

function inlineKeyboard(array $rows): string {
    require_once dirname(__DIR__, 2) . '/src/Telegram/Markup.php';
    return \AchBot\Telegram\Markup\inlineKeyboard($rows);
}

function generateHigherRandomizedAmount(int $amountToman): array {
    $amountToman = max(1, $amountToman);

    do {
        $lastThree = rand(100, 999);
    } while ($lastThree % 10 == 0 || $lastThree % 100 == 0);

    $randomizedToman = $amountToman + $lastThree;
    $randomizedRial = $randomizedToman * 10;

    return [
        'toman' => $randomizedToman,
        'rial'  => $randomizedRial,
    ];
}

/**
 * Manual payment choice is durable and mutually exclusive with hosted
 * gateway claims in the existing shared ledger. No schema or pays.payid reuse.
 */
function achbotManualMethod(string $choice): ?string {
    return match ($choice) {
        'CartToCart', 'bank_transfer' => 'bank_transfer',
        'GiftCard', 'gift_card' => 'gift_card',
        'ManualUsdt', 'manual_usdt' => 'manual_usdt',
        'ManualTrx', 'manual_trx' => 'manual_trx',
        default => null
    };
}

function achbotManualLabel(string $method): string {
    return match ($method) {
        'gift_card' => 'گیفت‌کارت',
        'manual_usdt' => 'تتر دستی (USDT)',
        'manual_trx' => 'ترون دستی (TRX)',
        default => 'کارت‌به‌کارت بانکی'
    };
}

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

/** Validated explicit bot-setting rate; no private or network fallback. */
function achbotUsdtRateValue($raw): ?float {
    if ((!is_string($raw) && !is_int($raw) && !is_float($raw)) ||
        !is_numeric($raw)) return null;
    $rate=(float)$raw;
    return is_finite($rate) && $rate>=100 && $rate<=1000000000 ? $rate : null;
}

/** A cleared optional agent override deliberately reuses the normal rate. */
function achbotUsdtRate(bool $agent=false, ?array $keys=null): ?float {
    $keys=$keys ?? (is_array($GLOBALS['paymentKeys']??null) ? $GLOBALS['paymentKeys'] : []);
    if ($agent && array_key_exists('usdtAgentRateToman',$keys) &&
        $keys['usdtAgentRateToman']!=='')
        return achbotUsdtRateValue($keys['usdtAgentRateToman']);
    return achbotUsdtRateValue($keys['manualUsdtRateToman']??null);
}

/** Quote/menu role comes from the actual account, never callback or cached UI. */
function achbotUsdtRateForUser(mysqli $db,int $userId): ?float {
    if ($userId<=0) return null;
    try {
        $stmt=$db->prepare('SELECT is_agent FROM users WHERE userid=? LIMIT 2');
        if (!$stmt) return null;
        $stmt->bind_param('i',$userId);
        if (!$stmt->execute()) { $stmt->close(); return null; }
        $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
        return count($rows)===1 ? achbotUsdtRate((int)$rows[0]['is_agent']===1) : null;
    } catch (Throwable $ignored) { return null; }
}

/**
 * All available manual-crypto destinations are independent. The existing
 * manualUsdtNetwork/manualUsdtWallet pair remains a read-compatible fallback
 * for already configured tenants; new per-network entries override it.
 *
 * A rate and destination are required before an option appears. Neither a
 * screenshot nor an operator click is automatic blockchain settlement proof.
 */
function achbotManualUsdtConfiguration(?string $network = null): ?array {
    global $paymentKeys;
    $keys = is_array($paymentKeys ?? null) ? $paymentKeys : [];
    if ($network === null) {
        $network = (string)($keys['manualUsdtNetwork'] ?? 'BEP20');
    }
    if (!in_array($network, ['BEP20','TRC20','ERC20'], true)) return null;
    $field = 'manualUsdtWallet' . $network;
    $wallet = array_key_exists($field, $keys)
        ? trim((string)$keys[$field])
        : ((string)($keys['manualUsdtNetwork'] ?? 'BEP20') === $network
            ? trim((string)($keys['manualUsdtWallet'] ?? '')) : '');
    if (!achbotManualUsdtWalletValid($wallet, $network)) return null;
    $rate=achbotUsdtRate(false,$keys);
    $agentRate=achbotUsdtRate(true,$keys);
    if ($rate===null && $agentRate===null) return null;
    return ['network'=>$network, 'wallet'=>$wallet,
            'rate'=>$rate, 'agent_rate'=>$agentRate];
}

/** Independent, explicit TRX/Toman rate; never infer it from legacy checker. */
function achbotManualTrxConfiguration(): ?array {
    global $paymentKeys;
    $keys = is_array($paymentKeys ?? null) ? $paymentKeys : [];
    $wallet = trim((string)($keys['manualTrxWallet'] ?? ''));
    $rawRate = $keys['manualTrxRateToman'] ?? null;
    if (!achbotManualTronAddressValid($wallet) ||
        !is_scalar($rawRate) ||
        !preg_match('/^[1-9][0-9]{0,8}$/D', (string)$rawRate)) return null;
    $rate = (float)$rawRate;
    if (!is_finite($rate) || $rate <= 0) return null;
    return ['network'=>'TRON', 'wallet'=>$wallet,
            'rate'=>$rate, 'agent_rate'=>$rate];
}

/**
 * Selection is per order, not per runtime-global wallet. All four options
 * share the existing manual photo/approval pipeline. A claimed quote is
 * permanent even when admin settings change later.
 */
function achbotManualCryptoOptions(?int $userId=null): array {
    global $botState, $connection;
    if (($botState['manualUsdtState'] ?? 'off') !== 'on') return [];
    $available = [];
    $rateUsable=$userId===null || achbotUsdtRateForUser($connection,$userId)!==null;
    foreach (['BEP20'=>'b','TRC20'=>'c','ERC20'=>'e'] as $network=>$tag) {
        if (achbotManualUsdtConfiguration($network) !== null &&
            $rateUsable) {
            $available[$tag] = ['provider'=>'manual_usdt','network'=>$network,
                                'label'=>'💎 USDT — '.$network];
        }
    }
    if (achbotManualTrxConfiguration() !== null) {
        $available['t']=['provider'=>'manual_trx','network'=>'TRON',
                          'label'=>'🔴 TRX — TRON'];
    }
    return $available;
}

function achbotManualCryptoEnabled(?int $userId=null): bool {
    return achbotManualCryptoOptions($userId) !== [];
}

/** No implicit recipient or gift seller: invalid settings disable initiation. */
function achbotManualBankIdentity(): ?array {
    global $paymentKeys;
    $keys = is_array($paymentKeys ?? null) ? $paymentKeys : [];
    $account = trim((string)($keys['bankAccount'] ?? ''));
    $holder = trim((string)($keys['holderName'] ?? ''));
    if ($account === '' || $holder === '' || strlen($account) > 150 || strlen($holder) > 150) return null;
    return ['account' => $account, 'holder' => $holder];
}

/** One validation rule for the editor, administrator label and checkout. */
function achbotManualGiftSellerUrlValid(string $rawUrl): bool {
    $url = trim($rawUrl);
    if ($url === '' || strlen($url) > 350 ||
        filter_var($url, FILTER_VALIDATE_URL) === false) return false;
    $parts = parse_url($url);
    return is_array($parts) &&
        strtolower((string)($parts['scheme'] ?? '')) === 'https' &&
        !empty($parts['host']) &&
        !isset($parts['user']) && !isset($parts['pass']);
}
function achbotManualSellerUrl(): ?string {
    global $paymentKeys;
    $url = trim((string)($paymentKeys['giftCardUrl'] ?? ''));
    return achbotManualGiftSellerUrlValid($url) ? $url : null;
}

/** This JSON is a manual-only immutable destination intent, not a gateway URL. */
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
    // A previous PR candidate saved only an on-chain intent URI.
    if ($method === 'manual_usdt' &&
        preg_match('/^usdt:(BEP20|ERC20|TRC20):(.+)$/D', $saved, $m) &&
        achbotManualUsdtWalletValid($m[2], $m[1])) {
        return ['v'=>1, 'method'=>'manual_usdt','network'=>$m[1],'wallet'=>$m[2]];
    }
    return null;
}
function achbotManualEnabled(string $method): bool {
    global $botState;
    if ($method === 'gift_card') return ($botState['giftCardState'] ?? 'off') === 'on' &&
        achbotManualSellerUrl() !== null;
    if ($method === 'manual_usdt' || $method === 'manual_trx') {
        if (($botState['manualUsdtState'] ?? 'off') !== 'on') return false;
        return $method === 'manual_trx'
            ? achbotManualTrxConfiguration() !== null
            : achbotManualCryptoEnabled();
    }
    return $method === 'bank_transfer' && ($botState['cartToCartState'] ?? 'off') === 'on' &&
        achbotManualBankIdentity() !== null;
}

/** Method inquiry for customer/status only; never use null as bank auto-eligibility. */
function achbotManualSelectedMethod(mysqli $connection, array $payInfo): ?string {
    try {
        $stmt = $connection->prepare('SELECT provider FROM payment_attempts WHERE pay_row_id=? LIMIT 1');
        $id = (int)$payInfo['id'];
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $method = (string)($row['provider'] ?? '');
        return in_array($method, ['bank_transfer', 'gift_card', 'manual_usdt','manual_trx'], true) ? $method : null;
    } catch (Throwable $error) {
        return null;
    }
}


/** Read the provider from the immutable order-claim, not from a stale button. */
function achbotManualCryptoReceiptProviderByHash(mysqli $db, string $hashId): string {
    if (!preg_match('/^[A-Za-z0-9_-]{1,50}$/D', $hashId)) return '';
    try {
        $stmt=$db->prepare('SELECT provider FROM payment_attempts WHERE order_hash=? LIMIT 1');
        $stmt->bind_param('s',$hashId);$stmt->execute();
        $attempt=$stmt->get_result()->fetch_assoc();$stmt->close();
        $method=(string)($attempt['provider']??'');
        return in_array($method,['manual_usdt','manual_trx'],true) ? $method : '';
    } catch (Throwable $ignored) {
        return '';
    }
}

/** Cheap classification; uncertainty never means "legacy bank". Rechecked under lock. */
function achbotAutoBankEligible(mysqli $connection, array $seen): bool {
    try {
        $id = (int)$seen['id'];
        $stmt = $connection->prepare('SELECT provider FROM payment_attempts WHERE pay_row_id=? LIMIT 1');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $attempt = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $attempt === null || (string)$attempt['provider'] === 'bank_transfer';
    } catch (Throwable $error) {
        error_log('achbot_bank_auto_method_lookup_deferred pay_row_id=' . (int)($seen['id'] ?? 0));
        return false;
    }
}

/**
 * Fingerprint the original, raw purchase identity. A new source-issued 009
 * record is the historical authority; a later Cron scan never creates one.
 */
function achbotBankBuyInvoiceFingerprint(array $pay): string {
    return hash('sha256',json_encode([
        'achbot.bank.invoice.v1',
        (int)($pay['id']??0),(string)($pay['hash_id']??''),
        (int)($pay['user_id']??0),(string)($pay['type']??''),
        (int)($pay['plan_id']??0),(int)($pay['server_id']??0),
        (int)($pay['price']??0),(int)($pay['agent_count']??0),
        (int)($pay['day']??0),(int)($pay['volume']??0),
        (string)($pay['description']??''),(int)($pay['agent_bought']??0),
        (int)($pay['order_id']??0),(string)($pay['payid']??'')
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
}

/**
 * For card-transfer receipts the 003 checkout_url starts as pinned JSON,
 * then a receipt adds receipt_file_id. Only that evidence field can change;
 * method, destination account and holder belong to the issued instruction.
 */
function achbotBankBuyStableDestination(array $attempt): ?array {
    $intent=achbotManualIntentDecode((string)($attempt['checkout_url']??''),
                                    'bank_transfer');
    if ($intent===null ||
        !is_string($intent['account']??null) || $intent['account']==='' ||
        !is_string($intent['holder']??null) || $intent['holder']==='')
        return null;
    unset($intent['receipt_file_id']);
    ksort($intent);
    return $intent;
}

/**
 * The original 003 intent is a separate authority for customer-facing
 * bank instructions. Stage and timestamps are intentionally excluded:
 * normal receipt submission updates those fields without changing intent.
 */
function achbotBankBuyAttemptFingerprint(array $attempt): string {
    return hash('sha256',json_encode([
        'achbot.bank.attempt.v1',
        (int)($attempt['id']??0),(int)($attempt['pay_row_id']??0),
        (string)($attempt['provider']??''),(string)($attempt['order_hash']??''),
        (string)($attempt['quote_amount']??''),
        (string)($attempt['quote_currency']??''),
        (int)($attempt['local_amount_toman']??0),
        achbotBankBuyStableDestination($attempt),
        (string)($attempt['provider_reference']??''),
        (string)($attempt['payment_id']??''),
        (string)($attempt['settlement_currency']??'')
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
}

/** Basic identity before creating an issued invoice, never a NULL fallback. */
function achbotBankBuyBankIntentValid(array $pay, ?array $attempt): bool {
    return $attempt!==null && (int)($attempt['id']??0)>0 &&
        (int)($attempt['pay_row_id']??0)===(int)($pay['id']??0) &&
        (string)($attempt['provider']??'')==='bank_transfer' &&
        (string)($attempt['quote_currency']??'')==='toman' &&
        achbotBankBuyStableDestination($attempt)!==null &&
        hash_equals((string)($pay['hash_id']??''),
                    (string)($attempt['order_hash']??'')) &&
        (int)($attempt['local_amount_toman']??0)===(int)($pay['price']??0) &&
        (int)($pay['price']??0)>0;
}

/** Compare a CURRENT row to the originally issued 009 source-of-truth. */
function achbotBankBuyClaimMatches(array $pay, ?array $claim): bool {
    if (!$claim || (string)($pay['type']??'')!=='BUY_SUB' ||
        !preg_match('/^[a-f0-9]{64}$/D',(string)($claim['invoice_sha256']??'')) ||
        !hash_equals((string)$claim['invoice_sha256'],
                     achbotBankBuyInvoiceFingerprint($pay)))
        return false;
    $count=(int)($pay['agent_count']??0);
    if ($count<0) return false;
    $count=max(1,$count);
    $quotedDays=(int)($pay['day']??0);
    $quotedVolume=(int)($pay['volume']??0);
    $mode=($quotedDays===0 && $quotedVolume===0)?'standard':'custom';
    return (int)($claim['pay_row_id']??0)===(int)($pay['id']??0) &&
        (int)($claim['bank_attempt_id']??0)>0 &&
        preg_match('/^[a-f0-9]{64}$/D',(string)($claim['bank_attempt_sha256']??''))===1 &&
        (int)$claim['user_id']===(int)($pay['user_id']??0) &&
        (int)$claim['plan_id']===(int)($pay['plan_id']??0) &&
        (int)$claim['server_id']===(int)($pay['server_id']??0) &&
        (int)$claim['amount']===(int)($pay['price']??0) &&
        (int)$claim['account_count']===$count &&
        (string)$claim['quote_mode']===$mode &&
        (int)$claim['quoted_days']===$quotedDays &&
        (int)$claim['quoted_volume']===$quotedVolume &&
        ($mode==='standard' || ($quotedDays>0 && $quotedVolume>0)) &&
        (int)$claim['entitled_days']>0 &&
        (int)$claim['entitled_days']<=36500 &&
        (int)$claim['entitled_volume']>0 &&
        (int)$claim['entitled_volume']<=intdiv(PHP_INT_MAX,1073741824) &&
        (int)$claim['amount']>0 && $count<=(int)$claim['amount'];
}

/** Missing 003 is NOT legacy when an issued 009 is required. */
function achbotBankBuyAttemptMatches(array $pay, ?array $attempt, ?array $claim): bool {
    return $claim!==null && achbotBankBuyBankIntentValid($pay,$attempt) &&
        (int)($claim['bank_attempt_id']??0)===(int)$attempt['id'] &&
        preg_match('/^[a-f0-9]{64}$/D',(string)($claim['bank_attempt_sha256']??''))===1 &&
        hash_equals((string)$claim['bank_attempt_sha256'],
                    achbotBankBuyAttemptFingerprint($attempt));
}

/**
 * Called ONLY inside achbotManualClaim's existing pending-payment and 003
 * INSERT transaction. A failure rolls back BOTH issuance records. Plan
 * dimensions are purchased/locked NOW, not inferred during later Cron.
 */
function achbotBankBuyRecordIssuedIntent(
    mysqli $connection, array $pay, array $attempt
): void {
    if ((string)($pay['type']??'')!=='BUY_SUB' ||
        !achbotBankBuyBankIntentValid($pay,$attempt) ||
        (int)($pay['plan_id']??0)<=0 || (int)($pay['server_id']??0)<=0 ||
        (int)($pay['agent_count']??0)<0)
        throw new RuntimeException('bank_buy_issue_invalid_subject');
    $count=max(1,(int)$pay['agent_count']);
    if ($count>(int)$pay['price'])
        throw new RuntimeException('bank_buy_issue_count_price');

    $planId=(int)$pay['plan_id'];
    $stmt=$connection->prepare("SELECT server_id,inbound_id,days,volume,active
        FROM server_plans WHERE id=? LIMIT 1 FOR UPDATE");
    if (!$stmt) throw new RuntimeException('bank_buy_issue_plan_prepare');
    $stmt->bind_param('i',$planId);
    if (!$stmt->execute()) throw new RuntimeException('bank_buy_issue_plan_read');
    $plan=$stmt->get_result()->fetch_assoc();$stmt->close();
    $days=(int)($pay['day']??0);$volume=(int)($pay['volume']??0);
    $standard=$days===0 && $volume===0;
    $entitledDays=$standard?(int)($plan['days']??0):$days;
    $entitledVolume=$standard?(int)($plan['volume']??0):$volume;
    if (!$plan || (int)$plan['server_id']!==(int)$pay['server_id'] ||
        (int)$plan['inbound_id']!==0 || (int)$plan['active']!==1 ||
        (!$standard && ($days<=0 || $volume<=0)) ||
        $entitledDays<=0 || $entitledDays>36500 || $entitledVolume<=0 ||
        $entitledVolume>intdiv(PHP_INT_MAX,1073741824))
        throw new RuntimeException('bank_buy_issue_entitlement_invalid');

    $id=(int)$pay['id'];$user=(int)$pay['user_id'];
    $attemptId=(int)$attempt['id'];$server=(int)$pay['server_id'];
    $price=(int)$pay['price'];$mode=$standard?'standard':'custom';
    $invoiceHash=achbotBankBuyInvoiceFingerprint($pay);
    $attemptHash=achbotBankBuyAttemptFingerprint($attempt);
    $stmt=$connection->prepare("INSERT INTO bank_buy_invoice_claims
        (pay_row_id,invoice_sha256,bank_attempt_id,bank_attempt_sha256,
         user_id,plan_id,server_id,amount,account_count,quote_mode,
         quoted_days,quoted_volume,entitled_days,entitled_volume)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    if (!$stmt) throw new RuntimeException('bank_buy_issue_schema_missing');
    $stmt->bind_param('isisiiiiisiiii',$id,$invoiceHash,$attemptId,$attemptHash,
        $user,$planId,$server,$price,$count,$mode,$days,$volume,
        $entitledDays,$entitledVolume);
    if (!$stmt->execute() || $stmt->affected_rows!==1)
        throw new RuntimeException('bank_buy_issue_unconfirmed');
    $stmt->close();
}

/**
 * Exclusive bank-only settlement claim. Legacy receipts have no ledger attempt;
 * any explicit non-bank/unknown provider or failed lookup blocks auto-approval.
 * Wallet credit and the guarded receipt transition share one transaction.
 * For services, commit the exclusive claim before starting side effects.
 */
function achbotAutoBankClaim(mysqli $connection, array $seen): bool {
    $id = (int)($seen['id'] ?? 0);
    if ($id <= 0) return false;
    try {
        $connection->begin_transaction();
        $stmt = $connection->prepare('SELECT * FROM pays WHERE id=? FOR UPDATE');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $pay = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$pay || $pay['state'] !== 'have_sent' ||
            (string)($pay['payid'] ?? '') !== '' ||
            (int)$pay['user_id'] <= 0 || (int)$pay['price'] <= 0) {
            $connection->rollback();
            return false;
        }
        // Do not classify a failed ledger lookup as a legacy card receipt.
        $stmt = $connection->prepare('SELECT * FROM payment_attempts
            WHERE pay_row_id=? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $attempt = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($attempt && (string)$attempt['provider'] !== 'bank_transfer') {
            $connection->rollback();
            return false;
        }
        if ($pay['type']==='BUY_SUB') {
            // Only an original customer-issued 009 can authorize a NEW
            // provider CREATE. Missing records (including legacy receipts)
            // require operator reconciliation, not reconstructed history.
            $stmt=$connection->prepare('SELECT * FROM bank_buy_invoice_claims
                WHERE pay_row_id=? LIMIT 1 FOR UPDATE');
            if (!$stmt) throw new RuntimeException('bank_buy_issue_schema_missing');
            $stmt->bind_param('i',$id);
            if (!$stmt->execute()) throw new RuntimeException('bank_buy_issue_read');
            $issued=$stmt->get_result()->fetch_assoc();$stmt->close();
            if (!achbotBankBuyClaimMatches($pay,$issued) ||
                !achbotBankBuyAttemptMatches($pay,$attempt,$issued) ||
                !hash_equals(achbotBankBuyInvoiceFingerprint($pay),
                             achbotBankBuyInvoiceFingerprint($seen)))
                throw new RuntimeException('bank_buy_issued_identity_changed');
        }
        if ($pay['type'] === 'INCREASE_WALLET') {
            $userId = (int)$pay['user_id'];
            $stmt = $connection->prepare('SELECT wallet FROM users WHERE userid=? FOR UPDATE');
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $wallet = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$wallet || !addToWallet($connection, $userId, (int)$pay['price'])) {
                $connection->rollback();
                return false;
            }
        }
        // For remote service edits the bank receipt claim is NOT service
        // fulfillment. Keep it nonterminal until verified GET + local effects.
        // Wallet top-ups are atomic. BUY_SUB, like edits, is nonterminal
        // until remote effects and all local accounting have been proved.
        $kind=(string)$pay['type'];
        $isEdit=$kind==='RENEW_ACCOUNT' || $kind==='RENEW_SCONFIG' ||
            preg_match('/^INCREASE_(?:DAY|VOLUME)_[1-9][0-9]*_[1-9][0-9]*$/D',$kind)===1;
        $next=($isEdit || $kind==='BUY_SUB')?'processing':'auto_cart_approved';
        $stmt = $connection->prepare("UPDATE pays SET state=?
            WHERE id=? AND state='have_sent'");
        $stmt->bind_param('si',$next,$id);
        $stmt->execute();
        $changed = $stmt->affected_rows;
        $stmt->close();
        if ($changed !== 1 || !$connection->commit()) throw new RuntimeException('bank_auto_claim_failed');
        return true;
    } catch (Throwable $error) {
        try { $connection->rollback(); } catch (Throwable $ignored) {}
        // Only an opaque local row id, never photo, account, user or SQL text.
        error_log('achbot_bank_auto_claim_deferred pay_row_id=' . $id);
        return false;
    }
}

/**
 * Check the PAID target and original service subject before any outbound
 * account edit. Do not derive a remote destination from a mutable plan or
 * Telegram callback alone. The subsequent finisher RECHECKS under the
 * original payment/order row locks after remote verification.
 *
 * One autocommit preflight read is intentional: NEVER hold SQL locks while
 * performing remote HTTP. Unsupported/changed/ambiguous claims stay under
 * the existing processing no-replay fence.
 */
function achbotAutoBankEditPreflight(mysqli $db, array $pay, string $kind,
                                    int $serverId, int $orderId,
                                    string $remark, int $units=0): bool {
    $payId=(int)($pay['id']??0);
    $owner=(int)($pay['user_id']??0);
    if ($payId<=0 || $owner<=0 || $serverId<=0 || $remark==='' ||
        !in_array($kind,['renew','standard','day','volume'],true))
        return false;
    try {
        $stmt=$db->prepare("SELECT p.user_id,p.server_id,p.plan_id,p.price,
            p.order_id,p.state,p.type,p.description,
            sp.server_id AS plan_server
            FROM pays p LEFT JOIN server_plans sp ON sp.id=p.plan_id
            WHERE p.id=?");
        if (!$stmt) return false;
        $stmt->bind_param('i',$payId);
        $ok=$stmt->execute();
        $paid=$ok?$stmt->get_result()->fetch_assoc():null;
        $stmt->close();
        if (!$paid || (int)$paid['user_id']!==$owner ||
            $paid['state']!=='processing' ||
            (int)$paid['server_id']!==$serverId ||
            // The scheduler's original invoice subject is immutable across
            // receipt claim, preflight, provider HTTP and finalization.
            (int)$paid['plan_id']!==(int)($pay['plan_id']??0) ||
            (int)$paid['price']!==(int)($pay['price']??0) ||
            (int)($paid['order_id']??0)!==(int)($pay['order_id']??0) ||
            (string)$paid['type']!==(string)($pay['type']??'') ||
            (string)($paid['description']??'')!==(string)($pay['description']??'') ||
            // External standard accounts have no order/group alias. For an
            // order-backed edit, Core authorizes an explicitly shared panel.
            ($kind==='standard' && (int)($paid['plan_server']??0)!==$serverId))
            return false;

        if ($kind==='standard') {
            if ($paid['type']!=='RENEW_SCONFIG') return false;
            $subject=json_decode((string)$paid['description'],true);
            return is_array($subject) &&
                (string)($subject['remark']??'')===$remark &&
                !empty($subject['uuid']) && is_string($subject['uuid']);
        }
        if ($kind==='renew') {
            if ($paid['type']!=='RENEW_ACCOUNT' ||
                (int)$paid['order_id']!==$orderId) return false;
        } else {
            $pattern=$kind==='day'
                ? '/^INCREASE_DAY_([1-9][0-9]*)_([1-9][0-9]*)$/D'
                : '/^INCREASE_VOLUME_([1-9][0-9]*)_([1-9][0-9]*)$/D';
            if ($units<=0 || preg_match($pattern,(string)$paid['type'],$m)!==1 ||
                (int)$m[1]!==$orderId ||
                // Old extension receipts may omit order_id, but a pinned
                // nonzero ID must never contradict the encoded subject.
                ((int)($paid['order_id']??0)!==0 &&
                 (int)$paid['order_id']!==$orderId)) return false;
            // The existing quote is the only permitted source of units.
            $table=$kind==='day'?'increase_day':'increase_plan';
            $quoteId=(int)$m[2];
            $quote=$db->prepare("SELECT volume FROM ".$table." WHERE id=?");
            if (!$quote) return false;
            $quote->bind_param('i',$quoteId);
            $exists=$quote->execute();
            $value=$exists?$quote->get_result()->fetch_assoc():null;
            $quote->close();
            if (!$value || !is_numeric($value['volume']) ||
                (float)$value['volume']!==(float)$units) return false;
        }
        if ($orderId<=0) return false;
        $orderStmt=$db->prepare("SELECT userid,fileid,server_id,inbound_id,
            remark,status FROM orders_list WHERE id=?");
        if (!$orderStmt) return false;
        $orderStmt->bind_param('i',$orderId);
        $ok=$orderStmt->execute();
        $record=$ok?$orderStmt->get_result()->fetch_assoc():null;
        $orderStmt->close();
        return (bool)$record && (int)$record['userid']===$owner &&
            (int)$record['fileid']===(int)$paid['plan_id'] &&
            (int)$record['server_id']===$serverId &&
            (int)$record['inbound_id']===0 &&
            (int)$record['status']===1 &&
            (string)$record['remark']===$remark &&
            // Real Core authorization, not a shortcut from the plan's old
            // logical server ID. Cross-panel aliases fail before HTTP.
            achbotSupportsOrder($db,$orderId,
                (int)$paid['plan_id'],$owner);
    } catch (Throwable $error) {
        error_log('achbot_auto_service_preflight_deferred pay_id='.$payId.
            ' class='.get_class($error).' code='.(int)$error->getCode());
        return false;
    }
}

/**
 * The provider's authenticated absolute applied_expire is the only expiry
 * that can be locally recorded after an edit. Call inside the SAME caller-
 * owned SQL transaction as its payment/audit transition. A lost SQL reply,
 * stale order identity, concurrent renewal, truncated INT, or missing
 * readback is not fulfillment: leave the previously committed payment claim
 * operator-reviewable and never replay the remote effect.
 *
 * Source is the original pre-remote order snapshot (NOT a fresh one obtained
 * after dispatch). Lock the exact primary key and compare the entire payment
 * subject, including its prior local expiry and nullable UUID.
 */
function achbotStoreVerifiedServiceExpiryLocked(mysqli $db, array $original,
                                           object $verified): void {
    if (($verified->success??false)!==true ||
        ($verified->verified??false)!==true ||
        !isset($verified->applied_expire,$verified->applied_bytes) ||
        !is_int($verified->applied_expire) ||
        $verified->applied_expire<=0 || $verified->applied_expire>2147483647 ||
        !is_int($verified->applied_bytes) || $verified->applied_bytes<0)
        throw new RuntimeException('day_extension_remote_readback_invalid');
    foreach (['id','userid','server_id','inbound_id','fileid',
              'remark','status','expire_date'] as $key) {
        if (!array_key_exists($key,$original))
            throw new RuntimeException('day_extension_original_order_missing');
    }
    $orderId=(int)$original['id'];
    $owner=(int)$original['userid'];
    $server=(int)$original['server_id'];
    $inbound=(int)$original['inbound_id'];
    $fileId=(int)$original['fileid'];
    $remark=(string)$original['remark'];
    $uuid=$original['uuid']??null;
    $old=(int)$original['expire_date'];
    if ($orderId<=0 || $owner<=0 || $server<=0 || $fileId<=0 ||
        $inbound!==0 || $remark==='' || (int)$original['status']!==1 ||
        $old<0 || $old>2147483647)
        throw new RuntimeException('day_extension_original_order_invalid');

    $stmt=$db->prepare('SELECT userid,server_id,inbound_id,fileid,remark,uuid,
            status,expire_date,notif FROM orders_list WHERE id=? FOR UPDATE');
    if (!$stmt) throw new RuntimeException('day_extension_order_lock_unavailable');
    $stmt->bind_param('i',$orderId);
    if (!$stmt->execute()) throw new RuntimeException('day_extension_order_lock_failed');
    $current=$stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$current ||
        (int)$current['userid']!==$owner ||
        (int)$current['server_id']!==$server ||
        (int)$current['inbound_id']!==$inbound ||
        (int)$current['fileid']!==$fileId ||
        (int)$current['status']!==1 ||
        (string)$current['remark']!==$remark ||
        $current['uuid']!==$uuid ||
        (int)$current['expire_date']!==$old)
        throw new RuntimeException('day_extension_order_stale');
    $expiry=$verified->applied_expire;
    $stmt=$db->prepare('UPDATE orders_list SET expire_date=?,notif=0
        WHERE id=? AND userid=? AND server_id=? AND inbound_id=?
          AND fileid=? AND remark=? AND uuid <=> ? AND status=1
          AND expire_date=?');
    if (!$stmt) throw new RuntimeException('day_extension_update_prepare_failed');
    $stmt->bind_param('iiiiiissi',$expiry,$orderId,$owner,$server,
                      $inbound,$fileId,$remark,$uuid,$old);
    $saved=$stmt->execute();
    $changed=$stmt->affected_rows;
    $stmt->close();
    // A legitimately unchanged absolute expiry can still be a verified
    // successful edit (e.g. a provider was already ahead). MySQL reports
    // affected_rows=0 when both persisted fields were already equal.
    // Locking identity/previous-expiry CAS and exact post-write readback
    // remain mandatory; zero does not waive them.
    if ($saved!==true || ($changed!==1 &&
        !($changed===0 && $old===$expiry && (int)$current['notif']===0)))
        throw new RuntimeException('service_expiry_update_unconfirmed');
    $stmt=$db->prepare('SELECT expire_date,notif FROM orders_list WHERE id=? FOR UPDATE');
    if (!$stmt) throw new RuntimeException('day_extension_readback_prepare_failed');
    $stmt->bind_param('i',$orderId);
    $read=$stmt->execute();
    $stored=$read?$stmt->get_result()->fetch_assoc():null;
    $stmt->close();
    if (!$stored || (int)$stored['expire_date']!==$expiry ||
        (int)$stored['notif']!==0)
        throw new RuntimeException('day_extension_readback_mismatch');
}

/**
 * A scheduled bank receipt is only a PAYMENT claim. For service edits,
 * success requires a verified remote absolute outcome followed by ONE
 * all-or-nothing local order/accounting/payment commit.
 *
 * No remote POST, wallet refund or retry takes place here. Uncertain remote
 * or SQL outcomes remain 'processing' for reconciliation; never claim
 * approved until both remote and local results are confirmed.
 */
function achbotAutoBankFinishEdit(mysqli $db, array $pay,
                                  object $verified, string $kind,
                                  int $serverId, int $orderId,
                                  string $remark, int $amount,
                                  int $units=0, ?int $expectedOrderExpiry=null): bool {
    $id=(int)($pay['id']??0);
    $userId=(int)($pay['user_id']??0);
    if ($id<=0 || $userId<=0 || $amount<=0 || $serverId<=0 ||
        $remark==='' || !in_array($kind,['renew','standard','day','volume'],true) ||
        ($verified->success??false)!==true ||
        ($verified->verified??false)!==true ||
        !isset($verified->applied_expire,$verified->applied_bytes) ||
        // No 64-bit provider timestamp may be silently clamped by the
        // existing signed-INT orders_list.expire_date schema, including
        // non-strict MariaDB sql_mode.
        !is_int($verified->applied_expire) ||
        $verified->applied_expire<=0 ||
        $verified->applied_expire>2147483647 ||
        !is_int($verified->applied_bytes) ||
        $verified->applied_bytes<0 ||
        ($kind!=='standard' && ($expectedOrderExpiry===null ||
            $expectedOrderExpiry<0 || $expectedOrderExpiry>2147483647)))
        return false;
    $expectedType=match($kind) {
        'renew'=>'RENEW_ACCOUNT','standard'=>'RENEW_SCONFIG',
        'day'=>'INCREASE_DAY','volume'=>'INCREASE_VOLUME'
    };
    try {
        if ($db->begin_transaction()!==true) return false;
        $stmt=$db->prepare("SELECT p.id,p.type,p.state,p.user_id,p.order_id,
                p.price,p.server_id,p.plan_id,p.description,
                sp.server_id AS current_plan_server
            FROM pays p LEFT JOIN server_plans sp ON sp.id=p.plan_id
            WHERE p.id=? FOR UPDATE");
        if (!$stmt) throw new RuntimeException('auto_service_pay_lock_missing');
        $stmt->bind_param('i',$id);
        if (!$stmt->execute()) throw new RuntimeException('auto_service_pay_lock_failed');
        $locked=$stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$locked || $locked['state']!=='processing' ||
            (int)$locked['user_id']!==$userId ||
            (int)$locked['price']!==$amount ||
            (int)$locked['server_id']!==$serverId ||
            // A coherent payment/order rewrite after preflight must not
            // authorize an effect dispatched for the ORIGINAL paid subject.
            (int)$locked['plan_id']!==(int)($pay['plan_id']??0) ||
            (int)($locked['order_id']??0)!==(int)($pay['order_id']??0) ||
            (string)$locked['type']!==(string)($pay['type']??'') ||
            (string)($locked['description']??'')!==(string)($pay['description']??'') ||
            ($kind==='standard' &&
                (int)($locked['current_plan_server']??0)!==$serverId) ||
            ($kind==='day' &&
                preg_match('/^INCREASE_DAY_([1-9][0-9]*)_([1-9][0-9]*)$/D',
                    (string)$locked['type'],$m)!==1) ||
            ($kind==='volume' &&
                preg_match('/^INCREASE_VOLUME_([1-9][0-9]*)_([1-9][0-9]*)$/D',
                    (string)$locked['type'],$m)!==1) ||
            (in_array($kind,['standard','renew'],true) &&
                $locked['type']!==$expectedType)) {
            $db->rollback();return false;
        }
        if ($kind==='day' || $kind==='volume') {
            if ((int)$m[1]!==$orderId || $units<=0 ||
                ((int)($locked['order_id']??0)!==0 &&
                 (int)$locked['order_id']!==$orderId)) {
                $db->rollback();return false;
            }
            $quote=$db->prepare("SELECT volume FROM ".
                ($kind==='day'?'increase_day':'increase_plan')." WHERE id=?");
            if (!$quote) throw new RuntimeException('auto_service_quote_prepare_failed');
            $quoteId=(int)$m[2];
            $quote->bind_param('i',$quoteId);
            $ok=$quote->execute();
            $row=$ok?$quote->get_result()->fetch_assoc():null;
            $quote->close();
            if (!$row || !is_numeric($row['volume']) ||
                (float)$row['volume']!==(float)$units) {
                $db->rollback();return false;
            }
        }
        if ($kind==='standard') {
            $subject=json_decode((string)$locked['description'],true);
            if (!is_array($subject) || (string)($subject['remark']??'')!==$remark ||
                !isset($subject['uuid']) || !is_string($subject['uuid']) ||
                $subject['uuid']==='') {
                $db->rollback();return false;
            }
        }
        if ($kind==='renew' && (int)$locked['order_id']!==$orderId) {
            $db->rollback();return false;
        }
        if ($kind!=='standard') {
            if ($orderId<=0) { $db->rollback();return false; }
            $stmt=$db->prepare("SELECT userid,fileid,server_id,inbound_id,
                remark,status,expire_date FROM orders_list WHERE id=? FOR UPDATE");
            $stmt->bind_param('i',$orderId);
            if (!$stmt->execute()) throw new RuntimeException('auto_service_order_read_failed');
            $order=$stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$order || (int)$order['userid']!==$userId ||
                (int)$order['server_id']!==$serverId ||
                (int)$order['fileid']!==(int)$locked['plan_id'] ||
                (int)$order['inbound_id']!==0 ||
                (int)$order['status']!==1 ||
                (string)$order['remark']!==$remark ||
                // Refresh physical group membership under SQL locks after
                // remote verification. A revoked alias cannot finalize a
                // previously valid preflight; do not trust its old snapshot.
                !achbotSupportsOrder($db,$orderId,
                    (int)$locked['plan_id'],$userId,true) ||
                // B may have finalized a newer edit after this payment's
                // original pre-remote snapshot. Never let A restore old
                // absolute expiry just because its own payment row is locked.
                (int)$order['expire_date']!==$expectedOrderExpiry) {
                $db->rollback();return false;
            }
            if ($kind!=='volume') {
                $expiry=$verified->applied_expire;
                $stmt=$db->prepare("UPDATE orders_list SET expire_date=?,notif=0
                    WHERE id=? AND userid=? AND remark=? AND server_id=?
                      AND expire_date=?");
                $stmt->bind_param('iiisii',$expiry,$orderId,$userId,$remark,
                                  $serverId,$expectedOrderExpiry);
            } else {
                $stmt=$db->prepare("UPDATE orders_list SET notif=0
                    WHERE id=? AND userid=? AND remark=? AND server_id=?
                      AND expire_date=?");
                $stmt->bind_param('iisii',$orderId,$userId,$remark,$serverId,
                                  $expectedOrderExpiry);
            }
            if (!$stmt || !$stmt->execute())
                throw new RuntimeException('auto_service_order_write_failed');
            $stmt->close();
            // Under permissive SQL mode, execute()==true is not proof that
            // the verified 64-bit remote expiry fits persisted INT storage.
            // Read back under the same transaction BEFORE acknowledging pay.
            $stmt=$db->prepare('SELECT expire_date FROM orders_list WHERE id=? FOR UPDATE');
            if (!$stmt) throw new RuntimeException('auto_service_expiry_readback_prepare');
            $stmt->bind_param('i',$orderId);
            $ok=$stmt->execute();
            $persisted=$ok?$stmt->get_result()->fetch_assoc():null;
            $stmt->close();
            if (!$persisted || (int)$persisted['expire_date']!==(
                $kind==='volume'?$expectedOrderExpiry:$verified->applied_expire))
                throw new RuntimeException('auto_service_expiry_readback_mismatch');
        }
        $day=$kind==='day'?$units:null;
        $volume=$kind==='volume'?$units:null;
        $now=time();
        $inbound=0;
        $stmt=$db->prepare("INSERT INTO increase_order
            (userid,server_id,inbound_id,remark,amount,date,volume,day)
            VALUES (?,?,?,?,?,?,?,?)");
        $stmt->bind_param('iiisiiii',$userId,$serverId,$inbound,$remark,
                          $amount,$now,$volume,$day);
        if (!$stmt->execute() || $stmt->affected_rows!==1)
            throw new RuntimeException('auto_service_audit_insert_failed');
        $stmt->close();
        $stmt=$db->prepare("UPDATE pays SET state='auto_cart_approved'
            WHERE id=? AND user_id=? AND state='processing'");
        $stmt->bind_param('ii',$id,$userId);
        if (!$stmt->execute() || $stmt->affected_rows!==1)
            throw new RuntimeException('auto_service_finish_state_failed');
        $stmt->close();
        if ($db->commit()!==true) throw new RuntimeException('auto_service_commit_unconfirmed');
        return true;
    } catch (Throwable $error) {
        try {$db->rollback();} catch (Throwable $ignored) {}
        error_log('achbot_auto_service_edit_needs_review pay_id='.$id.' kind='.$kind.
                  ' exception='.get_class($error));
        return false;
    }
}

/**
 * Atomic operator-approved wallet top-up. Photo upload and Telegram callback
 * alone are NEVER payment proof. A privileged operator must have independently
 * verified the receipt before invoking this transition.
 *
 * The local pays row lock serializes competing administrators; the wallet and
 * approved state commit together. Existing signed administrator debt is kept.
 */
function achbotApproveManualWalletTopup(mysqli $connection, string $hashId, int $adminId): ?array {
    if ($adminId <= 0 || !preg_match('/^[A-Za-z0-9_-]{1,50}$/D', $hashId)) return null;
    try {
        $connection->begin_transaction();
        $stmt = $connection->prepare('SELECT id,user_id,type,price,state FROM pays
            WHERE hash_id=? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('s', $hashId);
        $stmt->execute();
        $pay = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$pay || $pay['type'] !== 'INCREASE_WALLET' ||
            $pay['state'] !== 'have_sent' || (int)$pay['price'] <= 0 ||
            (int)$pay['user_id'] <= 0) {
            $connection->rollback();
            return null;
        }
        $userId = (int)$pay['user_id'];
        $price = (int)$pay['price'];
        $stmt = $connection->prepare('SELECT wallet FROM users WHERE userid=? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$user) {
            $connection->rollback();
            return null;
        }
        $before = (int)$user['wallet'];
        if (!addToWallet($connection, $userId, $price)) {
            $connection->rollback();
            return null;
        }
        $payId = (int)$pay['id'];
        $stmt = $connection->prepare("UPDATE pays SET state='approved',approved_by=?
            WHERE id=? AND state='have_sent'");
        $stmt->bind_param('ii', $adminId, $payId);
        $stmt->execute();
        $changed = $stmt->affected_rows;
        $stmt->close();
        if ($changed !== 1 || !$connection->commit()) {
            throw new RuntimeException('manual_wallet_commit_failed');
        }
        return ['user_id'=>$userId, 'price'=>$price,
                'wallet_before'=>$before, 'wallet_after'=>$before+$price];
    } catch (Throwable $error) {
        try { $connection->rollback(); } catch (Throwable $ignored) {}
        return null;
    }
}

/**
 * Customer-wallet route, called while holding the unique user row first,
 * then the pays row FOR UPDATE. A present shared ledger must be inspected;
 * any attempt blocks wallet use. New-claim successor checks are CURRENT
 * locking reads under that same owner-row lock, not stale menu preflights.
 * Historical 23-table installs have no ledger and no new checkout issuance,
 * so they retain their pre-003 wallet behavior. SQL/permission failures are
 * never interpreted as an absent claim.
 */
function achbotWalletDirectAllowedLocked(mysqli $connection, array $pay,
                                         int $actor): bool {
    if ($actor <= 0 || (int)($pay['user_id'] ?? 0) !== $actor ||
        (int)($pay['price'] ?? 0) <= 0 ||
        !in_array((string)($pay['state'] ?? ''), ['pending','send','low_wallet'], true) ||
        (string)($pay['payid'] ?? '') !== '') return false;
    $id = (int)($pay['id'] ?? 0);
    if ($id <= 0) return false;
    require_once __DIR__ . '/PaymentLifecycle.php';
    if (!achbotPayUxUnclaimedDraftUsable($connection,$pay,true)) return false;
    if (!achbotPayUxAudienceAllowed($connection,'wallet',$actor,true)) return false;
    try {
        $stmt = $connection->prepare('SELECT id FROM payment_attempts WHERE pay_row_id=? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('i',$id);
        $stmt->execute();
        $found = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $found === null;
    } catch (Throwable $error) {
        if (!($error instanceof mysqli_sql_exception) || (int)$error->getCode() !== 1146) {
            return false;
        }
        // Only a positively verified absence of the table, not an arbitrary
        // connection or SQL failure, permits historical wallet payment.
        try {
            $attemptTable = $connection->query("SHOW TABLES LIKE 'payment_attempts'");
            $transactionTable = $connection->query("SHOW TABLES LIKE 'payment_transactions'");
            // A partially missing 003 ledger is not the historical schema:
            // it may contain active claims and must never be bypassed.
            return $attemptTable instanceof mysqli_result && $attemptTable->num_rows === 0 &&
                   $transactionTable instanceof mysqli_result && $transactionTable->num_rows === 0;
        } catch (Throwable $ignored) {
            return false;
        }
    }
}

/**
 * Standard BUY_SUB capacity is reserved with its payment processing claim,
 * BEFORE any non-idempotent remote CREATE. A capacity reservation becomes
 * operator-owned if a later remote response or COMMIT is uncertain: never
 * automatically restore and allow a duplicate CREATE against that payment.
 *
 * Caller owns the pays FOR UPDATE transaction. InnoDB serializes the
 * conditional capacity UPDATE across distinct buyers on the same server.
 */
function achbotManualStandardReserveCapacityLocked(mysqli $db, array $pay): int {
    return achbotManualBuyReserveCapacityLocked($db,$pay,false);
}

/** Custom positive day/volume manual receipts require the SAME pre-CREATE
 * payment-owned capacity hold as standard receipts, not an unsafe late debit.
 */
function achbotManualCustomReserveCapacityLocked(mysqli $db, array $pay): int {
    return achbotManualBuyReserveCapacityLocked($db,$pay,true);
}

/** Shared under-pay-lock validation and guarded InnoDB full-count decrement. */
function achbotManualBuyReserveCapacityLocked(mysqli $db,array $pay,bool $custom): int {
    $day=(int)($pay['day']??-1);
    $volume=(int)($pay['volume']??-1);
    if (($pay['type']??null)!=='BUY_SUB' ||
        ($custom ? ($day<=0 || $volume<=0) : ($day!==0 || $volume!==0)))
        throw new RuntimeException('manual_capacity_wrong_subject');
    $raw=$pay['agent_count'] ?? null;
    if ($raw===null || (string)$raw==='0' || (string)$raw==='') {
        $count=1; // Historical single-account rows have no explicit quantity.
    } elseif (!preg_match('/^[1-9][0-9]*$/D',(string)$raw) ||
              filter_var($raw,FILTER_VALIDATE_INT)===false) {
        throw new RuntimeException('manual_standard_capacity_invalid_count');
    } else {
        $count=(int)$raw;
    }
    $sid=(int)($pay['server_id'] ?? 0);
    $planId=(int)($pay['plan_id'] ?? 0);
    if ($sid<=0 || $planId<=0) throw new RuntimeException('manual_standard_capacity_invalid_target');

    $stmt=$db->prepare("SELECT server_id,inbound_id FROM server_plans
        WHERE id=? AND active=1 LIMIT 1 FOR UPDATE");
    if (!$stmt) throw new RuntimeException('manual_standard_capacity_plan_prepare_failed');
    $stmt->bind_param('i',$planId);
    if (!$stmt->execute()) throw new RuntimeException('manual_standard_capacity_plan_read_failed');
    $plan=$stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$plan || (int)$plan['server_id']!==$sid ||
        (int)$plan['inbound_id']!==0)
        throw new RuntimeException('manual_standard_capacity_plan_changed');

    $stmt=$db->prepare('UPDATE server_info SET ucount=ucount-?
        WHERE id=? AND ucount>=?');
    if (!$stmt) throw new RuntimeException('manual_standard_capacity_prepare_failed');
    $stmt->bind_param('iii',$count,$sid,$count);
    $ok=$stmt->execute();
    $affected=$stmt->affected_rows;
    $stmt->close();
    if ($ok!==true || $affected!==1)
        throw new RuntimeException('manual_standard_capacity_insufficient_or_unconfirmed');
    return $count;
}

/**
 * A pre-003 receipt may be reviewed against its original pays/operator
 * message only if both 003 ledger tables are PROVEN absent. Missing privileges,
 * SQL failures or an incomplete/partial migration are never treated as legacy.
 */
/**
 * Current additive-003 schema must be all present and structurally usable.
 * A malformed/one-sided installation is NEVER interpreted as legacy.
 * The original 23-table schema is supported only when both tables are absent.
 * Read-only fail-closed schema preflight under the original pays row lock.
 */
function achbotManualLedgerMode(mysqli $db): string {
    try {
        $result=$db->query('SHOW TABLES');
        if (!($result instanceof mysqli_result)) return 'invalid';
        $tables=[];
        while ($row=$result->fetch_row()) $tables[(string)$row[0]]=true;
        $result->free();
        $hasAttempt=isset($tables['payment_attempts']);
        $hasTransaction=isset($tables['payment_transactions']);
        if (!$hasAttempt && !$hasTransaction) return 'legacy';
        if (!$hasAttempt || !$hasTransaction) return 'invalid';

        $columns=[
            'payment_attempts'=>'id,pay_row_id,provider,stage,checkout_url,order_hash,provider_reference,payment_id',
            'payment_transactions'=>'id,attempt_id,provider,payment_id',
        ];
        foreach ($columns as $table=>$fields) {
            $probe=$db->query("SELECT $fields FROM `$table` LIMIT 0");
            if (!($probe instanceof mysqli_result)) return 'invalid';
            $probe->free();
        }
        $requiredIndexes=[
            'payment_attempts'=>[
                'PRIMARY'=>['id'],
                'uq_payment_attempt_pay_row'=>['pay_row_id'],
                'uq_payment_attempt_order_hash'=>['order_hash'],
                'uq_payment_attempt_ref'=>['provider','provider_reference'],
                'uq_payment_attempt_payment'=>['provider','payment_id'],
            ],
            'payment_transactions'=>[
                'PRIMARY'=>['id'],
                'uq_payment_transaction_reference'=>['provider','payment_id'],
            ],
        ];
        foreach ($requiredIndexes as $table=>$required) {
            $indexes=$db->query("SHOW INDEX FROM `$table`");
            if (!($indexes instanceof mysqli_result)) return 'invalid';
            $actual=[];
            while ($idx=$indexes->fetch_assoc()) {
                if ((int)$idx['Non_unique']!==0) continue;
                $actual[(string)$idx['Key_name']][(int)$idx['Seq_in_index']]=(string)$idx['Column_name'];
            }
            $indexes->free();
            foreach ($required as $name=>$columns) {
                if (!isset($actual[$name])) return 'invalid';
                ksort($actual[$name]);
                if (array_values($actual[$name])!==$columns) return 'invalid';
            }
        }
        return 'current';
    } catch (Throwable $ignored) {
        return 'invalid';
    }
}

function achbotManualLegacyLedgerAbsent(mysqli $db): bool {
    return achbotManualLedgerMode($db)==='legacy';
}

/**
 * Reviewable manual service receipt, checked under the exact pays FOR UPDATE
 * lock before any remote fulfillment. A callback never proves settlement.
 * Pre-ledger receipts require a persisted operator delivery; new manual
 * methods require their exclusive ledger attempt and saved original photo.
 * Ledger failures fail closed rather than impersonating historical receipts.
 */
function achbotManualServiceApprovalAllowedLocked(mysqli $connection, ?array $pay, string $subject): bool {
    if (!$pay || (int)($pay['id'] ?? 0) <= 0 ||
        (int)($pay['user_id'] ?? 0) <= 0 ||
        (string)($pay['state'] ?? '') !== 'have_sent' ||
        (string)($pay['payid'] ?? '') !== '' ||
        (int)($pay['message_id'] ?? 0) <= 0 ||
        (int)($pay['chat_id'] ?? 0) === 0) return false;
    $type = (string)($pay['type'] ?? '');
    $subjectMatches = match ($subject) {
        // The standard plan BUY_SUB uses both fields=0; only Catalogue's
        // custom term/volume selection is approved by accCustom.
        'custom' => $type === 'BUY_SUB' &&
            ((int)($pay['day'] ?? 0) > 0 || (int)($pay['volume'] ?? 0) > 0),
        'standard' => $type === 'BUY_SUB' &&
            (int)($pay['day'] ?? 0) === 0 && (int)($pay['volume'] ?? 0) === 0,
        'standard_renewal' => $type === 'RENEW_SCONFIG',
        'renewal' => $type === 'RENEW_ACCOUNT',
        'day' => preg_match('/^INCREASE_DAY_[0-9]+_[0-9]+$/D', $type) === 1,
        'volume' => preg_match('/^INCREASE_VOLUME_[0-9]+_[0-9]+$/D', $type) === 1,
        default => false,
    };
    if (!$subjectMatches) return false;
    // Validate BOTH ledger tables before reading the attempt; otherwise
    // payment_attempts-only migration can impersonate legacy receipts.
    $mode=achbotManualLedgerMode($connection);
    if ($mode==='invalid') return false;
    if ($mode==='legacy') return true;
    try {
        $id = (int)$pay['id'];
        $stmt = $connection->prepare('SELECT provider,stage,checkout_url
            FROM payment_attempts WHERE pay_row_id=? LIMIT 1 FOR UPDATE');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) {
            $stmt->close();
            return false;
        }
        $attempt = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($attempt === null) {
            // Legacy, operator-delivered manual receipt only. Human verification
            // remains required; missing evidence does not become verified funds.
            return true;
        }
        $method = (string)$attempt['provider'];
        if (!in_array($method, ['bank_transfer','gift_card','manual_usdt','manual_trx'], true) ||
            (string)$attempt['stage'] !== 'review') return false;
        $intent = achbotManualIntentDecode((string)($attempt['checkout_url'] ?? ''), $method);
        return is_array($intent) && !empty($intent['receipt_file_id']);
    } catch (Throwable $error) {
        // Invalid SQL or lost permissions after topology inspection
        // can never reactivate a historical manual receipt.
        return false;
    }
}

function achbotManualClaim(mysqli $connection, array $payInfo, string $method, int $userId,
                           ?string $chosenNetwork = null): bool {
    if (!in_array($method, ['bank_transfer', 'gift_card', 'manual_usdt','manual_trx'], true) ||
        (int)$payInfo['user_id'] !== $userId) return false;
    try {
        if (!$connection->begin_transaction()) return false;
        require_once __DIR__ . '/PaymentLifecycle.php';
        // The same owner-row serialization protects draft insertion and
        // hosted checkout. Acquire it BEFORE pays/attempt to avoid the
        // wallet/provider inverse lock order. Existing pinned claims remain
        // recoverable without applying a new supersession test to them.
        achbotPayUxLockDraftOwner($connection,$userId);
        $id = (int)$payInfo['id'];
        $stmt = $connection->prepare('SELECT * FROM pays WHERE id=? FOR UPDATE');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $current = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$current || (int)$current['user_id'] !== $userId ||
            (string)$current['state'] !== 'pending' ||
            (string)($current['payid'] ?? '') !== '') {
            $connection->rollback();
            return false;
        }
        $stmt = $connection->prepare('SELECT provider,stage,checkout_url,provider_status,
            TIMESTAMPDIFF(SECOND,created_at,NOW()) AS age_seconds
            FROM payment_attempts WHERE pay_row_id=? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($existing !== null) {
            require_once __DIR__ . '/PaymentLifecycle.php';
            if ((string)$existing['provider_status']==='' &&
                (int)$existing['age_seconds']>=achbotPayUxManualDeadlineSeconds()) {
                $connection->rollback(); return false;
            }
            if (($current['type']??'')==='BUY_SUB' && $method==='bank_transfer') {
                $stmt=$connection->prepare('SELECT * FROM bank_buy_invoice_claims
                    WHERE pay_row_id=? LIMIT 1 FOR UPDATE');
                if (!$stmt) throw new RuntimeException('bank_buy_existing_schema_missing');
                $stmt->bind_param('i',$id);
                if (!$stmt->execute()) throw new RuntimeException('bank_buy_existing_issue_read');
                $issued=$stmt->get_result()->fetch_assoc();$stmt->close();
                $stmt=$connection->prepare('SELECT * FROM payment_attempts
                    WHERE pay_row_id=? LIMIT 1 FOR UPDATE');
                if (!$stmt) throw new RuntimeException('bank_buy_existing_attempt_prepare');
                $stmt->bind_param('i',$id);
                if (!$stmt->execute()) throw new RuntimeException('bank_buy_existing_attempt_read');
                $original=$stmt->get_result()->fetch_assoc();$stmt->close();
                if (!achbotBankBuyClaimMatches($current,$issued) ||
                    !achbotBankBuyAttemptMatches($current,$original,$issued)) {
                    $connection->rollback();
                    return false;
                }
            }
            if (!$connection->commit()) return false;
            if (!hash_equals($method, (string)$existing['provider']) || $existing['stage'] !== 'pending')
                return false;
            if (in_array($method, ['manual_usdt','manual_trx'], true)) {
                $pinned=achbotManualIntentDecode((string)$existing['checkout_url'], $method);
                // An invalid historical address must not be reopened, even
                // without a network argument. Retain the row for review.
                return $pinned !== null && ($chosenNetwork === null ||
                    $pinned['network'] === $chosenNetwork);
            }
            return true;
        }
        // Only NEW method selection must pass supersession. The owning
        // user row is locked, so a replacement cannot commit between this
        // CURRENT successor read and the first 003 claim's COMMIT.
        if (!achbotPayUxUnclaimedDraftUsable($connection,$current,true)) {
            $connection->rollback();
            return false;
        }
        if (!achbotManualEnabled($method) ||
            !achbotPayUxAudienceAllowed($connection,$method,$userId,true) ||
            !achbotSupportsPaymentSubject($connection,$current,true)) {
            $connection->rollback();
            return false;
        }
        $hash = (string)$current['hash_id'];
        $price = (int)$current['price'];
        if ($price <= 0) {
            $connection->rollback();
            return false;
        }
        $quote = (string)$price;
        $currency = 'toman';
        $settlementNetwork = null;
        $intent = ['method'=>$method];
        if ($method === 'bank_transfer') {
            $bank = achbotManualBankIdentity();
            if ($bank === null) { $connection->rollback(); return false; }
            $intent += $bank;
            $quote = (string)generateHigherRandomizedAmount($price)['toman'];
        } elseif ($method === 'gift_card') {
            $url = achbotManualSellerUrl();
            if ($url === null) { $connection->rollback(); return false; }
            $intent['seller_url'] = $url;
        } elseif ($method === 'manual_usdt' || $method === 'manual_trx') {
            $config = $method === 'manual_trx' ? achbotManualTrxConfiguration()
                : achbotManualUsdtConfiguration($chosenNetwork);
            if ($config === null || ($chosenNetwork !== null &&
                $config['network'] !== $chosenNetwork)) {
                $connection->rollback();
                return false;
            }
            $rate=$method==='manual_trx' ? $config['rate']
                : achbotUsdtRateForUser($connection,$userId);
            if ($rate===null) { $connection->rollback(); return false; }
            $precision = $method === 'manual_trx' ? 6 : 2;
            $factor = $method === 'manual_trx' ? 1000000 : 100;
            $rawQuote=$price/$rate;
            if (!is_finite($rate) || $rate <= 0 || !is_finite($rawQuote) ||
                $rawQuote <= 0 || $rawQuote > 1000000000000) {
                $connection->rollback();
                return false;
            }
            $quote = number_format(ceil($rawQuote * $factor) / $factor,
                                   $precision, '.', '');
            $settlementNetwork = $config['network'];
            $intent['network'] = $config['network'];
            $intent['wallet'] = $config['wallet'];
            $intent['rate_toman'] = (string)$rate;
            $currency = $method === 'manual_trx' ? 'TRX' : 'USDT';
        }
        $destination = achbotManualIntentEncode($intent);
        if ($destination === null) { $connection->rollback(); return false; }
        $stmt = $connection->prepare("INSERT INTO payment_attempts
            (pay_row_id,provider,order_hash,quote_amount,quote_currency,local_amount_toman,
             settlement_currency,checkout_url,stage)
            VALUES (?,?,?,?,?,?,?,?,'pending')");
        $stmt->bind_param('issssiss', $id, $method, $hash, $quote, $currency, $price,
            $settlementNetwork, $destination);
        $inserted=$stmt->execute();
        $rows=$stmt->affected_rows;
        $attemptId=(int)$connection->insert_id;
        $stmt->close();
        if ($inserted!==true || $rows!==1)
            throw new RuntimeException('manual_claim_attempt_unverified');
        if ($method==='bank_transfer' && $current['type']==='BUY_SUB') {
            // Both records must COMMIT, or neither one may advertise a new
            // card-payment invoice. No later Cron may mint original evidence.
            $stmt=$connection->prepare('SELECT * FROM payment_attempts
                WHERE id=? AND pay_row_id=? LIMIT 1 FOR UPDATE');
            if (!$stmt) throw new RuntimeException('bank_buy_issue_attempt_prepare');
            $stmt->bind_param('ii',$attemptId,$id);
            if (!$stmt->execute()) throw new RuntimeException('bank_buy_issue_attempt_read');
            $issuedAttempt=$stmt->get_result()->fetch_assoc();$stmt->close();
            if (!$issuedAttempt) throw new RuntimeException('bank_buy_issue_attempt_missing');
            achbotBankBuyRecordIssuedIntent($connection,$current,$issuedAttempt);
        }
        if (!$connection->commit())
            throw new RuntimeException('manual_claim_commit_unverified');
        return true;
    } catch (Throwable $error) {
        try { $connection->rollback(); } catch (Throwable $ignored) {}
        return false;
    }
}


/** Receipt attempts are owner-bound; receiving is recoverable, not claimable by another method. */
function achbotManualReceiptAllowed(mysqli $connection, array $payInfo, string $method, int $userId): bool {
    if ((int)$payInfo['user_id'] !== $userId ||
        (string)$payInfo['state'] !== 'pending' ||
        (string)($payInfo['payid'] ?? '') !== '' ||
        !in_array($method, ['bank_transfer','gift_card','manual_usdt','manual_trx'], true)) return false;
    try {
        require_once __DIR__ . '/PaymentLifecycle.php';
        $stmt = $connection->prepare('SELECT provider,stage,checkout_url,provider_status,
            TIMESTAMPDIFF(SECOND,created_at,NOW()) AS age_seconds
            FROM payment_attempts WHERE pay_row_id=? LIMIT 1');
        $id = (int)$payInfo['id'];
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $attempt = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $validWindow=$attempt && (
            $attempt['stage']==='receiving' ||
            (string)($attempt['provider_status']??'')==='receipt_started' ||
            ((string)($attempt['provider_status']??'')==='' &&
             (int)$attempt['age_seconds']<achbotPayUxManualDeadlineSeconds())
        );
        return $validWindow &&
            (string)$attempt['provider'] === $method &&
            in_array((string)$attempt['stage'], ['pending','receiving'], true) &&
            (!in_array($method, ['manual_usdt','manual_trx'], true) ||
             achbotManualIntentDecode((string)$attempt['checkout_url'], $method) !== null);
    } catch (Throwable $error) {
        return false;
    }
}

/**
 * Save Telegram's reusable file identifier before delivery. A 2-minute
 * delivery lease prevents simultaneous retries; stale leases can be reclaimed
 * using the SAME evidence, not replaced by a different image.
 */
function achbotManualBeginEvidence(mysqli $connection, array $payInfo, string $method,
                                   int $userId, string $fileId): ?string {
    if ($userId <= 0 || $fileId === '' || strlen($fileId) > 1200 ||
        !in_array($method, ['bank_transfer','gift_card','manual_usdt','manual_trx'], true)) return null;
    try {
        $claimToken = bin2hex(random_bytes(16));
        $connection->begin_transaction();
        $id = (int)$payInfo['id'];
        $stmt = $connection->prepare('SELECT user_id,state,payid FROM pays WHERE id=? FOR UPDATE');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $pay = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$pay || (int)$pay['user_id'] !== $userId || $pay['state'] !== 'pending' ||
            (string)($pay['payid'] ?? '') !== '') { $connection->rollback(); return null; }
        $stmt = $connection->prepare('SELECT id,provider,stage,checkout_url,provider_status,
            TIMESTAMPDIFF(SECOND,created_at,NOW()) AS age_seconds
            FROM payment_attempts WHERE pay_row_id=? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $attempt = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$attempt || (string)$attempt['provider'] !== $method ||
            !in_array((string)$attempt['stage'], ['pending','receiving'], true)) {
            $connection->rollback(); return null;
        }
        require_once __DIR__ . '/PaymentLifecycle.php';
        if ($attempt['stage']==='pending' &&
            (string)($attempt['provider_status']??'')!== 'receipt_started' &&
            (int)$attempt['age_seconds']>=achbotPayUxManualDeadlineSeconds()) {
            $connection->rollback(); return null;
        }
        $intent = achbotManualIntentDecode((string)$attempt['checkout_url'], $method);
        if ($intent === null) { $connection->rollback(); return null; }
        if ($attempt['stage'] === 'receiving') {
            if (($intent['receipt_file_id'] ?? null) !== $fileId) {
                $connection->rollback(); return null;
            }
        } else {
            if (isset($intent['receipt_file_id'])) { $connection->rollback(); return null; }
            $intent['receipt_file_id'] = $fileId;
        }
        $encoded = achbotManualIntentEncode($intent);
        if ($encoded === null) { $connection->rollback(); return null; }
        $attemptId = (int)$attempt['id'];
        $stmt = $connection->prepare("UPDATE payment_attempts
            SET stage='receiving', checkout_url=?, review_notice_claim_token=?,
                review_notice_claimed_at=NOW()
            WHERE id=? AND stage IN ('pending','receiving') AND
                (review_notice_claim_token IS NULL OR review_notice_claimed_at IS NULL OR
                 review_notice_claimed_at < DATE_SUB(NOW(), INTERVAL 2 MINUTE))");
        $stmt->bind_param('ssi', $encoded, $claimToken, $attemptId);
        $stmt->execute();
        $changed = $stmt->affected_rows;
        $stmt->close();
        if ($changed !== 1) { $connection->rollback(); return null; }
        if (!$connection->commit()) throw new RuntimeException('manual_evidence_reserve_failed');
        return $claimToken;
    } catch (Throwable $error) {
        try { $connection->rollback(); } catch (Throwable $ignored) {}
        return null;
    }
}

function achbotManualReleaseEvidenceLease(mysqli $connection, string $hashId, string $token): void {
    try {
        $stmt = $connection->prepare("UPDATE payment_attempts a
            JOIN pays p ON p.id=a.pay_row_id
            SET a.review_notice_claim_token=NULL, a.review_notice_claimed_at=NULL
            WHERE p.hash_id=? AND p.state='pending'
              AND a.stage='receiving' AND a.review_notice_claim_token=?");
        $stmt->bind_param('ss', $hashId, $token);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $ignored) {
        // Lease expiry permits a later retry even if connection was lost.
    }
}

/** Transactional expected-state transition after Telegram returned a message ID. */
function achbotManualFinishEvidence(mysqli $connection, string $hashId, string $fileId,
                                    string $token, int $messageId, int $adminChatId): bool {
    if ($messageId <= 0 || $adminChatId <= 0 || $token === '') return false;
    try {
        $connection->begin_transaction();
        $stmt = $connection->prepare('SELECT id,state,payid FROM pays WHERE hash_id=? FOR UPDATE');
        $stmt->bind_param('s', $hashId);
        $stmt->execute();
        $pay = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$pay || $pay['state'] !== 'pending' || (string)($pay['payid'] ?? '') !== '') {
            $connection->rollback(); return false;
        }
        $id = (int)$pay['id'];
        $stmt = $connection->prepare('SELECT id,provider,stage,checkout_url,review_notice_claim_token
            FROM payment_attempts WHERE pay_row_id=? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $attempt = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$attempt || !in_array((string)$attempt['provider'],
            ['bank_transfer','gift_card','manual_usdt','manual_trx'], true) ||
            $attempt['stage'] !== 'receiving' ||
            (string)$attempt['review_notice_claim_token'] !== $token) {
            $connection->rollback(); return false;
        }
        $intent = achbotManualIntentDecode((string)$attempt['checkout_url'], (string)$attempt['provider']);
        if (!$intent || ($intent['receipt_file_id'] ?? null) !== $fileId) {
            $connection->rollback(); return false;
        }
        $stmt = $connection->prepare("UPDATE pays SET state='have_sent',message_id=?,chat_id=?
            WHERE id=? AND state='pending' AND (payid IS NULL OR payid='')");
        $stmt->bind_param('iii', $messageId, $adminChatId, $id);
        $stmt->execute();
        $changed = $stmt->affected_rows;
        $stmt->close();
        if ($changed !== 1) { $connection->rollback(); return false; }
        $attemptId = (int)$attempt['id'];
        $stmt = $connection->prepare("UPDATE payment_attempts
            SET stage='review',review_notice_claim_token=NULL,review_notice_claimed_at=NULL
            WHERE id=? AND stage='receiving' AND review_notice_claim_token=?");
        $stmt->bind_param('is', $attemptId, $token);
        $stmt->execute();
        $changed = $stmt->affected_rows;
        $stmt->close();
        if ($changed !== 1 || !$connection->commit()) throw new RuntimeException('manual_evidence_commit_failed');
        return true;
    } catch (Throwable $error) {
        try { $connection->rollback(); } catch (Throwable $ignored) {}
        return false;
    }
}


function achbotManualCaption(mysqli $connection, array $payInfo, string $caption): string {
    $method = achbotManualSelectedMethod($connection, $payInfo);
    $label = $method === null ? 'نیازمند بررسی' : achbotManualLabel($method);
    require_once __DIR__.'/PaymentLifecycle.php';
    $header = achbotPayUxReference($payInfo).'🧾 روش پرداخت: <b>' . $label . "</b>\n";
    if (in_array($method, ['manual_usdt','manual_trx','bank_transfer'], true)) {
        try {
            $stmt = $connection->prepare('SELECT provider,quote_amount,quote_currency,
                checkout_url FROM payment_attempts WHERE pay_row_id=? LIMIT 1');
            $id = (int)$payInfo['id'];
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $details = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $intent = $details ? achbotManualIntentDecode((string)$details['checkout_url'], $method) : null;
            if ($intent && in_array($method, ['manual_usdt','manual_trx'], true)) {
                $network = (string)($intent['network'] ?? '');
                $address = (string)($intent['wallet'] ?? '');
                $asset = $method === 'manual_trx' ? 'TRX' : 'USDT';
                $valid = $method === 'manual_trx'
                    ? $network === 'TRON' && achbotManualTronAddressValid($address)
                    : achbotManualUsdtWalletValid($address, $network);
                if ($details['quote_currency'] === $asset && $valid) {
                    $header .= '💎 مبلغ رمزارز: <code>' .
                        htmlspecialchars((string)$details['quote_amount'], ENT_QUOTES, 'UTF-8') .
                        ' ' . $asset . '</code> | شبکه: <b>' . htmlspecialchars($network, ENT_QUOTES, 'UTF-8') .
                        "</b>\nآدرس: <code>" . htmlspecialchars($address, ENT_QUOTES, 'UTF-8') .
                        "</code>\n";
                }
            } elseif ($intent && $method === 'bank_transfer' &&
                      $details['quote_currency'] === 'toman') {
                $header .= '💳 مبلغ دقیق حواله: <code>' .
                    number_format((int)$details['quote_amount']) . " تومان</code>\n";
                $header .= '💳 مقصد: <code>' .
                    htmlspecialchars((string)($intent['account'] ?? ''), ENT_QUOTES, 'UTF-8') .
                    '</code> | ' . htmlspecialchars((string)($intent['holder'] ?? ''), ENT_QUOTES, 'UTF-8') . "\n";
            }
        } catch (Throwable $error) {
            // A failed lookup must not produce a fabricated bank destination.
        }
    }
    return $header . $caption;
}

function achbotManualReceiptPrompt(array $mainValues, string $method): string {
    $key = match ($method) {
        'gift_card' => 'please_send_gift_card_image',
        'manual_usdt' => 'please_send_usdt_image',
        'manual_trx' => 'please_send_trx_image',
        default => 'please_send_receipt_image'
    };
    return $mainValues[$key];
}

function achbotManualAcknowledgement(array $payInfo, string $method): string {
    require_once __DIR__.'/PaymentLifecycle.php';
    return "📥 تصویر " . achbotManualLabel($method) . " دریافت شد.\n" .
        achbotPayUxReference($payInfo) .
        "⏳ رسید در انتظار بررسی مدیر است؛ ارسال تصویر به معنی تأیید پرداخت یا تحویل سرویس نیست.";
}

function getCartToCartMessageKey(string $messageType): string {
    require_once dirname(__DIR__, 2) . '/src/Presentation/Labels.php';
    return \AchBot\Presentation\Labels\cardToCardMessageKey($messageType);
}


/** Read only the original pinned manual quote and destination, never changed settings. */
function buildCartToCartInfoMessage(array $paymentKeys, array $mainValues, int $priceToman,
                                    int $userId = 0, string $method = 'bank_transfer',
                                    string $hashId = ''): string {
    global $connection;
    if (!in_array($method, ['bank_transfer','gift_card','manual_usdt','manual_trx'], true) ||
        $hashId === '' || !($connection instanceof mysqli)) return '';
    try {
        require_once __DIR__.'/PaymentLifecycle.php';
        $pay=achbotPayUxOwnedReference($connection,$hashId,$userId);
        if (!$pay) return '';
        $reference=achbotPayUxReference($pay);
        $stmt = $connection->prepare("SELECT provider,quote_amount,quote_currency,
            local_amount_toman,settlement_currency,checkout_url
            FROM payment_attempts WHERE order_hash=? AND provider=? LIMIT 1");
        $stmt->bind_param('ss', $hashId, $method);
        $stmt->execute();
        $attempt = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$attempt || (int)$attempt['local_amount_toman'] !== $priceToman) return '';
        $intent = achbotManualIntentDecode((string)$attempt['checkout_url'], $method);
        if (!$intent) return '';
        if (in_array($method, ['manual_usdt','manual_trx'], true)) {
            $network = (string)($intent['network'] ?? '');
            $wallet = (string)($intent['wallet'] ?? '');
            $asset = $method === 'manual_trx' ? 'TRX' : 'USDT';
            $addressValid = $method === 'manual_trx'
                ? $network === 'TRON' && achbotManualTronAddressValid($wallet)
                : achbotManualUsdtWalletValid($wallet, $network);
            if ((string)$attempt['quote_currency'] !== $asset ||
                (string)$attempt['settlement_currency'] !== $network ||
                !$addressValid ||
                !preg_match('/^[0-9]{1,15}\.[0-9]{2,8}$/D', (string)$attempt['quote_amount']) ||
                (float)$attempt['quote_amount'] <= 0) return '';
            $template = (string)($mainValues[$method === 'manual_trx'
                ? 'brw_cart_to_cart_trx' : 'brw_cart_to_cart_usdt'] ?? '');
            return $reference.strtr($template, [
                'PRICE-TOMAN-ROUND' => number_format($priceToman),
                'PRICE-USDT' => number_format((float)$attempt['quote_amount'], 2, '.', ''),
                'USDT-NETWORK' => htmlspecialchars($network, ENT_QUOTES, 'UTF-8'),
                'USDT-WALLET' => htmlspecialchars($wallet, ENT_QUOTES, 'UTF-8'),
                'PRICE-TRX' => number_format((float)$attempt['quote_amount'], 6, '.', ''),
                'TRX-WALLET' => htmlspecialchars($wallet, ENT_QUOTES, 'UTF-8'),
            ]);
        }
        if ((string)$attempt['quote_currency'] !== 'toman') return '';
        $amount = (int)$attempt['quote_amount'];
        if ($amount <= 0) return '';
        if ($method === 'bank_transfer' &&
            (trim((string)($intent['account'] ?? '')) === '' ||
             trim((string)($intent['holder'] ?? '')) === '')) return '';
        $template = (string)($mainValues[$method === 'gift_card' ?
            'brw_cart_to_cart_gift' : 'brw_cart_to_cart_detail'] ?? '');
        return $reference.strtr($template, [
            'PRICE-TOMAN-ROUND' => number_format($priceToman),
            'PRICE-TOMAN' => number_format($amount),
            'PRICE-RIAL' => number_format($amount * 10),
            'ACCOUNT-NUMBER' => htmlspecialchars((string)($intent['account'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            'HOLDER-NAME' => htmlspecialchars((string)($intent['holder'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        ]);
    } catch (Throwable $error) {
        return '';
    }
}


function kbSendReceiptAndCancel(string $sendPrefix, string $cancelPrefix, string $hashId, string $method = 'bank_transfer'): string {
    global $connection;
    require_once dirname(__DIR__, 2) . '/src/Telegram/Markup.php';
    $url = null;
    if ($method === 'gift_card' && $connection instanceof mysqli) {
        try {
            $stmt = $connection->prepare('SELECT checkout_url FROM payment_attempts
                WHERE order_hash=? AND provider=? LIMIT 1');
            $stmt->bind_param('ss', $hashId, $method);
            $stmt->execute();
            $attempt = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $intent = $attempt ? achbotManualIntentDecode((string)$attempt['checkout_url'], $method) : null;
            $url = $intent['seller_url'] ?? null;
            // Historical pinned destinations remain immutable; suppress only
            // the unsafe outward link when it violates current URL rules.
            if (!is_string($url) || !achbotManualGiftSellerUrlValid($url)) $url = null;
        } catch (Throwable $ignored) { $url = null; }
    }
    return \AchBot\Telegram\Markup\receiptAndCancel($sendPrefix, $cancelPrefix, $hashId, $method, $url);
}

function kbOnlyCancel(string $cancelPrefix, string $hashId): string {
    require_once dirname(__DIR__, 2) . '/src/Telegram/Markup.php';
    return \AchBot\Telegram\Markup\onlyCancel($cancelPrefix, $hashId);
}

function handleCancelFlow(array $mainValues, $removeKeyboard) {
    delMessage();
    setUser();
    sendMessage($mainValues['operation_canceled'], $removeKeyboard, "HTML");
    sendMessage($mainValues['reached_main_menu'], getMainKeys());
    exit;
}

function extractPhotoFileId($update): ?string {
    require_once dirname(__DIR__, 2) . '/src/Telegram/Response.php';
    return \AchBot\Telegram\Response\photoFileId($update);
}

function duplicateReceiptGuard(mysqli $connection, array $payInfo, int $nowTs): ?string {
    $userId   = (int)$payInfo['user_id'];
    $type     = (string)$payInfo['type'];
    $planId   = (int)($payInfo['plan_id'] ?? 0);
    $serverId = (int)($payInfo['server_id'] ?? 0);
    $orderId  = (int)($payInfo['order_id'] ?? 0);
    $price    = (int)($payInfo['price'] ?? 0);
    $hashId   = (string)$payInfo['hash_id'];

    if (in_array($payInfo['state'], ['have_sent', 'approved'], true)) {
        return "⛔️ شما درخواست مشابه‌ای قبلا ثبت کرده‌اید.\n⚠️ لطفاً منتظر بررسی پشتیبانی بمانید.";
    }

    // BOT_STATES bounds prevent malformed settings from weakening legacy
    // duplicate/payment/review safeguards. Financial idempotency is separate.
    require_once __DIR__ . '/PaymentLifecycle.php';
    $isSpecial = ($type === 'BUY_SUB' || $type === 'INCREASE_WALLET');
    $approvedMinutes=$isSpecial
        ? achbotPayUxPolicyNumber('receiptApprovedMinutes',10,1,1440)
        : achbotPayUxPolicyNumber('receiptApprovedOtherMinutes',60,1,1440);
    $windowApproved = 60*$approvedMinutes;
    $windowHaveSent = 60*achbotPayUxPolicyNumber('receiptReviewMinutes',360,1,1440);
    $windowDeclined = 60*achbotPayUxPolicyNumber('receiptDeclinedMinutes',3,1,1440);

    $lookback = max($windowApproved, $windowHaveSent, $windowDeclined);
    $minTs = $nowTs - $lookback;

    if ($type === 'INCREASE_WALLET') {
        $sql = "
            SELECT `state`, `request_date`
            FROM `pays`
            WHERE `user_id` = ?
              AND `hash_id` <> ?
              AND `type` = ?
              AND IFNULL(`price`,0) = ?
              AND `request_date` >= ?
            ORDER BY `request_date` DESC
            LIMIT 200
        ";
        $stmt = $connection->prepare($sql);
        $stmt->bind_param("issii", $userId, $hashId, $type, $price, $minTs);

    } elseif ($type === 'BUY_SUB') {
        $sql = "
            SELECT `state`, `request_date`
            FROM `pays`
            WHERE `user_id` = ?
              AND `hash_id` <> ?
              AND `type` = ?
              AND IFNULL(`plan_id`,0) = ?
              AND IFNULL(`server_id`,0) = ?
              AND IFNULL(`price`,0) = ?
              AND `request_date` >= ?
            ORDER BY `request_date` DESC
            LIMIT 200
        ";
        $stmt = $connection->prepare($sql);
        $stmt->bind_param("issiiii", $userId, $hashId, $type, $planId, $serverId, $price, $minTs);

    } else {
        $sql = "
            SELECT `state`, `request_date`
            FROM `pays`
            WHERE `user_id` = ?
              AND `hash_id` <> ?
              AND `type` = ?
              AND IFNULL(`plan_id`,0) = ?
              AND IFNULL(`server_id`,0) = ?
              AND IFNULL(`order_id`,0) = ?
              AND IFNULL(`price`,0) = ?
              AND `request_date` >= ?
            ORDER BY `request_date` DESC
            LIMIT 200
        ";
        $stmt = $connection->prepare($sql);
        $stmt->bind_param("issiiiii", $userId, $hashId, $type, $planId, $serverId, $orderId, $price, $minTs);
    }

    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    if (!$res || $res->num_rows == 0) return null;

    $latestHaveSentDiff = null;
    $latestApprovedDiff = null;
    $latestDeclinedDiff = null;

    while ($row = $res->fetch_assoc()) {
        $st = (string)$row['state'];
        $ts = (int)$row['request_date'];
        $diff = $nowTs - $ts;

        if ($st === 'have_sent' && $latestHaveSentDiff === null) $latestHaveSentDiff = $diff;
        if ($st === 'approved'  && $latestApprovedDiff === null) $latestApprovedDiff = $diff;
        if ($st === 'declined'  && $latestDeclinedDiff === null) $latestDeclinedDiff = $diff;

        if ($latestHaveSentDiff !== null && $latestApprovedDiff !== null && $latestDeclinedDiff !== null) break;
    }

    if ($latestHaveSentDiff !== null && $latestHaveSentDiff < $windowHaveSent) {
        $remain = $windowHaveSent - $latestHaveSentDiff;
        $mins = (int)ceil($remain / 60);
        return "⛔️ یک درخواست مشابه شما در حال بررسی است.\n⚠️ لطفاً حدود {$mins} دقیقه دیگر دوباره تلاش کنید.";
    }


    if ($latestApprovedDiff !== null && $latestApprovedDiff < $windowApproved) {
        $remain = $windowApproved - $latestApprovedDiff;
        $mins = (int)ceil($remain / 60);
        return "⛔️ شما اخیراً یک درخواست مشابه با وضعیت تایید شده داشته‌اید.\n⚠️ لطفاً حدود {$mins} دقیقه دیگر دوباره تلاش کنید.";
    }


    if ($latestDeclinedDiff !== null && $latestDeclinedDiff < $windowDeclined) {
        $remain = $windowDeclined - $latestDeclinedDiff;
        return "⛔️ درخواست مشابه قبلی شما اخیراً رد شده است.\n⚠️ لطفاً {$remain} ثانیه دیگر دوباره تلاش کنید.";
    }

    return null;
}

function countUserPaysByState(mysqli $connection, int $userId, string $state): int {
    $stmt = $connection->prepare("SELECT COUNT(*) AS c FROM `pays` WHERE `user_id`=? AND `state`=?");
    $stmt->bind_param("is", $userId, $state);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0);
}

function sumUserPaysByState(mysqli $connection, int $userId, string $state): int {
    $stmt = $connection->prepare(
        "SELECT COALESCE(SUM(`price`), 0) AS total
         FROM `pays`
         WHERE `user_id`=? AND `state`=?"
    );
    $stmt->bind_param("is", $userId, $state);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row['total'] ?? 0);
}

function buildAdminKeyboardWithStats(
    mysqli $connection,
    array $buttonValues,
    string $approveCb,
    string $declineCb,
    string $hashId,
    int $userId
): string {

    $approvedCount = countUserPaysByState($connection, $userId, 'approved');
    $declinedCount = countUserPaysByState($connection, $userId, 'declined');
    $approvedSum   = sumUserPaysByState($connection, $userId, 'approved');

    return inlineKeyboard([
        [
            ['text' => $buttonValues['decline'], 'callback_data' => $declineCb],
            ['text' => $buttonValues['approve'], 'callback_data' => $approveCb],
        ],
        [
            ['text' => $approvedCount . ' عدد', 'callback_data' => "noop_okc_{$hashId}"],
            ['text' => 'پرداخت موفق', 'callback_data' => "noop_ok_{$hashId}"],
        ],
        [
            ['text' => $declinedCount . ' عدد', 'callback_data' => "noop_badc_{$hashId}"],
            ['text' => 'پرداخت رد شده', 'callback_data' => "noop_bad_{$hashId}"],
        ],
        [
            ['text' => number_format($approvedSum) . ' تومان', 'callback_data' => "noop_sumv_{$hashId}"],
			['text' => 'مجموع', 'callback_data' => "noop_sum_{$hashId}"],
        ],
        [
            ['text' => "🆔 {$hashId}", 'callback_data' => "noop_{$hashId}"]
        ],
    ]);
}


/**
 * Delivery failure never turns a pending invoice into an approved payment.
 * Only the original leased photo may be retried; the source file ID is
 * already durable in the attempt before any outbound Telegram request.
 */
function sendReceiptToAdminsAndUpdatePay(
    mysqli $connection,
    string $fileid,
    string $caption,
    string $adminKeyboard,
    int $adminChatId,
    string $hashId,
    string $claimToken
): bool {
    try {
        $response = sendPhoto($fileid, $caption, $adminKeyboard, 'HTML', $adminChatId);
        $msgId = (int)($response->result->message_id ?? 0);
        if ($msgId <= 0 ||
            !achbotManualFinishEvidence($connection, $hashId, $fileid, $claimToken,
                                        $msgId, $adminChatId)) {
            achbotManualReleaseEvidenceLease($connection, $hashId, $claimToken);
            return false;
        }
    } catch (Throwable $error) {
        achbotManualReleaseEvidenceLease($connection, $hashId, $claimToken);
        return false;
    }
    try {
        sendToAllAdmins('sendPhoto', [
            'photo' => $fileid,
            'caption' => $caption,
            'reply_markup' => $adminKeyboard,
            'parse_mode' => 'HTML'
        ], $hashId);
    } catch (Throwable $ignored) {
        // The primary operator message and protected DB transition succeeded.
    }
    return true;
}

function sendWithStartButton($text, $buttonText, $botUsername, $startPayload = 'mainMenu', $parseMode = 'HTML', string $paymentHash = '')
{
    $buttons = [];
    if ($paymentHash !== '' && preg_match('/^[A-Za-z0-9_-]{1,50}$/D', $paymentHash)) {
        $buttons[] = [['text'=>'🔄 بررسی وضعیت', 'callback_data'=>'ps_'.$paymentHash]];
    }
    $buttons[] = [[
        'text' => $buttonText,
        'url'  => "https://t.me/{$botUsername}?start={$startPayload}"
    ]];
    $keyboard = json_encode(['inline_keyboard'=>$buttons], JSON_UNESCAPED_UNICODE);

    // A start deep link already sends a NEW menu. Enrich evidence before send,
    // using persisted ownership rather than a callback-supplied numeric ID.
    if ($paymentHash!=='' && ($GLOBALS['connection']??null) instanceof mysqli) {
        require_once __DIR__.'/PaymentLifecycle.php';
        $pay=achbotPayUxOwnedReference($GLOBALS['connection'],$paymentHash,(int)($GLOBALS['from_id']??0));
        if ($pay && !str_contains($text,achbotPayUxReference($pay)))
            $text=achbotPayUxReference($pay).$text;
    }
    $sent=sendMessage($text, $keyboard, $parseMode);
    if ($paymentHash!=='' && isset($GLOBALS['connection']) &&
        $GLOBALS['connection'] instanceof mysqli &&
        isset($GLOBALS['from_id']) && (int)$GLOBALS['from_id']>0) {
        require_once __DIR__ . '/PaymentLifecycle.php';
        achbotPayUxTrackByHash($GLOBALS['connection'],$paymentHash,
            (int)$GLOBALS['from_id'],$sent);
    }
    return $sent;
}


/** AChBot 004 schema proof, shared by payment reservation and deletion guards.
 * Preflight absent/incompatible separately; never assume table name is enough.
 * Privilege denial or schema drift blocks all new financial remote effects.
 */
function achbotAutoRenewSchemaState(mysqli $db): string {
    static $states=[];
    $conn=spl_object_id($db);
    if (isset($states[$conn])) return $states[$conn];
    $states[$conn]='incompatible';
    try {
        $r=$db->query("SELECT ENGINE FROM information_schema.TABLES
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='automatic_renewal_attempts'
              AND TABLE_TYPE='BASE TABLE'");
        $t=$r->fetch_assoc();
        if (!$t) return $states[$conn]='absent';
        if ($r->num_rows!==1 || strcasecmp((string)$t['ENGINE'],'InnoDB')!==0)
            return 'incompatible';
        $r=$db->query("SELECT COLUMN_NAME,DATA_TYPE,COLUMN_TYPE,
                CHARACTER_MAXIMUM_LENGTH,COLLATION_NAME,IS_NULLABLE,COLUMN_DEFAULT
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='automatic_renewal_attempts'");
        $cols=[];
        while ($row=$r->fetch_assoc()) $cols[$row['COLUMN_NAME']]=$row;
        $expected=[
          'id'=>['bigint',false,true,null],
          'order_id'=>['int',false,false,null],
          'cycle_expire'=>['int',false,false,null],
          'pay_row_id'=>['int',false,false,null],
          'user_id'=>['bigint',false,false,null],
          'plan_id'=>['int',false,false,null],
          'server_id'=>['int',false,false,null],
          'account_remark'=>['varchar',false,false,100],
          'price_toman'=>['int',false,false,null],
          'duration_days'=>['int',false,false,null],
          'volume_gb'=>['int',false,false,null],
          'new_expire'=>['int',false,false,null],
          'stage'=>['varchar',false,false,32],
          'reason_code'=>['varchar',true,false,32],
          'review_notified_at'=>['datetime',true,false,null],
          'review_claim_token'=>['char',true,false,32],
          'review_claimed_at'=>['datetime',true,false,null],
          'created_at'=>['datetime',false,false,null],
          'updated_at'=>['datetime',false,false,null],
        ];
        if (count($cols)!==count($expected)) return 'incompatible';
        foreach ($expected as $name=>$spec) {
            $c=$cols[$name]??null;
            if (!$c || strtolower((string)$c['DATA_TYPE'])!==$spec[0] ||
                ($c['IS_NULLABLE']==='YES')!==$spec[1] ||
                str_contains(strtolower((string)$c['COLUMN_TYPE']),'unsigned')!==$spec[2] ||
                ($spec[3]!==null && (int)$c['CHARACTER_MAXIMUM_LENGTH']!==$spec[3]))
                return 'incompatible';
        }
        foreach (['stage','reason_code','review_claim_token'] as $name)
            if (($cols[$name]['COLLATION_NAME']??'')!=='utf8mb4_bin')
                return 'incompatible';
        if (trim((string)$cols['stage']['COLUMN_DEFAULT'],"'")!=='reserved')
            return 'incompatible';
        $r=$db->query("SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='automatic_renewal_attempts'
            ORDER BY INDEX_NAME,SEQ_IN_INDEX");
        $idx=[];
        while ($i=$r->fetch_assoc()) {
            $n=$i['INDEX_NAME'];
            $idx[$n]['unique']=((int)$i['NON_UNIQUE']===0);
            $idx[$n]['cols'][]=$i['COLUMN_NAME'];
        }
        $required=[
          'PRIMARY'=>[true,['id']],
          'uq_renewal_cycle'=>[true,['order_id','cycle_expire']],
          'uq_renewal_payment'=>[true,['pay_row_id']],
          'idx_renewal_inflight'=>[false,['order_id','stage']],
          'idx_renewal_review'=>[false,['stage','updated_at','review_notified_at']],
        ];
        if (count($idx)!==count($required)) return 'incompatible';
        foreach ($required as $n=>$spec)
            if (!isset($idx[$n]) || $idx[$n]['unique']!==$spec[0] ||
                $idx[$n]['cols']!==$spec[1]) return 'incompatible';

        // Exactly one restrictive FK to historical pays. No order FK: the
        // order itself must remain deletable when renewal evidence is done.
        $r=$db->query("SELECT k.CONSTRAINT_NAME,k.COLUMN_NAME,
                k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,
                rc.UPDATE_RULE,rc.DELETE_RULE
            FROM information_schema.KEY_COLUMN_USAGE k
            INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
             ON rc.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA
             AND rc.TABLE_NAME=k.TABLE_NAME
             AND rc.CONSTRAINT_NAME=k.CONSTRAINT_NAME
            WHERE k.TABLE_SCHEMA=DATABASE()
              AND k.TABLE_NAME='automatic_renewal_attempts'
              AND k.REFERENCED_TABLE_NAME IS NOT NULL");
        $fk=$r->fetch_all(MYSQLI_ASSOC);
        if (count($fk)!==1 ||
            $fk[0]['CONSTRAINT_NAME']!=='fk_renewal_pay' ||
            $fk[0]['COLUMN_NAME']!=='pay_row_id' ||
            $fk[0]['REFERENCED_TABLE_NAME']!=='pays' ||
            $fk[0]['REFERENCED_COLUMN_NAME']!=='id' ||
            !in_array($fk[0]['UPDATE_RULE'],['RESTRICT','NO ACTION'],true) ||
            !in_array($fk[0]['DELETE_RULE'],['RESTRICT','NO ACTION'],true))
            return 'incompatible';
        return $states[$conn]='ready';
    } catch (Throwable $error) {
        error_log('achbot_auto_renew_schema_preflight_unavailable');
        return 'incompatible';
    }
}

/** Never infer physical panel identity from URLs or credentials. */
function achbotAutoRenewSameAccount(mysqli $db, int $serverA, int $serverB): bool {
    return $serverA>0 && $serverB>0 &&
        ($serverA===$serverB || achbotSameProviderPanel($db,$serverA,$serverB));
}

/**
 * Must run INSIDE the selected-order transaction before a wallet debit.
 * Lock all active candidate rows, including different order IDs, so parallel
 * workers cannot independently charge the same physical Marzban account.
 */
function achbotAutoRenewAccountExclusiveLocked(
    mysqli $db, int $orderId, int $serverId, string $remark
): bool {
    if ($orderId<=0 || $serverId<=0 || $remark==='') return false;
    $stmt=$db->prepare("SELECT id,server_id FROM orders_list
        WHERE status=1 AND remark=? FOR UPDATE");
    $stmt->bind_param('s',$remark);
    $stmt->execute();
    $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $found=false;
    foreach ($rows as $r) {
        if ((int)$r['id']===$orderId) $found=true;
        elseif (achbotAutoRenewSameAccount($db,$serverId,(int)$r['server_id']))
            return false;
    }
    return $found;
}

/** Legacy order deletion gate: lock + flag OFF before remote destructive call. */
function achbotAutoRenewPrepareDeletion(mysqli $db, int $orderId): bool {
    if ($orderId<=0) return false;
    $schema=achbotAutoRenewSchemaState($db);
    if ($schema==='incompatible') return false;
    try {
        $db->begin_transaction();
        $stmt=$db->prepare("SELECT id,server_id,remark,status FROM orders_list
            WHERE id=? FOR UPDATE");
        $stmt->bind_param('i',$orderId);
        $stmt->execute();
        $order=$stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$order || (int)$order['status']!==1 ||
            !achbotAutoRenewAccountExclusiveLocked($db,$orderId,
            (int)$order['server_id'],(string)$order['remark'])) {
            $db->rollback(); return false;
        }
        if ($schema==='ready') {
            $stmt=$db->prepare("SELECT id FROM automatic_renewal_attempts
                WHERE order_id=? AND stage<>'done' LIMIT 1 FOR UPDATE");
            $stmt->bind_param('i',$orderId);
            $stmt->execute();
            $pending=$stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($pending) { $db->rollback(); return false; }
        }
        // Durable deletion fence before remote DELETE. Any uncertain remote
        // result cannot cause an unattended repeated delete or new renewal.
        $stmt=$db->prepare("UPDATE orders_list SET status=0,autoRenew='off'
            WHERE id=? AND status=1");
        $stmt->bind_param('i',$orderId);
        $stmt->execute();
        $changed=$stmt->affected_rows;
        $stmt->close();
        if ($changed!==1 || $db->commit()!==true) throw new RuntimeException('delete_guard_commit_unknown');
        return true;
    } catch (Throwable $error) {
        try {$db->rollback();} catch (Throwable $ignored) {}
        error_log('achbot_auto_renew_deletion_guard_deferred');
        return false;
    }
}

/**
 * Complete an already-authorized remote deletion atomically in local SQL.
 * A failure leaves the order status=0 for operator reconciliation, never
 * eligible for a Cron-driven second remote deletion.
 */
function achbotAutoRenewFinalizeDeletion(
    mysqli $db, int $orderId, int $serverId, bool $restoreCapacity
): bool {
    if ($orderId<=0 || $serverId<=0 ||
        achbotAutoRenewSchemaState($db)==='incompatible') return false;
    try {
        $db->begin_transaction();
        $stmt=$db->prepare("SELECT status,server_id FROM orders_list
            WHERE id=? FOR UPDATE");
        $stmt->bind_param('i',$orderId);
        $stmt->execute();
        $row=$stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row || (int)$row['status']!==0 ||
            (int)$row['server_id']!==$serverId) {
            $db->rollback(); return false;
        }
        $stmt=$db->prepare("DELETE FROM orders_list WHERE id=? AND status=0");
        $stmt->bind_param('i',$orderId);
        $stmt->execute();
        $removed=$stmt->affected_rows;
        $stmt->close();
        if ($removed!==1) { $db->rollback(); return false; }
        if ($restoreCapacity) {
            $stmt=$db->prepare("UPDATE server_info SET ucount=ucount+1
                WHERE id=? AND ucount<2147483647");
            $stmt->bind_param('i',$serverId);
            $stmt->execute();
            $saved=$stmt->affected_rows;
            $stmt->close();
            if ($saved!==1) { $db->rollback(); return false; }
        }
        if ($db->commit()!==true) throw new RuntimeException('delete_commit_unknown');
        return true;
    } catch (Throwable $error) {
        try {$db->rollback();} catch (Throwable $ignored) {}
        error_log('achbot_auto_renew_local_deletion_deferred');
        return false;
    }
}
