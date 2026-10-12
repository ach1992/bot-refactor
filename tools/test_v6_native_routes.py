#!/usr/bin/env python3
"""Real native MariaDB SQL plus synthetic Telegram/panel account deletion and settings routes."""
from __future__ import annotations
import argparse,hashlib,json,os,shutil,subprocess,tempfile
from pathlib import Path
import v6_contract as c
import test_v6_native as native
import test_v6_routes as routes
import test_v6_panels as panel
import test_access_state as support
ROOT=c.ROOT

def stage(root:Path,module:str,changed:bool):
    (root/'tests/v6').mkdir(parents=True,exist_ok=True)
    shutil.copyfile(ROOT/'tests/v6/native-route.php',root/'native-route.php')
    config=(ROOT/'config.php').read_bytes() if changed else c.reference('config.php')
    (root/'functions-under-test.php').write_bytes(panel.function_bodies(config,c.PANEL_FUNCS+c.DETAIL_FUNCS))
    if changed:
        for path,ns in c.MODULES.items():
            if ns is not None:
                p=root/path;p.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(ROOT/path,p)
    original=c.reference('bot.php')
    if module=='src/Subscription/AccountDeletion.php':
        _,legacy=c.region(original,c.DELETION_START,c.DELETION_END,b'')
        if changed:
            p=root/module;p.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(ROOT/module,p)
            route=b"<?php\nif ($data==='synthetic-gift-never') {} else {require __DIR__ . '/src/Subscription/AccountDeletion.php';}\n"
        else:
            route=b"<?php\nif ($data==='synthetic-gift-never') {}\n"+legacy
    else:
        start,end=next((a,b) for name,a,b in c.ROUTES if name==module)
        _,legacy=c.region(original,start,end,b'')
        if changed:
            p=root/module;p.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(ROOT/module,p)
            route=b"<?php\nif ((require __DIR__ . '/"+module.encode()+b"') === null) return;\n"
        else:route=b'<?php\n'+legacy
    (root/'access-sequence.php').write_bytes(route)

def seed(work,env,url):
    native.seed(work,env,url)
    for db in native.DBS:
        native.sql(work,env,'DELETE FROM server_info WHERE id IN (2,3,4);',db)
        native.sql(work,env,"INSERT INTO server_info (id,title,ucount,remark,flag,active,state) VALUES (3,'fixture server','10','fixture','',1,1);",db)

def scenario():
    return {
        'subscription.backup.write':('src/Subscription/SubLinkSettings.php',{'actor':9001,'is_admin':0,'step':'subLinksInput3','text':'https://backup1.invalid,https://backup2.invalid'}),
        'subscription.backup.denied':('src/Subscription/SubLinkSettings.php',{'actor':1001,'is_admin':0,'step':'subLinksInput3','text':'https://not-allowed.invalid'}),
        'subscription.backup.ask':('src/Subscription/SubLinkSettings.php',{'actor':9001,'data':'editSubLinks3'}),
        'account.delete.ask':('src/Subscription/AccountDeletion.php',{'actor':1001,'data':'deleteMyConfig91'}),
        'account.delete.cancel':('src/Subscription/AccountDeletion.php',{'actor':1001,'data':'noDontDelete'}),
        'account.delete.admin.ask':('src/Subscription/AccountDeletion.php',{'actor':9001,'data':'delUserConfig91'}),
        'account.delete.admin.execute':('src/Subscription/AccountDeletion.php',{'actor':9001,'data':'yesDeleteUserConfig91'}),
        'account.transfer.no_valid':('src/Subscription/AccountTransfers.php',{'actor':1001,'data':'switchLocation91_3_1_1800000000'}),
    }

def run(work,env,root,database,spec):
    (root/'case.json').write_text(json.dumps(spec))
    response=root/'result.json'
    if response.exists():response.unlink()
    e=dict(env,ACHBOT_PRIVATE_TEST='1',ACHBOT_V6_NATIVE='1',ACHBOT_DATABASE=database,
           ACHBOT_SOCKET=str(work/'mysql.sock'))
    cmd=['php','-n','-d','extension=mysqlnd','-d','extension=mysqli','-d','extension=curl',
         '-d','open_basedir='+str(root)+':'+str(work),'-d','allow_url_fopen=0',
         '-d','display_errors=0','-d','log_errors=0',
         '-d','disable_functions=exec,shell_exec,system,passthru,popen,proc_open,pcntl_exec,pcntl_fork,mail,dl',
         str(root/'native-route.php')]
    p=subprocess.run(cmd,cwd=root,env=e,capture_output=True,timeout=20)
    if p.returncode or p.stdout or p.stderr or not response.is_file():
        raise RuntimeError('Native route PHP error '+repr((p.returncode,p.stdout[-550:],p.stderr[-550:])))
    return json.loads(response.read_text())

def snapshot(work,env,db):
    return dict(native.snapshot(work,env,db),
        servers=native.sql(work,env,'SELECT id,ucount,active,state FROM server_info WHERE id=3',db),
        backup=native.sql(work,env,'SELECT id,sub_links FROM server_config WHERE id=3',db))

def main():
    p=argparse.ArgumentParser();p.add_argument('--run-disposable',required=True,action='store_true')
    p.add_argument('--report',type=Path);p.add_argument('--case',action='append',default=[])
    a=p.parse_args();c.verify()
    records=[]
    with support.owned_database() as (work,env),native.owned_provider() as (provider,url):
        prior=work/'v5-native-routes';after=work/'v6-native-routes';prior.mkdir();after.mkdir()
        specs={k:v for k,v in scenario().items() if not a.case or any(k.startswith(x) for x in a.case)}
        for name,(module,spec) in specs.items():
            seed(work,env,url)
            stage(prior,module,False);stage(after,module,True)
            output=[];wire=[]
            for root,db in ((prior,native.DBS[0]),(after,native.DBS[1])):
                provider.trace.clear()
                result=run(work,env,root,db,spec)
                output.append(result);wire.append(list(provider.trace))
            if output[0]!=output[1] or wire[0]!=wire[1]:
                raise RuntimeError('Native SQL/Telegram/provider route mismatch '+name+': '+json.dumps({'old':output[0],'new':output[1],'old_wire':wire[0],'new_wire':wire[1]},ensure_ascii=False)[:2200])
            if any(x.startswith(('EXCEPTION:','FATAL:')) for x in output[0]['warnings']):
                raise RuntimeError('Native route lacked successful behavior '+name+' '+str(output[0]['warnings']))
            if snapshot(work,env,native.DBS[0])!=snapshot(work,env,native.DBS[1]):
                raise RuntimeError('Native route persisted SQL mismatch '+name)
            records.append({'case':name,'telegram_events':len(output[0]['events']),
                'panel_requests':len(wire[0]),'legacy_warnings':len(output[0]['warnings'])})
    report={'v5_reference':c.BASE,'cases':len(records),'details':records,
            'real_mariadb_23_table_socket_only':True,'real_php_mysqli':True,
            'telegram_synthetic_no_real_requests':True,'panel_http_loopback':True,
            'ordered_behavior_and_persisted_rows_equal':True}
    if a.report:a.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps(report))
if __name__=='__main__':main()
