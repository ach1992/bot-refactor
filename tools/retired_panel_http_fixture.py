"""Owned HTTP peer used to prove retired-provider rejection in current gift tests."""
import contextlib
import json
import threading
from http.server import BaseHTTPRequestHandler,ThreadingHTTPServer

def require(ok,msg):
    if not ok:raise RuntimeError(msg)

class Panel(BaseHTTPRequestHandler):
    def log_message(self,*args):pass
    def do_POST(self):self.route()
    def do_GET(self):self.route()
    def do_PUT(self):self.route()

    def route(self):
        server=self.server
        body=self.rfile.read(int(self.headers.get('Content-Length','0')))
        server.events.append({'method':self.command,'path':self.path})
        key=(self.command,self.path)
        payload={};status=200;redirect=None
        try:
            if key==('POST','/api/admin/token'):
                if b'fixture-admin' not in body or b'fixture-password' not in body:
                    status=401;payload={'detail':'bad_credentials'}
                else:
                    payload={'access_token':'owned-token'}
                    server.fire('after_login')
            elif key==('GET','/api/users'):
                payload={'users':list(server.accounts.values()),
                         'total':len(server.accounts)}
                if server.list_mode=='missing_total':
                    del payload['total']
                elif server.list_mode=='wrong_total':
                    payload['total']+=1
                elif server.list_mode=='redirect_to_other_panel':
                    status=302
                    redirect=server.other_url+'/api/users'
                server.fire('after_list')
            elif self.path.startswith('/api/user/'):
                username=self.path.split('/api/user/',1)[1]
                if username not in server.accounts:
                    status=404;payload={'detail':'unknown_user'}
                elif self.command=='GET':
                    if server.fail_readback and server.put_count>0:
                        status=503;payload={'detail':'synthetic_readback_lost'}
                    else:
                        payload=dict(server.accounts[username])
                        server.fire('after_user_get')
                elif self.command=='PUT':
                    request=json.loads(body)
                    require(set(request.keys()) <= {'data_limit','expire'} and request,
                            'Unexpected/unsafe fields in service edit PUT')
                    server.put_count+=1
                    server.accounts[username].update(request)
                    payload=dict(server.accounts[username])
                    server.fire('after_put')
                    if server.lost_put:
                        status=503;payload={'detail':'synthetic_put_response_lost'}
                else:
                    status=405;payload={'detail':'unsupported'}
            else:
                status=404;payload={'detail':'unrecognized'}
        except Exception as error:
            server.errors.append(str(error))
            status=500;payload={'detail':'fixture_error'}
        raw=json.dumps(payload,separators=(',',':')).encode()
        self.send_response(status)
        if redirect:self.send_header('Location',redirect)
        self.send_header('Content-Type','application/json')
        self.send_header('Content-Length',str(len(raw)))
        self.end_headers()
        try:self.wfile.write(raw)
        except BrokenPipeError:pass

@contextlib.contextmanager
def panels():
    a=ThreadingHTTPServer(('127.0.0.1',0),Panel)
    b=ThreadingHTTPServer(('127.0.0.1',0),Panel)
    for server in (a,b):
        server.events=[];server.errors=[];server.accounts={}
        server.put_count=0;server.hook_at=None;server.hook=None
        server.lost_put=False;server.fail_readback=False;server.list_mode='complete'
        def fire(where,sv=server):
            if sv.hook_at==where and sv.hook is not None:
                action=sv.hook
                sv.hook=None;sv.hook_at=None
                action()
        server.fire=fire
    a.other_url='http://127.0.0.1:'+str(b.server_address[1])
    b.other_url='http://127.0.0.1:'+str(a.server_address[1])
    threads=[threading.Thread(target=s.serve_forever,daemon=True) for s in (a,b)]
    for t in threads:t.start()
    try:yield a,b,*('http://127.0.0.1:'+str(s.server_address[1]) for s in (a,b))
    finally:
        for s in (a,b):s.shutdown()
        for t in threads:t.join(timeout=5)
        for s in (a,b):s.server_close()

def php_args(stage,runner):
    return ['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
            '-d','extension=curl','-d','display_errors=1',
            '-d','allow_url_fopen=0',
            '-d','disable_functions=exec,shell_exec,system,passthru,popen,proc_open,mail',
            '-d','open_basedir='+str(stage)+':'+str(stage.parent),
            str(stage/runner)]
