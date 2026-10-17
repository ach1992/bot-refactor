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
        default => null
    };
}

function achbotManualLabel(string $method): string {
    return match ($method) {
        'gift_card' => 'گیفت‌کارت',
        'manual_usdt' => 'تتر دستی (USDT)',
        default => 'کارت‌به‌کارت بانکی'
    };
}

function achbotManualUsdtWalletValid(string $wallet, string $network): bool {
    if (!in_array($network, ['BEP20','ERC20','TRC20'], true)) return false;
    if ($network === 'TRC20') return preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/D', $wallet) === 1;
    return preg_match('/^0x[0-9a-fA-F]{40}$/D', $wallet) === 1;
}

/**
 * A manual transfer is a third-party on-chain transfer: the bot does not
 * verify its screenshot or infer final settlement from Telegram evidence.
 */
function achbotManualUsdtConfiguration(): ?array {
    global $paymentKeys, $USDTPrice, $USDTPriceagent, $connection;
    $keys = is_array($paymentKeys ?? null) ? $paymentKeys : [];
    $network = (string)($keys['manualUsdtNetwork'] ?? 'BEP20');
    $wallet = trim((string)($keys['manualUsdtWallet'] ?? ''));
    if (!achbotManualUsdtWalletValid($wallet, $network)) return null;
    $manualRate = $keys['manualUsdtRateToman'] ?? null;
    $rate = $manualRate !== null && is_numeric($manualRate) && (float)$manualRate > 0
        ? (float)$manualRate : 0.0;
    if ($rate <= 0) {
        $rate = isset($USDTPrice) && is_numeric($USDTPrice) ? (float)$USDTPrice : 0.0;
    }
    if (!is_finite($rate) || $rate < 100 || $rate > 1000000000) return null;
    $agentRate = isset($USDTPriceagent) && is_numeric($USDTPriceagent)
        ? (float)$USDTPriceagent : 0.0;
    if (!is_finite($agentRate) || $agentRate <= 0) $agentRate = $rate;
    // A configured manual-rate override applies to all customers, including agents.
    if ($manualRate !== null && $rate > 0) $agentRate = $rate;
    return ['network'=>$network, 'wallet'=>$wallet, 'rate'=>$rate, 'agent_rate'=>$agentRate];
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
        ($intent['method'] ?? null) === $method) return $intent;
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
    if ($method === 'manual_usdt') return ($botState['manualUsdtState'] ?? 'off') === 'on' &&
        achbotManualUsdtConfiguration() !== null;
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
        return in_array($method, ['bank_transfer', 'gift_card', 'manual_usdt'], true) ? $method : null;
    } catch (Throwable $error) {
        return null;
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
        $stmt = $connection->prepare('SELECT id,user_id,type,price,state,payid FROM pays WHERE id=? FOR UPDATE');
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
        $stmt = $connection->prepare('SELECT provider FROM payment_attempts WHERE pay_row_id=? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $attempt = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($attempt && (string)$attempt['provider'] !== 'bank_transfer') {
            $connection->rollback();
            return false;
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
        $stmt = $connection->prepare("UPDATE pays SET state='auto_cart_approved'
            WHERE id=? AND state='have_sent'");
        $stmt->bind_param('i',$id);
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
 * Customer-wallet route, called with the pays row already FOR UPDATE.
 * A present shared ledger must be inspected; any attempt blocks wallet use.
 * Historical 23-table installs have no ledger and no new checkout issuance,
 * so they retain their pre-003 wallet behavior. SQL/permission failures are
 * never interpreted as an absent claim.
 */
function achbotWalletDirectAllowedLocked(mysqli $connection, array $pay,
                                         int $actor): bool {
    if ($actor <= 0 || (int)($pay['user_id'] ?? 0) !== $actor ||
        !in_array((string)($pay['state'] ?? ''), ['pending','send','low_wallet'], true) ||
        (string)($pay['payid'] ?? '') !== '') return false;
    $id = (int)($pay['id'] ?? 0);
    if ($id <= 0) return false;
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

/** Legacy TRON buttons share the same exclusive invoice claim as other methods. */
function achbotTronClaim(mysqli $connection, array $seen, int $userId,
                         float $trxAmount, string $wallet): ?array {
    if ($userId <= 0 || !is_finite($trxAmount) || $trxAmount <= 0 || $wallet === '') return null;
    try {
        $connection->begin_transaction();
        $id = (int)$seen['id'];
        $stmt = $connection->prepare('SELECT id,hash_id,user_id,price,state,payid FROM pays WHERE id=? FOR UPDATE');
        $stmt->bind_param('i',$id);$stmt->execute();
        $pay = $stmt->get_result()->fetch_assoc();$stmt->close();
        if (!$pay || (int)$pay['user_id'] !== $userId ||
            $pay['state'] !== 'pending' || (string)($pay['payid'] ?? '') !== '') {
            $connection->rollback(); return null;
        }
        $stmt=$connection->prepare('SELECT provider,stage,quote_amount,checkout_url
            FROM payment_attempts WHERE pay_row_id=? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('i',$id);$stmt->execute();
        $attempt=$stmt->get_result()->fetch_assoc();$stmt->close();
        if ($attempt !== null) {
            $connection->commit();
            if ($attempt['provider'] !== 'tron_wallet' || $attempt['stage'] !== 'pending' ||
                !str_starts_with((string)$attempt['checkout_url'], 'tron:')) return null;
            return ['quote'=>(string)$attempt['quote_amount'],
                    'wallet'=>substr((string)$attempt['checkout_url'],5)];
        }
        $quote=number_format($trxAmount,2,'.','');
        $address='tron:'.$wallet;
        $price=(int)$pay['price'];$hash=(string)$pay['hash_id'];
        $stmt=$connection->prepare("INSERT INTO payment_attempts
            (pay_row_id,provider,order_hash,quote_amount,quote_currency,local_amount_toman,
             settlement_currency,checkout_url,stage)
            VALUES (?,'tron_wallet',?,?,'TRX',?,'TRX',?,'pending')");
        $stmt->bind_param('issis',$id,$hash,$quote,$price,$address);
        $stmt->execute();$stmt->close();
        // The frozen 23-table pays schema has no tron_price column.
        // Quote and receiving address are pinned in the shared ledger only.
        if (!$connection->commit()) throw new RuntimeException('tron_claim_commit_failed');
        return ['quote'=>$quote,'wallet'=>$wallet];
    } catch(Throwable $error) {
        try{$connection->rollback();}catch(Throwable $ignored){}
        return null;
    }
}
function achbotTronSubmitHash(mysqli $connection, string $hashId,
                              int $userId, string $txId): bool {
    if ($userId<=0 || !preg_match('/^[0-9a-f]{64}$/iD',$txId)) return false;
    try {
        $connection->begin_transaction();
        $stmt=$connection->prepare('SELECT id,user_id,state,payid FROM pays WHERE hash_id=? FOR UPDATE');
        $stmt->bind_param('s',$hashId);$stmt->execute();
        $pay=$stmt->get_result()->fetch_assoc();$stmt->close();
        if (!$pay || (int)$pay['user_id'] !== $userId ||
            $pay['state'] !== 'pending' || (string)($pay['payid'] ?? '') !== '') {
            $connection->rollback();return false;
        }
        $id=(int)$pay['id'];
        $stmt=$connection->prepare('SELECT id,provider,stage FROM payment_attempts
            WHERE pay_row_id=? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('i',$id);$stmt->execute();
        $attempt=$stmt->get_result()->fetch_assoc();$stmt->close();
        if (!$attempt || $attempt['provider']!=='tron_wallet' ||
            $attempt['stage']!=='pending'){$connection->rollback();return false;}
        $stmt=$connection->prepare('SELECT id FROM pays WHERE payid=? LIMIT 1');
        $stmt->bind_param('s',$txId);$stmt->execute();
        $duplicate=$stmt->get_result()->fetch_assoc();$stmt->close();
        if($duplicate){$connection->rollback();return false;}
        $stmt=$connection->prepare("UPDATE pays SET payid=?,state='0'
            WHERE id=? AND state='pending' AND (payid IS NULL OR payid='')");
        $stmt->bind_param('si',$txId,$id);$stmt->execute();
        $changed=$stmt->affected_rows;$stmt->close();
        if($changed!==1){$connection->rollback();return false;}
        $attemptId=(int)$attempt['id'];
        $stmt=$connection->prepare("UPDATE payment_attempts
            SET provider_reference=?,stage='review' WHERE id=? AND provider='tron_wallet' AND stage='pending'");
        $stmt->bind_param('si',$txId,$attemptId);$stmt->execute();
        $changed=$stmt->affected_rows;$stmt->close();
        if($changed!==1 || !$connection->commit())throw new RuntimeException('tron_submit_commit_failed');
        return true;
    }catch(Throwable $error){
        try{$connection->rollback();}catch(Throwable $ignored){}
        return false;
    }
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
        'renewal' => $type === 'RENEW_ACCOUNT',
        'day' => preg_match('/^INCREASE_DAY_[0-9]+_[0-9]+$/D', $type) === 1,
        'volume' => preg_match('/^INCREASE_VOLUME_[0-9]+_[0-9]+$/D', $type) === 1,
        default => false,
    };
    if (!$subjectMatches) return false;
    try {
        $id = (int)$pay['id'];
        $stmt = $connection->prepare('SELECT provider,stage,checkout_url
            FROM payment_attempts WHERE pay_row_id=? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $attempt = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($attempt === null) {
            // Legacy, operator-delivered manual receipt only. Human verification
            // remains required; missing evidence does not become verified funds.
            return true;
        }
        $method = (string)$attempt['provider'];
        if (!in_array($method, ['bank_transfer','gift_card','manual_usdt'], true) ||
            (string)$attempt['stage'] !== 'review') return false;
        $intent = achbotManualIntentDecode((string)($attempt['checkout_url'] ?? ''), $method);
        return is_array($intent) && !empty($intent['receipt_file_id']);
    } catch (Throwable $error) {
        return false;
    }
}

function achbotManualClaim(mysqli $connection, array $payInfo, string $method, int $userId): bool {
    if (!in_array($method, ['bank_transfer', 'gift_card', 'manual_usdt'], true) ||
        (int)$payInfo['user_id'] !== $userId) return false;
    try {
        $connection->begin_transaction();
        $id = (int)$payInfo['id'];
        $stmt = $connection->prepare('SELECT id,user_id,hash_id,price,state,payid FROM pays WHERE id=? FOR UPDATE');
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
        $stmt = $connection->prepare('SELECT provider,stage FROM payment_attempts WHERE pay_row_id=? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($existing !== null) {
            $connection->commit();
            return hash_equals($method, (string)$existing['provider']) && $existing['stage'] === 'pending';
        }
        if (!achbotManualEnabled($method)) {
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
        } elseif ($method === 'manual_usdt') {
            $config = achbotManualUsdtConfiguration();
            if ($config === null) {
                $connection->rollback();
                return false;
            }
            $rate = $config['rate'];
            $agentStmt = $connection->prepare('SELECT is_agent FROM users WHERE userid=? LIMIT 1');
            $agentStmt->bind_param('i', $userId);
            $agentStmt->execute();
            $agent = $agentStmt->get_result()->fetch_assoc();
            $agentStmt->close();
            if ((int)($agent['is_agent'] ?? 0) === 1) $rate = $config['agent_rate'];
            if (!is_finite($rate) || $rate <= 0) {
                $connection->rollback();
                return false;
            }
            $quote = number_format(ceil(($price / $rate) * 100) / 100, 2, '.', '');
            $settlementNetwork = $config['network'];
            $intent['network'] = $config['network'];
            $intent['wallet'] = $config['wallet'];
            $currency = 'USDT';
        }
        $destination = achbotManualIntentEncode($intent);
        if ($destination === null) { $connection->rollback(); return false; }
        $stmt = $connection->prepare("INSERT INTO payment_attempts
            (pay_row_id,provider,order_hash,quote_amount,quote_currency,local_amount_toman,
             settlement_currency,checkout_url,stage)
            VALUES (?,?,?,?,?,?,?,?,'pending')");
        $stmt->bind_param('issssiss', $id, $method, $hash, $quote, $currency, $price,
            $settlementNetwork, $destination);
        $stmt->execute();
        $stmt->close();
        $connection->commit();
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
        !in_array($method, ['bank_transfer','gift_card','manual_usdt'], true)) return false;
    try {
        $stmt = $connection->prepare('SELECT provider,stage FROM payment_attempts WHERE pay_row_id=? LIMIT 1');
        $id = (int)$payInfo['id'];
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $attempt = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $attempt !== null &&
            (string)$attempt['provider'] === $method &&
            in_array((string)$attempt['stage'], ['pending','receiving'], true);
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
        !in_array($method, ['bank_transfer','gift_card','manual_usdt'], true)) return null;
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
        $stmt = $connection->prepare('SELECT id,provider,stage,checkout_url FROM payment_attempts
            WHERE pay_row_id=? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $attempt = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$attempt || (string)$attempt['provider'] !== $method ||
            !in_array((string)$attempt['stage'], ['pending','receiving'], true)) {
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
            ['bank_transfer','gift_card','manual_usdt'], true) ||
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
    $header = '🧾 روش پرداخت: <b>' . $label . "</b>\n";
    if ($method === 'manual_usdt' || $method === 'bank_transfer') {
        try {
            $stmt = $connection->prepare('SELECT provider,quote_amount,quote_currency,
                checkout_url FROM payment_attempts WHERE pay_row_id=? LIMIT 1');
            $id = (int)$payInfo['id'];
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $details = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $intent = $details ? achbotManualIntentDecode((string)$details['checkout_url'], $method) : null;
            if ($intent && $method === 'manual_usdt') {
                $network = (string)($intent['network'] ?? '');
                $address = (string)($intent['wallet'] ?? '');
                if ($details['quote_currency'] === 'USDT' &&
                    achbotManualUsdtWalletValid($address, $network)) {
                    $header .= '💎 مبلغ تتر: <code>' .
                        htmlspecialchars((string)$details['quote_amount'], ENT_QUOTES, 'UTF-8') .
                        ' USDT</code> | شبکه: <b>' . htmlspecialchars($network, ENT_QUOTES, 'UTF-8') .
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
        default => 'please_send_receipt_image'
    };
    return $mainValues[$key];
}

function achbotManualAcknowledgement(array $payInfo, string $method): string {
    return "📥 تصویر " . achbotManualLabel($method) . " دریافت شد.\n" .
        "🧾 سفارش: <code>" . htmlspecialchars((string)$payInfo['hash_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</code>\n" .
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
    if (!in_array($method, ['bank_transfer','gift_card','manual_usdt'], true) ||
        $hashId === '' || !($connection instanceof mysqli)) return '';
    try {
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
        if ($method === 'manual_usdt') {
            $network = (string)($intent['network'] ?? '');
            $wallet = (string)($intent['wallet'] ?? '');
            if ((string)$attempt['quote_currency'] !== 'USDT' ||
                !achbotManualUsdtWalletValid($wallet, $network) ||
                !preg_match('/^[0-9]{1,15}\.[0-9]{2,8}$/D', (string)$attempt['quote_amount'])) return '';
            return strtr((string)($mainValues['brw_cart_to_cart_usdt'] ?? ''), [
                'PRICE-TOMAN-ROUND' => number_format($priceToman),
                'PRICE-USDT' => number_format((float)$attempt['quote_amount'], 2, '.', ''),
                'USDT-NETWORK' => htmlspecialchars($network, ENT_QUOTES, 'UTF-8'),
                'USDT-WALLET' => htmlspecialchars($wallet, ENT_QUOTES, 'UTF-8'),
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
        return strtr($template, [
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

    $isSpecial = ($type === 'BUY_SUB' || $type === 'INCREASE_WALLET');

    $windowApproved = $isSpecial ? 600  : 3600;
    $windowHaveSent = $isSpecial ? 21600 : 21600;
    $windowDeclined = 180;

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

    return sendMessage($text, $keyboard, $parseMode);
}

