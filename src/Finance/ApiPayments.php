<?php
/** Optional existing-API evidence inside the original manual payment ledger. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }
require_once __DIR__.'/ReceiptHelpers.php';
require_once __DIR__.'/PaymentLifecycle.php';
if (is_file(__DIR__.'/PaymentEvidence.php')) require_once __DIR__.'/PaymentEvidence.php';

function achbotApiPaymentReady(mysqli $db): bool {
    return function_exists('achbotPaymentEvidenceSchemaReady') && achbotPaymentEvidenceSchemaReady($db);
}
/** Presence is independent of decryptability: a broken pin never becomes legacy. */
function achbotApiPaymentPinned(mysqli $db,int $id): bool {
    try {$q=$db->prepare('SELECT evidence_profile FROM payment_attempts WHERE pay_row_id=?');$q->bind_param('i',$id);$q->execute();$a=$q->get_result()->fetch_assoc();$q->close();return is_array($a)&&is_string($a['evidence_profile'])&&$a['evidence_profile']!=='';}catch(Throwable $e){return false;}
}
function achbotApiPaymentRow(mysqli $db,int $id,bool $locked=false): ?array {
    $q=$db->prepare('SELECT * FROM pays WHERE id=?'.($locked?' FOR UPDATE':''));
    $q->bind_param('i',$id);$q->execute();$row=$q->get_result()->fetch_assoc();$q->close();
    return $row?:null;
}
function achbotApiPaymentAttempt(mysqli $db,int $id,bool $locked=false): ?array {
    $q=$db->prepare('SELECT *,TIMESTAMPDIFF(SECOND,created_at,NOW()) AS age_seconds FROM payment_attempts WHERE pay_row_id=?'.($locked?' FOR UPDATE':''));
    $q->bind_param('i',$id);$q->execute();$row=$q->get_result()->fetch_assoc();$q->close();return $row?:null;
}
/** Called only during ORIGINAL issuance, inside its existing transaction. */
function achbotApiPaymentPinLocked(mysqli $db,array $pay,array $attempt): void {
    if (!function_exists('achbotPaymentEvidenceProfile')) return;
    $profile=achbotPaymentEvidenceProfile((string)$attempt['provider']);
    if ($profile===null) return;
    if (!achbotApiPaymentReady($db)) throw new RuntimeException('api_payment_schema_unavailable');
    $subject=[];$type=(string)$pay['type'];
    if (in_array($type,['BUY_SUB','RENEW_ACCOUNT','RENEW_SCONFIG'],true)) {
        $id=(int)$pay['plan_id'];
        $q=$db->prepare('SELECT server_id,inbound_id,days,volume,active FROM server_plans WHERE id=? FOR UPDATE');
        $q->bind_param('i',$id);$q->execute();$plan=$q->get_result()->fetch_assoc();$q->close();
        if (!$plan || (int)$plan['active']!==1 || (int)$plan['inbound_id']!==0 ||
            ($type!=='RENEW_ACCOUNT' && (int)$plan['server_id']!==(int)$pay['server_id']))
            throw new RuntimeException('api_payment_subject_unavailable');
        $custom=$type==='BUY_SUB' && ((int)$pay['day']>0 || (int)$pay['volume']>0);
        $subject=['days'=>$custom?(int)$pay['day']:(int)$plan['days'],
            'volume'=>$custom?(int)$pay['volume']:(int)$plan['volume'],
            'count'=>max(1,(int)($pay['agent_count']??0))];
        if ($subject['days']<=0 || $subject['days']>36500 ||
            ($type==='BUY_SUB' ? $subject['volume']<=0 : $subject['volume']<0) ||
            $subject['volume']>intdiv(PHP_INT_MAX,1073741824) || $subject['count']>(int)$pay['price'])
            throw new RuntimeException('api_payment_entitlement_invalid');
    } elseif (preg_match('/^INCREASE_(DAY|VOLUME)_/', $type)) {
        $units=achbotPinnedAddonUnits($pay);
        if ($units===null) throw new RuntimeException('api_payment_units_invalid');
        $subject=['units'=>$units];
    } elseif ($type!=='INCREASE_WALLET') throw new RuntimeException('api_payment_subject_unsupported');
    $profile['subject']=$subject;
    if (!achbotPaymentEvidencePinLocked($db,$pay,$attempt,$profile))
        throw new RuntimeException('api_payment_pin_unconfirmed');
}
function achbotApiPaymentContext(mysqli $db,string $hash,int $actor=0): ?array {
    if (!achbotApiPaymentReady($db)) return null;
    try {
        $q=$db->prepare('SELECT * FROM pays WHERE hash_id=? LIMIT 1');$q->bind_param('s',$hash);
        $q->execute();$pay=$q->get_result()->fetch_assoc();$q->close();
        if (!$pay || ($actor>0 && (int)$pay['user_id']!==$actor)) return null;
        $attempt=achbotApiPaymentAttempt($db,(int)$pay['id']);
        $profile=$attempt?achbotPaymentEvidenceSnapshot($attempt):null;
        return $profile===null?null:['pay'=>$pay,'attempt'=>$attempt,'profile'=>$profile];
    } catch (Throwable $e) {return null;}
}
function achbotApiPaymentTerminal(array $pay): bool {
    return in_array((string)($pay['state']??''),['approved','paid','auto_cart_approved','paid_with_wallet'],true);
}
/** Original invoice callbacks, never inferred from an arbitrary submitted callback. */
function achbotApiPaymentReceiptPrefixes(array $pay): array {
    $t=(string)$pay['type'];
    if ($t==='INCREASE_WALLET') return ['sendIncreaseWalletReceipt','cancelIncreaseWalletReceipt'];
    if ($t==='RENEW_ACCOUNT' || $t==='RENEW_SCONFIG') return ['sendRenewCartReceipt','cancelRenewCartReceipt'];
    if (str_starts_with($t,'INCREASE_DAY_')) return ['sendIncreaseDayReceipt','cancelIncreaseDayReceipt'];
    if (str_starts_with($t,'INCREASE_VOLUME_')) return ['sendIncreaseVolumeReceipt','cancelIncreaseVolumeReceipt'];
    return ((int)$pay['day']>0 || (int)$pay['volume']>0)?['sendCustomCartReceipt','cancelCustomCartReceipt']:['sendBuyCartReceipt','cancelBuyCartReceipt'];
}
function achbotApiPaymentKeyboard(array $context,?string $sendPrefix=null,?string $cancelPrefix=null): string {
    $pay=$context['pay'];$a=$context['attempt'];$hash=(string)$pay['hash_id'];
    [$send,$cancel]=achbotApiPaymentReceiptPrefixes($pay);$send=$sendPrefix??$send;$cancel=$cancelPrefix??$cancel;
    if (achbotApiPaymentTerminal($pay) || !in_array($pay['state'],['pending','have_sent'],true))
        return achbotPayUxStatusKeyboard($hash);
    $gift=$a['provider']==='gift_card';
    $canCode=$gift && (empty($a['evidence_code']) || ($a['evidence_state']??'')==='invalid_code');
    $rows=[[['text'=>achbotMessage('payment.api_payment.'.($canCode?'code':'check')),'callback_data'=>($gift?'apiGift_':'apiCheck_').$hash]]];
    if (!$gift && $pay['state']==='pending' && (int)$a['age_seconds']>=600)
        $rows[]=[['text'=>achbotMessage('payment.api_payment.receipt'),'callback_data'=>$send.$hash]];
    if($gift){$intent=achbotManualIntentDecode((string)$a['checkout_url'],'gift_card');$url=$intent['seller_url']??null;if(is_string($url)&&achbotManualGiftSellerUrlValid($url))$rows[]=[['text'=>achbotMessage('telegram.markup.receipt_and_cancel.button.4595a70ab5'),'url'=>$url]];}
    $rows[]=[['text'=>achbotMessage('telegram.markup.receipt_and_cancel.button.cc357a4c9f'),'callback_data'=>$cancel.$hash]];
    return inlineKeyboard($rows);
}
/** API invoices use truthful API copy; legacy screenshot instructions stay OFF-only. */
function achbotApiPaymentInvoiceText(array $c): string {
    $p=$c['pay'];$a=$c['attempt'];$amount=achbotPaymentEvidenceIntegralQuote($a['quote_amount']??null);if($amount===null)return '';
    $gift=$a['provider']==='gift_card';$intent=achbotManualIntentDecode((string)$a['checkout_url'],(string)$a['provider']);if(!$intent)return '';
    if($gift)$body=achbotMessageHtml('payment.api_payment.gift_invoice',['amount'=>$amount]);
    else {
        if((int)$amount>intdiv(PHP_INT_MAX,10))return '';
        $body=achbotMessageHtml('payment.api_payment.bank_invoice',['amountToman'=>$amount,'amountRial'=>(string)((int)$amount*10),'account'=>(string)($intent['account']??''),'holder'=>(string)($intent['holder']??'')]);
    }
    if($gift){
        if(empty($a['evidence_code']))$key='code_prompt';
        elseif(($a['evidence_state']??'')==='invalid_code'){
            $d=achbotPaymentEvidenceUnprotect('code:'.$a['id'].':'.$p['id'],(string)$a['evidence_code']);$key=in_array($d['last_status']??'',['used','revoked','expired','mismatch'],true)?$d['last_status']:'code_prompt';
        } elseif(!empty($a['evidence_review_sent_at']))$key='review';
        elseif(($a['evidence_state']??'')==='review')$key='code_received';
        else $key='pending';
    } else $key=$p['state']==='have_sent'?'receipt_review':($a['stage']==='receiving'?'pending':((int)$a['age_seconds']>=600?'bank_fallback':'bank_pending'));
    return achbotPayUxReference($p).$body."\n\n".achbotMessage('payment.api_payment.'.$key);
}
/** Post-send source-real reference; first successful invoice response wins. */
function achbotApiPaymentTrackInvoice(mysqli $db,string $hash,int $actor,$response): void {
    $message=(int)($response->result->message_id??0);
    if ($message<=0 || $actor<=0 || !achbotApiPaymentReady($db)) return;
    try {
        $q=$db->prepare('UPDATE payment_attempts a JOIN pays p ON p.id=a.pay_row_id
            SET a.evidence_invoice_chat_id=?,a.evidence_invoice_message_id=?
            WHERE p.hash_id=? AND p.user_id=? AND a.evidence_profile IS NOT NULL
              AND a.evidence_invoice_message_id IS NULL');
        $q->bind_param('iisi',$actor,$message,$hash,$actor);$q->execute();$q->close();
        // If settlement won while Telegram was sending, immediately converge.
        $c=achbotApiPaymentContext($db,$hash,$actor);if ($c) achbotApiPaymentSyncInvoice($db,$c);
    } catch (Throwable $e) {error_log('achbot_api_invoice_reference_deferred');}
}
function achbotApiPaymentSyncInvoice(mysqli $db,array $c): bool {
    $p=$c['pay'];$a=$c['attempt'];$id=(int)$p['id'];
    $chat=(int)($a['evidence_invoice_chat_id']??0);$message=(int)($a['evidence_invoice_message_id']??0);
    if ($chat!==(int)$p['user_id'] || $chat<=0 || $message<=0 || !function_exists('bot')) return false;
    if (achbotApiPaymentTerminal($p) || !in_array($p['state'],['pending','have_sent'],true))
        $body=achbotPayUxStatusText($p,$a);
    else {
        $body=achbotApiPaymentInvoiceText($c);
        if ($body==='') return false;
    }
    $keys=achbotApiPaymentKeyboard($c);$digest=hash('sha256',$body."\n".$keys);
    if (($a['evidence_invoice_digest']??null)===$digest) return true;
    try {
        $response=bot('editMessageText',['chat_id'=>$chat,'message_id'=>$message,'text'=>$body,'parse_mode'=>'HTML','reply_markup'=>$keys,'disable_web_page_preview'=>true]);
        if (!is_object($response) || ($response->ok??false)!==true) return false;
        // Compare current financial state after the asynchronous edit. A stale
        // pending edit may never stamp a terminal message as synchronized.
        $fresh=achbotApiPaymentRow($db,$id);
        if (!$fresh || $fresh['state']!==$p['state']) {
            $again=achbotApiPaymentContext($db,(string)$p['hash_id']);
            if ($again) return achbotApiPaymentSyncInvoice($db,$again);
            return false;
        }
        $q=$db->prepare('UPDATE payment_attempts SET evidence_invoice_digest=? WHERE pay_row_id=?');
        $q->bind_param('si',$digest,$id);$q->execute();$q->close();return true;
    } catch (Throwable $e) {error_log('achbot_api_invoice_edit_deferred pay_id='.$id);return false;}
}
/** No general report reaches Operator/Support; existing copies still converge. */
function achbotApiPaymentAck($response): bool {
    return $response===true || (is_object($response)&&($response->ok??false)===true) || (is_array($response)&&($response['ok']??false)===true);
}
function achbotApiPaymentNotice(mysqli $db,array $pay,string $status,bool $report=true): void {
    $id=(int)$pay['id'];$hash=(string)$pay['hash_id'];$a=achbotApiPaymentAttempt($db,$id);if(!$a)return;
    $proof=achbotPaymentEvidenceGetProof($db,$id);$pending=$proof?null:achbotPaymentEvidencePending($db,$id);
    $evidence=$proof['operation']??($pending['operation']??('invoice-'.$id));
    $status=in_array($status,['confirmed','settled','review','uncertain','evidence_only'],true)?$status:'review';
    $label=achbotMessage('manual_payment.api_payment.status_'.$status);
    $amount=achbotPaymentEvidenceIntegralQuote($a['quote_amount'])??(string)$a['quote_amount'];
    $body=achbotMessageHtml('manual_payment.api_payment.report',[
        'reference'=>$hash,'userId'=>(string)$pay['user_id'],'amount'=>$amount,'unit'=>'تومان',
        'method'=>achbotManualLabel((string)$a['provider']),'evidence'=>$evidence,
        'subject'=>payTypeToDescription((string)$pay['type']),'status'=>$label]);
    $digest=hash('sha256',$body);
    if($report && ($a['evidence_report_digest']??null)!==$digest && function_exists('achbotNotifyAdmins')) {
        try {
            $results=achbotNotifyAdmins('sendMessage',['text'=>$body,'parse_mode'=>'HTML']);$ack=count($results)>0;
            foreach($results as $result)if(!achbotApiPaymentAck($result['response']??null))$ack=false;
            if($ack){$q=$db->prepare('UPDATE payment_attempts SET evidence_report_digest=? WHERE pay_row_id=?');$q->bind_param('si',$digest,$id);$q->execute();$q->close();}
        }catch(Throwable $e){error_log('achbot_api_report_delivery_deferred pay_id='.$id);}
    }
    $refs=json_decode((string)($pay['admins_messages']??''),true);$refs=is_array($refs)?$refs:[];
    $refs[]=['chat_id'=>$pay['chat_id']??0,'message_id'=>$pay['message_id']??0];$seen=[];
    $keys=inlineKeyboard([[['text'=>$label,'callback_data'=>'noop_'.$hash]]]);$staffDigest=hash('sha256',$keys.json_encode($refs));
    if(($a['evidence_staff_digest']??null)!==$staffDigest) {
        $ack=true;
        foreach($refs as $ref) {
            $chat=(int)($ref['chat_id']??0);$msg=(int)($ref['message_id']??0);$key=$chat.':'.$msg;
            if($msg<=0||isset($seen[$key])||!achbotAdminStoredRecipientAllowed($db,$chat,(int)($GLOBALS['admin']??0)))continue;
            $seen[$key]=true;try{if(!achbotApiPaymentAck(editKeys($keys,$msg,$chat)))$ack=false;}catch(Throwable $e){$ack=false;}
        }
        if($ack){$q=$db->prepare('UPDATE payment_attempts SET evidence_staff_digest=? WHERE pay_row_id=?');$q->bind_param('si',$staffDigest,$id);$q->execute();$q->close();}
    }
    $c=achbotApiPaymentContext($db,$hash);if($c)achbotApiPaymentSyncInvoice($db,$c);
}
/** Valid only with a genuine recoverable typed-code review, never a fake photo. */
function achbotApiPaymentTypedReviewLocked(mysqli $db,array $pay): bool {
    return function_exists('achbotPaymentEvidenceHasTypedReviewLocked') && achbotPaymentEvidenceHasTypedReviewLocked($db,$pay);
}
/** Called under the actual locked pays claim, also used by CREATE ownership. */
function achbotApiPaymentProcessingProofLocked(mysqli $db,int $id): bool {
    if (!achbotApiPaymentReady($db)) return false;
    $pay=achbotApiPaymentRow($db,$id,true);
    if (!$pay || $pay['state']!=='processing' || (int)($pay['approved_by']??-1)!==0) return false;
    $proof=achbotPaymentEvidenceGetProof($db,$id);
    return $proof!==null && ($proof['mode']??'')==='settle' && achbotPaymentEvidenceValidateProofLocked($db,$pay,$proof);
}
/** Short preflight only. Never retain SQL locks across a provider request. */
function achbotApiPaymentValidateProof(mysqli $db,array $pay,array $proof): bool {
    $tx=$db->query('SELECT @@in_transaction');$inside=$tx&&(int)$tx->fetch_row()[0]===1;
    if($inside)return achbotPaymentEvidenceValidateProofLocked($db,$pay,$proof);
    try {
        if(!$db->begin_transaction())return false;
        $fresh=achbotApiPaymentRow($db,(int)$pay['id'],true);
        $valid=$fresh && achbotPaymentEvidencePayFingerprint($fresh)===achbotPaymentEvidencePayFingerprint($pay) && achbotPaymentEvidenceValidateProofLocked($db,$fresh,$proof);
        $db->rollback();return (bool)$valid;
    }catch(Throwable $e){try{$db->rollback();}catch(Throwable $ignored){}return false;}
}
/** Canonical purchase dimensions read from the ORIGINAL encrypted issuance pin. */
function achbotApiPaymentPurchaseIntent(mysqli $db,array $pay,array $proof): ?array {
    if (!achbotApiPaymentValidateProof($db,$pay,$proof) || ($proof['mode']??'')!=='settle') return null;
    $a=achbotApiPaymentAttempt($db,(int)$pay['id'],true);$p=$a?achbotPaymentEvidenceSnapshot($a):null;$s=$p['subject']??null;
    if(($a['provider']??'')==='bank_transfer') {
        $id=(int)$pay['id'];$q=$db->prepare('SELECT * FROM bank_buy_invoice_claims WHERE pay_row_id=? FOR UPDATE');$q->bind_param('i',$id);$q->execute();$original=$q->get_result()->fetch_assoc();$q->close();
        if(!achbotBankBuyClaimMatches($pay,$original)||!achbotBankBuyAttemptMatches($pay,$a,$original))return null;
    }

    if (!is_array($s) || (int)($s['days']??0)<=0 || (int)($s['volume']??0)<=0 || (int)($s['count']??0)<=0) return null;
    return ['invoice_sha256'=>achbotBankBuyInvoiceFingerprint($pay),'account_count'=>(int)$s['count'],
        'amount'=>(int)$pay['price'],'server_id'=>(int)$pay['server_id'],'plan_id'=>(int)$pay['plan_id'],
        'entitled_days'=>(int)$s['days'],'entitled_volume'=>(int)$s['volume']];
}
/** Local admission only; fresh native eligibility belongs outside SQL locks. */
function achbotApiPaymentLocalSubjectAllowed(mysqli $db,array $pay): bool {
    if(preg_match('/^INCREASE_(?:DAY|VOLUME)_([1-9][0-9]*)_[1-9][0-9]*$/D',(string)$pay['type'],$m)) {
        $oid=(int)$m[1];return achbotPinnedAddonUnits($pay)!==null &&
            ((int)($pay['order_id']??0)===0||(int)$pay['order_id']===$oid) &&
            achbotProviderQuoteEligible($db,$pay,true) && achbotSupportsOrder($db,$oid,(int)$pay['plan_id'],(int)$pay['user_id'],true);
    }
    return achbotSupportsPaymentSubject($db,$pay,true);
}
/** Fresh native eligibility for a pinned API invoice's genuine human review. */
function achbotApiPaymentManualAddonPreflight(mysqli $db,string $hash): ?array {
    $pay=dbFetchPayByHash($db,$hash);
    if(!$pay||!achbotApiPaymentPinned($db,(int)$pay['id']))return null;
    if($pay['state']!=='have_sent')return ['valid'=>false,'fingerprint'=>achbotPaymentEvidencePayFingerprint($pay)];
    return ['valid'=>achbotSupportsPaymentSubject($db,$pay,false),'fingerprint'=>achbotPaymentEvidencePayFingerprint($pay)];
}
function achbotApiPaymentManualAddonAllowedLocked(mysqli $db,?array $pay,?array $seen): bool {
    if($pay===null)return false;
    if($seen===null)return achbotSupportsPaymentSubject($db,$pay,true);
    return $seen['valid']===true && hash_equals($seen['fingerprint'],achbotPaymentEvidencePayFingerprint($pay)) && achbotApiPaymentLocalSubjectAllowed($db,$pay);
}
/** Exclusive real local financial claim. No provider or Telegram HTTP inside. */
function achbotApiPaymentSettle(mysqli $db,int $id,array $proof): string {
    require_once __DIR__.'/ServiceEffects.php';require_once __DIR__.'/BankPurchase.php';
    try {
        if (!$db->begin_transaction()) return 'review';
        $pay=achbotApiPaymentRow($db,$id,true);
        if (!$pay) {$db->rollback();return 'review';}
        if (achbotApiPaymentTerminal($pay)) {$db->rollback();return 'settled';}
        $resuming=$pay['state']==='processing' && (int)($pay['approved_by']??-1)===0;
        if ((!in_array($pay['state'],['pending','have_sent'],true) && !$resuming) || (string)($pay['payid']??'')!=='' ||
            ($proof['mode']??'')!=='settle' || !achbotPaymentEvidenceValidateProofLocked($db,$pay,$proof) ||
            !achbotApiPaymentLocalSubjectAllowed($db,$pay)) {$db->rollback();return 'review';}
        $type=(string)$pay['type'];
        if (!$resuming && $type==='BUY_SUB') {
            $intent=achbotApiPaymentPurchaseIntent($db,$pay,$proof);if(!$intent)throw new RuntimeException('api_purchase_pin_invalid');
            achbotEffectRequireUniqueLine($db);
            $count=achbotManualBuyReserveCapacityLocked($db,$pay,(int)$pay['day']>0 || (int)$pay['volume']>0);
            if($count!==$intent['account_count'])throw new RuntimeException('api_purchase_count_drift');
            achbotEffectRecordManualCapacityLocked($db,$pay,$count,$proof);
        } elseif (!$resuming && $type==='INCREASE_WALLET') {
            if (!addToWallet($db,(int)$pay['user_id'],(int)$pay['price'])) throw new RuntimeException('api_wallet_credit_unconfirmed');
        } elseif (!$resuming && !in_array($type,['RENEW_ACCOUNT','RENEW_SCONFIG'],true) &&
            !preg_match('/^INCREASE_(DAY|VOLUME)_[1-9][0-9]*_[1-9][0-9]*$/D',$type)) throw new RuntimeException('api_subject_unsupported');
        if (!$resuming) {
        $state=$type==='INCREASE_WALLET'?'approved':'processing';$before=(string)$pay['state'];
        $q=$db->prepare('UPDATE pays SET state=?,approved_by=0 WHERE id=? AND state=? AND (payid IS NULL OR payid=\'\')');
        $q->bind_param('sis',$state,$id,$before);if(!$q->execute() || $q->affected_rows!==1)throw new RuntimeException('api_claim_unconfirmed');$q->close();
        if ($type!=='INCREASE_WALLET') {
            $f=$db->prepare("UPDATE payment_attempts SET evidence_fulfillment_state='reserved' WHERE pay_row_id=? AND evidence_fulfillment_state IS NULL");$f->bind_param('i',$id);$f->execute();$f->close();
        }
        }
        if (!$db->commit()) throw new RuntimeException('api_claim_commit_unknown');
    } catch(Throwable $e) {try{$db->rollback();}catch(Throwable $ignored){}error_log('achbot_api_claim_review pay_id='.$id);return 'review';}
    try {
        if ($type==='BUY_SUB') {
            // Existing CREATE journal is authoritative after a crash. An
            // uncertain/dispatched or partially verified batch stays manual;
            // the no-effect pre-reservation window can resume safely.
            $q=$db->prepare("SELECT COUNT(*) FROM service_effects WHERE pay_row_id=? AND effect_kind='create'");$q->bind_param('i',$id);$q->execute();$count=(int)$q->get_result()->fetch_row()[0];$q->close();
            if($count>0)return 'review';
            $delivery=achbotAutoBankProvisionBuySub($db,$pay,$GLOBALS['botState']??[],$GLOBALS['buttonValues']??[],[],false,(string)($GLOBALS['botUrl']??''),$proof);
            if (!$delivery['committed']) return 'review';
            achbotReferralAfterCompletedPurchase($db,(int)$pay['user_id'],$id,(int)($GLOBALS['admin']??0),$GLOBALS['botState']??[]);
            foreach($delivery['deliveries'] as $packet)try{sendQrFromLink($packet['qr'],$packet['caption'],$packet['keys'],(string)($GLOBALS['botUrl']??''),(int)$packet['uid']);}catch(Throwable $e){error_log('achbot_api_paid_delivery_review pay_id='.$id);}
        } elseif($type!=='INCREASE_WALLET') {
            if (!achbotApiPaymentFulfillEdit($db,$pay,$proof)) return 'review';
        }
        $fresh=achbotApiPaymentRow($db,$id);if(!$fresh || !achbotApiPaymentTerminal($fresh))return 'review';
        $done=$db->prepare("UPDATE payment_attempts SET evidence_fulfillment_state='done' WHERE pay_row_id=?");$done->bind_param('i',$id);$done->execute();$done->close();
        achbotApiPaymentNotice($db,$fresh,'confirmed');
        try{sendMessage(achbotMessage('payment.api_payment.confirmed'),null,'HTML',(int)$pay['user_id']);}catch(Throwable $e){}
        return 'confirmed';
    } catch(Throwable $e) {error_log('achbot_api_effect_review pay_id='.$id);$fresh=achbotApiPaymentRow($db,$id);if($fresh)achbotApiPaymentNotice($db,$fresh,'review');return 'review';}
}
/** Reuse actual panel edit and exact local finisher, with pinned quantities. */
function achbotApiPaymentStoreFulfillment(mysqli $db,int $id,array $proof,string $state,array $payload,string $expected): bool {
    try {
        if(!$db->begin_transaction())return false;$pay=achbotApiPaymentRow($db,$id,true);
        if(!$pay||$pay['state']!=='processing'||!achbotPaymentEvidenceValidateProofLocked($db,$pay,$proof)){$db->rollback();return false;}
        $payload['pay_sha256']=achbotPaymentEvidencePayFingerprint($pay);$payload['operation']=$proof['operation'];
        $sealed=achbotPaymentEvidenceProtect('fulfillment:'.$id.':'.$proof['operation'],$payload);
        $q=$db->prepare('UPDATE payment_attempts SET evidence_fulfillment_state=?,evidence_fulfillment_payload=? WHERE pay_row_id=? AND evidence_fulfillment_state=?');
        $q->bind_param('ssis',$state,$sealed,$id,$expected);$q->execute();$ok=$q->affected_rows===1;$q->close();
        if(!$ok||!$db->commit())throw new RuntimeException('api_fulfillment_commit_unknown');return true;
    }catch(Throwable $e){try{$db->rollback();}catch(Throwable $ignored){}return false;}
}
function achbotApiPaymentFulfillEdit(mysqli $db,array $pay,array $proof): bool {
    $id=(int)$pay['id'];$type=(string)$pay['type'];$a=achbotApiPaymentAttempt($db,$id);$profile=$a?achbotPaymentEvidenceSnapshot($a):null;$subject=$profile['subject']??[];
    $state=(string)($a['evidence_fulfillment_state']??'');$payload=null;
    if($state==='verified') {
        $payload=achbotPaymentEvidenceUnprotect('fulfillment:'.$id.':'.$proof['operation'],(string)$a['evidence_fulfillment_payload']);
        if(!$payload||($payload['operation']??null)!==$proof['operation']||($payload['pay_sha256']??null)!==achbotPaymentEvidencePayFingerprint($pay))return false;
    } elseif($state==='reserved') {
        $kind=$type==='RENEW_SCONFIG'?'standard':($type==='RENEW_ACCOUNT'?'renew':(str_starts_with($type,'INCREASE_DAY_')?'day':'volume'));
        $oid=(int)($pay['order_id']??0);$server=(int)$pay['server_id'];$units=0;$order=null;
        if($kind==='standard'){$desc=json_decode((string)$pay['description'],true);$remark=(string)($desc['remark']??'');}
        else {
            if($kind==='day'||$kind==='volume'){preg_match('/^INCREASE_(?:DAY|VOLUME)_([1-9][0-9]*)_/',$type,$m);$oid=(int)($m[1]??0);$units=(int)($subject['units']??0);if($units!==achbotPinnedAddonUnits($pay))return false;}
            $q=$db->prepare('SELECT * FROM orders_list WHERE id=?');$q->bind_param('i',$oid);$q->execute();$order=$q->get_result()->fetch_assoc();$q->close();if(!$order)return false;$remark=(string)$order['remark'];$server=(int)$order['server_id'];
        }
        if(!achbotAutoBankEditPreflight($db,$pay,$kind,$server,$oid,$remark,$units))return false;
        $params=['pay_id'=>$id,'remark'=>$remark];
        if($kind==='day')$params['plus_day']=$units;
        elseif($kind==='volume')$params['plus_volume']=$units;
        else {$params['days']=(int)($subject['days']??0);$params['volume']=(int)($subject['volume']??-1);if($params['days']<=0||$params['volume']<0)return false;}
        $payload=['kind'=>$kind,'server'=>$server,'order'=>$oid,'remark'=>$remark,'units'=>$units,'expiry'=>$order?(int)$order['expire_date']:null,'params'=>$params];
        if(!achbotApiPaymentStoreFulfillment($db,$id,$proof,'dispatched',$payload,'reserved'))return false;
        // Only the winner of the persisted dispatch transition may call the
        // native adapter. A crash/timeout here stays reviewable, never replayed.
        $response=achbotApplyServiceEdit($db,$server,$params);
        if(!is_object($response)||($response->success??false)!==true||($response->verified??false)!==true)return false;
        $payload['result']=['success'=>true,'verified'=>true,'applied_expire'=>$response->applied_expire??null,'applied_bytes'=>$response->applied_bytes??null];
        if(!achbotApiPaymentStoreFulfillment($db,$id,$proof,'verified',$payload,'dispatched'))return false;
    } else return false;
    $result=(object)($payload['result']??[]);
    return achbotAutoBankFinishEdit($db,$pay,$result,(string)$payload['kind'],(int)$payload['server'],(int)$payload['order'],(string)$payload['remark'],(int)$pay['price'],(int)$payload['units'],$payload['expiry']===null?null:(int)$payload['expiry']);
}

/** No first-match wins. A same-amount local collision is left untouched. */
function achbotApiPaymentSmsUniqueLocked(mysqli $db,array $evidence,int $id): bool {
    $amount=(string)($evidence['amount_toman']??'');
    if (!preg_match('/^[1-9][0-9]*$/D',$amount)) return false;
    $q=$db->prepare("SELECT a.*,p.state AS pay_state FROM payment_attempts a JOIN pays p ON p.id=a.pay_row_id
        WHERE a.provider='bank_transfer' AND a.evidence_profile IS NOT NULL
          AND a.quote_currency='toman' AND a.quote_amount=? ORDER BY p.id LIMIT 201 FOR UPDATE");
    $q->bind_param('s',$amount);$q->execute();$rows=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
    if(count($rows)>200)return false;$matches=[];
    foreach($rows as $a) {
        // A fully consumed, terminal invoice cannot claim another SMS.
        // A manual winner without API evidence remains eligible solely for
        // its late consumption-only reconciliation and still forms a collision.
        if(in_array($a['pay_state'],['approved','auto_cart_approved','paid','paid_with_wallet'],true) &&
            achbotPaymentEvidenceGetProof($db,(int)$a['pay_row_id'])!==null)continue;
        // Declined/processing contradictions stay ambiguous; do not silently
        // remove them and assign the same SMS to another customer.
        $p=achbotPaymentEvidenceSnapshot($a);
        if($p===null)return false;
        if(achbotPaymentEvidenceSmsMatches($evidence,$a,$p))$matches[]=(int)$a['pay_row_id'];
    }
    return count($matches)===1 && $matches[0]===$id;
}
/** A bounded page is revisited from its start. No unpersisted cursor can lose evidence. */
function achbotApiPaymentCheckBank(mysqli $db,array $claim): string {
    $p=$claim['pay'];$a=$claim['attempt'];$profile=$claim['profile'];$id=(int)$p['id'];
    $existing=achbotPaymentEvidenceGetProof($db,$id);
    if($existing) {
        if(achbotApiPaymentTerminal($p))return 'settled';
        return achbotApiPaymentSettle($db,$id,$existing);
    }
    $pending=achbotPaymentEvidencePending($db,$id);
    if($pending) {
        $r=achbotPaymentEvidenceConsume($db,$pending);
        if(($r['status']??'')!=='proven')return (string)($r['status']??'review');
        $fresh=achbotApiPaymentRow($db,$id);if($fresh && achbotApiPaymentTerminal($fresh))return 'settled';
        $proof=$r['proof']??achbotPaymentEvidenceGetProof($db,$id);
        return is_array($proof)?achbotApiPaymentSettle($db,$id,$proof):'review';
    }
    if(time()>(int)$profile['issued_at']+(int)$profile['horizon_seconds'])return 'manual';
    $after=max(0,(int)($a['evidence_cursor_id']??0));
    for($page=0;$page<4;$page++) {
        $result=achbotPaymentEvidenceSmsList($profile,$after);
        if(empty($result['ok']))return 'unavailable';
        $data=$result['data']??[];
        $rows=is_array($data)&&isset($data['items'])?$data['items']:$data;
        if(!is_array($rows))return 'review';
        foreach($rows as $raw) {
            if(!is_array($raw))continue;
            $e=achbotPaymentEvidenceSmsNormalize($raw,$profile);
            // Mismatched amounts are not candidates and never reach Reserve.
            if($e===null || !achbotPaymentEvidenceSmsMatches($e,$a,$profile))continue;
            $fresh=achbotApiPaymentRow($db,$id);if(!$fresh)return 'review';
            $mode=achbotApiPaymentTerminal($fresh)?'evidence_only':'settle';
            if($mode==='settle' && !in_array($fresh['state'],['pending','have_sent'],true))return 'review';
            $guard=static fn(mysqli $lockedDb)=>achbotApiPaymentSmsUniqueLocked($lockedDb,$e,$id);
            $handle=achbotPaymentEvidenceReserve($db,$id,$e,$mode,null,$guard);
            if(!$handle)return 'review';
            $consumed=achbotPaymentEvidenceConsume($db,$handle);
            if(($consumed['status']??'')!=='proven')return (string)($consumed['status']??'review');
            // A human may win while external consumption is in flight.
            // Its committed state is final; only mark external evidence used.
            $fresh=achbotApiPaymentRow($db,$id);
            if($fresh && achbotApiPaymentTerminal($fresh))return 'settled';
            $proof=$consumed['proof']??achbotPaymentEvidenceGetProof($db,$id);
            return is_array($proof)?achbotApiPaymentSettle($db,$id,$proof):'review';
        }
        $next=empty($result['has_more'])?0:(int)($result['next_after_id']??0);
        if($next!==0 && $next<=$after)return 'review';
        $cursor=$db->prepare('UPDATE payment_attempts SET evidence_cursor_id=? WHERE pay_row_id=? AND evidence_claim_token=?');
        $cursor->bind_param('iis',$next,$id,$claim['token']);$cursor->execute();$cursor->close();
        if($next===0)break;$after=$next;
    }
    return 'monitoring';
}
/** Preserve a definite no-effect response for same-code reads without HTTP. */
function achbotApiPaymentRememberGiftRejection(mysqli $db,int $id,string $code,string $status): void {
    if(!in_array($status,['used','revoked','expired','mismatch'],true))return;
    try {
        if(!$db->begin_transaction())return;$pay=achbotApiPaymentRow($db,$id,true);$a=achbotApiPaymentAttempt($db,$id,true);
        if(!$pay||!$a||$a['evidence_state']!=='invalid_code'||!hash_equals((string)$a['evidence_code_sha256'],achbotPaymentEvidenceCodeDigest($code))){$db->rollback();return;}
        $d=achbotPaymentEvidenceUnprotect('code:'.$a['id'].':'.$id,(string)$a['evidence_code']);if(($d['code']??null)!==$code){$db->rollback();return;}
        $d['last_status']=$status;$sealed=achbotPaymentEvidenceProtect('code:'.$a['id'].':'.$id,$d);
        $q=$db->prepare('UPDATE payment_attempts SET evidence_code=? WHERE pay_row_id=? AND evidence_code_sha256=?');$q->bind_param('sis',$sealed,$id,$a['evidence_code_sha256']);$q->execute();$q->close();$db->commit();
    }catch(Throwable $e){try{$db->rollback();}catch(Throwable $ignored){}}
}
/** Customer code is encrypted before verification HTTP. Never log or fan it out. */
function achbotApiPaymentCheckGift(mysqli $db,array $claim): string {
    $pay=$claim['pay'];$id=(int)$pay['id'];
    if(achbotApiPaymentTerminal($pay))return 'settled';
    $proof=achbotPaymentEvidenceGetProof($db,$id);if($proof)return achbotApiPaymentSettle($db,$id,$proof);
    $pending=achbotPaymentEvidencePending($db,$id);
    if($pending) {
        $r=achbotPaymentEvidenceConsume($db,$pending);
        if(($r['status']??'')!=='proven')return (string)($r['status']??'review');
        $fresh=achbotApiPaymentRow($db,$id);if($fresh && achbotApiPaymentTerminal($fresh))return 'settled';
        $proof=$r['proof']??achbotPaymentEvidenceGetProof($db,$id);
        return is_array($proof)?achbotApiPaymentSettle($db,$id,$proof):'review';
    }
    $code=achbotPaymentEvidenceReadGiftCode($db,$id);if($code===null)return 'monitoring';
    $result=achbotPaymentEvidenceGiftVerify($claim['profile'],$code);$status=(string)($result['status']??'unavailable');
    if(in_array($status,['used','revoked','expired'],true)) {
        if(achbotPaymentEvidenceRejectGiftCode($db,$id,$code,$status))achbotApiPaymentRememberGiftRejection($db,$id,$code,$status);return $status;
    }
    if(empty($result['ok']) || $status!=='active')return 'review';
    $e=achbotPaymentEvidenceGiftNormalize($result['data']??[],$claim['profile'],$claim['attempt']);
    if($e===null) {$why=achbotPaymentEvidenceGiftNormalizationStatus($result['data']??[],$claim['profile'],$claim['attempt']);if($why==='mismatch' && achbotPaymentEvidenceRejectGiftCode($db,$id,$code,'mismatch'))achbotApiPaymentRememberGiftRejection($db,$id,$code,'mismatch');return $why==='mismatch'?'mismatch':'review';}
    $handle=achbotPaymentEvidenceReserve($db,$id,$e,'settle',$code);
    if(!$handle)return 'review';
    $consumed=achbotPaymentEvidenceConsume($db,$handle);
    if(($consumed['status']??'')!=='proven')return (string)($consumed['status']??'review');
    $fresh=achbotApiPaymentRow($db,$id);if($fresh && achbotApiPaymentTerminal($fresh))return 'settled';
    $proof=$consumed['proof']??achbotPaymentEvidenceGetProof($db,$id);
    return is_array($proof)?achbotApiPaymentSettle($db,$id,$proof):'review';
}
/** Real text review uses a separate lease and the unchanged human callbacks. */
function achbotApiPaymentReviewKeyboard(array $pay): string {
    $hash=(string)$pay['hash_id'];$t=(string)$pay['type'];
    $prefix=match(true) {
        $t==='INCREASE_WALLET'=>'approvePayment',
        $t==='RENEW_ACCOUNT'=>'approveRenewAcc',
        $t==='RENEW_SCONFIG'=>'accept',
        str_starts_with($t,'INCREASE_DAY_')=>'approveIncreaseDay',
        str_starts_with($t,'INCREASE_VOLUME_')=>'approveIncreaseVolume',
        (int)$pay['day']>0 || (int)$pay['volume']>0=>'accCustom',
        default=>'accept'
    };
    return inlineKeyboard([
        [['text'=>achbotMessage('manual_payment.api_payment.view_code'),'callback_data'=>'apiReview_'.$hash]],
        [['text'=>($GLOBALS['buttonValues']['approve']??'تأیید پرداخت'),'callback_data'=>$prefix.$hash],
         ['text'=>($GLOBALS['buttonValues']['decline']??'رد پرداخت'),'callback_data'=>'declineAction_'.$hash]]
    ]);
}
function achbotApiPaymentDeliverReview(mysqli $db,int $id): bool {
    $token=bin2hex(random_bytes(16));$started=false;$sent=null;
    try {
        if(!$db->begin_transaction())return false;$started=true;
        $pay=achbotApiPaymentRow($db,$id,true);$a=achbotApiPaymentAttempt($db,$id,true);
        if(!$pay||!$a||$a['provider']!=='gift_card'||empty($a['evidence_code'])||
            !in_array($pay['state'],['pending','have_sent'],true)||!empty($a['evidence_review_sent_at'])) {$db->rollback();return false;}
        $q=$db->prepare("UPDATE payment_attempts SET evidence_state='review',evidence_review_claim_token=?,evidence_review_claimed_at=NOW()
            WHERE pay_row_id=? AND evidence_review_sent_at IS NULL AND
            (evidence_review_claim_token IS NULL OR evidence_review_claimed_at<DATE_SUB(NOW(),INTERVAL 2 MINUTE))");
        $q->bind_param('si',$token,$id);$q->execute();$changed=$q->affected_rows;$q->close();
        if($changed!==1||!$db->commit())throw new RuntimeException('typed_review_claim_unknown');$started=false;
        $body=achbotMessage('manual_payment.api_payment.typed_evidence',['reference'=>(string)$pay['hash_id'],'status'=>'review']);
        $keys=achbotApiPaymentReviewKeyboard($pay);$owner=(int)($GLOBALS['admin']??0);
        if($owner<=0)return false;
        $sent=sendMessage($body,$keys,'HTML',$owner);$message=(int)($sent->result->message_id??0);
        if($message<=0)return false;
        if(!$db->begin_transaction())return false;$started=true;
        $fresh=achbotApiPaymentRow($db,$id,true);$current=achbotApiPaymentAttempt($db,$id,true);
        if(!$fresh||!$current||($current['evidence_review_claim_token']??'')!==$token)throw new RuntimeException('typed_review_stale');
        if(!in_array($fresh['state'],['pending','have_sent'],true)) {
            $db->rollback();$started=false;
            achbotApiPaymentNotice($db,array_replace($fresh,['chat_id'=>$owner,'message_id'=>$message]),achbotApiPaymentTerminal($fresh)?'confirmed':'review',false);return false;
        }
        $q=$db->prepare("UPDATE pays SET state='have_sent',message_id=?,chat_id=? WHERE id=? AND state IN ('pending','have_sent')");
        $q->bind_param('iii',$message,$owner,$id);$q->execute();$q->close();
        $q=$db->prepare("UPDATE payment_attempts SET stage='review',evidence_state='review',evidence_review_sent_at=NOW(),
            evidence_review_claim_token=NULL,evidence_review_claimed_at=NULL WHERE pay_row_id=? AND evidence_review_claim_token=?");
        $q->bind_param('is',$id,$token);if(!$q->execute()||$q->affected_rows!==1)throw new RuntimeException('typed_review_finish_unknown');$q->close();
        if(!$db->commit())throw new RuntimeException('typed_review_commit_unknown');$started=false;
        try{sendToAllAdmins('sendMessage',['text'=>$body,'reply_markup'=>$keys,'parse_mode'=>'HTML'],(string)$pay['hash_id'],['event'=>'manual_review']);}catch(Throwable $e){}
        $fresh=achbotApiPaymentRow($db,$id);if($fresh&&achbotApiPaymentTerminal($fresh))achbotApiPaymentNotice($db,$fresh,'confirmed',false);
        return true;
    }catch(Throwable $e){if($started)try{$db->rollback();}catch(Throwable $ignored){}error_log('achbot_api_typed_review_delivery_deferred pay_id='.$id);return false;}
}
/** Button and admitted Cron service coalesce under this durable evidence claim. */
function achbotApiPaymentCheck(mysqli $db,int $id): string {
    if(!achbotApiPaymentReady($db))return 'unavailable';
    $saved=achbotApiPaymentAttempt($db,$id);
    if($saved && $saved['provider']==='gift_card' && ($saved['evidence_state']??'')==='invalid_code') {
        $d=achbotPaymentEvidenceUnprotect('code:'.$saved['id'].':'.$id,(string)$saved['evidence_code']);
        if(in_array($d['last_status']??'',['used','revoked','expired','mismatch'],true))return $d['last_status'];
    }
    $claim=achbotPaymentEvidenceClaim($db,$id);
    if(!$claim)return $saved && $saved['provider']==='gift_card' && ($saved['evidence_state']??'')==='review' && !empty($saved['evidence_review_sent_at'])?'review':'pending';
    $token=(string)$claim['token'];$status='review';
    try {
        $status=$claim['attempt']['provider']==='bank_transfer'?achbotApiPaymentCheckBank($db,$claim):achbotApiPaymentCheckGift($db,$claim);
        if(in_array($status,['review','unavailable','pending'],true) && $claim['attempt']['provider']==='gift_card') {
            $a=achbotApiPaymentAttempt($db,$id);if(empty($a['evidence_review_sent_at']) && !achbotApiPaymentDeliverReview($db,$id))$status='unavailable';
        }
    }catch(Throwable $e){error_log('achbot_api_payment_check_deferred pay_id='.$id);$status='review';if(($claim['attempt']['provider']??'')==='gift_card')achbotApiPaymentDeliverReview($db,$id);}
    finally {achbotPaymentEvidenceDefer($db,$id,$token,in_array($status,['confirmed','settled'],true)?'proven':(in_array($status,['review','unavailable'],true)?'review':(in_array($status,['used','expired','revoked','mismatch'],true)?'invalid_code':($status==='pending'?'uncertain':($status==='manual'?'manual':'monitoring')))),60);}
    $pay=achbotApiPaymentRow($db,$id);if($pay){
        $c=achbotApiPaymentContext($db,(string)$pay['hash_id']);if($c)achbotApiPaymentSyncInvoice($db,$c);
        if(in_array($status,['review','pending','unavailable'],true))achbotApiPaymentNotice($db,$pay,$status==='pending'?'uncertain':'review');
        elseif($status==='settled'){$proof=achbotPaymentEvidenceGetProof($db,$id);achbotApiPaymentNotice($db,$pay,($proof['mode']??'')==='evidence_only'?'evidence_only':'settled');}
    }
    return $status;
}
function achbotApiPaymentMaintain(mysqli $db,int $limit=12): array {
    $checked=0;$edited=0;$started=microtime(true);$limit=max(1,min(12,$limit));if(!achbotApiPaymentReady($db))return ['checked'=>0,'edited'=>0];
    try {
        $q=$db->query("SELECT a.pay_row_id,p.hash_id FROM payment_attempts a JOIN pays p ON p.id=a.pay_row_id
            WHERE a.evidence_profile IS NOT NULL AND a.provider IN ('bank_transfer','gift_card')
              AND (a.evidence_next_check_at IS NULL OR a.evidence_next_check_at<=NOW())
              AND (a.evidence_state IS NULL OR a.evidence_state NOT IN ('proven','invalid_code','manual')
                   OR (a.evidence_state='proven' AND (p.state IN ('pending','have_sent') OR
                       (p.state='processing' AND a.evidence_fulfillment_state IN ('reserved','verified')))))
            ORDER BY COALESCE(a.evidence_next_check_at,a.created_at),a.id LIMIT {$limit}");
        $rows=$q->fetch_all(MYSQLI_ASSOC);
        foreach($rows as $row){if(microtime(true)-$started>=35)break;achbotApiPaymentCheck($db,(int)$row['pay_row_id']);$checked++;}
        // UI convergence is independent of evidence polling/finality/leases.
        $q=$db->query("SELECT p.hash_id FROM payment_attempts a JOIN pays p ON p.id=a.pay_row_id
            WHERE a.evidence_profile IS NOT NULL
            ORDER BY a.evidence_projection_checked_at IS NOT NULL,a.evidence_projection_checked_at,a.id LIMIT {$limit}");
        foreach($q->fetch_all(MYSQLI_ASSOC) as $row){
            $c=achbotApiPaymentContext($db,(string)$row['hash_id']);if(!$c)continue;
            if(achbotApiPaymentSyncInvoice($db,$c))$edited++;
            $p=$c['pay'];$a=$c['attempt'];$proof=achbotPaymentEvidenceGetProof($db,(int)$p['id']);
            if(achbotApiPaymentTerminal($p))achbotApiPaymentNotice($db,$p,($proof['mode']??'')==='evidence_only'?'evidence_only':(($a['evidence_fulfillment_state']??'')==='done'?'confirmed':'settled'));
            elseif(in_array($a['evidence_state']??'',['review','uncertain'],true)||$p['state']==='processing')achbotApiPaymentNotice($db,$p,($a['evidence_state']??'')==='uncertain'?'uncertain':'review');
            $stamp=$db->prepare('UPDATE payment_attempts SET evidence_projection_checked_at=NOW(6) WHERE pay_row_id=?');$stamp->bind_param('i',$p['id']);$stamp->execute();$stamp->close();
        }
    }catch(Throwable $e){error_log('achbot_api_payment_maintenance_deferred');}
    return ['checked'=>$checked,'edited'=>$edited];
}
