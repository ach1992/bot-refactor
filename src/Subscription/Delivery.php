<?php
/** AChBot shared native purchase delivery and QR transport. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }
require_once __DIR__.'/Presentation.php';


/** Shared pure purchase rendering, without Telegram or a payment attempt. */
function achbotPurchaseRenderPacket(string $provider,array $native,array $botState,array $options,
    string $botUsername,string $remark,$volume,$days): array {
    $presentation = \AchBot\Subscription\Presentation\present(
        $provider, $native, $botState, $options, 'delivery'
    );
    $qrLink = $presentation['qr_link'];
    $safeRemark = \AchBot\Subscription\Presentation\escape($remark);
    $safeVolume = \AchBot\Subscription\Presentation\escape($volume);
    $safeDays = \AchBot\Subscription\Presentation\escape($days);
    $compose = static function(array $presentation) use ($safeRemark, $safeVolume, $safeDays): string {
        $acc_text = \achbotMessage('subscription.buy_helpers.build_links_and_send_qr.acc_text.698f512161', ['safeRemark' => $safeRemark, 'safeVolume' => $safeVolume, 'safeDays' => $safeDays]);
        if ($presentation['config_html'] !== '') {
            $acc_text .= \achbotMessage('subscription.buy_helpers.build_links_and_send_qr.acc_text.2f530a8e37') . $presentation['config_html'] . "\n\n";
        }
        if ($presentation['subscription_html'] !== '') {
            $acc_text .= \achbotMessage('subscription.buy_helpers.build_links_and_send_qr.acc_text.8eb803f6b0') . $presentation['subscription_html'];
        }
        if ($presentation['backup_html'] !== '') {
            $acc_text .= "\n" . $presentation['backup_html'];
        }
        if ($presentation['subscription_url'] !== '') {
            $acc_text .= \achbotMessage('subscription.buy_helpers.build_links_and_send_qr.acc_text.a2bb8da6b7');
            if ($presentation['backup_html'] !== '') {
                $acc_text .= \achbotMessage('subscription.buy_helpers.build_links_and_send_qr.acc_text.d5f471a600');
            }
        }
        if ($presentation['notice_html'] !== '') {
            $acc_text .= "\n\n" . $presentation['notice_html'];
        }

        return $acc_text;
    };
    $acc_text = \AchBot\Subscription\Presentation\fitMessage($presentation, $compose);

    $btnText = \achbotMessage('legacy_buttons.start_bot');
    $u = $botUsername;

    if ($u !== '') {
        $keys = json_encode([
            'inline_keyboard' => [
                [[
                    'text' => $btnText,
                    'url'  => "https://t.me/{$u}?start=mainMenu"
                ]]
            ]
        ], JSON_UNESCAPED_UNICODE);
    } else {
        $keys = json_encode([
            'inline_keyboard' => [
                [[
                    'text' => $btnText,
                    'callback_data' => "mySubscriptions"
                ]]
            ]
        ], JSON_UNESCAPED_UNICODE);
    }

    return ['caption'=>$acc_text,'keys'=>$keys,'qr'=>$qrLink];
}

if (!function_exists('buildLinksAndSendQr')) {
    function buildLinksAndSendQr(
        $serverType,
        $serverConfig,
        $botState,
        $botUrl,
        $server_id,
        $uniqid,
        $protocol,
        $remark,
        $port,
        $netType,
        $inbound_id,
        $rahgozar,
        $customPath,
        $customPort,
        $customSni,
        $response,
        $uid,
        $volume,
        $days,
        $unusedLinkIndices,
        $unusedHideConfigIfMany,
        $qrPublicPrefix = '',
        $qrFsDir = null,
        $sendToUser = true
    ) {
        global $botUsername, $connection;

        $response = is_object($response) ? get_object_vars($response)
            : (is_array($response) ? $response : []);
        $panelUrl = $serverConfig['panel_url'] ?? '';
        $subLinksRaw = $serverConfig['sub_links'] ?? '';
        if (!achbotSupportsPanel($serverType) || (int)$inbound_id !== 0) {
            throw new \UnexpectedValueException('unsupported_panel');
        }
        $backupUrls = [];
        if ($serverType === 'marzban') {
            // Preserve the existing token and URL contract only for Marzban.
            $uniqid = $token = str_replace("/sub/", "", $response['sub_link'] ?? '');
            $subPath = $response['sub_link'] ?? '';
            $mainSubLink = $panelUrl !== '' && $subPath !== '' ? $panelUrl . $subPath : '';
            if ($subPath !== '' && $subLinksRaw !== '') {
                foreach (array_filter(array_map('trim', explode(',', $subLinksRaw))) as $domain) {
                    $backupUrls[] = $domain . $subPath;
                }
            }
        } elseif ($serverType === 'pasarguard') {
            // Native URL is opaque. Never invent a Marzban token or rebuild a
            // vendor config; provider identity is retained in existing service-effect evidence.
            $token = '';
            $mainSubLink = $response['subscription_url'] ?? '';
            $backupUrls = \AchBot\Subscription\Presentation\subscriptionAliases($mainSubLink, $subLinksRaw);
        } else {
            throw new \UnexpectedValueException('unsupported_panel');
        }
        $nativeLinks = $serverType === 'pasarguard'
            ? ($response['links'] ?? []) : ($response['vray_links'] ?? []);
        $native = [
            'links' => $nativeLinks,
            'subscription_url' => $mainSubLink,
            'backup_urls' => $backupUrls,
        ];
        require_once __DIR__ . '/ServerDisplaySettings.php';
        $options = achbotServerDisplayOptions($connection ?? null, (int)$server_id, (string)$serverType);
        $vray_link = json_encode($nativeLinks, JSON_INVALID_UTF8_SUBSTITUTE);
        $packet = achbotPurchaseRenderPacket($serverType,$native,$botState,$options,
            (string)($botUsername??''),(string)$remark,$volume,$days);
        $acc_text=$packet['caption'];$keys=$packet['keys'];$qrLink=$packet['qr'];

        if (!$sendToUser) {
            return [$token, $vray_link, $uniqid, $acc_text, $keys, $qrLink];
        }

        sendQrFromLink((string)$qrLink, $acc_text, $keys, (string)$botUrl,
            (int)$uid, (string)$qrPublicPrefix, $qrFsDir);
        return [$token, $vray_link, $uniqid];
    }
}

/** Freeze the real Telegram message boundaries before the completion commit.
 * No QR for an empty link; the original keyboard belongs to the last text. */
function achbotSubscriptionDeliveryParts(array $packet): array {
    if ($packet['kind']==='text') {
        return [['kind'=>'text','text'=>$packet['text'],'keys'=>null,
            'mode'=>$packet['mode']==='plain'?null:'HTML']];
    }
    if ($packet['kind']!=='qr') throw new InvalidArgumentException('delivery_packet_kind');
    $parts=[];
    if ($packet['qr']!=='') $parts[]=['kind'=>'qr','qr'=>$packet['qr'],'caption'=>null];
    $text=\AchBot\Subscription\Presentation\messageParts($packet['caption']);
    foreach ($text as $index=>$part)
        $parts[]=['kind'=>'text','text'=>$part,
            'keys'=>$index===count($text)-1?$packet['keys']:null,'mode'=>'HTML'];
    return $parts;
}

/** One message effect. Only preparation errors before calling Telegram
 * may be reported as local_failed. Transport exceptions are uncertain. */
function achbotSubscriptionSendQrPart(array $part,int $uid,?string $qrFsDir=null): array {
    require_once __DIR__.'/../Telegram/Response.php';
    if ($part['kind']!=='qr') throw new InvalidArgumentException('delivery_part_kind');
    $fsPath=null;
    try {
        try {
            if (!class_exists('QRcode')) require_once dirname(__DIR__,2).'/phpqrcode/qrlib.php';
            if (!class_exists('QRcode')) throw new RuntimeException('delivery_qr_encoder_missing');
            $dir=$qrFsDir??sys_get_temp_dir();
            $fsPath=@tempnam($dir,'achqr-');
            if ($fsPath===false) throw new RuntimeException('delivery_qr_tempfile_failed');
            QRcode::png($part['qr'],$fsPath,'L',11,4);
            if (!is_file($fsPath) || filesize($fsPath)<=0)
                throw new RuntimeException('delivery_qr_image_missing');
            $file=basename($fsPath).'.png';
            $photo=class_exists('CURLFile')?new CURLFile($fsPath,'image/png',$file):$fsPath;
        } catch (Throwable $error) {
            error_log('achbot_qr_prepare_failed class='.get_class($error));
            // No transport call occurred. Use the existing maintenance
            // deferral interval; do not invent a Telegram rejection code.
            return ['result'=>'local_failed','code'=>null,'retry_after'=>120];
        }
        $photoCaption=$part['caption'];
        $resPhoto=sendPhoto($photo, $photoCaption, null, $photoCaption === null ? null : 'HTML', $uid);
        return \AchBot\Telegram\Response\deliveryOutcome($resPhoto);
    } finally {
        if (is_string($fsPath)) @unlink($fsPath);
    }
}

function achbotSubscriptionSendPart(array $part,int $uid,?string $qrFsDir=null): array {
    if ($part['kind']==='qr') return achbotSubscriptionSendQrPart($part,$uid,$qrFsDir);
    if ($part['kind']!=='text') throw new InvalidArgumentException('delivery_part_kind');
    require_once __DIR__.'/../Telegram/Response.php';
    $text=$part['text'];$keys=$part['keys'];$mode=$part['mode'];
    $res=sendMessage($text,$keys,$mode,$uid);
    return \AchBot\Telegram\Response\deliveryOutcome($res);
}

if (!function_exists('sendQrFromLink')) {
    function sendQrFromLink(
        string $qrLink,
        string $acc_text,
        string $keys,
        string $botUrl,
        int $uid,
        string $qrPublicPrefix = '',
        ?string $qrFsDir = null,
        ?string $photoCaption = null
    ): bool {
        // Retain the historical whole-packet caller's UX and bool contract.
        // Only new journaled service delivery owns individual durable parts.
        $photoConfirmed=$qrLink==='';
        if ($qrLink!=='') {
            try {
                $photoConfirmed=achbotSubscriptionSendPart(
                    ['kind'=>'qr','qr'=>$qrLink,'caption'=>$photoCaption],$uid,
                    empty($qrFsDir)?getcwd():$qrFsDir)['result']==='acknowledged';
            } catch (Throwable $error) {
                error_log('achbot_qr_photo_unconfirmed class='.get_class($error));
            }
        }
        $textConfirmed=false;
        try {
            $textConfirmed=true;
            foreach (achbotSubscriptionDeliveryParts(['kind'=>'qr','qr'=>'',
                'caption'=>$acc_text,'keys'=>$keys]) as $part) {
                if (achbotSubscriptionSendPart($part,$uid)['result']!=='acknowledged')
                    $textConfirmed=false;
            }
        } catch (Throwable $error) {
            $textConfirmed=false;
            error_log('achbot_account_text_unconfirmed class='.get_class($error));
        }
        return $photoConfirmed && $textConfirmed;
    }
}
