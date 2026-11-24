<?php
declare(strict_types=1);

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__.'/../Wallet/Ledger.php';
require_once __DIR__.'/../Admin/Access.php';
require_once __DIR__.'/PaymentEvidence.php';
require_once __DIR__.'/ManualReceiptIntent.php';
require_once __DIR__.'/GatewayClient.php';

/**
 * Wallet-first money identity deliberately excludes mutable service state
 * and the NOWPayments child ID. Actual amount and destination/issued quote
 * remain pinned. The caller is an authenticated issuer/verifier, NEVER raw
 * browser/Telegram input. No provider HTTP or service execution under SQL.
 */
function achbotCreditInvoiceFingerprint(array $pay,array $attempt): string {
    $payId=(int)($pay['id']??0);
    $provider=(string)($attempt['provider']??'');
    $hosted=in_array($provider,['nowpayments','nextpay','zarinpal'],true);
    if ($payId<=0 || (int)($attempt['id']??0)<=0 ||
        (int)($attempt['pay_row_id']??0)!==$payId ||
        (int)($pay['user_id']??0)<=0 || (int)($pay['price']??0)<=0 ||
        (int)($attempt['local_amount_toman']??0)!==(int)$pay['price'] ||
        (string)($pay['hash_id']??'')==='' ||
        (string)($attempt['order_hash']??'')!==(string)$pay['hash_id'])
        throw new RuntimeException('payment_credit_invoice_identity');
    if ($hosted) {
        $reference=(string)($attempt['provider_reference']??'');
        if ($reference==='' || (string)($pay['payid']??'')!==$reference)
            throw new RuntimeException('payment_credit_hosted_reference');
        if ($provider!=='nowpayments' &&
            (strtolower((string)$attempt['quote_currency'])!=='toman' ||
             !achbotNpDecimalAtLeast($attempt['quote_amount'],(string)$pay['price']) ||
             !achbotNpDecimalAtLeast((string)$pay['price'],$attempt['quote_amount'])))
            throw new RuntimeException('payment_credit_hosted_quote');
        if ($provider==='nowpayments' &&
            (strtolower((string)$attempt['quote_currency'])!=='usd' ||
             !achbotNpDecimalAtLeast($attempt['quote_amount'],'0.00000001')))
            throw new RuntimeException('payment_credit_hosted_quote');
    } elseif (!in_array($provider,['bank_transfer','gift_card','manual_usdt','manual_trx'],true) ||
        (string)($pay['payid']??'')!=='' ||
        achbotManualIntentDecode((string)($attempt['checkout_url']??''),$provider)===null) {
        throw new RuntimeException('payment_credit_manual_identity');
    }
    // Existing quote fingerprint includes immutable manual intent (except
    // submitted receipt_file_id), merchant reference and exact quote.
    // A later authenticated child does not change the issued invoice.
    unset($attempt['payment_id']);
    return hash('sha256',json_encode([
        $payId,(int)$pay['user_id'],(string)$pay['hash_id'],(int)$pay['price'],
        achbotPaymentEvidenceQuoteFingerprint($attempt),
        hash('sha256',(string)($attempt['evidence_profile']??'')),
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
        ($attempt['review_archived_at']??null)!==null ||
        ($pay['approved_by']??null)!==null)
        throw new RuntimeException('payment_credit_issue_not_new');
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

/**
 * Same existing explicit timer policy, re-read under the financial lock.
 * Missing/ambiguous/malformed settings are not evidence of payment.
 * No service/catalog dependency and no API-pinned timer fallback.
 */
function achbotCreditBankTimerEligibleLocked(mysqli $db,array $pay,array $attempt): bool {
    if ($attempt['provider']!=='bank_transfer' || $attempt['evidence_profile']!==null ||
        $pay['state']!=='have_sent') return false;
    // Match PaymentLifecycle's lock discipline: no unindexed locking
    // scan over unrelated Marzban group settings. The existing writer
    // mutex preserves the unique settings row's primary-key identity.
    $q=$db->query("SELECT id FROM setting WHERE type='BOT_STATES' ORDER BY id LIMIT 2");
    if (!$q) throw new RuntimeException('payment_credit_timer_policy_locate');
    $rows=$q->fetch_all(MYSQLI_ASSOC);$q->free();
    if (count($rows)!==1) return false;
    $settingId=(int)$rows[0]['id'];
    $s=$db->prepare('SELECT type,value FROM setting WHERE id=? LOCK IN SHARE MODE');
    if (!$s) throw new RuntimeException('payment_credit_timer_policy_prepare');
    $s->bind_param('i',$settingId);
    if (!$s->execute() || !($rows=$s->get_result()))
        throw new RuntimeException('payment_credit_timer_policy_read');
    $row=$rows->fetch_assoc();$s->close();
    if (!$row || $row['type']!=='BOT_STATES') return false;
    $states=json_decode((string)$row['value'],true);
    if (!is_array($states) || ($states['cartToCartAutoAcceptState']??null)!=='on') return false;
    $minutes=$states['cartToCartAutoAcceptTime']??10;
    $audience=(string)($states['cartToCartAutoAcceptType']??2);
    if (!is_numeric($minutes) || !is_finite((float)$minutes) || $minutes<0 ||
        !in_array($audience,['0','1','2'],true)) return false;
    // Retain the existing configured timer arithmetic and audience.
    $due=strtotime('-'.(string)$minutes.' minutes');
    if ($due===false || (int)$pay['request_date']>$due) return false;
    $id=(int)$pay['user_id'];
    $q=$db->prepare('SELECT is_agent FROM users FORCE INDEX (idx_users_userid_v1) WHERE userid=? LIMIT 2 FOR UPDATE');
    if (!$q) throw new RuntimeException('payment_credit_timer_owner_prepare');
    $q->bind_param('i',$id);
    if (!$q->execute() || !($rows=$q->get_result()))
        throw new RuntimeException('payment_credit_timer_owner_read');
    $users=$rows->fetch_all(MYSQLI_ASSOC);$q->close();
    if (count($users)!==1) throw new RuntimeException('payment_credit_timer_owner_ambiguous');
    $agent=(int)$users[0]['is_agent']===1;
    return $audience==='2' || ($audience==='0' && $agent) || ($audience==='1' && !$agent);
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
    $amount=(int)$pay['price'];$provider=(string)$attempt['provider'];
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
        if (!in_array($basis,['nowpayments','nextpay','zarinpal'],true) || $proof===[])
            return $existing;
        // A verified additional child is retained as evidence, not a second
        // credit. A conflicting later response never reverses this journal.
    }
    $tid=null;
    switch ($basis) {
        case 'api':
            if ($actor!==0 || !in_array($provider,['bank_transfer','gift_card'],true) ||
                !achbotPaymentEvidenceValidateProofLocked($db,$pay,$proof,true))
                throw new RuntimeException('payment_credit_api_proof');
            $tid=(int)$proof['transaction_id'];
            break;
        case 'manual':
        case 'bank_timer':
            if (!in_array($provider,['bank_transfer','gift_card','manual_usdt','manual_trx'],true) ||
                !in_array($pay['state'],['have_sent','service_superseded'],true) ||
                $attempt['stage']!=='review' ||
                ($attempt['review_archived_at']??null)!==null ||
                (int)($pay['message_id']??0)<=0 || (int)($pay['chat_id']??0)===0)
                throw new RuntimeException('payment_credit_manual_receipt');
            $intent=achbotManualIntentDecode((string)$attempt['checkout_url'],$provider);
            if (empty($intent['receipt_file_id']) &&
                !achbotPaymentEvidenceHasTypedReviewLocked($db,$pay))
                throw new RuntimeException('payment_credit_manual_evidence');
            if ($basis==='manual') {
                if ($actor<=0 || $primary<=0 || !achbotAdminCanLocked($db,$actor,$primary,'payments'))
                    throw new RuntimeException('payment_credit_manual_authority');
                // An authorized manual decision and a later authenticated
                // proof of this SAME invoice share the one monetary source.
                // Do not impose a new blanket ban on existing manual approval.
            } else {
                if ($actor!==0 || !achbotCreditBankTimerEligibleLocked($db,$pay,$attempt))
                    throw new RuntimeException('payment_credit_timer_policy');
            }
            break;
        case 'nowpayments':
            if ($actor!==0 || $provider!=='nowpayments' ||
                !achbotNpValidFinalAmount($attempt,$proof) ||
                (string)($proof['invoice_id']??'')!==(string)$attempt['provider_reference'] ||
                (string)($proof['order_id']??'')!==(string)$attempt['order_hash'] ||
                achbotHasUnscopedPaymentReferenceCollision($db,(string)$attempt['provider_reference'],$payId))
                throw new RuntimeException('payment_credit_nowpayments_proof');
            $tid=achbotCreditHostedEvidenceLocked($db,$attempt,(string)($proof['payment_id']??''));
            break;
        case 'nextpay':
        case 'zarinpal':
            // Only the actual authenticated verification adapter supplies
            // its requested reference/amount and exact protocol status.
            $status=$proof['status']??null;
            $verified=$basis==='nextpay'
                ? achbotVerifiedGatewayOutcome('nextpay',$status)==='verified'
                : ($status===100 || $status==='100');
            $reference=(string)($proof['reference']??'');
            if ($actor!==0 || $provider!==$basis || !$verified ||
                $reference!==(string)$attempt['provider_reference'] ||
                ($proof['amount']??null)!==$amount ||
                achbotHasUnscopedPaymentReferenceCollision($db,$reference,$payId))
                throw new RuntimeException('payment_credit_hosted_proof');
            $tid=achbotCreditHostedEvidenceLocked($db,$attempt,$reference);
            break;
        default: throw new InvalidArgumentException('payment_credit_basis_invalid');
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
