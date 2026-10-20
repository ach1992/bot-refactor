<?php
/** AChBot V8: source-pinned financial compatibility module. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

// Owner-only, provider-evidence-gated finance inbox. These buttons are
// read-only. Do not repurpose historical manual receipt approval handlers for
// a hosted payment: a click is not settlement or refund authorization.
if ($data==='npReviewInbox' ||
    preg_match('/^npReview(?:Case|Refresh|Verify)_[1-9][0-9]{0,17}(?:_(?:[0-9]|1[01]))?(?:_[1-9][0-9]{0,17})?$/D',
               (string)$data)) {
    if ((int)$from_id !== (int)$admin ||
        (int)($chat_id??0) !== (int)$admin) {
        answerCallbackQuery($callbackId,'دسترسی به بررسی مالی مجاز نیست.',true);
        return;
    }
    if (!defined('ACHBOT_NP_RECONCILE_ONLY')) define('ACHBOT_NP_RECONCILE_ONLY',true);
    require_once __DIR__ . '/../GatewayReturn.php';
    try {
        $scope=achbotNpOperatorWorkSql();
        if ($data==='npReviewInbox') {
            $items=$connection->query("SELECT a.id,a.order_hash,p.price,p.user_id,
                    a.provider_status
                FROM payment_attempts a JOIN pays p ON p.id=a.pay_row_id
                WHERE $scope ORDER BY a.updated_at DESC LIMIT 12");
            $rows=[];
            foreach ($items as $item) {
                $label='🧾 #'.(int)$item['id'].' | '.
                    number_format((int)$item['price']).' تومان | کاربر '.
                    (int)$item['user_id'];
                $rows[]=[['text'=>$label,
                    'callback_data'=>'npReviewCase_'.(int)$item['id']]];
            }
            if (!$rows) {
                $body="✅ <b>پروندهٔ دارای شواهد وجه و نیازمند رسیدگی پیدا نشد.</b>\n".
                    "ساخت شناسه یا فاکتور پرداخت به‌تنهایی هشدار مالی نیست.";
            } else {
                $body="📋 <b>مرکز بررسی پرداخت‌ها</b>\n\n".
                    "فقط پرونده‌های دارای شواهد وجه یا نتیجهٔ مالی نامطمئن نمایش داده می‌شوند.\n".
                    "حداکثر ۱۲ پروندهٔ اخیر نمایش داده می‌شود؛ برای دیدن جزئیات یکی را انتخاب کنید.";
            }
            $rows[]=[['text'=>'🏠 مدیریت','callback_data'=>'managePanel']];
            answerCallbackQuery($callbackId,'فهرست بر اساس اطلاعات مالی ثبت‌شده است.');
            editText($message_id,$body,json_encode(['inline_keyboard'=>$rows],
                JSON_UNESCAPED_UNICODE),'HTML');
            return;
        }
        preg_match('/^npReview(Case|Refresh|Verify)_([1-9][0-9]{0,17})(?:_([0-9]|1[01]))?(?:_([1-9][0-9]{0,17}))?$/D',
                   (string)$data,$parts);
        $action=$parts[1];
        $attemptId=(int)$parts[2];
        $childIndex=(int)($parts[3]??0);
        $cursor=(int)($parts[4]??0);
        $stmt=$connection->prepare("SELECT a.*,p.type,p.price,p.user_id,
                    p.state AS pay_state,p.plan_id,
                    (SELECT u.name FROM users u WHERE u.userid=p.user_id
                     ORDER BY u.id DESC LIMIT 1) AS user_name,
                    (SELECT u.username FROM users u WHERE u.userid=p.user_id
                     ORDER BY u.id DESC LIMIT 1) AS user_handle,
                    (SELECT sp.title FROM server_plans sp WHERE sp.id=p.plan_id
                     LIMIT 1) AS plan_title
            FROM payment_attempts a JOIN pays p ON p.id=a.pay_row_id
            WHERE a.id=? AND a.provider='nowpayments' LIMIT 1");
        $stmt->bind_param('i',$attemptId);
        $stmt->execute();
        $case=$stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$case) {
            answerCallbackQuery($callbackId,'پرونده در دسترس نیست.',true);
            return;
        }
        // Cursor is the first ledger row of this page. Fetch one extra row
        // only to provide a bounded next-page link, never truncate history.
        $stmt=$connection->prepare("SELECT id,payment_id,observed_status,received_at
            FROM payment_transactions
            WHERE attempt_id=? AND provider='nowpayments' AND id>=?
            ORDER BY id ASC LIMIT 13");
        $stmt->bind_param('ii',$attemptId,$cursor);
        if (!$stmt->execute()) throw new RuntimeException('operator_children_read_failed');
        $children=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $nextCursor=count($children)>12?(int)$children[12]['id']:0;
        $children=array_slice($children,0,12);
        if ($action==='Verify' && !isset($children[$childIndex]))
            throw new RuntimeException('operator_child_selection_invalid');
        $esc=static fn($value) => htmlspecialchars(
            substr((string)($value??''),0,128),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $subject=match ((string)$case['type']) {
            'INCREASE_WALLET'=>'شارژ کیف پول',
            'BUY_SUB'=>'خرید اشتراک',
            'RENEW_ACCOUNT'=>'تمدید اشتراک',
            default=>'پرداخت سفارش',
        };
        $handle=ltrim((string)($case['user_handle']??''),'@');
        $body="📋 <b>پروندهٔ مالی #".$attemptId."</b>\n\n".
              "👤 کاربر: ".$esc($case['user_name']?:'نامشخص').
              ($handle!==''?' (@'.$esc($handle).')':'')."\n".
              "🆔 شناسه تلگرام: <code>".(int)$case['user_id']."</code>\n".
              "🧾 موضوع: ".$subject.
              (!empty($case['plan_title'])?' ـ '.$esc($case['plan_title']):'')."\n".
              "💰 مبلغ سفارش: ".number_format((int)$case['price'])." تومان\n".
              "💱 مبلغ فاکتور: ".$esc($case['quote_amount']).' '.
              $esc($case['quote_currency'])."\n".
              "💳 درگاه: NOWPayments\n".
              "🔖 سفارش: <code>".$esc($case['order_hash'])."</code>\n".
              "🔗 فاکتور: <code>".$esc($case['provider_reference'])."</code>\n".
              "📌 شناسه اصلی: <code>".$esc($case['payment_id']?:'ثبت نشده')."</code>\n".
              "🔍 وضعیت داخلی: <code>".$esc($case['stage'])."</code>\n".
              "🕓 آخرین تغییر: ".$esc($case['updated_at'])."\n\n".
              "<b>گزارش شناسه‌های پرداخت:</b>\n".
              ($cursor>0?"📄 ادامهٔ پرونده (شناسه‌های بعدی)\n":'');
        foreach ($children as $i=>$child) {
            $status=match ((string)$child['observed_status']) {
                'waiting'=>'در انتظار واریز؛ وجهی احراز نشده',
                'failed','expired'=>'ناموفق/منقضی؛ مدرک وصول نیست',
                'confirming'=>'شروع تأیید شبکه؛ هنوز نهایی نیست',
                'confirmed'=>'تأیید اولیه؛ تسویه نهایی نیست',
                'sending'=>'پرداخت در مسیر تسویه',
                'partially_paid'=>'وجه ناکافی/جزئی گزارش شده',
                'finished'=>'درگاه وضعیت نهایی گزارش کرده',
                'refunded'=>'بازپرداخت/وجه نیازمند تطبیق',
                default=>'وضعیت نامشخص؛ نیازمند استعلام',
            };
            $body.='• <code>'.$esc($child['payment_id']).'</code>: '.$status.
                ' | ثبت: '.$esc($child['received_at'])."\n";
        }
        if (!$children) $body.="هیچ شناسهٔ پرداختی ثبت نشده است.\n";
        if ($action==='Verify') {
            // Each callback makes AT MOST ONE provider GET (7s timeout),
            // avoiding a 5x7s webhook stall and allowing sequential review
            // of each child without storing broader merchant credentials.
            $apiKey=(string)($paymentKeys['nowpayment']??'');
            $body.="\n<b>استعلام فقط‌خواندنی از درگاه:</b>\n";
            if ($apiKey==='') {
                $body.="کلید درگاه موجود نیست؛ وضعیت نامشخص است.\n";
            } else {
                foreach (array_slice($children,$childIndex,1) as $child) {
                    $remote=achbotNpGetVerifiedPayment(
                        (string)$child['payment_id'],$apiKey);
                    if (!$remote ||
                        (string)($remote['payment_id']??'')!==(string)$child['payment_id'] ||
                        (string)($remote['invoice_id']??'')!==(string)$case['provider_reference'] ||
                        (string)($remote['order_id']??'')!==(string)$case['order_hash']) {
                        $body.='• <code>'.$esc($child['payment_id']).
                            "</code>: پاسخ معتبر و منطبق دریافت نشد.\n";
                        continue;
                    }
                    $raw=(string)($remote['payment_status']??'');
                    $kind=achbotNpChildEvidenceRank($raw)>0
                        ? 'شواهد واریز/تسویه (نیازمند تطبیق مبلغ)'
                        : 'مدرک واریز شناسایی نشد؛ امکان پرداخت دیرهنگام باقی است';
                    $body.='• <code>'.$esc($child['payment_id']).
                        '</code>: '.$esc($raw).' — '.$kind."\n";
                    // These monetary observations are provider-readonly,
                    // after strict payment/invoice/order identity matching.
                    $gross=(string)($remote['actually_paid']??'');
                    $asset=(string)($remote['pay_currency']??'');
                    $net=(string)($remote['outcome_amount']??'');
                    $netAsset=(string)($remote['outcome_currency']??'');
                    $validAmount=static fn($value): bool => preg_match(
                        '/^\d{1,18}(?:\.\d{1,18})?$/D',(string)$value)===1;
                    if (achbotNpChildEvidenceRank($raw)>0 &&
                        $validAmount($gross) &&
                        preg_match('/^[a-z0-9]{2,32}$/iD',$asset)) {
                        $body.='  مقدار گزارش‌شده: '.$esc($gross).' '.
                            $esc($asset)."\n";
                    }
                    if (achbotNpChildEvidenceRank($raw)>0 &&
                        $validAmount($net) &&
                        preg_match('/^[a-z0-9]{2,32}$/iD',$netAsset)) {
                        $body.='  خالص گزارش‌شده: '.$esc($net).' '.
                            $esc($netAsset)."\n";
                    }
                }
            }
        }
        $body.="\n⚠️ مشاهده یا استعلام، پرداخت را تأیید/رد نمی‌کند ".
            "و هیچ اعتبار، تحویل یا بازپرداختی انجام نمی‌دهد.";
        $pageSuffix=$cursor>0?'_0_'.$cursor:'';
        $buttons=[
            [['text'=>'🔄 تازه‌سازی اطلاعات',
              'callback_data'=>'npReviewRefresh_'.$attemptId.$pageSuffix]],
        ];
        if ($children) {
            $next=$action==='Verify'
                ? ($childIndex+1)%count($children) : 0;
            $buttons[]=[['text'=>'🔍 استعلام شناسهٔ '.($next+1).' از '.count($children),
                         'callback_data'=>'npReviewVerify_'.$attemptId.'_'.$next.
                             ($cursor>0?'_'.$cursor:'')]];
        }
        if ($nextCursor>0) {
            $buttons[]=[['text'=>'➡️ شناسه‌های بعدی',
                'callback_data'=>'npReviewCase_'.$attemptId.'_0_'.$nextCursor]];
        }
        $buttons[]=[['text'=>'📋 بازگشت به فهرست',
                     'callback_data'=>'npReviewInbox']];
        answerCallbackQuery($callbackId,'جزئیات محافظت‌شدهٔ پرونده بارگذاری شد.');
        editText($message_id,$body,json_encode(['inline_keyboard'=>$buttons],
            JSON_UNESCAPED_UNICODE),'HTML');
    } catch (Throwable $error) {
        error_log('achbot_payment_operator_inbox_unavailable');
        answerCallbackQuery($callbackId,
            'اطلاعات فعلاً قابل بازیابی نیست؛ بعداً دوباره تلاش کنید.',true);
    }
    return;
}

if (preg_match('/^approvePayment(.*)/', $data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $hash_id = $match[1];
    // Keep the existing receipt display identifiers for post-commit updates.
    $stmt = $connection->prepare('SELECT * FROM pays WHERE hash_id=? LIMIT 1');
    $stmt->bind_param('s', $hash_id);
    $stmt->execute();
    $payInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$payInfo) {
        alert("⛔️ اطلاعات پرداخت پیدا نشد.");
        return;
    }
    $approved = achbotApproveManualWalletTopup($connection, $hash_id, (int)$from_id);
    if ($approved === null) {
        alert("⛔️ این رسید قبلاً بررسی شده، قابل تأیید نیست یا به بررسی پشتیبانی نیاز دارد.");
        return;
    }

    // These values are read from the committed transaction, not from stale
    // Telegram buttons or a pre-commit estimate.
    $price = $approved['price'];
    $userId = $approved['user_id'];
    $currentWallet = $approved['wallet_before'];
    $newWallet = $approved['wallet_after'];
    $methodName = achbotManualLabel(
        achbotManualSelectedMethod($connection, $payInfo) ?? 'bank_transfer'
    );

    $keys = json_encode([
        'inline_keyboard' => [
            [['text' => "✅ تایید شد توسط $from_id", 'callback_data' => "wizwizch"]],
            [['text' => "🆔 $hash_id", 'callback_data' => "noop_$hash_id"]]
        ]
    ], 488);

    // ویرایش پیام ادمین اصلی
    try {
        editKeys($keys, $payInfo['message_id'], $payInfo['chat_id']);
    } catch (Throwable $ignored) {
        // Keep the buyer notification attempt even if editing Telegram fails.
    }

    // ویرایش پیام سایر ادمین‌ها
    $adminMessages = json_decode($payInfo['admins_messages'], true);
    if (is_array($adminMessages)) {
        foreach ($adminMessages as $msgInfo) {
            if (
                isset($msgInfo['chat_id'], $msgInfo['message_id']) &&
                $msgInfo['chat_id'] != $payInfo['chat_id']
            ) {
                try {
                    editKeys($keys, $msgInfo['message_id'], $msgInfo['chat_id']);
                } catch (Throwable $ignored) {
                    // One stale administrator message must not block the buyer.
                }
            }
        }
    }

    // ارسال پیام به ادمین‌ها
    $adminMessages = "✅ تأیید دستی {$methodName} و افزایش موجودی کاربر $userId انجام شد.

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

if (preg_match('/^declineActionReason_(.+)_(\d+)$/', $userInfo['step'], $match) &&
    $text != $buttonValues['cancel'] &&
    ($from_id == $admin || !empty($userInfo['isAdmin']))) {
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
    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'declined', `approved_by` = ?
        WHERE `hash_id` = ? AND `state` = 'have_sent'");
    $stmt->bind_param("is", $from_id, $hash_id);
    $stmt->execute();
    $declined = $stmt->affected_rows === 1;
    $stmt->close();
    if (!$declined) {
        alert("⛔️ وضعیت درخواست هنگام بررسی تغییر کرده است؛ برای جلوگیری از تداخل با پشتیبانی بررسی کنید.");
        return;
    }
	
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

