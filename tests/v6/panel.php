<?php
/** Synthetic V6 config facades vs pinned V5 original function bodies, no network. */
if (PHP_SAPI !== 'cli' || getenv('ACHBOT_PRIVATE_TEST') !== '1' || extension_loaded('curl') || extension_loaded('mysqli') || extension_loaded('FFI')) exit(3);
require __DIR__.'/tests/support/doubles.php';
$ach_case=json_decode(file_get_contents(__DIR__.'/case.json'),true,512,JSON_THROW_ON_ERROR);
foreach ($ach_case['reads'] ?? [] as $read) achRead($read['pattern'],$read['rows']);
foreach ($ach_case['http'] ?? [] as $http) achHttp($http['method'],$http['body'],$http['status']??200,$http['errno']??0);
$connection=new mysqli();
$botState=['subLinkState'=>'on','configLinkState'=>'on'];
$admin=9001; $from_id=1001; $chat_id=1001;
$botUrl='https://bot.fixture.invalid/';$showIndexes=[1,2];$mainValues=[];
$hideConnectLinkForMarzban=false;
$ach_complete=false;$ach_return=null;$ach_exception=null;
set_error_handler(function($level,$message){
    if (error_reporting() & $level) $GLOBALS['ach_errors'][]='PHP '.$level.': '.$message;
    return true;
});
error_reporting(E_ALL);
ob_start();
register_shutdown_function(function(){
    $out=ob_get_contents();if(ob_get_level())ob_end_clean();
    if($GLOBALS['ach_reads'])$GLOBALS['ach_errors'][]='Unconsumed reads: '.count($GLOBALS['ach_reads']);
    if($GLOBALS['ach_http'])$GLOBALS['ach_errors'][]='Unconsumed HTTP: '.count($GLOBALS['ach_http']);
    $record=['complete'=>$GLOBALS['ach_complete'],'return'=>$GLOBALS['ach_return'],
      'exception'=>$GLOBALS['ach_exception'],'events'=>$GLOBALS['ach_trace'],
      'errors'=>$GLOBALS['ach_errors'],'stdout'=>$out];
    file_put_contents(__DIR__.'/result.json',json_encode($record,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
});
require __DIR__.'/functions-under-test.php';
try {
    $function=$ach_case['function'];
    if(!function_exists($function)) throw new RuntimeException('Missing public facade '.$function);
    $ach_return=$function(...($ach_case['args']??[]));
    $ach_complete=true;
} catch (Throwable $error) {
    $ach_exception=get_class($error).': '.$error->getMessage();
}
