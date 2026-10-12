<?php
/** Real owned 23-table MariaDB, original V5 / V6 account Telegram route chunks. */
if (PHP_SAPI !== 'cli' || getenv('ACHBOT_PRIVATE_TEST') !== '1' || getenv('ACHBOT_V6_NATIVE') !== '1'
    || !extension_loaded('mysqli') || !extension_loaded('curl') || extension_loaded('FFI')) exit(3);
$spec=json_decode(file_get_contents(__DIR__.'/case.json'),true,512,JSON_THROW_ON_ERROR);
$db=getenv('ACHBOT_DATABASE');$socket=getenv('ACHBOT_SOCKET');
if(!in_array($db,['achbot_v4_old','achbot_v4_new'],true) || !file_exists($socket)) exit(4);
$connection=new mysqli('localhost','root','',$db,0,$socket);
$connection->set_charset('utf8mb4');
$admin=9001;$from_id=$spec['actor']??1001;$chat_id=$from_id;$message_id=71;
$data=$spec['data']??'';$text=$spec['text']??'';$match=[];$m=[];
$first_name='Fixture';$username='fixture_user';$botUrl='http://127.0.0.1:1/';
$userInfo=['userid'=>$from_id,'isAdmin'=>$spec['is_admin']??0,'is_agent'=>$spec['is_agent']??0,
           'step'=>$spec['step']??'none','temp'=>$spec['temp']??''];
$mainValues=['send_config_uuid'=>'Send config','send_config_remark'=>'Search remark',
 'please_wait_message'=>'Wait','select_one_to_show_detail'=>'Choose account','reached_main_menu'=>'Home'];
$buttonValues=['cancel'=>'Cancel','back_to_main'=>'Back','back_button'=>'Back',
 'my_subscriptions'=>'Subscriptions','search_agent_config'=>'Search agent','search_my_config'=>'Search mine'];
$botState=['searchState'=>'on','subLinkState'=>'on','configLinkState'=>'on','rewardChannel'=>'',
 'changeProtocolState'=>'on','updateConfigLinkState'=>'on'];
$hideConnectLinkForMarzban=false;$showIndexes=[1,2];
$cancelKey='fixture-cancel';$removeKeyboard='fixture-remove';$update=[];
$ach_events=[];$ach_errors=[];$ach_return=false;
function achEvent($kind,$args){$GLOBALS['ach_events'][]=['kind'=>$kind,'args'=>$args];}
function sendMessage(...$args){achEvent('Telegram.sendMessage',$args);return (object)['ok'=>true];}
function editText(...$args){achEvent('Telegram.editText',$args);return (object)['ok'=>true];}
function delMessage(...$args){achEvent('Telegram.delMessage',$args);return (object)['ok'=>true];}
function alert(...$args){achEvent('Telegram.alert',$args);return (object)['ok'=>true];}
function setUser($step='none',$field='step'){
  achEvent('setUser',[$step,$field]);
  $stmt=$GLOBALS['connection']->prepare("UPDATE users SET `$field` = ? WHERE userid = ?");
  $stmt->bind_param('si',$step,$GLOBALS['from_id']);$stmt->execute();$stmt->close();
}
function getServerConfigKeys($id){achEvent('serverKeys',[$id]);return 'synthetic-keys';}
function getUserInfoKeys(...$args){return ['step'=>'none','isAdmin'=>0,'userid'=>1001];}
function RandomString($count=9,$type='all'){return str_repeat('x',(int)$count);}
function generateUID(){return 'fixture-uid';}
function sumerize($amount){return '1 GB';}
function sendToAllAdmins(...$args){achEvent('Telegram.sendToAllAdmins',$args);}
function getMainKeys(){return 'main-keys';}
function getServerListKeys(){return 'server-keys';}
set_error_handler(function($level,$message){
  if(error_reporting()&$level)$GLOBALS['ach_errors'][]=$level.': '.$message;
  return true;
});
error_reporting(E_ALL);ob_start();
register_shutdown_function(function(){
  $last=error_get_last();
  if($last && in_array($last['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR],true))
     $GLOBALS['ach_errors'][]='FATAL: '.$last['message'];
  $out=ob_get_contents();if(ob_get_level())ob_end_clean();
  file_put_contents(__DIR__.'/result.json',json_encode(['events'=>$GLOBALS['ach_events'],
    'warnings'=>$GLOBALS['ach_errors'],'stdout'=>$out,'returned'=>$GLOBALS['ach_return']],
    JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_UNICODE));
});
require __DIR__.'/functions-under-test.php';
try{require __DIR__.'/access-sequence.php';$ach_return=true;}
catch(Throwable $e){$ach_errors[]='EXCEPTION: '.get_class($e).': '.$e->getMessage();}
