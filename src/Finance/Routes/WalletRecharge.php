<?php
/** AChBot V8: source-pinned financial compatibility module. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

// Parse the complete Telegram amount before integer conversion. PHP's (int)
// and is_numeric() can silently accept prefixes, fractions and exponents.
// Never truncate a malformed input into an amount that can replace old drafts.
if (!function_exists('achbotParseWalletTopupRawInteger')) {
    function achbotParseWalletTopupRawInteger($raw): ?int {
        if (!is_string($raw) || preg_match('/\\A[0-9]+\\z/', $raw) !== 1) {
            return null;
        }
        $digits = ltrim($raw, '0');
        if ($digits === '') {
            return 0;
        }
        $max = (string) PHP_INT_MAX;
        if (strlen($digits) > strlen($max) ||
            (strlen($digits) === strlen($max) && strcmp($digits, $max) > 0)) {
            return null;
        }
        return (int) $digits;
    }
}

// All wallet top-up buttons must originate in the customer's own bot DM;
// a callback is not newly authored customer text. In UpdateBindings.php,
// callbacks bind $text to the earlier bot message referenced by the button.
if (!function_exists('achbotWalletTopupPrivateCallback')) {
    function achbotWalletTopupPrivateCallback(
        $update, $actorId, $chatId, $messageId, $boundData
    ): bool {
        if (!is_object($update) || !isset($update->callback_query) ||
            !is_object($update->callback_query) || isset($update->message) ||
            !is_int($update->update_id ?? null) || $update->update_id <= 0) {
            return false;
        }
        $callback = $update->callback_query;
        $sender = $callback->from ?? null;
        $message = $callback->message ?? null;
        $chat = is_object($message) ? ($message->chat ?? null) : null;
        return is_object($sender) && is_object($chat) &&
            is_int($sender->id ?? null) && $sender->id > 0 &&
            ($sender->is_bot ?? false) !== true &&
            is_int($chat->id ?? null) && ($chat->type ?? null) === 'private' &&
            $chat->id === $sender->id &&
            $chat->id === (int)$actorId && $chat->id === (int)$chatId &&
            is_int($message->message_id ?? null) && $message->message_id > 0 &&
            $message->message_id === (int)$messageId &&
            is_string($callback->data ?? null) && $callback->data === $boundData;
    }
}

// A custom amount can only be submitted as a fresh customer-authored
// Telegram message, never from an unrelated group or callback bot text.
if (!function_exists('achbotWalletTopupPrivateText')) {
    function achbotWalletTopupPrivateText(
        $update, $actorId, $chatId, $messageId, $boundText, $storedUserId
    ): bool {
        if (!is_object($update)) {
            return false;
        }
        $keys = array_keys(get_object_vars($update));
        sort($keys);
        if ($keys !== ['message', 'update_id'] ||
            !is_object($update->message) ||
            !is_int($update->update_id) || $update->update_id <= 0) {
            return false;
        }
        $message = $update->message;
        $sender = $message->from ?? null;
        $chat = $message->chat ?? null;
        return is_object($sender) && is_object($chat) &&
            is_int($sender->id ?? null) && $sender->id > 0 &&
            ($sender->is_bot ?? false) !== true &&
            is_int($chat->id ?? null) && ($chat->type ?? null) === 'private' &&
            $chat->id === $sender->id &&
            $chat->id === (int)$actorId && $chat->id === (int)$chatId &&
            (string)$storedUserId === (string)$sender->id &&
            is_int($message->message_id ?? null) && $message->message_id > 0 &&
            $message->message_id === (int)$messageId &&
            is_string($message->text ?? null) &&
            is_string($boundText) && $boundText === $message->text;
    }
}

if (is_string($data ?? null) &&
    ($data === 'increaseMyWallet' || $data === 'increaseWalletCustom' ||
     strpos($data, 'increaseWallet_') === 0) &&
    !achbotWalletTopupPrivateCallback($update ?? null, $from_id ?? null,
                                     $chat_id ?? null, $message_id ?? null, $data)) {
    // Fail closed without editing a different conversation or consuming a step.
    return;
}

/** Retire an old inline prompt best-effort, never after-the-fact roll back SQL. */
if (!function_exists('achbotWalletTopupRetirePrompt')) {
    function achbotWalletTopupRetirePrompt(): void {
        try {
            delMessage();
        } catch (Throwable $ignored) {
            error_log('achbot_wallet_topup_old_prompt_cleanup_deferred');
        }
    }
}

/** Use the confirmed sent message ID as the actual reply/selection boundary. */
if (!function_exists('achbotWalletTopupSentMessageId')) {
    function achbotWalletTopupSentMessageId($reply): ?int {
        $id = is_object($reply) ? ($reply->result->message_id ?? null) : null;
        return is_object($reply) && ($reply->ok ?? false) === true &&
            is_int($id) && $id > 0 ? $id : null;
    }
}

if (!function_exists('achbotWalletTopupAmountProblem')) {
    function achbotWalletTopupAmountProblem($amount): ?string {
        if ($amount === null) return '⚠️ مبلغ شارژ معتبر نیست. لطفاً فقط عدد صحیح با ارقام انگلیسی وارد کنید.';
        if ($amount < 100000) return '⚠️ لطفا مقداری بیشتر یا مساوی 100,000 تومان وارد کنید.';
        if ($amount > 10000000) return '⚠️ حداکثر مبلغ قابل شارژ کیف پول 10,000,000 تومان می‌باشد.';
        if ($amount % 50000 !== 0) return '⚠️ مبلغ واردشده باید مضربی از 50,000 تومان باشد.';
        return null;
    }
}

// A fresh, private menu callback reserves a unique update identity BEFORE
// sending choices. Only the sent bot prompt's message_id authorizes buttons.
if ($data === 'increaseMyWallet') {
    $updateId = $update->update_id;
    $opening = achbotWalletTopupStage('opening', $updateId, $message_id);
    $result = achbotWalletTopupAdvance($connection, (int)$from_id, null,
                                      $opening, $updateId);
    if ($result !== 'applied') return;

    $textMsg = "✅ با شارژ کیف پول می‌توانید هنگام خرید از <b>دکمه پرداخت با موجودی</b> استفاده کنید و منتظر تأیید ادمین <b>نمانید</b>.\n\n❇️ لطفاً مبلغ شارژ کیف پول را انتخاب کنید:";
    $keyboard = [
        [
            ['text' => '100,000 تومان', 'callback_data' => 'increaseWallet_100000'],
            ['text' => '200,000 تومان', 'callback_data' => 'increaseWallet_200000'],
        ],
        [
            ['text' => '300,000 تومان', 'callback_data' => 'increaseWallet_300000'],
            ['text' => '500,000 تومان', 'callback_data' => 'increaseWallet_500000'],
        ],
        [
            ['text' => '1,000,000 تومان', 'callback_data' => 'increaseWallet_1000000'],
            ['text' => '2,000,000 تومان', 'callback_data' => 'increaseWallet_2000000'],
        ],
        [
            ['text' => '5,000,000 تومان', 'callback_data' => 'increaseWallet_5000000'],
        ],
        [
            ['text' => '✏️ مبلغ دلخواه', 'callback_data' => 'increaseWalletCustom'],
        ],
        [
            ['text' => $buttonValues['cancel'], 'callback_data' => 'mainMenu'],
        ],
    ];
    $keys = json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE);
    try {
        $reply = sendMessage($textMsg, $keys, 'HTML');
    } catch (Throwable $failure) {
        $reply = null;
    }
    $sentId = achbotWalletTopupSentMessageId($reply);
    if ($sentId === null) {
        error_log('achbot_wallet_topup_menu_delivery_unconfirmed');
        return; // A fresh menu invocation is the safe recovery action.
    }
    $ready = achbotWalletTopupStage('choose', $updateId, $sentId);
    if (achbotWalletTopupAdvance($connection, (int)$from_id, $opening,
                                $ready, $updateId) !== 'applied') {
        error_log('achbot_wallet_topup_menu_stage_unconfirmed');
        return;
    }
    achbotWalletTopupRetirePrompt();
    return;
}

// Both fixed and custom selection must come from the CURRENT bot prompt,
// not a historic private inline button with the same callback_data.
$selectStep = (string)($userInfo['step'] ?? 'none');
$canSelect = preg_match('/\AachbotWalletTopup:choose:([1-9][0-9]*):([1-9][0-9]*):([1-9][0-9]*)\z/D',
                        $selectStep, $choice) === 1 &&
    $message_id === (int)$choice[2];

if (strpos($data, 'increaseWallet_') === 0) {
    $amount = achbotParseWalletTopupRawInteger(substr($data, strlen('increaseWallet_')));
    $problem = achbotWalletTopupAmountProblem($amount);
    if ($problem !== null) {
        sendMessage($problem);
        return;
    }
    if (!$canSelect) {
        sendMessage('⚠️ این گزینه مربوط به درخواست فعلی نیست. شارژ کیف پول را دوباره از منو آغاز کنید.');
        return;
    }
    $result = startWalletPayment($connection, $botState, $buttonValues, $botUrl,
        $from_id, $removeKeyboard, $amount, $selectStep,
        $update->update_id, $message_id);
    if ($result === 'created') {
        achbotWalletTopupRetirePrompt();
    } elseif ($result === 'review') {
        sendMessage('⚠️ وضعیت درخواست شارژ نیازمند بررسی است؛ قبل از تلاش مجدد، وضعیت را بررسی کنید.');
    } else {
        sendMessage('⚠️ این درخواست قبلاً پردازش یا جایگزین شده است. شارژ کیف پول را دوباره از منو آغاز کنید.');
    }
    return;
}

if ($data === 'increaseWalletCustom') {
    if (!$canSelect) {
        sendMessage('⚠️ این گزینه مربوط به درخواست فعلی نیست. شارژ کیف پول را دوباره از منو آغاز کنید.');
        return;
    }
    $updateId = $update->update_id;
    $prompting = achbotWalletTopupStage('prompting', $updateId, $message_id);
    if (achbotWalletTopupAdvance($connection, (int)$from_id, $selectStep,
                                $prompting, $updateId) !== 'applied') {
        return;
    }
    try {
        $reply = sendMessage("💰 لطفاً مبلغ شارژ را وارد کنید.\n✅ حداقل مبلغ قابل شارژ <b>100,000 تومان</b> می باشد.\n✅ حداکثر مبلغ قابل شارژ <b>10,000,000 تومان</b> می باشد.\n✅ مبلغ باید فقط مضربی از <b>50,000 تومان</b> باشد.\n🔢 لطفاً فقط اعداد انگلیسی وارد کنید.", $cancelKey, 'HTML');
    } catch (Throwable $failure) {
        $reply = null;
    }
    $sentId = achbotWalletTopupSentMessageId($reply);
    if ($sentId === null) {
        error_log('achbot_wallet_topup_amount_prompt_unconfirmed');
        return; // No unverified prompt may authorize a future amount message.
    }
    $await = achbotWalletTopupStage('amount', $updateId, $sentId);
    if (achbotWalletTopupAdvance($connection, (int)$from_id, $prompting,
                                $await, $updateId) !== 'applied') {
        error_log('achbot_wallet_topup_amount_stage_unconfirmed');
        return;
    }
    achbotWalletTopupRetirePrompt();
    return;
}

$amountStep = (string)($userInfo['step'] ?? 'none');
if ($text != ($buttonValues['cancel'] ?? null) &&
    preg_match('/\AachbotWalletTopup:amount:([1-9][0-9]*):([1-9][0-9]*):([1-9][0-9]*)\z/D',
               $amountStep, $pending) === 1 &&
    achbotWalletTopupPrivateText($update ?? null, $from_id ?? null,
        $chat_id ?? null, $message_id ?? null,
        $text, $userInfo['userid'] ?? null)) {
    // The message must postdate BOTH the custom callback and the delivered
    // bot prompt, even if Telegram hands webhook updates out of order.
    if ($message_id <= (int)$pending[2]) {
        return;
    }
    $amount = achbotParseWalletTopupRawInteger($text);
    $problem = achbotWalletTopupAmountProblem($amount);
    if ($problem !== null) {
        sendMessage($problem);
        return;
    }
    $result = startWalletPayment($connection, $botState, $buttonValues, $botUrl,
        $from_id, $removeKeyboard, $amount, $amountStep,
        $update->update_id, $message_id);
    if ($result === 'review') {
        sendMessage('⚠️ وضعیت درخواست شارژ نیازمند بررسی است؛ قبل از تلاش مجدد، وضعیت را بررسی کنید.');
    } elseif ($result !== 'created') {
        sendMessage('⚠️ این درخواست قبلاً پردازش یا جایگزین شده است. شارژ کیف پول را دوباره از منو آغاز کنید.');
    }
    return;
}

if (preg_match('/payIncreaseWalletWith(?:CartToCart|GiftCard|ManualUsdt)(?<hashId>.*)/', $data, $match)) {

    $payInfo = dbFetchPayByHash($connection, $match['hashId']);
    if (!$payInfo) {
        prepareStartFallback();
        delMessage();
        return '__ACHBOT_V8_GOTO_START__';
    }

    $method = strpos($data, 'WithManualUsdt') !== false ? (($GLOBALS['achbotManualCryptoChoice'] ?? '') === 'TRON' ? 'manual_trx' : 'manual_usdt') :
        (strpos($data, 'WithGiftCard') !== false ? 'gift_card' : 'bank_transfer');
    if (!achbotManualClaim($connection, $payInfo, $method, (int)$from_id,
                           $GLOBALS['achbotManualCryptoChoice'] ?? null)) {
        alert("⚠️ پرداخت این سفارش با این روش مجاز نیست یا قبلاً به روش دیگری آغاز شده است.", true);
        return;
    }

	$msg = buildCartToCartInfoMessage($paymentKeys, $mainValues, (int)$payInfo['price'], (int)$payInfo['user_id'], $method, (string)$payInfo['hash_id']);
    if ($msg === "") {
        alert("⚠️ اطلاعات این پرداخت قابل بازیابی نیست؛ با پشتیبانی تماس بگیرید.", true);
        return;
    }
    $keyboard = kbSendReceiptAndCancel(in_array($method, ['manual_usdt','manual_trx'], true) ? "sendIncreaseWalletUsdtReceipt" : ($method === 'gift_card' ? "sendIncreaseWalletGiftReceipt" : "sendIncreaseWalletReceipt"), "cancelIncreaseWalletReceipt", $match['hashId'], $method);

    $delivered = sendMessage($msg, $keyboard, "HTML");
    if (is_object($delivered) && !empty($delivered->ok)) {
    setUser("payIncreaseWalletWith" . (in_array($method, ['manual_usdt','manual_trx'], true) ? 'ManualUsdt' : ($method === 'gift_card' ? 'GiftCard' : 'CartToCart')) . $match['hashId']);
        delMessage();
    } else {
        // The pinned attempt may be reopened. Never erase the selection menu
        // if Telegram failed to deliver the replacement instructions.
        alert("⚠️ نمایش اطلاعات پرداخت تأیید نشد. از همان دکمه دوباره تلاش کنید و وجهی ارسال نکنید.", true);
    }
    exit;
}

if (preg_match('/send(?:IncreaseWallet|IncreaseWalletGift|IncreaseWalletUsdt)Receipt(?<hashId>.*)/', $data, $match)) {

    $payInfo = dbFetchPayByHash($connection, $match['hashId']);
    if (!$payInfo) {
        prepareStartFallback();
        delMessage();
        return '__ACHBOT_V8_GOTO_START__';
    }

    $method = strpos($data, 'UsdtReceipt') !== false ? achbotManualCryptoReceiptProviderByHash($connection, (string)$match['hashId']) :
        (strpos($data, 'GiftReceipt') !== false ? 'gift_card' : 'bank_transfer');
    if (!achbotManualReceiptAllowed($connection, $payInfo, $method, (int)$from_id)) {
        alert("⚠️ این رسید با روش پرداخت انتخاب‌شده مطابقت ندارد یا سفارش در انتظار پرداخت نیست.", true);
        return;
    }
    $guardMsg = duplicateReceiptGuard($connection, $payInfo, time());
    if ($guardMsg) { alert($guardMsg, true); exit; }

    delMessage();
    setUser((in_array($method, ['manual_usdt','manual_trx'], true) ? "waitIncreaseWalletUsdtReceipt" : ($method === 'gift_card' ? "waitIncreaseWalletGiftReceipt" : "waitIncreaseWalletReceipt")) . $match['hashId']);

    $keyboard = kbOnlyCancel("cancelIncreaseWalletReceipt", $match['hashId']);
    sendMessage(achbotManualReceiptPrompt($mainValues, $method), $keyboard, "HTML");
    exit;
}

if (preg_match('/cancelIncreaseWalletReceipt(?<hashId>.*)/', $data, $match)) {
    handleCancelFlow($mainValues, $removeKeyboard);
}

if (preg_match('/wait(?:IncreaseWallet|IncreaseWalletGift|IncreaseWalletUsdt)Receipt(.*)/', $userInfo['step'], $match)) {

    $method = strpos($userInfo['step'], 'UsdtReceipt') !== false ? achbotManualCryptoReceiptProviderByHash($connection, (string)$match[1]) :
        (strpos($userInfo['step'], 'GiftReceipt') !== false ? 'gift_card' : 'bank_transfer');
    $receiptPay = dbFetchPayByHash($connection, $match[1]);
    if (!$receiptPay || !achbotManualReceiptAllowed($connection, $receiptPay, $method, (int)$from_id)) {
        setUser();
        alert("⚠️ این سفارش قابل ثبت رسید نیست؛ برای بررسی با پشتیبانی تماس بگیرید.", true);
        return;
    }
    $fileid = extractPhotoFileId($update);
    if (!$fileid) {
        $keyboard = kbOnlyCancel("cancelIncreaseWalletReceipt", $match[1]);
        sendMessage($mainValues['please_send_only_image'], $keyboard, "HTML");
        exit;
    }

    $deliveryToken = achbotManualBeginEvidence($connection, $receiptPay, $method, (int)$from_id, $fileid);
    if ($deliveryToken === null) {
        alert("⚠️ ثبت یا ارسال رسید در جریان است. همان تصویر را دوباره ارسال کنید؛ در صورت تداوم مشکل با پشتیبانی تماس بگیرید.", true);
        return;
    }


    $uid = $userInfo['userid'];
    $name = $userInfo['name'];
    $username = $userInfo['username'];

    $payInfo = dbFetchPayByHash($connection, $match[1]);
    if (!$payInfo) {
        prepareStartFallback();
        delMessage();
        return '__ACHBOT_V8_GOTO_START__';
    }

	// $price = (int)$payInfo['price'];
	$rawPrice = (int)$payInfo['price'];
	$price = number_format($rawPrice);



    $msg = str_replace(
        ['PRICE', 'USERNAME', 'NAME', 'USER-ID'],
        [$price, $username, $name, $from_id],
        $mainValues['increase_wallet_request_message']
    );

    $adminKeyboard = buildAdminKeyboardWithStats(
        $connection,
        $buttonValues,
        "approvePayment{$match[1]}",
        "declineAction_{$match[1]}",
        $match[1],
        (int)$payInfo['user_id']
    );

    if (sendReceiptToAdminsAndUpdatePay($connection, $fileid,
        achbotManualCaption($connection, $receiptPay, $msg), $adminKeyboard, $admin, $match[1],
        $deliveryToken)) {
        setUser();
        sendWithStartButton(achbotManualAcknowledgement($receiptPay, $method), $buttonValues['start_bot'], $botUsername, 'mainMenu', 'HTML', (string)$receiptPay['hash_id']);
    } else {
        sendMessage("📥 تصویر برای پیگیری ثبت شده اما تأیید ارسال به مدیر دریافت نشده است. لطفاً همان تصویر را دوباره بفرستید یا با پشتیبانی تماس بگیرید. پرداخت مجدد انجام ندهید.");
    }
    exit;
}

