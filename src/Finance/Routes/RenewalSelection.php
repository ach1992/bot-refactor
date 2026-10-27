<?php
/** AChBot V8: source-pinned financial compatibility module. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

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
        alert("⛔️ تمدید این اشتراک در حال حاضر امکان‌پذیر نیست.", true);
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
                $price = ($price == 0) ? 'رایگان' : number_format($price).' تومان ';
                $keyboard[] = ['text' => "$name - $price", 'callback_data' => "sConfigRenewPlan{$id}_{$inboundId}"];
            }
        }
        $keyboard[] = ['text' => $buttonValues['back_to_main'], 'callback_data' => "mainMenu"];
        $keyboard = array_chunk($keyboard,1);
        editText($message_id, "3️⃣ مرحله سه

🤲 🕋 یکی از پلن هارو انتخاب کنید.
➕ اگر سرویس مورد نظر شما در لیست زیر نیست لازم هست اول اشتراک را به لیست اشتراک های خودتان اضافه کنید و بعد با زدن دکمه ← 🔄 تغییر پلن، پلن انتخاب کنید تا بتوانید اشتراک خودتان را تمدید و یا شارژ کنید. ", json_encode(['inline_keyboard'=>$keyboard]));
    }else sendMessage("💡 پلنی در این دسته بندی وجود ندارد. ");
}

