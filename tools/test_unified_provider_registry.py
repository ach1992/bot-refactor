#!/usr/bin/env python3
"""AChBot closed provider contract and common-core dispatch acceptance.

Only synthetic values, task-owned source copies and socket MariaDB are used.
The temporary fixture adapter proves dispatch, not support for another panel.
"""
from __future__ import annotations
import argparse,json,shutil,subprocess
from pathlib import Path
import package_post_v9 as package
import test_access_state as owned
import test_v6_native as native
import test_v8_native as purchase
ROOT=Path(__file__).resolve().parents[1]
DB=purchase.SIDE[1]
PHP=r'''<?php
error_reporting(E_ALL);set_error_handler(static function($n,$m){if($n===E_DEPRECATED)return true;throw new ErrorException($m,0,$n);});
$_SERVER['SCRIPT_FILENAME']=__FILE__;
$root=$argv[1];$spec=json_decode($argv[4],true,64,JSON_THROW_ON_ERROR);
$GLOBALS['events']=[];
function getMarzbanUser(...$a){$GLOBALS['events'][]='marzban_read';throw new RuntimeException('unexpected_transport');}
function getMarzbanUserInfo(...$a){$GLOBALS['events'][]='marzban_info';throw new RuntimeException('unexpected_transport');}
function editMarzbanConfig(...$a){$GLOBALS['events'][]='marzban_edit';throw new RuntimeException('unexpected_transport');}
function deleteMarzban(...$a){$GLOBALS['events'][]='marzban_delete';throw new RuntimeException('unexpected_transport');}
function renewMarzbanUUID(...$a){$GLOBALS['events'][]='marzban_revoke';throw new RuntimeException('unexpected_transport');}
function changeMarzbanState(...$a){$GLOBALS['events'][]='marzban_toggle';throw new RuntimeException('unexpected_transport');}
function createServerAccount(...$a){$GLOBALS['events'][]='marzban_create';throw new RuntimeException('unexpected_transport');}
function sendMessage(...$a){$GLOBALS['events'][]='telegram';return(object)['ok'=>true];}
function achbotSupportsPanel($type){return achbotSupportsProviderType($type);}
function fixturePlan(...$a){return true;}
function fixtureOrder(...$a){return true;}
function fixtureSame(...$a){return true;}
function fixtureUser($sid,$remark,$links=true){$GLOBALS['events'][]='fixture_user';return(object)['username'=>$remark,'expire'=>2000000000,'data_limit'=>2147483648,'used_traffic'=>0,'status'=>'active'];}
function fixtureInfo(...$a){return fixtureUser(...$a);}
function fixtureDeleteTarget($db,$sid,$remark,$row){return['provider'=>'fixture_native','server_id'=>$sid,'remark'=>$remark,'identity'=>'test-only'];}
function fixtureAction(...$a){$GLOBALS['events'][]='fixture_action';return(object)['success'=>true,'verified'=>true];}
function fixtureEdit(...$a){$GLOBALS['events'][]=['fixture_edit'=>$a[2]];return(object)['success'=>true,'verified'=>true,'applied_expire'=>2000000000,'applied_bytes'=>2147483648];}
function fixturePin(...$a){$GLOBALS['events'][]='fixture_pin';}
function fixtureQuote(...$a){return true;}
function fixtureTarget(...$a){return['group'=>null,'endpoint'=>str_repeat('a',64),'credentials'=>str_repeat('b',64),'key'=>'fixture:test-only'];}
function fixtureReserve(...$a){$GLOBALS['events'][]='fixture_reserve';}
function fixtureDispatch(...$a){$GLOBALS['events'][]=['fixture_dispatch'=>[$a[1],$a[2],$a[5],$a[6],$a[7],$a[8],$a[9],$a[10],$a[11],$a[13]]];return[(object)['success'=>true,'verified'=>true],$a[5],0,$a[1]];}
function fixtureLink(...$a){$GLOBALS['events'][]=['fixture_link'=>[$a[1],$a[2],$a[3]]];}
require $root.'/src/Purchase/Core.php';
require $root.'/src/Finance/ServiceEffects.php';
require $root.'/src/Finance/PaymentLifecycle.php';
$connection=new mysqli('localhost','root','',$argv[3],0,$argv[2]);$connection->set_charset('utf8mb4');
$botState=['renewAutoState'=>'off'];$admin=9001;
require $root.'/src/Finance/AutomaticRenewal.php';
try{
 if($spec['op']==='registry'){
  $map=achbotProviderRegistry();$missing=[];
  foreach(achbotProviderRequiredOperations()as$operation){$bad=$map;unset($bad['marzban'][$operation]);$missing[$operation]=achbotProviderRegistryValid($bad);}
  $bad=$map;$bad['unsafe\\x27provider']=$map['marzban'];
  $out=['valid'=>achbotProviderRegistryValid($map),'types'=>achbotProviderTypes(),'sql'=>achbotProviderSqlTypes(),'missing'=>$missing,
   'unsafe_key'=>achbotProviderRegistryValid($bad),'unsupported'=>array_map('achbotSupportsProviderType',['unknown','Marzban','',null,0,true,[]]),
   'current_callbacks'=>array_map(static fn($p)=>array_keys($p),$map)];
 } elseif($spec['op']==='denied'){
  $pay=$connection->query('SELECT * FROM pays WHERE id=1')->fetch_assoc();$type=achbotProviderType($connection,2);
  $out=['account'=>achbotSupportsAccount($type,0),'plan'=>achbotSupportsPlan($connection,7),'order'=>achbotSupportsOrder($connection,91),
   'subject'=>achbotSupportsPaymentSubject($connection,$pay),'quote'=>achbotProviderQuoteEligible($connection,$pay),
   'user'=>achbotProviderUser(2,'fixture_user',false),'action'=>achbotProviderDelete(2,'fixture_user'),
   'edit'=>achbotApplyServiceEdit($connection,2,['remark'=>'fixture_user','plus_day'=>1])];
  try{$out['target']=achbotEffectTargetIdentity($connection,2,7,1,['type'=>$type,'panel_group_id'=>null,'panel_url'=>'https://fixture.invalid','username'=>'fixture','password'=>'fixture']);}
  catch(Throwable$e){$out['target_error']=$e->getMessage();}
  try{achbotProviderReserveEffect($connection,1,['provider'=>$type],'fixture_user',str_repeat('c',32),2000000000,2147483648);$out['reserve']=true;}
  catch(Throwable$e){$out['reserve_error']=$e->getMessage();}
  try{$out['create']=achbotCreatePaidAccount($connection,1,1,['server_type'=>$type,'server_id'=>2,'inbound_id'=>0,'plan_id'=>7,'remark'=>'fixture_user','volume'=>2,'days'=>30,'user_id'=>1001]);}
  catch(Throwable$e){$out['create_error']=$e->getMessage();}
  $out['auto']=achbotAutoRenewReserve($connection,91,1001);
  $stmt=$connection->prepare("INSERT INTO pays(hash_id,user_id,type,plan_id,server_id,volume,day,price,request_date,state) VALUES('registry_draft',1001,'BUY_SUB',7,2,0,0,100,1700000000,'pending')");
  $out['draft']=achbotPayUxInsertDraft($connection,1001,$stmt);$stmt->close();
 } elseif($spec['op']==='flip') {
  try{achbotEffectDispatchReservedAccount($connection,111,2,null,str_repeat('a',64),'fixture_user',2,30,7,str_repeat('c',32),2000000000,2147483648,['server_type'=>$spec['expected']],str_repeat('b',64));$out=['accepted'=>true];}
  catch(Throwable$error){$out=['error'=>$error->getMessage()];}
 } else {
  $out=['types'=>achbotProviderTypes(),'sql'=>achbotProviderSqlTypes(),'account'=>achbotSupportsAccount('fixture_native',0),
   'plan'=>achbotSupportsPlan($connection,7),'order'=>achbotSupportsOrder($connection,91),
   'display'=>achbotServerDisplayValid(['mode'=>'native','policy'=>['max_links'=>10]],'fixture_native')];
  $out['plus_day']=achbotApplyServiceEdit($connection,2,['remark'=>'fixture_user','plus_day'=>3]);
  $out['plus_volume']=achbotApplyServiceEdit($connection,2,['remark'=>'fixture_user','plus_volume'=>7]);
  $out['read']=achbotProviderUser(2,'fixture_user',false);
  $out['target']=achbotEffectTargetIdentity($connection,2,7,1,['type'=>'fixture_native']);
  achbotProviderReserveEffect($connection,1,$out['target'],'fixture_user',str_repeat('c',32),2000000000,2147483648);
  $out['dispatch']=achbotEffectDispatchReservedAccount($connection,111,2,null,str_repeat('a',64),'fixture_user',2,30,7,str_repeat('c',32),2000000000,2147483648,[],str_repeat('b',64));
  achbotProviderPinPayment($connection,1);
 }
 echo json_encode(['out'=>$out,'events'=>$GLOBALS['events']],JSON_THROW_ON_ERROR);
}catch(Throwable$error){echo json_encode(['exception'=>$error->getMessage(),'events'=>$GLOBALS['events']],JSON_THROW_ON_ERROR);}
'''

def main():
 p=argparse.ArgumentParser();p.add_argument('--run-disposable',action='store_true',required=True);p.add_argument('--report',type=Path);a=p.parse_args()
 checks=[]
 def yes(ok,name):
  if not ok:raise AssertionError(name)
  checks.append(name)
 with owned.owned_database()as(work,env):
  purchase.init(work,env,'http://127.0.0.1:9')
  def sql(q):return native.sql(work,env,q,DB)
  for name in ['001_marzban_panel_group_id.sql','004_automatic_renewal_attempts.sql','007_service_effects.sql','011_provider_identity.sql']:
   sql((ROOT/'migrations/post-v9'/name).read_text())
  if not sql("SHOW INDEX FROM users WHERE Key_name='idx_users_userid_v1'").strip():sql('ALTER TABLE users ADD INDEX idx_users_userid_v1(userid)')
  sql("INSERT INTO pays(id,hash_id,user_id,type,plan_id,server_id,volume,day,price,request_date,state) VALUES(1,'registry_paid',1001,'BUY_SUB',7,2,0,0,100,1700000000,'processing')")
  sql("INSERT INTO orders_list(id,userid,token,transid,fileid,server_id,inbound_id,remark,uuid,protocol,expire_date,link,amount,status,date,autoRenew,notif) VALUES(91,1001,'fixture','',7,2,0,'fixture_user','fixture','vless',1900000000,'[]',100,1,'1700000000','on',-1)")
  driver=work/'registry.php';driver.write_text(PHP)
  def run(root,op,**extra):
   result=subprocess.run(['php','-d','display_errors=0','-d','log_errors=0',str(driver),str(root),str(work/'mysql.sock'),DB,json.dumps(dict(op=op,**extra))],capture_output=True,text=True,timeout=15)
   if result.returncode or any(line!='achbot_customer_draft_creation_deferred'for line in result.stderr.splitlines()):raise AssertionError(('php-registry-child',result.returncode,result.stderr[:1000]))
   data=json.loads(result.stdout)
   if 'exception'in data:raise AssertionError(data)
   return data
  def snapshot():return sql('SELECT * FROM users ORDER BY id;SELECT * FROM pays ORDER BY id;SELECT * FROM orders_list ORDER BY id;SELECT * FROM server_info ORDER BY id;SELECT * FROM service_effects ORDER BY id;SELECT * FROM automatic_renewal_attempts ORDER BY id')
  r=run(ROOT,'registry')['out']
  yes(r['valid']and r['types']==['marzban','pasarguard'],'production_registry_has_exact_two_native_adapters')
  yes(r['sql']=="'marzban','pasarguard'",'SQL_list_comes_from_registered_source_keys')
  for operation,accepted in r['missing'].items():yes(accepted is False,'incomplete_contract_rejected_'+operation)
  yes(r['unsafe_key']is False,'unsafe_source_key_cannot_enter_SQL_list')
  yes(r['unsupported']==[False]*7,'unknown_nonstring_or_case_alias_never_supported')
  for actual,expected in [('pasarguard','marzban'),('marzban','pasarguard')]:
   sql("UPDATE server_config SET type='"+actual+"' WHERE id=2")
   before=snapshot();flip=run(ROOT,'flip',expected=expected)
   yes(flip['out'].get('error')=='effect_provider_changed_pre_dispatch'and not flip['events']and before==snapshot(),'provider_type_flip_'+expected+'_to_'+actual+'_cannot_select_new_adapter')
  # Make owned copies only; intentionally incomplete source must fail before any local/remote effect.
  def stage(name):
   destination=work/name
   for member in package.members():
    path=destination/member;path.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(ROOT/member,path)
   return destination
  partial=stage('partial-adapter')
  path=partial/'src/Panel/ProviderRuntime.php';raw=path.read_text();needle="'edit'=>'achbotMarzbanServiceEditAdapter',"
  yes(raw.count(needle)==1,'partial_negative_control_exact_registry_callback')
  path.write_text(raw.replace(needle,'',1))
  for root,type,label in [(ROOT,'unregistered_panel','unknown'),(partial,'marzban','partial')]:
   sql("UPDATE server_config SET type='"+type+"' WHERE id=2")
   before=snapshot();out=run(root,'denied')
   yes(before==snapshot(),label+'_provider_cannot_change_balance_capacity_payment_order_or_effect')
   yes(not out['events'],label+'_provider_cannot_call_any_transport')
   d=out['out']
   yes(not any(d[key]for key in ['account','plan','order','subject','quote']),label+'_provider_is_ineligible_before_reservation')
   yes(d['user']['success']is False and d['action']['success']is False and d['edit']['success']is False,label+'_read_delete_and_edit_fail_closed')
   yes(d.get('target_error')=='unsupported_effect_provider',label+'_cannot_fall_back_to_Marzban_target_identity')
   yes(d.get('reserve_error')=='unsupported_effect_provider'and'create_error'in d,label+'_no_account_reservation_or_CREATE')
   yes(d['auto']['status']=='ineligible'and d['draft']==0,label+'_auto_renew_and_payment_draft_leave_no_debit_or_invoice')
  # A temporary non-production adapter proves generic dispatch without editing shared flow files.
  fixture=stage('fixture-adapter');path=fixture/'src/Panel/ProviderRuntime.php';raw=path.read_text()
  callbacks=dict(label='admin_settings.management.marzban',plan='fixturePlan',order='fixtureOrder',same_panel='fixtureSame',
   user='fixtureUser',user_info='fixtureInfo',deletion_target='fixtureDeleteTarget',action='fixtureAction',edit='fixtureEdit',
   pin_payment='fixturePin',quote='fixtureQuote',target='fixtureTarget',reserve='fixtureReserve',dispatch='fixtureDispatch',link='fixtureLink')
  literal="'fixture_native'=>["+",".join("'"+key+"'=>'"+value+"'"for key,value in callbacks.items())+"],\n        "
  needle="        'marzban'=>[";yes(raw.count(needle)==1,'fixture_registry_entry_has_one_source_insertion')
  path.write_text(raw.replace(needle,'        '+literal+"'marzban'=>[",1))
  for member in ['src/Purchase/Core.php','src/Finance/ServiceEffects.php','src/Finance/PaymentLifecycle.php','src/Finance/AutomaticRenewal.php']:
   yes((fixture/member).read_bytes()==(ROOT/member).read_bytes(),'fixture_adapter_uses_unchanged_shared_'+Path(member).stem)
  sql("UPDATE server_config SET type='fixture_native' WHERE id=2")
  before=snapshot();out=run(fixture,'fixture');d=out['out'];events=out['events']
  yes(before==snapshot(),'temporary_dispatch_probe_does_not_mutate_business_state')
  yes(d['account']and d['plan']and d['order']and d['display'],'registered_fixture_uses_common_eligibility_and_native_display')
  yes(d['types']==['fixture_native','marzban','pasarguard']and d['sql']=="'fixture_native','marzban','pasarguard'",'registered_fixture_automatically_enters_shared_SQL_filter')
  yes(d['plus_day']['verified']and d['plus_volume']['verified'],'time_and_volume_use_same_verified_edit_contract')
  yes([e['fixture_edit']for e in events if isinstance(e,dict)and'fixture_edit'in e]==[
   dict(remark='fixture_user',plus_day=3),dict(remark='fixture_user',plus_volume=7)],'shared_edit_passes_exact_requested_units')
  yes(d['target']['provider']=='fixture_native'and d['target']['key']=='fixture:test-only','registered_fixture_owns_its_target_identity')
  yes('fixture_reserve'in events and'fixture_pin'in events,'shared_effect_and_quote_hooks_delegate_to_registered_adapter')
  expected=[111,2,'fixture_user',2,30,7,'c'*32,2000000000,2147483648,'b'*64]
  yes(any(isinstance(e,dict)and e.get('fixture_dispatch')==expected for e in events),'shared_CREATE_dispatch_preserves_all_pinned_arguments')
  yes(not any(isinstance(e,str)and e.startswith('marzban_')for e in events),'registered_fixture_never_falls_back_to_Marzban')
  # Provider-specific state remains inside adapters; ordinary business callers name only common seams.
  for rel in ['src/Finance/ServiceEffects.php','src/Finance/PaymentLifecycle.php','src/Finance/AutomaticRenewal.php']:
   raw=(ROOT/rel).read_text()
   yes(not any(name in raw for name in ['achbotPgPinPayment(','achbotPgReserveEffect(','achbotPgDispatchReserved(','achbotPgLinkEffect(']),'shared_'+Path(rel).stem+'_contains_no_PasarGuard_effect_branch')
  report={'complete':True,'cases':len(checks),'checks':checks,'actual_php':True,'owned_socket_mariadb':True,
   'closed_native_adapters':2,'fixture_adapter_only':True,'external_requests':0,'production_mutations':0,'new_schema':False}
 if a.report:a.report.write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n')
 print(json.dumps(report,ensure_ascii=False))
if __name__=='__main__':main()
