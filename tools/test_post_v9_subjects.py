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
$lock=($argv[5]??'0')==='1';
if ($lock) $db->begin_transaction();
echo achbotSupportsPaymentSubject($db,$subject,$lock)?'SUPPORTED':'BLOCKED';
if ($lock) $db->rollback();
$db->close();
'''

def sql(work, env, query):
    return native.sql(work,env,query,purchase.SIDE[1])

def evaluate(work, env, pay, lock=False):
    cmd=['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
         '-d','allow_url_fopen=0',
         '-d','disable_functions=exec,shell_exec,system,passthru,popen,proc_open,mail',
         '-r', PHP, str(ROOT/'src/Purchase/Core.php'),
         purchase.SIDE[1],str(work/'mysql.sock'),json.dumps(pay),'1' if lock else '0']
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
        # Valid NEW quotes carry their issued whole quantity. Do not depend
        # on empty historical add-on tables and silently skip this boundary.
        addon_day='9970';addon_volume='9971'
        sql(work,env,'INSERT INTO increase_day(id,volume,price) VALUES(9970,7,100)')
        sql(work,env,'INSERT INTO increase_plan(id,volume,price) VALUES(9971,8,100)')
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
        # A read-only subject may omit the redundant server hint. A present
        # hint must agree with the authoritative referenced plan/order.
        subjects.extend([
            ('marzban_purchase_omitted_server',dict(type='BUY_SUB',plan_id=7),True),
            ('marzban_renew_explicit_matching_server',dict(type='RENEW_ACCOUNT',
                 plan_id=7,order_id=oid,user_id=1001,server_id=2),True),
            ('marzban_renew_explicit_conflicting_server',dict(type='RENEW_ACCOUNT',
                 plan_id=7,order_id=oid,user_id=1001,server_id=999),False),
        ])
        for label,sid in [('zero',0),('null',None),('bool',True),
                          ('fractional',2.5),('malformed','2suffix')]:
            subjects.append(('marzban_renew_explicit_'+label+'_server',
                 dict(type='RENEW_ACCOUNT',plan_id=7,order_id=oid,
                      user_id=1001,server_id=sid),False))
        pinned=[]
        for kind,sku,field,other,units,limit in (
            ('DAY',addon_day,'day','volume',7,36500),
            ('VOLUME',addon_volume,'volume','day',8,2147483647),
        ):
            quote=dict(type=f'INCREASE_{kind}_{oid}_{sku}',plan_id=7,
                       server_id=2,order_id=oid,user_id=1001,
                       **{field:units,other:0})
            pinned.append((kind,sku,quote))
            subjects.append(('marzban_'+kind.lower()+'_extension',quote,True))
            omitted={k:v for k,v in quote.items() if k!='server_id'}
            subjects.extend([
                ('marzban_'+kind.lower()+'_extension_omitted_server',omitted,True),
                ('marzban_'+kind.lower()+'_extension_conflicting_server',
                 dict(quote,server_id=999),False),
                ('marzban_'+kind.lower()+'_extension_order_mismatch',
                 dict(omitted,order_id=999),False),
                ('marzban_'+kind.lower()+'_extension_owner_mismatch',
                 dict(omitted,user_id=9002),False),
                ('marzban_'+kind.lower()+'_extension_plan_mismatch',
                 dict(omitted,plan_id=8),False),
            ])
            # Legacy zero/NULL/missing or inconsistent quantities cannot be
            # inferred from a mutable SKU, even when the SKU is valid today.
            for label,values in (
                ('missing',{}),('null',{field:None,other:0}),
                ('zero',{field:0,other:0}),('negative',{field:-1,other:0}),
                ('fractional',{field:1.5,other:0}),
                ('overflow',{field:limit+1,other:0}),
                ('other_axis',{field:units,other:1}),
                ('other_null',{field:units,other:None}),
                ('other_missing',{field:units}),
            ):
                invalid={k:v for k,v in quote.items() if k not in (field,other)}
                invalid.update(values)
                subjects.append(('legacy_'+kind.lower()+'_'+label,invalid,False))
        baseline=purchase.snapshot(work,env,purchase.SIDE[1])
        for name,pay,want in subjects:
            got=evaluate(work,env,pay)
            if got!=want:raise RuntimeError(f'{name} got={got},expected={want}')
            if purchase.snapshot(work,env,purchase.SIDE[1])!=baseline:
                raise RuntimeError('Payment preflight mutated DB: '+name)
            observations.append({'case':name,'expected_supported':want,'pass':True})
        for name,pay,want in [
            ('locked_renew_omitted_server',dict(type='RENEW_ACCOUNT',
                 plan_id=7,order_id=oid,user_id=1001),True),
            ('locked_renew_conflicting_server',dict(type='RENEW_ACCOUNT',
                 plan_id=7,order_id=oid,user_id=1001,server_id=999),False),
        ]:
            if evaluate(work,env,pay,lock=True)!=want:
                raise RuntimeError('Locked payment subject admission failed '+name)
            if purchase.snapshot(work,env,purchase.SIDE[1])!=baseline:
                raise RuntimeError('Locked payment preflight mutated SQL')
            observations.append({'case':name,'expected_supported':want,'pass':True})
        # Issued pays units stay authoritative after administrator SKU
        # edits/deletion; no current catalog value can rewrite the quote.
        for kind,sku,quote in pinned:
            table='increase_day' if kind=='DAY' else 'increase_plan'
            for change in ('changed','deleted'):
                if change=='changed':
                    sql(work,env,f'UPDATE {table} SET volume=99,price=777 WHERE id={sku}')
                else:
                    sql(work,env,f'DELETE FROM {table} WHERE id={sku}')
                before=purchase.snapshot(work,env,purchase.SIDE[1])
                if not evaluate(work,env,quote):
                    raise RuntimeError('Pinned quote lost after SKU '+change+' '+kind)
                if purchase.snapshot(work,env,purchase.SIDE[1])!=before:
                    raise RuntimeError('Pinned subject preflight mutated SQL')
                observations.append({'case':'pinned_'+kind.lower()+'_sku_'+change,
                                     'expected_supported':True,'pass':True})
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
            'live_payment_tested':False,
            'omitted_server_identity_verified':True,
            'explicit_server_mismatch_rejected':True,
            'locked_subject_resolution_readonly':True,'cases_detail':observations}
    if args.report:args.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps({k:v for k,v in report.items() if k!='cases_detail'}))
if __name__=='__main__':
    main()
