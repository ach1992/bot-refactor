<?php
/** AChBot V7: immutable V6.1 purchase or plan route. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG

/** Shared add-on catalogue: no provider-specific plan or pricing branches. */
function achbotIntervalPrice($raw): ?int {
    if (!is_string($raw) || preg_match('/^(?:0|[1-9][0-9]{0,9})$/D',trim($raw))!==1)
        return null;
    $value=(int)trim($raw);
    return $value<=2147483647?$value:null;
}

function achbotIntervalFrame($update,int $actor,string $callback,?string $text): bool {
    if (!is_object($update) || !is_int($update->update_id??null) || $update->update_id<=0) return false;
    $keys=array_keys(get_object_vars($update));sort($keys);
    $callbackMode=$callback!=='';
    if ($keys!==($callbackMode?['callback_query','update_id']:['message','update_id'])) return false;
    $q=$callbackMode?($update->callback_query??null):null;
    $m=$q?($q->message??null):($update->message??null);$sender=$q?($q->from??null):($m->from??null);
    return is_object($m) && is_object($sender) && is_int($sender->id??null) &&
        $sender->id===$actor && ($sender->is_bot??false)!==true &&
        ($m->chat->type??null)==='private' && is_int($m->chat->id??null) && $m->chat->id===$actor &&
        is_int($m->message_id??null) && $m->message_id>0 &&
        ($callbackMode?is_object($q) && ($q->data??null)===$callback:is_string($text) && ($m->text??null)===$text);
}

// Persisted permissions decide whether an old admin keyboard/editor may write.
$achbotIntervalOwned=preg_match('/^(?:dayPlanSettings|volumePlanSettings|addNewDayPlan|addNewVolumePlan|deleteDayPlan[1-9][0-9]*|deleteVolumePlan[1-9][0-9]*|changeDayPlan(?:Price|Day)[1-9][0-9]*|changeVolumePlan(?:Price|Volume)[1-9][0-9]*)$/D',(string)($data??'')) ||
    preg_match('/^(?:addNewDayPlan|addNewVolumePlan|changeDayPlan(?:Price|Day)[1-9][0-9]*|changeVolumePlan(?:Price|Volume)[1-9][0-9]*)$/D',(string)($userInfo['step']??''));
$achbotIntervalMessage=(string)($data??'')==='';
if ($achbotIntervalOwned) {
    if (!achbotIntervalFrame($update??null,(int)$from_id,(string)($data??''),is_string($text??null)?$text:null)) return;
    $achbotIntervalActor=(int)($from_id??0);
    $achbotIntervalRole=$connection->prepare('SELECT isAdmin,step FROM users WHERE userid=? LIMIT 2');
    $achbotIntervalRole->bind_param('i',$achbotIntervalActor);$achbotIntervalRole->execute();
    $achbotIntervalActors=$achbotIntervalRole->get_result()->fetch_all(MYSQLI_ASSOC);$achbotIntervalRole->close();
    if (count($achbotIntervalActors)!==1 || $achbotIntervalActors[0]['step']==='banned' ||
        ($achbotIntervalActor!==(int)$admin && (int)$achbotIntervalActors[0]['isAdmin']!==1)) {
        alert(\achbotMessage('subscription.manual_accounts.alert.03aea25611'));return;
    }
    $achbotIntervalEditor=preg_match('/^(?:addNewDayPlan|addNewVolumePlan|changeDayPlan(?:Price|Day)[1-9][0-9]*|changeVolumePlan(?:Price|Volume)[1-9][0-9]*)$/D',(string)($userInfo['step']??''));
    if ($achbotIntervalEditor && $achbotIntervalActors[0]['step']!==($userInfo['step']??null)) return;
    if ($achbotIntervalEditor && $achbotIntervalMessage && $text===($buttonValues['cancel']??null)) {
        try {
            require_once __DIR__.'/../Subscription/GiftBatch.php';
            achbotGiftTelegramSource($update,$from_id,$chat_id,$message_id,$text);
            if (!$connection->begin_transaction()) throw new RuntimeException('interval_cancel_begin');
            $q=$connection->prepare('SELECT step,temp,isAdmin FROM users WHERE userid=? FOR UPDATE');
            $q->bind_param('i',$achbotIntervalActor);$q->execute();$current=$q->get_result()->fetch_assoc();$q->close();
            if (!$current || $current['step']!==$userInfo['step'] || $current['temp']!==($userInfo['temp']??null) ||
                ($achbotIntervalActor!==(int)$admin && (int)$current['isAdmin']!==1)) {
                $connection->rollback();return;
            }
            $q=$connection->prepare("UPDATE users SET step='none',temp=NULL WHERE userid=?");
            $q->bind_param('i',$achbotIntervalActor);$q->execute();$q->close();
            if (!$connection->commit()) throw new RuntimeException('interval_cancel_unknown');
            $kind=str_contains($userInfo['step'],'Volume')?'volume':'day';
            $userInfo['step']='none';$userInfo['temp']=null;
            sendMessage(\achbotMessage('admin_settings.management.canceled'),$removeKeyboard);
            achbotIntervalShow($connection,$kind);return;
        } catch (Throwable $error) {
            try {$connection->rollback();} catch (Throwable $ignored) {}
            error_log('achbot_interval_cancel_deferred');return;
        }
    }

}





/** Serialize add-on writes with a fresh admin and the exact current editor. */
function achbotIntervalWrite(mysqli $db,string $sql,string $types,array $params,bool $clear=true,?string $next=null): bool {
    global $from_id,$admin,$userInfo,$update;
    $actor=(int)$from_id;$GLOBALS['achbotIntervalWriteUncertain']=false;
    try {
        if (!$db->begin_transaction()) throw new RuntimeException('interval_write_begin');
        $q=$db->prepare('SELECT id,userid,isAdmin,step,temp FROM users WHERE userid=? LIMIT 2 FOR UPDATE');
        $q->bind_param('i',$actor);$q->execute();$rows=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
        if (count($rows)!==1 || $rows[0]['step']==='banned' ||
            ($actor!==(int)$admin && (int)$rows[0]['isAdmin']!==1) ||
            $rows[0]['step']!==($userInfo['step']??null) || $rows[0]['temp']!==($userInfo['temp']??null)) {
            $db->rollback();return false;
        }
        $stored=json_decode((string)($rows[0]['temp']??''),true);
        if (is_array($stored) && ($stored['kind']??null)==='addon' &&
            (!is_int($stored['source']??null) || ($update->update_id??0)<=$stored['source'])) {
            $db->rollback();return false;
        }
        $step=$rows[0]['step'];
        if ($step!=='none' && !preg_match('/^(?:addNewDayPlan|addNewVolumePlan|changeDayPlan(?:Price|Day)[1-9][0-9]*|changeVolumePlan(?:Price|Volume)[1-9][0-9]*)$/D',$step)) {
            $db->rollback();return false;
        }
        if ($sql!=='') {
            $q=$db->prepare($sql);
            if (!$q || !$q->bind_param($types,...$params) || !$q->execute()) throw new RuntimeException('interval_write_failed');
            $q->close();
        }
        $nextStep=$next??'none';$id=(int)$rows[0]['id'];
        if ($clear || $next!==null) {
            $nextTemp=$next!==null?json_encode(['version'=>1,'kind'=>'addon','source'=>$update->update_id],JSON_THROW_ON_ERROR):null;
            $q=$db->prepare('UPDATE users SET step=?,temp=? WHERE id=? AND userid=?');
            $q->bind_param('ssii',$nextStep,$nextTemp,$id,$actor);$q->execute();$q->close();
        }
        try {if (!$db->commit()) throw new RuntimeException('interval_write_commit_unknown');}
        catch (Throwable $error) {throw new RuntimeException('interval_write_commit_unknown');}
        if ($clear || $next!==null) {$userInfo['step']=$nextStep;$userInfo['temp']=$nextTemp;}
        return true;
    } catch (Throwable $error) {
        try {$db->rollback();} catch (Throwable $ignored) {}
        $GLOBALS['achbotIntervalWriteUncertain']=$error instanceof RuntimeException && $error->getMessage()==='interval_write_commit_unknown';
        error_log('achbot_interval_write_deferred');return false;
    }
}

function achbotIntervalWriteNotice(): void {
    global $cancelKey;
    sendMessage(($GLOBALS['achbotIntervalWriteUncertain']??false)?
        \achbotMessage('admin_settings.management.review_required'):
        \achbotMessage('admin_settings.management.management_stale'),$cancelKey);
}

function achbotIntervalShow(mysqli $db,string $kind): void {
    global $buttonValues;
    $day=$kind==='day';$table=$day?'increase_day':'increase_plan';
    $q=$db->prepare("SELECT id,volume,price FROM $table ORDER BY id");$q->execute();$rows=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
    $keys=[];
    foreach ($rows as $row) {
        $id=(int)$row['id'];
        $keys[]=[['text'=>\achbotMessage('plans.intervals.day_plan_settings.button.2b2966a54e'),'callback_data'=>($day?'deleteDayPlan':'deleteVolumePlan').$id],
            ['text'=>number_format($row['price']).\achbotMessage('plans.administration.edit_custom_plan.text.2cc98f7f7c'),'callback_data'=>($day?'changeDayPlanPrice':'changeVolumePlanPrice').$id],
            ['text'=>(string)$row['volume'],'callback_data'=>($day?'changeDayPlanDay':'changeVolumePlanVolume').$id]];
    }
    $keys[]=[['text'=>$day?\achbotMessage('plans.intervals.day_plan_settings.button.4e0a1e41c8'):\achbotMessage('plans.intervals.volume_plan_settings.button.a3524cb835'),'callback_data'=>$day?'addNewDayPlan':'addNewVolumePlan']];
    $keys[]=[['text'=>$buttonValues['back_button'],'callback_data'=>'backplan']];
    sendMessage($day?\achbotMessage('plans.intervals.day_plan_settings.msg.0eb1f0debf'):\achbotMessage('plans.intervals.volume_plan_settings.msg.573ae79cf6'),json_encode(['inline_keyboard'=>$keys],JSON_UNESCAPED_UNICODE));
}

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
    if (!achbotIntervalWrite($connection,'','',[],true,(string)$data)) {achbotIntervalWriteNotice();return;}
    delMessage();
    sendMessage(\achbotMessage('plans.intervals.add_new_day_plan.message.3bf1033cb0'),$cancelKey);exit;
}
if($achbotIntervalMessage && $userInfo['step'] == "addNewDayPlan" and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $input = explode('-',$text);
    if (count($input)!==2) {sendMessage(\achbotMessage('admin_settings.management.interval_input'),$cancelKey);return;}
    $volume=achbotWholeServiceUnits($input[0]??null,36500);
    $price=achbotIntervalPrice($input[1]??null);
    if ($price===null && count($input)===2) {sendMessage(\achbotMessage('admin_settings.management.interval_price'),$cancelKey);return;}
    if (count($input)!==2 || $volume===null || $price===null) {sendMessage(\achbotMessage('plans.intervals.add_new_day_plan.message.a2684ce4cc'),$cancelKey);return;}
    if (!achbotIntervalWrite($connection,"INSERT INTO `increase_day` VALUES (NULL, ?, ?)",'ii',[$volume,$price],true)) {achbotIntervalWriteNotice();return;}

    sendMessage(\achbotMessage('plans.intervals.add_new_day_plan.message.9acd097b34'),$removeKeyboard);
    sendMessage($mainValues['reached_main_menu'], getAdminKeys(), 'HTML');
}
if(preg_match('/^deleteDayPlan([1-9][0-9]*)$/D',$data,$match) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if (!achbotIntervalWrite($connection,"DELETE FROM `increase_day` WHERE `id` = ?",'i',[(int)$match[1]],false)) {achbotIntervalWriteNotice();return;}
    alert(\achbotMessage('plans.intervals.add_new_day_plan.alert.b7bba0b0c0'));


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
if(preg_match('/^changeDayPlanPrice([1-9][0-9]*)$/D',$data,$match) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if (!achbotIntervalWrite($connection,'','',[],true,(string)$data)) {achbotIntervalWriteNotice();return;}
    delMessage();
    sendMessage(\achbotMessage('plans.intervals.add_new_day_plan.message.b9989bb7c2'), $cancelKey);
    exit;
}
if($achbotIntervalMessage && preg_match('/^changeDayPlanPrice([1-9][0-9]*)$/D',$userInfo['step'],$match) and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $price=achbotIntervalPrice($text);
    if($price!==null){
    if (!achbotIntervalWrite($connection,"UPDATE `increase_day` SET `price` = ? WHERE `id` = ?",'ii',[$price,(int)$match[1]],true)) {achbotIntervalWriteNotice();return;}

        sendMessage(\achbotMessage('plans.intervals.add_new_day_plan.message.d03501095b'),$removeKeyboard);

        $stmt = $connection->prepare("SELECT * FROM `increase_day`");
        $stmt->execute();
        $res = $stmt->get_result();
        $stmt->close();

        if($res->num_rows == 0){
           sendMessage( \achbotMessage('plans.intervals.day_plan_settings.message.f253e3780e'),json_encode([
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

            $keyboard[] = [['text'=>\achbotMessage('plans.intervals.day_plan_settings.button.2b2966a54e'),'callback_data'=>"deleteDayPlan" . $id],['text'=>$price,'callback_data'=>"changeDayPlanPrice" . $id],['text'=>$title,'callback_data'=>"changeDayPlanDay" . $id]];
        }
        $keyboard[] = [['text' => \achbotMessage('plans.intervals.day_plan_settings.button.4e0a1e41c8'), 'callback_data' =>"addNewDayPlan"]];
        $keyboard[] = [['text' => $buttonValues['back_button'], 'callback_data' => "backplan"]];
        $msg = \achbotMessage('plans.intervals.add_new_day_plan.msg.34a6e4ec60');

        sendMessage($msg,json_encode([
                'inline_keyboard' => $keyboard
            ]));


    }else{
        sendMessage(\achbotMessage('admin_settings.management.interval_price'),$cancelKey);
    }
}
if(preg_match('/^changeDayPlanDay([1-9][0-9]*)$/D',$data,$match) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if (!achbotIntervalWrite($connection,'','',[],true,(string)$data)) {achbotIntervalWriteNotice();return;}
    delMessage();
    sendMessage(\achbotMessage('plans.intervals.add_new_day_plan.message.395f467a7d'), $cancelKey);
    exit;
}
if($achbotIntervalMessage && preg_match('/^changeDayPlanDay([1-9][0-9]*)$/D',$userInfo['step'],$match) && ($from_id == $admin || $userInfo['isAdmin'] == true) and $text != $buttonValues['cancel']) {
    $days=achbotWholeServiceUnits($text,36500);
    if ($days===null) {sendMessage(\achbotMessage('plans.intervals.add_new_day_plan.message.a2684ce4cc'),$cancelKey);return;}
    if (!achbotIntervalWrite($connection,"UPDATE `increase_day` SET `volume` = ? WHERE `id` = ?",'ii',[$days,(int)$match[1]],true)) {achbotIntervalWriteNotice();return;}

    sendMessage(\achbotMessage('plans.intervals.add_new_day_plan.message.d03501095b'),$removeKeyboard);

    $stmt = $connection->prepare("SELECT * FROM `increase_day`");
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    if($res->num_rows == 0){
       sendMessage( \achbotMessage('plans.intervals.day_plan_settings.message.f253e3780e'),json_encode([
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

        $keyboard[] = [['text'=>\achbotMessage('plans.intervals.day_plan_settings.button.2b2966a54e'),'callback_data'=>"deleteDayPlan" . $id],['text'=>$price,'callback_data'=>"changeDayPlanPrice" . $id],['text'=>$title,'callback_data'=>"changeDayPlanDay" . $id]];
    }
    $keyboard[] = [['text' => \achbotMessage('plans.intervals.day_plan_settings.button.4e0a1e41c8'), 'callback_data' =>"addNewDayPlan"]];
    $keyboard[] = [['text' => $buttonValues['back_button'], 'callback_data' => "backplan"]];
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
    if (!achbotIntervalWrite($connection,'','',[],true,(string)$data)) {achbotIntervalWriteNotice();return;}
    delMessage();
    sendMessage(\achbotMessage('plans.intervals.add_new_volume_plan.message.d36c49c30e'),$cancelKey);
 exit;
}
if($achbotIntervalMessage && $userInfo['step'] == "addNewVolumePlan" and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $input=explode('-',$text);
    if (count($input)!==2) {sendMessage(\achbotMessage('admin_settings.management.interval_input'),$cancelKey);return;}
    $volume=achbotWholeServiceUnits($input[0]??null,achbotServiceVolumeMax());
    $price=achbotIntervalPrice($input[1]??null);
    if ($price===null && count($input)===2) {sendMessage(\achbotMessage('admin_settings.management.interval_price'),$cancelKey);return;}
    if (count($input)!==2 || $volume===null || $price===null) {sendMessage(\achbotMessage('plans.intervals.add_new_day_plan.message.a2684ce4cc'),$cancelKey);return;}
    if (!achbotIntervalWrite($connection,"INSERT INTO `increase_plan` VALUES (NULL, ? ,?)",'ii',[$volume,$price],true)) {achbotIntervalWriteNotice();return;}

    sendMessage(\achbotMessage('plans.intervals.add_new_volume_plan.message.b003cdcfab'),$removeKeyboard);
    sendMessage($mainValues['reached_main_menu'], getAdminKeys(), 'HTML');
}
if(preg_match('/^deleteVolumePlan([1-9][0-9]*)$/D',$data,$match) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if (!achbotIntervalWrite($connection,"DELETE FROM `increase_plan` WHERE `id` = ?",'i',[(int)$match[1]],false)) {achbotIntervalWriteNotice();return;}
    alert(\achbotMessage('plans.intervals.add_new_volume_plan.alert.d5009733f9'));


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
}
if(preg_match('/^changeVolumePlanPrice([1-9][0-9]*)$/D',$data,$match) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if (!achbotIntervalWrite($connection,'','',[],true,(string)$data)) {achbotIntervalWriteNotice();return;}
    delMessage();
    sendMessage(\achbotMessage('plans.intervals.add_new_day_plan.message.b9989bb7c2'), $cancelKey);
    exit;
}
if($achbotIntervalMessage && preg_match('/^changeVolumePlanPrice([1-9][0-9]*)$/D',$userInfo['step'],$match) and $text != $buttonValues['cancel'] and ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $pid=$match[1];
    $price=achbotIntervalPrice($text);
    if($price!==null){
    if (!achbotIntervalWrite($connection,"UPDATE `increase_plan` SET `price` = ? WHERE `id` = ?",'ii',[$price,(int)$pid],true)) {achbotIntervalWriteNotice();return;}
        sendMessage(\achbotMessage('plans.intervals.add_new_volume_plan.message.76731ef62b'),$removeKeyboard);

        $stmt = $connection->prepare("SELECT * FROM `increase_plan`");
        $stmt->execute();
        $plans = $stmt->get_result();
        $stmt->close();

        if($plans->num_rows == 0){
           sendMessage( \achbotMessage('plans.intervals.volume_plan_settings.message.63f1e596ef'),json_encode([
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
        $msg = \achbotMessage('plans.intervals.add_new_volume_plan.msg.9725f12c7b');

        $res = sendMessage($msg,json_encode([
                'inline_keyboard' => $keyboard
            ]));
    }else{
        sendMessage(\achbotMessage('admin_settings.management.interval_price'),$cancelKey);
    }
}
if(preg_match('/^changeVolumePlanVolume([1-9][0-9]*)$/D',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if (!achbotIntervalWrite($connection,'','',[],true,(string)$data)) {achbotIntervalWriteNotice();return;}
    delMessage();
    sendMessage(\achbotMessage('plans.intervals.add_new_volume_plan.message.149e31d927'),$cancelKey);
    exit;
}
if($achbotIntervalMessage && preg_match('/^changeVolumePlanVolume([1-9][0-9]*)$/D',$userInfo['step'], $match) and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $pid=$match[1];
    $volume=achbotWholeServiceUnits($text,achbotServiceVolumeMax());
    if ($volume===null) {sendMessage(\achbotMessage('plans.intervals.add_new_day_plan.message.a2684ce4cc'),$cancelKey);return;}
    if (!achbotIntervalWrite($connection,"UPDATE `increase_plan` SET `volume` = ? WHERE `id` = ?",'ii',[$volume,(int)$pid],true)) {achbotIntervalWriteNotice();return;}
    sendMessage(\achbotMessage('plans.intervals.add_new_day_plan.message.d03501095b'),$removeKeyboard);

    $stmt = $connection->prepare("SELECT * FROM `increase_plan`");
    $stmt->execute();
    $plans = $stmt->get_result();
    $stmt->close();

    if($plans->num_rows == 0){
       sendMessage( \achbotMessage('plans.intervals.volume_plan_settings.message.63f1e596ef'),json_encode([
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

    $res = sendMessage( $msg,json_encode([
            'inline_keyboard' => $keyboard
        ]));

}
