<?php
declare(strict_types=1);

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/PaymentCredit.php';
require_once __DIR__.'/../Services/Funding.php';

function achbotCheckoutPayLocked(mysqli $db,int $payId,int $userId): array {
    achbotWalletLockOwnerLocked($db,$userId);
    $pay=achbotProviderRow($db,'SELECT * FROM pays WHERE id=? FOR UPDATE','i',[$payId]);
    if (!$pay || (int)$pay['user_id']!==$userId) throw new RuntimeException('payment_credit_owner_mismatch');
    return $pay;
}

/** Owner and pay already locked. This is original invoice admission,
 * not a later Native/preflight eligibility check. */
function achbotCheckoutValidateOriginalLocked(mysqli $db,array $pay,int $userId,?int $operationId): void {
    if ($operationId===null) {
        if ($pay['type']!=='INCREASE_WALLET')
            throw new DomainException('checkout_service_invoice_missing');
        return;
    }
    [$op]=achbotServiceReadLocked($db,$userId,$operationId);
    if ($op['command_kind']==='checkout') {
        require_once __DIR__.'/CheckoutAdmission.php';
        if ((int)($pay['checkout_operation_id']??0)!==$operationId) throw new DomainException('checkout_original_command_changed');
        achbotCheckoutOriginalCommandLocked($db,$pay);
    }
    if ($op['stage']!=='awaiting_funds' || (int)$op['price_toman']!==(int)$pay['price'])
        throw new DomainException('checkout_service_invoice_mismatch');
}

/** The new issuer sets service_operation_id at INSERT (or NULL for top-up).
 * A service checkout still invoices the entire original amount. No shortfall
 * pricing or Native preflight is introduced here. */
function achbotCheckoutPinReceiptLocked(mysqli $db,int $payId,int $userId): void {
    [$pay,$attempt]=achbotCreditInvoiceLocked($db,$payId,$userId);
    if (!array_key_exists('service_operation_id',$attempt))
        throw new RuntimeException('checkout_funding_schema_missing');
    achbotCheckoutValidateOriginalLocked($db,$pay,$userId,
        $attempt['service_operation_id']===null?null:(int)$attempt['service_operation_id']);
    achbotCreditPinIssuedLocked($db,$payId,$userId);
}

/** Transaction owned by the route adapter. Receipt credit, optional debit,
 * capacity, funded continuation and original handoff result commit together.
 * A verified receipt for an unavailable original service remains in Wallet.
 * Replaying the receipt never makes another admission/funding decision. */
function achbotCheckoutSettleReceiptLocked(mysqli $db,int $payId,int $userId,string $basis,
    array $proof=[],int $actor=0,int $primary=0): array {
    [$pay,$attempt]=achbotCreditInvoiceLocked($db,$payId,$userId);
    if (!array_key_exists('wallet_funding_result',$attempt))
        throw new RuntimeException('checkout_funding_schema_missing');
    $entry=achbotPaymentCreditLocked($db,$payId,$userId,$basis,$proof,$actor,$primary);
    $operation=$attempt['service_operation_id']===null?null:(int)$attempt['service_operation_id'];
    if ($entry['replayed']) {
        if ($attempt['wallet_funding_result']===null)
            throw new RuntimeException('checkout_funding_finality_missing');
        return ['credit'=>$entry,'operation'=>$operation,'funding'=>$attempt['wallet_funding_result']];
    }
    $result='wallet_only';
    if ($operation!==null) {
        // The original quote cannot be replaced with a different plan.
        // Service-superseded/expired/archived requests keep received value.
        if (in_array($pay['state'],['service_superseded','expired','expired_have_sent','mismatched_plan'],true) ||
            ($attempt['review_archived_at']??null)!==null) $result='request_invalidated';
        else {
            [$op]=achbotServiceReadLocked($db,$userId,$operation);
            if ($op['command_kind']==='checkout') {
                require_once __DIR__.'/CheckoutAdmission.php';
                achbotCheckoutOriginalCommandLocked($db,$pay);
                if ((int)($pay['checkout_operation_id']??0)!==$operation)
                    throw new RuntimeException('checkout_original_command_changed');
            }
            $result=achbotServiceFundLocked($db,$userId,$operation);
        }
    }
    $s=achbotProviderStatement($db,'UPDATE payment_attempts SET wallet_funding_result=?
        WHERE id=? AND wallet_credit_entry_id=? AND wallet_funding_result IS NULL',
        'sii',[$result,(int)$attempt['id'],$entry['id']]);
    $n=$s->affected_rows;$s->close();
    if ($n!==1) throw new RuntimeException('checkout_funding_result_write');
    // These are financial projections only. The durable service operation
    // and its delivery have their own result; paid never means provisioned.
    $s=achbotProviderStatement($db,"UPDATE pays SET state='paid',approved_by=IF(?=0,approved_by,?) WHERE id=?",
        'iii',[$actor,$actor,$payId]);$s->close();
    $s=achbotProviderStatement($db,"UPDATE payment_attempts SET stage='done' WHERE id=?",'i',[(int)$attempt['id']]);$s->close();
    require_once __DIR__.'/ReceiptReports.php';
    achbotReceiptReportStageLocked($db,$payId,$userId);
    return ['credit'=>$entry,'operation'=>$operation,'funding'=>$result];
}


/** The one transaction boundary for persisted API evidence and authenticated
 * manual/timer decisions. No external transport or delivery under its locks. */
function achbotCheckoutSettleReceipt(mysqli $db,int $payId,int $userId,string $basis,
    array $proof=[],int $actor=0,int $primary=0): array {
    achbotPaymentEvidenceRequireAutocommit($db);
    if (!$db->begin_transaction()) throw new RuntimeException('checkout_settlement_begin');
    try {
        $result=achbotCheckoutSettleReceiptLocked($db,$payId,$userId,$basis,$proof,$actor,$primary);
        if (!$db->commit()) throw new RuntimeException('checkout_settlement_commit');
        return $result;
    } catch (Throwable $e) {$db->rollback();throw $e;}
}
