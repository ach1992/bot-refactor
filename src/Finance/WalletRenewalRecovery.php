<?php
/**
 * #227: finish an ORIGINAL, already debited PasarGuard wallet renewal.
 *
 * Native GET is outside SQL; this coordinator never debits the wallet.
 * On an incomplete/unknown original absolute renewal, it can replay the
 * ORIGINAL pinned RESET/PUT while holding the original payment lease.
 * A verified original native absolute renewal,
 * the original local order, audit, payment and one-shot notification intents
 * are committed together. An unresolved effect stays in operator review.
 */
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {
    http_response_code(404); exit;
}
require_once __DIR__.'/../Panel/ProviderRuntime.php';
require_once __DIR__.'/../Admin/Access.php';
require_once __DIR__.'/ReceiptHelpers.php';
require_once __DIR__.'/ServiceEffects.php';
require_once __DIR__.'/WalletAddonNotices.php';

/** Read the exact immutable original request; never infer a new entitlement. */
function achbotWalletPgRenewOriginal(mysqli $db,int $payId,bool $locked=false): array {
    if ($payId<=0) throw new RuntimeException('wallet_renew_recovery_payment_invalid');
    $q=$db->prepare('SELECT * FROM pays WHERE id=?'.($locked?' FOR UPDATE':''));
    $q->bind_param('i',$payId);$q->execute();
    $pay=$q->get_result()->fetch_assoc();$q->close();
    $automatic=$pay && (string)($pay['description']??'')==='automatic_renewal';
    if (!$pay || $pay['type']!=='RENEW_ACCOUNT' ||
        $pay['state']!=='processing_wallet' ||
        (int)$pay['user_id']<=0 ||
        (int)$pay['price']<($automatic?0:1) ||
        (int)$pay['order_id']<=0 || (int)$pay['plan_id']<=0 ||
        (int)$pay['server_id']<=0 || (string)($pay['payid']??'')!=='')
        throw new RuntimeException('wallet_renew_recovery_claim_invalid');

    $auto=null;
    if ($automatic) {
        // An auto-renew claim owns TWO financial lifecycle records. Never
        // settle pays alone and leave the cycle in the permanent 'review'
        // fence (nor synthesize a cycle from an old manual Wallet payment).
        $q=$db->prepare('SELECT id,order_id,cycle_expire,pay_row_id,user_id,
            plan_id,server_id,account_remark,price_toman,duration_days,
            volume_gb,new_expire,stage FROM automatic_renewal_attempts
            WHERE pay_row_id=?'.($locked?' FOR UPDATE':''));
        $q->bind_param('i',$payId);$q->execute();
        $rows=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
        $auto=count($rows)===1?$rows[0]:null;
        if (!$auto || !in_array((string)$auto['stage'],
                ['remote_started','review'],true) ||
            (int)$auto['order_id']!==(int)$pay['order_id'] ||
            (int)$auto['pay_row_id']!==$payId ||
            (int)$auto['user_id']!==(int)$pay['user_id'] ||
            (int)$auto['plan_id']!==(int)$pay['plan_id'] ||
            (int)$auto['server_id']!==(int)$pay['server_id'] ||
            (int)$auto['price_toman']!==(int)$pay['price'] ||
            (int)$auto['duration_days']!==(int)$pay['day'] ||
            (int)$auto['volume_gb']!==(int)$pay['volume'] ||
            (int)$auto['duration_days']<=0 ||
            (int)$auto['volume_gb']<0 ||
            (int)$auto['new_expire']<=0)
            throw new RuntimeException('wallet_renew_recovery_auto_cycle_changed');
    }

    $ctx=achbotPgOrderContext($db,(int)$pay['order_id'],$locked,true);
    $order=$ctx['order'];$account=$ctx['account'];$key='pay:'.$payId;
    if ((int)$order['id']!==(int)$pay['order_id'] ||
        (int)$order['userid']!==(int)$pay['user_id'] ||
        (int)$order['fileid']!==(int)$pay['plan_id'] ||
        (int)$order['server_id']!==(int)$pay['server_id'] ||
        (int)$order['inbound_id']!==0 || (int)$order['status']!==1 ||
        (string)$order['remark']==='' ||
        ($automatic && ((int)$auto['cycle_expire']!==(int)$order['expire_date'] ||
            (string)$auto['account_remark']!==(string)$order['remark'])) ||
        !in_array((string)$account['state'],['mutating','linked'],true) ||
        (string)($account['mutation_key']??'')!==$key ||
        !achbotPgRecoveryOrderOriginMatches($db,$ctx,(int)$order['id']))
        throw new RuntimeException('wallet_renew_recovery_identity_changed');

    $pin=json_decode((string)($pay['provider_snapshot']??''),true,64,JSON_THROW_ON_ERROR);
    if (!is_array($pin) || ($pin['provider']??null)!=='pasarguard' ||
        (int)($pin['provider_account_id']??0)!==(int)$account['id'] ||
        (int)($pin['remote_user_id']??0)!==(int)$account['remote_user_id'] ||
        (string)($pin['remote_created_at']??'')!==(string)$account['remote_created_at'] ||
        (string)($pin['operation_marker']??'')!==(string)$account['operation_marker'] ||
        (int)($pin['panel_id']??0)!==(int)$ctx['panel']['id'] ||
        (string)($pin['instance_key']??'')!==(string)$ctx['panel']['instance_key'] ||
        (string)($pin['endpoint_sha256']??'')!==(string)$account['endpoint_sha256'])
        throw new RuntimeException('wallet_renew_recovery_quote_changed');
    foreach ($ctx['identity'] as $field=>$value)
        if ((string)($pin[$field]??'')!==(string)$value)
            throw new RuntimeException('wallet_renew_recovery_native_changed');

    // Use only the ORIGINAL, durable Native intent for its purchased units.
    // The current plan row is an editable catalog item, not a historical
    // entitlement; changing a price/volume after debit must not block
    // reconciliation or silently change the old customer's settlement.
    $proof=json_decode((string)($account['mutation_snapshot']??''),true,64,JSON_THROW_ON_ERROR);
    $change=$proof['changes']??null;$absolute=$change['absolute']??null;
    $units=is_array($change)?($change['requested_units']??null):null;
    $days=is_array($units)?($units['days']??null):null;
    $volume=is_array($units)?($units['volume']??null):null;
    if (!is_array($units) || count($units)!==2 ||
        !is_int($days) || $days<=0 || $days>36500 ||
        !is_int($volume) || $volume<0 || $volume>achbotServiceVolumeMax())
        throw new RuntimeException('wallet_renew_recovery_original_units_invalid');
    if ($automatic && ((int)$auto['duration_days']!==$days ||
        (int)$auto['volume_gb']!==$volume))
        throw new RuntimeException('wallet_renew_recovery_auto_units_changed');
    // The original worker may have crashed after the durable Native account
    // claim but BEFORE its first GET, absolute target capture, RESET or PUT.
    // In that exact phase the sealed requested_units already belong to this
    // debited invoice; the provider can capture the target on its first safe
    // continuation, without taking another Wallet debit or inventing units.
    $preWriteClaim=$account['state']==='mutating' &&
        is_array($change) && count($change)===2 &&
        ($change['phase']??null)==='claimed' &&
        array_key_exists('requested_units',$change) &&
        ($proof['result']??null)===null &&
        ($proof['verified_at']??null)===null;
    if (!is_array($proof) || ($proof['version']??null)!==1 ||
        ($proof['key']??null)!==$key || ($proof['action']??null)!=='edit' ||
        !in_array($key,$proof['consumed_keys']??[],true) ||
        ($proof['identity']??null)!==$ctx['identity'] ||
        !is_array($change) ||
        ($change['requested_units']??null)!==['days'=>$days,'volume'=>$volume] ||
        (!$preWriteClaim && (
            ($change['reset_usage']??null)!==true ||
            ($change['order_expire_before']??null)!==(int)$order['expire_date'] ||
            !is_array($change['before']??null) ||
            !is_array($change['before']['group_ids']??null) ||
            !is_array($absolute) ||
            !is_int($absolute['expire']??null) ||
            $absolute['expire']<=0 || $absolute['expire']>2147483647 ||
            !is_int($absolute['data_limit']??null) ||
            $absolute['data_limit']!==1073741824*$volume)) ||
        ($account['state']==='linked' &&
            (($proof['result']??null)!=='verified' ||
             !is_string($proof['verified_at']??null) ||
             ($proof['current_group_ids']??null)!==$change['before']['group_ids'])))
        throw new RuntimeException('wallet_renew_recovery_original_intent_invalid');

    $fingerprint=hash('sha256',json_encode([
        'pay'=>array_intersect_key($pay,array_flip([
            'id','hash_id','state','user_id','type','order_id','plan_id',
            'server_id','price','day','volume','description','payid','provider_snapshot'])),
        'order'=>$order,
        'account'=>[$account['id'],$account['state'],$account['mutation_key'],
            $account['mutation_snapshot'],$account['remote_user_id'],
            $account['remote_created_at'],$account['operation_marker']],
        'original_plan_id'=>(int)$pay['plan_id'],
        'automatic_cycle'=>$auto?[(int)$auto['id'],(int)$auto['cycle_expire'],
            (int)$auto['duration_days'],(int)$auto['volume_gb']]:null,
        'original_units'=>[$days,$volume],
        'identity'=>$ctx['identity'],
        'panel'=>[$ctx['panel']['id'],$ctx['panel']['instance_key'],$ctx['panel']['api_url']]
    ],JSON_THROW_ON_ERROR));
    return ['fingerprint'=>$fingerprint,'pay'=>$pay,'order'=>$order,
        'account'=>$account,'ctx'=>$ctx,'days'=>$days,'volume'=>$volume,
        'absolute'=>$absolute,'order_id'=>(int)$order['id'],
        'automatic_attempt'=>$auto,
        'kind'=>'renew','units'=>$days];
}

/** Provider GET only; no SQL/remote mutation and no financial finality claim. */
function achbotWalletPgRenewObserved(mysqli $db,array $original): ?object {
    // There cannot be an applied absolute result before the first pre-write
    // intent was captured. Recovery, not GET-only finality, owns that phase.
    if ($original['absolute']===null) return null;
    $id=(int)$original['pay']['id'];
    $params=['pay_id'=>$id,'remark'=>(string)$original['order']['remark'],
        'days'=>$original['days'],'volume'=>$original['volume']];
    $source=['kind'=>'renew','order'=>$original['order_id'],
        'expiry'=>(int)$original['order']['expire_date'],'observe_only'=>true];
    $remote=achbotPgRecoverEdit($db,(int)$original['order']['server_id'],$params,$source,true);
    if (($remote->success??false)!==true || ($remote->verified??false)!==true ||
        !is_int($remote->applied_expire??null) ||
        !is_int($remote->applied_bytes??null) ||
        $remote->applied_expire!==$original['absolute']['expire'] ||
        $remote->applied_bytes!==$original['absolute']['data_limit'] ||
        !is_array($remote->observed_group_ids??null)) {
        return null;
    }
    $proof=json_decode((string)$original['account']['mutation_snapshot'],true,64,JSON_THROW_ON_ERROR);
    if ($remote->observed_group_ids!==$proof['changes']['before']['group_ids'])
        return null;
    return $remote;
}

/** An operator may preview the ORIGINAL stored absolute reset target only.
 * A pre-existing native mutation fence and exact immutable account/quote
 * are mandatory. This does not reset consumption or write Native data.
 */
function achbotWalletPgRenewRetryPreview(mysqli $db,array $original): ?object {
    if ($original['account']['state']!=='mutating' ||
        $original['absolute']===null) return null;
    $id=(int)$original['pay']['id'];
    $params=['pay_id'=>$id,'remark'=>(string)$original['order']['remark'],
        'days'=>$original['days'],'volume'=>$original['volume']];
    $source=['kind'=>'renew','order'=>$original['order_id'],
        'expiry'=>(int)$original['order']['expire_date'],'preview_only'=>true];
    $preview=achbotPgRecoverEdit($db,(int)$original['order']['server_id'],
        $params,$source,true);
    if (($preview->success??false)!==true ||
        ($preview->preview??false)!==true ||
        ($preview->verified??false)!==true ||
        !in_array($preview->disposition??null,['baseline','drift'],true) ||
        !is_string($preview->entitlement_digest??null) ||
        preg_match('/^[a-f0-9]{64}$/D',$preview->entitlement_digest)!==1 ||
        ($preview->target_expire??null)!==$original['absolute']['expire'] ||
        ($preview->target_bytes??null)!==$original['absolute']['data_limit'])
        return null;
    return $preview;
}

/** Staff's actor-bound confirmation also pins the observed Native digest. */
function achbotWalletPgRenewRetryToken(array $claim,int $actor,string $digest): string {
    if ($actor<=0 || (int)($claim['pay']['id']??0)<=0 ||
        !is_string($claim['fingerprint']??null) ||
        preg_match('/^[a-f0-9]{64}$/D',$digest)!==1)
        throw new RuntimeException('wallet_renew_retry_confirmation_invalid');
    return substr(hash_hmac('sha256',
        'wallet-renew-retry:v1:'.(int)$claim['pay']['id'].':'.
        $actor.':'.$claim['fingerprint'].':'.$digest,
        achbotPaymentEvidenceKey()),0,20);
}

/**
 * Independent live evidence for an operator preview. This never changes
 * Native, finance, SQL journals or Telegram. The completion callback rechecks
 * everything and takes the original worker lease; preview is not authority.
 */
function achbotWalletPgRenewInspect(mysqli $db,int $payId,int $actor,int $primary): array {
    if ($payId<=0 || !achbotAdminCanFor($db,$actor,$primary,'payments'))
        return ['status'=>'denied'];
    try {
        if (achbotGatewayOriginalArchived($db,$payId))
            return ['status'=>'archived','pay_id'=>$payId];
        $original=achbotWalletPgRenewOriginal($db,$payId);
        $remote=achbotWalletPgRenewObserved($db,$original);
        $retry=$remote===null?achbotWalletPgRenewRetryPreview($db,$original):null;
        return ['status'=>$remote?'applied':($retry?'retryable':
                ($original['account']['state']==='mutating'?'retry_pending':'review')),
            'pay_id'=>$payId,'order_id'=>$original['order_id'],
            'disposition'=>$retry->disposition??null,
            'native_expire'=>$retry->native_expire??null,
            'native_bytes'=>$retry->native_bytes??null,
            'native_status'=>$retry->native_status??null,
            'native_groups'=>$retry->native_groups??null,
            'target_expire'=>$retry->target_expire??null,
            'target_bytes'=>$retry->target_bytes??null,
            'entitlement_digest'=>$retry->entitlement_digest??null];
    } catch (Throwable $error) {
        achbotDiagnosticEvent('achbot.finance.wallet_renew_inspection_deferred',[
            'pay_id'=>$payId,'actor_id'=>$actor,
            'reason'=>preg_match('/^wallet_renew_recovery_[a-z0-9_]+$/D',
                $error->getMessage())?$error->getMessage():'native_or_runtime_unverified'],
            'warning');
        return ['status'=>'review','pay_id'=>$payId];
    }
}

/** A private, actor-bound one-time confirmation of the current original state. */
function achbotWalletPgRenewConfirmationToken(array $claim,int $actor): string {
    if ($actor<=0 || (int)($claim['pay']['id']??0)<=0 ||
        !is_string($claim['fingerprint']??null))
        throw new RuntimeException('wallet_renew_confirmation_invalid');
    return substr(hash_hmac('sha256',
        'wallet-renew-reconcile:v1:'.(int)$claim['pay']['id'].':'.
            $actor.':'.$claim['fingerprint'],
        achbotPaymentEvidenceKey()),0,20);
}

/** Applied-only, originally-debited recovery: staff and Cron share one core. */
function achbotWalletPgResumeRenew(mysqli $db,int $payId,int $actor,int $primary): array {
    return achbotWalletPgResumeRenewCore($db,$payId,$actor,$primary,false);
}
function achbotWalletPgResumeRenewAppliedSystem(mysqli $db,int $payId): array {
    return achbotWalletPgResumeRenewCore($db,$payId,0,0,true);
}
function achbotWalletPgRetryOriginalRenew(mysqli $db,int $payId,int $actor,
    int $primary,string $confirmation): array {
    return achbotWalletPgResumeRenewCore($db,$payId,$actor,$primary,false,$confirmation);
}
function achbotWalletPgResumeRenewCore(mysqli $db,int $payId,int $actor,int $primary,
                                       bool $system,?string $retryToken=null): array {
    if ($payId<=0 || ($system?($actor!==0 || $primary!==0):
        ($actor<=0 || $primary<=0 ||
         !achbotAdminCanFor($db,$actor,$primary,'payments'))))
        return ['status'=>'denied','pay_id'=>$payId];
    $lease=achbotOriginalDispatchLeaseBegin($db,$payId);
    if ($lease!=='owned' && $lease!=='borrowed')
        return ['status'=>$lease,'pay_id'=>$payId];
    $begun=false;
    try {
        // Archive and recovery share the original session lease. Check the
        // terminal LOCAL fence BEFORE a Native GET/RESET/PUT, not just inside
        // the later financial COMMIT, so old callbacks cannot restart work.
        if (achbotGatewayOriginalArchived($db,$payId))
            return ['status'=>'archived','pay_id'=>$payId];
        $s=$db->prepare('SELECT type,state FROM pays WHERE id=?');
        $s->bind_param('i',$payId);$s->execute();
        $previous=$s->get_result()->fetch_assoc();$s->close();
        if ($previous && $previous['type']==='RENEW_ACCOUNT' &&
            $previous['state']==='paid_with_wallet')
            return ['status'=>'already_paid_state','pay_id'=>$payId];
        if (!achbotWalletAddonNoticesReady($db))
            throw new RuntimeException('wallet_renew_recovery_delivery_unavailable');
        $before=achbotWalletPgRenewOriginal($db,$payId);
        if ($retryToken!==null) {
            // A user-visible private confirmation of EXACTLY the observed
            // original account, not a generic retry of a wallet payment.
            if ($system || preg_match('/^[a-f0-9]{20}$/D',$retryToken)!==1)
                throw new RuntimeException('wallet_renew_recovery_retry_invalid');
            $preview=achbotWalletPgRenewRetryPreview($db,$before);
            if (!$preview || !hash_equals(
                achbotWalletPgRenewRetryToken($before,$actor,
                    $preview->entitlement_digest),$retryToken))
                throw new RuntimeException('wallet_renew_recovery_retry_stale');
            $params=['pay_id'=>$payId,'remark'=>(string)$before['order']['remark'],
                'days'=>$before['days'],'volume'=>$before['volume']];
            $source=['kind'=>'renew','order'=>$before['order_id'],
                'expiry'=>(int)$before['order']['expire_date'],
                'defer_account_finality'=>true];
            if ($preview->disposition==='drift') {
                $source['operator_override_native_digest']=$preview->entitlement_digest;
                $source['operator_override_target']=[
                    'expire'=>$before['absolute']['expire'],
                    'data_limit'=>$before['absolute']['data_limit']];
                $source['operator_id']=$actor;
                $source['primary_owner_id']=$primary;
            }
            // Provider re-observes before RESET and again before PUT. Native
            // absolute values come from the original journal; no new payment
            // or additive units are generated. Keep its fence until COMMIT.
            $remote=achbotPgRecoverEdit($db,(int)$before['order']['server_id'],
                $params,$source,true);
            if (($remote->success??false)!==true ||
                ($remote->verified??false)!==true ||
                !is_array($remote->observed_group_ids??null))
                throw new RuntimeException('wallet_renew_recovery_native_retry_unverified');
            // A captured pre-dispatch intent may have added immutable journal
            // evidence without changing the original financial identity.
            $before=achbotWalletPgRenewOriginal($db,$payId);
        } else {
            $remote=achbotWalletPgRenewObserved($db,$before);
            if ($remote===null && $before['account']['state']==='mutating') {
                // GET-only finality first; then retry EXACTLY this held
                // original absolute RESET/PUT. A Native outage defers the
                // same claim to a future Cron pass, not a second charge.
                $params=['pay_id'=>$payId,
                    'remark'=>(string)$before['order']['remark'],
                    'days'=>$before['days'],'volume'=>$before['volume']];
                $source=['kind'=>'renew','order'=>$before['order_id'],
                    'expiry'=>(int)$before['order']['expire_date'],
                    'defer_account_finality'=>true,
                    'original_absolute_replay'=>true];
                $remote=achbotPgRecoverEdit($db,(int)$before['order']['server_id'],
                    $params,$source,true);
                if (($remote->success??false)===true &&
                    ($remote->verified??false)===true)
                    $before=achbotWalletPgRenewOriginal($db,$payId);
            }
        }
        if (($remote->success??false)!==true ||
            ($remote->verified??false)!==true ||
            ($remote->applied_expire??null)!==$before['absolute']['expire'] ||
            ($remote->applied_bytes??null)!==$before['absolute']['data_limit'] ||
            !is_array($remote->observed_group_ids??null))
            throw new RuntimeException('wallet_renew_recovery_native_not_applied');
        if (!$db->begin_transaction())
            throw new RuntimeException('wallet_renew_recovery_begin');
        $begun=true;
        $current=achbotWalletPgRenewOriginal($db,$payId,true);
        if (!hash_equals($before['fingerprint'],$current['fingerprint']))
            throw new RuntimeException('wallet_renew_recovery_original_changed');
        if (!$system && !achbotAdminCanLocked($db,$actor,$primary,'payments'))
            throw new RuntimeException('wallet_renew_recovery_operator_denied');
        achbotEffectAssertNotArchivedLocked($db,$payId);
        if ($current['account']['state']==='mutating') {
            $proof=json_decode((string)$current['account']['mutation_snapshot'],
                true,64,JSON_THROW_ON_ERROR);
            achbotPgFinishMutation($db,$current['ctx'],$proof,[
                'success'=>true,'user'=>['group_ids'=>$remote->observed_group_ids]]);
        }
        achbotStoreVerifiedServiceExpiryLocked($db,$current['order'],$remote);
        $uid=(int)$current['pay']['user_id'];
        $sid=(int)$current['order']['server_id'];
        $inbound=(int)$current['order']['inbound_id'];
        $remark=(string)$current['order']['remark'];
        $price=(int)$current['pay']['price'];$now=time();
        $q=$db->prepare('INSERT INTO increase_order
            (userid,server_id,inbound_id,remark,amount,date)
            VALUES (?,?,?,?,?,?)');
        $q->bind_param('iiisii',$uid,$sid,$inbound,$remark,$price,$now);
        if (!$q->execute() || $q->affected_rows!==1)
            throw new RuntimeException('wallet_renew_recovery_audit_unconfirmed');
        $q->close();
        $hash=(string)$current['pay']['hash_id'];
        $q=$db->prepare("UPDATE pays SET state='paid_with_wallet'
            WHERE id=? AND type='RENEW_ACCOUNT' AND user_id=?
              AND hash_id=? AND price=? AND state='processing_wallet'");
        $q->bind_param('iisi',$payId,$uid,$hash,$price);
        if (!$q->execute() || $q->affected_rows!==1)
            throw new RuntimeException('wallet_renew_recovery_payment_unconfirmed');
        $q->close();
        if ($current['automatic_attempt']!==null) {
            // Close the exact captured automatic cycle, not just pays. A
            // pending cycle otherwise blocks every subsequent auto-renewal.
            $autoId=(int)$current['automatic_attempt']['id'];
            $q=$db->prepare("UPDATE automatic_renewal_attempts
                SET stage='done' WHERE id=? AND pay_row_id=?
                AND stage IN ('remote_started','review')");
            $q->bind_param('ii',$autoId,$payId);
            if (!$q->execute() || $q->affected_rows!==1)
                throw new RuntimeException('wallet_renew_recovery_auto_finality_unconfirmed');
            $q->close();
            $q=$db->prepare('UPDATE orders_list SET notif=0
                WHERE id=? AND userid=? AND notif<>0');
            $orderId=$current['order_id'];
            $q->bind_param('ii',$orderId,$uid);
            if (!$q->execute())
                throw new RuntimeException('wallet_renew_recovery_auto_order_flag_unconfirmed');
            $q->close();
        }
        achbotWalletAddonMarkRecoveryNoticesLocked($db,$current);
        if ($db->commit()!==true)
            throw new RuntimeException('wallet_renew_recovery_commit_unknown');
        $begun=false;
        achbotDiagnosticEvent('achbot.finance.wallet_original_renew_reconciled',[
            'pay_id'=>$payId,'order_id'=>$current['order_id'],
            'actor_id'=>$actor,'result'=>'paid_with_wallet',
            'delivery'=>'first_send_pending'],'info');
        return ['status'=>'settled_delivery_pending',
            'pay_id'=>$payId,'order_id'=>$current['order_id']];
    } catch (Throwable $error) {
        if ($begun) try {$db->rollback();} catch (Throwable $ignored) {}
        $reason=(string)$error->getMessage();
        if (preg_match('/^wallet_renew_recovery_[a-z0-9_]{2,100}$/D',$reason)!==1)
            $reason=$error instanceof mysqli_sql_exception
                ?'database_'.$error->getSqlState():'native_or_runtime_unverified';
        achbotDiagnosticEvent('achbot.finance.wallet_original_renew_deferred',[
            'pay_id'=>$payId,'actor_id'=>$actor,
            'reason'=>$reason,'class'=>get_class($error)],'warning');
        return ['status'=>'review','pay_id'=>$payId,'reason'=>$reason];
    } finally {
        achbotOriginalDispatchLeaseEnd($db,$payId,$lease);
    }
}

/**
 * System recovery only for an original, already started Native edit. An old
 * wallet payment whose original key is absent on the account stays in inbox.
 * The original absolute PasarGuard renewal can be replayed after Native
 * uncertainty, but never the wallet debit, additive edit or Telegram send.
 */
function achbotWalletPgRenewMaintain(mysqli $db,int $batch=4): array {
    $stats=['considered'=>0,'settled'=>0,'review'=>0];
    $batch=max(1,min(16,$batch));$cutoff=time()-180;
    try {
        $q=$db->prepare("SELECT p.id FROM pays p
            JOIN orders_list o ON o.id=p.order_id
            JOIN service_effects e ON e.provider_reference_id=o.provider_effect_id
            LEFT JOIN payment_attempts a ON a.pay_row_id=p.id
            WHERE p.state='processing_wallet' AND p.type='RENEW_ACCOUNT'
              AND a.review_archived_at IS NULL
              AND p.request_date<=?
              AND (p.updated_at IS NULL OR p.updated_at<=DATE_SUB(NOW(),INTERVAL 2 MINUTE))
              AND e.provider_state IN ('mutating','linked')
              AND e.provider_mutation_key=CONCAT('pay:',p.id)
            ORDER BY p.id LIMIT ?");
        $q->bind_param('ii',$cutoff,$batch);$q->execute();
        $rows=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
    } catch (Throwable $error) {
        achbotDiagnosticEvent('achbot.finance.wallet_renew_scan_deferred',[
            'class'=>get_class($error)],'warning');
        return $stats;
    }
    foreach ($rows as $row) {
        $payId=(int)$row['id'];$stats['considered']++;
        $outcome=achbotWalletPgResumeRenewAppliedSystem($db,$payId);
        if (($outcome['status']??null)==='settled_delivery_pending')
            $stats['settled']++;
        elseif (($outcome['status']??null)!=='already_paid_state') {
            $stats['review']++;
            $q=$db->prepare("UPDATE pays SET updated_at=NOW()
                WHERE id=? AND state='processing_wallet'");
            $q->bind_param('i',$payId);$q->execute();$q->close();
        }
    }
    return $stats;
}
