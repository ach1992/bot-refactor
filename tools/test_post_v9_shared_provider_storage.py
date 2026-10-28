#!/usr/bin/env python3
"""AChBot populated 011->012 consolidation proof on an owned socket MariaDB."""
from __future__ import annotations
import argparse,copy,hashlib,json,subprocess
from pathlib import Path
import test_access_state as owned
import test_v6_native as native
import test_v8_native as purchase
import test_post_v9_pasarguard_client as pg

ROOT=Path(__file__).resolve().parents[1]
DB=purchase.SIDE[1]
URL='https://panel.example.invalid'
CREATED='2026-10-26T05:00:00.123456Z'
MARKER='achbot:v1:'+'a'*32
KEY='fixture-key-'+('k'*350)
SNAP=dict(provider='pasarguard',panel_id=41,instance_key='b'*32,
 endpoint_sha256=hashlib.sha256(URL.encode()).hexdigest(),group_ids=[2,5])
ENT=dict(data_limit=2147483648,expire=2000000000,group_ids=[2,5],status='active')
ORIGIN=dict(version=1,kind='external_import',order_id=92,owner_userid=9002,actor_userid=9001,
 provider='pasarguard',server_id=3,remark='fixture_user',proof_sha256='d'*64,
 native_identity=dict(remote_user_id=17,remote_created_at=CREATED,remote_username='fixture_user'),
 capacity_reserved=False,provider_account_id=70)
MUTATION=dict(version=1,key='pay:77',consumed_keys=['pay:77'],action='edit',changes={},result='verified')
def q(value):
 return "'"+str(value).replace("\\","\\\\").replace("'","''")+"'"
def wire(value):return q(json.dumps(value,separators=(',',':')))

def reset(work,env):
 native.sql(work,env,'DROP DATABASE '+DB+';CREATE DATABASE '+DB+' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')
 native.sql(work,env,(ROOT/'database/legacy-schema.sql').read_text()+owned.SEED,DB)
 native.sql(work,env,"INSERT INTO server_info(id,title,ucount,remark,flag,active,state) VALUES(2,'A',10,'a','',1,1),(3,'B',10,'b','',1,1);",DB)
 native.sql(work,env,"INSERT INTO server_config(id,panel_url,ip,sni,header_type,request_header,response_header,security,tlsSettings,username,password,type) VALUES"
  "(2,'"+URL+"','','','none','','','none','','','','pasarguard'),"
  "(3,'"+URL+"','','','none','','','none','','','','pasarguard');",DB)
 native.sql(work,env,"INSERT INTO server_config(id,panel_url,ip,sni,header_type,request_header,response_header,security,tlsSettings,username,password,type) VALUES(800,'https://retired-config.invalid','','','none','','','none','','fixture','fixture','marzban');",DB)
 for name in ['001_marzban_panel_group_id.sql','007_service_effects.sql','011_provider_identity.sql']:
  native.sql(work,env,(ROOT/'migrations/post-v9'/name).read_text(),DB)
 native.sql(work,env,"INSERT INTO provider_panels(id,provider,instance_key,name,api_url,api_key,version) VALUES"
  "(41,'pasarguard',REPEAT('b',32),'Native',"+q(URL)+","+q(KEY)+",'5.5.1');"
  "UPDATE server_info SET provider_panel_id=41,provider_group_ids='[2,5]';"
  "INSERT INTO orders_list(id,userid,token,transid,fileid,server_id,inbound_id,remark,uuid,protocol,expire_date,link,amount,status,date,agent_bought,autoRenew) VALUES"
  "(91,1001,'native-token','',7,2,0,'fixture_user','','',2000000000,'[]',100,1,'1700000000',0,'off'),"
  "(92,9002,'native-token','',0,3,0,'fixture_user','','',2000000000,'[]',0,1,'1700000000',0,'off');"
  "INSERT INTO service_effects(id,source_kind,source_ref,actor_userid,ordinal,reserved_count,operation_id,effect_kind,target_server_id,target_key,"
  "endpoint_sha256,plan_id,remote_username,requested_expire,requested_bytes,request_sha256,state,order_id,verified_at,provider_snapshot) VALUES"
  "(11,'admin_bulk','fixture-created',9001,1,1,REPEAT('a',32),'create',2,'pg:"+('b'*32)+"',"
  "SHA2("+q(URL)+",256),7,'fixture_user',2000000000,2147483648,REPEAT('c',64),'local_recorded',91,UTC_TIMESTAMP(),"+wire(SNAP)+"),"
  "(12,'admin_bulk','fixture-pending',9001,1,1,REPEAT('c',32),'create',2,'pg:"+('b'*32)+"',"
  "SHA2("+q(URL)+",256),7,'pending_user',2000000000,2147483648,REPEAT('e',64),'dispatching',NULL,NULL,"+wire(SNAP)+");"
  "INSERT INTO provider_accounts(id,effect_id,order_id,provider,panel_id,instance_key,endpoint_sha256,remote_user_id,remote_created_at,remote_username,"
  "operation_marker,entitlement_snapshot,mutation_key,mutation_snapshot,state,verified_at) VALUES"
  "(70,11,91,'pasarguard',41,REPEAT('b',32),SHA2("+q(URL)+",256),17,"+q(CREATED)+",'fixture_user',"+q(MARKER)+","+wire(ENT)+",'pay:77',"+wire(MUTATION)+",'linked',UTC_TIMESTAMP()),"
  "(75,12,NULL,'pasarguard',41,REPEAT('b',32),SHA2("+q(URL)+",256),NULL,NULL,'pending_user','achbot:v1:"+('c'*32)+"',"+wire(ENT)+",NULL,NULL,'dispatching',NULL);"
  "INSERT INTO setting(type,value) VALUES('ACHBOT_SERVICE_ORIGIN:92',"+wire(ORIGIN)+");"
  "INSERT INTO pays(id,hash_id,user_id,type,plan_id,server_id,price,state,request_date,provider_snapshot) VALUES"
  "(77,'old-quote',1001,'BUY_SUB',7,2,100,'paid_with_wallet',1700000000,"+wire(SNAP)+");",DB)
 native.sql(work,env,'ALTER TABLE server_info AUTO_INCREMENT=500;ALTER TABLE service_effects AUTO_INCREMENT=600;ALTER TABLE provider_panels AUTO_INCREMENT=700;ALTER TABLE provider_accounts AUTO_INCREMENT=5000;',DB)

def preserved(work,env):
 return dict(
  pays=native.sql(work,env,"SELECT id,hash_id,user_id,type,plan_id,server_id,price,state,provider_snapshot FROM pays ORDER BY id",DB),
  origin=native.sql(work,env,"SELECT type,value FROM setting ORDER BY id",DB),
  effects=native.sql(work,env,"SELECT id,source_kind,source_ref,operation_id,effect_kind,order_id,request_sha256,state,provider_snapshot,created_at,updated_at FROM service_effects ORDER BY id",DB),
  orders=native.sql(work,env,"SELECT id,userid,fileid,server_id,remark,expire_date,amount,status,autoRenew FROM orders_list ORDER BY id",DB),
  users=native.sql(work,env,"SELECT id,userid,wallet,isAdmin FROM users ORDER BY id",DB))
PHP=r'''<?php
error_reporting(E_ALL);set_error_handler(static function($n,$m){if($n===E_DEPRECATED)return true;throw new ErrorException($m,0,$n);});
$_SERVER['SCRIPT_FILENAME']=__FILE__;
require $argv[1].'/src/Panel/ProviderRuntime.php';require $argv[1].'/src/Panel/ProviderStorage.php';
$connection=new mysqli('localhost','root','',$argv[3],0,$argv[2]);$connection->set_charset('utf8mb4');
try {
 $a=achbotPgOrderContext($connection,91);$b=achbotPgOrderContext($connection,92);
 $replay=false;try{achbotPgMutationAdmission($a['account'],'pay:77');}catch(Throwable $e){$replay=true;}
 $intent=achbotPgBeginMutation($connection,$a,'pay:78','edit',['days'=>30]);
 $other=achbotProviderAccountByOrder($connection,92);$held=false;
 try{achbotPgMutationAdmission($other,'pay:79');}catch(Throwable $e){$held=true;}
 echo json_encode(['same'=>$a['account']['id']===$b['account']['id'],'id'=>$a['account']['id'],
 'effect'=>$a['account']['effect_id'],'panel'=>$a['panel']['id'],'key_length'=>strlen($a['panel']['api_key']),
 'replay'=>$replay,'shared_mutation_held'=>$held,'intent_key'=>$intent['key']]);
}catch(Throwable $e){echo json_encode(['error'=>$e->getMessage()]);}
'''
def run(work,env):
 checks=[]
 def ok(value,name):
  assert value,name
  checks.append(name)
 script=work/'shared-storage-proof.php';script.write_text(PHP)
 sql=(ROOT/'migrations/post-v9/012_shared_provider_storage.sql').read_text()
 reset(work,env)
 before=preserved(work,env)
 old_connections=native.sql(work,env,'SELECT * FROM provider_panels ORDER BY id',DB)
 old_accounts=native.sql(work,env,'SELECT * FROM provider_accounts ORDER BY id',DB)
 old_schema=native.sql(work,env,"SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION",DB)
 client=['mariadb-dump','--no-defaults','--protocol=SOCKET','--socket='+str(work/'mysql.sock'),'-uroot','--skip-comments','--skip-dump-date',DB]
 backup=subprocess.run(client,capture_output=True,check=True,timeout=20).stdout
 native.sql(work,env,sql,DB)
 ok(preserved(work,env)==before,'immutable_payment_origin_effect_and_local_order_data_preserved')
 ok(native.sql(work,env,"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('provider_panels','provider_accounts')",DB).strip()=='0','both_redundant_tables_absent')
 ok(native.sql(work,env,"SELECT id,provider_effect_id,provider_remote_user_id,provider_remote_created_at FROM orders_list ORDER BY id",DB).splitlines()==
  ['91\t11\t17\t'+CREATED,'92\t11\t17\t'+CREATED],'two_users_and_two_alias_servers_bind_same_native_origin')
 ok(native.sql(work,env,"SELECT provider_state,provider_remote_user_id FROM service_effects WHERE id=12",DB).splitlines()[-1]=='dispatching\tNULL','unknown_preorder_dispatch_evidence_preserved')
 ok(native.sql(work,env,"SELECT COUNT(*) FROM server_config WHERE provider_connection_id=41 AND BINARY password=BINARY "+q(KEY),DB).strip()=='2','shared_connection_full_length_keys_transferred')
 ok(native.sql(work,env,"SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='server_info' AND COLUMN_NAME='provider_panel_id'",DB).strip()=='0','obsolete_server_pointer_removed')
 r=pg.php(script,ROOT,work/'mysql.sock',DB)
 ok(r.get('same') is True and r['id']==70 and r['effect']==11 and r['panel']==41,'native_runtime_keeps_historical_snapshot_identifiers')
 ok(r.get('key_length')==len(KEY),'long_api_key_not_truncated')
 ok(r.get('replay') is True and r.get('shared_mutation_held') is True,'shared_mutation_and_consumed_payment_fences_survive_consolidation')
 ok(preserved(work,env)==before,'mutation_evidence_does_not_change_order_payment_or_wallet')
 ok(int(native.sql(work,env,"SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='service_effects'",DB).splitlines()[-1])>75,'new_effect_ids_cannot_collide_with_legacy_reference_ids')
 ok(int(native.sql(work,env,"SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='server_info'",DB).splitlines()[-1])>41,'new_server_ids_cannot_collide_with_legacy_panel_ids')
 ok(int(native.sql(work,env,"SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='server_info'",DB).strip())>800,'orphan_non_native_config_id_never_reused')
 ok(int(native.sql(work,env,"SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='service_effects'",DB).strip())>=5000,'legacy_account_allocated_counter_preserved')

 # Actual isolated rollback: restore the pre-migration full dump, not a guessed reverse ALTER.
 native.sql(work,env,'DROP DATABASE '+DB+';CREATE DATABASE '+DB+' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')
 native.sql(work,env,backup.decode(),DB)
 ok(preserved(work,env)==before,'pre_migration_backup_restores_exact_original_data')
 ok(native.sql(work,env,"SELECT COUNT(*) FROM provider_accounts",DB).strip()=='2','restore_reinstates_native_operation_evidence')
 ok(native.sql(work,env,'SELECT * FROM provider_panels ORDER BY id',DB)==old_connections,'restore_preserves_all_original_connection_fields')
 ok(native.sql(work,env,'SELECT * FROM provider_accounts ORDER BY id',DB)==old_accounts,'restore_preserves_all_original_native_evidence_fields')
 ok(native.sql(work,env,"SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION",DB)==old_schema,'restore_reinstates_complete_original_column_schema')
 # Preserve current and legacy allocated counters even when no live row has that ID.
 reset(work,env)
 native.sql(work,env,'ALTER TABLE server_info AUTO_INCREMENT=9000;ALTER TABLE service_effects AUTO_INCREMENT=9000;',DB)
 native.sql(work,env,sql,DB)
 ok(int(native.sql(work,env,"SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='server_info'",DB).strip())>=9000,'existing_server_allocated_counter_never_lowered')
 ok(int(native.sql(work,env,"SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='service_effects'",DB).strip())>=9000,'existing_effect_allocated_counter_never_lowered')
 reset(work,env)
 native.sql(work,env,'ALTER TABLE provider_panels AUTO_INCREMENT=11000;ALTER TABLE provider_accounts AUTO_INCREMENT=12000;',DB)
 native.sql(work,env,sql,DB)
 ok(int(native.sql(work,env,"SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='server_info'",DB).strip())>=11000,'legacy_panel_allocated_counter_preserved')
 ok(int(native.sql(work,env,"SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='service_effects'",DB).strip())>=12000,'legacy_account_high_allocated_counter_preserved')
 negatives={
  'orphan_connection':"INSERT INTO provider_panels(provider,instance_key,name,api_url,api_key,version) VALUES('pasarguard',REPEAT('f',32),'orphan','https://orphan.invalid','key','5.5.1')",
  'unmapped_order':"INSERT INTO orders_list(userid,token,transid,fileid,server_id,inbound_id,remark,uuid,protocol,expire_date,link,amount,status,date) VALUES(1001,'x','',0,2,0,'unknown','','',2000000000,'[]',0,1,'1700000000')",
  'wrong_incarnation':"UPDATE provider_accounts SET remote_created_at='2026-10-27T05:00:00.123456Z' WHERE id=70",
  'wrong_owner':"UPDATE setting SET value=JSON_SET(value,'$.owner_userid',1001) WHERE type='ACHBOT_SERVICE_ORIGIN:92'",
  'malformed_origin':"UPDATE setting SET value='{' WHERE type='ACHBOT_SERVICE_ORIGIN:92'",
  'missing_snapshot':"UPDATE service_effects SET provider_snapshot=NULL WHERE id=11",
  'missing_snapshot_provider':"UPDATE service_effects SET provider_snapshot=JSON_REMOVE(provider_snapshot,'$.provider') WHERE id=11",
  'missing_snapshot_panel_id':"UPDATE service_effects SET provider_snapshot=JSON_REMOVE(provider_snapshot,'$.panel_id') WHERE id=11",
  'wrong_snapshot_panel_id_type':"UPDATE service_effects SET provider_snapshot=JSON_SET(provider_snapshot,'$.panel_id','41') WHERE id=11",
  'wrong_origin_capacity_type':"UPDATE setting SET value=JSON_SET(value,'$.capacity_reserved','false') WHERE type='ACHBOT_SERVICE_ORIGIN:92'",
  'missing_snapshot_instance':"UPDATE service_effects SET provider_snapshot=JSON_REMOVE(provider_snapshot,'$.instance_key') WHERE id=11",
  'missing_snapshot_groups':"UPDATE service_effects SET provider_snapshot=JSON_REMOVE(provider_snapshot,'$.group_ids') WHERE id=11",
  'missing_origin_provider':"UPDATE setting SET value=JSON_REMOVE(value,'$.provider') WHERE type='ACHBOT_SERVICE_ORIGIN:92'",
  'missing_origin_owner':"UPDATE setting SET value=JSON_REMOVE(value,'$.owner_userid') WHERE type='ACHBOT_SERVICE_ORIGIN:92'",
  'wrong_origin_id_type':"UPDATE setting SET value=JSON_SET(value,'$.native_identity.remote_user_id','17') WHERE type='ACHBOT_SERVICE_ORIGIN:92'",
  'missing_native_origin_field':"UPDATE setting SET value=JSON_REMOVE(value,'$.native_identity.remote_user_id') WHERE type='ACHBOT_SERVICE_ORIGIN:92'",
  'duplicate_origin':"INSERT INTO setting(type,value) SELECT type,value FROM setting WHERE type='ACHBOT_SERVICE_ORIGIN:92'"
 }
 for name,change in negatives.items():
  reset(work,env);native.sql(work,env,change,DB)
  old=preserved(work,env);rejected=False
  try:native.sql(work,env,sql,DB)
  except RuntimeError:rejected=True
  ok(rejected,'reject_before_drop:'+name)
  ok(preserved(work,env)==old,'reject_keeps_original_data:'+name)
  ok(native.sql(work,env,"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('provider_panels','provider_accounts')",DB).strip()=='2','reject_keeps_source_tables:'+name)
  ok(native.sql(work,env,"SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='server_config' AND COLUMN_NAME='provider_connection_id'",DB).strip()=='0','reject_before_ddl:'+name)
 return checks

def main():
 p=argparse.ArgumentParser();p.add_argument('--run-disposable',action='store_true',required=True);p.add_argument('--report',type=Path);args=p.parse_args()
 with owned.owned_database() as (work,env):checks=run(work,env)
 report=dict(complete=True,cases=len(checks),checks=checks,populated_migration=True,actual_backup_restore=True,
  disposable_socket_mariadb=True,native_runtime=True,real_provider_calls=0,tenant_mutation=False)
 if args.report:args.report.write_text(json.dumps(report,indent=2)+'\n')
 print(json.dumps(report))
if __name__=='__main__':main()
