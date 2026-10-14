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

        $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` + ? WHERE `userid` = ?");
        $stmt->bind_param("ii", $amount, $userId);
        $stmt->execute();
        $stmt->close();
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
        addToWallet($connection, $userId, $amount);

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

function fetchCartToCartUserEligibility(mysqli $connection, int $userId, int $cutoffTs): array {
    $default = [
        'exists' => false,
        'join_date' => 0,
        'is_agent' => 0,
        'approved_total' => 0,
        'approved_before_cutoff' => 0,
    ];

    if ($userId <= 0) {
        return $default;
    }

    $stmt = $connection->prepare("SELECT `date`, `is_agent` FROM `users` WHERE `userid` = ? LIMIT 1");
    if (!$stmt) {
        return $default;
    }

    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $userRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$userRow) {
        return $default;
    }

    $stats = [
        'exists' => true,
        'join_date' => (int)($userRow['date'] ?? 0),
        'is_agent' => (int)($userRow['is_agent'] ?? 0),
        'approved_total' => 0,
        'approved_before_cutoff' => 0,
    ];

    $stmt = $connection->prepare(
        "SELECT
            COUNT(*) AS approved_total,
            COALESCE(SUM(CASE WHEN `request_date` < ? THEN 1 ELSE 0 END), 0) AS approved_before_cutoff
         FROM `pays`
         WHERE `user_id` = ?
           AND `type` IN ('BUY_SUB', 'RENEW_ACCOUNT')
           AND `state` = 'approved'"
    );

    if (!$stmt) {
        return $stats;
    }

    $stmt->bind_param("ii", $cutoffTs, $userId);
    $stmt->execute();
    $payStats = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $stats['approved_total'] = (int)($payStats['approved_total'] ?? 0);
    $stats['approved_before_cutoff'] = (int)($payStats['approved_before_cutoff'] ?? 0);

    return $stats;
}

function resolveCartToCartMessageType(int $priceToman, int $userId = 0): string {
    global $connection;

    $detailMinPrice = 50000;
    $usdtMinPrice = 12000000;
    $cutoffTs = strtotime('2026-03-01 00:00:00');
    if ($cutoffTs === false) {
        $cutoffTs = 1772323200;
    }

    if ($priceToman >= $usdtMinPrice) {
        return 'usdt';
    }

    if ($priceToman < $detailMinPrice || $userId <= 0 || !($connection instanceof mysqli)) {
        return 'gift';
    }

    $stats = fetchCartToCartUserEligibility($connection, $userId, (int)$cutoffTs);
    $joinDate = (int)$stats['join_date'];
    $joinedBeforeCutoff = ($joinDate > 0 && $joinDate < (int)$cutoffTs);
    $joinedAtLeastTenDaysAgo = ($joinDate > 0 && $joinDate <= (time() - (10 * 86400)));

    if ((int)$stats['is_agent'] === 1) {
        return 'detail';
    }

    if ($joinedBeforeCutoff && (int)$stats['approved_before_cutoff'] >= 1) {
        return 'detail';
    }

    if ($joinedBeforeCutoff && (int)$stats['approved_total'] >= 2) {
        return 'detail';
    }

    if ($joinedAtLeastTenDaysAgo && (int)$stats['approved_total'] >= 3) {
        return 'detail';
    }

    return 'gift';
}

function getCartToCartMessageKey(string $messageType): string {
    require_once dirname(__DIR__, 2) . '/src/Presentation/Labels.php';
    return \AchBot\Presentation\Labels\cardToCardMessageKey($messageType);
}

function buildCartToCartInfoMessage(array $paymentKeys, array $mainValues, int $priceToman, int $userId = 0): string {
    global $connection, $USDTPrice, $USDTPriceagent;

    $messageType = resolveCartToCartMessageType($priceToman, $userId);
    $GLOBALS['lastCartToCartMessageType'] = $messageType;

    $randomPrice = generateHigherRandomizedAmount($priceToman);
    $finalTomanAmount = (float)$randomPrice['toman'];

    $isAgent = 0;
    if ($userId > 0 && ($connection instanceof mysqli)) {
        $stmt = $connection->prepare("SELECT `is_agent` FROM `users` WHERE `userid` = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $userRow = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $isAgent = (int)($userRow['is_agent'] ?? 0);
        }
    }

    if ($isAgent === 1 && isset($USDTPriceagent) && trim($USDTPriceagent) !== '' && is_numeric($USDTPriceagent)) {
        $usdtPrice = (float)$USDTPriceagent;
    } elseif (isset($USDTPrice) && trim($USDTPrice) !== '' && is_numeric($USDTPrice)) {
        $usdtPrice = (float)$USDTPrice;
    } else {
        $usdtPrice = 0;
    }

    $usdtAmount = $usdtPrice > 0 ? round($finalTomanAmount / $usdtPrice, 2) : 0;
    $messageKey = getCartToCartMessageKey($messageType);
    $messageTemplate = $mainValues[$messageKey] ?? '';
    if ($messageTemplate === '' && $messageType === 'detail') {
        $messageTemplate = $mainValues['brw_cart_to_cart_detail'] ?? '';
    }

    return strtr($messageTemplate, [
        "PRICE-TOMAN-ROUND" => number_format($priceToman),
        "ACCOUNT-NUMBER" => $paymentKeys['bankAccount'] ?? '---',
        "HOLDER-NAME" => $paymentKeys['holderName'] ?? '---',
        "PRICE-TOMAN" => number_format($randomPrice['toman']),
        "PRICE-RIAL" => number_format($randomPrice['rial']),
        "PRICE-USDT" => number_format($usdtAmount, 2, '.', ''),
        "USDT-WALLET" => $paymentKeys['tronwallet'] ?? '---',
    ]);
}

function kbSendReceiptAndCancel(string $sendPrefix, string $cancelPrefix, string $hashId): string {
    require_once dirname(__DIR__, 2) . '/src/Telegram/Markup.php';
    return \AchBot\Telegram\Markup\receiptAndCancel($sendPrefix, $cancelPrefix, $hashId, $GLOBALS['lastCartToCartMessageType'] ?? 'detail');
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
    }
}

function sendWithStartButton($text, $buttonText, $botUsername, $startPayload = 'mainMenu', $parseMode = 'HTML')
{
    $keyboard = json_encode([
        'inline_keyboard' => [
            [[
                'text' => $buttonText,
                'url'  => "https://t.me/{$botUsername}?start={$startPayload}"
            ]]
        ]
    ], JSON_UNESCAPED_UNICODE);

    return sendMessage($text, $keyboard, $parseMode);
}

