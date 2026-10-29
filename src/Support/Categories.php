<?php
/**
 * AchBot Support: Administrator ticket menu and category create/list/delete operations.
 * Scoped compatibility handler, included at its original bot.php route position.
 * Keep caller variables, fall-through, exit behavior and SQL/message ordering.
 * No direct HTTP/CLI entrypoint. See docs/v3/README.md for the scope contract.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG

require_once __DIR__.'/../Admin/Access.php';
if (!achbotAdminRequestAllowed(is_string($data??null)?$data:null,is_string($text??null)?$text:null,(string)($userInfo['step']??''))) {achbotAdminDeny();return;}


if($data=="ticketsList" and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $ticketSection = json_encode(['inline_keyboard'=>[
        [
            ['text'=>\achbotMessage('support.categories.tickets_list.button.33d6fa9470'),'callback_data'=>"openTickets"],
            ['text'=>\achbotMessage('support.categories.tickets_list.button.732c0fd101'),'callback_data'=>"newTickets"]
            ],
        [
            ['text'=>\achbotMessage('support.categories.tickets_list.button.c77cd22d1c'),'callback_data'=>"allTickets"],
            ['text'=>\achbotMessage('support.categories.tickets_list.button.0e6cb76a8c'),'callback_data'=>"ticketsCategory"]
            ],
        [['text' => $buttonValues['back_button'], 'callback_data' => "managePanel"]]
        ]]);
    editText($message_id, \achbotMessage('support.categories.tickets_list.message.41905ef3f3'),$ticketSection);
}
if($data=='ticketsCategory' and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'TICKETS_CATEGORY'");
    $stmt->execute();
    $ticketCategory = $stmt->get_result();
    $stmt->close();
    $keys = array();
    $keys[] = [['text'=>\achbotMessage('support.categories.tickets_category.button.59ca629220'),'callback_data'=>"wizwizch"],['text'=>\achbotMessage('support.categories.tickets_category.button.0fc3aac224'),'callback_data'=>"wizwizch"]];
    
    if($ticketCategory->num_rows>0){
        while($row = $ticketCategory->fetch_assoc()){
            $rowId = $row['id'];
            $ticketName = $row['value'];
            $keys[] = [['text'=>\achbotMessage('support.categories.tickets_category.button.2b2966a54e'),'callback_data'=>"delTicketCat_$rowId"],['text'=>$ticketName,'callback_data'=>"wizwizch"]];
        }
    }else{
        $keys[] = [['text'=>\achbotMessage('support.categories.tickets_category.button.7f9f2e3157'),'callback_data'=>"wizwizch"]];
    }
    $keys[] = [['text'=>\achbotMessage('support.categories.tickets_category.button.ee4edcb19e'),'callback_data'=>"addTicketCategory"]];
    $keys[] = [['text'=>$buttonValues['back_button'],'callback_data'=>"ticketsList"]];
    
    $keys =  json_encode(['inline_keyboard'=>$keys]);
    editText($message_id,\achbotMessage('support.categories.tickets_list.button.0e6cb76a8c'),$keys);
}
if($data=="addTicketCategory" and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setUser('addTicketCategory');
    editText($message_id,\achbotMessage('support.categories.add_ticket_category.message.f17638132c'));
}
if ($userInfo['step']=="addTicketCategory" and ($from_id == $admin || $userInfo['isAdmin'] == true)){
	$stmt = $connection->prepare("INSERT INTO `setting` (`type`, `value`) VALUES ('TICKETS_CATEGORY', ?)");	
	$stmt->bind_param("s", $text);
	$stmt->execute();
	$stmt->close();
    setUser();
    sendMessage($mainValues['saved_successfuly']);
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'TICKETS_CATEGORY'");
    $stmt->execute();
    $ticketCategory = $stmt->get_result();
    $stmt->close();
    
    $keys = array();
    $keys[] = [['text'=>\achbotMessage('support.categories.tickets_category.button.59ca629220'),'callback_data'=>"wizwizch"],['text'=>\achbotMessage('support.categories.tickets_category.button.0fc3aac224'),'callback_data'=>"wizwizch"]];
    
    if($ticketCategory->num_rows>0){
        while ($row = $ticketCategory->fetch_assoc()){
            
            $rowId = $row['id'];
            $ticketName = $row['value'];
            $keys[] = [['text'=>\achbotMessage('support.categories.tickets_category.button.2b2966a54e'),'callback_data'=>"delTicketCat_$rowId"],['text'=>$ticketName,'callback_data'=>"wizwizch"]];
        }
    }else{
        $keys[] = [['text'=>\achbotMessage('support.categories.tickets_category.button.7f9f2e3157'),'callback_data'=>"wizwizch"]];
    }
    $keys[] = [['text'=>\achbotMessage('support.categories.tickets_category.button.ee4edcb19e'),'callback_data'=>"addTicketCategory"]];
    $keys[] = [['text'=>$buttonValues['back_button'],'callback_data'=>"ticketsList"]];
    
    $keys =  json_encode(['inline_keyboard'=>$keys]);
    sendMessage(\achbotMessage('support.categories.tickets_list.button.0e6cb76a8c'),$keys);
}
if(preg_match("/^delTicketCat_(\d+)/",$data,$match) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("DELETE FROM `setting` WHERE `id` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();
    
    alert(\achbotMessage('support.categories.add_ticket_category.alert.e1e7245408'));
        

    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'TICKETS_CATEGORY'");
    $stmt->execute();
    $ticketCategory = $stmt->get_result();
    $stmt->close();
    
    $keys = array();
    $keys[] = [['text'=>\achbotMessage('support.categories.tickets_category.button.59ca629220'),'callback_data'=>"wizwizch"],['text'=>\achbotMessage('support.categories.tickets_category.button.0fc3aac224'),'callback_data'=>"wizwizch"]];
    
    if($ticketCategory->num_rows>0){
        while ($row = $ticketCategory->fetch_assoc()){
            
            $rowId = $row['id'];
            $ticketName = $row['value'];
            $keys[] = [['text'=>\achbotMessage('support.categories.tickets_category.button.2b2966a54e'),'callback_data'=>"delTicketCat_$rowId"],['text'=>$ticketName,'callback_data'=>"wizwizch"]];
        }
    }else{
        $keys[] = [['text'=>\achbotMessage('support.categories.add_ticket_category.button.fba2c6927b'),'callback_data'=>"wizwizch"]];
    }
    $keys[] = [['text'=>\achbotMessage('support.categories.tickets_category.button.ee4edcb19e'),'callback_data'=>"addTicketCategory"]];
    $keys[] = [['text'=>$buttonValues['back_button'],'callback_data'=>"ticketsList"]];
    
    $keys =  json_encode(['inline_keyboard'=>$keys]);
    editText($message_id, \achbotMessage('support.categories.tickets_list.button.0e6cb76a8c'),$keys);
}
