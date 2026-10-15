<?php
include_once __DIR__ . '/../baseInfo.php';
include_once __DIR__ . '/../config.php';

$time = time();

$stmt = $connection->prepare("SELECT * FROM `gift_list` LIMIT 1");
$stmt->execute();
$giftList = $stmt->get_result();
$stmt->close();

if($giftList->num_rows>0){
    $info = $giftList->fetch_assoc();
    $rowId = $info['id'];
    $server_id = $info['server_id'];
    $volume = $info['volume'];
    $day = $info['day'];
    $offset = $info['offset'];

    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id` = ?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $serverName = $stmt->get_result()->fetch_assoc()['title'];
    $stmt->close();

    $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id` = ?");
    $stmt->bind_param('i', $server_id);
    $stmt->execute();
    $serverConfig = $stmt->get_result()->fetch_assoc();
    $serverType = $serverConfig['type'] ?? null;
    $stmt->close();

    // Never advance the gift cursor for a removed/unknown panel type.
    if (!achbotSupportsPanel($serverType)) {
        sendMessage("پنل انتخابی پشتیبانی نمی‌شود؛ عملیات هدیه متوقف شد.", null, null, $admin);
        exit;
    }

    if($offset == 0) sendMessage("عملیات هدیه حجم و زمان سرور $serverName شروع شد.",null,null,$admin);
    sendToAllAdmins('sendMessage', ['text' => "عملیات هدیه حجم و زمان سرور $serverName شروع شد.", 'reply_markup' => null, 'parse_mode' => null]);
    
    $rowCount = -1;
    $found = false;
    
    if($serverType == "marzban"){
        $usersList = getMarzbanJson($server_id)->users;
        foreach($usersList as $key => $config){
            $rowCount++;
            if($rowCount < $offset) continue;
            $found = true;
            
    	    $remark = $config->username;
            $total = $config->data_limit;
            $expiry_time = $config->expire;
            $state = $config->status == "active"?true:false;

            if($total > 0 && $expiry_time > 0 && $state){
                $editReponse = editMarzbanConfig($server_id, ['remark'=>$remark, 'plus_volume'=>$volume/1024, 'plus_day'=> $day]);
            }

            if(!is_null($editReponse)){
                $orderExistStmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `server_id` = ? AND `inbound_id` = 0 AND `remark` = ?");
                $orderExistStmt->bind_param("is", $server_id, $remark);
                $orderExistStmt->execute();
                $orderExist = $orderExistStmt->get_result();
                $orderExistStmt->close();
                
                if($orderExist->num_rows > 0){
                    $orderInfo = $orderExist->fetch_assoc();
                    $orderId = $orderInfo['id'];
                    $user_id = $orderInfo['userid'];
                    $gift = "";
                    if($volume != 0) $gift = "$volume مگابایت حجم ";
                    if($day != 0){
                        $expire_date = $day * 86400;
                        $gift .= " و $day روز زمان";
                        $stmt = $connection->prepare("UPDATE `orders_list` SET `expire_date` = `expire_date` + ? WHERE `id` = ?");
                        $stmt->bind_param("ii", $expire_date, $orderId);
                        $stmt->execute();
                        $stmt->close();
                    }
                        
                    
                    sendMessage("به اشتراک $remark شما $gift اضافه شد",null,null,$user_id);
                }
            }
            
            $stmt = $connection->prepare("UPDATE `gift_list` SET `offset` = `offset` + 1 WHERE `id` = ?");
            $stmt->bind_param("i", $rowId);
            $stmt->execute();
            $stmt->close();
        }
    }
    
    if($found == false){
        $stmt = $connection->prepare("DELETE FROM `gift_list` WHERE `id` = ?");
        $stmt->bind_param("i", $rowId);
        $stmt->execute();
        $stmt->close();
        
        sendMessage("عملیات هدیه حجم و زمان سرور $serverName به اتمام رسید.",null,null,$admin);
        sendToAllAdmins('sendMessage', ['text' => "عملیات هدیه حجم و زمان سرور $serverName به اتمام رسید.", 'reply_markup' => null, 'parse_mode' => null]);
    }
}
