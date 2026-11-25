<?php
/**
 * AchBot Support: Persisted category/title/body wizard and text/photo ticket creation.
 * Scoped compatibility handler, included at its original bot.php route position.
 * Keep caller variables, fall-through, exit behavior and SQL/message ordering.
 * No direct HTTP/CLI entrypoint. See docs/v3/README.md for the scope contract.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG
require_once __DIR__.'/../Telegram/Presentation.php';


if(preg_match('/^supportCat(.*)/',$data,$match)){
    delMessage();
    sendMessage(\achbotMessage('legacy_messages.enter_ticket_title'), $cancelKey,'HTML');
    setUser("newTicket_" . $match[1]);
}
if(preg_match('/^newTicket_(.*)/',$userInfo['step'],$match)  and $text!=\achbotMessage('legacy_buttons.cancel')){
    setUser($text, 'temp');
	setUser("sendTicket_" . $match[1]);
    sendMessage(\achbotMessage('legacy_messages.enter_ticket_description'), null, 'HTML');
}
if(preg_match('/^sendTicket_(.*)/',$userInfo['step'],$match)  and $text!=\achbotMessage('legacy_buttons.cancel')){
    if(isset($text) || isset($update->message->photo)){
        $ticketCat = $match[1];
        
        $ticketTitle = $userInfo['temp'];
        $time = time();

        $stmt = $connection->prepare("INSERT INTO `chats` (`user_id`,`create_date`, `title`,`category`,`state`,`rate`) VALUES 
                            (?,?,?,?,'0','0')");
        $stmt->bind_param("iiss", $from_id, $time, $ticketTitle, $ticketCat);
        $stmt->execute();
        $inserId = $stmt->get_result();
        $chatRowId = $stmt->insert_id;
        $stmt->close();
        
        $keys = json_encode(['inline_keyboard'=>[
            [['text'=>\achbotMessage('support.create_ticket.button.3b90dccc67'),'callback_data'=>"reply_{$chatRowId}"]]
            ]]);
        if(isset($text)){
            $txt = \achbotMessageHtml('support.create_ticket.txt.e819afcdf4', ['from_id' => $from_id, 'first_name' => $first_name, 'username' => $username, 'from_id_2' => $from_id, 'ticketCat' => $ticketCat]) .htmlspecialchars((string)$ticketTitle,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') . \achbotMessage('support.create_ticket.text.fe45cc8381', ['text' => \AchBot\Telegram\Presentation\incomingHtml($text,(array)($update->message->entities??[]))]);

            $stmt = $connection->prepare("INSERT INTO `chats_info` (`chat_id`,`sent_date`,`msg_type`,`text`) VALUES
                        (?,?,'USER',?)");
            $stmt->bind_param("iis", $chatRowId, $time, $text);
            sendMessage($txt,$keys,"html", $admin);
            sendToAllAdmins('sendMessage', ['text' => $txt, 'reply_markup' => $keys, 'parse_mode' => "html"]);
        }else{
            $txt = \achbotMessageHtml('support.create_ticket.txt.e819afcdf4', ['from_id' => $from_id, 'first_name' => $first_name, 'username' => $username, 'from_id_2' => $from_id, 'ticketCat' => $ticketCat]) .htmlspecialchars((string)$ticketTitle,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') . \achbotMessage('support.create_ticket.text.4479736037', ['caption' => \AchBot\Telegram\Presentation\incomingHtml($caption,(array)($update->message->caption_entities??[]))]);
            $stmt = $connection->prepare("INSERT INTO `chats_info` (`chat_id`,`sent_date`,`msg_type`,`text`) VALUES
                        (?,?,'USER',?)");
            $text = json_encode(['file_id'=>$fileid, 'caption'=>$caption]);
            $stmt->bind_param("iis", $chatRowId, $time, $text);
            sendPhoto($fileid, $txt,$keys, "HTML", $admin);
            sendToAllAdmins('sendPhoto', ['photo' => $fileid, 'caption' => $txt, 'reply_markup' => $keys, 'parse_mode' => "HTML"]);
        }
        $stmt->execute();
        $stmt->close();
        
        sendMessage(\achbotMessage('support.create_ticket.message.5c9c3dda00'),$removeKeyboard,"HTML");
        sendMessage(\achbotMessage('support.create_ticket.message.b059640ccc'),getMainKeys(), 'HTML');
            
        setUser(NULL,'temp');
    	setUser("none");
    }else{
        sendMessage(\achbotMessage('support.create_ticket.message.19602d80df'), null, 'HTML');
    }
    
}
