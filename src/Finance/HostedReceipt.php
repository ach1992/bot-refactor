<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/FundingCheckout.php';

/**
 * Hosted issuance/verification is outside money transactions. The source
 * registry owns the protocol. The caller is the authenticated draft/checkout
 * adapter: it must enforce configured availability, user/category rules,
 * draft lifetime, authorization and its original issued commercial quote.
 * No callback data may choose the amount, merchant, service or verifier.
 */
function achbotHostedReceiptDefinition(string $method): ?array {
    $r=achbotCreditMethodDefinition($method);
    return isset($r['hosted'])?$r['hosted']:null;
}
/** Reservation identity excludes the as-yet unknown remote reference. */
function achbotHostedReservationIdentity(array $pay,array $a): string {
    return hash('sha256',json_encode([
        (int)$pay['id'],(int)$pay['user_id'],(string)$pay['hash_id'],(int)$pay['price'],
        (int)$a['id'],(string)$a['provider'],(string)$a['order_hash'],
        (string)$a['quote_amount'],(string)$a['quote_currency'],(int)$a['local_amount_toman'],
        (string)($a['settlement_currency']??''),(string)$a['minimum_settlement_amount'],
        (int)($a['service_operation_id']??0),
    ],JSON_THROW_ON_ERROR));
}
function achbotHostedMerchantPurpose(array $a): string {
    return 'wallet-hosted:'.$a['id'].':'.$a['pay_row_id'];
}
function achbotHostedMerchantSnapshot(array $pay,array $a): array {
    $p=achbotPaymentEvidenceUnprotect(achbotHostedMerchantPurpose($a),(string)($a['wallet_merchant_profile']??''));
    if (!$p || ($p['v']??null)!==1 ||
        ($p['method']??null)!==$a['provider'] ||
        !is_string($p['credential']??null) || $p['credential']==='' ||
        !hash_equals((string)($p['identity']??''),achbotHostedReservationIdentity($pay,$a)))
        throw new RuntimeException('hosted_merchant_profile_invalid');
    return $p;
}
/** The first reservation owns the only remote issuance dispatch. A replay of
 * the same issued invoice can return its original URL; an unresolved creation
 * must not generate another provider invoice. Caller owns the SQL transaction. */
function achbotHostedReceiptReserveLocked(mysqli $db,int $payId,int $userId,string $method,
    ?int $operationId,array $quote,string $credential): array {
    $def=achbotHostedReceiptDefinition($method);
    if ($def===null) throw new DomainException('hosted_method_unavailable');
    $pay=achbotCheckoutPayLocked($db,$payId,$userId);
    $a=achbotProviderRow($db,'SELECT * FROM payment_attempts WHERE pay_row_id=? FOR UPDATE','i',[$payId]);
    if ($a) {
        if ($a['provider']!==$method || (int)($a['service_operation_id']??0)!==($operationId??0) ||
            $a['wallet_invoice_sha256']===null)
            throw new DomainException('hosted_original_attempt_conflict');
        achbotHostedMerchantSnapshot($pay,$a);
        if ($a['wallet_invoice_sha256']!=='' &&
            hash_equals($a['wallet_invoice_sha256'],achbotCreditInvoiceFingerprint($pay,$a))) {
            // Reissue neither a paid nor an invalidated invoice. The route
            // can render its original status without reopening a checkout.
            return ['status'=>$pay['state']==='pending' && $a['stage']==='pending' &&
                $a['wallet_credit_entry_id']===null && ($a['review_archived_at']??null)===null &&
                empty($a['payment_id'])?'issued':'closed','url'=>$a['checkout_url']];
        }
        return ['status'=>'issuance_uncertain'];
    }
    if ($credential==='') throw new DomainException('hosted_method_unavailable');
    if ($pay['state']!=='pending' || (string)($pay['payid']??'')!=='' ||
        (int)$pay['price']<=0 || $pay['hash_id']==='')
        throw new DomainException('hosted_draft_unavailable');
    achbotCheckoutValidateOriginalLocked($db,$pay,$userId,$operationId);
    $money=($def['quote'])($pay,$quote);
    $s=achbotProviderStatement($db,"INSERT INTO payment_attempts
        (pay_row_id,provider,order_hash,quote_amount,quote_currency,local_amount_toman,
        settlement_currency,minimum_settlement_amount,stage,wallet_invoice_sha256,service_operation_id)
        VALUES (?,?,?,?,?,?,?,?,'creating','',?)",'issssissi',
        [$payId,$method,$pay['hash_id'],$money['amount'],$money['currency'],(int)$pay['price'],
        $money['settlement_currency'],$money['minimum'],$operationId]);
    $aid=(int)$s->insert_id;$s->close();
    if ($aid<=0) throw new RuntimeException('hosted_reservation_insert');
    $a=achbotProviderRow($db,'SELECT * FROM payment_attempts WHERE id=? FOR UPDATE','i',[$aid]);
    $profile=achbotPaymentEvidenceProtect(achbotHostedMerchantPurpose($a),[
        'v'=>1,'method'=>$method,'credential'=>$credential,
        'identity'=>achbotHostedReservationIdentity($pay,$a)]);
    $marker='achbot_'.$method.'_creating_'.$payId;
    $s=achbotProviderStatement($db,'UPDATE payment_attempts SET wallet_merchant_profile=? WHERE id=?','si',[$profile,$aid]);
    $n=$s->affected_rows;$s->close();
    if ($n!==1) throw new RuntimeException('hosted_merchant_pin_write');
    $s=achbotProviderStatement($db,"UPDATE pays SET payid=? WHERE id=? AND state='pending' AND (payid IS NULL OR payid='')",'si',[$marker,$payId]);
    $n=$s->affected_rows;$s->close();
    if ($n!==1) throw new RuntimeException('hosted_reservation_write');
    $a['wallet_merchant_profile']=$profile;$pay['payid']=$marker;
    return ['status'=>'reserved','pay'=>$pay,'attempt'=>$a];
}
/** Bind a response from the original reserved merchant request, even when
 * service eligibility changed during HTTP. Never publish an unbound URL. */
function achbotHostedReceiptBindLocked(mysqli $db,int $payId,int $userId,array $reservation,array $remote): array {
    [$pay,$a]=achbotCreditInvoiceLocked($db,$payId,$userId);
    $method=(string)$a['provider'];
    $def=achbotHostedReceiptDefinition($method);
    if ($def===null || ($reservation['attempt']['id']??null)!==$a['id'] ||
        $reservation['attempt']['wallet_merchant_profile']!==$a['wallet_merchant_profile'])
        throw new RuntimeException('hosted_reservation_changed');
    achbotHostedMerchantSnapshot($pay,$a);
    $reference=(string)($remote['reference']??'');$url=(string)($remote['url']??'');
    if ($a['stage']!=='creating' || $a['wallet_invoice_sha256']!=='' ||
        $a['provider_reference']!==null ||
        $pay['payid']!=='achbot_'.$method.'_creating_'.$payId ||
        !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D',$reference) ||
        !($def['url'])($reference,$url) ||
        achbotHasUnscopedPaymentReferenceCollision($db,$reference,$payId))
        throw new RuntimeException('hosted_reference_binding_conflict');
    $s=achbotProviderStatement($db,"UPDATE payment_attempts SET provider_reference=?,checkout_url=?,stage='pending'
        WHERE id=? AND stage='creating' AND provider_reference IS NULL",'ssi',[$reference,$url,(int)$a['id']]);
    $n=$s->affected_rows;$s->close();
    if ($n!==1) throw new RuntimeException('hosted_reference_write');
    $s=achbotProviderStatement($db,'UPDATE pays SET payid=? WHERE id=? AND payid=?','sis',[$reference,$payId,$pay['payid']]);
    $n=$s->affected_rows;$s->close();
    if ($n!==1) throw new RuntimeException('hosted_pay_reference_write');
    // The exact creating reservation already admitted the amount and
    // original service. Do not require a still-eligible service or pending
    // pay.state after HTTP. A late receipt remains creditable.
    $a['provider_reference']=$reference;$a['checkout_url']=$url;$a['stage']='pending';$pay['payid']=$reference;
    $pin=achbotCreditInvoiceFingerprint($pay,$a);
    $s=achbotProviderStatement($db,"UPDATE payment_attempts SET wallet_invoice_sha256=? WHERE id=? AND wallet_invoice_sha256=''",'si',[$pin,(int)$a['id']]);
    $n=$s->affected_rows;$s->close();
    if ($n!==1) throw new RuntimeException('hosted_invoice_pin_write');
    return ['status'=>$pay['state']==='pending'?'issued':'closed','url'=>$url];
}
function achbotHostedReceiptIssue(mysqli $db,int $payId,int $userId,string $method,?int $operationId,
    array $quote,array $paymentKeys,string $botUrl,string $description,?array $draftStates=null): array {
    achbotPaymentEvidenceRequireAutocommit($db);
    $def=achbotHostedReceiptDefinition($method);
    if ($def===null) throw new DomainException('hosted_method_unavailable');
    achbotHostedCallbackBase($botUrl);
    $credential=(string)($paymentKeys[$def['credential_setting']]??'');
    if (!$db->begin_transaction()) throw new RuntimeException('hosted_issue_begin');
    try {
        if ($draftStates!==null) {
            require_once __DIR__.'/CheckoutAdmission.php';
            if (!achbotCheckoutCommandsPresent($db)) throw new RuntimeException('checkout_command_schema_missing');
            $pay=achbotCheckoutPayLocked($db,$payId,$userId);
            $a=achbotProviderRow($db,'SELECT * FROM payment_attempts WHERE pay_row_id=? FOR UPDATE','i',[$payId]);
            $operationId=achbotCheckoutAdmitDraftLocked($db,$pay,$a,$method,$draftStates);
        }
        $r=achbotHostedReceiptReserveLocked($db,$payId,$userId,$method,$operationId,$quote,$credential);
        if (!$db->commit()) throw new RuntimeException('hosted_reservation_commit');
    } catch (Throwable $e) {$db->rollback();throw $e;}
    if ($r['status']!=='reserved') return $r;
    // The initial reservation is durable before transport; no SQL lock spans
    // an API request. Non-idempotent creation is not retried blindly.
    try {
        $remote=($def['issue'])($r['pay'],$r['attempt'],$credential,$botUrl,$description);
        if ($remote===null) return ['status'=>'issuance_uncertain'];
    } catch (Throwable $e) {
        achbotDiagnosticEvent('achbot.finance.hosted_issue',[
            'pay_id'=>$payId,'provider'=>$method,'reason'=>'transport_or_response_unconfirmed'],'warning');
        return ['status'=>'issuance_uncertain'];
    }
    if (!$db->begin_transaction()) throw new RuntimeException('hosted_bind_begin');
    try {
        $result=achbotHostedReceiptBindLocked($db,$payId,$userId,$r,$remote);
        if (!$db->commit()) throw new RuntimeException('hosted_bind_commit');
        return $result;
    } catch (Throwable $e) {$db->rollback();throw $e;}
}
/** Real authenticated verifier; no service preflight or browser status.
 * The caller locates the original invoice and passes only its exact reference
 * (or the known child identifier for a hosted multi-transaction method).
 * All money effects revalidate the original invoice under its owner lock. */
function achbotHostedReceiptVerify(mysqli $db,int $payId,int $userId,string $method,string $reference): array {
    achbotPaymentEvidenceRequireAutocommit($db);
    $def=achbotHostedReceiptDefinition($method);
    if ($def===null) throw new DomainException('hosted_method_unavailable');
    if (!$db->begin_transaction()) throw new RuntimeException('hosted_verify_begin');
    try {
        [$pay,$a]=achbotCreditInvoiceLocked($db,$payId,$userId);
        if ($a['provider']!==$method || !is_string($a['wallet_invoice_sha256']) ||
            !hash_equals($a['wallet_invoice_sha256'],achbotCreditInvoiceFingerprint($pay,$a)))
            throw new RuntimeException('hosted_invoice_unadmitted_or_changed');
        $profile=achbotHostedMerchantSnapshot($pay,$a);
        if (!($def['reference'])($a,$reference))
            throw new DomainException('hosted_reference_mismatch');
        if ($def['child_transactions'] && $reference!==(string)($a['payment_id']??'')) {
            // Only a known original child (e.g. authenticated IPN) may
            // initiate a merchant lookup. No browser-driven enumeration.
            $child=achbotProviderRow($db,'SELECT attempt_id FROM payment_transactions WHERE provider=? AND payment_id=? FOR UPDATE',
                'ss',[$method,$reference]);
            if (!$child || (int)$child['attempt_id']!==(int)$a['id'])
                throw new DomainException('hosted_child_not_known');
        }
        if (achbotHasUnscopedPaymentReferenceCollision($db,(string)$a['provider_reference'],$payId))
            throw new DomainException('hosted_reference_collision');
        if (!$db->commit()) throw new RuntimeException('hosted_verify_snapshot_commit');
    } catch (Throwable $e) {$db->rollback();throw $e;}
    // A completed single-reference receipt needs no more provider calls.
    if ($a['wallet_credit_entry_id']!==null && !$def['child_transactions']) {
        $proof=[];
    } else {
        $verified=($def['verify'])($pay,$a,$profile['credential'],$reference);
        if (($verified['status']??null)!=='verified') return $verified;
        $proof=$verified['proof'];
        $proof['merchant_profile_sha256']=hash('sha256',$a['wallet_merchant_profile']);
    }
    if (!$db->begin_transaction()) throw new RuntimeException('hosted_credit_begin');
    try {
        [$current,$attempt]=achbotCreditInvoiceLocked($db,$payId,$userId);
        if ($attempt['wallet_invoice_sha256']!==$a['wallet_invoice_sha256'] ||
            !hash_equals($a['wallet_invoice_sha256'],achbotCreditInvoiceFingerprint($current,$attempt)))
            throw new RuntimeException('hosted_invoice_changed_after_verify');
        $result=achbotCheckoutSettleReceiptLocked($db,$payId,$userId,$method,$proof);
        if (!$db->commit()) throw new RuntimeException('hosted_credit_commit');
        return ['status'=>'credited']+$result;
    } catch (Throwable $e) {$db->rollback();throw $e;}
}
