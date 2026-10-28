#!/usr/bin/env python3
"""Current runtime cleanup, shared add-on catalogue and exact existing-account import."""
from __future__ import annotations
import argparse,json,subprocess,time,sys
from pathlib import Path
import test_access_state as owned
import test_v6_native as native
import test_v8_native as purchase
import test_post_v9_service_unit_guards as units
ROOT=Path(__file__).resolve().parents[1]
DB=purchase.SIDE[1]
PHP=units.PHP.replace("$GLOBALS['events']=[];","$GLOBALS['spec']=$spec;$GLOBALS['events']=[];").replace("$buttonValues=['cancel'=>'Cancel','back_to_main'=>'Home','pay_with_wallet'=>'Wallet'];","$buttonValues=['cancel'=>'Cancel','back_to_main'=>'Home','back_button'=>'Back','my_subscriptions'=>'My services','pay_with_wallet'=>'Wallet'];")
PHP=PHP.replace("function achbotSupportsPanel($type)","function getMarzbanUser(...$args){++$GLOBALS['provider_reads'];return json_decode(json_encode($GLOBALS['spec']['remote']??null));}\n$GLOBALS['provider_reads']=0;\nfunction achbotSupportsPanel($type)")
PHP=PHP.replace("if (($spec['route']??'extensions')==='claim') {",r"""if (isset($spec['frame'])) $update=json_decode(json_encode($spec['frame']));
if (($spec['route']??'')==='proof') {
 require $root.'/src/Subscription/ExternalAccounts.php';
 $GLOBALS['events'][]=['event'=>'PROOF','value'=>achbotExternalImportProof($spec['server'],json_decode(json_encode($spec['remote'])),$spec['input'])];
} elseif (($spec['route']??'')==='import') {
 require $root.'/src/Subscription/ExternalAccounts.php';
 require $root.'/src/Subscription/ManualAccounts.php';
} elseif (($spec['route']??'extensions')==='claim') {""")
def main():
 p=argparse.ArgumentParser();p.add_argument('--run-disposable',action='store_true',required=True);p.add_argument('--report',type=Path);a=p.parse_args()
 checks=[]
 def yes(value,label):
  if not value:raise AssertionError(label)
  checks.append(label)
 retired=['settings/subLink.php','settings/values.php','settings/messagewizwiz.php','settings/warnusers.php','pay/index.php','src/Finance/GatewayInitiation.php']
 for rel in retired:yes(not (ROOT/rel).exists(),'obsolete_alias_removed_'+rel)
 settings={'cronRunner.php','gift2all.php','jdf.php','rewardReport.php'}
 yes({x.name for x in (ROOT/'settings').glob('*.php')}==settings,'four_actual_settings_implementations_retained')
 yes({x.name for x in (ROOT/'pay').glob('*.php')}=={'checkout.php','back.php'},'canonical_supported_public_payment_entrypoints_present')
 for rel in ['pay/back.php']:
  old=subprocess.check_output(['git','show','2c0e728:'+rel],cwd=ROOT)
  yes((ROOT/rel).read_bytes()==old,'payment_entrypoint_preserved_'+Path(rel).name)
 checkout=(ROOT/'pay/checkout.php').read_text()
 yes('function achbotReserveCheckoutProvider(' in checkout and 'function achbotRecordCheckoutReference(' in checkout,'canonical_checkout_contains_actual_implementation')
 runner=(ROOT/'settings/cronRunner.php').read_text()
 for worker in ['gift2all.php','rewardReport.php']:
  yes(("'name'=>'"+worker+"'") in runner and (ROOT/'settings'/worker).is_file(),'cron_actual_worker_kept_'+worker)
 for mode in ['broadcast','services']:
  yes(("'name'=>'"+mode+"'") in runner and ("--job="+mode) in runner,'canonical_coordinator_child_composition_'+mode)
 with owned.owned_database() as (w,e):
  purchase.init(w,e,'https://panel.example.invalid')
  def sql(q):return native.sql(w,e,q,DB).strip()
  sql((ROOT/'migrations/post-v9/001_marzban_panel_group_id.sql').read_text())
  sql("UPDATE server_config SET panel_url='https://panel.example.invalid',sub_links='https://sub.example.invalid' WHERE id=2")
  sql("INSERT INTO increase_day(id,volume,price) VALUES(61,5,50000)")
  sql("INSERT INTO increase_plan(id,volume,price) VALUES(51,10,50000)")
  driver=w/'runtime-cleanup-under-test.php';driver.write_text(PHP)
  def call(**spec):
   r=subprocess.run(['php','-d','display_errors=0','-d','log_errors=0',str(driver),str(ROOT),str(w/'mysql.sock'),DB,json.dumps(spec)],capture_output=True,text=True,timeout=20)
   if r.returncode or any(not line.startswith(('achbot_existing_subscription_import_deferred reason=','achbot_interval_cancel_deferred')) for line in r.stderr.splitlines()):raise AssertionError((spec,r.returncode,r.stdout,r.stderr))
   yes(True,'native_request_'+str(len(checks)))
   out=json.loads(r.stdout);out['diagnostic']=r.stderr;return out
  def catalogue():return sql('SELECT * FROM increase_day ORDER BY id;SELECT * FROM increase_plan ORDER BY id')
  for step,table in [('addNewDayPlan','increase_day'),('addNewVolumePlan','increase_plan')]:
   for value in ['10--1','10-1.5','10-1e3','10-2147483648','10-no','10-','0-100','1.5-100','10']:
    sql("UPDATE users SET step='"+step+"',temp=NULL WHERE userid=9001")
    before=catalogue();out=call(route='admin',actor=9001,text=value)
    yes(catalogue()==before and out['provider_writes']==0,'addon_invalid_create_'+step+'_'+value)
  for step,table,row in [('changeDayPlanPrice61','increase_day',61),('changeVolumePlanPrice51','increase_plan',51)]:
   for value in ['-1','1.5','1e3','2147483648','no']:
    sql("UPDATE users SET step='"+step+"',temp=NULL WHERE userid=9001")
    before=catalogue();call(route='admin',actor=9001,text=value)
    yes(catalogue()==before,'addon_invalid_price_edit_'+step+'_'+value)
  for value in ['-1','0','1.5','36501']:
   sql("UPDATE users SET step='changeDayPlanDay61',temp=NULL WHERE userid=9001")
   before=catalogue();call(route='admin',actor=9001,text=value)
   yes(catalogue()==before,'addon_invalid_day_edit_'+value)
  sql("UPDATE users SET step='changeDayPlanDay61',temp=NULL WHERE userid=9001")
  call(route='admin',actor=9001,text='7')
  yes(sql('SELECT volume FROM increase_day WHERE id=61')=='7','addon_whole_day_edit_remains_available')
  sql("UPDATE users SET step='changeVolumePlanPrice51',temp=NULL WHERE userid=9001")
  call(route='admin',actor=9001,text='75000')
  yes(sql('SELECT price FROM increase_plan WHERE id=51')=='75000','addon_integer_price_edit_remains_available')
  sql("UPDATE users SET step='changeDayPlanPrice61',temp=NULL,isAdmin=0 WHERE userid=1001")
  before=catalogue();call(route='admin',actor=1001,text='50000')
  yes(catalogue()==before,'nonadmin_day_price_edit_denied')
  for step,expected in [('addNewDayPlan','addNewDayPlan'),('changeDayPlanPrice61','addNewDayPlan'),('addNewVolumePlan','addNewVolumePlan'),('changeVolumePlanVolume51','addNewVolumePlan')]:
   sql("UPDATE users SET step='"+step+"',temp=NULL WHERE userid=9001")
   frame={'update_id':900,'message':{'message_id':702,'text':'Cancel','from':{'id':9001},'chat':{'id':9001,'type':'private'}}}
   out=call(route='admin',actor=9001,text='Cancel',frame=frame)
   yes(sql('SELECT step FROM users WHERE userid=9001')=='none' and expected in json.dumps(out) and 'backplan' in json.dumps(out),'single_press_contextual_addon_cancel_'+step)
  server={'type':'marzban','panel_url':'https://panel.example.invalid','sub_links':'https://sub.example.invalid'}
  expires=int(time.time())+86400*23
  remote={'username':'fixture_import','status':'active','used_traffic':10,'data_limit':10737418240,'expire':expires,'subscription_url':'https://panel.example.invalid/sub/native-secret','links':['vless://fixture-secret@node.example.invalid:443#Fixture'],'proxies':{'vless':{'id':'fixture-native-uuid'}}}
  for inp,kind in [('https://panel.example.invalid/sub/native-secret','native_origin'),('https://sub.example.invalid/sub/native-secret','explicit_sub_alias')]:
   out=call(route='proof',server=server,remote=remote,input=inp)
   yes(out['events'][-1]['value']['expiry']==expires,'import_proof_'+kind)
  for inp,label in [('https://panel.example.invalid/sub/wrong','wrong_token'),('https://foreign.example.invalid/sub/native-secret','wrong_origin'),('https://panel.example.invalid.evil.invalid/sub/native-secret','host_suffix'),('https://name:password@panel.example.invalid/sub/native-secret','url_credentials')]:
   out=call(route='proof',server=server,remote=remote,input=inp)
   yes(out['events'][-1]['value'] is None,'import_rejects_'+label)
  pg=dict(server,type='pasarguard')
  yes(call(route='proof',server=pg,remote=remote,input=remote['subscription_url'])['events'][-1]['value'] is None,'import_never_invents_native_pg_identity')
  def draft(user=1001,server_id=2,link=None,custom=None):
   d={'remark':'fixture_import','server_id':server_id,'uuid':'legacy_hint','import_url':link or remote['subscription_url']}
   if custom is not None:d['custom_userid']=custom
   value=json.dumps(d).replace("'","''")
   sql("UPDATE users SET step='none',temp='"+value+"' WHERE userid="+str(user))
  sql("INSERT INTO add_link(remark,token,added_by) VALUES('fixture_import','native-secret',1001),('fixture_import','native-secret',9001)")
  before_pays=sql('SELECT * FROM pays ORDER BY id;SELECT userid,wallet FROM users ORDER BY userid')
  draft(link='https://foreign.example.invalid/sub/native-secret')
  call(route='import',data='addToOrders2',remote=remote)
  yes(sql("SELECT COUNT(*) FROM orders_list WHERE remark='fixture_import'")=='0','bad_import_origin_creates_no_order')
  draft(custom=9001)
  call(route='import',data='addToOrders2',remote=remote)
  yes(sql("SELECT COUNT(*) FROM orders_list WHERE remark='fixture_import'")=='0','ordinary_customer_cannot_import_for_another_user')
  draft()
  call(route='import',data='addToOrders3',remote=remote)
  yes(sql("SELECT COUNT(*) FROM orders_list WHERE remark='fixture_import'")=='0','forged_import_server_callback_cannot_fallback_by_prefix')
  draft()
  observed=call(route='import',data='addToOrders2',remote=remote)
  yes(sql("SELECT COUNT(*) FROM orders_list WHERE remark='fixture_import' AND server_id=2 AND userid=1001 AND expire_date="+str(expires))=='1','exact_proven_existing_account_import_uses_native_expiry')
  yes(sql("SELECT token FROM orders_list WHERE remark='fixture_import'")=='native-secret','import_uses_native_credential_not_old_temp_hint')
  yes(sql("SELECT step FROM users WHERE userid=1001")=='none' and sql("SELECT IF(temp IS NULL,1,0) FROM users WHERE userid=1001")=='1','successful_import_consumes_owned_draft')
  call(route='import',data='addToOrders2',remote=remote)
  yes(sql("SELECT COUNT(*) FROM orders_list WHERE remark='fixture_import'")=='1','import_replay_creates_no_duplicate')
  draft(user=9001,custom=1001)
  call(route='import',actor=9001,data='addToOrders2',remote=remote)
  yes(sql("SELECT COUNT(*) FROM orders_list WHERE remark='fixture_import'")=='1','second_actor_cannot_alias_existing_same_server_account')
  yes(sql('SELECT * FROM pays ORDER BY id;SELECT userid,wallet FROM users ORDER BY userid')==before_pays,'import_has_no_payment_wallet_or_credit_effect')
 report={'complete':True,'assertions':len(checks),'checks':checks,'actual_php':True,'owned_socket_mariadb':True,'loopback_or_injected_provider_reads_only':True,'provider_mutations':0,'production_mutations':0,'removed_runtime_files':retired,'retained_pay_files':['pay/checkout.php','pay/back.php'],'external_pg_adoption':'unsupported_without_separate_stable_identity_contract'}
 if a.report:a.report.write_text(json.dumps(report,indent=2)+'\n')
 print(json.dumps(report))
if __name__=='__main__':main()
