<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/../../Services/Resolution.php';
require_once __DIR__.'/../../Telegram/Conversation.php';
require_once __DIR__.'/../CheckoutRoutes.php';

$actor=(int)$from_id;$primary=(int)$admin;
if (!achbotAdminCanFor($connection,$actor,$primary,'payments') || (int)($chat_id??0)!==$actor) {
    answerCallbackQuery($callbackId,achbotMessage('automatic_gateway_review.denied'),true);return;
}
if (!preg_match('/^attentionService(?:(Resume|CloseC|CloseS|RefundC|RefundS|Page))?_([1-9][0-9]{0,17})(?:_([a-f0-9]{20}|[1-9][0-9]{0,8}))?$/D',
    (string)$data,$m)) return;
$id=(int)$m[2];$action=$m[1];$suffix=$m[3]??null;
$back='attentionService_'.$id;
$render=static function(string $body,array $rows) use ($message_id,$actor): void {
    $rows[]=[['text'=>achbotMessage('automatic_gateway_review.back_list'),'callback_data'=>'attentionInbox']];
    $keys=json_encode(['inline_keyboard'=>$rows],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    \AchBot\Telegram\Conversation\replaceTransient($actor,(int)$message_id,$body,$keys);
};
try {
    $op=achbotProviderRow($connection,'SELECT * FROM service_operations WHERE id=?','i',[$id]);
    if (!$op) throw new DomainException('service_resolution_stale');
    if ($action==='Resume') {
        if ($suffix!==null || !in_array($op['stage'],['funded','prepared','dispatching','applied'],true))
            throw new DomainException('service_resolution_stale');
        answerCallbackQuery($callbackId,achbotMessage('automatic_gateway_review.checking'));
        achbotCheckoutAdvance($connection,$id,null,true);
        $op=achbotProviderRow($connection,'SELECT * FROM service_operations WHERE id=?','i',[$id]);
        $action='';
    }
    if (in_array($action,['CloseC','CloseS','RefundC','RefundS'],true)) {
        $decision=str_starts_with($action,'Close')?'closed':'refunded';
        $plan=achbotServiceResolutionPreview($connection,$id,$actor,$primary);
        if (!$plan || !($decision==='closed'?$plan['can_close']:$plan['can_refund']))
            throw new DomainException('service_resolution_stale');
        $token=achbotServiceResolutionToken($plan,$actor,$decision);
        $rows=[];
        if (str_ends_with($action,'C') && $suffix===null) {
            $body=$decision==='closed'?achbotMessage('automatic_gateway_review.service_close_confirm'):
                achbotMessageHtml('automatic_gateway_review.service_refund_confirm',['amount'=>number_format($plan['refund'])]);
            if ($decision==='refunded') foreach ($plan['unlinked'] as $e)
                $body.="\n".achbotMessageHtml('automatic_gateway_review.service_unlinked',['name'=>$e['username']]);
            $callback='attentionService'.($decision==='closed'?'CloseS':'RefundS').'_'.$id.'_'.$token;
            if (strlen($callback)>64) throw new RuntimeException('service_resolution_callback_limit');
            $rows[]=[['text'=>achbotMessage('automatic_gateway_review.service_confirm'),'callback_data'=>$callback]];
            answerCallbackQuery($callbackId);
        } elseif (str_ends_with($action,'S') && is_string($suffix) && hash_equals($token,$suffix)) {
            answerCallbackQuery($callbackId,achbotMessage('automatic_gateway_review.checking'));
            $r=achbotServiceResolve($connection,$id,$actor,$primary,$decision,$token);
            $body=match($r['status']) {
                'closed'=>achbotMessageHtml('automatic_gateway_review.resolution_closed_notice',['id'=>$id]),
                'refunded'=>$op['kind']==='BUY'?
                    achbotMessageHtml('automatic_gateway_review.resolution_buy_notice',['id'=>$id,'retained'=>$r['retained'],
                        'cancelled'=>$r['cancelled'],'amount'=>number_format($r['refund'])]):
                    achbotMessageHtml('automatic_gateway_review.resolution_refunded_notice',['id'=>$id,'amount'=>number_format($r['refund'])]),
                'account_exists'=>achbotMessageHtml('automatic_gateway_review.service_account_exists',['name'=>$r['username']]),
                'service_applied'=>achbotMessage('automatic_gateway_review.service_applied'),
                'busy'=>achbotMessage('automatic_gateway_review.redrive_busy'),
                'stale'=>achbotMessage('automatic_gateway_review.status_stale'),
                'denied'=>achbotMessage('automatic_gateway_review.denied'),
                default=>achbotMessage('automatic_gateway_review.status_unavailable'),
            };
            if (in_array($r['status'],['closed','refunded'],true)) achbotCheckoutAdvance($connection,$id);
        } else throw new DomainException('service_resolution_stale');
        $rows[]=[['text'=>achbotMessage('automatic_gateway_review.redrive_cancel'),'callback_data'=>$back]];
        $render($body,$rows);return;
    }
    if ($action!=='' && $action!=='Page') throw new DomainException('service_resolution_stale');
    $kind=match($op['kind']) {
        'BUY'=>achbotMessage('labels.labels.payment_type.label.e6343571a4'),
        'RENEW'=>achbotMessage('labels.labels.payment_type.label.df1c62b99e'),
        'ADD_TIME'=>achbotMessage('automatic_gateway_review.days'),
        'ADD_VOLUME'=>achbotMessage('labels.labels.payment_type.label.160a34aa6d'),
    };
    $body=achbotMessageHtml('automatic_gateway_review.service_title',[
        'id'=>$id,'user'=>$op['userid'],'kind'=>$kind,
        'amount'=>number_format((int)$op['price_toman']),'stage'=>$op['resolution_kind']??$op['stage'],
        'error'=>$op['failure_code']??'—','updated'=>$op['updated_at']]);
    $q=json_decode($op['quote_payload'],true,64,JSON_THROW_ON_ERROR)['request'];
    if ((int)$op['subject_order_id']>0) {
        $body.="
".achbotMessageHtml('automatic_gateway_review.order',['id'=>$op['subject_order_id']]).
            "
".achbotMessageHtml('automatic_gateway_review.username',['username'=>$q['remark']]);
    }
    $rows=[];
    if (in_array($op['stage'],['funded','prepared','dispatching','applied'],true)) {
        $rows[]=[['text'=>achbotMessage('automatic_gateway_review.service_continue'),'callback_data'=>'attentionServiceResume_'.$id]];
        try {$plan=achbotServiceResolutionPreview($connection,$id,$actor,$primary);}
        catch (Throwable $e) {
            $plan=null;
            achbotDiagnosticEvent('achbot.service.attention_preview',['service_operation_id'=>$id,
                'reason'=>achbotServiceExecutionReason($e),'class'=>get_class($e)],'error');
        }
        if (!$plan) $body.="
".achbotMessage('automatic_gateway_review.unavailable');
        if ($plan && $op['kind']==='BUY') $body.="\n".achbotMessageHtml('automatic_gateway_review.service_details',[
            'quantity'=>$plan['retained']+$plan['cancel_count'],'retained'=>$plan['retained'],'amount'=>number_format($plan['refund'])]);
        if ($plan && $plan['can_close']) $rows[]=[['text'=>achbotMessage('automatic_gateway_review.service_close'),'callback_data'=>'attentionServiceCloseC_'.$id]];
        if ($plan && $plan['can_refund']) $rows[]=[['text'=>achbotMessage('automatic_gateway_review.service_refund'),'callback_data'=>'attentionServiceRefundC_'.$id]];
    }
    $offset=$action==='Page'?(int)$suffix:0;
    $s=achbotProviderStatement($connection,"SELECT * FROM (
        SELECT d.id,p.part_no,d.ordinal,'delivery' AS source FROM payment_delivery_intents d
            JOIN payment_delivery_parts p ON p.intent_id=d.id WHERE d.service_operation_id=?
            AND p.state IN ('unknown','rejected')
        UNION ALL
        SELECT f.id,0,0,'report' FROM payment_followup_intents f
            WHERE f.service_operation_id=? AND f.state IN ('unknown','rejected')
        ) AS messages ORDER BY source,id,part_no LIMIT 11 OFFSET ?",'iii',[$id,$id,$offset]);
    $items=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
    foreach (array_slice($items,0,10) as $item) {
        $delivery=$item['source']==='delivery';
        $rows[]=[['text'=>achbotMessage($delivery?'automatic_gateway_review.redrive_action':'automatic_gateway_review.wallet_report_action').
            ' #'.($delivery?$item['ordinal'].'.'.$item['part_no']:$item['id']),
            'callback_data'=>$delivery?'attentionDeliveryC_'.$item['id'].'_'.$item['part_no']:'attentionReportC_'.$item['id']]];
    }
    if ($offset>0) $rows[]=[['text'=>achbotMessage('automatic_gateway_review.newest'),'callback_data'=>$back]];
    if (count($items)>10) $rows[]=[['text'=>achbotMessage('automatic_gateway_review.older'),
        'callback_data'=>'attentionServicePage_'.$id.'_'.($offset+10)]];
    answerCallbackQuery($callbackId);$render($body,$rows);
} catch (Throwable $e) {
    achbotDiagnosticEvent('achbot.service.attention',['service_operation_id'=>$id,'actor_id'=>$actor,
        'reason'=>achbotServiceExecutionReason($e),'class'=>get_class($e)],'error');
    answerCallbackQuery($callbackId,achbotMessage('automatic_gateway_review.unavailable'),true);
}
