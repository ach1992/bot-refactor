#!/usr/bin/env python3
"""AChBot explicit imported-reference origin on the original23-table schema.

Uses actual OrderWrites PHP and owned socket-only MariaDB. It does not prove a
native bearer: provider adapters own that separate proof before calling this writer.
"""
from __future__ import annotations
import argparse,json,subprocess
from pathlib import Path
import test_access_state as owned
import test_v6_native as native
import test_v8_native as purchase
ROOT=Path(__file__).resolve().parents[1]
DB=purchase.SIDE[1]
PHP=r'''<?php
error_reporting(E_ALL);
set_error_handler(static function($n,$m){if($n===E_DEPRECATED)return true;throw new ErrorException($m,0,$n);});
$_SERVER['SCRIPT_FILENAME']=__FILE__;
$root=$argv[1];$spec=json_decode($argv[4],true,32,JSON_THROW_ON_ERROR);
$db=new mysqli('localhost','root','',$argv[3],0,$argv[2]);$db->set_charset('utf8mb4');
function achbotSupportsProviderType($provider){return in_array($provider,['marzban','pasarguard'],true);}
require_once $root.'/src/Purchase/OrderWrites.php';
$begun=false;$id=(int)($spec['id']??101);
try {
 if($spec['op']==='locked_snapshot') {
  $db->begin_transaction();$begun=true;
  $original=achbotImportedOrderOrigin($db,$id);
  $order=$db->query('SELECT * FROM orders_list WHERE id='.$id)->fetch_assoc();
  $other=new mysqli('localhost','root','',$argv[3],0,$argv[2]);$other->set_charset('utf8mb4');
  $key=achbotServiceOriginKey($id);$poison=json_encode(array_merge($original,['capacity_reserved'=>true]),JSON_THROW_ON_ERROR);
  $q=$other->prepare('UPDATE setting SET value=? WHERE type=?');$q->bind_param('ss',$poison,$key);$q->execute();$q->close();
  try {$allowed=achbotOrderMayUsePaidPlan($db,$order,true);$out=['unexpected'=>$allowed];}
  catch(Throwable$error){$out=['held'=>true,'reason'=>$error->getMessage()];}
  finally {
   $db->rollback();$begun=false;$saved=json_encode($original,JSON_THROW_ON_ERROR);
   $q=$other->prepare('UPDATE setting SET value=? WHERE type=?');$q->bind_param('ss',$saved,$key);$q->execute();$q->close();$other->close();
  }
 } elseif($spec['op']==='write') {
  if(($spec['begin']??true)===true){$db->begin_transaction();$begun=true;}
  if(!empty($spec['new_order'])) {
   $id=insertOrder($db,(int)$spec['owner'],'synthetic-token',0,(int)$spec['server'],0,
    $spec['remark'],'synthetic-uuid','',0,'[]',0,time(),0,0,true);
  }
  $value=achbotRecordImportedOrderOriginLocked($db,$id,(int)$spec['owner'],(int)$spec['actor'],
   $spec['provider'],(int)$spec['server'],$spec['remark'],$spec['proof'],$spec['identity'],$spec['account']??null);
  if(!empty($spec['fail_after']))throw new RuntimeException('owned_failure_after_origin');
  if($begun){($spec['commit']??true)?$db->commit():$db->rollback();$begun=false;}
  $out=['origin'=>$value,'id'=>$id];
 } else {
  $q=$db->prepare('SELECT * FROM orders_list WHERE id=?');$q->bind_param('i',$id);$q->execute();
  $order=$q->get_result()->fetch_assoc();$q->close();
  if(!$order)throw new RuntimeException('fixture_order_missing');
  foreach($spec['override']??[]as$key=>$value)$order[$key]=$value;
  $out=['raw'=>achbotImportedOrderOrigin($db,$id),'bound'=>achbotOrderImportOrigin($db,$order),
   'imported'=>achbotOrderIsImported($db,$order),'paid_plan'=>achbotOrderMayUsePaidPlan($db,$order)];
 }
 echo json_encode(['out'=>$out],JSON_THROW_ON_ERROR);
} catch(Throwable$error) {
 if($begun)$db->rollback();
 echo json_encode(['error'=>$error instanceof RuntimeException?$error->getMessage():get_class($error),'id'=>$id],JSON_THROW_ON_ERROR);
}
'''

def main():
 p=argparse.ArgumentParser();p.add_argument('--run-disposable',action='store_true',required=True);p.add_argument('--report',type=Path);a=p.parse_args();checks=[]
 def yes(value,label):
  if not value:raise AssertionError(label)
  checks.append(label)
 with owned.owned_database()as(work,env):
  purchase.init(work,env,'http://127.0.0.1:9')
  def sql(q):return native.sql(work,env,q,DB).strip()
  schema=sql('SHOW TABLES')
  sql("INSERT INTO server_info(id,title,ucount,remark,flag,active,state) VALUES(3,'Owned PG',10,'pg','',1,1);UPDATE server_config SET type='pasarguard',panel_url='https://native.example.invalid' WHERE id=3")
  def order(i,owner=1001,remark=None,amount=0,plan=0):
   name=remark or 'origin_fixture_'+str(i)
   sql("INSERT INTO orders_list(id,userid,token,transid,fileid,server_id,inbound_id,remark,uuid,protocol,expire_date,link,amount,status,date,autoRenew) "
       f"VALUES({i},{owner},'synthetic-token','',{plan},2,0,'{name}','synthetic-uuid','',0,'[]',{amount},1,'1700000000','off')")
   return name
  for i in range(101,111):order(i,owner=1002 if i==103 else 1001)
  order(111,amount=100,plan=7);order(112,amount=0,plan=7)
  sql('UPDATE server_config SET type=\'marzban\' WHERE id=2;UPDATE orders_list SET server_id=3 WHERE id IN(102,103)')
  driver=work/'order-origin.php';driver.write_text(PHP)
  def call(op='read',**spec):
   r=subprocess.run(['php','-d','display_errors=0','-d','log_errors=0','-d','allow_url_fopen=0',
    '-d','disable_functions=curl_init,fsockopen,pfsockopen,stream_socket_client,exec,shell_exec,system,passthru,popen,proc_open,mail',
    str(driver),str(ROOT),str(work/'mysql.sock'),DB,json.dumps(dict(op=op,**spec))],capture_output=True,text=True,timeout=15)
   if r.returncode or r.stderr:raise AssertionError((r.returncode,r.stdout,r.stderr))
   return json.loads(r.stdout)
  def write(i=101,**extra):
   provider='pasarguard'if i in(102,103)else'marzban';remark='origin_fixture_'+str(i)
   identity={'remote_created_at':'2026-10-20T10:00:00.000000Z','remote_username':remark}
   if provider=='pasarguard':identity['remote_user_id']=41
   spec=dict(id=i,owner=1002 if i==103 else 1001,actor=9001,provider=provider,server=3 if provider=='pasarguard' else 2,
    remark=remark,proof='a'*64,identity=identity,account=31 if provider=='pasarguard' else None)
   spec.update(extra);return call('write',**spec)
  def origin_rows():return sql("SELECT id,type,value FROM setting WHERE type LIKE 'ACHBOT_SERVICE_ORIGIN:%' ORDER BY id")
  finance=sql('SELECT * FROM pays ORDER BY id;SELECT userid,wallet FROM users ORDER BY id;SELECT id,ucount FROM server_info ORDER BY id')
  yes(call(id=111)['out']['paid_plan']is True,'absent_origin_preserves_legitimate_positive_paid_order')
  trial=call(id=112)['out'];yes(trial['raw']is None and not trial['imported']and not trial['paid_plan'],'amount_zero_trial_is_not_guessed_to_be_an_import')
  for invalid in [0,-1,False,3.5,'1e3',' 1','01','2147483648',None]:
   yes(call(id=111,override={'amount':invalid})['out']['paid_plan']is False,'malformed_or_zero_price_does_not_become_paid_'+repr(invalid))
  before=origin_rows();r=write(begin=False)
  yes(r.get('error')=='service_origin_transaction_required'and origin_rows()==before,'origin_write_requires_existing_transaction')
  for extra in [dict(owner=1002),dict(remark='different'),dict(server=3),dict(provider='unknown'),dict(proof='not-a-proof'),dict(identity={'remote_created_at':'bad','remote_username':'origin_fixture_101'}),dict(identity={'remote_created_at':'2026-10-20T10:00:00.000000Z','remote_username':'origin_fixture_101','token':'secret'})]:
   yes('error'in write(**extra)and origin_rows()==before,'invalid_writer_input_or_order_has_no_partial_origin_'+str(len(checks)))
  for column,value in [('fileid','7'),('amount','1'),('status','0'),('inbound_id','1'),('autoRenew',"'on'")]:
   sql(f'UPDATE orders_list SET {column}={value} WHERE id=101');yes('error'in write()and origin_rows()==before,'initial_order_contract_rejects_'+column)
   sql(f"UPDATE orders_list SET {column}="+("'off'"if column=='autoRenew'else'1'if column=='status'else'0')+' WHERE id=101')
  saved=write()['out']['origin'];read=call()['out']
  yes(read['raw']==saved and read['bound']==saved and read['imported']and read['paid_plan'],'actual_committed_MB_origin_readback_enables_paid_plan_without_inventing_amount')
  yes(saved['capacity_reserved']is False and saved['provider_account_id']is None and sql('SELECT amount FROM orders_list WHERE id=101')=='0','origin_has_explicit_zero_capacity_and_no_fake_payment')
  yes('synthetic-token'not in json.dumps(saved)and 'https://'not in json.dumps(saved),'persisted_origin_contains_no_bearer_or_credentials')
  yes(write().get('error')=='service_origin_already_recorded','same_order_origin_cannot_be_written_twice')
  locked=call('locked_snapshot',id=101)
  yes(locked.get('out',{}).get('held')is True and call()['out']['bound']==saved,'locked_origin_classifier_sees_current_corruption_after_earlier_repeatable_read_snapshot')
  pg=write(102)['out']['origin'];sql("UPDATE orders_list SET remark='origin_fixture_102' WHERE id=103")
  shared=write(103,remark='origin_fixture_102',identity=pg['native_identity'])['out']['origin']
  yes(pg['provider_account_id']==shared['provider_account_id']==31 and pg['owner_userid']!=shared['owner_userid'],'different_local_owners_may_reference_one_physical_PG_account')
  sql("UPDATE orders_list SET fileid=7,token='rotated-token',amount=0,server_id=3 WHERE id=101")
  yes(call()['out']['bound']==saved and call()['out']['paid_plan']and call()['out']['imported'],'origin_survives_plan_selection_token_rotation_and_logical_routing_without_amount_fabrication')
  for override in [{'userid':1002},{'remark':'foreign'}]:
   yes(call(override=override).get('error')=='service_origin_order_changed','origin_cannot_authorize_foreign_reference_'+next(iter(override)))
  key='ACHBOT_SERVICE_ORIGIN:101'
  def replace(value):
   raw=json.dumps(value).encode().hex();sql(f"UPDATE setting SET value=CONVERT(UNHEX('{raw}') USING utf8mb4) WHERE type='{key}'")
  for change in [dict(version=2),dict(order_id=102),dict(owner_userid='1001'),dict(actor_userid=0),dict(provider=''),dict(capacity_reserved=True),dict(proof_sha256='wrong'),dict(provider_account_id=-1),dict(unexpected='field')]:
   replace(dict(saved,**change));yes('error'in call(override={'amount':100}),'malformed_present_origin_never_falls_back_to_paid_'+next(iter(change)))
  replace(saved)
  sql(f"INSERT INTO setting(type,value) SELECT type,value FROM setting WHERE type='{key}'")
  yes(call().get('error')=='service_origin_record_invalid','duplicate_origin_rows_are_ambiguous_not_legacy')
  sql(f"DELETE FROM setting WHERE type='{key}' ORDER BY id DESC LIMIT 1")
  before_orders=sql('SELECT id FROM orders_list ORDER BY id');before_origins=origin_rows()
  rolled=write(104,new_order=True,fail_after=True)
  yes(rolled.get('error')=='owned_failure_after_origin'and sql('SELECT id FROM orders_list ORDER BY id')==before_orders and origin_rows()==before_origins,'order_and_origin_roll_back_together_after_injected_postwrite_failure')
  yes(sql('SELECT * FROM pays ORDER BY id;SELECT userid,wallet FROM users ORDER BY id;SELECT id,ucount FROM server_info ORDER BY id')==finance,'origin_helpers_never_mutate_payments_wallets_or_capacity')
  yes(sql('SHOW TABLES')==schema and not sql("SHOW TABLES LIKE 'service_effects'")and not sql("SHOW TABLES LIKE 'provider_accounts'"),'original23_storage_support_requires_no_new_schema_or_financial_sidecar')
 result=dict(complete=True,cases=len(checks),checks=checks,actual_php=True,owned_socket_mariadb=True,original23=True,no_new_schema=True,
  no_provider_or_telegram_calls=True,shared_reference_metadata=True,malformed_origin_never_legacy=True,atomic_order_origin_rollback=True,locked_origin_current_read=True,
  payment_wallet_capacity_mutations=0,production_mutations=0)
 if a.report:a.report.write_text(json.dumps(result,indent=2)+'\n')
 print(json.dumps(result))

# Current domain fixture imports the real event-aware notification router.
import notification_fixture
PHP=notification_fixture.with_router(PHP)

if __name__=='__main__':main()
