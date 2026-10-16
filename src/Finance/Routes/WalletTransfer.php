<?php
/** AChBot V8: source-pinned financial compatibility module. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

if($data=="transferMyWallet"){
    if($userInfo['wallet'] > 0 ){
        delMessage();
        sendMessage("🔀 در صورتی که قصد انتقال مبلغ دلخواه از موجودی خودتان به کاربری دیگری را دارید از اینجا می توانید انجام دهید.
🔢 لطفا شناسه عددی کاربری که می خواهید موجودی به حساب آن منتقل کنید را وارد کنید.",$cancelKey);
        setUser($data);
    }else alert("⛔️ موجودی حساب شما کم است. موجودی حساب شما باید بیشتر از 0 باشد.");
}
if($userInfo['step'] =="transferMyWallet" && $text != $buttonValues['cancel']){
    if(preg_match('/^[1-9][0-9]*$/D', (string)$text) &&
        strlen((string)$text) <= 19 && (string)(int)$text === (string)$text){
        if($text != $from_id){
            $stmt= $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
            $stmt->bind_param("i", $text);
            $stmt->execute();
            $checkExist = $stmt->get_result();
            $stmt->close();
            
            if($checkExist->num_rows > 0){
                setUser("tranfserUserAmount" . $text);
                sendMessage("لطفا مبلغ مورد نظر را به تومان و با اعداد انگلیسی وارد کنید.");
            }else sendMessage("⛔️ کاربری با این شناسه عددی یافت نشد.");
        }else sendMessage("⚠️ لطفا شناسه عددی کاربری غیر از خودتان را ارسال کنید.");
    }else sendMessage($mainValues['send_only_number']);
}
if (preg_match('/^tranfserUserAmount(\d+)$/D', (string)$userInfo['step'], $match) &&
    $text != $buttonValues['cancel']) {

    if (!preg_match('/^[0-9]+$/D', (string)$text)) {
        sendMessage($mainValues['send_only_number']);
        exit;
    }

    $digits = ltrim((string)$text, '0');
    if ($digits === '' || strlen($digits) > 10 ||
        (strlen($digits) === 10 && strcmp($digits, '2147483647') > 0)) {
        sendMessage('⛔️ مبلغ انتقال نامعتبر یا خارج از محدوده مجاز است.');
        exit;
    }
    $amount = (int)$digits;
    $to_id  = (int)$match[1];

    if ($amount <= 0) {
        sendMessage("لطفا عددی بزرگتر از صفر وارد کنید.");
        exit;
    }

    if ($to_id === (int)$from_id) {
        sendMessage("⚠️ لطفا شناسه عددی کاربری غیر از خودتان را ارسال کنید.");
        exit;
    }

    $transactionActive = false;
    $commitStarted = false;
    try {
        $connection->begin_transaction();
        $transactionActive = true;

        // Stable lock order avoids inverse-direction transfer deadlocks.
        $userIds = [(int)$from_id, $to_id];
        sort($userIds, SORT_NUMERIC);
        $locked = [];
        foreach ($userIds as $userId) {
            $stmt = $connection->prepare(
                "SELECT userid,name,username,wallet,step FROM users WHERE userid = ? FOR UPDATE"
            );
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $locked[$userId] = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
        $sender = $locked[(int)$from_id] ?? null;
        $receiver = $locked[$to_id] ?? null;
        if (!$sender) {
            throw new RuntimeException('transfer_sender_missing');
        }
        if (!$receiver) {
            $connection->rollback();
            $transactionActive = false;
            sendMessage("⛔️ کاربری با این شناسه عددی یافت نشد.");
            exit;
        }

        // A Telegram transfer step is a one-use claim, verified under lock.
        $expectedStep = (string)$userInfo['step'];
        if ((string)$sender['step'] !== $expectedStep) {
            $connection->rollback();
            $transactionActive = false;
            sendMessage("⛔️ این درخواست انتقال قبلاً پردازش شده یا معتبر نیست.");
            exit;
        }

        $sender_before = (int)$sender['wallet'];
        $receiver_before = (int)$receiver['wallet'];
        if ($sender_before < $amount) {
            $connection->rollback();
            $transactionActive = false;
            sendMessage("موجودی حساب شما کم است.");
            exit;
        }
        // The original wallet is signed INT(11): incoming genuine credit may
        // reduce recipient debt but must never overflow the positive bound.
        if ($receiver_before > 2147483647 - $amount) {
            $connection->rollback();
            $transactionActive = false;
            sendMessage("⛔️ انتقال این مبلغ در حال حاضر امکان‌پذیر نیست؛ مبلغی کسر نشد.");
            exit;
        }

        // Both wallet effects and step consumption must commit atomically.
        $maxReceiverBefore = 2147483647 - $amount;
        $stmt = $connection->prepare(
            "UPDATE users SET wallet = wallet + ? WHERE userid = ? AND wallet <= ? LIMIT 1"
        );
        $stmt->bind_param("iii", $amount, $to_id, $maxReceiverBefore);
        $stmt->execute();
        $credited = $stmt->affected_rows;
        $stmt->close();
        if ($credited !== 1) {
            throw new RuntimeException('transfer_recipient_credit_missing');
        }

        $stmt = $connection->prepare(
            "UPDATE users SET wallet = wallet - ?, step = 'none' " .
            "WHERE userid = ? AND step = ? AND wallet >= ? LIMIT 1"
        );
        $stmt->bind_param("iisi", $amount, $from_id, $expectedStep, $amount);
        $stmt->execute();
        $debited = $stmt->affected_rows;
        $stmt->close();
        if ($debited !== 1) {
            throw new RuntimeException('transfer_sender_debit_claim_missing');
        }

        $sender_after = $sender_before - $amount;
        $receiver_after = $receiver_before + $amount;

        $commitStarted = true;
        $connection->commit();
        $transactionActive = false;
    } catch (Throwable $e) {
        $rollbackState = 'not_needed';
        if ($transactionActive) {
            try {
                $connection->rollback();
                $rollbackState = 'completed';
            } catch (Throwable $rollbackError) {
                $rollbackState = 'unconfirmed';
            }
        }

        // Never expose SQL, panel, API or exception details to customers.
        // Use a short reference for a separate, access-controlled operator
        // report. Only known internal identifiers are allowed as reason codes.
        $reference = strtoupper(bin2hex(random_bytes(6)));
        $knownReasons = [
            'transfer_sender_missing',
            'transfer_recipient_credit_missing',
            'transfer_sender_debit_claim_missing',
        ];
        $reason = in_array($e->getMessage(), $knownReasons, true)
            ? $e->getMessage() : 'transfer_database_or_runtime_error';
        $sqlstate = $e instanceof mysqli_sql_exception ? $e->getSqlState() : 'n/a';
        $adminReport = "⚠️ <b>AChBot wallet transfer failure</b>\n"
            . "Reference: <code>{$reference}</code>\n"
            . "Reason: <code>{$reason}</code>\n"
            . "Exception class: <code>" . htmlspecialchars(get_class($e), ENT_QUOTES, 'UTF-8') . "</code>\n"
            . "SQLSTATE: <code>" . htmlspecialchars((string)$sqlstate, ENT_QUOTES, 'UTF-8') . "</code>\n"
            . "Commit attempted: <code>" . ($commitStarted ? 'yes' : 'no') . "</code>\n"
            . "Rollback: <code>{$rollbackState}</code>\n"
            . "Sender: <code>" . (int)$from_id . "</code>\n"
            . "Recipient: <code>{$to_id}</code>\n"
            . "Amount (Toman): <code>{$amount}</code>";
        $reported = false;
        if (isset($admin) && (int)$admin > 0) {
            try {
                $reply = sendMessage($adminReport, null, 'HTML', $admin);
                $reported = $reply !== false &&
                    !(is_object($reply) && isset($reply->ok) && $reply->ok !== true);
            } catch (Throwable $notifyError) {
                // A missing Telegram notification must not hide the failure.
            }
        }
        if (!$reported) {
            error_log("ACHBOT_WALLET_TRANSFER_FAILURE reference={$reference} "
                . "reason={$reason} sqlstate={$sqlstate} "
                . "commit_attempted=" . ($commitStarted ? 'yes' : 'no')
                . " rollback={$rollbackState}");
        }
        sendMessage("❌ وضعیت درخواست انتقال شما نیاز به بررسی دارد. "
            . "لطفاً موجودی کیف پول را بررسی کنید و در صورت نیاز "
            . "کد پیگیری {$reference} را به پشتیبانی بدهید.");
        exit;
    }

    // All notifications happen strictly AFTER the wallet transaction commits.
    // A failed Telegram request cannot roll back a completed banked transfer.
    $deliveryProblems = [];
    $sendTransferNotice = static function ($message, $keyboard, $parse, $recipient, $role)
        use (&$deliveryProblems) {
        try {
            $result = sendMessage($message, $keyboard, $parse, $recipient);
            if ($result === false ||
                (is_object($result) && isset($result->ok) && $result->ok !== true)) {
                $deliveryProblems[] = $role;
            }
        } catch (Throwable $noticeError) {
            $deliveryProblems[] = $role;
        }
    };

    // ساخت نام و یوزرنیم
        $sender_name = !empty($sender['name']) ? $sender['name'] : '—';
        $receiver_name = !empty($receiver['name']) ? $receiver['name'] : '—';

        $sender_username = !empty($sender['username']) ? '@' . $sender['username'] : '—';
        $receiver_username = !empty($receiver['username']) ? '@' . $receiver['username'] : '—';

        // پیام برای مقصد
        $sendTransferNotice(
            "✅ مبلغ " . number_format($amount) . " تومان به کیف پول شما توسط کاربر $from_id انتقال یافت.",
            null, null, $to_id, 'recipient'
        );

        // گزارش برای ادمین/کانال
        $channelMessage =
            "🔀 <b>گزارش جابجایی موجودی</b>\n"
            . "━━━━━━━━━━━━\n"
            . "🎫 <b>مبدأ</b>: <code>$from_id</code>\n"
            . "🧾 نام: <b>" . htmlspecialchars($sender_name) . "</b>\n"
            . "🔰 نام کاربری: <b>" . htmlspecialchars($sender_username) . "</b>\n"
            . "💼 قبل: <b>" . number_format($sender_before) . "</b>\n"
            . "💼 بعد: <b>" . number_format($sender_after) . "</b>\n"
            . "━━━━━━━━━━━━\n"
            . "🎫 <b>مقصد</b>: <code>$to_id</code>\n"
            . "🧾 نام: <b>" . htmlspecialchars($receiver_name) . "</b>\n"
            . "🔰 نام کاربری: <b>" . htmlspecialchars($receiver_username) . "</b>\n"
            . "💼 قبل: <b>" . number_format($receiver_before) . "</b>\n"
            . "💼 بعد: <b>" . number_format($receiver_after) . "</b>\n"
            . "━━━━━━━━━━━━\n"
            . "💰 مبلغ انتقال: <b>" . number_format($amount) . "</b> تومان";

        // ارسال به ادمین
        $sendTransferNotice($channelMessage, null, "html", $admin, 'admin');

        // ارسال به کانال گزارشات (در صورت تنظیم)
        $reportChannel = $botState['rewardChannel'] ?? null;
        if (!empty($reportChannel)) {
            $sendTransferNotice($channelMessage, null, "html", $reportChannel, 'report_channel');
        }

        // پیام برای مبدأ؛ قدم قبلاً در همان تراکنش مصرف شده است.
        $sendTransferNotice("✅ مبلغ " . number_format($amount) .
            " تومان به کیف پول کاربر مورد نظر شما انتقال یافت.",
            $removeKeyboard, null, $from_id, 'sender');
        $sendTransferNotice("لطفا یکی از کلید های زیر را انتخاب کنید.",
            getMainKeys(), null, $from_id, 'sender_menu');

    // A committed transfer is never undone because an outbound Telegram
    // notification failed. Surface precise destination-level diagnostics
    // to the private operator, not to the customer or public report channel.
    if ($deliveryProblems !== []) {
        $noticeReference = strtoupper(bin2hex(random_bytes(6)));
        $failedRoles = implode(',', array_unique($deliveryProblems));
        $notice = "⚠️ <b>AChBot wallet transfer notification failure</b>\n"
            . "Reference: <code>{$noticeReference}</code>\n"
            . "Reason: <code>transfer_notification_unconfirmed</code>\n"
            . "Failed roles: <code>{$failedRoles}</code>\n"
            . "Transfer already committed: <code>yes</code>\n"
            . "Sender: <code>" . (int)$from_id . "</code>\n"
            . "Recipient: <code>{$to_id}</code>\n"
            . "Amount (Toman): <code>{$amount}</code>";
        $operatorNotified = false;
        if (!in_array('admin', $deliveryProblems, true) && isset($admin) && (int)$admin > 0) {
            try {
                $reply = sendMessage($notice, null, 'HTML', $admin);
                $operatorNotified = $reply !== false &&
                    !(is_object($reply) && isset($reply->ok) && $reply->ok !== true);
            } catch (Throwable $noticeError) {
                // Non-financial notification failure must not invalidate the payment.
            }
        }
        if (!$operatorNotified) {
            error_log("ACHBOT_WALLET_TRANSFER_NOTIFICATION reference={$noticeReference} "
                . "roles={$failedRoles} committed=yes");
        }
    }
}

