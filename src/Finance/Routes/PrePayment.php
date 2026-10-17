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

    // A callback button can survive a provider settlement indefinitely.
    // Reject ALL terminal states before checking plans, expiry or gateways.
    // Never let a stale Telegram callback rewrite a paid/terminal receipt.
    if (!in_array((string)$payInfo['state'], ['pending','send'], true)) {
        answerCallbackQuery($callbackId, "این سفارش دیگر در انتظار پرداخت نیست.");
        return;
    }

    $subjectSupported=achbotSupportsPaymentSubject($connection,$payInfo);
    // A changed RENEW_ACCOUNT plan is deliberately handled below with its
    // own owner/provider preflight; other unsupported subjects fail here.
    if (!$subjectSupported && $payInfo['type']!=='RENEW_ACCOUNT') {
        alert("⛔️ انجام این درخواست برای اشتراک انتخاب‌شده امکان‌پذیر نیست. با پشتیبانی تماس بگیرید.", true);
        return;
    }
    $now = time();
    // A provider-scoped attempt blocks competing checkout and renewal
    // invalidation, even while the upstream create request is uncertain.
    $paymentAttempt=null;
    $attemptTable=$connection->query("SHOW TABLES LIKE 'payment_attempts'");
    if ($attemptTable && $attemptTable->num_rows===1) {
        $attemptStmt=$connection->prepare("SELECT provider,stage,provider_reference,checkout_url
            FROM payment_attempts WHERE pay_row_id=? LIMIT 1");
        $attemptStmt->bind_param('i',$payInfo['id']);
        $attemptStmt->execute();
        $paymentAttempt=$attemptStmt->get_result()->fetch_assoc() ?: null;
        $attemptStmt->close();
    }
    $activeGatewayClaim=$paymentAttempt!==null &&
        in_array($paymentAttempt['stage'],['creating','pending','checking','fulfilling','review'],true);

    // RENEW_ACCOUNT may be rejected by the generic subject preflight when
    // its saved plan differs from the CURRENT account plan. Handle that
    // mismatch only for the same Marzban owner, before any expiry mutation.
    if ($payInfo['type']==='RENEW_ACCOUNT') {
        $oid=(int)($payInfo['order_id']??0);
        $savedFid=(int)($payInfo['plan_id']??0);
        $userId=(int)($payInfo['user_id']??0);
        $stmt=$connection->prepare("SELECT fileid,userid FROM orders_list WHERE id=? LIMIT 1");
        $stmt->bind_param('i',$oid);
        $stmt->execute();
        $order=$stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($order && (int)$order['fileid']!==$savedFid) {
            // Do not reinterpret unsupported/foreign accounts as a valid
            // renewal just because the saved plan identifier is stale.
            if ($userId<=0 || (int)$order['userid']!==$userId ||
                !achbotSupportsMarzbanOrder($connection,$oid,null,$userId) ||
                !achbotSupportsMarzbanPlan($connection,$savedFid,0,$payInfo['server_id']??null)) {
                alert("⛔️ انجام این درخواست برای اشتراک انتخاب‌شده امکان‌پذیر نیست. با پشتیبانی تماس بگیرید.",true);
                return;
            }

            // Even a pending row can already have a provider-issued token.
            // Never invalidate that evidence or issue a competing checkout.
            $claimedInvoice=$paymentAttempt!==null ||
                (string)($payInfo['payid']??'')!=='' ||
                (string)$payInfo['state']!=='pending';
            if ($claimedInvoice) {
                answerCallbackQuery($callbackId,"این پرداخت قبلاً ثبت شده یا در حال بررسی است.");
                // Do not advertise a still-payable link for a now-mismatched
                // plan: retain the invoice ID/URL for operator reconciliation.
                sendMessage("پلن اشتراک تغییر کرده ولی وضعیت پرداخت/فاکتور قبلی محفوظ است. لطفاً برای تطبیق پرداخت با پشتیبانی تماس بگیرید.",
                    getMainKeys(),null,$from_id);
                return;
            }

            // A second worker can finalize the payment after our initial
            // SELECT. The local mismatch transition is legal ONLY while
            // the same renewal remains pending, unclaimed and mismatched.
            // Removing the obsolete button never implies financial finality.
            delMessage();
            $mismatchSql="UPDATE pays SET state='mismatched_plan'
                WHERE id=? AND type='RENEW_ACCOUNT' AND state='pending'
                  AND (payid IS NULL OR payid='')
                  AND order_id=? AND plan_id=? AND user_id=?
                  AND EXISTS (SELECT 1 FROM orders_list o
                      WHERE o.id=? AND o.userid=? AND o.fileid<>?)";
            if ($attemptTable && $attemptTable->num_rows===1) {
                $mismatchSql.=" AND NOT EXISTS (
                    SELECT 1 FROM payment_attempts a
                    WHERE a.pay_row_id=pays.id)";
            }
            $stmt=$connection->prepare($mismatchSql);
            $stmt->bind_param('iiiiiii',$payInfo['id'],$oid,$savedFid,
                              $userId,$oid,$userId,$savedFid);
            $stmt->execute();
            $invalidated=$stmt->affected_rows===1;
            $stmt->close();
            sendMessage($invalidated
                ? "❗️ پلن فعلی شما تغییر کرده است.\n♻️ لطفاً دوباره روی دکمه تمدید اشتراک بزنید تا قیمت جدید محاسبه شود."
                : "وضعیت پرداخت این سفارش تغییر کرده است؛ برای جلوگیری از پرداخت تکراری با پشتیبانی تماس بگیرید.",
                getMainKeys());
            return;
        }
        if (!$subjectSupported) {
            alert("⛔️ انجام این درخواست برای اشتراک انتخاب‌شده امکان‌پذیر نیست. با پشتیبانی تماس بگیرید.",true);
            return;
        }
    }

    // The same order cannot be reissued with a different payment provider.
    $selectedProvider=$payMethod==='nowpayment' ? 'nowpayments' : $payMethod;
    if ($activeGatewayClaim && $selectedProvider!==(string)$paymentAttempt['provider']) {
        answerCallbackQuery($callbackId,"این سفارش قبلاً در یک درگاه پرداخت ثبت شده است.");
        $invoiceUrl=(string)($paymentAttempt['checkout_url']??'');
        if ($paymentAttempt['provider']==='nowpayments' && $invoiceUrl!=='' &&
            function_exists('achbotNpValidInvoiceUrl') &&
            achbotNpValidInvoiceUrl($invoiceUrl)) {
            $keyboard=json_encode(['inline_keyboard'=>[[
                ['text'=>'🔗 ادامه همان فاکتور','url'=>$invoiceUrl]
            ]]],JSON_UNESCAPED_UNICODE);
            sendMessage("💳 فاکتور اصلی این سفارش هنوز معتبر است؛ از همان لینک پرداخت استفاده کنید.",
                        $keyboard,"html",$from_id);
        } else {
            sendMessage("وضعیت صدور فاکتور این سفارش در حال بررسی است. برای جلوگیری از درخواست تکراری با پشتیبانی تماس بگیرید.",
                        getMainKeys(),null,$from_id);
        }
        return;
    }

    if ($now - (int)$payInfo['request_date'] > 10800 && !$activeGatewayClaim) {
        if ($payInfo['state']==='pending') {
            // A provider may have claimed payid after the SELECT above.
            // Expire only the same still-unclaimed row; never orphan an
            // external invoice through an old menu button.
            $stmt=$connection->prepare("UPDATE pays SET state='expired'
                WHERE id=? AND state='pending' AND (payid IS NULL OR payid='')");
            $stmt->bind_param('i',$payInfo['id']);
            $stmt->execute();
            $expired=$stmt->affected_rows===1;
            $stmt->close();
            if (!$expired) {
                answerCallbackQuery($callbackId,"پرداخت این سفارش در حال بررسی است.");
                return;
            }
        } elseif ($payInfo['state']!=='expired') {
            answerCallbackQuery($callbackId,"وضعیت این سفارش تغییر کرده است.");
            return;
        }

        delMessage();
        sendMessage("⏳ مهلت پرداخت این سفارش به پایان رسیده است.\n🔄 لطفاً دوباره اقدام کنید.",getMainKeys());
        exit;
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
        if ($payMethod === 'nowpayment') {
            sendMessage("💳 برای ادامه پرداخت روی دکمه زیر بزنید.
✅ بعد از تایید نهایی توسط درگاه، نتیجه خودکار در ربات ثبت می‌شود؛ نیازی به زدن دکمه بازگشت نیست.
🔗 فاکتور صادرشده قابل بازیابی است و در گفت‌وگو لینک مستقیم آن هم برای شما ارسال می‌شود.
⏳ زمان نهایی‌شدن به شبکه و پردازش پذیرنده بستگی دارد.", json_encode($button), "html", $from_id);
        } else {
            sendMessage("⚠️ دقت کنید هنگام پرداخت کارمزد را در نظر بگیرید که از مقدار پرداختی شما کسر نشود.
✅ بعد پرداخت باید صفحه را باز نگه دارید تا تایید نهایی شود و بعد به ربات منتقل شوید.
🔰 حتما PaymentID یا شناسه پرداخت خودتان در صفحه باز شده را یادداشت کنید.
💳 برای ادامه پرداخت روی دکمه زیر بزنید.
⏳ اعتبار این لینک 30 دقیقه می باشد.", json_encode($button), "html", $from_id);
        }
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

