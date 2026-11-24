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

function achbotGatewayReviewReady(mysqli $db): bool {
    try {
        $q=$db->query('SELECT id,pay_row_id,provider,stage,provider_status,
            review_archived_at,review_archived_by FROM payment_attempts LIMIT 0');
        $q->free();
        $q=$db->query("SHOW INDEX FROM payment_attempts WHERE Key_name='uq_payment_attempt_pay_row'");
        $cols=[];
        while ($r=$q->fetch_assoc()) {
            if ((int)$r['Non_unique']!==0) return false;
            $cols[(int)$r['Seq_in_index']]=(string)$r['Column_name'];
        }
        ksort($cols);
        return array_values($cols)===['pay_row_id'];
    } catch (Throwable $error) {return false;}
}

/** Current committed local state, NOT a claim that merchant funds settled.
 * A recorded remote CREATE/credit disqualifies all further decisions.
 * Aged unpaid invoices never enter the queue. Genuine provider evidence
 * or a verified upstream transport outage is required for original decisions.
 */
function achbotGatewayReviewEligible(mysqli $db,int $attemptId,
    string $decision,bool $lock=false): ?array {
    if ($attemptId<=0 || !in_array($decision,['approve','reject'],true)) return null;
    $q=$db->prepare("SELECT pay_row_id FROM payment_attempts
        WHERE id=? AND provider IN ('nowpayments','zarinpal','nextpay') LIMIT 1");
    $q->bind_param('i',$attemptId);$q->execute();$r=$q->get_result()->fetch_assoc();$q->close();
    if (!$r) return null;
    $payId=(int)$r['pay_row_id'];$suffix=$lock?' FOR UPDATE':'';
    $q=$db->prepare('SELECT * FROM pays WHERE id=?'.$suffix);
    $q->bind_param('i',$payId);$q->execute();$pay=$q->get_result()->fetch_assoc();$q->close();
    $q=$db->prepare('SELECT *,TIMESTAMPDIFF(SECOND,created_at,NOW()) AS age_seconds
        FROM payment_attempts WHERE id=? AND pay_row_id=?'.$suffix);
    $q->bind_param('ii',$attemptId,$payId);$q->execute();
    $a=$q->get_result()->fetch_assoc();$q->close();
    if (!$pay || !$a || ($a['review_archived_at']??null)!==null ||
        !in_array((string)$pay['state'],['pending','send'],true) ||
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
    if ($provider==='nowpayments') {
        if (strtolower((string)$a['quote_currency'])!=='usd' ||
            !achbotNpDecimalAtLeast($a['quote_amount'],'0.00000001')) return null;
    } else {
        if (strtolower((string)$a['quote_currency'])!=='toman' ||
            !achbotNpDecimalAtLeast($a['quote_amount'],(string)$pay['price']) ||
            !achbotNpDecimalAtLeast((string)$pay['price'],$a['quote_amount']) ||
            (string)($a['payment_id']??'')!=='') return null;
    }
    $stage=(string)$a['stage'];$status=(string)($a['provider_status']??'');
    // Staff cannot approve a waiting invoice on elapsed time alone or while
    // a real verifier is running. Human decision is only for a trusted
    // provider observation or a verified merchant-transport outage.
    if ($stage!=='review') return null;
    if ($provider==='nowpayments') {
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
        if ($provider==='nowpayments' && $childId!==$primary &&
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
                    $s=achbotProviderStatement($db,"UPDATE payment_attempts SET stage='done',provider_status='operator_rejected' WHERE id=?",
                        'i',[$attemptId]);$s->close();
                    $s=achbotProviderStatement($db,"UPDATE pays SET state='declined',approved_by=? WHERE id=?",
                        'ii',[$actor,$id]);$s->close();
                    if ($attempt['service_operation_id']!==null)
                        achbotServiceCancelUnfundedLocked($db,$uid,(int)$attempt['service_operation_id']);
                }
                if (!$db->commit()) throw new RuntimeException('gateway_review_commit');
            } catch (Throwable $e) {$db->rollback();throw $e;}
            if ($decision==='reject') return ['status'=>'rejected','pay'=>$pay];
            achbotCheckoutAdvance($db,$r['operation'],$id);
            return ['status'=>'completed','pay'=>$pay];
        } finally {achbotOriginalDispatchLeaseEnd($db,$id,$lease);}
    } catch (Throwable $e) {
        achbotDiagnosticEvent('achbot.finance.gateway_review',['attempt_id'=>$attemptId,'class'=>get_class($e)],'warning');
        return ['status'=>'unavailable'];
    }
}

/** Recovery uses the original encrypted merchant credential and shared verifier. */
function achbotGatewayReviewRecover(mysqli $db,int $actor,int $attemptId,
    int $primaryOwner,array $paymentKeys=[]): array {
    if (!achbotAdminCanFor($db,$actor,$primaryOwner,'payments')) return ['status'=>'denied'];
    $r=achbotProviderRow($db,"SELECT a.pay_row_id,p.user_id,a.provider,a.provider_reference,a.payment_id
        FROM payment_attempts a JOIN pays p ON p.id=a.pay_row_id
        WHERE a.id=? AND a.review_archived_at IS NULL AND a.wallet_invoice_sha256<>''",'i',[$attemptId]);
    if (!$r) return ['status'=>'stale'];
    $id=(int)$r['pay_row_id'];
    $reference=$r['provider']==='nowpayments'?$r['payment_id']:$r['provider_reference'];
    if (!is_string($reference) || $reference==='') return ['status'=>'stale'];
    $lease=achbotOriginalDispatchLeaseBegin($db,$id);
    if ($lease!=='owned') return ['status'=>$lease==='busy'?'busy':'unavailable'];
    try {
        if (!achbotAdminCanFor($db,$actor,$primaryOwner,'payments')) return ['status'=>'denied'];
        $result=achbotHostedReceiptVerify($db,$id,(int)$r['user_id'],$r['provider'],$reference);
        if ($result['status']!=='credited') return ['status'=>'unknown'];
        achbotCheckoutAdvance($db,$result['operation'],$id);
        return ['status'=>'completed'];
    } catch (Throwable $e) {return ['status'=>'unavailable'];}
    finally {achbotOriginalDispatchLeaseEnd($db,$id,$lease);}
}

/** Archive only local execution. Never reverse an existing debit or fabricate
 * a payment attempt. Both worker session locks fence the entire action. */
function achbotCheckoutArchive(mysqli $db,int $actor,int $payId,int $primary,?int $attemptId): string {
    if (!achbotAdminCanFor($db,$actor,$primary,'payments')) return 'denied';
    $lease=achbotOriginalDispatchLeaseBegin($db,$payId);
    if ($lease!=='owned') return $lease==='busy'?'busy':'unavailable';
    $lock=null;
    try {
        $loc=achbotProviderRow($db,'SELECT user_id,checkout_operation_id FROM pays WHERE id=?','i',[$payId]);
        if (!$loc) return 'stale';
        $opId=(int)$loc['checkout_operation_id'];
        if ($opId>0) {
            require_once __DIR__.'/../Services/Execution.php';
            $name=achbotServiceExecutionLock($db,$opId);
            $r=achbotProviderRow($db,'SELECT GET_LOCK(?,0) AS acquired','s',[$name]);
            if ((int)($r['acquired']??0)!==1) return 'busy';
            $lock=$name;
        }
        if (!$db->begin_transaction()) throw new RuntimeException('gateway_archive_begin');
        try {
            $pay=achbotCheckoutPayLocked($db,$payId,(int)$loc['user_id']);
            $a=achbotProviderRow($db,'SELECT * FROM payment_attempts WHERE pay_row_id=? FOR UPDATE','i',[$payId]);
            if ((int)$pay['checkout_operation_id']!==$opId ||
                ($attemptId===null?$a!==null:(!$a || (int)$a['id']!==$attemptId)) ||
                !achbotGatewayAttentionCaseOpen($db,$payId)) {$db->rollback();return 'stale';}
            if (!achbotAdminCanLocked($db,$actor,$primary,'payments')) {$db->rollback();return 'denied';}
            if ($opId>0) {
                $s=achbotProviderStatement($db,"UPDATE service_operations SET archived_at=NOW(6),archived_by=?,next_attempt_at=NULL
                    WHERE id=? AND archived_at IS NULL",'ii',[$actor,$opId]);$s->close();
            }
            if ($a!==null) {
                $s=achbotProviderStatement($db,"UPDATE payment_attempts SET review_archived_at=UTC_TIMESTAMP(),review_archived_by=?
                    WHERE id=? AND review_archived_at IS NULL",'ii',[$actor,$attemptId]);$s->close();
            }
            if (!$db->commit()) throw new RuntimeException('gateway_archive_commit');
            return 'archived';
        } catch (Throwable $e) {$db->rollback();throw $e;}
    } catch (Throwable $e) {return 'unavailable';}
    finally {
        if ($lock!==null) {
            $s=achbotProviderStatement($db,'SELECT RELEASE_LOCK(?)','s',[$lock]);$s->close();
        }
        achbotOriginalDispatchLeaseEnd($db,$payId,$lease);
    }
}
function achbotWalletReviewArchive(mysqli $db,int $actor,int $payId,int $primaryOwner): string {
    return achbotCheckoutArchive($db,$actor,$payId,$primaryOwner,null);
}
function achbotGatewayReviewArchive(mysqli $db,int $actor,int $attemptId,int $primaryOwner): string {
    if (!achbotAdminCanFor($db,$actor,$primaryOwner,'payments')) return 'denied';
    $a=achbotProviderRow($db,'SELECT pay_row_id FROM payment_attempts WHERE id=?','i',[$attemptId]);
    return $a?achbotCheckoutArchive($db,$actor,(int)$a['pay_row_id'],$primaryOwner,$attemptId):'stale';
}
