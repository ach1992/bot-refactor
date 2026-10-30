<?php
/** AChBot shared native purchase delivery and QR transport. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }
require_once __DIR__.'/Presentation.php';

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
        $buttonValues,
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
        $presentation = \AchBot\Subscription\Presentation\present(
            $serverType, $native, $botState, $options, 'delivery'
        );
        $vray_link = json_encode($nativeLinks, JSON_INVALID_UTF8_SUBSTITUTE);
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

        $btnText = $buttonValues['start_bot'] ?? ($buttonValues['my_subscriptions'] ?? \achbotMessage('subscription.buy_helpers.build_links_and_send_qr.start.e4bb9f1ece'));
        $u = (string)($botUsername ?? '');

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

        if (!$sendToUser) {
            return [$token, $vray_link, $uniqid, $acc_text, $keys, $qrLink];
        }

        sendQrFromLink((string)$qrLink, $acc_text, $keys, (string)$botUrl,
            (int)$uid, (string)$qrPublicPrefix, $qrFsDir);
        return [$token, $vray_link, $uniqid];
    }
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
        if (!class_exists('QRcode')) require_once dirname(__DIR__, 2).'/phpqrcode/qrlib.php';
        $photoConfirmed = false;
        // Photo and account details are TWO independent Telegram effects.
        // A successful photo cannot certify delivery of the account text.
        if ($qrLink !== '' && class_exists('QRcode')) {
            if (empty($qrFsDir)) $qrFsDir = getcwd();
            $qrFsDir = rtrim($qrFsDir, "/\\");
            $file = RandomString() . ".png";
            $fsPath = $qrFsDir . DIRECTORY_SEPARATOR . $file;
            try {
                QRcode::png($qrLink, $fsPath, 'L', 11, 4);
                $photo = class_exists('CURLFile')
                    ? new CURLFile($fsPath, 'image/png', $file)
                    : $fsPath;
                $resPhoto = sendPhoto($photo, $photoCaption, null, $photoCaption === null ? null : 'HTML', $uid);
                $photoConfirmed = is_object($resPhoto) && ($resPhoto->ok ?? false) === true;
            } catch (Throwable $error) {
                // A network error says nothing about whether Telegram
                // accepted the image; do not repeat automatically.
                error_log('achbot_qr_photo_unconfirmed class='.get_class($error));
            } finally {
                @unlink($fsPath);
            }
        }
        $textConfirmed = false;
        try {
            $textConfirmed = true;
            $parts = \AchBot\Subscription\Presentation\messageParts($acc_text);
            foreach ($parts as $index => $part) {
                $resText = sendMessage($part, $index === count($parts)-1 ? $keys : null, "HTML", $uid);
                if (!is_object($resText) || ($resText->ok ?? false) !== true) $textConfirmed = false;
            }
        } catch (Throwable $error) {
            $textConfirmed = false;
            error_log('achbot_account_text_unconfirmed class='.get_class($error));
        }
        return ($qrLink === '' || $photoConfirmed) && $textConfirmed;
    }
}
