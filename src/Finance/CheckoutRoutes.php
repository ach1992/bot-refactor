<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/CheckoutAdmission.php';

/** Presentation is supplied by the same established installation context. */
function achbotCheckoutPresentation(): array {
    return ['bot_state'=>(array)($GLOBALS['botState']??[]),
        'bot_url'=>(string)($GLOBALS['botUrl']??''),
        'bot_username'=>(string)($GLOBALS['botUsername']??'')];
}

/** Fast-path only. The durable worker owns progress after this request ends.
 * Never interpret an exhausted budget or failed Telegram call as a refund. */
function achbotCheckoutAdvance(mysqli $db,?int $operation,?int $payId=null,bool $force=false): void {
    require_once __DIR__.'/../Services/Execution.php';
    require_once __DIR__.'/ServiceDelivery.php';
    require_once __DIR__.'/FollowupOutbox.php';
    $deadline=hrtime(true)/1000000000+8.0;
    try {
        if ($operation!==null) {
            for ($i=0;$i<16 && hrtime(true)/1000000000<$deadline;$i++) {
                $r=achbotServiceExecuteStep($db,$operation,achbotCheckoutPresentation(),$force && $i===0);
                if (!in_array($r['status'],['progressed','applied'],true)) break;
            }
            $s=achbotProviderStatement($db,"SELECT id FROM payment_delivery_intents
                WHERE service_operation_id=? AND state IN ('pending','dispatching') ORDER BY ordinal LIMIT 8",'i',[$operation]);
            $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
            foreach ($rows as $row) {
                if (hrtime(true)/1000000000>=$deadline) break;
                achbotServiceDeliveryDispatch($db,(int)$row['id']);
            }
        }
        if ($operation!==null || $payId!==null) {
            $s=achbotProviderStatement($db,"SELECT id FROM payment_followup_intents
                WHERE ((service_operation_id IS NOT NULL AND service_operation_id=?) OR
                    (pay_row_id IS NOT NULL AND pay_row_id=? AND stage='wallet_credit'))
                AND state IN ('pending','dispatching') ORDER BY id LIMIT 8",
                'ii',[$operation,$payId]);
            $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
            foreach ($rows as $row) {
                if (hrtime(true)/1000000000>=$deadline) break;
                achbotFollowupDispatchOne($db,(int)$row['id']);
            }
        }
    } catch (Throwable $e) {
        error_log('achbot_checkout_progress_deferred service_operation_id='.(int)$operation.' pay_id='.(int)$payId.' class='.get_class($e));
    }
}

/** One outcome projection for every verified receipt. It never infers
 * a service or delivery result from the fact that money was credited. */
function achbotCheckoutReceiptOutcome(mysqli $db,int $payId): string {
    $a=achbotProviderRow($db,'SELECT wallet_credit_entry_id,wallet_funding_result,service_operation_id
        FROM payment_attempts WHERE pay_row_id=?','i',[$payId]);
    if (!$a || $a['wallet_credit_entry_id']===null || $a['wallet_funding_result']===null) return 'unknown';
    if ($a['wallet_funding_result']!=='funded') return 'wallet_credited';
    $id=(int)$a['service_operation_id'];
    if ($id<=0) return 'unknown';
    $op=achbotProviderRow($db,'SELECT stage,resolution_kind,kind,quote_payload FROM service_operations WHERE id=?','i',[$id]);
    if (!$op) return 'unknown';
    if ($op['resolution_kind']!==null) return 'resolved';
    if ($op['stage']!=='completed') return 'service_pending';
    $delivery=achbotProviderRow($db,"SELECT COUNT(*) AS total,COALESCE(SUM(state='acknowledged'),0) AS ack
        FROM payment_delivery_intents WHERE service_operation_id=?",'i',[$id]);
    $request=json_decode($op['quote_payload'],true,64,JSON_THROW_ON_ERROR)['request'];
    $expected=$op['kind']==='BUY'?(int)$request['quantity']:1;
    return $expected>0 && (int)$delivery['total']===$expected && (int)$delivery['ack']===$expected?
        'completed':'delivery_unconfirmed';
}
function achbotCheckoutReceiptOutcomeText(string $status): string {
    return achbotMessage(match($status) {
        'wallet_credited'=>'manual_receipt_final.status_wallet_credited',
        'service_pending'=>'manual_receipt_final.status_service_pending',
        'completed'=>'manual_receipt_final.status_completed',
        'delivery_unconfirmed'=>'manual_receipt_final.status_delivery_unconfirmed',
        'resolved'=>'automatic_gateway_review.status_stale',
        default=>'manual_receipt_final.status_unknown',
    });
}

/** Consume the original Wallet callback when the command schema is present.
 * Keep its original callback family, existing alerts and receipt reference. */
function achbotCheckoutWalletCallback(mysqli $db,string $hash,int $actor,string $mode,array $states): void {
    try {
        achbotCheckoutRequireSchema($db);
        $r=achbotCheckoutWallet($db,$hash,$actor,$mode,$states);
        if ($r['status']==='insufficient_funds') {
            $wallet=$r['wallet'];$need=$r['price']-$wallet;
            if (in_array($mode,['standard','custom'],true))
                alert(achbotMessage('wallet.wallet_settlement.process_wallet_payment.alert.ae55877021').
                    achbotMessage('wallet.wallet_settlement.process_wallet_payment.alert.4092b19ef1').number_format($wallet).
                    achbotMessage('wallet.admin_wallet.admin_wallet_report_result.text.81a62ff6c6').
                    achbotMessage('wallet.wallet_settlement.process_wallet_payment.alert.f82b5605a9').number_format($need).
                    achbotMessage('wallet.admin_wallet.admin_wallet_report_result.text.2cc98f7f7c'),true);
            else alert(achbotMessage('renewal.extensions.alert.39c72d8044').number_format($wallet).
                achbotMessage('renewal.extensions.alert.71a2507661').number_format($need).
                achbotMessage('renewal.extensions.text.2cc98f7f7c'),true);
        } elseif ($r['status']==='capacity_unavailable') {
            alert(achbotMessage('wallet.wallet_settlement.process_wallet_payment.alert.0b74ab3f2e'),true);
        } elseif (in_array($r['status'],['funded','already_funded'],true)) {
            // Do not announce success while the durable service is pending.
            if (($r['stage']??null)!=='completed')
                alert(achbotMessage('wallet.wallet_settlement.process_wallet_payment.alert.978c658074'),true);
            achbotCheckoutAdvance($db,(int)$r['operation']);
        } else alert(achbotMessage('wallet.wallet_settlement.process_wallet_payment.alert.978c658074'),true);
    } catch (Throwable $e) {
        error_log('achbot_checkout_wallet_route_deferred class='.get_class($e));
        alert(achbotMessage('wallet.wallet_settlement.process_wallet_payment.alert.978c658074'),true);
    }
}

/** Reject only the original admitted, unpaid receipt. Caller owns the
 * original session lease and SQL transaction; notification packets commit
 * with the decision. No uncertain API consumption can be discarded. */
function achbotCheckoutRejectReceiptLocked(mysqli $db,int $id,int $uid,int $actor,int $primary,string $hash): array {
    achbotCheckoutRequireSchema($db);
    [$pay,$attempt]=achbotCreditInvoiceLocked($db,$id,$uid);
    if (!in_array($pay['state'],['have_sent','service_superseded'],true) || $pay['hash_id']!==$hash ||
        $attempt['wallet_credit_entry_id']!==null ||
        !achbotPayUxManualProvider($attempt['provider']) ||
        !is_string($attempt['wallet_invoice_sha256']) ||
        !hash_equals($attempt['wallet_invoice_sha256'],achbotCreditInvoiceFingerprint($pay,$attempt)) ||
        !achbotAdminCanLocked($db,$actor,$primary,'payments'))
        throw new DomainException('manual_reject_stale');
    achbotCreditValidateManualReceiptLocked($db,$pay,$attempt);
    if (achbotProviderRow($db,"SELECT id FROM payment_transactions WHERE attempt_id=?
        AND evidence_operation IS NOT NULL LIMIT 1 FOR UPDATE",'i',[(int)$attempt['id']]))
        throw new DomainException('manual_reject_original_evidence_pending');
    return achbotCheckoutRejectUnfundedLocked($db,$pay,$attempt,$actor,$primary);
}

/** The same terminal financial decision for every method. Adapter-specific
 * eligibility is checked by the caller under the same owner/invoice locks.
 * A rejected payment cannot fund a service; actual late hosted proof may
 * still credit its original Wallet source, independently of that service. */
function achbotCheckoutRejectUnfundedLocked(mysqli $db,array $pay,array $attempt,int $actor,int $primary): array {
    $id=(int)$pay['id'];$uid=(int)$pay['user_id'];
    if ($attempt['wallet_credit_entry_id']!==null || $attempt['stage']!=='review' ||
        !in_array($pay['state'],['pending','send','have_sent','service_superseded'],true) ||
        !is_string($attempt['wallet_invoice_sha256']) ||
        !hash_equals($attempt['wallet_invoice_sha256'],achbotCreditInvoiceFingerprint($pay,$attempt)) ||
        !achbotAdminCanLocked($db,$actor,$primary,'payments'))
        throw new DomainException('manual_reject_stale');
    $operation=$attempt['service_operation_id']===null?null:(int)$attempt['service_operation_id'];
    if ($operation!==null) {
        [$op]=achbotServiceReadLocked($db,$uid,$operation);
        if ($op['command_kind']==='checkout') {
            achbotCheckoutOriginalCommandLocked($db,$pay);
            if ((int)$pay['checkout_operation_id']!==$operation)
                throw new RuntimeException('manual_reject_original_command_changed');
        }
        achbotServiceCancelUnfundedLocked($db,$uid,$operation);
    } elseif ($pay['type']!=='INCREASE_WALLET') {
        throw new RuntimeException('manual_reject_original_command_missing');
    }
    $s=achbotProviderStatement($db,"UPDATE payment_attempts SET stage='done',provider_status='operator_rejected'
        WHERE id=? AND stage='review' AND wallet_credit_entry_id IS NULL",'i',[(int)$attempt['id']]);
    $n=$s->affected_rows;$s->close();
    if ($n!==1) throw new RuntimeException('manual_reject_cas_failed');
    $s=achbotProviderStatement($db,"UPDATE pays SET state='declined',approved_by=? WHERE id=? AND state=?",
        'iis',[$actor,$id,$pay['state']]);
    $n=$s->affected_rows;$s->close();
    if ($n!==1) throw new RuntimeException('manual_reject_cas_failed');
    $pay['state']='declined';$pay['approved_by']=$actor;
    return $pay;
}

/** Human approval must use the exact persisted receipt and matching old
 * approval callback, not a price or order carried in Telegram data. */
function achbotCheckoutApproveReceipt(mysqli $db,string $hash,int $actor,int $primary,string $mode): array {
    if (!preg_match('/\A[A-Za-z0-9_-]{1,50}\z/D',$hash) ||
        !achbotAdminCanFor($db,$actor,$primary,'payments')) throw new DomainException('manual_receipt_actor_invalid');
    $s=achbotProviderStatement($db,'SELECT id,user_id FROM pays WHERE hash_id=? LIMIT 2','s',[$hash]);
    $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
    if (count($rows)!==1) throw new DomainException('manual_receipt_original_missing');
    $id=(int)$rows[0]['id'];$uid=(int)$rows[0]['user_id'];
    require_once __DIR__.'/GatewayClient.php';
    $lease=achbotOriginalDispatchLeaseBegin($db,$id);
    if (!in_array($lease,['owned','borrowed'],true)) throw new RuntimeException('manual_receipt_worker_busy');
    try {
        if (!$db->begin_transaction()) throw new RuntimeException('manual_receipt_approval_begin');
        try {
            [$pay,$a]=achbotCreditInvoiceLocked($db,$id,$uid);
            if (!achbotAdminCanLocked($db,$actor,$primary,'payments'))
                throw new DomainException('manual_receipt_actor_invalid');
            $valid=achbotCheckoutModeMatchesRequest($pay,$mode);
            if (!$valid || $pay['hash_id']!==$hash || !in_array($pay['state'],['have_sent','service_superseded','paid'],true) ||
                !achbotPayUxManualProvider($a['provider']) ||
                !is_string($a['wallet_invoice_sha256']??null) || $a['wallet_invoice_sha256']==='')
                throw new DomainException('manual_receipt_original_unadmitted');
            $result=achbotCheckoutSettleReceiptLocked($db,$id,$uid,'manual',[],$actor,$primary);
            if (!$db->commit()) throw new RuntimeException('manual_receipt_approval_commit');
            return ['pay_id'=>$id]+$result;
        } catch (Throwable $e) {$db->rollback();throw $e;}
    } finally {achbotOriginalDispatchLeaseEnd($db,$id,$lease);}
}

function achbotCheckoutApprovalCallback(mysqli $db,string $hash,int $actor,int $primary,string $mode): void {
    try {
        achbotCheckoutRequireSchema($db);
        $r=achbotCheckoutApproveReceipt($db,$hash,$actor,$primary,$mode);
        achbotCheckoutAdvance($db,$r['operation'],(int)$r['pay_id']);
    } catch (Throwable $e) {
        error_log('achbot_checkout_approval_deferred class='.get_class($e));
        alert(achbotMessage('manual_payment.manual_receipts.alert.2d252fd96f'),true);
    }
}
