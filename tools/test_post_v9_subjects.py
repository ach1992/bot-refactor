#!/usr/bin/env python3
"""Disposable original-schema MariaDB financial subject preflight matrix.

Only invokes a read-only PHP function. No Telegram, VPN, payment, Tron or
external HTTP; injected unsupported references cannot mutate any rows.
"""
import argparse
import json
import subprocess
from pathlib import Path

import test_access_state as owned
import test_v6_native as native
import test_v8_native as purchase

ROOT = Path(__file__).resolve().parents[1]
PHP = r'''
$_SERVER['SCRIPT_FILENAME']='owned-synthetic-subject-test.php';
require $argv[1];
$db=new mysqli('localhost','root','',$argv[2],0,$argv[3]);
$db->set_charset('utf8mb4');
$subject=json_decode($argv[4],true,512,JSON_THROW_ON_ERROR);
echo achbotSupportsPaymentSubject($db,$subject)?'SUPPORTED':'BLOCKED';
$db->close();
'''

def sql(work, env, query):
    return native.sql(work,env,query,purchase.SIDE[1])

def evaluate(work, env, pay):
    cmd=['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
         '-d','allow_url_fopen=0',
         '-d','disable_functions=exec,shell_exec,system,passthru,popen,proc_open,mail',
         '-r', PHP, str(ROOT/'src/Purchase/Core.php'),
         purchase.SIDE[1],str(work/'mysql.sock'),json.dumps(pay)]
    run=subprocess.run(cmd,env=env,cwd=work,capture_output=True,timeout=15)
    if run.returncode or run.stderr or run.stdout not in (b'SUPPORTED',b'BLOCKED'):
        raise RuntimeError('Guard PHP execution failed '+repr((run.returncode,run.stdout,run.stderr[:300])))
    return run.stdout==b'SUPPORTED'

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('--run-disposable',action='store_true',required=True)
    ap.add_argument('--report',type=Path)
    args=ap.parse_args()
    observations=[]
    with owned.owned_database() as (work,env):
        purchase.init(work,env,'http://127.0.0.1:9')
        if len(sql(work,env,'SHOW TABLES').splitlines())!=23:
            raise RuntimeError('Original schema table count drift')
        # Original-schema, entirely synthetic references to a customer order.
        sql(work,env,'''INSERT INTO orders_list
            (userid,token,transid,fileid,server_id,inbound_id,remark,uuid,
             protocol,expire_date,link,amount,status,date)
            VALUES (1001,'synthetic-token','',7,2,0,'synthetic','synthetic-uuid',
                    'vless',2000000000,'[]',100,1,'synthetic')''')
        oid=int(sql(work,env,'SELECT id FROM orders_list WHERE remark="synthetic" LIMIT 1').strip())
        addon_day=sql(work,env,'SELECT id FROM increase_day ORDER BY id LIMIT 1').strip()
        addon_volume=sql(work,env,'SELECT id FROM increase_plan ORDER BY id LIMIT 1').strip()
        subjects=[
            ('marzban_purchase',dict(type='BUY_SUB',plan_id=7,server_id=2),True),
            ('marzban_renew',dict(type='RENEW_ACCOUNT',plan_id=7,order_id=oid,user_id=1001),True),
            ('wallet_credit',dict(type='INCREASE_WALLET',plan_id=0),True),
            ('marzban_external_renew',dict(type='RENEW_SCONFIG',plan_id=7,
                 user_id=1001,server_id=2,volume=0,description=json.dumps(
                     {'marzban':True,'remark':'synthetic','server_id':2,'uuid':'id'})),True),
            ('old_external_renew_without_identity',dict(type='RENEW_SCONFIG',
                 plan_id=7,user_id=1001,server_id=2,volume=0),False),
            ('retired_external_renew',dict(type='RENEW_SCONFIG',plan_id=7,
                 user_id=1001,server_id=2,volume=0,description=json.dumps(
                     {'marzban':False,'remark':'synthetic','server_id':2})),False),
            ('external_renew_server_mismatch',dict(type='RENEW_SCONFIG',
                 plan_id=7,user_id=1001,server_id=2,volume=0,description=json.dumps(
                     {'marzban':True,'remark':'synthetic','server_id':3})),False),
            ('external_renew_plan_server_mismatch',dict(type='RENEW_SCONFIG',
                 plan_id=7,user_id=1001,server_id=3,volume=0,description=json.dumps(
                     {'marzban':True,'remark':'synthetic','server_id':3})),False),
            ('external_renew_missing_remark',dict(type='RENEW_SCONFIG',
                 plan_id=7,user_id=1001,server_id=2,volume=0,description=json.dumps(
                     {'marzban':True,'server_id':2})),False),
            ('missing_plan',dict(type='BUY_SUB',plan_id=999,server_id=2),False),
            ('plan_server_mismatch',dict(type='BUY_SUB',plan_id=7,server_id=999),False),
            ('missing_order',dict(type='RENEW_ACCOUNT',plan_id=7,order_id=999,user_id=1001),False),
            ('renew_order_plan_mismatch',dict(type='RENEW_ACCOUNT',plan_id=8,order_id=oid,user_id=1001),False),
            ('renew_owner_mismatch',dict(type='RENEW_ACCOUNT',plan_id=7,order_id=oid,user_id=9002),False),
            ('old_inbound_renew',dict(type='RENEW_SCONFIG',plan_id=7,volume=7),False),
            ('missing_extension',dict(type='INCREASE_VOLUME_999_999',plan_id=7,order_id=999),False),
            ('unknown_payment_type',dict(type='OTHER_PANEL',plan_id=7),False),
        ]
        if addon_day:
            subjects.append(('marzban_day_extension',
                dict(type=f'INCREASE_DAY_{oid}_{addon_day}',plan_id=7,order_id=oid,user_id=1001),True))
        if addon_volume:
            subjects.append(('marzban_volume_extension',
                dict(type=f'INCREASE_VOLUME_{oid}_{addon_volume}',plan_id=7,order_id=oid,user_id=1001),True))
        baseline=purchase.snapshot(work,env,purchase.SIDE[1])
        for name,pay,want in subjects:
            got=evaluate(work,env,pay)
            if got!=want:raise RuntimeError(f'{name} got={got},expected={want}')
            if purchase.snapshot(work,env,purchase.SIDE[1])!=baseline:
                raise RuntimeError('Payment preflight mutated DB: '+name)
            observations.append({'case':name,'expected_supported':want,'pass':True})
        for panel in ('sanaei','alireza','normal','mystery'):
            sql(work,env,"UPDATE server_config SET type='"+panel+"' WHERE id=2")
            if evaluate(work,env,dict(type='BUY_SUB',plan_id=7,server_id=2)):
                raise RuntimeError('Retired panel accepted '+panel)
            if evaluate(work,env,dict(type='RENEW_ACCOUNT',plan_id=7,order_id=oid)):
                raise RuntimeError('Retired panel renewal accepted '+panel)
            observations.append({'case':'retired_'+panel,'expected_supported':False,'pass':True})
            sql(work,env,"UPDATE server_config SET type='marzban' WHERE id=2")
        sql(work,env,'UPDATE server_plans SET inbound_id=8 WHERE id=7')
        if evaluate(work,env,dict(type='BUY_SUB',plan_id=7,server_id=2)):
            raise RuntimeError('Marzban with inbound accepted')
        if evaluate(work,env,dict(type='RENEW_ACCOUNT',plan_id=7,order_id=oid)):
            raise RuntimeError('Order linked to inbound plan accepted')
        observations.append({'case':'invalid_inbound_plan','expected_supported':False,'pass':True})
        sql(work,env,'UPDATE server_plans SET inbound_id=0 WHERE id=7')
        sql(work,env,'UPDATE orders_list SET inbound_id=8 WHERE id='+str(oid))
        if evaluate(work,env,dict(type='RENEW_ACCOUNT',plan_id=7,order_id=oid)):
            raise RuntimeError('Order with obsolete inbound accepted')
        observations.append({'case':'invalid_inbound_order','expected_supported':False,'pass':True})
        sql(work,env,'UPDATE orders_list SET inbound_id=0 WHERE id='+str(oid))
    report={'complete':True,'cases':len(observations),'schema_tables':23,
            'original_mariadb_socket_only':True,'financial_sql_mutations':0,
            'payment_provider_requests':0,'vpn_provider_requests':0,
            'live_payment_tested':False,'cases_detail':observations}
    if args.report:args.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps({k:v for k,v in report.items() if k!='cases_detail'}))
if __name__=='__main__':
    main()
