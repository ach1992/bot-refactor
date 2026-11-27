<?php
/** AChBot V7: immutable V6.1 purchase or plan route. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG


require_once __DIR__ . '/../Finance/PaymentLifecycle.php';
require_once __DIR__.'/../Telegram/Conversation.php';

if(preg_match('/\AselectServer(?<serverId>[1-9][0-9]*)_(?<buyType>none|one|much)\z/D',$data, $match) && achbotCustomerSaleAllowed($botState,(int)$from_id,(int)$admin) ) {
    if (!achbotPurchaseModeAllowed($userInfo,$botState,$match['buyType'])) return;
    $sid = $match['serverId'];
        
    $categories=[];
    foreach (achbotCustomerPurchasePlans($connection,$userInfo,$botState,$match['buyType'],(int)$from_id,(int)$admin,(int)$sid) as $plan)
        $categories[(int)$plan['catid']]=$plan['category_title'];
    if (!$categories) {
        alert(\achbotMessage('legacy_messages.category_not_avilable'));
    } else {
        ksort($categories);
        $keyboard=[];
        foreach ($categories as $id=>$name)
            $keyboard[]=['text'=>"$name",'callback_data'=>"selectCategory{$id}_{$sid}_{$match['buyType']}"];
        alert(\achbotMessage('legacy_messages.receive_categories'));

        $keyboard[] = ['text' => \achbotMessage('legacy_buttons.back_to_main'), 'callback_data' =>
        ($match['buyType'] == "one"?"agentOneBuy":($match['buyType'] == "much"?"agentMuchBuy":"buySubscription"))];
        $keyboard = array_chunk($keyboard,1);
        editText($message_id,\achbotMessage('legacy_messages.buy_sub_select_category'), json_encode(['inline_keyboard'=>$keyboard]));
    }

}
if(preg_match('/\AselectCategory(?<categoryId>[1-9][0-9]*)_(?<serverId>[1-9][0-9]*)_(?<buyType>none|one|much)\z/D',$data,$match) && achbotCustomerSaleAllowed($botState,(int)$from_id,(int)$admin)) {
    if (!achbotPurchaseModeAllowed($userInfo,$botState,$match['buyType'])) return;
    $call_id = $match['categoryId'];
    $sid = $match['serverId'];
    $respd=achbotCustomerPurchasePlans($connection,$userInfo,$botState,$match['buyType'],(int)$from_id,(int)$admin,(int)$sid,(int)$call_id);
    $custom=$respd!==[] && achbotCustomPurchaseEnabled($botState,$match['buyType']);
    if(!$respd && !$custom){
        alert(\achbotMessage('legacy_messages.no_plan_available'));
    }else{
        alert(\achbotMessage('legacy_messages.receive_plans'));
        $keyboard = [];
        foreach($respd as $file){
            $id = $file['id'];
            $name = $file['title'];
            [$price]=achbotAgencyPrice($userInfo,$botState,(int)$id,(int)$sid,(int)$file['price']);
            if ((int)$from_id===(int)$admin) $price=0;
            $price = ($price == 0) ? \achbotMessage('labels.free_price') : number_format($price).\achbotMessage('purchase.catalogue.text.bfb7221456');
            $keyboard[] = ['text' => "$name - $price", 'callback_data' => "selectPlan{$id}_{$call_id}_{$match['buyType']}"];
        }
        if($custom){
	        $keyboard[] = ['text' => \achbotMessage('legacy_messages.buy_custom_plan'), 'callback_data' => "selectCustomPlan{$call_id}_{$sid}_{$match['buyType']}"];
        }
        $keyboard[] = ['text' => \achbotMessage('legacy_buttons.back_to_main'), 'callback_data' => "selectServer{$sid}_{$match['buyType']}"];
        $keyboard = array_chunk($keyboard,1);
        $keys=json_encode(['inline_keyboard'=>$keyboard]);
        if (!achbotPayUxNavigateBack($connection,(int)$from_id,(int)$message_id,
            \achbotMessage('legacy_messages.buy_sub_select_plan'),$keys))
            editText($message_id,\achbotMessage('legacy_messages.buy_sub_select_plan'),$keys);
    }

}
if(preg_match('/\AselectCustomPlan(?<categoryId>[1-9][0-9]*)_(?<serverId>[1-9][0-9]*)_(?<buyType>none|one)\z/D',$data,$match) && achbotCustomerSaleAllowed($botState,(int)$from_id,(int)$admin)) {
    if (!achbotPurchaseModeAllowed($userInfo,$botState,$match['buyType'])) return;
    $call_id = $match['categoryId'];
    $sid = $match['serverId'];
    $respd=achbotCustomerCustomPlans($connection,$userInfo,$botState,$match['buyType'],(int)$from_id,(int)$admin,(int)$sid,(int)$call_id);
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
if(preg_match('/\AselectCustomePlan(?<planId>[1-9][0-9]*)_(?<categoryId>[1-9][0-9]*)_(?<buyType>none|one)\z/D',$data, $match) && achbotCustomerSaleAllowed($botState,(int)$from_id,(int)$admin)){
    $plan=null;
    foreach (achbotCustomerCustomPlans($connection,$userInfo,$botState,$match['buyType'],(int)$from_id,(int)$admin,null,(int)$match['categoryId']) as $candidate)
        if ((int)$candidate['id']===(int)$match['planId']) {$plan=$candidate;break;}
    if (!$plan) {
        alert(achbotMessage('purchase.catalogue.alert.67fda139df'), true);
        return;
    }
    if (!\AchBot\Telegram\Conversation\source($update,(int)$from_id,true) ||
        !\AchBot\Telegram\Conversation\transitionEditor($connection,(int)$from_id,$userInfo,
            "selectCustomPlanGB".$match[1]."_".$match[2]."_".$match['buyType'],update:$update)) return;
    [$price]=achbotAgencyPrice($userInfo,$botState,(int)$plan['id'],(int)$plan['server_id'],(int)$botState['gbPrice']);
    $prompt=sendMessage(str_replace("VOLUME-PRICE", $price, \achbotMessage('legacy_messages.customer_custome_plan_volume')),$cancelKey, 'HTML');
    if (\AchBot\Telegram\Conversation\acknowledgedMessage($prompt,(int)$from_id)!==null)
        try {delMessage();}catch(Throwable $ignored) {}
    return;
}
if(isset($update->message->text) && \AchBot\Telegram\Conversation\source($update,(int)$from_id,false)!==null && preg_match('/\AselectCustomPlanGB(?<planId>[1-9][0-9]*)_(?<categoryId>[1-9][0-9]*)_(?<buyType>none|one)\z/D',$userInfo['step'], $match) && achbotCustomerSaleAllowed($botState,(int)$from_id,(int)$admin) && $text != \achbotMessage('legacy_buttons.cancel')){
    $plan=null;
    foreach (achbotCustomerCustomPlans($connection,$userInfo,$botState,$match['buyType'],(int)$from_id,(int)$admin,null,(int)$match['categoryId']) as $candidate)
        if ((int)$candidate['id']===(int)$match['planId']) {$plan=$candidate;break;}
    if (!$plan) return;
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
    $units=achbotWholeServiceUnits($text,achbotServiceVolumeMax());
    if ($units===null || !preg_match('/^[1-9][0-9]*$/D',(string)$text)) return;

    
    $id=(int)$plan['id'];
    [$price]=achbotAgencyPrice($userInfo,$botState,$id,(int)$plan['server_id'],(int)$botState['dayPrice']);
    if (!\AchBot\Telegram\Conversation\transitionEditor($connection,(int)$from_id,$userInfo,
        "selectCustomPlanDay".$id."_".$match['categoryId']."_".$units."_".$match['buyType'])) return;
    sendMessage(str_replace("DAY-PRICE", $price, \achbotMessage('legacy_messages.customer_custome_plan_day')), $cancelKey, 'HTML');
    return;
}
if(isset($update->message->text) && \AchBot\Telegram\Conversation\source($update,(int)$from_id,false)!==null && preg_match('/\AselectCustomPlanDay(?<planId>[1-9][0-9]*)_(?<categoryId>[1-9][0-9]*)_(?<accountCount>[1-9][0-9]*)_(?<buyType>none|one)\z/D',$userInfo['step'], $match) && achbotCustomerSaleAllowed($botState,(int)$from_id,(int)$admin) && $text != \achbotMessage('legacy_buttons.cancel')){
    $plan=null;
    foreach (achbotCustomerCustomPlans($connection,$userInfo,$botState,$match['buyType'],(int)$from_id,(int)$admin,null,(int)$match['categoryId']) as $candidate)
        if ((int)$candidate['id']===(int)$match['planId']) {$plan=$candidate;break;}
    if (!$plan) return;
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
    $units=achbotWholeServiceUnits($text,achbotServiceDaysMax());
    if ($units===null || !preg_match('/^[1-9][0-9]*$/D',(string)$text)) return;


    if (!\AchBot\Telegram\Conversation\transitionEditor($connection,(int)$from_id,$userInfo,
        "enterCustomPlanName".$match['planId']."_".$match['categoryId']."_".$match['accountCount']."_".$units."_".$match['buyType'])) return;
    sendMessage(\achbotMessage('legacy_messages.customer_custome_plan_name'), $cancelKey, 'HTML');
    return;
}

if(isset($update->message->text) && \AchBot\Telegram\Conversation\source($update,(int)$from_id,false)!==null && preg_match('/\AenterCustomPlanName([1-9][0-9]*)_([1-9][0-9]*)_([1-9][0-9]*)_([1-9][0-9]*)_(?<buyType>none|one)\z/D',$userInfo['step'],$match) &&
    achbotCustomerSaleAllowed($botState,(int)$from_id,(int)$admin) && $text!=achbotMessage('legacy_buttons.cancel')){
    if (!achbotSupportsPlan($connection,(int)$match[1])) {
        alert(achbotMessage('purchase.catalogue.alert.10fd7f4fa9'),true);return;
    }
        $id = $match[1]; 
    	$call_id = $match[2]; 
    	$volume = $match[3]; 
        $days = $match[4]; 
        // Single custom names become durable remote usernames; the
        // final admission independently checks persisted data.
        require_once __DIR__.'/../Finance/ServiceEffects.php';
        if (!achbotEffectValidUsername((string)$text) ||
            preg_match('/^[a-z]+[0-9]+$/D',(string)$text)!==1) {
            sendMessage(\achbotMessage('legacy_messages.incorrect_config_name'), null, 'HTML');
            exit();
        }
    $respd=null;
    foreach (achbotCustomerCustomPlans($connection,$userInfo,$botState,$match['buyType'],(int)$from_id,(int)$admin,null,(int)$call_id) as $candidate)
        if ((int)$candidate['id']===(int)$id) {$respd=$candidate;break;}
    if (!$respd) {
        alert(achbotMessage('purchase.catalogue.alert.10fd7f4fa9'),true);return;
    }
    $sid=$respd['server_id'];
    $volume=achbotWholeServiceUnits($volume,achbotServiceVolumeMax());
    $days=achbotWholeServiceUnits($days,achbotServiceDaysMax());
    [$gbPrice]=achbotAgencyPrice($userInfo,$botState,(int)$id,(int)$sid,(int)$botState['gbPrice']);
    [$dayPrice]=achbotAgencyPrice($userInfo,$botState,(int)$id,(int)$sid,(int)$botState['dayPrice']);
    $agentBought=$match['buyType']!=='none';
    $gbPrice=(int)$gbPrice;$dayPrice=(int)$dayPrice;
    if ($volume===null || $days===null || $gbPrice<0 || $dayPrice<0 ||
        $gbPrice>intdiv(2147483647,$volume) ||
        $dayPrice>intdiv(2147483647-$volume*$gbPrice,$days)) {
        alert(achbotMessage('purchase.catalogue.alert.201380d8bf'),true);return;
    }
    $price=$volume*$gbPrice+$days*$dayPrice;
        $hash_id = RandomString(); 

         
        $time = time(); 
        $stmt = $connection->prepare("INSERT INTO `pays` (`hash_id`, `description`, `user_id`, `type`, `plan_id`, `server_id`, `volume`, `day`, `price`, `request_date`, `state`, `agent_bought`) 
									VALUES (?, ?, ?, 'BUY_SUB', ?, ?, ?, ?, ?, ?, 'pending', ?)"); 
		$stmt->bind_param("ssiiiiiiii", $hash_id, $text, $from_id, $id, $sid, $volume, $days, $price, $time, $agentBought); 
        $rowId=achbotPayUxInsertDraft($connection,(int)$from_id,$stmt,achbotAgencyQuotePolicy($userInfo,$botState,(int)$id,(int)$sid),false,[],$botState,$userInfo);
        $stmt->close();
        if ($rowId<=0) {
            alert(\achbotMessage('purchase.catalogue.alert.201380d8bf'),true);
            return;
        }
    require_once __DIR__.'/CheckoutPresentation.php';
    \AchBot\Telegram\Conversation\removeReplyKeyboard(achbotMessage('legacy_messages.saved_successfuly'));
    if (!achbotRenderNewCheckoutDraft($connection,$rowId,(int)$from_id,$botState,$botUrl))
        sendMessage(achbotMessage('purchase.catalogue.alert.201380d8bf'),null,'HTML');
    return;
}
