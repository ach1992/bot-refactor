<?php
// Test-only synthetic bootstrap. Installed as config.php in a private fixture,
// never in the repository runtime, and never connected to external services.
$connection = new mysqli();
$admin=9001;
$botUsername='FixtureBot';
$buttonValues=['start_bot'=>'Start test','cancel'=>'Cancel','my_subscriptions'=>'Subscriptions','sharj'=>'Wallet','back_to_main'=>'Home'];
$botState=['rateLimit'=>2000000000,'renewAutoState'=>'off','rewardChannel'=>''];

function achResponse(string $method, array $payload): object {
    achEvent('telegram.request', ['method'=>$method,'payload'=>$payload]);
    if (($GLOBALS['ach_case']['exit_on_method']??'') === $method) exit(0);
    $response=array_shift($GLOBALS['ach_responses']);
    if ($response!==null) {
        if (($response['method']??'')!==$method) achFail('Unexpected response method '.$method);
        return json_decode(json_encode($response['response'],JSON_THROW_ON_ERROR),false,512,JSON_THROW_ON_ERROR);
    }
    return (object)['ok'=>true,'result'=>(object)['message_id'=>501]];
}
function bot($method, $payload=[]) { return achResponse($method,$payload); }
function sendMessage($text,$keyboard=null,$parse='MarkDown',$chatId=null) {
    return achResponse('sendMessage', ['text'=>$text,'keyboard'=>$keyboard,'parse'=>$parse,'chat_id'=>$chatId]);
}
function forwardmessage($to,$from,$id) { return achResponse('forwardMessage',['chat_id'=>$to,'from_chat_id'=>$from,'message_id'=>$id]); }
function pinOne($chat,$id) { return achResponse('pinChatMessage',['chat_id'=>$chat,'message_id'=>$id]); }
function unpinOne($chat,$id) { return achResponse('unpinChatMessage',['chat_id'=>$chat,'message_id'=>$id]); }
function deleteOne($chat,$id) { return achResponse('deleteMessage',['chat_id'=>$chat,'message_id'=>$id]); }
function apiOk($result) { return is_object($result) && ($result->ok??false)===true; }
function getApiMessageId($result) { return (int)($result->result->message_id??0); }
function logBroadcastUserMessage($connection,$broadcastId,$userid,$messageId) {
    if ($broadcastId<=0 || $userid<=0 || $messageId<=0) return;
    $st=$connection->prepare("\n        INSERT INTO `bc_messages` (`broadcast_id`,`userid`,`message_id`)\n        VALUES (?,?,?)\n        ON DUPLICATE KEY UPDATE\n            `message_id`=VALUES(`message_id`),\n            `deleted`=0,\n            `deleted_at`=NULL\n    ");
    $st->bind_param('iii',$broadcastId,$userid,$messageId);$st->execute();$st->close();
}
function getMarzbanUser($sid,$remark) {
    achEvent('panel.mock',['method'=>'getMarzbanUser','args'=>[$sid,$remark]]);
    return (object)($GLOBALS['ach_case']['marzban_response']??[]);
}
function getJson($sid) {
    achEvent('panel.mock',['method'=>'getJson','args'=>[$sid]]);
    return (object)($GLOBALS['ach_case']['getjson_response']??['success'=>false]);
}
function deleteMarzban($sid,$remark) {
    achEvent('panel.mock',['method'=>'deleteMarzban','args'=>[$sid,$remark]]);
    return (object)['ok'=>true];
}
function deleteClient(...$args) { achEvent('panel.mock',['method'=>'deleteClient','args'=>$args]);return (object)['ok'=>true]; }
function deleteInbound(...$args) { achEvent('panel.mock',['method'=>'deleteInbound','args'=>$args]);return (object)['ok'=>true]; }
