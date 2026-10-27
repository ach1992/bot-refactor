<?php
/** This endpoint was generated only for legacy X-UI subscription tokens.
 * Active Marzban accounts use their own /sub/{token} provider URL.
 * Keep the public filename as a safe, side-effect-free retirement response.
 */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Method not allowed';
    exit;
}
header('Content-Type: text/plain; charset=utf-8');
$token = $_GET['token'] ?? null;
if (!is_string($token) || !preg_match('/^[a-zA-Z0-9]{30}$/D', $token)) {
    echo 'Wrong token';
    exit;
}
http_response_code(410);
echo 'Legacy subscription link retired; use the current Marzban subscription URL.';
