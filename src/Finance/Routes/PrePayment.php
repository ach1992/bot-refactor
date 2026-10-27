<?php
/** AChBot V8: source-pinned financial compatibility module. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../../messages/loader.php'; // ACHBOT_TEXT_CATALOG


require_once __DIR__ . '/../PaymentLifecycle.php';

// Read-only status inspection: never contacts a provider or changes money.
if (preg_match('/^ps_([A-Za-z0-9_-]{1,50})$/D', $data, $statusMatch)) {
    $hashId = $statusMatch[1];
    // Full local identity is required for same-family/same-order supersession.
    // A reduced projection silently treats nonzero order_id as zero.
    $stmt = $connection->prepare("SELECT * FROM pays WHERE hash_id=? LIMIT 1");
    $stmt->bind_param('s', $hashId);
    $stmt->execute();
    $pay = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$pay || (int)$pay['user_id'] !== (int)$from_id) {
        answerCallbackQuery($callbackId, \achbotMessage('payment.pre_payment.alert.8fbaa8aebc'));
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
    $methodName = $provider === 'manual_trx' ? \achbotMessage('payment.pre_payment.text.ea9faa7b63') :
        ($provider === 'manual_usdt' ? \achbotMessage('payment.pre_payment.text.16b71e846a') : ($provider === 'gift_card' ? \achbotMessage('payment.payment_lifecycle.pay_ux_audience_methods.text.3904ef0f3d') :
        ($provider === 'bank_transfer' ? \achbotMessage('payment.pre_payment.text.ace4b5ad89') :
        ($provider === 'nowpayments' ? \achbotMessage('payment.pre_payment.text.46daee8b43') :
        ($provider === 'nextpay' ? 'NextPay' :
        ($provider === 'zarinpal' ? 'Zarinpal' : \achbotMessage('payment.pre_payment.text.f07a81a062')))))));
    // A newer order invalidates the old call-to-action even before the next
    // one-minute maintenance tick. Keep any issued provider claim intact.
    try { $pay['superseded']=achbotPayUxHasNewer($connection,$pay); }
    catch (Throwable $ignored) { $pay['superseded']=true; }
    $body = achbotPayUxStatusText($pay,$attempt) .
        \achbotMessage('payment.pre_payment.text.c8886e46d5') . htmlspecialchars($methodName,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $uiState = achbotPayUxState($pay,$attempt);
    if (in_array((string)$pay['state'],['approved','auto_cart_approved'],true) &&
        preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D',
                   (string)($pay['updated_at'] ?? ''))) {
        $body .= \achbotMessage('payment.pre_payment.body.ff6ceb47bf') .
            htmlspecialchars((string)$pay['updated_at'],ENT_QUOTES,'UTF-8') . "</code>";
    }
    $actions=[];
    if (!in_array($uiState,['expired','declined','payment_recorded'],true)) {
        $actions[]=[['text'=>\achbotMessage('payment.pre_payment.button.ea1db2132d'),'callback_data'=>'ps_'.$hashId]];
    }
    $actions[]=[['text'=>\achbotMessage('payment.payment_lifecycle.pay_ux_status_keyboard.button.1121ffbf88'),'callback_data'=>'ph_'.$hashId]];
    $keyboard=inlineKeyboard($actions);
    answerCallbackQuery($callbackId, \achbotMessage('payment.pre_payment.alert.fdf0d1d6f9'));
    // The clicked Telegram message is our canonical status card; never
    // manufacture a second status message on every refresh.
    editText($message_id, $body, $keyboard, 'HTML', $from_id);
    return;
}

// Pre Pay Check By ACh

// Only a complete, valid Telegram prepayment frame may be dispatched.
// Consume malformed callbacks in this namespace before bot.php can pass
// them to unrelated financial handlers with legacy substring matching.
if (is_string($data) && str_starts_with($data, 'prePayCheck_')) {
    if (strlen($data)>64 ||
        !preg_match('/^prePayCheck_([a-zA-Z0-9]+)_([a-zA-Z0-9]+)_([a-zA-Z0-9]{1,50})$/D',
                    $data, $match)) {
        answerCallbackQuery($callbackId,
            \achbotMessage('payment.pre_payment.alert.d4b0660226'), true);
        return;
    }
    $actionType = $match[1];
    $payMethod  = strtolower($match[2]);
    $hash_id    = $match[3];

    // Only recognized first-party methods may enter payment-state handling.
    // Unknown/retired callbacks fail closed BEFORE reading or editing an order.
    if (!in_array($payMethod, [
        'zarinpal','nowpayment','nextpay','wallet',
        'carttocart','giftcard','manualusdt'
    ],true)) {
        answerCallbackQuery($callbackId,\achbotMessage('payment.pre_payment.alert.192c7f896f'),true);
        return;
    }

    $stmt = $connection->prepare("SELECT * FROM pays WHERE hash_id = ?");
    $stmt->bind_param("s", $hash_id);
    $stmt->execute();
    $payInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$payInfo) {
        delMessage();
        sendMessage(\achbotMessage('payment.pre_payment.message.6fe859eb01'), getMainKeys());
        exit;
    }

    // A stale Telegram payment button must not allow another user to
    // expire, re-route or reveal the original buyer's payment information.
    if ((int)($payInfo['user_id'] ?? 0) <= 0 ||
        (int)$payInfo['user_id'] !== (int)$from_id) {
        answerCallbackQuery($callbackId, \achbotMessage('payment.pre_payment.alert.4b56f62df2'), true);
        return;
    }

    // A callback button can survive a provider settlement indefinitely.
    // Reject ALL terminal states before checking plans, expiry or gateways.
    // Never let a stale Telegram callback rewrite a paid/terminal receipt.
    if (!in_array((string)$payInfo['state'], ['pending','send'], true)) {
        answerCallbackQuery($callbackId, \achbotMessage('payment.pre_payment.alert.bf99a9262b'));
        return;
    }

    // Even an old forged callback must honor the current audience policy.
    $policyMethod=match ($payMethod) {
        'nowpayment'=>'nowpayments','zarinpal'=>'zarinpal','nextpay'=>'nextpay',
        'wallet'=>'wallet','carttocart'=>'bank_transfer','giftcard'=>'gift_card',
        'manualusdt'=>'manual_usdt','manualtrx'=>'manual_trx',
        default=>null
    };
    // ManualUsdt is a historical SHARED crypto-selector callback. A TRX-only
    // account must reach the menu; per-network authorization is enforced by
    // ManualCrypto.php and again at the transactional manual-claim boundary.
    $methodAllowed=$payMethod==='manualusdt'
        ? (achbotPayUxAudienceAllowed($connection,'manual_usdt',(int)$from_id) ||
           achbotPayUxAudienceAllowed($connection,'manual_trx',(int)$from_id))
        : ($policyMethod===null ||
           achbotPayUxAudienceAllowed($connection,$policyMethod,(int)$from_id));
    if (!$methodAllowed) {
        answerCallbackQuery($callbackId,\achbotMessage('payment.pre_payment.alert.cb95ca3290'),true);
        return;
    }
    if (achbotPayUxExpireDraft($connection,(int)$payInfo['id'])) {
        answerCallbackQuery($callbackId,\achbotMessage('payment.pre_payment.alert.15d949aabf'),true);
        editText($message_id,
            \achbotMessage('payment.pre_payment.message.e3aaf3c571'),
            achbotPayUxStatusKeyboard((string)$payInfo['hash_id']), 'HTML',$from_id);
        achbotPayUxTrackClicked($connection,(int)$payInfo['id'],(int)$from_id,(int)$message_id);
        return;
    }
    $subjectSupported=achbotSupportsPaymentSubject($connection,$payInfo);
    // A changed RENEW_ACCOUNT plan is deliberately handled below with its
    // own owner/provider preflight; other unsupported subjects fail here.
    if (!$subjectSupported && $payInfo['type']!=='RENEW_ACCOUNT') {
        alert(\achbotMessage('payment.pre_payment.alert.fa55f5201f'), true);
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
                answerCallbackQuery($callbackId,\achbotMessage('payment.pre_payment.alert.6871e8bfbf'),true);
                editText($message_id,
                    \achbotMessage('payment.pre_payment.message.904e919e0e'),
                    achbotPayUxStatusKeyboard((string)$payInfo['hash_id']),
                    'HTML',$from_id);
                achbotPayUxTrackClicked($connection,(int)$payInfo['id'],(int)$from_id,(int)$message_id);
                return;
            }
        } catch (Throwable $ignored) {
            answerCallbackQuery($callbackId,\achbotMessage('payment.pre_payment.alert.e3336d72e2'),true);
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
                !achbotSupportsOrder($connection,$oid,null,$userId) ||
                !achbotSupportsPlan($connection,$savedFid,0,$payInfo['server_id']??null)) {
                alert(\achbotMessage('payment.pre_payment.alert.fa55f5201f'),true);
                return;
            }

            // Even a pending row can already have a provider-issued token.
            // Never invalidate that evidence or issue a competing checkout.
            $claimedInvoice=$paymentAttempt!==null ||
                (string)($payInfo['payid']??'')!=='' ||
                (string)$payInfo['state']!=='pending';
            if ($claimedInvoice) {
                answerCallbackQuery($callbackId,\achbotMessage('payment.pre_payment.alert.b801192b87'));
                // Do not advertise a still-payable link for a now-mismatched
                // plan: retain the invoice ID/URL for operator reconciliation.
                sendMessage(\achbotMessage('payment.pre_payment.message.34461be871'),
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
                ? \achbotMessage('payment.pre_payment.message.6a728137d5')
                : \achbotMessage('payment.pre_payment.message.f7acca2890'),
                getMainKeys());
            return;
        }
        if (!$subjectSupported) {
            alert(\achbotMessage('payment.pre_payment.alert.fa55f5201f'),true);
            return;
        }
    }

    // The same order cannot be reissued with a different payment provider.
    $selectedProvider=$payMethod==='nowpayment' ? 'nowpayments' : $payMethod;
    if ($activeGatewayClaim && $selectedProvider!==(string)$paymentAttempt['provider']) {
        answerCallbackQuery($callbackId,\achbotMessage('payment.pre_payment.alert.a421affc40'));
        $invoiceUrl=(string)($paymentAttempt['checkout_url']??'');
        if ($paymentAttempt['provider']==='nowpayments' && $invoiceUrl!=='' &&
            function_exists('achbotNpValidInvoiceUrl') &&
            achbotNpValidInvoiceUrl($invoiceUrl)) {
            $keyboard=json_encode(['inline_keyboard'=>[[
                ['text'=>\achbotMessage('payment.pre_payment.button.9638a59ddc'),'url'=>$botUrl.'pay/?nowpayment&hash_id='.urlencode($hash_id)]
            ]]],JSON_UNESCAPED_UNICODE);
            sendMessage(\achbotMessage('payment.pre_payment.message.08a2b0a6f6'),
                        $keyboard,"html",$from_id);
        } else {
            sendMessage(\achbotMessage('payment.pre_payment.message.59e3040876'),
                        getMainKeys(),null,$from_id);
        }
        return;
    }

    if ($now-(int)$payInfo['request_date'] >= achbotPayUxDraftDeadlineSeconds()
        && !$activeGatewayClaim) {
        // The guarded expiry may have failed because the original payment
        // was claimed concurrently. Never bypass that financial guard.
        answerCallbackQuery($callbackId,
            \achbotMessage('payment.pre_payment.alert.5ba8749860'),true);
        return;
    }

    // اگر روش پرداخت ریدایرکتی بود
	if (in_array($payMethod, ["zarinpal", "nowpayment", "nextpay"])) {
		$redirectUrl = $botUrl . "pay/?$payMethod&hash_id=$hash_id";

		// دکمه با لینک مستقیم به درگاه
        $button = [
            'inline_keyboard'=>[
                [['text'=>\achbotMessage('payment.pre_payment.button.f81a8cfcdd'),'url'=>$redirectUrl]],
                [['text'=>\achbotMessage('payment.payment_lifecycle.pay_ux_status_keyboard.button.9e25b02e86'),'callback_data'=>'ps_'.$hash_id]]
            ]
        ];
        answerCallbackQuery($callbackId,\achbotMessage('payment.pre_payment.alert.093b359bab'));
        $extra = $payMethod==='nowpayment'
            ? \achbotMessage('payment.pre_payment.text.52bdfa0dce')
            : \achbotMessage('payment.pre_payment.text.64b1e46fd3');
        $sent = sendMessage(
            \achbotMessage('payment.pre_payment.message.b3b3cf5aae').achbotPayUxReference($payInfo).$extra.\achbotMessage('payment.pre_payment.message.69b0efb715'),
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
        sendMessage(\achbotMessage('payment.pre_payment.message.526bf7b198'), getMainKeys());
        exit;
    }

    $data = $map[$payMethod];
}

