<?php
/** AChBot provider identity storage. Migration 011 is never applied at runtime. */
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
/** Validate the persistence invariants, including indexes, before any provider dispatch. */
function achbotProviderRequireSchema(mysqli $db): void {
    $shape=[
        'provider_panels'=>[
            'id'=>['int',false],'provider'=>['varchar(24)',false],
            'instance_key'=>['char(32)',false],'name'=>['varchar(100)',false],
            'api_url'=>['varchar(1000)',false],'api_key'=>['text',false],
            'version'=>['varchar(32)',false],'enabled'=>['tinyint unsigned',false]
        ],
        'provider_accounts'=>[
            'id'=>['bigint unsigned',false],'effect_id'=>['bigint unsigned',false],
            'order_id'=>['int',true],'provider'=>['varchar(24)',false],
            'panel_id'=>['int',false],'instance_key'=>['char(32)',false],
            'endpoint_sha256'=>['char(64)',false],'remote_user_id'=>['bigint unsigned',true],
            'remote_created_at'=>['varchar(40)',true],'remote_username'=>['varchar(100)',false],
            'operation_marker'=>['varchar(64)',false],'entitlement_snapshot'=>['longtext',false],
            'mutation_key'=>['varchar(100)',true],'mutation_snapshot'=>['longtext',true],
            'state'=>['varchar(24)',false],'verified_at'=>['datetime',true],
            'created_at'=>['datetime',false],'updated_at'=>['datetime',false]
        ],
        'server_info'=>['provider_panel_id'=>['int',true],'provider_group_ids'=>['text',true]],
        'server_plans'=>['provider_group_ids'=>['text',true]],
        'pays'=>['provider_snapshot'=>['longtext',true]],
        'service_effects'=>['provider_snapshot'=>['longtext',true]]
    ];
    $s=achbotProviderStatement($db,"SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE
        FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
        AND TABLE_NAME IN ('provider_panels','provider_accounts','server_info','server_plans','pays','service_effects')");
    $found=[];
    try {
        $r=$s->get_result();
        while ($v=$r->fetch_assoc()) $found[$v['TABLE_NAME']][$v['COLUMN_NAME']]=$v;
    } finally { $s->close(); }
    foreach ($shape as $table=>$columns) foreach ($columns as $name=>[$type,$nullable]) {
        $v=$found[$table][$name]??null;
        // MariaDB display widths are not semantic integer widths.
        $actual=preg_replace('/\b(tinyint|int|bigint)\(\d+\)/','$1',strtolower((string)($v['COLUMN_TYPE']??'')));
        if (!$v || $actual!==$type || ($v['IS_NULLABLE']==='YES')!==$nullable)
            throw new RuntimeException('provider_schema_missing_or_invalid');
    }
    $s=achbotProviderStatement($db,"SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('provider_panels','provider_accounts','service_effects')");
    $engines=[];
    try { $r=$s->get_result(); while ($v=$r->fetch_assoc()) $engines[$v['TABLE_NAME']]=$v['ENGINE']; }
    finally { $s->close(); }
    foreach (['provider_panels','provider_accounts','service_effects'] as $table)
        if (strcasecmp($engines[$table]??'','InnoDB')!==0)
            throw new RuntimeException('provider_schema_missing_or_invalid');
    foreach ([
        'provider_panels'=>['PRIMARY'=>['id'],'uq_provider_instance'=>['instance_key']],
        'provider_accounts'=>['PRIMARY'=>['id'],'uq_provider_effect'=>['effect_id'],
            'uq_provider_order'=>['order_id'],'uq_provider_remote'=>['panel_id','remote_user_id'],
            'uq_provider_marker'=>['instance_key','operation_marker']]
    ] as $table=>$indexes) {
        $s=achbotProviderStatement($db,'SHOW INDEX FROM '.$table);$found=[];
        try {
            $r=$s->get_result();
            while ($v=$r->fetch_assoc()) if ((int)$v['Non_unique']===0 && $v['Sub_part']===null)
                $found[$v['Key_name']][(int)$v['Seq_in_index']]=$v['Column_name'];
        } finally { $s->close(); }
        foreach ($indexes as $name=>$columns) {
            $actual=$found[$name]??[];ksort($actual);
            if (array_values($actual)!==$columns) throw new RuntimeException('provider_unique_index_invalid');
        }
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
function achbotProviderCanonicalSnapshot(array $snapshot): array {
    try {
        if (($snapshot['provider']??'')!=='pasarguard' ||
            !is_int($snapshot['panel_id']??null) || $snapshot['panel_id']<=0 ||
            !is_string($snapshot['instance_key']??null) ||
            !preg_match('/^[a-f0-9]{32}$/D',$snapshot['instance_key']) ||
            !is_string($snapshot['endpoint_sha256']??null) ||
            !preg_match('/^[a-f0-9]{64}$/D',$snapshot['endpoint_sha256']))
            throw new RuntimeException();
        return ['provider'=>'pasarguard','panel_id'=>$snapshot['panel_id'],
            'instance_key'=>$snapshot['instance_key'],'endpoint_sha256'=>$snapshot['endpoint_sha256'],
            'group_ids'=>achbotProviderGroups($snapshot['group_ids']??null)];
    } catch (Throwable $e) { throw new RuntimeException('provider_snapshot_invalid'); }
}
function achbotProviderPanel(mysqli $db,int $panelId): array {
    achbotProviderRequireSchema($db);
    $p=achbotProviderRow($db,'SELECT * FROM provider_panels WHERE id=?','i',[$panelId]);
    if (!$p || $p['provider']!=='pasarguard' || (int)$p['enabled']!==1)
        throw new RuntimeException('provider_panel_unavailable');
    $p['id']=achbotProviderPositiveInt($p['id']);
    $p['enabled']=(int)$p['enabled'];
    if (!achbotPgPanelValid($p)) throw new RuntimeException('provider_panel_invalid');
    return $p;
}
function achbotProviderSnapshot(mysqli $db,int $serverId,?int $planId=null): array {
    achbotProviderRequireSchema($db);
    $r=achbotProviderRow($db,'SELECT i.provider_panel_id,i.provider_group_ids,c.type,c.panel_url
        FROM server_info i JOIN server_config c ON c.id=i.id WHERE i.id=?','i',[$serverId]);
    if (!$r || $r['type']!=='pasarguard' || $r['provider_panel_id']===null)
        throw new RuntimeException('provider_server_invalid');
    $panel=achbotProviderPanel($db,achbotProviderPositiveInt($r['provider_panel_id']));
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
function achbotProviderAccountRow(?array $r): ?array {
    if ($r===null) return null;
    foreach (['id','effect_id','panel_id','order_id','remote_user_id'] as $key)
        if ($r[$key]!==null) $r[$key]=achbotProviderPositiveInt($r[$key]);
    return $r;
}
function achbotProviderAccountByOrder(mysqli $db,int $orderId): ?array {
    achbotProviderRequireSchema($db);
    return achbotProviderAccountRow(achbotProviderRow($db,'SELECT * FROM provider_accounts WHERE order_id=?','i',[$orderId]));
}
function achbotProviderAccountByEffect(mysqli $db,int $effectId): ?array {
    achbotProviderRequireSchema($db);
    return achbotProviderAccountRow(achbotProviderRow($db,'SELECT * FROM provider_accounts WHERE effect_id=?','i',[$effectId]));
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
    $effect=achbotProviderRow($db,'SELECT operation_id,remote_username,endpoint_sha256,effect_kind,provider_snapshot
        FROM service_effects WHERE id=? FOR UPDATE','i',[$effectId]);
    try {
        $pinned=achbotProviderCanonicalSnapshot(json_decode((string)($effect['provider_snapshot']??''),true,64,JSON_THROW_ON_ERROR));
    } catch (Throwable $e) { throw new RuntimeException('provider_effect_snapshot_missing'); }
    if (!$effect || $effect['effect_kind']!=='create' || $effect['remote_username']!==$username ||
        'achbot:v1:'.$effect['operation_id']!==$marker ||
        $effect['endpoint_sha256']!==$snapshot['endpoint_sha256'] || $pinned!==$snapshot)
        throw new RuntimeException('provider_effect_identity_mismatch');
    $json=json_encode($entitlement,JSON_THROW_ON_ERROR);
    $old=achbotProviderAccountByEffect($db,$effectId);
    if ($old!==null) {
        foreach (['provider','panel_id','instance_key','endpoint_sha256'] as $k)
            if ($old[$k]!==$snapshot[$k]) throw new RuntimeException('provider_reservation_conflict');
        if ($old['remote_username']!==$username || $old['operation_marker']!==$marker ||
            $old['entitlement_snapshot']!==$json) throw new RuntimeException('provider_reservation_conflict');
        return $old+['newly_reserved'=>false];
    }
    $s=achbotProviderStatement($db,"INSERT INTO provider_accounts
        (effect_id,provider,panel_id,instance_key,endpoint_sha256,remote_username,operation_marker,entitlement_snapshot,state)
        VALUES (?,'pasarguard',?,?,?,?,?,?,'reserved')",'iisssss',
        [$effectId,$snapshot['panel_id'],$snapshot['instance_key'],$snapshot['endpoint_sha256'],$username,$marker,$json]);
    $s->close();
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
    $s=achbotProviderStatement($db,"UPDATE provider_accounts SET remote_user_id=?,remote_created_at=?,
        state='verified',verified_at=UTC_TIMESTAMP() WHERE effect_id=? AND state IN ('reserved','dispatching')
        AND remote_user_id IS NULL AND remote_created_at IS NULL",'isi',[$user['id'],$created,$effectId]);
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
    $o=achbotProviderRow($db,'SELECT o.remark,i.provider_panel_id,c.type
        FROM orders_list o JOIN server_info i ON i.id=o.server_id
        JOIN server_config c ON c.id=i.id WHERE o.id=?','i',[$orderId]);
    if (!$o || $o['remark']!==$r['remote_username'] || $o['type']!=='pasarguard' ||
        (int)$o['provider_panel_id']!==$r['panel_id'])
        throw new RuntimeException('provider_order_identity_mismatch');
    if ($r['order_id']!==null) {
        if ($r['order_id']!==$orderId) throw new RuntimeException('provider_order_link_conflict');
        return;
    }
    $s=achbotProviderStatement($db,"UPDATE provider_accounts SET order_id=?,state='linked'
        WHERE effect_id=? AND order_id IS NULL AND state='verified'",'ii',[$orderId,$effectId]);
    $changed=$s->affected_rows;$s->close();
    if ($changed!==1) throw new RuntimeException('provider_order_link_unconfirmed');
}
