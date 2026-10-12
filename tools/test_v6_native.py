#!/usr/bin/env python3
"""AChBot V6 paired real PHP 8.4 MariaDB/loopback HTTP provider comparisons.

Owned, disposable socket-only original 23-table schema, two private DBs,
real PHP mysqli and cURL, synthetic X-UI/Sanaei/Alireza/Marzban HTTP server.
Never touches a production service, real credentials, or outside repository.
"""
from __future__ import annotations
import argparse, contextlib, hashlib, json, os, re, shutil, subprocess, sys, tempfile, threading
from http.server import BaseHTTPRequestHandler,ThreadingHTTPServer
from pathlib import Path
import v6_contract as c
import test_v6_panels as panels
import test_v5_native as v5n
import test_access_state as support
ROOT=c.ROOT
DBS=('achbot_v4_old','achbot_v4_new')

class Handler(BaseHTTPRequestHandler):
    server_version='AChBot-Synthetic-Panel'
    def do_GET(self):self.handle_request()
    def do_POST(self):self.handle_request()
    def do_PUT(self):self.handle_request()
    def do_DELETE(self):self.handle_request()
    def log_message(self,*args):pass
    def handle_request(self):
        n=int(self.headers.get('Content-Length','0'))
        body=self.rfile.read(n) if n else b''
        headers={k.lower():v for k,v in self.headers.items()
            if k.lower() in ('cookie','authorization','content-type','x-requested-with','accept','user-agent')}
        # Real cURL picks a fresh multipart delimiter per handle. Check every
        # part/payload byte after canonicalizing only that transport delimiter.
        content_type=headers.get('content-type','')
        boundary=re.search(r'; boundary=([^;]+)',content_type)
        if boundary and content_type.startswith('multipart/form-data'):
            marker=boundary.group(1).encode('ascii')
            body=body.replace(marker,b'achbot-fixture-boundary')
            headers['content-type']=content_type.replace(boundary.group(1),'achbot-fixture-boundary')
        # Real X-UI expiry writes include a fresh 2-day clock timestamp.
        # Assert that interval before canonicalizing the wall-clock digits.
        if b'expiryTime' in body:
            import time
            def stable_expiry(match):
                ms=int(match.group(2))
                if abs(ms/1000-(time.time()+2*86400))<15:
                    return match.group(1)+b'fixture-time-plus-2-days'
                return match.group(0)
            body=re.sub(rb'("expiryTime"\s*:\s*)(\d{12,14})',stable_expiry,body)
            body=re.sub(rb'(Content-Disposition: form-data; name="expiryTime"\r\n\r\n)(\d{12,14})',stable_expiry,body)
        if self.path=='/api/user' and self.command=='POST':
            # Existing Marzban new-account API sets expiry from wall-clock.
            # Preserve every other request byte and assert exact 30-day offset
            # within the unavoidable request scheduling interval.
            request=json.loads(body)
            if 'expire' in request:
                import time
                offset=request['expire']-time.time()
                if not (30*86400-10<=offset<=30*86400+10):
                    raise RuntimeError('Marzban account expiry offset changed')
                request['expire']='fixture-time-plus-30-days'
                body=json.dumps(request,ensure_ascii=False,separators=(',',':')).encode()
        self.server.trace.append({'method':self.command,'path':self.path,
            'body':body.decode('utf-8','replace'),'headers':headers})
        status=200
        mode=getattr(self.server,'mode','ok')
        if self.path.endswith('/login'):
            if mode=='xui-invalid':
                status=401;payload={'success':False,'msg':'Invalid credentials'};headers={}
            else:
                payload={'success':True};headers={'Set-Cookie':'session=fixture-session; Path=/; HttpOnly'}
        elif self.path.endswith('/api/admin/token'):
            if mode=='marzban-invalid':
                status=401;payload={'detail':'Invalid credentials'}
            elif mode=='marzban-503':
                status=503;payload={'detail':'Unavailable'}
            else:payload={'access_token':'fixture-access'}
            headers={}
        elif '/api/user/' in self.path:
            payload={'username':'fixture-user','status':'active','data_limit':2147483648,
              'used_traffic':1073741824,'expire':1800000100,'inbounds':{},'proxies':{},
              'note':'fixture','data_limit_reset_strategy':'no_reset','subscription_url':'/sub/fixture'}
            if self.command=='PUT':payload={'status':'ok'}
            headers={}
        elif self.path.endswith('/api/users'):
            payload={'users':[]};headers={}
        elif self.path=='/api/user' and self.command=='POST':
            payload={'links':['vless://fixture']};headers={}
        elif self.path.endswith('/panel/inbound/list') or self.path.endswith('/xui/inbound/list'):
            client={'id':'fixture-uuid','email':'fixture-user','enable':True,'flow':'xtls-rprx-vision'}
            transport=getattr(self.server,'transport','ws')
            security=getattr(self.server,'security','none')
            stream={'network':transport,'security':security,
              'tcpSettings':{'header':{'type':'none','request':{'path':['/'],'headers':{'Host':['fixture.invalid']}}}},
              'wsSettings':{'header':{'type':'http'},'path':'/socket','headers':{'Host':'cdn.fixture.invalid'}},
              'grpcSettings':{'serviceName':'fixture-grpc'},
              'kcpSettings':{'header':{'type':'none'},'seed':'fixture-seed'},
              'tlsSettings':{'serverName':'tls.fixture.invalid','certificates':{'alpn':['h2']}},
              'xtlsSettings':{'serverName':'xtls.fixture.invalid','alpn':['h2']},
              'realitySettings':{'settings':{'fingerprint':'chrome','spiderX':'/','publicKey':'fixture-pubkey'},
                                'serverNames':['reality.fixture.invalid'],'shortIds':['fixture-short']}}
            payload={'success':True,'obj':[{'id':7,'port':443,'protocol':'vless',
               'settings':json.dumps({'clients':[client]}),
               'streamSettings':json.dumps(stream),
               'clientStats':[{'email':'fixture-user','up':0,'down':0,'total':2147483648,'enable':True}],
               'total':2147483648,'up':0,'down':0}]};headers={}
        else:
            payload={'success':True,'obj':[]};headers={}
        encoded=json.dumps(payload,ensure_ascii=False).encode()
        self.send_response(status)
        self.send_header('Content-Type','application/json')
        self.send_header('Content-Length',str(len(encoded)))
        for k,v in headers.items():self.send_header(k,v)
        self.end_headers();self.wfile.write(encoded)

@contextlib.contextmanager
def owned_provider():
    server=ThreadingHTTPServer(('127.0.0.1',0),Handler)
    server.trace=[]
    th=threading.Thread(target=server.serve_forever,daemon=True);th.start()
    try:yield server,'http://127.0.0.1:'+str(server.server_address[1])
    finally:server.shutdown();th.join(timeout=6);server.server_close()

def sql(work,env,query,db=None):return v5n.sql_command(work,env,query,db)

def seed(work,env,port):
    for db in DBS:
        sql(work,env,'DELETE FROM server_config WHERE id IN (2,3,4); DELETE FROM server_plans WHERE id=7; DELETE FROM orders_list WHERE id=91;',db)
        for id,typ in [(2,'marzban'),(3,'sanaei'),(4,'alireza')]:
            safe=port.replace("'","''")
            sql(work,env,f"INSERT INTO server_config (id,panel_url,ip,sni,header_type,request_header,response_header,security,tlsSettings,type,username,password,sub_links) VALUES ({id},'{safe}','127.0.0.1','','none','','','none','{{}}','{typ}','fixture-admin','fixture-password','');",db)
        sql(work,env,"INSERT INTO server_plans (id,fileid,catid,server_id,acount,title,protocol,days,volume,type,price,descr,pic,active,step,date,custom_sni) VALUES (7,'7',1,3,1,'Fixture','vless',30,2,'normal',100,'description','',1,0,'fixture','{\"inbounds\":{\"vless\":[\"inbound\"]}}');",db)
        sql(work,env,"INSERT INTO orders_list (id,userid,token,transid,fileid,server_id,inbound_id,remark,uuid,protocol,expire_date,link,amount,status,date) VALUES (91,1001,REPEAT('a',30),'fixture',7,3,7,'fixture-user','fixture-uuid','vless',1800000100,'[\"vless://fixture\"]',100,1,'fixture');",db)

def case_defs():
    return {
      'marzban.token':('getMarzbanToken',[2]),
      'marzban.token.invalid':('getMarzbanToken',[2]),
      'marzban.token.service_503':('getMarzbanToken',[2]),
      'marzban.user.unauthorized':('getMarzbanUser',[2,'fixture-user']),
      'sanaei.login.invalid':('getJson',[3]),
      'alireza.login.invalid':('getJson',[4]),
      'marzban.users':('getMarzbanJson',[2]),
      'marzban.user':('getMarzbanUser',[2,'fixture-user']),
      'marzban.status':('changeMarzbanState',[2,'fixture-user']),
      'marzban.traffic':('getOrderTrafficInfo',['marzban',2,'fixture-user','fixture-uuid',0]),
      'sanaei.list':('getJson',[3]),
      'alireza.list':('getJson',[4]),
      'sanaei.traffic':('getOrderTrafficInfo',['sanaei',3,'fixture-user','fixture-uuid',7]),
      'alireza.traffic':('getOrderTrafficInfo',['alireza',4,'fixture-user','fixture-uuid',7]),
      'sanaei.link.vmess_ws':('getConnectionLink',[3,'fixture-uuid','vmess','fixture-user',443,'ws',7]),
      'sanaei.link.trojan_ws':('getConnectionLink',[3,'fixture-uuid','trojan','fixture-user',443,'ws',7]),
      'sanaei.link.vless_grpc_tls':('getConnectionLink',[3,'fixture-uuid','vless','fixture-user',443,'grpc',7]),
      'sanaei.link.vmess_grpc_tls':('getConnectionLink',[3,'fixture-uuid','vmess','fixture-user',443,'grpc',7]),
      'sanaei.link.vless_tcp_reality':('getConnectionLink',[3,'fixture-uuid','vless','fixture-user',443,'tcp',7]),
      'alireza.link.vless_tcp_tls':('getConnectionLink',[4,'fixture-uuid','vless','fixture-user',443,'tcp',7]),
      'alireza.link.vless_kcp_none':('getConnectionLink',[4,'fixture-uuid','vless','fixture-user',443,'kcp',7]),
      'alireza.link.trojan_grpc_tls':('getConnectionLink',[4,'fixture-uuid','trojan','fixture-user',443,'grpc',7]),
      'sanaei.links':('getConnectionLink',[3,'fixture-uuid','vless','fixture-user',443,'ws',7]),
      'alireza.links':('getConnectionLink',[4,'fixture-uuid','vless','fixture-user',443,'ws',7]),
      'sanaei.remark':('editClientRemark',[3,7,'fixture-uuid','renamed']),
      'alireza.remark':('editClientRemark',[4,7,'fixture-uuid','renamed']),
      'marzban.detail':('getMarzbanUserInfo',[2,'fixture-user']),
      'marzban.hosts':('getMarzbanHosts',[2]),
      'marzban.update':('editMarzbanConfig',[2,{'remark':'fixture-user','plus_volume':1}]),
      'marzban.reset':('resetMarzbanTraffic',[2,'fixture-user',None]),
      'marzban.uuid':('renewMarzbanUUID',[2,'fixture-user']),
      'marzban.add':('addMarzbanUser',[2,'fixture-user',2,30,7]),
      'sanaei.client.toggle':('changeClientState',[3,7,'fixture-uuid']),
      'sanaei.client.uuid':('renewClientUuid',[3,7,'fixture-uuid']),
      'sanaei.client.traffic':('editClientTraffic',[3,7,'fixture-uuid',1,2]),
      'sanaei.client.delete':('deleteClient',[3,7,'fixture-uuid',1]),
      'sanaei.inbound.toggle':('changeInboundState',[3,'fixture-uuid']),
      'sanaei.inbound.uuid':('renewInboundUuid',[3,'fixture-uuid']),
      'sanaei.inbound.traffic':('editInboundTraffic',[3,'fixture-uuid',1,2]),
      'sanaei.inbound.remark':('editInboundRemark',[3,'fixture-uuid','renamed']),
      'sanaei.inbound.delete':('deleteInbound',[3,'fixture-uuid',1]),
      'sanaei.reset.ip':('resetIpLog',[3,'fixture-user']),
      'sanaei.reset.traffic':('resetClientTraffic',[3,'fixture-user',7]),
      'sanaei.add.client':('addInboundAccount',[3,'fixture-uuid',7,1800000100,'fixture-user',1]),
      'sanaei.update.config':('updateConfig',[3,7,'vless','ws','none',False]),
      'sanaei.edit.inbound':('editInbound',[3,'fixture-uuid','fixture-uuid','vless','ws','none',False]),
      'sanaei.add.user':('addUser',[3,'fixture-uuid','vless',443,1800000100,'fixture-user',2,'ws','none',False,7]),
      'sanaei.cert':('getNewCert',[3]),
      'marzban.delete':('deleteMarzban',[2,'fixture-user']),
    }

def prepare(source:Path,app:Path,changed:bool):
    source_bytes=(ROOT/'config.php').read_bytes() if changed else c.reference('config.php')
    (app/'functions-under-test.php').write_bytes(panels.function_bodies(source_bytes,c.PANEL_FUNCS+c.DETAIL_FUNCS))
    shutil.copyfile(ROOT/'tests/v6/native.php',app/'native.php')
    if changed:
        for path,ns in c.MODULES.items():
            if ns is not None:
                dest=app/path;dest.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(ROOT/path,dest)
    if not (app/'baseInfo.php').exists():
        (app/'baseInfo.php').write_text('<?php // synthetic private placeholder, never imported\n')
    if not (app/'private-state.json').exists():
        (app/'private-state.json').write_text('{"fixture":true}\n')

def run(work,env,app,dbname,case):
    (app/'case.json').write_text(json.dumps(case))
    result=app/'result.json'
    if result.exists():result.unlink()
    e=dict(env,ACHBOT_PRIVATE_TEST='1',ACHBOT_V6_NATIVE='1',ACHBOT_DATABASE=dbname,ACHBOT_SOCKET=str(work/'mysql.sock'))
    cmd=['php','-n','-d','extension=mysqlnd','-d','extension=mysqli','-d','extension=curl',
      '-d','disable_functions=exec,shell_exec,system,passthru,popen,proc_open,pcntl_exec,pcntl_fork,mail,dl',
      '-d','allow_url_fopen=0','-d','open_basedir='+str(app)+':'+str(work),
      '-d','display_errors=0','-d','log_errors=0',str(app/'native.php')]
    p=subprocess.run(cmd,env=e,cwd=app,capture_output=True,timeout=20)
    if p.returncode or p.stdout or p.stderr or not result.is_file():
        raise RuntimeError('Native PHP failure '+repr((p.returncode,p.stdout[-600:],p.stderr[-600:])))
    return json.loads(result.read_text())

def snapshot(work,env,db):
    return {table:sql(work,env,query,db) for table,query in {
        'server_config':'SELECT id,panel_url,type,username,password FROM server_config WHERE id IN (2,3,4) ORDER BY id',
        'plans':'SELECT id,server_id,protocol,custom_path,custom_port FROM server_plans WHERE id=7',
        'orders':'SELECT id,userid,token,server_id,remark,uuid,protocol,link FROM orders_list WHERE id=91',
        'users':'SELECT userid,wallet,step FROM users WHERE userid=1001',
    }.items()}

def main():
    parser=argparse.ArgumentParser();parser.add_argument('--run-disposable',action='store_true',required=True)
    parser.add_argument('--case',action='append',default=[]);parser.add_argument('--report',type=Path)
    args=parser.parse_args()
    c.verify();cases={k:v for k,v in case_defs().items() if not args.case or any(k.startswith(x) for x in args.case)}
    records=[]
    with support.owned_database() as (work,env),owned_provider() as (provider,url):
        seed(work,env,url)
        old=work/'v5';new=work/'v6';old.mkdir();new.mkdir()
        prepare(ROOT,old,False);prepare(ROOT,new,True)
        sentinel={name:hashlib.sha256((new/name).read_bytes()).hexdigest() for name in ('baseInfo.php','private-state.json')}
        for name,(fn,arguments) in cases.items():
            provider.transport=('grpc' if '_grpc_' in name else 'tcp' if '_tcp_' in name
                else 'kcp' if '_kcp_' in name else 'ws')
            provider.security=('reality' if '_reality' in name else 'tls' if '_tls' in name else 'none')
            provider.mode=('marzban-invalid' if name.endswith('.invalid') and name.startswith('marzban')
                or name=='marzban.user.unauthorized' else 'marzban-503' if name=='marzban.token.service_503'
                else 'xui-invalid' if name in ('sanaei.login.invalid','alireza.login.invalid') else 'ok')
            # No live DB mutations: the owned fixtures must remain equivalent throughout.
            results=[];traces=[]
            for app,db in ((old,DBS[0]),(new,DBS[1])):
                provider.trace.clear()
                output=run(work,env,app,db,{'function':fn,'args':arguments})
                results.append(output);traces.append(list(provider.trace))
            if results[0]!=results[1] or traces[0]!=traces[1]:
                raise RuntimeError('V5/V6 native provider mismatch '+name+': '+json.dumps({'old':results[0], 'new':results[1], 'wire_old':traces[0],'wire_new':traces[1]},ensure_ascii=False)[:2300])
            if results[0]['exception']:
                raise RuntimeError('Native scenario lacks successful behavior '+name+': '+str(results[0])[:750])
            if not traces[0]:raise RuntimeError('Native scenario made no HTTP requests: '+name)
            state_old=snapshot(work,env,DBS[0]);state_new=snapshot(work,env,DBS[1])
            if state_old!=state_new:raise RuntimeError('MariaDB state regression '+name)
            records.append({'case':name,'provider_requests':len(traces[0]),'warnings':len(results[0]['warnings'])})
        for name,digest in sentinel.items():
            if hashlib.sha256((new/name).read_bytes()).hexdigest()!=digest:
                raise RuntimeError('Private synthetic sentinel mutated '+name)
    report={'v5_reference':c.BASE,'cases':len(records),'requests':sum(r['provider_requests'] for r in records),
       'details':records,'private_mariadb':'original 23-table DDL, two owned socket-only databases',
       'provider':'owned Python 127.0.0.1 HTTP server, real PHP cURL',
       'same_sql_rows_and_http_wire':True,'canonicalization':'multipart boundaries; time()-relative 2-day X-UI and 30-day Marzban expiry offsets each validated', 'unchanged_private_state':True}
    if args.report:args.report.write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps({k:v for k,v in report.items() if k!='details'}))
if __name__=='__main__':main()
