<?php
/** AChBot V8: source-pinned financial compatibility module. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../PaymentLifecycle.php';

// Retired automatic TRON buttons must never reach the old TXID claim path.
if (preg_match('/^prePayCheck_[A-Za-z0-9]+_TronWallet_[A-Za-z0-9_-]+$/iD', (string)$data)) {
    answerCallbackQuery($callbackId,
        'درگاه قدیمی ترون غیرفعال است. برای پرداخت جدید از منوی روش‌ها استفاده کنید.', true);
    return;
}

// Historical WeSwap choices may still exist in old Telegram keyboards.
if (preg_match('/^prePayCheck_[A-Za-z0-9]+_WeSwap_[A-Za-z0-9]+$/iD', $data)) {
    answerCallbackQuery($callbackId, "این روش پرداخت دیگر پشتیبانی نمی‌شود.");
    sendMessage("⛔️ این روش پرداخت حذف شده است؛ سفارش قبلی را با پشتیبانی پیگیری کنید.", getMainKeys(), "HTML", $from_id);
    return;
}

// Read-only status inspection: never contacts a provider or changes money.
if (preg_match('/^ps_([A-Za-z0-9_-]{1,50})$/D', $data, $statusMatch)) {
    $hashId = $statusMatch[1];
    $stmt = $connection->prepare("SELECT id,hash_id,user_id,type,price,state,payid,updated_at FROM pays
        WHERE hash_id=? LIMIT 1");
    $stmt->bind_param('s', $hashId);
    $stmt->execute();
    $pay = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$pay || (int)$pay['user_id'] !== (int)$from_id) {
        answerCallbackQuery($callbackId, "این سفارش برای شما قابل مشاهده نیست.");
        return;
    }
    achbotPayUxTrackClicked($connection,(int)$pay['id'],(int)$from_id,(int)$message_id);
    $provider = null;
    $stage = null;
    $attempt = null;
    try {
        $stmt = $connection->prepare('SELECT provider,stage,payment_id,provider_status,
            TIMESTAMPDIFF(SECOND,created_at,NOW()) AS age_seconds
            FROM payment_attempts WHERE pay_row_id=? LIMIT 1');
        $id = (int)$pay['id'];
        $stmt->bind_param('i',$id);
        $stmt->execute();
        $attempt = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $provider = $attempt['provider'] ?? null;
        $stage = $attempt['stage'] ?? null;
    } catch (Throwable $ignored) {
        // No attempt evidence: do not manufacture a verified status.
    }
    $manual = in_array($provider, ['bank_transfer','gift_card','manual_usdt','manual_trx'], true);
    $methodName = $provider === 'manual_trx' ? 'ترون دستی (TRX)' :
        ($provider === 'manual_usdt' ? 'تتر دستی' : ($provider === 'gift_card' ? 'گیفت‌کارت' :
        ($provider === 'bank_transfer' ? 'کارت‌به‌کارت بانکی' :
        ($provider === 'nowpayments' ? 'پرداخت رمزارزی' :
        ($provider === 'nextpay' ? 'NextPay' :
        ($provider === 'zarinpal' ? 'Zarinpal' : 'نامشخص'))))));
    // A newer order invalidates the old call-to-action even before the next
    // one-minute maintenance tick. Keep any issued provider claim intact.
    try { $pay['superseded']=achbotPayUxHasNewer($connection,$pay); }
    catch (Throwable $ignored) { $pay['superseded']=true; }
    $body = achbotPayUxStatusText($pay,$attempt) .
        "\nروش: " . htmlspecialchars($methodName,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $uiState = achbotPayUxState($pay,$attempt);
    if (in_array((string)$pay['state'],['approved','auto_cart_approved'],true) &&
        preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D',
                   (string)($pay['updated_at'] ?? ''))) {
        $body .= "\n📅 تاریخ تأیید: <code>" .
            htmlspecialchars((string)$pay['updated_at'],ENT_QUOTES,'UTF-8') . "</code>";
    }
    $actions=[];
    if (!in_array($uiState,['expired','declined','payment_recorded'],true)) {
        $actions[]=[['text'=>'🔄 بررسی دوباره وضعیت','callback_data'=>'ps_'.$hashId]];
    }
    $actions[]=[['text'=>'🏠 منوی اصلی','callback_data'=>'mainMenu']];
    $keyboard=inlineKeyboard($actions);
    answerCallbackQuery($callbackId, "وضعیت سفارش از اطلاعات ثبت‌شده خوانده شد.");
    // The clicked Telegram message is our canonical status card; never
    // manufacture a second status message on every refresh.
    editText($message_id, $body, $keyboard, 'HTML', $from_id);
    return;
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

    // A stale Telegram payment button must not allow another user to
    // expire, re-route or reveal the original buyer's payment information.
    if ((int)($payInfo['user_id'] ?? 0) <= 0 ||
        (int)$payInfo['user_id'] !== (int)$from_id) {
        answerCallbackQuery($callbackId, "این درخواست پرداخت متعلق به حساب شما نیست.", true);
        return;
    }

    // A callback button can survive a provider settlement indefinitely.
    // Reject ALL terminal states before checking plans, expiry or gateways.
    // Never let a stale Telegram callback rewrite a paid/terminal receipt.
    if (!in_array((string)$payInfo['state'], ['pending','send'], true)) {
        answerCallbackQuery($callbackId, "این سفارش دیگر در انتظار پرداخت نیست.");
        return;
    }

    // Even an old forged callback must honor the current audience policy.
    $policyMethod=match ($payMethod) {
        'nowpayment'=>'nowpayments','zarinpal'=>'zarinpal','nextpay'=>'nextpay',
        'wallet'=>'wallet','carttocart'=>'bank_transfer','giftcard'=>'gift_card',
        'manualusdt'=>'manual_usdt','manualtrx'=>'manual_trx',
        default=>null
    };
    if ($policyMethod!==null &&
        !achbotPayUxAudienceAllowed($connection,$policyMethod,(int)$from_id)) {
        answerCallbackQuery($callbackId,'این روش پرداخت برای گروه کاربری شما فعال نیست.',true);
        return;
    }
    if (achbotPayUxExpireDraft($connection,(int)$payInfo['id'])) {
        answerCallbackQuery($callbackId,'مهلت این درخواست پایان یافته است.',true);
        editText($message_id,
            '⌛️ این درخواست پرداخت منقضی شده است. از منوی ربات درخواست جدید بسازید.',
            achbotPayUxStatusKeyboard((string)$payInfo['hash_id']), 'HTML',$from_id);
        achbotPayUxTrackClicked($connection,(int)$payInfo['id'],(int)$from_id,(int)$message_id);
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
    if ($activeGatewayClaim) {
        try {
            if (achbotPayUxHostedAttentionElapsed($connection,(int)$payInfo['id'])) {
                answerCallbackQuery($callbackId,'مهلت لینک آنلاین پایان یافته است؛ وضعیت را پیگیری کنید.',true);
                editText($message_id,
                    '⏳ مهلت لینک فاکتور گذشته است؛ پرداخت یا تحویل هنوز باید بررسی شود. اگر وجهی ارسال کرده‌اید مجدداً پرداخت نکنید.',
                    achbotPayUxStatusKeyboard((string)$payInfo['hash_id']),
                    'HTML',$from_id);
                achbotPayUxTrackClicked($connection,(int)$payInfo['id'],(int)$from_id,(int)$message_id);
                return;
            }
        } catch (Throwable $ignored) {
            answerCallbackQuery($callbackId,'وضعیت فاکتور درگاه نامشخص است؛ از پرداخت تکراری پرهیز کنید.',true);
            return;
        }
    }

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
                ['text'=>'🔄 مشاهده وضعیت فاکتور','url'=>$botUrl.'pay/?nowpayment&hash_id='.urlencode($hash_id)]
            ]]],JSON_UNESCAPED_UNICODE);
            sendMessage("💳 فاکتور این سفارش محفوظ است؛ با لینک زیر وضعیت آن را بررسی کنید. اگر پرداختی انجام داده‌اید دوباره پرداخت نکنید.",
                        $keyboard,"html",$from_id);
        } else {
            sendMessage("وضعیت صدور فاکتور این سفارش در حال بررسی است. برای جلوگیری از درخواست تکراری با پشتیبانی تماس بگیرید.",
                        getMainKeys(),null,$from_id);
        }
        return;
    }

    if ($now-(int)$payInfo['request_date'] >= achbotPayUxDraftDeadlineSeconds()
        && !$activeGatewayClaim) {
        // The guarded expiry may have failed because the original payment
        // was claimed concurrently. Never bypass that financial guard.
        answerCallbackQuery($callbackId,
            'مهلت پیش‌نویس پایان یافته یا وضعیت آن نیازمند بررسی است.',true);
        return;
    }

    // اگر روش پرداخت ریدایرکتی بود
	if (in_array($payMethod, ["zarinpal", "nowpayment", "nextpay"])) {
		$redirectUrl = $botUrl . "pay/?$payMethod&hash_id=$hash_id";

		// دکمه با لینک مستقیم به درگاه
        $button = [
            'inline_keyboard'=>[
                [['text'=>'💳 پرداخت فاکتور','url'=>$redirectUrl]],
                [['text'=>'🔄 بررسی وضعیت سفارش','callback_data'=>'ps_'.$hash_id]]
            ]
        ];
        answerCallbackQuery($callbackId,'فاکتور آمادهٔ پرداخت است.');
        $extra = $payMethod==='nowpayment'
            ? '⏳ پردازش رمزارزی ممکن است بیش از چند دقیقه طول بکشد.'
            : '⚠️ بازگشت از درگاه، به‌تنهایی تأیید مالی نیست.';
        $sent = sendMessage(
            "🧾 <b>فاکتور آماده است</b>\n🆔 شناسه: <code>".$hash_id.
            "</code>\n".$extra."\nپس از پرداخت، وضعیت سفارش را در ربات بررسی کنید.",
            json_encode($button,JSON_UNESCAPED_UNICODE),'HTML',$from_id
        );
        achbotPayUxTrackMessage($connection,(int)$payInfo['id'],(int)$from_id,$sent);
		exit;
	}

    // بازنویسی $data برای هدایت به هندلر اصلی
    $map = [
        "wallet"      => "pay{$actionType}WithWallet$hash_id",
        "carttocart"  => "pay{$actionType}WithCartToCart$hash_id",
        "giftcard"    => "pay{$actionType}WithGiftCard$hash_id",
        "manualusdt"  => "mcm_$hash_id"
    ];

    if (!isset($map[$payMethod])) {
        delMessage();
        sendMessage("⛔️ روش پرداخت ناشناخته است.", getMainKeys());
        exit;
    }

    $data = $map[$payMethod];
}

