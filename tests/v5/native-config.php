<?php
/** AChBot synthetic standalone config.php for owned private test DB only. */
if (PHP_SAPI!=='cli' || getenv('ACHBOT_PRIVATE_TEST')!=='1' || getenv('ACHBOT_V5_NATIVE')!=='1') exit(3);
$dbname=getenv('ACHBOT_DATABASE');$socket=getenv('ACHBOT_SOCKET');
if(!in_array($dbname,['achbot_v4_old','achbot_v4_new'],true) || !file_exists($socket)) throw new RuntimeException('Non-fixture database denied');
$connection=new mysqli('localhost','root','',$dbname,0,$socket);
if($connection->connect_errno)throw new RuntimeException('Fixture database unavailable');
$connection->set_charset('utf8mb4');
$admin=9001;
$botUsername='FixtureBot';
$from_id=1001;
$buttonValues=['start_bot'=>'Start bot','my_subscriptions'=>'Subscriptions','sharj'=>'Wallet','back_to_main'=>'Home'];
$botState=['rateLimit'=>2147483647,'renewAutoState'=>'off','rewardChannel'=>''];
function achResponse($name,$payload) {
    achEvent('telegram', ['method'=>$name,'payload'=>$payload]);
    $spec=$GLOBALS['ach_case'];
    if(($spec['interrupt_method']??'')===$name && ($spec['interrupt_chat_id']??null)===($payload['chat_id']??null)) exit(0);
    if(($spec['cancel_on_method']??'')===$name && ($spec['cancel_chat_id']??null)===($payload['chat_id']??null)) {
        $table=$spec['cancel_table'];$id=(int)$spec['cancel_id'];
        if(!in_array($table,['send_list','bc_jobs'],true)) throw new RuntimeException('Fixture cancellation table denied');
        $stmt=$GLOBALS['connection']->prepare("UPDATE `$table` SET `state`=4 WHERE `id`=?");
        $stmt->bind_param('i',$id);$stmt->execute();$stmt->close();
        achEvent('synthetic-operator-cancel',['table'=>$table,'id'=>$id]);
    }
    if(($spec['rate_method']??'')===$name && ($spec['rate_chat_id']??null)===($payload['chat_id']??null))
        return (object)['ok'=>false,'error_code'=>429,'parameters'=>(object)['retry_after'=>2]];
    return (object)['ok'=>true,'result'=>(object)['message_id'=>($payload['chat_id']??0)+1000]];
}
function bot($method,$payload=[]) {return achResponse($method,$payload);}
function sendMessage($txt,$keys=null,$parse='MarkDown',$ci=null) {
    return achResponse('sendMessage',['chat_id'=>$ci??$GLOBALS['from_id'],'text'=>$txt,'reply_markup'=>$keys,'parse_mode'=>$parse]);
}
function forwardmessage($to,$from,$mid) {return achResponse('forwardMessage',['chat_id'=>$to,'from_chat_id'=>$from,'message_id'=>$mid]);}
function pinOne($chat,$mid) {return achResponse('pinChatMessage',['chat_id'=>$chat,'message_id'=>$mid]);}
function unpinOne($chat,$mid) {return achResponse('unpinChatMessage',['chat_id'=>$chat,'message_id'=>$mid]);}
function deleteOne($chat,$mid) {return achResponse('deleteMessage',['chat_id'=>$chat,'message_id'=>$mid]);}
function apiOk($res) {return is_object($res) && ($res->ok??false)===true;}
function getApiMessageId($res) {return (int)($res->result->message_id??0);}
function logBroadcastUserMessage($connection,$broadcastId,$userid,$messageId) {
    if($broadcastId<=0 || $userid<=0 || $messageId<=0)return;
    $stmt=$connection->prepare("\n        INSERT INTO `bc_messages` (`broadcast_id`,`userid`,`message_id`)\n        VALUES (?,?,?)\n        ON DUPLICATE KEY UPDATE\n            `message_id`=VALUES(`message_id`),\n            `deleted`=0,\n            `deleted_at`=NULL\n    ");
    $stmt->bind_param('iii',$broadcastId,$userid,$messageId);$stmt->execute();$stmt->close();
}
function getMarzbanUser($id,$remark) {
    achEvent('panel.fixture',['method'=>'getMarzbanUser','server_id'=>$id,'remark'=>$remark]);
    return (object)($GLOBALS['ach_case']['panel']??[]);
}
function getJson($id) {
    achEvent('panel.fixture',['method'=>'getJson','server_id'=>$id]);
    return (object)['success'=>false];
}
function deleteMarzban($id,$remark) {
    achEvent('panel.fixture',['method'=>'deleteMarzban','server_id'=>$id,'remark'=>$remark]);
    return (object)['ok'=>true];
}
function deleteClient(...$args) {achEvent('panel.fixture',['method'=>'deleteClient','args'=>$args]);return (object)['ok'=>true];}
function deleteInbound(...$args) {achEvent('panel.fixture',['method'=>'deleteInbound','args'=>$args]);return (object)['ok'=>true];}
