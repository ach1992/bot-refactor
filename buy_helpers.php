<?php

require_once __DIR__ . '/src/Purchase/Core.php';

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
        $linkIndices,
        $hideConfigIfMany,
        $qrPublicPrefix = '',
        $qrFsDir = null,
        $sendToUser = true
    ) {
        global $botUsername;

        // include qr lib
        $qrlibCandidates = [
            __DIR__ . '/phpqrcode/qrlib.php',
            __DIR__ . '/../phpqrcode/qrlib.php',
            'phpqrcode/qrlib.php',
            '../phpqrcode/qrlib.php',
        ];
        $qrlibIncluded = false;
        foreach ($qrlibCandidates as $p) {
            if (file_exists($p)) {
                include_once $p;
                $qrlibIncluded = true;
                break;
            }
        }
        if (!$qrlibIncluded && !class_exists('QRcode')) {
            @include_once 'phpqrcode/qrlib.php';
        }

        $panelUrl    = $serverConfig['panel_url']  ?? '';
        $subLinksRaw = $serverConfig['sub_links'] ?? '';

        if (!achbotSupportsPanel($serverType) || (int)$inbound_id !== 0) {
            // Marzban-only: never deliver an unsupported VPN account link.
            throw new \UnexpectedValueException('unsupported_panel');
        }


            // sub_link مثال: /sub/xxxxx
            $uniqid = $token = str_replace("/sub/", "", $response->sub_link ?? '');

            $mainSubLink  = "";
            $subLinkArray = [];

            if (($botState['subLinkState'] ?? '') === "on") {
                if (!empty($panelUrl) && !empty($response->sub_link)) {
                    $mainSubLink = $panelUrl . $response->sub_link;
                }
                if (!empty($subLinksRaw) && !empty($response->sub_link)) {
                    $subDomains = array_filter(array_map('trim', explode(',', $subLinksRaw)));
                    foreach ($subDomains as $sub) {
                        $subLinkArray[] = $sub . $response->sub_link;
                    }
                }
            }

            if (isset($response->vray_links) && !empty($response->vray_links)) {
                $vraylink = $response->vray_links;
            } elseif (!empty($mainSubLink)) {
                $vraylink = [$mainSubLink];
            } else {
                $vraylink = [];
            }

            $vray_link = json_encode($response->vray_links ?? []);

        if (empty($vraylink)) {
            if (!empty($mainSubLink)) {
                $vraylink = [$mainSubLink];
            } else {
                $vraylink = [''];
            }
        }

        $linkCount   = count($vraylink);
        $targetIndex = 1;
        $link        = $vraylink[$targetIndex] ?? $vraylink[0];

        $qrLink = ($linkCount <= 3 && !empty($link)) ? $link : $mainSubLink;
        if (empty($qrLink)) $qrLink = $vraylink[0];

        $selectedLinks = [];
        foreach ($linkIndices as $index) {
            $selectedLinks[] = $vraylink[$index] ?? $vraylink[0];
        }
        if (empty($selectedLinks)) $selectedLinks[] = $vraylink[0];

        $acc_text = "
🛍 <b>اطلاعات اشتراک جدید شما</b>

🎈 نام کاربری اشتراک:
<code>$remark</code>
🔋 حجم اشتراک: $volume گیگ
⏰ مدت اشتراک: $days روز\n
";

        if (
            (($botState['configLinkState'] ?? '') !== "off") &&
            !empty($link) &&
            (!$hideConfigIfMany || $linkCount <= 10)
        ) {
            $acc_text .= "🔌 لینک تکی کانفیگ:\n";
            foreach ($selectedLinks as $singleLink) {
                if (!empty($singleLink)) {
                    $acc_text .= "<code>$singleLink</code>\n\n";
                }
            }
        }

        if (!empty($mainSubLink)) {
            $acc_text .= "🌐 لینک اصلی اشتراک:
<code>$mainSubLink</code>";
        }

        if (!empty($subLinkArray)) {
            $acc_text .= "\n\n🌐 لینک کمکی اشتراک:\n";
            $acc_text .= "<code>" . implode("</code>\n\n<code>", $subLinkArray) . "</code>";
        }

        $acc_text .= "

💡 همیشه اکانت را با لینک اشتراک وارد کنید تا به همه کانفیگ ها دسترسی داشته باشد.
⚠️ در صورتی که لینک اصلی اشتراک کار نکرد از لینک کمکی اشتراک استفاده کنید.";

        $btnText = $buttonValues['start_bot'] ?? ($buttonValues['my_subscriptions'] ?? 'Start');
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

        if (empty($qrFsDir)) $qrFsDir = getcwd();
        $qrFsDir = rtrim($qrFsDir, "/\\");
        $file = RandomString() . ".png";
        $fsPath = $qrFsDir . DIRECTORY_SEPARATOR . $file;

        $ecc        = 'L';
        $pixel_Size = 11;
        $frame_Size = 4;

        if (!class_exists('QRcode')) {
            sendMessage($acc_text, $keys, "HTML", $uid);
            return [$token, $vray_link, $uniqid];
        }

        QRcode::png($qrLink, $fsPath, $ecc, $pixel_Size, $frame_Size);

        $photo = class_exists('CURLFile')
            ? new CURLFile($fsPath, 'image/png', $file)
            : $fsPath;

        $resPhoto = sendPhoto($photo, null, null, null, $uid);

        if ($resPhoto && isset($resPhoto->ok) && $resPhoto->ok === true) {
            @unlink($fsPath);
        }

        sendMessage($acc_text, $keys, "HTML", $uid);
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
        ?string $qrFsDir = null
    ): bool {
        $qrlibCandidates = [
            __DIR__ . '/phpqrcode/qrlib.php',
            __DIR__ . '/../phpqrcode/qrlib.php',
            'phpqrcode/qrlib.php',
            '../phpqrcode/qrlib.php',
        ];
        $qrlibIncluded = false;
        foreach ($qrlibCandidates as $p) {
            if (file_exists($p)) {
                include_once $p;
                $qrlibIncluded = true;
                break;
            }
        }
        if (!$qrlibIncluded && !class_exists('QRcode')) {
            @include_once 'phpqrcode/qrlib.php';
        }
        if (!class_exists('QRcode')) {
            sendMessage($acc_text, $keys, "HTML", $uid);
            return false;
        }

        if (empty($qrFsDir)) $qrFsDir = getcwd();
        $qrFsDir = rtrim($qrFsDir, "/\\");
        $file = RandomString() . ".png";
        $fsPath = $qrFsDir . DIRECTORY_SEPARATOR . $file;

        $ecc        = 'L';
        $pixel_Size = 11;
        $frame_Size = 4;

        QRcode::png($qrLink, $fsPath, $ecc, $pixel_Size, $frame_Size);

        $photo = class_exists('CURLFile')
            ? new CURLFile($fsPath, 'image/png', $file)
            : $fsPath;

        $resPhoto = sendPhoto($photo, null, null, null, $uid);

        $ok = ($resPhoto && isset($resPhoto->ok) && $resPhoto->ok === true);
        @unlink($fsPath);
        sendMessage($acc_text, $keys, "HTML", $uid);

        return $ok;
    }
}

require_once __DIR__ . '/src/Purchase/OrderWrites.php';

require_once __DIR__ . '/src/Finance/ReferralReward.php';
require_once __DIR__ . '/src/Finance/RenewalHelper.php';
require_once __DIR__ . '/src/Finance/WalletSettlement.php';
require_once __DIR__ . '/src/Purchase/AgentPricing.php';

if (!function_exists('createIncreaseOrder')) {
    function createIncreaseOrder(
        $connection,
        $uid,
        $server_id,
        $inbound_id,
        $remark,
        $amount,
        $date,
        $volume = null,
        $day = null
    ) {
        $stmt = $connection->prepare("
            INSERT INTO `increase_order`
                (`userid`, `server_id`, `inbound_id`, `remark`, `amount`, `date`, `volume`, `day`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            "iiisiiii",
            $uid,
            $server_id,
            $inbound_id,
            $remark,
            $amount,
            $date,
            $volume,
            $day
        );
        $stmt->execute();
        $stmt->close();
    }
}

require_once __DIR__ . '/src/Finance/ReceiptHelpers.php';
?>