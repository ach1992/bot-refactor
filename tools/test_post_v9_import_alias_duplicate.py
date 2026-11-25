#!/usr/bin/env python3
"""Source-real, alias-aware Native import admission for distinct Telegram owners.

Runs the actual preview, recipient-selection, import and supported transfer
controllers against disposable socket MariaDB and HTTP loopback providers.
"""
from __future__ import annotations

import argparse
import copy
import json
import re
import subprocess
import threading
import time
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

import provider_storage_fixture as storage
import database_fixture as owned
import existing_subscription_fixture as imports
import database_fixture as native
import php_fixture as bodies

ROOT = Path(__file__).resolve().parents[1]
DB = owned.NAMES[1]
DRIVER = imports.PHP


def run() -> list[str]:
    checks = []
    for provider in ('pasarguard',):
        fixture = imports.existing_panel
        with owned.owned_database(ROOT/'database/current-schema.sql') as (work, env), fixture() as (vendor, url):
            def db(sql: str) -> str:
                return native.sql(work, env, sql, DB).strip()

            # The actual supported transfer now retires current drafts. Use
            # the complete fresh runtime schema, never a partial old chain.
            db("INSERT INTO server_config(id,panel_url,type,username,password)"
               " VALUES(2,"+storage.text(url)+",'pasarguard','fixture-admin','fixture-private-key');"
               "INSERT INTO server_info(id,title,ucount,remark,flag,active,state)"
               " VALUES(2,'Fixture purchase server',10,'buyfixture','',1,1);"
               "INSERT INTO server_categories(id,title,parent,active) VALUES(1,'Fixture category',0,1);"
               "INSERT INTO server_plans(id,catid,server_id,inbound_id,title,days,volume,price,descr,active,date,provider_group_ids)"
               " VALUES(7,1,2,0,'Fixture',30,2,100,'description',1,'fixture','[1,2]');")
            db("INSERT INTO users(userid,name,username,refcode,wallet,date,isAdmin,first_start,step)"
               " VALUES (1002,'B','b','0',50000,'1700000000',0,'sent','none'),"
               "(1003,'C','c','0',50000,'1700000000',0,'sent','none'),"
               "(1004,'D','d','0',50000,'1700000000',0,'sent','none');"
               "INSERT INTO server_info(id,title,ucount,remark,flag,active,state)"
               " VALUES(5,'Shared alias',10,'alias','',1,1);"
               + storage.clone(5, 2, current=True) +
               "INSERT INTO setting(type,value) VALUES"
               "('ACHBOT_MARZBAN_PANEL_GROUP','reviewed shared panel');"
               "UPDATE server_info SET panel_group_id=(SELECT MAX(id) FROM setting"
               " WHERE type='ACHBOT_MARZBAN_PANEL_GROUP') WHERE id IN (2,5);")
            db(storage.connection(2, 1, url) + storage.connection(5, 1, url))
            remark = 'existing_user_41'
            bearer = vendor.users[41]['subscription_url']
            def writes():
                return [(method, path) for method, path, *_ in vendor.trace if method != 'GET']
            def current_native():
                return copy.deepcopy(vendor.users)

            facade = bodies.function_bodies((ROOT / 'config.php').read_bytes(), [
                'buildSubscriptionPresentation','buildConfigMessage','setUser',
                'sumerize','getOrderDetailKeys','getUserOrderDetailKeys',
                'RandomString'])
            (work / 'facades.php').write_bytes(facade.replace(b'__DIR__', repr(str(ROOT)).encode()))
            driver = work / 'alias-import-controller.php'
            driver.write_text(DRIVER)
            assert subprocess.run(['php','-l',str(driver)],capture_output=True,text=True).returncode == 0
            sequence=540000
            sequence_lock=threading.Lock()
            def call(op: str, **kwargs):
                nonlocal sequence
                with sequence_lock:
                    sequence += 1
                    src=sequence
                args={'op':op,'source':src,'actor':kwargs.pop('actor',1001),**kwargs}
                result=subprocess.run(['php','-d','display_errors=0','-d','log_errors=1',
                    '-d','error_log=/dev/stderr',str(driver),str(ROOT),str(work/'mysql.sock'),
                    DB,json.dumps(args)],cwd=work,env=env,capture_output=True,text=True,timeout=35)
                assert result.returncode==0, (provider,op,'php',result.returncode,result.stderr[-1200:])
                output=json.loads(result.stdout)
                assert 'fixture_error' not in output,(provider,op,output,result.stderr[-1200:])
                assert all(line.startswith(('achbot_existing_subscription_import_deferred reason=',
                    'achbot_existing_subscription_lookup_deferred',
                    'achbot_customer_draft_creation_deferred',
                    'achbot_shared_transfer_committed','[AChBot] {"event":"achbot.delivery.followup_result"')) for line in (re.sub(r'^\[[^\]]+\] ', '', item) for item in result.stderr.splitlines())), (
                        provider,op,'unexpected_logs',result.stderr[-1400:])
                return output, result.stderr

            def snapshot():
                # Actor draft state is intentionally outside the immutable
                # order, physical, origin, financial and capacity evidence.
                return db('SELECT * FROM orders_list ORDER BY id;'
                    'SELECT * FROM service_effects ORDER BY id;'
                    "SELECT id,type,value FROM setting WHERE type LIKE 'ACHBOT_SERVICE_ORIGIN:%' ORDER BY id;"
                    'SELECT id,userid,wallet FROM users ORDER BY id;'
                    'SELECT * FROM pays ORDER BY id;'
                    'SELECT id,ucount FROM server_info ORDER BY id;'
                    'SELECT * FROM increase_order ORDER BY id;')

            def preview(actor, token=None):
                output,_=call('external',actor=actor,data='showUUIDLeft')
                response,_=call('external',actor=actor,text=token or bearer)
                assert 'addToOrders2' in json.dumps(response) or 'addSubscriptionToCustomer2' in json.dumps(response),(
                    provider,'preview',response)
            def customer_preview(actor,customer,token=None):
                preview(actor,token)
                call('manual',actor=actor,data='addSubscriptionToCustomer2')
                call('manual',actor=actor,text=str(customer))
            def import_for(actor, customer=None, token=None):
                if customer is None:preview(actor,token)
                else:customer_preview(actor,customer,token)
                before=snapshot();native_before=current_native();mutations=len(writes())
                response,log=call('manual',actor=actor,data='addToOrders2')
                assert current_native()==native_before and len(writes())==mutations,(provider,'provider_write')
                return response,log,before
            def rejected(actor, customer=None):
                response,log,before=import_for(actor,customer)
                assert 'reason=import_already_attached' in log, (provider,'duplicate_reason',log,response)
                assert snapshot()==before,(provider,'duplicate_changed_database')
                return response
            result,log,_=import_for(1001)
            assert db("SELECT COUNT(*) FROM orders_list WHERE userid=1001 AND remark='"+remark+"' AND status=1")=='1',(
                provider,'first_import_failed',result,log)
            checks.append(provider+'_first_user_imports_original_native_once')
            rejected(1001)
            checks.append(provider+'_same_server_same_customer_duplicate_rejected_without_effect')
            result,log,_=import_for(1002)
            assert db("SELECT COUNT(*) FROM orders_list WHERE remark='"+remark+"' AND status=1")=='2',(
                provider,'second_customer_import_failed',result,log)
            checks.append(provider+'_second_customer_shares_physical_native_without_create')
            oid=int(db("SELECT id FROM orders_list WHERE userid=1001 AND remark='"+remark+"'"))
            moved,transfer_logs=call('transfer',actor=9001,order=oid,target=5)
            assert moved['out'] is not None,(provider,'transfer_failed',moved,transfer_logs)
            assert db("SELECT COUNT(*) FROM orders_list WHERE remark='"+remark+"' AND server_id=5 AND status=1")=='2',(
                provider,'aliases_not_transferred')
            checks.append(provider+'_supported_admin_transfer_moves_all_active_refs_to_alias')
            rejected(1001)
            rejected(1002)
            checks.append(provider+'_same_native_alias_duplicate_rejected_for_both_owners_without_effect')
            result,log,_=import_for(9001,1003)
            assert db("SELECT COUNT(*) FROM orders_list WHERE remark='"+remark+"' AND status=1")=='3',(
                provider,'admin_third_customer_failed',result,log)
            checks.append(provider+'_admin_can_attach_same_native_for_distinct_third_customer')
            # Competing administrator callbacks target the SAME recipient.
            # Recipient user row, not the initiating actor, owns serialization.
            customer_preview(9001,1004)
            customer_preview(9002,1004)
            financial_before=db('SELECT id,userid,wallet FROM users ORDER BY id;'
                'SELECT * FROM pays ORDER BY id;SELECT * FROM increase_order ORDER BY id;'
                'SELECT id,ucount FROM server_info ORDER BY id;')
            original_effect=db('SELECT * FROM service_effects WHERE provider_reference_id IS NOT NULL ORDER BY id')
            with ThreadPoolExecutor(max_workers=2) as pool:
                first=pool.submit(call,'manual',actor=9001,data='addToOrders2')
                second=pool.submit(call,'manual',actor=9002,data='addToOrders2')
                results=[first.result(timeout=45),second.result(timeout=45)]
            assert db("SELECT COUNT(*) FROM orders_list WHERE userid=1004 AND remark='"+remark+"' AND status=1")=='1',(
                provider,'concurrent_duplicate',results)
            assert db("SELECT COUNT(*) FROM setting WHERE type LIKE 'ACHBOT_SERVICE_ORIGIN:%'")=='4',(
                provider,'concurrent_origin_duplicate')
            assert len(writes())==0,(provider,'unexpected_native_mutation',writes())
            assert financial_before==db('SELECT id,userid,wallet FROM users ORDER BY id;'
                'SELECT * FROM pays ORDER BY id;SELECT * FROM increase_order ORDER BY id;'
                'SELECT id,ucount FROM server_info ORDER BY id;')
            assert original_effect==db('SELECT * FROM service_effects WHERE provider_reference_id IS NOT NULL ORDER BY id')
            assert any('reason=import_already_attached' in logs for _,logs in results),(
                provider,'concurrent_loser_not_rejected',results)
            checks.append(provider+'_concurrent_admin_imports_for_same_customer_commit_once')
            # A new native incarnation must not silently take over an active
            # old reference. Once the old references are retired, it may be
            # registered under its own new Native identity and immutable origin.
            old=vendor.users.pop(41)
            newer=copy.deepcopy(old)
            newer.update(id=51,created_at='2026-10-27T05:00:00.123456Z')
            newer['subscription_url']=url+'/native-subscriptions/'+imports.mint(51,int(time.time())-60)
            vendor.users[51]=newer
            new_bearer=newer['subscription_url']
            preview(1001,new_bearer)
            before=snapshot()
            _,log=call('manual',actor=1001,data='addToOrders2')
            assert 'reason=import_unavailable' in log or 'reason=import_already_attached' in log,(
                provider,'active_old_incarnation_rejection',log)
            assert snapshot()==before,(provider,'active_incarnation_guard_changed_evidence')
            checks.append(provider+'_active_old_incarnation_refuses_new_identity_without_changes')
            db("UPDATE orders_list SET status=0 WHERE remark='"+remark+"'")
            db("UPDATE service_effects SET provider_state='deleted' WHERE provider_reference_id IS NOT NULL")
            result,log,_=import_for(1001,token=new_bearer)
            assert db("SELECT COUNT(*) FROM orders_list WHERE userid=1001 AND remark='"+remark+"' AND status=1")=='1',(
                provider,'new_incarnation_after_retire',result,log)
            assert db("SELECT COUNT(*) FROM orders_list WHERE userid=1001 AND remark='"+remark+"' AND status=0")=='1'
            checks.append(provider+'_retired_history_allows_new_real_native_incarnation')
    return checks


def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--run-disposable',action='store_true',required=True)
    parser.add_argument('--report',type=Path)
    args=parser.parse_args()
    checks=run()
    report={'complete':True,'cases':len(checks),'checks':checks,
            'source_real':True,'disposable_socket_mariadb':True,
            'native_mutations':0,'production_effects':0}
    if args.report:args.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps(report))


if __name__=='__main__':main()
