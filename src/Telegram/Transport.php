<?php
/**
 * AChBot Telegram transport. Retain public return/transport contracts, but
 * send structured diagnostics to PHP's configured error sink (not a public,
 * root-owned, unwritable webroot error_log.txt). No raw bot URL, payload,
 * Telegram description, customer text or credentials are logged.
 */
namespace AchBot\Telegram\Transport;

use CURLFile;

require_once __DIR__ . '/Response.php';

/** Safe correlation is set by the ORIGINAL financial delivery worker only. */
function diagnosticContext(): array {
    $source=$GLOBALS['achbotTelegramDiagnosticContext']??null;
    if (!is_array($source)) return [];
    $out=[];
    foreach (['pay_id','delivery_ordinal','delivery_attempt','delivery_actor'] as $k) {
        if (isset($source[$k]) && is_int($source[$k]) && $source[$k]>=0)
            $out[$k]=$source[$k];
    }
    return $out;
}

/** A finite taxonomy for Telegram rejections; no raw rejection text in logs. */
function diagnosticReason(?string $description,int $telegramCode): string {
    $s=strtolower((string)$description);
    if (str_contains($s,"can't parse entities")) return 'entities_invalid';
    if (str_contains($s,'message is not modified')) return 'message_not_modified';
    if (str_contains($s,'bot was blocked by the user')) return 'bot_blocked';
    if (str_contains($s,'chat not found')) return 'chat_not_found';
    if (str_contains($s,'user is deactivated')) return 'user_deactivated';
    if (str_contains($s,'message to edit not found')) return 'message_not_found';
    return match($telegramCode) {
        400=>'telegram_bad_request', 401=>'telegram_unauthorized',
        403=>'telegram_forbidden',404=>'telegram_not_found',
        409=>'telegram_conflict',429=>'telegram_rate_limited',
        default=>'telegram_unclassified',
    };
}

function logResult(string $runtimeDir,string $method,?array $diagnostics,
                   int $http,int $errno,float $durationMs,
                   $decoded,bool $file=false): void {
    $telegram=is_array($decoded)?$decoded:(is_object($decoded)?(array)$decoded:[]);
    $ok=($telegram['ok']??null)===true;
    $tcode=filter_var($telegram['error_code']??null,FILTER_VALIDATE_INT);
    $tcode=$tcode===false?null:$tcode;
    $retry=is_array($decoded)
        ?(int)($decoded['parameters']['retry_after']??0)
        :\AchBot\Telegram\Response\retryAfter($decoded);
    $failed=$errno!==0 || $http<200 || $http>=300 || !$ok;
    $context=diagnosticContext();
    // Do not fill a webserver log with every successful keyboard edit.
    // Always record payment-bound customer sends and ALL transport failures.
    if (!$failed && !$context && getenv('ACHBOT_TELEGRAM_LOG_SUCCESS')!=='1')
        return;
    $reason=$errno!==0 ? match($errno) {
        6=>'dns_resolution',7=>'connection_failed',
        28=>'timeout',35=>'tls_handshake',
        default=>'curl_error',
    } : ($http<200||$http>=300?'http_unexpected':
        (!$ok?($decoded===null?'response_unparseable':'telegram_rejected')
           :'acknowledged'));
    $event=[
        'event'=>'achbot.telegram.transport',
        'timestamp_utc'=>(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))
            ->format('Y-m-d\TH:i:s.u\Z'),
        'level'=>$failed?'error':'info',
        'request_id'=>bin2hex(random_bytes(8)),
        'instance'=>preg_replace('/[^a-zA-Z0-9_-]/','_',basename(rtrim($runtimeDir,'/'))),
        'method'=>preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D',$method)?$method:'invalid',
        'format'=>$file?'multipart':'form',
        'duration_ms'=>round($durationMs,2),
        'http_status'=>$http,
        'curl_errno'=>$errno,
        'reason'=>$reason,
        'telegram_ok'=>$telegram['ok']??null,
        'telegram_error_code'=>$tcode,
    ];
    if ($tcode!==null && !$ok)
        $event['telegram_reason']=diagnosticReason(
            is_string($telegram['description']??null)?$telegram['description']:null,$tcode);
    if (is_int($retry) && $retry>0) $event['retry_after']=$retry;
    $msg=is_object($telegram['result']??null)
        ?(array)$telegram['result'] :($telegram['result']??null);
    if ($ok && is_array($msg) && is_int($msg['message_id']??null))
        $event['telegram_message_id']=$msg['message_id'];
    // DNS/connect/TLS timing fields are safe and very useful for cURL 35/28.
    if (is_array($diagnostics))
        foreach (['dns_ms','connect_ms','tls_ms','total_ms'] as $key)
            if (isset($diagnostics[$key]) && is_numeric($diagnostics[$key]))
                $event[$key]=round((float)$diagnostics[$key],2);
    foreach ($context as $k=>$v) $event[$k]=$v;
    error_log('[AChBot] '.json_encode($event,
        JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_PARTIAL_OUTPUT_ON_ERROR));
}

/** Timing is read from curl after its last operation; never log IP/URL. */
function timings($ch): array {
    $out=[];
    foreach ([
        'dns_ms'=>'CURLINFO_NAMELOOKUP_TIME',
        'connect_ms'=>'CURLINFO_CONNECT_TIME',
        'tls_ms'=>'CURLINFO_APPCONNECT_TIME',
        'total_ms'=>'CURLINFO_TOTAL_TIME',
    ] as $key=>$constant) {
        if (!defined($constant)) continue;
        $value=curl_getinfo($ch,constant($constant));
        if (is_int($value)||is_float($value)) $out[$key]=$value*1000;
    }
    return $out;
}

function request($botToken,$runtimeDir,$method,$datas=[]) {
    $url="https://api.telegram.org/bot".$botToken."/".$method;
    $ch=curl_init();
    curl_setopt($ch,CURLOPT_URL,$url);
    curl_setopt($ch,CURLOPT_POST,true);
    $hasFile=false;
    if (is_array($datas))
        foreach ($datas as $v) if ($v instanceof CURLFile) {$hasFile=true;break;}
    curl_setopt($ch,CURLOPT_POSTFIELDS,$hasFile?$datas:http_build_query($datas,'','&'));
    curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
    curl_setopt($ch,CURLOPT_TIMEOUT,10);
    curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,5);
    curl_setopt($ch,CURLOPT_HTTPHEADER,['Expect:']);
    curl_setopt($ch,CURLOPT_HTTP_VERSION,CURL_HTTP_VERSION_1_1);
    curl_setopt($ch,CURLOPT_NOSIGNAL,true);
    curl_setopt($ch,CURLOPT_DNS_CACHE_TIMEOUT,300);
    if (defined('CURLOPT_DNS_SERVERS'))
        curl_setopt($ch,CURLOPT_DNS_SERVERS,'1.1.1.1,9.9.9.9,8.8.8.8');
    if (defined('CURLOPT_TCP_KEEPALIVE'))
        curl_setopt($ch,CURLOPT_TCP_KEEPALIVE,1);
    if (defined('CURLOPT_TCP_KEEPIDLE'))
        curl_setopt($ch,CURLOPT_TCP_KEEPIDLE,30);
    if (defined('CURLOPT_TCP_KEEPINTVL'))
        curl_setopt($ch,CURLOPT_TCP_KEEPINTVL,15);

    $begin=hrtime(true);
    $res=curl_exec($ch);
    $elapsed=(hrtime(true)-$begin)/1_000_000;
    $errno=curl_errno($ch);
    $http=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
    $timing=timings($ch);
    curl_close($ch);
    $decoded=is_string($res)&&$res!==''?json_decode($res):null;
    logResult($runtimeDir,$method,$timing,$http,$errno,$elapsed,$decoded,$hasFile);
    // Preserve old calling contract. Never mask an unknown send as ACK.
    if ($errno!==0) return null;
    return $decoded;
}

function sendFile($botToken,$runtimeDir,$method,$datas=[]) {
    $url="https://api.telegram.org/bot".$botToken."/".$method;
    if (isset($datas['reply_markup'])&&is_array($datas['reply_markup']))
        $datas['reply_markup']=json_encode($datas['reply_markup'],JSON_UNESCAPED_UNICODE);
    $ch=curl_init();
    curl_setopt($ch,CURLOPT_URL,$url);
    curl_setopt($ch,CURLOPT_POST,true);
    curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
    curl_setopt($ch,CURLOPT_POSTFIELDS,$datas);
    curl_setopt($ch,CURLOPT_TIMEOUT,20);
    curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,8);
    curl_setopt($ch,CURLOPT_HTTPHEADER,['Expect:']);
    curl_setopt($ch,CURLOPT_HTTP_VERSION,CURL_HTTP_VERSION_1_1);
    curl_setopt($ch,CURLOPT_NOSIGNAL,true);
    curl_setopt($ch,CURLOPT_DNS_CACHE_TIMEOUT,300);
    if (defined('CURLOPT_DNS_SERVERS'))
        curl_setopt($ch,CURLOPT_DNS_SERVERS,'1.1.1.1,9.9.9.9,8.8.8.8');
    if (defined('CURLOPT_TCP_KEEPALIVE'))
        curl_setopt($ch,CURLOPT_TCP_KEEPALIVE,1);
    if (defined('CURLOPT_TCP_KEEPIDLE'))
        curl_setopt($ch,CURLOPT_TCP_KEEPIDLE,30);
    if (defined('CURLOPT_TCP_KEEPINTVL'))
        curl_setopt($ch,CURLOPT_TCP_KEEPINTVL,15);
    $begin=hrtime(true);
    $res=curl_exec($ch);
    $elapsed=(hrtime(true)-$begin)/1_000_000;
    $errno=curl_errno($ch);
    $http=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
    $timing=timings($ch);
    curl_close($ch);
    $decoded=is_string($res)&&$res!==''?json_decode($res,true):null;
    logResult($runtimeDir,$method,$timing,$http,$errno,$elapsed,$decoded,true);
    if ($errno!==0) return false;
    // Legacy sendFile callers receive json_decode(..., true) as-is when
    // truthy, including unusual scalar JSON. Do not narrow its contract.
    return $decoded ?: false;
}
