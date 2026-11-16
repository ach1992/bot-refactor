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

/** Marzban edits have no local Native mutation journal: only an explicit
 * operator may repeat additive changes. Renewal sets an absolute plan, so
 * automatic re-application is allowed after the original financial claim.
 */
function achbotMarzbanRecoverEdit(mysqli $db,int $serverId,array $params,
    array $source,bool $operator): object {
    $kind=(string)($source['kind']??'');
    if (!$operator && !in_array($kind,['renew','standard'],true))
        return achbotProviderFailure('recovery_operator_required',false);
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
function achbotPgRecoveryDisposition(array $before,array $target,array $native): string {
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
    if ($native['expire']===$before['expire'] &&
        $native['data_limit']===$before['data_limit'] &&
        $native['status']===$before['status']) return 'baseline';
    return 'drift';
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
            !$operator && !in_array($kind,['renew','standard'],true))
            throw new RuntimeException('pg_recovery_not_authorized');
        $key='pay:'.$payId;
        $units=achbotPgEditInputUnits($params);
        $ctx=achbotPgOrderContext($db,$orderId,false,true);
        $account=$ctx['account'];
        if ((int)$ctx['order_server_id']!==$serverId ||
            (string)$ctx['order']['remark']!==(string)$params['remark'] ||
            (int)$account['order_id']!==$orderId ||
            !in_array($account['state'],['mutating','linked'],true) ||
            (string)$account['mutation_key']!==$key)
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

        $disposition=achbotPgRecoveryDisposition($before,$absolute,$native);
        if ($disposition==='drift')
            throw new RuntimeException('pg_recovery_native_entitlement_drift');
        if ($account['state']==='linked' &&
            (($saved['result']??null)!=='verified' || $disposition!=='applied'))
            throw new RuntimeException('pg_recovery_already_finished_changed');
        if ($account['state']==='mutating') {
            if ($disposition==='baseline') {
                // Original financial claim and account fence are held by the
                // caller. This PUT uses a stored ABSOLUTE target, never
                // current + purchased units, so even additive retry does not
                // grant another copy of the original purchase.
                if (($changes['reset_usage']??false)===true ||
                    array_key_exists('volume',$params)) {
                    $reset=achbotPgReset($ctx['panel'],$ctx['identity']);
                    if (($reset['success']??false)!==true)
                        throw new RuntimeException('pg_recovery_reset_unverified');
                }
                $write=achbotPgModify($ctx['panel'],$ctx['identity'],$absolute,
                    $native['group_ids']);
                if (($write['success']??false)!==true ||
                    $write['user']['group_ids']!==$native['group_ids'])
                    throw new RuntimeException('pg_recovery_native_write_unverified');
                $native=$write['user'];
                if ($native['expire']!==$absolute['expire'] ||
                    $native['data_limit']!==$absolute['data_limit'])
                    throw new RuntimeException('pg_recovery_native_readback_changed');
            }
            achbotPgFinishMutation($db,$ctx,$saved,['success'=>true,'user'=>$native]);
        }
        return (object)['success'=>true,'verified'=>true,'uncertain'=>false,
            'applied_expire'=>$native['expire'],'applied_bytes'=>$native['data_limit']];
    } catch (Throwable $error) {
        $reason=(string)$error->getMessage();
        if (preg_match('/\A(?:pg|provider|addon|effect|service|recovery)_[a-z0-9_]{2,90}\z/D',$reason)!==1)
            $reason='recovery_internal_exception';
        error_log('achbot_provider_edit_recovery_deferred pay_id='.
            (int)($params['pay_id']??0).' server_id='.$serverId.
            ' code='.$reason.' class='.get_class($error));
        return achbotProviderFailure($reason);
    }
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
        return achbotContinueMarzbanPaidAccount($db,$payId,$ordinal,$effect,$exactAccountGet);
    } catch (RuntimeException $error) {
        if ($actor<=0 || $effect['state']!=='dispatching' ||
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
        return achbotContinuePasarGuardPaidAccount($db,$payId,$ordinal,$effect);
    } catch (RuntimeException $error) {
        if ($actor<=0 || $effect['state']!=='dispatching' ||
            $error->getMessage()!=='paid_resume_native_unavailable')
            throw $error;
        return achbotRetryOriginalPaidCreate($db,$payId,$ordinal,$effect,$request,
            $actor,$primary);
    }
}
