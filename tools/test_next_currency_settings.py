#!/usr/bin/env python3
"""Explicit bot USDT rates: native PHP, owned MariaDB, no real config/network."""
import argparse
import hashlib
import json
import subprocess
from pathlib import Path
import test_access_state as owned
import test_v8_native as purchase
import test_post_v9_manual_payments as manual
import test_post_v9_finance_routes as finance
import test_post_v9_purchases as stages

ROOT=Path(__file__).resolve().parents[1]
NORMAL={'manualUsdtWalletBEP20':'0x'+'1'*40,'manualUsdtRateToman':'100000',
        'nowpayment':'SYNTHETIC-LOCAL-ONLY',
        'manualUsdtWalletERC20':'0x'+'2'*40,
        'manualUsdtWalletTRC20':'TNPeeaaFB7K9cmo4uQpcU32zGK8G1NYqeL',
        'manualTrxWallet':'TJRabPrwbZy45sbavfcjinPJC18kjpRTv8',
        'manualTrxRateToman':'15000'}

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('--run-disposable',required=True,action='store_true')
    ap.add_argument('--report',type=Path)
    args=ap.parse_args();cases=[]
    with owned.owned_database() as (work,env):
        purchase.init(work,env,'http://127.0.0.1:9')
        sql=lambda text: manual.query(work,env,text)
        for name in ['001_marzban_panel_group_id.sql','003_payment_attempts.sql',
                     '006_payment_message_refs.sql','007_service_effects.sql',
                     '008_referral_awards.sql','009_bank_buy_invoice_claims.sql']:
            sql((ROOT/'migrations/post-v9'/name).read_text())
        sql("INSERT INTO setting(type,value) VALUES ('BOT_STATES',"
            "'{\"walletState\":\"on\",\"cartToCartState\":\"on\",\"giftCardState\":\"on\","
            "\"manualUsdtState\":\"on\",\"nowPaymentWallet\":\"on\",\"nowPaymentOther\":\"on\","
            "\"zarinpal\":\"on\",\"nextpay\":\"on\"}')")
        sql('UPDATE users SET is_agent=1 WHERE userid=9002')
        driver=work/'manual.php';driver.write_text(manual.PHP)
        helper=work/'rates.php';helper.write_text(r'''<?php
$connection=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$paymentKeys=json_decode($argv[4],true,512,JSON_THROW_ON_ERROR);
$botState=['manualUsdtState'=>'on'];
$USDTPrice=123456;$USDTPriceagent=654321;
require_once $argv[1].'/src/Finance/ReceiptHelpers.php';
$normal=achbotUsdtRateForUser($connection,1001);
$agent=achbotUsdtRateForUser($connection,9002);
echo json_encode([$normal,$agent,array_keys(achbotManualCryptoOptions(1001)),
                  array_keys(achbotManualCryptoOptions(9002))],JSON_THROW_ON_ERROR);
''')
        def rates(keys,root=ROOT):
            p=subprocess.run(['php','-d','allow_url_fopen=0',str(helper),str(root),
                str(work/'mysql.sock'),manual.DB,json.dumps(keys)],capture_output=True,text=True)
            assert p.returncode==0 and not p.stderr,(p.stdout,p.stderr)
            return json.loads(p.stdout)
        for label,keys,want in [
            ('no_settings_ignores_private_globals',{},[None,None,[],[]]),
            ('shared_normal',NORMAL,[100000,100000,['b','c','e','t'],['b','c','e','t']]),
            ('minimum_normal_rate',dict(NORMAL,manualUsdtRateToman='100'),[100,100,['b','c','e','t'],['b','c','e','t']]),
            ('maximum_normal_rate',dict(NORMAL,manualUsdtRateToman='1000000000'),[1000000000,1000000000,['b','c','e','t'],['b','c','e','t']]),
            ('retired_agent_override_ignored',dict(NORMAL,usdtAgentRateToman='200000'),[100000,100000,['b','c','e','t'],['b','c','e','t']]),
            ('invalid_retired_agent_ignored',dict(NORMAL,usdtAgentRateToman='bad'),[100000,100000,['b','c','e','t'],['b','c','e','t']]),
            ('cleared_retired_agent_ignored',dict(NORMAL,usdtAgentRateToman=''),[100000,100000,['b','c','e','t'],['b','c','e','t']]),
            ('nonscalar_retired_agent_ignored',dict(NORMAL,usdtAgentRateToman=[]),[100000,100000,['b','c','e','t'],['b','c','e','t']]),
            ('retired_agent_cannot_rescue_clear_normal',dict(NORMAL,manualUsdtRateToman='',usdtAgentRateToman='200000'),[None,None,['t'],['t']]),
            ('clear_normal_no_fallback',dict(NORMAL,manualUsdtRateToman=''),[None,None,['t'],['t']]),
        ]:
            assert rates(keys)==want,(label,rates(keys));cases.append(label)
        for index,raw in enumerate(['1','99','1000000001','1e400',None,[],True,'nan']):
            assert rates(dict(NORMAL,manualUsdtRateToman=raw))==[None,None,['t'],['t']],raw
            assert rates(dict(NORMAL,manualUsdtRateToman=raw,usdtAgentRateToman='200000'))==[None,None,['t'],['t']],raw
            cases.append('invalid_explicit_normal_'+str(index))
        # Historical RC12 source supplies a real already-issued agent quote.
        # The new helper must reuse it unchanged despite the new shared rate.
        legacy=work/'rc12-runtime';stages.stage_source(legacy,True)
        (legacy/'src/Finance/ReceiptHelpers.php').write_bytes(subprocess.check_output(
            ['git','show','ecd30ba9192ed374703118a6c131be8809cc7b06:src/Finance/ReceiptHelpers.php'],cwd=ROOT))
        archived=rates(dict(NORMAL,usdtAgentRateToman='200000'),root=legacy)
        assert archived[:2]==[100000,200000] and rates(dict(NORMAL,usdtAgentRateToman='200000'))[:2]==[100000,100000]
        cases.append('archived_rc12_override_RED_current_shared_GREEN')
        before=sql('SELECT userid,wallet FROM users ORDER BY id')
        for network in ['BEP20','TRC20','ERC20']:
            label='prioragent'+network.lower()
            manual.seed(work,env,label,owner=9002)
            previous_root=manual.ROOT
            try:
                manual.ROOT=legacy
                assert manual.run_php(work,env,driver,label,'claim','manual_usdt',user=9002,
                    keys=dict(NORMAL,usdtAgentRateToman='200000'),network=network)
            finally:
                manual.ROOT=previous_root
            old_quote=sql("SELECT quote_amount,checkout_url FROM payment_attempts WHERE order_hash='"+label+"'")
            assert old_quote.startswith('0.50000000\t'),old_quote
            assert manual.run_php(work,env,driver,label,'claim','manual_usdt',user=9002,keys=NORMAL,network=network)
            assert sql("SELECT quote_amount,checkout_url FROM payment_attempts WHERE order_hash='"+label+"'")==old_quote
            cases.append('rc12_agent_quote_preserved_'+network)
        for network in ['BEP20','TRC20','ERC20']:
            for user in [1001,9002]:
                label='shared'+network.lower()+str(user)
                manual.seed(work,env,label,owner=user)
                assert manual.run_php(work,env,driver,label,'claim','manual_usdt',user=user,
                    keys=dict(NORMAL,usdtAgentRateToman='200000'),network=network)
                assert sql("SELECT quote_amount FROM payment_attempts WHERE order_hash='"+label+"'")=='1.00000000'
                cases.append('shared_quote_'+network+'_'+str(user))
        for label,user,keys,amount in [
            ('normalquote',1001,NORMAL,'1.00000000'),
            ('agentquote',9002,dict(NORMAL,usdtAgentRateToman='200000'),'1.00000000'),
            ('agentfallback',9002,NORMAL,'1.00000000')]:
            manual.seed(work,env,label,owner=user)
            assert manual.run_php(work,env,driver,label,'claim','manual_usdt',user=user,keys=keys,network='BEP20')
            pinned=sql("SELECT quote_amount,checkout_url FROM payment_attempts WHERE order_hash='"+label+"'")
            assert pinned.startswith(amount+'\t'),(label,pinned)
            assert manual.run_php(work,env,driver,label,'claim','manual_usdt',user=user,keys={},network='BEP20')
            assert sql("SELECT quote_amount,checkout_url FROM payment_attempts WHERE order_hash='"+label+"'")==pinned
            cases.append(label+'_pinned_after_settings_clear')
        for label,user,keys in [('invalidagentclaim',9002,dict(NORMAL,manualUsdtRateToman='bad',usdtAgentRateToman='200000')),
                                ('missingnormalclaim',1001,dict(NORMAL,manualUsdtRateToman=''))]:
            manual.seed(work,env,label,owner=user)
            assert not manual.run_php(work,env,driver,label,'claim','manual_usdt',user=user,keys=keys,network='BEP20')
            assert sql("SELECT COUNT(*) FROM payment_attempts WHERE order_hash='"+label+"'")=='0'
            cases.append(label+'_no_attempt_no_money')
        stage=work/'runtime';stages.stage_source(stage,True)
        (stage/'baseInfo.php').write_text('<?php throw new RuntimeException("PRIVATE_CONFIG_READ");')
        private_before=hashlib.sha256((stage/'baseInfo.php').read_bytes()).hexdigest()
        # Reuse source-real HTTP initiation with disabled cURL replacements.
        (work/'owned-finance-guard.php').write_text(finance.PHP_DRIVER)
        old_keys=dict(NORMAL,usdtAgentRateToman='200000')
        sql("DELETE FROM setting WHERE type='PAYMENT_KEYS'")
        sql("INSERT INTO setting(type,value) VALUES ('PAYMENT_KEYS','"+json.dumps(old_keys)+"')")
        finance.seed_payment(work,env,'priorhostagent','INCREASE_WALLET',payid='')
        sql("UPDATE pays SET user_id=9002,price=100000 WHERE hash_id='priorhostagent'")
        original=finance.run_php(work,env,legacy,dict(route='initiate',get={'nowpayment':'','hash_id':'priorhostagent'},
            keys=old_keys,fake_invoice_id='priorhostagentinvoice'))
        assert len(original['network_calls'])==1
        old_pinned=sql("SELECT quote_amount,checkout_url FROM payment_attempts WHERE order_hash='priorhostagent'")
        assert old_pinned.startswith('0.50000000\t'),old_pinned
        shared=finance.run_php(work,env,stage,dict(route='initiate',get={'nowpayment':'','hash_id':'priorhostagent'},keys=NORMAL))
        assert shared['network_calls']==[]
        assert sql("SELECT quote_amount,checkout_url FROM payment_attempts WHERE order_hash='priorhostagent'")==old_pinned
        cases.append('rc12_hosted_agent_quote_preserved_no_new_request')
        for label,user,keys,want in [
            ('hostnormal',1001,NORMAL,'1.00000000'),
            ('hostagent',9002,dict(NORMAL,usdtAgentRateToman='200000'),'1.00000000'),
            ('hostfallback',9002,NORMAL,'1.00000000'),
            ('hostmissing',1001,dict(NORMAL,manualUsdtRateToman=''),None),
            ('hostbadagent',9002,dict(NORMAL,usdtAgentRateToman='bad'),'1.00000000'),
            ('hostagentmissingnormal',9002,dict(NORMAL,manualUsdtRateToman='',usdtAgentRateToman='200000'),None),
        ]:
            sql("DELETE FROM setting WHERE type='PAYMENT_KEYS'")
            document=json.dumps(keys).replace("'","''")
            sql("INSERT INTO setting(type,value) VALUES ('PAYMENT_KEYS','"+document+"')")
            finance.seed_payment(work,env,label,'INCREASE_WALLET',payid='')
            sql(f"UPDATE pays SET user_id={user},price=100000 WHERE hash_id='{label}'")
            result=finance.run_php(work,env,stage,dict(route='initiate',get={'nowpayment':'','hash_id':label},
                keys=keys,fake_invoice_id=label+'invoice'))
            if want is None:
                assert result['network_calls']==[] and result['form'],result
                assert 'پشتیبانی' in result['form'] and all(secret not in result['form'] for secret in
                    ('NOWPayments','SQL','USDTPrice','manualUsdtRateToman','SYNTHETIC','نرخ'))
                assert sql(f"SELECT COUNT(*) FROM payment_attempts WHERE order_hash='{label}'")=='0'
            else:
                assert len(result['network_calls'])==1,result
                pinned=sql(f"SELECT quote_amount FROM payment_attempts WHERE order_hash='{label}'")
                assert pinned==want,(label,pinned)
                sql("UPDATE setting SET value='{}' WHERE type='PAYMENT_KEYS'")
                reused=finance.run_php(work,env,stage,dict(route='initiate',get={'nowpayment':'','hash_id':label},keys={}))
                assert reused['network_calls']==[],reused
                assert sql(f"SELECT quote_amount FROM payment_attempts WHERE order_hash='{label}'")==pinned
            cases.append(label+'_audience_quote_and_privacy')
        assert sql('SELECT userid,wallet FROM users ORDER BY id')==before
        assert sql('SELECT COUNT(*) FROM payment_transactions')=='0'
        assert hashlib.sha256((stage/'baseInfo.php').read_bytes()).hexdigest()==private_before
        cases.extend(['no_wallet_or_settlement_effect','private_config_unread_and_unchanged'])
    report={'pass':True,'cases':cases}
    if args.report:args.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps(report))

if __name__=='__main__':main()
