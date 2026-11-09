<?php
/** The only automatic-gateway exception screen and its direct callbacks.
 * No separate review journal or recovery workbench is loaded here.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404); exit;
}
require_once __DIR__.'/../../Admin/Access.php';
require_once __DIR__.'/../../../messages/loader.php';

/** Telegram's 4096-character text limit applies after HTML parsing.
 * Conservatively count UTF-16 units so astral emojis cannot overflow.
 */
function achbotGatewayReviewTextLength(string $html): int {
    $plain=html_entity_decode(strip_tags($html),ENT_QUOTES|ENT_HTML5,'UTF-8');
    $characters=preg_match_all('/./us',$plain);
    $astral=preg_match_all('/[\x{10000}-\x{10FFFF}]/u',$plain);
    return $characters===false || $astral===false
        ? PHP_INT_MAX : $characters+$astral;
}

/** Protect payment/provider/Native facts and buttons; fit only display-only
 * text (including a 5000-character legacy description) into the remainder.
 * Raw persisted facts remain unchanged for all subsequent validations.
 */
function achbotGatewayReviewFitCase(string $core,array $optional,string $footer): string {
    $notice="\n".achbotMessage('automatic_gateway_review.truncated');
    // Reserve a notice even when it turns out unnecessary. This keeps the
    // budget exact for both editMessageText and fallback sendMessage.
    $budget=4096-achbotGatewayReviewTextLength($notice);
    if (achbotGatewayReviewTextLength($core.$footer)>$budget)
        throw new RuntimeException('gateway_review_essential_text_over_limit');
    $added='';$trimmed=false;
    foreach ($optional as [$key,$param,$value]) {
        $value=(string)$value;
        if ($value==='') continue;
        $line=static fn(string $v): string =>
            "\n".achbotMessageHtml($key,[$param=>$v]);
        $complete=$line($value);
        if (achbotGatewayReviewTextLength($core.$added.$complete.$footer)<=$budget) {
            $added.=$complete;
            continue;
        }
        $trimmed=true;
        $low=0;$high=mb_strlen($value,'UTF-8');
        while ($low<$high) {
            $mid=intdiv($low+$high+1,2);
            $partial=$line(mb_substr($value,0,$mid,'UTF-8').'…');
            if (achbotGatewayReviewTextLength($core.$added.$partial.$footer)<=$budget)
                $low=$mid;
            else $high=$mid-1;
        }
        if ($low>0)
            $added.=$line(mb_substr($value,0,$low,'UTF-8').'…');
    }
    $result=$core.$added.($trimmed?$notice:'').$footer;
    if (achbotGatewayReviewTextLength($result)>4096)
        throw new RuntimeException('gateway_review_message_budget_invalid');
    return $result;
}

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
    $scope=achbotGatewayAttentionScope();
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
    $safe=static fn($v) => htmlspecialchars(mb_substr((string)($v??''),0,180,'UTF-8'),
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
            // The existing immutable 007 ledger explains whether a Native
            // account was reserved/dispatched, including without a local order.
            // This is read-only case evidence, not another recovery screen.
            $native=[];$nativeTotal=0;
            if ($type==='BUY_SUB') {
                $q=$connection->prepare("SELECT COUNT(*) AS total FROM service_effects
                    WHERE pay_row_id=? AND effect_kind IN ('create','replacement')");
                $q->bind_param('i',$payId);$q->execute();
                $nativeTotal=(int)($q->get_result()->fetch_assoc()['total']??0);$q->close();
                if ($nativeTotal>0) {
                    $q=$connection->prepare("SELECT id,ordinal,state,effect_kind,operation_id,
                        remote_username,target_server_id,order_id,provider_state
                        FROM service_effects WHERE pay_row_id=?
                          AND effect_kind IN ('create','replacement')
                        ORDER BY ordinal DESC LIMIT 5");
                    $q->bind_param('i',$payId);$q->execute();
                    $native=array_reverse($q->get_result()->fetch_all(MYSQLI_ASSOC));
                    $q->close();
                }
            }
            $requested=(int)$item['request_date']>0
                ?(new DateTimeImmutable('@'.(int)$item['request_date']))
                    ->setTimezone(new DateTimeZone('Asia/Tehran'))->format('Y-m-d H:i:s')
                : (string)$item['created_at'];
            $status=(string)($item['provider_status']??'');
            $optional=[
                ['automatic_gateway_review.plan','plan',$planTitle],
                ['automatic_gateway_review.name','name',$name],
                ['automatic_gateway_review.handle','handle',$handle],
                ['automatic_gateway_review.description','description',
                    (string)($item['description']??'')],
            ];
            $body=achbotMessage('automatic_gateway_review.case_title').
                "\n\n".achbotMessageHtml('automatic_gateway_review.customer',['id'=>$item['user_id']]).
                "\n".achbotMessageHtml('automatic_gateway_review.amount',[
                    'amount'=>number_format((int)$item['price'])]).
                "\n".achbotMessageHtml('automatic_gateway_review.gateway',[
                    'provider'=>$labels[$item['provider']]??$item['provider']]).
                "\n".achbotMessageHtml('automatic_gateway_review.subject',['subject'=>$subject]).
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
            if ($type==='BUY_SUB') {
                $body.="\n".achbotMessageHtml('automatic_gateway_review.native_count',[
                    'count'=>$nativeTotal]);
                foreach ($native as $effect) {
                    $body.="\n".achbotMessageHtml('automatic_gateway_review.native_effect',[
                        'id'=>$effect['id'],'ordinal'=>$effect['ordinal'],
                        'username'=>$effect['remote_username']?:achbotMessage('automatic_gateway_review.unknown'),
                        'state'=>$effect['state'],
                        'provider_state'=>$effect['provider_state']?:achbotMessage('automatic_gateway_review.unknown'),
                        'operation'=>$effect['operation_id'],
                        'server'=>$effect['target_server_id'],
                        'order'=>$effect['order_id']?:achbotMessage('automatic_gateway_review.unknown')]);
                }
                if ($nativeTotal>count($native))
                    $body.="\n".achbotMessageHtml('automatic_gateway_review.native_more',[
                        'remaining'=>$nativeTotal-count($native)]);
                if ($nativeTotal>0)
                    $body.="\n".achbotMessage('automatic_gateway_review.native_errors');
            }
            $footer="\n\n".achbotMessage('automatic_gateway_review.warning');
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
            else $footer.="\n".achbotMessage('automatic_gateway_review.manual_hold');
            $rows[]=[['text'=>achbotMessage('automatic_gateway_review.archive'),
                'callback_data'=>'gatewayReview_H_'.$attempt]];
            $footer.="\n".achbotMessage('automatic_gateway_review.archive_notice');
            $body=achbotGatewayReviewFitCase($body,$optional,$footer);
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
if (preg_match('/^gatewayReview_([ARH])_([1-9][0-9]{0,17})$/D',
    (string)($data??''),$gatewayAction)===1) {
    if (!achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'payments') ||
        (int)($chat_id??0)!==(int)$from_id) {
        answerCallbackQuery($callbackId,achbotMessage('automatic_gateway_review.denied'),true);
        return;
    }
    $archive=$gatewayAction[1]==='H';
    require_once __DIR__.'/../PaymentLifecycle.php';
    if (!$archive) {
        if (!defined('ACHBOT_NP_RECONCILE_ONLY')) define('ACHBOT_NP_RECONCILE_ONLY',true);
        require_once __DIR__.'/../GatewayReturn.php';
    }
    require_once __DIR__.'/../GatewayReview.php';
    $mode=$gatewayAction[1]==='A'?'approve':'reject';
    $attempt=(int)$gatewayAction[2];
    $keys=json_encode(['inline_keyboard'=>[[[
        'text'=>achbotMessage('automatic_gateway_review.back_list'),
        'callback_data'=>'attentionInbox',
    ]]]],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    try {
        answerCallbackQuery($callbackId,achbotMessage('automatic_gateway_review.checking'));
        $result=$archive
            ?['status'=>achbotGatewayReviewArchive(
                $connection,(int)$from_id,$attempt,(int)$admin)]
            :achbotGatewayReviewCommit(
                $connection,(int)$from_id,$attempt,$mode,(int)$admin);
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
            'archived'=>achbotMessage('automatic_gateway_review.status_archived'),
            'busy'=>achbotMessage('automatic_gateway_review.status_busy'),
            'unknown'=>achbotMessage('automatic_gateway_review.status_unknown'),
            'stale'=>achbotMessage('automatic_gateway_review.status_stale'),
            'denied'=>achbotMessage('automatic_gateway_review.denied'),
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
