#!/usr/bin/env python3
"""Original-schema native financial route guards with mocked payment transport.

The current PHP modules run unmodified against a socket-only disposable
23-table database. curl functions are disabled and replaced by local spies:
no payment, Marzban, Telegram or internet request is possible.
"""
from __future__ import annotations

import argparse
import json
import subprocess
from pathlib import Path

import test_access_state as owned
import test_v6_native as native
import test_v8_native as purchase
import test_post_v9_purchases as stages

ROOT = Path(__file__).resolve().parents[1]
DB = purchase.SIDE[1]

PHP_DRIVER = r"""<?php
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');
set_error_handler(function($level,$message){ throw new ErrorException($message, 0, $level); });
$_SERVER['SCRIPT_FILENAME'] = 'owned-finance-guard.php';
$root = $argv[1];
$dbname = $argv[2];
$socket = $argv[3];
$spec = json_decode($argv[4], true, 512, JSON_THROW_ON_ERROR);
$_GET = $spec['get'];
$GLOBALS['network_calls'] = [];
$GLOBALS['error'] = null;
$GLOBALS['mock_invoice'] = $spec['mock_invoice'] ?? '';
$botState = [];
$botUrl = 'http://127.0.0.1:9/owned-fixture/';
$USDTPrice = 100000;
$USDTPriceagent = 100000;
$paymentKeys = ['nowpayment'=>'SYNTHETIC-LOCAL-ONLY'];
function curl_init() { return (object)['url' => '']; }
function curl_setopt($handle,$option,$value) {
    if ($option === CURLOPT_URL) $handle->url = (string)$value;
    return true;
}
function curl_exec($handle) {
    $GLOBALS['network_calls'][] = $handle->url;
    if (str_contains($handle->url, '/v1/invoice')) {
        return json_encode(['id'=>'owned-new-invoice',
                             'invoice_url'=>'http://127.0.0.1:9/owned-no-navigation']);
    }
    return json_encode(['invoice_id'=>$GLOBALS['mock_invoice'],
                        'payment_status'=>'waiting']);
}
function curl_close($handle) {}
function curl_error($handle) { return ''; }
function curl_getinfo($handle,$option) { return 200; }
ob_start();
register_shutdown_function(function () {
    $html = ob_get_contents();
    ob_end_clean();
    $last=error_get_last();
    echo json_encode([
      'network_calls'=>$GLOBALS['network_calls'],
      'error'=>$GLOBALS['error'],
      'form'=>$html,
      'fatal'=>($last && in_array($last['type'], [E_ERROR,E_PARSE,E_COMPILE_ERROR])) ? $last['message'] : null
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
});
try {
    $connection = new mysqli('localhost', 'root', '', $dbname, 0, $socket);
    $connection->set_charset('utf8mb4');
    require $root . '/src/Purchase/Core.php';
    if ($spec['route'] === 'return') {
        require $root . '/src/Finance/GatewayReturn.php';
    } elseif ($spec['route'] === 'initiate') {
        require $root . '/src/Finance/GatewayInitiation.php';
    } else {
        throw new RuntimeException('unknown route');
    }
} catch (Throwable $e) {
    $GLOBALS['error'] = get_class($e) . ': ' . $e->getMessage();
}
"""

def sql(work,env,query):
    return native.sql(work,env,query,DB)

def run_php(work, env, stage, spec):
    script=work/'owned-finance-guard.php'
    if not script.exists():
        script.write_text(PHP_DRIVER)
    cmd=['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
         '-d','extension=curl','-d','allow_url_fopen=0',
         '-d','disable_functions=curl_init,curl_setopt,curl_exec,curl_close,curl_error,curl_getinfo,exec,shell_exec,system,passthru,popen,proc_open,mail',
         str(script),str(stage),DB,str(work/'mysql.sock'),json.dumps(spec)]
    p=subprocess.run(cmd,env=env,cwd=work,capture_output=True,timeout=20)
    if p.returncode or p.stderr:
        raise RuntimeError('native finance php error: '+repr((p.returncode,p.stdout[-500:],p.stderr[-600:])))
    try:
        result=json.loads(p.stdout)
    except json.JSONDecodeError as error:
        raise RuntimeError('native finance output not JSON: '+repr(p.stdout[-500:])) from error
    if result['error'] or result['fatal']:
        raise RuntimeError('native finance php runtime failure: '+repr(result)[:600])
    return result

def seed_payment(work,env,name,type_name,plan=0,server=0,payid=None):
    invoice=payid if payid is not None else 'invoice-'+name
    query="""INSERT INTO pays
    (hash_id,payid,user_id,type,plan_id,server_id,volume,day,price,request_date,state)
    VALUES ('{}','{}',1001,'{}',{},{},0,0,100,1700000000,'pending')""".format(
       name,invoice,type_name,plan,server)
    sql(work,env,query)
    return invoice

def snapshot(work,env):
    return purchase.snapshot(work,env,DB)

def verify_unchanged(work,env,before,label):
    if snapshot(work,env)!=before:
        raise RuntimeError('Unexpected financial/account SQL mutation on '+label)

def main():
    p=argparse.ArgumentParser()
    p.add_argument('--run-disposable',required=True,action='store_true')
    p.add_argument('--report',type=Path)
    args=p.parse_args()
    records=[]
    with owned.owned_database() as (work,env):
        stage=work/'current-finance'
        stages.stage_source(stage,True)
        purchase.init(work,env,'http://127.0.0.1:9')
        if len(sql(work,env,'SHOW TABLES').splitlines()) != 23:
            raise RuntimeError('Original schema missing')
        sql(work,env,"INSERT INTO setting (type,value) VALUES ('PAYMENT_KEYS','{\"nowpayment\":\"SYNTHETIC-LOCAL-ONLY\"}')")
        seed_payment(work,env,'unsupported-now','RETIRED_PANEL',7,2)
        seed_payment(work,env,'marzban-now','BUY_SUB',7,2,'owned-verified-invoice')
        seed_payment(work,env,'wallet-now','INCREASE_WALLET',0,0,'wallet-verified-invoice')
        seed_payment(work,env,'unsupported-init','RETIRED_PANEL',7,2)
        seed_payment(work,env,'unsupported-zarin','RETIRED_PANEL',7,2)
        seed_payment(work,env,'unsupported-next','RETIRED_PANEL',7,2)
        seed_payment(work,env,'wallet-init','INCREASE_WALLET',0,0)
        seed_payment(work,env,'nohash-now','RETIRED_PANEL',7,2,'retired-fallback-id')
        seed_payment(work,env,'inbound-now','BUY_SUB',7,2,'inbound-invoice')
        for name, spec in [
            ('retired_callback',dict(route='return',get={'nowpayment':'','NP_id':'owned-pmt','hash_id':'unsupported-now'})),
            ('unknown_callback',dict(route='return',get={'nowpayment':'','NP_id':'owned-pmt','hash_id':'no-such-pay'})),
            ('retired_fallback_no_hash',dict(route='return',get={'nowpayment':'','NP_id':'retired-fallback-id'})),
            ('retired_initiation',dict(route='initiate',get={'nowpayment':'','hash_id':'unsupported-init'})),
            ('retired_zarinpal_callback',dict(route='return',get={'zarinpal':'',
                     'hash_id':'unsupported-zarin','Authority':'owned-synthetic'})),
            ('retired_nextpay_callback',dict(route='return',get={'nextpay':'',
                     'trans_id':'invoice-unsupported-next'})),
        ]:
            before=snapshot(work,env)
            res=run_php(work,env,stage,spec)
            verify_unchanged(work,env,before,name)
            if res['network_calls'] or not res['form']:
                raise RuntimeError('Unsupported subject reached gateway or lacked error: '+name)
            records.append(dict(case=name,pass_=True,financial_mutations=0,external_requests=0))
        sql(work,env,'UPDATE server_plans SET inbound_id=7 WHERE id=7')
        before=snapshot(work,env)
        res=run_php(work,env,stage,dict(route='return',
                get={'nowpayment':'','NP_id':'owned-pmt','hash_id':'inbound-now'}))
        verify_unchanged(work,env,before,'forged_inbound_callback')
        if res['network_calls']:
            raise RuntimeError('Invalid Marzban inbound reached payment provider')
        records.append(dict(case='forged_inbound_callback',pass_=True,financial_mutations=0,external_requests=0))
        sql(work,env,'UPDATE server_plans SET inbound_id=0 WHERE id=7')
        sql(work,env,"UPDATE server_config SET type='normal' WHERE id=2")
        before=snapshot(work,env)
        res=run_php(work,env,stage,dict(route='return',
                get={'nowpayment':'','NP_id':'owned-pmt','hash_id':'marzban-now'}))
        verify_unchanged(work,env,before,'retired_provider_live_reference')
        if res['network_calls']:
            raise RuntimeError('Retired panel reached payment provider')
        records.append(dict(case='retired_provider_live_reference',pass_=True,financial_mutations=0,external_requests=0))
        sql(work,env,"UPDATE server_config SET type='marzban' WHERE id=2")
        before=snapshot(work,env)
        res=run_php(work,env,stage,dict(route='return',
            get={'nowpayment':'','NP_id':'owned-pmt','hash_id':'wallet-now'},
            mock_invoice='different-invoice'))
        verify_unchanged(work,env,before,'mismatched_confirmed_invoice')
        if len(res['network_calls'])!=1 or 'v1/payment/owned-pmt' not in res['network_calls'][0]:
            raise RuntimeError('Supported wallet payment never reached fake verifier')
        if 'مطابقت' not in res['form']:
            raise RuntimeError('Invoice mismatch did not fail closed')
        records.append(dict(case='positive_subject_invoice_mismatch',pass_=True,financial_mutations=0,external_requests=1))
        before=snapshot(work,env)
        res=run_php(work,env,stage,dict(route='initiate',
                    get={'nowpayment':'','hash_id':'wallet-init'}))
        if len(res['network_calls'])!=1 or '/v1/invoice' not in res['network_calls'][0]:
            raise RuntimeError('Independent wallet top-up did not initiate mock invoice')
        if sql(work,env,"SELECT payid FROM pays WHERE hash_id='wallet-init'").strip()!='owned-new-invoice':
            raise RuntimeError('Wallet top-up invoice ID was not stored')
        records.append(dict(case='positive_wallet_gateway_initiation',pass_=True,synthetic_invoice=True,external_requests=1))
        # Actual admin route, including old cached/forged callbacks, cannot
        # perform the inherited database-only server rewrite.
        native.sql(work,env,'''
        INSERT INTO orders_list
            (userid,token,transid,fileid,server_id,inbound_id,remark,uuid,
             protocol,expire_date,link,amount,status,date)
        VALUES (1001,'admin-callback-token','',7,2,0,'admin-fixture','id','vless',
                2000000000,'[]',100,1,'synthetic')''',DB)
        oid=int(sql(work,env,"SELECT id FROM orders_list WHERE remark='admin-fixture'").strip())
        sql(work,env,'''INSERT INTO server_plans
            (id,fileid,catid,server_id,inbound_id,acount,title,protocol,days,
             volume,type,price,descr,pic,active,step,date,custom_sni)
            SELECT 10,'10',catid,2,7,acount,'Unsupported inbound',protocol,
                   days,volume,type,price,descr,pic,active,step,date,custom_sni
            FROM server_plans WHERE id=7;
            INSERT INTO server_plans
            (id,fileid,catid,server_id,inbound_id,acount,title,protocol,days,
             volume,type,price,descr,pic,active,step,date,custom_sni)
            SELECT 11,'11',catid,3,0,acount,'Wrong server plan',protocol,
                   days,volume,type,price,descr,pic,active,step,date,custom_sni
            FROM server_plans WHERE id=7;''')
        old=purchase.block
        try:
            purchase.block=lambda kind,modern: (
               b"<?php\nrequire __DIR__ . '/src/Subscription/OrderManagement.php';\n"
               if kind=='order_management' else
               b"<?php\nrequire __DIR__ . '/src/Finance/Routes/RenewalPlan.php';\n"
               if kind=='external_renew' else old(kind,modern)
            )
            for name, data in [('admin_open_legacy_relocation',f'changeServerId_{oid}'),
                               ('admin_forged_location_change',f'selectServerId_{oid}_3')]:
                before=snapshot(work,env)
                obs=purchase.run(work,env,stage,DB,'order_management',
                                 dict(data=data,actor=9001),modern=True)
                verify_unchanged(work,env,before,name)
                if obs.get('exception') or obs.get('warnings'):
                    raise RuntimeError('Admin route runtime failure: '+repr(obs)[:650])
                if not any(event['name']=='alert' for event in obs['events']):
                    raise RuntimeError('Admin stale relocation callback had no error')
                records.append(dict(case=name,pass_=True,financial_mutations=0,external_requests=0))
            for name, data in [('admin_forged_inbound_plan',f'selectFileId_{oid}_10'),
                               ('admin_forged_cross_server_plan',f'selectFileId_{oid}_11')]:
                before=snapshot(work,env)
                obs=purchase.run(work,env,stage,DB,'order_management',
                                 dict(data=data,actor=9001),modern=True)
                verify_unchanged(work,env,before,name)
                if obs.get('exception') or obs.get('warnings'):
                    raise RuntimeError('Admin plan negative runtime failure: '+repr(obs)[:650])
                if not any(event['name']=='alert' for event in obs['events']):
                    raise RuntimeError('Forged plan selector had no visible rejection')
                records.append(dict(case=name,pass_=True,financial_mutations=0,external_requests=0))
            obs=purchase.run(work,env,stage,DB,'order_management',
                dict(data=f'changeFileId_{oid}_2',actor=9001),modern=True)
            if obs.get('exception') or obs.get('warnings'):
                raise RuntimeError('Marzban plan catalogue error: '+repr(obs)[:650])
            wire=json.dumps(obs['events'],ensure_ascii=False)
            if f'selectFileId_{oid}_10' in wire or f'selectFileId_{oid}_11' in wire:
                raise RuntimeError('Invalid plans leaked into administrative plan selector')
            if f'selectFileId_{oid}_7' not in wire:
                raise RuntimeError('Supported Marzban plan absent from admin selector')
            records.append(dict(case='admin_supported_plan_catalogue',pass_=True,external_requests=0))
            obs=purchase.run(work,env,stage,DB,'order_management',
                dict(data=f'selectFileId_{oid}_8',actor=9001),modern=True)
            if obs.get('exception') or obs.get('warnings'):
                raise RuntimeError('Supported same-server plan change error: '+repr(obs)[:650])
            if sql(work,env,f'SELECT fileid FROM orders_list WHERE id={oid}').strip()!='8':
                raise RuntimeError('Supported admin plan change was not committed')
            records.append(dict(case='admin_supported_plan_change',pass_=True,external_requests=0))
            for name,profile in [
                ('external_renew_other_server',{'marzban':True,'remark':'external-fixture',
                                                'uuid':'id','server_id':3}),
                ('external_renew_retired_identity',{'marzban':False,'remark':'external-fixture',
                                                   'uuid':'id','server_id':2})
            ]:
                profile_json=json.dumps(profile,separators=(',',':'))
                sql(work,env,"UPDATE users SET temp='"+profile_json+"' WHERE userid=1001")
                before=snapshot(work,env)
                obs=purchase.run(work,env,stage,DB,'external_renew',
                                 dict(data='sConfigRenewPlan7_0',actor=1001),modern=True)
                verify_unchanged(work,env,before,name)
                if obs.get('exception') or obs.get('warnings'):
                    raise RuntimeError('External renewal rejection error: '+repr(obs)[:650])
                if not any(event['name']=='alert' for event in obs['events']):
                    raise RuntimeError('Mismatched external account was accepted: '+name)
                records.append(dict(case=name,pass_=True,financial_mutations=0,external_requests=0))
            profile={'marzban':True,'remark':'external-fixture','uuid':'id','server_id':2}
            sql(work,env,"UPDATE users SET temp='"+json.dumps(profile,separators=(',',':'))+"' WHERE userid=1001")
            obs=purchase.run(work,env,stage,DB,'external_renew',
                             dict(data='sConfigRenewPlan7_0',actor=1001),modern=True)
            if obs.get('exception') or obs.get('warnings'):
                raise RuntimeError('Supported external renewal route error: '+repr(obs)[:850])
            data=sql(work,env,"SELECT server_id,description FROM pays WHERE type='RENEW_SCONFIG' AND user_id=1001 LIMIT 1").strip()
            if not data or not data.startswith('2\t') or 'external-fixture' not in data:
                raise RuntimeError('Valid external Marzban payment identity not persisted: '+repr(data))
            records.append(dict(case='positive_external_renewal_checkout',pass_=True,
                                original_schema_payment_insert=True,external_requests=0))
        finally:
            purchase.block=old
    report={'complete':True,'cases':len(records),'schema_tables':23,
       'original_mariadb_socket_only':True,'real_provider_requests':0,
       'synthetic_payment_spy':True,'operator_finance_acceptance':False,
       'scenarios':records}
    if args.report:args.report.write_text(json.dumps(report,indent=2,ensure_ascii=False)+'\n')
    print(json.dumps({k:v for k,v in report.items() if k!='scenarios'}))
if __name__=='__main__':main()
