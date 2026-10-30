#!/usr/bin/env python3
"""Event-aware staff reports over actual PHP with an owned original-schema DB."""
from pathlib import Path
import argparse,json,shutil,subprocess
import test_access_state as owned

ROOT=Path(__file__).resolve().parents[1]
BASE='68e94b5f90b13cab88835421d83afb78eb573c0d'
EXCLUSIVE_READ_BASE='463c2593e86d717f5fd54886f23d4d54a3606c9c'
PHP=r'''<?php
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(static function($n,$m){if($n===E_DEPRECATED)return true;throw new ErrorException($m,0,$n);});
ini_set('error_log',$argv[4]);
$root=$argv[1];$connection=new mysqli('localhost','root','',$argv[3],0,$argv[2]);$connection->set_charset('utf8mb4');
$admin=9001;$from_id=1001;$message_id=7;$checks=[];$events=[];$sequence=700;
require $root.'/tools/source_scan.php';require $root.'/messages/loader.php';require $root.'/src/Admin/Access.php';
require_once $root.'/src/Purchase/Core.php';require_once $root.'/src/Finance/ReceiptHelpers.php';
function yes(bool $ok,string $name):void{if(!$ok)throw new RuntimeException($name);$GLOBALS['checks'][]=$name;}
function bot($method,$data=[]){
 global $events,$connection,$sequence;
 $events[]=['method'=>$method,'data'=>$data,'tx'=>(int)$connection->query('SELECT @@in_transaction')->fetch_row()[0]];
 if(isset($GLOBALS['after_send']))($GLOBALS['after_send'])($method,$data);
 if(in_array((int)($data['chat_id']??0),$GLOBALS['throw_targets']??[],true))throw new RuntimeException('SYNTHETIC_PRIVATE_MARKER token=fixture-sensitive-text');
 if(in_array((int)($data['chat_id']??0),$GLOBALS['fail_targets']??[],true))return null;
 return(object)['ok'=>true,'result'=>(object)['message_id'=>++$sequence]];
}
function answerCallbackQuery(...$a){}
function alert(...$a){}
foreach(['sendToAllAdmins','sendPhoto','sendMessage','editKeys'] as $fn)
 eval(str_replace('__DIR__',var_export($root,true),achbotExtractFunction($root.'/config.php',$fn)));
if(str_contains(file_get_contents($root.'/config.php'),'function achbotNotifyAdmins('))
 eval(achbotExtractFunction($root.'/config.php','achbotNotifyAdmins'));
$connection->query("INSERT INTO users(userid,name,username,refcode,wallet,date,isAdmin,first_start) VALUES
 (9003,'Manager','manager','0',0,'1700000000',1,'sent'),
 (9004,'Support','support','0',0,'1700000000',1,'sent'),
 (9005,'Manager off','manager_off','0',0,'1700000000',1,'sent'),
 (9006,'Operator off','operator_off','0',0,'1700000000',1,'sent'),
 (9007,'Banned','banned','0',0,'1700000000',1,'sent'),
 (9008,'Duplicate one','dup','0',0,'1700000000',1,'sent'),
 (9008,'Duplicate two','dup','0',0,'1700000000',1,'sent'),
 (9009,'Duplicate role','dup_role','0',0,'1700000000',1,'sent'),
 (9010,'Invalid role','invalid_role','0',0,'1700000000',1,'sent'),
 (9011,'Revoked','revoked','0',0,'1700000000',0,'sent'),
 (9012,'Invalid preference','bad_pref','0',0,'1700000000',1,'sent'),
 (9013,'Duplicate preference','dup_pref','0',0,'1700000000',1,'sent')");
$connection->query("UPDATE users SET step='banned' WHERE userid=9007");
$connection->query("INSERT INTO setting(type,value) VALUES
 ('ADMIN_ROLE_9003','manager'),('ADMIN_ROLE_9004','support'),('ADMIN_ROLE_9005','manager'),
 ('ADMIN_ROLE_9006','operator'),('ADMIN_ROLE_9007','manager'),
 ('ADMIN_ROLE_9009','manager'),('ADMIN_ROLE_9009','support'),('ADMIN_ROLE_9010','owner'),
 ('ADMIN_ROLE_9011','manager'),('ADMIN_ROLE_9012','manager'),('ADMIN_ROLE_9013','manager'),
 ('ADMIN_NOTIFY_9001','off'),('ADMIN_NOTIFY_9005','off'),('ADMIN_NOTIFY_9006','off'),
 ('ADMIN_NOTIFY_9012','invalid'),('ADMIN_NOTIFY_9013','on'),('ADMIN_NOTIFY_9013','off')");
$botState=['rewardChannel'=>-100123];$markup='{"inline_keyboard":[[{"text":"بررسی","callback_data":"approvePaymentfixture"}]]}';
$text='پیام گزارش <b>مشتری</b> & PRICE';$targets=static fn()=>array_map(static fn($e)=>(int)$e['data']['chat_id'],$GLOBALS['events']);
// First assertion also runs against the immutable pre-change source for genuine RED.
notifyAllAdminsSimple($text,$admin,'HTML',$markup,-100123);
yes($targets()===[9001,9003,-100123],'general helper delivers Owner Manager channel, excludes Operator and Support');
foreach($events as $e)yes($e['data']['text']===$text&&$e['data']['reply_markup']===$markup,'general raw body and markup preserved');
$events=[];
// Deterministic overlap holds only owned read transactions. Every lock is
// released before outbound events in this proof; no financial transaction is altered.
require $argv[5];
$connection->query("INSERT INTO setting(type,value) VALUES('ADMIN_NOTIFY_9003','on')");
$rolePk=(int)$connection->query("SELECT id FROM setting WHERE type='ADMIN_ROLE_9003'")->fetch_row()[0];
$notifyPk=(int)$connection->query("SELECT id FROM setting WHERE type='ADMIN_NOTIFY_9003'")->fetch_row()[0];
$left=new mysqli('localhost','root','',$argv[3],0,$argv[2]);$right=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$left->begin_transaction();$right->begin_transaction();$firstDone=$secondDone=false;
$first=archivedExclusiveAdminRecipientCandidates($left,9001,$firstDone);
$second=archivedExclusiveAdminRecipientCandidates($right,9001,$secondDone);
yes($firstDone&&$first!==[]&&!$secondDone&&$second===[],'archived exclusive discovery RED suppresses overlapping current reader');
$left->rollback();$right->rollback();
$left->begin_transaction();$admitted=archivedExclusiveAdminRecipientAllowed($left,9003,9001);$secondDone=false;
$second=archivedExclusiveAdminRecipientCandidates($right,9001,$secondDone);
yes($admitted&&!$secondDone&&$second===[],'archived notification admission RED conflicts with overlapping discovery');
$left->rollback();
$left->begin_transaction();$right->begin_transaction();$firstDone=$secondDone=false;
$first=achbotAdminRecipientCandidates($left,9001,$firstDone);
$second=achbotAdminRecipientCandidates($right,9001,$secondDone);
yes($firstDone&&$secondDone&&$first!==[]&&$first===$second,'overlapping actual current discovery readers both return complete membership');
$left->rollback();$right->rollback();
$left->begin_transaction();$right->begin_transaction();
$one=achbotAdminRecipientAllowed($left,9003,9001);$two=achbotAdminRecipientAllowed($right,9003,9001);$secondDone=false;
$second=achbotAdminRecipientCandidates($right,9001,$secondDone);
yes($one&&$two&&$secondDone&&$second===$first,'overlapping actual role preference admission and discovery remain compatible');
$left->rollback();$right->rollback();
$left->begin_transaction();$left->query('SELECT id FROM users WHERE userid=9003 FOR UPDATE');
$started=microtime(true);$admitted=achbotAdminRecipientAllowed($right,9003,9001);
yes(!$admitted&&microtime(true)-$started<1.0,'notification membership read fails closed without waiting for writer');
$left->rollback();
$left->begin_transaction();$left->query('SELECT id FROM setting WHERE id='.$rolePk.' FOR UPDATE');
$started=microtime(true);$level=achbotAdminLevel(9003,$right,9001,true,true);
yes($level==='none'&&microtime(true)-$started<1.0,'notification role read fails closed without waiting for writer');
$left->rollback();
$left->begin_transaction();$left->query('SELECT id FROM setting WHERE id='.$notifyPk.' FOR UPDATE');
$started=microtime(true);$enabled=achbotAdminNotifyEnabled($right,9003,9001,true,true);
yes(!$enabled&&microtime(true)-$started<1.0,'notification preference read fails closed without waiting for writer');
$left->rollback();
yes(achbotAdminRecipientAllowed($right,9003,9001),'notification admission observes current committed values after writer releases');
$left->begin_transaction();$level=achbotAdminLevel(9003,$left,9001,true);$secondDone=false;
$second=achbotAdminRecipientCandidates($right,9001,$secondDone);
yes($level==='manager'&&!$secondDone&&$second===[],'financial and administrator current-lock default remains exclusive');
$left->rollback();
$left->begin_transaction();$stored=achbotAdminStoredRecipientAllowed($left,9003,9001);$secondDone=false;
$second=achbotAdminRecipientCandidates($right,9001,$secondDone);
yes($stored&&!$secondDone&&$second===[],'stored receipt maintenance lock default remains exclusive');
$left->rollback();
yes((int)$left->query('SELECT @@in_transaction')->fetch_row()[0]===0&&(int)$right->query('SELECT @@in_transaction')->fetch_row()[0]===0&&$events===[],'all overlap proof locks released before any outbound delivery');
$left->close();$right->close();
$connection->query("DELETE FROM setting WHERE id=".$notifyPk);

$events=[];achbotNotifyAdmins('sendMessage',['text'=>$text,'reply_markup'=>$markup],['event'=>'manual_review']);yes($targets()===[9001,9002,9003,9004],'full actionable review preserves Owner and staff without adding a report-channel receipt');
$events=[];sendToAllAdmins('sendMessage',['text'=>$text]);yes($targets()===[9003],'secondary helper defaults to informational Manager-only event');
$events=[];sendToAllAdmins('sendPhoto',['photo'=>'fixture','caption'=>$text],null,['event'=>'manual_review']);
yes($targets()===[9002,9003,9004],'actionable manual receipt reaches valid enabled Operator Manager Support');
foreach([9001,9002,9003,9004,9005,9006] as $id)yes(achbotAdminCan('payments',$id),'notification matrix preserves existing receipt action authority '.$id);
yes(!achbotAdminCan('operations',9004),'Support receives manual review without gaining independent operations');
foreach([9007,9008,9009,9010,9011,9012,9013,1001,-1,'9003bad',true] as $id)
 yes(!achbotAdminNotificationAllowed($connection,$id,9001,'general')&&!achbotAdminNotificationAllowed($connection,$id,9001,'manual_review'),'invalid or suppressed recipient excluded '.json_encode($id));
foreach(['unknown','backup'] as $event){
 $events=[];yes(achbotNotifyAdmins('sendMessage',['text'=>$text],['event'=>$event])===[]&&$events===[],'unsupported full event has no delivery '.$event);
 sendToAllAdmins('sendMessage',['text'=>$text],null,['event'=>$event]);yes($events===[],'unsupported secondary event has no delivery '.$event);
}
$events=[];achbotNotifyAdmins('sendDocument',['document'=>'never']);yes($events===[],'backup document cannot enter generic fanout');
$events=[];achbotNotifyAdmins('sendMessage',['text'=>$text],['primary'=>9004]);yes($events===[],'caller cannot redirect primary identity');
$events=[];achbotNotifyAdmins('sendMessage',['text'=>$text,'chat_id'=>9004],['report_channel'=>'009001']);
yes($targets()===[9001,9003],'primary channel identity deduplicated and payload cannot redirect Owner');
$events=[];achbotNotifyAdmins('sendMessage',['text'=>$text],['report_channel'=>9003]);yes($targets()===[9001,9003],'Manager channel identity deduplicated');
$events=[];achbotNotifyAdmins('sendMessage',['text'=>$text],['report_channel'=>null]);yes($targets()===[9001,9003],'private report adds Managers without exposing channel');
$events=[];achbotNotifyAdmins('sendMessage',['text'=>$text],['report_parse_mode'=>'HTML']);yes(!isset($events[0]['data']['parse_mode'])&&$events[2]['data']['parse_mode']==='HTML','existing per-channel parse mode is preserved');
$events=[];$GLOBALS['fail_targets']=[9001];$notices=achbotNotifyAdmins('sendMessage',['text'=>$text]);
yes($targets()===[9001,9003,-100123]&&$notices[0]['response']===null&&($notices[1]['response']->ok??false),'primary ACK failure remains observable and does not suppress independent copies');$GLOBALS['fail_targets']=[];
$update=(object)['update_id'=>7654321,'private'=>'SYNTHETIC_PRIVATE_MARKER'];
$GLOBALS['throw_targets']=[9003];$events=[];$notices=achbotNotifyAdmins('sendMessage',['text'=>'SYNTHETIC_PRIVATE_MARKER token=fixture-sensitive-text']);
yes($targets()===[9001,9003,-100123]&&($notices[0]['response']->ok??false)&&$notices[1]['response']===null&&($notices[2]['response']->ok??false),'throwing secondary delivery preserves primary ACK and continues to report channel');
$GLOBALS['throw_targets']=[9001];$events=[];$notices=achbotNotifyAdmins('sendMessage',['text'=>'SYNTHETIC_PRIVATE_MARKER token=fixture-sensitive-text']);
yes($targets()===[9001,9003,-100123]&&$notices[0]['response']===null&&($notices[1]['response']->ok??false)&&($notices[2]['response']->ok??false),'throwing primary delivery fails primary ACK and continues every independent copy');$GLOBALS['throw_targets']=[];unset($update);
foreach($events as $event)yes($event['tx']===0,'router holds no transaction across outbound delivery');
$connection->query("INSERT INTO users(userid,name,username,refcode,wallet,date,isAdmin) VALUES(9020,'Later manager','later','0',0,'1700000000',1)");
$connection->query("INSERT INTO setting(type,value) VALUES('ADMIN_ROLE_9020','manager')");
$GLOBALS['after_send']=static function($method,$data)use($connection){if((int)$data['chat_id']===9001)$connection->query("INSERT INTO setting(type,value) VALUES('ADMIN_NOTIFY_9020','off')");};
$events=[];achbotNotifyAdmins('sendMessage',['text'=>$text]);unset($GLOBALS['after_send']);
yes($targets()===[9001,9003,-100123],'recipient preference refreshed after earlier target delivery');
$connection->query("DELETE FROM setting WHERE type='ADMIN_NOTIFY_9020'");
$GLOBALS['after_send']=static function($method,$data)use($connection){if((int)$data['chat_id']===9003)$connection->query("UPDATE setting SET value='support' WHERE type='ADMIN_ROLE_9020'");};
$events=[];achbotNotifyAdmins('sendMessage',['text'=>$text]);unset($GLOBALS['after_send']);
yes($targets()===[9001,9003,-100123]&&achbotAdminLevel(9020)==='support','later recipient role refreshed after candidate list and earlier staff delivery');
$connection->query('UPDATE users SET isAdmin=0 WHERE userid=9020');
// A locked nonstaff customer must not block financial error notification.
$held=new mysqli('localhost','root','',$argv[3],0,$argv[2]);$held->begin_transaction();
$held->query('SELECT id FROM users WHERE userid=1001 FOR UPDATE');
$events=[];$started=microtime(true);$notices=achbotNotifyAdmins('sendMessage',['text'=>$text]);
yes(microtime(true)-$started<1.0&&$targets()===[9001,-100123], 'contended customer row cannot block Owner or channel delivery');
yes(($notices[0]['response']->ok??false)&&count(array_filter($notices,static fn($r)=>($r['deferred']??'')==='recipient_discovery'&&$r['response']===null))===1, 'incomplete recipient discovery remains observable after primary acknowledgement');
$held->rollback();$held->close();$events=[];$notices=achbotNotifyAdmins('sendMessage',['text'=>$text]);
yes($targets()===[9001,9003,-100123]&&!array_filter($notices,static fn($r)=>isset($r['deferred'])), 'next ordinary delivery retries fresh membership after contention clears');
// Discovery can succeed while a role/preference writer makes admission
// unreadable. This is incomplete delivery, distinct from a complete exclusion.
$connection->query("INSERT INTO setting(type,value) VALUES('ADMIN_NOTIFY_9003','on')");
$admissionWriter=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$allAck=static fn(array $rows):bool=>$rows!==[]&&!array_filter($rows,static fn($r)=>!($r['response']->ok??false));
foreach(['role'=>'ADMIN_ROLE_9003','preference'=>'ADMIN_NOTIFY_9003'] as $kind=>$key){
 $pk=(int)$connection->query("SELECT id FROM setting WHERE type='".$key."'")->fetch_row()[0];
 $admissionWriter->begin_transaction();$admissionWriter->query('SELECT id FROM setting WHERE id='.$pk.' FOR UPDATE');
 $membershipComplete=false;$members=achbotAdminRecipientCandidates($connection,9001,$membershipComplete);
 $events=[];$started=microtime(true);$notices=achbotNotifyAdmins('sendMessage',['text'=>$text]);
 yes($membershipComplete&&in_array('9003',array_map('strval',$members),true)&&microtime(true)-$started<1.0&&$targets()===[9001,-100123]&&!array_filter($events,static fn($e)=>$e['tx']!==0),
  'actual router '.$kind.' writer preserves complete discovery and nonblocking independent delivery');
 yes(($notices[0]['response']->ok??false)&&($notices[count($notices)-1]['response']->ok??false)&&!$allAck($notices)&&count(array_filter($notices,static fn($r)=>($r['deferred']??'')==='recipient_discovery'&&$r['response']===null))===1,
  'actual router '.$kind.' admission contention defers aggregate delivery acknowledgement');
 $admissionWriter->rollback();$events=[];$notices=achbotNotifyAdmins('sendMessage',['text'=>$text]);
 yes($targets()===[9001,9003,-100123]&&$allAck($notices)&&!array_filter($notices,static fn($r)=>isset($r['deferred'])),
  'next ordinary router delivery includes Manager after '.$kind.' writer releases');
}
$admissionWriter->close();
$connection->query("UPDATE setting SET value='off' WHERE type='ADMIN_NOTIFY_9003'");
$events=[];$notices=achbotNotifyAdmins('sendMessage',['text'=>$text]);
yes($targets()===[9001,-100123]&&$allAck($notices)&&!array_filter($notices,static fn($r)=>isset($r['deferred'])),'explicit notification OFF is a complete exclusion with successful acknowledgement');
$connection->query("UPDATE setting SET value='on' WHERE type='ADMIN_NOTIFY_9003'");
$connection->query('UPDATE users SET isAdmin=0 WHERE userid=9003');
$events=[];$notices=achbotNotifyAdmins('sendMessage',['text'=>$text]);
yes($targets()===[9001,-100123]&&$allAck($notices)&&!array_filter($notices,static fn($r)=>isset($r['deferred'])),'revoked staff is a complete exclusion with successful acknowledgement');
$connection->query('UPDATE users SET isAdmin=1 WHERE userid=9003');
$connection->query("DELETE FROM setting WHERE type='ADMIN_NOTIFY_9003'");
// Genuine receipt evidence/lease and once-only approval are unchanged.
$connection->query("INSERT INTO setting(type,value) VALUES('BOT_STATES','{\"cartToCartState\":\"on\"}')");
$botState=['cartToCartState'=>'on','rewardChannel'=>-100123];$paymentKeys=['bankAccount'=>'6037999999999999','holderName'=>'Fixture'];
$connection->query("INSERT INTO pays(hash_id,user_id,type,price,request_date,state) VALUES('r6receipt',1001,'INCREASE_WALLET',100000,UNIX_TIMESTAMP(),'pending')");
$pay=dbFetchPayByHash($connection,'r6receipt');
yes(achbotManualClaim($connection,$pay,'bank_transfer',1001),'receipt pins existing original quote');
$token=achbotManualBeginEvidence($connection,$pay,'bank_transfer',1001,'r6-evidence');yes(is_string($token),'receipt acquires original evidence lease');
$events=[];yes(sendReceiptToAdminsAndUpdatePay($connection,'r6-evidence',$text,$markup,9001,'r6receipt',$token),'actual receipt helper preserves primary evidence confirmation');
yes($targets()===[9001,9002,9003,9004],'actual actionable receipt Owner once plus eligible staff, no report channel');
$receipt=dbFetchPayByHash($connection,'r6receipt');
yes(array_column(json_decode($receipt['admins_messages'],true),'chat_id')===[9002,9003,9004],'receipt refs store every successful secondary copy only');
yes(achbotManualBeginEvidence($connection,$receipt,'bank_transfer',1001,'r6-evidence')===null,'receipt lease cannot replay');
yes(achbotAdminSetNotify($connection,9001,9001,9004,false)&&achbotAdminCan('payments',9004),'Support OFF only suppresses new delivery');
$before=(int)$connection->query('SELECT wallet FROM users WHERE userid=1001')->fetch_row()[0];
yes(achbotApproveManualWalletTopup($connection,'r6receipt',9004,9001)!==null,'muted Support retains actual receipt approval');
achbotApproveManualWalletTopup($connection,'r6receipt',9004,9001);
yes((int)$connection->query('SELECT wallet FROM users WHERE userid=1001')->fetch_row()[0]===$before+100000,'receipt approval still credits exactly once');
$events=[];achbotNotifyAdmins('sendMessage',['text'=>$text]);yes($targets()===[9001,9003,-100123],'new automatic success report excludes all Operator Support');
function storedLoops(string $path):array{
 $source=file_get_contents($path);$tokens=achbotTokens($source);$out=[];
 preg_match_all('/foreach\s*\(\$adminMessages\s+as\s+\$msgInfo\)\s*\{/',$source,$matches,PREG_OFFSET_CAPTURE);
 foreach($matches[0] as [$head,$offset])foreach($tokens as $i=>$t)if($t[3]>=$offset&&$t[0]==='{'){
  $end=achbotBodyEnd($tokens,$i);$out[]=substr($source,$offset,$tokens[$end][3]+1-$offset);break;
 }
 return $out;
}
$data='';$textInput='';$text='';$userInfo=['step'=>'none'];require $root.'/src/Finance/Routes/ManualApproval.php';
$historicalRefs=[];foreach([9001,9002,9003,9004,9005,9006,9007,9008,9009,9010,9011,1001] as $id)$historicalRefs[]=['chat_id'=>$id,'message_id'=>$id+100];
$financialBefore=$connection->query('SELECT * FROM pays ORDER BY id')->fetch_all(MYSQLI_ASSOC);$loopCount=0;
foreach(['Routes/Checkout.php','Routes/RenewalCheckout.php','Routes/Extensions.php','Routes/ManualApproval.php','Routes/ManualReceipts.php','AutomaticReceipts.php'] as $file)
 foreach(storedLoops($root.'/src/Finance/'.$file) as $body){
  $loopCount++;$adminMessages=$historicalRefs;$payInfo=$payParam=['chat_id'=>9001];$deferredAdminKeys=['chat_id'=>9001,'keys'=>$markup];
  $keys=$markup;$autoServiceLabel='وضعیت تأییدشده';$payId=1;$deliveryIssues=[];$hash_id='r6receipt';$events=[];
  eval($body);yes($targets()===[9002,9003,9004,9005,9006],'stored receipt converges for current muted staff and excludes revoked invalid '.$file.' '.$loopCount);
  $connection->query('UPDATE users SET isAdmin=0 WHERE userid=9002');$events=[];eval($body);
  yes($targets()===[9003,9004,9005,9006],'stored receipt rechecks current membership '.$file.' '.$loopCount);$connection->query('UPDATE users SET isAdmin=1 WHERE userid=9002');
 }
yes($loopCount===9,'all nine real stored receipt loops exercised');
yes($connection->query('SELECT * FROM pays ORDER BY id')->fetch_all(MYSQLI_ASSOC)===$financialBefore,'receipt convergence preserves historical financial rows');
echo json_encode(['complete'=>true,'cases'=>count($checks),'checks'=>$checks,'owned_original_schema_mariadb'=>true,'network_calls'=>0,'stored_receipt_loops'=>$loopCount],JSON_THROW_ON_ERROR);
'''

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--source-ref')
    parser.add_argument('--report',type=Path)
    a=parser.parse_args()
    if a.source_ref and (len(a.source_ref)!=40 or any(c not in '0123456789abcdef' for c in a.source_ref)):
        raise ValueError('source-ref must be an exact SHA')
    original=subprocess.check_output(['git','show',BASE+':src/Finance/AutomaticReceipts.php'],cwd=ROOT)
    assert (ROOT/'src/Finance/AutomaticReceipts.php').read_bytes()==original,'excluded legacy automatic bank runtime changed'
    current=(ROOT/'settings/rewardReport.php').read_text()
    old=subprocess.check_output(['git','show',BASE+':settings/rewardReport.php'],cwd=ROOT,text=True)
    def backup_block(source):
        return source[source.index('// AChBot backup:'):source.index('// Existing operator-managed Cron runner')]
    assert backup_block(current)==backup_block(old),'backup scheduling/destination changed'
    with owned.owned_database() as (work,env):
        app=work/'app';app.mkdir();shutil.copytree(ROOT/'src',app/'src');shutil.copytree(ROOT/'messages',app/'messages')
        (app/'tools').mkdir();shutil.copy2(ROOT/'tools/source_scan.php',app/'tools/source_scan.php')
        shutil.copy2(ROOT/'config.php',app/'config.php')
        if a.source_ref:
            for name in ['config.php','src/Admin/Access.php','src/Finance/ReceiptHelpers.php']:
                (app/name).write_bytes(subprocess.check_output(['git','show',a.source_ref+':'+name],cwd=ROOT))
        client=['mariadb','--no-defaults','--protocol=SOCKET','--socket='+str(work/'mysql.sock'),'-uroot',owned.NAMES[1]]
        subprocess.run(client,input=(ROOT/'migrations/post-v9/003_payment_attempts.sql').read_bytes(),env=env,capture_output=True,check=True,timeout=15)
        archived=work/'archived-exclusive-access.php'
        archived.write_text(subprocess.check_output(['git','show',EXCLUSIVE_READ_BASE+':src/Admin/Access.php'],cwd=ROOT,text=True).replace('achbotAdmin','archivedExclusiveAdmin'))
        driver=work/'notifications.php';driver.write_text(PHP);notice_log=work/'sanitized-notices.log'
        result=subprocess.run(['php','-d','allow_url_fopen=0','-d','disable_functions=curl_init,curl_exec',str(driver),str(app),str(work/'mysql.sock'),owned.NAMES[1],str(notice_log),str(archived)],env=env,capture_output=True,text=True,timeout=40)
        if result.returncode or result.stderr:
            raise RuntimeError(result.stdout[-1000:]+'\n'+result.stderr[-4000:]+'\n'+(notice_log.read_text()[-4000:] if notice_log.exists() else ''))
        lines=notice_log.read_text().splitlines()
        import re
        assert len(lines)==2 and all(re.search(r'\] achbot_admin_notice_exception event=general method=sendMessage target=(owner|staff) class=RuntimeException update_id=7654321$',line) for line in lines),lines
        assert 'SYNTHETIC_PRIVATE_MARKER' not in '\n'.join(lines) and 'fixture-sensitive-text' not in '\n'.join(lines),lines
        report=json.loads(result.stdout);report['source_ref']=a.source_ref or 'current-source'
        report['checks']+=['actual thrown transport continues to independent targets with sanitized structured metadata','exception diagnostics contain no outgoing payload or raw exception text']
        report['cases']=len(report['checks']);report['sanitized_exception_metadata']=True;report['exception_continuation']=True
        report.update({'concurrent_current_discovery_readers':True,'concurrent_notification_admission_discovery':True,
            'notification_writer_contention_nonblocking':True,'exclusive_financial_admin_lock_defaults_preserved':True,
            'exclusive_stored_receipt_lock_default_preserved':True,'archived_exclusive_reader_red':True,
            'exclusive_reader_baseline_source':EXCLUSIVE_READ_BASE,
            'unreadable_admission_defers_ack':True,'role_preference_writer_deferred_fanout':True,
            'next_ordinary_delivery_after_admission_contention':True,'definitive_exclusion_complete':True})
    if a.report:a.report.write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps({k:v for k,v in report.items() if k!='checks'}))
if __name__=='__main__':main()
