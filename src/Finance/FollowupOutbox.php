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
function achbotFollowupJournaled(array $r): bool {
    return isset($r['service_operation_id']) ||
        (($r['stage']??null)==='wallet_credit' && (int)($r['pay_row_id']??0)>0);
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
                                    array $content,?int $serviceId=null): void {
    if ($serviceId!==null && ($serviceId<=0 || $stage!=='referral_reward' ||
        !in_array($role,['referral_inviter','referral_admin','referral_channel'],true) ||
        $kind!=='send' || ($pay['state']??null)!=='paid'))
        throw new RuntimeException('followup_service_source_invalid');
    if (!in_array($stage,['approved_purchase','approved_edit','manual_reject','referral_reward'],true) ||
        !in_array($role,['customer','operator','receipt_primary','receipt_mirror',
                          'referral_admin','referral_channel','referral_inviter',
                          'purchase_owner','purchase_channel'],true) ||
        (in_array($role,['purchase_owner','purchase_channel'],true) &&
            $stage!=='approved_purchase') ||
        !in_array($kind,['send','edit'],true) ||
        preg_match('/^(?:-?[0-9]{1,19}|@[A-Za-z0-9_]{3,64})$/D',$recipient)!==1 ||
        ($kind==='edit'?($messageId===null || $messageId<=0):$messageId!==null) ||
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
    achbotFollowupStoreLocked($db,$payId,$serviceId,$stage,$role,$kind,$recipient,$messageId,
        achbotPaymentEvidencePayFingerprint($pay),$content);
}

/** Existing 020 storage for an actual payment, actual service, or both.
 * The historical pay_fingerprint column carries the source digest for v3/v4. */
function achbotFollowupStoreLocked(mysqli $db,?int $payId,?int $serviceId,string $stage,string $role,
    string $kind,string $recipient,?int $messageId,string $fingerprint,array $content): void {
    $tx=$db->query('SELECT @@in_transaction AS tx');
    if (!$tx || (int)($tx->fetch_assoc()['tx']??0)!==1 ||
        ($payId===null && $serviceId===null) ||
        ($stage==='wallet_credit' && ($payId===null || $serviceId!==null)) ||
        !in_array($kind,['send','edit'],true) ||
        ($kind==='edit'?($messageId===null || $messageId<=0):$messageId!==null) || ($payId!==null && $payId<=0) ||
        ($serviceId!==null && $serviceId<=0) ||
        preg_match('/^(?:-?[0-9]{1,19}|@[A-Za-z0-9_]{3,64})$/D',$recipient)!==1 ||
        !preg_match('/^[a-f0-9]{64}$/D',$fingerprint) ||
        !is_string($content['text']??null) || $content['text']==='' ||
        !in_array($content['mode']??null,['HTML','plain',null],true) ||
        (!is_null($content['keys']??null) && !is_string($content['keys']??null)))
        throw new RuntimeException('followup_source_packet_invalid');
    $key=achbotFollowupIntentKey($stage,$role,$kind,$recipient,$messageId);
    $body=['v'=>$stage==='wallet_credit'?4:($payId===null?3:($serviceId===null?1:2)),'pay_id'=>$payId,'stage'=>$stage,'role'=>$role,
           'kind'=>$kind,'recipient'=>$recipient,'message_id'=>$messageId,
           'pay_fingerprint'=>$fingerprint,'content'=>$content];
    if ($serviceId!==null) $body['service_operation_id']=$serviceId;
    $purpose=$payId===null?achbotFollowupServicePurpose($serviceId,$stage,$key):
        achbotFollowupPurpose($payId,$stage,$key);
    $sealed=achbotPaymentEvidenceProtect($purpose,$body);
    $sha=hash('sha256',$sealed);
    if ($serviceId===null) {
        $q=$db->prepare("INSERT INTO payment_followup_intents
            (pay_row_id,intent_key,stage,kind,role,recipient,message_id,
             pay_fingerprint,sealed_payload,payload_sha256,state)
            VALUES (?,?,?,?,?,?,?,?,?,?,'pending')");
        if (!$q) throw new RuntimeException('followup_insert_prepare');
        $q->bind_param('isssssisss',$payId,$key,$stage,$kind,$role,$recipient,
            $messageId,$fingerprint,$sealed,$sha);
    } else {
        $q=$db->prepare("INSERT INTO payment_followup_intents
            (pay_row_id,intent_key,stage,kind,role,recipient,message_id,
             pay_fingerprint,sealed_payload,payload_sha256,state,service_operation_id)
            VALUES (?,?,?,?,?,?,?,?,?,?,'pending',?)");
        if (!$q) throw new RuntimeException('followup_insert_prepare');
        $q->bind_param('isssssisssi',$payId,$key,$stage,$kind,$role,$recipient,
            $messageId,$fingerprint,$sealed,$sha,$serviceId);
    }
    if (!$q->execute() || $q->affected_rows!==1)
        throw new RuntimeException('followup_insert_unconfirmed');
    $q->close();
}
function achbotFollowupServicePurpose(int $id,string $stage,string $key): string {
    return 'service-followup:v3:'.$id.':'.$stage.':'.$key;
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
    if ($role==='purchase_owner') return $owner>0 && $who===$owner;
    if ($role==='purchase_channel') {
        $channel=(string)($GLOBALS['botState']['rewardChannel']??'');
        return $channel!=='' && $recipient===$channel;
    }
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
/** Shared sealed original packet verification for historical and service sources. */
function achbotFollowupDecodePacket(?array $pay,array $r,?string $sourceFingerprint=null): ?array {
    $serviceId=isset($r['service_operation_id'])?(int)$r['service_operation_id']:null;
    $payId=$pay===null?null:(int)$pay['id'];
    $fingerprint=$sourceFingerprint??($pay===null?null:achbotPaymentEvidencePayFingerprint($pay));
    if (($payId===null?($r['pay_row_id']!==null || $serviceId===null):
            $payId!==(int)$r['pay_row_id']) ||
        !is_string($fingerprint) ||
        !hash_equals((string)$r['pay_fingerprint'],$fingerprint) ||
        !hash_equals((string)$r['payload_sha256'],hash('sha256',(string)$r['sealed_payload'])) ||
        !hash_equals((string)$r['intent_key'],achbotFollowupIntentKey(
            (string)$r['stage'],(string)$r['role'],(string)$r['kind'],
            (string)$r['recipient'],$r['message_id']===null?null:(int)$r['message_id'])))
        return null;
    $purpose=$payId===null?achbotFollowupServicePurpose($serviceId,(string)$r['stage'],(string)$r['intent_key']):
        achbotFollowupPurpose($payId,(string)$r['stage'],(string)$r['intent_key']);
    $body=achbotPaymentEvidenceUnprotect($purpose,(string)$r['sealed_payload']);
    $version=$r['stage']==='wallet_credit'?4:($payId===null?3:($serviceId===null?1:2));
    if (!is_array($body) || ($body['v']??null)!==$version ||
        ($serviceId!==null && ($body['service_operation_id']??null)!==$serviceId)) return null;
    foreach (['pay_id'=>$payId,'stage'=>(string)$r['stage'],
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

function achbotFollowupValidateLocked(mysqli $db,array $pay,array $r): ?array {
    if ($r['stage']==='wallet_credit') {
        require_once __DIR__.'/ReceiptReports.php';
        return achbotReceiptReportValidateLocked($db,$pay,$r);
    }
    if (isset($r['service_operation_id'])) {
        require_once __DIR__.'/ServicePurchaseReferral.php';
        if (!achbotServiceReferralReportValid($db,$pay,$r) ||
            !achbotFollowupRecipientAllowed($db,$pay,$r)) return null;
        return achbotFollowupDecodePacket($pay,$r);
    }
    // The caller holds the original pays row; check the same original
    // attempt before any new customer/admin Telegram dispatch.
    $payId=(int)($pay['id']??0);
    $attempt=null;
    try {
        $q=$db->prepare('SELECT * FROM payment_attempts WHERE pay_row_id=? FOR UPDATE');
        $q->bind_param('i',$payId);$q->execute();
        $attempt=$q->get_result()->fetch_assoc();$q->close();
    } catch (mysqli_sql_exception $error) {
        // A legacy follow-up-only install without migration 003 cannot
        // have an archived payment attempt. Preserve its original delivery.
        // Permission or other schema failures still fail closed.
        if ((int)$error->getCode()!==1146) throw $error;
    }
    if ($attempt && ($attempt['review_archived_at']??null)!==null) return null;
    if ((int)$pay['id']!==(int)$r['pay_row_id'] ||
        !in_array((string)$r['stage'],['approved_purchase','approved_edit','manual_reject','referral_reward'],true) ||
        !in_array((string)$r['kind'],['send','edit'],true) ||
        ($r['stage']==='manual_reject' && $pay['state']!=='declined') ||
        (in_array((string)$r['role'],['purchase_owner','purchase_channel'],true) &&
            ((string)$r['stage']!=='approved_purchase' ||
             (string)$pay['type']!=='BUY_SUB' ||
             (int)($pay['agent_count']??0)<=1)) ||
        (in_array($r['stage'],['approved_purchase','referral_reward'],true) &&
            ($pay['state']!=='approved' || $pay['type']!=='BUY_SUB')) ||
        ($r['stage']==='approved_edit' &&
            ($pay['state']!=='approved' ||
             !((string)$pay['type']==='RENEW_ACCOUNT' ||
               preg_match('/^INCREASE_(?:DAY|VOLUME)_[1-9][0-9]*_[1-9][0-9]*$/D',
                          (string)$pay['type'])===1))) ||
        (int)$pay['approved_by']<=0)
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
    return achbotFollowupDecodePacket($pay,$r);

}
/**
 * One logical message per original intent. Historical v1 rows retain
 * one-shot behavior. New service sources keep explicit attempt evidence;
 * only proven non-application is eligible for automatic redelivery.
 * Per-recipient attempts do not block independent pending recipients.
 */
function achbotFollowupDispatchOne(mysqli $db,int $id,int $actor=0,?string $confirmation=null): string {
    if (!achbotFollowupSchemaReady($db) || $id<=0 || $actor<0 ||
        ($actor===0 && $confirmation!==null)) return 'unavailable';
    // Resolve the immutable original owner before the first SQL claim. The
    // in-transaction read below verifies it again after acquiring the lease.
    try {
        $q=$db->prepare('SELECT * FROM payment_followup_intents WHERE id=?');
        $q->bind_param('i',$id);$q->execute();
        $owner=$q->get_result()->fetch_assoc();$q->close();
        $payId=(int)($owner['pay_row_id']??0);
        $serviceId=isset($owner['service_operation_id'])?(int)$owner['service_operation_id']:null;
        if ($payId<=0 && ($serviceId===null || $serviceId<=0)) return 'unavailable';
        $journaled=achbotFollowupJournaled($owner);
        if ($journaled) {
            require_once __DIR__.'/FollowupAttempts.php';
            achbotPaymentEvidenceRequireAutocommit($db);
        } elseif ($actor>0) return 'unavailable';
    } catch (Throwable $error) {
        error_log('achbot_followup_owner_unavailable id='.$id.' class='.get_class($error));
        return 'unavailable';
    }
    require_once __DIR__.'/GatewayClient.php';
    $serviceLock=null;$lease='unavailable';
    if ($payId>0) {
        $lease=achbotOriginalDispatchLeaseBegin($db,$payId);
        if ($lease!=='owned' && $lease!=='borrowed') return $lease;
        if ($actor>0 && $lease!=='owned') return 'busy';
    } else {
        require_once __DIR__.'/ServiceReports.php';
        require_once __DIR__.'/../Services/Execution.php';
        try {
            $name=achbotServiceExecutionLock($db,$serviceId);
            $held=achbotProviderRow($db,'SELECT GET_LOCK(?,0) AS acquired','s',[$name]);
            if ((int)($held['acquired']??0)!==1) return 'busy';
            $serviceLock=$name;
        } catch (Throwable $error) {
            error_log('achbot_followup_service_lease_unavailable id='.$id.' class='.get_class($error));
            return 'unavailable';
        }
    }
    try {
    $token='';$r=null;$packet=null;$opened=false;
    try {
        if (!$db->begin_transaction()) return 'unavailable';$opened=true;
        $pay=null;$op=null;$quote=null;
        if ($payId>0) {
            // New credit reports must follow the common owner-first order,
            // never lock a receipt and then wait on its financial owner.
            if ($owner['stage']==='wallet_credit') {
                require_once __DIR__.'/PaymentCredit.php';
                $loc=achbotProviderRow($db,'SELECT user_id FROM pays WHERE id=?','i',[$payId]);
                if (!$loc) throw new RuntimeException('followup_missing');
                achbotWalletLockOwnerLocked($db,(int)$loc['user_id']);
            }
            $q=$db->prepare('SELECT * FROM pays WHERE id=? FOR UPDATE');
            $q->bind_param('i',$payId);$q->execute();$pay=$q->get_result()->fetch_assoc();$q->close();
            if (!$pay) throw new RuntimeException('followup_missing');
            if ($owner['stage']==='wallet_credit' && (int)$pay['user_id']!==(int)$loc['user_id'])
                throw new RuntimeException('followup_owner_changed');
        } else {
            $loc=achbotProviderRow($db,'SELECT userid FROM service_operations WHERE id=?','i',[$serviceId]);
            if (!$loc) throw new RuntimeException('followup_service_missing');
            [$op,$quote]=achbotServiceReadLocked($db,(int)$loc['userid'],$serviceId);
        }
        $q=$db->prepare('SELECT * FROM payment_followup_intents WHERE id=? FOR UPDATE');
        $q->bind_param('i',$id);$q->execute();$r=$q->get_result()->fetch_assoc();$q->close();
        if (!$r || $r['stage']!==$owner['stage'] || achbotFollowupJournaled($r)!==$journaled || (int)$r['pay_row_id']!==$payId ||
            (isset($r['service_operation_id'])?(int)$r['service_operation_id']:null)!==$serviceId)
            throw new RuntimeException('followup_owner_changed');
        if ($journaled && $r['state']==='dispatching') {
            $state=achbotFollowupAttemptFinishLocked($db,$id,(string)$r['dispatch_token'],
                ['result'=>'unknown','code'=>null,'retry_after'=>null],true);
            if (!$db->commit()) throw new RuntimeException('followup_abandoned_commit');
            $opened=false;return $state;
        }
        if (($actor===0 && $r['state']!=='pending') ||
            ($actor>0 && !in_array($r['state'],['unknown','rejected'],true))) {
            $state=$actor===0?(string)$r['state']:'stale';
            $db->rollback();$opened=false;return $state;
        }
        $packet=$payId>0?achbotFollowupValidateLocked($db,$pay,$r):
            achbotServiceReportValidateLocked($db,$op,$quote,$r);
        if (!$packet) throw new RuntimeException('followup_identity_invalid');
        if ($journaled) {
            $claim=achbotFollowupAttemptClaimLocked($db,$r,$actor,$confirmation);
            if ($claim['status']!=='claimed') {
                $db->rollback();$opened=false;return $claim['status'];
            }
            $token=$claim['token'];$ok=true;
        } else {
            $token=bin2hex(random_bytes(16));
            $q=$db->prepare("UPDATE payment_followup_intents SET state='dispatching',
                dispatch_token=?,attempt_count=attempt_count+1,dispatched_at=NOW(),
                last_result=NULL WHERE id=? AND state='pending' AND attempt_count=0");
            $q->bind_param('si',$token,$id);
            $ok=$q->execute() && $q->affected_rows===1;$q->close();
        }
        if (!$ok || !$db->commit()) throw new RuntimeException('followup_claim_unknown');
        $opened=false;
    } catch(Throwable $error) {
        if ($opened) try {$db->rollback();}catch(Throwable $ignored){}
        if ($journaled && $error instanceof RuntimeException &&
            $error->getMessage()==='service_delivery_not_ready') return 'not_ready';
        if ($journaled) {
            $reason=$error->getMessage();
            achbotDiagnosticEvent('achbot.delivery.followup_prepare_failed',[
                'followup_id'=>$id,'pay_id'=>$payId,'service_operation_id'=>$serviceId,
                'reason'=>preg_match('/\A(?:followup|service|payment_credit|wallet|provider)_[a-z0-9_]{1,80}\z/D',$reason)
                    ?$reason:'followup_storage_unavailable',
                'class'=>get_class($error)],'warning');
        } else error_log('achbot_followup_prepare_unknown id='.$id.' class='.get_class($error));
        return 'unavailable';
    }
    $status='unknown';$outcome=['result'=>'unknown','code'=>null,'retry_after'=>null];
    $priorContext=$GLOBALS['achbotTelegramDiagnosticContext']??null;
    if ($journaled)
        $GLOBALS['achbotTelegramDiagnosticContext']=['followup_id'=>$id,'pay_id'=>$payId,
            'service_operation_id'=>$serviceId,'delivery_attempt'=>(int)$r['attempt_count']+1,
            'delivery_actor'=>$actor];
    try {
        if ($serviceLock!==null) {
            if (!achbotServiceExecutionOwnsLock($db,$serviceLock))
                throw new RuntimeException('followup_service_ownership_lost');
        } elseif ($journaled) {
            $name=achbotHostedWorkerLockName($db,$payId);
            $lock=achbotProviderRow($db,'SELECT IS_USED_LOCK(?)=CONNECTION_ID() AS owned','s',[$name]);
            if ((int)($lock['owned']??0)!==1)
                throw new RuntimeException('followup_service_ownership_lost');
        }
        $target=preg_match('/^-?[0-9]+$/D',(string)$r['recipient'])===1
            ?(int)$r['recipient']:(string)$r['recipient'];
        if ($r['kind']==='edit') {
            $result=editKeys((string)$packet['text'],(int)$r['message_id'],$target);
        } else {
            $mode=($packet['mode']??null)==='plain'?null:$packet['mode'];
            $result=sendMessage((string)$packet['text'],$packet['keys']??null,$mode,$target);
        }
        if ($journaled)
            $outcome=\AchBot\Telegram\Response\deliveryOutcome($result);
        elseif (is_object($result) && ($result->ok??false)===true)
            $status='acknowledged';
    } catch(Throwable $error) {
        error_log('achbot_followup_telegram_uncertain id='.$id.' class='.get_class($error));
    } finally {
        if ($journaled) {
            if ($priorContext===null) unset($GLOBALS['achbotTelegramDiagnosticContext']);
            else $GLOBALS['achbotTelegramDiagnosticContext']=$priorContext;
        }
    }
    try {
        if ($journaled) {
            if (!$db->begin_transaction()) throw new RuntimeException('followup_finish_begin');
            $name=$serviceLock??achbotHostedWorkerLockName($db,$payId);
            $lock=achbotProviderRow($db,'SELECT IS_USED_LOCK(?)=CONNECTION_ID() AS owned','s',[$name]);
            if ((int)($lock['owned']??0)!==1) throw new RuntimeException('followup_service_ownership_lost');
            $status=achbotFollowupAttemptFinishLocked($db,$id,$token,$outcome);
            if (!$db->commit()) throw new RuntimeException('followup_ack_commit_unknown');
            achbotDiagnosticEvent('achbot.delivery.followup_result',[
                'followup_id'=>$id,'pay_id'=>$payId,'service_operation_id'=>$serviceId,'actor_id'=>$actor,
                'request_attempt'=>(int)$r['attempt_count']+1,'state'=>$status,
                'telegram_error_code'=>$outcome['code'],'retry_after'=>$outcome['retry_after'],
                'result'=>$outcome['result']],$status==='acknowledged'?'info':'warning');
        } else {
            $q=$db->prepare("UPDATE payment_followup_intents SET state=?,
                last_result=?,acknowledged_at=CASE WHEN ?='acknowledged' THEN NOW() ELSE NULL END,
                dispatch_token=NULL WHERE id=? AND dispatch_token=? AND state='dispatching'");
            $q->bind_param('sssis',$status,$status,$status,$id,$token);
            $ok=$q->execute() && $q->affected_rows===1;$q->close();
            if (!$ok) throw new RuntimeException('followup_ack_commit_unknown');
        }
    } catch(Throwable $error) {
        if ($journaled) try {$db->rollback();}catch(Throwable $ignored){}
        error_log('achbot_followup_ack_unknown id='.$id);
        return 'unknown';
    }
    return $status;
    } finally {
        if ($serviceLock!==null) {
            try {$q=achbotProviderStatement($db,'SELECT RELEASE_LOCK(?)','s',[$serviceLock]);$q->close();}
            catch (Throwable $ignored) {}
        } else achbotOriginalDispatchLeaseEnd($db,$payId,$lease);
    }
}
/** All pending rows only, including previous-crash rows. UNKNOWN untouched. */
function achbotFollowupDispatchPending(mysqli $db,int $payId,string $stage): array {
    if (!achbotFollowupSchemaReady($db) || $payId<=0 ||
        !in_array($stage,['approved_purchase','approved_edit','manual_reject','referral_reward','wallet_credit'],true)) return [];
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
    string $hash): void {
    if (!achbotFollowupSchemaReady($db) || $owner<=0 ||
        !function_exists('achbotManualFinalReceiptKeyboard'))
        throw new RuntimeException('manual_purchase_followup_preflight_missing');
    $payId=(int)$pay['id'];$buyer=(int)$pay['user_id'];
    $actor=(int)$pay['approved_by'];
    if ($payId<=0 || $buyer<=0 || $actor<=0 || $count<=0 ||
        !is_string(\achbotMessage('legacy_messages.sent_config_to_user')??null))
        throw new RuntimeException('manual_purchase_followup_identity_invalid');
    $bulkProof=null;
    if ($count>1) {
        require_once __DIR__.'/../Purchase/BulkReport.php';
        $bulkProof=achbotBulkRecordedAccounts($db,$count,$buyer,$payId);
        if (!$bulkProof['complete'])
            throw new RuntimeException('manual_bulk_report_original_effect_unverified');
    }
    $actualSubject=$count>1?achbotMessage('bulk_reporting.bulk_subject'):$remark;
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
        [$buyer,$actualSubject,$volume,$days,$price,$hash],
        (string)\achbotMessage('legacy_messages.sent_config_to_user'));
    if ($bulkProof!==null) $notice=achbotBulkReportAppend($notice,$bulkProof);
    achbotFollowupInsertLocked($db,$pay,'approved_purchase','operator','send',
        (string)$actor,null,
        ['text'=>$notice,'keys'=>getMainKeys(),'mode'=>null]);
    if ($bulkProof!==null) {
        // A one-shot report for the original Owner and the configured
        // channel, even when a delegated administrator approved the receipt.
        if ($owner!==$actor)
            achbotFollowupInsertLocked($db,$pay,'approved_purchase',
                'purchase_owner','send',(string)$owner,null,
                ['text'=>$notice,'keys'=>null,'mode'=>null]);
        $channel=(string)($GLOBALS['botState']['rewardChannel']??'');
        if ($channel!=='')
            achbotFollowupInsertLocked($db,$pay,'approved_purchase',
                'purchase_channel','send',$channel,null,
                ['text'=>$notice,'keys'=>null,'mode'=>null]);
    }
    $q=$db->prepare('SELECT name,username,refered_by FROM users WHERE userid=? LIMIT 2');
    $q->bind_param('i',$buyer);$q->execute();
    $buyers=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
    if (count($buyers)!==1) throw new RuntimeException('manual_purchase_followup_buyer_invalid');
    $user=$buyers[0];
    if ((int)$user['refered_by']>0 && $actor!==$owner) {
        if (!is_string(\achbotMessage('legacy_messages.invite_buy_new_account')??null))
            throw new RuntimeException('manual_purchase_invitation_template_missing');
        $actions=json_encode(['inline_keyboard'=>[[
            ['text'=>\achbotMessage('manual_payment.manual_approval.manual_standard_report_delivery.button.e401f3d5a2'),
             'callback_data'=>'wizwizch']
        ]]],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $text=str_replace(
            ['USER-ID','USERNAME','NAME','PRICE','REMARK','HASHID'],
            [$buyer,(string)$user['username'],(string)$user['name'],
             $price,$remark,$hash],
            (string)\achbotMessage('legacy_messages.invite_buy_new_account'));
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

/** Only new service/credit-sourced reports; historical pending claims retain their
 * own disposition until cutover rehearsal. Reuse the existing 020 sender. */
function achbotFollowupMaintainOriginalPending(mysqli $db,int $limit,float $budgetSeconds): array {
    if ($limit<=0 || !is_finite($budgetSeconds) || $budgetSeconds<=0)
        throw new InvalidArgumentException('followup_original_budget_invalid');
    require_once __DIR__.'/../Services/Ownership.php';
    // The existing services Cron also runs before the Wallet-first cutover.
    // With no 026 schema there cannot be any new original-source work.
    if (!achbotServiceOperationsPresent($db)) return [];
    $deadline=hrtime(true)/1000000000+$budgetSeconds;
    $s=achbotProviderStatement($db,"SELECT f.id FROM payment_followup_intents f
        WHERE (f.service_operation_id IS NOT NULL OR f.stage='wallet_credit')
          AND f.state IN ('pending','dispatching')
          AND ((f.next_attempt_at IS NULL AND f.updated_at<=DATE_SUB(NOW(),INTERVAL 2 MINUTE))
               OR f.next_attempt_at<=NOW(6))
          AND (f.state='dispatching' OR f.stage<>'service_delivered' OR NOT EXISTS (
              SELECT 1 FROM payment_delivery_intents d WHERE d.service_operation_id=f.service_operation_id
              AND (d.state<>'acknowledged' OR d.part_count IS NULL)))
        ORDER BY COALESCE(f.next_attempt_at,f.updated_at),f.id LIMIT ?",'i',[$limit]);
    $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();$out=[];
    foreach ($rows as $r) {
        if (hrtime(true)/1000000000>=$deadline) break;
        $id=(int)$r['id'];
        $state=achbotFollowupDispatchOne($db,$id);
        $out[$id]=$state;
        if (!in_array($state,['unavailable','busy','not_ready'],true)) continue;
        try {
            // Defer an unavailable already-due retry behind healthy peers;
            // do not shorten or rewrite a newly committed Telegram wait.
            $s=achbotProviderStatement($db,"UPDATE payment_followup_intents SET updated_at=NOW(),
                next_attempt_at=CASE WHEN next_attempt_at<=NOW(6)
                    THEN DATE_ADD(NOW(6),INTERVAL 2 MINUTE) ELSE next_attempt_at END
                WHERE id=? AND state IN ('pending','dispatching')",'i',[$id]);$s->close();
        } catch (Throwable $e) {
            error_log('achbot_followup_maintenance_deferred id='.$id.' class='.get_class($e));
        }
    }
    return $out;
}

/** Stage a general report to the existing authorized owner/staff audience.
 * A caller explicitly supplies the existing channel policy (empty = none). */
function achbotFollowupStageAdminReportLocked(mysqli $db,?int $payId,?int $serviceId,
    string $stage,string $fingerprint,int $owner,string $channel,array $content): void {
    if ($owner<=0) throw new RuntimeException('followup_report_owner_missing');
    $complete=false;$staff=achbotAdminRecipientCandidates($db,$owner,$complete);
    if (!$complete) throw new RuntimeException('followup_report_staff_unavailable');
    $targets=[(string)$owner=>true];
    foreach ($staff as $uid) {
        $complete=true;
        if (achbotAdminNotificationAllowed($db,$uid,$owner,'general',$complete))
            $targets[(string)(int)$uid]=true;
        if (!$complete) throw new RuntimeException('followup_report_permission_unavailable');
    }
    foreach ($targets as $recipient=>$_)
        achbotFollowupStoreLocked($db,$payId,$serviceId,$stage,'service_admin','send',
            (string)$recipient,null,$fingerprint,$content);
    if ($channel!=='' && !isset($targets[$channel]))
        achbotFollowupStoreLocked($db,$payId,$serviceId,$stage,'service_channel','send',
            $channel,null,$fingerprint,$content);
}
function achbotFollowupAdminReportAllowed(mysqli $db,array $r): bool {
    $owner=(int)($GLOBALS['admin']??0);$recipient=(string)$r['recipient'];
    if ($r['kind']!=='send' || $r['message_id']!==null) return false;
    if ($r['role']==='service_admin')
        return $owner>0 && achbotAdminNotificationAllowed($db,(int)$recipient,$owner,'general');
    if ($r['role']==='service_channel') {
        $channel=(string)($GLOBALS['botState']['rewardChannel']??'');
        return $channel!=='' && $recipient===$channel;
    }
    return false;
}
