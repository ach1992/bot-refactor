<?php
/** AChBot native PasarGuard 5.5.1 adapter; no retries, scheduling or finance. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }

function achbotPgFailure(string $reason, bool $uncertain=false, int $http=0): array {
    return ['success'=>false,'uncertain'=>$uncertain,'reason'=>$reason,'http'=>$http];
}
function achbotPgGroupsCanonical(array $groups): array {
    if (!array_is_list($groups) || count($groups)>1000) throw new RuntimeException('pg_groups_invalid');
    foreach ($groups as $id) if (!is_int($id) || $id<=0) throw new RuntimeException('pg_groups_invalid');
    $groups=array_values(array_unique($groups,SORT_NUMERIC)); sort($groups,SORT_NUMERIC);
    return $groups;
}
function achbotPgDate(string $value): string {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D',$value))
        throw new RuntimeException('pg_datetime_invalid');
    $date=new DateTimeImmutable($value);
    $errors=DateTimeImmutable::getLastErrors();
    if ($errors!==false && ($errors['warning_count'] || $errors['error_count']))
        throw new RuntimeException('pg_datetime_invalid');
    return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
}
function achbotPgExpiry($value): ?int {
    if ($value===null || $value===0) return null;
    if (is_int($value) && $value>0) return $value;
    if (!is_string($value)) throw new RuntimeException('pg_expiry_invalid');
    return (new DateTimeImmutable(achbotPgDate($value)))->getTimestamp();
}
function achbotPgPanelValid(array $panel): bool {
    if (($panel['version']??'')!=='5.5.1' || !is_int($panel['id']??null) || $panel['id']<=0 ||
        !preg_match('/^[a-f0-9]{32}$/D',(string)($panel['instance_key']??'')) ||
        !is_string($panel['api_key']??null) || $panel['api_key']==='' ||
        preg_match('/[^\x21-\x7e]/',$panel['api_key']) ||
        !is_string($panel['api_url']??null)) return false;
    $url=parse_url($panel['api_url']);
    if (!$url || empty($url['host']) || isset($url['user'],$url['pass']) ||
        isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) ||
        preg_match('/[\x00-\x20\x7f]/',$panel['api_url'])) return false;
    if (($url['scheme']??'')==='https') return true;
    return PHP_SAPI==='cli' && defined('ACHBOT_PG_TEST_LOOPBACK') && ACHBOT_PG_TEST_LOOPBACK===true &&
        ($url['scheme']??'')==='http' && in_array($url['host'],['127.0.0.1','[::1]'],true);
}
/** Private transport. Diagnostics never include response/credentials/URL. */
function achbotPgRequest(array $panel,string $method,string $path,?array $payload=null,
                         bool $text=false): array {
    $write=$method!=='GET';
    $validPath=preg_match('#^/api/[a-zA-Z0-9_/?=&.-]+$#D',$path) ||
        preg_match('#^/api/user/by-username/(?:[a-zA-Z0-9_.-]|%40)+$#D',$path);
    if (!achbotPgPanelValid($panel) || !$validPath) return achbotPgFailure('pg_panel_config_invalid');
    $body=null;
    try { if ($payload!==null) $body=json_encode($payload,JSON_THROW_ON_ERROR); }
    catch (Throwable $e) { return achbotPgFailure('pg_request_invalid'); }
    $curl=curl_init(rtrim($panel['api_url'],'/').$path);
    $wire='';$tooLarge=false;$max=$text?4194304:1048576;
    curl_setopt_array($curl,[
        CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>10,
        CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER=>['X-Api-Key: '.$panel['api_key'],
            'Accept: '.($text?'text/plain':'application/json'),'Content-Type: application/json'],
        CURLOPT_WRITEFUNCTION=>static function($ch,string $chunk) use (&$wire,&$tooLarge,$max):int {
            if (strlen($wire)+strlen($chunk)>$max) {$tooLarge=true;return 0;}
            $wire.=$chunk;return strlen($chunk);
        }
    ]);
    if ($body!==null) curl_setopt($curl,CURLOPT_POSTFIELDS,$body);
    $ok=curl_exec($curl);$errno=curl_errno($curl);
    $http=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);
    $contentType=(string)curl_getinfo($curl,CURLINFO_CONTENT_TYPE);curl_close($curl);
    if ($ok===false || $errno!==0) return achbotPgFailure($tooLarge?'pg_response_too_large':
        ($errno===CURLE_OPERATION_TIMEDOUT?'pg_timeout':'pg_transport_unavailable'),$write,$http);
    if ($http<200 || $http>=300) {
        $reasons=[401=>'pg_auth_denied',403=>'pg_permission_denied',404=>'pg_not_found',
            409=>'pg_conflict',422=>'pg_request_rejected',429=>'pg_rate_limited'];
        return achbotPgFailure($reasons[$http]??($http>=500?'pg_vendor_unavailable':'pg_http_unexpected'),$write,$http);
    }
    if ($method==='GET' && $http!==200) return achbotPgFailure('pg_read_status_invalid',false,$http);
    if ($http===204) return ['success'=>true,'uncertain'=>false,'reason'=>'','http'=>$http,'data'=>null];
    if ($text) {
        if (stripos($contentType,'text/plain')!==0) return achbotPgFailure('pg_content_type_invalid',$write,$http);
        return ['success'=>true,'uncertain'=>false,'reason'=>'','http'=>$http,'data'=>$wire];
    }
    if (!preg_match('#^application/(?:[a-z0-9.+-]+\+)?json(?:;|$)#i',$contentType))
        return achbotPgFailure('pg_content_type_invalid',$write,$http);
    try { $json=json_decode($wire,true,64,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING); }
    catch (Throwable $e) { return achbotPgFailure('pg_json_invalid',$write,$http); }
    if (!is_array($json)) return achbotPgFailure('pg_schema_invalid',$write,$http);
    return ['success'=>true,'uncertain'=>false,'reason'=>'','http'=>$http,'data'=>$json];
}
function achbotPgNormalizeUser(array $user): array {
    foreach (['id','used_traffic'] as $field)
        if (!is_int($user[$field]??null) || $user[$field]<($field==='id'?1:0))
            throw new RuntimeException('pg_user_schema_invalid');
    if (!is_string($user['username']??null) || $user['username']==='' ||
        !is_string($user['created_at']??null) ||
        !array_key_exists('expire',$user) || !array_key_exists('data_limit',$user) ||
        !is_array($user['group_ids']??null) || !is_array($user['proxy_settings']??null) ||
        !in_array($user['status']??null,['active','disabled','limited','expired','on_hold'],true) ||
        !(is_null($user['data_limit']) || (is_int($user['data_limit']) && $user['data_limit']>=0)) ||
        !(is_null($user['note']??null) || is_string($user['note'])) ||
        !array_key_exists('data_limit_reset_strategy',$user) ||
        !array_key_exists('next_plan',$user) ||
        !is_string($user['subscription_url']??null))
        throw new RuntimeException('pg_user_schema_invalid');
    $subscription=$user['subscription_url'];$parts=parse_url($subscription);
    if (!$parts || !in_array($parts['scheme']??'', ['https','http'],true) || empty($parts['host']) ||
        isset($parts['user']) || isset($parts['pass']) || preg_match('/[\x00-\x20\x7f]/',$subscription))
        throw new RuntimeException('pg_subscription_invalid');
    $user['created_at']=achbotPgDate($user['created_at']);
    $user['expire']=achbotPgExpiry($user['expire']);
    $user['data_limit']=$user['data_limit']??0;
    $user['group_ids']=achbotPgGroupsCanonical($user['group_ids']);
    $user['note']=$user['note']??'';
    return array_intersect_key($user,array_flip(['id','username','created_at','note',
        'data_limit','expire','status','group_ids','used_traffic','subscription_url',
        'proxy_settings','data_limit_reset_strategy','next_plan','auto_delete_in_days',
        'on_hold_expire_duration','on_hold_timeout']));
}
function achbotPgUserResult(array $result,bool $write=false): array {
    if (!$result['success']) return $result;
    try { $user=achbotPgNormalizeUser($result['data']); }
    catch (Throwable $e) {
        $reason=in_array($e->getMessage(),['pg_user_schema_invalid','pg_subscription_invalid',
            'pg_datetime_invalid','pg_expiry_invalid','pg_groups_invalid'],true)?
            $e->getMessage():'pg_user_schema_invalid';
        return achbotPgFailure($reason,$write,$result['http']);
    }
    return ['success'=>true,'uncertain'=>false,'reason'=>'','http'=>$result['http'],'user'=>$user];
}
function achbotPgIdentityValid(array $identity): bool {
    if (!is_int($identity['remote_user_id']??null) || $identity['remote_user_id']<=0 ||
        !is_string($identity['remote_created_at']??null)) return false;
    if (($identity['identity_mode']??null)==='adopted')
        return preg_match('/^achbot:adopt:[a-f0-9]{32}$/D',(string)($identity['operation_marker']??''))===1 &&
            is_string($identity['remote_username']??null) && $identity['remote_username']!=='' &&
            preg_match('/^[a-f0-9]{64}$/D',(string)($identity['adoption_sha256']??''))===1;
    return !isset($identity['identity_mode']) &&
        preg_match('/^achbot:v1:[a-f0-9]{32}$/D',(string)($identity['operation_marker']??''))===1;
}
function achbotPgIdentityMatches(array $user,array $identity): bool {
    try {
        if (!achbotPgIdentityValid($identity) || $user['id']!==$identity['remote_user_id'] ||
            $user['created_at']!==achbotPgDate($identity['remote_created_at'])) return false;
        // Adopted mode is constructed only by the persisted adoption provenance validator.
        // Native notes stay untouched. Created identities retain their exact marker requirement.
        return ($identity['identity_mode']??null)==='adopted'?
            ($user['username']??null)===$identity['remote_username']:
            hash_equals($identity['operation_marker'],$user['note']);
    } catch (Throwable $e) {return false;}
}
function achbotPgRead(array $panel,array $identity): array {
    if (!achbotPgIdentityValid($identity)) return achbotPgFailure('pg_identity_invalid');
    $r=achbotPgUserResult(achbotPgRequest($panel,'GET','/api/user/by-id/'.$identity['remote_user_id']));
    if ($r['success'] && !achbotPgIdentityMatches($r['user'],$identity))
        return achbotPgFailure('pg_identity_mismatch',false,$r['http']);
    return $r;
}
function achbotPgManaged(array $user): bool {
    return $user['status']!=='on_hold' && ($user['next_plan']??null)===null &&
        ($user['data_limit_reset_strategy']??null)==='no_reset' &&
        ($user['auto_delete_in_days']??null)===null &&
        ($user['on_hold_expire_duration']??null)===null && ($user['on_hold_timeout']??null)===null;
}
function achbotPgCreate(array $panel,array $request): array {
    $dispatched=false;
    try {
        if (!is_string($request['username']??null) ||
            !preg_match('/^[a-zA-Z0-9_@.-]{3,100}$/D',$request['username']) ||
            preg_match('/[-_@.]{2}/',$request['username']) ||
            !is_int($request['data_limit']??null) || $request['data_limit']<0 ||
            !array_key_exists('expire',$request) ||
            !($request['expire']===null || (is_int($request['expire']) && $request['expire']>0)) ||
            !preg_match('/^achbot:v1:[a-f0-9]{32}$/D',(string)($request['operation_marker']??'')))
            return achbotPgFailure('pg_create_invalid');
        $groups=achbotPgGroupsCanonical($request['group_ids']??[]);
        if (!$groups) return achbotPgFailure('pg_groups_required');
        $payload=['username'=>$request['username'],'data_limit'=>$request['data_limit'],
            'expire'=>$request['expire']===null?null:gmdate('Y-m-d\TH:i:s\Z',$request['expire']),
            'group_ids'=>$groups,'status'=>'active','data_limit_reset_strategy'=>'no_reset',
            'next_plan'=>null,'auto_delete_in_days'=>null,'on_hold_expire_duration'=>null,
            'on_hold_timeout'=>null,'note'=>$request['operation_marker']];
        $dispatched=true;
        $r=achbotPgRequest($panel,'POST','/api/user',$payload);
        if (!$r['success']) return $r;
        if ($r['http']!==201) return achbotPgFailure('pg_create_status_invalid',true,$r['http']);
        $r=achbotPgUserResult($r,true);
        if (!$r['success']) return $r;
        $u=$r['user'];
        $identity=['remote_user_id'=>$u['id'],'remote_created_at'=>$u['created_at'],
            'operation_marker'=>$request['operation_marker']];
        if ($u['username']!==$request['username'] || !achbotPgIdentityMatches($u,$identity))
            return achbotPgFailure('pg_create_identity_unconfirmed',true,$r['http']);
        $read=achbotPgRead($panel,$identity);
        if (!$read['success']) return achbotPgFailure('pg_create_readback_unconfirmed',true,$read['http']);
        $u=$read['user'];
        if ($u['username']!==$request['username'] || $u['data_limit']!==$request['data_limit'] ||
            $u['expire']!==$request['expire'] || $u['group_ids']!==$groups ||
            $u['status']!=='active' || !achbotPgManaged($u))
            return achbotPgFailure('pg_create_entitlement_unconfirmed',true,$read['http']);
        return $read;
    } catch (Throwable $e) { return achbotPgFailure($dispatched?'pg_create_unconfirmed':'pg_create_invalid',$dispatched); }
}
function achbotPgModify(array $panel,array $identity,array $absoluteChanges): array {
    $dispatched=false;
    try {
        if (!$absoluteChanges || array_diff(array_keys($absoluteChanges),['data_limit','expire','status','group_ids']))
            return achbotPgFailure('pg_modify_invalid');
        $payload=[];$expected=$absoluteChanges;
        foreach ($absoluteChanges as $key=>$value) {
            if ($key==='data_limit' && (!is_int($value) || $value<0)) return achbotPgFailure('pg_quota_invalid');
            if ($key==='expire' && !($value===null || (is_int($value) && $value>0))) return achbotPgFailure('pg_expiry_invalid');
            if ($key==='status' && !in_array($value,['active','disabled'],true)) return achbotPgFailure('pg_status_invalid');
            if ($key==='group_ids') {
                $value=achbotPgGroupsCanonical($value);
                if (!$value) return achbotPgFailure('pg_groups_required');
                $expected[$key]=$value;
            }
            $payload[$key]=$key==='expire' && $value!==null?gmdate('Y-m-d\TH:i:s\Z',$value):$value;
        }
        $before=achbotPgRead($panel,$identity);
        if (!$before['success']) return $before;
        if (!achbotPgManaged($before['user'])) return achbotPgFailure('pg_native_schedule_conflict');
        $dispatched=true;
        $r=achbotPgRequest($panel,'PUT','/api/user/by-id/'.$identity['remote_user_id'],$payload);
        if (!$r['success']) return $r;
        if ($r['http']!==200) return achbotPgFailure('pg_modify_status_invalid',true,$r['http']);
        $ack=achbotPgUserResult($r,true);
        if (!$ack['success'] || !achbotPgIdentityMatches($ack['user'],$identity))
            return achbotPgFailure('pg_modify_identity_unconfirmed',true,$r['http']);
        $read=achbotPgRead($panel,$identity);
        if (!$read['success']) return achbotPgFailure('pg_modify_readback_unconfirmed',true,$read['http']);
        $target=array_merge(array_intersect_key($before['user'],array_flip(['data_limit','expire','status','group_ids'])),$expected);
        // An expiry/quota change may naturally activate expired/limited users.
        foreach ($target as $key=>$value) {
            if ($key==='status' && !array_key_exists('status',$expected)) continue;
            if ($read['user'][$key]!==$value) return achbotPgFailure('pg_modify_entitlement_unconfirmed',true,$read['http']);
        }
        if (!achbotPgManaged($read['user'])) return achbotPgFailure('pg_native_schedule_conflict',true,$read['http']);
        return $read;
    } catch (Throwable $e) {return achbotPgFailure($dispatched?'pg_modify_unconfirmed':'pg_modify_invalid',$dispatched);}
}
function achbotPgAction(array $panel,array $identity,string $action): array {
    $before=achbotPgRead($panel,$identity);
    if (!$before['success']) return $before;
    if (!achbotPgManaged($before['user'])) return achbotPgFailure('pg_native_schedule_conflict');
    $r=achbotPgRequest($panel,$action==='delete'?'DELETE':'POST',
        '/api/user/by-id/'.$identity['remote_user_id'].($action==='delete'?'':'/'.$action));
    if (!$r['success']) return $r;
    if ($action==='delete') {
        if ($r['http']!==204) return achbotPgFailure('pg_delete_status_invalid',true,$r['http']);
        $read=achbotPgRead($panel,$identity);
        if (!$read['success'] && $read['http']===404)
            return ['success'=>true,'uncertain'=>false,'reason'=>'','http'=>204];
        return achbotPgFailure('pg_delete_absence_unconfirmed',true,$read['http']);
    }
    if ($r['http']!==200) return achbotPgFailure('pg_action_status_invalid',true,$r['http']);
    $ack=achbotPgUserResult($r,true);
    if (!$ack['success'] || !achbotPgIdentityMatches($ack['user'],$identity))
        return achbotPgFailure('pg_action_identity_unconfirmed',true,$r['http']);
    $read=achbotPgRead($panel,$identity);
    if (!$read['success']) return achbotPgFailure('pg_action_readback_unconfirmed',true,$read['http']);
    foreach (['data_limit','expire','group_ids'] as $key) if ($read['user'][$key]!==$before['user'][$key])
        return achbotPgFailure('pg_action_entitlement_changed',true,$read['http']);
    if (!achbotPgManaged($read['user'])) return achbotPgFailure('pg_native_schedule_conflict',true,$read['http']);
    if ($action==='reset' && ($ack['user']['used_traffic']!==0 || $read['user']['used_traffic']!==0))
        return achbotPgFailure('pg_reset_unconfirmed',true,$read['http']);
    if ($action==='revoke_sub' && ($read['user']['proxy_settings']===$before['user']['proxy_settings'] ||
        $ack['user']['proxy_settings']!==$read['user']['proxy_settings']))
        return achbotPgFailure('pg_revoke_identity_unconfirmed',true,$read['http']);
    return $read;
}
function achbotPgReset(array $panel,array $identity): array {return achbotPgAction($panel,$identity,'reset');}
function achbotPgRevoke(array $panel,array $identity): array {return achbotPgAction($panel,$identity,'revoke_sub');}
function achbotPgDelete(array $panel,array $identity): array {return achbotPgAction($panel,$identity,'delete');}
function achbotPgLinks(array $panel,array $identity,string $format='links'): array {
    if (!in_array($format,['links','links_base64'],true)) return achbotPgFailure('pg_format_unsupported');
    $read=achbotPgRead($panel,$identity);if (!$read['success']) return $read;
    $r=achbotPgRequest($panel,'GET','/api/user/'.$identity['remote_user_id'].'/subscription/'.$format,null,true);
    if (!$r['success']) return $r;
    if ($r['http']!==200) return achbotPgFailure('pg_links_status_invalid',false,$r['http']);
    $wire=$format==='links_base64'?base64_decode(trim($r['data']),true):$r['data'];
    if ($wire===false) return achbotPgFailure('pg_links_base64_invalid',false,$r['http']);
    $links=[];
    foreach (preg_split('/\r\n|\n|\r/',$wire) as $line) {
        $line=trim($line);if ($line==='' || str_starts_with($line,'#')) continue;
        if (!preg_match('#^[a-z][a-z0-9+.-]*://[^\x00-\x20\x7f]+$#iD',$line))
            return achbotPgFailure('pg_links_invalid',false,$r['http']);
        $links[]=$line;
    }
    $after=achbotPgRead($panel,$identity);
    if (!$after['success'] || $after['user']['proxy_settings']!==$read['user']['proxy_settings'])
        return achbotPgFailure('pg_links_identity_unconfirmed',false,$after['http']);
    return ['success'=>true,'uncertain'=>false,'reason'=>'','http'=>$r['http'],
        'links'=>$links,'subscription_url'=>$after['user']['subscription_url'],'user'=>$after['user']];
}
function achbotPgGroups(array $panel): array {
    $groups=[];$total=null;
    do {
        $r=achbotPgRequest($panel,'GET','/api/groups?offset='.count($groups).'&limit=100');
        if (!$r['success']) return $r;
        $data=$r['data'];
        if (!is_int($data['total']??null) || $data['total']<0 || $data['total']>10000 ||
            ($total!==null && $total!==$data['total']) || !is_array($data['groups']??null) ||
            !array_is_list($data['groups']) || (!$data['groups'] && count($groups)<$data['total']))
            return achbotPgFailure('pg_groups_incomplete',false,$r['http']);
        $total=$data['total'];
        foreach ($data['groups'] as $group) {
            if (!is_int($group['id']??null) || $group['id']<=0 ||
                isset($groups[$group['id']]) || !is_string($group['name']??null) ||
                !is_bool($group['is_disabled']??null) ||
                !(is_array($group['inbound_tags']??null) || array_key_exists('inbound_tags',$group) && $group['inbound_tags']===null))
                return achbotPgFailure('pg_groups_schema_invalid',false,$r['http']);
            $tags=$group['inbound_tags']??[];
            if (!array_is_list($tags)) return achbotPgFailure('pg_groups_schema_invalid',false,$r['http']);
            foreach ($tags as $tag) if (!is_string($tag) || $tag==='')
                return achbotPgFailure('pg_groups_schema_invalid',false,$r['http']);
            $groups[$group['id']]=array_intersect_key($group,array_flip(['id','name','is_disabled']));
            $groups[$group['id']]['inbound_tags']=$tags;
        }
        if (count($groups)>$total) return achbotPgFailure('pg_groups_incomplete',false,$r['http']);
    } while (count($groups)<$total);
    return ['success'=>true,'uncertain'=>false,'reason'=>'','http'=>200,'groups'=>array_values($groups)];
}
function achbotPgCheck(array $panel): array {
    $system=achbotPgRequest($panel,'GET','/api/system');
    if (!$system['success']) return $system;
    if (($system['data']['version']??null)!=='5.5.1') return achbotPgFailure('pg_version_unsupported');
    $admin=achbotPgRequest($panel,'GET','/api/admin');
    if (!$admin['success']) return $admin;
    $role=$admin['data']['role']??null;
    if (!is_array($role) || ($role['access']['require_template']??null)!==false)
        return achbotPgFailure('pg_direct_access_required');
    if (($role['is_owner']??false)!==true) {
        foreach (['create','read','update','delete','reset_usage','revoke_sub'] as $action) {
            $p=$role['permissions']['users'][$action]??null;
            if ($p!==true && !(is_array($p) && is_int($p['scope']??null) && $p['scope']>=1))
                return achbotPgFailure('pg_user_permission_required');
        }
        foreach (['read','read_simple'] as $action)
            if (($role['permissions']['groups'][$action]??null)!==true)
                return achbotPgFailure('pg_group_permission_required');
    }
    $groups=achbotPgGroups($panel);if (!$groups['success']) return $groups;
    return ['success'=>true,'uncertain'=>false,'reason'=>'','http'=>200,
        'version'=>'5.5.1','groups'=>$groups['groups'],
        'allowed_group_ids'=>$role['access']['allowed_group_ids']??null];
}

/** Native signature proof shares only the bounded credential-free transport. */
function achbotPgSubscriptionInfo(array $panel,string $trustedUrl): array {
    if (!achbotPgPanelValid($panel)) return achbotPgFailure('pg_panel_config_invalid');
    require_once __DIR__.'/ProviderImport.php';
    return achbotProviderSubscriptionInfo($trustedUrl);
}
