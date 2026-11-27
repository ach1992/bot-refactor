<?php
/** AChBot current payment route and shared receipt presentation. */
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

require_once __DIR__.'/../WalletStart.php';

// A fresh, private menu callback reserves a unique update identity BEFORE
// sending choices. Only the sent bot prompt's message_id authorizes buttons.
if ($data === 'increaseMyWallet') {
    $updateId = $update->update_id;
    $recovered=achbotWalletTopupRecoverSelector($connection,(int)$from_id,(string)($userInfo['step']??''),$updateId,$botUrl);
    if ($recovered!==null) {
        if ($recovered==='delivery_pending' || $recovered==='review')
            alert(achbotMessage('wallet.wallet_start.selector_delivery_pending'),true);
        return;
    }
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
            ['text' => \achbotMessage('legacy_buttons.cancel'), 'callback_data' => 'mainMenu'],
        ],
    ];
    $keys = json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE);
    try {
        $reply = sendMessage($textMsg, $keys, 'HTML');
    } catch (Throwable $failure) {
        $reply = null;
    }
    $sentId = \AchBot\Telegram\Conversation\acknowledgedMessage($reply,(int)$from_id);
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
    // The shared entry can be on a retained warning or delivered result.
    // Only the subsequent exact choice/amount prompt is disposable.
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
        sendMessage($problem, null, 'HTML');
        return;
    }
    if (!$canSelect) {
        $recovered=achbotWalletTopupRecoverSelector($connection,(int)$from_id,$selectStep,$update->update_id,$botUrl,$message_id,$amount);
        if ($recovered==='delivery_pending' || $recovered==='review')
            alert(achbotMessage('wallet.wallet_start.selector_delivery_pending'),true);
        elseif ($recovered===null)
            alert(achbotMessage('wallet.wallet_recharge.wallet_topup_amount_problem.message.778b5776b6'),true);
        return;
    }
    $result = startWalletPayment($connection, $botUrl,
        $from_id, $amount, $selectStep,
        $update->update_id, $message_id);
    if ($result === 'delivery_pending') {
        alert(achbotMessage('wallet.wallet_start.selector_delivery_pending'),true);
    } elseif ($result === 'review') {
        sendMessage(\achbotMessage('wallet.wallet_recharge.wallet_topup_amount_problem.message.983d706c66'), null, 'HTML');
    } elseif ($result!=='created') {
        sendMessage(\achbotMessage('wallet.wallet_recharge.wallet_topup_amount_problem.message.d0531e17b9'), null, 'HTML');
    }
    return;
}

if ($data === 'increaseWalletCustom') {
    if (!$canSelect) {
        sendMessage(\achbotMessage('wallet.wallet_recharge.wallet_topup_amount_problem.message.778b5776b6'), null, 'HTML');
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
    $sentId = \AchBot\Telegram\Conversation\acknowledgedMessage($reply,(int)$from_id);
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
if ($text != (\achbotMessage('legacy_buttons.cancel') ?? null) &&
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
        sendMessage($problem, null, 'HTML');
        return;
    }
    $result = startWalletPayment($connection, $botUrl,
        $from_id, $amount, $amountStep,
        $update->update_id, $message_id);
    if (in_array($result,['created','delivery_pending'],true)) {
        \AchBot\Telegram\Conversation\removeReplyKeyboard(achbotMessage($result==='created'
            ?'wallet.wallet_start.amount_registered':'wallet.wallet_start.selector_delivery_pending'),(int)$from_id);
    } elseif ($result === 'review') {
        sendMessage(\achbotMessage('wallet.wallet_recharge.wallet_topup_amount_problem.message.983d706c66'), null, 'HTML');
    } else {
        sendMessage(\achbotMessage('wallet.wallet_recharge.wallet_topup_amount_problem.message.d0531e17b9'), null, 'HTML');
    }
    return;
}

