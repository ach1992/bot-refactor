#!/usr/bin/env python3
"""Privileged recipient authority over actual PHP and an owned original-schema DB."""
from pathlib import Path
import argparse,json,subprocess,shutil
import test_access_state as owned

ROOT=Path(__file__).resolve().parents[1]
BASE='a070a47c5796e8244a3fe7fbca21e301977c3dcf'
PRIVATE_BASE='4a28474fdba41e17ebadab29ece1c17d18038437'
MAINTENANCE_BASE='add6948fd0720c0fed454d5c2db2f06d1d5d8b24'
FINANCE_FILES=['src/Finance/ReceiptHelpers.php','src/Finance/AutomaticReceipts.php',
    *['src/Finance/Routes/'+name for name in ['Checkout.php','RenewalCheckout.php','Extensions.php','ManualApproval.php','ManualReceipts.php','WalletRecharge.php']]]
PHP=r'''<?php
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(static function($n,$m){if($n===E_DEPRECATED)return true;throw new ErrorException($m,0,$n);});
$root=$argv[1];$connection=new mysqli('localhost','root','',$argv[3],0,$argv[2]);$connection->set_charset('utf8mb4');
$admin=9001;$from_id=1001;$message_id=7;$special_admins=[9002,9007];$checks=[];$events=[];$messageSequence=700;
require $root.'/tools/source_scan.php';require $root.'/messages/loader.php';require $root.'/src/Admin/Access.php';
require_once $root.'/src/Purchase/Core.php';require_once $root.'/src/Finance/ReceiptHelpers.php';
function yes(bool $ok,string $name):void{if(!$ok)throw new RuntimeException($name);$GLOBALS['checks'][]=$name;}
function bot($method,$data=[]){
 global $events,$connection,$messageSequence;
 $tx=(int)$connection->query('SELECT @@in_transaction')->fetch_row()[0];
 $events[]=['method'=>$method,'data'=>$data,'in_transaction'=>$tx];
 if(isset($GLOBALS['after_send']))($GLOBALS['after_send'])($method,$data);
 if(in_array((int)($data['chat_id']??0),$GLOBALS['fail_targets']??[],true))return null;
 return(object)['ok'=>true,'result'=>(object)['message_id'=>++$messageSequence]];
}
function answerCallbackQuery(...$a){$GLOBALS['events'][]=['method'=>'answerCallbackQuery','data'=>['chat_id'=>$GLOBALS['from_id']]];}
function alert(...$a){$GLOBALS['events'][]=['method'=>'alert','data'=>['chat_id'=>$GLOBALS['from_id']]];}
function loadFacade(string $name):void{
 global $root;
 $body=achbotExtractFunction($root.'/config.php',$name);
 eval(str_replace('__DIR__',var_export($root,true),$body));
}
foreach(['achbotNotifyAdmins','sendToAllAdmins','sendPhoto','sendMessage','editKeys'] as $fn)loadFacade($fn);
require $argv[4];
$connection->query("INSERT INTO users(userid,name,username,refcode,wallet,date,isAdmin,first_start) VALUES
 (9003,'Legacy operator','legacy_operator','0',0,'1700000000',1,'sent'),
 (9004,'Support','support','0',0,'1700000000',1,'sent'),
 (9005,'Manager','manager','0',0,'1700000000',1,'sent'),
 (9006,'Operator','operator','0',0,'1700000000',1,'sent'),
 (9007,'Revoked special','revoked','0',0,'1700000000',0,'sent'),
 (9008,'Duplicate one','dup','0',0,'1700000000',1,'sent'),
 (9008,'Duplicate two','dup','0',0,'1700000000',1,'sent'),
 (9009,'Duplicate roles','dup_roles','0',0,'1700000000',1,'sent'),
 (9010,'Invalid role','invalid','0',0,'1700000000',1,'sent'),
 (9011,'Banned','banned','0',0,'1700000000',1,'sent'),
 (9013,'Malformed notify','notify_bad','0',0,'1700000000',1,'sent'),
 (9014,'Duplicate notify','notify_dup','0',0,'1700000000',1,'sent')");
$connection->query("UPDATE users SET step='banned' WHERE userid=9011");
$connection->query("INSERT INTO setting(type,value) VALUES
 ('ADMIN_ROLE_9004','support'),('ADMIN_ROLE_9005','manager'),('ADMIN_ROLE_9006','operator'),
 ('ADMIN_ROLE_9007','manager'),('ADMIN_ROLE_9009','manager'),('ADMIN_ROLE_9009','operator'),
 ('ADMIN_ROLE_9010','owner'),('ADMIN_ROLE_9011','manager'),('ADMIN_ROLE_9012','manager'),
 ('ADMIN_NOTIFY_9013','unexpected'),('ADMIN_NOTIFY_9014','on'),('ADMIN_NOTIFY_9014','off')");
require $argv[6];require $argv[7];
$roleRows=$connection->query("SELECT * FROM setting WHERE type LIKE 'ADMIN_ROLE_%' ORDER BY id")->fetch_all(MYSQLI_ASSOC);
yes(achbotAdminLevel(9002)==='operator'&&!achbotAdminCan('bot_settings',9002),'roleless staff stays Operator despite obsolete special array');
yes(achbotAdminLevel(9005)==='manager'&&achbotAdminCan('bot_settings',9005),'valid explicit Manager level preserved');
yes(archivedStaffAdminLevel(9002)==='manager','archived private special fallback RED infers Manager');
$special_admins=[9004,9007,9010];$admins_list=[];
yes(achbotAdminLevel(9002)==='operator'&&achbotAdminLevel(9004)==='support'&&achbotAdminLevel(9007)==='none','obsolete arrays neither grant nor restrict current levels');
yes($connection->query("SELECT * FROM setting WHERE type LIKE 'ADMIN_ROLE_%' ORDER BY id")->fetch_all(MYSQLI_ASSOC)===$roleRows,'obsolete private arrays are never imported or written to settings');
$allowed=[9002,9003,9004,9005,9006];$excluded=[9007,9008,9009,9010,9011,9012,9013,9014];
$admins_list=[9001,'9001',...$allowed,...$excluded,-1001,'@group','9002suffix',true];
$markup='{"inline_keyboard":[[{"text":"تأیید","callback_data":"approvePaymentfanout"}]]}';
$text='پیام خصوصی <b>مشتری</b> با داده خام & PRICE';$photo=['photo'=>'synthetic-file','caption'=>$text,'reply_markup'=>$markup,'parse_mode'=>'HTML'];
$targets=static fn()=>array_map(static fn($e)=>(int)$e['data']['chat_id'],$GLOBALS['events']);
$payloads=static function(array $expected,string $name,?array $recipients=null)use($allowed):void{
 global $events;$recipients=$recipients??$allowed;
 yes(count($events)===count($recipients),$name.' exact permitted copies');
 foreach($events as $event){$want=$expected;$want['chat_id']=$event['data']['chat_id'];$actual=$event['data'];ksort($actual);ksort($want);yes($actual===$want,$name.' raw payload unchanged '.$event['data']['chat_id']);yes($event['in_transaction']===0,$name.' no new transaction held across delivery '.$event['data']['chat_id']);}
};
foreach(['sendMessage'=>['text'=>$text,'reply_markup'=>$markup,'parse_mode'=>'HTML'],'sendPhoto'=>$photo] as $method=>$data){
 $recipients=$method==='sendPhoto'?$allowed:[9005];$options=$method==='sendPhoto'?['event'=>'manual_review']:[];
 $events=[];sendToAllAdmins($method,$data,null,$options);yes($targets()===$recipients,'current recipient matrix '.$method);$payloads($data,$method,$recipients);
 foreach($excluded as $id)yes(!in_array($id,$targets(),true),'excluded recipient gets zero payload '.$method.' '.$id);
}
$events=[];$savedObsolete=$admins_list;$admins_list=[];sendToAllAdmins('sendPhoto',$photo,null,['event'=>'manual_review']);yes($targets()===$allowed,'empty obsolete recipient list does not omit DB staff');
$events=[];archivedPrivateListSendToAllAdmins('sendPhoto',$photo);yes($events===[],'archived private recipient selection RED omits genuine DB staff');
unset($admins_list,$special_admins);$events=[];sendToAllAdmins('sendPhoto',$photo,null,['event'=>'manual_review']);yes($targets()===$allowed,'absent obsolete globals do not alter database-selected delivery');
$admins_list=$savedObsolete;$special_admins=[9002,9007];
$events=[];archivedSendToAllAdmins('sendPhoto',$photo);yes(in_array(9007,$targets(),true)&&in_array(9008,$targets(),true),'archived a070 RED leaks revoked/ambiguous payloads');
$events=[];sendToAllAdmins('sendPhoto',$photo,null,['event'=>'manual_review','reply_to_message_id'=>88,'extra'=>['chat_id'=>9004,'disable_notification'=>true]]);
yes($targets()===$allowed,'extra chat_id cannot redirect to excluded recipient');$payloads($photo+['reply_to_message_id'=>88,'disable_notification'=>true],'options');
$connection->query("INSERT INTO pays(hash_id,user_id,type,price,request_date,state,message_id,chat_id,admins_messages) VALUES('fanoutrefs',1001,'INCREASE_WALLET',100000,UNIX_TIMESTAMP(),'have_sent',51,'9001','[]')");
$events=[];sendToAllAdmins('sendPhoto',$photo,'fanoutrefs',['event'=>'manual_review','extra'=>['chat_id'=>9004]]);
$row=$connection->query("SELECT message_id,chat_id,admins_messages FROM pays WHERE hash_id='fanoutrefs'")->fetch_assoc();$refs=json_decode($row['admins_messages'],true);
yes(array_column($refs,'chat_id')===$targets()&&$targets()===$allowed,'recorded refs equal actual checked outgoing recipients');
yes((int)$row['message_id']===51&&(int)$row['chat_id']===9001,'secondary evidence preserves explicit primary reference');
$savedRecipients=$admins_list;$admins_list=[9002];
foreach([null,'','not valid historical JSON','{"legacy":"untouched"}'] as $index=>$rawHistory){
 $hash='fanouthistory'.$index;$q=$connection->prepare("INSERT INTO pays(hash_id,user_id,type,price,request_date,state,admins_messages) VALUES(?,1001,'INCREASE_WALLET',100000,UNIX_TIMESTAMP(),'have_sent',?)");$q->bind_param('ss',$hash,$rawHistory);$q->execute();$q->close();$events=[];sendToAllAdmins('sendPhoto',$photo,$hash,['event'=>'manual_review']);
 $stored=dbFetchPayByHash($connection,$hash)['admins_messages'];
 if($index<2)yes(array_column(json_decode($stored,true),'chat_id')===$allowed,'null/empty history stores confirmed recipient refs '.$index);
 else yes($stored===$rawHistory,'malformed/non-array historical bytes retained '.$index);
 yes($targets()===$allowed,'history shape never authorizes an extra recipient '.$index);
}
$admins_list=$savedRecipients;
$historical=$row['admins_messages'];$connection->query("UPDATE setting SET value='support' WHERE type='ADMIN_ROLE_9005'");$events=[];sendToAllAdmins('sendMessage',['text'=>$text]);
yes(!in_array(9005,$targets(),true)&&achbotAdminNotifyEnabled($connection,9005,9001),'Support keeps enabled preference but receives no new general report');
$connection->query('UPDATE users SET isAdmin=0 WHERE userid=9002');$events=[];sendToAllAdmins('sendMessage',['text'=>$text]);yes(!in_array(9002,$targets(),true),'revoked legacy special immediately excluded');
yes($connection->query("SELECT admins_messages FROM pays WHERE hash_id='fanoutrefs'")->fetch_assoc()['admins_messages']===$historical,'role changes retain all historical receipt refs');
$priorRefs=[...$refs,['chat_id'=>9004,'message_id'=>600],['chat_id'=>9007,'message_id'=>601]];
$q=$connection->prepare("UPDATE pays SET admins_messages=? WHERE hash_id='fanoutrefs'");$priorJson=json_encode($priorRefs);$q->bind_param('s',$priorJson);$q->execute();$q->close();
$events=[];sendToAllAdmins('sendPhoto',$photo,'fanoutrefs',['event'=>'manual_review']);$merged=json_decode(dbFetchPayByHash($connection,'fanoutrefs')['admins_messages'],true);
yes(array_slice($merged,0,count($priorRefs))===$priorRefs,'later fan-out atomically preserves historical Support/revoked delivery refs');
yes(array_column(array_slice($merged,count($priorRefs)),'chat_id')===$targets(),'history append records only new actual checked deliveries');
$connection->query('UPDATE users SET isAdmin=1 WHERE userid=9002');
$connection->query("UPDATE setting SET value='manager' WHERE type='ADMIN_ROLE_9005'");
$other=new mysqli('localhost','root','',$argv[3],0,$argv[2]);$connection->query('START TRANSACTION WITH CONSISTENT SNAPSHOT');
$connection->query("SELECT value FROM setting WHERE type='ADMIN_NOTIFY_9005'")->fetch_all(MYSQLI_ASSOC);
yes(achbotAdminSetNotify($other,9001,9001,9005,false),'concurrent Owner notification OFF commits');$admins_list=[9005];$events=[];sendToAllAdmins('sendMessage',['text'=>$text]);
yes(!in_array(9005,$targets(),true),'existing RR snapshot cannot retain notification-OFF recipient');$connection->rollback();$other->close();
yes(achbotAdminSetNotify($connection,9001,9001,9005,true),'restore notification ON after stale snapshot proof');
$connection->query("UPDATE setting SET value='manager' WHERE type='ADMIN_ROLE_9005'");
$admins_list=[9002,9006];$events=[];$GLOBALS['after_send']=static function($m,$d)use($connection){if((int)$d['chat_id']===9002)achbotAdminSetNotify($connection,9001,9001,9006,false);};
sendToAllAdmins('sendPhoto',$photo,null,['event'=>'manual_review']);unset($GLOBALS['after_send']);yes($targets()===[9002,9003,9004,9005],'each later recipient rechecked after earlier delivery changes preference');
$connection->query("UPDATE setting SET value='operator' WHERE type='ADMIN_ROLE_9006'");
yes(achbotAdminSetNotify($connection,9001,9001,9006,true),'restore next-recipient notification after mid-delivery proof');

// Receiving a copy is independent of role and never grants action authority.
yes(achbotAdminRecipientAllowed($connection,9004,9001)&&!achbotAdminCan('operations',9004),'Support default ON receives notifications without operations authority');
foreach([9001,9002,9003,9004,9005,9006] as $actor)yes(achbotAdminCan('payments',$actor)&&achbotAdminCan('review',$actor),'same receipt review approve reject permission for every valid tier '.$actor);
yes(achbotAdminCan('payments',9013)&&achbotAdminCan('payments',9014),'invalid notification preference cannot revoke genuine staff payment authority');
$from_id=9004;yes(achbotAdminRequestAllowed('approvePaymentfixture',null,'none')&&achbotAdminRequestAllowed('attentionInbox',null,'none'),'Support receipt/review ingress remains allowed');
foreach(['increaseUserWallet','decreaseUserWallet','createUserWalletDebt','pgResetAccount1','reply_7','npReviewInbox'] as $cb)yes(!achbotAdminRequestAllowed($cb,null,'none'),'notification permission cannot grant independent operation '.$cb);
$from_id=1001;
yes(achbotAdminLevel(9013)==='operator'&&!achbotAdminRecipientAllowed($connection,9013,9001),'malformed notification preference denies otherwise valid staff');
yes(achbotAdminLevel(9014)==='operator'&&!achbotAdminRecipientAllowed($connection,9014,9001),'duplicate notification preference denies otherwise valid staff');
yes(!achbotAdminSetNotify($connection,9001,9001,9014,true),'ambiguous preference cannot be silently replaced');
yes(!archivedStaffAdminRecipientAllowed($connection,9004,9001)&&achbotAdminRecipientAllowed($connection,9004,9001),'archived role-coupled recipient RED wrongly excludes notification-enabled Support');
yes(achbotAdminSetNotify($connection,9001,9001,9005,false),'Owner can disable notification separately from Manager level');
$events=[];sendToAllAdmins('sendPhoto',$photo,null,['event'=>'manual_review']);yes(!in_array(9005,$targets(),true)&&achbotAdminLevel(9005)==='manager','notification OFF suppresses copies without changing level');
yes(archivedStaffAdminRecipientAllowed($connection,9005,9001),'archived recipient RED ignores independent OFF preference');
yes(achbotAdminSetNotify($connection,9001,9001,9005,true),'Owner can restore notification preference');

// Only database membership/levels and the existing Owner UI govern delivery.
$buttonValues=achbotMessages('legacy_buttons');$cancelKey='{}';$removeKeyboard='{}';$callbackId='fixture';$first_name='Fixture';$username='fixture';
foreach(['getAdminsKeys','achbotAdminRoleKeys','setUser','editText'] as $fn)loadFacade($fn);
$connection->query("INSERT INTO users(userid,name,username,refcode,wallet,date,isAdmin,first_start) VALUES(9020,'New staff','new_staff','0',0,'1700000000',0,'sent'),(9021,'Snapshot addition','snapshot_staff','0',0,'1700000000',0,'sent')");
$from_id=$chat_id=$admin;$userInfo=['step'=>'none'];$data='addNewAdmin';$text=null;require $root.'/src/Admin/Roles.php';
yes($connection->query('SELECT step FROM users WHERE userid=9001')->fetch_row()[0]==='addNewAdmin','actual Owner add-admin UI stores the existing prompt');
$data='';$text='9020';$userInfo=['step'=>'addNewAdmin'];require $root.'/src/Admin/Roles.php';
yes((int)$connection->query('SELECT isAdmin FROM users WHERE userid=9020')->fetch_row()[0]===0,'actual UI target alone grants no staff authority');
$data='adminAssign|9020|operator';$text=null;$userInfo=['step'=>'none'];require $root.'/src/Admin/Roles.php';
yes(achbotAdminLevel(9020)==='operator','actual Owner UI adds persisted Operator');
$admins_list=[];$special_admins=[9004,9007];$events=[];sendToAllAdmins('sendPhoto',$photo,null,['event'=>'manual_review']);yes(in_array(9020,$targets(),true),'new DB staff receives next copy without private-list membership');
$data='adminAssign|9020|manager';require $root.'/src/Admin/Roles.php';yes(achbotAdminCan('bot_settings',9020)&&achbotAdminLevel(9020)==='manager','actual Owner UI assigns explicit Manager authority');
$events=[];sendToAllAdmins('sendMessage',['text'=>'پیام آزمایشی']);yes(in_array(9020,$targets(),true),'explicit Manager receives next database-selected delivery');
$data='adminAssign|9020|support';require $root.'/src/Admin/Roles.php';$events=[];sendToAllAdmins('sendMessage',['text'=>'پیام آزمایشی']);yes(!in_array(9020,$targets(),true)&&achbotAdminNotifyEnabled($connection,9020,9001),'level downgrade to Support keeps preference ON but excludes general reports');
// Actual Owner UI edits notification preferences independently of assignment.
$data='adminNotify|9020|off';require $root.'/src/Admin/Roles.php';$events=[];sendToAllAdmins('sendPhoto',$photo,null,['event'=>'manual_review']);
yes(!in_array(9020,$targets(),true)&&achbotAdminLevel(9020)==='support'&&achbotAdminCan('payments',9020),'actual notification OFF suppresses copies but keeps Support receipt authority');
$data='adminAssign|9020|manager';require $root.'/src/Admin/Roles.php';$events=[];sendToAllAdmins('sendPhoto',$photo,null,['event'=>'manual_review']);
yes(!in_array(9020,$targets(),true)&&achbotAdminLevel(9020)==='manager','level change preserves independent OFF preference');
$data='adminAssign|9020|operator';require $root.'/src/Admin/Roles.php';$events=[];sendToAllAdmins('sendPhoto',$photo,null,['event'=>'manual_review']);
yes(!in_array(9020,$targets(),true)&&!achbotAdminNotifyEnabled($connection,9020,9001),'second level change still preserves OFF');
$data='adminNotify|9020|on';require $root.'/src/Admin/Roles.php';$events=[];sendToAllAdmins('sendPhoto',$photo,null,['event'=>'manual_review']);yes(in_array(9020,$targets(),true),'actual Owner toggle ON resumes next delivery');
$notifyButtons=[];foreach(json_decode(getAdminsKeys(),true)['inline_keyboard'] as $row)foreach($row as $button)if(str_starts_with($button['callback_data'],'adminNotify|'))$notifyButtons[]=$button['callback_data'];
yes(in_array('adminNotify|9020|off',$notifyButtons,true)&&!array_filter($notifyButtons,static fn($v)=>str_starts_with($v,'adminNotify|9001|')),'actual list has independent non-owner toggle and no primary Owner toggle');
foreach([9002,9004,9005] as $actor){$from_id=$chat_id=$actor;$data='adminNotify|9020|off';$userInfo=['step'=>'none'];$events=[];require $root.'/src/Admin/Roles.php';yes(achbotAdminNotifyEnabled($connection,9020,9001),'non-owner cannot change notification preferences '.$actor);yes(!achbotAdminSetNotify($connection,$actor,9001,9020,false),'direct setter rejects non-owner '.$actor);}
$from_id=$chat_id=9004;$data='adminNotify|9004|off';require $root.'/src/Admin/Roles.php';yes(achbotAdminNotifyEnabled($connection,9004,9001),'Support cannot toggle own notification preference');
$from_id=9001;$chat_id=-100;$data='adminNotify|9020|off';require $root.'/src/Admin/Roles.php';yes(achbotAdminNotifyEnabled($connection,9020,9001),'Owner group callback cannot change notification preference');
$chat_id=9001;$data='adminNotify|9001|off';require $root.'/src/Admin/Roles.php';yes(!achbotAdminSetNotify($connection,9001,9001,9001,false)&&achbotAdminRecipientAllowed($connection,9001,9001),'mandatory primary Owner delivery cannot be toggled off');
$connection->query("INSERT INTO setting(type,value) VALUES('ADMIN_NOTIFY_9001','off')");yes(achbotAdminRecipientAllowed($connection,9001,9001),'unsupported retained Owner OFF metadata cannot suppress mandatory primary');

$data='adminAssign|9020|operator';require $root.'/src/Admin/Roles.php';$events=[];sendToAllAdmins('sendMessage',['text'=>'پیام آزمایشی']);yes(!in_array(9020,$targets(),true),'actual UI Operator restoration does not admit general reports');
$data='delAdmin9020';require $root.'/src/Admin/Roles.php';$events=[];sendToAllAdmins('sendMessage',['text'=>'پیام آزمایشی']);yes(!in_array(9020,$targets(),true)&&achbotAdminLevel(9020)==='none','actual UI removal stops next delivery despite retained obsolete arrays');yes(achbotAdminNotifyEnabled($connection,9020,9001)&&!achbotAdminRecipientAllowed($connection,9020,9001),'revocation excludes delivery despite retained ON preference');
$data='adminAssign|9001|support';require $root.'/src/Admin/Roles.php';yes(achbotAdminLevel(9001)==='owner','configured Owner remains immutable in actual UI');
$ownerKeys=json_decode(getAdminsKeys(),true);yes(($ownerKeys['inline_keyboard'][0][0]['callback_data']??null)==='wizwizch','configured immutable Owner remains listed');
// A current membership read must see additions absent from the caller's old RR snapshot.
$other=new mysqli('localhost','root','',$argv[3],0,$argv[2]);$connection->query('START TRANSACTION WITH CONSISTENT SNAPSHOT');
$connection->query('SELECT userid FROM users WHERE isAdmin=1')->fetch_all(MYSQLI_ASSOC);
yes(achbotAdminAssignLevel($other,9001,9001,9021,'operator'),'source-real concurrent Owner assignment creates current staff membership');
$candidates=achbotAdminRecipientCandidates($connection,9001);
$oldSnapshotMode=$candidates===[]?'deferred_current_read_conflict':'current_member_observed';
yes($candidates===[] || (in_array('9021',array_map('strval',$candidates),true)&&achbotAdminRecipientAllowed($connection,9021,9001)),'old RR membership either fails closed or observes current addition');
$connection->rollback();$other->close();$events=[];sendToAllAdmins('sendPhoto',$photo,null,['event'=>'manual_review']);yes(in_array(9021,$targets(),true),'next normal delivery includes concurrent DB addition');
$data='delAdmin9021';$userInfo=['step'=>'none'];require $root.'/src/Admin/Roles.php';
// Isolate the original receipt/evidence/replay cases with two operations recipients and one Support
// recipient by removing genuine unused staff through the real UI, never private selection.
foreach([9005,9006] as $target){$data='delAdmin'.$target;require $root.'/src/Admin/Roles.php';}
$from_id=$chat_id=1001;$data='';$text='';$userInfo=['step'=>'none'];$events=[];

// Exercise the actual receipt claim, evidence lease, primary delivery and secondary fan-out.
$connection->query("INSERT INTO setting(type,value) VALUES('BOT_STATES','{\"cartToCartState\":\"on\"}')");
$botState=['cartToCartState'=>'on'];$paymentKeys=['bankAccount'=>'6037999999999999','holderName'=>'Fixture'];
$makePay=static function(string $hash)use($connection):array{$q=$connection->prepare("INSERT INTO pays(hash_id,user_id,type,price,request_date,state) VALUES(?,1001,'INCREASE_WALLET',100000,UNIX_TIMESTAMP(),'pending')");$q->bind_param('s',$hash);$q->execute();$q->close();return dbFetchPayByHash($connection,$hash);};
$pay=$makePay('fanoutreceipt');yes(achbotManualClaim($connection,$pay,'bank_transfer',1001),'actual receipt pins original quote');
$token=achbotManualBeginEvidence($connection,$pay,'bank_transfer',1001,'synthetic-receipt');yes(is_string($token),'actual receipt evidence lease acquired');
$walletBefore=(int)$connection->query('SELECT wallet FROM users WHERE userid=1001')->fetch_row()[0];
$admins_list=[9001,'9001',9002,9003,9004,9007,9008,9009,9010];$events=[];
yes(sendReceiptToAdminsAndUpdatePay($connection,'synthetic-receipt',$text,$markup,9001,'fanoutreceipt',$token),'actual primary receipt delivery/evidence returns success');
yes($targets()===[9001,9002,9003,9004],'actual receipt Owner once plus eligible secondary recipients only');
$receipt=dbFetchPayByHash($connection,'fanoutreceipt');$attempt=$connection->query("SELECT * FROM payment_attempts WHERE order_hash='fanoutreceipt'")->fetch_assoc();
yes($receipt['state']==='have_sent'&&(int)$receipt['chat_id']===9001&&(int)$receipt['message_id']>0&&$attempt['stage']==='review'&&$attempt['review_notice_claim_token']===null,'original primary evidence and claim transition preserved');
$receiptRefs=json_decode($receipt['admins_messages'],true);yes(array_column($receiptRefs,'chat_id')===[9002,9003,9004],'actual receipt records only delivered secondary refs');
yes(achbotManualBeginEvidence($connection,$receipt,'bank_transfer',1001,'synthetic-receipt')===null,'completed evidence cannot acquire a replay lease');
yes((int)$connection->query('SELECT wallet FROM users WHERE userid=1001')->fetch_row()[0]===$walletBefore,'receipt fan-out creates zero wallet effect');
$before=json_encode($receipt);$events=[];
yes(achbotApproveManualWalletTopup($connection,'fanoutreceipt',9001,9001)!==null,'original actual approval remains available');
yes((int)$connection->query('SELECT wallet FROM users WHERE userid=1001')->fetch_row()[0]===$walletBefore+100000,'original approval credits exactly once');
achbotApproveManualWalletTopup($connection,'fanoutreceipt',9001,9001);yes((int)$connection->query('SELECT wallet FROM users WHERE userid=1001')->fetch_row()[0]===$walletBefore+100000,'notification policy preserves original approval replay protection');
$pay=$makePay('supportreceipt');yes(achbotManualClaim($connection,$pay,'bank_transfer',1001),'Support receipt uses original actual quote');$supportToken=achbotManualBeginEvidence($connection,$pay,'bank_transfer',1001,'support-receipt');$events=[];
yes(sendReceiptToAdminsAndUpdatePay($connection,'support-receipt',$text,$markup,9001,'supportreceipt',$supportToken)&&in_array(9004,$targets(),true),'actual production receipt helper includes Support through payments context');
yes(achbotAdminSetNotify($connection,9001,9001,9004,false)&&achbotAdminCan('payments',9004),'notification OFF retains existing receipt approval permission');
$supportWallet=(int)$connection->query('SELECT wallet FROM users WHERE userid=1001')->fetch_row()[0];
yes(achbotApproveManualWalletTopup($connection,'supportreceipt',9004,9001)!==null,'actual Support receipt approval succeeds through payments authority');
achbotApproveManualWalletTopup($connection,'supportreceipt',9004,9001);$supportPay=dbFetchPayByHash($connection,'supportreceipt');
yes((int)$connection->query('SELECT wallet FROM users WHERE userid=1001')->fetch_row()[0]===$supportWallet+100000&&(int)$supportPay['approved_by']===9004,'actual Support receipt replay cannot duplicate credit and retains approving actor');
yes(achbotAdminSetNotify($connection,9001,9001,9004,true),'restore Support notifications after muted approval proof');
$pay=$makePay('fanoutfailure');yes(achbotManualClaim($connection,$pay,'bank_transfer',1001),'failure fixture uses actual quote');$token=achbotManualBeginEvidence($connection,$pay,'bank_transfer',1001,'same-evidence');
$events=[];$GLOBALS['fail_targets']=[9001];yes(!sendReceiptToAdminsAndUpdatePay($connection,'same-evidence',$text,$markup,9001,'fanoutfailure',$token),'primary failure retains original false result');
yes($targets()===[9001]&&dbFetchPayByHash($connection,'fanoutfailure')['state']==='pending','primary failure cannot fan out or approve invoice');
$retry=achbotManualBeginEvidence($connection,$pay,'bank_transfer',1001,'same-evidence');yes(is_string($retry),'primary failure releases lease for identical evidence retry');
yes(achbotManualBeginEvidence($connection,$pay,'bank_transfer',1001,'other-evidence')===null,'evidence replacement remains forbidden');
$GLOBALS['fail_targets']=[9002];$events=[];yes(sendReceiptToAdminsAndUpdatePay($connection,'same-evidence',$text,$markup,9001,'fanoutfailure',$retry),'secondary delivery failure cannot undo successful primary');
$failure=dbFetchPayByHash($connection,'fanoutfailure');yes($failure['state']==='have_sent'&&array_column(json_decode($failure['admins_messages'],true),'chat_id')===[9003,9004],'only successful secondary receipt refs persisted');$GLOBALS['fail_targets']=[];

// Receipt maintenance does not consult a notification preference, including
// malformed preferences, and never treats a stored ref as staff membership.
yes(achbotAdminStoredRecipientAllowed($connection,9013,9001)&&achbotAdminStoredRecipientAllowed($connection,9014,9001),'malformed or duplicate notification preference cannot strand genuine staff receipt keyboards');
foreach([9007,9008,9009,9010,9011,9012,1001] as $invalidRecipient)yes(!achbotAdminStoredRecipientAllowed($connection,$invalidRecipient,9001),'receipt status ignores refs without current valid staff authority '.$invalidRecipient);
require $argv[9];
// Real retained-reference foreach blocks, not replacement routing stubs.
$data='';$textInput='';$text='';$userInfo=['step'=>'none'];require $root.'/src/Finance/Routes/ManualApproval.php';
function storedLoops(string $path):array{
 $source=file_get_contents($path);$tokens=achbotTokens($source);$out=[];
 preg_match_all('/foreach\s*\(\$adminMessages\s+as\s+\$msgInfo\)\s*\{/',$source,$matches,PREG_OFFSET_CAPTURE);
 foreach($matches[0] as [$head,$offset]){foreach($tokens as $i=>$t)if($t[3]>=$offset&&$t[0]==='{'){$end=achbotBodyEnd($tokens,$i);$out[]=substr($source,$offset,$tokens[$end][3]+1-$offset);break;}}
 return $out;
}
$files=['Routes/Checkout.php','Routes/RenewalCheckout.php','Routes/Extensions.php','Routes/ManualApproval.php','Routes/ManualReceipts.php','AutomaticReceipts.php'];
$loopCount=0;$historicalRefs=[['chat_id'=>9001,'message_id'=>100],['chat_id'=>9002,'message_id'=>101],['chat_id'=>9003,'message_id'=>102],['chat_id'=>9004,'message_id'=>103],['chat_id'=>9007,'message_id'=>104],['chat_id'=>9008,'message_id'=>105],['chat_id'=>9009,'message_id'=>106],['chat_id'=>9010,'message_id'=>107],['chat_id'=>9011,'message_id'=>108],['chat_id'=>9012,'message_id'=>109],['chat_id'=>1001,'message_id'=>110]];
$connection->query("UPDATE pays SET admins_messages='".$connection->real_escape_string(json_encode($historicalRefs))."' WHERE hash_id='fanoutrefs'");
$financialBefore=$connection->query('SELECT * FROM pays ORDER BY id')->fetch_all(MYSQLI_ASSOC);
foreach($files as $file)foreach(storedLoops($root.'/src/Finance/'.$file) as $ordinal=>$body){
 $loopCount++;$adminMessages=$historicalRefs;$payInfo=$payParam=['chat_id'=>9001];$deferredAdminKeys=['chat_id'=>9001,'keys'=>$markup];$keys=$markup;$autoServiceLabel='وضعیت تأییدشده';$payId=1;$deliveryIssues=[];$hash_id='fanoutrefs';$events=[];
 eval($body);yes($targets()===[9002,9003,9004],'actual stored-ref loop includes Support payments and excludes forbidden recipients '.$file.' '.$ordinal);
 $currentEvents=$events;$oldBodies=storedLoops($argv[5].'/'.$file);$events=[];eval($oldBodies[$ordinal]);$oldPermitted=array_values(array_filter($events,static fn($e)=>in_array((int)$e['data']['chat_id'],[9002,9003,9004],true)));yes($oldPermitted===$currentEvents,'allowed stored-ref raw markup unchanged from archived source '.$file.' '.$ordinal);$events=$currentEvents;
 foreach($events as $event)yes($event['in_transaction']===0,'stored-ref delivery introduces no transaction '.$file.' '.$ordinal.' '.$event['data']['chat_id']);
 yes(achbotAdminSetNotify($connection,9001,9001,9004,false),'disable Support notification before stored followup '.$file.' '.$ordinal);$events=[];eval($body);yes($targets()===[9002,9003,9004]&&achbotAdminCan('payments',9004),'actual prior receipt status remains current with notifications OFF '.$file.' '.$ordinal);
 $oldMaintenanceBodies=storedLoops($argv[8].'/'.$file);$oldMaintenance=str_replace('achbotAdminRecipientAllowed','archivedNotifyAdminRecipientAllowed',$oldMaintenanceBodies[$ordinal]);$events=[];eval($oldMaintenance);yes($targets()===[9002,9003],'archived notification-coupled status RED strands OFF recipient '.$file.' '.$ordinal);
 $events=[];sendToAllAdmins('sendPhoto',$photo,null,['event'=>'manual_review']);yes($targets()===[9002,9003],'notifications OFF still suppresses only new copies '.$file.' '.$ordinal);
 yes(achbotAdminSetNotify($connection,9001,9001,9004,true),'restore Support notification after stored followup '.$file.' '.$ordinal);
 $connection->query("UPDATE users SET isAdmin=0 WHERE userid=9002");$events=[];eval($body);yes($targets()===[9003,9004],'stored-ref loop observes fresh revocation '.$file.' '.$ordinal);$connection->query('UPDATE users SET isAdmin=1 WHERE userid=9002');
}
yes($loopCount===9,'all nine actual stored-ref consumers covered');
yes($connection->query('SELECT * FROM pays ORDER BY id')->fetch_all(MYSQLI_ASSOC)===$financialBefore,'role-filtered followups preserve every historical pay/ref row');
$legacyLoops=storedLoops($argv[5].'/Routes/ManualReceipts.php');yes(count($legacyLoops)===2,'archived source has actual manual-receipt followups');$adminMessages=$historicalRefs;$payInfo=['chat_id'=>9001];$events=[];eval($legacyLoops[0]);yes(in_array(9007,$targets(),true)&&in_array(9008,$targets(),true),'archived a070 stored-ref RED sends new markup to revoked/ambiguous staff');
echo json_encode(['complete'=>true,'cases'=>count($checks),'checks'=>$checks,'source_real'=>true,'owned_original_schema_mariadb'=>true,'network_calls'=>0,'recipient_matrix'=>true,'notification_recipient_matrix'=>true,'receiving_preference_independent_of_role'=>true,'general_reports_manager_only'=>true,'manual_review_all_staff'=>true,'notification_default_on'=>true,'owner_only_notification_ui'=>true,'notification_preference_preserved'=>true,'uniform_receipt_authority_all_tiers'=>true,'notification_never_grants_or_revokes_actions'=>true,'notification_off_suppresses_new_copies'=>true,'stored_receipt_maintenance_ignores_notification_preference'=>true,'stored_receipt_maintenance_requires_current_staff'=>true,'archived_notification_coupling_red'=>true,'malformed_duplicate_notifications_denied'=>true,'support_receipt_approval_replay'=>true,'support_payment_followups'=>true,'support_operations_still_denied'=>true,'raw_text_photo_markup_preserved'=>true,'persisted_tiers_preserved'=>true,'db_only_staff_authority'=>true,'legacy_roleless_operator'=>true,'obsolete_staff_arrays_ignored'=>true,'explicit_manager_assignment'=>true,'actual_admin_ui_membership_delivery'=>true,'old_snapshot_membership_safe'=>true,'old_snapshot_membership_mode'=>$oldSnapshotMode,'next_normal_delivery_includes_added_staff'=>true,'no_private_bootstrap_import'=>true,'db_only_baseline_red'=>true,'primary_owner_once'=>true,'options_recipient_integrity'=>true,'recorded_refs_match_delivery'=>true,'fresh_role_changes'=>true,'repeatable_read_notification_off_denied'=>true,'no_new_transaction_across_delivery'=>true,'actual_primary_receipt_evidence'=>true,'original_evidence_retry_preserved'=>true,'approval_replay_preserved'=>true,'stored_followup_loops'=>$loopCount,'stored_followup_markup_preserved'=>true,'historical_refs_preserved'=>true,'atomic_history_append'=>true,'malformed_history_preserved'=>true,'archived_fanout_red'=>true,'archived_stored_refs_red'=>true],JSON_THROW_ON_ERROR);
'''

PARALLEL=r'''<?php
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(static function($n,$m){if($n===E_DEPRECATED)return true;throw new ErrorException($m,0,$n);});
$root=$argv[1];$actor=(int)$argv[4];$barrier=$argv[5];$admin=9001;$special_admins=[9002];$admins_list=[$actor];
class AppendBarrierDb extends mysqli {
 public function prepare(string $query):mysqli_stmt|false {
  if(str_starts_with($query,'UPDATE `pays` SET `admins_messages`')){
   file_put_contents($GLOBALS['barrier'].'/'.$GLOBALS['actor'].'.ready','ready');$end=microtime(true)+6;
   while(count(glob($GLOBALS['barrier'].'/*.ready'))<2){if(microtime(true)>$end)throw new RuntimeException('owned append barrier timeout');usleep(10000);}
  }
  return parent::prepare($query);
 }
}
$connection=new AppendBarrierDb('localhost','root','',$argv[3],0,$argv[2]);
require $root.'/src/Admin/Access.php';require $root.'/tools/source_scan.php';
function bot($method,$data){
 if((int)$GLOBALS['connection']->query('SELECT @@in_transaction')->fetch_row()[0]!==0)throw new RuntimeException('unexpected outbound transaction');
 $GLOBALS['sent'][]=['recipient'=>(int)$data['chat_id'],'message_id'=>10000+$GLOBALS['actor']*10+(int)$data['chat_id']];
 return(object)['ok'=>true,'result'=>(object)['message_id'=>10000+$GLOBALS['actor']*10+(int)$data['chat_id']]];
}
eval(achbotExtractFunction($root.'/config.php','sendToAllAdmins'));
sendToAllAdmins('sendPhoto',['photo'=>'parallel-confirmed','caption'=>'پیام آزمایشی'],'fanoutparallel',['event'=>'manual_review']);
echo json_encode($sent,JSON_THROW_ON_ERROR);
'''

def main():
    p=argparse.ArgumentParser();p.add_argument('--run-disposable',action='store_true',required=True);p.add_argument('--report',type=Path);p.add_argument('--finance-ref',help='Optional exact committed finance-worker source dependency');p.add_argument('--catalog-ref',help='Optional exact committed message-catalog dependency');a=p.parse_args()
    with owned.owned_database() as (work,env):
        app=work/'actual-source';app.mkdir()
        for name in ['src','messages']:shutil.copytree(ROOT/name,app/name)
        (app/'tools').mkdir();shutil.copy2(ROOT/'tools/source_scan.php',app/'tools/source_scan.php');shutil.copy2(ROOT/'config.php',app/'config.php')
        if a.catalog_ref:
            if len(a.catalog_ref)!=40 or any(c not in '0123456789abcdef' for c in a.catalog_ref):raise ValueError('catalog-ref must be an exact lowercase commit SHA')
            for name in ['messages/fa/admin.php','messages/fa/user.php']:(app/name).write_bytes(subprocess.check_output(['git','show',a.catalog_ref+':'+name],cwd=ROOT))
        if a.finance_ref:
            if len(a.finance_ref)!=40 or any(c not in '0123456789abcdef' for c in a.finance_ref):raise ValueError('finance-ref must be an exact lowercase commit SHA')
            for name in FINANCE_FILES:(app/name).write_bytes(subprocess.check_output(['git','show',a.finance_ref+':'+name],cwd=ROOT))
        client=['mariadb','--no-defaults','--protocol=SOCKET','--socket='+str(work/'mysql.sock'),'-uroot',owned.NAMES[1]]
        subprocess.run(client,input=(ROOT/'migrations/post-v9/003_payment_attempts.sql').read_bytes(),env=env,capture_output=True,check=True,timeout=15)
        raw=subprocess.check_output(['git','show',BASE+':config.php'],cwd=ROOT,text=True)
        start=raw.index('function sendToAllAdmins(');end=raw.index('\n\n\n$buttons_only_for_special_admins',start)
        old=work/'archived-fanout.php';old.write_text('<?php\n'+raw[start:end].replace('function sendToAllAdmins(','function archivedSendToAllAdmins(',1))
        follow=work/'archived-followups';follow.mkdir()
        for name in ['Routes/Checkout.php','Routes/RenewalCheckout.php','Routes/Extensions.php','Routes/ManualApproval.php','Routes/ManualReceipts.php','AutomaticReceipts.php']:
            file=follow/name;file.parent.mkdir(parents=True,exist_ok=True)
            file.write_bytes(subprocess.check_output(['git','show',BASE+':src/Finance/'+name],cwd=ROOT))
        private_source=subprocess.check_output(['git','show',PRIVATE_BASE+':config.php'],cwd=ROOT,text=True)
        start=private_source.index('function sendToAllAdmins(');end=private_source.index('\n\n\n$buttons_only_for_special_admins',start)
        private_fanout=work/'archived-private-fanout.php';private_fanout.write_text('<?php\n'+private_source[start:end].replace('function sendToAllAdmins(','function archivedPrivateListSendToAllAdmins(',1).replace('achbotAdminRecipientAllowed','archivedStaffAdminRecipientAllowed'))
        private_access=work/'archived-private-access.php';private_access.write_text(subprocess.check_output(['git','show',PRIVATE_BASE+':src/Admin/Access.php'],cwd=ROOT,text=True).replace('achbotAdmin','archivedStaffAdmin'))
        maintenance=work/'archived-muted-maintenance';maintenance.mkdir()
        for name in ['Routes/Checkout.php','Routes/RenewalCheckout.php','Routes/Extensions.php','Routes/ManualApproval.php','Routes/ManualReceipts.php','AutomaticReceipts.php']:
            file=maintenance/name;file.parent.mkdir(parents=True,exist_ok=True)
            file.write_bytes(subprocess.check_output(['git','show',MAINTENANCE_BASE+':src/Finance/'+name],cwd=ROOT))
        old_notify=work/'archived-notify-access.php';old_notify.write_text(subprocess.check_output(['git','show',MAINTENANCE_BASE+':src/Admin/Access.php'],cwd=ROOT,text=True).replace('achbotAdmin','archivedNotifyAdmin'))
        driver=work/'admin-fanout.php';driver.write_text(PHP)
        r=subprocess.run(['php','-d','allow_url_fopen=0','-d','disable_functions=curl_init,curl_exec',str(driver),str(app),str(work/'mysql.sock'),owned.NAMES[1],str(old),str(follow),str(private_fanout),str(private_access),str(maintenance),str(old_notify)],env=env,capture_output=True,text=True,timeout=45)
        if r.returncode or r.stderr:raise RuntimeError(r.stdout[-1000:]+'\n'+r.stderr[-4000:])
        report=json.loads(r.stdout);report['baseline_source']=BASE;report['private_staff_baseline_source']=PRIVATE_BASE;report['finance_source_ref']=a.finance_ref or 'current-source';report['catalog_source_ref']=a.catalog_ref or 'current-source';report['receipt_maintenance_baseline_source']=MAINTENANCE_BASE
        historical=[{'chat_id':9004,'message_id':1},{'chat_id':9007,'message_id':2}]
        sql="INSERT INTO pays(hash_id,user_id,type,price,request_date,state,admins_messages) VALUES('fanoutparallel',1001,'INCREASE_WALLET',100000,UNIX_TIMESTAMP(),'have_sent','"+json.dumps(historical)+"')"
        subprocess.run(client+['-e',sql],env=env,capture_output=True,check=True,timeout=5)
        parallel=work/'parallel.php';parallel.write_text(PARALLEL);barrier=work/'append-barrier';barrier.mkdir()
        children=[subprocess.Popen(['php','-d','allow_url_fopen=0',str(parallel),str(app),str(work/'mysql.sock'),owned.NAMES[1],str(actor),str(barrier)],env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True) for actor in [9002,9003]]
        delivered=[]
        try:
            for child in children:
                out,err=child.communicate(timeout=12)
                if child.returncode or err:raise RuntimeError('owned concurrent append failed '+err[-1000:])
                delivered.extend(json.loads(out))
        finally:
            for child in children:
                if child.poll() is None:child.kill();child.wait(timeout=3)
        raw=subprocess.check_output(client+['--batch','--skip-column-names','-e',"SELECT admins_messages FROM pays WHERE hash_id='fanoutparallel'"],env=env,text=True,timeout=5)
        refs=json.loads(raw)
        assert refs[:2]==historical,refs
        expected={(d['recipient'],d['message_id']) for d in delivered}
        assert {(r['chat_id'],r['message_id']) for r in refs[2:]}==expected and len(refs)==8 and len(expected)==len(delivered)==6,refs
        report['checks']+=['two actual helpers confirm deliveries before concurrent append','atomic append retains both historical Support/revoked refs','atomic append loses neither confirmed concurrent recipient/message ref']
        report['cases']=len(report['checks']);report['concurrent_confirmed_append']=True
    if a.report:a.report.write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps({k:v for k,v in report.items() if k!='checks'}))
if __name__=='__main__':main()
