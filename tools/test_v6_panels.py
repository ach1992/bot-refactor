#!/usr/bin/env python3
"""V5-V6 real PHP function execution: legacy SQL bind and synthetic HTTP wire option parity."""
from __future__ import annotations
import argparse, json, os, re, shutil, subprocess, sys, tempfile
from pathlib import Path
import v6_contract as c
sys.path.insert(0,str(c.ROOT/'tests'))
from run import DISABLED
ROOT=c.ROOT

def function_bodies(data:bytes,names:list[str]):
    out=[]
    for name in names:
        found=list(re.finditer(rb'(?m)^function\s+'+name.encode()+rb'\s*\([^\n]*\)\s*\{',data))
        if len(found)!=1:raise RuntimeError('Cannot locate original/facade '+name)
        end=re.search(rb'(?m)^}\s*(?:\n|\Z)',data[found[0].end():])
        if not end:raise RuntimeError('Missing function end '+name)
        out.append(data[found[0].start():found[0].end()+end.end()])
    return b'<?php\n'+b'\n'.join(out)

def config_row(type='marzban'):
    return {'id':2,'panel_url':'http://127.0.0.1:35001','username':'fixture-admin',
            'password':'synthetic-password','type':type,'ip':'192.0.2.3',
            'security':'none','tlsSettings':'{}','sni':'','request_header':'','response_header':'',
            'header_type':'none'}

def read(table,rows):
    return {'pattern':r'/FROM [`]?'+table+r'[`]? WHERE/', 'rows':rows}

def cases():
    out={}
    def add(name,fn,args=None,reads=None,http=None):
        out[name]={'function':fn,'args':args or [],'reads':reads or [],'http':http or []}
    add('orders.read.found','findOrder',[9],[read('orders_list',[{'id':9,'userid':1001,'remark':'fixture'}])])
    add('orders.read.missing','findOrder',[9],[read('orders_list',[])])
    add('orders.owner.read','findOrder',[9,1001],[read('orders_list',[{'id':9,'userid':1001}])])
    add('orders.other.read','findOrder',[9,2001],[read('orders_list',[])])
    add('server.config','getServerConfigById',[2],[read('server_config',[config_row()])])
    add('server.missing','getServerConfigById',[2],[read('server_config',[])])
    add('plan.missing','getPlanFullName',[7,70],[read('server_plans',[])])
    add('plan.found','getPlanFullName',[7,70],[read('server_plans',[{'id':7,'catid':3,'title':'Tier'}]),
        {'pattern':r'/FROM server_categories WHERE/', 'rows':[{'title':'Cat'}]}])
    add('headers.ws','getNewHeaders',['ws','','','normal'])
    add('headers.tcp','getNewHeaders',['tcp','{"Host":["example.invalid"]}','', 'sanaei'])
    add('headers.http','getNewHeaders',['http','','','alireza'])
    for value in ['vmess','vless','trojan','shadowsocks']:
        add('protocol.names.'+value,'getProtocolNamesRow',[value,'ws','tls',False,1])
    add('protocol.buttons.admin','buildAdminProtocolButtons',['vless','tcp','none',False,0])
    add('protocol.buttons.user','buildUserProtocolButtons',['vless','tcp','none',False,0,'normal',{'changeProtocolState':'on'},7,9])
    token_ok=[{'method':'token','body':{'access_token':'fixture-access'}}]
    add('marzban.login.ok','getMarzbanToken',[2],[read('server_config',[config_row()])],token_ok)
    add('marzban.login.network','getMarzbanToken',[2],[read('server_config',[config_row()])],
        [{'method':'token','body':'','errno':7}])
    add('marzban.users.explicit-token','getMarzbanJson',[2],
        [read('server_config',[config_row()]),read('server_config',[config_row()])],[*token_ok,{'method':'users','body':[{'username':'one'}]}])
    add('marzban.user.explicit-token','getMarzbanUser',[2,'user X'],
        [read('server_config',[config_row()]),read('server_config',[config_row()])],[*token_ok,{'method':'user+X','body':{'username':'user X','status':'active'}}])
    add('marzban.user.missing','getMarzbanUser',[2,'absent'],
        [read('server_config',[config_row()]),read('server_config',[config_row()])],[*token_ok,{'method':'absent','body':{'detail':'not found'},'status':404}])
    add('marzban.user.auth','getMarzbanUser',[2,'alice'],
        [read('server_config',[config_row()]),read('server_config',[config_row()])],
        [*token_ok,{'method':'alice','body':{'username':'alice','status':'active'}}])
    add('traffic.marzban.active','getOrderTrafficInfo',['marzban',2,'alice','id',0],
        [read('server_config',[config_row()]),read('server_config',[config_row()])],
        [*token_ok,{'method':'alice','body':{'username':'alice','status':'active','data_limit':2147483648,'used_traffic':1073741824}}])
    add('traffic.marzban.notfound','getOrderTrafficInfo',['marzban',2,'alice','id',0],
        [read('server_config',[config_row()]),read('server_config',[config_row()])],
        [*token_ok,{'method':'alice','body':{'detail':'not found'}}])
    return out

def fixture(target:Path,changed:bool):
    (target/'tests/support').mkdir(parents=True)
    shutil.copyfile(ROOT/'tests/support/doubles.php',target/'tests/support/doubles.php')
    shutil.copyfile(ROOT/'tests/v6/panel.php',target/'panel.php')
    source=(ROOT/'config.php').read_bytes() if changed else c.reference('config.php')
    names=c.PANEL_FUNCS+c.DETAIL_FUNCS
    (target/'functions-under-test.php').write_bytes(function_bodies(source,names))
    if changed:
        for name in c.MODULES:
            if c.MODULES[name] is not None:
                p=target/name;p.parent.mkdir(parents=True,exist_ok=True)
                shutil.copyfile(ROOT/name,p)

def run(root:Path,spec:dict):
    (root/'case.json').write_text(json.dumps(spec,ensure_ascii=True))
    target=root/'result.json'
    if target.exists():target.unlink()
    env={'PATH':os.environ.get('PATH','/usr/bin:/bin'),'LANG':'C.UTF-8','ACHBOT_PRIVATE_TEST':'1'}
    cmd=['php','-n','-d','open_basedir='+str(root),'-d','allow_url_fopen=0',
         '-d','disable_functions='+DISABLED,'-d','display_errors=0','-d','log_errors=0',
         str(root/'panel.php')]
    p=subprocess.run(cmd,cwd=root,env=env,capture_output=True,timeout=12)
    if p.returncode or p.stdout or p.stderr or not target.is_file():
        raise RuntimeError('Panel fixture failed '+repr((p.returncode,p.stdout[-500:],p.stderr[-500:])))
    return json.loads(target.read_text())

def main():
    ap=argparse.ArgumentParser();ap.add_argument('--report',type=Path)
    ap.add_argument('--case',action='append',default=[]);args=ap.parse_args()
    c.verify()
    chosen={name:s for name,s in cases().items() if not args.case or any(name.startswith(x) for x in args.case)}
    observed_warnings=0
    with tempfile.TemporaryDirectory(prefix='achbot-v6-panel-') as tmp:
        old=Path(tmp)/'old';new=Path(tmp)/'new'
        fixture(old,False);fixture(new,True)
        for name,spec in chosen.items():
            a=run(old,spec);b=run(new,spec)
            if a!=b:raise RuntimeError('Panel regression '+name+': '+json.dumps({'old':a,'new':b},ensure_ascii=False)[:3500])
            if not a['complete'] or a['exception'] or any(not error.startswith('PHP ') for error in a['errors']):
                raise RuntimeError('Panel fixture not behaviorally validated '+name+': '+json.dumps(a,ensure_ascii=False)[:2500])
            observed_warnings+=len(a['errors'])
    report={'reference':c.BASE,'cases':len(chosen),'legacy_php_warnings_compared':observed_warnings,'names':sorted(chosen),
            'original_global_facades':True,'sql_bind_order_and_curl_options_equal':True,'synthetic_no_network':True}
    if args.report:args.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps({k:v for k,v in report.items() if k!='names'}))
if __name__=='__main__':main()
