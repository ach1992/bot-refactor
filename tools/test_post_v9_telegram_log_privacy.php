<?php
/** Isolated current-runtime Telegram transport privacy regression.
 * Run with php -n: all curl calls are synthetic and cannot contact Telegram.
 */
if (PHP_SAPI !== 'cli' || extension_loaded('curl')) {
    fwrite(STDERR, "Run only with PHP CLI -n, without cURL.\n");
    exit(3);
}

$curlConstants = [
    'CURLOPT_URL', 'CURLOPT_POST', 'CURLOPT_POSTFIELDS', 'CURLOPT_RETURNTRANSFER',
    'CURLOPT_TIMEOUT', 'CURLOPT_CONNECTTIMEOUT', 'CURLOPT_IPRESOLVE',
    'CURL_IPRESOLVE_V4', 'CURLOPT_HTTPHEADER', 'CURLOPT_HTTP_VERSION',
    'CURL_HTTP_VERSION_1_1', 'CURLOPT_NOSIGNAL', 'CURLOPT_DNS_CACHE_TIMEOUT',
    'CURLINFO_RESPONSE_CODE',
];
foreach ($curlConstants as $i => $name) {
    if (!defined($name)) {
        define($name, 1000 + $i);
    }
}
$GLOBALS['owned_curl_mode'] = 'success';

function curl_init() {
    $GLOBALS['owned_curl_options'] = [];
    return (object)['mock'=>true];
}
function curl_setopt($handle, $option, $value) {
    $GLOBALS['owned_curl_options'][$option] = $value;
    return true;
}
function curl_close($handle) {}
function curl_errno($handle) {
    return str_contains($GLOBALS['owned_curl_mode'], 'curl_error') ? 28 : 0;
}
function curl_error($handle) { return 'PRIVATE-TRANSPORT-ERROR-SENTINEL'; }
function curl_getinfo($handle, $opt) {
    return match($GLOBALS['owned_curl_mode']) {
        'request_http_500','file_http_500','journal_conflict_429','journal_conflict_ack'=>500,
        'entities_400'=>400,'blocked_403'=>403,
        'rate_429','journal_rate_429'=>429,'journal_conflict_entities'=>503,default=>200,
    };
}
function curl_exec($handle) {
    $mode=$GLOBALS['owned_curl_mode'];
    if (str_contains($mode, 'curl_error')) { return false; }
    if (str_contains($mode, 'http_500')) {
        return '{"ok":false,"description":"PRIVATE-TELEGRAM-RESPONSE-SENTINEL"}';
    }
    if (in_array($mode,['journal_rate_429','journal_conflict_429'],true)) {
        return '{"ok":false,"error_code":429,"description":"Too Many Requests","parameters":{"retry_after":7}}';
    }
    if ($mode==='journal_conflict_entities') return '{"ok":false,"error_code":400,"description":"can\'t parse entities"}';
    if ($mode==='rate_429') {
        return '{"ok":false,"error_code":429,"parameters":{"retry_after":7}}';
    }
    if ($mode==='entities_400') {
        return '{"ok":false,"error_code":400,"description":"Bad Request: can\'t parse entities in PRIVATE-CUSTOMER-ERROR-AND-SQLSTATE-SENTINEL"}';
    }
    if ($mode==='blocked_403') {
        return '{"ok":false,"error_code":403,"description":"Forbidden: bot was blocked by the user"}';
    }
    if ($mode==='scalar_file' || $mode==='scalar_request')
        return '"synthetic-unusual-telegram-scalar"';
    return '{"ok":true,"result":{"message_id":10}}';
}

require dirname(__DIR__) . '/src/Telegram/Transport.php';

$runtime=sys_get_temp_dir().'/achbot-telegram-log-'.bin2hex(random_bytes(8));
if (!mkdir($runtime,0700)) {
    throw new RuntimeException('Could not create owned test directory');
}
$webLog=$runtime.'/private-webhook.log';
$cronLog=$runtime.'/private-cron.log';
$oldErrorLog=ini_get('error_log');
$payload=[
    'chat_id'=>'PRIVATE-USER-ID-SENTINEL',
    'text'=>'PRIVATE-CUSTOMER-ERROR-AND-SQLSTATE-SENTINEL',
    'api_key'=>'PRIVATE-MERCHANT-KEY-SENTINEL',
    'document'=>'PRIVATE-FILE-PATH-SENTINEL',
];
$cases=[
    ['request_curl_error','request',true],
    ['request_http_500','request',true],
    ['rate_429','request',true],
    ['entities_400','request',true],
    ['blocked_403','file',true],
    ['financial_success','request',true],
    ['cron_sink_429','request',true],
    ['scalar_request','request',true],
    ['scalar_file','file',true],
    ['file_curl_error','file',true],
    ['file_http_500','file',true],
    ['success','request',false],
    ['success','file',false],
    ['journal_success','request',true],
    ['journal_rate_429','request',true],
    ['journal_conflict_429','request',true],
    ['journal_conflict_ack','request',true],
    ['journal_conflict_entities','request',true],
];
$passed=0;
try {
    foreach ($cases as [$mode,$kind,$mustLog]) {
        $log=$mode==='cron_sink_429'?$cronLog:$webLog;
        ini_set('error_log',$log);
        $GLOBALS['owned_curl_mode']=match($mode) {
            'financial_success'=>'success', 'cron_sink_429'=>'rate_429',default=>$mode,
        };
        foreach ([$webLog,$cronLog] as $path) if (is_file($path)) unlink($path);
        $GLOBALS['achbotTelegramDiagnosticContext']=$mode==='financial_success'
            ? ['pay_id'=>1234,'delivery_ordinal'=>1,'delivery_attempt'=>2,'delivery_actor'=>9014]
            : (str_starts_with($mode,'journal_')?['service_operation_id'=>456,'delivery_id'=>789,'delivery_part'=>2,'delivery_attempt'=>3]:null);
        $result=$kind==='request'
            ? \AchBot\Telegram\Transport\request('PRIVATE-BOT-TOKEN-SENTINEL',
                $runtime,'sendMessage',$payload)
            : \AchBot\Telegram\Transport\sendFile('PRIVATE-BOT-TOKEN-SENTINEL',
                $runtime,'sendDocument',$payload);
        // Let libcurl select a working address family on IPv4-only and dual-stack hosts.
        if (array_key_exists(CURLOPT_IPRESOLVE, $GLOBALS['owned_curl_options'])) {
            throw new RuntimeException('Telegram transport forced an IP family: '.$mode);
        }
        if (str_contains($mode,'curl_error')) {
            if ($result!==($kind==='request' ? null : false)) {
                throw new RuntimeException('cURL error contract drift: '.$mode);
            }
        } elseif (str_starts_with($mode,'journal_conflict_')) {
            if ($result!==null) throw new RuntimeException('Contradictory HTTP response certified: '.$mode);
        } elseif ($mode==='journal_rate_429') {
            if (\AchBot\Telegram\Response\deliveryOutcome($result)!==['result'=>'rejected','code'=>429,'retry_after'=>7])
                throw new RuntimeException('Confirmed rate limit lost');
        } elseif (in_array($mode,['scalar_request','scalar_file'],true)) {
            if ($result!=='synthetic-unusual-telegram-scalar')
                throw new RuntimeException('Legacy scalar response contract drift: '.$mode);
        } elseif ($kind==='request') {
            if (!is_object($result) || ($result->ok ?? null)!==in_array($mode,['success','financial_success','journal_success'],true)) {
                throw new RuntimeException('Telegram response object contract drift: '.$mode);
            }
        } elseif (!is_array($result) ||
            ($result['ok']??null)!==in_array($mode,['success','financial_success','journal_success'],true)) {
            throw new RuntimeException('Telegram file response contract drift: '.$mode);
        }

        if ($mustLog) {
            if (!is_file($log)) {
                throw new RuntimeException('Missing diagnostic log for '.$mode);
            }
            $content=file_get_contents($log);
            if (!str_contains($content,'"event":"achbot.telegram.transport"') ||
                !str_contains($content,'"method":"send') ||
                !str_contains($content,'"duration_ms":') ||
                !str_contains($content,'"request_id":') ||
                !str_contains($content,'"curl_errno":') ||
                !str_contains($content,'"http_status":') ||
                !str_contains($content,'"reason":')) {
                throw new RuntimeException('Missing structured transport identity and diagnostics: '.$mode);
            }
            $json=substr($content,strpos($content,'{'));
            $item=json_decode($json,true,512,JSON_THROW_ON_ERROR);
            if (in_array($mode,['rate_429','cron_sink_429'],true) &&
                ($item['retry_after']??null)!==7)
                throw new RuntimeException('Missing Telegram retry_after');
            if ($mode==='request_curl_error' && ($item['curl_errno']??null)!==28)
                throw new RuntimeException('Missing numeric cURL reason');
            if ($mode==='entities_400' && ($item['telegram_error_code']??null)!==400 ||
                $mode==='entities_400' && ($item['telegram_reason']??null)!=='entities_invalid')
                throw new RuntimeException('Missing precise Telegram entity rejection');
            if ($mode==='blocked_403' && ($item['telegram_error_code']??null)!==403 ||
                $mode==='blocked_403' && ($item['telegram_reason']??null)!=='bot_blocked')
                throw new RuntimeException('Missing Telegram blocked-user classification');
            if ($mode==='financial_success') {
                foreach (['pay_id'=>1234,'delivery_ordinal'=>1,'delivery_attempt'=>2,
                          'delivery_actor'=>9014,'telegram_message_id'=>10] as $key=>$value)
                    if (($item[$key]??null)!==$value)
                        throw new RuntimeException('Payment correlation missing '.$key);
                if (($item['telegram_ok']??null)!==true)
                    throw new RuntimeException('ACK not recorded for financial customer send');
            } elseif (str_starts_with($mode,'journal_')) {
                foreach (['service_operation_id'=>456,'delivery_id'=>789,'delivery_part'=>2,'delivery_attempt'=>3] as $key=>$value)
                    if (($item[$key]??null)!==$value) throw new RuntimeException('Part correlation missing '.$key);
            } elseif (isset($item['pay_id']) || isset($item['delivery_actor']) || isset($item['delivery_part'])) {
                throw new RuntimeException('Unrelated Telegram call inherited delivery context');
            }
            if ($mode==='cron_sink_429' && is_file($webLog))
                throw new RuntimeException('Cron fixture wrote into webhook sink');
            foreach ([
                'PRIVATE-BOT-TOKEN-SENTINEL',
                'PRIVATE-USER-ID-SENTINEL',
                'PRIVATE-CUSTOMER-ERROR-AND-SQLSTATE-SENTINEL',
                'PRIVATE-MERCHANT-KEY-SENTINEL',
                'PRIVATE-FILE-PATH-SENTINEL',
                'PRIVATE-TELEGRAM-RESPONSE-SENTINEL',
                'PRIVATE-TRANSPORT-ERROR-SENTINEL'
            ] as $sensitive) {
                if (str_contains($content, $sensitive)) {
                    throw new RuntimeException('Sensitive transport data leaked: '.$mode);
                }
            }
        } elseif (is_file($log)) {
            throw new RuntimeException('Successful synthetic Telegram call produced error log');
        }
        $passed++;
    }
} finally {
    unset($GLOBALS['achbotTelegramDiagnosticContext']);
    ini_set('error_log',(string)$oldErrorLog);
    foreach ([$webLog,$cronLog] as $path) if (is_file($path)) unlink($path);
    rmdir($runtime);
}
echo json_encode(['complete'=>true,'cases'=>$passed,'network_requests'=>0,
                  'raw_payload_log_records'=>0,'php'=>PHP_VERSION_ID], JSON_THROW_ON_ERROR);
