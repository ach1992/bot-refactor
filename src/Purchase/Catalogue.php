<?php
/** AChBot V7: immutable V6.1 purchase or plan route. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG


require_once __DIR__ . '/../Finance/PaymentLifecycle.php';

if(preg_match('/\AselectServer(?<serverId>[1-9][0-9]*)_(?<buyType>none|one|much)\z/D',$data, $match) && ($botState['sellState']=="on" || ($from_id == $admin || $userInfo['isAdmin'] == true)) ) {
    if ($match['buyType']!=='none' && (int)$userInfo['is_agent']!==1) return;
    $sid = $match['serverId'];
        
    $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `parent`=0 AND `active`=1 order by `id` asc");
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();
    if($respd->num_rows == 0){
        alert(\achbotMessage('legacy_messages.category_not_avilable'));
    }else{
        
        $keyboard = [];
        while ($file = $respd->fetch_assoc()){
            $id = $file['id'];
            $name = $file['title'];
            $rowcount=count(achbotPurchaseOfferings($connection,$botState,$match['buyType'],(int)$sid,(int)$id));
            if($rowcount>0) $keyboard[] = ['text' => "$name", 'callback_data' => "selectCategory{$id}_{$sid}_{$match['buyType']}"];
        }
        if(empty($keyboard)){
            alert(\achbotMessage('legacy_messages.category_not_avilable'));exit;
        }
        alert(\achbotMessage('legacy_messages.receive_categories'));

        $keyboard[] = ['text' => \achbotMessage('legacy_buttons.back_to_main'), 'callback_data' =>
        ($match['buyType'] == "one"?"agentOneBuy":($match['buyType'] == "much"?"agentMuchBuy":"buySubscription"))];
        $keyboard = array_chunk($keyboard,1);
        editText($message_id,\achbotMessage('legacy_messages.buy_sub_select_category'), json_encode(['inline_keyboard'=>$keyboard]));
    }

}
if(preg_match('/\AselectCategory(?<categoryId>[1-9][0-9]*)_(?<serverId>[1-9][0-9]*)_(?<buyType>none|one|much)\z/D',$data,$match) && ($botState['sellState']=="on" || $from_id == $admin || $userInfo['isAdmin'] == true)) {
    if ($match['buyType']!=='none' && (int)$userInfo['is_agent']!==1) return;
    $call_id = $match['categoryId'];
    $sid = $match['serverId'];
    $respd=achbotSaleablePlans($connection,(int)$sid,(int)$call_id);
    $custom=achbotCustomPurchaseEnabled($botState,$match['buyType']) &&
        achbotAvailablePlans($connection,(int)$sid,(int)$call_id,'custom')!==[];
    if(!$respd && !$custom){
        alert(\achbotMessage('legacy_messages.no_plan_available'));
    }else{
        alert(\achbotMessage('legacy_messages.receive_plans'));
        $keyboard = [];
        foreach($respd as $file){
            $id = $file['id'];
            $name = $file['title'];
            $price = $file['price'];
            if($userInfo['is_agent'] == true && ($match['buyType'] == "one" || $match['buyType'] == "much")){
                $discounts = json_decode($userInfo['discount_percent'],true);
                if(($botState['agencyPlanDiscount']??'off')=="on") $discount = $discounts['plans'][$id]?? $discounts['normal'];
                else $discount = $discounts['servers'][$sid]?? $discounts['normal'];
                
                $price -= floor($price * $discount / 100);
            }
            $price = ($price == 0) ? \achbotMessage('purchase.catalogue.text.dda7ff2974') : number_format($price).\achbotMessage('purchase.catalogue.text.bfb7221456');
            $keyboard[] = ['text' => "$name - $price", 'callback_data' => "selectPlan{$id}_{$call_id}_{$match['buyType']}"];
        }
        if($custom){
	        $keyboard[] = ['text' => \achbotMessage('legacy_messages.buy_custom_plan'), 'callback_data' => "selectCustomPlan{$call_id}_{$sid}_{$match['buyType']}"];
        }
        $keyboard[] = ['text' => \achbotMessage('legacy_buttons.back_to_main'), 'callback_data' => "selectServer{$sid}_{$match['buyType']}"];
        $keyboard = array_chunk($keyboard,1);
        editText($message_id,\achbotMessage('legacy_messages.buy_sub_select_plan'), json_encode(['inline_keyboard'=>$keyboard]));
    }

}
if(preg_match('/\AselectCustomPlan(?<categoryId>[1-9][0-9]*)_(?<serverId>[1-9][0-9]*)_(?<buyType>none|one)\z/D',$data,$match) && ($botState['sellState']=="on" || $from_id == $admin || $userInfo['isAdmin'] == true)) {
    if ($match['buyType']!=='none' && (int)$userInfo['is_agent']!==1) return;
    $call_id = $match['categoryId'];
    $sid = $match['serverId'];
    if (!achbotCustomPurchaseEnabled($botState,$match['buyType'])) return;
    $respd=achbotAvailablePlans($connection,(int)$sid,(int)$call_id,'custom');
    if (!$respd) {alert(achbotMessage('legacy_messages.no_plan_available'));return;}
    alert(\achbotMessage('legacy_messages.receive_plans'));
    $keyboard = [];
    foreach($respd as $file){
        $id = $file['id'];
        $name = preg_replace(\achbotMessage('purchase.catalogue.text.1bc7ba6a31'),"",$file['title']);
        $keyboard[] = ['text' => "$name", 'callback_data' => "selectCustomePlan{$id}_{$call_id}_{$match['buyType']}"];
    }
    $keyboard[] = ['text' => \achbotMessage('legacy_buttons.back_to_main'), 'callback_data' => "selectServer{$sid}_{$match['buyType']}"];
    $keyboard = array_chunk($keyboard,1);
    editText($message_id, \achbotMessage('legacy_messages.select_one_plan_to_edit'), json_encode(['inline_keyboard'=>$keyboard]));

}
if(preg_match('/\AselectCustomePlan(?<planId>[1-9][0-9]*)_(?<categoryId>[1-9][0-9]*)_(?<buyType>none|one)\z/D',$data, $match) && ($botState['sellState']=="on" ||$from_id == $admin)){
    if (!achbotCustomPurchaseEnabled($botState,$match['buyType']) ||
        ($match['buyType']==='one' && (int)$userInfo['is_agent']!==1)) return;
    $eligible=array_column(achbotAvailablePlans($connection,null,(int)$match['categoryId'],'custom'),'id');
    if (!in_array((int)$match['planId'],array_map('intval',$eligible),true)) {
        alert(\achbotMessage('purchase.catalogue.alert.67fda139df'), true);
        return;
    }
	delMessage();
	$price = $botState['gbPrice'];
	if($match['buyType'] == "one" && $userInfo['is_agent'] == true){ 
        $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ?");
        $stmt->bind_param("i", $match[1]);
        $stmt->execute();
        $serverId = $stmt->get_result()->fetch_assoc()['server_id'];
        $stmt->close();

        $discounts = json_decode($userInfo['discount_percent'],true);
        if(($botState['agencyPlanDiscount']??'off')=="on") $discount = $discounts['plans'][$match[1]]?? $discounts['normal'];
        else $discount = $discounts['servers'][$serverId]?? $discounts['normal'];
        $price -= floor($price * $discount / 100);
	}
	sendMessage(str_replace("VOLUME-PRICE", $price, \achbotMessage('legacy_messages.customer_custome_plan_volume')),$cancelKey);
	setUser("selectCustomPlanGB" . $match[1] . "_" . $match[2] . "_" . $match['buyType']);
}
if(preg_match('/selectCustomPlanGB(?<planId>\d+)_(?<categoryId>\d+)_(?<buyType>\w+)/',$userInfo['step'], $match) && ($botState['sellState']=="on" ||$from_id == $admin) && $text != \achbotMessage('legacy_buttons.cancel')){
    if(!is_numeric($text)){
        sendMessage(\achbotMessage('purchase.catalogue.message.ef774be0a5'), null, 'HTML');
        exit();
    }
    elseif($text <1){
        sendMessage(\achbotMessage('purchase.catalogue.message.05308dd049'), null, 'HTML');
        exit();
    }
    elseif(strstr($text,".")){
        sendMessage(\achbotMessage('purchase.catalogue.message.bf8ff96ef3'), null, 'HTML');
        exit();
    }
    elseif(substr($text, 0, 1) == '0'){
        sendMessage(\achbotMessage('purchase.catalogue.message.b989e71b4f'), null, 'HTML');
        exit();
    }
    
    $id = $match['planId'];
    $price = $botState['dayPrice'];
	if($match['buyType'] == "one" && $userInfo['is_agent'] == true){
        $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $serverId = $stmt->get_result()->fetch_assoc()['server_id'];
        $stmt->close();

        $discounts = json_decode($userInfo['discount_percent'],true);
        if(($botState['agencyPlanDiscount']??'off')=="on") $discount = $discounts['plans'][$id]?? $discounts['normal'];
        else $discount = $discounts['servers'][$serverId]?? $discounts['normal'];
        $price -= floor($price * $discount / 100);
	}
    
	sendMessage(str_replace("DAY-PRICE", $price, \achbotMessage('legacy_messages.customer_custome_plan_day')));
	setUser("selectCustomPlanDay" . $id . "_" . $match['categoryId'] . "_" . $text . "_" . $match['buyType']);
}
if((preg_match('/selectCustomPlanDay(?<planId>\d+)_(?<categoryId>\d+)_(?<accountCount>\d+)_(?<buyType>\w+)/',$userInfo['step'], $match)) && ($botState['sellState']=="on" ||$from_id == $admin) && $text != \achbotMessage('legacy_buttons.cancel')){
    if(!is_numeric($text)){
        sendMessage(\achbotMessage('purchase.catalogue.message.ef774be0a5'), null, 'HTML');
        exit();
    }
    elseif($text <1){
        sendMessage(\achbotMessage('purchase.catalogue.message.05308dd049'), null, 'HTML');
        exit();
    }
    elseif(strstr($text,".")){
        sendMessage(\achbotMessage('purchase.catalogue.message.3fbe481726'), null, 'HTML');
        exit();
    }
    elseif(substr($text, 0, 1) == '0'){
        sendMessage(\achbotMessage('purchase.catalogue.message.b989e71b4f'), null, 'HTML');
        exit();
    }

	sendMessage(\achbotMessage('legacy_messages.customer_custome_plan_name'), null, 'HTML');
	setUser("enterCustomPlanName" . $match['planId'] . "_" . $match['categoryId'] . "_" . $match['accountCount'] . "_" . $text . "_" . $match['buyType']);
}

if(isset($update->message->text) && !isset($update->callback_query) && preg_match('/\AenterCustomPlanName(\d+)_(\d+)_(\d+)_(\d+)_(?<buyType>\w+)\z/D',$userInfo['step'],$match) &&
    ($botState['sellState']=="on" ||$from_id==$admin) && $text!=achbotMessage('legacy_buttons.cancel')){
    if (!achbotSupportsPlan($connection,(int)$match[1])) {
        alert(achbotMessage('purchase.catalogue.alert.10fd7f4fa9'),true);return;
    }
        $id = $match[1]; 
    	$call_id = $match[2]; 
    	$volume = $match[3]; 
        $days = $match[4]; 
        // Both regular and agent "much" custom names become durable
        // remote usernames. Reject invalid names before draft creation;
        // the wallet debit boundary independently rechecks persisted data.
        require_once __DIR__.'/../Finance/ServiceEffects.php';
        if (!achbotEffectValidUsername((string)$text) ||
            ($match['buyType'] !== 'much' &&
             preg_match('/^[a-z]+[0-9]+$/D',(string)$text)!==1)) {
            sendMessage(\achbotMessage('legacy_messages.incorrect_config_name'), null, 'HTML');
            exit();
        }
    if (!achbotCustomPurchaseEnabled($botState,$match['buyType']) ||
        ($match['buyType']==='one' && (int)$userInfo['is_agent']!==1)) return;
    $respd=null;
    foreach (achbotAvailablePlans($connection,null,(int)$call_id,'custom') as $candidate)
        if ((int)$candidate['id']===(int)$id) {$respd=$candidate;break;}
    if (!$respd) {
        alert(achbotMessage('purchase.catalogue.alert.10fd7f4fa9'),true);return;
    }
    $sid=$respd['server_id'];
        if (!isset($botState['gbPrice'],$botState['dayPrice'])) {
            alert(achbotMessage('purchase.catalogue.alert.201380d8bf'),true);return;
        }
        $gbPrice = $botState['gbPrice']; 
        $dayPrice = $botState['dayPrice']; 
         
        if($userInfo['is_agent'] == true && $match['buyType'] == "one") { 
            $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ?"); 
            $stmt->bind_param("i", $match[1]); 
            $stmt->execute(); 
            $serverId = $stmt->get_result()->fetch_assoc()['server_id']; 
            $stmt->close(); 
             
            $discounts = json_decode($userInfo['discount_percent'],true); 
            if(($botState['agencyPlanDiscount']??'off')=="on") $discount = $discounts['plans'][$id]?? $discounts['normal'];
            else $discount = $discounts['servers'][$sid]?? $discounts['normal']; 
             
            $gbPrice -= floor($gbPrice * $discount /100); 
            $dayPrice -= floor($dayPrice * $discount / 100); 
        } 
         
        $agentBought = false; 
        if($userInfo['is_agent'] == 1 && ($match['buyType'] == "one" || $match['buyType'] == "much")) { 
            $agentBought = true; 
        } 
         
        $price =  ($volume * $gbPrice) + ($days * $dayPrice); 
        $hash_id = RandomString(); 

         
        $time = time(); 
        $stmt = $connection->prepare("INSERT INTO `pays` (`hash_id`, `description`, `user_id`, `type`, `plan_id`, `server_id`, `volume`, `day`, `price`, `request_date`, `state`, `agent_bought`) 
									VALUES (?, ?, ?, 'BUY_SUB', ?, ?, ?, ?, ?, ?, 'pending', ?)"); 
		$stmt->bind_param("ssiiiiiiii", $hash_id, $text, $from_id, $id, $sid, $volume, $days, $price, $time, $agentBought); 
        $rowId=achbotPayUxInsertDraft($connection,(int)$from_id,$stmt);
        $stmt->close();
        if ($rowId<=0) {
            alert(\achbotMessage('purchase.catalogue.alert.201380d8bf'),true);
            return;
        }
    require_once __DIR__.'/CheckoutPresentation.php';
    if (!achbotRenderNewCheckoutDraft($connection,$rowId,(int)$from_id,$botState,$botUrl))
        alert(achbotMessage('purchase.catalogue.alert.201380d8bf'),true);
    setUser();
    return;
}
