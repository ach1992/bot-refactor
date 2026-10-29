<?php
/** AChBot simple owner-managed staff levels; caller-scope route. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }
require_once __DIR__.'/../../messages/loader.php';
require_once __DIR__.'/Access.php';

$achbotRoleCallback=is_string($data??null) && preg_match('/^(?:adminsList$|addNewAdmin$|delAdmin[1-9][0-9]*$|adminRole\\||adminAssign\\|)/D',$data);
$achbotRoleInput=($userInfo['step']??'')==='addNewAdmin';
if (($achbotRoleCallback || $achbotRoleInput) &&
    (!achbotAdminCan('admins') || (int)($chat_id??0)!==(int)$from_id)) {
    achbotAdminDeny();return;
}
if (($data??null)==='adminsList') {
    editText($message_id,achbotMessage('admin.roles.admins_list.message.191495d5fc'),getAdminsKeys());
}
if (preg_match('/^delAdmin([1-9][0-9]{0,18})$/D',(string)($data??''),$match)) {
    if (!achbotAdminAssignLevel($connection,(int)$from_id,(int)$admin,(int)$match[1],null))
        alert(achbotMessage('admin.roles.assignment_failed'),true);
    editText($message_id,achbotMessage('admin.roles.admins_list.message.191495d5fc'),getAdminsKeys());
}
if (($data??null)==='addNewAdmin') {
    sendMessage(achbotMessage('admin.roles.add_new_admin.message.026acb2e69'),$cancelKey);
    setUser('addNewAdmin');
}
if (preg_match('/^adminRole\\|([1-9][0-9]{0,18})$/D',(string)($data??''),$match)) {
    if ((int)$match[1]===(int)$admin) {achbotAdminDeny();return;}
    editText($message_id,achbotMessage('admin.roles.choose_level',['userId'=>$match[1]]),
        achbotAdminRoleKeys((int)$match[1]),'HTML');
}
if (preg_match('/^adminAssign\\|([1-9][0-9]{0,18})\\|(manager|operator|support)$/D',(string)($data??''),$match)) {
    if (!achbotAdminAssignLevel($connection,(int)$from_id,(int)$admin,(int)$match[1],$match[2])) {
        alert(achbotMessage('admin.roles.assignment_failed'),true);return;
    }
    editText($message_id,achbotMessage('admin.roles.saved'),getAdminsKeys(),'HTML');
}
if ($achbotRoleInput && ($text??null)!==($buttonValues['cancel']??null)) {
    if (!is_string($text) || !preg_match('/^[1-9][0-9]{0,18}$/D',$text) || (int)$text===(int)$admin) {
        sendMessage(achbotMessage('admin.roles.target_prompt'),$cancelKey,'HTML');return;
    }
    $stmt=$connection->prepare('SELECT id FROM users WHERE userid=? LIMIT 2');
    $target=(int)$text;$stmt->bind_param('i',$target);$stmt->execute();$matches=$stmt->get_result()->num_rows;$stmt->close();
    if ($matches!==1) {sendMessage(achbotMessage('admin.roles.assignment_failed'),$cancelKey,'HTML');return;}
    setUser();
    sendMessage(achbotMessage('admin.roles.choose_level',['userId'=>$text]),achbotAdminRoleKeys($target),'HTML');
}
