<?php
/** AChBot V7: immutable V6.1 purchase or plan route. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG

require_once __DIR__.'/../Admin/Access.php';
if (!achbotAdminRequestAllowed(is_string($data??null)?$data:null,is_string($text??null)?$text:null,(string)($userInfo['step']??''))) {achbotAdminDeny();return;}


if(($data=='categoriesSetting' || preg_match('/^nextCategoryPage(\d+)/',$data,$match)) and achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'plans')){
    if(isset($match[1])) $keys = getCategoriesKeys($match[1]);
    else $keys = getCategoriesKeys();
    
    editText($message_id,\achbotMessage('plans.categories.categories_setting.message.12ae9f8483'), $keys);
}
if($data=='addNewCategory' and (achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'plans'))){
    setUser($data);
    delMessage();
    $stmt = $connection->prepare("DELETE FROM `server_categories` WHERE `active`=0");
    $stmt->execute();
    $stmt->close();


    $sql = "INSERT INTO `server_categories` VALUES (NULL, 0, '', 0,2,0);";
    $stmt = $connection->prepare($sql);
    $stmt->execute();
    $stmt->close();


    $msg = \achbotMessage('plans.categories.add_new_category.msg.3eab115e65');
    sendMessage($msg,$cancelKey);
    exit;
}
if(preg_match('/^addNewCategory/',$userInfo['step']) and $text!=$buttonValues['cancel'] && achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'plans')){
    $step = checkStep('server_categories');
    if($step==2 and $text!=$buttonValues['cancel'] ){
        
        $stmt = $connection->prepare("UPDATE `server_categories` SET `title`=?,`step`=4,`active`=1 WHERE `active`=0");
        $stmt->bind_param("s", $text);
        $stmt->execute();
        $stmt->close();


        $msg = \achbotMessage('plans.categories.add_new_category.msg.5ba2f5c693');
        sendMessage($msg,$removeKeyboard);
        sendMessage($mainValues['reached_main_menu'], getCategoriesKeys(), 'HTML');
    }
}
if(preg_match('/^wizwizcategorydelete(\d+)_(\d+)/',$data, $match) and achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'plans')){
    $stmt = $connection->prepare("DELETE FROM `server_categories` WHERE `id`=?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();

    alert(\achbotMessage('plans.categories.add_new_category.alert.d13765614a'));
    
    $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `active`=1 AND `parent`=0");
    $stmt->execute();
    $cats = $stmt->get_result();
    $stmt->close();

    $keys = getCategoriesKeys($match[2]);
    editText($message_id,\achbotMessage('plans.categories.categories_setting.message.12ae9f8483'), $keys);
}
if(preg_match('/^wizwizcategoryedit/',$data) and achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'plans') && $text != $buttonValues['cancel']){
    setUser($data);
    delMessage();
    sendMessage(\achbotMessage('plans.categories.add_new_category.message.15337e0db0'),$cancelKey);exit;
}
if(preg_match('/wizwizcategoryedit(\d+)_(\d+)/',$userInfo['step'], $match) && achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'plans') && $text != $buttonValues['cancel']){
    $stmt = $connection->prepare("UPDATE `server_categories` SET `title`=? WHERE `id`=?");
    $stmt->bind_param("si", $text, $match[1]);
    $stmt->execute();
    $stmt->close();

    sendMessage(\achbotMessage('plans.administration.edit_custom_plan.message.43afd491f6'));
    setUser();
    
    sendMessage(\achbotMessage('plans.categories.categories_setting.message.12ae9f8483'), getCategoriesKeys($match[2]));
}
