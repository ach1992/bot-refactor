<?php
/** AChBot V7: immutable V6.1 purchase or plan route. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG


if($data == 'dayPlanSettings' and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `increase_day`");
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    if($res->num_rows == 0){
       editText($message_id, \achbotMessage('plans.intervals.day_plan_settings.message.f253e3780e'),json_encode([
                'inline_keyboard' => [
                    [['text' => \achbotMessage('plans.intervals.day_plan_settings.button.4e0a1e41c8'), 'callback_data' =>"addNewDayPlan"]],
                    [['text'=>$buttonValues['back_button'],'callback_data'=>"backplan"]]
                ]
            ]));
        exit;
    }
    $keyboard = [];
    $keyboard[] = [['text'=>\achbotMessage('plans.intervals.day_plan_settings.button.59ca629220'),'callback_data'=>"wizwizch"],['text'=>\achbotMessage('plans.intervals.day_plan_settings.button.7d2c8a1926'),'callback_data'=>"wizwizch"],['text'=>\achbotMessage('plans.intervals.day_plan_settings.button.be0d8f0892'),'callback_data'=>"wizwizch"]];
    while($cat = $res->fetch_assoc()){
        $id = $cat['id'];
        $title = $cat['volume'];
        $price=number_format($cat['price']) . \achbotMessage('plans.administration.edit_custom_plan.text.2cc98f7f7c');
        $acount =$cat['acount'];

        $keyboard[] = [['text'=>\achbotMessage('plans.intervals.day_plan_settings.button.2b2966a54e'),'callback_data'=>"deleteDayPlan" . $id],['text'=>$price,'callback_data'=>"changeDayPlanPrice" . $id],['text'=>$title,'callback_data'=>"changeDayPlanDay" . $id]];
    }
    $keyboard[] = [['text' => \achbotMessage('plans.intervals.day_plan_settings.button.4e0a1e41c8'), 'callback_data' =>"addNewDayPlan"]];
    $keyboard[] = [['text' => $buttonValues['back_button'], 'callback_data' => "backplan"]];
    $msg = \achbotMessage('plans.intervals.day_plan_settings.msg.0eb1f0debf');
    
    editText($message_id,$msg,json_encode([
            'inline_keyboard' => $keyboard
        ]));

    exit;
}
if($data=='addNewDayPlan' and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setUser($data);
    delMessage();
    sendMessage(\achbotMessage('plans.intervals.add_new_day_plan.message.3bf1033cb0'),$cancelKey);exit;
}
if($userInfo['step'] == "addNewDayPlan" and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $input = explode('-',$text);
    $volume=achbotWholeServiceUnits($input[0]??null,achbotServiceVolumeMax());
    if (count($input)!==2 || $volume===null) {sendMessage(\achbotMessage('plans.intervals.add_new_day_plan.message.a2684ce4cc'),$cancelKey);return;}
    $price = $input[1];
    $stmt = $connection->prepare("INSERT INTO `increase_day` VALUES (NULL, ?, ?)");
    $stmt->bind_param("ii", $volume, $price);
    $stmt->execute();
    $stmt->close();
    
    sendMessage(\achbotMessage('plans.intervals.add_new_day_plan.message.9acd097b34'),$removeKeyboard);
    sendMessage($mainValues['reached_main_menu'],getAdminKeys());
    setUser();
}
if(preg_match('/^deleteDayPlan(\d+)/',$data,$match) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("DELETE FROM `increase_day` WHERE `id` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();
    alert(\achbotMessage('plans.intervals.add_new_day_plan.alert.b7bba0b0c0'));
    
    
    $stmt = $connection->prepare("SELECT * FROM `increase_day`");
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    if($res->num_rows == 0){
       editText($message_id, \achbotMessage('plans.intervals.day_plan_settings.message.f253e3780e'),json_encode([
                'inline_keyboard' => [
                    [['text' => \achbotMessage('plans.intervals.day_plan_settings.button.4e0a1e41c8'), 'callback_data' =>"addNewDayPlan"]],
                    [['text'=>$buttonValues['back_button'],'callback_data'=>"managePanel"]]
                ]
            ]));
        exit;
    }
    $keyboard = [];
    $keyboard[] = [['text'=>\achbotMessage('plans.intervals.day_plan_settings.button.59ca629220'),'callback_data'=>"wizwizch"],['text'=>\achbotMessage('plans.intervals.day_plan_settings.button.7d2c8a1926'),'callback_data'=>"wizwizch"],['text'=>\achbotMessage('plans.intervals.day_plan_settings.button.be0d8f0892'),'callback_data'=>"wizwizch"]];
    while($cat = $res->fetch_assoc()){
        $id = $cat['id'];
        $title = $cat['volume'];
        $price=number_format($cat['price']) . \achbotMessage('plans.administration.edit_custom_plan.text.2cc98f7f7c');
        $acount =$cat['acount'];

        $keyboard[] = [['text'=>\achbotMessage('plans.intervals.day_plan_settings.button.2b2966a54e'),'callback_data'=>"deleteDayPlan" . $id],['text'=>$price,'callback_data'=>"changeDayPlanPrice" . $id],['text'=>$title,'callback_data'=>"changeDayPlanDay" . $id]];
    }
    $keyboard[] = [['text' => \achbotMessage('plans.intervals.day_plan_settings.button.4e0a1e41c8'), 'callback_data' =>"addNewDayPlan"]];
    $keyboard[] = [['text' => $buttonValues['back_button'], 'callback_data' => "managePanel"]];
    $msg = \achbotMessage('plans.intervals.day_plan_settings.msg.0eb1f0debf');
    
    editText($message_id,$msg,json_encode([
            'inline_keyboard' => $keyboard
        ]));

    exit;
}
if(preg_match('/^changeDayPlanPrice(\d+)/',$data,$match) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setUser($data);
    delMessage();
    sendMessage(\achbotMessage('plans.intervals.add_new_day_plan.message.b9989bb7c2'), $cancelKey);
    exit;
}
if(preg_match('/^changeDayPlanPrice(\d+)/',$userInfo['step'],$match) and $text != $buttonValues['cancel']){
    if(is_numeric($text)){
        setUser();
        $stmt = $connection->prepare("UPDATE `increase_day` SET `price` = ? WHERE `id` = ?");
        $stmt->bind_param("ii", $text, $match[1]);
        $stmt->execute();
        $stmt->close();
        
        sendMessage(\achbotMessage('plans.intervals.add_new_day_plan.message.d03501095b'),$removeKeyboard);
        
        $stmt = $connection->prepare("SELECT * FROM `increase_day`");
        $stmt->execute();
        $res = $stmt->get_result();
        $stmt->close();
    
        if($res->num_rows == 0){
           sendMessage( \achbotMessage('plans.intervals.day_plan_settings.message.f253e3780e'),json_encode([
                    'inline_keyboard' => [
                        [['text' => \achbotMessage('plans.intervals.day_plan_settings.button.4e0a1e41c8'), 'callback_data' =>"addNewDayPlan"]],
                        [['text'=>$buttonValues['back_button'],'callback_data'=>"managePanel"]]
                    ]
                ]));
            exit;
        }
        $keyboard = [];
        $keyboard[] = [['text'=>\achbotMessage('plans.intervals.day_plan_settings.button.59ca629220'),'callback_data'=>"wizwizch"],['text'=>\achbotMessage('plans.intervals.day_plan_settings.button.7d2c8a1926'),'callback_data'=>"wizwizch"],['text'=>\achbotMessage('plans.intervals.day_plan_settings.button.be0d8f0892'),'callback_data'=>"wizwizch"]];
        while($cat = $res->fetch_assoc()){
            $id = $cat['id'];
            $title = $cat['volume'];
            $price=number_format($cat['price']) . \achbotMessage('plans.administration.edit_custom_plan.text.2cc98f7f7c');
            $acount =$cat['acount'];
    
            $keyboard[] = [['text'=>\achbotMessage('plans.intervals.day_plan_settings.button.2b2966a54e'),'callback_data'=>"deleteDayPlan" . $id],['text'=>$price,'callback_data'=>"changeDayPlanPrice" . $id],['text'=>$title,'callback_data'=>"changeDayPlanDay" . $id]];
        }
        $keyboard[] = [['text' => \achbotMessage('plans.intervals.day_plan_settings.button.4e0a1e41c8'), 'callback_data' =>"addNewDayPlan"]];
        $keyboard[] = [['text' => $buttonValues['back_button'], 'callback_data' => "managePanel"]];
        $msg = \achbotMessage('plans.intervals.add_new_day_plan.msg.34a6e4ec60');
        
        sendMessage($msg,json_encode([
                'inline_keyboard' => $keyboard
            ]));
    
        
    }else{
        sendMessage(\achbotMessage('plans.intervals.add_new_day_plan.message.a7db47ad08'));
    }
}
if(preg_match('/^changeDayPlanDay(\d+)/',$data,$match) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setUser($data);
    delMessage();
    sendMessage(\achbotMessage('plans.intervals.add_new_day_plan.message.395f467a7d'), $cancelKey);
    exit;
}
if(preg_match('/^changeDayPlanDay(\d+)/',$userInfo['step'],$match) && ($from_id == $admin || $userInfo['isAdmin'] == true) and $text != $buttonValues['cancel']) {
    setUser();
    $stmt = $connection->prepare("UPDATE `increase_day` SET `volume` = ? WHERE `id` = ?");
    $stmt->bind_param("ii", $text, $match[1]);
    $stmt->execute();
    $stmt->close();

    sendMessage(\achbotMessage('plans.intervals.add_new_day_plan.message.d03501095b'),$removeKeyboard);
    
    $stmt = $connection->prepare("SELECT * FROM `increase_day`");
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    if($res->num_rows == 0){
       sendMessage( \achbotMessage('plans.intervals.day_plan_settings.message.f253e3780e'),json_encode([
                'inline_keyboard' => [
                    [['text' => \achbotMessage('plans.intervals.day_plan_settings.button.4e0a1e41c8'), 'callback_data' =>"addNewDayPlan"]],
                    [['text'=>$buttonValues['back_button'],'callback_data'=>"managePanel"]]
                ]
            ]));
        exit;
    }
    $keyboard = [];
    $keyboard[] = [['text'=>\achbotMessage('plans.intervals.day_plan_settings.button.59ca629220'),'callback_data'=>"wizwizch"],['text'=>\achbotMessage('plans.intervals.day_plan_settings.button.7d2c8a1926'),'callback_data'=>"wizwizch"],['text'=>\achbotMessage('plans.intervals.day_plan_settings.button.be0d8f0892'),'callback_data'=>"wizwizch"]];
    while($cat = $res->fetch_assoc()){
        $id = $cat['id'];
        $title = $cat['volume'];
        $price=number_format($cat['price']) . \achbotMessage('plans.administration.edit_custom_plan.text.2cc98f7f7c');
        $acount =$cat['acount'];

        $keyboard[] = [['text'=>\achbotMessage('plans.intervals.day_plan_settings.button.2b2966a54e'),'callback_data'=>"deleteDayPlan" . $id],['text'=>$price,'callback_data'=>"changeDayPlanPrice" . $id],['text'=>$title,'callback_data'=>"changeDayPlanDay" . $id]];
    }
    $keyboard[] = [['text' => \achbotMessage('plans.intervals.day_plan_settings.button.4e0a1e41c8'), 'callback_data' =>"addNewDayPlan"]];
    $keyboard[] = [['text' => $buttonValues['back_button'], 'callback_data' => "managePanel"]];
    $msg = \achbotMessage('plans.intervals.day_plan_settings.msg.0eb1f0debf');
    
    sendMessage($msg,json_encode([
            'inline_keyboard' => $keyboard
        ]));

    
}
if($data == 'volumePlanSettings' and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `increase_plan`");
    $stmt->execute();
    $plans = $stmt->get_result();
    $stmt->close();
    
    if($plans->num_rows == 0){
       editText($message_id, \achbotMessage('plans.intervals.volume_plan_settings.message.63f1e596ef'),json_encode([
                'inline_keyboard' => [
                    [['text' => \achbotMessage('plans.intervals.volume_plan_settings.button.a3524cb835'), 'callback_data' =>"addNewVolumePlan"]],
                    [['text' => $buttonValues['back_button'],'callback_data'=>"backplan"]]
                    ]]));
        exit;
    }
    $keyboard = [];
    $keyboard[] = [['text'=>\achbotMessage('plans.intervals.day_plan_settings.button.59ca629220'),'callback_data'=>"wizwizch"],['text'=>\achbotMessage('plans.intervals.day_plan_settings.button.7d2c8a1926'),'callback_data'=>"wizwizch"],['text'=>\achbotMessage('plans.intervals.volume_plan_settings.button.57fa83c28f'),'callback_data'=>"wizwizch"]];
    while ($cat = $plans->fetch_assoc()){
        $id = $cat['id'];
        $title = $cat['volume'];
        $price=number_format($cat['price']) . \achbotMessage('plans.administration.edit_custom_plan.text.2cc98f7f7c');
        
        $keyboard[] = [['text'=>\achbotMessage('plans.intervals.day_plan_settings.button.2b2966a54e'),'callback_data'=>"deleteVolumePlan" . $id],['text'=>$price,'callback_data'=>"changeVolumePlanPrice" . $id],['text'=>$title,'callback_data'=>"changeVolumePlanVolume" . $id]];
    }
    $keyboard[] = [['text' => \achbotMessage('plans.intervals.volume_plan_settings.button.a3524cb835'), 'callback_data' =>"addNewVolumePlan"]];
    $keyboard[] = [['text' =>$buttonValues['back_button'], 'callback_data' => "backplan"]];
    $msg = \achbotMessage('plans.intervals.volume_plan_settings.msg.573ae79cf6');
    
    $res = editText($message_id, $msg,json_encode([
            'inline_keyboard' => $keyboard
        ]));
    exit;
}
if($data=='addNewVolumePlan' and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setUser($data);
    delMessage();
    sendMessage(\achbotMessage('plans.intervals.add_new_volume_plan.message.d36c49c30e'),$cancelKey);
 exit;
}
if($userInfo['step'] == "addNewVolumePlan" and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $input=explode('-',$text);
    $volume=achbotWholeServiceUnits($input[0]??null,achbotServiceVolumeMax());
    if (count($input)!==2 || $volume===null) {sendMessage(\achbotMessage('plans.intervals.add_new_day_plan.message.a2684ce4cc'),$cancelKey);return;}
    $price=$input[1];
    $stmt = $connection->prepare("INSERT INTO `increase_plan` VALUES (NULL, ? ,?)");
    $stmt->bind_param("ii",$volume,$price);
    $stmt->execute();
    $stmt->close();
    
    sendMessage(\achbotMessage('plans.intervals.add_new_volume_plan.message.b003cdcfab'),$removeKeyboard);
    sendMessage($mainValues['reached_main_menu'],getAdminKeys());
    setUser();
}
if(preg_match('/^deleteVolumePlan(\d+)/',$data,$match) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("DELETE FROM `increase_plan` WHERE `id` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();
    alert(\achbotMessage('plans.intervals.add_new_volume_plan.alert.d5009733f9'));
    
    
    $stmt = $connection->prepare("SELECT * FROM `increase_plan`");
    $stmt->execute();
    $plans = $stmt->get_result();
    $stmt->close();
    
    if($plans->num_rows == 0){
       editText($message_id, \achbotMessage('plans.intervals.volume_plan_settings.message.63f1e596ef'),json_encode([
                'inline_keyboard' => [
                    [['text' => \achbotMessage('plans.intervals.volume_plan_settings.button.a3524cb835'), 'callback_data' =>"addNewVolumePlan"]],
                    [['text' => $buttonValues['back_button'],'callback_data'=>"managePanel"]]
                    ]]));
        exit;
    }
    $keyboard = [];
    $keyboard[] = [['text'=>\achbotMessage('plans.intervals.day_plan_settings.button.59ca629220'),'callback_data'=>"wizwizch"],['text'=>\achbotMessage('plans.intervals.day_plan_settings.button.7d2c8a1926'),'callback_data'=>"wizwizch"],['text'=>\achbotMessage('plans.intervals.volume_plan_settings.button.57fa83c28f'),'callback_data'=>"wizwizch"]];
    while ($cat = $plans->fetch_assoc()){
        $id = $cat['id'];
        $title = $cat['volume'];
        $price=number_format($cat['price']) . \achbotMessage('plans.administration.edit_custom_plan.text.2cc98f7f7c');
        
        $keyboard[] = [['text'=>\achbotMessage('plans.intervals.day_plan_settings.button.2b2966a54e'),'callback_data'=>"deleteVolumePlan" . $id],['text'=>$price,'callback_data'=>"changeVolumePlanPrice" . $id],['text'=>$title,'callback_data'=>"changeVolumePlanVolume" . $id]];
    }
    $keyboard[] = [['text' => \achbotMessage('plans.intervals.volume_plan_settings.button.a3524cb835'), 'callback_data' =>"addNewVolumePlan"]];
    $keyboard[] = [['text' =>$buttonValues['back_button'], 'callback_data' => "managePanel"]];
    $msg = \achbotMessage('plans.intervals.volume_plan_settings.msg.573ae79cf6');
    
    $res = editText($message_id, $msg,json_encode([
            'inline_keyboard' => $keyboard
        ]));
}
if(preg_match('/^changeVolumePlanPrice(\d+)/',$data,$match) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setUser($data);
    delMessage();
    sendMessage(\achbotMessage('plans.intervals.add_new_day_plan.message.b9989bb7c2'), $cancelKey);
    exit;
}
if(preg_match('/^changeVolumePlanPrice(\d+)/',$userInfo['step'],$match) and $text != $buttonValues['cancel'] and ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $pid=$match[1];
    if(is_numeric($text)){
        $stmt = $connection->prepare("UPDATE `increase_plan` SET `price` = ? WHERE `id` = ?");
        $stmt->bind_param("ii", $text, $pid);
        $stmt->execute();
        $stmt->close();
        sendMessage(\achbotMessage('plans.intervals.add_new_volume_plan.message.76731ef62b'),$removeKeyboard);
        
        setUser();
        $stmt = $connection->prepare("SELECT * FROM `increase_plan`");
        $stmt->execute();
        $plans = $stmt->get_result();
        $stmt->close();
        
        if($plans->num_rows == 0){
           sendMessage( \achbotMessage('plans.intervals.volume_plan_settings.message.63f1e596ef'),json_encode([
                    'inline_keyboard' => [
                        [['text' => \achbotMessage('plans.intervals.volume_plan_settings.button.a3524cb835'), 'callback_data' =>"addNewVolumePlan"]],
                        [['text' => $buttonValues['back_button'],'callback_data'=>"managePanel"]]
                        ]]));
            exit;
        }
        $keyboard = [];
        $keyboard[] = [['text'=>\achbotMessage('plans.intervals.day_plan_settings.button.59ca629220'),'callback_data'=>"wizwizch"],['text'=>\achbotMessage('plans.intervals.day_plan_settings.button.7d2c8a1926'),'callback_data'=>"wizwizch"],['text'=>\achbotMessage('plans.intervals.volume_plan_settings.button.57fa83c28f'),'callback_data'=>"wizwizch"]];
        while ($cat = $plans->fetch_assoc()){
            $id = $cat['id'];
            $title = $cat['volume'];
            $price=number_format($cat['price']) . \achbotMessage('plans.administration.edit_custom_plan.text.2cc98f7f7c');
            
            $keyboard[] = [['text'=>\achbotMessage('plans.intervals.day_plan_settings.button.2b2966a54e'),'callback_data'=>"deleteVolumePlan" . $id],['text'=>$price,'callback_data'=>"changeVolumePlanPrice" . $id],['text'=>$title,'callback_data'=>"changeVolumePlanVolume" . $id]];
        }
        $keyboard[] = [['text' => \achbotMessage('plans.intervals.volume_plan_settings.button.a3524cb835'), 'callback_data' =>"addNewVolumePlan"]];
        $keyboard[] = [['text' =>$buttonValues['back_button'], 'callback_data' => "managePanel"]];
        $msg = \achbotMessage('plans.intervals.add_new_volume_plan.msg.9725f12c7b');
        
        $res = sendMessage($msg,json_encode([
                'inline_keyboard' => $keyboard
            ]));
    }else{
        sendMessage(\achbotMessage('plans.intervals.add_new_day_plan.message.a7db47ad08'));
    }
}
if(preg_match('/^changeVolumePlanVolume(\d+)/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setUser($data);
    delMessage();
    sendMessage(\achbotMessage('plans.intervals.add_new_volume_plan.message.149e31d927'),$cancelKey);
    exit;
}
if(preg_match('/^changeVolumePlanVolume(\d+)/',$userInfo['step'], $match) and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $pid=$match[1];
    $volume=achbotWholeServiceUnits($text,achbotServiceVolumeMax());
    if ($volume===null) {sendMessage(\achbotMessage('plans.intervals.add_new_day_plan.message.a2684ce4cc'),$cancelKey);return;}
    $stmt = $connection->prepare("UPDATE `increase_plan` SET `volume` = ? WHERE `id` = ?");
    $stmt->bind_param("ii", $volume, $pid);
    $stmt->execute();
    $stmt->close();
    sendMessage(\achbotMessage('plans.intervals.add_new_day_plan.message.d03501095b'),$removeKeyboard);
    setUser();

    $stmt = $connection->prepare("SELECT * FROM `increase_plan`");
    $stmt->execute();
    $plans = $stmt->get_result();
    $stmt->close();
    
    if($plans->num_rows == 0){
       sendMessage( \achbotMessage('plans.intervals.volume_plan_settings.message.63f1e596ef'),json_encode([
                'inline_keyboard' => [
                    [['text' => \achbotMessage('plans.intervals.volume_plan_settings.button.a3524cb835'), 'callback_data' =>"addNewVolumePlan"]],
                    [['text' => $buttonValues['back_button'],'callback_data'=>"managePanel"]]
                    ]]));
        exit;
    }
    $keyboard = [];
    $keyboard[] = [['text'=>\achbotMessage('plans.intervals.day_plan_settings.button.59ca629220'),'callback_data'=>"wizwizch"],['text'=>\achbotMessage('plans.intervals.day_plan_settings.button.7d2c8a1926'),'callback_data'=>"wizwizch"],['text'=>\achbotMessage('plans.intervals.volume_plan_settings.button.57fa83c28f'),'callback_data'=>"wizwizch"]];
    while ($cat = $plans->fetch_assoc()){
        $id = $cat['id'];
        $title = $cat['volume'];
        $price=number_format($cat['price']) . \achbotMessage('plans.administration.edit_custom_plan.text.2cc98f7f7c');
        
        $keyboard[] = [['text'=>\achbotMessage('plans.intervals.day_plan_settings.button.2b2966a54e'),'callback_data'=>"deleteVolumePlan" . $id],['text'=>$price,'callback_data'=>"changeVolumePlanPrice" . $id],['text'=>$title,'callback_data'=>"changeVolumePlanVolume" . $id]];
    }
    $keyboard[] = [['text' => \achbotMessage('plans.intervals.volume_plan_settings.button.a3524cb835'), 'callback_data' =>"addNewVolumePlan"]];
    $keyboard[] = [['text' =>$buttonValues['back_button'], 'callback_data' => "managePanel"]];
    $msg = \achbotMessage('plans.intervals.volume_plan_settings.msg.573ae79cf6');
    
    $res = sendMessage( $msg,json_encode([
            'inline_keyboard' => $keyboard
        ]));
    
}
