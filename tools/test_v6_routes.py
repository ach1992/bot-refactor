#!/usr/bin/env python3
"""V5-V6 ordered Telegram/SQL/state/return differential for six original route slots."""
from __future__ import annotations
import argparse,json,os,shutil,subprocess,sys,tempfile
from pathlib import Path
import v6_contract as c
sys.path.insert(0,str(c.ROOT/'tests'))
from run import DISABLED
ROOT=c.ROOT

def read(pattern,rows):return {'pattern':pattern,'rows':rows}
def order(oid=9):
    return {'id':oid,'userid':1001,'fileid':7,'server_id':2,'remark':'fixture','uuid':'fixture-uuid',
            'token':'a'*30,'protocol':'vless','inbound_id':0,'netType':'tcp','status':1,
            'expire_date':1800001000,'link':'["vless://fixture"]','agent_bought':0}

def cases():
    d={}
    def add(module,name,**spec):
        if name in d:raise RuntimeError('Duplicate case')
        d[name]={'module':module,**spec}
    m='src/Subscription/OrderManagement.php'
    for actor,admin in [(1001,0),(9001,0),(9002,1)]:
        suf=f'.{actor}-{admin}'
        add(m,'order.show'+suf,data='showUserSubscriptions2001',actor=actor,is_admin=admin)
        add(m,'order.show.empty'+suf,data='showUserSubscriptions2001',actor=actor,is_admin=admin,subscriptionMessages=[])
        add(m,'order.manage.ask'+suf,data='manageorderdatabase',actor=actor,is_admin=admin)
        add(m,'order.quick.found'+suf,data='quickUserOrderDetails_9',actor=actor,is_admin=admin,
            reads=[read(r'/FROM `orders_list` WHERE `id` =/',[order()])])
        add(m,'order.quick.missing'+suf,data='quickUserOrderDetails_9',actor=actor,is_admin=admin,
            reads=[read(r'/FROM `orders_list` WHERE `id` =/',[])])
        add(m,'order.noop'+suf,data='not-a-subscription-action',actor=actor,is_admin=admin)
    m='src/Subscription/CustomerRoutes.php'
    for actor,admin in [(1001,0),(9001,0),(9002,1)]:
        suf=f'.{actor}-{admin}'
        for callback in ['mySubscriptions','agentConfigsList','changeOrdersPage2','changeAgentOrder2']:
            reads=[read(r'/FROM `orders_list` WHERE `userid`=/',[]),read(r'/FROM `orders_list` WHERE `userid`=/',[])]
            add(m,'customer.list.empty.'+callback+suf,data=callback,actor=actor,is_admin=admin,reads=reads)
        add(m,'customer.detail'+suf,data='orderDetails9',actor=actor,is_admin=admin)
        add(m,'customer.cancel'+suf,data='cancelSearchConfig',actor=actor,is_admin=admin)
        add(m,'customer.search.ask'+suf,data='searchAgentConfig',actor=actor,is_admin=admin)
        add(m,'customer.change.port.ask'+suf,data='changeCustomPort9',actor=actor,is_admin=admin)
        add(m,'customer.change.sni.ask'+suf,data='changeCustomSni9',actor=actor,is_admin=admin)
        add(m,'customer.change.protocol.disabled'+suf,data='changeProtocolIsDisable',actor=actor,is_admin=admin)
        add(m,'customer.noop'+suf,data='not-a-subscription-action',actor=actor,is_admin=admin)
    m='src/Subscription/SubLinkSettings.php'
    for actor,admin in [(1001,0),(9001,0),(9002,1)]:
        suf=f'.{actor}-{admin}'
        add(m,'sublink.edit.ask'+suf,data='editSubLinks2',actor=actor,is_admin=admin)
        add(m,'sublink.noop'+suf,data='not-a-subscription-action',actor=actor,is_admin=admin)
    m='src/Subscription/ExternalAccounts.php'
    for actor,admin in [(1001,0),(9001,0),(9002,1)]:
        suf=f'.{actor}-{admin}'
        add(m,'external.show.ask'+suf,data='showUUIDLeft',actor=actor,is_admin=admin)
        add(m,'external.noop'+suf,data='not-a-subscription-action',actor=actor,is_admin=admin)
    m='src/Subscription/ManualAccounts.php'
    for actor,admin in [(1001,0),(9001,0),(9002,1)]:
        suf=f'.{actor}-{admin}'
        add(m,'manual.add.ask'+suf,data='addSubscriptionToCustomer2',actor=actor,is_admin=admin)
        add(m,'manual.noop'+suf,data='not-a-subscription-action',actor=actor,is_admin=admin)
    m='src/Subscription/ConfigRefresh.php'
    for actor,admin in [(1001,0),(9001,0),(9002,1)]:
        suf=f'.{actor}-{admin}'
        add(m,'refresh.missing.temp'+suf,data='sConfigUpdate2',actor=actor,is_admin=admin)
        add(m,'refresh.noop'+suf,data='not-a-subscription-action',actor=actor,is_admin=admin)
    m='src/Subscription/AccountTransfers.php'
    for actor,admin in [(1001,0),(9001,0),(9002,1)]:
        suf=f'.{actor}-{admin}'
        add(m,'transfer.choose.empty'+suf,data='switchLocation9_3_50_1800000000',actor=actor,is_admin=admin,
            reads=[read(r'/FROM `server_info` WHERE `active` = 1/',[])])
        add(m,'transfer.noop'+suf,data='not-a-subscription-action',actor=actor,is_admin=admin)
    m='src/Subscription/AccountDeletion.php'
    for actor,admin in [(1001,0),(9001,0),(9002,1)]:
        suf=f'.{actor}-{admin}'
        add(m,'deletion.cancel'+suf,data='noDontDelete',actor=actor,is_admin=admin)
        add(m,'deletion.ask'+suf,data='deleteMyConfig9',actor=actor,is_admin=admin,
            reads=[read(r'/FROM `orders_list` WHERE `id` =/',[order()])])
        add(m,'deletion.admin.ask'+suf,data='delUserConfig9',actor=actor,is_admin=admin,
            reads=[read(r'/FROM `orders_list` WHERE `id` =/',[order()])] if actor==9001 or admin else [])
        add(m,'deletion.noop'+suf,data='not-a-subscription-action',actor=actor,is_admin=admin)
        add(m,'deletion.gift-ancestor'+suf,data='deleteMyConfig91',actor=actor,is_admin=admin,
            step='giftServerVolume3_5',text='4',reads=[] if actor==9001 or admin else
            [read(r'/FROM `orders_list` WHERE `id` =/',[order()])])
    return d

def fixture(target:Path,module:str,changed:bool):
    (target/'tests/support').mkdir(parents=True)
    shutil.copyfile(ROOT/'tests/support/doubles.php',target/'tests/support/doubles.php')
    shutil.copyfile(ROOT/'tests/v6/routes.php',target/'routes.php')
    source=c.reference('bot.php')
    if module=='src/Subscription/AccountDeletion.php':
        _,original=c.region(source,c.DELETION_START,c.DELETION_END,b'')
        if changed:
            p=target/module;p.parent.mkdir(parents=True,exist_ok=True)
            shutil.copyfile(ROOT/module,p)
            route=b"<?php\nif (preg_match('/^giftServerVolume(\\d+)_(\\d+)/',$userInfo['step'],$match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']) { achEvent('gift-precedence'); } else { require __DIR__ . '/src/Subscription/AccountDeletion.php'; }\n"
        else:
            route=b"<?php\nif (preg_match('/^giftServerVolume(\\d+)_(\\d+)/',$userInfo['step'],$match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']) { achEvent('gift-precedence'); }\n"+original
    else:
        start,end=next((start,end) for name,start,end in c.ROUTES if name==module)
        _,original=c.region(source,start,end,b'')
        if changed:
            p=target/module;p.parent.mkdir(parents=True,exist_ok=True)
            shutil.copyfile(ROOT/module,p)
            route=b'<?php\n'+b"if ((require __DIR__ . '/"+module.encode()+b"') === null) return;\n"
        else:route=b'<?php\n'+original
    (target/'access-sequence.php').write_bytes(route+b"\nachEvent('after-route');\n")

def run(target:Path,spec:dict):
    spec={k:v for k,v in spec.items() if k!='module'}
    (target/'case.json').write_text(json.dumps(spec,ensure_ascii=True))
    result=target/'result.json'
    if result.exists():result.unlink()
    env={'PATH':os.environ.get('PATH','/usr/bin:/bin'),'ACHBOT_PRIVATE_TEST':'1','LANG':'C.UTF-8'}
    cmd=['php','-n','-d','open_basedir='+str(target),'-d','allow_url_fopen=0',
         '-d','disable_functions='+DISABLED,'-d','display_errors=1','-d','log_errors=1',str(target/'routes.php')]
    child=subprocess.run(cmd,cwd=target,env=env,capture_output=True,timeout=12)
    if child.returncode or child.stdout or child.stderr or not result.is_file():
        raise RuntimeError('Route child failed '+repr((child.returncode,child.stdout[-500:],child.stderr[-500:])))
    return json.loads(result.read_text())

def main():
    ap=argparse.ArgumentParser();ap.add_argument('--report',type=Path)
    ap.add_argument('--case',action='append',default=[]);args=ap.parse_args()
    c.verify()
    chosen={name:s for name,s in cases().items() if not args.case or any(name.startswith(x) for x in args.case)}
    with tempfile.TemporaryDirectory(prefix='achbot-v6-routes-') as tmp:
        for module in c.MODULES:
            specs={name:s for name,s in chosen.items() if s['module']==module}
            if not specs:continue
            old=Path(tmp)/'old';new=Path(tmp)/'new'
            if old.exists():shutil.rmtree(old)
            if new.exists():shutil.rmtree(new)
            fixture(old,module,False);fixture(new,module,True)
            for name,spec in specs.items():
                a=run(old,spec);b=run(new,spec)
                if a!=b:raise RuntimeError('Route regression '+name+': '+json.dumps({'old':a,'new':b},ensure_ascii=False)[:3500])
                if a['errors']:
                    raise RuntimeError('Route fixture error '+name+': '+json.dumps(a['errors'],ensure_ascii=False)[:1700])
    report={'reference':c.BASE,'cases':len(chosen),'route_modules':len(set(x['module'] for x in chosen.values())),
            'names':sorted(chosen),'ordered_sql_telegram_and_scope_equal':True,'inflight_callbacks_equal':True}
    if args.report:args.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps({k:v for k,v in report.items() if k!='names'}))
if __name__=='__main__':main()
