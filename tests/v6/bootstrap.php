<?php
/** Real full legacy config.php bootstrap, with an intentional synthetic negative actor. */
if (PHP_SAPI!=='cli' || getenv('ACHBOT_PRIVATE_TEST')!=='1' || getenv('ACHBOT_V6_NATIVE')!=='1'
    || extension_loaded('FFI') || !extension_loaded('mysqli') || !extension_loaded('curl')) exit(3);
$from_id=-1; // Same existing early-exit gate: no Telegram dispatch, payments or wallet.
$admins_list=[9001];
$ach_result=null; $ach_errors=[];
set_error_handler(function($severity,$message){
    if(error_reporting()&$severity)$GLOBALS['ach_errors'][]=$severity.': '.$message;
    return true;
});
error_reporting(E_ALL);
ob_start();
register_shutdown_function(function(){
    $output=ob_get_contents();if(ob_get_level())ob_end_clean();
    $result=[];$exception=null;
    try {
        $names=json_decode(file_get_contents(__DIR__.'/names.json'),true,512,JSON_THROW_ON_ERROR);
        foreach($names as $name) {
            if(!function_exists($name))throw new RuntimeException('Missing global facade: '.$name);
            $fn=new ReflectionFunction($name);
            $params=[];
            foreach ($fn->getParameters() as $p) {
                $params[]=[$p->getName(),$p->isOptional(),$p->isDefaultValueAvailable() ? $p->getDefaultValue() : null];
            }
            $result['signatures'][$name]=$params;
        }
        $result['server']=getServerConfigById(3);
        $result['marzban_token']=getMarzbanToken(2);
        $result['xui_panel']=getJson(3);
        $result['alireza_panel']=getJson(4);
        $result['link']=getConnectionLink(3,'fixture-uuid','vless','fixture-user',443,'ws',7);
        $result['presentation']=buildSubLink('sanaei','https://panel.invalid/','https://bot.invalid/','a'.str_repeat('x',29),['subLinkState'=>'on']);
    } catch(Throwable $e){$exception=get_class($e).': '.$e->getMessage();}
    file_put_contents(__DIR__.'/result.json',json_encode([
        'result'=>$result,'exception'=>$exception,'warnings'=>$GLOBALS['ach_errors'],
        'stdout'=>$output,'bootstrap_log'=>file_exists(__DIR__.'/admins_list_check.txt')?
            file_get_contents(__DIR__.'/admins_list_check.txt'):null],
        JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE));
});
require __DIR__.'/config.php';
// The source must exit on the original negative-actor gate; no live webhook work.
throw new RuntimeException('Original negative-actor early gate was bypassed');
