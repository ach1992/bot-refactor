<?php
/** AChBot V8: source-pinned financial compatibility module. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../../messages/loader.php'; // ACHBOT_TEXT_CATALOG


if(preg_match('/sConfigRenew(\d+)/', $data,$match)){
    if($botState['sellState']=="off" && $from_id !=$admin){ alert($mainValues['bot_is_updating']); exit(); }
    
    alert($mainValues['please_wait_message']);
    $server_id = $match[1];
    if(empty($userInfo['temp'])){delMessage(); exit();}
    
    $configInfo = json_decode($userInfo['temp'],true);
    $inboundId = $configInfo['id']??0;
    $uuid = $configInfo['uuid'];
    $remark = $configInfo['remark'];

    if (empty($configInfo['marzban']) || (int)$inboundId !== 0) {
        alert(\achbotMessage('renewal.renewal_checkout.alert.6aa152a253'), true);
        return;
    }
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `server_id` = ? AND `custom_sni` LIKE '%inbounds%' AND `active` = 1 AND `price` != 0 AND `inbound_id` = 0 ORDER BY id ASC");
    $stmt->bind_param("i", $server_id);

    $stmt->execute();
    $plans = $stmt->get_result();
    $stmt->close();
    if($plans->num_rows > 0){
        $keyboard = [];
        while($file = $plans->fetch_assoc()){ 
            {
                $id = $file['id'];
                $name = $file['title'];
                $price = $file['price'];
                $price = ($price == 0) ? \achbotMessage('renewal.extensions.price.dda7ff2974') : number_format($price).\achbotMessage('renewal.renewal_selection.text.bfb7221456');
                $keyboard[] = ['text' => "$name - $price", 'callback_data' => "sConfigRenewPlan{$id}_{$inboundId}"];
            }
        }
        $keyboard[] = ['text' => $buttonValues['back_to_main'], 'callback_data' => "mainMenu"];
        $keyboard = array_chunk($keyboard,1);
        editText($message_id, \achbotMessage('renewal.renewal_selection.message.51ab077156'), json_encode(['inline_keyboard'=>$keyboard]));
    }else sendMessage(\achbotMessage('renewal.renewal_selection.message.5223e5a747'));
}

