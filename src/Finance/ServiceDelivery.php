<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/DeliveryLedger.php';
require_once __DIR__.'/DeliveryParts.php';
require_once __DIR__.'/../Services/Funding.php';

/** Reuse the existing 018 journal with an actual service source, never a
 * synthetic pays row. Old 018 packets remain byte-identical. */
function achbotServiceDeliverySchemaReady(mysqli $db): bool {
    try {
        if (!achbotDeliverySchemaReady($db)) return false;
        $q=$db->query('SELECT service_operation_id FROM payment_delivery_intents LIMIT 0');
        if (!$q) return false;
        $q->free();
        $q=$db->query("SHOW INDEX FROM payment_delivery_intents WHERE Key_name='uq_delivery_service_ordinal'");
        if (!$q) return false;
        $keys=[];
        while ($row=$q->fetch_assoc()) {
            if ((int)$row['Non_unique']!==0 || $row['Sub_part']!==null) return false;
            $keys[(int)$row['Seq_in_index']]=$row['Column_name'];
        }
        $q->free();ksort($keys);
        if (array_values($keys)!==['service_operation_id','ordinal'] || !achbotDeliveryPartsSchemaReady($db)) return false;
        $constraint=achbotProviderRow($db,"SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_delivery_intents'
            AND CONSTRAINT_NAME='ck_delivery_source_current' AND CONSTRAINT_TYPE='CHECK'");
        return $constraint!==null;
    } catch (Throwable $e) {return false;}
}

/** Exact original local result identity. Changing an order/account later
 * must not release an obsolete customer packet containing old credentials. */
function achbotServiceBuyDeliveryBinding(array $op,array $quote,array $e,array $o): array {
    $q=$quote['request'];$ordinal=(int)$e['ordinal'];
    $owner=(int)$op['userid'];$id=(int)$op['id'];
    if ($op['kind']!=='BUY' || !in_array($op['stage'],['applied','completed'],true) ||
        (int)$op['reserved_count']!==$q['quantity'] ||
        $ordinal<1 || $ordinal>$q['quantity']-(int)$op['cancelled_count'] ||
        $e['source_kind']!=='service_operation' || $e['source_ref']!==(string)$id ||
        $e['pay_row_id']!==null || $e['effect_kind']!=='create' ||
        $e['target_key']!==$quote['target']['physical'] ||
        (int)$e['actor_userid']!==$owner || (int)$e['reserved_count']!==0 ||
        $e['state']!=='local_recorded' || $e['provider_state']!=='linked' ||
        (int)$e['order_id']!==(int)$o['id'] || (int)$o['id']<=0 ||
        (int)$o['status']!==1 || (int)$o['userid']!==$owner ||
        (int)$o['server_id']!==$q['server_id'] || (int)$e['target_server_id']!==$q['server_id'] ||
        (int)$o['fileid']!==$q['plan_id'] || (int)$e['plan_id']!==$q['plan_id'] ||
        (int)$o['inbound_id']!==0 || $o['remark']!==$e['remote_username'] ||
        (int)$o['provider_effect_id']!==(int)$e['id'] ||
        (int)$o['provider_remote_user_id']<=0 ||
        (int)$o['provider_remote_user_id']!==(int)$e['provider_remote_user_id'] ||
        !is_string($o['provider_remote_created_at']) ||
        $o['provider_remote_created_at']!==$e['provider_remote_created_at'] ||
        (int)$o['amount']!==achbotAllocateOrderAmount($q['price'],$q['quantity'],$ordinal))
        throw new RuntimeException('service_delivery_source_conflict');
    return ['v'=>2,'service_id'=>$id,'quote_sha256'=>$op['quote_sha256'],'ordinal'=>$ordinal,
        'user_id'=>$owner,'effect_id'=>(int)$e['id'],'order_id'=>(int)$o['id'],
        'server_id'=>$q['server_id'],'native_operation'=>$e['operation_id'],
        'native_target_key'=>$e['target_key'],'native_request_sha256'=>$e['request_sha256'],
        'native_user_id'=>(int)$e['provider_remote_user_id'],'native_created_at'=>$e['provider_remote_created_at'],
        'remark'=>$o['remark'],'uuid'=>(string)$o['uuid'],'expire'=>(int)$e['requested_expire'],
        'link_sha256'=>hash('sha256',(string)$o['link']),'token_sha256'=>hash('sha256',(string)$o['token'])];
}
function achbotServiceDeliveryPurpose(int $id,int $ordinal,int $effect,int $order): string {
    return "customer-service-delivery:v2:$id:$ordinal:$effect:$order";
}
function achbotServiceBuyPacketValid(array $packet,int $owner): bool {
    return ($packet['kind']??null)==='qr' && ($packet['uid']??null)===$owner &&
        is_string($packet['qr']??null) && is_string($packet['caption']??null) &&
        trim($packet['caption'])!=='' && is_string($packet['keys']??null) &&
        is_string($packet['bot_url']??null);
}
function achbotServiceBuyDeliveryInsertLocked(mysqli $db,array $op,array $quote,array $e,array $o,array $packet): void {
    require_once __DIR__.'/../Purchase/OrderWrites.php';
    $b=achbotServiceBuyDeliveryBinding($op,$quote,$e,$o);
    if (!achbotServiceBuyPacketValid($packet,$b['user_id']))
        throw new RuntimeException('service_delivery_packet_invalid');
    achbotServiceDeliveryInsertBindingLocked($db,$b,$packet);
}

/** A text completion notice is historical evidence, not current entitlement
 * or a credential packet. It remains valid after later legitimate edits. */
function achbotServiceEditDeliveryBindingLocked(mysqli $db,array $op,array $quote,array $m): array {
    $q=$quote['request'];$kind=$op['kind'];$owner=(int)$op['userid'];$id=(int)$op['id'];
    if (!in_array($kind,['RENEW','ADD_VOLUME','ADD_TIME'],true) ||
        !in_array($op['stage'],['applied','completed'],true) ||
        (int)$m['service_operation_id']!==$id || $m['phase']!=='completed' ||
        !is_string($m['result_payload']) || !is_string($m['intent_payload']) ||
        !hash_equals($m['result_sha256'],hash('sha256',$m['result_payload'])) ||
        !hash_equals($m['intent_sha256'],hash('sha256',$m['intent_payload'])))
        throw new RuntimeException('service_delivery_edit_result_invalid');
    $intent=json_decode($m['intent_payload'],true,64,JSON_THROW_ON_ERROR);
    $result=json_decode($m['result_payload'],true,64,JSON_THROW_ON_ERROR);
    if (($intent['quote_sha256']??null)!==$op['quote_sha256'] ||
        ($result['quote_sha256']??null)!==$op['quote_sha256'] ||
        ($result['intent_sha256']??null)!==$m['intent_sha256'] ||
        !isset($intent['target'],$result['entitlement']) || $intent['target']!==$result['entitlement'] ||
        ($kind==='RENEW')!==($m['reset_ack_at']!==null))
        throw new RuntimeException('service_delivery_edit_evidence_conflict');
    $audit=achbotProviderRow($db,'SELECT * FROM increase_order WHERE id=? FOR UPDATE','i',[(int)$m['audit_id']]);
    if (!$audit || (int)$audit['userid']!==$owner || (int)$audit['server_id']!==$q['server_id'] ||
        (int)$audit['inbound_id']!==0 || $audit['remark']!==$q['remark'] ||
        (int)$audit['amount']!==$q['price'] ||
        ($kind==='ADD_TIME'?(int)$audit['day']!==$q['days']:$audit['day']!==null) ||
        ($kind==='ADD_VOLUME'?(int)$audit['volume']!==$q['volume']:$audit['volume']!==null))
        throw new RuntimeException('service_delivery_edit_audit_invalid');
    return ['v'=>2,'service_id'=>$id,'quote_sha256'=>$op['quote_sha256'],'ordinal'=>1,
        'user_id'=>$owner,'effect_id'=>null,'order_id'=>$q['order_id'],'server_id'=>$q['server_id'],
        'native_operation'=>null,'native_target_key'=>null,
        'account_effect_id'=>(int)$m['account_effect_id'],'audit_id'=>(int)$m['audit_id'],
        'intent_sha256'=>$m['intent_sha256'],'result_sha256'=>$m['result_sha256']];
}
function achbotServiceTextPacketValid(array $packet,int $owner): bool {
    return ($packet['kind']??null)==='text' && ($packet['uid']??null)===$owner &&
        is_string($packet['text']??null) && trim($packet['text'])!=='' &&
        in_array($packet['mode']??null,['HTML','plain'],true) &&
        (($packet['keys']??null)===null || is_string($packet['keys']));
}
function achbotServiceEditDeliveryInsertLocked(mysqli $db,array $op,array $quote,array $m,array $packet): void {
    $b=achbotServiceEditDeliveryBindingLocked($db,$op,$quote,$m);
    if (!achbotServiceTextPacketValid($packet,$b['user_id']))
        throw new RuntimeException('service_delivery_packet_invalid');
    achbotServiceDeliveryInsertBindingLocked($db,$b,$packet);
}
function achbotServiceDeliveryInsertBindingLocked(mysqli $db,array $b,array $packet): int {
    if ((int)(achbotProviderRow($db,'SELECT @@in_transaction AS active')['active']??0)!==1)
        throw new RuntimeException('delivery_insert_requires_transaction');
    $source=hash('sha256',achbotServiceJson($b));
    $parts=achbotSubscriptionDeliveryParts($packet);
    $sealed=achbotPaymentEvidenceProtect(achbotServiceDeliveryPurpose(
        $b['service_id']??0,$b['ordinal'],$b['effect_id']??0,$b['order_id']),
        ['binding'=>$b,'packet'=>$packet,'parts'=>$parts]);
    $s=achbotProviderStatement($db,"INSERT INTO payment_delivery_intents
        (service_operation_id,ordinal,effect_id,order_id,user_id,server_id,source_digest,
         native_operation,native_target_key,sealed_payload,payload_sha256,part_count)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",'iiiiiisssssi',
        [$b['service_id'],$b['ordinal'],$b['effect_id'],$b['order_id'],$b['user_id'],$b['server_id'],
        $source,$b['native_operation'],$b['native_target_key'],$sealed,hash('sha256',$sealed),count($parts)]);
    $n=$s->affected_rows;$id=(int)$s->insert_id;$s->close();
    if ($n!==1 || $id<=0) throw new RuntimeException('service_delivery_insert_failed');
    achbotDeliveryPartsInsertLocked($db,$id,$parts);
    return $id;
}

/** A nonpaid CREATE has the same physical identity and delivery parts, but
 * its original source is the verified effect, never a fabricated payment. */
function achbotNonpaidDeliveryBinding(mysqli $db,int $effectId,int $orderId): array {
    $o=achbotProviderRow($db,'SELECT * FROM orders_list WHERE id=? FOR UPDATE','i',[$orderId]);
    $e=achbotProviderRow($db,'SELECT * FROM service_effects WHERE id=? FOR UPDATE','i',[$effectId]);
    if (!$o || !$e) throw new RuntimeException('delivery_nonpaid_source_missing');
    $owner=(int)$o['userid'];$ordinal=(int)$e['ordinal'];$kind=$e['source_kind'];$ref=$e['source_ref'];
    if ($owner<=0 || $ordinal<=0 || !is_string($ref) ||
        !($kind==='free_trial'?($ordinal===1 && preg_match('/^(?:trial:'.$owner.'|trial_admin:'.$owner.':[1-9][0-9]*)$/D',$ref)===1):
            ($kind==='admin_bulk' && preg_match('/^bulk:'.$owner.':[1-9][0-9]*$/D',$ref)===1)) ||
        $e['pay_row_id']!==null || $e['effect_kind']!=='create' ||
        (int)$e['actor_userid']!==$owner || $e['state']!=='local_recorded' ||
        $e['provider_state']!=='linked' || (int)$e['order_id']!==$orderId ||
        (int)$o['status']!==1 || (int)$o['server_id']!==(int)$e['target_server_id'] ||
        (int)$o['fileid']!==(int)$e['plan_id'] || (int)$o['inbound_id']!==0 ||
        $o['remark']!==$e['remote_username'] || (int)$o['provider_effect_id']!==$effectId ||
        (int)$o['provider_remote_user_id']<=0 ||
        (int)$o['provider_remote_user_id']!==(int)$e['provider_remote_user_id'] ||
        !is_string($o['provider_remote_created_at']) ||
        $o['provider_remote_created_at']!==$e['provider_remote_created_at'])
        throw new RuntimeException('delivery_nonpaid_source_conflict');
    return ['v'=>3,'service_id'=>null,'source_kind'=>$kind,'source_ref'=>$ref,'ordinal'=>$ordinal,
        'user_id'=>$owner,'effect_id'=>$effectId,'order_id'=>$orderId,
        'server_id'=>(int)$o['server_id'],'native_operation'=>$e['operation_id'],
        'native_target_key'=>$e['target_key'],'native_request_sha256'=>$e['request_sha256'],
        'native_user_id'=>(int)$e['provider_remote_user_id'],'native_created_at'=>$e['provider_remote_created_at'],
        'remark'=>$o['remark'],'uuid'=>(string)$o['uuid'],'expire'=>(int)$e['requested_expire'],
        'link_sha256'=>hash('sha256',(string)$o['link']),'token_sha256'=>hash('sha256',(string)$o['token'])];
}
function achbotNonpaidDeliveryInsertLocked(mysqli $db,int $effectId,int $orderId,array $packet): int {
    $b=achbotNonpaidDeliveryBinding($db,$effectId,$orderId);
    if (!achbotServiceBuyPacketValid($packet,$b['user_id']))
        throw new RuntimeException('delivery_nonpaid_packet_invalid');
    return achbotServiceDeliveryInsertBindingLocked($db,$b,$packet);
}
function achbotNonpaidDeliveryValidateLocked(mysqli $db,array $d): array {
    if ($d['service_operation_id']!==null || $d['pay_row_id']!==null ||
        $d['attempt_id']!==null || $d['pay_type']!==null || $d['pay_fingerprint']!==null ||
        (int)$d['effect_id']<=0 || (int)$d['order_id']<=0 ||
        !hash_equals($d['payload_sha256'],hash('sha256',$d['sealed_payload'])))
        throw new RuntimeException('delivery_nonpaid_intent_invalid');
    $b=achbotNonpaidDeliveryBinding($db,(int)$d['effect_id'],(int)$d['order_id']);
    foreach (['ordinal','user_id','server_id'] as $key)
        if ($b[$key]!==(int)$d[$key]) throw new RuntimeException('delivery_nonpaid_binding_conflict');
    foreach (['native_operation','native_target_key'] as $key)
        if ($b[$key]!==$d[$key]) throw new RuntimeException('delivery_nonpaid_binding_conflict');
    $body=achbotPaymentEvidenceUnprotect(achbotServiceDeliveryPurpose(0,$b['ordinal'],$b['effect_id'],$b['order_id']),$d['sealed_payload']);
    if (($body['binding']??null)!==$b || !hash_equals($d['source_digest'],hash('sha256',achbotServiceJson($b))) ||
        !is_array($body['packet']??null) || !achbotServiceBuyPacketValid($body['packet'],$b['user_id']) ||
        !is_array($body['parts']??null))
        throw new RuntimeException('delivery_nonpaid_binding_conflict');
    return $body['parts'];
}

/** Shared original completion evidence for historical reports and actual
 * delivery. No mutable order, provider or delivery ACK is required here. */
function achbotServiceDeliveryOriginalBody(array $op,array $quote,array $d,int $ordinal): array {
    $id=(int)$op['id'];$uid=(int)$op['userid'];$q=$quote['request'];
    $buy=$op['kind']==='BUY';$effect=$d['effect_id']===null?null:(int)$d['effect_id'];
    $order=(int)$d['order_id'];
    if (!in_array($op['stage'],['applied','completed'],true) ||
        (int)$d['service_operation_id']!==$id || $ordinal<1 ||
        $ordinal>($buy?$q['quantity']-(int)$op['cancelled_count']:1) || (int)$d['ordinal']!==$ordinal ||
        (int)$d['user_id']!==$uid || (int)$d['server_id']!==$q['server_id'] || $order<=0 ||
        ($buy?($effect===null || $effect<=0):($effect!==null || $order!==$q['order_id'])) ||
        $d['pay_row_id']!==null || $d['attempt_id']!==null ||
        $d['pay_type']!==null || $d['pay_fingerprint']!==null ||
        !hash_equals($d['payload_sha256'],hash('sha256',$d['sealed_payload'])))
        throw new RuntimeException('service_delivery_original_line_conflict');
    $body=achbotPaymentEvidenceUnprotect(achbotServiceDeliveryPurpose($id,$ordinal,$effect??0,$order),
        $d['sealed_payload']);
    $b=$body['binding']??null;$packet=$body['packet']??null;
    if (!is_array($b) || !is_array($packet) ||
        ($b['v']??null)!==2 || ($b['service_id']??null)!==$id ||
        ($b['quote_sha256']??null)!==$op['quote_sha256'] ||
        ($b['ordinal']??null)!==$ordinal || ($b['user_id']??null)!==$uid ||
        !array_key_exists('effect_id',$b) || $b['effect_id']!==$effect ||
        ($b['order_id']??null)!==$order || ($b['server_id']??null)!==$q['server_id'] ||
        !array_key_exists('native_operation',$b) || $b['native_operation']!==$d['native_operation'] ||
        !array_key_exists('native_target_key',$b) || $b['native_target_key']!==$d['native_target_key'] ||
        !hash_equals($d['source_digest'],hash('sha256',achbotServiceJson($b))) ||
        !($buy?achbotServiceBuyPacketValid($packet,$uid):achbotServiceTextPacketValid($packet,$uid)))
        throw new RuntimeException('service_delivery_original_binding_conflict');
    if ($buy && (!is_string($b['remark']??null) || $b['remark']==='' ||
        $b['native_target_key']!==$quote['target']['physical']))
        throw new RuntimeException('service_delivery_original_account_conflict');
    return $body;
}

/** Delivery additionally requires the current exact credential owner or
 * original verified edit/audit. Historical financial notices need not. */
function achbotServiceDeliveryValidateLocked(mysqli $db,array $op,array $quote,array $d): array {
    require_once __DIR__.'/../Purchase/OrderWrites.php';
    if ($op['stage']!=='completed') throw new RuntimeException('service_delivery_source_unavailable');
    $body=achbotServiceDeliveryOriginalBody($op,$quote,$d,(int)$d['ordinal']);
    if ($op['kind']==='BUY') {
        $e=achbotProviderRow($db,'SELECT * FROM service_effects WHERE id=? FOR UPDATE','i',[(int)$d['effect_id']]);
        $o=achbotProviderRow($db,'SELECT * FROM orders_list WHERE id=? FOR UPDATE','i',[(int)$d['order_id']]);
        if (!$e || !$o) throw new RuntimeException('service_delivery_order_unavailable');
        $b=achbotServiceBuyDeliveryBinding($op,$quote,$e,$o);
    } else {
        $m=achbotProviderRow($db,'SELECT * FROM service_mutations WHERE service_operation_id=? FOR UPDATE','i',[(int)$op['id']]);
        if (!$m || $d['effect_id']!==null) throw new RuntimeException('service_delivery_edit_missing');
        $b=achbotServiceEditDeliveryBindingLocked($db,$op,$quote,$m);
    }
    if ($body['binding']!==$b || !is_array($body['parts']??null))
        throw new RuntimeException('service_delivery_source_conflict');
    return $body['parts'];
}

/** A parent projection alone cannot certify a delivered report. Only
 * acknowledged original 032 parts with matching latest attempt history
 * prove historical Telegram acceptance. No current account read required. */
function achbotServiceDeliveryAcknowledgedLocked(mysqli $db,array $d,array $body): bool {
    if ($d['state']!=='acknowledged' || !isset($d['part_count'])) return false;
    if ($d['acknowledged_at']===null || $d['dispatch_token']!==null ||
        $d['last_result']!=='acknowledged' || !is_array($body['parts']??null))
        throw new RuntimeException('service_delivery_ack_projection_conflict');
    foreach (achbotDeliveryPartsValidateLocked($db,$d,$body['parts']) as $part) {
        if ($part['state']!=='acknowledged')
            throw new RuntimeException('service_delivery_ack_part_conflict');
    }
    return true;
}

/** One original session lease per delivery, plus the existing physical
 * account lease for credential-bearing BUY packets. Commit each part claim,
 * send without SQL locks, then commit its own outcome. A failed/unknown
 * part never prevents an independent, never-started sibling from progressing.
 * An explicit redrive addresses exactly one part, not the entire packet. */
function achbotServiceDeliveryDispatch(mysqli $db,int $id,int $actor=0,int $partNo=0,?string $confirmation=null): string {
    if ($id<=0 || $actor<0 || ($actor===0?($partNo!==0 || $confirmation!==null):
        ($partNo<=0 || $confirmation===null)))
        throw new InvalidArgumentException('service_delivery_dispatch_context');
    achbotPaymentEvidenceRequireAutocommit($db);
    $dbName=achbotProviderRow($db,'SELECT DATABASE() AS name')['name']??null;
    if (!is_string($dbName) || $dbName==='') throw new RuntimeException('service_delivery_database');
    $lock='achbot-delivery-'.substr(hash('sha256',$dbName),0,20).'-'.$id;
    $r=achbotProviderRow($db,'SELECT GET_LOCK(?,0) AS acquired','s',[$lock]);
    if ((int)($r['acquired']??0)!==1) return 'busy';
    $serviceId=0;$claim=null;$physicalLock=null;$serviceLock=null;$cursor=0;
    $priorContext=$GLOBALS['achbotTelegramDiagnosticContext']??null;
    try {
        $loc=achbotProviderRow($db,'SELECT service_operation_id,user_id,effect_id,pay_row_id,part_count
            FROM payment_delivery_intents WHERE id=?','i',[$id]);
        if (!$loc || $loc['pay_row_id']!==null || $loc['part_count']===null ||
            ($loc['service_operation_id']===null && (int)$loc['effect_id']<=0)) return 'missing';
        $serviceId=(int)$loc['service_operation_id'];$owner=(int)$loc['user_id'];
        $nonpaid=$serviceId===0;
        require_once __DIR__.'/../Services/Execution.php';
        if (!$nonpaid) {
            $name=achbotServiceExecutionLock($db,$serviceId);
            $held=achbotProviderRow($db,'SELECT GET_LOCK(?,0) AS acquired','s',[$name]);
            if ((int)($held['acquired']??0)!==1) return 'busy';
            $serviceLock=$name;
        }
        while (true) {
            $claim=null;
            if (!$db->begin_transaction()) throw new RuntimeException('service_delivery_begin');
            if (!$nonpaid) [$op,$quote,$walletOwner]=achbotServiceReadLocked($db,$owner,$serviceId);
            $d=achbotProviderRow($db,'SELECT * FROM payment_delivery_intents WHERE id=? FOR UPDATE','i',[$id]);
            if (!$d || (int)$d['service_operation_id']!==$serviceId || (int)$d['user_id']!==$owner ||
                $d['pay_row_id']!==null || $d['effect_id']!==$loc['effect_id'])
                throw new RuntimeException('service_delivery_source_changed');
            // Source-only pre-032 service drafts have no verified part
            // boundaries. Never fabricate part outcomes from old claims.
            if (!isset($d['part_count']))
                throw new RuntimeException('service_delivery_parts_unadmitted');
            if ($d['state']==='dispatching') {
                $r=achbotDeliveryPartFinishLocked($db,$id,(string)$d['dispatch_token'],
                    ['result'=>'unknown','code'=>null,'retry_after'=>null]);
                if (!$db->commit()) throw new RuntimeException('service_delivery_unknown_commit');
                if ($actor>0) return 'stale';
                continue;
            }
            if ($actor===0 && $d['state']!=='pending') {$db->rollback();return $d['state'];}
            if ($actor>0 && $d['state']==='acknowledged') {$db->rollback();return 'stale';}
            if ($nonpaid) {
                $parts=achbotNonpaidDeliveryValidateLocked($db,$d);
            } else {
                if ($op['stage']!=='completed') {$db->rollback();return 'not_ready';}
                achbotServiceVerifyFundingLocked($db,$op,$walletOwner);
                $parts=achbotServiceDeliveryValidateLocked($db,$op,$quote,$d);
            }
            $rows=achbotDeliveryPartsValidateLocked($db,$d,$parts);
            $p=null;
            foreach ($rows as $row) {
                if ($actor>0?(int)$row['part_no']===$partNo:
                    ($row['state']==='pending' && (int)$row['due']===1 && (int)$row['part_no']>$cursor)) {
                    $p=$row;break;
                }
            }
            if ($p===null) {$db->rollback();return $actor>0?'stale':$d['state'];}
            // Reuse the physical lease already used by account edits,
            // deletion and plan/ownership changes, without a new lock family.
            if (($nonpaid || $op['kind']==='BUY') && $physicalLock===null) {
                $provider=$nonpaid?achbotProviderType($db,(int)$d['server_id']):($quote['target']['provider']??null);
                $adapter=achbotProviderDefinition($provider);
                if ($nonpaid && !isset($adapter['service_account_lock']))
                    throw new RuntimeException('delivery_nonpaid_provider_lock_missing');
                if (isset($adapter['service_account_lock'])) {
                    $name=($adapter['service_account_lock'])($db,(int)$d['order_id']);
                    $held=achbotProviderRow($db,'SELECT GET_LOCK(?,0) AS acquired','s',[$name]);
                    if ((int)($held['acquired']??0)!==1) {$db->rollback();return 'busy';}
                    $physicalLock=$name;
                }
            }
            $claim=achbotDeliveryPartClaimLocked($db,$d,$p,$actor,$confirmation);
            if ($claim['status']!=='claimed') {$db->rollback();return $claim['status'];}
            $cursor=$claim['part_no'];
            if (!$db->commit()) throw new RuntimeException('service_delivery_claim_commit');
            foreach (array_filter([$lock,$physicalLock,$serviceLock]) as $name) {
                $owned=achbotProviderRow($db,'SELECT IS_USED_LOCK(?)=CONNECTION_ID() AS owned','s',[$name]);
                if ((int)($owned['owned']??0)!==1) throw new RuntimeException('service_delivery_ownership_lost');
            }
            $GLOBALS['achbotTelegramDiagnosticContext']=[
                'service_operation_id'=>$serviceId?:null,'service_effect_id'=>$nonpaid?(int)$d['effect_id']:null,
                'delivery_id'=>$id,'delivery_ordinal'=>(int)$d['ordinal'],
                'delivery_part'=>$cursor,'delivery_attempt'=>$claim['attempt'],'delivery_actor'=>$actor];
            $outcome=['result'=>'unknown','code'=>null,'retry_after'=>null];
            try {
                $outcome=achbotSubscriptionSendPart($parts[$cursor-1],$owner);
            } catch (Throwable $e) {
                achbotDiagnosticEvent('achbot.delivery.service_transport_unconfirmed',
                    ['service_operation_id'=>$serviceId,'delivery_id'=>$id,'ordinal'=>(int)$d['ordinal'],
                        'delivery_part'=>$cursor,'request_attempt'=>$claim['attempt'],'class'=>get_class($e)],'warning');
            } finally {
                if ($priorContext===null) unset($GLOBALS['achbotTelegramDiagnosticContext']);
                else $GLOBALS['achbotTelegramDiagnosticContext']=$priorContext;
            }
            if (!$db->begin_transaction()) throw new RuntimeException('service_delivery_finish_begin');
            foreach (array_filter([$lock,$physicalLock,$serviceLock]) as $name) {
                $owned=achbotProviderRow($db,'SELECT IS_USED_LOCK(?)=CONNECTION_ID() AS owned','s',[$name]);
                if ((int)($owned['owned']??0)!==1) throw new RuntimeException('service_delivery_ownership_lost');
            }
            $r=achbotDeliveryPartFinishLocked($db,$id,$claim['token'],$outcome);
            if (!$db->commit()) throw new RuntimeException('service_delivery_finish_commit');
            achbotDiagnosticEvent('achbot.delivery.part_result',[
                'service_operation_id'=>$serviceId,'delivery_id'=>$id,'ordinal'=>(int)$d['ordinal'],
                'delivery_part'=>$cursor,'actor_id'=>$actor,'request_attempt'=>$claim['attempt'],
                'state'=>$r['state'],'result'=>$outcome['result'],'telegram_error_code'=>$outcome['code'],
                'retry_after'=>$outcome['retry_after']],$outcome['result']==='acknowledged'?'info':'warning');
            if ($actor>0) return $r['part_state'];
            if ($r['state']!=='pending') return $r['state'];
            // Scan each part at most once during this invocation, even if
            // a very short 429 wait expires while another part is sent.
        }
    } catch (Throwable $e) {
        try {$db->rollback();} catch (Throwable $ignored) {}
        achbotDiagnosticEvent('achbot.delivery.service_deferred',[
            'service_operation_id'=>$serviceId,'delivery_id'=>$id,'delivery_part'=>$cursor,
            'class'=>get_class($e),'reason'=>$e->getMessage()],'warning');
        return ($claim['status']??null)==='claimed'?'unknown':'deferred';
    } finally {
        foreach (array_filter([$physicalLock,$serviceLock,$lock]) as $name) {
            try {$s=achbotProviderStatement($db,'SELECT RELEASE_LOCK(?)','s',[$name]);$s->close();}
            catch (Throwable $ignored) {}
        }
    }
}
