<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/../Panel/ProviderStorage.php';

/** New runtime requires transactional service ownership storage. */
function achbotServiceOperationsPresent(mysqli $db): bool {
    $r=achbotProviderRow($db,"SELECT TABLE_TYPE,ENGINE FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='service_operations'");
    if ($r===null) throw new RuntimeException('service_ownership_schema_missing');
    if ($r['TABLE_TYPE']!=='BASE TABLE' || strcasecmp((string)$r['ENGINE'],'InnoDB')!==0)
        throw new RuntimeException('service_ownership_schema_invalid');
    $s=achbotProviderStatement($db,'SELECT resolution_kind,resolved_by,resolved_at,refund_entry_id,cancelled_count FROM service_operations LIMIT 0');
    $s->close();
    return true;
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
        (int)$effect['ordinal']<1 || (int)$effect['ordinal']>$q['quantity']-(int)$op['cancelled_count'] ||
        (int)$op['userid']!==(int)$effect['actor_userid'] ||
        ($q['server_id']??null)!==(int)$effect['target_server_id'] ||
        ($q['plan_id']??null)!==(int)$effect['plan_id'] ||
        ($quote['target']['physical']??null)!==$effect['target_key'])
        throw new RuntimeException('service_original_buy_reservation_conflict');
    return $op;
}

/** Current draft references are invalidated by a verified subject change.
 * Historical rows with no sealed draft are accounting evidence only.
 * The canonical order index scopes locking to this subject and its gap. */
function achbotServiceDraftRowsLocked(mysqli $db,array $orderIds): array {
    $ids=[];
    foreach ($orderIds as $id) {
        if (!is_int($id) || $id<=0) throw new RuntimeException('service_draft_order_invalid');
        $ids[$id]=$id;
    }
    if (!$ids) return [];
    ksort($ids,SORT_NUMERIC);
    $list=implode(',',$ids);
    $s=achbotProviderStatement($db,"SELECT id,state,type,user_id,order_id,checkout_draft_context
        FROM pays FORCE INDEX (idx_pays_order_ref_v1)
        WHERE order_id IN ($list) AND checkout_draft_context IS NOT NULL
            AND state IN ('pending','send','have_sent','low_wallet')
        ORDER BY order_id,id FOR UPDATE");
    $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
    return $rows;
}

/** Call while holding ALL current order references. Funding also locks its
 * original order before changing awaiting_funds -> funded. The indexed
 * current read and those row locks serialize the two decisions without a
 * database-wide scan or a new session lock. Only genuinely funded work
 * holds the subject; unpaid quotes are retired in the real change commit. */
function achbotServiceSubjectWorkSettledLocked(mysqli $db,array $peers,bool $retire=false): bool {
    achbotServiceOperationsPresent($db);
    $ids=[];$anchors=[];$owners=[];
    foreach ($peers as $o) {
        $id=(int)($o['id']??0);
        if ($id<=0) throw new RuntimeException('service_ownership_order_invalid');
        $ids[$id]=$id;$owners[$id]=(int)($o['userid']??0);
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
        // The financial receipt and its proof remain intact. Invalidating an
        // unpaid service quote never rejects money or prevents late credit.
        require_once __DIR__.'/../Finance/PaymentLifecycle.php';
        foreach (achbotServiceDraftRowsLocked($db,array_values($ids)) as $draft) {
            if (!achbotPayUxCurrentDraft($draft)) continue;
            $order=(int)$draft['order_id'];$type=(string)$draft['type'];
            if ($type!=='RENEW_ACCOUNT' &&
                !preg_match('/^INCREASE_(?:DAY|VOLUME)_([1-9][0-9]*)_[1-9][0-9]*$/D',$type,$m)) continue;
            if (($type!=='RENEW_ACCOUNT' && (int)$m[1]!==$order) ||
                ($owners[$order]??0)<=0 || $owners[$order]!==(int)$draft['user_id'])
                throw new RuntimeException('service_draft_subject_invalid');
            $s=achbotProviderStatement($db,"UPDATE pays SET state='service_superseded',updated_at=NOW()
                WHERE id=? AND state=?",'is',[(int)$draft['id'],$draft['state']]);
            $changed=$s->affected_rows;$s->close();
            if ($changed!==1) throw new RuntimeException('service_draft_retirement_unconfirmed');
        }
    }
    return true;
}

/** A prepared plan journal precedes its Native mutation marker. A new
 * monetary claim must not get between that intent and local finality.
 * Missing current journal storage fails closed. */
function achbotServicePlanChangePendingLocked(mysqli $db,string $lock): bool {
    $r=achbotProviderRow($db,"SELECT TABLE_TYPE,ENGINE FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='service_plan_changes'");
    if (!$r) throw new RuntimeException('service_plan_journal_missing');
    if ($r['TABLE_TYPE']!=='BASE TABLE' || strcasecmp((string)$r['ENGINE'],'InnoDB')!==0)
        throw new RuntimeException('service_plan_journal_invalid');
    return achbotProviderRow($db,"SELECT id FROM service_plan_changes FORCE INDEX(idx_shared_plan_lock_state)
        WHERE lock_key=? AND state<>'committed' LIMIT 1 FOR UPDATE",'s',[$lock])!==null;
}

/** Rebinding a logical endpoint must not strand a charged purchase before
 * its first order exists. Ordinary credential rotation remains allowed. */
function achbotServiceServerWorkSettledLocked(mysqli $db,array $serverIds): bool {
    achbotServiceOperationsPresent($db);
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
