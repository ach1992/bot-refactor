#!/usr/bin/env python3
"""Actual pay/index + initiation/page source; socket-only DB and loopback HTTP."""
from __future__ import annotations
import argparse,json,socket,subprocess,time,urllib.error,urllib.parse,urllib.request
from pathlib import Path
import test_access_state as owned
import test_v6_native as native
import test_v8_native as purchase
import test_post_v9_purchases as stages
import catalog_source
import test_post_v9_customer_errors as privacy
ROOT=Path(__file__).resolve().parents[1]
DB=purchase.SIDE[1]
BASE='f3c6fa956edcdfcb057a05a80da2c05741de5a69'
CASES=[
 ('unsupported',1001,'BUY_SUB','c2f74dfd7e'),
 ('missing-secret',1002,'INCREASE_WALLET','c4414cfacc'),
 ('missing-merchant',1003,'INCREASE_WALLET','df2a7c68c7'),
]
def check(ok,message):
    if not ok:raise AssertionError(message)
def sql(w,e,q):return native.sql(w,e,q,DB).strip()
def php(w,stage,query):
    run=subprocess.run(['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
        '-d','allow_url_fopen=0','-d','display_errors=1',str(w/'driver.php'),query],
        cwd=stage,capture_output=True,text=True,timeout=10)
    check(run.returncode==0 and not run.stderr,'actual initiation PHP failure')
    return run.stdout
def main():
    parser=argparse.ArgumentParser();parser.add_argument('--run-disposable',required=True,action='store_true');parser.parse_args()
    amendment=json.loads((ROOT/'tools/text_catalog_amendments.json').read_text())
    corrections=amendment['approved_copy'];copy=catalog_source.templates(ROOT);checks=0
    check('src/Finance/GatewayInitiation.php' in privacy.CUSTOMER_SURFACES,'public initiation omitted from audience guard')
    privacy.verify(ROOT,copy)
    for key,entry in corrections.items():
        poisoned=copy|{key:entry['before']}
        try:privacy.verify(ROOT,poisoned)
        except RuntimeError as error:check('GatewayInitiation.php' in str(error),'public audience negative rejected wrong surface')
        else:raise AssertionError('public audience negative missed unsafe original: '+key)
        checks+=1
    with owned.owned_database() as (w,e):
        purchase.init(w,e,'http://127.0.0.1:9')
        for migration in ['001_marzban_panel_group_id.sql','003_payment_attempts.sql','006_payment_message_refs.sql']:
            sql(w,e,(ROOT/'migrations/post-v9'/migration).read_text())
        app=w/'app';stages.stage_source(app,True)
        flags={'nowPaymentWallet':'on','nowPaymentOther':'on','nextpay':'on','zarinpal':'on'}
        sql(w,e,"INSERT INTO setting(type,value) VALUES ('BOT_STATES','"+json.dumps(flags)+"')")
        keys={'manualUsdtRateToman':'100000','nowpayment':''}
        sql(w,e,"INSERT INTO setting(type,value) VALUES ('PAYMENT_KEYS','"+json.dumps(keys)+"')")
        sql(w,e,"UPDATE server_config SET type='sanaei' WHERE id=2")
        for case,actor,kind,suffix in CASES:
            if actor!=1001:sql(w,e,f"INSERT INTO users(userid,name,username,refcode,date) VALUES ({actor},'Fixture','fixture','0','1700000000')")
            sql(w,e,f"INSERT INTO pays(hash_id,user_id,type,plan_id,server_id,price,request_date,state) VALUES ('privacy-{case}',{actor},'{kind}',7,2,100000,UNIX_TIMESTAMP(),'pending')")
        # Only owned connection/config values are replaced. Both public entry
        # and complete shipping initiation/page/catalog modules remain real.
        bootstrap="""<?php
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(static function($n,$m){if ($n===E_DEPRECATED)return true;throw new ErrorException($m,0,$n);});
$connection=new mysqli('localhost','root','',"""+json.dumps(DB)+""",0,"""+json.dumps(str(w/'mysql.sock'))+""");
$connection->set_charset('utf8mb4');
$botState=json_decode("""+json.dumps(json.dumps(flags))+""",true);
$botUsername='fixture_bot';
$nowpaymentsIpnSecret=($_GET['fixture']??'')==='missing-secret'?'':'PRIVATE_GATEWAY_SIGNING_CANARY';
require_once __DIR__.'/src/Purchase/Core.php';
require_once __DIR__.'/src/Finance/ReceiptHelpers.php';
require_once __DIR__.'/src/Finance/GatewayClient.php';
"""
        (app/'baseInfo.php').write_text('<?php // Synthetic source-only instance.\n')
        (app/'config.php').write_text(bootstrap)
        (w/'driver.php').write_text('<?php parse_str($argv[1],$_GET);require '+json.dumps(str(app/'pay/index.php'))+';')
        topic=app/'messages/fa/gateway.php';current=topic.read_bytes()
        archived=subprocess.check_output(['git','show',BASE+':messages/fa/gateway.php'],cwd=ROOT)
        before=purchase.snapshot(w,e,DB)
        topic.write_bytes(archived)
        for case,actor,kind,suffix in CASES:
            query=urllib.parse.urlencode({'nowpayment':'','hash_id':'privacy-'+case,'fixture':case})
            body=php(w,app,query)
            key='gateway.gateway_initiation.mark_checkout_uncertain.message.'+suffix
            check(corrections[key]['before'] in body,'archived public copy RED missing: '+case)
            checks+=1
        check(purchase.snapshot(w,e,DB)==before,'archived denial changed financial state')
        topic.write_bytes(current)
        def verify(body,case,suffix):
            key='gateway.gateway_initiation.mark_checkout_uncertain.message.'+suffix
            check(corrections[key]['after'] in body,'public safe guidance missing: '+case)
            check(all(x not in body for x in ['PRIVATE_GATEWAY_SIGNING_CANARY','SQLSTATE','مرزبان','پنل','NOWPayments','NowPayments','کلید امن','کلید درگاه']),'public initiation leaks setup/provider detail: '+case)
            check('data-status="completed"' not in body,'denial falsely claims settlement')
        for case,actor,kind,suffix in CASES:
            query=urllib.parse.urlencode({'nowpayment':'','hash_id':'privacy-'+case,'fixture':case})
            verify(php(w,app,query),case,suffix);checks+=1
        with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
        with (w/'http.log').open('w+') as log:
            server=subprocess.Popen(['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
                '-d','allow_url_fopen=0','-d','display_errors=1','-S','127.0.0.1:'+str(port),'-t',str(app)],
                cwd=app,env=e,stdout=log,stderr=log)
            try:
                deadline=time.monotonic()+5
                while True:
                    try:
                        with socket.create_connection(('127.0.0.1',port),timeout=.2):break
                    except OSError:
                        check(time.monotonic()<deadline,'owned HTTP listener unavailable');time.sleep(.03)
                for case,actor,kind,suffix in CASES:
                    query=urllib.parse.urlencode({'nowpayment':'','hash_id':'privacy-'+case,'fixture':case})
                    with urllib.request.urlopen('http://127.0.0.1:'+str(port)+'/pay/index.php?'+query,timeout=10) as response:
                        check(response.status==200 and response.headers['Cache-Control']=='private, no-store, max-age=0','public payment response contract')
                        verify(response.read().decode(),case,suffix)
                    checks+=1
            finally:server.terminate();server.wait(timeout=5)
            log.seek(0);check('PRIVATE_GATEWAY_SIGNING_CANARY' not in log.read(),'HTTP logs leaked signing value')
        check(purchase.snapshot(w,e,DB)==before,'denied public initiation changed wallet/order/attempt state')
    print(json.dumps({'status':'PASS','cases':checks,'actual_public_pay_entry':True,'actual_initiation_and_renderer':True,
      'archived_unsafe_copy_RED':3,'static_audience_negative_controls':3,'native_public_paths':3,'http_public_paths':3,
      'financial_snapshot_unchanged':True,'provider_or_telegram_calls':0,'owned_mariadb_and_loopback_only':True}))
if __name__=='__main__':main()
