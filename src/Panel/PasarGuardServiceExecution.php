<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}

/** Resolve only the original physical panel, independently of mutable
 * sales/catalog settings. Authorized credential rotation on that same
 * instance is permitted; a moved server never redirects a funded effect. */
function achbotPasarGuardBuySnapshot(mysqli $db,array $quote): array {
    achbotPgLoad();
    $t=$quote['target'];$q=$quote['request'];
    if (($t['provider']??null)!=='pasarguard' ||
        !is_string($t['physical']??null) || !str_starts_with($t['physical'],'pg:'))
        throw new RuntimeException('service_buy_target_invalid');
    $snapshot=achbotProviderCanonicalSnapshot(['provider'=>'pasarguard','panel_id'=>$t['panel_id'],
        'instance_key'=>substr($t['physical'],3),'endpoint_sha256'=>$t['endpoint'],'group_ids'=>$t['groups']]);
    $panel=achbotProviderPanelForSnapshot($db,$snapshot);
    $current=achbotPgServerPanel($db,$q['server_id']);
    if ($current['id']!==$panel['id'] || $current['instance_key']!==$panel['instance_key'] ||
        $current['api_url']!==$panel['api_url'] || $current['api_key']!==$panel['api_key'])
        throw new RuntimeException('service_buy_target_changed');
    return [$snapshot,$panel];
}
function achbotPasarGuardBuyDigest(array $op,array $snapshot,array $e): string {
    return hash('sha256',achbotServiceJson([$op['quote_sha256'],$snapshot,(int)$e['ordinal'],
        $e['operation_id'],$e['remote_username'],(int)$e['requested_expire'],(int)$e['requested_bytes']]));
}
/** Revalidate both the common original quote and the provider's persisted
 * evidence. The response cannot finalize a different or locally changed line. */
function achbotPasarGuardBuyEffectAccount(array $op,array $quote,array $snapshot,array $e,int $ordinal): array {
    $q=$quote['request'];
    if ($ordinal<1 || $ordinal>$q['quantity'] || (int)$e['ordinal']!==$ordinal ||
        $e['source_kind']!=='service_operation' || $e['source_ref']!==(string)$op['id'] ||
        $e['effect_kind']!=='create' || $e['pay_row_id']!==null ||
        (int)$e['actor_userid']!==(int)$op['userid'] || (int)$e['target_server_id']!==$q['server_id'] ||
        (int)$e['plan_id']!==$q['plan_id'] || (int)$e['reserved_count']!==0 ||
        $e['target_key']!==$quote['target']['physical'] || $e['endpoint_sha256']!==$snapshot['endpoint_sha256'] ||
        (int)$e['requested_expire']<=0 || (int)$e['requested_expire']>2147483647 ||
        (int)$e['requested_bytes']!==$q['volume']*1073741824 ||
        $e['provider_snapshot']!==json_encode($snapshot,JSON_THROW_ON_ERROR) ||
        !hash_equals($e['request_sha256'],achbotPasarGuardBuyDigest($op,$snapshot,$e)))
        throw new RuntimeException('service_buy_original_effect_conflict');
    $account=achbotProviderAccountRow($e);
    if (!$account || $account['operation_marker']!=='achbot:v1:'.$e['operation_id'] ||
        $account['entitlement_snapshot']!==json_encode(achbotProviderEntitlement([
            'data_limit'=>(int)$e['requested_bytes'],'expire'=>(int)$e['requested_expire'],
            'group_ids'=>$snapshot['group_ids'],'status'=>'active']),JSON_THROW_ON_ERROR))
        throw new RuntimeException('service_buy_account_evidence_missing');
    return $account;
}
/** One immutable ordinal and physical identity, using the existing Native
 * CREATE evidence table. Capacity and money belong to service_operations. */
function achbotPasarGuardBuyPrepareLocked(mysqli $db,array $op,array $quote): ?array {
    require_once __DIR__.'/AccountNaming.php';
    $id=(int)$op['id'];$ref=(string)$id;$q=$quote['request'];$count=$q['quantity'];
    if ($op['kind']!=='BUY' || (int)$op['reserved_count']!==$count)
        throw new RuntimeException('service_buy_capacity_unverified');
    [$snapshot]=achbotPasarGuardBuySnapshot($db,$quote);
    $s=achbotProviderStatement($db,"SELECT * FROM service_effects
        WHERE source_kind='service_operation' AND source_ref=? ORDER BY ordinal FOR UPDATE",'s',[$ref]);
    $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
    if (count($rows)>$count) throw new RuntimeException('service_buy_ordinal_conflict');
    $work=null;
    foreach ($rows as $i=>$e) {
        $ordinal=$i+1;
        $account=achbotPasarGuardBuyEffectAccount($op,$quote,$snapshot,$e,$ordinal);
        if (in_array($e['state'],['verified','local_recorded'],true)) {
            if ($work!==null || $account['remote_user_id']===null ||
                !in_array($account['state'],['verified','linked'],true))
                throw new RuntimeException('service_buy_ordinal_result_conflict');
            continue;
        }
        if ($work!==null || $e['state']!=='dispatching' || $e['order_id']!==null ||
            $account['remote_user_id']!==null || !in_array($account['state'],['reserved','dispatching'],true))
            throw new RuntimeException('service_buy_ordinal_state_conflict');
        $work=$e;
    }
    if ($work===null && count($rows)===$count) return null;
    if ($work===null) {
        require_once __DIR__.'/../Finance/ServiceDelivery.php';
        if (!achbotServiceDeliverySchemaReady($db))
            throw new RuntimeException('service_delivery_schema_unavailable');
        $ordinal=count($rows)+1;
        $remark=achbotStyledAccountRemark($q['remark'],'pasarguard','service:'.$id,
            $ordinal,$count,$q['naming_mode'],$q['explicit_name']);
        // Exact real orders_list expiry INT contract; not a new provider cap.
        $expiry=time()+$q['days']*86400;
        if ($expiry<=0 || $expiry>2147483647) throw new RuntimeException('service_buy_expiry_unrepresentable');
        $bytes=$q['volume']*1073741824;
        $operation=bin2hex(random_bytes(16));
        $work=['ordinal'=>$ordinal,'operation_id'=>$operation,'remote_username'=>$remark,
            'requested_expire'=>$expiry,'requested_bytes'=>$bytes];
        $digest=achbotPasarGuardBuyDigest($op,$snapshot,$work);
        $json=json_encode($snapshot,JSON_THROW_ON_ERROR);
        $s=achbotProviderStatement($db,"INSERT INTO service_effects
            (source_kind,source_ref,actor_userid,ordinal,reserved_count,operation_id,effect_kind,
             target_server_id,target_key,endpoint_sha256,plan_id,remote_username,requested_expire,requested_bytes,
             request_sha256,state,provider_snapshot)
            VALUES ('service_operation',?,?,?,0,?,'create',?,?,?,?,?,?,?,?,'dispatching',?)",
            'siisissisiiss',[$ref,(int)$op['userid'],$ordinal,$operation,$q['server_id'],
                $quote['target']['physical'],$snapshot['endpoint_sha256'],$q['plan_id'],$remark,$expiry,$bytes,$digest,$json]);
        $effectId=(int)$s->insert_id;$s->close();
        if ($effectId<=0) throw new RuntimeException('service_buy_effect_insert');
        $account=achbotProviderReserveAccount($db,$effectId,$snapshot,$remark,'achbot:v1:'.$operation,
            ['data_limit'=>$bytes,'expire'=>$expiry,'group_ids'=>$snapshot['group_ids'],'status'=>'active']);
        if (($account['newly_reserved']??false)!==true) throw new RuntimeException('service_buy_effect_reservation');
        $work=achbotProviderRow($db,'SELECT * FROM service_effects WHERE id=? FOR UPDATE','i',[$effectId]);
    }
    $s=achbotProviderStatement($db,"UPDATE service_effects SET provider_state='dispatching',
        provider_evidence_updated_at=UTC_TIMESTAMP(),updated_at=updated_at
        WHERE id=? AND provider_state IN ('reserved','dispatching') AND provider_remote_user_id IS NULL",
        'i',[(int)$work['id']]);$s->close();
    return $work;
}

function achbotPasarGuardBuyReadback(array $panel,array $snapshot,array $e,array $user): array {
    $identity=['remote_user_id'=>$user['id'],'remote_created_at'=>$user['created_at'],
        'operation_marker'=>'achbot:v1:'.$e['operation_id']];
    if (!achbotPgIdentityMatches($user,$identity) || $user['username']!==$e['remote_username'])
        return achbotPgFailure('pg_create_name_identity_conflict');
    $r=achbotPgRead($panel,$identity);
    if (!$r['success']) return $r;
    $u=$r['user'];
    if ($u['username']!==$e['remote_username'] || $u['data_limit']!==(int)$e['requested_bytes'] ||
        $u['expire']!==(int)$e['requested_expire'] || $u['group_ids']!==$snapshot['group_ids'] ||
        $u['status']!=='active' || !achbotPgManaged($u))
        return achbotPgFailure('pg_create_entitlement_conflict');
    return $r;
}
/** Recovery observes the exact original name and marker first. Only an
 * authenticated not-found can permit the same original unique-name POST.
 * A 409 or ambiguous write is not a new name, and never another debit. */
function achbotPasarGuardBuyPerform(mysqli $db,array $op,array $quote,array $e): array {
    [$snapshot,$panel]=achbotPasarGuardBuySnapshot($db,$quote);
    $found=achbotPgUserResult(achbotPgRequest($panel,'GET','/api/user/by-username/'.rawurlencode($e['remote_username'])));
    if ($found['success']) return achbotPasarGuardBuyReadback($panel,$snapshot,$e,$found['user']);
    if ($found['reason']!=='pg_not_found' || $found['http']!==404) return $found;
    return achbotPgCreate($panel,['username'=>$e['remote_username'],'data_limit'=>(int)$e['requested_bytes'],
        'expire'=>(int)$e['requested_expire'],'group_ids'=>$snapshot['group_ids'],
        'operation_marker'=>'achbot:v1:'.$e['operation_id']]);
}
/** Commit only authoritative result from the exact still-owned step. */
function achbotPasarGuardBuyApplyLocked(mysqli $db,array $op,array $quote,array $e,array $result): bool {
    [$snapshot]=achbotPasarGuardBuySnapshot($db,$quote);
    $current=achbotProviderRow($db,'SELECT * FROM service_effects WHERE id=? FOR UPDATE','i',[(int)$e['id']]);
    if (!$current || $current['state']!=='dispatching' || $current['order_id']!==null ||
        !hash_equals($current['request_sha256'],achbotPasarGuardBuyDigest($op,$snapshot,$e)))
        throw new RuntimeException('service_buy_result_identity_conflict');
    $account=achbotPasarGuardBuyEffectAccount($op,$quote,$snapshot,$current,(int)$e['ordinal']);
    if ($account['state']!=='dispatching' || $account['remote_user_id']!==null)
        throw new RuntimeException('service_buy_result_identity_conflict');
    achbotProviderVerifyAccount($db,(int)$e['id'],$result['user']);
    $s=achbotProviderStatement($db,"UPDATE service_effects SET state='verified',verified_at=NOW()
        WHERE id=? AND state='dispatching' AND order_id IS NULL",'i',[(int)$e['id']]);
    $n=$s->affected_rows;$s->close();
    if ($n!==1) throw new RuntimeException('service_buy_result_write');
    return (int)$e['ordinal']===$quote['request']['quantity'];
}

/** Native success has already been recorded. This phase only reads the
 * original incarnation and materializes its local result. No CREATE path. */
function achbotPasarGuardBuyFinalizePrepareLocked(mysqli $db,array $op,array $quote): ?array {
    require_once __DIR__.'/../Finance/ServiceDelivery.php';
    if (!achbotServiceDeliverySchemaReady($db)) throw new RuntimeException('service_delivery_schema_unavailable');
    [$snapshot]=achbotPasarGuardBuySnapshot($db,$quote);
    $id=(int)$op['id'];$count=$quote['request']['quantity'];
    if ($op['stage']!=='applied' || (int)$op['reserved_count']!==$count)
        throw new RuntimeException('service_buy_finalize_stage');
    $s=achbotProviderStatement($db,"SELECT * FROM service_effects
        WHERE source_kind='service_operation' AND source_ref=? ORDER BY ordinal FOR UPDATE",'s',[(string)$id]);
    $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
    if (count($rows)!==$count) throw new RuntimeException('service_buy_finalize_count');
    $work=null;
    foreach ($rows as $i=>$e) {
        $a=achbotPasarGuardBuyEffectAccount($op,$quote,$snapshot,$e,$i+1);
        if ($a['remote_user_id']===null || $a['remote_created_at']===null)
            throw new RuntimeException('service_buy_finalize_identity');
        if ($e['state']==='local_recorded' && $a['state']==='linked' && (int)$e['order_id']>0) {
            if ($work!==null) throw new RuntimeException('service_buy_finalize_ordinal_gap');
            $d=achbotProviderRow($db,"SELECT id,effect_id,order_id,user_id FROM payment_delivery_intents
                WHERE service_operation_id=? AND ordinal=? FOR UPDATE",'ii',[$id,$i+1]);
            if (!$d || (int)$d['effect_id']!==(int)$e['id'] || (int)$d['order_id']!==(int)$e['order_id'] ||
                (int)$d['user_id']!==(int)$op['userid'])
                throw new RuntimeException('service_buy_finalize_intent_missing');
        } elseif ($e['state']==='verified' && $a['state']==='verified' && $e['order_id']===null) {
            $work??=$e;
        } else throw new RuntimeException('service_buy_finalize_effect_state');
    }
    return $work;
}
function achbotPasarGuardBuyFinalizePerform(mysqli $db,array $op,array $quote,array $e,array $presentation): array {
    require_once __DIR__.'/../Subscription/Delivery.php';
    require_once __DIR__.'/../Subscription/ServerDisplaySettings.php';
    if (!is_array($presentation['bot_state']??null) ||
        !is_string($presentation['bot_username']??null) ||
        !is_string($presentation['bot_url']??null))
        throw new RuntimeException('service_buy_presentation_context_missing');
    [$snapshot,$panel]=achbotPasarGuardBuySnapshot($db,$quote);
    $a=achbotPasarGuardBuyEffectAccount($op,$quote,$snapshot,$e,(int)$e['ordinal']);
    $identity=achbotProviderAccountIdentity($db,$a);
    $links=achbotPgLinks($panel,$identity);
    if (!$links['success']) return $links;
    $u=$links['user'];
    if ($u['username']!==$e['remote_username'] ||
        $u['data_limit']!==(int)$e['requested_bytes'] ||
        $u['expire']!==(int)$e['requested_expire'] || $u['group_ids']!==$snapshot['group_ids'])
        return achbotPgFailure('pg_create_entitlement_conflict');
    $q=$quote['request'];$sid=$q['server_id'];
    $cfg=achbotProviderRow($db,'SELECT type,sub_links FROM server_config WHERE id=?','i',[$sid]);
    if (!$cfg || $cfg['type']!=='pasarguard') throw new RuntimeException('service_buy_target_changed');
    // An inactive catalogue is not a cancelled funded purchase. Apply its
    // original provider's actual display policy without the sales-active gate.
    $options=achbotServerDisplayDocument(achbotServerDisplayRow($db,$sid),$sid,'pasarguard');
    $native=['links'=>$links['links'],'subscription_url'=>$links['subscription_url'],
        'backup_urls'=>\AchBot\Subscription\Presentation\subscriptionAliases($links['subscription_url'],$cfg['sub_links']??'')];
    $packet=achbotPurchaseRenderPacket('pasarguard',$native,$presentation['bot_state'],$options,
        $presentation['bot_username'],$e['remote_username'],$q['volume'],$q['days']);
    return ['success'=>true,'user'=>$u,'link'=>json_encode($links['links'],JSON_THROW_ON_ERROR),
        'packet'=>['kind'=>'qr','uid'=>(int)$op['userid'],'bot_url'=>$presentation['bot_url']]+$packet];
}
/** Stable local reference; never an invented Native proxy credential. */
function achbotPasarGuardBuyLocalUuid(string $operation): string {
    if (preg_match('/^[a-f0-9]{32}$/D',$operation)!==1) throw new RuntimeException('service_buy_local_uuid');
    $operation[12]='4';$operation[16]=dechex((hexdec($operation[16])&3)|8);
    return substr($operation,0,8).'-'.substr($operation,8,4).'-'.substr($operation,12,4).'-'.
        substr($operation,16,4).'-'.substr($operation,20,12);
}
function achbotPasarGuardBuyFinalizeApplyLocked(mysqli $db,array $op,array $quote,array $e,array $result): bool {
    require_once __DIR__.'/../Purchase/OrderWrites.php';
    require_once __DIR__.'/../Finance/ServiceDelivery.php';
    [$snapshot]=achbotPasarGuardBuySnapshot($db,$quote);
    $current=achbotProviderRow($db,'SELECT * FROM service_effects WHERE id=? FOR UPDATE','i',[(int)$e['id']]);
    if (!$current || $current['state']!=='verified' || $current['order_id']!==null ||
        !hash_equals($current['request_sha256'],achbotPasarGuardBuyDigest($op,$snapshot,$e)))
        throw new RuntimeException('service_buy_finalize_result_changed');
    $ordinal=(int)$e['ordinal'];$a=achbotPasarGuardBuyEffectAccount($op,$quote,$snapshot,$current,$ordinal);
    $u=$result['user'];$q=$quote['request'];
    if ($a['state']!=='verified' || !achbotPgIdentityMatches($u,achbotProviderAccountIdentity($db,$a)) ||
        $u['username']!==$current['remote_username'] ||
        $u['data_limit']!==(int)$current['requested_bytes'] ||
        $u['expire']!==(int)$current['requested_expire'] || $u['group_ids']!==$snapshot['group_ids'])
        throw new RuntimeException('service_buy_finalize_identity_changed');
    $agent=$q['policy']['agent_bought']??false;
    if (!in_array($agent,[0,1,false,true],true)) throw new RuntimeException('service_buy_agent_policy_invalid');
    $orderId=insertOrder($db,(int)$op['userid'],'',$q['plan_id'],$q['server_id'],0,
        $current['remote_username'],achbotPasarGuardBuyLocalUuid($current['operation_id']),
        (int)$current['requested_expire'],$result['link'],
        achbotAllocateOrderAmount($q['price'],$q['quantity'],$ordinal),
        time(),(int)$agent,true);
    if (!achbotPgLinkEffect($db,(int)$current['id'],$orderId))
        throw new RuntimeException('service_buy_order_link_failed');
    $current=achbotProviderRow($db,'SELECT * FROM service_effects WHERE id=? FOR UPDATE','i',[(int)$current['id']]);
    $order=achbotProviderRow($db,'SELECT * FROM orders_list WHERE id=? FOR UPDATE','i',[$orderId]);
    if (!$current || !$order) throw new RuntimeException('service_buy_order_missing');
    achbotServiceBuyDeliveryInsertLocked($db,$op,$quote,$current,$order,$result['packet']);
    return $ordinal===$q['quantity'];
}
