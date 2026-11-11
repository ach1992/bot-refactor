#!/usr/bin/env python3
"""Actual Marzban auth/list/user/CREATE guards with synthetic credentials and no network."""
import json
import subprocess
import tempfile
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
FIXTURE=r'''<?php
namespace AchBot\Panel\Accounts;
error_reporting(E_ALL);
class FakeResult {
    function __construct(private string $sql) {}
    function fetch_assoc() {
        if (str_contains($this->sql,'server_plans'))
            return ['custom_sni'=>'{"inbounds":[]}'];
        return ['id'=>91,'type'=>'marzban','panel_url'=>'https://fixture.invalid',
                'username'=>'fixture-admin','password'=>'fixture-password'];
    }
}
class FakeStmt {
    function __construct(private string $sql) {}
    function bind_param(...$params) {}
    function execute() {return true;}
    function get_result() {return new FakeResult($this->sql);}
    function close() {}
}
class FakeDb {
    function prepare($sql) {return new FakeStmt($sql);}
}
$connection=new FakeDb();
foreach (['CURLOPT_URL','CURLOPT_FOLLOWLOCATION','CURLOPT_RETURNTRANSFER',
          'CURLOPT_POST','CURLOPT_CONNECTTIMEOUT','CURLOPT_TIMEOUT',
          'CURLOPT_SSL_VERIFYPEER','CURLOPT_SSL_VERIFYHOST','CURLOPT_POSTFIELDS',
          'CURLOPT_HTTPHEADER','CURLOPT_HTTPGET','CURLINFO_HTTP_CODE'] as $i=>$name)
    define($name,$i+1);
$GLOBALS['auth_calls']=0;
$GLOBALS['downstream_calls']=0;
$GLOBALS['bad_token']=match($argv[1]) {
    'whitespace'=>'   ',
    'crlf'=>"abc\r\nInjected",
    'inline'=>'abc def',
    'tab'=>"abc\tdef",
    'control'=>"abc\x00def",
    default=>throw new \RuntimeException('unknown_test_token_mode')
};
function curl_init() {return (object)['url'=>''];}
function curl_setopt($handle,$option,$value) {
    if ($option===CURLOPT_URL)$handle->url=$value;
    return true;
}
function curl_exec($handle) {
    if (str_ends_with($handle->url,'/api/admin/token')) {
        ++$GLOBALS['auth_calls'];
        return json_encode(['access_token'=>$GLOBALS['bad_token']],JSON_THROW_ON_ERROR);
    }
    ++$GLOBALS['downstream_calls'];
    throw new \RuntimeException('malformed_bearer_reached_downstream_http');
}
function curl_error($handle) {return '';}
function curl_errno($handle) {return 0;}
function curl_getinfo($handle,$option) {return 200;}
require $argv[2];
$bad=(object)['access_token'=>$GLOBALS['bad_token']];
$target=['panel_url'=>'https://fixture.invalid',
         'username'=>'fixture-admin','password'=>'fixture-password'];
$results=[
    'list_auth'=>getMarzbanJson(91)->msg??null,
    'list_supplied'=>getMarzbanJson(91,$bad)->msg??null,
    'user_auth'=>getMarzbanUser(91,'test-account')->msg??null,
    'user_supplied'=>getMarzbanUser(91,'test-account',$bad)->msg??null,
    'hosts'=>getMarzbanHosts(91)->msg??null,
    'user_info'=>getMarzbanUserInfo(91,'test-account')->msg??null,
    'create'=>addMarzbanUser(91,'test-account',1,30,7)->msg??null,
    'create_pinned'=>addMarzbanUser(91,'test-account',1,30,7,
                      'achbot:v1:'.str_repeat('a',32),2000000000,$target)->msg??null,
];
echo json_encode(['results'=>$results,
                  'auth_calls'=>$GLOBALS['auth_calls'],
                  'downstream_calls'=>$GLOBALS['downstream_calls']],JSON_THROW_ON_ERROR);
'''
EXPECTED={
    'list_auth':'marzban_list_auth_unverified',
    'list_supplied':'marzban_list_auth_unverified',
    'user_auth':'marzban_auth_invalid',
    'user_supplied':'marzban_auth_unverified',
    'hosts':'marzban_auth_invalid',
    'user_info':'marzban_auth_invalid',
    'create':'marzban_auth_invalid',
    'create_pinned':'effect_auth_unconfirmed',
}
def run():
    with tempfile.TemporaryDirectory(prefix='achbot-native-marzb-token-') as directory:
        f=Path(directory)/'fixture.php'
        f.write_text(FIXTURE)
        for mode in ('whitespace','crlf','inline','tab','control'):
            result=subprocess.run(['php','-n','-d','display_errors=stderr',str(f),
                                   mode,str(ROOT/'src/Panel/Accounts.php')],
                                   capture_output=True,text=True,timeout=8)
            assert result.returncode==0,(mode,result.stdout,result.stderr)
            received=json.loads(result.stdout)
            assert received=={'results':EXPECTED,'auth_calls':6,
                              'downstream_calls':0},(mode,received)
            assert result.stderr.count('reason=invalid_token http=200 curl_errno=0')==1,(mode,result.stderr)
            assert result.stderr.count('stage=get_user')==1,(mode,result.stderr)
            assert 'PHP Warning:' not in result.stderr and 'PHP Fatal error:' not in result.stderr
    return {'complete':True,'cases':5,'http_outbound':0,
            'supplied_token_get_list_denied':True,'invalid_provider_create_denied':True,
            'pinned_effect_denied':True}

if __name__=='__main__':
    print(json.dumps(run()))
