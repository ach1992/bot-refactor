<?php
/** AChBot V5 real MariaDB / fake Telegram worker fixture. Never ship in runtime. */
if (PHP_SAPI !== 'cli' || getenv('ACHBOT_PRIVATE_TEST') !== '1' || getenv('ACHBOT_V5_NATIVE') !== '1'
    || !extension_loaded('mysqli') || extension_loaded('curl') || extension_loaded('FFI')) { exit(3); }
$ach_case=json_decode(file_get_contents(__DIR__.'/native-case.json'),true,512,JSON_THROW_ON_ERROR);
$ach_trace=[];
$ach_errors=[];
$ach_complete=false;
set_error_handler(function($severity,$message){
    if(error_reporting()&$severity)$GLOBALS['ach_errors'][]='PHP '.$severity.': '.$message;
    return true;
});
error_reporting(E_ALL);
function achEvent($name,$args=[]) { $GLOBALS['ach_trace'][]=['name'=>$name,'args'=>$args]; }
// Builtins disabled only in this private fixture to avoid real wait times.
function sleep($time) { achEvent('sleep', ['seconds'=>$time]); return 0; }
function usleep($time) { achEvent('usleep',['microseconds'=>$time]); }
ob_start();
register_shutdown_function(function(){
    // Worker shutdown handlers must run before this final recorder.
    register_shutdown_function(function(){
        $output=ob_get_contents();if(ob_get_level())ob_end_clean();
        if(isset($GLOBALS['connection']) && $GLOBALS['connection'] instanceof mysqli) {
            $GLOBALS['connection']->close();
        }
        $record=['complete'=>$GLOBALS['ach_complete'],'errors'=>$GLOBALS['ach_errors'],
                 'events'=>$GLOBALS['ach_trace'],'stdout'=>$output];
        file_put_contents(__DIR__.'/native-result.json',json_encode($record,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
    });
});
try {
    $which=$ach_case['worker']??'messagewizwiz';
    if(!in_array($which,['messagewizwiz','warnusers'],true)) throw new RuntimeException('Unknown worker');
    require __DIR__.'/settings/'.$which.'.php';
    $ach_complete=true;
} catch(Throwable $e) { $GLOBALS['ach_errors'][]=get_class($e).': '.$e->getMessage(); }
