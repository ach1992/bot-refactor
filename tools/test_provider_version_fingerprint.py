#!/usr/bin/env python3
"""Version-free PG target pins preserve old unresolved journals; disposable MariaDB only.

Uses actual native source, two same-instance aliases and real plan pre-PUT SQL
fence. No outbound HTTP mutation, payments, production credentials or panel.
"""
from __future__ import annotations

import argparse
import json
import subprocess
from pathlib import Path

import provider_storage_fixture as storage
import test_shared_plan_review_fixes as shared

ROOT = Path(__file__).resolve().parents[1]
DRIVER = r'''<?php
error_reporting(E_ALL);
set_error_handler(static function($n,$m) {if ($n===E_DEPRECATED) return true;throw new ErrorException($m,0,$n);});
$_SERVER['SCRIPT_FILENAME']=__FILE__;
define('ACHBOT_PG_TEST_LOOPBACK',true);
require_once $argv[1].'/src/Panel/ProviderRuntime.php';
require_once $argv[1].'/src/Subscription/PhysicalService.php';
$db=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$db->set_charset('utf8mb4');
$spec=json_decode($argv[4],true,64,JSON_THROW_ON_ERROR);
$orderId=(int)$spec['order'];
$order=$db->query('SELECT * FROM orders_list WHERE id='.$orderId)->fetch_assoc();
try {
 if ($spec['op']==='fingerprint') {
  $out=['new'=>achbotPhysicalTargetFingerprint($db,$order),
   'legacy'=>achbotPhysicalTargetFingerprint($db,$order,true),
   'revision2'=>achbotPhysicalTargetForIntent($db,$order,
    ['provider'=>'pasarguard','physical_target_format'=>2]),
   'original'=>achbotPhysicalTargetForIntent($db,$order,['provider'=>'pasarguard'])];
 } elseif ($spec['op']==='intent') {
  $out=achbotPhysicalTargetForIntent($db,$order,$spec['intent']);
 } elseif ($spec['op']==='before_write') {
  achbotPhysicalBeforeNativeWrite($db,$spec['intent'],(int)$spec['journal']);
  $out='allowed';
 } elseif ($spec['op']==='identity') {
  $out=achbotPhysicalPgIdentityFingerprint($spec['ctx']);
 } elseif ($spec['op']==='order_context') {
  $out=achbotPhysicalPgIdentityFingerprint(achbotPgOrderContext($db,$orderId));
 } else throw new RuntimeException('bad_fixture_operation');
} catch (Throwable $e) {$out=['held'=>$e->getMessage()];}
$db->close();
echo json_encode($out,JSON_THROW_ON_ERROR);
'''


def main() -> None:
    cli = argparse.ArgumentParser()
    cli.add_argument('--run-disposable', action='store_true', required=True)
    cli.add_argument('--report', type=Path)
    args = cli.parse_args()
    runtime=int(subprocess.check_output(['php','-r','echo PHP_VERSION_ID;'], text=True))
    if not 80500 <= runtime < 80600:
        raise RuntimeError('PHP 8.5 required for current-source acceptance')
    checks: list[str] = []

    def ok(expr: bool, name: str) -> None:
        if not expr:
            raise AssertionError(name)
        checks.append(name)

    with shared.harness('pasarguard') as h:
        db = h['db']
        order = h['attach'](1001)
        peer = h['attach'](1002)
        work = h['work']
        driver = work / 'version-fingerprint.php'
        driver.write_text(DRIVER)
        init_finance = db('SELECT userid,wallet FROM users ORDER BY userid;'
                          'SELECT id,price,state FROM pays ORDER BY id;'
                          'SELECT id,ucount FROM server_info ORDER BY id')
        initial_writes = len(h['writes']())
        # Two genuine aliases of one physical connection have different
        # informational versions while all actual credentials/endpoint match.
        db("INSERT INTO server_info(id,title,ucount,remark,flag,active,state) "
           "VALUES(5,'Native alias',10,'alias','',1,1);" + storage.clone(5, 2) +
           "UPDATE server_config SET provider_version='5.6.0' WHERE id=5")

        def run(op: str, **fields):
            spec = {'op': op, 'order': order, **fields}
            p = subprocess.run(['php', '-d', 'display_errors=0', '-d', 'log_errors=0',
                str(driver), str(ROOT), str(work / 'mysql.sock'), shared.DB,
                json.dumps(spec)], capture_output=True, text=True, timeout=20,
                cwd=work, env=h['env'])
            if p.returncode or p.stderr:
                raise AssertionError(('php_driver', p.returncode, p.stderr[:1200]))
            return json.loads(p.stdout)

        old = run('fingerprint')
        assert 'new' in old, ('fixture_fingerprint_failed', old)
        ok(old['new'] == old['revision2'] and old['legacy'] == old['original']
           and old['new'] != old['legacy'], 'new_and_legacy_digest_are_distinct')
        intent = dict(version=1, provider='pasarguard',
            lock_key='achbot-plan-' + 'b' * 40,
            source_order_id=order, selected_server_id=2,
            new_plan_id=7, physical_target_format=2,
            physical_target_sha256=old['new'],
            old_rows=[dict(id=i, server_id=2, fileid=0) for i in [order, peer]])
        legacy = dict(intent)
        legacy.pop('physical_target_format')
        legacy['physical_target_sha256'] = old['legacy']

        # Version-only changes to both selected and peer alias do not affect
        # the new digest; the original historic digest still notices drift.
        db("UPDATE server_config SET provider_version='5.4.2' WHERE id=2;"
           "UPDATE server_config SET provider_version=NULL WHERE id=5")
        current = run('fingerprint')
        ok(current['new'] == old['new'] and current['legacy'] != old['legacy'],
           'version_metadata_change_does_not_rebind_new_target')
        ok(run('intent', intent=intent) == old['new'],
           'new_intent_ignores_version_only_drift')
        ok(run('intent', intent=legacy) != old['legacy'],
           'historic_immutable_intent_keeps_exact_old_version_pin')
        ok(run('intent', intent={**intent, 'physical_target_format': 999})
           == {'held': 'physical_target_format_unverified'},
           'unknown_future_format_does_not_fallback')
        ok(run('intent', intent={**intent, 'physical_target_format': None})
           == {'held': 'physical_target_format_unverified'},
           'null_format_does_not_infer_legacy')

        # Actual source pre-write SQL guard, NOT a remote PUT. Persist one
        # synthetic dispatching journal at a time; never rewrite a real one.
        def journal(document):
            encoded = storage.text(json.dumps(document, separators=(',', ':')))
            db('INSERT INTO service_plan_changes(lock_key,source_order_id,plan_id,'
               'server_id,provider,state,intent_json) VALUES('
               + ','.join([storage.text(document['lock_key']), str(order), '7',
                            '2', "'pasarguard'", "'dispatching'", encoded]) + ');')
            return int(db('SELECT MAX(id) FROM service_plan_changes'))

        fresh_id = journal(intent)
        ok(run('before_write', intent=intent, journal=fresh_id) == 'allowed',
           'real_before_write_admits_version_only_change_on_new_journal')
        legacy_id = journal(legacy)
        ok(run('before_write', intent=legacy, journal=legacy_id)
           == {'held': 'physical_before_put_target_changed'},
           'historic_unresolved_journal_remains_held_after_version_change')
        ok(db(f'SELECT state FROM service_plan_changes WHERE id={legacy_id}')
           == 'dispatching', 'historic_journal_not_rewritten_or_replayed')

        # Changed physical credentials/instance/URL can never pass the new
        # pin, even when aliases are edited consistently. Restore each before
        # moving to another negative path; the journal remains immutable.
        original_key = 'fixture-private-key'
        db("UPDATE server_config SET password='changed-fixture-key' "
           "WHERE provider_connection_id=1")
        ok(run('before_write', intent=intent, journal=fresh_id)
           == {'held': 'physical_before_put_target_changed'},
           'credential_drift_holds_new_journal')
        db('UPDATE server_config SET password=' + storage.text(original_key) +
           ' WHERE provider_connection_id=1')
        db("UPDATE server_config SET provider_instance_key=REPEAT('c',32) "
           "WHERE provider_connection_id=1")
        ok(run('before_write', intent=intent, journal=fresh_id)
           == {'held': 'physical_before_put_target_changed'},
           'instance_drift_holds_new_journal')
        db("UPDATE server_config SET provider_instance_key=REPEAT('b',32) "
           "WHERE provider_connection_id=1")
        db("UPDATE server_config SET panel_url='http://localhost.invalid' "
           "WHERE provider_connection_id=1")
        # The panel URL still has to be valid and both aliases remain aligned;
        # with a changed endpoint the connection's own API/address validator
        # may reject before the fingerprint comparison, also a safe hold.
        endpoint = run('before_write', intent=intent, journal=fresh_id)
        ok(isinstance(endpoint, dict) and endpoint.get('held') in
           ('physical_before_put_target_changed', 'pg_panel_config_invalid',
            'provider_panel_invalid', 'pg_endpoint_changed'),
           'endpoint_drift_holds_new_journal')
        db('UPDATE server_config SET panel_url=' + storage.text(h['url']) +
           ' WHERE provider_connection_id=1')

        original_ctx = dict(account={'id':41}, identity={
            'remote_user_id':41,'remote_created_at':'2026-10-26T05:00:00+00:00'},
            order_server_id=2)
        original_identity = run('identity', ctx=original_ctx)
        altered = dict(original_ctx, identity=dict(original_ctx['identity'], remote_user_id=42))
        ok(run('identity', ctx=altered) != original_identity,
           'separate_native_account_identity_pin_remains_active')
        valid_account_pin = run('order_context')
        ok(isinstance(valid_account_pin, str) and len(valid_account_pin) == 64,
           'actual_linked_account_identity_resolves_before_drift')
        db(f'UPDATE orders_list SET provider_remote_user_id=provider_remote_user_id+1 WHERE id={order}')
        stale_account = run('order_context')
        ok(isinstance(stale_account, dict) and 'held' in stale_account,
           'changed_actual_order_remote_identity_fails_closed')
        db(f'UPDATE orders_list SET provider_remote_user_id=provider_remote_user_id-1 WHERE id={order}')
        ok(run('order_context') == valid_account_pin,
           'native_order_identity_pin_restored_only_after_exact_repair')
        ok(len(h['writes']()) == initial_writes and
           db('SELECT userid,wallet FROM users ORDER BY userid;'
              'SELECT id,price,state FROM pays ORDER BY id;'
              'SELECT id,ucount FROM server_info WHERE id<>5 ORDER BY id') == init_finance and
           db(f'SELECT state FROM service_plan_changes WHERE id={legacy_id}')
           == 'dispatching',
           'no_native_put_payment_mutation_or_automatic_replay')

    # Confirm the production plan-selection controller actually writes the
    # new format marker. Here native access is already equivalent, so it
    # finalizes a local plan choice without any provider mutation.
    with shared.harness('pasarguard') as h:
        db = h['db']
        source = h['attach'](1001)
        h['attach'](1002)
        db("INSERT INTO server_info(id,title,ucount,remark,flag,active,state) "
           "VALUES(5,'Native alias',10,'alias','',1,1);" + storage.clone(5, 2) +
           "UPDATE server_config SET provider_version='5.6.0' WHERE id=5")
        previous_writes = len(h['writes']())
        before_finance = db('SELECT userid,wallet FROM users ORDER BY userid;'
                            'SELECT id,price,state FROM pays ORDER BY id')
        h['call']('extensions', data=f'confirmChangePlan_{source}_7')
        recorded = json.loads(db('SELECT intent_json FROM service_plan_changes '
                                 'ORDER BY id DESC LIMIT 1'))
        ok(recorded.get('provider') == 'pasarguard' and
           recorded.get('physical_target_format') == 2 and
           isinstance(recorded.get('physical_target_sha256'), str) and
           len(recorded['physical_target_sha256']) == 64,
           'actual_new_plan_controller_persists_version_free_format')
        ok(db('SELECT state FROM service_plan_changes ORDER BY id DESC LIMIT 1')
           == 'committed' and
           db(f'SELECT fileid FROM orders_list WHERE id={source}') == '7' and
           len(h['writes']()) == previous_writes and
           db('SELECT userid,wallet FROM users ORDER BY userid;'
              'SELECT id,price,state FROM pays ORDER BY id') == before_finance,
           'actual_new_plan_controller_preserves_native_and_financial_effects')

    report = {'complete': True, 'cases': len(checks), 'checks': checks,
              'disposable_mariadb': True, 'source_php85': True,
              'native_puts': 0, 'live_panel_requests': 0}
    if args.report:
        args.report.write_text(json.dumps(report, indent=2) + '\n')
    print(json.dumps(report))


if __name__ == '__main__':
    main()
