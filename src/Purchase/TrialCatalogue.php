<?php
/** AChBot V7: immutable V6.1 purchase or plan route. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

if($data=="getTestAccount"){
    if($userInfo['freetrial'] != null && $from_id != $admin && $userInfo['isAdmin'] != true){
        alert("شما اکانت تست را قبلا استفاده کرده اید.");
        exit();
    }
    $stmt = $connection->prepare("SELECT p.* FROM `server_plans` p INNER JOIN `server_config` sc ON sc.`id`=p.`server_id` AND sc.`type` IN ('marzban','pasarguard') WHERE p.`price`=0 AND p.`active`=1 AND p.`inbound_id`=0");
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();
    
    if($respd->num_rows > 0){
        alert($mainValues['receving_information']);
    	$keyboard = array();
        while ($row = $respd->fetch_assoc()){
            $id = $row['id'];
            $catInfo = $connection->prepare("SELECT * FROM `server_categories` WHERE `id`=?");
            $catInfo->bind_param("i", $row['catid']);
            $catInfo->execute();
            $catname = $catInfo->get_result()->fetch_assoc()['title'];
            $catInfo->close();
            
            $name = $catname." ".$row['title'];
            $price =  $row['price'];
            $desc = $row['descr'];
        	$sid = $row['server_id'];

            $keyboard[] = [['text' => $name, 'callback_data' => "freeTrial{$id}_normal"]];

        }
    	$keyboard[] = [['text' => $buttonValues['back_to_main'], 'callback_data' => "mainMenu"]];
        editText($message_id,"لطفا یکی از کلید های زیر را انتخاب کنید.", json_encode(['inline_keyboard'=>$keyboard]), "HTML");
    }else alert("این بخش موقتا غیر فعال است.");
}

