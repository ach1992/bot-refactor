<?php
/** AChBot V6: exact V5 internal src/Subscription/AccountDetails.php bodies; keep legacy effects. */
namespace AchBot\Subscription\AccountDetails;

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


function getOrderTrafficInfo($serverType, $server_id, $remark, $uuid, $inbound_id, $rahgozar=false) {

    $result = [
        'found'    => false,
        'enable'   => false,
        'leftgb'   => "⚠️",
        'netType'  => null,
        'security' => null,
    ];

    // ---- Marzban ----
    if ($serverType == "marzban") {
        $info = getMarzbanUser($server_id, $remark);

        if (!isset($info->username)) {
            return $result;
        }

        $result['found']  = true;
        $result['enable'] = ($info->status == "active");

        $total = $info->data_limit ?? 0;
        $used  = $info->used_traffic ?? 0;

        $result['leftgb'] = round(($total - $used) / 1073741824, 2) . " GB";
        return $result;
    }

    // ---- Non-Marzban ----
    $data = getJson($server_id)->obj ?? null;
    if (!$data) return $result;

    foreach ($data as $row) {

        $settings = json_decode($row->settings ?? '{}');
        $clients  = $settings->clients ?? [];

        if ($inbound_id == 0) {
            if (empty($clients) || !isset($clients[0])) continue;

            $c0 = $clients[0];
            $cid = $c0->id ?? null;
            $cpw = $c0->password ?? null;

            if ($cid != $uuid && $cpw != $uuid) continue;

            $result['found'] = true;

            $stream = json_decode($row->streamSettings ?? '{}');
            $result['netType']  = $stream->network ?? null;
            $result['security'] = $stream->security ?? null;

            $total  = $row->total ?? 0;
            $up     = $row->up ?? 0;
            $down   = $row->down ?? 0;
            $enable = $row->enable ?? false;
			
            if (!empty($row->clientStats)) {
                $stats = $row->clientStats;
                $email = $c0->email ?? null;

                if ($email) {
                    $emails = array_column($stats, 'email');
                    $k = array_search($email, $emails);

                    if ($k !== false) {
                        $clientTotal = $stats[$k]->total ?? null;
                        $clientUp    = $stats[$k]->up ?? 0;
                        $clientDown  = $stats[$k]->down ?? 0;

                        $clientExpiry = $stats[$k]->expiryTime ?? null;

                        if ($clientTotal !== null && $clientTotal != 0) {
                            if ($clientExpiry === null || $clientExpiry != 0) {
                                $total = $clientTotal;
                                $up   += $clientUp;
                                $down += $clientDown;
                            }
                        }
                    }
                }
            }

            $result['enable'] = $enable;
            $result['leftgb'] = round(($total - $up - $down) / 1073741824, 2) . " GB";
            return $result;
        }

        // inbound_id != 0
        if (($row->id ?? null) != $inbound_id) continue;

        $stream = json_decode($row->streamSettings ?? '{}');
        $result['netType']  = $stream->network ?? null;
        $result['security'] = $stream->security ?? null;

        $clientsStates = $row->clientStats ?? [];

        foreach ($clients as $client) {
            $cid = $client->id ?? null;
            $cpw = $client->password ?? null;

            if ($cid == $uuid || $cpw == $uuid) {
                $result['found'] = true;

                $email = $client->email ?? null;
                if (!$email) return $result;

                $emails = array_column($clientsStates, 'email');
                $k = array_search($email, $emails);

                if ($k === false) return $result;

                $up    = $clientsStates[$k]->up ?? 0;
                $down  = $clientsStates[$k]->down ?? 0;
                $total = $clientsStates[$k]->total ?? 0;

                $stateEnable = $clientsStates[$k]->enable ?? false;
                $clientEnable = $client->enable ?? true;

                $result['enable'] = ($stateEnable && $clientEnable);
                $result['leftgb'] = round(($total - $up - $down) / 1073741824, 2) . " GB";
                return $result;
            }
        }
    }

    return $result;
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
            'text' => ($protocol == $name ? "☑️ $name" : $name),
            'callback_data' => "wizwizch"
        ];
    }

    return [$row];
}


function buildUserProtocolButtons($protocol, $netType, $security, $rahgozar, $inbound_id, $serverType, $botState, $fid, $id) {
    if ($serverType == "marzban") return [];

    $names = getProtocolNamesRow($protocol, $netType, $security, $rahgozar, $inbound_id);

    $row = [];
    foreach ($names as $name) {
        $cb = ($botState['changeProtocolState']=="on")
            ? "changeAccProtocol{$fid}_{$id}_{$name}"
            : "changeProtocolIsDisable";

        $row[] = [
            'text' => ($protocol == $name ? "☑️ $name" : $name),
            'callback_data' => $cb
        ];
    }

    return [$row];
}


function getUserOrderDetailKeys($id, $offset = 0){
    global $botState, $buttonValues, $botUrl, $showIndexes;

    $order = findOrder($id);
    if (!$order) return null;

    $userId      = $order['userid'];
    $firstName   = bot('getChat',['chat_id'=>$userId])->result->first_name ?? " ";
    $name        = getPlanFullName($order['fileid'], $id);

    $date        = jdate("Y-m-d H:i", $order['date']);
    $expire_date = jdate("Y-m-d H:i", $order['expire_date']);

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

    $server      = getServerConfigById($server_id);
    $serverType  = $server['type'] ?? '';
    $panelUrl    = $server['panel_url'] ?? '';
    $subLinksRaw = $server['sub_links'] ?? '';

    $traffic     = getOrderTrafficInfo($serverType, $server_id, $remark, $uuid, $inbound_id, $rahgozar);

    $leftgb      = $traffic['leftgb'] ?? "⚠️";
    $enableBool  = $traffic['enable'] ?? false;
    $enableText  = $enableBool ? $buttonValues['active'] : $buttonValues['deactive'];

    $configLinks = buildConfigLinks($acc_link, $botState, $showIndexes);
    $subLink     = buildSubLink($serverType, $panelUrl, $botUrl, $token, $botState);
    $backup      = buildSubBackupSection($subLinksRaw, $token);

    $msg = buildConfigMessage($enableText, $remark, $configLinks, $subLink, $backup, $serverType);

    // ---- Admin Keyboard ----
    $keyboard = [];

    $keyboard[] = [
        ['text' => $userId, 'callback_data' => "wizwizch"],
        ['text' => "آیدی کاربر", 'callback_data' => "wizwizch"],
    ];

    $keyboard[] = [
        ['text' => $firstName, 'callback_data' => "wizwizch"],
        ['text' => "اسم کاربر", 'callback_data' => "wizwizch"],
    ];

    $keyboard[] = [
        ['text' => ($agentBought ? "بله" : "خیر"), 'callback_data' => "wizwizch"],
        ['text' => "خرید همکار", 'callback_data' => "wizwizch"],
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

    // change plan + switch location
    $keyboard[] = [
        ['text' => $buttonValues['change_plan'], 'callback_data' => "changePlan_{$id}"],
        ['text' => $buttonValues['change_config_location'], 'callback_data' => "switchLocation{$id}_{$server_id}_{$leftgb}_".$order['expire_date']],
    ];

    if (($botState['renewAutoState'] ?? "off") == "on") {
        $keyboard[] = [[
            'text' => (($autoRenew ?? 'off') == "on" ? "🟢 تمدید خودکار روشن" : "🔴 تمدید خودکار خاموش"),
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
    $keyboard[] = [
        [
            'text' => ($enableBool ? $buttonValues['disable_config'] : $buttonValues['enable_config']),
            'callback_data' => "changeUserConfigState" . $order['id']
        ],
        [
            'text' => $buttonValues['delete_config'],
            'callback_data' => "delUserConfig" . $order['id']
        ],
    ];

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
    global $botState, $mainValues, $buttonValues, $botUrl, $userInfo, $showIndexes;

    $order = findOrder($id, $from_id);
    if (!$order) return null;

    $fid         = $order['fileid'];
    $name        = getPlanFullName($fid, $id);

    $expire_date = jdate("Y-m-d H:i", $order['expire_date']);

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
    $price       = $order['amount'];

    $server      = getServerConfigById($server_id);
    $serverType  = $server['type'] ?? '';
    $panelUrl    = $server['panel_url'] ?? '';
    $subLinksRaw = $server['sub_links'] ?? '';

    $traffic = getOrderTrafficInfo($serverType, $server_id, $remark, $uuid, $inbound_id, $rahgozar);

    $found      = $traffic['found'] ?? false;
    $enableBool = $traffic['enable'] ?? false;
    $leftgb     = $traffic['leftgb'] ?? "⚠️";

    $enableText = $found
        ? ($enableBool ? $buttonValues['active'] : $buttonValues['deactive'])
        : $mainValues['config_doesnt_exist'];

    $configLinks = buildConfigLinks($acc_link, $botState, $showIndexes);
    $subLink     = buildSubLink($serverType, $panelUrl, $botUrl, $token, $botState);
    $backup      = buildSubBackupSection($subLinksRaw, $token);

    $msg = buildConfigMessage($enableText, $remark, $configLinks, $subLink, $backup, $serverType);

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
    if ($serverType != "marzban") {
        $keyboard[] = [
            ['text' => $buttonValues['selected_protocol'], 'callback_data' => "wizwizch"],
        ];
    }

    if (($botState['renewAutoState'] ?? "off") == "on") {
        $keyboard[] = [[
            'text' => (($autoRenew ?? 'off') == "on" ? "🟢 تمدید خودکار روشن" : "🔴 تمدید خودکار خاموش"),
            'callback_data' => "toggleAutoRenew{$id}"
        ]];
    }

    foreach (buildUserProtocolButtons(
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
    if ($found) {
        $temp = [];

        if (
            ($botState['sellState'] ?? "off") == "on" &&
            ($price != 0 || $agentBought == true) &&
            ($botState['renewAccountState'] ?? "off") == "on"
        ) {
            $temp[] = ['text' => $buttonValues['renew_config'], 'callback_data' => "renewAccountAsk{$id}"];
        }

        if (
            ($price != 0 || $agentBought == true) &&
            ($botState['switchLocationState'] ?? "off") == "on" &&
            $rahgozar != true
        ) {
            $temp[] = ['text' => $buttonValues['change_config_location'], 'callback_data' => "switchLocation{$id}_{$server_id}_{$leftgb}_".$order['expire_date']];
        }

        if (!empty($temp)) {
            $keyboard[] = $temp;
        }
    }

    // ---- User Actions
    if ($found) {

        $flatButtons = [];

        if (
            ($botState['sellState'] ?? "off") == "on" &&
            ($botState['increaseVolumeState'] ?? "off") == "on" &&
            ($price != 0 || $agentBought)
        ) {
            $flatButtons[] = ['text' => $buttonValues['increase_config_volume'], 'callback_data' => "increaseAVolume{$id}"];
        }

        if (
            ($botState['sellState'] ?? "off") == "on" &&
            ($botState['increaseTimeState'] ?? "off") == "on" &&
            ($price != 0 || $agentBought)
        ) {
            $flatButtons[] = ['text' => $buttonValues['increase_config_days'], 'callback_data' => "increaseADay{$id}"];
        }

        if (($botState['renewConfigLinkState'] ?? "off") == "on")
            $flatButtons[] = ['text' => $buttonValues['renew_connection_link'], 'callback_data' => "changAccountConnectionLink{$id}"];

        if (($botState['updateConfigLinkState'] ?? "off") == "on")
            $flatButtons[] = ['text' => $buttonValues['update_config_connection'], 'callback_data' => "updateConfigConnectionLink{$id}"];

        $flatButtons[] = ['text' => $buttonValues['change_plan'], 'callback_data' => "changePlan_{$id}"];

        if (($botState['qrConfigState'] ?? "off") == "on")
            $flatButtons[] = ['text' => $buttonValues['qr_config'], 'callback_data' => "showQrConfig{$id}"];

        if (($botState['qrSubState'] ?? "off") == "on")
            $flatButtons[] = ['text' => $buttonValues['qr_sub'], 'callback_data' => "showQrSub{$id}"];

        // همکار enable/disable
        if (!empty($userInfo['is_agent']) && (int)$userInfo['is_agent'] == 1) {
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

