<?php
require_once __DIR__.'/settings/values.php';
require_once __DIR__.'/baseInfo.php';

header('Content-Type: application/json; charset=utf-8');

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET','HEAD'], true)) {
    http_response_code(405);
    echo json_encode(['app'=>'fail','error'=>'method_not_allowed']);
    exit;
}

if (empty($botToken)) {
    http_response_code(500);
    echo json_encode(['app'=>'fail','error'=>'no_bot_token']);
    exit;
}

$cacheFile = __DIR__ . '/health_cache.json';
$cacheTtl  = 30;
if (is_file($cacheFile) && (time() - filemtime($cacheFile) < $cacheTtl)) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') { exit; }
    readfile($cacheFile);
    exit;
}

$ok = true;
$status = ['app'=>'ok','time'=>date('c'),'checks'=>[]];

try {
    $mysqli = @new mysqli('localhost', $dbUserName, $dbPassword, $dbName);
    if ($mysqli->connect_error) { throw new Exception('db_connect'); }
    $mysqli->set_charset('utf8mb4');
    if (!$mysqli->ping())      { throw new Exception('db_ping'); }
    $status['checks']['db'] = 'ok';
} catch (Throwable $e) {
    $ok = false;
    $status['checks']['db'] = 'fail';
    $status['error_db'] = 'db_fail';
}

function tg_get($method) {
    global $botToken;
    $url = "https://api.telegram.org/bot{$botToken}/{$method}";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_NOSIGNAL => true,
        CURLOPT_HTTPHEADER => ['Expect:'],
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res ?: '', false);
}

try {
    $tg = tg_get('getMe');
    if (!is_object($tg) || empty($tg->ok)) { throw new Exception('tg'); }
    $status['checks']['telegram_api'] = 'ok';
    $status['bot_username'] = $tg->result->username ?? null;
} catch (Throwable $e) {
    $ok = false;
    $status['checks']['telegram_api'] = 'fail';
    $status['error_tg'] = 'tg_fail';
}

if (!$ok) $status['app'] = 'fail';
http_response_code($ok ? 200 : 500);
$json = json_encode($status, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
@file_put_contents($cacheFile, $json, LOCK_EX);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') { exit; }

echo $json;
