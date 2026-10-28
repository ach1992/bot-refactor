#!/usr/bin/env python3
"""Owned socket-only MariaDB automatic-renewal journal and native PHP tests.

No live payment, Telegram, Marzban, production database or external network.
"""
import argparse
import json
import re
import subprocess
import time
from pathlib import Path
import test_access_state as owned
import test_v6_native as native
import test_v8_native as purchase

ROOT=Path(__file__).resolve().parents[1]
DB=purchase.SIDE[1]
PHP=r'''<?php
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(function($sev,$msg){throw new ErrorException($msg,0,$sev);});
$_SERVER['SCRIPT_FILENAME']='owned-renew-php.php';
$root=$argv[1];
$GLOBALS['root']=$root;
$spec=json_decode($argv[4],true,512,JSON_THROW_ON_ERROR);
$GLOBALS['spec']=$spec;
// Exercise error-return handling rather than relying on mysqli exceptions.
if (!empty($spec['nonthrowing_sql'])) mysqli_report(MYSQLI_REPORT_OFF);
$connection=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$connection->set_charset('utf8mb4');
// Loading function definitions must not run the shared Cron sweep when the
// test is exercising an individual owned transaction. Sweep has its own case.
$botState=['renewAutoState'=>'off'];
$admin=null;
$events=[];
require_once $root.'/src/Purchase/Core.php';
function getMarzbanUser($id,$remark) {
    $GLOBALS['events'][]=['type'=>'read'];
    return (object)['username'=>$remark,
                    'expire'=>time()+($GLOBALS['spec']['expire_delta']??1000),
                    'data_limit'=>1073741824,'used_traffic'=>1060000000,
                    'status'=>'active'];
}
function editMarzbanConfig($id,$info) {
    $oid=(int)$GLOBALS['spec']['order_id'];
    $stmt=$GLOBALS['connection']->prepare(
        "SELECT stage FROM automatic_renewal_attempts WHERE order_id=? ORDER BY id DESC LIMIT 1");
    $stmt->bind_param('i',$oid);$stmt->execute();
    $stage=$stmt->get_result()->fetch_assoc()['stage']??'absent';$stmt->close();
    if($stage!=='remote_started') throw new RuntimeException('remote_started not persisted');
    $GLOBALS['events'][]=['type'=>'remote','stage'=>$stage];
    if(!empty($GLOBALS['spec']['delay_ms'])) usleep((int)$GLOBALS['spec']['delay_ms']*1000);
    if(!empty($GLOBALS['spec']['drift'])) {
        $stmt=$GLOBALS['connection']->prepare("UPDATE orders_list SET expire_date=expire_date+7 WHERE id=?");
        $stmt->bind_param('i',$oid);$stmt->execute();$stmt->close();
    }
    if(!empty($GLOBALS['spec']['throw']))throw new RuntimeException('mock ambiguous timeout');
    return (object)['success'=>$GLOBALS['spec']['remote_success']??true,
                    'verified'=>$GLOBALS['spec']['remote_success']??true,
                    'applied_expire'=>$GLOBALS['spec']['applied_expire']??(time()+30*86400),
                    'applied_bytes'=>10*1073741824];
}
function sendMessage($message,$keys=null,$parse=null,$id=null) {
    $GLOBALS['events'][]=['type'=>'send','to'=>$id];
    return (object)['ok'=>$GLOBALS['spec']['telegram_ok']??true];
}
function deleteMarzban($serverId,$remark) {
    $GLOBALS['events'][]=['type'=>'remote_delete','server'=>$serverId];
    if (!empty($GLOBALS['spec']['official_http_provider'])) {
        require_once $GLOBALS['root'].'/src/Panel/Accounts.php';
        return \AchBot\Panel\Accounts\deleteMarzban($serverId,$remark);
    }
    return (object)['success'=>$GLOBALS['spec']['remote_delete_ok']??true];
}
function achbotSupportsPanel($type) {return $type==='marzban';}
function sumerize($bytes) {return '0';}
function alert($message,$modal=false) {
    $GLOBALS['events'][]=['type'=>'alert','modal'=>$modal];
}
function editText($id,$message,$keyboard=null,$parse=null) {
    $GLOBALS['events'][]=['type'=>'editText','to'=>$id];
    $keys=is_string($keyboard)?json_decode($keyboard,true):$keyboard;
    foreach (($keys['inline_keyboard']??[]) as $row) foreach ($row as $button) {
        if (preg_match('/^confirmDeleteOrder_([1-9][0-9]*)$/D',(string)($button['callback_data']??''),$m))
            $GLOBALS['events'][]=['type'=>'local_delete_confirmation','order_id'=>(int)$m[1]];
    }
}
function sendToAllAdmins($type,$data) {
    $GLOBALS['events'][]=['type'=>'notify_admins'];
}
function setUser($step='none') {
    $GLOBALS['events'][]=['type'=>'setUser','step'=>$step];
}
require_once $root.'/src/Purchase/Core.php';
require_once $root.'/src/Finance/AutomaticRenewal.php';
$botState['renewAutoState']=$spec['enabled']??'off';
$admin=9001;
$action=$spec['action']??'call';
$oid=(int)($spec['order_id']??0);
$uid=(int)($spec['user_id']??1001);
if ($action==='real_warning_cleanup') {
    $botState['rewardChannel']='';
    $buttonValues=['back_to_main'=>'mainMenu','my_subscriptions'=>'subs',
                   'sharj'=>'wallet'];
    require $root.'/src/Notifications/Warnings.php';
    $events[]=['type'=>'warnings_finished'];
} elseif (in_array($action,
    ['real_user_delete','real_admin_delete','real_order_delete',
     'real_order_delete_delegate','real_order_delete_nonadmin',
     'real_order_delete_missing_user','real_user_preview_nonadmin'],true)) {
    // These actual bot routes may call exit(); the shutdown hook keeps
    // structured synthetic test evidence without modifying production code.
    register_shutdown_function(function() use (&$events) {
        echo json_encode($events,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    });
    $from_id=match ($action) {
        'real_user_preview_nonadmin','real_order_delete_delegate'=>9002,
        'real_user_delete','real_order_delete_nonadmin'=>1001,
        'real_order_delete_missing_user'=>9003,
        default=>9001,
    };
    // Mirror config.php's actual prepared-statement SELECT of the actor.
    // A tinyint(1) is returned as a numeric/string value, NOT PHP true.
    $actor=$connection->prepare("SELECT * FROM users WHERE userid=?");
    $actor->bind_param('i',$from_id);
    $actor->execute();
    $userInfo=$actor->get_result()->fetch_assoc() ?: ['step'=>'none'];
    $actor->close();
    $events[]=['type'=>'db_actor_role','actor'=>$from_id,
               'flag'=>$userInfo['isAdmin']??null];
    $data=($action==='real_user_delete'?'yesDeleteConfig':
          ($action==='real_user_preview_nonadmin'?'deleteMyConfig':
          ($action==='real_admin_delete'?'yesDeleteUserConfig':(!empty($spec['confirm'])?'confirmDeleteOrder_':'deleteOrder_')))).$oid;
    $text='';
    $message_id=53;
    $first_name='Owned synthetic';
    $username='test';
    $buttonValues=['back_to_main'=>'mainMenu','cancel'=>'cancel'];
    $botState['rewardChannel']='';
    if (in_array($action,['real_order_delete',
                          'real_order_delete_delegate',
                          'real_order_delete_nonadmin',
                          'real_order_delete_missing_user'],true))
        require $root.'/src/Subscription/OrderManagement.php';
    else
        require $root.'/src/Subscription/AccountDeletion.php';
    exit;
}
if($action==='call') {
    handleAutoRenewCallback('renewAuto_with_wallet|'.$uid.'|'.$oid);
} elseif($action==='reserve' || $action==='remote_mark') {
    $a=achbotAutoRenewReserve($connection,$oid,$uid);
    $events[]=['type'=>'reserve','status'=>$a['status']];
    if($action==='remote_mark' && $a['status']==='reserved') {
        $events[]=['type'=>'mark','ok'=>achbotAutoRenewMarkRemoteStarted($connection,(int)$a['id'])];
    }
} elseif($action==='alert') {
    achbotAutoRenewAlertReview($connection,$admin);
} elseif($action==='real_warning_cleanup') {
    // Already exercised the actual warning module above.
} elseif($action==='sweep') {
    achbotAutoRenewSweep($connection,$botState);
} elseif($action==='schema') {
    $events[]=['type'=>'schema','state'=>achbotAutoRenewSchemaState($connection)];
} elseif($action==='deletion_guard') {
    $events[]=['type'=>'delete_guard','allowed'=>
        achbotAutoRenewPrepareDeletion($connection,$oid)];
} elseif($action==='finalize_deletion_only') {
    $stmt=$connection->prepare("SELECT server_id FROM orders_list WHERE id=?");
    $stmt->bind_param('i',$oid);
    $stmt->execute();
    $found=$stmt->get_result()->fetch_assoc();
    $stmt->close();
    $events[]=['type'=>'local_delete','success'=>$found ?
        achbotAutoRenewFinalizeDeletion($connection,$oid,(int)$found['server_id'],
            $spec['capacity']??false) : false];
} elseif($action==='deletion_done') {
    $guard=achbotAutoRenewPrepareDeletion($connection,$oid);
    $events[]=['type'=>'delete_guard','allowed'=>$guard];
    if ($guard) {
        $stmt=$connection->prepare('SELECT server_id FROM orders_list WHERE id=?');
        $stmt->bind_param('i',$oid);
        $stmt->execute();
        $server=(int)$stmt->get_result()->fetch_assoc()['server_id'];
        $stmt->close();
        $events[]=['type'=>'local_delete','success'=>
            achbotAutoRenewFinalizeDeletion($connection,$oid,$server,
                $spec['capacity']??false)];
    }
} else throw new RuntimeException('invalid test operation');
echo json_encode($events,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
'''
def owned_trigger(w,e,ddl):
    # mariadb -e splits semicolons in compound trigger bodies. Only synthetic
    # DDL on the disposable socket database may use this PHP mysqli client.
    php='$db=new mysqli("localhost","root","",$argv[1],0,$argv[2]);$db->query($argv[3]);'
    result=subprocess.run(
        ['php','-n','-d','extension=mysqlnd','-d','extension=mysqli','-r',
         php,DB,str(w/'mysql.sock'),ddl],
        cwd=w,env=e,capture_output=True,timeout=10)
    if result.returncode or result.stderr:
        raise RuntimeError('Isolated renewal trigger setup failed: '+
                           repr((result.returncode,result.stderr[-300:])))

def sql(w,e,q):
    return native.sql(w,e,q,DB)
def cmd(w,e,s):
    file=w/'owned-renew-php.php'
    if not file.exists():file.write_text(PHP)
    return ['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
            '-d','extension=curl','-d','allow_url_fopen=0',
            '-d','disable_functions=exec,shell_exec,system,passthru,popen,proc_open,mail',
            str(file),str(ROOT),str(w/'mysql.sock'),DB,json.dumps(s)]
def run(w,e,s):
    p=subprocess.run(cmd(w,e,s),cwd=w,env=e,capture_output=True,timeout=25)
    expected={b'achbot_auto_renew_migration_required',
              b'achbot_auto_renew_reservation_deferred',
              b'achbot_auto_renew_finalization_deferred',
              b'achbot_auto_renew_sweep_deferred',
              b'achbot_auto_renew_review_alert_deferred',
              b'achbot_auto_renew_migration_or_schema_invalid',
              b'achbot_auto_renew_schema_incompatible_operator_review',
              b'achbot_auto_renew_schema_preflight_unavailable',
              b'achbot_auto_renew_deletion_guard_deferred',
              b'achbot_auto_renew_local_deletion_deferred',
              b'achbot_account_delete_remote_deferred',
              b'achbot_admin_delete_remote_deferred',
              b'achbot_warning_remote_delete_deferred',
              b'achbot_warning_local_delete_deferred',
              b'achbot_operator_local_order_delete_deferred'}
    # Correlation-safe order IDs are intentionally included in operator logs.
    diagnostic=re.compile(rb'^achbot_(?:account_delete_remote|admin_delete_remote|'
                          rb'account_delete_local|warning_remote_delete|'
                          rb'warning_local_delete|operator_local_order_delete)'
                          rb'_deferred order_id=[1-9][0-9]*$')
    unexpected=[line for line in p.stderr.splitlines()
                if line not in expected and not diagnostic.fullmatch(line)]
    if p.returncode or unexpected:
        raise RuntimeError(repr((p.returncode,unexpected[-8:],p.stdout[-400:])))
    return json.loads(p.stdout)
def seed(w,e,name,amount=100000,wallet=500000):
    sql(w,e,f"UPDATE users SET wallet={wallet} WHERE userid=1001")
    sql(w,e,f"UPDATE server_plans SET price={amount},days=30,volume=2,active=1 WHERE id=7")
    exp=int(time.time())+1000
    sql(w,e,"INSERT INTO orders_list (userid,token,transid,fileid,server_id,inbound_id,"
          "remark,uuid,protocol,expire_date,link,amount,status,date,notif,autoRenew) VALUES "
          f"(1001,'t-{name}','',7,2,0,'{name}','uuid','vless',{exp},'[]',{amount},1,'synthetic',1,'on')")
    return int(sql(w,e,f"SELECT MAX(id) FROM orders_list WHERE remark='{name}'"))
def due_grace(w,e,oid):
    # These scheduled-deletion scenarios need a real chronology, not the
    # synthetic date/notif=1 markers used only for reservation tests.
    deadline=int(time.time())-1
    sql(w,e,f"UPDATE orders_list SET date='1700000000',notif={deadline} WHERE id={oid}")

def count(w,e,table,where='1=1'):
    return int(sql(w,e,f"SELECT COUNT(*) FROM {table} WHERE {where}"))
def wallet(w,e):
    return int(sql(w,e,"SELECT wallet FROM users WHERE userid=1001"))
def state(w,e,oid):
    return sql(w,e,f"SELECT stage FROM automatic_renewal_attempts WHERE order_id={oid}").strip()
def assert_no_remote(events):
    assert not any(x['type']=='remote' for x in events),events

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('--run-disposable',required=True,action='store_true')
    ap.add_argument('--report',type=Path)
    a=ap.parse_args()
    cases=[]
    with owned.owned_database() as (w,e):
        purchase.init(w,e,'http://127.0.0.1:9')
        assert len(sql(w,e,'SHOW TABLES').splitlines())==23
        oid=seed(w,e,'unmigrated')
        n=count(w,e,'pays')
        assert_no_remote(run(w,e,{'order_id':oid,'enabled':'off'}))
        assert_no_remote(run(w,e,{'order_id':oid,'enabled':'on'}))
        assert count(w,e,'pays')==n and wallet(w,e)==500000
        cases.append('missing_schema_fails_closed')
        sql(w,e,(ROOT/'migrations/post-v9/004_automatic_renewal_attempts.sql').read_text())
        assert len(sql(w,e,'SHOW TABLES').splitlines())==24
        assert_no_remote(run(w,e,{'order_id':oid,'enabled':'off'}))
        assert wallet(w,e)==500000
        cases.append('disabled_flag_no_effect')
        assert run(w,e,{'action':'schema','order_id':oid})==[
            {'type':'schema','state':'ready'}]

        def schema_invalid(tag):
            assert run(w,e,{'action':'schema','order_id':oid})==[
                {'type':'schema','state':'incompatible'}],tag
            old_pay=count(w,e,'pays')
            old_wallet=wallet(w,e)
            old_attempts=count(w,e,'automatic_renewal_attempts')
            assert_no_remote(run(w,e,{'order_id':oid,'enabled':'on'}))
            assert wallet(w,e)==old_wallet
            assert count(w,e,'pays')==old_pay
            assert count(w,e,'automatic_renewal_attempts')==old_attempts
            assert sql(w,e,f"SELECT status FROM orders_list WHERE id={oid}").strip()=='1'
            assert run(w,e,{'action':'deletion_guard','order_id':oid})==[
                {'type':'delete_guard','allowed':False}]
            cases.append(tag)

        sql(w,e,"ALTER TABLE automatic_renewal_attempts DROP COLUMN review_claimed_at")
        schema_invalid('incompatible_004_missing_alert_field_blocks_debit_and_delete')
        sql(w,e,"ALTER TABLE automatic_renewal_attempts ADD COLUMN review_claimed_at DATETIME DEFAULT NULL AFTER review_claim_token")

        sql(w,e,"ALTER TABLE automatic_renewal_attempts DROP INDEX uq_renewal_cycle")
        schema_invalid('incompatible_004_missing_unique_cycle_blocks_debit_and_delete')
        sql(w,e,"ALTER TABLE automatic_renewal_attempts ADD UNIQUE KEY uq_renewal_cycle (order_id,cycle_expire)")

        sql(w,e,"ALTER TABLE automatic_renewal_attempts DROP FOREIGN KEY fk_renewal_pay")
        schema_invalid('incompatible_004_missing_payment_fk_blocks_debit_and_delete')
        sql(w,e,"ALTER TABLE automatic_renewal_attempts ENGINE=MyISAM")
        schema_invalid('incompatible_004_nontransactional_engine_blocks_debit_and_delete')
        sql(w,e,"ALTER TABLE automatic_renewal_attempts ENGINE=InnoDB")
        sql(w,e,"ALTER TABLE automatic_renewal_attempts ADD CONSTRAINT fk_renewal_pay FOREIGN KEY (pay_row_id) REFERENCES pays(id) ON UPDATE RESTRICT ON DELETE RESTRICT")

        sql(w,e,"ALTER TABLE automatic_renewal_attempts MODIFY stage ENUM('reserved','done') NOT NULL DEFAULT 'reserved'")
        schema_invalid('incompatible_004_wrong_stage_definition_blocks_debit_and_delete')
        sql(w,e,"ALTER TABLE automatic_renewal_attempts MODIFY stage VARCHAR(32) COLLATE utf8mb4_bin NOT NULL DEFAULT 'reserved'")

        sql(w,e,"ALTER TABLE automatic_renewal_attempts ADD CONSTRAINT fk_renewal_order FOREIGN KEY (order_id) REFERENCES orders_list(id) ON DELETE RESTRICT")
        schema_invalid('incompatible_004_old_restrictive_order_fk_is_rejected')
        sql(w,e,"ALTER TABLE automatic_renewal_attempts DROP FOREIGN KEY fk_renewal_order")
        assert run(w,e,{'action':'schema','order_id':oid})==[
            {'type':'schema','state':'ready'}]
        cases.append('restored_full_004_contract_ready')
        ev=run(w,e,{'order_id':oid,'enabled':'on'})
        assert sum(x['type']=='remote' for x in ev)==1,ev
        assert wallet(w,e)==400000 and state(w,e,oid)=='done'
        assert sql(w,e,f"SELECT state FROM pays WHERE order_id={oid} AND description='automatic_renewal'").strip()=='paid_with_wallet'
        assert sql(w,e,f"SELECT notif FROM orders_list WHERE id={oid}").strip()=='0'
        assert count(w,e,'increase_order',"remark='unmigrated'")==1
        assert count(w,e,'automatic_renewal_attempts',f'order_id={oid}')==1
        cases.append('verified_remote_success_all_local_effects_atomic')
        remote_absolute=1800201111
        exact=seed(w,e,'remote-absolute',wallet=500000)
        ev=run(w,e,{'order_id':exact,'enabled':'on',
                    'applied_expire':remote_absolute})
        assert sum(x['type']=='remote' for x in ev)==1,ev
        assert state(w,e,exact)=='done'
        assert int(sql(w,e,f'SELECT expire_date FROM orders_list WHERE id={exact}'))==remote_absolute
        assert int(sql(w,e,'SELECT new_expire FROM automatic_renewal_attempts '
                             f'WHERE order_id={exact}'))!=remote_absolute
        assert_no_remote(run(w,e,{'order_id':exact,'enabled':'on'}))
        cases.append('remote_absolute_expiry_persisted_not_preclaimed_estimate')
        over=seed(w,e,'remote-expiry-overflow',wallet=500000)
        old_expiry=sql(w,e,f'SELECT expire_date FROM orders_list WHERE id={over}')
        ev=run(w,e,{'order_id':over,'enabled':'on',
                    'applied_expire':2147483648})
        assert sum(x['type']=='remote' for x in ev)==1
        assert state(w,e,over)=='review'
        assert sql(w,e,f'SELECT expire_date FROM orders_list WHERE id={over}')==old_expiry
        assert sql(w,e,f'SELECT state FROM pays WHERE order_id={over}').strip()=='processing_wallet'
        assert count(w,e,'increase_order',"remark='remote-expiry-overflow'")==0
        assert_no_remote(run(w,e,{'order_id':over,'enabled':'on'}))
        cases.append('remote_expiry_outside_signed_int_review_no_double_debit')
        assert_no_remote(run(w,e,{'order_id':oid,'enabled':'on'}))
        assert_no_remote(run(w,e,{'order_id':oid,'enabled':'on','user_id':9002}))
        assert wallet(w,e)==400000
        cases.append('replayed_and_foreign_requests_no_extra_charge')

        # F4: the actual PHP callback must not finalize a successful remote
        # renewal when a nonthrowing mysqli INSERT into increase_order fails.
        # The reserved debit/journal must remain reviewable, with no replay.
        accounting=seed(w,e,'accounting-fail',wallet=500000)
        accounting_expiry=sql(w,e,f"SELECT expire_date FROM orders_list WHERE id={accounting}")
        sql(w,e,"CREATE TRIGGER achbot_owned_accounting_fail BEFORE INSERT "
                "ON increase_order FOR EACH ROW SIGNAL SQLSTATE '45000' "
                "SET MESSAGE_TEXT='synthetic accounting insert failure'")
        try:
            failed_accounting=run(w,e,{'order_id':accounting,'enabled':'on',
                                      'nonthrowing_sql':True})
        finally:
            sql(w,e,"DROP TRIGGER achbot_owned_accounting_fail")
        assert sum(x['type']=='remote' for x in failed_accounting)==1,failed_accounting
        assert not any(x['type']=='send' for x in failed_accounting),failed_accounting
        assert wallet(w,e)==400000, wallet(w,e)
        assert state(w,e,accounting)=='review',state(w,e,accounting)
        assert sql(w,e,f"SELECT state FROM pays WHERE order_id={accounting}").strip()=='processing_wallet'
        assert sql(w,e,f"SELECT expire_date FROM orders_list WHERE id={accounting}")==accounting_expiry
        assert count(w,e,'increase_order',"remark='accounting-fail'")==0
        assert count(w,e,'automatic_renewal_attempts',f'order_id={accounting}')==1
        assert_no_remote(run(w,e,{'order_id':accounting,'enabled':'on'}))
        assert wallet(w,e)==400000
        cases.append('f4_nonthrowing_accounting_insert_failure_rolls_back_local_finalization_without_replay')

        low=seed(w,e,'low',wallet=10)
        assert_no_remote(run(w,e,{'order_id':low,'enabled':'on'}))
        assert wallet(w,e)==10 and sql(w,e,f"SELECT autoRenew FROM orders_list WHERE id={low}").strip()=='off'
        assert count(w,e,'automatic_renewal_attempts',f'order_id={low}')==0
        cases.append('insufficient_funds_disables_auto_only')
        debt=seed(w,e,'debt',wallet=-1000)
        assert_no_remote(run(w,e,{'order_id':debt,'enabled':'on'}))
        assert wallet(w,e)==-1000 and state(w,e,debt)==''
        cases.append('signed_admin_debt_preserved_no_user_debit')
        invalid=seed(w,e,'invalid',amount=0)
        n=count(w,e,'pays')
        assert_no_remote(run(w,e,{'order_id':invalid,'enabled':'on'}))
        assert count(w,e,'pays')==n and wallet(w,e)==500000
        cases.append('nonpositive_plan_fails_closed')
        failed=seed(w,e,'timeout')
        ev=run(w,e,{'order_id':failed,'enabled':'on','throw':True})
        assert sum(x['type']=='remote' for x in ev)==1 and state(w,e,failed)=='review'
        assert wallet(w,e)==400000
        assert sql(w,e,f"SELECT state FROM pays WHERE order_id={failed}").strip()=='processing_wallet'
        assert count(w,e,'increase_order',"remark='timeout'")==0
        assert_no_remote(run(w,e,{'order_id':failed,'enabled':'on'}))
        assert wallet(w,e)==400000
        cases.append('ambiguous_remote_result_never_replayed_or_refunded')
        changed=seed(w,e,'drift')
        run(w,e,{'order_id':changed,'enabled':'on','drift':True})
        assert state(w,e,changed)=='review'
        assert count(w,e,'increase_order',"remark='drift'")==0
        cases.append('remote_success_database_drift_held_for_review')
        crash=seed(w,e,'crash')
        ev=run(w,e,{'order_id':crash,'enabled':'off','action':'remote_mark'})
        assert any(x['type']=='mark' and x['ok'] for x in ev),ev
        assert state(w,e,crash)=='remote_started'
        assert_no_remote(run(w,e,{'order_id':crash,'enabled':'on'}))
        cases.append('crashed_worker_remote_marker_blocks_retry')
        sql(w,e,f"UPDATE automatic_renewal_attempts SET created_at=NOW()-INTERVAL 10 MINUTE WHERE order_id={crash}")
        alerts=run(w,e,{'action':'alert','order_id':crash,'telegram_ok':False})
        assert any(x['type']=='send' for x in alerts)
        assert sql(w,e,f"SELECT review_notified_at IS NULL FROM automatic_renewal_attempts WHERE order_id={crash}").strip()=='1'
        success=run(w,e,{'action':'alert','order_id':crash})
        assert any(x['type']=='send' for x in success)
        again=run(w,e,{'action':'alert','order_id':crash})
        assert not any(x['type']=='send' for x in again)
        cases.append('private_operator_notice_retry_and_once_per_day_ack')
        racing=seed(w,e,'race')
        spec={'order_id':racing,'enabled':'on','delay_ms':250}
        ps=[subprocess.Popen(cmd(w,e,spec),cwd=w,env=e,stdout=subprocess.PIPE,stderr=subprocess.PIPE) for _ in range(2)]
        results=[]
        for p in ps:
            out,err=p.communicate(timeout=24)
            expected_stderr={b'achbot_auto_renew_reservation_deferred'}
            assert p.returncode==0 and all(
                e in expected_stderr for e in err.splitlines()),(p.returncode,err,out)
            results.append(json.loads(out))
        remote_count=sum(x['type']=='remote' for es in results for x in es)
        if remote_count!=1:
            # Distinguish a transient competing transaction from financial
            # guard rejection or a lost claim, without inventing a retry.
            raise AssertionError({
                'worker_events':results,'journal_stage':state(w,e,racing),
                'order_state':sql(w,e,f"SELECT status,autoRenew,notif,expire_date FROM orders_list WHERE id={racing}"),
                'payment_state':sql(w,e,f"SELECT state FROM pays WHERE order_id={racing}"),
                'wallet':wallet(w,e),
                'account_rows':sql(w,e,f"SELECT id,remark,status,server_id FROM orders_list WHERE remark='race'"),
            })
        assert wallet(w,e)==400000 and state(w,e,racing)=='done'
        assert count(w,e,'increase_order',"remark='race'")==1
        cases.append('two_real_php_mysql_clients_exactly_one_debit_and_remote')
        # Reproduce the formerly intermittent fail-closed reserved hold:
        # a REAL MariaDB BEFORE UPDATE trigger returns a single deadlock or
        # lock-wait error during the LOCAL pre-dispatch journal transition.
        # MyISAM counter is a disposable test witness for statement attempts;
        # no provider may run before durable remote_started is committed.
        for mode,sqlstate,errno,nonthrow in (
            ('deadlock_throwing','40001',1213,False),
            ('timeout_throwing','HY000',1205,False),
            ('deadlock_nonthrowing','40001',1213,True),
            ('timeout_nonthrowing','HY000',1205,True),
        ):
            transient=seed(w,e,'mark-'+mode,wallet=500000)
            sql(w,e,"CREATE TABLE achbot_owned_mark_counter "
                    "(n INT NOT NULL) ENGINE=MyISAM")
            sql(w,e,"INSERT INTO achbot_owned_mark_counter VALUES (0)")
            trigger=(
                "CREATE TRIGGER achbot_owned_mark_transient "
                "BEFORE UPDATE ON automatic_renewal_attempts FOR EACH ROW "
                "BEGIN IF OLD.order_id="+str(transient)+" "
                "AND OLD.stage='reserved' AND NEW.stage='remote_started' THEN "
                "UPDATE achbot_owned_mark_counter SET n=n+1; "
                "IF (SELECT n FROM achbot_owned_mark_counter LIMIT 1)=1 THEN "
                "SIGNAL SQLSTATE '"+sqlstate+"' SET MYSQL_ERRNO="+str(errno)+", "
                "MESSAGE_TEXT='synthetic_pre_dispatch_marker_lock'; "
                "END IF; END IF; END"
            )
            try:
                owned_trigger(w,e,trigger)
                race_spec={'order_id':transient,'enabled':'on',
                           'delay_ms':120,'nonthrowing_sql':nonthrow}
                children=[
                    subprocess.Popen(cmd(w,e,race_spec),cwd=w,env=e,
                                     stdout=subprocess.PIPE,stderr=subprocess.PIPE)
                    for _ in range(2)]
                events=[]
                for child in children:
                    out,err=child.communicate(timeout=25)
                    allowed={b'achbot_auto_renew_reservation_deferred'}
                    assert child.returncode==0 and all(
                        line in allowed for line in err.splitlines()),(
                            mode,child.returncode,err,out)
                    events.append(json.loads(out))
                count_remote=sum(evt['type']=='remote'
                                 for group in events for evt in group)
                assert count_remote==1, {
                    'mode':mode,'worker_events':events,
                    'journal_stage':state(w,e,transient),
                    'payment_state':sql(w,e,
                        f"SELECT state FROM pays WHERE order_id={transient}"),
                    'marker_attempts':sql(w,e,
                        'SELECT n FROM achbot_owned_mark_counter')}
                assert sql(w,e,'SELECT n FROM achbot_owned_mark_counter').strip()=='2'
                assert state(w,e,transient)=='done'
                assert wallet(w,e)==400000
                assert count(w,e,'automatic_renewal_attempts',
                             f'order_id={transient}')==1
                assert count(w,e,'increase_order',
                             "remark='mark-"+mode+"'")==1
            finally:
                sql(w,e,'DROP TRIGGER IF EXISTS achbot_owned_mark_transient')
                sql(w,e,'DROP TABLE IF EXISTS achbot_owned_mark_counter')
            assert_no_remote(run(w,e,{'order_id':transient,'enabled':'on'}))
            cases.append('real_two_worker_once_'+mode+
                         '_marker_retry_no_double_debit')

        # A permanently failing marker or unrelated SQL error MUST NOT
        # turn the bounded local retry into remote replay or silent refund.
        for mode,sqlstate,errno,expected_attempts in (
            ('persistent_deadlock','40001',1213,2),
            ('nonretryable_marker_error','45000',1644,1),
        ):
            held=seed(w,e,'hold-'+mode,wallet=500000)
            sql(w,e,"CREATE TABLE achbot_owned_mark_counter "
                    "(n INT NOT NULL) ENGINE=MyISAM")
            sql(w,e,"INSERT INTO achbot_owned_mark_counter VALUES (0)")
            trigger=(
                "CREATE TRIGGER achbot_owned_mark_persistent "
                "BEFORE UPDATE ON automatic_renewal_attempts FOR EACH ROW "
                "BEGIN IF OLD.order_id="+str(held)+" "
                "AND OLD.stage='reserved' AND NEW.stage='remote_started' THEN "
                "UPDATE achbot_owned_mark_counter SET n=n+1; "
                "SIGNAL SQLSTATE '"+sqlstate+"' SET MYSQL_ERRNO="+str(errno)+", "
                "MESSAGE_TEXT='synthetic_pre_dispatch_marker_failure'; "
                "END IF; END"
            )
            try:
                owned_trigger(w,e,trigger)
                observed=run(w,e,{'action':'remote_mark',
                                   'order_id':held,'enabled':'on'})
                assert observed==[{'type':'reserve','status':'reserved'},
                                  {'type':'mark','ok':False}],(mode,observed)
                assert int(sql(w,e,'SELECT n FROM achbot_owned_mark_counter')
                           )==expected_attempts,(mode,expected_attempts)
                assert state(w,e,held)=='reserved'
                assert wallet(w,e)==400000
                assert sql(w,e,f"SELECT state FROM pays WHERE order_id={held}"
                           ).strip()=='processing_wallet'
                assert count(w,e,'automatic_renewal_attempts',
                             f'order_id={held}')==1
                assert count(w,e,'increase_order',
                             "remark='hold-"+mode+"'")==0
            finally:
                sql(w,e,'DROP TRIGGER IF EXISTS achbot_owned_mark_persistent')
                sql(w,e,'DROP TABLE IF EXISTS achbot_owned_mark_counter')
            assert_no_remote(run(w,e,{'order_id':held,'enabled':'on'}))
            assert wallet(w,e)==400000
            sql(w,e,'UPDATE automatic_renewal_attempts '
                    'SET created_at=NOW()-INTERVAL 10 MINUTE '
                    f'WHERE order_id={held}')
            alert=run(w,e,{'action':'alert','order_id':held})
            assert any(evt['type']=='send' and evt['to']==9001
                       for evt in alert),alert
            assert sql(w,e,'SELECT review_notified_at IS NOT NULL '
                           'FROM automatic_renewal_attempts '
                           f'WHERE order_id={held}').strip()=='1'
            assert_no_remote(run(w,e,{'order_id':held,'enabled':'on'}))
            cases.append(mode+'_keeps_one_hold_operator_alert_no_replay')

        # A duplicate legacy userid cannot be the source of a spend.
        duplicate=seed(w,e,'duplicateuser')
        sql(w,e,"INSERT INTO users (userid,name,username,refcode,date,step,wallet) "
              "VALUES (1001,'synthetic','duplicate','synthetic','today','none',500000)")
        n=count(w,e,'pays')
        assert_no_remote(run(w,e,{'order_id':duplicate,'enabled':'on'}))
        assert count(w,e,'pays')==n and state(w,e,duplicate)==''
        sql(w,e,"DELETE FROM users WHERE userid=1001 AND username='duplicate'")
        cases.append('nonunique_legacy_identity_cannot_fund_renewal')
        # Real sweep path must not starve or reach out when off. Make old test
        # orders ineligible so one eligible order proves the exact Cron loop.
        sql(w,e,"UPDATE orders_list SET autoRenew='off' WHERE id<>"+str(duplicate))
        sql(w,e,"UPDATE orders_list SET autoRenew='off' WHERE id="+str(duplicate))
        sweep=seed(w,e,'sweep')
        sql(w,e,"UPDATE setting SET value='0' WHERE type='AutoRenewCheck'")
        ev=run(w,e,{'action':'sweep','order_id':sweep,'enabled':'on'})
        assert sum(x['type']=='remote' for x in ev)==1,ev
        assert state(w,e,sweep)=='done' and wallet(w,e)==400000
        assert count(w,e,'increase_order',"remark='sweep'")==1
        cases.append('real_cron_sweep_one_eligible_marzban_order')
        # A Telegram failure after commit is not allowed to revert success.
        notice=seed(w,e,'notification')
        ev=run(w,e,{'order_id':notice,'enabled':'on','telegram_ok':False})
        assert any(x['type']=='send' for x in ev) and state(w,e,notice)=='done'
        assert wallet(w,e)==400000 and count(w,e,'increase_order',"remark='notification'")==1
        cases.append('telegram_failure_after_commit_does_not_revert_payment')

        # F2: two independent order IDs for the same remote user. Even with
        # enough wallet money, neither can claim an exclusive remote account.
        first=seed(w,e,'duplicate-account',wallet=500000)
        second=seed(w,e,'duplicate-account',wallet=500000)
        assert first!=second
        specA={'order_id':first,'enabled':'on','delay_ms':250}
        specB={'order_id':second,'enabled':'on','delay_ms':250}
        children=[subprocess.Popen(cmd(w,e,spec),cwd=w,env=e,
                    stdout=subprocess.PIPE,stderr=subprocess.PIPE)
                  for spec in (specA,specB)]
        outputs=[]
        for proc in children:
            out,err=proc.communicate(timeout=24)
            allowed={b'achbot_auto_renew_reservation_deferred'}
            assert proc.returncode==0 and all(x in allowed for x in err.splitlines()),(err,out)
            outputs.append(json.loads(out))
        assert all(not any(v['type']=='remote' for v in vals) for vals in outputs),outputs
        assert wallet(w,e)==500000
        assert count(w,e,'automatic_renewal_attempts',f'order_id IN ({first},{second})')==0
        assert count(w,e,'pays',f"order_id IN ({first},{second}) AND description='automatic_renewal'")==0
        cases.append('f2_distinct_active_orders_same_account_two_workers_no_debit')
        assert run(w,e,{'action':'deletion_guard','order_id':first})==[
            {'type':'delete_guard','allowed':False}]
        sql(w,e,f"UPDATE orders_list SET status=0 WHERE id IN ({first},{second})")

        # Explicit administrator-assigned panel groups, not URL equality,
        # define aliasing of the physical remote account.
        sql(w,e,(ROOT/'migrations/post-v9/001_marzban_panel_group_id.sql').read_text())
        sql(w,e,"INSERT INTO setting (type,value) VALUES "
                "('ACHBOT_MARZBAN_PANEL_GROUP','synthetic renewal group')")
        group=int(sql(w,e,"SELECT id FROM setting WHERE "
                        "type='ACHBOT_MARZBAN_PANEL_GROUP' "
                        "AND value='synthetic renewal group'"))
        sql(w,e,"INSERT INTO server_info (id,title,ucount,remark,flag,active,state) "
                "SELECT 88,'alias',ucount,remark,flag,active,state "
                "FROM server_info WHERE id=2")
        sql(w,e,"INSERT INTO server_config (id,panel_url,ip,sni,header_type,"
                "request_header,response_header,security,tlsSettings,type,"
                "username,password,port_type,reality,sub_links) "
                "SELECT 88,CONCAT(panel_url,'/different-url'),ip,sni,header_type,"
                "request_header,response_header,security,tlsSettings,type,"
                "'different-admin',password,port_type,reality,sub_links "
                "FROM server_config WHERE id=2")
        sql(w,e,f"UPDATE server_info SET panel_group_id={group} WHERE id IN (2,88)")
        aliasA=seed(w,e,'aliased-remote',wallet=500000)
        aliasB=seed(w,e,'aliased-remote',wallet=500000)
        sql(w,e,f"UPDATE orders_list SET server_id=88 WHERE id={aliasB}")
        assert_no_remote(run(w,e,{'order_id':aliasA,'enabled':'on'}))
        assert_no_remote(run(w,e,{'order_id':aliasB,'enabled':'on'}))
        assert wallet(w,e)==500000
        assert count(w,e,'automatic_renewal_attempts',f'order_id IN ({aliasA},{aliasB})')==0
        cases.append('f2_explicit_same_panel_server_alias_duplicate_refused')
        # Same-panel alias *concurrent* workers use different server IDs and
        # different order IDs but must also be completely non-chargeable.
        concurrent=[]
        for target in (aliasA,aliasB):
            concurrent.append(subprocess.Popen(
                cmd(w,e,{'order_id':target,'enabled':'on','delay_ms':150}),
                cwd=w,env=e,stdout=subprocess.PIPE,stderr=subprocess.PIPE))
        for child in concurrent:
            out,err=child.communicate(timeout=24)
            allowed={b'achbot_auto_renew_reservation_deferred'}
            assert child.returncode==0 and all(x in allowed for x in err.splitlines()),(err,out)
            assert not any(x['type']=='remote' for x in json.loads(out)),out
        assert wallet(w,e)==500000
        assert count(w,e,'automatic_renewal_attempts',
                     f'order_id IN ({aliasA},{aliasB})')==0
        cases.append('f2_two_alias_workers_no_double_debit_even_under_race')
        sql(w,e,f"UPDATE orders_list SET status=0 WHERE id IN ({aliasA},{aliasB})")
        validA=seed(w,e,'distinct-real-one',wallet=500000)
        validB=seed(w,e,'distinct-real-two',wallet=500000)
        assert sum(x['type']=='remote' for x in
                   run(w,e,{'order_id':validA,'enabled':'on'}))==1
        assert sum(x['type']=='remote' for x in
                   run(w,e,{'order_id':validB,'enabled':'on'}))==1
        assert wallet(w,e)==300000
        assert state(w,e,validA)==state(w,e,validB)=='done'
        cases.append('f2_distinct_remote_accounts_remain_renewable')

        # F1: a completed renewal may be deleted without losing the local
        # pays row or its immutable audit journal, even though order_id no
        # longer references a live order.
        originalJournal=count(w,e,'automatic_renewal_attempts',f'order_id={oid}')
        ucount=int(sql(w,e,'SELECT ucount FROM server_info WHERE id=2'))
        delete=run(w,e,{'action':'deletion_done','order_id':oid,'capacity':True})
        assert delete==[
            {'type':'delete_guard','allowed':True},
            {'type':'local_delete','success':True}],delete
        assert count(w,e,'orders_list',f'id={oid}')==0
        assert count(w,e,'automatic_renewal_attempts',f'order_id={oid}')==originalJournal
        assert count(w,e,'pays',f"order_id={oid} AND state='paid_with_wallet'")==1
        assert int(sql(w,e,'SELECT ucount FROM server_info WHERE id=2'))==ucount+1
        cases.append('f1_completed_renewal_order_deletable_history_permanently_retained')

        # F1: unresolved remote effects may never be deleted.
        for pending in (failed,crash):
            before=count(w,e,'orders_list',f'id={pending} AND status=1')
            ev=run(w,e,{'action':'deletion_done','order_id':pending})
            assert ev==[{'type':'delete_guard','allowed':False}]
            assert count(w,e,'orders_list',f'id={pending} AND status=1')==before
            assert count(w,e,'automatic_renewal_attempts',f'order_id={pending}')==1
        cases.append('f1_unresolved_renewal_remote_deletion_preflight_denied')

        # F1: external deletion may already have succeeded when a DB trigger
        # denies the subsequent local delete. Retain the inactive row,
        # exact financial evidence and capacity for manual reconciliation.
        localFail=seed(w,e,'cleanup-rollback',wallet=500000)
        assert sum(x['type']=='remote' for x in
                   run(w,e,{'order_id':localFail,'enabled':'on'}))==1
        sql(w,e,"CREATE TRIGGER achbot_owned_delete_fail BEFORE DELETE "
                "ON orders_list FOR EACH ROW SIGNAL SQLSTATE '45000' "
                "SET MESSAGE_TEXT='synthetic local delete failure'")
        ucount=int(sql(w,e,'SELECT ucount FROM server_info WHERE id=2'))
        out=run(w,e,{'action':'deletion_done','order_id':localFail,'capacity':True})
        assert out==[
            {'type':'delete_guard','allowed':True},
            {'type':'local_delete','success':False}],out
        assert count(w,e,'orders_list',f'id={localFail} AND status=0')==1
        assert state(w,e,localFail)=='done'
        assert wallet(w,e)==400000
        assert int(sql(w,e,'SELECT ucount FROM server_info WHERE id=2'))==ucount
        assert_no_remote(run(w,e,{'order_id':localFail,'enabled':'on'}))
        assert run(w,e,{'action':'deletion_guard','order_id':localFail})==[
            {'type':'delete_guard','allowed':False}]
        sql(w,e,"DROP TRIGGER achbot_owned_delete_fail")
        recovered=run(w,e,{'action':'finalize_deletion_only',
                          'order_id':localFail,'capacity':True})
        assert recovered==[{'type':'local_delete','success':True}],recovered
        assert count(w,e,'orders_list',f'id={localFail}')==0
        assert state(w,e,localFail)=='done'
        assert count(w,e,'pays',f"order_id={localFail} AND state='paid_with_wallet'")==1
        cases.append('f1_sql_failure_after_remote_retains_inactive_record_then_recovers')

        # F1: remote timeout after the deletion fence cannot issue another
        # automatic renewal or a second Cron-driven remote DELETE.
        aborted=seed(w,e,'cleanup-remote-unknown')
        assert run(w,e,{'action':'deletion_guard','order_id':aborted})==[
            {'type':'delete_guard','allowed':True}]
        assert count(w,e,'orders_list',f'id={aborted} AND status=0')==1
        assert_no_remote(run(w,e,{'order_id':aborted,'enabled':'on'}))
        assert run(w,e,{'action':'deletion_guard','order_id':aborted})==[
            {'type':'delete_guard','allowed':False}]
        cases.append('f1_remote_deletion_unknown_single_durable_local_fence')

        # Exercise ACTUAL existing Telegram route PHP, not just isolated
        # helpers. Confirm same financial guard is evaluated first.
        assert count(w,e,'orders_list',f'id={failed} AND status=1')==1
        pendingRoute=run(w,e,{'order_id':failed,'action':'real_user_delete'})
        assert not any(x['type']=='remote_delete' for x in pendingRoute),pendingRoute
        assert any(x['type']=='alert' for x in pendingRoute)
        assert count(w,e,'orders_list',f'id={failed} AND status=1')==1
        cases.append('f1_real_user_route_pending_renewal_prevents_remote_delete')

        accountRoute=seed(w,e,'customer-delete',wallet=500000)
        run(w,e,{'order_id':accountRoute,'enabled':'on'})
        preJournal=count(w,e,'automatic_renewal_attempts',
                         f'order_id={accountRoute}')
        beforeCapacity=int(sql(w,e,'SELECT ucount FROM server_info WHERE id=2'))
        userRoute=run(w,e,{'order_id':accountRoute,'action':'real_user_delete'})
        assert sum(x['type']=='remote_delete' for x in userRoute)==1,userRoute
        assert any(x['type']=='editText' for x in userRoute)
        assert count(w,e,'orders_list',f'id={accountRoute}')==0
        assert count(w,e,'automatic_renewal_attempts',
                     f'order_id={accountRoute}')==preJournal
        assert int(sql(w,e,'SELECT ucount FROM server_info WHERE id=2'))==beforeCapacity+1
        cases.append('f1_real_customer_delete_after_done_journal_retained')

        operatorRoute=seed(w,e,'admin-delete',wallet=500000)
        run(w,e,{'order_id':operatorRoute,'enabled':'on'})
        admin=run(w,e,{'order_id':operatorRoute,'action':'real_admin_delete'})
        assert sum(x['type']=='remote_delete' for x in admin)==1,admin
        assert count(w,e,'orders_list',f'id={operatorRoute}')==0
        assert count(w,e,'automatic_renewal_attempts',
                     f'order_id={operatorRoute}')==1
        cases.append('f1_real_admin_remote_delete_after_done_journal_retained')

        localOnly=seed(w,e,'operator-only',wallet=500000)
        run(w,e,{'order_id':localOnly,'enabled':'on'})
        local_wallet=wallet(w,e)
        local_evidence=sql(w,e,f'SELECT * FROM automatic_renewal_attempts WHERE order_id={localOnly};SELECT * FROM pays WHERE order_id={localOnly};SELECT * FROM orders_list WHERE id={localOnly}')
        preview=run(w,e,{'order_id':localOnly,'action':'real_order_delete'})
        assert not any(x['type']=='remote_delete' for x in preview),preview
        assert any(x=={'type':'local_delete_confirmation','order_id':localOnly} for x in preview),preview
        assert sql(w,e,f'SELECT * FROM automatic_renewal_attempts WHERE order_id={localOnly};SELECT * FROM pays WHERE order_id={localOnly};SELECT * FROM orders_list WHERE id={localOnly}')==local_evidence
        assert wallet(w,e)==local_wallet
        cases.append('f1_primary_local_delete_preview_is_read_only_and_requires_matching_confirmation')
        op=run(w,e,{'order_id':localOnly,'action':'real_order_delete','confirm':True})
        assert not any(x['type']=='remote_delete' for x in op),op
        assert count(w,e,'orders_list',f'id={localOnly}')==0,op
        assert count(w,e,'automatic_renewal_attempts',
                     f'order_id={localOnly}')==1
        cases.append('f1_real_operator_local_only_delete_retains_paid_history')

        # F5: source-real operator callback with actual MariaDB isAdmin
        # values, rather than a fixture-invented PHP boolean.
        # The historical fixture seeds 9002 as a delegate; explicitly toggle
        # it here to prove the 0 -> 1 role transition comes from MariaDB.
        sql(w,e,"UPDATE users SET isAdmin=0 WHERE userid=9002")
        assert sql(w,e,"SELECT isAdmin FROM users WHERE userid=9002").strip()=='0'
        sql(w,e,"UPDATE users SET isAdmin=1 WHERE userid=9002")
        delegated=seed(w,e,'delegated-local-delete',wallet=500000)
        assert sum(x['type']=='remote' for x in run(w,e,{
            'order_id':delegated,'enabled':'on'}))==1
        delegated_wallet=wallet(w,e)
        delegated_pays=count(w,e,'pays',f'order_id={delegated}')
        delegated_journal=count(w,e,'automatic_renewal_attempts',f'order_id={delegated}')
        delegated_evidence=sql(w,e,f'SELECT * FROM automatic_renewal_attempts WHERE order_id={delegated};SELECT * FROM pays WHERE order_id={delegated};SELECT * FROM orders_list WHERE id={delegated}')
        delegated_preview=run(w,e,{'order_id':delegated,'action':'real_order_delete_delegate'})
        assert any(x=={'type':'local_delete_confirmation','order_id':delegated} for x in delegated_preview),delegated_preview
        assert not any(x['type']=='remote_delete' for x in delegated_preview),delegated_preview
        assert sql(w,e,f'SELECT * FROM automatic_renewal_attempts WHERE order_id={delegated};SELECT * FROM pays WHERE order_id={delegated};SELECT * FROM orders_list WHERE id={delegated}')==delegated_evidence
        assert wallet(w,e)==delegated_wallet
        cases.append('f5_db_backed_delegate_local_delete_preview_is_read_only')
        delegated_delete=run(w,e,{'order_id':delegated,
                                  'action':'real_order_delete_delegate','confirm':True})
        assert any(x['type']=='db_actor_role' and x['actor']==9002 and
                   str(x['flag'])=='1' for x in delegated_delete),delegated_delete
        assert any(x['type']=='alert' and not x['modal'] for x in delegated_delete),delegated_delete
        assert not any(x['type']=='remote_delete' for x in delegated_delete),delegated_delete
        assert count(w,e,'orders_list',f'id={delegated}')==0
        assert count(w,e,'pays',f'order_id={delegated}')==delegated_pays==1
        assert count(w,e,'automatic_renewal_attempts',
                     f'order_id={delegated}')==delegated_journal==1
        assert wallet(w,e)==delegated_wallet
        assert_no_remote(run(w,e,{'order_id':delegated,'enabled':'on'}))
        cases.append('f5_db_backed_delegated_admin_can_delete_local_order_once_preserving_financial_history')

        # If the delegated operator's local DELETE fails, the already-committed
        # inactive fence MUST survive; no second route invocation may retry it.
        held=seed(w,e,'delegated-delete-held',wallet=500000)
        assert sum(x['type']=='remote' for x in run(w,e,{
            'order_id':held,'enabled':'on'}))==1
        held_wallet=wallet(w,e)
        sql(w,e,"CREATE TRIGGER achbot_owned_delegate_delete_fail "
                "BEFORE DELETE ON orders_list FOR EACH ROW "
                "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic delegated local delete failure'")
        try:
            held_result=run(w,e,{'order_id':held,
                                 'action':'real_order_delete_delegate','confirm':True})
        finally:
            sql(w,e,"DROP TRIGGER achbot_owned_delegate_delete_fail")
        assert not any(x['type']=='remote_delete' for x in held_result),held_result
        assert any(x['type']=='alert' and x['modal']
                   for x in held_result),held_result
        assert count(w,e,'orders_list',f"id={held} AND status=0 AND autoRenew='off'")==1
        assert count(w,e,'pays',
                     f"order_id={held} AND state='paid_with_wallet'")==1
        assert state(w,e,held)=='done' and wallet(w,e)==held_wallet
        replay=run(w,e,{'order_id':held,'action':'real_order_delete_delegate','confirm':True})
        assert not any(x['type']=='remote_delete' for x in replay),replay
        assert count(w,e,'orders_list',f'id={held} AND status=0')==1
        cases.append('f5_delegated_delete_failure_persists_inactive_fence_without_remote_replay')

        sql(w,e,"UPDATE users SET isAdmin=0 WHERE userid=9002")
        forbidden=seed(w,e,'operator-foreign-role')
        outcome=run(w,e,{'order_id':forbidden,
                         'action':'real_order_delete_nonadmin'})
        assert any(x['type']=='alert' for x in outcome),outcome
        assert count(w,e,'orders_list',f'id={forbidden} AND status=1')==1
        assert not any(x['type']=='remote_delete' for x in outcome),outcome
        cases.append('f1_forged_nonadmin_operator_delete_callback_refused')
        assert any(x['type']=='db_actor_role' and x['actor']==1001 and
                   str(x['flag'])=='0' for x in outcome),outcome
        missing=seed(w,e,'operator-missing-role')
        missing_result=run(w,e,{'order_id':missing,
                                'action':'real_order_delete_missing_user'})
        assert any(x['type']=='db_actor_role' and x['flag'] is None
                   for x in missing_result),missing_result
        assert any(x['type']=='alert' and x['modal']
                   for x in missing_result),missing_result
        assert count(w,e,'orders_list',f'id={missing} AND status=1')==1
        assert not any(x['type']=='remote_delete'
                       for x in missing_result),missing_result
        cases.append('f5_missing_actor_role_cannot_forge_local_delete_callback')
        for actor_action,target,label in [
            ('real_order_delete_nonadmin',forbidden,'nonadmin'),
            ('real_order_delete_missing_user',missing,'missing_actor')]:
            before=sql(w,e,f'SELECT * FROM orders_list WHERE id={target};SELECT * FROM pays WHERE order_id={target};SELECT * FROM automatic_renewal_attempts WHERE order_id={target}')
            before_wallet=wallet(w,e)
            denied_confirm=run(w,e,{'order_id':target,'action':actor_action,'confirm':True})
            assert any(x['type']=='alert' and x['modal'] for x in denied_confirm),denied_confirm
            assert not any(x['type'] in ('remote_delete','local_delete_confirmation') for x in denied_confirm),denied_confirm
            assert sql(w,e,f'SELECT * FROM orders_list WHERE id={target};SELECT * FROM pays WHERE order_id={target};SELECT * FROM automatic_renewal_attempts WHERE order_id={target}')==before
            assert wallet(w,e)==before_wallet
            cases.append('f5_'+label+'_cannot_forge_local_delete_confirmation')

        # A valid preview is never durable authorization after role revocation.
        sql(w,e,"UPDATE users SET isAdmin=1 WHERE userid=9002")
        revoked=seed(w,e,'delegated-role-revoked',wallet=500000)
        assert sum(x['type']=='remote' for x in run(w,e,{'order_id':revoked,'enabled':'on'}))==1
        preview=run(w,e,{'order_id':revoked,'action':'real_order_delete_delegate'})
        assert any(x=={'type':'local_delete_confirmation','order_id':revoked} for x in preview),preview
        sql(w,e,"UPDATE users SET isAdmin=0 WHERE userid=9002")
        before=sql(w,e,f'SELECT * FROM orders_list WHERE id={revoked};SELECT * FROM pays WHERE order_id={revoked};SELECT * FROM automatic_renewal_attempts WHERE order_id={revoked}')
        before_wallet=wallet(w,e)
        denied=run(w,e,{'order_id':revoked,'action':'real_order_delete_delegate','confirm':True})
        assert any(x['type']=='alert' and x['modal'] for x in denied),denied
        assert not any(x['type']=='remote_delete' for x in denied),denied
        assert sql(w,e,f'SELECT * FROM orders_list WHERE id={revoked};SELECT * FROM pays WHERE order_id={revoked};SELECT * FROM automatic_renewal_attempts WHERE order_id={revoked}')==before
        assert wallet(w,e)==before_wallet
        cases.append('f5_local_delete_confirmation_rechecks_revoked_delegate_role')

        # Confirmation does not override an unresolved renewal/payment fence.
        pending_before=sql(w,e,f'SELECT * FROM orders_list WHERE id={failed};SELECT * FROM pays WHERE order_id={failed};SELECT * FROM automatic_renewal_attempts WHERE order_id={failed}')
        pending_wallet=wallet(w,e)
        pending_preview=run(w,e,{'order_id':failed,'action':'real_order_delete'})
        assert any(x=={'type':'local_delete_confirmation','order_id':failed} for x in pending_preview),pending_preview
        pending_confirm=run(w,e,{'order_id':failed,'action':'real_order_delete','confirm':True})
        assert any(x['type']=='alert' and x['modal'] for x in pending_confirm),pending_confirm
        assert not any(x['type']=='remote_delete' for x in pending_preview+pending_confirm),pending_confirm
        assert sql(w,e,f'SELECT * FROM orders_list WHERE id={failed};SELECT * FROM pays WHERE order_id={failed};SELECT * FROM automatic_renewal_attempts WHERE order_id={failed}')==pending_before
        assert wallet(w,e)==pending_wallet
        cases.append('f1_local_delete_confirmation_cannot_override_unresolved_renewal')


        remoteUncertain=seed(w,e,'customer-delete-timeout')
        result=run(w,e,{'order_id':remoteUncertain,'action':'real_user_delete',
                         'remote_delete_ok':False})
        assert sum(x['type']=='remote_delete' for x in result)==1,result
        assert not any(x['type']=='editText' for x in result),result
        assert count(w,e,'orders_list',
                     f'id={remoteUncertain} AND status=0')==1
        replay=run(w,e,{'order_id':remoteUncertain,'action':'real_user_delete'})
        assert not any(x['type']=='remote_delete' for x in replay),replay
        cases.append('f1_real_user_remote_failure_fenced_without_false_success')

        # The actual expiry Cron cleanup must refuse a journal under review
        # before deleting the associated remote account.
        sql(w,e,f"UPDATE orders_list SET status=0 WHERE id<>{failed}")
        due_grace(w,e,failed)
        pendingCron=run(w,e,{'action':'real_warning_cleanup','order_id':failed,
                             'expire_delta':-100})
        assert any(x['type']=='warnings_finished' for x in pendingCron),pendingCron
        assert not any(x['type']=='remote_delete' for x in pendingCron),pendingCron
        assert count(w,e,'orders_list',f'id={failed} AND status=1')==1
        cases.append('f1_real_expiry_cron_cleanup_blocks_unresolved_journal')
        sql(w,e,f"UPDATE orders_list SET status=0 WHERE id={failed}")
        expired=seed(w,e,'expiry-cron-target')
        due_grace(w,e,expired)
        cron=run(w,e,{'action':'real_warning_cleanup','order_id':expired,
                        'expire_delta':-100})
        assert sum(x['type']=='remote_delete' for x in cron)==1,cron
        assert count(w,e,'orders_list',f'id={expired}')==0
        assert any(x['type']=='send' for x in cron),cron
        cases.append('f1_real_expired_cron_cleanup_single_verified_delete')

        failedCron=seed(w,e,'expiry-cron-unconfirmed')
        due_grace(w,e,failedCron)
        cron=run(w,e,{'action':'real_warning_cleanup','order_id':failedCron,
                        'expire_delta':-100,'remote_delete_ok':False})
        assert sum(x['type']=='remote_delete' for x in cron)==1,cron
        assert count(w,e,'orders_list',
                     f'id={failedCron} AND status=0')==1
        second=run(w,e,{'action':'real_warning_cleanup','order_id':failedCron,
                         'expire_delta':-100})
        assert not any(x['type']=='remote_delete' for x in second),second
        cases.append('f1_real_cron_remote_failure_does_not_repeat_destructive_effect')

        # The actual Cron entrypoint must dispatch reconciliation even when
        # its earlier warning cleanup throws. Stage ONLY an isolated fake
        # include root: production config/bot/private credentials untouched.
        import os,fcntl,hashlib,sys
        import test_post_v9_cron_runner as canonical_cron
        wrapper=w/'cron-fault-staging';wrapper.mkdir()
        entrypoint,cron_cwd=canonical_cron.setup(wrapper)
        (wrapper/'src/Notifications/Warnings.php').write_text(
            '<?php throw new RuntimeException("synthetic warning failure");')
        (wrapper/'src/Finance/AutomaticRenewal.php').write_text(
            '<?php echo "RECONCILIATION_EXECUTED";')
        (wrapper/'src/Finance/PaymentLifecycle.php').write_text(
            '<?php function achbotPayUxMaintain($db){echo "PAYMENT_MAINTENANCE_EXECUTED";}')
        lock_dir=w/'canonical-cron-private-locks';lock_dir.mkdir(mode=0o700)
        lock_path=lock_dir/('achbot-cron-'+hashlib.sha256(str(wrapper.resolve()).encode()).hexdigest()[:24]+'.lock')
        with lock_path.open('x+b') as lease:
            lock_path.chmod(0o600);fcntl.flock(lease.fileno(),fcntl.LOCK_EX)
            # Force a non-3 descriptor, as in the full MariaDB/HTTP suite.
            # subprocess may close fd3 after preexec_fn; perform the mapping
            # after the handoff interpreter starts, immediately before execPHP.
            inherited_fd=fcntl.fcntl(lease.fileno(),fcntl.F_DUPFD_CLOEXEC,4)
            with os.fdopen(inherited_fd,'rb') as inherited:
                assert inherited.fileno()!=3
                handoff=('import os,sys; fd=int(sys.argv[1]); '
                    'os.dup2(fd,3,inheritable=True); os.set_inheritable(3,True); '
                    'os.close(fd) if fd!=3 else None; '
                    'os.execvp(sys.argv[2],sys.argv[2:])')
                proc=subprocess.run([sys.executable,'-c',handoff,str(inherited.fileno()),
                        'php',*canonical_cron.PHP_CLI_OPTS,str(entrypoint),'--job=services'],
                    cwd=cron_cwd,env={**os.environ,'ACHBOT_CRON_LOCK_DIR':str(lock_dir)},
                    pass_fds=(inherited.fileno(),),capture_output=True,timeout=10)
        assert proc.returncode==0 and proc.stdout==b'RECONCILIATION_EXECUTEDPAYMENT_MAINTENANCE_EXECUTED',(
            proc.returncode,proc.stdout,proc.stderr)
        assert b'achbot_warning_cleanup_deferred' in proc.stderr
        cases.append('f1_real_cron_entrypoint_warning_exception_cannot_skip_renewal_alert')

        # A real combined provider ledger and 004 may coexist. Neither
        # migration drops or rewrites the other's original payment evidence.
        sql(w,e,(ROOT/'migrations/post-v9/003_payment_attempts.sql').read_text())
        assert count(w,e,'payment_attempts')==0
        assert len(sql(w,e,'SHOW TABLES').splitlines())==26
        combined=seed(w,e,'combined-ledgers')
        assert sum(x['type']=='remote' for x in
                   run(w,e,{'order_id':combined,'enabled':'on'}))==1
        assert state(w,e,combined)=='done'
        assert count(w,e,'payment_attempts')==0
        cases.append('f3_shared_003_and_004_compatible_without_history_loss')

        # Actual Marzban DELETE transport regression, not a stubbed return
        # value. Owned loopback API only; failed HTTP/non-JSON must never
        # become a reported remote deletion success.
        from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
        from threading import Thread
        class FakeMarzban(BaseHTTPRequestHandler):
            def log_message(self,*args): pass
            def respond(self,code,body):
                self.send_response(code)
                self.send_header('Content-Type','application/json')
                self.send_header('Content-Length',str(len(body)))
                self.end_headers()
                if body:self.wfile.write(body)
            def do_POST(self):
                if self.path!='/api/admin/token':
                    self.respond(404,b'{}');return
                self.respond(200,b'{"access_token":"synthetic-local-only"}')
            def do_DELETE(self):
                tail=self.path.rsplit('/',1)[-1]
                official=b'{"detail":"User successfully deleted"}'
                options={
                  # Official Gozargah/Marzban pinned upstream success contract.
                  'official-200':(200,official),
                  # No evidence of 204 in the pinned Marzban contract.
                  'unconfirmed-204':(204,b''),
                  'unrelated-200':(200,b'{}'),
                  'unexpected-detail':(200,b'{"detail":"Different status"}'),
                  'forged-extra-field':(200,b'{"detail":"User successfully deleted","extra":1}'),
                  'bad-500':(500,b'{"detail":"Server error"}'),
                  'bad-400':(400,b'{"detail":"Request failed"}'),
                  'not-found-404':(404,b'{"detail":"User not found"}'),
                  'bad-200':(200,b'<html>not-json</html>'),
                  'empty-200':(200,b''),
                  'lost-response':(0,b''),
                  'integrated-customer-200':(200,official),
                  'integrated-admin-200':(200,official),
                  'integrated-cron-200':(200,official),
                  'integrated-customer-bad-500':(500,b'{"detail":"Failed"}'),
                  'integrated-customer-unexpected-200':(200,b'{"detail":"Not deleted"}'),
                }
                code,body=options.get(tail,(404,b''))
                self.server.delete_calls.append((tail,code))
                if code==0:
                    self.connection.close()
                else:
                    self.respond(code,body)
            def do_GET(self):
                self.respond(405,b'')
        server=ThreadingHTTPServer(('127.0.0.1',0),FakeMarzban)
        server.delete_calls=[]
        thread=Thread(target=server.serve_forever,daemon=True)
        thread.start()
        try:
            sql(w,e,"UPDATE server_config SET panel_url="
                    f"'http://127.0.0.1:{server.server_port}' WHERE id=2")
            deleteScript=w/'owned-delete-transport.php'
            deleteScript.write_text('''<?php
$_SERVER['SCRIPT_FILENAME']=__FILE__;
$root=$argv[1];
$connection=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
require_once $root.'/src/Panel/Accounts.php';
$out=\\AchBot\\Panel\\Accounts\\deleteMarzban(2,$argv[4]);
echo json_encode(['success'=>$out->success??false,
                  'uncertain'=>$out->uncertain??false],
                 JSON_THROW_ON_ERROR);
''')
            transportCases=(
                'official-200','unconfirmed-204','unrelated-200',
                'unexpected-detail','forged-extra-field','bad-500','bad-400',
                'not-found-404','bad-200','empty-200','lost-response',
            )
            for case in transportCases:
                argv=['php','-n','-d','extension=mysqlnd',
                      '-d','extension=mysqli','-d','extension=curl',
                      str(deleteScript),str(ROOT),str(w/'mysql.sock'),DB,case]
                proc=subprocess.run(argv,cwd=w,env=e,capture_output=True,
                                    timeout=15)
                assert proc.returncode==0 and not proc.stderr,(case,proc.stderr)
                result=json.loads(proc.stdout)
                expectedSuccess=case=='official-200'
                assert result['success'] is expectedSuccess,(case,result)
                if not expectedSuccess:assert result['uncertain'] is True,(case,result)
            assert [x[0] for x in server.delete_calls]==list(transportCases),server.delete_calls
            cases.append('f1_real_marzban_delete_http_failure_not_falsely_successful')

            # The exact REAL guarded customer/admin/Cron PHP routes must
            # consume the actual native Panel/Accounts.php transport result,
            # not a stub pretending a provider response succeeded.
            sql(w,e,"UPDATE orders_list SET status=0 WHERE status=1")
            customer=seed(w,e,'integrated-customer-200',wallet=500000)
            assert sum(x['type']=='remote' for x in run(w,e,{
                'order_id':customer,'enabled':'on'}))==1
            before_wallet=wallet(w,e)
            before_capacity=int(sql(w,e,"SELECT ucount FROM server_info WHERE id=2"))
            old_paid=count(w,e,'pays',
                           f"order_id={customer} AND state='paid_with_wallet'")
            old_journal=count(w,e,'automatic_renewal_attempts',
                              f'order_id={customer} AND stage="done"')
            first=len(server.delete_calls)
            res=run(w,e,{'order_id':customer,'action':'real_user_delete',
                         'official_http_provider':True})
            assert sum(x['type']=='remote_delete' for x in res)==1,res
            assert sum(x['type']=='editText' for x in res)==1,res
            assert server.delete_calls[first:]==[('integrated-customer-200',200)]
            assert count(w,e,'orders_list',f'id={customer}')==0
            assert count(w,e,'pays',
                         f"order_id={customer} AND state='paid_with_wallet'")==old_paid==1
            assert count(w,e,'automatic_renewal_attempts',
                         f'order_id={customer} AND stage="done"')==old_journal==1
            assert wallet(w,e)==before_wallet
            assert int(sql(w,e,'SELECT ucount FROM server_info WHERE id=2'))==before_capacity+1
            again=run(w,e,{'order_id':customer,'action':'real_user_delete',
                           'official_http_provider':True})
            assert not any(x['type']=='remote_delete' for x in again),again
            assert len(server.delete_calls)==first+1
            cases.append('f1_official_http_200_guarded_customer_once_full_evidence')

            admin=seed(w,e,'integrated-admin-200',wallet=500000)
            run(w,e,{'order_id':admin,'enabled':'on'})
            before_wallet=wallet(w,e)
            before_capacity=int(sql(w,e,'SELECT ucount FROM server_info WHERE id=2'))
            first=len(server.delete_calls)
            res=run(w,e,{'order_id':admin,'action':'real_admin_delete',
                         'official_http_provider':True})
            assert sum(x['type']=='remote_delete' for x in res)==1,res
            assert server.delete_calls[first:]==[('integrated-admin-200',200)]
            assert count(w,e,'orders_list',f'id={admin}')==0
            assert count(w,e,'pays',f'order_id={admin}')==1
            assert count(w,e,'automatic_renewal_attempts',f'order_id={admin}')==1
            assert wallet(w,e)==before_wallet
            assert int(sql(w,e,'SELECT ucount FROM server_info WHERE id=2'))==before_capacity+1
            cases.append('f1_official_http_200_guarded_admin_once_full_evidence')

            cron_id=seed(w,e,'integrated-cron-200',wallet=500000)
            run(w,e,{'order_id':cron_id,'enabled':'on'})
            # Notif=0 after paid renewal: simulate the independently due
            # expired-order warning without changing financial history.
            due_grace(w,e,cron_id)
            before_wallet=wallet(w,e)
            first=len(server.delete_calls)
            res=run(w,e,{'order_id':cron_id,'action':'real_warning_cleanup',
                         'official_http_provider':True,'expire_delta':-100})
            assert sum(x['type']=='remote_delete' for x in res)==1,res
            assert server.delete_calls[first:]==[('integrated-cron-200',200)]
            assert count(w,e,'orders_list',f'id={cron_id}')==0
            assert count(w,e,'pays',f'order_id={cron_id}')==1
            assert count(w,e,'automatic_renewal_attempts',f'order_id={cron_id}')==1
            assert wallet(w,e)==before_wallet
            again=run(w,e,{'order_id':cron_id,'action':'real_warning_cleanup',
                           'official_http_provider':True,'expire_delta':-100})
            assert not any(x['type']=='remote_delete' for x in again),again
            cases.append('f1_official_http_200_guarded_cron_once_full_evidence')

            for failureRemark in ('integrated-customer-bad-500',
                                  'integrated-customer-unexpected-200'):
                fallback=seed(w,e,failureRemark,wallet=500000)
                run(w,e,{'order_id':fallback,'enabled':'on'})
                before_wallet=wallet(w,e)
                before_capacity=int(sql(w,e,'SELECT ucount FROM server_info WHERE id=2'))
                first=len(server.delete_calls)
                res=run(w,e,{'order_id':fallback,'action':'real_user_delete',
                             'official_http_provider':True})
                assert sum(x['type']=='remote_delete' for x in res)==1,res
                assert not any(x['type']=='editText' for x in res),res
                assert len(server.delete_calls)==first+1
                assert count(w,e,'orders_list',f'id={fallback} AND status=0')==1
                assert count(w,e,'automatic_renewal_attempts',
                             f'order_id={fallback} AND stage="done"')==1
                assert count(w,e,'pays',
                             f"order_id={fallback} AND state='paid_with_wallet'")==1
                assert wallet(w,e)==before_wallet
                assert int(sql(w,e,'SELECT ucount FROM server_info WHERE id=2'))==before_capacity
                retried=run(w,e,{'order_id':fallback,'action':'real_user_delete',
                                 'official_http_provider':True})
                assert not any(x['type']=='remote_delete' for x in retried),retried
                assert len(server.delete_calls)==first+1
            cases.append('f1_real_guarded_customer_error_and_unexpected_200_fenced')

            preview=seed(w,e,'preview-private-owned')
            res=run(w,e,{'order_id':preview,
                         'action':'real_user_preview_nonadmin'})
            assert any(x['type']=='alert' for x in res),res
            assert not any(x['type']=='editText' for x in res),res
            assert not any(x['type']=='remote_delete' for x in res),res
            assert count(w,e,'orders_list',
                         f'id={preview} AND status=1')==1
            cases.append('o1_user_delete_preview_ownership_and_privacy')
        finally:
            server.shutdown()
            thread.join(timeout=5)
            server.server_close()
    report=dict(complete=True,cases=len(cases),scenarios=cases,source_only_004=True,
                original_schema_file_unchanged=True,provider_requests=0,
                production_mutations=0,native_php_workers=2,
                synthetic_local_http_delete_cases=11)
    if a.report:a.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps({k:v for k,v in report.items() if k!='scenarios'}))
if __name__=='__main__': main()
