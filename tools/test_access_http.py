#!/usr/bin/env python3
"""Verify new scoped modules deny direct HTTP execution on an owned loopback PHP server."""
from __future__ import annotations
import argparse,json,os,secrets,shutil,socket,subprocess,tempfile,time,urllib.error,urllib.request
from pathlib import Path
import check_v4 as check
import v4_contract as c

def run_http_guards():
    with c.previous() as (old,lock),tempfile.TemporaryDirectory(prefix='achbot-access-http-') as tmp:
        c.verify(c.ROOT,old,lock);root=Path(tmp)
        for file in c.NEW_FILES:
            p=root/file;p.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(c.ROOT/file,p)
        nonce=secrets.token_hex(16);(root/'ready.php').write_text('<?php echo "'+nonce+'";')
        with socket.socket() as reserved:reserved.bind(('127.0.0.1',0));port=reserved.getsockname()[1]
        base='http://127.0.0.1:'+str(port)
        opener=urllib.request.build_opener(urllib.request.ProxyHandler({}))
        env={'PATH':os.environ.get('PATH','/usr/bin:/bin'),'LANG':'C.UTF-8','HOME':'/nonexistent'}
        args=['php','-n','-d','open_basedir='+tmp,'-d','allow_url_fopen=0','-d','allow_url_include=0',
              '-d','disable_functions='+check.check_v1.run.DISABLED,'-d','display_errors=0','-d','log_errors=0','-S','127.0.0.1:'+str(port),'-t',tmp]
        with (root/'server.log').open('wb') as log:
            server=subprocess.Popen(args,env=env,cwd=root,stdout=log,stderr=log)
            results=[]
            try:
                for _ in range(80):
                    if server.poll() is not None:raise RuntimeError('Owned PHP server exited')
                    try:
                        with opener.open(base+'/ready.php',timeout=.3) as r:
                            if r.read()!=nonce.encode():raise RuntimeError('Loopback ownership nonce mismatch')
                        break
                    except (OSError,urllib.error.URLError):time.sleep(.05)
                else:raise RuntimeError('Owned PHP server did not become ready')
                for file in c.NEW_FILES:
                    for method in ['GET','POST']:
                        req=urllib.request.Request(base+'/'+file+'?data=banUser',method=method,data=b'data=agencyApprove1001' if method=='POST' else None)
                        try:
                            with opener.open(req,timeout=2) as r:status=r.status;body=r.read()
                        except urllib.error.HTTPError as e:status=e.code;body=e.read()
                        if status!=404 or body:raise RuntimeError('Direct module execution not denied: '+file+' '+method)
                        results.append({'file':file,'method':method,'status':status,'body_bytes':len(body)})
            finally:
                if server.poll() is None:server.terminate()
                try:server.wait(timeout=5)
                except subprocess.TimeoutExpired:server.kill();server.wait(timeout=3)
        return {'direct_http_checks':len(results),'results':results,'listener':'owned ephemeral 127.0.0.1 PHP development server',
                'external_provider_used':False,'fpm_tested':False,'production_acceptance':False}

def main():
    ap=argparse.ArgumentParser(description=__doc__);ap.add_argument('--run-loopback',required=True,action='store_true');ap.add_argument('--report',required=True,type=Path);args=ap.parse_args()
    report=run_http_guards();report.update(c.identity());args.report.write_text(json.dumps(report,indent=2)+'\n');print(json.dumps({k:v for k,v in report.items() if k!='results'},sort_keys=True))
if __name__=='__main__':main()
