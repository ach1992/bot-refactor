<?php
/**
 * AchBot Support: Customer lists, closure, reply wizard, message persistence and rating.
 * Scoped compatibility handler, included at its original bot.php route position.
 * Keep caller variables, fall-through, exit behavior and SQL/message ordering.
 * No direct HTTP/CLI entrypoint. See docs/v3/README.md for the scope contract.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG


if($data== "usersOpenTickets" || $data == "userAllTickets"){
    if($data== "usersOpenTickets"){
        $stmt = $connection->prepare("SELECT * FROM `chats` WHERE `state` != 2 AND `user_id` = ? ORDER BY `state` ASC, `create_date` DESC");
        $stmt->bind_param("i", $from_id);
        $stmt->execute();
        $ticketList = $stmt->get_result();
        $stmt->close();
        $type = 2;
    }elseif($data == "userAllTickets"){
        $stmt = $connection->prepare("SELECT * FROM `chats` WHERE `user_id` = ? ORDER BY `state` ASC, `create_date` DESC");
        $stmt->bind_param("i", $from_id);
        $stmt->execute();
        $ticketList = $stmt->get_result();
        $stmt->close();
        $type = "all";
    }
	$allList = $ticketList->num_rows;
	$cont = 5;
	$current = 0;
	$keys = array();
	setUser("none");


	if($allList>0){
        while($row = $ticketList->fetch_assoc()){
		    $current++;
		    
            $rowId = $row['id'];
            $title = $row['title'];
            $category = $row['category'];
	        $state = $row['state'];

            $stmt = $connection->prepare("SELECT * FROM `chats_info` WHERE `chat_id` = ? ORDER BY `sent_date` DESC");
            $stmt->bind_param("i", $rowId);
            $stmt->execute();
            $ticketInfo = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            $lastmsg = $ticketInfo['text'];
            $sentType = $ticketInfo['msg_type']=="ADMIN"?\achbotMessage('support.customer_conversation.user_all_tickets.text.13ad0eef28'):\achbotMessage('support.customer_conversation.user_all_tickets.text.5bf4b36161');
            if($state !=2){
                $keys = [
                        [['text'=>\achbotMessage('support.admin_reply.button.173875ae6b'),'callback_data'=>"closeTicket_$rowId"],['text'=>\achbotMessage('support.admin_reply.button.f8a34e79d9'),'callback_data'=>"replySupport_{$rowId}"]],
                        [['text'=>\achbotMessage('support.customer_conversation.user_all_tickets.button.2f9a0fbfd0'),'callback_data'=>"latestMsg_$rowId"]]
                        ];
            }
            else{
                $keys = [
                    [['text'=>\achbotMessage('support.customer_conversation.user_all_tickets.button.2f9a0fbfd0'),'callback_data'=>"latestMsg_$rowId"]]
                    ];
            }
                
            if(isset(json_decode($lastmsg,true)['file_id'])){
                $info = json_decode($lastmsg,true);
                $fileid = $info['file_id'];
                $caption = $info['caption'];
                $txt =\achbotMessage('support.customer_conversation.user_all_tickets.txt.d10f40bde9', ['title' => $title, 'category' => $category, 'sentType' => $sentType, 'caption' => $caption]);
                sendPhoto($fileid, $txt,json_encode(['inline_keyboard'=>$keys]), "HTML");
            }else{
                sendMessage(\achbotMessage('support.customer_conversation.user_all_tickets.message.dfae2b8b30', ['title' => $title, 'category' => $category, 'sentType' => $sentType, 'lastmsg' => $lastmsg]),json_encode(['inline_keyboard'=>$keys]),"HTML");
            }

			if($current>=$cont){
			    break;
			}
        }
        
		if($allList > $cont){
		    sendmessage(\achbotMessage('support.customer_conversation.user_all_tickets.text.7555aab9b2'),json_encode(['inline_keyboard'=>[
                                        [['text'=>\achbotMessage('support.customer_conversation.user_all_tickets.button.29582a35e0'),'callback_data'=>"moreTicket_{$type}_{$cont}"]]
                		        ]]),"HTML");
		}
	}else{
	    alert(\achbotMessage('support.customer_conversation.user_all_tickets.alert.69716a6209'));
        exit();
	}
}
if(preg_match('/^closeTicket_(\d+)/',$data,$match) and  $from_id != $admin){
    $chatRowId = $match[1];
    $stmt = $connection->prepare("SELECT * FROM `chats` WHERE `id` = ?");
    $stmt->bind_param("i", $chatRowId);
    $stmt->execute();
    $ticketInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $from_id = $ticketInfo['user_id'];
    $title = $ticketInfo['title'];
    $category = $ticketInfo['category'];
        

    $stmt = $connection->prepare("UPDATE `chats` SET `state` = 2 WHERE `id` = ?");
    $stmt->bind_param("i", $chatRowId);
    $stmt->execute();
    $stmt->close();
    
    editKeys();

    $ticketClosed = \achbotMessage('support.customer_conversation.user_all_tickets.title_category.f58d7d9818', ['title' => $title, 'category' => $category]) . \achbotMessage('support.customer_conversation.user_all_tickets.text.54487cb6cb');;
    
    $keys = json_encode(['inline_keyboard'=>[
        [['text'=>\achbotMessage('support.customer_conversation.user_all_tickets.button.67c753a13d'),'callback_data'=>"rate_{$chatRowId}_1"]],
        [['text'=>\achbotMessage('support.customer_conversation.user_all_tickets.button.6f4e0b97a2'),'callback_data'=>"rate_{$chatRowId}_2"]],
        [['text'=>\achbotMessage('support.customer_conversation.user_all_tickets.button.e26806a4af'),'callback_data'=>"rate_{$chatRowId}_3"]],
        [['text'=>\achbotMessage('support.customer_conversation.user_all_tickets.button.3ed1f2e72a'),'callback_data'=>"rate_{$chatRowId}_4"]],
        [['text'=>\achbotMessage('support.customer_conversation.user_all_tickets.button.3d61f01bbd'),'callback_data'=>"rate_{$chatRowId}_5"]]
        ]]);
    sendMessage($ticketClosed,$keys,'html');
    
    $keys = json_encode(['inline_keyboard'=>[
        [
            ['text'=>"$from_id",'callback_data'=>"wizwizch"],
            ['text'=>\achbotMessage('support.customer_conversation.user_all_tickets.button.2dc9dcf8cd'),'callback_data'=>'wizwizch']
        ],
        [
            ['text'=>$first_name??" ",'callback_data'=>"wizwizch"],
            ['text'=>\achbotMessage('support.customer_conversation.user_all_tickets.button.c9b4331f4f'),'callback_data'=>'wizwizch']
        ],
        [
            ['text'=>"$title",'callback_data'=>'wizwizch'],
            ['text'=>\achbotMessage('support.customer_conversation.user_all_tickets.button.74190f4bb0'),'callback_data'=>'wizwizch']
        ],
        [
            ['text'=>"$category",'callback_data'=>'wizwizch'],
            ['text'=>\achbotMessage('support.categories.tickets_category.button.0fc3aac224'),'callback_data'=>'wizwizch']
        ],
        ]]);
    sendMessage(\achbotMessage('support.customer_conversation.user_all_tickets.message.2baf15e3c0'),$keys,"HTML",$admin);
    sendToAllAdmins('sendMessage', ['text' => \achbotMessage('support.customer_conversation.user_all_tickets.message.2baf15e3c0'), 'reply_markup' => $keys, 'parse_mode' => "HTML"]);

}
if(preg_match('/^replySupport_(.*)/',$data,$match)){
    delMessage();
    sendMessage(\achbotMessage('support.customer_conversation.user_all_tickets.message.13e7a1cdbb'),$cancelKey);
	setUser("sendMsg_" . $match[1]);
}
if(preg_match('/^sendMsg_(.*)/',$userInfo['step'],$match)  and $text!=$buttonValues['cancel']){
    $ticketRowId = $match[1];

    $stmt = $connection->prepare("SELECT * FROM `chats` WHERE `id` = ?");
    $stmt->bind_param("i", $ticketRowId);
    $stmt->execute();
    $ticketInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $ticketTitle = $ticketInfo['title'];
    $ticketCat = $ticketInfo['category'];

    $time = time();
    if(isset($text)){
        $txt = \achbotMessage('support.customer_conversation.user_all_tickets.txt.54b5fecc08', ['ticketTitle' => $ticketTitle, 'ticketCat' => $ticketCat, 'from_id' => $from_id, 'first_name' => $first_name, 'username' => $username, 'from_id_2' => $from_id]) . \achbotMessage('support.customer_conversation.user_all_tickets.text.a79a2fa69e', ['text' => $text]);
    
        $text = str_replace(["/","'","#"],['\/',"\'","\#"],$text);
        $stmt = $connection->prepare("INSERT INTO `chats_info` (`chat_id`,`sent_date`,`msg_type`,`text`) VALUES (?,?,'USER',?)");
        $stmt->bind_param("iis",$ticketRowId, $time, $text);

        $keys = json_encode(['inline_keyboard'=>[
            [['text'=>\achbotMessage('support.create_ticket.button.3b90dccc67'),'callback_data'=>"reply_{$ticketRowId}"]]
        ]]);

        // ارسال به ادمین اصلی
        sendMessage($txt, $keys, "HTML", $admin);

        // ارسال به همه ادمین‌ها
        sendToAllAdmins('sendMessage', [
            'text' => $txt,
            'reply_markup' => $keys,
            'parse_mode' => "HTML"
        ]);

    } else {
        $txt = \achbotMessage('support.customer_conversation.user_all_tickets.txt.54b5fecc08', ['ticketTitle' => $ticketTitle, 'ticketCat' => $ticketCat, 'from_id' => $from_id, 'first_name' => $first_name, 'username' => $username, 'from_id_2' => $from_id]) . \achbotMessage('support.customer_conversation.user_all_tickets.text.46d8580795', ['caption' => $caption]);
        
        $stmt = $connection->prepare("INSERT INTO `chats_info` (`chat_id`,`sent_date`,`msg_type`,`text`) VALUES (?,?,'USER',?)");
        $text = json_encode(['file_id'=>$fileid, 'caption'=>$caption]);
        $stmt->bind_param("iis", $ticketRowId, $time, $text);

        $keys = json_encode(['inline_keyboard'=>[
            [['text'=>\achbotMessage('support.create_ticket.button.3b90dccc67'),'callback_data'=>"reply_{$ticketRowId}"]]
        ]]);

        sendPhoto($fileid, $txt,$keys, "HTML", $admin);
        sendToAllAdmins('sendPhoto', [
            'photo' => $fileid,
            'caption' => $txt,
            'reply_markup' => $keys,
            'parse_mode' => "HTML"
        ]);
    }
    $stmt->execute();
    $stmt->close();
                
    sendMessage(\achbotMessage('support.create_ticket.message.5c9c3dda00'),getMainKeys(),"HTML");
	setUser("none");
}
if(preg_match("/^rate_+([0-9])+_+([0-9])/",$data,$match)){
    $rowChatId = $match[1];
    $rate = $match[2];
    
    $stmt = $connection->prepare("SELECT * FROM `chats` WHERE `id` = ?");
    $stmt->bind_param("i",$rowChatId);
    $stmt->execute();
    $ticketInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $title = $ticketInfo['title'];
    $category = $ticketInfo['category'];
    
    
    $stmt = $connection->prepare("UPDATE `chats` SET `rate` = $rate WHERE `id` = ?");
    $stmt->bind_param("i", $rowChatId);
    $stmt->execute();
    $stmt->close();
    editText($message_id,\achbotMessage('support.customer_conversation.user_all_tickets.message.1d456424c3'));
    
    $keys = json_encode(['inline_keyboard'=>[
        [
            ['text'=>\achbotMessage('support.customer_conversation.user_all_tickets.button.105e0409bc'),'callback_data'=>"wizwizch"]
            ],
        ]]);

	$text = \achbotMessage('support.customer_conversation.user_all_tickets.text.497743bb13', ['from_id' => $from_id, 'first_name' => $first_name, 'username' => $username, 'title' => $title, 'category' => $category, 'rate' => $rate]);
	sendMessage($text, $keys, "HTML", $admin);
	sendToAllAdmins('sendMessage', ['text' => $text, 'reply_markup' => $keys, 'parse_mode' => "HTML"]);
}

