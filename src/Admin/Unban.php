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

        if($usersList->num_rows >0){
            $userState = $usersList->fetch_assoc();
            if($userState['step'] == "banned"){
                $stmt = $connection->prepare("UPDATE `users` SET `step` = 'none' WHERE `userid` = ?");
                $stmt->bind_param("i", $text);
                $stmt->execute();
                $stmt->close();

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

