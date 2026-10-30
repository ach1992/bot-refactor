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
import test_post_v9_manual_payments as manual

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
// Fixture-only deterministic suffixes deliberately expose cross-case name
// reuse. Each independent invoice receives its own server remark prefix.
mt_srand(424242);
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
/**
 * Fixture-only corruption of an original 003 attempt. Normal 009 FK
 * RESTRICT blocks deletion; this isolated disposable probe deliberately
 * bypasses that FK to prove the source STILL refuses missing/replaced 003.
 * No production code or operator database uses this helper.
 */
function fixtureAlterOriginalBankAttempt($payId,$mode) {
    global $socket,$dbName;
    $db=new mysqli('localhost','root','',$dbName,0,$socket);
    if (!$db->query('SET FOREIGN_KEY_CHECKS=0'))
        throw new RuntimeException('fixture_fk_disable_denied');
    try {
        if (!$db->query('DELETE FROM payment_attempts WHERE pay_row_id='.(int)$payId) ||
            $db->affected_rows!==1)
            throw new RuntimeException('fixture_delete_original_attempt_failed');
        if ($mode==='replace') {
            $row=$db->query('SELECT id,hash_id,price FROM pays WHERE id='.(int)$payId)
                ->fetch_assoc();
            $hash=$db->real_escape_string((string)$row['hash_id']);
            $price=(int)$row['price'];
            if (!$db->query("INSERT INTO payment_attempts
                (pay_row_id,provider,order_hash,quote_amount,quote_currency,
                 local_amount_toman,stage)
                VALUES (".(int)$payId.",'bank_transfer','".$hash."',".$price.",
                        'toman',".$price.",'review')"))
                throw new RuntimeException('fixture_attempt_replacement_failed');
        }
    } finally {
        $db->query('SET FOREIGN_KEY_CHECKS=1');
        $db->close();
    }
    $GLOBALS['events'][]=['event'=>'ORIGINAL_ATTEMPT_BROKEN',
        'mode'=>$mode];
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
    if (in_array($mode,['attempt_deleted_after_get','attempt_replaced_after_get'],true) &&
        !($GLOBALS['fixture_attempt_broken']??false)) {
        fixtureAlterOriginalBankAttempt((int)($GLOBALS['spec']['pay']??0),
            $mode==='attempt_replaced_after_get'?'replace':'delete');
        $GLOBALS['fixture_attempt_broken']=true;
    }
    if (end($args)!==false) throw new RuntimeException('premature_qr_delivery');
    $GLOBALS['events'][]=['event'=>'RENDER','state'=>fixtureState()];
    return ['fixture-token','["vless://fixture.invalid"]','fixture-uuid',
        'مشخصات اشتراک','{}',$mode==='text_only'?'':'vless://fixture.invalid'];
}
function sendQrFromLink(...$args) {
    $GLOBALS['events'][]=['event'=>'DELIVER','state'=>fixtureState(),
        'empty_qr'=>($args[0]??null)==='', 'has_text'=>trim((string)($args[1]??''))!==''];
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
} elseif ($op==='break_attempt') {
    fixtureAlterOriginalBankAttempt((int)($spec['pay']??0),
        ($spec['mode']??'delete')==='replace'?'replace':'delete');
    $value=true;
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
    try: result=json.loads(p.stdout)
    except ValueError as e: raise RuntimeError('unexpected PHP output '+p.stdout[-700:]) from e
    # Synthetic-only diagnostics must survive a successful PHP exit followed
    # by a failed semantic assertion; never suppress the actual child stderr.
    result['diagnostic_stderr']=p.stderr[-8000:]
    return result

def issuer_driver(work):
    # Shared source-real disposable bank issuer for all bank/recovery fixtures.
    # No process-global path from another owned MariaDB scenario may be reused.
    driver=work/'bank-issue-actual-manual-claim.php'
    if not driver.exists():
        driver.write_text(manual.PHP)
    return driver


def invoice_namespace(work,env,pay_id):
    # Separate independent fixture invoices while keeping the same genuine
    # source buildRemark() and same prefix for two workers of ONE invoice.
    # This is display metadata set BEFORE issuance, not a physical identity
    # or a mutation while the tested financial transactions are running.
    assert int(pay_id)>0
    query(work,env,f"UPDATE server_info SET remark='bank_fixture_{int(pay_id)}' WHERE id=2")


def seed(work,env,key,count=1,plan=7,method='bank_transfer',
         days=0,volume=0,description=''):
    # New bank orders MUST originate from the REAL achbotManualClaim()
    # writing both 003 and 009 in the SAME issuance transaction.
    # This is a disposable fixture; set have_sent only AFTER issuance.
    query(work,env,"UPDATE pays SET request_date=UNIX_TIMESTAMP() WHERE state='have_sent'")
    safe_description=description.replace("'","''")
    query(work,env,
        "INSERT INTO pays (hash_id,user_id,type,plan_id,server_id,volume,day,"
        "price,request_date,state,agent_count,message_id,chat_id,description) VALUES "
        f"('{key}',1001,'BUY_SUB',{plan},2,{volume},{days},101,UNIX_TIMESTAMP()-120,"
        f"'pending',{count},550,9001,'{safe_description}')")
    pid=int(query(work,env,f"SELECT id FROM pays WHERE hash_id='{key}'"))
    invoice_namespace(work,env,pid)
    if method=='bank_transfer':
        issued=manual.run_php(work,env,issuer_driver(work),key,'claim','bank_transfer')
        if issued is not True:
            raise AssertionError(('real_customer_bank_issuance_refused',key,pid,issued))
        assert query(work,env,
            f'SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id={pid}')=='1'
        assert query(work,env,
            f'SELECT COUNT(*) FROM bank_buy_invoice_claims WHERE pay_row_id={pid}')=='1'
    elif method!='legacy':
        query(work,env,f"INSERT INTO payment_attempts "
            "(pay_row_id,provider,order_hash,quote_amount,quote_currency,local_amount_toman) "
            f"VALUES ({pid},'{method}','{key}',101,'toman',101)")
    query(work,env,f"UPDATE pays SET state='have_sent' WHERE id={pid}")
    return pid

def pending_bank(work,env,key):
    # A synthetic unpaid draft; the actual PHP manual issuer must create
    # BOTH 003 and 009 before any bank payment instructions can be shown.
    query(work,env,
        "INSERT INTO pays (hash_id,user_id,type,plan_id,server_id,volume,day,"
        "price,request_date,state,agent_count) VALUES "
        f"('{key}',1001,'BUY_SUB',7,2,0,0,101,UNIX_TIMESTAMP(),'pending',1)")
    pid=int(query(work,env,f"SELECT id FROM pays WHERE hash_id='{key}'"))
    invoice_namespace(work,env,pid)
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
        _issuer_driver=issuer_driver(work)
        query(work,env,"INSERT INTO setting(type,value) VALUES "
            "('BOT_STATES','{\"cartToCartState\":\"on\"}')")
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

        # A valid per-server qr:none is a text-only delivery, not a failed CREATE.
        query(work,env,"INSERT INTO setting(type,value) VALUES ('ACHBOT_SERVER_DISPLAY_2'," +
            "'"+json.dumps({'version':1,'server_id':2,'provider':'marzban','updated_source':1,
                'display':{'mode':'native','policy':{'qr':'none'}}})+"')")
        textonly=seed(work,env,'bankbuytextonly')
        observed=run(work,env,driver,pay=textonly,mode='text_only')
        assert state(work,env,textonly)=='auto_cart_approved',observed
        assert total(work,env,'orders_list',textonly)==1 and effects(work,env,textonly)=='local_recorded'
        deliveries=[e for e in observed['events'] if e['event']=='DELIVER']
        assert len(deliveries)==1 and deliveries[0]['empty_qr'] and deliveries[0]['has_text'],observed
        cases.append('configured_text_only_delivery_records_account_before_text_ack')
        query(work,env,"UPDATE setting SET value='invalid json' WHERE type='ACHBOT_SERVER_DISPLAY_2'")
        bad=seed(work,env,'bankbuybaddisplay')
        cap_before=query(work,env,'SELECT ucount FROM server_info WHERE id=2')
        observed=run(work,env,driver,pay=bad)
        assert not any(e['event']=='POST' for e in observed['events']),observed
        assert total(work,env,'orders_list',bad)==0
        assert query(work,env,'SELECT ucount FROM server_info WHERE id=2')==cap_before
        cases.append('malformed_server_display_blocks_before_remote_create_and_capacity')
        query(work,env,"INSERT INTO setting(type,value) VALUES ('ACHBOT_SERVER_DISPLAY_2','invalid json')")
        duplicate=seed(work,env,'bankbuyduplicatedisplay')
        observed=run(work,env,driver,pay=duplicate)
        assert not any(e['event']=='POST' for e in observed['events']),observed
        assert total(work,env,'orders_list',duplicate)==0
        assert query(work,env,'SELECT ucount FROM server_info WHERE id=2')==cap_before
        query(work,env,"DELETE FROM setting WHERE type='ACHBOT_SERVER_DISPLAY_2'")
        cases.append('duplicate_server_display_blocks_before_remote_create')

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
            frozen=seed(work,env,name,days=37 if is_custom else 0,
                        volume=7 if is_custom else 0)
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
                     days=37 if mode=='invoice_custom_to_standard' else 0,
                     volume=7 if mode=='invoice_custom_to_standard' else 0)
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

        # A real bank-transfer receipt appends photo evidence to 003's
        # checkout_url. It MUST NOT invalidate the immutable method,
        # destination, original quote, or 009 snapshot.
        photo=pending_bank(work,env,'r1a_original_receipt_photo_accepted')
        assert manual.run_php(work,env,_issuer_driver,
            'r1a_original_receipt_photo_accepted','claim','bank_transfer') is True
        photo_before=query(work,env,
            f'SELECT bank_attempt_sha256 FROM bank_buy_invoice_claims WHERE pay_row_id={photo}')
        lease=manual.run_php(work,env,_issuer_driver,
            'r1a_original_receipt_photo_accepted','begin','bank_transfer')
        assert isinstance(lease,str) and len(lease)==32
        assert manual.run_php(work,env,_issuer_driver,
            'r1a_original_receipt_photo_accepted','finish','bank_transfer',token=lease) is True
        assert 'receipt_file_id' in query(work,env,
            f'SELECT checkout_url FROM payment_attempts WHERE pay_row_id={photo}')
        query(work,env,f"UPDATE pays SET request_date=UNIX_TIMESTAMP()-120 WHERE id={photo}")
        done=run(work,env,driver,pay=photo)
        assert state(work,env,photo)=='auto_cart_approved',done
        assert sum(e['event']=='POST' for e in done['events'])==1,done
        assert sum(e['event']=='GET' for e in done['events'])==1,done
        assert amount(work,env,photo)==101
        assert query(work,env,
            f'SELECT bank_attempt_sha256 FROM bank_buy_invoice_claims WHERE pay_row_id={photo}')==photo_before
        assert not any(e['event']=='POST' for e in run(work,env,driver,pay=photo)['events'])
        cases.append('r1a_real_photo_receipt_only_extends_003_evidence_original_009_approved_once')

        # Changing the transfer DESTINATION in 003 is purchase intent
        # tampering, never a harmless evidence attachment.
        bank_change=seed(work,env,'r1a_issued_bank_destination_changed')
        query(work,env,
            "UPDATE payment_attempts SET checkout_url="
            "REPLACE(checkout_url,'6037999999999999','6037888888888888') "
            f"WHERE pay_row_id={bank_change}")
        rejected=run(work,env,driver,pay=bank_change)
        assert state(work,env,bank_change)=='have_sent',rejected
        assert not any(e['event']=='POST' for e in rejected['events'])
        cases.append('r1a_original_bank_destination_changed_before_cron_denied')

        # R1-A: original account quantity, plan duration/GB, custom mode and
        # customer description are fixed when the ACTUAL manual PHP issues
        # the original 003 bank intent. No Cron reconstruction is allowed.
        issued=seed(work,env,'r1a_original_invoice',count=3,description='issuebound')
        assert query(work,env,"SELECT b.account_count,b.quote_mode,"
            "b.entitled_days,b.entitled_volume,b.amount,a.provider "
            "FROM bank_buy_invoice_claims b JOIN payment_attempts a "
            f"ON a.id=b.bank_attempt_id WHERE b.pay_row_id={issued}"
            )=='3\tstandard\t30\t2\t101\tbank_transfer'
        cases.append('r1a_real_manual_issue_snapshots_original_quantity_entitlement_and_attempt')

        # Change CURRENT plan template AFTER bank instructions were issued but
        # BEFORE first bank-Cron scan; dispatch must retain paid 30d/2GiB.
        template=seed(work,env,'r1a_template_changed_before_cron')
        try:
            query(work,env,'UPDATE server_plans SET days=14,volume=1 WHERE id=7')
            observed=run(work,env,driver,pay=template)
            posted=[e for e in observed['events'] if e['event']=='POST']
            assert [(e['days'],e['volume_gb']) for e in posted]==[(30,2)],observed
            assert state(work,env,template)=='auto_cart_approved'
            assert amount(work,env,template)==101
            assert not any(e['event']=='POST' for e in run(work,env,driver,pay=template)['events'])
        finally:
            query(work,env,'UPDATE server_plans SET days=30,volume=2 WHERE id=7')
        cases.append('r1a_original_standard_entitlement_survives_precron_template_edit_no_replay')

        # An issued 009 is earlier authority than either mutable pays or a
        # Cron's snapshot. The paid subject can NEVER gain/lose ordinals,
        # switch quote mode, change custom units or rename paid recipients.
        for label,original,change in (
            ('quantity_changed',{'count':2},'agent_count=3'),
            ('standard_to_custom',{},'day=35,volume=6'),
            ('description_changed',{'description':'issuedname'},
                "description='newname'"),
            ('custom_dimensions_changed',{'days':37,'volume':7},
                'day=38,volume=8'),
            ('custom_to_standard',{'days':37,'volume':7},
                'day=0,volume=0'),
            ('original_server_changed',{},'server_id=1')
        ):
            pid=seed(work,env,'r1a_'+label,**original)
            old_sha=query(work,env,
                f'SELECT invoice_sha256 FROM bank_buy_invoice_claims WHERE pay_row_id={pid}')
            query(work,env,f'UPDATE pays SET {change} WHERE id={pid}')
            denied=run(work,env,driver,pay=pid)
            assert state(work,env,pid)=='have_sent',denied
            assert total(work,env,'service_effects',pid)==0
            assert total(work,env,'orders_list',pid)==0
            assert not any(e['event']=='POST' for e in denied['events'])
            assert query(work,env,
                f'SELECT invoice_sha256 FROM bank_buy_invoice_claims WHERE pay_row_id={pid}')==old_sha
            cases.append('r1a_precron_'+label+'_denied_without_remote_post')

        # The bank's randomized transfer quote is pinned at issuance, not
        # later editable just because the nominal 003 amount still matches.
        edited_quote=seed(work,env,'r1a_issued_random_quote_changed')
        query(work,env,f'UPDATE payment_attempts SET quote_amount=quote_amount+1 '
             f'WHERE pay_row_id={edited_quote}')
        stopped=run(work,env,driver,pay=edited_quote)
        assert state(work,env,edited_quote)=='have_sent',stopped
        assert not any(e['event']=='POST' for e in stopped['events'])
        cases.append('r1a_original_random_transfer_quote_changed_before_cron_denied')

        # R1-B: a formerly issued 003 is NOT a legacy NULL attempt. Its
        # original ID, method and original byte-intent must remain available.
        fk_id=seed(work,env,'r1b_fk_restrict_original_attempt')
        try:
            query(work,env,
                f'DELETE FROM payment_attempts WHERE pay_row_id={fk_id}')
        except RuntimeError:
            pass
        else:
            raise AssertionError('009 attempt FK permitted deletion of original bank intent')
        assert total(work,env,'payment_attempts',fk_id)==1
        cases.append('r1b_009_fk_restrict_protects_original_003_attempt')

        # A historical receipt without either 003 OR issued 009 cannot be
        # granted automatic CREATE based on whichever plan happens to exist.
        legacy=seed(work,env,'r1a_legacy_without_purchase_proof',method='legacy')
        nohistory=run(work,env,driver,pay=legacy)
        assert state(work,env,legacy)=='have_sent',nohistory
        assert not any(e['event']=='POST' for e in nohistory['events'])
        assert total(work,env,'bank_buy_invoice_claims',legacy)==0
        cases.append('r1a_legacy_no_attempt_no_issued_quote_operator_only')

        previous=seed(work,env,'r1a_003_without_009')
        # Simulate an older installation's 003 bank attempt: source now
        # refuses to create a new authoritative 009 during Cron.
        query(work,env,'SET FOREIGN_KEY_CHECKS=0; '
              f'DELETE FROM bank_buy_invoice_claims WHERE pay_row_id={previous}; '
              'SET FOREIGN_KEY_CHECKS=1')
        before=run(work,env,driver,pay=previous)
        assert state(work,env,previous)=='have_sent',before
        assert not any(e['event']=='POST' for e in before['events'])
        assert total(work,env,'payment_attempts',previous)==1
        cases.append('r1a_legacy_003_without_009_never_backfilled_by_cron')

        # To exercise application-level fail-closed even under a corrupted
        # or missing FK (not a real tenant), the disposable fixture explicitly
        # bypasses the local FK and removes/replaces the OLD 003.
        for label,mode in (('deleted','delete'),('replaced','replace')):
            pid=seed(work,env,'r1b_preflight_'+label)
            initial=int(query(work,env,
                f'SELECT bank_attempt_id FROM bank_buy_invoice_claims WHERE pay_row_id={pid}'))
            assert run(work,env,driver,op='claim',pay=pid)['result'] is True
            changed=run(work,env,driver,op='break_attempt',pay=pid,mode=mode)
            assert changed['result'] is True,changed
            if mode=='replace':
                current=int(query(work,env,
                    f'SELECT id FROM payment_attempts WHERE pay_row_id={pid}'))
                assert current!=initial
            else:
                assert total(work,env,'payment_attempts',pid)==0
            outcome=run(work,env,driver,op='provision_claimed',pay=pid)
            assert outcome['result']['committed'] is False,outcome
            assert state(work,env,pid)=='processing'
            assert not any(e['event']=='POST' for e in outcome['events'])
            assert total(work,env,'service_effects',pid)==0
            assert not any(e['event']=='POST' for e in
                run(work,env,driver,pay=pid)['events'])
            cases.append('r1b_original_attempt_'+label+'_after_claim_blocks_preflight')

        for label,mode in (('deleted','attempt_deleted_after_get'),
                           ('replaced','attempt_replaced_after_get')):
            pid=seed(work,env,'r1b_post_get_'+label)
            before_capacity=int(query(work,env,'SELECT ucount FROM server_info WHERE id=2'))
            outcome=run(work,env,driver,pay=pid,mode=mode)
            assert len([e for e in outcome['events'] if e['event']=='POST'])==1,outcome
            assert len([e for e in outcome['events'] if e['event']=='GET'])==1,outcome
            assert any(e['event']=='ORIGINAL_ATTEMPT_BROKEN' for e in outcome['events'])
            assert state(work,env,pid)=='processing',outcome
            assert effects(work,env,pid)=='verified'
            assert total(work,env,'orders_list',pid)==0
            assert int(query(work,env,'SELECT ucount FROM server_info WHERE id=2'))==before_capacity-1
            assert not any(e['event']=='DELIVER' for e in outcome['events'])
            assert not any(e['event']=='POST' for e in run(work,env,driver,pay=pid)['events'])
            cases.append('r1b_original_attempt_'+label+'_after_verified_get_nonterminal')

        # Earlier 003 method selection preserved nominal price independently
        # of the randomized payable transfer amount. A changed pays.price
        # BEFORE 009 claim must not become a newly 'original' bank invoice.
        intent_id=seed(work,env,'bankr1_preclaim_intent',method='bank_transfer')
        query(work,env,f'UPDATE pays SET price=105 WHERE id={intent_id}')
        blocked=run(work,env,driver,op='claim',pay=intent_id)
        assert blocked['result'] is False,blocked
        assert state(work,env,intent_id)=='have_sent'
        assert total(work,env,'bank_buy_invoice_claims',intent_id)==1
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
        assert float(query(work,env,
            f'SELECT quote_amount FROM payment_attempts WHERE pay_row_id={odd_id}'))>101
        confirmed=run(work,env,driver,pay=odd_id)
        assert confirmed['result'] is True,confirmed
        assert state(work,env,odd_id)=='auto_cart_approved'
        assert amount(work,env,odd_id)==101
        assert not any(x['event']=='POST' for x in run(work,env,driver,pay=odd_id)['events'])
        cases.append('r1_bank_random_transfer_quote_keeps_original_nominal_price')

        # After the original 009 was issued, disappearing 003 may NEVER be
        # confused with a legitimate receipt predating migration 003.
        before_claim=seed(work,env,'r1b_attempt_deleted_before_cron_claim')
        assert run(work,env,driver,op='break_attempt',
                   pay=before_claim,mode='delete')['result'] is True
        vanished=run(work,env,driver,pay=before_claim)
        assert state(work,env,before_claim)=='have_sent'
        assert not any(e['event']=='POST' for e in vanished['events'])
        assert total(work,env,'service_effects',before_claim)==0
        cases.append('r1b_deleted_original_attempt_before_first_cron_claim_denied')

        # Existing 003 method evidence without earlier issued 009 is NOT a
        # fresh bank subject. The pending customer may not reopen that intent
        # and inadvertently upgrade the current mutable plan to authority.
        historic_pending=pending_bank(work,env,'r1ab_pre009_pending')
        query(work,env,"INSERT INTO payment_attempts "
            "(pay_row_id,provider,order_hash,quote_amount,quote_currency,"
            f"local_amount_toman) VALUES ({historic_pending},'bank_transfer',"
            "'r1ab_pre009_pending',101,'toman',101)")
        reopened=manual.run_php(work,env,_issuer_driver,
            'r1ab_pre009_pending','claim','bank_transfer')
        assert reopened is False,reopened
        assert state(work,env,historic_pending)=='pending'
        assert total(work,env,'bank_buy_invoice_claims',historic_pending)==0
        cases.append('r1a_existing_003_without_issued_009_not_reopened_or_backfilled')

        # Fail the real issuance INSERT using disposable SQL, not a Python
        # decision stub. Its 003 attempt MUST roll back in the same tx.
        failed_issuer=pending_bank(work,env,'r1ab_009_sql_failure')
        query(work,env,"CREATE TRIGGER fixture_bank_009_fail "
            "BEFORE INSERT ON bank_buy_invoice_claims FOR EACH ROW "
            "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture_issue_denied'")
        try:
            refused=manual.run_php(work,env,_issuer_driver,
                'r1ab_009_sql_failure','claim','bank_transfer')
        finally:
            query(work,env,'DROP TRIGGER fixture_bank_009_fail')
        assert refused is False,refused
        assert state(work,env,failed_issuer)=='pending'
        assert total(work,env,'payment_attempts',failed_issuer)==0
        assert total(work,env,'bank_buy_invoice_claims',failed_issuer)==0
        cases.append('r1a_failed_009_issue_rolls_back_original_003_atomically')

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
        missing=seed(work,env,'bankr1_missing_009')
        query(work,env,'RENAME TABLE bank_buy_invoice_claims TO fixture_hidden_bank_claim')
        try:
            # Missing 009 blocks issuing NEW bank instructions. An attempt
            # must not be committed on its own, even if 003 is available.
            newpending=pending_bank(work,env,'r1a_missing_009_during_issue')
            issue=manual.run_php(work,env,_issuer_driver,
                'r1a_missing_009_during_issue','claim','bank_transfer')
            assert issue is False,issue
            assert state(work,env,newpending)=='pending'
            assert total(work,env,'payment_attempts',newpending)==0
            cases.append('r1a_missing_009_refuses_new_bank_intent_without_half_commit')
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

        # A real duplicate remote identity remains a refusal. Namespace
        # isolation must never hide or relax the unique journal invariant.
        first=seed(work,env,'bank_fixture_collision_first',description='fixture_same_remote_name')
        accepted=run(work,env,driver,pay=first)
        assert state(work,env,first)=='auto_cart_approved',accepted
        assert sum(e['event']=='POST'for e in accepted['events'])==1,accepted
        duplicate=seed(work,env,'bank_fixture_collision_duplicate',description='fixture_same_remote_name')
        capacity_before=int(query(work,env,'SELECT ucount FROM server_info WHERE id=2'))
        wallet_before=query(work,env,'SELECT wallet FROM users WHERE userid=1001')
        denied=run(work,env,driver,pay=duplicate)
        assert state(work,env,duplicate)=='processing',denied
        assert not any(e['event']=='POST'for e in denied['events']),denied
        assert total(work,env,'service_effects',duplicate)==0
        assert total(work,env,'orders_list',duplicate)==0
        assert total(work,env,'service_effects',first)==1
        assert int(query(work,env,'SELECT ucount FROM server_info WHERE id=2'))==capacity_before
        assert query(work,env,'SELECT wallet FROM users WHERE userid=1001')==wallet_before
        cases.append('explicit_duplicate_username_refused_before_post_without_financial_effect')
        replay=run(work,env,driver,pay=duplicate)
        assert not any(e['event']=='POST'for e in replay['events']),replay
        assert state(work,env,duplicate)=='processing'
        assert total(work,env,'service_effects',duplicate)==0
        assert total(work,env,'orders_list',duplicate)==0
        assert int(query(work,env,'SELECT ucount FROM server_info WHERE id=2'))==capacity_before
        assert query(work,env,'SELECT wallet FROM users WHERE userid=1001')==wallet_before
        cases.append('duplicate_username_hold_never_replayed_or_refunded')

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
        installed_operator_tested=False,fixture_mt_seed=424242,
        independent_invoice_namespaces=True,successful_exit_stderr_retained=True)

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--run-disposable',action='store_true',required=True)
    parser.add_argument('--report',type=Path)
    args=parser.parse_args()
    result=check()
    if args.report:args.report.write_text(json.dumps(result,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps(result,ensure_ascii=False))


# Current domain fixture imports the real event-aware notification router.
import notification_fixture
PHP=notification_fixture.with_router(PHP)

if __name__=='__main__':main()
