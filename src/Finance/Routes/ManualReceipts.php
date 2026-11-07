<?php
require_once __DIR__.'/../../Admin/Access.php';
/** AChBot V8: source-pinned financial compatibility module. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../../messages/loader.php'; // ACHBOT_TEXT_CATALOG

if (!achbotAdminRequestAllowed(is_string($data??null)?$data:null,is_string($text??null)?$text:null,(string)($userInfo['step']??''))) {achbotAdminDeny();return;}


// Owner-private, current-authority delivery journal. No financial/native replay.
if (is_string($data??null) && str_starts_with($data,'delivery')) {
    require __DIR__.'/DeliveryRecovery.php';
    return;
}

// Authenticated additional provider children are financial exceptions,
// separate from primary payment approval and service/delivery recovery.
$extraParts=[];
if (preg_match('/^financeExtra(Inbox|Case)(?:_([1-9][0-9]{0,17}))?$/D',
    (string)$data,$extraParts)===1) {
    if ((int)$admin<=0 || (int)$from_id!==(int)$admin ||
        (int)($chat_id??0)!==(int)$admin) {
        answerCallbackQuery($callbackId,achbotMessage('finance_workbench.denied'),true);
        return;
    }
    require_once __DIR__.'/../RecoveryInspection.php';
    $buttons=[];$body='';
    try {
        if ($extraParts[1]==='Inbox') {
            $before=(int)($extraParts[2]??0);
            $items=achbotRecoveryAdditionalFundsCases($connection,$before);
            $more=count($items)>12;$items=array_slice($items,0,12);
            $body=achbotMessage('finance_exceptions.title');
            foreach ($items as $item) {
                $buttons[]=[['text'=>achbotMessageHtml('finance_exceptions.case_label',[
                    'pay'=>(int)$item['id'],'state'=>$item['state']]),
                    'callback_data'=>'financeExtraCase_'.(int)$item['id']]];
            }
            if (!$items) $body.="\n".achbotMessage('finance_exceptions.empty');
            if ($more && $items)
                $buttons[]=[['text'=>achbotMessage('finance_exceptions.next'),
                    'callback_data'=>'financeExtraInbox_'.(int)end($items)['id']]];
            if ($before>0)
                $buttons[]=[['text'=>achbotMessage('finance_exceptions.first'),
                    'callback_data'=>'financeExtraInbox']];
        } else {
            $payId=(int)($extraParts[2]??0);
            $pay=achbotRecoveryAdditionalFundsCase($connection,$payId);
            if (!$pay || ($pay['attempt']['provider']??'')!=='nowpayments')
                throw new RuntimeException('extra_funds_case_not_found');
            $a=$pay['attempt'];$attemptId=(int)$a['id'];
            if (achbotPayUxTable($connection,'payment_review_decisions')) {
            $q=$connection->prepare("SELECT d.actor_id,d.decision,d.provider_status,
                    d.state,a.order_hash,a.provider_reference,a.payment_id
                FROM payment_review_decisions d
                JOIN payment_attempts a ON a.id=d.attempt_id
                WHERE d.pay_row_id=? AND d.attempt_id=? AND d.decision='reject'
                AND d.provider_status='operator_rejected' AND d.state='completed' LIMIT 1");
            $q->bind_param('ii',$payId,$attemptId);$q->execute();
            $human=$q->get_result()->fetch_assoc();$q->close();
            } else $human=null;
            $primaryRejected=$pay['state']==='declined' && $human!==null;
            $q=$connection->prepare("SELECT t.payment_id,t.observed_status,t.received_at
                FROM payment_transactions t JOIN payment_attempts a ON a.id=t.attempt_id
                WHERE t.attempt_id=? AND t.provider='nowpayments'
                    AND BINARY t.payment_id<>BINARY a.payment_id
                    AND t.observed_status IN
                        ('confirming','confirmed','sending','partially_paid','finished','refunded')
                ORDER BY t.id DESC LIMIT 9");
            $q->bind_param('i',$attemptId);$q->execute();
            $children=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
            $body=achbotMessageHtml('finance_exceptions.case_title',[
                'pay'=>$payId,'state'=>$pay['state'],'type'=>$pay['type']])."\n".
                achbotRecoveryEvidenceText($pay,$a)."\n";
            foreach (array_slice($children,0,8) as $child)
                $body.=achbotMessageHtml('finance_exceptions.child_line',[
                    'id'=>substr((string)$child['payment_id'],0,128),
                    'status'=>substr((string)$child['observed_status'],0,128),
                    'received'=>substr((string)$child['received_at'],0,128)])."\n";
            if (count($children)>8) $body.=achbotMessage('finance_exceptions.more');
            if ($primaryRejected) {
                $q=$connection->prepare("SELECT t.payment_id,t.observed_status,t.received_at
                    FROM payment_transactions t
                    WHERE t.attempt_id=? AND t.provider='nowpayments'
                    AND BINARY t.payment_id=BINARY ? AND t.observed_status IN
                    ('confirming','confirmed','sending','partially_paid','finished')
                    ORDER BY t.id DESC LIMIT 1");
                $primaryId=(string)$human['payment_id'];
                $q->bind_param('is',$attemptId,$primaryId);$q->execute();
                $funded=$q->get_result()->fetch_assoc();$q->close();
                if ($funded) {
                    $body.="\n".achbotMessageHtml('finance_exceptions.primary_rejected',[
                        'invoice'=>substr((string)$human['provider_reference'],0,100),
                        'order'=>substr((string)$human['order_hash'],0,100),
                        'actor'=>(int)$human['actor_id']])."\n";
                    $body.=achbotMessageHtml('finance_exceptions.child_line',[
                        'id'=>substr((string)$funded['payment_id'],0,128),
                        'status'=>substr((string)$funded['observed_status'],0,128),
                        'received'=>substr((string)$funded['received_at'],0,128)])."\n";
                    $body.=achbotMessage('finance_exceptions.primary_refund_unverified')."\n";
                }
            }
            $body.="\n".achbotMessage('finance_exceptions.readonly_warning');
            // Never offer the old primary review action on a declined payment.
            if (!$primaryRejected) $buttons[]=[['text'=>achbotMessage('finance_exceptions.inspect'),
                'callback_data'=>'npReviewCase_'.$attemptId]];
            $buttons[]=[['text'=>achbotMessage('finance_exceptions.back'),
                'callback_data'=>'financeExtraInbox']];
        }
        $buttons[]=[['text'=>achbotMessage('finance_exceptions.back_finance'),
            'callback_data'=>'attentionInbox']];
        $markup=json_encode(['inline_keyboard'=>$buttons],
            JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        answerCallbackQuery($callbackId,achbotMessage('finance_exceptions.checked'));
        $reply=editText($message_id,$body,$markup,'HTML',(int)$admin);
        if (!is_object($reply)||($reply->ok??false)!==true)
            sendMessage($body,$markup,'HTML',(int)$admin);
    } catch(Throwable $error) {
        error_log('achbot_additional_funds_inbox_unavailable class='.get_class($error));
        answerCallbackQuery($callbackId,achbotMessage('finance_exceptions.unavailable'),true);
    }
    return;
}

// Quiet, actionable financial-review inbox. Only existing journal-backed
// decision routes and original fulfillment/recovery callbacks can mutate.
$attentionMatch=[];$technicalMatch=[];
$technicalInbox=preg_match('/^attentionInboxService(?:_([1-9][0-9]{0,17}))?$/D',
    (string)$data,$technicalMatch)===1;
if ($technicalInbox ||
    preg_match('/^attention(Inbox|Case)(?:_([1-9][0-9]{0,17}))?$/D',
        (string)$data,$attentionMatch)===1) {
    if (!achbotAdminCan('review',(int)$from_id) || (int)($chat_id??0)!==(int)$from_id) {
        answerCallbackQuery($callbackId,\achbotMessage('manual_payment.manual_receipts.alert.0a23f88410'),true);
        return;
    }
    require_once __DIR__.'/../RecoveryInspection.php';
    if (!defined('ACHBOT_NP_RECONCILE_ONLY')) define('ACHBOT_NP_RECONCILE_ONLY',true);
    require_once __DIR__.'/../GatewayReturn.php';
    $escAttention=static fn($v)=>htmlspecialchars(substr((string)$v,0,150),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $rows=[];
    try {
        if ($technicalInbox) {
            // The existing review permission already admits this queue.
            // This is just the technical view of the same attention hub,
            // not a new role, permission or financial approval flow.
            $before=(int)($technicalMatch[1]??0);
            $items=achbotRecoveryTechnicalCases($connection,$before);
            $more=count($items)>12;$items=array_slice($items,0,12);
            $body=achbotMessage('service_workbench.title');
            foreach ($items as $item) $rows[]=[['text'=>achbotMessageHtml(
                'service_workbench.case_label',[
                'pay'=>(int)$item['id'],'user'=>(int)$item['user_id']]),
                'callback_data'=>'attentionCase_'.(int)$item['id']]];
            if (!$items) $body.="\n".achbotMessage('service_workbench.empty');
            if ($more && $items) $rows[]=[[
                'text'=>achbotMessage('service_workbench.next'),
                'callback_data'=>'attentionInboxService_'.(int)end($items)['id']]];
            if ($before>0) $rows[]=[[
                'text'=>achbotMessage('service_workbench.first'),
                'callback_data'=>'attentionInboxService']];
            $rows[]=[['text'=>achbotMessage('service_workbench.back_finance'),
                'callback_data'=>'attentionInbox']];
        } elseif ($attentionMatch[1]==='Inbox') {
            $before=(int)($attentionMatch[2]??0);
            $items=achbotRecoveryAttentionCases($connection,$before);
            $more=count($items)>12;$items=array_slice($items,0,12);
            $body=\achbotMessage('manual_payment.manual_receipts.body.33cce3f587').
                \achbotMessage('manual_payment.manual_receipts.text.a7c60ae676').
                \achbotMessage('manual_payment.manual_receipts.text.fc540b5b95').
                \achbotMessage('manual_payment.manual_receipts.text.892c054af9');
            foreach ($items as $item) $rows[]=[['text'=>\achbotMessage('manual_payment.manual_receipts.button.8b87c9c9f0').(int)$item['id'].\achbotMessage('manual_payment.manual_receipts.text.44017e9932').(int)$item['user_id'].' | '.number_format((int)$item['price']).\achbotMessage('manual_payment.automatic_receipts.text.2cc98f7f7c'),
                'callback_data'=>'attentionCase_'.(int)$item['id']]];
            if (!$items) $body.=\achbotMessage('manual_payment.manual_receipts.body.344ad306af');
            if ($more) $rows[]=[['text'=>\achbotMessage('manual_payment.manual_receipts.button.2a07cf7022'),'callback_data'=>'attentionInbox_'.(int)end($items)['id']]];
            if ($before>0) $rows[]=[['text'=>\achbotMessage('manual_payment.manual_receipts.button.19ff81effd'),'callback_data'=>'attentionInbox']];
            $rows[]=[['text'=>achbotMessage('service_workbench.open'),
                'callback_data'=>'attentionInboxService']];
        } else {
            $id=(int)($attentionMatch[2]??0);
            $pay=achbotRecoveryAttentionCase($connection,$id);
            if (!$pay) throw new RuntimeException('attention_pay_not_found');
            $a=$pay['attempt'];
            $body=\achbotMessage('manual_payment.manual_receipts.body.069b961bf9').$id."</b>\n".
                achbotPayUxReference($pay).\achbotMessage('manual_payment.manual_receipts.text.be26bedc74').number_format((int)$pay['price']).\achbotMessage('manual_payment.manual_receipts.text.81a62ff6c6').
                \achbotMessage('manual_payment.manual_receipts.text.cb30509da2').$escAttention($pay['type'])."</code>\n".
                \achbotMessage('manual_payment.manual_receipts.text.f0b53210c3').$escAttention($a['provider']??\achbotMessage('manual_payment.manual_receipts.text.260481666f')).\achbotMessage('manual_payment.manual_receipts.text.8e4c17a2df').$escAttention($pay['state'])."</code>\n".
                \achbotMessage('manual_payment.manual_receipts.text.11acfb8b65').$escAttention($a['stage']??\achbotMessage('manual_payment.manual_receipts.text.8e25fa8b4c')).\achbotMessage('manual_payment.manual_receipts.text.57895a27e1').$escAttention($a['provider_status']??\achbotMessage('manual_payment.manual_receipts.text.5749a86140'))."</code>\n\n".
                achbotRecoveryEvidenceText($pay,$a).achbotRecoverySubjectText($pay);
            if ($a && (string)($a['payment_id']??'')!=='') $body.=\achbotMessage('manual_payment.manual_receipts.body.5c0b1f20fc').$escAttention($a['payment_id'])."</code>\n";
            if ($a && (string)($a['provider_reference']??'')!=='')
                $body.=achbotMessage('manual_payment.manual_receipts.body.5c0b1f20fc').
                    $escAttention($a['provider_reference'])."</code>\n";
            // The provider's unresolved VERIFY is not a financial receipt.
            // Owner attestation remains separate from a merchant settlement.
            if ((int)$from_id===(int)$admin && $a &&
                in_array((string)$a['provider'],['nextpay','zarinpal'],true) &&
                in_array((string)$pay['state'],['pending','send'],true) &&
                $a['stage']==='review' &&
                $a['provider_status']==='verification_uncertain') {
                require_once __DIR__.'/../ReviewDecisions.php';
                if (achbotNpDecisionSchemaReady($connection)) {
                    $q=$connection->prepare('SELECT decision,state,outcome
                        FROM payment_review_decisions WHERE pay_row_id=? LIMIT 1');
                    $q->bind_param('i',$id);$q->execute();
                    $journal=$q->get_result()->fetch_assoc();$q->close();
                    if ($journal) {
                        $body.=achbotMessageHtml('finance_workbench.prior_decision',[
                            'decision'=>$journal['decision'],'state'=>$journal['state'],
                            'outcome'=>$journal['outcome']??'unknown']);
                    } else {
                        $aid=(int)$a['id'];
                        $rows[]=[[
                            ['text'=>achbotMessage('finance_workbench.preview_button'),
                                'callback_data'=>'hostedDecisionPA_'.$aid],
                            ['text'=>achbotMessage('finance_workbench.reject_button'),
                                'callback_data'=>'hostedDecisionPR_'.$aid],
                        ]];
                        $body.=achbotMessage('finance_workbench.manual_evidence_warning');
                    }
                } else $body.=achbotMessage('finance_workbench.migration_required');
            }
            if ($a && in_array((string)$a['provider'],['bank_transfer','gift_card'],true) &&
                in_array((string)$pay['state'],['pending','have_sent','processing'],true) &&
                achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'payments')) {
                $proof=achbotPaymentEvidenceGetProof($connection,$id);
                if (($proof['mode']??null)==='settle' &&
                    ($pay['state']!=='processing' || $pay['type']==='BUY_SUB' ||
                     in_array((string)($a['evidence_fulfillment_state']??''),
                         ['reserved','verified'],true)))
                    $rows[]=[['text'=>achbotMessage('finance_workbench.preview_button'),
                        'callback_data'=>'apiEvidenceResume_'.$id]];
            }
            if ((int)$from_id===(int)$admin && $a &&
                in_array((string)$a['provider'],['nextpay','zarinpal'],true) &&
                (($a['stage']==='fulfilling' &&
                  $a['provider_status']==='operator_attested') ||
                 ($a['stage']==='review' &&
                  $a['provider_status']==='operator_fulfillment_unconfirmed')) &&
                achbotPayUxTable($connection,'payment_review_decisions')) {
                $q=$connection->prepare('SELECT decision,state,outcome
                    FROM payment_review_decisions WHERE pay_row_id=? LIMIT 1');
                $q->bind_param('i',$id);$q->execute();
                $journal=$q->get_result()->fetch_assoc();$q->close();
                if ($journal) $body.=achbotMessageHtml('finance_workbench.prior_decision',[
                    'decision'=>$journal['decision'],'state'=>$journal['state'],
                    'outcome'=>$journal['outcome']??'unknown']);
            }
            if ((int)$from_id===(int)$admin && ($a['provider']??'')==='nowpayments') {
                $stmt=$connection->prepare('SELECT id FROM payment_attempts WHERE pay_row_id=? LIMIT 1');
                $stmt->bind_param('i',$id);$stmt->execute();$attemptId=(int)($stmt->get_result()->fetch_assoc()['id']??0);$stmt->close();
                if ($attemptId>0) $rows[]=[['text'=>\achbotMessage('manual_payment.manual_receipts.button.8b5b783029'),'callback_data'=>'npReviewCase_'.$attemptId]];
            }
            if ((int)$from_id===(int)$admin) {
                require_once __DIR__.'/../DeliveryLedger.php';
                if (achbotDeliverySchemaReady($connection)) {
                    $delivery=achbotDeliveryAggregate($connection,$id);
                    if ($delivery && (int)$delivery['total']>0)
                        $rows[]=[['text'=>achbotMessage('delivery_recovery.open'),
                            'callback_data'=>'deliveryCase_'.$id]];
                }
            }
            if ((int)$from_id===(int)$admin && $pay['type']==='BUY_SUB' && in_array($pay['state'],['processing','processing_wallet','paid'],true))
                $rows[]=[['text'=>\achbotMessage('manual_payment.manual_receipts.button.ea309dff23'),'callback_data'=>'bankReviewCase_'.$id]];
            $manual=achbotPayUxManualProvider((string)($a['provider']??''));
            // The quiet queue is a second entrypoint into the ORIGINAL
            // receipt decision handlers. Never invent a shortcut settlement:
            // those handlers re-lock pays, validate the submitted evidence,
            // pin service/capacity and keep their original outcome reports.
            if ($manual && $pay['state']==='have_sent' &&
                (int)($pay['message_id']??0)>0 && (int)($pay['chat_id']??0)>0 &&
                achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'payments')) {
                $kind=(string)$pay['type'];
                $approve=match(true) {
                    $kind==='INCREASE_WALLET'=>'approvePayment',
                    $kind==='RENEW_ACCOUNT'=>'approveRenewAcc',
                    $kind==='RENEW_SCONFIG'=>'accept',
                    str_starts_with($kind,'INCREASE_DAY_')=>'approveIncreaseDay',
                    str_starts_with($kind,'INCREASE_VOLUME_')=>'approveIncreaseVolume',
                    $kind==='BUY_SUB' && ((int)$pay['day']>0 ||
                        (int)$pay['volume']>0)=>'accCustom',
                    $kind==='BUY_SUB'=>'accept',
                    default=>null
                };
                if ($approve!==null && preg_match('/^[A-Za-z0-9_-]{1,50}$/D',
                    (string)$pay['hash_id'])) {
                    $rows[]=[[
                        ['text'=>achbotMessage('finance_workbench.approve_original'),
                            'callback_data'=>$approve.$pay['hash_id']],
                        ['text'=>achbotMessage('finance_workbench.reject_original'),
                            'callback_data'=>'declineAction_'.$pay['hash_id']],
                    ]];
                    $body.=achbotMessage('finance_workbench.manual_evidence_warning');
                }
            }
            // A staff member who can approve the original purchase can
            // finish its ORIGINAL processing claim via the SAME accept
            // callback. The existing source/ledger check is only a button
            // hint; native continuation revalidates under its own locks.
            if (($pay['type']??'')==='BUY_SUB' &&
                ($pay['state']??'')==='processing' &&
                achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'payments') &&
                (achbotRecoveryCanResumeManualPurchase($connection,$pay) ||
                 achbotRecoveryCanResumeBankPurchase($connection,$pay))) {
                $rows[]=[['text'=>achbotMessage('service_recovery_action.resume_purchase'),
                    'callback_data'=>'accept'.$pay['hash_id']]];
                $body.="\n".achbotMessage('service_recovery_action.resume_warning');
            }
            if ($manual && ($pay['state']??null)==='processing' &&
                achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'payments') &&
                preg_match('/^[A-Za-z0-9_-]{1,50}$/D',(string)($pay['hash_id']??''))) {
                require_once __DIR__.'/../ManualEditRecovery.php';
                if (achbotManualPgEditRecoverable($connection,$pay)) {
                    $kind=(string)$pay['type'];
                    $callback=$kind==='RENEW_ACCOUNT'?'approveRenewAcc':
                        (str_starts_with($kind,'INCREASE_DAY_')?'approveIncreaseDay':'approveIncreaseVolume');
                    $rows[]=[['text'=>achbotMessage('service_recovery_action.resume_verified_edit'),
                        'callback_data'=>$callback.$pay['hash_id']]];
                    $body.="\n".achbotMessage('service_recovery_action.edit_warning');
                }
            }
            if ($manual && $pay['state']==='have_sent') $body.=\achbotMessage('manual_payment.manual_receipts.body.189e0e519d');
            elseif ($pay['type']==='INCREASE_WALLET') $body.=\achbotMessage('manual_payment.manual_receipts.body.12b390893d');
            elseif ($pay['type']!=='BUY_SUB') $body.=\achbotMessage('manual_payment.manual_receipts.body.6c27eff9db');
            else $body.=\achbotMessage('manual_payment.manual_receipts.body.3e0c446f58');
            $rows[]=[['text'=>\achbotMessage('manual_payment.manual_receipts.button.4d6cf1a248'),'callback_data'=>'attentionInbox']];
        }
        if (!achbotPayUxTable($connection,'payment_attempts') || !achbotPayUxTable($connection,'service_effects') ||
            !achbotPayUxTable($connection,'automatic_renewal_attempts'))
            $body.=\achbotMessage('manual_payment.manual_receipts.body.75381485c2');
        $body.=\achbotMessage('manual_payment.manual_receipts.body.2d8dc82a81');
        if ((int)$from_id===(int)$admin) {
            $rows[]=[['text'=>achbotMessage('delivery_workbench.open_inbox'),
                'callback_data'=>'deliveryInbox']];
            $rows[]=[['text'=>achbotMessage('finance_exceptions.open'),
                'callback_data'=>'financeExtraInbox']];
            $rows[]=[['text'=>achbotMessage('service_workbench.open'),
                'callback_data'=>'serviceRecoveryInbox']];
            $rows[]=[['text'=>achbotMessage('delivery_workbench.native_inbox'),
                'callback_data'=>'bankReviewInbox']];
            $rows[]=[['text'=>\achbotMessage('manual_payment.manual_receipts.button.3d941bcb15'),
                'callback_data'=>'npReviewInbox']];
        }
        $rows[]=[['text'=>\achbotMessage('manual_payment.manual_crypto.button.1121ffbf88'),'callback_data'=>'evidenceHome']];
        answerCallbackQuery($callbackId,\achbotMessage('manual_payment.manual_receipts.alert.c3fb4bd548'));
        $markup=json_encode(['inline_keyboard'=>$rows],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $result=editText($message_id,$body,$markup,'HTML',(int)$from_id);
        if (!is_object($result) || ($result->ok??false)!==true) sendMessage($body,$markup,'HTML',(int)$from_id);
    } catch (Throwable $error) {
        error_log('achbot_attention_inspection_unavailable class='.get_class($error));
        sendMessage(\achbotMessage('manual_payment.manual_receipts.message.c46e102fab'),null,'HTML',(int)$from_id);
    }
    return;
}

// Re-enter an already claimed bank-Cron BUY_SUB through the SAME 'accept'
// action and 'payments' permission used by existing administrator approvals.
// Only the original issued 003/009 bank case is intercepted. Other accept
// callbacks continue unchanged to ManualApproval.php (no new access rules).
$processingAccept=[];
if (preg_match('/^accept([A-Za-z0-9_-]{1,50})$/D',
        (string)$data,$processingAccept)===1 &&
    ($text??null)!==($buttonValues['cancel']??null) &&
    achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'payments')) {
    $hash=$processingAccept[1];
    $q=$connection->prepare("SELECT id FROM pays WHERE hash_id=? AND type='BUY_SUB'
        AND state='processing' AND COALESCE(approved_by,0)=0 LIMIT 1");
    $q->bind_param('s',$hash);$q->execute();
    $id=(int)($q->get_result()->fetch_assoc()['id']??0);$q->close();
    if ($id>0) {
        try {
            require_once __DIR__.'/../RecoveryInspection.php';
            require_once __DIR__.'/../BankPurchase.php';
            $pay=achbotRecoveryTechnicalCase($connection,$id);
            if (!$pay || !achbotRecoveryCanResumeBankPurchase($connection,$pay))
                throw new RuntimeException('bank_purchase_original_claim_unavailable');
            $result=achbotAutoBankProvisionBuySub($connection,$pay,
                $botState,$buttonValues,[],false,(string)$botUrl);
            if (($result['committed']??false)!==true)
                throw new RuntimeException('bank_purchase_original_completion_unconfirmed');
        } catch (Throwable $error) {
            error_log('achbot_bank_purchase_resume_deferred pay_id='.$id.
                ' class='.get_class($error));
            answerCallbackQuery($callbackId,achbotMessage('service_workbench.unavailable'),true);
            return;
        }
        // Business COMMIT precedes the independently resumable delivery
        // and referral messages; never repeat CREATE on Telegram uncertainty.
        try {
            require_once __DIR__.'/../ReferralReward.php';
            achbotReferralAfterCompletedPurchase($connection,(int)$pay['user_id'],
                $id,(int)$from_id,$botState);
        } catch (Throwable $error) {
            error_log('achbot_bank_purchase_resume_referral_review pay_id='.$id.
                ' class='.get_class($error));
        }
        try {
            achbotDeliveryDispatchPending($connection,$id);
            achbotDeliverySyncAggregate($connection,$id);
        } catch (Throwable $error) {
            error_log('achbot_bank_purchase_resume_delivery_review pay_id='.$id.
                ' class='.get_class($error));
        }
        // The original bank-Cron receipt and existing staff copies need the
        // same success status as a purchase completed in the first sweep.
        // Telegram uncertainty remains a presentation issue after COMMIT.
        try {
            achbotAutoBankSyncReceiptButtons($connection,$pay,(int)$admin,
                achbotMessage('manual_payment.automatic_receipts.text.2898f8f112'));
        } catch (Throwable $error) {
            error_log('achbot_bank_purchase_resume_receipt_edit_deferred pay_id='.$id.
                ' class='.get_class($error));
        }
        answerCallbackQuery($callbackId,achbotMessage('service_workbench.checked'));
        $keys=json_encode(['inline_keyboard'=>[
            [['text'=>achbotMessage('service_workbench.open'),
                'callback_data'=>'attentionInboxService']],
            [['text'=>achbotMessage('service_workbench.back_finance'),
                'callback_data'=>'attentionInbox']]]],
            JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $body=achbotMessage('service_workbench.checked');
        $sent=editText($message_id,$body,$keys,'HTML',(int)$chat_id);
        if (!is_object($sent)||($sent->ok??false)!==true)
            sendMessage($body,$keys,'HTML',(int)$chat_id);
        return;
    }
}

// Paid remote/renewal uncertainty is not an unpaid transaction. Keep it in
// a separate, owner-initiated read-only recovery queue. Never offer a second
// financial approve/decline or dispatch a new native CREATE from this UI.
$serviceParts=[];
if (preg_match('/^serviceRecovery(Inbox|Case)(?:_([1-9][0-9]{0,17}))?$/D',
    (string)$data,$serviceParts)===1) {
    if ((int)$admin<=0 || (int)$from_id!==(int)$admin ||
        (int)($chat_id??0)!==(int)$admin) {
        answerCallbackQuery($callbackId,achbotMessage('finance_workbench.denied'),true);
        return;
    }
    require_once __DIR__.'/../RecoveryInspection.php';
    $buttons=[];$body='';
    $esc=static fn($s)=>htmlspecialchars(substr((string)$s,0,100),
        ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    try {
        if ($serviceParts[1]==='Inbox') {
            $before=(int)($serviceParts[2]??0);
            $items=achbotRecoveryTechnicalCases($connection,$before);
            $more=count($items)>12;$items=array_slice($items,0,12);
            $body=achbotMessage('service_workbench.title');
            foreach ($items as $item) {
                $buttons[]=[['text'=>achbotMessageHtml('service_workbench.case_label',[
                    'pay'=>(int)$item['id'],'user'=>(int)$item['user_id']]),
                    'callback_data'=>'serviceRecoveryCase_'.(int)$item['id']]];
            }
            if (!$items) $body.="
".achbotMessage('service_workbench.empty');
            if ($more && $items) {
                $buttons[]=[['text'=>achbotMessage('service_workbench.next'),
                    'callback_data'=>'serviceRecoveryInbox_'.(int)end($items)['id']]];
            }
            if ($before>0)
                $buttons[]=[['text'=>achbotMessage('service_workbench.first'),
                    'callback_data'=>'serviceRecoveryInbox']];
        } else {
            $id=(int)($serviceParts[2]??0);
            $pay=achbotRecoveryTechnicalCase($connection,$id);
            if (!$pay) throw new RuntimeException('service_recovery_not_reviewable');
            $attempt=$pay['attempt']??null;
            $canResume=achbotRecoveryCanResumeManualPurchase($connection,$pay) ||
                achbotRecoveryCanResumeBankPurchase($connection,$pay);
            $pendingEdit=null;
            if (($pay['state']??null)==='approved' &&
                preg_match('/^[A-Za-z0-9_-]{1,50}$/D',(string)($pay['hash_id']??''))) {
                require_once __DIR__.'/../ManualEditRecovery.php';
                if (achbotManualPgEditPending($connection,$pay)) {
                    $type=(string)$pay['type'];
                    $prefix=$type==='RENEW_ACCOUNT'?'approveRenewAcc':
                        (str_starts_with($type,'INCREASE_DAY_')?'approveIncreaseDay':
                         (str_starts_with($type,'INCREASE_VOLUME_')?'approveIncreaseVolume':null));
                    if ($prefix!==null) $pendingEdit=$prefix.(string)$pay['hash_id'];
                }
            }
            $editDelivery=[];
            if (($pay['state']??null)==='approved' &&
                achbotPayUxTable($connection,'payment_followup_intents')) {
                require_once __DIR__.'/../FollowupOutbox.php';
                $editDelivery=achbotFollowupSummary($connection,$id,'approved_edit');
            }
            $body=achbotMessageHtml('service_workbench.case_title',[
                'pay'=>$id,'user'=>$pay['user_id'],
                'state'=>$pay['state'],'type'=>$pay['type']])."
".
                achbotRecoveryEvidenceText($pay,$attempt).
                achbotRecoverySubjectText($pay).
                "
".($canResume
                    ?achbotMessage('service_recovery_action.resume_warning')
                    :achbotMessage('service_workbench.readonly_warning'));
            if ($editDelivery) {
                // Unresolved original recipients first; show acknowledged
                // peers for context. This is an Owner-private SQL-only view.
                $unresolved=[];$acknowledged=[];
                foreach ($editDelivery as $packet) {
                    if (in_array((string)$packet['state'],
                            ['pending','dispatching','unknown'],true))
                        $unresolved[]=$packet;
                    elseif ($packet['state']==='acknowledged')
                        $acknowledged[]=$packet;
                }
                $visible=array_slice(array_merge($unresolved,$acknowledged),0,10);
                $body.="\n\n".achbotMessage('service_edit_delivery.header');
                foreach ($visible as $packet)
                    $body.="\n".achbotMessageHtml('service_edit_delivery.line',[
                        'role'=>(string)$packet['role'],
                        'state'=>(string)$packet['state'],
                        'attempts'=>(int)$packet['attempt_count'],
                        'recipient'=>(string)$packet['recipient'],
                        'message_id'=>$packet['message_id']===null?'-':
                            (int)$packet['message_id']]);
                if (count($editDelivery)>count($visible))
                    $body.="\n".achbotMessage('service_edit_delivery.more');
                $body.="\n".achbotMessage('service_edit_delivery.warning');
            }
            if ($canResume) {
                // The existing accept handler is the only financial/native
                // continuation; its original proof and once-only journals
                // are rechecked under locks. No new recovery state machine.
                $buttons[]=[['text'=>achbotMessage('service_recovery_action.resume_purchase'),
                    'callback_data'=>'accept'.$pay['hash_id']]];
            }
            if ($pendingEdit!==null) {
                // The original payments callback dispatches PENDING 020
                // packets only; approved means there is no financial/native
                // operation to repeat. UNKNOWN remains a read-only case.
                $buttons[]=[['text'=>achbotMessage('service_recovery_action.resume_verified_edit'),
                    'callback_data'=>$pendingEdit]];
            }
            if ($pay['type']==='BUY_SUB') {
                $buttons[]=[['text'=>achbotMessage('delivery_workbench.native_inbox'),
                    'callback_data'=>'bankReviewCase_'.$id]];
            }
            $buttons[]=[['text'=>achbotMessage('service_workbench.back'),
                'callback_data'=>'serviceRecoveryInbox']];
        }
        $buttons[]=[['text'=>achbotMessage('service_workbench.back_finance'),
            'callback_data'=>'attentionInbox']];
        $keys=json_encode(['inline_keyboard'=>$buttons],
            JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        answerCallbackQuery($callbackId,achbotMessage('service_workbench.checked'));
        $response=editText($message_id,$body,$keys,'HTML',(int)$admin);
        if (!is_object($response)||($response->ok??false)!==true)
            sendMessage($body,$keys,'HTML',(int)$admin);
    } catch (Throwable $error) {
        error_log('achbot_service_recovery_inbox_unavailable class='.get_class($error));
        try {answerCallbackQuery($callbackId,achbotMessage('service_workbench.unavailable'),true);}
        catch(Throwable $ignored) {}
    }
    return;
}

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
        require_once __DIR__.'/../ApiPayments.php';
        $pay=achbotApiPaymentRow($connection,$payId);
        $attempt=$pay?achbotApiPaymentAttempt($connection,$payId):null;
        $proof=$pay?achbotPaymentEvidenceGetProof($connection,$payId):null;
        if (!$pay || !$attempt ||
            !in_array((string)$attempt['provider'],['bank_transfer','gift_card'],true) ||
            !in_array((string)$pay['state'],['pending','have_sent','processing'],true) ||
            !is_array($proof) || ($proof['mode']??'')!=='settle' ||
            ($pay['state']==='processing' && $pay['type']!=='BUY_SUB' &&
             !in_array((string)($attempt['evidence_fulfillment_state']??''),
                 ['reserved','verified'],true))) {
            answerCallbackQuery($callbackId,achbotMessage('finance_workbench.status_stale'),true);
            return;
        }
        answerCallbackQuery($callbackId,achbotMessage('finance_workbench.checking'));
        $outcome=achbotApiPaymentSettle($connection,$payId,$proof);
        $result=in_array($outcome,['confirmed','settled'],true)
            ? achbotMessage('finance_workbench.status_completed')
            : achbotMessage('finance_workbench.status_unknown');
        $buttons=json_encode(['inline_keyboard'=>[[['text'=>achbotMessage('finance_workbench.back'),
            'callback_data'=>'attentionCase_'.$payId]]]],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $text=achbotMessageHtml('finance_workbench.result',['result'=>$result]);
        $reply=editText($message_id,$text,$buttons,'HTML',(int)$from_id);
        if (!is_object($reply)||($reply->ok??false)!==true)
            sendMessage($text,$buttons,'HTML',(int)$from_id);
    } catch(Throwable $error) {
        error_log('achbot_api_review_resume_unavailable pay_id='.$payId.
            ' class='.get_class($error));
        answerCallbackQuery($callbackId,achbotMessage('finance_workbench.status_unavailable'),true);
    }
    return;
}

// Direct Owner gateway decisions with the existing unique 019 journal.
// No unsolicited review notice or shortcut around normal wallet/service effects.
$npDecisionPreview=[];$npDecisionCommit=[];$hostedDecisionPreview=[];
$npPreview=preg_match('/^npDecisionP([AR])_([1-9][0-9]{0,17})$/D',
    (string)$data,$npDecisionPreview)===1;
$hostedPreview=preg_match('/^hostedDecisionP([AR])_([1-9][0-9]{0,17})$/D',
    (string)$data,$hostedDecisionPreview)===1;
$npCommit=preg_match('/^npDecisionC([AR])_([1-9][0-9]{0,17})_([1-9][0-9]{9})_([a-f0-9]{20})$/D',
    (string)$data,$npDecisionCommit)===1;
if ($npPreview || $npCommit || $hostedPreview) {
    if ((int)$admin<=0 || (int)$from_id!==(int)$admin ||
        (int)($chat_id??0)!==(int)$admin) {
        answerCallbackQuery($callbackId,achbotMessage('finance_workbench.denied'),true);
        return;
    }
    require_once __DIR__.'/../ReviewDecisions.php';
    if (!defined('ACHBOT_NP_RECONCILE_ONLY')) define('ACHBOT_NP_RECONCILE_ONLY',true);
    require_once __DIR__.'/../GatewayReturn.php';
    $parts=$npPreview?$npDecisionPreview:($hostedPreview?$hostedDecisionPreview:$npDecisionCommit);
    $mode=$parts[1]==='A'?'approve':'reject';
    $id=(int)$parts[2];
    $casePayId=0;
    if ($hostedPreview) {
        $q=$connection->prepare('SELECT pay_row_id FROM payment_attempts WHERE id=? LIMIT 1');
        $q->bind_param('i',$id);$q->execute();
        $casePayId=(int)($q->get_result()->fetch_assoc()['pay_row_id']??0);$q->close();
    }
    $reply=static function(string $body,array $buttons) use ($message_id,$admin): void {
        $keys=json_encode(['inline_keyboard'=>$buttons],
            JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $result=editText($message_id,$body,$keys,'HTML',(int)$admin);
        if (!is_object($result) || ($result->ok??false)!==true)
            sendMessage($body,$keys,'HTML',(int)$admin);
    };
    $back=[[['text'=>achbotMessage('finance_workbench.back'),
        'callback_data'=>$hostedPreview?'attentionCase_'.$casePayId:'npReviewCase_'.$id]]];
    try {
        answerCallbackQuery($callbackId,achbotMessage('finance_workbench.checking'));
        if (!achbotNpDecisionSchemaReady($connection))
            throw new RuntimeException('review_decision_schema_missing');
        // The Owner already checked the payment. This callback IS the
        // decision: no merchant GET and no second operator confirmation.
        // Retired two-step callbacks may outlive deployment in Telegram;
        // do not execute a stale confirmation using a changed evidence basis.
        if ($npCommit) {
            $reply(achbotMessage('finance_workbench.challenge_expired'),$back);
            return;
        }
        $result=achbotNpDecisionCommit($connection,(int)$from_id,$id,$mode,null);
        $status=(string)($result['status']??'unavailable');
        // This is the normal terminal business outcome, not a review alert.
        // Human rejection closes business fulfillment without claiming refund.
        if ($status==='rejected') {
            $pay=$result['pay'];
            $params=['id'=>$id,'user'=>(int)$pay['user_id'],
                'amount'=>number_format((int)$pay['price']),'hash'=>(string)$pay['hash_id']];
            try {
                // Customer copy has TWO slots. The separate business report
                // has all four; passing extra slots was throwing before send.
                $customer=['hash'=>$params['hash'],'amount'=>$params['amount']];
                sendMessage(achbotMessageHtml('finance_workbench.rejected_customer',$customer),
                    null,'HTML',(int)$pay['user_id']);
            } catch(Throwable $error) {
                error_log('achbot_np_reject_customer_notice_unknown pay_id='.(int)$pay['id']);
            }
            try {
                $report=achbotMessageHtml('finance_workbench.rejected_report',$params);
                notifyAllAdminsSimple($report,(int)$admin,'html',null,
                    $botState['rewardChannel']??null);
            } catch(Throwable $error) {
                error_log('achbot_np_reject_business_report_unknown pay_id='.(int)$pay['id']);
            }
        }
        $statusText=match($status) {
            'completed'=>achbotMessage('finance_workbench.status_completed'),
            'rejected'=>achbotMessage('finance_workbench.status_rejected'),
            'unknown'=>achbotMessage('finance_workbench.status_unknown'),
            'stale'=>achbotMessage('finance_workbench.status_stale'),
            'already'=>achbotMessage('finance_workbench.status_already'),
            'unsupported'=>achbotMessage('finance_workbench.status_unsupported'),
            default=>achbotMessage('finance_workbench.status_unavailable'),
        };
        $reply(achbotMessageHtml('finance_workbench.result',['result'=>$statusText]),$back);
    } catch (Throwable $error) {
        error_log('achbot_np_review_decision_unavailable attempt_id='.$id.
            ' class='.get_class($error));
        try {$reply(achbotMessage('finance_workbench.unavailable'),$back);}
        catch(Throwable $ignored) {}
    }
    return;
}

// The operator recovery workbench is never available to other admins or chats.
$bankInboxMatch=[];$bankCaseMatch=[];$bankReadMatch=[];
$bankInbox=preg_match('/^bankReviewInbox(?:_([1-9][0-9]{0,17}))?$/D',
                      (string)$data,$bankInboxMatch)===1;
$bankCase=preg_match('/^bankReviewCase_([1-9][0-9]{0,17})(?:_([0-9]{1,10}))?$/D',
                     (string)$data,$bankCaseMatch)===1;
$bankRead=preg_match('/^bankReviewRead_([1-9][0-9]{0,17})_([1-9][0-9]{0,9})_([0-9]{1,10})$/D',
                     (string)$data,$bankReadMatch)===1;
if ($bankInbox || $bankCase || $bankRead) {
    if ((int)$admin<=0 || (int)$from_id!==(int)$admin ||
        (int)($chat_id??0)!==(int)$admin) {
        answerCallbackQuery($callbackId,\achbotMessage('manual_payment.manual_receipts.alert.0a23f88410'),true);
        return;
    }
    require_once __DIR__.'/../RecoveryInspection.php';
    // The production implementation never consults a mutable server ID.
    // The named seam permits isolated source tests to replace only transport.
    if (!function_exists('achbotRecoveryStrictAccountGet'))
        require_once __DIR__.'/../RecoveryTransport.php';
    $escRecovery=static fn($value) => htmlspecialchars(
        substr((string)($value??''),0,100),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');

    $bankAcked=false;
    $bankAck=static function(string $message) use (&$bankAcked,$callbackId): void {
        if ($bankAcked) return;
        $bankAcked=true;
        answerCallbackQuery($callbackId,$message);
    };
    // Never use a second callback ACK as delivery confirmation. After the
    // original ACK, edit the owner's message or send a new owner-private one.
    $bankShow=static function(string $body, array $buttons=[]) use ($message_id,$admin): void {
        $markup=json_encode(['inline_keyboard'=>$buttons],
                            JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        try {
            $edited=editText($message_id,$body,$markup,'HTML',$admin);
            if (is_object($edited) && ($edited->ok??false)===true) return;
        } catch (Throwable $ignored) {}
        $sent=sendMessage($body,$markup,'HTML',$admin);
        if (!is_object($sent) || ($sent->ok??false)!==true)
            throw new RuntimeException('recovery_owner_delivery_unconfirmed');
    };

    $payId=0;
    try {
        $bankAck($bankRead?\achbotMessage('manual_payment.manual_receipts.text.e4945a4036'):\achbotMessage('manual_payment.manual_receipts.text.f45bd281a9'));
        if ($bankInbox) {
            $before=(int)($bankInboxMatch[1]??0);
            $items=achbotRecoveryPendingCases($connection,$before);
            $more=count($items)>12;
            $items=array_slice($items,0,12);
            $buttons=[];
            foreach ($items as $item) {
                $buttons[]=[['text'=>\achbotMessage('manual_payment.manual_receipts.button.8b87c9c9f0').(int)$item['id'].\achbotMessage('manual_payment.manual_receipts.text.44017e9932').
                    (int)$item['user_id'].' | '.number_format((int)$item['price']).\achbotMessage('manual_payment.automatic_receipts.text.2cc98f7f7c'),
                    'callback_data'=>'bankReviewCase_'.(int)$item['id']]];
            }
            if ($more && $items) {
                $last=end($items);
                $buttons[]=[['text'=>\achbotMessage('manual_payment.manual_receipts.button.2a07cf7022'),
                    'callback_data'=>'bankReviewInbox_'.(int)$last['id']]];
            }
            if ($before>0)
                $buttons[]=[['text'=>\achbotMessage('manual_payment.manual_receipts.button.19ff81effd'),'callback_data'=>'bankReviewInbox']];
            $buttons[]=[['text'=>\achbotMessage('manual_payment.manual_receipts.button.84fe055cdf'),'callback_data'=>'attentionInbox']];
            $buttons[]=[['text'=>\achbotMessage('manual_payment.manual_receipts.button.c7ac8dfee0'),'callback_data'=>'managePanel']];
            $body=\achbotMessage('manual_payment.manual_receipts.body.0b11329132').
                \achbotMessage('manual_payment.manual_receipts.text.daf7f84c9e').
                \achbotMessage('manual_payment.manual_receipts.text.0702ddffac').
                \achbotMessage('manual_payment.manual_receipts.text.d18917f91a');
            if (!$items) $body.=\achbotMessage('manual_payment.manual_receipts.body.83bffce220');
            $bankShow($body,$buttons);
            return;
        }
        $payId=(int)($bankCase?$bankCaseMatch[1]:$bankReadMatch[1]);
        $cursor=(int)($bankCase?($bankCaseMatch[2]??0):$bankReadMatch[3]);
        $observation=null;
        if ($bankRead) {
            $ordinal=(int)$bankReadMatch[2];
            // Pinned, bounded authenticated login and exactly one account GET.
            $observation=achbotRecoveryInspectLine(
                $connection,$payId,$ordinal,
                static fn(string $endpoint,string $user,string $password,string $username) =>
                    achbotRecoveryStrictAccountGet($endpoint,$user,$password,$username));
        }
        $case=achbotRecoveryCase($connection,$payId,$cursor);
        if (!$case) {
            $bankShow(\achbotMessage('manual_payment.manual_receipts.text.78315abd05').$payId.\achbotMessage('manual_payment.manual_receipts.text.5743ace55a').
                \achbotMessage('manual_payment.manual_receipts.text.5743a85170').
                \achbotMessage('manual_payment.manual_receipts.text.1348893533'),
                [[['text'=>\achbotMessage('manual_payment.manual_receipts.button.cec3d268b6'),'callback_data'=>'bankReviewInbox']]]);
            return;
        }
        $requested=(int)$case['requested_accounts'];
        $recorded=(int)$case['recorded_accounts'];
        $body=\achbotMessage('manual_payment.manual_receipts.body.7dd7fb1632').(int)$case['pay_id']."</b>\n\n".
            \achbotMessage('manual_payment.manual_receipts.text.d2bbfff6d2').(int)$case['user_id']."\n".
            \achbotMessage('manual_payment.manual_receipts.text.db022eb061').number_format((int)$case['amount']).\achbotMessage('manual_payment.manual_receipts.text.81a62ff6c6').
            \achbotMessage('manual_payment.manual_receipts.text.d279f3e369').$requested.\achbotMessage('manual_payment.manual_receipts.text.f5bbd167b7').$recorded."\n".
            \achbotMessage('manual_payment.manual_receipts.text.955398cb47').$escRecovery($case['method'])."</code>\n".
            \achbotMessage('manual_payment.manual_receipts.text.1adade53f0').$escRecovery($case['payment_state'])."</code>\n".
            $case['evidence_text'];
        if ($case['payment_state']==='processing_wallet') {
            $body.=\achbotMessage('manual_payment.manual_receipts.body.421579cc6a').
                $escRecovery($case['wallet_hold_state'])."</code>".
                \achbotMessage('manual_payment.manual_receipts.text.7a7fde1704').
                (int)$case['wallet_capacity_reserved']."</code>
";
        }
        if ($case['historical_evidence_unavailable'])
            $body.=\achbotMessage('manual_payment.manual_receipts.body.a9416a9d0f');
        elseif (!$case['journal_complete'])
            $body.=\achbotMessage('manual_payment.manual_receipts.body.a578ddc599');
        else
            $body.=\achbotMessage('manual_payment.manual_receipts.body.dbc1d1ad5d');
        $body.=\achbotMessage('manual_payment.manual_receipts.body.9c35b59ec6').
               \achbotMessage('manual_payment.manual_receipts.text.1fd9b81d66');
        $states=[
            'dispatching'=>\achbotMessage('manual_payment.manual_receipts.text.9509889d5a'),
            'verified'=>\achbotMessage('manual_payment.manual_receipts.text.ced6988f1b'),
            'local_recorded'=>\achbotMessage('manual_payment.manual_receipts.text.872f51fba7')
        ];
        $orderStates=[
            'not_recorded'=>\achbotMessage('manual_payment.manual_receipts.text.49dcc53128'),
            'historical_order_missing'=>\achbotMessage('manual_payment.manual_receipts.text.23f88a8e40'),
            'linked'=>\achbotMessage('manual_payment.manual_receipts.text.592fae7fc5'),
            'linked_mismatch'=>\achbotMessage('manual_payment.manual_receipts.text.1dcc76aa6d')
        ];
        $buttons=[];
        foreach ($case['lines'] as $line) {
            $num=(int)$line['ordinal'];
            $body.=\achbotMessage('manual_payment.manual_receipts.body.e32998c4f2').$num." | <code>".$escRecovery($line['username'])."</code>\n".
                \achbotMessage('manual_payment.manual_receipts.text.5def5245fd').$escRecovery($states[$line['state']]??\achbotMessage('manual_payment.manual_receipts.text.f8ba5b2ba2'))."\n".
                \achbotMessage('manual_payment.manual_receipts.text.7906264202').$escRecovery($line['operation_id'])."</code>\n".
                \achbotMessage('manual_payment.manual_receipts.text.1fa4518da2').(int)$line['effect_id'].\achbotMessage('manual_payment.manual_receipts.text.e79a84073f').
                    ($line['order_id']===null?\achbotMessage('manual_payment.manual_receipts.text.cbf88cdc30'):(int)$line['order_id'])."\n".
                \achbotMessage('manual_payment.manual_receipts.text.a8d37ad733').$escRecovery($orderStates[$line['local_evidence']]??\achbotMessage('manual_payment.manual_receipts.text.aa75bd9846'))."\n";
            if ($num>$requested)
                $body.=\achbotMessage('manual_payment.manual_receipts.body.549aabe72c');
            $buttons[]=[['text'=>\achbotMessage('manual_payment.manual_receipts.button.3b19e9c031').$num,
                'callback_data'=>'bankReviewRead_'.(int)$case['pay_id'].'_'.$num.'_'.$cursor]];
        }
        if (!$case['lines'])
            $body.=\achbotMessage('manual_payment.manual_receipts.body.f8d79baf6f');
        if ($observation!==null) {
            $explain=match($observation['status']) {
                'matching_observation'=>\achbotMessage('manual_payment.manual_receipts.text.b4dda864a4'),
                'remote_mismatch'=>\achbotMessage('manual_payment.manual_receipts.text.f5ca620f64'),
                'remote_unavailable'=>\achbotMessage('manual_payment.manual_receipts.text.7d1ab789a1'),
                'changed_during_inspection'=>\achbotMessage('manual_payment.manual_receipts.text.e802d83881'),
                default=>\achbotMessage('manual_payment.manual_receipts.text.81d4f3f95f'),
            };
            $body.=\achbotMessage('manual_payment.manual_receipts.body.46a1988ab1').(int)$observation['ordinal'].":</b>\n".$explain."\n";
        }
        $body.=\achbotMessage('manual_payment.manual_receipts.body.f6f12cb363').
            \achbotMessage('manual_payment.manual_receipts.text.6cd6e8610f');
        if ((int)$case['next_ordinal']>0)
            $buttons[]=[['text'=>\achbotMessage('manual_payment.manual_receipts.button.30c297064a'),
                'callback_data'=>'bankReviewCase_'.(int)$case['pay_id'].'_'.
                    (int)$case['next_ordinal']]];
        if ($cursor>0)
            $buttons[]=[['text'=>\achbotMessage('manual_payment.manual_receipts.button.e9b88c11de'),
                'callback_data'=>'bankReviewCase_'.(int)$case['pay_id']]];
        $buttons[]=[['text'=>\achbotMessage('manual_payment.manual_receipts.button.2de264e127'),
            'callback_data'=>'bankReviewInbox']];
        $bankShow($body,$buttons);
    } catch (Throwable $error) {
        // No raw token, provider response or customer data in protected logs.
        error_log('achbot_service_recovery_inspection_unavailable pay_id='.
            (int)$payId.' class='.get_class($error));
        try {
            $bankAck(\achbotMessage('manual_payment.manual_receipts.text.f8772986a6'));
            $bankShow(\achbotMessage('manual_payment.manual_receipts.text.7434f799ae').
                \achbotMessage('manual_payment.manual_receipts.text.7aefae1fe7').$payId.\achbotMessage('manual_payment.manual_receipts.text.1f851ceccd').
                \achbotMessage('manual_payment.manual_receipts.text.39f6c134e8'),
                [[['text'=>\achbotMessage('manual_payment.manual_receipts.button.cec3d268b6'),'callback_data'=>'bankReviewInbox']]]);
        } catch (Throwable $deliveryError) {
            error_log('achbot_service_recovery_owner_delivery_failed class='.
                get_class($deliveryError));
        }
    }
    return;
}

// Owner-only, provider-evidence-gated finance inbox. These buttons are
// read-only. Do not repurpose historical manual receipt approval handlers for
// a hosted payment: a click is not settlement or refund authorization.
$inboxParts=[];
$isInbox=preg_match('/^npReviewInbox(?:_([0-9]{14})_([1-9][0-9]{0,17}))?$/D',
                    (string)$data,$inboxParts)===1;
if ($isInbox ||
    preg_match('/^npReview(?:Case|Refresh|Verify)_[1-9][0-9]{0,17}(?:_(?:[0-9]|1[01]))?(?:_[1-9][0-9]{0,17})?$/D',
               (string)$data)) {
    if ((int)$from_id !== (int)$admin ||
        (int)($chat_id??0) !== (int)$admin) {
        answerCallbackQuery($callbackId,\achbotMessage('manual_payment.manual_receipts.alert.f961d21391'),true);
        return;
    }
    if (!defined('ACHBOT_NP_RECONCILE_ONLY')) define('ACHBOT_NP_RECONCILE_ONLY',true);
    require_once __DIR__ . '/../GatewayReturn.php';
    try {
        $scope=achbotNpOperatorWorkSql();
        if ($isInbox) {
            // Stable keyset order for the owner worklist: multiple cases may
            // have the same second-resolution updated_at timestamp.
            $cursorText=(string)($inboxParts[1]??'');
            $cursorId=(int)($inboxParts[2]??0);
            $hasCursor=$cursorText!=='';
            if ($hasCursor) {
                $cursorTime=DateTimeImmutable::createFromFormat('!YmdHis',$cursorText);
                if (!$cursorTime || $cursorTime->format('YmdHis')!==$cursorText) {
                    answerCallbackQuery($callbackId,\achbotMessage('manual_payment.manual_receipts.alert.b4db548d5f'),true);
                    return;
                }
                $cursorDate=$cursorTime->format('Y-m-d H:i:s');
            }
            $listSql="SELECT a.id,a.order_hash,p.price,p.user_id,
                    a.provider_status,a.updated_at
                FROM payment_attempts a JOIN pays p ON p.id=a.pay_row_id
                WHERE ($scope)";
            if ($hasCursor)
                $listSql.=" AND (a.updated_at < ? OR
                                 (a.updated_at = ? AND a.id < ?))";
            $listSql.=" ORDER BY a.updated_at DESC,a.id DESC LIMIT 13";
            $stmt=$connection->prepare($listSql);
            if ($hasCursor)
                $stmt->bind_param('ssi',$cursorDate,$cursorDate,$cursorId);
            if (!$stmt->execute())
                throw new RuntimeException('operator_inbox_page_read_failed');
            $items=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            $hasOlder=count($items)>12;
            $items=array_slice($items,0,12);
            $rows=[];
            foreach ($items as $item) {
                $label=\achbotMessage('manual_payment.manual_receipts.button.8b87c9c9f0').(int)$item['id'].' | '.
                    number_format((int)$item['price']).\achbotMessage('manual_payment.manual_receipts.text.e9f634ddbd').
                    (int)$item['user_id'];
                $rows[]=[['text'=>$label,
                    'callback_data'=>'npReviewCase_'.(int)$item['id']]];
            }
            if (!$items) {
                $body=$hasCursor
                    ? \achbotMessage('manual_payment.manual_receipts.text.46ec3f45b9')
                    : \achbotMessage('manual_payment.manual_receipts.text.40b9cc08aa').
                      \achbotMessage('manual_payment.manual_receipts.text.1ce7bc1f9c');
            } else {
                $body=\achbotMessage('manual_payment.manual_receipts.body.13e6c95688').
                    \achbotMessage('manual_payment.manual_receipts.text.e06224688d').
                    \achbotMessage('manual_payment.manual_receipts.text.1993d8c086');
            }
            if ($hasOlder) {
                // Anchor after the last displayed row; next page excludes it.
                $last=$items[count($items)-1];
                $stamp=str_replace(['-',' ',':'],'',(string)$last['updated_at']);
                if (!preg_match('/^[0-9]{14}$/D',$stamp))
                    throw new RuntimeException('operator_inbox_cursor_unconfirmed');
                $rows[]=[['text'=>\achbotMessage('manual_payment.manual_receipts.button.2a07cf7022'),
                    'callback_data'=>'npReviewInbox_'.$stamp.'_'.(int)$last['id']]];
            }
            if ($hasCursor)
                $rows[]=[['text'=>\achbotMessage('manual_payment.manual_receipts.button.407c2a3b62'),
                    'callback_data'=>'npReviewInbox']];
            $rows[]=[['text'=>\achbotMessage('manual_payment.manual_receipts.button.0fbb8a589a'),'callback_data'=>'attentionInbox']];
            $rows[]=[['text'=>\achbotMessage('manual_payment.manual_receipts.button.02aaa18210'),
                       'callback_data'=>'bankReviewInbox']];
            $rows[]=[['text'=>\achbotMessage('manual_payment.manual_receipts.button.c7ac8dfee0'),'callback_data'=>'managePanel']];
            answerCallbackQuery($callbackId,\achbotMessage('manual_payment.manual_receipts.alert.3f27859840'));
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
            answerCallbackQuery($callbackId,\achbotMessage('manual_payment.manual_receipts.alert.5e6ddc1f5c'),true);
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
            'INCREASE_WALLET'=>\achbotMessage('manual_payment.manual_receipts.text.991ae08d64'),
            'BUY_SUB'=>\achbotMessage('manual_payment.manual_receipts.text.e6343571a4'),
            'RENEW_ACCOUNT'=>\achbotMessage('manual_payment.manual_receipts.text.df1c62b99e'),
            default=>\achbotMessage('manual_payment.manual_receipts.text.df83eb82dd'),
        };
        $handle=ltrim((string)($case['user_handle']??''),'@');
        $body=\achbotMessage('manual_payment.manual_receipts.body.8725ef87b4').$attemptId."</b>\n\n".
              \achbotMessage('manual_payment.manual_receipts.text.73d52bae11').$esc($case['user_name']?:\achbotMessage('manual_payment.manual_receipts.text.f07a81a062')).
              ($handle!==''?' (@'.$esc($handle).')':'')."\n".
              \achbotMessage('manual_payment.manual_receipts.text.7b98936196').(int)$case['user_id']."</code>\n".
              \achbotMessage('manual_payment.manual_receipts.text.cc92486df2').$subject.
              (!empty($case['plan_title'])?\achbotMessage('manual_payment.manual_receipts.text.3ecde6c863').$esc($case['plan_title']):'')."\n".
              \achbotMessage('manual_payment.manual_receipts.text.4edea352b1').number_format((int)$case['price']).\achbotMessage('manual_payment.manual_receipts.text.81a62ff6c6').
              \achbotMessage('manual_payment.manual_receipts.text.8ec86d6a5e').$esc($case['quote_amount']).' '.
              $esc($case['quote_currency'])."\n".
              \achbotMessage('manual_payment.manual_receipts.text.36ea78fe22').
              \achbotMessage('manual_payment.manual_receipts.text.15263040e4').$esc($case['order_hash'])."</code>\n".
              \achbotMessage('manual_payment.manual_receipts.text.d5edc0c62b').$esc($case['provider_reference'])."</code>\n".
              \achbotMessage('manual_payment.manual_receipts.text.701b748131').$esc($case['payment_id']?:\achbotMessage('manual_payment.manual_receipts.text.5749a86140'))."</code>\n".
              \achbotMessage('manual_payment.manual_receipts.text.293c249101').$esc($case['stage'])."</code>\n".
              \achbotMessage('manual_payment.manual_receipts.text.91c6437ebc').$esc($case['updated_at'])."\n\n".
              \achbotMessage('manual_payment.manual_receipts.text.50a075354e').
              ($cursor>0?\achbotMessage('manual_payment.manual_receipts.text.b93ce429b1'):'');
        foreach ($children as $i=>$child) {
            $status=match ((string)$child['observed_status']) {
                'waiting'=>\achbotMessage('manual_payment.manual_receipts.text.24b45eeaa4'),
                'failed','expired'=>\achbotMessage('manual_payment.manual_receipts.text.3425d64f6c'),
                'confirming'=>\achbotMessage('manual_payment.manual_receipts.text.06ddd1f056'),
                'confirmed'=>\achbotMessage('manual_payment.manual_receipts.text.6a31c94af4'),
                'sending'=>\achbotMessage('manual_payment.manual_receipts.text.6935f64e3e'),
                'partially_paid'=>\achbotMessage('manual_payment.manual_receipts.text.21678e358d'),
                'finished'=>\achbotMessage('manual_payment.manual_receipts.text.5ddc257365'),
                'refunded'=>\achbotMessage('manual_payment.manual_receipts.text.6fdf2d7c0c'),
                default=>\achbotMessage('manual_payment.manual_receipts.text.582a8d1e93'),
            };
            $body.=\achbotMessage('manual_payment.manual_receipts.body.8cd41a21bb').$esc($child['payment_id']).'</code>: '.$status.
                \achbotMessage('manual_payment.manual_receipts.text.028fd2b704').$esc($child['received_at'])."\n";
        }
        if (!$children) $body.=\achbotMessage('manual_payment.manual_receipts.body.2f148e055f');
        if ($action==='Verify') {
            // Each callback makes AT MOST ONE provider GET (7s timeout),
            // avoiding a 5x7s webhook stall and allowing sequential review
            // of each child without storing broader merchant credentials.
            $apiKey=(string)($paymentKeys['nowpayment']??'');
            $body.=\achbotMessage('manual_payment.manual_receipts.body.96d1157ea2');
            if ($apiKey==='') {
                $body.=\achbotMessage('manual_payment.manual_receipts.body.787ece4365');
            } else {
                foreach (array_slice($children,$childIndex,1) as $child) {
                    $remote=achbotNpGetVerifiedPayment(
                        (string)$child['payment_id'],$apiKey);
                    if (!$remote ||
                        (string)($remote['payment_id']??'')!==(string)$child['payment_id'] ||
                        (string)($remote['invoice_id']??'')!==(string)$case['provider_reference'] ||
                        (string)($remote['order_id']??'')!==(string)$case['order_hash']) {
                        $body.=\achbotMessage('manual_payment.manual_receipts.body.8cd41a21bb').$esc($child['payment_id']).
                            \achbotMessage('manual_payment.manual_receipts.text.bf0550b9ae');
                        continue;
                    }
                    $raw=(string)($remote['payment_status']??'');
                    $kind=achbotNpChildEvidenceRank($raw)>0
                        ? \achbotMessage('manual_payment.manual_receipts.text.90eb6509fc')
                        : \achbotMessage('manual_payment.manual_receipts.text.bb5fcc74b7');
                    $body.=\achbotMessage('manual_payment.manual_receipts.body.8cd41a21bb').$esc($child['payment_id']).
                        '</code>: '.$esc($raw).\achbotMessage('manual_payment.manual_receipts.text.3b2e8119b6').$kind."\n";
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
                        $body.=\achbotMessage('manual_payment.manual_receipts.body.a4a120e0d3').$esc($gross).' '.
                            $esc($asset)."\n";
                    }
                    if (achbotNpChildEvidenceRank($raw)>0 &&
                        $validAmount($net) &&
                        preg_match('/^[a-z0-9]{2,32}$/iD',$netAsset)) {
                        $body.=\achbotMessage('manual_payment.manual_receipts.body.8efe531abe').$esc($net).' '.
                            $esc($netAsset)."\n";
                    }
                }
            }
        }
        $body.=\achbotMessage('manual_payment.manual_receipts.body.b167f67bcd').
            \achbotMessage('manual_payment.manual_receipts.text.f4e8720908');
        $pageSuffix=$cursor>0?'_0_'.$cursor:'';
        $buttons=[
            [['text'=>\achbotMessage('manual_payment.manual_receipts.button.462a4081da'),
              'callback_data'=>'npReviewRefresh_'.$attemptId.$pageSuffix]],
        ];
        if ($children) {
            $next=$action==='Verify'
                ? ($childIndex+1)%count($children) : 0;
            $buttons[]=[['text'=>\achbotMessage('manual_payment.manual_receipts.button.5fb8e2523c').($next+1).\achbotMessage('manual_payment.manual_receipts.text.a624a1e41f').count($children),
                         'callback_data'=>'npReviewVerify_'.$attemptId.'_'.$next.
                             ($cursor>0?'_'.$cursor:'')]];
        }
        if ($nextCursor>0) {
            $buttons[]=[['text'=>\achbotMessage('manual_payment.manual_receipts.button.af4b7d7113'),
                'callback_data'=>'npReviewCase_'.$attemptId.'_0_'.$nextCursor]];
        }
        if (in_array((string)$case['pay_state'],['pending','send'],true) &&
            (in_array((string)$case['stage'],['review','fulfilling'],true))) {
            require_once __DIR__.'/../ReviewDecisions.php';
            $decisionRecord=null;
            if (achbotNpDecisionSchemaReady($connection)) {
                $record=$connection->prepare('SELECT decision,state,outcome
                    FROM payment_review_decisions WHERE attempt_id=? LIMIT 1');
                $record->bind_param('i',$attemptId);$record->execute();
                $decisionRecord=$record->get_result()->fetch_assoc()?:null;
                $record->close();
            }
            if ($decisionRecord) {
                $body.=achbotMessageHtml('finance_workbench.prior_decision',[
                    'decision'=>$decisionRecord['decision'],
                    'state'=>$decisionRecord['state'],
                    'outcome'=>$decisionRecord['outcome']??'unknown']);
            } elseif ((string)$case['stage']==='review' &&
                      achbotNpDecisionSchemaReady($connection)) {
                $buttons[]=[[
                    ['text'=>achbotMessage('finance_workbench.preview_button'),
                        'callback_data'=>'npDecisionPA_'.$attemptId],
                    ['text'=>achbotMessage('finance_workbench.reject_button'),
                        'callback_data'=>'npDecisionPR_'.$attemptId],
                ]];
            } else {
                $body.=achbotMessage('finance_workbench.migration_required');
            }
        }
        $buttons[]=[['text'=>\achbotMessage('manual_payment.manual_receipts.button.014e4e047f'),
                     'callback_data'=>'npReviewInbox']];
        answerCallbackQuery($callbackId,\achbotMessage('manual_payment.manual_receipts.alert.671dc77b41'));
        editText($message_id,$body,json_encode(['inline_keyboard'=>$buttons],
            JSON_UNESCAPED_UNICODE),'HTML');
    } catch (Throwable $error) {
        error_log('achbot_payment_operator_inbox_unavailable');
        answerCallbackQuery($callbackId,
            \achbotMessage('manual_payment.manual_receipts.alert.55c0496662'),true);
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
    $text = \achbotMessage('finance_workbench.status_rejected');
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
    $adminReport=\achbotMessageHtml('finance_workbench.rejected_report',[
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

