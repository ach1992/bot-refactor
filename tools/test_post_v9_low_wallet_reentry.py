#!/usr/bin/env python3
"""Exact native PHP 8.5/MariaDB historical low-wallet choice bridge.

Real PrePayment.php and pay/checkout.php execute against a disposable,
socket-only original+003/007/017 schema. No provider, live Telegram, wallet,
Native, deployed service or production database is contacted.
"""
from __future__ import annotations

import argparse
import json
from pathlib import Path

import test_access_state as owned
import test_post_v9_finance_routes as finance
import test_post_v9_wallet_checkout as checkout
import test_post_v9_purchases as stages

ROOT = Path(__file__).resolve().parents[1]
SUBJECTS = (
    ('buy', 'BUY_SUB', 'payBuyWithWallet', 0, 0),
    ('custom', 'BUY_SUB', 'payCustomWithWallet', 30, 2),
    ('renew', 'RENEW_ACCOUNT', 'prePayCheck_Renew_nextpay_', 0, 0),
    ('day', 'INCREASE_DAY', 'payIncreaseDayWithWallet', 7, 0),
    ('volume', 'INCREASE_VOLUME', 'payIncreaseWithWallet', 0, 8),
)
# Synthetic browser initiation is intentionally separate from Telegram
# authentication. It must never rearm low_wallet by GET alone.
STATES = {'walletState':'on','nextpay':'on','nowPaymentOther':'on',
          'manualUsdtState':'on',
          'gatewayAudience':{'wallet':'all','nextpay':'all',
                             'manual_usdt':'all','manual_trx':'all'}}

def check() -> dict:
    cases=[]
    with owned.owned_database() as (work, env):
        stages.purchase.init(work,env,'http://127.0.0.1:9')
        assert len(checkout.sql(work,env,'SHOW TABLES').splitlines())==23
        for migration in (
            '001_marzban_panel_group_id.sql',
            '003_payment_attempts.sql','007_service_effects.sql',
            '009_bank_buy_invoice_claims.sql','017_shared_plan_changes.sql'
        ):
            checkout.sql(work,env,(ROOT/'migrations/post-v9'/migration).read_text())
        checkout.sql(work,env,'UPDATE server_info SET ucount=100 WHERE id=2')
        # The unchanged hosted add-on eligibility path also checks the
        # pinned original SKU; a missing catalogue offer is a real denial.
        checkout.sql(work,env,
            "INSERT INTO increase_day (id,volume,price) VALUES (901,7,100)")
        checkout.sql(work,env,
            "INSERT INTO increase_plan (id,volume,price) VALUES (902,8,100)")
        # Provider issuance does a CURRENT primary-key read of persisted
        # BOT_STATES, not the Telegram preview globals. The test must seed
        # that real source of truth for a usable nextpay alternative.
        frozen=json.dumps(STATES,separators=(',',':'))
        checkout.sql(work,env,"DELETE FROM setting WHERE type='BOT_STATES'")
        checkout.sql(work,env,"INSERT INTO setting(type,value) VALUES "
                     "('BOT_STATES','"+frozen+"')")
        # Actual pay/checkout.php also reads the tenant PAYMENT_KEYS row,
        # independently of the fixture's no-network transport API token.
        # Use synthetic credentials, never private tenant configuration.
        gateway_keys=json.dumps({'nextpay':'SYNTHETIC-LOCAL-ONLY'},
                                separators=(',',':'))
        checkout.sql(work,env,"DELETE FROM setting WHERE type='PAYMENT_KEYS'")
        checkout.sql(work,env,"INSERT INTO setting(type,value) VALUES "
                     "('PAYMENT_KEYS','"+gateway_keys+"')")
        stage=work/'low-wallet-real-routes'
        stages.stage_source(stage,True)

        # Test transport only: give the real Telegram prepayment route the
        # same callback_query/from/data object as genuine authenticated ingress.
        # Do not replace a predicate or payment handler in production source.
        driver=finance.PHP_DRIVER
        marker="""        $from_id=(int)($spec['actor']??1001);
        if ((require $root . '/src/Finance/Routes/PrePayment.php') === null) {"""
        replacement="""        $from_id=(int)($spec['actor']??1001);
        if (!empty($spec['has_telegram_callback'])) {
            $sender=(int)($spec['callback_sender']??$from_id);
            $update=(object)['callback_query'=>(object)[
                'from'=>(object)['id'=>$sender],
                'data'=>(string)($spec['callback_data']??'')]];
        }
        if ((require $root . '/src/Finance/Routes/PrePayment.php') === null) {"""
        assert driver.count(marker)==1
        driver=driver.replace(marker,replacement)
        # Synthetic-only location for actionable failure localization in CI.
        # No production exception/logging or provider path is changed.
        errorline="$GLOBALS['error'] = get_class($e) . ': ' . $e->getMessage();"
        assert driver.count(errorline)==1
        driver=driver.replace(errorline,
            "$GLOBALS['error'] = get_class($e) . ': ' . $e->getMessage() "
            ". ' @' . basename($e->getFile()) . ':' . $e->getLine();")
        (work/'owned-finance-guard.php').write_text(driver)

        def sql(query:str)->str:
            return checkout.sql(work,env,query)
        def state(pid:int)->str:
            return sql(f'SELECT state FROM pays WHERE id={pid}')
        def claims(pid:int)->tuple[str,str,str]:
            return (
                sql(f'SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id={pid}'),
                sql(f'SELECT COUNT(*) FROM service_effects WHERE pay_row_id={pid}'),
                sql(f'SELECT COUNT(*) FROM payment_transactions t JOIN payment_attempts a ON a.id=t.attempt_id WHERE a.pay_row_id={pid}')
            )
        def seed(subject:str,kind:str,day:int,volume:int,suffix:str):
            actor=15000+len(cases)*7
            name='LW'+subject.title()+str(len(cases)+1)
            assert len(name)<35 and name.isalnum()
            sql("INSERT INTO users (userid,name,username,refcode,wallet,date,isAdmin,first_start) "
                f"VALUES ({actor},'Legacy fixture','customer','0',0,'1700000000',0,'sent')")
            order_id=0
            if kind in ('RENEW_ACCOUNT','INCREASE_DAY','INCREASE_VOLUME'):
                remark='oldwallet'+name
                sql('INSERT INTO orders_list '
                    '(userid,token,transid,fileid,server_id,inbound_id,remark,uuid,'
                    'protocol,expire_date,link,amount,status,date) VALUES '
                    f"({actor},'owned-token','',7,2,0,'{remark}',"
                    f"'owned-uuid','vless',2000000000,'[]',100,1,'synthetic')")
                order_id=int(sql(f"SELECT id FROM orders_list WHERE remark='{remark}'"))
            payment_type=(f'{kind}_{order_id}_901' if kind=='INCREASE_DAY' else
                          f'{kind}_{order_id}_902' if kind=='INCREASE_VOLUME' else kind)
            sql('INSERT INTO pays '
                '(hash_id,user_id,type,plan_id,server_id,order_id,agent_count,'
                'day,volume,price,description,request_date,state) VALUES '
                f"('{name}',{actor},'{payment_type}',7,2,{order_id},1,"
                f"{day},{volume},100,'legacy-quoted',UNIX_TIMESTAMP(),'low_wallet')")
            pid=int(sql(f"SELECT id FROM pays WHERE hash_id='{name}'"))
            return actor,name,pid,order_id

        def telegram(actor:int,data:str,**options):
            spec=dict(route='prepayment',get={},actor=actor,callback_data=data,
                      has_telegram_callback=True,states=STATES)
            spec.update(options)
            return finance.run_php(work,env,stage,spec)
        def hosted(name:str,**options):
            spec=dict(route='initiate',get={'nextpay':'','hash_id':name},
                      states=STATES)
            # Existing renewal/add-on customers must have a verified real
            # Native-account identity; an invented/missing account is a hard
            # settlement rejection in production, not a wallet-reentry bug.
            # The provider is the synthetic curl transport of this fixture.
            if name.startswith(('LWRenew','LWDay','LWVolume')):
                native='oldwallet'+name
                spec.update(existing_native_accounts=[native],
                            marzban_username=native)
            spec.update(options)
            return finance.run_php(work,env,stage,spec)

        for subject,kind,prefix,day,volume in SUBJECTS:
            actor,name,pid,order_id=seed(subject,kind,day,volume,'Positive')
            button=prefix+name
            assert len(button.encode())<=64
            original=claims(pid)
            assert original==('0','0','0')
            assert state(pid)=='low_wallet'
            # URL is not an authenticated Telegram callback.
            initial=hosted(name)
            assert state(pid)=='low_wallet' and claims(pid)==original
            assert not initial['network_calls'],(subject,initial)
            cases.append(subject+'_public_GET_does_not_rearm')

            selected=telegram(actor,button)
            assert state(pid)=='pending',(subject,selected)
            assert claims(pid)==original,(subject,selected)
            assert sql(f'SELECT wallet FROM users WHERE userid={actor}')=='0'
            assert not selected['network_calls'],(subject,selected)
            cases.append(subject+'_authenticated_Telegram_reopens_no_effect')

            # The original alternative *hosted* payment URL is still usable
            # after authenticating by the old Telegram choice. Provider calls
            # here are fake fixture transport; only the 003 local reservation
            # may be written. Repeated callbacks cannot create a second claim.
            switched=hosted(name)
            assert claims(pid)[0]=='1',(
                subject,switched.get('error'),switched['events'],
                switched['network_calls'],state(pid),claims(pid),
                'no_provider_claim_on_alternative')
            assert sql(f'SELECT wallet FROM users WHERE userid={actor}')=='0'
            assert sql(f"SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id={pid} AND provider='nextpay'")=='1'
            reselected=telegram(actor,button)
            assert claims(pid)[0]=='1'
            twice=hosted(name)
            assert claims(pid)[0]=='1',(subject,reselected,twice)
            cases.append(subject+'_alternative_hosted_once_and_replay_safe')

        # Old USDT buttons are not mcm_ until ManualCrypto.php runs AFTER
        # PrePayment.php. Reopen before the original network selector without
        # claiming funds or choosing the destination.
        actor,name,pid,oid=seed('buy','BUY_SUB',0,0,'LegacyManualCrypto')
        chosen=telegram(actor,'payBuyWithManualUsdt'+name)
        assert state(pid)=='pending',(chosen.get('error'),chosen['events'])
        assert claims(pid)==('0','0','0')
        assert any(event.get('name')=='editText' for event in chosen['events'])
        assert not chosen['network_calls']
        cases.append('legacy_manual_crypto_direct_button_reopens_before_network_menu')

        # Negative rearm probes use the identical real PrePayment.php path.
        # Leave each synthetic invoice untouched for unambiguous evidence.
        def negative(label,subject,kind,prefix,day,volume,setup=None,actor_override=None,
                     callback_override=None,callback_options=None):
            actor,name,pid,oid=seed(subject,kind,day,volume,label)
            if setup: setup(actor,name,pid,oid)
            before=state(pid); prior=claims(pid)
            chosen=callback_override(name) if callback_override else prefix+name
            denial=telegram(actor_override or actor,chosen,**(callback_options or {}))
            assert state(pid)==before=='low_wallet',(label,denial)
            assert claims(pid)==prior,(label,claims(pid),prior)
            assert sql(f'SELECT wallet FROM users WHERE userid={actor}')=='0'
            assert not denial['network_calls'],(label,denial)
            cases.append('denied_'+label)

        def issue_reference(actor,name,pid,oid):
            sql(f"UPDATE pays SET payid='issued-provider-{pid}' WHERE id={pid}")
        def expired(actor,name,pid,oid):
            sql(f'UPDATE pays SET request_date=UNIX_TIMESTAMP()-7200 WHERE id={pid}')
        def successor(actor,name,pid,oid):
            sql('INSERT INTO pays (hash_id,user_id,type,plan_id,server_id,order_id,'
                'agent_count,day,volume,price,request_date,state) VALUES '
                f"('Successor{pid}',{actor},'BUY_SUB',7,2,0,1,0,0,100,UNIX_TIMESTAMP(),'pending')")
        def attempt_with_child(actor,name,pid,oid):
            sql('INSERT INTO payment_attempts '
                '(pay_row_id,provider,order_hash,quote_amount,quote_currency,local_amount_toman,stage) VALUES '
                f"({pid},'nextpay','{name}',100,'toman',100,'pending')")
            aid=int(sql(f'SELECT id FROM payment_attempts WHERE pay_row_id={pid}'))
            sql('INSERT INTO payment_transactions (attempt_id,provider,payment_id,observed_status) VALUES '
                f"({aid},'nextpay','child{pid}','pending')")
        def effect(actor,name,pid,oid):
            sql('INSERT INTO service_effects '
                '(pay_row_id,ordinal,operation_id,effect_kind,target_server_id,target_key,'
                'endpoint_sha256,plan_id,remote_username,requested_expire,requested_bytes,request_sha256) VALUES '
                f"({pid},0,'{pid:032d}','manual_hold',2,'target{pid}',"
                f"'{('a'*64)}',7,'oldwallet{pid}',0,0,'{('b'*64)}')")
        def journal(actor,name,pid,oid):
            assert oid>0
            sql('INSERT INTO service_plan_changes '
                '(lock_key,source_order_id,plan_id,server_id,provider,state,intent_json) VALUES '
                f"('order{oid}',{oid},7,2,'marzban','prepared','{{\"old_rows\":[]}}')")

        negative('foreign_owner','buy','BUY_SUB','payBuyWithWallet',0,0,
                 actor_override=900000)
        negative('issued_reference','custom','BUY_SUB','payCustomWithWallet',30,2,
                 setup=issue_reference)
        negative('provider_attempt_with_transaction','buy','BUY_SUB','payBuyWithWallet',0,0,
                 setup=attempt_with_child)
        negative('Native_service_effect','volume','INCREASE_VOLUME','payIncreaseWithWallet',0,8,
                 setup=effect)
        negative('Native_plan_journal','day','INCREASE_DAY','payIncreaseDayWithWallet',7,0,
                 setup=journal)
        negative('expired_quote','renew','RENEW_ACCOUNT','prePayCheck_Renew_nextpay_',0,0,
                 setup=expired)
        negative('newer_draft','buy','BUY_SUB','payBuyWithWallet',0,0,
                 setup=successor)
        negative('forged_callback_family','buy','BUY_SUB','payBuyWithWallet',0,0,
                 callback_override=lambda hash:'payIncreaseWithWallet'+hash)
        negative('forged_callback_sender','custom','BUY_SUB','payCustomWithWallet',30,2,
                 callback_options={'callback_sender':900000})
        negative('disabled_wallet','buy','BUY_SUB','payBuyWithWallet',0,0,
                 callback_options={'states':{'walletState':'off'}})
        negative('disabled_manual_crypto','buy','BUY_SUB','payBuyWithWallet',0,0,
                 callback_override=lambda name:'payBuyWithManualUsdt'+name,
                 callback_options={'states':{'manualUsdtState':'off'}})
        # No Telegram callback envelope must never permit a local transition,
        # even when a syntactically valid $data string is passed internally.
        actor,name,pid,oid=seed('buy','BUY_SUB',0,0,'NoTelegramEnvelope')
        unauth=finance.run_php(work,env,stage,dict(
            route='prepayment',get={},actor=actor,
            callback_data='payBuyWithWallet'+name,states=STATES))
        assert state(pid)=='low_wallet' and claims(pid)==('0','0','0')
        assert not unauth['network_calls']
        cases.append('denied_no_Telegram_envelope')

    return {'complete':True,'cases':len(cases),'scenarios':cases,
            'real_php_mariadb':True,'actual_prepayment_php':True,
            'actual_hosted_checkout_php':True,
            'all_real_provider_calls':0,'live_wallet_mutations':0}

def main() -> None:
    p=argparse.ArgumentParser()
    p.add_argument('--run-disposable',action='store_true',required=True)
    p.add_argument('--report',type=Path)
    args=p.parse_args()
    result=check()
    if args.report:args.report.write_text(json.dumps(result,indent=2)+'\n')
    print(json.dumps(result))

if __name__=='__main__':
    main()
