<?php
/** The only automatic-gateway exception screen and its direct callbacks.
 * No separate review journal or recovery workbench is loaded here.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404); exit;
}
require_once __DIR__.'/../../Admin/Access.php';
require_once __DIR__.'/../../../messages/loader.php';

// Only unfinished automatic-gateway payments are shown here.
if (preg_match('/^attention(?:Inbox(?:_[1-9][0-9]{0,17})?|Case_[1-9][0-9]{0,17})$/D',
    (string)($data??''))===1) {
    require_once __DIR__.'/../PaymentLifecycle.php';
    require_once __DIR__.'/../GatewayReview.php';

    if (!achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'payments') ||
        (int)($chat_id??0)!==(int)$from_id) {
        answerCallbackQuery($callbackId,achbotMessage('automatic_gateway_review.denied'),true);
        return;
    }

    // An old unpaid checkout is only a review *candidate*, never proof of funds.
    // The existing configurable hosted-attention age also controls this list.
    $threshold=achbotPayUxHostedAttentionSeconds();
    $scope="a.provider IN ('nowpayments','nextpay','zarinpal')
        AND (
            (p.state IN ('pending','send') AND (
                (a.stage='review' AND (
                    (a.provider IN ('zarinpal','nextpay') AND
                     a.provider_status='verification_uncertain')
                    OR (a.provider='nowpayments' AND (
                        a.provider_status IS NULL OR a.provider_status NOT IN (
                        'fulfillment_started','fulfillment_unconfirmed',
                        'fulfillment_failed','fulfillment_needs_review',
                        'operator_attested','operator_verified_finished',
                        'operator_rejected','operator_compensated',
                        'operator_fulfilled')))
                    OR a.provider_status='operator_fulfillment_unconfirmed'
                    OR (a.provider_status IN
                         ('fulfillment_failed','fulfillment_needs_review',
                          'fulfillment_unconfirmed')
                        AND a.updated_at <= NOW()-INTERVAL 10 MINUTE)))
                OR (a.stage='pending' AND a.provider_status IS NULL
                    AND a.created_at <= NOW()-INTERVAL ".(int)$threshold." SECOND)
                OR (a.provider='nowpayments' AND a.stage='checking'
                    AND a.last_checked_at IS NOT NULL
                    AND a.last_checked_at < NOW()-INTERVAL 10 MINUTE)
                OR (a.stage='fulfilling' AND
                    a.updated_at <= NOW()-INTERVAL 10 MINUTE)))
            OR (p.state IN ('processing','paid') AND
                a.stage IN ('review','fulfilling') AND
                a.provider_status IN ('fulfillment_started',
                    'fulfillment_unconfirmed','fulfillment_failed',
                    'fulfillment_needs_review',
                    'operator_fulfillment_unconfirmed') AND
                a.updated_at <= NOW()-INTERVAL 10 MINUTE))";
    $inbox=[];$case=[];
    $isInbox=preg_match('/^attentionInbox(?:_([1-9][0-9]{0,17}))?$/D',
        (string)$data,$inbox)===1;
    $isCase=preg_match('/^attentionCase_([1-9][0-9]{0,17})$/D',
        (string)$data,$case)===1;
    if (!$isInbox && !$isCase) return;

    $send=static function(string $message,array $rows) use ($message_id,$from_id): void {
        $markup=json_encode(['inline_keyboard'=>$rows],
            JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $result=editText($message_id,$message,$markup,'HTML',(int)$from_id);
        if (!is_object($result) || ($result->ok??false)!==true)
            sendMessage($message,$markup,'HTML',(int)$from_id);
    };
    $labels=[
        'nowpayments'=>achbotMessage('automatic_gateway_review.provider_nowpayments'),
        'zarinpal'=>achbotMessage('automatic_gateway_review.provider_zarinpal'),
        'nextpay'=>achbotMessage('automatic_gateway_review.provider_nextpay'),
    ];
    $safe=static fn($v) => htmlspecialchars(substr((string)($v??''),0,180),
        ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    try {
        $rows=[];
        if ($isInbox) {
            $before=(int)($inbox[1]??0);
            $items=[];$more=false;
            if (achbotPayUxTable($connection,'payment_attempts')) {
                $q=$connection->prepare("SELECT p.id,p.user_id,p.price,a.provider
                    FROM pays p INNER JOIN payment_attempts a ON a.pay_row_id=p.id
                    WHERE $scope AND (?=0 OR p.id<?)
                    ORDER BY p.id DESC LIMIT 13");
                $q->bind_param('ii',$before,$before);
                $q->execute();$items=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
                $more=count($items)>12;$items=array_slice($items,0,12);
            }
            foreach ($items as $item) {
                $label=number_format((int)$item['price']).' '.
                    achbotMessage('automatic_gateway_review.toman').' | '.
                    (int)$item['user_id'].' | '.
                    ($labels[$item['provider']]??$safe($item['provider']));
                $rows[]=[['text'=>$label,'callback_data'=>'attentionCase_'.(int)$item['id']]];
            }
            if ($more && $items) $rows[]=[['text'=>achbotMessage('automatic_gateway_review.older'),
                'callback_data'=>'attentionInbox_'.(int)end($items)['id']]];
            if ($before>0) $rows[]=[['text'=>achbotMessage('automatic_gateway_review.newest'),
                'callback_data'=>'attentionInbox']];
            $rows[]=[['text'=>achbotMessage('automatic_gateway_review.back_admin'),
                'callback_data'=>'managePanel']];
            $body=achbotMessage('automatic_gateway_review.title');
            if (!$items) $body.="\n".achbotMessage('automatic_gateway_review.empty');
        } else {
            $payId=(int)$case[1];
            $q=$connection->prepare("SELECT p.id,p.user_id,p.hash_id,p.type,p.price,p.state,
                p.plan_id,p.order_id,p.server_id,p.day,p.volume,p.description,\n                p.agent_count,p.request_date,
                a.id AS attempt_id,a.provider,a.order_hash,a.provider_reference,
                a.payment_id,a.quote_amount,a.quote_currency,a.stage,a.provider_status,
                a.created_at,a.updated_at,a.last_checked_at
                FROM pays p INNER JOIN payment_attempts a ON a.pay_row_id=p.id
                WHERE p.id=? AND $scope LIMIT 1");
            $q->bind_param('i',$payId);$q->execute();
            $item=$q->get_result()->fetch_assoc();$q->close();
            if (!$item) {
                $send(achbotMessage('automatic_gateway_review.resolved'),
                    [[['text'=>achbotMessage('automatic_gateway_review.back_list'),
                        'callback_data'=>'attentionInbox']]]);
                answerCallbackQuery($callbackId);
                return;
            }
            // Customer-facing display names are context, never authorization.
            $name='';
            $handle='';
            $q=$connection->prepare('SELECT name,username FROM users WHERE userid=? LIMIT 1');
            $uid=(int)$item['user_id'];$q->bind_param('i',$uid);$q->execute();
            $person=$q->get_result()->fetch_assoc();$q->close();
            if ($person) {
                $name=(string)($person['name']??'');
                $handle=(string)($person['username']??'');
            }
            // Recorded merchant observations, not new network verification.
            $latest=[];
            $q=$connection->prepare('SELECT payment_id,observed_status,received_at
                FROM payment_transactions WHERE attempt_id=? AND provider=?
                ORDER BY id DESC LIMIT 2');
            $attempt=(int)$item['attempt_id'];$provider=(string)$item['provider'];
            $q->bind_param('is',$attempt,$provider);$q->execute();
            $latest=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
            $type=(string)$item['type'];
            $subject=match(true) {
                $type==='BUY_SUB' => achbotMessage('automatic_gateway_review.purchase'),
                $type==='RENEW_ACCOUNT'||$type==='RENEW_SCONFIG' =>
                    achbotMessage('automatic_gateway_review.renew'),
                $type==='INCREASE_WALLET' => achbotMessage('automatic_gateway_review.wallet'),
                str_starts_with($type,'INCREASE_DAY_') =>
                    achbotMessage('automatic_gateway_review.days'),
                str_starts_with($type,'INCREASE_VOLUME_') =>
                    achbotMessage('automatic_gateway_review.volume'),
                default => $safe($type),
            };
            $orderId=(int)($item['order_id']??0);
            if ($orderId<=0 && preg_match('/^INCREASE_(?:DAY|VOLUME)_([1-9][0-9]*)_/D',$type,$m))
                $orderId=(int)$m[1];
            $orderName='';
            if ($orderId>0) {
                $q=$connection->prepare('SELECT remark FROM orders_list
                    WHERE id=? AND userid=? LIMIT 1');
                $user=(int)$item['user_id'];
                $q->bind_param('ii',$orderId,$user);
                $q->execute();$r=$q->get_result()->fetch_assoc();$q->close();
                $orderName=(string)($r['remark']??'');
            }
            $planTitle='';
            if ((int)$item['plan_id']>0) {
                $q=$connection->prepare('SELECT title FROM server_plans WHERE id=? LIMIT 1');
                $pid=(int)$item['plan_id'];$q->bind_param('i',$pid);$q->execute();
                $r=$q->get_result()->fetch_assoc();$q->close();
                $planTitle=(string)($r['title']??'');
            }
            $requested=(int)$item['request_date']>0
                ?(new DateTimeImmutable('@'.(int)$item['request_date']))
                    ->setTimezone(new DateTimeZone('Asia/Tehran'))->format('Y-m-d H:i:s')
                : (string)$item['created_at'];
            $status=(string)($item['provider_status']??'');
            $body=achbotMessage('automatic_gateway_review.case_title').
                "\n\n".achbotMessageHtml('automatic_gateway_review.customer',['id'=>$item['user_id']]).
                ($name!==''?"\n".achbotMessageHtml('automatic_gateway_review.name',['name'=>$name]):'').
                ($handle!==''?"\n".achbotMessageHtml('automatic_gateway_review.handle',['handle'=>$handle]):'').
                "\n".achbotMessageHtml('automatic_gateway_review.amount',[
                    'amount'=>number_format((int)$item['price'])]).
                "\n".achbotMessageHtml('automatic_gateway_review.gateway',[
                    'provider'=>$labels[$item['provider']]??$item['provider']]).
                "\n".achbotMessageHtml('automatic_gateway_review.subject',['subject'=>$subject]).
                ($planTitle!==''?"\n".achbotMessageHtml('automatic_gateway_review.plan',
                    ['plan'=>$planTitle]):'').
                ($orderName!==''?"\n".achbotMessageHtml('automatic_gateway_review.username',
                    ['username'=>$orderName]):'').
                ($orderId>0?"\n".achbotMessageHtml('automatic_gateway_review.order',
                    ['id'=>$orderId]):'').
                (($type==='BUY_SUB' && (int)$item['agent_count']>1)
                    ?"\n".achbotMessageHtml('automatic_gateway_review.quantity',
                        ['quantity'=>$item['agent_count']]):'').
                (($type==='BUY_SUB' || $type==='RENEW_SCONFIG')
                    && (int)$item['day']>0?"\n".achbotMessageHtml(
                        'automatic_gateway_review.duration',['days'=>$item['day']]):'').
                (($type==='BUY_SUB' || $type==='RENEW_SCONFIG')
                    && (int)$item['volume']>0?"\n".achbotMessageHtml(
                        'automatic_gateway_review.quota',['gb'=>$item['volume']]):'').
                ((string)($item['description']??'')!==''?"\n".achbotMessageHtml(
                    'automatic_gateway_review.description',['description'=>$item['description']]):'').
                "\n".achbotMessageHtml('automatic_gateway_review.request_time',
                    ['date'=>$requested]).
                "\n".achbotMessageHtml('automatic_gateway_review.merchant_quote',[
                    'amount'=>$item['quote_amount'],'currency'=>$item['quote_currency']]).
                "\n".achbotMessageHtml('automatic_gateway_review.invoice',
                    ['reference'=>$item['provider_reference']]).
                "\n".achbotMessageHtml('automatic_gateway_review.payment',
                    ['reference'=>$item['payment_id']?:achbotMessage('automatic_gateway_review.unknown')]).
                "\n".achbotMessageHtml('automatic_gateway_review.reference',
                    ['hash'=>$item['hash_id']]).
                "\n".achbotMessageHtml('automatic_gateway_review.status',[
                    'stage'=>$item['state'].' / '.$item['stage'],
                    'status'=>$status!==''?$status:
                        achbotMessage('automatic_gateway_review.unknown')]).
                "\n".achbotMessageHtml('automatic_gateway_review.updated',[
                    'date'=>$item['updated_at']]);
            foreach ($latest as $observed) {
                $body.="\n".achbotMessageHtml('automatic_gateway_review.merchant_observation',[
                    'payment'=>$observed['payment_id'],
                    'status'=>$observed['observed_status'],
                    'date'=>$observed['received_at']]);
            }
            $body.="\n\n".achbotMessage('automatic_gateway_review.warning');
            $attempt=(int)$item['attempt_id'];
            // A merchant-attested decision still enforces the existing one-shot
            // payment/reference/native-effect checks on execution.
            $actions=[];
            if (achbotGatewayReviewEligible($connection,$attempt,'approve'))
                $actions[]=['text'=>achbotMessage('automatic_gateway_review.approve'),
                    'callback_data'=>'gatewayReview_A_'.$attempt];
            if (achbotGatewayReviewEligible($connection,$attempt,'reject'))
                $actions[]=['text'=>achbotMessage('automatic_gateway_review.reject'),
                    'callback_data'=>'gatewayReview_R_'.$attempt];
            if ($actions) $rows[]=$actions;
            else $body.="\n".achbotMessage('automatic_gateway_review.manual_hold');
            $rows[]=[['text'=>achbotMessage('automatic_gateway_review.back_list'),
                'callback_data'=>'attentionInbox']];
        }
        answerCallbackQuery($callbackId);
        $send($body,$rows);
    } catch (Throwable $error) {
        error_log('achbot_automatic_gateway_inbox_unavailable class='.get_class($error));
        answerCallbackQuery($callbackId,achbotMessage('automatic_gateway_review.unavailable'),true);
    }
    return;
}


// Same original financial effect, with one-shot provider-attempt claim.
$gatewayAction=[];
if (preg_match('/^gatewayReview_([AR])_([1-9][0-9]{0,17})$/D',
    (string)($data??''),$gatewayAction)===1) {
    if (!achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'payments') ||
        (int)($chat_id??0)!==(int)$from_id) {
        answerCallbackQuery($callbackId,achbotMessage('automatic_gateway_review.denied'),true);
        return;
    }
    if (!defined('ACHBOT_NP_RECONCILE_ONLY')) define('ACHBOT_NP_RECONCILE_ONLY',true);
    require_once __DIR__.'/../GatewayReturn.php';
    require_once __DIR__.'/../GatewayReview.php';
    $mode=$gatewayAction[1]==='A'?'approve':'reject';
    $attempt=(int)$gatewayAction[2];
    $keys=json_encode(['inline_keyboard'=>[[[
        'text'=>achbotMessage('automatic_gateway_review.back_list'),
        'callback_data'=>'attentionInbox',
    ]]]],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    try {
        answerCallbackQuery($callbackId,achbotMessage('automatic_gateway_review.checking'));
        $result=achbotGatewayReviewCommit($connection,(int)$from_id,$attempt,$mode);
        $status=(string)($result['status']??'unavailable');
        if ($status==='rejected') {
            // Closing the business order does not refund the merchant.
            $pay=$result['pay'];
            $params=['id'=>$attempt,'user'=>(int)$pay['user_id'],
                'amount'=>number_format((int)$pay['price']),
                'hash'=>(string)$pay['hash_id']];
            try {
                sendMessage(achbotMessageHtml('automatic_gateway_review.rejected_customer',[
                    'hash'=>$params['hash'],'amount'=>$params['amount']]),
                    null,'HTML',(int)$pay['user_id']);
            } catch(Throwable $error) {
                error_log('achbot_gateway_reject_customer_notice_unknown pay_id='.
                    (int)$pay['id']);
            }
            try {
                notifyAllAdminsSimple(achbotMessageHtml('automatic_gateway_review.rejected_report',
                    $params),(int)$admin,'html',null,$botState['rewardChannel']??null);
            } catch(Throwable $error) {
                error_log('achbot_gateway_reject_report_unknown pay_id='.(int)$pay['id']);
            }
        }
        $text=match($status) {
            'completed'=>achbotMessage('automatic_gateway_review.status_completed'),
            'rejected'=>achbotMessage('automatic_gateway_review.status_rejected'),
            'unknown'=>achbotMessage('automatic_gateway_review.status_unknown'),
            'stale'=>achbotMessage('automatic_gateway_review.status_stale'),
            'unsupported'=>achbotMessage('automatic_gateway_review.status_unsupported'),
            default=>achbotMessage('automatic_gateway_review.status_unavailable'),
        };
        $body=achbotMessageHtml('automatic_gateway_review.result',['result'=>$text]);
        $sent=editText($message_id,$body,$keys,'HTML',(int)$from_id);
        if (!is_object($sent)||($sent->ok??false)!==true)
            sendMessage($body,$keys,'HTML',(int)$from_id);
    } catch(Throwable $error) {
        error_log('achbot_gateway_review_callback_error class='.get_class($error));
        $body=achbotMessage('automatic_gateway_review.unavailable');
        try {
            $sent=editText($message_id,$body,$keys,'HTML',(int)$from_id);
            if (!is_object($sent)||($sent->ok??false)!==true)
                sendMessage($body,$keys,'HTML',(int)$from_id);
        } catch(Throwable $ignored) {}
    }
    return;
}


return 1;
