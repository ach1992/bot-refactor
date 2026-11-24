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
    $type=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);
    // curl_close() is a no-op and deprecated on PHP 8.5.
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
    // The token version only provides an untrusted Native-ID lookup hint.
    // A future version with the same shape needs no bot-side allowlist;
    // authenticated GET and the trusted /info identity remain mandatory.
    if (is_string($payload) && preg_match('/^v[1-9][0-9]*,([1-9][0-9]*),[0-9]+$/D',$payload,$m))
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
        achbotPgLoad();achbotProviderImportServer($db,$serverId);
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
        // Verify the submitted bearer at the trusted native endpoint; never
        // fetch or require registration of the submitted alias domain/path.
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
