<?php
/**
 * Provider implementations of the same original-payment recovery contract.
 *
 * Finance, staff UI and renewal scheduling never branch on provider names.
 * Each adapter owns only its Native protocol, identity and mutation evidence.
 * No adapter charges money, edits a payment, or sends a customer message.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__.'/../Diagnostics/EventLog.php';

/** Marzban add-ons may resume automatically only from the sealed original
 * absolute Native intent, or from a capture-first claim proving no write began.
 * No second relative increment is authorized by a payment retry.
 */
function achbotMarzbanRecoverEdit(mysqli $db,int $serverId,array $params,
    array $source,bool $operator): object {
    $kind=(string)($source['kind']??'');
    if (!in_array($kind,['day','volume'],true))
        return achbotApplyServiceEdit($db,$serverId,$params);
    if (isset($source['native_intent'])) {
        // The exact first planned absolute result is already committed and
        // sealed to the original invoice. No new relative increment.
        $params['_paid_recovery_intent']=$source['native_intent'];
        return achbotApplyServiceEdit($db,$serverId,$params);
    }
    if (($source['capture_required']??false)!==true ||
        !is_callable($source['_paid_intent_capture']??null))
        return achbotProviderFailure('marzban_original_intent_missing');
    // Crash before original capture: this new-code operation could not have
    // sent any Native mutation. Begin the SAME original additive attempt
    // with capture-first semantics, never derive this from an old fixture.
    $params['_paid_intent_capture']=$source['_paid_intent_capture'];
    return achbotApplyServiceEdit($db,$serverId,$params);
}

/**
 * An unknown acknowledgement does not imply the Native operation failed.
 * Only the original complete baseline permits replay of the saved absolute
 * target. The full expected target may instead be reconciled without a PUT.
 * All other states (including a later operator's independent change) hold.
 */
function achbotPgRecoveryDisposition(array $before,array $target,array $native,
    bool $allowFullRenewReset=false): string {
    foreach (['expire','data_limit','status','group_ids'] as $field)
        if (!array_key_exists($field,$before) ||
            !array_key_exists($field,$native)) return 'drift';
    if (!is_int($before['data_limit']) || !is_int($native['data_limit']) ||
        !($native['expire']===null || is_int($native['expire'])) ||
        !($before['expire']===null || is_int($before['expire'])) ||
        !is_string($native['status']) || !is_string($before['status']) ||
        !is_array($before['group_ids']) || !is_array($native['group_ids']) ||
        !is_int($target['expire']??null) || !is_int($target['data_limit']??null) ||
        $native['group_ids']!==$before['group_ids']) return 'drift';
    $statusOk=$native['status']===$before['status'] ||
        ($native['status']==='active' &&
         in_array($before['status'],['expired','limited'],true));
    if (!$statusOk) return 'drift';
    if ($native['expire']===$target['expire'] &&
        $native['data_limit']===$target['data_limit']) return 'applied';
    // A full RENEW reset may reactivate an expired/limited account before
    // its original absolute PUT completes. Retrying the SAME paid reset
    // is safe; additive operations cannot use this exception.
    if ($native['expire']===$before['expire'] &&
        $native['data_limit']===$before['data_limit'] &&
        ($native['status']===$before['status'] ||
         ($allowFullRenewReset && $native['status']==='active' &&
          in_array($before['status'],['expired','limited'],true))))
        return 'baseline';
    return 'drift';
}

/**
 * Snapshot of the physical Native entitlement that a staff actor explicitly
 * reviewed before overriding an independently changed ORIGINAL operation.
 * Usage is deliberately excluded: legitimate client traffic can increase
 * between the preview and the pre-write GET without changing entitlement.
 */
function achbotPgOperatorEntitlementDigest(array $native): string {
    foreach (['expire','data_limit','status','group_ids'] as $field)
        if (!array_key_exists($field,$native))
            throw new RuntimeException('pg_operator_preview_invalid');
    $view=[
        'expire'=>$native['expire'],
        'data_limit'=>$native['data_limit'],
        'status'=>$native['status'],
        'group_ids'=>$native['group_ids'],
    ];
    if (!is_array($view['group_ids']) ||
        !is_int($view['data_limit']) ||
        !($view['expire']===null || is_int($view['expire'])) ||
        !is_string($view['status']))
        throw new RuntimeException('pg_operator_preview_invalid');
    return hash('sha256',json_encode($view,JSON_THROW_ON_ERROR));
}

/**
 * Originating CREATE/IMPORT rows pin their one local order. Historical
 * migrations keep a NULL origin and admit either an immutable old customer
 * reference OR a later independently proven PasarGuard external attachment.
 * The shared account-by-order validator owns both proofs and rechecks the
 * current owner, Native incarnation, imported origin and provider account.
 * Never rewrite the migration reference list or invent an originating order.
 */
function achbotPgRecoveryOrderOriginMatches(mysqli $db,array $ctx,int $orderId): bool {
    $account=$ctx['account']??null;
    $order=$ctx['order']??null;
    if ($orderId<=0 || !is_array($account) || !is_array($order) ||
        (int)($order['id']??0)!==$orderId ||
        (int)($order['provider_effect_id']??0)!==(int)($account['effect_id']??0))
        return false;
    if ($account['order_id']!==null)
        return (int)$account['order_id']===$orderId;
    try {
        // Already used by achbotPgOrderContext(): includes the immutable
        // migration refs AND modern ACHBOT_SERVICE_ORIGIN PasarGuard imports.
        // Recheck at recovery admission; the returned account must still be
        // precisely the same physical account captured by this payment.
        $validated=achbotProviderAccountByOrder($db,$orderId);
        return $validated!==null && $validated['order_id']===null &&
            $validated['id']===$account['id'] &&
            $validated['effect_id']===$account['effect_id'] &&
            $validated['panel_id']===$account['panel_id'] &&
            $validated['instance_key']===$account['instance_key'] &&
            $validated['endpoint_sha256']===$account['endpoint_sha256'] &&
            $validated['remote_user_id']===$account['remote_user_id'] &&
            $validated['remote_created_at']===$account['remote_created_at'] &&
            $validated['remote_username']===$account['remote_username'] &&
            $validated['operation_marker']===$account['operation_marker'];
    } catch (Throwable $error) {
        return false;
    }
}

/**
 * PasarGuard has a durable account-level mutation fence. Continue its
 * ORIGINAL key, exact account and captured absolute entitlement rather than
 * making a second additive change or freeing an unrelated account's fence.
 */
function achbotPgRecoverEdit(mysqli $db,int $serverId,array $params,
    array $source,bool $operator): object {
    try {
        $payId=(int)($params['pay_id']??0);
        $orderId=(int)($source['order']??0);
        $kind=(string)($source['kind']??'');
        if ($payId<=0 || $serverId<=0 || $orderId<=0 ||
            (string)($params['remark']??'')==='' ||
            !in_array($kind,['renew','standard','day','volume'],true))
            throw new RuntimeException('pg_recovery_not_authorized');
        $key='pay:'.$payId;
        $units=achbotPgEditInputUnits($params);
        $ctx=achbotPgOrderContext($db,$orderId,false,true);
        $account=$ctx['account'];
        // Finance records DISPATCHED before calling this Native adapter. A
        // transient preflight failure can leave that durable finance marker
        // but no account mutation claim. It is not proof of an HTTP write.
        $neverClaimed=$account['state']==='linked' &&
            (string)$account['mutation_key']!==$key;
        // An additive operation with a prior Native claim can change the
        // baseline or encounter permission drift. Keep existing human
        // reconciliation for that class; only a proven never-started claim
        // may recover automatically. Renewals are absolute replacements.
        if (!$operator && !$neverClaimed && in_array($kind,['day','volume'],true))
            throw new RuntimeException('pg_recovery_operator_required');
        if ((int)$ctx['order_server_id']!==$serverId ||
            (string)$ctx['order']['remark']!==(string)$params['remark'] ||
            !achbotPgRecoveryOrderOriginMatches($db,$ctx,$orderId) ||
            !in_array($account['state'],['mutating','linked'],true) ||
            (!$neverClaimed && (string)$account['mutation_key']!==$key))
            throw new RuntimeException('pg_recovery_identity_changed');

        // The original quote pinned this physical identity before the
        // payment was ever claimed. A replaced account or connection is not
        // made recoverable by the administrator's button.
        $q=$db->prepare('SELECT provider_snapshot FROM pays WHERE id=?');
        $q->bind_param('i',$payId);$q->execute();$row=$q->get_result()->fetch_assoc();$q->close();
        $pin=json_decode((string)($row['provider_snapshot']??''),true,64,JSON_THROW_ON_ERROR);
        $target=achbotProviderCanonicalSnapshot($pin);
        if ((int)($pin['provider_account_id']??0)!==(int)$account['id'] ||
            (int)($pin['remote_user_id']??0)!==(int)$account['remote_user_id'] ||
            (string)($pin['remote_created_at']??'')!==(string)$account['remote_created_at'] ||
            (string)($pin['operation_marker']??'')!==(string)$account['operation_marker'] ||
            (string)$target['instance_key']!==(string)$ctx['panel']['instance_key'] ||
            (string)$target['endpoint_sha256']!==(string)$account['endpoint_sha256'])
            throw new RuntimeException('pg_recovery_payment_target_changed');

        if ($neverClaimed) {
            // A previous pay:ID in the Native history means the original
            // effect DID start or finish; never mistake that for pre-dispatch.
            // Re-enter the ordinary source path with the same paid claim,
            // immutable account and Native CAS. It claims the account before
            // any RESET/PUT; neither a new payment nor a second quote is made.
            if (($source['defer_account_finality']??false)!==true ||
                ($source['observe_only']??false)===true ||
                ($source['preview_only']??false)===true ||
                array_key_exists('operator_override_native_digest',$source) ||
                isset($source['native_intent']) ||
                (in_array($kind,['day','volume'],true) &&
                 ($source['capture_required']??false)!==true))
                throw new RuntimeException('pg_recovery_pre_dispatch_invalid');
            achbotPgMutationAdmission($account,$key);
            $first=$params;
            $first['_api_defer_account_finality']=true;
            $response=achbotPgServiceEdit($db,$serverId,$first);
            if (($response->success??false)!==true ||
                ($response->verified??false)!==true ||
                ($response->native_fence_deferred??false)!==true ||
                !is_array($response->observed_group_ids??null))
                throw new RuntimeException('pg_recovery_pre_dispatch_unverified');
            achbotDiagnosticEvent('achbot.native.edit_pre_dispatch_recovered',[
                'pay_id'=>$payId,'order_id'=>$orderId,'server_id'=>$serverId,
                'source'=>$kind,'result'=>'verified'],'info');
            return $response;
        }

        $saved=json_decode((string)($account['mutation_snapshot']??''),true,64,JSON_THROW_ON_ERROR);
        if (!is_array($saved) || ($saved['version']??null)!==1 ||
            ($saved['key']??null)!==$key ||
            ($saved['action']??null)!=='edit' ||
            !in_array($key,$saved['consumed_keys']??[],true) ||
            ($saved['identity']??null)!==$ctx['identity'])
            throw new RuntimeException('pg_recovery_intent_changed');
        $changes=$saved['changes']??[];
        if (($changes['requested_units']??null)!==$units)
            throw new RuntimeException('pg_recovery_units_changed');
        if (($changes['order_expire_before']??(int)$ctx['order']['expire_date'])!== (int)($source['expiry']??0))
            throw new RuntimeException('pg_recovery_order_baseline_changed');

        $read=achbotPgRead($ctx['panel'],$ctx['identity']);
        if (($read['success']??false)!==true || !achbotPgManaged($read['user']))
            throw new RuntimeException('pg_recovery_native_unavailable');
        $native=$read['user'];
        $before=$changes['before']??[];
        $absolute=$changes['absolute']??null;
        // Wallet reconciliation is GET-only. A claimed-but-unsent original
        // operation has no applied result to reconcile and must not acquire
        // a new write intent merely because the operator is authorized.
        if ((($source['observe_only']??false)===true ||
             ($source['preview_only']??false)===true) && !is_array($absolute))
            throw new RuntimeException('pg_recovery_original_result_missing');
        if ($absolute===null && $account['state']==='mutating' &&
            ($changes['phase']??null)==='claimed') {
            // The first worker stopped BEFORE the Native write intent.
            $absolute=achbotPgEditChanges($native,$params);
            $saved=achbotPgPersistMutationIntent($db,$ctx,$saved,[
                'requested_units'=>$units,
                'order_expire_before'=>(int)$ctx['order']['expire_date'],
                'absolute'=>$absolute,'reset_usage'=>array_key_exists('volume',$params),
                'before'=>array_intersect_key($native,array_flip([
                    'data_limit','expire','status','group_ids','used_traffic']))]);
            $changes=$saved['changes'];
            $before=$changes['before'];
        }
        if (!is_array($absolute) || !array_key_exists('expire',$absolute) ||
            !array_key_exists('data_limit',$absolute) ||
            !is_int($absolute['expire']) || !is_int($absolute['data_limit']))
            throw new RuntimeException('pg_recovery_absolute_missing');

        // The existing operator preview is a GET-only diagnostic UI; do not
        // change its classification or action prompts without Owner approval.
        $fullRenewReset=($kind==='renew' &&
            ($source['preview_only']??false)!==true &&
            ($changes['reset_usage']??null)===true &&
            array_key_exists('days',$units) && array_key_exists('volume',$units));
        $disposition=achbotPgRecoveryDisposition($before,$absolute,$native,
            $fullRenewReset);
        achbotDiagnosticEvent('achbot.native.edit_recovery_observed',[
            'pay_id'=>$payId,'order_id'=>$orderId,'server_id'=>$serverId,
            'source'=>$kind,'result'=>$disposition,'phase'=>(string)($changes['phase']??'none'),
            'state'=>(string)$account['state']],$disposition==='drift'?'warning':'info');
        // An operator preview is strictly GET-only and returns only typed
        // entitlement facts, not provider credentials or a mutable journal.
        if (($source['preview_only']??false)===true) {
            if (!$operator || $account['state']!=='mutating' ||
                !in_array($kind,['renew','day','volume'],true))
                throw new RuntimeException('pg_operator_preview_unavailable');
            return (object)['success'=>true,'verified'=>true,'preview'=>true,
                'disposition'=>$disposition,
                'native_expire'=>$native['expire'],
                'native_bytes'=>$native['data_limit'],
                'native_status'=>$native['status'],
                'native_groups'=>$native['group_ids'],
                'target_expire'=>$absolute['expire'],
                'target_bytes'=>$absolute['data_limit'],
                'entitlement_digest'=>achbotPgOperatorEntitlementDigest($native)];
        }
        $operatorDigest=$source['operator_override_native_digest']??null;
        $forceOperator=$disposition==='drift' && $operator &&
            $account['state']==='mutating' &&
            is_string($operatorDigest) &&
            preg_match('/\A[a-f0-9]{64}\z/D',$operatorDigest)===1 &&
            hash_equals($operatorDigest,achbotPgOperatorEntitlementDigest($native)) &&
            ($source['operator_override_target']??null)===[
                'expire'=>$absolute['expire'],
                'data_limit'=>$absolute['data_limit']];
        if ($forceOperator) {
            require_once __DIR__.'/../Admin/Access.php';
            $actor=(int)($source['operator_id']??0);
            $owner=(int)($source['primary_owner_id']??0);
            if ($actor<=0 || $owner<=0 ||
                !achbotAdminCanFor($db,$actor,$owner,'payments'))
                throw new RuntimeException('pg_operator_override_denied');
            achbotDiagnosticEvent('achbot.native.operator_override_requested',[
                'pay_id'=>$payId,'order_id'=>$orderId,'server_id'=>$serverId,
                'actor_id'=>$actor,'source'=>$kind,'result'=>'drift'],'warning');
        }
        // Owner-authorized original Wallet RENEW_ACCOUNT replay is different
        // from an additive reapplication: one debited, fenced account and one
        // previously persisted ABSOLUTE reset target. An unknown Native ACK
        // cannot require a permanent manual unlock. A verified identity,
        // unchanged access groups and an owned mutation key are still required;
        // a separate operator's group/disable change is not our purchase.
        $originalRenewReplay=($source['original_absolute_replay']??false)===true
            && $kind==='renew'
            && $account['state']==='mutating'
            && (string)$account['mutation_key']===$key
            && ($changes['reset_usage']??null)===true
            && $units===['days'=>(int)($params['days']??0),
                        'volume'=>(int)($params['volume']??-1)]
            && is_array($before['group_ids']??null)
            && $native['group_ids']===$before['group_ids']
            && $native['status']!=='disabled';
        if ($originalRenewReplay) {
            $q=$db->prepare("SELECT type,state,user_id,order_id FROM pays WHERE id=?");
            $q->bind_param('i',$payId);$q->execute();
            $originalPay=$q->get_result()->fetch_assoc();$q->close();
            $originalRenewReplay=$originalPay
                && $originalPay['type']==='RENEW_ACCOUNT'
                && $originalPay['state']==='processing_wallet'
                && (int)$originalPay['order_id']===$orderId
                && (int)$originalPay['user_id']===(int)$ctx['order']['userid'];
        }
        if ($disposition==='drift' && !$forceOperator && !$originalRenewReplay)
            throw new RuntimeException('pg_recovery_native_entitlement_drift');
        if ($originalRenewReplay && $disposition!=='applied')
            achbotDiagnosticEvent('achbot.native.original_renew_replay',[
                'pay_id'=>$payId,'order_id'=>$orderId,'server_id'=>$serverId,
                'disposition'=>$disposition,'state'=>$account['state']], 'info');
        // Applied-only continuation must NEVER issue a PUT or RESET, even
        // when an untouched baseline could theoretically be replayed with an
        // absolute target. Require fresh authenticated proof of application.
        if (($source['observe_only']??false)===true && $disposition!=='applied')
            throw new RuntimeException('pg_recovery_original_not_applied');
        if ($account['state']==='linked' &&
            (($saved['result']??null)!=='verified' || $disposition!=='applied'))
            throw new RuntimeException('pg_recovery_already_finished_changed');
        $observeOnly=($source['observe_only']??false)===true;
        if ($account['state']==='mutating') {
            if ($disposition==='baseline' || $forceOperator ||
                ($originalRenewReplay && $disposition==='drift')) {
                // Original financial claim and account fence are held by the
                // caller. This PUT uses a stored ABSOLUTE target, never
                // current + purchased units, so even additive retry does not
                // grant another copy of the original purchase.
                $expectedNative=$native;
                if (($changes['reset_usage']??false)===true ||
                    array_key_exists('volume',$params)) {
                    // RESET performs a second authenticated GET. Enforce the
                    // original persisted baseline at THAT boundary, not only
                    // at the coordinator's first observation.
                    $reset=achbotPgReset($ctx['panel'],$ctx['identity'],$native);
                    if (($reset['success']??false)!==true)
                        throw new RuntimeException('pg_recovery_reset_unverified');
                    $expectedNative=$reset['user']??null;
                    // RESET may naturally reactivate an expired/limited
                    // account, but it cannot authorize a quota/expiry/group
                    // edit or an unrelated disable before the following PUT.
                    if (!is_array($expectedNative) ||
                        $expectedNative['data_limit']!==$native['data_limit'] ||
                        $expectedNative['expire']!==$native['expire'] ||
                        $expectedNative['group_ids']!==$native['group_ids'] ||
                        ($expectedNative['status']!==$native['status'] &&
                         !($expectedNative['status']==='active' &&
                           in_array($native['status'],['expired','limited'],true))))
                        throw new RuntimeException('pg_recovery_reset_entitlement_changed');
                }
                // PUT performs its own fresh authenticated GET. Require the
                // exact state observed after RESET (or initial baseline when
                // there was no RESET) right before the physical write.
                $write=achbotPgModify($ctx['panel'],$ctx['identity'],$absolute,
                    $native['group_ids'],null,$expectedNative);
                if (($write['success']??false)!==true ||
                    $write['user']['group_ids']!==$native['group_ids'])
                    throw new RuntimeException('pg_recovery_native_write_unverified');
                $native=$write['user'];
                if ($native['expire']!==$absolute['expire'] ||
                    $native['data_limit']!==$absolute['data_limit'])
                    throw new RuntimeException('pg_recovery_native_readback_changed');
            }
            // Observation alone does not release the original account fence.
            // Wallet finalizes this journal under the SAME short SQL
            // transaction as its order, audit and payment; a failed local
            // COMMIT must leave provider_state=mutating.
            if (!$observeOnly && ($source['defer_account_finality']??false)!==true)
                achbotPgFinishMutation($db,$ctx,$saved,['success'=>true,'user'=>$native]);
        }
        // GET-only Wallet verification is an observation, not local financial
        // completion. The Wallet coordinator logs finality after its COMMIT.
        if (!$observeOnly)
            achbotDiagnosticEvent('achbot.native.edit_recovery_complete',[
                'pay_id'=>$payId,'order_id'=>$orderId,'server_id'=>$serverId,
                'source'=>$kind,'result'=>'verified'],'info');
        return (object)['success'=>true,'verified'=>true,'uncertain'=>false,
            'applied_expire'=>$native['expire'],'applied_bytes'=>$native['data_limit'],
            // Coordinator finalizes this original account fence INSIDE the
            // payment/order/audit/delivery COMMIT, not after remote PUT.
            'observed_group_ids'=>$observeOnly ||
                ($source['defer_account_finality']??false)===true
                ?$native['group_ids']:null];
    } catch (Throwable $error) {
        $reason=(string)$error->getMessage();
        if (preg_match('/\A(?:pg|provider|addon|effect|service|recovery)_[a-z0-9_]{2,90}\z/D',$reason)!==1)
            $reason='recovery_internal_exception';
        achbotDiagnosticEvent('achbot.native.edit_recovery_deferred',[
            'pay_id'=>(int)($params['pay_id']??0),'server_id'=>$serverId,
            'reason'=>$reason,'class'=>get_class($error)],'warning');
        error_log('achbot_provider_edit_recovery_deferred pay_id='.
            (int)($params['pay_id']??0).' server_id='.$serverId.
            ' code='.$reason.' class='.get_class($error));
        return achbotProviderFailure($reason);
    }
}

/**
 * Finish the original API PasarGuard account mutation in the SAME short
 * transaction that writes the order expiry, increase_order, customer delivery
 * and terminal pays state. Must be called with the original pays row locked.
 * Native HTTP was verified earlier, OUTSIDE any SQL transaction; this
 * function only validates immutable identity/intent and writes local state.
 */
function achbotPgFinishOriginalApiEditLocked(mysqli $db,array $pay,string $kind,
    int $serverId,int $orderId,string $remark,?int $expectedExpiry,
    object $verified,array $fence,bool $finish=true): void {
    $payId=(int)($pay['id']??0);
    if ($payId<=0 || $serverId<=0 || $orderId<=0 ||
        !in_array($kind,['renew','day','volume'],true) ||
        (int)($pay['server_id']??0)!==$serverId ||
        (int)($pay['user_id']??0)<=0 ||
        !is_string($remark) || $remark==='' ||
        !is_int($expectedExpiry) || $expectedExpiry<=0 ||
        !is_array($fence['observed_group_ids']??null))
        throw new RuntimeException('pg_api_original_finish_invalid');
    $tx=$db->query('SELECT @@in_transaction AS active');
    if (!$tx || (int)($tx->fetch_assoc()['active']??0)!==1)
        throw new RuntimeException('pg_api_original_finish_transaction_missing');
    $ctx=achbotPgOrderContext($db,$orderId,true,true);
    $account=$ctx['account'];
    if ((int)$ctx['order_server_id']!==$serverId ||
        (string)$ctx['order']['remark']!==$remark ||
        (int)$ctx['order']['userid']!==(int)$pay['user_id'] ||
        !achbotPgRecoveryOrderOriginMatches($db,$ctx,$orderId) ||
        $account['state']!=='mutating' ||
        (string)$account['mutation_key']!=='pay:'.$payId)
        throw new RuntimeException('pg_api_original_finish_identity_changed');

    $pin=json_decode((string)($pay['provider_snapshot']??''),true,64,JSON_THROW_ON_ERROR);
    $target=achbotProviderCanonicalSnapshot($pin);
    if ((int)($pin['provider_account_id']??0)!==(int)$account['id'] ||
        (int)($pin['remote_user_id']??0)!==(int)$account['remote_user_id'] ||
        (string)($pin['remote_created_at']??'')!==(string)$account['remote_created_at'] ||
        (string)($pin['operation_marker']??'')!==(string)$account['operation_marker'] ||
        (string)$target['instance_key']!==(string)$ctx['panel']['instance_key'] ||
        (string)$target['endpoint_sha256']!==(string)$account['endpoint_sha256'])
        throw new RuntimeException('pg_api_original_finish_target_changed');
    $saved=json_decode((string)($account['mutation_snapshot']??''),true,64,JSON_THROW_ON_ERROR);
    $changes=$saved['changes']??null;
    $key='pay:'.$payId;
    if (!is_array($saved) || ($saved['version']??null)!==1 ||
        ($saved['key']??null)!==$key || ($saved['action']??null)!=='edit' ||
        ($saved['identity']??null)!==$ctx['identity'] ||
        !in_array($key,$saved['consumed_keys']??[],true) ||
        !is_array($changes) || !is_array($changes['before']??null) ||
        !is_array($changes['absolute']??null) ||
        ($changes['order_expire_before']??null)!==$expectedExpiry ||
        ($changes['absolute']['expire']??null)!==($verified->applied_expire??null) ||
        ($changes['absolute']['data_limit']??null)!==($verified->applied_bytes??null) ||
        !is_array($changes['before']['group_ids']??null) ||
        ($changes['before']['group_ids']!==$fence['observed_group_ids'] &&
            ($fence['operator_override_attested']??false)!==true))
        throw new RuntimeException('pg_api_original_finish_intent_changed');
    $units=$changes['requested_units']??null;
    if (!is_array($units) || count($units)<1 ||
        ($kind==='day' && $units!==['plus_day'=>achbotPinnedAddonUnits($pay)]) ||
        ($kind==='volume' && $units!==['plus_volume'=>achbotPinnedAddonUnits($pay)]))
        throw new RuntimeException('pg_api_original_finish_units_changed');

    // This SQL update is rolled back if ANY later finalizer step fails.
    // A lost financial COMMIT acknowledgement can be resolved from pays state;
    // until then no second account edit can replace the held pay:ID key.
    if ($finish)
        achbotPgFinishMutation($db,$ctx,$saved,[
            'success'=>true,
            'user'=>['group_ids'=>$fence['observed_group_ids']]]);
}

/** Marzban stores the paid CREATE journal in common 007 only. */
function achbotMarzbanRecoverRearm(mysqli $db,array $effect): void {
    // No Native-specific fields or second journal to rewrite.
}
/** PasarGuard also owns the native account reservation attached to 007. */
function achbotPgRecoverRearm(mysqli $db,array $effect): void {
    $id=(int)($effect['id']??0);
    if ($id<=0) throw new RuntimeException('pg_recovery_rearm_invalid');
    if ($effect['state']==='reserved') {
        // Crash after the prior COMMIT: the original provider reservation
        // must still exist and be unclaimed, or recovery refuses to POST.
        $q=$db->prepare("SELECT id FROM service_effects
            WHERE id=? AND effect_kind='create' AND state='reserved'
              AND provider_state='reserved' AND provider_reference_id IS NOT NULL
              AND provider_remote_user_id IS NULL LIMIT 1 FOR UPDATE");
        $q->bind_param('i',$id);$q->execute();
        $found=$q->get_result()->num_rows===1;$q->close();
        if (!$found) throw new RuntimeException('pg_recovery_reservation_changed');
        return;
    }
    $q=$db->prepare("UPDATE service_effects SET provider_state='reserved',
        provider_evidence_updated_at=UTC_TIMESTAMP()
        WHERE id=? AND state='reserved' AND provider_state='dispatching'
          AND provider_reference_id IS NOT NULL AND provider_remote_user_id IS NULL");
    $q->bind_param('i',$id);
    if (!$q->execute() || $q->affected_rows!==1)
        throw new RuntimeException('pg_recovery_rearm_unconfirmed');
    $q->close();
}
/** Read-first common CREATE continuation with one staff-authorized retry
 * of the ORIGINAL reserved Native identity, not another account purchase.
 */
function achbotMarzbanRecoverCreate(mysqli $db,int $payId,int $ordinal,
    array $effect,array $request,int $actor,int $primary,
    ?callable $exactAccountGet=null): array {
    try {
        return achbotContinueMarzbanPaidAccount($db,$payId,$ordinal,$effect,
            $exactAccountGet,$actor>0 && $effect['state']==='reserved',
            $actor,$primary);
    } catch (RuntimeException $error) {
        if ($actor<=0 ||
            !in_array($effect['state'],['dispatching','reserved'],true) ||
            $error->getMessage()!=='paid_resume_native_not_proven')
            throw $error;
        return achbotRetryOriginalPaidCreate($db,$payId,$ordinal,$effect,$request,
            $actor,$primary);
    }
}
function achbotPgRecoverCreate(mysqli $db,int $payId,int $ordinal,
    array $effect,array $request,int $actor,int $primary,
    ?callable $exactAccountGet=null): array {
    try {
        return achbotContinuePasarGuardPaidAccount($db,$payId,$ordinal,$effect,
            $actor,$primary);
    } catch (RuntimeException $error) {
        if ($actor<=0 ||
            !in_array($effect['state'],['dispatching','reserved'],true) ||
            $error->getMessage()!=='paid_resume_native_unavailable')
            throw $error;
        return achbotRetryOriginalPaidCreate($db,$payId,$ordinal,$effect,$request,
            $actor,$primary);
    }
}
