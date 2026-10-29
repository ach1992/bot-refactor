<?php
/** AChBot pure subscription presentation; no bootstrap, database or network. */
namespace AchBot\Subscription\Presentation;
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG



function escape($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Validate an opaque native subscription URL; never infer a vendor path. */
function subscriptionUrl($value): string {
    if (!is_string($value) || preg_match('//u',$value)!==1 || strlen($value)>1600 || preg_match('~[\x00-\x20\x7f<>"\x60]~', $value)) return '';
    $parts = parse_url($value);
    return is_array($parts) && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
        && !empty($parts['host']) && !isset($parts['user']) && !isset($parts['pass'])
        ? $value : '';
}

/**
 * Configured alternate origins for an observed native subscription URL.
 * Preserve its opaque path, query and fragment; never fetch or rewrite the
 * primary URL, its bearer bytes, or any config URI.
 */
function subscriptionAliases($nativeUrl, $configuredBases): array {
    $native = subscriptionUrl($nativeUrl);
    if ($native === '' || !is_string($configuredBases)) return [];
    $parts = parse_url($native);
    $nativePath = (string)($parts['path'] ?? '/');
    if ($nativePath === '') $nativePath = '/';
    $tail = (array_key_exists('query', $parts) ? '?' . $parts['query'] : '')
        . (array_key_exists('fragment', $parts) ? '#' . $parts['fragment'] : '');
    $out = [];
    foreach (array_filter(array_map('trim', explode(',', $configuredBases))) as $base) {
        if (subscriptionUrl($base) === '') continue;
        $alias = parse_url($base);
        if (isset($alias['query']) || isset($alias['fragment']) ||
            str_contains($alias['host'], '*')) continue;
        $prefix = rtrim((string)($alias['path'] ?? ''), '/');
        // An alternate may already include the native deployment prefix.
        $path = $prefix !== '' && ($nativePath === $prefix || str_starts_with($nativePath, $prefix . '/'))
            ? $nativePath : $prefix . $nativePath;
        $url = $alias['scheme'] . '://' . $alias['host']
            . (isset($alias['port']) ? ':' . $alias['port'] : '') . $path . $tail;
        if ($url !== $native && subscriptionUrl($url) !== '') $out[] = $url;
    }
    return array_values(array_unique($out));
}

function codeLinks(array $links): string {
    return implode("\n\n", array_map(static function ($link) {
        return '<code>' . escape($link) . '</code>';
    }, $links));
}

/** A short native display name accompanies its QR; the URI remains unchanged. */
function configQrCaption(string $uri, array $presentation = []): string {
    $record = nativeLinks([$uri])[0] ?? [];
    $name = $presentation['qr_config_names'][$uri] ?? ($record['name'] ?? '');
    if (!is_string($name) || trim($name) === '') $name = strtoupper($record['protocol'] ?? '');
    // Keep the photo caption small even when an encoded URI fragment is long.
    // Do not shorten, decode or reconstruct the separate copyable URI.
    if (preg_match('/^.{0,200}/us', $name, $match) === 1) {
        $name = $match[0] . ($match[0] !== $name ? \achbotMessage('subscription.presentation.config_qr_name_suffix') : '');
    } else $name = strtoupper($record['protocol'] ?? '');
    return escape($name);
}

function configQrText(string $uri): string {
    return '<pre><code>' . escape($uri) . '</code></pre>';
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
        if (!is_string($uri) || preg_match('//u',$uri)!==1 || strlen($uri)>2048) continue;
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
        if (in_array($key, ['show_configs', 'show_subscription', 'show_auxiliary'], true)) {
            if (!is_bool($value)) return false;
        } elseif (in_array($key, ['max_links', 'hide_if_more_than'], true)) {
            if (!is_int($value) || $value < 0) return false;
        } elseif ($key === 'qr') {
            if (!in_array($value, ['auto', 'subscription', 'first_config', 'none'], true)) return false;
        } elseif (in_array($key, ['selectors', 'delivery_selectors', 'view_selectors'], true)) {
            if (!is_array($value) || !array_is_list($value)) return false;
            foreach ($value as $selector) {
                if (!is_array($selector) || $selector === []) return false;
                foreach ($selector as $field => $match) {
                    if (!in_array($field, ['protocol', 'name', 'tag'], true)
                        || !is_string($match) || $match === '') return false;
                }
            }
        } elseif (in_array($key, ['delivery', 'view'], true)) {
            if (!is_array($value) || !validContext($value, $key)) return false;
        } else {
            return false;
        }
    }
    return true;
}

/** New simple controls share existing per-server storage; old policies remain readable. */
function validContext(array $value, string $context): bool {
    foreach ($value as $key => $item) {
        if (in_array($key, ['show_configs', 'show_subscription', 'show_auxiliary'], true)) {
            if (!is_bool($item)) return false;
        } elseif ($key === 'qr' && $context === 'delivery') {
            if (!in_array($item, ['subscription', 'first_config', 'none'], true)) return false;
        } elseif ($key === 'names') {
            if ($item === null) continue; // Explicit automatic first three.
            if (!is_array($item) || !array_is_list($item) || count($item) > 10) return false;
            foreach ($item as $name)
                if (!is_string($name) || trim($name) === '' || strlen($name) > 300 ||
                    preg_match('/[\\x00-\\x1f\\x7f]/', $name) || preg_match('//u', $name) !== 1) return false;
            if (count(array_unique($item)) !== count($item)) return false;
        } else return false;
    }
    return true;
}

/** Read compatibility only: never rewrite a stored document during rendering. */
function contextPolicy(array $policy, string $context): array {
    $context = $context === 'delivery' ? 'delivery' : 'view';
    $local = $policy[$context] ?? [];
    unset($policy['delivery'], $policy['view']);
    if (array_key_exists('names', $local)) {
        unset($policy['selectors'], $policy['delivery_selectors'], $policy['view_selectors'],
            $policy['hide_if_more_than']);
        $policy['max_links'] = $local['names'] === null ? 3 : 10;
        if ($local['names'] !== null)
            $policy['selectors'] = array_map(static fn($name) => ['name'=>$name], $local['names']);
    }
    foreach (['show_configs', 'show_subscription', 'show_auxiliary', 'qr'] as $key)
        if (array_key_exists($key, $local)) $policy[$key] = $local[$key];
    // Retire the former automatic/fallback choice in the three-state UI.
    if (($policy['qr']??'auto')==='auto') $policy['qr']='subscription';
    return $policy;
}

/**
 * Neutral renderer contract:
 * native = {links: array|string, subscription_url: absolute URL, backup_urls?:array}
 * options = {policy?: array, mode?: native|marzban_legacy, legacy?: managed values}
 * Legacy compatibility requires an explicit validated per-server mode.
 * Native context selectors override shared selectors, including an empty list.
 * Unconfigured text selects the first three native configs. Explicit order QR
 * keeps every native config independently of text visibility/selection/limits.
 * These are display choices only. Panel Groups remain the access authority.
 */
function present(string $provider, array $native, array $botState, array $options = [], string $context = 'view'): array {
    require_once __DIR__.'/../Panel/ProviderRuntime.php';
    $result = [
        'links' => [], 'config_html' => '', 'subscription_url' => '',
        'subscription_html' => '', 'backup_html' => '', 'qr_link' => '',
        'qr_config_links' => [], 'qr_config_names' => [], 'qr_subscription_url' => '', 'missing_links' => false,
        'selection_missing' => false, 'reason' => '',
        'notice_html' => '', 'limited' => false,
    ];
    if (!\achbotSupportsProviderType($provider)) {
        $result['reason'] = 'unsupported_provider';
        return $result;
    }
    $url = subscriptionUrl($native['subscription_url'] ?? '');
    $legacy = is_array($options['legacy'] ?? null) ? $options['legacy'] : [];
    $rawPolicy = $options['policy'] ?? null;
    $mode = $options['mode'] ?? 'native';
    $compat = $provider === 'marzban' && $mode === 'marzban_legacy';
    if (!in_array($mode, ['native', 'marzban_legacy'], true)
        || ($mode === 'marzban_legacy' && $provider !== 'marzban')
        || ($rawPolicy !== null && !is_array($rawPolicy))
        || (is_array($rawPolicy) && !validPolicy($rawPolicy))) {
        $result['reason'] = 'invalid_policy';
        $result['notice_html'] = \achbotMessage('subscription.presentation.present.text.d60293ba07');
        return $result;
    }
    $policy = contextPolicy($rawPolicy ?? [], $context);
    // Explicit name editing retires positional selection for this context only.
    if (array_key_exists('names', ($rawPolicy ?? [])[$context] ?? [])) $compat = false;
    $result['qr_subscription_url'] = $url;
    $subscriptionVisible = ($policy['show_subscription'] ?? true) === true;
    $visibleUrl = $subscriptionVisible ? $url : '';
    $configsVisible = ($policy['show_configs'] ?? true) === true;
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
        } else {
            // Original view uses one-based showIndexes only above three links.
            foreach ($all as $i => $link) {
                if (count($all) <= 3 || in_array($i + 1, is_array($legacy['showIndexes'] ?? null) ? $legacy['showIndexes'] : [])) $selected[] = $link;
            }
            if (!empty($legacy['hideConnectLinkForMarzban'])) $configsVisible = false;
        }
        // Existing explicit config QR actions include every Marzban config,
        // even when inline text is hidden. Preserve this intentional boundary.
        $result['qr_config_links'] = array_values(array_filter($all, static function ($uri) { return $uri !== ''; }));
        $result['missing_links'] = $all === [];
    } else {
        $records = nativeLinks($native['links'] ?? []);
        $selected = [];
        $contextKey = $context === 'delivery' ? 'delivery_selectors' : 'view_selectors';
        $selectorKey = array_key_exists($contextKey, $policy) ? $contextKey : 'selectors';
        if (array_key_exists($selectorKey, $policy)) {
            // Selector order first, native order within each selector.
            foreach ($policy[$selectorKey] as $selector) {
                foreach ($records as $record) {
                    if (selectorMatches($record, $selector)) $selected[] = $record['uri'];
                }
            }
            $result['selection_missing'] = $selected === [] && $policy[$selectorKey] !== [];
        } else {
            $selected = array_column($records, 'uri');
        }
        $selected = array_values(array_unique($selected));
        $hasSelectors = array_key_exists($selectorKey, $policy);
        $policy['max_links'] = min(10, $policy['max_links'] ?? ($hasSelectors ? 10 : 3));
        if (isset($policy['max_links']) && is_int($policy['max_links']) && $policy['max_links'] >= 0) {
            if ($policy['max_links'] > 0 && count($selected) > $policy['max_links']) $result['limited'] = true;
            $selected = array_slice($selected, 0, $policy['max_links']);
        }
        if (isset($policy['hide_if_more_than']) && is_int($policy['hide_if_more_than'])
            && count($records) > $policy['hide_if_more_than']) $configsVisible = false;
        $result['missing_links'] = $records === [];
        $result['qr_config_links'] = $configsVisible ? $selected : [];

    }
    // QR actions are explicit and independent from inline-text selection/budgets.
    // Every accepted native URI is sent separately by the authorized order action.
    $result['qr_config_links'] = array_column(nativeLinks($native['links'] ?? []), 'uri');
    $qrMode=$policy['qr']??'subscription';
    if ($qrMode==='subscription') $result['qr_link']=$url;
    elseif ($qrMode==='first_config') $result['qr_link']=$selected[0]??'';
    if (strlen($result['qr_link']) > 2048) $result['qr_link'] = '';
    foreach (nativeLinks($native['links'] ?? []) as $record) {
        if (in_array($record['uri'], $result['qr_config_links'], true) && $record['name'] !== '' &&
            !isset($result['qr_config_names'][$record['uri']]))
            $result['qr_config_names'][$record['uri']] = $record['name'];
    }
    $result['links'] = $configsVisible ? $selected : [];
    $result['config_html'] = codeLinks($result['links']);
    $result['subscription_url'] = $visibleUrl;
    $result['subscription_html'] = $visibleUrl !== '' ? codeLinks([$visibleUrl]) : '';
    $backups = [];
    if (($policy['show_auxiliary'] ?? true) && is_array($native['backup_urls'] ?? null)) {
        foreach ($native['backup_urls'] as $backup) {
            if (is_string($backup) && $backup !== $url && subscriptionUrl($backup) !== '') $backups[] = $backup;
        }
    }
    if ($backups !== []) {
        $backups = array_values(array_unique($backups));
        $result['backup_html'] = \achbotMessage('subscription.presentation.present.text.3ff0456031') . codeLinks($backups);
    }
    if ($result['selection_missing']) $result['reason'] = 'selection_missing';
    elseif ($result['missing_links']) $result['reason'] = 'links_unavailable';
    if ($result['reason'] !== '' && $result['links'] === [] && $visibleUrl === '') {
        $result['notice_html'] = \achbotMessage('subscription.presentation.present.text.d60293ba07');
    }
    if ($result['limited']) {
        $result['reason'] = 'display_limit';
        $result['notice_html'] = \achbotMessage('subscription.presentation.present.text.6b058a11c6');
    }
    return $result;
}

/** Telegram counts parsed text; UTF-16 units are conservative for astral emoji. */
function textLength(string $html): int {
    $plain = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $count = preg_match_all('/./us', $plain);
    $astral = preg_match_all('/[\x{10000}-\x{10FFFF}]/u', $plain);
    return $count === false || $astral === false ? PHP_INT_MAX : $count + $astral;
}

/** Reserve every protected fact/link first, then remove whole trailing configs. */
function fitMessage(array &$presentation, callable $compose): string {
    $text = $compose($presentation);
    while (textLength($text) > 4096 && !empty($presentation['links'])) {
        array_pop($presentation['links']);
        $presentation['config_html'] = codeLinks($presentation['links']);
        $presentation['limited'] = true;
        $presentation['notice_html'] = \achbotMessage('subscription.presentation.present.text.6b058a11c6');
        $text = $compose($presentation);
    }
    return $text;
}

/** Split protected overflow without breaking entities, markup or native code URIs.
 * Ordinary messages remain byte-identical. Native code blocks are <=2048 bytes.
 */
function messageParts(string $html, int $limit = 4096): array {
    if (preg_match('//u',$html)!==1) throw new \UnexpectedValueException('display_text_invalid');
    if (textLength($html) <= $limit) return [$html];
    preg_match_all('~<pre\b[^>]*>.*?</pre>|<code\b[^>]*>.*?</code>|<[^>]*>|&(?:\#[0-9]+|\#x[0-9a-f]+|[a-z][a-z0-9]+);|.~isu', $html, $matches);
    $tokens = [];
    foreach ($matches[0] as $token) {
        if (textLength($token) > $limit) {
            // Only an oversized custom template block can reach this branch.
            preg_match_all('~<[^>]*>|&(?:\#[0-9]+|\#x[0-9a-f]+|[a-z][a-z0-9]+);|.~isu', $token, $inside);
            array_push($tokens, ...$inside[0]);
        } else $tokens[] = $token;
    }
    $parts = []; $part = ''; $length = 0; $stack = [];
    $close = static fn($stack) => implode('', array_map(static fn($tag) => '</'.$tag[0].'>', array_reverse($stack)));
    foreach ($tokens as $token) {
        $size = textLength($token);
        if ($length > 0 && $length + $size > $limit) {
            $parts[] = $part . $close($stack);
            $part = implode('', array_column($stack, 1)); $length = 0;
        }
        $part .= $token; $length += $size;
        if (preg_match('~^<(/?)([a-z][a-z0-9-]*)(?:\s[^>]*)?>$~iD', $token, $tag)) {
            if ($tag[1] === '/') array_pop($stack);
            elseif (!in_array(strtolower($tag[2]), ['br', 'hr'], true) && !str_ends_with($token, '/>'))
                $stack[] = [strtolower($tag[2]), $token];
        }
    }
    if ($part !== '') $parts[] = $part . $close($stack);
    return $parts;
}

function configLinks($acc_link, $botState, $unusedIndexes) {
    return present('marzban', ['links' => $acc_link], $botState,
        ['policy' => []])['config_html'];
}

function primaryLink($serverType, $panelUrl, $botUrl, $token, $botState) {
    if ($serverType !== 'marzban') return '';
    return codeLinks([rtrim($panelUrl, '/') . "/sub/$token"]);
}

function backupLinks($subRaw, $token) {
    if (empty($subRaw)) return '';
    $links = [];
    foreach (array_filter(array_map('trim', explode(',', $subRaw))) as $domain) {
        $links[] = rtrim($domain, '/') . "/sub/$token";
    }
    return \achbotMessage('subscription.presentation.present.text.3ff0456031') . codeLinks($links);
}

function configMessage($template, $enableText, $remark, $configLinks, $subLink, $backupSection, bool $hideConfigs = false): string {
    if ($hideConfigs || $configLinks === '') {
        $template = str_replace(\achbotMessage('subscription.presentation.config_message.text.2953f9a4ad'), '', $template);
        if ($hideConfigs) $configLinks = '';
    }
    $msg = strtr($template, [
        'STATE' => escape($enableText), 'NAME' => escape($remark),
        'CONNECT-LINK' => $configLinks, 'SUB-LINK' => $subLink,
    ]);
    $msg .= $backupSection;
    return $msg . \achbotMessage('subscription.presentation.config_message.text.359fef2e2b');
}

function formatTraffic($amount){
    $gb = $amount / (1024 * 1024 * 1024);
    if($gb > 1){
      return round($gb,2) . \achbotMessage('subscription.presentation.format_traffic.text.2acc4ef207');
    }
    else{
        $gb *= 1024;
        return round($gb,2) . \achbotMessage('subscription.presentation.format_traffic.text.4f65338b34');
    }
}

function trafficInGigabytes($amount){
    $gb = $amount / (1024 * 1024 * 1024);
    return round($gb,2);
}
