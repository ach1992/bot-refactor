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
    return {'complete': True,'cases':5,'actual_config_section':True,
            'cli_no_telegram_or_php_warnings':True,'real_verified_webhook_membership':True,
            'malformed_membership_no_warning':True,'unknown_actor_fails_closed':True,
            'external_network_calls':0,'live_database_mutations':0}


if __name__ == '__main__':
    print(json.dumps(run()))
