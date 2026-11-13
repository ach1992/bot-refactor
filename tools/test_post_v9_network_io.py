#!/usr/bin/env python3
"""Offline current-runtime regression for invite-link construction and bounded webhook I/O.

Execute the real inviteFriends handler with synthetic Telegram events; no production
secrets, database, HTTP requests or Native/provider calls are available to the test.
"""
from __future__ import annotations

import json
from pathlib import Path
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[1]

PHP = r'''<?php
error_reporting(E_ALL);
set_error_handler(static function($level,$message) { throw new ErrorException($message,0,$level); });
$source=file_get_contents($argv[1].'/bot.php');
$start=strpos($source,'if($data=="inviteFriends"){');
$end=strpos($source,'if($data=="myInfo"){',$start);
if ($start===false || $end===false || $end<=$start) throw new RuntimeException('handler_missing');
$handler=substr($source,$start,$end-$start);
$spec=json_decode($argv[2],true,512,JSON_THROW_ON_ERROR);
$data='inviteFriends';
$botUsername=$spec['username']??null;
$from_id=123456;
$inviteReward=4200;
$buttonValues=['back_to_main'=>'Back'];
$mainValues=['invite_second_text'=>'Reward {INVITE_REWARD} / {INVITE_AMOUNT}'];
$events=[];
class Result {
    public function __construct(private array $row) {}
    public function fetch_assoc(): array {return $this->row;}
}
class Statement {
    public function __construct(private string $sql) {}
    public function execute(): void {}
    public function get_result(): Result {
        global $spec;
        if (str_contains($this->sql,'INVITE_BANNER_TEXT')) {
            $banner=$spec['banner']??'text';
            $value=match($banner){
                'none'=>null,
                'photo'=>json_encode(['type'=>'photo','file_id'=>'fixture-file','caption'=>'Invite LINK']),
                default=>json_encode(['type'=>'text','text'=>'Invite LINK'])
            };
            return new Result(['value'=>$value]);
        }
        if (str_contains($this->sql,'INVITE_BANNER_AMOUNT')) return new Result(['value'=>25000]);
        throw new RuntimeException('unexpected SQL query');
    }
    public function close(): void {}
}
class DB {
    public function prepare(string $sql): Statement {return new Statement($sql);}
}
$connection=new DB();
function achbotMessage(string $key,array $params=[]): string {return 'fallback';}
function alert($body): void {global $events;$events[]=['name'=>'alert','body'=>$body];}
function delMessage(): void {global $events;$events[]=['name'=>'delete'];}
function sendMessage($body,$key=null,$parse=null,$to=null,$reply=null): object {
    global $events;$events[]=['name'=>'message','body'=>$body,'reply'=>$reply];
    return (object)['result'=>(object)['message_id'=>55]];
}
function sendPhoto($file,$caption=null,$key=null,$parse=null,$to=null,$reply=null): object {
    global $events;$events[]=['name'=>'photo','body'=>$caption,'reply'=>$reply];
    return (object)['result'=>(object)['message_id'=>55]];
}
eval($handler);
echo json_encode(['events'=>$events],JSON_THROW_ON_ERROR);
'''

MARZBAN_PHP = r"""<?php
namespace AchBot\Panel\Accounts;
error_reporting(E_ALL);
set_error_handler(static function($level,$message) {throw new \ErrorException($message,0,$level);});
$case=\json_decode($argv[2],true,512,JSON_THROW_ON_ERROR);
$GLOBALS['case']=$case;
$GLOBALS['options']=[];
$GLOBALS['closed']=false;
class QueryResult {
    public function fetch_assoc(): array {
        return ['panel_url'=>'http://fixture.invalid','username'=>'operator','password'=>'password','type'=>'marzban'];
    }
}
class Query {
    public function bind_param(...$args): void {}
    public function execute(): void {}
    public function get_result(): QueryResult {return new QueryResult();}
    public function close(): void {}
}
class DB {
    public function prepare($sql): Query {return new Query();}
}
$connection=new DB();
function getMarzbanToken($server_id) {return (object)['access_token'=>'fixture'];}
function getMarzbanUser($server_id,$remark,$token) {
    return (object)['inbounds'=>[], 'proxies'=>(object)[], 'expire'=>1,
        'data_limit'=>1,'note'=>'fixture','data_limit_reset_strategy'=>'no_reset','status'=>'active'];
}
function curl_init() {return (object)[];}
function curl_setopt($ch,$option,$value): bool {
    $GLOBALS['options'][$option]=$value;
    return true;
}
function curl_exec($ch) {return $GLOBALS['case']['raw'];}
function curl_errno($ch): int {return (int)$GLOBALS['case']['errno'];}
function curl_getinfo($ch,$info): int {return (int)$GLOBALS['case']['http'];}
function curl_close($ch): void {$GLOBALS['closed']=true;}
$source=\file_get_contents($argv[1].'/src/Panel/Accounts.php');
$start=\strpos($source,'function changeMarzbanState(');
if ($start===false) throw new \RuntimeException('missing toggle function');
$fn=\substr($source,$start);
eval('namespace AchBot\\Panel\\Accounts;'. $fn);
$result=changeMarzbanState(1,'fixture');
$opts=$GLOBALS['options'];
$bounded=($opts[CURLOPT_TIMEOUT]??null)===20 &&
    ($opts[CURLOPT_CONNECTTIMEOUT]??null)===5 &&
    ($opts[CURLOPT_FOLLOWLOCATION]??null)===false &&
    ($opts[CURLOPT_SSL_VERIFYPEER]??null)===true &&
    ($opts[CURLOPT_SSL_VERIFYHOST]??null)===2;
echo \json_encode(['result'=>$result,'bounded'=>$bounded,'closed'=>$GLOBALS['closed']],
    JSON_THROW_ON_ERROR);
"""

def verify(root: Path = ROOT) -> dict:
    bot = (root / "bot.php").read_text()
    assert bot.count('if($data=="inviteFriends"){') == 1
    handler = bot.split('if($data=="inviteFriends"){', 1)[1].split('if($data=="myInfo"){', 1)[0]
    assert 'inviteStartLink($botUsername??null,$from_id)' in handler
    assert 'file_get_contents(' not in handler and 'getMe' not in handler
    assert 'http://api.telegram.org/bot' not in bot

    cases = 0
    with tempfile.TemporaryDirectory(prefix="achbot-invite-offline-") as temp:
        driver = Path(temp) / "driver.php"
        (Path(temp)/"src").symlink_to(root/"src", target_is_directory=True)
        driver.write_text(PHP)
        def execute(username: str, banner: str = 'text') -> list[dict]:
            result = subprocess.run(
                ["php", "-d", "allow_url_fopen=0", "-d", "log_errors=1",
                 str(driver), str(root), json.dumps({"username": username, "banner": banner})],
                capture_output=True, text=True, timeout=10)
            if result.returncode:
                raise RuntimeError('Offline invite fixture error: '+repr(result.stderr[-2000:]))
            return json.loads(result.stdout)['events']

        events = execute("FixtureBot")
        assert [e['name'] for e in events] == ['delete','message','message'], events
        assert events[1]['body'] == 'Invite t.me/FixtureBot?start=123456'
        assert events[2]['reply'] == 55
        cases += 1

        events = execute("@FixtureBot", 'photo')
        assert [e['name'] for e in events] == ['delete','photo','message'], events
        assert events[1]['body'] == 'Invite t.me/FixtureBot?start=123456'
        cases += 1

        for name in ('', 'bad-name', '@@FixtureBot', ' ', 'x' * 33):
            events = execute(name)
            assert [e['name'] for e in events] == ['alert'], (name, events)
            cases += 1

        events = execute("FixtureBot", 'none')
        assert [e['name'] for e in events] == ['alert'], events
        cases += 1

    with tempfile.TemporaryDirectory(prefix='achbot-native-io-offline-') as temp:
        native_driver = Path(temp)/'marzban.php'
        native_driver.write_text(MARZBAN_PHP)
        for name,raw,errno,http,expected_success,expected_uncertain in [
            ('success','{"username":"fixture"}',0,200,True,False),
            ('timeout',False,28,0,False,True),
            ('empty_body','',0,200,False,True),
            ('bad_json','not-json',0,200,False,True),
            ('server_error','{"detail":"error"}',0,500,False,True),
        ]:
            response=subprocess.run(
                ['php',str(native_driver),str(root),
                 json.dumps({'raw':raw,'errno':errno,'http':http})],
                capture_output=True,text=True,timeout=10)
            if response.returncode:
                raise RuntimeError('Offline Marzban '+name+': '+repr(response.stderr[-1600:]))
            observed=json.loads(response.stdout)
            record=observed['result']
            assert observed['bounded'] and observed['closed'], (name,observed)
            assert record['success'] is expected_success, (name,observed)
            assert bool(record.get('uncertain')) is expected_uncertain, (name,observed)

    toggle = (root / 'src/Panel/Accounts.php').read_text().split('function changeMarzbanState(',1)[1]
    assert 'CURLOPT_CONNECTTIMEOUT, 5' in toggle and 'CURLOPT_TIMEOUT, 20' in toggle
    assert 'CURLOPT_FOLLOWLOCATION, false' in toggle
    assert "'uncertain'=>true" in toggle and 'curl_errno($curl)' in toggle
    assert 'curl_close($curl)' in toggle and '$http!==200' in toggle

    for path, action in (('pay/checkout.php', 'PaymentRequest'),
                         ('src/Finance/GatewayReturn.php','PaymentVerification')):
        content = (root/path).read_text()
        target=content.split('new SoapClient(',1)[1].split('->'+action+'(',1)[0]
        assert "'connection_timeout'=>5" in target
        assert "'stream_context'=>stream_context_create(['http'=>['timeout'=>15]])" in target
        assert "ini_set('default_socket_timeout','15')" in content
        assert "if ($socketTimeout!==false) ini_set('default_socket_timeout',$socketTimeout)" in content

    return {'verified': True, 'invite_handler_scenarios': cases,
            'offline_no_real_telegram': True, 'active_marzban_toggle_timeout_guard': True, 'offline_marzban_scenarios': 5,
            'both_zarinpal_soap_timeout_guards': True}

if __name__=='__main__':
    print(json.dumps(verify()))
