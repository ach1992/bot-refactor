<?php
/** AChBot common warning/grace/deletion lifecycle for every supported provider. */
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/../../messages/loader.php';
require_once __DIR__.'/../Panel/ServiceLifecycle.php';
require_once __DIR__.'/../Purchase/OrderWrites.php';

$time=time();$limit=100;
// Keyset traversal stays complete when warning updates remove rows from the set.
$stmt=$connection->prepare("SELECT value FROM setting WHERE type='warnLastOrderId' LIMIT 1");
$stmt->execute();$cursorRow=$stmt->get_result()->fetch_assoc();$stmt->close();
$cursor=max(0,(int)($cursorRow['value']??0));
$stmt=$connection->prepare('SELECT * FROM orders_list WHERE status=1 AND notif IN(0,-1,-2) AND id>? ORDER BY id LIMIT ?');
$stmt->bind_param('ii',$cursor,$limit);$stmt->execute();$batch=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
foreach ($batch as $order) {
    $id=(int)$order['id'];$serverId=(int)$order['server_id'];$remark=(string)$order['remark'];
    try {$imported=achbotOrderIsImported($connection,$order);}
    catch (Throwable $error) {
        error_log('achbot_warning_origin_unverified order_id='.$id);continue;
    }
    if (!achbotSupportsOrder($connection,$id)) continue;
    $observation=achbotServiceObserve($serverId,$remark);$policy=achbotServiceLifecycle($observation,$time);
    if (!$observation['verified']) continue;
    $next=(int)$order['notif'];
    if (in_array($next,[0,-2],true) && $policy['warning']!=='') {
        $delivered=true;
        if ((int)$order['amount']!==0 || $imported) {
            $reason=$policy['warning']==='time'?
                achbotMessage('notifications.warnings.send.1e5349d9b2'):
                achbotMessage('notifications.warnings.send.0458ad0612');
            $msg=achbotMessage('notifications.warnings.msg.804056ecc4',['remark'=>htmlspecialchars($remark,ENT_QUOTES,'UTF-8'),'send'=>$reason]);
            try {
                $response=sendMessage($msg,json_encode(['inline_keyboard'=>[[
                    ['text'=>\achbotMessage('legacy_buttons.my_subscriptions'),'callback_data'=>'mySubscriptions'],
                    ['text'=>\achbotMessage('legacy_buttons.sharj'),'callback_data'=>'increaseMyWallet']
                ]]]),'HTML',(int)$order['userid']);
            } catch (Throwable $error) {$response=null;}
            $delivered=is_object($response) && ($response->ok??false)===true;
        }
        // -2 means pending notification retry, never a delivery claim. It is
        // nonzero so the existing strict auto-renewal eligibility is retained.
        $next=$delivered?-1:-2;
    }
    // Confirmed expiry/exhaustion starts the full grace independently of
    // Telegram delivery. Disabled/on-hold states never start this timer.
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

// A deferred oldest row must not permanently hide later eligible services.
// Scan due rows fairly by identity and advance even when every row defers.
$stmt=$connection->prepare("SELECT value FROM setting WHERE type='deleteLastOrderId' LIMIT 1");
$stmt->execute();$deleteCursorRow=$stmt->get_result()->fetch_assoc();$stmt->close();
$deleteCursor=max(0,(int)($deleteCursorRow['value']??0));$deleteLimit=50;
$stmt=$connection->prepare('SELECT * FROM orders_list WHERE status=1 AND notif>0 AND notif<? AND id>? ORDER BY id LIMIT ?');
$stmt->bind_param('iii',$time,$deleteCursor,$deleteLimit);
$stmt->execute();$deletions=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
foreach ($deletions as $order) {
    $id=(int)$order['id'];$serverId=(int)$order['server_id'];$remark=(string)$order['remark'];
    if (!achbotServiceGraceValid($order)) {
        error_log('achbot_warning_delete_grace_unverified order_id='.$id);continue;
    }
    if (!achbotSupportsOrder($connection,$id)) continue;
    try {$target=achbotProviderDeletionTarget($connection,$serverId,$remark);}
    catch (Throwable $error) {continue;}
    if (!achbotProviderActionEligible($connection,$serverId,$remark,'delete',$target)) continue;
    $observation=achbotServiceObserve($serverId,$remark);
    if (!$observation['verified']) continue;
    $policy=achbotServiceLifecycle($observation,$time);
    if (!$policy['ended']) {
        $old=(int)$order['notif'];$cycle=(int)$order['expire_date'];
        $stmt=$connection->prepare('UPDATE orders_list SET notif=0 WHERE id=? AND status=1 AND notif=? AND expire_date=?');
        $stmt->bind_param('iii',$id,$old,$cycle);$stmt->execute();$stmt->close();
        continue;
    }
    // A scheduled Native DELETE and a user/staff confirmation must have one
    // outbound owner. Manual recovery already uses this physical-account lease;
    // hold the SAME lease from the original Cron fence until remote/local
    // finality, not merely through the SQL claim. Never auto-replay a timeout.
    // Marzban keeps its existing guarded Cron policy unchanged.
    $deleteLease=null;$deletionComplete=false;
    try {
        $native=achbotProviderType($connection,$serverId)==='pasarguard';
        if ($native) {
            require_once __DIR__.'/../Subscription/PhysicalService.php';
            $deleteLease=achbotPhysicalLockName($connection,$order);
            achbotPhysicalAcquireLock($connection,$deleteLease);
        }
        // Re-observe before claiming the Native account: the normal provider
        // user adapter intentionally disallows reads after it is mutating.
        // The pinned by-ID GET inside ResumeDelete performs the final ended
        // check immediately before HTTP DELETE instead.
        if ($native && !achbotServiceLifecycle(
            achbotServiceObserve($serverId,$remark),time())['ended']) continue;
        // Persist Cron's original no-restoration policy in the Native claim
        // IN THE SAME TRANSACTION as its order fence. A later explicit user
        // or staff recovery must honor this exact original decision.
        $claim=$native?static function(mysqli $db,array $lockedOrder) use ($target): void {
            achbotPgClaimOriginalDelete($db,$lockedOrder,$target,false);
        }:null;
        if (!achbotProviderDeletionTargetMatches($connection,$serverId,$remark,$target) ||
            !achbotAutoRenewPrepareDeletion($connection,$id,$order,$claim)) continue;
        if (!$native) {
            $latest=achbotServiceObserve($serverId,$remark);
            if (!achbotServiceLifecycle($latest,time())['ended']) {
                error_log('achbot_warning_delete_observation_changed order_id='.$id);
                continue; // Retain the fence; no speculative local rollback.
            }
            if (!achbotProviderDeletionTargetMatches($connection,$serverId,$remark,$target)) {
                error_log('achbot_warning_delete_target_changed order_id='.$id);continue;
            }
        }
        // Native targeting was verified before the claim and is independently
        // checked against pinned identity and credentials in ResumeDelete.
        try {$result=$native?
            achbotPgResumeDelete($connection,$id,$serverId,$remark,true):
            achbotProviderDelete($serverId,$remark,$target);}
        catch (Throwable $error) {
            error_log('achbot_warning_remote_delete_deferred order_id='.$id);continue;
        }
        if (!is_object($result) || ($result->success??false)!==true ||
            ($native && ($result->delete_restore_capacity??null)!==false) ||
            !achbotAutoRenewFinalizeDeletion($connection,$id,$serverId,false)) {
            error_log('achbot_warning_local_delete_deferred order_id='.$id);continue;
        }
        $deletionComplete=true;
    } catch (Throwable $error) {
        $reason=(string)$error->getMessage();
        if (preg_match('/^[a-z][a-z0-9_]{2,90}$/D',$reason)!==1)
            $reason='delete_internal';
        error_log('achbot_warning_delete_deferred order_id='.$id.' code='.$reason);
    } finally {
        if ($deleteLease!==null) achbotPhysicalReleaseLock($connection,$deleteLease);
    }
    if (!$deletionComplete) continue;
    $msg=achbotMessage('notifications.warnings.msg.c7907b6342',['remark'=>htmlspecialchars($remark,ENT_QUOTES,'UTF-8')]);
    sendMessage($msg,json_encode(['inline_keyboard'=>[[[
        'text'=>\achbotMessage('legacy_buttons.back_to_main'),'callback_data'=>'mainMenu'
    ]]]]),'HTML',(int)$order['userid']);
    achbotNotifyAdmins('sendMessage', ['text'=>achbotMessage('notifications.warnings.channel_message.5e59a4a33e',[
        'from_id'=>(int)$order['userid'],'remark'=>htmlspecialchars($remark,ENT_QUOTES,'UTF-8')]),'parse_mode'=>'HTML']);
}

// This is traversal metadata only: a cursor never authorizes a deletion.
// Wrap after exhaustion so deferred rows and newly due earlier IDs are revisited.
$nextDeleteCursor=count($deletions)===$deleteLimit?(int)$deletions[count($deletions)-1]['id']:0;
$value=(string)$nextDeleteCursor;
if ($deleteCursorRow) $stmt=$connection->prepare("UPDATE setting SET value=? WHERE type='deleteLastOrderId'");
else $stmt=$connection->prepare("INSERT INTO setting(type,value) VALUES('deleteLastOrderId',?)");
$stmt->bind_param('s',$value);$stmt->execute();$stmt->close();
