<?php
/** AChBot V8: source-pinned financial compatibility module. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

if (!function_exists('renewConfigOnServer')) {
    function renewConfigOnServer($connection, $server_id, $inbound_id, $uuid, $remark, $days, $volume, $isMarzban, ?int $payId=null, ?array $originalTarget=null)
    {
        list($serverInfo, $serverConfig) = getServerInfoAndConfig($connection, $server_id);
        $serverType = $serverConfig['type'] ?? '';
        // Prevent provider-type substitution after Checkout's Wallet check.
        if ($originalTarget!==null && $serverType!=='marzban')
            throw new RuntimeException('wallet_renew_provider_changed');

        if (!achbotSupportsPanel($serverType) || (int)$inbound_id !== 0) {
            throw new \UnexpectedValueException('unsupported_panel');
        }
        $change=['pay_id'=>$payId,'remark'=>$remark,
            'days'=>$days,'volume'=>$volume];
        if ($originalTarget!==null) {
            if (($originalTarget['provider']??null)!=='marzban' ||
                (int)($originalTarget['server_id']??0)!==(int)$server_id ||
                (int)($originalTarget['pay_id']??0)!==$payId ||
                (string)($originalTarget['remark']??'')!==(string)$remark ||
                (int)($originalTarget['days']??-1)!==(int)$days ||
                (int)($originalTarget['volume']??-1)!==(int)$volume ||
                (string)($originalTarget['panel_url']??'')==='')
                throw new RuntimeException('wallet_renew_original_target_invalid');
            $change['_wallet_renew_target']=$originalTarget;
        }
        $response = achbotApplyServiceEdit($connection,$server_id,$change);

        return $response;
    }
}

