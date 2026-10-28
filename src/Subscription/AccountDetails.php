<?php
/** AChBot V6: exact V5 internal src/Subscription/AccountDetails.php bodies; keep legacy effects. */
namespace AchBot\Subscription\AccountDetails;
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG
require_once __DIR__.'/../Purchase/OrderWrites.php';


if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

function findOrder($id, $userId = null) {
    global $connection;

    if ($userId === null) {
        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id`=?");
        $stmt->bind_param("i", $id);
    } else {
        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `userid`=? AND `id`=?");
        $stmt->bind_param("ii", $userId, $id);
    }

    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    return $res->num_rows ? $res->fetch_assoc() : null;
}


function getPlanFullName($fid, $fallbackId) {
    global $connection;

    $stmt = $connection->prepare("SELECT * FROM server_plans WHERE id=? AND active=1");
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $plan = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$plan) return (string)$fallbackId;

    $stmt = $connection->prepare("SELECT title FROM server_categories WHERE id=?");
    $stmt->bind_param("i", $plan['catid']);
    $stmt->execute();
    $cat = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return ($cat ? $cat['title'] . " " : "") . $plan['title'];
}


function getServerConfigById($server_id) {
    global $connection;

    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $config = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $config;
}


function getOrderTrafficInfo($serverType, $server_id, $remark, $uuid, $inbound_id, $rahgozar=false, ?object $provenRemote=null) {
    require_once __DIR__.'/../Panel/ServiceLifecycle.php';
    $result = [
        'found'=>false,'enable'=>false,
        'leftgb'=>\achbotMessage('subscription.account_details.get_order_traffic_info.text.f07ac0638d'),
        'netType'=>null,'security'=>null,'uncertain'=>true,'status'=>null,
        'expire'=>null,'actionable'=>false,
    ];
    if (!achbotSupportsAccount($serverType,$inbound_id)) return $result;
    $observed=$provenRemote===null?achbotServiceObserve((int)$server_id,(string)$remark):
        achbotServiceObservation($provenRemote,(string)$remark);
    if (!$observed['verified']) return $result;
    $result['found']=true;$result['uncertain']=false;
    $result['enable']=$observed['enabled'];$result['status']=$observed['status'];
    $result['expire']=$observed['expire'];
    $result['actionable']=in_array($observed['status'],['active','disabled','expired','limited'],true);
    $result['leftgb']=$observed['remaining']===null?
        \achbotMessage('subscription.account_deletion.no_dont_delete.text.5e6ee5eaa1'):
        \achbotMessage('subscription.account_details.remaining_traffic',[
            'remaining_gib'=>round($observed['remaining']/1073741824,2)]);
    return $result;
}


function getOrderStatusText(array $traffic,string $serverType,array $buttons,?string $missing=null): string {
    if (($traffic['uncertain']??true)===true || ($traffic['found']??false)!==true)
        return \achbotMessage('subscription.account_details.get_order_status_text.label.ff02acf7a2');
    return match($traffic['status']??null) {
        'active'=>$buttons['active'],
        'disabled'=>$buttons['deactive'],
        'expired'=>\achbotMessage('subscription.account_details.get_order_status_text.text.63ef396546'),
        'limited'=>\achbotMessage('subscription.account_details.get_order_status_text.text.e9216d0d0c'),
        'on_hold'=>\achbotMessage('subscription.account_details.status_on_hold'),
        default=>\achbotMessage('subscription.account_details.get_order_status_text.label.ff02acf7a2')
    };
}

function getProtocolNamesRow($protocol, $netType, $security, $rahgozar, $inbound_id) {

    if ($inbound_id != 0) {
        return [$protocol];
    }

    if ($protocol == 'trojan') {
        if ($security == "xtls") {
            return ['trojan', 'vless'];
        }
        return ['trojan', 'vmess', 'vless'];
    }

    if ($netType == "grpc") {
        return ['vmess', 'vless'];
    }

    if ($netType == "tcp" && $security == "xtls") {
        return ['trojan', 'vless'];
    }

    if ($rahgozar) {
        return ['vmess', 'vless'];
    }

    return ['trojan', 'vmess', 'vless'];
}


function buildAdminProtocolButtons($protocol, $netType, $security, $rahgozar, $inbound_id) {
    $names = getProtocolNamesRow($protocol, $netType, $security, $rahgozar, $inbound_id);

    $row = [];
    foreach ($names as $name) {
        $row[] = [
            'text' => ($protocol == $name ? \achbotMessage('subscription.account_details.build_admin_protocol_buttons.name.177d6f2a4e', ['name' => $name]) : $name),
            'callback_data' => "wizwizch"
        ];
    }

    return [$row];
}


function buildUserProtocolButtons($protocol, $netType, $security, $rahgozar, $inbound_id, $serverType, $botState, $fid, $id) {
    if (achbotSupportsAccount($serverType,$inbound_id)) return [];

    $names = getProtocolNamesRow($protocol, $netType, $security, $rahgozar, $inbound_id);

    $row = [];
    foreach ($names as $name) {
        $cb = ($botState['changeProtocolState']=="on")
            ? "changeAccProtocol{$fid}_{$id}_{$name}"
            : "changeProtocolIsDisable";

        $row[] = [
            'text' => ($protocol == $name ? \achbotMessage('subscription.account_details.build_admin_protocol_buttons.name.177d6f2a4e', ['name' => $name]) : $name),
            'callback_data' => $cb
        ];
    }

    return [$row];
}


/** Resolve the current actor from persisted roles before disclosing account secrets. */
function currentOrderDetailActor(): ?array {
    global $connection, $from_id;
    $actor=(int)($from_id??0);
    if (!($connection instanceof \mysqli) || $actor<=0) return null;
    try {
        $stmt=$connection->prepare('SELECT id,userid,isAdmin,step FROM users WHERE userid=? LIMIT 2');
        $stmt->bind_param('i',$actor);$stmt->execute();
        $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
        if (count($rows)!==1 || (string)$rows[0]['step']==='banned') return null;
        return $rows[0];
    } catch (\Throwable $error) {return null;}
}
function canReadAdminOrderDetails(): bool {
    global $admin;
    $actor=currentOrderDetailActor();
    return $actor!==null && ((int)$actor['userid']===(int)($admin??0) || (int)$actor['isAdmin']===1);
}

/**
 * A selected plan authorizes future paid operations, never retroactive payment.
 * New selections require the same logical server. Existing relocated services
 * retain their original plan only within the already verified physical panel.
 */
function paidPlanForOrder(\mysqli $db,array $order,int $planId,bool $lock=false,
    bool $sameLogicalServer=true): ?array {
    if ($planId<=0 || (int)($order['status']??0)!==1 ||
        !achbotOrderMayUsePaidPlan($db,$order,$lock)) return null;
    $stmt=$db->prepare('SELECT p.*,c.type AS provider_type,i.active AS server_active
        FROM server_plans p JOIN server_info i ON i.id=p.server_id
        JOIN server_config c ON c.id=p.server_id
        WHERE p.id=? AND p.active=1 AND p.price>0'.($lock?' FOR UPDATE':''));
    $stmt->bind_param('i',$planId);$stmt->execute();$plan=$stmt->get_result()->fetch_assoc();$stmt->close();
    if (!$plan || (int)$plan['server_active']!==1 ||
        !achbotSupportsAccount($plan['provider_type'], $plan['inbound_id']) ||
        achbotWholeServiceUnits($plan['days'],36500)===null ||
        achbotWholeServiceUnits($plan['volume'],achbotServiceVolumeMax())===null ||
        !achbotSupportsPlan($db,$planId,0,(int)$plan['server_id'],$lock))
        return null;
    $orderServer=(int)($order['server_id']??0);$planServer=(int)$plan['server_id'];
    if ($orderServer!==$planServer &&
        ($sameLogicalServer || !achbotSameProviderPanel($db,$orderServer,$planServer))) return null;
    $stmt=$db->prepare('SELECT i.active,c.type FROM server_info i JOIN server_config c ON c.id=i.id WHERE i.id=?'.($lock?' FOR UPDATE':''));
    $stmt->bind_param('i',$orderServer);$stmt->execute();$server=$stmt->get_result()->fetch_assoc();$stmt->close();
    if (!$server || (int)$server['active']!==1 ||
        !achbotSupportsAccount($server['type'],$order['inbound_id']??null)) return null;
    return $plan;
}
function selectedPaidPlan(\mysqli $db,array $order,bool $lock=false): ?array {
    return paidPlanForOrder($db,$order,(int)($order['fileid']??0),$lock,false);
}
/** Missing/malformed origin evidence is not a free-trial inference. */
function orderPlanActions(\mysqli $db,array $order): array {
    try {
        $mayChoose=(int)($order['status']??0)===1 && achbotOrderMayUsePaidPlan($db,$order);
        return ['certain'=>true,'choose'=>$mayChoose,'paid'=>$mayChoose?selectedPaidPlan($db,$order):null];
    } catch (\Throwable $error) {
        error_log('achbot_order_plan_actions_unverified order_id='.(int)($order['id']??0));
        return ['certain'=>false,'choose'=>false,'paid'=>null];
    }
}

function getUserOrderDetailKeys($id, $offset = 0){
    global $botState, $buttonValues, $botUrl;

    if (!canReadAdminOrderDetails()) return null;
    $order = findOrder($id);
    if (!$order) return null;

    $userId      = $order['userid'];
    $firstName   = bot('getChat',['chat_id'=>$userId])->result->first_name ?? " ";
    $name        = getPlanFullName($order['fileid'], $id);

    $date        = jdate("Y-m-d H:i", $order['date']);

    $remark      = $order['remark'];
    $uuid        = $order['uuid'] ?? "0";
    $acc_link    = json_decode($order['link'], true);
    $protocol    = $order['protocol'];
    $token       = $order['token'];
    $server_id   = $order['server_id'];
    $inbound_id  = $order['inbound_id'];
    $rahgozar    = $order['rahgozar'];
    $agentBought = $order['agent_bought'];
    $autoRenew   = $order['autoRenew'] ?? "off";
    $planActions=orderPlanActions($GLOBALS['connection'],$order);

    $server      = getServerConfigById($server_id);
    $serverType  = $server['type'] ?? '';
    $panelUrl    = $server['panel_url'] ?? '';
    $subLinksRaw = $server['sub_links'] ?? '';

    $renderRemote=achbotSupportsAccount($serverType,$inbound_id)?
        achbotProviderRenderPacket($GLOBALS['connection'],(int)$server_id,(string)$remark):null;
    $traffic     = getOrderTrafficInfo($serverType, $server_id, $remark, $uuid, $inbound_id, $rahgozar,$renderRemote);

    $leftgb      = $traffic['leftgb'] ?? \achbotMessage('subscription.account_details.get_order_traffic_info.text.f07ac0638d');
    $enableBool  = $traffic['enable'] ?? false;
    $uncertain=($traffic['uncertain']??true)===true;
    $actionable=($traffic['actionable']??false)===true;
    $expire_date=$uncertain?\achbotMessage('subscription.account_details.get_order_traffic_info.text.f07ac0638d'):
        ($traffic['expire']===null?\achbotMessage('subscription.account_deletion.no_dont_delete.text.5e6ee5eaa1'):jdate('Y-m-d H:i',$traffic['expire'],'','Asia/Tehran','en'));
    $canToggle=in_array($traffic['status']??null,['active','disabled'],true);
    $enableText=getOrderStatusText($traffic,$serverType,$buttonValues);

    $presentation=$uncertain?['config_html'=>'','subscription_html'=>'','backup_html'=>'','notice_html'=>'']:
        achbotOrderPresentation($GLOBALS['connection'],$order,$server,$botState,$renderRemote);
    $configLinks=$presentation['config_html'];
    $subLink=$presentation['subscription_html'];
    $backup=$presentation['backup_html'];

    $msg = buildConfigMessage($enableText, $remark, $configLinks, $subLink, $backup, $serverType);
    if ($presentation['notice_html'] !== '') $msg .= "\n\n" . $presentation['notice_html'];
    if ($uncertain && !str_contains($msg,$enableText)) $msg.="\n\n".$enableText;
    if (!$planActions['certain']) $msg.="\n\n".\achbotMessage('subscription.account_details.get_order_status_text.label.ff02acf7a2');

    // ---- Admin Keyboard ----
    $keyboard = [];

    $keyboard[] = [
        ['text' => $userId, 'callback_data' => "wizwizch"],
        ['text' => \achbotMessage('subscription.account_details.get_user_order_detail_keys.button.d35e1b4661'), 'callback_data' => "wizwizch"],
    ];

    $keyboard[] = [
        ['text' => $firstName, 'callback_data' => "wizwizch"],
        ['text' => \achbotMessage('subscription.account_details.get_user_order_detail_keys.button.c9b4331f4f'), 'callback_data' => "wizwizch"],
    ];

    $keyboard[] = [
        ['text' => ($agentBought ? \achbotMessage('subscription.account_deletion.button.13159f8c74') : \achbotMessage('subscription.account_deletion.button.5f5c4c72bd')), 'callback_data' => "wizwizch"],
        ['text' => \achbotMessage('subscription.account_details.get_user_order_detail_keys.button.1c7f597d4f'), 'callback_data' => "wizwizch"],
    ];

    $keyboard[] = [
        ['text' => $name, 'callback_data' => "wizwizch"],
        ['text' => $buttonValues['plan_name'], 'callback_data' => "wizwizch"],
    ];

    $keyboard[] = [
        ['text' => $date, 'callback_data' => "wizwizch"],
        ['text' => $buttonValues['buy_date'], 'callback_data' => "wizwizch"],
    ];

    $keyboard[] = [
        ['text' => $expire_date, 'callback_data' => "wizwizch"],
        ['text' => $buttonValues['expire_date'], 'callback_data' => "wizwizch"],
    ];

    $keyboard[] = [
        ['text' => $leftgb, 'callback_data' => "wizwizch"],
        ['text' => $buttonValues['volume_left'], 'callback_data' => "wizwizch"],
    ];

    // Physical relocation is held by Issue #31 until cross-panel transfer
    // and failure recovery are proved; never expose the DB-only legacy route.
    if (!$uncertain && $actionable) $keyboard[] = [
        ['text' => $buttonValues['change_plan'], 'callback_data' => "changePlan_{$id}"],
    ];

    if (!$uncertain && $actionable && $planActions['paid']!==null && ($botState['renewAutoState'] ?? "off") == "on") {
        $keyboard[] = [[
            'text' => (($autoRenew ?? 'off') == "on" ? \achbotMessage('subscription.account_details.get_user_order_detail_keys.text.ed76444585') : \achbotMessage('subscription.account_details.get_user_order_detail_keys.text.fb6d00ad8d')),
            'callback_data' => "adminToggleAutoRenew{$order['id']}"
        ]];
    }

    // protocol label
    /*$keyboard[] = [
        ['text' => $buttonValues['selected_protocol'], 'callback_data' => "wizwizch"],
    ];

    // protocol rows
    foreach (buildAdminProtocolButtons($protocol, $traffic['netType'], $traffic['security'], $rahgozar, $inbound_id) as $row) {
        $keyboard[] = $row;
    }*/

    // enable/disable + delete
    if (!$uncertain && $actionable) {
        $stateActions=[];
        if ($canToggle) $stateActions[]=[
            'text'=>($enableBool?$buttonValues['disable_config']:$buttonValues['enable_config']),
            'callback_data'=>"changeUserConfigState".$order['id']
        ];
        $stateActions[]=[
            'text'=>$buttonValues['delete_config'],
            'callback_data'=>"delUserConfig".$order['id']
        ];
        $keyboard[]=$stateActions;
    }

    $keyboard[] = [[
        'text' => $buttonValues['back_button'],
        'callback_data' => "managePanel"
    ]];

    return [
        "keyboard" => json_encode(['inline_keyboard' => $keyboard]),
        "msg"      => $msg
    ];
}


function getOrderDetailKeys($from_id, $id, $offset = 0){
    global $botState, $mainValues, $buttonValues, $botUrl, $userInfo;

    $actor=currentOrderDetailActor();
    if ($actor===null || ((int)$actor['userid']!==(int)$from_id && !canReadAdminOrderDetails())) return null;
    $order = findOrder($id, $from_id);
    if (!$order) return null;

    $fid         = $order['fileid'];
    $name        = getPlanFullName($fid, $id);


    $remark      = $order['remark'];
    $uuid        = $order['uuid'] ?? "0";
    $acc_link    = json_decode($order['link'], true);
    $protocol    = $order['protocol'];
    $token       = $order['token'];
    $server_id   = $order['server_id'];
    $inbound_id  = $order['inbound_id'];
    $rahgozar    = $order['rahgozar'];
    $agentBought = $order['agent_bought'];
    $autoRenew   = $order['autoRenew'] ?? "off";
    $planActions=orderPlanActions($GLOBALS['connection'],$order);
    $price       = $order['amount'];

    $server      = getServerConfigById($server_id);
    $serverType  = $server['type'] ?? '';
    $panelUrl    = $server['panel_url'] ?? '';
    $subLinksRaw = $server['sub_links'] ?? '';

    $renderRemote=achbotSupportsAccount($serverType,$inbound_id)?
        achbotProviderRenderPacket($GLOBALS['connection'],(int)$server_id,(string)$remark):null;
    $traffic = getOrderTrafficInfo($serverType, $server_id, $remark, $uuid, $inbound_id, $rahgozar,$renderRemote);

    $found      = $traffic['found'] ?? false;
    $enableBool = $traffic['enable'] ?? false;
    $leftgb     = $traffic['leftgb'] ?? \achbotMessage('subscription.account_details.get_order_traffic_info.text.f07ac0638d');

    $uncertain=($traffic['uncertain']??true)===true;
    $actionable=($traffic['actionable']??false)===true;
    $expire_date=$uncertain?\achbotMessage('subscription.account_details.get_order_traffic_info.text.f07ac0638d'):
        ($traffic['expire']===null?\achbotMessage('subscription.account_deletion.no_dont_delete.text.5e6ee5eaa1'):jdate('Y-m-d H:i',$traffic['expire'],'','Asia/Tehran','en'));
    $canToggle=in_array($traffic['status']??null,['active','disabled'],true);
    $canExtendTime=!$uncertain && $traffic['expire']!==null;
    // Use the verified total allowance, not the finite remaining balance.
    $canExtendVolume=$canExtendTime && (int)($renderRemote->data_limit??0)>0;
    $enableText=getOrderStatusText($traffic,$serverType,$buttonValues,$mainValues['config_doesnt_exist']);

    $presentation=$uncertain?['config_html'=>'','subscription_html'=>'','backup_html'=>'','notice_html'=>'']:
        achbotOrderPresentation($GLOBALS['connection'],$order,$server,$botState,$renderRemote);
    $configLinks=$presentation['config_html'];
    $subLink=$presentation['subscription_html'];
    $backup=$presentation['backup_html'];

    $msg = buildConfigMessage($enableText, $remark, $configLinks, $subLink, $backup, $serverType);
    if ($presentation['notice_html'] !== '') $msg .= "\n\n" . $presentation['notice_html'];
    if ($uncertain && !str_contains($msg,$enableText)) $msg.="\n\n".$enableText;
    if (!$planActions['certain']) $msg.="\n\n".\achbotMessage('subscription.account_details.get_order_status_text.label.ff02acf7a2');

    // ---- User Keyboard ----
    $keyboard = [];

    $keyboard[] = [
        ['text' => $name, 'callback_data' => "wizwizch"],
        ['text' => $buttonValues['plan_name'], 'callback_data' => "wizwizch"],
    ];

    /*
    $keyboard[] = [
        ['text' => jdate("Y-m-d H:i", $order['date']), 'callback_data' => "wizwizch"],
        ['text' => $buttonValues['buy_date'], 'callback_data' => "wizwizch"],
    ];
    */

    $keyboard[] = [
        ['text' => $expire_date, 'callback_data' => "wizwizch"],
        ['text' => $buttonValues['expire_date'], 'callback_data' => "wizwizch"],
    ];

    $keyboard[] = [
        ['text' => $leftgb, 'callback_data' => "wizwizch"],
        ['text' => $buttonValues['volume_left'], 'callback_data' => "wizwizch"],
    ];

    // protocol label
    if (!achbotSupportsAccount($serverType,$inbound_id)) {
        $keyboard[] = [
            ['text' => $buttonValues['selected_protocol'], 'callback_data' => "wizwizch"],
        ];
    }

    if (!$uncertain && $actionable && $planActions['paid']!==null && ($botState['renewAutoState'] ?? "off") == "on") {
        $keyboard[] = [[
            'text' => (($autoRenew ?? 'off') == "on" ? \achbotMessage('subscription.account_details.get_user_order_detail_keys.text.ed76444585') : \achbotMessage('subscription.account_details.get_user_order_detail_keys.text.fb6d00ad8d')),
            'callback_data' => "toggleAutoRenew{$id}"
        ]];
    }

    if ($found && $actionable) foreach (buildUserProtocolButtons(
        $protocol,
        $traffic['netType'],
        $traffic['security'],
        $rahgozar,
        $inbound_id,
        $serverType,
        $botState,
        $fid,
        $id
    ) as $row) {
        $keyboard[] = $row;
    }

    // ---- Renewal + SwitchLocation
    if ($found && $actionable) {
        $temp = [];

        if (
            ($botState['sellState'] ?? "off") == "on" &&
            $planActions['paid']!==null &&
            ($botState['renewAccountState'] ?? "off") == "on"
        ) {
            $temp[] = ['text' => $buttonValues['renew_config'], 'callback_data' => "renewAccountAsk{$id}"];
        }

        if (($botState['switchLocationState'] ?? 'off') === 'on' &&
            achbotSupportsAccount($serverType,$inbound_id) &&
            achbotProviderRelocationTargets($GLOBALS['connection'],(int)$server_id)!==[]) {
            // The callback carries the old shape but never grants authority.
            $temp[] = ['text' => \achbotMessage('subscription.account_details.get_order_detail_keys.button.5c38085697'),
                       'callback_data' => "switchLocation{$id}_{$server_id}_0_0"];
        }

        if (!empty($temp)) {
            $keyboard[] = $temp;
        }
    }

    // ---- User Actions
    if ($found && $actionable) {

        $flatButtons = [];

        if (
            ($botState['sellState'] ?? "off") == "on" &&
            ($botState['increaseVolumeState'] ?? "off") == "on" &&
            $canExtendVolume &&
            $planActions['paid']!==null
        ) {
            $flatButtons[] = ['text' => $buttonValues['increase_config_volume'], 'callback_data' => "increaseAVolume{$id}"];
        }

        if (
            ($botState['sellState'] ?? "off") == "on" &&
            ($botState['increaseTimeState'] ?? "off") == "on" &&
            $canExtendTime &&
            $planActions['paid']!==null
        ) {
            $flatButtons[] = ['text' => $buttonValues['increase_config_days'], 'callback_data' => "increaseADay{$id}"];
        }

        if (($botState['renewConfigLinkState'] ?? "off") == "on")
            $flatButtons[] = ['text' => $buttonValues['renew_connection_link'], 'callback_data' => "changAccountConnectionLink{$id}"];

        if (($botState['updateConfigLinkState'] ?? "off") == "on")
            $flatButtons[] = ['text' => $buttonValues['update_config_connection'], 'callback_data' => "updateConfigConnectionLink{$id}"];

        if ($planActions['choose']) $flatButtons[] = ['text' => $buttonValues['change_plan'], 'callback_data' => "changePlan_{$id}"];

        if (($botState['qrConfigState'] ?? "off") == "on" && !empty($presentation['qr_config_links']))
            $flatButtons[] = ['text' => $buttonValues['qr_config'], 'callback_data' => "showQrConfig{$id}"];

        if (($botState['qrSubState'] ?? "off") == "on" && !empty($presentation['subscription_url']))
            $flatButtons[] = ['text' => $buttonValues['qr_sub'], 'callback_data' => "showQrSub{$id}"];

        // همکار enable/disable
        if ($canToggle && $agentBought == true && !empty($userInfo['is_agent']) && (int)$userInfo['is_agent'] == 1) {
            $flatButtons[] = [
                'text' => ($enableBool ? $buttonValues['disable_config'] : $buttonValues['enable_config']),
                'callback_data' => "agentChangeUserConfigState{$id}"
            ];
        }

        for ($i = 0; $i < count($flatButtons) - 1; $i += 2) {
            $keyboard[] = [$flatButtons[$i], $flatButtons[$i + 1]];
        }

        $lastButton = (count($flatButtons) % 2 == 1) ? $flatButtons[count($flatButtons) - 1] : null;

        // delete
        $deleteButton = ['text' => $buttonValues['delete_config'], 'callback_data' => "deleteMyConfig{$id}"];

        if ($lastButton) {
            $keyboard[] = [$lastButton, $deleteButton];
        } else {
            $keyboard[] = [$deleteButton];
        }
    }

    $keyboard[] = [[
        'text' => $buttonValues['back_button'],
        'callback_data' => ($agentBought == true ? "agentConfigsList" : "mySubscriptions")
    ]];

    return [
        "keyboard" => json_encode(['inline_keyboard' => $keyboard]),
        "msg"      => $msg
    ];
}

