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
$legacy = ['mode' => 'marzban_legacy', 'legacy' => ['showIndexes' => [2, 4], 'linkIndices' => [2, 9]]];
$legacyHidden = array_replace($legacy, ['policy'=>['show_configs'=>false,'show_subscription'=>false]]);

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
same(present('marzban', ['links' => [$one]], $on, $legacy, 'delivery')['qr_link'],
    $one, 'marzban_purchase_one_uses_first');
same(present('marzban', $native, $off, $legacyHidden, 'delivery')['qr_link'],
    $one, 'marzban_purchase_hidden_sub_many_falls_back_first');
$hidden = present('marzban', $native, $off, $legacyHidden);
same($hidden['config_html'], '', 'marzban_config_state_hides_inline_only');
same($hidden['qr_config_links'], $all, 'marzban_config_state_preserves_qr_data');
same(present('marzban', $native, $on, ['mode' => 'marzban_legacy', 'legacy' => ['hideConnectLinkForMarzban' => true]])['qr_config_links'],
    $all, 'marzban_hide_connect_keeps_qr');
same(present('marzban', ['links' => [$one, $one]], $on, $legacy)['qr_config_links'],
    [$one, $one], 'marzban_legacy_keeps_duplicates');
$many = ['links' => array_fill(0, 11, $one), 'subscription_url' => $sub];
same(present('marzban', $many, $on, ['mode' => 'marzban_legacy', 'legacy' => ['hideConfigIfMany' => true]], 'delivery')['links'],
    [], 'marzban_hide_delivery_above_ten');
same(present('marzban', ['subscription_url' => $sub], $on, ['mode'=>'marzban_legacy'], 'delivery')['links'],
    [$sub], 'marzban_no_configs_delivery_subscription_fallback');
same(present('marzban', [], $off, $legacy, 'delivery')['qr_link'], '', 'marzban_empty_no_qr');
check(!str_contains(present('marzban', [], $off, [], 'delivery')['config_html'], '<code>'),
    'marzban_empty_no_fabricated_config');

// PG never adopts the legacy index settings; native URIs remain unchanged.
same(present('pasarguard', $native, $on, ['legacy'=>$legacy['legacy']])['links'], $all, 'pg_ignores_legacy_positions');
same(present('pasarguard', $native, $on)['qr_link'], $sub, 'pg_default_native_subscription_qr');
same(present('pasarguard', $native, ['subLinkState' => 'off'], ['policy'=>['show_subscription'=>false]])['qr_link'],
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
same(present('pasarguard', ['backup_urls' => ['https://backup.invalid/x']], $off, ['policy'=>['show_subscription'=>false]])['backup_html'],
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

// Real database resolver/wrapper/delivery coverage lives in test_server_display_settings.py.
$largeNative = ['links' => array_map(static fn($i) => 'vless://native.invalid/'.$i, range(1, 80)),
    'subscription_url' => $sub];
$bounded = present('pasarguard', $largeNative, $on, ['policy'=>['max_links'=>1000]]);
check(count($bounded['links']) === 10 && count($bounded['qr_config_links']) === 10
    && $bounded['limited'] && $bounded['notice_html'] !== '', 'bounded_native_configs_and_qr_notice');
check(strlen($bounded['config_html'].$bounded['subscription_html'].$bounded['backup_html']) <= 2200,
    'telegram_link_text_budget');
$largeUri = 'vless://native.invalid/' . str_repeat('a', 2000);
$bounded = present('pasarguard', ['links'=>[$largeUri,$one], 'subscription_url'=>$sub], $on);
check(strlen($bounded['config_html'].$bounded['subscription_html']) <= 2200
    && !str_contains($bounded['config_html'], substr($largeUri,0,1000).'&'),
    'whole_uri_display_budget_never_rewrites_credentials');
function achbotSupportsPanel($provider) { return in_array($provider, ['marzban','pasarguard'], true); }
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
try {
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
echo "PASS subscription presentation: $checks checks; real renderer/transport; no DB/network\n";
