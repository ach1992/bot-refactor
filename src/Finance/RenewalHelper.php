<?php
/** AChBot V8: source-pinned financial compatibility module. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

if (!function_exists('renewConfigOnServer')) {
    function renewConfigOnServer($connection, $server_id, $inbound_id, $uuid, $remark, $days, $volume, $isMarzban, ?int $payId=null)
    {
        list($serverInfo, $serverConfig) = getServerInfoAndConfig($connection, $server_id);
        $serverType = $serverConfig['type'] ?? '';

        if (!achbotSupportsPanel($serverType) || (int)$inbound_id !== 0) {
            throw new \UnexpectedValueException('unsupported_panel');
        }
        $response = achbotApplyServiceEdit($connection,$server_id, [
            'pay_id'=>$payId,'remark' => $remark,
            'days' => $days,
            'volume' => $volume,
        ]);

        return $response;
    }
}

