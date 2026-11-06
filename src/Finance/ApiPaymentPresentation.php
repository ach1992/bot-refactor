<?php
/**
 * Informational projection of the ORIGINAL payment plus locally verified
 * subscription facts. No provider HTTP, financial side effects or SQL writes.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

function achbotApiPaymentPresentation(mysqli $db,array $pay,array $attempt): array {
    $type=(string)($pay['type']??'');
    $uid=(int)($pay['user_id']??0);
    $pid=(int)($pay['plan_id']??0);
    $sid=(int)($pay['server_id']??0);
    $orderId=(int)($pay['order_id']??0);
    if (preg_match('/^INCREASE_(?:DAY|VOLUME)_([1-9][0-9]*)_[1-9][0-9]*$/D',$type,$m))
        $orderId=(int)$m[1];
    $label=payTypeToDescription($type);
    $display=$label;
    $profile=achbotPaymentEvidenceSnapshot($attempt);
    $terms=is_array($profile['subject']??null)?$profile['subject']:[];
    $days=(int)($terms['days']??0);
    $volume=(int)($terms['volume']??0);
    $count=max(1,(int)($terms['count']??1));
    $userName='';$userHandle='';$serverName=$sid>0?'#'.$sid:'';
    $planName=$pid>0?'#'.$pid:'';
    $accountNames=[];$recordedCount=0;
    try {
        if ($uid>0) {
            $q=$db->prepare('SELECT name,username FROM users WHERE userid=? ORDER BY id DESC LIMIT 1');
            $q->bind_param('i',$uid);$q->execute();
            $u=$q->get_result()->fetch_assoc();$q->close();
            $userName=(string)($u['name']??'');
            $userHandle=ltrim((string)($u['username']??''),'@');
        }
        if ($sid>0) {
            $q=$db->prepare('SELECT remark FROM server_info WHERE id=? LIMIT 1');
            $q->bind_param('i',$sid);$q->execute();
            $s=$q->get_result()->fetch_assoc();$q->close();
            if (is_string($s['remark']??null) && $s['remark']!=='')
                $serverName=(string)$s['remark'].' (#'.$sid.')';
        }
        if ($pid>0) {
            $q=$db->prepare('SELECT title FROM server_plans WHERE id=? LIMIT 1');
            $q->bind_param('i',$pid);$q->execute();
            $p=$q->get_result()->fetch_assoc();$q->close();
            if (is_string($p['title']??null) && $p['title']!=='')
                $planName=(string)$p['title'].' (#'.$pid.')';
        }
        if ($orderId>0 && $uid>0) {
            $q=$db->prepare('SELECT remark FROM orders_list WHERE id=? AND userid=? LIMIT 1');
            $q->bind_param('ii',$orderId,$uid);$q->execute();
            $o=$q->get_result()->fetch_assoc();$q->close();
            if (is_string($o['remark']??null) && $o['remark']!=='')
                $display=(string)$o['remark'].' (#'.$orderId.')';
        }
        if ($type==='BUY_SUB' && (int)($pay['id']??0)>0) {
            $id=(int)$pay['id'];
            // Only locally recorded CREATE effects identify issued accounts.
            // A dispatching/unknown remote effect must never be called created.
            $q=$db->prepare("SELECT COUNT(*) AS count FROM service_effects
                WHERE pay_row_id=? AND effect_kind='create' AND state='local_recorded'");
            $q->bind_param('i',$id);$q->execute();
            $recordedCount=(int)($q->get_result()->fetch_assoc()['count']??0);$q->close();
            $q=$db->prepare("SELECT remote_username,order_id FROM service_effects
                WHERE pay_row_id=? AND effect_kind='create' AND state='local_recorded'
                ORDER BY ordinal ASC LIMIT 8");
            $q->bind_param('i',$id);$q->execute();
            $rows=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
            foreach ($rows as $row) {
                $name=(string)($row['remote_username']??'');
                if ($name==='' || strlen($name)>100 || !preg_match('/^[A-Za-z0-9_-]+$/D',$name))continue;
                $oid=(int)($row['order_id']??0);
                $accountNames[]=$name.($oid>0?' (#'.$oid.')':'');
            }
            if ($accountNames)$display=$accountNames[0].($recordedCount>1?' +'.($recordedCount-1):'');
            elseif ($planName!=='')$display=$planName;
        } elseif($type==='RENEW_SCONFIG' && $display===$label) {
            $decoded=json_decode((string)($pay['description']??''),true);
            $remark=is_array($decoded)?($decoded['remark']??null):null;
            if (is_string($remark) && $remark!=='' && strlen($remark)<=100)
                $display=$remark;
        }
    } catch (Throwable $error) {
        error_log('achbot_api_presentation_read_deferred pay_id='.(int)($pay['id']??0).
            ' class='.get_class($error));
    }
    if ($type!=='INCREASE_WALLET' && $display===$label && $planName!=='')$display=$planName;

    // The existing admin catalog owns Persian text. HTML substitution values
    // are escaped individually, and report length is checked by the caller.
    $escape=static fn($value):string=>htmlspecialchars(
        substr((string)$value,0,256),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $subject=$display;
    if ($type==='BUY_SUB' && $accountNames) {
        $subject=implode(', ',$accountNames);
        if ($recordedCount>count($accountNames))$subject.=' +'.($recordedCount-count($accountNames));
        if ($recordedCount<$count)
            $subject.=' ('.$recordedCount.'/'.$count.')';
    }
    $templates=$GLOBALS['mainValues']??achbotMessages('legacy_messages');
    $templateKey=match(true) {
        $type==='INCREASE_WALLET'=>'increase_wallet_request_message',
        $type==='BUY_SUB'=>'buy_new_account_request',
        in_array($type,['RENEW_ACCOUNT','RENEW_SCONFIG'],true)=>'renew_account_request_message',
        default=>''
    };
    $template=is_array($templates)&&$templateKey!==''?($templates[$templateKey]??''):'';
    if (!is_string($template) || (($type!=='INCREASE_WALLET') && !$terms))
        $template='';
    $typeWord=achbotManualLabel((string)($attempt['provider']??''));
    if ($template!=='') {
        $template=str_replace(
            ['USER-ID','USERNAME','NAME','PRICE','SERVERNAME','REMARK','VOLUME','DAYS','INCREASE','TYPE'],
            [(string)$uid,$escape($userHandle),$escape($userName),(string)(int)($pay['price']??0),
             $escape($serverName),$escape($subject),(string)$volume,(string)$days,
             (string)(int)($terms['units']??0),$escape($typeWord)],
            $template);
    }
    return ['subject'=>$display,'details'=>$template,'account_count'=>$count,
        'confirmed_accounts'=>$recordedCount];
}

function achbotApiPaymentRichReport(array $pay,string $body,array $presentation): string {
    $details=(string)($presentation['details']??'');
    if ($details==='' || strlen($body)+strlen($details)>3500)return $body;
    return $body."\n\n".$details;
}

/**
 * Customer success is the existing MANUAL path copy, never an API-specific
 * confirmation. This module only renders/notifies; financial and native
 * effects have already committed under the original payment identity.
 * A failed or ambiguous Telegram acknowledgement is NOT successful delivery.
 */
function achbotApiPaymentManualCustomerText(mysqli $db,array $pay): ?string {
    $type=(string)($pay['type']??'');
    $uid=(int)($pay['user_id']??0);
    if($uid<=0)return null;
    if($type==='INCREASE_WALLET') {
        return achbotMessage('manual_payment.manual_receipts.message.f568baa065').
            number_format((int)$pay['price']).
            achbotMessageHtml('manual_payment.manual_receipts.message.f6331b33af',
                ['supportID'=>(string)($GLOBALS['supportID']??'')]);
    }
    $remark='';
    if($type==='RENEW_SCONFIG') {
        $data=json_decode((string)($pay['description']??''),true);
        $remark=is_array($data)?(string)($data['remark']??''):'';
    } else {
        $orderId=(int)($pay['order_id']??0);
        if(preg_match('/^INCREASE_(?:DAY|VOLUME)_([1-9][0-9]*)_[1-9][0-9]*$/D',$type,$match))
            $orderId=(int)$match[1];
        if($orderId<=0)return null;
        try {
            $stmt=$db->prepare('SELECT remark FROM orders_list WHERE id=? AND userid=? LIMIT 1');
            $stmt->bind_param('ii',$orderId,$uid);
            $stmt->execute();
            $row=$stmt->get_result()->fetch_assoc();$stmt->close();
            $remark=(string)($row['remark']??'');
        } catch(Throwable $error) {
            error_log('achbot_api_customer_subject_read_deferred pay_id='.(int)($pay['id']??0));
            return null;
        }
    }
    if($remark==='')return null;
    if($type==='RENEW_SCONFIG')
        return achbotMessage('manual_payment.automatic_receipts.message.cca1075329',['remark'=>$remark]);
    if($type==='RENEW_ACCOUNT')
        return achbotMessage('renewal.renewal_checkout.message.47b1149fb0',['remark'=>$remark]);
    $units=achbotPinnedAddonUnits($pay);
    if(!is_int($units)||$units<=0)return null;
    if(str_starts_with($type,'INCREASE_DAY_'))
        return achbotMessage('renewal.extensions.message.24dea9b122',['volume'=>$units,'remark'=>$remark]);
    if(str_starts_with($type,'INCREASE_VOLUME_'))
        return achbotMessage('renewal.extensions.message.d5b6a4e6e7',['volume'=>$units,'remark'=>$remark]);
    return null;
}
function achbotApiPaymentDeliverManualCustomer(mysqli $db,array $pay): bool {
    $text=achbotApiPaymentManualCustomerText($db,$pay);
    if($text===null)return false;
    try {
        $mode=(string)$pay['type']==='RENEW_SCONFIG'?null:'HTML';
        return achbotApiPaymentAck(sendMessage($text,null,$mode,(int)$pay['user_id']));
    } catch(Throwable $error) {
        error_log('achbot_api_customer_delivery_unconfirmed pay_id='.(int)$pay['id'].
            ' class='.get_class($error));
        return false;
    }
}

function achbotApiPaymentFinalKeyboard(array $pay,string $fallback,bool $automatic,array $presentation): string {
    $hash=(string)($pay['hash_id']??'');
    $actor=(int)($pay['approved_by']??0);
    $approver=$automatic?achbotMessage('manual_payment.api_payment.auto_approved'):
        ($actor>0?achbotMessage('manual_payment.manual_approval.manual_standard_report_delivery.button.139c829462',
                 ['from_id'=>$actor]):$fallback);
    $subject=(string)($presentation['subject']??payTypeToDescription((string)($pay['type']??'')));
    // A Telegram button is limited to 64 UTF-8 characters. Prefer a
    // short, readable subject and preserve the complete hash on row three.
    if (preg_match('/\A.{57}/us',$subject)) {
        if (preg_match('/\A(.{1,55})/us',$subject,$short))$subject=$short[1].'...';
    }
    $subjectButton=($pay['type']??'')==='INCREASE_WALLET'?$subject:
        achbotMessage('manual_payment.manual_approval.manual_standard_report_delivery.last_remark_for_admin_msg.2b262756e6',
            ['lastRemarkForAdminMsg'=>$subject]);
    return inlineKeyboard([
        [['text'=>$approver,'callback_data'=>'noop_'.$hash]],
        [['text'=>$subjectButton,'callback_data'=>'noop_'.$hash]],
        [['text'=>achbotMessage('manual_payment.manual_approval.manual_standard_report_delivery.hash_id.2899228604',
            ['hash_id'=>$hash]),'callback_data'=>'noop_'.$hash]]
    ]);
}
