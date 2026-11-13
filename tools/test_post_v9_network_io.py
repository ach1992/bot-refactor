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
const MARZBAN_BEARER_TOKEN_PATTERN='#\A[A-Za-z0-9._~+/-]+=*\z#D';
$case=\json_decode($argv[2],true,512,JSON_THROW_ON_ERROR);
$GLOBALS['case']=$case;
$GLOBALS['handles']=[];
$GLOBALS['calls']=[];
$GLOBALS['last_method']='';
class QueryResult {
    public function fetch_assoc(): array {
        return ['panel_url'=>'https://fixture.invalid','username'=>'operator',
            'password'=>'fixture-password','type'=>'marzban'];
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
function getMarzbanToken($server_id, ?string $expectedPanelUrl=null,
                         ?array $effectTarget=null) {
    return (object)['access_token'=>'fixture'];
}
function curl_init() {
    $id=\count($GLOBALS['handles'])+1;
    $GLOBALS['handles'][$id]=[];
    return (object)['id'=>$id];
}
function curl_setopt($ch,$option,$value): bool {
    $GLOBALS['handles'][$ch->id][$option]=$value;
    return true;
}
function curl_exec($ch) {
    $opts=$GLOBALS['handles'][$ch->id];
    $url=(string)($opts[CURLOPT_URL]??'');
    $method=($opts[CURLOPT_CUSTOMREQUEST]??'')==='PUT'?'PUT':
        (\str_contains($url,'/sub/')?'SUB':'GET');
    $GLOBALS['last_method']=$method;
    $body=$opts[CURLOPT_POSTFIELDS]??'';
    $post=\json_decode((string)$body,true);
    $GLOBALS['calls'][]=[
        'method'=>$method,
        'connect'=>$opts[CURLOPT_CONNECTTIMEOUT]??null,
        'timeout'=>$opts[CURLOPT_TIMEOUT]??null,
        'redirect'=>$opts[CURLOPT_FOLLOWLOCATION]??null,
        'tls_peer'=>$opts[CURLOPT_SSL_VERIFYPEER]??null,
        'tls_host'=>$opts[CURLOPT_SSL_VERIFYHOST]??null,
        'post_status'=>$post['status']??null,
    ];
    $c=$GLOBALS['case'];
    return match($method) {
        'GET'=>$c['get_raw']??false,
        'PUT'=>$c['put_raw']??false,
        default=>$c['sub_raw']??false,
    };
}
function curl_errno($ch): int {
    $c=$GLOBALS['case'];$kind=$GLOBALS['last_method'];
    return (int)($c[\strtolower($kind).'_errno']??0);
}
function curl_getinfo($ch,$info): int {
    $c=$GLOBALS['case'];$kind=$GLOBALS['last_method'];
    return (int)($c[\strtolower($kind).'_http']??0);
}
function curl_error($ch): string {return curl_errno($ch)?'synthetic network failure':'';}
$source=\file_get_contents($argv[1].'/src/Panel/Accounts.php');
$i=\strpos($source,'function getMarzbanUserInfo(');
$g=\strpos($source,'function getMarzbanUser(');
$end=\strpos($source,'function getMarzbanHosts(');
$t=\strpos($source,'function changeMarzbanState(');
if ($i===false || $g===false || $end===false || $t===false || !($i<$g && $g<$end && $end<$t))
    throw new \RuntimeException('source_method_extraction_failed');
$functions=\substr($source,$i,$end-$i).\substr($source,$t);
eval('namespace AchBot\\Panel\\Accounts;'.$functions);
$mode=$case['mode']??'toggle';
$result=match($mode) {
    'get'=>getMarzbanUser(1,'fixture',(object)['access_token'=>'fixture']),
    'info'=>getMarzbanUserInfo(1,'fixture'),
    default=>changeMarzbanState(1,'fixture'),
};
echo \json_encode(['result'=>$result,'calls'=>$GLOBALS['calls']],
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
        native_driver=Path(temp)/'marzban.php'
        native_driver.write_text(MARZBAN_PHP)

        def native(**case):
            result=subprocess.run(
                ['php',str(native_driver),str(root),json.dumps(case)],
                capture_output=True,text=True,timeout=10)
            if result.returncode:
                raise RuntimeError('Source-real native fixture: '+repr(result.stderr[-1800:]))
            return json.loads(result.stdout)

        base={'username':'fixture','status':'active',
              'inbounds':{'vless':['fixture-inbound']},'proxies':{'vless':{}},
              'expire':0,'data_limit':0,'data_limit_reset_strategy':'no_reset',
              'note':None,'subscription_url':'https://fixture.invalid/sub/fixture'}
        def wire(**overrides):
            return json.dumps(base|overrides)

        def bounded(call):
            assert call['connect']==5 and call['timeout']==20,call
            assert call['redirect'] is False and call['tls_peer'] is True,call
            assert call['tls_host']==2,call

        # Exercise the actual non-strict account GET that was unbounded in
        # REQUIRED-01. No mocked replacement for getMarzbanUser is defined.
        for raw,http in ((wire(),200),(False,0),('{invalid',200)):
            result=native(mode='get',get_raw=raw,get_http=http)
            assert [c['method'] for c in result['calls']]==['GET'],result
            bounded(result['calls'][0])

        # Existing getMarzbanUserInfo previously repeated the account GET up to
        # eleven times. Link probes remain bounded by a single 12s retry budget.
        info=native(mode='info',get_raw=wire(),get_http=200,
                    sub_raw=False,sub_http=503,sub_errno=7)
        calls=info['calls']
        assert [c['method'] for c in calls].count('GET')==1,calls
        assert 1 <= [c['method'] for c in calls].count('SUB') <= 11,calls
        bounded(calls[0])
        assert all(c['connect']==3 and 1<=c['timeout']<=3
                   for c in calls[1:]),calls
        assert info['result']['subscription_url']=='/sub/fixture'

        native_cases=0
        def toggle(name,get_raw,get_http,put_raw,put_http,
                   success,uncertain,puts,get_errno=0,put_errno=0,
                   expected_status=None):
            nonlocal native_cases
            result=native(get_raw=get_raw,get_http=get_http,get_errno=get_errno,
                          put_raw=put_raw,put_http=put_http,put_errno=put_errno)
            calls=result['calls']
            gets=[c for c in calls if c['method']=='GET']
            put_calls=[c for c in calls if c['method']=='PUT']
            assert len(gets)==1 and len(put_calls)==puts,(name,calls)
            assert len(calls)==1+puts,(name,calls)
            bounded(gets[0])
            for call in put_calls:bounded(call)
            record=result['result']
            assert record['success'] is success,(name,record)
            assert bool(record.get('uncertain')) is uncertain,(name,record)
            if expected_status is not None:
                assert put_calls[0]['post_status']==expected_status,(name,put_calls)
            native_cases+=1

        good_ack=json.dumps({'username':'fixture','status':'disabled'})
        toggle('valid_disable',wire(),200,good_ack,200,True,False,1,
               expected_status='disabled')
        toggle('valid_enable',wire(status='disabled'),200,
               json.dumps({'username':'fixture','status':'active'}),200,
               True,False,1,expected_status='active')
        for name,raw,http,errno in [
            ('get_timeout',False,0,28),
            ('get_partial_transport_error',wire(),200,28),
            ('get_server_error',wire(),500,0),
            ('get_malformed','{invalid',200,0),
            ('get_detail',json.dumps({'detail':'not found'}),404,0),
            ('get_empty','{}',200,0),
            ('get_wrong_identity',wire(username='foreign'),200,0),
            ('get_bad_status',wire(status='expired'),200,0),
            ('get_missing_required',json.dumps({'username':'fixture','status':'active'}),200,0),
        ]:
            toggle(name,raw,http,good_ack,200,False,True,0,get_errno=errno)

        for name,raw,http,errno in [
            ('put_timeout',False,0,28),
            ('put_http_500',good_ack,500,0),
            ('put_empty_object','{}',200,0),
            ('put_wrong_identity',json.dumps({'username':'foreign','status':'disabled'}),200,0),
            ('put_wrong_status',json.dumps({'username':'fixture','status':'active'}),200,0),
            ('put_missing_status',json.dumps({'username':'fixture'}),200,0),
            ('put_detail',json.dumps({'username':'fixture','status':'disabled','detail':'error'}),200,0),
            ('put_bad_json','{invalid',200,0),
        ]:
            toggle(name,wire(),200,raw,http,False,True,1,put_errno=errno,
                   expected_status='disabled')
        # Confirm the actual customer route only displays success on a true
        # adapter result; uncertain results reach the existing review message.
        provider=(root/'src/Panel/ProviderRuntime.php').read_text()
        customer=(root/'src/Subscription/CustomerRoutes.php').read_text()
        assert "'toggle'=>changeMarzbanState($serverId,$remark)" in provider
        assert "return achbotProviderAction($serverId,$remark,'toggle');" in provider
        assert '$update_response = achbotProviderToggle($server_id, $remark);' in customer
        assert 'if($update_response->success)' in customer
        assert "sendMessage(\\achbotMessage('subscription.customer_routes.cancel_search_keyboard.message.b63b579845'));" in customer

    toggle = (root / 'src/Panel/Accounts.php').read_text().split('function changeMarzbanState(',1)[1]
    assert 'CURLOPT_CONNECTTIMEOUT, 5' in toggle and 'CURLOPT_TIMEOUT, 20' in toggle
    assert 'CURLOPT_FOLLOWLOCATION, false' in toggle
    assert "'uncertain'=>true" in toggle and 'curl_errno($curl)' in toggle
    assert 'curl_close($curl)' not in toggle and '$http!==200' in toggle

    checkout=(root/'pay/checkout.php').read_text()
    marker='/**\n * Claim one gateway per local order'
    assert checkout.count(marker)==1
    prelude,body=checkout.split(marker,1)
    assert "ini_set('default_socket_timeout','15')" in prelude
    # Keep historical issuance exactly byte-pinned. The SOAP bound is applied
    # by the request entrypoint, not by rewriting that verified controller.
    assert "'connection_timeout'=>5" not in body
    verify_source=(root/'src/Finance/GatewayReturn.php').read_text()
    target=verify_source.split('new SoapClient(',1)[1].split('->PaymentVerification(',1)[0]
    assert "'connection_timeout'=>5" in target
    assert "'stream_context'=>stream_context_create(['http'=>['timeout'=>15]])" in target
    assert "ini_set('default_socket_timeout','15')" in verify_source
    assert "if ($socketTimeout!==false) ini_set('default_socket_timeout',$socketTimeout)" in verify_source

    return {'verified': True, 'invite_handler_scenarios': cases,
            'offline_no_real_telegram': True, 'active_marzban_toggle_timeout_guard': True, 'offline_marzban_scenarios': native_cases,
            'both_zarinpal_soap_timeout_guards': True}

if __name__=='__main__':
    print(json.dumps(verify()))
