<?php
/** AChBot V6: original Telegram account/subscription routes; keep caller scope. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

if (preg_match('/sConfigUpdate(\d+)/', $data, $match)) {
    alert($mainValues['please_wait_message']);
    $server_id = $match[1];
    if (empty($userInfo['temp'])) { delMessage(); exit(); }

    $configInfo = json_decode($userInfo['temp'], true);
    $inboundId = $configInfo['id'] ?? 0;
    $uuid = $configInfo['uuid'];
    $remark = $configInfo['remark'];

    if (isset($configInfo['marzban'])) {
        $info = getMarzbanUserInfo($server_id, $remark);
        $vraylink = $info->links;
    } else {
        $response = getJson($server_id)->obj;
        if ($response == null) { delMessage(); exit(); }

        if ($inboundId == 0) {
            foreach ($response as $row) {
                $clients = json_decode($row->settings)->clients;
                if ($clients[0]->id == $uuid || $clients[0]->password == $uuid) {
                    $port = $row->port;
                    $protocol = $row->protocol;
                    $netType = json_decode($row->streamSettings)->network;
                    break;
                }
            }
        } else {
            foreach ($response as $row) {
                if ($row->id == $inboundId) {
                    $port = $row->port;
                    $protocol = $row->protocol;
                    $netType = json_decode($row->streamSettings)->network;
                    break;
                }
            }
        }

        if ($uuid == null) { delMessage(); exit(); }
        $vraylink = getConnectionLink($server_id, $uuid, $protocol, $remark, $port, $netType, $inboundId);
    }

    if ($vraylink == null) { delMessage(); exit(); }

    include_once dirname(__DIR__, 2) . '/phpqrcode/qrlib.php';

    if (!defined('IMAGE_WIDTH'))  define('IMAGE_WIDTH', 540);
    if (!defined('IMAGE_HEIGHT')) define('IMAGE_HEIGHT', 540);

    foreach ($vraylink as $vray_link) {
        $acc_text = ($botState['configLinkState'] != "off") ? "<code>$vray_link</code>" : ".";

        $ecc = 'L';
        $pixel_Size = 11;
        $frame_Size = 4;

        $file = RandomString() . ".png";
        QRcode::png($vray_link, $file, $ecc, $pixel_Size, $frame_Size);

        sendPhoto($botUrl . $file, $acc_text, null, "HTML");
        unlink($file);
    }
}

