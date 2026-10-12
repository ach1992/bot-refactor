#!/usr/bin/env python3
"""Owned localhost direct GET/POST security regression for all ten new V6 internal modules."""
from __future__ import annotations
import argparse,json,os,shutil,socket,subprocess,tempfile,time,urllib.error,urllib.request
from pathlib import Path
import v6_contract as c

def main():
    ap=argparse.ArgumentParser();ap.add_argument('--run-loopback',required=True,action='store_true')
    ap.add_argument('--report',type=Path);args=ap.parse_args();c.verify()
    requests=[]
    with tempfile.TemporaryDirectory(prefix='achbot-v6-guard-') as tmp:
        root=Path(tmp)
        for name in c.MODULES:
            p=root/name;p.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(c.ROOT/name,p)
        with socket.socket() as s:
            s.bind(('127.0.0.1',0));port=s.getsockname()[1]
        env={'PATH':os.environ.get('PATH','/usr/bin:/bin'),'LANG':'C.UTF-8'}
        app=subprocess.Popen(['php','-n','-S',f'127.0.0.1:{port}','-t',tmp],cwd=root,env=env,
                             stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        try:
            for n in range(60):
                if app.poll() is not None:raise RuntimeError('Loopback PHP exited')
                try:urllib.request.urlopen('http://127.0.0.1:'+str(port)+'/nothing',timeout=.4)
                except urllib.error.HTTPError:break
                except urllib.error.URLError:time.sleep(.05)
            else:raise RuntimeError('Loopback not ready')
            for name in c.MODULES:
                for method in ('GET','POST'):
                    req=urllib.request.Request('http://127.0.0.1:'+str(port)+'/'+name,
                        data=b'fixture=1' if method=='POST' else None,method=method)
                    try:
                        with urllib.request.urlopen(req,timeout=5) as res:
                            code=res.status;body=res.read()
                    except urllib.error.HTTPError as e:code=e.code;body=e.read()
                    if code!=404 or body:raise RuntimeError('Direct module exposed '+name+' '+method+': '+str(code))
                    requests.append(name+':'+method)
        finally:
            if app.poll() is None:app.terminate()
            try:app.wait(timeout=5)
            except subprocess.TimeoutExpired:app.kill();app.wait(timeout=3)
    report={'internal_modules':len(c.MODULES),'get_post_probes':len(requests),
            'empty_404_for_direct_access':True,'no_public_path_replacement':True}
    if args.report:args.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps(report))
if __name__=='__main__':main()
