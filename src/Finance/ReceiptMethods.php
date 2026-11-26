<?php
declare(strict_types=1);

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/../Admin/Access.php';
require_once __DIR__.'/PaymentEvidence.php';
require_once __DIR__.'/ManualReceiptIntent.php';
require_once __DIR__.'/GatewayClient.php';
require_once __DIR__.'/HostedMethods.php';
require_once __DIR__.'/../../messages/loader.php';

/**
 * Source-owned receipt admission and verification definitions. Callbacks
 * validate the issued invoice and its proof; they do not credit Wallet, fund
 * a service, or make network calls under SQL locks. The registry uses the
 * actual persisted payment-method key, never a callback-provided function.
 * Hosted gateways may retain additional authenticated external evidence
 * on replay without authorizing another financial movement.
 */
function achbotCreditMethodRegistry(): array {
    $manual=['invoice'=>'achbotCreditValidateManualInvoice','proofs'=>[
        'manual'=>'achbotCreditVerifyManualLocked'],'additional_evidence'=>false,
        'manual_quote'=>'achbotManualBuildQuote'];
    $api=$manual;$api['proofs']['api']='achbotCreditVerifyApiLocked';
    $bank=$api;
    $bank['api_checker']='achbotCreditCheckBankApi';
    $api['api_checker']='achbotCreditCheckGiftApi';
    return [
        'bank_transfer'=>$bank+['label'=>static fn()=>achbotMessage('manual_payment.receipt_helpers.manual_label.text.ace4b5ad89')],
        'gift_card'=>$api+['label'=>static fn()=>achbotMessage('manual_payment.receipt_helpers.manual_label.text.3904ef0f3d')],
        'manual_usdt'=>$manual+['label'=>static fn()=>achbotMessage('manual_payment.receipt_helpers.manual_label.text.2f1fd66193')],
        'manual_trx'=>$manual+['label'=>static fn()=>achbotMessage('manual_payment.receipt_helpers.manual_label.text.ea9faa7b63')],
        'nowpayments'=>['label'=>static fn()=>achbotMessage('automatic_gateway_review.provider_nowpayments'),
            'invoice'=>'achbotCreditValidateUsdInvoice','proofs'=>[
            'nowpayments'=>'achbotCreditVerifyNowpaymentsLocked','manual'=>'achbotCreditVerifyHostedAttestationLocked'],'additional_evidence'=>true,
            'hosted'=>['credential_setting'=>'nowpayment','checkout_input'=>'achbotHostedNowpaymentsCheckoutInput','quote'=>'achbotHostedUsdQuote',
                'issue'=>'achbotHostedNowpaymentsIssue','verify'=>'achbotHostedNowpaymentsVerify',
                'url'=>'achbotHostedNowpaymentsUrl','reference'=>'achbotHostedChildReference',
                'child_transactions'=>true]],
        'nextpay'=>['label'=>static fn()=>achbotMessage('automatic_gateway_review.provider_nextpay'),
            'invoice'=>'achbotCreditValidateTomanInvoice','proofs'=>[
            'nextpay'=>'achbotCreditVerifyNextpayLocked','manual'=>'achbotCreditVerifyHostedAttestationLocked'],'additional_evidence'=>true,
            'hosted'=>['credential_setting'=>'nextpay','checkout_input'=>'achbotHostedTomanCheckoutInput','quote'=>'achbotHostedTomanQuote',
                'issue'=>'achbotHostedNextpayIssue','verify'=>'achbotHostedNextpayVerify',
                'url'=>'achbotHostedNextpayUrl','reference'=>'achbotHostedExactReference',
                'child_transactions'=>false]],
        'zarinpal'=>['label'=>static fn()=>achbotMessage('automatic_gateway_review.provider_zarinpal'),
            'invoice'=>'achbotCreditValidateTomanInvoice','proofs'=>[
            'zarinpal'=>'achbotCreditVerifyZarinpalLocked','manual'=>'achbotCreditVerifyHostedAttestationLocked'],'additional_evidence'=>true,
            'hosted'=>['credential_setting'=>'zarinpal','checkout_input'=>'achbotHostedTomanCheckoutInput','quote'=>'achbotHostedTomanQuote',
                'issue'=>'achbotHostedZarinpalIssue','verify'=>'achbotHostedZarinpalVerify',
                'url'=>'achbotHostedZarinpalUrl','reference'=>'achbotHostedExactReference',
                'child_transactions'=>false]],
    ];
}
function achbotCreditMethodDefinition(string $key): ?array {
    if (!preg_match('/\A[a-z][a-z0-9_]{0,31}\z/D',$key)) return null;
    $method=achbotCreditMethodRegistry()[$key]??null;
    if (!is_array($method) || !is_callable($method['label']??null) || !is_callable($method['invoice']??null) ||
        !is_bool($method['additional_evidence']??null) ||
        !is_array($method['proofs']??null) || !$method['proofs']) return null;
    foreach ($method['proofs'] as $basis=>$verify) {
        if (!is_string($basis) || !preg_match('/\A[a-z][a-z0-9_]{0,31}\z/D',$basis) ||
            !is_callable($verify)) return null;
    }
    if (isset($method['api_checker']) && !is_callable($method['api_checker'])) return null;
    if (isset($method['manual_quote']) && !is_callable($method['manual_quote'])) return null;
    if (array_key_exists('hosted',$method)) {
        $h=$method['hosted'];
        if (!is_array($h) || !is_string($h['credential_setting']??null) ||
            $h['credential_setting']==='' || !is_bool($h['child_transactions']??null) ||
            !isset($method['proofs'][$key])) return null;
        foreach (['checkout_input','quote','issue','verify','url','reference'] as $callback)
            if (!is_callable($h[$callback]??null)) return null;
    }
    return $method;
}

/** One source-owned method label for receipt presentation and reports. */
function achbotCreditMethodLabel(string $key): string {
    $method=achbotCreditMethodRegistry()[$key]??null;
    return $method!==null?($method['label'])():($key!==''?$key:'-');
}

/** Shared classification and SQL selectors derive from the same registered
 * capabilities. Keys are source-owned and validated before SQL interpolation. */
function achbotCreditMethodHas(string $key,string $capability): bool {
    $method=achbotCreditMethodDefinition($key);
    return isset($method[$capability]);
}
function achbotCreditMethodSqlList(string $capability): string {
    $keys=[];
    foreach (array_keys(achbotCreditMethodRegistry()) as $key)
        if (achbotCreditMethodHas($key,$capability)) $keys[]="'".$key."'";
    return $keys?implode(',',$keys):'NULL';
}

function achbotCreditValidateManualInvoice(array $pay,array $attempt): void {
    if ((string)($pay['payid']??'')!=='' ||
        achbotManualIntentDecode((string)($attempt['checkout_url']??''),(string)$attempt['provider'])===null)
        throw new RuntimeException('payment_credit_manual_identity');
}
function achbotCreditValidateHostedReference(array $pay,array $attempt): void {
    $reference=(string)($attempt['provider_reference']??'');
    if ($reference==='' || (string)($pay['payid']??'')!==$reference)
        throw new RuntimeException('payment_credit_hosted_reference');
}
function achbotCreditValidateTomanInvoice(array $pay,array $attempt): void {
    achbotCreditValidateHostedReference($pay,$attempt);
    if (strtolower((string)$attempt['quote_currency'])!=='toman' ||
        !achbotNpDecimalAtLeast($attempt['quote_amount'],(string)$pay['price']) ||
        !achbotNpDecimalAtLeast((string)$pay['price'],$attempt['quote_amount']))
        throw new RuntimeException('payment_credit_hosted_quote');
}
function achbotCreditValidateUsdInvoice(array $pay,array $attempt): void {
    achbotCreditValidateHostedReference($pay,$attempt);
    if (strtolower((string)$attempt['quote_currency'])!=='usd' ||
        !achbotNpDecimalAtLeast($attempt['quote_amount'],'0.00000001'))
        throw new RuntimeException('payment_credit_hosted_quote');
}

/** Durable hosted child identity, written only after authenticated exact
 * merchant proof. Preserve an existing stronger contradictory status. */
function achbotCreditHostedEvidenceLocked(mysqli $db,array $attempt,string $reference): int {
    $provider=(string)$attempt['provider'];$aid=(int)$attempt['id'];
    if (!preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D',$reference))
        throw new RuntimeException('payment_credit_external_reference');
    $q=$db->prepare('SELECT id FROM payment_attempts
        WHERE provider=? AND payment_id=? AND id<>? FOR UPDATE');
    if (!$q) throw new RuntimeException('payment_credit_primary_prepare');
    $q->bind_param('ssi',$provider,$reference,$aid);
    if (!$q->execute() || !($rows=$q->get_result()))
        throw new RuntimeException('payment_credit_primary_read');
    $conflict=$rows->num_rows!==0;$q->close();
    if ($conflict) throw new RuntimeException('payment_credit_external_collision');
    $q=$db->prepare('SELECT id,attempt_id,observed_status FROM payment_transactions
        WHERE provider=? AND payment_id=? FOR UPDATE');
    if (!$q) throw new RuntimeException('payment_credit_evidence_prepare');
    $q->bind_param('ss',$provider,$reference);
    if (!$q->execute() || !($rows=$q->get_result()))
        throw new RuntimeException('payment_credit_evidence_read');
    $row=$rows->fetch_assoc();$q->close();
    if ($row) {
        if ((int)$row['attempt_id']!==$aid || $row['observed_status']==='refunded')
            throw new RuntimeException('payment_credit_external_collision');
        $tid=(int)$row['id'];
        if ($row['observed_status']!=='finished') {
            $q=$db->prepare("UPDATE payment_transactions SET observed_status='finished' WHERE id=?");
            if (!$q) throw new RuntimeException('payment_credit_evidence_update_prepare');
            $q->bind_param('i',$tid);
            if (!$q->execute() || $q->affected_rows!==1)
                throw new RuntimeException('payment_credit_evidence_update');
            $q->close();
        }
        return $tid;
    }
    $q=$db->prepare("INSERT INTO payment_transactions
        (attempt_id,provider,payment_id,observed_status) VALUES (?,?,?,'finished')");
    if (!$q) throw new RuntimeException('payment_credit_evidence_insert_prepare');
    $q->bind_param('iss',$aid,$provider,$reference);
    if (!$q->execute() || $q->affected_rows!==1)
        throw new RuntimeException('payment_credit_evidence_insert');
    $tid=(int)$q->insert_id;$q->close();
    return $tid;
}

/** The manual evidence contract is shared only by registered manual methods. */
function achbotCreditValidateManualReceiptLocked(mysqli $db,array $pay,array $attempt): void {
    if (!in_array($pay['state'],['have_sent','service_superseded'],true) ||
        $attempt['stage']!=='review' ||
        (int)($pay['message_id']??0)<=0 || (int)($pay['chat_id']??0)===0)
        throw new RuntimeException('payment_credit_manual_receipt');
    $intent=achbotManualIntentDecode((string)$attempt['checkout_url'],(string)$attempt['provider']);
    if (empty($intent['receipt_file_id']) &&
        !achbotPaymentEvidenceHasTypedReviewLocked($db,$pay))
        throw new RuntimeException('payment_credit_manual_evidence');
}
function achbotCreditVerifyManualLocked(mysqli $db,array $pay,array $attempt,array $proof,int $actor,int $primary): ?int {
    achbotCreditValidateManualReceiptLocked($db,$pay,$attempt);
    if ($actor<=0 || $primary<=0 || !achbotAdminCanLocked($db,$actor,$primary,'payments'))
        throw new RuntimeException('payment_credit_manual_authority');
    // A legitimate manual approval and later verified API proof of the SAME
    // invoice share the one monetary source; the adapter cannot credit itself.
    return null;
}
/** Explicit staff attestation is local evidence, never a synthetic merchant transaction. */
function achbotCreditVerifyHostedAttestationLocked(mysqli $db,array $pay,array $attempt,array $proof,int $actor,int $primary): ?int {
    require_once __DIR__.'/GatewayReview.php';
    if (!achbotAdminCanLocked($db,$actor,$primary,'payments'))
        throw new RuntimeException('payment_credit_hosted_actor_invalid');
    $r=achbotGatewayReviewEligible($db,(int)$attempt['id'],'approve',true);
    if (!$r || (int)$r['pay']['id']!==(int)$pay['id'] ||
        !hash_equals($r['digest'],(string)($proof['review_digest']??'')))
        throw new RuntimeException('payment_credit_hosted_review_changed');
    return null;
}

function achbotCreditVerifyApiLocked(mysqli $db,array $pay,array $attempt,array $proof,int $actor,int $primary): ?int {
    if ($actor!==0 || !achbotPaymentEvidenceValidateProofLocked($db,$pay,$proof))
        throw new RuntimeException('payment_credit_api_proof');
    return (int)$proof['transaction_id'];
}
function achbotCreditVerifyNowpaymentsLocked(mysqli $db,array $pay,array $attempt,array $proof,int $actor,int $primary): ?int {
    if ($actor!==0 || !achbotNpValidFinalAmount($attempt,$proof) ||
        (string)($proof['invoice_id']??'')!==(string)$attempt['provider_reference'] ||
        (string)($proof['order_id']??'')!==(string)$attempt['order_hash'] ||
        achbotHasUnscopedPaymentReferenceCollision($db,(string)$attempt['provider_reference'],(int)$pay['id']))
        throw new RuntimeException('payment_credit_nowpayments_proof');
    return achbotCreditHostedEvidenceLocked($db,$attempt,(string)($proof['payment_id']??''));
}
function achbotCreditVerifyReferenceLocked(mysqli $db,array $pay,array $attempt,array $proof,int $actor,bool $verified): int {
    $reference=(string)($proof['reference']??'');
    if ($actor!==0 || !$verified ||
        $reference!==(string)$attempt['provider_reference'] ||
        ($proof['amount']??null)!==(int)$pay['price'] ||
        achbotHasUnscopedPaymentReferenceCollision($db,$reference,(int)$pay['id']))
        throw new RuntimeException('payment_credit_hosted_proof');
    return achbotCreditHostedEvidenceLocked($db,$attempt,$reference);
}
function achbotCreditVerifyNextpayLocked(mysqli $db,array $pay,array $attempt,array $proof,int $actor,int $primary): ?int {
    return achbotCreditVerifyReferenceLocked($db,$pay,$attempt,$proof,$actor,
        achbotVerifiedGatewayOutcome('nextpay',$proof['status']??null)==='verified');
}
function achbotCreditVerifyZarinpalLocked(mysqli $db,array $pay,array $attempt,array $proof,int $actor,int $primary): ?int {
    $status=$proof['status']??null;
    // 101 recovery is permitted only for the newly issued merchant-pinned
    // contract. The common credit path also checks the captured profile.
    $verified=in_array($status,[100,'100'],true) ||
        (in_array($status,[101,'101'],true) && !empty($attempt['wallet_merchant_profile']));
    return achbotCreditVerifyReferenceLocked($db,$pay,$attempt,$proof,$actor,$verified);
}


/** Existing authenticated evidence transport, with the new receipt sink.
 * Provider-specific matching/redemption stays in its original single owner. */
function achbotCreditCheckBankApi(mysqli $db,array $claim,callable $settle): string {
    require_once __DIR__.'/ApiPayments.php';
    return achbotApiPaymentCheckBank($db,$claim,$settle);
}
function achbotCreditCheckGiftApi(mysqli $db,array $claim,callable $settle): string {
    require_once __DIR__.'/ApiPayments.php';
    return achbotApiPaymentCheckGift($db,$claim,$settle);
}
