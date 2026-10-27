<?php
/**
 * AchBot Support: Support menu and customer category selection.
 * Scoped compatibility handler, included at its original bot.php route position.
 * Keep caller variables, fall-through, exit behavior and SQL/message ordering.
 * No direct HTTP/CLI entrypoint. See docs/v3/README.md for the scope contract.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG


if($data=="supportSection"){
    editText($message_id,\achbotMessage('support.entry.support_section.message.7089955c27'),
        json_encode(['inline_keyboard'=>[
        [['text'=>\achbotMessage('support.entry.support_section.button.47b4e6ab96'),'callback_data'=>"usersNewTicket"]],
        [['text'=>\achbotMessage('support.entry.support_section.button.a203213e37'),'callback_data'=>"usersOpenTickets"],['text'=>\achbotMessage('support.entry.support_section.button.b15cb57126'), 'callback_data'=>"userAllTickets"]],
        [['text'=>$buttonValues['back_button'],'callback_data'=>"mainMenu"]]
        ]]));
}
if($data== "usersNewTicket"){
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'TICKETS_CATEGORY'");
    $stmt->execute();
    $ticketCategory = $stmt->get_result();
    $stmt->close();
    $keys = array();
    $temp = array();
    if($ticketCategory->num_rows >0){
        while($row = $ticketCategory->fetch_assoc()){
            $ticketName = $row['value'];
            $temp[] = ['text'=>$ticketName,'callback_data'=>"supportCat$ticketName"];
            
            if(count($temp) == 2){
                array_push($keys,$temp);
                $temp = null;
            }
        }
        
        if($temp != null){
            if(count($temp)>0){
                array_push($keys,$temp);
                $temp = null;
            }
        }
        $temp[] = ['text'=>$buttonValues['back_button'],'callback_data'=>"mainMenu"];
        array_push($keys,$temp);
        editText($message_id,\achbotMessage('support.entry.users_new_ticket.message.adca0ba3c2'),json_encode(['inline_keyboard'=>$keys]));
    }else{
        alert(\achbotMessage('support.entry.users_new_ticket.alert.46a8c85d10'));
    }
}
