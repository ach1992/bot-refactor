#!/usr/bin/env python3
"""Three distinct manual payment methods on owned socket-only MariaDB.

No provider, real customer, live gateway, bot host, or external HTTP access.
Source is exercised by real PHP against the existing additive shared ledger.
"""
from __future__ import annotations
import argparse
import json
import subprocess
from pathlib import Path

import test_access_state as owned
import test_v6_native as native
import test_v8_native as purchase

ROOT=Path(__file__).resolve().parents[1]
DB=purchase.SIDE[1]
PHP=r"""<?php
error_reporting(E_ALL);
set_error_handler(static function($severity,$msg){throw new ErrorException($msg,0,$severity);});
$_SERVER['SCRIPT_FILENAME'] = __FILE__;
$root=$argv[1];$socket=$argv[2];$hash=$argv[3];
$spec=json_decode($argv[4],true,512,JSON_THROW_ON_ERROR);
$connection=new mysqli('localhost','root','', $argv[5],0,$socket);
$connection->set_charset('utf8mb4');
$botState=$spec['states']??['cartToCartState'=>'on','giftCardState'=>'on','manualUsdtState'=>'on'];
$paymentKeys=$spec['keys']??[
    'bankAccount'=>'6037999999999999','holderName'=>'Fixture',
    'giftCardUrl'=>'https://example.org/gift-card',
    'manualUsdtWallet'=>'0x1111111111111111111111111111111111111111',
    'manualUsdtNetwork'=>'BEP20','manualUsdtRateToman'=>'100000',
];
$USDTPrice='100000';$USDTPriceagent='110000';
require_once $root.'/src/Finance/ReceiptHelpers.php';
function achbotSupportsPaymentSubject(...$args) {return true;}
function sendMessage(...$args) {return (object)['ok'=>true];}
function editKeys(...$args) {}

require $root.'/settings/values.php';
$stmt=$connection->prepare('SELECT * FROM pays WHERE hash_id=? LIMIT 1');
$stmt->bind_param('s',$hash);$stmt->execute();
$payInfo=$stmt->get_result()->fetch_assoc();$stmt->close();
if (!$payInfo) throw new RuntimeException('synthetic payment missing');
$method=$spec['method']??'bank_transfer';$user=(int)($spec['user']??1001);
$op=$spec['op']??'claim';
if ($op==='claim') $value=achbotManualClaim($connection,$payInfo,$method,$user,
    $spec['network']??null);
elseif ($op==='allow') $value=achbotManualReceiptAllowed($connection,$payInfo,$method,$user);
elseif ($op==='begin') $value=achbotManualBeginEvidence($connection,$payInfo,$method,$user,
    (string)($spec['photo_id']??'fixture-photo-id'));
elseif ($op==='approve') $value=achbotApproveManualWalletTopup(
    $connection,$hash,(int)($spec['actor']??9001));
elseif ($op==='bank_auto_claim') $value=achbotAutoBankClaim($connection,$payInfo);
elseif ($op==='bank_auto_eligible') $value=achbotAutoBankEligible($connection,$payInfo);
elseif ($op==='finish') $value=achbotManualFinishEvidence($connection,$hash,
    (string)($spec['photo_id']??'fixture-photo-id'),(string)($spec['token']??''),222,9001);
elseif ($op==='service_guard') {
    $connection->begin_transaction();
    $stmt=$connection->prepare('SELECT * FROM pays WHERE hash_id=? FOR UPDATE');
    $stmt->bind_param('s',$hash);$stmt->execute();
    $locked=$stmt->get_result()->fetch_assoc();$stmt->close();
    $value=achbotManualServiceApprovalAllowedLocked($connection,$locked,
        (string)($spec['subject']??'custom'));
    $connection->rollback();
}
elseif ($op==='crypto_options') $value=achbotManualCryptoOptions();
elseif ($op==='crypto_enabled') $value=achbotManualCryptoEnabled();
elseif ($op==='wallet') {
    $from_id=$user;$admin=9001;
    function alert(...$args) {}
    require_once $root.'/src/Finance/WalletSettlement.php';
    $value=processWalletPayment($connection,$hash,static function(){return true;});
}
elseif ($op==='read') {
    $selected=achbotManualSelectedMethod($connection,$payInfo);
    $message=buildCartToCartInfoMessage($paymentKeys,$mainValues,
        (int)$payInfo['price'],$user,$method,$hash);
    $prefix=$method==='manual_usdt'?'sendBuyUsdtReceipt':
        ($method==='gift_card'?'sendBuyGiftReceipt':'sendBuyCartReceipt');
    $keys=json_decode(kbSendReceiptAndCancel($prefix,'cancelBuyCartReceipt',$hash,$method),true);
    $value=['method'=>$selected,'body'=>$message,'keys'=>$keys,
      'caption'=>achbotManualCaption($connection,$payInfo,'Fixture purchase')];
}
elseif ($op==='auto') {
    $botState['cartToCartAutoAcceptState']='on';
    $botState['cartToCartAutoAcceptTime']=1;
    require $root.'/src/Finance/AutomaticReceipts.php';
    $value='tested';
}
else throw new RuntimeException('unknown synthetic op');
echo json_encode(['result'=>$value],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
"""


PHP_ROUTE=r"""<?php
error_reporting(E_ALL);
set_error_handler(static function($severity,$message){
    if ($severity === E_DEPRECATED) return true;
    throw new ErrorException($message,0,$severity);
});
$_SERVER['SCRIPT_FILENAME']=__FILE__;
$root=$argv[1];
$spec=json_decode($argv[4],true,512,JSON_THROW_ON_ERROR);
$connection=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$connection->set_charset('utf8mb4');
if (($spec['mysqli_report_off']??false)===true) mysqli_report(MYSQLI_REPORT_OFF);
$from_id=(int)($spec['actor']??1001);
$chat_id=$from_id;
$admin=9001;$botUsername='FixtureBot';$message_id=111;
$callbackId='fixture-callback';
$data=(string)($spec['data']??'');
$text=$spec['text']??'';
$userInfo=[
    'step'=>$spec['step']??'none',
    'userid'=>$from_id,'name'=>'Customer',
    'username'=>'synthetic_user','isAdmin'=>false
];
$update=json_decode(json_encode($spec['update']??['message'=>[
    'photo'=>[['file_id'=>'fixture-small'],['file_id'=>'fixture-photo-id']]
]]),false);
$removeKeyboard=null;$cancelKey='{}';$botUrl='http://127.0.0.1:9/fixture/';$supportID='fixture_support';
$USDTPrice='100000';$USDTPriceagent='110000';
$botState=array_replace([
    'cartToCartState'=>'on','giftCardState'=>'on','manualUsdtState'=>'on',
    'nowPaymentWallet'=>'off','zarinpal'=>'off','nextpay'=>'off',
    'tronWallet'=>'off'], $spec['states']??[]);
$paymentKeys=$spec['keys']??[
    'manualUsdtWalletTRC20'=>'TNPeeaaFB7K9cmo4uQpcU32zGK8G1NYqeL',
    'manualUsdtWalletERC20'=>'0x'.str_repeat('2',40),
    'manualTrxWallet'=>'TJRabPrwbZy45sbavfcjinPJC18kjpRTv8',
    'manualTrxRateToman'=>'15000',
    'bankAccount'=>'6037999999999999','holderName'=>'Fixture',
    'giftCardUrl'=>'https://example.org/gift-card',
    'manualUsdtWallet'=>'0x1111111111111111111111111111111111111111',
    'manualUsdtNetwork'=>'BEP20','manualUsdtRateToman'=>'100000',
];
require $root.'/settings/values.php';
require $root.'/src/Finance/ReceiptHelpers.php';
function achbotSupportsPaymentSubject(...$args){return true;}
$GLOBALS['events']=[];
register_shutdown_function(static function(){
    echo json_encode(['events'=>$GLOBALS['events'],'fatal'=>error_get_last()], JSON_THROW_ON_ERROR |
                     JSON_UNESCAPED_UNICODE);
});
function delMessage(){ $GLOBALS['events'][]=['name'=>'delMessage'];}
function setUser($value='none',$field='step'){
    global $connection,$from_id,$spec;
    $GLOBALS['events'][]=['name'=>'setUser','step'=>$value,'field'=>$field];
    // Model the real database step transition for this test's top-up route;
    // rejected updates must preserve even a pre-existing private step.
    if (($spec['route']??'')==='topup') {
        if ($field!=='step') throw new RuntimeException('unexpected step field');
        $stmt=$connection->prepare('UPDATE users SET step=? WHERE userid=?');
        $stmt->bind_param('si',$value,$from_id);
        $stmt->execute();$stmt->close();
    }
}
function getMainKeys(){return '{}';}
function alert(...$args){$GLOBALS['events'][]=['name'=>'alert','args'=>$args];}
function sendMessage(...$args){
    global $spec;
    $GLOBALS['events'][]=['name'=>'sendMessage','args'=>$args];
    if (($spec['message_transport']??'')==='throw')
        throw new RuntimeException('fixture_telegram_transport_down');
    if (($spec['message_transport']??'')==='no_id')
        return (object)['ok'=>true,'result'=>(object)[]];
    return (object)['ok'=>true,'result'=>(object)[
        'message_id'=>(int)($spec['bot_reply_message_id']??500)]];
}
// Isolated fixture ID; the production reference generator is tested separately.
function RandomString(){
    if (($GLOBALS['spec']['pause_before_insert_ms']??0)>0)
        usleep(min(2500,(int)$GLOBALS['spec']['pause_before_insert_ms'])*1000);
    return bin2hex(random_bytes(10));
}
function sendPhoto(...$args){
    global $spec,$connection;
    $GLOBALS['events'][]=['name'=>'sendPhoto','args'=>$args];
    $mode=$spec['photo_mode']??'success';
    if($mode==='throw')throw new RuntimeException('fixture_tel_transport_down');
    if($mode==='terminal_change'){
        $hash=preg_replace('/^wait.*Receipt/', '', (string)($spec['step']??''));
        $stmt=$connection->prepare("UPDATE pays SET state='declined' WHERE hash_id=? AND state='pending'");
        $stmt->bind_param('s',$hash);$stmt->execute();$stmt->close();
    }
    if($mode==='no_id' || $mode==='response_lost')
        return (object)['ok'=>true,'result'=>(object)[]];
    return (object)['ok'=>true,'result'=>(object)['message_id'=>222]];
}
function sendToAllAdmins(...$args){$GLOBALS['events'][]=['name'=>'sendToAllAdmins'];}
function editKeys(...$args){$GLOBALS['events'][]=['name'=>'editKeys'];}
function editText(...$args){$GLOBALS['events'][]=['name'=>'editText','args'=>$args];return (object)['ok'=>true];}
function answerCallbackQuery(...$args){$GLOBALS['events'][]=['name'=>'answerCallbackQuery'];}
// Native payment-authorization tests never contact real fulfillment servers.
// Record every attempted external effect, including mistaken authorization.
$linkIndices=[];$hideConfigIfMany=false;$markup=[];
function achbotSupportsMarzbanOrder(...$args){return true;}
function getPlanDetails(...$args){return ['plan'=>[
    'type'=>'vless','protocol'=>'vless','server_id'=>1,'acount'=>9,
    'inbound_id'=>1,'limitip'=>1,'rahgozar'=>0,'custom_path'=>'',
    'custom_port'=>null,'custom_sni'=>''
]];}
function ensureServerCapacity(...$args){}
function getServerInfoAndConfig(...$args){return [
    ['remark'=>'fixture-remark'],
    ['type'=>'marzban','port_type'=>'automatic']
];}
function generateRandomString(...$args){return 'fixture-random-unique';}
function getNextPortAndIncrease(...$args){return [15001,1];}
function createServerAccount(...$args){
    $GLOBALS['events'][]=['name'=>'remote_create'];
    if (($GLOBALS['spec']['remote_mode']??'success')==='uncertain')
        throw new RuntimeException('fixture_remote_result_unknown');
    return [(object)['success'=>true],'fixture-remark',15001];
}
function ensureAccountCreationSucceeded(...$args){}
function buildLinksAndSendQr(...$args){
    $GLOBALS['events'][]=['name'=>'qr_delivery_mock'];
    return ['fixture-token','fixture-url','fixture-unique'];
}
function insertOrder(...$args){$GLOBALS['events'][]=['name'=>'order_insert'];}
function decreaseServerCapacity(...$args){$GLOBALS['events'][]=['name'=>'capacity_decrement'];}
function editMarzbanConfig(...$args){
    $GLOBALS['events'][]=['name'=>'remote_edit'];
    if (($GLOBALS['spec']['remote_mode']??'success')==='uncertain')
        throw new RuntimeException('fixture_remote_result_unknown');
    return (object)['success'=>true];
}
$GLOBALS['spec']=$spec;
if (($spec['route']??'wallet') === 'manual_crypto') {
    require $root.'/src/Finance/Routes/ManualCrypto.php';
    if (str_starts_with($data,'payIncreaseWalletWith')) {
        require $root.'/src/Finance/Routes/WalletRecharge.php';
    } elseif (str_starts_with($data,'payBuyWith') ||
              str_starts_with($data,'payCustomWith')) {
        require $root.'/src/Finance/Routes/Checkout.php';
    } elseif (str_starts_with($data,'payRenewWith')) {
        require $root.'/src/Finance/Routes/RenewalCheckout.php';
    } elseif (str_starts_with($data,'payIncreaseWith') ||
              str_starts_with($data,'payIncreaseDayWith')) {
        require $root.'/src/Finance/Routes/Extensions.php';
    }
} elseif (($spec['route']??'wallet') === 'crypto_retired') {
    require $root.'/src/Finance/Routes/CryptoAndExchange.php';
} elseif (($spec['route']??'wallet') === 'manual_admin') {
    require $root.'/src/Finance/Routes/ManualReceipts.php';
} elseif (($spec['route']??'wallet') === 'checkout') {
    require $root.'/src/Finance/Routes/Checkout.php';
} elseif (($spec['route']??'wallet') === 'extension') {
    require $root.'/src/Finance/Routes/Extensions.php';
} elseif (($spec['route']??'wallet') === 'renewal') {
    require $root.'/src/Finance/Routes/RenewalCheckout.php';
} else {
    if (($spec['route']??'wallet')==='topup') {
        // Real Telegram bindings: callbacks bind old bot-message text, while
        // genuine customer messages bind a newly authored text payload.
        // In this path, do not trust independently fabricated $data/$text.
        // The actual update bindings must be their only authority.
        $data='';$text=null;
        require $root.'/src/Telegram/UpdateBindings.php';
        // Read the persisted user state as config.php actually does; no
        // fabricated $userInfo may authorize a financial stage.
        $stmt=$connection->prepare('SELECT * FROM users WHERE userid=?');
        $stmt->bind_param('i',$from_id);$stmt->execute();
        $userInfo=$stmt->get_result()->fetch_assoc() ?: ['step'=>'none','userid'=>$from_id];
        $stmt->close();
        // Exercise actual payment insertion and draft cleanup.
        require $root.'/src/Purchase/Core.php';
        require $root.'/src/Finance/WalletStart.php';
    }
    require $root.'/src/Finance/Routes/WalletRecharge.php';
}
"""
def run_route(work,env,driver,data='',step='none',actor=1001,update=None,route='wallet',
              photo_mode='success',remote_mode='success',text='',
              keys=None,states=None,bot_reply_message_id=None,message_transport='',
              pause_before_insert_ms=0,expected_review=False,expected_warning=None,
              mysqli_report_off=False):
    spec={'data':data,'step':step,'actor':actor,'route':route,
          'photo_mode':photo_mode,'remote_mode':remote_mode,'text':text,
          'message_transport':message_transport,
          'pause_before_insert_ms':pause_before_insert_ms,
          'mysqli_report_off':mysqli_report_off}
    if bot_reply_message_id is not None:
        spec['bot_reply_message_id']=bot_reply_message_id
    elif route=='topup':
        spec['bot_reply_message_id']=510 if data=='increaseWalletCustom' else 500
    if route == 'topup' and update is None:
        # Generate a real Telegram update envelope rather than fabricating
        # independent $data/$text variables that bypass UpdateBindings.php.
        sender={'id':actor,'is_bot':False,'username':'fixture_customer',
                'first_name':'Fixture'}
        chat={'id':actor,'type':'private'}
        if data:
            selected=data!='increaseMyWallet'
            update={'update_id':990002 if selected else 990001,'callback_query':{
                'id':'fixture-callback','from':sender,'data':data,
                'message':{'message_id':500 if selected else 111,'chat':chat,
                           'text':'Previous bot instructions',
                           'reply_markup':{'inline_keyboard':[]}}}}
        else:
            update={'update_id':990003,'message':{
                'message_id':520,'from':sender,'chat':chat,'text':text}}
    if update is not None: spec['update']=update
    if keys is not None: spec['keys']=keys
    if states is not None: spec['states']=states
    cmd=['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
         '-d','allow_url_fopen=0','-d','display_errors=1',
         '-d','log_errors=1','-d','error_log=/dev/stderr','-d','open_basedir='+str(ROOT)+':'+str(work),
         '-d','disable_functions=exec,shell_exec,system,passthru,popen,proc_open,mail,dl',
         str(driver),str(ROOT),str(work/'mysql.sock'),DB,
         json.dumps(spec,ensure_ascii=False)]
    p=subprocess.run(cmd,cwd=work,env=env,capture_output=True,text=True,timeout=20)
    accepted_log_codes=[]
    if expected_review:
        accepted_log_codes.append('achbot_wallet_topup_creation_review')
    if expected_warning:
        accepted_log_codes.append(expected_warning)
    safe_review=(accepted_log_codes and p.stderr and all(
        any(code in line for code in accepted_log_codes) and
        'fixture_' not in line and 'SQL' not in line
        for line in p.stderr.splitlines()))
    if p.returncode or (p.stderr and not safe_review):
        raise RuntimeError('isolated wallet payment route failed: '+
                           repr((p.returncode,p.stderr,p.stdout))[:1400])
    try:
        report=json.loads(p.stdout)
        if report.get('fatal'):
            raise RuntimeError('wallet payment route fatal: '+repr(report['fatal'])[:400])
        return report['events']
    except (json.JSONDecodeError,KeyError) as exc:
        raise RuntimeError('invalid wallet payment route output: '+repr(p.stdout)) from exc

def query(work,env,sql:str)->str:
    return native.sql(work,env,sql,DB).strip()

def run_php(work,env,driver,hash_id,op,method='bank_transfer',user=1001,**more):
    expect_deferred=bool(more.pop('expect_deferred',False))
    spec=dict(op=op,method=method,user=user,**more)
    cmd=['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
         '-d','allow_url_fopen=0','-d','display_errors=1',
         '-d','log_errors=1','-d','error_log=/dev/stderr','-d','open_basedir='+str(ROOT)+':'+str(work),
         '-d','disable_functions=exec,shell_exec,system,passthru,popen,proc_open,mail,dl',
         str(driver),str(ROOT),str(work/'mysql.sock'),hash_id,
         json.dumps(spec,ensure_ascii=False),DB]
    p=subprocess.run(cmd,cwd=work,env=env,capture_output=True,text=True,timeout=20)
    safe_deferred=(expect_deferred and p.stderr and all(
        'achbot_bank_auto_' in line and
        ('pay_row_id=' in line or 'achbot_bank_auto_scan_deferred' in line) and
        'fixture' not in line and 'SQL' not in line
        for line in p.stderr.splitlines()))
    if p.returncode or (p.stderr and not safe_deferred):
        raise RuntimeError('isolated PHP method test failed: '+repr((p.returncode,p.stderr,p.stdout))[:1200])
    try: return json.loads(p.stdout)['result']
    except Exception as exc:raise RuntimeError('invalid manual driver: '+repr(p.stdout)) from exc

def seed(work,env,hash_id,price=100000,owner=1001):
    safe="'"+hash_id+"'"
    if not hash_id.isascii() or not hash_id.isalnum():raise ValueError(hash_id)
    query(work,env,"INSERT INTO pays (hash_id,user_id,type,price,request_date,state) "
          f"VALUES ({safe},{int(owner)},'INCREASE_WALLET',{int(price)},1700000000,'pending')")

def check():
    cases=[]
    with owned.owned_database() as (work,env):
        purchase.init(work,env,"http://127.0.0.1:9")
        assert len(query(work,env,'SHOW TABLES').splitlines())==23
        query(work,env,(ROOT/'migrations/post-v9/003_payment_attempts.sql').read_text())
        driver=work/'manual-payments.php';driver.write_text(PHP)
        for name in ('bankfixture','giftfixture','usdtfixture','crossfixture',
                     'wronguser','disabledusdt','badwallet','badnetwork','agentusdt'):
            seed(work,env,name,100000,9002 if name=='agentusdt' else 1001)

        base=lambda hash_id,op,method='bank_transfer',**kw:run_php(work,env,driver,hash_id,op,method,**kw)

        # Nonpositive local prices must never credit wallet through subtraction.
        for key,value in [('walletnegative',-100000),('walletzero',0)]:
            seed(work,env,key,price=value)
            before=query(work,env,'SELECT userid,wallet FROM users ORDER BY userid')
            got=base(key,'wallet')
            assert got['status']=='invalid_amount', (key,got)
            assert query(work,env,'SELECT userid,wallet FROM users ORDER BY userid')==before
            assert query(work,env,f"SELECT state FROM pays WHERE hash_id='{key}'")=='pending'
            cases.append('wallet_spend_invalid_'+key+'_no_financial_effect')

        assert base('bankfixture','claim') is True
        assert base('bankfixture','claim') is True
        cases.append('bank_claim_idempotent')
        bank=base('bankfixture','read')
        assert bank['method']=='bank_transfer'
        quote=int(query(work,env,"SELECT quote_amount FROM payment_attempts WHERE order_hash='bankfixture'").split('.')[0])
        assert 100100<=quote<=100999 and f'{quote:,}' in bank['body']
        assert '6037999999999999' in bank['body'] and 'ارسال رسید بانکی' in str(bank['keys'])
        cases.append('bank_random_exact_amount_persisted_and_rendered')

        assert base('giftfixture','claim','gift_card') is True
        gift=base('giftfixture','read','gift_card')
        assert '100,000' in gift['body'] and 'گیفت‌کارت' in gift['body']
        assert 'https://example.org/gift-card' in str(gift['keys']) and 'USDT' not in gift['body']
        cases.append('gift_distinct_quote_link_and_photo_action')

        assert base('usdtfixture','claim','manual_usdt') is True
        u=base('usdtfixture','read','manual_usdt')
        assert u['method']=='manual_usdt' and '1.00 USDT' in u['body']
        assert 'BEP20' in u['body'] and '0x1111111111111111111111111111111111111111' in u['body']
        assert 'ارسال رسید تتر' in str(u['keys']) and 'USDT' in u['caption']
        cases.append('usdt_quote_network_wallet_photo_admin_distinct')

        assert base('usdtfixture','claim','manual_usdt',keys={
          'manualUsdtWallet':'0x2222222222222222222222222222222222222222',
          'manualUsdtNetwork':'ERC20','manualUsdtRateToman':'200000'}) is True
        same=base('usdtfixture','read','manual_usdt',keys={
          'manualUsdtWallet':'0x2222222222222222222222222222222222222222',
          'manualUsdtNetwork':'ERC20','manualUsdtRateToman':'200000'})
        assert same['body']==u['body']
        cases.append('usdt_amount_network_destination_immutable_on_revisit')

        assert base('bankfixture','claim','gift_card') is False
        assert base('giftfixture','claim','manual_usdt') is False
        assert base('usdtfixture','claim','bank_transfer') is False
        assert base('usdtfixture','allow','gift_card') is False
        assert base('usdtfixture','allow','manual_usdt') is True
        cases.append('no_manual_method_switch_replay_or_cross_receipt')

        assert base('wronguser','claim',user=9002) is False
        assert base('wronguser','claim') is True
        assert base('wronguser','allow',user=9002) is False
        cases.append('wrong_user_denied')

        assert base('disabledusdt','claim','manual_usdt',states={
            'manualUsdtState':'off','cartToCartState':'on','giftCardState':'on'}) is False
        assert base('badwallet','claim','manual_usdt',keys={
            'manualUsdtWallet':'T123','manualUsdtNetwork':'BEP20','manualUsdtRateToman':'100000'}) is False
        assert base('badnetwork','claim','manual_usdt',keys={
            'manualUsdtWallet':'0x1111111111111111111111111111111111111111',
            'manualUsdtNetwork':'UNKNOWN','manualUsdtRateToman':'100000'}) is False
        cases.append('manual_usdt_disabled_or_bad_configuration_fails_closed')

        assert base('agentusdt','claim','manual_usdt',user=9002,keys={
            'manualUsdtWallet':'TNPeeaaFB7K9cmo4uQpcU32zGK8G1NYqeL','manualUsdtNetwork':'TRC20'}) is True
        agent=base('agentusdt','read','manual_usdt',user=9002)
        assert 'TRC20' in agent['body'] and '0.91 USDT' in agent['body']
        cases.append('agent_existing_price_and_trc20_validated')

        # A concurrent checkout across all three manual choices is reserved
        # by the shared UNIQUE(pay_row_id) and locked local invoice row.
        assert base('crossfixture','claim','gift_card') is True
        assert base('crossfixture','claim','manual_usdt') is False
        assert base('crossfixture','claim','bank_transfer') is False
        assert query(work,env,"SELECT COUNT(*) FROM payment_attempts WHERE order_hash='crossfixture'")=='1'
        cases.append('one_attempt_no_cross_method_claim')

        assert isinstance(base('usdtfixture','begin','manual_usdt'),str)
        assert base('usdtfixture','begin','manual_usdt') is None
        assert base('usdtfixture','allow','manual_usdt') is True
        assert query(work,env,"SELECT stage FROM payment_attempts WHERE order_hash='usdtfixture'")=='receiving'
        cases.append('single_receipt_claim_no_duplicate_photo_submission')
        before=query(work,env,'SELECT userid,wallet FROM users ORDER BY userid')
        assert base('usdtfixture','auto','manual_usdt')=='tested'
        assert query(work,env,'SELECT userid,wallet FROM users ORDER BY userid')==before
        cases.append('manual_usdt_no_automatic_wallet_credit')
        query(work,env,"UPDATE pays SET state='have_sent' WHERE hash_id IN ('giftfixture','usdtfixture') ")
        # Both gift and USDT receipts must NOT enter card auto-accept.
        query(work,env,"UPDATE pays SET request_date=1700000000 WHERE hash_id IN ('giftfixture','usdtfixture')")
        assert base('usdtfixture','auto','manual_usdt')=='tested'
        assert query(work,env,"SELECT state FROM pays WHERE hash_id='usdtfixture'")=='have_sent'
        assert query(work,env,"SELECT state FROM pays WHERE hash_id='giftfixture'")=='have_sent'
        assert query(work,env,'SELECT userid,wallet FROM users ORDER BY userid')==before
        cases.append('gift_usdt_screenshots_never_auto_approved')

        route_driver=work/'manual-wallet-route.php';route_driver.write_text(PHP_ROUTE)
        # Execute the real current WalletRecharge PHP: buttons, receipt
        # prompts, Telegram photo, operator delivery and persisted review.
        for key,choice,receipt,wait,label in [
            ('routebank','CartToCart','IncreaseWallet','waitIncreaseWalletReceipt','کارت‌به‌کارت'),
            ('routegift','GiftCard','IncreaseWalletGift','waitIncreaseWalletGiftReceipt','گیفت‌کارت'),
            ('routeusdt','ManualUsdt','IncreaseWalletUsdt','waitIncreaseWalletUsdtReceipt','تتر دستی')
        ]:
            seed(work,env,key)
            started=run_route(work,env,route_driver,
                              data='payIncreaseWalletWith'+choice+key)
            wanted={'CartToCart':'bank_transfer','GiftCard':'gift_card',
                    'ManualUsdt':'manual_usdt'}[choice]
            assert query(work,env,"SELECT provider FROM payment_attempts "
                          f"WHERE order_hash='{key}'")==wanted
            assert any(e['name']=='sendMessage' and any(label in str(arg) for arg in e['args'])
                       for e in started),repr((key,started))
            cases.append('real_wallet_route_start_'+wanted)

            # A cached receipt button from the *other* manual method cannot
            # change an already-claimed order.
            wrong='sendIncreaseWalletGiftReceipt' if wanted!='gift_card' else 'sendIncreaseWalletUsdtReceipt'
            rejected=run_route(work,env,route_driver,data=wrong+key)
            assert any(e['name']=='alert' for e in rejected)
            assert query(work,env,"SELECT stage FROM payment_attempts "
                         f"WHERE order_hash='{key}'")=='pending'
            cases.append('real_wallet_route_cross_receipt_denied_'+wanted)

            prepared=run_route(work,env,route_driver,data='send'+receipt+'Receipt'+key)
            assert any(e['name']=='setUser' and e['step']==wait+key
                       for e in prepared),repr((key,prepared))
            cases.append('real_wallet_route_prompt_'+wanted)

            wallet_before=query(work,env,'SELECT userid,wallet FROM users ORDER BY userid')
            received=run_route(work,env,route_driver,step=wait+key)
            assert any(e['name']=='sendPhoto' and any(label in str(arg) for arg in e['args'])
                       for e in received),repr((key,received))
            assert query(work,env,"SELECT state FROM pays "
                         f"WHERE hash_id='{key}'")=='have_sent'
            assert query(work,env,"SELECT stage FROM payment_attempts "
                         f"WHERE order_hash='{key}'")=='review'
            assert query(work,env,'SELECT userid,wallet FROM users ORDER BY userid')==wallet_before
            cases.append('real_wallet_route_operator_review_'+wanted)

            repeated=run_route(work,env,route_driver,step=wait+key)
            assert any(e['name']=='alert' for e in repeated),repr((key,repeated))
            assert not any(e['name']=='sendPhoto' for e in repeated)
            assert query(work,env,'SELECT userid,wallet FROM users ORDER BY userid')==wallet_before
            cases.append('real_wallet_route_photo_replay_denied_'+wanted)

        assert query(work,env,'SELECT COUNT(*) FROM payment_transactions')=='0'
        assert query(work,env,"SELECT COUNT(*) FROM pays WHERE state IN ('approved','paid','auto_cart_approved')")=='0'
        cases.append('no_fulfillment_financial_provider_effect')

        # Review F1/F2: execute actual service operator callback handlers, not
        # merely helper functions. Every remote action is a local spy.
        def service_pay(key,subject='BUY_SUB',method='bank_transfer',received=True):
            seed(work,env,key)
            query(work,env,"UPDATE pays SET type='"+subject+"',plan_id=1,"
                  "server_id=1,day=30,volume=10,description='fixture' "
                  "WHERE hash_id='"+key+"'")
            assert base(key,'claim',method)
            if received:
                token=base(key,'begin',method)
                assert isinstance(token,str) and token
                assert base(key,'finish',method,token=token) is True
                assert query(work,env,"SELECT state FROM pays WHERE hash_id='"+key+"'")=='have_sent'
                assert query(work,env,"SELECT stage FROM payment_attempts WHERE order_hash='"+key+"'")=='review'
            return key

        def service_snapshot(key):
            return (
                query(work,env,"SELECT state,IFNULL(approved_by,0) FROM pays WHERE hash_id='"+key+"'"),
                query(work,env,'SELECT userid,wallet FROM users ORDER BY userid'),
                query(work,env,'SELECT COUNT(*) FROM orders_list'),
                query(work,env,'SELECT COUNT(*) FROM increase_order'),
                query(work,env,'SELECT COUNT(*),IFNULL(SUM(acount),0) FROM server_plans'),
            )

        def reject_service(key,route,callback,actor=9001):
            before=service_snapshot(key)
            events=run_route(work,env,route_driver,data=callback+key,actor=actor,route=route)
            assert not any(event['name'] in ('remote_create','remote_edit','order_insert',
                              'capacity_decrement','qr_delivery_mock')
                           for event in events),repr((key,events))
            assert service_snapshot(key)==before,(key,before,service_snapshot(key))
            return events

        service_pay('customvalid',method='gift_card')
        assert base('customvalid','service_guard',subject='custom') is True
        rejected=reject_service('customvalid','checkout','accCustom',actor=1001)
        assert any(event['name']=='alert' for event in rejected),repr(rejected)
        cases.append('real_custom_nonadmin_cannot_approve_submitted_gift_receipt')
        positive=run_route(work,env,route_driver,data='accCustomcustomvalid',
                           actor=9001,route='checkout')
        assert [event['name'] for event in positive].count('remote_create')==1,repr(positive)
        assert [event['name'] for event in positive].count('order_insert')==1
        assert query(work,env,"SELECT state FROM pays WHERE hash_id='customvalid'")=='approved'
        cases.append('real_custom_authorized_review_state_approves_only_once')
        reject_service('customvalid','checkout','accCustom')
        cases.append('real_custom_replayed_operator_callback_no_remote_effect')

        service_pay('custompending',received=False)
        reject_service('custompending','checkout','accCustom')
        cases.append('real_custom_pending_without_photo_cannot_approve')
        service_pay('customwalletpaid',received=False)
        query(work,env,"UPDATE pays SET state='paid_with_wallet' WHERE hash_id='customwalletpaid'")
        reject_service('customwalletpaid','checkout','accCustom')
        cases.append('real_custom_wallet_paid_invoice_cannot_approve_again')
        service_pay('customwrongtype',subject='INCREASE_WALLET',method='manual_usdt')
        assert base('customwrongtype','service_guard',subject='custom') is False
        reject_service('customwrongtype','checkout','accCustom')
        cases.append('real_custom_wrong_subject_cannot_approve')
        # Standard plan checkout is also BUY_SUB but has volume=day=0.
        # Its callback must never be consumable by accCustom.
        service_pay('customnormalplan',method='bank_transfer')
        query(work,env,"UPDATE pays SET volume=0,day=0 WHERE hash_id='customnormalplan'")
        assert base('customnormalplan','service_guard',subject='custom') is False
        reject_service('customnormalplan','checkout','accCustom')
        cases.append('real_custom_callback_cannot_approve_standard_plan_subject')
        service_pay('customwrongprovider',method='manual_usdt')
        query(work,env,"UPDATE payment_attempts SET provider='nowpayments' "
              "WHERE order_hash='customwrongprovider'")
        reject_service('customwrongprovider','checkout','accCustom')
        cases.append('real_custom_hosted_provider_conflict_cannot_approve')
        service_pay('customwrongstage',method='bank_transfer')
        query(work,env,"UPDATE payment_attempts SET stage='pending' "
              "WHERE order_hash='customwrongstage'")
        reject_service('customwrongstage','checkout','accCustom')
        cases.append('real_custom_unreviewed_attempt_cannot_approve')
        service_pay('custommissingphoto',method='gift_card')
        query(work,env,"UPDATE pays SET message_id=0 WHERE hash_id='custommissingphoto'")
        reject_service('custommissingphoto','checkout','accCustom')
        cases.append('real_custom_missing_admin_delivery_evidence_denied')
        service_pay('customterminal',method='manual_usdt')
        query(work,env,"UPDATE pays SET state='auto_cart_approved' WHERE hash_id='customterminal'")
        reject_service('customterminal','checkout','accCustom')
        cases.append('real_custom_stale_after_bank_auto_claim_denied')

        for route,callback,subject,guard in [
            ('renewal','approveRenewAcc','RENEW_ACCOUNT','renewal'),
            ('extension','approveIncreaseDay','INCREASE_DAY_1_2','day'),
            ('extension','approveIncreaseVolume','INCREASE_VOLUME_1_2','volume'),
        ]:
            prefix='safeguard'+guard
            service_pay(prefix,subject=subject,method='manual_usdt')
            assert base(prefix,'service_guard',subject=guard) is True
            # The scheduled bank claim has priority only for a bank receipt;
            # its terminal state must never be accepted by a later operator.
            query(work,env,"UPDATE pays SET state='auto_cart_approved' WHERE hash_id='"+prefix+"'")
            reject_service(prefix,route,callback)
            cases.append('real_'+guard+'_stale_auto_bank_claim_cannot_refulfill')
            query(work,env,"UPDATE pays SET state='approved' WHERE hash_id='"+prefix+"'")
            reject_service(prefix,route,callback)
            cases.append('real_'+guard+'_already_approved_cannot_refulfill')
            query(work,env,"UPDATE pays SET state='pending' WHERE hash_id='"+prefix+"'")
            reject_service(prefix,route,callback)
            cases.append('real_'+guard+'_pending_without_review_state_cannot_fulfill')
            query(work,env,"UPDATE pays SET state='have_sent' WHERE hash_id='"+prefix+"'")
            query(work,env,"UPDATE payment_attempts SET provider='nowpayments' "
                  "WHERE order_hash='"+prefix+"'")
            reject_service(prefix,route,callback)
            cases.append('real_'+guard+'_conflicting_provider_cannot_fulfill')

        # A legacy bank receipt can proceed ONLY if operator-delivery evidence
        # exists. The test does not treat that as automatic financial proof.
        seed(work,env,'legacyreviewservice')
        query(work,env,"UPDATE pays SET type='BUY_SUB',state='have_sent',"
              "volume=10,day=30,message_id=222,chat_id=9001 "
              "WHERE hash_id='legacyreviewservice'")
        assert base('legacyreviewservice','service_guard',subject='custom') is True
        query(work,env,"UPDATE pays SET message_id=0 WHERE hash_id='legacyreviewservice'")
        assert base('legacyreviewservice','service_guard',subject='custom') is False
        cases.append('legacy_operator_receipt_requires_persisted_delivery_evidence')

        # Admin callback and scheduler claim contend for one pays row. Their
        # ordering must never lead to a second remote service creation.
        from concurrent.futures import ThreadPoolExecutor
        for i in range(4):
            key='servicecallbackrace'+str(i)
            service_pay(key,subject='BUY_SUB',method='bank_transfer')
            with ThreadPoolExecutor(max_workers=2) as executor:
                admin_future=executor.submit(run_route,work,env,route_driver,
                    'accCustom'+key,'none',9001,None,'checkout')
                auto_future=executor.submit(base,key,'bank_auto_claim')
                admin_events=admin_future.result()
                auto_won=auto_future.result()
            state=query(work,env,"SELECT state FROM pays WHERE hash_id='"+key+"'")
            creates=sum(event['name']=='remote_create' for event in admin_events)
            assert (state,creates,auto_won) in (
                ('approved',1,False),('auto_cart_approved',0,True)),repr((key,state,creates,auto_won,admin_events))
            reject_service(key,'checkout','accCustom')
        cases.append('real_custom_operator_and_bank_scheduler_race_single_service_claim')

        service_pay('customconcurrentadmins',method='manual_usdt')
        with ThreadPoolExecutor(max_workers=2) as executor:
            decisions=list(executor.map(lambda _:run_route(work,env,route_driver,
                data='accCustomcustomconcurrentadmins',actor=9001,route='checkout'),range(2)))
        assert sum(sum(event['name']=='remote_create' for event in decision)
                   for decision in decisions)==1,repr(decisions)
        assert query(work,env,"SELECT state FROM pays WHERE hash_id='customconcurrentadmins'")=='approved'
        reject_service('customconcurrentadmins','checkout','accCustom')
        cases.append('real_custom_two_concurrent_admins_single_remote_create')

        # Exercise successful administrator-before-Cron paths for all three
        # service operations using synthetic Marzban records and HTTP-free spies.
        query(work,env,"INSERT INTO orders_list "
              "(userid,token,transid,fileid,server_id,inbound_id,remark,uuid,"
              "protocol,expire_date,link,amount,status,date) VALUES "
              "(1001,'service-token','',7,2,0,'service-fixture','service-uuid',"
              "'vless',2000000000,'[]',100,1,'synthetic')")
        service_oid=int(query(work,env,"SELECT id FROM orders_list WHERE remark='service-fixture' LIMIT 1"))
        query(work,env,"INSERT INTO increase_day (id,volume,price) VALUES (901,7,100)")
        query(work,env,"INSERT INTO increase_plan (id,volume,price) VALUES (902,8,100)")
        query(work,env,"UPDATE server_config SET type='marzban' WHERE id=2")
        for route,callback,subject,guard in [
            ('renewal','approveRenewAcc','RENEW_ACCOUNT','renewal'),
            ('extension','approveIncreaseDay',f'INCREASE_DAY_{service_oid}_901','day'),
            ('extension','approveIncreaseVolume',f'INCREASE_VOLUME_{service_oid}_902','volume'),
        ]:
            key='adminfirst'+guard
            service_pay(key,subject=subject,method='bank_transfer')
            query(work,env,"UPDATE pays SET plan_id=7,server_id=2,order_id="+str(service_oid)+
                  ",price=100 WHERE hash_id='"+key+"'")
            before=int(query(work,env,'SELECT COUNT(*) FROM increase_order'))
            result=run_route(work,env,route_driver,data=callback+key,actor=9001,route=route)
            assert [event['name'] for event in result].count('remote_edit')==1,repr((key,result))
            assert query(work,env,"SELECT state FROM pays WHERE hash_id='"+key+"'")=='approved',repr((key,result))
            assert int(query(work,env,'SELECT COUNT(*) FROM increase_order'))==before+1
            assert base(key,'bank_auto_claim') is False
            reject_service(key,route,callback)
            cases.append('real_'+guard+'_operator_before_bank_cron_one_remote_effect')

        # Ambiguous provider outcomes are not retried; the operator must
        # reconcile remote state before acting on the still-reviewable invoice.
        service_pay('customremoteunknown',method='manual_usdt')
        prior=service_snapshot('customremoteunknown')
        uncertain=run_route(work,env,route_driver,data='accCustomcustomremoteunknown',
            actor=9001,route='checkout',remote_mode='uncertain')
        assert any(event['name']=='remote_create' for event in uncertain),repr(uncertain)
        assert any(event['name']=='sendMessage' and 'بررسی سرویس و پرداخت' in str(event['args'])
                   for event in uncertain),repr(uncertain)
        assert service_snapshot('customremoteunknown')==prior
        cases.append('uncertain_custom_external_effect_operator_reconciliation_no_retry')

        for key,provider in [('routebank','bank_transfer'),('routegift','gift_card'),
                             ('routeusdt','manual_usdt')]:
            before=int(query(work,env,'SELECT wallet FROM users WHERE userid=1001'))
            unauthorized=run_route(work,env,route_driver,
               data='approvePayment'+key,actor=1001,route='manual_admin')
            assert not any(e['name']=='sendMessage' and 'تایید شد' in str(e.get('args'))
                           for e in unauthorized)
            assert query(work,env,f"SELECT state FROM pays WHERE hash_id='{key}'")=='have_sent'
            assert int(query(work,env,'SELECT wallet FROM users WHERE userid=1001'))==before
            cases.append('manual_wallet_nonadmin_callback_denied_'+provider)

            credited=run_route(work,env,route_driver,
                data='approvePayment'+key,actor=9001,route='manual_admin')
            assert any(e['name']=='sendMessage' and 'افزایش حساب' in str(e.get('args'))
                       for e in credited),repr((key,credited))
            assert query(work,env,f"SELECT state FROM pays WHERE hash_id='{key}'")=='approved'
            assert int(query(work,env,'SELECT wallet FROM users WHERE userid=1001'))==before+100000
            cases.append('manual_wallet_exactly_one_credit_'+provider)

            repeated=run_route(work,env,route_driver,
                data='approvePayment'+key,actor=9001,route='manual_admin')
            assert any(e['name']=='alert' for e in repeated)
            assert int(query(work,env,'SELECT wallet FROM users WHERE userid=1001'))==before+100000
            cases.append('manual_wallet_approval_replay_denied_'+provider)

        # Concurrent actor callbacks must serialize on the order row,
        # preserving exactly one wallet effect.
        seed(work,env,'walletapprovalrace')
        assert base('walletapprovalrace','claim','manual_usdt')
        query(work,env,"UPDATE pays SET state='have_sent' WHERE hash_id='walletapprovalrace'")
        before=int(query(work,env,'SELECT wallet FROM users WHERE userid=1001'))
        from concurrent.futures import ThreadPoolExecutor
        with ThreadPoolExecutor(max_workers=2) as executor:
            two=list(executor.map(lambda _:base('walletapprovalrace','approve','manual_usdt',
                                                 actor=9001),range(2)))
        assert sum(x is not None for x in two)==1,repr(two)
        assert query(work,env,"SELECT state FROM pays WHERE hash_id='walletapprovalrace'")=='approved'
        assert int(query(work,env,'SELECT wallet FROM users WHERE userid=1001'))==before+100000
        cases.append('manual_wallet_two_admin_concurrent_single_credit')

        # Negative administrator debt remains legal; positive top-ups
        # offset it atomically instead of clamping a debt balance.
        seed(work,env,'walletapprovaldebt',owner=9002)
        query(work,env,'UPDATE users SET wallet=-50000 WHERE userid=9002')
        query(work,env,"UPDATE pays SET state='have_sent' WHERE hash_id='walletapprovaldebt'")
        debt=base('walletapprovaldebt','approve','manual_usdt',actor=9001)
        assert debt is not None and debt['wallet_before']==-50000 and debt['wallet_after']==50000
        cases.append('manual_wallet_privileged_debt_offset_preserved')

        seed(work,env,'walletapprovalbadtype')
        query(work,env,"UPDATE pays SET type='BUY_SUB',state='have_sent' WHERE hash_id='walletapprovalbadtype'")
        balance=int(query(work,env,'SELECT wallet FROM users WHERE userid=1001'))
        assert base('walletapprovalbadtype','approve',actor=9001) is None
        assert int(query(work,env,'SELECT wallet FROM users WHERE userid=1001'))==balance
        cases.append('manual_wallet_not_for_other_payment_subjects')


        # F5: original bank destination is immutable despite administrator changes.
        seed(work,env,'bankpinold')
        assert base('bankpinold','claim','bank_transfer')
        old=base('bankpinold','read')
        assert '6037999999999999' in old['body'] and 'Fixture' in old['body']
        new_keys={'bankAccount':'5892100000000001','holderName':'Updated Recipient'}
        pinned=base('bankpinold','read','bank_transfer',keys=new_keys)
        assert pinned['body']==old['body']
        assert '5892100000000001' not in pinned['body']
        cases.append('bank_destination_and_quote_original_immutable_after_settings_change')
        seed(work,env,'bankpinnew')
        assert base('bankpinnew','claim','bank_transfer',keys=new_keys)
        newer=base('bankpinnew','read','bank_transfer',keys=new_keys)
        assert '5892100000000001' in newer['body'] and 'Updated Recipient' in newer['body']
        cases.append('new_bank_invoice_gets_new_destination')
        seed(work,env,'bankmissing')
        assert base('bankmissing','claim',keys={'bankAccount':'','holderName':''}) is False
        cases.append('incomplete_bank_destination_not_advertised')

        # F6: no implicit third-party gift-card shop is ever silently selected.
        seed(work,env,'giftbadurl')
        assert base('giftbadurl','claim','gift_card',
            keys={'giftCardUrl':'http://example.org/buy'}) is False
        assert base('giftfixture','read','gift_card',keys={'giftCardUrl':'https://new.example.net'})['keys']==gift['keys']
        cases.append('gift_seller_must_be_explicit_https_and_stays_pinned')

        # The shared editor/checkout guard admits <=350-byte HTTPS seller URLs
        # while rejecting length overflow and embedded URL credentials.
        prefix='https://example.org/'
        valid_url=prefix+'x'*(350-len(prefix))
        assert len(valid_url)==350
        for name,url,eligible in (
            ('giftlimit350',valid_url,True),
            ('giftlimit351',valid_url+'x',False),
            ('giftcredentials','https://username:password@example.org/gift',False),
            ('giftempty','',False),
        ):
            seed(work,env,name)
            value=base(name,'claim','gift_card',keys={'giftCardUrl':url})
            assert bool(value) is eligible, (name,value)
            cases.append(name+'_seller_validation')

        # F1: legacy pending gift orders may contain a pinned seller URL which
        # predates the stricter editor check. Read/reopen the real saved attempt,
        # suppress only unsafe outbound URL actions and preserve financial data.
        wallet_before=query(work,env,'SELECT userid,wallet FROM users ORDER BY userid')
        transactions_before=query(work,env,'SELECT COUNT(*) FROM payment_transactions')
        oversized='https://example.org/'+'x'*(351-len('https://example.org/'))
        assert len(oversized)==351
        for name,modified_url in (
            ('giftlegacycredentials','https://fixture:password@example.org/gift-card'),
            ('giftlegacyoversize',oversized),
            ('giftlegacymissing',None),
        ):
            seed(work,env,name)
            assert base(name,'claim','gift_card') is True
            if modified_url is None:
                query(work,env,"UPDATE payment_attempts SET checkout_url="
                    "JSON_REMOVE(checkout_url,'$.seller_url') "
                    f"WHERE order_hash='{name}'")
            else:
                query(work,env,"UPDATE payment_attempts SET checkout_url="
                    f"REPLACE(checkout_url,'https://example.org/gift-card','{modified_url}') "
                    f"WHERE order_hash='{name}'")
            pinned_before=query(work,env,
                f"SELECT checkout_url FROM payment_attempts WHERE order_hash='{name}'")
            assert base(name,'claim','gift_card',keys={
                'giftCardUrl':'https://new.example.org/gift'}) is True
            reread=base(name,'read','gift_card',keys={
                'giftCardUrl':'https://new.example.org/gift'})
            rows=reread['keys']['inline_keyboard']
            assert rows[0]==[{'text':'📤 ارسال تصویر گیفت‌کارت',
                              'callback_data':'sendBuyGiftReceipt'+name}],(name,rows)
            assert rows[1]==[{'text':'❌ لغو عملیات',
                              'callback_data':'cancelBuyCartReceipt'+name}]
            assert all('url' not in button for row in rows for button in row)
            assert query(work,env,
                f"SELECT checkout_url FROM payment_attempts WHERE order_hash='{name}'"
            )==pinned_before
            assert query(work,env,
                f"SELECT state FROM pays WHERE hash_id='{name}'")=='pending'
            assert query(work,env,
                f"SELECT stage FROM payment_attempts WHERE order_hash='{name}'")=='pending'
            assert query(work,env,
                f"SELECT COUNT(*) FROM payment_attempts WHERE order_hash='{name}'")=='1'
            assert query(work,env,'SELECT userid,wallet FROM users ORDER BY userid')==wallet_before
            assert query(work,env,'SELECT COUNT(*) FROM payment_transactions')==transactions_before
            cases.append(name+'_pinned_url_hidden_without_mutation')

        seed(work,env,'giftlegacyvalid')
        assert base('giftlegacyvalid','claim','gift_card') is True
        original_intent=query(work,env,
            "SELECT checkout_url FROM payment_attempts WHERE order_hash='giftlegacyvalid'")
        assert base('giftlegacyvalid','claim','gift_card',keys={
            'giftCardUrl':'https://new.example.org/gift'}) is True
        valid_rows=base('giftlegacyvalid','read','gift_card',keys={
            'giftCardUrl':'https://new.example.org/gift'})['keys']['inline_keyboard']
        assert valid_rows[0][1]=={'text':'🎁 خرید گیفت‌کارت',
            'url':'https://example.org/gift-card'}
        assert query(work,env,
            "SELECT checkout_url FROM payment_attempts WHERE order_hash='giftlegacyvalid'")==original_intent
        assert query(work,env,
            "SELECT state FROM pays WHERE hash_id='giftlegacyvalid'")=='pending'
        assert query(work,env,'SELECT userid,wallet FROM users ORDER BY userid')==wallet_before
        assert query(work,env,'SELECT COUNT(*) FROM payment_transactions')==transactions_before
        cases.append('giftlegacyvalid_pinned_url_retained_despite_settings_change')

        # The unified manual-crypto menu offers ONLY valid configured networks.
        crypto_keys={
            'manualUsdtWalletBEP20':'0x'+'1'*40,
            'manualUsdtWalletTRC20':'TNPeeaaFB7K9cmo4uQpcU32zGK8G1NYqeL',
            'manualUsdtWalletERC20':'0x'+'2'*40,
            'manualUsdtRateToman':'100000',
            'manualTrxWallet':'TJRabPrwbZy45sbavfcjinPJC18kjpRTv8',
            'manualTrxRateToman':'15000'
        }
        seed(work,env,'cryptosettings')
        options=base('cryptosettings','crypto_options',keys=crypto_keys)
        assert list(options)==['b','c','e','t'] and {
            x['provider'] for x in options.values()
        }=={'manual_usdt','manual_trx'}
        assert all(options[t]['network']==n for t,n in [
            ('b','BEP20'),('c','TRC20'),('e','ERC20'),('t','TRON')])
        cases.append('four_valid_crypto_destinations_independent_in_shared_menu')
        for key,removed,expected in [
            ('cryptonotrxwallet','manualTrxWallet',['b','c','e']),
            ('cryptonotrxrate','manualTrxRateToman',['b','c','e']),
            ('cryptonotrc20','manualUsdtWalletTRC20',['b','e','t']),
            ('cryptonoerc20','manualUsdtWalletERC20',['b','c','t']),
            ('cryptonobep20','manualUsdtWalletBEP20',['c','e','t'])
        ]:
            seed(work,env,key)
            partial=dict(crypto_keys)
            partial.pop(removed)
            # No fallback to a synthetic legacy default for an unset new key.
            result=base(key,'crypto_options',keys=partial)
            assert list(result)==expected, (key,result)
            cases.append(key+'_unconfigured_option_not_displayed')
        seed(work,env,'cryptodisabled')
        assert base('cryptodisabled','crypto_options',keys=crypto_keys,
            states={'manualUsdtState':'off'})==[]
        assert base('cryptodisabled','crypto_enabled',keys=crypto_keys,
            states={'manualUsdtState':'off'}) is False
        cases.append('manual_crypto_master_toggle_disables_all_four')
        for key,bad in [
            ('trxwrongaddress',{'manualTrxWallet':'0x'+'1'*40}),
            ('trxzerorate',{'manualTrxRateToman':'0'}),
            ('trxinvalidrate',{'manualTrxRateToman':'not_a_number'})
        ]:
            seed(work,env,key)
            bad_keys=dict(crypto_keys,**bad)
            assert 't' not in base(key,'crypto_options',keys=bad_keys)
            cases.append(key+'_no_trx_issuance')
        seed(work,env,'legacytronkey')
        assert 't' not in base('legacytronkey','crypto_options',
            keys={'tronwallet':'TJRabPrwbZy45sbavfcjinPJC18kjpRTv8', 'manualTrxRateToman':'15000'})
        cases.append('legacy_tronwallet_is_never_implicitly_activated')

        # R1: a plausible T-prefixed address is not sufficient payment
        # configuration. Neither a bad-checksum USDT/TRC20 nor TRX address
        # may appear as a selectable method or create an invoice claim.
        for tag, field, method, network in [
            ('c','manualUsdtWalletTRC20','manual_usdt','TRC20'),
            ('t','manualTrxWallet','manual_trx','TRON')
        ]:
            key='checksumreject'+tag
            seed(work,env,key)
            original=crypto_keys[field]
            broken=original[:-1]+('1' if original[-1]!='1' else '2')
            bad_keys=dict(crypto_keys)
            bad_keys[field]=broken
            assert tag not in base(key,'crypto_options',keys=bad_keys)
            assert base(key,'claim',method,network=network,keys=bad_keys) is False
            assert query(work,env,
                f"SELECT COUNT(*) FROM payment_attempts WHERE order_hash='{key}'")=='0'
            assert query(work,env,
                f"SELECT state FROM pays WHERE hash_id='{key}'")=='pending'
            assert broken not in str(base(key,'read',method,keys=bad_keys)['body'])
            cases.append('tron_checksum_invalid_destination_denied_before_claim_'+tag)

        # Immutable per-order quoted asset/network/recipient/rate; no second
        # claim from same or competing provider, even after settings rotation.
        for tag,asset,network,currency in [
            ('b','manual_usdt','BEP20','USDT'),('c','manual_usdt','TRC20','USDT'),
            ('e','manual_usdt','ERC20','USDT'),('t','manual_trx','TRON','TRX')
        ]:
            hash_id='cryptochoice'+tag
            seed(work,env,hash_id)
            assert base(hash_id,'claim',asset,keys=crypto_keys,network=network)
            old_intent=query(work,env,
                f"SELECT checkout_url FROM payment_attempts WHERE order_hash='{hash_id}'")
            quote=query(work,env,
                f"SELECT quote_amount,quote_currency,settlement_currency FROM payment_attempts "
                f"WHERE order_hash='{hash_id}'").splitlines()[0].split('\t')
            assert quote[1:]==[currency,network],(tag,quote)
            assert base(hash_id,'claim',asset,keys=crypto_keys,network=network)
            other='TRON' if network!='TRON' else 'TRC20'
            assert base(hash_id,'claim',asset,keys=crypto_keys,network=other) is False
            rotated={k:('0x'+'3'*40 if v.startswith('0x') else
                         'TXT7aiRQCLiMgsMZgFceLYbQu3MynssYUK' if v.startswith('T') else v)
                     for k,v in crypto_keys.items()}
            rotated['manualUsdtRateToman']='200000'
            rotated['manualTrxRateToman']='30000'
            assert base(hash_id,'claim',asset,keys=rotated,network=network)
            assert query(work,env,
                f"SELECT checkout_url FROM payment_attempts WHERE order_hash='{hash_id}'")==old_intent
            assert query(work,env,
                f"SELECT COUNT(*) FROM payment_attempts WHERE order_hash='{hash_id}'")=='1'
            assert query(work,env,
                f"SELECT state FROM pays WHERE hash_id='{hash_id}'")=='pending'
            cases.append('manual_crypto_'+tag+'_immutable_network_quote_and_replay')

        # R1: retained historical payment evidence is never destroyed or
        # silently rewritten merely because its pinned destination is invalid.
        # No new customer instructions/receipt/claims for such a pending row.
        for tag, method, network, original in [
            ('c','manual_usdt','TRC20',crypto_keys['manualUsdtWalletTRC20']),
            ('t','manual_trx','TRON',crypto_keys['manualTrxWallet'])
        ]:
            key='historicalinvalid'+tag
            seed(work,env,key)
            assert base(key,'claim',method,network=network,keys=crypto_keys)
            pinned=json.loads(query(work,env,
                f"SELECT checkout_url FROM payment_attempts WHERE order_hash='{key}'"))
            assert pinned['wallet']==original
            broken=original[:-1]+('1' if original[-1]!='1' else '2')
            pinned['wallet']=broken
            tampered=json.dumps(pinned,separators=(',',':'))
            query(work,env,"UPDATE payment_attempts SET checkout_url='"+
                  tampered+f"' WHERE order_hash='{key}'")
            before_pay=query(work,env,
                f"SELECT state,price,payid FROM pays WHERE hash_id='{key}'")
            before_attempt=query(work,env,
                f"SELECT provider,stage,checkout_url FROM payment_attempts WHERE order_hash='{key}'")
            before_wallet=query(work,env,'SELECT userid,wallet FROM users ORDER BY userid')
            assert base(key,'claim',method,keys=crypto_keys,network=network) is False
            assert base(key,'claim',method,keys=crypto_keys) is False
            assert base(key,'allow',method) is False
            assert base(key,'begin',method) is None
            view=base(key,'read',method)
            assert view['body']=='' and broken not in view['caption']
            assert query(work,env,
                f"SELECT state,price,payid FROM pays WHERE hash_id='{key}'")==before_pay
            assert query(work,env,
                f"SELECT provider,stage,checkout_url FROM payment_attempts WHERE order_hash='{key}'")==before_attempt
            assert query(work,env,'SELECT userid,wallet FROM users ORDER BY userid')==before_wallet
            cases.append('historical_invalid_tron_destination_requires_review_'+tag)

        # Existing *valid* immutable evidence must remain usable after a bad
        # new administrator setting; never substitute or rewrite the recipient.
        for tag,method,network,field in [
            ('c','manual_usdt','TRC20','manualUsdtWalletTRC20'),
            ('t','manual_trx','TRON','manualTrxWallet')
        ]:
            key='cryptochoice'+tag
            initial=base(key,'read',method,keys=crypto_keys)['body']
            changed=dict(crypto_keys)
            changed[field]='T'+'A'*33 # invalid shape-only historical fixture
            assert base(key,'claim',method,network=network,keys=changed)
            assert base(key,'read',method,keys=changed)['body']==initial
            cases.append('existing_valid_tron_intent_survives_bad_new_config_'+tag)

        # F2: a direct cached wallet callback must honor an earlier manual claim.
        query(work,env,'UPDATE users SET wallet=1000000 WHERE userid=1001')
        for method in ('bank_transfer','gift_card','manual_usdt'):
            key='walletblock'+method.replace('_','')
            seed(work,env,key)
            query(work,env,f"UPDATE pays SET type='BUY_SUB' WHERE hash_id='{key}'")
            assert base(key,'claim',method)
            balance=int(query(work,env,'SELECT wallet FROM users WHERE userid=1001'))
            assert base(key,'wallet')['status']=='claimed_provider'
            assert int(query(work,env,'SELECT wallet FROM users WHERE userid=1001'))==balance
            assert query(work,env,f"SELECT state FROM pays WHERE hash_id='{key}'")=='pending'
            assert base(key,'wallet',user=9002)['status']=='invalid_owner'
            assert base(key,'claim',user=9002) is False
            cases.append('cached_wallet_button_blocked_after_'+method)

        # Reverse order: a successful wallet charge excludes later manual/Tron claims.
        seed(work,env,'walletfirst')
        query(work,env,"UPDATE pays SET type='BUY_SUB' WHERE hash_id='walletfirst'")
        debitBefore=int(query(work,env,'SELECT wallet FROM users WHERE userid=1001'))
        assert base('walletfirst','wallet')['status']=='ok'
        assert int(query(work,env,'SELECT wallet FROM users WHERE userid=1001'))==debitBefore-100000
        assert base('walletfirst','claim','bank_transfer') is False
        assert base('walletfirst','claim','manual_usdt') is False
        assert base('walletfirst','claim','manual_trx',network='TRON',keys={
            'manualTrxWallet':'TJRabPrwbZy45sbavfcjinPJC18kjpRTv8','manualTrxRateToman':'15000'}) is False
        cases.append('wallet_first_prevents_external_method_replacement')

        # Manual TRX shares the original guarded photo/approval flow.
        seed(work,env,'trxfirst')
        trxKeys={'manualTrxWallet':'TJRabPrwbZy45sbavfcjinPJC18kjpRTv8','manualTrxRateToman':'15000'}
        assert base('trxfirst','claim','manual_trx',network='TRON',keys=trxKeys)
        trx=base('trxfirst','read','manual_trx')
        assert trx['method']=='manual_trx' and 'TRX' in trx['body']
        assert 'TJRabPrwbZy45sbavfcjinPJC18kjpRTv8' in trx['body']
        assert 'ارسال رسید TRX' in str(trx['keys'])
        assert query(work,env,"SELECT quote_currency,settlement_currency FROM payment_attempts WHERE order_hash='trxfirst'")=='TRX\tTRON'
        assert base('trxfirst','claim','bank_transfer') is False
        assert base('trxfirst','wallet')['status']=='claimed_provider'
        assert base('trxfirst','claim','manual_trx',network='BEP20',keys=trxKeys) is False
        assert base('trxfirst','claim','manual_usdt') is False
        assert base('trxfirst','begin','manual_trx') is not None
        assert base('trxfirst','begin','manual_trx') is None
        assert query(work,env,"SELECT state FROM pays WHERE hash_id='trxfirst'")=='pending'
        cases.append('manual_trx_unique_quote_receipt_and_no_auto_settlement')

        # TRX receipts must remain manual-review-only, never bank auto-accepted.
        seed(work,env,'trxautoguard')
        assert base('trxautoguard','claim','manual_trx',network='TRON',keys=trxKeys)
        token=base('trxautoguard','begin','manual_trx')
        assert isinstance(token,str) and len(token)==32
        assert base('trxautoguard','finish','manual_trx',token=token)
        assert query(work,env,"SELECT state FROM pays WHERE hash_id='trxautoguard'")=='have_sent'
        before_automated=query(work,env,'SELECT userid,wallet FROM users ORDER BY userid')
        assert base('trxautoguard','bank_auto_eligible','manual_trx') is False
        assert base('trxautoguard','bank_auto_claim','manual_trx') is False
        assert query(work,env,"SELECT state FROM pays WHERE hash_id='trxautoguard'")=='have_sent'
        assert query(work,env,'SELECT userid,wallet FROM users ORDER BY userid')==before_automated
        cases.append('manual_trx_photo_never_enters_automatic_bank_approval')

        # The service operator path accepts the same reviewed, persisted
        # TRX image evidence, not an unreviewed URL/callback or old TXID.
        seed(work,env,'trxserviceguard')
        assert base('trxserviceguard','claim','manual_trx',network='TRON',keys=trxKeys)
        token=base('trxserviceguard','begin','manual_trx')
        assert base('trxserviceguard','finish','manual_trx',token=token)
        query(work,env,"UPDATE pays SET type='BUY_SUB',day=30 WHERE hash_id='trxserviceguard'")
        assert base('trxserviceguard','service_guard','manual_trx',subject='custom') is True
        assert base('trxserviceguard','service_guard','manual_trx',subject='renewal') is False
        cases.append('manual_trx_service_requires_reviewed_photo_and_correct_subject')

        seed(work,env,'manualfirsttron')
        assert base('manualfirsttron','claim','gift_card')
        assert base('manualfirsttron','claim','manual_trx',network='TRON',keys=trxKeys) is False
        cases.append('manual_trx_cannot_replace_existing_manual_receipt_claim')

        # A hosted provider claim excludes cached local wallet actions.
        seed(work,env,'hostedclaimed')
        query(work,env,"INSERT INTO payment_attempts (pay_row_id,provider,order_hash,quote_amount,quote_currency,local_amount_toman,stage) "
             "SELECT id,'nowpayments',hash_id,100000,'toman',price,'creating' FROM pays WHERE hash_id='hostedclaimed'")
        assert base('hostedclaimed','wallet')['status']=='claimed_provider'
        assert base('hostedclaimed','claim','manual_usdt') is False
        assert base('hostedclaimed','claim','manual_trx',network='TRON',keys={
            'manualTrxWallet':'TJRabPrwbZy45sbavfcjinPJC18kjpRTv8','manualTrxRateToman':'15000'}) is False
        assert query(work,env,"SELECT state FROM pays WHERE hash_id='hostedclaimed'")=='pending'
        cases.append('hosted_provider_claim_blocks_direct_wallet_tron_manual_replay')


        # The three legacy direct wallet routes (renewal/day/volume) bypass
        # processWalletPayment(), so test their real PHP early claim guard.
        for key,route,data_prefix,pay_type in [
            ('oldrenewwallet','renewal','payRenewWithWallet','RENEW_ACCOUNT'),
            ('olddaywallet','extension','payIncreaseDayWithWallet','INCREASE_DAY_1_2'),
            ('oldvolwallet','extension','payIncreaseWithWallet','INCREASE_VOLUME_1_2')
        ]:
            seed(work,env,key)
            query(work,env,f"UPDATE pays SET type='{pay_type}' WHERE hash_id='{key}'")
            assert base(key,'claim','manual_usdt')
            before=int(query(work,env,'SELECT wallet FROM users WHERE userid=1001'))
            refused=run_route(work,env,route_driver,
                data=data_prefix+key,route=route)
            assert any(e['name']=='alert' for e in refused),repr((key,refused))
            assert query(work,env,f"SELECT state FROM pays WHERE hash_id='{key}'")=='pending'
            assert int(query(work,env,'SELECT wallet FROM users WHERE userid=1001'))==before
            cases.append('direct_'+route+'_'+data_prefix+'_blocked_by_manual_claim')
            second=run_route(work,env,route_driver,
                data=data_prefix+key,actor=9002,route=route)
            assert any(e['name']=='alert' for e in second),repr((key,second))
            assert int(query(work,env,'SELECT wallet FROM users WHERE userid=1001'))==before
            cases.append('wrong_owner_direct_'+route+'_'+data_prefix+'_denied')

        # F1: native MariaDB wallet credit is in the same bank auto-claim
        # transaction; admin and timer may win in either order, never both.
        seed(work,env,'autoadminfirst')
        assert base('autoadminfirst','claim')
        query(work,env,"UPDATE pays SET state='have_sent' WHERE hash_id='autoadminfirst'")
        before=int(query(work,env,'SELECT wallet FROM users WHERE userid=1001'))
        assert base('autoadminfirst','approve') is not None
        assert base('autoadminfirst','bank_auto_claim') is False
        assert int(query(work,env,'SELECT wallet FROM users WHERE userid=1001'))==before+100000
        assert query(work,env,"SELECT state FROM pays WHERE hash_id='autoadminfirst'")=='approved'
        cases.append('admin_before_scheduled_bank_accept_exactly_one_credit')

        seed(work,env,'autoautofirst')
        assert base('autoautofirst','claim')
        query(work,env,"UPDATE pays SET state='have_sent' WHERE hash_id='autoautofirst'")
        before=int(query(work,env,'SELECT wallet FROM users WHERE userid=1001'))
        assert base('autoautofirst','bank_auto_claim') is True
        assert base('autoautofirst','approve') is None
        assert base('autoautofirst','bank_auto_claim') is False
        assert int(query(work,env,'SELECT wallet FROM users WHERE userid=1001'))==before+100000
        assert query(work,env,"SELECT state FROM pays WHERE hash_id='autoautofirst'")=='auto_cart_approved'
        cases.append('scheduled_before_admin_bank_approval_exactly_one_credit')

        from concurrent.futures import ThreadPoolExecutor
        for n in range(6):
            key=f'autocontest{n}'
            seed(work,env,key)
            assert base(key,'claim')
            query(work,env,f"UPDATE pays SET state='have_sent' WHERE hash_id='{key}'")
            before=int(query(work,env,'SELECT wallet FROM users WHERE userid=1001'))
            with ThreadPoolExecutor(max_workers=2) as executor:
                fauto=executor.submit(lambda:base(key,'bank_auto_claim'))
                fadmin=executor.submit(lambda:base(key,'approve'))
                auto_won=fauto.result();admin_won=fadmin.result() is not None
            assert int(auto_won)+int(admin_won)==1,(key,auto_won,admin_won)
            assert int(query(work,env,'SELECT wallet FROM users WHERE userid=1001'))==before+100000
            assert query(work,env,f"SELECT state FROM pays WHERE hash_id='{key}'") in ('approved','auto_cart_approved')
            assert base(key,'bank_auto_claim') is False
        cases.append('six_native_bank_timer_admin_concurrency_races_exactly_once')

        # The exclusive service claim can be granted only once even when
        # two distinct process workers race. No external Marzban side effect.
        seed(work,env,'autoservice')
        query(work,env,"UPDATE pays SET type='BUY_SUB' WHERE hash_id='autoservice'")
        assert base('autoservice','claim')
        query(work,env,"UPDATE pays SET state='have_sent' WHERE hash_id='autoservice'")
        with ThreadPoolExecutor(max_workers=2) as executor:
            claims=list(executor.map(lambda _:base('autoservice','bank_auto_claim'),range(2)))
        assert claims.count(True)==1 and claims.count(False)==1
        assert base('autoservice','bank_auto_claim') is False
        cases.append('scheduled_service_effect_claimed_only_once')

        # A card receipt predating the shared ledger stays eligible for the
        # inherited timer; an arbitrary unknown provider never does.
        seed(work,env,'autolegacybank')
        query(work,env,"UPDATE pays SET state='have_sent' WHERE hash_id='autolegacybank'")
        assert base('autolegacybank','bank_auto_claim') is True
        assert base('autolegacybank','bank_auto_claim') is False
        cases.append('verified_legacy_card_receipt_eligible_and_single_credit')
        seed(work,env,'autounknown')
        query(work,env,"UPDATE pays SET state='have_sent' WHERE hash_id='autounknown'")
        query(work,env,"INSERT INTO payment_attempts (pay_row_id,provider,order_hash,quote_amount,quote_currency,local_amount_toman) "
             "SELECT id,'unknown_method',hash_id,100000,'toman',price FROM pays WHERE hash_id='autounknown'")
        assert base('autounknown','bank_auto_claim') is False
        cases.append('unknown_attempt_provider_never_bank_auto_approves')



        # Execute the actual scheduler include (not only its claim helper)
        # with an isolated eligible bank receipt, twice, checking money and state.
        query(work,env,"UPDATE pays SET request_date=UNIX_TIMESTAMP() WHERE state='have_sent'")
        seed(work,env,'schedulerrealbank')
        assert base('schedulerrealbank','claim')
        query(work,env,"UPDATE pays SET state='have_sent' WHERE hash_id='schedulerrealbank'")
        before=int(query(work,env,'SELECT wallet FROM users WHERE userid=1001'))
        assert base('schedulerrealbank','auto')=='tested'
        assert query(work,env,"SELECT state FROM pays WHERE hash_id='schedulerrealbank'")=='auto_cart_approved'
        assert int(query(work,env,'SELECT wallet FROM users WHERE userid=1001'))==before+100000
        assert base('schedulerrealbank','auto')=='tested'
        assert int(query(work,env,'SELECT wallet FROM users WHERE userid=1001'))==before+100000
        cases.append('real_auto_scheduler_bank_credit_once_across_repeated_runs')

        # F3: injected method lookup outage while the rest of the database
        # remains healthy must NOT authorize a legacy bank settlement.
        seed(work,env,'lookupfailure')
        query(work,env,"UPDATE pays SET state='have_sent' WHERE hash_id='lookupfailure'")
        before=int(query(work,env,'SELECT wallet FROM users WHERE userid=1001'))
        seed(work,env,'partialwallet')
        query(work,env,'RENAME TABLE payment_attempts TO payment_attempts_unavailable')
        try:
            assert base('lookupfailure','bank_auto_claim',expect_deferred=True) is False
            assert base('lookupfailure','auto',expect_deferred=True)=='tested'
            assert query(work,env,"SELECT state FROM pays WHERE hash_id='lookupfailure'")=='have_sent'
            assert int(query(work,env,'SELECT wallet FROM users WHERE userid=1001'))==before
            # 003 has two tables; one missing is corruption, not legacy V9.
            assert base('partialwallet','wallet')['status']=='claimed_provider'
            assert query(work,env,"SELECT state FROM pays WHERE hash_id='partialwallet'")=='pending'
            assert int(query(work,env,'SELECT wallet FROM users WHERE userid=1001'))==before
        finally:
            query(work,env,'RENAME TABLE payment_attempts_unavailable TO payment_attempts')
        cases.append('method_lookup_error_never_approves_legacy_card_or_mutates_money')
        cases.append('partially_missing_003_ledger_blocks_cached_wallet_debit')

        # F4: failed, uncertain, and exception Telegram responses preserve
        # the exact file ID and can be retried without claiming a new method.
        for key,method,wait,mode in [
            ('photofailbank','bank_transfer','waitIncreaseWalletReceipt','throw'),
            ('photofailgift','gift_card','waitIncreaseWalletGiftReceipt','no_id'),
            ('photofailusdt','manual_usdt','waitIncreaseWalletUsdtReceipt','response_lost')
        ]:
            seed(work,env,key)
            assert base(key,'claim',method)
            balance=int(query(work,env,'SELECT wallet FROM users WHERE userid=1001'))
            failed=run_route(work,env,route_driver,step=wait+key,photo_mode=mode)
            assert any(e['name']=='sendPhoto' for e in failed),repr((mode,failed))
            assert any(e['name']=='sendMessage' and 'دوباره بفرستید' in str(e.get('args'))
                       for e in failed),repr((mode,failed))
            assert not any(e['name']=='sendMessage' and 'در انتظار بررسی مدیر است' in str(e.get('args'))
                           for e in failed)
            assert not any(e['name']=='setUser' for e in failed)
            assert query(work,env,f"SELECT state FROM pays WHERE hash_id='{key}'")=='pending'
            assert query(work,env,f"SELECT stage FROM payment_attempts WHERE order_hash='{key}'")=='receiving'
            intent=json.loads(query(work,env,
                f"SELECT checkout_url FROM payment_attempts WHERE order_hash='{key}'"))
            assert intent['receipt_file_id']=='fixture-photo-id'
            assert int(query(work,env,'SELECT wallet FROM users WHERE userid=1001'))==balance
            cases.append('durable_photo_and_retry_guidance_on_'+mode)

            # New photos are not allowed to replace potentially delivered evidence.
            other={'message':{'photo':[{'file_id':'other-photo-id'}]}}
            changed=run_route(work,env,route_driver,step=wait+key,update=other)
            assert not any(e['name']=='sendPhoto' for e in changed)
            assert query(work,env,f"SELECT state FROM pays WHERE hash_id='{key}'")=='pending'
            cases.append('uncertain_delivery_different_photo_denied_'+method)

            retried=run_route(work,env,route_driver,step=wait+key)
            assert any(e['name']=='sendPhoto' for e in retried)
            assert any(e['name']=='sendMessage' and 'در انتظار بررسی مدیر است' in str(e.get('args'))
                       for e in retried)
            assert query(work,env,f"SELECT state FROM pays WHERE hash_id='{key}'")=='have_sent'
            assert query(work,env,f"SELECT stage FROM payment_attempts WHERE order_hash='{key}'")=='review'
            assert int(query(work,env,'SELECT wallet FROM users WHERE userid=1001'))==balance
            cases.append('same_photo_recovery_after_uncertain_delivery_'+method)

            approved=run_route(work,env,route_driver,data='approvePayment'+key,
                               actor=9001,route='manual_admin')
            assert any(e['name']=='sendMessage' and 'تایید' in str(e.get('args'))
                       for e in approved)
            repeat=run_route(work,env,route_driver,data='approvePayment'+key,
                             actor=9001,route='manual_admin')
            assert any(e['name']=='alert' for e in repeat)
            assert int(query(work,env,'SELECT wallet FROM users WHERE userid=1001'))==balance+100000
            cases.append('uncertain_telegram_delivery_never_double_credits_'+method)

        # Exercise the real network-selection router, not a copied key mapper.
        seed(work,env,'menureal',price=345678)
        menu=run_route(work,env,route_driver,data='mcm_menureal',
            route='manual_crypto',keys=crypto_keys)
        text_changes=[x for x in menu if x['name']=='editText']
        assert text_changes,repr(menu)
        menu_keyboard=json.loads(text_changes[-1]['args'][2])
        assert [r[0]['callback_data'] for r in menu_keyboard['inline_keyboard'][:4]]==[
            'mc_b_menureal','mc_c_menureal','mc_e_menureal','mc_t_menureal']
        assert query(work,env,"SELECT COUNT(*) FROM payment_attempts WHERE order_hash='menureal'")=='0'
        cases.append('real_telegram_manual_crypto_menu_four_choices_no_claim')
        # Execute each USDT network as an actual Telegram callback instead
        # of trusting the menu labels or a copied network/asset map.
        for tag,network,value in [('b','BEP20',212001),
                                  ('c','TRC20',212002),
                                  ('e','ERC20',212003)]:
            hash_id='usdtbutton'+tag
            seed(work,env,hash_id,price=value)
            selected=run_route(work,env,route_driver,
                data='mc_'+tag+'_'+hash_id,route='manual_crypto',keys=crypto_keys)
            assert any(e['name']=='sendMessage' and network in str(e['args'])
                       for e in selected),repr((tag,selected))
            assert query(work,env,
                "SELECT provider,quote_currency,settlement_currency,stage "
                f"FROM payment_attempts WHERE order_hash='{hash_id}'"
            )=='manual_usdt\tUSDT\t'+network+'\tpending'
            assert query(work,env,f"SELECT state FROM pays WHERE hash_id='{hash_id}'")=='pending'
            cases.append('real_telegram_manual_usdt_'+tag+'_network_pinned')

        seed(work,env,'cryptoforged',price=313333)
        disabled=dict(crypto_keys)
        disabled.pop('manualUsdtWalletTRC20')
        blocked_missing=run_route(work,env,route_driver,
            data='mc_c_cryptoforged',route='manual_crypto',keys=disabled)
        assert any(e['name']=='answerCallbackQuery' for e in blocked_missing)
        assert query(work,env,
            "SELECT COUNT(*) FROM payment_attempts WHERE order_hash='cryptoforged'")=='0'
        cases.append('forged_unconfigured_network_never_claimed')

        blocked=run_route(work,env,route_driver,data='mc_t_menureal',
            route='manual_crypto',actor=9002,keys=crypto_keys)
        assert any(x['name']=='answerCallbackQuery' for x in blocked)
        assert query(work,env,"SELECT COUNT(*) FROM payment_attempts WHERE order_hash='menureal'")=='0'
        cases.append('real_telegram_crypto_pick_unauthorized_owner_denied')
        selected=run_route(work,env,route_driver,data='mc_t_menureal',
            route='manual_crypto',keys=crypto_keys)
        assert any(x['name']=='sendMessage' and 'TRX' in str(x['args']) for x in selected)
        assert query(work,env,"SELECT provider,quote_currency,stage FROM payment_attempts "
            "WHERE order_hash='menureal'")=='manual_trx\tTRX\tpending'
        assert query(work,env,"SELECT state FROM pays WHERE hash_id='menureal'")=='pending'
        cases.append('real_telegram_manual_trx_choice_routes_to_wallet_topup_receipt')
        receipt=run_route(work,env,route_driver,
            data='sendIncreaseWalletUsdtReceiptmenureal',route='wallet')
        assert any(x['name']=='sendMessage' and 'TRX' in str(x['args'])
                   for x in receipt),repr(receipt)
        uploaded=run_route(work,env,route_driver,step='waitIncreaseWalletUsdtReceiptmenureal')
        assert any(x['name']=='sendPhoto' for x in uploaded),repr(uploaded)
        assert query(work,env,"SELECT state FROM pays WHERE hash_id='menureal'")=='have_sent'
        before=int(query(work,env,"SELECT wallet FROM users WHERE userid=1001"))
        approved=run_route(work,env,route_driver,
            data='approvePaymentmenureal',actor=9001,route='manual_admin')
        assert any(x['name']=='sendMessage' for x in approved)
        assert int(query(work,env,"SELECT wallet FROM users WHERE userid=1001"))==before+345678
        repeated=run_route(work,env,route_driver,
            data='approvePaymentmenureal',actor=9001,route='manual_admin')
        assert any(x['name']=='alert' for x in repeated)
        assert int(query(work,env,"SELECT wallet FROM users WHERE userid=1001"))==before+345678
        cases.append('real_telegram_trx_manual_review_credits_wallet_once_only')
        older=run_route(work,env,route_driver,
            data='payWithTronWalletmenureal',route='crypto_retired')
        assert any(x['name']=='answerCallbackQuery' for x in older)
        assert not any(x['name']=='sendPhoto' or x['name']=='sendMessage' for x in older)
        cases.append('stale_tron_auto_callback_retired_fail_closed')

        # Database transition failure must preserve the committed receipt
        # evidence and allow the SAME photo to be retried.
        seed(work,env,'photodbfail')
        assert base('photodbfail','claim','bank_transfer')
        query(work,env,"CREATE TRIGGER test_receipt_db_failure BEFORE UPDATE ON pays "
             "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture_failure'")
        try:
            failed=run_route(work,env,route_driver,step='waitIncreaseWalletReceiptphotodbfail')
            assert any(e['name']=='sendPhoto' for e in failed)
            assert query(work,env,"SELECT state FROM pays WHERE hash_id='photodbfail'")=='pending'
            assert query(work,env,"SELECT stage FROM payment_attempts WHERE order_hash='photodbfail'")=='receiving'
        finally:
            query(work,env,'DROP TRIGGER test_receipt_db_failure')
        recovered=run_route(work,env,route_driver,step='waitIncreaseWalletReceiptphotodbfail')
        assert any(e['name']=='sendMessage' and 'در انتظار بررسی مدیر است' in str(e.get('args'))
                   for e in recovered)
        assert query(work,env,"SELECT state FROM pays WHERE hash_id='photodbfail'")=='have_sent'
        cases.append('database_transition_failure_retries_same_durable_photo')

        # A concurrently declined/terminal payment cannot be resurrected
        # by an outdated Telegram send completion.
        seed(work,env,'phototerminal')
        assert base('phototerminal','claim','manual_usdt')
        failed=run_route(work,env,route_driver,
                         step='waitIncreaseWalletUsdtReceiptphototerminal',photo_mode='terminal_change')
        assert any(e['name']=='sendPhoto' for e in failed)
        assert query(work,env,"SELECT state FROM pays WHERE hash_id='phototerminal'")=='declined'
        assert query(work,env,"SELECT stage FROM payment_attempts WHERE order_hash='phototerminal'")=='receiving'
        assert base('phototerminal','approve') is None
        assert base('phototerminal','bank_auto_claim') is False
        cases.append('late_telegram_response_cannot_revert_terminal_payment')

        # Unexpected worker loss: a bounded lease expires, allowing the
        # identical durable file ID to be delivered by a new worker.
        seed(work,env,'leaseexpiry')
        assert base('leaseexpiry','claim','gift_card')
        assert isinstance(base('leaseexpiry','begin','gift_card'),str)
        blocked=run_route(work,env,route_driver,step='waitIncreaseWalletGiftReceiptleaseexpiry')
        assert not any(e['name']=='sendPhoto' for e in blocked)
        query(work,env,"UPDATE payment_attempts SET review_notice_claimed_at="
              "DATE_SUB(NOW(),INTERVAL 3 MINUTE) WHERE order_hash='leaseexpiry'")
        recovered=run_route(work,env,route_driver,step='waitIncreaseWalletGiftReceiptleaseexpiry')
        assert any(e['name']=='sendPhoto' for e in recovered)
        assert query(work,env,"SELECT state FROM pays WHERE hash_id='leaseexpiry'")=='have_sent'
        cases.append('lost_worker_recovered_after_lease_without_new_photo')

        # P1 + R1-01: test real Telegram UpdateBindings, persisted users.step,
        # actual top-up route, and atomic pays INSERT against socket-only MariaDB.
        seed(work,env,'topupissuedold',price=200000)
        assert base('topupissuedold','claim','bank_transfer') is True
        query(work,env,"UPDATE pays SET payid='synthetic-issued-invoice' "
              "WHERE hash_id='topupissuedold'")
        issued_before=query(work,env,
            "SELECT id,hash_id,payid,state,price FROM pays "
            "WHERE hash_id='topupissuedold'")
        attempt_before=query(work,env,
            "SELECT id,pay_row_id,provider,order_hash,stage FROM payment_attempts "
            "WHERE order_hash='topupissuedold'")
        def topup_snapshot():
            return (
                query(work,env,"SELECT * FROM pays ORDER BY id"),
                query(work,env,"SELECT * FROM payment_attempts ORDER BY id"),
                query(work,env,"SELECT * FROM payment_transactions ORDER BY id"),
                query(work,env,"SELECT userid,wallet,step FROM users ORDER BY id"),
            )
        def get_topup_step():
            return query(work,env,'SELECT step FROM users WHERE userid=1001')
        def put_topup_step(value):
            assert value.isascii() and "'" not in value
            query(work,env,"UPDATE users SET step='"+value+"' WHERE userid=1001")
        def choose_ready():
            put_topup_step('achbotWalletTopup:choose:990001:500:1700000000')
        def amount_ready():
            put_topup_step('achbotWalletTopup:amount:990002:510:1700000000')
        def customer(user=1001):
            return {'id':user,'is_bot':False,'username':'fixture_customer',
                    'first_name':'Fixture'}
        def message_update(text,chat_type='private',chat_id=1001,user=1001,
                           update_id=990003,message_id=520):
            return {'update_id':update_id,'message':{
                'message_id':message_id,'from':customer(user),
                'chat':{'id':chat_id,'type':chat_type},'text':text}}
        def callback_update(text,data,chat_type='private',chat_id=1001,user=1001,
                            update_id=990002,message_id=500):
            return {'update_id':update_id,'callback_query':{
                'id':'fixture-callback','from':customer(user),'data':data,
                'message':{'message_id':message_id,
                           'chat':{'id':chat_id,'type':chat_type},
                           'text':text,'reply_markup':{'inline_keyboard':[]}}}}
        def open_menu(update_id=990001):
            put_topup_step('none')
            wire=callback_update('Original bot main menu','increaseMyWallet',
                                 update_id=update_id,message_id=111)
            events=run_route(work,env,route_driver,route='topup',
                             data='increaseMyWallet',update=wire,
                             bot_reply_message_id=500)
            assert get_topup_step().startswith(f'achbotWalletTopup:choose:{update_id}:500:'),events
            assert any(e['name']=='sendMessage' for e in events)
            return events
        def open_custom():
            opened=run_route(work,env,route_driver,route='topup',
                             data='increaseWalletCustom',
                             update=callback_update('Pick custom','increaseWalletCustom'),
                             bot_reply_message_id=510)
            assert get_topup_step().startswith('achbotWalletTopup:amount:990002:510:'),opened
            return opened

        malformed_callbacks=[
            'increaseWallet_100000junk','increaseWallet_100000.5',
            'increaseWallet_1e5','increaseWallet_100000_200000',
            'increaseWallet_','increaseWallet_-100000',
            'increaseWallet_0','increaseWallet_50000','increaseWallet_100001',
            'increaseWallet_10000001','increaseWallet_99999999999999999999999',
            'increaseWallet_ 100000','increaseWallet_+100000',
        ]
        malformed_texts=[
            '100000.5','100000.0','1e5','100000junk','+100000',
            '-100000',' 100000','100000 ','100,000',
            '۰۱۰۰۰۰۰','', '0','50000','100001','10000001',
            '99999999999999999999999',
        ]
        for i,(kind,raw) in enumerate(
            [('callback',x) for x in malformed_callbacks] +
            [('text',x) for x in malformed_texts]
        ):
            seed(work,env,'topupdraft'+str(i),price=150000)
            choose_ready() if kind=='callback' else amount_ready()
            before=topup_snapshot()
            options=({'data':raw} if kind=='callback' else {'text':raw})
            events=run_route(work,env,route_driver,route='topup',**options)
            assert topup_snapshot()==before,(kind,raw,'payment state mutated')
            assert any(e['name']=='sendMessage' for e in events),(kind,raw,events)
            assert not any(e['name'] in ('remote_create','remote_edit','sendPhoto','setUser')
                           for e in events),(kind,raw,events)
            assert not any(e['name']=='sendMessage' and
                           'اطلاعات شارژ' in str(e['args']) for e in events)
            cases.append('real_topup_raw_'+kind+'_rejected_'+str(i))
        assert query(work,env,
            "SELECT id,hash_id,payid,state,price FROM pays "
            "WHERE hash_id='topupissuedold'")==issued_before
        assert query(work,env,
            "SELECT id,pay_row_id,provider,order_hash,stage FROM payment_attempts "
            "WHERE order_hash='topupissuedold'")==attempt_before

        denied_origin_cases=[
            ('group_message_denied','',message_update('100000','group',-4100)),
            ('supergroup_message_denied','',message_update('100000','supergroup',-1004100)),
            ('old_callback_text_denied','unrelatedCallback',
             callback_update('100000','unrelatedCallback')),
            ('foreign_chat_callback_denied','unrelatedCallback',
             callback_update('100000','unrelatedCallback','supergroup',-1004100)),
            ('group_custom_start_denied','increaseWalletCustom',
             callback_update('Old prompt','increaseWalletCustom','group',-4100)),
            ('group_fixed_amount_denied','increaseWallet_100000',
             callback_update('Old prompt','increaseWallet_100000','group',-4100)),
            ('other_actor_message_denied','',message_update('100000',user=1002)),
            ('bot_authored_message_denied','',{
                'update_id':990003,'message':{'message_id':520,
                    'from':{'id':1001,'is_bot':True,'first_name':'FixtureBot'},
                    'chat':{'id':1001,'type':'private'},'text':'100000'}}),
            ('mixed_callback_message_denied','unrelatedCallback',{
                **message_update('100000'),
                'callback_query':callback_update('100000','unrelatedCallback')['callback_query']}),
        ]
        for i,(name,raw_data,wire_update) in enumerate(denied_origin_cases):
            seed(work,env,'topuporigin'+str(i),price=150000)
            choose_ready() if ('_start' in name or 'fixed_amount' in name) else amount_ready()
            before=topup_snapshot()
            events=run_route(work,env,route_driver,route='topup',
                             data=raw_data,text='100000',update=wire_update)
            assert topup_snapshot()==before,(name,'payment state mutated')
            assert not any(e['name'] in ('delMessage','setUser',
                       'remote_create','remote_edit','sendPhoto')
                       for e in events),(name,events)
            assert not any(e['name']=='sendMessage' and
                           'اطلاعات شارژ' in str(e['args']) for e in events),(name,events)
            cases.append('real_topup_origin_'+name)

        # Full genuine private flow, including verified *outgoing* message IDs.
        open_menu()
        cases.append('real_topup_origin_private_menu_start_accepted')
        open_custom()
        cases.append('real_topup_origin_private_custom_start_accepted')
        result=run_route(work,env,route_driver,route='topup',text='150000')
        assert any(e['name']=='sendMessage' and 'اطلاعات شارژ' in str(e['args'])
                   for e in result),result
        assert get_topup_step().startswith('achbotWalletTopup:done:990003:520:')
        assert query(work,env,"SELECT price FROM pays WHERE type='INCREASE_WALLET' "
                     "AND state='pending' AND user_id=1001 AND payid IS NULL "
                     "ORDER BY id DESC LIMIT 1")=='150000'
        assert query(work,env,
            "SELECT id,hash_id,payid,state,price FROM pays "
            "WHERE hash_id='topupissuedold'")==issued_before
        assert query(work,env,
            "SELECT id,pay_row_id,provider,order_hash,stage FROM payment_attempts "
            "WHERE order_hash='topupissuedold'")==attempt_before
        cases.append('real_topup_origin_private_customer_text_accepted')
        after=topup_snapshot()
        retry=run_route(work,env,route_driver,route='topup',text='150000')
        assert topup_snapshot()==after and not any(
            e['name']=='sendMessage' and 'اطلاعات شارژ' in str(e['args']) for e in retry)
        cases.append('real_topup_replayed_private_amount_no_second_invoice')

        # An old text update received AFTER the new callback must fail closed.
        amount_ready()
        for name,wire in [
            ('out_of_order_older_update',message_update('150000',update_id=990001,message_id=509)),
            ('old_private_message_before_prompt',message_update('150000',update_id=990003,message_id=509)),
            ('exact_prompt_message_id_not_new',message_update('150000',update_id=990003,message_id=510)),
        ]:
            before=topup_snapshot()
            events=run_route(work,env,route_driver,route='topup',update=wire)
            assert topup_snapshot()==before,(name,'mutated state')
            assert not any(e['name']=='sendMessage' and 'اطلاعات شارژ' in str(e['args'])
                           for e in events)
            cases.append('real_topup_'+name+'_no_financial_effect')

        # The Bot API may assign a lower random update_id after >=7 days of
        # inactivity. A genuinely newer private message remains acceptable
        # because its per-chat message_id postdates the SENT bot prompt.
        put_topup_step('achbotWalletTopup:amount:990002:510:1700000000')
        reset_input=message_update('350000',update_id=7,message_id=520)
        result=run_route(work,env,route_driver,route='topup',update=reset_input)
        assert any(e['name']=='sendMessage' and 'اطلاعات شارژ' in str(e['args'])
                   for e in result),result
        assert get_topup_step().startswith('achbotWalletTopup:done:7:520:')
        assert query(work,env,"SELECT price FROM pays WHERE type='INCREASE_WALLET' "
                     "AND state='pending' AND user_id=1001 AND payid IS NULL "
                     "ORDER BY id DESC LIMIT 1")=='350000'
        cases.append('real_topup_week_idle_update_id_reset_fresh_private_amount')
        put_topup_step('achbotWalletTopup:done:990003:520:1700000000')
        restart=run_route(work,env,route_driver,route='topup',data='increaseMyWallet',
                          update=callback_update('Old home menu','increaseMyWallet',
                                                 update_id=7,message_id=111))
        assert get_topup_step().startswith('achbotWalletTopup:choose:7:500:')
        assert any(e['name']=='sendMessage' for e in restart)
        cases.append('real_topup_week_idle_random_reset_allows_new_menu')

        # A replacement/abandoned workflow invalidates the old claimed step.
        put_topup_step('anotherUserWorkflow')
        before=topup_snapshot()
        run_route(work,env,route_driver,route='topup',text='150000')
        assert topup_snapshot()==before
        cases.append('real_topup_replaced_step_rejects_stale_input')

        # Repeated menu event cannot replace a newer one; an old button
        # cannot authorize payment through a newer menu's message identity.
        open_menu()
        before=topup_snapshot()
        retry=run_route(work,env,route_driver,route='topup',data='increaseMyWallet')
        assert topup_snapshot()==before and not any(e['name']=='sendMessage' for e in retry)
        cases.append('real_topup_menu_update_replay_no_reissue')
        stale_button=callback_update('Old choices','increaseWallet_100000',
                                      update_id=990005,message_id=499)
        denied=run_route(work,env,route_driver,route='topup',
                         data='increaseWallet_100000',update=stale_button)
        assert topup_snapshot()==before
        assert not any(e['name']=='sendMessage' and 'اطلاعات شارژ' in str(e['args'])
                       for e in denied)
        cases.append('real_topup_old_fixed_button_wrong_prompt_denied')

        older_menu=callback_update('Old home menu','increaseMyWallet',
                                   update_id=990000,message_id=111)
        before=topup_snapshot()
        events=run_route(work,env,route_driver,route='topup',
                         data='increaseMyWallet',update=older_menu)
        assert topup_snapshot()==before and not any(e['name']=='sendMessage' for e in events)
        cases.append('real_topup_out_of_order_old_menu_cannot_replace_new_stage')

        # Fixed-amount choice must still work through the actual new menu.
        fixed=run_route(work,env,route_driver,route='topup',data='increaseWallet_100000')
        assert any(e['name']=='sendMessage' and 'اطلاعات شارژ' in str(e['args'])
                   for e in fixed),fixed
        after=topup_snapshot()
        retry=run_route(work,env,route_driver,route='topup',data='increaseWallet_100000')
        assert topup_snapshot()==after and not any(
            e['name']=='sendMessage' and 'اطلاعات شارژ' in str(e['args']) for e in retry)
        cases.append('real_topup_fixed_callback_consumed_once')

        # All previously accepted boundary amounts remain allowed.
        for i,(kind,raw,expected) in enumerate([
            ('callback','increaseWallet_100000',100000),
            ('text','10000000',10000000),
            ('text','000100000',100000),
            ('callback','increaseWallet_10000000',10000000),
        ]):
            choose_ready() if kind=='callback' else amount_ready()
            options=({'data':raw} if kind=='callback' else {'text':raw})
            events=run_route(work,env,route_driver,route='topup',**options)
            assert any(e['name']=='sendMessage' and 'اطلاعات شارژ' in str(e['args'])
                       for e in events),(kind,raw,events)
            assert not any(e['name'] in ('remote_create','remote_edit','sendPhoto')
                           for e in events)
            assert query(work,env,
                "SELECT id,hash_id,payid,state,price FROM pays "
                "WHERE hash_id='topupissuedold'")==issued_before
            assert query(work,env,
                "SELECT id,pay_row_id,provider,order_hash,stage FROM payment_attempts "
                "WHERE order_hash='topupissuedold'")==attempt_before
            assert query(work,env,
                "SELECT price FROM pays WHERE type='INCREASE_WALLET' "
                "AND state='pending' AND user_id=1001 AND payid IS NULL "
                "ORDER BY id DESC LIMIT 1")==str(expected),(kind,raw)
            cases.append('real_topup_raw_'+kind+'_accepted_'+str(i))

        # No unconfirmed sendMessage may create an active selectable stage.
        put_topup_step('none')
        before=topup_snapshot()
        run_route(work,env,route_driver,route='topup',data='increaseMyWallet',
                  message_transport='no_id',
                  expected_warning='achbot_wallet_topup_menu_delivery_unconfirmed')
        assert get_topup_step().startswith('achbotWalletTopup:opening:990001:111:')
        assert topup_snapshot()[:3]==before[:3]
        assert not any(e['name']=='sendMessage' and 'اطلاعات شارژ' in str(e['args'])
                       for e in run_route(work,env,route_driver,route='topup',
                                           data='increaseWallet_100000'))
        cases.append('real_topup_unconfirmed_bot_menu_fails_closed')

        # Duplicate historical userid rows fail closed rather than choosing
        # a random row; no wallet, ledger, pending invoice, or step mutation.
        choose_ready()
        query(work,env,"INSERT INTO users (userid,name,username,refcode,wallet,date,step) "
              "VALUES (1001,'Fixture duplicate','duplicate','0',0,'1700000000','none')")
        before=topup_snapshot()
        denied=run_route(work,env,route_driver,route='topup',data='increaseWallet_100000')
        assert topup_snapshot()==before and not any(
            e['name']=='sendMessage' and 'اطلاعات شارژ' in str(e['args']) for e in denied)
        query(work,env,"DELETE FROM users WHERE name='Fixture duplicate' AND userid=1001")
        cases.append('real_topup_ambiguous_customer_identity_fails_closed')

        # Transactional insertion failure MUST restore BOTH the original
        # unclaimed draft and the exact pending step, including under mysqli
        # nonthrowing mode. Provider-claimed rows always remain unchanged.
        seed(work,env,'topupatomicold',price=150000)
        amount_ready()
        before=topup_snapshot()
        query(work,env,"CREATE TRIGGER test_topup_fail_insert BEFORE INSERT ON pays "
              "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic_failure'")
        try:
            events=run_route(work,env,route_driver,route='topup',text='200000',
                             expected_review=True)
            assert topup_snapshot()==before,('insert_rollback','partial financial mutation')
            assert any('نیازمند بررسی' in str(e.get('args')) for e in events)
            cases.append('real_topup_insert_failure_rolls_back_claim_and_cleanup')
        finally:
            query(work,env,'DROP TRIGGER test_topup_fail_insert')

        # Legacy mysqli non-throw mode must also roll back on DELETE errors.
        # The shared cleaner must not silently return success after a failed
        # DELETE, then let the new payment INSERT commit.
        amount_ready()
        before=topup_snapshot()
        query(work,env,"CREATE TRIGGER test_topup_fail_delete BEFORE DELETE ON pays "
              "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic_delete_failure'")
        try:
            events=run_route(work,env,route_driver,route='topup',text='200000',
                             expected_review=True,mysqli_report_off=True)
            assert topup_snapshot()==before,('delete_rollback','partial financial mutation')
            assert any('نیازمند بررسی' in str(e.get('args')) for e in events)
            cases.append('real_topup_mysqli_report_off_delete_failure_rolls_back')
        finally:
            query(work,env,'DROP TRIGGER test_topup_fail_delete')

        # A lost Telegram acknowledgment after COMMIT does not repeat SQL.
        amount_ready()
        emitted=run_route(work,env,route_driver,route='topup',text='300000',
                          message_transport='throw',
                          expected_warning='achbot_wallet_topup_delivery_deferred')
        assert get_topup_step().startswith('achbotWalletTopup:done:990003:520:')
        assert query(work,env,"SELECT price FROM pays WHERE type='INCREASE_WALLET' "
                     "AND user_id=1001 AND state='pending' AND payid IS NULL "
                     "ORDER BY id DESC LIMIT 1")=='300000'
        after=topup_snapshot()
        run_route(work,env,route_driver,route='topup',text='300000')
        assert topup_snapshot()==after
        cases.append('real_topup_postcommit_telegram_failure_never_repeats_invoice')

        # Two real PHP + MariaDB sessions race for the SAME pending step. Only
        # one can claim, delete the previous draft and INSERT a new invoice.
        amount_ready()
        seed(work,env,'topupconcurrentold',price=150000)
        with ThreadPoolExecutor(max_workers=2) as executor:
            futures=[executor.submit(run_route,work,env,route_driver,route='topup',
                                     text='250000',pause_before_insert_ms=250)
                     for _ in range(2)]
            results=[f.result() for f in futures]
        successes=sum(any(e['name']=='sendMessage' and 'اطلاعات شارژ' in str(e['args'])
                          for e in events) for events in results)
        assert successes==1,(successes,results)
        assert get_topup_step().startswith('achbotWalletTopup:done:990003:520:')
        assert int(query(work,env,"SELECT COUNT(*) FROM pays p WHERE p.type='INCREASE_WALLET' "
            "AND p.user_id=1001 AND p.state='pending' AND (p.payid IS NULL OR p.payid='') "
            "AND NOT EXISTS (SELECT 1 FROM payment_attempts a WHERE a.pay_row_id=p.id)"))==1
        assert query(work,env,"SELECT price FROM pays WHERE type='INCREASE_WALLET' "
            "AND user_id=1001 AND state='pending' AND payid IS NULL ORDER BY id DESC LIMIT 1")=='250000'
        assert query(work,env,"SELECT COUNT(*) FROM pays WHERE hash_id='topupconcurrentold'")=='0'
        assert query(work,env,
            "SELECT id,hash_id,payid,state,price FROM pays WHERE hash_id='topupissuedold'")==issued_before
        assert query(work,env,
            "SELECT id,pay_row_id,provider,order_hash,stage FROM payment_attempts "
            "WHERE order_hash='topupissuedold'")==attempt_before
        cases.append('real_topup_concurrent_step_claim_single_atomic_invoice')

    return {'complete':True,'cases':len(cases),'scenarios':cases,
            'php_runtime':'native','database':'owned_socket_only',
            'live_provider_calls':0,'production_data_modified':False}

def main():
    p=argparse.ArgumentParser()
    p.add_argument('--run-disposable',action='store_true',required=True)
    p.add_argument('--report',type=Path)
    args=p.parse_args()
    res=check()
    if args.report:
        args.report.write_text(json.dumps(res,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps(res,ensure_ascii=False))

if __name__=='__main__':main()
