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
        $tables=[
            'payment_followup_intents'=>[
                'columns'=>'id,pay_row_id,service_operation_id,wallet_entry_id,intent_key,stage,kind,role,recipient,'.
                    'message_id,pay_fingerprint,sealed_payload,payload_sha256,state,attempt_count,dispatch_token,'.
                    'dispatched_at,acknowledged_at,last_result,created_at,updated_at,next_attempt_at',
                'unique'=>['uq_followup_pay_key'=>['pay_row_id','intent_key'],
                    'uq_followup_service_key'=>['service_operation_id','intent_key'],
                    'uq_followup_wallet_key'=>['wallet_entry_id','intent_key']]],
            'payment_followup_attempts'=>[
                'columns'=>'id,intent_id,attempt_no,dispatch_token,actor_userid,claim_state,result,'.
                    'telegram_error_code,retry_after_seconds,started_at,finished_at',
                'unique'=>['uq_followup_attempt_no'=>['intent_id','attempt_no'],
                    'uq_followup_attempt_token'=>['dispatch_token']]]
        ];
        $q=$db->query("SHOW TABLE STATUS WHERE Name IN ('payment_followup_intents','payment_followup_attempts')");
        if (!$q) return false;
        $engines=[];
        while ($r=$q->fetch_assoc()) $engines[$r['Name']]=strtoupper((string)$r['Engine']);
        $q->free();
        foreach ($tables as $table=>$contract) {
            if (($engines[$table]??'')!=='INNODB') return false;
            $q=$db->query('SELECT '.$contract['columns'].' FROM '.$table.' LIMIT 0');
            if (!$q) return false;$q->free();
            $q=$db->query('SHOW INDEX FROM '.$table);
            if (!$q) return false;$indexes=[];
            while ($r=$q->fetch_assoc()) {
                $key=(string)$r['Key_name'];
                if (!isset($contract['unique'][$key])) continue;
                if ((int)$r['Non_unique']!==0 || $r['Sub_part']!==null) return false;
                $indexes[$key][(int)$r['Seq_in_index']]=(string)$r['Column_name'];
            }
            $q->free();
            foreach ($contract['unique'] as $key=>$columns) {
                if (!isset($indexes[$key])) return false;
                ksort($indexes[$key]);
                if (array_values($indexes[$key])!==$columns) return false;
            }
        }
        return true;
    } catch(Throwable $error) {return false;}
}
function achbotFollowupJournaled(array $r): bool {
    return (int)($r['wallet_entry_id']??0)>0 || (int)($r['service_operation_id']??0)>0 ||
        (in_array($r['stage']??null,['wallet_credit','manual_reject'],true) && (int)($r['pay_row_id']??0)>0);
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
    if ($serviceId!==null) {
        if ($serviceId<=0 || $stage!=='referral_reward' || $role!=='referral_inviter' ||
            $kind!=='send' || ($pay['state']??null)!=='paid')
            throw new RuntimeException('followup_service_source_invalid');
    } elseif ($stage!=='manual_reject' ||
        !in_array($role,['customer','receipt_primary','receipt_mirror'],true))
        throw new RuntimeException('followup_pay_source_invalid');
    if (!in_array($kind,['send','edit'],true) ||
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
        ($role==='customer' && $recipient!==(string)(int)$pay['user_id']) ||
        ($role==='receipt_primary' &&
            ($recipient!==(string)(int)$pay['chat_id'] ||
             $messageId!==(int)$pay['message_id'])))
        throw new RuntimeException('followup_pay_target_invalid');
    achbotFollowupStoreLocked($db,$payId,$serviceId,$stage,$role,$kind,$recipient,$messageId,
        achbotPaymentEvidencePayFingerprint($pay),$content);
}

/** Existing 020 storage for an actual payment, actual service, or both.
 * The historical pay_fingerprint column carries the source digest for v3/v4. */
function achbotFollowupStoreLocked(mysqli $db,?int $payId,?int $serviceId,string $stage,string $role,
    string $kind,string $recipient,?int $messageId,string $fingerprint,array $content,?int $walletId=null): void {
    $tx=$db->query('SELECT @@in_transaction AS tx');
    if (!$tx || (int)($tx->fetch_assoc()['tx']??0)!==1 ||
        ($walletId===null && $payId===null && $serviceId===null) ||
        ($walletId!==null && ($walletId<=0 || $payId!==null || $serviceId!==null || $stage!=='wallet_movement')) ||
        ($stage==='wallet_movement' && $walletId===null) ||
        ($stage==='wallet_credit' && ($payId===null || $serviceId!==null || $walletId!==null)) ||
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
    $body=['v'=>$walletId!==null?5:($stage==='wallet_credit'?4:($payId===null?3:($serviceId===null?1:2))),'pay_id'=>$payId,'stage'=>$stage,'role'=>$role,
           'kind'=>$kind,'recipient'=>$recipient,'message_id'=>$messageId,
           'pay_fingerprint'=>$fingerprint,'content'=>$content];
    if ($serviceId!==null) $body['service_operation_id']=$serviceId;
    if ($walletId!==null) $body['wallet_entry_id']=$walletId;
    $purpose=$walletId!==null?achbotFollowupWalletPurpose($walletId,$stage,$key):
        ($payId===null?achbotFollowupServicePurpose($serviceId,$stage,$key):
        achbotFollowupPurpose($payId,$stage,$key));
    $sealed=achbotPaymentEvidenceProtect($purpose,$body);
    $sha=hash('sha256',$sealed);
    if ($walletId!==null) {
        $q=$db->prepare("INSERT INTO payment_followup_intents
            (intent_key,stage,kind,role,recipient,message_id,pay_fingerprint,sealed_payload,payload_sha256,state,wallet_entry_id,next_attempt_at)
            VALUES (?,?,?,?,?,?,?,?,?,'pending',?,NOW(6))");
        if (!$q) throw new RuntimeException('followup_insert_prepare');
        $q->bind_param('sssssisssi',$key,$stage,$kind,$role,$recipient,$messageId,$fingerprint,$sealed,$sha,$walletId);
    } elseif ($serviceId===null) {
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
function achbotFollowupWalletPurpose(int $id,string $stage,string $key): string {
    return 'wallet-followup:v5:'.$id.':'.$stage.':'.$key;
}
/** Who is STILL eligible to receive a previously intended report? */
function achbotFollowupRecipientAllowed(mysqli $db,array $pay,array $r): bool {
    $owner=(int)($GLOBALS['admin']??0);
    $recipient=(string)$r['recipient'];$who=(int)$recipient;
    $role=(string)$r['role'];
    if ($r['stage']==='manual_reject' && in_array($role,['service_admin','service_channel','report_actor'],true))
        return achbotFollowupAdminReportAllowed($db,$r,(int)$pay['approved_by']);
    if ($role==='customer') return $recipient===(string)(int)$pay['user_id'];
    if ($role==='receipt_primary')
        return achbotAdminStoredRecipientAllowed($db,$who,$owner) && $who===(int)$pay['chat_id'] && (int)$r['message_id']===(int)$pay['message_id'];
    if ($role==='receipt_mirror') {
        $copies=json_decode((string)($pay['admins_messages']??''),true);
        if (!is_array($copies) || !achbotAdminStoredRecipientAllowed($db,$who,$owner))
            return false;
        foreach ($copies as $copy)
            if ((int)($copy['chat_id']??0)===$who &&
                (int)($copy['message_id']??0)===(int)$r['message_id']) return true;
        return false;
    }
    return false;
}
/** Shared sealed original packet verification for historical and service sources. */
function achbotFollowupDecodePacket(?array $pay,array $r,?string $sourceFingerprint=null): ?array {
    $serviceId=isset($r['service_operation_id'])?(int)$r['service_operation_id']:null;
    $walletId=isset($r['wallet_entry_id'])?(int)$r['wallet_entry_id']:null;
    $payId=$pay===null?null:(int)$pay['id'];
    $fingerprint=$sourceFingerprint??($pay===null?null:achbotPaymentEvidencePayFingerprint($pay));
    if (($payId===null?($r['pay_row_id']!==null || ($serviceId===null && $walletId===null)):
            $payId!==(int)$r['pay_row_id']) ||
        ($walletId!==null && ($payId!==null || $serviceId!==null || $r['stage']!=='wallet_movement')) ||
        !is_string($fingerprint) ||
        !hash_equals((string)$r['pay_fingerprint'],$fingerprint) ||
        !hash_equals((string)$r['payload_sha256'],hash('sha256',(string)$r['sealed_payload'])) ||
        !hash_equals((string)$r['intent_key'],achbotFollowupIntentKey(
            (string)$r['stage'],(string)$r['role'],(string)$r['kind'],
            (string)$r['recipient'],$r['message_id']===null?null:(int)$r['message_id'])))
        return null;
    $purpose=$walletId!==null?achbotFollowupWalletPurpose($walletId,(string)$r['stage'],(string)$r['intent_key']):
        ($payId===null?achbotFollowupServicePurpose($serviceId,(string)$r['stage'],(string)$r['intent_key']):
        achbotFollowupPurpose($payId,(string)$r['stage'],(string)$r['intent_key']));
    $body=achbotPaymentEvidenceUnprotect($purpose,(string)$r['sealed_payload']);
    $version=$walletId!==null?5:($r['stage']==='wallet_credit'?4:($payId===null?3:($serviceId===null?1:2)));
    if (!is_array($body) || ($body['v']??null)!==$version ||
        ($walletId!==null && ($body['wallet_entry_id']??null)!==$walletId) ||
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
        if (!achbotServiceReferralReportValid($db,$pay,$r)) return null;
        return achbotFollowupDecodePacket($pay,$r);
    }
    // Historical approved-purchase/edit and referral notices are evidence
    // only. Current service and receipt reports have their own validators.
    if ($r['stage']!=='manual_reject') return null;
    $payId=(int)($pay['id']??0);
    $attempt=achbotProviderRow($db,'SELECT * FROM payment_attempts WHERE pay_row_id=? FOR UPDATE','i',[$payId]);
    require_once __DIR__.'/PaymentCredit.php';
    if (!$attempt ||
        $attempt['wallet_credit_entry_id']!==null || $attempt['stage']!=='done' ||
        $attempt['provider_status']!=='operator_rejected' ||
        !is_string($attempt['wallet_invoice_sha256']) ||
        !hash_equals($attempt['wallet_invoice_sha256'],achbotCreditInvoiceFingerprint($pay,$attempt)) ||
        $payId!==(int)$r['pay_row_id'] || $pay['state']!=='declined' ||
        !in_array((string)$r['kind'],['send','edit'],true) ||
        (int)$pay['approved_by']<=0) return null;
    if (!achbotFollowupRecipientAllowed($db,$pay,$r)) return null;
    return achbotFollowupDecodePacket($pay,$r);

}
/**
 * One logical message per current intent, with explicit attempt evidence;
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
        $walletId=isset($owner['wallet_entry_id'])?(int)$owner['wallet_entry_id']:null;
        if ($payId<=0 && ($serviceId===null || $serviceId<=0) && ($walletId===null || $walletId<=0)) return 'unavailable';
        if (!achbotFollowupJournaled($owner)) return 'unavailable';
        require_once __DIR__.'/FollowupAttempts.php';
        achbotPaymentEvidenceRequireAutocommit($db);
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
            if ($walletId!==null) {
                $dbName=achbotProviderRow($db,'SELECT DATABASE() AS db')['db'];
                $name='achbot-wallet-report-'.substr(hash('sha256',$dbName.':'.$walletId),0,40);
            } else $name=achbotServiceExecutionLock($db,$serviceId);
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
            if (in_array($owner['stage'],['wallet_credit','manual_reject'],true)) {
                require_once __DIR__.'/PaymentCredit.php';
                $loc=achbotProviderRow($db,'SELECT user_id FROM pays WHERE id=?','i',[$payId]);
                if (!$loc) throw new RuntimeException('followup_missing');
                achbotWalletLockOwnerLocked($db,(int)$loc['user_id']);
            }
            $q=$db->prepare('SELECT * FROM pays WHERE id=? FOR UPDATE');
            $q->bind_param('i',$payId);$q->execute();$pay=$q->get_result()->fetch_assoc();$q->close();
            if (!$pay) throw new RuntimeException('followup_missing');
            if (in_array($owner['stage'],['wallet_credit','manual_reject'],true) && (int)$pay['user_id']!==(int)$loc['user_id'])
                throw new RuntimeException('followup_owner_changed');
        } elseif ($walletId!==null) {
            require_once __DIR__.'/WalletReports.php';
            $wallet=achbotWalletReportEntryLocked($db,$walletId);
        } else {
            $loc=achbotProviderRow($db,'SELECT userid FROM service_operations WHERE id=?','i',[$serviceId]);
            if (!$loc) throw new RuntimeException('followup_service_missing');
            [$op,$quote]=achbotServiceReadLocked($db,(int)$loc['userid'],$serviceId);
        }
        $q=$db->prepare('SELECT * FROM payment_followup_intents WHERE id=? FOR UPDATE');
        $q->bind_param('i',$id);$q->execute();$r=$q->get_result()->fetch_assoc();$q->close();
        if (!$r || $r['stage']!==$owner['stage'] || !achbotFollowupJournaled($r) || (int)$r['pay_row_id']!==$payId ||
            (isset($r['service_operation_id'])?(int)$r['service_operation_id']:null)!==$serviceId ||
            (isset($r['wallet_entry_id'])?(int)$r['wallet_entry_id']:null)!==$walletId)
            throw new RuntimeException('followup_owner_changed');
        if ($r['state']==='dispatching') {
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
        $packet=$walletId!==null?achbotWalletReportValidateLocked($db,$wallet,$r):
            ($payId>0?achbotFollowupValidateLocked($db,$pay,$r):
            achbotServiceReportValidateLocked($db,$op,$quote,$r));
        if (!$packet) throw new RuntimeException('followup_identity_invalid');
        $claim=achbotFollowupAttemptClaimLocked($db,$r,$actor,$confirmation);
        if ($claim['status']!=='claimed') {
            $db->rollback();$opened=false;return $claim['status'];
        }
        $token=$claim['token'];
        if (!$db->commit()) throw new RuntimeException('followup_claim_unknown');
        $opened=false;
    } catch(Throwable $error) {
        if ($opened) try {$db->rollback();}catch(Throwable $ignored){}
        if ($error instanceof RuntimeException &&
            $error->getMessage()==='service_delivery_not_ready') return 'not_ready';
        {
            $reason=$error->getMessage();
            achbotDiagnosticEvent('achbot.delivery.followup_prepare_failed',[
                'followup_id'=>$id,'pay_id'=>$payId,'service_operation_id'=>$serviceId,'wallet_entry_id'=>$walletId,
                'reason'=>preg_match('/\A(?:followup|service|payment_credit|wallet|provider)_[a-z0-9_]{1,80}\z/D',$reason)
                    ?$reason:'followup_storage_unavailable',
                'class'=>get_class($error)],'warning');
        }
        return 'unavailable';
    }
    $status='unknown';$outcome=['result'=>'unknown','code'=>null,'retry_after'=>null];
    $priorContext=$GLOBALS['achbotTelegramDiagnosticContext']??null;
    $GLOBALS['achbotTelegramDiagnosticContext']=['followup_id'=>$id,'pay_id'=>$payId,
            'service_operation_id'=>$serviceId,'wallet_entry_id'=>$walletId,'delivery_attempt'=>(int)$r['attempt_count']+1,
            'delivery_actor'=>$actor];
    try {
        if ($serviceLock!==null) {
            if (!achbotServiceExecutionOwnsLock($db,$serviceLock))
                throw new RuntimeException('followup_service_ownership_lost');
        } else {
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
        $outcome=\AchBot\Telegram\Response\deliveryOutcome($result);
    } catch(Throwable $error) {
        error_log('achbot_followup_telegram_uncertain id='.$id.' class='.get_class($error));
    } finally {
        if ($priorContext===null) unset($GLOBALS['achbotTelegramDiagnosticContext']);
        else $GLOBALS['achbotTelegramDiagnosticContext']=$priorContext;
    }
    try {
        if (!$db->begin_transaction()) throw new RuntimeException('followup_finish_begin');
        $name=$serviceLock??achbotHostedWorkerLockName($db,$payId);
        $lock=achbotProviderRow($db,'SELECT IS_USED_LOCK(?)=CONNECTION_ID() AS owned','s',[$name]);
        if ((int)($lock['owned']??0)!==1) throw new RuntimeException('followup_service_ownership_lost');
        $status=achbotFollowupAttemptFinishLocked($db,$id,$token,$outcome);
        if (!$db->commit()) throw new RuntimeException('followup_ack_commit_unknown');
        achbotDiagnosticEvent('achbot.delivery.followup_result',[
            'followup_id'=>$id,'pay_id'=>$payId,'service_operation_id'=>$serviceId,'wallet_entry_id'=>$walletId,'actor_id'=>$actor,
            'request_attempt'=>(int)$r['attempt_count']+1,'state'=>$status,
            'telegram_error_code'=>$outcome['code'],'retry_after'=>$outcome['retry_after'],
            'result'=>$outcome['result']],$status==='acknowledged'?'info':'warning');
    } catch(Throwable $error) {
        try {$db->rollback();}catch(Throwable $ignored){}
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
        !in_array($stage,['manual_reject','referral_reward','wallet_credit'],true)) return [];
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
function achbotFollowupMaintainOriginalPending(mysqli $db,int $limit,float $budgetSeconds): array {
    if ($limit<=0 || !is_finite($budgetSeconds) || $budgetSeconds<=0)
        throw new InvalidArgumentException('followup_original_budget_invalid');
    require_once __DIR__.'/../Services/Ownership.php';
    // The existing services Cron also runs before the Wallet-first cutover.
    // With no 026 schema there cannot be any new original-source work.
    if (!achbotServiceOperationsPresent($db)) return [];
    $deadline=hrtime(true)/1000000000+$budgetSeconds;
    $s=achbotProviderStatement($db,"SELECT f.id FROM payment_followup_intents f
        WHERE (f.service_operation_id IS NOT NULL OR f.wallet_entry_id IS NOT NULL OR f.stage IN ('wallet_credit','manual_reject'))
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

/** Normalize only an actual configured Telegram destination. Bad configuration
 * must not veto a verified credit, refund, or completed Native operation.
 * The mandatory owner still gets the report; do not guess a destination.
 * When staging, record the exact source for operational diagnostics. */
function achbotFollowupReportChannel(string $raw,string $stage='',?int $payId=null,
    ?int $serviceId=null,?int $walletId=null): string {
    $channel=trim($raw);
    if ($channel==='') return '';
    if (preg_match('/^@[A-Za-z0-9_]{3,64}$/D',$channel)===1) return strtolower($channel);
    if (preg_match('/^-?[0-9]{1,19}$/D',$channel)===1) {
        $id=filter_var($channel,FILTER_VALIDATE_INT);
        if ($id!==false && $id!==0) return (string)$id;
    }
    if ($stage!=='') achbotDiagnosticEvent('achbot.finance.report_channel_invalid',[
        'stage'=>$stage,'pay_id'=>$payId,'service_operation_id'=>$serviceId,
        'wallet_entry_id'=>$walletId,'reason'=>'followup_channel_invalid'],'warning');
    return '';
}

/** Stage a general report to the existing authorized owner/staff audience.
 * A caller explicitly supplies the existing channel policy (empty = none). */
function achbotFollowupStageAdminReportLocked(mysqli $db,?int $payId,?int $serviceId,
    string $stage,string $fingerprint,int $owner,string $channel,array $content,int $actor=0,?int $walletId=null,
    ?string $actorKeys=null,array $pages=[]): void {
    if ($owner<=0) throw new RuntimeException('followup_report_owner_missing');
    $complete=false;$staff=achbotAdminRecipientCandidates($db,$owner,$complete);
    if (!$complete) error_log('achbot_report_optional_staff_deferred stage='.$stage);
    $targets=[(string)$owner=>true];
    foreach ($staff as $uid) {
        $complete=true;
        if (achbotAdminNotificationAllowed($db,$uid,$owner,'general',$complete))
            $targets[(string)(int)$uid]=true;
        if (!$complete) error_log('achbot_report_optional_permission_deferred stage='.$stage);
    }
    // The authenticated business actor receives their own result even if
    // their unsolicited general-report preference is off. Do not duplicate
    // a manager or the primary owner who is also the original actor.
    if ($actor>0 && $actor!==$owner) $targets[(string)$actor]='report_actor';
    $channel=achbotFollowupReportChannel($channel,$stage,$payId,$serviceId,$walletId);
    if ($channel!=='' && !isset($targets[$channel])) $targets[$channel]='service_channel';
    if ($pages!==[] && ($serviceId===null || $payId!==null || $walletId!==null ||
        !in_array($stage,['service_completed','service_resolution'],true)))
        throw new RuntimeException('followup_report_pages_source_invalid');
    foreach ($targets as $recipient=>$role) {
        $role=$role===true?'service_admin':$role;
        $packet=$content;
        if ($actorKeys!==null && (string)$recipient===(string)$actor) $packet['keys']=$actorKeys;
        achbotFollowupStoreLocked($db,$payId,$serviceId,$stage,$role,'send',
            (string)$recipient,null,$fingerprint,$packet,$walletId);
        foreach (array_values($pages) as $i=>$page) {
            achbotFollowupStoreLocked($db,null,$serviceId,$stage.'_p'.($i+1),$role,'send',
                (string)$recipient,null,$fingerprint,['text'=>$page,'keys'=>null,'mode'=>$content['mode']]);
        }
    }
}
function achbotFollowupAdminReportAllowed(mysqli $db,array $r,int $actor=0,string $capability='payments'): bool {
    $owner=(int)($GLOBALS['admin']??0);$recipient=(string)$r['recipient'];
    if ($r['kind']!=='send' || $r['message_id']!==null) return false;
    if ($r['role']==='report_actor')
        return $actor>0 && $owner>0 && $recipient===(string)$actor &&
            achbotAdminCanLocked($db,$actor,$owner,$capability);
    if ($r['role']==='service_admin')
        return $owner>0 && achbotAdminNotificationAllowed($db,(int)$recipient,$owner,'general');
    if ($r['role']==='service_channel') {
        $channel=achbotFollowupReportChannel((string)($GLOBALS['botState']['rewardChannel']??''));
        return $channel!=='' && $recipient===$channel;
    }
    return false;
}
