<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/DeliveryLedger.php';
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
        return array_values($keys)===['service_operation_id','ordinal'];
    } catch (Throwable $e) {return false;}
}

/** Exact original local result identity. Changing an order/account later
 * must not release an obsolete customer packet containing old credentials. */
function achbotServiceBuyDeliveryBinding(array $op,array $quote,array $e,array $o): array {
    $q=$quote['request'];$ordinal=(int)$e['ordinal'];
    $owner=(int)$op['userid'];$id=(int)$op['id'];
    if ($op['kind']!=='BUY' || !in_array($op['stage'],['applied','completed'],true) ||
        (int)$op['reserved_count']!==$q['quantity'] ||
        $ordinal<1 || $ordinal>$q['quantity'] ||
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
        (int)$o['expire_date']!==(int)$e['requested_expire'] ||
        (int)$o['amount']!==achbotAllocateOrderAmount($q['price'],$q['quantity'],$ordinal))
        throw new RuntimeException('service_delivery_source_conflict');
    return ['v'=>2,'service_id'=>$id,'quote_sha256'=>$op['quote_sha256'],'ordinal'=>$ordinal,
        'user_id'=>$owner,'effect_id'=>(int)$e['id'],'order_id'=>(int)$o['id'],
        'server_id'=>$q['server_id'],'native_operation'=>$e['operation_id'],
        'native_target_key'=>$e['target_key'],'native_request_sha256'=>$e['request_sha256'],
        'native_user_id'=>(int)$e['provider_remote_user_id'],'native_created_at'=>$e['provider_remote_created_at'],
        'remark'=>$o['remark'],'uuid'=>(string)$o['uuid'],'expire'=>(int)$o['expire_date'],
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
    $source=hash('sha256',achbotServiceJson($b));
    $sealed=achbotPaymentEvidenceProtect(achbotServiceDeliveryPurpose(
        $b['service_id'],$b['ordinal'],$b['effect_id'],$b['order_id']),['binding'=>$b,'packet'=>$packet]);
    $s=achbotProviderStatement($db,"INSERT INTO payment_delivery_intents
        (service_operation_id,ordinal,effect_id,order_id,user_id,server_id,source_digest,
         native_operation,native_target_key,sealed_payload,payload_sha256)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)",'iiiiiisssss',
        [$b['service_id'],$b['ordinal'],$b['effect_id'],$b['order_id'],$b['user_id'],$b['server_id'],
        $source,$b['native_operation'],$b['native_target_key'],$sealed,hash('sha256',$sealed)]);
    $n=$s->affected_rows;$s->close();
    if ($n!==1) throw new RuntimeException('service_delivery_insert_failed');
}

/** Validate the committed source and the original sealed payload. No remote
 * request, new money, re-rendering or inferred service result. */
function achbotServiceDeliveryValidateLocked(mysqli $db,array $op,array $quote,array $d): array {
    require_once __DIR__.'/../Purchase/OrderWrites.php';
    if ($op['stage']!=='completed' || (int)$d['service_operation_id']!==(int)$op['id'] ||
        (int)$d['user_id']!==(int)$op['userid'] || $d['pay_row_id']!==null ||
        $d['attempt_id']!==null || $d['pay_type']!==null || $d['pay_fingerprint']!==null ||
        !hash_equals($d['payload_sha256'],hash('sha256',$d['sealed_payload'])))
        throw new RuntimeException('service_delivery_source_unavailable');
    $e=achbotProviderRow($db,'SELECT * FROM service_effects WHERE id=? FOR UPDATE','i',[(int)$d['effect_id']]);
    $o=achbotProviderRow($db,'SELECT * FROM orders_list WHERE id=? FOR UPDATE','i',[(int)$d['order_id']]);
    if (!$e || !$o) throw new RuntimeException('service_delivery_order_unavailable');
    $b=achbotServiceBuyDeliveryBinding($op,$quote,$e,$o);
    if ($b['ordinal']!==(int)$d['ordinal'] || $b['server_id']!==(int)$d['server_id'] ||
        $b['native_operation']!==$d['native_operation'] || $b['native_target_key']!==$d['native_target_key'] ||
        !hash_equals($d['source_digest'],hash('sha256',achbotServiceJson($b))))
        throw new RuntimeException('service_delivery_source_conflict');
    $body=achbotPaymentEvidenceUnprotect(achbotServiceDeliveryPurpose(
        $b['service_id'],$b['ordinal'],$b['effect_id'],$b['order_id']),$d['sealed_payload']);
    if (!is_array($body) || ($body['binding']??null)!==$b || !is_array($body['packet']??null) ||
        !achbotServiceBuyPacketValid($body['packet'],$b['user_id']))
        throw new RuntimeException('service_delivery_packet_invalid');
    return $body['packet'];
}

/** A session-local lock prevents an active sender from being mistaken for
 * an abandoned dispatch. After process/session loss classify UNKNOWN, never
 * infer a failed Telegram send or repeat it automatically. */
function achbotServiceDeliveryDispatch(mysqli $db,int $id): string {
    if ($id<=0 || (int)(achbotProviderRow($db,'SELECT @@in_transaction AS tx')['tx']??1)!==0)
        throw new RuntimeException('service_delivery_dispatch_context');
    $dbName=achbotProviderRow($db,'SELECT DATABASE() AS name')['name']??null;
    if (!is_string($dbName) || $dbName==='') throw new RuntimeException('service_delivery_database');
    $lock='achbot-delivery-'.substr(hash('sha256',$dbName),0,20).'-'.$id;
    $r=achbotProviderRow($db,'SELECT GET_LOCK(?,0) AS acquired','s',[$lock]);
    if ((int)($r['acquired']??0)!==1) return 'busy';
    $serviceId=0;$claim=null;
    try {
        $loc=achbotProviderRow($db,'SELECT service_operation_id,user_id FROM payment_delivery_intents WHERE id=?','i',[$id]);
        if (!$loc || $loc['service_operation_id']===null) return 'missing';
        $serviceId=(int)$loc['service_operation_id'];
        $owner=(int)$loc['user_id'];
        if (!$db->begin_transaction()) throw new RuntimeException('service_delivery_begin');
        // Same owner->operation lock order as financial and finalization
        // writers. Terminal unknown/ACK remains terminal.
        [$op,$quote]=achbotServiceReadLocked($db,$owner,$serviceId);
        $d=achbotProviderRow($db,'SELECT * FROM payment_delivery_intents WHERE id=? FOR UPDATE','i',[$id]);
        if (!$d || (int)$d['service_operation_id']!==$serviceId || (int)$d['user_id']!==$owner)
            throw new RuntimeException('service_delivery_source_changed');
        if ($d['state']==='dispatching') {
            $claim=['id'=>$id,'token'=>$d['dispatch_token'],'attempt'=>(int)$d['attempt_count']];
            $state=achbotDeliveryFinishClaimLocked($db,$claim,false);
            if (!$db->commit()) throw new RuntimeException('service_delivery_unknown_commit');
            return $state;
        }
        if ($d['state']!=='pending') {$db->rollback();return $d['state'];}
        if ($op['stage']!=='completed') {$db->rollback();return 'not_ready';}
        if (achbotServiceFundLocked($db,$owner,$serviceId)!=='already_funded')
            throw new RuntimeException('service_delivery_funding_missing');
        $packet=achbotServiceDeliveryValidateLocked($db,$op,$quote,$d);
        $claim=achbotDeliveryClaimLocked($db,$d);
        if (!$db->commit()) throw new RuntimeException('service_delivery_claim_commit');
        $owned=achbotProviderRow($db,'SELECT IS_USED_LOCK(?)=CONNECTION_ID() AS owned','s',[$lock]);
        if ((int)($owned['owned']??0)!==1) throw new RuntimeException('service_delivery_ownership_lost');
        $ack=false;
        try {
            $ack=achbotDeliverySendPacket($packet,0,(int)$d['ordinal'],$claim['attempt'],0,$serviceId);
        } catch (Throwable $e) {
            achbotDiagnosticEvent('achbot.delivery.service_transport_unconfirmed',
                ['service_operation_id'=>$serviceId,'ordinal'=>(int)$d['ordinal'],'class'=>get_class($e)],'warning');
        }
        if (!$db->begin_transaction()) throw new RuntimeException('service_delivery_finish_begin');
        $owned=achbotProviderRow($db,'SELECT IS_USED_LOCK(?)=CONNECTION_ID() AS owned','s',[$lock]);
        if ((int)($owned['owned']??0)!==1) throw new RuntimeException('service_delivery_ownership_lost');
        $state=achbotDeliveryFinishClaimLocked($db,$claim,$ack);
        if (!$db->commit()) throw new RuntimeException('service_delivery_finish_commit');
        return $state;
    } catch (Throwable $e) {
        try {$db->rollback();} catch (Throwable $ignored) {}
        achbotDiagnosticEvent('achbot.delivery.service_deferred',[
            'service_operation_id'=>$serviceId,'class'=>get_class($e),
            'reason'=>$e->getMessage()],'warning');
        return $claim===null?'deferred':'unknown';
    } finally {
        try {$s=achbotProviderStatement($db,'SELECT RELEASE_LOCK(?)','s',[$lock]);$s->close();}
        catch (Throwable $ignored) {}
    }
}
