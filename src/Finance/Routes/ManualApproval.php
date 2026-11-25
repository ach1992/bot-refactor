<?php
/** AChBot V8: source-pinned financial compatibility module. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../../messages/loader.php'; // ACHBOT_TEXT_CATALOG

require_once __DIR__ . '/../../Admin/Access.php';
require_once __DIR__.'/../ApiPaymentPresentation.php';
if (!achbotAdminRequestAllowed(is_string($data??null)?$data:null,is_string($text??null)?$text:null,(string)($userInfo['step']??''))) {achbotAdminDeny();return;}


if (
    preg_match('/^accept([A-Za-z0-9_-]{1,50})$/D', (string)$data, $match) &&
    $text != \achbotMessage('legacy_buttons.cancel') &&
    achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'payments')
) {
    $hash_id = $match[1];
    require_once __DIR__.'/../CheckoutRoutes.php';
    achbotCheckoutApprovalCallback($connection,(string)$match[1],(int)$from_id,(int)$admin,'standard');
    return;
}

