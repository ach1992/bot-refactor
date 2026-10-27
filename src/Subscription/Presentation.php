<?php
/** AChBot pure subscription presentation; no bootstrap, database or network. */
namespace AchBot\Subscription\Presentation;

function escape($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Validate an opaque native subscription URL; never infer a vendor path. */
function subscriptionUrl($value): string {
    if (!is_string($value) || preg_match('~[\x00-\x20\x7f<>"\x60]~', $value)) return '';
    $parts = parse_url($value);
    return is_array($parts) && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
        && !empty($parts['host']) && !isset($parts['user']) && !isset($parts['pass'])
        ? $value : '';
}

function codeLinks(array $links): string {
    return implode("\n\n", array_map(static function ($link) {
        return '<code>' . escape($link) . '</code>';
    }, $links));
}

/** Parse native links/links_base64 without generating or rewriting a URI.
 * Tags exist only when an adapter supplies them; a display name is not a tag.
 */
function nativeLinks($input): array {
    if (is_string($input)) {
        $text = trim($input);
        if ($text === '') return [];
        if (!preg_match('~^[a-z][a-z0-9+.-]*://~i', $text)) {
            $decoded = base64_decode(preg_replace('/\s+/', '', $text), true);
            if ($decoded === false) return [];
            $text = trim($decoded);
        }
        $input = preg_split('/\r\n|\n|\r/', $text);
    }
    if (!is_array($input)) return [];
    $out = [];
    foreach ($input as $item) {
        $metadata = is_object($item) ? get_object_vars($item) : (is_array($item) ? $item : []);
        $uri = is_string($item) ? $item : ($metadata['uri'] ?? '');
        if (!is_string($uri)) continue;
        $uri = trim($uri);
        if (!preg_match('~^([a-z][a-z0-9+.-]*)://[^\x00-\x20\x7f<>"\x60]+$~iD', $uri, $match)) continue;
        // The native format may add protocols unknown to the bot. Keep the
        // opaque URI unchanged; this layer neither interprets nor regenerates it.
        $protocol = strtolower($match[1]);
        $name = isset($metadata['name']) && is_string($metadata['name']) ? $metadata['name'] : '';
        if ($name === '' && strpos($uri, '#') !== false) {
            $name = rawurldecode(substr($uri, strpos($uri, '#') + 1));
        }
        if ($name === '' && $protocol === 'vmess') {
            $payload = base64_decode(substr($uri, 8), true);
            $vmess = $payload === false ? null : json_decode($payload, true);
            if (is_array($vmess) && is_string($vmess['ps'] ?? null)) $name = $vmess['ps'];
        }
        $tags = $metadata['tags'] ?? [];
        $tags = is_array($tags) ? array_values(array_filter($tags, 'is_string')) : [];
        $out[] = ['uri' => $uri, 'protocol' => $protocol, 'name' => $name, 'tags' => $tags];
    }
    return $out;
}

/** Preserve the original Marzban array positions, duplicates and index bases. */
function legacyLinks($input): array {
    if (!is_array($input)) return [];
    return array_values(array_map(static function ($item) {
        return is_string($item) ? $item : '';
    }, $input));
}

function selectorMatches(array $record, $selector): bool {
    if (!is_array($selector) || $selector === []) return false;
    foreach ($selector as $field => $value) {
        if (!is_string($value) || $value === '') return false;
        if ($field === 'protocol') {
            if ($record['protocol'] !== strtolower($value)) return false;
        } elseif ($field === 'name') {
            if ($record['name'] !== $value) return false;
        } elseif ($field === 'tag') {
            if (!in_array($value, $record['tags'], true)) return false;
        } else {
            return false;
        }
    }
    return true;
}

/** Reject malformed explicit policies instead of silently exposing all links. */
function validPolicy(array $policy): bool {
    foreach ($policy as $key => $value) {
        if (in_array($key, ['show_configs', 'show_subscription'], true)) {
            if (!is_bool($value)) return false;
        } elseif (in_array($key, ['max_links', 'hide_if_more_than'], true)) {
            if (!is_int($value) || $value < 0) return false;
        } elseif ($key === 'qr') {
            if (!in_array($value, ['auto', 'subscription', 'first_config', 'none'], true)) return false;
        } elseif ($key === 'selectors') {
            if (!is_array($value) || !array_is_list($value)) return false;
            foreach ($value as $selector) {
                if (!is_array($selector) || $selector === []) return false;
                foreach ($selector as $field => $match) {
                    if (!in_array($field, ['protocol', 'name', 'tag'], true)
                        || !is_string($match) || $match === '') return false;
                }
            }
        } else {
            return false;
        }
    }
    return true;
}

/**
 * Neutral renderer contract:
 * native = {links: array|string, subscription_url: absolute URL, backup_urls?:array}
 * options = {policy?: provider-specific array, legacy?: old private settings}
 * An absent Marzban policy enables explicit legacy compatibility by context.
 * A configured policy uses the same selection for view, delivery and config QR.
 * These are display choices only. Panel Groups remain the access authority.
 */
function present(string $provider, array $native, array $botState, array $options = [], string $context = 'view'): array {
    $result = [
        'links' => [], 'config_html' => '', 'subscription_url' => '',
        'subscription_html' => '', 'backup_html' => '', 'qr_link' => '',
        'qr_config_links' => [], 'missing_links' => false,
        'selection_missing' => false, 'reason' => '',
        'notice_html' => '',
    ];
    if (!in_array($provider, ['marzban', 'pasarguard'], true)) {
        $result['reason'] = 'unsupported_provider';
        return $result;
    }
    $url = subscriptionUrl($native['subscription_url'] ?? '');
    $legacy = is_array($options['legacy'] ?? null) ? $options['legacy'] : [];
    $rawPolicy = $options['policy'] ?? null;
    $compat = $provider === 'marzban' && $rawPolicy === null;
    if (($rawPolicy !== null && !is_array($rawPolicy))
        || (is_array($rawPolicy) && !validPolicy($rawPolicy))) {
        $result['reason'] = 'invalid_policy';
        $result['notice_html'] = '🔎 لینک‌های اتصال این اشتراک در حال حاضر در دسترس نیست؛ از بخش اشتراک‌های من دوباره بررسی کنید.';
        return $result;
    }
    $policy = $rawPolicy ?? [];
    $subscriptionVisible = ($botState['subLinkState'] ?? '') === 'on'
        && ($policy['show_subscription'] ?? true) === true;
    $visibleUrl = $subscriptionVisible ? $url : '';
    $configsVisible = ($botState['configLinkState'] ?? '') !== 'off'
        && ($policy['show_configs'] ?? true) === true;
    if ($compat) {
        $all = legacyLinks($native['links'] ?? []);
        $selected = [];
        if ($context === 'delivery') {
            // Original delivery substitutes the subscription URL only when no
            // native configs exist, and falls back to first on missing indices.
            $delivery = $all ?: ($visibleUrl !== '' ? [$visibleUrl] : ['']);
            foreach ((is_array($legacy['linkIndices'] ?? null) ? $legacy['linkIndices'] : []) as $index) {
                $selected[] = is_int($index) || is_string($index) ? ($delivery[$index] ?? $delivery[0]) : $delivery[0];
            }
            if ($selected === []) $selected[] = $delivery[0];
            $selected = array_values(array_filter($selected, static function ($uri) { return $uri !== ''; }));
            if (!empty($legacy['hideConfigIfMany']) && count($delivery) > 10) $configsVisible = false;
            $target = $delivery[1] ?? $delivery[0];
            $qr = count($delivery) <= 3 && $target !== '' ? $target : $visibleUrl;
            $result['qr_link'] = $qr !== '' ? $qr : $delivery[0];
        } else {
            // Original view uses one-based showIndexes only above three links.
            foreach ($all as $i => $link) {
                if (count($all) <= 3 || in_array($i + 1, is_array($legacy['showIndexes'] ?? null) ? $legacy['showIndexes'] : [])) $selected[] = $link;
            }
            if (!empty($legacy['hideConnectLinkForMarzban'])) $configsVisible = false;
            $result['qr_link'] = $visibleUrl;
        }
        // Existing explicit config QR actions include every Marzban config,
        // even when inline text is hidden. Preserve this intentional boundary.
        $result['qr_config_links'] = array_values(array_filter($all, static function ($uri) { return $uri !== ''; }));
        $result['missing_links'] = $all === [];
    } else {
        $records = nativeLinks($native['links'] ?? []);
        $selected = [];
        if (array_key_exists('selectors', $policy)) {
            if (is_array($policy['selectors'])) {
                // Selector order first, native order within each selector.
                foreach ($policy['selectors'] as $selector) {
                    foreach ($records as $record) {
                        if (selectorMatches($record, $selector)) $selected[] = $record['uri'];
                    }
                }
            }
            $result['selection_missing'] = $selected === [] && $policy['selectors'] !== [];
        } else {
            $selected = array_column($records, 'uri');
        }
        $selected = array_values(array_unique($selected));
        if (isset($policy['max_links']) && is_int($policy['max_links']) && $policy['max_links'] >= 0) {
            $selected = array_slice($selected, 0, $policy['max_links']);
        }
        if (isset($policy['hide_if_more_than']) && is_int($policy['hide_if_more_than'])
            && count($records) > $policy['hide_if_more_than']) $configsVisible = false;
        $result['missing_links'] = $records === [];
        $result['qr_config_links'] = $configsVisible ? $selected : [];
        $qrMode = $policy['qr'] ?? 'auto';
        if ($qrMode === 'auto') {
            $result['qr_link'] = $visibleUrl !== '' ? $visibleUrl : ($result['qr_config_links'][0] ?? '');
        } elseif ($qrMode === 'subscription') {
            $result['qr_link'] = $visibleUrl;
        } elseif ($qrMode === 'first_config') {
            $result['qr_link'] = $result['qr_config_links'][0] ?? '';
        }
    }
    $result['links'] = $configsVisible ? $selected : [];
    $result['config_html'] = codeLinks($result['links']);
    $result['subscription_url'] = $visibleUrl;
    $result['subscription_html'] = $visibleUrl !== '' ? codeLinks([$visibleUrl]) : '';
    $backups = [];
    if ($subscriptionVisible && is_array($native['backup_urls'] ?? null)) {
        foreach ($native['backup_urls'] as $backup) {
            if (is_string($backup) && $backup !== $url && subscriptionUrl($backup) !== '') $backups[] = $backup;
        }
    }
    if ($backups !== []) $result['backup_html'] = "\n🔁 لینک کمکی اشتراک:\n" . codeLinks(array_values(array_unique($backups)));
    if ($result['selection_missing']) $result['reason'] = 'selection_missing';
    elseif ($result['missing_links']) $result['reason'] = 'links_unavailable';
    if ($result['reason'] !== '' && $result['links'] === [] && $visibleUrl === '') {
        $result['notice_html'] = '🔎 لینک‌های اتصال این اشتراک در حال حاضر در دسترس نیست؛ از بخش اشتراک‌های من دوباره بررسی کنید.';
    }
    return $result;
}

function configLinks($acc_link, $botState, $showIndexes) {
    return present('marzban', ['links' => $acc_link], $botState,
        ['legacy' => ['showIndexes' => $showIndexes]])['config_html'];
}

function primaryLink($serverType, $panelUrl, $botUrl, $token, $botState) {
    if (($botState['subLinkState'] ?? '') !== 'on') return '';
    if ($serverType !== 'marzban') return '';
    return codeLinks([rtrim($panelUrl, '/') . "/sub/$token"]);
}

function backupLinks($subRaw, $token) {
    if (empty($subRaw)) return '';
    $links = [];
    foreach (array_filter(array_map('trim', explode(',', $subRaw))) as $domain) {
        $links[] = rtrim($domain, '/') . "/sub/$token";
    }
    return "\n🔁 لینک کمکی اشتراک:\n" . codeLinks($links);
}

function configMessage($template, $enableText, $remark, $configLinks, $subLink, $backupSection, bool $hideConfigs = false): string {
    if ($hideConfigs || $configLinks === '') {
        $template = str_replace("\n🔌 لینک تکی کانفیگ:\nCONNECT-LINK\n", '', $template);
        if ($hideConfigs) $configLinks = '';
    }
    $msg = strtr($template, [
        'STATE' => escape($enableText), 'NAME' => escape($remark),
        'CONNECT-LINK' => $configLinks, 'SUB-LINK' => $subLink,
    ]);
    $msg .= $backupSection;
    return $msg . "\n\n♻️ در صورتی که قصد دارید حجم دیگری خریداری کنید می توانید از دکمه ← 🔄 <b>تغییر پلن</b> استفاده و بعد تمدید کنید.";
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
