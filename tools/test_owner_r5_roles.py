#!/usr/bin/env python3
"""AChBot four staff levels over actual source and owned original-schema MariaDB."""
from pathlib import Path
import argparse,json,subprocess,shutil
import test_access_state as owned
ROOT=Path(__file__).resolve().parents[1]
BASE='64feca4b777a442023676122e315c68ad26e902d'
PHP=r'''<?php
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(static function($n,$m){if($n===E_DEPRECATED)return true;throw new ErrorException($m,0,$n);});
class RoleAuditMysqli extends mysqli {
 public array $writes=[];public array $financialReads=[];public int $transactions=0;
 private function record(string $sql):void {
  if(preg_match('/^\s*(?:INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE)\b/i',$sql))$this->writes[]=$sql;
  if(preg_match('/^\s*(?:SELECT|SHOW)\b/i',$sql)&&preg_match('/\b(?:pays|orders_list|service_effects|payment_attempts|payment_transactions|increase_order|wallet_adjustments|wallet)\b/i',$sql))$this->financialReads[]=$sql;
 }
 public function prepare(string $query):mysqli_stmt|false {$this->record($query);return parent::prepare($query);}
 public function query(string $query,int $mode=MYSQLI_STORE_RESULT):mysqli_result|bool {$this->record($query);return parent::query($query,$mode);}
 public function begin_transaction(int $flags=0,?string $name=null):bool {$this->transactions++;return parent::begin_transaction($flags,$name);}
 public function resetAudit():void {$this->writes=[];$this->financialReads=[];$this->transactions=0;}
}
$root=$argv[1];$connection=new RoleAuditMysqli('localhost','root','',$argv[3],0,$argv[2]);$connection->set_charset('utf8mb4');
$admin=9001;$from_id=9001;$special_admins=[9002];$data='';$text='';$userInfo=['step'=>'none'];$checks=[];
require $root.'/messages/loader.php';require $root.'/src/Admin/Access.php';
$buttonValues=achbotMessages('legacy_buttons');
$GLOBALS['events']=[];$callbackId='fixture';$message_id=77;$chat_id=9001;$cancelKey='{}';$removeKeyboard='{}';
function sendMessage(...$a){$GLOBALS['events'][]=['kind'=>'send','body'=>$a[0]??'','keys'=>$a[1]??null,'chat'=>$a[3]??$GLOBALS['from_id']];return(object)['ok'=>true,'result'=>(object)['message_id'=>888]];}
function editText(...$a){$GLOBALS['events'][]=['kind'=>'edit','body'=>$a[1]??'','keys'=>$a[2]??null,'chat'=>$a[4]??$GLOBALS['from_id']];return(object)['ok'=>true];}
function sendPhoto(...$a){$GLOBALS['events'][]=['kind'=>'photo','file'=>$a[0]??''];return(object)['ok'=>true];}
function answerCallbackQuery(...$a){$GLOBALS['events'][]=['kind'=>'ack','body'=>$a[1]??''];}
function alert(...$a){$GLOBALS['events'][]=['kind'=>'alert','body'=>$a[0]??''];}
function delMessage(...$a){if(!empty($GLOBALS['allow_prompt_delete'])){$GLOBALS['events'][]=['kind'=>'delete'];return;}throw new RuntimeException('administrator input deletion forbidden');}
function editKeys(...$a){$GLOBALS['events'][]=['kind'=>'keys'];}
function sendToAllAdmins(...$a){$GLOBALS['events'][]=['kind'=>'staff_notice'];}
function bot(...$a){$GLOBALS['native_attempts'][]=$a[0]??'unknown';throw new RuntimeException('unexpected native/Telegram operation');}


function yes(bool $ok,string $label):void {if(!$ok)throw new RuntimeException($label);$GLOBALS['checks'][]=$label;}
$config=file_get_contents($root.'/config.php');$a=strpos($config,'function getAdminKeys(){');$b=strpos($config,"\n/**",$a);eval(substr($config,$a,$b-$a));
$a=strpos($config,'function achbotAdminRoleKeys(');$b=strpos($config,'// BEGIN By ACh',$a);eval(substr($config,$a,$b-$a));
$a=strpos($config,'function setUser(');$b=strpos($config,'function generateRandomString(',$a);eval(substr($config,$a,$b-$a));
$connection->query("INSERT INTO users(userid,name,username,refcode,wallet,date,isAdmin,first_start) VALUES
(9003,'Operator','operator','0',0,'1700000000',1,'sent'),
(9004,'Support','support','0',0,'1700000000',1,'sent'),
(9005,'Future staff','future','0',0,'1700000000',0,'sent')");
$schema=[];foreach(['users','setting'] as $t)$schema[$t]=preg_replace('/AUTO_INCREMENT=[0-9]+/','AUTO_INCREMENT=fixture',$connection->query('SHOW CREATE TABLE '.$t)->fetch_row()[1]);
yes(achbotAdminLevel(9001)==='owner','configured owner needs no admin boolean');
yes(achbotAdminLevel(9002)==='manager','legacy special admin remains manager');
yes(achbotAdminLevel(9003)==='operator','legacy ordinary admin remains operator');
yes(!achbotAdminAssignLevel($connection,9002,9001,9004,'manager'),'manager cannot assign staff');
yes(!achbotAdminAssignLevel($connection,9001,9001,9001,'support'),'owner immutable');
yes(!achbotAdminAssignLevel($connection,9001,9001,9999,'support'),'missing target rejected');
yes(achbotAdminAssignLevel($connection,9001,9001,9004,'support'),'owner assigns support');
yes(achbotAdminLevel(9004)==='support','explicit support role read');
$expected=[
9001=>['botReports','userReports','messageToSpeceficUser','increaseUserWallet','decreaseUserWallet','createMultipleAccounts','giftVolumeAndDay','banUser','unbanUser','manageorderdatabase','serversSetting','categoriesSetting','backplan','discount_codes','mainMenuButtons','ticketsList','message2All','agentsList','pinManageAll','adminsList','attentionInbox','gateWays_Channels','botSettings','mainMenu'],
9002=>['botReports','userReports','messageToSpeceficUser','increaseUserWallet','decreaseUserWallet','createMultipleAccounts','giftVolumeAndDay','banUser','unbanUser','manageorderdatabase','serversSetting','categoriesSetting','backplan','discount_codes','mainMenuButtons','ticketsList','message2All','agentsList','pinManageAll','attentionInbox','gateWays_Channels','botSettings','mainMenu'],
9003=>['userReports','messageToSpeceficUser','increaseUserWallet','decreaseUserWallet','createMultipleAccounts','giftVolumeAndDay','banUser','unbanUser','manageorderdatabase','discount_codes','mainMenuButtons','ticketsList','message2All','agentsList','pinManageAll','attentionInbox','mainMenu'],
9004=>['userReports','banUser','unbanUser','manageorderdatabase','mainMenu'],
];
foreach($expected as $actor=>$callbacks){$from_id=$actor;$keys=json_decode(getAdminKeys(),true);$actual=[];foreach($keys['inline_keyboard'] as $row)foreach($row as $button)$actual[]=$button['callback_data'];yes($actual===$callbacks,'actual exact admin keyboard '.$actor);}

// A pre-existing repeatable-read snapshot must not preserve a revoked tier.
yes(achbotAdminAssignLevel($connection,9001,9001,9002,'manager'),'seed explicit manager for current-read proof');
$connection->query('START TRANSACTION WITH CONSISTENT SNAPSHOT');
$connection->query("SELECT value FROM setting WHERE type='ADMIN_ROLE_9002'")->fetch_all(MYSQLI_ASSOC);
$other=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
yes(achbotAdminAssignLevel($other,9001,9001,9002,'support'),'concurrent role downgrade commits');
yes(!achbotAdminCanLocked($connection,9002,9001,'servers'),'locked actor role lookup ignores old repeatable-read snapshot');
$connection->rollback();
yes(achbotAdminAssignLevel($other,9001,9001,9002,'manager'),'restore manager after current-read proof');$other->close();
require $root.'/src/Admin/ManagementFlow.php';require $root.'/src/Subscription/ServerDisplaySettings.php';require $root.'/src/Panel/ProviderAdmin.php';require_once $root.'/src/Subscription/DirectGift.php';
foreach([9001,9002,9003,9004] as $actor){
 $from_id=$actor;$allowed=in_array($actor,[9001,9002],true);
 foreach(['management','display','provider'] as $kind){
  $ok=false;$connection->begin_transaction();
  try{if($kind==='management')achbotManagementActor($connection,$actor,9001);
      elseif($kind==='display')achbotServerDisplayActor($connection,$actor,9001,true);
      else achbotPgAdminActor($connection,'none',true);
      $ok=true;
  }catch(RuntimeException $e){}finally{$connection->rollback();}
  yes($ok===$allowed,'direct current server actor enforces tier '.$actor.' '.$kind);
 }
 $pks=[];foreach($connection->query('SELECT id,userid FROM users WHERE userid IN (9001,'.(int)$actor.')') as $row)$pks[(int)$row['userid']]=(int)$row['id'];
 $ok=false;$connection->begin_transaction();try{achbotDirectGiftActorLocked($connection,$actor,9001,$pks);$ok=true;}catch(RuntimeException $e){}finally{$connection->rollback();}
 yes($ok===($actor!==9004),'direct gift actor follows operations tier '.$actor);
}
$from_id=1001;yes(achbotAdminRequestAllowed('agencySettings',null,'none'),'ordinary agent customer agency menu stays public');
$from_id=$chat_id=9001;$data='adminsList';$text=null;$userInfo=['step'=>'none'];$GLOBALS['events']=[];
require $root.'/src/Admin/Roles.php';
$adminRows=json_decode(end($GLOBALS['events'])['keys'],true)['inline_keyboard'];
yes(($adminRows[0][0]['callback_data']??null)==='wizwizch' && str_contains($adminRows[0][0]['text'],'9001'),'configured immutable owner shown in actual staff list');
foreach($adminRows as $row)foreach($row as $button)yes(!in_array($button['callback_data'],['delAdmin9001','adminRole|9001'],true),'owner has no mutable staff control');
$data='addNewAdmin';$GLOBALS['events']=[];require $root.'/src/Admin/Roles.php';
yes($connection->query('SELECT step FROM users WHERE userid=9001')->fetch_assoc()['step']==='addNewAdmin','actual add staff prompt stores simple step');
$data='';$text='9005';$userInfo=['step'=>'addNewAdmin'];require $root.'/src/Admin/Roles.php';
$picker=json_decode(end($GLOBALS['events'])['keys'],true)['inline_keyboard'];$pickerCallbacks=[];foreach($picker as $row)foreach($row as $button)$pickerCallbacks[]=$button['callback_data'];
yes($pickerCallbacks===['adminAssign|9005|manager','adminAssign|9005|operator','adminAssign|9005|support','adminsList'],'actual existing staff UI offers all three levels');
yes((int)$connection->query('SELECT isAdmin FROM users WHERE userid=9005')->fetch_assoc()['isAdmin']===0,'target input alone does not grant role');
$data='adminAssign|9005|support';$text=null;$userInfo=['step'=>'none'];require $root.'/src/Admin/Roles.php';
yes(achbotAdminLevel(9005)==='support','actual role choice assigns support');
$data='adminAssign|9005|manager';$from_id=$chat_id=9002;$GLOBALS['events']=[];require $root.'/src/Admin/Roles.php';
yes(achbotAdminLevel(9005)==='support' && end($GLOBALS['events'])['kind']==='ack','manager forged role assignment denied without mutation');
$from_id=9001;$chat_id=-100;$GLOBALS['events']=[];require $root.'/src/Admin/Roles.php';
yes(achbotAdminLevel(9005)==='support','owner group callback cannot change staff');
$chat_id=9001;$data='delAdmin9001';$GLOBALS['events']=[];require $root.'/src/Admin/Roles.php';
yes(achbotAdminLevel(9001)==='owner','actual delete owner callback cannot revoke configured owner');
$data='delAdmin9005';require $root.'/src/Admin/Roles.php';yes(achbotAdminLevel(9005)==='none','actual staff delete revokes marker and role');
$connection->query("INSERT INTO pays(hash_id,user_id,type,price,request_date,state) VALUES('r5-review',1001,'BUY_SUB',1000,1700000000,'processing')");
$attentionPayId=(int)$connection->insert_id;
$readBefore=[];foreach(['pays','users','setting'] as $table)$readBefore[$table]=$connection->query('SELECT * FROM '.$table.' ORDER BY id')->fetch_all(MYSQLI_ASSOC);
foreach([9001,9002,9003] as $actor)foreach(['attentionInbox','attentionCase_'.$attentionPayId] as $cb){
 $from_id=$chat_id=$actor;$data=$cb;$text=null;$userInfo=['step'=>'none'];$GLOBALS['events']=[];
 require $root.'/src/Finance/Routes/ManualReceipts.php';
 $screens=array_values(array_filter($GLOBALS['events'],fn($e)=>in_array($e['kind'],['edit','send'],true)));
 yes(count($screens)===1 && $screens[0]['chat']===$actor,'actual private read-only review delivered to allowed actor '.$actor.' '.$cb);
 $callbacks=[];foreach(json_decode($screens[0]['keys'],true)['inline_keyboard'] as $row)foreach($row as $button)$callbacks[]=$button['callback_data'];
 yes($actor===9001?in_array('npReviewInbox',$callbacks,true):!array_filter($callbacks,fn($c)=>preg_match('/^(npReview|bankReview)/',$c)),'consequential recovery child controls remain owner-only '.$actor.' '.$cb);
}
$from_id=$chat_id=9004;$data='attentionInbox';$GLOBALS['events']=[];require $root.'/src/Finance/Routes/ManualReceipts.php';
yes(count($GLOBALS['events'])===1 && $GLOBALS['events'][0]['kind']==='ack','support actual review route denied');
foreach($readBefore as $table=>$rows)yes($connection->query('SELECT * FROM '.$table.' ORDER BY id')->fetch_all(MYSQLI_ASSOC)===$rows,'attention reads do not mutate '.$table);
require $argv[4];$from_id=9004;$legacy=json_decode(baselineAdminKeys(),true);$oldCallbacks=[];foreach($legacy['inline_keyboard'] as $row)foreach($row as $button)$oldCallbacks[]=$button['callback_data'];
yes(in_array('increaseUserWallet',$oldCallbacks,true),'archived baseline RED support overexposure');
$from_id=9001;$legacy=json_decode(baselineAdminKeys(),true);yes($legacy['inline_keyboard'][0][0]['callback_data']==='attentionInbox','archived baseline RED payment review at wrong position');

$from_id=9004;
foreach(['userReports','userInfoDetails1001','banUser','unbanUser','manageorderdatabase','showUserSubscriptions1001'] as $cb)
 yes(achbotAdminRequestAllowed($cb,null,'none'),'support allowed route '.$cb);
foreach(['botReports','serversSetting','am|abc|save','pgServerGroups1','achbotDisplay|1','categoriesSetting','backplan','botSettings','changeBotbotState','ic|menu','gateWays_Channels','gr|l','attentionInbox','npReviewInbox','bankReviewInbox','adminsList','delAdmin9002','adminAssign|9005|manager','createMultipleAccounts','createAccPlan1','increaseUserWallet','createUserWalletDebt','walletDebtYes:abc','approvePaymentxyz','acceptxyz','declineAction_xyz','toggleAgent_1001','editAgentDiscountNormal1001_userinfo','message2All','ticketsList'] as $cb)
 yes(!achbotAdminRequestAllowed($cb,null,'none'),'support forged callback denied '.$cb);
foreach(['editServerName1','achbotManage|abc','achbotInstance:start_message','achbotPaymentKey|abc','gr|edit|abc','createAccAmount1_1_1','declineActionReason_xyz_1','editAgentDiscountNormal1001_userinfo'] as $step)
 yes(!achbotAdminRequestAllowed(null,'new value',$step),'support stale editor denied '.$step);
yes(!achbotAdminRequestAllowed('botSettings','/start','none'),'callback message start cannot bypass role');
yes(!achbotAdminRequestAllowed('buySubscription',null,'editServerName1'),'public callback cannot execute forbidden pending editor');
yes(achbotAdminRequestAllowed('mainMenu',null,'editServerName1'),'home escapes stale editor');
yes(achbotAdminRequestAllowed(null,'/start','editServerName1'),'fresh start escapes stale editor');
$from_id=9003;
foreach(['botReports','serversSetting','newPlanForServer1','gateWays_Channels','botSettings','adminAssign|9004|support'] as $cb)
 yes(!achbotAdminRequestAllowed($cb,null,'none'),'operator denied '.$cb);
foreach(['attentionInbox','attentionCase_1','userReports','increaseUserWallet','createMultipleAccounts','agentsList'] as $cb)
 yes(achbotAdminRequestAllowed($cb,null,'none'),'operator allowed '.$cb);
yes(!achbotAdminRequestAllowed('npReviewVerify_1',null,'none'),'operator cannot invoke owner financial recovery');
$from_id=9002;yes(!achbotAdminRequestAllowed('adminsList',null,'none'),'manager admin management denied');yes(achbotAdminRequestAllowed('botSettings',null,'none'),'manager bot settings allowed');
yes(achbotAdminAssignLevel($connection,9001,9001,9002,'support'),'owner downgrades same marker');
yes((int)$connection->query('SELECT isAdmin FROM users WHERE userid=9002')->fetch_assoc()['isAdmin']===1,'all levels preserve financial boolean');
yes(!achbotAdminCan('bot_settings',9002),'downgrade immediately defeats stale role cache');
yes(achbotAdminAssignLevel($connection,9001,9001,9002,null),'owner revokes staff');
yes(achbotAdminLevel(9002)==='none','revoked legacy special actor not elevated');
yes(!achbotAdminCan('customers',9002),'revoked cached admin cannot access customer info');
yes(achbotAdminAssignLevel($connection,9001,9001,9005,'operator'),'existing customer can become operator');
$connection->query("INSERT INTO setting(type,value) VALUES('ADMIN_ROLE_9005','manager')");
yes(achbotAdminLevel(9005)==='none','duplicate role fails closed');
yes(!achbotAdminAssignLevel($connection,9001,9001,9005,'support'),'ambiguous role assignment rejected');

yes(achbotAdminAssignLevel($connection,9001,9001,9002,'manager'),'manual route explicit manager fixture');
yes(achbotAdminAssignLevel($connection,9001,9001,9003,'operator'),'manual route explicit operator fixture');
// Evaluate the exact production ingress block, not a module-only bypass.
$botSource=file_get_contents($root.'/bot.php');$guardStart=strpos($botSource,'if (!achbotAdminRequestAllowed(');$guardEnd=strpos($botSource,"\n\nrequire_once",$guardStart);
yes($guardStart!==false&&$guardEnd!==false,'actual production bot ingress block located');
$ingress=substr($botSource,$guardStart,$guardEnd-$guardStart)."\n\$GLOBALS['ingress_admitted']=true;";
$enter=static function()use($ingress):bool{global $data,$text,$userInfo;$GLOBALS['ingress_admitted']=false;eval($ingress);return $GLOBALS['ingress_admitted'];};
$bootstrapStart=strpos($config,"// Enforce the same staff level");$bootstrapEnd=strpos($config,"\n\$supportID",$bootstrapStart);$bootstrapGuard=substr($config,$bootstrapStart,$bootstrapEnd-$bootstrapStart);
yes(str_contains($bootstrapGuard,'achbotAdminRequestAllowed('),'actual config bootstrap uses the same fresh request helper');
define('ACHBOT_VERIFIED_WEBHOOK_ENTRYPOINT',true);
$enterPublic=static function()use($bootstrapGuard,$enter):bool{global $data,$text,$userInfo;eval($bootstrapGuard);return $enter();};
foreach([['getMainKeys','function getAgentKeys('],['RandomString','function generateUID(']] as [$fn,$endMarker]){
 $start=strpos($config,'function '.$fn.'(');$end=strpos($config,$endMarker,$start);eval(substr($config,$start,$end-$start));
}
$start=strpos($config,'function getSupportUrl(');$end=strpos($config,"\nif (isset(\$update",$start);eval(str_replace('__DIR__',var_export($root,true),substr($config,$start,$end-$start)));
$connection->query("INSERT INTO users(userid,name,username,refcode,wallet,date,isAdmin,is_agent,first_start) VALUES(1002,'Agent','agent','0',0,'1700000000',0,1,'sent')");
$botState=['agencyState'=>'on','sellState'=>'on','testAccount'=>'on','sharedExistence'=>'on','individualExistence'=>'on','searchState'=>'on','supportID'=>'fixture_support','nowPaymentWallet'=>'off','zarinpal'=>'off','nextpay'=>'off'];
$mainValues=achbotMessages('legacy_messages');$supportID='fixture_support';$GLOBALS['native_attempts']=[];
$publicMain=[];
foreach([1001,1002] as $actor){
 $from_id=$chat_id=$actor;$userInfo=$connection->query('SELECT * FROM users WHERE userid='.$actor)->fetch_assoc();$userInfo['step']='none';$text=null;
 $menu=json_decode(getMainKeys(),true);$callbacks=[];
 foreach($menu['inline_keyboard'] as $row)foreach($row as $button)if(isset($button['callback_data'])){
  $data=$button['callback_data'];yes($enterPublic(),'actual public main menu survives production ingress '.$actor.' '.$data);$callbacks[]=$data;
 }
 yes(in_array('reciveApplications',$callbacks,true)&&in_array('increaseMyWallet',$callbacks,true),'actual public menu includes app downloads and wallet '.$actor);$publicMain[$actor]=$callbacks;
}
$from_id=$chat_id=1001;$data='reciveApplications';$text=null;$userInfo=$connection->query('SELECT * FROM users WHERE userid=1001')->fetch_assoc();$GLOBALS['events']=[];
yes($enterPublic(),'actual app downloads pass config and bot ingress');
$start=strpos($botSource,"if(\$data == 'reciveApplications') {");$end=strpos($botSource,"\nif (\$text ==",$start);eval(substr($botSource,$start,$end-$start));
yes(count($GLOBALS['events'])===1&&$GLOBALS['events'][0]['kind']==='edit','actual app download route returns its existing screen');
// Source-linked callback and pending-state families cover the full public path.
$publicJourneys=[
 ['buy','src/Purchase/Catalogue.php','selectServer','selectServer1_none','callback'],
 ['buy','src/Purchase/Catalogue.php','selectCategory','selectCategory1_1_none','callback'],
 ['buy','src/Purchase/Catalogue.php','selectPlan','selectPlan1_1_none','callback'],
 ['buy','src/Purchase/Catalogue.php','selectCustomPlanGB','selectCustomPlanGB1_1_none','step'],
 ['buy','src/Purchase/Catalogue.php','selectCustomPlanDay','selectCustomPlanDay1_1_1_none','step'],
 ['buy','src/Purchase/Catalogue.php','enterCustomPlanName','enterCustomPlanName1_1_1_1_none','step'],
 ['buy','src/Purchase/SelectPlan.php','enterAccountName','enterAccountName1_1_none','step'],
 ['bulk','src/Purchase/EntryAndBulk.php','agentMuchBuy','agentMuchBuy','callback'],
 ['bulk','src/Purchase/SelectPlan.php','enterAccountName','enterAccountName1_1_much','step'],
 ['trial','src/Purchase/TrialCatalogue.php','getTestAccount','getTestAccount','callback'],
 ['trial','src/Purchase/TrialCatalogue.php','freeTrial','freeTrial1_normal','callback'],
 ['own_orders','src/Subscription/CustomerRoutes.php','mySubscriptions','mySubscriptions','callback'],
 ['own_orders','src/Subscription/CustomerRoutes.php','agentConfigsList','agentConfigsList','callback'],
 ['own_orders','src/Subscription/CustomerRoutes.php','orderDetails','orderDetails1','callback'],
 ['own_orders','src/Subscription/CustomerRoutes.php','searchMyConfig','searchMyConfig','step'],
 ['own_orders','src/Subscription/CustomerRoutes.php','searchAgentConfig','searchAgentConfig','step'],
 ['own_orders','src/Subscription/CustomerRoutes.php','changeUserConfigState','changeUserConfigState1','callback'],
 ['import','src/Subscription/ExternalAccounts.php','showUUIDLeft','showUUIDLeft','callback'],
 ['import','src/Subscription/ManualAccounts.php','addToOrders','addToOrders1','callback'],
 ['renew','src/Finance/Routes/RenewalCheckout.php','renewAccount','renewAccount1','callback'],
 ['renew','src/Finance/Routes/RenewalCheckout.php','waitRenewCartReceipt','waitRenewCartReceiptfixture','step'],
 ['renew','src/Finance/Routes/RenewalSelection.php','sConfigRenewPlan','sConfigRenewPlan1_1','callback'],
 ['addon','src/Finance/Routes/Extensions.php','selectPlanDayIncrease','selectPlanDayIncrease1_1','callback'],
 ['addon','src/Finance/Routes/Extensions.php','increaseVolumePlan','increaseVolumePlan1_1','callback'],
 ['addon','src/Finance/Routes/Extensions.php','waitIncreaseDayReceipt','waitIncreaseDayReceiptfixture','step'],
 ['wallet','src/Finance/Routes/WalletRecharge.php','increaseWallet_100000','increaseWallet_100000','callback'],
 ['wallet','src/Finance/Routes/WalletRecharge.php','increaseWalletCustom','increaseWalletCustom','callback'],
 ['wallet','src/Finance/WalletStart.php','achbotWalletTopup:','achbotWalletTopup:choose:1:77:1700000000','step'],
 ['wallet','src/Finance/WalletStart.php','achbotWalletTopup:','achbotWalletTopup:amount:1:77:1700000000','step'],
 ['wallet','src/Finance/WalletTransferFlow.php','achbotTransferRecipient:','achbotTransferRecipient:1:77','step'],
 ['wallet','src/Finance/WalletTransferFlow.php','achbotTransferAmount:','achbotTransferAmount:1002:2:78:1:77','step'],
 ['status','src/Finance/Routes/PrePayment.php','ps_','ps_fixture','callback'],
 ['status','src/Finance/Routes/PrePayment.php','ph_','ph_fixture','callback'],
 ['support','src/Support/Entry.php','usersNewTicket','usersNewTicket','callback'],
 ['support','src/Support/CreateTicket.php','newTicket_','newTicket_fixture','step'],
 ['support','src/Support/CreateTicket.php','sendTicket_','sendTicket_fixture','step'],
 ['support','src/Support/CustomerConversation.php','replySupport_','replySupport_1','callback'],
 ['support','src/Support/CustomerConversation.php','sendMsg_','sendMsg_1','step'],
 ['support','src/Support/CustomerConversation.php','closeTicket_','closeTicket_1','callback'],
 ['support','src/Support/CustomerConversation.php','latestMsg_','latestMsg_1','callback'],
 ['support','src/Support/CustomerConversation.php','moreTicket_','moreTicket_all_5','callback'],
 ['agency','src/Agent/Applications.php','requestAgency','requestAgency','callback'],
 ['downloads','bot.php','reciveApplications','reciveApplications','callback'],
 ['agency','config.php','agencySettings','agencySettings','callback'],
];
$publicFamilies=[];$pendingFamilies=[];
foreach($publicJourneys as [$family,$source,$marker,$value,$kind]){
 yes(str_contains(file_get_contents($root.'/'.$source),$marker),'public journey tied to current source '.$family.' '.$value);
 foreach([1001,1002] as $actor){
  $from_id=$chat_id=$actor;$data=$kind==='callback'?$value:'';$text=$kind==='step'?'customer input':null;$userInfo=['step'=>$kind==='step'?$value:'none'];
  yes($enterPublic(),'current public '.$kind.' passes actual ingress '.$actor.' '.$value);
 }
 $publicFamilies[$family]=true;if($kind==='step')$pendingFamilies[$family]=true;
}
// Actual current wallet route after the extracted ingress: fixed amount and
// custom amount become owned SQL drafts; no credit/provider action is performed.
require_once $root.'/src/Finance/ReceiptHelpers.php';require_once $root.'/src/Purchase/Core.php';require_once $root.'/src/Finance/WalletStart.php';
$GLOBALS['allow_prompt_delete']=true;$publicWallet=[];$event=5000;$botUrl='https://fixture.invalid/';
foreach([1001,1002] as $actor)foreach(['increaseWallet_100000','increaseWalletCustom'] as $selection){
 $from_id=$chat_id=$actor;$message_id=77;$data=$selection;$text=null;$event++;
 $stage=achbotWalletTopupStage('choose',$event-1,$message_id);$q=$connection->prepare('UPDATE users SET step=? WHERE userid=?');$q->bind_param('si',$stage,$actor);$q->execute();$q->close();
 $userInfo=$connection->query('SELECT * FROM users WHERE userid='.$actor)->fetch_assoc();
 $update=(object)['update_id'=>$event,'callback_query'=>(object)['id'=>'fixture-'.$event,'from'=>(object)['id'=>$actor,'is_bot'=>false],'message'=>(object)['message_id'=>$message_id,'chat'=>(object)['id'=>$actor,'type'=>'private']],'data'=>$data]];
 yes($enterPublic(),'actual wallet selection passes production ingress '.$actor.' '.$selection);require $root.'/src/Finance/Routes/WalletRecharge.php';
 if($selection==='increaseWalletCustom'){
  $userInfo=$connection->query('SELECT * FROM users WHERE userid='.$actor)->fetch_assoc();
  yes(str_starts_with($userInfo['step'],'achbotWalletTopup:amount:'),'actual custom selection reaches existing amount stage '.$actor);
  $data='';$text='150000';$message_id=889;$event++;
  $update=(object)['update_id'=>$event,'message'=>(object)['message_id'=>$message_id,'from'=>(object)['id'=>$actor,'is_bot'=>false],'chat'=>(object)['id'=>$actor,'type'=>'private'],'text'=>$text]];
  yes($enterPublic(),'actual custom pending amount passes production ingress '.$actor);require $root.'/src/Finance/Routes/WalletRecharge.php';
 }
 $pay=$connection->query("SELECT user_id,price,state FROM pays WHERE user_id=$actor AND type='INCREASE_WALLET' AND state='pending' ORDER BY id DESC LIMIT 1")->fetch_assoc();
 yes($pay&&(int)$pay['user_id']===$actor&&(int)$pay['price']===($selection==='increaseWalletCustom'?150000:100000),'actual admitted public wallet creates correct owned draft '.$actor.' '.$selection);$publicWallet[]=$actor.':'.$selection;
}
$GLOBALS['allow_prompt_delete']=false;$from_id=$chat_id=9004;$supportWalletDenied=[];$walletDeniedTotals=['financial_reads'=>0,'sql_writes'=>0,'transactions'=>0,'native_calls'=>0];
foreach(['increaseUserWallet','decreaseUserWallet','createUserWalletDebt','increaseWalletUser1001','decreaseWalletUser1001','debtWalletUser1001','achbotWalletReason:credit:1001:100:7:77','achbotWalletDebt:1001:100:0:7:77:nonce:reason','walletDebtYes:nonce'] as $stage){
 $data='';$text='100000';$userInfo=['step'=>$stage,'isAdmin'=>true,'userid'=>9004];$connection->resetAudit();
 yes(!$enter(),'Support admin wallet stage rejected at actual ingress '.$stage);
 require $root.'/src/Finance/Routes/AdminWallet.php';
 $walletDeniedTotals['financial_reads']+=count($connection->financialReads);$walletDeniedTotals['sql_writes']+=count($connection->writes);$walletDeniedTotals['transactions']+=$connection->transactions;
 yes($connection->writes===[]&&$connection->financialReads===[]&&$connection->transactions===0,'Support admin wallet stage has no effects '.$stage);$supportWalletDenied[]=$stage;
}

// Shared ticket callbacks retain customer ownership and staff capability.
$first_name='Fixture';$username='fixture';$ticketIds=[];
foreach([1001,1002,9004] as $ticketActor)for($i=0;$i<6;$i++){
 $q=$connection->prepare("INSERT INTO chats(user_id,create_date,title,category,state,rate) VALUES(?,1700000000,?,'fixture',0,0)");$title='owned-'.$ticketActor.'-'.$i;$q->bind_param('is',$ticketActor,$title);$q->execute();$ticketId=(int)$q->insert_id;$q->close();$ticketIds[$ticketActor][]=$ticketId;
 $q=$connection->prepare("INSERT INTO chats_info(chat_id,sent_date,msg_type,text) VALUES(?,1700000000,'USER',?)");$body='private-'.$ticketActor.'-'.$i;$q->bind_param('is',$ticketId,$body);$q->execute();$q->close();
}
$publicTicketActors=[];
foreach([1001,1002,9004] as $actor){
 $from_id=$chat_id=$actor;$data='userAllTickets';$text=null;$userInfo=$connection->query('SELECT * FROM users WHERE userid='.$actor)->fetch_assoc();$GLOBALS['events']=[];
 yes($enterPublic(),'actual customer ticket list passes both ingress guards '.$actor);require $root.'/src/Support/CustomerConversation.php';
 $buttons=[];foreach($GLOBALS['events'] as $ev)if(isset($ev['keys'])&&is_string($ev['keys']))foreach((json_decode($ev['keys'],true)['inline_keyboard']??[]) as $row)foreach($row as $button)if(isset($button['callback_data']))$buttons[]=$button['callback_data'];
 yes(in_array('moreTicket_all_5',$buttons,true)&&in_array('replySupport_'.$ticketIds[$actor][0],$buttons,true),'actual customer list preserves shared callback bytes '.$actor);
 $data='moreTicket_all_5';$GLOBALS['events']=[];yes($enterPublic(),'customer ticket pagination passes both ingress guards '.$actor);require $root.'/src/Support/QueueAndHistory.php';
 $body=json_encode($GLOBALS['events']);yes(str_contains($body,'private-'.$actor.'-5')&&!preg_match('/private-(?!'.(int)$actor.'-)[0-9]+-/',$body),'actual customer pagination reads only own tickets '.$actor);
 yes(str_contains($body,'replySupport_')&&!preg_match('/"callback_data":"reply_[0-9]/',$body),'customer ticket page keeps customer reply callbacks '.$actor);
 $ticket=$ticketIds[$actor][0];$data='latestMsg_'.$ticket;$GLOBALS['events']=[];yes($enterPublic(),'customer history passes both ingress guards '.$actor);require $root.'/src/Support/QueueAndHistory.php';
 yes(str_contains(json_encode($GLOBALS['events']),'private-'.$actor.'-0'),'actual shared history serves owned ticket '.$actor);
 $GLOBALS['allow_prompt_delete']=true;$data='replySupport_'.$ticket;$GLOBALS['events']=[];yes($enterPublic(),'customer reply passes both ingress guards '.$actor);require $root.'/src/Support/CustomerConversation.php';
 yes($connection->query('SELECT step FROM users WHERE userid='.$actor)->fetch_assoc()['step']==='sendMsg_'.$ticket,'actual customer reply stores owned message editor '.$actor);
 $data='';$text='fresh owned reply';$userInfo=$connection->query('SELECT * FROM users WHERE userid='.$actor)->fetch_assoc();$GLOBALS['events']=[];yes($enterPublic(),'customer pending reply passes both ingress guards '.$actor);require $root.'/src/Support/CustomerConversation.php';
 yes((int)$connection->query("SELECT COUNT(*) n FROM chats_info WHERE chat_id=$ticket AND text='fresh owned reply' AND msg_type='USER'")->fetch_assoc()['n']===1,'actual pending customer reply writes only owned ticket '.$actor);
 $GLOBALS['allow_prompt_delete']=false;$data='closeTicket_'.$ticket;$text=null;$userInfo=$connection->query('SELECT * FROM users WHERE userid='.$actor)->fetch_assoc();yes($enterPublic(),'customer close passes both ingress guards '.$actor);require $root.'/src/Support/CustomerConversation.php';require $root.'/src/Support/QueueAndHistory.php';
 yes((int)$connection->query('SELECT state FROM chats WHERE id='.$ticket)->fetch_assoc()['state']===2&&$from_id===$actor&&$admin===9001,'actual customer closes own ticket without replacing actor/owner '.$actor);$data='rate_'.$ticket.'_5';$text=null;$userInfo=$connection->query('SELECT * FROM users WHERE userid='.$actor)->fetch_assoc();yes($enterPublic(),'customer rating passes both ingress guards '.$actor);require $root.'/src/Support/CustomerConversation.php';
 yes((int)$connection->query('SELECT rate FROM chats WHERE id='.$ticket)->fetch_assoc()['rate']===5,'actual customer rating retains full numeric ticket ID '.$actor);$publicTicketActors[]=$actor;
}
$foreignTicket=$ticketIds[1002][1];$ticketDenials=[];
foreach([1001,9004] as $actor)foreach(['replySupport_'.$foreignTicket,'closeTicket_'.$foreignTicket,'latestMsg_'.$foreignTicket,'sendMsg_'.$foreignTicket,'rate_'.$foreignTicket.'_5','replySupport_'.$foreignTicket.'suffix','closeTicket_'.$foreignTicket.'suffix','latestMsg_'.$foreignTicket.'suffix','sendMsg_'.$foreignTicket.'suffix'] as $action){
 $from_id=$chat_id=$actor;$data=str_starts_with($action,'sendMsg_')?'':$action;$text='forged customer text';$userInfo=['step'=>str_starts_with($action,'sendMsg_')?$action:'none','isAdmin'=>true,'userid'=>$actor];$GLOBALS['events']=[];$connection->resetAudit();
 require $root.'/src/Support/CustomerConversation.php';require $root.'/src/Support/QueueAndHistory.php';
 yes($connection->writes===[]&&count(array_filter($GLOBALS['events'],fn($e)=>$e['kind']==='ack'))>0,'actual foreign ticket callback/stale editor denied without write '.$actor.' '.$action);$ticketDenials[]=$actor.':'.$action;
}
foreach([9002,9003] as $actor){
 $ticket=$ticketIds[1002][$actor===9002?2:3];$from_id=$chat_id=$actor;$data='closeTicket_'.$ticket;$text=null;$userInfo=['step'=>'none','isAdmin'=>true,'userid'=>$actor];
 yes($enter(),'Manager/Operator retains staff ticket close '.$actor);require $root.'/src/Support/CustomerConversation.php';require $root.'/src/Support/QueueAndHistory.php';
 yes((int)$connection->query('SELECT state FROM chats WHERE id='.$ticket)->fetch_assoc()['state']===2&&$from_id===$actor&&$admin===9001,'actual Manager/Operator closes foreign ticket with original actor/owner '.$actor);
}
$q=$connection->prepare("INSERT INTO chats_info(chat_id,sent_date,msg_type,text) VALUES(?,1700000001,'USER',?)");$attachment=json_encode(['file_id'=>'synthetic-attachment','caption'=>'fixture']);$q->bind_param('is',$foreignTicket,$attachment);$q->execute();$attachmentId=(int)$q->insert_id;$q->close();
foreach([1001,1002,9002,9003,9004] as $actor){
 $from_id=$chat_id=$actor;$data='';$text='/dlPic'.$attachmentId;$userInfo=['step'=>'none','isAdmin'=>true,'userid'=>$actor];$GLOBALS['events']=[];require $root.'/src/Support/QueueAndHistory.php';
 yes((count(array_filter($GLOBALS['events'],fn($e)=>$e['kind']==='photo'))===1)===in_array($actor,[1002,9002,9003],true),'actual ticket attachment enforces ownership or staff operations '.$actor);
}
yes($GLOBALS['native_attempts']===[],'public wallet and ticket checks make no external calls');
require $argv[8];$from_id=$chat_id=1001;
foreach(['increaseWallet_100000','increaseWalletCustom','reciveApplications','replySupport_1','latestMsg_1'] as $cb)
 yes(!publicBaselineAdminRequestAllowed($cb,null,'none'),'archived 86 checkpoint RED blocks real customer callback '.$cb);

// Actual approval modules must reject Support before any financial query or write.
require_once $root.'/src/Finance/ReceiptHelpers.php';require_once $root.'/src/Purchase/Core.php';
$mainValues=achbotMessages('legacy_messages');$supportID='fixture_support';$botUsername='FixtureBot';$GLOBALS['native_attempts']=[];
$makeReceipt=static function(string $hash,string $type='INCREASE_WALLET')use($connection):void{
 $q=$connection->prepare("INSERT INTO pays(hash_id,description,payid,user_id,type,plan_id,server_id,volume,day,price,request_date,state,message_id,chat_id,admins_messages) VALUES(?,'fixture','',1001,?,0,0,5,3,100,1700000000,'have_sent',111,'9001','[]')");
 $q->bind_param('ss',$hash,$type);$q->execute();$q->close();
};
$serviceHash='r5-approval-proof';$makeReceipt($serviceHash,'BUY_SUB');
$forbiddenTotals=['financial_reads'=>0,'sql_writes'=>0,'transactions'=>0,'native_calls'=>0];$positiveLevels=[];$replayLevels=[];
$approvalRoutes=[
 ['Checkout.php','accCustom'.$serviceHash],
 ['RenewalCheckout.php','approveRenewAcc'.$serviceHash],
 ['Extensions.php','approveIncreaseDay'.$serviceHash],
 ['Extensions.php','approveIncreaseVolume'.$serviceHash],
 ['ManualApproval.php','accept'.$serviceHash],
 ['ManualReceipts.php','approvePayment'.$serviceHash],
 ['ManualReceipts.php','declineAction_'.$serviceHash],
 // Legacy unanchored handlers cannot turn an unclassified prefix into authority.
 ['RenewalCheckout.php','prefixapproveRenewAcc'.$serviceHash],
 ['Extensions.php','prefixapproveIncreaseDay'.$serviceHash],
 ['Extensions.php','prefixapproveIncreaseVolume'.$serviceHash],
];
foreach($approvalRoutes as [$module,$cb]){
 $from_id=$chat_id=9004;$data=$cb;$text='';$userInfo=['step'=>'none','isAdmin'=>true,'userid'=>9004];$GLOBALS['events']=[];
 $beforePay=$connection->query("SELECT * FROM pays WHERE hash_id='$serviceHash'")->fetch_assoc();$wallet=(int)$connection->query('SELECT wallet FROM users WHERE userid=1001')->fetch_assoc()['wallet'];
 $connection->resetAudit();require $root.'/src/Finance/Routes/'.$module;
 $writes=$connection->writes;$reads=$connection->financialReads;$transactions=$connection->transactions;
 $forbiddenTotals['financial_reads']+=count($reads);$forbiddenTotals['sql_writes']+=count($writes);$forbiddenTotals['transactions']+=$transactions;
 yes($writes===[]&&$reads===[]&&$transactions===0,'actual Support approval no financial SQL '.$module.' '.$cb);
 yes($connection->query("SELECT * FROM pays WHERE hash_id='$serviceHash'")->fetch_assoc()===$beforePay&&(int)$connection->query('SELECT wallet FROM users WHERE userid=1001')->fetch_assoc()['wallet']===$wallet,'actual Support approval preserves payment and wallet '.$cb);
}
foreach(['declineActionReason_'.$serviceHash.'_111','approveRenewAcc'.$serviceHash,'accCustom'.$serviceHash,'approveIncreaseDay'.$serviceHash,'approveIncreaseVolume'.$serviceHash] as $step){
 foreach(['a reason','/start'] as $input){
  $from_id=$chat_id=9004;$data='';$text=$input;$userInfo=['step'=>$step,'isAdmin'=>true,'userid'=>9004];$GLOBALS['events']=[];
  $connection->resetAudit();require $root.'/src/Finance/Routes/ManualReceipts.php';
  $forbiddenTotals['financial_reads']+=count($connection->financialReads);$forbiddenTotals['sql_writes']+=count($connection->writes);$forbiddenTotals['transactions']+=$connection->transactions;
  yes($connection->writes===[]&&$connection->financialReads===[]&&$connection->transactions===0,'actual Support stale approval/decline editor no SQL '.$step.' '.$input);
 }
}
yes($GLOBALS['native_attempts']===[],'all forbidden actual approval/editor routes have zero native calls');
$directHash='r5-valid-support-helper';$makeReceipt($directHash);$wallet=(int)$connection->query('SELECT wallet FROM users WHERE userid=1001')->fetch_assoc()['wallet'];$connection->resetAudit();
yes(achbotApproveManualWalletTopup($connection,$directHash,9004,9001)===null,'direct valid wallet approval helper rejects Support');
$forbiddenTotals['financial_reads']+=count($connection->financialReads);$forbiddenTotals['sql_writes']+=count($connection->writes);$forbiddenTotals['transactions']+=$connection->transactions;
yes($connection->writes===[]&&$connection->financialReads===[]&&$connection->transactions===0,'direct Support helper has no financial SQL or transaction');
yes($connection->query("SELECT state FROM pays WHERE hash_id='$directHash'")->fetch_assoc()['state']==='have_sent'&&(int)$connection->query('SELECT wallet FROM users WHERE userid=1001')->fetch_assoc()['wallet']===$wallet,'direct valid Support helper preserves wallet and reviewable receipt');
$forbiddenTotals['native_calls']=count($GLOBALS['native_attempts']);

foreach([9002,9003] as $actor){
 $from_id=$chat_id=$actor;$data='';$text='';$userInfo=['step'=>'none','isAdmin'=>false,'userid'=>$actor];
 foreach(array_slice($approvalRoutes,0,4) as [$module,$cb]){
  $data=$cb;$connection->resetAudit();require $root.'/src/Finance/Routes/'.$module;
  yes(count($connection->financialReads)>0&&$connection->writes===[],'actual Manager/Operator service approval reaches original financial proof '.$actor.' '.$module.' '.$cb);
 }
 $hash='r5-legitimate-topup-'.$actor;$makeReceipt($hash);$from_id=$chat_id=$actor;$data='approvePayment'.$hash;$text='';$userInfo=['step'=>'none','isAdmin'=>false,'userid'=>$actor];
 $wallet=(int)$connection->query('SELECT wallet FROM users WHERE userid=1001')->fetch_assoc()['wallet'];$connection->resetAudit();
 require $root.'/src/Finance/Routes/ManualReceipts.php';
 $approved=$connection->query("SELECT state,approved_by FROM pays WHERE hash_id='$hash'")->fetch_assoc();
 yes($approved['state']==='approved'&&(int)$approved['approved_by']===$actor&&(int)$connection->query('SELECT wallet FROM users WHERE userid=1001')->fetch_assoc()['wallet']===$wallet+100,'actual Manager/Operator approval commits one correct wallet credit '.$actor);
 $positiveLevels[]=achbotAdminLevel($actor);
 require $root.'/src/Finance/Routes/ManualReceipts.php';
 yes((int)$connection->query('SELECT wallet FROM users WHERE userid=1001')->fetch_assoc()['wallet']===$wallet+100,'actual Manager/Operator approval replay cannot double credit '.$actor);$replayLevels[]=achbotAdminLevel($actor);
}
foreach([
 ['src/Plans/Administration.php','planDetails1','none',''],
 ['src/Plans/Administration.php','editDestName1','none',''],
 ['src/Plans/Administration.php','editSpiderX1','none',''],
 ['src/Plans/Administration.php','editFlow1','none',''],
 ['src/Subscription/SubLinkSettings.php','','subLinksInput1','https://example.invalid'],
 ['src/Finance/Routes/DiscountAdministration.php','toggleDiscount1','none',''],
 ['src/Finance/Routes/DiscountAdministration.php','copyHashfixture','none',''],
 ['src/Support/AdminReply.php','reply_1','none',''],
] as [$module,$cb,$step,$input]){
 $from_id=$chat_id=str_contains($module,'Plans/')||str_contains($module,'SubLinkSettings')?9003:9004;
 $data=$cb;$text=$input;$userInfo=['step'=>$step,'isAdmin'=>true,'userid'=>$from_id];$GLOBALS['events']=[];$connection->resetAudit();
 require $root.'/'.$module;
 yes($connection->writes===[]&&$connection->financialReads===[],'excluded actual alias/editor stays denied '.$module.' '.$cb.' '.$step);
}
// Pinned pre-correction source proves the actual missing-family and stale /start
// defects, not merely a synthetic policy predicate. Every effect is disposable.
require $argv[7];$from_id=$chat_id=9004;$data='accCustom'.$serviceHash;$text='';$userInfo=['step'=>'none','isAdmin'=>true,'userid'=>9004];$GLOBALS['events']=[];
yes(baselineAdminRequestAllowed($data,null,'none'),'archived checkpoint dispatcher RED admits missing custom-approval family');
$connection->resetAudit();require $argv[5];
yes(count($connection->financialReads)>0&&$connection->transactions>0,'archived actual Checkout RED enters forbidden payment scope');
$hash='r5-decline-escape';$makeReceipt($hash);$from_id=$chat_id=9004;$data='';$text='/start';$userInfo=['step'=>'declineActionReason_'.$hash.'_111','isAdmin'=>true,'userid'=>9004];$GLOBALS['events']=[];$connection->resetAudit();
require $argv[6];
yes($connection->query("SELECT state FROM pays WHERE hash_id='$hash'")->fetch_assoc()['state']==='declined','archived actual decline handler RED mutates from stale Support editor on /start');
yes($GLOBALS['native_attempts']===[],'approval regression makes no external calls even for archived RED');

foreach($schema as $table=>$ddl)yes(preg_replace('/AUTO_INCREMENT=[0-9]+/','AUTO_INCREMENT=fixture',$connection->query('SHOW CREATE TABLE '.$table)->fetch_row()[1])===$ddl,'original schema preserved '.$table);
echo json_encode(['complete'=>true,'cases'=>count($checks),'checks'=>$checks,'real_disposable_mariadb'=>true,'actual_admin_keyboard'=>true,'financial_boolean_preserved'=>true,'no_network'=>true,'actual_role_management_route'=>true,'actual_readonly_attention_hub'=>true,'baseline_red'=>true,'actual_approval_denials'=>true,'manager_operator_approval'=>true,'baseline_approval_callback_red'=>true,'baseline_decline_escape_red'=>true,'approval_family_coverage'=>['accCustom','approveRenewAcc','approveIncreaseDay','approveIncreaseVolume','accept','approvePayment','declineAction','declineActionReason'],'forbidden_financial_effects'=>$forbiddenTotals,'actual_positive_approval_levels'=>$positiveLevels,'actual_replay_protected_levels'=>$replayLevels,'actual_production_bot_ingress'=>true,'actual_config_bootstrap_ingress'=>true,'actual_customer_app_downloads'=>true,'actual_customer_pagination_reply'=>true,'actual_ticket_attachment_authorization'=>true,'forbidden_admin_wallet_effects'=>$walletDeniedTotals,'actual_customer_ticket_actors'=>$publicTicketActors,'actual_foreign_ticket_denials'=>$ticketDenials,'public_callback_collision_baseline_red'=>true,'actual_public_main_menus'=>$publicMain,'public_customer_journey_families'=>array_keys($publicFamilies),'public_pending_stage_families'=>array_keys($pendingFamilies),'actual_customer_wallet_draft_paths'=>$publicWallet,'support_admin_wallet_stages_denied'=>$supportWalletDenied],JSON_THROW_ON_ERROR);
'''
def main():
    p=argparse.ArgumentParser();p.add_argument('--run-disposable',action='store_true',required=True);p.add_argument('--report',type=Path)
    p.add_argument('--catalog-source',type=Path,default=ROOT,help='Optional staged catalog source for parallel batch development')
    a=p.parse_args()
    old=subprocess.check_output(['git','show',BASE+':config.php'],cwd=ROOT,text=True)
    start=old.index('function getAdminKeys(){');end=old.index('\n/**',start)
    menu=old[start:end].replace('function getAdminKeys(', 'function baselineAdminKeys(',1).replace('canSeeButton(','baselineCanSeeButton(')
    start=old.index('$buttons_only_for_special_admins =');end=old.index('\n?>',start)
    policy=old[start:end].replace('function canSeeButton(','function baselineCanSeeButton(',1)
    with owned.owned_database() as (work,env):
        app=work/'app';app.mkdir()
        for name in ['src','settings']:shutil.copytree(ROOT/name,app/name)
        shutil.copytree(a.catalog_source/'messages',app/'messages')
        for name in ['config.php','bot.php','buy_helpers.php']:shutil.copy2(ROOT/name,app/name)
        baseline=work/'archived-current-admin-menu.php';baseline.write_text('<?php\n'+policy+'\n'+menu)
        prior='a901006736a6a75fcac29a609efff3204e1edd6b'
        archived=[]
        for filename in ['Checkout.php','ManualReceipts.php']:
            raw=subprocess.check_output(['git','show',prior+':src/Finance/Routes/'+filename],cwd=ROOT,text=True)
            rebased=raw.replace('__DIR__',json.dumps(str(app/'src/Finance/Routes')))
            path=work/('archived-'+filename);path.write_text(rebased);archived.append(str(path))
        raw=subprocess.check_output(['git','show',prior+':src/Admin/Access.php'],cwd=ROOT,text=True)
        path=work/'archived-access.php';path.write_text(raw.replace('achbotAdmin','baselineAdmin'));archived.append(str(path))
        public_prior='86a1ea4c42d78389b0795700ad83764828b94844'
        raw=subprocess.check_output(['git','show',public_prior+':src/Admin/Access.php'],cwd=ROOT,text=True)
        path=work/'archived-public-access.php';path.write_text(raw.replace('achbotAdmin','publicBaselineAdmin'));archived.append(str(path))
        driver=work/'r5-roles.php';driver.write_text(PHP)
        r=subprocess.run(['php','-d','disable_functions=curl_init,curl_exec',str(driver),str(app),str(work/'mysql.sock'),owned.NAMES[1],str(baseline),*archived],env=env,capture_output=True,text=True,timeout=45)
        if r.returncode:raise RuntimeError(r.stdout[-1000:]+'\n'+r.stderr[-4000:])
        report=json.loads(r.stdout);report['baseline_source']=BASE;report['approval_baseline_source']=prior;report['public_collision_baseline_source']=public_prior
    if a.report:a.report.write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps({k:v for k,v in report.items() if k!='checks'}))
if __name__=='__main__':main()
