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
function achbotNpChildEvidenceRank($status): int {
    return match ((string)$status) {
        'waiting', 'failed', 'expired' => 0,
        'confirming' => 1,
        'confirmed' => 2,
        'sending' => 3,
        'partially_paid' => 4,
        'finished' => 5,
        'refunded' => 6,
        default => 0,
    };
}

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
    ?int $operationId,array $quote,string $credential,?string $ipnSecret=null): array {
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
                $a['wallet_credit_entry_id']===null &&
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
        'identity'=>achbotHostedReservationIdentity($pay,$a)] + ($ipnSecret===null?[]:['ipn_secret'=>$ipnSecret]));
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
    array $quote,array $paymentKeys,string $botUrl,string $description,?array $draftStates=null,?string $ipnSecret=null): array {
    achbotPaymentEvidenceRequireAutocommit($db);
    $def=achbotHostedReceiptDefinition($method);
    if ($def===null) throw new DomainException('hosted_method_unavailable');
    achbotHostedCallbackBase($botUrl);
    $credential=(string)($paymentKeys[$def['credential_setting']]??'');
    if (!$db->begin_transaction()) throw new RuntimeException('hosted_issue_begin');
    try {
        if ($draftStates!==null) {
            require_once __DIR__.'/CheckoutAdmission.php';
            achbotCheckoutRequireSchema($db);
            $pay=achbotCheckoutPayLocked($db,$payId,$userId);
            $a=achbotProviderRow($db,'SELECT * FROM payment_attempts WHERE pay_row_id=? FOR UPDATE','i',[$payId]);
            if ($a===null && ((int)$pay['price']!==($quote['local_price']??null) ||
                ($method==='nowpayments' && trim((string)$ipnSecret)==='')))
                throw new DomainException('hosted_draft_quote_changed');
            $operationId=achbotCheckoutAdmitDraftLocked($db,$pay,$a,$method,$draftStates);
        }
        $r=achbotHostedReceiptReserveLocked($db,$payId,$userId,$method,$operationId,$quote,$credential,$ipnSecret);
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
        if ($draftStates!==null) $result['newly_issued']=true;
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
        if (($verified['status']??null)!=='verified') {
            $status=$verified['observed_status']??null;
            if (!$def['child_transactions'] && ($verified['status']??null)==='unavailable')
                $status='verification_unavailable';
            if (in_array($status,['partially_paid','confirming','confirmed','sending','finished','refunded','amount_mismatch','verification_unavailable'],true)) {
                if (!$db->begin_transaction()) throw new RuntimeException('hosted_observation_begin');
                try {
                    [$current,$attempt]=achbotCreditInvoiceLocked($db,$payId,$userId);
                    if ($attempt['wallet_invoice_sha256']!==$a['wallet_invoice_sha256'] ||
                        !hash_equals($a['wallet_invoice_sha256'],achbotCreditInvoiceFingerprint($current,$attempt)))
                        throw new RuntimeException('hosted_invoice_changed_after_verify');
                    if ($current['state']==='declined') {
                        if (!$db->commit()) throw new RuntimeException('hosted_observation_commit');
                        return $verified;
                    }
                    $s=achbotProviderStatement($db,"UPDATE payment_attempts SET stage='review',provider_status=?
                        WHERE id=? AND wallet_credit_entry_id IS NULL",
                        'si',[$status,(int)$a['id']]);$s->close();
                    if (!$db->commit()) throw new RuntimeException('hosted_observation_commit');
                } catch (Throwable $e) {$db->rollback();throw $e;}
            }
            return $verified;
        }
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
        if ($def['child_transactions'] && array_key_exists('checkout_operation_id',$current)) {
            $s=achbotProviderStatement($db,"UPDATE payment_transactions SET merchant_verified_at=COALESCE(merchant_verified_at,NOW(6))
                WHERE attempt_id=? AND provider=? AND payment_id=? AND observed_status='finished'",
                'iss',[(int)$attempt['id'],$method,$reference]);$s->close();
        }
        if (!$db->commit()) throw new RuntimeException('hosted_credit_commit');
        return ['status'=>'credited']+$result;
    } catch (Throwable $e) {$db->rollback();throw $e;}
}

/** Only the persisted original merchant secret may authenticate this invoice.
 * The JSON is an untrusted locator until the exact signature is verified.
 * A signed event never credits funds and never dispatches a Native effect. */
function achbotHostedReceiptIngestIpn(mysqli $db,string $raw,string $signature): int {
    if (strlen($raw)>65536 || strlen($raw)<2) return 401;
    try {
        $hint=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
        if (!is_array($hint) || !is_scalar($hint['order_id']??null)) return 422;
        $hash=(string)$hint['order_id'];
        if ($hash==='' || strlen($hash)>50) return 422;
        $loc=achbotProviderRow($db,"SELECT a.pay_row_id,p.user_id FROM payment_attempts a
            JOIN pays p ON p.id=a.pay_row_id WHERE a.provider='nowpayments' AND a.order_hash=?",'s',[$hash]);
        if (!$loc) return 409;
        $id=(int)$loc['pay_row_id'];$uid=(int)$loc['user_id'];
        if (!$db->begin_transaction()) throw new RuntimeException('hosted_ipn_begin');
        [$pay,$a]=achbotCreditInvoiceLocked($db,$id,$uid);
        if ($a['provider']!=='nowpayments' || $a['order_hash']!==$hash)
            throw new RuntimeException('hosted_ipn_locator_changed');
        $profile=achbotHostedMerchantSnapshot($pay,$a);
        $signed=achbotNpValidIpn($raw,$signature,(string)($profile['ipn_secret']??''));
        if ($signed===null) {$db->rollback();return 401;}
        foreach (['payment_id','invoice_id','order_id','price_amount','price_currency','payment_status'] as $key)
            if (!isset($signed[$key]) || !is_scalar($signed[$key])) {$db->rollback();return 422;}
        $child=(string)$signed['payment_id'];$invoice=(string)$signed['invoice_id'];$status=(string)$signed['payment_status'];
        if (!achbotHostedReferenceSyntax($child) || !achbotHostedReferenceSyntax($invoice) ||
            (string)$signed['order_id']!==$hash || strtolower((string)$signed['price_currency'])!=='usd' ||
            !achbotNpDecimalAtLeast($signed['price_amount'],$a['quote_amount']) ||
            !achbotNpDecimalAtLeast($a['quote_amount'],$signed['price_amount']) ||
            !in_array($status,['waiting','confirming','confirmed','sending','partially_paid','finished','failed','expired','refunded'],true)) {
            $db->rollback();return 422;
        }
        // A genuinely signed original IPN may identify an unresolved creation.
        // Bind the exact provider identity but never invent a checkout URL or
        // reopen a browser invoice. The worker still verifies money via GET.
        if ($a['stage']==='creating' && $a['wallet_invoice_sha256']==='' &&
            $a['provider_reference']===null && $pay['payid']==='achbot_nowpayments_creating_'.$id) {
            if (achbotHasUnscopedPaymentReferenceCollision($db,$invoice,$id)) {$db->rollback();return 409;}
            $s=achbotProviderStatement($db,"UPDATE pays SET payid=? WHERE id=? AND payid=?",
                'sis',[$invoice,$id,$pay['payid']]);$n=$s->affected_rows;$s->close();
            if ($n!==1) throw new RuntimeException('hosted_ipn_reference_write');
            $pay['payid']=$invoice;$a['provider_reference']=$invoice;$a['checkout_url']=null;
            $pin=achbotCreditInvoiceFingerprint($pay,$a);
            $s=achbotProviderStatement($db,"UPDATE payment_attempts SET provider_reference=?,checkout_url=NULL,
                stage='review',provider_status='signed_creation_recovered',wallet_invoice_sha256=?
                WHERE id=? AND stage='creating' AND provider_reference IS NULL AND wallet_invoice_sha256=''",
                'ssi',[$invoice,$pin,(int)$a['id']]);$n=$s->affected_rows;$s->close();
            if ($n!==1) throw new RuntimeException('hosted_ipn_pin_write');
            $a['wallet_invoice_sha256']=$pin;
        }
        if ($a['provider_reference']!==$invoice || $pay['payid']!==$invoice ||
            !is_string($a['wallet_invoice_sha256']) ||
            !hash_equals($a['wallet_invoice_sha256'],achbotCreditInvoiceFingerprint($pay,$a))) {
            $db->rollback();return 409;
        }
        $existing=achbotProviderRow($db,"SELECT * FROM payment_transactions WHERE provider='nowpayments' AND payment_id=? FOR UPDATE",'s',[$child]);
        if ($existing && (int)$existing['attempt_id']!==(int)$a['id']) {$db->rollback();return 409;}
        if (!$existing) {
            $s=achbotProviderStatement($db,"INSERT INTO payment_transactions(attempt_id,provider,payment_id,observed_status)
                VALUES (?,'nowpayments',?,?)",'iss',[(int)$a['id'],$child,$status]);$s->close();
        } elseif (achbotNpChildEvidenceRank($status)>achbotNpChildEvidenceRank($existing['observed_status']) ||
            ($existing['observed_status']==='waiting' && in_array($status,['failed','expired'],true))) {
            $s=achbotProviderStatement($db,'UPDATE payment_transactions SET observed_status=? WHERE id=?',
                'si',[$status,(int)$existing['id']]);$s->close();
        }
        if ($a['payment_id']===null) {
            $s=achbotProviderStatement($db,'UPDATE payment_attempts SET payment_id=? WHERE id=? AND payment_id IS NULL',
                'si',[$child,(int)$a['id']]);$s->close();
        }
        if (!$db->commit()) throw new RuntimeException('hosted_ipn_commit');
        return 200;
    } catch (Throwable $e) {
        $db->rollback();
        achbotDiagnosticEvent('achbot.finance.hosted_ipn',['reason'=>'original_evidence_deferred','class'=>get_class($e)],'warning');
        return 409;
    }
}

/** Explicit bounded merchant reconciliation of admitted references. A known
 * late child is polled even when the original invoice has already been paid
 * or rejected. An unknown issuance is NOT sent again. */
function achbotHostedReceiptMaintain(mysqli $db,int $limit=3): array {
    $limit=max(1,min(5,$limit));
    $done=['examined'=>0,'completed'=>0,'pending'=>0,'review'=>0];
    $q=$db->query("SELECT a.pay_row_id,p.user_id,a.provider,a.provider_reference AS reference,
        NULL AS child_id,COALESCE(a.last_checked_at,a.created_at) AS due
        FROM payment_attempts a JOIN pays p ON p.id=a.pay_row_id
        WHERE a.provider IN ('nextpay','zarinpal') AND a.wallet_invoice_sha256<>'' AND a.wallet_credit_entry_id IS NULL
          AND (a.last_checked_at IS NULL OR a.last_checked_at<DATE_SUB(NOW(),INTERVAL 60 SECOND))
        UNION ALL
        SELECT a.pay_row_id,p.user_id,a.provider,t.payment_id AS reference,t.id AS child_id,
          COALESCE(t.merchant_checked_at,t.received_at) AS due
        FROM payment_transactions t JOIN payment_attempts a ON a.id=t.attempt_id
        JOIN pays p ON p.id=a.pay_row_id
        WHERE t.provider='nowpayments' AND a.wallet_invoice_sha256<>'' AND t.merchant_verified_at IS NULL
          AND t.observed_status NOT IN ('refunded','failed','expired')
          AND (t.merchant_checked_at IS NULL OR t.merchant_checked_at<DATE_SUB(NOW(),INTERVAL 60 SECOND))
        ORDER BY due,pay_row_id,child_id LIMIT ".$limit);
    if (!$q) throw new RuntimeException('hosted_due_read');
    $deadline=microtime(true)+25.0;
    foreach ($q->fetch_all(MYSQLI_ASSOC) as $row) {
        if (microtime(true)>=$deadline) break;
        $id=(int)$row['pay_row_id'];
        $lease=achbotOriginalDispatchLeaseBegin($db,$id);
        if (!in_array($lease,['owned','borrowed'],true)) {
            // Busy rows must not permanently occupy the head of the queue.
            // Record scheduling only; never change evidence or the receipt.
            if ($row['child_id']===null)
                $s=achbotProviderStatement($db,'UPDATE payment_attempts SET last_checked_at=NOW() WHERE pay_row_id=?','i',[$id]);
            else $s=achbotProviderStatement($db,'UPDATE payment_transactions SET merchant_checked_at=NOW(6) WHERE id=?','i',[(int)$row['child_id']]);
            $s->close();
            continue;
        }
        try {
            $done['examined']++;
            if ($row['child_id']===null)
                $s=achbotProviderStatement($db,'UPDATE payment_attempts SET last_checked_at=NOW() WHERE pay_row_id=?','i',[$id]);
            else $s=achbotProviderStatement($db,'UPDATE payment_transactions SET merchant_checked_at=NOW(6) WHERE id=?','i',[(int)$row['child_id']]);
            $s->close();
            $r=achbotHostedReceiptVerify($db,$id,(int)$row['user_id'],$row['provider'],(string)$row['reference']);
            $done[($r['status']??null)==='credited'?'completed':'pending']++;
        } catch (Throwable $e) {
            $done['review']++;
            achbotDiagnosticEvent('achbot.finance.hosted_reconcile',['pay_id'=>$id,'reason'=>'original_merchant_reconcile_deferred','class'=>get_class($e)],'warning');
        } finally {achbotOriginalDispatchLeaseEnd($db,$id,$lease);}
    }
    return $done;
}
