<?php
/**
 * AchBot presentation helpers. No bootstrap, database or network effects.
 * Legacy coercions, strings and edge behavior are intentional in V1.
 */
namespace AchBot\Subscription\Presentation;

function configLinks($acc_link, $botState, $showIndexes) {

    if ($botState['configLinkState'] == "off") return "";

    if (!is_array($acc_link)) $acc_link = [];

    $total = count($acc_link);
    $out = [];

    if ($total <= 3) {
        foreach ($acc_link as $link) {
            $out[] = "<code>$link</code>";
        }
    } else {
        $i = 1;
        foreach ($acc_link as $link) {
            if (in_array($i, $showIndexes)) {
                $out[] = "<code>$link</code>";
            }
            $i++;
        }
    }

    return implode("\n\n", $out);
}

function primaryLink($serverType, $panelUrl, $botUrl, $token, $botState) {
    if ($botState['subLinkState'] != "on") return "";

    if ($serverType == "marzban") {
        return "<code>" . rtrim($panelUrl, '/') . "/sub/$token</code>";
    }

    return "<code>{$botUrl}settings/subLink.php?token=$token</code>";
}

function backupLinks($subRaw, $token) {
    if (empty($subRaw)) return "";

    $domains = array_filter(array_map('trim', explode(',', $subRaw)));
    $links = [];

    foreach ($domains as $d) {
        $links[] = "<code>" . rtrim($d, '/') . "/sub/$token</code>";
    }

    return "\n🔁 لینک کمکی اشتراک:\n" . implode("\n\n", $links);
}

function formatTraffic($amount){
    $gb = $amount / (1024 * 1024 * 1024);
    if($gb > 1){
      return round($gb,2) . " گیگابایت";
    }
    else{
        $gb *= 1024;
        return round($gb,2) . " مگابایت";
    }

}

function trafficInGigabytes($amount){
    $gb = $amount / (1024 * 1024 * 1024);
    return round($gb,2);
}
