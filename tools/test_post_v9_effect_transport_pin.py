#!/usr/bin/env python3
"""PR66 R2: real PHP cURL + disposable MariaDB + two local Marzban panels.

Exercise actual 007 effect dispatcher, Core createServerAccount and native
Marzban adapter; prove no redirect/credential/physical-target substitution.
No external network, real credentials, customer funds or installed services.
"""
from __future__ import annotations
import argparse
import contextlib
import hashlib
import json
import shutil
import threading
import time
from http.server import BaseHTTPRequestHandler,ThreadingHTTPServer
from pathlib import Path

import test_access_state as owned
import test_v6_native as native
import test_v8_native as purchase
import test_post_v9_marzban as marzban

ROOT=Path(__file__).resolve().parents[1]
DB=native.DBS[1]
OP='0123456789abcdef0123456789abcdef'
GIB=1073741824

def expect(test,message):
    if not test:raise RuntimeError(message)

def sql(work,env,query):
    return native.sql(work,env,query,DB).strip()

class PhysicalPanel(BaseHTTPRequestHandler):
    def log_message(self,*args):pass
    def do_POST(self):self.handle_request()
    def do_GET(self):self.handle_request()
    def handle_request(self):
        server=self.server
        content=self.rfile.read(int(self.headers.get('Content-Length','0') or '0'))
        server.trace.append({'method':self.command,'path':self.path,
                             'body':content.decode('utf-8','replace')})
        mode=getattr(server,'mode','normal')
        status=200
        payload={}
        redirect=None
        if self.path=='/api/admin/token':
            if mode=='redirect_login':
                status=302
                redirect=server.other+'/api/admin/token'
            else:
                if mode in ('switch_at_login','switch_credentials_at_login'):
                    server.switch('target' if mode=='switch_at_login' else 'credentials')
                payload={'access_token':'test-only-access'}
        elif self.path=='/api/user' and self.command=='POST':
            if mode=='redirect_create':
                status=307
                redirect=server.other+'/api/user'
            else:
                request=json.loads(content)
                server.remote[request['username']]=dict(request,subscription_url='/sub/fixture')
                if mode in ('switch_after_post','switch_credentials_after_post'):
                    server.switch('target' if mode=='switch_after_post' else 'credentials')
                if mode=='lost_create':
                    status=503
                    payload={'detail':'synthetic_lost_ack'}
                else:
                    payload={'links':['vless://fixture']}
        elif self.path.startswith('/api/user/') and self.command=='GET':
            username=self.path.split('/api/user/',1)[1]
            payload=server.remote.get(username,{'detail':'missing'})
            if mode in ('switch_at_get','switch_credentials_at_get'):
                server.switch('target' if mode=='switch_at_get' else 'credentials')
        else:
            status=404
            payload={'detail':'not_found'}
        wire=json.dumps(payload,separators=(',',':')).encode()
        self.send_response(status)
        if redirect:self.send_header('Location',redirect)
        self.send_header('Content-Type','application/json')
        self.send_header('Content-Length',str(len(wire)))
        self.end_headers()
        self.wfile.write(wire)

@contextlib.contextmanager
def panels():
    a=ThreadingHTTPServer(('127.0.0.1',0),PhysicalPanel)
    b=ThreadingHTTPServer(('127.0.0.1',0),PhysicalPanel)
    urls=['http://127.0.0.1:'+str(server.server_address[1]) for server in (a,b)]
    for i,server in enumerate((a,b)):
        server.trace=[]
        server.remote={}
        server.mode='normal'
        server.other=urls[1-i]
        server.switch=lambda kind:None
    workers=[threading.Thread(target=s.serve_forever,daemon=True) for s in (a,b)]
    for t in workers:t.start()
    try:yield a,b,*urls
    finally:
        for s in (a,b):s.shutdown()
        for t in workers:t.join(timeout=5)
        for s in (a,b):s.server_close()

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('--run-disposable',action='store_true',required=True)
    ap.add_argument('--report',type=Path)
    args=ap.parse_args()
    cases=[]
    with owned.owned_database() as (work,env),panels() as (a,b,url_a,url_b):
        purchase.init(work,env,url_a)
        sql(work,env,(ROOT/'migrations/post-v9/001_marzban_panel_group_id.sql').read_text())
        sql(work,env,(ROOT/'migrations/post-v9/007_service_effects.sql').read_text())
        stage=work/'strict-create'
        marzban.prepare(work,stage,True)
        for f in ('src/Purchase/Core.php','src/Finance/ServiceEffects.php'):
            target=stage/f;target.parent.mkdir(parents=True,exist_ok=True)
            shutil.copyfile(ROOT/f,target)
        with (stage/'functions-under-test.php').open('a') as output:
            output.write("""
function achbotSupportsPanel($type) { return $type==='marzban'; }
require_once __DIR__.'/src/Purchase/Core.php';
require_once __DIR__.'/src/Finance/ServiceEffects.php';
function ownedStrictEffectDispatch($effectId,$serverId,$group,$endpoint,$remark,
    $expires,$credentialFingerprint) {
    global $connection;
    return achbotEffectDispatchReservedAccount(
        $connection,(int)$effectId,(int)$serverId,$group,$endpoint,
        $remark,2,30,7,'0123456789abcdef0123456789abcdef',
        (int)$expires,2147483648,
        ['uniqid'=>'synthetic','protocol'=>'vless','port'=>443,
         'expire_microdate'=>0,'net_type'=>'normal','rahgozar'=>0,'limitip'=>0],
        $credentialFingerprint);
}
""")

        def configure(mode,group=None):
            a.mode=mode;a.trace.clear();a.remote.clear()
            b.mode='normal';b.trace.clear();b.remote.clear()
            sql(work,env,"UPDATE server_config SET panel_url='"+
                url_a+"',type='marzban',username='fixture-admin',password='fixture-password' WHERE id=2")
            sql(work,env,"UPDATE server_config SET panel_url='"+
                url_b+"',type='marzban',username='different-alias',password='alias-secret' WHERE id=3")
            sql(work,env,"UPDATE server_info SET panel_group_id="+
                ('NULL' if group is None else str(group))+" WHERE id=2")
            sql(work,env,"UPDATE server_info SET panel_group_id=NULL WHERE id=3")
            sql(work,env,"DELETE FROM service_effects")
            a.switch=lambda kind: sql(work,env,
                ("UPDATE server_config SET panel_url='"+url_b+"' WHERE id=2"
                 if kind=='target' else
                 "UPDATE server_config SET username='changed-admin',password='changed-pass' WHERE id=2"))
        def run(effect_id,expires,url=url_a,group=None):
            credential_hash=hashlib.sha256(json.dumps(
                ['fixture-admin','fixture-password'],ensure_ascii=False,separators=(',',':')
            ).encode()).hexdigest()
            result=native.run(work,env,stage,DB,{
                'function':'ownedStrictEffectDispatch',
                'args':[effect_id,2,group,hashlib.sha256(url.encode()).hexdigest(),
                        'fixture-user',expires,credential_hash]})
            expect(not result['warnings'] and result['stdout']=='',f'Unexpected PHP warnings: {result}')
            return result
        def journal(group=None):
            expires=int(time.time())+30*86400
            endpoint=hashlib.sha256(url_a.encode()).hexdigest()
            scope='server:2' if group is None else 'group:'+str(group)
            sql(work,env,"INSERT INTO service_effects "
                "(source_kind,source_ref,actor_userid,ordinal,operation_id,effect_kind,"
                "target_server_id,target_group_id,target_key,endpoint_sha256,plan_id,"
                "remote_username,requested_expire,requested_bytes,request_sha256,reserved_count,state) "
                "VALUES('free_trial','trial:1001',1001,1,'"+OP+"','create',2,"+
                ('NULL' if group is None else str(group))+",'"+scope+"','"+endpoint+
                "',7,'fixture-user',"+str(expires)+",2147483648,'"+
                'a'*64+"',1,'dispatching')")
            return int(sql(work,env,'SELECT id FROM service_effects ORDER BY id DESC LIMIT 1')),expires
        def check_hold(effect_id,mode,post_count,gets_b=0):
            state=sql(work,env,f'SELECT state FROM service_effects WHERE id={effect_id}')
            expect(state=='dispatching',f'Unverified {mode} became {state}')
            posted=sum(x['method']=='POST' and x['path']=='/api/user' for x in a.trace)
            expect(posted==post_count,f'{mode} unexpectedly POSTed {posted} on A: {a.trace}')
            expect(len(b.trace)==gets_b,f'{mode} sent requests to wrong panel: {b.trace}')

        configure('normal')
        effect,expiry=journal()
        result=run(effect,expiry)
        expect(result['exception'] is None and result['result'] is not None,
               f'Normal strict effect failed: {result} trace={a.trace} wrong={b.trace}')
        expect(sql(work,env,f'SELECT state FROM service_effects WHERE id={effect}')=='verified',
               'Normal pinned effect not verified')
        expect(len(b.trace)==0 and
               sum(x['path']=='/api/user' for x in a.trace)==1,
               f'Normal effect went to wrong panel: {a.trace} {b.trace}')
        cases.append('strict_effect_normal_auth_post_get_exact_same_panel_verified')

        # The physical endpoint hash is immutable across reservation/dispatch.
        configure('normal')
        effect,expiry=journal()
        sql(work,env,"UPDATE server_config SET panel_url='"+url_b+"' WHERE id=2")
        failed=run(effect,expiry)
        expect(failed['exception'] is not None,'Reserved endpoint was substituted')
        check_hold(effect,'target_changed_before_preflight',0)
        expect(not a.trace,'Preflight target change issued HTTP')
        cases.append('journal_reservation_endpoint_changed_no_outbound_effect')

        configure('normal')
        effect,expiry=journal()
        sql(work,env,"UPDATE server_config SET password='changed-pass' WHERE id=2")
        failed=run(effect,expiry)
        expect(failed['exception'] is not None,'Reservation credential changed undetected')
        check_hold(effect,'credentials_changed_before_preflight',0)
        expect(not a.trace,'Preflight credential change issued HTTP')
        cases.append('journal_reservation_credentials_changed_no_outbound_effect')

        for mode,post_count in (
            ('switch_at_login',0),
            ('switch_credentials_at_login',0),
            ('redirect_login',0),
            ('redirect_create',1),
            ('switch_after_post',1),
            ('switch_credentials_after_post',1),
            ('switch_at_get',1),
            ('switch_credentials_at_get',1),
            ('lost_create',1),
        ):
            configure(mode)
            effect,expiry=journal()
            failed=run(effect,expiry)
            expect(failed['exception'] is not None,f'{mode} false local success: {failed}')
            check_hold(effect,mode,post_count)
            cases.append(mode+'_no_wrong_panel_or_false_verified')

        # Explicit physical aliases do not authorize tenant substitution:
        # two server IDs share one group but use different HTTP panel URLs.
        configure('normal',group=9991)
        sql(work,env,"INSERT INTO setting (id,type,value) "
                     "VALUES(9991,'ACHBOT_MARZBAN_PANEL_GROUP','fixture')")
        sql(work,env,'UPDATE server_info SET panel_group_id=9991 WHERE id=3')
        effect,expiry=journal(group=9991)
        ok=run(effect,expiry,group=9991)
        expect(ok['exception'] is None and
               sql(work,env,f'SELECT state FROM service_effects WHERE id={effect}')=='verified' and
               len(b.trace)==0,
               f'Group alias substituted second physical panel: {ok} {b.trace}')
        cases.append('same_group_different_endpoint_auth_post_get_remains_pinned')
        sql(work,env,'UPDATE server_info SET panel_group_id=NULL WHERE id IN (2,3)')
        sql(work,env,'DELETE FROM setting WHERE id=9991')
    result={'complete':True,'cases':len(cases),'scenarios':cases,
        'actual_007_journal':True,'actual_php_curl':True,
        'physical_synthetic_panels':2,'owned_mariadb_only':True,
        'external_network_requests':0,'installed_changes':0}
    if args.report:args.report.write_text(json.dumps(result,indent=2)+'\n')
    print(json.dumps(result))
if __name__=='__main__':main()
