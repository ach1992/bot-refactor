<?php
// Original assignment block versus actual candidate scoped bindings; no native I/O.
if(PHP_SAPI!=='cli'||getenv('ACHBOT_PRIVATE_TEST')!=='1') exit(3);
require __DIR__.'/projection-under-test.php';
$inputs=json_decode(file_get_contents(__DIR__.'/inputs.json'),true,512,JSON_THROW_ON_ERROR);$out=[];
foreach($inputs as $name=>$raw) {
    $warnings=[];$error=null;$projection=null;$partial=[];
    set_error_handler(function($s,$m)use(&$warnings){$warnings[]=[$s,$m];return true;});
    try{$projection=projectUnderTest(json_decode($raw),$partial);}
    catch(Throwable $e){$error=[get_class($e),$e->getCode(),$e->getMessage()];}
    restore_error_handler();
    $seed=['caption'=>'prior-caption','data'=>'prior-callback','last_name'=>'prior-last','untouched'=>'persistent-value'];
    $out[$name]=['fields'=>$projection,'partial_scope'=>$partial,'overlay'=>is_array($projection)?array_replace($seed,$projection):null,'warnings'=>$warnings,'error'=>$error];
}
file_put_contents('input-result.json',json_encode($out,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
