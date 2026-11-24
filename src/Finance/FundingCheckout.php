<?php
declare(strict_types=1);

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/PaymentCredit.php';
require_once __DIR__.'/../Services/Funding.php';

/** The new issuer sets service_operation_id at INSERT (or NULL for top-up).
 * A service checkout still invoices the entire original amount. No shortfall
 * pricing or Native preflight is introduced here. */
function achbotCheckoutPinReceiptLocked(mysqli $db,int $payId,int $userId): void {
    [$pay,$attempt]=achbotCreditInvoiceLocked($db,$payId,$userId);
    if (!array_key_exists('service_operation_id',$attempt))
        throw new RuntimeException('checkout_funding_schema_missing');
    if ($attempt['service_operation_id']!==null) {
        [$op]=achbotServiceReadLocked($db,$userId,(int)$attempt['service_operation_id']);
        if ($op['stage']!=='awaiting_funds' || (int)$op['price_toman']!==(int)$pay['price'])
            throw new RuntimeException('checkout_service_invoice_mismatch');
    }
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
        else $result=achbotServiceFundLocked($db,$userId,$operation);
    }
    $s=achbotProviderStatement($db,'UPDATE payment_attempts SET wallet_funding_result=?
        WHERE id=? AND wallet_credit_entry_id=? AND wallet_funding_result IS NULL',
        'sii',[$result,(int)$attempt['id'],$entry['id']]);
    $n=$s->affected_rows;$s->close();
    if ($n!==1) throw new RuntimeException('checkout_funding_result_write');
    return ['credit'=>$entry,'operation'=>$operation,'funding'=>$result];
}
