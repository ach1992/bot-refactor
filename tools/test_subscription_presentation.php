<?php
/** Real pure renderer + delivery helpers with synthetic local transport only. */
error_reporting(E_ALL);
set_error_handler(static function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
require_once dirname(__DIR__) . '/src/Subscription/Presentation.php';

use function AchBot\Subscription\Presentation\present;
use function AchBot\Subscription\Presentation\nativeLinks;
use function AchBot\Subscription\Presentation\configMessage;

$checks = 0;
function check($condition, string $case): void {
    global $checks;
    if (!$condition) throw new RuntimeException($case);
    $checks++;
}
function same($actual, $expected, string $case): void {
    check($actual === $expected, $case);
}
$on = ['configLinkState' => 'on', 'subLinkState' => 'on'];
$off = ['configLinkState' => 'off', 'subLinkState' => 'off'];
$one = 'vless://native.invalid:443?security=tls&type=ws#Fast';
$two = 'trojan://native.invalid:443#Backup';
$three = 'ss://native.invalid:443#Third';
$four = 'future+native://native.invalid:443#Fourth';
$sub = 'https://subscription.invalid/native?token=a&mode=b';
$all = [$one, $two, $three, $four];
$native = ['links' => $all, 'subscription_url' => $sub];
$legacy = ['legacy' => ['showIndexes' => [2, 4], 'linkIndices' => [2, 9]]];

// Genuine Marzban contracts have distinct view and delivery index bases.
$v = present('marzban', $native, $on, $legacy);
same($v['links'], [$two, $four], 'marzban_view_one_based_above_three');
same($v['qr_config_links'], $all, 'marzban_qr_all_raw_configs');
same($v['qr_link'], $sub, 'marzban_view_subscription_qr');
$d = present('marzban', $native, $on, $legacy, 'delivery');
same($d['links'], [$three, $one], 'marzban_delivery_zero_based_missing_index_first');
same($d['qr_link'], $sub, 'marzban_purchase_many_uses_subscription');
same(present('marzban', ['links' => array_slice($all, 0, 3)], $on, $legacy)['links'],
    array_slice($all, 0, 3), 'marzban_small_view_ignores_indexes');
same(present('marzban', ['links' => [$one, $two]], $on, $legacy, 'delivery')['qr_link'],
    $two, 'marzban_purchase_small_uses_second');
same(present('marzban', ['links' => [$one]], $on, [], 'delivery')['qr_link'],
    $one, 'marzban_purchase_one_uses_first');
same(present('marzban', $native, $off, $legacy, 'delivery')['qr_link'],
    $one, 'marzban_purchase_hidden_sub_many_falls_back_first');
$hidden = present('marzban', $native, $off, $legacy);
same($hidden['config_html'], '', 'marzban_config_state_hides_inline_only');
same($hidden['qr_config_links'], $all, 'marzban_config_state_preserves_qr_data');
same(present('marzban', $native, $on, ['legacy' => ['hideConnectLinkForMarzban' => true]])['qr_config_links'],
    $all, 'marzban_hide_connect_keeps_qr');
same(present('marzban', ['links' => [$one, $one]], $on)['qr_config_links'],
    [$one, $one], 'marzban_legacy_keeps_duplicates');
$many = ['links' => array_fill(0, 11, $one), 'subscription_url' => $sub];
same(present('marzban', $many, $on, ['legacy' => ['hideConfigIfMany' => true]], 'delivery')['links'],
    [], 'marzban_hide_delivery_above_ten');
same(present('marzban', ['subscription_url' => $sub], $on, [], 'delivery')['links'],
    [$sub], 'marzban_no_configs_delivery_subscription_fallback');
same(present('marzban', [], $off, [], 'delivery')['qr_link'], '', 'marzban_empty_no_qr');
check(!str_contains(present('marzban', [], $off, [], 'delivery')['config_html'], '<code>'),
    'marzban_empty_no_fabricated_config');

// PG never adopts the legacy index settings; native URIs remain unchanged.
same(present('pasarguard', $native, $on, $legacy)['links'], $all, 'pg_ignores_legacy_positions');
same(present('pasarguard', $native, $on)['qr_link'], $sub, 'pg_default_native_subscription_qr');
same(present('pasarguard', $native, ['subLinkState' => 'off'])['qr_link'],
    $one, 'pg_no_subscription_uses_selected_config');
$policy = ['policy' => ['selectors' => [['protocol' => 'TROJAN'], ['name' => 'Fast']]]];
$s = present('pasarguard', $native, $on, $policy);
same($s['links'], [$two, $one], 'pg_protocol_name_selector_order');
same($s['qr_config_links'], $s['links'], 'pg_explicit_config_qr_matches_inline_selection');
same(present('pasarguard', $native, $on, $policy, 'delivery'), $s,
    'pg_delivery_view_share_policy');
same(present('marzban', $native, $on, $policy)['links'], [$two, $one],
    'explicit_marzban_policy_replaces_legacy_indexes');
$tagged = ['links' => [
    ['uri' => $one, 'name' => 'Custom', 'tags' => ['west']],
    (object)['uri' => $two, 'name' => 'Other', 'tags' => ['east']],
]];
same(present('pasarguard', $tagged, $on, ['policy' => ['selectors' => [['tag' => 'west']]]])['links'],
    [$one], 'explicit_metadata_tags');
same(present('pasarguard', $native, $on, ['policy' => ['selectors' => [['tag' => 'Fast']]]])['links'],
    [], 'native_name_is_not_group_or_tag');
same(present('pasarguard', $tagged, $on, ['policy' => ['selectors' => [['name' => 'Fast']]]])['links'],
    [], 'supplied_name_takes_precedence');
$vmess = 'vmess://' . base64_encode(json_encode(['ps' => 'Native vmess', 'add' => 'native.invalid']));
same(nativeLinks([$vmess])[0]['name'], 'Native vmess', 'vmess_native_name_extraction_only');
same(nativeLinks([$vmess])[0]['uri'], $vmess, 'vmess_uri_not_regenerated');
same(nativeLinks(' ' . base64_encode($one . "\r\n" . $two) . ' '),
    nativeLinks([$one, $two]), 'native_links_base64_supported');
same(nativeLinks($one . "\r" . $two), nativeLinks([$one, $two]), 'native_line_format');
same(nativeLinks([null, false, 14, [], (object)['uri' => []], 'bad', 'vless://',
    "vless://bad.invalid/\0oops", 'vless://bad.invalid/<b>']), [],
    'malformed_native_links_omitted_without_warnings');
same(nativeLinks('not valid base64!!'), [], 'malformed_base64_omitted');
same(nativeLinks([$four])[0]['uri'], $four, 'native_unknown_protocol_opaque_preserved');
$dedup = ['policy' => ['selectors' => [['protocol' => 'vless'], ['name' => 'Fast']], 'max_links' => 1]];
same(present('pasarguard', $native, $on, $dedup)['links'], [$one], 'selectors_deduplicate_before_limit');
same(present('pasarguard', $native, $on, ['policy' => ['max_links' => 0]])['links'],
    [], 'explicit_zero_link_limit');
$hidden = present('pasarguard', $native, $on, ['policy' => ['show_configs' => false]]);
same([$hidden['links'], $hidden['qr_config_links']], [[], []], 'pg_hidden_configs_hide_config_qr');
same($hidden['qr_link'], $sub, 'pg_hidden_configs_keep_visible_subscription');
same(present('pasarguard', $native, $on, ['policy' => ['hide_if_more_than' => 3]])['qr_config_links'],
    [], 'pg_count_policy_hides_config_qr');
same(present('pasarguard', $native, $on, ['policy' => ['qr' => 'first_config']])['qr_link'],
    $one, 'single_qr_first_config_mode');
same(present('pasarguard', $native, $on, ['policy' => ['qr' => 'none']])['qr_link'],
    '', 'single_qr_none_mode');
same(present('pasarguard', ['links' => [$one]], $on, ['policy' => ['qr' => 'subscription']])['qr_link'],
    '', 'subscription_qr_no_config_fallback');
same(present('pasarguard', $native, $on, ['policy' => ['selectors' => []]])['links'],
    [], 'explicit_empty_selector_list_selects_none');
$missing = present('pasarguard', ['links' => [$one]], $on,
    ['policy' => ['selectors' => [['name' => 'Missing']]]]);
check($missing['selection_missing'] && $missing['qr_link'] === ''
    && $missing['notice_html'] !== '', 'missing_selection_does_not_fallback_to_unselected');
foreach ([
    'bad', false, ['show_configs' => 'false'], ['max_links' => -1],
    ['hide_if_more_than' => '2'], ['qr' => 'invalid'], ['indexes' => [1]],
    ['selectors' => 'all'], ['selectors' => [['index' => '1']]],
    ['selectors' => [[]]], ['selectors' => [['name' => []]]],
] as $badPolicy) {
    $r = present('marzban', $native, $on, ['policy' => $badPolicy]);
    check($r['reason'] === 'invalid_policy' && $r['qr_link'] === ''
        && $r['subscription_html'] === '' && $r['config_html'] === '',
        'invalid_explicit_policy_fails_closed');
}
same(present('retired', $native, $on)['reason'], 'unsupported_provider', 'unsupported_provider_boundary');

// Subscription URLs and customer HTML are safe; diagnostics stay out of copy.
foreach (['https://', '/sub/path', 'javascript://host', "https://bad.invalid/\nleak",
    'https://user:secret@native.invalid/sub', 'https://bad.invalid/<tag>', []] as $badUrl) {
    same(present('pasarguard', ['subscription_url' => $badUrl], $on)['subscription_url'],
        '', 'invalid_subscription_url_omitted');
}
$safe = present('pasarguard', [
    'links' => [$one], 'subscription_url' => $sub,
    'backup_urls' => [$sub, 'https://backup.invalid/sub?x=1&y=2',
        'https://backup.invalid/sub?x=1&y=2', '/bad'],
], $on);
check(str_contains($safe['config_html'], '&amp;type=ws') &&
    !str_contains($safe['config_html'], '&type=ws'), 'native_query_html_escaped');
check(str_contains($safe['subscription_html'], 'token=a&amp;mode=b'), 'subscription_html_escaped');
same(substr_count($safe['backup_html'], '<code>'), 1, 'backups_validated_deduplicated');
same(present('pasarguard', ['backup_urls' => ['https://backup.invalid/x']], $off)['backup_html'],
    '', 'backup_respects_subscription_visibility');
$msg = configMessage("STATE NAME\n🔌 لینک تکی کانفیگ:\nCONNECT-LINK\nSUB-LINK",
    '<enabled>', 'NAME<&SUB-LINK', $safe['config_html'], $safe['subscription_html'], '');
check(str_contains($msg, '&lt;enabled&gt; NAME&lt;&amp;SUB-LINK')
    && !str_contains($msg, '<enabled>'), 'customer_names_escape_without_placeholder_expansion');
$noInline = configMessage("NAME\n🔌 لینک تکی کانفیگ:\nCONNECT-LINK\nSUB-LINK",
    'on', 'fixture', '', '', '');
check(!str_contains($noInline, 'CONNECT-LINK') && !str_contains($noInline, 'لینک تکی کانفیگ'),
    'empty_config_section_omitted');
$unavailable = present('pasarguard', [], $on);
check($unavailable['missing_links'] && $unavailable['notice_html'] !== ''
    && !preg_match('/pasarguard|marzban|SQL|API|links_unavailable|invalid_policy/i',
        $unavailable['notice_html']), 'safe_customer_unavailable_notice');

// Load the real compatibility wrappers without config.php bootstrap/network.
$source = file_get_contents(dirname(__DIR__) . '/config.php');
$begin = strpos($source, 'function buildSubscriptionPresentation(');
$end = strpos($source, 'function getProtocolNamesRow(', $begin);
check($begin !== false && $end !== false, 'wrapper_source_present');
eval(str_replace('__DIR__', var_export(dirname(__DIR__), true),
    substr($source, $begin, $end - $begin)));
$showIndexes = [2, 4]; $linkIndices = [2, 9];
$hideConfigIfMany = false; $hideConnectLinkForMarzban = false;
$subscriptionPresentation = ['pasarguard' => ['selectors' => [['protocol' => 'trojan']]]];
same(buildSubscriptionPresentation('pasarguard', $native, $on)['links'], [$two],
    'per_bot_provider_wrapper_applies_policy');
same(buildSubscriptionPresentation('marzban', $native, $on)['links'], [$two, $four],
    'provider_policy_does_not_bleed_into_other_provider');
same(buildConfigLinks($all, $on, [1, 3]),
    '<code>' . htmlspecialchars($one, ENT_QUOTES, 'UTF-8') . "</code>\n\n<code>$three</code>",
    'legacy_view_wrapper_honors_explicit_one_based_input');
same(buildSubLink('pasarguard', 'https://panel.invalid', '', 'token', $on), '',
    'legacy_url_helper_does_not_invent_pg_url');

// The real delivery function accepts native objects/arrays and uses no DB.
function achbotSupportsPanel($provider) {
    return in_array($provider, ['marzban', 'pasarguard'], true);
}
$events = [];
$photoOk = true; $textOk = true; $photoThrows = false;
function sendMessage(...$args) {
    $GLOBALS['events'][] = 'text';
    return (object)['ok' => $GLOBALS['textOk']];
}
function sendPhoto(...$args) {
    $GLOBALS['events'][] = 'photo';
    if ($GLOBALS['photoThrows']) throw new RuntimeException('synthetic_photo_failure');
    return (object)['ok' => $GLOBALS['photoOk']];
}
function RandomString() { return bin2hex(random_bytes(6)); }
class QRcode {
    public static array $payloads = [];
    public static function png($payload, $file, ...$args) {
        self::$payloads[] = $payload;
        file_put_contents($file, 'synthetic-png');
    }
}
require_once dirname(__DIR__) . '/buy_helpers.php';
$temp = sys_get_temp_dir() . '/achbot-presentation-' . bin2hex(random_bytes(7));
mkdir($temp, 0700);
function delivery($provider, $response, $state, $indices = [], $hide = false, $send = false): array {
    global $temp;
    return buildLinksAndSendQr($provider, ['panel_url' => 'https://panel.invalid',
        'sub_links' => 'https://backup.invalid'], $state, '', 1, 'stable-id', '',
        'fixture<&', 0, '', 0, '', '', '', '', $response, 1234, 2, 30, [],
        $indices, $hide, '', $temp, $send);
}
try {
    $subscriptionPresentation = [];
    $m = delivery('marzban', (object)['sub_link' => '/sub/native-token', 'vray_links' => $all],
        $on, [2, 9]);
    same([$m[0], $m[2]], ['native-token', 'native-token'], 'marzban_delivery_tuple_token');
    check(str_contains($m[3], htmlspecialchars($three, ENT_QUOTES, 'UTF-8'))
        && str_contains($m[3], 'fixture&lt;&amp;'), 'real_marzban_delivery_text_safe');
    same($m[5], 'https://panel.invalid/sub/native-token', 'real_marzban_delivery_qr_policy');
    $pg = delivery('pasarguard', (object)['links' => [$one, $two], 'subscription_url' => $sub], $on);
    same(json_decode($pg[1], true), [$one, $two], 'real_pg_persists_native_links');
    same([$pg[0], $pg[2]], ['', 'stable-id'], 'real_pg_no_invented_vendor_token');
    check(str_contains($pg[3], htmlspecialchars($one, ENT_QUOTES, 'UTF-8'))
        && !str_contains($pg[3], 'https://panel.invalid')
        && !str_contains($pg[3], 'https://backup.invalid'), 'real_pg_opaque_native_delivery_no_marzban_reconstruction');
    $pgArray = delivery('pasarguard', ['links' => [$one], 'subscription_url' => $sub], $on);
    same($pgArray[5], $sub, 'native_array_result_supported');
    $subscriptionPresentation = ['pasarguard' => ['selectors' => [['protocol' => 'trojan']],
        'qr' => 'first_config']];
    $pgPolicy = delivery('pasarguard', (object)['links' => [$one, $two]], $on);
    same($pgPolicy[5], $two, 'real_delivery_uses_explicit_selector_qr');
    check(!str_contains($pgPolicy[3], 'vless://'), 'real_delivery_does_not_leak_unselected_config');
    $subscriptionPresentation = [];
    $empty = delivery('pasarguard', (object)[], $on);
    check(str_contains($empty[3], 'دوباره بررسی کنید') && $empty[5] === ''
        && !preg_match('/pasarguard|marzban|SQL|API|links_unavailable/i', $empty[3]),
        'real_empty_delivery_customer_next_step_no_internal_reason');
    $events = [];
    delivery('pasarguard', (object)[], $on, [], false, true);
    same($events, ['text'], 'empty_delivery_no_empty_qr_effect');
    $events = [];
    check(sendQrFromLink('', 'fixture', '{}', '', 1234, '', $temp),
        'intentional_no_qr_delivery_needs_only_text_ack');
    same($events, ['text'], 'no_qr_text_ack_effect');
    $events = [];
    $photoOk = false;
    check(!sendQrFromLink($one, 'fixture', '{}', '', 1234, '', $temp),
        'photo_failure_does_not_certify_delivery');
    same($events, ['photo', 'text'], 'photo_failure_still_sends_account_text');
    $events = [];
    $photoOk = true; $textOk = false;
    check(!sendQrFromLink($one, 'fixture', '{}', '', 1234, '', $temp),
        'photo_success_does_not_hide_text_failure');
    $photoThrows = true; $textOk = true;
    check(!sendQrFromLink($one, 'fixture', '{}', '', 1234, '', $temp),
        'ambiguous_photo_never_retried_or_certified');
    $photoThrows = false;
    check(sendQrFromLink($one, 'fixture', '{}', '', 1234, '', $temp),
        'both_transport_effects_acknowledged');
    same(glob($temp . '/*'), [], 'owned_qr_files_always_removed');
} finally {
    foreach (glob($temp . '/*') as $file) unlink($file);
    rmdir($temp);
}
echo "PASS subscription presentation: $checks checks; real renderer/wrappers/delivery; no DB/network\n";
