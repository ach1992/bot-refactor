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
        achbotDiagnosticEvent('achbot.finance.checkout_progress_deferred',[
            'service_operation_id'=>(int)$operation,'pay_id'=>(int)$payId
        ]+achbotDiagnosticLocalFailure($e),'warning');
    }
}

/** One outcome projection for every verified receipt. It never infers
 * a service or delivery result from the fact that money was credited. */
function achbotCheckoutReceiptOutcome(mysqli $db,int $payId): string {
    $locator=achbotProviderRow($db,'SELECT p.user_id FROM pays p JOIN payment_attempts a ON a.pay_row_id=p.id
        WHERE p.id=? AND a.wallet_credit_entry_id IS NOT NULL','i',[$payId]);
    if (!$locator) return 'unknown';
    // The same evidence reader that owns financial reports verifies the
    // credit. Use one local snapshot; never equate a populated FK with money.
    achbotPaymentEvidenceRequireAutocommit($db);
    if (!$db->begin_transaction()) throw new RuntimeException('payment_outcome_begin');
    try {
        $uid=(int)$locator['user_id'];
        $e=achbotReceiptCreditEvidenceLocked($db,$payId,$uid);
        $a=$e['attempt'];
        $status='wallet_credited';
        if ($a['wallet_funding_result']==='funded') {
            $id=(int)$a['service_operation_id'];
            [$op,$quote,$owner]=achbotServiceReadLocked($db,$uid,$id);
            achbotServiceVerifyFundingLocked($db,$op,$owner);
            if ((int)$e['pay']['price']!==(int)$op['price_toman'] || (int)$op['price_toman']<=0 ||
                ($op['command_kind']==='checkout' && (int)$e['pay']['checkout_operation_id']!==$id))
                throw new RuntimeException('payment_outcome_funding_conflict');
            if ($op['resolution_kind']!==null) $status='resolved';
            elseif (in_array($op['stage'],['funded','prepared','dispatching','applied'],true)) $status='service_pending';
            elseif ($op['stage']==='completed') {
                require_once __DIR__.'/ServiceReports.php';
                // Use the same immutable source and part-level acknowledgement
                // proof as completion reports, not a parent status projection.
                $source=achbotServiceCompletionReportSourceLocked($db,$op,$quote,true);
                if ($source['receipt']===null || (int)$source['receipt']['pay']['id']!==$payId)
                    throw new RuntimeException('payment_outcome_original_receipt_conflict');
                $status=$source['delivered']?'completed':'delivery_unconfirmed';
            } else throw new RuntimeException('payment_outcome_service_conflict');
        }
        if (!$db->commit()) throw new RuntimeException('payment_outcome_commit');
        return $status;
    } catch (Throwable $e) {
        $db->rollback();
        $reason=$e->getMessage();
        achbotDiagnosticEvent('achbot.finance.receipt_outcome',['pay_id'=>$payId,'class'=>get_class($e),
            'reason'=>preg_match('/\A(?:service|payment|wallet)_[a-z0-9_]{1,86}\z/D',$reason)?$reason:'payment_outcome_evidence_unavailable'],'warning');
        return 'unknown';
    }
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
        achbotDiagnosticEvent('achbot.finance.checkout_wallet_deferred',[
            'actor_id'=>$actor,'mode'=>$mode]+achbotDiagnosticLocalFailure($e),'warning');
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
        !achbotCurrentReceiptPin($attempt) ||
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
        !in_array($pay['state'],['pending','send','have_sent','expired','service_superseded'],true) ||
        !achbotCurrentReceiptPin($attempt) ||
        !hash_equals($attempt['wallet_invoice_sha256'],achbotCreditInvoiceFingerprint($pay,$attempt)) ||
        !achbotAdminCanLocked($db,$actor,$primary,'payments'))
        throw new DomainException('manual_reject_stale');
    $operation=$attempt['service_operation_id']===null?null:(int)$attempt['service_operation_id'];
    if ($operation!==null) {
        [$op,$quote,$owner]=achbotServiceReadLocked($db,$uid,$operation);
        if ($op['command_kind']==='checkout') {
            achbotCheckoutOriginalCommandLocked($db,$pay);
            if ((int)$pay['checkout_operation_id']!==$operation)
                throw new RuntimeException('manual_reject_original_command_changed');
        }
        // The receipt is unpaid; that does not imply its service is unfunded.
        // A separate original funding source must remain untouched.
        if ($op['resolution_kind']===null && in_array($op['stage'],['awaiting_funds','cancelled'],true))
            achbotServiceCancelUnfundedLocked($db,$uid,$operation);
        else achbotServiceVerifyFundingLocked($db,$op,$owner);
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
        achbotDiagnosticEvent('achbot.finance.checkout_approval_deferred',[
            'actor_id'=>$actor,'mode'=>$mode]+achbotDiagnosticLocalFailure($e),'warning');
        alert(achbotMessage('manual_payment.manual_receipts.alert.2d252fd96f'),true);
    }
}
