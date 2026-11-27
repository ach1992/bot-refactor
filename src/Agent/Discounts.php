<?php
/**
 * AchBot Access: Agent listing, details, plan/server/default discount management.
 * Scoped, re-entrant, byte-compatible route at original bot.php position.
 * Internal include only. Do not execute via HTTP directly.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG

require_once __DIR__.'/State.php';
if (!achbotAdminRequestAllowed(is_string($data??null)?$data:null,is_string($text??null)?$text:null,(string)($userInfo['step']??''))) {achbotAdminDeny();return;}


// Match the same fresh administrator capability that makes these controls visible.
$agentDiscountAccess = function_exists('achbotAdminCan')
    ? achbotAdminCan('agents', (int)$from_id)
    : ($from_id == $admin || ($userInfo['isAdmin'] ?? false) == true);
if (is_string($data ?? null) && preg_match('/\AuserInfoDetails([1-9][0-9]*)\z/D', $data, $userInfoMatch) &&
    (function_exists('achbotAdminCan') ? achbotAdminCan('customers', (int)$from_id) : $agentDiscountAccess)) {
    $target = (int)$userInfoMatch[1];
    $details = getUserInfoKeys($target);
    if ($details !== null) {
        // A stale Back button may read the profile, but must not clear another wizard.
        if (preg_match('/^editAgentDiscountNormal'.(int)$target.'_userinfo_[1-9][0-9]*$/D',(string)($userInfo['step']??'')) &&
            \AchBot\Telegram\Conversation\source($update??null,(int)$from_id,true))
            \AchBot\Telegram\Conversation\transitionEditor($connection,(int)$from_id,$userInfo);
        editText($message_id, \achbotMessage('bot.bot.user_reports.message.e55e5591d2', ['text'=>$target]),
            json_encode(['inline_keyboard'=>$details['inline_keyboard']], JSON_UNESCAPED_UNICODE));
    }
    return;
}


if(($data == "agentsList" || preg_match('/^nextAgentList(\d+)/',$data,$match)) && $agentDiscountAccess){
    $keys = getAgentsList($match[1]??0);
    if($keys != null) editText($message_id,\achbotMessage('legacy_messages.agents_list'), $keys);
    else alert(\achbotMessage('agent.discounts.agents_list.alert.6a01bdb6c4'));
}
if(preg_match('/^agentDetails(\d+)/',$data,$match) && $agentDiscountAccess){
    $userDetail = bot('getChat',['chat_id'=>$match[1]])->result;
    $userUserName = $userDetail->username;
    $fullName = $userDetail->first_name . " " . $userDetail->last_name;

    editText($message_id,str_replace("AGENT-NAME", $fullName, \achbotMessage('legacy_messages.agent_details')), getAgentDetails($match[1]));
}
if(preg_match('/^removeAgent([1-9][0-9]*)$/D',(string)$data,$match) && $agentDiscountAccess &&
    \AchBot\Telegram\Conversation\source($update??null,(int)$from_id,true)){
    $uid=achbotAgencyPositive($match[1]);
    if (!$uid || !achbotAgencyReset($connection,(int)$from_id,$uid,1)) {
        alert(achbotMessage('legacy_messages.config_not_found'));return;
    }
    alert(\achbotMessage('legacy_messages.agent_deleted_successfuly'));
    $keys = getAgentsList();
    if($keys != null) editKeys($keys);
    else editKeys(json_encode(['inline_keyboard'=>[[['text'=>\achbotMessage('legacy_buttons.back_button'),'callback_data'=>"managePanel"]]]]));
}
if(preg_match('/^agentPercentDetails(\d+)/',$data,$match) && $agentDiscountAccess){
    $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
    $stmt->bind_param('i',$match[1]);
    $stmt->execute();
    $info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $userName = $info['name'];
    editText($message_id, str_replace("AGENT-NAME", $userName, \achbotMessage('legacy_messages.agent_discount_settings')), getAgentDiscounts($match[1]));
}
if(preg_match('/^addDiscount(Server|Plan)Agent(\d+)/',$data,$match) && $agentDiscountAccess){
    $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
    $stmt->bind_param('i',$match[2]);
    $stmt->execute();
    $info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $userName = $info['name'];
    
    if($match[1] == "Plan"){
        $offset = 0;
        $limit = 20;
        
        $condition = array_values(array_keys(json_decode($info['discount_percent'],true)['plans']??array()));
        $condition = count($condition) > 0? "WHERE `id` NOT IN (" . implode(",", $condition) . ")":"";
        $stmt = $connection->prepare("SELECT * FROM `server_plans` $condition LIMIT ? OFFSET ?");
        $stmt->bind_param("ii", $limit, $offset);
        $stmt->execute();
        $list = $stmt->get_result();
        $stmt->close();
        
        if($list->num_rows > 0){
            $keys = array();
            while($row = $list->fetch_assoc()){
                $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `id` = ?");
                $stmt->bind_param("i", $row['catid']);
                $stmt->execute();
                $catInfo = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                
                $keys[] = [['text'=>$row['title'] . " " . $catInfo['title'],'callback_data'=>"editAgentDiscountPlan" . $match[2] . "_" . $row['id']]];
            }
            
            if($list->num_rows >= $limit){
                $keys[] = [['text'=>\achbotMessage('agent.discounts.agents_list.button.8f9628264c'),'callback_data'=>"nextAgentDiscountPlan" . $match[2] . "_" . ($offset + $limit)]];
            }
            $keys[] = [['text' => \achbotMessage('legacy_buttons.back_button'), 'callback_data' => "agentPercentDetails" . $match[2]]];
            $keys = json_encode(['inline_keyboard'=>$keys]);
            
            editText($message_id,\achbotMessage('agent.discounts.agents_list.message.3c0d01e71b', ['userName' => $userName]),$keys);
        }else alert(\achbotMessage('agent.discounts.agents_list.alert.75b2cb38d6'));
    }else{
        $condition = array_values(array_keys(json_decode($info['discount_percent'],true)['servers']??array()));
        $condition = count($condition) > 0? "WHERE `id` NOT IN (" . implode(",", $condition) . ")":"";
        $stmt = $connection->prepare("SELECT * FROM `server_info` $condition");
        $stmt->execute();
        $list = $stmt->get_result();
        $stmt->close();
        
        if($list->num_rows > 0){
            $keys = array();
            while($row = $list->fetch_assoc()){
                $keys[] = [['text'=>$row['title'],'callback_data'=>"editAgentDiscountServer" . $match[2] . "_" . $row['id']]];
            }
            
            $keys[] = [['text' => \achbotMessage('legacy_buttons.back_button'), 'callback_data' => "agentPercentDetails" . $match[2]]];
            $keys = json_encode(['inline_keyboard'=>$keys]);
            
            editText($message_id,\achbotMessage('agent.discounts.agents_list.message.3c0d01e71b', ['userName' => $userName]),$keys);
        }else alert(\achbotMessage('agent.discounts.agents_list.alert.75b2cb38d6'));
    }
}
if(preg_match('/^nextAgentDiscountPlan(?<agentId>\d+)_(?<offset>\d+)/',$data,$match) &&$agentDiscountAccess){
    $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
    $stmt->bind_param('i',$match['agentId']);
    $stmt->execute();
    $info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $userName = $info['name'];
    
    $offset = $match['offset'];
    $limit = 20;
    
    $condition = array_values(array_keys(json_decode($info['discount_percent'],true)['plans']??array()));
    $condition = count($condition) > 0? "WHERE `id` NOT IN (" . implode(",", $condition) . ")":"";
    $stmt = $connection->prepare("SELECT * FROM `server_plans` $condition LIMIT ? OFFSET ?");
    $stmt->bind_param("ii", $limit, $offset);
    $stmt->execute();
    $list = $stmt->get_result();
    $stmt->close();
    
    if($list->num_rows > 0){
        $keys = array();
        while($row = $list->fetch_assoc()){
            $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `id` = ?");
            $stmt->bind_param("i", $row['catid']);
            $stmt->execute();
            $catInfo = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            $keys[] = [['text'=>$row['title'] . " " . $catInfo['title'],'callback_data'=>"editAgentDiscountPlan" . $match['agentId'] . "_" . $row['id']]];
        }
        
        if($list->num_rows >= $limit && $offset == 0){
            $keys[] = [['text'=>\achbotMessage('agent.discounts.agents_list.button.8f9628264c'),'callback_data'=>"nextAgentDiscountPlan" . $match['agentId'] . "_" . ($offset + $limit)]];
        }
        elseif($list->num_rows >= $limit && $offset != 0){
            $keys[] = [
                ['text'=>\achbotMessage('agent.discounts.agents_list.button.a38a46edb9'),'callback_data'=>"nextAgentDiscountPlan" . $match['agentId'] . "_" . ($offset - $limit)],
                ['text'=>\achbotMessage('agent.discounts.agents_list.button.8f9628264c'),'callback_data'=>"nextAgentDiscountPlan" . $match['agentId'] . "_" . ($offset + $limit)]
                ];
        }
        elseif($offset != 0){
            $keys[] = [
                ['text'=>\achbotMessage('agent.discounts.agents_list.button.a38a46edb9'),'callback_data'=>"nextAgentDiscountPlan" . $match['agentId'] . "_" . ($offset - $limit)]
                ];
        }
        $keys[] = [['text' => \achbotMessage('legacy_buttons.back_button'), 'callback_data' => "agentPercentDetails" . $match['agentId']]];
        $keys = json_encode(['inline_keyboard'=>$keys]);
        
        editText($message_id,\achbotMessage('agent.discounts.agents_list.message.3c0d01e71b', ['userName' => $userName]),$keys);
    }else alert(\achbotMessage('agent.discounts.agents_list.alert.75b2cb38d6'));
}
if(preg_match('/^removePercentOfAgent(?<type>Server|Plan)(?<agentId>[1-9][0-9]*)_(?<serverId>[1-9][0-9]*)$/D',(string)$data,$match) && $agentDiscountAccess &&
    \AchBot\Telegram\Conversation\source($update??null,(int)$from_id,true)){
    $uid=achbotAgencyPositive($match['agentId']);
    $info=$uid?achbotAgencyDiscountWrite($connection,(int)$from_id,$uid,$match['type']==='Server'?'servers':'plans',$match['serverId'],null):null;
    if (!$info) {alert(achbotMessage('legacy_messages.config_not_found'));return;}
    $userName=htmlspecialchars((string)$info['name'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    alert(\achbotMessage('labels.deleted_successfully'));
    editText($message_id, str_replace("AGENT-NAME", $userName, \achbotMessage('legacy_messages.agent_discount_settings')), getAgentDiscounts($match['agentId']));
}
if(preg_match('/^editAgentDiscount(Server|Plan|Normal)([1-9][0-9]*)_(0|userinfo|[1-9][0-9]*)$/D',(string)$data,$match) && $agentDiscountAccess){
    $source=\AchBot\Telegram\Conversation\source($update??null,(int)$from_id,true);
    $uid=achbotAgencyPositive($match[2]);
    if (!$source || !$uid || ($match[1]==='Normal'?!in_array($match[3],['0','userinfo'],true):achbotAgencyPositive($match[3])===null)) return;
    if (!\AchBot\Telegram\Conversation\transitionEditor($connection,(int)$from_id,$userInfo,$data.'_'.$source->message_id,update:$update)) return;
    try {delMessage();}catch(Throwable $ignored){}
    $fromUserInfo = $match[1] === 'Normal' && $match[3] === 'userinfo';
    $editorKeys = $fromUserInfo ? json_encode(['inline_keyboard'=>[[
        ['text'=>\achbotMessage('legacy_buttons.back_button'), 'callback_data'=>'userInfoDetails'.$match[2]]
    ]]], JSON_UNESCAPED_UNICODE) : $cancelKey;
    sendMessage($fromUserInfo ? \achbotMessage('admin_settings.user_info.agent_percent_prompt')
        : \achbotMessage('legacy_messages.send_agent_discount_percent'), $editorKeys, 'html');
    return;
}
if(preg_match('/^editAgentDiscount(Server|Plan|Normal)([1-9][0-9]*)_(0|userinfo|[1-9][0-9]*)_([1-9][0-9]*)$/D',(string)$userInfo['step'],$match) && $agentDiscountAccess && !isset($update->callback_query) && $text != \achbotMessage('legacy_buttons.cancel')){
    $source=\AchBot\Telegram\Conversation\source($update??null,(int)$from_id,false);
    if (!$source || $source->message_id<=(int)$match[4]) return;
    if(is_string($source->text??null) && preg_match('/\A[0-9]{1,3}\z/D',$source->text) && (int)$source->text<=100){
        $uid=achbotAgencyPositive($match[2]);
        $scope=match($match[1]) {'Server'=>'servers','Plan'=>'plans','Normal'=>'normal'};
        $info=$uid?achbotAgencyDiscountWrite($connection,(int)$from_id,$uid,$scope,$scope==='normal'?'':$match[3],(int)$source->text,$userInfo):null;
        if (!$info) {
            sendMessage(\achbotMessage('bot.bot.user_reports.message.c1d7ab477b'), null, 'HTML');
            return;
        }
        $userName=$info['name'];
        sendMessage(\achbotMessage('legacy_messages.saved_successfuly'),$removeKeyboard, 'HTML');
        if ($match[1] === 'Normal' && $match[3] === 'userinfo') {
            $details = getUserInfoKeys($match[2]);
            if ($details !== null) sendMessage(
                \achbotMessage('bot.bot.user_reports.message.e55e5591d2', ['text'=>$match[2]]),
                json_encode(['inline_keyboard'=>$details['inline_keyboard']], JSON_UNESCAPED_UNICODE), 'html');
        } else {
            sendMessage(str_replace("AGENT-NAME", htmlspecialchars((string)$userName,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'), \achbotMessage('legacy_messages.agent_discount_settings')), getAgentDiscounts($match[2]),'HTML');
        }
    } else sendMessage(\achbotMessage('admin_settings.user_info.agent_percent_invalid'), null, 'HTML');
}
