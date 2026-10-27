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

if($data=="rejectedAgentList" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $keys = getRejectedAgentList();
    if($keys != null){
        editText($message_id,"لیست کاربران رد شده از همکاری",$keys);
    }else alert("کاربری یافت نشد.");
}
if(preg_match('/^releaseRejectedAgent(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("UPDATE `users` SET `is_agent` = 0 WHERE `userid` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();
    
    alert($mainValues['saved_successfuly']);
    $keys = getRejectedAgentList();
    if($keys != null){
        editText($message_id,"لیست کاربران رد شده از همکاری",$keys);
    }else editText($message_id,"کاربری یافت نشد.",json_encode(['inline_keyboard'=>[[['text'=>$buttonValues['back_to_main'],'callback_data'=>"managePanel"]]]]));
}

