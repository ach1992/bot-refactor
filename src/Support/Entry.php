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

if($data=="supportSection"){
    editText($message_id,"🛂 به بخش پشتیبانی خوش آمدید.\n❇️ لطفا، یکی از دکمه های زیر را انتخاب نمایید.",
        json_encode(['inline_keyboard'=>[
        [['text'=>"✉️ ثبت تیکت",'callback_data'=>"usersNewTicket"]],
        [['text'=>"تیکت های باز 📨",'callback_data'=>"usersOpenTickets"],['text'=>"📮 لیست تیکت ها", 'callback_data'=>"userAllTickets"]],
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
        editText($message_id,"⁉️ لطفا واحد مورد نظر خود را انتخاب نمایید!
🛑 از ارسال درخواست های نامفهوم و بیجا خودداری کنید، بدون اخطار اکانت شما مسدود می شود.
🚫 فقط به درخواست هایی که به زبان فارسی تایپ می شوند پاسخ داده می شود.",json_encode(['inline_keyboard'=>$keys]));
    }else{
        alert("ای وای، ببخشید الان نیستم");
    }
}
