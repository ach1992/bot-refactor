<?php
/** Owned MariaDB and loopback-provider-only V6 native runtime fixture. */
if (PHP_SAPI!=='cli' || getenv('ACHBOT_PRIVATE_TEST')!=='1' || getenv('ACHBOT_V6_NATIVE')!=='1'
    || !extension_loaded('mysqli') || !extension_loaded('curl') || extension_loaded('FFI')) exit(3);
$db=getenv('ACHBOT_DATABASE');$socket=getenv('ACHBOT_SOCKET');
if(!in_array($db,['achbot_v4_old','achbot_v4_new'],true) || !file_exists($socket)) exit(4);
$spec=json_decode(file_get_contents(__DIR__.'/case.json'),true,512,JSON_THROW_ON_ERROR);
$connection=new mysqli('localhost','root','',$db,0,$socket);
if ($connection->connect_error) throw new RuntimeException('Fixture database unavailable');
$connection->set_charset('utf8mb4');
$botState=['subLinkState'=>'on','configLinkState'=>'on'];
$hideConnectLinkForMarzban=false;$showIndexes=[1,2];
$mainValues=[];$botUrl='http://127.0.0.1:1/';
// Deterministic original external utility dependencies; never call live providers.
function RandomString($count=9,$type='all'){return str_repeat('x',(int)$count);}
function generateUID(){return 'fixture-uid';}
function generateRandomString($count=16){return str_repeat('x',(int)$count);}
function sendMessage(...$args){return (object)['ok'=>true];}
$ach_result=null;$ach_exception=null;$ach_warnings=[];
set_error_handler(function($level,$msg){if(error_reporting()&$level)$GLOBALS['ach_warnings'][]=$level.': '.$msg;return true;});
error_reporting(E_ALL);
ob_start();
try {
    require __DIR__.'/functions-under-test.php';
    $name=$spec['function'];
    if(!function_exists($name))throw new RuntimeException('Unknown facade');
    $ach_result=$name(...($spec['args']??[]));
} catch (Throwable $e) {$ach_exception=get_class($e).': '.$e->getMessage();}
$out=ob_get_clean();
file_put_contents(__DIR__.'/result.json',json_encode([
    'result'=>$ach_result,'exception'=>$ach_exception,'warnings'=>$ach_warnings,'stdout'=>$out
],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE));
