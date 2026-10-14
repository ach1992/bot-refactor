<?php
/** AChBot V8: source-pinned financial compatibility module. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

if($data=="increaseUserWallet" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage($mainValues['send_user_id'],$cancelKey);
    setUser($data);
}
if($userInfo['step'] == "increaseUserWallet" && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if(is_numeric($text)){
        $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
        $stmt->bind_param("i", $text);
        $stmt->execute();
        $userCount = $stmt->get_result()->num_rows;
        $stmt->close();
        if($userCount > 0){
            setUser("increaseWalletUser" . $text);
            sendMessage($mainValues['enter_increase_amount']);
        }
        else{
            setUser();
            sendMessage($mainValues['user_not_found'], $removeKeyboard);
            sendMessage($mainValues['reached_main_menu'],getMainKeys());
        }
    }else{
        sendMessage($mainValues['send_only_number']);
    }
}
if(preg_match('/^increaseWalletUser(\d+)/',$userInfo['step'], $match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(is_numeric($text)){
        $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` + ? WHERE `userid` = ?");
        $stmt->bind_param("ii", $text, $match[1]);
        $stmt->execute();
        $stmt->close();
    
        sendMessage("✅ مبلغ " . number_format($text). " تومان به حساب شما اضافه شد.",null,null,$match[1]);
        sendMessage("✅ مبلغ " . number_format($text) . " تومان به کیف پول کاربر مورد نظر اضافه شد.",$removeKeyboard);
        sendMessage($mainValues['reached_main_menu'],getMainKeys());
        setUser();
    }else{
        sendMessage($mainValues['send_only_number']);
    }
}
if($data=="decreaseUserWallet" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage($mainValues['send_user_id'],$cancelKey);
    setUser($data);
}
if($userInfo['step'] == "decreaseUserWallet" && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if(is_numeric($text)){
        $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
        $stmt->bind_param("i", $text);
        $stmt->execute();
        $userCount = $stmt->get_result()->num_rows;
        $stmt->close();
        if($userCount > 0){
            setUser("decreaseWalletUser" . $text);
            sendMessage($mainValues['enter_decrease_amount']);
        }
        else{
            setUser();
            sendMessage($mainValues['user_not_found'], $removeKeyboard);
            sendMessage($mainValues['reached_main_menu'],getMainKeys());
        }
    }else{
        sendMessage($mainValues['send_only_number']);
    }
}
if(preg_match('/^decreaseWalletUser(\d+)/',$userInfo['step'], $match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(is_numeric($text)){
        $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` - ? WHERE `userid` = ?");
        $stmt->bind_param("ii", $text, $match[1]);
        $stmt->execute();
        $stmt->close();
    
        sendMessage(str_replace("AMOUNT", number_format($text), $mainValues['amount_decreased_from_your_wallet']),null,null,$match[1]);
        sendMessage(str_replace("AMOUNT", number_format($text), $mainValues['amount_decreased_from_user_wallet']),$removeKeyboard);
        sendMessage($mainValues['reached_main_menu'],getMainKeys());
        setUser();
    }else{
        sendMessage($mainValues['send_only_number']);
    }
}
