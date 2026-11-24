<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/Funding.php';
require_once __DIR__.'/Ownership.php';
require_once __DIR__.'/../Panel/ServiceLifecycle.php';

/** Same existing cycle and discount policy; no payment invoice or second
 * renewal executor. The scheduler receives a durable service operation ID. */
function achbotServiceAutoRenewOrderEligible(?array $o,int $owner): bool {
    return $o!==null && (int)$o['userid']===$owner && (int)$o['status']===1 &&
        (int)$o['inbound_id']===0 && (int)$o['fileid']>0 &&
        (int)$o['notif']!==0 && $o['autoRenew']==='on' && $o['remark']!=='';
}
function achbotServiceAutoRenewUnits($value): ?int {
    // Historical plan columns are FLOAT. Accept their exact integral values;
    // do not silently truncate a malformed/nonintegral entitlement.
    if (is_float($value)) {
        if (!is_finite($value) || $value<0 || $value>achbotServiceVolumeMax() ||
            floor($value)!==$value) return null;
        return (int)$value;
    }
    return achbotServiceNonnegative($value);
}
function achbotServiceAutoRenewAdmit(mysqli $db,int $orderId,int $userId,array $botState): array {
    if (($botState['renewAutoState']??'off')!=='on' || $orderId<=0 || $userId<=0)
        return ['status'=>'ineligible'];
    if ((int)(achbotProviderRow($db,'SELECT @@in_transaction AS tx')['tx']??1)!==0)
        throw new LogicException('service_auto_requires_autocommit');
    $lock=null;
    try {
        $initial=achbotProviderRow($db,'SELECT * FROM orders_list WHERE id=?','i',[$orderId]);
        if (!achbotServiceAutoRenewOrderEligible($initial,$userId)) return ['status'=>'ineligible'];
        $sid=(int)$initial['server_id'];
        if (!achbotServerCommerceAvailable($db,$sid,false)) return ['status'=>'server_renewal_disabled'];
        $adapter=achbotProviderDefinition(achbotProviderType($db,$sid));
        if (!isset($adapter['service_account_lock'],$adapter['service_auto_observe'],$adapter['service_auto_reserve']))
            return ['status'=>'ineligible'];
        $name=($adapter['service_account_lock'])($db,$orderId);
        $held=achbotProviderRow($db,'SELECT GET_LOCK(?,0) AS acquired','s',[$name]);
        if ((int)($held['acquired']??0)!==1) return ['status'=>'account_busy'];
        $lock=$name;
        $old=achbotProviderRow($db,"SELECT id,userid FROM service_operations
            WHERE command_kind='automatic_renewal' AND subject_order_id=?
                AND stage IN ('funded','prepared','dispatching','applied') ORDER BY id DESC LIMIT 1",'i',[$orderId]);
        if ($old) {
            if ((int)$old['userid']!==$userId) throw new RuntimeException('service_auto_cycle_owner');
            return ['status'=>'already_claimed','id'=>(int)$old['id']];
        }
        // All Native I/O happens before locking the owner's money.
        $live=($adapter['service_auto_observe'])($db,$orderId);
        if (!$live['success']) return ['status'=>($live['reason']??'')==='service_edit_account_busy'?'account_busy':'deferred',
            'reason'=>$live['reason']??'service_auto_observation_failed'];
        if (!achbotServiceLifecycle($live['observation'],time())['renew']) return ['status'=>'ineligible'];

        // The provider's current expiry, not the stale local expiry, defines
        // the renewal cycle. A refunded cycle can be retried only after the
        // operator-disabled automatic renewal has been deliberately reenabled.
        $cycle=$orderId.':'.(int)($live['observation']['expire']??0);
        $old=achbotProviderRow($db,"SELECT id,userid,resolution_kind FROM service_operations
            WHERE command_kind='automatic_renewal' AND subject_order_id=? AND
                (command_ref=? OR command_ref LIKE CONCAT(?,':%')) ORDER BY id DESC LIMIT 1",'iss',[$orderId,$cycle,$cycle]);
        if ($old) {
            if ((int)$old['userid']!==$userId) throw new RuntimeException('service_auto_cycle_owner');
            if ($old['resolution_kind']!=='refunded') return ['status'=>'already_claimed','id'=>(int)$old['id']];
            $cycle.=':'.(int)$old['id'];
        }

        if (!$db->begin_transaction()) throw new RuntimeException('service_auto_begin');
        $owner=achbotWalletLockOwnerLocked($db,$userId);
        $o=achbotProviderRow($db,'SELECT * FROM orders_list WHERE id=? FOR UPDATE','i',[$orderId]);
        if (!achbotServiceAutoRenewOrderEligible($o,$userId) ||
            (int)$o['expire_date']!==(int)$initial['expire_date'] ||
            (int)$o['server_id']!==$sid || (int)$o['fileid']!==(int)$initial['fileid']) {
            $db->rollback();return ['status'=>'ineligible'];
        }
        $planId=(int)$o['fileid'];
        $p=achbotProviderRow($db,'SELECT * FROM server_plans WHERE id=? FOR UPDATE','i',[$planId]);
        $amount=achbotServiceNonnegative($p['price']??null);
        $days=achbotServiceAutoRenewUnits($p['days']??null);
        $volume=achbotServiceAutoRenewUnits($p['volume']??null);
        if (!$p || (int)$p['inbound_id']!==0 || $amount===null || $amount<=0 || $amount>2147483647 ||
            $days===null || $days<=0 || $days>3650 || time()>2147483647-$days*86400 ||
            $volume===null || $volume>achbotServiceVolumeMax()) {
            $db->rollback();return ['status'=>'invalid_plan'];
        }
        $u=achbotProviderRow($db,'SELECT is_agent,discount_percent FROM users WHERE id=?','i',[$owner['id']]);
        if ((int)$u['is_agent']===1) {
            $raw=trim((string)$u['discount_percent']);
            $discounts=$raw===''?[]:json_decode($raw,true);
            if (!is_array($discounts)) {$db->rollback();return ['status'=>'invalid_discount'];}
            $normal=$discounts['normal']??0;
            $discount=achbotServiceNonnegative(($botState['agencyPlanDiscount']??'off')==='on'
                ?($discounts['plans'][$planId]??$normal):($discounts['servers'][$sid]??$normal));
            if ($discount===null || $discount>100) {$db->rollback();return ['status'=>'invalid_discount'];}
            $amount-=intdiv($amount*$discount,100);
        }
        $q=achbotServiceQuoteRequest(['kind'=>'RENEW','variant'=>'managed','price'=>$amount,
            'server_id'=>$sid,'plan_id'=>$planId,'order_id'=>$orderId,'quantity'=>1,
            'days'=>$days,'volume'=>$volume,'remark'=>$o['remark'],'uuid'=>(string)$o['uuid'],
            'policy'=>['automatic_renewal'=>['cycle'=>$cycle,'reference'=>'rn_'.bin2hex(random_bytes(16)),
                'wallet_before'=>$owner['wallet'],'wallet_after'=>$owner['wallet']-$amount]]]);
        $local=achbotServiceLocalTargetLocked($db,$userId,$q,true);
        // A stale observation must never authorize a different account.
        if ($local['identity']!==$live['target'] ||
            (int)(achbotProviderRow($db,'SELECT IS_USED_LOCK(?)=CONNECTION_ID() AS owned','s',[$lock])['owned']??0)!==1) {
            $db->rollback();return ['status'=>'deferred','reason'=>'service_auto_target_changed'];
        }
        // Serialize against a concurrent cycle through the physical lease;
        // even if an earlier COMMIT reply was lost, never reissue/reprice.
        $old=achbotProviderRow($db,"SELECT id,userid FROM service_operations
            WHERE command_kind='automatic_renewal' AND command_ref=? FOR UPDATE",'s',[$cycle]);
        if ($old) {
            if ((int)$old['userid']!==$userId) throw new RuntimeException('service_auto_cycle_owner');
            $db->rollback();return ['status'=>'already_claimed','id'=>(int)$old['id']];
        }
        if ($amount>0 && $owner['wallet']<$amount) {
            // A real refused command records this committed disable event.
            // Do not consume the funded-cycle identity: a later deliberate
            // re-enable can renew the same subscription when balance suffices.
            $ref=$cycle.':'.$q['policy']['automatic_renewal']['reference'];
            $refusal=achbotServiceIssueLocked($db,$userId,'automatic_renewal_refusal',$ref,$q);
            achbotServiceCancelUnfundedLocked($db,$userId,$refusal);
            $s=achbotProviderStatement($db,"UPDATE service_operations SET failure_code='wallet_balance_insufficient'
                WHERE id=? AND stage='cancelled'",'i',[$refusal]);$n=$s->affected_rows;$s->close();
            if ($n!==1) throw new RuntimeException('service_auto_refusal_write');
            $s=achbotProviderStatement($db,"UPDATE orders_list SET autoRenew='off' WHERE id=? AND userid=? AND autoRenew='on'",
                'ii',[$orderId,$userId]);$n=$s->affected_rows;$s->close();
            if ($n!==1) throw new RuntimeException('service_auto_disable_unconfirmed');
            [$refused,$original]=achbotServiceReadLocked($db,$userId,$refusal);
            require_once __DIR__.'/../Finance/ServiceReports.php';
            achbotServiceAutoRenewStageLowWalletLocked($db,$refused,$original);
            if (!$db->commit()) throw new RuntimeException('service_auto_disable_unconfirmed');
            return ['status'=>'insufficient_funds','user_id'=>$userId,'remark'=>$o['remark'],
                'amount'=>$amount,'wallet'=>$owner['wallet']];
        }
        $id=achbotServiceIssueLocked($db,$userId,'automatic_renewal',$cycle,$q);
        if (achbotServiceFundLocked($db,$userId,$id)!=='funded')
            throw new RuntimeException('service_auto_funding_refused');
        [$op,$quote]=achbotServiceReadLocked($db,$userId,$id);
        // Reserve the real account in this same commit. A second alias can
        // neither charge itself nor start a competing native renewal.
        ($adapter['service_auto_reserve'])($db,$op,$quote,$live);
        if (!$db->commit()) throw new RuntimeException('service_auto_commit_unconfirmed');
        return ['status'=>'funded','id'=>$id];
    } catch (Throwable $e) {
        try {$db->rollback();} catch (Throwable $ignored) {}
        $reason=$e instanceof DomainException || ($e instanceof RuntimeException &&
            preg_match('/^(?:service|provider|pg)_[a-z0-9_]+$/D',$e->getMessage()))?
            $e->getMessage():'service_auto_local_failed';
        achbotDiagnosticEvent('achbot.service.auto_renewal',['order_id'=>$orderId,'reason'=>$reason,
            'class'=>get_class($e)],'error');
        return ['status'=>'deferred','reason'=>$reason];
    } finally {
        if ($lock!==null) {
            try {$s=achbotProviderStatement($db,'SELECT RELEASE_LOCK(?)','s',[$lock]);$s->close();}
            catch (Throwable $ignored) {}
        }
    }
}
