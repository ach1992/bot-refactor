<?php
/** One-shot human decisions use the existing per-payment provider attempt.
 * No new review table, additional financial transaction or separate order.
 * Operator checks the merchant's ORIGINAL invoice before deciding.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '')===__FILE__) {
    http_response_code(404); exit;
}
require_once __DIR__.'/GatewayClient.php';
require_once __DIR__.'/PaymentEvidence.php';
require_once __DIR__.'/CheckoutRoutes.php';
require_once __DIR__.'/HostedReceipt.php';
require_once __DIR__.'/../Admin/Access.php';

/** Current committed local state, NOT a claim that merchant funds settled.
 * A recorded remote CREATE/credit disqualifies all further decisions.
 * Aged unpaid invoices never enter the queue. Genuine provider evidence
 * or a verified upstream transport outage is required for original decisions.
 */
function achbotGatewayReviewEligible(mysqli $db,int $attemptId,
    string $decision,bool $lock=false): ?array {
    if ($attemptId<=0 || !in_array($decision,['approve','reject'],true)) return null;
    $q=$db->prepare('SELECT pay_row_id FROM payment_attempts WHERE id=? LIMIT 1');
    $q->bind_param('i',$attemptId);$q->execute();$r=$q->get_result()->fetch_assoc();$q->close();
    if (!$r) return null;
    $payId=(int)$r['pay_row_id'];$suffix=$lock?' FOR UPDATE':'';
    $q=$db->prepare('SELECT * FROM pays WHERE id=?'.$suffix);
    $q->bind_param('i',$payId);$q->execute();$pay=$q->get_result()->fetch_assoc();$q->close();
    $q=$db->prepare('SELECT *,TIMESTAMPDIFF(SECOND,created_at,NOW()) AS age_seconds
        FROM payment_attempts WHERE id=? AND pay_row_id=?'.$suffix);
    $q->bind_param('ii',$attemptId,$payId);$q->execute();
    $a=$q->get_result()->fetch_assoc();$q->close();
    if (!$pay || !$a ||
        !in_array((string)$pay['state'],['pending','send','service_superseded'],true) ||
        (int)$pay['price']<=0 ||
        (int)$a['local_amount_toman']!==(int)$pay['price'] ||
        !hash_equals((string)$pay['hash_id'],(string)$a['order_hash']) ||
        !hash_equals((string)$pay['payid'],(string)$a['provider_reference']) ||
        (string)$a['provider_reference']==='' ||
        achbotHasUnscopedPaymentReferenceCollision($db,(string)$a['provider_reference'],$payId))
        return null;
    if ($a['wallet_credit_entry_id']!==null || !is_string($a['wallet_invoice_sha256']) ||
        !hash_equals($a['wallet_invoice_sha256'],achbotCreditInvoiceFingerprint($pay,$a))) return null;
    $provider=(string)$a['provider'];
    $hosted=achbotHostedReceiptDefinition($provider);
    if (!$hosted || (!$hosted['child_transactions'] && (string)($a['payment_id']??'')!=='')) return null;
    // The invoice fingerprint already invokes the registered quote validator.
    $stage=(string)$a['stage'];$status=(string)($a['provider_status']??'');
    // Staff cannot approve a waiting invoice on elapsed time alone or while
    // a real verifier is running. Human decision is only for a trusted
    // provider observation or a verified merchant-transport outage.
    if ($stage!=='review') return null;
    if ($hosted['child_transactions']) {
        // A merchant-confirmed refund permits closing the *original* local
        // quote as declined, never approving it or refunding anything again.
        if ($status==='refunded') {
            if ($decision!=='reject') return null;
        } elseif (!in_array($status,[
            'partially_paid','confirming','confirmed','sending','finished'],true))
            return null;
    } elseif ($status!=='verification_unavailable') return null;
    // Never declare an invoice unpaid or send its effects a second time if
    // a local service/payment side effect has already started.
    $q=$db->prepare('SELECT id FROM service_effects WHERE pay_row_id=? LIMIT 1'.$suffix);
    $q->bind_param('i',$payId);$q->execute();
    $effect=$q->get_result()->fetch_assoc();$q->close();
    if ($effect) return null;
    // A second funded provider child cannot settle the same original order.
    // A refunded child is not proof that another child has been refunded.
    $q=$db->prepare('SELECT payment_id,observed_status FROM payment_transactions
        WHERE attempt_id=? AND provider=? ORDER BY id'.$suffix);
    $q->bind_param('is',$attemptId,$provider);$q->execute();
    $children=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
    $primary=(string)($a['payment_id']??'');
    foreach ($children as $child) {
        $childId=(string)$child['payment_id'];
        $seen=(string)$child['observed_status'];
        if ($hosted['child_transactions'] && $childId!==$primary &&
            in_array($seen,['confirming','confirmed','sending','partially_paid','finished'],true))
            return null;
        if ($decision==='reject' &&
            in_array($seen,['confirming','confirmed','sending','partially_paid','finished'],true))
            return null;
        if ($decision==='approve' && $seen==='refunded' && $childId===$primary)
            return null;
    }
    // NOWPayments may have persisted the issued invoice but lost the child
    // payment ID during a provider disconnect. The Owner can independently
    // match the original invoice in the merchant dashboard. No synthetic
    // payment_transactions row or provider 'finished' claim is created.
    unset($a['age_seconds']); // time passes between preflight and locked recheck
    $digest=hash('sha256',json_encode([
        'pay'=>$pay,'attempt'=>$a,'children'=>$children],
        JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
    return ['pay'=>$pay,'attempt'=>$a,'digest'=>$digest,'provider'=>$provider];
}

/** One human decision, serialized against receipt verification and using
 * the original immutable invoice. Approval goes through common credit. */
function achbotGatewayReviewCommit(mysqli $db,int $actor,int $attemptId,
    string $decision,int $primaryOwner): array {
    if (!achbotAdminCanFor($db,$actor,$primaryOwner,'payments')) return ['status'=>'denied'];
    try {
        $before=achbotGatewayReviewEligible($db,$attemptId,$decision);
        if (!$before) return ['status'=>'stale'];
        $pay=$before['pay'];$id=(int)$pay['id'];$uid=(int)$pay['user_id'];
        $lease=achbotOriginalDispatchLeaseBegin($db,$id);
        if ($lease!=='owned') return ['status'=>$lease==='busy'?'busy':'unavailable'];
        try {
            if (!$db->begin_transaction()) throw new RuntimeException('gateway_review_begin');
            try {
                [$current,$attempt]=achbotCreditInvoiceLocked($db,$id,$uid);
                if (!achbotAdminCanLocked($db,$actor,$primaryOwner,'payments'))
                    throw new DomainException('gateway_review_actor_invalid');
                $locked=achbotGatewayReviewEligible($db,$attemptId,$decision,true);
                if (!$locked || !hash_equals($before['digest'],$locked['digest'])) {
                    $db->rollback();return ['status'=>'stale'];
                }
                if ($decision==='approve') {
                    achbotHostedMerchantSnapshot($current,$attempt);
                    $r=achbotCheckoutSettleReceiptLocked($db,$id,$uid,'manual',[
                        'merchant_profile_sha256'=>hash('sha256',$attempt['wallet_merchant_profile']),
                        'review_digest'=>$locked['digest']],$actor,$primaryOwner);
                } else {
                    $pay=achbotCheckoutRejectUnfundedLocked($db,$current,$attempt,$actor,$primaryOwner);
                    require_once __DIR__.'/FollowupOutbox.php';
                    achbotFollowupInsertLocked($db,$pay,'manual_reject','customer','send',(string)$uid,null,
                        ['text'=>achbotMessageHtml('automatic_gateway_review.rejected_customer',
                            ['hash'=>$pay['hash_id'],'amount'=>number_format((int)$pay['price'])]),'keys'=>null,'mode'=>'HTML']);
                    achbotFollowupStageAdminReportLocked($db,$id,null,'manual_reject',
                        achbotPaymentEvidencePayFingerprint($pay),$primaryOwner,(string)($GLOBALS['botState']['rewardChannel']??''),
                        ['text'=>achbotMessageHtml('automatic_gateway_review.rejected_report',[
                            'id'=>$attemptId,'user'=>$uid,'amount'=>number_format((int)$pay['price']),'hash'=>$pay['hash_id']]),
                            'keys'=>null,'mode'=>'HTML']);
                }
                if (!$db->commit()) throw new RuntimeException('gateway_review_commit');
            } catch (Throwable $e) {$db->rollback();throw $e;}
            if ($decision==='reject') {
                try {achbotFollowupDispatchPending($db,$id,'manual_reject');}
                catch (Throwable $e) {
                    achbotDiagnosticEvent('achbot.finance.gateway_review_report',
                        ['pay_id'=>$id,'class'=>get_class($e)],'warning');
                }
                return ['status'=>'rejected','pay'=>$pay];
            }
            achbotCheckoutAdvance($db,$r['operation'],$id);
            return ['status'=>achbotCheckoutReceiptOutcome($db,$id),'pay'=>$pay];
        } finally {achbotOriginalDispatchLeaseEnd($db,$id,$lease);}
    } catch (Throwable $e) {
        achbotDiagnosticEvent('achbot.finance.gateway_review',['attempt_id'=>$attemptId,'class'=>get_class($e)],'warning');
        return ['status'=>'unavailable'];
    }
}

/** Recovery uses the original encrypted merchant credential and shared verifier. */
function achbotGatewayReviewRecover(mysqli $db,int $actor,int $attemptId,
    int $primaryOwner): array {
    if (!achbotAdminCanFor($db,$actor,$primaryOwner,'payments')) return ['status'=>'denied'];
    $r=achbotProviderRow($db,"SELECT a.pay_row_id,p.user_id,a.provider,a.provider_reference,a.payment_id
        FROM payment_attempts a JOIN pays p ON p.id=a.pay_row_id
        WHERE a.id=? AND a.wallet_invoice_sha256<>''",'i',[$attemptId]);
    if (!$r) return ['status'=>'stale'];
    $id=(int)$r['pay_row_id'];
    $hosted=achbotHostedReceiptDefinition($r['provider']);
    if (!$hosted) return ['status'=>'stale'];
    $reference=$hosted['child_transactions']?$r['payment_id']:$r['provider_reference'];
    if (!is_string($reference) || $reference==='') return ['status'=>'stale'];
    $lease=achbotOriginalDispatchLeaseBegin($db,$id);
    if ($lease!=='owned') return ['status'=>$lease==='busy'?'busy':'unavailable'];
    try {
        if (!achbotAdminCanFor($db,$actor,$primaryOwner,'payments')) return ['status'=>'denied'];
        $result=achbotHostedReceiptVerify($db,$id,(int)$r['user_id'],$r['provider'],$reference);
        if ($result['status']!=='credited') return ['status'=>'unknown'];
        achbotCheckoutAdvance($db,$result['operation'],$id);
        return ['status'=>achbotCheckoutReceiptOutcome($db,$id)];
    } catch (Throwable $e) {return ['status'=>'unavailable'];}
    finally {achbotOriginalDispatchLeaseEnd($db,$id,$lease);}
}
