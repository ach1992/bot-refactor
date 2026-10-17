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
require $root.'/settings/values.php';
$stmt=$connection->prepare('SELECT * FROM pays WHERE hash_id=? LIMIT 1');
$stmt->bind_param('s',$hash);$stmt->execute();
$payInfo=$stmt->get_result()->fetch_assoc();$stmt->close();
if (!$payInfo) throw new RuntimeException('synthetic payment missing');
$method=$spec['method']??'bank_transfer';$user=(int)($spec['user']??1001);
$op=$spec['op']??'claim';
if ($op==='claim') $value=achbotManualClaim($connection,$payInfo,$method,$user);
elseif ($op==='allow') $value=achbotManualReceiptAllowed($connection,$payInfo,$method,$user);
elseif ($op==='begin') $value=achbotManualBeginEvidence($connection,$payInfo,$method,$user);
elseif ($op==='approve') $value=achbotApproveManualWalletTopup(
    $connection,$hash,(int)($spec['actor']??9001));
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
function sendPhoto(...$args){$GLOBALS['events'][]=['name'=>'sendPhoto','args'=>$args];
    return (object)['ok'=>true,'result'=>(object)['message_id'=>222]];}
function sendToAllAdmins(...$args){$GLOBALS['events'][]=['name'=>'sendToAllAdmins'];}
function editKeys(...$args){$GLOBALS['events'][]=['name'=>'editKeys'];}
if (($spec['route']??'wallet') === 'manual_admin') {
    require $root.'/src/Finance/Routes/ManualReceipts.php';
} else {
    require $root.'/src/Finance/Routes/WalletRecharge.php';
}
"""
def run_route(work,env,driver,data='',step='none',actor=1001,update=None,route='wallet'):
    spec={'data':data,'step':step,'actor':actor,'route':route}
    if update is not None: spec['update']=update
    cmd=['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
         '-d','allow_url_fopen=0','-d','display_errors=0',
         '-d','log_errors=0','-d','open_basedir='+str(ROOT)+':'+str(work),
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
    spec=dict(op=op,method=method,user=user,**more)
    cmd=['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
         '-d','allow_url_fopen=0','-d','display_errors=0',
         '-d','log_errors=0','-d','open_basedir='+str(ROOT)+':'+str(work),
         '-d','disable_functions=exec,shell_exec,system,passthru,popen,proc_open,mail,dl',
         str(driver),str(ROOT),str(work/'mysql.sock'),hash_id,
         json.dumps(spec,ensure_ascii=False),DB]
    p=subprocess.run(cmd,cwd=work,env=env,capture_output=True,text=True,timeout=20)
    if p.returncode or p.stderr:
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

        assert base('usdtfixture','begin','manual_usdt') is True
        assert base('usdtfixture','begin','manual_usdt') is False
        assert base('usdtfixture','allow','manual_usdt') is False
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
