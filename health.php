<?php
/**
 * Cheap local HTTP/DB readiness probe. Telegram reachability is not bot liveness:
 * getMe can time out when webhook/cron handling is healthy.
 *
 * GET /health.php -> JSON + 200 (ready) or 503 (not ready).
 * HEAD -> identical status, no body. No cache files, outbound HTTP or writes.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    http_response_code(405);
    echo json_encode(['app' => 'fail', 'error' => 'method_not_allowed']);
    exit;
}

$ready = false;
$reason = 'unavailable';

try {
    // Never include the message catalogs or load Telegram client code on a probe.
    @require __DIR__.'/baseInfo.php';
    if (empty($botToken)) {
        $reason = 'no_bot_token';
    } else {
        $db = mysqli_init();
        if (!$db) {
            throw new RuntimeException('db_init');
        }

        // Bound the one local dependency, including delayed reads after connect.
        $db->options(MYSQLI_OPT_CONNECT_TIMEOUT, 2);
        if (defined('MYSQLI_OPT_READ_TIMEOUT')) {
            $db->options(MYSQLI_OPT_READ_TIMEOUT, 2);
        }
        @$db->real_connect('localhost', $dbUserName, $dbPassword, $dbName);
        $result = @$db->query('SELECT 1');
        $row = $result ? $result->fetch_row() : null;
        if (!is_array($row) || (string)($row[0] ?? '') !== '1') {
            throw new RuntimeException('db_probe');
        }
        $db->close();
        $ready = true;
    }
} catch (Throwable $e) {
    // The public endpoint must not expose connection strings or error details.
    $reason = 'db_unavailable';
}

http_response_code($ready ? 200 : 503);
$body = [
    'app' => $ready ? 'ok' : 'fail',
    'time' => date('c'),
    'checks' => ['db' => $ready ? 'ok' : 'fail'],
];
if (!$ready) {
    $body['error'] = $reason;
}
if ($method === 'GET') {
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
