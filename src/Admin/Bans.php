<?php
/**
 * AchBot Access: User ban/unban request and ban authorization.
 * Scoped, re-entrant, byte-compatible route at original bot.php position.
 * Internal include only. Do not execute via HTTP directly.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

if($data == "banUser" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("😡 کی باز شلوغی کرده شناسه عددی شو بفرس تا برم ...... آرهههه:", $cancelKey);
    setUser($data);
}
if($data=="unbanUser" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("شناسه عددیشو بفرست تا آزادش کنم", $cancelKey);
    setUser($data);
}
if($userInfo['step'] == "banUser" && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if(is_numeric($text)){
        
        $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
        $stmt->bind_param("i", $text);
        $stmt->execute();
        $usersList = $stmt->get_result();
        $stmt->close();
        
        if($usersList->num_rows === 1){
            $userState = $usersList->fetch_assoc();
            if($userState['step'] != "banned"){
                // Older text-step moderation must never erase an active gift.
                $currentStep=(string)$userState['step'];
                $targetPk=(int)$userState['id'];
                $targetUserid=(int)$userState['userid'];
                $stmt = $connection->prepare("UPDATE `users` SET `step`='banned' WHERE `id`=? AND `userid`=? AND `step`=? AND `step` NOT LIKE 'achbotDirectGift%'");
                $stmt->bind_param("iis",$targetPk,$targetUserid,$currentStep);
                $ok=$stmt->execute();$updated=$stmt->affected_rows;$stmt->close();
                if (!$ok || $updated!==1) {
                    sendMessage('وضعیت کاربر تغییر کرده است؛ عملیات تأیید نشد.');
                    exit();
                }
                sendMessage("❌ خب خب برید کنار که مسدودش کردم 😎😂",$removeKeyboard);
            }else{
                sendMessage("☑️ این کاربر که از قبل مسدود بود چیکارش داری بدبخت و 😂🤣",$removeKeyboard);
            }
        }else sendMessage("کاربری با این آیدی یافت نشد.");
        setUser();
        sendMessage($mainValues['reached_main_menu'],getAdminKeys());
    }else{
        sendMessage($mainValues['send_only_number']);
    }
}
