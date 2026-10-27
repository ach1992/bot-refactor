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

if($data=="ticketsList" and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $ticketSection = json_encode(['inline_keyboard'=>[
        [
            ['text'=>"تیکت های باز",'callback_data'=>"openTickets"],
            ['text'=>"تیکت های جدید",'callback_data'=>"newTickets"]
            ],
        [
            ['text'=>"همه ی تیکت ها",'callback_data'=>"allTickets"],
            ['text'=>"دسته بندی تیکت ها",'callback_data'=>"ticketsCategory"]
            ],
        [['text' => $buttonValues['back_button'], 'callback_data' => "managePanel"]]
        ]]);
    editText($message_id, "به بخش تیکت ها خوش اومدید
    
🚪 /start
    ",$ticketSection);
}
if($data=='ticketsCategory' and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'TICKETS_CATEGORY'");
    $stmt->execute();
    $ticketCategory = $stmt->get_result();
    $stmt->close();
    $keys = array();
    $keys[] = [['text'=>"حذف",'callback_data'=>"wizwizch"],['text'=>"دسته بندی",'callback_data'=>"wizwizch"]];
    
    if($ticketCategory->num_rows>0){
        while($row = $ticketCategory->fetch_assoc()){
            $rowId = $row['id'];
            $ticketName = $row['value'];
            $keys[] = [['text'=>"❌",'callback_data'=>"delTicketCat_$rowId"],['text'=>$ticketName,'callback_data'=>"wizwizch"]];
        }
    }else{
        $keys[] = [['text'=>"دسته بندی یافت نشد",'callback_data'=>"wizwizch"]];
    }
    $keys[] = [['text'=>"افزودن دسته بندی",'callback_data'=>"addTicketCategory"]];
    $keys[] = [['text'=>$buttonValues['back_button'],'callback_data'=>"ticketsList"]];
    
    $keys =  json_encode(['inline_keyboard'=>$keys]);
    editText($message_id,"دسته بندی تیکت ها",$keys);
}
if($data=="addTicketCategory" and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setUser('addTicketCategory');
    editText($message_id,"لطفا اسم دسته بندی را وارد کنید");
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
    $keys[] = [['text'=>"حذف",'callback_data'=>"wizwizch"],['text'=>"دسته بندی",'callback_data'=>"wizwizch"]];
    
    if($ticketCategory->num_rows>0){
        while ($row = $ticketCategory->fetch_assoc()){
            
            $rowId = $row['id'];
            $ticketName = $row['value'];
            $keys[] = [['text'=>"❌",'callback_data'=>"delTicketCat_$rowId"],['text'=>$ticketName,'callback_data'=>"wizwizch"]];
        }
    }else{
        $keys[] = [['text'=>"دسته بندی یافت نشد",'callback_data'=>"wizwizch"]];
    }
    $keys[] = [['text'=>"افزودن دسته بندی",'callback_data'=>"addTicketCategory"]];
    $keys[] = [['text'=>$buttonValues['back_button'],'callback_data'=>"ticketsList"]];
    
    $keys =  json_encode(['inline_keyboard'=>$keys]);
    sendMessage("دسته بندی تیکت ها",$keys);
}
if(preg_match("/^delTicketCat_(\d+)/",$data,$match) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("DELETE FROM `setting` WHERE `id` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();
    
    alert("با موفقیت حذف شد.");
        

    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'TICKETS_CATEGORY'");
    $stmt->execute();
    $ticketCategory = $stmt->get_result();
    $stmt->close();
    
    $keys = array();
    $keys[] = [['text'=>"حذف",'callback_data'=>"wizwizch"],['text'=>"دسته بندی",'callback_data'=>"wizwizch"]];
    
    if($ticketCategory->num_rows>0){
        while ($row = $ticketCategory->fetch_assoc()){
            
            $rowId = $row['id'];
            $ticketName = $row['value'];
            $keys[] = [['text'=>"❌",'callback_data'=>"delTicketCat_$rowId"],['text'=>$ticketName,'callback_data'=>"wizwizch"]];
        }
    }else{
        $keys[] = [['text'=>"دسته بندی یافت نشد.",'callback_data'=>"wizwizch"]];
    }
    $keys[] = [['text'=>"افزودن دسته بندی",'callback_data'=>"addTicketCategory"]];
    $keys[] = [['text'=>$buttonValues['back_button'],'callback_data'=>"ticketsList"]];
    
    $keys =  json_encode(['inline_keyboard'=>$keys]);
    editText($message_id, "دسته بندی تیکت ها",$keys);
}
