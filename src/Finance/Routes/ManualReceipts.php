<?php
require_once __DIR__.'/../../Admin/Access.php';
/** AChBot V8: source-pinned financial compatibility module. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../../messages/loader.php'; // ACHBOT_TEXT_CATALOG

if (!achbotAdminRequestAllowed(is_string($data??null)?$data:null,is_string($text??null)?$text:null,(string)($userInfo['step']??''))) {achbotAdminDeny();return;}


// The hosted-gateway exception UI is independent of manual receipt approval.
if ((require __DIR__.'/AutomaticGatewayReview.php') === null) return;

// An existing authenticated bank/gift API proof can finish the ORIGINAL
// payment. No provider GET, second financial ledger, or new access grant.
if (preg_match('/^apiEvidenceResume_([1-9][0-9]{0,17})$/D',(string)$data,$apiResume)) {
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
            in_array((string)$attempt['provider'],['bank_transfer','gift_card'],true) &&
            ($proof['mode']??null)==='settle';
        if ((!achbotApiPaymentCanResume($connection,$pay) && !$terminalDeliveryOnly) ||
            !is_array($proof)) {
            answerCallbackQuery($callbackId,achbotMessage('manual_receipt_final.status_stale'),true);
            return;
        }
        answerCallbackQuery($callbackId,achbotMessage('manual_receipt_final.checking'));
        $outcome=achbotApiPaymentSettle($connection,$payId,$proof);
        // A settled invoice is not necessarily an ACKed customer message.
        // The terminal fast-path returns 'settled' after pending-only delivery;
        // inspect the original per-message journal before reporting success.
        $acknowledged=false;
        if (in_array($outcome,['confirmed','settled'],true)) {
            $delivery=achbotDeliveryAggregate($connection,$payId);
            $acknowledged=$delivery && (int)$delivery['total']>0 &&
                (int)$delivery['ack']===(int)$delivery['total'];
        }
        $result=$acknowledged
            ? achbotMessage('manual_receipt_final.status_completed')
            : (in_array($outcome,['confirmed','settled'],true)
                ? achbotMessage('manual_receipt_final.status_delivery_unconfirmed')
                : achbotMessage('manual_receipt_final.status_unknown'));
        // The former financial case is no longer in its inbox after
        // processing/terminal completion. Return to an always-valid list.
        $buttons=json_encode(['inline_keyboard'=>[[['text'=>achbotMessage('manual_receipt_final.back'),
            'callback_data'=>'managePanel']]]],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $text=achbotMessageHtml('manual_receipt_final.result',['result'=>$result]);
        $reply=editText($message_id,$text,$buttons,'HTML',(int)$from_id);
        if (!is_object($reply)||($reply->ok??false)!==true)
            sendMessage($text,$buttons,'HTML',(int)$from_id);
    } catch(Throwable $error) {
        error_log('achbot_api_review_resume_unavailable pay_id='.$payId.
            ' class='.get_class($error));
        answerCallbackQuery($callbackId,achbotMessage('manual_receipt_final.status_unavailable'),true);
    }
    return;
}

if (preg_match('/^approvePayment(.*)/', $data, $match) && achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'payments')) {
    $hash_id = $match[1];
    // Keep the existing receipt display identifiers for post-commit updates.
    $stmt = $connection->prepare('SELECT * FROM pays WHERE hash_id=? LIMIT 1');
    $stmt->bind_param('s', $hash_id);
    $stmt->execute();
    $payInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$payInfo) {
        alert(\achbotMessage('manual_payment.manual_receipts.alert.9e5ae0bfa1'));
        return;
    }
    $approved = achbotApproveManualWalletTopup($connection, $hash_id, (int)$from_id, (int)$admin);
    if ($approved === null) {
        alert(\achbotMessage('manual_payment.manual_receipts.alert.2d252fd96f'));
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

    require_once __DIR__.'/../ApiPaymentPresentation.php';
    $keys=achbotManualFinalReceiptKeyboard(
        $connection,(int)$payInfo['id'],payTypeToDescription('INCREASE_WALLET'));

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
                $msgInfo['chat_id'] != $payInfo['chat_id'] &&
                achbotAdminStoredRecipientAllowed($connection,$msgInfo['chat_id'],(int)$admin)
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
    $adminMessages = \achbotMessage('manual_payment.manual_receipts.admin_messages.31d90c5e69', ['methodName' => $methodName, 'userId' => $userId]) . number_format($currentWallet) . \achbotMessage('manual_payment.manual_receipts.text.c0780ce268') . number_format($newWallet) . \achbotMessage('manual_payment.manual_receipts.text.89a4910ebe', ['hash_id' => $hash_id]);
    sendToAllAdmins('sendMessage', ['text' => $adminMessages, 'parse_mode' => "html"]);

    // ارسال پیام به ادمین خاص
    sendMessage($adminMessages, null, "html", $admin);

    // ارسال پیام به کاربر
    sendMessage(\achbotMessage('manual_payment.manual_receipts.message.f568baa065') . number_format($price) . \achbotMessageHtml('manual_payment.manual_receipts.message.f6331b33af', ['supportID' => $supportID]), null, "HTML", $userId);
}

// Decline Cart To Cart by ACh

if (preg_match('/^noop_(.+)$/', $data, $m)) {
	$hash_id = $m[1];
	sendMessage(\achbotMessage('manual_payment.manual_receipts.hash__i_d_hash_id.0ce5b30b71', ['hash_id' => $hash_id]), null, "HTML", $from_id);
	exit;
}

if (preg_match('/^declineAction_(.+)$/', $data, $match) && achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'payments')) {
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
    if ($payInfo['state'] !== 'have_sent') {
        // Never reverse a potentially delivered Processing/native operation.
        alert(\achbotMessage('manual_payment.manual_receipts.alert.af4cc41dc0'));
        return;
    }

    // Owner decision: this button IS the final rejection. Reuse the existing
    // financial CAS, original customer message and admin report path below;
    // no separate reason entry or provider status GET is required. A stale
    // previously opened reason editor is still handled by the same code.
    $userInfo['step'] = 'declineActionReason_' . $hash_id . '_' . $message_id;
    $text = \achbotMessage('manual_receipt_final.status_rejected');
}

if (preg_match('/^declineActionReason_(.+)_(\d+)$/', (string)($userInfo['step']??''), $match) &&
    $text != $buttonValues['cancel'] &&
    achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'payments')) {
    if (!is_string($text) || trim($text)==='') {
        // Compatibility for an editor opened before the one-click change.
        sendMessage(\achbotMessage('manual_payment.manual_receipts.message.a757d0e2a6'),$cancelKey);
        return;
    }
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
		alert(!$payInfo ? \achbotMessage('manual_payment.manual_receipts.alert.9e5ae0bfa1') : \achbotMessage('manual_payment.manual_receipts.alert.caa1f4e27c'));
		return;
	}

    if ($payInfo['state']==='declined' && (int)$payInfo['approved_by']>0) {
        require_once __DIR__.'/../FollowupOutbox.php';
        if (achbotFollowupSchemaReady($connection))
            achbotFollowupDispatchPending($connection,(int)$payInfo['id'],'manual_reject');
        return;
    }
    if ($payInfo['state'] !== 'have_sent') {
        alert(\achbotMessage('manual_payment.manual_receipts.alert.af4cc41dc0'));
        return;
    }

    $uid = $payInfo['user_id'];
    $price = !empty($payInfo['price']) ? number_format($payInfo['price']) . \achbotMessage('manual_payment.automatic_receipts.text.2cc98f7f7c') : "---";
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
    $keys=json_encode(['inline_keyboard'=>[
        [['text'=>\achbotMessage('manual_payment.manual_receipts.button.51fdc91d69',
            ['from_id'=>$from_id]),'callback_data'=>'wizwizch']],
        [['text'=>\achbotMessage('manual_payment.manual_approval.manual_standard_report_delivery.hash_id.2899228604',
            ['hash_id'=>$hash_id]),'callback_data'=>'noop_'.$hash_id]],
    ]],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $retryButton=json_encode(['inline_keyboard'=>[[['text'=>
        \achbotMessage('manual_payment.manual_receipts.button.e3517e568b'),
        'url'=>"https://t.me/$botUsername?start=restart_$hash_id"]]]],
        JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $message=\achbotMessage('manual_payment.manual_receipts.message.bc0155976d',
        ['reason'=>$reason]).
        \achbotMessage('manual_payment.manual_receipts.message.c4d810a7d3',['price'=>$price]).
        \achbotMessage('manual_payment.manual_receipts.message.61d75413a2',['remark'=>$remark]).
        \achbotMessage('manual_payment.manual_receipts.message.df4f415ed5',['hash_id'=>$hash_id]);
    $adminAfterSendKeys=json_encode(['inline_keyboard'=>[[['text'=>
        \achbotMessage('manual_payment.manual_receipts.button.7918a2f411'),
        'callback_data'=>'banUserDirect_'.$uid]]]],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    // This message is staged BEFORE Telegram is called. Never assert that
    // the customer's rejection notice was delivered: its ACK may be unknown.
    $payId=(int)$payInfo['id'];
    $adminReport=\achbotMessageHtml('manual_receipt_final.rejected_report',[
        'id'=>$payId,'user'=>(int)$uid,
        'amount'=>number_format((int)$payInfo['price']),'hash'=>$hash_id])."\n".
        \achbotMessageHtml('manual_payment.manual_receipts.message.61d75413a2',
            ['remark'=>$remark]);
    $opened=false;
    try {
        if (!$connection->begin_transaction()) throw new RuntimeException('manual_reject_begin');
        $opened=true;
        $q=$connection->prepare('SELECT * FROM pays WHERE id=? FOR UPDATE');
        $q->bind_param('i',$payId);$q->execute();$locked=$q->get_result()->fetch_assoc();$q->close();
        if (!$locked || $locked['state']!=='have_sent' ||
            (string)$locked['hash_id']!==(string)$hash_id ||
            (int)$locked['user_id']!==(int)$uid ||
            (int)$locked['chat_id']!==(int)$payInfo['chat_id'] ||
            (int)$locked['message_id']!==(int)$payInfo['message_id'])
            throw new RuntimeException('manual_reject_stale');
        $q=$connection->prepare("UPDATE pays SET state='declined',approved_by=?
            WHERE id=? AND state='have_sent'");
        $actor=(int)$from_id;$q->bind_param('ii',$actor,$payId);
        $ok=$q->execute() && $q->affected_rows===1;$q->close();
        if (!$ok) throw new RuntimeException('manual_reject_cas_failed');
        $locked['state']='declined';$locked['approved_by']=$actor;
        achbotFollowupInsertLocked($connection,$locked,'manual_reject',
            'receipt_primary','edit',(string)(int)$locked['chat_id'],
            (int)$locked['message_id'],['text'=>$keys,'keys'=>null,'mode'=>null]);
        $adminMessages=json_decode((string)($locked['admins_messages']??''),true);
        $seenMirrors=[];
        if (is_array($adminMessages)) foreach ($adminMessages as $msgInfo) {
            if (!is_array($msgInfo) ||
                !isset($msgInfo['chat_id'],$msgInfo['message_id'])) continue;
            $chat=(int)$msgInfo['chat_id'];$msg=(int)$msgInfo['message_id'];
            // Distinct messages in the primary Owner chat are real copies;
            // only the exact primary chat/message pair is redundant.
            if (($chat===(int)$locked['chat_id'] &&
                    $msg===(int)$locked['message_id']) ||
                $chat<=0 || $msg<=0 ||
                !achbotAdminStoredRecipientAllowed($connection,$msgInfo['chat_id'],
                    (int)$admin)) continue;
            $ref=$chat.':'.$msg;
            if (isset($seenMirrors[$ref])) continue;
            achbotFollowupInsertLocked($connection,$locked,'manual_reject',
                'receipt_mirror','edit',(string)$chat,$msg,
                ['text'=>$keys,'keys'=>null,'mode'=>null]);
            $seenMirrors[$ref]=true;
        }
        achbotFollowupInsertLocked($connection,$locked,'manual_reject',
            'customer','send',(string)(int)$uid,null,
            ['text'=>$message,'keys'=>$retryButton,'mode'=>'HTML']);
        achbotFollowupInsertLocked($connection,$locked,'manual_reject',
            'operator','send',(string)$actor,null,
            ['text'=>$adminReport,'keys'=>$adminAfterSendKeys,'mode'=>'HTML']);
        if (!$connection->commit()) throw new RuntimeException('manual_reject_commit_unknown');
        $opened=false;
    } catch(Throwable $error) {
        if ($opened) try {$connection->rollback();} catch(Throwable $ignored) {}
        error_log('achbot_manual_reject_claim_unconfirmed pay_id='.$payId.
            ' class='.get_class($error));
        alert(\achbotMessage('manual_payment.manual_receipts.alert.872f260882'));
        return;
    }
    // An edit exception never suppresses independent customer/staff notices.
    // Missing ACK is UNKNOWN, not permission for a duplicate message.
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

