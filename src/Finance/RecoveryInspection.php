<?php
/**
 * AChBot #26 — owner-only, read-only payment attention and BUY_SUB effect inspection.
 *
 * This source does not send Telegram, charge/refund, mutate payment/order/
 * service_effects, issue remote CREATE/PUT/DELETE, or decide financial policy.
 * An authenticated GET match is only a point-in-time corroboration, never
 * universal idempotence, proof of merchant funds or permission to replay.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG

require_once __DIR__.'/ServiceEffects.php';

require_once __DIR__.'/PaymentLifecycle.php';
// The inbox can be the first route loaded in a bot update; API proof
// visibility must not depend on an earlier checkout request.
require_once __DIR__.'/ApiPayments.php';

/** Current unresolved CREATE evidence only; absence never condemns paid history. */
function achbotRecoveryCreateAttentionSql(mysqli $db, string $p='p'): string {
    $claims=achbotPayUxTable($db,'payment_attempts')
        ? "EXISTS (SELECT 1 FROM payment_attempts a WHERE a.pay_row_id=$p.id
            AND a.stage IN ('review','checking','fulfilling'))" : '0';
    $effects=achbotPayUxTable($db,'service_effects')
        ? "EXISTS (SELECT 1 FROM service_effects e WHERE e.pay_row_id=$p.id
            AND ((e.effect_kind='create' AND e.state IN ('dispatching','verified'))
              OR (e.effect_kind IN ('wallet_hold','manual_hold') AND e.state='reserved')))" : '0';
    return "$p.type='BUY_SUB' AND ($p.state IN ('processing','processing_wallet')
        OR ($p.state='paid' AND (($claims) OR ($effects))))";
}

/** Bounded newest-to-oldest pending BUY_SUB cases, excluding completed history. */
function achbotRecoveryPendingCases(mysqli $db, int $before=0): array {
    if ($before<0) throw new InvalidArgumentException('recovery_invalid_cursor');
    $scope=achbotRecoveryCreateAttentionSql($db);
    $stmt=$db->prepare("SELECT p.id,p.user_id,p.price,p.agent_count,p.state
        FROM pays p WHERE ($scope) AND (?=0 OR p.id<?)
        ORDER BY p.id DESC LIMIT 13");
    if (!$stmt) throw new RuntimeException('recovery_list_prepare_failed');
    $stmt->bind_param('ii',$before,$before);
    if (!$stmt->execute()) throw new RuntimeException('recovery_list_failed');
    $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
    return $rows;
}

/** Financial review only: payment not yet finalized, with positive funds
 * evidence or an original customer receipt awaiting human verification.
 * A paid but undelivered account is NOT an unapproved payment.
 */
function achbotRecoveryAttentionSql(mysqli $db): string {
    $ledger='0';
    if (achbotPayUxTable($db,'payment_attempts')) {
        $journal=achbotPayUxTable($db,'payment_review_decisions')
            ? "OR (a.stage='fulfilling'
                AND a.provider_status IN ('operator_verified_finished','operator_attested')
                AND EXISTS (SELECT 1 FROM payment_review_decisions d
                    WHERE d.attempt_id=a.id
                      AND d.state IN ('dispatching','unknown')))"
            : '';
        $np=achbotPayUxTable($db,'payment_transactions')
            ? "a.provider='nowpayments'
                AND ((a.stage='review'
                    AND EXISTS (SELECT 1 FROM payment_transactions t
                        WHERE t.attempt_id=a.id AND t.provider='nowpayments'
                        AND t.observed_status IN
                          ('confirmed','sending','partially_paid','finished','refunded')))
                    $journal)"
            : '0';
        // An exact hosted VERIFY uncertainty is a *human-review claim*, not
        // provider proof of funds. Paid native outcomes live in service recovery.
        $hosted="a.provider IN ('nextpay','zarinpal')
            AND ((a.stage='review' AND a.provider_status IN
                     ('verification_uncertain','fulfillment_started'))
              OR (a.stage='fulfilling' AND a.provider_status='operator_attested'
                  AND EXISTS (SELECT 1 FROM payment_review_decisions d
                      WHERE d.pay_row_id=p.id AND d.attempt_id=a.id
                        AND d.decision='approve'
                        AND d.state IN ('dispatching','unknown')))
              OR (a.stage='review' AND a.provider_status='operator_fulfillment_unconfirmed'
                  AND EXISTS (SELECT 1 FROM payment_review_decisions d
                      WHERE d.pay_row_id=p.id AND d.attempt_id=a.id
                        AND d.decision='approve' AND d.state='unknown')))";
        if (!achbotPayUxTable($db,'payment_review_decisions'))
            $hosted="a.provider IN ('nextpay','zarinpal') AND a.stage='review'
                AND a.provider_status IN ('verification_uncertain','fulfillment_started')";
        // A saved human receipt is a CLAIM of payment, not provider proof.
        // A provider status alone is not authority to approve a hosted charge.
        $ledger="EXISTS (SELECT 1 FROM payment_attempts a
          WHERE a.pay_row_id=p.id AND (
            ($np) OR ($hosted) OR (
              a.provider IN ('bank_transfer','gift_card','manual_usdt','manual_trx')
              AND a.stage='review' AND p.state='have_sent'
              AND p.message_id>0 AND p.chat_id<>0
            )
          ))";
    }
    $api='0';
    if (function_exists('achbotApiPaymentReady') &&
        achbotApiPaymentReady($db)) {
        $api="EXISTS (SELECT 1 FROM payment_attempts a
          WHERE a.pay_row_id=p.id AND a.evidence_profile IS NOT NULL
          AND (a.evidence_state='proven' OR EXISTS
            (SELECT 1 FROM payment_transactions t
              WHERE t.attempt_id=a.id AND t.evidence_state='proven')))";
    }
    return "(p.type IN ('BUY_SUB','RENEW_ACCOUNT','RENEW_SCONFIG','INCREASE_WALLET')
      OR p.type LIKE 'INCREASE_DAY\_%' OR p.type LIKE 'INCREASE_VOLUME\_%')
      AND p.state IN ('pending','send','have_sent')
      AND (($ledger) OR ($api))";
}

/**
 * Authenticated secondary merchant funds are an independent FINANCIAL
 * exception, even when the original pay is already paid or an operator's
 * once-only fulfillment is running. Never reopen the original pay here.
 * No schema or alerts: payment_transactions is the authoritative child ledger.
 */
function achbotRecoveryAdditionalFundsSql(mysqli $db,string $p='p'): string {
    if (!achbotPayUxTable($db,'payment_attempts') ||
        !achbotPayUxTable($db,'payment_transactions')) return '0';
    $secondary="EXISTS (SELECT 1 FROM payment_attempts a
        JOIN payment_transactions t ON t.attempt_id=a.id
        WHERE a.pay_row_id=$p.id AND a.provider='nowpayments'
          AND t.provider='nowpayments' AND a.payment_id IS NOT NULL
          AND BINARY t.payment_id<>BINARY a.payment_id
          AND t.observed_status IN
              ('confirming','confirmed','sending','partially_paid','finished','refunded'))";
    // A human rejection closes the BUSINESS order, not the merchant refund.
    // Keep a signed funded PRIMARY visible as an independent exception until
    // evidence actually shows refunded. No approval/rejection is offered here.
    $primary=achbotPayUxTable($db,'payment_review_decisions')
        ? "EXISTS (SELECT 1 FROM payment_attempts a
           JOIN payment_review_decisions d ON d.pay_row_id=a.pay_row_id
             AND d.attempt_id=a.id AND d.decision='reject'
             AND d.provider_status='operator_rejected'
             AND d.state='completed'
           JOIN payment_transactions t ON t.attempt_id=a.id
             AND t.provider='nowpayments' AND BINARY t.payment_id=BINARY a.payment_id
           WHERE a.pay_row_id=$p.id AND a.provider='nowpayments'
             AND a.provider_status='operator_rejected'
             AND $p.state='declined'
             AND t.observed_status IN
                ('confirming','confirmed','sending','partially_paid','finished'))"
        : '0';
    return "(($secondary) OR ($primary))";
}
function achbotRecoveryAdditionalFundsCases(mysqli $db,int $before=0): array {
    if ($before<0) throw new InvalidArgumentException('additional_funds_cursor');
    $scope=achbotRecoveryAdditionalFundsSql($db);
    $q=$db->prepare("SELECT p.id,p.user_id,p.price,p.type,p.state
        FROM pays p WHERE ($scope) AND (?=0 OR p.id<?)
        ORDER BY p.id DESC LIMIT 13");
    $q->bind_param('ii',$before,$before);$q->execute();
    $rows=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
    return $rows;
}
function achbotRecoveryAdditionalFundsCase(mysqli $db,int $payId): ?array {
    if ($payId<=0) return null;
    $scope=achbotRecoveryAdditionalFundsSql($db);
    $q=$db->prepare("SELECT p.id FROM pays p WHERE p.id=? AND ($scope) LIMIT 1");
    $q->bind_param('i',$payId);$q->execute();
    $exists=$q->get_result()->num_rows===1;$q->close();
    return $exists?achbotRecoveryAttentionCase($db,$payId):null;
}

/** Display-only proof of a safe original API continuation; settlement
 * repeats all immutable evidence and native-effect checks under its locks.
 * A processing service belongs to technical recovery, not financial review.
 */
function achbotRecoveryApiCanResume(mysqli $db,array $pay): bool {
    $provider=(string)($pay['attempt']['provider']??'');
    $state=(string)($pay['state']??'');
    $id=(int)($pay['id']??0);
    if ($id<=0 || !in_array($provider,['bank_transfer','gift_card'],true) ||
        !in_array($state,['pending','have_sent','processing'],true) ||
        ($state==='processing' && (int)($pay['approved_by']??-1)!==0)) return false;
    $proof=achbotPaymentEvidenceGetProof($db,$id);
    if (($proof['mode']??null)!=='settle') return false;
    if ($state!=='processing' || (string)($pay['type']??'')==='BUY_SUB') return true;
    $attempt=achbotApiPaymentAttempt($db,$id);
    return in_array((string)($attempt['evidence_fulfillment_state']??''),
        ['reserved','verified'],true);
}

/** Separate paid/held SERVICE recovery queue, never a second financial inbox.
 * A successful transaction with unresolved remote CREATE/renewal may still
 * require an owner, but must not be offered a second money approval.
 */
function achbotRecoveryTechnicalSql(mysqli $db): string {
    $renewals=achbotPayUxTable($db,'automatic_renewal_attempts')
        ? "EXISTS (SELECT 1 FROM automatic_renewal_attempts r
            WHERE r.pay_row_id=p.id
            AND r.stage IN ('reserved','remote_started','review'))" : '0';
    $effects=achbotPayUxTable($db,'service_effects')
        ? "EXISTS (SELECT 1 FROM service_effects e
            WHERE e.pay_row_id=p.id
            AND ((e.effect_kind='create'
                 AND e.state IN ('dispatching','verified')
                 AND e.order_id IS NULL)
              OR (e.effect_kind IN ('wallet_hold','manual_hold')
                 AND e.state='reserved'
                 AND p.state IN ('processing','processing_wallet'))))" : '0';
    $hosted=achbotPayUxTable($db,'payment_attempts')
        ? "EXISTS (SELECT 1 FROM payment_attempts a
            WHERE a.pay_row_id=p.id
              AND a.stage='review'
              AND a.provider IN ('nowpayments','nextpay','zarinpal')
              AND a.provider_status IN
                 ('fulfillment_started','fulfillment_unconfirmed',
                  'fulfillment_failed','fulfillment_needs_review',
                  'operator_fulfillment_unconfirmed')
              AND p.state='paid')" : '0';
    // Once-local approved PasarGuard edits can retain PENDING or UNKNOWN
    // sealed notices. Display them as delivery cases, never unpaid funds.
    $editNotices=achbotPayUxTable($db,'payment_followup_intents')
        ? "EXISTS (SELECT 1 FROM payment_followup_intents f
             WHERE f.pay_row_id=p.id AND f.stage='approved_edit'
               AND f.state IN ('pending','dispatching','unknown'))" : '0';
    // These nonterminal processing states are themselves an incident if
    // the expected journal is missing. Surface for read-only diagnosis,
    // never silently drop a potentially debited wallet or remote CREATE.
    return "(p.state IN ('processing','processing_wallet')
        OR (p.state='paid' AND (($renewals) OR ($effects) OR ($hosted)))
        OR (p.state='approved' AND ($editNotices)))";
}

/** Only offer the ORIGINAL approval callback when its original manual BUY_SUB
 * can pass the source admission gates. The callback independently rechecks
 * everything under pay/attempt locks; this read-only hint grants no effect.
 */
function achbotRecoveryCanResumeManualPurchase(mysqli $db,array $pay): bool {
    if (($pay['type']??null)!=='BUY_SUB' || ($pay['state']??null)!=='processing' ||
        (int)($pay['approved_by']??0)<=0 || (int)($pay['day']??0)!==0 ||
        (int)($pay['volume']??0)!==0 || (int)($pay['message_id']??0)<=0 ||
        (int)($pay['chat_id']??0)===0 || (string)($pay['payid']??'')!=='' ||
        preg_match('/^[A-Za-z0-9_-]{1,50}$/D',(string)($pay['hash_id']??''))!==1)
        return false;
    $attempt=$pay['attempt']??null;
    if (!is_array($attempt) || !achbotPayUxManualProvider((string)($attempt['provider']??'')) ||
        (string)($attempt['stage']??'')!=='review' ||
        (int)($attempt['local_amount_toman']??0)!==(int)($pay['price']??0))
        return false;
    require_once __DIR__.'/DeliveryLedger.php';
    require_once __DIR__.'/FollowupOutbox.php';
    require_once __DIR__.'/ReferralReward.php';
    if (!achbotDeliverySchemaReady($db) || !achbotFollowupSchemaReady($db) ||
        !achbotReferralSchemaReady($db)) return false;
    try {
        achbotEffectRequireUniqueLine($db);
        $id=(int)$pay['id'];
        $stmt=$db->prepare("SELECT state,reserved_count FROM service_effects
            WHERE pay_row_id=? AND effect_kind='manual_hold' AND ordinal=0 LIMIT 1");
        $stmt->bind_param('i',$id);
        $stmt->execute();$hold=$stmt->get_result()->fetch_assoc();$stmt->close();
        return $hold!==null && in_array((string)$hold['state'],['reserved','consumed'],true) &&
            (int)$hold['reserved_count']===max(1,(int)($pay['agent_count']??0));
    } catch (Throwable $error) { return false; }
}

/** A bank-Cron payment already claimed as processing has no second scan
 * (the normal scanner only admits have_sent). This is an operator-UI *hint*,
 * not a new financial decision or an approval of a pending bank invoice.
 * The original per-ordinal provisioner repeats all of these checks before
 * any native operation, independently of this read-only preview.
 */
function achbotRecoveryCanResumeBankPurchase(mysqli $db,array $pay): bool {
    if (($pay['type']??null)!=='BUY_SUB' || ($pay['state']??null)!=='processing' ||
        (int)($pay['approved_by']??0)!==0 || (int)($pay['user_id']??0)<=0 ||
        (string)($pay['payid']??'')!=='' ||
        preg_match('/^[A-Za-z0-9_-]{1,50}$/D',(string)($pay['hash_id']??''))!==1)
        return false;
    $attempt=$pay['attempt']??null;
    // Cron claims the payment without changing the 003 stage from 'pending'.
    // The original 009 bank_attempt_sha256 proves the 003 receipt identity;
    // stage is intentionally not part of that immutable fingerprint.
    if (!is_array($attempt) || ($attempt['provider']??null)!=='bank_transfer')
        return false;
    require_once __DIR__.'/ReceiptHelpers.php';
    require_once __DIR__.'/DeliveryLedger.php';
    if (!achbotDeliverySchemaReady($db)) return false;
    try {
        achbotEffectRequireUniqueLine($db);
        $id=(int)$pay['id'];
        $q=$db->prepare('SELECT * FROM payment_attempts WHERE pay_row_id=? LIMIT 1');
        $q->bind_param('i',$id);$q->execute();$original=$q->get_result()->fetch_assoc();$q->close();
        if (!$original || ($original['evidence_profile']??null)!==null)
            return false; // API and manual modes have their own owner/ledger.
        $q=$db->prepare('SELECT * FROM bank_buy_invoice_claims WHERE pay_row_id=? LIMIT 1');
        $q->bind_param('i',$id);$q->execute();$claim=$q->get_result()->fetch_assoc();$q->close();
        if (!achbotBankBuyClaimMatches($pay,$claim) ||
            !achbotBankBuyAttemptMatches($pay,$original,$claim)) return false;
        $q=$db->prepare("SELECT effect_kind,state,ordinal,reserved_count,order_id
            FROM service_effects WHERE pay_row_id=? AND (
                effect_kind IN ('manual_hold','wallet_hold','create')) ORDER BY ordinal");
        $q->bind_param('i',$id);$q->execute();$effects=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
        $count=(int)$claim['account_count'];$next=1;
        foreach ($effects as $effect) {
            if ($effect['effect_kind']!=='create' ||
                (int)$effect['ordinal']!==$next || $next>$count ||
                !in_array((string)$effect['state'],['dispatching','verified'],true) ||
                $effect['order_id']!==null ||
                (int)$effect['reserved_count']!==($next===1?$count:0)) return false;
            $next++;
        }
        return true; // No CREATE line yet is an original never-started batch.
    } catch (Throwable $error) { return false; }
}

function achbotRecoveryTechnicalCases(mysqli $db,int $before=0): array {
    if ($before<0) throw new InvalidArgumentException('technical_recovery_cursor');
    $scope=achbotRecoveryTechnicalSql($db);
    $q=$db->prepare("SELECT p.id,p.user_id,p.price,p.type,p.state
        FROM pays p WHERE ($scope) AND (?=0 OR p.id<?)
        ORDER BY p.id DESC LIMIT 13");
    $q->bind_param('ii',$before,$before);$q->execute();
    $rows=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
    return $rows;
}

function achbotRecoveryTechnicalCase(mysqli $db,int $id): ?array {
    if ($id<=0) return null;
    $scope=achbotRecoveryTechnicalSql($db);
    $q=$db->prepare("SELECT p.id FROM pays p WHERE p.id=? AND ($scope) LIMIT 1");
    $q->bind_param('i',$id);$q->execute();
    $eligible=$q->get_result()->num_rows===1;$q->close();
    return $eligible?achbotRecoveryAttentionCase($db,$id):null;
}

function achbotRecoveryAttentionCases(mysqli $db, int $before=0): array {
    if ($before<0) throw new InvalidArgumentException('recovery_invalid_cursor');
    $scope=achbotRecoveryAttentionSql($db);
    $stmt=$db->prepare("SELECT p.id,p.user_id,p.price,p.type,p.state FROM pays p
        WHERE ($scope) AND (?=0 OR p.id<?) ORDER BY p.id DESC LIMIT 13");
    $stmt->bind_param('ii',$before,$before);
    if (!$stmt->execute()) throw new RuntimeException('recovery_attention_list_failed');
    $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();return $rows;
}

/** Any existing pay can be read by the separately authorized owner by ID.
 * This lookup does not turn a completed/historical record into an open case.
 */
function achbotRecoveryAttentionCase(mysqli $db, int $payId): ?array {
    $stmt=$db->prepare('SELECT * FROM pays WHERE id=? LIMIT 1');
    $stmt->bind_param('i',$payId);$stmt->execute();
    $pay=$stmt->get_result()->fetch_assoc();$stmt->close();
    if (!$pay) return null;
    $attempt=null;
    if (achbotPayUxTable($db,'payment_attempts')) {
        $stmt=$db->prepare('SELECT id,provider,stage,provider_status,payment_id,provider_reference,
                quote_amount,quote_currency,local_amount_toman
            FROM payment_attempts WHERE pay_row_id=? LIMIT 1');
        $stmt->bind_param('i',$payId);$stmt->execute();
        $attempt=$stmt->get_result()->fetch_assoc()?:null;$stmt->close();
    }
    $pay['attempt']=$attempt;
    $pay['transactions']=[];
    if ($attempt && achbotPayUxTable($db,'payment_transactions')) {
        $stmt=$db->prepare('SELECT payment_id,observed_status FROM payment_transactions
            WHERE attempt_id=? AND provider=? ORDER BY id DESC LIMIT 6');
        $aid=(int)$attempt['id'];$provider=(string)$attempt['provider'];
        $stmt->bind_param('is',$aid,$provider);$stmt->execute();
        $pay['transactions']=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
    }
    $pay['renewal']=null;
    if ($pay['type']==='RENEW_ACCOUNT' && achbotPayUxTable($db,'automatic_renewal_attempts')) {
        $stmt=$db->prepare('SELECT order_id,user_id,cycle_expire,new_expire,stage,reason_code
            FROM automatic_renewal_attempts WHERE pay_row_id=? LIMIT 1');
        $stmt->bind_param('i',$payId);$stmt->execute();
        $pay['renewal']=$stmt->get_result()->fetch_assoc()?:null;$stmt->close();
    }
    // Mutable account values are context only, never proof of this payment's effect.
    $pay['target_order']=null;
    if ((int)($pay['order_id']??0)>0 && $pay['type']!=='INCREASE_WALLET') {
        $stmt=$db->prepare('SELECT id,expire_date FROM orders_list WHERE id=? AND userid=? LIMIT 1');
        $oid=(int)$pay['order_id'];$uid=(int)$pay['user_id'];
        $stmt->bind_param('ii',$oid,$uid);$stmt->execute();
        $pay['target_order']=$stmt->get_result()->fetch_assoc()?:null;$stmt->close();
    }
    return $pay;
}

/** Subject-specific evidence; never infer a payment effect from today's balance/account. */
function achbotRecoverySubjectText(array $pay): string {
    $safe=static fn($v)=>htmlspecialchars(substr((string)$v,0,128),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $out='';$attempt=$pay['attempt']??null;
    if ($attempt) {
        $out.=\achbotMessage('payment_review.recovery_inspection.recovery_subject_text.out.67b61bb75d').$safe($attempt['quote_amount'])." ".$safe($attempt['quote_currency'])."</code>\n";
        foreach (array_slice($pay['transactions']??[],0,6) as $t)
            $out.=\achbotMessage('payment_review.recovery_inspection.recovery_subject_text.out.ba84960b42').$safe($t['payment_id']).\achbotMessage('payment_review.recovery_inspection.recovery_subject_text.text.4f7e040914').$safe($t['observed_status'])."</code>\n";
        $out.=\achbotMessage('payment_review.recovery_inspection.recovery_subject_text.out.1f27f2ca2e');
    }
    if ($pay['type']==='INCREASE_WALLET') {
        return $out.\achbotMessage('payment_review.recovery_inspection.recovery_subject_text.text.1cb692e6c9');
    }
    if ($pay['type']==='BUY_SUB') return $out;
    $kind=$pay['type']==='RENEW_ACCOUNT'?\achbotMessage('payment_review.recovery_inspection.recovery_subject_text.text.3225fab3cd'):
        (str_starts_with((string)$pay['type'],'INCREASE_DAY_')?\achbotMessage('payment_review.recovery_inspection.recovery_subject_text.text.41beb22aa1'):\achbotMessage('payment_review.recovery_inspection.recovery_subject_text.text.4bbbd988ad'));
    $out.=\achbotMessage('payment_review.recovery_inspection.recovery_subject_text.out.460e830650').$kind.\achbotMessage('payment_review.recovery_inspection.recovery_subject_text.text.679a7d0587').(int)($pay['order_id']??0).".\n";
    $r=$pay['renewal']??null;
    if ($r && (int)$r['user_id']===(int)$pay['user_id'] && (int)$r['order_id']===(int)($pay['order_id']??0)) {
        $out.=\achbotMessage('payment_review.recovery_inspection.recovery_subject_text.out.44c6ee7199').$safe($r['stage']).\achbotMessage('payment_review.recovery_inspection.recovery_subject_text.text.86754560fe').$safe($r['reason_code']??'')."</code>\n".
            \achbotMessage('payment_review.recovery_inspection.recovery_subject_text.text.a75318378a').$safe($r['cycle_expire']).\achbotMessage('payment_review.recovery_inspection.recovery_subject_text.text.2048d071c8').$safe($r['new_expire'])."</code>\n";
    } else $out.=\achbotMessage('payment_review.recovery_inspection.recovery_subject_text.out.4a791e1065');
    if ($pay['target_order']??null)
        $out.=\achbotMessage('payment_review.recovery_inspection.recovery_subject_text.out.1cf809d536').$safe($pay['target_order']['expire_date']).\achbotMessage('payment_review.recovery_inspection.recovery_subject_text.text.9480810971');
    else $out.=\achbotMessage('payment_review.recovery_inspection.recovery_subject_text.out.708e0c6a1f');
    return $out;
}

/** Payment recording and fulfillment are independent, conservative facts. */
function achbotRecoveryEvidenceText(array $pay, ?array $attempt): string {
    $state=(string)($pay['state']??$pay['payment_state']??'');
    $stage=(string)($attempt['stage']??'');
    $status=(string)($attempt['provider_status']??'');
    $terminal=in_array($state,['paid','approved','auto_cart_approved','paid_with_wallet'],true);
    $funds=$terminal ? \achbotMessage('payment_review.recovery_inspection.recovery_evidence_text.text.b02573f9d0')
        : ($state==='processing_wallet' ? \achbotMessage('payment_review.recovery_inspection.recovery_evidence_text.text.7651d424f1')
        : ($state==='have_sent' ? \achbotMessage('payment_review.recovery_inspection.recovery_evidence_text.text.fbc388c114')
        : \achbotMessage('payment_review.recovery_inspection.recovery_evidence_text.text.b7b90e0fc2')));
    $effect=$stage==='done'
        ? ($status==='compensated' ? \achbotMessage('payment_review.recovery_inspection.recovery_evidence_text.text.49cdc62e8b')
           : \achbotMessage('payment_review.recovery_inspection.recovery_evidence_text.text.cd957f37f7'))
        : ($terminal && $attempt===null ? \achbotMessage('payment_review.recovery_inspection.recovery_evidence_text.text.e0191bbaea')
           : \achbotMessage('payment_review.recovery_inspection.recovery_evidence_text.text.97ebe49e23'));
    return \achbotMessage('payment_review.recovery_inspection.recovery_evidence_text.label.440766e324').$funds.\achbotMessage('payment_review.recovery_inspection.recovery_evidence_text.text.7fc33173f7').$effect."\n";
}

/**
 * Bounded per-payment ordinal page. Counts are LOCAL journal inventory only;
 * never use them to infer remote success, absence, settlement or capacity.
 */
function achbotRecoveryCase(mysqli $db, int $payId, int $afterOrdinal=0): ?array {
    if ($payId<=0 || $afterOrdinal<0)
        throw new InvalidArgumentException('recovery_invalid_subject');
    achbotEffectRequireUniqueLine($db);
    $stmt=$db->prepare("SELECT id,hash_id,user_id,type,price,agent_count,state,server_id,
            plan_id FROM pays
        WHERE id=? AND type='BUY_SUB' AND state IN ('processing','paid','processing_wallet')
        LIMIT 1");
    if (!$stmt) throw new RuntimeException('recovery_pay_prepare_failed');
    $stmt->bind_param('i',$payId);
    if (!$stmt->execute()) throw new RuntimeException('recovery_pay_read_failed');
    $pay=$stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$pay) return null;

    // Do not guess funds from a processing/paid label or a bank screenshot.
    $stmt=$db->prepare("SELECT provider,stage,provider_status FROM payment_attempts
        WHERE pay_row_id=? LIMIT 1");
    if (!$stmt) throw new RuntimeException('recovery_method_prepare_failed');
    $stmt->bind_param('i',$payId);
    if (!$stmt->execute()) throw new RuntimeException('recovery_method_read_failed');
    $method=$stmt->get_result()->fetch_assoc();
    $stmt->close();

    // A BUY_SUB wallet debit and all server slots may be durably held
    // before ordinal 1 exists. Show that escrow for owner-only diagnosis
    // rather than mistaking zero CREATE rows for an unpaid order.
    $walletHold=null;
    if ($pay['state']==='processing_wallet') {
        $hold=$db->prepare("SELECT state,reserved_count FROM service_effects
            WHERE pay_row_id=? AND effect_kind='wallet_hold' AND ordinal=0
            LIMIT 1");
        if (!$hold) throw new RuntimeException('recovery_wallet_hold_prepare');
        $hold->bind_param('i',$payId);
        if (!$hold->execute()) throw new RuntimeException('recovery_wallet_hold_read');
        $walletHold=$hold->get_result()->fetch_assoc();$hold->close();
    }
    // Count the whole local journal, not just the displayed page. Unique
    // ordinal keys plus [1..expected] bounds are needed for completeness.
    $stmt=$db->prepare("SELECT COUNT(*) AS recorded,MIN(ordinal) AS first_ordinal,
            MAX(ordinal) AS last_ordinal FROM service_effects
        WHERE pay_row_id=? AND effect_kind='create'");
    if (!$stmt) throw new RuntimeException('recovery_journal_prepare_failed');
    $stmt->bind_param('i',$payId);
    if (!$stmt->execute()) throw new RuntimeException('recovery_journal_read_failed');
    $journal=$stmt->get_result()->fetch_assoc();
    $stmt->close();
    $requested=(int)$pay['agent_count']>0?(int)$pay['agent_count']:1;
    $recorded=(int)$journal['recorded'];
    $complete=$recorded===$requested &&
        (int)$journal['first_ordinal']===1 &&
        (int)$journal['last_ordinal']===$requested;

    $stmt=$db->prepare("SELECT e.id,e.ordinal,e.state,e.order_id,e.target_server_id,
            e.remote_username,e.operation_id,e.requested_expire,e.requested_bytes,
            e.plan_id,o.id AS live_order_id,o.userid AS order_user,
            o.fileid AS order_plan,o.server_id AS order_server,
            o.inbound_id AS order_inbound,o.remark AS order_username
        FROM service_effects e LEFT JOIN orders_list o ON o.id=e.order_id
        WHERE e.pay_row_id=? AND e.effect_kind='create' AND e.ordinal>?
        ORDER BY e.ordinal LIMIT 13");
    if (!$stmt) throw new RuntimeException('recovery_effect_prepare_failed');
    $stmt->bind_param('ii',$payId,$afterOrdinal);
    if (!$stmt->execute()) throw new RuntimeException('recovery_effect_read_failed');
    $lines=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $items=[];
    foreach ($lines as $line) {
        $local='not_recorded';
        if ($line['order_id']!==null) {
            $local=$line['live_order_id']===null?'historical_order_missing':'linked';
            if ($line['live_order_id']!==null &&
                ((int)$line['order_user']!==(int)$pay['user_id'] ||
                 (int)$line['order_plan']!==(int)$line['plan_id'] ||
                 (int)$line['order_server']!==(int)$line['target_server_id'] ||
                 (int)$line['order_inbound']!==0 ||
                 (string)$line['order_username']!==(string)$line['remote_username']))
                $local='linked_mismatch';
        }
        $items[]=[
            'ordinal'=>(int)$line['ordinal'],'state'=>(string)$line['state'],
            'effect_id'=>(int)$line['id'],'order_id'=>$line['order_id']===null
                ?null:(int)$line['order_id'],
            'local_evidence'=>$local,
            'operation_id'=>(string)$line['operation_id'],
            'requested_expire'=>(string)$line['requested_expire'],
            'requested_bytes'=>(string)$line['requested_bytes'],
            'username'=>(string)$line['remote_username'],
        ];
    }
    return [
        'pay_id'=>(int)$pay['id'],'user_id'=>(int)$pay['user_id'],
        'hash_id'=>(string)$pay['hash_id'],
        'evidence_text'=>achbotRecoveryEvidenceText($pay,$method?:null),
        'historical_evidence_unavailable'=>$recorded===0 && $pay['state']==='paid',
        'amount'=>(int)$pay['price'],'requested_accounts'=>$requested,
        'recorded_accounts'=>$recorded,'journal_complete'=>$complete,
        'journal_assurance'=>'current_mutable_payment_count_only',
        'payment_state'=>(string)$pay['state'],
        'method'=>$method ? (string)$method['provider'] :
            ($walletHold!==null?'wallet_held':'unproven'),
        'method_stage'=>$method ? (string)$method['stage'] :
            ($walletHold!==null?'operator_reconciliation':'unproven'),
        'wallet_hold_state'=>$walletHold===null?'not_recorded':(string)$walletHold['state'],
        'wallet_capacity_reserved'=>$walletHold===null?0:(int)$walletHold['reserved_count'],
        'lines'=>array_slice($items,0,12),
        'next_ordinal'=>count($items)>12 ? (int)$items[11]['ordinal'] : 0,
        'after_ordinal'=>$afterOrdinal,
        'read_only'=>true,
    ];
}

/** Inspect the current exact target identity without holding locks across HTTP. */
function achbotRecoveryTarget(mysqli $db, int $payId, int $ordinal): ?array {
    $stmt=$db->prepare("SELECT e.*,p.state AS pay_state,p.type AS pay_type,
            p.user_id,p.plan_id AS paid_plan,p.server_id AS paid_server,
            p.price,p.agent_count,sp.server_id AS plan_server,
            sp.inbound_id AS plan_inbound,s.panel_group_id,
            c.type AS panel_type,c.panel_url,
            c.username AS panel_auth_user,c.password AS panel_auth_password,
            g.id AS valid_group
        FROM service_effects e
        JOIN pays p ON p.id=e.pay_row_id
        LEFT JOIN server_plans sp ON sp.id=p.plan_id
        LEFT JOIN server_info s ON s.id=e.target_server_id
        LEFT JOIN server_config c ON c.id=e.target_server_id
        LEFT JOIN setting g ON g.id=s.panel_group_id
            AND g.type='ACHBOT_MARZBAN_PANEL_GROUP'
        WHERE e.pay_row_id=? AND e.ordinal=? AND e.effect_kind='create'
        LIMIT 1");
    if (!$stmt) throw new RuntimeException('recovery_target_prepare_failed');
    $stmt->bind_param('ii',$payId,$ordinal);
    if (!$stmt->execute()) throw new RuntimeException('recovery_target_read_failed');
    $row=$stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row?:null;
}

/** Must be a pinned original paid target, not a guessed panel/server. */
function achbotRecoveryTargetValid(?array $e, int $payId, int $ordinal): bool {
    if (!$e || (int)$e['pay_row_id']!==$payId ||
        (int)$e['ordinal']!==$ordinal ||
        $e['pay_type']!=='BUY_SUB' ||
        !in_array($e['pay_state'],['processing','paid','processing_wallet'],true) ||
        !in_array($e['state'],['dispatching','verified','local_recorded'],true) ||
        (int)$e['user_id']<=0 ||
        (int)$e['paid_plan']<=0 ||
        (int)$e['price']<0 ||
        (int)$e['agent_count']<0 ||
        $ordinal>max(1,(int)$e['agent_count']) ||
        $e['plan_inbound']===null || (int)$e['plan_inbound']!==0 ||
        (int)$e['target_server_id']<=0 ||
        (int)$e['target_server_id']!==(int)$e['paid_server'] ||
        (int)$e['target_server_id']!==(int)$e['plan_server'] ||
        (int)$e['plan_id']!==(int)$e['paid_plan'] ||
        $e['panel_type']!=='marzban' ||
        !is_string($e['panel_url']) || $e['panel_url']==='' ||
        !is_string($e['panel_auth_user']) || $e['panel_auth_user']==='' ||
        !is_string($e['panel_auth_password']) || $e['panel_auth_password']==='' ||
        !is_string($e['remote_username']) ||
        preg_match('/^[a-zA-Z0-9_-]{1,100}$/D',$e['remote_username'])!==1 ||
        !is_string($e['operation_id']) ||
        preg_match('/^[a-f0-9]{32}$/D',$e['operation_id'])!==1 ||
        (int)$e['requested_expire']<=0 ||
        achbotRecoveryExactInt($e['requested_bytes'])===null ||
        achbotRecoveryExactInt($e['requested_bytes'])===0 ||
        achbotRecoveryExactInt($e['requested_bytes'])%1073741824!==0)
        return false;
    $group=$e['target_group_id']===null?null:(int)$e['target_group_id'];
    $actualGroup=$e['panel_group_id']===null?null:(int)$e['panel_group_id'];
    if ($group!==$actualGroup || ($group!==null && $e['valid_group']===null))
        return false;
    $endpoint=hash('sha256',rtrim($e['panel_url'],'/'));
    $targetKey=$group===null?'server:'.(int)$e['target_server_id']:'group:'.$group;
    if (!hash_equals((string)$e['endpoint_sha256'],$endpoint) ||
        (string)$e['target_key']!==$targetKey) return false;
    $fingerprint=hash('sha256',json_encode([
        $payId,$ordinal,(int)$e['plan_id'],(int)$e['target_server_id'],
        $group,$targetKey,$endpoint,(string)$e['remote_username'],
        (int)$e['requested_expire'],(int)$e['requested_bytes']
    ],JSON_THROW_ON_ERROR));
    return hash_equals((string)$e['request_sha256'],$fingerprint);
}

/** Exact integer, never PHP's truncating (int) of remote JSON floats. */
function achbotRecoveryExactInt($value): ?int {
    if (!is_int($value) &&
        (!is_string($value) || preg_match('/^(0|[1-9][0-9]*)$/D',$value)!==1))
        return null;
    $parsed=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
    return $parsed===false?null:$parsed;
}

/**
 * Exactly one optional remote account GET, with a second local identity read.
 * Caller MUST independently authorize the owner-private invocation.
 * No result permits automatic repair, refund, replay or terminal transition.
 */
function achbotRecoveryInspectLine(mysqli $db, int $payId, int $ordinal,
                                   callable $getExactAccount): array {
    $result=['status'=>'unknown','pay_id'=>$payId,'ordinal'=>$ordinal,
             'remote_gets'=>0,'read_only'=>true,'settlement_proven'=>false];
    if ($payId<=0 || $ordinal<=0) return $result;
    achbotEffectRequireUniqueLine($db);
    $first=achbotRecoveryTarget($db,$payId,$ordinal);
    if (!achbotRecoveryTargetValid($first,$payId,$ordinal)) {
        $result['status']='target_or_identity_changed';
        return $result;
    }
    $result['effect_id']=(int)$first['id'];
    $result['username']=(string)$first['remote_username'];
    try {
        $result['remote_gets']=1;
        // Pass the same immutable in-memory target/credentials to BOTH
        // authentication and account GET. No server-ID lookup in transport.
        $remote=$getExactAccount((string)$first['panel_url'],
                                 (string)$first['panel_auth_user'],
                                 (string)$first['panel_auth_password'],
                                 (string)$first['remote_username']);
    } catch (Throwable $ignored) {
        $result['status']='remote_unavailable';
        return $result;
    }
    // An HTTP 404, transport failure or missing account is never permission to
    // repeat CREATE. The pinned adapter authenticates and requires HTTP 200.
    if (!is_object($remote) || (isset($remote->success) && $remote->success===false)) {
        $result['status']='remote_unavailable';
        return $result;
    }
    $last=achbotRecoveryTarget($db,$payId,$ordinal);
    if (!achbotRecoveryTargetValid($last,$payId,$ordinal) ||
        $first!==$last) {
        $result['status']='changed_during_inspection';
        return $result;
    }
    $expire=isset($remote->expire)?achbotRecoveryExactInt($remote->expire):null;
    $bytes=isset($remote->data_limit)?achbotRecoveryExactInt($remote->data_limit):null;
    $result['status']=(
        (string)($remote->username??'')===(string)$first['remote_username'] &&
        (string)($remote->note??'')==='achbot:v1:'.$first['operation_id'] &&
        $expire===(int)$first['requested_expire'] &&
        $bytes===(int)$first['requested_bytes'] &&
        (string)($remote->status??'')==='active'
    )?'matching_observation':'remote_mismatch';
    return $result;
}
