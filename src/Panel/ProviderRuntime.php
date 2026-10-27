<?php
/** AChBot current provider dispatch. Native identities never use legacy UUID/inbound fields. */
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) { http_response_code(404); exit; }

function achbotProviderType(mysqli $db,int $serverId): string {
    $s=$db->prepare('SELECT type FROM server_config WHERE id=?');
    if (!$s) return '';
    $s->bind_param('i',$serverId);$ok=$s->execute();
    $r=$ok?$s->get_result()->fetch_assoc():null;$s->close();
    return (string)($r['type']??'');
}
function achbotSupportsAccount($type,$inbound): bool {
    return in_array($type,['marzban','pasarguard'],true) && (int)$inbound===0;
}
function achbotSupportsPlan($db,$planId,$inbound=0,$serverId=null,bool $lock=false): bool {
    if (achbotSupportsMarzbanPlan($db,$planId,$inbound,$serverId,$lock)) return true;
    if ((int)$planId<=0 || (int)$inbound!==0) return false;
    try {
        $s=$db->prepare('SELECT p.server_id,p.inbound_id,c.type FROM server_plans p JOIN server_config c ON c.id=p.server_id WHERE p.id=? AND p.active=1'.($lock?' FOR UPDATE':''));
        $s->bind_param('i',$planId);$s->execute();$r=$s->get_result()->fetch_assoc();$s->close();
        if (!$r || $r['type']!=='pasarguard' || (int)$r['inbound_id']!==0 ||
            ($serverId!==null && (int)$serverId!==0 && (int)$serverId!==(int)$r['server_id'])) return false;
        achbotPgPlanSnapshot($db,(int)$r['server_id'],(int)$planId,$lock);
        return true;
    } catch (Throwable $e) { return false; }
}
function achbotSupportsOrder($db,$orderId,$planId=null,$userId=null,bool $lock=false): bool {
    if (achbotSupportsMarzbanOrder($db,$orderId,$planId,$userId,$lock)) return true;
    try {
        $s=$db->prepare('SELECT o.*,c.type,p.server_id AS plan_server,p.inbound_id AS plan_inbound FROM orders_list o JOIN server_config c ON c.id=o.server_id LEFT JOIN server_plans p ON p.id=o.fileid WHERE o.id=?'.($lock?' FOR UPDATE':''));
        $s->bind_param('i',$orderId);$s->execute();$r=$s->get_result()->fetch_assoc();$s->close();
        if (!$r || $r['type']!=='pasarguard' || (int)$r['inbound_id']!==0 ||
            ($planId!==null && (int)$r['fileid']!==(int)$planId) ||
            ($userId!==null && (int)$r['userid']!==(int)$userId)) return false;
        achbotPgOrderContext($db,(int)$orderId,$lock);
        return (int)$r['fileid']===0 ? $planId===null :
            (int)$r['plan_inbound']===0 && achbotSameProviderPanel($db,(int)$r['server_id'],(int)$r['plan_server']);
    } catch (Throwable $e) { return false; }
}
function achbotSameProviderPanel(mysqli $db,int $a,int $b): bool {
    $ta=achbotProviderType($db,$a);$tb=achbotProviderType($db,$b);
    if ($ta!==$tb) return false;
    if ($ta==='marzban') return $a===$b || achbotSameMarzbanPanel($db,$a,$b);
    if ($ta!=='pasarguard') return false;
    try {
        $pa=achbotPgServerPanel($db,$a);$pb=achbotPgServerPanel($db,$b);
        return $pa['id']===$pb['id'] && $pa['instance_key']===$pb['instance_key'] && $pa['api_url']===$pb['api_url'];
    } catch (Throwable $e) { return false; }
}
function achbotProviderFailure(string $reason,bool $uncertain=true): object {
    return (object)['success'=>false,'uncertain'=>$uncertain,'msg'=>$reason];
}
function achbotProviderUser($serverId,$remark,bool $withLinks=true): object {
    global $connection;
    $type=achbotProviderType($connection,(int)$serverId);
    if ($type==='marzban') return getMarzbanUser($serverId,$remark);
    if ($type!=='pasarguard') return achbotProviderFailure('unsupported_provider',false);
    try {
        $ctx=achbotPgRemarkContext($connection,(int)$serverId,(string)$remark);
        $r=achbotPgRead($ctx['panel'],$ctx['identity']);
        if (($r['success']??false)!==true) return achbotProviderFailure($r['reason']??'pg_read_unverified');
        $u=$r['user'];
        $u['provider']='pasarguard';$u['provider_account_id']=$ctx['account']['id'];
        if ($withLinks) {
            $links=achbotPgLinks($ctx['panel'],$ctx['identity']);
            $u['links']=($links['success']??false)===true?($links['links']??[]):[];
        }
        return (object)$u;
    } catch (Throwable $e) { return achbotProviderFailure('pg_account_identity_unverified'); }
}
function achbotProviderUserInfo($serverId,$remark): object {
    global $connection;
    return achbotProviderType($connection,(int)$serverId)==='marzban'?
        getMarzbanUserInfo($serverId,$remark):achbotProviderUser($serverId,$remark);
}
function achbotProviderAction($serverId,$remark,string $action): object {
    global $connection;
    $type=achbotProviderType($connection,(int)$serverId);
    if ($type==='marzban') return match($action) {
        'delete'=>deleteMarzban($serverId,$remark), 'revoke'=>renewMarzbanUUID($serverId,$remark),
        'toggle'=>changeMarzbanState($serverId,$remark),default=>achbotProviderFailure('unsupported_action',false)
    };
    if ($type!=='pasarguard') return achbotProviderFailure('unsupported_provider',false);
    try {
        if (!in_array($action,['delete','revoke','reset','toggle'],true)) return achbotProviderFailure('unsupported_action',false);
        $ctx=achbotPgRemarkContext($connection,(int)$serverId,(string)$remark);
        $panel=$ctx['panel'];$identity=$ctx['identity'];
        $key=$action==='delete'?'delete:'.(int)$ctx['account']['id']:achbotPgOperationKey($action);
        $intent=achbotPgBeginMutation($connection,$ctx,$key,$action,['phase'=>'claimed']);
        $read=achbotPgRead($panel,$identity);
        if (($read['success']??false)!==true) throw new RuntimeException('pg_action_read_unverified');
        $before=$read['user'];
        $expected=match($action) {
            'delete'=>['absent'=>true,'http'=>404],
            'reset'=>['used_traffic'=>0],
            'revoke'=>['proxy_identity_must_change'=>true,'before_proxy_sha256'=>hash('sha256',json_encode($before['proxy_settings'],JSON_THROW_ON_ERROR))],
            'toggle'=>['status'=>$before['status']==='active'?'disabled':'active']
        };
        if ($action==='toggle' && !in_array($before['status'],['active','disabled'],true)) throw new RuntimeException('pg_status_restricted');
        $intent=achbotPgPersistMutationIntent($connection,$ctx,$intent,[
            'before_status'=>$before['status'],'expected'=>$expected]);
        $r=match($action) {
            'delete'=>achbotPgDelete($panel,$identity),'revoke'=>achbotPgRevoke($panel,$identity),
            'reset'=>achbotPgReset($panel,$identity),'toggle'=>achbotPgModify($panel,$identity,$expected)
        };
        if (($r['success']??false)!==true) return achbotProviderFailure($r['reason']??'pg_action_unverified',(bool)($r['uncertain']??true));
        achbotPgFinishMutation($connection,$ctx,$intent,$r,$action==='delete'?'deleted':'linked');
        if ($action==='delete') return (object)['success'=>true,'verified'=>true];
        $user=achbotProviderUser($serverId,$remark);
        if (!isset($user->username)) return achbotProviderFailure('pg_action_delivery_unverified');
        return (object)array_merge((array)$user,['success'=>true,'verified'=>true]);
    } catch (Throwable $e) { return achbotProviderFailure('pg_action_requires_review'); }
}

function achbotProviderDelete($serverId,$remark): object { return achbotProviderAction($serverId,$remark,'delete'); }
function achbotProviderRevoke($serverId,$remark): object { return achbotProviderAction($serverId,$remark,'revoke'); }
function achbotProviderToggle($serverId,$remark): object { return achbotProviderAction($serverId,$remark,'toggle'); }

function achbotPgLoad(): void {
    require_once __DIR__.'/PasarGuard.php';
    require_once __DIR__.'/ProviderStorage.php';
}
function achbotPgServerPanel(mysqli $db,int $serverId): array {
    achbotPgLoad();
    $s=$db->prepare('SELECT i.provider_panel_id,c.type,c.panel_url FROM server_info i JOIN server_config c ON c.id=i.id WHERE i.id=?');
    $s->bind_param('i',$serverId);$s->execute();$r=$s->get_result()->fetch_assoc();$s->close();
    if (!$r || $r['type']!=='pasarguard') throw new RuntimeException('pg_server_identity_missing');
    $p=achbotProviderPanel($db,(int)$r['provider_panel_id']);
    if (rtrim($r['panel_url'],'/')!==rtrim($p['api_url'],'/')) throw new RuntimeException('pg_endpoint_changed');
    return $p;
}
function achbotPgPlanSnapshot(mysqli $db,int $serverId,int $planId,bool $lock=false): array {
    achbotPgLoad();achbotPgServerPanel($db,$serverId);
    return achbotProviderSnapshot($db,$serverId,$planId);
}
function achbotPgOrderContext(mysqli $db,int $orderId,bool $lock=false): array {
    achbotPgLoad();
    $a=achbotProviderAccountByOrder($db,$orderId);
    if (!$a || !in_array($a['state'],['verified','linked'],true) || (int)$a['remote_user_id']<=0 || empty($a['remote_created_at'])) throw new RuntimeException('pg_account_not_verified');
    $s=$db->prepare('SELECT o.server_id,o.remark,c.type FROM orders_list o JOIN server_config c ON c.id=o.server_id WHERE o.id=?'.($lock?' FOR UPDATE':''));
    $s->bind_param('i',$orderId);$s->execute();$o=$s->get_result()->fetch_assoc();$s->close();
    if (!$o || $o['type']!=='pasarguard' || $o['remark']!==$a['remote_username']) throw new RuntimeException('pg_order_identity_changed');
    $panel=achbotPgServerPanel($db,(int)$o['server_id']);
    if ((int)$panel['id']!==(int)$a['panel_id'] || $panel['instance_key']!==$a['instance_key'] || hash('sha256',rtrim($panel['api_url'],'/'))!==$a['endpoint_sha256']) throw new RuntimeException('pg_account_panel_changed');
    return ['order_server_id'=>(int)$o['server_id'],'account'=>$a,'panel'=>$panel,'identity'=>['remote_user_id'=>(int)$a['remote_user_id'],'remote_created_at'=>$a['remote_created_at'],'operation_marker'=>$a['operation_marker']]];
}
function achbotPgRemarkContext(mysqli $db,int $serverId,string $remark): array {
    // Exact local ownership lookup only. Never list/search vendor users by a partial name.
    $s=$db->prepare('SELECT o.id FROM orders_list o JOIN provider_accounts a ON a.order_id=o.id WHERE o.server_id=? AND o.remark=? LIMIT 2');
    $s->bind_param('is',$serverId,$remark);$s->execute();$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
    if (count($rows)!==1) throw new RuntimeException('pg_local_account_ambiguous');
    return achbotPgOrderContext($db,(int)$rows[0]['id']);
}
function achbotPgPinPayment(mysqli $db,int $payId): void {
    $s=$db->prepare('SELECT * FROM pays WHERE id=?');$s->bind_param('i',$payId);$s->execute();$p=$s->get_result()->fetch_assoc();$s->close();
    if (!$p || achbotProviderType($db,(int)$p['server_id'])!=='pasarguard') return;
    achbotPgLoad();
    if ($p['type']==='BUY_SUB') {
        $snapshot=achbotProviderSnapshot($db,(int)$p['server_id'],(int)$p['plan_id']);
    } else {
        $oid=(int)($p['order_id']??0);
        if ($oid<=0 && preg_match('/^INCREASE_(?:DAY|VOLUME)_([1-9][0-9]*)_/',(string)$p['type'],$m)) $oid=(int)$m[1];
        $ctx=achbotPgOrderContext($db,$oid);
        $entitlement=json_decode($ctx['account']['entitlement_snapshot'],true,64,JSON_THROW_ON_ERROR);
        $groups=$entitlement['group_ids']??null;
        $mutation=json_decode((string)($ctx['account']['mutation_snapshot']??''),true);
        if (($mutation['result']??'')==='verified') $groups=$mutation['current_group_ids']??$groups;
        $snapshot=['provider'=>'pasarguard','panel_id'=>(int)$ctx['panel']['id'],
            'instance_key'=>$ctx['panel']['instance_key'],'endpoint_sha256'=>$ctx['account']['endpoint_sha256'],
            'group_ids'=>$groups,'group_policy'=>'preserve_current','provider_account_id'=>(int)$ctx['account']['id'],
            'remote_user_id'=>(int)$ctx['account']['remote_user_id'],
            'remote_created_at'=>$ctx['account']['remote_created_at'],'operation_marker'=>$ctx['account']['operation_marker']];
    }
    $json=json_encode($snapshot,JSON_THROW_ON_ERROR);
    $s=$db->prepare('UPDATE pays SET provider_snapshot=? WHERE id=? AND provider_snapshot IS NULL');
    $s->bind_param('si',$json,$payId);
    if (!$s->execute() || $s->affected_rows!==1) throw new RuntimeException('pg_quote_pin_unconfirmed');$s->close();
}
function achbotPgQuote(mysqli $db,int $payId): array {
    achbotPgLoad();
    $s=$db->prepare('SELECT provider_snapshot,server_id,plan_id,type,order_id FROM pays WHERE id=?');$s->bind_param('i',$payId);$s->execute();$p=$s->get_result()->fetch_assoc();$s->close();
    $snap=json_decode((string)($p['provider_snapshot']??''),true,64,JSON_THROW_ON_ERROR);
    if (!is_array($snap) || ($snap['provider']??'')!=='pasarguard') throw new RuntimeException('pg_quote_snapshot_missing');
    $panel=achbotProviderPanelForSnapshot($db,$snap);$current=achbotPgServerPanel($db,(int)$p['server_id']);
    if ($current['id']!==$panel['id'] || $current['instance_key']!==$panel['instance_key']) throw new RuntimeException('pg_quote_panel_changed');
    if ($p['type']!=='BUY_SUB') {
        $oid=(int)$p['order_id'];
        if ($oid<=0 && preg_match('/^INCREASE_(?:DAY|VOLUME)_([1-9][0-9]*)_/',(string)$p['type'],$m)) $oid=(int)$m[1];
        $ctx=achbotPgOrderContext($db,$oid);
        foreach (['remote_user_id','remote_created_at','operation_marker'] as $key)
            if ((string)($snap[$key]??'')!==(string)$ctx['identity'][$key]) throw new RuntimeException('pg_quote_account_changed');
    }
    return $snap;
}
function achbotEffectTargetIdentity(mysqli $db,int $serverId,?int $planId,?int $payId,array $row): array {
    if (($row['panel_type']??$row['type']??'')!=='pasarguard') return [
        'group'=>$row['panel_group_id']===null?null:(int)$row['panel_group_id'],
        'endpoint'=>hash('sha256',rtrim($row['panel_url'],'/')),
        'credentials'=>hash('sha256',json_encode([(string)($row['panel_username']??$row['username']??''),(string)($row['panel_password']??$row['password']??'')],JSON_THROW_ON_ERROR)),
        'key'=>$row['panel_group_id']===null?'server:'.$serverId:'group:'.(int)$row['panel_group_id']
    ];
    $snap=$payId===null?achbotPgPlanSnapshot($db,$serverId,(int)$planId):achbotPgQuote($db,$payId);
    $panel=achbotProviderPanelForSnapshot($db,$snap);
    return ['group'=>null,'endpoint'=>$snap['endpoint_sha256'],'credentials'=>hash('sha256',$panel['api_key']),'key'=>'pg:'.$snap['instance_key'],'snapshot'=>$snap];
}
function achbotPgReserveEffect(mysqli $db,int $effectId,array $target,string $remark,string $operation,int $expire,int $bytes): void {
    if (!isset($target['snapshot'])) return;
    if ($expire<=0 || $expire>2147483647) throw new RuntimeException('pg_create_local_expiry_overflow');
    $snapshot=$target['snapshot'];$json=json_encode($snapshot,JSON_THROW_ON_ERROR);
    $s=$db->prepare('UPDATE service_effects SET provider_snapshot=? WHERE id=? AND provider_snapshot IS NULL');$s->bind_param('si',$json,$effectId);
    if (!$s->execute() || $s->affected_rows!==1) throw new RuntimeException('pg_effect_snapshot_unconfirmed');$s->close();
    $reservation=achbotProviderReserveAccount($db,$effectId,$snapshot,$remark,'achbot:v1:'.$operation,['data_limit'=>$bytes,'expire'=>$expire,'group_ids'=>$snapshot['group_ids'],'status'=>'active']);
    if (($reservation['newly_reserved']??false)!==true) throw new RuntimeException('pg_account_already_reserved');
}

/** Read the immutable effect snapshot without requiring 011 for old providers. */
function achbotPgEffect(mysqli $db,int $effectId): ?array {
    $s=$db->prepare('SELECT e.*,c.type AS panel_type FROM service_effects e JOIN server_config c ON c.id=e.target_server_id WHERE e.id=?');
    $s->bind_param('i',$effectId);$s->execute();$e=$s->get_result()->fetch_assoc();$s->close();
    if (!$e || $e['panel_type']!=='pasarguard') return null;
    achbotPgLoad();
    $s=$db->prepare('SELECT provider_snapshot FROM service_effects WHERE id=?');
    $s->bind_param('i',$effectId);$s->execute();$r=$s->get_result()->fetch_assoc();$s->close();
    $e['provider_snapshot']=json_decode((string)($r['provider_snapshot']??''),true,64,JSON_THROW_ON_ERROR);
    return $e;
}
function achbotPgDispatchReserved(mysqli $db,int $effectId,int $serverId,string $remark,
    string $operation,int $expiry,int $bytes,array $p,string $credentials): array {
    $e=achbotPgEffect($db,$effectId);
    if (!$e || $e['state']!=='dispatching' || $e['remote_username']!==$remark ||
        $e['operation_id']!==$operation || (int)$e['requested_expire']!==$expiry ||
        (int)$e['requested_bytes']!==$bytes) throw new RuntimeException('pg_dispatch_reservation_changed');
    $snap=$e['provider_snapshot'];$panel=achbotProviderPanelForSnapshot($db,$snap);
    $current=achbotPgServerPanel($db,$serverId);
    if ($current['id']!==$panel['id'] || $current['instance_key']!==$panel['instance_key'] ||
        !hash_equals($credentials,hash('sha256',$panel['api_key']))) throw new RuntimeException('pg_dispatch_target_changed');
    $a=achbotProviderAccountByEffect($db,$effectId);
    if (!$a || $a['state']!=='reserved' || $a['remote_user_id']!==null)
        throw new RuntimeException('pg_dispatch_already_started');
    // This single guarded, committed transition is the dispatch authority.
    $s=$db->prepare("UPDATE provider_accounts SET state='dispatching' WHERE id=? AND state='reserved' AND remote_user_id IS NULL");
    $aid=(int)$a['id'];$s->bind_param('i',$aid);
    if (!$s->execute() || $s->affected_rows!==1) throw new RuntimeException('pg_dispatch_claim_unconfirmed');$s->close();
    $r=achbotPgCreate($panel,['username'=>$remark,'data_limit'=>$bytes,'expire'=>$expiry,
        'group_ids'=>$snap['group_ids'],'operation_marker'=>'achbot:v1:'.$operation]);
    if (($r['success']??false)!==true) throw new RuntimeException('pg_create_requires_review');
    if (!$db->begin_transaction()) throw new RuntimeException('pg_verify_begin');
    try {
        $after=achbotProviderPanelForSnapshot($db,$snap);
        if (!hash_equals($credentials,hash('sha256',$after['api_key']))) throw new RuntimeException('pg_credentials_changed');
        achbotProviderVerifyAccount($db,$effectId,$r['user']);
        $s=$db->prepare("UPDATE service_effects SET state='verified',verified_at=NOW() WHERE id=? AND state='dispatching' AND order_id IS NULL");
        $s->bind_param('i',$effectId);
        if (!$s->execute() || $s->affected_rows!==1) throw new RuntimeException('pg_effect_verification_unconfirmed');$s->close();
        if (!$db->commit()) throw new RuntimeException('pg_verify_commit_unknown');
    } catch (Throwable $error) {try{$db->rollback();}catch(Throwable $ignored){} throw $error;}
    $identity=['remote_user_id'=>$r['user']['id'],'remote_created_at'=>$r['user']['created_at'],'operation_marker'=>'achbot:v1:'.$operation];
    $links=achbotPgLinks($panel,$identity);
    $user=$r['user'];$user['links']=($links['success']??false)===true?($links['links']??[]):[];
    $user['provider']='pasarguard';$user['success']=true;$user['verified']=true;
    return [(object)$user,$remark,(int)($p['port']??0),$effectId];
}
function achbotPgLinkEffect(mysqli $db,int $effectId,int $orderId): bool {
    $e=achbotPgEffect($db,$effectId);
    if ($e===null) return false;
    if ($e['state']!=='verified' || $e['order_id']!==null) throw new RuntimeException('pg_effect_not_linkable');
    $s=$db->prepare('SELECT o.*,p.user_id AS paid_user FROM orders_list o LEFT JOIN pays p ON p.id=? WHERE o.id=? FOR UPDATE');
    $pay=(int)($e['pay_row_id']??0);$s->bind_param('ii',$pay,$orderId);$s->execute();$o=$s->get_result()->fetch_assoc();$s->close();
    $owner=$pay>0?(int)($o['paid_user']??0):(int)$e['actor_userid'];
    if (!$o || (int)$o['userid']!==$owner || (int)$o['fileid']!==(int)$e['plan_id'] ||
        (int)$o['server_id']!==(int)$e['target_server_id'] || (int)$o['inbound_id']!==0 ||
        $o['remark']!==$e['remote_username']) throw new RuntimeException('pg_effect_order_mismatch');
    achbotProviderPanelForSnapshot($db,$e['provider_snapshot']);
    achbotProviderLinkAccount($db,$effectId,$orderId);
    $s=$db->prepare("UPDATE service_effects SET order_id=?,state='local_recorded' WHERE id=? AND state='verified' AND order_id IS NULL");
    $s->bind_param('ii',$orderId,$effectId);
    if (!$s->execute() || $s->affected_rows!==1) throw new RuntimeException('pg_effect_link_unconfirmed');$s->close();
    return true;
}
function achbotPgOperationKey(string $action,array $change=[]): string {
    if (isset($change['operation_key']) && is_string($change['operation_key']) &&
        preg_match('/^[a-zA-Z0-9:_-]{1,100}$/D',$change['operation_key'])===1) return $change['operation_key'];
    if ((int)($change['pay_id']??0)>0) return 'pay:'.(int)$change['pay_id'];
    $update=$GLOBALS['update']??null;
    if (is_object($update) && is_int($update->update_id??null) && $update->update_id>0)
        return 'telegram:'.$update->update_id.':'.$action;
    throw new RuntimeException('pg_mutation_source_missing');
}
/** One durable account fence spans reset+modify and survives lost responses. */
function achbotPgBeginMutation(mysqli $db,array $ctx,string $key,string $action,array $changes): array {
    $tx=$db->query('SELECT @@in_transaction AS tx');
    if (!$tx || (int)($tx->fetch_assoc()['tx']??1)!==0) throw new RuntimeException('pg_mutation_requires_autocommit');
    if (!$db->begin_transaction()) throw new RuntimeException('pg_mutation_begin');
    try {
        $id=(int)$ctx['account']['id'];
        $s=$db->prepare('SELECT state,mutation_key,mutation_snapshot FROM provider_accounts WHERE id=? FOR UPDATE');
        $s->bind_param('i',$id);$s->execute();$a=$s->get_result()->fetch_assoc();$s->close();
        $prior=json_decode((string)($a['mutation_snapshot']??''),true);
        $consumed=is_array($prior['consumed_keys']??null)?$prior['consumed_keys']:[];
        if (!$a || $a['state']!=='linked' || ($a['mutation_key']??null)===$key || in_array($key,$consumed,true) || count($consumed)>=4096)
            throw new RuntimeException('pg_account_mutation_requires_review');
        $consumed[]=$key;
        $intent=['version'=>1,'key'=>$key,'consumed_keys'=>$consumed,'action'=>$action,'changes'=>$changes,'identity'=>$ctx['identity'],
            'requested_at'=>gmdate('c')];
        $json=json_encode($intent,JSON_THROW_ON_ERROR);
        $s=$db->prepare("UPDATE provider_accounts SET state='mutating',mutation_key=?,mutation_snapshot=? WHERE id=? AND state='linked'");
        $s->bind_param('ssi',$key,$json,$id);
        if (!$s->execute() || $s->affected_rows!==1) throw new RuntimeException('pg_mutation_claim_unconfirmed');$s->close();
        if (!$db->commit()) throw new RuntimeException('pg_mutation_commit_unknown');
        return $intent;
    } catch (Throwable $error) {try{$db->rollback();}catch(Throwable $ignored){}throw $error;}
}
function achbotPgPersistMutationIntent(mysqli $db,array $ctx,array $intent,array $changes): array {
    $intent['changes']=$changes;$intent['phase']='dispatching';
    $json=json_encode($intent,JSON_THROW_ON_ERROR);$id=(int)$ctx['account']['id'];$key=$intent['key'];
    $s=$db->prepare("UPDATE provider_accounts SET mutation_snapshot=? WHERE id=? AND state='mutating' AND mutation_key=?");
    $s->bind_param('sis',$json,$id,$key);
    if (!$s->execute() || $s->affected_rows!==1) throw new RuntimeException('pg_mutation_intent_unconfirmed');$s->close();
    return $intent;
}
function achbotPgFinishMutation(mysqli $db,array $ctx,array $intent,array $result,string $state='linked'): void {
    if (($result['success']??false)!==true) throw new RuntimeException('pg_mutation_requires_review');
    $current=achbotPgServerPanel($db,(int)$ctx['order_server_id']);
    if ($current['id']!==$ctx['panel']['id'] || $current['instance_key']!==$ctx['panel']['instance_key'] ||
        $current['api_url']!==$ctx['panel']['api_url'] || !hash_equals($current['api_key'],$ctx['panel']['api_key']))
        throw new RuntimeException('pg_mutation_target_changed');
    $id=(int)$ctx['account']['id'];$key=$intent['key'];
    $intent['verified_at']=gmdate('c');$intent['result']='verified';
    if (isset($result['user']['group_ids'])) $intent['current_group_ids']=$result['user']['group_ids'];
    $json=json_encode($intent,JSON_THROW_ON_ERROR);
    $s=$db->prepare("UPDATE provider_accounts SET state=?,mutation_snapshot=? WHERE id=? AND state='mutating' AND mutation_key=?");
    $s->bind_param('ssis',$state,$json,$id,$key);
    if (!$s->execute() || $s->affected_rows!==1) throw new RuntimeException('pg_mutation_finish_unknown');$s->close();
}
/** Pure absolute entitlement calculation; provider Groups are never replaced on renewal. */
function achbotPgEditChanges(array $user,array $change,?int $clock=null): array {
    $now=$clock??time();$expire=$user['expire'];$bytes=$user['data_limit'];
    if (!is_int($expire) || $expire<=0 || !is_int($bytes) || $bytes<0)
        throw new RuntimeException('pg_entitlement_not_finite');
    $whole=static function($raw,int $max,bool $zero=false): int {
        if ((!is_int($raw) && (!is_string($raw) || preg_match('/^(0|[1-9][0-9]*)$/D',$raw)!==1)) ||
            filter_var($raw,FILTER_VALIDATE_INT)===false || (int)$raw<($zero?0:1) || (int)$raw>$max)
            throw new RuntimeException('pg_edit_units_invalid');
        return (int)$raw;
    };
    $out=[];
    if (isset($change['days'])) $out['expire']=$now+86400*$whole($change['days'],36500);
    elseif (isset($change['plus_day'])) $out['expire']=max($expire,$now)+86400*$whole($change['plus_day'],36500);
    else $out['expire']=$expire;
    if ($out['expire']>2147483647) throw new RuntimeException('pg_edit_expire_overflow');
    if (isset($change['volume'])) $out['data_limit']=1073741824*$whole($change['volume'],intdiv(PHP_INT_MAX,1073741824),true);
    elseif (isset($change['plus_volume'])) {
        if ($bytes===0) throw new RuntimeException('pg_unlimited_volume_extension');
        $extra=1073741824*$whole($change['plus_volume'],intdiv(PHP_INT_MAX,1073741824));
        if ($bytes>PHP_INT_MAX-$extra) throw new RuntimeException('pg_edit_volume_overflow');
        $out['data_limit']=$bytes+$extra;
    } else $out['data_limit']=$bytes;
    if ($user['status']==='disabled') $out['status']='disabled';
    elseif (in_array($user['status'],['expired','limited'],true)) $out['status']='active';
    return $out;
}
function achbotPgServiceEdit(mysqli $db,int $serverId,array $change,?array $giftTarget=null): object {
    try {
        $ctx=achbotPgRemarkContext($db,$serverId,$change['remark']);
        if ($giftTarget!==null && !achbotGiftTargetMatches($db,$serverId,$giftTarget))
            throw new RuntimeException('pg_gift_target_changed');
        if ((int)($change['pay_id']??0)>0) achbotPgQuote($db,(int)$change['pay_id']);
        $key=achbotPgOperationKey('edit',$change);
        // Own the durable account fence BEFORE observing limits. A competing
        // completed edit is then included in this request's absolute arithmetic.
        $intent=achbotPgBeginMutation($db,$ctx,$key,'edit',['phase'=>'claimed']);
        $read=achbotPgRead($ctx['panel'],$ctx['identity']);
        if (($read['success']??false)!==true) throw new RuntimeException('pg_edit_read_unverified');
        $absolute=achbotPgEditChanges($read['user'],$change);
        $intent=achbotPgPersistMutationIntent($db,$ctx,$intent,[
            'absolute'=>$absolute,'reset_usage'=>isset($change['volume']),
            'before'=>array_intersect_key($read['user'],array_flip(['data_limit','expire','status','group_ids','used_traffic']))]);
        if (isset($change['volume'])) {
            $reset=achbotPgReset($ctx['panel'],$ctx['identity']);
            if (($reset['success']??false)!==true) throw new RuntimeException('pg_reset_requires_review');
        }
        $r=achbotPgModify($ctx['panel'],$ctx['identity'],$absolute);
        if (($r['success']??false)!==true || $r['user']['group_ids']!==$read['user']['group_ids'])
            throw new RuntimeException('pg_edit_readback_requires_review');
        achbotPgFinishMutation($db,$ctx,$intent,$r);
        return (object)['success'=>true,'verified'=>true,'applied_expire'=>$r['user']['expire'],
            'applied_bytes'=>$r['user']['data_limit']];
    } catch (Throwable $error) {return achbotProviderFailure('pg_edit_requires_review');}
}
function achbotProviderRelocationTargets(mysqli $db,int $sourceId): array {
    if (achbotProviderType($db,$sourceId)==='marzban') return achbotMarzbanRelocationTargets($db,$sourceId);
    try {
        $panel=achbotPgServerPanel($db,$sourceId);
        $s=$db->prepare("SELECT i.id,i.title FROM server_info i JOIN server_config c ON c.id=i.id WHERE i.provider_panel_id=? AND i.id<>? AND c.type='pasarguard' AND i.active=1 AND i.state=1 AND i.ucount>0 ORDER BY i.id");
        $pid=(int)$panel['id'];$s->bind_param('ii',$pid,$sourceId);$s->execute();$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
        return array_values(array_filter($rows,fn($r)=>achbotSameProviderPanel($db,$sourceId,(int)$r['id'])));
    } catch (Throwable $error) {return [];}
}
function achbotReassignLogicalServer(mysqli $db,int $orderId,int $targetId,int $actor,bool $adminAllowed=false): ?array {
    $s=$db->prepare('SELECT * FROM orders_list WHERE id=? AND status=1');$s->bind_param('i',$orderId);$s->execute();$o=$s->get_result()->fetch_assoc();$s->close();
    if (!$o || (!$adminAllowed && (int)$o['userid']!==$actor)) return null;
    if (achbotProviderType($db,(int)$o['server_id'])==='marzban')
        return achbotReassignMarzbanLogicalServer($db,$orderId,$targetId,$actor,$adminAllowed);
    try {
        $old=(int)$o['server_id'];$ctx=achbotPgOrderContext($db,$orderId);
        if ($old===$targetId || !achbotSameProviderPanel($db,$old,$targetId)) throw new RuntimeException('pg_switch_panel_mismatch');
        $targets=achbotProviderRelocationTargets($db,$old);
        $target=null;foreach ($targets as $row) if ((int)$row['id']===$targetId) $target=$row;
        if ($target===null) throw new RuntimeException('pg_switch_target_ineligible');
        $snap=achbotPgPlanSnapshot($db,$targetId,0); // explicit logical switch adopts destination defaults
        $key=achbotPgOperationKey('switch');
        $intent=achbotPgBeginMutation($db,$ctx,$key,'switch',['server_id'=>$targetId,'group_ids'=>$snap['group_ids']]);
        $r=achbotPgModify($ctx['panel'],$ctx['identity'],['group_ids'=>$snap['group_ids']]);
        if (($r['success']??false)!==true) throw new RuntimeException('pg_switch_requires_review');
        if (!$db->begin_transaction()) throw new RuntimeException('pg_switch_finish_begin');
        try {
            if (!achbotSameProviderPanel($db,$old,$targetId) || achbotPgPlanSnapshot($db,$targetId,0)!==$snap)
                throw new RuntimeException('pg_switch_target_changed');
            $s=$db->prepare('UPDATE orders_list SET server_id=? WHERE id=? AND server_id=? AND userid=? AND status=1');
            $uid=(int)$o['userid'];$s->bind_param('iiii',$targetId,$orderId,$old,$uid);
            if (!$s->execute() || $s->affected_rows!==1) throw new RuntimeException('pg_switch_local_unconfirmed');$s->close();
            achbotPgFinishMutation($db,$ctx,$intent,$r);
            if (!$db->commit()) throw new RuntimeException('pg_switch_commit_unknown');
        } catch (Throwable $error) {try{$db->rollback();}catch(Throwable $ignored){}throw $error;}
        return ['id'=>$orderId,'userid'=>$o['userid'],'remark'=>$o['remark'],'title'=>$target['title'],
            'source_id'=>$old,'target_id'=>$targetId,'provider'=>'pasarguard'];
    } catch (Throwable $error) {return null;}
}

/** PG gifts operate only on the bot's exact owned identities; never a vendor username search. */
function achbotProviderGiftUsers(mysqli $db,int $serverId,array $target): object {
    if (achbotProviderType($db,$serverId)==='marzban') return getMarzbanJson($serverId,null,$target);
    try {
        if (!achbotGiftTargetMatches($db,$serverId,$target)) throw new RuntimeException('pg_gift_target_changed');
        $s=$db->prepare("SELECT o.id,o.remark,a.state FROM orders_list o LEFT JOIN provider_accounts a ON a.order_id=o.id WHERE o.server_id=? AND o.status=1 ORDER BY o.id");
        $s->bind_param('i',$serverId);$s->execute();$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
        foreach ($rows as $row) if (($row['state']??null)!=='linked') throw new RuntimeException('pg_gift_roster_account_requires_review');
        $users=[];foreach ($rows as $row) {
            $ctx=achbotPgOrderContext($db,(int)$row['id']);$r=achbotPgRead($ctx['panel'],$ctx['identity']);
            if (($r['success']??false)!==true) throw new RuntimeException('pg_gift_list_unverified');
            $users[]=(object)$r['user'];
        }
        return (object)['users'=>$users,'total'=>count($users)];
    } catch (Throwable $error) {return achbotProviderFailure('pg_gift_list_requires_review');}
}

/** The same native presentation policy is used for details and both QR routes. */
function achbotOrderPresentation(mysqli $db,array $order,array $server,array $state): array {
    $type=(string)($server['type']??'');$native=['links'=>[],'subscription_url'=>'','backup_urls'=>[]];
    if ($type==='marzban') {
        $native['links']=json_decode((string)$order['link'],true)?:[];
        if ((string)$order['token']!=='')
            $native['subscription_url']=rtrim((string)$server['panel_url'],'/').'/sub/'.$order['token'];
        foreach (array_filter(array_map('trim',explode(',',(string)($server['sub_links']??'')))) as $domain)
            $native['backup_urls'][]=rtrim($domain,'/').'/sub/'.$order['token'];
    } elseif ($type==='pasarguard') {
        try {
            $ctx=achbotPgOrderContext($db,(int)$order['id']);
            $r=achbotPgRead($ctx['panel'],$ctx['identity']);
            if (($r['success']??false)!==true) throw new RuntimeException('pg_presentation_read_unverified');
            $native['subscription_url']=$r['user']['subscription_url'];
            $links=achbotPgLinks($ctx['panel'],$ctx['identity']);
            if (($links['success']??false)===true) $native['links']=$links['links'];
        } catch (Throwable $error) {}
    }
    return buildSubscriptionPresentation($type,$native,$state,[],'view',(int)$order['server_id']);
}
