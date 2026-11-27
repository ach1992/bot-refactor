<?php
/** AChBot V8: source-pinned financial compatibility module. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG


function getAgentDiscounts($agentId){
    global $connection, $botState;
    $stmt = $connection->prepare("SELECT * FROM `users` WHERE `is_agent` = 1 AND `userid` = ?");
    $stmt->bind_param("i", $agentId);
    $stmt->execute();
    $agentInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $keys = array();
    
    if (!$agentInfo) return json_encode(['inline_keyboard'=>[]]);
    $discounts = json_decode((string)$agentInfo['discount_percent'],true);
    if (!is_array($discounts)) $discounts=[];

    $normal = $discounts['normal']??0;
    $keys[] = [['text'=>" ",'callback_data'=>"wizwizch"],
    ['text'=>$normal . "%",'callback_data'=>"editAgentDiscountNormal" . $agentId . "_0"],
    ['text'=>\achbotMessage('payment.agent_discounts.get_agent_discounts.button.526e310df4'),'callback_data'=>"wizwizch"]];
    
    if($botState['agencyPlanDiscount']=="on"){
        foreach((is_array($discounts['plans']??null)?$discounts['plans']:[]) as $planId=>$discount){
            $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ?");
            $stmt->bind_param('i', $planId);
            $stmt->execute();
            $info = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$info) continue;
            
            $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `id` = ?");
            $stmt->bind_param("i", $info['catid']);
            $stmt->execute();
            $catInfo = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$info) continue;
            
            $keys[] = [['text'=>\achbotMessage('payment.agent_discounts.get_agent_discounts.button.2b2966a54e'),'callback_data'=>"removePercentOfAgentPlan" . $agentId . "_" . $planId],
            ['text'=>$discount . "%",'callback_data'=>"editAgentDiscountPlan" . $agentId . "_" . $planId],
            ['text'=>$info['title'] . " " . ($catInfo['title']??''),'callback_data'=>"wizwizch"]];
        }
    }else{
        foreach((is_array($discounts['servers']??null)?$discounts['servers']:[]) as $serverId=>$discount){
            $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id` = ?");
            $stmt->bind_param('i', $serverId);
            $stmt->execute();
            $info = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$info) continue;
            
            $keys[] = [['text'=>\achbotMessage('payment.agent_discounts.get_agent_discounts.button.2b2966a54e'),'callback_data'=>"removePercentOfAgentServer" . $agentId . "_" . $serverId],
            ['text'=>$discount . "%",'callback_data'=>"editAgentDiscountServer" . $agentId . "_" . $serverId],
            ['text'=>$info['title'],'callback_data'=>"wizwizch"]];            
        }                
    }
    if($botState['agencyPlanDiscount']=="on")$keys[] = [['text' => \achbotMessage('payment.agent_discounts.get_agent_discounts.button.d15a8dbd6d'), 'callback_data' => "addDiscountPlanAgent" . $agentId]];
    else $keys[] = [['text' => \achbotMessage('payment.agent_discounts.get_agent_discounts.button.82aaebb083'), 'callback_data' => "addDiscountServerAgent" . $agentId]];
    $keys[] = [['text' => \achbotMessage('legacy_buttons.back_button'), 'callback_data' => "agentsList"]];
    return json_encode(['inline_keyboard'=>$keys]);
}
