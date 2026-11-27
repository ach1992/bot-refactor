<?php
/** AChBot V6: exact linked legacy account deletion branches. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG

require_once __DIR__ . '/../Finance/ReceiptHelpers.php';
require_once __DIR__.'/AccountDetails.php';
require_once __DIR__.'/../Panel/ServiceLifecycle.php';
require_once __DIR__.'/PhysicalService.php';
require_once __DIR__.'/../Presentation/Text.php';

if (preg_match('/^(?:delUserConfig|yesDeleteUserConfig)/',(string)$data) &&
    !\AchBot\Subscription\AccountDetails\canReadAdminOrderDetails()) {
    alert(\achbotMessage('subscription.account_deletion.alert.a370755120'),true);
    return;
}



/**
 * Only an explicitly resumable PasarGuard interruption may invite a second
 * confirmation. Protected state, invalid authority and fenced
 * unknown DELETE outcome must retain their existing review/support route.
 */
function achbotSubscriptionDeleteRetryable(string $reason): bool {
    return in_array($reason,[
        'physical_operation_busy','pg_timeout','pg_transport_unavailable',
        'pg_vendor_unavailable','pg_rate_limited',
        'pg_delete_absence_unconfirmed','pg_mutation_intent_unconfirmed',
        'pg_delete_recovery_native_finality_deferred'
    ],true);
}

/**
 * Both owner and staff confirmations use one physical account lease and one
 * local finalizer. A second confirmation resumes a PINNED originally claimed
 * DELETE, never a new purchase, payment, credential or free-standing DELETE.
 */
function achbotSubscriptionDeleteConfirmed(mysqli $db,array $original,bool $ownerView,int $actor=0): string {
    $orderId=(int)$original['id'];$serverId=(int)$original['server_id'];
    $remark=(string)$original['remark'];$lease=null;$stage='preflight';$provider=null;
    try {
        require_once __DIR__.'/../Finance/OperationalReports.php';
        achbotOperationalReportRequireReady($db);
        $provider=achbotProviderType($db,$serverId);
        $lease=achbotPhysicalLockName($db,$original);
        achbotPhysicalAcquireLock($db,$lease);
        $s=$db->prepare('SELECT * FROM orders_list WHERE id=?');
        $s->bind_param('i',$orderId);$s->execute();$fresh=$s->get_result()->fetch_assoc();$s->close();
        if (!$fresh || (int)$fresh['userid']!==(int)$original['userid'] ||
            (int)$fresh['server_id']!==$serverId || $fresh['remark']!==$remark ||
            achbotPhysicalLockName($db,$fresh)!==$lease)
            return 'blocked';
        if (achbotProviderType($db,$serverId)!==$provider) return 'review';
        if ((int)$fresh['status']===1) {
            $target=achbotProviderDeletionTarget($db,$serverId,$remark);
            if (!achbotProviderActionEligible($db,$serverId,$remark,'delete',$target)) {
                error_log('achbot_subscription_delete_preflight_deferred order_id='.$orderId.
                    ' server_id='.$serverId);
                return 'review';
            }
            if (!achbotProviderDeletionTargetMatches($db,$serverId,$remark,$target))
                return 'blocked';
            $claim=static function(mysqli $db,array $lockedOrder) use ($target): void {
                achbotPgClaimOriginalDelete($db,$lockedOrder,$target,true);
            };
            if (!achbotAutoRenewPrepareDeletion($db,$orderId,null,$claim))
                return 'review';
            $stage='remote';
            $result=achbotPgResumeDelete($db,$orderId,$serverId,$remark);
        } elseif ((int)$fresh['status']===0 && $fresh['autoRenew']==='off' &&
            $provider==='pasarguard') {
            $stage='recovery';
            $result=achbotPgResumeDelete($db,$orderId,$serverId,$remark);
        } else return 'blocked';
        if (!is_object($result) || ($result->success??false)!==true) {
            $reason=is_object($result)?(string)($result->msg??$result->reason??''):'';
            return $provider==='pasarguard' && achbotSubscriptionDeleteRetryable($reason)?
                'retry':'review';
        }
        $stage='local';
        $restoreCapacity=$result->delete_restore_capacity??null;
        if (!is_bool($restoreCapacity)) return 'review';
        if (!achbotAutoRenewFinalizeDeletion($db,$orderId,$serverId,$restoreCapacity,$ownerView?'customer':'admin',$ownerView?0:$actor))
            return $provider==='pasarguard'?'local':'review';
        return 'complete';
    } catch (Throwable $error) {
        $code=(string)$error->getMessage();
        if (preg_match('/^[a-z][a-z0-9_]{2,90}$/D',$code)!==1)
            $code='delete_internal';
        error_log('achbot_subscription_delete_deferred order_id='.$orderId.
            ' server_id='.$serverId.' stage='.$stage.' code='.$code.
            ' class='.get_class($error));
        return $provider==='pasarguard' &&
            achbotSubscriptionDeleteRetryable($code)?'retry':'review';
    } finally {
        if ($lease!==null) achbotPhysicalReleaseLock($db,$lease);
    }
}

if(preg_match('/^deleteMyConfig(\d+)/', $data, $match)){
    $oid = $match[1];
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    // The preview callback is user-controlled: do not expose another account.
    if (!$order || (int)$order['userid']!==(int)$from_id) {
        alert(\achbotMessage('subscription.account_deletion.alert.a370755120'), true);
        exit;
    }
    $remark = $order['remark'];

    editText($message_id,
        \achbotMessage('subscription.account_deletion.message.acb79596be', ['remark' => $remark]) . "\n\n" . \achbotMessage('subscription.account_deletion.shared_effect_notice'),
        json_encode([
            'inline_keyboard' => [
                [['text'=>\achbotMessage('labels.confirm_yes'),'callback_data'=>"yesDeleteConfig" . $match[1]],['text'=>\achbotMessage('subscription.account_deletion.button.5f5c4c72bd'),'callback_data'=>"noDontDelete"]]
            ]
        ]),
        "HTML"
    );
}

elseif($data=="noDontDelete"){
    editText($message_id, \achbotMessage('subscription.account_deletion.no_dont_delete.message.4ca0ffd711'),json_encode([
        'inline_keyboard' => [
            [['text'=>\achbotMessage('legacy_buttons.back_to_main'),'callback_data'=>"mainMenu"]]
            ]
    ]));
}
elseif(preg_match('/^yesDeleteConfig(\d+)/',$data,$match)){
    $oid = $match[1];
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$order || (int)$order['userid']!==(int)$from_id) {
        alert(\achbotMessage('subscription.account_deletion.alert.a370755120'), true);
        exit;
    }
    $server_id = $order['server_id'];
    $remark = $order['remark'];
    $fileid = $order['fileid'];
    
    $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id` = ?");
    $stmt->bind_param('i', $server_id);
    $stmt->execute();
    $serverConfig = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $serverType = $serverConfig['type']??'';

	
    if (!achbotSupportsPanel($serverType)) {
        alert(\achbotMessage('subscription.account_deletion.no_dont_delete.alert.739703d8ae'), true);
        exit;
    }
    $deleteOutcome=achbotSubscriptionDeleteConfirmed($connection,$order,true);
    if ($deleteOutcome!=='complete') {
        $key=match($deleteOutcome) {
            'retry'=>'subscription.account_deletion.no_dont_delete.alert.9347ccc8b0',
            'local'=>'subscription.account_deletion.no_dont_delete.alert.e2cbeb044b',
            default=>'subscription.account_deletion.no_dont_delete.alert.fc1b571950'
        };
        alert(\achbotMessage($key),true);
        exit;
    }

    achbotOperationalReportDispatch($connection,'delete:'.(int)$oid);
    editText($message_id, \achbotMessageHtml('subscription.account_deletion.no_dont_delete.message.ab50b51b1f', ['remark' => $remark]),json_encode([
            'inline_keyboard' => [
                [['text'=>\achbotMessage('legacy_buttons.back_to_main'),'callback_data'=>"mainMenu"]]
                ]
        ]));
    exit();
}
elseif(preg_match('/^delUserConfig(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $oid = $match[1];
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$order) {alert(\achbotMessage('subscription.account_deletion.no_dont_delete.alert.bd8e953385'),true);exit;}
    $remark = $order['remark'];

    editText($message_id, \achbotMessageHtml('subscription.account_deletion.no_dont_delete.message.2ddfbc22e4', ['remark' => $remark]) . "\n\n" . \achbotMessage('subscription.account_deletion.shared_effect_notice'),json_encode([
        'inline_keyboard' => [
            [['text'=>\achbotMessage('labels.confirm_yes'),'callback_data'=>"yesDeleteUserConfig" . $match[1]],['text'=>\achbotMessage('subscription.account_deletion.button.5f5c4c72bd'),'callback_data'=>"noDontDelete"]]
            ]
    ]));
}
elseif(preg_match('/^yesDeleteUserConfig(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $oid = $match[1];
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$order) { alert(\achbotMessage('subscription.account_deletion.no_dont_delete.alert.bd8e953385'), true); exit; }
    $userId = $order['userid'];
    $server_id = $order['server_id'];
    $remark = $order['remark'];
    
    $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id` = ?");
    $stmt->bind_param('i', $server_id);
    $stmt->execute();
    $serverConfig = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $serverType = $serverConfig['type']??'';
    
	
    if (!achbotSupportsPanel($serverType)) {
        alert(\achbotMessage('subscription.account_deletion.no_dont_delete.alert.739703d8ae'), true);
        exit;
    }
    $deleteOutcome=achbotSubscriptionDeleteConfirmed($connection,$order,false,(int)$from_id);
    if ($deleteOutcome!=='complete') {
        $key=match($deleteOutcome) {
            'retry'=>'subscription.account_deletion.no_dont_delete.alert.9347ccc8b0',
            'local'=>'subscription.account_deletion.no_dont_delete.alert.e2cbeb044b',
            default=>'subscription.account_deletion.no_dont_delete.alert.1186ae9204'
        };
        alert(\achbotMessage($key),true);
        exit;
    }

    achbotOperationalReportDispatch($connection,'delete:'.(int)$oid);
    editText($message_id, \achbotMessageHtml('subscription.account_deletion.no_dont_delete.message.ab50b51b1f', ['remark' => $remark]),json_encode([
            'inline_keyboard' => [
                [['text'=>\achbotMessage('legacy_buttons.back_to_main'),'callback_data'=>"mainMenu"]]
                ]
	]));
    exit();
}
