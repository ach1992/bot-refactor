<?php
// Test-only URL remapping. All HTTP is forced to the parent's owned loopback server.
namespace AchBotTest\Wire {
    function init($url=null) {
        if($url!==null) throw new \RuntimeException('Native fixture requires deferred URL');
        $h=\curl_init();\curl_setopt($h,CURLOPT_PROXY,'');\curl_setopt($h,CURLOPT_NOPROXY,'*');return $h;
    }
    function option($h,$key,$value) {
        if($key===CURLOPT_URL) {
            if(!preg_match('~^https://api\.telegram\.org/botfixture-token/([A-Za-z]+)$~D',$value,$m)) throw new \RuntimeException('Rejected non-fixture native URL');
            $base=getenv('ACHBOT_WIRE_BASE');
            if(!preg_match('~^http://127\.0\.0\.1:[0-9]+$~D',$base)) throw new \RuntimeException('Rejected non-loopback server');
            $value=$base.'/'.$m[1];
        }
        if($key===CURLOPT_FOLLOWLOCATION && $value) throw new \RuntimeException('Redirect following forbidden by native fixture');
        return \curl_setopt($h,$key,$value);
    }
}
namespace AchBot\Telegram\Transport {
    function curl_init($url=null){return \AchBotTest\Wire\init($url);}
    function curl_setopt($h,$key,$value){return \AchBotTest\Wire\option($h,$key,$value);}
}
namespace AchBotTest\Original {
    function curl_init($url=null){return \AchBotTest\Wire\init($url);}
    function curl_setopt($h,$key,$value){return \AchBotTest\Wire\option($h,$key,$value);}
}
namespace {
    if(PHP_SAPI!=='cli'||getenv('ACHBOT_PRIVATE_TEST')!=='1'||!extension_loaded('curl')||extension_loaded('mysqli')||extension_loaded('FFI'))exit(3);
    $spec=json_decode(file_get_contents(__DIR__.'/native-case.json'),true,512,JSON_THROW_ON_ERROR);
    $botToken='fixture-token';
    require __DIR__.'/native-functions.php';
    $fn=($spec['mode']==='old'?'AchBotTest\\Original\\':'').$spec['function'];
    $data=['chat_id'=>'1001','text'=>"fixture & + \u{0633}\u{0644}\u{0627}\u{0645}",'reply_markup'=>'{"inline_keyboard":[]}'];
    if($spec['variant']==='multipart') {
        file_put_contents('upload.txt','fixture native upload');
        $data['document']=new \CURLFile(realpath('upload.txt'),'text/plain','fixture.txt');
        $data['reply_markup']=['inline_keyboard'=>[]];
    }
    $result=$fn('sendMessage',$data);
    $guard=false;$h=\AchBotTest\Wire\init();
    try{\AchBotTest\Wire\option($h,CURLOPT_URL,'https://outside.example.invalid/');}catch(\RuntimeException $e){$guard=true;}
    \curl_close($h);
    if(!$guard)throw new \RuntimeException('Native egress guard failed');
    file_put_contents('native-result.json',json_encode(['return'=>base64_encode(serialize($result)),'egress_guard'=>true,'php'=>PHP_VERSION,'curl'=>curl_version()['version']],JSON_THROW_ON_ERROR));
}
