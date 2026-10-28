#!/usr/bin/env python3
"""AChBot common customer service views and read-only catalogue admission."""
from __future__ import annotations
import argparse,hashlib,json,re,subprocess,time
from pathlib import Path
import test_access_state as owned
import test_v6_native as native
import test_v8_native as purchase
ROOT=Path(__file__).resolve().parents[1]
PHP=r'''<?php
error_reporting(E_ALL);
set_error_handler(static function($n,$m){if($n===E_DEPRECATED)return true;throw new ErrorException($m,0,$n);});
$_SERVER['SCRIPT_FILENAME']=__FILE__;
$spec=json_decode($argv[4],true);$root=$argv[1];
require $root.'/messages/loader.php';
require_once $root.'/messages/loader.php';
$mainValues=achbotMessages('legacy_messages');
$buttonValues=achbotMessages('legacy_buttons');
$connection=new mysqli('localhost','root','',$argv[3],0,$argv[2]);$connection->set_charset('utf8mb4');
$from_id=(int)($spec['actor']??1001);$admin=9001;$botUrl='https://bot.example.invalid/';
$q=$connection->prepare('SELECT * FROM users WHERE userid=?');$q->bind_param('i',$from_id);$q->execute();$userInfo=$q->get_result()->fetch_assoc()??['step'=>'none','isAdmin'=>0,'is_agent'=>0,'freetrial'=>null];$q->close();
$botState=array_fill_keys(['renewAutoState','sellState','renewAccountState','switchLocationState','increaseVolumeState','increaseTimeState','renewConfigLinkState','updateConfigLinkState','qrConfigState','qrSubState','subLinkState','configLinkState'],'on');
$botState['plandelkhahState']='off';$botState['agencyPlanDiscount']='off';
$data=$spec['data']??'';$text='';$message_id=1;$cancelKey='{}';$removeKeyboard='{}';$time=time();
$GLOBALS['response_emitted']=false;register_shutdown_function(static function(){if(!$GLOBALS['response_emitted'])echo json_encode(['out'=>$GLOBALS['out']??$GLOBALS['events'],'reads'=>$GLOBALS['reads'],'presentations'=>$GLOBALS['presentations']],JSON_THROW_ON_ERROR);});
$GLOBALS['reads']=0;$GLOBALS['presentations']=0;$GLOBALS['events']=[];
function achbotSupportsAccount($type,$inbound){return in_array($type,['marzban','pasarguard'],true)&&(int)$inbound===0;}
function achbotProviderSqlTypes(){return "'marzban','pasarguard'";}
function achbotProviderUser($server,$remark,$withLinks=true){++$GLOBALS['reads'];if($withLinks)throw new RuntimeException('unexpected_link_read');return json_decode(json_encode($GLOBALS['spec']['remote']??null));}
function achbotProviderRelocationTargets($db,$server){return $GLOBALS['spec']['relocations']??[];}
function achbotOrderPresentation(...$args){
 ++$GLOBALS['presentations'];
 $hide=$GLOBALS['spec']['hidden_links']??false;
 return ['config_html'=>$hide?'':'vless://fixture-config','subscription_html'=>$hide?'':'https://sub.example.invalid/fixture',
  'backup_html'=>'','notice_html'=>'','subscription_url'=>$hide?'':'https://sub.example.invalid/fixture',
  'qr_config_links'=>$hide?[]:['vless://fixture-config']];
}
function buildConfigMessage($state,$remark,$links,$sub,$backup,$type){return $state."\n".$remark."\n".$links."\n".$sub;}
function jdate($format,$stamp){return 'DATE:'.$stamp;}
function bot(...$args){return (object)['result'=>(object)['first_name'=>'Fixture']];}
function alert(...$args){$GLOBALS['events'][]=['alert'=>$args[0]];}
function editText(...$args){$GLOBALS['events'][]=['edit'=>$args[1],'keyboard'=>$args[2]??null];}
function sendMessage(...$args){$GLOBALS['events'][]=['message'=>$args[0],'keyboard'=>$args[1]??null];return (object)['ok'=>true];}
function delMessage(...$args){}
function setUser(...$args){throw new RuntimeException('unexpected_state_write');}
try {
 switch($spec['op']) {
 case 'admission':
  function achbotManualEnabled($method){return in_array($method,$GLOBALS['spec']['manual']??[],true);}
  function achbotManualCryptoEnabled($actor=null){return (bool)array_intersect($GLOBALS['spec']['manual']??[],['manual_usdt','manual_trx']);}
  function achbotPayUxAudienceAllowed($db,$method,$actor,...$rest){return !in_array($method,$GLOBALS['spec']['denied']??[],true);}
  function achbotUsdtRateForUser($db,$actor){return $GLOBALS['spec']['rate']??null;}
  require $root.'/src/Purchase/EntryAndBulk.php';
  $out=achbotCustomerHasPaymentMethod($connection,$from_id,$spec['state']??[]);break;
 case 'catalogue':
  require $root.'/src/Purchase/Catalogue.php';$out=$GLOBALS['events'];break;
 case 'trial':
  require $root.'/src/Purchase/TrialCatalogue.php';$out=$GLOBALS['events'];break;
 default:
  require $root.'/src/Subscription/AccountDetails.php';
  $out=match($spec['op']){
   'traffic'=>\AchBot\Subscription\AccountDetails\getOrderTrafficInfo($spec['provider'],2,'fixture_user','fixture',0),
   'admin'=>\AchBot\Subscription\AccountDetails\getUserOrderDetailKeys(91),
   default=>\AchBot\Subscription\AccountDetails\getOrderDetailKeys(1001,91)
  };
 }
 $GLOBALS['response_emitted']=true;echo json_encode(['out'=>$out,'reads'=>$GLOBALS['reads'],'presentations'=>$GLOBALS['presentations']],JSON_THROW_ON_ERROR);
} catch(Throwable $error){fwrite(STDERR,get_class($error).':'.$error->getMessage());exit(1);}
'''

ORDER_PHP=r'''<?php
error_reporting(E_ALL);
set_error_handler(static function($n,$m){if($n===E_DEPRECATED)return true;throw new ErrorException($m,0,$n);});
$_SERVER['SCRIPT_FILENAME']=__FILE__;
$spec=json_decode($argv[4],true);$root=$argv[1];
require_once $root.'/messages/loader.php';
$mainValues=achbotMessages('legacy_messages');
$buttonValues=achbotMessages('legacy_buttons');
class OrderQueryTrace extends mysqli {
 public function prepare(string $sql): mysqli_stmt|false {
  if(preg_match('/^\s*SELECT\b.*\borders_list\b/is',$sql))++$GLOBALS['order_queries'];
  return parent::prepare($sql);
 }
}
$GLOBALS['order_queries']=0;$GLOBALS['events']=[];$GLOBALS['response_emitted']=false;
register_shutdown_function(static function(){if(!$GLOBALS['response_emitted'])echo json_encode(['events'=>$GLOBALS['events'],'order_queries'=>$GLOBALS['order_queries']],JSON_THROW_ON_ERROR);});
$connection=new OrderQueryTrace('localhost','root','',$argv[3],0,$argv[2]);$connection->set_charset('utf8mb4');
$from_id=(int)($spec['actor']??9001);$admin=9001;$botUrl='https://bot.example.invalid/';
$q=$connection->prepare('SELECT * FROM users WHERE userid=?');$q->bind_param('i',$from_id);$q->execute();$userInfo=$q->get_result()->fetch_assoc()??['step'=>'none','isAdmin'=>0];$q->close();
if(!empty($spec['cached_admin']))$userInfo['isAdmin']=1;
$data=$spec['data']??'';$text=$spec['text']??'';$message_id=1;$cancelKey='{}';$removeKeyboard='REMOVE_KEYBOARD';$botState=['rewardChannel'=>''];$callback_query=['message'=>['chat'=>['id'=>$from_id]]];
function alert(...$args){$GLOBALS['events'][]=['alert'=>$args[0]];}
function editText(...$args){$GLOBALS['events'][]=['edit'=>$args[1],'keyboard'=>$args[2]??null];}
function sendMessage(...$args){$GLOBALS['events'][]=['message'=>$args[0],'keyboard'=>$args[1]??null];return(object)['ok'=>true];}
function jdate($format,$stamp){return 'DATE:'.$stamp;}
function delMessage(...$args){}
function getAdminKeys(){return 'ADMIN_KEYS';}
function getUserInfoKeys(...$args){throw new RuntimeException('unexpected_privileged_subscription_read');}
function setUser($value=null,$field='step'){
 global $connection,$from_id,$userInfo;
 if($field!=='step')throw new RuntimeException('fixture_step_only');
 $step=$value??'none';$q=$connection->prepare('UPDATE users SET step=? WHERE userid=?');$q->bind_param('si',$step,$from_id);$q->execute();$q->close();$userInfo['step']=$step;
}
try{
 require $root.'/src/Subscription/OrderManagement.php';
 if(!empty($spec['direct'])) {
  $q=$connection->query('SELECT * FROM orders_list WHERE id=91');$order=$q->fetch_assoc();showOrderManagementPanel($order);
 }
 $GLOBALS['response_emitted']=true;echo json_encode(['events'=>$GLOBALS['events'],'order_queries'=>$GLOBALS['order_queries']],JSON_THROW_ON_ERROR);
}catch(Throwable$error){fwrite(STDERR,get_class($error).':'.$error->getMessage());exit(1);}
'''

ARCHIVE_PHP=r'''<?php
error_reporting(E_ALL);
set_error_handler(static function($n,$m){if($n===E_DEPRECATED)return true;throw new ErrorException($m,0,$n);});
$spec=json_decode($argv[4],true);$connection=new mysqli('localhost','root','',$argv[3],0,$argv[2]);$connection->set_charset('utf8mb4');
$data='toggleNotif_91';$message_id=1;
function showOrderManagementPanel(...$args){}
'''

def main():
 p=argparse.ArgumentParser();p.add_argument('--run-disposable',action='store_true',required=True);p.add_argument('--report',type=Path);a=p.parse_args()
 checks=[]
 def yes(ok,name):
  if not ok:raise AssertionError(name)
  checks.append(name)
 with owned.owned_database() as (work,env):
  purchase.init(work,env,'http://127.0.0.1:9')
  db=purchase.SIDE[1];script=work/'customer-unified.php';script.write_text(PHP)
  def sql(s):return native.sql(work,env,s,db)
  sql("INSERT INTO orders_list(id,userid,token,transid,fileid,server_id,inbound_id,remark,uuid,protocol,expire_date,link,amount,status,date) VALUES(91,1001,'fixture','','7',2,0,'fixture_user','fixture','vless',1900000000,'[]',100,1,'1700000000');INSERT INTO users(userid,name,username,refcode,wallet,date,isAdmin,first_start,is_agent) VALUES(1002,'Foreign','foreign','0',0,'1700000000',0,'sent',1)")
  def snapshot():return sql("SELECT * FROM users ORDER BY id;SELECT * FROM orders_list ORDER BY id;SELECT * FROM pays ORDER BY id;SELECT * FROM server_info ORDER BY id;SELECT * FROM server_plans ORDER BY id")
  def call(op,**kw):
   before=snapshot()
   r=subprocess.run(['php','-d','display_errors=0','-d','log_errors=0',str(script),str(ROOT),str(work/'mysql.sock'),db,json.dumps(dict(op=op,**kw))],capture_output=True,text=True,timeout=12)
   if r.returncode or r.stderr:raise RuntimeError(repr((r.returncode,r.stdout,r.stderr)))
   yes(before==snapshot(),op+'_read_only_'+str(len(checks)))
   return json.loads(r.stdout)
  finite=dict(username='fixture_user',status='active',expire=1800000000,data_limit=2147483648,used_traffic=1073741824)
  labels={'active':'فعال','disabled':'غیرفعال','expired':'مدت اشتراک تمام','limited':'حجم اشتراک تمام','on_hold':'در انتظار فعال'}
  for provider in ['marzban','pasarguard']:
   sql("UPDATE server_config SET type='"+provider+"' WHERE id=2")
   for status in labels:
    remote=dict(finite,status=status)
    traffic=call('traffic',provider=provider,remote=remote)['out']
    yes(traffic['found'] and not traffic['uncertain'] and traffic['status']==status and traffic['leftgb']=='1 GB',provider+'_'+status+'_common_traffic')
    for op in ['view','admin']:
     rendered=call(op,actor=9001 if op=='admin' else 1001,remote=remote)['out'];keys=rendered['keyboard']
     yes(labels[status] in rendered['msg'],provider+'_'+status+'_'+op+'_confirmed_status')
     yes('DATE:1800000000' in keys and 'DATE:1900000000' not in keys,provider+'_'+op+'_remote_expiry')
     yes(('changeUserConfigState91' in keys)==(op=='admin' and status in ['active','disabled']),provider+'_'+status+'_'+op+'_toggle_capability')
     if status=='on_hold':yes('renewAccountAsk91' not in keys and 'changePlan_91' not in keys and 'delUserConfig91' not in keys,provider+'_'+op+'_on_hold_no_unsupported_actions')
   for unlimited in [None,0]:
    remote=dict(finite,data_limit=unlimited,expire=unlimited)
    rendered=call('view',remote=remote)['out']
    yes(json.dumps(json.loads(rendered['keyboard']),ensure_ascii=False).count('نامحدود')==2 and '-1 GB' not in rendered['keyboard'],provider+'_unlimited_'+str(unlimited)+'_time_and_quota')
   for malformed in [None,dict(finite,username='foreign_user'),dict(finite,used_traffic=-1),dict(finite,data_limit='bad'),dict(finite,expire=-1),dict(finite,status='unknown')]:
    r=call('view',remote=malformed);shown=r['out']['msg']+r['out']['keyboard']
    yes('قابل تأیید نیست' in shown and 'در حال بررسی' in shown and 'renewAccountAsk91' not in shown and 'deleteMyConfig91' not in shown and 'vless://' not in shown and r['presentations']==0,provider+'_invalid_observation_'+str(len(checks)))
   hidden=call('view',remote=finite,hidden_links=True)['out']['keyboard']
   yes('showQrConfig91' not in hidden and 'showQrSub91' not in hidden,provider+'_hidden_data_no_dead_QR')
   no_targets=call('view',remote=finite)['out']['keyboard'];yes('switchLocation91' not in no_targets,provider+'_no_relocation_no_dead_button')
   targets=call('view',remote=finite,relocations=[{'id':3}])['out']['keyboard'];yes('switchLocation91' in targets,provider+'_eligible_relocation_button')
   sql("UPDATE users SET is_agent=1 WHERE userid=1001;UPDATE orders_list SET agent_bought=0 WHERE id=91")
   no_agent=call('view',remote=finite)['out']['keyboard'];yes('agentChangeUserConfigState91' not in no_agent,provider+'_agent_cannot_toggle_non_agent_purchase')
   sql("UPDATE orders_list SET agent_bought=1 WHERE id=91")
   agent=call('view',remote=finite)['out']['keyboard'];yes('agentChangeUserConfigState91' in agent,provider+'_agent_owned_purchase_toggle')
   sql("UPDATE users SET is_agent=0 WHERE userid=1001;UPDATE orders_list SET agent_bought=0 WHERE id=91")
   for op in ['view','admin']:
    denied=call(op,actor=1002,remote=finite)
    yes(denied['out'] is None and denied['reads']==0 and denied['presentations']==0,provider+'_'+op+'_foreign_no_disclosure')
   sql("UPDATE server_info SET active=0 WHERE id=2")
   categories=call('catalogue',data='selectServer2_none')['out']
   yes(not any('selectCategory' in str(e.get('keyboard','')) for e in categories),provider+'_inactive_server_no_categories')
   sql("UPDATE server_info SET active=1,state=0 WHERE id=2")
   plans=call('catalogue',data='selectCategory1_2_none')['out']
   yes(not any('selectPlan' in str(e.get('keyboard','')) for e in plans),provider+'_sale_disabled_no_plans')
   sql("UPDATE server_info SET state=1,ucount=0 WHERE id=2")
   trials=call('trial',data='getTestAccount')['out']
   yes(not any('freeTrial' in str(e.get('keyboard','')) for e in trials),provider+'_full_server_no_trial')
   sql("UPDATE server_info SET ucount=10 WHERE id=2")
   plans=call('catalogue',data='selectCategory1_2_none')['out']
   yes(any('selectPlan7_' in str(e.get('keyboard','')) for e in plans),provider+'_available_server_plan_choice')
  sql("UPDATE server_config SET type='retired_provider' WHERE id=2")
  unsupported=call('view',remote=finite)
  shown=json.dumps(unsupported['out'],ensure_ascii=False)
  yes(unsupported['reads']==0 and 'changeAccProtocol' not in shown and 'renewAccountAsk91' not in shown and 'deleteMyConfig91' not in shown,'unsupported_provider_no_legacy_protocol_or_other_actions')
  order_script=work/'order-management-unified.php';order_script.write_text(ORDER_PHP)
  def order_call(**spec):
   r=subprocess.run(['php','-d','disable_functions=curl_exec','-d','display_errors=0','-d','log_errors=0',str(order_script),str(ROOT),str(work/'mysql.sock'),db,json.dumps(spec)],capture_output=True,text=True,timeout=12)
   if r.returncode or r.stderr:raise RuntimeError(repr((r.returncode,r.stdout,r.stderr)))
   return json.loads(r.stdout)
  # Neither cached administrative flags nor forged old buttons confer a role.
  sql("UPDATE users SET isAdmin=0,step='none' WHERE userid IN(1002,9002)")
  protected=['returnToPanel_91','toggleAgent_91','toggleNotif_91','orderWarningStatus_91','changeExpireDate_91',
   'changeToken_91','changeUUID_91','changeServerId_91','selectServerId_91_2',
   'changeFileId_91_2','selectFileId_91_7','deleteOrder_91','confirmDeleteOrder_91',
   'showUserSubscriptions1001','quickUserOrderDetails_91','manageorderdatabase']
  for actor in [1002,9002]:
   for callback in protected:
    before=snapshot();out=order_call(actor=actor,data=callback,cached_admin=True)
    yes(out['order_queries']==0 and snapshot()==before and len(out['events'])==1 and 'alert' in out['events'][0],'order_role_'+str(actor)+'_'+callback+'_no_order_read_or_write')
   for step in ['awaitingToken_91','awaitingUUID_91','awaitingExpireChange_91']:
    sql("UPDATE users SET step='"+step+"' WHERE userid="+str(actor));before=snapshot()
    out=order_call(actor=actor,text='3',cached_admin=True)
    yes(out['order_queries']==0 and snapshot()==before,'order_role_'+str(actor)+'_'+step+'_stale_state_denied')
   sql("UPDATE users SET step='none' WHERE userid="+str(actor))
  for provider in ['marzban','pasarguard']:
   sql("UPDATE server_config SET type='"+provider+"' WHERE id=2;UPDATE users SET step='none' WHERE userid=9001")
   before=snapshot();out=order_call(actor=9001,direct=True);shown=json.dumps(out,ensure_ascii=False)
   yes(snapshot()==before and 'Fixture purchase server' in shown and 'اصلاح' in shown and 'اعتبار یا حجم سرویس' in shown,provider+'_order_context_read_only_clear_local_semantics')
   yes(('changeToken_91' in shown)==(provider=='marzban') and ('changeUUID_91' in shown)==(provider=='marzban'),provider+'_order_native_identity_controls')
   yes('deleteOrder_91' in shown and 'confirmDeleteOrder_91' not in shown,provider+'_order_initial_delete_is_confirmation_only')
   before=snapshot();out=order_call(actor=9001,data='deleteOrder_91');shown=json.dumps(out,ensure_ascii=False)
   yes(snapshot()==before and 'confirmDeleteOrder_91' in shown and 'سرویس از پنل حذف نمی' in shown,provider+'_local_delete_explicit_confirmation_no_mutation')
   if provider=='pasarguard':
    for callback in ['changeToken_91','changeUUID_91']:
     before=snapshot();out=order_call(actor=9001,data=callback)
     yes(snapshot()==before and any('alert'in e for e in out['events']),'pg_stale_'+callback+'_refused_without_identity_corruption')
   sql("UPDATE users SET step='awaitingExpireChange_91' WHERE userid=9001")
   before=sql('SELECT * FROM orders_list WHERE id=91')
   out=order_call(actor=9001,text='1.5');yes(sql('SELECT * FROM orders_list WHERE id=91')==before,provider+'_local_expiry_fraction_rejected')
   out=order_call(actor=9001,text='999999');yes(sql('SELECT * FROM orders_list WHERE id=91')==before,provider+'_local_expiry_overflow_rejected')
   out=order_call(actor=9001,text='3');yes('1900259200' in sql('SELECT expire_date FROM orders_list WHERE id=91'),provider+'_local_expiry_integer_exact')
   sql("UPDATE orders_list SET expire_date=1900000000 WHERE id=91;UPDATE users SET step='awaitingToken_91',temp='owned-draft' WHERE userid=9001")
   out=order_call(actor=9001,text='❌ لغو عملیات');shown=json.dumps(out,ensure_ascii=False)
   yes(sql("SELECT step,IFNULL(temp,'NULL') FROM users WHERE userid=9001").strip()=='none\tNULL' and 'REMOVE_KEYBOARD' in shown and 'order_context' not in shown and 'deleteOrder_91' in shown,provider+'_order_cancel_once_returns_context')
  # Execute the exact released toggle body against the owned DB as a RED
  # control: it wrote processing markers and erased existing grace deadlines.
  archived_ref='2c0e728dcd71c38e20d176fe2577bc264d3faa1a'
  archived=subprocess.check_output(['git','show',archived_ref+':src/Subscription/OrderManagement.php'],cwd=ROOT).decode()
  start=archived.index("if (preg_match('/^toggleNotif_")
  stop=archived.index("if (preg_match('/^changeExpireDate_",start)
  archived_toggle=archived[start:stop]
  archived_script=work/'released-warning-toggle.php';archived_script.write_text(ARCHIVE_PHP+archived_toggle)
  baseline_red=0
  for original,changed in [(0,-1),(int(time.time())+864000,0)]:
   sql('UPDATE orders_list SET notif='+str(original)+' WHERE id=91')
   r=subprocess.run(['php','-d','disable_functions=curl_exec','-d','display_errors=0','-d','log_errors=0',str(archived_script),str(ROOT),str(work/'mysql.sock'),db,'{}'],capture_output=True,text=True,timeout=10)
   yes(r.returncode==0 and not r.stdout and not r.stderr,'archived_toggle_real_php_executed_'+str(original))
   observed=int(sql('SELECT notif FROM orders_list WHERE id=91').strip())
   yes(observed==changed and observed!=original,'archived_toggle_actual_lifecycle_mutation_RED_'+str(original))
   baseline_red+=1

  warning_cases=[
   (0,'1700000000','ثبت نشده'),
   (-1,'1700000000','پردازش شده'),
   (-2,'1700000000','ارسال مجدد'),
   (int(time.time())+864000,'1700000000','مهلت نگهداری'),
   (1,'1700000000','نیازمند بررسی'),
   (-7,'1700000000','نیازمند بررسی'),
   (int(time.time())+864000,'synthetic','نیازمند بررسی'),
   (1700000000,'1700000000','نیازمند بررسی'),
  ]
  for provider in ['marzban','pasarguard']:
   sql("UPDATE server_config SET type='"+provider+"' WHERE id=2;UPDATE users SET step='none' WHERE userid=9001")
   for value,created,label in warning_cases:
    sql("UPDATE orders_list SET notif="+str(value)+",date='"+created+"' WHERE id=91")
    for callback in ['orderWarningStatus_91','toggleNotif_91']:
     before=snapshot();out=order_call(actor=9001,data=callback);shown=json.dumps(out,ensure_ascii=False)
     yes(snapshot()==before,provider+'_'+callback+'_'+str(value)+'_read_only_warning_state')
     yes(label in shown and 'کلید روشن یا خاموش نیست' in shown and 'تضمین تحویل پیام نیست' in shown and 'orderWarningStatus_91' in shown,provider+'_'+callback+'_'+str(value)+'_clear_lifecycle_copy')
   sql("UPDATE orders_list SET notif=0,date='1700000000' WHERE id=91")
   panel=order_call(actor=9001,direct=True);shown=json.dumps(panel,ensure_ascii=False)
   yes('orderWarningStatus_91' in shown and 'toggleNotif_91' not in shown,provider+'_new_order_panel_has_no_mutating_notification_toggle')
  sql("UPDATE server_config SET type='marzban' WHERE id=2")
  cases=[
   ('no_method',{},[],[],None,False),
   ('hosted_nowpayments',{'nowPaymentOther':'on'},[],[],270000,True),
   ('hosted_nowpayments_no_rate',{'nowPaymentOther':'on'},[],[],None,False),
   ('hosted_zarinpal',{'zarinpal':'on'},[],[],None,True),
   ('hosted_nextpay',{'nextpay':'on'},[],[],None,True),
   ('manual_trx',{},['manual_trx'],[],None,True),
   ('manual_usdt',{},['manual_usdt'],[],270000,True),
   ('wallet',{'walletState':'on'},[],[],None,True),
   ('denied_hosted',{'zarinpal':'on'},[],['zarinpal'],None,False),
   ('denied_trx',{},['manual_trx'],['manual_trx'],None,False),
  ]
  for name,state,manual,denied,rate,expected in cases:
   out=call('admission',state=state,manual=manual,denied=denied,rate=rate)
   yes(out['out'] is expected,name+'_same_checkout_menu_admission')
 result={'complete':True,'cases':len(checks),'checks':checks,'actual_php':True,'native_disposable_mariadb':True,'canonical_catalog_bindings':True,'archived_warning_toggle_RED':baseline_red,'archived_warning_toggle_ref':archived_ref,'archived_warning_toggle_sha256':hashlib.sha256(archived_toggle.encode()).hexdigest(),'external_provider_calls':0,'production_mutations':0}
 if a.report:a.report.write_text(json.dumps(result,ensure_ascii=False,indent=2)+'\n')
 print(json.dumps(result,ensure_ascii=False))
if __name__=='__main__':main()
