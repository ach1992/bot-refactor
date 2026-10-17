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

function achbotManualEnabled(string $method): bool {
    global $botState;
    if ($method === 'gift_card') return ($botState['giftCardState'] ?? 'off') === 'on';
    if ($method === 'manual_usdt') {
        return ($botState['manualUsdtState'] ?? 'off') === 'on' &&
            achbotManualUsdtConfiguration() !== null;
    }
    return $method === 'bank_transfer' && ($botState['cartToCartState'] ?? 'off') === 'on';
}

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

/** Claim an invoice exactly once, so bank, gift, and hosted methods cannot race. */
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
        $destination = null;
        if ($method === 'bank_transfer') {
            // Match the exact non-round amount to the durable invoice claim.
            $quote = (string)generateHigherRandomizedAmount($price)['toman'];
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
            // Immutable destination intent URI, not a customer-facing hosted checkout URL.
            // Only manual_usdt code may read this snapshot.
            $settlementNetwork = $config['network'];
            $destination = 'usdt:' . $settlementNetwork . ':' . $config['wallet'];
            $currency = 'USDT';
        }
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
        return false; // Idempotency conflict, missing shared ledger or DB failure.
    }
}

/** A receipt callback cannot change methods or revive a completed payment. */
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
            (string)$attempt['provider'] === $method && (string)$attempt['stage'] === 'pending';
    } catch (Throwable $error) {
        return false;
    }
}

/** Claim a Telegram photo once before any admin delivery or monetary effect. */
function achbotManualBeginEvidence(mysqli $connection, array $payInfo, string $method, int $userId): bool {
    if (!achbotManualReceiptAllowed($connection, $payInfo, $method, $userId)) return false;
    try {
        $stmt = $connection->prepare("UPDATE payment_attempts a
            JOIN pays p ON p.id=a.pay_row_id
            SET a.stage='receiving'
            WHERE p.id=? AND p.user_id=? AND p.state='pending'
              AND a.provider=? AND a.stage='pending'");
        $id = (int)$payInfo['id'];
        $stmt->bind_param('iis', $id, $userId, $method);
        $stmt->execute();
        $ok = $stmt->affected_rows === 1;
        $stmt->close();
        return $ok;
    } catch (Throwable $error) {
        return false;
    }
}

function achbotManualMarkEvidence(mysqli $connection, string $hashId): void {
    $stmt = $connection->prepare("UPDATE payment_attempts a
        JOIN pays p ON p.id=a.pay_row_id
        SET a.stage='review'
        WHERE p.hash_id=? AND p.state='have_sent'
          AND a.provider IN ('bank_transfer','gift_card','manual_usdt') AND a.stage='receiving'");
    $stmt->bind_param('s', $hashId);
    $stmt->execute();
    $stmt->close();
}

function achbotManualCaption(mysqli $connection, array $payInfo, string $caption): string {
    $method = achbotManualSelectedMethod($connection, $payInfo);
    $label = achbotManualLabel($method ?? 'bank_transfer');
    $header = '🧾 روش پرداخت: <b>' . $label . "</b>\n";
    if ($method === 'manual_usdt' || $method === 'bank_transfer') {
        try {
            $stmt = $connection->prepare('SELECT quote_amount,quote_currency,settlement_currency,
                checkout_url FROM payment_attempts WHERE pay_row_id=? LIMIT 1');
            $id = (int)$payInfo['id'];
            $stmt->bind_param('i',$id);
            $stmt->execute();
            $details = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($details && $method === 'manual_usdt') {
                $network = (string)$details['settlement_currency'];
                $prefix = 'usdt:' . $network . ':';
                $value = (string)$details['checkout_url'];
                $address = str_starts_with($value,$prefix) ? substr($value,strlen($prefix)) : '';
                if ($details['quote_currency'] === 'USDT' &&
                    achbotManualUsdtWalletValid($address,$network)) {
                    $header .= '💎 مبلغ تتر: <code>' .
                        htmlspecialchars((string)$details['quote_amount'],ENT_QUOTES,'UTF-8') .
                        ' USDT</code> | شبکه: <b>' . htmlspecialchars($network,ENT_QUOTES,'UTF-8') .
                        "</b>\nآدرس: <code>" . htmlspecialchars($address,ENT_QUOTES,'UTF-8') .
                        "</code>\n";
                }
            } elseif ($details && $details['quote_currency'] === 'toman') {
                $header .= '💳 مبلغ دقیق حواله: <code>' .
                    number_format((int)$details['quote_amount']) . " تومان</code>\n";
            }
        } catch (Throwable $error) {
            // Keep the operator receipt and its method identity visible.
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

/** Render the chosen and persisted fiat or crypto quote and destination. */
function buildCartToCartInfoMessage(array $paymentKeys, array $mainValues, int $priceToman,
                                    int $userId = 0, string $method = 'bank_transfer',
                                    string $hashId = ''): string {
    global $connection;
    if (!in_array($method, ['bank_transfer','gift_card','manual_usdt'], true) ||
        $hashId === '' || !($connection instanceof mysqli)) return '';

    $stmt = $connection->prepare("SELECT provider,quote_amount,quote_currency,
        local_amount_toman,settlement_currency,checkout_url
        FROM payment_attempts WHERE order_hash=? AND provider=? LIMIT 1");
    if (!$stmt) return '';
    $stmt->bind_param('ss', $hashId, $method);
    $stmt->execute();
    $attempt = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$attempt || (int)$attempt['local_amount_toman'] !== $priceToman) return '';

    if ($method === 'manual_usdt') {
        $network = (string)$attempt['settlement_currency'];
        $walletIntent = (string)$attempt['checkout_url'];
        $prefix = 'usdt:' . $network . ':';
        $wallet = str_starts_with($walletIntent, $prefix) ? substr($walletIntent,strlen($prefix)) : '';
        if ((string)$attempt['quote_currency'] !== 'USDT' ||
            !achbotManualUsdtWalletValid($wallet, $network) ||
            !preg_match('/^[0-9]{1,15}\.[0-9]{2,8}$/D', (string)$attempt['quote_amount'])) {
            return '';
        }
        return strtr((string)($mainValues['brw_cart_to_cart_usdt'] ?? ''), [
            'PRICE-TOMAN-ROUND' => number_format($priceToman),
            'PRICE-USDT' => number_format((float)$attempt['quote_amount'], 2, '.', ''),
            'USDT-NETWORK' => htmlspecialchars($network, ENT_QUOTES, 'UTF-8'),
            'USDT-WALLET' => htmlspecialchars($wallet, ENT_QUOTES, 'UTF-8'),
        ]);
    }

    if ((string)$attempt['quote_currency'] !== 'toman') return '';
    $bankAmount = (int)$attempt['quote_amount'];
    if ($bankAmount <= 0) return '';
    $key = $method === 'gift_card' ? 'brw_cart_to_cart_gift' : 'brw_cart_to_cart_detail';
    $template = (string)($mainValues[$key] ?? '');
    return strtr($template, [
        'PRICE-TOMAN-ROUND' => number_format($priceToman),
        'PRICE-TOMAN' => number_format($bankAmount),
        'PRICE-RIAL' => number_format($bankAmount * 10),
        'ACCOUNT-NUMBER' => htmlspecialchars((string)($paymentKeys['bankAccount'] ?? '---'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        'HOLDER-NAME' => htmlspecialchars((string)($paymentKeys['holderName'] ?? '---'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
    ]);
}

function kbSendReceiptAndCancel(string $sendPrefix, string $cancelPrefix, string $hashId, string $method = 'bank_transfer'): string {
    global $paymentKeys;
    require_once dirname(__DIR__, 2) . '/src/Telegram/Markup.php';
    $url = $paymentKeys['giftCardUrl'] ?? '';
    if (!is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false ||
        strtolower((string)(parse_url($url, PHP_URL_SCHEME) ?: '')) !== 'https') $url = null;
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

function sendReceiptToAdminsAndUpdatePay(
    mysqli $connection,
    string $fileid,
    string $caption,
    string $adminKeyboard,
    int $adminChatId,
    string $hashId
): void {
    $res = sendPhoto($fileid, $caption, $adminKeyboard, "HTML", $adminChatId);

    sendToAllAdmins('sendPhoto', [
        'photo' => $fileid,
        'caption' => $caption,
        'reply_markup' => $adminKeyboard,
        'parse_mode' => "HTML"
    ], $hashId);

    $msgId = $res->result->message_id ?? null;
    if ($msgId) {
        $stmt = $connection->prepare("UPDATE `pays` SET `state`='have_sent', `message_id`=?, `chat_id`=? WHERE `hash_id`=?");
        $stmt->bind_param("iis", $msgId, $adminChatId, $hashId);
        $stmt->execute();
        $stmt->close();
        achbotManualMarkEvidence($connection, $hashId);
    }
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

