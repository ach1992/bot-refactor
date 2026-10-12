<?php
// Development-only differential vectors; never deploy this file.
if (PHP_SAPI !== 'cli' || getenv('ACHBOT_PRIVATE_TEST') !== '1') { http_response_code(404); exit; }
if (class_exists('mysqli', false) || function_exists('curl_init') || extension_loaded('FFI')) throw new RuntimeException('Native external I/O loaded');
require __DIR__.'/functions-under-test.php';
$mapping=json_decode(file_get_contents(__DIR__.'/mapping.json'),true,512,JSON_THROW_ON_ERROR);
$mode=$argv[1] ?? 'legacy';
$cases=[];
function vector($function,$arguments,$choice=null) { global $cases; $cases[]=[$function,$arguments,$choice]; }
function unicode($json) { return json_decode('"'.$json.'"',true,512,JSON_THROW_ON_ERROR); }
$fa=unicode('\u0633\u0644\u0627\u0645');
$emoji=unicode('\ud83d\udce4');
$objects=[null,false,true,0,1,'1',[],['ok'=>true],(object)[],(object)['ok'=>true],(object)['ok'=>1],
 (object)['error_code'=>429],(object)['error_code'=>'429','parameters'=>(object)['retry_after'=>'17']],
 (object)['error_code'=>429,'parameters'=>(object)['retry_after'=>0]],(object)['error_code'=>429,'parameters'=>(object)['retry_after'=>-2]],
 (object)['error_code'=>500,'parameters'=>(object)['retry_after'=>4]],
 (object)['result'=>(object)['message_id'=>'9001']],(object)['result'=>['message_id'=>5]],
 (object)['forward_origin'=>(object)[]],(object)['forward_origin'=>null],(object)['forward_from'=>false],
 (object)['forward_from_chat'=>(object)[]],(object)['forward_sender_name'=>'fixture']];
foreach($objects as $obj) foreach(['tgRetryAfter','apiOk','getApiMessageId','isForwardedMessageObj'] as $f) vector($f,[$obj]);
foreach([null,(object)[],(object)['message'=>(object)['photo'=>[]]],
 (object)['message'=>(object)['photo'=>[(object)['file_id'=>'small'],(object)['file_id'=>'large']]]],
 (object)['message'=>(object)['photo'=>[(object)['file_id'=>100]]]],
 (object)['message'=>(object)['photo'=>['x'=>(object)['file_id'=>'keyed']]]],
 (object)['message'=>(object)['photo'=>'invalid']]] as $v) vector('extractPhotoFileId',[$v]);
foreach([null,false,0,'','raw','{"inline_keyboard":[]}',[],['inline_keyboard'=>[[['text'=>$fa,'callback_data'=>'old_fixture']]]],['x'=>"\xB1\x31"],(object)['a'=>1]] as $v) vector('normalizeReplyMarkup',[$v]);
foreach([[],[[['text'=>$fa.$emoji,'callback_data'=>'old_fixture']]],['unexpected'=>'shape'],['bad'=>"\xB1\x31"],null,'invalid'] as $v) vector('inlineKeyboard',[$v]);
foreach(['has','none','agents','all','users','',null,0,1,true,[],(object)[]] as $v) vector('groupFa',[$v]);
foreach([0,1,2,3,4,99,'1','01','1.0','2','3','4','',null,true,false,[]] as $v) vector('stateFa',[$v]);
foreach(['BUY_SUB','RENEW_ACCOUNT','RENEW_SCONFIG','INCREASE_WALLET','INCREASE_DAY_1_2','INCREASE_DAY_1_2_suffix','INCREASE_VOLUME_3_4','INCREASE_DAY_x','UNKNOWN','',null,123,[]] as $v) vector('payTypeToDescription',[$v]);
foreach(['usdt','gift','detail','other','',0,null,[]] as $v) vector('getCartToCartMessageKey',[$v]);
foreach(['','   ','@fixture_support','@@fixture_support','fixture_support','https://t.me/fixture_support','HTTP://T.ME/fixture_support','https://example.invalid','0',null,123,[]] as $v) {
 vector('getSupportUrl',[['supportID'=>$v],'fallback']);
}
vector('getSupportUrl',[[]]);
vector('getSupportUrl',['fallback'=>'chosen','botState'=>[]]);
vector('replaceAllPlaceholdersInMainValues',[['a'=>'SUPPORTID LOCKCHANNEL userid','n'=>[7,false,'userid',null]],['supportID'=>'@fixture','lockChannel'=>'@channel'],1001]);
vector('replaceAllPlaceholdersInMainValues',[['a'=>'SUPPORTID'],['supportID'=>'LOCKCHANNEL','lockChannel'=>'userid'],42]);
vector('replaceAllPlaceholdersInMainValues',[['untouched'=>(object)['userid'=>'userid']],[],null]);
vector('replaceAllPlaceholdersInMainValues',[[],[]]);
vector('replaceAllPlaceholdersInMainValues',[['a'=>$fa.' userid'],[],1001]);
vector('replaceAllPlaceholdersInMainValues',[['a'=>'SUPPORTID'],['supportID'=>[]],1]);
foreach([null,false,true,0,1,-1,1024,1048576,1073741823,1073741824,1073741825,2147483648,1.5,'1073741824','0',-1073741824,1e15,'invalid',[],INF,NAN] as $v) {
 vector('sumerize',[$v]); vector('sumerize2',[$v]);
}
foreach([0,1,2,3,4,6] as $n) foreach([[],[1],['2'],[0,4]] as $indexes) {
 $links=[]; for($i=0;$i<$n;$i++) $links[]='vless://fixture-'.$i.'?x=a&b=<raw>';
 vector('buildConfigLinks',[$links,['configLinkState'=>'on'],$indexes]);
}
foreach(['off',null,false,'ON',''] as $flag) vector('buildConfigLinks',[['fixture'],['configLinkState'=>$flag],[1]]);
vector('buildConfigLinks',['not-an-array',['configLinkState'=>'on'],[]]);
foreach(['marzban','xui','MARZBAN'] as $kind) foreach(['on','off',null] as $flag) foreach(['https://panel.example.invalid/','https://panel.example.invalid//'] as $panel) {
 vector('buildSubLink',[$kind,$panel,'https://bot.example.invalid/','fixture&token',['subLinkState'=>$flag]]);
}
foreach([null,false,'',0,'0','   ',',','https://a.example.invalid/',' https://a.example.invalid/ , , https://b.example.invalid// ','0,https://a.example.invalid'] as $raw) vector('buildSubBackupSection',[$raw,'fixture-token']);
foreach([null,'detail','gift','usdt',false,0] as $choice) {
 vector('kbSendReceiptAndCancel',['legacy_send_','legacy_cancel_','fixturehash'],$choice);
 vector('kbOnlyCancel',['legacy_cancel_','fixturehash'],$choice);
}
vector('inlineKeyboard',['rows'=>[[['text'=>$fa,'callback_data'=>'preserved']]]]);
vector('getCartToCartMessageKey',['messageType'=>'gift']);
vector('sumerize',[1024,'ignored-extra-argument']);

function diagnostic($message) {
 global $mapping;
 foreach($mapping as $old=>$new) $message=str_replace($new,$old,$message);
 // Physical source locations and internal namespace labels are not user-message content.
 return preg_replace('/, called in .* on line [0-9]+$/','',$message);
}
$results=[];
foreach($cases as $index=>[$legacy,$args,$choice]) {
 unset($GLOBALS['lastCartToCartMessageType']);
 if($choice!==null) $GLOBALS['lastCartToCartMessageType']=$choice;
 $call=$mode==='internal'?$mapping[$legacy]:$legacy;
 if($mode==='internal') {
  if($legacy==='kbSendReceiptAndCancel') {
   if(array_is_list($args)) $args[]=$choice ?? 'detail'; else $args['messageType']=$choice ?? 'detail';
  }
 }
 $warnings=[];
 set_error_handler(function($severity,$message) use (&$warnings) { $warnings[]=[$severity,diagnostic($message)]; return true; });
 ob_start();
 try { $value=$call(...$args); $out=['type'=>get_debug_type($value),'value'=>base64_encode(serialize($value))]; }
 catch(Throwable $e) { $out=['exception'=>get_class($e),'code'=>$e->getCode(),'message'=>diagnostic($e->getMessage())]; }
 $out['output']=base64_encode(ob_get_clean());
 restore_error_handler();
 $out['warnings']=$warnings;
 $results[$legacy.'.'.$index]=$out;
}
file_put_contents(__DIR__.'/helpers-result.json',json_encode($results,JSON_THROW_ON_ERROR));
