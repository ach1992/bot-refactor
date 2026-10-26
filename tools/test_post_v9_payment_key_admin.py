#!/usr/bin/env python3
"""Source-real PAYMENT_KEYS admin permissions, concurrency, data and menu privacy.

Extracts real bot.php route / config.php menu, loads actual PaymentKeyAdmin.php,
real webhook and original 23-table MariaDB on a disposable socket. No services,
Telegram, credentials, network calls, or customer balance are touched.
"""
from __future__ import annotations

import argparse
import json
from pathlib import Path
import subprocess

import test_access_state as owned
import test_post_v9_checkout_serialization as database
import test_v8_native as fixture

ROOT=Path(__file__).resolve().parents[1]
BASE='77ae8c8f9c3774aae4fea0817fa5da7bc3e110d3'
DB=fixture.SIDE[1]
SECRET='SYNTHETIC_MERCHANT_ABC_2026_ONLY'
ADDRESS='SYNTHETIC_PUBLIC_BANK_00001'

DRIVER=r"""<?php
error_reporting(E_ALL);
set_error_handler(static function($level,$message){
    if ($level===E_DEPRECATED) return true;
    throw new ErrorException($message,0,$level);
});
$_SERVER['SCRIPT_FILENAME']=__FILE__;
$_SERVER['REQUEST_METHOD']='POST';
$root=$argv[1];$socket=$argv[2];$dbname=$argv[3];
$webhook=$argv[4];$menu=$argv[5];$route=$argv[6];
$spec=json_decode($argv[7],true,512,JSON_THROW_ON_ERROR);
putenv('ACHBOT_TELEGRAM_WEBHOOK_SECRET=ownedSyntheticMerchantToken2026');
$_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']='ownedSyntheticMerchantToken2026';
$connection=new mysqli('localhost','root','',$dbname,0,$socket);
$connection->set_charset('utf8mb4');
if (($spec['isolation']??'')==='READ-COMMITTED')
    $connection->query('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
$from_id=(int)($spec['actor']??9002);
$admin=9001;
$chat_id=(int)($spec['chat']??$from_id);
$message_id=(int)($spec['message_id']??77);
$data=(string)($spec['data']??'');
$text=$spec['text']??'';
$userInfo=['isAdmin'=>(int)($spec['cached_admin']??1),
           'step'=>(string)($spec['cached_step']??'none')];
if (!array_key_exists('cached_step',$spec)) {
    $st=$connection->prepare('SELECT step FROM users WHERE userid=? LIMIT 1');
    $st->bind_param('i',$from_id);$st->execute();
    $found=$st->get_result()->fetch_assoc();$st->close();
    $userInfo['step']=(string)($found['step']??'none');
}
$mainValues=['saved_successfuly'=>'SAVED','change_bot_settings_message'=>'SETTINGS'];
$buttonValues=['cancel'=>'Cancel','on'=>'ON','off'=>'OFF','back_button'=>'BACK'];
$cancelKey='CANCEL-KEY';$removeKeyboard='REMOVE-KEY';
$callbackId='test-callback';
$botState=['cartToCartState'=>'on','walletState'=>'on','sellState'=>'on',
    'giftCardState'=>'on','manualUsdtState'=>'on','botState'=>'on',
    'nowPaymentWallet'=>'on','nowPaymentOther'=>'on','nextpay'=>'on','zarinpal'=>'on'];
$kind=$spec['kind']??'callback';
$source=(object)['id'=>$from_id,'is_bot'=>false];
$chat=(object)['id'=>$chat_id,'type'=>'private'];
if ($kind==='callback' || $kind==='menu_callback') {
    $update=(object)['update_id'=>(int)($spec['update_id']??100),
        'callback_query'=>(object)['id'=>'owned-'.(int)($spec['update_id']??100),
            'from'=>$source,
            'message'=>(object)['message_id'=>$message_id,'chat'=>$chat],
            'data'=>$data]];
} else {
    $update=(object)['update_id'=>(int)($spec['update_id']??100),
        'message'=>(object)['message_id'=>$message_id,'from'=>$source,
            'chat'=>$chat,'text'=>$text]];
}
if (($spec['invalid']??null)==='token')
    unset($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']);
if (($spec['invalid']??null)==='group') $chat->type='group';
if (($spec['invalid']??null)==='sender') $source->id++;
if (($spec['invalid']??null)==='message') $update->callback_query->message->message_id++;
if (($spec['invalid']??null)==='text' && $kind==='message')
    $update->message->text='synthetic-tampered-text';
$GLOBALS['events']=[];
function sendMessage(...$args) {$GLOBALS['events'][]=['send',(string)($args[0]??''),(string)($args[1]??'')];}
function answerCallbackQuery(...$args) {$GLOBALS['events'][]=['deny',(string)($args[1]??'')];}
function delMessage(...$args) {$GLOBALS['events'][]=['delete'];}
function editText(...$args) {
    $GLOBALS['events'][]=['edit',(string)($args[1]??''),(string)($args[2]??'')];
}
function setUser($step='none') {
    global $connection,$from_id;
    $stmt=$connection->prepare('UPDATE users SET step=? WHERE userid=?');
    $stmt->bind_param('si',$step,$from_id);$stmt->execute();$stmt->close();
}
require $webhook;
require $menu;
require $root.'/src/Finance/PaymentKeyAdmin.php';
require $root.'/src/Finance/GatewayRuleAdmin.php';
if ($kind==='menu') {
    $GLOBALS['events'][]=['menu',getGateWaysKeys()];
} elseif ($kind==='menu_callback') {
    require $argv[8];
} else {
    require $route;
}
echo json_encode(['events'=>$GLOBALS['events']],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
"""

def old_blob(name):
    return subprocess.check_output(['git','show',BASE+':'+name],cwd=ROOT)

def extract(bot:bytes,cfg:bytes):
    start=bot.index(b'function achbotWebhookIngressAuthenticated(): bool')
    stop=bot.index(b'if (!achbotWebhookIngressAuthenticated())',start)
    webhook=b'<?php\n'+bot[start:stop]
    start=cfg.index(b'function getGateWaysKeys(){')
    stop=cfg.index(b'\nfunction ',start+1)
    menu=b'<?php\n'+cfg[start:stop]
    # Rebase ONLY the extracted helper import. Production always resolves
    # ReceiptHelpers from the original config.php directory.
    before=b"require_once __DIR__ . '/src/Finance/ReceiptHelpers.php';"
    assert menu.count(before)==1
    menu=menu.replace(before,b"require_once $GLOBALS['root'] . '/src/Finance/ReceiptHelpers.php';",1)
    if b'// Privileged PAYMENT_KEYS editor:' in bot:
        start=bot.index(b'// Privileged PAYMENT_KEYS editor:')
    else:
        start=bot.index(b"if(preg_match('/^changePaymentKeys(")
    stop=bot.index(b"require __DIR__ . '/src/Agent/Discounts.php';",start)
    route=b'<?php\n'+bot[start:stop]
    a=bot.index(b'if(($data=="gateWays_Channels"')
    b=bot.index(b'// Strict, source-authenticated private operator rules.',a)
    gateway_menu_route=b'<?php\n'+bot[a:b]
    return webhook,menu,route,gateway_menu_route

def sql(w,e,q):
    return database.sql(w,e,q)

def setup(w,e):
    fixture.init(w,e,'http://127.0.0.1:9')
    assert len(sql(w,e,'SHOW TABLES').splitlines())==23
    flags={k:'on' for k in ('nowPaymentOther','nowPaymentWallet','walletState',
          'cartToCartState','giftCardState','manualUsdtState','sellState',
          'botState','nextpay','zarinpal')}
    flags.update({'rewardChannel':'','lockChannel':'','supportID':''})
    raw=json.dumps(flags).replace("'","''")
    sql(w,e,"INSERT INTO setting(type,value) VALUES ('BOT_STATES','"+raw+"')")
    sql(w,e,'DELETE FROM users WHERE userid IN (9001,9002,9003)')
    sql(w,e,"INSERT INTO users(userid,name,username,refcode,date,isAdmin) VALUES "
        "(9001,'Primary','first','0','1700000000',1),"
        "(9002,'Delegate','second','0','1700000000',1),"
        "(9003,'Delegate2','third','0','1700000000',1)")
    sql(w,e,"INSERT INTO setting(type,value) VALUES ('PAYMENT_KEYS','{}')")

def store(w,e,doc):
    raw=json.dumps(doc,ensure_ascii=False,separators=(',',':')).replace("'","''")
    sql(w,e,"DELETE FROM setting WHERE type='PAYMENT_KEYS'; "
        "INSERT INTO setting(type,value) VALUES ('PAYMENT_KEYS','"+raw+"')")

def state(w,e):
    raw=sql(w,e,"SELECT value FROM setting WHERE type='PAYMENT_KEYS'")
    return json.loads(raw) if raw else {}

def step(w,e,actor=9002):
    return sql(w,e,f'SELECT step FROM users WHERE userid={actor}')

def marker(w,e):
    raw=sql(w,e,"SELECT value FROM setting WHERE type='ACHBOT_ADMIN_PAYMENT_KEYS_SOURCE'")
    return json.loads(raw) if raw else None

def invoke(w,e,paths,kind='callback',data='',text='',actor=9002,seq=101,**extra):
    spec={'kind':kind,'data':data,'text':text,'actor':actor,'update_id':seq,**extra}
    command=['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
             '-d','display_errors=0','-d','log_errors=1',
             '-d',f'error_log={w}/synthetic-key-admin.log',
             '-d','allow_url_fopen=0',
             '-d','disable_functions=curl_init,curl_exec,exec,shell_exec,system,passthru,popen,proc_open,mail',
             str(w/'key-admin-driver.php'),str(ROOT),str(w/'mysql.sock'),DB,
             str(paths['webhook']),str(paths['menu']),str(paths['route']),
             json.dumps(spec),str(paths['gateway_menu_route'])]
    p=subprocess.run(command,cwd=w,env=e,capture_output=True,timeout=20)
    assert p.returncode==0 and not p.stderr,(p.returncode,p.stdout[-1100:],p.stderr[-1200:],(w/'synthetic-key-admin.log').read_text()[-1200:] if (w/'synthetic-key-admin.log').exists() else 'NO-LOG')
    assert p.stdout,p
    return json.loads(p.stdout)

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--run-disposable',action='store_true',required=True)
    parser.add_argument('--report',type=Path)
    args=parser.parse_args()
    checks=[]
    with owned.owned_database() as (w,e):
        setup(w,e)
        (w/'key-admin-driver.php').write_text(DRIVER)
        sources={}
        for label,bot,cfg in [
            ('baseline',old_blob('bot.php'),old_blob('config.php')),
            ('current',(ROOT/'bot.php').read_bytes(),(ROOT/'config.php').read_bytes())
        ]:
            paths={}
            for name,data in zip(('webhook','menu','route','gateway_menu_route'),
                                 extract(bot,cfg)):
                p=w/(label+'-'+name+'.php');p.write_bytes(data);paths[name]=p
            sources[label]=paths
        old=sources['baseline'];new=sources['current']
        seq=[100]
        def call(kind='callback',data='',text='',actor=9002,**kwargs):
            seq[0]+=1
            return invoke(w,e,new,kind=kind,data=data,text=text,
                          actor=actor,seq=seq[0],**kwargs)
        def reset(doc=None):
            sql(w,e,"DELETE FROM setting WHERE type='ACHBOT_ADMIN_PAYMENT_KEYS_SOURCE'")
            sql(w,e,"UPDATE users SET step='none',isAdmin=1 WHERE userid IN (9001,9002,9003)")
            store(w,e,{} if doc is None else doc)
        def opened(field,actor=9002):
            result=call(data='changePaymentKeys'+field,actor=actor)
            assert step(w,e,actor).startswith('achbotPaymentKey|'+field+'|'),result
            assert not any(event[0]=='deny' for event in result['events']),result
            return result
        def saved(value,actor=9002,**kwargs):
            return call(kind='message',text=value,actor=actor,**kwargs)
        def denied(result):
            assert not any(event[0]=='send' and event[1]=='SAVED'
                           for event in result['events']),result
        # Historical source reproduces the concrete secret exposure. A
        # current menu offers a status while preserving public destinations.
        reset({'nowpayment':SECRET,'nextpay':'NEXT_SYNTHETIC',
               'zarinpal':'ZARIN_SYNTHETIC','bankAccount':ADDRESS,
               'holderName':'Test public holder'})
        old_menu=invoke(w,e,old,kind='menu')
        new_menu=invoke(w,e,new,kind='menu')
        assert SECRET in str(old_menu) and 'NEXT_SYNTHETIC' in str(old_menu)
        assert all(x not in str(new_menu) for x in
                   (SECRET,'NEXT_SYNTHETIC','ZARIN_SYNTHETIC'))
        assert ADDRESS in str(new_menu) and 'Test public holder' in str(new_menu)
        checks.append('baseline_raw_merchant_secret_menu_red_current_masked_green')
        # Original cached-role writer can save after database role revocation.
        reset({'nowpayment':'OLD_SECRET'})
        sql(w,e,"UPDATE users SET isAdmin=0,step='changePaymentKeysnowpayment' WHERE userid=9002")
        old_save=invoke(w,e,old,kind='message',text=SECRET,cached_admin=1,seq=90)
        assert state(w,e)['nowpayment']==SECRET,old_save
        checks.append('baseline_revoked_cached_delegate_writer_red')
        # Even the READ-ONLY gateway settings menu must refuse a revoked
        # cached delegate, because it contains public payment destinations.
        reset({'bankAccount':ADDRESS,'nowpayment':SECRET})
        sql(w,e,'UPDATE users SET isAdmin=0 WHERE userid=9002')
        old_read=invoke(w,e,old,kind='menu_callback',
            data='gateWays_Channels',cached_admin=1,seq=91)
        assert any(event[0]=='edit' and ADDRESS in str(event)
                   for event in old_read['events']),old_read
        new_read=call(kind='menu_callback',data='gateWays_Channels',cached_admin=1)
        assert not any(event[0]=='edit' for event in new_read['events'])
        assert any(event[0]=='deny' for event in new_read['events'])
        checks.append('revoked_cached_delegate_cannot_open_payment_menu')
        sql(w,e,'UPDATE users SET isAdmin=1 WHERE userid=9002')
        normal_read=call(kind='menu_callback',data='gateWays_Channels')
        assert any(event[0]=='edit' for event in normal_read['events']),normal_read
        assert SECRET not in str(normal_read)
        checks.append('current_admin_private_menu_safe_and_editable')
        reset({'nowpayment':'OLD_SECRET'})
        sql(w,e,"UPDATE users SET isAdmin=0 WHERE userid=9002")
        denied(call(data='changePaymentKeysnowpayment',cached_admin=1))
        assert state(w,e)['nowpayment']=='OLD_SECRET' and step(w,e)=='none'
        assert marker(w,e) is None
        checks.append('revoked_delegate_cannot_open_merchant_editor')
        # Start and save are separate authenticated Telegram events. The
        # active step carries a non-secret sha256 baseline and source nonce.
        reset()
        opened('nowpayment')
        first=marker(w,e)['last_update_id']
        assert str(first) in step(w,e)
        assert SECRET not in step(w,e)
        success=saved(SECRET)
        assert state(w,e)['nowpayment']==SECRET
        assert step(w,e)=='none' and marker(w,e)['last_update_id']>first
        assert SECRET not in str(success)
        assert any(x[0]=='send' and x[1]=='SAVED' for x in success['events'])
        checks.append('signed_private_text_saves_field_without_credential_echo')
        again=saved(SECRET)
        assert not any(x[0]=='send' and x[1]=='SAVED' for x in again['events'])
        assert state(w,e)['nowpayment']==SECRET
        checks.append('replayed_credential_message_has_no_write')
        # A revoked administrator who already owns a valid private step
        # cannot turn it into a persisted secret write.
        reset({'nextpay':'PRIOR'})
        opened('nextpay')
        before=marker(w,e)['last_update_id']
        sql(w,e,'UPDATE users SET isAdmin=0 WHERE userid=9002')
        denied(saved(SECRET,cached_admin=1))
        assert state(w,e)['nextpay']=='PRIOR' and marker(w,e)['last_update_id']==before
        assert step(w,e).startswith('achbotPaymentKey|nextpay|')
        checks.append('revocation_during_wizard_prevents_write')
        # Valid private source may not be replaced by forged webhook, group,
        # modified text, sender or stale update id.
        reset({'nextpay':'PRIOR'})
        opened('nextpay')
        preview=marker(w,e)['last_update_id']
        for attack in ('token','group','sender','text'):
            denied(saved(SECRET,invalid=attack))
            assert state(w,e)['nextpay']=='PRIOR' and marker(w,e)['last_update_id']==preview
        checks.append('forged_private_message_token_group_sender_text_denied')
        stale=invoke(w,e,new,kind='message',text=SECRET,seq=preview)
        denied(stale)
        assert state(w,e)['nextpay']=='PRIOR'
        checks.append('stale_message_source_marker_denied')
        # Signed cancel consumes only the active wizard and has no PAYMENT_KEYS
        # side effect, even after an unrelated admin has edited a different key.
        cancelled=saved('Cancel')
        assert step(w,e)=='none' and state(w,e)['nextpay']=='PRIOR'
        assert not any(x[1]=='SAVED' for x in cancelled['events'] if x[0]=='send')
        checks.append('signed_cancel_preserves_existing_values')
        # Two administrators start from the same document, then complete
        # independent fields in the reverse order on separate PHP/MySQLi
        # sessions. Both values survive, without a stale whole-document write.
        reset({'nowpayment':'INIT','nextpay':'INIT'})
        opened('nowpayment',actor=9002)
        opened('nextpay',actor=9003)
        done=saved('NEXT_LATEST',actor=9003)
        assert state(w,e)['nextpay']=='NEXT_LATEST',done
        done=saved('NOW_LATEST',actor=9002)
        assert state(w,e)['nowpayment']=='NOW_LATEST',done
        assert state(w,e)['nextpay']=='NEXT_LATEST'
        checks.append('two_admins_independent_fields_both_persist')
        # Same-field conflict: no old editor can overwrite a newer merchant
        # decision even when its message has a higher Telegram update_id.
        reset({'nowpayment':'INIT'})
        opened('nowpayment',actor=9002)
        opened('nowpayment',actor=9003)
        saved('B_WINNER',actor=9003)
        latest=marker(w,e)['last_update_id']
        rejected=saved('A_STALE',actor=9002)
        denied(rejected)
        assert state(w,e)['nowpayment']=='B_WINNER'
        assert marker(w,e)['last_update_id']==latest
        assert step(w,e,9002).startswith('achbotPaymentKey|nowpayment|')
        checks.append('two_admins_same_field_stale_baseline_denied')
        # Stronger than value-only CAS: B changes X->Y->X after A opened,
        # then A's old step must still be refused (ABA).
        reset({'nowpayment':'X'})
        opened('nowpayment',actor=9002)
        oldA=step(w,e,9002)
        opened('nowpayment',actor=9003)
        saved('Y',actor=9003)
        opened('nowpayment',actor=9003)
        saved('X',actor=9003)
        assert state(w,e)['nowpayment']=='X'
        currentRevision=marker(w,e)['field_versions']['nowpayment']
        denied(saved('STALE_AFTER_ABA',actor=9002))
        assert state(w,e)['nowpayment']=='X'
        assert marker(w,e)['field_versions']['nowpayment']==currentRevision
        assert step(w,e,9002)==oldA
        checks.append('same_field_aba_reversion_does_not_reopen_stale_editor')
        # HMAC baseline is not the public SHA256 of low-entropy
        # account/holder fields; neither plaintext nor legacy digest is
        # stored as a user step.
        reset({'bankAccount':ADDRESS})
        opened('bankAccount')
        storedStep=step(w,e)
        import hashlib
        raw=json.dumps([True,ADDRESS],ensure_ascii=False,separators=(',',':'))
        assert hashlib.sha256(raw.encode()).hexdigest() not in storedStep
        assert ADDRESS not in storedStep
        saved('NEW_PUBLIC_BANK')
        assert state(w,e)['bankAccount']=='NEW_PUBLIC_BANK'
        opened('holderName')
        saved('New public holder')
        assert state(w,e)['holderName']=='New public holder'
        checks.append('public_bank_fields_editable_hmac_baseline_nonreversible')
        # Deliberately invalid callback cannot select an arbitrary setting
        # or be mistaken for a successful wizard start.
        reset()
        for invalid in ('changePaymentKeysweSwap',
                        'changePaymentKeysnowpayment_extra',
                        'changePaymentKeys'):
            denied(call(data=invalid))
            assert state(w,e)=={} and marker(w,e) is None
        checks.append('malformed_and_retired_payment_keys_cannot_be_edited')
        # Signed private callback must be intact; newly forged callbacks
        # cannot create an owned step even when cached role is admin.
        for failure in ('token','group','sender','message'):
            denied(call(data='changePaymentKeysnowpayment',invalid=failure))
            assert step(w,e)=='none' and marker(w,e) is None
        checks.append('unverified_admin_key_callback_never_opens_step')
        # The inherited pre-remediation cached step is never accepted as an
        # active privileged wizard, including if the actor is still admin.
        reset()
        sql(w,e,"UPDATE users SET step='changePaymentKeysnowpayment' WHERE userid=9002")
        denied(saved(SECRET))
        assert state(w,e)=={} and marker(w,e) is None
        checks.append('legacy_unbound_payment_key_step_denied')
        # The historical setting.type lacks a unique constraint; duplicates
        # and malformed JSON must fail closed instead of updating both rows.
        reset()
        sql(w,e,"INSERT INTO setting(type,value) VALUES ('PAYMENT_KEYS','{}')")
        denied(call(data='changePaymentKeysnowpayment'))
        assert marker(w,e) is None and step(w,e)=='none'
        checks.append('ambiguous_payment_key_setting_type_denied')
        reset()
        sql(w,e,"UPDATE setting SET value='not-json' WHERE type='PAYMENT_KEYS'")
        denied(call(data='changePaymentKeysnowpayment'))
        assert marker(w,e) is None
        checks.append('malformed_payment_key_document_denied')
        reset()
        sql(w,e,"INSERT INTO users(userid,name,username,refcode,date,isAdmin) "
            "VALUES (9002,'Duplicate','unsafe','0','1700000000',1)")
        denied(call(data='changePaymentKeysnowpayment'))
        assert marker(w,e) is None
        sql(w,e,"DELETE FROM users WHERE username='unsafe'")
        checks.append('duplicate_administrator_userid_denied')
        # Inject an actual MariaDB error at the last payment document UPDATE:
        # the source marker and users.step remain atomic.
        reset({'nowpayment':'PRIOR'})
        opened('nowpayment')
        source=marker(w,e)['last_update_id']
        beforeStep=step(w,e)
        sql(w,e,"CREATE TRIGGER synthetic_payment_key_failed_write "
            "BEFORE UPDATE ON setting FOR EACH ROW "
            "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic abort'")
        denied(saved('AFTER_FAILURE'))
        assert state(w,e)['nowpayment']=='PRIOR' and step(w,e)==beforeStep
        assert marker(w,e)['last_update_id']==source
        checks.append('sql_write_failure_rollback_no_false_success')
        sql(w,e,'DROP TRIGGER synthetic_payment_key_failed_write')
        success=saved('AFTER_FAILURE')
        assert state(w,e)['nowpayment']=='AFTER_FAILURE'
        assert any(x[0]=='send' and x[1]=='SAVED' for x in success['events'])
        checks.append('retriable_authentic_save_after_sql_rollback')
        # Gift-card URL, chain-address and token-rate validators preserve
        # their old behavior and support an explicit /clear where accepted.
        reset()
        opened('giftCardUrl')
        invalidGift=saved('http://invalid.local/')
        denied(invalidGift)
        assert any(event[0]=='send' and 'HTTPS' in event[1]
                   for event in invalidGift['events'])
        assert step(w,e).startswith('achbotPaymentKey|giftCardUrl|')
        saved('https://gift.example.com/shop')
        assert state(w,e)['giftCardUrl']=='https://gift.example.com/shop'
        checks.append('gift_card_legacy_https_validation')
        opened('manualTrxRateToman')
        invalidRate=saved('0')
        denied(invalidRate)
        assert any(event[0]=='send' and 'نرخ معتبر' in event[1]
                   for event in invalidRate['events'])
        saved('123456')
        assert state(w,e)['manualTrxRateToman']=='123456'
        opened('manualTrxRateToman')
        saved('/clear')
        assert state(w,e)['manualTrxRateToman']==''
        checks.append('manual_trx_rate_positive_and_clear')
        opened('manualUsdtRateToman')
        denied(saved('12'))
        saved('23456')
        assert state(w,e)['manualUsdtRateToman']=='23456'
        checks.append('manual_usdt_rate_positive_range')
        # Four manual-chain destinations retain exactly their original
        # validators. Public wallet addresses, unlike merchant credentials,
        # are shown in operator menus and remain editable.
        evm='0x'+'12'*20
        tron='TNPeeaaFB7K9cmo4uQpcU32zGK8G1NYqeL'
        trx='TJRabPrwbZy45sbavfcjinPJC18kjpRTv8'
        for key,value in (
            ('manualUsdtWalletBEP20',evm),
            ('manualUsdtWalletERC20',evm),
            ('manualUsdtWalletTRC20',tron),
            ('manualTrxWallet',trx)):
            opened(key)
            denied(saved('invalid-chain-address'))
            assert step(w,e).startswith('achbotPaymentKey|'+key+'|')
            result=saved(value)
            assert state(w,e)[key]==value,result
            opened(key)
            cleared=saved('/clear')
            assert state(w,e)[key]=='' and any(
                event[0]=='send' and event[1]=='SAVED' for event in cleared['events'])
            checks.append('chain_address_'+key+'_validated_and_clear')
        reset()
        # First write can create PAYMENT_KEYS if it never existed.
        sql(w,e,"DELETE FROM setting WHERE type='PAYMENT_KEYS'")
        opened('nowpayment')
        saved('FIRST_SECRET')
        assert sql(w,e,"SELECT COUNT(*) FROM setting WHERE type='PAYMENT_KEYS'")=='1'
        assert state(w,e)['nowpayment']=='FIRST_SECRET'
        checks.append('missing_payment_keys_row_created_once_under_lock')
        # Duplicate source watermark must never permit a credential write.
        reset()
        sql(w,e,"INSERT INTO setting(type,value) VALUES "
            "('ACHBOT_ADMIN_PAYMENT_KEYS_SOURCE','{}'),"
            "('ACHBOT_ADMIN_PAYMENT_KEYS_SOURCE','{}')")
        denied(call(data='changePaymentKeysnowpayment'))
        assert step(w,e)=='none' and state(w,e)=={}
        checks.append('duplicate_admin_source_marker_fails_closed')
        # Both isolation levels preserve current committed values and
        # separate-field writes; a stale same-field step cannot win.
        for isolation in ('READ-COMMITTED','REPEATABLE-READ'):
            reset({'nowpayment':'BEGIN','zarinpal':'BEGIN'})
            call(data='changePaymentKeysnowpayment',isolation=isolation)
            call(data='changePaymentKeyszarinpal',actor=9003,isolation=isolation)
            saved('Z_DONE',actor=9003,isolation=isolation)
            saved('NOW_DONE',isolation=isolation)
            assert state(w,e)['nowpayment']=='NOW_DONE'
            assert state(w,e)['zarinpal']=='Z_DONE'
            checks.append('independent_writers_'+isolation.replace('-','_').lower()+'_preserve_fields')
        # Explicit owner-only menu source can still see the full public
        # account and network destinations without exposing merchant keys.
        reset({'bankAccount':ADDRESS,'holderName':'Example holder',
               'manualUsdtWalletTRC20':tron,'manualTrxWallet':trx,
               'nowpayment':SECRET})
        menu=invoke(w,e,new,kind='menu')
        assert tron in str(menu) and trx in str(menu)
        assert SECRET not in str(menu)
        checks.append('operator_crypto_destination_visible_merchant_secret_hidden')
        # Key preservation: public destinations/holder/rates remain visible
        # while all three hosted merchant secret labels are safely statuses.
        current=state(w,e)
        current.update({'nowpayment':SECRET,'zarinpal':'Z_SYNTHETIC',
                        'nextpay':'N_SYNTHETIC','bankAccount':ADDRESS,
                        'holderName':'Test public holder'})
        store(w,e,current)
        menu=invoke(w,e,new,kind='menu')
        assert all(key not in str(menu) for key in (SECRET,'Z_SYNTHETIC','N_SYNTHETIC'))
        assert ADDRESS in str(menu) and 'Test public holder' in str(menu)
        assert 'changePaymentKeysnowpayment' in str(menu)
        checks.append('hosted_secret_labels_masked_public_fields_preserved')
        log=(w/'synthetic-key-admin.log').read_text()
        for value in (SECRET,'NOW_LATEST','B_WINNER','A_STALE','NEXT_LATEST'):
            assert value not in log,'credential leaked to diagnostic log'
        checks.append('operational_log_excludes_merchant_values')
    result={'complete':True,'cases':len(checks),'scenarios':checks,
            'source_real':True,'original_schema_mariadb':True,
            'baseline_red':True,'current_source_green':True,
            'two_authenticated_admins':True,
            'no_real_provider_telegram_or_money':True}
    if args.report:args.report.write_text(json.dumps(result,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps(result,ensure_ascii=False))
if __name__=='__main__':main()
