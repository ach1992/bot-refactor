#!/usr/bin/env python3
"""Actual API admin routes, guarded mutations and masked menus on owned socket MariaDB."""
from __future__ import annotations
import argparse
import hashlib
import json
from pathlib import Path
import subprocess
import test_post_v9_payment_key_admin as keys
import test_access_state as owned

ROOT=Path(__file__).resolve().parents[1]
TOKEN='SYNTHETIC_SMS_ADMIN_SECRET_2026'
SECRET='SYNTHETIC_GIFT_ADMIN_SECRET_2026'

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--run-disposable',required=True,action='store_true')
    parser.add_argument('--evidence-module',type=Path)
    parser.add_argument('--report',type=Path)
    args=parser.parse_args()
    evidence=(args.evidence_module or ROOT/'src/Finance/PaymentEvidence.php').resolve()
    if not evidence.is_file():
        raise RuntimeError('PaymentEvidence source dependency is unavailable')
    evidence_bytes=evidence.read_bytes()
    evidence_sha256=hashlib.sha256(evidence_bytes).hexdigest()
    checks=[]
    bot=(ROOT/'bot.php').read_bytes()
    cfg=(ROOT/'config.php').read_bytes()
    webhook,menu,route,gateway=keys.extract(bot,cfg)
    page_start=bot.index(b'// Existing API settings use the same current private gateway authority.')
    page_stop=bot.index(b'// Privileged PAYMENT_KEYS editor:',page_start)
    gateway+=b'\n'+bot[page_start:page_stop]
    start=cfg.index(b'function achbotMutateBotStates(')
    stop=cfg.index(b'\nfunction getRejectedAgentList()',start)
    writers=b'<?php\n'+cfg[start:stop]
    driver=keys.DRIVER.replace(
        "require $root.'/src/Finance/PaymentKeyAdmin.php';",
        "require $spec['evidence_module'];\n"
        "require $root.'/src/Subscription/DirectGift.php';\n"
        "require $root.'/src/Finance/PaymentKeyAdmin.php';\n"
        "require $argv[9];"
    )
    driver=driver.replace("} elseif ($kind==='menu') {", """} elseif ($kind==='manual_ui') {
    require_once $root.'/src/Finance/ReceiptHelpers.php';
    $manualValues=achbotMessages('legacy_messages');
    $GLOBALS['events'][]=['manual',
        achbotManualReceiptPrompt($manualValues,'bank_transfer'),
        achbotManualReceiptPrompt($manualValues,'gift_card'),
        kbSendReceiptAndCancel('sendBuyCartReceipt','cancelBuyCartReceipt','owned-quote','bank_transfer'),
        kbSendReceiptAndCancel('sendBuyGiftReceipt','cancelBuyCartReceipt','owned-quote','gift_card')];
} elseif ($kind==='menu') {""")
    with owned.owned_database() as (w,env):
        keys.setup(w,env)
        evidence_snapshot=w/'PaymentEvidence.php'
        evidence_snapshot.write_bytes(evidence_bytes)
        paths={}
        for name,data in [('webhook',webhook),('menu',menu),('route',route),
                          ('gateway_menu_route',gateway),('writers',writers)]:
            paths[name]=w/(name+'.php')
            paths[name].write_bytes(data)
        (w/'api-admin.php').write_text(driver)
        seq=[100]
        def call(kind='callback',data='',text='',actor=9002,**extra):
            seq[0]+=1
            spec={'kind':kind,'data':data,'text':text,'actor':actor,
                  'update_id':seq[0],'evidence_module':str(evidence_snapshot),**extra}
            cmd=['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
                 '-d','display_errors=0','-d','log_errors=1',
                 '-d',f'error_log={w}/api-admin.log','-d','allow_url_fopen=0',
                 '-d','disable_functions=curl_init,curl_exec,exec,shell_exec,system,passthru,popen,proc_open,mail',
                 str(w/'api-admin.php'),str(ROOT),str(w/'mysql.sock'),keys.DB,
                 str(paths['webhook']),str(paths['menu']),str(paths['route']),
                 json.dumps(spec),str(paths['gateway_menu_route']),str(paths['writers'])]
            p=subprocess.run(cmd,cwd=w,env=env,capture_output=True,timeout=20)
            assert p.returncode==0 and p.stdout and not p.stderr,(
                p.returncode,p.stdout[-1500:],p.stderr[-1500:],
                (w/'api-admin.log').read_text()[-1500:] if (w/'api-admin.log').exists() else '')
            return json.loads(p.stdout)
        def editor(field,value=None,actor=9002,**extra):
            opened=call(data='changePaymentKeys'+field,actor=actor)
            assert keys.step(w,env,actor).startswith('achbotPaymentKey|'+field+'|'),opened
            if value is None: return opened
            result=call(kind='message',text=value,actor=actor,**extra)
            return result
        def values(): return keys.state(w,env)
        def states():
            return json.loads(keys.sql(w,env,"SELECT value FROM setting WHERE type='BOT_STATES'"))
        def toggle(flag,**extra):
            return call(kind='menu_callback',data='changeGateWays'+flag,**extra)
        def no_save(result):
            assert not any(x[0]=='send' and x[1]=='SAVED' for x in result['events']),result

        # Absent flags are OFF and inserted immediately below their manual method.
        menu_result=call(kind='menu')
        keyboard=json.loads(menu_result['events'][0][1])['inline_keyboard']
        for manual,api in [('giftCardState','giftApiVerifyState'),('cartToCartState','smsApiVerifyState')]:
            pos=next(i for i,row in enumerate(keyboard) if row[0].get('callback_data')=='changeGateWays'+manual)
            assert keyboard[pos+1][0]=={'text':'OFF','callback_data':'changeGateWays'+api}
        assert 'cartToCartAutoAcceptState' not in str(keyboard)
        manual=call(kind='manual_ui')['events'][0]
        assert 'تصویر' in manual[1] and 'تصویر' in manual[2],manual
        assert 'ارسال رسید بانکی' in json.loads(manual[3])['inline_keyboard'][0][0]['text']
        assert 'ارسال تصویر گیفت‌کارت' in json.loads(manual[4])['inline_keyboard'][0][0]['text']
        assert 'ارسال کد' not in str(manual)
        checks.append('off_existing_manual_bank_and_gift_photo_prompts_and_buttons_preserved')
        before=states()
        toggle('smsApiVerifyState')
        assert states()==before
        checks.append('absent_modes_off_adjacent_rows_incomplete_activation_denied')

        # Actual private field edits; secret is encrypted, never rendered or in step.
        editor('smsApiUrl','https://example.com/wp-json/ach-pay-manager/v1')
        secret_saved=editor('smsApiToken',TOKEN)
        assert TOKEN not in json.dumps(values()) and TOKEN not in str(secret_saved)
        assert TOKEN not in keys.step(w,env)
        assert not any(x[0]=='delete' for x in secret_saved['events'])
        assert 'smsApiHorizonHours' not in values()
        before=states();toggle('smsApiVerifyState');assert states()==before
        editor('smsApiHorizonHours','48')
        toggle('smsApiVerifyState');assert states()['smsApiVerifyState']=='on'
        enabled=states()
        toggle('smsApiVerifyState');assert states()['smsApiVerifyState']=='off'
        assert {k:v for k,v in states().items() if k!='smsApiVerifyState'}==before
        checks.append('encrypted_token_explicit_horizon_guarded_enable_disable_preserves_other_flags')

        # Optional filters/time settings remain unset; no new activation prerequisite.
        assert all(not values().get(k) for k in ('smsApiProvider','smsApiSender','smsApiMobileSource',
            'smsApiRuleId','smsApiBankKey','smsApiTimezone','smsApiCalendar'))
        editor('giftApiUrl','https://example.com/wp-json/ach-gcw/v2')
        editor('giftApiKeyId','synthetic-gift-client')
        gift_saved=editor('giftApiSecret',SECRET)
        assert SECRET not in json.dumps(values()) and SECRET not in str(gift_saved)
        before=states();toggle('giftApiVerifyState');assert states()==before
        unknown=call(data='changePaymentKeysgiftApiAmountField')
        assert any(x[0]=='deny' for x in unknown['events'])
        editor('giftApiAmountUnit','toman')
        toggle('giftApiVerifyState');assert states()['giftApiVerifyState']=='on'
        assert not values().get('giftApiGameKey') and not values().get('giftApiValueId')
        checks.append('gift_canonical_amount_only_explicit_unit_required_optional_scope_unset')

        # Menus and confirmations have status labels, no raw authentication values.
        for data in ('changePaymentKeysApiSms','changePaymentKeysApiSmsAdvanced',
                     'changePaymentKeysApiGift','changePaymentKeysApiGiftAdvanced'):
            view=call(kind='menu_callback',data=data)
            assert any(x[0]=='edit' for x in view['events']),view
            assert TOKEN not in str(view) and SECRET not in str(view)
            for event in view['events']:
                if event[0]=='edit':
                    markup=json.loads(event[2])
                    for row in markup['inline_keyboard']:
                        for button in row:
                            assert len(button.get('callback_data','').encode())<=64
        checks.append('actual_private_pages_mask_secrets_and_bound_callbacks')

        # Cancellation preserves value and incoming administrator history.
        old=values()['smsApiToken']
        editor('smsApiToken')
        canceled=call(kind='message',text='Cancel')
        assert values()['smsApiToken']==old and keys.step(w,env)=='none'
        assert not any(x[0]=='delete' for x in canceled['events'])
        assert any('تنظیمات اتصال پیامک بانکی' in str(x) for x in canceled['events'])
        checks.append('cancel_one_action_returns_to_api_page_without_deleting_admin_input')

        # Validation and clear use the real evidence normalizer.
        for field,value in [('smsApiHorizonHours','0'),('smsApiHorizonHours','1.5'),
                            ('giftApiAmountUnit','USD'),('giftApiValueId','0'),
                            ('smsApiUrl','http://example.com/wp-json/ach-pay-manager/v1')]:
            old=values().get(field)
            editor(field)
            result=call(kind='message',text=value)
            no_save(result);assert values().get(field)==old
            call(kind='message',text='Cancel')
        editor('smsApiHorizonHours','/clear')
        assert values()['smsApiHorizonHours']==''
        before=states();toggle('smsApiVerifyState');assert states()==before
        checks.append('real_normalizer_rejects_bad_money_horizon_urls_clear_blocks_enable')

        # Source/frame forgery, revoked role, lower tiers and malformed page names.
        for role in ('operator','support'):
            keys.sql(w,env,"UPDATE setting SET value='"+role+"' WHERE type='ADMIN_ROLE_9002'")
            before=values();step_before=keys.step(w,env)
            result=call(data='changePaymentKeyssmsApiToken',cached_admin=1)
            assert values()==before and keys.step(w,env)==step_before
            assert any(x[0]=='deny' for x in result['events'])
            page=call(kind='menu_callback',data='changePaymentKeysApiSms')
            assert not any(x[0]=='edit' for x in page['events'])
        keys.sql(w,env,"UPDATE setting SET value='manager' WHERE type='ADMIN_ROLE_9002'")
        editor('smsApiToken')
        keys.sql(w,env,'UPDATE users SET isAdmin=0 WHERE userid=9002')
        old=values();no_save(call(kind='message',text='ATTACKER_TOKEN',cached_admin=1));assert values()==old
        keys.sql(w,env,"UPDATE users SET isAdmin=1,step='none' WHERE userid=9002")
        for invalid in ('token','group','sender','message'):
            old=values()
            result=call(data='changePaymentKeyssmsApiToken',invalid=invalid)
            assert values()==old and any(x[0]=='deny' for x in result['events']),result
        malformed=call(data='changePaymentKeysApiSmsAdvancedJunk')
        assert any(x[0]=='deny' for x in malformed['events'])
        checks.append('current_manager_authority_required_revocation_forged_frames_malformed_denied')

        # A second editor invalidates the older baseline, including same-value ABA.
        editor('smsApiHorizonHours',actor=9002)
        editor('smsApiHorizonHours','72',actor=9003)
        old=values()
        no_save(call(kind='message',text='24',actor=9002))
        assert values()==old and values()['smsApiHorizonHours']=='72'
        call(kind='message',text='Cancel',actor=9002)
        opened=editor('smsApiHorizonHours')
        replay_seq=seq[0]
        no_save(call(kind='message',text='24',update_id=replay_seq))
        assert values()['smsApiHorizonHours']=='72'
        call(kind='message',text='Cancel')
        checks.append('field_revision_conflict_and_replayed_message_cannot_overwrite')

        # Clearing protected settings does not retain plaintext or make a valid profile.
        editor('smsApiToken','/clear')
        assert values()['smsApiToken']==''
        view=call(kind='menu_callback',data='changePaymentKeysApiSms')
        assert any(button['text']=='تنظیم‌نشده'
            for event in view['events'] if event[0]=='edit'
            for row in json.loads(event[2])['inline_keyboard'] for button in row),view
        assert TOKEN not in str(view)
        assert keys.sql(w,env,'SELECT COUNT(*) FROM pays')=='0'
        checks.append('clear_secret_unsets_status_no_payment_rows_or_external_effects')
        # A stored credential must be decryptable, not merely present.
        doc=values();doc['smsApiToken']='MALFORMED_PLAINTEXT_CREDENTIAL'
        keys.store(w,env,doc)
        view=call(kind='menu_callback',data='changePaymentKeysApiSms')
        assert 'MALFORMED_PLAINTEXT_CREDENTIAL' not in str(view)
        assert any(button['text']=='نامعتبر'
            for event in view['events'] if event[0]=='edit'
            for row in json.loads(event[2])['inline_keyboard'] for button in row)
        before=states();toggle('smsApiVerifyState');assert states()==before
        editor('smsApiToken',TOKEN,actor=9001)
        editor('smsApiHorizonHours','48',actor=9001)
        toggle('smsApiVerifyState',actor=9001)
        assert states()['smsApiVerifyState']=='on'
        current=states();old_source=seq[0]
        toggle('smsApiVerifyState',actor=9001,update_id=old_source)
        assert states()==current
        malformed=call(kind='menu_callback',data='changeGateWayssmsApiNotState')
        assert states()==current and any(x[0]=='deny' for x in malformed['events'])
        checks.append('malformed_stored_secret_invalid_owner_allowed_duplicate_toggle_unknown_flag_denied')

    report={'status':'PASS','checks':checks,'evidence_module':str(evidence),
            'evidence_sha256':evidence_sha256}
    if args.report: args.report.write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps(report,ensure_ascii=False))

if __name__=='__main__': main()
