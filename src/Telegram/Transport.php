<?php
/**
 * AchBot Telegram boundary. Dependencies and request context are explicit.
 * Preserve legacy payloads, transport policies, return values and diagnostics.
 */
namespace AchBot\Telegram\Transport;

use CURLFile;

require_once __DIR__ . '/Response.php';

function request($botToken, $runtimeDir, $method, $datas = []) {
    $url = "https://api.telegram.org/bot" . $botToken . "/" . $method;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);

    $hasFile = false;
    if (is_array($datas)) {
        foreach ($datas as $v) {
            if ($v instanceof CURLFile) { $hasFile = true; break; }
        }
    }

    if ($hasFile) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $datas);
    } else {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($datas, '', '&'));
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    // ⏱ Timeout برای جلوگیری از هنگ
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Expect:']);
    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
    curl_setopt($ch, CURLOPT_NOSIGNAL, true);

    curl_setopt($ch, CURLOPT_DNS_CACHE_TIMEOUT, 300);
    if (defined('CURLOPT_DNS_SERVERS')) {
        curl_setopt($ch, CURLOPT_DNS_SERVERS, '1.1.1.1,9.9.9.9,8.8.8.8');
    }
    if (defined('CURLOPT_TCP_KEEPALIVE')) {
        curl_setopt($ch, CURLOPT_TCP_KEEPALIVE, 1);
    }
    if (defined('CURLOPT_TCP_KEEPIDLE')) {
        curl_setopt($ch, CURLOPT_TCP_KEEPIDLE, 30);
    }
    if (defined('CURLOPT_TCP_KEEPINTVL')) {
        curl_setopt($ch, CURLOPT_TCP_KEEPINTVL, 15);
    }

    $res  = curl_exec($ch);
    $errn = curl_errno($ch);
    $err  = curl_error($ch);
    $http = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

    if ($errn !== 0) {
        // ❗️خطای cURL
        $logFile = $runtimeDir . "/error_log.txt";
        $maxSize = 1024 * 1024; // 1MB
        if (file_exists($logFile) && filesize($logFile) > $maxSize) {
            file_put_contents($logFile, "");
        }
        $log  = "❌ Curl Error ({$errn}): {$err}\n";
        $log .= "🕒 Time: " . date("Y-m-d H:i:s") . "\n";
        $log .= "📡 Method: {$method}\n";
        $log .= "📤 Data Sent: " . print_r($datas, true) . "\n";
        $log .= "---------------------------\n";
        file_put_contents($logFile, $log, FILE_APPEND);

        curl_close($ch);
        return null;
    }

    // در صورت پاسخ مشکوک/کدهای HTTP نامعمول، یک لاگ سبک ثبت کن
    if ($http < 200 || $http >= 500 || $res === false || $res === '') {
        $logFile = $runtimeDir . "/error_log.txt";
        $maxSize = 1024 * 1024; // 1MB
        if (file_exists($logFile) && filesize($logFile) > $maxSize) {
            file_put_contents($logFile, "");
        }
        $snippet = is_string($res) ? substr($res, 0, 500) : '';
        $log  = "⚠️ HTTP {$http} or Empty Response\n";
        $log .= "🕒 Time: " . date("Y-m-d H:i:s") . "\n";
        $log .= "📡 Method: {$method}\n";
        $log .= "📤 Data Sent: " . print_r($datas, true) . "\n";
        if ($snippet !== '') {
            $log .= "📥 Resp Snippet: {$snippet}\n";
        }
        $log .= "---------------------------\n";
        file_put_contents($logFile, $log, FILE_APPEND);
    }

    $decoded = json_decode($res);

    $retryAfter = \AchBot\Telegram\Response\retryAfter($decoded);
    if ($retryAfter !== null) {
        // NO_SLEEP_ON_TELEGRAM_429
        $logFile = $runtimeDir . "/error_log.txt";
        $log  = "⛔ Telegram Flood Limit / 429\\n";
        $log .= "🕒 Time: " . date("Y-m-d H:i:s") . "\\n";
        $log .= "📡 Method: {$method}\\n";
        $log .= "⏳ Retry After: {$retryAfter}\\n";
        $log .= "📤 Data Sent: " . print_r($datas, true) . "\\n";
        $log .= "---------------------------\\n";
        file_put_contents($logFile, $log, FILE_APPEND);

        curl_close($ch);
        return $decoded;
    }

    curl_close($ch);
    return $decoded;
}

function sendFile($botToken, $runtimeDir, $method, $datas = []) {

    $url = "https://api.telegram.org/bot" . $botToken . "/" . $method;

    // اطمینان از json بودن reply_markup
    if (isset($datas['reply_markup']) && is_array($datas['reply_markup'])) {
        $datas['reply_markup'] = json_encode($datas['reply_markup'], JSON_UNESCAPED_UNICODE);
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $datas);

    // ⏱ Timeout برای جلوگیری از هنگ (فایل ممکنه کمی بیشتر طول بکشه)
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);

    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Expect:']);
    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
    curl_setopt($ch, CURLOPT_NOSIGNAL, true);

    curl_setopt($ch, CURLOPT_DNS_CACHE_TIMEOUT, 300);
    if (defined('CURLOPT_DNS_SERVERS')) {
        curl_setopt($ch, CURLOPT_DNS_SERVERS, '1.1.1.1,9.9.9.9,8.8.8.8');
    }
    if (defined('CURLOPT_TCP_KEEPALIVE')) {
        curl_setopt($ch, CURLOPT_TCP_KEEPALIVE, 1);
    }
    if (defined('CURLOPT_TCP_KEEPIDLE')) {
        curl_setopt($ch, CURLOPT_TCP_KEEPIDLE, 30);
    }
    if (defined('CURLOPT_TCP_KEEPINTVL')) {
        curl_setopt($ch, CURLOPT_TCP_KEEPINTVL, 15);
    }

    $res  = curl_exec($ch);
    $errn = curl_errno($ch);
    $err  = curl_error($ch);
    $http = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

    if ($errn !== 0) {
        // ❗️خطای cURL
        $logFile = $runtimeDir . "/error_log.txt";
        $maxSize = 1024 * 1024; // 1MB
        if (file_exists($logFile) && filesize($logFile) > $maxSize) {
            file_put_contents($logFile, "");
        }
        $log  = "❌ Curl Error ({$errn}): {$err}\n";
        $log .= "🕒 Time: " . date("Y-m-d H:i:s") . "\n";
        $log .= "📡 Method: {$method}\n";
        $log .= "📤 Data Sent: " . print_r($datas, true) . "\n";
        $log .= "---------------------------\n";
        file_put_contents($logFile, $log, FILE_APPEND);

        curl_close($ch);
        return false;
    }

    // لاگ سبک برای HTTP نامعمول/پاسخ خالی
    if ($http < 200 || $http >= 500 || $res === false || $res === '') {
        $logFile = $runtimeDir . "/error_log.txt";
        $maxSize = 1024 * 1024; // 1MB
        if (file_exists($logFile) && filesize($logFile) > $maxSize) {
            file_put_contents($logFile, "");
        }
        $snippet = is_string($res) ? substr($res, 0, 500) : '';
        $log  = "⚠️ HTTP {$http} or Empty Response (File)\n";
        $log .= "🕒 Time: " . date("Y-m-d H:i:s") . "\n";
        $log .= "📡 Method: {$method}\n";
        $log .= "📤 Data Sent: " . print_r($datas, true) . "\n";
        if ($snippet !== '') {
            $log .= "📥 Resp Snippet: {$snippet}\n";
        }
        $log .= "---------------------------\n";
        file_put_contents($logFile, $log, FILE_APPEND);
    }

    curl_close($ch);

    $decoded = json_decode($res, true);
    return $decoded ?: false;
}
