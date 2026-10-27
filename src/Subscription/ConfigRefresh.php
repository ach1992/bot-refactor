<?php
/** Native config refresh obeys the same per-server selection and QR bounds. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }

if (is_string($data) && str_starts_with($data,'sConfigUpdate')) {
    if (!preg_match('/^sConfigUpdate([1-9][0-9]*)$/D',$data,$match)) {
        alert('⛔️ این دکمه معتبر نیست؛ اشتراک را دوباره باز کنید.',true);
        return;
    }
    $server_id=(int)$match[1];
    $configInfo=json_decode((string)($userInfo['temp']??''),true);
    // A callback cannot change the logical server of the persisted account view.
    if (!is_array($configInfo) || (int)($configInfo['server_id']??0)!==$server_id ||
        !is_string($configInfo['remark']??null) || $configInfo['remark']==='') {
        alert('⛔️ اطلاعات اشتراک را دوباره باز کنید.',true);
        return;
    }
    $server=getServerConfigById($server_id);
    $type=(string)($server['type']??'');
    if (!achbotSupportsPanel($type)) {
        alert('⛔️ به‌روزرسانی اطلاعات این اشتراک در حال حاضر امکان‌پذیر نیست.',true);
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
            '🔎 کانفیگی برای نمایش انتخاب نشده است؛ لینک اشتراک را در اطلاعات اشتراک بررسی کنید.');
        return;
    }
    foreach ($presentation['qr_config_links'] as $uri) {
        sendQrFromLink($uri,\AchBot\Subscription\Presentation\codeLinks([$uri]),
            '{}',(string)$botUrl,(int)$from_id);
    }
    if ($presentation['notice_html']!=='') sendMessage($presentation['notice_html']);
}
