<?php
/** AChBot native identity and operation evidence in shared tables. Migration 012 is operator-only. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }
require_once __DIR__.'/PasarGuard.php';

/** SQL errors are private reason codes, never database text or parameter values. */
function achbotProviderStatement(mysqli $db,string $sql,string $types='',array $values=[]): mysqli_stmt {
    try {
        $s=$db->prepare($sql);
        if (!$s || ($types!=='' && !$s->bind_param($types,...$values)) || !$s->execute())
            throw new RuntimeException('provider_storage_unavailable');
        return $s;
    } catch (Throwable $e) { throw new RuntimeException('provider_storage_unavailable'); }
}
function achbotProviderRow(mysqli $db,string $sql,string $types='',array $values=[]): ?array {
    $s=achbotProviderStatement($db,$sql,$types,$values);
    try { return $s->get_result()->fetch_assoc(); }
    catch (Throwable $e) { throw new RuntimeException('provider_storage_unavailable'); }
    finally { $s->close(); }
}
/** Native connection/operation evidence lives in the existing shared tables (012). */
function achbotProviderRequireSchema(mysqli $db): void {
    $shape=[
        'server_config'=>['panel_url'=>['varchar(1000)',false],'password'=>['text',false],
            'provider_connection_id'=>['int',true],'provider_instance_key'=>['char(32)',true],
            'provider_version'=>['varchar(32)',true],'provider_enabled'=>['tinyint unsigned',false]],
        'orders_list'=>['provider_effect_id'=>['bigint unsigned',true],
            'provider_remote_user_id'=>['bigint unsigned',true],'provider_remote_created_at'=>['varchar(40)',true]],
        'server_info'=>['provider_group_ids'=>['text',true]],
        'server_plans'=>['provider_group_ids'=>['text',true]],
        'pays'=>['provider_snapshot'=>['longtext',true]],
        'service_effects'=>['provider_snapshot'=>['longtext',true],
            'provider_reference_id'=>['bigint unsigned',true],'provider_connection_id'=>['int',true],
            'provider_remote_user_id'=>['bigint unsigned',true],'provider_remote_created_at'=>['varchar(40)',true],
            'provider_operation_marker'=>['varchar(64)',true],'provider_entitlement_snapshot'=>['longtext',true],
            'provider_mutation_key'=>['varchar(100)',true],'provider_mutation_snapshot'=>['longtext',true],
            'provider_state'=>['varchar(24)',true],'provider_verified_at'=>['datetime',true],
            'provider_evidence_created_at'=>['datetime',true],'provider_evidence_updated_at'=>['datetime',true]]
    ];
    $s=achbotProviderStatement($db,"SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE
        FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
        AND TABLE_NAME IN ('server_config','orders_list','server_info','server_plans','pays','service_effects')");
    $found=[];
    try {$r=$s->get_result();while ($v=$r->fetch_assoc()) $found[$v['TABLE_NAME']][$v['COLUMN_NAME']]=$v;}
    finally {$s->close();}
    foreach ($shape as $table=>$columns) foreach ($columns as $name=>[$type,$nullable]) {
        $v=$found[$table][$name]??null;
        $actual=preg_replace('/\b(tinyint|int|bigint)\(\d+\)/','$1',strtolower((string)($v['COLUMN_TYPE']??'')));
        if (!$v || $actual!==$type || ($v['IS_NULLABLE']==='YES')!==$nullable)
            throw new RuntimeException('provider_schema_missing_or_invalid');
    }
    $s=achbotProviderStatement($db,"SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('service_effects','users','pays','orders_list',
        'server_info','server_plans','server_config','setting')");
    $engines=[];
    try {$r=$s->get_result();while ($v=$r->fetch_assoc()) $engines[$v['TABLE_NAME']]=$v['ENGINE'];}
    finally {$s->close();}
    foreach (['service_effects','users','pays','orders_list','server_info','server_plans','server_config','setting'] as $table)
        if (strcasecmp($engines[$table]??'','InnoDB')!==0) throw new RuntimeException('provider_schema_missing_or_invalid');
    $s=achbotProviderStatement($db,'SHOW INDEX FROM service_effects');$indexes=[];
    try {$r=$s->get_result();while ($v=$r->fetch_assoc()) if ((int)$v['Non_unique']===0 && $v['Sub_part']===null)
        $indexes[$v['Key_name']][(int)$v['Seq_in_index']]=$v['Column_name'];}
    finally {$s->close();}
    foreach (['PRIMARY'=>['id'],'uq_effect_provider_reference'=>['provider_reference_id'],
        'uq_effect_provider_remote'=>['provider_connection_id','provider_remote_user_id']] as $key=>$columns) {
        $actual=$indexes[$key]??[];ksort($actual);
        if (array_values($actual)!==$columns) throw new RuntimeException('provider_unique_index_invalid');
    }
}
function achbotProviderPositiveInt($value): int {
    if (is_int($value) && $value>0) return $value;
    if (is_string($value) && preg_match('/^[1-9][0-9]*$/D',$value) &&
        strlen($value)<=strlen((string)PHP_INT_MAX) &&
        (strlen($value)<strlen((string)PHP_INT_MAX) || strcmp($value,(string)PHP_INT_MAX)<=0))
        return (int)$value;
    throw new RuntimeException('provider_identity_invalid');
}
function achbotProviderGroups($value): array {
    try {
        if (is_string($value)) $value=json_decode($value,true,64,JSON_THROW_ON_ERROR);
        if (!is_array($value)) throw new RuntimeException();
        $groups=achbotPgGroupsCanonical($value);
        if (!$groups) throw new RuntimeException();
        return $groups;
    } catch (Throwable $e) { throw new RuntimeException('provider_groups_invalid'); }
}
function achbotProviderCanonicalTarget(array $snapshot): array {
    try {
        if (($snapshot['provider']??'')!=='pasarguard' ||
            !is_int($snapshot['panel_id']??null) || $snapshot['panel_id']<=0 ||
            !is_string($snapshot['instance_key']??null) ||
            !preg_match('/^[a-f0-9]{32}$/D',$snapshot['instance_key']) ||
            !is_string($snapshot['endpoint_sha256']??null) ||
            !preg_match('/^[a-f0-9]{64}$/D',$snapshot['endpoint_sha256']))
            throw new RuntimeException();
        return ['provider'=>'pasarguard','panel_id'=>$snapshot['panel_id'],
            'instance_key'=>$snapshot['instance_key'],'endpoint_sha256'=>$snapshot['endpoint_sha256']];
    } catch (Throwable $e) { throw new RuntimeException('provider_snapshot_invalid'); }
}
/** Resolve one shared connection from the actual server connection rows. */
function achbotProviderCanonicalSnapshot(array $snapshot): array {
    return achbotProviderCanonicalTarget($snapshot)+['group_ids'=>achbotProviderGroups($snapshot['group_ids']??null)];
}
function achbotProviderPanel(mysqli $db,int $panelId,bool $lock=false): array {
    achbotProviderRequireSchema($db);
    $s=achbotProviderStatement($db,"SELECT c.*,i.title FROM server_config c JOIN server_info i ON i.id=c.id
        WHERE c.type='pasarguard' AND c.provider_connection_id=? ORDER BY c.id".($lock?' FOR UPDATE':''),'i',[$panelId]);
    $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();$panel=null;
    foreach ($rows as $r) {
        $p=['id'=>achbotProviderPositiveInt($r['provider_connection_id']),'provider'=>'pasarguard',
            'instance_key'=>$r['provider_instance_key'],'name'=>$r['title'],
            'api_url'=>$r['panel_url'],'api_key'=>$r['password'],'version'=>$r['provider_version'],
            'enabled'=>(int)$r['provider_enabled']];
        if ($p['enabled']!==1 || !achbotPgPanelValid($p)) throw new RuntimeException('provider_panel_invalid');
        if ($panel!==null) foreach (['instance_key','api_url','api_key','version','enabled'] as $field)
            if ($panel[$field]!==$p[$field]) throw new RuntimeException('provider_connection_ambiguous');
        $panel=$panel??$p;
    }
    if ($panel===null) throw new RuntimeException('provider_panel_unavailable');
    return $panel;
}
function achbotProviderConnections(mysqli $db): array {
    achbotProviderRequireSchema($db);
    $s=achbotProviderStatement($db,"SELECT c.provider_connection_id AS id,MIN(i.title) AS name
        FROM server_config c JOIN server_info i ON i.id=c.id
        WHERE c.type='pasarguard' AND c.provider_enabled=1 GROUP BY c.provider_connection_id ORDER BY id LIMIT 30");
    $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();return $rows;
}
function achbotProviderSnapshot(mysqli $db,int $serverId,?int $planId=null): array {
    achbotProviderRequireSchema($db);
    $r=achbotProviderRow($db,'SELECT c.provider_connection_id,i.provider_group_ids,c.type,c.panel_url
        FROM server_info i JOIN server_config c ON c.id=i.id WHERE i.id=?','i',[$serverId]);
    if (!$r || $r['type']!=='pasarguard' || $r['provider_connection_id']===null)
        throw new RuntimeException('provider_server_invalid');
    $panel=achbotProviderPanel($db,achbotProviderPositiveInt($r['provider_connection_id']));
    if (rtrim((string)$r['panel_url'],'/')!==rtrim($panel['api_url'],'/'))
        throw new RuntimeException('provider_endpoint_changed');
    $groups=$r['provider_group_ids'];
    if ($planId!==null && $planId!==0) {
        $p=achbotProviderRow($db,'SELECT server_id,inbound_id,provider_group_ids FROM server_plans WHERE id=?','i',[$planId]);
        if (!$p || (int)$p['server_id']!==$serverId || (int)$p['inbound_id']!==0)
            throw new RuntimeException('provider_plan_invalid');
        if ($p['provider_group_ids']!==null) $groups=$p['provider_group_ids'];
    }
    return ['provider'=>'pasarguard','panel_id'=>$panel['id'],'instance_key'=>$panel['instance_key'],
        'endpoint_sha256'=>hash('sha256',rtrim($panel['api_url'],'/')),
        'group_ids'=>achbotProviderGroups($groups)];
}
function achbotProviderPanelForSnapshot(mysqli $db,array $snapshot): array {
    $snapshot=achbotProviderCanonicalSnapshot($snapshot);
    $p=achbotProviderPanel($db,$snapshot['panel_id']);
    if (!hash_equals($snapshot['instance_key'],$p['instance_key']) ||
        !hash_equals($snapshot['endpoint_sha256'],hash('sha256',rtrim($p['api_url'],'/'))))
        throw new RuntimeException('provider_snapshot_target_changed');
    return $p;
}
/** Materialize native operation evidence; this is an originating service-effect row. */
function achbotProviderAccountRow(?array $r): ?array {
    if ($r===null || $r['provider_reference_id']===null) return null;
    try {$snapshot=achbotProviderCanonicalTarget(json_decode((string)$r['provider_snapshot'],true,64,JSON_THROW_ON_ERROR));}
    catch (Throwable $e) {throw new RuntimeException('provider_effect_snapshot_missing');}
    $a=['id'=>$r['provider_reference_id'],'effect_id'=>$r['id'],'order_id'=>$r['order_id'],
        'provider'=>'pasarguard','panel_id'=>$r['provider_connection_id'],'instance_key'=>$snapshot['instance_key'],
        'endpoint_sha256'=>$r['endpoint_sha256'],'remote_user_id'=>$r['provider_remote_user_id'],
        'remote_created_at'=>$r['provider_remote_created_at'],'remote_username'=>$r['remote_username'],
        'operation_marker'=>$r['provider_operation_marker'],'entitlement_snapshot'=>$r['provider_entitlement_snapshot'],
        'mutation_key'=>$r['provider_mutation_key'],'mutation_snapshot'=>$r['provider_mutation_snapshot'],
        'state'=>$r['provider_state'],'verified_at'=>$r['provider_verified_at'],
        'created_at'=>$r['provider_evidence_created_at'],'updated_at'=>$r['provider_evidence_updated_at']];
    foreach (['id','effect_id','panel_id','order_id','remote_user_id'] as $key)
        if ($a[$key]!==null) $a[$key]=achbotProviderPositiveInt($a[$key]);
    if ($a['panel_id']!==$snapshot['panel_id'] || $a['endpoint_sha256']!==$snapshot['endpoint_sha256'])
        throw new RuntimeException('provider_effect_identity_invalid');
    return $a;
}
function achbotProviderAccountByOrder(mysqli $db,int $orderId,bool $lock=false): ?array {
    achbotProviderRequireSchema($db);
    $order=achbotProviderRow($db,'SELECT id,userid,remark,provider_effect_id,provider_remote_user_id,provider_remote_created_at
        FROM orders_list WHERE id=?'.($lock?' FOR UPDATE':''),'i',[$orderId]);
    if (!$order || $order['provider_effect_id']===null) return null;
    $anchor=achbotProviderAccountRow(achbotProviderRow($db,'SELECT * FROM service_effects WHERE id=?'.($lock?' LOCK IN SHARE MODE':''),
        'i',[(int)$order['provider_effect_id']]));
    if (!$anchor || $anchor['remote_username']!==$order['remark'] ||
        $anchor['remote_user_id']!==(int)$order['provider_remote_user_id'] ||
        $anchor['remote_created_at']!==$order['provider_remote_created_at']) throw new RuntimeException('provider_order_identity_invalid');
    require_once __DIR__.'/../Purchase/OrderWrites.php';
    $origin=achbotOrderImportOrigin($db,$order,$lock);
    if ($origin!==null) {
        if ($origin['provider']!=='pasarguard' || ($origin['provider_account_id']??null)!==$anchor['id'])
            throw new RuntimeException('provider_order_origin_invalid');
        foreach (['remote_user_id','remote_created_at','remote_username'] as $field)
            if (($origin['native_identity'][$field]??null)!==$anchor[$field]) throw new RuntimeException('provider_order_origin_identity_invalid');
    } elseif ($anchor['order_id']!==$orderId) throw new RuntimeException('provider_order_origin_invalid');
    return $anchor;
}
function achbotProviderAccountByEffect(mysqli $db,int $effectId): ?array {
    achbotProviderRequireSchema($db);
    return achbotProviderAccountRow(achbotProviderRow($db,'SELECT * FROM service_effects WHERE id=?','i',[$effectId]));
}
function achbotProviderBindOrder(mysqli $db,array $account,int $orderId): void {
    $s=achbotProviderStatement($db,'UPDATE orders_list SET provider_effect_id=?,provider_remote_user_id=?,provider_remote_created_at=?
        WHERE id=? AND (provider_effect_id IS NULL OR provider_effect_id=?)','iisii',
        [$account['effect_id'],$account['remote_user_id'],$account['remote_created_at'],$orderId,$account['effect_id']]);$s->close();
    $o=achbotProviderRow($db,'SELECT provider_effect_id,provider_remote_user_id,provider_remote_created_at FROM orders_list WHERE id=?','i',[$orderId]);
    if (!$o || (int)$o['provider_effect_id']!==$account['effect_id'] || (int)$o['provider_remote_user_id']!==$account['remote_user_id'] ||
        $o['provider_remote_created_at']!==$account['remote_created_at']) throw new RuntimeException('provider_order_link_unconfirmed');
}
function achbotProviderEntitlement(array $value): array {
    if (array_diff(array_keys($value),['data_limit','expire','group_ids','status']) ||
        !is_int($value['data_limit']??null) || $value['data_limit']<0 ||
        !array_key_exists('expire',$value) ||
        !($value['expire']===null || (is_int($value['expire']) && $value['expire']>0)) ||
        ($value['status']??'')!=='active') throw new RuntimeException('provider_entitlement_invalid');
    return ['data_limit'=>$value['data_limit'],'expire'=>$value['expire'],
        'group_ids'=>achbotProviderGroups($value['group_ids']??null),'status'=>'active'];
}
/**
 * Uses the caller transaction. COMMIT must succeed before HTTP.
 * newly_reserved=false is observation only: it NEVER authorizes a second POST.
 * service_effects remains the only financial/replay authority.
 */
function achbotProviderReserveAccount(mysqli $db,int $effectId,array $snapshot,string $username,string $marker,array $entitlement): array {
    achbotProviderRequireSchema($db);
    $tx=achbotProviderRow($db,'SELECT @@in_transaction AS tx');
    if ((int)($tx['tx']??0)!==1) throw new RuntimeException('provider_reservation_requires_transaction');
    $snapshot=achbotProviderCanonicalSnapshot($snapshot);
    achbotProviderPanelForSnapshot($db,$snapshot);
    $entitlement=achbotProviderEntitlement($entitlement);
    if ($effectId<=0 || !preg_match('/^[a-zA-Z0-9_@.-]{3,100}$/D',$username) ||
        preg_match('/[-_@.]{2}/',$username) || !preg_match('/^achbot:v1:[a-f0-9]{32}$/D',$marker) ||
        $entitlement['group_ids']!==$snapshot['group_ids'])
        throw new RuntimeException('provider_reservation_invalid');
    $effect=achbotProviderRow($db,'SELECT operation_id,remote_username,endpoint_sha256,effect_kind,provider_snapshot,requested_bytes,requested_expire
        FROM service_effects WHERE id=? FOR UPDATE','i',[$effectId]);
    try {
        $pinned=achbotProviderCanonicalSnapshot(json_decode((string)($effect['provider_snapshot']??''),true,64,JSON_THROW_ON_ERROR));
    } catch (Throwable $e) { throw new RuntimeException('provider_effect_snapshot_missing'); }
    if (!$effect || $effect['effect_kind']!=='create' || $effect['remote_username']!==$username ||
        'achbot:v1:'.$effect['operation_id']!==$marker ||
        $effect['endpoint_sha256']!==$snapshot['endpoint_sha256'] || $pinned!==$snapshot ||
        (string)$effect['requested_bytes']!==(string)$entitlement['data_limit'] ||
        (string)$effect['requested_expire']!==(string)($entitlement['expire']??0))
        throw new RuntimeException('provider_effect_identity_mismatch');
    $json=json_encode($entitlement,JSON_THROW_ON_ERROR);
    // Locking current read is required after waiting for a competing effect
    // lock: an earlier repeatable-read snapshot may predate its committed row.
    $old=achbotProviderAccountRow(achbotProviderRow($db,
        'SELECT * FROM service_effects WHERE id=? FOR UPDATE','i',[$effectId]));
    if ($old!==null) {
        foreach (['provider','panel_id','instance_key','endpoint_sha256'] as $k)
            if ($old[$k]!==$snapshot[$k]) throw new RuntimeException('provider_reservation_conflict');
        if ($old['remote_username']!==$username || $old['operation_marker']!==$marker ||
            $old['entitlement_snapshot']!==$json) throw new RuntimeException('provider_reservation_conflict');
        return $old+['newly_reserved'=>false];
    }
    $s=achbotProviderStatement($db,"UPDATE service_effects SET provider_reference_id=id,
        provider_connection_id=?,provider_operation_marker=?,provider_entitlement_snapshot=?,provider_state='reserved',
        provider_evidence_created_at=UTC_TIMESTAMP(),provider_evidence_updated_at=UTC_TIMESTAMP(),updated_at=updated_at
        WHERE id=? AND provider_reference_id IS NULL",'issi',[$snapshot['panel_id'],$marker,$json,$effectId]);
    if ($s->affected_rows!==1) {$s->close();throw new RuntimeException('provider_reservation_unconfirmed');}$s->close();
    $r=achbotProviderAccountByEffect($db,$effectId);
    if (!$r) throw new RuntimeException('provider_reservation_unconfirmed');
    return $r+['newly_reserved'=>true];
}
function achbotProviderVerifyAccount(mysqli $db,int $effectId,array $user): void {
    achbotProviderRequireSchema($db);
    $r=achbotProviderAccountByEffect($db,$effectId);
    try {
        // Accept the adapter normalized shape, while revalidating the persisted fence.
        $created=achbotPgDate((string)($user['created_at']??''));
        $ent=achbotProviderEntitlement(json_decode((string)($r['entitlement_snapshot']??''),true,64,JSON_THROW_ON_ERROR));
        if (!$r || !is_int($user['id']??null) || $user['id']<=0 ||
            ($user['username']??null)!==$r['remote_username'] ||
            ($user['note']??null)!==$r['operation_marker'] || !achbotPgManaged($user))
            throw new RuntimeException();
        foreach ($ent as $key=>$v) if (($user[$key]??null)!==$v) throw new RuntimeException();
    } catch (Throwable $e) { throw new RuntimeException('provider_account_verification_invalid'); }
    // Exact repeated confirmation is harmless; differing incarnation is never adopted.
    if ($r['remote_user_id']!==null) {
        if ($r['remote_user_id']!==$user['id'] || $r['remote_created_at']!==$created ||
            !in_array($r['state'],['verified','linked'],true))
            throw new RuntimeException('provider_account_identity_conflict');
        return;
    }
    if (!in_array($r['state'],['reserved','dispatching'],true)) throw new RuntimeException('provider_account_state_invalid');
    $s=achbotProviderStatement($db,"UPDATE service_effects SET provider_remote_user_id=?,provider_remote_created_at=?,
        provider_state='verified',provider_verified_at=UTC_TIMESTAMP(),provider_evidence_updated_at=UTC_TIMESTAMP(),updated_at=updated_at WHERE id=? AND provider_state IN ('reserved','dispatching')
        AND provider_remote_user_id IS NULL AND provider_remote_created_at IS NULL",'isi',[$user['id'],$created,$effectId]);
    $changed=$s->affected_rows;$s->close();
    if ($changed!==1) throw new RuntimeException('provider_account_verification_unconfirmed');
}
function achbotProviderLinkAccount(mysqli $db,int $effectId,int $orderId): void {
    achbotProviderRequireSchema($db);
    if ($orderId<=0) throw new RuntimeException('provider_order_invalid');
    $r=achbotProviderAccountByEffect($db,$effectId);
    if (!$r || $r['remote_user_id']===null || $r['remote_created_at']===null ||
        !in_array($r['state'],['verified','linked'],true))
        throw new RuntimeException('provider_account_not_verified');
    $o=achbotProviderRow($db,'SELECT o.remark,c.provider_connection_id,c.type
        FROM orders_list o JOIN server_info i ON i.id=o.server_id
        JOIN server_config c ON c.id=i.id WHERE o.id=?','i',[$orderId]);
    if (!$o || $o['remark']!==$r['remote_username'] || $o['type']!=='pasarguard' ||
        (int)$o['provider_connection_id']!==$r['panel_id'])
        throw new RuntimeException('provider_order_identity_mismatch');
    if ($r['order_id']!==null && $r['order_id']!==$orderId) throw new RuntimeException('provider_order_link_conflict');
    $s=achbotProviderStatement($db,"UPDATE service_effects SET order_id=?,provider_state='linked',provider_evidence_updated_at=UTC_TIMESTAMP(),updated_at=updated_at
        WHERE id=? AND (order_id IS NULL OR order_id=?) AND provider_state IN ('verified','linked')",'iii',[$orderId,$effectId,$orderId]);$s->close();
    $confirmed=achbotProviderAccountByEffect($db,$effectId);
    if (!$confirmed || $confirmed['order_id']!==$orderId || $confirmed['state']!=='linked') throw new RuntimeException('provider_order_link_unconfirmed');
    achbotProviderBindOrder($db,$confirmed,$orderId);
}

/** Immutable local adoption evidence is distinct from a remote CREATE marker. */
function achbotProviderAccountIdentity(mysqli $db,array $account,bool $lock=false): array {
    $identity=['remote_user_id'=>(int)$account['remote_user_id'],
        'remote_created_at'=>$account['remote_created_at'],'operation_marker'=>$account['operation_marker']];
    if (preg_match('/^achbot:v1:[a-f0-9]{32}$/D',(string)$account['operation_marker'])) return $identity;
    if (!preg_match('/^achbot:adopt:([a-f0-9]{32})$/D',(string)$account['operation_marker'],$m))
        throw new RuntimeException('provider_account_provenance_invalid');
    $e=achbotProviderRow($db,'SELECT * FROM service_effects WHERE id=?'.($lock?' LOCK IN SHARE MODE':''),'i',[(int)$account['effect_id']]);
    try {
        $snap=json_decode((string)($e['provider_snapshot']??''),true,64,JSON_THROW_ON_ERROR);
        $native=json_decode((string)$account['entitlement_snapshot'],true,64,JSON_THROW_ON_ERROR);
        if (!is_array($snap) || !is_array($native) || ($snap['identity_mode']??null)!=='adopted' ||
            ($snap['native_user']??null)!==$native || achbotPgNormalizeUser($native)!==$native ||
            ($native['id']??null)!==$identity['remote_user_id'] ||
            ($native['created_at']??null)!==$identity['remote_created_at'] ||
            ($native['username']??null)!==$account['remote_username'] ||
            ($snap['adoption']['version']??null)!==1 ||
            ($snap['adoption']['order_id']??null)!==$account['order_id'] ||
            !is_int($snap['adoption']['owner_userid']??null) || $snap['adoption']['owner_userid']<=0 ||
            !is_int($snap['adoption']['actor_userid']??null) || $snap['adoption']['actor_userid']<=0 ||
            !preg_match('/^[a-f0-9]{64}$/D',(string)($snap['adoption']['input_sha256']??'')) ||
            !preg_match('/^[a-f0-9]{64}$/D',(string)($snap['adoption']['token_sha256']??'')))
            throw new RuntimeException();
        foreach (['provider','panel_id','instance_key','endpoint_sha256'] as $key)
            if (($snap[$key]??null)!==$account[$key]) throw new RuntimeException();
        $digest=hash('sha256',json_encode($snap,JSON_THROW_ON_ERROR));
        if (!$e || $e['pay_row_id']!==null || $e['effect_kind']!=='adopt' ||
            $e['source_kind']!=='external_import' || $e['source_ref']!=='order:'.$account['order_id'] ||
            (int)$e['ordinal']!==1 || (int)$e['reserved_count']!==0 ||
            $e['operation_id']!==$m[1] || $e['state']!=='local_recorded' ||
            (int)$e['order_id']!==$account['order_id'] || (int)$e['plan_id']!==0 ||
            (int)$e['actor_userid']!==$snap['adoption']['actor_userid'] ||
            (int)$e['target_server_id']!==($snap['adoption']['server_id']??null) ||
            $e['target_key']!=='pg:'.$account['instance_key'] ||
            $e['endpoint_sha256']!==$account['endpoint_sha256'] ||
            $e['remote_username']!==$account['remote_username'] ||
            (string)$e['requested_expire']!==(string)($native['expire']??0) ||
            (string)$e['requested_bytes']!==(string)$native['data_limit'] ||
            !is_string($e['verified_at']) || !hash_equals($digest,(string)$e['request_sha256']))
            throw new RuntimeException();
    } catch (Throwable $error) {throw new RuntimeException('provider_account_provenance_invalid');}
    return $identity+['identity_mode'=>'adopted','remote_username'=>$account['remote_username'],
        'adoption_sha256'=>$digest];
}
/** Caller owns one transaction spanning the order, source credential and both evidence rows. */
function achbotProviderAdoptAccount(mysqli $db,int $serverId,array $native,array $proof,
    int $owner,int $orderId,int $actor,int $primary): void {
    achbotProviderRequireSchema($db);
    $tx=achbotProviderRow($db,'SELECT @@in_transaction AS tx');
    if ((int)($tx['tx']??0)!==1 || min($serverId,$owner,$orderId,$actor)<=0)
        throw new RuntimeException('provider_adoption_transaction_invalid');
    $native=achbotPgNormalizeUser($native);
    $panel=achbotPgServerPanel($db,$serverId);
    if (achbotPgImportTarget($panel)!==($proof['target']??null))
        throw new RuntimeException('provider_adoption_target_changed');
    $s=achbotProviderStatement($db,'SELECT userid,isAdmin,step FROM users WHERE userid=? LIMIT 2 FOR UPDATE','i',[$actor]);
    $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
    if (count($rows)!==1 || $rows[0]['step']==='banned' ||
        ($owner!==$actor && $actor!==$primary && (int)$rows[0]['isAdmin']!==1))
        throw new RuntimeException('provider_adoption_actor_invalid');
    $s=achbotProviderStatement($db,'SELECT userid FROM users WHERE userid=? LIMIT 2 FOR UPDATE','i',[$owner]);
    $n=$s->get_result()->num_rows;$s->close();
    if ($n!==1) throw new RuntimeException('provider_adoption_owner_invalid');
    $o=achbotProviderRow($db,'SELECT * FROM orders_list WHERE id=? FOR UPDATE','i',[$orderId]);
    if (!$o || (int)$o['userid']!==$owner || (int)$o['server_id']!==$serverId ||
        (int)$o['fileid']!==0 || (int)$o['inbound_id']!==0 || $o['remark']!==$native['username'] ||
        $o['token']!==$proof['token'] || (int)$o['status']!==1 || (int)$o['amount']!==0 ||
        ($o['autoRenew']??null)!=='off')
        throw new RuntimeException('provider_adoption_order_invalid');
    // The original NOT NULL order column uses zero for unlimited; the native snapshot retains literal NULL.
    $localExpiry=$native['expire']??0;
    if ((string)$o['expire_date']!==(string)$localExpiry) {
        $s=achbotProviderStatement($db,'UPDATE orders_list SET expire_date=? WHERE id=?','ii',[$localExpiry,$orderId]);
        if ($s->affected_rows!==1) {$s->close();throw new RuntimeException('provider_adoption_order_unconfirmed');}
        $s->close();
    }
    // Source credentials are owned by the initiating actor; another customer's preview is not authority.
    $credential=achbotProviderRow($db,'SELECT token FROM add_link WHERE added_by=? AND remark=? AND BINARY token=BINARY ?
        LIMIT 1 FOR UPDATE','iss',[$actor,$native['username'],$proof['token']]);
    if (!$credential) throw new RuntimeException('provider_adoption_credential_missing');
    $physical=achbotProviderAccountRow(achbotProviderRow($db,
        'SELECT * FROM service_effects WHERE provider_connection_id=? AND provider_remote_user_id=? LIMIT 1 LOCK IN SHARE MODE',
        'ii',[$panel['id'],$native['id']]));
    $physicalIdentity=null;
    if ($physical!==null) {
        $physicalIdentity=achbotProviderAccountIdentity($db,$physical,true);
        if ($physical['provider']!=='pasarguard' || $physical['state']!=='linked' ||
            $physical['instance_key']!==$panel['instance_key'] ||
            $physical['endpoint_sha256']!==$proof['target']['endpoint_sha256'] ||
            !achbotPgIdentityMatches($native,$physicalIdentity))
            throw new RuntimeException('provider_adoption_existing_identity_unverified');
    }
    $operation=bin2hex(random_bytes(16));$marker='achbot:adopt:'.$operation;
    $snapshot=['provider'=>'pasarguard','panel_id'=>$panel['id'],'instance_key'=>$panel['instance_key'],
        'endpoint_sha256'=>hash('sha256',rtrim($panel['api_url'],'/')),'group_ids'=>$native['group_ids'],
        'identity_mode'=>'adopted','native_user'=>$native,
        'adoption'=>['version'=>1,'server_id'=>$serverId,'order_id'=>$orderId,'owner_userid'=>$owner,
            'actor_userid'=>$actor,'input_sha256'=>$proof['input_sha256'],
            'token_sha256'=>hash('sha256',$proof['token'])]];
    if ($physical!==null) {
        $snapshot['identity_mode']='attachment';
        $snapshot['provider_account_id']=$physical['id'];
        $snapshot['physical_identity']=$physicalIdentity;
    }
    $json=json_encode($snapshot,JSON_THROW_ON_ERROR);$hash=hash('sha256',$json);
    $source='order:'.$orderId;
    $target=$physical===null?'pg:'.$panel['instance_key']:'attachment:'.$orderId;
    $expiry=$native['expire']??0;
    $s=achbotProviderStatement($db,"INSERT INTO service_effects
        (pay_row_id,source_kind,source_ref,actor_userid,ordinal,reserved_count,operation_id,effect_kind,
         target_server_id,target_group_id,target_key,endpoint_sha256,plan_id,remote_username,
         requested_expire,requested_bytes,request_sha256,state,order_id,verified_at,provider_snapshot)
        VALUES (NULL,'external_import',?,?,1,0,?,'adopt',?,NULL,?,?,0,?,?,?,?,'local_recorded',?,UTC_TIMESTAMP(),?)",
        'sisisssiisis',[$source,$actor,$operation,$serverId,$target,$snapshot['endpoint_sha256'],
            $native['username'],$expiry,$native['data_limit'],$hash,$orderId,$json]);
    $effect=(int)$s->insert_id;$s->close();
    if ($physical===null) {
        $entitlement=json_encode($native,JSON_THROW_ON_ERROR);
        $s=achbotProviderStatement($db,"UPDATE service_effects SET provider_reference_id=id,provider_connection_id=?,
            provider_remote_user_id=?,provider_remote_created_at=?,provider_operation_marker=?,provider_entitlement_snapshot=?,
            provider_state='linked',provider_verified_at=UTC_TIMESTAMP(),provider_evidence_created_at=UTC_TIMESTAMP(),
            provider_evidence_updated_at=UTC_TIMESTAMP(),updated_at=updated_at WHERE id=? AND provider_reference_id IS NULL",
            'iisssi',[$panel['id'],$native['id'],$native['created_at'],$marker,$entitlement,$effect]);
        if ($s->affected_rows!==1) {$s->close();throw new RuntimeException('provider_adoption_unconfirmed');}$s->close();
        $physical=achbotProviderAccountByEffect($db,$effect);
    }
    if ($physical!==null && $physicalIdentity===null) $physicalIdentity=achbotProviderAccountIdentity($db,$physical,true);
    if (!$physical || !achbotPgIdentityMatches($native,$physicalIdentity))
        throw new RuntimeException('provider_adoption_unconfirmed');
    require_once __DIR__.'/../Purchase/OrderWrites.php';
    achbotRecordImportedOrderOriginLocked($db,$orderId,$owner,$actor,'pasarguard',$serverId,$native['username'],$hash,
        ['remote_user_id'=>$native['id'],'remote_created_at'=>$native['created_at'],'remote_username'=>$native['username']],
        $physical['id']);
    achbotProviderBindOrder($db,$physical,$orderId);
    $resolved=achbotProviderAccountByOrder($db,$orderId,true);
    if (!$resolved || $resolved['id']!==$physical['id']) throw new RuntimeException('provider_adoption_reference_unconfirmed');
}
