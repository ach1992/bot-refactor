<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/ReferralReward.php';
require_once __DIR__.'/FollowupOutbox.php';
require_once __DIR__.'/FundingCheckout.php';

/** Only the verified receipt that actually funded this original BUY is
 * eligible. A direct Wallet purchase, unrelated top-up, cancelled quote,
 * or second/later receipt cannot create a purchase referral reward. */
function achbotServiceReferralReceiptLocked(mysqli $db,array $op): ?array {
    if ($op['kind']!=='BUY') throw new RuntimeException('service_referral_kind_conflict');
    $source=achbotServiceFundingReceiptLocked($db,$op);
    return $source===null?null:$source['pay'];
}

/** Reserve the original reward decision in the same local COMMIT as the
 * completed purchase. Never depend on subsequent Telegram delivery, order
 * survival, or changes to the invitation policy. Called only by the shared
 * service engine after all original lines have been locally finalized. */
function achbotServiceReferralReserveLocked(mysqli $db,array $op): void {
    if ($op['kind']!=='BUY') return;
    if ($op['stage']!=='applied') throw new RuntimeException('service_referral_not_finalizing');
    $pay=achbotServiceReferralReceiptLocked($db,$op);
    if (!$pay) return;
    $buyer=(int)$op['userid'];$id=(int)$op['id'];
    $u=achbotProviderRow($db,'SELECT refered_by FROM users WHERE id=? AND userid=? FOR UPDATE',
        'ii',[(int)$op['user_row_id'],$buyer]);
    if (!$u) throw new RuntimeException('service_referral_buyer_missing');
    $inviter=(int)$u['refered_by'];
    if ($inviter<=0 || $inviter===$buyer) return;
    $amount=achbotPurchaseReferralPolicyAmount($db,'service_referral_policy');
    $row=null;$state='DISABLED';
    if ($amount>0) {
        $s=achbotProviderStatement($db,'SELECT id FROM users FORCE INDEX (idx_users_userid_v1)
            WHERE userid=? ORDER BY id LIMIT 2 FOR UPDATE','i',[$inviter]);
        $users=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
        // A genuine missing/ambiguous recipient is not permission to pay a
        // future replacement user. Preserve the original claim for diagnosis.
        $row=count($users)===1?(int)$users[0]['id']:null;
        $state=$row===null?'NEEDS_REVIEW':'NOT_ATTEMPTED';
    }
    $s=achbotProviderStatement($db,"INSERT INTO referral_awards
        (pay_row_id,buyer_userid,inviter_userid,amount_toman,state,service_operation_id,
         inviter_user_row_id,next_attempt_at,review_at)
        VALUES (?,?,?,?,?,?,?,IF(?='NOT_ATTEMPTED',NOW(6),NULL),
            IF(?='NEEDS_REVIEW',NOW(),NULL))",
        'iiiisiiss',[(int)$pay['id'],$buyer,$inviter,$amount,$state,$id,$row,$state,$state]);
    $n=$s->affected_rows;$s->close();
    if ($n!==1) throw new RuntimeException('service_referral_reservation_failed');
    if ($state==='NEEDS_REVIEW') achbotDiagnosticEvent('achbot.finance.purchase_referral',[
        'service_operation_id'=>$id,'pay_id'=>(int)$pay['id'],'reason'=>'service_referral_inviter_identity_unavailable'],'warning');
}

/** The original claim and the Wallet credit, projection, and sealed reports
 * commit atomically. No Telegram or Native request in this transaction.
 * A subsequent call can reconcile a committed result after lost COMMIT ACK. */
function achbotServiceReferralComplete(mysqli $db,int $id,int $owner): string {
    achbotPaymentEvidenceRequireAutocommit($db);
    if ($id<=0 || $owner<=0) throw new InvalidArgumentException('service_referral_context');
    $payId=0;
    try {
        $loc=achbotProviderRow($db,'SELECT buyer_userid FROM referral_awards WHERE service_operation_id=?','i',[$id]);
        if (!$loc) return 'not_applicable';
        $buyer=(int)$loc['buyer_userid'];
        if (!$db->begin_transaction()) throw new RuntimeException('service_referral_begin');
        [$op]=achbotServiceReadLocked($db,$buyer,$id);
        if ($op['archived_at']!==null) {$db->rollback();return 'archived';}
        if ($op['kind']!=='BUY' || $op['stage']!=='completed' ||
            achbotServiceFundLocked($db,$buyer,$id)!=='already_funded')
            throw new RuntimeException('service_referral_purchase_unconfirmed');
        $claim=achbotProviderRow($db,'SELECT * FROM referral_awards WHERE service_operation_id=? FOR UPDATE','i',[$id]);
        if (!$claim || (int)$claim['buyer_userid']!==$buyer)
            throw new RuntimeException('service_referral_claim_changed');
        $payId=(int)$claim['pay_row_id'];
        if ($claim['state']!=='NOT_ATTEMPTED') {$db->rollback();return strtolower($claim['state']);}
        $pay=achbotServiceReferralReceiptLocked($db,$op);
        if (!$pay || (int)$pay['id']!==$payId)
            throw new RuntimeException('service_referral_original_receipt_changed');
        $inviter=(int)$claim['inviter_userid'];$amount=(int)$claim['amount_toman'];
        if ($inviter<=0 || $inviter===$buyer || $amount<=0 || $claim['wallet_entry_id']!==null)
            throw new RuntimeException('service_referral_claim_invalid');
        $s=achbotProviderStatement($db,'SELECT id FROM users FORCE INDEX (idx_users_userid_v1)
            WHERE userid=? ORDER BY id LIMIT 2 FOR UPDATE','i',[$inviter]);
        $users=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
        if (count($users)!==1 || (int)$users[0]['id']!==(int)$claim['inviter_user_row_id']) {
            $s=achbotProviderStatement($db,"UPDATE referral_awards SET state='NEEDS_REVIEW',
                next_attempt_at=NULL,review_at=NOW() WHERE id=? AND state='NOT_ATTEMPTED'",
                'i',[(int)$claim['id']]);$s->close();
            if (!$db->commit()) throw new RuntimeException('service_referral_review_commit');
            achbotDiagnosticEvent('achbot.finance.purchase_referral',[
                'service_operation_id'=>$id,'pay_id'=>$payId,'reason'=>'service_referral_inviter_identity_changed'],'warning');
            return 'needs_review';
        }
        $entry=achbotWalletApplyLocked($db,$inviter,'purchase_referral',(string)$id,0,$amount);
        // A journal entry without the same committed claim cannot occur in
        // this transaction. Do not repair/adopt contradictory money evidence.
        if ($entry['replayed']) throw new RuntimeException('service_referral_orphan_entry');
        $s=achbotProviderStatement($db,"UPDATE referral_awards SET state='CONFIRMED',
            before_balance=?,after_balance=?,wallet_entry_id=?,confirmed_at=NOW(),next_attempt_at=NULL
            WHERE id=? AND state='NOT_ATTEMPTED' AND wallet_entry_id IS NULL",
            'iiii',[$entry['before'],$entry['after'],$entry['id'],(int)$claim['id']]);
        $n=$s->affected_rows;$s->close();
        if ($n!==1) throw new RuntimeException('service_referral_confirm_failed');
        achbotPurchaseReferralStageReportsLocked($db,$pay,$buyer,$inviter,$amount,
            $entry['before'],$entry['after'],$owner,$id);
        if (!$db->commit()) throw new RuntimeException('service_referral_commit');
        return 'confirmed';
    } catch (Throwable $e) {
        try {$db->rollback();} catch (Throwable $ignored) {}
        // Do not downgrade a committed credit after an uncertain ACK. Only
        // the uncredited original claim remains due; move it behind peers.
        try {
            $s=achbotProviderStatement($db,"UPDATE referral_awards SET
                next_attempt_at=DATE_ADD(NOW(6),INTERVAL 60 SECOND)
                WHERE service_operation_id=? AND state='NOT_ATTEMPTED'",'i',[$id]);$s->close();
        } catch (Throwable $ignored) {}
        $reason=$e->getMessage();
        if (!preg_match('/\A[a-z][a-z0-9_]{2,95}\z/D',$reason)) $reason='service_referral_local_failed';
        achbotDiagnosticEvent('achbot.finance.purchase_referral',[
            'service_operation_id'=>$id,'pay_id'=>$payId,'reason'=>$reason,'class'=>get_class($e)],'warning');
        return 'deferred';
    }
}

/** The 020 report source is the completed service AND the real receipt and
 * Wallet award. Historical reports remain on their original v1 validator.
 * No later order/plan/policy changes can invalidate a financial report. */
function achbotServiceReferralReportValid(mysqli $db,array $pay,array $r): bool {
    $id=(int)$r['service_operation_id'];$payId=(int)$pay['id'];
    if ($id<=0 || $r['stage']!=='referral_reward' || $r['kind']!=='send' ||
        $r['message_id']!==null || !in_array($r['role'],['referral_inviter','referral_admin','referral_channel'],true))
        return false;
    $award=achbotProviderRow($db,"SELECT a.inviter_userid,a.inviter_user_row_id FROM referral_awards a
        JOIN service_operations o ON o.id=a.service_operation_id
        JOIN payment_attempts p ON p.pay_row_id=a.pay_row_id AND p.service_operation_id=o.id
        JOIN wallet_entries c ON c.id=p.wallet_credit_entry_id
        JOIN wallet_entries w ON w.id=a.wallet_entry_id
        WHERE a.service_operation_id=? AND a.pay_row_id=? AND a.state='CONFIRMED'
          AND o.stage='completed' AND o.archived_at IS NULL AND o.kind='BUY' AND o.userid=a.buyer_userid
          AND o.userid=? AND o.price_toman=? AND p.wallet_funding_result='funded'
          AND c.source_kind='payment_credit' AND c.source_ref=CAST(a.pay_row_id AS CHAR)
          AND c.leg=0 AND c.userid=o.userid AND c.user_row_id=o.user_row_id
          AND c.delta_toman=o.price_toman AND w.source_kind='purchase_referral'
          AND w.source_ref=CAST(o.id AS CHAR) AND w.leg=0
          AND w.userid=a.inviter_userid AND w.user_row_id=a.inviter_user_row_id
          AND w.delta_toman=a.amount_toman AND w.balance_before=a.before_balance
          AND w.balance_after=a.after_balance",'iiii',[$id,$payId,(int)$pay['user_id'],(int)$pay['price']]);
    if (!$award || $pay['state']!=='paid') return false;
    if ($r['role']==='referral_inviter') {
        $q=achbotProviderStatement($db,'SELECT id FROM users FORCE INDEX (idx_users_userid_v1)
            WHERE userid=? ORDER BY id LIMIT 2 LOCK IN SHARE MODE NOWAIT',
            'i',[(int)$award['inviter_userid']]);
        $current=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
        if (count($current)!==1 || (int)$current[0]['id']!==(int)$award['inviter_user_row_id']) return false;
    }
    return true;
}

/** Fair bounded local work, independent of the Native and Telegram workers.
 * A pending credit is retried after rollback; UNKNOWN delivery never rearms it. */
function achbotServiceReferralMaintainPending(mysqli $db,int $owner,int $limit,float $budgetSeconds): array {
    if ($limit<=0 || $owner<=0 || !is_finite($budgetSeconds) || $budgetSeconds<=0)
        throw new InvalidArgumentException('service_referral_budget_invalid');
    $deadline=hrtime(true)/1000000000+$budgetSeconds;
    $s=achbotProviderStatement($db,"SELECT service_operation_id FROM referral_awards
        WHERE service_operation_id IS NOT NULL AND state='NOT_ATTEMPTED' AND next_attempt_at<=NOW(6)
        ORDER BY next_attempt_at,id LIMIT ?",'i',[$limit]);
    $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();$out=[];
    foreach ($rows as $r) {
        if (hrtime(true)/1000000000>=$deadline) break;
        $id=(int)$r['service_operation_id'];
        $out[$id]=achbotServiceReferralComplete($db,$id,$owner);
    }
    return $out;
}
