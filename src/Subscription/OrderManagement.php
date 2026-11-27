<?php
/** AChBot V6: original Telegram account/subscription routes; keep caller scope. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG

require_once __DIR__ . '/../Finance/ReceiptHelpers.php';
require_once __DIR__.'/AccountDetails.php';
require_once __DIR__.'/../Panel/ServiceLifecycle.php';
require_once __DIR__.'/../Presentation/Text.php';
require_once __DIR__.'/../Telegram/Conversation.php';
// Every local-record management callback is privileged. A forged old button
// or stale cached role must not disclose or mutate another customer's order.
$achbotOrderAdminRoute=is_string($data??null) &&
    ($data==='manageorderdatabase' || preg_match('/^(?:showUserSubscriptions|selectOrderByRemark_userid_|returnToPanel_|deleteOrder_|confirmDeleteOrder_|toggleOrderAgent_|orderWarningStatus_|changeExpireDate_|changeServerId_|selectServerId_|changeFileId_|selectFileId_|recoverPlanJournal_|reviewPlanCorrection_|confirmPlanCorrection_|quickUserOrderDetails_)/D',$data));
$achbotOrderAdminStep=preg_match('/^(?:awaitingOrderRemarkOnly$|awaitingUserIdSelection_|manageOrderById_|awaitingExpireChange_)/D',(string)($userInfo['step']??''));
if (($achbotOrderAdminRoute || $achbotOrderAdminStep) &&
    !\AchBot\Subscription\AccountDetails\canReadAdminOrderDetails()) {
    alert(\achbotMessage('subscription.order_management.show_order_management_panel.alert.057ec5448c'),true);
    return;
}
// Cancel is owned here and completes once, retaining the operator's context.
if ($achbotOrderAdminStep && ($text??null)===(\achbotMessage('legacy_buttons.cancel')??null)) {
    $ownedStep=(string)$userInfo['step'];
    $id=preg_match('/^(?:manageOrderById_|awaitingExpireChange_)([1-9][0-9]*)$/D',$ownedStep,$ownedMatch)?(int)$ownedMatch[1]:0;
    if (!\AchBot\Telegram\Conversation\source($update,(int)$from_id,false) ||
        !\AchBot\Telegram\Conversation\transitionEditor($connection,(int)$from_id,$userInfo)) return;
    $userInfo['step']='none';$userInfo['temp']=null;
    sendMessage(\achbotMessage('admin_settings.management.canceled'),$removeKeyboard, 'HTML');
    $order=$id>0?\AchBot\Subscription\AccountDetails\findOrder($id):null;
    if ($order) showOrderManagementPanel($order);
    else sendMessage(\achbotCurrentMessage('reached_main_menu'), getAdminKeys(), 'HTML');
    return;
}


// هندل دکمه نمایش اشتراک های کاربر
if (preg_match('/^showUserSubscriptions(\d+)/', $data, $match)) {
    $userId = $match[1];
    $subscriptionInfo = getUserInfoKeys($userId);
    
    if (isset($subscriptionInfo['subscriptionMessages']) && count($subscriptionInfo['subscriptionMessages']) > 0) {
        $subscriptionMessages = $subscriptionInfo['subscriptionMessages'];
        
        $title = \achbotMessage('subscription.order_management.title.b4f29204c1', ['userId' => $userId]);
        $keyboard = json_encode([
            'inline_keyboard' => [
                [['text' => \achbotMessage('subscription.manual_accounts.manual_account_consume_selection.button.11b0188fcb'), 'callback_data' => "searchUsersConfig"]]
            ]
        ]);

        foreach ($subscriptionMessages as $message) {
            $fullMessage = $title . "\n\n" . $message;
            sendMessage($fullMessage, $keyboard, 'HTML', $callback_query['message']['chat']['id']);
        }
    } else {
        sendMessage(\achbotMessage('subscription.order_management.message.6e1cf97901'), null, 'HTML', $callback_query['message']['chat']['id']);
    }
}

// Begin of Manage a specific order
if ($data == "manageorderdatabase" && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    if (!setUser("awaitingOrderRemarkOnly")) return;
    sendMessage(\achbotMessage('subscription.order_management.manageorderdatabase.message.e16b131103'), $cancelKey, "HTML");
    return;
}
if (isset($update->message->text) && !isset($update->callback_query) && $userInfo['step'] == "awaitingOrderRemarkOnly" && $text != \achbotMessage('legacy_buttons.cancel')) {
    $remark = trim($text);

    // بررسی کاراکترهای معتبر
    if (!preg_match('/^[a-zA-Z0-9\s\-_]+$/', $remark)) {
        sendMessage(\achbotMessage('subscription.order_management.awaiting_order_remark_only.message.a94b913d39'), $cancelKey, 'HTML');
        return;
    }

    $stmt = $connection->prepare("SELECT * FROM orders_list WHERE remark = ?");
    if (!$stmt) {
        if (!setUser()) return;
        sendMessage(\achbotMessage('subscription.order_management.awaiting_order_remark_only.message.99b2e0169a'), $removeKeyboard, 'HTML');
        return;
    }
    $stmt->bind_param("s", $remark);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();

    $count = $result->num_rows;

    if ($count == 0) {
        sendMessage(\achbotMessage('subscription.order_management.awaiting_order_remark_only.message.2ae43a0263'), $cancelKey,'HTML');
        return;
    }

    if ($count == 1) {
        $order = $result->fetch_assoc();
        if (!setUser()) return;
        \AchBot\Telegram\Conversation\removeReplyKeyboard(achbotMessage('legacy_messages.receving_information'));
        showOrderManagementPanel($order);
        return;
    }

    $keyboard = ['inline_keyboard' => []];
    $useridList = [];
    $maxResults = 10;
    $counter = 0;

    $tempRemarkId = uniqid(); // شناسه موقت برای ریمارک
    while ($row = $result->fetch_assoc()) {
        if (!in_array($row['userid'], $useridList)) {
            if ($counter >= $maxResults) {
                if (!setUser()) return;
                sendMessage(\achbotMessage('subscription.order_management.awaiting_order_remark_only.message.f8c9916b9c'), $removeKeyboard, 'HTML');
                return;
            }
            $useridList[] = $row['userid'];
            $callbackData = "selectOrderByRemark_userid_{$row['userid']}_tempId_" . $tempRemarkId;
            if (strlen($callbackData) > 64) {
                if (!setUser()) return;
                sendMessage(\achbotMessage('subscription.order_management.awaiting_order_remark_only.message.45a8b2af73'), $removeKeyboard, 'HTML');
                return;
            }
            $keyboard['inline_keyboard'][] = [['text' => "{$row['userid']}", 'callback_data' => $callbackData]];
            $counter++;
        }
    }
    $keyboard['inline_keyboard'][] = [['text' => \achbotMessage('subscription.order_management.awaiting_order_remark_only.button.76f3daea16'), 'callback_data' => "manageorderdatabase"]];

    if (!setUser("awaitingUserIdSelection_" . $tempRemarkId . "_" . $remark)) return;
    \AchBot\Telegram\Conversation\removeReplyKeyboard(achbotMessage('legacy_messages.receving_information'));
    sendMessage(\achbotMessage('subscription.order_management.awaiting_order_remark_only.message.e02c72df1d'), json_encode($keyboard), 'HTML');
    return;
}
// مدیریت انتخاب userID
if (preg_match('/^selectOrderByRemark_userid_(\d+)_tempId_(.+)/', $data, $match)) {
    $userid = intval($match[1]);
    $tempRemarkId = $match[2];

    // بازیابی ریمارک از حالت کاربر یا دیتابیس
    if (preg_match('/^awaitingUserIdSelection_' . $tempRemarkId . '_(.+)/', $userInfo['step'], $remarkMatch)) {
        $remark = $remarkMatch[1];
    } else {
        sendMessage(\achbotMessage('subscription.order_management.awaiting_order_remark_only.message.7ebcf50a27'), null, 'HTML');
        return;
    }

    $stmt = $connection->prepare("SELECT * FROM orders_list WHERE userid = ? AND remark = ? LIMIT 1");
    if (!$stmt) {
        if (!setUser()) return;
        sendMessage(\achbotMessage('subscription.order_management.awaiting_order_remark_only.message.99b2e0169a'), $removeKeyboard, 'HTML');
        return;
    }
    $stmt->bind_param("is", $userid, $remark);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();

    if ($result->num_rows == 0) {
        $keyboard = json_encode([
            'inline_keyboard' => [
                [['text' => \achbotMessage('subscription.order_management.awaiting_order_remark_only.button.76f3daea16'), 'callback_data' => "manageorderdatabase"]]
            ]
        ]);
        if (!setUser()) return;
        sendMessage(\achbotMessage('subscription.order_management.awaiting_order_remark_only.message.d11ef21837'), $keyboard, 'HTML');
        return;
    }

    $order = $result->fetch_assoc();

    if (!setUser()) return;
    showOrderManagementPanel($order,$message_id);
    return;
}

/** Warning processing and the deletion grace are lifecycle state, never an ON/OFF preference. */
function achbotOrderWarningPresentation(array $order): array {
    $value=(int)$order['notif'];
    if ($value===0) {
        $label=\achbotMessage('admin_settings.management.warning_unprocessed');
        $detail=$label;
    } elseif ($value===-1) {
        $label=\achbotMessage('admin_settings.management.warning_processed');
        $detail=$label;
    } elseif ($value===-2) {
        $label=\achbotMessage('admin_settings.management.warning_retry');
        $detail=$label;
    } elseif (achbotServiceGraceValid($order)) {
        $label=\achbotMessage('admin_settings.management.warning_grace_state');
        $detail=\achbotMessage('admin_settings.management.warning_grace',[
            'date'=>jdate('Y-m-d H:i',$value,'','Asia/Tehran','en')]);
    } else {
        $label=\achbotMessage('admin_settings.management.warning_unknown');
        $detail=$label;
    }
    return ['label'=>$label,'detail'=>$detail."\n\n".
        \achbotMessage('admin_settings.management.warning_explanation')];
}

function showOrderManagementPanel($order, $msgId = null,string $notice=''){
    if (!\AchBot\Subscription\AccountDetails\canReadAdminOrderDetails()) {
        alert(\achbotMessage('subscription.order_management.show_order_management_panel.alert.057ec5448c'),true);
        return;
    }
    $provider=achbotProviderType($GLOBALS['connection'],(int)$order['server_id']);

	
    $warning=achbotOrderWarningPresentation($order);
	$agentStatus = $order['agent_bought'] == 0 ? \achbotMessage('subscription.order_management.show_order_management_panel.text.44f1a0048c') : \achbotMessage('subscription.order_management.show_order_management_panel.text.e0b20b49a2');

    $keyboard = [
        [["text" => \achbotMessage('admin_settings.management.local_delete'), "callback_data" => "deleteOrder_{$order['id']}"]],
        [["text" => \achbotMessage('admin_settings.management.local_expiry'), "callback_data" => "changeExpireDate_{$order['id']}"]],
        [
            ["text" => \achbotMessage('subscription.order_management.show_order_management_panel.button.8824464622'), "callback_data" => "changeServerId_{$order['id']}"],
            ["text" => \achbotMessage('admin_settings.management.local_plan'), "callback_data" => "changeFileId_{$order['id']}_{$order['server_id']}"]
        ],
		[["text" => \achbotMessage('subscription.order_management.show_order_management_panel.button.72e5322e9a', ['agentStatus' => $agentStatus]), "callback_data" => "toggleOrderAgent_{$order['id']}_".((int)$order['agent_bought']===1?0:1)]],
        [["text"=>$warning['label'],"callback_data"=>"orderWarningStatus_{$order['id']}"]],
		[["text" => \achbotMessage('subscription.order_management.show_order_management_panel.button.bf169ddbf3'), "callback_data" => "quickUserOrderDetails_{$order['id']}"]],
        [["text" => \achbotMessage('subscription.order_management.show_order_management_panel.button.087a5a43b1'), "callback_data" => "managePanel"]],
    ];

    if ($provider==='pasarguard')
        array_splice($keyboard,1,0,[[['text'=>\achbotMessage('subscription.order_management.show_order_management_panel.button.198b410113'),'callback_data'=>'pgResetAccount'.(int)$order['id']]]]);

    // Offer the GET-only recovery action only for an exact originating
    // dispatched plan journal. Older installs without 017 retain their UI.
    if ($provider==='pasarguard') {
        try {
        require_once __DIR__.'/../Admin/Access.php';
        if (achbotAdminCanFor($GLOBALS['connection'],(int)($GLOBALS['from_id']??0),
            (int)($GLOBALS['admin']??0),'orders')) {
            $tables=$GLOBALS['connection']->query("SELECT TABLE_TYPE,ENGINE FROM information_schema.TABLES
                WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='service_plan_changes'");
            $table=$tables?$tables->fetch_assoc():null;
            if ($tables) $tables->free();
            if ($table && $table['TABLE_TYPE']==='BASE TABLE' &&
                strcasecmp((string)$table['ENGINE'],'InnoDB')===0) {
                $q=$GLOBALS['connection']->prepare("SELECT id FROM service_plan_changes
                    WHERE source_order_id=? AND provider='pasarguard'
                    AND state='dispatching' ORDER BY id DESC LIMIT 1");
                $orderId=(int)$order['id'];$q->bind_param('i',$orderId);$q->execute();
                $pending=$q->get_result()->fetch_assoc();$q->close();
                if ($pending) {
                    $keyboard[]=[[ 'text'=>\achbotMessage('subscription.service_plan_recovery.action'),
                        'callback_data'=>'recoverPlanJournal_'.(int)$pending['id'].'_'.$orderId ]];
                    $keyboard[]=[[ 'text'=>\achbotMessage('subscription.service_plan_correction.action'),
                        'callback_data'=>'reviewPlanCorrection_'.(int)$pending['id'].'_'.$orderId ]];
                }
            }
        }
        } catch (Throwable $error) {
            // A partial/older 017 installation must not break the ordinary
            // read-only order panel or fabricate a recovery action.
            error_log('achbot_plan_recovery_action_unavailable class='.get_class($error));
        }
    }

    $stmt=$GLOBALS['connection']->prepare('SELECT i.title AS server_title,p.title AS plan_title FROM orders_list o LEFT JOIN server_info i ON i.id=o.server_id LEFT JOIN server_plans p ON p.id=o.fileid WHERE o.id=?');
    $orderId=(int)$order['id'];$stmt->bind_param('i',$orderId);$stmt->execute();$context=$stmt->get_result()->fetch_assoc()??[];$stmt->close();
    $escape=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $msg = \achbotMessage('subscription.order_management.show_order_management_panel.msg.4292e04017', ['order_userid' => $order['userid'], 'order_remark' => $escape($order['remark'])]);
    $msg.=\achbotMessage('admin_settings.management.order_context',[
        'order_id'=>(string)$orderId,
        'server'=>$escape($context['server_title']??$order['server_id']),
        'plan'=>$escape($context['plan_title']??$order['fileid']),
        'recorded_expiry'=>\AchBot\Presentation\Text\serviceExpiryLabel(
            $order['expire_date'],static fn(int $timestamp)=>jdate('Y-m-d H:i',$timestamp,'','Asia/Tehran','en'),
            \achbotMessage('subscription.account_deletion.no_dont_delete.text.5e6ee5eaa1'),
            \achbotMessage('admin_settings.config.get_bot_report_keys.text.f07a81a062'),
            achbotSupportsAccount($provider,$order['inbound_id']))
    ]);

    if ($notice!=='') $msg.="\n\n".$notice;

    if ($msgId) {
        editText($msgId, $msg, json_encode(['inline_keyboard' => $keyboard]), "html");
    } else {
        sendMessage($msg, json_encode(['inline_keyboard' => $keyboard]), "html");
    }
}

if (preg_match('/^returnToPanel_(\\d+)/', $data, $match)) {
    $id = intval($match[1]);
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
    if ($result->num_rows > 0) {
        $order = $result->fetch_assoc();
        showOrderManagementPanel($order, $message_id);
    }
    return;
}

if (preg_match('/^deleteOrder_([1-9][0-9]*)$/D',(string)$data,$match)) {
    $id=(int)$match[1];
    $stmt=$connection->prepare('SELECT id FROM orders_list WHERE id=?');$stmt->bind_param('i',$id);$stmt->execute();$exists=$stmt->get_result()->num_rows===1;$stmt->close();
    if (!$exists) {alert(\achbotMessage('subscription.order_management.show_order_management_panel.alert.0ffc3a1ad2'),true);return;}
    editText($message_id,\achbotMessage('admin_settings.management.local_delete_confirm'),json_encode(['inline_keyboard'=>[
        [['text'=>\achbotMessage('admin_settings.management.confirm_local_delete'),'callback_data'=>'confirmDeleteOrder_'.$id]],
        [['text'=>\achbotMessage('legacy_buttons.cancel'),'callback_data'=>'returnToPanel_'.$id]]
    ]]),'HTML');
    return;
}

if (preg_match('/^confirmDeleteOrder_([1-9][0-9]*)$/D', (string)$data, $match)) {
    // Telegram callback_data is arbitrary user input, not proof of role.
    // users.isAdmin is a MariaDB TINYINT, returned as 1/"1", not true.
    // Reject any value other than the explicit delegated-admin flag.
    if ((int)$from_id!==(int)$admin &&
        (int)($userInfo['isAdmin']??0)!==1) {
        alert(\achbotMessage('subscription.order_management.show_order_management_panel.alert.c9b07798db'), true);
        return;
    }
    $id = intval($match[1]);

    // دریافت اطلاعات قبل از حذف
    $stmt = $connection->prepare("SELECT userid, remark FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();

    if ($result->num_rows == 0) {
        alert(\achbotMessage('subscription.order_management.show_order_management_panel.alert.0ffc3a1ad2'));
        return;
    }

    $row = $result->fetch_assoc();
    $userid = $row['userid'];
    $remark = $row['remark'];

    // A local-only operator deletion still needs the same durable fence.
    // A historical renewal journal MUST remain intact after order deletion.
    if (!achbotAutoRenewPrepareDeletion($connection,(int)$id)) {
        alert(\achbotMessage('subscription.order_management.show_order_management_panel.alert.abcbb638e7'), true);
        return;
    }
    $stmt = $connection->prepare("SELECT server_id FROM orders_list WHERE id=?");
    $stmt->bind_param('i',$id);
    $stmt->execute();
    $server=(int)($stmt->get_result()->fetch_assoc()['server_id']??0);
    $stmt->close();
    if (!achbotAutoRenewFinalizeDeletion($connection,(int)$id,$server,false,'local',(int)$from_id)) {
        error_log('achbot_operator_local_order_delete_deferred order_id='.(int)$id);
        alert(\achbotMessage('subscription.order_management.show_order_management_panel.alert.3781a25fa3'), true);
        return;
    }

    achbotOperationalReportDispatch($connection,'local-delete:'.$id);

    alert(\achbotMessage('subscription.order_management.show_order_management_panel.alert.1340fd2124'));
    sendMessage(\achbotMessageHtml('subscription.order_management.show_order_management_panel.message.606600ccca', ['userid' => $userid, 'remark' => $remark]), null, "html");

}

if (preg_match('/^toggleOrderAgent_([1-9][0-9]*)_([01])$/D', (string)$data, $match)) {
    if (!\AchBot\Telegram\Conversation\source($update,(int)$from_id,true) ||
        !achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'agents')) return;
    $id=(int)$match[1];$desired=(int)$match[2];
    try {
        if (!$connection->begin_transaction()) return;
        if (!achbotAdminCanLocked($connection,(int)$from_id,(int)$admin,'orders') ||
            !achbotAdminCanLocked($connection,(int)$from_id,(int)$admin,'agents'))
            throw new DomainException('order_agent_role_changed');
        $order=achbotProviderRow($connection,'SELECT * FROM orders_list WHERE id=? FOR UPDATE','i',[$id]);
        if (!$order) throw new DomainException('order_agent_target_changed');
        $q=achbotProviderStatement($connection,'UPDATE orders_list SET agent_bought=? WHERE id=?','ii',[$desired,$id]);$q->close();
        if (!$connection->commit()) throw new RuntimeException('order_agent_commit_unconfirmed');
        $order['agent_bought']=$desired;
    } catch (Throwable $e) {
        $connection->rollback();
        return;
    }
    showOrderManagementPanel($order,$message_id);
    return;
}

// This read-only action observes the current lifecycle state.
if (preg_match('/^orderWarningStatus_([1-9][0-9]*)$/D',(string)$data,$match)) {
    $id=(int)$match[1];
    $stmt=$connection->prepare('SELECT * FROM orders_list WHERE id=?');
    $stmt->bind_param('i',$id);$stmt->execute();$order=$stmt->get_result()->fetch_assoc();$stmt->close();
    if (!$order) {
        alert(\achbotMessage('subscription.order_management.show_order_management_panel.alert.0ffc3a1ad2'),true);
        return;
    }
    $warning=achbotOrderWarningPresentation($order);
    showOrderManagementPanel($order,$message_id,$warning['detail']);
    return;
}

if (preg_match('/^changeExpireDate_([1-9][0-9]*)$/D', (string)$data, $match)) {
    $id = intval($match[1]);
    if (!setUser("awaitingExpireChange_$id")) return;
    sendMessage(\achbotMessage('admin_settings.management.local_expiry_prompt'), $cancelKey, "HTML");
    return;
}

if (isset($update->message->text) && !isset($update->callback_query) && preg_match('/^awaitingExpireChange_(\\d+)$/D', $userInfo['step'], $match) && $text!=\achbotMessage('legacy_buttons.cancel')) {
    $id = intval($match[1]);
    if (!is_string($text) || preg_match('/^-?[1-9][0-9]{0,4}$/D',$text)!==1 || abs((int)$text)>achbotServiceDaysMax(0)) {
        sendMessage(\achbotMessage('admin_settings.management.local_expiry_prompt'),$cancelKey,'HTML');return;
    }
    $days=(int)$text;
    $closed=\AchBot\Telegram\Conversation\transitionEditor($connection,(int)$from_id,$userInfo,'none',null,
        static function(mysqli $db) use($from_id,$admin,$id,$days): void {
            if (!achbotAdminCanLocked($db,(int)$from_id,(int)$admin,'orders'))
                throw new DomainException('expiry_editor_role_changed');
            $q=achbotProviderStatement($db,'UPDATE orders_list SET expire_date=expire_date+(?*86400)
                WHERE id=? AND expire_date+(?*86400) BETWEEN 1 AND 2147483647','iii',[$days,$id,$days]);
            $changed=$q->affected_rows;$q->close();
            if ($changed!==1) throw new DomainException('expiry_editor_target_changed');
        });
    if (!$closed) return;
    sendMessage(\achbotMessage('subscription.order_management.show_order_management_panel.message.86c15dd0ed'), $removeKeyboard, 'HTML');
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
    $order = $result->fetch_assoc();
    showOrderManagementPanel($order);
    return;
}

// An admin may change the logical service label only within one verified
// PasarGuard connection. Cached callbacks are revalidated against current SQL state.
if (preg_match('/^changeServerId_(\d+)$/D', (string)($data ?? ''), $match)) {
    if (!($from_id == $admin || ($userInfo['isAdmin'] ?? false))) {
        alert(\achbotMessage('subscription.order_management.show_order_management_panel.alert.057ec5448c'), true);
        return;
    }
    $id = (int)$match[1];
    $stmt = $connection->prepare(
        "SELECT `server_id` FROM `orders_list` WHERE `id` = ? AND `status` = 1"
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$order || !achbotSupportsOrder($connection, $id)) {
        alert(\achbotMessage('subscription.order_management.show_order_management_panel.alert.3e801d365f'), true);
        return;
    }
    $targets = achbotProviderRelocationTargets($connection, (int)$order['server_id']);
    if (!$targets) {
        alert(\achbotMessage('subscription.order_management.show_order_management_panel.alert.7a6ebab798'), true);
        return;
    }
    $keyboard = [];
    foreach ($targets as $server) {
        $keyboard[] = [['text'=>$server['title'],
            'callback_data'=>'selectServerId_' . $id . '_' . (int)$server['id']]];
    }
    $keyboard[] = [['text'=>\achbotMessage('subscription.order_management.show_order_management_panel.button.b35a2352f5'), 'callback_data'=>'returnToPanel_' . $id]];
    sendMessage(\achbotMessage('subscription.order_management.show_order_management_panel.message.557fb66856'),
        json_encode(['inline_keyboard'=>$keyboard], JSON_UNESCAPED_UNICODE), 'HTML');
    return;
}

if (preg_match('/^selectServerId_(\d+)_(\d+)$/D', (string)($data ?? ''), $match)) {
    if (!($from_id == $admin || ($userInfo['isAdmin'] ?? false))) {
        alert(\achbotMessage('subscription.order_management.show_order_management_panel.alert.057ec5448c'), true);
        return;
    }
    $id = (int)$match[1];
    $targetId = (int)$match[2];
    $result = achbotReassignLogicalServer($connection, $id, $targetId, $from_id, true);
    if (!$result) {
        alert(\achbotMessage('subscription.order_management.show_order_management_panel.alert.c5dae93802'), true);
        return;
    }
    require_once __DIR__.'/PhysicalService.php';
    achbotOperationalReportDispatch($connection,$result['report_ref']);
    sendMessage(\achbotMessage('subscription.order_management.show_order_management_panel.message.88e0b88b0d'), null, 'HTML');
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    showOrderManagementPanel($order, $message_id);
    return;
}

if (preg_match('/^changeFileId_(\\d+)_(\\d+)$/', (string) ($data ?? ''), $match)) {
    $id = (int)$match[1];
    $serverId = (int)$match[2];
    // A cached plan-selector callback cannot supply another server id.
    if (!achbotSupportsOrder($connection, $id) || $serverId <= 0) {
        alert(\achbotMessage('subscription.order_management.show_order_management_panel.alert.3e801d365f'), true);
        return;
    }
    $stmt = $connection->prepare(
        "SELECT p.`id`, p.`title` FROM `server_plans` p
         INNER JOIN `server_config` c ON c.`id` = p.`server_id`
         INNER JOIN `orders_list` o ON o.`id` = ? AND o.`server_id` = p.`server_id`
         WHERE p.`server_id` = ? AND p.`active`=1 AND p.`inbound_id` = 0 AND c.`type` IN (".achbotProviderSqlTypes().")"
    );
    $stmt->bind_param("ii", $id, $serverId);
    $stmt->execute();
    $result = $stmt->get_result();
    $keyboard = [];
    while ($row = $result->fetch_assoc()) {
        $keyboard[] = [["text" => $row['title'], "callback_data" => "selectFileId_{$id}_{$row['id']}"]];
    }
    $stmt->close();
    $keyboard[] = [["text" => \achbotMessage('subscription.order_management.show_order_management_panel.button.b35a2352f5'), "callback_data" => "returnToPanel_$id"]];
    sendMessage(\achbotMessage('subscription.order_management.show_order_management_panel.message.877fdebc48'), json_encode(['inline_keyboard' => $keyboard]), 'HTML');
}

if (preg_match('/^reviewPlanCorrection_([1-9][0-9]*)_([1-9][0-9]*)$/D',
    (string)($data??''),$match)) {
    $journalId=(int)$match[1];$orderId=(int)$match[2];
    require_once __DIR__.'/../Admin/Access.php';
    if (!achbotAdminCanFor($connection,(int)$from_id,(int)($GLOBALS['admin']??0),'orders')) {
        alert(\achbotMessage('subscription.order_management.show_order_management_panel.alert.057ec5448c'),true);
        return;
    }
    // Displaying a confirmation button is read-only, never Native authorization.
    // The second callback independently rechecks every SQL/Native invariant.
    $keyboard=['inline_keyboard'=>[
        [[ 'text'=>\achbotMessage('subscription.service_plan_correction.confirm'),
            'callback_data'=>'confirmPlanCorrection_'.$journalId.'_'.$orderId ]],
        [[ 'text'=>\achbotMessage('legacy_buttons.back_button')??\achbotMessage('subscription.order_management.show_order_management_panel.button.b35a2352f5'),
            'callback_data'=>'returnToPanel_'.$orderId ]]
    ]];
    sendMessage(\achbotMessage('subscription.service_plan_correction.warning'),
        json_encode($keyboard,JSON_THROW_ON_ERROR), 'HTML');
    return;
}

if (preg_match('/^(recoverPlanJournal|confirmPlanCorrection)_([1-9][0-9]*)_([1-9][0-9]*)$/D',
    (string)($data??''),$match)) {
    $journalId=(int)$match[2];$orderId=(int)$match[3];
    $correct=$match[1]==='confirmPlanCorrection';
    require_once __DIR__.'/PhysicalService.php';
    $result=$correct?
        achbotPhysicalCorrectPlan($connection,$journalId,(int)$from_id,$orderId):
        achbotPhysicalRecoverPlan($connection,$journalId,(int)$from_id,$orderId);
    if (!$result['success']) {
        $key=$correct && $result['reason']==='physical_correction_get_only_available'
            ?'subscription.service_plan_correction.get_only'
            :'subscription.order_management.show_order_management_panel.alert.99d247fc18';
        alert(\achbotMessage($key),true);
        return;
    }
    achbotOperationalReportDispatch($connection,$result['report_ref']);
    sendMessage(\achbotMessage('subscription.order_management.show_order_management_panel.message.75785b37ae'), null, 'HTML');
    $q=$connection->prepare('SELECT * FROM orders_list WHERE id=? AND status=1');
    $q->bind_param('i',$orderId);$q->execute();
    $order=$q->get_result()->fetch_assoc();$q->close();
    if ($order) showOrderManagementPanel($order,$message_id);
    return;
}

if (preg_match('/^selectFileId_(\d+)_(\d+)$/', (string) ($data ?? ''), $match)) {
    $id=(int)$match[1];$newFileId=(int)$match[2];
    require_once __DIR__.'/PhysicalService.php';
    $result=achbotPhysicalSelectPlan($connection,$id,$newFileId,(int)$from_id,true);
    if (!$result['success']) {
        alert(\achbotMessage('subscription.order_management.show_order_management_panel.alert.99d247fc18'),true);
        return;
    }
    achbotOperationalReportDispatch($connection,$result['report_ref']);
    sendMessage(\achbotMessage('subscription.order_management.show_order_management_panel.message.75785b37ae'), null, 'HTML');
    $s=$connection->prepare('SELECT * FROM orders_list WHERE id=?');
    $s->bind_param('i',$id);$s->execute();$order=$s->get_result()->fetch_assoc();$s->close();
    if ($order) showOrderManagementPanel($order,$message_id);
    return;
}

if (preg_match('/^quickUserOrderDetails_(\d+)$/D', $data, $match)) {
    require_once __DIR__ . '/AccountDetails.php';
    if (!\AchBot\Subscription\AccountDetails\canReadAdminOrderDetails()) {
        alert(\achbotMessage('subscription.order_management.show_order_management_panel.alert.afe3a5b10e'));return;
    }
    $orderId = intval($match[1]);

    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();

    if ($result->num_rows == 0) {
        alert(\achbotMessage('subscription.order_management.show_order_management_panel.alert.ace28d61b3'));
        return;
    }

    $order = $result->fetch_assoc();
    $keys = getUserOrderDetailKeys($orderId, 0); // فرض: 0 یعنی admin دیده

    if ($keys == null) {
        alert(\achbotMessage('subscription.order_management.show_order_management_panel.alert.afe3a5b10e'));
        return;
    }

    \AchBot\Subscription\AccountDetails\showMessage($keys);
}

// End of Manage a specific order
//End by ACh

