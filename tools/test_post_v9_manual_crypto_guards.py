#!/usr/bin/env python3
"""Current-runtime cryptographic destination and retired-admin-callback checks.

Use authoritative TRON test vectors plus an independent Python big-integer
Base58Check oracle. Run the exact current bot.php administrator handler and
config.php setting writers on an owned socket-only original-schema MariaDB.

No live wallets, chain calls, customer identities, installed bot, or money.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import re
import subprocess
from pathlib import Path

import test_access_state as owned
import test_v6_native as native

ROOT = Path(__file__).resolve().parents[1]
DB = owned.NAMES[1]
BASE58 = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz'

# Official TRON address/hex examples:
# https://developers.tron.network/docs/account
# https://developers.tron.network/docs/encoding
OFFICIAL = {
    'TNPeeaaFB7K9cmo4uQpcU32zGK8G1NYqeL':
        '418840E6C55B9ADA326D211D818C34A994AECED808',
    'TJRabPrwbZy45sbavfcjinPJC18kjpRTv8':
        '415CBDD86A2FA8DC4BDDD8A8F69DBA48572EEC07FB',
    'TXT7aiRQCLiMgsMZgFceLYbQu3MynssYUK':
        '41EBA1C3A4A5E7A26ACAB1E10CD6E6C6E2DB6EE9C2',
}
USDT_TRC20 = 'TNPeeaaFB7K9cmo4uQpcU32zGK8G1NYqeL'
TRX_TRON = 'TJRabPrwbZy45sbavfcjinPJC18kjpRTv8'


def encode_check(payload: bytes) -> str:
    """Independent Python Base58Check fixture construction, never deployed."""
    raw = payload + hashlib.sha256(hashlib.sha256(payload).digest()).digest()[:4]
    n = int.from_bytes(raw, 'big')
    encoded = ''
    while n:
        n, mod = divmod(n, 58)
        encoded = BASE58[mod] + encoded
    return '1' * (len(raw) - len(raw.lstrip(b'\0'))) + encoded


def decode_check(encoded: str) -> bytes | None:
    n = 0
    for c in encoded:
        if c not in BASE58:
            return None
        n = n * 58 + BASE58.index(c)
    data = b'\0' * (len(encoded) - len(encoded.lstrip('1')))
    if n:
        data += n.to_bytes((n.bit_length()+7)//8, 'big')
    if len(data) != 25:
        return None
    payload, given = data[:21], data[21:]
    expected = hashlib.sha256(hashlib.sha256(payload).digest()).digest()[:4]
    return payload if given == expected and encode_check(payload) == encoded else None


PHP_ADDRESS = r"""<?php
error_reporting(E_ALL);
set_error_handler(static function($level,$message){
    throw new ErrorException($message,0,$level);
});
$_SERVER['SCRIPT_FILENAME']=__FILE__;
require $argv[1].'/src/Finance/ReceiptHelpers.php';
$values=json_decode($argv[2],true,512,JSON_THROW_ON_ERROR);
$out=[];
foreach ($values as $key=>$value) {
    $out[$key]=[
        'tron'=>achbotManualTronAddressValid($value),
        'trc20'=>achbotManualUsdtWalletValid($value,'TRC20'),
        'bep20'=>achbotManualUsdtWalletValid($value,'BEP20')
    ];
}
echo json_encode($out,JSON_THROW_ON_ERROR);
"""

PHP_ADMIN = r"""<?php
error_reporting(E_ALL);
set_error_handler(static function($level,$message){
    if ($level === E_DEPRECATED) return true;
    throw new ErrorException($message,0,$level);
});
$_SERVER['SCRIPT_FILENAME']=__FILE__;
$_SERVER['REQUEST_METHOD']='POST';
$_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']='SafeSyntheticGatewayToken_2026';
putenv('ACHBOT_TELEGRAM_WEBHOOK_SECRET=SafeSyntheticGatewayToken_2026');
$root=$argv[1];
$spec=json_decode($argv[4],true,512,JSON_THROW_ON_ERROR);
$connection=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$connection->set_charset('utf8mb4');
$from_id=(int)($spec['actor']??9001);
$admin=9001;$chat_id=$from_id;
$sender=(object)['id'=>$from_id,'is_bot'=>false];
$chat=(object)['id'=>$from_id,'type'=>'private'];
$sourceId=(int)($spec['update_id']??1);
if (($spec['source_kind']??'callback')==='message') {
    $update=(object)['update_id'=>$sourceId,
        'message'=>(object)['message_id'=>600,'from'=>$sender,
            'chat'=>$chat,'text'=>(string)($spec['text']??'')]];
} else {
    $update=(object)['update_id'=>$sourceId,
        'callback_query'=>(object)['id'=>'admin-test-callback',
            'from'=>$sender,
            'message'=>(object)['message_id'=>600,'chat'=>$chat],
            'data'=>(string)($spec['data']??'')]];
}
$callbackId='fixture-callback';
$message_id=600;
$data=(string)($spec['data']??'');
$text=(string)($spec['text']??'');
$removeKeyboard='{}';
$cancelKey='{}';
$buttonValues=['cancel'=>'لغو'];
$mainValues=['change_bot_settings_message'=>'Fixture bot settings',
             'saved_successfuly'=>'Fixture saved'];
$username='fixture';
$first_name='Fixture';
$stmt=$connection->prepare('SELECT * FROM users WHERE userid=? LIMIT 1');
$stmt->bind_param('i',$from_id);
$stmt->execute();
$userInfo=$stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$userInfo) throw new RuntimeException('Unknown test actor');
$stmt=$connection->prepare("SELECT value FROM setting WHERE type='BOT_STATES' LIMIT 1");
$stmt->execute();
$row=$stmt->get_result()->fetch_assoc();
$stmt->close();
$botState=json_decode((string)($row['value']??'{}'),true,512,JSON_THROW_ON_ERROR);
$GLOBALS['events']=[];
function answerCallbackQuery(...$args) {$GLOBALS['events'][]=['answerCallbackQuery',$args];}
function editText(...$args) {$GLOBALS['events'][]=['editText',$args];}
function delMessage(...$args) {$GLOBALS['events'][]=['delMessage',$args];}
function sendMessage(...$args) {$GLOBALS['events'][]=['sendMessage',$args];}
function getBotSettingKeys() {return '{}';}
function getGateWaysKeys() {return '{}';}
require $root.'/src/Finance/PaymentLifecycle.php';
require $root.'/src/Subscription/DirectGift.php';
require $root.'/src/Finance/GatewayRuleAdmin.php';
require $root.'/src/Finance/PaymentKeyAdmin.php';
require $argv[7]; // Actual bot.php ingress-auth function, source-extracted.
require $argv[5]; // Actual config.php writers, source-extracted.
if (isset($spec['writer_key'])) {
    // Explicitly exercise the final source persistence boundary too, not a
    // fake setSettings() stand-in or merely the two bot.php callback routes.
    setSettings((string)$spec['writer_key'], 'on');
} else {
    require $root.'/src/Finance/ReceiptHelpers.php';
    require $argv[6]; // Byte-extracted current bot.php admin handlers.
}
echo json_encode(['events'=>$GLOBALS['events']],JSON_THROW_ON_ERROR |
                 JSON_UNESCAPED_UNICODE);
"""


def php(work: Path, env: dict, script: Path, args: list[str]) -> object:
    cmd=['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
         '-d','extension=tokenizer','-d','log_errors=1',
         '-d',f'error_log={work}/admin-fixture.log','-d','allow_url_fopen=0',
         '-d','disable_functions=curl_init,curl_exec,fsockopen,pfsockopen,'
             'stream_socket_client,shell_exec,exec,system,passthru,popen,proc_open,mail,dl',
         str(script),*args]
    result=subprocess.run(cmd,cwd=work,env=env,capture_output=True,
                          text=True,timeout=20)
    if result.returncode or result.stderr:
        raise RuntimeError('Isolated current PHP admin guard failed: '+
                           repr((result.returncode,result.stderr[-1300:],result.stdout[-1000:])))
    try:
        return json.loads(result.stdout)
    except ValueError as exc:
        raise RuntimeError('Invalid current handler response: '+
                           repr(result.stdout[-500:])) from exc


def sql(work:Path, env:dict, statement:str) -> str:
    return native.sql(work,env,statement,DB)


def check() -> dict:
    cases=[]
    for address,expected_hex in OFFICIAL.items():
        assert decode_check(address) == bytes.fromhex(expected_hex)
        assert encode_check(bytes.fromhex(expected_hex)) == address
        cases.append('official_base58check_hex_pair_'+address[:8])

    corrupted=USDT_TRC20[:-1]+('1' if USDT_TRC20[-1]!='1' else '2')
    wrong_prefix=encode_check(b'\x42'+bytes.fromhex(OFFICIAL[USDT_TRC20])[1:])
    bad_length=encode_check(b'\x41'+bytes(range(19)))
    tests={
        'documented_trc20':(USDT_TRC20,True),
        'documented_native_trx':(TRX_TRON,True),
        'third_documented_address':(next(reversed(OFFICIAL)),True),
        'invalid_checksum_single_character':(corrupted,False),
        'invalid_network_prefix_checksummed':(wrong_prefix,False),
        'invalid_decoded_length_checksummed':(bad_length,False),
        'invalid_base58_character_zero':(USDT_TRC20[:7]+'0'+USDT_TRC20[8:],False),
        'invalid_base58_character_capital_o':(USDT_TRC20[:7]+'O'+USDT_TRC20[8:],False),
        'invalid_base58_character_lower_l':(USDT_TRC20[:7]+'l'+USDT_TRC20[8:],False),
        'noncanonical_leading_zero':('1'+USDT_TRC20,False),
        'noncanonical_whitespace':(' '+USDT_TRC20,False),
        'shape_only_old_fixture':('T'+'A'*33,False),
        'another_shape_only_old_fixture':('T'+'B'*33,False),
        'too_large_base58_integer':('T'+'z'*33,False),
    }
    for index in range(32):
        payload=b'\x41'+hashlib.sha256(
            ('manual-tron-offline-vector-'+str(index)).encode()).digest()[:20]
        wallet=encode_check(payload)
        assert wallet.startswith('T') and len(wallet)==34
        tests['diverse_valid_checksum_'+str(index)]=(wallet,True)
        invalid=wallet[:-1]+('1' if wallet[-1]!='1' else '2')
        tests['diverse_corrupted_checksum_'+str(index)]=(invalid,False)
    for key,(wallet,expected) in tests.items():
        decoded=decode_check(wallet)
        assert ((decoded is not None and decoded[0] == 0x41) is expected),key
    with owned.owned_database() as (work,env):
        # Keep actual current handler boundaries rather than inventing a
        # duplicate implementation of bot.php or config.php in the fixture.
        route=(ROOT/'bot.php').read_text()
        begin=route.index('if(($data=="botSettings"')
        end=route.index('if($data=="changeConfigRemarkType")',begin)
        editor_begin=route.index('// Privileged PAYMENT_KEYS editor:',end)
        editor_end=route.index("require __DIR__ . '/src/Agent/Discounts.php';",editor_begin)
        callback_source=route[begin:end]
        editor_source=route[editor_begin:editor_end]
        assert 'setSettings(' in callback_source
        assert 'achbotAdminPaymentKeyChange(' in editor_source
        # Address validation moved into the actual source-loaded editor
        # instead of duplicating the legacy inline persistence shortcut.
        key_helper=(ROOT/'src/Finance/PaymentKeyAdmin.php').read_text()
        assert 'achbotManualTronAddressValid(' in key_helper
        assert 'achbotManualUsdtWalletValid(' in key_helper
        (work/'admin-callbacks.php').write_text('<?php\n'+callback_source+
                                                 '\n'+editor_source)
        (work/'admin-driver.php').write_text(PHP_ADMIN)
        (work/'address-driver.php').write_text(PHP_ADDRESS)
        extractor=('require $argv[1]; echo "<?php\\n",'
                   'achbotExtractFunction($argv[2],"achbotMutateBotStates"),"\\n",'
                   'achbotExtractFunction($argv[2],"achbotAdminGatewayChange"),"\\n",'
                   'achbotExtractFunction($argv[2],"achbotPaymentPolicyFields"),"\\n",'
                   'achbotExtractFunction($argv[2],"setSettings"),"\\n",'
                   'achbotExtractFunction($argv[2],"setUser"),"\\n";')
        extracted=subprocess.run(['php','-n','-d','extension=tokenizer','-r',
             extractor,str(ROOT/'tools/source_scan.php'),str(ROOT/'config.php')],
             cwd=work,env=env,capture_output=True,text=True,timeout=15,check=True)
        (work/'actual-writers.php').write_text(extracted.stdout)
        ingress=subprocess.run(['php','-n','-d','extension=tokenizer','-r',
              'require $argv[1];echo "<?php\\n",achbotExtractFunction($argv[2],"achbotWebhookIngressAuthenticated");',
              str(ROOT/'tools/source_scan.php'),str(ROOT/'bot.php')],
              cwd=work,env=env,capture_output=True,text=True,timeout=15,check=True)
        (work/'actual-ingress.php').write_text(ingress.stdout)
        values={key:address for key,(address,_) in tests.items()}
        actual=php(work,env,work/'address-driver.php',
                   [str(ROOT),json.dumps(values) ])
        for key,(address,expected) in tests.items():
            assert actual[key]['tron'] is expected,(key,address,actual[key])
            assert actual[key]['trc20'] is expected,(key,address,actual[key])
            assert actual[key]['bep20'] is False,(key,address,actual[key])
            cases.append('offline_canonical_tron_'+key)
        # EVM destinations preserve their distinct 0x/20-byte contract.
        assert php(work,env,work/'address-driver.php',
                   [str(ROOT),json.dumps({'evm':'0x'+'1'*40})])['evm']['bep20'] is True
        cases.append('unrelated_evm_validation_preserved')

        toggles={
            'weSwapState':'on', 'tronWallet':'on',
            'agencyState':'off','cartToCartAutoAcceptType':'0',
            'manualUsdtState':'off','nextpay':'off','zarinpal':'off',
            'nowPaymentWallet':'off','nowPaymentOther':'off',
        }
        sql(work,env,"INSERT INTO setting (type,value) VALUES ('BOT_STATES','"+
            json.dumps(toggles,separators=(',',':'))+"')")
        settings={'manualUsdtWalletTRC20':USDT_TRC20,
                  'manualTrxWallet':TRX_TRON,
                  'manualUsdtRateToman':'100000',
                  'manualTrxRateToman':'15000'}
        sql(work,env,"INSERT INTO setting (type,value) VALUES ('PAYMENT_KEYS','"+
            json.dumps(settings,separators=(',',':'))+"')")

        def state_raw() -> str:
            return sql(work,env,"SELECT value FROM setting WHERE type='BOT_STATES' LIMIT 1")
        def payment_raw() -> str:
            return sql(work,env,"SELECT value FROM setting WHERE type='PAYMENT_KEYS' LIMIT 1")
        def protected_snapshot() -> tuple[str,str,str]:
            return (sql(work,env,'SELECT userid,wallet FROM users ORDER BY userid'),
                    sql(work,env,'SELECT id,user_id,state,price FROM pays ORDER BY id'),
                    sql(work,env,'SELECT * FROM orders_list ORDER BY id'))
        sequence=[100]
        def run(actor:int=9001,data:str='',text:str='') -> list:
            sequence[0]+=1
            return php(work,env,work/'admin-driver.php',[
                str(ROOT),str(work/'mysql.sock'),DB,
                json.dumps({'actor':actor,'data':data,'text':text,
                            'source_kind':'message' if text else 'callback',
                            'update_id':sequence[0]}),
                str(work/'actual-writers.php'),str(work/'admin-callbacks.php'),
                str(work/'actual-ingress.php')
            ])['events']

        baseline_finance=protected_snapshot()
        for retired_key in ('weSwapState','tronWallet'):
            old=state_raw()
            php(work,env,work/'admin-driver.php',[
                str(ROOT),str(work/'mysql.sock'),DB,
                json.dumps({'actor':9001,'writer_key':retired_key}),
                str(work/'actual-writers.php'),str(work/'admin-callbacks.php'),
                str(work/'actual-ingress.php')
            ])
            assert state_raw()==old and protected_snapshot()==baseline_finance
            cases.append('final_persistence_denies_retired_key_'+retired_key)
        retired=[
            'changeGateWaysweSwapState','changeGateWaystronWallet',
            'changeBotweSwapState','changeBottronWallet',
            'changeBotnotASetting','changeGateWaysnotASetting',
            'changeBotnextpay','changeGateWaysagencyState',
            'changeBotagencyState_suffix','changeGateWaysmanualUsdtState_suffix',
        ]
        for callback in retired:
            old=state_raw()
            events=run(data=callback)
            assert state_raw()==old,(callback,'mutated retired or cross-surface flag')
            assert protected_snapshot()==baseline_finance
            if '_suffix' not in callback:
                assert any(e[0]=='answerCallbackQuery' for e in events),callback
            cases.append('stale_admin_callback_denied_'+callback)
        for callback in ('botSettings','gateWays_Channels'):
            old=state_raw()
            assert any(e[0]=='editText' for e in run(data=callback))
            assert state_raw()==old
            cases.append('current_admin_menu_read_only_'+callback)

        config_source=(ROOT/'config.php').read_text()
        advertised={
            'changeBot':set(re.findall(r"'callback_data'\s*=>\s*[\"']changeBot([A-Za-z0-9_]+)[\"']",config_source)),
            'changeGateWays':set(re.findall(r"'callback_data'\s*=>\s*[\"']changeGateWays([A-Za-z0-9_]+)[\"']",config_source))
        }
        # Detect drift in the actual active administrator keyboard and ensure
        # every advertised toggle remains editable after the allowlist fix.
        assert len(advertised['changeBot'])>=25,advertised['changeBot']
        assert len(advertised['changeGateWays'])>=8,advertised['changeGateWays']
        assert not {'weSwapState','tronWallet'}.intersection(
            advertised['changeBot']|advertised['changeGateWays'])
        for surface,keys in advertised.items():
            for key in sorted(keys):
                callback=surface+key
                old=json.loads(state_raw())
                events=run(data=callback)
                new=json.loads(state_raw())
                expected='1' if key=='cartToCartAutoAcceptType' and old.get(key)=='0' else (
                    'off' if old.get(key)=='on' else 'on')
                assert new.get(key)==expected,(callback,new.get(key),expected)
                assert {k:v for k,v in new.items() if k!=key} == {
                    k:v for k,v in old.items() if k!=key},callback
                assert any(e[0]=='editText' for e in events),callback
                assert protected_snapshot()==baseline_finance
                cases.append('advertised_current_admin_toggle_works_'+callback)

        # No alternate operator identity may bypass the allowlists or alter
        # current toggles. A delegated administrator retains current privileges.
        old=state_raw()
        assert run(actor=1001,data='changeGateWaysmanualUsdtState')==[]
        assert run(actor=1001,data='changeBotagencyState')==[]
        assert state_raw()==old
        cases.append('non_admin_cannot_change_current_payment_or_bot_flags')
        events=run(actor=9002,data='changeGateWaysmanualUsdtState')
        assert any(e[0]=='editText' for e in events)
        assert state_raw()!=old
        cases.append('delegated_admin_current_gateway_toggle_still_works')

        # Run the actual admin payment-key editor using the persisted user
        # stage. Corrupt addresses must not overwrite old valid destinations.
        for key,address in [('manualUsdtWalletTRC20',USDT_TRC20),
                            ('manualTrxWallet',TRX_TRON)]:
            # Start with the exact source-authenticated current callback;
            # only a newer genuine private message may finish its step.
            start=run(data='changePaymentKeys'+key)
            assert any(e[0]=='sendMessage' for e in start)
            currentStep=sql(work,env,"SELECT step FROM users WHERE userid=9001")
            assert currentStep.startswith('achbotPaymentKey|'+key+'|'),(key,currentStep)
            old=payment_raw()
            invalid=address[:-1]+('1' if address[-1]!='1' else '2')
            events=run(text=invalid)
            assert payment_raw()==old,(key,'invalid address persisted')
            assert any(e[0]=='sendMessage' and '⚠️' in str(e[1]) for e in events),key
            assert sql(work,env,"SELECT step FROM users WHERE userid=9001")==currentStep
            assert protected_snapshot()==baseline_finance
            cases.append('actual_editor_rejects_checksum_corruption_'+key)

            # Accept a different officially documented valid destination.
            replacement=TRX_TRON if address==USDT_TRC20 else USDT_TRC20
            events=run(text=replacement)
            assert json.loads(payment_raw())[key]==replacement,key
            assert any(e[0]=='sendMessage' and 'Fixture saved' in str(e[1])
                       for e in events)
            final_step=sql(work,env,"SELECT step FROM users WHERE userid=9001")
            assert final_step.strip()=='none',(key,repr(final_step),events)
            assert protected_snapshot()==baseline_finance
            cases.append('actual_editor_accepts_checksums_'+key)
        cases.append('no_financial_effects_from_admin_settings_or_editors')

    return {
        'complete':True, 'cases':len(cases), 'scenarios':cases,
        'official_tron_test_vectors_verified':True,
        'independent_base58check_oracle':True,
        'real_bot_admin_callbacks_executed':True,
        'real_config_setting_writers_extracted':True,
        'actual_payment_editor_executed':True,
        'retired_flags_preserved_not_mutated':True,
        'retired_keys_denied_at_persistence_boundary':True,
        'all_advertised_current_admin_toggles_work':True,
        'schema_tables':23,
        'isolated_socket_only':True,
        'live_provider_or_chain_calls':0,
        'production_data_modified':False
    }


def main() -> None:
    parser=argparse.ArgumentParser()
    parser.add_argument('--run-disposable',action='store_true',required=True)
    parser.add_argument('--report',type=Path)
    args=parser.parse_args()
    result=check()
    if args.report:
        args.report.write_text(json.dumps(result,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps({'complete':result['complete'],'cases':result['cases'],
        'source_real':result['real_bot_admin_callbacks_executed']},ensure_ascii=False))


if __name__=='__main__':
    main()
