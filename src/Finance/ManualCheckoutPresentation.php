<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/CheckoutAdmission.php';
require_once __DIR__.'/ReceiptHelpers.php';
require_once __DIR__.'/../Telegram/Conversation.php';

/** Compare one current actor step, never replace an unrelated newer step.
 * This is a navigation claim only, not a payment or receipt decision. */
function achbotReceiptStepChange(mysqli $db,int $actor,string $expected,string $next): bool {
    if ($actor<=0 || $expected==='banned' || str_starts_with($expected,'achbotDirectGift') ||
        str_starts_with($expected,'achbotExempt:')) return false;
    $begun=false;
    try {
        if (!$db->begin_transaction()) return false;
        $begun=true;
        $s=$db->prepare('SELECT id,step FROM users FORCE INDEX (idx_users_userid_v1) WHERE userid=? LIMIT 2 FOR UPDATE');
        $s->bind_param('i',$actor);$s->execute();
        $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
        if (count($rows)!==1 || $rows[0]['step']!==$expected) {
            $db->rollback();return false;
        }
        if ($expected!==$next) {
            $s=$db->prepare('UPDATE users SET step=? WHERE id=? AND userid=? AND step=?');
            $id=(int)$rows[0]['id'];$s->bind_param('siis',$next,$id,$actor,$expected);
            $ok=$s->execute() && $s->affected_rows===1;$s->close();
            if (!$ok) throw new RuntimeException('receipt_step_changed');
        }
        if (!$db->commit()) throw new RuntimeException('receipt_step_commit_unknown');
        return true;
    } catch (Throwable $error) {
        if ($begun) try {$db->rollback();}catch(Throwable $ignored){}
        error_log('achbot_receipt_step_deferred actor='.$actor);
        return false;
    }
}

function achbotReceiptStep(string $step): ?array {
    if (!preg_match('/\Areceipt:([A-Za-z0-9_-]{1,50}):([a-f0-9]{16}):([0-9]+)\z/D',$step,$m)) return null;
    return ['hash'=>$m[1],'generation'=>$m[2],'prompt'=>(int)$m[3]];
}

/** One method selection for every checkout kind. The pinned financial
 * coordinator is the only owner of eligibility, quote and service admission. */
function achbotManualPresentInvoice(mysqli $db,array $pay,int $actor,string $method,?string $network,int $source): void {
    $expected=(string)($GLOBALS['userInfo']['step']??'none');
    $mode=achbotCheckoutMode($pay);
    if ($mode===null || !achbotManualClaim($db,$pay,$method,$actor,$network,$mode)) {
        alert(achbotMessage('payment.checkout.alert.1a885aaca4'),true);return;
    }
    $body=buildCartToCartInfoMessage((array)($GLOBALS['paymentKeys']??[]),
        (int)$pay['price'],$actor,$method,(string)$pay['hash_id']);
    if ($body==='') {alert(achbotMessage('payment.checkout.alert.07ee06b4bc'),true);return;}
    $keys=achbotManualInvoiceKeyboard($db,$pay);
    $sent=sendMessage($body,$keys,'HTML',$actor);
    if (\AchBot\Telegram\Conversation\acknowledgedMessage($sent,$actor)===null) {
        alert(achbotMessage('payment.checkout.alert.7a9c30e938'),true);return;
    }
    answerCallbackQuery((string)($GLOBALS['callbackId']??''));
    $old=achbotReceiptStep($expected);
    if ($old!==null && achbotReceiptStepChange($db,$actor,$expected,'none') && $old['prompt']>0)
        delMessage($old['prompt'],$actor);
    achbotPayUxTrackMessage($db,(int)$pay['id'],$actor,$sent);
    if (function_exists('achbotApiPaymentTrackInvoice'))
        achbotApiPaymentTrackInvoice($db,(string)$pay['hash_id'],$actor,$sent);
    // The selector (not the new issued invoice) is explicitly transient.
    // Retire its controls only after acknowledgement of the retained invoice.
    $deleted=delMessage($source,$actor);
    if (\AchBot\Telegram\Response\isSuccessful($deleted)) {
        $s=achbotProviderStatement($db,'DELETE FROM payment_message_refs WHERE pay_row_id=? AND chat_id=? AND message_id=?',
            'iii',[(int)$pay['id'],$actor,$source]);$s->close();
    }
}

function achbotReceiptOpenPrompt(mysqli $db,array $pay,int $actor,string $expected): void {
    $mode=achbotCheckoutMode($pay);$method=achbotManualSelectedMethod($db,$pay);
    if ($mode===null || $method===null || !achbotManualReceiptAllowed($db,$pay,$method,$actor,$mode)) {
        alert(achbotMessage('payment.checkout.alert.d06bc731eb'),true);return;
    }
    $guard=duplicateReceiptGuard($db,$pay,time());
    if ($guard!==null) {alert($guard,true);return;}
    if (!achbotPayUxStartManualReceipt($db,(int)$pay['id'],$method,$actor,$mode)) {
        alert(achbotMessage('payment.checkout.alert.560f05f822'),true);return;
    }
    answerCallbackQuery((string)($GLOBALS['callbackId']??''));
    $previous=achbotReceiptStep($expected);
    if (($previous['hash']??null)===$pay['hash_id'] && $previous['prompt']>0) {
        achbotReceiptPromptUpdate($db,$pay,$actor,$expected,achbotManualEvidencePrompt($db,$pay,$method));
        return;
    }
    $opening='receipt:'.$pay['hash_id'].':'.bin2hex(random_bytes(8)).':';
    if (!achbotReceiptStepChange($db,$actor,$expected,$opening.'0')) return;
    $sent=sendMessage(achbotManualEvidencePrompt($db,$pay,$method),kbOnlyCancel($pay),'HTML',$actor);
    $message=\AchBot\Telegram\Conversation\acknowledgedMessage($sent,$actor);
    if ($message===null) return; // retain original receipt recovery; no blind send
    if (!achbotReceiptStepChange($db,$actor,$opening.'0',$opening.$message)) {
        delMessage($message,$actor);return;
    }
    if (($previous['prompt']??0)>0 && $previous['prompt']!==$message)
        delMessage($previous['prompt'],$actor);
}

/** Reuse one owned prompt; only a definite rejected edit permits a replacement.
 * A new prompt ID is recorded by CAS before retiring the old one. */
function achbotReceiptPromptUpdate(mysqli $db,array $pay,int $actor,string $expected,string $body): void {
    $step=achbotReceiptStep($expected);
    if ($step===null || $step['hash']!==$pay['hash_id'] ||
        !achbotReceiptStepChange($db,$actor,$expected,$expected)) return;
    $keys=kbOnlyCancel($pay);
    if ($step['prompt']>0) {
        $edited=editText($step['prompt'],$body,$keys,'HTML',$actor);
        if (\AchBot\Telegram\Response\isSuccessful($edited) ||
            \AchBot\Telegram\Response\isEditNoop($edited)) return;
        if (!\AchBot\Telegram\Conversation\canReplaceRejectedEdit($edited)) return;
    } else return; // An unacknowledged send must not be retried automatically.
    $id=\AchBot\Telegram\Conversation\acknowledgedMessage(sendMessage($body,$keys,'HTML',$actor),$actor);
    if ($id===null) return;
    $next='receipt:'.$step['hash'].':'.$step['generation'].':'.$id;
    if (achbotReceiptStepChange($db,$actor,$expected,$next)) delMessage($step['prompt'],$actor);
    else delMessage($id,$actor);
}

/** All mode-specific report facts originate in the immutable service quote.
 * Template tokens are replaced once, including HTML-escaped user values. */
function achbotReceiptStaffCaption(mysqli $db,array $pay,string $mode,string $method,array $actor): ?string {
    $q=$mode==='topup'?null:achbotManualOriginalServiceQuote($db,$pay,(int)$pay['user_id'],$mode);
    if ($mode!=='topup' && $q===null) return null;
    $bulk=$mode==='standard' && (int)$pay['agent_count']>0;
    $template=match($mode) {
        'standard'=>$bulk?achbotMessage('legacy_messages.buy_new_much_account_request'):achbotMessage('legacy_messages.buy_new_account_request'),
        'custom'=>achbotMessage('legacy_messages.buy_custom_account_request'),
        'renew'=>achbotMessage('legacy_messages.renew_account_request_message'),
        'day','volume'=>achbotMessage('legacy_messages.increase_account_request_message'),
        'topup'=>achbotMessage('legacy_messages.increase_wallet_request_message')
    };
    $type=match($mode) {
        'day'=>achbotMessage('renewal.extensions.text.9699c34220'),
        'volume'=>achbotMessage('renewal.extensions.text.4123904480'),
        default=>achbotManualLabel($method)
    };
    $remark=$mode==='standard'?($q['labels']['category'].' '.$q['labels']['plan']):($q['remark']??'');
    $values=['TYPE'=>$type,'USER-ID'=>$pay['user_id'],'USERNAME'=>$actor['username']??'',
        'NAME'=>$actor['name']??'','PRICE'=>number_format((int)$pay['price']),
        'REMARK'=>$remark,'VOLUME'=>$q['volume']??'','DAYS'=>$q['days']??'',
        'ACCOUNT-COUNT'=>$pay['agent_count'],'SERVERNAME'=>$q['labels']['server']??'',
        'INCREASE'=>$mode==='day'?($q['days']??''):($q['volume']??'')];
    return strtr(achbotManualCaption($db,$pay,$template),
        array_map(static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'),$values));
}

/** One submission path for all receipt methods and modes. Human evidence
 * is never deleted. The current durable lease still owns exactly-once
 * settlement and verified staff review. */
function achbotReceiptSubmit(mysqli $db,array $pay,int $actor,array $profile,string $expected,object $update): void {
    $mode=achbotCheckoutMode($pay);$method=achbotManualSelectedMethod($db,$pay);
    if ($mode===null || $method===null || !achbotManualReceiptAllowed($db,$pay,$method,$actor,$mode)) {
        if (achbotReceiptStepChange($db,$actor,$expected,'none'))
            sendMessage(achbotMessage('payment.checkout.alert.882596d0f1'),null,'HTML',$actor);
        return;
    }
    // A concurrent Home/new wizard must not be cleared by this submission.
    if (!achbotReceiptStepChange($db,$actor,$expected,$expected)) return;
    $step=achbotReceiptStep($expected);
    if (($step['prompt']??0)>0 && (int)$update->message->message_id<=$step['prompt']) return;
    $evidence=achbotManualEvidenceInput($update,$db,$pay,$method);
    if ($evidence===null) {
        achbotReceiptPromptUpdate($db,$pay,$actor,$expected,achbotManualEvidencePrompt($db,$pay,$method,true));return;
    }
    $caption=achbotReceiptStaffCaption($db,$pay,$mode,$method,$profile);
    if ($caption===null) {sendMessage(achbotMessage('payment.checkout.alert.882596d0f1'),null,'HTML',$actor);return;}
    $screen=achbotManualGiftSubmissionPreflight($db,$pay,$method,$evidence);
    if (!$screen['accepted']) {
        achbotReceiptPromptUpdate($db,$pay,$actor,$expected,achbotManualGiftRetryMessage((string)$screen['reason']));return;
    }
    if (achbotManualTypedGift($db,$pay,$method) && !achbotManualGiftReviewFits($caption,$evidence,$screen)) {
        sendMessage(achbotMessage('payment.api_payment.code_too_long'),null,'HTML',$actor);return;
    }
    $token=achbotManualBeginEvidence($db,$pay,$method,$actor,$evidence,$mode,$expected);
    if ($token===null) {sendMessage(achbotMessage('payment.checkout.alert.795c4bddb8'),null,'HTML',$actor);return;}
    $hash=(string)$pay['hash_id'];
    $approve=match($mode) {
        'standard'=>'accept','custom'=>'accCustom','renew'=>'approveRenewAcc',
        'day'=>'approveIncreaseDay','volume'=>'approveIncreaseVolume','topup'=>'approvePayment'
    };
    $keys=buildAdminKeyboardWithStats($db,$approve.$hash,'declineAction_'.$hash,$hash,$actor);
    if (sendReceiptToAdminsAndUpdatePay($db,$evidence,$caption,$keys,(int)$GLOBALS['admin'],$hash,$token,$screen)) {
        if (achbotReceiptStepChange($db,$actor,$expected,'none') && ($step['prompt']??0)>0)
            delMessage($step['prompt'],$actor);
        achbotManualAcknowledgeAndVerify($db,$pay,$method,achbotMessage('legacy_buttons.start_bot'),(string)$GLOBALS['botUsername']);
    } else sendMessage(achbotMessage('payment.checkout.message.c346a0d62e'),null,'HTML',$actor);
}
