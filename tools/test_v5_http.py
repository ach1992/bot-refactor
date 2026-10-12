#!/usr/bin/env python3
"""AChBot V5 internal PHP modules must not execute over direct HTTP."""
from __future__ import annotations
import argparse
import json
import os
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request
from pathlib import Path
import v5_contract as c


def main():
    ap=argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--run-loopback',action='store_true',required=True)
    ap.add_argument('--report',type=Path)
    a=ap.parse_args()
    c.verify()
    with tempfile.TemporaryDirectory(prefix='achbot-v5-http-') as tmp:
        root=Path(tmp)
        for name in c.NEW_FILES:
            p=root/name;p.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(c.ROOT/name,p)
        with socket.socket() as sk:
            sk.bind(('127.0.0.1',0));port=sk.getsockname()[1]
        env={'PATH':os.environ.get('PATH','/usr/bin:/bin'),'LANG':'C.UTF-8'}
        app=subprocess.Popen(['php','-n','-S',f'127.0.0.1:{port}','-t',tmp],env=env,cwd=root,
                             stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        probes=[]
        try:
            for _ in range(35):
                if app.poll() is not None:raise RuntimeError('Owned php server terminated')
                try:
                    urllib.request.urlopen(f'http://127.0.0.1:{port}/health-not-found',timeout=.5)
                except urllib.error.HTTPError:
                    break
                except urllib.error.URLError:
                    time.sleep(.05)
            else:raise RuntimeError('Loopback server unavailable')
            for name in c.NEW_FILES:
                for method in ['GET','POST']:
                    request=urllib.request.Request('http://127.0.0.1:'+str(port)+'/'+name,
                                      data=b'fixture=1' if method=='POST' else None,method=method)
                    try:
                        with urllib.request.urlopen(request,timeout=5) as response:
                            status=response.status;data=response.read()
                    except urllib.error.HTTPError as err:
                        status=err.code;data=err.read()
                    if status!=404 or data:
                        raise RuntimeError('Direct module exposed content '+name+' '+method+': '+str(status))
                    probes.append(name+':'+method)
        finally:
            if app.poll() is None:app.terminate()
            try:app.wait(timeout=6)
            except subprocess.TimeoutExpired:app.kill();app.wait(timeout=3)
    report={'direct_module_paths_checked':len(c.NEW_FILES),'get_post_requests':len(probes),
            'empty_404_all':True,'owned_loopback_only':True}
    if a.report:a.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps(report))

if __name__=='__main__':main()
