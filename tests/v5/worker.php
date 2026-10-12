<?php
// Internal worker replay: all DB/Telegram/panel effects are scripted doubles.
if (PHP_SAPI!=='cli' || getenv('ACHBOT_PRIVATE_TEST')!=='1' || extension_loaded('mysqli') || extension_loaded('curl') || extension_loaded('FFI')) exit(3);
require __DIR__.'/tests/support/doubles.php';
if (!function_exists('mb_substr')) { function mb_substr($text,$start,$length=null,$encoding=null) { return substr($text,$start,$length); } }
$ach_case=json_decode(file_get_contents(__DIR__.'/case.json'),true,512,JSON_THROW_ON_ERROR);
$GLOBALS['ach_responses']=$ach_case['responses']??[];
foreach($ach_case['reads']??[] as $read) achRead($read['pattern'],$read['rows'],$read['extra']??[]);
$ach_errors=[];
set_error_handler(function($severity,$message){
    if (error_reporting()&$severity) $GLOBALS['ach_errors'][]='PHP '.$severity.': '.$message;
    return true;
});
error_reporting(E_ALL);
$ach_complete=false;
ob_start();
register_shutdown_function(function(){
    // Run after all application-registered checkpoint callbacks.
    register_shutdown_function(function(){
        $out=ob_get_contents();if(ob_get_level())ob_end_clean();
        if ($GLOBALS['ach_reads']) $GLOBALS['ach_errors'][]='Unconsumed SQL reads: '.count($GLOBALS['ach_reads']);
        if ($GLOBALS['ach_responses']) $GLOBALS['ach_errors'][]='Unconsumed responses: '.count($GLOBALS['ach_responses']);
        $record=['complete'=>$GLOBALS['ach_complete'],'events'=>$GLOBALS['ach_trace'],
                 'errors'=>$GLOBALS['ach_errors'],'stdout'=>$out];
        file_put_contents(__DIR__.'/result.json',json_encode($record,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
    });
});
try {
    $which=$ach_case['worker']??'messagewizwiz';
    if (!in_array($which,['messagewizwiz','warnusers'],true)) throw new RuntimeException('Unknown test worker');
    require __DIR__.'/settings/'.$which.'.php';
    $ach_complete=true;
} catch (Throwable $err) {
    $GLOBALS['ach_errors'][]=get_class($err).': '.$err->getMessage();
}
