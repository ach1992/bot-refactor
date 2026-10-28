<?php
/** AChBot V7: immutable V6.1 purchase or plan route. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG


require_once __DIR__ . '/../Finance/PaymentLifecycle.php';

if(preg_match('/selectServer(?<serverId>\d+)_(?<buyType>\w+)/',$data, $match) && ($botState['sellState']=="on" || ($from_id == $admin || $userInfo['isAdmin'] == true)) ) {
    $sid = $match['serverId'];
        
    $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `parent`=0 AND `active`=1 order by `id` asc");
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();
    if($respd->num_rows == 0){
        alert($mainValues['category_not_avilable']);
    }else{
        
        $keyboard = [];
        while ($file = $respd->fetch_assoc()){
            $id = $file['id'];
            $name = $file['title'];
            $stmt = $connection->prepare("SELECT p.* FROM `server_plans` p INNER JOIN `server_config` sc ON sc.`id`=p.`server_id` AND sc.`type` IN (".achbotProviderSqlTypes().") INNER JOIN `server_info` si ON si.`id`=p.`server_id` AND si.`active`=1 AND si.`state`=1 AND si.`ucount`>0 WHERE p.`server_id`=? and p.`catid`=? and p.`active`=1 AND p.`inbound_id`=0");
            $stmt->bind_param("ii", $sid, $id);
            $stmt->execute();
            $rowcount = $stmt->get_result()->num_rows; 
            $stmt->close();
            if($rowcount>0) $keyboard[] = ['text' => "$name", 'callback_data' => "selectCategory{$id}_{$sid}_{$match['buyType']}"];
        }
        if(empty($keyboard)){
            alert($mainValues['category_not_avilable']);exit;
        }
        alert($mainValues['receive_categories']);

        $keyboard[] = ['text' => $buttonValues['back_to_main'], 'callback_data' => 
        ($match['buyType'] == "one"?"agentOneBuy":($match['buyType'] == "much"?"agentMuchBuy":"buySubscription"))];
        $keyboard = array_chunk($keyboard,1);
        editText($message_id,$mainValues['buy_sub_select_category'], json_encode(['inline_keyboard'=>$keyboard]));
    }

}
if(preg_match('/selectCategory(?<categoryId>\d+)_(?<serverId>\d+)_(?<buyType>\w+)/',$data,$match) && ($botState['sellState']=="on" || $from_id == $admin || $userInfo['isAdmin'] == true)) {
    $call_id = $match['categoryId'];
    $sid = $match['serverId'];
    $stmt = $connection->prepare("SELECT p.* FROM `server_plans` p INNER JOIN `server_config` sc ON sc.`id`=p.`server_id` AND sc.`type` IN (".achbotProviderSqlTypes().") INNER JOIN `server_info` si ON si.`id`=p.`server_id` AND si.`active`=1 AND si.`state`=1 AND si.`ucount`>0 WHERE p.`server_id`=? and p.`price` != 0 and p.`catid`=? and p.`active`=1 AND p.`inbound_id`=0 order by p.`id` asc");
    $stmt->bind_param("ii", $sid, $call_id);
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();
    if($respd->num_rows==0){
        alert($mainValues['no_plan_available']); 
    }else{
        alert($mainValues['receive_plans']);
        $keyboard = [];
        while($file = $respd->fetch_assoc()){
            $id = $file['id'];
            $name = $file['title'];
            $price = $file['price'];
            if($userInfo['is_agent'] == true && ($match['buyType'] == "one" || $match['buyType'] == "much")){
                $discounts = json_decode($userInfo['discount_percent'],true);
                if($botState['agencyPlanDiscount']=="on") $discount = $discounts['plans'][$id]?? $discounts['normal'];
                else $discount = $discounts['servers'][$sid]?? $discounts['normal'];
                
                $price -= floor($price * $discount / 100);
            }
            $price = ($price == 0) ? \achbotMessage('purchase.catalogue.text.dda7ff2974') : number_format($price).\achbotMessage('purchase.catalogue.text.bfb7221456');
            $keyboard[] = ['text' => "$name - $price", 'callback_data' => "selectPlan{$id}_{$call_id}_{$match['buyType']}"];
        }
        if($botState['plandelkhahState'] == "on" && $match['buyType'] != "much"){
	        $keyboard[] = ['text' => $mainValues['buy_custom_plan'], 'callback_data' => "selectCustomPlan{$call_id}_{$sid}_{$match['buyType']}"];
        }
        $keyboard[] = ['text' => $buttonValues['back_to_main'], 'callback_data' => "selectServer{$sid}_{$match['buyType']}"];
        $keyboard = array_chunk($keyboard,1);
        editText($message_id,$mainValues['buy_sub_select_plan'], json_encode(['inline_keyboard'=>$keyboard]));
    }

}
if(preg_match('/selectCustomPlan(?<categoryId>\d+)_(?<serverId>\d+)_(?<buyType>\w+)/',$data,$match) && ($botState['sellState']=="on" || $from_id == $admin || $userInfo['isAdmin'] == true)) {
    $call_id = $match['categoryId'];
    $sid = $match['serverId'];
    $stmt = $connection->prepare("SELECT p.* FROM `server_plans` p INNER JOIN `server_config` sc ON sc.`id`=p.`server_id` AND sc.`type` IN (".achbotProviderSqlTypes().") INNER JOIN `server_info` si ON si.`id`=p.`server_id` AND si.`active`=1 AND si.`state`=1 AND si.`ucount`>0 WHERE p.`server_id`=? and p.`catid`=? and p.`active`=1 AND p.`inbound_id`=0 order by p.`id` asc");
    $stmt->bind_param("ii", $sid, $call_id);
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();
    alert($mainValues['receive_plans']);
    $keyboard = [];
    while($file = $respd->fetch_assoc()){
        $id = $file['id'];
        $name = preg_replace(\achbotMessage('purchase.catalogue.text.1bc7ba6a31'),"",$file['title']);
        $keyboard[] = ['text' => "$name", 'callback_data' => "selectCustomePlan{$id}_{$call_id}_{$match['buyType']}"];
    }
    $keyboard[] = ['text' => $buttonValues['back_to_main'], 'callback_data' => "selectServer{$sid}_{$match['buyType']}"];
    $keyboard = array_chunk($keyboard,1);
    editText($message_id, $mainValues['select_one_plan_to_edit'], json_encode(['inline_keyboard'=>$keyboard]));

}
if(preg_match('/selectCustomePlan(?<planId>\d+)_(?<categoryId>\d+)_(?<buyType>\w+)/',$data, $match) && ($botState['sellState']=="on" ||$from_id == $admin)){
    if (!achbotSupportsPlan($connection, (int)$match['planId'])) {
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
        if($botState['agencyPlanDiscount']=="on") $discount = $discounts['plans'][$match[1]]?? $discounts['normal'];
        else $discount = $discounts['servers'][$serverId]?? $discounts['normal'];
        $price -= floor($price * $discount / 100);
	}
	sendMessage(str_replace("VOLUME-PRICE", $price, $mainValues['customer_custome_plan_volume']),$cancelKey);
	setUser("selectCustomPlanGB" . $match[1] . "_" . $match[2] . "_" . $match['buyType']);
}
if(preg_match('/selectCustomPlanGB(?<planId>\d+)_(?<categoryId>\d+)_(?<buyType>\w+)/',$userInfo['step'], $match) && ($botState['sellState']=="on" ||$from_id == $admin) && $text != $buttonValues['cancel']){
    if(!is_numeric($text)){
        sendMessage(\achbotMessage('purchase.catalogue.message.ef774be0a5'));
        exit();
    }
    elseif($text <1){
        sendMessage(\achbotMessage('purchase.catalogue.message.05308dd049'));
        exit();
    }
    elseif(strstr($text,".")){
        sendMessage(\achbotMessage('purchase.catalogue.message.bf8ff96ef3'));
        exit();
    }
    elseif(substr($text, 0, 1) == '0'){
        sendMessage(\achbotMessage('purchase.catalogue.message.b989e71b4f'));
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
        if($botState['agencyPlanDiscount']=="on") $discount = $discounts['plans'][$id]?? $discounts['normal'];
        else $discount = $discounts['servers'][$serverId]?? $discounts['normal'];
        $price -= floor($price * $discount / 100);
	}
    
	sendMessage(str_replace("DAY-PRICE", $price, $mainValues['customer_custome_plan_day']));
	setUser("selectCustomPlanDay" . $id . "_" . $match['categoryId'] . "_" . $text . "_" . $match['buyType']);
}
if((preg_match('/selectCustomPlanDay(?<planId>\d+)_(?<categoryId>\d+)_(?<accountCount>\d+)_(?<buyType>\w+)/',$userInfo['step'], $match)) && ($botState['sellState']=="on" ||$from_id == $admin) && $text != $buttonValues['cancel']){
    if(!is_numeric($text)){
        sendMessage(\achbotMessage('purchase.catalogue.message.ef774be0a5'));
        exit();
    }
    elseif($text <1){
        sendMessage(\achbotMessage('purchase.catalogue.message.05308dd049'));
        exit();
    }
    elseif(strstr($text,".")){
        sendMessage(\achbotMessage('purchase.catalogue.message.3fbe481726'));
        exit();
    }
    elseif(substr($text, 0, 1) == '0'){
        sendMessage(\achbotMessage('purchase.catalogue.message.b989e71b4f'));
        exit();
    }

	sendMessage($mainValues['customer_custome_plan_name']);
	setUser("enterCustomPlanName" . $match['planId'] . "_" . $match['categoryId'] . "_" . $match['accountCount'] . "_" . $text . "_" . $match['buyType']);
}

if((preg_match('/^discountCustomPlanDay(\d+)/',$userInfo['step'], $match) || preg_match('/enterCustomPlanName(\d+)_(\d+)_(\d+)_(\d+)_(?<buyType>\w+)/',$userInfo['step'], $match)) && ($botState['sellState']=="on" ||$from_id ==$admin) && $text != $buttonValues['cancel']){ 
    $selectedCustomPlanId = (int)($match[1] ?? 0);
    if (preg_match('/^discountCustomPlanDay/', $userInfo['step'])) {
        $stmt = $connection->prepare("SELECT plan_id FROM pays WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $selectedCustomPlanId);
        $stmt->execute();
        $discountPay = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $selectedCustomPlanId = (int)($discountPay['plan_id'] ?? 0);
    }
    if (!achbotSupportsPlan($connection, $selectedCustomPlanId)) {
        alert(\achbotMessage('purchase.catalogue.alert.10fd7f4fa9'), true);
        return;
    }
    if(preg_match('/^discountCustomPlanDay/', $userInfo['step'])){ 
        $rowId = $match[1];

        // استفاده از تابع مشترک تخفیف
        $discountResult = applyDiscountToPay(
            $connection,
            $rowId,
            $text,
            $from_id,
            $username,
            $first_name,
            $admin,
            $mainValues,
            $botState
        );

        // در هر صورت (موفق یا ناموفق)، اطلاعات pays را از خروجی می‌گیریم
        if(isset($discountResult['payInfo']) && is_array($discountResult['payInfo'])) {
            $payInfo = $discountResult['payInfo'];
            $hash_id = $discountResult['hash_id'];
            $price   = $discountResult['price'];
            $id      = $payInfo['type'];
            $volume  = $payInfo['volume'];
            $days    = $payInfo['day'];
        } else {
            // اگر به هر دلیلی payInfo برنگشت، به عنوان کد نامعتبر رفتار می‌کنیم
            sendMessage($mainValues['not_valid_discount_code']);
            exit();
        }
    }else{ 
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
            sendMessage($mainValues['incorrect_config_name']);
            exit();
        }
    } 
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=? and `active`=1"); 
    $stmt->bind_param("i", $id); 
    $stmt->execute(); 
    $respd = $stmt->get_result()->fetch_assoc(); 
    $stmt->close(); 
     
    $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `id`=?"); 
    $stmt->bind_param("i", $respd['catid']); 
    $stmt->execute(); 
    $catname = $stmt->get_result()->fetch_assoc()['title']; 
    $stmt->close(); 
     
    $name = $catname." ".$respd['title']; 
    $desc = $respd['descr']; 
	$sid = $respd['server_id']; 
	$keyboard = array(); 
    $token = base64_encode("{$from_id}.{$id}"); 
 
    if(!preg_match('/^discountCustomPlanDay/', $userInfo['step'])){ 
        $discountPrice = 0; 
        $gbPrice = $botState['gbPrice']; 
        $dayPrice = $botState['dayPrice']; 
         
        if($userInfo['is_agent'] == true && $match['buyType'] == "one") { 
            $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ?"); 
            $stmt->bind_param("i", $match[1]); 
            $stmt->execute(); 
            $serverId = $stmt->get_result()->fetch_assoc()['server_id']; 
            $stmt->close(); 
             
            $discounts = json_decode($userInfo['discount_percent'],true); 
            if($botState['agencyPlanDiscount']=="on") $discount = $discounts['plans'][$id]?? $discounts['normal']; 
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
        achbotRemoveUnclaimedPendingPayments($connection,$from_id,'BUY_SUB');
         
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
    } 
     
     
    if(achbotManualEnabled('bank_transfer') && achbotPayUxAudienceAllowed($connection,'bank_transfer',(int)$from_id)) $keyboard[] = [['text' => $buttonValues['cart_to_cart'],  'callback_data' => "payCustomWithCartToCart$hash_id"]];
    if(achbotManualEnabled('gift_card') && achbotPayUxAudienceAllowed($connection,'gift_card',(int)$from_id)) $keyboard[] = [['text' => $buttonValues['gift_card'], 'callback_data' => "payCustomWithGiftCard$hash_id"]];
    if ((achbotManualCryptoEnabled((int)$from_id) && (achbotPayUxAudienceAllowed($connection,'manual_usdt',(int)$from_id) || achbotPayUxAudienceAllowed($connection,'manual_trx',(int)$from_id)))) $keyboard[] = [['text' => \achbotMessage('purchase.catalogue.button.e729457ba2'), 'callback_data' => "mcm_$hash_id"]];
    if(($botState['nowPaymentOther'] == "on" && achbotPayUxAudienceAllowed($connection,'nowpayments',(int)$from_id) && achbotUsdtRateForUser($connection,(int)$from_id)!==null)) $keyboard[] = [['text' => $buttonValues['now_payment_gateway'],  'url' => $botUrl . "pay/checkout.php?nowpayment&hash_id=" . $hash_id]];
    if(($botState['zarinpal'] == "on" && achbotPayUxAudienceAllowed($connection,'zarinpal',(int)$from_id))) $keyboard[] = [['text' => $buttonValues['zarinpal_gateway'],  'url' => $botUrl . "pay/checkout.php?zarinpal&hash_id=" . $hash_id]];
    if(($botState['nextpay'] == "on" && achbotPayUxAudienceAllowed($connection,'nextpay',(int)$from_id))) $keyboard[] = [['text' => $buttonValues['nextpay_gateway'],  'url' => $botUrl . "pay/checkout.php?nextpay&hash_id=" . $hash_id]];
    if(($botState['walletState'] == "on" && achbotPayUxAudienceAllowed($connection,'wallet',(int)$from_id))) $keyboard[] = [['text' => $buttonValues['pay_with_wallet'],  'callback_data' => "payCustomWithWallet$hash_id"]];
 
    if(!preg_match('/^discountCustomPlanDay/', $userInfo['step'])) $keyboard[] = [['text' => \achbotMessage('purchase.catalogue.button.05c3a1e3f4'),  'callback_data' => "haveDiscountCustom_" . $rowId]];
	$keyboard[] = [['text' => $buttonValues['cancel'], 'callback_data' => "mainMenu"]]; 
    $price = ($price == 0) ? \achbotMessage('purchase.catalogue.text.dda7ff2974') : number_format($price).\achbotMessage('purchase.catalogue.text.bfb7221456');
    $sent=sendMessage(str_replace(['VOLUME', 'DAYS', 'PLAN-NAME', 'PRICE', 'DESCRIPTION'], [$volume, $days, $name, $price, $desc], $mainValues['buy_subscription_detail']),json_encode(['inline_keyboard'=>$keyboard]), "HTML");
    require_once __DIR__ . '/../Finance/PaymentLifecycle.php';
    achbotPayUxTrackByHash($connection,(string)$hash_id,(int)$from_id,$sent);
    setUser(); 
}

