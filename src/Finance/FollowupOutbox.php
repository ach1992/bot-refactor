<?php
/**
 * AChBot #117: payment-bound one-shot administrative/customer follow-ups.
 * The *intent* is committed with the business decision; only PENDING rows
 * may make their first external Telegram request. UNKNOWN is never retried.
 * No merchant, wallet or native-provider effect belongs to this module.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/PaymentEvidence.php';
require_once __DIR__.'/../Admin/Access.php';

function achbotFollowupSchemaReady(mysqli $db): bool {
    try {
        if (!function_exists('openssl_encrypt') ||
            strlen(achbotPaymentEvidenceKey())!==32) return false;
        $q=$db->query('SELECT id,pay_row_id,intent_key,stage,kind,role,recipient,'.
            'message_id,pay_fingerprint,sealed_payload,payload_sha256,state,'.
            'attempt_count,dispatch_token,dispatched_at,acknowledged_at,last_result '.
            'FROM payment_followup_intents LIMIT 0');
        if (!$q) return false;$q->free();
        $q=$db->query("SHOW TABLE STATUS WHERE Name='payment_followup_intents'");
        if (!$q || strtoupper((string)($q->fetch_assoc()['Engine']??''))!=='INNODB') return false;
        $q=$db->query("SHOW INDEX FROM payment_followup_intents WHERE Key_name='uq_followup_pay_key'");
        if (!$q) return false;$indexes=[];
        while ($r=$q->fetch_assoc()) {
            if ((int)$r['Non_unique']!==0) return false;
            $indexes[(int)$r['Seq_in_index']]=(string)$r['Column_name'];
        }
        ksort($indexes);
        return array_values($indexes)===['pay_row_id','intent_key'];
    } catch(Throwable $error) {return false;}
}
function achbotFollowupPurpose(int $payId,string $stage,string $key): string {
    return 'payment-followup:v1:'.$payId.':'.$stage.':'.$key;
}
function achbotFollowupIntentKey(string $stage,string $role,string $kind,
                                string $recipient,?int $messageId): string {
    return hash('sha256',$stage.'|'.$role.'|'.$kind.'|'.$recipient.'|'.($messageId??0));
}
/** Pure SQL-local staging, before the payment's terminal COMMIT. */
function achbotFollowupInsertLocked(mysqli $db,array $pay,string $stage,string $role,
                                    string $kind,string $recipient,?int $messageId,
                                    array $content): void {
    if (!in_array($stage,['approved_purchase','approved_edit','manual_reject','referral_reward'],true) ||
        !in_array($role,['customer','operator','receipt_primary','receipt_mirror',
                          'referral_admin','referral_channel','referral_inviter'],true) ||
        !in_array($kind,['send','edit'],true) ||
        preg_match('/^(?:-?[0-9]{1,19}|@[A-Za-z0-9_]{3,64})$/D',$recipient)!==1 ||
        ($kind==='edit')!==($messageId!==null && $messageId>0) ||
        !is_string($content['text']??null) || $content['text']==='' ||
        !in_array($content['mode']??null,['HTML','plain',null],true) ||
        !is_null($content['keys']??null) && !is_string($content['keys']??null))
        throw new RuntimeException('followup_packet_invalid');
    $tx=$db->query('SELECT @@in_transaction AS tx');
    if (!$tx || (int)($tx->fetch_assoc()['tx']??0)!==1)
        throw new RuntimeException('followup_requires_payment_transaction');
    $payId=(int)($pay['id']??0);
    if ($payId<=0 || (int)($pay['user_id']??0)<=0 ||
        ($stage==='approved_purchase' && ($pay['type']??'')!=='BUY_SUB') ||
        ($stage==='approved_edit' &&
            (($pay['state']??null)!=='approved' ||
             !((string)($pay['type']??'')==='RENEW_ACCOUNT' ||
               preg_match('/^INCREASE_(?:DAY|VOLUME)_[1-9][0-9]*_[1-9][0-9]*$/D',
                          (string)($pay['type']??''))===1))) ||
        ($role==='customer' && $recipient!==(string)(int)$pay['user_id']) ||
        ($role==='receipt_primary' &&
            ($recipient!==(string)(int)$pay['chat_id'] ||
             $messageId!==(int)$pay['message_id'])) ||
        ($role==='operator' && (int)($pay['approved_by']??0)>0 &&
            $recipient!==(string)(int)$pay['approved_by']))
        throw new RuntimeException('followup_pay_target_invalid');
    $key=achbotFollowupIntentKey($stage,$role,$kind,$recipient,$messageId);
    $fingerprint=achbotPaymentEvidencePayFingerprint($pay);
    $body=['v'=>1,'pay_id'=>$payId,'stage'=>$stage,'role'=>$role,
           'kind'=>$kind,'recipient'=>$recipient,'message_id'=>$messageId,
           'pay_fingerprint'=>$fingerprint,'content'=>$content];
    $sealed=achbotPaymentEvidenceProtect(
        achbotFollowupPurpose($payId,$stage,$key),$body);
    $sha=hash('sha256',$sealed);
    $q=$db->prepare("INSERT INTO payment_followup_intents
        (pay_row_id,intent_key,stage,kind,role,recipient,message_id,
         pay_fingerprint,sealed_payload,payload_sha256,state)
        VALUES (?,?,?,?,?,?,?,?,?,?,'pending')");
    $q->bind_param('isssssisss',$payId,$key,$stage,$kind,$role,$recipient,
        $messageId,$fingerprint,$sealed,$sha);
    if (!$q->execute() || $q->affected_rows!==1)
        throw new RuntimeException('followup_insert_unconfirmed');
    $q->close();
}
/** Who is STILL eligible to receive a previously intended report? */
function achbotFollowupRecipientAllowed(mysqli $db,array $pay,array $r): bool {
    $owner=(int)($GLOBALS['admin']??0);
    $recipient=(string)$r['recipient'];$who=(int)$recipient;
    $role=(string)$r['role'];
    if ($role==='customer') return $recipient===(string)(int)$pay['user_id'];
    if ($role==='receipt_primary')
        return $who===(int)$pay['chat_id'] && (int)$r['message_id']===(int)$pay['message_id'];
    if ($role==='receipt_mirror') {
        $copies=json_decode((string)($pay['admins_messages']??''),true);
        if (!is_array($copies) || !achbotAdminStoredRecipientAllowed($db,$who,$owner))
            return false;
        foreach ($copies as $copy)
            if ((int)($copy['chat_id']??0)===$who &&
                (int)($copy['message_id']??0)===(int)$r['message_id']) return true;
        return false;
    }
    if ($role==='operator') return $who===(int)$pay['approved_by'] &&
        achbotAdminCanFor($db,$who,$owner,'payments');
    if ($role==='referral_inviter') {
        $q=$db->prepare("SELECT inviter_userid,state FROM referral_awards
            WHERE pay_row_id=? AND state='CONFIRMED'");
        $id=(int)$pay['id'];$q->bind_param('i',$id);$q->execute();
        $award=$q->get_result()->fetch_assoc();$q->close();
        return $award && $who===(int)$award['inviter_userid'];
    }
    if ($role==='referral_admin') return $who===$owner ||
        achbotAdminNotificationAllowed($db,$who,$owner,'general');
    if ($role==='referral_channel') {
        $channel=(string)($GLOBALS['botState']['rewardChannel']??'');
        return $channel!=='' && $recipient===$channel;
    }
    return false;
}
function achbotFollowupValidateLocked(mysqli $db,array $pay,array $r): ?array {
    if ((int)$pay['id']!==(int)$r['pay_row_id'] ||
        !in_array((string)$r['stage'],['approved_purchase','approved_edit','manual_reject','referral_reward'],true) ||
        !in_array((string)$r['kind'],['send','edit'],true) ||
        ($r['stage']==='manual_reject' && $pay['state']!=='declined') ||
        (in_array($r['stage'],['approved_purchase','referral_reward'],true) &&
            ($pay['state']!=='approved' || $pay['type']!=='BUY_SUB')) ||
        ($r['stage']==='approved_edit' &&
            ($pay['state']!=='approved' ||
             !((string)$pay['type']==='RENEW_ACCOUNT' ||
               preg_match('/^INCREASE_(?:DAY|VOLUME)_[1-9][0-9]*_[1-9][0-9]*$/D',
                          (string)$pay['type'])===1))) ||
        (int)$pay['approved_by']<=0 ||
        !hash_equals((string)$r['pay_fingerprint'],
            achbotPaymentEvidencePayFingerprint($pay)) ||
        !hash_equals((string)$r['payload_sha256'],
            hash('sha256',(string)$r['sealed_payload'])) ||
        !hash_equals((string)$r['intent_key'],achbotFollowupIntentKey(
            (string)$r['stage'],(string)$r['role'],(string)$r['kind'],
            (string)$r['recipient'],$r['message_id']===null?null:(int)$r['message_id'])))
        return null;
    if (in_array($r['stage'],['approved_purchase','referral_reward'],true)) {
        // A forgotten local order is not authority for a purchase report.
        $q=$db->prepare("SELECT COUNT(*) AS n FROM service_effects
            WHERE pay_row_id=? AND effect_kind='create' AND state='local_recorded'
                AND order_id IS NOT NULL");
        $payId=(int)$pay['id'];$q->bind_param('i',$payId);$q->execute();
        $count=(int)($q->get_result()->fetch_assoc()['n']??0);$q->close();
        if ($count!==max(1,(int)($pay['agent_count']??0))) return null;
    }
    if ($r['stage']==='referral_reward') {
        $q=$db->prepare("SELECT state FROM referral_awards WHERE pay_row_id=?");
        $pid=(int)$pay['id'];$q->bind_param('i',$pid);$q->execute();
        $award=$q->get_result()->fetch_assoc();$q->close();
        if (($award['state']??null)!=='CONFIRMED') return null;
    }
    if (!achbotFollowupRecipientAllowed($db,$pay,$r)) return null;
    $body=achbotPaymentEvidenceUnprotect(
        achbotFollowupPurpose((int)$pay['id'],(string)$r['stage'],(string)$r['intent_key']),
        (string)$r['sealed_payload']);
    if (!is_array($body) || ($body['v']??null)!==1) return null;
    foreach (['pay_id'=>(int)$pay['id'],'stage'=>(string)$r['stage'],
       'kind'=>(string)$r['kind'],'role'=>(string)$r['role'],
       'recipient'=>(string)$r['recipient'],
       'message_id'=>$r['message_id']===null?null:(int)$r['message_id'],
       'pay_fingerprint'=>(string)$r['pay_fingerprint']] as $key=>$value)
        if (($body[$key]??null)!==$value) return null;
    $packet=$body['content']??null;
    return is_array($packet) && is_string($packet['text']??null) &&
        ($packet['text']??'')!=='' &&
        in_array($packet['mode']??null,['HTML','plain',null],true) &&
        (is_null($packet['keys']??null) || is_string($packet['keys']))
        ?$packet:null;
}
/**
 * Exactly one attempt for an intended message. A crash after CAS yields
 * dispatching (uncertain); no later ordinary callback sends it again.
 * Per-recipient attempts do not block independent pending recipients.
 */
function achbotFollowupDispatchOne(mysqli $db,int $id): string {
    if (!achbotFollowupSchemaReady($db) || $id<=0) return 'unavailable';
    $token='';$r=null;$packet=null;$opened=false;
    try {
        if (!$db->begin_transaction()) return 'unavailable';$opened=true;
        $q=$db->prepare('SELECT pay_row_id FROM payment_followup_intents WHERE id=?');
        $q->bind_param('i',$id);$q->execute();$row=$q->get_result()->fetch_assoc();$q->close();
        if (!$row) throw new RuntimeException('followup_missing');
        $payId=(int)$row['pay_row_id'];
        $q=$db->prepare('SELECT * FROM pays WHERE id=? FOR UPDATE');
        $q->bind_param('i',$payId);$q->execute();$pay=$q->get_result()->fetch_assoc();$q->close();
        $q=$db->prepare('SELECT * FROM payment_followup_intents WHERE id=? AND pay_row_id=? FOR UPDATE');
        $q->bind_param('ii',$id,$payId);$q->execute();$r=$q->get_result()->fetch_assoc();$q->close();
        if (!$r || !$pay) throw new RuntimeException('followup_missing');
        if ($r['state']!=='pending') {
            $state=(string)$r['state'];$db->rollback();$opened=false;return $state;
        }
        $packet=achbotFollowupValidateLocked($db,$pay,$r);
        if (!$packet) throw new RuntimeException('followup_identity_invalid');
        $token=bin2hex(random_bytes(16));
        $q=$db->prepare("UPDATE payment_followup_intents SET state='dispatching',
            dispatch_token=?,attempt_count=attempt_count+1,dispatched_at=NOW(),
            last_result=NULL WHERE id=? AND state='pending' AND attempt_count=0");
        $q->bind_param('si',$token,$id);
        $ok=$q->execute() && $q->affected_rows===1;$q->close();
        if (!$ok || !$db->commit()) throw new RuntimeException('followup_claim_unknown');
        $opened=false;
    } catch(Throwable $error) {
        if ($opened) try {$db->rollback();}catch(Throwable $ignored){}
        error_log('achbot_followup_prepare_unknown id='.$id.' class='.get_class($error));
        return 'unavailable';
    }
    $status='unknown';
    try {
        $target=preg_match('/^-?[0-9]+$/D',(string)$r['recipient'])===1
            ?(int)$r['recipient']:(string)$r['recipient'];
        if ($r['kind']==='edit') {
            $result=editKeys((string)$packet['text'],(int)$r['message_id'],$target);
        } else {
            $mode=($packet['mode']??null)==='plain'?null:$packet['mode'];
            $result=sendMessage((string)$packet['text'],$packet['keys']??null,$mode,$target);
        }
        if (is_object($result) && ($result->ok??false)===true)
            $status='acknowledged';
    } catch(Throwable $error) {
        error_log('achbot_followup_telegram_uncertain id='.$id.' class='.get_class($error));
    }
    try {
        $q=$db->prepare("UPDATE payment_followup_intents SET state=?,
            last_result=?,acknowledged_at=CASE WHEN ?='acknowledged' THEN NOW() ELSE NULL END,
            dispatch_token=NULL WHERE id=? AND dispatch_token=? AND state='dispatching'");
        $q->bind_param('sssis',$status,$status,$status,$id,$token);
        $ok=$q->execute() && $q->affected_rows===1;$q->close();
        if (!$ok) throw new RuntimeException('followup_ack_commit_unknown');
    } catch(Throwable $error) {
        error_log('achbot_followup_ack_unknown id='.$id);
        return 'unknown';
    }
    return $status;
}
/** All pending rows only, including previous-crash rows. UNKNOWN untouched. */
function achbotFollowupDispatchPending(mysqli $db,int $payId,string $stage): array {
    if (!achbotFollowupSchemaReady($db) || $payId<=0 ||
        !in_array($stage,['approved_purchase','approved_edit','manual_reject','referral_reward'],true)) return [];
    $cursor=0;$out=[];
    while (true) {
        $q=$db->prepare("SELECT id FROM payment_followup_intents
            WHERE pay_row_id=? AND stage=? AND state='pending' AND id>?
            ORDER BY id LIMIT 30");
        $q->bind_param('isi',$payId,$stage,$cursor);$q->execute();
        $rows=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
        if (!$rows) break;
        foreach ($rows as $row) {
            $cursor=(int)$row['id'];
            $out[$cursor]=achbotFollowupDispatchOne($db,$cursor);
        }
    }
    return $out;
}
/** Stage original standard purchase presentation BEFORE the paid COMMIT. */
function achbotFollowupStageManualPurchaseLocked(mysqli $db,array $pay,int $owner,
    string $remark,int $count,int $days,int $volume,int $price,
    string $hash,array $mainValues): void {
    if (!achbotFollowupSchemaReady($db) || $owner<=0 ||
        !function_exists('achbotManualFinalReceiptKeyboard'))
        throw new RuntimeException('manual_purchase_followup_preflight_missing');
    $payId=(int)$pay['id'];$buyer=(int)$pay['user_id'];
    $actor=(int)$pay['approved_by'];
    if ($payId<=0 || $buyer<=0 || $actor<=0 || $count<=0 ||
        !is_string($mainValues['sent_config_to_user']??null))
        throw new RuntimeException('manual_purchase_followup_identity_invalid');
    $actualSubject=$remark.($count>1?' +'.($count-1):'');
    $keys=achbotManualFinalReceiptKeyboard($db,$payId,$actualSubject);
    achbotFollowupInsertLocked($db,$pay,'approved_purchase','receipt_primary','edit',
        (string)(int)$pay['chat_id'],(int)$pay['message_id'],
        ['text'=>$keys,'keys'=>null,'mode'=>null]);
    $copies=json_decode((string)($pay['admins_messages']??''),true);
    $seenMirrors=[];
    if (is_array($copies)) foreach ($copies as $copy) {
        if (!is_array($copy) || !isset($copy['chat_id'],$copy['message_id'])) continue;
        $chat=(int)$copy['chat_id'];$msg=(int)$copy['message_id'];
        // Only the exact original Telegram pair is the primary receipt.
        // Another message in that same chat is a distinct historical copy.
        if (($chat===(int)$pay['chat_id'] && $msg===(int)$pay['message_id']) ||
            $chat<=0 || $msg<=0 ||
            !achbotAdminStoredRecipientAllowed($db,$copy['chat_id'],$owner)) continue;
        // Historical fan-out may contain repeated references to the SAME
        // Telegram message. Journal it once, preserving different messages.
        $ref=$chat.':'.$msg;
        if (isset($seenMirrors[$ref])) continue;
        achbotFollowupInsertLocked($db,$pay,'approved_purchase',
            'receipt_mirror','edit',(string)$chat,$msg,
            ['text'=>$keys,'keys'=>null,'mode'=>null]);
        $seenMirrors[$ref]=true;
    }
    $notice=str_replace(
        ['USERID','REMARK','VOLUME','DAYS','PRICE','HASHID'],
        [$buyer,$remark,$volume,$days,$price,$hash],
        (string)$mainValues['sent_config_to_user']);
    achbotFollowupInsertLocked($db,$pay,'approved_purchase','operator','send',
        (string)$actor,null,
        ['text'=>$notice,'keys'=>getMainKeys(),'mode'=>null]);
    $q=$db->prepare('SELECT name,username,refered_by FROM users WHERE userid=? LIMIT 2');
    $q->bind_param('i',$buyer);$q->execute();
    $buyers=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
    if (count($buyers)!==1) throw new RuntimeException('manual_purchase_followup_buyer_invalid');
    $user=$buyers[0];
    if ((int)$user['refered_by']>0 && $actor!==$owner) {
        if (!is_string($mainValues['invite_buy_new_account']??null))
            throw new RuntimeException('manual_purchase_invitation_template_missing');
        $actions=json_encode(['inline_keyboard'=>[[
            ['text'=>\achbotMessage('manual_payment.manual_approval.manual_standard_report_delivery.button.e401f3d5a2'),
             'callback_data'=>'wizwizch']
        ]]],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $text=str_replace(
            ['USER-ID','USERNAME','NAME','PRICE','REMARK','HASHID'],
            [$buyer,(string)$user['username'],(string)$user['name'],
             $price,$remark,$hash],
            (string)$mainValues['invite_buy_new_account']);
        $complete=false;$staff=achbotAdminRecipientCandidates($db,$owner,$complete);
        if (!$complete) throw new RuntimeException('manual_purchase_staff_discovery_unknown');
        achbotFollowupInsertLocked($db,$pay,'approved_purchase','referral_admin',
            'send',(string)$owner,null,['text'=>$text,'keys'=>$actions,'mode'=>'HTML']);
        $seen=[(string)$owner=>true];
        foreach ($staff as $id) {
            $allowed=true;$recipient=(string)(int)$id;
            if (achbotAdminNotificationAllowed($db,$id,$owner,'general',$allowed) &&
                !isset($seen[$recipient])) {
                achbotFollowupInsertLocked($db,$pay,'approved_purchase','referral_admin',
                    'send',$recipient,null,['text'=>$text,'keys'=>$actions,'mode'=>'HTML']);
                $seen[$recipient]=true;
            }
            if (!$allowed) throw new RuntimeException('manual_purchase_staff_auth_unknown');
        }
        $channel=(string)($GLOBALS['botState']['rewardChannel']??'');
        if ($channel!=='' && !isset($seen[$channel]))
            achbotFollowupInsertLocked($db,$pay,'approved_purchase','referral_channel',
                'send',$channel,null,['text'=>$text,'keys'=>$actions,'mode'=>'HTML']);
    }
}
