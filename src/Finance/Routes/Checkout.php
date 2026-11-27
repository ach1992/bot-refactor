<?php
/** AChBot current payment route and shared receipt presentation. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../../messages/loader.php'; // ACHBOT_TEXT_CATALOG

require_once __DIR__ . '/../../Admin/Access.php';
if (!achbotAdminRequestAllowed(is_string($data??null)?$data:null,is_string($text??null)?$text:null,(string)($userInfo['step']??''))) {achbotAdminDeny();return;}

require_once __DIR__ . '/../PaymentLifecycle.php';
if (is_string($data) && strlen($data)<=64 &&
    preg_match('/^showQr(Sub|Config)([1-9][0-9]{0,9})$/D', $data, $match)) {
    $qrSetting=$match[1]==='Sub'?'qrSubState':'qrConfigState';
    if (($botState[$qrSetting]??'off')!=='on') {
        alert(\achbotMessage('payment.checkout.alert.baff5f2488'),true);
        return;
    }
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `userid`=? AND `id`=?");
    $stmt->bind_param("ii", $from_id, $match[2]);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$order || !achbotSupportsOrderObservation($connection, (int)$order['id'],(int)$from_id)) {
        alert(\achbotMessage('payment.checkout.alert.7f1f245514'), true);
        return;
    }

    $stmt=$connection->prepare('SELECT * FROM server_config WHERE id=?');
    $stmt->bind_param('i',$order['server_id']);$stmt->execute();$panel=$stmt->get_result()->fetch_assoc();$stmt->close();
    $presentation=achbotOrderPresentation($connection,$order,$panel?:[],$botState);
    if ($match[1] == "Sub") {
        $subLink=$presentation['qr_subscription_url'];
        if ($subLink==='') {alert(\achbotMessage('payment.checkout.alert.bfa5553444'),true);return;}
        $keys=json_encode(['inline_keyboard'=>[[['text'=>\achbotMessage('legacy_buttons.back_to_main'),'callback_data'=>'mainMenu']]]]);
        if (!sendQrFromLink($subLink,\AchBot\Subscription\Presentation\codeLinks([$subLink]),
            $keys,(string)$botUrl,(int)$from_id))
            alert(\achbotMessage('subscription.presentation.config_qr_delivery_unconfirmed'),true);
    }
    elseif ($match[1] == "Config") {

        $vraylink = $presentation['qr_config_links'];
        if (!$vraylink) {alert(\achbotMessage('payment.checkout.alert.baff5f2488'),true);return;}

        $complete = true;
        foreach ($vraylink as $vray_link) {
            $sent = sendQrFromLink(
                $vray_link,
                \AchBot\Subscription\Presentation\configQrText($vray_link),
                '{}', (string)$botUrl, (int)$from_id, '', null,
                \AchBot\Subscription\Presentation\configQrCaption($vray_link, $presentation)
            );
            $complete = $sent && $complete;
        }
        if (!$complete) alert(\achbotMessage('subscription.presentation.config_qr_delivery_unconfirmed'), true);

    }
}
