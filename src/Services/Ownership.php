<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/../Panel/ProviderStorage.php';

/** The old, unactivated schema has no new operations. An installed but
 * malformed table is never absence. The final cutover will require 026. */
function achbotServiceOperationsPresent(mysqli $db): bool {
    $r=achbotProviderRow($db,"SELECT TABLE_TYPE,ENGINE FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='service_operations'");
    if ($r===null) return false;
    if ($r['TABLE_TYPE']!=='BASE TABLE' || strcasecmp((string)$r['ENGINE'],'InnoDB')!==0)
        throw new RuntimeException('service_ownership_schema_invalid');
    return true;
}

/** Historical 004 claims own their original subscription until proven
 * terminal. Include aliases of the same physical account, even when the
 * old journal was written before its Native mutation marker. The caller
 * holds the physical account lease for scheduling and the owner/order locks
 * for financial admission. 004 is retained as historical evidence. */
function achbotServiceHistoricalAutoPending(mysqli $db,int $orderId,?int $effectId,bool $lock=false): ?array {
    $suffix=$lock?' FOR UPDATE':'';
    if ($effectId!==null && $effectId>0)
        return achbotProviderRow($db,"SELECT a.id,a.order_id,a.stage
            FROM orders_list o FORCE INDEX(idx_order_provider_effect)
            JOIN automatic_renewal_attempts a FORCE INDEX(idx_renewal_inflight) ON a.order_id=o.id
            WHERE o.provider_effect_id=? AND a.stage<>'done' LIMIT 1".$suffix,'i',[$effectId]);
    return achbotProviderRow($db,"SELECT id,order_id,stage FROM automatic_renewal_attempts
        WHERE order_id=? AND stage<>'done' LIMIT 1".$suffix,'i',[$orderId]);
}

/** Exact original cycle replay, including a historical terminal cycle. */
function achbotServiceHistoricalAutoCycle(mysqli $db,int $orderId,int $cycle,bool $lock=false): ?array {
    return achbotProviderRow($db,'SELECT id,stage FROM automatic_renewal_attempts
        WHERE order_id=? AND cycle_expire=?'.($lock?' FOR UPDATE':''),'ii',[$orderId,$cycle]);
}

/** Original purchased account provenance. The caller already holds the
 * relevant order and physical account locks; no balance or Native I/O. */
function achbotServiceOriginalBuyLocked(mysqli $db,array $effect): array {
    $ref=$effect['source_ref']??null;
    if (($effect['source_kind']??null)!=='service_operation' ||
        !is_string($ref) || !preg_match('/^[1-9][0-9]*$/D',$ref) ||
        filter_var($ref,FILTER_VALIDATE_INT)===false ||
        ($effect['effect_kind']??null)!=='create' || $effect['pay_row_id']!==null ||
        (int)$effect['reserved_count']!==0)
        throw new RuntimeException('service_original_buy_source_invalid');
    $op=achbotProviderRow($db,'SELECT * FROM service_operations WHERE id=? FOR UPDATE','i',[(int)$ref]);
    if (!$op || $op['kind']!=='BUY' || !hash_equals($op['quote_sha256'],hash('sha256',$op['quote_payload'])))
        throw new RuntimeException('service_original_buy_missing');
    $quote=json_decode($op['quote_payload'],true,64,JSON_THROW_ON_ERROR);
    $q=$quote['request']??[];
    if (($q['kind']??null)!=='BUY' || !is_int($q['quantity']??null) || $q['quantity']<=0 ||
        ($q['price']??null)!==(int)$op['price_toman'] ||
        (int)$op['subject_order_id']!==0 ||
        (int)$op['target_server_id']!==(int)$effect['target_server_id'] ||
        (int)$op['reserved_count']!==$q['quantity'] ||
        (int)$effect['ordinal']<1 || (int)$effect['ordinal']>$q['quantity'] ||
        (int)$op['userid']!==(int)$effect['actor_userid'] ||
        ($q['server_id']??null)!==(int)$effect['target_server_id'] ||
        ($q['plan_id']??null)!==(int)$effect['plan_id'] ||
        ($quote['target']['physical']??null)!==$effect['target_key'])
        throw new RuntimeException('service_original_buy_reservation_conflict');
    return $op;
}

/** Call while holding ALL current order references. Funding also locks its
 * original order before changing awaiting_funds -> funded. The indexed
 * current read and those row locks serialize the two decisions without a
 * database-wide scan or a new session lock. Only genuinely funded work
 * holds the subject; unpaid quotes are retired in the real change commit. */
function achbotServiceSubjectWorkSettledLocked(mysqli $db,array $peers,bool $retire=false): bool {
    if (!achbotServiceOperationsPresent($db)) return true;
    $ids=[];$anchors=[];
    foreach ($peers as $o) {
        $id=(int)($o['id']??0);
        if ($id<=0) throw new RuntimeException('service_ownership_order_invalid');
        $ids[$id]=$id;
        $anchor=(int)($o['provider_effect_id']??0);
        if ($anchor>0) $anchors[$anchor]=$anchor;
    }
    if (!$ids) throw new RuntimeException('service_ownership_order_invalid');
    ksort($ids,SORT_NUMERIC);
    $list=implode(',',$ids);
    $pending=achbotProviderRow($db,"SELECT id FROM service_operations FORCE INDEX(idx_service_subject_stage)
        WHERE subject_order_id IN ($list) AND stage IN ('funded','prepared','dispatching','applied')
        LIMIT 1 FOR UPDATE");
    if ($pending) return false;
    // A locally linked first ordinal of a bulk purchase is still owned by
    // that unfinished BUY, even before its final sibling is recorded.
    foreach ($anchors as $id) {
        $e=achbotProviderRow($db,'SELECT * FROM service_effects WHERE id=? FOR UPDATE','i',[$id]);
        if (!$e) throw new RuntimeException('service_ownership_anchor_missing');
        if ($e['source_kind']==='service_operation' &&
            achbotServiceOriginalBuyLocked($db,$e)['stage']!=='completed') return false;
    }
    if ($retire) {
        $s=achbotProviderStatement($db,"UPDATE service_operations FORCE INDEX(idx_service_subject_stage)
            SET stage='cancelled',failure_code='service_subject_superseded',updated_at=NOW(6)
            WHERE subject_order_id IN ($list) AND stage='awaiting_funds'
                AND debit_entry_id IS NULL AND reserved_count=0");
        $s->close();
    }
    return true;
}

/** A prepared plan journal precedes its Native mutation marker. A new
 * monetary claim must not get between that intent and local finality.
 * Absent 017 during isolated legacy-source verification is not a journal. */
function achbotServicePlanChangePendingLocked(mysqli $db,string $lock): bool {
    $r=achbotProviderRow($db,"SELECT TABLE_TYPE,ENGINE FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='service_plan_changes'");
    if (!$r) return false;
    if ($r['TABLE_TYPE']!=='BASE TABLE' || strcasecmp((string)$r['ENGINE'],'InnoDB')!==0)
        throw new RuntimeException('service_plan_journal_invalid');
    return achbotProviderRow($db,"SELECT id FROM service_plan_changes FORCE INDEX(idx_shared_plan_lock_state)
        WHERE lock_key=? AND state<>'committed' LIMIT 1 FOR UPDATE",'s',[$lock])!==null;
}

/** Rebinding a logical endpoint must not strand a charged purchase before
 * its first order exists. Ordinary credential rotation remains allowed. */
function achbotServiceServerWorkSettledLocked(mysqli $db,array $serverIds): bool {
    if (!achbotServiceOperationsPresent($db)) return true;
    $ids=[];
    foreach ($serverIds as $id) {
        if (!is_int($id) || $id<=0) throw new RuntimeException('service_ownership_server_invalid');
        $ids[$id]=$id;
    }
    if (!$ids) throw new RuntimeException('service_ownership_server_invalid');
    $list=implode(',',$ids);
    return achbotProviderRow($db,"SELECT id FROM service_operations FORCE INDEX(idx_service_target_stage)
        WHERE target_server_id IN ($list) AND stage IN ('funded','prepared','dispatching','applied')
        LIMIT 1 FOR UPDATE")===null;
}

/** One newly purchased original ordinal consumed one slot of its parent
 * reservation. Current logical plan/price/routing is not that evidence.
 * Explicit UI deletion can restore that slot; Cron/local-only policy does
 * not. No capacity is transferred or invented on a later alias. */
function achbotServiceBuyCapacityServerLocked(mysqli $db,array $order,array $effect,bool $finalizing): ?int {
    $op=achbotServiceOriginalBuyLocked($db,$effect);
    if ($op['stage']!=='completed' || $effect['state']!=='local_recorded' ||
        (int)$effect['order_id']!==(int)$order['id'] ||
        (int)$order['provider_effect_id']!==(int)$effect['id'] ||
        (int)$order['provider_remote_user_id']<=0 ||
        (int)$order['provider_remote_user_id']!==(int)$effect['provider_remote_user_id'] ||
        $order['provider_remote_created_at']!==$effect['provider_remote_created_at'] ||
        $order['remark']!==$effect['remote_username'] ||
        ($finalizing && $effect['provider_state']!=='deleted'))
        throw new RuntimeException('deletion_capacity_original_buy_unverified');
    $sid=(int)$op['target_server_id'];
    $r=achbotProviderRow($db,'SELECT id FROM server_info WHERE id=? FOR UPDATE','i',[$sid]);
    if ($r!==null) return $sid;
    // A deliberately hard-deleted original logical server has no inventory
    // to restore. Do not credit the relocated account's different server.
    if (achbotProviderRow($db,'SELECT id FROM server_config WHERE id=? FOR UPDATE','i',[$sid])!==null)
        throw new RuntimeException('deletion_capacity_original_server_incomplete');
    return null;
}
