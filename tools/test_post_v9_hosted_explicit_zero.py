#!/usr/bin/env python3
"""Real custom BUY_SUB draft -> hosted payment, including literal username '0'.

Actual application Catalogue and GatewayReturn PHP run against one disposable
socket-only MariaDB. Remote create, payment and Telegram are local spies only.
"""
from __future__ import annotations

import argparse
import json
from pathlib import Path

import test_access_state as owned
import test_post_v9_finance_routes as finance
import test_post_v9_purchases as stages
import test_post_v9_wallet_checkout as wallet
import test_v8_native as purchase

ROOT = Path(__file__).resolve().parents[1]


def verify():
    checks = []
    # A literal '0' is a deliberately chosen SINGLE username. Distinct
    # payments cannot all own that same name on one physical provider; run
    # each gateway/mode against its own disposable target, not by deleting
    # any prior recorded effect to manufacture room for another CREATE.
    for provider in ('nextpay', 'zarinpal'):
        for mode in ('default', 'digits', 'manual'):
            with owned.owned_database() as (work, env):
                stage = work / 'current-finance'
                stages.stage_source(stage, True)
                purchase.init(work, env, 'http://127.0.0.1:9')
                sql = lambda q: finance.sql(work, env, q).strip()
                for migration in (
                    '001_marzban_panel_group_id.sql',
                    '003_payment_attempts.sql',
                    '007_service_effects.sql',
                    '022_gateway_review_archive.sql',
                ):
                    sql((ROOT / 'migrations/post-v9' / migration).read_text())
                sql('UPDATE server_plans SET inbound_id=0 WHERE id=7')
                sql('UPDATE server_info SET ucount=20 WHERE id=2')
                catalogue = work / 'catalogue-custom-source.php'
                catalogue.write_text(wallet.PHP_CATALOGUE)

                if provider == 'nextpay' and mode == 'default':
                    # The original form's validator must reject malformed names BEFORE
                    # creating an invoice, not rely on the gateway to rename bad input.
                    invalid = wallet.catalogue(work, env, catalogue,
                                               username='bad/name', hash='hosted-bad-name')
                    assert any(e['name'] == 'MESSAGE' and
                               'Invalid account username' in e['text'] for e in invalid), invalid
                    assert sql("SELECT COUNT(*) FROM pays WHERE hash_id='hosted-bad-name'") == '0'
                    checks.append('original_custom_form_denies_invalid_username_before_invoice')

                hash_id = f'hosted-zero-{provider}-{mode}'
                # Deliberately use the exact real customer BUY_SUB draft
                # constructor. Unlike a hand-seeded pays row, this catches
                # accidental change to original request validation/persistence.
                events = wallet.catalogue(work, env, catalogue,
                                          username='0', hash=hash_id)
                assert any(e['name'] == 'MESSAGE' and
                           'Invalid account username' not in e['text']
                           for e in events), (hash_id, events)
                original = sql(
                    "SELECT id,description,type,price,volume,day,state,agent_count "
                    f"FROM pays WHERE hash_id='{hash_id}'").split('\t')
                assert len(original) == 8, (hash_id, original)
                pay_id = int(original[0])
                assert original[1] == '0' and original[2] == 'BUY_SUB' and \
                    int(original[3]) > 0 and int(original[4]) == 1 and \
                    int(original[5]) == 30 and original[6] == 'pending' and \
                    int(original[7]) == 0, (hash_id, original)
                checks.append(f'custom_draft_{provider}_{mode}_persists_literal_zero')
                sql("INSERT INTO payment_attempts "
                    "(pay_row_id,provider,order_hash,provider_reference,"
                    "quote_amount,quote_currency,local_amount_toman,stage) "
                    f"VALUES({pay_id},'{provider}','{hash_id}',"
                    f"'{hash_id}-invoice',{original[3]},'toman',"
                    f"{original[3]},'pending')")
                wallet_before = sql('SELECT wallet FROM users WHERE userid=1001')
                capacity_before = int(sql('SELECT ucount FROM server_info WHERE id=2'))
                result = finance.run_php(work, env, stage, {
                    'route': 'do_action', 'get': {}, 'provider': provider,
                    'pay_row_id': pay_id, 'scripted_marzban_creates': ['success'],
                    'states': {'remark': '' if mode == 'default' else mode},
                })
                creates = [e for e in result['events']
                           if e['name'] == 'marzban_create_attempt']
                outcome = [e['result'] for e in result['events']
                           if e['name'] == 'do_action_result']
                assert outcome == ['delivered'] and len(creates) == 1 and \
                    creates[0]['username'] == '0' and \
                    creates[0]['held_at_post'] == 1 and \
                    creates[0]['capacity_at_post'] == capacity_before - 1, \
                    (hash_id, outcome, creates)
                assert sql(f'SELECT description,price,state FROM pays WHERE id={pay_id}') == \
                    f"0\t{original[3]}\tpaid"
                assert sql('SELECT stage,provider_status FROM payment_attempts '
                           f'WHERE pay_row_id={pay_id}') == 'done\tdelivered'
                assert sql('SELECT ordinal,remote_username,state,reserved_count,order_id '
                           f'FROM service_effects WHERE pay_row_id={pay_id} '
                           "AND effect_kind='create'").split('\t')[:4] == \
                    ['1', '0', 'local_recorded', '1']
                linked = sql('SELECT o.remark,o.amount,e.remote_username,o.userid '
                             'FROM orders_list o JOIN service_effects e ON '
                             f'e.order_id=o.id WHERE e.pay_row_id={pay_id} '
                             "AND e.effect_kind='create'")
                assert linked == f'0\t{original[3]}\t0\t1001', (hash_id, linked)
                assert sql('SELECT wallet FROM users WHERE userid=1001') == wallet_before
                assert int(sql('SELECT ucount FROM server_info WHERE id=2')) == \
                    capacity_before - 1
                checks.append(f'hosted_{provider}_{mode}_zero_exact_order_effect_finance_capacity')

                replay = finance.run_php(work, env, stage, {
                    'route': 'do_action', 'get': {}, 'provider': provider,
                    'pay_row_id': pay_id, 'scripted_marzban_creates': ['success'],
                    'states': {'remark': '' if mode == 'default' else mode},
                })
                assert not any(e['name'] == 'marzban_create_attempt'
                               for e in replay['events']), (hash_id, replay)
                assert sql('SELECT COUNT(*) FROM orders_list o '
                           'JOIN service_effects e ON e.order_id=o.id '
                           f'WHERE e.pay_row_id={pay_id}') == '1'
                assert sql('SELECT COUNT(*) FROM service_effects '
                           f'WHERE pay_row_id={pay_id}') == '1'
                assert sql('SELECT wallet FROM users WHERE userid=1001') == wallet_before
                assert int(sql('SELECT ucount FROM server_info WHERE id=2')) == \
                    capacity_before - 1
                checks.append(f'hosted_{provider}_{mode}_zero_replay_no_second_effect')

    return {'complete': True, 'cases': len(checks), 'scenarios': checks,
            'real_catalogue_php': True, 'real_gateway_return_php': True,
            'owned_socket_mariadb': True, 'synthetic_merchant_and_native': True,
            'real_external_operations': 0}


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--run-disposable', required=True, action='store_true')
    parser.add_argument('--report', type=Path)
    args = parser.parse_args()
    evidence = verify()
    if args.report:
        args.report.write_text(json.dumps(evidence, ensure_ascii=False, indent=2) + '\n')
    print(json.dumps(evidence))


if __name__ == '__main__':
    main()
