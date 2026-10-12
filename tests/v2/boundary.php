<?php
// Test-only provider doubles, never real configuration or external services.
if (PHP_SAPI !== 'cli' || getenv('ACHBOT_PRIVATE_TEST') !== '1') exit(3);
if (extension_loaded('curl') || extension_loaded('mysqli') || extension_loaded('FFI')) exit(3);
date_default_timezone_set('UTC');
require __DIR__.'/tests/support/doubles.php';require __DIR__.'/functions-under-test.php';
$spec=json_decode(file_get_contents(__DIR__.'/case.json'),true,512,JSON_THROW_ON_ERROR);
$mapping=json_decode(file_get_contents(__DIR__.'/mapping.json'),true,512,JSON_THROW_ON_ERROR);
$mode=$spec['mode'];$family=$spec['family'];$variant=$spec['variant'];
$botToken='fixture-token';$from_id=1001;$message_id=71;$callbackId='fixture-callback';$botUrl='https://bot.example.invalid/';
$warnings=[];$error=null;$result=null;
set_error_handler(function($severity,$message)use(&$warnings){$warnings[]=[$severity,$message];return true;});
function v2call($name,array $args) {
    global $mode,$mapping;
    if($mode!=='internal')return $name(...$args);
    $entry=$mapping[$name];require_once __DIR__.'/'.$entry['path'];$dependencies=[];
    foreach($entry['context'] as $key=>$source) {
        if($source==='__DIR__')$dependencies[]=__DIR__;
        elseif($source==="'bot'")$dependencies[]='bot';
        elseif($source==="'sendFileToTelegram'")$dependencies[]='sendFileToTelegram';
        elseif($source==="'file_get_contents'")$dependencies[]='file_get_contents';
        elseif($source==="$"."GLOBALS['botUrl']")$dependencies[]=$GLOBALS['botUrl'];
        else $dependencies[]=$GLOBALS[substr($source,1)];
    }
    return ($entry['function'])(...array_merge($dependencies,$args));
}
class V2UrlStream {
    public $context;private $position=0;
    function stream_open($path,$mode,$options,&$opened_path){
        if(!str_starts_with($path,'https://bot.example.invalid/answerCallbackQuery?'))achFail('Unexpected URL read');
        achEvent('legacy.get',['url'=>$path]);return true;
    }
    function stream_read($count){$v=substr('fixture-get',$this->position,$count);$this->position+=strlen($v);return $v;}
    function stream_eof(){return $this->position>=11;}
    function stream_stat(){return ['size'=>11];}
}
if(in_array('https',stream_get_wrappers(),true))stream_wrapper_unregister('https');
stream_wrapper_register('https',V2UrlStream::class); // No STREAM_IS_URL; local scripted reads only.
ob_start();
try {
    if($family==='transport') {
        [$name,$v]=explode(':',$variant,2);
        $data=['chat_id'=>1001,'text'=>"Fixture & + / \u{0633}\u{0644}\u{0627}\u{0645}",'nil'=>null,'flag'=>false,'reply_markup'=>['inline_keyboard'=>[]]];
        $body='{"ok":true,"result":{"message_id":501}}';$http=200;$errno=0;
        if($v==='429'){$body='{"ok":false,"error_code":429,"parameters":{"retry_after":17}}';$http=429;}
        if($v==='429-default'){$body='{"ok":false,"error_code":429}';$http=429;}
        if($v==='failure')$errno=28;
        if($v==='500'){$body='{"ok":false,"description":"fixture server failure"}';$http=500;}
        if($v==='199')$http=199;
        if($v==='400'){$http=400;$body='{"ok":false}';}
        if($v==='empty')$body='';
        if($v==='invalid')$body='invalid-json';
        if($v==='null')$body='null';
        if($v==='false')$body='false';
        if($v==='zero')$body='0';
        if($v==='array')$body='[1,2]';
        if($v==='unicode')$body='{"ok":true,"result":"\u0633\u0644\u0627\u0645"}';
        if($v==='multipart'){file_put_contents('upload.txt','fixture upload');$data['document']=new CURLFile(realpath('upload.txt'),'text/plain','fixture.txt');}
        if($v==='nested-file')$data['nested']=[new CURLFile('fixture-nested.txt')];
        if($v==='scalar')$data='invalid';
        if($v==='defaults')$data=[];
        if($v==='large-log'||$v==='log-boundary'){file_put_contents('error_log.txt',str_repeat('X',1024*1024+($v==='large-log'?1:0)));$http=500;$body=str_repeat('Z',600);}
        if($v!=='scalar')achHttp('sendMessage',$body,$http,$errno);
        $args=['sendMessage',$data];
        if($v==='defaults')$args=['sendMessage'];
        if($v==='named')$args=['datas'=>$data,'method'=>'sendMessage'];
        $result=v2call($name,$args);
        if($v==='failure')achExpect($result===($name==='bot'?null:false),'distinct failure contract');
        if($v==='429'||$v==='429-default')achExpect(!in_array('sleep',array_column($GLOBALS['ach_trace'],'kind'),true),'no transport sleep');
        clearstatcache();
        if($v==='large-log')achExpect(filesize('error_log.txt')<2000,'large log rotated before append');
        if($v==='log-boundary')achExpect(filesize('error_log.txt')>1024*1024,'exact log boundary preserved');
    } elseif($family==='message') {
        [$name,$v]=explode(':',$variant,2);$ok=['ok'=>true,'result'=>['message_id'=>501,'file_path'=>'documents/fixture.txt']];
        $defaults=['sendMessage'=>['fixture text'],'sendDocument'=>['upload.txt'],'answerCallbackQuery'=>['fixture-callback'],'editKeys'=>[],
          'editText'=>[71,'fixture edit'],'delMessage'=>[],'sendAction'=>['typing'],'forwardmessage'=>[2002,1001,71],
          'pinOne'=>[1001,71],'unpinOne'=>[1001,71],'deleteOne'=>[1001,71],'sendPhoto'=>['fixture-photo-id'],'getFileUrl'=>['fixture-file'],'alert'=>['fixture alert']];
        $methods=['delMessage'=>'deleteMessage','deleteOne'=>'deleteMessage','editKeys'=>'editMessageReplyMarkup','editText'=>'editMessageText',
          'sendAction'=>'sendChatAction','forwardmessage'=>'forwardMessage','pinOne'=>'pinChatMessage','unpinOne'=>'unpinChatMessage','getFileUrl'=>'getFile','alert'=>'answercallbackquery'];
        $method=$methods[$name]??$name;$args=$defaults[$name];
        if($name==='sendDocument'&&$v!=='missing')file_put_contents('upload.txt','fixture upload');
        if($v==='null-parse')$args=$name==='sendMessage'?['fixture',null,null]:['fixture-photo',null,null,null];
        if($v==='explicit')$args=match($name){
          'sendMessage'=>['fixture',['inline_keyboard'=>[]],'HTML',2002,72],
          'sendPhoto'=>['fixture-photo','fixture caption',['inline_keyboard'=>[]],'HTML',2002,72],
          'editKeys'=>['raw-keyboard',72,2002],'editText'=>[72,'fixture',null,null,2002],'delMessage'=>[72,2002],
          'sendAction'=>['upload_photo',2002],'alert'=>['fixture',true,'other-callback'],'sendDocument'=>['upload.txt','caption',2002],
          'answerCallbackQuery'=>['fixture-callback','fixture & plus+',true],default=>$args};
        if($v==='named')$args=match($name){'sendMessage'=>['txt'=>'fixture','ci'=>2002],'alert'=>['txt'=>'fixture','callid'=>'other-callback'],default=>$args};
        if($name!=='answerCallbackQuery'&&!($name==='sendDocument'&&$v==='missing')) {
            if($v==='parse-error'||$v==='unpin-fallback')achHttp($method,['ok'=>false,'description'=>"Bad Request: can't parse entities"]);
            if($v==='failure')achHttp($method,'',200,28);
            elseif($v==='api-false')achHttp($method,['ok'=>false]);
            elseif($v==='file-path-missing')achHttp($method,['ok'=>true,'result'=>[]]);
            else achHttp($method,$ok);
            if($name==='unpinOne'&&($v==='api-false'||$v==='failure'))achHttp($method,$ok);
        }
        $result=v2call($name,$args);$n=count(array_filter($GLOBALS['ach_trace'],fn($x)=>$x['kind']==='http.fake'));
        if($v==='parse-error'||$v==='unpin-fallback')achExpect($n===2,'legacy fallback count');
        if($name==='sendDocument'&&$v==='missing')achExpect($result===false&&$n===0,'missing file sends nothing');
        if($name==='answerCallbackQuery')achExpect($result===null&&$n===0,'legacy GET callback endpoint preserved');
    } else throw new RuntimeException('Unknown boundary family');
} catch(Throwable $e){$error=['class'=>get_class($e),'code'=>$e->getCode(),'message'=>$e->getMessage()];}
$out=ob_get_clean();
if($GLOBALS['ach_reads']||$GLOBALS['ach_http']||$GLOBALS['ach_panel']||$GLOBALS['ach_errors']){fwrite(STDERR,json_encode(['errors'=>$GLOBALS['ach_errors'],'http_remaining'=>count($GLOBALS['ach_http'])]));exit(2);}
$logs=[];
foreach(['error_log.txt','src/Telegram/error_log.txt']as$p)if(is_file($p)) {
    // Normalize only this fixture's private directory in logged CURLFile paths.
    $bytes=str_replace(__DIR__,'<SANDBOX>',file_get_contents($p));
    $logs[$p]=['normalized_size'=>strlen($bytes),'normalized_sha256'=>hash('sha256',$bytes)];
}
$record=['return'=>base64_encode(serialize($result)),'error'=>$error,'warnings'=>$warnings,'stdout'=>$out,'events'=>$GLOBALS['ach_trace'],'logs'=>$logs];
file_put_contents('boundary-result.json',json_encode($record,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
