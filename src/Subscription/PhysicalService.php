<?php
/** One physical subscription may have several independently owned local references. */
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}

/** Stable process lock name shared by transfer and plan selection. */
function achbotPhysicalLockName(mysqli $db,array $order): string {
    $server=(int)$order['server_id'];
    $type=achbotProviderType($db,$server);
    if ($type==='pasarguard') {
        $account=achbotProviderAccountByOrder($db,(int)$order['id']);
        if (!$account) throw new RuntimeException('physical_account_unverified');
        return 'achbot-plan-'.substr(hash('sha256','pg:'.$account['id']),0,40);
    }
    if ($type==='marzban') {
        $group=achbotPanelGroup($db,$server);
        $s=$db->prepare("SELECT panel_url FROM server_config WHERE id=? AND type='marzban'");
        $s->bind_param('i',$server);$s->execute();$config=$s->get_result()->fetch_assoc();$s->close();
        if (!$config) throw new RuntimeException('physical_panel_unavailable');
        $scope=$group===null?'endpoint:'.rtrim((string)$config['panel_url'],'/'):'group:'.$group['id'];
        return 'achbot-edit-'.substr(hash('sha256',$scope."\n".$order['remark']),0,40);
    }
    throw new RuntimeException('physical_provider_unsupported');
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

/** Hold quotes/effects that would otherwise settle against a stale plan/server. */
function achbotPhysicalNoPendingPayments(mysqli $db,array $rows): void {
    $ids=array_map(static fn($r)=>(int)$r['id'],$rows);
    if (!$ids) throw new RuntimeException('physical_order_unavailable');
    $sql="SELECT id FROM pays WHERE order_id IN (".implode(',',$ids).
        ") AND state IN ('pending','processing','waiting','in_progress') LIMIT 1 FOR UPDATE";
    $r=$db->query($sql);
    if (!$r || $r->num_rows>0) throw new RuntimeException('physical_pending_financial_effect');
    $r->free();
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
        $admin=$adminAllowed &&
            achbotAdminCanLocked($db,$actorId,(int)($GLOBALS['admin']??0),'orders');
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
        $pgMutation=null;$pgContext=null;$pgResult=null;
        if ($provider==='pasarguard') {
            $pgContext=achbotPgOrderContext($db,$orderId);
            if ((int)$pgContext['order_server_id']!==$server)
                throw new RuntimeException('physical_pg_target_changed');
            $current=achbotPgRead($pgContext['panel'],$pgContext['identity']);
            if (($current['success']??false)!==true || !achbotPgManaged($current['user']))
                throw new RuntimeException('physical_pg_remote_unavailable');
            $check=achbotPgCheck($pgContext['panel']);
            if (($check['success']??false)!==true ||
                array_diff($groups,achbotPgEligibleGroupIds($check)))
                throw new RuntimeException('physical_pg_group_authority_unavailable');
            if ($current['user']['group_ids']!==$groups) {
                // The durable Native account fence survives any interrupted
                // HTTP response. Never automatically call it a second time.
                $pgMutation=achbotPgBeginMutation($db,$pgContext,'plan:'.$journalId,
                    'select_plan',['plan_id'=>$newPlanId,'group_ids'=>$groups]);
                $pgMutation=achbotPgPersistMutationIntent($db,$pgContext,$pgMutation,[
                    'group_ids'=>$groups,'before'=>$current['user']['group_ids']]);
                $pgResult=achbotPgModify($pgContext['panel'],$pgContext['identity'],
                    ['group_ids'=>$groups],$current['user']['group_ids']);
                if (($pgResult['success']??false)!==true ||
                    $pgResult['user']['group_ids']!==$groups)
                    throw new RuntimeException('physical_pg_access_unverified');
            }
        } else {
            $result=achbotMarzbanApplyPlanHosts($server,(string)$order['remark'],$inbounds);
            if (($result['success']??false)!==true ||
                ($result['verified']??false)!==true)
                throw new RuntimeException('physical_marzban_access_unverified');
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
        if ($pgMutation!==null) achbotPgFinishMutation($db,$pgContext,$pgMutation,$pgResult);
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
        error_log('achbot_shared_plan_requires_review order_id='.$orderId.
            ' journal_id='.$journalId.' code='.$safe);
        return achbotPhysicalPlanResult(false,$journalId>0?
            'physical_plan_requires_review':$safe);
    } finally {
        if ($lease!==null) achbotPhysicalReleaseLock($db,$lease);
    }
}
