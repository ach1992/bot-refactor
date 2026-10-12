#!/usr/bin/env python3
"""Load the *entire* original V5/V6 config.php, not snippets, in disposable PHP+MariaDB.

The unchanged inherited negative actor exit cuts off Telegram/payment work.
The shutdown fixture then executes real global facades against owned HTTP panels.
"""
from __future__ import annotations
import argparse,hashlib,json,os,shutil,subprocess
from pathlib import Path
import v6_contract as c
import test_v6_native as native
import test_access_state as support
ROOT=c.ROOT

def stage(root:Path,changed:bool):
    for name in ['settings/values.php','settings/jdf.php','src/Telegram/UpdateBindings.php','src/Subscription/Presentation.php']:
        p=root/name;p.parent.mkdir(parents=True,exist_ok=True)
        p.write_bytes(c.reference(name))
    (root/'config.php').write_bytes((ROOT/'config.php').read_bytes() if changed else c.reference('config.php'))
    (root/'bootstrap.php').write_bytes((ROOT/'tests/v6/bootstrap.php').read_bytes())
    (root/'names.json').write_text(json.dumps(c.PANEL_FUNCS+c.DETAIL_FUNCS))
    (root/'baseInfo.php').write_text('<?php\n$dbUserName="root";$dbPassword="";$dbName="'+('achbot_v4_new' if changed else 'achbot_v4_old')+'";\n$botToken="synthetic-only";\n$admin=9001;$botUrl="https://bot.invalid/";\n')
    (root/'private-state.json').write_text('inert-private-state-unchanged\n')
    if changed:
        for name in c.MODULES:
            if c.MODULES[name] is not None:
                p=root/name;p.parent.mkdir(parents=True,exist_ok=True)
                shutil.copyfile(ROOT/name,p)

def run(root:Path,work:Path,env:dict,dbname:str):
    config_hash=hashlib.sha256((root/'baseInfo.php').read_bytes()).hexdigest()
    private_hash=hashlib.sha256((root/'private-state.json').read_bytes()).hexdigest()
    e=dict(env,ACHBOT_PRIVATE_TEST='1',ACHBOT_V6_NATIVE='1',ACHBOT_DATABASE=dbname,
         ACHBOT_SOCKET=str(work/'mysql.sock'))
    cmd=['php','-n','-d','extension=mysqlnd','-d','extension=mysqli','-d','extension=curl',
         '-d','mysqli.default_socket='+str(work/'mysql.sock'),
         '-d','open_basedir='+str(root)+':'+str(work),'-d','allow_url_fopen=0',
         '-d','display_errors=0','-d','log_errors=0',
         '-d','disable_functions=exec,shell_exec,system,passthru,popen,proc_open,pcntl_exec,pcntl_fork,mail,dl',
         str(root/'bootstrap.php')]
    p=subprocess.run(cmd,cwd=root,env=e,capture_output=True,timeout=20)
    result=root/'result.json'
    if p.returncode or p.stdout or p.stderr or not result.exists():
        raise RuntimeError('Full config.php bootstrap failed '+str((p.returncode,p.stdout[-550:],p.stderr[-550:])))
    if hashlib.sha256((root/'baseInfo.php').read_bytes()).hexdigest()!=config_hash:
        raise RuntimeError('Full config modified private baseInfo.php')
    if hashlib.sha256((root/'private-state.json').read_bytes()).hexdigest()!=private_hash:
        raise RuntimeError('Full config modified private state')
    return json.loads(result.read_text())

def main():
    ap=argparse.ArgumentParser();ap.add_argument('--run-disposable',required=True,action='store_true')
    ap.add_argument('--report',type=Path);args=ap.parse_args();c.verify()
    with support.owned_database() as (work,env),native.owned_provider() as (provider,url):
        native.seed(work,env,url)
        old=work/'full-v5';new=work/'full-v6';old.mkdir();new.mkdir()
        stage(old,False);stage(new,True)
        observations=[];traces=[]
        for app,db in [(old,native.DBS[0]),(new,native.DBS[1])]:
            provider.trace.clear();observations.append(run(app,work,env,db));traces.append(list(provider.trace))
        if observations[0]!=observations[1] or traces[0]!=traces[1]:
            raise RuntimeError('Full real config.php bootstrap parity changed '+json.dumps({'old':observations[0],'new':observations[1],'old_wire':traces[0],'new_wire':traces[1]},ensure_ascii=False)[:3200])
        if observations[0]['exception']:
            raise RuntimeError('Full config facade integration not successful '+str(observations[0]['exception']))
        if not observations[0]['result'] or len(observations[0]['result']['signatures'])!=40:
            raise RuntimeError('Expected 40 global legacy facade signatures')
        if not traces[0]:raise RuntimeError('Actual full config facades never used panel cURL')
        if native.snapshot(work,env,native.DBS[0])!=native.snapshot(work,env,native.DBS[1]):
            raise RuntimeError('Full config SQL persistence not equal')
    report={'v5_reference':c.BASE,'full_unmodified_original_bootstrap':True,
        'legacy_global_facades_verified':40,'real_native_php_and_mariadb':True,
        'real_panel_http_calls_per_version':len(traces[0]),
        'same_signatures_panel_wire_sql_and_bootstrap_side_effects':True,
        'negative_actor_no_real_telegram_payment':True,'private_config_and_state_unchanged':True}
    if args.report:args.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps(report))
if __name__=='__main__':main()
