<?php
/**
 * Owner-only recovery READ transport. The login and one account GET share one
 * prevalidated, pinned URL and credentials; neither rereads mutable server IDs.
 * No remote write, redirect, retry, settlement or state transition is possible.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

/** Strictly validate the exact configured URL prior to any credential request. */
function achbotRecoveryEndpoint(string $endpoint): ?string {
    if ($endpoint === '' || strlen($endpoint)>2048 || trim($endpoint)!==$endpoint ||
        preg_match('/[\x00-\x20\x7f]/', $endpoint)) return null;
    $parts=parse_url($endpoint);
    if (!is_array($parts) || !isset($parts['scheme'],$parts['host']) ||
        isset($parts['user']) || isset($parts['pass']) ||
        isset($parts['query']) || isset($parts['fragment'])) return null;
    $scheme=strtolower($parts['scheme']);
    if ($scheme!=='https' && !($scheme==='http' &&
        in_array(strtolower($parts['host']),['localhost','127.0.0.1','[::1]','::1'],true)))
        return null;
    if (preg_match('/[\x00-\x20\x7f]/', $parts['host']) ||
        str_contains($parts['host'],'%') || str_contains($parts['host'],'\\')) return null;
    return rtrim($endpoint,'/');
}

/**
 * One bounded HTTP response from the exact pinned URL. After authentication,
 * its observed destination IP is also pinned for the subsequent GET to stop
 * DNS-based destination drift while preserving the original TLS hostname.
 */
function achbotRecoveryHttp(string $url, ?array $form, array $headers,
                            int $maxBytes, ?string $pinnedIp=null): ?array {
    $parts=parse_url($url);
    if (!is_array($parts) || !isset($parts['scheme'],$parts['host'])) return null;
    $host=$parts['host'];
    $port=(int)($parts['port']??(strtolower($parts['scheme'])==='https'?443:80));
    $ch=curl_init($url);
    if ($ch===false) return null;
    $body='';
    try {
        $options=[
            CURLOPT_RETURNTRANSFER=>false,
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_MAXREDIRS=>0,
            CURLOPT_CONNECTTIMEOUT=>3,
            CURLOPT_TIMEOUT=>8,
            CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_PROXY=>'',
            CURLOPT_NOPROXY=>'*',
            CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk) use (&$body,$maxBytes): int {
                if (strlen($chunk)>$maxBytes-strlen($body)) return 0;
                $body.=$chunk;
                return strlen($chunk);
            },
        ];
        if ($form===null) {
            $options[CURLOPT_HTTPGET]=true;
        } else {
            $options[CURLOPT_POST]=true;
            $options[CURLOPT_POSTFIELDS]=http_build_query($form,'','&',PHP_QUERY_RFC3986);
        }
        if ($pinnedIp!==null) {
            if (filter_var($pinnedIp,FILTER_VALIDATE_IP)===false) return null;
            $resolvedIp=str_contains($pinnedIp,':')?'['.$pinnedIp.']':$pinnedIp;
            $options[CURLOPT_RESOLVE]=[$host.':'.$port.':'.$resolvedIp];
        }
        curl_setopt_array($ch,$options);
        $ok=curl_exec($ch);
        $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
        $address=(string)curl_getinfo($ch,CURLINFO_PRIMARY_IP);
        $effective=(string)curl_getinfo($ch,CURLINFO_EFFECTIVE_URL);
        $redirects=(int)curl_getinfo($ch,CURLINFO_REDIRECT_COUNT);
        if ($ok===false || $status!==200 || $effective!==$url ||
            $redirects!==0 || filter_var($address,FILTER_VALIDATE_IP)===false ||
            ($pinnedIp!==null && $pinnedIp!==$address)) return null;
        return ['body'=>$body,'ip'=>$address];
    } finally {
        curl_close($ch);
    }
}

/** Returns a decoded observation only, never a verdict or write authorization. */
function achbotRecoveryStrictAccountGet(string $endpoint, string $adminUser,
                                        string $adminPassword, string $username): ?object {
    $base=achbotRecoveryEndpoint($endpoint);
    if ($base===null || $adminUser==='' || $adminPassword==='' ||
        strlen($adminUser)>512 || strlen($adminPassword)>4096 ||
        preg_match('/[\r\n]/',$adminUser) ||
        preg_match('/^[a-zA-Z0-9_-]{1,100}$/D',$username)!==1)
        return null;
    try {
        $login=achbotRecoveryHttp($base.'/api/admin/token',
            ['username'=>$adminUser,'password'=>$adminPassword],
            ['Accept: application/json','Content-Type: application/x-www-form-urlencoded'],8192);
        if ($login===null) return null;
        $auth=json_decode($login['body']);
        if (!is_object($auth) || !isset($auth->access_token) ||
            !is_string($auth->access_token) ||
            preg_match('/^[a-zA-Z0-9._~+\/=\-]{1,4096}$/D',$auth->access_token)!==1)
            return null;
        if (isset($auth->token_type) && strcasecmp((string)$auth->token_type,'bearer')!==0)
            return null;
        $get=achbotRecoveryHttp($base.'/api/user/'.rawurlencode($username),null,
            ['Accept: application/json','Authorization: Bearer '.$auth->access_token],
            65536,$login['ip']);
        if ($get===null) return null;
        $result=json_decode($get['body']);
        if (!is_object($result) || isset($result->detail) ||
            (isset($result->success) && $result->success===false) ||
            !isset($result->username,$result->note,$result->expire,
                   $result->data_limit,$result->status) ||
            !is_string($result->username) || !is_string($result->note) ||
            !is_string($result->status)) return null;
        return $result;
    } catch (Throwable $ignored) {
        return null;
    }
}
