#!/usr/bin/env python3
"""Actual pinned Marzban login/GET transport against disposable loopback HTTP.

No real provider endpoints, Telegram calls or financial/account writes.
"""
from __future__ import annotations

import argparse
import contextlib
import json
import subprocess
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import parse_qs

import test_access_state as owned
import test_v8_native as purchase
import test_post_v9_recovery_inspection as recovery

ROOT = Path(__file__).resolve().parents[1]
DB = recovery.DB

PHP = r"""<?php
error_reporting(E_ALL);
set_error_handler(static function($level,$message) {
    if ($level===E_DEPRECATED) return true;
    throw new ErrorException($message,0,$level);
});
$_SERVER['SCRIPT_FILENAME']=__FILE__;
$root=$argv[1];$socket=$argv[2];$database=$argv[3];
$spec=json_decode($argv[4],true,512,JSON_THROW_ON_ERROR);
if ($spec['op']==='direct') {
    require $root.'/src/Finance/RecoveryTransport.php';
    $result=achbotRecoveryStrictAccountGet(
        $spec['url'],'fixture-admin','fixture-password',$spec['username']);
    echo json_encode(['result'=>$result,'events'=>[]],JSON_THROW_ON_ERROR);
    exit;
}
$connection=new mysqli('localhost','root','',$database,0,$socket);
$connection->set_charset('utf8mb4');
if (!empty($spec['nonthrowing'])) mysqli_report(MYSQLI_REPORT_OFF);
$GLOBALS['events']=[];
$admin=9001;$from_id=(int)($spec['actor']??9001);
$chat_id=(int)($spec['chat']??9001);
$callbackId='loopback-only-callback';$message_id=456;
$data=(string)($spec['callback']??'bankReviewInbox');
function answerCallbackQuery(...$args) {
    $GLOBALS['events'][]=['event'=>'ACK','text'=>(string)($args[1]??'')];
}
function editText(...$args) {
    $GLOBALS['events'][]=['event'=>'EDIT','text'=>(string)($args[1]??'')];
    return (object)['ok'=>true];
}
function sendMessage(...$args) {
    if (($args[3]??null)!==9001) throw new RuntimeException('nonowner_send');
    $GLOBALS['events'][]=['event'=>'SEND','text'=>(string)($args[0]??'')];
    return (object)['ok'=>true];
}
function createServerAccount(...$args){throw new RuntimeException('forbidden_create');}
function addMarzbanUser(...$args){throw new RuntimeException('forbidden_create');}
function editMarzbanConfig(...$args){throw new RuntimeException('forbidden_put');}
require $root.'/src/Finance/Routes/ManualReceipts.php';
echo json_encode(['result'=>null,'events'=>$GLOBALS['events']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
"""


class FixtureHandler(BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def reply(self, code: int, data: bytes, *, redirect: bool = False):
        self.send_response(code)
        self.send_header('Content-Type', 'application/json')
        if redirect:
            self.send_header('Location', self.server.url+'/trap')
        self.send_header('Content-Length', str(len(data)))
        self.end_headers()
        try:
            self.wfile.write(data)
        except (BrokenPipeError, ConnectionResetError):
            pass

    def do_POST(self):
        length = int(self.headers.get('Content-Length', '0'))
        form = parse_qs(self.rfile.read(min(length, 8192)).decode())
        self.server.trace.append(('POST',self.path, bool(self.headers.get('Authorization'))))
        assert self.path == '/api/admin/token', self.path
        assert form.get('username') == ['fixture-admin']
        assert form.get('password') == ['fixture-password']
        if self.server.mode=='login_redirect':
            return self.reply(302,b'{}',redirect=True)
        if self.server.mode=='login_wrong_status':
            return self.reply(201,b'{"access_token":"fixture_token_123"}')
        if self.server.mode=='login_empty_token':
            return self.reply(200,b'{"access_token":""}')
        if self.server.mode=='login_bad_type':
            return self.reply(200,b'{"access_token":"fixture_token_123","token_type":"basic"}')
        if self.server.mode=='login_invalid_token':
            return self.reply(200,b'{"access_token":"bad\\r\\nInjected: value"}')
        if self.server.mode=='login_malformed':
            return self.reply(200,b'not json')
        if self.server.mode=='login_oversize':
            return self.reply(200,b'x'*9000)
        if self.server.mode=='login_timeout':
            time.sleep(9)
            return self.reply(200,b'{"access_token":"fixture_token_123"}')
        if self.server.mode in ('drift_url','drift_credentials'):
            recovery.sql(self.server.work,self.server.env,
                "UPDATE server_config SET "+(
                    "panel_url='https://different.invalid'" if self.server.mode=='drift_url'
                    else "password='changed-fixture-password'")+
                " WHERE id=2")
        return self.reply(200,b'{"access_token":"fixture_token_123","token_type":"Bearer"}')

    def do_GET(self):
        self.server.trace.append(('GET',self.path,
                                  self.headers.get('Authorization')=='Bearer fixture_token_123'))
        if self.server.mode=='get_redirect':
            return self.reply(302,b'{}',redirect=True)
        if self.server.mode=='get_wrong_status':
            return self.reply(401,b'{"detail":"denied"}')
        if self.server.mode=='get_404':
            return self.reply(404,b'{"detail":"not found"}')
        if self.server.mode=='get_invalid_json':
            return self.reply(200,b'{')
        if self.server.mode=='get_missing_fields':
            return self.reply(200,b'{"username":"partial"}')
        if self.server.mode=='get_oversize':
            return self.reply(200,b'x'*70000)
        assert self.path == '/api/user/'+self.server.username, self.path
        assert self.headers.get('Authorization') == 'Bearer fixture_token_123'
        return self.reply(200,json.dumps(self.server.account).encode())


@contextlib.contextmanager
def owned_server():
    server=ThreadingHTTPServer(('127.0.0.1',0),FixtureHandler)
    server.daemon_threads=True
    server.url=f'http://127.0.0.1:{server.server_address[1]}'
    server.trace=[];server.mode='ok';server.username='';server.account={}
    thread=threading.Thread(target=server.serve_forever,daemon=True)
    thread.start()
    try: yield server
    finally:
        server.shutdown();thread.join(timeout=5);server.server_close()


def call(work,env,driver,**kwargs):
    command=['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
             '-d','extension=curl','-d','allow_url_fopen=0',
             '-d','display_errors=0',
             '-d','disable_functions=exec,shell_exec,system,passthru,popen,proc_open,mail',
             str(driver),str(ROOT),str(work/'mysql.sock'),DB,json.dumps(kwargs)]
    run=subprocess.run(command,cwd=work,env=env,capture_output=True,text=True,timeout=24)
    if run.returncode:
        raise RuntimeError(f'Loopback PHP failed: {run.returncode} {run.stderr[-2500:]}')
    return json.loads(run.stdout)


def check():
    scenarios=[]
    with owned_server() as server, owned.owned_database() as (work,env):
        server.work=work;server.env=env
        purchase.init(work,env,server.url)
        for name in ('001_marzban_panel_group_id.sql',
                     '003_payment_attempts.sql','007_service_effects.sql'):
            recovery.sql(work,env,(ROOT/'migrations/post-v9'/name).read_text())
        pay=recovery.create_case(work,env,'loopbackrecover',count=1)
        server.username=f'fx_{pay}_1'
        server.account={
            'username':server.username,
            'note':'achbot:v1:'+recovery.sql(work,env,
                f"SELECT operation_id FROM service_effects WHERE pay_row_id={pay}"),
            'expire':int(recovery.sql(work,env,
                f"SELECT requested_expire FROM service_effects WHERE pay_row_id={pay}")),
            'data_limit':int(recovery.sql(work,env,
                f"SELECT requested_bytes FROM service_effects WHERE pay_row_id={pay}")),
            'status':'active',
        }
        driver=work/'actual-recovery-transport-driver.php';driver.write_text(PHP)

        def expect_two(result, *, matching):
            assert [r[:2] for r in server.trace]==[
                ('POST','/api/admin/token'),
                ('GET','/api/user/'+server.username)],server.trace
            assert server.trace[0][2] is False
            assert server.trace[1][2] is True
            if matching:
                assert result['result']['note']==server.account['note']
            else:
                assert result['result'] is None

        first=call(work,env,driver,op='direct',url=server.url,username=server.username)
        expect_two(first,matching=True)
        scenarios.append('pinned_same_exact_endpoint_authenticated_login_and_account_get')
        server.trace.clear()

        for mode,expected_get in (
            ('login_redirect',False),('login_wrong_status',False),
            ('login_empty_token',False),('login_bad_type',False),
            ('login_invalid_token',False),('login_malformed',False),
            ('login_oversize',False),('login_timeout',False),
            ('get_redirect',True),('get_wrong_status',True),
            ('get_404',True),('get_invalid_json',True),
            ('get_missing_fields',True),
            ('get_oversize',True),
        ):
            server.mode=mode
            result=call(work,env,driver,op='direct',
                        url=server.url,username=server.username)
            assert result['result'] is None,(mode,result)
            assert len(server.trace)==(2 if expected_get else 1),(mode,server.trace)
            assert server.trace[0][0:2]==('POST','/api/admin/token')
            if expected_get:
                assert server.trace[1][0:2]==('GET','/api/user/'+server.username)
            assert all('/trap' not in t[1] for t in server.trace)
            server.trace.clear()
            scenarios.append('actual_http_failclosed_'+mode)

        server.mode='ok'
        for invalid in (
            'http://remote.example.invalid',
            'https://user:pass@example.invalid',
            'https://example.invalid/?u=1',
            'file:///etc/passwd',
            'https://example.invalid/#frag',
        ):
            value=call(work,env,driver,op='direct',
                       url=invalid,username=server.username)
            assert value['result'] is None and not server.trace,invalid
            scenarios.append('strict_invalid_endpoint_no_login_or_get')

        baseline=recovery.unchanged(work,env,pay)
        route=call(work,env,driver,op='route',callback=f'bankReviewRead_{pay}_1_0')
        assert [e['event'] for e in route['events']]==['ACK','EDIT'],route
        assert 'شواهد لحظه‌ای حساب' in route['events'][-1]['text']
        assert len(server.trace)==2 and server.trace[-1][2] is True
        assert recovery.unchanged(work,env,pay)==baseline
        scenarios.append('real_operator_route_uses_exact_pinned_curl_readback_no_writes')
        server.trace.clear()

        nonowner=call(work,env,driver,op='route',
                      callback=f'bankReviewRead_{pay}_1_0',actor=9002)
        assert not server.trace and [e['event'] for e in nonowner['events']]==['ACK']
        scenarios.append('unauthorized_callback_never_contacts_login_or_account')

        for mode,restore in (
            ('drift_url',f"UPDATE server_config SET panel_url='{server.url}' WHERE id=2"),
            ('drift_credentials',"UPDATE server_config SET password='fixture-password' WHERE id=2"),
        ):
            server.mode=mode;server.trace.clear()
            result=call(work,env,driver,op='route',
                        callback=f'bankReviewRead_{pay}_1_0')
            assert [r[:2] for r in server.trace]==[
                ('POST','/api/admin/token'),
                ('GET','/api/user/'+server.username)],(mode,server.trace)
            assert len([e for e in result['events'] if e['event']=='ACK'])==1
            assert 'نتیجه غیرقطعی' in result['events'][-1]['text']
            recovery.sql(work,env,restore)
            scenarios.append('mutation_during_login_does_not_retarget_'+mode)

        # Repeat the same actual route under failures, never claiming
        # remote nonexecution from a 404 or a missing token.
        for mode in ('get_404','login_redirect'):
            server.mode=mode;server.trace.clear()
            result=call(work,env,driver,op='route',
                        callback=f'bankReviewRead_{pay}_1_0')
            assert len([e for e in result['events'] if e['event']=='ACK'])==1
            assert 'استعلام قابل اتکا دریافت نشد' in result['events'][-1]['text']
            scenarios.append('operator_route_http_failure_remains_unknown_'+mode)

        assert recovery.unchanged(work,env,pay)==baseline
    return {'complete':True,'cases':len(scenarios),'scenarios':scenarios,
            'real_curl_login_and_get':True,
            'loopback_only':True,'max_account_get_per_inspection':1,
            'redirects_followed':0,'credential_leak_to_redirect':False,
            'remote_writes':0,'money_mutations':0,'installed_operator_tested':False}


def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--run-disposable',required=True,action='store_true')
    parser.add_argument('--report',type=Path)
    opts=parser.parse_args()
    result=check()
    if opts.report:opts.report.write_text(json.dumps(result,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps(result,ensure_ascii=False))


if __name__=='__main__':
    main()
