<?php
/** One physical subscription may have several independently owned local references. */
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}

/** Stable process lock name shared by transfer and plan selection. */
function achbotPhysicalLockName(mysqli $db,array $order): string {
    $server=(int)$order['server_id'];
    $type=achbotProviderType($db,$server);
    if ($type==='pasarguard') {
        // Direct admin transfer entrypoints load the shared provider runtime
        // without opening PG storage until needed. Resolve it lazily here.
        achbotPgLoad();
        $account=achbotProviderAccountByOrder($db,(int)$order['id']);
        if (!$account) throw new RuntimeException('physical_account_unverified');
        return 'achbot-plan-'.substr(hash('sha256','pg:'.$account['id']),0,40);
    }
    if ($type==='marzban') {
        $group=achbotPanelGroup($db,$server);
        $s=$db->prepare("SELECT panel_url FROM server_config WHERE id=? AND type='marzban'");
        $s->bind_param('i',$server);$s->execute();$config=$s->get_result()->fetch_assoc();$s->close();
        if (!$config) throw new RuntimeException('physical_panel_unavailable');
        // An ungrouped subscription is anchored to its immutable logical
        // server ID. A URL is an editable connection, never a durable lock ID.
        $scope=$group===null?'server:'.$server:'group:'.$group['id'];
        return 'achbot-edit-'.substr(hash('sha256',$scope."\n".$order['remark']),0,40);
    }
    throw new RuntimeException('physical_provider_unsupported');
}
/**
 * An administrator may not silently rebind an active physical identity or
 * strand an unfinished plan journal. This runs inside the administrator's
 * existing transaction after locking the target configuration.
 *
 * The table may be absent on an older installation before source-only 017;
 * an installed but malformed journal is not treated as absent.
 */
function achbotPhysicalAssertRebindSafe(mysqli $db,int $serverId,
    bool $wholeGroup=false,bool $denyActive=true): void {
    $s=$db->prepare('SELECT i.panel_group_id,c.type FROM server_info i
        JOIN server_config c ON c.id=i.id WHERE i.id=? FOR UPDATE');
    $s->bind_param('i',$serverId);$s->execute();$row=$s->get_result()->fetch_assoc();$s->close();
    if (!$row || !in_array($row['type'],['marzban','pasarguard'],true))
        throw new RuntimeException('physical_rebind_target_missing');
    $ids=[$serverId];
    if ($wholeGroup && $row['panel_group_id']!==null) {
        $gid=(int)$row['panel_group_id'];$provider=(string)$row['type'];
        $s=$db->prepare('SELECT i.id FROM server_info i JOIN server_config c ON c.id=i.id
            WHERE i.panel_group_id=? AND c.type=? ORDER BY i.id');
        $s->bind_param('is',$gid,$provider);$s->execute();
        foreach ($s->get_result()->fetch_all(MYSQLI_ASSOC) as $peer) $ids[]=(int)$peer['id'];
        $s->close();
    }
    $ids=array_values(array_unique($ids));
    $list=implode(',',$ids); // IDs are validated integers from our own tables.
    if ($denyActive) {
        $r=$db->query("SELECT id FROM orders_list WHERE status=1 AND server_id IN ($list)
            ORDER BY id LIMIT 1 FOR UPDATE");
        if (!$r || $r->num_rows>0) throw new RuntimeException('physical_rebind_active_references');
        $r->free();
    }
    $r=$db->query("SELECT TABLE_TYPE,ENGINE FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='service_plan_changes'");
    if (!($r instanceof mysqli_result)) throw new RuntimeException('physical_rebind_journal_unavailable');
    $shape=$r->fetch_assoc();$count=$r->num_rows;$r->free();
    if ($shape===null) return; // 017 not yet installed; no plan journal could have been created.
    if ($count!==1 || $shape['TABLE_TYPE']!=='BASE TABLE' ||
        strcasecmp((string)$shape['ENGINE'],'InnoDB')!==0)
        throw new RuntimeException('physical_rebind_journal_unavailable');
    $r=$db->query("SELECT server_id,intent_json FROM service_plan_changes
        WHERE state<>'committed' ORDER BY id FOR UPDATE");
    if (!($r instanceof mysqli_result)) throw new RuntimeException('physical_rebind_journal_unavailable');
    while ($pending=$r->fetch_assoc()) {
        if (in_array((int)$pending['server_id'],$ids,true)) {
            $r->free();throw new RuntimeException('physical_rebind_pending_journal');
        }
        try {$intent=json_decode((string)$pending['intent_json'],true,64,JSON_THROW_ON_ERROR);}
        catch (Throwable $ignored) {$r->free();throw new RuntimeException('physical_rebind_journal_unavailable');}
        if (!is_array($intent) || !is_array($intent['old_rows']??null)) {
            $r->free();throw new RuntimeException('physical_rebind_journal_unavailable');
        }
        foreach ($intent['old_rows'] as $peer)
            if (is_array($peer) && in_array((int)($peer['server_id']??0),$ids,true)) {
                $r->free();throw new RuntimeException('physical_rebind_pending_journal');
            }
    }
    $r->free();
}

/** Exact connected target evidence, persisted as a digest rather than secrets. */
function achbotPhysicalTargetFingerprint(mysqli $db,array $order): string {
    $server=(int)$order['server_id'];
    // Marzban stays usable on its own post-V9 schema: optional PasarGuard
    // provider_identity columns are NOT a prerequisite for this target pin.
    $s=$db->prepare('SELECT c.id,c.type,c.panel_url,c.username,c.password,
        i.panel_group_id FROM server_config c JOIN server_info i ON i.id=c.id
        WHERE c.id=? FOR UPDATE');
    $s->bind_param('i',$server);$s->execute();$row=$s->get_result()->fetch_assoc();$s->close();
    if (!$row || !in_array($row['type'],['marzban','pasarguard'],true))
        throw new RuntimeException('physical_target_unavailable');
    $pin=[$row,(int)$order['id'],(string)$order['remark']];
    if ($row['type']==='pasarguard') {
        $p=achbotPgServerPanel($db,$server);
        $pin[]=[(int)$p['id'],$p['instance_key'],$p['api_url'],$p['version'],
            hash('sha256',$p['api_key'])];
    }
    return hash('sha256',json_encode($pin,JSON_THROW_ON_ERROR));
}
function achbotPhysicalPgIdentityFingerprint(array $ctx): string {
    return hash('sha256',json_encode([
        (int)$ctx['account']['id'],$ctx['identity'],
        (int)$ctx['order_server_id']
    ],JSON_THROW_ON_ERROR));
}
function achbotPhysicalAcquireLock(mysqli $db,string $name): void {
    $s=$db->prepare('SELECT GET_LOCK(?,3) AS acquired');
    $s->bind_param('s',$name);$s->execute();
    $r=$s->get_result()->fetch_assoc();$s->close();
    if ((int)($r['acquired']??0)!==1)
        throw new RuntimeException('physical_operation_busy');
}
function achbotPhysicalReleaseLock(mysqli $db,string $name): void {
    try {$s=$db->prepare('SELECT RELEASE_LOCK(?)');$s->bind_param('s',$name);
        $s->execute();$s->close();
    } catch (Throwable $ignored) {}
}
/**
 * Return ALL active rows referencing this exact panel subscription in stable
 * primary-key order. Callers own a transaction when $lock is true.
 *
 * Marzban: native account key = trusted admin-declared physical group (or
 * one logical server when ungrouped) + exact native username.
 * PasarGuard: the immutable linked service-effect ID/physical incarnation;
 * never infer an account from a username or URL.
 */
function achbotPhysicalActiveOrders(mysqli $db,array $selected,bool $lock=false): array {
    $server=(int)($selected['server_id']??0);
    $orderId=(int)($selected['id']??0);
    $remark=(string)($selected['remark']??'');
    if ($server<=0 || $orderId<=0 || $remark===''
        || (int)($selected['status']??0)!==1)
        throw new RuntimeException('physical_order_unavailable');
    $type=achbotProviderType($db,$server);
    if ($type==='pasarguard') {
        achbotPgLoad();
        $anchor=achbotProviderAccountByOrder($db,$orderId,$lock);
        if (!$anchor || !in_array($anchor['state'],['linked','verified'],true))
            throw new RuntimeException('physical_account_unverified');
        $effect=(int)$anchor['effect_id'];
        $s=$db->prepare('SELECT * FROM orders_list WHERE provider_effect_id=? AND status=1 ORDER BY id'.($lock?' FOR UPDATE':''));
        $s->bind_param('i',$effect);
    } elseif ($type==='marzban') {
        $group=achbotPanelGroup($db,$server);
        if ($group!==null) {
            $gid=(int)$group['id'];
            $s=$db->prepare("SELECT o.* FROM orders_list o
                JOIN server_info i ON i.id=o.server_id
                JOIN server_config c ON c.id=o.server_id AND c.type='marzban'
                WHERE o.status=1 AND i.panel_group_id=?
                  AND BINARY o.remark=BINARY ? ORDER BY o.id".($lock?' FOR UPDATE':''));
            $s->bind_param('is',$gid,$remark);
        } else {
            $s=$db->prepare("SELECT o.* FROM orders_list o
                JOIN server_config c ON c.id=o.server_id AND c.type='marzban'
                WHERE o.status=1 AND o.server_id=?
                  AND BINARY o.remark=BINARY ? ORDER BY o.id".($lock?' FOR UPDATE':''));
            $s->bind_param('is',$server,$remark);
        }
    } else throw new RuntimeException('physical_provider_unsupported');
    $s->execute();$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
    $contains=false;
    foreach ($rows as $row) {
        if ((int)$row['id']===$orderId) $contains=true;
        if ((string)$row['remark']!==$remark)
            throw new RuntimeException('physical_reference_inconsistent');
        if ($type==='pasarguard') {
            $peer=achbotProviderAccountByOrder($db,(int)$row['id'],$lock);
            if (!$peer || $peer['id']!==$anchor['id'] ||
                !achbotSameProviderPanel($db,$server,(int)$row['server_id']))
                throw new RuntimeException('physical_reference_conflict');
        }
    }
    if (!$contains || !$rows) throw new RuntimeException('physical_reference_missing');
    return $rows;
}

/** All-or-nothing local transition: callers hold row locks and own COMMIT. */
function achbotPhysicalUpdateOrders(mysqli $db,array $rows,int $serverId,int $planId,bool $clearRenew=false): int {
    if (!$rows || $serverId<=0 || $planId<0)
        throw new RuntimeException('physical_order_update_invalid');
    $sql="UPDATE orders_list SET server_id=?,fileid=?".
        ($clearRenew?",autoRenew='off'":"").
        " WHERE id=? AND server_id=? AND fileid=? AND status=1";
    $s=$db->prepare($sql);
    $modified=0;
    foreach ($rows as $row) {
        $id=(int)$row['id'];$originalServer=(int)$row['server_id'];
        $originalPlan=(int)$row['fileid'];
        if ($serverId===$originalServer && $planId===$originalPlan && !$clearRenew) {
            // Still require exact-current state; do not trust a stale row.
            $check=$db->prepare('SELECT id FROM orders_list WHERE id=? AND server_id=? AND fileid=? AND status=1 FOR UPDATE');
            $check->bind_param('iii',$id,$originalServer,$originalPlan);
            $check->execute();$exists=$check->get_result()->num_rows===1;$check->close();
            if (!$exists) throw new RuntimeException('physical_order_changed');
            ++$modified;continue;
        }
        $s->bind_param('iiiii',$serverId,$planId,$id,$originalServer,$originalPlan);
        if (!$s->execute() || $s->affected_rows!==1)
            throw new RuntimeException('physical_order_update_unconfirmed');
        ++$modified;
    }
    $s->close();
    return $modified;
}

/** A failed/native-uncertain plan operation is never retried from a callback. */
function achbotPhysicalPlanResult(bool $ok,string $reason='',int $count=0): array {
    return ['success'=>$ok,'reason'=>$reason,'shared_count'=>$count];
}

/** Reuse the cross-ledger account settlement contract; never shortcut it to pays.state. */
function achbotPhysicalNoPendingPayments(mysqli $db,array $rows): void {
    require_once __DIR__.'/../Finance/ReceiptHelpers.php';
    // Plan/transfer changes may orphan even an unclaimed quote; unlike
    // automatic renewal they require a fully quiet physical subscription.
    if (!achbotSharedAccountWorkSettledLocked($db,$rows,0,0,true,true))
        throw new RuntimeException('physical_pending_financial_effect');
}

/**
 * Recheck source rows, target and all financial ledgers at the LAST local
 * boundary before a Native access PUT. This check also works after the
 * PasarGuard service_effects account fence entered 'mutating', when normal
 * account-discovery helpers deliberately refuse to return it as linked.
 * The physical journal remains an unretryable hold on any failure.
 */
function achbotPhysicalBeforeNativeWrite(mysqli $db,array $intent,int $journalId): void {
    $transaction=false;$commitAttempted=false;
    try {
        if (!$db->begin_transaction()) throw new RuntimeException('physical_before_put_begin');
        $transaction=true;
        $s=$db->prepare("SELECT id FROM service_plan_changes
            WHERE id=? AND lock_key=? AND state='dispatching' FOR UPDATE");
        $s->bind_param('is',$journalId,$intent['lock_key']);$s->execute();
        $valid=$s->get_result()->num_rows===1;$s->close();
        if (!$valid) throw new RuntimeException('physical_before_put_journal_changed');
        $old=$intent['old_rows'];
        if (!$old || count($old)>10000) throw new RuntimeException('physical_before_put_references_invalid');
        $ids=array_map(static fn($r)=>(int)$r['id'],$old);
        if (min($ids)<=0 || count(array_unique($ids))!==count($ids))
            throw new RuntimeException('physical_before_put_references_invalid');
        $list=implode(',',$ids);
        $r=$db->query("SELECT * FROM orders_list WHERE id IN ($list) ORDER BY id FOR UPDATE");
        if (!($r instanceof mysqli_result)) throw new RuntimeException('physical_before_put_references_invalid');
        $rows=$r->fetch_all(MYSQLI_ASSOC);$r->free();
        $now=array_map(static fn($r)=>['id'=>(int)$r['id'],
            'server_id'=>(int)$r['server_id'],'fileid'=>(int)$r['fileid']],$rows);
        if ($now!==$old || count($rows)!==count($old))
            throw new RuntimeException('physical_before_put_references_changed');
        foreach ($rows as $row)
            if ((int)$row['status']!==1 ||
                $row['remark']!==$rows[0]['remark'])
                throw new RuntimeException('physical_before_put_references_changed');
        $selected=null;
        foreach ($rows as $row)
            if ((int)$row['id']===(int)$intent['source_order_id']) $selected=$row;
        if ($selected===null || (int)$selected['server_id']!==(int)$intent['selected_server_id'])
            throw new RuntimeException('physical_before_put_subject_changed');
        if (achbotPhysicalTargetFingerprint($db,$selected)!==$intent['physical_target_sha256'])
            throw new RuntimeException('physical_before_put_target_changed');
        achbotPhysicalNoPendingPayments($db,$rows);
        $commitAttempted=true;
        if (!$db->commit()) throw new RuntimeException('physical_before_put_commit_unknown');
        $transaction=false;
    } catch (Throwable $error) {
        if ($transaction && !$commitAttempted) {try{$db->rollback();}catch(Throwable $ignored){}}
        throw $error;
    }
}

function achbotPhysicalPlanFingerprint(array $plan): string {
    return hash('sha256',json_encode([
        (int)$plan['id'],(int)$plan['server_id'],(int)$plan['active'],
        (int)$plan['inbound_id'],(string)($plan['custom_sni']??''),
        $plan['provider_group_ids']??null
    ],JSON_THROW_ON_ERROR));
}

/** Authorize and choose a native access plan, then synchronize every local reference.
 *
 * The immutable service_plan_changes row is COMMITTED before any provider HTTP
 * mutation. It retains reconciliation evidence across crashes. Any uncertain
 * remote/DB result holds its physical subscription; no automatic second PUT.
 */
function achbotPhysicalSelectPlan(mysqli $db,int $orderId,int $newPlanId,int $actorId,bool $adminAllowed=false): array {
    if (min($orderId,$newPlanId,$actorId)<=0)
        return achbotPhysicalPlanResult(false,'physical_plan_invalid');
    require_once __DIR__.'/../Admin/Access.php';
    require_once __DIR__.'/../Panel/MarzbanPlanAccess.php';
    $lease=null;$tx=false;$journalId=0;$dispatch=false;$commitAttempted=false;
    try {
        $s=$db->prepare('SELECT * FROM orders_list WHERE id=? AND status=1');
        $s->bind_param('i',$orderId);$s->execute();$initial=$s->get_result()->fetch_assoc();$s->close();
        if (!$initial) throw new RuntimeException('physical_order_unavailable');
        $lease=achbotPhysicalLockName($db,$initial);
        achbotPhysicalAcquireLock($db,$lease);
        if (!$db->begin_transaction()) throw new RuntimeException('physical_plan_begin');
        $tx=true;
        $s=$db->prepare('SELECT userid,isAdmin,step FROM users WHERE userid=? LIMIT 2 FOR UPDATE');
        $s->bind_param('i',$actorId);$s->execute();$actors=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
        if (count($actors)!==1 || $actors[0]['step']==='banned')
            throw new RuntimeException('physical_actor_unavailable');
        $s=$db->prepare('SELECT * FROM orders_list WHERE id=? AND status=1 FOR UPDATE');
        $s->bind_param('i',$orderId);$s->execute();$order=$s->get_result()->fetch_assoc();$s->close();
        if (!$order || achbotPhysicalLockName($db,$order)!==$lease)
            throw new RuntimeException('physical_order_changed');
        $admin=achbotAdminCanLocked($db,$actorId,(int)($GLOBALS['admin']??0),'orders');
        // Admin UI callbacks must never silently fall back to customer-own
        // access merely because the same customer owns this order.
        if ($adminAllowed && !$admin)
            throw new RuntimeException('physical_admin_intent_denied');
        if (!$admin && (int)$order['userid']!==$actorId)
            throw new RuntimeException('physical_actor_denied');
        $server=(int)$order['server_id'];
        $provider=achbotProviderType($db,$server);
        if (!in_array($provider,['marzban','pasarguard'],true) ||
            !achbotSupportsOrder($db,$orderId,null,(int)$order['userid'],true))
            throw new RuntimeException('physical_service_unavailable');
        $s=$db->prepare('SELECT p.* FROM server_plans p WHERE p.id=? AND p.server_id=? AND p.active=1 AND p.inbound_id=0 FOR UPDATE');
        $s->bind_param('ii',$newPlanId,$server);$s->execute();$plan=$s->get_result()->fetch_assoc();$s->close();
        if (!$plan || !achbotSupportsPlan($db,$newPlanId,0,$server,true))
            throw new RuntimeException('physical_plan_unavailable');
        $groups=null;$inbounds=null;
        if ($provider==='pasarguard') {
            $snapshot=achbotPgPlanSnapshot($db,$server,$newPlanId,true);
            $groups=$snapshot['group_ids'];
        } else $inbounds=achbotMarzbanPlanInbounds($plan);
        if (!$admin) {
            require_once __DIR__.'/../Purchase/OrderWrites.php';
            if (!achbotOrderMayUsePaidPlan($db,$order,true))
                throw new RuntimeException('physical_plan_not_eligible');
        }
        $all=achbotPhysicalActiveOrders($db,$order,true);
        foreach ($all as $peer) {
            $peerServer=(int)$peer['server_id'];
            if ($peerServer!==$server && (!achbotSamePanelGroup($db,$peerServer,$server) ||
                !achbotSameProviderPanel($db,$peerServer,$server)))
                throw new RuntimeException('physical_peer_server_mismatch');
        }
        achbotPhysicalNoPendingPayments($db,$all);
        $targetSha=achbotPhysicalTargetFingerprint($db,$order);
        $pgIdentitySha=$provider==='pasarguard'?
            achbotPhysicalPgIdentityFingerprint(achbotPgOrderContext($db,$orderId,true)):null;
        $s=$db->prepare("SELECT id FROM service_plan_changes
            WHERE lock_key=? AND state<>'committed' LIMIT 1 FOR UPDATE");
        $s->bind_param('s',$lease);$s->execute();
        $held=$s->get_result()->num_rows>0;$s->close();
        if ($held) throw new RuntimeException('physical_plan_requires_review');
        // Do not mistake an already-selected fileid for verified native
        // access. A same-plan selection must still read and reconcile the
        // provider Hosts/Groups; only the Native readback proves equivalence.
        $intent=['version'=>1,'provider'=>$provider,'lock_key'=>$lease,
            'source_order_id'=>$orderId,'selected_server_id'=>$server,
            'new_plan_id'=>$newPlanId,'plan_sha256'=>achbotPhysicalPlanFingerprint($plan),
            'physical_target_sha256'=>$targetSha,'pg_identity_sha256'=>$pgIdentitySha,
            'old_rows'=>array_map(static fn($r)=>[
                'id'=>(int)$r['id'],'server_id'=>(int)$r['server_id'],
                'fileid'=>(int)$r['fileid']
            ],$all),
            'native_target'=>$provider==='pasarguard'?['groups'=>$groups]:['inbounds'=>$inbounds],
            'created_at'=>gmdate('c')];
        $json=json_encode($intent,JSON_THROW_ON_ERROR);
        $s=$db->prepare("INSERT INTO service_plan_changes
            (lock_key,source_order_id,plan_id,server_id,provider,state,intent_json)
            VALUES (?,?,?,?,?,'prepared',?)");
        $s->bind_param('siiiss',$lease,$orderId,$newPlanId,$server,$provider,$json);
        if (!$s->execute() || $s->affected_rows!==1)
            throw new RuntimeException('physical_plan_journal_unavailable');
        $journalId=(int)$s->insert_id;$s->close();
        $commitAttempted=true;
        if (!$db->commit()) throw new RuntimeException('physical_plan_intent_commit_unknown');
        $tx=false;$commitAttempted=false;
        $s=$db->prepare("UPDATE service_plan_changes SET state='dispatching' WHERE id=? AND state='prepared'");
        $s->bind_param('i',$journalId);
        if (!$s->execute() || $s->affected_rows!==1)
            throw new RuntimeException('physical_plan_dispatch_hold');
        $s->close();$dispatch=true;
        // A payment/renewal may have started after the first durable intent
        // commit. Re-lock and recheck every linked reference immediately
        // before Native GET/PUT, without holding a SQL transaction over HTTP.
        if (!$db->begin_transaction()) throw new RuntimeException('physical_plan_preflight_begin');
        $tx=true;
        $s=$db->prepare("SELECT id FROM service_plan_changes
            WHERE id=? AND state='dispatching' FOR UPDATE");
        $s->bind_param('i',$journalId);$s->execute();
        $pending=$s->get_result()->num_rows===1;$s->close();
        if (!$pending) throw new RuntimeException('physical_plan_journal_changed');
        $s=$db->prepare('SELECT * FROM orders_list WHERE id=? AND status=1 FOR UPDATE');
        $s->bind_param('i',$orderId);$s->execute();$preOrder=$s->get_result()->fetch_assoc();$s->close();
        if (!$preOrder || (int)$preOrder['server_id']!==$server ||
            $preOrder['remark']!==$order['remark'])
            throw new RuntimeException('physical_plan_order_changed');
        $prePeers=achbotPhysicalActiveOrders($db,$preOrder,true);
        $preRows=array_map(static fn($r)=>['id'=>(int)$r['id'],
            'server_id'=>(int)$r['server_id'],'fileid'=>(int)$r['fileid']],$prePeers);
        if ($preRows!==$intent['old_rows'])
            throw new RuntimeException('physical_plan_references_changed');
        if (achbotPhysicalTargetFingerprint($db,$preOrder)!==$targetSha)
            throw new RuntimeException('physical_plan_target_changed');
        if ($provider==='pasarguard' &&
            achbotPhysicalPgIdentityFingerprint(achbotPgOrderContext($db,$orderId,true))!==$pgIdentitySha)
            throw new RuntimeException('physical_pg_identity_changed');
        achbotPhysicalNoPendingPayments($db,$prePeers);
        $commitAttempted=true;
        if (!$db->commit()) throw new RuntimeException('physical_plan_preflight_commit_unknown');
        $tx=false;$commitAttempted=false;
        $pgMutation=null;$pgContext=null;$pgResult=null;
        // This callback executes only when Native access ACTUALLY differs.
        // Unchanged Native access has zero PUT and zero redundant catalog reads.
        $beforeWrite=static function() use($db,$intent,$journalId):void {
            achbotPhysicalBeforeNativeWrite($db,$intent,$journalId);
        };
        if ($provider==='pasarguard') {
            $pgContext=achbotPgOrderContext($db,$orderId);
            if ((int)$pgContext['order_server_id']!==$server ||
                achbotPhysicalPgIdentityFingerprint($pgContext)!==$pgIdentitySha)
                throw new RuntimeException('physical_pg_target_changed');
            $current=achbotPgRead($pgContext['panel'],$pgContext['identity']);
            if (($current['success']??false)!==true || !achbotPgManaged($current['user']))
                throw new RuntimeException('physical_pg_remote_unavailable');
            if ($current['user']['group_ids']!==$groups) {
                // Identical current Native Groups require no mutation. For
                // real changes, re-check current admin Group permissions and
                // enabled Groups before the single fenced Native PUT.
                $check=achbotPgCheck($pgContext['panel']);
                if (($check['success']??false)!==true ||
                    array_diff($groups,achbotPgEligibleGroupIds($check)))
                    throw new RuntimeException('physical_pg_group_authority_unavailable');
                // The durable Native account fence survives any interrupted
                // HTTP response. Never automatically call it a second time.
                $pgMutation=achbotPgBeginMutation($db,$pgContext,'plan:'.$journalId,
                    'select_plan',['plan_id'=>$newPlanId,'group_ids'=>$groups]);
                $pgMutation=achbotPgPersistMutationIntent($db,$pgContext,$pgMutation,[
                    'group_ids'=>$groups,'before'=>$current['user']['group_ids']]);
                $pgResult=achbotPgModify($pgContext['panel'],$pgContext['identity'],
                    ['group_ids'=>$groups],$current['user']['group_ids'],$beforeWrite);
                if (($pgResult['success']??false)!==true ||
                    $pgResult['user']['group_ids']!==$groups ||
                    $pgResult['user']['used_traffic']<$current['user']['used_traffic'])
                    throw new RuntimeException('physical_pg_access_unverified');
            }
        } else {
            $result=achbotMarzbanApplyPlanHosts($server,(string)$order['remark'],$inbounds,$beforeWrite);
            if (($result['success']??false)!==true ||
                ($result['verified']??false)!==true) {
                // Only known, bounded adapter reason codes enter diagnostics.
                // No remote response, account data or credentials are logged.
                $detail=(string)($result['reason']??'');
                if (!preg_match('/\Amarzban_[a-z0-9_]{3,72}\z/D',$detail))
                    $detail='physical_marzban_access_unverified';
                throw new RuntimeException($detail);
            }
        }
        if (!$db->begin_transaction()) throw new RuntimeException('physical_plan_finalize_begin');
        $tx=true;
        $s=$db->prepare('SELECT * FROM service_plan_changes WHERE id=? AND state=\'dispatching\' FOR UPDATE');
        $s->bind_param('i',$journalId);$s->execute();$effect=$s->get_result()->fetch_assoc();$s->close();
        if (!$effect || $effect['intent_json']!==$json)
            throw new RuntimeException('physical_plan_journal_changed');
        $s=$db->prepare('SELECT * FROM orders_list WHERE id=? AND status=1 FOR UPDATE');
        $s->bind_param('i',$orderId);$s->execute();$freshOrder=$s->get_result()->fetch_assoc();$s->close();
        if (!$freshOrder || $freshOrder['server_id']!=$server ||
            $freshOrder['remark']!==$order['remark'])
            throw new RuntimeException('physical_plan_order_changed');
        // PG native effect is currently 'mutating'. Finish it first in the
        // very same transaction, so a peer read may verify its exact linked
        // identity. Any subsequent failure rolls this finish back and leaves
        // the durable native hold in place.
        if (achbotPhysicalTargetFingerprint($db,$freshOrder)!==$intent['physical_target_sha256'])
            throw new RuntimeException('physical_plan_target_changed');
        if ($pgMutation!==null) achbotPgFinishMutation($db,$pgContext,$pgMutation,$pgResult);
        if ($provider==='pasarguard' &&
            achbotPhysicalPgIdentityFingerprint(achbotPgOrderContext($db,$orderId,true))!==$intent['pg_identity_sha256'])
            throw new RuntimeException('physical_pg_identity_changed');
        $fresh=achbotPhysicalActiveOrders($db,$freshOrder,true);
        $fingerprint=array_map(static fn($r)=>[
            'id'=>(int)$r['id'],'server_id'=>(int)$r['server_id'],
            'fileid'=>(int)$r['fileid']
        ],$fresh);
        if ($fingerprint!==$intent['old_rows'])
            throw new RuntimeException('physical_plan_references_changed');
        $s=$db->prepare('SELECT p.* FROM server_plans p WHERE p.id=? AND p.server_id=? FOR UPDATE');
        $s->bind_param('ii',$newPlanId,$server);$s->execute();$freshPlan=$s->get_result()->fetch_assoc();$s->close();
        if (!$freshPlan || achbotPhysicalPlanFingerprint($freshPlan)!==$intent['plan_sha256'])
            throw new RuntimeException('physical_plan_changed_during_effect');
        achbotPhysicalNoPendingPayments($db,$fresh);
        $count=achbotPhysicalUpdateOrders($db,$fresh,$server,$newPlanId);
        $s=$db->prepare("UPDATE service_plan_changes SET state='committed' WHERE id=? AND state='dispatching'");
        $s->bind_param('i',$journalId);
        if (!$s->execute() || $s->affected_rows!==1)
            throw new RuntimeException('physical_plan_finalize_unknown');
        $s->close();
        $commitAttempted=true;
        if (!$db->commit()) throw new RuntimeException('physical_plan_finalize_commit_unknown');
        $tx=false;$commitAttempted=false;
        error_log('achbot_shared_plan_committed journal_id='.$journalId.
            ' source_order_id='.$orderId.' active_refs='.$count);
        return achbotPhysicalPlanResult(true,'committed',$count);
    } catch (Throwable $error) {
        if ($tx) {try{$db->rollback();}catch(Throwable $ignored){}}
        $reason=(string)$error->getMessage();
        $safe=preg_match('/\A[a-z0-9_]{5,100}\z/D',$reason)===1?$reason:'internal_exception';
        if ($journalId>0 && !$commitAttempted && !$dispatch) {
            try {
                $s=$db->prepare("UPDATE service_plan_changes SET state='review'
                    WHERE id=? AND state='prepared'");
                $s->bind_param('i',$journalId);$s->execute();$s->close();
            } catch (Throwable $ignored) {}
        }
        // Rejected stale/forged plan callbacks are normal expected denials;
        // do not emit an error for them. A durable journal, ambiguous write,
        // unusual exception, or unknown native effect always emits a bounded
        // reconciliation diagnostic with its real journal ID.
        $denials=['physical_order_unavailable','physical_order_changed',
            'physical_actor_unavailable','physical_admin_intent_denied',
            'physical_actor_denied','physical_service_unavailable',
            'physical_plan_unavailable','physical_plan_not_eligible',
            'physical_peer_server_mismatch','physical_pending_financial_effect',
            'physical_plan_requires_review','physical_operation_busy'];
        if ($journalId>0 || $commitAttempted || $dispatch ||
            !in_array($safe,$denials,true))
            error_log('achbot_shared_plan_requires_review order_id='.$orderId.
                ' journal_id='.$journalId.' code='.$safe);
        return achbotPhysicalPlanResult(false,$journalId>0?
            'physical_plan_requires_review':$safe);
    } finally {
        if ($lease!==null) achbotPhysicalReleaseLock($db,$lease);
    }
}
