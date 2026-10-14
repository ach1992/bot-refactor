#!/usr/bin/env python3
"""Direct GET/POST to all new internal PHP modules must be empty 404 on localhost."""
from __future__ import annotations
import argparse,json,os,shutil,socket,subprocess,tempfile,time,urllib.error,urllib.request
from pathlib import Path
import v8_contract as c

def main():
    p=argparse.ArgumentParser();p.add_argument('--report',type=Path);a=p.parse_args();c.verify()
    with tempfile.TemporaryDirectory(prefix='achbot-v8-internal-http-') as tmp:
        root=Path(tmp)
        for path in c.MODULES:
            target=root/path;target.parent.mkdir(parents=True,exist_ok=True)
            shutil.copyfile(c.ROOT/path,target)
        with socket.socket() as sock:
            sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
        e={'PATH':os.environ.get('PATH','/usr/bin:/bin'),'LANG':'C.UTF-8','HOME':tmp}
        cmd=['php','-n','-S','127.0.0.1:'+str(port),'-t',str(root)]
        with (root/'server.log').open('wb') as log:
            child=subprocess.Popen(cmd,env=e,stdout=log,stderr=log)
            try:
                failures=[]
                for path in c.MODULES:
                    for method in ('GET','POST'):
                        result=None
                        url='http://127.0.0.1:'+str(port)+'/'+path
                        for _ in range(18):
                            if child.poll() is not None:raise RuntimeError('Owned PHP server unexpectedly exited')
                            try:
                                with urllib.request.urlopen(urllib.request.Request(url,method=method),timeout=3) as req:
                                    result=(req.status,req.read())
                            except urllib.error.HTTPError as ex:
                                result=(ex.code,ex.read())
                            except urllib.error.URLError:
                                time.sleep(.05);continue
                            break
                        if result!=(404,b''):failures.append((path,method,result))
                if failures:raise RuntimeError('Internal module HTTP exposure '+repr(failures[:4]))
            finally:
                child.terminate()
                try:child.wait(timeout=5)
                except subprocess.TimeoutExpired:child.kill();child.wait(timeout=5)
    report={'new_internal_php_modules':len(c.MODULES),'guarded_http_requests':len(c.MODULES)*2,
            'methods':['GET','POST'],'every_response_empty_404':True,'localhost_only':True}
    if a.report:a.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps(report))
if __name__=='__main__':main()
