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
    delMessage();
    setUser("subLinksInput" . $match[1]);
    sendMessage(\achbotMessage('subscription.sub_link_settings.message.9f2715dc7c'), $cancelKey, "Markdown");
    exit();
}
if(preg_match('/^subLinksInput(\d+)/', $userInfo['step'], $match) && $text != $buttonValues['cancel'] && achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'servers')) {
    $rowId = $match[1];
    $subLinks = trim($text);

    // ذخیره در دیتابیس
    $stmt = $connection->prepare("UPDATE `server_config` SET `sub_links` = ? WHERE `id` = ?");
    $stmt->bind_param("si", $subLinks, $rowId);
    $stmt->execute();
    $stmt->close();

    sendMessage(\achbotMessage('subscription.sub_link_settings.message.745a80a770'), $removeKeyboard);

    // برگشت به منوی سرور
    $keys = getServerConfigKeys($rowId);
    sendMessage(\achbotMessage('subscription.sub_link_settings.message.aee64f26a1'), $keys);
    setUser();
    exit();
}

