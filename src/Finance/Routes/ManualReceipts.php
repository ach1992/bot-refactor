<?php
/** AChBot V8: source-pinned financial compatibility module. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

if (preg_match('/^approvePayment(.*)/', $data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $hash_id = $match[1];
    // دریافت اطلاعات پرداخت
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $price = $payInfo['price'];
    $userId = $payInfo['user_id'];

    if (!$payInfo || ($payInfo['state'] === 'expired_have_sent')) {
        alert(!$payInfo ? "⛔️ اطلاعات پرداخت پیدا نشد." : "⛔️ این درخواست منقضی شده است.");
        return;
    }
    if ($payInfo['state'] === 'approved' || $payInfo['state'] === 'declined') {
        alert("⛔️ این درخواست قبلاً بررسی شده است.");
        return;
    }

    // دریافت موجودی فعلی کیف پول
    $stmt = $connection->prepare("SELECT `wallet` FROM `users` WHERE `userid` = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $currentWallet = $stmt->get_result()->fetch_assoc()['wallet'] ?? 0;
    $stmt->close();

    // ثبت وضعیت تایید و ادمین تاییدکننده
    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'approved', `approved_by` = ? WHERE `hash_id` = ?");
    $stmt->bind_param("is", $from_id, $match[1]);
    $stmt->execute();
    $stmt->close();

    // افزایش موجودی کیف پول
    $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` + ? WHERE `userid` = ?");
    $stmt->bind_param("ii", $price, $userId);
    $stmt->execute();
    $stmt->close();

    // محاسبه موجودی جدید
    $newWallet = $currentWallet + $price;

    $keys = json_encode([
        'inline_keyboard' => [
            [['text' => "✅ تایید شد توسط $from_id", 'callback_data' => "wizwizch"]],
            [['text' => "🆔 $hash_id", 'callback_data' => "noop_$hash_id"]]
        ]
    ], 488);

    // ویرایش پیام ادمین اصلی
    editKeys($keys, $payInfo['message_id'], $payInfo['chat_id']);

    // ویرایش پیام سایر ادمین‌ها
    $adminMessages = json_decode($payInfo['admins_messages'], true);
    if (is_array($adminMessages)) {
        foreach ($adminMessages as $msgInfo) {
            if (
                isset($msgInfo['chat_id'], $msgInfo['message_id']) &&
                $msgInfo['chat_id'] != $payInfo['chat_id']
            ) {
                editKeys($keys, $msgInfo['message_id'], $msgInfo['chat_id']);
            }
        }
    }

    // ارسال پیام به ادمین‌ها
    $adminMessages = "💳 افزایش موجودی کاربر $userId با موفقیت انجام شد.

💰 موجودی قدیم: " . number_format($currentWallet) . " تومان
💵 موجودی جدید: " . number_format($newWallet) . " تومان
🔓 شناسه پرداخت: $hash_id";
    sendToAllAdmins('sendMessage', ['text' => $adminMessages, 'parse_mode' => "html"]);
    
    // ارسال پیام به ادمین خاص
    sendMessage($adminMessages, null, "html", $admin);

    // ارسال پیام به کاربر
    sendMessage("✅ افزایش حساب شما با موفقیت تایید شد.\n💰 مبلغ <b>" . number_format($price) . " تومان</b> به کیف پول شما اضافه شد.\n\n<blockquote>
⚠️ لطفا دقت کنید شما فقط کیف پول خودتان را شارژ کرده‌اید، برای خرید یا تمدید باید از دکمه های زیر استفاده کنید:
🛒 <b>خرید اشتراک جدید</b>
یا <b>🔄 تمدید یا مشاهده اشتراک ها</b>
و در مرحله پرداخت روی دکمه زیر بزنید:
💰 <b>پرداخت با موجودی</b>
</blockquote>
📨 پشتیبانی:
🆔 $supportID", null, "HTML", $userId);
}

// Decline Cart To Cart by ACh

if (preg_match('/^noop_(.+)$/', $data, $m)) {
	$hash_id = $m[1];
	sendMessage("🆔 Hash ID: <code>$hash_id</code>", null, "HTML", $from_id);
	exit;
}

if (preg_match('/^declineAction_(.+)$/', $data, $match) && ($from_id == $admin || !empty($userInfo['isAdmin']))) {
    $hash_id = $match[1];

    // بررسی وجود و وضعیت پرداخت
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $hash_id);
    $stmt->execute();
    $payInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();

	if (!$payInfo || ($payInfo['state'] === 'expired_have_sent')) {
		alert(!$payInfo ? "⛔️ اطلاعات پرداخت پیدا نشد." : "⛔️ این درخواست منقضی شده است.");
		return;
	}

    if ($payInfo['state'] === 'approved' || $payInfo['state'] === 'declined') {
        alert("⛔️ این درخواست قبلاً بررسی شده است.");
        return;
    }

    setUser("declineActionReason_" . $hash_id . "_" . $message_id);
    sendMessage("📝 لطفاً دلیل رد این درخواست را با جزئیات بنویسید تا به کاربر اطلاع داده شود:", $cancelKey);
}

if (preg_match('/^declineActionReason_(.+)_(\d+)$/', $userInfo['step'], $match) && $text != $buttonValues['cancel']) {
    setUser();
    $hash_id = $match[1];
    $message_id = $match[2];

    // دریافت اطلاعات پرداخت
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $hash_id);
    $stmt->execute();
    $payInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
	
	if (!$payInfo || ($payInfo['state'] === 'expired_have_sent')) {
		alert(!$payInfo ? "⛔️ اطلاعات پرداخت پیدا نشد." : "⛔️ این درخواست منقضی شده است.");
		return;
	}

    if ($payInfo['state'] === 'approved' || $payInfo['state'] === 'declined') {
		alert("⛔️ این درخواست قبلاً بررسی شده است.");
		return;
	}

    $uid = $payInfo['user_id'];
    $price = !empty($payInfo['price']) ? number_format($payInfo['price']) . " تومان" : "---";
    $reason = $text;
	$remark = "---";
	
	// تلاش برای گرفتن ریمارک دقیق
	if (!empty($payInfo['order_id'])) {
		$stmt = $connection->prepare("SELECT `remark` FROM `orders_list` WHERE `id` = ?");
		$stmt->bind_param("i", $payInfo['order_id']);
		$stmt->execute();
		$stmt->bind_result($remarkFromOrder);
		if ($stmt->fetch()) {
			$remark = $remarkFromOrder ?: "---";
		}
		$stmt->close();
	} elseif (!empty($payInfo['description'])) {
		$remark = $payInfo['description'];
	}

    // ثبت در دیتابیس
    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'declined', `approved_by` = ? WHERE `hash_id` = ?");
    $stmt->bind_param("is", $from_id, $hash_id);
    $stmt->execute();
    $stmt->close();
	
    $keys = json_encode(['inline_keyboard' => [
        [['text' => "❌ رد شد توسط $from_id", 'callback_data' => "wizwizch"]],
		[['text' => "🆔 $hash_id", 'callback_data' => "noop_$hash_id"]]
    ]]);

    // ویرایش دکمه ادمین اصلی
    editKeys($keys, $payInfo['message_id'], $payInfo['chat_id']);

    // ویرایش دکمه سایر ادمین ها
    $adminMessages = json_decode($payInfo['admins_messages'], true);
    if (is_array($adminMessages)) {
        foreach ($adminMessages as $msgInfo) {
            if (
                isset($msgInfo['chat_id'], $msgInfo['message_id']) &&
                $msgInfo['chat_id'] != $payInfo['chat_id']
            ) {
                editKeys($keys, $msgInfo['message_id'], $msgInfo['chat_id']);
            }
        }
    }

	// ارسال پیام برای کاربر
	$retryButton = json_encode([
		'inline_keyboard' => [
			[
				[
					'text' => "🔄 درخواست دوباره",
					'url'  => "https://t.me/$botUsername?start=restart_$hash_id"
				]
			]
		]
	]);
    $message = "❌ درخواست شما به دلایل زیر رد شد:\n\n<blockquote>$reason</blockquote>\n\n⚠️ اطلاعات درخواست:\n";
    $message .= "💰 مبلغ: <b>$price</b>\n";
    $message .= "♾️ نام اشتراک: <code>$remark</code>\n";
	$message .= "💳 شناسه پرداخت: <code>$hash_id</code>";
    sendMessage($message, $retryButton, "HTML", $uid);

	$adminAfterSendKeys = json_encode([
		'inline_keyboard' => [
			[
				['text' => "🚫 مسدود کردن کاربر", 'callback_data' => "banUserDirect_$uid"]
			]
		]
	]);

	sendMessage(
		"📩 پیام شما برای کاربر <code>$uid</code> ارسال شد.\n\n⚠️ جزئیات درخواست کاربر:\n💰 مبلغ: <b>$price</b>\n♾️ نام اشتراک: <code>$remark</code>\n💳 شناسه پرداخت: <code>$hash_id</code>",
		$adminAfterSendKeys,
		"html"
	);
}

if (preg_match('/^banUserDirect_(\d+)$/', $data, $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)) {

    $targetUserId = (int)$m[1];
	
    // delMessage();

    $stmt = $connection->prepare("SELECT `step` FROM `users` WHERE `userid` = ?");
    $stmt->bind_param("i", $targetUserId);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    if ($res->num_rows > 0) {
        $u = $res->fetch_assoc();

        if ($u['step'] != "banned") {
            $stmt = $connection->prepare("UPDATE `users` SET `step` = 'banned' WHERE `userid` = ?");
            $stmt->bind_param("i", $targetUserId);
            $stmt->execute();
            $stmt->close();

            alert("✅ کاربر $targetUserId مسدود شد.");
        } else {
            alert("☑️ این کاربر از قبل مسدود بود.");
        }
    } else {
        alert("⛔️ کاربری با این آیدی یافت نشد.");
    }

    $doneKeys = json_encode([
        'inline_keyboard' => [
            [
                ['text' => "✅ مسدود شد: $targetUserId", 'callback_data' => "wizwizch"]
            ]
        ]
    ]);
    editKeys($doneKeys, $message_id, $chat_id);

}

// END Decline Cart To Cart by ACh

