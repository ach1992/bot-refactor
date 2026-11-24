<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/Execution.php';
require_once __DIR__.'/../Admin/Access.php';
require_once __DIR__.'/../Purchase/OrderWrites.php';

/** Shared attention is keyed by actual operations, including scheduled
 * renewals without an invoice. A notification is not a financial failure. */
function achbotServiceAttentionScope(): string {
    return "(o.stage IN ('funded','prepared','dispatching','applied') AND (
            o.failure_code IS NOT NULL OR o.claim_until<=NOW(6)
            OR (o.claim_token IS NULL AND o.next_attempt_at<=NOW(6)-INTERVAL 2 MINUTE))
        OR EXISTS (SELECT 1 FROM payment_delivery_intents d
            JOIN payment_delivery_parts p ON p.intent_id=d.id
            WHERE d.service_operation_id=o.id AND p.state IN ('unknown','rejected'))
        OR EXISTS (SELECT 1 FROM payment_followup_intents f WHERE f.service_operation_id=o.id
            AND f.state IN ('unknown','rejected')))";
}
function achbotServiceAttentionCaseOpen(mysqli $db,int $id): bool {
    return $id>0 && achbotProviderRow($db,'SELECT o.id FROM service_operations o WHERE o.id=? AND '.achbotServiceAttentionScope(),'i',[$id])!==null;
}

/** Only a recorded original order plus its atomic delivery intent is a
 * retained BUY unit. Creation now finalizes each ordinal before the next.
 * Unlinked uncertain/verified units need explicit external reconciliation. */
function achbotServiceResolutionPlanLocked(mysqli $db,array $op,array $quote,array $owner): array {
    if (!in_array($op['stage'],['funded','prepared','dispatching','applied'],true) ||
        $op['resolution_kind']!==null) throw new DomainException('service_resolution_stale');
    achbotServiceVerifyFundingLocked($db,$op,$owner);
    $id=(int)$op['id'];$q=$quote['request'];$buy=$op['kind']==='BUY';
    $retained=0;$unlinked=[];$mutation=null;
    if ($buy) {
        if ((int)$op['reserved_count']!==$q['quantity']) throw new RuntimeException('service_resolution_capacity');
        $s=achbotProviderStatement($db,"SELECT * FROM service_effects
            WHERE source_kind='service_operation' AND source_ref=? ORDER BY ordinal FOR UPDATE",'s',[(string)$id]);
        $effects=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
        if (count($effects)>$q['quantity']) throw new RuntimeException('service_resolution_ordinals');
        foreach ($effects as $i=>$e) {
            if ((int)$e['ordinal']!==$i+1 || (int)$e['actor_userid']!==(int)$op['userid'] ||
                (int)$e['target_server_id']!==$q['server_id'] || $e['target_key']!==$quote['target']['physical'] ||
                $e['effect_kind']!=='create' || $e['pay_row_id']!==null || (int)$e['reserved_count']!==0)
                throw new RuntimeException('service_resolution_effect_identity');
            $d=achbotProviderRow($db,'SELECT * FROM payment_delivery_intents WHERE service_operation_id=? AND ordinal=? FOR UPDATE','ii',[$id,$i+1]);
            if ($e['state']==='local_recorded') {
                if ($unlinked || !$d || (int)$d['effect_id']!==(int)$e['id'] ||
                    (int)$d['order_id']!==(int)$e['order_id'] || (int)$d['user_id']!==(int)$op['userid'])
                    throw new RuntimeException('service_resolution_delivery_evidence');
                $retained++;
            } elseif (in_array($e['state'],['dispatching','verified'],true) && $e['order_id']===null && !$d) {
                $unlinked[]=['id'=>(int)$e['id'],'ordinal'=>$i+1,'username'=>$e['remote_username'],
                    'state'=>$e['state'],'remote_id'=>$e['provider_remote_user_id'],
                    'marker'=>$e['provider_operation_marker']];
            } else throw new RuntimeException('service_resolution_effect_state');
        }
    } else {
        $m=achbotServiceMutationRow($db,$id,true);
        if ($m) $mutation=['effect_id'=>(int)$m['account_effect_id'],'phase'=>$m['phase'],
            'intent'=>$m['intent_sha256'],'result'=>$m['result_sha256'],'reset_ack'=>$m['reset_ack_at']];
    }
    $count=$buy?$q['quantity']-$retained:1;
    // Exact same allocation as order persistence, without iterating over
    // uncreated units. Original discount/price cannot be recomputed.
    $refund=$buy?$q['price']-intdiv($q['price'],$q['quantity'])*$retained-
        min($retained,$q['price']%$q['quantity']):$q['price'];
    return ['id'=>$id,'quote'=>$op['quote_sha256'],'kind'=>$op['kind'],'stage'=>$op['stage'],
        'debit'=>$op['debit_entry_id'],'retained'=>$retained,'cancel_count'=>$count,'refund'=>$refund,
        'unlinked'=>$unlinked,'mutation'=>$mutation,
        'can_close'=>!$buy,'can_refund'=>$count>0 && ($buy || ($mutation['phase']??null)!=='verified')];
}
function achbotServiceResolutionToken(array $plan,int $actor,string $decision): string {
    return substr(hash_hmac('sha256',achbotServiceJson([$actor,$decision,$plan]),achbotPaymentEvidenceKey()),0,20);
}
function achbotServiceResolutionPreview(mysqli $db,int $id,int $actor,int $primary): ?array {
    if (!achbotAdminCanFor($db,$actor,$primary,'payments')) return null;
    $loc=achbotProviderRow($db,'SELECT userid FROM service_operations WHERE id=?','i',[$id]);
    if (!$loc) return null;
    if (!$db->begin_transaction()) throw new RuntimeException('service_resolution_begin');
    try {
        [$op,$quote,$owner]=achbotServiceReadLocked($db,(int)$loc['userid'],$id);
        $plan=achbotServiceResolutionPlanLocked($db,$op,$quote,$owner);
        $db->rollback();return $plan;
    } catch (Throwable $e) {$db->rollback();throw $e;}
}

/** Operator is explicitly asserting external disposition for an unresolved
 * mutation; this is never fabricated provider success. No HTTP write,
 * no second debit, no reversal of an original accounting row. */
function achbotServiceResolve(mysqli $db,int $id,int $actor,int $primary,string $decision,string $token): array {
    if (!in_array($decision,['closed','refunded'],true) ||
        !achbotAdminCanFor($db,$actor,$primary,'payments')) return ['status'=>'denied'];
    achbotPaymentEvidenceRequireAutocommit($db);
    $name=achbotServiceExecutionLock($db,$id);
    $held=achbotProviderRow($db,'SELECT GET_LOCK(?,0) AS acquired','s',[$name]);
    if ((int)($held['acquired']??0)!==1) return ['status'=>'busy'];
    $physical=null;
    try {
        $loc=achbotProviderRow($db,'SELECT userid FROM service_operations WHERE id=?','i',[$id]);
        if (!$loc) return ['status'=>'stale'];
        $uid=(int)$loc['userid'];
        if (!$db->begin_transaction()) throw new RuntimeException('service_resolution_begin');
        [$op,$quote,$owner]=achbotServiceReadLocked($db,$uid,$id);
        if (!achbotAdminCanLocked($db,$actor,$primary,'payments')) {$db->rollback();return ['status'=>'denied'];}
        if ($op['resolution_kind']!==null || !in_array($op['stage'],['funded','prepared','dispatching','applied'],true)) {
            $db->rollback();return ['status'=>'stale'];
        }
        $plan=achbotServiceResolutionPlanLocked($db,$op,$quote,$owner);
        if (!hash_equals(achbotServiceResolutionToken($plan,$actor,$decision),$token) ||
            !($decision==='closed'?$plan['can_close']:$plan['can_refund'])) {
            $db->rollback();return ['status'=>'stale'];
        }
        $adapter=achbotProviderDefinition($quote['target']['provider']);
        if (!$adapter || !isset($adapter['service_resolution_check'],$adapter['service_resolution_release']))
            throw new RuntimeException('service_resolution_adapter_unavailable');
        // Physical owner is resolved by the adapter from the persisted claim,
        // even when the original local order can no longer be read normally.
        $db->rollback();
        if (isset($adapter['service_resolution_lock'])) {
            $lock=($adapter['service_resolution_lock'])($db,$op,$quote,$plan);
            if ($lock!==null) {
                $r=achbotProviderRow($db,'SELECT GET_LOCK(?,0) AS acquired','s',[$lock]);
                if ((int)($r['acquired']??0)!==1) return ['status'=>'busy'];
                $physical=$lock;
            }
        }
        // Authenticated GET only, outside SQL transactions. A positive
        // observation of a still-existing unlinked original BUY blocks refund.
        // Unavailable reads require the operator's explicit external assertion
        // in the confirmation, never inferred absence from a timeout.
        $checked=($adapter['service_resolution_check'])($db,$op,$quote,$plan,$decision);
        if (($checked['status']??null)!=='ok') return $checked;
        if (!$db->begin_transaction()) throw new RuntimeException('service_resolution_commit_begin');
        [$op,$quote,$owner]=achbotServiceReadLocked($db,$uid,$id);
        if (!achbotServiceExecutionOwnsLock($db,$name) ||
            ($physical!==null && !achbotServiceExecutionOwnsLock($db,$physical)) ||
            !achbotAdminCanLocked($db,$actor,$primary,'payments')) {$db->rollback();return ['status'=>'denied'];}
        $current=achbotServiceResolutionPlanLocked($db,$op,$quote,$owner);
        if ($current!==$plan) {$db->rollback();return ['status'=>'stale'];}
        ($adapter['service_resolution_release'])($db,$op,$quote,$plan,$decision,$checked);
        $amount=$decision==='refunded'?$plan['refund']:0;$entry=null;
        if ($amount>0) {
            $e=achbotWalletApplyLocked($db,$uid,'service_refund',(string)$id,0,$amount,$actor);
            if ($e['replayed']) throw new RuntimeException('service_resolution_orphan_refund');
            $entry=$e['id'];
        }
        if ($op['kind']==='BUY') {
            $s=achbotProviderStatement($db,'UPDATE server_info SET ucount=ucount+? WHERE id=?',
                'ii',[$plan['cancel_count'],$quote['request']['server_id']]);
            $n=$s->affected_rows;$s->close();
            if ($n!==1) throw new RuntimeException('service_resolution_capacity_release');
        }
        $stage=$op['kind']==='BUY' && $plan['retained']>0?'completed':'cancelled';
        $cancelled=$decision==='refunded'?$plan['cancel_count']:0;
        $s=achbotProviderStatement($db,"UPDATE service_operations SET stage=?,resolution_kind=?,resolved_by=?,
            resolved_at=NOW(6),refund_entry_id=?,cancelled_count=?,next_attempt_at=NULL,
            claim_token=NULL,claim_until=NULL,updated_at=NOW(6)
            WHERE id=? AND resolution_kind IS NULL",
            'ssiiii',[$stage,$decision,$actor,$entry,$cancelled,$id]);
        $n=$s->affected_rows;$s->close();
        if ($n!==1) throw new RuntimeException('service_resolution_write');
        [$resolved,$original]=achbotServiceReadLocked($db,$uid,$id);
        achbotServiceStageResolutionReportsLocked($db,$resolved,$original,$primary);
        if (!$db->commit()) throw new RuntimeException('service_resolution_commit');
        return ['status'=>$decision,'refund'=>$amount,'retained'=>$plan['retained'],'cancelled'=>$cancelled];
    } catch (Throwable $e) {
        try {$db->rollback();} catch (Throwable $ignored) {}
        $reason=achbotServiceExecutionReason($e);
        achbotDiagnosticEvent('achbot.service.resolution',['service_operation_id'=>$id,'actor_id'=>$actor,
            'reason'=>$reason,'class'=>get_class($e)],'error');
        return ['status'=>'unavailable','reason'=>$reason];
    } finally {
        foreach ([$physical,$name] as $lock) if ($lock!==null) {
            try {$s=achbotProviderStatement($db,'SELECT RELEASE_LOCK(?)','s',[$lock]);$s->close();}
            catch (Throwable $ignored) {}
        }
    }
}
