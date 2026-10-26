#!/usr/bin/env python3
"""AChBot #51: real PHP read-only preflight against an owned MariaDB and ZIP-shaped runtime.

The test creates only synthetic databases, never reads real baseInfo.php,
never calls Telegram/providers, and does not touch any installed bot.
"""
from __future__ import annotations
import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess

import package_post_v9 as package
import test_access_state as fixture

ROOT = Path(__file__).resolve().parents[1]
DB = 'achbot_v4_new'
SECRET = 'SYNTHETIC_HOSTED_SECRET_NEVER_REPORT_2026'
PASSWORD = 'fixture_readonly_only_secret'

def require(verified: bool, check_name: str) -> None:
    if not verified:
        raise RuntimeError('readiness_regression_' + check_name)


def sql(d: Path, text: str, *, user: str = 'root', password: str = '') -> str:
    env = dict(os.environ, MYSQL_PWD=password)
    cmd = ['mariadb', '--no-defaults', '--protocol=SOCKET',
           '--socket='+str(d/'mysql.sock'), '--user='+user,
           '--batch', '--skip-column-names', DB]
    p = subprocess.run(cmd, input=text, text=True, capture_output=True,
                       env=env, timeout=15)
    if p.returncode != 0:
        raise RuntimeError('fixture_sql_failure')
    return p.stdout


def statement(d: Path, text: str) -> None:
    sql(d, text)


def seed(d: Path, state: dict, keys: dict) -> None:
    def literal(obj: dict) -> str:
        return json.dumps(obj, separators=(',', ':'), ensure_ascii=False).replace("'", "''")
    statement(d, "DELETE FROM setting WHERE type IN ('BOT_STATES','PAYMENT_KEYS');" +
        "INSERT INTO setting(type,value) VALUES " +
        "('BOT_STATES','"+literal(state)+"'),"+
        "('PAYMENT_KEYS','"+literal(keys)+"');")


def report_run(d: Path, *, manifest: bool = True,
               env_update: dict | None = None) -> tuple[int, dict, str]:
    environment = dict(os.environ,
        ACHBOT_PREFLIGHT_DB_HOST='localhost',
        ACHBOT_PREFLIGHT_DB_NAME=DB,
        ACHBOT_PREFLIGHT_DB_USER='achbot_preflight_ro',
        ACHBOT_PREFLIGHT_DB_PASSWORD=PASSWORD,
        ACHBOT_PREFLIGHT_DB_SOCKET=str(d/'mysql.sock'))
    if env_update: environment.update(env_update)
    cmd = ['php', str(ROOT/'tools/payment_readiness_preflight.php')]
    if manifest:
        cmd.extend(['--manifest', str(d/'manifest.json'),
                    '--runtime-dir', str(d/'runtime')])
    result = subprocess.run(cmd, env=environment, cwd=ROOT, text=True,
                            capture_output=True, timeout=20)
    require((SECRET not in (result.stdout+result.stderr)), 'line_69')
    require((PASSWORD not in (result.stdout+result.stderr)), 'line_70')
    require((result.stderr == ''), 'line_71')
    data = json.loads(result.stdout)
    require((data['completed'] is True), 'line_73')
    require((data['scope'] == 'read_only_local_source_and_database'), 'line_74')
    require((data['ready_for_isolated_operator_smoke'] == (result.returncode == 0)), 'line_75')
    return result.returncode, data, result.stdout


def has(data: dict, name: str, status: str, code: str | None = None) -> bool:
    return any(c['id'] == name and c['status'] == status and
               (code is None or c['code'] == code) for c in data['checks'])



# Compare preflight against source-real configuration/authorization functions.
# Both helpers run with synthetic globals and a SELECT-only disposable DB;
# neither loads private configuration nor produces a real customer effect.
SOURCE_USDT_DRIVER = r"""
$root=$argv[1];
$GLOBALS['paymentKeys']=json_decode($argv[2],true,512,JSON_THROW_ON_ERROR);
$GLOBALS['USDTPrice']=1000;
$GLOBALS['USDTPriceagent']=1000;
require $root.'/src/Finance/ReceiptHelpers.php';
$available=[];
foreach (['BEP20','TRC20','ERC20'] as $network)
    $available[$network]=achbotManualUsdtConfiguration($network)!==null;
echo json_encode($available,JSON_THROW_ON_ERROR);
"""
SOURCE_POLICY_DRIVER = r"""
$root=$argv[1];$socket=$argv[2];$dbName=$argv[3];
$GLOBALS['botState']=json_decode($argv[4],true,512,JSON_THROW_ON_ERROR);
$db=new mysqli('localhost','achbot_preflight_ro',
    getenv('ACHBOT_PREFLIGHT_DB_PASSWORD'),$dbName,0,$socket);
require $root.'/src/Finance/PaymentLifecycle.php';
echo json_encode(achbotPayUxAudienceAllowed($db,'wallet',9002,false),JSON_THROW_ON_ERROR);
"""

def source_usdt(keys: dict) -> bool:
    p=subprocess.run(['php','-r',SOURCE_USDT_DRIVER,str(ROOT),
                      json.dumps(keys,separators=(',',':'))],
                     text=True,capture_output=True,timeout=8)
    require(p.returncode==0 and not p.stderr,'production_usdt_driver')
    return any(json.loads(p.stdout).values())


def source_wallet_allowed(d: Path, state: dict) -> bool:
    p=subprocess.run(['php','-r',SOURCE_POLICY_DRIVER,str(ROOT),
                      str(d/'mysql.sock'),DB,json.dumps(state,separators=(',',':'))],
                     text=True,capture_output=True,timeout=8,
                     env=dict(os.environ,ACHBOT_PREFLIGHT_DB_PASSWORD=PASSWORD))
    require(p.returncode==0 and not p.stderr,'production_wallet_policy_driver')
    return json.loads(p.stdout)


def rule(category: str = 'all', enabled: bool = True) -> dict:
    return {'category':category,'enabled':enabled,'registration_age_days':0,
            'metrics':{key:{'count':0,'days':0,'direction':'older'}
                       for key in ('buy','renew','volume','time','topup')}}

def main() -> dict:
    cases = []
    with fixture.owned_database() as (d, _env):
        runtime = d/'runtime'
        runtime.mkdir()
        files = package.members()
        require((len(files) == 103), 'line_90')
        checksums = {}
        for name in files:
            original = ROOT/name
            target = runtime/name
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(original, target)
            raw = original.read_bytes()
            checksums[name] = {'size': len(raw),
                               'sha256': hashlib.sha256(raw).hexdigest()}
        (runtime/'baseInfo.php').write_text('<?php // private synthetic sentinel; never open\n')
        private_before = (runtime/'baseInfo.php').read_bytes()
        manifest = {
            'candidate': subprocess.check_output(['git','rev-parse','HEAD'], cwd=ROOT).decode().strip(),
            'file_count': len(checksums), 'files': checksums,
            'schema_migration_included': False, 'private_config_included': False,
        }
        (d/'manifest.json').write_text(json.dumps(manifest))
        # A real MariaDB account with SELECT only, not just a claimed read-only query.
        statement(d, "CREATE USER 'achbot_preflight_ro'@'localhost' IDENTIFIED BY '"+PASSWORD+"';"+
                  "GRANT SELECT ON `"+DB+"`.* TO 'achbot_preflight_ro'@'localhost';")
        p = subprocess.run(['mariadb','--no-defaults','--protocol=SOCKET',
                           '--socket='+str(d/'mysql.sock'), '--user=achbot_preflight_ro',
                           DB, '-e', 'UPDATE setting SET value=\'forbidden\' WHERE id=50'],
                           env=dict(os.environ, MYSQL_PWD=PASSWORD),
                           text=True,capture_output=True,timeout=8)
        require((p.returncode != 0), 'line_116')
        code, result, _ = report_run(d)
        require((code == 2 and has(result,'schema_003','BLOCK','missing')), 'line_118')
        require((has(result,'setting_bot_states','BLOCK','document_missing')), 'line_119')
        cases.append('missing_ledger_and_settings_fail_closed')

        for name in ('001_marzban_panel_group_id.sql',
                     '003_payment_attempts.sql',
                     '004_automatic_renewal_attempts.sql',
                     '005_wallet_adjustments.sql',
                     '006_payment_message_refs.sql',
                     '007_service_effects.sql',
                     '008_referral_awards.sql',
                     '009_bank_buy_invoice_claims.sql'):
            statement(d, (ROOT/'migrations/post-v9'/name).read_text())
        state = {
            'botState':'on', 'sellState':'on',
            'walletState':'on', 'nowPaymentOther':'off', 'nowPaymentWallet':'off',
            'zarinpal':'off', 'nextpay':'off', 'cartToCartState':'off',
            'giftCardState':'off', 'manualUsdtState':'off',
        }
        keys = {'nowpayment':SECRET, 'nextpay':'', 'holderName':'', 'bankAccount':''}
        seed(d, state, keys)
        data_before = sql(d, "SELECT userid,wallet FROM users ORDER BY id;"+
                          "SELECT type,value FROM setting ORDER BY id;")
        code, result, raw = report_run(d)
        require((code == 0), 'line_142')
        require((result['source_file_count'] == 103), 'line_143')
        require((all(result['schema'][k] == 'columns_and_named_unique_indexes_detected_not_ddl_certified'
                   for k in ('001','003','004','005','006','007','008','009'))), 'line_144')
        require((has(result,'gateway_policies','PASS')), 'line_146')
        require((result['methods']['wallet']['switch'] == 'on'), 'line_147')
        require((result['methods']['nowpayments']['switch'] == 'off'), 'line_148')
        require((data_before == sql(d,"SELECT userid,wallet FROM users ORDER BY id;"+
                                  "SELECT type,value FROM setting ORDER BY id;")), 'line_149')
        require(((runtime/'baseInfo.php').read_bytes() == private_before), 'line_151')
        cases.append('complete_migration_shapes_and_wallet_only_pass_no_sql_mutation')

        # Current parsers, not a lax lookalike: invalid version or unknown
        # method policy is a blocking finding even if another method works.
        badstate = dict(state, gatewayRules={'version':1,'methods':{
            'wallet':[{'category':'non_agents','enabled':'on'}]}})
        seed(d, badstate, keys)
        code, result, _ = report_run(d)
        require((code == 2 and has(result,'gateway_policies','BLOCK')), 'line_160')
        cases.append('malformed_explicit_rules_block')

        badstate = dict(state, gatewayAudience={'wallet':'allow_anyone'})
        seed(d, badstate, keys)
        code, result, _ = report_run(d)
        require((code == 2 and has(result,'gateway_policies','BLOCK')), 'line_166')
        cases.append('unknown_legacy_audience_blocks')

        seed(d, state, keys)
        statement(d,"INSERT INTO setting(type,value) VALUES ('PAYMENT_KEYS','{}')")
        code, result, _ = report_run(d)
        require((code == 2 and has(result,'setting_payment_keys','BLOCK','duplicate_document')), 'line_172')
        cases.append('duplicate_payment_document_blocks')

        seed(d, dict(state, nowPaymentOther='on'), dict(keys, nowpayment=''))
        code, result, _ = report_run(d)
        require((code == 2 and has(result,'method_nowpayments','BLOCK')), 'line_177')
        cases.append('enabled_hosted_gateway_without_merchant_blocks')

        # JSON [] decodes to a PHP array like {}, but it is not a valid
        # persisted settings object and must be rejected.
        seed(d, state, keys)
        statement(d, "DELETE FROM setting WHERE type='PAYMENT_KEYS';"+
                  "INSERT INTO setting(type,value) VALUES ('PAYMENT_KEYS','[]');")
        code, result, _ = report_run(d)
        require(code == 2 and has(result,'setting_payment_keys','BLOCK',
                                   'invalid_object_document'), 'list_settings_not_object')
        cases.append('json_list_not_accepted_as_settings_object')

        # One enabled manual network with the other intentionally absent is
        # legal: the shared switch does not require BOTH assets to be offered.
        only_usdt = dict(state, walletState='off', manualUsdtState='on')
        seed(d, only_usdt, {'manualUsdtWalletBEP20':'0x' + 'a'*40})
        code, result, _ = report_run(d)
        require(code == 0 and has(result,'method_manual_trx','WARN') and
                result['methods']['manual_trx']['local_configuration']=='not_offered',
                'usdt_only_manual_config')
        cases.append('usdt_only_manual_network_is_not_blocked_by_missing_trx')

        only_trx = dict(state, walletState='off', manualUsdtState='on')
        seed(d, only_trx, {'manualTrxWallet':'TJRabPrwbZy45sbavfcjinPJC18kjpRTv8',
                          'manualTrxRateToman':'1000'})
        code, result, _ = report_run(d)
        require(code == 0 and has(result,'method_manual_usdt','WARN') and
                result['methods']['manual_usdt']['local_configuration']=='not_offered',
                'trx_only_manual_config')
        cases.append('trx_only_manual_network_is_not_blocked_by_missing_usdt')

        seed(d, only_trx, {})
        code, result, _ = report_run(d)
        require(code == 2 and has(result,'method_manual_trx','BLOCK') and
                has(result,'method_manual_usdt','BLOCK'), 'no_manual_destination')
        cases.append('shared_manual_switch_without_any_destination_blocks')

        seed(d, dict(state,nowPaymentOther='on'), {'nowpayment':['bad-type']})
        code, result, _ = report_run(d)
        require(code == 2 and has(result,'method_nowpayments','BLOCK'),
                'merchant_field_non_string')
        cases.append('non_string_merchant_credential_not_treated_as_configured')

        # Review R1: source parity, including an explicitly cleared network,
        # invalid or bounded rates, legacy fallback and other-network choice.
        # This matrix contains no other enabled payment method, so false PASS
        # directly changes overall ready/exit status.
        addr='0x'+'a'*40
        source_cases=(
            ('usdt_explicit_clear_overrides_legacy',
             {'manualUsdtNetwork':'BEP20','manualUsdtWallet':addr,
              'manualUsdtWalletBEP20':'','manualUsdtRateToman':'1000'},False,False),
            ('usdt_invalid_explicit_rate',
             {'manualUsdtWalletBEP20':addr,'manualUsdtRateToman':'invalid'},False,False),
            ('usdt_explicit_rate_below_minimum',
             {'manualUsdtWalletBEP20':addr,'manualUsdtRateToman':'99'},False,False),
            ('usdt_explicit_rate_above_maximum',
             {'manualUsdtWalletBEP20':addr,'manualUsdtRateToman':'1000000001'},False,False),
            ('usdt_explicit_infinite_rate',
             {'manualUsdtWalletBEP20':addr,'manualUsdtRateToman':'1e400'},False,False),
            ('usdt_explicit_rate_valid',
             {'manualUsdtWalletBEP20':addr,'manualUsdtRateToman':'1000'},True,False),
            ('usdt_explicit_rate_upper_boundary',
             {'manualUsdtWalletBEP20':addr,'manualUsdtRateToman':'1000000000'},True,False),
            ('usdt_legacy_bep20_default',
             {'manualUsdtWallet':addr,'manualUsdtRateToman':'1000'},True,False),
            ('usdt_valid_trc20',
             {'manualUsdtWalletTRC20':'TNPeeaaFB7K9cmo4uQpcU32zGK8G1NYqeL',
              'manualUsdtRateToman':'1000'},True,False),
            ('usdt_valid_erc20',
             {'manualUsdtWalletERC20':addr,'manualUsdtRateToman':'1000'},True,False),
            ('usdt_valid_legacy_private_rate_unverified',
             {'manualUsdtWalletBEP20':addr},True,True),
        )
        for label, money_keys, expected, private_rate in source_cases:
            require(source_usdt(money_keys) is expected,'source_'+label)
            seed(d,only_usdt,money_keys)
            code,result,raw=report_run(d)
            require((code==0)==expected,'exit_'+label)
            require(has(result,'method_manual_usdt','PASS' if expected else 'BLOCK'),
                    'method_'+label)
            require(has(result,'method_selection','PASS' if expected else 'BLOCK'),
                    'selection_'+label)
            require(has(result,'manual_usdt_rate','WARN')==private_rate,
                    'private_rate_review_'+label)
            require(result['methods']['manual_usdt']['local_configuration']==
                    ('present_not_verified' if expected else 'missing_or_invalid'),
                    'status_'+label)
            require(all(str(secret) not in raw for secret in (addr,SECRET,PASSWORD,
                   'TNPeeaaFB7K9cmo4uQpcU32zGK8G1NYqeL')),
                   'privacy_'+label)
            cases.append(label)

        # A known-bad explicit USDT rate must not be hidden behind a valid
        # TRX fallback. The bad configuration remains a blocking diagnostic.
        both_crypto=dict(state,walletState='off',manualUsdtState='on')
        seed(d,both_crypto,{'manualUsdtWalletBEP20':addr,
             'manualUsdtRateToman':'invalid',
             'manualTrxWallet':'TJRabPrwbZy45sbavfcjinPJC18kjpRTv8',
             'manualTrxRateToman':'1000'})
        code,result,_=report_run(d)
        require(code==2 and has(result,'method_manual_usdt','BLOCK') and
                has(result,'method_manual_trx','PASS'),
                'usdt_bad_explicit_rate_not_masked_by_trx')
        cases.append('usdt_invalid_rate_remains_blocking_when_trx_is_valid')

        # Review R2: policy precedence is source-real and independent of
        # customer history. Here the enabled blank 'all' rule is genuinely
        # satisfiable for synthetic userid 9002; no real customer is read.
        policy_cases=(
            ('legacy_off_wallet_only',
             dict(state,gatewayAudience={'wallet':'off'}),False),
            ('empty_explicit_new_rule_denies_wallet',
             dict(state,gatewayRules={'version':1,'methods':{'wallet':[]}}),False),
            ('all_disabled_rules_deny_wallet',
             dict(state,gatewayRules={'version':1,'methods':{'wallet':[rule(enabled=False)]}}),False),
            ('enabled_new_rule_allows_wallet',
             dict(state,gatewayRules={'version':1,'methods':{'wallet':[rule()]}}),True),
            ('new_enabled_rule_overrides_legacy_off',
             dict(state,gatewayAudience={'wallet':'off'},
                  gatewayRules={'version':1,'methods':{'wallet':[rule()]}}),True),
            ('legacy_off_when_new_document_omits_wallet',
             dict(state,gatewayAudience={'wallet':'off'},
                  gatewayRules={'version':1,'methods':{}}),False),
        )
        for label,policy_state,allow in policy_cases:
            require(source_wallet_allowed(d,policy_state) is allow,
                    'source_policy_'+label)
            seed(d,policy_state,keys)
            code,result,_=report_run(d)
            require((code==0)==allow,'policy_exit_'+label)
            require(has(result,'method_wallet','PASS' if allow else 'WARN',
                        None if allow else 'configured_but_policy_denied'),
                    'method_policy_'+label)
            require(has(result,'method_selection','PASS' if allow else 'BLOCK'),
                    'selection_policy_'+label)
            require(result['methods']['wallet']['offerability']==
                    ('potentially_offerable' if allow else 'configured_but_policy_denied'),
                    'offerability_'+label)
            require(result['methods']['wallet']['actual_customer_eligibility']==
                    'not_tested','not_customer_authorization_'+label)
            cases.append(label)

        # Denied wallet must not make a valid independent bank method fail.
        bank_alternative=dict(state,cartToCartState='on',
                              gatewayAudience={'wallet':'off'})
        require(source_wallet_allowed(d,bank_alternative) is False,
                'source_wallet_denied_bank_available')
        seed(d,bank_alternative,{'bankAccount':'123-456','holderName':'Test'})
        code,result,_=report_run(d)
        require(code==0 and has(result,'method_wallet','WARN',
                                'configured_but_policy_denied') and
                has(result,'method_bank_transfer','PASS') and
                has(result,'method_selection','PASS') and
                result['methods']['bank_transfer']['offerability']=='potentially_offerable',
                'denied_wallet_with_bank_fallback')
        cases.append('denied_wallet_with_other_potentially_available_method')

        # Source-synchronized gift, bank, and TRX validation. A mere HTTPS
        # prefix, nonempty bank string, or positive huge rate is insufficient.
        gift_state = dict(state, walletState='off', giftCardState='on')
        seed(d, gift_state, {'giftCardUrl':'https://alice:password@example.invalid/gift'})
        code, result, _ = report_run(d)
        require(code == 2 and has(result, 'method_gift_card', 'BLOCK'),
                'userinfo_in_gift_url')
        seed(d, gift_state, {'giftCardUrl':'https://example.invalid/gift#products'})
        code, result, _ = report_run(d)
        require(code == 0 and has(result, 'method_gift_card', 'PASS'),
                'valid_gift_url_with_fragment')
        cases.append('gift_url_matches_source_checkout_validation')

        bank_state = dict(state, walletState='off', cartToCartState='on')
        seed(d, bank_state, {'bankAccount':'X'*151, 'holderName':'Test'})
        code, result, _ = report_run(d)
        require(code == 2 and has(result, 'method_bank_transfer', 'BLOCK'),
                'long_bank_detail')
        seed(d, bank_state, {'bankAccount':'123-456', 'holderName':'Test'})
        code, result, _ = report_run(d)
        require(code == 0 and has(result, 'method_bank_transfer', 'PASS'),
                'valid_bank_detail')
        cases.append('bank_details_match_checkout_length_guard')

        seed(d, only_trx, {'manualTrxWallet':'TJRabPrwbZy45sbavfcjinPJC18kjpRTv8',
                          'manualTrxRateToman':'1234567890'})
        code, result, _ = report_run(d)
        require(code == 2 and has(result, 'method_manual_trx', 'BLOCK'),
                'trx_rate_oversized')
        cases.append('trx_rate_follows_source_nine_digit_limit')

        # A malicious substituted inventory must be rejected before it can
        # read arbitrary installed paths (including baseInfo.php).
        manifest_file = d/'manifest.json'
        data = json.loads(manifest_file.read_text())
        old_name = 'src/Finance/PaymentLifecycle.php'
        old_record = data['files'].pop(old_name)
        data['files']['baseInfo.php'] = {
            'size':len(private_before),
            'sha256':hashlib.sha256(private_before).hexdigest(),
        }
        manifest_file.write_text(json.dumps(data))
        code, result, _ = report_run(d)
        require(code == 2 and has(result,'source_identity','BLOCK'),
                'unexpected_member_inventory')
        data['files'].pop('baseInfo.php')
        data['files'][old_name] = old_record
        manifest_file.write_text(json.dumps(data))
        cases.append('untrusted_manifest_cannot_add_private_member')

        seed(d, state, keys)
        code, result, _ = report_run(d, manifest=False)
        require((code == 2 and has(result,'source_identity','BLOCK')), 'line_182')
        cases.append('unverified_source_identity_blocks')

        # Even matching remote file bytes + supplied manifest cannot make
        # this checkout's source-real parser authoritative if helper versions
        # disagree. Both local helper bytes and installed bytes are pinned.
        helper_name = 'src/Finance/GatewayRules.php'
        helper_file = runtime/helper_name
        helper_original = helper_file.read_bytes()
        changed_helper = helper_original + b'\n// foreign fixture version\n'
        helper_file.write_bytes(changed_helper)
        manifest_path = d/'manifest.json'
        changed_manifest = json.loads(manifest_path.read_text())
        changed_manifest['files'][helper_name] = {
            'size': len(changed_helper),
            'sha256': hashlib.sha256(changed_helper).hexdigest(),
        }
        manifest_path.write_text(json.dumps(changed_manifest))
        code, result, _ = report_run(d)
        require(code == 2 and has(result, 'source_identity', 'BLOCK'),
                'foreign_source_parser')
        helper_file.write_bytes(helper_original)
        changed_manifest['files'][helper_name] = {
            'size': len(helper_original),
            'sha256': hashlib.sha256(helper_original).hexdigest(),
        }
        manifest_path.write_text(json.dumps(changed_manifest))
        cases.append('helper_source_mismatch_with_manifest_blocks')

        member='src/Finance/PaymentLifecycle.php'
        target=runtime/member
        target.write_bytes(target.read_bytes()+b'\n')
        code, result, _ = report_run(d)
        require((code == 2 and has(result,'source_identity','BLOCK')), 'line_189')
        shutil.copyfile(ROOT/member,target)
        cases.append('runtime_byte_drift_blocks')

        old=runtime/'settings/tronChecker.php'
        old.write_text('<?php // synthetic retired member\n')
        code, result, _ = report_run(d)
        require((code == 2 and has(result,'source_identity','BLOCK')), 'line_196')
        old.unlink()
        cases.append('retired_runtime_member_blocks')

        # Current final payment/cohort SQL explicitly FORCE INDEXes these
        # original-schema keys. No safe smoke can ignore a missing name.
        statement(d, 'ALTER TABLE users DROP INDEX idx_users_userid_v1')
        code, result, _ = report_run(d)
        require(code == 2 and has(result, 'final_payment_policy_indexes',
                                  'BLOCK'), 'missing_final_auth_index')
        statement(d, 'ALTER TABLE users ADD KEY idx_users_userid_v1 (userid)')
        cases.append('missing_required_final_authorization_index_blocks')

        # Successful DDL detection alone does not prove correct migration
        # provenance: corrupting a named unique index must be detected.
        statement(d,'ALTER TABLE payment_attempts DROP INDEX uq_payment_attempt_ref')
        code, result, _ = report_run(d)
        require((code == 2 and has(result,'schema_003','BLOCK')), 'line_204')
        cases.append('partial_003_unique_index_missing_blocks')

        bad_password='SYNTHETIC_BAD_DATABASE_PASSWORD_DO_NOT_REPORT'
        code, result, raw = report_run(d, env_update={
            'ACHBOT_PREFLIGHT_DB_PASSWORD':bad_password})
        require((code == 2 and has(result,'database_inspection','BLOCK')), 'line_210')
        require((bad_password not in raw), 'line_211')
        cases.append('database_connection_failure_sanitized')
        require(((runtime/'baseInfo.php').read_bytes() == private_before), 'line_213')
        require((data_before == sql(d,"SELECT userid,wallet FROM users ORDER BY id;"+
                                  "SELECT type,value FROM setting ORDER BY id;")), 'line_214')
        cases.append('private_sentinel_and_financial_rows_untouched')
    return {
        'complete':True, 'cases':len(cases), 'details':cases,
        'readonly_sql_account_enforced':True,
        'source_usdt_production_helper_compared':True,
        'source_policy_production_helper_compared':True,
        'source_members_verified':103,
        'original_23_table_schema_used':True,
        'all_eight_additive_shapes_exercised':True,
        'no_real_provider_or_telegram_calls':True,
        'installed_operator_acceptance':False,
    }


if __name__ == '__main__':
    parser=argparse.ArgumentParser()
    parser.add_argument('--run-disposable',action='store_true')
    parser.add_argument('--report',type=Path)
    args=parser.parse_args()
    if not args.run_disposable:
        parser.error('Use --run-disposable to create an owned synthetic MariaDB fixture')
    result=main()
    if args.report:
        args.report.write_text(json.dumps(result,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps(result,ensure_ascii=False))
