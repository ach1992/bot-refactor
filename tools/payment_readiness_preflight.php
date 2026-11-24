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
    'report_version' => 2,
    'structure_ready' => false,
    'payment_configuration_ready' => false,
    'completed' => false,
    'ready_for_isolated_operator_smoke' => false,
    'scope' => 'read_only_local_source_and_database',
    'checks' => &$checks,
    'schema' => [],
    'methods' => [],
    'payment_apis' => [],
    'limitations' => [
        'trusted manifest identity is checked by the operator entrypoint; not live web-SAPI proof',
        'metadata and runtime schema gates do not certify every column type, check expression, row or migration history',
        'does not execute a Telegram, merchant, Cron, Native, restore, payment or financial operation',
        'private encryption key and live API authentication are not inspected',
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
    $report['payment_configuration_ready'] = $exit === 0;
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
/** Compare the metadata projection generated from the exact consolidated DDL.
 * No SQL from the contract is executed and no arbitrary table names are queried. */
function inspectSchema(mysqli $db,array $expected): bool {
    global $report;
    $tables=[];$columns=[];$indexes=[];
    foreach ($db->query("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE'") as $r)
        $tables[$r['TABLE_NAME']]=$r['ENGINE'];
    foreach ($db->query("SELECT TABLE_NAME,COLUMN_NAME,IS_NULLABLE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE()") as $r)
        $columns[$r['TABLE_NAME']][$r['COLUMN_NAME']]=$r['IS_NULLABLE'];
    foreach ($db->query("SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART
        FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE()
        ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX") as $r) {
        $indexes[$r['TABLE_NAME']][$r['INDEX_NAME']][]=
            [$r['COLUMN_NAME'],(int)$r['NON_UNIQUE']===0,$r['SUB_PART']];
    }
    $ready=true;
    foreach ($expected as $table=>$spec) {
        $issues=[];
        if (strcasecmp((string)($tables[$table]??''),$spec['engine'])!==0) $issues[]='missing_or_wrong_engine';
        foreach ($spec['columns'] as $name=>$nullable)
            if (($columns[$table][$name]??null)!==$nullable) $issues[]='column:'.$name;
        foreach ($spec['indexes'] as $name=>$index) {
            $want=array_map(static fn($c)=>[$c,$index['unique'],null],$index['columns']);
            if (($indexes[$table][$name]??null)!==$want) $issues[]='index:'.$name;
        }
        // SELECT alone does not expose every TABLE_CONSTRAINTS row in
        // MariaDB. Read the existing definition with the same privilege;
        // compare only named current constraints and FK endpoints, not raw
        // DDL or column defaults in the public report.
        if ($spec['constraints'] && isset($tables[$table])) {
            if (!preg_match('/^[a-zA-Z0-9_]+$/D',$table))
                throw new RuntimeException('invalid_schema_table');
            $ddl=$db->query('SHOW CREATE TABLE `'.$table.'`')->fetch_assoc()['Create Table']??'';
            foreach ($spec['constraints'] as $name=>$c) {
                $prefix='/^\\s*CONSTRAINT `'.preg_quote($name,'/').'` ';
                if ($c['type']==='CHECK') {
                    if (!preg_match($prefix.'CHECK\\s*\\(/mi',$ddl)) $issues[]='constraint:'.$name;
                    continue;
                }
                $pattern=$prefix.'FOREIGN KEY\\s*\\(([^)]+)\\) REFERENCES `([a-zA-Z0-9_]+)`\\s*\\(([^)]+)\\)/mi';
                if (!preg_match($pattern,$ddl,$m)) {$issues[]='constraint:'.$name;continue;}
                preg_match_all('/`([a-zA-Z0-9_]+)`/',$m[1],$from);
                preg_match_all('/`([a-zA-Z0-9_]+)`/',$m[3],$to);
                if ($from[1]!==$c['columns'] || $m[2]!==$c['target'] || $to[1]!==$c['target_columns'])
                    $issues[]='foreign_key:'.$name;
            }
        }
        if ($issues) {$ready=false;$report['schema'][$table]=$issues;}
    }
    finding('consolidated_schema',$ready?'PASS':'BLOCK',$ready?'required_current_metadata_present':'current_schema_mismatch');
    return $ready;
}
function readSettings(mysqli $db): ?array {
    $records = ['BOT_STATES' => [], 'PAYMENT_KEYS' => []];
    $r = $db->query("SELECT type,value FROM setting WHERE type IN ('BOT_STATES','PAYMENT_KEYS') ORDER BY id");
    foreach ($r as $row) $records[$row['type']][] = $row['value'];
    $valid = true;
    foreach ($records as $type => $values) {
        if (!$values) {
            $records[$type]=[];
            finding('setting_'.strtolower($type),'INFO','unconfigured_document');
            continue;
        }
        if (count($values) !== 1) {
            finding('setting_' . strtolower($type), 'BLOCK',
                'duplicate_document');
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
/**
 * Static policy opportunity only. Do not query any customer or infer their
 * actual eligibility. Mirror PaymentLifecycle's new-document precedence:
 * a method key (including []) overrides the legacy audience for that method.
 */
function achbotPreflightPolicyPotential(array $state, string $method): bool {
    if (array_key_exists('gatewayRules', $state)) {
        $document = achbotGatewayRulesDecode($state['gatewayRules']);
        if ($document === null) return false;
        if (array_key_exists($method, $document['methods'])) {
            foreach ($document['methods'][$method] as $rule) {
                if ($rule['enabled'] === true) return true;
            }
            return false;
        }
    }
    $legacy = $state['gatewayAudience'] ?? [];
    if (!is_array($legacy)) return false;
    $mode = $legacy[$method] ?? 'all';
    return is_string($mode) && array_key_exists($mode, achbotPayUxAudienceModes()) &&
        $mode !== 'off';
}

/** Use only explicit PAYMENT_KEYS rates and production network validation. */
function achbotPreflightUsdtConfiguration(array $keys): array {
    $GLOBALS['paymentKeys']=$keys;
    $rateConfigured=achbotUsdtRate($keys)!==null;
    $configured=false;$address=false;
    foreach (['BEP20','TRC20','ERC20'] as $network) {
        if (achbotManualUsdtConfiguration($network)!==null) $configured=true;
        // Isolate destination validity without pretending a private rate exists.
        $probe=$keys;
        $probe['manualUsdtRateToman']='1000';
        $GLOBALS['paymentKeys']=$probe;
        if (achbotManualUsdtConfiguration($network)!==null) $address=true;
        $GLOBALS['paymentKeys']=$keys;
    }
    $invalid=array_key_exists('manualUsdtRateToman',$keys) &&
        $keys['manualUsdtRateToman']!=='' &&
        achbotUsdtRateValue($keys['manualUsdtRateToman'])===null;
    return ['configured'=>$configured,'invalid_explicit_rate'=>$address && $invalid,
            'rate_configured'=>$rateConfigured];
}

/** Never decrypt credentials or infer live authentication in a diagnostic. */
function achbotPreflightApiReadiness(array $state,array $keys): void {
    global $report;
    foreach (['bank_transfer'=>'smsApi','gift_card'=>'giftApi'] as $method=>$prefix) {
        $active=flag($state,$prefix.'VerifyState');
        $credential=$keys[$prefix.($method==='bank_transfer'?'Token':'Secret')]??null;
        $url=$keys[$prefix.'Url']??null;
        $envelope=is_string($credential) && str_starts_with($credential,'achpe1:')
            ?base64_decode(substr($credential,7),true):false;
        $present=is_string($envelope) && strlen($envelope)>=29 &&
            is_string($url) && achbotPaymentEvidenceBaseUrl($url,$method)!==null;
        if ($method==='bank_transfer') {
            $hours=achbotPaymentEvidenceInteger($keys['smsApiHorizonHours']??null);
            $present=$present && $hours!==null && $hours<=intdiv(PHP_INT_MAX,3600);
        } else {
            $id=$keys['giftApiKeyId']??null;
            $present=$present && is_string($id) && $id!=='' &&
                achbotPaymentEvidenceNormalizeSetting('giftApiKeyId',$id)===$id &&
                in_array($keys['giftApiAmountUnit']??null,['rial','toman'],true);
        }
        $report['payment_apis'][$method]=[
            'switch'=>$active?'on':'off',
            'local_configuration'=>$active?($present?'present_not_decrypted':'missing_or_invalid'):'not_evaluated',
            'authentication'=>'not_verified'];
        if ($active) finding('api_'.$method,$present?'WARN':'BLOCK',
            $present?'credentials_not_decrypted_not_verified':'required_api_setting_missing_or_invalid');
    }
}

function methodChecks(array $state, array $keys): void {
    global $report;
    require_once dirname(__DIR__) . '/src/Finance/ReceiptHelpers.php';
    $usdt = achbotPreflightUsdtConfiguration($keys);
    $usdtConfigured = $usdt['configured'];
    $GLOBALS['paymentKeys']=$keys;
    $trxConfigured=achbotManualTrxConfiguration()!==null;
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
        $offerability = 'disabled';
        $policyPotential = $active && achbotPreflightPolicyPotential($state, $method);
        if ($active) {
            $configured = match ($method) {
                'nowpayments' => available($keys['nowpayment'] ?? null) &&
                    $usdt['rate_configured'],
                'zarinpal', 'nextpay' => available($keys[$method] ?? null),
                'bank_transfer' => available($keys['bankAccount']??null) &&
                    available($keys['holderName']??null) && achbotManualBankIdentity()!==null,
                'gift_card' => achbotManualGiftSellerUrlValid(
                    textValue($keys['giftCardUrl'] ?? null)),
                'manual_usdt' => $usdtConfigured,
                'manual_trx' => $trxConfigured,
                'wallet' => true,
            };
            if ($configured) {
                $status = 'present_not_verified';
                if ($policyPotential) {
                    $offerable++;
                    $offerability = 'potentially_offerable';
                    finding('method_' . $method, 'PASS', 'locally_configured_not_provider_verified');
                } else {
                    $offerability = 'configured_but_policy_denied';
                    finding('method_' . $method, 'WARN', 'configured_but_policy_denied');
                }
            } else {
                $alternate = ($method === 'manual_usdt' && $trxConfigured) ||
                             ($method === 'manual_trx' && $usdtConfigured);
                if ($method === 'manual_usdt' && $usdt['invalid_explicit_rate']) {
                    $status = 'missing_or_invalid';
                    $offerability = 'invalid_explicit_rate';
                    finding('method_' . $method, 'BLOCK', 'invalid_explicit_usdt_rate');
                } elseif ($manualOn && $alternate &&
                    in_array($method, ['manual_usdt','manual_trx'], true)) {
                    $status = 'not_offered';
                    $offerability = 'not_offered';
                    finding('method_' . $method, 'WARN', 'network_not_configured_not_offered');
                } else {
                    $status = 'missing_or_invalid';
                    $offerability = 'unavailable';
                    finding('method_' . $method, 'BLOCK', 'required_setting_missing_or_invalid');
                }
            }
        }
        $report['methods'][$method] = [
            'switch' => $active ? ($manualOn && in_array($method,
                ['manual_usdt','manual_trx'],true) ? 'shared_on' : 'on') : 'off',
            'local_configuration' => $status,
            'policy_availability' => $active
                ? ($policyPotential ? 'potentially_permitted' : 'unconditionally_denied')
                : 'not_evaluated',
            'offerability' => $offerability,
            'actual_customer_eligibility' => 'not_tested',
        ];
    }
    finding('method_selection', $offerable > 0 ? 'PASS' : 'BLOCK',
        $offerable > 0 ? 'one_or_more_methods_potentially_offerable' :
                         'no_potentially_offerable_method_for_operator_smoke');
    if ($manualOn || $on['nowpayments']) {
        finding('usdt_rate', $usdt['rate_configured'] ? 'PASS' : 'WARN',
            $usdt['rate_configured'] ? 'explicit_settings_rate_available' :
                'usdt_methods_unavailable_configure_gateway_settings');
    }
}

// The Python operator entrypoint authenticates the release/source and derives
// this metadata projection before starting the CLI worker.
try {
    $contract=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
    if (!is_array($contract) || !$contract) errorExit('arguments','current_schema_contract_required');
} catch (Throwable $e) {errorExit('arguments','current_schema_contract_required');}
if (!extension_loaded('mysqli'))
    errorExit('php_runtime','mysqli_required');
finding('php_runtime','PASS','mysqli_available');
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
    if (inspectSchema($db,$contract)) {
        require_once dirname(__DIR__).'/src/Finance/CheckoutAdmission.php';
        require_once dirname(__DIR__).'/src/Finance/ServiceDelivery.php';
        require_once dirname(__DIR__).'/src/Finance/FollowupOutbox.php';
        require_once dirname(__DIR__).'/src/Finance/WalletTransferFlow.php';
        require_once dirname(__DIR__).'/src/Services/EditFinalization.php';
        $gates=['achbotCheckoutRequireSchema','achbotProviderRequireSchema',
            'achbotWalletLedgerReady','achbotPaymentEvidenceSchemaReady',
            'achbotServiceDeliveryStorageReady','achbotFollowupStorageReady',
            'achbotServiceMutationSchemaReady','achbotAddonSchema','achbotWalletTransferFlowReady'];
        foreach ($gates as $gate) {
            try {$valid=$gate($db)!==false;} catch (Throwable $e) {$valid=false;}
            finding($gate,$valid?'PASS':'BLOCK',$valid?'current_runtime_storage_ready':'current_runtime_storage_incompatible');
        }
    }
    $report['structure_ready']=!array_filter($checks,static fn($c)=>$c['status']==='BLOCK');
    if ($report['structure_ready']) {
        $settings=readSettings($db);
        if ($settings!==null) {
            $state=$settings['BOT_STATES'];$keys=$settings['PAYMENT_KEYS'];
            $valid=policyCheck($state);
            finding('gateway_policies',$valid?'PASS':'BLOCK',$valid?'current_source_parser_accepts_rules':'malformed_or_unknown_rule_denies');
            achbotPreflightApiReadiness($state,$keys);
            if ($valid) methodChecks($state,$keys);
        }
    }
    $db->rollback();
    $db->close();
} catch (Throwable $ignored) {
    $report['structure_ready']=false;
    finding('database_inspection', 'BLOCK', 'read_only_inspection_failed');
}
$bad = count(array_filter($checks,static fn(array $check): bool => $check['status'] === 'BLOCK'));
finish(!$report['structure_ready'] ? 2 : ($bad===0 ? 0 : 3));
