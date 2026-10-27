<?php
/**
 * AchBot Support: Administrator queues, pagination, closure, history and attachment retrieval.
 * Scoped compatibility handler, included at its original bot.php route position.
 * Keep caller variables, fall-through, exit behavior and SQL/message ordering.
 * No direct HTTP/CLI entrypoint. See docs/v3/README.md for the scope contract.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG


if(($data=="openTickets" || $data=="newTickets" || $data == "allTickets") && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if($data=="openTickets"){
        $stmt = $connection->prepare("SELECT * FROM `chats` WHERE `state` != 2 ORDER BY `state` ASC, `create_date` DESC");
        $type = 2;
    }elseif($data=="newTickets"){
        $stmt = $connection->prepare("SELECT * FROM `chats` WHERE `state` = 0 ORDER BY `create_date` DESC");
        $type = 0;
    }elseif($data=="allTickets"){
        $stmt = $connection->prepare("SELECT * FROM `chats` ORDER BY `state` ASC, `create_date` DESC");
        $type = "all";
    }
    $stmt->execute();
    $ticketList = $stmt->get_result();
    $stmt->close();
	$allList =$ticketList->num_rows;
	$cont = 5;
	$current = 0;
	$keys = array();
	if($allList>0){
        while ($row = $ticketList->fetch_assoc()){
		    $current++;
		    
            $rowId = $row['id'];
            $admin = $row['user_id'];
            $title = $row['title'];
            $category = $row['category'];
	        $state = $row['state'];
	        $username = bot('getChat',['chat_id'=>$admin])->result->first_name ?? " ";

            $stmt = $connection->prepare("SELECT * FROM `chats_info` WHERE `chat_id` = ? ORDER BY `sent_date` DESC");
            $stmt->bind_param("i",$rowId);
            $stmt->execute();
            $ticketInfo = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $lastmsg = $ticketInfo['text'];
            $sentType = $ticketInfo['msg_type']=="USER"?\achbotMessage('support.customer_conversation.user_all_tickets.text.5bf4b36161'):\achbotMessage('support.customer_conversation.user_all_tickets.text.13ad0eef28');
            
            if($state !=2){
                $keys = [
                        [['text'=>\achbotMessage('support.queue_and_history.all_tickets.button.12e9251ccb'),'callback_data'=>"closeTicket_$rowId"],['text'=>\achbotMessage('support.create_ticket.button.3b90dccc67'),'callback_data'=>"reply_{$rowId}"]],
                        [['text'=>\achbotMessage('support.queue_and_history.all_tickets.button.ce4f25e31b'),'callback_data'=>"latestMsg_$rowId"]]
                        ];
            }
            else{
                $keys = [[['text'=>\achbotMessage('support.queue_and_history.all_tickets.button.ce4f25e31b'),'callback_data'=>"latestMsg_$rowId"]]];
                $rate = \achbotMessage('support.queue_and_history.all_tickets.rate.c5509cf4dc'). $row['rate'];
            }
            
            sendMessage(\achbotMessage('support.queue_and_history.all_tickets.message.abf3f55cac', ['admin' => $admin, 'username' => $username, 'category' => $category, 'rate' => $rate, 'title' => $title, 'sentType' => $sentType, 'lastmsg' => $lastmsg]),
                json_encode(['inline_keyboard'=>$keys]),"html");

			if($current>=$cont){
			    break;
			}
        }
        
		if($allList > $cont){
		    $keys = json_encode(['inline_keyboard'=>[
		        [['text'=>\achbotMessage('support.customer_conversation.user_all_tickets.button.29582a35e0'),'callback_data'=>"moreTicket_{$type}_{$cont}"]]
		        ]]);
            sendMessage(\achbotMessage('support.customer_conversation.user_all_tickets.text.7555aab9b2'),$keys,"html");
		}
	}else{
        alert(\achbotMessage('support.queue_and_history.all_tickets.alert.5974314756'));
	}
}
if(preg_match('/^moreTicket_(.+)_(.+)/',$data, $match) and  ($from_id == $admin || $userInfo['isAdmin'] == true)){
    editText($message_id,$mainValues['please_wait_message']);
    $type = $match[1];
    $offset = $match[2];
    if($type=="2") $stmt = $connection->prepare("SELECT * FROM `chats` WHERE `state` != 2 ORDER BY `state` ASC, `create_date` DESC");
    elseif($type=="0") $stmt = $connection->prepare("SELECT * FROM `chats` WHERE `state` = 0 ORDER BY `create_date` DESC");
    elseif($type=="all") $stmt = $connection->prepare("SELECT * FROM `chats` ORDER BY `state` ASC, `create_date` DESC");
    
    $stmt->execute();
    $ticketList = $stmt->get_result();
    $stmt->close();

	$allList =$ticketList->num_rows;
	$cont = 5 + $offset;
	$current = 0;
	$keys = array();
	$rowCont = 0;
	if($allList>0){
        while ($row = $ticketList->fetch_assoc()){
            $rowCont++;
            if($rowCont>$offset){
    		    $current++;
    		    
                $rowId = $row['id'];
                $admin = $row['user_id'];
                $title = $row['title'];
                $category = $row['category'];
    	        $state = $row['state'];
    	        $username = bot('getChat',['chat_id'=>$admin])->result->first_name ?? " ";
    
                $stmt = $connection->prepare("SELECT * FROM `chats_info` WHERE `chat_id` = ? ORDER BY `sent_date` DESC");
                $stmt->bind_param("i",$rowId);
                $stmt->execute();
                $ticketInfo = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $lastmsg = $ticketInfo['text'];
                $sentType = $ticketInfo['msg_type']=="USER"?\achbotMessage('support.customer_conversation.user_all_tickets.text.5bf4b36161'):\achbotMessage('support.customer_conversation.user_all_tickets.text.13ad0eef28');
                
                if($state !=2){
                    $keys = [
                            [['text'=>\achbotMessage('support.queue_and_history.all_tickets.button.12e9251ccb'),'callback_data'=>"closeTicket_$rowId"],['text'=>\achbotMessage('support.create_ticket.button.3b90dccc67'),'callback_data'=>"reply_{$rowId}"]],
                            [['text'=>\achbotMessage('support.queue_and_history.all_tickets.button.ce4f25e31b'),'callback_data'=>"latestMsg_$rowId"]]
                            ];
                }
                else{
                    $keys = [[['text'=>\achbotMessage('support.queue_and_history.all_tickets.button.ce4f25e31b'),'callback_data'=>"latestMsg_$rowId"]]];
                    $rate = \achbotMessage('support.queue_and_history.all_tickets.rate.c5509cf4dc'). $row['rate'];
                }
                
                sendMessage(\achbotMessage('support.queue_and_history.all_tickets.message.abf3f55cac', ['admin' => $admin, 'username' => $username, 'category' => $category, 'rate' => $rate, 'title' => $title, 'sentType' => $sentType, 'lastmsg' => $lastmsg]),
                    json_encode(['inline_keyboard'=>$keys]),"html");


    			if($current>=$cont){
    			    break;
    			}
            }
        }
        
		if($allList > $cont){
		    $keys = json_encode(['inline_keyboard'=>[
		        [['text'=>\achbotMessage('support.customer_conversation.user_all_tickets.button.29582a35e0'),'callback_data'=>"moreTicket_{$type}_{$cont}"]]
		        ]]);
            sendMessage(\achbotMessage('support.customer_conversation.user_all_tickets.text.7555aab9b2'),$keys);
		}
	}else{
        alert(\achbotMessage('support.queue_and_history.all_tickets.alert.5974314756'));
	}
}
if(preg_match('/^closeTicket_(\d+)/',$data,$match) and  ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $chatRowId = $match[1];
    $stmt = $connection->prepare("SELECT * FROM `chats` WHERE `id` = ?");
    $stmt->bind_param("i", $chatRowId);
    $stmt->execute();
    $ticketInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $userId = $ticketInfo['user_id'];
    $title = $ticketInfo['title'];
    $category = $ticketInfo['category'];
        

    $stmt = $connection->prepare("UPDATE `chats` SET `state` = 2 WHERE `id` = ?");
    $stmt->bind_param("i", $chatRowId);
    $stmt->execute();
    $stmt->close();
    
    $ticketClosed = "[$title] <i>$category</i> \n\n" . \achbotMessage('support.customer_conversation.user_all_tickets.text.54487cb6cb');;
    
    $keys = json_encode(['inline_keyboard'=>[
        [['text'=>\achbotMessage('support.customer_conversation.user_all_tickets.button.67c753a13d'),'callback_data'=>"rate_{$chatRowId}_1"]],
        [['text'=>\achbotMessage('support.customer_conversation.user_all_tickets.button.6f4e0b97a2'),'callback_data'=>"rate_{$chatRowId}_2"]],
        [['text'=>\achbotMessage('support.customer_conversation.user_all_tickets.button.e26806a4af'),'callback_data'=>"rate_{$chatRowId}_3"]],
        [['text'=>\achbotMessage('support.customer_conversation.user_all_tickets.button.3ed1f2e72a'),'callback_data'=>"rate_{$chatRowId}_4"]],
        [['text'=>\achbotMessage('support.customer_conversation.user_all_tickets.button.3d61f01bbd'),'callback_data'=>"rate_{$chatRowId}_5"]]
        ]]);
    sendMessage($ticketClosed,$keys,'html', $userId);
    editKeys(json_encode(['inline_keyboard'=>[
        [['text'=>\achbotMessage('support.queue_and_history.all_tickets.button.b1eee8adee'),'callback_data'=>"wizwizch"]]
        ]]));

}
if(preg_match('/^latestMsg_(.*)/',$data,$match)){
    $stmt = $connection->prepare("SELECT * FROM `chats_info` WHERE `chat_id` = ? ORDER BY `sent_date` DESC LIMIT 10");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $chatList = $stmt->get_result();
    $stmt->close();
    $output = "";
    while($row = $chatList->fetch_assoc()){
        $rowId = $row['id'];
        $type = $row['msg_type'] == "USER" ?\achbotMessage('support.customer_conversation.user_all_tickets.text.5bf4b36161'):\achbotMessage('support.customer_conversation.user_all_tickets.text.13ad0eef28');
        $text = $row['text'];
        if(isset(json_decode($text,true)['file_id'])) $text = \achbotMessage('support.queue_and_history.all_tickets.text.61cf036058') . $rowId;

        $output .= "<i>[$type]</i>\n$text\n\n";
    }
    sendMessage($output, null, "html");
}
if(preg_match('/^\/dlPic(\d+)/',$text,$match)){
     $stmt = $connection->prepare("SELECT * FROM `chats_info` WHERE `id` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $chatList = $stmt->get_result();
    $stmt->close();
    $output = "";
    while($row = $chatList->fetch_assoc()){
        $text = json_decode($row['text'],true);
        $fileid = $text['file_id'];
        $caption = $text['caption'];
        $chatInfoId = $row['chat_id'];
        $stmt = $connection->prepare("SELECT * FROM `chats` WHERE `id` = ?");
        $stmt->bind_param("i", $chatInfoId);
        $stmt->execute();
        $info = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        $userid = $info['user_id'];
        
        if($userid == $from_id || $from_id == $admin || $userInfo['isAdmin'] == true) sendPhoto($fileid, $caption);
    }
}
