<?php
/** AChBot V8: source-pinned financial compatibility module. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

// Pre Pay Check By ACh

if (preg_match('/^prePayCheck_([a-zA-Z0-9]+)_([a-zA-Z0-9]+)_([a-zA-Z0-9]+)/', $data, $match)) {
    $actionType = $match[1];
    $payMethod  = strtolower($match[2]);
    $hash_id    = $match[3];

    $stmt = $connection->prepare("SELECT * FROM pays WHERE hash_id = ?");
    $stmt->bind_param("s", $hash_id);
    $stmt->execute();
    $payInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$payInfo) {
        delMessage();
        sendMessage("❌ سفارش یافت نشد یا حذف شده است.", getMainKeys());
        exit;
    }

    $now = time();
    if ($now - $payInfo['request_date'] > 10800) {
		if ($payInfo['state'] === 'pending') {
			$stmt = $connection->prepare("UPDATE pays SET state = 'expired' WHERE id = ?");
			$stmt->bind_param("i", $payInfo['id']);
			$stmt->execute();
			$stmt->close();
		}
    
		delMessage();
		sendMessage("⏳ مهلت پرداخت این سفارش به پایان رسیده است.\n🔄 لطفاً دوباره اقدام کنید.", getMainKeys());
		exit;
	}

    // چک پلن فقط برای تمدید
    if ($payInfo['type'] === 'RENEW_ACCOUNT') {
        $oid = $payInfo['order_id'];
        $savedFid = $payInfo['plan_id'];

        $stmt = $connection->prepare("SELECT fileid FROM orders_list WHERE id = ?");
        $stmt->bind_param("i", $oid);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$order || $order['fileid'] != $savedFid) {
            delMessage();
            sendMessage("❗️ پلن فعلی شما تغییر کرده است.\n♻️ لطفاً دوباره روی دکمه تمدید اشتراک بزنید تا قیمت جدید محاسبه شود.", getMainKeys());
            $stmt = $connection->prepare("UPDATE pays SET state = 'mismatched_plan' WHERE id = ?");
            $stmt->bind_param("i", $payInfo['id']);
            $stmt->execute();
            $stmt->close();
            exit;
        }
    }

    // اگر روش پرداخت ریدایرکتی بود
	if (in_array($payMethod, ["zarinpal", "nowpayment", "nextpay"])) {
		$redirectUrl = $botUrl . "pay/?$payMethod&hash_id=$hash_id";

		// دکمه با لینک مستقیم به درگاه
		$button = [
			'inline_keyboard' => [
				[['text' => "🔗 رفتن به درگاه پرداخت", 'url' => $redirectUrl]]
			]
		];

		answerCallbackQuery($callbackId, "در حال آماده‌سازی لینک پرداخت...");
		sendMessage("⚠️ دقت کنید هنگام پرداخت کارمزد را در نظر بگیرید که از مقدار پرداختی شما کسر نشود.
✅ بعد پرداخت باید صفحه را باز نگه دارید تا تایید نهایی شود و بعد به ربات منتقل شوید.
🔰 حتما PaymentID یا شناسه پرداخت خودتان در صفحه باز شده را یادداشت کنید.
💳 برای ادامه پرداخت روی دکمه زیر بزنید.
⏳ اعتبار این لینک 30 دقیقه می باشد.", json_encode($button), "html", $from_id);
		exit;
	}

    // بازنویسی $data برای هدایت به هندلر اصلی
    $map = [
        "wallet"      => "pay{$actionType}WithWallet$hash_id",
        "carttocart"  => "pay{$actionType}WithCartToCart$hash_id",
        "tronwallet"  => "payWithTronWallet$hash_id",
        "weswap"      => "payWithWeSwap$hash_id"
    ];

    if (!isset($map[$payMethod])) {
        delMessage();
        sendMessage("⛔️ روش پرداخت ناشناخته است.", getMainKeys());
        exit;
    }

    $data = $map[$payMethod];
}

