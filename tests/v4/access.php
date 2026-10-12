<?php
/** Isolated support trace runner; never include in a deployed runtime. */
if (PHP_SAPI !== 'cli' || getenv('ACHBOT_PRIVATE_TEST') !== '1') { http_response_code(404); exit; }
if (extension_loaded('mysqli') || extension_loaded('curl') || extension_loaded('FFI')) { throw new RuntimeException('Native I/O forbidden'); }
require __DIR__.'/tests/support/doubles.php';
date_default_timezone_set('UTC');
$ach_case=json_decode(file_get_contents(__DIR__.'/case.json'),true,512,JSON_THROW_ON_ERROR);
$ach_scope=json_decode(file_get_contents(__DIR__.'/scope.json'),true,512,JSON_THROW_ON_ERROR);
$ach_warnings=[]; $ach_error=null; $ach_complete=false; $ach_persisted=[];
$connection=new mysqli();
$from_id=$ach_case['actor']??1001; $ach_actor_alias=&$from_id;
$admin=9001; $ach_admin_alias=&$admin;
$admins_list=[9001,9002]; $username='fixture_user'; $first_name='Fixture';
$time=1800000000; $chat_id=$from_id; $message_id=71; $callbackId='fixture-callback';
$data=$ach_case['data']??'';
$text=array_key_exists('text',$ach_case)?$ach_case['text']:'fixture message';
$caption=$ach_case['caption']??'fixture photo caption'; $fileid='fixture-photo';
$userInfo=['userid'=>$from_id,'isAdmin'=>$ach_case['is_admin']??0,'is_agent'=>$ach_case['is_agent']??0,
           'step'=>$ach_case['step']??'none','temp'=>$ach_case['temp']??'fixture title', 'first_start'=>'sent'];
$botState=['supportID'=>'@fixture_support','lockChannel'=>'@fixture_channel','rewardChannel'=>'@fixture_report','agencyPlanDiscount'=>'off'];
$update=(object)['message'=>(object)[]];
if (!empty($ach_case['photo'])) $update->message->photo=[(object)['file_id'=>$fileid]];
if ($text!==null) $update->message->text=$text;
require __DIR__.'/settings/values.php';
if ($text==='__CANCEL__') $text=$buttonValues['cancel'];
$cancelKey=json_encode(['keyboard'=>[[$buttonValues['cancel']]],'resize_keyboard'=>true]);
$removeKeyboard=json_encode(['remove_keyboard'=>true]);
foreach($ach_case['reads']??[] as $ach_read) achRead($ach_read['pattern'],$ach_read['rows'],$ach_read['extra']??[]);
if(isset($ach_case['write_failure'])) $GLOBALS['ach_write_failure']=$ach_case['write_failure'];

function achSupportCall($name,$args) {
    achEvent('support.call',['function'=>$name,'args'=>$args,'actor'=>$GLOBALS['from_id'],'admin'=>$GLOBALS['admin']]);
    if (($GLOBALS['ach_case']['throw_notification']??'')===$name) throw new RuntimeException('fixture notification failure');
    return (object)['ok'=>true,'result'=>(object)['message_id'=>501]];
}
function editText(...$args) { return achSupportCall(__FUNCTION__,$args); }
function editKeys(...$args) { return achSupportCall(__FUNCTION__,$args); }
function delMessage(...$args) { return achSupportCall(__FUNCTION__,$args); }
function sendMessage(...$args) { return achSupportCall(__FUNCTION__,$args); }
function sendPhoto(...$args) { return achSupportCall(__FUNCTION__,$args); }
function alert(...$args) { return achSupportCall(__FUNCTION__,$args); }
function sendToAllAdmins(...$args) { return achSupportCall(__FUNCTION__,$args); }
function getMainKeys() { achEvent('support.menu'); return '{"inline_keyboard":[[{"text":"fixture","callback_data":"mainMenu"}]]}'; }
function setUser($value='none',$field='step') {
    achEvent('support.state',['field'=>$field,'value'=>$value,'actor'=>$GLOBALS['from_id']]);
    $GLOBALS['ach_persisted'][$field]=$value;
    // Legacy setUser writes storage but does not refresh the request's userInfo snapshot.
}
function bot($method,$args=[]) {
    if ($method!=='getChat') achFail('Unexpected support HTTP operation');
    achEvent('support.getChat',['args'=>$args]);
    return (object)['ok'=>true,'result'=>(object)['first_name'=>'Fixture Owner','last_name'=>'Other','username'=>'fixture_owner']];
}

function achAccessKeys($function, $args) {
    achEvent('access.keys', ['function'=>$function,'args'=>$args]);
    return ($GLOBALS['ach_case']['keys_null']??false) ? null : '{"inline_keyboard":[[{"text":"fixture","callback_data":"mainMenu"}]]}';
}
function getAdminsKeys(...$args) { return achAccessKeys(__FUNCTION__,$args); }
function getAgentsList(...$args) { return achAccessKeys(__FUNCTION__,$args); }
function getAgentDetails(...$args) { return achAccessKeys(__FUNCTION__,$args); }
function getAgentDiscounts(...$args) { return achAccessKeys(__FUNCTION__,$args); }
function getRejectedAgentList(...$args) { return achAccessKeys(__FUNCTION__,$args); }
function getAgentKeys(...$args) { return achAccessKeys(__FUNCTION__,$args); }
function getAdminKeys(...$args) { return achAccessKeys(__FUNCTION__,$args); }

set_error_handler(function($severity,$message){
    if (error_reporting() & $severity) $GLOBALS['ach_warnings'][]=[$severity,$message];
    return true;
});
error_reporting(E_ALL);
ob_start();
register_shutdown_function(function(){
    $ach_output=ob_get_contents();if(ob_get_level())ob_end_clean();
    if($GLOBALS['ach_reads']) $GLOBALS['ach_errors'][]='Unconsumed scripted reads';
    $ach_values=[];
    foreach($GLOBALS['ach_scope'] as $ach_name) {
        $ach_values[$ach_name]=array_key_exists($ach_name,$GLOBALS)
          ? ['type'=>get_debug_type($GLOBALS[$ach_name]),'serialized'=>base64_encode(serialize($GLOBALS[$ach_name]))]
          : ['undefined'=>true];
    }
    $ach_record=['complete'=>$GLOBALS['ach_complete'],'error'=>$GLOBALS['ach_error'],
        'warnings'=>$GLOBALS['ach_warnings'],'events'=>$GLOBALS['ach_trace'],'scope'=>$ach_values,
        'actor_alias'=>$GLOBALS['ach_actor_alias'],'admin_alias'=>$GLOBALS['ach_admin_alias'],
        'persisted'=>$GLOBALS['ach_persisted'],'stdout'=>$ach_output,'harness_errors'=>$GLOBALS['ach_errors']];
    file_put_contents(__DIR__.'/access-result.json',json_encode($ach_record,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
});
try {
    for($ach_pass=0;$ach_pass<($ach_case['repetitions']??1);$ach_pass++) require __DIR__.'/access-sequence.php';
    $ach_complete=true;
} catch(Throwable $ach_exception) {
    $ach_error=[get_class($ach_exception),$ach_exception->getCode(),$ach_exception->getMessage()];
}
