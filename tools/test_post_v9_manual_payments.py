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
if ($op==='claim') $value=achbotManualClaim($connection,$payInfo,$method,$user);
elseif ($op==='allow') $value=achbotManualReceiptAllowed($connection,$payInfo,$method,$user);
elseif ($op==='begin') $value=achbotManualBeginEvidence($connection,$payInfo,$method,$user,
    (string)($spec['photo_id']??'fixture-photo-id'));
elseif ($op==='approve') $value=achbotApproveManualWalletTopup(
    $connection,$hash,(int)($spec['actor']??9001));
elseif ($op==='bank_auto_claim') $value=achbotAutoBankClaim($connection,$payInfo);
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
elseif ($op==='tron_claim') $value=achbotTronClaim($connection,$payInfo,$user,
    13.25, 'fixture-tron-wallet');
elseif ($op==='tron_submit') $value=achbotTronSubmitHash($connection,$hash,$user,
    (string)($spec['tx_id']??str_repeat('a',64)));
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
$from_id=(int)($spec['actor']??1001);
$chat_id=$from_id;
$admin=9001;$botUsername='FixtureBot';$message_id=111;
$callbackId='fixture-callback';
$data=(string)($spec['data']??'');
$text='';
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
$botState=['cartToCartState'=>'on','giftCardState'=>'on','manualUsdtState'=>'on'];
$paymentKeys=[
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
function setUser($value='none',$field='step'){$GLOBALS['events'][]=[
    'name'=>'setUser','step'=>$value,'field'=>$field];}
function getMainKeys(){return '{}';}
function alert(...$args){$GLOBALS['events'][]=['name'=>'alert','args'=>$args];}
function sendMessage(...$args){$GLOBALS['events'][]=['name'=>'sendMessage','args'=>$args];return (object)['ok'=>true];}
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
if (($spec['route']??'wallet') === 'manual_admin') {
    require $root.'/src/Finance/Routes/ManualReceipts.php';
} elseif (($spec['route']??'wallet') === 'checkout') {
    require $root.'/src/Finance/Routes/Checkout.php';
} elseif (($spec['route']??'wallet') === 'extension') {
    require $root.'/src/Finance/Routes/Extensions.php';
} elseif (($spec['route']??'wallet') === 'renewal') {
    require $root.'/src/Finance/Routes/RenewalCheckout.php';
} else {
    require $root.'/src/Finance/Routes/WalletRecharge.php';
}
"""
def run_route(work,env,driver,data='',step='none',actor=1001,update=None,route='wallet',
              photo_mode='success',remote_mode='success'):
    spec={'data':data,'step':step,'actor':actor,'route':route,
          'photo_mode':photo_mode,'remote_mode':remote_mode}
    if update is not None: spec['update']=update
    cmd=['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
         '-d','allow_url_fopen=0','-d','display_errors=1',
         '-d','log_errors=1','-d','error_log=/dev/stderr','-d','open_basedir='+str(ROOT)+':'+str(work),
         '-d','disable_functions=exec,shell_exec,system,passthru,popen,proc_open,mail,dl',
         str(driver),str(ROOT),str(work/'mysql.sock'),DB,
         json.dumps(spec,ensure_ascii=False)]
    p=subprocess.run(cmd,cwd=work,env=env,capture_output=True,text=True,timeout=20)
    if p.returncode or p.stderr:
        raise RuntimeError('isolated wallet payment route failed: '+
                           repr((p.returncode,p.stderr,p.stdout))[:1400])
    try: return json.loads(p.stdout)['events']
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
            'manualUsdtWallet':'T'+'A'*33,'manualUsdtNetwork':'TRC20'}) is True
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
        assert base('walletfirst','tron_claim') is None
        cases.append('wallet_first_prevents_external_method_replacement')

        # F2: stale TRON Telegram paths reserve and respect the same ledger.
        seed(work,env,'tronfirst')
        trx=base('tronfirst','tron_claim')
        assert trx and trx['quote']=='13.25'
        assert base('tronfirst','claim','bank_transfer') is False
        assert base('tronfirst','wallet')['status']=='claimed_provider'
        assert base('tronfirst','tron_submit',user=9002) is False
        assert base('tronfirst','tron_submit') is True
        assert base('tronfirst','tron_submit') is False
        assert query(work,env,"SELECT state FROM pays WHERE hash_id='tronfirst'")=='0'
        assert query(work,env,"SELECT provider,stage FROM payment_attempts WHERE order_hash='tronfirst'")=='tron_wallet\treview'
        cases.append('tron_stale_callback_claim_owner_and_terminal_nonreplay')
        seed(work,env,'manualfirsttron')
        assert base('manualfirsttron','claim','gift_card')
        assert base('manualfirsttron','tron_claim') is None
        assert base('manualfirsttron','tron_submit') is False
        cases.append('tron_cannot_replace_existing_manual_receipt_claim')

        # A hosted provider claim excludes cached local wallet actions.
        seed(work,env,'hostedclaimed')
        query(work,env,"INSERT INTO payment_attempts (pay_row_id,provider,order_hash,quote_amount,quote_currency,local_amount_toman,stage) "
             "SELECT id,'nowpayments',hash_id,100000,'toman',price,'creating' FROM pays WHERE hash_id='hostedclaimed'")
        assert base('hostedclaimed','wallet')['status']=='claimed_provider'
        assert base('hostedclaimed','claim','manual_usdt') is False
        assert base('hostedclaimed','tron_claim') is None
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
