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
import test_post_v9_finance_routes as finance
import test_post_v9_purchases as stages

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

        # Run actual bot.php dispatch. A malformed wallet-prefixed callback
        # must not fall through to a different recognized financial handler.
        bot_stage = work / 'wallet-framing-full-bot'
        stages.stage_source(bot_stage, True)
        config_path = bot_stage / 'config.php'
        original_config = config_path.read_bytes()
        bot_driver = finance.PHP_DRIVER
        if "$spec['route'] === 'bot_callback'" not in bot_driver:
            marker = "    } elseif ($spec['route'] === 'local_purchase_write') {"
            replacement = (
                "    } elseif ($spec['route'] === 'bot_callback') {\n"
                "        $data=(string)($spec['callback_data']??'');\n"
                "        $callbackId='owned-wallet-frame';\n"
                "        $message_id=702;\n"
                "        $from_id=(int)($spec['actor']??1001);\n"
                "        $userInfo=['step'=>'none','isAdmin'=>false];\n"
                "        $joniedState='member';\n"
                "        $text='';\n"
                "        require $root . '/bot.php';\n"
                "        $GLOBALS['events'][]=['name'=>'bot_dispatch_returned'];\n"
                "    } elseif ($spec['route'] === 'local_purchase_write') {")
            assert bot_driver.count(marker) == 1
            bot_driver = bot_driver.replace(marker, replacement, 1)
        (work / 'owned-finance-guard.php').write_text(bot_driver)
        config_path.write_text('<?php function check() {} '
                               'function handleToggleCallbacks(...$args) { return false; } '
            'function achbotHandleChannelExemption(...$args) { return false; } '
                          'function checkSpam() { return false; }\n')
        try:
            actor = 12101
            name = 'FrameBot12345'
            sql('INSERT INTO users '
                '(userid,name,username,refcode,wallet,date,isAdmin,first_start) '
                f"VALUES ({actor},'Fixture','fixture','0',50000,'1700000000',0,'sent')")
            sql('INSERT INTO pays '
                '(hash_id,user_id,type,plan_id,server_id,agent_count,day,volume,'
                'price,description,request_date,state) VALUES '
                f"('{name}',{actor},'BUY_SUB',7,2,1,0,0,100,"
                "'callback-framing',UNIX_TIMESTAMP(),'pending')")
            for subject, _, prefix in ROUTES:
                invalid = prefix + '!payBuyWithCartToCart' + name
                assert len(invalid.encode('utf-8')) <= 64
                before = observe(actor, name)
                result = finance.run_php(work, env, bot_stage, {
                    'route': 'bot_callback', 'get': {},
                    'callback_data': invalid, 'actor': actor})
                assert result['network_calls'] == [], (subject, result)
                assert result['events'][-1] == {'name': 'bot_dispatch_returned'}
                assert not any(e['name'] not in ('callback', 'bot_dispatch_returned')
                               for e in result['events']), (subject, result)
                assert any(e['name'] == 'callback' and 'معتبر نیست' in str(e['args'])
                           for e in result['events']), (subject, result)
                assert observe(actor, name) == before, (subject, 'bot dispatch SQL drift')
                scenarios.append(f'{subject}_full_bot_invalid_wallet_namespace_consumed')

            # For valid callbacks, the early bot guard must NOT consume the
            # action. Replace only the staged PrePayment module with a
            # sentinel that terminates before other payment routes; direct
            # actual wallet handler tests above prove financial validity.
            prepayment = bot_stage / 'src/Finance/Routes/PrePayment.php'
            saved_prepayment = prepayment.read_bytes()
            prepayment.write_text("<?php $GLOBALS['events'][]="
                                  "['name'=>'wallet_frame_passed']; return null;\n")
            try:
                for subject, _, prefix in ROUTES:
                    before = observe(actor, name)
                    result = finance.run_php(work, env, bot_stage, {
                        'route': 'bot_callback', 'get': {},
                        'callback_data': prefix + name, 'actor': actor})
                    assert result['events'] == [
                        {'name': 'wallet_frame_passed'},
                        {'name': 'bot_dispatch_returned'}], (subject, result)
                    assert not result['network_calls']
                    assert observe(actor, name) == before
                    scenarios.append(f'{subject}_valid_frame_reaches_dispatch')
                before = observe(actor, name)
                unrelated = finance.run_php(work, env, bot_stage, {
                    'route': 'bot_callback', 'get': {},
                    'callback_data': 'unrelatedCallback', 'actor': actor})
                assert unrelated['events'] == [
                    {'name': 'wallet_frame_passed'},
                    {'name': 'bot_dispatch_returned'}], unrelated
                assert observe(actor, name) == before
                scenarios.append('unrelated_callback_not_blocked_by_wallet_guard')
            finally:
                prepayment.write_bytes(saved_prepayment)
        finally:
            config_path.write_bytes(original_config)

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
