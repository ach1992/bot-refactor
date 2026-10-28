<?php
/** AChBot existing-service proof and atomic local adoption; never remote creation. */
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}

/** Canonical URL components, never a URL which this parser authorizes fetching. */
function achbotProviderImportParts(string $url): ?array {
    $p=parse_url($url);
    if (!$p || strlen($url)>4096 || preg_match('/[\x00-\x20\x7f]/',$url) ||
        !in_array($p['scheme']??'',['https','http'],true) || empty($p['host']) ||
        isset($p['user']) || isset($p['pass']) ||
        !preg_match('~^(.*)/([^/]+)/?$~D',$p['path']??'',$m)) return null;
    $token=rawurldecode($m[2]);
    if ($token==='' || strlen($token)>255 || preg_match('~[^a-zA-Z0-9_.=-]~',$token) ||
        preg_match('~(?:^|/)\.\.?(/|$)|[\\\\\x00-\x20\x7f]~',$m[1])) return null;
    $port=(int)($p['port']??($p['scheme']==='https'?443:80));
    return ['origin'=>strtolower($p['scheme']).'://'.strtolower($p['host']).':'.$port,
        'prefix'=>$m[1],'token'=>$token];
}
function achbotProviderImportBase(string $base,string $prefix): ?array {
    $p=parse_url($base);
    if (!$p || empty($p['host']) || isset($p['user']) || isset($p['pass']) ||
        isset($p['query']) || isset($p['fragment']) || preg_match('/[\x00-\x20\x7f]/',$base)) return null;
    $path=rtrim((string)($p['path']??''),'/');
    // Native paths may already contain the deployment prefix.
    $suffix=$path!=='' && ($prefix===$path || str_starts_with($prefix,$path.'/'))?$prefix:$path.$prefix;
    $origin=$p['scheme'].'://'.$p['host'].(isset($p['port'])?':'.$p['port']:'');
    return achbotProviderImportParts($origin.$suffix.'/probe');
}
function achbotProviderImportOriginAllowed(string $supplied,string $trusted): bool {
    if ($supplied===$trusted) return true;
    $a=parse_url($supplied);$b=parse_url($trusted);
    $host=strtolower((string)($b['host']??''));
    if (!$a || !$b || !str_starts_with($host,'*.') ||
        !preg_match('/^\*\.[a-z0-9-]+(?:\.[a-z0-9-]+)+$/D',$host)) return false;
    $suffix=substr($host,1);$actual=strtolower((string)($a['host']??''));
    return $a['scheme']===$b['scheme'] && ($a['port']??null)===($b['port']??null) &&
        strlen($actual)>strlen($suffix) && str_ends_with($actual,$suffix);
}
function achbotProviderImportServer(mysqli $db,int $serverId,bool $lock=false): array {
    $s=$db->prepare('SELECT c.* FROM server_config c JOIN server_info i ON i.id=c.id
        WHERE c.id=? AND i.active=1'.($lock?' FOR UPDATE':''));
    $s->bind_param('i',$serverId);$s->execute();$r=$s->get_result()->fetch_assoc();$s->close();
    if (!$r || !achbotSupportsProviderType($r['type'])) throw new RuntimeException('import_server_unavailable');
    return $r;
}
/** Optional source-owned capability; incomplete/unknown adapters fail import only. */
function achbotProviderExistingUser(mysqli $db,int $serverId,string $input,?string $usernameHint=null): object {
    try {
        $server=achbotProviderImportServer($db,$serverId);
        $adapter=achbotProviderDefinition($server['type']);
        if (!isset($adapter['import_lookup'],$adapter['import_adopt'])) throw new RuntimeException('import_not_supported');
        return ($adapter['import_lookup'])($db,$serverId,$input,$usernameHint);
    } catch (Throwable $e) {return achbotProviderFailure('import_identity_unverified',false);}
}

/** Credential-free proof transport. Its caller must construct the URL from trusted native metadata. */
function achbotProviderSubscriptionInfo(string $trustedUrl,bool $trustedHttp=false): array {
    $p=parse_url($trustedUrl);
    $failure=static fn(int $http=0)=>['success'=>false,'uncertain'=>false,'http'=>$http,'reason'=>'subscription_proof_unverified'];
    if (!$p || empty($p['host']) || isset($p['user']) || isset($p['pass']) ||
        isset($p['query']) || isset($p['fragment']) || preg_match('/[\x00-\x20\x7f]/',$trustedUrl) ||
        !(($p['scheme']??'')==='https' || ($trustedHttp && ($p['scheme']??'')==='http') || (PHP_SAPI==='cli' &&
            defined('ACHBOT_PG_TEST_LOOPBACK') && ACHBOT_PG_TEST_LOOPBACK===true &&
            ($p['scheme']??'')==='http' && in_array($p['host'],['127.0.0.1','[::1]'],true)))) return $failure();
    $ch=curl_init($trustedUrl);$wire='';$large=false;
    curl_setopt_array($ch,[CURLOPT_HTTPGET=>true,CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>10,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_HTTPHEADER=>['Accept: application/json'],
        CURLOPT_WRITEFUNCTION=>static function($curl,string $chunk) use (&$wire,&$large):int {
            if (strlen($wire)+strlen($chunk)>1048576) {$large=true;return 0;}
            $wire.=$chunk;return strlen($chunk);
        }]);
    $ok=curl_exec($ch);$errno=curl_errno($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    $type=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);curl_close($ch);
    if ($ok===false || $errno!==0 || $large || $http!==200 ||
        !preg_match('#^application/(?:[a-z0-9.+-]+\\+)?json(?:;|$)#i',$type)) return $failure($http);
    try {$data=json_decode($wire,true,64,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}
    catch (Throwable $e) {return $failure($http);}
    return is_array($data)?['success'=>true,'uncertain'=>false,'http'=>$http,'data'=>$data]:$failure($http);
}
function achbotProviderImportDate(string $value): string {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})?$/D',$value))
        throw new RuntimeException('import_created_at_invalid');
    $date=new DateTimeImmutable($value,new DateTimeZone('UTC'));$errors=DateTimeImmutable::getLastErrors();
    if ($errors!==false && ($errors['warning_count'] || $errors['error_count']))
        throw new RuntimeException('import_created_at_invalid');
    return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
}
function achbotProviderImportTrusted(array $supplied,array $native,array $server): bool {
    $allowed=[[$native['origin'],$native['prefix']]];
    foreach (array_merge([(string)$server['panel_url']],
        array_filter(array_map('trim',explode(',',(string)($server['sub_links']??''))))) as $base) {
        $b=achbotProviderImportBase($base,$native['prefix']);
        if ($b) $allowed[]=[$b['origin'],$b['prefix']];
        $direct=achbotProviderImportParts(rtrim($base,'/').'/probe');
        if ($direct && $direct['prefix']!=='') $allowed[]=[$direct['origin'],$direct['prefix']];
        $p=parse_url($base);
        if ($p && !empty($p['host'])) {
            $origin=$p['scheme'].'://'.$p['host'].(isset($p['port'])?':'.$p['port']:'');
            $root=achbotProviderImportBase($origin,$native['prefix']);
            if ($root) $allowed[]=[$root['origin'],$root['prefix']];
        }
    }
    foreach ($allowed as [$origin,$prefix])
        if ($supplied['prefix']===$prefix && achbotProviderImportOriginAllowed($supplied['origin'],$origin)) return true;
    return false;
}
function achbotMarzbanImportTarget(array $target): array {
    return ['provider'=>'marzban','server_id'=>(int)$target['server_id'],'panel_url'=>$target['panel_url'],
        'credentials_sha256'=>hash('sha256',json_encode([$target['username'],$target['password']],JSON_THROW_ON_ERROR))];
}

function achbotMarzbanExistingUser(mysqli $db,int $serverId,string $input,?string $usernameHint=null): object {
    try {
        $supplied=achbotProviderImportParts($input);
        // The pinned native parser also accepts legacy HS256 subscription JWTs.
        // Claims locate one exact user only; the trusted /info route still proves the signature.
        if ($supplied && $usernameHint===null &&
            str_starts_with($supplied['token'],'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.')) {
            $segments=explode('.',$supplied['token']);
            if (count($segments)===3) {
                $decoded=achbotProviderImportDecode($segments[1]);
                try {$claims=json_decode((string)$decoded,true,16,JSON_THROW_ON_ERROR);}
                catch (Throwable $error) {$claims=[];}
                if (is_string($claims['sub']??null) &&
                    preg_match('/^[a-zA-Z0-9_@.-]{1,100}$/D',$claims['sub'])) $usernameHint=$claims['sub'];
            }
        }
        if (!$supplied || !is_string($usernameHint) || !preg_match('/^[a-zA-Z0-9_@.-]{1,100}$/D',$usernameHint))
            throw new RuntimeException('import_identity_unverified');
        $server=achbotProviderImportServer($db,$serverId);
        $pin=achbotProviderDeletionTarget($db,$serverId,$usernameHint);
        if (($pin['provider']??null)!=='marzban') throw new RuntimeException('import_target_invalid');
        $r=getMarzbanUser($serverId,$usernameHint,null,true,$pin['panel_url'],$pin);
        if (!is_object($r)) throw new RuntimeException('import_native_lookup_unverified');
        unset($r->provider_import_proof);
        require_once __DIR__.'/ServiceLifecycle.php';
        $observation=achbotServiceObservation($r,$usernameHint);
        if (!$observation['verified'] || !is_string($r->subscription_url??null) || !is_string($r->created_at??null))
            throw new RuntimeException('import_native_schema_invalid');
        $created=achbotProviderImportDate($r->created_at);
        $url=$r->subscription_url;
        if (str_starts_with($url,'/')) $url=rtrim($pin['panel_url'],'/').$url;
        $native=achbotProviderImportParts($url);
        if (!$native || !achbotProviderImportTrusted($supplied,$native,$server))
            throw new RuntimeException('import_origin_untrusted');
        if (!hash_equals($native['token'],$supplied['token'])) {
            $info=achbotProviderSubscriptionInfo($native['origin'].$native['prefix'].'/'.
                rawurlencode($supplied['token']).'/info',true);
            $public=$info['data']??null;
            if (($info['success']??false)!==true || !is_array($public) ||
                ($public['username']??null)!==$usernameHint || !is_string($public['created_at']??null) ||
                achbotProviderImportDate($public['created_at'])!==$created)
                throw new RuntimeException('import_token_identity_unverified');
        }
        if (!achbotProviderDeletionTargetMatches($db,$serverId,$usernameHint,$pin))
            throw new RuntimeException('import_target_changed');
        $proof=['verified'=>true,'provider'=>'marzban','server_id'=>$serverId,'input_url'=>$input,
            'input_sha256'=>hash('sha256',$input),'token'=>$supplied['token'],'expiry'=>$observation['expire'],
            'status'=>$observation['status'],'subscription_url'=>$url,'target'=>achbotMarzbanImportTarget($pin),
            'identity'=>['remote_username'=>$usernameHint,'remote_created_at'=>$created],
            'native_user'=>achbotProviderCanonicalValue($r)];
        $r->provider_import_proof=$proof;return $r;
    } catch (Throwable $e) {return achbotProviderFailure('import_identity_unverified',false);}
}
function achbotProviderImportDecode(string $value): ?string {
    if ($value==='' || !preg_match('/^[A-Za-z0-9_-]+={0,2}$/D',$value)) return null;
    $plain=rtrim($value,'=');
    if (strlen($plain)%4===1 || (str_contains($value,'=') && strlen($value)%4!==0)) return null;
    $r=base64_decode(strtr($plain,'-_','+/').str_repeat('=',(4-strlen($plain)%4)%4),true);
    return is_string($r) && rtrim(strtr(base64_encode($r),'+/','-_'),'=')===$plain?$r:null;
}
/** Payload is an untrusted exact-lookup hint. Only native signature validation proves ownership. */
function achbotPgImportHint(string $token): array {
    $parts=explode('.',$token);$payload=null;
    if (count($parts)===3) {
        $json=achbotProviderImportDecode($parts[1]);
        try {$claims=json_decode((string)$json,true,16,JSON_THROW_ON_ERROR);}
        catch (Throwable $e) {$claims=[];}
        $sub=$claims['sub']??null;
        if (is_string($sub) && preg_match('/^[a-zA-Z0-9_@.-]{1,100}$/D',$sub))
            return ['username'=>$sub];
    }
    if (count($parts)===2) $payload=achbotProviderImportDecode($parts[0]);
    if ($payload===null) $payload=achbotProviderImportDecode(substr($token,0,-10));
    if ($payload===null) $payload=achbotProviderImportDecode($token);
    if (is_string($payload) && preg_match('/^v[23],([1-9][0-9]*),[0-9]+$/D',$payload,$m))
        return ['id'=>achbotProviderPositiveInt($m[1])];
    if (is_string($payload) && preg_match('/^([a-zA-Z0-9_@.-]{1,100}),[0-9]+$/D',$payload,$m))
        return ['username'=>$m[1]];
    throw new RuntimeException('import_token_hint_invalid');
}
function achbotPgImportTarget(array $panel): array {
    return ['provider'=>'pasarguard','panel_id'=>$panel['id'],'instance_key'=>$panel['instance_key'],
        'endpoint_sha256'=>hash('sha256',rtrim($panel['api_url'],'/')),
        'credentials_sha256'=>hash('sha256',$panel['api_key'])];
}
/** Native subscriptions rotate on read. Validate the supplied older token at a trusted /info route. */
function achbotPasarGuardExistingUser(mysqli $db,int $serverId,string $input,?string $usernameHint=null): object {
    try {
        achbotPgLoad();$server=achbotProviderImportServer($db,$serverId);
        $panel=achbotPgServerPanel($db,$serverId);$supplied=achbotProviderImportParts($input);
        if (!$supplied) throw new RuntimeException('import_url_invalid');
        $hint=achbotPgImportHint($supplied['token']);
        $path=isset($hint['id'])?'/api/user/by-id/'.$hint['id']:
            '/api/user/by-username/'.rawurlencode($hint['username']);
        $r=achbotPgUserResult(achbotPgRequest($panel,'GET',$path));
        if (($r['success']??false)!==true) throw new RuntimeException('import_native_lookup_unverified');
        $u=$r['user'];
        if ((isset($hint['id']) && $hint['id']!==$u['id']) ||
            (isset($hint['username']) && $hint['username']!==$u['username'])) throw new RuntimeException('import_hint_mismatch');
        $native=achbotProviderImportParts($u['subscription_url']);
        if (!$native) throw new RuntimeException('import_native_url_invalid');
        if (!achbotProviderImportTrusted($supplied,$native,$server)) throw new RuntimeException('import_origin_untrusted');
        $info=achbotPgSubscriptionInfo($panel,$native['origin'].$native['prefix'].'/'.
            rawurlencode($supplied['token']).'/info');
        if (($info['success']??false)!==true) throw new RuntimeException('import_token_unverified');
        $pub=$info['data'];
        if (!is_int($pub['id']??null) || $pub['id']!==$u['id'] ||
            ($pub['username']??null)!==$u['username'] ||
            !is_string($pub['created_at']??null) || achbotPgDate($pub['created_at'])!==$u['created_at'])
            throw new RuntimeException('import_token_identity_mismatch');
        $target=achbotPgImportTarget($panel);
        if ($target!==achbotPgImportTarget(achbotPgServerPanel($db,$serverId)))
            throw new RuntimeException('import_target_changed');
        $identity=['remote_user_id'=>$u['id'],'remote_created_at'=>$u['created_at'],'remote_username'=>$u['username']];
        $proof=['verified'=>true,'provider'=>'pasarguard','server_id'=>$serverId,
            'input_sha256'=>hash('sha256',$input),'token'=>$supplied['token'],'expiry'=>$u['expire'],
            'status'=>$u['status'],'subscription_url'=>$u['subscription_url'],'input_url'=>$input,
            'identity_key'=>hash('sha256',json_encode(['pasarguard',$panel['instance_key'],$u['id'],$u['created_at']],JSON_THROW_ON_ERROR)),
            'identity'=>$identity,'target'=>$target,'native_user'=>$u];
        $u['provider']='pasarguard';$u['provider_import_proof']=$proof;
        return (object)$u;
    } catch (Throwable $e) {return achbotProviderFailure('import_identity_unverified',false);}
}
function achbotProviderAdoptExistingLocked(mysqli $db,int $serverId,object $remote,array $proof,
    int $finalUserId,int $orderId,int $actorId,int $primary): void {
    $server=achbotProviderImportServer($db,$serverId,true);
    $adapter=achbotProviderDefinition($server['type']);
    if (!isset($adapter['import_lookup'],$adapter['import_adopt'])) throw new RuntimeException('import_not_supported');
    ($adapter['import_adopt'])($db,$serverId,$remote,$proof,$finalUserId,$orderId,$actorId,$primary);
}
function achbotMarzbanAdoptExistingLocked(mysqli $db,int $serverId,object $remote,array $proof,
    int $finalUserId,int $orderId,int $actorId,int $primary): void {
    require_once __DIR__.'/ProviderStorage.php';
    require_once __DIR__.'/../Purchase/OrderWrites.php';
    // Every attachment records its nonfinancial origin, including pre-007 installations.
    $s=$db->query("SELECT TABLE_TYPE,ENGINE FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='service_effects'");
    if (!($s instanceof mysqli_result)) throw new RuntimeException('import_effect_schema_unavailable');
    $table=$s->fetch_assoc();$s->free();
    $hasEffects=$table!==null;
    if ($hasEffects) {
        if ($table['TABLE_TYPE']!=='BASE TABLE' || strcasecmp((string)$table['ENGINE'],'InnoDB')!==0)
            throw new RuntimeException('import_effect_schema_invalid');
        require_once __DIR__.'/../Finance/ServiceEffects.php';
        achbotEffectRequireUniqueLine($db);
    }
    $tx=achbotProviderRow($db,'SELECT @@in_transaction AS tx');
    if ((int)($tx['tx']??0)!==1 || min($serverId,$finalUserId,$orderId,$actorId)<=0 ||
        ($proof['verified']??null)!==true || ($proof['provider']??null)!=='marzban' ||
        ($proof['server_id']??null)!==$serverId || !is_string($proof['input_url']??null) ||
        !is_string($proof['input_sha256']??null) ||
        !hash_equals($proof['input_sha256'],hash('sha256',$proof['input_url'])) ||
        ($remote->username??null)!==($proof['identity']['remote_username']??null))
        throw new RuntimeException('import_proof_invalid');
    $server=achbotProviderImportServer($db,$serverId,true);
    $fresh=achbotMarzbanExistingUser($db,$serverId,$proof['input_url'],$remote->username);
    $confirmed=$fresh->provider_import_proof??null;
    if (!is_array($confirmed) || $confirmed['target']!==($proof['target']??null) ||
        $confirmed['identity']!==($proof['identity']??null) || $confirmed['token']!==($proof['token']??null))
        throw new RuntimeException('import_proof_changed');
    $s=achbotProviderStatement($db,'SELECT userid,isAdmin,step FROM users WHERE userid=? LIMIT 2 FOR UPDATE','i',[$actorId]);
    $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
    if (count($rows)!==1 || $rows[0]['step']==='banned' ||
        ($finalUserId!==$actorId && $actorId!==$primary && (int)$rows[0]['isAdmin']!==1))
        throw new RuntimeException('import_actor_invalid');
    $s=achbotProviderStatement($db,'SELECT userid FROM users WHERE userid=? LIMIT 2 FOR UPDATE','i',[$finalUserId]);
    $n=$s->get_result()->num_rows;$s->close();
    if ($n!==1) throw new RuntimeException('import_owner_invalid');
    $order=achbotProviderRow($db,'SELECT * FROM orders_list WHERE id=? FOR UPDATE','i',[$orderId]);
    if (!$order || (int)$order['userid']!==$finalUserId || (int)$order['server_id']!==$serverId ||
        (int)$order['fileid']!==0 || (int)$order['inbound_id']!==0 || (int)$order['amount']!==0 ||
        (int)$order['status']!==1 || ($order['autoRenew']??null)!=='off' ||
        $order['remark']!==$remote->username || $order['token']!==$confirmed['token'])
        throw new RuntimeException('import_order_invalid');
    $credential=achbotProviderRow($db,'SELECT token FROM add_link WHERE added_by=? AND remark=? AND BINARY token=BINARY ?
        LIMIT 1 FOR UPDATE','iss',[$actorId,$remote->username,$confirmed['token']]);
    if (!$credential) throw new RuntimeException('import_credential_missing');
    $group=null;
    if ($hasEffects) {
        $binding=achbotProviderRow($db,"SELECT i.panel_group_id,g.value AS group_name FROM server_info i
            LEFT JOIN setting g ON g.id=i.panel_group_id AND g.type='ACHBOT_MARZBAN_PANEL_GROUP'
            WHERE i.id=? FOR UPDATE",'i',[$serverId]);
        if (!$binding || ($binding['panel_group_id']!==null &&
            (!is_string($binding['group_name']) || !achbotMarzbanGroupValid($binding['group_name']))))
            throw new RuntimeException('import_group_invalid');
        $group=$binding['panel_group_id']===null?null:(int)$binding['panel_group_id'];
    }
    // This journal records a local attachment, not a second physical CREATE.
    $key='attachment:'.$orderId;
    $observation=$confirmed['expiry']??0;$expiry=$observation;
    if ((string)$order['expire_date']!==(string)$expiry) {
        $s=achbotProviderStatement($db,'UPDATE orders_list SET expire_date=? WHERE id=?','ii',[$expiry,$orderId]);
        if ($s->affected_rows!==1) {$s->close();throw new RuntimeException('import_order_unconfirmed');}$s->close();
    }
    $snapshot=['provider'=>'marzban','server_id'=>$serverId,'target'=>$confirmed['target'],
        'identity'=>$confirmed['identity'],'native_user'=>$confirmed['native_user'],
        'order_id'=>$orderId,'owner_userid'=>$finalUserId,'actor_userid'=>$actorId,
        'input_sha256'=>$confirmed['input_sha256'],'token_sha256'=>hash('sha256',$confirmed['token'])];
    $hash=hash('sha256',json_encode($snapshot,JSON_THROW_ON_ERROR));
    achbotRecordImportedOrderOriginLocked($db,$orderId,$finalUserId,$actorId,'marzban',$serverId,
        $remote->username,$hash,$confirmed['identity']);
    if (!$hasEffects) return;
    $operation=bin2hex(random_bytes(16));$bytes=$fresh->data_limit??0;
    $s=achbotProviderStatement($db,"INSERT INTO service_effects
        (pay_row_id,source_kind,source_ref,actor_userid,ordinal,reserved_count,operation_id,effect_kind,
         target_server_id,target_group_id,target_key,endpoint_sha256,plan_id,remote_username,
         requested_expire,requested_bytes,request_sha256,state,order_id,verified_at)
        VALUES (NULL,'external_import',?,?,1,0,?,'adopt',?,?,?,?,0,?,?,?,?,'local_recorded',?,UTC_TIMESTAMP())",
        'sisiisssiisi',['order:'.$orderId,$actorId,$operation,$serverId,$group,$key,
            hash('sha256',rtrim($server['panel_url'],'/')),$remote->username,$expiry,$bytes,$hash,$orderId]);
    if ($s->affected_rows!==1) {$s->close();throw new RuntimeException('import_adoption_unconfirmed');}$s->close();
}
function achbotPasarGuardAdoptExistingLocked(mysqli $db,int $serverId,object $remote,array $proof,
    int $finalUserId,int $orderId,int $actorId,int $primary): void {
    achbotPgLoad();achbotProviderRequireSchema($db);
    $tx=achbotProviderRow($db,'SELECT @@in_transaction AS tx');
    if ((int)($tx['tx']??0)!==1) throw new RuntimeException('import_transaction_required');
    if (($proof['verified']??null)!==true || ($proof['provider']??null)!=='pasarguard' ||
        ($proof['server_id']??null)!==$serverId || !is_string($proof['input_url']??null) ||
        !is_string($proof['input_sha256']??null) ||
        !hash_equals($proof['input_sha256'],hash('sha256',$proof['input_url'])) ||
        ($remote->username??null)!==($proof['identity']['remote_username']??null))
        throw new RuntimeException('import_proof_invalid');
    // Lock current configuration before the final native proof; no remote mutation is performed.
    $server=achbotProviderImportServer($db,$serverId,true);
    $binding=achbotProviderRow($db,'SELECT c.provider_connection_id,c.type,c.panel_url FROM server_info i
        JOIN server_config c ON c.id=i.id WHERE i.id=? FOR UPDATE','i',[$serverId]);
    $panelId=achbotProviderPositiveInt($binding['provider_connection_id']??null);
    $locked=achbotProviderPanel($db,$panelId,true);
    if ($binding['type']!=='pasarguard' || rtrim($binding['panel_url'],'/')!==rtrim($locked['api_url'],'/') ||
        achbotPgImportTarget($locked)!==($proof['target']??null)) throw new RuntimeException('import_target_changed');
    $fresh=achbotPasarGuardExistingUser($db,$serverId,$proof['input_url']);
    $confirmed=$fresh->provider_import_proof??null;
    if (!is_array($confirmed) || $confirmed['target']!==($proof['target']??null) ||
        $confirmed['identity']!==($proof['identity']??null) || $confirmed['token']!==($proof['token']??null))
        throw new RuntimeException('import_proof_changed');
    // Usage, derived status and generated URLs can change between preview and confirmation.
    // The final authenticated observation is the actual persisted entitlement snapshot.
    achbotProviderAdoptAccount($db,$serverId,$confirmed['native_user'],$confirmed,$finalUserId,$orderId,$actorId,$primary);
}
