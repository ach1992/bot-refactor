<?php
/**
 * AchBot Access: Administrator listing, grant and revoke.
 * Scoped, re-entrant, byte-compatible route at original bot.php position.
 * Internal include only. Do not execute via HTTP directly.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG


if($data=="adminsList" && $from_id == $admin){
    editText($message_id, \achbotMessage('admin.roles.admins_list.message.191495d5fc'),getAdminsKeys());
}
if(preg_match('/^delAdmin(\d+)/',$data,$match) && $from_id === $admin){
    $stmt = $connection->prepare("UPDATE `users` SET `isAdmin` = false WHERE `userid` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();
    
    editText($message_id, \achbotMessage('admin.roles.admins_list.message.191495d5fc'),getAdminsKeys());

}
if($data=="addNewAdmin" && $from_id === $admin){
    delMessage();
    sendMessage(\achbotMessage('admin.roles.add_new_admin.message.026acb2e69'),$cancelKey);
    setUser($data);
}
if($userInfo['step'] == "addNewAdmin" && $from_id === $admin && $text != $buttonValues['cancel']){
    if(is_numeric($text)){
        $stmt = $connection->prepare("UPDATE `users` SET `isAdmin` = true WHERE `userid` = ?");
        $stmt->bind_param("i", $text);
        $stmt->execute();
        $stmt->close();
        
        sendMessage(\achbotMessage('admin.roles.add_new_admin.message.2e675a6e17'),$removeKeyboard);
        setUser();
        
        sendMessage(\achbotMessage('admin.roles.admins_list.message.191495d5fc'),getAdminsKeys());
    }else{
        sendMessage($mainValues['send_only_number']);
    }
}
