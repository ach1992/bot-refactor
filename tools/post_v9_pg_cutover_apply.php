<?php
/**
 * Operator-only, transaction-scoped conversion of previously paid Marzban
 * references to existing PasarGuard native identities. NO provider HTTP, no
 * financial changes, no user creation, no runtime activation.
 *
 * --dry-run (default) uses a READ ONLY transaction. --apply requires an
 * independent Owner-approved production window, bot/Cron writers stopped,
 * confirmed source/target snapshots, and restorable backup.
 */
if (PHP_SAPI !== 'cli') {http_response_code(404);exit(1);}
umask(0077);

function pgcFail(string $code): never {throw new RuntimeException($code);}
function pgcExpect(bool $valid,string $code): void {if (!$valid) pgcFail($code);}
function pgcRead(string $path): array {
    pgcExpect($path!=='' && !is_link($path) && is_file($path) &&
        filesize($path)!==false && filesize($path)<=50000000 &&
        (fileperms($path)&0077)===0,'private_input_invalid');
    $raw=file_get_contents($path);
    pgcExpect(is_string($raw),'private_input_invalid');
    $value=json_decode($raw,true,64,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);
    pgcExpect(is_array($value) && ($value['version']??null)===1,'snapshot_version_invalid');
    return [$value,hash('sha256',$raw)];
}
function pgcPositive($v): bool {return is_int($v) && $v>0;}
function pgcGroups($v,bool $allowEmpty=false): bool {
    if (!is_array($v) || !array_is_list($v) || (!$allowEmpty && !$v)) return false;
    foreach ($v as $id) if (!pgcPositive($id)) return false;
    $sorted=$v;sort($sorted,SORT_NUMERIC);
    return $v===$sorted && count($v)===count(array_unique($v,SORT_NUMERIC));
}
function pgcDate($v): bool {
    if (!is_string($v) || !preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{6}Z$/D',$v)) return false;
    try {$d=new DateTimeImmutable($v);return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z')===$v;}
    catch (Throwable $e){return false;}
}
function pgcIndex(array $rows,string $key,string $code): array {
    $out=[];
    foreach ($rows as $r) {
        pgcExpect(is_array($r) && pgcPositive($r[$key]??null) && !isset($out[$r[$key]]),$code);
        $out[$r[$key]]=$r;
    }
    return $out;
}
function pgcStmt(mysqli $db,string $sql,array $params=[]): mysqli_stmt {
    $s=$db->prepare($sql);pgcExpect($s!==false,'sql_prepare_failed');
    pgcExpect($s->execute($params),'sql_execute_failed');
    return $s;
}
function pgcRows(mysqli $db,string $sql,array $params=[]): array {
    $s=pgcStmt($db,$sql,$params);$data=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();return $data;
}
function pgcUpdate(mysqli $db,string $sql,array $params=[],int $expected=1): void {
    $s=pgcStmt($db,$sql,$params);$n=$s->affected_rows;$s->close();
    pgcExpect($n===$expected,'cutover_write_count_invalid');
}

function pgcPlan(array $source,array $native,array $mapping,array $validated,string $tenant): array {
    pgcExpect(($source['tenant']??null)===$tenant && ($native['tenant']??null)===$tenant &&
        ($mapping['tenant']??null)===$tenant && ($validated['tenant']??null)===$tenant,
        'tenant_identity_invalid');
    $servers=pgcIndex($source['servers']??[],'id','source_server_invalid');
    $plans=pgcIndex($source['plans']??[],'id','source_plan_invalid');
    $orders=pgcIndex($source['orders']??[],'id','source_order_invalid');
    pgcExpect($servers && $plans && $orders && is_array($validated['references']??null),
        'source_empty');
    $mappedServers=array_map('strval',array_keys($mapping['server_panels']??[]));
    sort($mappedServers,SORT_STRING);
    $sourceServers=array_map('strval',array_keys($servers));
    sort($sourceServers,SORT_STRING);
    pgcExpect($mappedServers===$sourceServers,'mapping_server_invalid');
    pgcExpect(count($mapping['plan_groups']??[])===count($plans),'mapping_plan_invalid');
    $panels=[];$panelIds=[];
    foreach ($native['panels']??[] as $panel) {
        pgcExpect(is_array($panel) && is_string($panel['key']??null) &&
            pgcPositive($panel['id']??null) &&
            preg_match('/^[a-f0-9]{32}$/D',(string)($panel['instance_key']??''))===1 &&
            preg_match('/^[a-f0-9]{64}$/D',(string)($panel['endpoint_sha256']??''))===1 &&
            !isset($panels[$panel['key']]) && !isset($panelIds[$panel['id']]),
            'native_panel_invalid');
        $panels[$panel['key']]=$panel;$panelIds[$panel['id']]=true;
    }
    $accounts=[];$remoteIds=[];
    foreach ($native['accounts']??[] as $nativeAccount) {
        $alias=$nativeAccount['panel_key']??null;
        pgcExpect(is_string($alias) && isset($panels[$alias]) &&
            pgcPositive($nativeAccount['id']??null) &&
            preg_match('/^[a-zA-Z0-9_@.-]{1,100}$/D',(string)($nativeAccount['username']??''))===1 &&
            pgcDate($nativeAccount['created_at']??null) &&
            pgcGroups($nativeAccount['group_ids']??null,true) &&
            is_int($nativeAccount['data_limit']??null) && $nativeAccount['data_limit']>=0 &&
            is_int($nativeAccount['used_traffic']??null) && $nativeAccount['used_traffic']>=0 &&
            array_key_exists('expire',$nativeAccount) &&
            ($nativeAccount['expire']===null || pgcPositive($nativeAccount['expire'])) &&
            in_array($nativeAccount['status']??null,['active','disabled','limited','expired'],true) &&
            is_bool($nativeAccount['native_schedules_clear']??null),'native_account_invalid');
        $key=$alias.'|'.$nativeAccount['username'];
        $nativeKey=$alias.'|'.$nativeAccount['id'];
        pgcExpect(!isset($accounts[$key]) && !isset($remoteIds[$nativeKey]),
            'native_identity_collision');
        $accounts[$key]=$nativeAccount;$remoteIds[$nativeKey]=true;
    }
    $exceptions=$validated['exceptions']??null;
    if ($exceptions!==null) pgcExpect(is_array($exceptions) &&
        is_int($exceptions['missing_accounts']??null) && $exceptions['missing_accounts']>=0 &&
        is_int($exceptions['preserved_groups']??null) && $exceptions['preserved_groups']>=0 &&
        is_int($exceptions['preserved_schedules']??null) && $exceptions['preserved_schedules']>=0,
        'exception_policy_invalid');
    $absent=[];
    foreach ($native['not_found']??[] as $missing) {
        pgcExpect(is_array($missing) && is_string($missing['panel_key']??null) &&
            isset($panels[$missing['panel_key']]) &&
            preg_match('/^[a-zA-Z0-9_@.-]{1,100}$/D',(string)($missing['username']??''))===1,
            'native_absence_invalid');
        $missingKey=$missing['panel_key'].'|'.$missing['username'];
        pgcExpect(!isset($absent[$missingKey]) && !isset($accounts[$missingKey]),
            'native_absence_conflict');
        $absent[$missingKey]=true;
    }
    $excluded=[];
    foreach ($validated['excluded_orders']??[] as $excludedRef) {
        pgcExpect(is_array($excludedRef) && pgcPositive($excludedRef['order_id']??null) &&
            !isset($excluded[$excludedRef['order_id']]),'excluded_reference_invalid');
        $excluded[$excludedRef['order_id']]=$excludedRef;
    }
    $refs=$validated['references'];$byOrder=[];
    foreach ($refs as $reference) {
        pgcExpect(is_array($reference) && pgcPositive($reference['order_id']??null) &&
            !isset($byOrder[$reference['order_id']]),'validated_reference_invalid');
        $byOrder[$reference['order_id']]=$reference;
    }
    pgcExpect(count($byOrder)+count($excluded)===count($orders),
        'validated_reference_incomplete');
    $physical=[];$nativeAllocation=[];$absentPhysical=[];$preservedPhysical=[];$preservedSchedules=[];
    foreach ($orders as $id=>$order) {
        pgcExpect(is_array($order) && isset($servers[$order['server_id']??null]) &&
            is_string($order['username']??null) &&
            preg_match('/^[a-zA-Z0-9_@.-]{1,100}$/D',$order['username'])===1 &&
            pgcPositive($order['owner_id']??null) && is_int($order['plan_id']??null) &&
            $order['plan_id']>=0 && ($order['inbound_id']??null)===0 &&
            array_key_exists('provider_effect_id',$order) && $order['provider_effect_id']===null &&
            array_key_exists('provider_remote_user_id',$order) && $order['provider_remote_user_id']===null &&
            in_array($order['auto_renew']??null,['on','off'],true) &&
            in_array($order['status']??null,[0,1],true) &&
            is_int($order['amount']??null) && $order['amount']>=0,
            'legacy_order_invalid');
        $sid=$order['server_id'];
        pgcExpect(($servers[$sid]['provider']??null)==='marzban',
            'legacy_provider_invalid');
        $alias=$mapping['server_panels'][(string)$sid]??null;
        pgcExpect(is_string($alias) && isset($panels[$alias]),'legacy_server_alias_missing');
        if ($order['plan_id']!==0) {
            $p=$plans[$order['plan_id']]??null;
            pgcExpect($p!==null && $p['server_id']===$sid && $p['inbound_id']===0,
                'legacy_order_plan_invalid');
        }
        $m=$byOrder[$id]??null;
        $accountKey=$alias.'|'.$order['username'];
        $account=$accounts[$accountKey]??null;
        $x=$excluded[$id]??null;
        if ($account===null && $x!==null && $exceptions!==null) {
            pgcExpect(isset($absent[$accountKey]) && $m===null &&
                ($x['order_id']??null)===$id && ($x['server_id']??null)===$sid &&
                ($x['username']??null)===$order['username'] &&
                ($x['owner_id']??null)===$order['owner_id'] &&
                ($x['plan_id']??null)===$order['plan_id'],
                'excluded_reference_mismatch');
            $absentPhysical[$sid.'|'.$order['username']]=true;
            continue;
        }
        pgcExpect($x===null,'excluded_reference_has_native_account');
        pgcExpect($account!==null && is_array($m) &&
            ($m['order_id']??null)===$id && ($m['server_id']??null)===$sid &&
            ($m['plan_id']??null)===$order['plan_id'] &&
            ($m['owner_id']??null)===$order['owner_id'] &&
            ($m['panel_id']??null)===$panels[$alias]['id'] &&
            ($m['remote_user_id']??null)===$account['id'] &&
            ($m['remote_created_at']??null)===$account['created_at'] &&
            ($m['native_groups']??null)===$account['group_ids'],
            'validated_reference_mismatch');
        $groups=$order['plan_id']===0?null:($mapping['plan_groups'][(string)$order['plan_id']]??null);
        $scheduled=($account['native_schedules_clear']===false);
        pgcExpect(($m['schedule_preserved']??false)===$scheduled &&
            (!$scheduled || $exceptions!==null), 'native_scheduling_unverified');
        if ($scheduled) $preservedSchedules[$sid.'|'.$order['username']]=true;
        $groupPreserved=($m['group_preserved']??null)===true;
        if ($groups!==null) {
            pgcExpect(pgcGroups($groups) && $groups===($m['planned_groups']??null),
                'native_plan_group_drift');
            if ($groupPreserved) {
                pgcExpect($exceptions!==null && $groups!==$account['group_ids'] &&
                    $account['group_ids'] &&
                    !array_diff($account['group_ids'],$panels[$alias]['available_groups']??[]),
                    'native_preserved_group_invalid');
                $preservedPhysical[$sid.'|'.$order['username']]=true;
            } else pgcExpect($groups===$account['group_ids'], 'native_plan_group_drift');
        } else {
            pgcExpect($order['plan_id']===0 && array_key_exists('planned_groups',$m) &&
                $m['planned_groups']===null && !$groupPreserved,
                'planless_mapping_invalid');
        }
        $physicalKey=$sid.'|'.$order['username'];
        $nativeKey=$alias.'|'.$account['id'];
        pgcExpect(!isset($nativeAllocation[$nativeKey]) ||
            $nativeAllocation[$nativeKey]===$physicalKey,'physical_identity_collision');
        $nativeAllocation[$nativeKey]=$physicalKey;
        if (!isset($physical[$physicalKey]))
            $physical[$physicalKey]=['server_id'=>$sid,'username'=>$order['username'],
                'alias'=>$alias,'account'=>$account,'references'=>[]];
        $physical[$physicalKey]['references'][]=['order_id'=>$id,'owner_id'=>$order['owner_id'],
            'plan_id'=>$order['plan_id']];
    }
    if ($exceptions!==null) pgcExpect(
        count($absentPhysical)===$exceptions['missing_accounts'] &&
        count($preservedPhysical)===$exceptions['preserved_groups'] &&
        count($preservedSchedules)===$exceptions['preserved_schedules'] &&
        count($absent)===count($absentPhysical), 'exception_count_mismatch');
    else pgcExpect(!$excluded && !$absent && !$preservedPhysical && !$preservedSchedules,
        'unexpected_cutover_exception');
    foreach ($plans as $id=>$p)
        pgcExpect(isset($servers[$p['server_id']??null]) && $p['inbound_id']===0 &&
            pgcGroups($mapping['plan_groups'][(string)$id]??null),
            'plan_mapping_incomplete');
    foreach ($physical as &$item) {
        usort($item['references'],static fn($a,$b)=>$a['order_id']<=>$b['order_id']);
        pgcExpect($item['account']['group_ids'] ||
            !array_filter($item['references'],static fn($r)=>$r['plan_id']!==0),
            'planless_native_groups_invalid');
    }
    unset($item);
    return compact('servers','plans','orders','physical','panels','mapping',
        'excluded','preservedPhysical','preservedSchedules');
}

function pgcDatabase(mysqli $db,array $data,string $tenant,string $fingerprint,bool $apply): array {
    $servers=$data['servers'];$plans=$data['plans'];$orders=$data['orders'];
    $physical=$data['physical'];$panels=$data['panels'];$map=$data['mapping'];
    $excluded=$data['excluded'];$preserved=$data['preservedPhysical'];
    $scheduled=$data['preservedSchedules'];
    $journalKey='ACHBOT_PG_CUTOVER:v1:'.$tenant;
    $lock=$apply?' FOR UPDATE':'';
    $journal=pgcRows($db,'SELECT id,value FROM setting WHERE type=?'.$lock,[$journalKey]);
    pgcExpect(count($journal)<=1,'journal_ambiguous');
    $existing=$journal?json_decode((string)$journal[0]['value'],true):null;
    if ($journal) pgcExpect(is_array($existing) &&
        ($existing['version']??null)===1 &&
        ($existing['fingerprint']??null)===$fingerprint &&
        ($existing['orders']??null)===count($orders) &&
        ($existing['physical_accounts']??null)===count($physical) &&
        ($existing['excluded_orders']??null)===count($excluded) &&
        ($existing['preserved_groups']??null)===count($preserved) &&
        ($existing['preserved_schedules']??null)===count($scheduled),
        'journal_conflict');

    $allServers=pgcRows($db,"SELECT id,type FROM server_config WHERE type='marzban' ORDER BY id".$lock);
    if (!$journal) {
        pgcExpect(array_map('intval',array_column($allServers,'id'))===
            array_values(array_map('intval',array_keys($servers))),'live_source_server_drift');
    } else {
        pgcExpect(!$allServers,'journal_source_still_active');
    }
    $testPanels=[];
    foreach ($panels as $alias=>$panel) {
        $r=pgcRows($db,"SELECT id,panel_url,provider_connection_id,provider_instance_key,provider_enabled
            FROM server_config WHERE type='pasarguard' AND provider_connection_id=?".$lock,
            [$panel['id']]);
        pgcExpect((bool)$r,'configured_target_missing');
        foreach ($r as $connection) {
            pgcExpect((int)$connection['provider_enabled']===1 &&
                (int)$connection['provider_connection_id']===$panel['id'] &&
                $connection['provider_instance_key']===$panel['instance_key'] &&
                hash('sha256',rtrim($connection['panel_url'],'/'))===$panel['endpoint_sha256'],
                'configured_target_drift');
        }
        $testPanels[$alias]=(int)$r[0]['id'];
    }
    $currentServers=pgcRows($db,'SELECT * FROM server_config WHERE id IN ('.
        implode(',',array_map('intval',array_keys($servers))).') ORDER BY id'.$lock);
    pgcExpect(count($currentServers)===count($servers),'source_config_missing');
    foreach ($currentServers as $config) {
        $sid=(int)$config['id'];$alias=$map['server_panels'][(string)$sid];
        if (!$journal) pgcExpect($config['type']==='marzban' &&
            $config['provider_connection_id']===null,'source_config_changed');
        else pgcExpect($config['type']==='pasarguard' &&
            (int)$config['provider_connection_id']===$panels[$alias]['id'] &&
            $config['provider_instance_key']===$panels[$alias]['instance_key'],
            'journal_config_changed');
    }
    $currentPlans=pgcRows($db,'SELECT id,server_id,inbound_id,provider_group_ids FROM server_plans
        ORDER BY id'.$lock);
    $currentLegacyPlans=[];
    foreach ($currentPlans as $p) if (isset($servers[(int)$p['server_id']])) $currentLegacyPlans[(int)$p['id']]=$p;
    pgcExpect(count($currentLegacyPlans)===count($plans),'live_plan_count_drift');
    foreach ($plans as $id=>$p) {
        $live=$currentLegacyPlans[$id]??null;
        $nativeGroup=json_encode($map['plan_groups'][(string)$id],JSON_THROW_ON_ERROR);
        pgcExpect($live!==null && (int)$live['server_id']===$p['server_id'] &&
            (int)$live['inbound_id']===0 &&
            ($journal ? $live['provider_group_ids']===$nativeGroup :
                $live['provider_group_ids']===null),'live_plan_changed');
    }
    $currentOrders=pgcRows($db,'SELECT id,server_id,fileid,inbound_id,userid,remark,amount,status,autoRenew,
        provider_effect_id,provider_remote_user_id,provider_remote_created_at
        FROM orders_list ORDER BY id'.$lock);
    $currentLegacyOrders=[];
    foreach ($currentOrders as $o) if (isset($servers[(int)$o['server_id']]))
        $currentLegacyOrders[(int)$o['id']]=$o;
    pgcExpect(count($currentLegacyOrders)===count($orders),'live_order_count_drift');
    foreach ($orders as $id=>$o) {
        $live=$currentLegacyOrders[$id]??null;
        pgcExpect($live!==null && (int)$live['server_id']===$o['server_id'] &&
            (int)$live['fileid']===$o['plan_id'] &&
            (int)$live['inbound_id']===0 && (int)$live['userid']===$o['owner_id'] &&
            $live['remark']===$o['username'] && (int)$live['amount']===$o['amount'] &&
            (int)$live['status']===($journal && isset($excluded[$id])?0:$o['status']) &&
            $live['autoRenew']===($journal && isset($excluded[$id])?'off':$o['auto_renew']),
            'live_order_changed');
        if (!$journal) pgcExpect($live['provider_effect_id']===null &&
            $live['provider_remote_user_id']===null &&
            $live['provider_remote_created_at']===null,'source_prelinked_order');
    }
    if (!$journal) {
        pgcExpect(is_array($data['claims']??null) &&
            ($data['claims']['uncertain_provider_effects']??null)===0 &&
            ($data['claims']['uncommitted_plan_changes']??null)===0,
            'snapshot_unsettled_effects_or_plans');
        // Recheck the *current* writer/effect state under the migration
        // transaction, not only the earlier offline source inventory.
        $currentClaims=pgcRows($db,"SELECT
            (SELECT COUNT(*) FROM service_effects e WHERE
              e.state IN ('reserved','dispatching','mutating','unknown','review')
              OR e.provider_state IN ('reserved','dispatching','mutating','unknown','review')) AS effects,
            (SELECT COUNT(*) FROM service_plan_changes p WHERE
              p.state NOT IN ('committed','aborted')) AS plans");
        pgcExpect(count($currentClaims)===1 &&
            (int)$currentClaims[0]['effects']===0 &&
            (int)$currentClaims[0]['plans']===0,
            'live_unsettled_effects_or_plans');
    }
    if ($journal) {
        foreach ($physical as $item) {
            $first=$item['references'][0];
            $r=pgcRows($db,'SELECT id,provider_reference_id,provider_connection_id,
                provider_remote_user_id,provider_remote_created_at,provider_state
                FROM service_effects WHERE source_kind=\'legacy_migration\'
                AND source_ref=?',[
                'legacy:'.$item['server_id'].':'.hash('sha256',$item['username'])]);
            pgcExpect(count($r)===1 &&
                (int)$r[0]['provider_reference_id']===(int)$r[0]['id'] &&
                (int)$r[0]['provider_remote_user_id']===$item['account']['id'] &&
                $r[0]['provider_remote_created_at']===$item['account']['created_at'] &&
                $r[0]['provider_state']==='linked','journal_native_evidence_changed');
            foreach ($item['references'] as $ref) {
                $order=$currentLegacyOrders[$ref['order_id']];
                pgcExpect((int)$order['provider_effect_id']===(int)$r[0]['id'] &&
                    (int)$order['provider_remote_user_id']===$item['account']['id'] &&
                    $order['provider_remote_created_at']===$item['account']['created_at'],
                    'journal_reference_changed');
            }
        }
        foreach ($excluded as $id=>$ref) {
            $o=$currentLegacyOrders[$id];
            pgcExpect($o['provider_effect_id']===null &&
                $o['provider_remote_user_id']===null &&
                $o['provider_remote_created_at']===null, 'journal_excluded_order_changed');
        }
        return ['outcome'=>'already_applied','servers'=>count($servers),
            'plans'=>count($plans),'orders'=>count($orders),
            'physical_accounts'=>count($physical),
            'excluded_orders'=>count($excluded),
            'preserved_groups'=>count($preserved),
            'preserved_schedules'=>count($scheduled)];
    }
    if ($apply) {
        $sourceHashes=$data['input_hashes'];
        foreach ($physical as $item) {
            $account=$item['account'];$sourceId=$item['server_id'];
            $alias=$item['alias'];$panel=$panels[$alias];
            $rawNative=['version'=>1,'id'=>$account['id'],'username'=>$account['username'],
                'created_at'=>$account['created_at'],'group_ids'=>$account['group_ids'],
                'data_limit'=>$account['data_limit'],'used_traffic'=>$account['used_traffic'],
                'expire'=>$account['expire'],'status'=>$account['status'],
                'native_schedules_clear'=>$account['native_schedules_clear']];
            $snapshot=['provider'=>'pasarguard','panel_id'=>$panel['id'],
                'instance_key'=>$panel['instance_key'],
                'endpoint_sha256'=>$panel['endpoint_sha256'],
                'group_ids'=>$account['group_ids'],
                'identity_mode'=>'historical_cutover',
                'migration'=>['version'=>1,'tenant'=>$tenant,'source_server_id'=>$sourceId,
                    'native_schedule_preserved'=>!$account['native_schedules_clear'],
                    'source_input_sha256'=>$sourceHashes['local'],
                    'native_input_sha256'=>$sourceHashes['native'],
                    'mapping_input_sha256'=>$sourceHashes['mapping'],
                    'references'=>$item['references']]];
            $json=json_encode($snapshot,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            $entitlement=json_encode($rawNative,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            $operation=substr(hash('sha256',$tenant.'|'.$sourceId.'|'.$item['username'].'|'.
                $account['id'].'|'.$account['created_at'].'|'.$fingerprint),0,32);
            $sourceRef='legacy:'.$sourceId.':'.hash('sha256',$item['username']);
            $expire=$account['expire']??0;
            $stmt=pgcStmt($db,"INSERT INTO service_effects
                (pay_row_id,source_kind,source_ref,actor_userid,ordinal,reserved_count,
                operation_id,effect_kind,target_server_id,target_group_id,target_key,
                endpoint_sha256,plan_id,remote_username,requested_expire,requested_bytes,
                request_sha256,state,order_id,verified_at,provider_snapshot)
                VALUES (NULL,'legacy_migration',?,NULL,1,0,?,'adopt',?,NULL,?,?,0,?,?,?,
                ?,'local_recorded',NULL,UTC_TIMESTAMP(),?)",[
                $sourceRef,$operation,$sourceId,'pg:'.$panel['instance_key'],
                $panel['endpoint_sha256'],$item['username'],
                $expire,$account['data_limit'],hash('sha256',$json),$json]);
            pgcExpect($stmt->affected_rows===1,'migration_effect_insert_failed');
            $effectId=(int)$stmt->insert_id;$stmt->close();
            pgcUpdate($db,"UPDATE service_effects SET provider_reference_id=id,
                provider_connection_id=?,provider_remote_user_id=?,provider_remote_created_at=?,
                provider_operation_marker=?,provider_entitlement_snapshot=?,provider_state='linked',
                provider_verified_at=UTC_TIMESTAMP(),provider_evidence_created_at=UTC_TIMESTAMP(),
                provider_evidence_updated_at=UTC_TIMESTAMP(),updated_at=updated_at
                WHERE id=? AND provider_reference_id IS NULL",[
                $panel['id'],$account['id'],$account['created_at'],
                'achbot:adopt:'.$operation,$entitlement,$effectId]);
            foreach ($item['references'] as $ref) {
                pgcUpdate($db,"UPDATE orders_list SET provider_effect_id=?,
                    provider_remote_user_id=?,provider_remote_created_at=?
                    WHERE id=? AND provider_effect_id IS NULL AND
                    provider_remote_user_id IS NULL AND provider_remote_created_at IS NULL",[
                    $effectId,$account['id'],$account['created_at'],$ref['order_id']]);
            }
        }
        // The Owner approved orphaned historical order rows to remain in the
        // financial ledger, but not to advertise a missing Native service or
        // participate in future automation. No source row is deleted.
        foreach ($excluded as $id=>$ref) {
            $o=$currentLegacyOrders[$id];
            if ((int)$o['status']!==0 || $o['autoRenew']!=='off')
                pgcUpdate($db,"UPDATE orders_list SET status=0,autoRenew='off'
                    WHERE id=? AND status=? AND autoRenew=? AND
                    provider_effect_id IS NULL AND provider_remote_user_id IS NULL",[
                    $id,$o['status'],$o['autoRenew']]);
        }
        foreach ($plans as $id=>$plan)
            pgcUpdate($db,'UPDATE server_plans SET provider_group_ids=?
                WHERE id=? AND provider_group_ids IS NULL',[
                json_encode($map['plan_groups'][(string)$id],JSON_THROW_ON_ERROR),$id]);
        foreach ($servers as $sid=>$source) {
            $alias=$map['server_panels'][(string)$sid];
            pgcUpdate($db,"UPDATE server_config c JOIN server_config t ON t.id=?
                SET c.type='pasarguard',c.panel_url=t.panel_url,c.password=t.password,
                c.provider_connection_id=t.provider_connection_id,
                c.provider_instance_key=t.provider_instance_key,c.provider_version=t.provider_version,
                c.provider_enabled=t.provider_enabled WHERE c.id=? AND c.type='marzban'",[
                $testPanels[$alias],$sid]);
        }
        $journalDoc=json_encode(['version'=>1,'fingerprint'=>$fingerprint,
            'servers'=>count($servers),'plans'=>count($plans),'orders'=>count($orders),
            'physical_accounts'=>count($physical),
            'excluded_orders'=>count($excluded),
            'preserved_groups'=>count($preserved),
            'preserved_schedules'=>count($scheduled)],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        pgcUpdate($db,'INSERT INTO setting (type,value) VALUES (?,?)',
            [$journalKey,$journalDoc]);
    }
    return ['outcome'=>$apply?'applied_pending_commit':'dry_run_only',
        'servers'=>count($servers),'plans'=>count($plans),'orders'=>count($orders),
        'physical_accounts'=>count($physical),
        'excluded_orders'=>count($excluded),
        'preserved_groups'=>count($preserved),
        'preserved_schedules'=>count($scheduled)];
}

$database=null;
try {
    $opt=getopt('',['tenant:','local:','native:','mapping:','validated-map:','apply','dry-run']);
    $tenant=$opt['tenant']??null;
    $apply=array_key_exists('apply',$opt);
    pgcExpect(in_array($tenant,['vivan','hell'],true) &&
        !($apply && array_key_exists('dry-run',$opt)) &&
        count($opt)===($apply||array_key_exists('dry-run',$opt)?6:5) &&
        is_string($opt['local']??null) && is_string($opt['native']??null) &&
        is_string($opt['mapping']??null) &&
        is_string($opt['validated-map']??null),'arguments_invalid');
    [$source,$sourceHash]=pgcRead($opt['local']);
    [$native,$nativeHash]=pgcRead($opt['native']);
    [$mapping,$mappingHash]=pgcRead($opt['mapping']);
    [$validated,$validatedHash]=pgcRead($opt['validated-map']);
    $hashes=['local'=>$sourceHash,'native'=>$nativeHash,'mapping'=>$mappingHash];
    $observedHashes=$validated['input_sha256']??null;
    pgcExpect(is_array($observedHashes) && count($observedHashes)===count($hashes),
        'validated_inputs_changed');
    $sortedHashes=$hashes;
    ksort($sortedHashes,SORT_STRING);
    ksort($observedHashes,SORT_STRING);
    pgcExpect($observedHashes===$sortedHashes,'validated_inputs_changed');
    $data=pgcPlan($source,$native,$mapping,$validated,$tenant);
    $data['input_hashes']=$hashes;
    $data['claims']=$source['claims']??null;
    $fingerprint=hash('sha256',json_encode([$tenant,$hashes,$validatedHash],
        JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    $socket=getenv('ACHBOT_PG_CUTOVER_DB_SOCKET');
    $dbName=getenv('ACHBOT_PG_CUTOVER_DB');
    $dbUser=getenv('ACHBOT_PG_CUTOVER_DB_USER');
    $password=getenv('ACHBOT_PG_CUTOVER_DB_PASSWORD');
    pgcExpect(is_string($socket) && str_starts_with($socket,'/') &&
        $dbName==='bot'.$tenant && is_string($dbUser) && $dbUser!=='' &&
        $password!==false,'database_environment_invalid');
    mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
    $database=mysqli_init();
    $database->real_connect('localhost',$dbUser,(string)$password,$dbName,null,$socket);
    $database->begin_transaction($apply?MYSQLI_TRANS_START_READ_WRITE:MYSQLI_TRANS_START_READ_ONLY);
    $result=pgcDatabase($database,$data,$tenant,$fingerprint,$apply);
    if ($apply && $result['outcome']==='applied_pending_commit') {
        $database->commit();$result['outcome']='applied';
    } else $database->rollback();
    echo json_encode(['complete'=>true,'tenant'=>$tenant,'input_sha256'=>$fingerprint]+$result,
        JSON_THROW_ON_ERROR)."\n";
    exit(0);
} catch (Throwable $e) {
    if ($database instanceof mysqli) {try {$database->rollback();}catch(Throwable $ignored) {}}
    // Fixture-only diagnostic: emit class/code/line, never SQL arguments.
    if (getenv('ACHBOT_PG_CUTOVER_FIXTURE_DEBUG')==='1')
        fwrite(STDERR,get_class($e).':'.(int)$e->getCode().':'.(int)$e->getLine()."\n");
    // Do not expose SQL values, paths, usernames, URLs, secrets, or provider data.
    $code=$e instanceof RuntimeException?$e->getMessage():'cutover_internal_error';
    if (!preg_match('/^[a-z_]{3,70}$/D',$code)) $code='cutover_internal_error';
    echo json_encode(['complete'=>false,'error_code'=>$code],
        JSON_THROW_ON_ERROR)."\n";
    exit(2);
}
