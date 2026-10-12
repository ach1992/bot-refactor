<?php
/**
 * AchBot Support: Administrator reply wizard, text/photo notifications and ticket state update.
 * Scoped compatibility handler, included at its original bot.php route position.
 * Keep caller variables, fall-through, exit behavior and SQL/message ordering.
 * No direct HTTP/CLI entrypoint. See docs/v3/README.md for the scope contract.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

if(preg_match("/^reply_(.*)/",$data,$match) and  ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setUser("answer_" . $match[1]);
    sendMessage("لطفا پیام خود را ارسال کنید.",$cancelKey);
}
if(preg_match('/^answer_(.*)/', $userInfo['step'], $match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    $chatRowId = $match[1];
    $stmt = $connection->prepare("SELECT * FROM `chats` WHERE `id` = ?");
    $stmt->bind_param("i", $chatRowId);
    $stmt->execute();
    $ticketInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $userId = $ticketInfo['user_id'];
    $ticketTitle = $ticketInfo['title'];
    $ticketCat = $ticketInfo['category'];
    
    $time = time();

    
    if(isset($text)){
        $ticketTitle = str_replace(["/","'","#"],['\/',"\'","\#"],$ticketTitle);
        $text = str_replace(["/","'","#"],['\/',"\'","\#"],$text);
        $stmt = $connection->prepare("INSERT INTO `chats_info` (`chat_id`,`sent_date`,`msg_type`,`text`) VALUES
                    (?,?,'ADMIN',?)");
        $stmt->bind_param("iis", $chatRowId, $time, $text);
        
        sendMessage("\[$ticketTitle] _{$ticketCat}_\n\n" . $text,json_encode(['inline_keyboard'=>[
            [
                ['text'=>'پاسخ به تیکت 📝','callback_data'=>"replySupport_$chatRowId"],
                ['text'=>"بستن تیکت 🗳",'callback_data'=>"closeTicket_$chatRowId"]
                ]
            ]]),"MarkDown", $userId);        
    }else{
        $text = json_encode(['file_id'=>$fileid, 'caption'=>$caption]);
        $stmt = $connection->prepare("INSERT INTO `chats_info` (`chat_id`,`sent_date`,`msg_type`,`text`) VALUES
                    (?,?,'ADMIN',?)");
        $stmt->bind_param("iis", $chatRowId, $time, $text);
        
        $keyboard = json_encode(['inline_keyboard'=>[
            [
                ['text'=>'پاسخ به تیکت 📝','callback_data'=>"replySupport_$chatRowId"],
                ['text'=>"بستن تیکت 🗳",'callback_data'=>"closeTicket_$chatRowId"]
                ]
            ]]);
            
        sendPhoto($fileid, "\[$ticketTitle] _{$ticketCat}_\n\n" . $caption,$keyboard, "MarkDown", $userId);
    }
    $stmt->execute();
    $stmt->close();
    
    $stmt = $connection->prepare("UPDATE `chats` SET `state` = 1 WHERE `id` = ?");
    $stmt->bind_param("i", $chatRowId);
    $stmt->execute();
    $stmt->close();
    
    setUser();
    sendMessage("✅ پیام شما با موفقیت ارسال شد.",$removeKeyboard);
}

