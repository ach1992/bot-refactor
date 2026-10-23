#!/usr/bin/env python3
"""Native PHP/MariaDB payment-wallet callback framing across five routes.

All customer payments, wallet balances, and remote operations are disposable;
no production, real provider, Telegram, or Marzban access is performed.
"""
from __future__ import annotations

import argparse
import json
from pathlib import Path

import test_post_v9_wallet_checkout as checkout
import test_post_v9_manual_payments as manual

ROOT = Path(__file__).resolve().parents[1]
ROUTES = (
    ('buy', 'checkout', 'payBuyWithWallet'),
    ('custom', 'checkout', 'payCustomWithWallet'),
    ('renew', 'renewal', 'payRenewWithWallet'),
    ('day', 'extension', 'payIncreaseDayWithWallet'),
    ('volume', 'extension', 'payIncreaseWithWallet'),
)


def check() -> dict:
    scenarios = []
    with checkout.owned.owned_database() as (work, env):
        checkout.purchase.init(work, env, 'http://127.0.0.1:9')
        assert len(checkout.sql(work, env, 'SHOW TABLES').splitlines()) == 23
        for sidecar in (
            '001_marzban_panel_group_id.sql', '003_payment_attempts.sql',
            '007_service_effects.sql', '008_referral_awards.sql',
            '009_bank_buy_invoice_claims.sql',
        ):
            checkout.sql(work, env, (ROOT / 'migrations/post-v9' / sidecar).read_text())
        policy = json.dumps({'walletState': 'on', 'gatewayAudience': {'wallet': 'all'}})
        checkout.sql(work, env, "INSERT INTO setting(type,value) VALUES "
                     f"('BOT_STATES','{policy}')")
        checkout.sql(work, env, 'UPDATE server_info SET ucount=100 WHERE id=2')
        checkout.sql(work, env, "INSERT INTO increase_day (id,volume,price) VALUES (901,7,100)")
        checkout.sql(work, env, "INSERT INTO increase_plan (id,volume,price) VALUES (902,8,100)")
        buy_driver = work / 'wallet-callback-checkout.php'
        buy_driver.write_text(checkout.PHP)
        service_driver = work / 'wallet-callback-services.php'
        service_driver.write_text(manual.PHP_ROUTE)

        def sql(query: str) -> str:
            return checkout.sql(work, env, query)

        def observe(actor: int, name: str) -> tuple[str, ...]:
            pay_id = sql(f"SELECT id FROM pays WHERE hash_id='{name}'")
            return (
                sql(f'SELECT wallet FROM users WHERE userid={actor}'),
                sql(f"SELECT state,IFNULL(payid,'') FROM pays WHERE hash_id='{name}'"),
                sql(f'SELECT COUNT(*) FROM service_effects WHERE pay_row_id={pay_id}'),
                sql(f'SELECT COUNT(*) FROM increase_order WHERE userid={actor}'),
                sql(f'SELECT COUNT(*) FROM orders_list WHERE userid={actor}'),
                sql('SELECT ucount FROM server_info WHERE id=2'),
                sql(f'SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id={pay_id}'),
            )

        for i, (subject, route, prefix) in enumerate(ROUTES, 1):
            actor = 12000 + i
            name = f'WalletFrame{i:02}Order12345'
            assert name.isalnum() and len(name) == 23
            sql("INSERT INTO users (userid,name,username,refcode,wallet,date,isAdmin,first_start) "
                f"VALUES ({actor},'Synthetic buyer','synthetic','0',50000,'1700000000',0,'sent')")
            order_id = 0
            if route != 'checkout':
                sql('INSERT INTO orders_list '
                    '(userid,token,transid,fileid,server_id,inbound_id,remark,uuid,'
                    'protocol,expire_date,link,amount,status,date) VALUES '
                    f"({actor},'owned-token','',7,2,0,'frame-{subject}',"
                    f"'uuid-{subject}','vless',2000000000,'[]',100,1,'synthetic')")
                order_id = int(sql(f"SELECT id FROM orders_list WHERE remark='frame-{subject}'"))
            payment_type = {
                'buy': 'BUY_SUB', 'custom': 'BUY_SUB', 'renew': 'RENEW_ACCOUNT',
                'day': f'INCREASE_DAY_{order_id}_901',
                'volume': f'INCREASE_VOLUME_{order_id}_902',
            }[subject]
            day, volume = (30, 2) if subject == 'custom' else (0, 0)
            sql('INSERT INTO pays '
                '(hash_id,user_id,type,plan_id,server_id,order_id,agent_count,'
                'day,volume,price,description,request_date,state) VALUES '
                f"('{name}',{actor},'{payment_type}',7,2,{order_id},1,"
                f"{day},{volume},100,'frameuser{i}',UNIX_TIMESTAMP(),'pending')")

            def send(callback: str):
                if route == 'checkout':
                    return checkout.route(work, env, buy_driver, hash=name,
                                          custom=(subject == 'custom'), user=actor,
                                          callback_data=callback)
                return manual.run_route(work, env, service_driver, data=callback,
                                        actor=actor, route=route, remote_mode='success',
                                        service_applied_expire=2000000010,
                                        edit_order_id=order_id)

            before = observe(actor, name)
            for label, invalid in (
                ('embedded_action', 'invalid_' + prefix + name),
                ('trailing_separator', prefix + name + '/extra'),
                ('multibyte_suffix', prefix + name + '\N{LATIN SMALL LETTER E WITH ACUTE}'),
            ):
                assert len(invalid.encode('utf-8')) <= 64
                result = send(invalid)
                assert not result, (subject, label, 'invalid dispatched', result)
                assert observe(actor, name) == before, (subject, label, 'SQL mutated')
                scenarios.append(f'{subject}_{label}_no_wallet_or_service_effect')

            assert len((prefix + name).encode('utf-8')) <= 64
            events = send(prefix + name)
            types = [evt.get('event', evt.get('name')) for evt in events]
            expected = 'POST' if route == 'checkout' else 'remote_edit'
            assert expected in types, (subject, 'valid action not processed', events)
            assert sql(f'SELECT wallet FROM users WHERE userid={actor}') == '49900', subject
            assert sql(f"SELECT state FROM pays WHERE hash_id='{name}'") == 'paid_with_wallet', subject
            assert len([e for e in types if e == expected]) == 1, (subject, types)
            scenarios.append(f'{subject}_valid_owned_callback_still_pays_once')

            repeated = send(prefix + name)
            repeated_types = [evt.get('event', evt.get('name')) for evt in repeated]
            assert expected not in repeated_types, (subject, 'valid replay triggered remote')
            assert sql(f'SELECT wallet FROM users WHERE userid={actor}') == '49900', subject
            scenarios.append(f'{subject}_paid_callback_no_replay')

        # 64 is accepted only with the exact grammar and owned payment; 65
        # must not be enough to reach a real wallet debit even though the
        # hash is individually within the 50-character component limit.
        prefix = 'payBuyWithWallet'
        for size in (64, 65):
            actor = 12010 + size
            name = 'A' * (size - len(prefix))
            assert len(name) <= 50 and len((prefix + name).encode()) == size
            sql('INSERT INTO users '
                '(userid,name,username,refcode,wallet,date,isAdmin,first_start) '
                f"VALUES ({actor},'Buyer','buyer','0',50000,'1700000000',0,'sent')")
            sql('INSERT INTO pays '
                '(hash_id,user_id,type,plan_id,server_id,order_id,agent_count,'
                'day,volume,price,description,request_date,state) VALUES '
                f"('{name}',{actor},'BUY_SUB',7,2,0,1,0,0,100,"
                f"'boundary{size}',UNIX_TIMESTAMP(),'pending')")
            before = observe(actor, name)
            events = checkout.route(work, env, buy_driver, hash=name,
                                    custom=False, user=actor,
                                    callback_data=prefix + name)
            if size == 64:
                assert sum(e['event'] == 'POST' for e in events) == 1, events
                assert sql(f'SELECT wallet FROM users WHERE userid={actor}') == '49900'
                assert sql(f"SELECT state FROM pays WHERE hash_id='{name}'") == 'paid_with_wallet'
                scenarios.append('wallet_exact_64_byte_callback_accepted')
            else:
                assert events == [] and observe(actor, name) == before, events
                scenarios.append('wallet_65_byte_callback_without_side_effects')

    return {'complete': True, 'cases': len(scenarios), 'scenarios': scenarios,
            'real_php_mariadb': True, 'original_payment_schema_preserved': True,
            'provider_requests': 0, 'live_wallet_mutations': 0}


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--run-disposable', action='store_true', required=True)
    parser.add_argument('--report', type=Path)
    args = parser.parse_args()
    result = check()
    if args.report:
        args.report.write_text(json.dumps(result, indent=2) + '\n')
    print(json.dumps(result))


if __name__ == '__main__':
    main()
