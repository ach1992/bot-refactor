#!/usr/bin/env python3
"""Small current-Stable NOWPayments finality/replay test on owned PHP/MariaDB.

Exercises the real committed checkout/IPN/Cron reconciliation/return PHP with
synthetic authenticated notifications and in-process provider spies. No live
merchant, Native, Telegram, wallet, or deployed database is touched.
"""
from __future__ import annotations

import argparse
import hashlib
import hmac
import json
from pathlib import Path

import test_access_state as owned
import test_post_v9_finance_routes as finance
import test_post_v9_purchases as staging
import test_v8_native as purchase

ROOT = Path(__file__).resolve().parents[1]


def check() -> dict:
    cases = []

    def assert_case(condition: bool, name: str) -> None:
        if not condition:
            raise AssertionError(name)
        cases.append(name)

    def sign_ipn(payload: dict, *, valid: bool = True) -> dict:
        raw = json.dumps(payload, sort_keys=True, ensure_ascii=False, separators=(',', ':'))
        signature = hmac.new(b'LOCAL-IPN-SECRET', raw.encode(), hashlib.sha512).hexdigest()
        return {'route': 'ipn', 'get': {}, 'ipn_raw': raw,
                'ipn_wrong_signature': signature if valid else 'a' * 128}

    with owned.owned_database() as (work, env):
        app = work / 'current-stable-finance'
        staging.stage_source(app, True)
        purchase.init(work, env, 'http://127.0.0.1:9')
        query = lambda sql: finance.sql(work, env, sql)
        call = lambda **spec: finance.run_php(work, env, app, spec)
        assert len(query('SHOW TABLES').splitlines()) == 23
        for migration in (
            '001_marzban_panel_group_id.sql',
            '003_payment_attempts.sql',
            '007_service_effects.sql',
            '022_gateway_review_archive.sql',
        ):
            query((ROOT / 'migrations/post-v9' / migration).read_text())
        query("INSERT INTO setting(type,value) VALUES "
              "('BOT_STATES','{\"walletState\":\"on\",\"nowPaymentWallet\":\"on\",\"nowPaymentOther\":\"on\"}')")
        payment_keys = json.dumps({
            'nowpayment': 'SYNTHETIC-LOCAL-ONLY',
            'nowpayment_ipn_secret': 'LOCAL-IPN-SECRET',
            'manualUsdtRateToman': '100000',
        }, separators=(',', ':'))
        query("INSERT INTO setting(type,value) VALUES ('PAYMENT_KEYS','" + payment_keys + "')")

        name = 'stable-nowpay-current'
        finance.seed_payment(work, env, name, 'INCREASE_WALLET', 0, 0, '')
        query(f"UPDATE pays SET price=100000 WHERE hash_id='{name}'")
        first = call(route='initiate', get={'nowpayment': '', 'hash_id': name})
        invoice = query(f"SELECT payid FROM pays WHERE hash_id='{name}'").strip()
        assert_case(invoice == 'owned-new-invoice' and len(first['network_calls']) == 1
                    and '/v1/invoice' in first['network_calls'][0],
                    'current_checkout_creates_exact_original_invoice_once')
        assert_case(query(
            f"SELECT quote_amount,quote_currency,local_amount_toman FROM payment_attempts WHERE order_hash='{name}'"
        ).strip() == '1.00000000\tusd\t100000',
                    'current_checkout_pins_usd_and_local_amount')

        ipn = dict(
            payment_id='stable-nowpay-payment', invoice_id=invoice,
            order_id=name, payment_status='confirmed', price_amount=1,
            price_currency='usd', pay_amount=1, actually_paid=1,
            pay_currency='usdttrc20',
            fee={'withdrawalFee': '0.010', 'depositFee': '0.001'},
        )
        baseline = finance.snapshot(work, env)
        invalid = call(**sign_ipn(ipn, valid=False))
        finance.verify_unchanged(work, env, baseline, 'stable-invalid-signature')
        assert_case(invalid['events'][-1].get('code') == 401 and not invalid['network_calls'],
                    'unauthenticated_ipn_rejected_without_money_effect')

        wrong_order = call(**sign_ipn(dict(ipn, order_id='another-customer-order')))
        finance.verify_unchanged(work, env, baseline, 'stable-other-order')
        assert_case(wrong_order['events'][-1].get('code') == 409
                    and not wrong_order['network_calls'],
                    'signed_ipn_cannot_bind_other_original_order')

        wrong_quote = call(**sign_ipn(dict(ipn, price_amount=2)))
        finance.verify_unchanged(work, env, baseline, 'stable-quote-mismatch')
        assert_case(wrong_quote['events'][-1].get('code') != 200
                    and not wrong_quote['network_calls'],
                    'signed_ipn_does_not_reprice_original_quote')

        start_wallet = int(query('SELECT wallet FROM users WHERE userid=1001'))
        accepted = call(**sign_ipn(ipn))
        assert_case(accepted['events'][-1].get('code') == 200
                    and not accepted['network_calls']
                    and int(query('SELECT wallet FROM users WHERE userid=1001')) == start_wallet,
                    'authenticated_ipn_durable_but_not_credited_early')

        intermediate = call(
            route='reconcile', get={}, mock_invoice=invoice,
            nowpayment_payment_id=ipn['payment_id'],
            nowpayment_order_id=name, nowpayment_status='confirmed',
            nowpayment_price=1, nowpayment_pay_amount=1, nowpayment_actual=1,
        )
        assert_case(intermediate['events'][-1]['result']['pending'] == 1
                    and int(query('SELECT wallet FROM users WHERE userid=1001')) == start_wallet,
                    'merchant_nonfinal_confirmation_does_not_settle')

        query(f"UPDATE payment_attempts SET last_checked_at=NOW()-INTERVAL 120 SECOND WHERE order_hash='{name}'")
        final = call(
            route='reconcile', get={}, mock_invoice=invoice,
            nowpayment_payment_id=ipn['payment_id'],
            nowpayment_order_id=name, nowpayment_status='finished',
            nowpayment_price=1, nowpayment_pay_amount=1, nowpayment_actual=1,
            nowpayment_outcome=0.98,
        )
        assert_case(int(query('SELECT wallet FROM users WHERE userid=1001')) == start_wallet + 100000
                    and query(f"SELECT state FROM pays WHERE hash_id='{name}'").strip() == 'paid'
                    and query(f"SELECT stage FROM payment_attempts WHERE order_hash='{name}'").strip() == 'done',
                    'merchant_finished_settles_original_once_and_records_done')
        assert_case(any(e.get('name') == 'sendMessage' and
                        len(e.get('args', [])) > 3 and e['args'][3] == 1001
                        for e in final['events']),
                    'finished_local_fulfillment_notifies_original_customer')

        before_replay = finance.snapshot(work, env)
        txs = query("SELECT COUNT(*) FROM payment_transactions").strip()
        effects = query("SELECT COUNT(*) FROM service_effects").strip()
        orders = query("SELECT COUNT(*) FROM orders_list").strip()
        replay_ipn = call(**sign_ipn(dict(ipn, payment_status='finished')))
        replay_reconcile = call(
            route='reconcile', get={}, mock_invoice=invoice,
            nowpayment_payment_id=ipn['payment_id'],
            nowpayment_order_id=name, nowpayment_status='finished')
        assert_case(replay_ipn['events'][-1].get('code') == 200
                    and not replay_reconcile['network_calls']
                    and finance.snapshot(work, env) == before_replay
                    and query("SELECT COUNT(*) FROM payment_transactions").strip() == txs
                    and query("SELECT COUNT(*) FROM service_effects").strip() == effects
                    and query("SELECT COUNT(*) FROM orders_list").strip() == orders,
                    'replayed_ipn_and_cron_no_duplicate_wallet_or_native_effect')

    return {
        'complete': True,
        'cases': len(cases),
        'scenarios': cases,
        'source_real_php': True,
        'socket_only_mariadb': True,
        'real_merchant_telegram_native_calls': 0,
        'pre_stable_execution': False,
    }


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument('--run-disposable', action='store_true', required=True)
    parser.add_argument('--report', type=Path)
    args = parser.parse_args()
    result = check()
    text = json.dumps(result, ensure_ascii=False)
    if args.report:
        args.report.write_text(text + '\n', encoding='utf-8')
    print(text)


if __name__ == '__main__':
    main()
