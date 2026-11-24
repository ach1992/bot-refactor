<?php
/** AChBot V7: immutable V6.1 purchase or plan route. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG


require_once __DIR__ . '/../Finance/PaymentLifecycle.php';

$typedPlanInput=isset($update->message->text) && !isset($update->callback_query);
$planSelectionCallback=preg_match('/\AselectPlan([1-9][0-9]*)_([1-9][0-9]*)_(?<buyType>none|one|much)\z/D',(string)$data,$match)===1;
if(($planSelectionCallback ||
    ($typedPlanInput && preg_match('/\A(?:selectPlan|enterAccountName)([1-9][0-9]*)_([1-9][0-9]*)_(?<buyType>none|one|much)\z/D',$userInfo['step'],$match))) &&
    (($botState['sellState']??'off')==="on" || $from_id==$admin) &&
    $text != \achbotMessage('legacy_buttons.cancel')){
    if (!in_array($match['buyType'],['none','one','much'],true) ||
        ($match['buyType']!=='none' && (int)$userInfo['is_agent']!==1)) return;
    if (!achbotSupportsPlan($connection, (int)($match[1] ?? 0))) {
        alert(\achbotMessage('purchase.entry_and_bulk.create_multiple_accounts.alert.d3fb64c4d9'), true);
        return;
    }
    if($planSelectionCallback) delMessage();

    if(($botState['remark']??'') ==  "manual" && preg_match('/^selectPlan/',$data) && $match['buyType'] != "much"){
        sendMessage(\achbotMessage('legacy_messages.customer_custome_plan_name'), $cancelKey, 'HTML');
        setUser('enterAccountName' . $match[1] . "_" . $match[2] . "_" . $match['buyType']); 
        exit(); 
    } 
 
    $remark = ""; 
    if(!$planSelectionCallback && str_starts_with($userInfo['step'],'selectPlan')){
        if($match['buyType'] == "much"){ 
            // An account quantity is a positive integer, not an exponent,
            // decimal or a value which PHP will silently cast during INSERT.
            if (!is_string($text) || preg_match('/^0*[1-9][0-9]*$/D',$text)!==1 ||
                filter_var(ltrim($text,'0'),FILTER_VALIDATE_INT)===false) {
                sendMessage(\achbotMessage('legacy_messages.send_only_number'), null, 'HTML'); exit();
            }
            $accountCount=(int)$text;
            setUser();
        }         
    } 
    elseif(!$planSelectionCallback && str_starts_with($userInfo['step'],'enterAccountName')){
        require_once __DIR__.'/../Panel/AccountNaming.php';
        if(is_string($text) && achbotEffectValidUsername($text,'pasarguard') && preg_match('/^[a-z]+[0-9]+$/D',$text)){
            $remark = $text; 
            setUser(); 
        } else{ 
            sendMessage(\achbotMessage('legacy_messages.incorrect_config_name'), null, 'HTML');
            exit(); 
        } 
    } 
    else{ 
        if($match['buyType'] == "much"){ 
            setUser($data); 
            sendMessage(\achbotMessage('legacy_messages.enter_account_amount'), $cancelKey, 'HTML');
            exit(); 
        } 
    } 
     
     
    $id = $match[1]; 
	$call_id = $match[2]; 
    alert(\achbotMessage('legacy_messages.receving_information'));
    $respd=null;
    foreach (achbotSaleablePlans($connection,null,(int)$call_id) as $candidate)
        if ((int)$candidate['id']===(int)$id) {$respd=$candidate;break;}
    if (!$respd) {
        alert(\achbotMessage('purchase.select_plan.alert.789af84a82'),true);return;
    }
    $cat=achbotProviderRow($connection,'SELECT title FROM server_categories WHERE id=? AND active=1','i',[(int)$respd['catid']]);
    if (!$cat) return;
    $catname=$cat['title'];
    $name = $catname." ".$respd['title']; 
    $desc = $respd['descr']; 
	$sid = $respd['server_id']; 
	$keyboard = array(); 
    $price=(int)$respd['price'];
    if (isset($accountCount)) {
        if ($price<0 || $price>0 && $accountCount>intdiv(PHP_INT_MAX,$price)) {
            alert(\achbotMessage('legacy_messages.send_only_number')); return;
        }
        $price*=$accountCount;
    }
     
    $agentBought = false; 
    if($userInfo['is_agent'] == true && ($match['buyType'] == "one" || $match['buyType'] == "much")){ 
        $discounts = json_decode($userInfo['discount_percent'],true); 
        if(($botState['agencyPlanDiscount']??'off')=="on") $discount = $discounts['plans'][$id]?? $discounts['normal'];
        else $discount = $discounts['servers'][$sid]?? $discounts['normal']; 
        $price -= floor($price * $discount / 100); 
 
        $agentBought = true; 
    } 
    if($price == 0 or ($from_id == $admin)){ 
        $keyboard[] = [['text' => \achbotMessage('purchase.select_plan.button.8719396e1e'), 'callback_data' => "freeTrial{$id}_{$match['buyType']}"]];
        setUser($remark, 'temp'); 
    }else{ 
        $hash_id=RandomString();$time=time();
        if (isset($accountCount)) {
            $stmt=$connection->prepare("INSERT INTO pays(hash_id,user_id,type,plan_id,server_id,volume,day,price,request_date,state,agent_bought,agent_count)
                VALUES (?,?,'BUY_SUB',?,?,'0','0',?,?,'pending',?,?)");
            $stmt->bind_param("siiiiiii",$hash_id,$from_id,$id,$sid,$price,$time,$agentBought,$accountCount);
        } else {
            $stmt=$connection->prepare("INSERT INTO pays(hash_id,description,user_id,type,plan_id,server_id,volume,day,price,request_date,state,agent_bought)
                VALUES (?,?,?,'BUY_SUB',?,?,'0','0',?,?,'pending',?)");
            $stmt->bind_param("ssiiiiii",$hash_id,$remark,$from_id,$id,$sid,$price,$time,$agentBought);
        }
        $rowId=achbotPayUxInsertDraft($connection,(int)$from_id,$stmt,false,$respd,$botState);
        $stmt->close();
        if ($rowId<=0) {
            alert(achbotMessage('purchase.catalogue.alert.201380d8bf'),true);return;
        }
        require_once __DIR__.'/CheckoutPresentation.php';
        if (!achbotRenderNewCheckoutDraft($connection,$rowId,(int)$from_id,$botState,$botUrl))
            alert(achbotMessage('purchase.catalogue.alert.201380d8bf'),true);
        return;
    } 
	$keyboard[] = [['text' => \achbotMessage('legacy_buttons.back_to_main'), 'callback_data' => "selectCategory{$call_id}_{$sid}_{$match['buyType']}"]];
    $priceC = ($price == 0) ? \achbotMessage('purchase.catalogue.text.dda7ff2974') : number_format($price).\achbotMessage('purchase.catalogue.text.bfb7221456');
    if(isset($accountCount)){ 
        $eachPrice = number_format($price / $accountCount) . \achbotMessage('purchase.select_plan.text.2cc98f7f7c');
        $msg = str_replace(['ACCOUNT-COUNT', 'TOTAL-PRICE', 'PLAN-NAME', 'PRICE', 'DESCRIPTION'], [$accountCount, $priceC, $name, $eachPrice, $desc], \achbotCurrentMessage('buy_much_subscription_detail'));
    } 
    else $msg = str_replace(['PLAN-NAME', 'PRICE', 'DESCRIPTION'], [$name, $priceC, $desc], \achbotCurrentMessage('buy_subscription_detail'));
    sendMessage($msg, json_encode(['inline_keyboard'=>$keyboard]), "HTML");
}

