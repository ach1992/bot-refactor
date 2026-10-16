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

function curl_init() { return (object)['mock'=>true]; }
function curl_setopt($handle, $option, $value) { return true; }
function curl_close($handle) {}
function curl_errno($handle) {
    return str_contains($GLOBALS['owned_curl_mode'], 'curl_error') ? 28 : 0;
}
function curl_error($handle) { return 'PRIVATE-TRANSPORT-ERROR-SENTINEL'; }
function curl_getinfo($handle, $opt) {
    return str_contains($GLOBALS['owned_curl_mode'], 'http_500') ? 500
        : ($GLOBALS['owned_curl_mode']==='rate_429' ? 429 : 200);
}
function curl_exec($handle) {
    $mode=$GLOBALS['owned_curl_mode'];
    if (str_contains($mode, 'curl_error')) { return false; }
    if (str_contains($mode, 'http_500')) {
        return '{"ok":false,"description":"PRIVATE-TELEGRAM-RESPONSE-SENTINEL"}';
    }
    if ($mode==='rate_429') {
        return '{"ok":false,"error_code":429,"parameters":{"retry_after":7}}';
    }
    return '{"ok":true,"result":{"message_id":10}}';
}

require dirname(__DIR__) . '/src/Telegram/Transport.php';

$runtime=sys_get_temp_dir().'/achbot-telegram-log-'.bin2hex(random_bytes(8));
if (!mkdir($runtime,0700)) {
    throw new RuntimeException('Could not create owned test directory');
}
$log=$runtime.'/error_log.txt';
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
    ['file_curl_error','file',true],
    ['file_http_500','file',true],
    ['success','request',false],
    ['success','file',false],
];
$passed=0;
try {
    foreach ($cases as [$mode,$kind,$mustLog]) {
        $GLOBALS['owned_curl_mode']=$mode;
        if (is_file($log)) unlink($log);
        $result=$kind==='request'
            ? \AchBot\Telegram\Transport\request('PRIVATE-BOT-TOKEN-SENTINEL',
                $runtime,'sendMessage',$payload)
            : \AchBot\Telegram\Transport\sendFile('PRIVATE-BOT-TOKEN-SENTINEL',
                $runtime,'sendDocument',$payload);
        if (str_contains($mode,'curl_error')) {
            if ($result!==($kind==='request' ? null : false)) {
                throw new RuntimeException('cURL error contract drift: '.$mode);
            }
        } elseif ($kind==='request') {
            if (!is_object($result) || ($result->ok ?? null)!==($mode==='success')) {
                throw new RuntimeException('Telegram response object contract drift: '.$mode);
            }
        } elseif (!is_array($result) ||
            ($result['ok']??null)!==($mode==='success')) {
            throw new RuntimeException('Telegram file response contract drift: '.$mode);
        }

        if ($mustLog) {
            if (!is_file($log)) {
                throw new RuntimeException('Missing diagnostic log for '.$mode);
            }
            $content=file_get_contents($log);
            if (!str_contains($content,'Method: send')) {
                throw new RuntimeException('Missing safe transport operation detail');
            }
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
    if (is_file($log)) unlink($log);
    rmdir($runtime);
}
echo json_encode(['complete'=>true,'cases'=>$passed,'network_requests'=>0,
                  'raw_payload_log_records'=>0,'php'=>PHP_VERSION_ID], JSON_THROW_ON_ERROR);
