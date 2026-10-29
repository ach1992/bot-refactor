<?php
/**
 * AchBot Access: Rejected agent listing and release.
 * Scoped, re-entrant, byte-compatible route at original bot.php position.
 * Internal include only. Do not execute via HTTP directly.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG

require_once __DIR__.'/../Admin/Access.php';
if (!achbotAdminRequestAllowed(is_string($data??null)?$data:null,is_string($text??null)?$text:null,(string)($userInfo['step']??''))) {achbotAdminDeny();return;}


if($data=="rejectedAgentList" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $keys = getRejectedAgentList();
    if($keys != null){
        editText($message_id,\achbotMessage('agent.rejected.rejected_agent_list.message.4bb2ce6b81'),$keys);
    }else alert(\achbotMessage('agent.rejected.rejected_agent_list.alert.d5290e0a13'));
}
if(preg_match('/^releaseRejectedAgent(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("UPDATE `users` SET `is_agent` = 0 WHERE `userid` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();
    
    alert($mainValues['saved_successfuly']);
    $keys = getRejectedAgentList();
    if($keys != null){
        editText($message_id,\achbotMessage('agent.rejected.rejected_agent_list.message.4bb2ce6b81'),$keys);
    }else editText($message_id,\achbotMessage('agent.rejected.rejected_agent_list.alert.d5290e0a13'),json_encode(['inline_keyboard'=>[[['text'=>$buttonValues['back_to_main'],'callback_data'=>"managePanel"]]]]));
}

