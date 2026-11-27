<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}

/** Reuse the original physical owner, not a possibly moved local order. */
function achbotPgServiceResolutionLock(mysqli $db,array $op,array $quote,array $plan): ?string {
    if ($plan['mutation']===null) return null;
    $e=achbotProviderRow($db,'SELECT provider_reference_id FROM service_effects WHERE id=?','i',[$plan['mutation']['effect_id']]);
    if (!$e || (int)$e['provider_reference_id']<=0) throw new RuntimeException('service_resolution_anchor_missing');
    return achbotPhysicalJournalKey((int)$e['provider_reference_id']);
}
function achbotPgServiceResolutionCheck(mysqli $db,array $op,array $quote,array $plan,string $decision): array {
    // The operator confirmation is explicitly about actual external state.
    // A successful read showing an owned account still exists must not be
    // ignored even if the operator requested a refund.
    if ($op['kind']==='BUY') {
        if (!$plan['unlinked']) return ['status'=>'ok'];
        try {[, $panel]=achbotPasarGuardBuySnapshot($db,$quote);}
        catch (Throwable $e) {return ['status'=>'ok'];}
        foreach ($plan['unlinked'] as $e) {
            $r=achbotPgUserResult(achbotPgRequest($panel,'GET','/api/user/by-username/'.rawurlencode($e['username'])));
            if ($r['success'] && (achbotPgIdentityMatches($r['user'],[
                'remote_user_id'=>$r['user']['id'],'remote_created_at'=>$r['user']['created_at'],
                'operation_marker'=>$e['marker']]) || ($e['remote_id']!==null && (int)$e['remote_id']===$r['user']['id'])))
                return ['status'=>'account_exists','username'=>$e['username']];
        }
        return ['status'=>'ok'];
    }
    $intent=null;
    if ($decision==='refunded' && $plan['mutation']!==null) {
        $m=achbotServiceMutationRow($db,(int)$op['id']);
        if (!$m) throw new RuntimeException('service_resolution_mutation_missing');
        if ($m['dispatch_started_at']!==null) $intent=achbotPgServiceEditDecode($op,$m,'intent');
    }
    // Capture actual current expiry if available. The explicitly authorized
    // operator decision is still possible after a provider identity/outage
    // problem; corrupted immutable local evidence is not such a problem.
    try {
        $ctx=achbotPgServiceEditContext($db,$op,$quote);
    } catch (RuntimeException $e) {
        return ['status'=>'ok'];
    }
    $r=achbotPgServiceEditRead($ctx,$quote);
    if ($r['success']) {
        if ($intent!==null && $intent['before']!==$intent['target'] &&
            achbotPgServiceEditEntitlement($r['user'],$op['kind'])===$intent['target'])
            return ['status'=>'service_applied'];
        return ['status'=>'ok','expiry'=>$r['user']['expire']];
    }
    return ['status'=>'ok'];
}
function achbotPgServiceResolutionRelease(mysqli $db,array $op,array $quote,array $plan,string $decision,array $checked): void {
    if ($op['kind']==='BUY') {
        foreach ($plan['unlinked'] as $e) {
            // Keep the original Native identity, marker and real evidence.
            // 'cancelled' is an operator disposition, not authenticated DELETE.
            $s=achbotProviderStatement($db,"UPDATE service_effects SET state='cancelled',provider_state='cancelled'
                WHERE id=? AND source_kind='service_operation' AND source_ref=? AND order_id IS NULL AND state=?",
                'iss',[$e['id'],(string)$op['id'],$e['state']]);
            $n=$s->affected_rows;$s->close();
            if ($n!==1) throw new RuntimeException('service_resolution_effect_release');
        }
        return;
    }
    if ($plan['mutation']!==null) {
        $m=achbotServiceMutationRow($db,(int)$op['id'],true);
        $s=achbotProviderStatement($db,"UPDATE service_effects SET provider_state='linked',
            provider_mutation_key=?,provider_evidence_updated_at=UTC_TIMESTAMP(),updated_at=updated_at
            WHERE id=? AND provider_state='mutating' AND provider_mutation_key=?",
            'sis',[$m['previous_mutation_key'],(int)$m['account_effect_id'],'service:'.$op['id']]);
        $n=$s->affected_rows;$s->close();
        if ($n!==1) throw new RuntimeException('service_resolution_fence_release');
    }
    $q=$quote['request'];
    if ($decision==='closed' && array_key_exists('expiry',$checked) &&
        is_int($checked['expiry']) && $checked['expiry']>0 && $checked['expiry']<=2147483647) {
        $s=achbotProviderStatement($db,"UPDATE orders_list SET expire_date=?,notif=0
            WHERE id=? AND userid=? AND remark=? AND uuid=?",
            'iiiss',[$checked['expiry'],$q['order_id'],(int)$op['userid'],$q['remark'],$q['uuid']]);$s->close();
    }
    // A refunded automatic renewal must not repeatedly charge the same
    // unresolved cycle. The customer can deliberately enable it again.
    if ($op['command_kind']==='automatic_renewal' && $decision==='refunded') {
        $s=achbotProviderStatement($db,"UPDATE orders_list SET autoRenew='off' WHERE id=? AND userid=?",
            'ii',[$q['order_id'],(int)$op['userid']]);$s->close();
    }
}
