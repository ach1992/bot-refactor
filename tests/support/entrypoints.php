<?php
// Execute unchanged entrypoint files inside a disposable directory with fake native I/O.
class AchInputStream {
    public $context;
    private $position=0;
    private $data='';
    function stream_open($path,$mode,$options,&$opened_path) {
        if($path!=='php://input') achFail('Unexpected php wrapper path: '.$path);
        $this->data=$GLOBALS['ach_input'] ?? ''; return true;
    }
    function stream_read($count) { $v=substr($this->data,$this->position,$count); $this->position+=strlen($v); return $v; }
    function stream_eof() { return $this->position>=strlen($this->data); }
    function stream_tell() { return $this->position; }
    function stream_stat() { return ['size'=>strlen($this->data)]; }
    function stream_set_option($option,$arg1,$arg2) { return false; }
}
$variant=substr($scenario,6);
if(str_starts_with($variant,'health_')) {
    if($variant==='health_method') $_SERVER['REQUEST_METHOD']='POST';
    if($variant==='health_head') $_SERVER['REQUEST_METHOD']='HEAD';
    if($variant==='health_no_token') {
        $cfg=file_get_contents('baseInfo.php');
        file_put_contents('baseInfo.php',str_replace("'fixture-token'","''",$cfg));
    }
    if($variant==='health_cached_failure') {
        file_put_contents('health_cache.json','{"app":"fail","checks":{"db":"fail"}}'); touch('health_cache.json',time());
    }
    if($variant==='health_db_failure') $GLOBALS['ach_db_ping_fail']=true;
    require $root.'/health.php'; return;
}
$fixtureState += ['cartToCartAutoAcceptState'=>'off','sharedExistence'=>'off','individualExistence'=>'off'];
$fixtureUser += ['freetrial'=>null,'agent_date'=>0];
if($variant==='bot_banned') $fixtureUser['step']='banned';
if($variant==='bot_off') $fixtureState['botState']='off';
$message=['message_id'=>71,'from'=>['id'=>1001,'first_name'=>'Fixture','last_name'=>'User','username'=>'fixture_user'],
          'chat'=>['id'=>1001,'type'=>'private'],'text'=>'/start'];
$input=['update_id'=>1,'message'=>$message];
if(in_array($variant,['bot_legacy_callback','bot_main_callback'],true)) {
    $message['text']='fixture'; $message['reply_markup']=['inline_keyboard'=>[]];
    $input=['update_id'=>1,'callback_query'=>['id'=>'fixture-callback','from'=>$message['from'],
            'data'=>$variant==='bot_legacy_callback'?'wizwizch':'mainMenu','message'=>$message]];
}
$GLOBALS['ach_input']=json_encode($input);
stream_wrapper_unregister('php'); stream_wrapper_register('php',AchInputStream::class);
achRead('/^SELECT \* FROM `users` WHERE `userid`=\?$/',[$fixtureUser]);
achRead('/PAYMENT_KEYS/',[['value'=>'{}']]);
achRead('/BOT_STATES/',[['value'=>json_encode($fixtureState)]]);

if(str_starts_with($variant,'bot_')) {
    if(in_array($variant,['bot_start','bot_main_callback'],true)) {
        // setUser reads current row before updating step/temp.
        achRead('/^SELECT \* FROM `users`/',[$fixtureUser]);
        achRead('/^SELECT \* FROM `users`/',[$fixtureUser]);
        achRead('/MAIN_BUTTONS/',[]);
    }
    require $root.'/bot.php'; return;
}
if($variant==='pay_unknown') { $_GET=['hash_id'=>'fixturehash1']; require $root.'/pay/index.php'; return; }
if($variant==='pay_missing') {
    $_GET=['hash_id'=>'fixturehash1','nowpayment'=>''];
    achRead('/^SELECT \* FROM `pays`/',[]); require $root.'/pay/index.php'; return;
}
if($variant==='pay_return_missing') {
    $_GET=['nowpayment'=>''];
    achRead('/PAYMENT_KEYS/',[['value'=>'{}']]); require $root.'/pay/back.php'; return;
}
if($variant==='sub_missing'||$variant==='sub_short') {
    if($variant==='sub_short') $_GET=['token'=>'short'];
    require $root.'/settings/subLink.php'; return;
}
if($variant==='gift_idle') {
    achRead('/^SELECT \* FROM `gift_list`/',[]); require $root.'/settings/gift2all.php'; return;
}
if($variant==='tron_disabled') {
    achRead('/^SELECT \* FROM `pays`/',[]); require $root.'/settings/tronChecker.php'; return;
}
if($variant==='warn_idle') {
    achRead('/warnOffset/',[['value'=>'0']]); achRead('/^SELECT \* FROM `orders_list`/',[]);
    achRead('/^SELECT \* FROM `orders_list`/',[]);
    achRead('/AutoRenewCheck/',[['value'=>'0']]); require $root.'/settings/warnusers.php'; return;
}
if($variant==='broadcast_pin'||$variant==='broadcast_429') {
    achRead('/^SELECT \* FROM `bc_jobs`/',[['id'=>51,'broadcast_id'=>61,'action'=>'pin','offset'=>0,'new_text'=>null,'parse_mode'=>null]]);
    achRead('/^SELECT id, userid, message_id FROM bc_messages/',[['id'=>70,'userid'=>1001,'message_id'=>71]]);
    achRead('/^SELECT `state` FROM `bc_jobs`/',[['state'=>2]]);
    achRead('/^SELECT `id` FROM `send_list`/',[]);
    if($variant==='broadcast_429') {
        achHttp('getChatMember',['ok'=>true,'result'=>['status'=>'member']]);
        achHttp('sendMessage',['ok'=>true,'result'=>['message_id'=>501]]);
        achHttp('pinChatMessage',['ok'=>false,'error_code'=>429,'parameters'=>['retry_after'=>2]],429);
    }
    require $root.'/settings/messagewizwiz.php'; return;
}
if($variant==='broadcast_idle') {
    achRead('/^SELECT \* FROM `bc_jobs`/',[]); achRead('/^SELECT `id` FROM `send_list`/',[]);
    require $root.'/settings/messagewizwiz.php'; return;
}
if($variant==='reward_idle') {
    achRead('/LAST_BACKUP_TIME/',[['value'=>'1900000000']]); require $root.'/settings/rewardReport.php'; return;
}
achFail('Unknown entrypoint scenario');
