<?php
/** AChBot V8: source-pinned financial compatibility module. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

if (!function_exists('renewConfigOnServer')) {
    function renewConfigOnServer($connection, $server_id, $inbound_id, $uuid, $remark, $days, $volume, $isMarzban)
    {
        list($serverInfo, $serverConfig) = getServerInfoAndConfig($connection, $server_id);
        $serverType = $serverConfig['type'] ?? '';

        if ($isMarzban || $serverType === 'marzban') {
            $response = editMarzbanConfig($server_id, [
                'remark' => $remark,
                'days'   => $days,
                'volume' => $volume,
            ]);
        } else {
            if ((int)$inbound_id > 0) {
                $response = editClientTraffic($server_id, $inbound_id, $uuid, $volume, $days, "renew");
            } else {
                $response = editInboundTraffic($server_id, $uuid, $volume, $days, "renew");
            }
        }

        return $response;
    }
}

