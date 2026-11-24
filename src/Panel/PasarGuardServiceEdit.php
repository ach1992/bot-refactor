<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/../Services/EditFinalization.php';

/** Same physical lease used by existing plan/reassignment operations.
 * No credentials or mutable usernames are part of the lock name. */
function achbotPgServiceAccountLock(mysqli $db,int $orderId): string {
    $ctx=achbotPgOrderContext($db,$orderId,false,true);
    return 'achbot-plan-'.substr(hash('sha256','pg:'.$ctx['account']['id']),0,40);
}

/** Fresh identity-pinned observation for a scheduled renewal, outside the
 * monetary transaction, while the physical session lease is held. */
function achbotPgServiceAutoObserve(mysqli $db,int $orderId): array {
    require_once __DIR__.'/ServiceLifecycle.php';
    $ctx=achbotPgOrderContext($db,$orderId,false,true);
    if ($ctx['account']['state']!=='linked')
        return ['success'=>false,'reason'=>'service_edit_account_busy'];
    $r=achbotPgServiceEditRead($ctx,['request'=>['remark'=>$ctx['order']['remark']]]);
    if (!$r['success']) return $r;
    return ['success'=>true,'target'=>achbotPgServiceOrderTarget($ctx),
        'observation'=>achbotServiceObservation((object)$r['user'],$ctx['order']['remark']),
        'native'=>array_intersect_key($r['user'],array_flip(['data_limit','expire','group_ids','status']))];
}

/** Scheduled admission already owns the fresh authenticated observation.
 * Persist that exact first target in the same transaction as funding and
 * physical claim; a later external change is not a new renewal decision. */
function achbotPgServiceAutoReserveLocked(mysqli $db,array $op,array $quote,array $live): void {
    if ($op['kind']!=='RENEW' || $op['command_kind']!=='automatic_renewal' ||
        ($live['target']??null)!==$quote['target'])
        throw new RuntimeException('service_auto_target_changed');
    $row=achbotPgServiceEditPrepareLocked($db,$op,$quote);
    if (!$row || $row['phase']!=='observe') throw new RuntimeException('service_auto_claim_missing');
    $intent=achbotPgServiceEditIntent($op,$quote,$live['native']);
    achbotPgServiceEditApplyLocked($db,$op,$quote,$row,['intent'=>$intent]);
}

/** Reuse the original account incarnation and the existing durable physical
 * state fence. All local aliases share this same service_effects anchor. */
function achbotPgServiceEditContext(mysqli $db,array $op,array $quote,bool $lock=false): array {
    $q=$quote['request'];$t=$quote['target'];
    if (!in_array($op['kind'],['RENEW','ADD_TIME','ADD_VOLUME'],true) || $t['provider']!=='pasarguard')
        throw new RuntimeException('service_edit_kind');
    $ctx=achbotPgOrderContext($db,$q['order_id'],$lock,true);
    $o=$ctx['order'];$p=$ctx['panel'];$a=$ctx['account'];
    if ((int)$o['userid']!==(int)$op['userid'] || (int)$o['status']!==1 ||
        (int)$o['server_id']!==$q['server_id'] || (int)$o['fileid']!==$q['plan_id'] ||
        (int)$o['inbound_id']!==0 || $o['remark']!==$q['remark'] ||
        (string)$o['uuid']!==$q['uuid'] ||
        $t['account']['native']!==$ctx['identity'] ||
        $t['account']['remark']!==$o['remark'] ||
        $t['account']['uuid']!==(string)$o['uuid'] ||
        $t['physical']!=='pg:'.$p['instance_key'] || $t['panel_id']!==$p['id'] ||
        $t['endpoint']!==hash('sha256',rtrim($p['api_url'],'/')) ||
        !in_array($a['state'],['linked','mutating'],true))
        throw new RuntimeException('service_edit_identity_changed');
    return $ctx;
}
function achbotPgServiceEditClaimMatches(array $op,array $ctx,array $row): void {
    if ((int)$row['service_operation_id']!==(int)$op['id'] ||
        (int)$row['account_effect_id']!==$ctx['account']['effect_id'] ||
        (int)$row['order_expire_before']!==(int)$ctx['order']['expire_date'] ||
        $ctx['account']['state']!=='mutating' ||
        $ctx['account']['mutation_key']!=='service:'.$op['id'])
        throw new RuntimeException('service_edit_claim_changed');
}
function achbotPgServiceEditPrepareLocked(mysqli $db,array $op,array $quote): ?array {
    require_once __DIR__.'/../Finance/ServiceDelivery.php';
    if (!achbotServiceMutationSchemaReady($db) || !achbotServiceDeliverySchemaReady($db)) throw new RuntimeException('service_delivery_schema_unavailable');
    $ctx=achbotPgServiceEditContext($db,$op,$quote,true);
    $id=(int)$op['id'];$a=$ctx['account'];$eid=$a['effect_id'];
    $row=achbotServiceMutationRow($db,$id,true);
    if ($row===null) {
        // Lock and CAS the actual common anchor; a different operation
        // remains pending until its owner completes, even across aliases.
        if ($a['state']!=='linked') throw new RuntimeException('service_edit_account_busy');
        $s=achbotProviderStatement($db,"INSERT INTO service_mutations
            (service_operation_id,account_effect_id,previous_mutation_key,order_expire_before,phase)
            VALUES (?,?,?,?,'observe')",'iisi',[$id,$eid,$a['mutation_key'],(int)$ctx['order']['expire_date']]);$s->close();
        $s=achbotProviderStatement($db,"UPDATE service_effects SET provider_state='mutating',
            provider_mutation_key=?,provider_evidence_updated_at=UTC_TIMESTAMP(),updated_at=updated_at
            WHERE id=? AND provider_state='linked' AND provider_mutation_key <=> ?",
            'sis',['service:'.$id,$eid,$a['mutation_key']]);
        $n=$s->affected_rows;$s->close();
        if ($n!==1) throw new RuntimeException('service_edit_claim_failed');
        $row=achbotServiceMutationRow($db,$id,true);
        $ctx=achbotPgServiceEditContext($db,$op,$quote,true);
    }
    achbotPgServiceEditClaimMatches($op,$ctx,$row);
    if (!in_array($row['phase'],['observe','reset','modify'],true))
        throw new RuntimeException('service_edit_phase_invalid');
    return $row;
}

/** Only stable entitlement fields participate in target comparison. Traffic
 * and derived active/expired/limited states can advance normally, but an
 * administrator's disabled state cannot be overwritten by recovery. */
function achbotPgServiceEditEntitlement(array $u,string $kind): array {
    return match($kind) {
        'ADD_VOLUME'=>['data_limit'=>$u['data_limit']],
        'ADD_TIME'=>['expire'=>$u['expire']],
        'RENEW'=>['data_limit'=>$u['data_limit'],'expire'=>$u['expire'],'disabled'=>$u['status']==='disabled'],
    };
}
function achbotPgServiceEditExpectedNative(array $u,string $kind): array {
    $fields=match($kind) {
        'ADD_VOLUME'=>['data_limit'],
        'ADD_TIME'=>['expire'],
        'RENEW'=>['data_limit','expire','status'],
    };
    return array_intersect_key($u,array_flip($fields));
}
function achbotPgServiceEditIntent(array $op,array $quote,array $u): array {
    $q=$quote['request'];
    $units=match($op['kind']){
        'RENEW'=>['days'=>$q['days'],'volume'=>$q['volume']],
        'ADD_TIME'=>['plus_day'=>$q['days']],
        'ADD_VOLUME'=>['plus_volume'=>$q['volume']],
    };
    $anchor=time();
    if ($op['kind']==='ADD_VOLUME' && ($u['expire']===null || $u['expire']<=$anchor))
        throw new RuntimeException('pg_addon_expired_or_unlimited_time');
    $absolute=achbotPgEditChanges($u,$units,$anchor);
    $changes=match($op['kind']){
        'RENEW'=>array_intersect_key($absolute,array_flip(['expire','data_limit','status'])),
        'ADD_TIME'=>['expire'=>$absolute['expire']],
        'ADD_VOLUME'=>['data_limit'=>$absolute['data_limit']],
    };
    // Never explicitly enable an administratively disabled subscription.
    // ADD only sends its owned entitlement field, not stale unrelated values.
    $before=achbotPgServiceEditEntitlement($u,$op['kind']);
    $target=array_replace($before,array_intersect_key($changes,array_flip(['expire','data_limit'])));
    return ['version'=>1,'quote_sha256'=>$op['quote_sha256'],'anchor'=>$anchor,
        'before'=>$before,'target'=>$target,'changes'=>$changes];
}
function achbotPgServiceEditDecode(array $op,array $row,string $field): array {
    $payload=$row[$field.'_payload']??null;$digest=$row[$field.'_sha256']??null;
    if (!is_string($payload) || !is_string($digest) || !hash_equals($digest,hash('sha256',$payload)))
        throw new RuntimeException('service_edit_evidence_integrity');
    $v=json_decode($payload,true,64,JSON_THROW_ON_ERROR);
    if (!is_array($v) || ($v['quote_sha256']??null)!==$op['quote_sha256'])
        throw new RuntimeException('service_edit_evidence_invalid');
    return $v;
}
function achbotPgServiceEditRead(array $ctx,array $quote): array {
    $r=achbotPgRead($ctx['panel'],$ctx['identity']);
    if (!$r['success']) return $r;
    $u=$r['user'];
    if ($u['username']!==$quote['request']['remark'] || !achbotPgManaged($u))
        return achbotPgFailure('pg_service_edit_identity_or_schedule_changed');
    return $r;
}
function achbotPgServiceEditScope(array $quote,array $ctx,array $u): array {
    $scope=$quote['request']['policy']['addon_scope']??null;
    if ($scope===null) return ['success'=>true,'groups'=>null];
    if (!is_array($scope) || ($scope['provider']??null)!=='pasarguard' ||
        ($scope['native_identity']??null)!==hash('sha256',json_encode($ctx['identity'],JSON_THROW_ON_ERROR)) ||
        ($scope['native_groups']??null)!==$u['group_ids'])
        return achbotPgFailure('pg_addon_native_groups_changed');
    $check=achbotPgCheck($ctx['panel']);
    if (!$check['success']) return $check;
    if (array_diff($u['group_ids'],achbotPgEligibleGroupIds($check)))
        return achbotPgFailure('pg_addon_authority_changed_before_write');
    return ['success'=>true,'groups'=>$u['group_ids']];
}
function achbotPgServiceEditPerform(mysqli $db,array $op,array $quote,array $row): array {
    $ctx=achbotPgServiceEditContext($db,$op,$quote);
    achbotPgServiceEditClaimMatches($op,$ctx,$row);
    $read=achbotPgServiceEditRead($ctx,$quote);
    if (!$read['success']) return $read;
    $u=$read['user'];$scope=achbotPgServiceEditScope($quote,$ctx,$u);
    if (!$scope['success']) return $scope;
    if ($row['phase']==='observe') {
        try {$intent=achbotPgServiceEditIntent($op,$quote,$u);}
        catch (RuntimeException $e) {return achbotPgFailure($e->getMessage());}
        return ['success'=>true,'intent'=>$intent];
    }
    $intent=achbotPgServiceEditDecode($op,$row,'intent');
    $beforeWrite=static function() use ($db,$op,$quote,$row,$ctx): void {
        if (!achbotServiceExecutionOwnsLock($db,achbotServiceExecutionLock($db,(int)$op['id'])) ||
            !achbotServiceExecutionOwnsLock($db,achbotPgServiceAccountLock($db,$quote['request']['order_id'])))
            throw new RuntimeException('service_worker_ownership_lost');
        $current=achbotPgServiceEditContext($db,$op,$quote);
        achbotPgServiceEditClaimMatches($op,$current,$row);
        if (array_intersect_key($current['panel'],array_flip(['id','instance_key','api_url','api_key']))!==
            array_intersect_key($ctx['panel'],array_flip(['id','instance_key','api_url','api_key'])) ||
            achbotServiceMutationRow($db,(int)$op['id'])!==$row)
            throw new RuntimeException('service_edit_dispatch_changed');
    };
    $current=achbotPgServiceEditEntitlement($u,$op['kind']);
    $baseline=$current===$intent['before'];$target=$current===$intent['target'];
    if ($row['phase']==='reset') {
        if ($op['kind']!=='RENEW' || $row['reset_ack_at']!==null || !$baseline)
            return achbotPgFailure('pg_service_reset_baseline_conflict');
        // Acknowledged reset and subsequent traffic are different facts.
        // A lost reset ACK may be retried only for this unresolved renewal.
        $r=achbotPgReset($ctx['panel'],$ctx['identity'],
            achbotPgServiceEditExpectedNative($u,$op['kind']),$beforeWrite);
        if (!$r['success']) return $r;
        if (achbotPgServiceEditEntitlement($r['user'],$op['kind'])!==$intent['before'])
            return achbotPgFailure('pg_service_reset_entitlement_changed',true);
        return ['success'=>true,'reset_ack'=>true];
    }
    if ($op['kind']==='RENEW' && $row['reset_ack_at']===null)
        throw new RuntimeException('service_edit_reset_ack_missing');
    if (!$target && !$baseline)
        return achbotPgFailure('pg_service_edit_entitlement_conflict');
    if (!$target) {
        $r=achbotPgModify($ctx['panel'],$ctx['identity'],$intent['changes'],$scope['groups'],
            $beforeWrite,achbotPgServiceEditExpectedNative($u,$op['kind']));
        if (!$r['success']) return $r;
        $u=$r['user'];
    }
    if ($u['username']!==$quote['request']['remark'] ||
        achbotPgServiceEditEntitlement($u,$op['kind'])!==$intent['target'])
        return achbotPgFailure('pg_service_edit_target_unconfirmed',true);
    return ['success'=>true,'result'=>['quote_sha256'=>$op['quote_sha256'],
        'intent_sha256'=>$row['intent_sha256'],'entitlement'=>$intent['target']]];
}
function achbotPgServiceEditApplyLocked(mysqli $db,array $op,array $quote,array $row,array $result): bool {
    $ctx=achbotPgServiceEditContext($db,$op,$quote,true);
    $id=(int)$op['id'];$current=achbotServiceMutationRow($db,$id,true);
    if ($current!==$row) throw new RuntimeException('service_edit_result_stale');
    achbotPgServiceEditClaimMatches($op,$ctx,$row);
    if ($row['phase']==='observe') {
        $body=achbotServiceJson($result['intent']);$sha=hash('sha256',$body);
        $phase=$op['kind']==='RENEW'?'reset':'modify';
        $s=achbotProviderStatement($db,"UPDATE service_mutations SET phase=?,intent_payload=?,intent_sha256=?
            WHERE service_operation_id=? AND phase='observe'",'sssi',[$phase,$body,$sha,$id]);
    } elseif ($row['phase']==='reset' && ($result['reset_ack']??false)===true) {
        $s=achbotProviderStatement($db,"UPDATE service_mutations SET phase='modify',reset_ack_at=NOW(6)
            WHERE service_operation_id=? AND phase='reset' AND reset_ack_at IS NULL",'i',[$id]);
    } elseif ($row['phase']==='modify') {
        $body=achbotServiceJson($result['result']);$sha=hash('sha256',$body);
        $s=achbotProviderStatement($db,"UPDATE service_mutations SET phase='verified',result_payload=?,result_sha256=?
            WHERE service_operation_id=? AND phase='modify'",'ssi',[$body,$sha,$id]);
    } else throw new RuntimeException('service_edit_result_phase');
    $n=$s->affected_rows;$s->close();
    if ($n!==1) throw new RuntimeException('service_edit_result_write');
    return $row['phase']==='modify';
}

function achbotPgServiceEditVerified(array $op,array $row): array {
    if ($row['phase']!=='verified' || $row['audit_id']!==null ||
        ($op['kind']==='RENEW')!==($row['reset_ack_at']!==null))
        throw new RuntimeException('service_edit_verified_phase');
    $intent=achbotPgServiceEditDecode($op,$row,'intent');
    $result=achbotPgServiceEditDecode($op,$row,'result');
    if (($result['intent_sha256']??null)!==$row['intent_sha256'] ||
        ($result['entitlement']??null)!==$intent['target'])
        throw new RuntimeException('service_edit_verified_target');
    return $result['entitlement'];
}
function achbotPgServiceEditFinalizePrepareLocked(mysqli $db,array $op,array $quote): ?array {
    $ctx=achbotPgServiceEditContext($db,$op,$quote,true);
    $row=achbotServiceMutationRow($db,(int)$op['id'],true);
    if (!$row) throw new RuntimeException('service_edit_verified_missing');
    achbotPgServiceEditClaimMatches($op,$ctx,$row);
    achbotPgServiceEditVerified($op,$row);
    return $row;
}
function achbotPgServiceEditFinalizeApplyLocked(mysqli $db,array $op,array $quote,array $row,array $result): bool {
    $ctx=achbotPgServiceEditContext($db,$op,$quote,true);
    $id=(int)$op['id'];$q=$quote['request'];$o=$ctx['order'];$oid=(int)$o['id'];
    $current=achbotServiceMutationRow($db,$id,true);
    if ($current!==$row) throw new RuntimeException('service_edit_finalization_stale');
    achbotPgServiceEditClaimMatches($op,$ctx,$row);
    $entitlement=achbotPgServiceEditVerified($op,$row);
    achbotServiceEditCommitLocalLocked($db,$op,$quote,$row,$o,$entitlement['expire']??null);
    // The temporary service marker is removed only in the same commit as
    // the original local order, audit, delivery and terminal operation.
    // Do not overwrite any original historical mutation snapshot.
    $s=achbotProviderStatement($db,"UPDATE service_effects SET provider_state='linked',
        provider_mutation_key=?,provider_evidence_updated_at=UTC_TIMESTAMP(),updated_at=updated_at
        WHERE id=? AND provider_state='mutating' AND provider_mutation_key=?",
        'sis',[$row['previous_mutation_key'],(int)$row['account_effect_id'],'service:'.$id]);
    $n=$s->affected_rows;$s->close();
    if ($n!==1) throw new RuntimeException('service_edit_fence_release_failed');
    return true;
}
