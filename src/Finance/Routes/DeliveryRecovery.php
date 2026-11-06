<?php
/**
 * Issue #117 — owner-private, operator-initiated delivery-only recovery.
 * Does not interact with the merchant, wallet or native account providers.
 * Check is observational; Send requires a short-lived two-step warning.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }

$deliveryRoute=(string)($data??'');
$caseParts=[];$checkParts=[];$confirmParts=[];$sendParts=[];
$isCase=preg_match('/^deliveryCase_([1-9][0-9]{0,9})(?:_([1-9][0-9]{0,9}))?$/D',
    $deliveryRoute,$caseParts)===1;
$isCheck=preg_match('/^deliveryCheck_([1-9][0-9]{0,9})_([1-9][0-9]{0,9})$/D',
    $deliveryRoute,$checkParts)===1;
$isConfirm=preg_match('/^deliveryConfirm_([1-9][0-9]{0,9})_([1-9][0-9]{0,9})$/D',
    $deliveryRoute,$confirmParts)===1;
$isSend=preg_match('/^deliverySend_([1-9][0-9]{0,9})_([1-9][0-9]{0,9})_([1-9][0-9]{0,10})_([a-f0-9]{12})$/D',
    $deliveryRoute,$sendParts)===1;
if (!$isCase && !$isCheck && !$isConfirm && !$isSend) {
    achbotAdminDeny();
    return;
}
if ((int)($admin??0)<=0 || (int)($from_id??0)!==(int)$admin ||
    (int)($chat_id??0)!==(int)$admin || !achbotAdminCan('review',(int)$from_id)) {
    achbotAdminDeny();
    return;
}
require_once __DIR__.'/../DeliveryLedger.php';
$payId=(int)($isCase?$caseParts[1]:($isCheck?$checkParts[1]:
    ($isConfirm?$confirmParts[1]:$sendParts[1])));
$ordinal=(int)($isCheck?$checkParts[2]:($isConfirm?$confirmParts[2]:
    ($isSend?$sendParts[2]:0)));
$cursor=$isCase?(int)($caseParts[2]??0):0;
$esc=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$reply=static function(string $body,array $rows) use ($message_id,$admin): void {
    $keys=json_encode(['inline_keyboard'=>$rows],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    try {
        $sent=editText($message_id,$body,$keys,'HTML',(int)$admin);
        if (is_object($sent) && ($sent->ok??false)===true) return;
    } catch(Throwable $error) {}
    $sent=sendMessage($body,$keys,'HTML',(int)$admin);
    if (!is_object($sent) || ($sent->ok??false)!==true)
        throw new RuntimeException('delivery_owner_ui_unconfirmed');
};
try {
    answerCallbackQuery($callbackId,achbotMessage('delivery_recovery.check_ack'));
    if (!achbotDeliverySchemaReady($connection))
        throw new RuntimeException('delivery_schema_missing');
    $q=$connection->prepare('SELECT id,user_id,type,price,state FROM pays WHERE id=? LIMIT 1');
    $q->bind_param('i',$payId);$q->execute();$pay=$q->get_result()->fetch_assoc();$q->close();
    if (!$pay) throw new RuntimeException('delivery_payment_not_found');
    $back=[[['text'=>achbotMessage('delivery_recovery.back'),'callback_data'=>'deliveryCase_'.$payId]]];
    $note='';
    if ($isCheck || $isConfirm || $isSend) {
        if ($isCheck) {
            achbotDeliveryMarkStaleUnknown($connection,$payId,$ordinal);
            $checked=achbotDeliveryCheck($connection,$payId,$ordinal);
            $note=achbotMessageHtml('delivery_recovery.check_result',['ordinal'=>$ordinal,'result'=>$checked])."\n";
        }
        if ($isConfirm) {
            $checked=achbotDeliveryCheck($connection,$payId,$ordinal);
            $d=achbotDeliveryInspectOne($connection,$payId,$ordinal);
            if (!$d || !in_array($checked,['pending','unknown'],true)) {
                $reply(achbotMessage('delivery_recovery.not_eligible')."\n".
                    achbotMessageHtml('delivery_recovery.check_result',
                        ['ordinal'=>$ordinal,'result'=>$checked]),$back);
                return;
            }
            $challenge=achbotDeliveryChallenge($d,(int)$from_id);
            $rows=[[['text'=>achbotMessage('delivery_recovery.send_confirm'),
                'callback_data'=>'deliverySend_'.$payId.'_'.$ordinal.'_'.$challenge]],...$back];
            $body=achbotMessageHtml('delivery_recovery.warning',
                ['pay'=>$payId,'ordinal'=>$ordinal,'state'=>$checked]);
            $reply($body,$rows);
            return;
        }
        if ($isSend) {
            $result=achbotDeliveryDispatch($connection,$payId,$ordinal,(int)$from_id,
                $sendParts[3].'_'.$sendParts[4]);
            achbotDeliverySyncAggregate($connection,$payId);
            $note=achbotMessageHtml('delivery_recovery.send_result',
                ['ordinal'=>$ordinal,'result'=>$result])."\n";
        }
    }
    $summary=achbotDeliveryAggregate($connection,$payId);
    if (!$summary || (int)$summary['total']===0) {
        $reply(achbotMessage('delivery_recovery.missing'),$back);
        return;
    }
    $rows=achbotDeliveryInspect($connection,$payId,$cursor);
    $more=count($rows)>10;$rows=array_slice($rows,0,10);
    $body=achbotMessageHtml('delivery_recovery.title',['pay'=>$payId,'user'=>$pay['user_id'],
        'amount'=>number_format((int)$pay['price']),'state'=>$pay['state']]).
        achbotMessageHtml('delivery_recovery.summary',['total'=>$summary['total'],
        'ack'=>$summary['ack'],'pending'=>$summary['pending'],
        'unknown'=>$summary['unknown'],'dispatching'=>$summary['dispatching']])."\n".$note;
    $buttons=[];
    foreach ($rows as $d) {
        $n=(int)$d['ordinal'];
        $body.=achbotMessageHtml('delivery_recovery.line',
            ['ordinal'=>$n,'state'=>$d['state'],'attempts'=>$d['attempt_count'],
            'effect'=>$d['effect_id']??0,'order'=>$d['order_id']??0])."\n";
        $group=[['text'=>achbotMessage('delivery_recovery.check'),
            'callback_data'=>'deliveryCheck_'.$payId.'_'.$n]];
        if (in_array($d['state'],['pending','unknown'],true))
            $group[]=['text'=>achbotMessage('delivery_recovery.request_send'),
                'callback_data'=>'deliveryConfirm_'.$payId.'_'.$n];
        $buttons[]=$group;
    }
    if ($more && $rows) {
        $last=end($rows);
        $buttons[]=[['text'=>achbotMessage('delivery_recovery.next'),
            'callback_data'=>'deliveryCase_'.$payId.'_'.(int)$last['ordinal']]];
    }
    if ($cursor>0) $buttons[]=[['text'=>achbotMessage('delivery_recovery.first'),
        'callback_data'=>'deliveryCase_'.$payId]];
    $buttons[]=[['text'=>achbotMessage('delivery_recovery.back_inbox'),
        'callback_data'=>'attentionInbox']];
    $reply($body,$buttons);
} catch(Throwable $error) {
    error_log('achbot_delivery_operator_unavailable pay_id='.$payId.' ordinal='.$ordinal.
        ' class='.get_class($error));
    try {
        $reply(achbotMessage('delivery_recovery.unavailable'),
            [[['text'=>achbotMessage('delivery_recovery.back_inbox'),
                'callback_data'=>'attentionInbox']]]);
    } catch(Throwable $ignored) {
        error_log('achbot_delivery_operator_ui_unconfirmed pay_id='.$payId);
    }
}
