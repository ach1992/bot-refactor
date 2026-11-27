<?php
require_once __DIR__.'/../../Admin/Access.php';
require_once __DIR__.'/../../Telegram/Conversation.php';
/** Current manual receipt rejection and recovery routes. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../../messages/loader.php'; // ACHBOT_TEXT_CATALOG

if (!achbotAdminRequestAllowed(is_string($data??null)?$data:null,is_string($text??null)?$text:null,(string)($userInfo['step']??''))) {achbotAdminDeny();return;}


// Keep hosted-gateway exceptions outside the manual receipt handler.
$adminCallback=(string)($data??'');
// These are all action families issued by the same payments-attention inbox.
// Forward the actual Telegram callback, not just the inbox/card callbacks;
// the receiving route performs fresh payment, actor and Native checks.
if (str_starts_with($adminCallback,'attentionInbox') ||
    str_starts_with($adminCallback,'attentionCase_') ||
    str_starts_with($adminCallback,'attentionOpenCase_') ||
    str_starts_with($adminCallback,'attentionReceipt_') ||
    str_starts_with($adminCallback,'attentionDelivery') ||
    str_starts_with($adminCallback,'attentionService') ||
    str_starts_with($adminCallback,'attentionReport') ||
    preg_match('/^gatewayReview_[ARV]_[1-9][0-9]{0,17}$/D',$adminCallback)===1) {
    require __DIR__.'/AutomaticGatewayReview.php';
    return;
}

// An existing authenticated bank/gift API proof can finish the ORIGINAL
// payment. No provider GET, second financial ledger, or new access grant.
if (preg_match('/^apiEvidenceResume_([1-9][0-9]{0,17})$/D',
    (string)$data,$apiResume)) {
    if (!achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'payments') ||
        (int)($chat_id??0)!==(int)$from_id) {
        answerCallbackQuery($callbackId,achbotMessage('manual_payment.manual_receipts.alert.0a23f88410'),true);
        return;
    }
    $payId=(int)$apiResume[1];
    try {
        // The direct callback may be the first admin route in this process.
        // Do not depend on having opened the attention inbox beforehand.
        require_once __DIR__.'/../ApiPayments.php';
        $pay=achbotApiPaymentRow($connection,$payId);
        $attempt=$pay?achbotApiPaymentAttempt($connection,$payId):null;
        $proof=$pay?achbotPaymentEvidenceGetProof($connection,$payId):null;
        if (!$pay || !$attempt) {
            answerCallbackQuery($callbackId,achbotMessage('manual_receipt_final.status_stale'),true);
            return;
        }
        $pay['attempt']=$attempt;
        // Previously issued callbacks can safely revisit an already terminal
        // invoice: the original coordinator dispatches only PENDING delivery
        // packets, never money/native effects or UNKNOWN Telegram sends.
        $terminalDeliveryOnly=achbotApiPaymentTerminal($pay) &&
            achbotCreditMethodHas((string)$attempt['provider'],'api_checker') &&
            ($proof['mode']??null)==='settle';
        if ((!achbotApiPaymentCanResume($connection,$pay) && !$terminalDeliveryOnly) ||
            !is_array($proof)) {
            answerCallbackQuery($callbackId,achbotMessage('manual_receipt_final.status_stale'),true);
            return;
        }
        answerCallbackQuery($callbackId,achbotMessage('manual_receipt_final.checking'));
        $outcome=achbotApiPaymentSettle($connection,$payId,$proof);
        $result=achbotCheckoutReceiptOutcomeText(in_array($outcome,['confirmed','settled'],true)?
            achbotCheckoutReceiptOutcome($connection,$payId):'unknown');
        // This action began inside the shared attention inbox. Its result
        // always returns there, even if the original case is now closed.
        $rows=[[['text'=>achbotMessage('manual_receipt_final.back'),
            'callback_data'=>'attentionInbox']]];
        // A deferred result is NOT a terminal denial: expose the original
        // case directly, where the next confirmed preview uses fresh evidence.
        require_once __DIR__.'/../../Services/Resolution.php';
        require_once __DIR__.'/../GatewayClient.php';
        $serviceId=(int)($pay['checkout_operation_id']??0);
        if ($serviceId>0 && achbotServiceAttentionCaseOpen($connection,$serviceId))
            array_unshift($rows,[['text'=>achbotMessage('automatic_gateway_review.open_case'),
                'callback_data'=>'attentionService_'.$serviceId]]);
        elseif (!in_array($outcome,['confirmed','settled'],true) && achbotGatewayAttentionCaseOpen($connection,$payId))
            array_unshift($rows,[['text'=>achbotMessage('automatic_gateway_review.redrive_cancel'),
                'callback_data'=>'attentionCase_'.$payId]]);
        $buttons=json_encode(['inline_keyboard'=>$rows],
            JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $text=achbotMessageHtml('manual_receipt_final.result',['result'=>$result]);
        \AchBot\Telegram\Conversation\replaceTransient((int)$from_id,(int)$message_id,$text,$buttons);
    } catch(Throwable $error) {
        error_log('achbot_api_review_resume_unavailable pay_id='.$payId.
            ' class='.get_class($error));
        answerCallbackQuery($callbackId,achbotMessage('manual_receipt_final.status_unavailable'),true);
    }
    return;
}

// Decline Cart To Cart by ACh

if (preg_match('/^noop_(.+)$/', $data, $m)) {
	$hash_id = $m[1];
	sendMessage(\achbotMessage('manual_payment.manual_receipts.hash__i_d_hash_id.0ce5b30b71', ['hash_id' => $hash_id]), null, "HTML", $from_id);
	exit;
}

if (preg_match('/^declineAction_([A-Za-z0-9_-]{1,50})$/D', (string)$data, $match) && achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'payments')) {
    if (!\AchBot\Telegram\Conversation\source($update,(int)$from_id,true)) return;
    $hash_id = $match[1];

    // بررسی وجود و وضعیت پرداخت
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $hash_id);
    $stmt->execute();
    $payInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();

	if (!$payInfo || ($payInfo['state'] === 'expired_have_sent')) {
		alert(!$payInfo ? \achbotMessage('manual_payment.manual_receipts.alert.9e5ae0bfa1') : \achbotMessage('manual_payment.manual_receipts.alert.caa1f4e27c'));
		return;
	}

    if ($payInfo['state']==='declined' && (int)$payInfo['approved_by']>0) {
        require_once __DIR__.'/../FollowupOutbox.php';
        if (achbotFollowupSchemaReady($connection))
            achbotFollowupDispatchPending($connection,(int)$payInfo['id'],'manual_reject');
        return;
    }
    if (!in_array($payInfo['state'],['have_sent','service_superseded'],true)) {
        // Never reverse a potentially delivered Processing/native operation.
        alert(\achbotMessage('manual_payment.manual_receipts.alert.af4cc41dc0'));
        return;
    }

    // All manual receipt methods/subjects use one reason-first rejection
    // workflow. This callback never settles or rejects the original payment.
    // Keep the original pay and staff identity; the typed reply is rechecked
    // against the current financial row by the guarded transaction below.
    if (!setUser('declineActionReason_' . $hash_id . '_' . $message_id)) return;
    sendMessage(\achbotMessage('manual_payment.manual_receipts.message.a757d0e2a6'),$cancelKey, 'HTML');
    return;
}

if (preg_match('/^declineActionReason_(.+)_(\d+)$/', (string)($userInfo['step']??''), $match) &&
    (string)($data??'')==='' &&
    $text != \achbotMessage('legacy_buttons.cancel') &&
    achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'payments')) {
    if (!\AchBot\Telegram\Conversation\source($update,(int)$from_id,false)) return;
    // Only a freshly typed administrator message can supply the reason;
    // a callback's previously rendered message must not finalize rejection.
    if (!is_string($text) || trim($text)==='') {
        sendMessage(\achbotMessage('manual_payment.manual_receipts.message.a757d0e2a6'),$cancelKey, 'HTML');
        return;
    }
    // This webhook's cached step may already have been canceled or replaced.
    // Consume it only when the same financial transaction verifies it still
    // belongs to this exact administrator decision.
    $hash_id = $match[1];
    $message_id = $match[2];

    // دریافت اطلاعات پرداخت
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $hash_id);
    $stmt->execute();
    $payInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$payInfo || !in_array($payInfo['state'],['have_sent','service_superseded'],true)) {
        // The pending reason is obsolete, not a new financial rejection.
        // A stale retry must not clear a newer editor or replay a notice.
        if (\AchBot\Telegram\Conversation\transitionEditor($connection,(int)$from_id,$userInfo) !== null)
            \AchBot\Telegram\Conversation\removeReplyKeyboard(
                achbotMessage('manual_receipt_final.status_unavailable'),(int)$from_id);
        if ($payInfo && $payInfo['state']==='declined' && (int)$payInfo['approved_by']>0) {
            require_once __DIR__.'/../FollowupOutbox.php';
            if (achbotFollowupSchemaReady($connection))
                achbotFollowupDispatchPending($connection,(int)$payInfo['id'],'manual_reject');
        }
        return;
    }

    $uid = $payInfo['user_id'];
    $price = !empty($payInfo['price']) ? number_format($payInfo['price']) . \achbotMessage('bot.bot.webhook_ingress_authenticated.text.2cc98f7f7c') : "---";
    $reason = htmlspecialchars((string)$text, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
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

    // No Telegram side effects before the financial CAS and complete original
    // outbox are committed. A second click only resumes PENDING rows.
    require_once __DIR__.'/../FollowupOutbox.php';
    if (!achbotFollowupSchemaReady($connection)) {
        error_log('achbot_manual_reject_outbox_unavailable');
        alert(\achbotMessage('manual_payment.manual_receipts.alert.872f260882'));
        return;
    }
    $retryButton=json_encode(['inline_keyboard'=>[[['text'=>
        \achbotMessage('manual_payment.manual_receipts.button.e3517e568b'),
        'url'=>"https://t.me/$botUsername?start=restart_$hash_id"]]]],
        JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    // The reason is administrator-authored HTML data, never markup. Preserve
    // it in full; only a display-only subscription name may be shortened.
    $payId=(int)$payInfo['id'];
    $customerNotice=static function(string $name) use ($reason,$price,$hash_id): string {
        return \achbotMessage('manual_payment.manual_receipts.message.bc0155976d',
            ['reason'=>$reason]).
            \achbotMessage('manual_payment.manual_receipts.message.c4d810a7d3',
                ['price'=>$price]).
            \achbotMessageHtml('manual_payment.manual_receipts.message.61d75413a2',
                ['remark'=>$name]).
            \achbotMessageHtml('manual_payment.manual_receipts.message.df4f415ed5',
                ['hash_id'=>$hash_id]);
    };
    $operatorNotice=static function(string $name) use ($payId,$uid,$payInfo,$hash_id,$from_id): string {
        return \achbotMessage('manual_receipt_final.status_rejected')."\n".
            \achbotMessageHtml('manual_receipt_final.rejected_report',[
                'id'=>$payId,'user'=>(int)$uid,
                'amount'=>number_format((int)$payInfo['price']),'hash'=>$hash_id])."\n".
            \achbotMessageHtml('manual_payment.manual_receipts.message.61d75413a2',
                ['remark'=>$name])."\n".achbotMessageHtml('operational_reports.actor',['actor'=>(int)$from_id]);
    };
    // Telegram bounds parsed HTML in UTF-16 units (4096); escaping an
    // otherwise valid reason/name can exceed that limit. Never COMMIT a
    // rejection whose original customer/operator notice cannot be sent.
    $telegramUnits=static function(string $html): int {
        $plain=html_entity_decode(strip_tags($html),ENT_QUOTES|ENT_HTML5,'UTF-8');
        $characters=preg_match_all('/./us',$plain);
        $astral=preg_match_all('/[\x{10000}-\x{10FFFF}]/u',$plain);
        return $characters===false || $astral===false ? PHP_INT_MAX : $characters+$astral;
    };
    $message=$customerNotice($remark);
    $adminReport=$operatorNotice($remark);
    if ($telegramUnits($message)>4096 || $telegramUnits($adminReport)>4096) {
        // Keep the largest readable display prefix and the exact reason,
        // original payment and database description untouched.
        // PCRE Unicode splitting works even in the minimal PHP 8.5
        // fixture/runtime without an optional mbstring extension.
        $codepoints=preg_split('//u',(string)$remark,-1,PREG_SPLIT_NO_EMPTY);
        if (!is_array($codepoints)) $codepoints=[];
        $low=0;$high=count($codepoints);
        while ($low<$high) {
            $mid=intdiv($low+$high+1,2);
            $preview=implode('',array_slice($codepoints,0,$mid)).'…';
            if ($telegramUnits($customerNotice($preview))<=4096 &&
                $telegramUnits($operatorNotice($preview))<=4096) $low=$mid;
            else $high=$mid-1;
        }
        $displayName=($low>0?implode('',array_slice($codepoints,0,$low)):'').'…';
        $message=$customerNotice($displayName);
        $adminReport=$operatorNotice($displayName);
        if ($telegramUnits($message)>4096 || $telegramUnits($adminReport)>4096) {
            // The reason itself is too large. Keep the editor open and ask
            // for a shorter message BEFORE any irreversible financial state.
            // The persisted editor remains open; no unconditional rewrite.
            sendMessage(\achbotMessage('broadcast.routes.pm_noop.message.72fe3f7c5d')."\n".
                \achbotMessage('manual_payment.manual_receipts.message.a757d0e2a6'),$cancelKey, 'HTML');
            return;
        }
    }
    $adminAfterSendKeys=json_encode(['inline_keyboard'=>[[['text'=>
        \achbotMessage('manual_payment.manual_receipts.button.7918a2f411'),
        'callback_data'=>'banUserDirect_'.$uid]]]],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    // These messages are staged BEFORE Telegram is called. Never assert that
    // the customer's rejection notice was delivered: its ACK may be unknown.
    require_once __DIR__.'/../CheckoutRoutes.php';
    require_once __DIR__.'/../GatewayClient.php';
    $lease=achbotOriginalDispatchLeaseBegin($connection,$payId);
    if ($lease!=='owned') {
        alert(achbotMessage('manual_payment.manual_receipts.alert.872f260882'));return;
    }
    $opened=false;
    try {
        if (!$connection->begin_transaction()) throw new RuntimeException('manual_reject_begin');
        $opened=true;
        $actor=(int)$from_id;
        $locked=achbotCheckoutRejectReceiptLocked($connection,$payId,(int)$uid,$actor,(int)$admin,(string)$hash_id);
        // Consume only the current private reason editor; rollback also
        // restores the payment/command if another editor replaced it.
        $editor=\AchBot\Telegram\Conversation\lockEditor($connection,$actor,$userInfo);
        \AchBot\Telegram\Conversation\writeEditorLocked($connection,$editor,'none',null);
        require_once __DIR__.'/../ReceiptReports.php';
        $receiptAttempt=achbotProviderRow($connection,'SELECT * FROM payment_attempts WHERE pay_row_id=? FOR UPDATE','i',[$payId]);
        achbotReceiptStageTerminalEditsLocked($connection,$locked,$receiptAttempt);
        achbotFollowupInsertLocked($connection,$locked,'manual_reject',
            'customer','send',(string)(int)$uid,null,
            ['text'=>$message,'keys'=>$retryButton,'mode'=>'HTML']);
        achbotFollowupStageAdminReportLocked($connection,$payId,null,'manual_reject',
            achbotPaymentEvidencePayFingerprint($locked),(int)$admin,(string)($GLOBALS['botState']['rewardChannel']??''),
            ['text'=>$adminReport,'keys'=>null,'mode'=>'HTML'],$actor,null,$adminAfterSendKeys);
        if (!$connection->commit()) throw new RuntimeException('manual_reject_commit_unknown');
        $opened=false;
    } catch(Throwable $error) {
        if ($opened) try {$connection->rollback();} catch(Throwable $ignored) {}
        // Preserve a bounded, non-secret reason for operator diagnostics.
        $code=in_array($error->getMessage(),[
            'manual_reject_stale',
            'manual_reject_actor_revoked','manual_reject_editor_stale',
            'manual_reject_cas_failed','manual_reject_original_evidence_pending',
        ],true)?$error->getMessage():'transaction_unconfirmed';
        error_log('achbot_manual_reject_claim_unconfirmed pay_id='.$payId.
            ' code='.$code.' class='.get_class($error));
        alert(\achbotMessage('manual_payment.manual_receipts.alert.872f260882'));
        return;
    } finally {achbotOriginalDispatchLeaseEnd($connection,$payId,$lease);}
    \AchBot\Telegram\Conversation\removeReplyKeyboard(
        achbotMessage('legacy_messages.saved_successfuly'),(int)$from_id);
    // An edit exception never suppresses independent customer/staff notices.
    // Missing ACK is UNKNOWN, not permission for a duplicate message.
    // The reason/retry instruction is independent meaningful information.
    // The acknowledged receipt status still converges on the same decision.
    achbotPayUxSyncMessages($connection,$payId);
    achbotFollowupDispatchPending($connection,$payId,'manual_reject');

}

if (is_string($data) &&
    preg_match('/\AbanUserDirect_([1-9][0-9]{0,18})\z/D',$data,$m)===1) {
    $status=achbotLegacyModerationTransition(
        $connection,$update,(int)$from_id,(int)$chat_id,(int)$message_id,
        $userInfo,(int)$admin,'receiptBan',$m[1]);
    if ($status==='changed') {
        alert(\achbotMessage('manual_payment.manual_receipts.alert.61d5318e58', ['m_1' => $m[1]]));
    } elseif ($status==='already') {
        alert(\achbotMessage('manual_payment.manual_receipts.alert.8b8e660e87'));
    } else {
        alert(\achbotMessage('manual_payment.manual_receipts.alert.abf0b6c70d'),true);
    }
    // Do not claim or mark a failed/unknown callback as committed.
    if ($status==='changed' || $status==='already') {
        $doneKeys=json_encode(['inline_keyboard'=>[
            [['text'=>\achbotMessage('manual_payment.manual_receipts.button.85f4d0ad41', ['m_1' => $m[1]]),'callback_data'=>"wizwizch"]]
        ]]);
        editKeys($doneKeys,$message_id,$chat_id);
    }
}

// END Decline Cart To Cart by ACh

