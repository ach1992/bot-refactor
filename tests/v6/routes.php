<?php
/** Fail-closed private V5 admin broadcast-route differential harness. */
if (PHP_SAPI!=='cli' || getenv('ACHBOT_PRIVATE_TEST')!=='1' || extension_loaded('mysqli') || extension_loaded('curl') || extension_loaded('FFI')) exit(3);
require __DIR__.'/tests/support/doubles.php';
if(!function_exists('mb_strlen')) { function mb_strlen($v,$encoding=null) { return strlen($v); } }
if(!function_exists('mb_substr')) { function mb_substr($v,$start,$length=null,$encoding=null) { return substr($v,$start,$length); } }
$ach_case=json_decode(file_get_contents(__DIR__.'/case.json'),true,512,JSON_THROW_ON_ERROR);
foreach($ach_case['reads']??[] as $read) achRead($read['pattern'],$read['rows'],$read['extra']??[]);
$ach_errors=[];$ach_complete=false;
$connection=new mysqli();
$admin=9001;
$from_id=$ach_case['actor']??9001;
$ach_user_alias=&$from_id;
$chat_id=$from_id;$message_id=71;
$data=$ach_case['data']??'';
$text=$ach_case['text']??'fixture title';
$caption=$ach_case['caption']??'fixture caption';
$fileid=$ach_case['fileid']??null;
$filetype=$ach_case['filetype']??'photo';
$userInfo=['isAdmin'=>$ach_case['is_admin']??0,'userid'=>$from_id,'step'=>$ach_case['step']??'none'];
$buttonValues=['cancel'=>'Cancel','start_bot'=>'Start bot','back_to_main'=>'Home'];
$mainValues=['user_keys'=>'fixture keys'];
$removeKeyboard='{"remove_keyboard":true}';$cancelKey='{"keyboard":[["Cancel"]]}';
$update=(object)['message'=>(object)['text'=>$text]];
if(isset($ach_case['forward'])) $update->message->forward_origin=(object)['type'=>'user'];
if(isset($ach_case['data'])) $update->callback_query=(object)['id'=>'fixture-callback'];
$match=[];$m=[];
function achCall($name,$args=[]) {
    achEvent('api.call',['method'=>$name,'args'=>$args]);
    return (object)['ok'=>true,'result'=>(object)['message_id'=>501]];
}
function bot($method,$data=[]) {return achCall('bot:'.$method,[$data]);}
function sendMessage(...$args) {return achCall(__FUNCTION__,$args);}
function editText(...$args) {return achCall(__FUNCTION__,$args);}
function delMessage(...$args) {return achCall(__FUNCTION__,$args);}
function editKeys(...$args) {return achCall(__FUNCTION__,$args);}
function alert(...$args) {return achCall(__FUNCTION__,$args);}
function setUser($step='none') {achEvent('state.user',['step'=>$step,'actor'=>$GLOBALS['from_id']]);}
function getMainKeys() {achEvent('ui.menu');return 'fixture-menu';}
function getUsersCountByGroup($connection,$group) {achEvent('helper.count',['group'=>$group]);return 7;}
function groupFa($group) {achEvent('helper.group',['group'=>$group]);return 'Group '.$group;}
function stateFa($state) {achEvent('helper.state',['state'=>$state]);return 'State '.$state;}
function isForwardedMessageObj($message) {achEvent('helper.forward');return isset($message->forward_origin);}
// Account/subscription-specific global caller scope (synthetic only).
$userInfo['temp']=$ach_case['temp']??'';
$userInfo['is_agent']=$ach_case['is_agent']??0;
$userInfo['subscriptionMessages']=$ach_case['subscriptionMessages']??['Fixture account message'];
$botState=['searchState'=>'on','configLinkState'=>'on','subLinkState'=>'on','changeProtocolState'=>'on','updateConfigLinkState'=>'on','updateConnectionState'=>'robot'];
$mainValues += [ 'send_config_uuid'=>'Send config or subscription URL','send_config_remark'=>'Send account remark', 'select_one_to_show_detail'=>'Select account','no_order_found'=>'Not found',
   'you_dont_have_config'=>'No accounts','reached_main_menu'=>'Home','send_only_number'=>'Number only',
   'please_wait_message'=>'Please wait','config_not_found'=>'Config missing'];
$buttonValues += ['search_agent_config'=>'Search agent','search_my_config'=>'Search mine',
   'my_subscriptions'=>'Subscriptions','back_button'=>'Back','cancel'=>'Cancel'];
$botUrl='https://bot.fixture.invalid/';$botUsername='FixtureBot';
$admin=9001;$special_admins=[9001];$rewardChannel='';$callback_query=['message'=>['chat'=>['id'=>$from_id]]];
$hideConnectLinkForMarzban=false;$permissions=['servers'=>true];
function getUserInfoKeys($id) { achEvent('helper.getUserInfo',['id'=>$id]);return ['step'=>$GLOBALS['ach_case']['step']??'none','isAdmin'=>$GLOBALS['ach_case']['is_admin']??0,'userid'=>$id,'subscriptionMessages'=>$GLOBALS['ach_case']['subscriptionMessages']??['Fixture message']];}
function getUserOrderDetailKeys($id,$offset=0) {achEvent('helper.detail.user',['id'=>$id,'offset'=>$offset]);return ['msg'=>'User detail','keyboard'=>'fixture-keyboard'];}
function getOrderDetailKeys($actor,$id,$offset=0) {achEvent('helper.detail.order',['actor'=>$actor,'id'=>$id,'offset'=>$offset]);return ['msg'=>'Order detail','keyboard'=>'fixture-keyboard'];}
function getAgentDetails($id) {achEvent('helper.agent',['id'=>$id]);return [];}
function buildConfigLinks($a,$b,$c) {return 'fixture-links';}
function getConnectionLink(...$args) {achEvent('helper.connectionLink',['args'=>$args]);return ['vless://fixture'];}
function getJson(...$args) {achEvent('helper.panelJson',['args'=>$args]);return (object)['obj'=>[]];}
function editInbound(...$args) {achEvent('helper.editInbound',['args'=>$args]);return (object)['success'=>true];}
function updateConfig(...$args) {achEvent('helper.updateConfig',['args'=>$args]);return (object)['success'=>true];}
function changeMarzbanState(...$args) {achEvent('helper.changeMarzbanState',['args'=>$args]);return (object)['success'=>true];}
function changeClientState(...$args) {achEvent('helper.changeClientState',['args'=>$args]);return (object)['success'=>true];}
function changeInboundState(...$args) {achEvent('helper.changeInboundState',['args'=>$args]);return (object)['success'=>true];}
function renewMarzbanUUID(...$args) {achEvent('helper.renewMarzbanUUID',['args'=>$args]);return (object)['success'=>true];}
function renewInboundUuid(...$args) {achEvent('helper.renewInboundUuid',['args'=>$args]);return (object)['success'=>true,'uuid'=>'fixture-next'];}
function renewClientUuid(...$args) {achEvent('helper.renewClientUuid',['args'=>$args]);return (object)['success'=>true,'uuid'=>'fixture-next'];}
function getMarzbanUser(...$args) {achEvent('helper.marzbanUser',['args'=>$args]);return (object)['links'=>['vless://fixture'],'status'=>'active'];}
function getMarzbanUserInfo(...$args) {achEvent('helper.marzbanInfo',['args'=>$args]);return (object)['links'=>['vless://fixture']];}
function RandomString($length=9) {return 'fixture-random';}
function getServerConfigKeys(...$args) {achEvent('helper.server.keys',['args'=>$args]);return 'server-keys';}
function deleteMarzban(...$args) {achEvent('helper.deleteMarzban',['args'=>$args]);return (object)['success'=>true];}
function deleteClient(...$args) {achEvent('helper.deleteClient',['args'=>$args]);return ['total'=>1073741824,'up'=>0,'down'=>0,'expiryTime'=>1800000000000];}
function deleteInbound(...$args) {achEvent('helper.deleteInbound',['args'=>$args]);return ['total'=>1073741824,'up'=>0,'down'=>0,'expiryTime'=>1800000000000];}
function sumerize($amount) {achEvent('helper.sumerize',['amount'=>$amount]);return '1 GB';}
function sendToAllAdmins(...$args) {achEvent('helper.sendToAdmins',['args'=>$args]);}

set_error_handler(function($severity,$message){
    if(error_reporting()&$severity) $GLOBALS['ach_errors'][]='PHP '.$severity.': '.$message;
    return true;
});
error_reporting(E_ALL);
ob_start();
register_shutdown_function(function(){
    $output=ob_get_contents();if(ob_get_level())ob_end_clean();
    if($GLOBALS['ach_reads'])$GLOBALS['ach_errors'][]='Unconsumed reads: '.count($GLOBALS['ach_reads']);
    $scope=[];
    foreach(['data','text','caption','from_id','admin','m','match','group','id','bid','jid','action','offset','sendId','jobId','page','title','input','parseMode','userInfo'] as $name) {
        $scope[$name]=array_key_exists($name,$GLOBALS)?
            base64_encode(serialize($GLOBALS[$name])):null;
    }
    $record=['complete'=>$GLOBALS['ach_complete'],'events'=>$GLOBALS['ach_trace'],
             'errors'=>$GLOBALS['ach_errors'],'stdout'=>$output,'scope'=>$scope,
             'actor_alias'=>$GLOBALS['ach_user_alias']];
    file_put_contents(__DIR__.'/result.json',json_encode($record,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
});
try {
    require __DIR__.'/access-sequence.php';
    $ach_complete=true;
} catch (Throwable $e) {
    $GLOBALS['ach_errors'][]=get_class($e).': '.$e->getMessage();
}
