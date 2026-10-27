#!/usr/bin/env python3
"""AChBot provider integration: actual PHP, owned socket MariaDB and native HTTP fixtures."""
import argparse,copy,json,subprocess,tempfile,threading
from urllib.parse import urlsplit
from pathlib import Path
import test_post_v9_pasarguard_client as pg
import test_access_state as owned
import test_v6_native as native
import test_v8_native as purchase
ROOT=Path(__file__).resolve().parents[1]
PHP=r'''<?php
error_reporting(E_ALL);
set_error_handler(static function($n,$m){if($n===E_DEPRECATED)return true;throw new ErrorException($m,0,$n);});
$_SERVER['SCRIPT_FILENAME']=__FILE__;define('ACHBOT_PG_TEST_LOOPBACK',true);
require $argv[1].'/src/Purchase/Core.php';
require $argv[1].'/src/Finance/ServiceEffects.php';
require $argv[1].'/src/Finance/PaymentLifecycle.php';
require $argv[1].'/src/Panel/ProviderAdmin.php';
function achbotSupportsPanel($type){return in_array($type,['marzban','pasarguard'],true);}
$connection=new mysqli('localhost','root','',$argv[3],0,$argv[2]);$connection->set_charset('utf8mb4');
$spec=json_decode($argv[4],true);$db=$connection;
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
case 'gift':
require $argv[1].'/src/Subscription/GiftBatch.php';
$ctx=achbotPgOrderContext($db,92);
$out=achbotApplyServiceEdit($db,2,['remark'=>'trial_user','operation_key'=>'gift:fixture:1','plus_day'=>1],
achbotGiftPhysicalTarget($db,2));break;
case 'link':
$db->begin_transaction();achbotLinkPaidAccountEffect($db,(int)$spec['effect'],91);$db->commit();
$out=achbotProviderUser(2,'fixture_user');break;
case 'edit':$out=achbotApplyServiceEdit($db,2,array_merge(['remark'=>'fixture_user','pay_id'=>$spec['pay']],$spec['change']));break;
case 'action':
$GLOBALS['update']=(object)['update_id'=>$spec['source']];
$out=achbotProviderAction(2,'fixture_user',$spec['action']);break;
case 'switch':
$GLOBALS['update']=(object)['update_id'=>$spec['source']];
$out=achbotReassignLogicalServer($db,91,3,1001);break;
case 'groups':achbotPgLoad();$out=achbotPgAdminGroupIds($spec['groups'],achbotProviderPanel($db,1));break;
default:throw new RuntimeException('fixture_op_missing');
}
echo json_encode(['out'=>$out],JSON_THROW_ON_ERROR);
}catch(Throwable $e){try{$db->rollback();}catch(Throwable $ignored){}echo json_encode(['error'=>$e->getMessage()]);}
'''
# Deliver B's captured pre-A observation only after A has completed.
# This deterministically tests stale absolute math across a released SQL fence.
ORIGINAL_HANDLE=pg.Handler.handle_request
def stale_observation(self):
 server=self.server
 if self.command=='GET' and urlsplit(self.path).path=='/api/user/by-id/17' and getattr(server,'capture_stale_once',False):
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
  def call(op,**spec):
   out=subprocess.run(['php','-d','display_errors=0','-d','log_errors=0',str(script),str(ROOT),str(work/'mysql.sock'),db,json.dumps(dict(op=op,**spec))],capture_output=True,text=True,timeout=25)
   if out.returncode or out.stderr:raise AssertionError((out.returncode,out.stderr))
   return json.loads(out.stdout)
  def sql(s):return native.sql(work,env,s,db)
  def yes(condition,name):
   assert condition,name;checks.append(name)
  yes(call('gate')['out']=={'plan':True,'marzban':True},'marzban_works_without_011')
  sql("UPDATE server_config SET type='pasarguard' WHERE id=2")
  yes(call('gate')['out']=={'plan':False,'marzban':False},'pasarguard_fails_closed_without_011')
  for name in ['001_marzban_panel_group_id.sql','004_automatic_renewal_attempts.sql','007_service_effects.sql','011_provider_identity.sql']:
   sql((ROOT/'migrations/post-v9'/name).read_text())
  sql("INSERT INTO provider_panels(id,provider,instance_key,name,api_url,api_key,version) VALUES(1,'pasarguard',REPEAT('b',32),'Fixture','"+url+"','fixture-private-key','5.5.1');UPDATE server_info SET provider_panel_id=1,provider_group_ids='[2,5]' WHERE id=2;")
  sql("INSERT INTO pays(id,hash_id,user_id,plan_id,server_id,type,state,price,request_date) VALUES(1,'pgbuy',1001,7,2,'BUY_SUB','paid',100,1700000000)")
  snap=call('pin',pay=1)['out'];yes(snap['group_ids']==[2,5],'quote_pins_native_groups')
  sql("UPDATE server_info SET provider_group_ids='[5]' WHERE id=2;UPDATE server_plans SET provider_group_ids='[5]' WHERE id=7")
  r=call('create');yes('error' not in r,'paid_create_committed_native_identity')
  effect=r['out'][3];yes(server.user['group_ids']==[2,5],'create_uses_quoted_groups_after_defaults_change')
  expire=r['out'][0]['expire']
  sql("INSERT INTO orders_list(id,userid,token,transid,fileid,server_id,inbound_id,remark,uuid,protocol,expire_date,link,amount,status,date) VALUES(91,1001,'','',7,2,0,'fixture_user','native-placeholder','vless',"+str(expire)+",'[]',100,1,'fixture')")
  r=call('link',effect=effect);yes(r['out']['id']==17 and r['out']['provider']=='pasarguard','verified_account_links_order_by_native_id')
  posts=sum(t[0]=='POST' and t[1]=='/api/user' for t in server.trace)
  yes('error' in call('create') and sum(t[0]=='POST' and t[1]=='/api/user' for t in server.trace)==posts,'duplicate_create_never_dispatches')
  yes(call('gate')['out']=={'plan':True,'marzban':False},'neutral_gate_admits_pg_without_marzban_alias')
  sql("INSERT INTO pays(id,hash_id,user_id,plan_id,server_id,order_id,type,state,price,request_date) VALUES(2,'pgrenew',1001,7,2,91,'RENEW_ACCOUNT','processing',100,1700000000)")
  yes(call('pin',pay=2)['out']['group_ids']==[2,5],'renewal_snapshot_preserves_purchased_groups')
  r=call('edit',pay=2,change={'plus_day':3});yes(r['out'].get('success') is True and server.user['group_ids']==[2,5],'day_extension_verified_and_groups_preserved')
  writes=server.write_count
  yes(call('edit',pay=2,change={'plus_day':3})['out']['success'] is False and server.write_count==writes,'same_mutation_key_cannot_repeat')
  # Group switch verifies native PUT before SQL routing changes.
  sql("INSERT INTO server_info(id,title,ucount,remark,flag,active,state,provider_panel_id,provider_group_ids) VALUES(3,'Destination',10,'pgdst','',1,1,1,'[5]');REPLACE INTO server_config SELECT 3,panel_url,ip,sni,header_type,request_header,response_header,security,tlsSettings,type,username,password,port_type,reality,sub_links FROM server_config WHERE id=2")
  r=call('switch',source=100);yes(r['out']['target_id']==3 and server.user['group_ids']==[5],'explicit_switch_updates_groups_then_local_route')
  # Switch back for remaining exact local-context checks.
  sql("UPDATE orders_list SET server_id=2 WHERE id=91")
  sql("INSERT INTO pays(id,hash_id,user_id,plan_id,server_id,order_id,type,state,price,request_date) VALUES(4,'pgparallelA',1001,7,2,91,'INCREASE_VOLUME_91_1','processing',100,1700000000),(5,'pgparallelB',1001,7,2,91,'INCREASE_VOLUME_91_1','processing',100,1700000000)")
  call('pin',pay=4);call('pin',pay=5)
  server.capture_stale_once=True;server.stale_captured=threading.Event();server.release_stale=threading.Event()
  args=['php','-d','display_errors=0','-d','log_errors=0',str(script),str(ROOT),str(work/'mysql.sock'),db,json.dumps(dict(op='edit',pay=5,change={'plus_volume':1}))]
  before_writes=server.write_count
  b=subprocess.Popen(args,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
  try:
   assert server.stale_captured.wait(10),'B did not capture its initial observation'
   result_a=call('edit',pay=4,change={'plus_volume':1})
   yes(result_a['out']['success'] is False,'account_fence_owned_before_initial_GET_blocks_competing_A')
  finally:server.release_stale.set()
  out,err=b.communicate(timeout=20);assert not err and b.returncode==0,(out,err)
  result_b=json.loads(out)
  yes(result_b['out']['success'] is True and server.write_count==before_writes+1,'only_fence_owner_B_dispatches_absolute_change')
  sql("UPDATE provider_accounts SET state='linked',mutation_snapshot=JSON_SET(mutation_snapshot,'$.result','verified','$.current_group_ids',JSON_ARRAY(5)) WHERE order_id=91")
  prior_quota=server.user['data_limit']
  yes(call('edit',pay=4,change={'plus_volume':1})['out']['success'] is True and server.user['data_limit']==prior_quota+1073741824,'later_edit_observes_completed_prior_quota')

  sql("INSERT INTO pays(id,hash_id,user_id,plan_id,server_id,order_id,type,state,price,request_date) VALUES(3,'pgvolume',1001,7,2,91,'INCREASE_VOLUME_91_1','processing',100,1700000000)")
  call('pin',pay=3);server.mode='write_lost'
  writes=server.write_count
  r=call('edit',pay=3,change={'plus_volume':1});yes(r['out']['success'] is False and server.write_count==writes+1,'lost_write_is_uncertain')
  writes=server.write_count;server.mode='ok'
  r=call('edit',pay=3,change={'plus_volume':1});yes(r['out']['success'] is False and server.write_count==writes,'unknown_mutation_fence_prevents_retry')
  # Explicit operator reconciliation fixture releases fence; runtime never does this automatically.
  sql("UPDATE provider_accounts SET state='linked' WHERE order_id=91")
  yes(call('action',action='toggle',source=101)['out']['success'] is True and server.user['status']=='disabled','native_disable_verified')
  yes(call('action',action='toggle',source=102)['out']['success'] is True and server.user['status']=='active','native_enable_verified')
  server.mode='write_lost'
  r=call('action',action='toggle',source=106)
  yes(r['out']['success'] is False,'lost_toggle_is_uncertain')
  intent=json.loads(sql("SELECT mutation_snapshot FROM provider_accounts WHERE order_id=91").strip())
  yes(intent['changes']['expected']['status']=='disabled' and intent['changes']['before_status']=='active','lost_toggle_retains_durable_absolute_target')
  server.mode='ok'
  writes=server.write_count
  yes(call('action',action='toggle',source=107)['out']['success'] is False and server.write_count==writes,'unknown_toggle_blocks_new_source')
  sql("UPDATE provider_accounts SET state='linked' WHERE order_id=91")

  yes(call('action',action='reset',source=103)['out']['success'] is True and server.user['used_traffic']==0,'native_reset_verified')
  yes(call('action',action='revoke',source=104)['out']['success'] is True,'native_revoke_checks_proxy_identity')
  writes=server.write_count
  yes(call('action',action='toggle',source=101)['out']['success'] is False and server.write_count==writes,'nonadjacent_old_mutation_key_cannot_repeat')

  yes(call('action',action='delete',source=105)['out']['success'] is True and server.user is None,'native_delete_requires_by_id_404')
  writes=server.write_count;yes(call('action',action='delete',source=105)['out']['success'] is False and server.write_count==writes,'deleted_identity_cannot_repeat')
  r=call('groups',groups='2,5,2');assert 'out' in r,r;yes(r['out']==[2,5],'admin_groups_are_native_validated')
  yes('error' in call('groups',groups='999'),'admin_unknown_group_denied')
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

  for line,remote in [(1,19),(2,20)]:
   pg.USER['id']=remote
   r=call('bulk',line=line);yes('error' not in r,'bulk_line_'+str(line)+'_durable')
  posts=sum(t[0]=='POST' and t[1]=='/api/user' for t in server.trace)
  yes('error' in call('bulk',line=2) and sum(t[0]=='POST' and t[1]=='/api/user' for t in server.trace)==posts,'bulk_partial_source_cannot_reissue')

 report={'complete':True,'cases':len(checks),'checks':checks,'actual_php_curl':True,'owned_socket_mariadb':True,'real_provider_tested':False}
 if a.report:a.report.write_text(json.dumps(report,indent=2)+'\n')
 print(json.dumps(report))
if __name__=='__main__':main()
