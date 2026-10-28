#!/usr/bin/env python3
"""Native AChBot automatic renewal from valid unlimited-time Marzban and PasarGuard users.

Actual sweep/reservation/debit/native edit/readback/finalization are exercised.
Only owned socket MariaDB, loopback HTTP and synthetic identities are used.
"""
from __future__ import annotations
import argparse,copy,hashlib,io,json,subprocess,tarfile,tempfile,time
from pathlib import Path
from datetime import datetime
import test_access_state as owned
import test_v6_native as native
import test_v8_native as purchase
import test_post_v9_pasarguard_client as pg
import test_post_v9_marzban_edits as mb

ROOT=Path(__file__).resolve().parents[1]
BASELINE='2408982797af15bc99e1df2909c13be3976cb1f4'
DB=purchase.SIDE[1]
ORDER=731
REMOTE=73
PRICE=137
DAYS=30
VOLUME=3
MARKER='achbot:v1:'+'d'*32
PHP=r'''<?php
error_reporting(E_ALL);
set_error_handler(static function($n,$m){if($n===E_DEPRECATED)return true;throw new ErrorException($m,0,$n);});
$_SERVER['SCRIPT_FILENAME']=__FILE__;define('ACHBOT_PG_TEST_LOOPBACK',true);
$root=$argv[1];$spec=json_decode($argv[4],true,64,JSON_THROW_ON_ERROR);
$events=[];$admin=null;$botState=['renewAutoState'=>'off'];
$connection=new mysqli('localhost','root','',$argv[3],0,$argv[2]);$connection->set_charset('utf8mb4');
function sendMessage($message,$keys=null,$parse=null,$to=null){
 $GLOBALS['events'][]=['kind'=>'send','to'=>$to];return(object)['ok'=>true];
}
function getMarzbanUser(...$a){return \AchBot\Panel\Accounts\getMarzbanUser(...$a);}
function editMarzbanConfig(...$a){return \AchBot\Panel\Accounts\editMarzbanConfig(...$a);}
function achbotSupportsPanel($type){return achbotSupportsProviderType($type);}
require_once $root.'/src/Panel/Accounts.php';
require_once $root.'/src/Purchase/Core.php';
require_once $root.'/src/Finance/AutomaticRenewal.php';
try {
 if($spec['action']==='observe'){
  $observation=achbotServiceObserve(2,'unlimited_quota_user');
  $out=['observation'=>$observation,'policy'=>achbotServiceLifecycle($observation,time())];
 } elseif($spec['action']==='sweep'){
  $botState=['renewAutoState'=>$spec['enabled']??'on'];
  achbotAutoRenewSweep($connection,$botState);$out=['events'=>$events];
 } elseif($spec['action']==='changes'){
  try {$out=['accepted'=>true,'changes'=>achbotPgEditChanges($spec['user'],$spec['change'],$spec['clock']??1700000000)];}
  catch(Throwable $error){$out=['accepted'=>false];}
 } else throw new RuntimeException('fixture_action_missing');
 echo json_encode($out,JSON_THROW_ON_ERROR);
} catch(Throwable $error){echo json_encode(['fixture_error'=>$error->getMessage()],JSON_THROW_ON_ERROR);}
'''

def acceptance(root:Path=ROOT,baseline_only:bool=False,providers=('pasarguard','marzban')):
 checks=[];by_provider={provider:[]for provider in providers};active_provider=providers[0]
 def yes(ok,name):
  if not ok:raise AssertionError(name)
  checks.append(name if active_provider=='pasarguard' else 'marzban_'+name)
  by_provider[active_provider].append(name)
 def scenario(source:Path,baseline:bool,provider:str):
  nonlocal active_provider
  active_provider=provider;is_pg=provider=='pasarguard'
  label='baseline_240' if baseline else 'current'
  fixture=pg.panel if is_pg else mb.owned_edit_provider
  with owned.owned_database()as(work,env),fixture()as(server,url):
   purchase.init(work,env,url)
   def sql(statement):return native.sql(work,env,statement,DB).strip()
   for migration in ['001_marzban_panel_group_id.sql','004_automatic_renewal_attempts.sql',
                     '007_service_effects.sql','011_provider_identity.sql']:
    sql((source/'migrations/post-v9'/migration).read_text())
   endpoint=hashlib.sha256(url.encode()).hexdigest()
   entitlement=json.dumps(dict(data_limit=2147483648,expire=None,group_ids=[2,5],status='active'),separators=(',',':'))
   sql("UPDATE server_config SET type='"+provider+"' WHERE id=2;"
       "UPDATE server_plans SET days=30,volume=3,price=137,provider_group_ids='[5]' WHERE id=7;"
       "UPDATE users SET wallet=1000 WHERE userid=1001;"
       "INSERT INTO orders_list(id,userid,token,transid,fileid,server_id,inbound_id,remark,uuid,protocol,"
       "expire_date,link,amount,status,date,notif,autoRenew)"
       f" VALUES({ORDER},1001,'','',7,2,0,'unlimited_quota_user','native-unused','vless',0,'[]',137,1,'1700000000',1,'off');"
       "INSERT INTO setting(type,value) VALUES('AutoRenewLastOrderId','0')")
   if is_pg:
    sql("UPDATE server_info SET provider_panel_id=1,provider_group_ids='[5]' WHERE id=2;"
        "INSERT INTO provider_panels(id,provider,instance_key,name,api_url,api_key,version)"
        " VALUES(1,'pasarguard',REPEAT('b',32),'Unlimited time fixture','"+url+"','fixture-private-key','5.5.1');"
        "INSERT INTO provider_accounts(effect_id,order_id,provider,panel_id,instance_key,endpoint_sha256,"
        "remote_user_id,remote_created_at,remote_username,operation_marker,entitlement_snapshot,state)"
        f" VALUES(731,{ORDER},'pasarguard',1,REPEAT('b',32),'{endpoint}',{REMOTE},"
        "'2026-10-26T05:00:00.123456Z','unlimited_quota_user','"+MARKER+"','"+entitlement+"','linked')")
    server.user=copy.deepcopy(pg.USER)
    server.user.update(id=REMOTE,username='unlimited_quota_user',note=MARKER,expire=None,
        data_limit=2147483648,used_traffic=2047483648,group_ids=[2,5])
    remote=server.user
   else:
    server.mode='edit-reset-verified'
    server.edit_account=copy.deepcopy(mb.INITIAL)
    server.edit_account.update(username='unlimited_quota_user',expire=None,
        data_limit=2147483648,used_traffic=2047483648,
        inbounds={'vless':['preserved-fixture-inbound']},
        proxies={'vless':{'id':'preserved-fixture-identity'}},note='preserved-renewal-note')
    server.edit_put_count=0;server.edit_reset_count=0
    remote=server.edit_account
   driver=work/'actual-unlimited-renewal.php';driver.write_text(PHP)
   def call(action,**spec):
    proc=subprocess.run(['php','-d','display_errors=0','-d','log_errors=0',
       str(driver),str(source),str(work/'mysql.sock'),DB,
       json.dumps(dict(action=action,**spec))],env=env,cwd=work,capture_output=True,text=True,timeout=30)
    if proc.returncode or proc.stderr:raise AssertionError((label,'php_child',proc.returncode,proc.stderr[:1000]))
    out=json.loads(proc.stdout)
    if 'fixture_error'in out:raise AssertionError((label,out))
    return out
   def financial():
    return sql("SELECT * FROM users ORDER BY id;SELECT * FROM orders_list ORDER BY id;"
      "SELECT * FROM pays ORDER BY id;SELECT * FROM automatic_renewal_attempts ORDER BY id;"
      "SELECT * FROM provider_accounts ORDER BY id;SELECT * FROM increase_order ORDER BY id")
   def writes():
    if is_pg:return [item for item in server.trace if item[0]!='GET']
    return [(item['method'],item['path'],json.loads(item['body'])if item['body']else None)
            for item in server.trace if item['method']!='GET' and item['path']!='/api/admin/token']
   before=financial();requests=len(server.trace)
   call('sweep')
   yes(financial()==before and len(server.trace)==requests,label+'_account_consent_off_no_money_claim_or_provider_read')
   sql(f"UPDATE orders_list SET autoRenew='on' WHERE id={ORDER}")
   before=financial();requests=len(server.trace);call('sweep',enabled='off')
   yes(financial()==before and len(server.trace)==requests,label+'_global_off_no_money_claim_or_provider_read')
   observed=call('observe');observation=observed['observation']
   yes(observation['verified']and observation['expire']is None and observation['remaining']==100000000
       and observed['policy']['renew']is True and observed['policy']['warning']=='quota',
       label+'_actual_native_NULL_expiry_low_quota_authorizes_common_policy')
   identity_keys=['id','username','created_at','note']if is_pg else [
       'username','note','inbounds','proxies','data_limit_reset_strategy']
   def identity():return json.dumps({key:remote[key]for key in identity_keys},sort_keys=True)
   preserved_identity=identity()
   started=int(time.time());call('sweep');finished=int(time.time())
   yes(sql('SELECT wallet FROM users WHERE userid=1001')==str(1000-PRICE),
       label+'_actual_sweep_reserves_exactly_one_wallet_debit')
   yes(sql('SELECT COUNT(*),MIN(price_toman),MAX(price_toman) FROM automatic_renewal_attempts')==f'1\t{PRICE}\t{PRICE}',
       label+'_actual_sweep_owns_one_durable_cycle_claim')
   if baseline:
    yes(sql('SELECT state FROM pays')=='processing_wallet'
        and sql('SELECT stage,reason_code FROM automatic_renewal_attempts')=='review\tremote_unconfirmed'
        and (not is_pg or sql(f'SELECT state FROM provider_accounts WHERE order_id={ORDER}')=='mutating')
        and sql(f'SELECT expire_date,notif FROM orders_list WHERE id={ORDER}')=='0\t1'
        and not writes() and remote['expire']is None,
        'baseline_240_actual_native_sweep_RED_holds_debit_and_mutation_for_review_before_write')
    before=financial();requests=len(server.trace);call('sweep')
    yes(financial()==before and (not is_pg or len(server.trace)==requests) and not writes(),
        'baseline_240_ambiguous_review_never_redebits_or_replays')
    return
   remote_expiry=remote['expire']
   expiry=int(datetime.fromisoformat(remote_expiry.replace('Z','+00:00')).timestamp())if is_pg else remote_expiry
   yes(isinstance(expiry,int)and started+DAYS*86400<=expiry<=finished+DAYS*86400,
       'current_native_full_renewal_replaces_NULL_with_verified_finite_future_expiry')
   yes(remote['data_limit']==VOLUME*1073741824 and remote['used_traffic']==0
       and (not is_pg or remote['group_ids']==[2,5]) and preserved_identity==identity(),
       'current_native_readback_proves_exact_quota_reset_and_preserved_identity_and_groups'
       if is_pg else 'current_native_readback_proves_exact_quota_reset_and_preserved_configuration')
   actual_writes=writes()
   account_path=f'/api/user/by-id/{REMOTE}'if is_pg else '/api/user/unlimited_quota_user'
   yes([(x[0],x[1])for x in actual_writes]==[
       ('POST',account_path+'/reset'),('PUT',account_path)],
       'current_native_writes_exactly_one_reset_and_one_absolute_modify')
   payload=actual_writes[1][2]
   yes(payload==dict(data_limit=VOLUME*1073741824,expire=remote_expiry),
       'current_native_modify_contains_only_finite_paid_limits_without_group_replacement')
   yes(sql('SELECT state,type,price,order_id FROM pays')==f'paid_with_wallet\tRENEW_ACCOUNT\t{PRICE}\t{ORDER}'
       and sql('SELECT stage FROM automatic_renewal_attempts')=='done'
       and sql(f'SELECT expire_date,notif FROM orders_list WHERE id={ORDER}')==f'{expiry}\t0'
       and (not is_pg or sql(f'SELECT state FROM provider_accounts WHERE order_id={ORDER}')=='linked'),
       'current_actual_finalize_commits_payment_attempt_order_and_account_completion')
   yes(sql('SELECT COUNT(*),MIN(amount),MAX(amount) FROM increase_order')==f'1\t{PRICE}\t{PRICE}',
       'current_actual_finalize_records_one_exact_accounting_row')
   if is_pg:
    journal=json.loads(sql(f'SELECT mutation_snapshot FROM provider_accounts WHERE order_id={ORDER}'))
    yes(journal['result']=='verified' and journal['current_group_ids']==[2,5]
        and journal['changes']['before']['expire']is None
        and journal['changes']['absolute']['expire']==expiry
        and len(journal['consumed_keys'])==1 and journal['consumed_keys'][0].startswith('autorenew:'),
        'current_account_fence_retains_NULL_before_state_and_one_verified_consumed_source')
    pinned=json.loads(sql('SELECT provider_snapshot FROM pays'))
    yes(pinned['provider']=='pasarguard' and pinned['remote_user_id']==REMOTE
        and pinned['operation_marker']==MARKER and pinned['group_ids']==[2,5],
        'current_actual_reservation_retains_native_account_quote_pin')
   before=financial();requests=len(server.trace);write_count=len(writes());call('sweep')
   yes(financial()==before and len(server.trace)==requests and len(writes())==write_count,
       'current_second_actual_tick_has_no_debit_claim_or_native_replay')
   # A later warning re-enables scan eligibility; fresh high-quota/finite-time
   # observation also prevents a second charge through the common policy.
   sql(f'UPDATE orders_list SET notif=1 WHERE id={ORDER}')
   before=financial();write_count=len(writes());call('sweep')
   yes(financial()==before and len(writes())==write_count,
       'current_rescanned_renewed_native_account_has_no_additional_charge_or_write')
   if not is_pg:return
   base_user=dict(expire=None,data_limit=2147483648,status='active')
   cases=[
    ('NULL_full_days',dict(days=30,volume=3),None,True),
    ('NULL_days_only_replacement',dict(days=30),None,True),
    ('NULL_plus_day',dict(plus_day=1),None,False),
    ('NULL_plus_volume',dict(plus_volume=1),None,False),
    ('NULL_volume_only',dict(volume=3),None,False),
    ('NULL_days_null',dict(days=None,volume=3),None,False),
    ('NULL_days_zero',dict(days=0,volume=3),None,False),
    ('NULL_days_negative',dict(days=-1,volume=3),None,False),
    ('NULL_days_fractional',dict(days=0.5,volume=3),None,False),
    ('NULL_days_malformed',dict(days='bad',volume=3),None,False),
    ('NULL_conflicting_day_axes',dict(days=30,plus_day=1),None,False),
    ('zero_current_expiry',dict(days=30,volume=3),0,False),
    ('fractional_current_expiry',dict(days=30,volume=3),1.5,False),
    ('string_current_expiry',dict(days=30,volume=3),'2000000000',False),
    ('boolean_current_expiry',dict(days=30,volume=3),False,False),
    ('negative_current_expiry',dict(days=30,volume=3),-1,False),
    ('finite_plus_day_unchanged',dict(plus_day=1),2000000000,True),
    ('finite_volume_only_unchanged',dict(volume=3),2000000000,True),
    ('NULL_invalid_volume',dict(days=30,volume=None),None,False),
   ]
   before=financial();requests=len(server.trace)
   for name,change,current_expiry,want in cases:
    result=call('changes',user=dict(base_user,expire=current_expiry),change=change)
    yes(result['accepted']is want,'current_bounded_expiry_input_'+name)
   result=call('changes',user=base_user,change=dict(days=1),clock=2147483600)
   yes(result['accepted']is False,'current_NULL_replacement_overflow_remains_denied')
   yes(financial()==before and len(server.trace)==requests,
       'current_pure_admission_negatives_make_no_SQL_or_native_effect')
 with tempfile.TemporaryDirectory(prefix='achbot-null-expiry-baseline-')as(directory):
  baseline=Path(directory)
  blob=subprocess.check_output(['git','archive',BASELINE],cwd=root)
  with tarfile.open(fileobj=io.BytesIO(blob))as archive:archive.extractall(baseline,filter='data')
  for provider in providers:scenario(baseline,True,provider)
 if not baseline_only:
  for provider in providers:scenario(root,False,provider)
 def proof(provider):
  out=dict(complete=True,cases=len(by_provider[provider]),actual_php=True,owned_socket_mariadb=True,
    actual_financial_dispatch=True,baseline_red=True,baseline_source=BASELINE,current_green=not baseline_only,
    null_expiry_native_renewal_verified=not baseline_only,consent_off_verified=True,
    wallet_debit_once=not baseline_only,
    completed_payment_attempt_and_order=not baseline_only,no_tick_replay=not baseline_only,
    external_requests=0,production_mutations=0)
  if provider=='pasarguard':
   out.update(loopback_native_pasar_guard=True,null_expiry_extension_scope_preserved=not baseline_only,
              native_identity_groups_and_quote_pins_preserved=not baseline_only)
  else:out.update(loopback_native_marzban=True,native_configuration_preserved=not baseline_only)
  return out
 return dict(complete=True,cases=len(checks),checks=checks,actual_php=True,owned_socket_mariadb=True,
    actual_financial_dispatch=True,baseline_red=True,baseline_source=BASELINE,current_green=not baseline_only,
    null_expiry_native_renewal_verified=not baseline_only,consent_off_verified=True,
    wallet_debit_once=not baseline_only,completed_payment_attempt_and_order=not baseline_only,
    no_tick_replay=not baseline_only,external_requests=0,production_mutations=0,
    providers={provider:proof(provider)for provider in providers},
    both_adapters_verified=not baseline_only and set(providers)=={'marzban','pasarguard'})

def main():
 parser=argparse.ArgumentParser();parser.add_argument('--run-disposable',action='store_true',required=True)
 parser.add_argument('--baseline-only',action='store_true');parser.add_argument('--report',type=Path)
 parser.add_argument('--provider',choices=['pasarguard','marzban','both'],default='both');args=parser.parse_args()
 providers=('pasarguard','marzban')if args.provider=='both'else(args.provider,)
 report=acceptance(ROOT,args.baseline_only,providers)
 if args.report:args.report.write_text(json.dumps(report,indent=2)+'\n')
 print(json.dumps(report))
if __name__=='__main__':main()
