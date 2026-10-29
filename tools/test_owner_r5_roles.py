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
$root=$argv[1];$connection=new mysqli('localhost','root','',$argv[3],0,$argv[2]);$connection->set_charset('utf8mb4');
$admin=9001;$from_id=9001;$special_admins=[9002];$data='';$text='';$userInfo=['step'=>'none'];$checks=[];
require $root.'/messages/loader.php';require $root.'/src/Admin/Access.php';
$buttonValues=achbotMessages('legacy_buttons');
$GLOBALS['events']=[];$callbackId='fixture';$message_id=77;$chat_id=9001;$cancelKey='{}';$removeKeyboard='{}';
function sendMessage(...$a){$GLOBALS['events'][]=['kind'=>'send','body'=>$a[0]??'','keys'=>$a[1]??null,'chat'=>$a[3]??$GLOBALS['from_id']];return(object)['ok'=>true];}
function editText(...$a){$GLOBALS['events'][]=['kind'=>'edit','body'=>$a[1]??'','keys'=>$a[2]??null,'chat'=>$a[4]??$GLOBALS['from_id']];return(object)['ok'=>true];}
function answerCallbackQuery(...$a){$GLOBALS['events'][]=['kind'=>'ack','body'=>$a[1]??''];}
function alert(...$a){$GLOBALS['events'][]=['kind'=>'alert','body'=>$a[0]??''];}
function delMessage(...$a){throw new RuntimeException('administrator input deletion forbidden');}

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
foreach($schema as $table=>$ddl)yes(preg_replace('/AUTO_INCREMENT=[0-9]+/','AUTO_INCREMENT=fixture',$connection->query('SHOW CREATE TABLE '.$table)->fetch_row()[1])===$ddl,'original schema preserved '.$table);
echo json_encode(['complete'=>true,'cases'=>count($checks),'checks'=>$checks,'real_disposable_mariadb'=>true,'actual_admin_keyboard'=>true,'financial_boolean_preserved'=>true,'no_network'=>true,'actual_role_management_route'=>true,'actual_readonly_attention_hub'=>true,'baseline_red'=>true],JSON_THROW_ON_ERROR);
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
        driver=work/'r5-roles.php';driver.write_text(PHP)
        r=subprocess.run(['php','-d','disable_functions=curl_init,curl_exec',str(driver),str(app),str(work/'mysql.sock'),owned.NAMES[1],str(baseline)],env=env,capture_output=True,text=True,timeout=45)
        if r.returncode:raise RuntimeError(r.stdout[-1000:]+'\n'+r.stderr[-4000:])
        report=json.loads(r.stdout);report['baseline_source']=BASE
    if a.report:a.report.write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps({k:v for k,v in report.items() if k!='checks'}))
if __name__=='__main__':main()
