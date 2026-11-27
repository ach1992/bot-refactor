<?php
/** AChBot V6: original Telegram account/subscription routes; keep caller scope. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG

require_once __DIR__ . '/../Admin/Access.php';
if (!achbotAdminRequestAllowed(is_string($data??null)?$data:null,is_string($text??null)?$text:null,(string)($userInfo['step']??''))) {achbotAdminDeny();return;}


if(preg_match('/^editSubLinks(\d+)/', $data, $match) && achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'servers')) {
    if (!setUser("subLinksInput" . $match[1])) return;
    delMessage();
    sendMessage(\achbotMessage('subscription.sub_link_settings.message.9f2715dc7c'), $cancelKey, "HTML");
    exit();
}
if(isset($update->message->text) && !isset($update->callback_query) && preg_match('/^subLinksInput(\d+)/', $userInfo['step'], $match) && $text != \achbotMessage('legacy_buttons.cancel') && achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'servers')) {
    $rowId = $match[1];
    $subLinks = trim($text);

    require_once __DIR__.'/../Telegram/Conversation.php';
    $closed=\AchBot\Telegram\Conversation\transitionEditor($connection,(int)$from_id,$userInfo,'none',null,
        static function(mysqli $db) use($from_id,$admin,$rowId,$subLinks): void {
            if (!achbotAdminCanLocked($db,(int)$from_id,(int)$admin,'servers') ||
                !achbotProviderRow($db,'SELECT id FROM server_config WHERE id=? FOR UPDATE','i',[(int)$rowId]))
                throw new DomainException('sublink_editor_target_changed');
            $q=achbotProviderStatement($db,'UPDATE server_config SET sub_links=? WHERE id=?','si',[$subLinks,(int)$rowId]);$q->close();
        });
    if (!$closed) return;

    sendMessage(\achbotMessage('subscription.sub_link_settings.message.745a80a770'), $removeKeyboard, 'HTML');

    // برگشت به منوی سرور
    $keys = getServerConfigKeys($rowId);
    sendMessage(\achbotMessage('subscription.sub_link_settings.message.aee64f26a1'), $keys, 'HTML');
    exit();
}

