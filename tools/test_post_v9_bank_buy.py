#!/usr/bin/env python3
"""AChBot #26: real current bank-Cron PHP + socket-only disposable MariaDB.

Real SQL, source routes and journal are exercised against synthetic remote
CREATE/GET and Telegram spies. No actual bot, paid provider or customer.
"""
from __future__ import annotations

import argparse
import json
import subprocess
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

import test_access_state as owned
import test_v6_native as native
import test_v8_native as purchase

ROOT=Path(__file__).resolve().parents[1]
DB=purchase.SIDE[1]
PHP=r"""<?php
error_reporting(E_ALL);
set_error_handler(static function($level,$msg) {
    if ($level===E_DEPRECATED) return true;
    throw new ErrorException($msg,0,$level);
});
$_SERVER['SCRIPT_FILENAME']=__FILE__;
$root=$argv[1];$socket=$argv[2];$dbName=$argv[3];
$spec=json_decode($argv[4],true,512,JSON_THROW_ON_ERROR);
$connection=new mysqli('localhost','root','',$dbName,0,$socket);
$connection->set_charset('utf8mb4');
if (!empty($spec['nonthrowing'])) mysqli_report(MYSQLI_REPORT_OFF);
$botState=['cartToCartAutoAcceptState'=>'on','cartToCartAutoAcceptTime'=>1,
    'cartToCartAutoAcceptType'=>2,'remark'=>'default','subLinkState'=>'on',
    'configLinkState'=>'off'];
$admin=9001;$botUrl='https://fixture.invalid';
$buttonValues=['start_bot'=>'Start'];$linkIndices=[0];$hideConfigIfMany=false;
$GLOBALS['events']=[];$GLOBALS['spec']=$spec;
function fixtureState() {
    global $connection;
    $id=(int)($GLOBALS['spec']['pay']??0);
    $result=$connection->query('SELECT state FROM pays WHERE id='.$id);
    return $result?($result->fetch_row()[0]??null):null;
}
function createServerAccount(...$args) {
    $mode=$GLOBALS['spec']['mode']??'ok';
    $n=($GLOBALS['posts']??0)+1;$GLOBALS['posts']=$n;
    $name=(string)$args[4];$operation=(string)$args[14];
    $GLOBALS['events'][]=['event'=>'POST','line'=>$n,
        'username'=>$name,'state'=>fixtureState(),
        'days'=>(int)$args[6],'volume_gb'=>(int)$args[5]];
    $GLOBALS['remote']=['username'=>$name,'note'=>'achbot:v1:'.$operation,
        'expire'=>(int)$args[15],'data_limit'=>(int)$args[5]*1073741824];
    if ($mode==='lost' || ($mode==='partial' && $n===2))
        throw new RuntimeException('synthetic_post_response_lost');
    if ($mode==='exists')
        return [(object)['success'=>false,'msg'=>'User already exists'],$name,12345];
    return [(object)['success'=>true,'sub_link'=>'/sub/fixture',
        'vray_links'=>['vless://fixture.invalid']],$name,12345];
}
function getMarzbanUser($sid,$name,$token=null,$strict=false) {
    if ($strict!==true) throw new RuntimeException('non_strict_remote_get');
    $GLOBALS['events'][]=['event'=>'GET','username'=>$name];
    $remote=$GLOBALS['remote']??null;
    if (!$remote) return null;
    $mode=$GLOBALS['spec']['mode']??'ok';
    if ($mode==='wrong_note') $remote['note']='other-party';
    if ($mode==='fraction') $remote['data_limit']+=0.5;
    return (object)$remote;
}
function buildLinksAndSendQr(...$args) {
    // Inject a REAL independent MariaDB plan edit only after the remote
    // CREATE/strict GET has been durably journaled, before local finality.
    // This hook exists only in this disposable synthetic PHP fixture.
    $mode=(string)($GLOBALS['spec']['mode']??'');
    if (in_array($mode,['plan_dimensions_drift','plan_target_drift'],true) &&
        !($GLOBALS['fixture_plan_edited']??false)) {
        global $socket,$dbName;
        $second=new mysqli('localhost','root','',$dbName,0,$socket);
        $sql=$mode==='plan_dimensions_drift'
            ? 'UPDATE server_plans SET days=15,volume=1 WHERE id=7'
            : 'UPDATE server_plans SET inbound_id=1 WHERE id=7';
        if (!$second->query($sql) || $second->affected_rows!==1)
            throw new RuntimeException('fixture_external_plan_edit_failed');
        $second->close();
        $GLOBALS['fixture_plan_edited']=true;
        $GLOBALS['events'][]=['event'=>'POST_VERIFIED_PLAN_CHANGED',
            'change'=>$mode];
    }
    if (in_array($mode,[
        'invoice_amount_drift','invoice_mode_drift',
        'invoice_custom_to_standard','invoice_coordinated_drift',
        'bank_intent_amount_drift'
    ],true) && !($GLOBALS['fixture_invoice_edited']??false)) {
        // A DIFFERENT SQL connection changes the paid subject only after
        // the real source verified the provider GET, before its local COMMIT.
        global $socket,$dbName;
        $second=new mysqli('localhost','root','',$dbName,0,$socket);
        $payId=(int)($GLOBALS['spec']['pay']??0);
        $set=match($mode) {
            'bank_intent_amount_drift'=>"local_amount_toman=102",
            'invoice_amount_drift'=>"price=102",
            'invoice_mode_drift'=>"day=37,volume=7",
            'invoice_custom_to_standard'=>"day=0,volume=0",
            'invoice_coordinated_drift'=>"price=102,agent_count=2,day=37,volume=7,"
                ."description='different_invoice',agent_bought=1",
        };
        $table=$mode==='bank_intent_amount_drift'?'payment_attempts':'pays';
        $where=$mode==='bank_intent_amount_drift'?'pay_row_id':'id';
        if (!$second->query("UPDATE ".$table." SET ".$set." WHERE ".$where."=".$payId) ||
            $second->affected_rows!==1)
            throw new RuntimeException('fixture_invoice_mutation_failed');
        $second->close();
        $GLOBALS['fixture_invoice_edited']=true;
        $GLOBALS['events'][]=['event'=>'POST_VERIFIED_INVOICE_CHANGED','mode'=>$mode];
    }
    if (end($args)!==false) throw new RuntimeException('premature_qr_delivery');
    $GLOBALS['events'][]=['event'=>'RENDER','state'=>fixtureState()];
    return ['fixture-token','["vless://fixture.invalid"]','fixture-uuid',
        'مشخصات اشتراک','{}','vless://fixture.invalid'];
}
function sendQrFromLink(...$args) {
    $GLOBALS['events'][]=['event'=>'DELIVER','state'=>fixtureState()];
    if (($GLOBALS['spec']['mode']??'')==='telegram_failure')
        throw new RuntimeException('synthetic_telegram_failure');
    if (($GLOBALS['spec']['mode']??'')==='telegram_unconfirmed') return false;
    return true;
}
function sendMessage($text=null,$keys=null,$mode=null,$to=null) {
    $GLOBALS['events'][]=['event'=>'MESSAGE','state'=>fixtureState(),
        'success'=>strpos((string)$text,'موفق')!==false,'to'=>$to,'text'=>(string)$text];
    return (object)['ok'=>true];
}
function editKeys(...$args) {
    $GLOBALS['events'][]=['event'=>'EDIT_KEYS','state'=>fixtureState()];
}
function getMainKeys() {return '{}';}
function sendToAllAdmins(...$args) {return null;}
function giveReferralReward(...$args) {$GLOBALS['events'][]=['event'=>'REFERRAL'];}
function generateRandomString($count,$protocol=null) {return str_repeat('a',max(1,(int)$count));}
function achbotApplyServiceEdit(...$args) {throw new RuntimeException('unexpected_edit');}
function achbotSupportsPanel($type) {return $type==='marzban';}
require_once $root.'/src/Purchase/Core.php';
require_once $root.'/src/Purchase/OrderWrites.php';
require_once $root.'/src/Finance/ReceiptHelpers.php';
require_once $root.'/src/Finance/ServiceEffects.php';
$op=$spec['op']??'auto';
if ($op==='claim') {
    $id=(int)($spec['pay']??0);
    $pay=$connection->query('SELECT * FROM pays WHERE id='.$id)->fetch_assoc();
    $value=achbotAutoBankClaim($connection,$pay);
} elseif ($op==='provision_claimed') {
    require_once $root.'/src/Finance/BankPurchase.php';
    $id=(int)($spec['pay']??0);
    $pay=$connection->query('SELECT * FROM pays WHERE id='.$id)->fetch_assoc();
    try {
        $value=achbotAutoBankProvisionBuySub(
            $connection,$pay,$botState,$buttonValues,[0],false,$botUrl);
    } catch (Throwable $error) {
        $value=['committed'=>false,'deferred'=>true];
    }
} else {
    require $root.'/src/Finance/AutomaticReceipts.php';
    $value=true;
}
echo json_encode(['result'=>$value,'events'=>$GLOBALS['events']],
    JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
"""

def query(work,env,sql,db=DB):
    return native.sql(work,env,sql,db).strip()

def run(work,env,driver,**spec):
    args=['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
          '-d','allow_url_fopen=0','-d','display_errors=0',
          '-d','disable_functions=curl_init,curl_exec,exec,shell_exec,system,passthru,popen,proc_open,mail',
          str(driver),str(ROOT),str(work/'mysql.sock'),DB,json.dumps(spec)]
    p=subprocess.run(args,cwd=work,env=env,capture_output=True,text=True,timeout=25)
    if p.returncode or not p.stdout.strip():
        raise RuntimeError('native bank PHP failed '+repr((p.returncode,p.stderr[-1250:])))
    try: return json.loads(p.stdout)
    except ValueError as e: raise RuntimeError('unexpected PHP output '+p.stdout[-700:]) from e

def seed(work,env,key,count=1,plan=7,method=None):
    # Only this due payment is processed in the current synthetic Cron scan.
    query(work,env,"UPDATE pays SET request_date=UNIX_TIMESTAMP() WHERE state='have_sent'")
    query(work,env,
        "INSERT INTO pays (hash_id,user_id,type,plan_id,server_id,volume,day,"
        "price,request_date,state,agent_count,message_id,chat_id) VALUES "
        f"('{key}',1001,'BUY_SUB',{plan},2,0,0,101,UNIX_TIMESTAMP()-120,"
        f"'have_sent',{count},550,9001)")
    pid=int(query(work,env,f"SELECT id FROM pays WHERE hash_id='{key}'"))
    if method:
        query(work,env,f"INSERT INTO payment_attempts "
           "(pay_row_id,provider,order_hash,quote_amount,quote_currency,local_amount_toman) "
           f"VALUES ({pid},'{method}','{key}',101,'toman',101)")
    return pid

def state(work,env,pid):
    return query(work,env,f"SELECT state FROM pays WHERE id={pid}")

def amount(work,env,pid):
    return int(query(work,env,
        "SELECT COALESCE(SUM(o.amount),0) FROM orders_list o "
        "JOIN service_effects e ON e.order_id=o.id "
        f"WHERE e.pay_row_id={pid}"))

def effects(work,env,pid):
    return query(work,env,f"SELECT GROUP_CONCAT(state ORDER BY ordinal) "
               f"FROM service_effects WHERE pay_row_id={pid}")

def total(work,env,table,pid):
    if table=='orders_list':
        return int(query(work,env,
           "SELECT COUNT(*) FROM service_effects e JOIN orders_list o "
           f"ON o.id=e.order_id WHERE e.pay_row_id={pid}"))
    return int(query(work,env,
        f"SELECT COUNT(*) FROM {table} WHERE pay_row_id={pid}"))

def check():
    cases=[]
    with owned.owned_database() as (work,env):
        purchase.init(work,env,'http://127.0.0.1:9')
        # Persistent pre-POST reservations survive uncertain provider calls.
        # Keep the broad isolation matrix independent of exhaustion.
        query(work,env,'UPDATE server_info SET ucount=100 WHERE id=2')
        assert len(query(work,env,'SHOW TABLES').splitlines())==23
        for f in ('001_marzban_panel_group_id.sql',
                  '003_payment_attempts.sql','007_service_effects.sql',
                  '009_bank_buy_invoice_claims.sql'):
            query(work,env,(ROOT/'migrations/post-v9'/f).read_text())
        driver=work/'bank-buy-driver.php';driver.write_text(PHP)
        claim=seed(work,env,'bankbuynonterminal')
        assert run(work,env,driver,op='claim',pay=claim)['result'] is True
        assert state(work,env,claim)=='processing'
        assert run(work,env,driver,op='claim',pay=claim)['result'] is False
        cases.append('buy_sub_nonterminal_exclusive_claim')

        good=seed(work,env,'bankbuygood')
        ok=run(work,env,driver,pay=good)
        assert state(work,env,good)=='auto_cart_approved',ok
        assert [e['event'] for e in ok['events']
            if e['event'] in ('POST','GET','DELIVER')]==['POST','GET','DELIVER'],ok
        assert all(e['state']=='auto_cart_approved' for e in ok['events']
            if e['event']=='DELIVER')
        assert total(work,env,'orders_list',good)==1
        assert amount(work,env,good)==101
        assert effects(work,env,good)=='local_recorded'
        assert not any(e['event']=='POST' for e in run(work,env,driver,pay=good)['events'])
        cases.append('one_verified_account_atomic_order_before_telegram_no_replay')

        for mode in ('lost','exists','wrong_note','fraction'):
            pid=seed(work,env,'bankbuy'+mode)
            result=run(work,env,driver,pay=pid,mode=mode)
            assert state(work,env,pid)=='processing',(mode,result)
            assert total(work,env,'orders_list',pid)==0
            assert effects(work,env,pid)=='dispatching'
            assert not any(e['event']=='DELIVER' or
                (e['event']=='MESSAGE' and e['success']) for e in result['events'])
            assert not any(e['event']=='POST' for e in run(work,env,driver,pay=pid)['events'])
            admin=[e for e in result['events'] if e['event']=='MESSAGE' and e['to']==9001]
            assert len(admin)==1 and 'شناسه پرداخت' in admin[0]['text'] and str(pid) in admin[0]['text'] and '1001' in admin[0]['text'] and '101' in admin[0]['text'],(mode,admin)
            cases.append('unknown_'+mode+'_no_refund_success_or_replay')

        multi=seed(work,env,'bankbuymulti',count=2)
        success=run(work,env,driver,pay=multi)
        assert state(work,env,multi)=='auto_cart_approved',success
        assert effects(work,env,multi)=='local_recorded,local_recorded'
        assert total(work,env,'orders_list',multi)==2 and amount(work,env,multi)==101
        assert len([e for e in success['events'] if e['event']=='DELIVER'])==2
        cases.append('multi_line_atomic_orders_and_integer_amount_allocation')

        # After an exact provider CREATE + readback and its durable 007
        # verified effect, an unrelated administrator can edit the plan.
        # That must not invalidate the already-owned paid entitlement and
        # leave an otherwise complete service stranded in processing.
        for name,is_custom in (('bankplanlate_standard',False),
                               ('bankplanlate_custom',True)):
            frozen=seed(work,env,name)
            if is_custom:
                query(work,env,f'UPDATE pays SET day=37,volume=7 WHERE id={frozen}')
            capacity_before=int(query(work,env,'SELECT ucount FROM server_info WHERE id=2'))
            try:
                plan_before=query(work,env,'SELECT days,volume FROM server_plans WHERE id=7')
                observed=run(work,env,driver,pay=frozen,mode='plan_dimensions_drift')
                changed=query(work,env,'SELECT days,volume FROM server_plans WHERE id=7')
            finally:
                query(work,env,'UPDATE server_plans SET days=30,volume=2 WHERE id=7')
            assert plan_before=='30\t2' and changed=='15\t1'
            assert any(x['event']=='POST_VERIFIED_PLAN_CHANGED'
                       for x in observed['events']),observed
            expected=(37,7) if is_custom else (30,2)
            posted=[x for x in observed['events'] if x['event']=='POST']
            assert len(posted)==1 and (posted[0]['days'],posted[0]['volume_gb'])==expected,observed
            assert sum(x['event']=='GET' for x in observed['events'])==1,observed
            assert state(work,env,frozen)=='auto_cart_approved',observed
            assert effects(work,env,frozen)=='local_recorded'
            assert total(work,env,'orders_list',frozen)==1
            assert amount(work,env,frozen)==101
            assert int(query(work,env,'SELECT ucount FROM server_info WHERE id=2'))==capacity_before-1
            assert len([x for x in observed['events'] if x['event']=='DELIVER'
                        and x['state']=='auto_cart_approved'])==1,observed
            assert not any(x['event']=='POST' for x in run(work,env,driver,pay=frozen)['events'])
            assert int(query(work,env,'SELECT ucount FROM server_info WHERE id=2'))==capacity_before-1
            cases.append('postverified_plan_dimensions_edit_keeps_'+
                         ('custom_quote' if is_custom else 'standard_quote')+'_and_order')

        # R1: mutable pays fields are NOT immutable invoice evidence. Source
        # has already posted and STRICTLY GET-verified the provider by this
        # point; every changed original quote/amount must remain reviewable.
        for mode in ('invoice_amount_drift','invoice_mode_drift',
                     'invoice_custom_to_standard','invoice_coordinated_drift',
                     'bank_intent_amount_drift'):
            pid=seed(work,env,'bankr1_'+mode,
                     method='bank_transfer' if mode=='bank_intent_amount_drift' else None)
            if mode=='invoice_custom_to_standard':
                query(work,env,f'UPDATE pays SET day=37,volume=7 WHERE id={pid}')
            original_price=int(query(work,env,f'SELECT price FROM pays WHERE id={pid}'))
            before_capacity=int(query(work,env,'SELECT ucount FROM server_info WHERE id=2'))
            outcome=run(work,env,driver,pay=pid,mode=mode)
            assert any(e['event']=='POST_VERIFIED_INVOICE_CHANGED'
                for e in outcome['events']),outcome
            assert sum(e['event']=='POST' for e in outcome['events'])==1,outcome
            assert sum(e['event']=='GET' for e in outcome['events'])==1,outcome
            assert state(work,env,pid)=='processing',outcome
            assert effects(work,env,pid)=='verified'
            assert total(work,env,'orders_list',pid)==0
            assert amount(work,env,pid)==0
            assert int(query(work,env,'SELECT ucount FROM server_info WHERE id=2'))==before_capacity-1
            assert not any(e['event']=='DELIVER' or
                (e['event']=='MESSAGE' and e['success']) for e in outcome['events']),outcome
            assert not any(e['event']=='POST' for e in run(work,env,driver,pay=pid)['events'])
            assert int(query(work,env,'SELECT ucount FROM server_info WHERE id=2'))==before_capacity-1
            assert int(query(work,env,f'SELECT amount FROM bank_buy_invoice_claims WHERE pay_row_id={pid}'))==original_price
            cases.append('r1_postverified_'+mode+'_keeps_original_invoice_nonterminal')

        # Earlier 003 method selection preserved nominal price independently
        # of the randomized payable transfer amount. A changed pays.price
        # BEFORE 009 claim must not become a newly 'original' bank invoice.
        intent_id=seed(work,env,'bankr1_preclaim_intent',method='bank_transfer')
        query(work,env,f'UPDATE pays SET price=105 WHERE id={intent_id}')
        blocked=run(work,env,driver,op='claim',pay=intent_id)
        assert blocked['result'] is False,blocked
        assert state(work,env,intent_id)=='have_sent'
        assert total(work,env,'bank_buy_invoice_claims',intent_id)==0
        assert not any(x['event']=='POST' for x in blocked['events'])
        cases.append('r1_issued_bank_amount_differs_before_claim_no_new_original')

        # A 003 bank attempt reassigned to a different method after exclusive
        # claim must be refused BEFORE POST, even with unchanged pays fields.
        method_id=seed(work,env,'bankr1_preflight_method',method='bank_transfer')
        assert run(work,env,driver,op='claim',pay=method_id)['result'] is True
        query(work,env,f"UPDATE payment_attempts SET provider='gift_card' "
             f"WHERE pay_row_id={method_id}")
        wrong_method=run(work,env,driver,op='provision_claimed',pay=method_id)
        assert wrong_method['result']['committed'] is False,wrong_method
        assert not any(e['event']=='POST' for e in wrong_method['events'])
        assert state(work,env,method_id)=='processing'
        assert total(work,env,'service_effects',method_id)==0
        cases.append('r1_bank_method_drift_after_claim_no_remote_post')

        # The randomized transfer quote need not equal nominal order amount.
        # 003 local_amount_toman, rather than quote_amount, is the anchor.
        odd_id=seed(work,env,'bankr1_random_quote',method='bank_transfer')
        query(work,env,f'UPDATE payment_attempts SET quote_amount=98765 '
             f'WHERE pay_row_id={odd_id}')
        confirmed=run(work,env,driver,pay=odd_id)
        assert confirmed['result'] is True,confirmed
        assert state(work,env,odd_id)=='auto_cart_approved'
        assert amount(work,env,odd_id)==101
        assert not any(x['event']=='POST' for x in run(work,env,driver,pay=odd_id)['events'])
        cases.append('r1_bank_random_transfer_quote_keeps_original_nominal_price')

        # A paid subject edited AFTER exclusive claim but BEFORE preflight
        # also must never reach POST or terminal local accounting.
        preclaim=seed(work,env,'bankr1_preflight_changed')
        assert run(work,env,driver,op='claim',pay=preclaim)['result'] is True
        query(work,env,f'UPDATE pays SET price=104,day=31,volume=4 WHERE id={preclaim}')
        before_pre=int(query(work,env,'SELECT ucount FROM server_info WHERE id=2'))
        refused=run(work,env,driver,op='provision_claimed',pay=preclaim)
        assert refused['result']['committed'] is False,refused
        assert not any(e['event']=='POST' for e in refused['events'])
        assert effects(work,env,preclaim) in ('','NULL') and state(work,env,preclaim)=='processing',(
            effects(work,env,preclaim),state(work,env,preclaim),refused)
        assert int(query(work,env,'SELECT ucount FROM server_info WHERE id=2'))==before_pre
        cases.append('r1_postclaim_preflight_mutation_never_posts')

        # The 009 durable invoice contract is MANDATORY, not a silently
        # optional ledger. Missing schema keeps an untouched bank receipt.
        query(work,env,'RENAME TABLE bank_buy_invoice_claims TO fixture_hidden_bank_claim')
        try:
            missing=seed(work,env,'bankr1_missing_009')
            denied=run(work,env,driver,pay=missing)
            assert state(work,env,missing)=='have_sent',denied
            assert not any(e['event']=='POST' for e in denied['events'])
            assert total(work,env,'service_effects',missing)==0
        finally:
            query(work,env,'RENAME TABLE fixture_hidden_bank_claim TO bank_buy_invoice_claims')
        cases.append('r1_missing_invoice_contract_before_claim_fails_closed')

        # Plan identity and physical inbound are NOT mutable entitlements:
        # a target edit after a verified effect must still fail closed.
        changed_target=seed(work,env,'bankplanlate_target')
        before_target_capacity=int(query(work,env,'SELECT ucount FROM server_info WHERE id=2'))
        try:
            target_events=run(work,env,driver,pay=changed_target,mode='plan_target_drift')
            assert query(work,env,'SELECT inbound_id FROM server_plans WHERE id=7')=='1'
        finally:
            query(work,env,'UPDATE server_plans SET inbound_id=0 WHERE id=7')
        assert state(work,env,changed_target)=='processing',target_events
        assert effects(work,env,changed_target)=='verified'
        assert total(work,env,'orders_list',changed_target)==0
        assert int(query(work,env,'SELECT ucount FROM server_info WHERE id=2'))==before_target_capacity-1
        assert not any(x['event']=='DELIVER' for x in target_events['events']),target_events
        assert not any(x['event']=='POST' for x in run(work,env,driver,pay=changed_target)['events'])
        assert int(query(work,env,'SELECT ucount FROM server_info WHERE id=2'))==before_target_capacity-1
        cases.append('postverified_plan_physical_target_edit_stays_under_review')

        partial=seed(work,env,'bankbuypartial',count=2)
        mixed=run(work,env,driver,pay=partial,mode='partial')
        assert state(work,env,partial)=='processing',mixed
        assert effects(work,env,partial)=='verified,dispatching'
        assert total(work,env,'orders_list',partial)==0
        assert not any(e['event']=='DELIVER' for e in mixed['events'])
        cases.append('multi_line_partial_unknown_requires_review_not_refund')

        sqlfail=seed(work,env,'bankbuysqlfail')
        query(work,env,"CREATE TRIGGER fixture_bank_order_fail BEFORE INSERT ON orders_list "
            "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture_sql_failure'")
        try: failed=run(work,env,driver,pay=sqlfail,nonthrowing=True)
        finally: query(work,env,'DROP TRIGGER fixture_bank_order_fail')
        assert state(work,env,sqlfail)=='processing',failed
        assert effects(work,env,sqlfail)=='verified'
        assert total(work,env,'orders_list',sqlfail)==0
        assert not any(e['event']=='DELIVER' for e in failed['events'])
        cases.append('sql_failure_after_remote_verified_fails_closed')
        notices=[e for e in failed['events'] if e['event']=='MESSAGE' and e['to']==9001]
        assert len(notices)==1 and 'شناسه پرداخت' in notices[0]['text'] and str(sqlfail) in notices[0]['text'] and '1001' in notices[0]['text'] and '101' in notices[0]['text'],notices
        cases.append('failed_local_commit_operator_receives_payment_identity')

        for mode in ('telegram_failure','telegram_unconfirmed'):
            notice=seed(work,env,'bankbuy'+mode)
            result=run(work,env,driver,pay=notice,mode=mode)
            assert state(work,env,notice)=='auto_cart_approved',result
            assert total(work,env,'orders_list',notice)==1
            assert not any(e['event']=='POST' for e in run(work,env,driver,pay=notice)['events'])
            alerts=[e for e in result['events'] if e['event']=='MESSAGE' and e['to']==9001]
            assert len(alerts)==1 and 'تحویل' in alerts[0]['text'] and str(notice) in alerts[0]['text'] and '1001' in alerts[0]['text'],(mode,alerts)
            cases.append('postcommit_'+mode+'_alerts_operator_without_recreate')

        wrong=seed(work,env,'bankbuyotherprovider',method='nowpayments')
        blocked=run(work,env,driver,pay=wrong)
        assert state(work,env,wrong)=='have_sent',blocked
        assert total(work,env,'service_effects',wrong)==0
        assert not any(e['event']=='POST' for e in blocked['events'])
        cases.append('nonbank_attempt_never_auto_provisions')

        race=seed(work,env,'bankbuyrace')
        with ThreadPoolExecutor(max_workers=2) as pool:
            results=list(pool.map(lambda _:run(work,env,driver,pay=race),range(2)))
        assert sum(e['event']=='POST' for result in results
            for e in result['events'])==1,results
        assert state(work,env,race)=='auto_cart_approved'
        cases.append('two_simultaneous_bank_workers_one_remote_create')
    return dict(complete=True,cases=len(cases),scenarios=cases,
        source_real_php=True,owned_mariadb_only=True,
        real_provider_requests=0,real_financial_effects=0,
        installed_operator_tested=False)

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--run-disposable',action='store_true',required=True)
    parser.add_argument('--report',type=Path)
    args=parser.parse_args()
    result=check()
    if args.report:args.report.write_text(json.dumps(result,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps(result,ensure_ascii=False))

if __name__=='__main__':main()
