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
            $sentType = $ticketInfo['msg_type']=="ADMIN"?"پشتیبانی":"کاربر";
            if($state !=2){
                $keys = [
                        [['text'=>"بستن تیکت 🗳",'callback_data'=>"closeTicket_$rowId"],['text'=>"پاسخ به تیکت 📝",'callback_data'=>"replySupport_{$rowId}"]],
                        [['text'=>"آخرین پیام ها 📩",'callback_data'=>"latestMsg_$rowId"]]
                        ];
            }
            else{
                $keys = [
                    [['text'=>"آخرین پیام ها 📩",'callback_data'=>"latestMsg_$rowId"]]
                    ];
            }
                
            if(isset(json_decode($lastmsg,true)['file_id'])){
                $info = json_decode($lastmsg,true);
                $fileid = $info['file_id'];
                $caption = $info['caption'];
                $txt ="🔘 موضوع: $title
            		💭 دسته بندی:  {$category}
            		\n
            		$sentType: $caption";
                sendPhoto($fileid, $txt,json_encode(['inline_keyboard'=>$keys]), "HTML");
            }else{
                sendMessage("🔘 موضوع: $title
            		💭 دسته بندی:  {$category}
            		\n
            		$sentType: $lastmsg",json_encode(['inline_keyboard'=>$keys]),"HTML");
            }

			if($current>=$cont){
			    break;
			}
        }
        
		if($allList > $cont){
		    sendmessage("موارد بیشتر",json_encode(['inline_keyboard'=>[
                		        [['text'=>"دریافت",'callback_data'=>"moreTicket_{$type}_{$cont}"]]
                		        ]]),"HTML");
		}
	}else{
	    alert("تیکتی یافت نشد");
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

    $ticketClosed = " $title 🟰 $category \n\n" . "این تیکت بسته شد\n به این تیکت رأی بدهید";;
    
    $keys = json_encode(['inline_keyboard'=>[
        [['text'=>"بسیار بد 😠",'callback_data'=>"rate_{$chatRowId}_1"]],
        [['text'=>"بد 🙁",'callback_data'=>"rate_{$chatRowId}_2"]],
        [['text'=>"خوب 😐",'callback_data'=>"rate_{$chatRowId}_3"]],
        [['text'=>"بسیار خوب 😃",'callback_data'=>"rate_{$chatRowId}_4"]],
        [['text'=>"عالی 🤩",'callback_data'=>"rate_{$chatRowId}_5"]]
        ]]);
    sendMessage($ticketClosed,$keys,'html');
    
    $keys = json_encode(['inline_keyboard'=>[
        [
            ['text'=>"$from_id",'callback_data'=>"wizwizch"],
            ['text'=>"شناسه عددی",'callback_data'=>'wizwizch']
        ],
        [
            ['text'=>$first_name??" ",'callback_data'=>"wizwizch"],
            ['text'=>"اسم کاربر",'callback_data'=>'wizwizch']
        ],
        [
            ['text'=>"$title",'callback_data'=>'wizwizch'],
            ['text'=>"عنوان",'callback_data'=>'wizwizch']
        ],
        [
            ['text'=>"$category",'callback_data'=>'wizwizch'],
            ['text'=>"دسته بندی",'callback_data'=>'wizwizch']
        ],
        ]]);
    sendMessage("☑️ تیکت توسط کاربر بسته شد.",$keys,"HTML",$admin);
    sendToAllAdmins('sendMessage', ['text' => "☑️ تیکت توسط کاربر بسته شد.", 'reply_markup' => $keys, 'parse_mode' => "HTML"]);

}
if(preg_match('/^replySupport_(.*)/',$data,$match)){
    delMessage();
    sendMessage("💠 لطفا متن پیام خود را بصورت ساده و مختصر ارسال کنید!",$cancelKey);
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
        $txt = "پیام جدید:\n[$ticketTitle] <i>{$ticketCat}</i>\n\nکاربر: <a href='tg://user?id=$from_id'>$first_name</a>\nنام کاربری: $username\nشناسه عددی: $from_id\n" . "\nمتن پیام: $text";
    
        $text = str_replace(["/","'","#"],['\/',"\'","\#"],$text);
        $stmt = $connection->prepare("INSERT INTO `chats_info` (`chat_id`,`sent_date`,`msg_type`,`text`) VALUES (?,?,'USER',?)");
        $stmt->bind_param("iis",$ticketRowId, $time, $text);

        $keys = json_encode(['inline_keyboard'=>[
            [['text'=>"پاسخ",'callback_data'=>"reply_{$ticketRowId}"]]
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
        $txt = "پیام جدید:\n[$ticketTitle] <i>{$ticketCat}</i>\n\nکاربر: <a href='tg://user?id=$from_id'>$first_name</a>\nنام کاربری: $username\nشناسه عددی: $from_id\n" . "\nمتن پیام: $caption";
        
        $stmt = $connection->prepare("INSERT INTO `chats_info` (`chat_id`,`sent_date`,`msg_type`,`text`) VALUES (?,?,'USER',?)");
        $text = json_encode(['file_id'=>$fileid, 'caption'=>$caption]);
        $stmt->bind_param("iis", $ticketRowId, $time, $text);

        $keys = json_encode(['inline_keyboard'=>[
            [['text'=>"پاسخ",'callback_data'=>"reply_{$ticketRowId}"]]
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
                
    sendMessage("پیام شما با موفقیت ثبت شد.",getMainKeys(),"HTML");
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
    editText($message_id,"✅");
    
    $keys = json_encode(['inline_keyboard'=>[
        [
            ['text'=>"رای تیکت",'callback_data'=>"wizwizch"]
            ],
        ]]);

	$text = "📨 رأی به تیکت 

👤 شناسه عددی: $from_id
❕ نام کاربر: $first_name
❗️ نام کاربری: @$username
〽️ عنوان: $title
⚜️ دسته بندی: $category
❤️ رای: $rate
";
	sendMessage($text, $keys, "HTML", $admin);
	sendToAllAdmins('sendMessage', ['text' => $text, 'reply_markup' => $keys, 'parse_mode' => "HTML"]);
}

