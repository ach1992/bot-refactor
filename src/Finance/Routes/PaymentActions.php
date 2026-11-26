<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
$action=(string)($data??'');
if (!str_starts_with($action,'pw_') && !str_starts_with($action,'pa_')) return 1;
if (!preg_match('/\A(pw|pa)_([A-Za-z0-9_-]{1,50})\z/D',$action,$match)) return;
require_once __DIR__.'/../CheckoutRoutes.php';
require_once __DIR__.'/../../Telegram/Conversation.php';
$actor=(int)$from_id;
$source=\AchBot\Telegram\Conversation\source($update??null,$actor,true);
if (!$source || $source->message_id!==(int)($message_id??0) || $update->callback_query->data!==$action) return;
if ($match[1]==='pa') {
    if (!achbotAdminCanFor($connection,$actor,(int)$admin,'payments')) {achbotAdminDeny();return;}
    achbotCheckoutApprovalCallback($connection,$match[2],$actor,(int)$admin);
} else {
    achbotCheckoutWalletCallback($connection,$match[2],$actor,$botState);
}
return;
