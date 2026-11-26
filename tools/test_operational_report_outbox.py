#!/usr/bin/env python3
"""Real common outbox, owned MariaDB and synthetic Telegram acknowledgements."""
import argparse
import json
from pathlib import Path
import subprocess
import database_fixture as owned

ROOT = Path(__file__).resolve().parents[1]
PHP = r'''<?php
mysqli_report($argv[4]==='strict'?MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT:MYSQLI_REPORT_OFF);
$root=$argv[1];$connection=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$connection->set_charset('utf8mb4');
$admin=9001;$telegramWebhookSecret='FixtureOperationalReportSecret2026';
$botState=['rewardChannel'=>'@FixtureReport'];
require $root.'/messages/loader.php';
require $root.'/src/Finance/OperationalReports.php';
require $root.'/src/Finance/FollowupAttempts.php';
$events=[];$checks=[];$reply='ack';
function ensure($ok,$name) {if (!$ok) throw new RuntimeException($name);$GLOBALS['checks'][]=$name;}
function query($sql) {return $GLOBALS['connection']->query($sql);}
function row($sql) {return query($sql)->fetch_assoc();}
function countRows($table) {return (int)row('SELECT COUNT(*) AS n FROM '.$table)['n'];}
function sendMessage($body,$keys,$mode,$to) {
    global $events,$reply;
    $ctx=$GLOBALS['achbotTelegramDiagnosticContext'];
    $r=row('SELECT state,attempt_count FROM payment_followup_intents WHERE id='.(int)$ctx['followup_id']);
    if ((int)row('SELECT @@in_transaction AS active')['active']!==0 ||
        $r['state']!=='dispatching' || (int)$r['attempt_count']<=0)
        throw new RuntimeException('fixture_wire_without_committed_claim');
    $events[]=['to'=>$to,'text'=>$body,'keys'=>$keys,'mode'=>$mode,'ref'=>$ctx['operational_ref']];
    return $reply==='ack'?fixtureMessageAck($to,10000+count($events)):$reply;
}
function stage($ref,$pages=[],$stage='operational_report') {
    achbotOperationalReportStageLocked($GLOBALS['connection'],$ref,1001,9002,'orders','<b>Verified</b>','{"inline_keyboard":[]}',$pages,$stage);
}
function reportRow($ref,$to) {
    return row("SELECT * FROM payment_followup_intents WHERE operational_ref='".$ref."' AND recipient='".$to."' ORDER BY id LIMIT 1");
}
query("INSERT INTO setting(type,value) VALUES ('ADMIN_NOTIFY_9002','off')");
$baseWallet=row('SELECT SUM(wallet) AS balance FROM users')['balance'];
$connection->begin_transaction();
query("UPDATE users SET temp='fixture-rollback' WHERE userid=1001");
stage('delete:101');
$connection->rollback();
ensure(countRows('payment_followup_intents')===0 &&
    row("SELECT temp FROM users WHERE userid=1001")['temp']!=='fixture-rollback','rollback_has_no_report_or_business_effect');
$connection->begin_transaction();
query("UPDATE users SET temp='fixture-commit' WHERE userid=1001");
stage('delete:101',['Continuation']);
$connection->commit();
ensure(countRows('payment_followup_intents')===6 && countRows('payment_followup_attempts')===0 &&
    row("SELECT temp FROM users WHERE userid=1001")['temp']==='fixture-commit','committed_outbox_survives_missing_immediate_send');
ensure(countRows('pays')===0 && countRows('wallet_entries')===0 && countRows('service_operations')===0,
    'no_synthetic_payment_wallet_or_service_source');
$drained=achbotFollowupMaintainOriginalPending($connection,10,8.0);
ensure(count($drained)===6 && count(array_filter($drained,fn($s)=>$s==='acknowledged'))===6,
    'common_worker_delivers_all_original_pages');
$main=array_values(array_filter($events,fn($e)=>$e['text']==='<b>Verified</b>'));
ensure(array_column($main,'to')===[9001,9002,'@fixturereport'] &&
    array_column($main,'keys')===['{"inline_keyboard":[]}','{"inline_keyboard":[]}',null],
    'owner_actor_channel_dedup_private_controls');
ensure(achbotOperationalReportDispatch($connection,'delete:101') && count($events)===6,
    'acknowledged_never_repeated');
$ackRows=row("SELECT COUNT(*) AS n,COUNT(DISTINCT CONCAT(ack_chat_id,':',ack_message_id)) AS identities
    FROM payment_followup_attempts WHERE result='acknowledged'");
ensure((int)$ackRows['n']===6 && (int)$ackRows['identities']===6 &&
    (int)row("SELECT COUNT(*) AS n FROM payment_followup_attempts a JOIN payment_followup_intents i ON i.id=a.intent_id
      WHERE i.recipient='@fixturereport' AND a.ack_chat_id<0 AND a.ack_message_id>0")['n']===2 &&
    (int)row("SELECT COUNT(*) AS n FROM payment_followup_attempts a JOIN payment_followup_intents i ON i.id=a.intent_id
      WHERE i.recipient='9001' AND a.ack_chat_id=9001 AND a.ack_message_id>0")['n']===2,
    'actual_message_and_destination_are_recorded_per_attempt');

$connection->begin_transaction();stage('plan:102');$connection->commit();
$r=reportRow('plan:102','9001');$id=(int)$r['id'];
$reply=(object)['ok'=>false,'error_code'=>500,'description'=>'Internal error'];
ensure(achbotFollowupDispatchOne($connection,$id)==='unknown','ambiguous_delivery_not_retried');
$n=count($events);
ensure(achbotFollowupDispatchOne($connection,$id)==='unknown' && count($events)===$n,
    'unknown_requires_explicit_redrive');
$r=reportRow('plan:102','9001');$token=achbotFollowupRedriveToken($r,9001);
ensure(achbotFollowupDispatchOne($connection,$id,9002,$token)==='stale','redrive_token_actor_bound');
$reply='ack';
ensure(achbotFollowupDispatchOne($connection,$id,9001,$token)==='acknowledged' && count($events)===$n+1,
    'explicit_redrive_uses_same_packet');
$r=reportRow('plan:102','@fixturereport');$id=(int)$r['id'];
$reply=(object)['ok'=>false,'error_code'=>429,'description'=>'Too Many Requests','parameters'=>(object)['retry_after'=>35]];
ensure(achbotFollowupDispatchOne($connection,$id)==='pending' &&
    achbotFollowupDispatchOne($connection,$id)==='not_due','verified_429_only_due_retry');
$reply='ack';
query('UPDATE payment_followup_intents SET next_attempt_at=NOW(6) WHERE id='.$id);
ensure(achbotFollowupDispatchOne($connection,$id)==='acknowledged','due_retry_sends_once');
$r=reportRow('plan:102','9002');$id=(int)$r['id'];
query('UPDATE users SET isAdmin=0 WHERE userid=9002');
$n=count($events);
ensure(achbotFollowupDispatchOne($connection,$id)==='skipped' && count($events)===$n &&
    reportRow('plan:102','9002')['last_result']==='recipient_ineligible' &&
    (int)reportRow('plan:102','9002')['attempt_count']===0 &&
    reportRow('plan:102','9002')['next_attempt_at']===null,
    'revoked_actor_terminal_no_telegram_attempt');
ensure(achbotOperationalReportDispatch($connection,'plan:102'),'skipped_recipient_does_not_block_acknowledged_siblings');
$attempts=$connection->query("SELECT result,ack_chat_id,ack_message_id FROM payment_followup_attempts
    WHERE intent_id=".(int)reportRow('plan:102','@fixturereport')['id']." ORDER BY attempt_no")->fetch_all(MYSQLI_ASSOC);
ensure(count($attempts)===2 && $attempts[0]['result']==='rejected' &&
    $attempts[0]['ack_chat_id']===null && $attempts[0]['ack_message_id']===null &&
    $attempts[1]['result']==='acknowledged' && (int)$attempts[1]['ack_chat_id']<0 &&
    (int)$attempts[1]['ack_message_id']>0,'rate_limit_recovery_preserves_original_attempt_and_pins_new_ack');

query('UPDATE users SET isAdmin=1 WHERE userid=9002');
ensure(achbotFollowupDispatchOne($connection,$id)==='skipped' && count($events)===$n,
    'restored_permission_does_not_replay_skipped_report');
$connection->begin_transaction();stage('server:103');$connection->commit();
$r=reportRow('server:103','@fixturereport');$id=(int)$r['id'];
$botState['rewardChannel']='@OtherReport';
ensure(achbotFollowupDispatchOne($connection,$id)==='skipped' && count($events)===$n &&
    reportRow('server:103','@fixturereport')['state']==='skipped','changed_channel_no_silent_retarget');
$botState['rewardChannel']='@FixtureReport';
$connection->begin_transaction();stage('server:106');$connection->commit();
$r=reportRow('server:106','@fixturereport');$id=(int)$r['id'];
query("UPDATE payment_followup_intents SET sealed_payload='corrupt' WHERE id=".$id);
$botState['rewardChannel']='@OtherReport';
ensure(achbotFollowupDispatchOne($connection,$id)==='unavailable' && count($events)===$n &&
    reportRow('server:106','@fixturereport')['state']==='pending','tampered_envelope_not_mislabeled_as_skipped');
$botState['rewardChannel']='@FixtureReport';
// A transient NOWAIT permission read must defer, not permanently skip.
query("UPDATE setting SET value='on' WHERE type='ADMIN_NOTIFY_9002'");
query("INSERT INTO setting(type,value) VALUES ('ADMIN_ROLE_9002','manager')");
$connection->begin_transaction();
achbotOperationalReportStageLocked($connection,'server:107',1001,0,'orders','Fixture');
$connection->commit();
$r=reportRow('server:107','9002');$id=(int)$r['id'];
$other=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$other->begin_transaction();
$other->query("SELECT id FROM users WHERE userid=9002 FOR UPDATE");
ensure(achbotFollowupDispatchOne($connection,$id)==='unavailable' &&
    reportRow('server:107','9002')['state']==='pending','contended_permission_read_not_definitive');
$other->rollback();$other->close();
ensure(achbotFollowupDispatchOne($connection,$id)==='acknowledged','deferred_permission_recovers');
query("DELETE FROM setting WHERE type='ADMIN_ROLE_9002'");
query("UPDATE setting SET value='off' WHERE type='ADMIN_NOTIFY_9002'");
// Unknown transport evidence remains immutable when a later authorized
// redrive detects that the original recipient is no longer staff.
$connection->begin_transaction();stage('server:108');$connection->commit();
$r=reportRow('server:108','9002');$id=(int)$r['id'];
$reply=(object)['ok'=>false,'error_code'=>500,'description'=>'Internal error'];
ensure(achbotFollowupDispatchOne($connection,$id)==='unknown','actor_original_attempt_unknown');
$reply='ack';
$r=reportRow('server:108','9002');$token=achbotFollowupRedriveToken($r,9001);
query('UPDATE users SET isAdmin=0 WHERE userid=9002');
$n=count($events);
ensure(achbotFollowupDispatchOne($connection,$id,9001,str_repeat('a',20))==='stale' &&
    reportRow('server:108','9002')['state']==='unknown','stale_manual_skip_cannot_mutate');
ensure(achbotFollowupDispatchOne($connection,$id,9001,$token)==='skipped' && count($events)===$n &&
    (int)reportRow('server:108','9002')['attempt_count']===1 &&
    row('SELECT result FROM payment_followup_attempts WHERE intent_id='.$id)['result']==='unknown',
    'authorized_skip_preserves_unknown_original_attempt');
query('UPDATE users SET isAdmin=1 WHERE userid=9002');
$connection->begin_transaction();stage('bulk:9002:104',[],'operational_report_p1');stage('bulk:9002:104',[],'operational_report_p2');$connection->commit();
$lease=achbotOperationalReportLock($connection,'bulk:9002:104');
$s=achbotProviderStatement($connection,'SELECT GET_LOCK(?,0)','s',[$lease]);$s->close();
achbotOperationalReportCompactBatch($connection,'bulk:9002:104',9002,'Complete batch',[]);
$s=achbotProviderStatement($connection,'SELECT RELEASE_LOCK(?)','s',[$lease]);$s->close();
ensure((int)row("SELECT COUNT(*) AS n FROM payment_followup_intents WHERE operational_ref='bulk:9002:104' AND stage='operational_report'")['n']===3,
    'batch_compaction_only_unattempted_original_audience');
ensure(achbotOperationalReportDispatch($connection,'bulk:9002:104'),'compacted_batch_delivers_once');
require_once $root.'/src/Subscription/GiftBatch.php';
$giftSource=['kind'=>'telegram_bulk_gift_v1','update_id'=>654321,'actor_id'=>9002,
    'chat_id'=>9002,'message_id'=>987,'text_sha256'=>hash('sha256','fixture')];
$sourceType=achbotGiftSourceType($giftSource);
$receipt=['gift_id'=>105,'server_id'=>2,'volume_mb'=>1024,'days'=>1,'enqueue_source_type'=>$sourceType];
$claim=['version'=>1,'source_type'=>$sourceType,'source'=>$giftSource,'server_id'=>2,
    'volume_mb'=>1024,'days'=>1,'gift_id'=>105];
$st=achbotProviderStatement($connection,'INSERT INTO setting(type,value) VALUES (?,?)','ss',[$sourceType,json_encode($claim,JSON_THROW_ON_ERROR)]);$st->close();
ensure(achbotGiftReportActor($connection,$receipt)===9002 &&
    achbotGiftReportActor($connection,array_replace($receipt,['gift_id'=>106]))===0 &&
    achbotGiftReportActor($connection,array_replace($receipt,['days'=>2]))===0,
    'scheduled_gift_actor_from_original_enqueue_not_worker');
query("INSERT INTO orders_list (id,userid,token,transid,fileid,server_id,inbound_id,remark,uuid,expire_date,link,amount,status,date)
    VALUES (101,1001,REPEAT('a',30),'fixture',7,2,0,'fixture-gift','fixture-uuid',1800000100,'[]',0,1,'fixture')");
$order=achbotGiftOrder($connection,101);
$connection->begin_transaction();
achbotGiftStageReportLocked($connection,'batch:105:0','fixture-gift',1,1024,
    [$order],achbotGiftReportActor($connection,$receipt));
$connection->commit();
ensure((int)row("SELECT COUNT(*) AS n FROM payment_followup_intents WHERE operational_ref='batch:105:0' AND state='pending'")['n']===4,
    'gift_customer_and_report_commit_before_any_telegram');
$n=count($events);
ensure(achbotOperationalReportDispatch($connection,'batch:105:0') &&
    (int)row("SELECT COUNT(*) AS n FROM payment_followup_intents WHERE operational_ref='batch:105:0' AND state='acknowledged'")['n']===4 &&
    count($events)===$n+4 && $events[$n+3]['to']===1001,
    'scheduled_gift_report_shares_committed_outbox_and_actual_actor');
$connection->begin_transaction();
achbotGiftStageReportLocked($connection,'direct:106','fixture-gift',1,1024,[$order],9002);
$connection->commit();
$r=reportRow('direct:106','1001');$id=(int)$r['id'];
$reply=(object)['ok'=>false,'error_code'=>500,'description'=>'Internal error'];
ensure(achbotFollowupDispatchOne($connection,$id)==='unknown','gift_customer_unknown_has_original_attempt');
$n=count($events);
ensure(achbotFollowupDispatchOne($connection,$id)==='unknown' && count($events)===$n,'gift_customer_unknown_never_automatic_replay');
$reply='ack';
$r=reportRow('direct:106','1001');$token=achbotFollowupRedriveToken($r,9001);
ensure(achbotFollowupDispatchOne($connection,$id,9001,$token)==='acknowledged' &&
    count($events)===$n+1 && $events[$n]['to']===1001,'gift_customer_explicit_original_redrive');
$connection->begin_transaction();
achbotGiftStageReportLocked($connection,'direct:107','fixture-gift',1,1024,[$order],9002);
$connection->commit();
$r=reportRow('direct:107','1001');$id=(int)$r['id'];$n=count($events);
query("UPDATE orders_list SET userid=9002 WHERE id=101");
ensure(achbotFollowupDispatchOne($connection,$id)==='skipped' && count($events)===$n,
    'gift_customer_changed_attachment_never_retargeted');
query("UPDATE orders_list SET userid=1001 WHERE id=101");

foreach ([
    (object)['ok'=>true],
    (object)['ok'=>true,'result'=>true],
    fixtureMessageAck(9002,7201),
    (object)['ok'=>true,'error_code'=>400,'result'=>fixtureMessageAck(9001,7204)->result],
    (object)['ok'=>true,'result'=>(object)['chat'=>(object)['id'=>9001],'message_id'=>'7202']],
    (object)['ok'=>true,'result'=>(object)['chat'=>(object)['id'=>9001],'message_id'=>0]]
] as $case=>$bad) {
    $ref='server:'.(200+$case);
    $connection->begin_transaction();stage($ref);$connection->commit();
    $r=reportRow($ref,'9001');$id=(int)$r['id'];$reply=$bad;$n=count($events);
    ensure(achbotFollowupDispatchOne($connection,$id)==='unknown' &&
        achbotFollowupDispatchOne($connection,$id)==='unknown' && count($events)===$n+1 &&
        row("SELECT ack_chat_id,ack_message_id FROM payment_followup_attempts WHERE intent_id=".$id)===
            ['ack_chat_id'=>null,'ack_message_id'=>null],
        'unproven_message_identity_is_unknown_no_automatic_resend_'.$case);
    $r=reportRow($ref,'9001');$token=achbotFollowupRedriveToken($r,9001);
    $reply='ack';
    ensure(achbotFollowupDispatchOne($connection,$id,9001,$token)==='acknowledged' &&
        count($events)===$n+2 &&
        (int)row("SELECT COUNT(*) AS n FROM payment_followup_attempts WHERE intent_id=".$id." AND result='unknown' AND ack_chat_id IS NULL AND ack_message_id IS NULL")['n']===1 &&
        (int)row("SELECT COUNT(*) AS n FROM payment_followup_attempts WHERE intent_id=".$id." AND result='acknowledged' AND ack_chat_id=9001 AND ack_message_id>0")['n']===1,
        'explicit_redrive_pins_new_identity_without_rewriting_unknown_'.$case);
}
$connection->begin_transaction();stage('server:210');$connection->commit();
$r=reportRow('server:210','@fixturereport');$id=(int)$r['id'];$reply=fixtureMessageAck('@differentchannel',7203);
ensure(achbotFollowupDispatchOne($connection,$id)==='unknown' &&
    row("SELECT ack_chat_id FROM payment_followup_attempts WHERE intent_id=".$id)['ack_chat_id']===null,
    'channel_alias_mismatch_cannot_pin_numeric_identity');
$reply='ack';
// The database must reject incomplete or non-acknowledged identity pairs.
$last=(int)row("SELECT MAX(id) AS id FROM payment_followup_attempts")['id'];
foreach (["ack_chat_id=9001,ack_message_id=NULL",
    "ack_chat_id=NULL,ack_message_id=800",
    "ack_chat_id=9001,ack_message_id=800",
    "result='acknowledged',ack_chat_id=0,ack_message_id=800",
    "result='acknowledged',ack_chat_id=9001,ack_message_id=0"] as $case=>$change) {
    $connection->begin_transaction();$denied=false;
    try {$denied=$connection->query("UPDATE payment_followup_attempts SET ".$change." WHERE id=".$last)===false;}
    catch (mysqli_sql_exception $e) {$denied=$e->getCode()===4025;}
    $connection->rollback();
    ensure($denied,'database_rejects_invalid_ack_pair_'.$case);
}
// Missing ACK commit must not yield a duplicate or a manufactured target.
$connection->begin_transaction();stage('server:211');$connection->commit();
$r=reportRow('server:211','9001');$id=(int)$r['id'];$n=count($events);
query("CREATE TRIGGER fixture_ack_commit_error BEFORE UPDATE ON payment_followup_attempts
    FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture lost ACK write'");
try {
    ensure(achbotFollowupDispatchOne($connection,$id)==='unknown' &&
        row("SELECT result,ack_chat_id,ack_message_id FROM payment_followup_attempts WHERE intent_id=".$id)===
            ['result'=>'dispatching','ack_chat_id'=>null,'ack_message_id'=>null] &&
        count($events)===$n+1,'ack_identity_and_result_rollback_together');
} finally {query('DROP TRIGGER fixture_ack_commit_error');}
ensure(achbotFollowupDispatchOne($connection,$id)==='unknown' &&
    achbotFollowupDispatchOne($connection,$id)==='unknown' && count($events)===$n+1 &&
    row("SELECT result,ack_chat_id,ack_message_id FROM payment_followup_attempts WHERE intent_id=".$id)===
        ['result'=>'unknown','ack_chat_id'=>null,'ack_message_id'=>null],
    'interrupted_identity_commit_never_automatically_resends');
$ack=\AchBot\Telegram\Response\messageDeliveryOutcome(fixtureMessageAck(9001,80),'9001',80);
$wrong=\AchBot\Telegram\Response\messageDeliveryOutcome(fixtureMessageAck(9001,81),'9001',80);
$noop=(object)['ok'=>false,'error_code'=>400,'description'=>'Bad Request: message is not modified'];
$edit=\AchBot\Telegram\Response\messageDeliveryOutcome($noop,'9001',80);
$send=\AchBot\Telegram\Response\messageDeliveryOutcome($noop,'9001');
ensure($ack['ack']===['chat_id'=>9001,'message_id'=>80] &&
    $wrong['result']==='unknown' && $wrong['ack']===null &&
    $edit['result']==='acknowledged' && $edit['ack']===$ack['ack'] &&
    $send['result']==='rejected' && $send['ack']===null,
    'only_exact_message_edit_or_definite_numeric_noop_is_acknowledged');
ensure(row('SELECT SUM(wallet) AS balance FROM users')['balance']===$baseWallet &&
    countRows('pays')===0 && countRows('wallet_entries')===0 && countRows('service_operations')===0,
    'all_report_recovery_has_no_financial_or_native_effect');
echo json_encode(['complete'=>true,'cases'=>count($checks),'checks'=>$checks],JSON_THROW_ON_ERROR);
'''
from operational_report_fixture import with_message_ack
PHP=with_message_ack(PHP)

def main():
    p=argparse.ArgumentParser()
    p.add_argument('--run-disposable',action='store_true',required=True)
    p.add_argument('--report',type=Path,required=True)
    args=p.parse_args()
    checks=[]
    for mode in ('strict','off'):
        with owned.owned_database(ROOT/'database/current-schema.sql') as (work,env):
            script=work/'operational-outbox.php';script.write_text(PHP)
            proc=subprocess.run(['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
                '-d','log_errors=1','-d','display_errors=0','-d','error_log=/dev/stderr',
                str(script),str(ROOT),str(work/'mysql.sock'),owned.NAMES[0],mode],
                env=env,capture_output=True,text=True,timeout=35)
            assert proc.returncode==0,(mode,proc.stdout,proc.stderr[-3000:])
            result=json.loads(proc.stdout)
            checks += [mode+':'+name for name in result['checks']]
    report={'complete':True,'status':'PASS','cases':len(checks),'checks':checks,'external_requests':0}
    args.report.write_text(json.dumps(report,ensure_ascii=False)+'\n')
    print(json.dumps(report,ensure_ascii=False))
if __name__=='__main__':main()
