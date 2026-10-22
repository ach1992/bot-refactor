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
$GLOBALS['owned_socket']=$argv[2];$GLOBALS['owned_db']=$argv[3];
$GLOBALS['spec']=$spec;
// The test-only mysqli subclass delegates to REAL MariaDB COMMIT. Only
// after the wallet hold is committed does it perform a second-session
// plan mutation or pause to admit a competing PHP buyer.
class OwnedCheckoutMysqli extends mysqli {
    public function commit(int $flags=0, ?string $name=null): bool {
        $ok=parent::commit($flags,$name);
        $hook=(string)($GLOBALS['spec']['after_wallet_commit']??'');
        if (!$ok || $hook==='' || ($GLOBALS['hook_fired']??false)) return $ok;
        $hash=(string)($GLOBALS['spec']['hash']??'');
        $stmt=$this->prepare('SELECT state FROM pays WHERE hash_id=? LIMIT 1');
        $stmt->bind_param('s',$hash);$stmt->execute();
        $state=$stmt->get_result()->fetch_assoc()['state']??null;$stmt->close();
        if ($state!=='processing_wallet') return $ok;
        $GLOBALS['hook_fired']=true;
        if ($hook==='change_plan') {
            $other=new mysqli('localhost','root','',
                $GLOBALS['owned_db'],0,$GLOBALS['owned_socket']);
            if (!$other->query('UPDATE server_plans SET days=15,volume=1 WHERE id=7'))
                throw new RuntimeException('fixture_second_session_plan_update');
            $other->close();
            $GLOBALS['events'][]=['event'=>'SECOND_SESSION_PLAN_CHANGED'];
        } elseif ($hook==='pause_for_rival') {
            if (!$this->query('UPDATE wallet_fixture_barrier SET arrived=1 WHERE id=1'))
                throw new RuntimeException('fixture_signal_failed');
            $released=false;
            for ($i=0;$i<280;$i++) {
                $rows=$this->query('SELECT released FROM wallet_fixture_barrier WHERE id=1');
                if ($rows && (int)($rows->fetch_assoc()['released']??0)===1) {
                    $released=true;break;
                }
                usleep(50000);
            }
            if (!$released) throw new RuntimeException('fixture_barrier_timeout');
            $GLOBALS['events'][]=['event'=>'POST_COMMIT_RIVAL_FINISHED'];
        } else {
            throw new RuntimeException('unknown_fixture_hook');
        }
        return $ok;
    }
}
$connection=new OwnedCheckoutMysqli('localhost','root','',$argv[3],0,$argv[2]);
$connection->set_charset('utf8mb4');
$admin=9001;$from_id=(int)($spec['user']??1001);$chat_id=$from_id;$message_id=51;$time=time();
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
    $GLOBALS['events'][]=['event'=>'POST','ordinal'=>$count,'name'=>$args[4],
        'volume_gb'=>(int)$args[5],'days'=>(int)$args[6]];
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
        sql(work,env,"CREATE TABLE wallet_fixture_barrier "
            "(id INT PRIMARY KEY,arrived TINYINT NOT NULL,released TINYINT NOT NULL); "
            "INSERT INTO wallet_fixture_barrier VALUES (1,0,0)")
        sql(work,env,"INSERT INTO users "
            "(userid,name,username,refcode,wallet,date,isAdmin,first_start) "
            "VALUES (1002,'Buyer 2','buyer2','0',50000,'1700000000',0,'sent')")
        sql(work,env,"DELETE FROM setting WHERE type='BOT_STATES'; "
            "INSERT INTO setting(type,value) VALUES "
            "('BOT_STATES','{\"walletState\":\"on\",\"gatewayAudience\":{\"wallet\":\"all\"}}')")
        def newpay(name,count,custom=False,user=1001,reset_capacity=True):
            sql(work,env,f"UPDATE users SET wallet=50000 WHERE userid={user}")
            if reset_capacity:
                sql(work,env,'UPDATE server_info SET ucount=10 WHERE id=2')
            day,vol=(30,2) if custom else (0,0)
            sql(work,env,"INSERT INTO pays "
                "(hash_id,user_id,type,plan_id,server_id,agent_count,"
                "day,volume,price,description,request_date,state) VALUES "
                f"('{name}',{user},'BUY_SUB',7,2,{count},{day},{vol},100,"
                f"'{name}',UNIX_TIMESTAMP(),'pending')")
            return int(sql(work,env,f"SELECT id FROM pays WHERE hash_id='{name}'"))
        def detail(pid):
            return sql(work,env,f"SELECT effect_kind,ordinal,reserved_count,state "
                f"FROM service_effects WHERE pay_row_id={pid} "
                f"ORDER BY FIELD(effect_kind,'wallet_hold','create'),ordinal")
        def paid(pid):
            return sql(work,env,f"SELECT state FROM pays WHERE id={pid}")
        def wallet(user=1001):
            return int(sql(work,env,f'SELECT wallet FROM users WHERE userid={user}'))
        def cap():
            return int(sql(work,env,'SELECT ucount FROM server_info WHERE id=2'))
        def calls(events,key):
            return [e for e in events if e['event']==key]

        # R2 blocker: wrong customer callback cannot select a different
        # BUY_SUB fulfillment handler after a locked owner/payment claim.
        def assert_mode_denied(name,pid,*,custom):
            before_wallet=wallet()
            before_capacity=cap()
            before_hold=detail(pid)
            events=route(work,env,driver,hash=name,custom=custom)
            assert not calls(events,'POST') and not calls(events,'QR'),events
            assert paid(pid)=='pending' and wallet()==before_wallet,events
            assert cap()==before_capacity and detail(pid)==before_hold=='',events
            assert sql(work,env,f"SELECT COUNT(*) FROM orders_list o "
                f"JOIN service_effects e ON e.order_id=o.id "
                f"WHERE e.pay_row_id={pid}")=='0'
            assert any(e['event']=='ALERT' for e in events),events
            return events

        custom_quote=newpay('r2_custom_standard',1,custom=True)
        sql(work,env,f'UPDATE pays SET day=37,volume=7 WHERE id={custom_quote}')
        assert_mode_denied('r2_custom_standard',custom_quote,custom=False)
        cases.append('r2_custom_quote_standard_callback_predebit_denied')

        standard_quote=newpay('r2_standard_custom',1)
        assert_mode_denied('r2_standard_custom',standard_quote,custom=True)
        cases.append('r2_standard_quote_custom_callback_predebit_denied')

        for day,volume in ((0,7),(37,0)):
            name=f'r2_partial_{day}_{volume}'
            partial=newpay(name,1,custom=True)
            sql(work,env,f'UPDATE pays SET day={day},volume={volume} WHERE id={partial}')
            assert_mode_denied(name,partial,custom=True)
        cases.append('r2_partial_custom_quote_predebit_denied')

        positive=newpay('r2_quote_zero_template',1,custom=True)
        sql(work,env,f'UPDATE pays SET day=37,volume=7 WHERE id={positive}')
        sql(work,env,'UPDATE server_plans SET days=0,volume=0 WHERE id=7')
        try:
            allowed=route(work,env,driver,hash='r2_quote_zero_template',custom=True)
        finally:
            sql(work,env,'UPDATE server_plans SET days=30,volume=2 WHERE id=7')
        assert [(x['days'],x['volume_gb']) for x in calls(allowed,'POST')]==[(37,7)],allowed
        assert paid(positive)=='paid_with_wallet' and wallet()==49900 and cap()==9
        assert detail(positive)==('wallet_hold\t0\t1\tconsumed\n'
                                 'create\t1\t1\tlocal_recorded')
        assert not calls(route(work,env,driver,hash='r2_quote_zero_template',
                               custom=True),'POST')
        cases.append('r2_zero_template_exact_custom_dimensions_one_effect')

        for count in (1,3):
            name=f'r2_standard_count_{count}'
            standard=newpay(name,count)
            done=route(work,env,driver,hash=name)
            assert len(calls(done,'POST'))==count,done
            assert all((x['days'],x['volume_gb'])==(30,2) for x in calls(done,'POST')),done
            assert paid(standard)=='paid_with_wallet' and wallet()==49900
            assert cap()==10-count
            assert not calls(route(work,env,driver,hash=name,custom=True),'POST')
            assert not calls(route(work,env,driver,hash=name),'POST')
        cases.append('r2_standard_single_multi_plan_dimensions_no_replay')

        from concurrent.futures import ThreadPoolExecutor
        from threading import Barrier
        contested=newpay('r2_concurrent_custom',1,custom=True)
        sql(work,env,f'UPDATE pays SET day=37,volume=7 WHERE id={contested}')
        barrier=Barrier(2)
        def compete(as_custom):
            barrier.wait(timeout=10)
            return route(work,env,driver,hash='r2_concurrent_custom',
                         custom=as_custom)
        with ThreadPoolExecutor(max_workers=2) as pool:
            bad=pool.submit(compete,False)
            good=pool.submit(compete,True)
            outcomes=[bad.result(timeout=45),good.result(timeout=45)]
        posts=[x for outcome in outcomes for x in calls(outcome,'POST')]
        assert len(posts)==1 and (posts[0]['days'],posts[0]['volume_gb'])==(37,7),outcomes
        assert paid(contested)=='paid_with_wallet' and wallet()==49900 and cap()==9
        assert detail(contested)==('wallet_hold\t0\t1\tconsumed\n'
                                  'create\t1\t1\tlocal_recorded')
        assert not calls(route(work,env,driver,hash='r2_concurrent_custom',
                               custom=False),'POST')
        cases.append('r2_concurrent_wrong_right_mode_exactly_one_effect')

        # R3: capacity belongs to the order that already bought and held it.
        # The old post-COMMIT ensureServerCapacity would PHP-exit when the
        # remaining unreserved capacity reached zero.
        for name,quantity,is_custom in (
            ('r3_final_standard',1,False),
            ('r3_final_custom',1,True),
            ('r3_final_multi',3,False),
        ):
            pid=newpay(name,quantity,custom=is_custom)
            sql(work,env,f'UPDATE server_info SET ucount={quantity} WHERE id=2')
            completed=route(work,env,driver,hash=name,custom=is_custom)
            posts=calls(completed,'POST')
            assert len(posts)==len(calls(completed,'GET'))==quantity,completed
            assert all((x['days'],x['volume_gb'])==(30,2) for x in posts),completed
            assert paid(pid)=='paid_with_wallet' and wallet()==49900 and cap()==0
            holds=detail(pid).splitlines()
            assert holds[0]==f'wallet_hold\t0\t{quantity}\tconsumed',holds
            assert len(holds)==quantity+1 and all(
                row.endswith('local_recorded') for row in holds[1:]),holds
            assert sql(work,env,'SELECT COUNT(*) FROM orders_list o '
                f'JOIN service_effects e ON o.id=e.order_id WHERE e.pay_row_id={pid} '
                "AND e.effect_kind='create'")==str(quantity)
            assert not calls(route(work,env,driver,hash=name,custom=is_custom),'POST')
            cases.append('r3_exact_owned_last_slots_'+name)

        insufficient=newpay('r3_over_requested',2)
        sql(work,env,'UPDATE server_info SET ucount=1 WHERE id=2')
        assert_mode_denied('r3_over_requested',insufficient,custom=False)
        cases.append('r3_excess_quantity_denied_before_wallet_hold')

        # Two independent real wallet buyers race one physical slot. The
        # server row decides; loser keeps their unclaimed payment and funds.
        left=newpay('r3_race_one',1,user=1001)
        right=newpay('r3_race_two',1,user=1002,reset_capacity=False)
        sql(work,env,'UPDATE server_info SET ucount=1 WHERE id=2')
        start_both=Barrier(2)
        def buyer(name,user):
            start_both.wait(timeout=12)
            return route(work,env,driver,hash=name,user=user)
        with ThreadPoolExecutor(max_workers=2) as pool:
            f1=pool.submit(buyer,'r3_race_one',1001)
            f2=pool.submit(buyer,'r3_race_two',1002)
            both=[f1.result(timeout=60),f2.result(timeout=60)]
        assert sum(len(calls(result,'POST')) for result in both)==1,both
        assert cap()==0 and sorted([wallet(1001),wallet(1002)])==[49900,50000]
        winner=[p for p in (left,right) if paid(p)=='paid_with_wallet']
        loser=[p for p in (left,right) if paid(p)=='pending']
        assert len(winner)==len(loser)==1,(winner,loser,[(pid,paid(pid),detail(pid)) for pid in (left,right)],both)
        assert detail(winner[0]).splitlines()[0]=='wallet_hold\t0\t1\tconsumed'
        assert detail(loser[0])==''
        assert not calls(route(work,env,driver,hash='r3_race_one',user=1001),'POST')
        assert not calls(route(work,env,driver,hash='r3_race_two',user=1002),'POST')
        cases.append('r3_independent_buyers_one_slot_no_double_debit')

        # First buyer commits a hold for slot 1 of 2. Pause the actual
        # PHP call just after COMMIT, let another wallet buyer take slot 2,
        # then resume the first real Checkout handler: both must complete.
        holder=newpay('r3_held_before_rival',1,user=1001)
        rival=newpay('r3_rival_after_hold',1,user=1002,reset_capacity=False)
        sql(work,env,'UPDATE server_info SET ucount=2 WHERE id=2')
        sql(work,env,'UPDATE wallet_fixture_barrier SET arrived=0,released=0 WHERE id=1')
        from time import monotonic,sleep
        with ThreadPoolExecutor(max_workers=1) as pool:
            future=pool.submit(route,work,env,driver,hash='r3_held_before_rival',
                               user=1001,after_wallet_commit='pause_for_rival')
            deadline=monotonic()+12
            while monotonic()<deadline:
                if sql(work,env,'SELECT arrived FROM wallet_fixture_barrier WHERE id=1')=='1':
                    break
                sleep(.05)
            else:
                raise RuntimeError('postcommit fixture barrier never reached')
            assert cap()==1 and wallet(1001)==49900
            rival_events=route(work,env,driver,hash='r3_rival_after_hold',user=1002)
            assert len(calls(rival_events,'POST'))==1 and cap()==0,rival_events
            sql(work,env,'UPDATE wallet_fixture_barrier SET released=1 WHERE id=1')
            held_events=future.result(timeout=45)
        assert len(calls(held_events,'POST'))==1 and len(calls(held_events,'QR'))==1,held_events
        assert paid(holder)==paid(rival)=='paid_with_wallet'
        assert cap()==0 and wallet(1001)==wallet(1002)==49900
        assert detail(holder).splitlines()[0]=='wallet_hold\t0\t1\tconsumed'
        assert detail(rival).splitlines()[0]=='wallet_hold\t0\t1\tconsumed'
        cases.append('r3_owned_wallet_hold_survives_postcommit_rival')

        # A separate MariaDB session changes plan dimensions AFTER wallet
        # COMMIT but BEFORE the actual standard handler runs. Dispatch must
        # keep the locked paid 30/2 quote, never the later 15/1 edit.
        frozen=newpay('r3_quoted_before_edit',1)
        try:
            drift=route(work,env,driver,hash='r3_quoted_before_edit',
                        after_wallet_commit='change_plan')
            changed=sql(work,env,'SELECT days,volume FROM server_plans WHERE id=7')
        finally:
            sql(work,env,'UPDATE server_plans SET days=30,volume=2 WHERE id=7')
        assert changed=='15\t1' and any(
            ev['event']=='SECOND_SESSION_PLAN_CHANGED' for ev in drift),drift
        assert [(ev['days'],ev['volume_gb']) for ev in calls(drift,'POST')]==[(30,2)],drift
        assert len(calls(drift,'GET'))==len(calls(drift,'QR'))==1,drift
        assert paid(frozen)=='paid_with_wallet' and wallet()==49900 and cap()==9
        assert detail(frozen)==('wallet_hold\t0\t1\tconsumed\n'
                                'create\t1\t1\tlocal_recorded')
        assert not calls(route(work,env,driver,hash='r3_quoted_before_edit'),'POST')
        cases.append('r3_locked_plan_dimensions_survive_postcommit_admin_edit')

        # Custom retains the persisted positive quote, even when a separate
        # session changes the template after COMMIT.
        custom_frozen=newpay('r3_custom_after_edit',1,custom=True)
        sql(work,env,f'UPDATE pays SET day=37,volume=7 WHERE id={custom_frozen}')
        try:
            custom_drift=route(work,env,driver,hash='r3_custom_after_edit',
                              custom=True,after_wallet_commit='change_plan')
        finally:
            sql(work,env,'UPDATE server_plans SET days=30,volume=2 WHERE id=7')
        assert [(ev['days'],ev['volume_gb']) for ev in calls(custom_drift,'POST')]==[(37,7)]
        assert paid(custom_frozen)=='paid_with_wallet'
        assert not calls(route(work,env,driver,hash='r3_custom_after_edit',custom=True),'POST')
        cases.append('r3_persisted_custom_dimensions_survive_postcommit_plan_edit')

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
        # Custom plans can deliberately have zero default template days/GB:
        # the customer's persisted custom quote is authoritative instead.
        zero_template=newpay('native_custom_zero_template',1,custom=True)
        sql(work,env,'UPDATE server_plans SET days=0,volume=0 WHERE id=7')
        try:
            accepted=route(work,env,driver,hash='native_custom_zero_template',
                           custom=True)
        finally:
            sql(work,env,'UPDATE server_plans SET days=30,volume=2 WHERE id=7')
        assert len(calls(accepted,'POST'))==1 and paid(zero_template)=='paid_with_wallet'
        assert len(calls(accepted,'QR'))==1 and wallet()==49900 and cap()==9
        assert not calls(route(work,env,driver,hash='native_custom_zero_template',
                               custom=True),'POST')
        cases.append('real_custom_zero_template_dimensions_use_persisted_quote')

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
