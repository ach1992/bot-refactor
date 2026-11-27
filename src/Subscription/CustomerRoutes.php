<?php
/** AChBot V6: original Telegram account/subscription routes; keep caller scope. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG
require_once __DIR__.'/AccountDetails.php';

require_once __DIR__ . '/../Admin/Access.php';
if (!achbotAdminRequestAllowed(is_string($data??null)?$data:null,is_string($text??null)?$text:null,(string)($userInfo['step']??''))) {achbotAdminDeny();return;}


require_once __DIR__.'/Search.php';
$searchActor=(int)$from_id;
$searchStep=achbotSubscriptionSearchEditor((string)($userInfo['step']??''));
if (preg_match('/^cancelSearchConfig_([1-9][0-9]*)$/D',(string)$data,$cancelSearch)) {
    $source=\AchBot\Telegram\Conversation\source($update,$searchActor,true);
    if ($source && $searchStep!==null && $searchStep['source']===(int)$cancelSearch[1] &&
        \AchBot\Telegram\Conversation\transitionEditor($connection,$searchActor,$userInfo)!==null) {
        // The no-result prompt is disposable. Keyboard removal is independent.
        sendMessage(achbotMessage('legacy_messages.operation_canceled'),$removeKeyboard,'HTML');
        \AchBot\Telegram\Conversation\replaceTransient($searchActor,$source->message_id,
            achbotCurrentMessage('reached_main_menu'),getMainKeys());
    }
    return;
}

if (
    $data == 'mySubscriptions' ||
    $data == 'agentConfigsList' ||
    preg_match('/^(changeAgentOrder|changeOrdersPage)([1-9][0-9]*)$/D', (string)$data, $match)
) {
    $results_per_page = 50;
    $action = $match[1] ?? null;
    $page = $match[2] ?? 1;

    $stmt = $connection->prepare("SELECT COUNT(*) AS total FROM `orders_list` WHERE `userid`=? AND `status`=1");
    $stmt->bind_param("i", $from_id);
    $stmt->execute();
    $number_of_result = (int)$stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();

    $number_of_page = ceil($number_of_result / $results_per_page);
    if ((int)$page<1 || ((int)$page>max(1,$number_of_page))) return;
    $page_first_result = ((int)$page - 1) * $results_per_page;

    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `userid`=? AND `status`=1 ORDER BY `id` DESC LIMIT ?, ?");
    $stmt->bind_param("iii", $from_id, $page_first_result, $results_per_page);
    $stmt->execute();
    $orders = $stmt->get_result();
    $stmt->close();

    if ($orders->num_rows == 0) {
        if (($customerShortcut??null)==='mysubs') sendMessage(\achbotMessage('legacy_messages.you_dont_have_config'),null,'HTML');
        else alert(\achbotMessage('legacy_messages.you_dont_have_config'));
        exit;
    }

    $keyboard = [];
    while ($cat = $orders->fetch_assoc()) {
        $id = $cat['id'];
        $remark = $cat['remark'];
        $keyboard[] = ['text' => $remark, 'callback_data' => "orderDetails$id"];
    }

    $keyboard = array_chunk($keyboard, 1);

    $prev = $page - 1;
    $next = $page + 1;

    $isAgentConfig = ($data == "agentConfigsList" || $action == "changeAgentOrder");

    $buttons = [];
    if ($prev > 0) {
        $buttons[] = [
            'text' => \achbotMessage('subscription.customer_routes.cancel_search_keyboard.button.0b8da1e098'),
            'callback_data' => $isAgentConfig ? "changeAgentOrder$prev" : "changeOrdersPage$prev"
        ];
    }

    if ($page < $number_of_page) {
        $buttons[] = [
            'text' => \achbotMessage('subscription.customer_routes.cancel_search_keyboard.button.0d56714222'),
            'callback_data' => $isAgentConfig ? "changeAgentOrder$next" : "changeOrdersPage$next"
        ];
    }

    if (!empty($buttons)) {
        $keyboard[] = $buttons;
    }

    if ($isAgentConfig) {
        $keyboard[] = [[
            'text' => \achbotMessage('legacy_buttons.search_agent_config'),
            'callback_data' => "searchAgentConfig"
        ]];
    }
    // else {
    //     $keyboard[] = [[
    //         'text' => \achbotMessage('legacy_buttons.search_agent_config'),
    //         'callback_data' => "searchMyConfig"
    //     ]];
    // }

    $keyboard[] = [[
        'text' => \achbotMessage('legacy_buttons.back_to_main'),
        'callback_data' => "navHome"
    ]];

    if (($customerShortcut??null)!=='mysubs') {
        editText(
            $message_id,
            \achbotMessage('legacy_messages.select_one_to_show_detail'),
            json_encode(['inline_keyboard' => $keyboard])
        );
    } else {
        sendAction('typing');
        sendMessage(
            \achbotMessage('legacy_messages.select_one_to_show_detail'),
            json_encode(['inline_keyboard' => $keyboard]), 'HTML'
        );
    }

    exit;
}

if (in_array($data,['searchAgentConfig','searchMyConfig','searchUsersConfig'],true)) {
    $source=\AchBot\Telegram\Conversation\source($update,$searchActor,true);
    if (!$source || ($data==='searchUsersConfig' && !achbotAdminCanFor($connection,$searchActor,(int)$admin,'orders'))) return;
    if (\AchBot\Telegram\Conversation\transitionEditor($connection,$searchActor,$userInfo,$data.'_'.$source->message_id,update:$update)!==null)
        sendMessage(achbotMessage('legacy_messages.send_config_remark'),$cancelKey,'HTML');
    return;
}
if ($searchStep!==null && !isset($update->callback_query) && $text!==achbotMessage('legacy_buttons.cancel')) {
    $source=\AchBot\Telegram\Conversation\source($update,$searchActor,false);
    if (!$source || !is_string($source->text??null) || $source->message_id<=$searchStep['source']) return;
    $result=achbotSubscriptionSearch($connection,$searchActor,$searchStep['mode'],$source->text);
    if ($result['status']==='denied') {achbotAdminDeny();return;}
    if ($result['status']!=='found') {
        sendMessage($result['status']==='ambiguous'
            ?achbotMessage('subscription.customer_routes.search_ambiguous')
            :achbotMessage('legacy_messages.no_order_found'),achbotSubscriptionSearchCancelKeys($searchStep['source']),'HTML');
        return;
    }
    if (\AchBot\Telegram\Conversation\transitionEditor($connection,$searchActor,$userInfo)===null) return;
    sendMessage(achbotMessage('legacy_messages.please_wait_message'),$removeKeyboard,'HTML');
    $keys=$searchStep['mode']==='searchUsersConfig'?getUserOrderDetailKeys($result['id']):getOrderDetailKeys($searchActor,$result['id']);
    if (is_array($keys)) \AchBot\Subscription\AccountDetails\showMessage($keys);
    else sendMessage(achbotMessage('legacy_messages.no_order_found'),null,'HTML');
    return;
}
if (preg_match('/^userOrderDetails([1-9][0-9]*)_([0-9]+)$/D',(string)$data,$adminOrderDetailMatch)) {
    if (!\AchBot\Telegram\Conversation\source($update,$searchActor,true) ||
        !\AchBot\Subscription\AccountDetails\canReadAdminOrderDetails()) return;
    $keys=getUserOrderDetailKeys((int)$adminOrderDetailMatch[1],(int)$adminOrderDetailMatch[2]);
    if (is_array($keys)) \AchBot\Subscription\AccountDetails\showMessage($keys,(int)$message_id);
    else alert(achbotMessage('legacy_messages.no_order_found'),true);
    return;
}

if(preg_match('/^orderDetails([1-9][0-9]*)(?:_(?<offset>[0-9]+))?$/D', (string)$data, $match)){
    if (!\AchBot\Telegram\Conversation\source($update,$searchActor,true)) return;
    $keys = getOrderDetailKeys(
        $from_id,
        $match[1],
        !empty($match['offset']) ? $match['offset'] : 0
    );

    if ($keys == null) {
        alert(\achbotMessage('legacy_messages.no_order_found'), true);
        exit;
    } else {
        \AchBot\Subscription\AccountDetails\showMessage($keys,(int)$message_id);
        exit;
    }
}

if (preg_match('/^(updateConfigConnectionLink|changAccountConnectionLink)([1-9][0-9]*)$/D',(string)$data,$m)) {
    $source=\AchBot\Telegram\Conversation\source($update,$searchActor,true);
    if (!$source) return;
    require_once __DIR__.'/LinkActions.php';
    $rotate=$m[1]==='changAccountConnectionLink';
    alert(achbotMessage('legacy_messages.please_wait_message'));
    $result=achbotSubscriptionLinkAction($connection,$searchActor,(int)$m[2],$rotate,
        static function(array $order,object $remote) use ($searchActor,$source): bool {
            $keys=(int)$order['userid']===$searchActor
                ?\AchBot\Subscription\AccountDetails\getOrderDetailKeys($searchActor,(int)$order['id'],0,$remote)
                :\AchBot\Subscription\AccountDetails\getUserOrderDetailKeys((int)$order['id'],0,$remote);
            return is_array($keys) && \AchBot\Subscription\AccountDetails\showMessage($keys,$source->message_id);
        });
    if ($result==='shown') {
        $heading=$rotate?achbotMessage('subscription.customer_routes.cancel_search_keyboard.success_message.eb1ae135ff'):
            achbotMessage('subscription.customer_routes.cancel_search_keyboard.success_message.6d1ad0af39');
        sendMessage($heading."\n\n".achbotMessage('subscription.customer_routes.link_update_advice'),null,'HTML');
    } elseif ($result==='denied') {
        sendMessage(achbotMessage('subscription.customer_routes.cancel_search_keyboard.alert.724ff90245'),null,'HTML');
    } elseif ($result==='view_unconfirmed') {
        sendMessage(achbotMessage('subscription.customer_routes.link_view_unconfirmed'),null,'HTML');
    } elseif ($rotate) {
        sendMessage(achbotMessage('subscription.customer_routes.cancel_search_keyboard.alert.001c06ff23'),null,'HTML');
    } else {
        sendMessage(achbotMessage('subscription.customer_routes.cancel_search_keyboard.error_message.496a7eede8').
            achbotMessage('subscription.customer_routes.cancel_search_keyboard.text.fd5bccf56e').
            achbotMessage('subscription.customer_routes.cancel_search_keyboard.text.0d78481f3e'),null,'HTML');
    }
    return;
}

if(preg_match('/changeUserConfigState(\d+)/', $data,$match)){
    alert(\achbotMessage('legacy_messages.please_wait_message'));
    $stateActorAdmin=((int)$from_id===(int)$admin || !empty($userInfo['isAdmin']));
    $oid = $match[1];

    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id`=?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$order || !achbotSupportsOrder($connection,(int)$oid,null,$stateActorAdmin?null:(int)$from_id)) {alert(\achbotMessage('subscription.customer_routes.cancel_search_keyboard.alert.627f7fad2f'),true);return;}
    $userId = $order['userid'];
    $uuid = $order['uuid']??"0";
    $inboundId = $order['inbound_id'];
    $server_id = $order['server_id'];
    $remark = $order['remark'];
    
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $serverType = $server_info['type'];

    
    if (!achbotSupportsPanel($serverType) || (int)$inboundId !== 0) {
        alert(\achbotMessage('subscription.customer_routes.cancel_search_keyboard.alert.724ff90245'), true);
        return;
    }
    require_once __DIR__.'/../Panel/ServiceLifecycle.php';
    $observed=achbotServiceObserve((int)$server_id,(string)$remark);
    if (!$observed['verified'] || !in_array($observed['status'],['active','disabled'],true)) {
        alert(\achbotMessage('subscription.customer_routes.cancel_search_keyboard.alert.724ff90245'),true);
        return;
    }
    $update_response = achbotProviderToggle($server_id, $remark);
    
    if($update_response->success){
        alert(\achbotMessage('legacy_messages.please_wait_message'));
    
        $keys = $stateActorAdmin?getUserOrderDetailKeys($oid):getOrderDetailKeys((int)$from_id,$oid);
        \AchBot\Subscription\AccountDetails\showMessage($keys,(int)$message_id);
    }else sendMessage(\achbotMessage('subscription.customer_routes.cancel_search_keyboard.message.b63b579845'), null, 'HTML');
}

if (preg_match('/agentChangeUserConfigState(\d+)/', $data, $match)) {
    alert(\achbotMessage('legacy_messages.please_wait_message'));

    $oid = $match[1];

    // گرفتن اطلاعات سفارش
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id`=?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$order || !achbotSupportsOrder($connection,(int)$oid,null,
        ((int)$from_id===(int)$admin || !empty($userInfo['isAdmin']))?null:(int)$from_id) ||
        (!((int)$from_id===(int)$admin || !empty($userInfo['isAdmin'])) &&
         ((int)($userInfo['is_agent']??0)!==1 || (int)($order['agent_bought']??0)!==1))) {
        sendMessage(\achbotMessage('subscription.customer_routes.cancel_search_keyboard.message.f0a1fc755c'), null, 'HTML');
        return;
    }

    $uuid = $order['uuid'] ?? "0";
    $inboundId = $order['inbound_id'];
    $server_id = $order['server_id'];
    $remark = $order['remark'];

    // گرفتن اطلاعات سرور
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$server_info) {
        sendMessage(\achbotMessage('subscription.customer_routes.cancel_search_keyboard.message.a7cb7bc569'), null, 'HTML');
        return;
    }

    $serverType = $server_info['type'];

    // Marzban-only: refuse obsolete inbound metadata instead of altering a
    // different panel/account by guesswork.
    if (!achbotSupportsPanel($serverType) || (int)$inboundId !== 0) {
        alert(\achbotMessage('subscription.customer_routes.cancel_search_keyboard.alert.724ff90245'), true);
        return;
    }
    require_once __DIR__.'/../Panel/ServiceLifecycle.php';
    $observed=achbotServiceObserve((int)$server_id,(string)$remark);
    if (!$observed['verified'] || !in_array($observed['status'],['active','disabled'],true)) {
        alert(\achbotMessage('subscription.customer_routes.cancel_search_keyboard.alert.724ff90245'),true);
        return;
    }
    $update_response = achbotProviderToggle($server_id, $remark);

    // بروزرسانی موفق بود
    if ($update_response->success) {
        alert(\achbotMessage('legacy_messages.please_wait_message'));

        $keys = getOrderDetailKeys($order['userid'], $oid);

        \AchBot\Subscription\AccountDetails\showMessage($keys,(int)$message_id);

    } else {
        sendMessage(\achbotMessage('subscription.customer_routes.cancel_search_keyboard.message.b63b579845'), null, 'HTML');
    }
}
