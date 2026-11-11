#!/usr/bin/env python3
"""Execute actual shared config actor/membership section as fake CLI and loopback webhook.

Only disposable local PHP, synthetic settings/users, no database or Telegram I/O.
"""
from __future__ import annotations

import http.client
import json
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time

ROOT = Path(__file__).resolve().parents[1]
START = '// bot.php has already verified the Telegram webhook-secret header.'
END = '// Channel exemption is dispatched by bot.php AFTER webhook/IP verification.'

DRIVER = r'''<?php
error_reporting(E_ALL);
ini_set('display_errors','stderr');
class FixtureResult {
    function __construct(public array $row) {}
    function fetch_assoc() {return $this->row;}
}
class FixtureStmt {
    function __construct(public string $sql) {}
    function bind_param(...$args) {}
    function execute() {}
    function close() {}
    function get_result() {
        if (str_contains($this->sql, 'FROM `users`')) return new FixtureResult(['step'=>'none']);
        if (str_contains($this->sql, "'PAYMENT_KEYS'")) return new FixtureResult(['value'=>'{}']);
        if (str_contains($this->sql, "'BOT_STATES'")) return new FixtureResult(['value'=>'{"supportID":0,"lockChannel":"-1001"}']);
        throw new RuntimeException('unrecognized_owned_query');
    }
}
class FixtureDb {function prepare($sql) {return new FixtureStmt($sql);}}
$connection = new FixtureDb();
$GLOBALS['member_calls'] = [];
function check() {return true;}
function achbotAdminRequestAllowed($data,$text,$step) {return true;}
function bot($method,$datas) {
    $GLOBALS['member_calls'][] = ['method'=>$method,'user_id'=>$datas['user_id']??null];
    if (isset($_GET['failed'])) return (object)['ok'=>false];
    return (object)['ok'=>true,'result'=>(object)['status'=>'member']];
}
if (isset($_GET['verified'])) define('ACHBOT_VERIFIED_WEBHOOK_ENTRYPOINT',true);
require __DIR__.'/config-actor.php';
echo json_encode(['calls'=>$GLOBALS['member_calls'], 'from_id'=>$from_id,
    'data'=>$data, 'join'=>$joniedState], JSON_THROW_ON_ERROR);
'''


def native_marzban_invalid_auth_guard() -> None:
    # Execute the actual current function with an absent token, without a real
    # DB, cURL extension or network. A bad Bearer request would fail loudly.
    source = (ROOT / 'src/Panel/Accounts.php').read_text()
    start = source.index('function getMarzbanUser(')
    end = source.index('function getMarzbanHosts(', start)
    function_source = source[start:end]
    fixture = r'''<?php
namespace AchBot\Panel\Accounts;
error_reporting(E_ALL);
class OwnedResult {
    function fetch_assoc() {return ['type'=>'marzban','panel_url'=>'http://127.0.0.1:9'];}
}
class OwnedStmt {
    function bind_param(...$arguments) {}
    function execute() {}
    function get_result() {return new OwnedResult();}
    function close() {}
}
class OwnedDb {function prepare($sql) {return new OwnedStmt();}}
$connection=new OwnedDb();
function getMarzbanToken(...$arguments) {return null;}
'''
    fixture += function_source
    fixture += r'''
foreach ([false,true] as $strict) {
    $result=getMarzbanUser(91,'fixture-user',null,$strict);
    $results[]=['success'=>$result->success??null,'uncertain'=>$result->uncertain??null,
        'msg'=>$result->msg??null];
}
echo json_encode($results,JSON_THROW_ON_ERROR);
'''
    with tempfile.TemporaryDirectory(prefix='achbot-cron-panel-auth-') as directory:
        path = Path(directory) / 'owned-panel.php'
        path.write_text(fixture)
        r = subprocess.run(['php','-n','-d','display_errors=stderr',str(path)],
                           capture_output=True,text=True,timeout=7)
    assert r.returncode == 0, (r.stdout,r.stderr)
    assert json.loads(r.stdout) == [
        {'success':False,'uncertain':False,'msg':'marzban_auth_unverified'},
        {'success':False,'uncertain':True,'msg':'marzban_auth_unverified'}
    ], r.stdout
    assert r.stderr.strip()=='achbot_marzban_auth_unverified server_id=91 stage=get_user',r.stderr


def native_marzban_login_subscription_guard() -> None:
    """Actual auth and subscription functions with fake local cURL/DB only."""
    source=(ROOT/'src/Panel/Accounts.php').read_text()
    auth=source[source.index('function getMarzbanToken('):
                source.index('function getMarzbanJson(')]
    info=source[source.index('function getMarzbanUserInfo('):
                source.index('function getMarzbanUser(',source.index('function getMarzbanUserInfo('))]
    prefix=r'''<?php
namespace AchBot\Panel\Accounts;
error_reporting(E_ALL);
foreach (['CURLOPT_URL'=>1,'CURLOPT_FOLLOWLOCATION'=>2,'CURLOPT_RETURNTRANSFER'=>3,
          'CURLOPT_POST'=>4,'CURLOPT_CONNECTTIMEOUT'=>5,'CURLOPT_TIMEOUT'=>6,
          'CURLOPT_SSL_VERIFYPEER'=>7,'CURLOPT_SSL_VERIFYHOST'=>8,
          'CURLOPT_POSTFIELDS'=>9,'CURLOPT_HTTPHEADER'=>10,'CURLINFO_HTTP_CODE'=>11] as $name=>$id)
    define($name,$id);
class OwnedResult { function fetch_assoc() {
    return ['id'=>91,'type'=>'marzban','panel_url'=>'https://fixture.invalid',
            'username'=>'owned','password'=>'synthetic'];
}}
class OwnedStmt {
    function bind_param(...$args) {}
    function execute() {}
    function get_result() {return new OwnedResult();}
    function close() {}
}
class OwnedDb {function prepare($query) {return new OwnedStmt();}}
$connection=new OwnedDb();
$mode=$argv[1];
$GLOBALS['auth_requests']=0;
$GLOBALS['user_requests']=0;
$GLOBALS['sub_requests']=0;
function curl_init() {return (object)['url'=>''];}
function curl_setopt($handle,$key,$value) {
    if ($key===CURLOPT_URL)$handle->url=$value;
    return true;
}
function curl_exec($handle) {
    global $mode;
    if (str_contains($handle->url,'/api/admin/token')) {
        ++$GLOBALS['auth_requests'];
        return match($mode) {
            'auth_http_401','info_auth_bad','auth_effect_401'=>'{"detail":"unauthorized"}',
            'auth_http_503'=>'{"detail":"unavailable"}',
            'auth_bad_json'=>'invalid-json',
            'auth_transport'=>false,
            default=>'{"access_token":"synthetic-token"}',
        };
    }
    if (str_contains($handle->url,'/sub/')) {
        ++$GLOBALS['sub_requests'];
        return $mode==='info_sub_retry' && $GLOBALS['sub_requests']<3?false:'fixture-subscription';
    }
    throw new \RuntimeException('unrecognized_fake_curl_url');
}
function curl_error($handle) {global $mode;
    return $mode==='auth_transport'?'owned-timeout':'';
}
function curl_errno($handle) {global $mode;
    return $mode==='auth_transport'?28:0;
}
function curl_getinfo($handle,$option) {global $mode;
    return match($mode) {
        'auth_http_401','info_auth_bad','auth_effect_401'=>401,
        'auth_http_503'=>503,
        'auth_transport'=>0,
        default=>200,
    };
}
function getMarzbanUser($serverId,$remark,$token) {
    global $mode;
    ++$GLOBALS['user_requests'];
    if (!is_object($token) || ($token->access_token??'')!=='synthetic-token')
        throw new \RuntimeException('invalid_token_passed_to_user_read');
    if ($mode==='info_bad_user')return (object)['success'=>false,'msg'=>'remote_get_unconfirmed'];
    if ($mode==='info_missing_sub')return (object)['username'=>'fixture','subscription_url'=>null];
    return (object)['username'=>'fixture','subscription_url'=>'https://fixture.invalid/sub/synthetic'];
}
'''
    suffix=r'''
if (str_starts_with($mode,'info_')) {
    $v=getMarzbanUserInfo(91,'owned-user');
    $result=['success'=>$v->success??null,'msg'=>$v->msg??null,
             'subscription_url'=>$v->subscription_url??null];
} else {
    $v=$mode==='auth_effect_401'
      ?getMarzbanToken(91,'https://fixture.invalid',[
          'panel_url'=>'https://fixture.invalid','username'=>'owned','password'=>'synthetic'])
      :getMarzbanToken(91);
    $result=['detail'=>$v->detail??null,
             'valid_token'=>is_string($v->access_token??null)];
}
echo json_encode(['result'=>$result,'auth_requests'=>$GLOBALS['auth_requests'],
   'user_requests'=>$GLOBALS['user_requests'],'sub_requests'=>$GLOBALS['sub_requests']],
   JSON_THROW_ON_ERROR);
'''
    cases={
      'auth_http_401':({'detail':'marzban_auth_http_unconfirmed','valid_token':False},1,0,0,
                        'achbot_marzban_auth_unverified server_id=91 reason=http_status http=401 curl_errno=0'),
      'auth_effect_401':({'detail':'effect_auth_unconfirmed','valid_token':False},1,0,0,''),
      'auth_http_503':({'detail':'marzban_auth_http_unconfirmed','valid_token':False},1,0,0,
                        'achbot_marzban_auth_unverified server_id=91 reason=http_status http=503 curl_errno=0'),
      'auth_bad_json':({'detail':'marzban_auth_invalid','valid_token':False},1,0,0,
                        'achbot_marzban_auth_unverified server_id=91 reason=invalid_token http=200 curl_errno=0'),
      'auth_transport':({'detail':'marzban_auth_transport_unavailable','valid_token':False},1,0,0,
                        'achbot_marzban_auth_unverified server_id=91 reason=transport http=0 curl_errno=28'),
      'auth_ok':({'detail':None,'valid_token':True},1,0,0,''),
      'info_auth_bad':({'success':False,'msg':'marzban_auth_http_unconfirmed','subscription_url':None},1,0,0,
                        'achbot_marzban_auth_unverified server_id=91 reason=http_status http=401 curl_errno=0'),
      'info_missing_sub':({'success':False,'msg':'marzban_subscription_url_unverified','subscription_url':None},1,1,0,''),
      'info_bad_user':({'success':False,'msg':'remote_get_unconfirmed','subscription_url':None},1,1,0,''),
      'info_sub_retry':({'success':None,'msg':None,'subscription_url':'/sub/synthetic'},1,3,3,''),
    }
    with tempfile.TemporaryDirectory(prefix='achbot-native-marzban-auth-owned-') as temp:
        path=Path(temp)/'fake-marzban.php'
        path.write_text(prefix+auth+info+suffix)
        for name,(expected,auth_calls,user_calls,sub_calls,diagnostic) in cases.items():
            r=subprocess.run(['php','-n','-d','display_errors=stderr',str(path),name],
                             capture_output=True,text=True,timeout=6)
            assert r.returncode==0,(name,r.stdout,r.stderr)
            output=json.loads(r.stdout)
            assert output=={'result':expected,'auth_requests':auth_calls,
                            'user_requests':user_calls,'sub_requests':sub_calls},(name,output)
            assert r.stderr.strip()==diagnostic,(name,r.stderr)


def run() -> dict:
    text = (ROOT / 'config.php').read_text()
    assert text.count(START) == 1 and text.count(END) == 1
    section = text[text.index(START):text.index(END)]
    with tempfile.TemporaryDirectory(prefix='achbot-cron-config-owned-') as temp:
        folder = Path(temp)
        shutil.copytree(ROOT / 'messages', folder / 'messages')
        (folder / 'src/Telegram').mkdir(parents=True)
        shutil.copyfile(ROOT / 'src/Telegram/UpdateBindings.php',
                        folder / 'src/Telegram/UpdateBindings.php')
        (folder / 'config-actor.php').write_text('<?php\n' + section)
        (folder / 'driver.php').write_text(DRIVER)
        result = subprocess.run(['php','-n','-d','display_errors=stderr','driver.php'],
                                cwd=folder, capture_output=True, text=True, timeout=8)
        assert result.returncode == 0 and not result.stderr, (result.stdout,result.stderr)
        cli = json.loads(result.stdout)
        assert cli == {'calls': [], 'from_id': 0, 'data': '', 'join': None}, cli
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        with (folder / 'php.log').open('w+') as php_log:
            server = subprocess.Popen(
                ['php','-n','-d','display_errors=stderr','-S',f'127.0.0.1:{port}',
                 '-t',str(folder)],cwd=folder,stdin=subprocess.DEVNULL,
                stdout=php_log,stderr=php_log)
            try:
                deadline = time.monotonic() + 6
                while True:
                    try:
                        with socket.create_connection(('127.0.0.1',port),timeout=.2):break
                    except OSError:
                        assert time.monotonic()<deadline,'loopback_php_server_unavailable'
                        time.sleep(.03)
                def request(path,body):
                    client = http.client.HTTPConnection('127.0.0.1',port,timeout=5)
                    try:
                        client.request('POST',path,body=json.dumps(body),
                                       headers={'Content-Type':'application/json'})
                        res=client.getresponse()
                        assert res.status==200, res.status
                        content=res.read().decode()
                        return json.loads(content) if content else None
                    finally:client.close()
                message={'message':{'from':{'id':1001,'first_name':'Owned'},
                                    'chat':{'id':1001},'message_id':55,'text':'hello'}}
                # A non-webhook Cron-like request is not an authenticated actor.
                assert request('/driver.php',message)==cli
                received=request('/driver.php?verified=1',message)
                assert received == {'calls':[{'method':'getChatMember','user_id':1001}],
                                    'from_id':1001,'data':'','join':'member'}, received
                failed=request('/driver.php?verified=1&failed=1',message)
                assert failed['join'] is None and len(failed['calls'])==1,failed
                assert request('/driver.php?verified=1',{}) is None
            finally:
                server.terminate()
                server.wait(timeout=5)
            php_log.seek(0)
            logs=php_log.read()
            for forbidden in ('PHP Warning:','PHP Deprecated:','PHP Fatal error:'):
                assert forbidden not in logs,(forbidden,logs[-700:])
    native_marzban_invalid_auth_guard()
    native_marzban_login_subscription_guard()
    return {'complete': True,'cases':17,'actual_config_section':True,
            'native_marzban_auth_root_cause_classified':True,
            'native_marzban_subscription_single_login':True,
            'native_panel_no_empty_auth_request':True,
            'native_panel_auth_diagnostic_once':True,
            'cli_no_telegram_or_php_warnings':True,'real_verified_webhook_membership':True,
            'malformed_membership_no_warning':True,'unknown_actor_fails_closed':True,
            'external_network_calls':0,'live_database_mutations':0}


if __name__ == '__main__':
    print(json.dumps(run()))
