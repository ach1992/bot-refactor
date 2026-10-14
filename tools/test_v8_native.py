#!/usr/bin/env python3
"""V8 exact V7-vs-V8 purchase+provision regression on disposable MariaDB.

PHP script execution is constrained to owned temporary files, original 23-table
socket DBs and an owned loopback Marzban HTTP emulator; no real Telegram/payment.
"""
from __future__ import annotations
import argparse
import hashlib
import json
import os
import re
import shutil
import subprocess
import sys
from pathlib import Path
import v8_contract as c
import v7_contract as v7
import test_v6_native as native
import test_access_state as support

ROOT=c.ROOT
SIDE=('achbot_v4_old','achbot_v4_new')
WALLET_START="if (preg_match('/payBuyWithWallet(.*)/', $data, $match)) {"
WALLET_END="if (preg_match('/payBuyWithCartToCart(.*)/', $data, $match)) {"

def stage(root:Path,modern:bool):
    root.mkdir(parents=True,exist_ok=True)
    shutil.copyfile(ROOT/'tests/v7/native-purchase.php',root/'native-purchase.php')
    (root/'buy_helpers.php').write_bytes((ROOT/'buy_helpers.php').read_bytes() if modern else c.old('buy_helpers.php'))
    p=root/'src/Panel/Accounts.php';p.parent.mkdir(parents=True,exist_ok=True)
    shutil.copyfile(ROOT/'src/Panel/Accounts.php',p)
    for name in v7.MODULES + (c.MODULES if modern else []):
        p=root/name;p.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(ROOT/name,p)
    (root/'settings').mkdir(exist_ok=True)
    (root/'settings/temp.txt').write_text('3000-0')

def block(kind:str,modern:bool) -> bytes:
    if kind=='wallet':
        if modern:
            return (b"<?php\nif (($achbotV8Result = require __DIR__ . '/src/Finance/Routes/Checkout.php') === null) return;\n"
                    b"if ($achbotV8Result === '__ACHBOT_V8_GOTO_START__') goto GOTOSTART;\n"
                    b"GOTOSTART:;\n")
        src=c.old('bot.php')
        _,contents,_=v7.span(src,WALLET_START.encode(),WALLET_END.encode(),'V7 wallet purchase consumer')
        return b'<?php\n'+contents+b'\nGOTOSTART:;\n'
    module=dict((name.rsplit('/',1)[-1].replace('.php','').lower(),name) for name,_,_ in v7.ROUTES)
    if kind not in module:raise ValueError('Unknown V7 compatible route '+kind)
    return b"<?php\nif ((require __DIR__ . '/"+module[kind].encode()+b"') === null) return;\n"

def init(work:Path,env:dict,url:str):
    for db in SIDE:
        native.sql(work,env,'DELETE FROM orders_list; DELETE FROM pays; DELETE FROM server_plans; DELETE FROM server_info; DELETE FROM server_categories; UPDATE users SET step=\'none\', freetrial=NULL, temp=NULL, wallet=50000, is_agent=0, discount_percent=NULL;',db)
    native.seed(work,env,url)
    sqls="""
DELETE FROM orders_list;
INSERT INTO server_info (id,title,ucount,remark,flag,active,state)
 VALUES (2,'Fixture purchase server','10','buyfixture','',1,1);
INSERT INTO server_categories (id,server_id,title,parent,step,active)
 VALUES (1,'2','Fixture category',0,4,1);
UPDATE server_plans SET server_id=2,inbound_id=0,acount=6,catid=1,price=100,
 protocol='vless',days=30,volume=2,type='normal',active=1,
 custom_sni='{"inbounds":{"vless":["inbound"]}}' WHERE id=7;
INSERT INTO server_plans (id,fileid,catid,server_id,inbound_id,acount,title,protocol,days,volume,type,price,descr,pic,active,step,date,custom_sni)
 VALUES (8,'8',1,2,0,6,'Free fixture','vless',30,2,'normal',0,'fixture-free','',1,0,'fixture','{"inbounds":{"vless":["inbound"]}}');
UPDATE users SET is_agent=1, discount_percent='{"normal":10,"plans":{},"servers":{}}', wallet=50000 WHERE userid=9002;
"""
    for db in SIDE:native.sql(work,env,sqls,db)

def snapshot(work,env,db):
    queries={
        'users':'SELECT userid,wallet,step,IFNULL(freetrial,\'NULL\'),IFNULL(temp,\'NULL\'),is_agent FROM users ORDER BY userid',
        'payments':'SELECT hash_id,user_id,type,plan_id,server_id,volume,day,price,state,agent_bought,agent_count,IFNULL(description,\'NULL\') FROM pays ORDER BY id',
        'orders':'SELECT userid,fileid,server_id,inbound_id,remark,uuid,protocol,link,amount,status,agent_bought FROM orders_list ORDER BY id',
        'capacity':'SELECT id,ucount,active,state FROM server_info ORDER BY id',
        'plans':'SELECT id,catid,server_id,acount,price,active,title FROM server_plans ORDER BY id',
        'categories':'SELECT id,server_id,title,parent,active FROM server_categories ORDER BY id',
    }
    return {k:native.sql(work,env,q,db) for k,q in queries.items()}

def run(work,env,root:Path,db,kind,spec,modern=None,execution_cwd=None):
    (root/'case.json').write_text(json.dumps(spec,ensure_ascii=False))
    (root/'access-sequence.php').write_bytes(block(kind,db==SIDE[1] if modern is None else modern))
    output=root/'result.json'
    if output.exists():output.unlink()
    e=dict(env,ACHBOT_PRIVATE_TEST='1',ACHBOT_V7_NATIVE='1',ACHBOT_DATABASE=db,ACHBOT_SOCKET=str(work/'mysql.sock'))
    cmd=['php','-n','-d','extension=mysqlnd','-d','extension=mysqli','-d','extension=curl','-d','extension=gd',
         '-d','open_basedir='+str(root)+':'+str(work),'-d','allow_url_fopen=0',
         '-d','display_errors=0','-d','log_errors=0','-d','memory_limit=128M',
         '-d','disable_functions=exec,shell_exec,system,passthru,popen,proc_open,pcntl_exec,pcntl_fork,mail,dl',
         str(root/'native-purchase.php')]
    p=subprocess.run(cmd,cwd=execution_cwd or root,env=e,capture_output=True,timeout=25)
    if p.returncode or p.stdout or p.stderr or not output.exists():
        raise RuntimeError('PHP native runner failed: '+repr((kind,spec,p.returncode,p.stdout[-550:],p.stderr[-550:])))
    result=json.loads(output.read_text())
    if result['exception'] or any(x.startswith(('FATAL:','EXCEPTION:')) for x in result['warnings']):
        raise RuntimeError('Native PHP scenario error '+kind+' '+repr(spec)+' '+repr(result)[:1800])
    return result

def series():
    return {
      'customer_paid_wallet':[
        ('entryandbulk',dict(data='buySubscription',actor=1001)),
        ('catalogue',dict(data='selectServer2_none',actor=1001)),
        ('catalogue',dict(data='selectCategory1_2_none',actor=1001)),
        ('selectplan',dict(data='selectPlan7_1_none',actor=1001)),
        ('wallet',dict(data='payBuyWithWalletxxxxxxxxx',actor=1001)),
      ],
      'agent_one_wallet':[
        ('catalogue',dict(data='selectServer2_one',actor=9002)),
        ('catalogue',dict(data='selectCategory1_2_one',actor=9002)),
        ('selectplan',dict(data='selectPlan7_1_one',actor=9002)),
        ('wallet',dict(data='payBuyWithWalletxxxxxxxxx',actor=9002)),
      ],
      'custom_paid_wallet':[
        ('catalogue',dict(data='selectCustomPlan1_2_none',actor=1001)),
        ('catalogue',dict(data='selectCustomePlan7_1_none',actor=1001)),
        ('catalogue',dict(text='2',actor=1001)),
        ('catalogue',dict(text='30',actor=1001)),
        ('catalogue',dict(text='customer123',actor=1001)),
        ('wallet',dict(data='payBuyWithWalletxxxxxxxxx',actor=1001)),
      ],
      'agent_many_wallet':[
        ('entryandbulk',dict(data='agentMuchBuy',actor=9002)),
        ('catalogue',dict(data='selectServer2_much',actor=9002)),
        ('catalogue',dict(data='selectCategory1_2_much',actor=9002)),
        ('selectplan',dict(data='selectPlan7_1_much',actor=9002)),
        ('selectplan',dict(text='2',actor=9002)),
        ('wallet',dict(data='payBuyWithWalletxxxxxxxxx',actor=9002)),
      ],
      'free_trial':[
        ('trialcatalogue',dict(data='getTestAccount',actor=1001)),
        ('trialprovision',dict(data='freeTrial8_normal',actor=1001)),
      ],
      'free_trial_replay_denied':[
        ('trialprovision',dict(data='freeTrial8_normal',actor=1001)),
        ('trialprovision',dict(data='freeTrial8_normal',actor=1001)),
      ],
      'admin_bulk_create':[
        ('entryandbulk',dict(data='createMultipleAccounts',actor=9001)),
        ('entryandbulk',dict(data='createAccServer2',actor=9001)),
        ('entryandbulk',dict(data='createAccCategory1_2',actor=9001)),
        ('entryandbulk',dict(data='createAccPlan7',actor=9001)),
        ('entryandbulk',dict(text='30',actor=9001)),
        ('entryandbulk',dict(text='2',actor=9001)),
        ('entryandbulk',dict(text='2',actor=9001)),
      ],
      'nonadmin_bulk_denied':[
        ('entryandbulk',dict(data='createMultipleAccounts',actor=1001)),
      ],
      'invalid_category_no_purchase':[
        ('catalogue',dict(data='selectCategory999_2_none',actor=1001)),
      ],
      'provider_reject_free':[
        ('trialprovision',dict(data='freeTrial8_normal',actor=1001)),
      ],
      'provider_unavailable_free':[
        ('trialprovision',dict(data='freeTrial8_normal',actor=1001)),
      ],
      'admin_category_create':[
        ('categories',dict(data='addNewCategory',actor=9001)),
        ('categories',dict(text='New category',actor=9001)),
      ],
      'admin_category_edit':[
        ('categories',dict(data='wizwizcategoryedit1_1',actor=9001)),
        ('categories',dict(text='Renamed category',actor=9001)),
      ],
      'admin_plan_price_edit':[
        ('administration',dict(data='wizwizplanrial7',actor=9001)),
        ('administration',dict(text='150',actor=9001)),
      ],
      'admin_plan_create_draft':[
        ('administration',dict(data='addNewPlan',actor=9001)),
        ('administration',dict(text='Sample plan',actor=9001)),
      ],
      'admin_category_manage':[
        ('categories',dict(data='categoriesSetting',actor=9001)),
      ],
      'admin_plan_manage':[
        ('administration',dict(data='backplan',actor=9001)),
      ],
      'interval_plan_manage':[
        ('intervals',dict(data='dayPlanSettings',actor=9001)),
      ],
    }


def assert_outcome(work, env, label):
    def scalar(query):
        return native.sql(work, env, query, SIDE[1]).strip()
    orders=int(scalar('SELECT COUNT(*) FROM orders_list'))
    payments=int(scalar('SELECT COUNT(*) FROM pays'))
    capacity=int(scalar('SELECT ucount FROM server_info WHERE id=2'))
    expected={
        'customer_paid_wallet':(1,1,9,49900,1001),
        'agent_one_wallet':(1,1,9,49910,9002),
        'agent_many_wallet':(2,1,8,49820,9002),
        'custom_paid_wallet':(1,1,9,49830,1001),
        'free_trial':(1,0,9,50000,1001),
        'free_trial_replay_denied':(1,0,9,50000,1001),
        'admin_bulk_create':(2,0,8,50000,9001),
    }
    if label in expected:
        eo,ep,ec,ew,actor=expected[label]
        wallet=int(scalar('SELECT wallet FROM users WHERE userid='+str(actor)))
        if (orders,payments,capacity,wallet)!=(eo,ep,ec,ew):
            raise RuntimeError('Lifecycle outcome mismatch '+label+': '+str((orders,payments,capacity,wallet)))
        if payments and scalar('SELECT state FROM pays LIMIT 1')!='paid_with_wallet':
            raise RuntimeError('Wallet payment did not persist success')
        if label.startswith('free_trial') and scalar('SELECT freetrial FROM users WHERE userid=1001')!='used':
            raise RuntimeError('Trial eligibility was not consumed')
    if label in ('provider_reject_free','provider_unavailable_free','nonadmin_bulk_denied','invalid_category_no_purchase'):
        if orders or payments or capacity!=10:
            raise RuntimeError('Negative mutation control failed '+label)
    if label=='admin_category_create' and scalar('SELECT COUNT(*) FROM server_categories')!='2':
        raise RuntimeError('New category was not persisted')
    if label=='admin_category_edit' and scalar('SELECT title FROM server_categories WHERE id=1')!='Renamed category':
        raise RuntimeError('Renamed category was not persisted')
    if label=='admin_plan_price_edit' and scalar('SELECT price FROM server_plans WHERE id=7')!='150':
        raise RuntimeError('Price edit did not persist')
    if label=='admin_plan_create_draft' and scalar('SELECT COUNT(*) FROM server_plans WHERE active=0')!='1':
        raise RuntimeError('Plan draft did not persist')
    return {'orders':orders,'payments':payments,'capacity_remaining':capacity}


def main():
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument('--run-disposable',required=True,action='store_true')
    p.add_argument('--report',type=Path)
    p.add_argument('--case',action='append',default=[])
    args=p.parse_args();c.verify()
    results=[]
    with support.owned_database() as (work,env),native.owned_provider() as (provider,url):
        prev=work/'v61';new=work/'v7';stage(prev,False);stage(new,True)
        tables=native.sql(work,env,'SHOW TABLES',SIDE[0]).splitlines()
        if len(tables)!=23:raise RuntimeError('Original schema is not 23 tables')
        def ddl(db):
            current=native.sql(work,env,'SHOW TABLES',db).splitlines()
            if current!=tables:raise RuntimeError('Original/candidate table inventory mismatch')
            return [re.sub(r' AUTO_INCREMENT=\d+','',native.sql(work,env,'SHOW CREATE TABLE `'+tab+'`',db)) for tab in tables]
        base_ddl={db:ddl(db) for db in SIDE}
        if base_ddl[SIDE[0]]!=base_ddl[SIDE[1]]:
            raise RuntimeError('Original vs candidate DDL mismatch before execution')
        for label,steps in series().items():
            if args.case and not any(label.startswith(k) for k in args.case):continue
            init(work,env,url)
            mode={'provider_reject_free':'marzban-invalid','provider_unavailable_free':'marzban-503'}.get(label,'ok')
            provider.mode=mode
            for i,(kind,spec) in enumerate(steps):
                actor=int(spec['actor'])
                observations=[];wire=[];states=[]
                for side,db in ((prev,SIDE[0]),(new,SIDE[1])):
                    provider.trace.clear()
                    result=run(work,env,side,db,kind,spec)
                    observations.append(result);wire.append(list(provider.trace));states.append(snapshot(work,env,db))
                if observations[0]!=observations[1] or wire[0]!=wire[1] or states[0]!=states[1]:
                    detail={'label':label,'step':i,'old_events':observations[0], 'new_events':observations[1],
                            'old_wire':wire[0],'new_wire':wire[1],'old_state':states[0],'new_state':states[1]}
                    raise RuntimeError('V8 differential mismatch: '+json.dumps(detail,ensure_ascii=False)[:5500])
                if mode=='ok' and kind=='wallet' and not wire[0]:
                    raise RuntimeError('Paid wallet lifecycle did not provision at provider')
                if mode=='ok' and kind=='trialprovision' and i==0 and not wire[0]:
                    raise RuntimeError('Free trial did not provision at provider')
                if mode!='ok' and kind=='trialprovision':
                    if wire[0] == []:raise RuntimeError('Provider negative control did not hit upstream')
                    if states[0]['orders'].strip():raise RuntimeError('Provider failure persisted order')
                results.append({'scenario':label,'step':i,'route':kind,'actor':actor,
                                'telegram_events':len(observations[0]['events']),'http_requests':len(wire[0]),
                                'sql_snapshot_sha256':hashlib.sha256(json.dumps(states[0],sort_keys=True).encode()).hexdigest()})
            outcome=assert_outcome(work,env,label)
            print('PASS '+label+' '+json.dumps(outcome),flush=True)
        for db in SIDE:
            after_ddl=ddl(db)
            if base_ddl[db]!=after_ddl:
                raise RuntimeError('Original 23-table schema changed during V8 testing on '+db)
    report={'base':c.BASE,'cases':len(results),'scenarios':len({x['scenario'] for x in results}),
            'details':results,'full_original_schema':True,'schema_unchanged':True,'ddl_both_sides_before_after_equal':True,
            'php_real_mysqli':True,'provider_owned_loopback':True,'telegram_synthetic':True,
            'baseline_payment_writes_pinned_v7':True,'exact_ordered_state_and_http_equal':True}
    if args.report:args.report.write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps({k:v for k,v in report.items() if k!='details'},ensure_ascii=False),flush=True)
if __name__=='__main__':main()
