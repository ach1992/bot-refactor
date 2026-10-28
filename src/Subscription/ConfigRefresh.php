<?php
/** Native config refresh obeys the same per-server selection and QR bounds. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG


if (is_string($data) && str_starts_with($data,'sConfigUpdate')) {
    if (!preg_match('/^sConfigUpdate([1-9][0-9]*)$/D',$data,$match)) {
        alert(\achbotMessage('subscription.config_refresh.alert.55dda26482'),true);
        return;
    }
    $server_id=(int)$match[1];
    $configInfo=json_decode((string)($userInfo['temp']??''),true);
    // A callback cannot change the logical server of the persisted account view.
    if (!is_array($configInfo) || (int)($configInfo['server_id']??0)!==$server_id ||
        !is_string($configInfo['remark']??null) || $configInfo['remark']==='') {
        alert(\achbotMessage('subscription.config_refresh.alert.8cc7d4da88'),true);
        return;
    }
    $server=getServerConfigById($server_id);
    $type=(string)($server['type']??'');
    if (!achbotSupportsPanel($type)) {
        alert(\achbotMessage('subscription.config_refresh.alert.05e5654640'),true);
        return;
    }
    alert($mainValues['please_wait_message']);
    $info=achbotProviderUserInfo($server_id,$configInfo['remark']);
    $presentation=buildSubscriptionPresentation($type,[
        'links'=>$info->links??[],
        'subscription_url'=>$info->subscription_url??'',
    ],$botState,[],'view',$server_id);
    if ($presentation['qr_config_links']===[]) {
        sendMessage($presentation['notice_html']!==''?$presentation['notice_html']:
            \achbotMessage('subscription.config_refresh.message.1d7cb7b50c'));
        return;
    }
    $complete = true;
    foreach ($presentation['qr_config_links'] as $uri) {
        $sent = sendQrFromLink($uri,\AchBot\Subscription\Presentation\configQrText($uri),
            '{}',(string)$botUrl,(int)$from_id,'',null,
            \AchBot\Subscription\Presentation\configQrCaption($uri,$presentation));
        $complete = $sent && $complete;
    }
    if (!$complete) alert(\achbotMessage('subscription.presentation.config_qr_delivery_unconfirmed'),true);
    if ($presentation['notice_html']!=='') sendMessage($presentation['notice_html']);
}
