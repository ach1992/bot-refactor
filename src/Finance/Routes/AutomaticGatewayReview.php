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

require_once __DIR__.'/../../Services/Resolution.php';
if (str_starts_with((string)($data??''),'attentionService')) {
    require __DIR__.'/ServiceAttention.php';
    return;
}

// Recover the exact stored manual evidence; a lost first Telegram ACK
// is not a reason to ask the customer to pay or upload another receipt.
if (preg_match('/^attentionReceipt_([1-9][0-9]{0,17})$/D',(string)($data??''),$receiptAction)) {
    $actor=(int)$from_id;$payId=(int)$receiptAction[1];
    if (!achbotAdminCanFor($connection,$actor,(int)$admin,'payments') || (int)($chat_id??0)!==$actor) {
        answerCallbackQuery($callbackId,achbotMessage('automatic_gateway_review.denied'),true);return;
    }
    require_once __DIR__.'/../ApiPayments.php';
    $token=null;$pay=null;
    try {
        $eligible=achbotProviderRow($connection,'SELECT p.id FROM pays p JOIN payment_attempts a ON a.pay_row_id=p.id
            WHERE p.id=? AND '.achbotGatewayAttentionReceiptScope(),'i',[$payId]);
        $pay=$eligible?achbotApiPaymentRow($connection,$payId):null;
        $a=$pay?achbotApiPaymentAttempt($connection,$payId):null;
        if (!$a || !achbotPayUxManualProvider($a['provider']) ||
            !in_array($a['stage'],['receiving','review'],true) ||
            $a['wallet_credit_entry_id']!==null ||
            !hash_equals((string)$a['wallet_invoice_sha256'],achbotCreditInvoiceFingerprint($pay,$a)))
            throw new DomainException('manual_review_stale');
        $intent=achbotManualIntentDecode((string)$a['checkout_url'],$a['provider']);
        if (!$intent) throw new DomainException('manual_review_evidence_invalid');
        $typed=achbotManualTypedGift($connection,$pay,$a['provider']);
        $file=$typed?achbotPaymentEvidenceReadGiftCode($connection,$payId):($intent['receipt_file_id']??null);
        if (!is_string($file) || $file==='' || ($typed && !achbotPaymentEvidenceGiftCodeFormatValid($file)))
            throw new DomainException('manual_review_evidence_invalid');
        if ($a['stage']==='receiving') {
            $token=achbotManualBeginEvidence($connection,$pay,$a['provider'],(int)$pay['user_id'],$file);
            if ($token===null) throw new DomainException('manual_review_busy');
        } else {
            if (!in_array($pay['state'],['have_sent','service_superseded'],true))
                throw new DomainException('manual_review_stale');
            achbotCreditValidateManualReceiptLocked($connection,$pay,$a);
        }
        // Never reveal proof in a group, to a support-only actor, or to a
        // revoked member. The actual approval independently rechecks it.
        if (!achbotAdminCanFor($connection,$actor,(int)$admin,'payments'))
            throw new DomainException('manual_review_denied');
        $body=achbotMessage('automatic_gateway_review.case_title')."\n".
            achbotMessageHtml('automatic_gateway_review.customer',['id'=>$pay['user_id']])."\n".
            achbotMessageHtml('automatic_gateway_review.amount',['amount'=>number_format((int)$pay['price'])])."\n".
            achbotMessageHtml('automatic_gateway_review.reference',['hash'=>$pay['hash_id']])."\n".
            achbotMessageHtml('automatic_gateway_review.api_quote',['amount'=>$a['quote_amount'],'currency'=>$a['quote_currency']]);
        $actions=[['text'=>achbotMessage('legacy_buttons.approve'),
            'callback_data'=>achbotManualApprovalPrefix($pay).$pay['hash_id']]];
        array_unshift($actions,['text'=>achbotMessage('legacy_buttons.decline'),'callback_data'=>'declineAction_'.$pay['hash_id']]);
        $keys=inlineKeyboard([$actions,[['text'=>achbotMessage('automatic_gateway_review.open_case'),
            'callback_data'=>'attentionCase_'.$payId]]]);
        if ($typed) {
            $body.="\n<code>".htmlspecialchars($file,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</code>';
            $response=sendMessage($body,$keys,'HTML',$actor);
        } else $response=sendPhoto($file,$body,$keys,'HTML',$actor);
        if (!is_object($response) || ($response->ok??false)!==true)
            throw new RuntimeException('manual_review_delivery_unconfirmed');
        if ($token!==null && !achbotManualFinishEvidence($connection,$pay['hash_id'],$file,$token,
            (int)($response->result->message_id??0),$actor))
            throw new RuntimeException('manual_review_commit_unconfirmed');
        $token=null;
        answerCallbackQuery($callbackId);
    } catch (Throwable $e) {
        if ($token!==null && $pay) achbotManualReleaseEvidenceLease($connection,$pay['hash_id'],$token);
        achbotDiagnosticEvent('achbot.finance.receipt_review',['pay_id'=>$payId,'actor_id'=>$actor,
            'class'=>get_class($e),'reason'=>preg_match('/^manual_review_[a-z0-9_]{1,64}$/D',$e->getMessage())?$e->getMessage():'manual_review_failed'],'warning');
        answerCallbackQuery($callbackId,achbotMessage('automatic_gateway_review.unavailable'),true);
    }
    return;
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
        $opId=(int)($d['service_operation_id']??0);
        if (!$d || !$p || !achbotServiceAttentionCaseOpen($connection,$opId))
            throw new RuntimeException('delivery_review_stale');
        $token=achbotDeliveryPartRedriveToken($d,$p,$actor);
        $rows=[];$payId=$opId;
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
        $rows[]=[['text'=>achbotMessage('automatic_gateway_review.redrive_cancel'),'callback_data'=>'attentionService_'.$opId]];
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
        $receiptId=(int)($r['pay_row_id']??0);
        $operationId=(int)($r['service_operation_id']??0);
        if (!$r) throw new RuntimeException('followup_review_stale');
        if ($operationId>0) {
            if (!achbotServiceAttentionCaseOpen($connection,$operationId))
                throw new RuntimeException('followup_review_stale');
            if ($receiptId>0 && ($r['stage']!=='referral_reward' ||
                !achbotProviderRow($connection,'SELECT id FROM payment_attempts WHERE pay_row_id=? AND service_operation_id=?',
                    'ii',[$receiptId,$operationId])))
                throw new RuntimeException('followup_review_source');
            $back='attentionService_'.$operationId;$payId=$operationId;
        } elseif ($receiptId>0 && achbotGatewayAttentionCaseOpen($connection,$receiptId)) {
            $back='attentionCase_'.$receiptId;$payId=$receiptId;
        } else throw new RuntimeException('followup_review_stale');
        $token=achbotFollowupRedriveToken($r,$actor);$rows=[];
        if ($reviewAction[1]==='C' && !isset($reviewAction[3])) {
            $body=achbotMessageHtml('automatic_gateway_review.wallet_report_confirm',['payment'=>$payId]);
            $rows[]=[['text'=>achbotMessage('automatic_gateway_review.wallet_report_send'),
                'callback_data'=>'attentionReportS_'.$id.'_'.$token]];
        } elseif ($reviewAction[1]==='S' && hash_equals($token,(string)($reviewAction[3]??''))) {
            answerCallbackQuery($callbackId,achbotMessage('automatic_gateway_review.checking'));
            $status=achbotFollowupDispatchOne($connection,$id,$actor,$token);
            $body=achbotMessage(match($status) {
                'acknowledged'=>'automatic_gateway_review.wallet_report_ack',
                'unknown'=>'automatic_gateway_review.wallet_report_unknown',
                'busy'=>'automatic_gateway_review.redrive_busy',
                default=>'automatic_gateway_review.wallet_report_hold',
            });
        } else throw new RuntimeException('followup_review_stale');
        $rows[]=[['text'=>achbotMessage('automatic_gateway_review.redrive_cancel'),'callback_data'=>$back]];
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
            $serviceScope=achbotServiceAttentionScope();
            $q=achbotProviderStatement($connection,"SELECT * FROM (
                SELECT p.id,p.user_id,p.price,COALESCE(a.provider,'wallet') AS provider,'receipt' AS source,p.id*2 AS cursor
                  FROM pays p LEFT JOIN payment_attempts a ON a.pay_row_id=p.id WHERE $scope
                UNION ALL
                SELECT o.id,o.userid,o.price_toman,o.kind,'service',o.id*2+1
                  FROM service_operations o WHERE $serviceScope
                ) AS cases WHERE (?=0 OR cursor<?) ORDER BY cursor DESC LIMIT 13",
                'ii',[$before,$before]);
            $items=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
            $more=count($items)>12;$items=array_slice($items,0,12);
            $kinds=['BUY'=>achbotMessage('automatic_gateway_review.purchase'),
                'RENEW'=>achbotMessage('automatic_gateway_review.renew'),
                'ADD_TIME'=>achbotMessage('automatic_gateway_review.days'),
                'ADD_VOLUME'=>achbotMessage('automatic_gateway_review.volume')];
            foreach ($items as $item) {
                $service=$item['source']==='service';
                $kind=$service?$kinds[$item['provider']]:
                    ($labels[$item['provider']]??$item['provider']);
                $label=($service?'⚙️ ':'💳 ').number_format((int)$item['price']).' '.
                    achbotMessage('automatic_gateway_review.toman').' | '.(int)$item['user_id'].' | '.$kind;
                $rows[]=[['text'=>$label,'callback_data'=>($service?'attentionService_':'attentionCase_').(int)$item['id']]];
            }
            if ($more && $items) $rows[]=[['text'=>achbotMessage('automatic_gateway_review.older'),
                'callback_data'=>'attentionInbox_'.(int)end($items)['cursor']]];
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
                a.created_at,a.updated_at,a.last_checked_at,(".achbotGatewayAttentionReceiptScope().") AS financial_review
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
            $method=achbotCreditMethodDefinition((string)$item['provider']);
            $manualCase=isset($method['manual_quote']);
            $apiAttempt=$manualCase?achbotApiPaymentAttempt($connection,$payId):null;
            $apiCase=$manualCase && isset($method['api_checker']) && !empty($apiAttempt['evidence_profile']);
            if ($apiCase) {
                $item['evidence_state']=$apiAttempt['evidence_state']??null;
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
                if (isset($method['hosted']) && (int)$item['financial_review']===1 &&
                    !empty($method['hosted']['child_transactions']?$item['payment_id']:$item['provider_reference']))
                    $recover=['text'=>achbotMessage('automatic_gateway_review.recover'),
                        'callback_data'=>'gatewayReview_V_'.$attempt];
            } elseif ($apiCase) {
                $item['attempt']=$apiAttempt;
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
            }
            if ($manualCase && (int)$item['financial_review']===1 &&
                in_array($item['state'],['have_sent','service_superseded'],true) && $item['stage']==='review' &&
                (!$apiCase || !achbotPaymentEvidenceGetProof($connection,$payId))) {
                $actions[]=['text'=>achbotMessage('legacy_buttons.approve'),
                    'callback_data'=>achbotManualApprovalPrefix($item).$item['hash_id']];
                $actions[]=['text'=>achbotMessage('legacy_buttons.decline'),'callback_data'=>'declineAction_'.$item['hash_id']];
            }
            if ($manualCase && (int)$item['financial_review']===1 &&
                in_array($item['stage'],['receiving','review'],true)) {
                $rows[]=[['text'=>achbotMessage('automatic_gateway_review.view_receipt'),
                    'callback_data'=>'attentionReceipt_'.$payId]];
            }
            if ($actions) $rows[]=$actions;
            if ($recover) $rows[]=[$recover];
            if ($opId>0 && achbotServiceAttentionCaseOpen($connection,$opId))
                $rows[]=[['text'=>achbotMessage('automatic_gateway_review.service_open'),
                    'callback_data'=>'attentionService_'.$opId]];
            $q=achbotProviderStatement($connection,"SELECT id FROM payment_followup_intents
                WHERE pay_row_id=? AND service_operation_id IS NULL AND stage IN ('wallet_credit','manual_reject')
                    AND state IN ('unknown','rejected') ORDER BY id",'i',[$payId]);
            foreach ($q->get_result()->fetch_all(MYSQLI_ASSOC) as $report)
                $rows[]=[['text'=>achbotMessage('automatic_gateway_review.wallet_report_action').' #'.$report['id'],
                    'callback_data'=>'attentionReportC_'.$report['id']]];
            $q->close();
            if (!$actions && !$recover)
                $footer.="\n".achbotMessage('automatic_gateway_review.manual_hold');
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


// Same original financial effect, with one-shot provider-attempt claim.
$gatewayAction=[];
if (preg_match('/^gatewayReview_([ARV])_([1-9][0-9]{0,17})$/D',
    (string)($data??''),$gatewayAction)===1) {
    if (!achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'payments') ||
        (int)($chat_id??0)!==(int)$from_id) {
        answerCallbackQuery($callbackId,achbotMessage('automatic_gateway_review.denied'),true);
        return;
    }
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
        $result=$recovery
            ?achbotGatewayReviewRecover($connection,(int)$from_id,$attempt,(int)$admin)
            :achbotGatewayReviewCommit($connection,(int)$from_id,$attempt,$mode,(int)$admin);
        $status=(string)($result['status']??'unavailable');
        $text=match($status) {
            'wallet_credited','service_pending','completed','delivery_unconfirmed','resolved'=>achbotCheckoutReceiptOutcomeText($status),
            'rejected'=>achbotMessage('automatic_gateway_review.status_rejected'),
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
