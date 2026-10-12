<?php
/** AChBot V6: original Telegram account/subscription routes; keep caller scope. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}



if(preg_match('/switchLocation(.+)_(.+)_(.+)_(.+)/', $data,$match)){
    $order_id = $match[1];
    $server_id = $match[2];
    $leftgp = $match[3];
    $expire = $match[4]; 
    /*if($expire < time() or $leftgp <= 0) {
        alert("سرویس شما غیرفعال است. لطفا ابتدا آن را تمدید کنید.",true);exit;
    }*/
    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `active` = 1 and `state` = 1 and ucount > 0 AND `id` != ?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();
    if($respd->num_rows == 0){
        alert('در حال حاضر هیچ سرور فعالی برای تغییر سرویس وجود ندارد.',true);
        exit;
    }
    $keyboard = [];
    while($cat = $respd->fetch_assoc()){
        $sid = $cat['id'];
        $name = $cat['title'];
        $keyboard[] = ['text' => "$name", 'callback_data' => "switchServer{$sid}_{$order_id}"];
    }
    $keyboard = array_chunk($keyboard,2);
    $keyboard[] = [['text' => $buttonValues['back_button'], 'callback_data' => "mainMenu"]];
    editText($message_id, ' 📍 لطفا برای تغییر سرویس فعلی، یکی از سرورها را انتخاب کنید👇',json_encode([
            'inline_keyboard' => $keyboard
        ]));
}
if(preg_match('/switchLocation(.+)_(.+)_(.+)_(.+)/', $data,$match)){
    $order_id = $match[1];
    $server_id = $match[2];
    $leftgp = $match[3];
    $expire = $match[4]; 
    /*if($expire < time() or $leftgp <= 0) {
        alert("سرویس شما غیرفعال است. لطفا ابتدا آن را تمدید کنید.",true);exit;
    }*/
    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `active` = 1 and `state` = 1 and ucount > 0 AND `id` != ?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();
    if($respd->num_rows == 0){
        alert('در حال حاضر هیچ سرور فعالی برای تغییر لوکیشن وجود ندارد.',true);
        exit;
    }
    $keyboard = [];
    while($cat = $respd->fetch_assoc()){
        $sid = $cat['id'];
        $name = $cat['title'];
        $keyboard[] = ['text' => "$name", 'callback_data' => "switchServer{$sid}_{$order_id}"];
    }
    $keyboard = array_chunk($keyboard,2);
    $keyboard[] = [['text' => $buttonValues['back_button'], 'callback_data' => "mainMenu"]];
    editText($message_id, ' 📍 لطفا برای تغییر لوکیشن سرویس فعلی، یکی از سرورها را انتخاب کنید👇',json_encode([
            'inline_keyboard' => $keyboard
        ]));
}

if(preg_match('/switchServer(.+)_(.+)/', $data, $match)){
    $sid = $match[1];  // ID جدید سرور
    $oid = $match[2];  // ID سفارش

    // گرفتن اطلاعات سفارش
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // گرفتن آی‌دی یوزر و ریمارک
    $user_id = $order['userid'];
    $remark = $order['remark'];

    // فقط تغییر آی‌دی سرور در جدول سفارش
    $stmt = $connection->prepare("UPDATE `orders_list` SET `server_id` = ? WHERE `id` = ?");
    $stmt->bind_param("ii", $sid, $oid);
    $stmt->execute();
    $stmt->close();

    // در صورتی که آی‌دی جدید سرور به درستی ذخیره شد
    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id` = ?");
    $stmt->bind_param("i", $sid);
    $stmt->execute();
    $server_title = $stmt->get_result()->fetch_assoc()['title'];
    $stmt->close();

    // ارسال پیام تایید تغییرات
    $msg = " 📍لوکیشن سرویس $remark به سرور جدید با نام $server_title تغییر یافت.\n";
    $adminMessage = "
🌐 تغییر نوع سرویس توسط ادمین

🔐 شناسه عددی: $user_id
🎈 نام کاربری اشتراک: $remark
🔄 سرور جدید: $server_title
";
    sendMessage($adminMessage, null, "html", $admin);
    sendToAllAdmins('sendMessage', ['text' => $adminMessage,'parse_mode' => "html"]);
    editText($message_id, $msg);
    exit();
}
/*
if(preg_match('/switchServer(.+)_(.+)/',$data,$match)){
    $sid = $match[1];
    $oid = $match[2];
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $inbound_id = $order['inbound_id'];
    $server_id = $order['server_id'];
    $remark = $order['remark'];
    $uuid = $order['uuid']??"0";
    $fid = $order['fileid'];
    $protocol = $order['protocol'];
	$link = json_decode($order['link'])[0];
	
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $fid); 
    $stmt->execute();
    $file_detail = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $flow = $file_detail['flow'] == "None"?"":$file_detail['flow'];
	
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $reality = $server_info['reality'];
    $serverType = $server_info['type'];
    $panelUrl = $server_info['panel_url'];

    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
    $stmt->bind_param("i", $sid);
    $stmt->execute();
    $srv_remark = $stmt->get_result()->fetch_assoc()['remark'];


    if($botState['remark'] == "digits"){
        $rnd = rand(10000,99999);
        $newRemark = "{$srv_remark}-{$rnd}";
    }else{
        $rnd = rand(1111,99999);
        $newRemark = "{$srv_remark}-{$from_id}-{$rnd}";
    }
	
    if(preg_match('/vmess/',$link)){
        $link_info = json_decode(base64_decode(str_replace('vmess://','',$link)));
        $uniqid = $link_info->id;
        $port = $link_info->port;
        $netType = $link_info->net;
    }else{
        $link_info = parse_url($link);
        $panel_ip = $link_info['host'];
        $uniqid = $link_info['user'];
        $protocol = $link_info['scheme'];
        $port = $link_info['port'];
        $netType = explode('type=',$link_info['query'])[1]; 
        $netType = explode('&',$netType)[0];
    }

    if($inbound_id > 0) {
        $remove_response = deleteClient($server_id, $inbound_id, $uuid);
		if(is_null($remove_response)){
			alert('🔻اتصال به سرور برقرار نیست. لطفا به مدیریت اطلاع بدید',true);
			exit;
		}
        if($remove_response){
            $total = $remove_response['total'];
            $up = $remove_response['up'];
            $down = $remove_response['down'];
			$id_label = $protocol == 'trojan' ? 'password' : 'id';
			if($serverType == "sanaei" || $serverType == "alireza"){
			    if($reality == "true"){
                    $newArr = [
                      "$id_label" => $uniqid,
                      "email" => $newRemark,
                      "enable" => true,
                      "flow" => $flow,
                      "limitIp" => $remove_response['limitIp'],
                      "totalGB" => $total - $up - $down,
                      "expiryTime" => $remove_response['expiryTime'],
                      "subId" => RandomString(16)
                    ];			        
			    }else{
                    $newArr = [
                      "$id_label" => $uniqid,
                      "email" => $newRemark,
                      "enable" => true,
                      "limitIp" => $remove_response['limitIp'],
                      "totalGB" => $total - $up - $down,
                      "expiryTime" => $remove_response['expiryTime'],
                      "subId" => RandomString(16)
                    ];
			    }
			}else{
                $newArr = [
                  "$id_label" => $uniqid,
                  "flow" => $remove_response['flow'],
                  "email" => $newRremark,
                  "limitIp" => $remove_response['limitIp'],
                  "totalGB" => $total - $up - $down,
                  "expiryTime" => $remove_response['expiryTime']
                ];
			}
            
            $response = addInboundAccount($sid, '', $inbound_id, 1, $newRemark, 0, 1, $newArr); 
            if(is_null($response)){
                alert('🔻اتصال به سرور برقرار نیست. لطفا به مدیریت اطلاع بدید',true);
                exit;
            }
			if($response == "inbound not Found"){
                alert("🔻سطر (inbound) با آیدی $inbound_id در این سرور یافت نشد. لطفا به مدیریت اطلاع بدید",true);
                exit;
            }
			if(!$response->success){
				alert('🔻خطا در ساخت اشتراک، لطفا به مدیریت اطلاع بدید',true);
				exit;
			}
			$vray_link = getConnectionLink($sid, $uniqid, $protocol, $newRemark, $port, $netType, $inbound_id);
			deleteClient($server_id, $inbound_id, $uuid, 1);
        }
    }else{
        $response = deleteInbound($server_id, $uuid);
		if(is_null($response)){
			alert('🔻اتصال به سرور برقرار نیست. لطفا به مدیریت اطلاع بدید',true);
			exit;
		}
        if($response){
            if($serverType == "marzban"){
                $response = addMarzbanUser($server_id, $newRemark, $volume, $days, $fid);
                if(!$response->success){
                    if($response->msg == "User already exists"){
                        $newRemark .= rand(1111,99999);
                        $response = addMarzbanUser($server_id, $newRemark, $volume, $days, $fid);
                    }
                }
                $uniqid = $token = str_replace("/sub/", "", $response->sub_link);
                $subLink = $botState['subLinkState'] == "on"?$panelUrl . $response->sub_link:"";
                $vraylink = $response->vray_links;

                $stmt = $connection->prepare("UPDATE `orders_list` SET `token` = ?, `uuid` =? WHERE `id` = ?");
                $stmt->bind_param("ssi", $token, $uniqid, $oid);
                $stmt->execute();
                $stmt->close();

            }else{
                $res = addUser($sid, $response['uniqid'], $response['protocol'], $response['port'], $response['expiryTime'], $newRemark, $response['volume'] / 1073741824, $response['netType'], $response['security']);
                $vray_link = getConnectionLink($sid, $response['uniqid'], $response['protocol'], $newRemark, $response['port'], $response['netType'], $inbound_id);
            }
            deleteInbound($server_id, $uuid, 1);
        }
    }
    $stmt = $connection->prepare("UPDATE `server_info` SET `ucount` = `ucount` + 1 WHERE `id` = ?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $stmt->close();

    $stmt = $connection->prepare("UPDATE `server_info` SET `ucount` = `ucount` - 1 WHERE `id` = ?");
    $stmt->bind_param("i", $sid);
    $stmt->execute();
    $stmt->close();

    $vray_link = json_encode($vray_link);
    $stmt = $connection->prepare("UPDATE `orders_list` SET `server_id` = ?, `link`=?, `remark` = ? WHERE `id` = ?");
    $stmt->bind_param("issi", $sid, $vray_link, $newRemark, $oid);
    $stmt->execute();
    $stmt->close();

    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id` = ?");
    $stmt->bind_param("i", $sid);
    $stmt->execute();
    $server_title = $stmt->get_result()->fetch_assoc()['title'];
    $stmt->close();
    
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `userid` = ? AND `status` = 1 ORDER BY `id` DESC");
    $stmt->bind_param("i", $from_id);
    $stmt->execute();
    $orders = $stmt->get_result();
    $stmt->close();
    
    $keyboard = [];
    while($cat = $orders->fetch_assoc()){
        $id = $cat['id'];
        $cremark = $cat['remark'];
        $keyboard[] = ['text' => "$cremark", 'callback_data' => "orderDetails$id"];
    }
    $keyboard = array_chunk($keyboard,2);
    $keyboard[] = [['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]];
    $msg = " 📍لوکیشن سرویس $remark به $server_title با نام کاربری $newRemark تغییر یافت.\n لطفا برای مشاهده مشخصات، روی آن بزنید👇";
    
    editText($message_id, $msg,json_encode([
            'inline_keyboard' => $keyboard
        ]));
    exit();
}
*/
