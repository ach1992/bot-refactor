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
        if (($userInfo['step'] ?? '') === 'editAgentDiscountNormal'.$target.'_userinfo') setUser();
        editText($message_id, \achbotMessage('bot.bot.user_reports.message.e55e5591d2', ['text'=>$target]),
            json_encode(['inline_keyboard'=>$details['inline_keyboard']], JSON_UNESCAPED_UNICODE));
    }
    return;
}


if(($data == "agentsList" || preg_match('/^nextAgentList(\d+)/',$data,$match)) && $agentDiscountAccess){
    $keys = getAgentsList($match[1]??0);
    if($keys != null) editText($message_id,$mainValues['agents_list'], $keys);
    else alert(\achbotMessage('agent.discounts.agents_list.alert.6a01bdb6c4'));
}
if(preg_match('/^agentDetails(\d+)/',$data,$match) && $agentDiscountAccess){
    $userDetail = bot('getChat',['chat_id'=>$match[1]])->result;
    $userUserName = $userDetail->username;
    $fullName = $userDetail->first_name . " " . $userDetail->last_name;

    editText($message_id,str_replace("AGENT-NAME", $fullName, $mainValues['agent_details']), getAgentDetails($match[1]));
}
if(preg_match('/^removeAgent(\d+)/',$data,$match) && $agentDiscountAccess){
    $stmt = $connection->prepare("UPDATE `users` SET `is_agent` = 0 WHERE `userid` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();
    
    alert($mainValues['agent_deleted_successfuly']);
    $keys = getAgentsList();
    if($keys != null) editKeys($keys);
    else editKeys(json_encode(['inline_keyboard'=>[[['text'=>$buttonValues['back_button'],'callback_data'=>"managePanel"]]]]));
}
if(preg_match('/^agentPercentDetails(\d+)/',$data,$match) && $agentDiscountAccess){
    $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
    $stmt->bind_param('i',$match[1]);
    $stmt->execute();
    $info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $userName = $info['name'];
    editText($message_id, str_replace("AGENT-NAME", $userName, $mainValues['agent_discount_settings']), getAgentDiscounts($match[1]));
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
            $keys[] = [['text' => $buttonValues['back_button'], 'callback_data' => "agentPercentDetails" . $match[2]]];
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
            
            $keys[] = [['text' => $buttonValues['back_button'], 'callback_data' => "agentPercentDetails" . $match[2]]];
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
        $keys[] = [['text' => $buttonValues['back_button'], 'callback_data' => "agentPercentDetails" . $match['agentId']]];
        $keys = json_encode(['inline_keyboard'=>$keys]);
        
        editText($message_id,\achbotMessage('agent.discounts.agents_list.message.3c0d01e71b', ['userName' => $userName]),$keys);
    }else alert(\achbotMessage('agent.discounts.agents_list.alert.75b2cb38d6'));
}
if(preg_match('/^removePercentOfAgent(?<type>Server|Plan)(?<agentId>\d+)_(?<serverId>\d+)/',$data,$match) && $agentDiscountAccess){
    $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
    $stmt->bind_param('i',$match['agentId']);
    $stmt->execute();
    $info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $discounts = json_decode($info['discount_percent'],true);
    if($match['type'] == "Server") unset($discounts['servers'][$match['serverId']]);
    elseif($match['type'] == "Plan") unset($discounts['plans'][$match['serverId']]);
    
    $discounts = json_encode($discounts,488);
    $stmt = $connection->prepare("UPDATE `users` SET `discount_percent` = ? WHERE `userid` = ?");
    $stmt->bind_param("si", $discounts, $match['agentId']);
    $stmt->execute();
    $stmt->close();
    
    alert(\achbotMessage('agent.discounts.agents_list.alert.e1e7245408'));
    editText($message_id, str_replace("AGENT-NAME", $userName, $mainValues['agent_discount_settings']), getAgentDiscounts($match['agentId']));
}
if(preg_match('/^editAgentDiscount(Server|Plan|Normal)(\d+)_(.*)/',$data,$match) && $agentDiscountAccess){
    delMessage();
    $fromUserInfo = $match[1] === 'Normal' && $match[3] === 'userinfo';
    $editorKeys = $fromUserInfo ? json_encode(['inline_keyboard'=>[[
        ['text'=>$buttonValues['back_button'], 'callback_data'=>'userInfoDetails'.$match[2]]
    ]]], JSON_UNESCAPED_UNICODE) : $cancelKey;
    sendMessage($fromUserInfo ? \achbotMessage('admin_settings.user_info.agent_percent_prompt')
        : $mainValues['send_agent_discount_percent'], $editorKeys, 'html');
    setUser($data);
}
if(preg_match('/^editAgentDiscount(Server|Plan|Normal)(\d+)_(.*)/',$userInfo['step'],$match) && $agentDiscountAccess && !isset($update->callback_query) && $text != $buttonValues['cancel']){
    if(is_string($text) && preg_match('/\A[0-9]{1,3}\z/D', $text) && (int)$text <= 100){
        $stmt = $connection->prepare("SELECT * FROM users WHERE userid = ? LIMIT 2");
        $stmt->bind_param('i',$match[2]);
        $stmt->execute();
        $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if (count($users) !== 1) {
            sendMessage(\achbotMessage('bot.bot.user_reports.message.c1d7ab477b'));
            return;
        }
        $info = $users[0];
        $userName = $info['name'];
        $discountInfo = json_decode((string)$info['discount_percent'],true);
        if (!is_array($discountInfo)) $discountInfo = [];
        $discountInfo += ['normal'=>0, 'servers'=>[], 'plans'=>[]];
        $percent = (string)(int)$text;
        if($match[1] == "Server") $discountInfo['servers'][$match[3]] = $percent;
        elseif($match[1] == "Plan") $discountInfo['plans'][$match[3]] = $percent;
        elseif($match[1] == "Normal") $discountInfo['normal'] = $percent;
        $encodedDiscount = json_encode($discountInfo);
        $targetPk = (int)$info['id'];
        $stmt = $connection->prepare("UPDATE users SET discount_percent = ? WHERE id = ?");
        $stmt->bind_param("si", $encodedDiscount, $targetPk);
        $stmt->execute();
        $stmt->close();
        sendMessage($mainValues['saved_successfuly'],$removeKeyboard);
        setUser();
        if ($match[1] === 'Normal' && $match[3] === 'userinfo') {
            $details = getUserInfoKeys($match[2]);
            if ($details !== null) sendMessage(
                \achbotMessage('bot.bot.user_reports.message.e55e5591d2', ['text'=>$match[2]]),
                json_encode(['inline_keyboard'=>$details['inline_keyboard']], JSON_UNESCAPED_UNICODE), 'html');
        } else {
            sendMessage(str_replace("AGENT-NAME", $userName, $mainValues['agent_discount_settings']), getAgentDiscounts($match[2]));
        }
    } else sendMessage(\achbotMessage('admin_settings.user_info.agent_percent_invalid'));
}
