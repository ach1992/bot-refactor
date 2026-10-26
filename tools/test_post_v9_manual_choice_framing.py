#!/usr/bin/env python3
"""Source-real six-route manual payment-choice callback framing regression.

Disposable socket-only MariaDB and PHP; bank/Telegram/provider operations are
synthetic spies, never installed payments or real customer accounts.
"""
from __future__ import annotations

import argparse
import json
from pathlib import Path

import test_post_v9_manual_payments as manual
import test_post_v9_finance_routes as finance
import test_post_v9_purchases as stages

ROOT = Path(__file__).resolve().parents[1]
ROUTES = (
    ('buy', 'checkout', 'payBuyWith'),
    ('custom', 'checkout', 'payCustomWith'),
    ('renew', 'renewal', 'payRenewWith'),
    ('day', 'extension', 'payIncreaseDayWith'),
    ('volume', 'extension', 'payIncreaseWith'),
    ('topup', 'wallet', 'payIncreaseWalletWith'),
)
METHODS = (('CartToCart', 'bank_transfer'), ('GiftCard', 'gift_card'))


def check():
    cases = []
    with manual.owned.owned_database() as (work, env):
        manual.purchase.init(work, env, 'http://127.0.0.1:9')
        assert len(manual.query(work, env, 'SHOW TABLES').splitlines()) == 23
        source_pays_ddl=manual.query(work, env, 'SHOW CREATE TABLE pays')
        source_users_ddl=manual.query(work, env, 'SHOW CREATE TABLE users')
        for migration in ('003_payment_attempts.sql','007_service_effects.sql',
                          '008_referral_awards.sql','009_bank_buy_invoice_claims.sql'):
            manual.query(work, env,(ROOT/'migrations/post-v9'/migration).read_text())
        assert manual.query(work, env, 'SHOW CREATE TABLE pays')==source_pays_ddl
        assert manual.query(work, env, 'SHOW CREATE TABLE users')==source_users_ddl
        states = {'walletState': 'on', 'cartToCartState': 'on',
                  'giftCardState': 'on', 'manualUsdtState': 'on',
                  'gatewayAudience': {'bank_transfer': 'all', 'gift_card': 'all'}}
        policy = json.dumps(states, separators=(',', ':'))
        manual.query(work, env,
            f"INSERT INTO setting(type,value) VALUES ('BOT_STATES','{policy}')")
        driver = work/'manual-choice-route.php'
        driver.write_text(manual.PHP_ROUTE)

        def sql(command):
            return manual.query(work, env, command)

        seq = 0
        def seed(subject, suffix):
            nonlocal seq
            seq += 1
            user = 13000 + seq
            name = suffix
            assert name.isalnum() and 1 <= len(name) <= 50
            sql('INSERT INTO users(userid,name,username,refcode,wallet,date,isAdmin,first_start) '
                f"VALUES({user},'Fixture','fixture','0',50000,'1700000000',0,'sent')")
            oid=0
            if subject in ('renew', 'day', 'volume'):
                sql('INSERT INTO orders_list '
                    '(userid,token,transid,fileid,server_id,inbound_id,remark,uuid,'
                    'protocol,expire_date,link,amount,status,date) VALUES '
                    f"({user},'fixture','',7,2,0,'manual-frame-{seq}',"
                    f"'uuid-{seq}','vless',2000000000,'[]',100,1,'synthetic')")
                oid=int(sql(f"SELECT id FROM orders_list WHERE remark='manual-frame-{seq}'"))
            pay_type = {
                'buy':'BUY_SUB', 'custom':'BUY_SUB', 'renew':'RENEW_ACCOUNT',
                'day':f'INCREASE_DAY_{oid}_901',
                'volume':f'INCREASE_VOLUME_{oid}_902',
                'topup':'INCREASE_WALLET',
            }[subject]
            dim_day,dim_volume = (30,2) if subject=='custom' else (0,0)
            sql('INSERT INTO pays '
                '(hash_id,user_id,type,plan_id,server_id,order_id,agent_count,'
                'day,volume,price,description,request_date,state) VALUES '
                f"('{name}',{user},'{pay_type}',7,2,{oid},1,"
                f"{dim_day},{dim_volume},100,'manual-frame',UNIX_TIMESTAMP(),'pending')")
            return user, name

        def snapshot(user, name):
            pay_id=int(sql(f"SELECT id FROM pays WHERE hash_id='{name}'"))
            return (
                sql(f'SELECT wallet,step FROM users WHERE userid={user}'),
                sql(f"SELECT state,IFNULL(payid,'') FROM pays WHERE hash_id='{name}'"),
                sql(f'SELECT provider,stage FROM payment_attempts WHERE pay_row_id={pay_id}'),
                sql(f'SELECT COUNT(*) FROM payment_transactions'),
                sql(f'SELECT COUNT(*) FROM service_effects WHERE pay_row_id={pay_id}'),
                sql(f'SELECT COUNT(*) FROM increase_order WHERE userid={user}'),
            )

        for subject, route, family in ROUTES:
            for method, expected_provider in METHODS:
                actor,name=seed(subject,f'ManualFr{seq+1:03}RefABC')
                callback=family+method+name
                assert len(callback.encode())<=64
                original=snapshot(actor,name)
                for label, bad in (
                    ('prefixed','X'+callback),
                    ('trailing','%s/extra' % callback),
                    ('unicode','%s\N{LATIN SMALL LETTER E WITH ACUTE}' % callback),
                ):
                    assert len(bad.encode('utf-8'))<=64
                    events=manual.run_route(work,env,driver,data=bad,
                                            actor=actor,route=route)
                    assert events==[],(subject,method,label,events)
                    assert snapshot(actor,name)==original,(subject,method,label)
                    cases.append(f'{subject}_{method}_{label}_denied')
                events=manual.run_route(work,env,driver,data=callback,
                                        actor=actor,route=route)
                assert any(e['name']=='sendMessage' for e in events),(subject,method,events)
                state=snapshot(actor,name)
                assert state[2]==expected_provider+'\tpending',(subject,method,state)
                assert state[0].split('\t')[0]=='50000',(subject,method,state)
                assert state[1].startswith('pending'),(subject,method,state)
                cases.append(f'{subject}_{method}_valid_choice_pins_correct_provider')
                manual.run_route(work,env,driver,data=callback,
                                 actor=actor,route=route)
                assert snapshot(actor,name)==state,(subject,method,'replay_mutated')
                cases.append(f'{subject}_{method}_reclick_keeps_one_claim')

            # Method identity MUST come from the prefix, not from arbitrary
            # substrings of the payment reference following that prefix.
            for method,unexpected,expected_provider in (
                ('CartToCart','WithGiftCard','bank_transfer'),
                ('GiftCard','WithManualUsdt','gift_card'),
            ):
                actor,name=seed(subject,unexpected+'Hash'+str(seq+1))
                callback=family+method+name
                assert len(callback.encode())<=64
                events=manual.run_route(work,env,driver,data=callback,
                                        actor=actor,route=route)
                assert any(e['name']=='sendMessage' for e in events),(subject,method,events)
                after=snapshot(actor,name)
                assert after[2]==expected_provider+'\tpending',(subject,method,after)
                assert after[0].split('\t')[0]=='50000'
                cases.append(f'{subject}_{method}_hash_text_cannot_change_method')

        # Direct ManualUsdt buttons from old messages require an explicit
        # crypto network choice; downstream selected methods must not be
        # activated from malformed fragments or guessed provider data.
        for subject,route,family in ROUTES:
            actor,name=seed(subject,f'CryptoFrame{seq+1}Ref')
            before=snapshot(actor,name)
            for bad in ('X'+family+'ManualUsdt'+name,
                        family+'ManualUsdt'+name+'!'):
                assert len(bad.encode())<=64
                events=manual.run_route(work,env,driver,data=bad,actor=actor,route=route)
                assert events==[],(subject,bad,events)
                assert snapshot(actor,name)==before,(subject,'crypto_fragment')
            cases.append(f'{subject}_crypto_malformed_no_intent')

        # ManualCrypto.php constructs an INTERNAL action after an authenticated
        # mc_[bcet]_<hash> Telegram callback. A legal 50-char hash makes the
        # internal action 70-81 bytes; only this verified internal dispatch
        # may exceed the Telegram transport's 64-byte callback limit.
        crypto_keys={
            'manualUsdtWalletBEP20':'0x'+'1'*40,
            'manualUsdtWalletTRC20':'TNPeeaaFB7K9cmo4uQpcU32zGK8G1NYqeL',
            'manualUsdtWalletERC20':'0x'+'2'*40,
            'manualUsdtRateToman':'100000',
            'manualTrxWallet':'TJRabPrwbZy45sbavfcjinPJC18kjpRTv8',
            'manualTrxRateToman':'15000',
        }
        for subject,route,family in ROUTES:
            for tag,provider,settlement in (
                ('c','manual_usdt','TRC20'),
                ('t','manual_trx','TRON'),
            ):
                label=f'{subject}{tag}{seq+1:04}'
                actor,name=seed(subject,label.ljust(50,'K'))
                internal=family+'ManualUsdt'+name
                assert len(name)==50 and len(internal)>64
                telegram='mc_'+tag+'_'+name
                assert len(telegram)==55
                before=snapshot(actor,name)
                external=manual.run_route(work,env,driver,
                     data=internal,actor=actor,route=route)
                assert external==[] and snapshot(actor,name)==before,(subject,tag,'external')
                cases.append(f'{subject}_{tag}_long_hash_untrusted_external_denied')
                trusted=manual.run_route(work,env,driver,
                     data=telegram,actor=actor,route='manual_crypto',keys=crypto_keys)
                assert any(e['name']=='sendMessage' for e in trusted),(subject,tag,trusted)
                assert snapshot(actor,name)[2]==provider+'\tpending',(subject,tag,snapshot(actor,name))
                assert snapshot(actor,name)[0].split('\t')[0]=='50000'
                assert sql(f"SELECT settlement_currency FROM payment_attempts WHERE order_hash='{name}'")==settlement
                cases.append(f'{subject}_{tag}_long_hash_verified_network_choice_pinned')

        # 64 is the Telegram callback limit. Grammar-valid exact 64-byte
        # manual choice works; the same selected family at 65 is inert.
        family = 'payIncreaseWalletWithGiftCard'
        for size in (64, 65):
            name='B'*(size-len(family))
            actor,name=seed('topup',name)
            before=snapshot(actor,name)
            reply=manual.run_route(work,env,driver,data=family+name,
                                   actor=actor,route='wallet')
            if size==64:
                assert any(e['name']=='sendMessage' for e in reply),reply
                assert snapshot(actor,name)[2]=='gift_card\tpending'
                cases.append('topup_manual_choice_exact_64_bytes_valid')
            else:
                assert reply==[] and snapshot(actor,name)==before,reply
                cases.append('topup_manual_choice_65_bytes_denied')

        # Actual bot.php dispatch is mandatory: a malformed manual-choice
        # namespace must be CONSUMED before another unanchored route can
        # interpret an embedded substring. The PrePayment sentinel is used
        # for only the valid-pass-through probe, not as a payment oracle.
        staged = work/'manual-choice-full-bot'
        stages.stage_source(staged, True)
        config=staged/'config.php'
        original_config=config.read_bytes()
        fixture=finance.PHP_DRIVER
        marker="    } elseif ($spec['route'] === 'local_purchase_write') {"
        replacement=(
            "    } elseif ($spec['route'] === 'bot_callback') {\n"
            "        $data=(string)($spec['callback_data']??'');\n"
            "        $callbackId='owned-manual-frame';\n"
            "        $message_id=702;\n"
            "        $from_id=(int)($spec['actor']??1001);\n"
            "        $userInfo=['step'=>'none','isAdmin'=>false];\n"
            "        $joniedState='member';\n"
            "        $text='';\n"
            "        require $root . '/bot.php';\n"
            "        $GLOBALS['events'][]=['name'=>'bot_dispatch_returned'];\n"
            "    } elseif ($spec['route'] === 'local_purchase_write') {")
        assert fixture.count(marker)==1
        (work/'owned-finance-guard.php').write_text(fixture.replace(marker,replacement,1))
        config.write_text('<?php function check() {} '
                          'function handleToggleCallbacks(...$args) { return false; } '
            'function achbotHandleChannelExemption(...$args) { return false; } '
                          'function checkSpam() { return false; }\n')
        try:
            actor,name=seed('buy','ValidM42')
            before=snapshot(actor,name)
            for subject,_,family in ROUTES:
                for method,_ in (*METHODS,('ManualUsdt','manual_usdt')):
                    action=family+method
                    invalid=action+'!payBuyWithGiftCard'+name
                    assert len(invalid.encode('utf-8'))<=64,(subject,method,len(invalid))
                    result=finance.run_php(work,env,staged,{
                        'route':'bot_callback','callback_data':invalid,
                        'actor':actor,'get':{}})
                    assert result['network_calls']==[],(subject,method,result)
                    assert [e['name'] for e in result['events']]==[
                        'callback','bot_dispatch_returned'],(subject,method,result)
                    assert 'معتبر نیست' in str(result['events'][0]['args']),(subject,method,result)
                    assert snapshot(actor,name)==before
                    cases.append(f'{subject}_{method}_full_bot_invalid_consumed')

            prepayment=staged/'src/Finance/Routes/PrePayment.php'
            old_prepayment=prepayment.read_bytes()
            prepayment.write_text("<?php $GLOBALS['events'][]="
                                  "['name'=>'framing_passed']; return null;\n")
            try:
                for subject,_,family in ROUTES:
                    for method,_ in (*METHODS,('ManualUsdt','manual_usdt')):
                        valid=family+method+name
                        assert len(valid.encode('utf-8'))<=64
                        result=finance.run_php(work,env,staged,{
                            'route':'bot_callback','callback_data':valid,
                            'actor':actor,'get':{}})
                        assert [e['name'] for e in result['events']]==[
                            'framing_passed','bot_dispatch_returned'],(subject,method,result)
                        assert not result['network_calls']
                        assert snapshot(actor,name)==before
                        cases.append(f'{subject}_{method}_full_bot_valid_continues')
                unrelated=finance.run_php(work,env,staged,{
                    'route':'bot_callback','callback_data':'unrelatedCallback',
                    'actor':actor,'get':{}})
                assert [e['name'] for e in unrelated['events']]==[
                    'framing_passed','bot_dispatch_returned']
                assert snapshot(actor,name)==before
                cases.append('manual_choice_guard_unrelated_namespace_continues')
            finally:
                prepayment.write_bytes(old_prepayment)
        finally:
            config.write_bytes(original_config)

    return {'complete':True,'cases':len(cases),'scenarios':cases,
            'real_php_mariadb':True,'socket_only':True,'provider_calls':0,
            'live_financial_mutations':0,'source_payment_tables_unchanged':True}


def main():
    p=argparse.ArgumentParser()
    p.add_argument('--run-disposable',action='store_true',required=True)
    p.add_argument('--report',type=Path)
    opts=p.parse_args()
    report=check()
    if opts.report:opts.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps(report))


if __name__=='__main__':main()
