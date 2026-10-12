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
