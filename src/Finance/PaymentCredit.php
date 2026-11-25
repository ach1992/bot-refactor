<?php
declare(strict_types=1);

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__.'/../Wallet/Ledger.php';
require_once __DIR__.'/PaymentEvidence.php';
require_once __DIR__.'/ReceiptMethods.php';
require_once __DIR__.'/../Panel/ProviderStorage.php';

/**
 * Wallet-first money identity deliberately excludes mutable service state
 * and a later external child transaction ID. Actual amount and destination/issued quote
 * remain pinned. The caller is an authenticated issuer/verifier, NEVER raw
 * browser/Telegram input. No provider HTTP or service execution under SQL.
 */
function achbotCreditInvoiceFingerprint(array $pay,array $attempt): string {
    $payId=(int)($pay['id']??0);
    $provider=(string)($attempt['provider']??'');
    if ($payId<=0 || (int)($attempt['id']??0)<=0 ||
        (int)($attempt['pay_row_id']??0)!==$payId ||
        (int)($pay['user_id']??0)<=0 || (int)($pay['price']??0)<=0 ||
        (int)($attempt['local_amount_toman']??0)!==(int)$pay['price'] ||
        (string)($pay['hash_id']??'')==='' ||
        (string)($attempt['order_hash']??'')!==(string)$pay['hash_id'])
        throw new RuntimeException('payment_credit_invoice_identity');
    $method=achbotCreditMethodDefinition($provider);
    if ($method===null) throw new RuntimeException('payment_credit_method_unsupported');
    ($method['invoice'])($pay,$attempt);
    // Existing quote fingerprint includes immutable manual intent (except
    // submitted receipt_file_id), merchant reference and exact quote.
    // A later authenticated child does not change the issued invoice.
    unset($attempt['payment_id']);
    return hash('sha256',json_encode([
        $payId,(int)$pay['user_id'],(string)$pay['hash_id'],(int)$pay['price'],
        achbotPaymentEvidenceQuoteFingerprint($attempt),
        hash('sha256',(string)($attempt['evidence_profile']??'')),
        hash('sha256',(string)($attempt['wallet_merchant_profile']??'')),
        (string)($attempt['minimum_settlement_amount']??'0'),
        (int)($attempt['service_operation_id']??0),
    ],JSON_THROW_ON_ERROR));
}

/** Owner -> pays -> attempt, in the caller's existing SQL transaction. */
function achbotCreditInvoiceLocked(mysqli $db,int $payId,int $userId): array {
    achbotWalletLockOwnerLocked($db,$userId);
    $q=$db->prepare('SELECT * FROM pays WHERE id=? FOR UPDATE');
    if (!$q) throw new RuntimeException('payment_credit_invoice_prepare');
    $q->bind_param('i',$payId);
    if (!$q->execute() || !($rows=$q->get_result()))
        throw new RuntimeException('payment_credit_invoice_read');
    $pay=$rows->fetch_assoc();$q->close();
    if (!$pay || (int)$pay['user_id']!==$userId)
        throw new RuntimeException('payment_credit_owner_mismatch');
    $q=$db->prepare('SELECT * FROM payment_attempts WHERE pay_row_id=? FOR UPDATE');
    if (!$q) throw new RuntimeException('payment_credit_attempt_prepare');
    $q->bind_param('i',$payId);
    if (!$q->execute() || !($rows=$q->get_result()))
        throw new RuntimeException('payment_credit_attempt_read');
    $attempt=$rows->fetch_assoc();$q->close();
    if (!$attempt || !array_key_exists('wallet_invoice_sha256',$attempt))
        throw new RuntimeException('payment_credit_attempt_or_schema_missing');
    return [$pay,$attempt];
}

/**
 * NEW issuer-only: the original INSERT must set wallet_invoice_sha256=''
 * (NULL is historical). Pin the bound reference before publishing it. Not a repair
 * command; migration must separately classify historical claims.
 */
function achbotCreditPinIssuedLocked(mysqli $db,int $payId,int $userId): void {
    [$pay,$attempt]=achbotCreditInvoiceLocked($db,$payId,$userId);
    if ($pay['state']!=='pending' || $attempt['stage']!=='pending' ||
        $attempt['wallet_invoice_sha256']!=='' ||
        $attempt['wallet_credit_entry_id']!==null ||
        ($pay['approved_by']??null)!==null)
        throw new RuntimeException('payment_credit_issue_not_new');
    require_once __DIR__.'/PaymentLifecycle.php';
    $attempt=achbotPayUxSealInvoiceLocked($db,$attempt,achbotPayUxCurrentStatesLocked($db)??[]);
    $fingerprint=achbotCreditInvoiceFingerprint($pay,$attempt);
    $id=(int)$attempt['id'];
    $q=$db->prepare("UPDATE payment_attempts SET wallet_invoice_sha256=?
        WHERE id=? AND wallet_invoice_sha256=''");
    if (!$q) throw new RuntimeException('payment_credit_pin_prepare');
    $q->bind_param('si',$fingerprint,$id);
    if (!$q->execute() || $q->affected_rows!==1)
        throw new RuntimeException('payment_credit_pin_write');
    $q->close();
}

/**
 * One common once-only credit. The domain entrypoint receives proof from
 * the existing authenticated verifier or current authorized staff callback.
 * No user-supplied proof is accepted by a web/Telegram endpoint.
 *
 * The caller owns the transaction: credit, receipt link, any admitted
 * service debit/capacity and durable continuations commit together.
 * On any exception roll back; on lost COMMIT ACK reread the original link.
 * Replays return original journal data, never another credit.
 */
function achbotPaymentCreditLocked(mysqli $db,int $payId,int $userId,
    string $basis,array $proof=[],int $actor=0,int $primary=0): array {
    [$pay,$attempt]=achbotCreditInvoiceLocked($db,$payId,$userId);
    $pin=$attempt['wallet_invoice_sha256'];
    if (!is_string($pin) || !hash_equals($pin,achbotCreditInvoiceFingerprint($pay,$attempt)))
        throw new RuntimeException('payment_credit_unadmitted_or_changed_invoice');
    $amount=(int)$pay['price'];
    $method=achbotCreditMethodDefinition((string)$attempt['provider']);
    if ($method===null) throw new RuntimeException('payment_credit_method_unsupported');
    $aid=(int)$attempt['id'];
    $existing=null;
    if ($attempt['wallet_credit_entry_id']!==null) {
        // Do NOT recreate a missing journal or repair mismatched linkage.
        // Existing link is final even if a later callback has weaker evidence.
        $owner=achbotWalletLockOwnerLocked($db,$userId);
        $entry=achbotWalletFindEntryLocked($db,$owner['id'],$userId,'payment_credit',(string)$payId,0,$amount);
        if ($entry===null || $entry['id']!==(int)$attempt['wallet_credit_entry_id'] ||
            $attempt['wallet_credit_basis']===null)
            throw new RuntimeException('payment_credit_finality_conflict');
        $existing=$entry;
        if (!$method['additional_evidence'] || $proof===[])
            return $existing;
        // A verified additional child is retained as evidence, not a second
        // credit. A conflicting later response never reverses this journal.
    }
    if (!empty($attempt['wallet_merchant_profile']) &&
        !hash_equals(hash('sha256',$attempt['wallet_merchant_profile']),(string)($proof['merchant_profile_sha256']??'')))
        throw new RuntimeException('payment_credit_merchant_proof_mismatch');
    $verify=$method['proofs'][$basis]??null;
    if ($verify===null) throw new InvalidArgumentException('payment_credit_basis_invalid');
    $tid=$verify($db,$pay,$attempt,$proof,$actor,$primary);
    if ($tid!==null) {
        if (!is_int($tid) || $tid<=0)
            throw new RuntimeException('payment_credit_proof_result_invalid');
        // Enforce the common receipt/evidence binding even for a future
        // adapter. Provider-specific proof semantics remain in its verifier.
        $q=$db->prepare('SELECT attempt_id,provider FROM payment_transactions WHERE id=? FOR UPDATE');
        if (!$q) throw new RuntimeException('payment_credit_proof_binding_prepare');
        $q->bind_param('i',$tid);
        if (!$q->execute() || !($rows=$q->get_result()))
            throw new RuntimeException('payment_credit_proof_binding_read');
        $evidence=$rows->fetch_assoc();$q->close();
        if (!$evidence || (int)$evidence['attempt_id']!==$aid ||
            $evidence['provider']!==$attempt['provider'])
            throw new RuntimeException('payment_credit_proof_binding_conflict');
    }
    if ($existing!==null) return $existing;
    $entry=achbotWalletApplyLocked($db,$userId,'payment_credit',(string)$payId,0,$amount,$actor);
    if ($entry['replayed']) throw new RuntimeException('payment_credit_orphan_entry');
    $id=$entry['id'];
    $q=$db->prepare('UPDATE payment_attempts SET wallet_credit_entry_id=?,
        wallet_credit_basis=?,wallet_credit_transaction_id=?
        WHERE id=? AND wallet_credit_entry_id IS NULL AND wallet_invoice_sha256=?');
    if (!$q) throw new RuntimeException('payment_credit_link_prepare');
    $q->bind_param('isiis',$id,$basis,$tid,$aid,$pin);
    if (!$q->execute() || $q->affected_rows!==1)
        throw new RuntimeException('payment_credit_link_write');
    $q->close();
    return $entry;
}

/** Read the original committed credit, not the current Wallet balance or
 * a later provider callback. Same owner -> invoice -> journal lock order. */
function achbotReceiptCreditEvidenceLocked(mysqli $db,int $payId,int $userId): array {
    [$pay,$attempt]=achbotCreditInvoiceLocked($db,$payId,$userId);
    $owner=achbotWalletLockOwnerLocked($db,$userId);
    $pin=$attempt['wallet_invoice_sha256'];
    $method=achbotCreditMethodDefinition((string)$attempt['provider']);
    $basis=(string)($attempt['wallet_credit_basis']??'');
    $entry=achbotWalletFindEntryLocked($db,$owner['id'],$userId,
        'payment_credit',(string)$payId,0,(int)$pay['price']);
    if ($pay['state']!=='paid' || $attempt['stage']!=='done' ||
        !$method || !isset($method['proofs'][$basis]) || !is_string($pin) ||
        !hash_equals($pin,achbotCreditInvoiceFingerprint($pay,$attempt)) ||
        !$entry || $entry['id']!==(int)$attempt['wallet_credit_entry_id'] ||
        !in_array($attempt['wallet_funding_result']??null,
            ['funded','wallet_only','already_funded','request_invalidated','capacity_unavailable','insufficient_funds'],true) ||
        $entry['after']-$entry['before']!==(int)$pay['price'] ||
        ($basis==='manual')!==($entry['actor']>0))
        throw new RuntimeException('payment_credit_report_evidence_conflict');
    $tid=$attempt['wallet_credit_transaction_id'];
    if ($tid!==null) {
        $t=achbotProviderRow($db,'SELECT attempt_id,provider FROM payment_transactions WHERE id=? FOR UPDATE',
            'i',[(int)$tid]);
        if (!$t || (int)$t['attempt_id']!==(int)$attempt['id'] || $t['provider']!==$attempt['provider'])
            throw new RuntimeException('payment_credit_report_transaction_conflict');
    }
    $fingerprint=hash('sha256',json_encode([
        'invoice'=>$pin,'entry'=>$entry['id'],'owner_row'=>$owner['id'],
        'before'=>$entry['before'],'after'=>$entry['after'],'actor'=>$entry['actor'],
        'basis'=>$basis,'funding'=>$attempt['wallet_funding_result'],
        'transaction'=>$tid===null?null:(int)$tid,
    ],JSON_THROW_ON_ERROR));
    return ['pay'=>$pay,'attempt'=>$attempt,'entry'=>$entry,'fingerprint'=>$fingerprint];
}

/** The original receipt that actually admitted the operation, not a late
 * receipt for a service already paid by Wallet or an unrelated top-up. */
function achbotServiceFundingReceiptLocked(mysqli $db,array $op): ?array {
    $id=(int)$op['id'];$user=(int)$op['userid'];
    $s=achbotProviderStatement($db,"SELECT pay_row_id FROM payment_attempts
        WHERE service_operation_id=? AND wallet_funding_result='funded'
        ORDER BY id LIMIT 2 FOR UPDATE",'i',[$id]);
    $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
    if (!$rows) return null;
    if (count($rows)!==1 || (int)$op['price_toman']<=0)
        throw new RuntimeException('service_funding_receipt_conflict');
    $e=achbotReceiptCreditEvidenceLocked($db,(int)$rows[0]['pay_row_id'],$user);
    if ((int)$e['attempt']['service_operation_id']!==$id ||
        $e['attempt']['wallet_funding_result']!=='funded' ||
        (int)$e['pay']['price']!==(int)$op['price_toman'])
        throw new RuntimeException('service_funding_receipt_evidence_conflict');
    return $e;
}
