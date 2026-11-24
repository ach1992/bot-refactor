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
function achbotCheckoutAdvance(mysqli $db,?int $operation,?int $payId=null): void {
    require_once __DIR__.'/../Services/Execution.php';
    require_once __DIR__.'/ServiceDelivery.php';
    require_once __DIR__.'/FollowupOutbox.php';
    $deadline=hrtime(true)/1000000000+8.0;
    try {
        if ($operation!==null) {
            for ($i=0;$i<16 && hrtime(true)/1000000000<$deadline;$i++) {
                $r=achbotServiceExecuteStep($db,$operation,achbotCheckoutPresentation());
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
                    (pay_row_id IS NOT NULL AND pay_row_id=?))
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

/** Consume the original Wallet callback when the command schema is present.
 * Keep its original callback family, existing alerts and receipt reference. */
function achbotCheckoutWalletCallback(mysqli $db,string $hash,int $actor,string $mode,array $states): bool {
    try {
        if (!achbotCheckoutCommandsPresent($db)) return false;
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
    return true;
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
            $valid=match($mode) {
                'topup'=>$pay['type']==='INCREASE_WALLET',
                'standard'=>$pay['type']==='BUY_SUB' && (int)$pay['day']===0 && (int)$pay['volume']===0,
                'custom'=>$pay['type']==='BUY_SUB' && (int)$pay['day']>0 && (int)$pay['volume']>0,
                'renew'=>$pay['type']==='RENEW_ACCOUNT',
                'day'=>preg_match('/\AINCREASE_DAY_[1-9][0-9]*_[1-9][0-9]*\z/D',$pay['type'])===1,
                'volume'=>preg_match('/\AINCREASE_VOLUME_[1-9][0-9]*_[1-9][0-9]*\z/D',$pay['type'])===1,
                default=>false,
            };
            if (!$valid || $pay['hash_id']!==$hash || !in_array($pay['state'],['have_sent','paid'],true) ||
                !achbotPayUxManualProvider($a['provider']) || ($a['review_archived_at']??null)!==null ||
                !is_string($a['wallet_invoice_sha256']??null) || $a['wallet_invoice_sha256']==='')
                throw new DomainException('manual_receipt_original_unadmitted');
            $result=achbotCheckoutSettleReceiptLocked($db,$id,$uid,'manual',[],$actor,$primary);
            if (!$db->commit()) throw new RuntimeException('manual_receipt_approval_commit');
            return ['pay_id'=>$id]+$result;
        } catch (Throwable $e) {$db->rollback();throw $e;}
    } finally {achbotOriginalDispatchLeaseEnd($db,$id,$lease);}
}

function achbotCheckoutApprovalCallback(mysqli $db,string $hash,int $actor,int $primary,string $mode): bool {
    try {
        if (!achbotCheckoutCommandsPresent($db)) return false;
        $r=achbotCheckoutApproveReceipt($db,$hash,$actor,$primary,$mode);
        achbotCheckoutAdvance($db,$r['operation'],(int)$r['pay_id']);
    } catch (Throwable $e) {
        error_log('achbot_checkout_approval_deferred class='.get_class($e));
        alert(achbotMessage('manual_payment.manual_receipts.alert.2d252fd96f'),true);
    }
    return true;
}
