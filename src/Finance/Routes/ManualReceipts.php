<?php
/** AChBot V8: source-pinned financial compatibility module. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../../messages/loader.php'; // ACHBOT_TEXT_CATALOG


// Unified read-only attention hub. Existing NP child inquiry and exact
// BUY_SUB CREATE readback remain specialized, separately gated tools.
$attentionMatch=[];
if (preg_match('/^attention(Inbox|Case)(?:_([1-9][0-9]{0,17}))?$/D',(string)$data,$attentionMatch)) {
    if ((int)$admin<=0 || (int)$from_id!==(int)$admin || (int)($chat_id??0)!==(int)$admin) {
        answerCallbackQuery($callbackId,\achbotMessage('manual_payment.manual_receipts.alert.0a23f88410'),true);
        return;
    }
    require_once __DIR__.'/../RecoveryInspection.php';
    if (!defined('ACHBOT_NP_RECONCILE_ONLY')) define('ACHBOT_NP_RECONCILE_ONLY',true);
    require_once __DIR__.'/../GatewayReturn.php';
    $escAttention=static fn($v)=>htmlspecialchars(substr((string)$v,0,150),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $rows=[];
    try {
        if ($attentionMatch[1]==='Inbox') {
            $before=(int)($attentionMatch[2]??0);
            $items=achbotRecoveryAttentionCases($connection,$before);
            $more=count($items)>12;$items=array_slice($items,0,12);
            $body=\achbotMessage('manual_payment.manual_receipts.body.33cce3f587').
                \achbotMessage('manual_payment.manual_receipts.text.a7c60ae676').
                \achbotMessage('manual_payment.manual_receipts.text.fc540b5b95').
                \achbotMessage('manual_payment.manual_receipts.text.892c054af9');
            foreach ($items as $item) $rows[]=[['text'=>\achbotMessage('manual_payment.manual_receipts.button.8b87c9c9f0').(int)$item['id'].\achbotMessage('manual_payment.manual_receipts.text.44017e9932').(int)$item['user_id'].' | '.number_format((int)$item['price']).\achbotMessage('manual_payment.automatic_receipts.text.2cc98f7f7c'),
                'callback_data'=>'attentionCase_'.(int)$item['id']]];
            if (!$items) $body.=\achbotMessage('manual_payment.manual_receipts.body.344ad306af');
            if ($more) $rows[]=[['text'=>\achbotMessage('manual_payment.manual_receipts.button.2a07cf7022'),'callback_data'=>'attentionInbox_'.(int)end($items)['id']]];
            if ($before>0) $rows[]=[['text'=>\achbotMessage('manual_payment.manual_receipts.button.19ff81effd'),'callback_data'=>'attentionInbox']];
        } else {
            $id=(int)($attentionMatch[2]??0);
            $pay=achbotRecoveryAttentionCase($connection,$id);
            if (!$pay) throw new RuntimeException('attention_pay_not_found');
            $a=$pay['attempt'];
            $body=\achbotMessage('manual_payment.manual_receipts.body.069b961bf9').$id."</b>\n".
                achbotPayUxReference($pay).\achbotMessage('manual_payment.manual_receipts.text.be26bedc74').number_format((int)$pay['price']).\achbotMessage('manual_payment.manual_receipts.text.81a62ff6c6').
                \achbotMessage('manual_payment.manual_receipts.text.cb30509da2').$escAttention($pay['type'])."</code>\n".
                \achbotMessage('manual_payment.manual_receipts.text.f0b53210c3').$escAttention($a['provider']??\achbotMessage('manual_payment.manual_receipts.text.260481666f')).\achbotMessage('manual_payment.manual_receipts.text.8e4c17a2df').$escAttention($pay['state'])."</code>\n".
                \achbotMessage('manual_payment.manual_receipts.text.11acfb8b65').$escAttention($a['stage']??\achbotMessage('manual_payment.manual_receipts.text.8e25fa8b4c')).\achbotMessage('manual_payment.manual_receipts.text.57895a27e1').$escAttention($a['provider_status']??\achbotMessage('manual_payment.manual_receipts.text.5749a86140'))."</code>\n\n".
                achbotRecoveryEvidenceText($pay,$a).achbotRecoverySubjectText($pay);
            if ($a && (string)($a['payment_id']??'')!=='') $body.=\achbotMessage('manual_payment.manual_receipts.body.5c0b1f20fc').$escAttention($a['payment_id'])."</code>\n";
            if (($a['provider']??'')==='nowpayments') {
                $stmt=$connection->prepare('SELECT id FROM payment_attempts WHERE pay_row_id=? LIMIT 1');
                $stmt->bind_param('i',$id);$stmt->execute();$attemptId=(int)($stmt->get_result()->fetch_assoc()['id']??0);$stmt->close();
                if ($attemptId>0) $rows[]=[['text'=>\achbotMessage('manual_payment.manual_receipts.button.8b5b783029'),'callback_data'=>'npReviewCase_'.$attemptId]];
            }
            if ($pay['type']==='BUY_SUB' && in_array($pay['state'],['processing','processing_wallet','paid'],true))
                $rows[]=[['text'=>\achbotMessage('manual_payment.manual_receipts.button.ea309dff23'),'callback_data'=>'bankReviewCase_'.$id]];
            $manual=achbotPayUxManualProvider((string)($a['provider']??''));
            if ($manual && $pay['state']==='have_sent') $body.=\achbotMessage('manual_payment.manual_receipts.body.189e0e519d');
            elseif ($pay['type']==='INCREASE_WALLET') $body.=\achbotMessage('manual_payment.manual_receipts.body.12b390893d');
            elseif ($pay['type']!=='BUY_SUB') $body.=\achbotMessage('manual_payment.manual_receipts.body.6c27eff9db');
            else $body.=\achbotMessage('manual_payment.manual_receipts.body.3e0c446f58');
            $rows[]=[['text'=>\achbotMessage('manual_payment.manual_receipts.button.4d6cf1a248'),'callback_data'=>'attentionInbox']];
        }
        if (!achbotPayUxTable($connection,'payment_attempts') || !achbotPayUxTable($connection,'service_effects') ||
            !achbotPayUxTable($connection,'automatic_renewal_attempts'))
            $body.=\achbotMessage('manual_payment.manual_receipts.body.75381485c2');
        $body.=\achbotMessage('manual_payment.manual_receipts.body.2d8dc82a81');
        $rows[]=[['text'=>\achbotMessage('manual_payment.manual_receipts.button.3d941bcb15'),'callback_data'=>'npReviewInbox']];
        $rows[]=[['text'=>\achbotMessage('manual_payment.manual_crypto.button.1121ffbf88'),'callback_data'=>'evidenceHome']];
        answerCallbackQuery($callbackId,\achbotMessage('manual_payment.manual_receipts.alert.c3fb4bd548'));
        $markup=json_encode(['inline_keyboard'=>$rows],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $result=editText($message_id,$body,$markup,'HTML',$admin);
        if (!is_object($result) || ($result->ok??false)!==true) sendMessage($body,$markup,'HTML',$admin);
    } catch (Throwable $error) {
        error_log('achbot_attention_inspection_unavailable class='.get_class($error));
        sendMessage(\achbotMessage('manual_payment.manual_receipts.message.c46e102fab'),null,'HTML',$admin);
    }
    return;
}

// The operator recovery workbench is never available to other admins or chats.
$bankInboxMatch=[];$bankCaseMatch=[];$bankReadMatch=[];
$bankInbox=preg_match('/^bankReviewInbox(?:_([1-9][0-9]{0,17}))?$/D',
                      (string)$data,$bankInboxMatch)===1;
$bankCase=preg_match('/^bankReviewCase_([1-9][0-9]{0,17})(?:_([0-9]{1,10}))?$/D',
                     (string)$data,$bankCaseMatch)===1;
$bankRead=preg_match('/^bankReviewRead_([1-9][0-9]{0,17})_([1-9][0-9]{0,9})_([0-9]{1,10})$/D',
                     (string)$data,$bankReadMatch)===1;
if ($bankInbox || $bankCase || $bankRead) {
    if ((int)$admin<=0 || (int)$from_id!==(int)$admin ||
        (int)($chat_id??0)!==(int)$admin) {
        answerCallbackQuery($callbackId,\achbotMessage('manual_payment.manual_receipts.alert.0a23f88410'),true);
        return;
    }
    require_once __DIR__.'/../RecoveryInspection.php';
    // The production implementation never consults a mutable server ID.
    // The named seam permits isolated source tests to replace only transport.
    if (!function_exists('achbotRecoveryStrictAccountGet'))
        require_once __DIR__.'/../RecoveryTransport.php';
    $escRecovery=static fn($value) => htmlspecialchars(
        substr((string)($value??''),0,100),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');

    $bankAcked=false;
    $bankAck=static function(string $message) use (&$bankAcked,$callbackId): void {
        if ($bankAcked) return;
        $bankAcked=true;
        answerCallbackQuery($callbackId,$message);
    };
    // Never use a second callback ACK as delivery confirmation. After the
    // original ACK, edit the owner's message or send a new owner-private one.
    $bankShow=static function(string $body, array $buttons=[]) use ($message_id,$admin): void {
        $markup=json_encode(['inline_keyboard'=>$buttons],
                            JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        try {
            $edited=editText($message_id,$body,$markup,'HTML',$admin);
            if (is_object($edited) && ($edited->ok??false)===true) return;
        } catch (Throwable $ignored) {}
        $sent=sendMessage($body,$markup,'HTML',$admin);
        if (!is_object($sent) || ($sent->ok??false)!==true)
            throw new RuntimeException('recovery_owner_delivery_unconfirmed');
    };

    $payId=0;
    try {
        $bankAck($bankRead?\achbotMessage('manual_payment.manual_receipts.text.e4945a4036'):\achbotMessage('manual_payment.manual_receipts.text.f45bd281a9'));
        if ($bankInbox) {
            $before=(int)($bankInboxMatch[1]??0);
            $items=achbotRecoveryPendingCases($connection,$before);
            $more=count($items)>12;
            $items=array_slice($items,0,12);
            $buttons=[];
            foreach ($items as $item) {
                $buttons[]=[['text'=>\achbotMessage('manual_payment.manual_receipts.button.8b87c9c9f0').(int)$item['id'].\achbotMessage('manual_payment.manual_receipts.text.44017e9932').
                    (int)$item['user_id'].' | '.number_format((int)$item['price']).\achbotMessage('manual_payment.automatic_receipts.text.2cc98f7f7c'),
                    'callback_data'=>'bankReviewCase_'.(int)$item['id']]];
            }
            if ($more && $items) {
                $last=end($items);
                $buttons[]=[['text'=>\achbotMessage('manual_payment.manual_receipts.button.2a07cf7022'),
                    'callback_data'=>'bankReviewInbox_'.(int)$last['id']]];
            }
            if ($before>0)
                $buttons[]=[['text'=>\achbotMessage('manual_payment.manual_receipts.button.19ff81effd'),'callback_data'=>'bankReviewInbox']];
            $buttons[]=[['text'=>\achbotMessage('manual_payment.manual_receipts.button.84fe055cdf'),'callback_data'=>'attentionInbox']];
            $buttons[]=[['text'=>\achbotMessage('manual_payment.manual_receipts.button.c7ac8dfee0'),'callback_data'=>'managePanel']];
            $body=\achbotMessage('manual_payment.manual_receipts.body.0b11329132').
                \achbotMessage('manual_payment.manual_receipts.text.daf7f84c9e').
                \achbotMessage('manual_payment.manual_receipts.text.0702ddffac').
                \achbotMessage('manual_payment.manual_receipts.text.d18917f91a');
            if (!$items) $body.=\achbotMessage('manual_payment.manual_receipts.body.83bffce220');
            $bankShow($body,$buttons);
            return;
        }
        $payId=(int)($bankCase?$bankCaseMatch[1]:$bankReadMatch[1]);
        $cursor=(int)($bankCase?($bankCaseMatch[2]??0):$bankReadMatch[3]);
        $observation=null;
        if ($bankRead) {
            $ordinal=(int)$bankReadMatch[2];
            // Pinned, bounded authenticated login and exactly one account GET.
            $observation=achbotRecoveryInspectLine(
                $connection,$payId,$ordinal,
                static fn(string $endpoint,string $user,string $password,string $username) =>
                    achbotRecoveryStrictAccountGet($endpoint,$user,$password,$username));
        }
        $case=achbotRecoveryCase($connection,$payId,$cursor);
        if (!$case) {
            $bankShow(\achbotMessage('manual_payment.manual_receipts.text.78315abd05').$payId.\achbotMessage('manual_payment.manual_receipts.text.5743ace55a').
                \achbotMessage('manual_payment.manual_receipts.text.5743a85170').
                \achbotMessage('manual_payment.manual_receipts.text.1348893533'),
                [[['text'=>\achbotMessage('manual_payment.manual_receipts.button.cec3d268b6'),'callback_data'=>'bankReviewInbox']]]);
            return;
        }
        $requested=(int)$case['requested_accounts'];
        $recorded=(int)$case['recorded_accounts'];
        $body=\achbotMessage('manual_payment.manual_receipts.body.7dd7fb1632').(int)$case['pay_id']."</b>\n\n".
            \achbotMessage('manual_payment.manual_receipts.text.d2bbfff6d2').(int)$case['user_id']."\n".
            \achbotMessage('manual_payment.manual_receipts.text.db022eb061').number_format((int)$case['amount']).\achbotMessage('manual_payment.manual_receipts.text.81a62ff6c6').
            \achbotMessage('manual_payment.manual_receipts.text.d279f3e369').$requested.\achbotMessage('manual_payment.manual_receipts.text.f5bbd167b7').$recorded."\n".
            \achbotMessage('manual_payment.manual_receipts.text.955398cb47').$escRecovery($case['method'])."</code>\n".
            \achbotMessage('manual_payment.manual_receipts.text.1adade53f0').$escRecovery($case['payment_state'])."</code>\n".
            $case['evidence_text'];
        if ($case['payment_state']==='processing_wallet') {
            $body.=\achbotMessage('manual_payment.manual_receipts.body.421579cc6a').
                $escRecovery($case['wallet_hold_state'])."</code>".
                \achbotMessage('manual_payment.manual_receipts.text.7a7fde1704').
                (int)$case['wallet_capacity_reserved']."</code>
";
        }
        if ($case['historical_evidence_unavailable'])
            $body.=\achbotMessage('manual_payment.manual_receipts.body.a9416a9d0f');
        elseif (!$case['journal_complete'])
            $body.=\achbotMessage('manual_payment.manual_receipts.body.a578ddc599');
        else
            $body.=\achbotMessage('manual_payment.manual_receipts.body.dbc1d1ad5d');
        $body.=\achbotMessage('manual_payment.manual_receipts.body.9c35b59ec6').
               \achbotMessage('manual_payment.manual_receipts.text.1fd9b81d66');
        $states=[
            'dispatching'=>\achbotMessage('manual_payment.manual_receipts.text.9509889d5a'),
            'verified'=>\achbotMessage('manual_payment.manual_receipts.text.ced6988f1b'),
            'local_recorded'=>\achbotMessage('manual_payment.manual_receipts.text.872f51fba7')
        ];
        $orderStates=[
            'not_recorded'=>\achbotMessage('manual_payment.manual_receipts.text.49dcc53128'),
            'historical_order_missing'=>\achbotMessage('manual_payment.manual_receipts.text.23f88a8e40'),
            'linked'=>\achbotMessage('manual_payment.manual_receipts.text.592fae7fc5'),
            'linked_mismatch'=>\achbotMessage('manual_payment.manual_receipts.text.1dcc76aa6d')
        ];
        $buttons=[];
        foreach ($case['lines'] as $line) {
            $num=(int)$line['ordinal'];
            $body.=\achbotMessage('manual_payment.manual_receipts.body.e32998c4f2').$num." | <code>".$escRecovery($line['username'])."</code>\n".
                \achbotMessage('manual_payment.manual_receipts.text.5def5245fd').$escRecovery($states[$line['state']]??\achbotMessage('manual_payment.manual_receipts.text.f8ba5b2ba2'))."\n".
                \achbotMessage('manual_payment.manual_receipts.text.7906264202').$escRecovery($line['operation_id'])."</code>\n".
                \achbotMessage('manual_payment.manual_receipts.text.1fa4518da2').(int)$line['effect_id'].\achbotMessage('manual_payment.manual_receipts.text.e79a84073f').
                    ($line['order_id']===null?\achbotMessage('manual_payment.manual_receipts.text.cbf88cdc30'):(int)$line['order_id'])."\n".
                \achbotMessage('manual_payment.manual_receipts.text.a8d37ad733').$escRecovery($orderStates[$line['local_evidence']]??\achbotMessage('manual_payment.manual_receipts.text.aa75bd9846'))."\n";
            if ($num>$requested)
                $body.=\achbotMessage('manual_payment.manual_receipts.body.549aabe72c');
            $buttons[]=[['text'=>\achbotMessage('manual_payment.manual_receipts.button.3b19e9c031').$num,
                'callback_data'=>'bankReviewRead_'.(int)$case['pay_id'].'_'.$num.'_'.$cursor]];
        }
        if (!$case['lines'])
            $body.=\achbotMessage('manual_payment.manual_receipts.body.f8d79baf6f');
        if ($observation!==null) {
            $explain=match($observation['status']) {
                'matching_observation'=>\achbotMessage('manual_payment.manual_receipts.text.b4dda864a4'),
                'remote_mismatch'=>\achbotMessage('manual_payment.manual_receipts.text.f5ca620f64'),
                'remote_unavailable'=>\achbotMessage('manual_payment.manual_receipts.text.7d1ab789a1'),
                'changed_during_inspection'=>\achbotMessage('manual_payment.manual_receipts.text.e802d83881'),
                default=>\achbotMessage('manual_payment.manual_receipts.text.81d4f3f95f'),
            };
            $body.=\achbotMessage('manual_payment.manual_receipts.body.46a1988ab1').(int)$observation['ordinal'].":</b>\n".$explain."\n";
        }
        $body.=\achbotMessage('manual_payment.manual_receipts.body.f6f12cb363').
            \achbotMessage('manual_payment.manual_receipts.text.6cd6e8610f');
        if ((int)$case['next_ordinal']>0)
            $buttons[]=[['text'=>\achbotMessage('manual_payment.manual_receipts.button.30c297064a'),
                'callback_data'=>'bankReviewCase_'.(int)$case['pay_id'].'_'.
                    (int)$case['next_ordinal']]];
        if ($cursor>0)
            $buttons[]=[['text'=>\achbotMessage('manual_payment.manual_receipts.button.e9b88c11de'),
                'callback_data'=>'bankReviewCase_'.(int)$case['pay_id']]];
        $buttons[]=[['text'=>\achbotMessage('manual_payment.manual_receipts.button.2de264e127'),
            'callback_data'=>'bankReviewInbox']];
        $bankShow($body,$buttons);
    } catch (Throwable $error) {
        // No raw token, provider response or customer data in protected logs.
        error_log('achbot_service_recovery_inspection_unavailable pay_id='.
            (int)$payId.' class='.get_class($error));
        try {
            $bankAck(\achbotMessage('manual_payment.manual_receipts.text.f8772986a6'));
            $bankShow(\achbotMessage('manual_payment.manual_receipts.text.7434f799ae').
                \achbotMessage('manual_payment.manual_receipts.text.7aefae1fe7').$payId.\achbotMessage('manual_payment.manual_receipts.text.1f851ceccd').
                \achbotMessage('manual_payment.manual_receipts.text.39f6c134e8'),
                [[['text'=>\achbotMessage('manual_payment.manual_receipts.button.cec3d268b6'),'callback_data'=>'bankReviewInbox']]]);
        } catch (Throwable $deliveryError) {
            error_log('achbot_service_recovery_owner_delivery_failed class='.
                get_class($deliveryError));
        }
    }
    return;
}

// Owner-only, provider-evidence-gated finance inbox. These buttons are
// read-only. Do not repurpose historical manual receipt approval handlers for
// a hosted payment: a click is not settlement or refund authorization.
$inboxParts=[];
$isInbox=preg_match('/^npReviewInbox(?:_([0-9]{14})_([1-9][0-9]{0,17}))?$/D',
                    (string)$data,$inboxParts)===1;
if ($isInbox ||
    preg_match('/^npReview(?:Case|Refresh|Verify)_[1-9][0-9]{0,17}(?:_(?:[0-9]|1[01]))?(?:_[1-9][0-9]{0,17})?$/D',
               (string)$data)) {
    if ((int)$from_id !== (int)$admin ||
        (int)($chat_id??0) !== (int)$admin) {
        answerCallbackQuery($callbackId,\achbotMessage('manual_payment.manual_receipts.alert.f961d21391'),true);
        return;
    }
    if (!defined('ACHBOT_NP_RECONCILE_ONLY')) define('ACHBOT_NP_RECONCILE_ONLY',true);
    require_once __DIR__ . '/../GatewayReturn.php';
    try {
        $scope=achbotNpOperatorWorkSql();
        if ($isInbox) {
            // Stable keyset order for the owner worklist: multiple cases may
            // have the same second-resolution updated_at timestamp.
            $cursorText=(string)($inboxParts[1]??'');
            $cursorId=(int)($inboxParts[2]??0);
            $hasCursor=$cursorText!=='';
            if ($hasCursor) {
                $cursorTime=DateTimeImmutable::createFromFormat('!YmdHis',$cursorText);
                if (!$cursorTime || $cursorTime->format('YmdHis')!==$cursorText) {
                    answerCallbackQuery($callbackId,\achbotMessage('manual_payment.manual_receipts.alert.b4db548d5f'),true);
                    return;
                }
                $cursorDate=$cursorTime->format('Y-m-d H:i:s');
            }
            $listSql="SELECT a.id,a.order_hash,p.price,p.user_id,
                    a.provider_status,a.updated_at
                FROM payment_attempts a JOIN pays p ON p.id=a.pay_row_id
                WHERE ($scope)";
            if ($hasCursor)
                $listSql.=" AND (a.updated_at < ? OR
                                 (a.updated_at = ? AND a.id < ?))";
            $listSql.=" ORDER BY a.updated_at DESC,a.id DESC LIMIT 13";
            $stmt=$connection->prepare($listSql);
            if ($hasCursor)
                $stmt->bind_param('ssi',$cursorDate,$cursorDate,$cursorId);
            if (!$stmt->execute())
                throw new RuntimeException('operator_inbox_page_read_failed');
            $items=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            $hasOlder=count($items)>12;
            $items=array_slice($items,0,12);
            $rows=[];
            foreach ($items as $item) {
                $label=\achbotMessage('manual_payment.manual_receipts.button.8b87c9c9f0').(int)$item['id'].' | '.
                    number_format((int)$item['price']).\achbotMessage('manual_payment.manual_receipts.text.e9f634ddbd').
                    (int)$item['user_id'];
                $rows[]=[['text'=>$label,
                    'callback_data'=>'npReviewCase_'.(int)$item['id']]];
            }
            if (!$items) {
                $body=$hasCursor
                    ? \achbotMessage('manual_payment.manual_receipts.text.46ec3f45b9')
                    : \achbotMessage('manual_payment.manual_receipts.text.40b9cc08aa').
                      \achbotMessage('manual_payment.manual_receipts.text.1ce7bc1f9c');
            } else {
                $body=\achbotMessage('manual_payment.manual_receipts.body.13e6c95688').
                    \achbotMessage('manual_payment.manual_receipts.text.e06224688d').
                    \achbotMessage('manual_payment.manual_receipts.text.1993d8c086');
            }
            if ($hasOlder) {
                // Anchor after the last displayed row; next page excludes it.
                $last=$items[count($items)-1];
                $stamp=str_replace(['-',' ',':'],'',(string)$last['updated_at']);
                if (!preg_match('/^[0-9]{14}$/D',$stamp))
                    throw new RuntimeException('operator_inbox_cursor_unconfirmed');
                $rows[]=[['text'=>\achbotMessage('manual_payment.manual_receipts.button.2a07cf7022'),
                    'callback_data'=>'npReviewInbox_'.$stamp.'_'.(int)$last['id']]];
            }
            if ($hasCursor)
                $rows[]=[['text'=>\achbotMessage('manual_payment.manual_receipts.button.407c2a3b62'),
                    'callback_data'=>'npReviewInbox']];
            $rows[]=[['text'=>\achbotMessage('manual_payment.manual_receipts.button.0fbb8a589a'),'callback_data'=>'attentionInbox']];
            $rows[]=[['text'=>\achbotMessage('manual_payment.manual_receipts.button.02aaa18210'),
                       'callback_data'=>'bankReviewInbox']];
            $rows[]=[['text'=>\achbotMessage('manual_payment.manual_receipts.button.c7ac8dfee0'),'callback_data'=>'managePanel']];
            answerCallbackQuery($callbackId,\achbotMessage('manual_payment.manual_receipts.alert.3f27859840'));
            editText($message_id,$body,json_encode(['inline_keyboard'=>$rows],
                JSON_UNESCAPED_UNICODE),'HTML');
            return;
        }
        preg_match('/^npReview(Case|Refresh|Verify)_([1-9][0-9]{0,17})(?:_([0-9]|1[01]))?(?:_([1-9][0-9]{0,17}))?$/D',
                   (string)$data,$parts);
        $action=$parts[1];
        $attemptId=(int)$parts[2];
        $childIndex=(int)($parts[3]??0);
        $cursor=(int)($parts[4]??0);
        $stmt=$connection->prepare("SELECT a.*,p.type,p.price,p.user_id,
                    p.state AS pay_state,p.plan_id,
                    (SELECT u.name FROM users u WHERE u.userid=p.user_id
                     ORDER BY u.id DESC LIMIT 1) AS user_name,
                    (SELECT u.username FROM users u WHERE u.userid=p.user_id
                     ORDER BY u.id DESC LIMIT 1) AS user_handle,
                    (SELECT sp.title FROM server_plans sp WHERE sp.id=p.plan_id
                     LIMIT 1) AS plan_title
            FROM payment_attempts a JOIN pays p ON p.id=a.pay_row_id
            WHERE a.id=? AND a.provider='nowpayments' LIMIT 1");
        $stmt->bind_param('i',$attemptId);
        $stmt->execute();
        $case=$stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$case) {
            answerCallbackQuery($callbackId,\achbotMessage('manual_payment.manual_receipts.alert.5e6ddc1f5c'),true);
            return;
        }
        // Cursor is the first ledger row of this page. Fetch one extra row
        // only to provide a bounded next-page link, never truncate history.
        $stmt=$connection->prepare("SELECT id,payment_id,observed_status,received_at
            FROM payment_transactions
            WHERE attempt_id=? AND provider='nowpayments' AND id>=?
            ORDER BY id ASC LIMIT 13");
        $stmt->bind_param('ii',$attemptId,$cursor);
        if (!$stmt->execute()) throw new RuntimeException('operator_children_read_failed');
        $children=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $nextCursor=count($children)>12?(int)$children[12]['id']:0;
        $children=array_slice($children,0,12);
        if ($action==='Verify' && !isset($children[$childIndex]))
            throw new RuntimeException('operator_child_selection_invalid');
        $esc=static fn($value) => htmlspecialchars(
            substr((string)($value??''),0,128),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $subject=match ((string)$case['type']) {
            'INCREASE_WALLET'=>\achbotMessage('manual_payment.manual_receipts.text.991ae08d64'),
            'BUY_SUB'=>\achbotMessage('manual_payment.manual_receipts.text.e6343571a4'),
            'RENEW_ACCOUNT'=>\achbotMessage('manual_payment.manual_receipts.text.df1c62b99e'),
            default=>\achbotMessage('manual_payment.manual_receipts.text.df83eb82dd'),
        };
        $handle=ltrim((string)($case['user_handle']??''),'@');
        $body=\achbotMessage('manual_payment.manual_receipts.body.8725ef87b4').$attemptId."</b>\n\n".
              \achbotMessage('manual_payment.manual_receipts.text.73d52bae11').$esc($case['user_name']?:\achbotMessage('manual_payment.manual_receipts.text.f07a81a062')).
              ($handle!==''?' (@'.$esc($handle).')':'')."\n".
              \achbotMessage('manual_payment.manual_receipts.text.7b98936196').(int)$case['user_id']."</code>\n".
              \achbotMessage('manual_payment.manual_receipts.text.cc92486df2').$subject.
              (!empty($case['plan_title'])?\achbotMessage('manual_payment.manual_receipts.text.3ecde6c863').$esc($case['plan_title']):'')."\n".
              \achbotMessage('manual_payment.manual_receipts.text.4edea352b1').number_format((int)$case['price']).\achbotMessage('manual_payment.manual_receipts.text.81a62ff6c6').
              \achbotMessage('manual_payment.manual_receipts.text.8ec86d6a5e').$esc($case['quote_amount']).' '.
              $esc($case['quote_currency'])."\n".
              \achbotMessage('manual_payment.manual_receipts.text.36ea78fe22').
              \achbotMessage('manual_payment.manual_receipts.text.15263040e4').$esc($case['order_hash'])."</code>\n".
              \achbotMessage('manual_payment.manual_receipts.text.d5edc0c62b').$esc($case['provider_reference'])."</code>\n".
              \achbotMessage('manual_payment.manual_receipts.text.701b748131').$esc($case['payment_id']?:\achbotMessage('manual_payment.manual_receipts.text.5749a86140'))."</code>\n".
              \achbotMessage('manual_payment.manual_receipts.text.293c249101').$esc($case['stage'])."</code>\n".
              \achbotMessage('manual_payment.manual_receipts.text.91c6437ebc').$esc($case['updated_at'])."\n\n".
              \achbotMessage('manual_payment.manual_receipts.text.50a075354e').
              ($cursor>0?\achbotMessage('manual_payment.manual_receipts.text.b93ce429b1'):'');
        foreach ($children as $i=>$child) {
            $status=match ((string)$child['observed_status']) {
                'waiting'=>\achbotMessage('manual_payment.manual_receipts.text.24b45eeaa4'),
                'failed','expired'=>\achbotMessage('manual_payment.manual_receipts.text.3425d64f6c'),
                'confirming'=>\achbotMessage('manual_payment.manual_receipts.text.06ddd1f056'),
                'confirmed'=>\achbotMessage('manual_payment.manual_receipts.text.6a31c94af4'),
                'sending'=>\achbotMessage('manual_payment.manual_receipts.text.6935f64e3e'),
                'partially_paid'=>\achbotMessage('manual_payment.manual_receipts.text.21678e358d'),
                'finished'=>\achbotMessage('manual_payment.manual_receipts.text.5ddc257365'),
                'refunded'=>\achbotMessage('manual_payment.manual_receipts.text.6fdf2d7c0c'),
                default=>\achbotMessage('manual_payment.manual_receipts.text.582a8d1e93'),
            };
            $body.=\achbotMessage('manual_payment.manual_receipts.body.8cd41a21bb').$esc($child['payment_id']).'</code>: '.$status.
                \achbotMessage('manual_payment.manual_receipts.text.028fd2b704').$esc($child['received_at'])."\n";
        }
        if (!$children) $body.=\achbotMessage('manual_payment.manual_receipts.body.2f148e055f');
        if ($action==='Verify') {
            // Each callback makes AT MOST ONE provider GET (7s timeout),
            // avoiding a 5x7s webhook stall and allowing sequential review
            // of each child without storing broader merchant credentials.
            $apiKey=(string)($paymentKeys['nowpayment']??'');
            $body.=\achbotMessage('manual_payment.manual_receipts.body.96d1157ea2');
            if ($apiKey==='') {
                $body.=\achbotMessage('manual_payment.manual_receipts.body.787ece4365');
            } else {
                foreach (array_slice($children,$childIndex,1) as $child) {
                    $remote=achbotNpGetVerifiedPayment(
                        (string)$child['payment_id'],$apiKey);
                    if (!$remote ||
                        (string)($remote['payment_id']??'')!==(string)$child['payment_id'] ||
                        (string)($remote['invoice_id']??'')!==(string)$case['provider_reference'] ||
                        (string)($remote['order_id']??'')!==(string)$case['order_hash']) {
                        $body.=\achbotMessage('manual_payment.manual_receipts.body.8cd41a21bb').$esc($child['payment_id']).
                            \achbotMessage('manual_payment.manual_receipts.text.bf0550b9ae');
                        continue;
                    }
                    $raw=(string)($remote['payment_status']??'');
                    $kind=achbotNpChildEvidenceRank($raw)>0
                        ? \achbotMessage('manual_payment.manual_receipts.text.90eb6509fc')
                        : \achbotMessage('manual_payment.manual_receipts.text.bb5fcc74b7');
                    $body.=\achbotMessage('manual_payment.manual_receipts.body.8cd41a21bb').$esc($child['payment_id']).
                        '</code>: '.$esc($raw).\achbotMessage('manual_payment.manual_receipts.text.3b2e8119b6').$kind."\n";
                    // These monetary observations are provider-readonly,
                    // after strict payment/invoice/order identity matching.
                    $gross=(string)($remote['actually_paid']??'');
                    $asset=(string)($remote['pay_currency']??'');
                    $net=(string)($remote['outcome_amount']??'');
                    $netAsset=(string)($remote['outcome_currency']??'');
                    $validAmount=static fn($value): bool => preg_match(
                        '/^\d{1,18}(?:\.\d{1,18})?$/D',(string)$value)===1;
                    if (achbotNpChildEvidenceRank($raw)>0 &&
                        $validAmount($gross) &&
                        preg_match('/^[a-z0-9]{2,32}$/iD',$asset)) {
                        $body.=\achbotMessage('manual_payment.manual_receipts.body.a4a120e0d3').$esc($gross).' '.
                            $esc($asset)."\n";
                    }
                    if (achbotNpChildEvidenceRank($raw)>0 &&
                        $validAmount($net) &&
                        preg_match('/^[a-z0-9]{2,32}$/iD',$netAsset)) {
                        $body.=\achbotMessage('manual_payment.manual_receipts.body.8efe531abe').$esc($net).' '.
                            $esc($netAsset)."\n";
                    }
                }
            }
        }
        $body.=\achbotMessage('manual_payment.manual_receipts.body.b167f67bcd').
            \achbotMessage('manual_payment.manual_receipts.text.f4e8720908');
        $pageSuffix=$cursor>0?'_0_'.$cursor:'';
        $buttons=[
            [['text'=>\achbotMessage('manual_payment.manual_receipts.button.462a4081da'),
              'callback_data'=>'npReviewRefresh_'.$attemptId.$pageSuffix]],
        ];
        if ($children) {
            $next=$action==='Verify'
                ? ($childIndex+1)%count($children) : 0;
            $buttons[]=[['text'=>\achbotMessage('manual_payment.manual_receipts.button.5fb8e2523c').($next+1).\achbotMessage('manual_payment.manual_receipts.text.a624a1e41f').count($children),
                         'callback_data'=>'npReviewVerify_'.$attemptId.'_'.$next.
                             ($cursor>0?'_'.$cursor:'')]];
        }
        if ($nextCursor>0) {
            $buttons[]=[['text'=>\achbotMessage('manual_payment.manual_receipts.button.af4b7d7113'),
                'callback_data'=>'npReviewCase_'.$attemptId.'_0_'.$nextCursor]];
        }
        $buttons[]=[['text'=>\achbotMessage('manual_payment.manual_receipts.button.014e4e047f'),
                     'callback_data'=>'npReviewInbox']];
        answerCallbackQuery($callbackId,\achbotMessage('manual_payment.manual_receipts.alert.671dc77b41'));
        editText($message_id,$body,json_encode(['inline_keyboard'=>$buttons],
            JSON_UNESCAPED_UNICODE),'HTML');
    } catch (Throwable $error) {
        error_log('achbot_payment_operator_inbox_unavailable');
        answerCallbackQuery($callbackId,
            \achbotMessage('manual_payment.manual_receipts.alert.55c0496662'),true);
    }
    return;
}

if (preg_match('/^approvePayment(.*)/', $data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $hash_id = $match[1];
    // Keep the existing receipt display identifiers for post-commit updates.
    $stmt = $connection->prepare('SELECT * FROM pays WHERE hash_id=? LIMIT 1');
    $stmt->bind_param('s', $hash_id);
    $stmt->execute();
    $payInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$payInfo) {
        alert(\achbotMessage('manual_payment.manual_receipts.alert.9e5ae0bfa1'));
        return;
    }
    $approved = achbotApproveManualWalletTopup($connection, $hash_id, (int)$from_id);
    if ($approved === null) {
        alert(\achbotMessage('manual_payment.manual_receipts.alert.2d252fd96f'));
        return;
    }

    // These values are read from the committed transaction, not from stale
    // Telegram buttons or a pre-commit estimate.
    $price = $approved['price'];
    $userId = $approved['user_id'];
    $currentWallet = $approved['wallet_before'];
    $newWallet = $approved['wallet_after'];
    $methodName = achbotManualLabel(
        achbotManualSelectedMethod($connection, $payInfo) ?? 'bank_transfer'
    );

    $keys = json_encode([
        'inline_keyboard' => [
            [['text' => \achbotMessage('manual_payment.manual_approval.manual_standard_report_delivery.button.139c829462', ['from_id' => $from_id]), 'callback_data' => "wizwizch"]],
            [['text' => \achbotMessage('manual_payment.manual_approval.manual_standard_report_delivery.hash_id.2899228604', ['hash_id' => $hash_id]), 'callback_data' => "noop_$hash_id"]]
        ]
    ], 488);

    // ویرایش پیام ادمین اصلی
    try {
        editKeys($keys, $payInfo['message_id'], $payInfo['chat_id']);
    } catch (Throwable $ignored) {
        // Keep the buyer notification attempt even if editing Telegram fails.
    }

    // ویرایش پیام سایر ادمین‌ها
    $adminMessages = json_decode($payInfo['admins_messages'], true);
    if (is_array($adminMessages)) {
        foreach ($adminMessages as $msgInfo) {
            if (
                isset($msgInfo['chat_id'], $msgInfo['message_id']) &&
                $msgInfo['chat_id'] != $payInfo['chat_id']
            ) {
                try {
                    editKeys($keys, $msgInfo['message_id'], $msgInfo['chat_id']);
                } catch (Throwable $ignored) {
                    // One stale administrator message must not block the buyer.
                }
            }
        }
    }

    // ارسال پیام به ادمین‌ها
    $adminMessages = \achbotMessage('manual_payment.manual_receipts.admin_messages.31d90c5e69', ['methodName' => $methodName, 'userId' => $userId]) . number_format($currentWallet) . \achbotMessage('manual_payment.manual_receipts.text.c0780ce268') . number_format($newWallet) . \achbotMessage('manual_payment.manual_receipts.text.89a4910ebe', ['hash_id' => $hash_id]);
    sendToAllAdmins('sendMessage', ['text' => $adminMessages, 'parse_mode' => "html"]);
    
    // ارسال پیام به ادمین خاص
    sendMessage($adminMessages, null, "html", $admin);

    // ارسال پیام به کاربر
    sendMessage(\achbotMessage('manual_payment.manual_receipts.message.f568baa065') . number_format($price) . \achbotMessage('manual_payment.manual_receipts.message.f6331b33af', ['supportID' => $supportID]), null, "HTML", $userId);
}

// Decline Cart To Cart by ACh

if (preg_match('/^noop_(.+)$/', $data, $m)) {
	$hash_id = $m[1];
	sendMessage(\achbotMessage('manual_payment.manual_receipts.hash__i_d_hash_id.0ce5b30b71', ['hash_id' => $hash_id]), null, "HTML", $from_id);
	exit;
}

if (preg_match('/^declineAction_(.+)$/', $data, $match) && ($from_id == $admin || !empty($userInfo['isAdmin']))) {
    $hash_id = $match[1];

    // بررسی وجود و وضعیت پرداخت
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $hash_id);
    $stmt->execute();
    $payInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();

	if (!$payInfo || ($payInfo['state'] === 'expired_have_sent')) {
		alert(!$payInfo ? \achbotMessage('manual_payment.manual_receipts.alert.9e5ae0bfa1') : \achbotMessage('manual_payment.manual_receipts.alert.caa1f4e27c'));
		return;
	}

    if ($payInfo['state'] === 'approved' || $payInfo['state'] === 'declined') {
        alert(\achbotMessage('manual_payment.manual_receipts.alert.af4cc41dc0'));
        return;
    }

    setUser("declineActionReason_" . $hash_id . "_" . $message_id);
    sendMessage(\achbotMessage('manual_payment.manual_receipts.message.a757d0e2a6'), $cancelKey);
}

if (preg_match('/^declineActionReason_(.+)_(\d+)$/', $userInfo['step'], $match) &&
    $text != $buttonValues['cancel'] &&
    ($from_id == $admin || !empty($userInfo['isAdmin']))) {
    setUser();
    $hash_id = $match[1];
    $message_id = $match[2];

    // دریافت اطلاعات پرداخت
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $hash_id);
    $stmt->execute();
    $payInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
	
	if (!$payInfo || ($payInfo['state'] === 'expired_have_sent')) {
		alert(!$payInfo ? \achbotMessage('manual_payment.manual_receipts.alert.9e5ae0bfa1') : \achbotMessage('manual_payment.manual_receipts.alert.caa1f4e27c'));
		return;
	}

    if ($payInfo['state'] === 'approved' || $payInfo['state'] === 'declined') {
		alert(\achbotMessage('manual_payment.manual_receipts.alert.af4cc41dc0'));
		return;
	}

    $uid = $payInfo['user_id'];
    $price = !empty($payInfo['price']) ? number_format($payInfo['price']) . \achbotMessage('manual_payment.automatic_receipts.text.2cc98f7f7c') : "---";
    $reason = $text;
	$remark = "---";
	
	// تلاش برای گرفتن ریمارک دقیق
	if (!empty($payInfo['order_id'])) {
		$stmt = $connection->prepare("SELECT `remark` FROM `orders_list` WHERE `id` = ?");
		$stmt->bind_param("i", $payInfo['order_id']);
		$stmt->execute();
		$stmt->bind_result($remarkFromOrder);
		if ($stmt->fetch()) {
			$remark = $remarkFromOrder ?: "---";
		}
		$stmt->close();
	} elseif (!empty($payInfo['description'])) {
		$remark = $payInfo['description'];
	}

    // ثبت در دیتابیس
    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'declined', `approved_by` = ?
        WHERE `hash_id` = ? AND `state` = 'have_sent'");
    $stmt->bind_param("is", $from_id, $hash_id);
    $stmt->execute();
    $declined = $stmt->affected_rows === 1;
    $stmt->close();
    if (!$declined) {
        alert(\achbotMessage('manual_payment.manual_receipts.alert.872f260882'));
        return;
    }
	
    $keys = json_encode(['inline_keyboard' => [
        [['text' => \achbotMessage('manual_payment.manual_receipts.button.51fdc91d69', ['from_id' => $from_id]), 'callback_data' => "wizwizch"]],
		[['text' => \achbotMessage('manual_payment.manual_approval.manual_standard_report_delivery.hash_id.2899228604', ['hash_id' => $hash_id]), 'callback_data' => "noop_$hash_id"]]
    ]]);

    // ویرایش دکمه ادمین اصلی
    editKeys($keys, $payInfo['message_id'], $payInfo['chat_id']);

    // ویرایش دکمه سایر ادمین ها
    $adminMessages = json_decode($payInfo['admins_messages'], true);
    if (is_array($adminMessages)) {
        foreach ($adminMessages as $msgInfo) {
            if (
                isset($msgInfo['chat_id'], $msgInfo['message_id']) &&
                $msgInfo['chat_id'] != $payInfo['chat_id']
            ) {
                editKeys($keys, $msgInfo['message_id'], $msgInfo['chat_id']);
            }
        }
    }

	// ارسال پیام برای کاربر
	$retryButton = json_encode([
		'inline_keyboard' => [
			[
				[
					'text' => \achbotMessage('manual_payment.manual_receipts.button.e3517e568b'),
					'url'  => "https://t.me/$botUsername?start=restart_$hash_id"
				]
			]
		]
	]);
    $message = \achbotMessage('manual_payment.manual_receipts.message.bc0155976d', ['reason' => $reason]);
    $message .= \achbotMessage('manual_payment.manual_receipts.message.c4d810a7d3', ['price' => $price]);
    $message .= \achbotMessage('manual_payment.manual_receipts.message.61d75413a2', ['remark' => $remark]);
	$message .= \achbotMessage('manual_payment.manual_receipts.message.df4f415ed5', ['hash_id' => $hash_id]);
    sendMessage($message, $retryButton, "HTML", $uid);

	$adminAfterSendKeys = json_encode([
		'inline_keyboard' => [
			[
				['text' => \achbotMessage('manual_payment.manual_receipts.button.7918a2f411'), 'callback_data' => "banUserDirect_$uid"]
			]
		]
	]);

	sendMessage(
		\achbotMessage('manual_payment.manual_receipts.message.667f0ab959', ['uid' => $uid, 'price' => $price, 'remark' => $remark, 'hash_id' => $hash_id]),
		$adminAfterSendKeys,
		"html"
	);
}

if (is_string($data) &&
    preg_match('/\AbanUserDirect_([1-9][0-9]{0,18})\z/D',$data,$m)===1) {
    $status=achbotLegacyModerationTransition(
        $connection,$update,(int)$from_id,(int)$chat_id,(int)$message_id,
        $userInfo,(int)$admin,'receiptBan',$m[1]);
    if ($status==='changed') {
        alert(\achbotMessage('manual_payment.manual_receipts.alert.61d5318e58', ['m_1' => $m[1]]));
    } elseif ($status==='already') {
        alert(\achbotMessage('manual_payment.manual_receipts.alert.8b8e660e87'));
    } else {
        alert(\achbotMessage('manual_payment.manual_receipts.alert.abf0b6c70d'),true);
    }
    // Do not claim or mark a failed/unknown callback as committed.
    if ($status==='changed' || $status==='already') {
        $doneKeys=json_encode(['inline_keyboard'=>[
            [['text'=>\achbotMessage('manual_payment.manual_receipts.button.85f4d0ad41', ['m_1' => $m[1]]),'callback_data'=>"wizwizch"]]
        ]]);
        editKeys($doneKeys,$message_id,$chat_id);
    }
}

// END Decline Cart To Cart by ACh

