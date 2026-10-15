#!/usr/bin/env python3
"""Real source route tests with original-schema isolated MariaDB / owned HTTP.

Mixed legacy-panel catalogue, direct forged plan callbacks and a current
Marzban subscription QR use only synthetic information. No live credentials.
"""
import argparse
import json
from pathlib import Path
import test_access_state as owned
import test_v6_native as native
import test_v8_native as purchase
import test_post_v9_purchases as src

ROOT=Path(__file__).resolve().parents[1]

def check_no_effects(work,env,panel,stage,db,kind,spec,tag):
    before=purchase.snapshot(work,env,db)
    panel.trace.clear()
    result=purchase.run(work,env,stage,db,kind,spec,modern=True)
    after=purchase.snapshot(work,env,db)
    if panel.trace:
        raise RuntimeError('Unexpected panel API on '+tag)
    if result['exception'] or result['warnings']:
        raise RuntimeError('PHP warnings/errors on '+tag+':'+repr(result)[:600])
    for key in ('payments','orders','capacity'):
        if before[key]!=after[key]:
            raise RuntimeError('Unsupported or display-only request changed '+key+' on '+tag)
    return result

def main():
    p=argparse.ArgumentParser()
    p.add_argument('--run-disposable',action='store_true',required=True)
    p.add_argument('--report',type=Path)
    a=p.parse_args()
    records=[]
    with owned.owned_database() as (work,env), native.owned_provider() as (panel,url):
        stage=work/'post-v9'
        src.stage_source(stage,True)
        db=purchase.SIDE[1]
        purchase.init(work,env,url)
        # Copy synthetically another server/config/plan. No deployed records.
        native.sql(work,env,'''
INSERT INTO server_info (id,title,ucount,remark,flag,active,state)
VALUES (3,'Retired synthetic server',10,'obsolete','',1,1);
INSERT INTO server_plans (id,fileid,catid,server_id,inbound_id,acount,
 title,protocol,days,volume,type,price,descr,pic,active,step,date,custom_sni)
SELECT 9,'9',catid,3,0,6,'Obsolete paid','vless',30,2,'normal',
 100,'synthetic','',1,0,'now',custom_sni FROM server_plans WHERE id=7;
INSERT INTO server_plans (id,fileid,catid,server_id,inbound_id,acount,
 title,protocol,days,volume,type,price,descr,pic,active,step,date,custom_sni)
SELECT 10,'10',catid,2,7,6,'Obsolete inbound','vless',30,2,'normal',
 100,'synthetic','',1,0,'now',custom_sni FROM server_plans WHERE id=7;
''',db)
        controls=[
            ('entryandbulk',dict(data='buySubscription',actor=1001),'mixed_server_catalogue'),
            ('catalogue',dict(data='selectServer3_none',actor=1001),'retired_server_category'),
            ('catalogue',dict(data='selectCategory1_3_none',actor=1001),'retired_server_plan'),
            ('catalogue',dict(data='selectCategory1_2_none',actor=1001),'marzban_only_plan_list'),
            ('selectplan',dict(data='selectPlan9_1_none',actor=1001),'retired_forged_purchase'),
            ('selectplan',dict(data='selectPlan10_1_none',actor=1001),'inbound_forged_purchase'),
            ('catalogue',dict(data='selectCustomePlan9_1_none',actor=1001),'retired_forged_custom'),
            ('trialcatalogue',dict(data='getTestAccount',actor=1001),'filtered_free_trial'),
            ('trialprovision',dict(data='freeTrial9_normal',actor=1001),'retired_forged_trial'),
            ('entryandbulk',dict(data='createAccPlan9',actor=9001),'admin_forged_bulk'),
        ]
        for kind,spec,tag in controls:
            obs=check_no_effects(work,env,panel,stage,db,kind,spec,tag)
            output=json.dumps(obs['events'],ensure_ascii=False)
            if tag=='mixed_server_catalogue':
                if 'selectServer2_' not in output or 'selectServer3_' in output:
                    raise RuntimeError('Legacy server appears in user catalogue')
            if tag=='marzban_only_plan_list':
                if 'selectPlan7_' not in output or 'selectPlan10_' in output:
                    raise RuntimeError('Legacy inbound plan appears in paid list')
            if tag=='filtered_free_trial' and 'freeTrial8_' not in output:
                raise RuntimeError('Valid Marzban free trial hidden')
            if tag in ('retired_forged_purchase','inbound_forged_purchase',
                       'retired_forged_trial','admin_forged_bulk'):
                if not any(event['name']=='alert' for event in obs['events']):
                    raise RuntimeError('Forged callback not given bounded message: '+tag)
            records.append({'case':tag,'pass':True,'sql_payment_order_mutations':0,'provider_calls':0})
        # Build one valid owned order and load the actual Checkout PHP route.
        native.sql(work,env,'''INSERT INTO orders_list
            (userid,token,transid,fileid,server_id,inbound_id,remark,uuid,
             protocol,expire_date,link,amount,status,date)
            VALUES (1001,'safe-qr-token','',7,2,0,'safe-qr','id','vless',
                    2000000000,'[]',100,1,'synthetic')''',db)
        oid=int(native.sql(work,env,"SELECT id FROM orders_list WHERE remark='safe-qr'",db).strip())
        # The QR-code encoder is a local spy; no network or actual customer media.
        qrlib=stage/'phpqrcode/qrlib.php'
        qrlib.write_text('''<?php
class QRcode {
  public static function png($payload,$file,$ecc,$scale,$margin) {
    file_put_contents(dirname(__DIR__).'/owned-qr-payload.txt',$payload);
    file_put_contents($file,'owned-synthetic-png-placeholder');
  }
}
''')
        old=purchase.block
        try:
            purchase.block=lambda kind,modern: (
                b"<?php\nif ((require __DIR__ . '/src/Finance/Routes/Checkout.php') === null) return;\n"
                if kind=='qrcheckout' else old(kind,modern)
            )
            report=check_no_effects(work,env,panel,stage,db,'qrcheckout',
                                     dict(data='showQrSub'+str(oid),actor=1001),'marzban_qr')
        finally:
            purchase.block=old
        value=(stage/'owned-qr-payload.txt').read_text()
        if value != url+'/sub/safe-qr-token' and value != 'http://127.0.0.1:'+url.rsplit(':',1)[-1]+'/sub/safe-qr-token':
            # Native seed stores the URL returned by the loopback server.
            stored=native.sql(work,env,"SELECT panel_url FROM server_config WHERE id=2",db).strip()
            if value != stored.rstrip('/')+'/sub/safe-qr-token':
                raise RuntimeError('QR sent wrong URL: '+repr((value,stored)))
        if 'settings/subLink.php' in value:
            raise RuntimeError('Live Marzban QR still points at retired endpoint')
        records.append({'case':'marzban_qr','pass':True,'correct_provider_url':True,
                        'sql_payment_order_mutations':0,'provider_calls':0})
    report={'complete':True,'cases':len(records),'original_schema_tables':23,
            'financial_mutations':0,'real_payment_or_telegram_tested':False,
            'scenarios':records}
    if a.report:a.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps({k:v for k,v in report.items() if k!='scenarios'}))
if __name__=='__main__':main()
