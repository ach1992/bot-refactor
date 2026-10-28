<?php
/** AChBot current provider dispatch. Native identities never use legacy UUID/inbound fields. */
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) { http_response_code(404); exit; }
require_once __DIR__.'/ProviderImport.php';

function achbotProviderType(mysqli $db,int $serverId): string {
    $s=$db->prepare('SELECT type FROM server_config WHERE id=?');
    if (!$s) return '';
    $s->bind_param('i',$serverId);$ok=$s->execute();
    $r=$ok?$s->get_result()->fetch_assoc():null;$s->close();
    return (string)($r['type']??'');
}
function achbotSupportsAccount($type,$inbound): bool {
    return achbotSupportsProviderType($type) && (int)$inbound===0;
}
function achbotSupportsPlan($db,$planId,$inbound=0,$serverId=null,bool $lock=false): bool {
    if (!($db instanceof mysqli) || (int)$planId<=0 || (int)$inbound!==0) return false;
    try {
        $s=$db->prepare('SELECT c.type FROM server_plans p JOIN server_config c ON c.id=p.server_id WHERE p.id=?');
        $s->bind_param('i',$planId);$s->execute();$row=$s->get_result()->fetch_assoc();$s->close();
        $adapter=achbotProviderDefinition($row['type']??null);
        return $adapter!==null && ($adapter['plan'])($db,$planId,$inbound,$serverId,$lock)===true;
    } catch (Throwable $error) {return false;}
}
function achbotPasarGuardSupportsPlan($db,$planId,$inbound=0,$serverId=null,bool $lock=false): bool {
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
    if (!($db instanceof mysqli) || (int)$orderId<=0) return false;
    try {
        $s=$db->prepare('SELECT c.type FROM orders_list o JOIN server_config c ON c.id=o.server_id WHERE o.id=?');
        $s->bind_param('i',$orderId);$s->execute();$row=$s->get_result()->fetch_assoc();$s->close();
        $adapter=achbotProviderDefinition($row['type']??null);
        return $adapter!==null && ($adapter['order'])($db,$orderId,$planId,$userId,$lock)===true;
    } catch (Throwable $error) {return false;}
}
function achbotPasarGuardSupportsOrder($db,$orderId,$planId=null,$userId=null,bool $lock=false): bool {
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
    try {
        $type=achbotProviderType($db,$a);
        if ($type!==achbotProviderType($db,$b)) return false;
        $adapter=achbotProviderDefinition($type);
        return $adapter!==null && ($adapter['same_panel'])($db,$a,$b)===true;
    } catch (Throwable $error) {return false;}
}
function achbotMarzbanSamePanelAdapter(mysqli $db,int $a,int $b): bool {
    return $a===$b || achbotSameMarzbanPanel($db,$a,$b);
}
function achbotPasarGuardSamePanelAdapter(mysqli $db,int $a,int $b): bool {
    try {
        $pa=achbotPgServerPanel($db,$a);$pb=achbotPgServerPanel($db,$b);
        return $pa['id']===$pb['id'] && $pa['instance_key']===$pb['instance_key'] && $pa['api_url']===$pb['api_url'];
    } catch (Throwable $error) {return false;}
}
function achbotProviderFailure(string $reason,bool $uncertain=true): object {
    return (object)['success'=>false,'uncertain'=>$uncertain,'msg'=>$reason];
}
function achbotProviderUser($serverId,$remark,bool $withLinks=true): object {
    global $connection;
    $adapter=achbotProviderDefinition(achbotProviderType($connection,(int)$serverId));
    if ($adapter===null) return achbotProviderFailure('unsupported_provider',false);
    return ($adapter['user'])($serverId,$remark,$withLinks);
}
function achbotMarzbanUserAdapter($serverId,$remark,bool $withLinks=true,bool $strictRender=false): object {
    return getMarzbanUser($serverId,$remark,null,!$withLinks || $strictRender);
}
function achbotPasarGuardUserAdapter($serverId,$remark,bool $withLinks=true): object {
    global $connection;
    try {
        $ctx=achbotPgRemarkContext($connection,(int)$serverId,(string)$remark);
        $links=$withLinks?achbotPgLinks($ctx['panel'],$ctx['identity']):null;
        // A successful links proof already contains the final exact-identity read.
        $r=$withLinks && ($links['success']??false)===true?$links:
            achbotPgRead($ctx['panel'],$ctx['identity']);
        if (($r['success']??false)!==true) return achbotProviderFailure($r['reason']??'pg_read_unverified');
        $u=$r['user'];
        $u['provider']='pasarguard';$u['provider_account_id']=$ctx['account']['id'];
        if ($withLinks) $u['links']=($links['success']??false)===true?($links['links']??[]):[];
        return (object)$u;
    } catch (Throwable $e) { return achbotProviderFailure('pg_account_identity_unverified'); }
}
/** One request-local observation for a page. It never authorizes a financial or remote effect. */
function achbotProviderRenderPacket(mysqli $db,int $serverId,string $username): object {
    try {
        $type=achbotProviderType($db,$serverId);$adapter=achbotProviderDefinition($type);
        if ($adapter===null) throw new RuntimeException('render_provider_unavailable');
        $remote=($adapter['user'])($serverId,$username,true,true);
        require_once __DIR__.'/ServiceLifecycle.php';
        if (!is_object($remote) || !achbotServiceObservation($remote,$username)['verified'])
            throw new RuntimeException('render_observation_unverified');
        $remote->provider_render_proof=['provider'=>$type,'server_id'=>$serverId,'username'=>$username];
        return $remote;
    } catch (Throwable $error) {return achbotProviderFailure('render_observation_unverified',false);}
}
function achbotProviderRenderProofValid(object $remote,array $order,string $type): bool {
    require_once __DIR__.'/ServiceLifecycle.php';
    $username=(string)($order['remark']??'');
    return ($remote->provider_render_proof??null)===['provider'=>$type,
        'server_id'=>(int)($order['server_id']??0),'username'=>$username] &&
        achbotServiceObservation($remote,$username)['verified'];
}
function achbotProviderUserInfo($serverId,$remark): object {
    global $connection;
    $adapter=achbotProviderDefinition(achbotProviderType($connection,(int)$serverId));
    return $adapter===null?achbotProviderFailure('unsupported_provider',false):
        ($adapter['user_info'])($serverId,$remark);
}
function achbotMarzbanUserInfoAdapter($serverId,$remark): object {
    return getMarzbanUserInfo($serverId,$remark);
}
function achbotPasarGuardUserInfoAdapter($serverId,$remark): object {
    return achbotPasarGuardUserAdapter($serverId,$remark);
}
/**
 * Private in-memory deletion target. Never persist or log these credentials.
 * Scheduled consumers capture it before reading the remote account; adapters
 * must refuse a changed target instead of silently reading a new endpoint.
 */
function achbotProviderDeletionTarget(mysqli $db,int $serverId,string $remark): array {
    $s=$db->prepare('SELECT type,panel_url,username,password FROM server_config WHERE id=?');
    $s->bind_param('i',$serverId);$s->execute();$row=$s->get_result()->fetch_assoc();$s->close();
    if (!$row || $serverId<=0 || $remark==='') throw new RuntimeException('delete_target_unverified');
    $adapter=achbotProviderDefinition($row['type']);
    if ($adapter===null) throw new RuntimeException('delete_target_unsupported');
    return ($adapter['deletion_target'])($db,$serverId,$remark,$row);
}
function achbotMarzbanDeletionTargetAdapter(mysqli $db,int $serverId,string $remark,array $row): array {
    return [
        'provider'=>'marzban','server_id'=>$serverId,'remark'=>$remark,
        'panel_url'=>rtrim((string)$row['panel_url'],'/'),
        'username'=>(string)$row['username'],'password'=>(string)$row['password']
    ];
}
function achbotPasarGuardDeletionTargetAdapter(mysqli $db,int $serverId,string $remark,array $row): array {
    return achbotPgDeletionContextTarget(achbotPgRemarkContext($db,$serverId,$remark),$serverId,$remark);
}
function achbotPgDeletionContextTarget(array $ctx,int $serverId,string $remark): array {
    return ['provider'=>'pasarguard','server_id'=>$serverId,'remark'=>$remark,
        'panel_id'=>(int)$ctx['panel']['id'],'instance_key'=>$ctx['panel']['instance_key'],
        'endpoint_sha256'=>hash('sha256',rtrim($ctx['panel']['api_url'],'/')),
        'credentials_sha256'=>hash('sha256',$ctx['panel']['api_key']),
        'provider_account_id'=>(int)$ctx['account']['id'],'identity'=>$ctx['identity']];
}
function achbotProviderDeletionTargetMatches(mysqli $db,int $serverId,string $remark,array $expected): bool {
    try {
        return hash_equals(json_encode($expected,JSON_THROW_ON_ERROR),
            json_encode(achbotProviderDeletionTarget($db,$serverId,$remark),JSON_THROW_ON_ERROR));
    } catch (Throwable $error) {return false;}
}

/** Read-only admission before a caller commits its own local effect fence. */
function achbotProviderActionEligible(mysqli $db,int $serverId,string $remark,string $action,?array $expectedTarget=null): bool {
    try {
        $adapter=achbotProviderDefinition(achbotProviderType($db,$serverId));
        if ($adapter===null || $serverId<=0 || $remark==='' ||
            ($expectedTarget!==null && ($action!=='delete' ||
             !achbotProviderDeletionTargetMatches($db,$serverId,$remark,$expectedTarget)))) return false;
        if (($adapter['action_preflight'])($db,$serverId,$remark,$action,$expectedTarget)!==true) return false;
        return $expectedTarget===null || achbotProviderDeletionTargetMatches($db,$serverId,$remark,$expectedTarget);
    } catch (Throwable $error) {return false;}
}
function achbotMarzbanActionPreflightAdapter(mysqli $db,int $serverId,string $remark,string $action,?array $expectedTarget=null): bool {
    // Marzban retains its existing native action eligibility and target proof.
    return in_array($action,['delete','revoke','toggle'],true);
}
/** Shared by outer read-only admission and the native adapter before its claim. */
function achbotPgActionPreflight(mysqli $db,int $serverId,string $remark,string $action,?array $expectedTarget=null): array {
    if (!in_array($action,['delete','revoke','reset','toggle'],true)) throw new RuntimeException('unsupported_action');
    $ctx=achbotPgRemarkContext($db,$serverId,$remark,true);
    $key=$action==='delete'?'delete:'.(int)$ctx['account']['id']:achbotPgOperationKey($action);
    achbotPgMutationAdmission($ctx['account'],$key);
    if ($expectedTarget!==null && $expectedTarget!==achbotPgDeletionContextTarget($ctx,$serverId,$remark))
        throw new RuntimeException('delete_target_changed');
    $preflight=achbotPgRead($ctx['panel'],$ctx['identity']);
    if (($preflight['success']??false)!==true) throw new RuntimeException('pg_action_read_unverified');
    if (!achbotPgManaged($preflight['user'])) throw new RuntimeException('pg_native_schedule_conflict');
    if ($action==='toggle' && !in_array($preflight['user']['status'],['active','disabled'],true))
        throw new RuntimeException('pg_status_restricted');
    return ['context'=>$ctx,'key'=>$key];
}
function achbotPasarGuardActionPreflightAdapter(mysqli $db,int $serverId,string $remark,string $action,?array $expectedTarget=null): bool {
    achbotPgActionPreflight($db,$serverId,$remark,$action,$expectedTarget);return true;
}

function achbotProviderAction($serverId,$remark,string $action,?array $expectedTarget=null): object {
    global $connection;
    $adapter=achbotProviderDefinition(achbotProviderType($connection,(int)$serverId));
    if ($adapter===null) return achbotProviderFailure('unsupported_provider',false);
    if ($expectedTarget!==null && ($action!=='delete' ||
        !achbotProviderDeletionTargetMatches($connection,(int)$serverId,(string)$remark,$expectedTarget)))
        return achbotProviderFailure('delete_target_changed');
    return ($adapter['action'])($serverId,$remark,$action,$expectedTarget);
}

/** Canonical JSON keeps native option order from becoming a false configuration change. */
function achbotProviderCanonicalValue($value) {
    if (is_object($value)) {$value=(array)$value;ksort($value,SORT_STRING);
        foreach ($value as $k=>$v) $value[$k]=achbotProviderCanonicalValue($v);return $value;}
    if (is_array($value)) {if (!array_is_list($value)) ksort($value,SORT_STRING);
        foreach ($value as $k=>$v) $value[$k]=achbotProviderCanonicalValue($v);}
    return $value;
}
function achbotMarzbanRevokeSnapshot(object $remote,string $remark,string $panelUrl): array {
    require_once __DIR__.'/ServiceLifecycle.php';
    if (!achbotServiceObservation($remote,$remark)['verified'] ||
        !is_string($remote->created_at??null) ||
        !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})?$/D',$remote->created_at) ||
        !is_object($remote->proxies??null) || !(is_array($remote->inbounds??null)||is_object($remote->inbounds??null)) ||
        !is_string($remote->data_limit_reset_strategy??null) || !is_array($remote->links??null) ||
        !is_string($remote->subscription_url??null)) throw new RuntimeException('marzban_revoke_schema_invalid');
    $date=new DateTimeImmutable($remote->created_at,new DateTimeZone('UTC'));
    $errors=DateTimeImmutable::getLastErrors();
    if ($errors!==false && ($errors['warning_count'] || $errors['error_count']))
        throw new RuntimeException('marzban_revoke_identity_invalid');
    $url=$remote->subscription_url;
    if (str_starts_with($url,'/')) $url=rtrim($panelUrl,'/').$url;
    if (!achbotProviderImportParts($url)) throw new RuntimeException('marzban_revoke_subscription_invalid');
    $identities=[];$options=[];$keys=['vmess'=>'id','vless'=>'id','trojan'=>'password','shadowsocks'=>'password'];
    foreach ($remote->proxies as $protocol=>$settings) {
        $key=$keys[$protocol]??null;
        if ($key===null || !is_object($settings) || !is_string($settings->{$key}??null) ||
            $settings->{$key}==='' || strlen($settings->{$key})>1024 ||
            preg_match('/[\x00-\x20\x7f]/',$settings->{$key}))
            throw new RuntimeException('marzban_revoke_proxy_invalid');
        $credential=$settings->{$key};
        if ($key==='id') {
            if (!preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/iD',$credential))
                throw new RuntimeException('marzban_revoke_proxy_invalid');
            $credential=strtolower($credential);
        }
        $identities[$protocol]=$credential;$option=(array)$settings;unset($option[$key]);
        $options[$protocol]=achbotProviderCanonicalValue((object)$option);
    }
    if (!$identities) throw new RuntimeException('marzban_revoke_proxy_missing');
    ksort($identities,SORT_STRING);ksort($options,SORT_STRING);
    foreach ($remote->links as $uri)
        if (!is_string($uri) || !preg_match('#^[a-z][a-z0-9+.-]*://[^\x00-\x20\x7f]+$#iD',$uri))
            throw new RuntimeException('marzban_revoke_links_invalid');
    $configuration=[];
    foreach (['data_limit','expire','inbounds','data_limit_reset_strategy','note','next_plan',
        'on_hold_expire_duration','on_hold_timeout'] as $field)
        $configuration[$field]=achbotProviderCanonicalValue($remote->{$field}??null);
    $configuration['status_class']=in_array($remote->status,['active','expired','limited'],true)?'eligible':$remote->status;
    return ['created_at'=>$date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
        'identities'=>$identities,'options'=>$options,'configuration'=>$configuration];
}
/** Current action contract: strict acknowledgement plus changed native credentials and exact readback. */
function achbotMarzbanRevokeVerified(mysqli $db,int $serverId,string $remark): object {
    $dispatched=false;
    try {
        $target=achbotProviderDeletionTarget($db,$serverId,$remark);
        if (($target['provider']??null)!=='marzban' || !preg_match('/^[a-zA-Z0-9_@.-]{1,100}$/D',$remark))
            throw new RuntimeException('marzban_revoke_target_invalid');
        $url=$target['panel_url'];$token=getMarzbanToken($serverId,$url,$target);
        if (!is_object($token) || !is_string($token->access_token??null) || $token->access_token==='' ||
            strlen($token->access_token)>8192 || preg_match('/[\x00-\x20\x7f]/',$token->access_token))
            throw new RuntimeException('marzban_revoke_auth_unverified');
        $before=getMarzbanUser($serverId,$remark,$token,true,$url,$target);
        if (!is_object($before)) throw new RuntimeException('marzban_revoke_read_unverified');
        $baseline=achbotMarzbanRevokeSnapshot($before,$remark,$url);
        if (!achbotProviderDeletionTargetMatches($db,$serverId,$remark,$target))
            throw new RuntimeException('marzban_revoke_target_changed');
        $ch=curl_init($url.'/api/user/'.rawurlencode($remark).'/revoke_sub');$wire='';$large=false;
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER=>['Accept: application/json','Authorization: Bearer '.$token->access_token],
            CURLOPT_WRITEFUNCTION=>static function($curl,string $chunk) use (&$wire,&$large):int {
                if (strlen($wire)+strlen($chunk)>1048576) {$large=true;return 0;}
                $wire.=$chunk;return strlen($chunk);
            }]);
        $dispatched=true;$ok=curl_exec($ch);$errno=curl_errno($ch);
        $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$content=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);curl_close($ch);
        if ($ok===false || $errno!==0 || $large || $http!==200 ||
            !preg_match('#^application/(?:[a-z0-9.+-]+\\+)?json(?:;|$)#i',$content))
            throw new RuntimeException('marzban_revoke_ack_unverified');
        $ack=json_decode($wire,false,64,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);
        if (!is_object($ack)) throw new RuntimeException('marzban_revoke_ack_invalid');
        $acknowledged=achbotMarzbanRevokeSnapshot($ack,$remark,$url);
        $after=getMarzbanUser($serverId,$remark,$token,true,$url,$target);
        if (!is_object($after) || !achbotProviderDeletionTargetMatches($db,$serverId,$remark,$target))
            throw new RuntimeException('marzban_revoke_readback_unverified');
        $confirmed=achbotMarzbanRevokeSnapshot($after,$remark,$url);
        foreach (['created_at','options','configuration'] as $field)
            if ($baseline[$field]!==$acknowledged[$field] || $baseline[$field]!==$confirmed[$field])
                throw new RuntimeException('marzban_revoke_account_changed');
        if ($acknowledged['identities']!==$confirmed['identities'] ||
            array_keys($baseline['identities'])!==array_keys($confirmed['identities']))
            throw new RuntimeException('marzban_revoke_identity_unconfirmed');
        foreach ($baseline['identities'] as $protocol=>$old)
            if (hash_equals($old,$confirmed['identities'][$protocol]))
                throw new RuntimeException('marzban_revoke_rotation_unconfirmed');
        // Marzban issues username+ceil(now) tokens on every model read. Equal same-second
        // tokens are legitimate; actual native proxy rotation and readback are the proof.
        $after->success=true;$after->verified=true;return $after;
    } catch (Throwable $error) {return achbotProviderFailure('marzban_revoke_unverified',$dispatched);}
}

function achbotMarzbanActionAdapter($serverId,$remark,string $action,?array $expectedTarget=null): object {
    return match($action) {
        'delete'=>deleteMarzban($serverId,$remark,$expectedTarget),
        'revoke'=>achbotMarzbanRevokeVerified($GLOBALS['connection'],(int)$serverId,(string)$remark),
        'toggle'=>changeMarzbanState($serverId,$remark),
        default=>achbotProviderFailure('unsupported_action',false)
    };
}
function achbotPasarGuardActionAdapter($serverId,$remark,string $action,?array $expectedTarget=null): object {
    global $connection;
    try {
        $admission=achbotPgActionPreflight($connection,(int)$serverId,(string)$remark,$action,$expectedTarget);
        $ctx=$admission['context'];$key=$admission['key'];
        $panel=$ctx['panel'];$identity=$ctx['identity'];
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

function achbotProviderDelete($serverId,$remark,?array $expectedTarget=null): object { return achbotProviderAction($serverId,$remark,'delete',$expectedTarget); }
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
    $s=$db->prepare('SELECT o.*,c.type AS provider_type FROM orders_list o JOIN server_config c ON c.id=o.server_id WHERE o.id=?'.($lock?' FOR UPDATE':''));
    $s->bind_param('i',$orderId);$s->execute();$o=$s->get_result()->fetch_assoc();$s->close();
    if (!$o || $o['provider_type']!=='pasarguard') throw new RuntimeException('pg_order_identity_changed');
    $a=achbotProviderAccountByOrder($db,$orderId,$lock);
    if (!$a || !in_array($a['state'],['verified','linked'],true) || (int)$a['remote_user_id']<=0 || empty($a['remote_created_at'])) throw new RuntimeException('pg_account_not_verified');
    if ($o['remark']!==$a['remote_username']) throw new RuntimeException('pg_order_identity_changed');
    $panel=achbotPgServerPanel($db,(int)$o['server_id']);
    if ((int)$panel['id']!==(int)$a['panel_id'] || $panel['instance_key']!==$a['instance_key'] || hash('sha256',rtrim($panel['api_url'],'/'))!==$a['endpoint_sha256']) throw new RuntimeException('pg_account_panel_changed');
    return ['order_id'=>$orderId,'order'=>$o,'order_server_id'=>(int)$o['server_id'],'account'=>$a,'panel'=>$panel,'identity'=>achbotProviderAccountIdentity($db,$a,$lock)];
}
function achbotPgRemarkContext(mysqli $db,int $serverId,string $remark,bool $lock=false): array {
    // Multiple authorized local references may name the same exact physical
    // identity. Every selected reference must prove that same identity.
    $s=$db->prepare('SELECT id FROM orders_list WHERE server_id=? AND BINARY remark=BINARY ? ORDER BY id'.($lock?' LOCK IN SHARE MODE':''));
    $s->bind_param('is',$serverId,$remark);$s->execute();$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
    if (!$rows) throw new RuntimeException('pg_local_account_ambiguous');
    $context=null;
    foreach ($rows as $row) {
        $candidate=achbotPgOrderContext($db,(int)$row['id'],$lock);
        if ($context!==null && ($candidate['account']['id']!==$context['account']['id'] ||
            $candidate['identity']!==$context['identity'])) throw new RuntimeException('pg_local_account_ambiguous');
        $context=$candidate;
    }
    return $context;
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
        $snapshot=array_merge($snapshot,$ctx['identity']);
    }
    $json=json_encode($snapshot,JSON_THROW_ON_ERROR);
    $s=$db->prepare('UPDATE pays SET provider_snapshot=? WHERE id=? AND provider_snapshot IS NULL');
    $s->bind_param('si',$json,$payId);
    if (!$s->execute() || $s->affected_rows!==1) throw new RuntimeException('pg_quote_pin_unconfirmed');$s->close();
}
function achbotPgQuote(mysqli $db,int $payId,bool $lock=false): array {
    achbotPgLoad();
    $s=$db->prepare('SELECT provider_snapshot,server_id,plan_id,type,order_id FROM pays WHERE id=?'.($lock?' FOR UPDATE':''));$s->bind_param('i',$payId);$s->execute();$p=$s->get_result()->fetch_assoc();$s->close();
    $snap=json_decode((string)($p['provider_snapshot']??''),true,64,JSON_THROW_ON_ERROR);
    if (!is_array($snap) || ($snap['provider']??'')!=='pasarguard') throw new RuntimeException('pg_quote_snapshot_missing');
    $panel=achbotProviderPanelForSnapshot($db,$snap);$current=achbotPgServerPanel($db,(int)$p['server_id']);
    if ($current['id']!==$panel['id'] || $current['instance_key']!==$panel['instance_key']) throw new RuntimeException('pg_quote_panel_changed');
    if ($p['type']!=='BUY_SUB') {
        $oid=(int)$p['order_id'];
        if ($oid<=0 && preg_match('/^INCREASE_(?:DAY|VOLUME)_([1-9][0-9]*)_/',(string)$p['type'],$m)) $oid=(int)$m[1];
        $ctx=achbotPgOrderContext($db,$oid,$lock);
        foreach ($ctx['identity'] as $key=>$identityValue)
            if ((string)($snap[$key]??'')!==(string)$identityValue) throw new RuntimeException('pg_quote_account_changed');
        $history=achbotPgMutationHistory($ctx['account']);
        // A quote may still describe an already consumed payment; only a new
        // mutation must append a key, and admission below the claim remains final.
        if (count($history['consumed_keys'])>=4096 && !in_array('pay:'.$payId,$history['consumed_keys'],true))
            throw new RuntimeException('pg_account_mutation_requires_review');
        // The same existing native eligibility guard runs before a wallet claim,
        // while the native mutation still reobserves after its durable fence.
        $preflight=achbotPgRead($ctx['panel'],$ctx['identity']);
        if (($preflight['success']??false)!==true) throw new RuntimeException('pg_quote_read_unverified');
        if (!achbotPgManaged($preflight['user'])) throw new RuntimeException('pg_native_schedule_conflict');
    }
    return $snap;
}
function achbotEffectTargetIdentity(mysqli $db,int $serverId,?int $planId,?int $payId,array $row): array {
    $type=$row['panel_type']??$row['type']??null;
    $adapter=achbotProviderDefinition($type);
    if ($adapter===null) throw new RuntimeException('unsupported_effect_provider');
    $target=($adapter['target'])($db,$serverId,$planId,$payId,$row);
    if (!is_array($target) || !array_key_exists('group',$target) ||
        ($target['group']!==null && (!is_int($target['group']) || $target['group']<=0)) ||
        !is_string($target['endpoint']??null) || !preg_match('/^[a-f0-9]{64}$/D',$target['endpoint']) ||
        !is_string($target['credentials']??null) || !preg_match('/^[a-f0-9]{64}$/D',$target['credentials']) ||
        !is_string($target['key']??null) || $target['key']==='')
        throw new RuntimeException('effect_adapter_identity_invalid');
    $target['provider']=$type;
    return $target;
}
function achbotMarzbanEffectTargetAdapter(mysqli $db,int $serverId,?int $planId,?int $payId,array $row): array {
    return [
        'group'=>$row['panel_group_id']===null?null:(int)$row['panel_group_id'],
        'endpoint'=>hash('sha256',rtrim($row['panel_url'],'/')),
        'credentials'=>hash('sha256',json_encode([(string)($row['panel_username']??$row['username']??''),(string)($row['panel_password']??$row['password']??'')],JSON_THROW_ON_ERROR)),
        'key'=>$row['panel_group_id']===null?'server:'.$serverId:'group:'.(int)$row['panel_group_id']
    ];
}
function achbotPasarGuardEffectTargetAdapter(mysqli $db,int $serverId,?int $planId,?int $payId,array $row): array {
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
    $identity=achbotProviderAccountIdentity($db,achbotProviderAccountByEffect($db,$effectId));
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
function achbotPgValidMutationKey($key): bool {
    return is_string($key) && preg_match('/^[a-zA-Z0-9:_-]{1,100}$/D',$key)===1;
}
function achbotPgOperationKey(string $action,array $change=[]): string {
    if (achbotPgValidMutationKey($change['operation_key']??null)) return $change['operation_key'];
    if ((int)($change['pay_id']??0)>0) return 'pay:'.(int)$change['pay_id'];
    $update=$GLOBALS['update']??null;
    if (is_object($update) && is_int($update->update_id??null) && $update->update_id>0)
        return 'telegram:'.$update->update_id.':'.$action;
    throw new RuntimeException('pg_mutation_source_missing');
}
/** Decode the existing replay authority without repairing malformed history. */
function achbotPgMutationHistory(array $account): array {
    if (($account['state']??null)!=='linked') throw new RuntimeException('pg_account_mutation_requires_review');
    if (!array_key_exists('mutation_key',$account) || !array_key_exists('mutation_snapshot',$account))
        throw new RuntimeException('pg_mutation_history_invalid');
    $lastKey=$account['mutation_key'];$snapshot=$account['mutation_snapshot'];$consumed=[];
    if ($lastKey!==null || $snapshot!==null) {
        if (!achbotPgValidMutationKey($lastKey) || !is_string($snapshot))
            throw new RuntimeException('pg_mutation_history_invalid');
        try {$decoded=json_decode($snapshot,false,64,JSON_THROW_ON_ERROR);}
        catch (Throwable $error) {throw new RuntimeException('pg_mutation_history_invalid');}
        if (!($decoded instanceof stdClass)) throw new RuntimeException('pg_mutation_history_invalid');
        $prior=(array)$decoded;
        if (($prior['version']??null)!==1 ||
            ($prior['key']??null)!==$lastKey || !is_array($prior['consumed_keys']??null) ||
            !array_is_list($prior['consumed_keys']) || count($prior['consumed_keys'])<1 ||
            count($prior['consumed_keys'])>4096)
            throw new RuntimeException('pg_mutation_history_invalid');
        $seen=[];
        foreach ($prior['consumed_keys'] as $consumedKey) {
            if (!achbotPgValidMutationKey($consumedKey) || isset($seen[$consumedKey]))
                throw new RuntimeException('pg_mutation_history_invalid');
            $seen[$consumedKey]=true;
        }
        if (!isset($seen[$lastKey])) throw new RuntimeException('pg_mutation_history_invalid');
        $consumed=$prior['consumed_keys'];
    }
    return ['last_key'=>$lastKey,'consumed_keys'=>$consumed];
}
/** The same admission check runs before HTTP and again under the exclusive claim lock. */
function achbotPgMutationAdmission(array $account,string $key): array {
    if (!achbotPgValidMutationKey($key)) throw new RuntimeException('pg_mutation_source_invalid');
    $history=achbotPgMutationHistory($account);$consumed=$history['consumed_keys'];
    if ($history['last_key']===$key || in_array($key,$consumed,true) || count($consumed)>=4096)
        throw new RuntimeException('pg_account_mutation_requires_review');
    return $consumed;
}

/** One durable account fence spans reset+modify and survives lost responses. */
function achbotPgBeginMutation(mysqli $db,array $ctx,string $key,string $action,array $changes): array {
    if (!achbotPgValidMutationKey($key)) throw new RuntimeException('pg_mutation_source_invalid');
    $tx=$db->query('SELECT @@in_transaction AS tx');
    if (!$tx || (int)($tx->fetch_assoc()['tx']??1)!==0) throw new RuntimeException('pg_mutation_requires_autocommit');
    if (!$db->begin_transaction()) throw new RuntimeException('pg_mutation_begin');
    try {
        $id=(int)$ctx['account']['id'];
        $s=$db->prepare('SELECT state,mutation_key,mutation_snapshot FROM provider_accounts WHERE id=? FOR UPDATE');
        $s->bind_param('i',$id);$s->execute();$a=$s->get_result()->fetch_assoc();$s->close();
        if (!$a) throw new RuntimeException('pg_account_mutation_requires_review');
        $consumed=achbotPgMutationAdmission($a,$key);
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
/** Existing shared accounting stores whole days/GiB; never round a quote. */
function achbotWholeServiceUnits($raw,int $max,bool $allowZero=false): ?int {
    if (!(is_int($raw)||is_float($raw)||is_string($raw)) || !is_numeric($raw)) return null;
    $number=(float)$raw;
    if (!is_finite($number) || $number!==floor($number) ||
        $number<($allowZero?0:1) || $number>$max) return null;
    return (int)$number;
}
function achbotServiceVolumeMax(): int {return min(2147483647,intdiv(PHP_INT_MAX,1073741824));}
function achbotPinnedAddonUnits(array $pay): ?int {
    if (preg_match('/^INCREASE_(DAY|VOLUME)_([1-9][0-9]*)_([1-9][0-9]*)$/D',(string)($pay['type']??''),$m)!==1) return null;
    $field=$m[1]==='DAY'?'day':'volume';$other=$field==='day'?'volume':'day';
    if (achbotWholeServiceUnits($pay[$other]??null,2147483647,true)!==0) return null;
    return achbotWholeServiceUnits($pay[$field]??null,$field==='day'?36500:achbotServiceVolumeMax());
}
function achbotServiceEditMatchesPay(mysqli $db,int $payId,array $change): bool {
    $s=$db->prepare('SELECT type,volume,day FROM pays WHERE id=?');
    if (!$s) return false;
    $s->bind_param('i',$payId);$ok=$s->execute();$pay=$ok?$s->get_result()->fetch_assoc():null;$s->close();
    if (!$pay) return false;
    if (preg_match('/^INCREASE_(DAY|VOLUME)_/',(string)$pay['type'],$m)!==1) return true;
    $units=achbotPinnedAddonUnits($pay);
    $field=$m[1]==='DAY'?'plus_day':'plus_volume';
    $seen=array_intersect(array_keys($change),['days','plus_day','volume','plus_volume']);
    return $units!==null && count($seen)===1 &&
        achbotWholeServiceUnits($change[$field]??null,$field==='plus_day'?36500:achbotServiceVolumeMax())===$units;
}
/** Pure validation runs before an account claim or provider GET. */
function achbotPgEditInputUnits(array $change): array {
    $units=[];
    foreach (['days','plus_day','volume','plus_volume'] as $field) {
        if (!array_key_exists($field,$change)) continue;
        $days=in_array($field,['days','plus_day'],true);
        $value=achbotWholeServiceUnits($change[$field],$days?36500:achbotServiceVolumeMax(),$field==='volume');
        if ($value===null) throw new RuntimeException('pg_edit_units_invalid');
        $units[$field]=$value;
    }
    if (!$units || isset($units['days'],$units['plus_day']) || isset($units['volume'],$units['plus_volume']))
        throw new RuntimeException('pg_edit_units_invalid');
    return $units;
}
/** Pure absolute entitlement calculation; provider Groups are never replaced on renewal. */
function achbotPgEditChanges(array $user,array $change,?int $clock=null): array {
    $now=$clock??time();$expire=$user['expire'];$bytes=$user['data_limit'];
    $units=achbotPgEditInputUnits($change);
    // A full positive-days replacement establishes a finite new expiry even
    // when the current native account has unlimited time. Extensions still
    // require an existing finite expiry; malformed/zero expiry is not NULL.
    if (($expire===null && !isset($units['days'])) ||
        ($expire!==null && (!is_int($expire) || $expire<=0)) ||
        !is_int($bytes) || $bytes<0)
        throw new RuntimeException('pg_entitlement_not_finite');
    $out=[];
    if (isset($change['days'])) $out['expire']=$now+86400*$units['days'];
    elseif (isset($change['plus_day'])) $out['expire']=max($expire,$now)+86400*$units['plus_day'];
    else $out['expire']=$expire;
    if ($out['expire']>2147483647) throw new RuntimeException('pg_edit_expire_overflow');
    if (isset($change['volume'])) $out['data_limit']=1073741824*$units['volume'];
    elseif (isset($change['plus_volume'])) {
        if ($bytes===0) throw new RuntimeException('pg_unlimited_volume_extension');
        $extra=1073741824*$units['plus_volume'];
        if ($bytes>PHP_INT_MAX-$extra) throw new RuntimeException('pg_edit_volume_overflow');
        $out['data_limit']=$bytes+$extra;
    } else $out['data_limit']=$bytes;
    if ($user['status']==='disabled') $out['status']='disabled';
    elseif (in_array($user['status'],['expired','limited'],true)) $out['status']='active';
    return $out;
}
function achbotPgServiceEdit(mysqli $db,int $serverId,array $change,?array $giftTarget=null): object {
    try {
        $requestUnits=achbotPgEditInputUnits($change);
        $referenceOrder=(int)($change['order_id']??0);
        if ((int)($change['pay_id']??0)>0) {
            $payId=(int)$change['pay_id'];
            $s=$db->prepare('SELECT order_id,type FROM pays WHERE id=?');
            $s->bind_param('i',$payId);$s->execute();$payment=$s->get_result()->fetch_assoc();$s->close();
            if (!$payment) throw new RuntimeException('pg_edit_payment_missing');
            $referenceOrder=(int)$payment['order_id'];
            if ($referenceOrder<=0 && preg_match('/^INCREASE_(?:DAY|VOLUME)_([1-9][0-9]*)_/',(string)$payment['type'],$m))
                $referenceOrder=(int)$m[1];
            if ($referenceOrder<=0) throw new RuntimeException('pg_edit_payment_reference_missing');
        }
        $ctx=$referenceOrder>0?achbotPgOrderContext($db,$referenceOrder):
            achbotPgRemarkContext($db,$serverId,$change['remark']);
        if ($ctx['order_server_id']!==$serverId || $ctx['order']['remark']!==$change['remark'])
            throw new RuntimeException('pg_edit_reference_changed');
        if ($giftTarget!==null && !achbotGiftTargetMatches($db,$serverId,$giftTarget))
            throw new RuntimeException('pg_gift_target_changed');
        $key=achbotPgOperationKey('edit',$change);
        achbotPgMutationAdmission($ctx['account'],$key);
        if ((int)($change['pay_id']??0)>0) achbotPgQuote($db,(int)$change['pay_id']);
        else {
            $preflight=achbotPgRead($ctx['panel'],$ctx['identity']);
            if (($preflight['success']??false)!==true) throw new RuntimeException('pg_edit_read_unverified');
            if (!achbotPgManaged($preflight['user'])) throw new RuntimeException('pg_native_schedule_conflict');
        }
        // Own the durable account fence BEFORE calculating limits. Eligibility
        // preflight is not used for arithmetic; a completed competing edit is
        // included by the fresh observation below.
        $intent=achbotPgBeginMutation($db,$ctx,$key,'edit',['phase'=>'claimed','requested_units'=>$requestUnits]);
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
        $s=$db->prepare("SELECT id,remark FROM orders_list WHERE server_id=? AND status=1 ORDER BY id");
        $s->bind_param('i',$serverId);$s->execute();$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
        $contexts=[];
        foreach ($rows as $row) {
            $ctx=achbotPgOrderContext($db,(int)$row['id']);
            if ($ctx['account']['state']!=='linked') throw new RuntimeException('pg_gift_roster_account_requires_review');
            $contexts[$ctx['account']['id']]=$ctx;
        }
        $users=[];foreach ($contexts as $ctx) {
            $r=achbotPgRead($ctx['panel'],$ctx['identity']);
            if (($r['success']??false)!==true) throw new RuntimeException('pg_gift_list_unverified');
            $users[]=(object)$r['user'];
        }
        return (object)['users'=>$users,'total'=>count($users)];
    } catch (Throwable $error) {return achbotProviderFailure('pg_gift_list_requires_review');}
}

/** The same native presentation policy is used for details and both QR routes. */
function achbotOrderPresentation(mysqli $db,array $order,array $server,array $state,?object $provenRemote=null): array {
    $type=(string)($server['type']??'');$native=['links'=>[],'subscription_url'=>'','backup_urls'=>[]];
    if ($type==='marzban') {
        if ($provenRemote===null) {
            $native['links']=json_decode((string)$order['link'],true)?:[];
            if ((string)$order['token']!=='')
                $native['subscription_url']=rtrim((string)$server['panel_url'],'/').'/sub/'.$order['token'];
            foreach (array_filter(array_map('trim',explode(',',(string)($server['sub_links']??'')))) as $domain)
                $native['backup_urls'][]=rtrim($domain,'/').'/sub/'.$order['token'];
        } elseif (achbotProviderRenderProofValid($provenRemote,$order,$type) &&
            is_string($provenRemote->subscription_url??null) && is_array($provenRemote->links??null)) {
            $url=$provenRemote->subscription_url;
            if (str_starts_with($url,'/')) $url=rtrim((string)$server['panel_url'],'/').$url;
            $parts=achbotProviderImportParts($url);
            if ($parts) {
                $links=[];
                foreach ($provenRemote->links as $uri)
                    if (is_string($uri) && preg_match('#^[a-z][a-z0-9+.-]*://[^\x00-\x20\x7f]+$#iD',$uri))
                        $links[]=$uri;
                $native['links']=$links;$native['subscription_url']=$url;
                foreach (array_filter(array_map('trim',explode(',',(string)($server['sub_links']??'')))) as $base) {
                    $alias=achbotProviderImportBase($base,$parts['prefix']);
                    if (!$alias || str_contains($alias['origin'],'*')) continue;
                    $origin=$alias['origin'];
                    if (str_starts_with($origin,'https://') && str_ends_with($origin,':443')) $origin=substr($origin,0,-4);
                    if (str_starts_with($origin,'http://') && str_ends_with($origin,':80')) $origin=substr($origin,0,-3);
                    $native['backup_urls'][]=$origin.$alias['prefix'].'/'.rawurlencode($parts['token']);
                }
            }
        }
    } elseif ($type==='pasarguard') {
        try {
            if ($provenRemote!==null) {
                if (!achbotProviderRenderProofValid($provenRemote,$order,$type))
                    throw new RuntimeException('pg_presentation_packet_unverified');
                $native['subscription_url']=(string)$provenRemote->subscription_url;
                $native['links']=is_array($provenRemote->links??null)?$provenRemote->links:[];
            } else {
                $ctx=achbotPgOrderContext($db,(int)$order['id']);
                $links=achbotPgLinks($ctx['panel'],$ctx['identity']);
                $r=($links['success']??false)===true?$links:achbotPgRead($ctx['panel'],$ctx['identity']);
                if (($r['success']??false)!==true) throw new RuntimeException('pg_presentation_read_unverified');
                $native['subscription_url']=$r['user']['subscription_url'];
                if (($links['success']??false)===true) $native['links']=$links['links'];
            }
        } catch (Throwable $error) {}
    }
    return buildSubscriptionPresentation($type,$native,$state,[],'view',(int)$order['server_id']);
}

/**
 * Closed, source-owned provider contract. Adding a native adapter registers all
 * real operations here; an unknown or partial implementation is never eligible
 * for a sale, wallet reservation or remote effect. No runtime plugin loading.
 */
function achbotProviderRequiredOperations(): array {
    return ['plan','order','same_panel','user','user_info','deletion_target','action_preflight','action',
        'edit','pin_payment','quote','target','reserve','dispatch','link'];
}
function achbotProviderRegistry(): array {
    return [
        'marzban'=>[
            'label'=>'admin_settings.management.marzban',
            'plan'=>'achbotMarzbanPlanAdapter','order'=>'achbotMarzbanOrderAdapter',
            'same_panel'=>'achbotMarzbanSamePanelAdapter','user'=>'achbotMarzbanUserAdapter',
            'user_info'=>'achbotMarzbanUserInfoAdapter','deletion_target'=>'achbotMarzbanDeletionTargetAdapter',
            'action_preflight'=>'achbotMarzbanActionPreflightAdapter',
            'action'=>'achbotMarzbanActionAdapter','edit'=>'achbotMarzbanServiceEditAdapter',
            'pin_payment'=>'achbotMarzbanPinPaymentAdapter','quote'=>'achbotMarzbanQuoteAdapter',
            'target'=>'achbotMarzbanEffectTargetAdapter','reserve'=>'achbotMarzbanReserveEffectAdapter',
            'dispatch'=>'achbotMarzbanDispatchEffectAdapter','link'=>'achbotMarzbanLinkEffectAdapter',
            'import_lookup'=>'achbotMarzbanExistingUser','import_adopt'=>'achbotMarzbanAdoptExistingLocked'
        ],
        'pasarguard'=>[
            'label'=>'admin_settings.management.pasarguard',
            'plan'=>'achbotPasarGuardSupportsPlan','order'=>'achbotPasarGuardSupportsOrder',
            'same_panel'=>'achbotPasarGuardSamePanelAdapter','user'=>'achbotPasarGuardUserAdapter',
            'user_info'=>'achbotPasarGuardUserInfoAdapter','deletion_target'=>'achbotPasarGuardDeletionTargetAdapter',
            'action_preflight'=>'achbotPasarGuardActionPreflightAdapter',
            'action'=>'achbotPasarGuardActionAdapter','edit'=>'achbotPasarGuardServiceEditAdapter',
            'pin_payment'=>'achbotPgPinPayment','quote'=>'achbotPasarGuardQuoteAdapter',
            'target'=>'achbotPasarGuardEffectTargetAdapter','reserve'=>'achbotPasarGuardReserveEffectAdapter',
            'dispatch'=>'achbotPasarGuardDispatchEffectAdapter','link'=>'achbotPasarGuardLinkEffectAdapter',
            'import_lookup'=>'achbotPasarGuardExistingUser','import_adopt'=>'achbotPasarGuardAdoptExistingLocked'
        ]
    ];
}
function achbotProviderRegistryEntryValid($type,$adapter): bool {
    if (!is_string($type) || preg_match('/^[a-z][a-z0-9_]{0,23}$/D',$type)!==1 ||
        !is_array($adapter) || !is_string($adapter['label']??null) ||
        preg_match('/^[a-z][a-z0-9_.]+$/D',$adapter['label'])!==1) return false;
    $required=achbotProviderRequiredOperations();
    if (array_diff(array_keys($adapter),array_merge(['label','import_lookup','import_adopt'],$required))) return false;
    foreach ($required as $operation)
        if (!is_string($adapter[$operation]??null) || !is_callable($adapter[$operation]))
            return false;
    $lookup=array_key_exists('import_lookup',$adapter);$adopt=array_key_exists('import_adopt',$adapter);
    if ($lookup!==$adopt) return false;
    if ($lookup) foreach (['import_lookup','import_adopt'] as $operation)
        if (!is_string($adapter[$operation]) || !is_callable($adapter[$operation])) return false;
    return true;
}
function achbotProviderRegistryValid(array $registry): bool {
    if (!$registry) return false;
    foreach ($registry as $type=>$adapter) if (!achbotProviderRegistryEntryValid($type,$adapter)) return false;
    return true;
}
function achbotProviderDefinition($type): ?array {
    if (!is_string($type)) return null;
    $adapter=achbotProviderRegistry()[$type]??null;
    return achbotProviderRegistryEntryValid($type,$adapter)?$adapter:null;
}
function achbotSupportsProviderType($type): bool {
    return achbotProviderDefinition($type)!==null;
}
function achbotProviderTypes(): array {
    $types=[];
    foreach (achbotProviderRegistry() as $type=>$adapter)
        if (achbotProviderRegistryEntryValid($type,$adapter)) $types[]=$type;
    return $types;
}
/** SQL contains only validated source-owned keys, never a callback or customer value. */
function achbotProviderSqlTypes(): string {
    $types=achbotProviderTypes();
    if (!$types) throw new RuntimeException('provider_registry_empty');
    return "'".implode("','",$types)."'";
}
function achbotProviderLabel($type): string {
    $adapter=achbotProviderDefinition($type);
    return $adapter===null?'':achbotMessage($adapter['label']);
}
function achbotMarzbanPlanAdapter($db,$planId,$inbound=0,$serverId=null,bool $lock=false): bool {
    return achbotSupportsMarzbanPlan($db,$planId,$inbound,$serverId,$lock);
}
function achbotMarzbanOrderAdapter($db,$orderId,$planId=null,$userId=null,bool $lock=false): bool {
    return achbotSupportsMarzbanOrder($db,$orderId,$planId,$userId,$lock);
}
function achbotProviderServiceEdit(mysqli $db,string $type,int $serverId,array $change,?array $giftTarget=null) {
    $adapter=achbotProviderDefinition($type);
    return $adapter===null?achbotProviderFailure('unsupported_service_adapter',false):
        ($adapter['edit'])($db,$serverId,$change,$giftTarget);
}
function achbotMarzbanServiceEditAdapter(mysqli $db,int $serverId,array $change,?array $giftTarget=null) {
    return editMarzbanConfig($serverId,$change,$giftTarget);
}
function achbotPasarGuardServiceEditAdapter(mysqli $db,int $serverId,array $change,?array $giftTarget=null) {
    return achbotPgServiceEdit($db,$serverId,$change,$giftTarget);
}
function achbotProviderPinPayment(mysqli $db,int $payId): void {
    $s=$db->prepare('SELECT type,server_id FROM pays WHERE id=?');
    $s->bind_param('i',$payId);$s->execute();$pay=$s->get_result()->fetch_assoc();$s->close();
    if (!$pay) throw new RuntimeException('provider_payment_missing');
    if ($pay['type']==='INCREASE_WALLET') return;
    $adapter=achbotProviderDefinition(achbotProviderType($db,(int)$pay['server_id']));
    if ($adapter===null) throw new RuntimeException('unsupported_payment_provider');
    ($adapter['pin_payment'])($db,$payId);
}
/**
 * Resolve the service named by the payment, rather than a redundant server
 * hint. Read-only subject checks may omit server_id; a present hint must
 * still identify the exact order/plan server and is never silently replaced.
 */
function achbotProviderPaymentSubjectServer(mysqli $db,array $pay,bool $lock=false): ?int {
    $type=(string)($pay['type']??'');
    $pid=(int)($pay['plan_id']??0);
    $uid=(int)($pay['user_id']??0);
    if ($type==='RENEW_ACCOUNT' ||
        preg_match('/^INCREASE_(DAY|VOLUME)_([1-9][0-9]*)_([1-9][0-9]*)$/D',$type,$match)) {
        $oid=$type==='RENEW_ACCOUNT'?(int)($pay['order_id']??0):(int)$match[2];
        if ($oid<=0 || ($type==='RENEW_ACCOUNT' && $pid<=0) ||
            (!empty($pay['order_id']) && (int)$pay['order_id']!==$oid)) return null;
        $s=$db->prepare('SELECT server_id,fileid,userid FROM orders_list WHERE id=?'.($lock?' FOR UPDATE':''));
        $s->bind_param('i',$oid);$s->execute();$subject=$s->get_result()->fetch_assoc();$s->close();
        if (!$subject || ($pid>0 && (int)$subject['fileid']!==$pid) ||
            ($uid>0 && (int)$subject['userid']!==$uid)) return null;
    } elseif ($type==='BUY_SUB' || $type==='RENEW_SCONFIG') {
        if ($pid<=0) return null;
        $s=$db->prepare('SELECT server_id FROM server_plans WHERE id=?'.($lock?' FOR UPDATE':''));
        $s->bind_param('i',$pid);$s->execute();$subject=$s->get_result()->fetch_assoc();$s->close();
        if (!$subject) return null;
    } else return null;
    $sid=(int)$subject['server_id'];
    if ($sid<=0) return null;
    if (array_key_exists('server_id',$pay) &&
        ((!is_int($pay['server_id']) && !is_string($pay['server_id'])) ||
         (string)$pay['server_id']!==(string)$sid)) return null;
    return $sid;
}
function achbotProviderQuoteEligible(mysqli $db,array $pay,bool $lock=false): bool {
    try {
        $sid=achbotProviderPaymentSubjectServer($db,$pay,$lock);
        if ($sid===null) return false;
        $adapter=achbotProviderDefinition(achbotProviderType($db,$sid));
        $pay['server_id']=$sid;
        return $adapter!==null && ($adapter['quote'])($db,$pay,$lock)===true;
    } catch (Throwable $error) {return false;}
}
function achbotMarzbanPinPaymentAdapter(mysqli $db,int $payId): void {
    // Existing Marzban quotes are pinned by their original ledger/effect fields.
}
function achbotMarzbanQuoteAdapter(mysqli $db,array $pay,bool $lock=false): bool {
    return true; // Plan/order/physical identity guards remain in the common caller.
}
function achbotPasarGuardQuoteAdapter(mysqli $db,array $pay,bool $lock=false): bool {
    achbotPgQuote($db,(int)($pay['id']??0),$lock);return true;
}
function achbotProviderReserveEffect(mysqli $db,int $effectId,array $target,string $remark,string $operation,int $expire,int $bytes): void {
    $adapter=achbotProviderDefinition($target['provider']??null);
    if ($adapter===null) throw new RuntimeException('unsupported_effect_provider');
    ($adapter['reserve'])($db,$effectId,$target,$remark,$operation,$expire,$bytes);
}
function achbotMarzbanReserveEffectAdapter(mysqli $db,int $effectId,array $target,string $remark,string $operation,int $expire,int $bytes): void {
    if (isset($target['snapshot'])) throw new RuntimeException('marzban_effect_identity_invalid');
    // Original Marzban reservation is already durably stored by ServiceEffects.
}
function achbotPasarGuardReserveEffectAdapter(mysqli $db,int $effectId,array $target,string $remark,string $operation,int $expire,int $bytes): void {
    if (($target['snapshot']['provider']??null)!=='pasarguard') throw new RuntimeException('pg_effect_identity_invalid');
    achbotPgReserveEffect($db,$effectId,$target,$remark,$operation,$expire,$bytes);
}
function achbotProviderDispatchReservedAccount(
    mysqli $db,int $effectId,int $serverId,?int $group,string $endpoint,string $remark,
    int $volume,int $days,int $planId,string $operation,int $expiry,int $bytes,array $p,string $credentials
): array {
    $type=achbotProviderType($db,$serverId);
    // The common reservation captured this provider before COMMIT. A later
    // edit must not select another adapter for the already-owned operation.
    if (array_key_exists('server_type',$p) &&
        (!is_string($p['server_type']) || $p['server_type']!==$type))
        throw new RuntimeException('effect_provider_changed_pre_dispatch');
    $adapter=achbotProviderDefinition($type);
    if ($adapter===null) throw new RuntimeException('unsupported_effect_provider');
    return ($adapter['dispatch'])($db,$effectId,$serverId,$group,$endpoint,$remark,$volume,$days,$planId,$operation,$expiry,$bytes,$p,$credentials);
}
function achbotMarzbanDispatchEffectAdapter(
    mysqli $db,int $effectId,int $serverId,?int $group,string $endpoint,string $remark,
    int $volume,int $days,int $planId,string $operation,int $expiry,int $bytes,array $p,string $credentials
): array {
    require_once __DIR__.'/../Finance/ServiceEffects.php';
    return achbotMarzbanEffectDispatchReservedAccount($db,$effectId,$serverId,$group,$endpoint,$remark,$volume,$days,$planId,$operation,$expiry,$bytes,$p,$credentials);
}
function achbotPasarGuardDispatchEffectAdapter(
    mysqli $db,int $effectId,int $serverId,?int $group,string $endpoint,string $remark,
    int $volume,int $days,int $planId,string $operation,int $expiry,int $bytes,array $p,string $credentials
): array {
    return achbotPgDispatchReserved($db,$effectId,$serverId,$remark,$operation,$expiry,$bytes,$p,$credentials);
}
function achbotProviderLinkEffect(mysqli $db,int $effectId,int $orderId,bool $paid): void {
    $s=$db->prepare('SELECT c.type FROM service_effects e JOIN server_config c ON c.id=e.target_server_id WHERE e.id=?');
    $s->bind_param('i',$effectId);$s->execute();$row=$s->get_result()->fetch_assoc();$s->close();
    $adapter=achbotProviderDefinition($row['type']??null);
    if ($adapter===null) throw new RuntimeException('unsupported_effect_provider');
    ($adapter['link'])($db,$effectId,$orderId,$paid);
}
function achbotMarzbanLinkEffectAdapter(mysqli $db,int $effectId,int $orderId,bool $paid): void {
    require_once __DIR__.'/../Finance/ServiceEffects.php';
    if ($paid) achbotMarzbanLinkPaidAccountEffect($db,$effectId,$orderId);
    else achbotMarzbanLinkNonPaymentAccountEffect($db,$effectId,$orderId);
}
function achbotPasarGuardLinkEffectAdapter(mysqli $db,int $effectId,int $orderId,bool $paid): void {
    if (!achbotPgLinkEffect($db,$effectId,$orderId)) throw new RuntimeException('pg_effect_link_unverified');
}
