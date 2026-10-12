#!/usr/bin/env python3
"""V5 vs V6 actual public settings/subLink.php GET through owned PHP HTTP server.

Uses original PHP endpoint bytes, original 23-table socket-only MariaDB,
real cURL to synthetic loopback X-UI panel, checks HTTP payload, persisted
rows, provider wire and legacy malformed/missing token outcomes.
"""
from __future__ import annotations
import argparse,hashlib,json,os,shutil,socket,subprocess,tempfile,time,urllib.error,urllib.request
from pathlib import Path
import v6_contract as c
import test_v6_native as native
import test_v6_panels as panels
import test_access_state as support
ROOT=c.ROOT

def stage(root:Path,dbname:str,changed:bool):
    (root/'settings').mkdir(parents=True)
    (root/'src/Panel').mkdir(parents=True)
    (root/'src/Subscription').mkdir(parents=True)
    source=(ROOT/'config.php').read_bytes() if changed else c.reference('config.php')
    (root/'config.php').write_bytes(panels.function_bodies(source,c.PANEL_FUNCS+c.DETAIL_FUNCS)+b'\n'+
        b'function RandomString($count=9,$type="all"){return str_repeat("x",(int)$count);}\n'
        +b'function generateUID(){return "fixture-uid";}\n')
    (root/'settings/subLink.php').write_bytes(c.reference('settings/subLink.php'))
    (root/'baseInfo.php').write_text("<?php\n$dbUserName='root';\n$dbPassword='';\n$dbName='"+dbname+"';\n")
    (root/'private-state.json').write_text('{"synthetic":true}\n')
    if changed:
        for path,ns in c.MODULES.items():
            if ns is None:continue
            dest=root/path;dest.parent.mkdir(parents=True,exist_ok=True)
            shutil.copyfile(ROOT/path,dest)

def serve(root:Path,work:Path,env:dict):
    with socket.socket() as sk:
        sk.bind(('127.0.0.1',0));port=sk.getsockname()[1]
    cmd=['php','-n','-d','extension=mysqlnd','-d','extension=mysqli','-d','extension=curl',
         '-d','mysqli.default_socket='+str(work/'mysql.sock'),
         '-d','open_basedir='+str(root)+':'+str(work),
         '-d','display_errors=0','-d','log_errors=0',
         '-d','disable_functions=exec,shell_exec,system,passthru,popen,proc_open,pcntl_exec,pcntl_fork,mail,dl',
         '-S','127.0.0.1:'+str(port),'-t',str(root)]
    p=subprocess.Popen(cmd,env=env,cwd=root,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    for _ in range(60):
        if p.poll() is not None:raise RuntimeError('Fixture PHP HTTP exited')
        try:
            urllib.request.urlopen('http://127.0.0.1:'+str(port)+'/health',timeout=.4)
        except urllib.error.HTTPError:
            break
        except urllib.error.URLError:
            time.sleep(.08)
    else:raise RuntimeError('Fixture HTTP not listening')
    return p,port

def fetch(port,token):
    url=f'http://127.0.0.1:{port}/settings/subLink.php'
    if token is not None:url+='?token='+token
    try:
        with urllib.request.urlopen(url,timeout=12) as x:
            return {'status':x.status,'body':x.read().decode('utf-8','replace'),
                    'type':x.headers.get('Content-Type','')}
    except urllib.error.HTTPError as e:
        return {'status':e.code,'body':e.read().decode('utf-8','replace'),
                'type':e.headers.get('Content-Type','')}

def main():
    ap=argparse.ArgumentParser();ap.add_argument('--run-disposable',required=True,action='store_true')
    ap.add_argument('--report',type=Path);args=ap.parse_args()
    c.verify()
    scenarios={'missing_token':None,'invalid_short':'short',
               'valid_exact':'a'*30}
    results=[]
    with support.owned_database() as (work,env),native.owned_provider() as (provider,url):
        native.seed(work,env,url)
        old=work/'v5-http';new=work/'v6-http';stage(old,'achbot_v4_old',False);stage(new,'achbot_v4_new',True)
        private={n:hashlib.sha256((new/n).read_bytes()).hexdigest() for n in ['baseInfo.php','private-state.json']}
        for label,token in scenarios.items():
            traces=[];responses=[]
            for app in [old,new]:
                provider.trace.clear()
                web,port=serve(app,work,env)
                try:resp=fetch(port,token)
                finally:
                    if web.poll() is None:web.terminate()
                    try:web.wait(timeout=5)
                    except subprocess.TimeoutExpired:web.kill();web.wait(timeout=3)
                traces.append(list(provider.trace));responses.append(resp)
            if responses[0]!=responses[1] or traces[0]!=traces[1]:
                raise RuntimeError('Public subLink V5/V6 mismatch '+label+': '+json.dumps({'old':responses[0],'new':responses[1],'provider_old':traces[0], 'provider_new':traces[1]},ensure_ascii=False)[:3000])
            if label!='valid_exact' and responses[0]['body']!='Wrong token':
                raise RuntimeError('Legacy invalid/missing token output not expected')
            if label=='valid_exact':
                if not responses[0]['body'] or not traces[0]:
                    raise RuntimeError('Public valid subscription no useful output or provider calls')
                try:import base64;base64.b64decode(responses[0]['body'],validate=True)
                except Exception as e:raise RuntimeError('Endpoint no valid base64 content') from e
            if native.snapshot(work,env,'achbot_v4_old')!=native.snapshot(work,env,'achbot_v4_new'):
                raise RuntimeError('SubLink SQL persisted state mismatch '+label)
            results.append({'case':label,'status':responses[0]['status'],
                            'output_sha256':hashlib.sha256(responses[0]['body'].encode()).hexdigest(),
                            'provider_requests':len(traces[0])})
        if any(hashlib.sha256((new/n).read_bytes()).hexdigest()!=v for n,v in private.items()):
            raise RuntimeError('Public endpoint touched synthetic private files')
    report={'v5_reference':c.BASE,'public_path':'settings/subLink.php','tests':len(results),'details':results,
       'real_php_http':True,'real_mariadb_23_table_socket_only':True,'owned_panel_http':True,
       'response_byte_and_sql_state_parity':True,'original_public_entrypoint_unchanged':True}
    if args.report:args.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps(report))
if __name__=='__main__':main()
