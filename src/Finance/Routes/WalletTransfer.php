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
    if(is_numeric($text)){
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
if (preg_match('/^tranfserUserAmount(\d+)/', $userInfo['step'], $match) && $text != $buttonValues['cancel']) {

    if (!is_numeric($text)) {
        sendMessage($mainValues['send_only_number']);
        exit;
    }

    $amount = (int)$text;
    $to_id  = (int)$match[1];

    if ($amount <= 0) {
        sendMessage("لطفا عددی بزرگتر از صفر وارد کنید.");
        exit;
    }

    if ($to_id === (int)$from_id) {
        sendMessage("⚠️ لطفا شناسه عددی کاربری غیر از خودتان را ارسال کنید.");
        exit;
    }

    $connection->begin_transaction();

    try {
        // مبدأ (قبل) - قفل رکورد
        $stmt = $connection->prepare("SELECT `userid`,`name`,`username`,`wallet` FROM `users` WHERE `userid` = ? FOR UPDATE");
        $stmt->bind_param("i", $from_id);
        $stmt->execute();
        $sender = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // مقصد (قبل) - قفل رکورد
        $stmt = $connection->prepare("SELECT `userid`,`name`,`username`,`wallet` FROM `users` WHERE `userid` = ? FOR UPDATE");
        $stmt->bind_param("i", $to_id);
        $stmt->execute();
        $receiver = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$sender) {
            throw new Exception("Sender not found");
        }
        if (!$receiver) {
            $connection->rollback();
            sendMessage("⛔️ کاربری با این شناسه عددی یافت نشد.");
            exit;
        }

        $sender_before   = (int)$sender['wallet'];
        $receiver_before = (int)$receiver['wallet'];

        if ($sender_before < $amount) {
            $connection->rollback();
            sendMessage("موجودی حساب شما کم است.");
            exit;
        }

        // انجام انتقال
        $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` + ? WHERE `userid` = ?");
        $stmt->bind_param("ii", $amount, $to_id);
        $stmt->execute();
        $stmt->close();

        $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` - ? WHERE `userid` = ?");
        $stmt->bind_param("ii", $amount, $from_id);
        $stmt->execute();
        $stmt->close();

        // موجودی بعد از انتقال (به دلیل قفل، قابل اطمینانه)
        $stmt = $connection->prepare("SELECT `wallet` FROM `users` WHERE `userid` = ?");
        $stmt->bind_param("i", $from_id);
        $stmt->execute();
        $sender_after = (int)($stmt->get_result()->fetch_assoc()['wallet'] ?? 0);
        $stmt->close();

        $stmt = $connection->prepare("SELECT `wallet` FROM `users` WHERE `userid` = ?");
        $stmt->bind_param("i", $to_id);
        $stmt->execute();
        $receiver_after = (int)($stmt->get_result()->fetch_assoc()['wallet'] ?? 0);
        $stmt->close();

        $connection->commit();

        // ساخت نام و یوزرنیم
        $sender_name = !empty($sender['name']) ? $sender['name'] : '—';
        $receiver_name = !empty($receiver['name']) ? $receiver['name'] : '—';

        $sender_username = !empty($sender['username']) ? '@' . $sender['username'] : '—';
        $receiver_username = !empty($receiver['username']) ? '@' . $receiver['username'] : '—';

        // پیام برای مقصد
        sendMessage(
            "✅ مبلغ " . number_format($amount) . " تومان به کیف پول شما توسط کاربر $from_id انتقال یافت.",
            null,
            null,
            $to_id
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
        sendMessage($channelMessage, null, "html", $admin);

        // ارسال به کانال گزارشات (در صورت تنظیم)
        $reportChannel = $botState['rewardChannel'] ?? null;
        if (!empty($reportChannel)) {
            sendMessage($channelMessage, null, "html", $reportChannel);
        }

        // پیام برای مبدأ
        setUser();
        sendMessage("✅ مبلغ " . number_format($amount) . " تومان به کیف پول کاربر مورد نظر شما انتقال یافت.", $removeKeyboard);
        sendMessage("لطفا یکی از کلید های زیر را انتخاب کنید.", getMainKeys());

    } catch (Exception $e) {
        $connection->rollback();
        sendMessage("❌ خطایی رخ داد. لطفاً دوباره تلاش کنید.");
    }
}

