<?php
/** Category edits are private, actor-owned, atomic commands. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__.'/../../messages/loader.php';
require_once __DIR__.'/../Admin/ManagementFlow.php';

function achbotCategoryMutation(mysqli $db,int $actor,int $primary,$update,string $data,?string $text,
    string $action,string $expectedStep,?int $categoryId=null): bool {
    $begun=false;
    try {
        achbotManagementFrame($update,$actor,$data,$text);
        if (!$db->begin_transaction()) throw new RuntimeException('management_storage');
        $begun=true;
        $user=achbotAdminIntentActor($db,$actor,$primary,'plans');
        $step=(string)$user['step'];
        if ($step==='banned' || str_starts_with($step,'achbotDirectGift') || str_starts_with($step,'achbotExempt:') ||
            str_starts_with($step,'achbotManage|') || str_starts_with($step,'achbotField|'))
            throw new RuntimeException('management_other_flow');
        if ($action==='start') {
            if ($categoryId!==null && !achbotManagementRow($db,
                'SELECT id FROM server_categories WHERE id=? AND parent=0 AND active=1 FOR UPDATE','i',[$categoryId]))
                throw new RuntimeException('management_stale');
            $next=$expectedStep;
        } elseif ($action==='delete') {
            $s=achbotManagementSql($db,'DELETE FROM server_categories WHERE id=? AND parent=0 AND active=1','i',[$categoryId]);
            $changed=$s->affected_rows;$s->close();
            if ($changed!==1) throw new RuntimeException('management_stale');
            $next=$step;
        } else {
            if ($data!=='' || $step!==$expectedStep || $text===null || trim($text)==='' ||
                !preg_match('//u',$text) || mb_strlen($text,'UTF-8')>50)
                throw new RuntimeException('management_input');
            if ($action==='create') {
                $s=achbotManagementSql($db,'INSERT INTO server_categories(title,parent,active) VALUES (?,0,1)','s',[$text]);
                $changed=$s->affected_rows;$s->close();
                if ($changed!==1) throw new RuntimeException('management_storage');
            } elseif ($action==='rename') {
                if (!achbotManagementRow($db,'SELECT id FROM server_categories WHERE id=? AND parent=0 AND active=1 FOR UPDATE','i',[$categoryId]))
                    throw new RuntimeException('management_stale');
                $s=achbotManagementSql($db,'UPDATE server_categories SET title=? WHERE id=?','si',[$text,$categoryId]);
                $s->close();
            } else {
                throw new LogicException('category_action_unknown');
            }
            $next='none';
        }
        if ($next!==$step) {
            $s=achbotManagementSql($db,'UPDATE users SET step=? WHERE id=? AND userid=?','sii',[$next,(int)$user['id'],$actor]);
            $changed=$s->affected_rows;$s->close();
            if ($changed!==1) throw new RuntimeException('management_storage');
        }
        achbotManagementCommit($db);
        $begun=false;
        return true;
    } catch (Throwable $e) {
        if ($begun) try {$db->rollback();} catch (Throwable $ignored) {}
        $reason=$e->getMessage();
        if (!in_array($reason,['management_input','management_stale','management_other_flow','management_admin_required',
            'management_private_required','management_frame','management_storage','management_commit_unknown'],true)) $reason='management_storage';
        if ($reason==='management_storage') error_log('category_mutation_unconfirmed actor='.$actor.' action='.$action.' type='.get_class($e));
        if (!in_array($reason,['management_frame','management_private_required'],true)) {
            $public=match($reason) {'management_storage'=>'management_unavailable','management_commit_unknown'=>'review_required',default=>$reason};
            sendMessage(achbotManagementText($public), null, 'HTML');
        }
        return false;
    }
}

if (!achbotAdminRequestAllowed(is_string($data??null)?$data:null,is_string($text??null)?$text:null,(string)($userInfo['step']??''))) {achbotAdminDeny();return;}

$categoryData=(string)($data??'');
$categoryStep=(string)($userInfo['step']??'');
$categoryText=is_string($text??null)?$text:null;
$categoryTyped=$categoryData==='' && isset($update->message->text) && !isset($update->callback_query);
$categoryActor=(int)$from_id;$categoryOwner=(int)$admin;
if(($data=='categoriesSetting' || preg_match('/\AnextCategoryPage([0-9]+)\z/D',$categoryData,$match)) &&
    achbotAdminCanFor($connection,$categoryActor,$categoryOwner,'plans')){
    $offset=$data==='categoriesSetting'?0:(int)$match[1];
    $keys=getCategoriesKeys($offset);
    editText($message_id,\achbotMessage('plans.categories.categories_setting.message.12ae9f8483'), $keys);
    return;
}
if ($categoryData==='addNewCategory') {
    if (!achbotCategoryMutation($connection,$categoryActor,$categoryOwner,$update,$categoryData,$categoryText,'start','addNewCategory')) return;
    delMessage();
    $msg=\achbotMessage('plans.categories.add_new_category.msg.3eab115e65');
    sendMessage($msg,$cancelKey);
    return;
}
if ($categoryTyped && $categoryStep==='addNewCategory' && $text!==\achbotMessage('legacy_buttons.cancel')) {
    if (!achbotCategoryMutation($connection,$categoryActor,$categoryOwner,$update,$categoryData,$categoryText,'create',$categoryStep)) return;
    $msg=\achbotMessage('plans.categories.add_new_category.msg.5ba2f5c693');
    sendMessage($msg,$removeKeyboard);
    sendMessage(\achbotCurrentMessage('reached_main_menu'), getCategoriesKeys(), 'HTML');
    return;
}
if (preg_match('/\Awizwizcategorydelete([1-9][0-9]*)_([0-9]+)\z/D',$categoryData,$match)) {
    if (!achbotCategoryMutation($connection,$categoryActor,$categoryOwner,$update,$categoryData,$categoryText,'delete','',(int)$match[1])) return;
    alert(\achbotMessage('plans.categories.add_new_category.alert.d13765614a'));
    $keys=getCategoriesKeys((int)$match[2]);
    editText($message_id,\achbotMessage('plans.categories.categories_setting.message.12ae9f8483'), $keys);
    return;
}
if (preg_match('/\Awizwizcategoryedit([1-9][0-9]*)_([0-9]+)\z/D',$categoryData,$match)) {
    if (!achbotCategoryMutation($connection,$categoryActor,$categoryOwner,$update,$categoryData,$categoryText,'start',$categoryData,(int)$match[1])) return;
    delMessage();
    sendMessage(\achbotMessage('plans.categories.add_new_category.message.15337e0db0'),$cancelKey, 'HTML');
    return;
}
if ($categoryTyped && preg_match('/\Awizwizcategoryedit([1-9][0-9]*)_([0-9]+)\z/D',$categoryStep,$match) &&
    $text!==\achbotMessage('legacy_buttons.cancel')) {
    if (!achbotCategoryMutation($connection,$categoryActor,$categoryOwner,$update,$categoryData,$categoryText,'rename',$categoryStep,(int)$match[1])) return;
    sendMessage(\achbotMessage('plans.administration.edit_custom_plan.message.43afd491f6'), null, 'HTML');
    sendMessage(\achbotMessage('plans.categories.categories_setting.message.12ae9f8483'), getCategoriesKeys($match[2]), 'HTML');
    return;
}
