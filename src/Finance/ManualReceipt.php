<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/FundingCheckout.php';
require_once __DIR__.'/ReceiptHelpers.php';

/** Trusted original checkout caller checks audience, age, configuration and
 * authorization before entering. New invoices never pin historical attempts.
 * An optional verified-evidence API profile is snapshotted on the same commit,
 * without a Native subject preflight or a duplicate service-money owner. */
function achbotManualReceiptIssue(mysqli $db,int $payId,int $userId,string $method,
    ?int $operationId,?string $network=null,?array $draftStates=null,?string $expectedMode=null): array {
    achbotPaymentEvidenceRequireAutocommit($db);
    $def=achbotCreditMethodDefinition($method);
    if (!$def || !isset($def['manual_quote']))
        throw new DomainException('manual_method_unavailable');
    if (!$db->begin_transaction()) throw new RuntimeException('manual_issue_begin');
    try {
        $pay=achbotCheckoutPayLocked($db,$payId,$userId);
        $a=achbotProviderRow($db,'SELECT * FROM payment_attempts WHERE pay_row_id=? FOR UPDATE','i',[$payId]);
        if ($draftStates!==null) {
            require_once __DIR__.'/CheckoutAdmission.php';
            achbotCheckoutRequireSchema($db);
            if ($expectedMode!==null && !achbotCheckoutModeMatchesRequest($pay,$expectedMode))
                throw new DomainException('checkout_draft_unavailable');
            $operationId=achbotCheckoutAdmitDraftLocked($db,$pay,$a,$method,$draftStates);
            // Read back the exact committed-in-this-transaction binding.
            $pay=achbotCheckoutPayLocked($db,$payId,$userId);
            if ($a!==null && empty($a['provider_status']) &&
                $a['evidence_profile']===null &&
                strtotime((string)$a['created_at'])+achbotPayUxManualDeadlineSeconds()<=time())
                throw new DomainException('manual_draft_unavailable');
        }
        if ($a) {
            if ($a['provider']!==$method || (int)($a['service_operation_id']??0)!==($operationId??0) ||
                !is_string($a['wallet_invoice_sha256']) ||
                !hash_equals($a['wallet_invoice_sha256'],achbotCreditInvoiceFingerprint($pay,$a)))
                throw new DomainException('manual_original_attempt_conflict');
            $intent=achbotManualIntentDecode((string)$a['checkout_url'],$method);
            if (!$intent || ($network!==null && ($intent['network']??null)!==$network))
                throw new DomainException('manual_original_network_conflict');
        } else {
            if ($pay['state']!=='pending' || (string)($pay['payid']??'')!=='' ||
                (int)$pay['price']<=0 || $pay['hash_id']==='')
                throw new DomainException('manual_draft_unavailable');
            achbotCheckoutValidateOriginalLocked($db,$pay,$userId,$operationId);
            // New customer admission reads the current persisted document
            // under the same transaction. Prepared internal commands supply
            // their trusted configuration; an existing pin reads neither.
            $keys=$draftStates!==null?(achbotPayUxCurrentDocumentLocked($db,'PAYMENT_KEYS')??[]):
                (array)($GLOBALS['paymentKeys']??[]);
            $money=($def['manual_quote'])((int)$pay['price'],$method,$network,$keys);
            $s=achbotProviderStatement($db,"INSERT INTO payment_attempts
                (pay_row_id,provider,order_hash,quote_amount,quote_currency,local_amount_toman,
                settlement_currency,checkout_url,stage,wallet_invoice_sha256,service_operation_id)
                VALUES (?,?,?,?,?,?,?,?,'pending','',?)",'issssissi',
                [$payId,$method,$pay['hash_id'],$money['amount'],$money['currency'],(int)$pay['price'],
                $money['settlement_currency'],$money['destination'],$operationId]);
            $aid=(int)$s->insert_id;$s->close();
            if ($aid<=0) throw new RuntimeException('manual_invoice_insert');
            $a=achbotProviderRow($db,'SELECT * FROM payment_attempts WHERE id=? FOR UPDATE','i',[$aid]);
            $profile=achbotPaymentEvidenceProfile($method);
            if ($profile!==null && !achbotPaymentEvidencePinLocked($db,$pay,$a,$profile))
                throw new RuntimeException('manual_evidence_pin');
            achbotCheckoutPinReceiptLocked($db,$payId,$userId);
            $a=achbotProviderRow($db,'SELECT * FROM payment_attempts WHERE id=? FOR UPDATE','i',[$aid]);
            $intent=achbotManualIntentDecode((string)$a['checkout_url'],$method);
        }
        // Only publish the original invoice in an open state. Never return
        // encrypted API credentials or merchant configuration to a UI caller.
        $result=['status'=>$pay['state']==='pending' && $a['stage']==='pending' &&
            $a['wallet_credit_entry_id']===null?'issued':'closed',
            'amount'=>(string)$a['quote_amount'],'currency'=>$a['quote_currency'],'intent'=>$intent];
        if (!$db->commit()) throw new RuntimeException('manual_issue_commit');
        return $result;
    } catch (Throwable $e) {$db->rollback();throw $e;}
}

/** API recovery owns evidence lookup/consumption, not service eligibility.
 * Previously proven money settles once, independently of service validity.
 * An explicitly rejected invoice cannot initiate new evidence consumption. */
function achbotManualReceiptCheckApi(mysqli $db,int $payId): string {
    require_once __DIR__.'/ApiPayments.php';
    return achbotApiPaymentWithWorkerLock($db,$payId,static function() use ($db,$payId): string {
        return achbotApiPaymentWithBudget(15.0,static function() use ($db,$payId): string {
            achbotPaymentEvidenceRequireAutocommit($db);
            $original=achbotApiPaymentRow($db,$payId);
            if (!$original) return 'unavailable';
            $owner=(int)$original['user_id'];
            if (!$db->begin_transaction()) throw new RuntimeException('api_receipt_check_begin');
            try {
                [$pay,$a]=achbotCreditInvoiceLocked($db,$payId,$owner);
                $pin=$a['wallet_invoice_sha256'];
                $def=achbotCreditMethodDefinition((string)$a['provider']);
                if (!$def || !isset($def['api_checker']) ||
                    !is_string($pin) || !hash_equals($pin,achbotCreditInvoiceFingerprint($pay,$a)) ||
                    !achbotPaymentEvidenceSnapshot($a))
                    throw new DomainException('api_receipt_unadmitted');
                if (!$db->commit()) throw new RuntimeException('api_receipt_check_commit');
            } catch (Throwable $e) {$db->rollback();throw $e;}
            $settle=static function(mysqli $db,int $id,array $proof) use ($payId,$owner): string {
                if ($id!==$payId) throw new RuntimeException('api_receipt_source_changed');
                achbotCheckoutSettleReceipt($db,$id,$owner,'api',$proof);
                return 'settled';
            };
            $proof=achbotPaymentEvidenceGetProof($db,$payId);
            if ($proof!==null && ($proof['mode']??null)==='settle') return $settle($db,$payId,$proof);
            // A human may have credited the same receipt first. Verify its
            // durable journal before treating its financial projection as final.
            if ($a['wallet_credit_entry_id']!==null) $settle($db,$payId,[]);
            if ($pay['state']==='declined') return 'rejected';
            if (!achbotPaymentEvidenceReviewReady($pay,$a) &&
                !achbotPaymentEvidencePending($db,$payId)) return 'awaiting_receipt';
            $claim=achbotPaymentEvidenceClaim($db,$payId);
            if (!$claim) return 'pending';
            $status='unavailable';
            try {
                $status=($def['api_checker'])($db,$claim,$settle);
                if ($status==='settled') {
                    // The old checker may observe an already-paid state.
                    // The new receipt owner must verify the corresponding
                    // credit link rather than infer success from that state.
                    $settle($db,$payId,achbotPaymentEvidenceGetProof($db,$payId)??[]);
                }
                return $status;
            } finally {
                $state=match($status) {
                    'settled'=>'proven',
                    'used','expired','revoked','mismatch'=>'invalid_code',
                    'review'=>'review',
                    'manual'=>'manual',
                    'pending'=>'uncertain',
                    default=>'monitoring',
                };
                achbotPaymentEvidenceDefer($db,$payId,(string)$claim['token'],$state,
                    $status==='pending'?20:60);
            }
        });
    });
}
