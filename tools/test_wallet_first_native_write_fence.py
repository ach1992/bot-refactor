#!/usr/bin/env python3
"""Characterize the real PasarGuard adapter's non-CAS write boundary.

The synthetic HTTP peer implements the reviewed upstream unconditional
modify semantics. No actual Native account or network endpoint is contacted.
PASS here means the unsafe ordering was REPRODUCED, not that write fencing
exists. This is a feasibility counterexample, not a provider acceptance test.
"""
from __future__ import annotations

import argparse
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
from pathlib import Path
import subprocess
import tempfile
import threading

ROOT=Path(__file__).resolve().parents[1]
PHP=r'''<?php
define('ACHBOT_PG_TEST_LOOPBACK',true);
require $argv[1].'/src/Panel/PasarGuard.php';
$p=['id'=>3,'instance_key'=>str_repeat('a',32),'api_url'=>$argv[2],'api_key'=>'fixture'];
$i=['remote_user_id'=>1,'remote_created_at'=>'2026-01-01T00:00:00.000000Z',
    'operation_marker'=>'achbot:v1:'.str_repeat('b',32)];
$b=['data_limit'=>(int)$argv[3],'expire'=>1800000000,'status'=>'active','group_ids'=>[1]];
echo json_encode(achbotPgModify($p,$i,['data_limit'=>(int)$argv[4]],null,null,$b),JSON_THROW_ON_ERROR);
'''

def main():
    ap=argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--run-disposable',required=True,action='store_true')
    ap.add_argument('--report',type=Path)
    args=ap.parse_args()
    lock=threading.Lock();entered=threading.Event();release=threading.Event()
    user=dict(id=1,username='fixture',created_at='2026-01-01T00:00:00Z',
        note='achbot:v1:'+'b'*32,data_limit=100,expire=1800000000,
        status='active',group_ids=[1],used_traffic=0,subscription_url='https://fixture.invalid/sub',
        proxy_settings={'vless':{'id':'fixture'}},data_limit_reset_strategy='no_reset',
        next_plan=None,auto_delete_in_days=None,on_hold_expire_duration=None,on_hold_timeout=None)
    writes=[]
    class Handler(BaseHTTPRequestHandler):
        def log_message(self,*args):pass
        def reply(self,obj):
            wire=json.dumps(obj).encode()
            self.send_response(200);self.send_header('Content-Type','application/json')
            self.send_header('Content-Length',str(len(wire)));self.end_headers()
            self.wfile.write(wire)
        def do_GET(self):
            assert self.path=='/api/user/by-id/1' and self.headers['X-Api-Key']=='fixture'
            with lock:copy=dict(user)
            self.reply(copy)
        def do_PUT(self):
            assert self.path=='/api/user/by-id/1' and self.headers['X-Api-Key']=='fixture'
            # There is no version/condition in the reviewed request contract.
            assert self.headers.get('If-Match') is None
            change=json.loads(self.rfile.read(int(self.headers['Content-Length'])))
            assert list(change)==['data_limit'],change
            if change['data_limit']==200 and not entered.is_set():
                entered.set()
                assert release.wait(20)
            with lock:
                user.update(change);writes.append(user['data_limit']);copy=dict(user)
            try:self.reply(copy)
            except (BrokenPipeError,ConnectionResetError):pass
    server=ThreadingHTTPServer(('127.0.0.1',0),Handler)
    thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
    base='http://127.0.0.1:'+str(server.server_port)
    try:
        with tempfile.TemporaryDirectory(prefix='wallet-first-native-fence-') as tmp:
            driver=Path(tmp)/'driver.php';driver.write_text(PHP)
            def command(before,target):
                return ['php','-n','-d','extension=curl','-d','allow_url_fopen=0',
                    str(driver),str(ROOT),base,str(before),str(target)]
            def update(before,target):
                p=subprocess.run(command(before,target),capture_output=True,text=True,timeout=15,check=True)
                assert not p.stderr,p.stderr
                return json.loads(p.stdout)
            # First PUT arrives, but server-side processing remains pending
            # after the actual current client's 10-second timeout.
            old=subprocess.Popen(command(100,200),stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
            try:
                assert entered.wait(5)
                out,err=old.communicate(timeout=15)
                original=json.loads(out)
                assert old.returncode==0 and not err,err
                assert not original['success'] and original['uncertain'] and original['reason']=='pg_timeout',original
                # An assumed safe identical retry succeeds. Its GET sees
                # original baseline, and its readback proves the target.
                retry=update(100,200)
                assert retry['success'] and retry['user']['data_limit']==200,retry
                # A later legitimate operation is acknowledged and verified.
                later=update(200,300)
                assert later['success'] and later['user']['data_limit']==300,later
                # The original delayed write still arrives AFTER finality.
                release.set()
                for _ in range(100):
                    with lock:
                        if writes==[200,300,200]:break
                    threading.Event().wait(.02)
                with lock:
                    assert writes==[200,300,200] and user['data_limit']==200,(writes,user['data_limit'])
            finally:
                release.set()
                if old.poll() is None:old.kill();old.wait()
    finally:
        release.set();server.shutdown();server.server_close();thread.join(5)
    result=dict(complete=True,cases=1,meaning='counterexample_reproduced_not_fence_acceptance',
        original='pg_timeout',retry_verified=200,later_verified=300,final_quota=200,writes=writes)
    if args.report:args.report.write_text(json.dumps(result,indent=2)+'\n')
    print(json.dumps(result))

if __name__=='__main__':main()
