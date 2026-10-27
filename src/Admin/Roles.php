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

if($data=="adminsList" && $from_id == $admin){
    editText($message_id, "لیست ادمین ها",getAdminsKeys());
}
if(preg_match('/^delAdmin(\d+)/',$data,$match) && $from_id === $admin){
    $stmt = $connection->prepare("UPDATE `users` SET `isAdmin` = false WHERE `userid` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();
    
    editText($message_id, "لیست ادمین ها",getAdminsKeys());

}
if($data=="addNewAdmin" && $from_id === $admin){
    delMessage();
    sendMessage("🧑 شناسه عددی فردی که می خواهید ادمین شود را ارسال کنید.",$cancelKey);
    setUser($data);
}
if($userInfo['step'] == "addNewAdmin" && $from_id === $admin && $text != $buttonValues['cancel']){
    if(is_numeric($text)){
        $stmt = $connection->prepare("UPDATE `users` SET `isAdmin` = true WHERE `userid` = ?");
        $stmt->bind_param("i", $text);
        $stmt->execute();
        $stmt->close();
        
        sendMessage("✅ | 🥳 خب کاربر الان ادمین شد تبریک میگم",$removeKeyboard);
        setUser();
        
        sendMessage("لیست ادمین ها",getAdminsKeys());
    }else{
        sendMessage($mainValues['send_only_number']);
    }
}
