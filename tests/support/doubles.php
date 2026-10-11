<?php
// Test-only doubles. Run exclusively through tests/run.py under php -n and a private sandbox.
$GLOBALS['ach_trace'] = [];
$GLOBALS['ach_errors'] = [];
$GLOBALS['ach_reads'] = [];
$GLOBALS['ach_http'] = [];
$GLOBALS['ach_panel'] = [];
$GLOBALS['ach_result'] = null;
$GLOBALS['ach_rand'] = 0;
function achEvent($kind, $data = []) { $GLOBALS['ach_trace'][] = ['kind'=>$kind] + $data; }
function achFail($message) { $GLOBALS['ach_errors'][] = $message; throw new RuntimeException($message); }
function achSql($sql) {
    // Normalize layout only outside literals/quoted identifiers; their whitespace is observable data.
    $pattern = <<<'REGEX'
/'(?:''|\\.|[^'\\])*'|"(?:""|\\.|[^"\\])*"|`(?:``|[^`])*`|(\s+)/s
REGEX;
    return trim(preg_replace_callback($pattern, fn($m)=>isset($m[1])?' ':$m[0], $sql));
}
function achRead($pattern, $rows, $extra = []) { $GLOBALS['ach_reads'][] = ['pattern'=>$pattern,'rows'=>$rows]+$extra; }
function achExpect($condition, $message) { if (!$condition) achFail('Assertion: '.$message); }
function achHttp($method, $response, $http = 200, $errno = 0) {
    $GLOBALS['ach_http'][] = ['method'=>$method, 'body'=>is_string($response)?$response:json_encode($response), 'http'=>$http,'errno'=>$errno];
}
// Frozen dependencies, not application reimplementations.
function time() { return 1800000000; }
function microtime($as_float = false) { return $as_float ? time()+0.125 : '0.12500000 '.time(); }
function rand($min = 0, $max = 2147483647) { return $min + (1 + 37 * $GLOBALS['ach_rand']++) % ($max-$min+1); }
function mt_rand($min = 0, $max = 2147483647) { return rand($min,$max); }
function random_int($min, $max) { return rand($min,$max); }
function random_bytes($length) { return str_repeat('A', $length); }
function openssl_random_pseudo_bytes($length, &$strong_result = null) { $strong_result=true; return random_bytes($length); }
function uniqid($prefix = '', $more_entropy = false) { return $prefix.'fixture'.($GLOBALS['ach_rand']++); }
function date($format, $timestamp = null) {
    return (new DateTimeImmutable('@'.($timestamp ?? time())))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format($format);
}
function strtotime($datetime, $baseTimestamp = null) {
    try { return (new DateTimeImmutable('@'.($baseTimestamp ?? time())))->setTimezone(new DateTimeZone(date_default_timezone_get()))->modify($datetime)->getTimestamp(); }
    catch (Throwable $e) { return false; }
}
function sleep($seconds) { achEvent('sleep',['seconds'=>$seconds]); return 0; }
function usleep($microseconds) { achEvent('usleep',['microseconds'=>$microseconds]); }
function set_time_limit($seconds) { return true; }

class AchResult {
    public int $num_rows;
    private array $rows;
    private int $offset = 0;
    function __construct(array $rows) { $this->rows=$rows; $this->num_rows=count($rows); }
    function fetch_assoc() { return $this->rows[$this->offset++] ?? null; }
    function fetch_all($mode = 1) { return $this->rows; }
    function fetch_row() { $r=$this->fetch_assoc(); return $r===null ? null : array_values($r); }
    function data_seek($offset) { $this->offset=$offset; return true; }
    function free() {}
}
class mysqli {
    public $connect_error = null;
    public $connect_errno = 0;
    public $error = '';
    public $insert_id = 7001;
    public $affected_rows = 1;
    function __construct(...$args) { achEvent('db.connect',['fake'=>true]); }
    function set_charset($charset) { achEvent('db.charset',['charset'=>$charset]); return true; }
    function ping() { return !($GLOBALS['ach_db_ping_fail'] ?? false); }
    function prepare($sql) { return new AchStatement($sql); }
    function query($sql) { $s=new AchStatement($sql); $s->execute(); return $s->get_result(); }
    function begin_transaction(...$args) { achEvent('db.begin'); return true; }
    function commit() { achEvent('db.commit'); return true; }
    function rollback() { achEvent('db.rollback'); return true; }
    function close() { return true; }
    function real_escape_string($s) { return addslashes($s); }
}
class AchStatement {
    public $affected_rows = 1;
    public $insert_id = 7001;
    public $error = '';
    private $sql;
    private $types = '';
    private $bound = [];
    private $rows = [];
    function __construct($sql) { $this->sql=achSql($sql); }
    function bind_param($types, &...$params) {
        if (strlen($types)!==count($params)) achFail('bind_param arity mismatch');
        $this->types=$types; $this->bound=$params; return true;
    }
    function execute($params = null) {
        $values = $params ?? array_values($this->bound);
        // Chosen fixtures use placeholders outside string literals; SQL engine semantics are checked separately.
        $withoutLiterals=preg_replace("/'(?:''|\\\\.|[^'])*'/s", "''", $this->sql);
        if (substr_count($withoutLiterals,'?')!==count($values)) achFail('SQL placeholder/bind arity mismatch: '.$this->sql);
        achEvent('db.sql',['sql'=>$this->sql,'types'=>$this->types,'params'=>$values]);
        if (preg_match('/^(SELECT|SHOW)\b/i',$this->sql)) {
            $script=array_shift($GLOBALS['ach_reads']);
            if ($script===null || !preg_match($script['pattern'],$this->sql)) achFail('Unscripted SQL read: '.$this->sql);
            $this->rows=$script['rows'];
            if (!empty($script['throws'])) throw new Exception($script['throws']);
        }
        if (isset($GLOBALS['ach_write_failure']) && preg_match($GLOBALS['ach_write_failure'],$this->sql)) {
            unset($GLOBALS['ach_write_failure']); throw new Exception('fixture-write-failure');
        }
        return true;
    }
    function get_result() { return new AchResult($this->rows); }
    function close() { return true; }
}
function mysqli_num_rows($result) { return $result->num_rows; }
function mysqli_fetch_assoc($result) { return $result->fetch_assoc(); }
function mysqli_real_escape_string($connection,$value) { return $connection->real_escape_string($value); }
if (!defined('MYSQLI_ASSOC')) define('MYSQLI_ASSOC',1);
if (!defined('MYSQLI_NUM')) define('MYSQLI_NUM',2);
if (!defined('MYSQLI_BOTH')) define('MYSQLI_BOTH',3);

class CURLFile {
    function __construct(public string $name, public string $mime='', public string $postname='') {}
}
class AchCurl { public array $options=[]; public array $response=[]; }
// Named stand-in option values retain observability without loading the native network extension.
$names = ['CURLOPT_URL','CURLOPT_POST','CURLOPT_POSTFIELDS','CURLOPT_RETURNTRANSFER','CURLOPT_TIMEOUT','CURLOPT_CONNECTTIMEOUT',
 'CURLOPT_IPRESOLVE','CURL_IPRESOLVE_V4','CURLOPT_HTTPHEADER','CURLOPT_HTTP_VERSION','CURL_HTTP_VERSION_1_1','CURLOPT_NOSIGNAL',
 'CURLOPT_DNS_CACHE_TIMEOUT','CURLOPT_DNS_SERVERS','CURLOPT_TCP_KEEPALIVE','CURLOPT_TCP_KEEPIDLE','CURLOPT_TCP_KEEPINTVL',
 'CURLINFO_RESPONSE_CODE','CURLINFO_HTTP_CODE','CURLOPT_SSL_VERIFYPEER','CURLOPT_SSL_VERIFYHOST','CURLOPT_CUSTOMREQUEST',
 'CURLOPT_HTTPGET','CURLOPT_ENCODING','CURLOPT_MAXREDIRS','CURLOPT_FOLLOWLOCATION','CURLOPT_HEADER','CURLOPT_COOKIEJAR',
 'CURLOPT_COOKIEFILE','CURLOPT_COOKIE','CURLOPT_USERAGENT','CURLOPT_HTTPAUTH','CURLAUTH_BASIC','CURLOPT_USERPWD'];
foreach ($names as $i=>$name) { if (!defined($name)) define($name,10000+$i); $GLOBALS['ach_curl_names'][10000+$i]=$name; }
function curl_init($url = null) { $h=new AchCurl; if ($url!==null) $h->options[CURLOPT_URL]=$url; return $h; }
function curl_setopt($h,$key,$value) { $h->options[$key]=$value; return true; }
function curl_setopt_array($h,$values) { foreach($values as $k=>$v) curl_setopt($h,$k,$v); return true; }
function curl_exec($h) {
    $url=$h->options[CURLOPT_URL] ?? '';
    $method=basename(parse_url($url,PHP_URL_PATH) ?? '');
    $options=[]; foreach($h->options as $k=>$v) $options[$GLOBALS['ach_curl_names'][$k] ?? (string)$k]=$v;
    achEvent('http.fake',['url'=>$url,'options'=>$options]);
    if ($GLOBALS['ach_http']) {
        $res=array_shift($GLOBALS['ach_http']);
        if ($res['method']!==$method) achFail('Unexpected HTTP method: '.$method);
    } elseif (str_starts_with($url,'https://api.telegram.org/botfixture-token/')) {
        $allowed=['sendMessage','editMessageText','deleteMessage','answerCallbackQuery','sendChatAction','pinChatMessage','unpinChatMessage',
                  'answercallbackquery','getChatMember','getMe','sendPhoto','sendDocument','editMessageReplyMarkup','forwardMessage'];
        if (!in_array($method,$allowed,true)) achFail('Unexpected Telegram method: '.$method);
        $body=['ok'=>true,'result'=>['message_id'=>501,'status'=>'member','id'=>9001,'username'=>'FixtureBot']];
        $res=['body'=>json_encode($body),'http'=>200,'errno'=>0];
    } else {
        achFail('Unscripted external request: '.$url);
    }
    $h->response=$res;
    return $res['errno'] ? false : $res['body'];
}
function curl_errno($h) { return $h->response['errno'] ?? 0; }
function curl_error($h) { return curl_errno($h) ? 'fixture-network-failure' : ''; }
function curl_getinfo($h,$option=null) { return $h->response['http'] ?? 200; }
function curl_close($h) {}

function achPanelCall($name,$args) {
    achEvent('panel.fake',['method'=>$name,'args'=>$args]);
    $next=array_shift($GLOBALS['ach_panel']);
    if (!$next || $next[0]!==$name) achFail('Unscripted panel call: '.$name);
    return $next[1];
}
