#!/usr/bin/env python3
"""AChBot actual order writer on populated MariaDB before/after obsolete columns."""
from __future__ import annotations
import argparse,json,subprocess
from pathlib import Path
import test_access_state as owned
import test_v6_native as native
import test_v8_native as purchase
ROOT=Path(__file__).resolve().parents[1]
PHP=r'''<?php
error_reporting(E_ALL);
set_error_handler(static function($n,$m){if($n===E_DEPRECATED)return true;throw new ErrorException($m,0,$n);});
$_SERVER['SCRIPT_FILENAME']=__FILE__;
$db=new mysqli('localhost','root','',$argv[3],0,$argv[2]);$db->set_charset('utf8mb4');
require $argv[1];
try {
 $args=[$db,1001,'fixture-token',7,2,0,$argv[4],'fixture-uuid'];
 if((new ReflectionFunction('insertOrder'))->getNumberOfParameters()===16)$args[]='vless';
 array_push($args,1900000000,'["vless://fixture#native-name"]',101,1700000000);
 if((new ReflectionFunction('insertOrder'))->getNumberOfParameters()===16)$args[]=0;
 array_push($args,1,true);
 $id=insertOrder(...$args);
 echo json_encode(['ok'=>true,'id'=>$id],JSON_THROW_ON_ERROR);
}catch(Throwable $e){echo json_encode(['ok'=>false,'class'=>get_class($e),'code'=>(int)$e->getCode()],JSON_THROW_ON_ERROR);}
'''
def main():
 ap=argparse.ArgumentParser();ap.add_argument('--run-disposable',action='store_true',required=True);ap.add_argument('--report',type=Path);args=ap.parse_args();checks=[]
 def yes(value,name):
  if not value:raise AssertionError(name)
  checks.append(name)
 with owned.owned_database() as (work,env):
  purchase.init(work,env,'http://127.0.0.1:9');db=purchase.SIDE[1]
  def sql(query):return native.sql(work,env,query,db).strip()
  script=work/'write-order.php';script.write_text(PHP)
  def write(source,remark):
   result=subprocess.run(['php','-d','display_errors=0','-d','log_errors=0',str(script),str(source),str(work/'mysql.sock'),db,remark],env=env,capture_output=True,text=True,timeout=12)
   if result.returncode or result.stderr:raise RuntimeError(repr((result.returncode,result.stdout,result.stderr)))
   return json.loads(result.stdout)
  writer=ROOT/'src/Purchase/OrderWrites.php'
  first=write(writer,'before-schema-drop');yes(first['ok']and first['id']>0,'actual_current_writer_accepts_original_schema')
  sql("UPDATE orders_list SET protocol='legacy-unused',rahgozar=7 WHERE id="+str(first['id']))
  fields='id,userid,token,transid,fileid,server_id,inbound_id,remark,uuid,expire_date,link,amount,status,date,notif,agent_bought,autoRenew'
  before=sql('SELECT '+fields+' FROM orders_list ORDER BY id')
  protected=';'.join('SELECT * FROM '+table+' ORDER BY id' for table in ['users','pays','server_info','server_config','server_plans','increase_order'])
  untouched=sql(protected)
  migration=(ROOT/'migrations/post-v9/013_remove_obsolete_order_columns.sql').read_text();sql(migration)
  yes(not sql("SHOW COLUMNS FROM orders_list WHERE Field IN ('protocol','rahgozar')"),'migration_removes_both_obsolete_columns')
  yes(sql('SELECT '+fields+' FROM orders_list ORDER BY id')==before,'populated_order_all_remaining_columns_preserved')
  yes(sql(protected)==untouched,'unrelated_customer_financial_server_and_plan_rows_unchanged')
  ddl=sql('SHOW CREATE TABLE orders_list');sql(migration)
  yes(sql('SHOW CREATE TABLE orders_list')==ddl,'repeat_migration_is_idempotent')
  second=write(writer,'after-schema-drop');yes(second['ok']and second['id']>first['id'],'actual_current_writer_accepts_removed_columns_schema')
  row=sql('SELECT userid,fileid,server_id,inbound_id,remark,expire_date,amount,date,agent_bought,autoRenew FROM orders_list WHERE id='+str(second['id']))
  yes(row=='1001\t7\t2\t0\tafter-schema-drop\t1900000000\t101\t1700000000\t1\toff','new_order_positional_fields_and_amount_exact')
  yes(sql(protected)==untouched,'actual_writer_has_no_wallet_payment_capacity_or_plan_effect')
  old=subprocess.check_output(['git','show','c9959811e264b5a8728f6a44376cb69c9a89b7f4:src/Purchase/OrderWrites.php'],cwd=ROOT)
  archived=work/'archived-order-writer.php';archived.write_bytes(old.replace(b'__DIR__',repr(str(ROOT/'src/Purchase')).encode()))
  red=write(archived,'archived-must-not-insert');yes(red=={'ok':False,'class':'mysqli_sql_exception','code':1054},'actual_previous_writer_RED_proves_missing_column_regression')
  yes(sql("SELECT COUNT(*) FROM orders_list WHERE remark='archived-must-not-insert'")=='0','failed_archived_writer_inserts_no_partial_order')
 report={'complete':True,'cases':len(checks),'checks':checks,'actual_php':True,'owned_socket_mariadb':True,'populated_migration':True,'previous_writer_RED':True,'production_mutations':0,'external_calls':0}
 if args.report:args.report.write_text(json.dumps(report,indent=2)+'\n')
 print(json.dumps(report))
if __name__=='__main__':main()
