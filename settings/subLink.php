<?php
require_once __DIR__ . '/../messages/loader.php'; // ACHBOT_TEXT_CATALOG

/** This endpoint was generated only for legacy X-UI subscription tokens.
 * Active Marzban accounts use their own /sub/{token} provider URL.
 * Keep the public filename as a safe, side-effect-free retirement response.
 */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Content-Type: text/plain; charset=utf-8');
    echo \achbotMessage('jobs.sub_link.method_not_allowed.371b26886b');
    exit;
}
header('Content-Type: text/plain; charset=utf-8');
$token = $_GET['token'] ?? null;
if (!is_string($token) || !preg_match('/^[a-zA-Z0-9]{30}$/D', $token)) {
    echo \achbotMessage('jobs.sub_link.wrong_token.9730d4c268');
    exit;
}
http_response_code(410);
echo \achbotMessage('jobs.sub_link.legacy_subscription_link_retired_use.6e4eb03e96');
