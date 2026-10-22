#!/usr/bin/env python3
"""Actual Checkout.php paid-wallet BUY_SUB routes with the 007 debit/capacity barrier.

Runs isolated MySQLi + real PHP handler, synthetic Telegram/Marzban only.
Never contacts a bank, public provider, bot, or installed customer database.
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
PHP=r'''<?php
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(static function($n,$message){throw new ErrorException($message,0,$n);});
$_SERVER['SCRIPT_FILENAME']=__FILE__;
$root=$argv[1];$spec=json_decode($argv[4],true,512,JSON_THROW_ON_ERROR);
$connection=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$connection->set_charset('utf8mb4');
$admin=9001;$from_id=1001;$chat_id=1001;$message_id=51;$time=time();
$data=(($spec['custom']??false)?'payCustomWithWallet':'payBuyWithWallet').
    ($spec['hash']??'native_standard');
$text='';$username='fixture';$first_name='Customer';$match=[];
$botUrl='https://fixture.invalid/';$botState=['remark'=>(string)($spec['remark_mode']??'digits'),'rewardChannel'=>null];
$buttonValues=['back_to_main'=>'back','cancel'=>'cancel'];
$mainValues=['sending_config_to_user'=>'Sending...',
 'buy_custom_account_request'=>'TYPE USER-ID USERNAME NAME PRICE REMARK VOLUME DAYS',
 'buy_new_account_request'=>'TYPE USER-ID USERNAME NAME PRICE REMARK VOLUME DAYS',
 'renew_account_request_message'=>'REMARK PRICE VOLUME DAYS',
 'invite_buy_new_account'=>'REMARK PRICE'];
$linkIndices=[];$hideConfigIfMany=false;$GLOBALS['events']=[];
$GLOBALS['spec']=$spec;$GLOBALS['remote']=[];
$userStmt=$connection->prepare('SELECT * FROM users WHERE userid=?');
$userStmt->bind_param('i',$from_id);$userStmt->execute();
$userInfo=$userStmt->get_result()->fetch_assoc();$userStmt->close();
function setUser($value='none',$field='step'){
    if ($field!=='step')throw new RuntimeException('fixture_step_only');
    $GLOBALS['events'][]=['event'=>'STEP'];
}
function alert(...$args){$GLOBALS['events'][]=['event'=>'ALERT','message'=>(string)($args[0]??'')];}
function delMessage(...$args){$GLOBALS['events'][]=['event'=>'DELETE'];}
function editText(...$args){$GLOBALS['events'][]=['event'=>'EDIT'];}
function editKeys(...$args){$GLOBALS['events'][]=['event'=>'EDIT_KEYS'];}
function getMainKeys(){return '{}';}
function sendToAllAdmins(...$args){$GLOBALS['events'][]=['event'=>'ADMIN_FANOUT'];}
function achbotFinanceDiagnosticReason(Throwable $error): string {
    return 'fixture_'.get_class($error).':'.$error->getMessage();
}
function sendMessage(...$args){
    $GLOBALS['events'][]=['event'=>'MESSAGE','to'=>$args[3]??null,
        'synthetic_note'=>(string)($args[0]??'')];
    return (object)['ok'=>true];
}
function sendQrFromLink(...$args) {
    $name=$GLOBALS['spec']['hash']??'native_standard';
    $stmt=$GLOBALS['connection']->prepare('SELECT state FROM pays WHERE hash_id=?');
    $stmt->bind_param('s',$name);$stmt->execute();
    $state=$stmt->get_result()->fetch_assoc()['state']??null;$stmt->close();
    $GLOBALS['events'][]=['event'=>'QR','user'=>$args[4]??null,'state'=>$state];
    if (($GLOBALS['spec']['delivery']??'')==='throw')
        throw new RuntimeException('fixture_unacknowledged_customer_delivery');
    if (($GLOBALS['spec']['delivery']??'')==='false') return false;
    return true;
}
function generateRandomString($len=42,$protocol='vless'){return 'testuuid';}
function getNextPortAndIncrease(...$args){return [12345,0];}
function buildRemark(...$args) {
    $i=($GLOBALS['built_names']??0)+1;$GLOBALS['built_names']=$i;
    return 'checkout_'.($GLOBALS['spec']['hash']??'native').'_'.$i;
}
function getServerInfoAndConfig($db,$id) {
    $stmt=$db->prepare('SELECT s.remark,s.title,c.* FROM server_info s
       JOIN server_config c ON c.id=s.id WHERE s.id=?');
    $stmt->bind_param('i',$id);$stmt->execute();
    $info=$stmt->get_result()->fetch_assoc();$stmt->close();
    return [['remark'=>$info['remark'],'title'=>$info['title']],$info];
}
function buildLinksAndSendQr(...$args){
    if (end($args)!==false) throw new RuntimeException('premature_telegram_delivery');
    $GLOBALS['events'][]=['event'=>'BUILD'];
    return ['token','["vless://fixture"]','uuid','Fixture account','{}','vless://fixture'];
}
function createServerAccount(...$args) {
    $count=($GLOBALS['posts']??0)+1;$GLOBALS['posts']=$count;
    $GLOBALS['events'][]=['event'=>'POST','ordinal'=>$count,'name'=>$args[4]];
    $GLOBALS['remote'][(string)$args[4]]=[
        'username'=>(string)$args[4],
        'note'=>'achbot:v1:'.(string)$args[14],
        'expire'=>(int)$args[15],
        'data_limit'=>(int)$args[5]*1073741824
    ];
    if (($GLOBALS['spec']['remote']??'')==='second_lost' && $count===2)
        throw new RuntimeException('fixture_second_post_lost');
    return [(object)['success'=>true],(string)$args[4],12345];
}
function getMarzbanUser($serverId,$remark,$token=null,$strict=false) {
    if ($strict!==true)throw new RuntimeException('strict_remote_read_required');
    $GLOBALS['events'][]=['event'=>'GET','name'=>$remark];
    $info=$GLOBALS['remote'][$remark]??null;
    return $info?(object)$info:null;
}
require_once $root.'/src/Purchase/Core.php';
require_once $root.'/src/Purchase/OrderWrites.php';
require_once $root.'/src/Finance/ReceiptHelpers.php';
require_once $root.'/src/Finance/ServiceEffects.php';
require_once $root.'/src/Finance/WalletSettlement.php';
require $root.'/src/Finance/Routes/Checkout.php';
echo json_encode(['events'=>$GLOBALS['events']],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
'''

# Real customer/agent `buyType=much` draft route (not a mock draft
# constructor); the same draft is then consumed by real WalletSettlement.
PHP_CATALOGUE=r'''<?php
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(static function($n,$m){throw new ErrorException($m,0,$n);});
$_SERVER['SCRIPT_FILENAME']=__FILE__;
$root=$argv[1];$spec=json_decode($argv[4],true,512,JSON_THROW_ON_ERROR);
$connection=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$connection->set_charset('utf8mb4');
$admin=9001;$from_id=1001;$chat_id=1001;$message_id=101;
$data='';$text=(string)($spec['username']??'');$match=[];
$botState=['sellState'=>'on','remark'=>'manual','gbPrice'=>100,'dayPrice'=>10,
 'walletState'=>'on','nowPaymentOther'=>'off','zarinpal'=>'off','nextpay'=>'off'];
$mainValues=['incorrect_config_name'=>'Invalid account username',
 'buy_subscription_detail'=>'Account PRICE VOLUME DAYS PLAN-NAME DESCRIPTION'];
$buttonValues=['cancel'=>'cancel','pay_with_wallet'=>'Wallet'];
$userInfo=['step'=>'enterCustomPlanName7_1_1_30_much','is_agent'=>false,
 'isAdmin'=>false,'userid'=>1001];
$botUrl='https://fixture.invalid/';$username='fixture';$first_name='Synthetic';
$GLOBALS['events']=[];$GLOBALS['spec']=$spec;
register_shutdown_function(static function(){
    echo json_encode(['events'=>$GLOBALS['events'],'fatal'=>error_get_last()],
        JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
});
function alert(...$args){$GLOBALS['events'][]=['name'=>'ALERT'];}
function sendMessage(...$args) {
 $GLOBALS['events'][]=['name'=>'MESSAGE','text'=>(string)($args[0]??'')];
 return (object)['ok'=>true,'result'=>(object)['message_id'=>101]];
}
function RandomString(){return (string)$GLOBALS['spec']['hash'];}
function setUser(...$args){$GLOBALS['events'][]=['name'=>'STEP'];}
function achbotSupportsMarzbanPlan($db,$id){return (int)$id===7;}
function achbotManualEnabled(...$args){return false;}
function achbotManualCryptoEnabled(...$args){return false;}
require_once $root.'/src/Purchase/Core.php';
require $root.'/src/Purchase/Catalogue.php';
'''

def sql(work,env,query):
    return native.sql(work,env,query,DB).strip()

def route(work,env,driver,**spec):
    p=subprocess.run(['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
        '-d','disable_functions=curl_init,curl_exec,exec,shell_exec,system,passthru,popen,proc_open,mail',
        str(driver),str(ROOT),str(work/'mysql.sock'),DB,json.dumps(spec)],
        cwd=work,env=env,capture_output=True,text=True,timeout=35)
    if p.returncode:
        raise RuntimeError('native checkout PHP failure '+repr(
            (p.returncode,p.stderr[-1000:],p.stdout[-700:])))
    try:return json.loads(p.stdout)['events']
    except Exception as exc:
        raise RuntimeError('native checkout JSON '+p.stdout[-800:]+p.stderr[-500:]) from exc

def catalogue(work,env,driver,**spec):
    p=subprocess.run(['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
        '-d','disable_functions=curl_init,curl_exec,exec,shell_exec,system,passthru,popen,proc_open,mail',
        str(driver),str(ROOT),str(work/'mysql.sock'),DB,json.dumps(spec)],
        cwd=work,env=env,capture_output=True,text=True,timeout=25)
    if p.returncode:
        raise RuntimeError('catalogue PHP failure '+repr((p.stderr[-1000:],p.stdout[-900:])))
    result=json.loads(p.stdout)
    if result.get('fatal') and result['fatal'].get('type') in (1,4,16,64,256):
        raise RuntimeError('catalogue fatal '+str(result['fatal'])[:700])
    return result['events']


def check():
    cases=[]
    with owned.owned_database() as (work,env):
        purchase.init(work,env,'http://127.0.0.1:9')
        for file in ('001_marzban_panel_group_id.sql','003_payment_attempts.sql',
                     '007_service_effects.sql'):
            sql(work,env,(ROOT/'migrations/post-v9'/file).read_text())
        driver=work/'checkout-wallet-source.php'
        driver.write_text(PHP)
        sql(work,env,"DELETE FROM setting WHERE type='BOT_STATES'; "
            "INSERT INTO setting(type,value) VALUES "
            "('BOT_STATES','{\"walletState\":\"on\",\"gatewayAudience\":{\"wallet\":\"all\"}}')")
        def newpay(name,count,custom=False):
            sql(work,env,"UPDATE users SET wallet=50000 WHERE userid=1001")
            sql(work,env,'UPDATE server_info SET ucount=10 WHERE id=2')
            day,vol=(30,2) if custom else (0,0)
            sql(work,env,"INSERT INTO pays "
                "(hash_id,user_id,type,plan_id,server_id,agent_count,"
                "day,volume,price,description,request_date,state) VALUES "
                f"('{name}',1001,'BUY_SUB',7,2,{count},{day},{vol},100,"
                f"'{name}',UNIX_TIMESTAMP(),'pending')")
            return int(sql(work,env,f"SELECT id FROM pays WHERE hash_id='{name}'"))
        def detail(pid):
            return sql(work,env,f"SELECT effect_kind,ordinal,reserved_count,state "
                f"FROM service_effects WHERE pay_row_id={pid} "
                f"ORDER BY FIELD(effect_kind,'wallet_hold','create'),ordinal")
        def paid(pid):
            return sql(work,env,f"SELECT state FROM pays WHERE id={pid}")
        def wallet():
            return int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001'))
        def cap():
            return int(sql(work,env,'SELECT ucount FROM server_info WHERE id=2'))
        def calls(events,key):
            return [e for e in events if e['event']==key]

        # Exact supported `buyType=much` draft route must reject usernames
        # that a later provider would reject, without inserting any invoice.
        catalogue_driver=work/'catalogue-much-wallet.php'
        catalogue_driver.write_text(PHP_CATALOGUE)
        for i,bad_name in enumerate(('foo bar','bad/name','برنامه','x'*101),start=1):
            events=catalogue(work,env,catalogue_driver,
                username=bad_name,hash='badmuchdraft'+str(i))
            assert any(e['name']=='MESSAGE' and
                       'Invalid account username' in e['text'] for e in events),events
            assert sql(work,env,f"SELECT COUNT(*) FROM pays WHERE hash_id='badmuchdraft{i}'")=='0'
        cases.append('actual_catalogue_much_draft_rejects_invalid_remote_usernames')

        # An older persisted BUY_SUB may have bypassed the new UI validator.
        # It must still fail at the authoritative locked pre-debit boundary.
        invalid=newpay('native_bad_persisted',1,custom=True)
        sql(work,env,f"UPDATE pays SET description='foo bar' WHERE id={invalid}")
        denied=route(work,env,driver,hash='native_bad_persisted',custom=True)
        assert not calls(denied,'POST') and not calls(denied,'QR'),denied
        assert paid(invalid)=='pending' and wallet()==50000 and cap()==10
        assert detail(invalid)=='' and sql(work,env,
            f'SELECT COUNT(*) FROM orders_list o JOIN service_effects e ON e.order_id=o.id WHERE e.pay_row_id={invalid}')=='0'
        assert not calls(route(work,env,driver,hash='native_bad_persisted',custom=True),'POST')
        cases.append('real_custom_wallet_persisted_bad_name_rejected_before_debit_hold_and_create')

        events=catalogue(work,env,catalogue_driver,
            username='valid_custom_17',hash='validmuchdraft')
        assert any(e['name']=='MESSAGE' and 'Invalid account username' not in e['text']
                   for e in events),events
        real_draft=int(sql(work,env,"SELECT id FROM pays WHERE hash_id='validmuchdraft' AND description='valid_custom_17'"))
        assert real_draft>0
        allowed=route(work,env,driver,hash='validmuchdraft',custom=True)
        assert len(calls(allowed,'POST'))==1 and paid(real_draft)=='paid_with_wallet'
        assert len(calls(allowed,'QR'))==1 and cap()==9
        assert not calls(route(work,env,driver,hash='validmuchdraft',custom=True),'POST')
        cases.append('real_catalogue_much_valid_draft_to_wallet_one_effect_order')

        # Persistent request tampering and locally-invalid plan/username
        # inputs must fail before a cent or capacity slot can be committed.
        bad_count=newpay('native_invalid_count',2,custom=True)
        held=route(work,env,driver,hash='native_invalid_count',custom=True)
        assert not calls(held,'POST') and wallet()==50000 and cap()==10
        assert paid(bad_count)=='pending' and detail(bad_count)==''
        cases.append('real_custom_persisted_multicount_rejected_predebit')

        bad_manual=newpay('native_manual_bad',1)
        sql(work,env,f"UPDATE pays SET description='bad name' WHERE id={bad_manual}")
        held=route(work,env,driver,hash='native_manual_bad',remark_mode='manual')
        assert not calls(held,'POST') and wallet()==50000 and cap()==10
        assert paid(bad_manual)=='pending' and detail(bad_manual)==''
        cases.append('real_standard_manual_remark_invalid_rejected_predebit')

        bad_plan=newpay('native_plan_bad',1)
        plan_days=sql(work,env,'SELECT days FROM server_plans WHERE id=7')
        sql(work,env,'UPDATE server_plans SET days=0 WHERE id=7')
        try:
            held=route(work,env,driver,hash='native_plan_bad')
        finally:
            sql(work,env,f'UPDATE server_plans SET days={plan_days} WHERE id=7')
        assert not calls(held,'POST') and wallet()==50000 and cap()==10
        assert paid(bad_plan)=='pending' and detail(bad_plan)==''
        cases.append('real_standard_invalid_plan_day_predebit_no_hold')

        bad_prefix=newpay('native_prefix_bad',1)
        original_prefix=sql(work,env,'SELECT remark FROM server_info WHERE id=2')
        sql(work,env,"UPDATE server_info SET remark='bad name' WHERE id=2")
        try:
            held=route(work,env,driver,hash='native_prefix_bad')
        finally:
            sql(work,env,"UPDATE server_info SET remark='"+
                original_prefix.replace("'","''")+"' WHERE id=2")
        assert not calls(held,'POST') and wallet()==50000 and cap()==10
        assert paid(bad_prefix)=='pending' and detail(bad_prefix)==''
        cases.append('real_standard_invalid_generated_prefix_predebit_no_hold')

        multi=newpay('native_multi',2)
        done=route(work,env,driver,hash='native_multi')
        assert len(calls(done,'POST'))==2,done
        assert len(calls(done,'GET'))==2
        assert all(e['state']=='paid_with_wallet' for e in calls(done,'QR')),done
        assert len(calls(done,'QR'))==2
        assert paid(multi)=='paid_with_wallet' and wallet()==49900 and cap()==8
        assert detail(multi)==('wallet_hold\t0\t2\tconsumed\n'
                              'create\t1\t2\tlocal_recorded\n'
                              'create\t2\t0\tlocal_recorded'),detail(multi)
        assert sql(work,env,f'SELECT COUNT(*) FROM orders_list o '
            f'JOIN service_effects e ON o.id=e.order_id '
            f"WHERE e.pay_row_id={multi} AND e.effect_kind='create'")=='2'
        repeated=route(work,env,driver,hash='native_multi')
        assert not calls(repeated,'POST') and not calls(repeated,'QR')
        assert wallet()==49900 and cap()==8
        cases.append('real_multi_wallet_checkout_full_hold_order_delivery_after_finality')

        unknown=newpay('native_partial',2)
        failed=route(work,env,driver,hash='native_partial',remote='second_lost')
        assert len(calls(failed,'POST'))==2 and not calls(failed,'QR'),failed
        assert paid(unknown)=='processing_wallet' and wallet()==49900 and cap()==8
        assert detail(unknown)==('wallet_hold\t0\t2\tconsumed\n'
                                 'create\t1\t2\tlocal_recorded\n'
                                 'create\t2\t0\tdispatching'),detail(unknown)
        assert not calls(route(work,env,driver,hash='native_partial'),'POST')
        cases.append('real_multi_wallet_second_line_ambiguous_stays_paid_hold_no_replay')

        custom=newpay('native_custom',1,custom=True)
        user=route(work,env,driver,hash='native_custom',custom=True)
        assert len(calls(user,'POST'))==1 and len(calls(user,'QR'))==1,user
        assert calls(user,'QR')[0]['state']=='paid_with_wallet'
        assert paid(custom)=='paid_with_wallet' and wallet()==49900 and cap()==9
        assert detail(custom)==('wallet_hold\t0\t1\tconsumed\n'
                                'create\t1\t1\tlocal_recorded'),detail(custom)
        assert not calls(route(work,env,driver,hash='native_custom',custom=True),'POST')
        cases.append('real_custom_wallet_checkout_single_effect_after_commit')

        no_notice=newpay('native_notice',1,custom=True)
        post=route(work,env,driver,hash='native_notice',custom=True,delivery='false')
        assert len(calls(post,'POST'))==1 and len(calls(post,'QR'))==1
        assert paid(no_notice)=='paid_with_wallet' and wallet()==49900 and cap()==9
        assert any(e['to']==9001 and 'تحویل' in e['synthetic_note']
                   for e in calls(post,'MESSAGE')),post
        assert not calls(route(work,env,driver,hash='native_notice',custom=True),'POST')
        cases.append('real_custom_wallet_postcommit_telegram_unconfirmed_never_reissues')

        multi_notice=newpay('native_multinotice',2)
        multi_delivery=route(work,env,driver,hash='native_multinotice',delivery='throw')
        assert len(calls(multi_delivery,'POST'))==2
        assert len(calls(multi_delivery,'QR'))==2
        assert any(e['to']==9001 and 'تحویل' in e['synthetic_note']
                   for e in calls(multi_delivery,'MESSAGE')),multi_delivery
        assert paid(multi_notice)=='paid_with_wallet' and wallet()==49900 and cap()==8
        assert not calls(route(work,env,driver,hash='native_multinotice'),'POST')
        cases.append('real_multi_wallet_postcommit_delivery_throw_owner_review_no_replay')

        sqlfail=newpay('native_sqlfail',1,custom=True)
        sql(work,env,"CREATE TRIGGER owned_wallet_checkout_order_fail "
            "BEFORE INSERT ON orders_list FOR EACH ROW "
            "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture_checkout_order_error'")
        try:
            failed=route(work,env,driver,hash='native_sqlfail',custom=True)
        finally:
            sql(work,env,'DROP TRIGGER owned_wallet_checkout_order_fail')
        assert len(calls(failed,'POST'))==1 and not calls(failed,'QR')
        assert paid(sqlfail)=='processing_wallet' and wallet()==49900 and cap()==9
        assert detail(sqlfail)==('wallet_hold\t0\t1\tconsumed\n'
                                 'create\t1\t1\tverified'),detail(sqlfail)
        assert not calls(route(work,env,driver,hash='native_sqlfail',custom=True),'POST')
        cases.append('real_custom_wallet_verified_remote_failed_order_retained_review')

    return {'complete':True,'cases':len(cases),'scenarios':cases,
            'actual_checkout_php':True,'isolated_mariadb':True,
            'remote_calls':0,'production_financial_mutations':0}

if __name__=='__main__':
    parser=argparse.ArgumentParser()
    parser.add_argument('--run-disposable',action='store_true',required=True)
    parser.add_argument('--report',type=Path)
    args=parser.parse_args()
    result=check()
    if args.report:args.report.write_text(json.dumps(result,indent=2)+'\n')
    print(json.dumps(result))
