#!/usr/bin/env python3
"""Disposable proof of the exact pre-hygiene -> current-schema projection.

This is deliberately a fixture, not an installed-instance migration tool.
No production credentials, network, destructive source mutation or guessed
repair. An actual transfer has its own source audit and owner authorization.
"""
import argparse
import json
from pathlib import Path
import subprocess
import tempfile
import database_fixture as owned

ROOT=Path(__file__).resolve().parents[1]
BASELINE='90a9d79e2f8b4814db08c00d5a52c4e5a5d842f7'
REMOVED={
    'orders_list':{'inbound_id'},
    'server_plans':{'inbound_id'},
    'increase_order':{'inbound_id'},
    'payment_attempts':{'review_notice_claimed_at','evidence_staff_digest'},
}
OLD='ACHBOT_MARZBAN_PANEL_GROUP'
NEW='ACHBOT_PANEL_GROUP'
SEED="""
INSERT INTO setting(id,type,value) VALUES (71,'ACHBOT_MARZBAN_PANEL_GROUP','Physical fixture'),
 (72,'PAYMENT_KEYS','{"manualUsdtRateToman":"100000","usdtAgentRateToman":"200000"}'),
 (73,'ACHBOT_ADMIN_PAYMENT_KEYS_SOURCE','{"version":1,"last_update_id":701,"actor_id":9001,"field_versions":{"manualUsdtRateToman":701,"usdtAgentRateToman":700}}');
INSERT INTO server_config(id,panel_url,type,username,password,sub_links,provider_connection_id,provider_instance_key,provider_version,provider_enabled)
 VALUES(3,'https://fixture.invalid','pasarguard','fixture','fixture-key','fixture-sub',3,REPEAT('a',32),'5.5.1',1);
INSERT INTO server_info(id,title,ucount,remark,flag,panel_group_id,active) VALUES(3,'fixture','100','fixture','',71,1);
INSERT INTO server_categories(id,title,parent,active) VALUES(5,'fixture',0,1);
INSERT INTO server_plans(id,catid,server_id,inbound_id,title,days,volume,price,descr,active,date,provider_group_ids)
 VALUES(7,5,3,0,'fixture',30,2,100,'fixture',1,'fixture','[1,2]');
INSERT INTO orders_list(id,userid,token,transid,fileid,server_id,inbound_id,remark,uuid,expire_date,link,amount,status,date,provider_effect_id,provider_remote_user_id,provider_remote_created_at)
 VALUES(19,1001,'fixture-token','fixture-payment',7,3,0,'fixture-account','fixture-credential',1900000000,'["vless://fixture"]',100,1,'1700000000',21,17,'2026-10-26T05:00:00Z');
INSERT INTO increase_order(id,userid,server_id,inbound_id,remark,amount,date,volume,day) VALUES(22,'1001',3,0,'fixture-account',100,'1700000000',2,NULL);
INSERT INTO pays(id,hash_id,user_id,type,price,request_date,state,description,admins_messages,approved_by)
 VALUES(23,'fixture-payment',1001,'INCREASE_WALLET',100,1700000000,'paid','original receipt text','original staff receipt',9001);
INSERT INTO payment_attempts(id,pay_row_id,provider,order_hash,quote_amount,quote_currency,stage,review_notice_claimed_at,evidence_staff_digest,review_notice_claim_token,evidence_review_sent_at,evidence_projection_checked_at)
 VALUES(24,23,'bank_transfer','fixture-payment',100,'toman','done','2026-10-26 05:00:00',REPEAT('a',64),REPEAT('b',32),'2026-10-26 05:01:00','2026-10-26 05:02:00');
UPDATE users SET wallet=-100 WHERE userid=1001;
INSERT INTO wallet_entries(id,source_kind,source_ref,leg,user_row_id,userid,delta_toman,balance_before,balance_after,actor_userid,created_at)
 SELECT 25,'opening','fixture-opening',1,id,userid,-100,0,-100,9001,'2026-10-26 05:00:00' FROM users WHERE userid=1001;
INSERT INTO service_effects(id,source_kind,source_ref,actor_userid,ordinal,operation_id,target_server_id,target_group_id,target_key,endpoint_sha256,plan_id,remote_username,requested_expire,requested_bytes,request_sha256,state,order_id,provider_snapshot,provider_reference_id,provider_connection_id,provider_remote_user_id,provider_remote_created_at,provider_operation_marker,provider_entitlement_snapshot,provider_state)
 VALUES(21,'admin_bulk','fixture-source',9001,1,REPEAT('c',32),3,71,'pg:fixture',REPEAT('d',64),7,'fixture-account',1900000000,2147483648,REPEAT('e',64),'applied',19,'{"provider":"marzban","historical_origin":"fixture"}',21,3,17,'2026-10-26T05:00:00Z','fixture-original-marker','{"fixture":"original entitlement"}','linked');
INSERT INTO service_plan_changes(id,lock_key,source_order_id,plan_id,server_id,provider,state,intent_json)
 VALUES(26,CONCAT('achbot-plan-',LEFT(SHA2('pg:21',256),40)),19,7,3,'pasarguard','completed','{"fixture":"original sealed evidence"}');
"""
PHP=r"""<?php
mysqli_report($argv[4]==='strict'?MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT:MYSQLI_REPORT_OFF);
require $argv[1].'/src/Purchase/Core.php';
require $argv[1].'/src/Subscription/GiftBatch.php';
require $argv[1].'/src/Subscription/PhysicalService.php';
$db=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
function yes($v,$case) {if(!$v)throw new RuntimeException($case);}
yes(achbotListPanelGroups($db)===[['id'=>71,'name'=>'Physical fixture']],'group_id');
yes(achbotPanelGroup($db,3)===['id'=>71,'name'=>'Physical fixture'],'group_member');
yes(!achbotDeletePanelGroupRecord($db,71),'group_in_use');
$id=achbotCreatePanelGroupRecord($db,'new group '.$argv[4]);
yes(is_int($id)&&$id>0,'group_create');
yes(achbotDeletePanelGroupRecord($db,$id),'group_delete');
$o=achbotGiftOrder($db,19);
$prior=['id'=>19,'userid'=>1001,'server_id'=>3,'inbound_id'=>0,'fileid'=>7,'status'=>1,'expire_date'=>1900000000,'remark'=>'fixture-account','uuid'=>'fixture-credential'];
yes($o===$prior,'sealed_reference_encoding');
yes(achbotGiftReferenceProof([$o])['references_sha256']===hash('sha256',json_encode([$prior],JSON_THROW_ON_ERROR)),'sealed_reference_digest');
$plan=['id'=>7,'server_id'=>3,'provider_group_ids'=>'[1,2]'];
yes(achbotPhysicalPlanFingerprint($plan)===hash('sha256',json_encode([7,3,0,'[1,2]'],JSON_THROW_ON_ERROR)),'sealed_plan_fingerprint');
echo 'PASS';
"""
def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--run-disposable',action='store_true',required=True)
    parser.add_argument('--report',type=Path)
    args=parser.parse_args()
    checks=[]
    with tempfile.TemporaryDirectory(prefix='achbot-source-schema-') as temp:
        schema=Path(temp)/'baseline.sql'
        schema.write_bytes(subprocess.check_output(['git','show',BASELINE+':database/current-schema.sql'],cwd=ROOT))
        with owned.owned_database(schema) as (work,env):
            source=owned.NAMES[0]; archive=owned.NAMES[1]; target='hygiene_target'
            cli=['mariadb','--no-defaults','--protocol=SOCKET','--socket='+str(work/'mysql.sock'),'-uroot','--batch','--skip-column-names','--raw']
            def sql(query,db=source):
                return subprocess.check_output(cli+[db,'-e',query],env=env,text=True,timeout=30).rstrip('\n')
            def execute(data,db=source):
                return subprocess.run(cli+[db],input=data,text=True,env=env,capture_output=True,check=True,timeout=45)
            execute(SEED)
            def preflight():
                if any(sql('SELECT COUNT(*) FROM '+t+' WHERE inbound_id<>0')!='0' for t in ('orders_list','server_plans','increase_order')):
                    return False
                if sql("SELECT COUNT(*) FROM setting WHERE type='"+NEW+"'")!='0':return False
                if sql("SELECT COUNT(*) FROM (SELECT value FROM setting WHERE type='"+OLD+"' GROUP BY value HAVING COUNT(*)>1) AS d")!='0':return False
                return sql("SELECT COUNT(*) FROM server_info i LEFT JOIN setting g ON g.id=i.panel_group_id AND g.type='"+OLD+"' WHERE i.panel_group_id IS NOT NULL AND g.id IS NULL")=='0'
            assert preflight()
            for t in ('orders_list','server_plans','increase_order'):
                sql('UPDATE '+t+' SET inbound_id=9')
                assert not preflight(),t
                sql('UPDATE '+t+' SET inbound_id=0')
            checks.append('unmapped_nonzero_inbound_refused')
            sql("INSERT INTO setting(id,type,value) VALUES(74,'"+NEW+"','collision')")
            assert not preflight();sql('DELETE FROM setting WHERE id=74')
            sql("INSERT INTO setting(id,type,value) VALUES(74,'"+OLD+"','Physical fixture')")
            assert not preflight();sql('DELETE FROM setting WHERE id=74')
            sql('UPDATE server_info SET panel_group_id=999')
            assert not preflight();sql('UPDATE server_info SET panel_group_id=71')
            checks.append('group_collision_and_dangling_membership_refused')
            # A complete dump and independent restore are required before any
            # column can be omitted. Preserve exact original rows, not merely
            # a digest of values that would be impossible to recover.
            dump=subprocess.check_output(['mariadb-dump','--no-defaults','--protocol=SOCKET','--socket='+str(work/'mysql.sock'),'-uroot','--single-transaction','--hex-blob',source],env=env,timeout=60)
            sql('DROP DATABASE '+archive+'; CREATE DATABASE '+archive+' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')
            execute(dump.decode(),archive)
            tables=sql('SHOW TABLES').splitlines()
            def columns(db,t):
                return [l.split('\t')[0] for l in sql("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='"+db+"' AND TABLE_NAME='"+t+"' ORDER BY ORDINAL_POSITION",db).splitlines()]
            def rows(db,t,cols):
                # Ordered, binary-safe exact values with distinct NULLs.
                expressions=','.join('IFNULL(HEX(CAST(`'+c+'` AS BINARY)),\'NULL\')' for c in cols)
                return sorted(sql('SELECT '+expressions+' FROM `'+t+'`',db).splitlines())
            original={t:rows(source,t,columns(source,t)) for t in tables}
            assert all(rows(archive,t,columns(source,t))==original[t] for t in tables)
            checks.append('complete_source_dump_restores_original_evidence')
            sql('CREATE DATABASE '+target+' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')
            execute((ROOT/'database/current-schema.sql').read_text(),target)
            assert sql('SHOW TABLES',target).splitlines()==tables
            # Fixture-only projection. All writable common columns retained;
            # only the reviewed omissions and discriminator mapping are legal.
            statements=['SET FOREIGN_KEY_CHECKS=0;']
            for t in tables:
                old=columns(source,t);new=columns(target,t)
                assert set(old)-set(new)==REMOVED.get(t,set()) and not set(new)-set(old),t
                generated=set(sql("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='"+target+"' AND TABLE_NAME='"+t+"' AND IS_GENERATED='ALWAYS'",target).splitlines())
                cols=[c for c in new if c not in generated]
                quoted=','.join('`'+c+'`' for c in cols)
                select=quoted
                if t=='setting':
                    select=','.join("IF(type='"+OLD+"','"+NEW+"',type)" if c=='type' else
                        "CASE WHEN type='PAYMENT_KEYS' THEN JSON_REMOVE(value,'$.usdtAgentRateToman') "
                        "WHEN type='ACHBOT_ADMIN_PAYMENT_KEYS_SOURCE' THEN JSON_REMOVE(value,'$.field_versions.usdtAgentRateToman') ELSE value END"
                        if c=='value' else '`'+c+'`' for c in cols)
                statements.append('INSERT INTO `'+target+'`.`'+t+'` ('+quoted+') SELECT '+select+' FROM `'+source+'`.`'+t+'`;')
            statements.append('SET FOREIGN_KEY_CHECKS=1;')
            execute('\n'.join(statements),target)
            for t in tables:
                cols=columns(target,t)
                expected=rows(source,t,cols)
                if t=='setting':
                    def setting_rows(db):
                        result={}
                        for line in sql("SELECT id,HEX(type),HEX(value) FROM setting",db).splitlines():
                            id,kind,value=line.split('\t')
                            result[int(id)]=[bytes.fromhex(kind).decode(),bytes.fromhex(value).decode()]
                        return result
                    old=setting_rows(source);new=setting_rows(target)
                    for id,(kind,value) in old.items():
                        if kind==OLD:
                            assert new[id]==[NEW,value]
                        elif kind in ('PAYMENT_KEYS','ACHBOT_ADMIN_PAYMENT_KEYS_SOURCE'):
                            parsed=json.loads(value)
                            if kind=='PAYMENT_KEYS': del parsed['usdtAgentRateToman']
                            else: del parsed['field_versions']['usdtAgentRateToman']
                            assert new[id][0]==kind and json.loads(new[id][1])==parsed
                        else: assert new[id]==[kind,value]
                    assert set(old)==set(new)
                    continue
                assert rows(target,t,cols)==expected,t
            assert all(rows(source,t,columns(source,t))==original[t] for t in tables)
            checks.append('all_table_common_values_and_generated_columns_preserved')
            script=work/'transfer.php';script.write_text(PHP)
            for mode in ('strict','off'):
                p=subprocess.run(['php',str(script),str(ROOT),str(work/'mysql.sock'),target,mode],env=env,capture_output=True,text=True,timeout=30)
                assert p.returncode==0 and p.stdout=='PASS',(mode,p.stdout,p.stderr)
                checks.append(mode+':current_groups_and_original_sealed_identity')
    result={'complete':True,'status':'PASS','checks':checks,'cases':len(checks),'tables':len(tables),'installed_mutations':0}
    if args.report:args.report.write_text(json.dumps(result,indent=2)+'\n')
    print(json.dumps(result))
if __name__=='__main__':main()
