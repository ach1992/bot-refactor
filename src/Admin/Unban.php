<?php
/**
 * AchBot Access: User unban completion, notifications and admin menu.
 * Scoped, re-entrant, byte-compatible route at original bot.php position.
 * Internal include only. Do not execute via HTTP directly.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

if($userInfo['step'] == "unbanUser" && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if(is_numeric($text)){
        $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
        $stmt->bind_param("i", $text);
        $stmt->execute();
        $usersList = $stmt->get_result();
        $stmt->close();

        if($usersList->num_rows === 1){
            $userState = $usersList->fetch_assoc();
            if($userState['step'] == "banned"){
                $targetPk=(int)$userState['id'];
                $targetUserid=(int)$userState['userid'];
                $stmt = $connection->prepare("UPDATE `users` SET `step`='none' WHERE `id`=? AND `userid`=? AND `step`='banned'");
                $stmt->bind_param("ii",$targetPk,$targetUserid);
                $ok=$stmt->execute();$updated=$stmt->affected_rows;$stmt->close();
                if (!$ok || $updated!==1) {
                    sendMessage('وضعیت کاربر تغییر کرده است؛ عملیات تأیید نشد.');
                    exit();
                }

                sendMessage("✅ آزاد شدم خوشحالم ننه، ایشالا آزادی همه 😂",$removeKeyboard);
            }else{
                sendMessage("☑️ این کاربری که فرستادی از قبل آزاد بود 🙁",$removeKeyboard);
            }
        }else sendMessage("کاربری با این آیدی یافت نشد.");
        setUser();
        sendMessage($mainValues['reached_main_menu'],getAdminKeys());
    }else{
        sendMessage($mainValues['send_only_number']);
    }
}

