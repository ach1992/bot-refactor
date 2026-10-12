#!/usr/bin/env python3
"""Compare actual PHP cURL form/multipart behavior against an owned loopback fixture only."""
from __future__ import annotations
import argparse,base64,email.parser,email.policy,json,os,shutil,socket,subprocess,tempfile,threading,urllib.parse
from http.server import BaseHTTPRequestHandler,ThreadingHTTPServer
from pathlib import Path
import check_v2 as check
import v1_contract as v1
from v2_contract import ROOT,previous,verify,candidate_identity
VARIANTS=['ok','429','503','empty','invalid','json-null','multipart','disconnect']

class Fixture(BaseHTTPRequestHandler):
    protocol_version='HTTP/1.1'
    def log_message(self,*args):pass
    def do_POST(self):
        length=int(self.headers.get('Content-Length','0'))
        if length>1024*1024:raise RuntimeError('Oversize native fixture request')
        data=self.rfile.read(length);ctype=self.headers.get('Content-Type','')
        if ctype.startswith('multipart/form-data'):
            msg=email.parser.BytesParser(policy=email.policy.default).parsebytes(('Content-Type: '+ctype+'\r\nMIME-Version: 1.0\r\n\r\n').encode()+data)
            fields=[{'name':p.get_param('name',header='content-disposition'),'filename':p.get_filename(),'type':p.get_content_type(),
                     'bytes':base64.b64encode(p.get_payload(decode=True)).decode()} for p in msg.iter_parts()]
            body={'encoding':'multipart','fields':fields}
        else:body={'encoding':ctype,'bytes':base64.b64encode(data).decode()}
        self.server.requests.append({'path':self.path,'version':self.request_version,'body':body,'expect':self.headers.get('Expect')})
        variant=self.server.variant
        if variant=='disconnect':self.close_connection=True;self.connection.shutdown(socket.SHUT_RDWR);self.connection.close();return
        status=429 if variant=='429' else 503 if variant=='503' else 200
        response={'429':b'{"ok":false,"error_code":429,"parameters":{"retry_after":17}}',
          '503':b'{"ok":false,"description":"fixture server failure"}','empty':b'','invalid':b'not-json','json-null':b'null'}.get(variant,b'{"ok":true,"result":{"message_id":501}}')
        self.send_response(status);self.send_header('Content-Type','application/json');self.send_header('Content-Length',str(len(response)));self.end_headers();self.wfile.write(response)

def functions(root:Path,mode:str):
    src=(root/'config.php').read_bytes();scan=v1.declarations(root)['config.php'];parts=[]
    for name in ['bot','sendFileToTelegram','tgRetryAfter']:
        f=next(f for f in scan if f['name']==name);parts.append(src[f['offset']:f['offset']+f['length']])
    prefix=b'<?php\n'
    if mode=='old':prefix+=b'namespace AchBotTest\\Original;\nuse CURLFile;\n'
    return prefix+b'\n\n'.join(parts)

def execute(root:Path,mode:str,name:str,variant:str,base:str):
    with tempfile.TemporaryDirectory(prefix='achbot-native-http-') as tmp:
        d=Path(tmp);shutil.copytree(root/'src',d/'src');shutil.copyfile(ROOT/'tests/v2/native_bridge.php',d/'native_bridge.php')
        (d/'native-functions.php').write_bytes(functions(root,mode));(d/'native-case.json').write_text(json.dumps({'mode':mode,'function':name,'variant':variant}))
        env={'PATH':os.environ.get('PATH','/usr/bin:/bin'),'LANG':'C.UTF-8','ACHBOT_PRIVATE_TEST':'1','ACHBOT_WIRE_BASE':base}
        disabled=','.join(['exec','shell_exec','system','passthru','popen','proc_open','pcntl_exec','pcntl_fork','mail','dl','putenv'])
        cmd=['php','-n','-d','extension=curl','-d','open_basedir='+tmp,'-d','allow_url_fopen=0','-d','allow_url_include=0',
             '-d','disable_functions='+disabled,'-d','display_errors=0','-d','log_errors=0','-d','memory_limit=128M',str(d/'native_bridge.php')]
        p=subprocess.run(cmd,cwd=d,env=env,capture_output=True,timeout=15,preexec_fn=check.run.enforce_limits)
        if p.returncode or p.stdout or p.stderr:raise RuntimeError('Native cURL fixture failed: '+str(p.returncode)+' '+p.stderr.decode(errors='replace')[-1500:])
        return json.loads((d/'native-result.json').read_text())

def main():
    ap=argparse.ArgumentParser(description=__doc__);ap.add_argument('--run-loopback',action='store_true',required=True);ap.add_argument('--report',type=Path,required=True);args=ap.parse_args()
    results=[];requests=0
    with previous() as (old,lock):
        verify(ROOT,old,lock)
        # Bind only loopback, request an ephemeral port, and own/close the server in this scope.
        with ThreadingHTTPServer(('127.0.0.1',0),Fixture) as server:
            server.daemon_threads=True;server.requests=[];server.variant='ok'
            thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start();base='http://127.0.0.1:'+str(server.server_port)
            try:
                for name in ['bot','sendFileToTelegram']:
                    for variant in VARIANTS:
                        pair=[]
                        for root,mode in [(old,'old'),(ROOT,'new')]:
                            server.requests=[];server.variant=variant;record=execute(root,mode,name,variant,base)
                            if len(server.requests)!=1:raise RuntimeError('Unexpected native HTTP request count')
                            pair.append({'result':record['return'],'requests':list(server.requests)});requests+=1
                        if pair[0]!=pair[1]:raise RuntimeError('Native wire/result mismatch: '+name+':'+variant+' '+json.dumps(pair)[:3000])
                        results.append(name+':'+variant)
                php_version=record['php'];curl_version=record['curl']
            finally:server.shutdown();thread.join(timeout=5)
    report={'comparison_pairs':len(results),'real_http_requests':requests,'cases':results,'php':php_version,'curl':curl_version,
      'listener':'127.0.0.1 ephemeral owned server','native_curl_used':True,'non_loopback_url_guard':True,'real_telegram_used':False,
      'tls_or_production_dns_tested':False,'multipart_boundary_normalized':True,'production_acceptance':False}
    report.update(candidate_identity())
    args.report.write_text(json.dumps(report,indent=2)+'\n');print(json.dumps(report,sort_keys=True))
if __name__=='__main__':main()
