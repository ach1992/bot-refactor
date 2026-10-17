<?php
/** AChBot V7: immutable V6.1 purchase or plan route. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

if((preg_match('/^discountSelectPlan(\d+)_(\d+)_(\d+)/',$userInfo['step'],$match) ||  
    preg_match('/selectPlan(\d+)_(\d+)_(?<buyType>\w+)/',$userInfo['step'], $match) ||  
    preg_match('/enterAccountName(\d+)_(\d+)_(?<buyType>\w+)/',$userInfo['step'], $match) ||  
    preg_match('/selectPlan(\d+)_(\d+)_(?<buyType>\w+)/',$data, $match)) &&  
    ($botState['sellState']=="on" ||$from_id ==$admin) &&  
    $text != $buttonValues['cancel']){ 
    if (!achbotSupportsMarzbanPlan($connection, (int)($match[1] ?? 0))) {
        alert("⛔️ طرح انتخابی در حال حاضر قابل خرید نیست.", true);
        return;
    }
    if(preg_match('/^discountSelectPlan/', $userInfo['step'])){ 
        $rowId = $match[3]; 

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

        // قیمت بعد از تخفیف (یا همان قیمت قبلی در صورت نامعتبر بودن کد)
        $afterDiscount = $discountResult['price'];
		$hash_id = $discountResult['hash_id'];

        setUser(); 
    }elseif(isset($data)) delMessage(); 
 
 
    if($botState['remark'] ==  "manual" && preg_match('/^selectPlan/',$data) && $match['buyType'] != "much"){ 
        sendMessage($mainValues['customer_custome_plan_name'], $cancelKey); 
        setUser('enterAccountName' . $match[1] . "_" . $match[2] . "_" . $match['buyType']); 
        exit(); 
    } 
 
    $remark = ""; 
    if(preg_match("/selectPlan(\d+)_(\d+)_(\w+)/",$userInfo['step'])){ 
        if($match['buyType'] == "much"){ 
            if(is_numeric($text)){ 
                if($text > 0){ 
                    $accountCount = $text; 
                    setUser(); 
                }else{sendMessage( $mainValues['send_positive_number']); exit(); } 
            }else{ sendMessage($mainValues['send_only_number']); exit(); } 
        }         
    } 
    elseif(preg_match("/enterAccountName(\d+)_(\d+)/",$userInfo['step'])){ 
        if(preg_match('/^[a-z]+[0-9]+$/',$text)){ 
            $remark = $text; 
            setUser(); 
        } else{ 
            sendMessage($mainValues['incorrect_config_name']); 
            exit(); 
        } 
    } 
    else{ 
        if($match['buyType'] == "much"){ 
            setUser($data); 
            sendMessage($mainValues['enter_account_amount'], $cancelKey); 
            exit(); 
        } 
    } 
     
     
    $id = $match[1]; 
	$call_id = $match[2]; 
    alert($mainValues['receving_information']); 
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=? and `active`=1"); 
    $stmt->bind_param("i", $id); 
    $stmt->execute(); 
    $respd = $stmt->get_result()->fetch_assoc(); 
    $stmt->close(); 
     
    if (!$respd) {
        alert("⛔️ پلن موردنظر معتبر نیست.", true);
        return;
    }
    $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `id`=?"); 
    $stmt->bind_param("i", $respd['catid']); 
    $stmt->execute(); 
    $catname = $stmt->get_result()->fetch_assoc()['title']; 
    $stmt->close(); 
     
    $name = $catname." ".$respd['title']; 
    $desc = $respd['descr']; 
	$sid = $respd['server_id']; 
	$keyboard = array(); 
    $price =  $respd['price']; 
    if(isset($accountCount)) $price *= $accountCount; 
     
    $agentBought = false; 
    if($userInfo['is_agent'] == true && ($match['buyType'] == "one" || $match['buyType'] == "much")){ 
        $discounts = json_decode($userInfo['discount_percent'],true); 
        if($botState['agencyPlanDiscount']=="on") $discount = $discounts['plans'][$id]?? $discounts['normal']; 
        else $discount = $discounts['servers'][$sid]?? $discounts['normal']; 
        $price -= floor($price * $discount / 100); 
 
        $agentBought = true; 
    } 
    if($price == 0 or ($from_id == $admin)){ 
        $keyboard[] = [['text' => '📥 دریافت رایگان', 'callback_data' => "freeTrial{$id}_{$match['buyType']}"]]; 
        setUser($remark, 'temp'); 
    }else{ 
        $token = base64_encode("{$from_id}.{$id}"); 
         
        if(!preg_match('/^discountSelectPlan/', $userInfo['step'])){ 
            $hash_id = RandomString(); 
            achbotRemoveUnclaimedPendingPayments($connection,$from_id,'BUY_SUB');
             
            $time = time(); 
            if(isset($accountCount)){ 
				$stmt = $connection->prepare("INSERT INTO `pays` (`hash_id`, `user_id`, `type`, `plan_id`, `server_id`, `volume`, `day`, `price`, `request_date`, `state`, `agent_bought`, `agent_count`) 
											VALUES (?, ?, 'BUY_SUB', ?, ?, '0', '0', ?, ?, 'pending', ?, ?)"); 
				$stmt->bind_param("siiiiiii", $hash_id, $from_id, $id, $sid, $price, $time, $agentBought, $accountCount); 
            }else{ 
				$stmt = $connection->prepare("INSERT INTO `pays` (`hash_id`, `description`, `user_id`, `type`, `plan_id`, `server_id`, `volume`, `day`, `price`, `request_date`, `state`, `agent_bought`) 
											VALUES (?, ?, ?, 'BUY_SUB', ?, ?, '0', '0', ?, ?, 'pending', ?)"); 
				$stmt->bind_param("ssiiiiii", $hash_id, $remark, $from_id, $id, $sid, $price, $time, $agentBought); 
            } 
            $stmt->execute(); 
            $rowId = $stmt->insert_id; 
            $stmt->close(); 
        }else{ 
            $price = $afterDiscount; 
        } 
         
        if($botState['cartToCartState'] == "on") $keyboard[] = [['text' => $buttonValues['cart_to_cart'],  'callback_data' => "payBuyWithCartToCart$hash_id"]];
        if(($botState['giftCardState'] ?? 'off') === 'on') $keyboard[] = [['text' => $buttonValues['gift_card'], 'callback_data' => "payBuyWithGiftCard$hash_id"]];
    if (achbotManualEnabled('manual_usdt')) $keyboard[] = [['text' => $buttonValues['manual_usdt'], 'callback_data' => "payBuyWithManualUsdt$hash_id"]];
        if($botState['nowPaymentOther'] == "on") $keyboard[] = [['text' => $buttonValues['now_payment_gateway'],  'url' => $botUrl . "pay/?nowpayment&hash_id=" . $hash_id]]; 
        if($botState['zarinpal'] == "on") $keyboard[] = [['text' => $buttonValues['zarinpal_gateway'],  'url' => $botUrl . "pay/?zarinpal&hash_id=" . $hash_id]]; 
        if($botState['nextpay'] == "on") $keyboard[] = [['text' => $buttonValues['nextpay_gateway'],  'url' => $botUrl . "pay/?nextpay&hash_id=" . $hash_id]]; 
        if($botState['walletState'] == "on") $keyboard[] = [['text' => $buttonValues['pay_with_wallet'],  'callback_data' => "payBuyWithWallet$hash_id"]]; 
        if($botState['tronWallet'] == "on") $keyboard[] = [['text' => $buttonValues['tron_gateway'],  'callback_data' => "payWithTronWallet" . $hash_id]]; 
         
        if(!preg_match('/^discountSelectPlan/', $userInfo['step'])) $keyboard[] = [['text' => " 🎟 افزودن کد تخفیف ",  'callback_data' => "haveDiscountSelectPlan_" . $match[1] . "_" . $match[2] . "_" . $rowId]]; 
 
    } 
	$keyboard[] = [['text' => $buttonValues['back_to_main'], 'callback_data' => "selectCategory{$call_id}_{$sid}_{$match['buyType']}"]]; 
    $priceC = ($price == 0) ? 'رایگان' : number_format($price).' تومان '; 
    if(isset($accountCount)){ 
        $eachPrice = number_format($price / $accountCount) . " تومان"; 
        $msg = str_replace(['ACCOUNT-COUNT', 'TOTAL-PRICE', 'PLAN-NAME', 'PRICE', 'DESCRIPTION'], [$accountCount, $priceC, $name, $eachPrice, $desc], $mainValues['buy_much_subscription_detail']); 
    } 
    else $msg = str_replace(['PLAN-NAME', 'PRICE', 'DESCRIPTION'], [$name, $priceC, $desc], $mainValues['buy_subscription_detail']); 
    sendMessage($msg, json_encode(['inline_keyboard'=>$keyboard]), "HTML"); 
}

