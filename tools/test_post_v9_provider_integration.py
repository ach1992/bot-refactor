#!/usr/bin/env python3
"""AChBot provider integration: actual PHP, owned socket MariaDB and native HTTP fixtures."""
import argparse,copy,io,json,shutil,subprocess,sys,tarfile,tempfile,threading
import provider_storage_fixture as shared_storage
from urllib.parse import urlsplit
from pathlib import Path
import test_post_v9_pasarguard_client as pg
import test_access_state as owned
import test_v6_native as native
import test_v8_native as purchase
import test_v6_panels as panel_functions
import re
ROOT=Path(__file__).resolve().parents[1]
PHP=r'''<?php
error_reporting(E_ALL);
set_error_handler(static function($n,$m){if($n===E_DEPRECATED)return true;throw new ErrorException($m,0,$n);});
$_SERVER['SCRIPT_FILENAME']=__FILE__;define('ACHBOT_PG_TEST_LOOPBACK',true);
require $argv[1].'/src/Purchase/Core.php';
require $argv[1].'/src/Finance/ServiceEffects.php';
require $argv[1].'/src/Finance/PaymentLifecycle.php';
require $argv[1].'/src/Panel/ProviderAdmin.php';
require __DIR__.'/presentation-under-test.php';
$qrSource=file_get_contents($argv[1].'/buy_helpers.php');
$qrStart=strpos($qrSource,"if (!function_exists('sendQrFromLink'))");
$qrEnd=strpos($qrSource,"require_once __DIR__ . '/src/Purchase/OrderWrites.php'",$qrStart);
eval(str_replace('__DIR__',var_export($argv[1],true),substr($qrSource,$qrStart,$qrEnd-$qrStart)));
chdir(__DIR__);set_include_path(__DIR__);
function achbotSupportsPanel($type){return in_array($type,['marzban','pasarguard'],true);}
$GLOBALS['order_reads']=0;$GLOBALS['chat_reads']=0;
class OrderReadTraceMysqli extends mysqli {
 public function prepare(string $query): mysqli_stmt|false {
  if (preg_match('/^\\s*SELECT\\b.*\\borders_list\\b/is',$query)) ++$GLOBALS['order_reads'];
  return parent::prepare($query);
 }
}
$connection=new OrderReadTraceMysqli('localhost','root','',$argv[3],0,$argv[2]);$connection->set_charset('utf8mb4');
$spec=json_decode($argv[4],true);$db=$connection;
$GLOBALS['events']=[];
function alert(...$args){$GLOBALS['events'][]=['alert'=>(string)($args[0]??'')];}
function sendMessage(...$args){$GLOBALS['events'][]=['message'=>(string)($args[0]??'')];return (object)['ok'=>true];}
function editText(...$args){$GLOBALS['events'][]=['edit'=>(string)($args[1]??'')];return (object)['ok'=>true];}
function getServerListKeys(...$args){return '{}';}
function setUser($value=null,$field='step'){
 global $connection,$from_id;
 if ($field!=='step') throw new RuntimeException('fixture_step_only');
 $step=$value??'none';$q=$connection->prepare('UPDATE users SET step=? WHERE userid=?');
 $q->bind_param('si',$step,$from_id);$q->execute();$q->close();
}
function delMessage(...$args){$GLOBALS['events'][]=['delete_message'=>true];}
function jdate(...$args){return 'fixture-date';}
function RandomString(){return 'ownedqr';}
function sendPhoto(...$args){$photo=$args[0]??'';$GLOBALS['events'][]=['photo'=>$photo instanceof CURLFile?$photo->getFilename():(string)$photo,'caption'=>$args[1]??null];return (object)['ok'=>true];}
function bot(...$args){if (($args[0]??'')==='getChat') ++$GLOBALS['chat_reads'];return (object)['ok'=>true,'result'=>(object)['first_name'=>'Fixture']];}
function getUserOrderDetailKeys(...$args){
 global $argv;require_once $argv[1].'/src/Subscription/AccountDetails.php';return \AchBot\Subscription\AccountDetails\getUserOrderDetailKeys(...$args);
}
function getOrderDetailKeys(...$args){
 global $argv;require_once $argv[1].'/src/Subscription/AccountDetails.php';return \AchBot\Subscription\AccountDetails\getOrderDetailKeys(...$args);
}

try {
switch($spec['op']){
case 'gate':$out=['plan'=>achbotSupportsPlan($db,7),'marzban'=>achbotSupportsMarzbanPlan($db,7)];break;
case 'pin':
$db->begin_transaction();achbotPgPinPayment($db,$spec['pay']);$db->commit();
$out=achbotPgQuote($db,$spec['pay']);break;
case 'create':
$out=achbotCreatePaidAccount($db,1,1,['server_type'=>'pasarguard','server_id'=>2,'inbound_id'=>0,
'plan_id'=>7,'remark'=>'fixture_user','volume'=>2,'days'=>30,'user_id'=>1001,'port'=>0]);break;
case 'trial':
$out=achbotCreateNonPaymentAccount($db,'free_trial','trial:1001',1001,1,1,
['server_type'=>'pasarguard','server_id'=>2,'inbound_id'=>0,'plan_id'=>8,'remark'=>'trial_user',
'volume'=>2,'days'=>30,'user_id'=>1001,'port'=>0]);break;
case 'admin':
$actor=(int)($spec['actor']??9001);$admin=9001;
$update=json_decode(json_encode(['update_id'=>$spec['source'],'message'=>[
'message_id'=>$spec['source'],'text'=>$spec['text'],'from'=>['id'=>$actor,'username'=>'fixture_admin','first_name'=>'Fixture'],
'chat'=>['id'=>$actor,'type'=>'private']]]));
require $argv[1].'/src/Telegram/UpdateBindings.php';$data=$spec['callback']??'';
$q=$db->prepare('SELECT * FROM users WHERE userid=?');$q->bind_param('i',$from_id);$q->execute();$userInfo=$q->get_result()->fetch_assoc();$q->close();
$cancelKey='{}';$buttonValues=['cancel'=>'cancel'];
$out=['handled'=>achbotHandleProviderAdmin(),'events'=>$GLOBALS['events']];break;
case 'view':
case 'adminview':
case 'traffic':
case 'qr':
$from_id=$spec['op']==='adminview'?9001:1001;$admin=9001;$userInfo=['is_agent'=>1,'step'=>'none'];$showIndexes=[];$botUrl='https://bot.example.invalid/';
$buttonValues=array_fill_keys($spec['buttons'],'fixture-label');
$buttonValues['active']='CONFIRMED_ACTIVE_FIXTURE';$buttonValues['deactive']='CONFIRMED_DISABLED_FIXTURE';
$mainValues=['config_details_message'=>"STATE\nNAME\nCONNECT-LINK\nSUB-LINK",'config_doesnt_exist'=>'missing'];
if (!empty($spec['omit_status_template'])) $mainValues['config_details_message']="NAME\nCONNECT-LINK\nSUB-LINK";
$botState=array_fill_keys(['renewAutoState','sellState','renewAccountState','switchLocationState','increaseVolumeState','increaseTimeState','renewConfigLinkState','updateConfigLinkState','qrConfigState','qrSubState','subLinkState','configLinkState'],'on');
if (in_array($spec['op'],['view','adminview','traffic'],true)) {
 require $argv[1].'/src/Subscription/AccountDetails.php';
 $out=match($spec['op']) {
  'view'=>\AchBot\Subscription\AccountDetails\getOrderDetailKeys(1001,91),
  'adminview'=>\AchBot\Subscription\AccountDetails\getUserOrderDetailKeys(91),
  'traffic'=>\AchBot\Subscription\AccountDetails\getOrderTrafficInfo('pasarguard',2,'fixture_user','native-placeholder',0)
 };
} else {
 $data=$spec['callback'];
 require $argv[1].'/src/Finance/Routes/Checkout.php';
 $out=['events'=>$GLOBALS['events'],'qr_payloads'=>$GLOBALS['qr_payloads']??[]];
}
break;
case 'detailread':
$actor=(int)$spec['actor'];$admin=9001;
$base=['update_id'=>$spec['source']];
$sender=['id'=>$actor,'username'=>'fixture_reader','first_name'=>'Fixture'];
$message=['message_id'=>1,'text'=>$spec['text']??'cached foreign text','from'=>['id'=>9001],
 'chat'=>['id'=>$actor,'type'=>'private'],'reply_markup'=>['inline_keyboard'=>[]]];
if (isset($spec['text'])) {$message['from']=$sender;$base['message']=$message;}
else $base['callback_query']=['id'=>'fixture_read','data'=>$spec['callback']??'none','from'=>$sender,'message'=>$message];
$update=json_decode(json_encode($base));require $argv[1].'/src/Telegram/UpdateBindings.php';
$data=$data??'';$condition='';
$q=$db->prepare('SELECT * FROM users WHERE userid=?');$q->bind_param('i',$from_id);$q->execute();$userInfo=$q->get_result()->fetch_assoc()??['step'=>'none'];$q->close();
if (!empty($spec['cached_admin'])) $userInfo['isAdmin']=1;
$buttonValues=array_fill_keys(array_merge($spec['buttons'],['cancel','active','deactive']),'fixture-label');
$mainValues=['config_details_message'=>"STATE\nNAME\nCONNECT-LINK\nSUB-LINK",'config_doesnt_exist'=>'missing',
 'no_order_found'=>'NO_ORDER','please_wait_message'=>'WAIT','send_config_remark'=>'ENTER_REMARK'];
$botState=array_fill_keys(['renewAutoState','sellState','renewAccountState','switchLocationState','increaseVolumeState','increaseTimeState','renewConfigLinkState','updateConfigLinkState','qrConfigState','qrSubState'],'on');
$cancelKey='{}';$removeKeyboard='{}';$botUrl='https://bot.example.invalid/';
register_shutdown_function(static function(){echo json_encode(['out'=>['actor'=>$GLOBALS['from_id'],'events'=>$GLOBALS['events'],
 'detail'=>$GLOBALS['detail_result']??null,'order_reads'=>$GLOBALS['order_reads'],'chat_reads'=>$GLOBALS['chat_reads'],
 'error'=>$GLOBALS['read_error']??null]],JSON_THROW_ON_ERROR);});
try {
 if ($spec['mode']==='directadmin') $GLOBALS['detail_result']=getUserOrderDetailKeys(91);
 elseif ($spec['mode']==='directowner') $GLOBALS['detail_result']=getOrderDetailKeys($spec['owner']??$actor,91);
 elseif ($spec['mode']==='quick') require $argv[1].'/src/Subscription/OrderManagement.php';
 else require $argv[1].'/src/Subscription/CustomerRoutes.php';
} catch (Throwable $error) {$GLOBALS['read_error']=$error->getMessage();}
exit;
case 'route':
$actor=(int)$spec['actor'];$admin=9001;
$update=json_decode(json_encode(['update_id'=>$spec['source'],'callback_query'=>[
'id'=>'fixture_callback','data'=>$spec['callback'],'from'=>['id'=>$actor,'username'=>'fixture_actor','first_name'=>'Fixture'],
'message'=>['message_id'=>1,'text'=>'cached foreign text','from'=>['id'=>9001],
'chat'=>['id'=>$actor,'type'=>'private'],'reply_markup'=>['inline_keyboard'=>[]]]]]));
require $argv[1].'/src/Telegram/UpdateBindings.php';
$q=$db->prepare('SELECT * FROM users WHERE userid=?');$q->bind_param('i',$from_id);$q->execute();$userInfo=$q->get_result()->fetch_assoc();$q->close();
$mainValues=['please_wait_message'=>'منتظر بمانید','no_order_found'=>'NO_ORDER',
 'config_details_message'=>"STATE\nNAME\nCONNECT-LINK\nSUB-LINK",'config_doesnt_exist'=>'missing'];
$botState=[];$buttonValues=array_fill_keys(array_merge($spec['buttons'],['active','deactive']),'fixture-label');
$botUrl='https://bot.example.invalid/';
require $argv[1].'/src/Subscription/CustomerRoutes.php';
$out=['actor'=>$from_id,'events'=>$GLOBALS['events']];break;
case 'autoreserve':
require $argv[1].'/src/Finance/ReceiptHelpers.php';require $argv[1].'/src/Finance/AutomaticRenewal.php';
$out=achbotAutoRenewReserve($db,92,1001);break;
case 'autoedit':
$out=achbotApplyServiceEdit($db,2,['remark'=>'trial_user','pay_id'=>$spec['pay'],'operation_key'=>'autorenew:'.$spec['attempt'],'days'=>30,'volume'=>2]);break;
case 'bulk':
$out=achbotCreateNonPaymentAccount($db,'admin_bulk','bulk:9001:201',9001,$spec['line'],2,
['is_primary_admin'=>true,'server_type'=>'pasarguard','server_id'=>2,'inbound_id'=>0,'plan_id'=>7,
'remark'=>'bulk_user_'.$spec['line'],'volume'=>2,'days'=>30,'user_id'=>9001,'port'=>0]);break;
case 'link_nonpaid':
$db->begin_transaction();achbotLinkNonPaymentAccountEffect($db,(int)$spec['effect'],(int)$spec['order']);$db->commit();$out=['linked'=>true];break;
case 'giftlist':
require $argv[1].'/src/Subscription/GiftBatch.php';
$out=achbotProviderGiftUsers($db,2,achbotGiftPhysicalTarget($db,2));break;
case 'gift':
require $argv[1].'/src/Subscription/GiftBatch.php';
$ctx=achbotPgOrderContext($db,92);
$out=achbotApplyServiceEdit($db,2,['remark'=>'trial_user','operation_key'=>'gift:fixture:1','plus_day'=>1],
achbotGiftPhysicalTarget($db,2));break;
case 'link':
$db->begin_transaction();achbotLinkPaidAccountEffect($db,(int)$spec['effect'],91);$db->commit();
$out=achbotProviderUser(2,'fixture_user');break;
case 'rawedit':
$raw=$spec['value'];
if ($raw==='@NAN') $raw=NAN;
elseif ($raw==='@INF') $raw=INF;
elseif ($raw==='@NEG_INF') $raw=-INF;
$change=['remark'=>'fixture_user','operation_key'=>'units:'.(int)$spec['source'],$spec['field']=>$raw];
if ($spec['field']==='both_volume') {$change['volume']=1;$change['plus_volume']=1;}
if ($spec['field']==='both_days') {$change['days']=1;$change['plus_day']=1;}
$out=achbotApplyServiceEdit($db,2,$change);break;
case 'edit':$out=achbotApplyServiceEdit($db,2,array_merge(['remark'=>'fixture_user','pay_id'=>$spec['pay']],$spec['change']));break;
case 'action':
$GLOBALS['update']=(object)['update_id'=>$spec['source']];
$out=achbotProviderAction(2,'fixture_user',$spec['action']);break;
case 'switch':
$GLOBALS['update']=(object)['update_id'=>$spec['source']];
$admin=9001;$out=achbotReassignLogicalServer($db,91,3,(int)($spec['actor']??9001),($spec['admin_allowed']??true)===true);break;
case 'groups':achbotPgLoad();$out=achbotPgAdminGroupIds($spec['groups'],achbotProviderPanel($db,1));break;
default:throw new RuntimeException('fixture_op_missing');
}
echo json_encode(['out'=>$out],JSON_THROW_ON_ERROR);
}catch(Throwable $e){try{$db->rollback();}catch(Throwable $ignored){}echo json_encode(['error'=>$e->getMessage()]);}
'''
# Let B's read-only eligibility preflight finish, then hold its arithmetic
# observation while A attempts to enter. Its durable fence must already be
# persisted; calculating limits before that claim would admit a competing A.
ORIGINAL_HANDLE=pg.Handler.handle_request
def stale_observation(self):
 server=self.server
 if self.headers.get('X-Api-Key')=='fixture-rotated-key' and getattr(server,'allow_rotated',False):
  self.headers.replace_header('X-Api-Key','fixture-private-key')
 if self.command=='GET' and urlsplit(self.path).path=='/api/user/by-id/17' and server.mode=='read_timeout':
  server.trace.append(('GET',self.path,None))
  threading.Event().wait(11)
  self.close_connection=True
  return
 if self.command=='GET' and urlsplit(self.path).path=='/api/user/by-id/17' and getattr(server,'capture_stale_once',False):
  if getattr(server,'capture_stale_skip',0):
   server.capture_stale_skip-=1
   ORIGINAL_HANDLE(self)
   return
  server.capture_stale_once=False
  value=copy.deepcopy(server.user)
  server.trace.append(('GET',self.path,None))
  server.stale_captured.set()
  assert server.release_stale.wait(15),'stale read fixture was not released'
  body=json.dumps(value).encode()
  self.send_response(200);self.send_header('Content-Type','application/json')
  self.send_header('Content-Length',str(len(body)));self.end_headers();self.wfile.write(body)
 else:ORIGINAL_HANDLE(self)
pg.Handler.handle_request=stale_observation

def main():
 p=argparse.ArgumentParser();p.add_argument('--run-disposable',action='store_true',required=True);p.add_argument('--report',type=Path);a=p.parse_args()
 checks=[]
 with owned.owned_database() as (work,env),pg.panel() as (server,url):
  purchase.init(work,env,url);db=purchase.SIDE[1]
  script=work/'integration.php';script.write_text(PHP)
  # Facades use the real runtime dependency root. ServiceEffects has already
  # loaded its resolver; a second physical copy would redeclare those functions.
  facades=panel_functions.function_bodies((ROOT/'config.php').read_bytes(),['buildSubscriptionPresentation','buildConfigMessage'])
  (work/'presentation-under-test.php').write_bytes(facades.replace(b'__DIR__',repr(str(ROOT)).encode()))
  (work/'phpqrcode').mkdir()
  (work/'phpqrcode/qrlib.php').write_text("<?php class QRcode {public static function png($payload,$file,...$rest){$GLOBALS['qr_payloads'][]=$payload;file_put_contents($file,'owned-png-placeholder');}}")
  buttons=['back_to_main']+sorted(set(re.findall(r"\$buttonValues\['([^']+)'\]",(ROOT/'src/Subscription/AccountDetails.php').read_text())))
  def call(op,**spec):
   if op=='route':spec.setdefault('buttons',buttons)
   out=subprocess.run(['php','-d','display_errors=0','-d','log_errors=0',str(script),str(ROOT),str(work/'mysql.sock'),db,json.dumps(dict(op=op,**spec))],capture_output=True,text=True,timeout=25)
   if out.returncode or out.stderr:raise AssertionError((out.returncode,out.stderr))
   return json.loads(out.stdout)
  def sql(s):return native.sql(work,env,s,db)
  def yes(condition,name):
   assert condition,name;checks.append(name)
  yes(call('gate')['out']=={'plan':True,'marzban':True},'marzban_works_without_011')
  sql("UPDATE server_config SET type='pasarguard' WHERE id=2")
  yes(call('gate')['out']=={'plan':False,'marzban':False},'pasarguard_fails_closed_without_011')
  sql("UPDATE server_config SET type='marzban' WHERE id=2")
  for name in ['001_marzban_panel_group_id.sql','004_automatic_renewal_attempts.sql','007_service_effects.sql','011_provider_identity.sql','012_shared_provider_storage.sql']:
   sql((ROOT/'migrations/post-v9'/name).read_text())
  sql(shared_storage.connection(2,1,url)+"UPDATE server_info SET provider_group_ids='[2,5]' WHERE id=2;")
  sql("INSERT INTO pays(id,hash_id,user_id,plan_id,server_id,type,state,price,request_date) VALUES(1,'pgbuy',1001,7,2,'BUY_SUB','paid',100,1700000000)")
  snap=call('pin',pay=1)['out'];yes(snap['group_ids']==[2,5],'quote_pins_native_groups')
  sql("UPDATE server_info SET provider_group_ids='[5]' WHERE id=2;UPDATE server_plans SET provider_group_ids='[5]' WHERE id=7")
  r=call('create');yes('error' not in r,'paid_create_committed_native_identity')
  effect=r['out'][3];yes(server.user['group_ids']==[2,5],'create_uses_quoted_groups_after_defaults_change')
  expire=r['out'][0]['expire']
  sql("INSERT INTO orders_list(id,userid,token,transid,fileid,server_id,inbound_id,remark,uuid,protocol,expire_date,link,amount,status,date) VALUES(91,1001,'','',7,2,0,'fixture_user','native-placeholder','vless',"+str(expire)+",'[]',100,1,'fixture')")
  r=call('link',effect=effect);yes(r['out']['id']==17 and r['out']['provider']=='pasarguard','verified_account_links_order_by_native_id')
  sql("INSERT INTO users(userid,name,username,refcode,wallet,date,isAdmin,first_start,is_agent) VALUES(1011,'Foreign','foreign','0',50000,'1700000000',0,'sent',0),(1012,'Agent','agent','0',50000,'1700000000',0,'sent',1),(1013,'CurrentAdmin','currentadmin','0',50000,'1700000000',1,'sent',0),(1014,'RevokedAdmin','revokedadmin','0',50000,'1700000000',0,'sent',0)")
  def read_snapshot():return sql("SELECT * FROM users ORDER BY id;SELECT * FROM orders_list ORDER BY id;SELECT * FROM service_effects WHERE provider_reference_id IS NOT NULL ORDER BY id")
  def read_call(actor,mode,**spec):return call('detailread',actor=actor,mode=mode,source=7000,buttons=buttons,**spec)['out']
  denied=[('directadmin',{}),('directowner',{'owner':1001}),('route',{'callback':'userOrderDetails91_0'}),
   ('quick',{'callback':'quickUserOrderDetails_91'}),('route',{'callback':'searchUsersConfig'}),
   ('route',{'text':'fixture_user'})]
  forbidden_order_queries=0;forbidden_provider_requests=0
  for actor in [1011,1012,1014,1015]:
   for mode,spec in denied:
    sql("UPDATE users SET step='"+('searchUsersConfig' if 'text' in spec else 'none')+"' WHERE userid="+str(actor))
    before=read_snapshot();requests=len(server.trace);writes=server.write_count
    result=read_call(actor,mode,cached_admin=True,**spec);shown=json.dumps(result,ensure_ascii=False)
    forbidden_order_queries+=result['order_reads'];forbidden_provider_requests+=len(server.trace)-requests
    yes(result['error'] is None and result['actor']==actor and result['detail'] is None and result['order_reads']==0 and result['chat_reads']==0 and len(server.trace)==requests and server.write_count==writes and read_snapshot()==before and 'sub.example.invalid' not in shown and 'vless://' not in shown and 'changePlan_' not in shown and 'fixture_user' not in shown,'read_auth_'+str(actor)+'_'+mode+'_'+('searchtext' if 'text' in spec else spec.get('callback','direct'))+'_no_privileged_query_or_disclosure')
  sql("UPDATE users SET step='none' WHERE userid IN(1011,1012,1014)")
  primary_role=sql('SELECT isAdmin FROM users WHERE userid=9001').strip()
  sql('UPDATE users SET isAdmin=0 WHERE userid=9001')
  for actor in [9001,1013]:
   for mode,spec in [('directadmin',{}),('route',{'callback':'userOrderDetails91_0'}),('quick',{'callback':'quickUserOrderDetails_91'}),('route',{'text':'fixture_user'})]:
    sql("UPDATE users SET step='"+('searchUsersConfig' if 'text' in spec else 'none')+"' WHERE userid="+str(actor))
    requests=len(server.trace);writes=server.write_count;account_before=sql('SELECT * FROM service_effects WHERE provider_reference_id IS NOT NULL AND order_id=91')
    result=read_call(actor,mode,**spec);shown=json.dumps(result,ensure_ascii=False)
    assert result['error'] is None and result['detail'] is not None if mode=='directadmin' else result['error'] is None,(actor,mode,result)
    yes(result['error'] is None and result['order_reads']>0 and result['chat_reads']==1 and len(server.trace)>requests and server.write_count==writes and sql('SELECT * FROM service_effects WHERE provider_reference_id IS NOT NULL AND order_id=91')==account_before and 'https://sub.example.invalid/token' in shown and 'vless://native-identity' in shown,'read_auth_current_'+str(actor)+'_'+mode+'_'+('searchtext' if 'text' in spec else spec.get('callback','direct'))+'_real_native_detail')
   sql("UPDATE users SET step='none' WHERE userid="+str(actor))
  sql('UPDATE users SET isAdmin='+primary_role+' WHERE userid=9001')
  for agent in [0,1]:
   sql('UPDATE users SET is_agent='+str(agent)+",step='none' WHERE userid=1001;UPDATE orders_list SET agent_bought="+str(agent)+' WHERE id=91')
   for mode,spec in [('directowner',{'owner':1001}),('route',{'callback':'orderDetails91'}),('route',{'text':'fixture_user'})]:
    sql("UPDATE users SET step='"+(('searchAgentConfig' if agent else 'searchMyConfig') if 'text' in spec else 'none')+"' WHERE userid=1001")
    requests=len(server.trace);writes=server.write_count
    result=read_call(1001,mode,**spec);shown=json.dumps(result,ensure_ascii=False)
    assert result['error'] is None,(agent,mode,result)
    yes(result['error'] is None and len(server.trace)>requests and server.write_count==writes and 'https://sub.example.invalid/token' in shown and 'vless://native-identity' in shown and 'adminToggleAutoRenew91' not in shown and 'delUserConfig91' not in shown,'read_auth_owned_'+str(agent)+'_'+mode+'_'+('searchtext' if 'text' in spec else spec.get('callback','direct'))+'_customer_detail_retained')
   requests=len(server.trace);result=read_call(1001,'directadmin')
   yes(result['detail'] is None and result['order_reads']==0 and result['chat_reads']==0 and len(server.trace)==requests,'read_auth_owned_'+str(agent)+'_cannot_elevate_to_admin_facade')
  sql("UPDATE users SET is_agent=1,step='none' WHERE userid=1001;UPDATE orders_list SET agent_bought=0 WHERE id=91")
  invalid_units=[('plus_volume',0.5),('plus_volume','1.25'),('plus_volume','0.1'),
   ('volume',0.5),('plus_day',0.5),('days','1.2'),('plus_volume',0),('plus_volume',-1),
   ('volume',None),('plus_volume',True),('plus_volume',[]),('plus_volume',{}),
   ('plus_volume','not-a-number'),('plus_volume','NaN'),('plus_volume','INF'),
   ('plus_volume','1e309'),('plus_volume',2147483648),('days',36501),
   ('plus_volume','@NAN'),('plus_volume','@INF'),('plus_volume','@NEG_INF'),
   ('both_volume',1),('both_days',1)]
  for index,(field,value) in enumerate(invalid_units):
   requests=len(server.trace);writes=server.write_count
   before=sql("SELECT * FROM service_effects WHERE provider_reference_id IS NOT NULL AND order_id=91;SELECT userid,wallet FROM users ORDER BY userid;SELECT id,state,price FROM pays ORDER BY id")
   result=call('rawedit',source=5000+index,field=field,value=value)
   yes(result['out']['success'] is False and len(server.trace)==requests and server.write_count==writes and sql("SELECT * FROM service_effects WHERE provider_reference_id IS NOT NULL AND order_id=91;SELECT userid,wallet FROM users ORDER BY userid;SELECT id,state,price FROM pays ORDER BY id")==before,'invalid_unit_'+str(index)+'_rejects_before_native_GET_claim_or_money_change')
  # A relocation action is shown only when a real eligible destination exists.
  sql("INSERT INTO server_info(id,title,ucount,remark,flag,active,state,provider_group_ids) VALUES(3,'Destination',10,'pgdst','',1,1,'[5]');DELETE FROM server_config WHERE id=3;"+shared_storage.clone(3,2))
  view=call('view',buttons=buttons)['out'];keys=view['keyboard']
  yes('https://sub.example.invalid/token' in view['msg'] and 'vless://native-identity' in view['msg'],'actual_pg_view_uses_native_subscription_and_links')
  for callback in ['renewAccountAsk91','increaseAVolume91','increaseADay91','changAccountConnectionLink91','showQrConfig91','showQrSub91']:
   yes(callback in keys,'actual_pg_view_exposes_'+callback)
  yes('switchLocation91'not in keys,'ordinary_customer_has_no_server_switch_control')
  r=call('qr',callback='showQrSub91',buttons=buttons);assert 'out' in r,r;qr=r['out']
  yes(qr['qr_payloads']==['https://sub.example.invalid/token'],'actual_pg_subscription_QR_uses_native_url')
  qr=call('qr',callback='showQrConfig91',buttons=buttons)['out']
  yes(len(qr['qr_payloads'])==2 and qr['qr_payloads'][0].startswith('vless://native-identity'),'actual_pg_config_QR_uses_native_links')
  # Native status is distinct from failed observation. Real timeouts use the
  # client's unchanged10-second cURL limit on the task-owned loopback fixture.
  sql("UPDATE orders_list SET agent_bought=1 WHERE id=91")
  original_status=server.user['status']
  status_labels={'active':'CONFIRMED_ACTIVE_FIXTURE','disabled':'CONFIRMED_DISABLED_FIXTURE',
    'expired':'مدت اشتراک تمام','limited':'حجم اشتراک تمام'}
  for status,label in status_labels.items():
   server.user['status']=status
   traffic=call('traffic',buttons=buttons)['out']
   yes(traffic['uncertain'] is False and traffic['found'] is True and traffic['status']==status,'confirmed_native_'+status+'_traffic_semantics')
   for audience in ['view','adminview']:
    state_before=sql("SELECT * FROM service_effects WHERE provider_reference_id IS NOT NULL AND order_id=91;SELECT * FROM orders_list WHERE id=91")
    writes=server.write_count
    result=call(audience,buttons=buttons)['out']
    yes(label in result['msg'],'confirmed_native_'+status+'_'+audience+'_status_copy')
    callback='agentChangeUserConfigState91' if audience=='view' else 'changeUserConfigState91'
    yes((callback in result['keyboard'])==(status in ['active','disabled']) and server.write_count==writes and sql("SELECT * FROM service_effects WHERE provider_reference_id IS NOT NULL AND order_id=91;SELECT * FROM orders_list WHERE id=91")==state_before,'confirmed_native_'+status+'_'+audience+'_eligible_controls_without_writes')
  server.user['status']=original_status
  dangerous=['renewAccountAsk91','increaseAVolume91','increaseADay91','changePlan_91',
   'changAccountConnectionLink91','updateConfigConnectionLink91','showQrConfig91','showQrSub91',
   'deleteMyConfig91','changeUserConfigState91','agentChangeUserConfigState91','delUserConfig91',
   'toggleAutoRenew91','adminToggleAutoRenew91']
  for mode in ['read_timeout','foreign_created','held_mutating','http_404']:
   server.mode='ok' if mode=='held_mutating' else mode
   if mode=='held_mutating':sql("UPDATE service_effects SET provider_state='mutating' WHERE order_id=91")
   traffic=call('traffic',buttons=buttons)['out']
   yes(traffic['uncertain'] is True and traffic['found'] is False,mode+'_traffic_reports_uncertainty')
   for audience in ['view','adminview']:
    state_before=sql("SELECT * FROM service_effects WHERE provider_reference_id IS NOT NULL AND order_id=91;SELECT * FROM orders_list WHERE id=91")
    writes=server.write_count;requests=len(server.trace)
    result=call(audience,buttons=buttons)['out'];shown=result['msg']+result['keyboard']
    yes('قابل تأیید نیست' in result['msg'] and 'در حال بررسی' in result['msg'] and 'missing' not in shown and 'CONFIRMED_' not in shown and 'pg_' not in shown and 'pasarguard' not in shown.lower(),mode+'_'+audience+'_truthful_private_copy')
    yes(not any(action in result['keyboard'] for action in dangerous),mode+'_'+audience+'_no_unconfirmed_actions')
    yes(server.write_count==writes and sql("SELECT * FROM service_effects WHERE provider_reference_id IS NOT NULL AND order_id=91;SELECT * FROM orders_list WHERE id=91")==state_before and (mode!='held_mutating' or len(server.trace)==requests),mode+'_'+audience+'_preserves_rows_and_no_external_writes')
   if mode=='held_mutating':sql("UPDATE service_effects SET provider_state='linked' WHERE order_id=91")
  server.mode='foreign_created'
  for audience in ['view','adminview']:
   result=call(audience,buttons=buttons,omit_status_template=True)['out']
   yes('قابل تأیید نیست' in result['msg'],'uncertainty_notice_survives_custom_statusless_'+audience+'_template')
  server.mode='ok'
  sql("UPDATE orders_list SET agent_bought=0 WHERE id=91")
  sql("INSERT INTO users(userid,name,username,refcode,wallet,date,isAdmin,first_start,is_agent) VALUES(1002,'Foreign','foreign','0',50000,'1700000000',0,'sent',1)")
  for provider in ['marzban','pasarguard']:
   sql("UPDATE server_config SET type='"+provider+"' WHERE id=2")
   for callback in ['changeUserConfigState91','agentChangeUserConfigState91','updateConfigConnectionLink91','changAccountConnectionLink91']:
    before=len(server.trace);r=call('route',actor=1002,source=200,callback=callback)
    yes('out' in r and r['out']['actor']==1002 and len(server.trace)==before,provider+'_genuine_update_foreign_'+callback+'_no_request')
  # Only the actual initial both-NULL state may have no consumed history.
  # Each corrupted fixture must survive rejection unchanged, with no new claim
  # and no provider GET/write. Restoring this still-initial fixture is test-only.
  initial_history=sql("SELECT IFNULL(HEX(provider_mutation_key),'NULL'),IFNULL(HEX(provider_mutation_snapshot),'NULL'),provider_state FROM service_effects WHERE order_id=91").strip()
  yes(initial_history=='NULL\tNULL\tlinked','initial_linked_account_has_both_history_fields_null')
  valid_history={'version':1,'key':'history:old','consumed_keys':['history:old']}
  bad_histories=[
   ('malformed_json','history:old','{'),
   ('null_json','history:old','null'),
   ('scalar_json','history:old','"history"'),
   ('missing_consumed','history:old',json.dumps({'version':1,'key':'history:old'})),
   ('nonarray_consumed','history:old',json.dumps(dict(valid_history,consumed_keys='history:old'))),
   ('object_consumed','history:old',json.dumps(dict(valid_history,consumed_keys={'a':'history:old'}))),
   ('numeric_object_consumed','history:old',json.dumps(dict(valid_history,consumed_keys={'0':'history:old'}))),
   ('empty_consumed','history:old',json.dumps(dict(valid_history,consumed_keys=[]))),
   ('duplicate_consumed','history:old',json.dumps(dict(valid_history,consumed_keys=['history:old','history:old']))),
   ('nonstrict_version','history:old',json.dumps(dict(valid_history,version='1'))),
   ('unsupported_version','history:old',json.dumps(dict(valid_history,version=2))),
   ('last_key_mismatch','history:old',json.dumps(dict(valid_history,key='history:other'))),
   ('last_key_not_retained','history:old',json.dumps(dict(valid_history,consumed_keys=['history:earlier']))),
   ('numeric_source_key','history:old',json.dumps(dict(valid_history,consumed_keys=[1,'history:old']))),
   ('empty_source_key','history:old',json.dumps(dict(valid_history,consumed_keys=['','history:old']))),
   ('oversized_source_key','history:old',json.dumps(dict(valid_history,consumed_keys=['x'*101,'history:old']))),
   ('invalid_source_key','history:old',json.dumps(dict(valid_history,consumed_keys=['history:new\n','history:old']))),
   ('null_key_with_snapshot',None,json.dumps(valid_history)),
   ('key_with_null_snapshot','history:old',None),
   ('empty_snapshot','history:old',''),
   ('overbound_history','history:old',json.dumps(dict(valid_history,consumed_keys=['history:'+str(i) for i in range(4096)]+['history:old']))),
   ('full_4096_history','history:old',json.dumps(dict(valid_history,consumed_keys=['history:'+str(i) for i in range(4095)]+['history:old']))),
  ]
  def text_sql(value):
   return 'NULL' if value is None else "CONVERT(X'"+value.encode().hex()+"' USING utf8mb4)"
  for index,(name,last,snapshot) in enumerate(bad_histories):
   sql("UPDATE service_effects SET provider_mutation_key="+text_sql(last)+",provider_mutation_snapshot="+text_sql(snapshot)+" WHERE order_id=91")
   before=sql("SELECT IFNULL(HEX(provider_mutation_key),'NULL'),IFNULL(HEX(provider_mutation_snapshot),'NULL'),provider_state FROM service_effects WHERE order_id=91").strip()
   requests=len(server.trace);writes=server.write_count
   result=call('action',action='toggle',source=250+index)
   after=sql("SELECT IFNULL(HEX(provider_mutation_key),'NULL'),IFNULL(HEX(provider_mutation_snapshot),'NULL'),provider_state FROM service_effects WHERE order_id=91").strip()
   yes(result['out']['success'] is False and len(server.trace)==requests and server.write_count==writes and after==before,'history_'+name+'_rejects_without_HTTP_or_new_claim')
  sql("UPDATE service_effects SET provider_mutation_key=NULL,provider_mutation_snapshot=NULL WHERE order_id=91")
  sql("UPDATE users SET is_agent=0 WHERE userid=1001;UPDATE orders_list SET agent_bought=1 WHERE id=91")
  before=len(server.trace)
  r=call('route',actor=1001,source=201,callback='agentChangeUserConfigState91')
  yes('out' in r and len(server.trace)==before,'owner_without_agent_role_cannot_use_agent_action')
  sql("UPDATE users SET is_agent=1 WHERE userid=1001;UPDATE orders_list SET agent_bought=0 WHERE id=91")
  before=len(server.trace)
  r=call('route',actor=1001,source=202,callback='agentChangeUserConfigState91')
  yes('out' in r and len(server.trace)==before,'agent_without_agent_purchase_cannot_use_agent_action')
  sql("UPDATE orders_list SET agent_bought=1 WHERE id=91")
  r=call('route',actor=1001,source=203,callback='agentChangeUserConfigState91')
  yes('out' in r and server.user['status']=='disabled','owned_agent_purchase_action_verified')
  r=call('route',actor=9001,source=204,callback='changeUserConfigState91')
  yes('out' in r and server.user['status']=='active','genuine_admin_action_verified')
  server.mode='write_lost'
  r=call('route',actor=1001,source=205,callback='changeUserConfigState91')
  text=json.dumps(r,ensure_ascii=False)
  yes('pg_' not in text and 'pasarguard' not in text.lower() and ('نیاز' in text or 'قطعی' in text),'customer_action_error_hides_provider_diagnostics')
  server.mode='ok';sql("UPDATE service_effects SET provider_state='linked' WHERE order_id=91");server.user['status']='active'
  posts=sum(t[0]=='POST' and t[1]=='/api/user' for t in server.trace)
  yes('error' in call('create') and sum(t[0]=='POST' and t[1]=='/api/user' for t in server.trace)==posts,'duplicate_create_never_dispatches')
  yes(call('gate')['out']=={'plan':True,'marzban':False},'neutral_gate_admits_pg_without_marzban_alias')
  sql("INSERT INTO pays(id,hash_id,user_id,plan_id,server_id,order_id,type,state,price,request_date) VALUES(2,'pgrenew',1001,7,2,91,'RENEW_ACCOUNT','processing',100,1700000000)")
  yes(call('pin',pay=2)['out']['group_ids']==[2,5],'renewal_snapshot_retains_account_identity_without_reinheriting_plan')
  r=call('edit',pay=2,change={'plus_day':3});yes(r['out'].get('success') is True and server.user['group_ids']==[2,5],'day_extension_verified_and_groups_preserved')
  writes=server.write_count
  yes(call('edit',pay=2,change={'plus_day':3})['out']['success'] is False and server.write_count==writes,'same_mutation_key_cannot_repeat')
  # Group switch verifies native PUT before SQL routing changes.
  sql("UPDATE server_info SET provider_group_ids='[5]',active=1,state=1,ucount=10 WHERE id=3")
  before_switch=sql('SELECT * FROM orders_list WHERE id=91');writes_before_switch=server.write_count
  denied_switch=call('switch',source=99,actor=1001,admin_allowed=True)
  yes(denied_switch['out']is None and sql('SELECT * FROM orders_list WHERE id=91')==before_switch and server.write_count==writes_before_switch,'forged_admin_flag_never_grants_customer_server_switch')
  r=call('switch',source=100);yes(r['out']['target_id']==3 and server.user['group_ids']==[5],'explicit_switch_updates_groups_then_local_route')
  # Switch back for remaining exact local-context checks.
  sql("UPDATE orders_list SET server_id=2 WHERE id=91")
  sql("INSERT INTO pays(id,hash_id,user_id,plan_id,server_id,order_id,type,state,price,request_date) VALUES(6,'pgmanualgroupsbefore',1001,7,2,91,'RENEW_ACCOUNT','processing',100,1700000000),(7,'pgmanualgroupsafter',1001,7,2,91,'RENEW_ACCOUNT','processing',100,1700000000)")
  server.user['group_ids']=[2]
  call('pin',pay=6)
  yes(call('edit',pay=6,change={'plus_day':1})['out']['success'] is True and server.user['group_ids']==[2],'manual_group_change_before_quote_preserved_by_extension')
  call('pin',pay=7);server.user['group_ids']=[5]
  yes(call('edit',pay=7,change={'plus_volume':1})['out']['success'] is True and server.user['group_ids']==[5],'manual_group_change_after_quote_preserved_by_extension')

  sql("INSERT INTO pays(id,hash_id,user_id,plan_id,server_id,order_id,type,state,price,request_date) VALUES(4,'pgparallelA',1001,7,2,91,'INCREASE_VOLUME_91_1','processing',100,1700000000),(5,'pgparallelB',1001,7,2,91,'INCREASE_VOLUME_91_1','processing',100,1700000000)")
  sql("UPDATE pays SET volume=1,day=0 WHERE id IN (4,5)")
  call('pin',pay=4);call('pin',pay=5)
  server.capture_stale_once=True;server.capture_stale_skip=1;server.stale_captured=threading.Event();server.release_stale=threading.Event()
  args=['php','-d','display_errors=0','-d','log_errors=0',str(script),str(ROOT),str(work/'mysql.sock'),db,json.dumps(dict(op='edit',pay=5,change={'plus_volume':1}))]
  before_writes=server.write_count
  b=subprocess.Popen(args,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
  try:
   assert server.stale_captured.wait(10),'B did not capture its post-preflight arithmetic observation'
   fence=sql("SELECT CONCAT(provider_state,':',provider_mutation_key) FROM service_effects WHERE order_id=91").strip()
   before_claim=sql("SELECT * FROM service_effects WHERE provider_reference_id IS NOT NULL AND order_id=91")
   requests=len(server.trace)
   result_a=call('edit',pay=4,change={'plus_volume':1})
   yes(fence=='mutating:pay:5' and result_a['out']['success'] is False
       and len(server.trace)==requests and server.write_count==before_writes
       and sql("SELECT * FROM service_effects WHERE provider_reference_id IS NOT NULL AND order_id=91")==before_claim,
       'account_fence_owned_before_arithmetic_GET_blocks_competing_A')
  finally:server.release_stale.set()
  out,err=b.communicate(timeout=20);assert not err and b.returncode==0,(out,err)
  result_b=json.loads(out)
  yes(result_b['out']['success'] is True and server.write_count==before_writes+1,'only_fence_owner_B_dispatches_absolute_change')
  sql("UPDATE service_effects SET provider_state='linked',provider_mutation_snapshot=JSON_SET(provider_mutation_snapshot,'$.result','verified','$.current_group_ids',JSON_ARRAY(5)) WHERE order_id=91")
  prior_quota=server.user['data_limit']
  yes(call('edit',pay=4,change={'plus_volume':1})['out']['success'] is True and server.user['data_limit']==prior_quota+1073741824,'later_edit_observes_completed_prior_quota')

  sql("INSERT INTO pays(id,hash_id,user_id,plan_id,server_id,order_id,type,state,price,request_date) VALUES(3,'pgvolume',1001,7,2,91,'INCREASE_VOLUME_91_1','processing',100,1700000000)")
  sql("UPDATE pays SET volume=1,day=0 WHERE id=3")
  call('pin',pay=3)
  before=sql("SELECT * FROM service_effects WHERE provider_reference_id IS NOT NULL AND order_id=91");requests=len(server.trace)
  mismatch=call('edit',pay=3,change={'plus_volume':2})
  yes(mismatch['out']['success'] is False and len(server.trace)==requests and sql("SELECT * FROM service_effects WHERE provider_reference_id IS NOT NULL AND order_id=91")==before,'paid_addon_mismatched_quantity_refused_before_native_claim')
  sql("UPDATE pays SET volume=0 WHERE id=3")
  legacy=call('edit',pay=3,change={'plus_volume':1})
  yes(legacy['out']['success'] is False and len(server.trace)==requests and sql("SELECT * FROM service_effects WHERE provider_reference_id IS NOT NULL AND order_id=91")==before,'paid_legacy_zero_addon_refused_before_native_claim')
  sql("UPDATE pays SET volume=1 WHERE id=3")
  sql("INSERT INTO pays(id,hash_id,user_id,plan_id,server_id,order_id,type,state,price,request_date,volume,day) VALUES(10,'pgpinnedvol',1001,7,2,91,'INCREASE_VOLUME_91_81','processing_wallet',100,1700000000,2,0),(11,'pgpinnedday',1001,7,2,91,'INCREASE_DAY_91_82','processing',200,1700000000,0,2)")
  call('pin',pay=10);call('pin',pay=11)
  quota=server.user['data_limit'];groups=copy.deepcopy(server.user['group_ids'])
  pinned=call('edit',pay=10,change={'plus_volume':2})
  yes(pinned['out']['success'] is True and server.user['data_limit']==quota+2147483648 and server.user['group_ids']==groups and sql("SELECT price FROM pays WHERE id=10").strip()=='100','wallet_style_pinned_volume_fulfills_exactly_after_SKU_absent')
  expiry=pinned['out']['applied_expire'];quota=server.user['data_limit']
  pinned=call('edit',pay=11,change={'plus_day':2})
  yes(pinned['out']['success'] is True and pinned['out']['applied_expire']==expiry+172800 and server.user['data_limit']==quota and server.user['group_ids']==groups and sql("SELECT price FROM pays WHERE id=11").strip()=='200','manual_style_pinned_day_fulfills_exactly_after_SKU_absent')
  server.mode='write_lost'
  writes=server.write_count
  r=call('edit',pay=3,change={'plus_volume':1});yes(r['out']['success'] is False and server.write_count==writes+1,'lost_write_is_uncertain')
  writes=server.write_count;server.mode='ok'
  r=call('edit',pay=3,change={'plus_volume':1});yes(r['out']['success'] is False and server.write_count==writes,'unknown_mutation_fence_prevents_retry')
  # Explicit operator reconciliation fixture releases fence; runtime never does this automatically.
  sql("UPDATE service_effects SET provider_state='linked' WHERE order_id=91")
  yes(call('action',action='toggle',source=101)['out']['success'] is True and server.user['status']=='disabled','native_disable_verified')
  yes(call('action',action='toggle',source=102)['out']['success'] is True and server.user['status']=='active','native_enable_verified')
  server.mode='write_lost'
  r=call('action',action='toggle',source=106)
  yes(r['out']['success'] is False,'lost_toggle_is_uncertain')
  intent=json.loads(sql("SELECT provider_mutation_snapshot FROM service_effects WHERE order_id=91").strip())
  yes(intent['changes']['expected']['status']=='disabled' and intent['changes']['before_status']=='active','lost_toggle_retains_durable_absolute_target')
  server.mode='ok'
  writes=server.write_count
  yes(call('action',action='toggle',source=107)['out']['success'] is False and server.write_count==writes,'unknown_toggle_blocks_new_source')
  sql("UPDATE service_effects SET provider_state='linked' WHERE order_id=91")

  yes(call('action',action='reset',source=103)['out']['success'] is True and server.user['used_traffic']==0,'native_reset_verified')
  yes(call('action',action='revoke',source=104)['out']['success'] is True,'native_revoke_checks_proxy_identity')
  writes=server.write_count
  yes(call('action',action='toggle',source=101)['out']['success'] is False and server.write_count==writes,'nonadjacent_old_mutation_key_cannot_repeat')

  before_groups=copy.deepcopy(server.user['group_ids']);before_status=server.user['status']
  quota=server.user['data_limit']
  result=call('rawedit',source=5100,field='plus_volume',value=1.0)
  yes(result['out']['success'] is True and server.user['data_limit']==quota+1073741824 and server.user['group_ids']==before_groups and server.user['status']==before_status,'whole_float_unit_remains_exact_native_GiB')
  result=call('rawedit',source=5101,field='volume',value=0)
  yes(result['out']['success'] is True and server.user['data_limit']==0 and server.user['used_traffic']==0 and server.user['group_ids']==before_groups,'explicit_zero_replacement_preserves_native_unlimited_semantics')
  writes=server.write_count
  result=call('rawedit',source=5102,field='plus_volume',value=1)
  yes(result['out']['success'] is False and server.write_count==writes and server.user['data_limit']==0,'current_unlimited_addon_refuses_before_first_write')
  sql("UPDATE service_effects SET provider_state='linked' WHERE order_id=91")
  result=call('rawedit',source=5103,field='volume',value=2)
  yes(result['out']['success'] is True and server.user['data_limit']==2147483648 and server.user['group_ids']==before_groups,'whole_replacement_from_unlimited_still_verified')
  server.user['data_limit']=9223372036854775807;writes=server.write_count
  result=call('rawedit',source=5104,field='plus_volume',value=1)
  yes(result['out']['success'] is False and server.write_count==writes and server.user['data_limit']==9223372036854775807,'integer_native_addition_overflow_refused_before_first_write')
  server.user['data_limit']=2147483648
  sql("UPDATE service_effects SET provider_state='linked' WHERE order_id=91")
  yes(call('action',action='delete',source=105)['out']['success'] is True and server.user is None,'native_delete_requires_by_id_404')
  writes=server.write_count;yes(call('action',action='delete',source=105)['out']['success'] is False and server.write_count==writes,'deleted_identity_cannot_repeat')
  r=call('groups',groups='2,5,2');assert 'out' in r,r;yes(r['out']==[2,5],'admin_groups_are_native_validated')
  yes('error' in call('groups',groups='999'),'admin_unknown_group_denied')
  before_legacy_connection=sql("SELECT * FROM server_info ORDER BY id;SELECT * FROM server_config ORDER BY id")
  sql("UPDATE users SET step='pg:connection' WHERE userid=9001")
  r=call('admin',source=2000,text='Fixture retired connection\n'+url+'\nfixture-private-key')
  yes(r['out']['handled'] is True and 'fixture-private-key' not in json.dumps(r),'retired_separate_connection_input_never_echoes_credentials')
  yes(sql("SELECT * FROM server_info ORDER BY id;SELECT * FROM server_config ORDER BY id")==before_legacy_connection,
      'retired_separate_connection_flow_cannot_create_orphan_connection')
  server.allow_rotated=True
  sql("UPDATE users SET step='pg:key:1:2' WHERE userid=9001")
  r=call('admin',source=2002,text='fixture-rotated-key')
  yes(sql("SELECT COUNT(*) FROM server_config WHERE provider_connection_id=1 AND BINARY password<>BINARY 'fixture-rotated-key'").strip()=='0' and 'fixture-rotated-key' not in json.dumps(r),'actual_admin_shared_server_key_rotation_validated_without_echo')
  sql("UPDATE users SET step='pg:groups:server:2' WHERE userid=9001")
  call('admin',source=2003,text='2,5')
  yes(sql("SELECT provider_group_ids FROM server_info WHERE id=2").strip()=='[2,5]','actual_admin_server_default_groups')
  sql("UPDATE users SET step='pg:groups:plan:7' WHERE userid=9001")
  call('admin',source=2004,text='/inherit')
  yes(sql("SELECT provider_group_ids IS NULL FROM server_plans WHERE id=7").strip()=='1','actual_admin_plan_groups_inherit')
  sql("UPDATE users SET step='pg:groups:plan:7' WHERE userid=9001")
  call('admin',source=2005,text='5')
  yes(sql("SELECT provider_group_ids FROM server_plans WHERE id=7").strip()=='[5]','actual_admin_plan_groups_replace')
  before=len(server.trace)
  r=call('admin',actor=1002,source=2006,text='none',callback='pgAddConnection')
  yes(len(server.trace)==before and r['out']['handled'] is True,'actual_admin_nonprivileged_callback_denied')
  pg.USER['id']=18
  r=call('trial');yes('error' not in r,'trial_uses_same_durable_native_create')
  trial_effect=r['out'][3];trial_expire=r['out'][0]['expire']
  sql("INSERT INTO orders_list(id,userid,token,transid,fileid,server_id,inbound_id,remark,uuid,protocol,expire_date,link,amount,status,date) VALUES(92,1001,'','',8,2,0,'trial_user','native-trial','vless',"+str(trial_expire)+",'[]',0,1,'fixture')")
  yes(call('link_nonpaid',effect=trial_effect,order=92)['out']['linked'] is True,'trial_links_native_account')
  posts=sum(t[0]=='POST' and t[1]=='/api/user' for t in server.trace)
  yes('error' in call('trial') and sum(t[0]=='POST' and t[1]=='/api/user' for t in server.trace)==posts,'trial_replay_never_creates')
  r=call('gift');yes(r['out']['success'] is True,'direct_gift_preserves_native_identity_and_groups')
  writes=server.write_count
  yes(call('gift')['out']['success'] is False and server.write_count==writes,'gift_source_cannot_repeat')
  sql("UPDATE server_plans SET price=100 WHERE id=8;UPDATE orders_list SET autoRenew='on',notif=1,expire_date=1700000000 WHERE id=92")
  r=call('autoreserve');yes(r['out']['status']=='reserved','auto_renew_claim_pins_native_snapshot_before_wallet_commit')
  claim=r['out'];r=call('autoedit',pay=claim['pay_id'],attempt=claim['id'])
  yes(r['out']['success'] is True and server.user['used_traffic']==0,'auto_renew_native_reset_and_absolute_limits_verified')
  yes(call('autoreserve')['out']['status']=='already_claimed','auto_renew_source_never_redebits')
  sql("UPDATE orders_list SET status=0 WHERE id=91")
  r=call('giftlist')
  yes(r['out']['total']==1 and r['out']['users'][0]['id']==18,'native_gift_roster_enumerates_exact_owned_by_id')
  sql("UPDATE service_effects SET provider_state='mutating' WHERE order_id=92")
  before=len(server.trace);r=call('giftlist')
  yes(r['out']['success'] is False and len(server.trace)==before,'native_gift_roster_never_silently_omits_unknown_account')
  sql("UPDATE service_effects SET provider_state='linked' WHERE order_id=92")


  for line,remote in [(1,19),(2,20)]:
   pg.USER['id']=remote
   r=call('bulk',line=line);yes('error' not in r,'bulk_line_'+str(line)+'_durable')
  posts=sum(t[0]=='POST' and t[1]=='/api/user' for t in server.trace)
  yes('error' in call('bulk',line=2) and sum(t[0]=='POST' and t[1]=='/api/user' for t in server.trace)==posts,'bulk_partial_source_cannot_reissue')

 # Bind RED evidence to the exact reviewed pre-remediation source on every
 # invocation. The archived run fails at the first disclosure negative before
 # this block, so it cannot recursively start another archived run.
 baseline='fb138eacdc5817922cc1e1c9c200323d24c79838'
 with tempfile.TemporaryDirectory(prefix='achbot-admin-read-baseline-') as archived:
  root=Path(archived)
  source=subprocess.check_output(['git','-C',str(ROOT),'archive',baseline])
  with tarfile.open(fileobj=io.BytesIO(source)) as archive:archive.extractall(root,filter='data')
  shutil.copyfile(Path(__file__),root/'tools/test_post_v9_provider_integration.py')
  red=subprocess.run([sys.executable,str(root/'tools/test_post_v9_provider_integration.py'),'--run-disposable'],capture_output=True,text=True,timeout=60)
  baseline_red=red.returncode!=0 and 'read_auth_1011_directadmin_direct_no_privileged_query_or_disclosure' in red.stderr
  assert baseline_red,('admin_read_baseline_did_not_reproduce',red.returncode,red.stdout,red.stderr)
 # Preserve every existing integration assertion, then cover the common
 # sweep through its real native wallet/effect/finalization path on a fresh
 # synthetic account. Historical authorization RED runs fail above this point.
 original_provider_integration_cases=len(checks)
 from test_unified_unlimited_renewal import acceptance as native_unlimited_renewal
 unlimited_renewal=native_unlimited_renewal(ROOT)
 checks.extend('native_unlimited_renewal_'+name for name in unlimited_renewal['checks'])
 auth_cases=sum(name.startswith('read_auth_') for name in checks)
 report={'complete':True,'cases':len(checks),'checks':checks,'actual_php_curl':True,'owned_socket_mariadb':True,'real_provider_tested':False,
  'lifecycle_cases':len(checks)-auth_cases,'admin_read_authorization_cases':auth_cases,
  'forbidden_order_queries':forbidden_order_queries,'forbidden_provider_requests':forbidden_provider_requests,
  'baseline_red':baseline_red,'baseline_red_source':baseline,
  'original_provider_integration_cases':original_provider_integration_cases,
  'native_unlimited_renewal':unlimited_renewal['providers']['pasarguard'],
  'native_unlimited_renewal_marzban':unlimited_renewal['providers']['marzban'],
  'native_unlimited_renewal_both_providers':{key:value for key,value in unlimited_renewal.items() if key not in ('checks','providers')}}
 if a.report:a.report.write_text(json.dumps(report,indent=2)+'\n')
 print(json.dumps(report))
if __name__=='__main__':main()
