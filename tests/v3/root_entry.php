<?php
// Loaded only by the frozen child harness in a private directory.
$ach_spec=json_decode(file_get_contents(__DIR__.'/root-case.json'),true,512,JSON_THROW_ON_ERROR);
$fixtureState += ['cartToCartAutoAcceptState'=>'off','sharedExistence'=>'off','individualExistence'=>'off'];
$fixtureUser += ['freetrial'=>null,'agent_date'=>0];
$fixtureUser['userid']=$ach_spec['actor']??1001;
$fixtureUser['step']=$ach_spec['step']??'none';
$fixtureUser['temp']=$ach_spec['temp']??'fixture title';
$fixtureUser['isAdmin']=$ach_spec['is_admin']??0;
$fixtureUser['is_agent']=$ach_spec['is_agent']??0;
$ach_text=array_key_exists('text',$ach_spec)?$ach_spec['text']:'fixture input';
if($ach_text==='__CANCEL__')$ach_text=$buttonValues['cancel'];
$ach_message=['message_id'=>71,'from'=>['id'=>$fixtureUser['userid'],'first_name'=>'Fixture','last_name'=>'User','username'=>'fixture_user'],
              'chat'=>['id'=>$fixtureUser['userid'],'type'=>'private']];
if($ach_text!==null)$ach_message['text']=$ach_text;
if(!empty($ach_spec['photo'])){$ach_message['photo']=[['file_id'=>'fixture-photo']];$ach_message['caption']='fixture photo';}
$ach_update=['update_id'=>1,'message'=>$ach_message];
if(isset($ach_spec['data'])) {
    $ach_message['text']='fixture callback message';$ach_message['reply_markup']=['inline_keyboard'=>[]];
    $ach_update=['update_id'=>1,'callback_query'=>['id'=>'fixture-callback','data'=>$ach_spec['data'],'from'=>$ach_message['from'],'message'=>$ach_message]];
}
$GLOBALS['ach_input']=json_encode($ach_update);
stream_wrapper_unregister('php');stream_wrapper_register('php',AchInputStream::class);
achRead('/^SELECT \* FROM `users` WHERE `userid`=\?$/',[$fixtureUser]);
achRead('/PAYMENT_KEYS/',[['value'=>'{}']]);
achRead('/BOT_STATES/',[['value'=>json_encode($fixtureState)]]);
foreach($ach_spec['reads']??[] as $ach_read) {
    $ach_rows=$ach_read['rows']==='__USER__'?[$fixtureUser]:$ach_read['rows'];
    achRead($ach_read['pattern'],$ach_rows);
}
require $root.'/bot.php';
