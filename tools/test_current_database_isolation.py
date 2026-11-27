#!/usr/bin/env python3
"""Current real writers, same MariaDB server, independent disposable schemas."""
import argparse
import json
from pathlib import Path
import subprocess
import database_fixture as owned

ROOT=Path(__file__).resolve().parents[1]
PHP=r"""<?php
mysqli_report($argv[3]==='strict'?MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT:MYSQLI_REPORT_OFF);
$root=$argv[1];$socket=$argv[2];
require_once $root.'/src/Purchase/Core.php';
require_once $root.'/src/Finance/PaymentKeyAdmin.php';
require_once $root.'/src/Subscription/ServerDisplaySettings.php';
require_once $root.'/src/Subscription/PhysicalService.php';
require_once $root.'/src/Services/Ownership.php';
require_once $root.'/src/Telegram/UserProfile.php';
require_once $root.'/tools/source_scan.php';
eval(str_replace('__DIR__',var_export($root,true),achbotExtractFunction($root.'/config.php','achbotMutateBotStates')));
function achbotWebhookIngressAuthenticated(): bool {return true;}
$GLOBALS['telegramWebhookSecret']='Synthetic_Isolation_Only_2026';
$_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']=$GLOBALS['telegramWebhookSecret'];
function db($name) {global $socket; return new mysqli('localhost','root','',$name,0,$socket);}
function yes($v,$case) {if (!$v) throw new RuntimeException($case);}
function row($db,$sql,$types='',$args=[]) {return achbotProviderRow($db,$sql,$types,$args);}
function lock($db,$name) {return (int)row($db,'SELECT GET_LOCK(?,0) AS acquired','s',[$name])['acquired']===1;}
function free($db,$name) {return (int)row($db,'SELECT IS_FREE_LOCK(?) AS free','s',[$name])['free']===1;}
function release($db,$name) {yes((int)row($db,'SELECT RELEASE_LOCK(?) AS released','s',[$name])['released']===1,'release');}
function fails($fn) {try {$fn();} catch (Throwable $e) {return true;} return false;}
function message($id,$text) {return (object)['update_id'=>$id,'message'=>(object)['message_id'=>92,'text'=>$text,'from'=>(object)['id'=>9001],'chat'=>(object)['id'=>9001,'type'=>'private']]];}
function update($id,$data) {return (object)['update_id'=>$id,'callback_query'=>(object)['data'=>$data,'from'=>(object)['id'=>9001],
    'message'=>(object)['message_id'=>91,'chat'=>(object)['id'=>9001,'type'=>'private']]]];}
$a=db('lock_a');$b=db('lock_b');$peer=db('lock_a');$connection=$b;$botState=[];$checks=[];
foreach ([$a,$b] as $d) {
    foreach (['setting','users','server_info','server_config','service_plan_changes'] as $table)
        $d->query('DELETE FROM '.$table);
    $d->query("INSERT INTO users(userid,name,username,refcode,date,isAdmin) VALUES(9001,'owner','owner','0','0',1)");
    $d->query("INSERT INTO server_info(id,title,ucount,remark,flag,active) VALUES(1,'fixture','10','','',1)");
    $d->query("INSERT INTO server_config(id,panel_url,type,username,password) VALUES(1,'https://fixture.invalid','pasarguard','fixture','fixture')");
}
// Assert lock ownership at the actual persisted writes, not just in a
// separate GET_LOCK probe. A writer using the wrong name cannot pass.
$resources=['BOT_STATES'=>'settings:BOT_STATES','PAYMENT_KEYS'=>'settings:PAYMENT_KEYS',
    'ACHBOT_ADMIN_PAYMENT_KEYS_SOURCE'=>'settings:PAYMENT_KEYS',
    'ACHBOT_PANEL_GROUP'=>'settings:panel-groups','ACHBOT_SERVER_DISPLAY_1'=>'settings:server-display'];
foreach (['INSERT','UPDATE'] as $event) {
    $checksSql=[];
    foreach ($resources as $type=>$resource) {
        $name=achbotDatabaseLockName($b,$resource);
        $checksSql[]="IF NEW.type='$type' AND COALESCE(IS_USED_LOCK('$name')=CONNECTION_ID(),0)=0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='incorrect writer lease'; END IF;";
    }
    $b->query("CREATE TRIGGER fixture_lease_".strtolower($event)." BEFORE $event ON setting FOR EACH ROW BEGIN ".implode(' ',$checksSql)." END");
}
$startName=achbotDatabaseLockName($b,'start:9002');
$b->query("CREATE TRIGGER fixture_signup_lease BEFORE INSERT ON users FOR EACH ROW BEGIN IF NEW.userid=9002 AND COALESCE(IS_USED_LOCK('$startName')=CONNECTION_ID(),0)=0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='incorrect signup lease'; END IF; END");
foreach ([
    'bot_states'=>['settings:BOT_STATES',fn()=>achbotMutateBotStates(fn($x)=>['fixture'=>1])],
    'payment_keys'=>['settings:PAYMENT_KEYS',function() use($b) {
        yes(achbotAdminPaymentKeyChange(update(501,'changePaymentKeysbankAccount'),9001,9001,91,9001,'start','bankAccount'),'payment_start');
        yes(achbotAdminPaymentKeyChange(message(502,'fixture-account'),9001,9001,92,9001,'save','bankAccount','fixture-account'),'payment_save');
        return json_decode(row($b,"SELECT value FROM setting WHERE type='PAYMENT_KEYS'")['value'],true)['bankAccount']==='fixture-account';
    }],
    'panel_groups'=>['settings:panel-groups',fn()=>achbotCreatePanelGroupRecord($b,'fixture')>0],
    'server_display'=>['settings:server-display',function() use($b) {
        $b->query("UPDATE users SET step='none' WHERE userid=9001");
        achbotServerDisplayEdit($b,1,9001,9001,503,'set','flip','dcfg',0);
        $doc=json_decode(row($b,"SELECT value FROM setting WHERE type='ACHBOT_SERVER_DISPLAY_1'")['value'],true);
        return $doc['updated_source']===503 && $doc['display']['policy']['delivery']['show_configs']===false;
    }],
    'start'=>['start:9002',fn()=>achbotRegisterStartUser($b,(object)['message'=>(object)['from'=>(object)['id'=>9002,'first_name'=>'fixture'],'chat'=>(object)['type'=>'private','id'=>9002],'text'=>'/start']],9002,0,0)!==null],
] as $case=>[$resource,$write]) {
    $name=achbotDatabaseLockName($a,$resource);$other=achbotDatabaseLockName($b,$resource);
    yes($name!==$other && strlen($name)<=64,$case.'_names');
    yes(lock($a,$name),$case.'_hold_a');
    try {
        yes(!lock($peer,$name),$case.'_same_db_exclusion');
        yes($write(),$case.'_actual_cross_db_writer');
        yes(free($b,$other),$case.'_writer_released');
        yes(!free($peer,$name),$case.'_a_still_held');
    } finally {release($a,$name);}
    $checks[]=$case.'_cross_db_same_db';
}
yes(!achbotMutateBotStates(fn($x)=>throw new RuntimeException('fixture_failure')),'settings_failed');
yes(free($b,achbotDatabaseLockName($b,'settings:BOT_STATES')),'settings_failure_released');
yes(row($b,"SELECT value FROM setting WHERE type='BOT_STATES'")['value']==='{"fixture":1}','settings_rollback');
yes(achbotCreatePanelGroupRecord($b,'fixture')===null,'group_duplicate');
$b->query("CREATE TRIGGER fixture_group_failure BEFORE INSERT ON setting FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture failure'");
yes(fails(fn()=>achbotCreatePanelGroupRecord($b,'failed')),'group_sql_failure');
yes(free($b,achbotDatabaseLockName($b,'settings:panel-groups')),'group_failure_released');
$b->query('DROP TRIGGER fixture_group_failure');
yes(!achbotAdminPaymentKeyChange(update(501,'changePaymentKeysbankAccount'),9001,9001,91,9001,'start','bankAccount'),'payment_replay_failed');
yes(free($b,achbotDatabaseLockName($b,'settings:PAYMENT_KEYS')),'payment_failure_released');
yes(fails(fn()=>achbotServerDisplayEdit($b,1,9001,9001,502,'start')),'display_replay_failed');
yes(free($b,achbotDatabaseLockName($b,'settings:server-display')),'display_failure_released');
$checks[]='writer_failure_release';
$checks[]='actual_persisted_writers_own_scoped_lock';
require_once $root.'/src/Finance/GatewayClient.php';
require_once $root.'/src/Finance/FollowupOutbox.php';
require_once $root.'/src/Finance/OperationalReports.php';
require_once $root.'/src/Services/Execution.php';
$receipt=['kind'=>'edit','role'=>'receipt_primary','recipient'=>'9001','message_id'=>91];
$otherReceipt=$receipt;$otherReceipt['role']='receipt_mirror';
foreach ([
    fn($d)=>achbotHostedWorkerLockName($d,23),
    fn($d)=>achbotServiceExecutionLock($d,23),
    fn($d)=>achbotFollowupLeaseName($d,$receipt),
    fn($d)=>achbotOperationalReportLock($d,'fixture:23')
] as $nameFor) {
    $name=$nameFor($a);$other=$nameFor($b);
    yes($name!==$other && lock($a,$name),'existing_lease_scope');
    try {
        yes(!lock($peer,$name) && lock($b,$other),'existing_lease_exclusion');
        release($b,$other);
    } finally {release($a,$name);}
}
yes(achbotFollowupLeaseName($b,$receipt)===achbotFollowupLeaseName($b,$otherReceipt),'same_receipt_destination_lease');
yes(achbotOriginalDispatchLeaseBegin($a,23)==='owned','hosted_original_a');
try {
    yes(achbotOriginalDispatchLeaseBegin($peer,23)==='busy','hosted_original_peer');
    yes(achbotOriginalDispatchLeaseBegin($a,23)==='borrowed','hosted_original_borrowed');
    yes(achbotOriginalDispatchLeaseBegin($b,23)==='owned','hosted_original_b');
    achbotOriginalDispatchLeaseEnd($b,23,'owned');
    achbotOriginalDispatchLeaseEnd($a,23,'borrowed');
    yes(!free($a,achbotHostedWorkerLockName($a,23)),'borrowed_not_released');
} finally {achbotOriginalDispatchLeaseEnd($a,23,'owned');}
$checks[]='existing_execution_and_projection_lease_scope';
$key='achbot-plan-'.str_repeat('a',40);
foreach ([$a,$b] as $d)
    $d->query("INSERT INTO service_plan_changes(lock_key,source_order_id,plan_id,server_id,provider,state,intent_json) VALUES('$key',1,1,1,'pasarguard','prepared','{}')");
achbotPhysicalAcquireLock($a,$key);
try {
    yes(!lock($peer,achbotPhysicalSessionLockName($peer,$key)),'physical_same_db');
    $b->begin_transaction();
    yes(!achbotServicePlanChangePendingLocked($b,$key),'other_db_orphan_retired');
    $b->commit();
    yes(row($b,'SELECT state FROM service_plan_changes')['state']==='cancelled','b_journal_retired');
    $peer->begin_transaction();
    yes(achbotServicePlanChangePendingLocked($peer,$key),'a_live_preparation_not_retired');
    $peer->commit();
    yes(row($a,'SELECT state FROM service_plan_changes')['state']==='prepared','a_journal_intact');
    achbotPhysicalAcquireLock($b,$key);achbotPhysicalReleaseLock($b,$key);
} finally {achbotPhysicalReleaseLock($a,$key);}
$checks[]='physical_journal_independence';
$none=db(null);yes(fails(fn()=>achbotDatabaseLockName($none,'settings:BOT_STATES')),'missing_schema');$none->close();
$checks[]='selected_database_required';
echo json_encode($checks,JSON_THROW_ON_ERROR);
"""
def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--run-disposable',action='store_true',required=True)
    parser.add_argument('--report',type=Path)
    args=parser.parse_args()
    checks=[]
    with owned.owned_database() as (work,env):
        cli=['mariadb','--no-defaults','--protocol=SOCKET','--socket='+str(work/'mysql.sock'),'-uroot']
        subprocess.run(cli,input=b'CREATE DATABASE lock_a; CREATE DATABASE lock_b;',env=env,check=True,capture_output=True)
        for db in ('lock_a','lock_b'):
            subprocess.run(cli+[db],input=(ROOT/'database/current-schema.sql').read_bytes(),env=env,check=True,capture_output=True)
        script=work/'isolation.php';script.write_text(PHP)
        for mode in ('strict','off'):
            p=subprocess.run(['php',str(script),str(ROOT),str(work/'mysql.sock'),mode],env=env,capture_output=True,text=True,timeout=60)
            if p.returncode:raise RuntimeError(mode+': '+p.stderr+' '+p.stdout)
            checks.extend(mode+':'+case for case in json.loads(p.stdout))
    result={'complete':True,'status':'PASS','checks':checks,'cases':len(checks)}
    if args.report:args.report.write_text(json.dumps(result,indent=2)+'\n')
    print(json.dumps(result))
if __name__=='__main__':main()
