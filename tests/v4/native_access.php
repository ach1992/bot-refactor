<?php
/** Native support persistence probe for a parent-owned disposable socket only. */
if(PHP_SAPI!=='cli'||getenv('ACHBOT_PRIVATE_TEST')!=='1') { http_response_code(404); exit; }
if(extension_loaded('curl')||extension_loaded('FFI'))throw new RuntimeException('Unexpected I/O extension');
$ach_dir=realpath($argv[1]??'');
if(!$ach_dir||!is_file($ach_dir.'/.achbot-access-db')||trim(file_get_contents($ach_dir.'/.achbot-access-db'))!=='achbot-v4-fixture-only')throw new RuntimeException('Not an owned fixture');
$ach_spec=json_decode(file_get_contents($ach_dir.'/request.json'),true,512,JSON_THROW_ON_ERROR);
$ach_app=realpath($ach_spec['app']);
if(!$ach_app||!str_starts_with($ach_app,$ach_dir.'/')||!in_array($ach_spec['database'],['achbot_v4_old','achbot_v4_new'],true))throw new RuntimeException('Fixture path/database mismatch');
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$connection=new mysqli('localhost','root','',$ach_spec['database'],0,$ach_dir.'/mysql.sock');
$ach_server=$connection->query('SELECT @@socket AS socket, @@datadir AS datadir, @@skip_networking AS skip_networking, @@version AS version, @@sql_mode AS sql_mode')->fetch_assoc();
if($ach_server['socket']!==$ach_dir.'/mysql.sock'||realpath($ach_server['datadir'])!==$ach_dir.'/data'||!(int)$ach_server['skip_networking'])throw new RuntimeException('Native target identity mismatch');
$connection->set_charset('utf8mb4');
$ach_events=[];$ach_warnings=[];$ach_error=null;$ach_complete=false;
function time(){return $GLOBALS['ach_spec']['epoch'];}
function achNativeEvent($name,$args) {
    $GLOBALS['ach_events'][]=['function'=>$name,'args'=>$args,'actor'=>$GLOBALS['from_id'],'admin'=>$GLOBALS['admin']];
    return (object)['ok'=>true,'result'=>(object)['message_id'=>501]];
}
function sendMessage(...$args){return achNativeEvent(__FUNCTION__,$args);}
function sendPhoto(...$args){return achNativeEvent(__FUNCTION__,$args);}
function editText(...$args){return achNativeEvent(__FUNCTION__,$args);}
function editKeys(...$args){return achNativeEvent(__FUNCTION__,$args);}
function delMessage(...$args){return achNativeEvent(__FUNCTION__,$args);}
function alert(...$args){return achNativeEvent(__FUNCTION__,$args);}
function sendToAllAdmins(...$args){return achNativeEvent(__FUNCTION__,$args);}
function getMainKeys(){return '{"inline_keyboard":[[{"text":"fixture","callback_data":"mainMenu"}]]}';}
function bot($method,$args=[]){if($method!=='getChat')throw new RuntimeException('Unexpected native support provider');achNativeEvent('getChat',$args);return (object)['result'=>(object)['first_name'=>'Fixture Owner']];}

function achNativeKeys($name,$args){achNativeEvent($name,$args);return '{"inline_keyboard":[]}';}
function getAdminsKeys(...$args){return achNativeKeys(__FUNCTION__,$args);}
function getAgentsList(...$args){return achNativeKeys(__FUNCTION__,$args);}
function getAgentDetails(...$args){return achNativeKeys(__FUNCTION__,$args);}
function getAgentDiscounts(...$args){return achNativeKeys(__FUNCTION__,$args);}
function getRejectedAgentList(...$args){return achNativeKeys(__FUNCTION__,$args);}
function getAgentKeys(...$args){return achNativeKeys(__FUNCTION__,$args);}
function getAdminKeys(...$args){return achNativeKeys(__FUNCTION__,$args);}

function achNativeSchema($db) {
    $out=[];
    foreach($db->query('SHOW TABLES')->fetch_all(MYSQLI_NUM) as [$t])$out[$t]=$db->query('SHOW CREATE TABLE `'.$t.'`')->fetch_row()[1];
    ksort($out);foreach($out as &$ddl)$ddl=preg_replace('/ AUTO_INCREMENT=\d+\b/','',$ddl);
    return hash('sha256',json_encode($out));
}
$ach_before=achNativeSchema($connection);
$time=time();$from_id=$ach_spec['actor']??1001;$admin=9001;$admins_list=[9001,9002];
$ach_actor_alias=&$from_id;$ach_admin_alias=&$admin;
$first_name='Fixture';$username='fixture_user';$message_id=71;$chat_id=$from_id;$callbackId='fixture-callback';
$ach_stmt=$connection->prepare('SELECT * FROM users WHERE userid=?');$ach_stmt->bind_param('i',$from_id);$ach_stmt->execute();$userInfo=$ach_stmt->get_result()->fetch_assoc();$ach_stmt->close();
if(!$userInfo)throw new RuntimeException('Missing synthetic user');
$data=$ach_spec['data']??'';$text=array_key_exists('text',$ach_spec)?$ach_spec['text']:'fixture input';
$fileid='fixture-photo';$caption='fixture caption';$update=(object)['message'=>(object)[]];
if($ach_spec['photo']??false)$update->message->photo=[(object)['file_id'=>$fileid]];
if($text!==null)$update->message->text=$text;
$botState=['supportID'=>'@fixture_support','lockChannel'=>'@fixture_channel','rewardChannel'=>'','agencyPlanDiscount'=>'off'];
require $ach_app.'/settings/values.php';
$cancelKey=json_encode(['keyboard'=>[[$buttonValues['cancel']]],'resize_keyboard'=>true]);$removeKeyboard=json_encode(['remove_keyboard'=>true]);
// The actual unchanged legacy setUser declaration, extracted with the frozen tokenizer by the parent.
require $ach_dir.'/set-user.php';
set_error_handler(function($severity,$message){if(error_reporting()&$severity)$GLOBALS['ach_warnings'][]=[$severity,$message];return true;});
error_reporting(E_ALL);ob_start();
register_shutdown_function(function(){
    $db=$GLOBALS['connection'];$out=ob_get_contents();if(ob_get_level())ob_end_clean();
    $rows=[];foreach(['users','chats','chats_info','setting'] as $table)$rows[$table]=$db->query('SELECT * FROM `'.$table.'` ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    $record=['complete'=>$GLOBALS['ach_complete'],'error'=>$GLOBALS['ach_error'],'warnings'=>$GLOBALS['ach_warnings'],'events'=>$GLOBALS['ach_events'],'stdout'=>$out,
        'rows'=>$rows,'actor_alias'=>$GLOBALS['ach_actor_alias'],'admin_alias'=>$GLOBALS['ach_admin_alias'],
        'schema_before'=>$GLOBALS['ach_before'],'schema_after'=>achNativeSchema($db),
        'engine'=>$GLOBALS['ach_server']['version'],'sql_mode'=>$GLOBALS['ach_server']['sql_mode']];
    file_put_contents($GLOBALS['ach_dir'].'/response.json',json_encode($record,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
});
try {require $ach_app.'/access-sequence.php';$ach_complete=true;}
catch(Throwable $ach_e){$ach_error=[get_class($ach_e),$ach_e->getCode(),$ach_e->getMessage()];}
