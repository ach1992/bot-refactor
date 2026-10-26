<?php
/**
 * AChBot #51: CLI-only, read-only installed payment readiness inspection.
 * This is a prerequisite inventory, NEVER certification of payment settlement.
 * No real bot, provider, Telegram, wallet or database mutation is performed.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
ini_set('display_errors', '0');
ini_set('log_errors', '0');
error_reporting(E_ALL);

$checks = [];
$report = [
    'tool' => 'achbot-payment-readiness-preflight',
    'report_version' => 1,
    'completed' => false,
    'ready_for_isolated_operator_smoke' => false,
    'scope' => 'read_only_local_source_and_database',
    'checks' => &$checks,
    'schema' => [],
    'methods' => [],
    'limitations' => [
        'matching supplied manifest does not authenticate its provenance',
        'schema shapes do not prove that a migration was actually run or that historical rows are valid',
        'does not execute a Telegram, merchant, Cron, Marzban, restore, payment or financial operation',
        'operator approval is required before installed or external-effect acceptance',
    ],
];
function finding(string $id, string $status, string $code): void {
    global $checks;
    $checks[] = ['id' => $id, 'status' => $status, 'code' => $code];
}
function finish(int $exit): never {
    global $report, $checks;
    $report['completed'] = true;
    $report['ready_for_isolated_operator_smoke'] = $exit === 0;
    $report['blocking_checks'] = count(array_filter($checks,
        static fn(array $c): bool => $c['status'] === 'BLOCK'));
    echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    exit($exit);
}
function errorExit(string $id, string $code): never {
    finding($id, 'BLOCK', $code);
    finish(2);
}
function available($value): bool {
    return is_string($value) && trim($value) !== '';
}
function textValue($value): string {
    return is_string($value) ? $value : '';
}
function options(array $argv): array {
    $out = [];
    for ($n = 1; $n < count($argv); $n++) {
        if (!in_array($argv[$n], ['--manifest', '--runtime-dir'], true) ||
            !isset($argv[$n + 1]) || str_starts_with($argv[$n + 1], '--') ||
            array_key_exists($argv[$n], $out)) errorExit('arguments', 'invalid_argument');
        $out[$argv[$n]] = $argv[++$n];
    }
    return $out;
}
function verifyManifest(string $manifestPath, string $runtimePath): bool {
    global $report;
    try {
        $json = file_get_contents($manifestPath);
        if ($json === false || strlen($json) > 500000) return false;
        $manifest = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || !isset($manifest['files']) ||
            !is_array($manifest['files']) || count($manifest['files']) !== 102 ||
            ($manifest['file_count'] ?? null) !== count($manifest['files']) ||
            ($manifest['schema_migration_included'] ?? null) !== false ||
            ($manifest['private_config_included'] ?? null) !== false ||
            !preg_match('/\A[a-f0-9]{40}\z/D', (string)($manifest['candidate'] ?? ''))) return false;
        // The expected current runtime inventory is pinned independently of
        // supplied manifest bytes. Without this guard an attacker-controlled
        // file list could request hashes of an installed private file.
        // SHA-256 of newline-joined sorted package_post_v9.members() paths.
        $members = array_keys($manifest['files']);
        sort($members, SORT_STRING);
        if (!hash_equals('541fb30dd989d2826b748e8a95724c487aae6497dec0817286a0b5e4cf71b3b2',
                         hash('sha256', implode("\n", $members))) ||
            in_array('baseInfo.php', $members, true)) return false;
        $root = realpath($runtimePath);
        if ($root === false || !is_dir($root)) return false;
        $report['source_candidate'] = $manifest['candidate'];
        $report['source_file_count'] = count($manifest['files']);
        foreach ($manifest['files'] as $rel => $expected) {
            if (!is_string($rel) || !preg_match('#\A[a-zA-Z0-9_./-]+\z#D', $rel) ||
                str_contains($rel, '..') || str_starts_with($rel, '/') ||
                !is_array($expected) ||
                !preg_match('/\A[a-f0-9]{64}\z/D', (string)($expected['sha256'] ?? '')) ||
                !is_int($expected['size']) || $expected['size'] < 0) return false;
            $path = $root . DIRECTORY_SEPARATOR . $rel;
            if (is_link($path) || !is_file($path)) return false;
            $resolved = realpath($path);
            if ($resolved === false || !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR) ||
                filesize($path) !== $expected['size'] ||
                !hash_equals($expected['sha256'], (string)hash_file('sha256', $path))) return false;
        }
        foreach (['settings/tronChecker.php', 'src/Finance/CryptoChecker.php',
                   'src/Finance/Routes/CryptoAndExchange.php'] as $retired) {
            if (file_exists($root . DIRECTORY_SEPARATOR . $retired) ||
                is_link($root . DIRECTORY_SEPARATOR . $retired)) return false;
        }
        // Never open baseInfo.php, or a runtime's secrets, even if present.
        return true;
    } catch (Throwable $ignored) {
        return false;
    }
}
function metadata(mysqli $db): array {
    $tables = [];
    $columns = [];
    $unique = [];
    $r = $db->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE=\'BASE TABLE\'');
    foreach ($r as $row) $tables[(string)$row['TABLE_NAME']] = true;
    $r = $db->query('SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()');
    foreach ($r as $row) $columns[$row['TABLE_NAME']][$row['COLUMN_NAME']] = true;
    $r = $db->query('SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX');
    foreach ($r as $row) {
        if ((int)$row['NON_UNIQUE'] === 0) $unique[$row['TABLE_NAME']][$row['INDEX_NAME']][] = $row['COLUMN_NAME'];
    }
    return [$tables, $columns, $unique];
}
function validShape(array $spec, array $tables, array $cols, array $unique): bool {
    foreach ($spec as $table => $requirements) {
        if (!isset($tables[$table])) return false;
        foreach ($requirements['columns'] as $column) {
            if (!isset($cols[$table][$column])) return false;
        }
        foreach (($requirements['unique'] ?? []) as $index => $fields) {
            if (($unique[$table][$index] ?? null) !== $fields) return false;
        }
    }
    return true;
}
function readSettings(mysqli $db): ?array {
    $records = ['BOT_STATES' => [], 'PAYMENT_KEYS' => []];
    $r = $db->query("SELECT type,value FROM setting WHERE type IN ('BOT_STATES','PAYMENT_KEYS') ORDER BY id");
    foreach ($r as $row) $records[$row['type']][] = $row['value'];
    $valid = true;
    foreach ($records as $type => $values) {
        if (count($values) !== 1) {
            finding('setting_' . strtolower($type), 'BLOCK',
                count($values) === 0 ? 'document_missing' : 'duplicate_document');
            $valid = false;
            continue;
        }
        try {
            $decoded = json_decode($values[0], true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $ignored) {
            $decoded = null;
        }
        // PHP decodes {} and [] to the same array; inspect the JSON
        // container type so a list can never masquerade as a settings map.
        if (!is_array($decoded) || !str_starts_with(ltrim($values[0]), '{') ||
            !str_ends_with(rtrim($values[0]), '}') ||
            (array_is_list($decoded) && $decoded !== [])) {
            finding('setting_' . strtolower($type), 'BLOCK', 'invalid_object_document');
            $valid = false;
            continue;
        }
        $records[$type] = $decoded;
        finding('setting_' . strtolower($type), 'PASS', 'unique_valid_json_object');
    }
    return $valid ? $records : null;
}
function flag(array $state, string $key): bool {
    return ($state[$key] ?? 'off') === 'on';
}
function policyCheck(array $state): bool {
    $methods = achbotPayUxAudienceMethods();
    $modes = achbotPayUxAudienceModes();
    $legacy = $state['gatewayAudience'] ?? [];
    if (!is_array($legacy)) return false;
    foreach ($legacy as $method => $mode) {
        if (!is_string($method) || !array_key_exists($method, $methods) ||
            !is_string($mode) || !array_key_exists($mode, $modes)) return false;
    }
    return !array_key_exists('gatewayRules', $state) ||
        achbotGatewayRulesDecode($state['gatewayRules']) !== null;
}
function methodChecks(array $state, array $keys): void {
    global $report;
    require_once dirname(__DIR__) . '/src/Finance/ReceiptHelpers.php';
    $usdtConfigured = false;
    foreach (['BEP20','TRC20','ERC20'] as $network) {
        $address = textValue($keys['manualUsdtWallet'.$network] ?? null);
        if (!available($address) && ($keys['manualUsdtNetwork'] ?? null) === $network)
            $address = textValue($keys['manualUsdtWallet'] ?? null);
        if (achbotManualUsdtWalletValid($address, $network)) $usdtConfigured = true;
    }
    $trxRate = $keys['manualTrxRateToman'] ?? null;
    $trxConfigured = achbotManualTronAddressValid(
        textValue($keys['manualTrxWallet'] ?? null)) &&
        is_scalar($trxRate) &&
        preg_match('/\A[1-9][0-9]{0,8}\z/D', (string)$trxRate) === 1;
    $manualOn = flag($state, 'manualUsdtState');
    $on = [
        'nowpayments' => flag($state,'nowPaymentWallet') || flag($state,'nowPaymentOther'),
        'zarinpal' => flag($state,'zarinpal'),
        'nextpay' => flag($state,'nextpay'),
        'bank_transfer' => flag($state,'cartToCartState'),
        'gift_card' => flag($state,'giftCardState'),
        'manual_usdt' => $manualOn,
        'manual_trx' => $manualOn,
        'wallet' => flag($state,'walletState'),
    ];
    $offerable = 0;
    foreach ($on as $method => $active) {
        $configured = null;
        $status = 'not_evaluated';
        if ($active) {
            $configured = match ($method) {
                'nowpayments' => available($keys['nowpayment'] ?? null),
                'zarinpal', 'nextpay' => available($keys[$method] ?? null),
                'bank_transfer' => available($keys['bankAccount'] ?? null) &&
                    available($keys['holderName'] ?? null) &&
                    strlen(trim($keys['bankAccount'])) <= 150 &&
                    strlen(trim($keys['holderName'])) <= 150,
                'gift_card' => achbotManualGiftSellerUrlValid(
                    textValue($keys['giftCardUrl'] ?? null)),
                'manual_usdt' => $usdtConfigured,
                'manual_trx' => $trxConfigured,
                'wallet' => true,
            };
            if ($configured) {
                $offerable++;
                $status = 'present_not_verified';
                finding('method_' . $method, 'PASS', 'locally_configured_not_provider_verified');
            } else {
                // TRX and USDT share the same global switch but have distinct
                // address/rate availability. An intentionally unconfigured
                // network is NOT offered; it must not block the other network.
                $alternate = ($method === 'manual_usdt' && $trxConfigured) ||
                             ($method === 'manual_trx' && $usdtConfigured);
                if ($manualOn && $alternate &&
                    in_array($method, ['manual_usdt','manual_trx'], true)) {
                    $status = 'not_offered';
                    finding('method_' . $method, 'WARN', 'network_not_configured_not_offered');
                } else {
                    $status = 'missing_or_invalid';
                    finding('method_' . $method, 'BLOCK', 'required_setting_missing_or_invalid');
                }
            }
        }
        $report['methods'][$method] = [
            'switch' => $active ? ($manualOn && in_array($method,
                ['manual_usdt','manual_trx'],true) ? 'shared_on' : 'on') : 'off',
            'local_configuration' => $status,
        ];
    }
    finding('method_selection', $offerable > 0 ? 'PASS' : 'BLOCK',
        $offerable > 0 ? 'one_or_more_methods_offerable' : 'no_offerable_method_for_operator_smoke');
    if ($manualOn && $usdtConfigured) finding('manual_usdt_rate', 'WARN',
        'private_base_rate_requires_operator_validation');
}

$args = options($argv);
if (($args['--manifest'] ?? null) === null || ($args['--runtime-dir'] ?? null) === null) {
    finding('source_identity', 'BLOCK', 'manifest_and_runtime_dir_required');
} elseif (verifyManifest($args['--manifest'], $args['--runtime-dir'])) {
    finding('source_identity', 'PASS', 'matches_supplied_manifest_not_provenance');
} else {
    finding('source_identity', 'BLOCK', 'manifest_mismatch_or_untrusted_shape');
}
if (PHP_VERSION_ID < 80100 || !extension_loaded('mysqli'))
    errorExit('php_runtime', 'php81_and_mysqli_required');
finding('php_runtime', 'PASS', 'supported_php_and_mysqli_available');

$name = getenv('ACHBOT_PREFLIGHT_DB_NAME') ?: '';
$user = getenv('ACHBOT_PREFLIGHT_DB_USER') ?: '';
$password = getenv('ACHBOT_PREFLIGHT_DB_PASSWORD');
$socket = getenv('ACHBOT_PREFLIGHT_DB_SOCKET') ?: null;
$host = getenv('ACHBOT_PREFLIGHT_DB_HOST') ?: 'localhost';
$port = getenv('ACHBOT_PREFLIGHT_DB_PORT') ?: '3306';
if (!preg_match('/\A[A-Za-z0-9_]+\z/D', $name) || !available($user) ||
    !ctype_digit((string)$port) || (int)$port < 1 || (int)$port > 65535 ||
    $password === false) errorExit('database_connect', 'explicit_db_environment_required');
try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = mysqli_init();
    $db->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
    $db->real_connect($host, $user, $password, $name, (int)$port, $socket);
    $db->set_charset('utf8mb4');
    $db->query('START TRANSACTION READ ONLY');
    finding('database_read_only', 'PASS', 'read_only_snapshot_opened');
    [$tables, $columns, $unique] = metadata($db);
    $legacy = [
        'add_link','admins','bc_jobs','bc_messages','black_list','chats',
        'chats_info','discounts','gift_list','increase_day','increase_order',
        'increase_plan','needed_sofwares','orders_list','pays','send_list',
        'servers','server_categories','server_config','server_info',
        'server_plans','setting','users',
    ];
    $missing = array_filter($legacy, static fn(string $t): bool => !isset($tables[$t]));
    finding('original_23_tables', $missing ? 'BLOCK' : 'PASS',
        $missing ? 'original_schema_tables_missing' : 'original_table_names_present');
    $specs = [
        '001' => ['server_info'=>['columns'=>['panel_group_id']]],
        '003' => [
            'payment_attempts'=>['columns'=>['id','pay_row_id','provider','provider_reference','payment_id'],
                'unique'=>['uq_payment_attempt_pay_row'=>['pay_row_id'],
                           'uq_payment_attempt_ref'=>['provider','provider_reference']]],
            'payment_transactions'=>['columns'=>['id','attempt_id','provider','payment_id'],
                'unique'=>['uq_payment_transaction_reference'=>['provider','payment_id']]],
        ],
        '004' => ['automatic_renewal_attempts'=>['columns'=>['order_id','cycle_expire','pay_row_id'],
            'unique'=>['uq_renewal_cycle'=>['order_id','cycle_expire']]]],
        '005' => ['admin_wallet_adjustments'=>['columns'=>['operator_userid','target_userid','telegram_update_id','reason'],
            'unique'=>['uq_admin_wallet_update'=>['telegram_update_id']]]],
        '006' => ['payment_message_refs'=>['columns'=>['pay_row_id','chat_id','message_id','updated_at'],
            'unique'=>['uq_payment_message_chat'=>['chat_id','message_id']]]],
        '007' => ['service_effects'=>['columns'=>['pay_row_id','source_kind','source_ref','operation_id','target_key','target_server_id','requested_bytes'],
            'unique'=>['uq_effect_operation'=>['operation_id'],
                       'uq_effect_source_line'=>['source_kind','source_ref','ordinal']]]],
        '008' => ['referral_awards'=>['columns'=>['pay_row_id','inviter_userid','amount_toman','state'],
            'unique'=>['uq_referral_award_payment'=>['pay_row_id']]]],
        '009' => ['bank_buy_invoice_claims'=>['columns'=>['pay_row_id','invoice_sha256','bank_attempt_id','quoted_days','entitled_days'],
            'unique'=>['PRIMARY'=>['pay_row_id'],
                       'uq_bank_claim_attempt'=>['bank_attempt_id']]]],
    ];
    foreach ($specs as $migration => $schema) {
        $present = count(array_filter(array_keys($schema),
            static fn(string $t): bool => isset($tables[$t])));
        $isPresent = $present === count($schema) && validShape($schema,$tables,$columns,$unique);
        $code = $isPresent ? 'columns_and_named_unique_indexes_detected_not_ddl_certified' :
            ($present === 0 ? 'missing' : 'partial_or_incompatible_schema_requires_audit');
        $report['schema'][$migration] = $code;
        finding('schema_' . $migration, $isPresent ? 'PASS' : 'BLOCK', $code);
    }
    if (isset($tables['setting'])) {
        $settings = readSettings($db);
        if ($settings !== null) {
            require_once dirname(__DIR__) . '/src/Finance/PaymentLifecycle.php';
            $state = $settings['BOT_STATES'];
            $valid = policyCheck($state);
            finding('gateway_policies', $valid ? 'PASS' : 'BLOCK',
                $valid ? 'current_source_parser_accepts_rules' : 'malformed_or_unknown_rule_denies');
            methodChecks($state,$settings['PAYMENT_KEYS']);
        }
    } else finding('settings_read', 'BLOCK', 'setting_table_missing');
    $db->rollback();
    $db->close();
} catch (Throwable $ignored) {
    finding('database_inspection', 'BLOCK', 'read_only_inspection_failed');
}
$bad = count(array_filter($checks,static fn(array $check): bool => $check['status'] === 'BLOCK'));
finish($bad === 0 ? 0 : 2);
