<?php
/**
 * Durable administrative/customer reports committed with the business decision.
 * Known nonapplication and exact edits can retry; unknown sends are retained.
 * No merchant, wallet or native-provider effect belongs to this module.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/PaymentEvidence.php';
require_once __DIR__.'/../Panel/ProviderStorage.php';
require_once __DIR__.'/../Admin/Access.php';

function achbotFollowupSchemaReady(mysqli $db): bool {
    try {
        if (!function_exists('openssl_encrypt') ||
            strlen(achbotPaymentEvidenceKey())!==32) return false;
        return achbotFollowupStorageReady($db);
    } catch (Throwable $e) {return false;}
}

/** Read-only storage contract, independent of the private encryption key. */
function achbotFollowupStorageReady(mysqli $db): bool {
    try {
        $tables=[
            'payment_followup_intents'=>[
                'columns'=>'id,pay_row_id,service_operation_id,wallet_entry_id,operational_ref,intent_key,stage,kind,role,recipient,'.
                    'message_id,pay_fingerprint,sealed_payload,payload_sha256,state,attempt_count,dispatch_token,'.
                    'dispatched_at,acknowledged_at,last_result,created_at,updated_at,next_attempt_at',
                'unique'=>['uq_followup_pay_key'=>['pay_row_id','intent_key'],
                    'uq_followup_service_key'=>['service_operation_id','intent_key'],
                    'uq_followup_wallet_key'=>['wallet_entry_id','intent_key'],
                    'uq_followup_operational_key'=>['operational_ref','intent_key']]],
            'payment_followup_attempts'=>[
                'columns'=>'id,intent_id,attempt_no,dispatch_token,actor_userid,claim_state,wire_action,wire_recipient,wire_message_id,result,'.
                    'telegram_error_code,retry_after_seconds,failure_reason,ack_chat_id,ack_message_id,started_at,finished_at',
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
        // The same read-only schema proof as the delivery journal: a
        // SELECT-only preflight can inspect SHOW CREATE TABLE.
        $ddl=achbotProviderRow($db,'SHOW CREATE TABLE payment_followup_attempts');
        foreach (['ck_followup_attempt_ack_identity','ck_followup_attempt_wire'] as $constraint)
            if (preg_match('/\bCONSTRAINT `'.preg_quote($constraint,'/').'` CHECK\s*\(/i',
                (string)($ddl['Create Table']??''))!==1) return false;
        return true;
    } catch(Throwable $error) {return false;}
}
function achbotFollowupReceiptStaffRole(string $role): bool {
    return $role==='receipt_notice' || preg_match('/\Aview_[1-9][0-9]{0,18}\z/D',$role)===1;
}
function achbotFollowupPhotoAllowed(string $stage,string $role,string $kind): bool {
    return $kind==='send' && (($stage==='receipt_admitted' && achbotFollowupReceiptStaffRole($role)) ||
        ($stage==='ticket_notice' && in_array($role,['service_admin','customer'],true)));
}
function achbotFollowupJournaled(array $r): bool {
    return (is_string($r['operational_ref']??null) &&
        achbotFollowupOperationalStage((string)($r['stage']??''))) || (int)($r['wallet_entry_id']??0)>0 || (int)($r['service_operation_id']??0)>0 ||
        (in_array($r['stage']??null,['wallet_credit','manual_reject','receipt_admitted'],true) && (int)($r['pay_row_id']??0)>0);
}
function achbotFollowupPurpose(int $payId,string $stage,string $key): string {
    return 'payment-followup:v1:'.$payId.':'.$stage.':'.$key;
}
function achbotFollowupIntentKey(string $stage,string $role,string $kind,
                                string $recipient,?int $messageId): string {
    return hash('sha256',$stage.'|'.$role.'|'.$kind.'|'.$recipient.'|'.($messageId??0));
}
function achbotFollowupReceiptTargetValid(mysqli $db,array $pay,string $role,string $recipient,?int $messageId): bool {
    require_once __DIR__.'/ReceiptNotices.php';
    return achbotReceiptTargetAllowed($db,$pay,['role'=>$role,'recipient'=>$recipient,'message_id'=>$messageId]);
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
        (in_array($role,['receipt_primary','receipt_mirror'],true) &&
            !achbotFollowupReceiptTargetValid($db,$pay,$role,$recipient,$messageId)))
        throw new RuntimeException('followup_pay_target_invalid');
    achbotFollowupStoreLocked($db,$payId,$serviceId,$stage,$role,$kind,$recipient,$messageId,
        achbotPaymentEvidencePayFingerprint($pay),$content);
}

/** Existing 020 storage for an actual payment, actual service, or both.
 * The historical pay_fingerprint column carries the source digest for v3/v4. */
function achbotFollowupStoreLocked(mysqli $db,?int $payId,?int $serviceId,string $stage,string $role,
    string $kind,string $recipient,?int $messageId,string $fingerprint,array $content,?int $walletId=null,?array $event=null): void {
    $ref=$event['reference']??null;
    $tx=$db->query('SELECT @@in_transaction AS tx');
    if (!$tx || (int)($tx->fetch_assoc()['tx']??0)!==1 ||
        ($ref===null && $walletId===null && $payId===null && $serviceId===null) ||
        ($event!==null && (!achbotFollowupOperationalEventValid($event) ||
            $walletId!==null || $payId!==null || $serviceId!==null ||
            !achbotFollowupOperationalStage($stage,$event) ||
            $kind!=='send' || !hash_equals(achbotFollowupOperationalFingerprint($event),$fingerprint))) ||
        ($event===null && achbotFollowupOperationalStage($stage)) ||
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
        (!is_null($content['keys']??null) && !is_string($content['keys']??null)) ||
        (array_key_exists('photo',$content) && (!achbotFollowupPhotoAllowed($stage,$role,$kind) ||
            !is_string($content['photo']) || $content['photo']==='')))
        throw new RuntimeException('followup_source_packet_invalid');
    $key=achbotFollowupIntentKey($stage,$role,$kind,$recipient,$messageId);
    $body=['v'=>$event!==null?6:($walletId!==null?5:($stage==='wallet_credit'?4:($payId===null?3:($serviceId===null?1:2)))),'pay_id'=>$payId,'stage'=>$stage,'role'=>$role,
           'kind'=>$kind,'recipient'=>$recipient,'message_id'=>$messageId,
           'pay_fingerprint'=>$fingerprint,'content'=>$content];
    if ($serviceId!==null) $body['service_operation_id']=$serviceId;
    if ($walletId!==null) $body['wallet_entry_id']=$walletId;
    if ($event!==null) $body['operational_event']=$event;
    $purpose=$event!==null?achbotFollowupOperationalPurpose($ref,$stage,$key):
        ($walletId!==null?achbotFollowupWalletPurpose($walletId,$stage,$key):
        ($payId===null?achbotFollowupServicePurpose($serviceId,$stage,$key):
        achbotFollowupPurpose($payId,$stage,$key)));
    $sealed=achbotPaymentEvidenceProtect($purpose,$body);
    $sha=hash('sha256',$sealed);
    // A newly queued original is immediately eligible for its first attempt.
    $q=$db->prepare("INSERT INTO payment_followup_intents
        (pay_row_id,service_operation_id,wallet_entry_id,operational_ref,intent_key,stage,kind,role,
         recipient,message_id,pay_fingerprint,sealed_payload,payload_sha256,state,next_attempt_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'pending',NOW(6))");
    if (!$q) throw new RuntimeException('followup_insert_prepare');
    $q->bind_param('iiissssssisss',$payId,$serviceId,$walletId,$ref,$key,$stage,$kind,$role,
        $recipient,$messageId,$fingerprint,$sealed,$sha);
    if (!$q->execute() || $q->affected_rows!==1)
        throw new RuntimeException('followup_insert_unconfirmed');
    $q->close();
}
function achbotFollowupServicePurpose(int $id,string $stage,string $key): string {
    return 'service-followup:v3:'.$id.':'.$stage.':'.$key;
}
function achbotFollowupOperationalEventValid(array $event): bool {
    $keys=array_keys($event);
    if (isset($event['user_row_id'])) {
        require_once __DIR__.'/../Telegram/UserEvents.php';
        return achbotUserEventValid($event);
    }
    if ($keys===['reference','actor','capability','user','ticket_id','ticket_sha256']) {
        return is_int($event['ticket_id']) && $event['ticket_id']>0 &&
            is_string($event['reference']) &&
            preg_match('/^ticket:'.preg_quote((string)$event['ticket_id'],'/').':(?:message:[1-9][0-9]*|close|rate:[1-9][0-9]*)$/D',$event['reference'])===1 &&
            is_int($event['actor']) && $event['actor']>0 && is_int($event['user']) && $event['user']>0 &&
            $event['capability']==='operations' && is_string($event['ticket_sha256']) &&
            preg_match('/^[a-f0-9]{64}$/D',$event['ticket_sha256'])===1;
    }
    $customer=$keys===['reference','actor','capability','user','order_id','order_sha256'];
    if (!$customer && $keys!==['reference','actor','capability','user']) return false;
    if (!is_string($event['reference']) || strlen($event['reference'])>128 ||
        preg_match('/^[a-z][a-z0-9_-]*:[A-Za-z0-9:_-]+$/D',$event['reference'])!==1 ||
        !is_int($event['actor']) || $event['actor']<0 ||
        !in_array($event['capability'],['orders','operations'],true) ||
        !is_int($event['user']) || $event['user']<0) return false;
    return !$customer || (is_int($event['order_id']) && $event['order_id']>0 &&
        is_string($event['order_sha256']) && preg_match('/^[a-f0-9]{64}$/D',$event['order_sha256'])===1 &&
        $event['user']>0 && $event['capability']==='operations' &&
        preg_match('/^(?:direct:[1-9][0-9]*|batch:[1-9][0-9]*:[0-9]+)$/D',$event['reference'])===1);
}
function achbotFollowupOperationalStage(string $stage,?array $event=null): bool {
    if (preg_match('/^user_notice(?:_p[1-9][0-9]*)?$/D',$stage))
        return $event===null || (isset($event['user_row_id']) &&
            in_array($event['event'],['welcome','agency_approved','agency_declined'],true));
    if (isset($event['user_row_id']))
        return in_array($event['event'],['welcome','agency_request'],true) &&
            preg_match('/^operational_report(?:_p[1-9][0-9]*)?$/D',$stage)===1;
    if (preg_match('/^ticket_notice(?:_p[1-9][0-9]*)?$/D',$stage))
        return $event===null || isset($event['ticket_id']);
    if (isset($event['ticket_id'])) return false;
    if ($stage==='operational_customer_notice')
        return $event===null || isset($event['order_id']);
    return ($event===null || !isset($event['order_id'])) &&
        preg_match('/^operational_report(?:_p[1-9][0-9]*)?$/D',$stage)===1;
}
function achbotFollowupOperationalFingerprint(array $event): string {
    return hash('sha256',json_encode($event,JSON_THROW_ON_ERROR));
}
function achbotFollowupOperationalPurpose(string $ref,string $stage,string $key): string {
    return 'operational-followup:v6:'.$ref.':'.$stage.':'.$key;
}
function achbotFollowupWalletPurpose(int $id,string $stage,string $key): string {
    return 'wallet-followup:v5:'.$id.':'.$stage.':'.$key;
}
/** A definitive current permission denial ends only this recipient's
 * presentation. An incomplete permission read must remain recoverable. */
final class AchbotFollowupRecipientIneligible extends RuntimeException {}
function achbotFollowupRequireRecipient(bool $allowed,bool $complete=true): bool {
    if (!$allowed && $complete) throw new AchbotFollowupRecipientIneligible('followup_recipient_ineligible');
    return $allowed;
}
function achbotFollowupStaffAllowed(mysqli $db,int $who,int $owner,?string $capability=null): bool {
    if ($owner<=0) return false;
    $complete=true;
    $allowed=$capability===null
        ?achbotAdminStoredRecipientAllowed($db,$who,$owner,false,$complete)
        :achbotAdminLevelCan(achbotAdminLevel($who,$db,$owner,true,false,$complete),$capability);
    return achbotFollowupRequireRecipient($allowed,$complete);
}
function achbotFollowupGeneralRecipientAllowed(mysqli $db,int $who,int $owner): bool {
    if ($owner<=0) return false;
    $complete=true;
    $allowed=achbotAdminNotificationAllowed($db,$who,$owner,'general',$complete);
    return achbotFollowupRequireRecipient($allowed,$complete);
}
function achbotFollowupChannelAllowed(string $recipient): bool {
    if (!isset($GLOBALS['botState']['rewardChannel'])) return false;
    $channel=achbotFollowupReportChannel((string)$GLOBALS['botState']['rewardChannel']);
    return achbotFollowupRequireRecipient($channel!=='' && $recipient===$channel);
}
/** Who is STILL eligible to receive a previously intended report? */
function achbotFollowupRecipientAllowed(mysqli $db,array $pay,array $r): bool {
    $owner=(int)($GLOBALS['admin']??0);
    $recipient=(string)$r['recipient'];$who=(int)$recipient;
    $role=(string)$r['role'];
    if ($r['stage']==='manual_reject' && in_array($role,['service_admin','service_channel','report_actor'],true))
        return achbotFollowupAdminReportAllowed($db,$r,(int)$pay['approved_by']);
    if ($role==='customer') return $recipient===(string)(int)$pay['user_id'];
    if (in_array($role,['receipt_primary','receipt_mirror'],true)) {
        require_once __DIR__.'/ReceiptNotices.php';
        return achbotReceiptTargetAllowed($db,$pay,$r) && achbotFollowupStaffAllowed($db,$who,$owner);
    }
    return false;
}
/** Shared sealed original packet verification for historical and service sources. */
function achbotFollowupDecodePacket(?array $pay,array $r,?string $sourceFingerprint=null,?array &$event=null): ?array {
    $serviceId=isset($r['service_operation_id'])?(int)$r['service_operation_id']:null;
    $walletId=isset($r['wallet_entry_id'])?(int)$r['wallet_entry_id']:null;
    $ref=$r['operational_ref']??null;
    $payId=$pay===null?null:(int)$pay['id'];
    $fingerprint=$sourceFingerprint??($pay===null?null:achbotPaymentEvidencePayFingerprint($pay));
    if (($payId===null?($r['pay_row_id']!==null || ($serviceId===null && $walletId===null && $ref===null)):
            $payId!==(int)$r['pay_row_id']) ||
        ($walletId!==null && ($payId!==null || $serviceId!==null || $r['stage']!=='wallet_movement')) ||
        ($ref!==null && ($walletId!==null || $payId!==null || $serviceId!==null ||
            !achbotFollowupOperationalStage($r['stage']))) ||
        !is_string($fingerprint) ||
        !hash_equals((string)$r['pay_fingerprint'],$fingerprint) ||
        !hash_equals((string)$r['payload_sha256'],hash('sha256',(string)$r['sealed_payload'])) ||
        !hash_equals((string)$r['intent_key'],achbotFollowupIntentKey(
            (string)$r['stage'],(string)$r['role'],(string)$r['kind'],
            (string)$r['recipient'],$r['message_id']===null?null:(int)$r['message_id'])))
        return null;
    $purpose=$ref!==null?achbotFollowupOperationalPurpose($ref,(string)$r['stage'],(string)$r['intent_key']):
        ($walletId!==null?achbotFollowupWalletPurpose($walletId,(string)$r['stage'],(string)$r['intent_key']):
        ($payId===null?achbotFollowupServicePurpose($serviceId,(string)$r['stage'],(string)$r['intent_key']):
        achbotFollowupPurpose($payId,(string)$r['stage'],(string)$r['intent_key'])));
    $body=achbotPaymentEvidenceUnprotect($purpose,(string)$r['sealed_payload']);
    $version=$ref!==null?6:($walletId!==null?5:($r['stage']==='wallet_credit'?4:($payId===null?3:($serviceId===null?1:2))));
    if (!is_array($body) || ($body['v']??null)!==$version ||
        ($walletId!==null && ($body['wallet_entry_id']??null)!==$walletId) ||
        ($serviceId!==null && ($body['service_operation_id']??null)!==$serviceId)) return null;
    foreach (['pay_id'=>$payId,'stage'=>(string)$r['stage'],
       'kind'=>(string)$r['kind'],'role'=>(string)$r['role'],
       'recipient'=>(string)$r['recipient'],
       'message_id'=>$r['message_id']===null?null:(int)$r['message_id'],
       'pay_fingerprint'=>(string)$r['pay_fingerprint']] as $key=>$value)
        if (($body[$key]??null)!==$value) return null;
    if ($ref!==null) {
        $event=$body['operational_event']??null;
        if (!is_array($event) || !achbotFollowupOperationalEventValid($event) ||
            $event['reference']!==$ref || !achbotFollowupOperationalStage($r['stage'],$event) ||
            !hash_equals(achbotFollowupOperationalFingerprint($event),$fingerprint)) return null;
    }
    $packet=$body['content']??null;
    return is_array($packet) && is_string($packet['text']??null) &&
        ($packet['text']??'')!=='' &&
        in_array($packet['mode']??null,['HTML','plain',null],true) &&
        (is_null($packet['keys']??null) || is_string($packet['keys'])) &&
        (!array_key_exists('photo',$packet) || (achbotFollowupPhotoAllowed($r['stage'],$r['role'],$r['kind']) &&
            is_string($packet['photo']) && $packet['photo']!==''))
        ?$packet:null;
}

function achbotFollowupValidateLocked(mysqli $db,array $pay,array $r): ?array {
    if ($r['stage']==='receipt_admitted') {
        require_once __DIR__.'/ReceiptNotices.php';
        return achbotReceiptNoticeValidateLocked($db,$pay,$r);
    }
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
/** A service summary is one logical report, with sealed source revisions.
 * Receipt markup, customer notices, referral awards and continuation pages
 * are independent messages, even when they share a service operation. */
function achbotFollowupRevisionRank(array $r): int {
    if ($r['pay_row_id']!==null || $r['service_operation_id']===null ||
        $r['kind']!=='send' || !in_array($r['role'],['service_admin','report_actor','service_channel'],true)) return 0;
    return match($r['stage']) {
        'service_funded'=>1,'service_completed'=>2,'service_delivered'=>3,'service_resolution'=>4,default=>0
    };
}
function achbotFollowupLeaseName(mysqli $db,array $r): string {
    $receiptEdit=$r['kind']==='edit' &&
        in_array($r['role'],['receipt_primary','receipt_mirror'],true);
    $key=$receiptEdit?'receipt:'.$r['recipient'].':'.$r['message_id']:
        (achbotFollowupRevisionRank($r)>0
        ?'service:'.$r['service_operation_id'].':'.$r['recipient']:'intent:'.$r['id']);
    return achbotDatabaseLockName($db,'followup:'.$key);
}
/** Read under the source row lock, then serialize only this destination
 * across Telegram I/O. No financial or Native session lease is held. */
function achbotFollowupRevisionRowsLocked(mysqli $db,array $r): array {
    if (achbotFollowupRevisionRank($r)===0) return [];
    $s=achbotProviderStatement($db,"SELECT * FROM payment_followup_intents
        WHERE service_operation_id=? AND recipient=? AND pay_row_id IS NULL
        AND kind='send' AND role IN ('service_admin','report_actor','service_channel')
        AND stage IN ('service_funded','service_completed','service_delivered','service_resolution')
        ORDER BY id FOR UPDATE",'is',[(int)$r['service_operation_id'],$r['recipient']]);
    $rows=[];
    foreach ($s->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $rank=achbotFollowupRevisionRank($row);
        if (isset($rows[$rank]) || !achbotFollowupDecodePacket(null,$row,$row['pay_fingerprint']))
            throw new RuntimeException('followup_revision_identity_invalid');
        $rows[$rank]=$row;
    }
    $s->close();ksort($rows);
    return $rows;
}
function achbotFollowupRevisionEligibleLocked(mysqli $db,array $op,int $rank): bool {
    if ($rank===1) return true;
    if ($rank===4) return $op['resolution_kind']!==null;
    if ($op['stage']!=='completed' || $op['resolution_kind']!==null) return false;
    if ($rank===2) return true;
    // A cheap eligibility filter; the selected delivered revision still
    // undergoes full original packet/ACK verification before any claim.
    $s=achbotProviderRow($db,"SELECT COUNT(*) AS total,
        SUM(state='acknowledged') AS acknowledged FROM payment_delivery_intents WHERE service_operation_id=?",
        'i',[(int)$op['id']]);
    return (int)$s['total']>0 && (int)$s['total']===(int)$s['acknowledged'];
}
function achbotFollowupSelectRevisionLocked(mysqli $db,array $op,array $r,array $rows,int $actor): ?array {
    $rank=achbotFollowupRevisionRank($r);
    if ($rank===0) return $r;
    $selected=$r;
    foreach ($rows as $otherRank=>$other) {
        if ($otherRank<=$rank) continue;
        if ($actor>0) {
            // An older explicit retry must never overwrite a newer ACK.
            if ((int)$other['attempt_count']>0) return null;
        } elseif (achbotFollowupRevisionEligibleLocked($db,$op,$otherRank)) {
            $selected=$other;
        }
    }
    if ($actor>0) return $r;
    if (!achbotFollowupRevisionEligibleLocked($db,$op,achbotFollowupRevisionRank($selected))) return null;
    // A possibly-applied exact edit must be settled before a newer edit.
    // A possibly-applied send must not generate a second message blindly.
    foreach ($rows as $otherRank=>$other) {
        if ($otherRank>=achbotFollowupRevisionRank($selected)) break;
        $last=achbotFollowupLastAttemptLocked($db,$other);
        if ($other['state']==='dispatching' ||
            ($other['state']==='pending' && ($last['result']??null)==='unknown')) return $other;
        if (($last['result']??null)==='unknown') return null;
    }
    return $selected;
}
/** Coalesce only proven untouched/rejected older revisions; never erase
 * their journal. A rate limit follows the logical report to its newer body. */
function achbotFollowupRevisionWireLocked(mysqli $db,array $r,array $rows): array {
    $wire=achbotFollowupDefaultWire($r);$rank=achbotFollowupRevisionRank($r);
    $wait=null;$retire=[];
    foreach ($rows as $otherRank=>$older) {
        if ($otherRank>=$rank) break;
        $last=achbotFollowupLastAttemptLocked($db,$older);
        if (($last['result']??null)==='acknowledged') {
            if ($older['state']!=='acknowledged' || (int)$last['ack_chat_id']===0 || (int)$last['ack_message_id']<=0 ||
                (!str_starts_with($last['wire_recipient'],'@') && (string)$last['ack_chat_id']!==$last['wire_recipient']) ||
                ($last['wire_action']!=='send' && (int)$last['wire_message_id']!==(int)$last['ack_message_id']))
                throw new RuntimeException('followup_revision_ack_unproven');
            $wire=['action'=>'edit_text','recipient'=>(string)$last['ack_chat_id'],'message'=>(int)$last['ack_message_id']];
        } elseif ($last!==null && $last['result']==='rejected') {
            if ($last['wire_action']==='edit_text' && achbotFollowupEditUnavailable($last['failure_reason']))
                $wire=['action'=>'send','recipient'=>$last['wire_recipient'],'message'=>null];
            elseif ($last['wire_action']==='send') $wire=achbotFollowupRecordedWire($last);
        } elseif ($last!==null) throw new RuntimeException('followup_revision_predecessor_unresolved');
        // This can be a deadline inherited by an as-yet unattempted revision.
        if ($older['state']==='pending' && $older['next_attempt_at']!==null &&
            ($wait===null || $older['next_attempt_at']>$wait)) $wait=$older['next_attempt_at'];
        if (in_array($older['state'],['pending','rejected'],true)) $retire[]=$older;
    }
    foreach ($retire as $older) {
        $s=achbotProviderStatement($db,"UPDATE payment_followup_intents SET state='superseded',last_result='superseded',next_attempt_at=NULL
            WHERE id=? AND state=? AND attempt_count=? AND dispatch_token IS NULL",'isi',
            [(int)$older['id'],$older['state'],(int)$older['attempt_count']]);
        $n=$s->affected_rows;$s->close();
        if ($n!==1) throw new RuntimeException('followup_revision_supersession_conflict');
    }
    if ($wait!==null && $r['state']==='pending') {
        $s=achbotProviderStatement($db,"UPDATE payment_followup_intents SET next_attempt_at=GREATEST(COALESCE(next_attempt_at,NOW(6)),?)
            WHERE id=?",'si',[$wait,(int)$r['id']]);$s->close();
    }
    $last=achbotFollowupLastAttemptLocked($db,$r);
    return $last===null?$wire:achbotFollowupRetryWire($last);
}
/** Independent delivery attempts: SQL claims precede network I/O and
 * each recipient/revision has its own durable recovery policy. */
function achbotFollowupDispatchOne(mysqli $db,int $id,int $actor=0,?string $confirmation=null): string {
    if (!achbotFollowupSchemaReady($db) || $id<=0 || $actor<0 ||
        ($actor===0 && $confirmation!==null)) return 'unavailable';
    // Resolve the immutable original owner before the first SQL claim. The
    // in-transaction read below verifies it again after acquiring the lease.
    try {
        $q=$db->prepare('SELECT * FROM payment_followup_intents WHERE id=?');
        $q->bind_param('i',$id);$q->execute();
        $owner=$q->get_result()->fetch_assoc();$q->close();
        if (!$owner) return 'unavailable';
        $payId=(int)($owner['pay_row_id']??0);
        $serviceId=isset($owner['service_operation_id'])?(int)$owner['service_operation_id']:null;
        $walletId=isset($owner['wallet_entry_id'])?(int)$owner['wallet_entry_id']:null;
        $ref=$owner['operational_ref']??null;
        if ($payId<=0 && ($serviceId===null || $serviceId<=0) && ($walletId===null || $walletId<=0) && $ref===null) return 'unavailable';
        if (!achbotFollowupJournaled($owner)) return 'unavailable';
        require_once __DIR__.'/FollowupAttempts.php';
        achbotPaymentEvidenceRequireAutocommit($db);
    } catch (Throwable $error) {
        error_log('achbot_followup_owner_unavailable id='.$id.' class='.get_class($error));
        return 'unavailable';
    }
    if ($serviceId!==null) require_once __DIR__.'/ServiceReports.php';
    if ($ref!==null) require_once __DIR__.'/OperationalReports.php';
    try {
        $name=achbotFollowupLeaseName($db,$owner);
        $held=achbotProviderRow($db,'SELECT GET_LOCK(?,0) AS acquired','s',[$name]);
        if ((int)($held['acquired']??0)!==1) return 'busy';
    } catch (Throwable $error) {
        error_log('achbot_followup_lease_unavailable id='.$id.' class='.get_class($error));
        return 'unavailable';
    }
    try {
    $token='';$r=null;$packet=null;$opened=false;
    try {
        if (!$db->begin_transaction()) return 'unavailable';$opened=true;
        $pay=null;$op=null;$quote=null;
        if ($payId>0) {
            // New credit reports must follow the common owner-first order,
            // never lock a receipt and then wait on its financial owner.
            if (in_array($owner['stage'],['wallet_credit','manual_reject','receipt_admitted'],true)) {
                require_once __DIR__.'/PaymentCredit.php';
                $loc=achbotProviderRow($db,'SELECT user_id FROM pays WHERE id=?','i',[$payId]);
                if (!$loc) throw new RuntimeException('followup_missing');
                achbotWalletLockOwnerLocked($db,(int)$loc['user_id']);
            }
            $q=$db->prepare('SELECT * FROM pays WHERE id=? FOR UPDATE');
            $q->bind_param('i',$payId);$q->execute();$pay=$q->get_result()->fetch_assoc();$q->close();
            if (!$pay) throw new RuntimeException('followup_missing');
            if (in_array($owner['stage'],['wallet_credit','manual_reject','receipt_admitted'],true) && (int)$pay['user_id']!==(int)$loc['user_id'])
                throw new RuntimeException('followup_owner_changed');
        } elseif ($walletId!==null) {
            require_once __DIR__.'/WalletReports.php';
            $wallet=achbotWalletReportEntryLocked($db,$walletId);
        } elseif ($ref===null) {
            $loc=achbotProviderRow($db,'SELECT userid FROM service_operations WHERE id=?','i',[$serviceId]);
            if (!$loc) throw new RuntimeException('followup_service_missing');
            [$op,$quote]=achbotServiceReadLocked($db,(int)$loc['userid'],$serviceId);
        }
        $q=$db->prepare('SELECT * FROM payment_followup_intents WHERE id=? FOR UPDATE');
        $q->bind_param('i',$id);$q->execute();$r=$q->get_result()->fetch_assoc();$q->close();
        if (!$r || $r['stage']!==$owner['stage'] || !achbotFollowupJournaled($r) || (int)$r['pay_row_id']!==$payId ||
            (isset($r['service_operation_id'])?(int)$r['service_operation_id']:null)!==$serviceId ||
            (isset($r['wallet_entry_id'])?(int)$r['wallet_entry_id']:null)!==$walletId ||
            ($r['operational_ref']??null)!==$ref)
            throw new RuntimeException('followup_owner_changed');
        if ($r['intent_key']!==$owner['intent_key'] || $r['recipient']!==$owner['recipient'] ||
            $r['kind']!==$owner['kind']) throw new RuntimeException('followup_owner_changed');
        $revisions=[];
        if ($r['state']==='pending' || ($actor>0 && in_array($r['state'],['unknown','rejected'],true))) {
            $revisions=achbotFollowupRevisionRowsLocked($db,$r);
            if ($revisions) {
                $selected=achbotFollowupSelectRevisionLocked($db,$op,$r,$revisions,$actor);
                if (!$selected) {$db->rollback();$opened=false;return $actor>0?'stale':'not_ready';}
                $r=$selected;$id=(int)$r['id'];
                if ($actor===0 && in_array($r['state'],['acknowledged','skipped','rejected','unknown'],true)) {
                    // A newer decision has already been attempted. Any safe
                    // earlier pending rows must not publish stale progress.
                    achbotFollowupRevisionWireLocked($db,$r,$revisions);
                    if (!$db->commit()) throw new RuntimeException('followup_revision_commit_unknown');
                    $opened=false;return $r['state'];
                }
            }
        }
        if ($r['state']==='dispatching') {
            $state=achbotFollowupAttemptFinishLocked($db,$id,(string)$r['dispatch_token'],
                ['result'=>'unknown','code'=>null,'retry_after'=>null]);
            if (!$db->commit()) throw new RuntimeException('followup_abandoned_commit');
            $opened=false;return $state;
        }
        if (($actor===0 && $r['state']!=='pending') ||
            ($actor>0 && !in_array($r['state'],['unknown','rejected'],true))) {
            $state=$actor===0?(string)$r['state']:'stale';
            $db->rollback();$opened=false;return $state;
        }
        try {
            $packet=$ref!==null?achbotOperationalReportValidateLocked($db,$r):
                ($walletId!==null?achbotWalletReportValidateLocked($db,$wallet,$r):
                ($payId>0?achbotFollowupValidateLocked($db,$pay,$r):
                achbotServiceReportValidateLocked($db,$op,$quote,$r)));
        } catch (AchbotFollowupRecipientIneligible $denied) {
            // Never discard a corrupt or forged packet as an authorization
            // decision. Keep all original attempt evidence, including UNKNOWN.
            if (!achbotFollowupDecodePacket($pay,$r,(string)$r['pay_fingerprint']))
                throw new RuntimeException('followup_identity_invalid');
            if ($actor>0) {
                $primary=(int)($GLOBALS['admin']??0);
                if ($primary<=0 || !achbotAdminCanLocked($db,$actor,$primary,'payments')) {
                    $db->rollback();$opened=false;return 'denied';
                }
                if (!is_string($confirmation) || !hash_equals(achbotFollowupRedriveToken($r,$actor),$confirmation)) {
                    $db->rollback();$opened=false;return 'stale';
                }
            }
            $s=achbotProviderStatement($db,"UPDATE payment_followup_intents
                SET state='skipped',last_result='recipient_ineligible',next_attempt_at=NULL
                WHERE id=? AND state=? AND attempt_count=? AND dispatch_token IS NULL",
                'isi',[$id,$r['state'],(int)$r['attempt_count']]);
            $n=$s->affected_rows;$s->close();
            if ($n!==1 || !$db->commit()) throw new RuntimeException('followup_recipient_skip_unconfirmed');
            $opened=false;
            achbotDiagnosticEvent('achbot.delivery.followup_recipient_skipped',[
                'followup_id'=>$id,'pay_id'=>$payId,'service_operation_id'=>$serviceId,
                'wallet_entry_id'=>$walletId,'operational_ref'=>$ref,
                'reason'=>'recipient_ineligible'],'info');
            return 'skipped';
        }
        if (!$packet) throw new RuntimeException('followup_identity_invalid');
        // Validate explicit actor authority before changing any older row.
        if ($actor>0) {
            $primary=(int)($GLOBALS['admin']??0);
            if ($primary<=0 || !achbotAdminCanLocked($db,$actor,$primary,'payments')) {
                $db->rollback();$opened=false;return 'denied';
            }
            if (!is_string($confirmation) || !hash_equals(achbotFollowupRedriveToken($r,$actor),$confirmation)) {
                $db->rollback();$opened=false;return 'stale';
            }
        }
        $wire=achbotFollowupRevisionWireLocked($db,$r,$revisions);
        $r=achbotProviderRow($db,'SELECT * FROM payment_followup_intents WHERE id=? FOR UPDATE','i',[$id]);
        $claim=achbotFollowupAttemptClaimLocked($db,$r,$actor,$confirmation,$wire);
        if ($claim['status']!=='claimed') {
            if ($claim['status']==='not_due') {
                if (!$db->commit()) throw new RuntimeException('followup_revision_commit_unknown');
            } else $db->rollback();
            $opened=false;return $claim['status'];
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
                'followup_id'=>$id,'pay_id'=>$payId,'service_operation_id'=>$serviceId,'wallet_entry_id'=>$walletId,'operational_ref'=>$ref,
                'reason'=>preg_match('/\A(?:followup|service|payment_credit|wallet|provider)_[a-z0-9_]{1,80}\z/D',$reason)
                    ?$reason:'followup_storage_unavailable',
                'class'=>get_class($error)],'warning');
        }
        return 'unavailable';
    }
    $status='unknown';$outcome=['result'=>'unknown','code'=>null,'retry_after'=>null];
    $priorContext=$GLOBALS['achbotTelegramDiagnosticContext']??null;
    $GLOBALS['achbotTelegramDiagnosticContext']=['followup_id'=>$id,'pay_id'=>$payId,
            'service_operation_id'=>$serviceId,'wallet_entry_id'=>$walletId,'operational_ref'=>$ref,'delivery_attempt'=>(int)$r['attempt_count']+1,
            'delivery_actor'=>$actor];
    try {
        $lock=achbotProviderRow($db,'SELECT IS_USED_LOCK(?)=CONNECTION_ID() AS owned','s',[$name]);
        if ((int)($lock['owned']??0)!==1) throw new RuntimeException('followup_ownership_lost');
        $target=str_starts_with($wire['recipient'],'@')?$wire['recipient']:(int)$wire['recipient'];
        $mode=($packet['mode']??null)==='plain'?null:$packet['mode'];
        $result=match($wire['action']) {
            'edit_keys'=>editKeys((string)$packet['text'],$wire['message'],$target),
            'edit_text'=>editText($wire['message'],(string)$packet['text'],$packet['keys']??null,$mode,$target),
            'send'=>isset($packet['photo'])
                ?sendPhoto($packet['photo'],(string)$packet['text'],$packet['keys']??null,$mode,$target)
                :sendMessage((string)$packet['text'],$packet['keys']??null,$mode,$target)
        };
        $outcome=\AchBot\Telegram\Response\messageDeliveryOutcome($result,$wire['recipient'],$wire['message']);
    } catch(Throwable $error) {
        error_log('achbot_followup_telegram_uncertain id='.$id.' class='.get_class($error));
    } finally {
        if ($priorContext===null) unset($GLOBALS['achbotTelegramDiagnosticContext']);
        else $GLOBALS['achbotTelegramDiagnosticContext']=$priorContext;
    }
    try {
        if (!$db->begin_transaction()) throw new RuntimeException('followup_finish_begin');
        $lock=achbotProviderRow($db,'SELECT IS_USED_LOCK(?)=CONNECTION_ID() AS owned','s',[$name]);
        if ((int)($lock['owned']??0)!==1) throw new RuntimeException('followup_service_ownership_lost');
        $receiptPay=null;
        if ($r['stage']==='receipt_admitted' && $outcome['result']==='acknowledged') {
            require_once __DIR__.'/ReceiptNotices.php';
            [$receiptPay]=achbotCreditInvoiceLocked($db,$payId,(int)$pay['user_id']);
        }
        $status=achbotFollowupAttemptFinishLocked($db,$id,$token,$outcome);
        if ($receiptPay!==null) achbotReceiptNoticeFinishLocked($db,$receiptPay,$r,$outcome,$packet);
        if (!$db->commit()) throw new RuntimeException('followup_ack_commit_unknown');
        achbotDiagnosticEvent('achbot.delivery.followup_result',[
            'followup_id'=>$id,'pay_id'=>$payId,'service_operation_id'=>$serviceId,'wallet_entry_id'=>$walletId,'operational_ref'=>$ref,'actor_id'=>$actor,
            'request_attempt'=>(int)$r['attempt_count']+1,'state'=>$status,
            'telegram_error_code'=>$outcome['code'],'retry_after'=>$outcome['retry_after'],
            'result'=>$outcome['result'],'curl_errno'=>$outcome['curl_errno']??null]+
            (isset($outcome['reason'])?['reason'=>$outcome['reason']]:[]),
            $status==='acknowledged'?'info':'warning');
    } catch(Throwable $error) {
        try {$db->rollback();}catch(Throwable $ignored){}
        error_log('achbot_followup_ack_unknown id='.$id);
        return 'unknown';
    }
    if ($status==='acknowledged' && $r['stage']==='receipt_admitted' && $r['role']==='customer') {
        require_once __DIR__.'/PaymentMessages.php';
        achbotPayUxSyncMessages($db,$payId);
    }
    return $status;
    } finally {
        try {$q=achbotProviderStatement($db,'SELECT RELEASE_LOCK(?)','s',[$name]);$q->close();}
        catch (Throwable $ignored) {}
    }
}
/** All pending rows only, including previous-crash rows. UNKNOWN untouched. */
function achbotFollowupDispatchPending(mysqli $db,int $payId,string $stage,?float $budgetSeconds=null): array {
    if (!achbotFollowupSchemaReady($db) || $payId<=0 ||
        !in_array($stage,['manual_reject','referral_reward','wallet_credit','receipt_admitted'],true)) return [];
    $cursor=0;$out=[];$deadline=$budgetSeconds===null?INF:hrtime(true)/1000000000+$budgetSeconds;
    while (hrtime(true)/1000000000<$deadline) {
        $q=$db->prepare("SELECT id FROM payment_followup_intents
            WHERE pay_row_id=? AND stage=? AND state='pending' AND id>?
            AND (next_attempt_at IS NULL OR next_attempt_at<=NOW(6)) ORDER BY id LIMIT 30");
        $q->bind_param('isi',$payId,$stage,$cursor);$q->execute();
        $rows=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
        if (!$rows) break;
        foreach ($rows as $row) {
            if (hrtime(true)/1000000000>=$deadline) break;
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
    // This runtime requires its current transactional service schema.
    achbotServiceOperationsPresent($db);
    $deadline=hrtime(true)/1000000000+$budgetSeconds;
    $s=achbotProviderStatement($db,"SELECT f.id FROM payment_followup_intents f
        WHERE (f.service_operation_id IS NOT NULL OR f.wallet_entry_id IS NOT NULL OR f.operational_ref IS NOT NULL OR f.stage IN ('wallet_credit','manual_reject','receipt_admitted'))
          AND f.state IN ('pending','dispatching')
          AND (f.next_attempt_at IS NULL OR f.next_attempt_at<=NOW(6))
          AND (f.state='dispatching' OR f.stage<>'service_delivered' OR NOT EXISTS (
              SELECT 1 FROM payment_delivery_intents d WHERE d.service_operation_id=f.service_operation_id
              AND d.state<>'acknowledged'))
          AND (f.state='dispatching' OR f.service_operation_id IS NULL OR f.kind<>'send'
              OR f.role NOT IN ('service_admin','report_actor','service_channel')
              OR FIELD(f.stage,'service_funded','service_completed','service_delivered','service_resolution')<2
              OR NOT EXISTS (
                SELECT 1 FROM payment_followup_intents predecessor
                JOIN payment_followup_attempts last_attempt ON last_attempt.intent_id=predecessor.id
                    AND last_attempt.attempt_no=predecessor.attempt_count
                WHERE predecessor.service_operation_id=f.service_operation_id
                  AND predecessor.recipient=f.recipient AND predecessor.kind='send'
                  AND predecessor.role IN ('service_admin','report_actor','service_channel')
                  AND FIELD(predecessor.stage,'service_funded','service_completed','service_delivered','service_resolution')
                    BETWEEN 1 AND FIELD(f.stage,'service_funded','service_completed','service_delivered','service_resolution')-1
                  AND ((last_attempt.result='unknown' AND predecessor.state IN ('unknown','skipped'))
                    OR (last_attempt.result='unknown' AND predecessor.state='pending' AND predecessor.next_attempt_at>NOW(6))
                    OR (predecessor.state='dispatching' AND predecessor.next_attempt_at>NOW(6)))))
        ORDER BY COALESCE(f.next_attempt_at,f.updated_at),f.id LIMIT ?",'i',[$limit]);
    $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();$out=[];
    foreach ($rows as $r) {
        if (hrtime(true)/1000000000>=$deadline) break;
        $id=(int)$r['id'];
        $state=achbotFollowupDispatchOne($db,$id);
        $out[$id]=$state;
        if (!in_array($state,['unavailable','busy','not_ready','not_due'],true)) continue;
        try {
            // Defer an unavailable already-due retry behind healthy peers;
            // do not shorten or rewrite a newly committed Telegram wait.
            $s=achbotProviderStatement($db,"UPDATE payment_followup_intents SET updated_at=NOW(),
                next_attempt_at=CASE WHEN next_attempt_at IS NULL OR next_attempt_at<=NOW(6)
                    THEN DATE_ADD(NOW(6),INTERVAL 5 SECOND) ELSE next_attempt_at END
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
    ?string $actorKeys=null,array $pages=[],?array $event=null,?string $staffKeys=null,array $additionalActors=[]): void {
    if ($owner<=0) throw new RuntimeException('followup_report_owner_missing');
    $complete=false;$staff=achbotAdminRecipientCandidates($db,$owner,$complete,true);
    if (!$complete) error_log('achbot_report_optional_staff_deferred stage='.$stage);
    $targets=[(string)$owner=>true];
    foreach ($staff as $uid) {
        $complete=true;
        if (achbotAdminNotificationAllowed($db,$uid,$owner,'general',$complete,true))
            $targets[(string)(int)$uid]=true;
        if (!$complete) error_log('achbot_report_optional_permission_deferred stage='.$stage);
    }
    // The authenticated business actor receives their own result even if
    // their unsolicited general-report preference is off. Do not duplicate
    // a manager or the primary owner who is also the original actor.
    foreach ([$actor,...$additionalActors] as $who)
        if ($who>0 && $who!==$owner) $targets[(string)$who]='report_actor';
    $channel=achbotFollowupReportChannel($channel,$stage,$payId,$serviceId,$walletId);
    if ($channel!=='' && !isset($targets[$channel])) $targets[$channel]='service_channel';
    if ($pages!==[] && $event===null && ($serviceId===null || $payId!==null || $walletId!==null ||
        !in_array($stage,['service_completed','service_resolution'],true)))
        throw new RuntimeException('followup_report_pages_source_invalid');
    foreach ($targets as $recipient=>$role) {
        $role=$role===true?'service_admin':$role;
        $packet=$content;
        if ($staffKeys!==null && $role!=='service_channel') $packet['keys']=$staffKeys;
        if ($actorKeys!==null && (string)$recipient===(string)$actor) $packet['keys']=$actorKeys;
        achbotFollowupStoreLocked($db,$payId,$serviceId,$stage,$role,'send',
            (string)$recipient,null,$fingerprint,$packet,$walletId,$event);
        foreach (array_values($pages) as $i=>$page) {
            achbotFollowupStoreLocked($db,null,$serviceId,$stage.'_p'.($i+1),$role,'send',
                (string)$recipient,null,$fingerprint,['text'=>$page,'keys'=>null,'mode'=>$content['mode']],null,$event);
        }
    }
}
function achbotFollowupAdminReportAllowed(mysqli $db,array $r,int $actor=0,string $capability='payments',array $additionalActors=[]): bool {
    $owner=(int)($GLOBALS['admin']??0);$recipient=(string)$r['recipient'];
    if ($r['kind']!=='send' || $r['message_id']!==null) return false;
    if ($r['role']==='report_actor')
        return $owner>0 && (int)$recipient>0 && (string)(int)$recipient===$recipient &&
            in_array((int)$recipient,[$actor,...$additionalActors],true) &&
            achbotFollowupStaffAllowed($db,(int)$recipient,$owner,$capability);
    if ($r['role']==='service_admin')
        return achbotFollowupGeneralRecipientAllowed($db,(int)$recipient,$owner);
    if ($r['role']==='service_channel') {
        return achbotFollowupChannelAllowed($recipient);
    }
    return false;
}
