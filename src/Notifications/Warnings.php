<?php
/** AChBot common warning/grace/deletion lifecycle for every supported provider. */
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/../../messages/loader.php';
require_once __DIR__.'/../Panel/ServiceLifecycle.php';

$time=time();$limit=100;
// Keyset traversal stays complete when warning updates remove rows from the set.
$stmt=$connection->prepare("SELECT value FROM setting WHERE type='warnLastOrderId' LIMIT 1");
$stmt->execute();$cursorRow=$stmt->get_result()->fetch_assoc();$stmt->close();
$cursor=max(0,(int)($cursorRow['value']??0));
$stmt=$connection->prepare('SELECT * FROM orders_list WHERE status=1 AND (notif=0 OR notif=-1) AND id>? ORDER BY id LIMIT ?');
$stmt->bind_param('ii',$cursor,$limit);$stmt->execute();$batch=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
foreach ($batch as $order) {
    $id=(int)$order['id'];$serverId=(int)$order['server_id'];$remark=(string)$order['remark'];
    if (!achbotSupportsOrder($connection,$id)) continue;
    $observation=achbotServiceObserve($serverId,$remark);$policy=achbotServiceLifecycle($observation,$time);
    if (!$observation['verified']) continue;
    $next=(int)$order['notif'];
    if ($next===0 && $policy['warning']!=='') {
        if ((int)$order['amount']!==0) {
            $reason=$policy['warning']==='time'?
                achbotMessage('notifications.warnings.send.1e5349d9b2'):
                achbotMessage('notifications.warnings.send.0458ad0612');
            $msg=achbotMessage('notifications.warnings.msg.804056ecc4',['remark'=>htmlspecialchars($remark,ENT_QUOTES,'UTF-8'),'send'=>$reason]);
            try {
            $response=sendMessage($msg,json_encode(['inline_keyboard'=>[[
                ['text'=>$buttonValues['my_subscriptions'],'callback_data'=>'mySubscriptions'],
                ['text'=>$buttonValues['sharj'],'callback_data'=>'increaseMyWallet']
            ]]]),'HTML',(int)$order['userid']);
            } catch (Throwable $error) {$response=null;}
            // Failed notifications remain eligible; they are not marked delivered.
            if (!is_object($response) || ($response->ok??false)!==true) continue;
        }
        $next=-1;
    }
    // Disabled/on-hold states do not start an expiry deletion timer.
    if ($policy['ended']) $next=$time+10*86400;
    if ($next!==(int)$order['notif']) {
        $old=(int)$order['notif'];$cycle=(int)$order['expire_date'];
        $stmt=$connection->prepare('UPDATE orders_list SET notif=? WHERE id=? AND status=1 AND notif=? AND expire_date=?');
        $stmt->bind_param('iiii',$next,$id,$old,$cycle);$stmt->execute();$stmt->close();
    }
}
$nextCursor=count($batch)===$limit?(int)$batch[count($batch)-1]['id']:0;
$value=(string)$nextCursor;
if ($cursorRow) $stmt=$connection->prepare("UPDATE setting SET value=? WHERE type='warnLastOrderId'");
else $stmt=$connection->prepare("INSERT INTO setting(type,value) VALUES('warnLastOrderId',?)");
$stmt->bind_param('s',$value);$stmt->execute();$stmt->close();

$stmt=$connection->prepare('SELECT * FROM orders_list WHERE status=1 AND notif>0 AND notif<? ORDER BY notif,id LIMIT 50');
$stmt->bind_param('i',$time);$stmt->execute();$deletions=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
foreach ($deletions as $order) {
    $id=(int)$order['id'];$serverId=(int)$order['server_id'];$remark=(string)$order['remark'];
    if (!achbotSupportsOrder($connection,$id)) continue;
    try {$target=achbotProviderDeletionTarget($connection,$serverId,$remark);}
    catch (Throwable $error) {continue;}
    $observation=achbotServiceObserve($serverId,$remark);
    if (!$observation['verified']) continue;
    $policy=achbotServiceLifecycle($observation,$time);
    if (!$policy['ended']) {
        $old=(int)$order['notif'];$cycle=(int)$order['expire_date'];
        $stmt=$connection->prepare('UPDATE orders_list SET notif=0 WHERE id=? AND status=1 AND notif=? AND expire_date=?');
        $stmt->bind_param('iii',$id,$old,$cycle);$stmt->execute();$stmt->close();
        continue;
    }
    // Existing durable fence blocks renewal/payment effects. Bind it to the
    // observed local cycle, then read again before the destructive provider call.
    if (!achbotProviderDeletionTargetMatches($connection,$serverId,$remark,$target) ||
        !achbotAutoRenewPrepareDeletion($connection,$id,$order)) continue;
    $latest=achbotServiceObserve($serverId,$remark);
    if (!achbotServiceLifecycle($latest,time())['ended']) {
        error_log('achbot_warning_delete_observation_changed order_id='.$id);
        continue; // Retain the fence for operator reconciliation; never guess a rollback.
    }
    if (!achbotProviderDeletionTargetMatches($connection,$serverId,$remark,$target)) {
        error_log('achbot_warning_delete_target_changed order_id='.$id);continue;
    }
    try {$result=achbotProviderDelete($serverId,$remark,$target);}
    catch (Throwable $error) {
        error_log('achbot_warning_remote_delete_deferred order_id='.$id);continue;
    }
    if (!is_object($result) || ($result->success??false)!==true ||
        !achbotAutoRenewFinalizeDeletion($connection,$id,$serverId,false)) {
        error_log('achbot_warning_local_delete_deferred order_id='.$id);continue;
    }
    $msg=achbotMessage('notifications.warnings.msg.c7907b6342',['remark'=>htmlspecialchars($remark,ENT_QUOTES,'UTF-8')]);
    sendMessage($msg,json_encode(['inline_keyboard'=>[[[
        'text'=>$buttonValues['back_to_main'],'callback_data'=>'mainMenu'
    ]]]]),'HTML',(int)$order['userid']);
    $channel=$botState['rewardChannel']??'';
    if ($channel!=='') sendMessage(achbotMessage('notifications.warnings.channel_message.5e59a4a33e',[
        'from_id'=>(int)$order['userid'],'remark'=>htmlspecialchars($remark,ENT_QUOTES,'UTF-8')]),null,'HTML',$channel);
}
