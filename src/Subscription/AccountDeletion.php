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
 * Both owner and staff confirmations use one physical account lease and one
 * local finalizer. A second confirmation resumes a PINNED dispatched DELETE,
 * never a new purchase, payment, credential or free-standing DELETE.
 */
function achbotSubscriptionDeleteConfirmed(mysqli $db,array $original,bool $ownerView): string {
    $orderId=(int)$original['id'];$serverId=(int)$original['server_id'];
    $remark=(string)$original['remark'];$lease=null;$stage='preflight';
    try {
        $lease=achbotPhysicalLockName($db,$original);
        achbotPhysicalAcquireLock($db,$lease);
        $s=$db->prepare('SELECT * FROM orders_list WHERE id=?');
        $s->bind_param('i',$orderId);$s->execute();$fresh=$s->get_result()->fetch_assoc();$s->close();
        if (!$fresh || (int)$fresh['userid']!==(int)$original['userid'] ||
            (int)$fresh['server_id']!==$serverId || $fresh['remark']!==$remark ||
            (int)$fresh['inbound_id']!==0 ||
            achbotPhysicalLockName($db,$fresh)!==$lease)
            return 'blocked';
        $provider=achbotProviderType($db,$serverId);
        if ((int)$fresh['status']===1) {
            $target=achbotProviderDeletionTarget($db,$serverId,$remark);
            if (!achbotProviderActionEligible($db,$serverId,$remark,'delete',$target)) {
                error_log('achbot_subscription_delete_preflight_deferred order_id='.$orderId.
                    ' server_id='.$serverId);
                return 'retry';
            }
            // Native PasarGuard preflight already verified the same account.
            if ($ownerView && $provider==='marzban') {
                $remote=achbotProviderUser($serverId,$remark,false);
                if (!achbotServiceObservation($remote,$remark)['verified'])
                    return 'retry';
            }
            if (!achbotProviderDeletionTargetMatches($db,$serverId,$remark,$target))
                return 'blocked';
            $claim=null;
            if ($provider==='pasarguard') {
                $claim=static function(mysqli $db,array $lockedOrder) use (
                    $orderId,$serverId,$remark,$target): void {
                    $ctx=achbotPgOrderContext($db,$orderId,true);
                    if ((int)$lockedOrder['server_id']!==$serverId ||
                        $lockedOrder['remark']!==$remark ||
                        achbotPgDeletionContextTarget($ctx,$serverId,$remark)!==$target)
                        throw new RuntimeException('pg_delete_original_target_changed');
                    // A shared historical physical account may have another
                    // legitimate local reference. Refuse BEFORE fencing this
                    // order or claiming the Native DELETE, not on its retry.
                    $effectId=(int)$ctx['account']['effect_id'];
                    $peers=$db->prepare('SELECT id FROM orders_list WHERE provider_effect_id=? AND id<>? LIMIT 1 FOR UPDATE');
                    $peers->bind_param('ii',$effectId,$orderId);
                    $peers->execute();$other=$peers->get_result()->fetch_assoc();$peers->close();
                    if ($other) throw new RuntimeException('pg_delete_shared_account_reference');
                    $key='delete:'.(int)$ctx['account']['id'];
                    achbotPgBeginMutation($db,$ctx,$key,'delete',['phase'=>'claimed'],true);
                };
            }
            if (!achbotAutoRenewPrepareDeletion($db,$orderId,null,$claim))
                return 'blocked';
            $stage='remote';
            $result=$provider==='pasarguard'?
                achbotPgResumeDelete($db,$orderId,$serverId,$remark):
                achbotProviderDelete($serverId,$remark,$target);
        } elseif ((int)$fresh['status']===0 && $fresh['autoRenew']==='off' &&
            $provider==='pasarguard') {
            $stage='recovery';
            $result=achbotPgResumeDelete($db,$orderId,$serverId,$remark);
        } else return 'blocked';
        if (!is_object($result) || ($result->success??false)!==true) return 'retry';
        $stage='local';
        if (!achbotAutoRenewFinalizeDeletion($db,$orderId,$serverId,true)) return 'local';
        return 'complete';
    } catch (Throwable $error) {
        $code=(string)$error->getMessage();
        if (preg_match('/^[a-z][a-z0-9_]{2,90}$/D',$code)!==1)
            $code='delete_internal';
        error_log('achbot_subscription_delete_deferred order_id='.$orderId.
            ' server_id='.$serverId.' stage='.$stage.' code='.$code.
            ' class='.get_class($error));
        return $stage==='local'?'local':'retry';
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
                [['text'=>\achbotMessage('subscription.account_deletion.button.13159f8c74'),'callback_data'=>"yesDeleteConfig" . $match[1]],['text'=>\achbotMessage('subscription.account_deletion.button.5f5c4c72bd'),'callback_data'=>"noDontDelete"]]
            ]
        ]),
        "HTML"
    );
}

elseif($data=="noDontDelete"){
    editText($message_id, \achbotMessage('subscription.account_deletion.no_dont_delete.message.4ca0ffd711'),json_encode([
        'inline_keyboard' => [
            [['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]]
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
    $inbound_id = $order['inbound_id'];
    $server_id = $order['server_id'];
    $remark = $order['remark'];
    $uuid = $order['uuid']??"0";
    $fileid = $order['fileid'];
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ?");
    $stmt->bind_param("i", $fileid);
    $stmt->execute();
    $planDetail = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $volumeState=\AchBot\Presentation\Text\serviceAllowanceState($planDetail['volume']??null,true);
    $daysState=\AchBot\Presentation\Text\serviceAllowanceState($planDetail['days']??null,true);
    $volume=match($volumeState['state']) {
        'finite'=>$volumeState['display'].\achbotMessage('subscription.presentation.format_traffic.text.2acc4ef207'),
        'unlimited'=>\achbotMessage('subscription.account_deletion.no_dont_delete.text.5e6ee5eaa1'),
        default=>\achbotMessage('payment.pre_payment.text.f07a81a062')
    };
    $days=match($daysState['state']) {
        'finite'=>$daysState['display'].\achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_page.text.69ff4a27e5'),
        'unlimited'=>\achbotMessage('subscription.account_deletion.no_dont_delete.text.5e6ee5eaa1'),
        default=>\achbotMessage('payment.pre_payment.text.f07a81a062')
    };
	
    $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id` = ?");
    $stmt->bind_param('i', $server_id);
    $stmt->execute();
    $serverConfig = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $serverType = $serverConfig['type']??'';

	
    if (!achbotSupportsPanel($serverType) || (int)$inbound_id !== 0) {
        alert(\achbotMessage('subscription.account_deletion.no_dont_delete.alert.739703d8ae'), true);
        exit;
    }
    $deleteOutcome=achbotSubscriptionDeleteConfirmed($connection,$order,true);
    if ($deleteOutcome!=='complete') {
        $key=match($deleteOutcome) {
            'blocked'=>'subscription.account_deletion.no_dont_delete.alert.fc1b571950',
            'local'=>'subscription.account_deletion.no_dont_delete.alert.e2cbeb044b',
            default=>'subscription.account_deletion.no_dont_delete.alert.9347ccc8b0'
        };
        alert(\achbotMessage($key),true);
        exit;
    }

    editText($message_id, \achbotMessage('subscription.account_deletion.no_dont_delete.message.ab50b51b1f', ['remark' => $remark]),json_encode([
            'inline_keyboard' => [
                [['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]]
                ]
        ]));
        
$reportSender=$update->callback_query->from??null;
$reportFirstName=is_string($reportSender->first_name??null)?$reportSender->first_name:
    html_entity_decode((string)$first_name,ENT_QUOTES|ENT_HTML5,'UTF-8');
$reportUsername=is_string($reportSender->username??null)?$reportSender->username:
    html_entity_decode((string)$username,ENT_QUOTES|ENT_HTML5,'UTF-8');
$channelMessage = \achbotMessageHtml('subscription.account_deletion.report', ['from_id' => $from_id, 'first_name' => $reportFirstName, 'username' => $reportUsername, 'remark' => $remark, 'volumeDisplay' => $volume, 'daysDisplay' => $days]);

// ارسال پیام به ادمین خاص
achbotNotifyAdmins('sendMessage', ['text'=>$channelMessage, 'reply_markup'=>null, 'parse_mode'=>'HTML']);
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

    editText($message_id, \achbotMessage('subscription.account_deletion.no_dont_delete.message.2ddfbc22e4', ['remark' => $remark]) . "\n\n" . \achbotMessage('subscription.account_deletion.shared_effect_notice'),json_encode([
        'inline_keyboard' => [
            [['text'=>\achbotMessage('subscription.account_deletion.button.13159f8c74'),'callback_data'=>"yesDeleteUserConfig" . $match[1]],['text'=>\achbotMessage('subscription.account_deletion.button.5f5c4c72bd'),'callback_data'=>"noDontDelete"]]
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
    $inbound_id = $order['inbound_id'];
    $server_id = $order['server_id'];
    $remark = $order['remark'];
    $uuid = $order['uuid']??"0";
    
    $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id` = ?");
    $stmt->bind_param('i', $server_id);
    $stmt->execute();
    $serverConfig = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $serverType = $serverConfig['type']??'';
    
	
    if (!achbotSupportsPanel($serverType) || (int)$inbound_id !== 0) {
        alert(\achbotMessage('subscription.account_deletion.no_dont_delete.alert.739703d8ae'), true);
        exit;
    }
    $deleteOutcome=achbotSubscriptionDeleteConfirmed($connection,$order,false);
    if ($deleteOutcome!=='complete') {
        $key=match($deleteOutcome) {
            'blocked'=>'subscription.account_deletion.no_dont_delete.alert.1186ae9204',
            'local'=>'subscription.account_deletion.no_dont_delete.alert.e2cbeb044b',
            default=>'subscription.account_deletion.no_dont_delete.alert.3c93240b6e'
        };
        alert(\achbotMessage($key),true);
        exit;
    }

    editText($message_id, \achbotMessage('subscription.account_deletion.no_dont_delete.message.ab50b51b1f', ['remark' => $remark]),json_encode([
            'inline_keyboard' => [
                [['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]]
                ]
	]));
	// ارسال پیام به ادمین
	$adminMessage = \achbotMessage('subscription.account_deletion.no_dont_delete.admin_message.53224aee55', ['userId' => $userId, 'remark' => $remark]);
	sendMessage($adminMessage, null, "html", $admin);
	sendToAllAdmins('sendMessage', ['text' => $adminMessage,'parse_mode' => "html"]);
        
    exit();
}
