<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/ReceiptHelpers.php';
require_once __DIR__.'/CheckoutAdmission.php';
require_once __DIR__.'/ReceiptReports.php';
require_once __DIR__.'/FollowupOutbox.php';

/** Admission and the original delivery intents have ONE SQL commit.
 * No staff/customer transport result is a financial admission prerequisite. */
function achbotManualAdmitReceipt(mysqli $db,array $original,string $method,int $actor,
    string $evidence,string $caption,string $keys,
    ?string $expectedMode=null,?string $expectedStep=null,?array $screen=null): bool {
    $id=(int)$original['id'];
    $opened=false;
    try {
        achbotPaymentEvidenceRequireAutocommit($db);
        if (!achbotFollowupSchemaReady($db) || !$db->begin_transaction())
            throw new RuntimeException('receipt_admission_storage_unavailable');
        $opened=true;
        [$pay,$a]=achbotCreditInvoiceLocked($db,$id,$actor);
        if (!hash_equals(achbotPaymentEvidencePayFingerprint($original),achbotPaymentEvidencePayFingerprint($pay)) ||
            ($expectedMode!==null && !achbotCheckoutModeMatchesRequest($pay,$expectedMode)) ||
            !achbotManualReceiptEligible($pay,$a,$method))
            throw new DomainException('receipt_admission_stale');
        if ($expectedStep!==null) {
            $current=achbotProviderRow($db,'SELECT step FROM users WHERE userid=? FOR UPDATE','i',[$actor]);
            if (($current['step']??null)!==$expectedStep)
                throw new DomainException('receipt_admission_step_changed');
        }
        $typed=achbotManualTypedGiftAttempt($a);
        if ($evidence==='' || strlen($evidence)>($typed?4096:1200))
            throw new DomainException('receipt_admission_invalid_evidence');
        achbotPayUxCloseInvoiceLocked($db,$pay,$a);
        if ($pay['state']==='expired') {
            $s=achbotProviderStatement($db,"UPDATE pays SET state='service_superseded' WHERE id=? AND state='expired'",'i',[$id]);
            $changed=$s->affected_rows;$s->close();
            if ($changed!==1) throw new RuntimeException('receipt_admission_expiry_changed');
            $pay['state']='service_superseded';
        }
        // Late proof remains money evidence, never a new service admission.
        $intent=achbotManualIntentDecode((string)$a['checkout_url'],$method);
        if (!$intent || isset($intent['receipt_file_id']))
            throw new DomainException('receipt_admission_original_conflict');
        if ($a['provider_status']===null) {
            $s=achbotProviderStatement($db,"UPDATE payment_attempts SET provider_status='receipt_started'
                WHERE id=? AND stage='pending' AND provider_status IS NULL",'i',[(int)$a['id']]);
            $s->close();$a['provider_status']='receipt_started';
        }
        if ($typed) {
            if (!achbotManualGiftReviewFits($caption,$evidence,$screen))
                throw new DomainException('receipt_admission_gift_text_invalid');
            $saved=achbotPaymentEvidenceSaveGiftCodeLocked($db,$pay,$a,$actor,$evidence);
            if (!in_array($saved['status']??null,['saved','same'],true))
                throw new DomainException('receipt_admission_gift_conflict');
            $caption=achbotManualGiftReviewText($caption,$evidence,$screen);
        } else $intent['receipt_file_id']=$evidence;
        $encoded=achbotManualIntentEncode($intent);
        if ($encoded===null) throw new DomainException('receipt_admission_intent_invalid');
        $s=achbotProviderStatement($db,"UPDATE payment_attempts SET stage='review',checkout_url=?
            WHERE id=? AND stage='pending'",'si',[$encoded,(int)$a['id']]);
        $changed=$s->affected_rows;$s->close();
        if ($changed!==1) throw new RuntimeException('receipt_admission_attempt_changed');
        if ($pay['state']==='pending') {
            $s=achbotProviderStatement($db,"UPDATE pays SET state='have_sent' WHERE id=? AND state='pending'",'i',[$id]);
            $changed=$s->affected_rows;$s->close();
            if ($changed!==1) throw new RuntimeException('receipt_admission_pay_changed');
            $pay['state']='have_sent';
        }
        $a=achbotProviderRow($db,'SELECT * FROM payment_attempts WHERE id=? FOR UPDATE','i',[(int)$a['id']]);
        $proof=achbotManualAdmittedEvidence($pay,$a);
        if (!$proof || $proof['value']!==$evidence)
            throw new RuntimeException('receipt_admission_proof_changed');
        $primary=(int)($GLOBALS['admin']??0);
        if ($primary<=0) throw new RuntimeException('receipt_admission_owner_missing');
        $stage='receipt_admitted';$fingerprint=$proof['fingerprint'];
        $customerText=achbotPayUxStatusText($pay,$a);
        $customerKeys=achbotPayUxStatusKeyboard((string)$pay['hash_id'],$pay,$a);
        achbotFollowupStoreLocked($db,$id,null,$stage,'customer','send',(string)$actor,null,$fingerprint,
            ['text'=>$customerText,'keys'=>$customerKeys,'mode'=>'HTML']);
        $packet=['text'=>$caption,'keys'=>$keys,'mode'=>'HTML'];
        if (!$typed) $packet['photo']=$evidence;
        $targets=[$primary];
        $complete=false;
        $candidates=achbotAdminRecipientCandidates($db,$primary,$complete,true);
        if (!$complete) throw new RuntimeException('receipt_admission_audience_unavailable');
        foreach ($candidates as $recipient) {
            $complete=true;
            if (achbotAdminNotificationAllowed($db,$recipient,$primary,'manual_review',$complete,true))
                $targets[]=(int)$recipient;
            if (!$complete) throw new RuntimeException('receipt_admission_audience_unavailable');
        }
        foreach ($targets as $recipient)
            achbotFollowupStoreLocked($db,$id,null,$stage,'receipt_notice','send',(string)$recipient,null,$fingerprint,$packet);
        if ($expectedStep!==null) {
            $s=achbotProviderStatement($db,"UPDATE users SET step='none' WHERE userid=? AND BINARY step=BINARY ?",'is',[$actor,$expectedStep]);
            $changed=$s->affected_rows;$s->close();
            if ($changed!==1) throw new RuntimeException('receipt_admission_step_changed');
        }
        if (!$db->commit()) throw new RuntimeException('receipt_admission_commit_unknown');
        return true;
    } catch (Throwable $e) {
        if ($opened) try {$db->rollback();} catch(Throwable $ignored) {}
        achbotDiagnosticEvent('achbot.finance.receipt_admission_deferred',['pay_id'=>$id,'actor_id'=>$actor]+achbotDiagnosticLocalFailure($e),'warning');
        return false;
    }
}

/** Exact original receipt message references. Historical delivered refs remain
 * readable; new unsolicited copies are owned only by the ACK journal. */
function achbotReceiptMessageTargets(mysqli $db,array $pay,bool $locked=false): array {
    $targets=[];$seen=[];
    $add=static function($chat,$message,string $role) use (&$targets,&$seen): void {
        $chat=(int)$chat;$message=(int)$message;$key=$chat.':'.$message;
        if ($chat<=0 || $message<=0 || isset($seen[$key])) return;
        $seen[$key]=true;$targets[]=['chat_id'=>$chat,'message_id'=>$message,'role'=>$role];
    };
    $add($pay['chat_id']??0,$pay['message_id']??0,'receipt_primary');
    $copies=json_decode((string)($pay['admins_messages']??''),true);
    if (is_array($copies)) foreach ($copies as $copy)
        if (is_array($copy)) $add($copy['chat_id']??0,$copy['message_id']??0,'receipt_mirror');
    $s=achbotProviderStatement($db,"SELECT a.ack_chat_id,a.ack_message_id
        FROM payment_followup_intents f JOIN payment_followup_attempts a ON a.intent_id=f.id
        WHERE f.pay_row_id=? AND f.stage='receipt_admitted' AND (f.role='receipt_notice' OR f.role REGEXP '^view_[1-9][0-9]{0,18}$')
          AND a.result='acknowledged' AND a.ack_chat_id>0 AND a.ack_message_id>0
          AND a.wire_action='send' AND a.wire_recipient=f.recipient
          AND CAST(a.ack_chat_id AS CHAR)=f.recipient ORDER BY a.id".($locked?' FOR UPDATE':''),'i',[(int)$pay['id']]);
    foreach ($s->get_result()->fetch_all(MYSQLI_ASSOC) as $ack)
        $add($ack['ack_chat_id'],$ack['ack_message_id'],
            (int)$ack['ack_chat_id']===(int)($GLOBALS['admin']??0)?'receipt_primary':'receipt_mirror');
    $s->close();
    return $targets;
}
function achbotReceiptTargetAllowed(mysqli $db,array $pay,array $r): bool {
    foreach (achbotReceiptMessageTargets($db,$pay,true) as $target)
        if ($r['recipient']===(string)$target['chat_id'] && (int)$r['message_id']===$target['message_id'])
            return in_array($r['role'],['receipt_primary','receipt_mirror'],true);
    return false;
}

/** Receipt content is sealed and bound to the actual admitted source.
 * A current staff permission denial never authorizes delivery. */
function achbotReceiptNoticeValidateLocked(mysqli $db,array $pay,array $r): ?array {
    if ($r['service_operation_id']!==null || $r['kind']!=='send' || $r['message_id']!==null)
        return null;
    $a=achbotProviderRow($db,'SELECT * FROM payment_attempts WHERE pay_row_id=? FOR UPDATE','i',[(int)$pay['id']]);
    $proof=$a?achbotManualAdmittedEvidence($pay,$a):null;
    if (!$proof) return null;
    $packet=achbotFollowupDecodePacket($pay,$r,$proof['fingerprint']);
    if (!$packet) return null;
    if ($r['role']==='customer') {
        if ($r['recipient']!==(string)(int)$pay['user_id'] || isset($packet['photo'])) return null;
        // The sealed admission packet remains evidence. Delivery renders the
        // current status, including a decision committed before this send.
        if ($pay['state']==='paid') {
            require_once __DIR__.'/CheckoutRoutes.php';
            $pay['projection_outcome']=achbotCheckoutReceiptOutcomeLocked($db,(int)$pay['id'],(int)$pay['user_id']);
        }
        $packet['text']=achbotPayUxStatusText($pay,$a);
        $packet['keys']=achbotPayUxStatusKeyboard((string)$pay['hash_id'],$pay,$a);
        return $packet;
    }
    if (!achbotFollowupReceiptStaffRole($r['role']) || (string)(int)$r['recipient']!==$r['recipient'] ||
        ($proof['kind']==='photo'?($packet['photo']??null)!==$proof['value']:isset($packet['photo']))) return null;
    $complete=true;
    $allowed=$r['role']==='receipt_notice'
        ?achbotAdminNotificationAllowed($db,(int)$r['recipient'],(int)($GLOBALS['admin']??0),'manual_review',$complete)
        :achbotAdminLevelCan(achbotAdminLevel((int)$r['recipient'],$db,(int)($GLOBALS['admin']??0),true,true,$complete),'payments');
    if (!achbotFollowupRequireRecipient($allowed,$complete)) return null;
    // If money/rejection already finalized, deliver the retained original
    // evidence with read-only controls, not obsolete approval actions.
    $terminal=achbotReceiptTerminalMarkupLocked($db,$pay,$a);
    if ($terminal!==null) $packet['keys']=$terminal;
    return $packet;
}

/** Register the exact customer ACK and converge any late staff ACK with the
 * same finality projector as an ordinary approval/rejection. */
function achbotReceiptNoticeFinishLocked(mysqli $db,array $pay,array $r,array $outcome,array $wirePacket): void {
    if ($outcome['result']!=='acknowledged') return;
    $ack=$outcome['ack'];$id=(int)$pay['id'];
    if ($r['role']==='customer') {
        require_once __DIR__.'/PaymentMessages.php';
        if (!achbotPayUxRecordReceiptAck($db,$id,(int)$pay['user_id'],$ack['message_id'],$wirePacket))
            throw new RuntimeException('receipt_customer_ack_registration_failed');
        return;
    }
    $a=achbotProviderRow($db,'SELECT * FROM payment_attempts WHERE pay_row_id=? FOR UPDATE','i',[$id]);
    if (!$a) throw new RuntimeException('receipt_notice_source_missing');
    $terminal=achbotReceiptTerminalMarkupLocked($db,$pay,$a);
    if ($terminal!==null && $terminal!==($wirePacket['keys']??null))
        achbotReceiptStageTerminalEditsLocked($db,$pay,$a,
            [['chat_id'=>$ack['chat_id'],'message_id'=>$ack['message_id'],
              'role'=>$ack['chat_id']===(int)($GLOBALS['admin']??0)?'receipt_primary':'receipt_mirror']]);
}

/** An explicit authenticated private request may show a fresh evidence copy.
 * Replaying the same Telegram update never generates another send intent. */
function achbotReceiptRequestView(mysqli $db,int $id,int $actor,int $updateId): ?int {
    if ($id<=0 || $actor<=0 || $updateId<=0) return null;
    $opened=false;
    try {
        achbotPaymentEvidenceRequireAutocommit($db);
        $owner=achbotProviderRow($db,'SELECT user_id FROM pays WHERE id=?','i',[$id]);
        if (!$owner || !achbotFollowupSchemaReady($db) || !$db->begin_transaction())
            return null;
        $opened=true;
        [$pay,$a]=achbotCreditInvoiceLocked($db,$id,(int)$owner['user_id']);
        if (!achbotFollowupStaffAllowed($db,$actor,(int)($GLOBALS['admin']??0),'payments') ||
            !in_array($pay['state'],['have_sent','service_superseded'],true))
            throw new DomainException('receipt_view_ineligible');
        $proof=achbotManualAdmittedEvidence($pay,$a);
        if (!$proof) throw new DomainException('receipt_view_evidence_invalid');
        $role='view_'.$updateId;
        $key=achbotFollowupIntentKey('receipt_admitted',$role,'send',(string)$actor,null);
        $r=achbotProviderRow($db,'SELECT id,pay_fingerprint FROM payment_followup_intents WHERE pay_row_id=? AND intent_key=?','is',[$id,$key]);
        if (!$r) {
            $body=achbotMessage('automatic_gateway_review.case_title')."\n".
                achbotMessageHtml('automatic_gateway_review.customer',['id'=>$pay['user_id']])."\n".
                achbotMessageHtml('automatic_gateway_review.amount',['amount'=>number_format((int)$pay['price'])])."\n".
                achbotMessageHtml('automatic_gateway_review.reference',['hash'=>$pay['hash_id']])."\n".
                achbotMessageHtml('automatic_gateway_review.api_quote',['amount'=>$a['quote_amount'],'currency'=>$a['quote_currency']]);
            $keys=inlineKeyboard([[
                ['text'=>achbotMessage('legacy_buttons.decline'),'callback_data'=>'declineAction_'.$pay['hash_id']],
                ['text'=>achbotMessage('legacy_buttons.approve'),'callback_data'=>achbotManualApprovalCallbackData($pay)]
            ],[['text'=>achbotMessage('automatic_gateway_review.open_case'),'callback_data'=>'attentionOpenCase_'.$id]]]);
            $packet=['text'=>$body,'keys'=>$keys,'mode'=>'HTML'];
            if ($proof['kind']==='typed')
                $packet['text'].="\n<code>".htmlspecialchars($proof['value'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</code>';
            else $packet['photo']=$proof['value'];
            achbotFollowupStoreLocked($db,$id,null,'receipt_admitted',$role,'send',(string)$actor,null,$proof['fingerprint'],$packet);
            $r=achbotProviderRow($db,'SELECT id,pay_fingerprint FROM payment_followup_intents WHERE pay_row_id=? AND intent_key=?','is',[$id,$key]);
        }
        if (!$r || !hash_equals($r['pay_fingerprint'],$proof['fingerprint']) || !$db->commit())
            throw new RuntimeException('receipt_view_unconfirmed');
        return (int)$r['id'];
    } catch (Throwable $e) {
        if ($opened) try {$db->rollback();} catch (Throwable $ignored) {}
        achbotDiagnosticEvent('achbot.finance.receipt_view_deferred',['pay_id'=>$id,'actor_id'=>$actor]+achbotDiagnosticLocalFailure($e),'warning');
        return null;
    }
}

/** Nonterminal staff status updates must not overwrite a newer terminal edit.
 * The existing target lease orders only Telegram projections, not money. */
function achbotReceiptStatusEdit(mysqli $db,array $expected,int $chat,int $message,callable $send): ?bool {
    $name=achbotFollowupLeaseName($db,[
        'pay_row_id'=>(int)$expected['id'],'kind'=>'edit','role'=>'receipt_primary',
        'recipient'=>(string)$chat,'message_id'=>$message]);
    $locked=achbotProviderRow($db,'SELECT GET_LOCK(?,0) AS acquired','s',[$name]);
    if ((int)($locked['acquired']??0)!==1) return null;
    try {
        $current=achbotProviderRow($db,'SELECT state,approved_by FROM pays WHERE id=?','i',[(int)$expected['id']]);
        if (!$current || $current['state']!==$expected['state'] ||
            ($current['approved_by']??null)!==($expected['approved_by']??null)) return null;
        $complete=true;
        $allowed=achbotAdminStoredRecipientAllowed($db,$chat,(int)($GLOBALS['admin']??0),true,$complete);
        if (!$complete) return null;
        if (!$allowed) return false;
        return $send();
    } finally {
        $q=achbotProviderStatement($db,'SELECT RELEASE_LOCK(?)','s',[$name]);$q->close();
    }
}
