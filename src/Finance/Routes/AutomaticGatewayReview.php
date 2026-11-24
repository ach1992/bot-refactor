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
$gatewayTextLength=static function(string $html): int {
    $plain=html_entity_decode(strip_tags($html),ENT_QUOTES|ENT_HTML5,'UTF-8');
    $characters=preg_match_all('/./us',$plain);
    $astral=preg_match_all('/[\x{10000}-\x{10FFFF}]/u',$plain);
    return $characters===false || $astral===false
        ? PHP_INT_MAX : $characters+$astral;
};

/** Protect payment/provider/Native facts and buttons; fit only display-only
 * text (including a 5000-character legacy description) into the remainder.
 * Raw persisted facts remain unchanged for all subsequent validations.
 */
$gatewayFitCase=static function(string $core,array $optional,string $footer) use ($gatewayTextLength): string {
    $notice="\n".achbotMessage('automatic_gateway_review.truncated');
    // Reserve a notice even when it turns out unnecessary. This keeps the
    // budget exact for both editMessageText and fallback sendMessage.
    $budget=4096-$gatewayTextLength($notice);
    if ($gatewayTextLength($core.$footer)>$budget)
        throw new RuntimeException('gateway_review_essential_text_over_limit');
    $added='';$trimmed=false;
    foreach ($optional as [$key,$param,$value]) {
        $value=(string)$value;
        if ($value==='') continue;
        $line=static fn(string $v): string =>
            "\n".achbotMessageHtml($key,[$param=>$v]);
        $complete=$line($value);
        if ($gatewayTextLength($core.$added.$complete.$footer)<=$budget) {
            $added.=$complete;
            continue;
        }
        $trimmed=true;
        $low=0;$high=mb_strlen($value,'UTF-8');
        while ($low<$high) {
            $mid=intdiv($low+$high+1,2);
            $partial=$line(mb_substr($value,0,$mid,'UTF-8').'…');
            if ($gatewayTextLength($core.$added.$partial.$footer)<=$budget)
                $low=$mid;
            else $high=$mid-1;
        }
        if ($low>0)
            $added.=$line(mb_substr($value,0,$low,'UTF-8').'…');
    }
    $result=$core.$added.($trimmed?$notice:'').$footer;
    if ($gatewayTextLength($result)>4096)
        throw new RuntimeException('gateway_review_message_budget_invalid');
    return $result;
};

// One current service operation and its immutable delivery/followup packets.
require_once __DIR__.'/../CheckoutRoutes.php';
require_once __DIR__.'/../GatewayReview.php';
require_once __DIR__.'/../ServiceDelivery.php';
require_once __DIR__.'/../FollowupAttempts.php';

$reviewAction=[];
if (preg_match('/^attentionWalletResume_([1-9][0-9]{0,17})$/D',(string)($data??''),$reviewAction)) {
    if (!achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'payments') ||
        (int)($chat_id??0)!==(int)$from_id) {
        answerCallbackQuery($callbackId,achbotMessage('automatic_gateway_review.denied'),true);return;
    }
    $payId=(int)$reviewAction[1];
    try {
        $p=achbotProviderRow($connection,'SELECT checkout_operation_id FROM pays WHERE id=?','i',[$payId]);
        if (!$p || !achbotGatewayAttentionCaseOpen($connection,$payId)) {
            answerCallbackQuery($callbackId,achbotMessage('automatic_gateway_review.status_stale'),true);return;
        }
        $opId=(int)$p['checkout_operation_id'];
        answerCallbackQuery($callbackId,achbotMessage('automatic_gateway_review.checking'));
        achbotCheckoutAdvance($connection,$opId>0?$opId:null,$payId);
        $data='attentionCase_'.$payId;
    } catch (Throwable $e) {
        answerCallbackQuery($callbackId,achbotMessage('automatic_gateway_review.unavailable'),true);return;
    }
}

$reviewAction=[];
if (preg_match('/^attentionDelivery([CS])_([1-9][0-9]{0,17})_([1-9][0-9]{0,8})(?:_([a-f0-9]{20}))?$/D',
    (string)($data??''),$reviewAction)) {
    if (!achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'payments') ||
        (int)($chat_id??0)!==(int)$from_id) {
        answerCallbackQuery($callbackId,achbotMessage('automatic_gateway_review.denied'),true);return;
    }
    $id=(int)$reviewAction[2];$part=(int)$reviewAction[3];$actor=(int)$from_id;
    try {
        $d=achbotProviderRow($connection,'SELECT * FROM payment_delivery_intents WHERE id=?','i',[$id]);
        $p=achbotProviderRow($connection,'SELECT * FROM payment_delivery_parts WHERE intent_id=? AND part_no=?','ii',[$id,$part]);
        $pay=$d?achbotProviderRow($connection,'SELECT id FROM pays WHERE checkout_operation_id=?','i',[(int)$d['service_operation_id']]):null;
        if (!$d || !$p || !$pay || !achbotGatewayAttentionCaseOpen($connection,(int)$pay['id']))
            throw new RuntimeException('delivery_review_stale');
        $token=achbotDeliveryPartRedriveToken($d,$p,$actor);
        $rows=[];$payId=(int)$pay['id'];
        if ($reviewAction[1]==='C' && !isset($reviewAction[4])) {
            $body=achbotMessageHtml('automatic_gateway_review.redrive_confirm',[
                'user'=>$d['user_id'],'payment'=>$payId,'ordinal'=>$d['ordinal'].'.'.$part]);
            $callback='attentionDeliveryS_'.$id.'_'.$part.'_'.$token;
            if (strlen($callback)>64) throw new RuntimeException('delivery_callback_limit');
            $rows[]=[['text'=>achbotMessage('automatic_gateway_review.redrive_send'),'callback_data'=>$callback]];
        } elseif ($reviewAction[1]==='S' && hash_equals($token,(string)($reviewAction[4]??''))) {
            answerCallbackQuery($callbackId,achbotMessage('automatic_gateway_review.checking'));
            $status=achbotServiceDeliveryDispatch($connection,$id,$actor,$part,$token);
            $body=achbotMessage(match($status) {
                'acknowledged'=>'automatic_gateway_review.redrive_ack',
                'unknown'=>'automatic_gateway_review.redrive_unknown',
                'busy'=>'automatic_gateway_review.redrive_busy',
                default=>'automatic_gateway_review.redrive_stale',
            });
        } else throw new RuntimeException('delivery_review_stale');
        $rows[]=[['text'=>achbotMessage('automatic_gateway_review.redrive_cancel'),'callback_data'=>'attentionCase_'.$payId]];
        $keys=json_encode(['inline_keyboard'=>$rows],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $edited=editText($message_id,$body,$keys,'HTML',$actor);
        if (!is_object($edited)||($edited->ok??false)!==true) sendMessage($body,$keys,'HTML',$actor);
        if ($reviewAction[1]==='C') answerCallbackQuery($callbackId);
    } catch (Throwable $e) {
        answerCallbackQuery($callbackId,achbotMessage('automatic_gateway_review.redrive_unavailable'),true);
    }
    return;
}

$reviewAction=[];
if (preg_match('/^attentionReport([CS])_([1-9][0-9]{0,17})(?:_([a-f0-9]{20}))?$/D',
    (string)($data??''),$reviewAction)) {
    if (!achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'payments') ||
        (int)($chat_id??0)!==(int)$from_id) {
        answerCallbackQuery($callbackId,achbotMessage('automatic_gateway_review.denied'),true);return;
    }
    $id=(int)$reviewAction[2];$actor=(int)$from_id;
    try {
        $r=achbotProviderRow($connection,'SELECT * FROM payment_followup_intents WHERE id=?','i',[$id]);
        $pay=$r?achbotProviderRow($connection,'SELECT id,user_id FROM pays WHERE id=? OR checkout_operation_id=? LIMIT 1',
            'ii',[(int)$r['pay_row_id'],(int)$r['service_operation_id']]):null;
        if (!$r || !$pay || !achbotGatewayAttentionCaseOpen($connection,(int)$pay['id']))
            throw new RuntimeException('followup_review_stale');
        $token=achbotFollowupRedriveToken($r,$actor);$payId=(int)$pay['id'];$rows=[];
        if ($reviewAction[1]==='C' && !isset($reviewAction[3])) {
            $body=achbotMessage('automatic_gateway_review.archive_notice');
            $rows[]=[['text'=>achbotMessage('automatic_gateway_review.redrive_send'),
                'callback_data'=>'attentionReportS_'.$id.'_'.$token]];
        } elseif ($reviewAction[1]==='S' && hash_equals($token,(string)($reviewAction[3]??''))) {
            answerCallbackQuery($callbackId,achbotMessage('automatic_gateway_review.checking'));
            $status=achbotFollowupDispatchOne($connection,$id,$actor,$token);
            $body=achbotMessage(match($status) {
                'acknowledged'=>'automatic_gateway_review.status_completed',
                'unknown'=>'automatic_gateway_review.status_unknown',
                'busy'=>'automatic_gateway_review.status_busy',
                default=>'automatic_gateway_review.status_stale',
            });
        } else throw new RuntimeException('followup_review_stale');
        $rows[]=[['text'=>achbotMessage('automatic_gateway_review.redrive_cancel'),'callback_data'=>'attentionCase_'.$payId]];
        $keys=json_encode(['inline_keyboard'=>$rows],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $edited=editText($message_id,$body,$keys,'HTML',$actor);
        if (!is_object($edited)||($edited->ok??false)!==true) sendMessage($body,$keys,'HTML',$actor);
        if ($reviewAction[1]==='C') answerCallbackQuery($callbackId);
    } catch (Throwable $e) {
        answerCallbackQuery($callbackId,achbotMessage('automatic_gateway_review.redrive_unavailable'),true);
    }
    return;
}

if (preg_match('/^attention(?:Inbox(?:_[1-9][0-9]{0,17})?|Case_[1-9][0-9]{0,17})$/D',
    (string)($data??''))===1) {
    require_once __DIR__.'/../PaymentLifecycle.php';
    require_once __DIR__.'/../GatewayReview.php';
    require_once __DIR__.'/../ApiPayments.php';

    if (!achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'payments') ||
        (int)($chat_id??0)!==(int)$from_id) {
        answerCallbackQuery($callbackId,achbotMessage('automatic_gateway_review.denied'),true);
        return;
    }

    // An old unpaid checkout is not itself an exception. Admission uses
    // original provider evidence or a previously started unresolved effect;
    // elapsed time alone never creates a staff action.
    $scope=achbotGatewayAttentionScope($connection);
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
        'bank_transfer'=>achbotMessage('automatic_gateway_review.provider_bank_transfer'),
        'gift_card'=>achbotMessage('automatic_gateway_review.provider_gift_card'),
        'manual_usdt'=>achbotManualLabel('manual_usdt'),
        'manual_trx'=>achbotManualLabel('manual_trx'),
        'wallet'=>achbotMessage('automatic_gateway_review.wallet_provider'),
    ];
    $safe=static fn($v) => htmlspecialchars(mb_substr((string)($v??''),0,180,'UTF-8'),
        ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    try {
        $rows=[];
        if ($isInbox) {
            $before=(int)($inbox[1]??0);
            $items=[];$more=false;
            if (achbotPayUxTable($connection,'payment_attempts')) {
                $q=$connection->prepare("SELECT p.id,p.user_id,p.price,COALESCE(a.provider,'wallet') AS provider
                    FROM pays p LEFT JOIN payment_attempts a ON a.pay_row_id=p.id
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
                p.checkout_operation_id,p.plan_id,p.order_id,p.server_id,p.day,p.volume,p.description,\n                p.agent_count,p.request_date,p.approved_by,
                a.id AS attempt_id,COALESCE(a.provider,'wallet') AS provider,a.order_hash,a.provider_reference,
                a.payment_id,a.quote_amount,a.quote_currency,a.stage,a.provider_status,
                a.created_at,a.updated_at,a.last_checked_at
                FROM pays p LEFT JOIN payment_attempts a ON a.pay_row_id=p.id
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
            $walletCase=(string)$item['provider']==='wallet';
            $subject=match(true) {
                $type==='BUY_SUB' => achbotMessage('automatic_gateway_review.purchase'),
                $type==='RENEW_ACCOUNT' =>
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
            $opId=(int)$item['checkout_operation_id'];
            $operation=$opId>0?achbotProviderRow($connection,'SELECT * FROM service_operations WHERE id=?','i',[$opId]):null;
            $native=[];$nativeTotal=0;
            if ($operation && $type==='BUY_SUB') {
                $nativeTotal=(int)achbotProviderRow($connection,
                    "SELECT COUNT(*) AS total FROM service_effects WHERE source_kind='service_operation' AND source_ref=?",
                    's',[(string)$opId])['total'];
                $q=achbotProviderStatement($connection,"SELECT id,ordinal,state,operation_id,remote_username,target_server_id,order_id,provider_state
                    FROM service_effects WHERE source_kind='service_operation' AND source_ref=? ORDER BY ordinal LIMIT 5",
                    's',[(string)$opId]);
                $native=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
            }
            $requested=(int)$item['request_date']>0
                ?(new DateTimeImmutable('@'.(int)$item['request_date']))
                    ->setTimezone(new DateTimeZone('Asia/Tehran'))->format('Y-m-d H:i:s')
                : (string)$item['created_at'];
            $status=(string)($operation['failure_code']??$item['provider_status']??'');
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
                ($type==='BUY_SUB'
                    && (int)$item['day']>0?"\n".achbotMessageHtml(
                        'automatic_gateway_review.duration',['days'=>$item['day']]):'').
                ($type==='BUY_SUB'
                    && (int)$item['volume']>0?"\n".achbotMessageHtml(
                        'automatic_gateway_review.quota',['gb'=>$item['volume']]):'').
                "\n".achbotMessageHtml('automatic_gateway_review.request_time',
                    ['date'=>$requested]).
                ($walletCase?'':(
                    "\n".achbotMessageHtml(
                        in_array($item['provider'],['bank_transfer','gift_card'],true)
                            ?'automatic_gateway_review.api_quote'
                            :'automatic_gateway_review.merchant_quote',[
                        'amount'=>$item['quote_amount'],'currency'=>$item['quote_currency']]).
                    "\n".achbotMessageHtml('automatic_gateway_review.invoice',
                        ['reference'=>$item['provider_reference']]).
                    "\n".achbotMessageHtml('automatic_gateway_review.payment',
                        ['reference'=>$item['payment_id']?:
                            achbotMessage('automatic_gateway_review.unknown')]))).
                "\n".achbotMessageHtml('automatic_gateway_review.reference',
                    ['hash'=>$item['hash_id']]).
                "\n".achbotMessageHtml('automatic_gateway_review.status',[
                    'stage'=>$item['state'].' / '.($operation['stage']??$item['stage']??
                        achbotMessage('automatic_gateway_review.unknown')),
                    'status'=>$status!==''?$status:
                        achbotMessage('automatic_gateway_review.unknown')]).
                "\n".achbotMessageHtml('automatic_gateway_review.updated',[
                    'date'=>$item['updated_at']??$requested]);
            foreach ($latest as $observed) {
                $body.="\n".achbotMessageHtml('automatic_gateway_review.merchant_observation',[
                    'payment'=>$observed['payment_id'],
                    'status'=>$observed['observed_status'],
                    'date'=>$observed['received_at']]);
            }
            $manualCase=in_array((string)$item['provider'],
                ['bank_transfer','gift_card','manual_usdt','manual_trx'],true);
            $apiAttempt=$manualCase?achbotApiPaymentAttempt($connection,$payId):null;
            $apiCase=$manualCase &&
                in_array((string)$item['provider'],['bank_transfer','gift_card'],true) &&
                !empty($apiAttempt['evidence_profile']);
            if ($apiCase) {
                $item['evidence_state']=$apiAttempt['evidence_state']??null;
                $item['evidence_fulfillment_state']=$apiAttempt['evidence_fulfillment_state']??null;
                $body.="\n".achbotMessageHtml('automatic_gateway_review.api_status',[
                    'evidence'=>$item['evidence_state']?:achbotMessage('automatic_gateway_review.unknown'),
                    'fulfillment'=>($operation['stage']??null)?:achbotMessage('automatic_gateway_review.unknown')]);
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
            $footer="\n\n".achbotMessage($walletCase
                ?'automatic_gateway_review.wallet_review_warning'
                :($apiCase?'automatic_gateway_review.api_warning'
                  :($manualCase?'automatic_gateway_review.manual_warning'
                               :'automatic_gateway_review.warning')));
            $attempt=(int)$item['attempt_id'];
            $actions=[];$recover=false;
            if (!$manualCase && !$walletCase) {
                // Hosted human Approve/Reject keep their original eligibility
                // and the same one-shot settlement coordinator.
                if (achbotGatewayReviewEligible($connection,$attempt,'approve'))
                    $actions[]=['text'=>achbotMessage('automatic_gateway_review.approve'),
                        'callback_data'=>'gatewayReview_A_'.$attempt];
                if (achbotGatewayReviewEligible($connection,$attempt,'reject'))
                    $actions[]=['text'=>achbotMessage('automatic_gateway_review.reject'),
                        'callback_data'=>'gatewayReview_R_'.$attempt];
                if ($item['provider']==='nowpayments' && $item['stage']==='review' &&
                    $item['provider_status']==='amount_mismatch' &&
                    in_array($item['state'],['pending','send'],true))
                    $recover=['text'=>achbotMessage('automatic_gateway_review.recover'),
                        'callback_data'=>'gatewayReview_V_'.$attempt];
            } elseif ($apiCase) {
                $item['attempt']=$apiAttempt;
                $item['evidence_fulfillment_state']=$apiAttempt['evidence_fulfillment_state']??null;
                $proof=achbotPaymentEvidenceGetProof($connection,$payId);
                $eligible=achbotApiPaymentCanResume($connection,$item);
                $terminalDelivery=achbotApiPaymentTerminal($item) &&
                    ($proof['mode']??null)==='settle';
                if ($eligible || $terminalDelivery)
                    // A final payment/native effect only needs a delivery
                    // status check. Do not promise to rerun an operation that
                    // has already completed or has an UNKNOWN Telegram ACK.
                    $recover=['text'=>achbotMessage($terminalDelivery
                        ?'automatic_gateway_review.open_case'
                        :'automatic_gateway_review.service_action'),
                        'callback_data'=>'apiEvidenceResume_'.$payId];
                // An API-unproven receipt remains a human decision, using
                // the SAME existing Approve/Reject callbacks and reason flow.
                if ($item['state']==='have_sent' && !$proof) {
                    $manual=json_decode(achbotApiPaymentReviewKeyboard($item),true);
                    foreach ($manual['inline_keyboard']??[] as $buttons)
                        foreach ($buttons as $button)
                            if (preg_match('/^(?:approve|accept|accCustom|declineAction_)/D',
                                (string)($button['callback_data']??''))===1)
                                $actions[]=$button;
                }
            }
            if ($operation && in_array($operation['stage'],['funded','prepared','dispatching','applied'],true)) {
                $recover=['text'=>achbotMessage('automatic_gateway_review.recover'),
                    'callback_data'=>'attentionWalletResume_'.$payId];
            }
            if ($actions) $rows[]=$actions;
            if ($recover) $rows[]=[$recover];
            if ($opId>0) {
                $q=achbotProviderStatement($connection,"SELECT d.id,p.part_no,d.ordinal FROM payment_delivery_intents d
                    JOIN payment_delivery_parts p ON p.intent_id=d.id WHERE d.service_operation_id=?
                    AND p.state IN ('unknown','rejected') ORDER BY d.ordinal,p.part_no LIMIT 5",'i',[$opId]);
                foreach ($q->get_result()->fetch_all(MYSQLI_ASSOC) as $part)
                    $rows[]=[['text'=>achbotMessage('automatic_gateway_review.redrive_action').' #'.$part['ordinal'].'.'.$part['part_no'],
                        'callback_data'=>'attentionDeliveryC_'.$part['id'].'_'.$part['part_no']]];
                $q->close();
            }
            $q=achbotProviderStatement($connection,"SELECT id FROM payment_followup_intents
                WHERE (service_operation_id=? OR (pay_row_id=? AND stage='wallet_credit'))
                    AND state IN ('unknown','rejected') ORDER BY id LIMIT 5",'ii',[$opId,$payId]);
            foreach ($q->get_result()->fetch_all(MYSQLI_ASSOC) as $report)
                $rows[]=[['text'=>achbotMessage('automatic_gateway_review.wallet_report_action').' #'.$report['id'],
                    'callback_data'=>'attentionReportC_'.$report['id']]];
            $q->close();
            if (!$actions && !$recover)
                $footer.="\n".achbotMessage('automatic_gateway_review.manual_hold');
            // Both hosted/manual and original Wallet exceptions have the
            // same independent terminal LOCAL Archive choice. Wallet cases
            // may have no hosted attempt and use their original pay_id.
            $rows[]=[['text'=>achbotMessage('automatic_gateway_review.archive'),
                'callback_data'=>$walletCase
                    ?'attentionWalletArchive_'.$payId
                    :'gatewayReview_H_'.$attempt]];
            $footer.="\n".achbotMessage('automatic_gateway_review.archive_notice');
            $body=$gatewayFitCase($body,$optional,$footer);
            $rows[]=[['text'=>achbotMessage('automatic_gateway_review.back_list'),
                'callback_data'=>'attentionInbox']];
        }
        answerCallbackQuery($callbackId);
        $send($body,$rows);
    } catch (Throwable $error) {
        error_log('achbot_automatic_gateway_inbox_unavailable class='.get_class($error).
            ' code='.(int)$error->getCode());
        answerCallbackQuery($callbackId,achbotMessage('automatic_gateway_review.unavailable'),true);
    }
    return;
}


// Wallet cases can exist without a hosted attempt. Archive is a local,
// original-payment terminal fence: no new debit, Native, refund or delivery.
$walletArchive=[];
if (preg_match('/^attentionWalletArchive_([1-9][0-9]{0,17})$/D',
    (string)($data??''),$walletArchive)===1) {
    if (!achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'payments') ||
        (int)($chat_id??0)!==(int)$from_id) {
        answerCallbackQuery($callbackId,achbotMessage('automatic_gateway_review.denied'),true);
        return;
    }
    require_once __DIR__.'/../GatewayReview.php';
    $payId=(int)$walletArchive[1];$actor=(int)$from_id;
    $keys=json_encode(['inline_keyboard'=>[[[
        'text'=>achbotMessage('automatic_gateway_review.back_list'),
        'callback_data'=>'attentionInbox']]]],
        JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    try {
        answerCallbackQuery($callbackId,achbotMessage('automatic_gateway_review.checking'));
        $status=achbotWalletReviewArchive($connection,$actor,$payId,(int)$admin);
        $label=achbotMessage(match($status) {
            'archived'=>'automatic_gateway_review.status_archived',
            'busy'=>'automatic_gateway_review.status_busy',
            'stale'=>'automatic_gateway_review.status_stale',
            'denied'=>'automatic_gateway_review.denied',
            default=>'automatic_gateway_review.status_unavailable',
        });
        $message=achbotMessageHtml('automatic_gateway_review.result',
            ['result'=>$label]);
        $edited=editText($message_id,$message,$keys,'HTML',$actor);
        if (!is_object($edited) || ($edited->ok??false)!==true)
            sendMessage($message,$keys,'HTML',$actor);
    } catch (Throwable $error) {
        error_log('achbot_wallet_archive_ui_unavailable pay_id='.$payId.
            ' actor='.$actor.' reason='.achbotFinanceDiagnosticReason($error));
        answerCallbackQuery($callbackId,
            achbotMessage('automatic_gateway_review.unavailable'),true);
    }
    return;
}

// Same original financial effect, with one-shot provider-attempt claim.
$gatewayAction=[];
if (preg_match('/^gatewayReview_([ARHV])_([1-9][0-9]{0,17})$/D',
    (string)($data??''),$gatewayAction)===1) {
    if (!achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'payments') ||
        (int)($chat_id??0)!==(int)$from_id) {
        answerCallbackQuery($callbackId,achbotMessage('automatic_gateway_review.denied'),true);
        return;
    }
    $archive=$gatewayAction[1]==='H';
    $recovery=$gatewayAction[1]==='V';
    require_once __DIR__.'/../PaymentLifecycle.php';
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
            :($recovery
                ?achbotGatewayReviewRecover($connection,(int)$from_id,$attempt,
                    (int)$admin,(array)($paymentKeys??[]))
                :achbotGatewayReviewCommit(
                    $connection,(int)$from_id,$attempt,$mode,(int)$admin));
        $status=(string)($result['status']??'unavailable');
        if ($archive && $status==='archived') {
            // The old API receipt remains a stored admin message. Update its
            // keyboard in place so it no longer offers a stale review action.
            try {
                $q=$connection->prepare('SELECT pay_row_id,provider FROM payment_attempts WHERE id=?');
                $q->bind_param('i',$attempt);$q->execute();
                $archivedSource=$q->get_result()->fetch_assoc();$q->close();
                if ($archivedSource && in_array($archivedSource['provider'],
                    ['bank_transfer','gift_card'],true)) {
                    $original=achbotApiPaymentRow($connection,(int)$archivedSource['pay_row_id']);
                    if ($original) achbotApiPaymentNotice($connection,$original,'archived',false);
                }
            } catch (Throwable $error) {
                error_log('achbot_archive_receipt_projection_deferred attempt='.$attempt.
                    ' class='.get_class($error));
            }
        }
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
