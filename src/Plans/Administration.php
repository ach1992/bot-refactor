<?php
/** AChBot V7: immutable V6.1 purchase or plan route. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG

require_once __DIR__.'/../Admin/Access.php';
require_once __DIR__.'/../Admin/IntentSafety.php';
require_once __DIR__.'/../Telegram/Conversation.php';
if (!achbotAdminRequestAllowed(is_string($data??null)?$data:null,is_string($text??null)?$text:null,(string)($userInfo['step']??''))) {achbotAdminDeny();return;}


if($data == 'backplan' and achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'plans')){
    $stmt = $connection->prepare("SELECT si.* FROM `server_info` si INNER JOIN `server_config` sc ON sc.`id`=si.`id` AND sc.`type` IN (".achbotProviderSqlTypes().") WHERE si.`active`=1");
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    $keyboard = [];
    while($cat = $res->fetch_assoc()){
        $id = $cat['id'];
        $title = $cat['title'];
        $keyboard[] = ['text' => "$title", 'callback_data' => "plansList$id"];
    }
    $keyboard = array_chunk($keyboard,2);
    $keyboard[] = [['text'=>\achbotMessage('plans.administration.backplan.button.9708693ebe'),'callback_data'=>"wizwizch"]];
    $keyboard[] = [['text'=>\achbotMessage('admin_settings.management.add_plan'),'callback_data'=>'addManagedPlan']];
    $keyboard[] = [['text'=>\achbotMessage('plans.administration.backplan.button.2abde87b2a'),'callback_data'=>"volumePlanSettings"],['text'=>\achbotMessage('plans.administration.backplan.button.92c1307fe6'),'callback_data'=>"dayPlanSettings"]];
    $keyboard[] = [['text' => \achbotMessage('plans.administration.backplan.button.d9172c2e21'), 'callback_data' => "editCustomPlan"]];
    $keyboard[] = [['text' => \achbotMessage('legacy_buttons.back_button'), 'callback_data' => "managePanel"]];

    $msg = \achbotMessage('plans.administration.backplan.msg.20bb3eca34');

    if(isset($data) and $data=='backplan') {
        editText($message_id, $msg, json_encode(['inline_keyboard'=>$keyboard]));
    }else { sendAction('typing');
        sendmessage($msg, json_encode(['inline_keyboard'=>$keyboard]),'HTML');
    }


    exit;
}
if(($data=="editCustomPlan" || (isset($update->message->text) && !isset($update->callback_query) && preg_match('/^editCustom(gbPrice|dayPrice)$/D',$userInfo['step'],$match))) && achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'plans') && $text != \achbotMessage('legacy_buttons.cancel')){
    if(!isset($update->callback_query)){
        if (!isset($update->message->text) || isset($update->callback_query)) return;
        if(is_numeric($text)){
            if (!setSettings($match[1],$text,$userInfo)) return;
            sendMessage(\achbotMessage('legacy_messages.saved_successfuly'),$removeKeyboard, 'HTML');
        }else{
            sendMessage(\achbotMessage('plans.administration.edit_custom_plan.message.be59f23713'), null, 'HTML');
            exit();
        }
    }
    $gbPrice=number_format($botState['gbPrice']??0) . \achbotMessage('plans.administration.edit_custom_plan.text.2cc98f7f7c');
    $dayPrice=number_format($botState['dayPrice']??0) . \achbotMessage('plans.administration.edit_custom_plan.text.2cc98f7f7c');

    $keys = json_encode(['inline_keyboard'=>[
        [
            ['text'=>$gbPrice,'callback_data'=>"editCustomgbPrice"],
            ['text'=>\achbotMessage('plans.administration.edit_custom_plan.button.7a96416b62'),'callback_data'=>"wizwizch"]
            ],
        [
            ['text'=>$dayPrice,'callback_data'=>"editCustomdayPrice"],
            ['text'=>\achbotMessage('plans.administration.edit_custom_plan.button.31bc93a08d'),'callback_data'=>"wizwizch"]
            ],
        [
            ['text'=>\achbotMessage('legacy_buttons.back_button'),'callback_data'=>"backplan"]
            ]

        ]]);
    if(!isset($update->callback_query)){
        sendMessage(\achbotMessage('plans.administration.edit_custom_plan.message.e3b03f6c8e'),$keys, 'HTML');

    }else{
        editText($message_id,\achbotMessage('plans.administration.edit_custom_plan.message.e3b03f6c8e'),$keys);
    }
}
if(preg_match('/^editCustom(gbPrice|dayPrice)/',$data,$match) && achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'plans')){
    if (!setUser($data)) return;
    try {delMessage();} catch(Throwable $ignored){}
    $title = $match[1] == "dayPrice"?\achbotMessage('plans.administration.edit_custom_plan.text.b641937e16'):\achbotMessage('plans.administration.edit_custom_plan.text.c84922eddf');
    sendMessage(\achbotMessage('plans.administration.edit_custom_plan.message.207313a746') . $title . \achbotMessage('plans.administration.edit_custom_plan.message.ae9d133be8'),$cancelKey,'HTML');
    return;
}
if(preg_match('/plansList(\d+)/', $data,$match) && achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'plans')){
    $stmt = $connection->prepare("SELECT p.* FROM `server_plans` p INNER JOIN `server_config` sc ON sc.`id`=p.`server_id` AND sc.`type` IN (".achbotProviderSqlTypes().") WHERE p.`server_id`=? AND p.`inbound_id`=0 ORDER BY p.`id` ASC");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    if($res->num_rows==0){
        $sid=(int)$match[1];
        editText($message_id,\achbotMessage('plans.administration.edit_custom_plan.alert.1633521d7d'),json_encode(['inline_keyboard'=>[
            [['text'=>\achbotMessage('admin_settings.management.add_plan'),'callback_data'=>'newPlanForServer'.$sid]],
            [['text'=>\achbotMessage('legacy_buttons.back_button'),'callback_data'=>'showServerSettings'.$sid.'_0']]
        ]],JSON_UNESCAPED_UNICODE));
        exit;
    }else {
        $keyboard = [];
        while($cat = $res->fetch_assoc()){
            $id = $cat['id'];
            $title = $cat['title'];
            $keyboard[] = ['text' => "#$id $title", 'callback_data' => "planDetails$id"];
        }
        $keyboard = array_chunk($keyboard,2);
        $keyboard[] = [['text'=>\achbotMessage('admin_settings.management.add_plan'),'callback_data'=>'newPlanForServer'.(int)$match[1]]];
        $keyboard[] = [['text' => \achbotMessage('legacy_buttons.back_button'), 'callback_data' => "backplan"],];
        $msg = \achbotMessage('plans.administration.edit_custom_plan.msg.537f60e05a');
        editText($message_id, $msg, json_encode(['inline_keyboard'=>$keyboard]), "HTML");
    }
    exit();
}
if(preg_match('/planDetails(\d+)/', $data,$match) && achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'plans')){
    $keys = getPlanDetailsKeys($match[1]);
    if($keys == null){
        alert(\achbotMessage('plans.administration.edit_custom_plan.alert.756c3005d3'));
        exit;
    }else editText($message_id, \achbotMessage('plans.administration.edit_custom_plan.message.06d5c646c1'), $keys, "HTML");
}
if(preg_match('/^wizwizplanacclist(\d+)/',$data,$match) and achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'plans')){
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `status`=1 AND `fileid`=?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    if($res->num_rows == 0){
        alert(\achbotMessage('plans.administration.edit_custom_plan.alert.7aeabddffe'));
        exit;
    }
    $txt = '';
    while($order = $res->fetch_assoc()){
		$suid = $order['userid'];
		$stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid`=?");
        $stmt->bind_param("i", $suid);
        $stmt->execute();
        $ures = $stmt->get_result()->fetch_assoc();
        $stmt->close();


        $date = $order['date'];
        $remark = $order['remark'];
        $date = jdate('Y-m-d H:i', $date);
        $uname = $ures['name'];
        $sold = \achbotMessage('plans.administration.edit_custom_plan.sold.caead5bf3e').$uname. " ($date)";
        $accid = $order['id'];
        $orderLink = json_decode($order['link'],true);
        $txt = \achbotMessage('plans.administration.edit_custom_plan.sold_remark.908883cc30', ['sold' => $sold, 'remark' => $remark]);
        $server=getServerConfigById((int)$order['server_id']);
        $presentation=achbotOrderPresentation($connection,$order,$server,$botState);
        $txt .= "\n".$presentation['config_html']."\n".$presentation['subscription_html'].
            $presentation['backup_html']."\n".$presentation['notice_html'];
        $txt .= \achbotMessageHtml('plans.administration.edit_custom_plan.channel_lock.5d72dee564', ['channelLock' => $channelLock]);
        sendMessage($txt, null, "HTML");
    }
}
if(preg_match('/^setPlanSale([1-9][0-9]*)_([01])_([a-f0-9]{16})$/D',(string)$data,$match) &&
    achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'plans')){
    $planId=(int)$match[1];
    try {
        achbotAdminPlanStateSet($connection,(int)$from_id,(int)$admin,
            $planId,(int)$match[2],$match[3],$update,(string)$data);
        editText($message_id,\achbotMessage('plans.administration.edit_custom_plan.message.06d5c646c1'),
            getPlanDetailsKeys($planId),'HTML');
    } catch (Throwable $error) {
        error_log('achbot_admin_plan_visibility_deferred');
        alert(\achbotMessage('admin_settings.management.management_stale'),true);
    }
    return;
}
if(preg_match('/^wizwizplandelete([1-9][0-9]*)$/D',(string)$data,$match) &&
    achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'plans')){
    $planId=(int)$match[1];
    try {
        $confirmation=achbotAdminIntentIssue($connection,(int)$from_id,(int)$admin,
            'plan',$planId,$update,(string)$data);
        $keys=json_encode(['inline_keyboard'=>[
            [['text'=>\achbotMessage('admin_settings.config.get_plan_details_keys.button.c3a5bc0cbd'),
              'callback_data'=>$confirmation]],
            [['text'=>\achbotMessage('legacy_buttons.back_button'),'callback_data'=>
              str_replace('confirmDeletePlan','cancelDeletePlan',$confirmation)]]
        ]],JSON_UNESCAPED_UNICODE);
        editText($message_id,\achbotMessage('admin_settings.management.plan_delete_confirm'),$keys,'HTML');
    } catch (Throwable $error) {
        error_log('achbot_admin_plan_delete_warning_deferred');
        alert(\achbotMessage('admin_settings.management.management_stale'),true);
    }
    return;
}
if(preg_match('/^cancelDeletePlan([1-9][0-9]*)_([a-f0-9]{16})$/D',(string)$data,$match) &&
    achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'plans')){
    $planId=(int)$match[1];$begun=false;
    try {
        if (!$connection->begin_transaction())throw new RuntimeException('plan_delete_cancel_begin');$begun=true;
        achbotAdminIntentCancelLocked($connection,(int)$from_id,(int)$admin,
            'plan',$planId,$match[2],$update,(string)$data);
        if (!$connection->commit())throw new RuntimeException('plan_delete_cancel_commit_unknown');$begun=false;
        editText($message_id,\achbotMessage('plans.administration.edit_custom_plan.message.06d5c646c1'),
            getPlanDetailsKeys($planId),'HTML');
    } catch (Throwable $error) {
        if ($begun) {try{$connection->rollback();}catch(Throwable $ignored){}}
        error_log('achbot_admin_plan_delete_cancel_deferred');
        alert(\achbotMessage('admin_settings.management.management_stale'),true);
    }
    return;
}
if(preg_match('/^confirmDeletePlan([1-9][0-9]*)_([a-f0-9]{16})$/D',(string)$data,$match) &&
    achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'plans')){
    $planId=(int)$match[1];$begun=false;
    try {
        if (!$connection->begin_transaction())throw new RuntimeException('plan_delete_begin');$begun=true;
        achbotAdminIntentConsumeLocked($connection,(int)$from_id,(int)$admin,
            'plan',$planId,$match[2],$update,(string)$data);
        // Only the local plan is deleted. Referencing orders and finance remain historical.
        $stmt=$connection->prepare('DELETE FROM server_plans WHERE id=?');
        $stmt->bind_param('i',$planId);
        if (!$stmt->execute() || $stmt->affected_rows!==1) {
            $stmt->close();throw new RuntimeException('plan_delete_stale');
        }
        $stmt->close();
        achbotAdminPlanStateBumpLocked($connection,$planId);
        if (!$connection->commit())throw new RuntimeException('plan_delete_commit_unknown');$begun=false;
        editText($message_id,\achbotMessage('admin_settings.management.plan_deleted'),getMainKeys());
    } catch (Throwable $error) {
        if ($begun) {try{$connection->rollback();}catch(Throwable $ignored){}}
        error_log('achbot_admin_plan_delete_deferred');
        alert(\achbotMessage('admin_settings.management.management_stale'),true);
    }
    return;
}
if(preg_match('/^wizwizplanname(\d+)/',$data) and achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'plans') && $text != \achbotMessage('legacy_buttons.cancel')){
    if (!setUser($data)) return;
    try {delMessage();} catch(Throwable $ignored){}
    sendMessage(\achbotMessage('plans.administration.edit_custom_plan.message.0253b1982e'),$cancelKey, 'HTML');exit;
}
if(isset($update->message->text) && !isset($update->callback_query) && preg_match('/^wizwizplanname(\d+)/',$userInfo['step'], $match) && $text != \achbotMessage('legacy_buttons.cancel') && achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'plans')){
    $closed=\AchBot\Telegram\Conversation\transitionEditor($connection,(int)$from_id,$userInfo,'none',null,
        static function(mysqli $db) use($from_id,$admin,$match,$text): void {
            if (!achbotAdminCanLocked($db,(int)$from_id,(int)$admin,'plans') ||
                !achbotProviderRow($db,'SELECT id FROM server_plans WHERE id=? FOR UPDATE','i',[(int)$match[1]]))
                throw new DomainException('plan_editor_target_changed');
            $q=achbotProviderStatement($db,'UPDATE server_plans SET title=? WHERE id=?','si',[$text,(int)$match[1]]);$q->close();
        });
    if (!$closed) return;

    sendMessage(\achbotMessage('plans.administration.edit_custom_plan.message.43afd491f6'), $removeKeyboard, 'HTML');


    $keys = getPlanDetailsKeys($match[1]);
    if($keys == null){
        alert(\achbotMessage('plans.administration.edit_custom_plan.alert.756c3005d3'));
        exit;
    }else sendMessage(\achbotMessage('plans.administration.edit_custom_plan.message.06d5c646c1'), $keys, 'HTML');
}
if(preg_match('/^wizwizplaneditdes(\d+)/',$data) and achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'plans') && $text != \achbotMessage('legacy_buttons.cancel')){
    if (!setUser($data)) return;
    try {delMessage();} catch(Throwable $ignored){}
    sendMessage(\achbotMessage('plans.administration.edit_custom_plan.message.090fe8ddfd'),$cancelKey, 'HTML');exit;
}
if(isset($update->message->text) && !isset($update->callback_query) && preg_match('/^wizwizplaneditdes(\d+)/',$userInfo['step'], $match) && achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'plans') && $text != \achbotMessage('legacy_buttons.cancel')){
    $closed=\AchBot\Telegram\Conversation\transitionEditor($connection,(int)$from_id,$userInfo,'none',null,
        static function(mysqli $db) use($from_id,$admin,$match,$text): void {
            if (!achbotAdminCanLocked($db,(int)$from_id,(int)$admin,'plans') ||
                !achbotProviderRow($db,'SELECT id FROM server_plans WHERE id=? FOR UPDATE','i',[(int)$match[1]]))
                throw new DomainException('plan_editor_target_changed');
            $q=achbotProviderStatement($db,'UPDATE server_plans SET descr=? WHERE id=?','si',[$text,(int)$match[1]]);$q->close();
        });
    if (!$closed) return;


    sendMessage(\achbotMessage('plans.administration.edit_custom_plan.message.43afd491f6'), $removeKeyboard, 'HTML');


    $keys = getPlanDetailsKeys($match[1]);
    if($keys == null){
        alert(\achbotMessage('plans.administration.edit_custom_plan.alert.756c3005d3'));
        exit;
    }else sendMessage(\achbotMessage('plans.administration.edit_custom_plan.message.06d5c646c1'), $keys, "HTML");
}
if(preg_match('/^wizwizplanrial(\d+)/',$data) and achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'plans') && $text != \achbotMessage('legacy_buttons.cancel')){
    if (!setUser($data)) return;
    try {delMessage();} catch(Throwable $ignored){}
    sendMessage(\achbotMessage('plans.administration.edit_custom_plan.message.031fdf541a'),$cancelKey, 'HTML');exit;
}
if(isset($update->message->text) && !isset($update->callback_query) && preg_match('/^wizwizplanrial(\d+)/',$userInfo['step'], $match) && achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'plans')&& $text != \achbotMessage('legacy_buttons.cancel')){
    if(is_numeric($text)){
        $closed=\AchBot\Telegram\Conversation\transitionEditor($connection,(int)$from_id,$userInfo,'none',null,
            static function(mysqli $db) use($from_id,$admin,$match,$text): void {
                if (!achbotAdminCanLocked($db,(int)$from_id,(int)$admin,'plans') ||
                    !achbotProviderRow($db,'SELECT id FROM server_plans WHERE id=? FOR UPDATE','i',[(int)$match[1]]))
                    throw new DomainException('plan_editor_target_changed');
                $q=achbotProviderStatement($db,'UPDATE server_plans SET price=? WHERE id=?','ii',[(int)$text,(int)$match[1]]);$q->close();
            });
        if (!$closed) return;

        sendMessage(\achbotMessage('plans.administration.edit_custom_plan.message.43afd491f6'), $removeKeyboard, 'HTML');


        $keys = getPlanDetailsKeys($match[1]);
        if($keys == null){
            alert(\achbotMessage('plans.administration.edit_custom_plan.alert.756c3005d3'));
            exit;
        }else sendMessage(\achbotMessage('plans.administration.edit_custom_plan.message.06d5c646c1'), $keys, "HTML");
    }else{
        sendMessage(\achbotMessage('plans.administration.edit_custom_plan.message.923b819fc4'), null, 'HTML');
    }
}

// ✅ تابع کمکی کیبورد لغو برای حالت "یافت نشد"
