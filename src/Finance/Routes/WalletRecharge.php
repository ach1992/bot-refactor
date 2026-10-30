<?php
/** AChBot V8: source-pinned financial compatibility module. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../../messages/loader.php'; // ACHBOT_TEXT_CATALOG


require_once __DIR__ . '/../PaymentLifecycle.php';

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
        if ($amount === null) return \achbotMessage('wallet.wallet_recharge.wallet_topup_amount_problem.label.7fab54f38e');
        if ($amount < 100000) return \achbotMessage('wallet.wallet_recharge.wallet_topup_amount_problem.label.a5b8f4d84e');
        if ($amount > 10000000) return \achbotMessage('wallet.wallet_recharge.wallet_topup_amount_problem.label.2ee8d817fc');
        if ($amount % 50000 !== 0) return \achbotMessage('wallet.wallet_recharge.wallet_topup_amount_problem.label.9185f287a6');
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

    $textMsg = \achbotMessage('wallet.wallet_recharge.wallet_topup_amount_problem.text_msg.a8d7f77dea');
    $keyboard = [
        [
            ['text' => \achbotMessage('wallet.wallet_recharge.wallet_topup_amount_problem.button.e0736904e5'), 'callback_data' => 'increaseWallet_100000'],
            ['text' => \achbotMessage('wallet.wallet_recharge.wallet_topup_amount_problem.button.15bec8537a'), 'callback_data' => 'increaseWallet_200000'],
        ],
        [
            ['text' => \achbotMessage('wallet.wallet_recharge.wallet_topup_amount_problem.button.c18668d617'), 'callback_data' => 'increaseWallet_300000'],
            ['text' => \achbotMessage('wallet.wallet_recharge.wallet_topup_amount_problem.button.d960e787a3'), 'callback_data' => 'increaseWallet_500000'],
        ],
        [
            ['text' => \achbotMessage('wallet.wallet_recharge.wallet_topup_amount_problem.button.79fa13dc2b'), 'callback_data' => 'increaseWallet_1000000'],
            ['text' => \achbotMessage('wallet.wallet_recharge.wallet_topup_amount_problem.button.1f4dbe4fcb'), 'callback_data' => 'increaseWallet_2000000'],
        ],
        [
            ['text' => \achbotMessage('wallet.wallet_recharge.wallet_topup_amount_problem.button.f4f1019c8d'), 'callback_data' => 'increaseWallet_5000000'],
        ],
        [
            ['text' => \achbotMessage('wallet.wallet_recharge.wallet_topup_amount_problem.button.5e7e50f6fe'), 'callback_data' => 'increaseWalletCustom'],
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
        sendMessage(\achbotMessage('wallet.wallet_recharge.wallet_topup_amount_problem.message.778b5776b6'));
        return;
    }
    $result = startWalletPayment($connection, $botState, $buttonValues, $botUrl,
        $from_id, $removeKeyboard, $amount, $selectStep,
        $update->update_id, $message_id);
    if ($result === 'created') {
        achbotWalletTopupRetirePrompt();
    } elseif ($result === 'review') {
        sendMessage(\achbotMessage('wallet.wallet_recharge.wallet_topup_amount_problem.message.983d706c66'));
    } else {
        sendMessage(\achbotMessage('wallet.wallet_recharge.wallet_topup_amount_problem.message.d0531e17b9'));
    }
    return;
}

if ($data === 'increaseWalletCustom') {
    if (!$canSelect) {
        sendMessage(\achbotMessage('wallet.wallet_recharge.wallet_topup_amount_problem.message.778b5776b6'));
        return;
    }
    $updateId = $update->update_id;
    $prompting = achbotWalletTopupStage('prompting', $updateId, $message_id);
    if (achbotWalletTopupAdvance($connection, (int)$from_id, $selectStep,
                                $prompting, $updateId) !== 'applied') {
        return;
    }
    try {
        $reply = sendMessage(\achbotMessage('wallet.wallet_recharge.wallet_topup_amount_problem.message.ae69d93a89'), $cancelKey, 'HTML');
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
        sendMessage(\achbotMessage('wallet.wallet_recharge.wallet_topup_amount_problem.message.983d706c66'));
    } elseif ($result !== 'created') {
        sendMessage(\achbotMessage('wallet.wallet_recharge.wallet_topup_amount_problem.message.d0531e17b9'));
    }
    return;
}

if (is_string($data) && (strlen($data) <= 64 || ($GLOBALS['achbotManualCryptoVerifiedDispatch'] ?? null) === $data) && preg_match('/\ApayIncreaseWalletWith(?:CartToCart|GiftCard|ManualUsdt)(?<hashId>[A-Za-z0-9_-]{1,50})\z/D', $data, $match)) {

    $payInfo = dbFetchPayByHash($connection, $match['hashId']);
    if (!$payInfo) {
        prepareStartFallback();
        delMessage();
        return '__ACHBOT_V8_GOTO_START__';
    }

    $method = str_starts_with($data, 'payIncreaseWalletWithManualUsdt') ? (($GLOBALS['achbotManualCryptoChoice'] ?? '') === 'TRON' ? 'manual_trx' : 'manual_usdt') :
        (str_starts_with($data, 'payIncreaseWalletWithGiftCard') ? 'gift_card' : 'bank_transfer');
    if (!achbotManualClaim($connection, $payInfo, $method, (int)$from_id,
                           $GLOBALS['achbotManualCryptoChoice'] ?? null)) {
        alert(\achbotMessage('wallet.wallet_recharge.wallet_topup_amount_problem.alert.1a885aaca4'), true);
        return;
    }

	$msg = buildCartToCartInfoMessage($paymentKeys, $mainValues, (int)$payInfo['price'], (int)$payInfo['user_id'], $method, (string)$payInfo['hash_id']);
    if ($msg === "") {
        alert(\achbotMessage('wallet.wallet_recharge.wallet_topup_amount_problem.alert.07ee06b4bc'), true);
        return;
    }
    $keyboard = kbSendReceiptAndCancel(in_array($method, ['manual_usdt','manual_trx'], true) ? "sendIncreaseWalletUsdtReceipt" : ($method === 'gift_card' ? "sendIncreaseWalletGiftReceipt" : "sendIncreaseWalletReceipt"), "cancelIncreaseWalletReceipt", $match['hashId'], $method);

    $delivered = sendMessage($msg, $keyboard, "HTML");
    require_once __DIR__ . '/../PaymentLifecycle.php';
    achbotPayUxTrackByHash($connection,(string)$payInfo['hash_id'],(int)$from_id,$delivered);
    if (function_exists('achbotApiPaymentTrackInvoice')) achbotApiPaymentTrackInvoice($connection,(string)$payInfo['hash_id'],(int)$from_id,$delivered);
    if (is_object($delivered) && !empty($delivered->ok)) {
    setUser(function_exists('achbotApiPaymentInitialStep') ? achbotApiPaymentInitialStep($connection,(string)$match['hashId'],"payIncreaseWalletWith" . (in_array($method, ['manual_usdt','manual_trx'], true) ? 'ManualUsdt' : ($method === 'gift_card' ? 'GiftCard' : 'CartToCart')) . $match['hashId']) : "payIncreaseWalletWith" . (in_array($method, ['manual_usdt','manual_trx'], true) ? 'ManualUsdt' : ($method === 'gift_card' ? 'GiftCard' : 'CartToCart')) . $match['hashId']);
        delMessage();
    } else {
        // The pinned attempt may be reopened. Never erase the selection menu
        // if Telegram failed to deliver the replacement instructions.
        alert(\achbotMessage('wallet.wallet_recharge.wallet_topup_amount_problem.alert.7a9c30e938'), true);
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
        alert(\achbotMessage('wallet.wallet_recharge.wallet_topup_amount_problem.alert.d06bc731eb'), true);
        return;
    }
    $guardMsg = duplicateReceiptGuard($connection, $payInfo, time());
    if ($guardMsg) { alert($guardMsg, true); exit; }

    // Explicit user intent to send a receipt, not a claim of paid funds.
    // Serialize with the six-hour unstarted-invoice expiry sweep.
    require_once __DIR__ . '/../PaymentLifecycle.php';
    if (!achbotPayUxStartManualReceipt($connection,(int)$payInfo['id'],
                                      $method,(int)$from_id)) {
        alert(\achbotMessage('wallet.wallet_recharge.wallet_topup_amount_problem.alert.560f05f822'),true);
        return;
    }

    delMessage();
    setUser((in_array($method, ['manual_usdt','manual_trx'], true) ? "waitIncreaseWalletUsdtReceipt" : ($method === 'gift_card' ? "waitIncreaseWalletGiftReceipt" : "waitIncreaseWalletReceipt")) . $match['hashId']);

    $keyboard = kbOnlyCancel("cancelIncreaseWalletReceipt", $match['hashId']);
    $sent=sendMessage(achbotManualEvidencePrompt($mainValues,$connection,$payInfo,$method), $keyboard, "HTML");
    require_once __DIR__ . '/../PaymentLifecycle.php';
    achbotPayUxTrackByHash($connection,(string)$match['hashId'],(int)$from_id,$sent);
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
        alert(\achbotMessage('wallet.wallet_recharge.wallet_topup_amount_problem.alert.882596d0f1'), true);
        return;
    }
    $fileid = achbotManualEvidenceInput($update, $connection, $receiptPay, $method);
    if ($fileid === null) {
        $keyboard = kbOnlyCancel("cancelIncreaseWalletReceipt", $match[1]);
        sendMessage(achbotManualEvidencePrompt($mainValues,$connection,$receiptPay,$method,true), $keyboard, "HTML");
        exit;
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
        achbotManualCaption($connection, $receiptPay, $mainValues['increase_wallet_request_message'])
    );

    if(achbotManualTypedGift($connection,$receiptPay,$method) && !achbotManualGiftReviewFits($msg,$fileid)){
        sendMessage(achbotMessage('payment.api_payment.code_too_long'),null,'HTML');return;
    }
    $deliveryToken = achbotManualBeginEvidence($connection, $receiptPay, $method, (int)$from_id, $fileid);
    if ($deliveryToken === null) {
        alert(\achbotMessage('wallet.wallet_recharge.wallet_topup_amount_problem.alert.795c4bddb8'), true);
        return;
    }

    $adminKeyboard = buildAdminKeyboardWithStats(
        $connection,
        $buttonValues,
        "approvePayment{$match[1]}",
        "declineAction_{$match[1]}",
        $match[1],
        (int)$payInfo['user_id']
    );

    if (sendReceiptToAdminsAndUpdatePay($connection, $fileid,
        $msg, $adminKeyboard, $admin, $match[1],
        $deliveryToken)) {
        setUser();
        sendWithStartButton(achbotManualAcknowledgement($receiptPay, $method), $buttonValues['start_bot'], $botUsername, 'mainMenu', 'HTML', (string)$receiptPay['hash_id']);
    } else {
        sendMessage(\achbotMessage('wallet.wallet_recharge.wallet_topup_amount_problem.message.c346a0d62e'));
    }
    exit;
}

