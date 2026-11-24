<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/../Panel/ProviderStorage.php';
require_once __DIR__.'/../Subscription/Delivery.php';
require_once __DIR__.'/../Telegram/Response.php';
require_once __DIR__.'/PaymentEvidence.php';
require_once __DIR__.'/../Admin/Access.php';

/** 032 extends the existing 018 intent and attempt journal. No separate
 * sender or synthetic payment; historical whole-packet evidence is untouched. */
function achbotDeliveryPartsSchemaReady(mysqli $db): bool {
    try {
        foreach ([
            'SELECT part_count,next_attempt_at FROM payment_delivery_intents LIMIT 0',
            'SELECT part_no,claim_state,telegram_error_code,retry_after_seconds FROM payment_delivery_attempts LIMIT 0',
            'SELECT intent_id,part_no,payload_sha256,state,last_attempt_no,next_attempt_at FROM payment_delivery_parts LIMIT 0',
        ] as $sql) {
            $s=achbotProviderStatement($db,$sql);$s->close();
        }
        $engine=achbotProviderRow($db,"SHOW TABLE STATUS WHERE Name='payment_delivery_parts'");
        if (strcasecmp((string)($engine['Engine']??''),'InnoDB')!==0) return false;
        $s=achbotProviderStatement($db,"SHOW INDEX FROM payment_delivery_parts WHERE Key_name='PRIMARY'");
        $keys=[];
        foreach ($s->get_result()->fetch_all(MYSQLI_ASSOC) as $row)
            if ((int)$row['Non_unique']===0 && $row['Sub_part']===null)
                $keys[(int)$row['Seq_in_index']]=$row['Column_name'];
        $s->close();ksort($keys);
        return array_values($keys)===['intent_id','part_no'];
    } catch (Throwable $e) {return false;}
}

function achbotDeliveryPartDigest(array $part): string {
    return hash('sha256',json_encode($part,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
}
function achbotDeliveryPartValid(array $part): bool {
    if (($part['kind']??null)==='qr')
        return array_keys($part)===['kind','qr','caption'] && is_string($part['qr']) &&
            $part['qr']!=='' && $part['caption']===null;
    return ($part['kind']??null)==='text' && array_keys($part)===['kind','text','keys','mode'] &&
        is_string($part['text']) && $part['text']!=='' &&
        ($part['keys']===null || is_string($part['keys'])) && in_array($part['mode'],[null,'HTML'],true);
}
function achbotDeliveryPartsInsertLocked(mysqli $db,int $id,array $parts): void {
    if ($id<=0 || !$parts || !array_is_list($parts)) throw new RuntimeException('delivery_parts_invalid');
    foreach ($parts as $index=>$part) {
        if (!is_array($part) || !achbotDeliveryPartValid($part))
            throw new RuntimeException('delivery_part_invalid');
        $s=achbotProviderStatement($db,'INSERT INTO payment_delivery_parts
            (intent_id,part_no,payload_sha256) VALUES (?,?,?)','iis',[$id,$index+1,achbotDeliveryPartDigest($part)]);
        $n=$s->affected_rows;$s->close();
        if ($n!==1) throw new RuntimeException('delivery_part_insert_failed');
    }
}

/** A part projection must agree with its latest durable attempt. */
function achbotDeliveryPartHistoryLocked(mysqli $db,array $d,array $p): ?array {
    $last=achbotProviderRow($db,'SELECT * FROM payment_delivery_attempts
        WHERE intent_id=? AND part_no=? ORDER BY attempt_no DESC LIMIT 1 FOR UPDATE',
        'ii',[(int)$d['id'],(int)$p['part_no']]);
    if (($last===null)!==($p['last_attempt_no']===null) ||
        ($last!==null && ((int)$last['attempt_no']!==(int)$p['last_attempt_no'] ||
            (int)$last['attempt_no']>(int)$d['attempt_count'])))
        throw new RuntimeException('delivery_part_attempt_history_conflict');
    $state=$p['state'];
    if ($state==='pending') {
        $valid=$last===null ? $p['next_attempt_at']===null :
            ($p['next_attempt_at']!==null && (int)$last['retry_after_seconds']>0 &&
                (($last['result']==='rejected' && (int)$last['telegram_error_code']===429) ||
                 ($last['result']==='local_failed' && $last['telegram_error_code']===null)));
    } else {
        $valid=$last!==null && $last['result']===$state && $p['next_attempt_at']===null;
        if ($state==='dispatching')
            $valid=$valid && $d['state']==='dispatching' && $d['dispatch_token']===$last['dispatch_token'];
    }
    if (!$valid) throw new RuntimeException('delivery_part_projection_conflict');
    return $last;
}

/** All part identities are verified against the original sealed manifest,
 * never reconstructed from current display settings or a new splitter. */
function achbotDeliveryPartsValidateLocked(mysqli $db,array $d,array $parts): array {
    $id=(int)$d['id'];
    if (!isset($d['part_count']) || (int)$d['part_count']<=0 ||
        !array_is_list($parts) || count($parts)!==(int)$d['part_count'])
        throw new RuntimeException('delivery_parts_manifest_invalid');
    $s=achbotProviderStatement($db,'SELECT *,next_attempt_at IS NULL OR next_attempt_at<=NOW(6) AS due
        FROM payment_delivery_parts WHERE intent_id=? ORDER BY part_no FOR UPDATE','i',[$id]);
    $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
    if (count($rows)!==count($parts)) throw new RuntimeException('delivery_parts_count_conflict');
    foreach ($rows as $i=>$r) {
        if ((int)$r['part_no']!==$i+1 || !is_array($parts[$i]) || !achbotDeliveryPartValid($parts[$i]) ||
            !hash_equals($r['payload_sha256'],achbotDeliveryPartDigest($parts[$i])) ||
            !in_array($r['state'],['pending','dispatching','acknowledged','unknown','rejected'],true))
            throw new RuntimeException('delivery_part_identity_conflict');
        achbotDeliveryPartHistoryLocked($db,$d,$r);
    }
    return $rows;
}

function achbotDeliveryPartRedriveToken(array $d,array $p,int $actor): string {
    if ($actor<=0 || !isset($d['part_count']) || (int)$d['id']!==(int)$p['intent_id'] ||
        (int)$p['part_no']<=0 || (int)$p['last_attempt_no']<=0 ||
        !in_array($p['state'],['unknown','rejected'],true) ||
        !preg_match('/^[a-f0-9]{64}$/D',(string)$d['payload_sha256']) ||
        !preg_match('/^[a-f0-9]{64}$/D',(string)$p['payload_sha256']))
        throw new RuntimeException('delivery_part_preview_invalid');
    return substr(hash_hmac('sha256',implode('|',['service-delivery-part-redrive-v1',
        $actor,(int)$d['id'],(int)$p['part_no'],(int)$p['last_attempt_no'],$p['state'],
        $d['payload_sha256'],$p['payload_sha256']]),achbotPaymentEvidenceKey()),0,20);
}

/** Parent and child rows are locked by the original owner. A single
 * monotone 018 attempt sequence records every intentional part dispatch. */
function achbotDeliveryPartClaimLocked(mysqli $db,array $d,array $p,int $actor=0,?string $confirmation=null): array {
    $id=(int)$d['id'];$part=(int)$p['part_no'];$state=$p['state'];$count=(int)$d['attempt_count'];
    if (!isset($d['part_count']) || $id!==(int)$p['intent_id'] || $part<1 ||
        $part>(int)$d['part_count'] || $d['state']==='dispatching' || $d['dispatch_token']!==null)
        throw new RuntimeException('delivery_part_claim_identity');
    $last=achbotDeliveryPartHistoryLocked($db,$d,$p);
    if ($actor>0) {
        if (!in_array($state,['unknown','rejected'],true) || !$last || $last['result']!==$state ||
            $confirmation===null) return ['status'=>'stale'];
        $primary=(int)($GLOBALS['admin']??0);
        if ($primary<=0 || !achbotAdminCanLocked($db,$actor,$primary,'payments')) return ['status'=>'denied'];
        if (!hash_equals(achbotDeliveryPartRedriveToken($d,$p,$actor),$confirmation))
            return ['status'=>'stale'];
        if ($state==='unknown' && achbotProviderRow($db,"SELECT id FROM payment_delivery_attempts
            WHERE intent_id=? AND part_no=? AND claim_state='unknown' AND actor_userid>0 LIMIT 1",'ii',[$id,$part]))
            return ['status'=>'stale'];
    } else {
        if ($state!=='pending' || $confirmation!==null) return ['status'=>'stale'];
        if ((int)$p['due']!==1) return ['status'=>'not_due'];
    }
    $token=bin2hex(random_bytes(16));$next=$count+1;
    $s=achbotProviderStatement($db,"UPDATE payment_delivery_intents SET state='dispatching',
        attempt_count=?,dispatch_token=?,dispatched_at=NOW(),last_actor=?,last_result=NULL,next_attempt_at=NULL
        WHERE id=? AND state=? AND attempt_count=? AND dispatch_token IS NULL",
        'isiisi',[$next,$token,$actor,$id,$d['state'],$count]);
    $n=$s->affected_rows;$s->close();
    if ($n!==1) throw new RuntimeException('delivery_part_parent_claim_conflict');
    $s=achbotProviderStatement($db,"UPDATE payment_delivery_parts SET state='dispatching',last_attempt_no=?,next_attempt_at=NULL
        WHERE intent_id=? AND part_no=? AND state=? AND last_attempt_no <=> ?",
        'iiisi',[$next,$id,$part,$state,$p['last_attempt_no']]);
    $n=$s->affected_rows;$s->close();
    if ($n!==1) throw new RuntimeException('delivery_part_claim_conflict');
    $s=achbotProviderStatement($db,'INSERT INTO payment_delivery_attempts
        (intent_id,attempt_no,dispatch_token,actor_userid,part_no,claim_state) VALUES (?,?,?,?,?,?)',
        'iisiis',[$id,$next,$token,$actor,$part,$state]);
    $n=$s->affected_rows;$s->close();
    if ($n!==1) throw new RuntimeException('delivery_part_attempt_insert_failed');
    return ['status'=>'claimed','token'=>$token,'attempt'=>$next,'part_no'=>$part];
}

/** Finalize one committed claim. Aggregate success requires ALL parts ACKed.
 * Independent pending parts remain eligible even when another is UNKNOWN. */
function achbotDeliveryPartFinishLocked(mysqli $db,int $id,string $token,array $outcome): array {
    $d=achbotProviderRow($db,'SELECT * FROM payment_delivery_intents WHERE id=? FOR UPDATE','i',[$id]);
    $a=achbotProviderRow($db,'SELECT * FROM payment_delivery_attempts
        WHERE intent_id=? AND dispatch_token=? FOR UPDATE','is',[$id,$token]);
    if (!$d || !$a || !isset($d['part_count']) || $d['state']!=='dispatching' ||
        $d['dispatch_token']!==$token || $a['result']!=='dispatching' || $a['part_no']===null ||
        (int)$a['attempt_no']!==(int)$d['attempt_count'])
        throw new RuntimeException('delivery_part_finish_identity');
    $part=(int)$a['part_no'];$result=$outcome['result'];$delay=$outcome['retry_after'];$code=$outcome['code'];
    $valid=match($result) {
        'acknowledged','unknown'=>$code===null && $delay===null,
        'rejected'=>in_array($code,[400,401,403,404,409,429],true) &&
            ($delay===null || ($code===429 && is_int($delay) && $delay>0 && $delay<=2147483647)),
        'local_failed'=>$code===null && is_int($delay) && $delay>0 && $delay<=2147483647,
        default=>false
    };
    if (!$valid) throw new RuntimeException('delivery_part_outcome_invalid');
    $state=$delay!==null?'pending':$result;
    $s=achbotProviderStatement($db,"UPDATE payment_delivery_attempts SET result=?,telegram_error_code=?,
        retry_after_seconds=?,finished_at=NOW() WHERE id=? AND result='dispatching'",
        'siii',[$result,$code,$delay,(int)$a['id']]);
    $n=$s->affected_rows;$s->close();
    if ($n!==1) throw new RuntimeException('delivery_part_attempt_finish_failed');
    $s=achbotProviderStatement($db,"UPDATE payment_delivery_parts SET state=?,
        next_attempt_at=CASE WHEN ?='pending' THEN DATE_ADD(NOW(6),INTERVAL ? SECOND) ELSE NULL END
        WHERE intent_id=? AND part_no=? AND state='dispatching' AND last_attempt_no=?",
        'ssiiii',[$state,$state,$delay,$id,$part,(int)$a['attempt_no']]);
    $n=$s->affected_rows;$s->close();
    if ($n!==1) throw new RuntimeException('delivery_part_finish_conflict');
    $r=achbotProviderRow($db,"SELECT COUNT(*) AS total,SUM(state='acknowledged') AS acked,
        SUM(state='pending') AS pending,SUM(state='unknown') AS unknown,SUM(state='rejected') AS rejected,
        MIN(CASE WHEN state='pending' THEN COALESCE(next_attempt_at,NOW(6)) END) AS due
        FROM payment_delivery_parts WHERE intent_id=?",'i',[$id]);
    if ((int)$r['total']!==(int)$d['part_count'] ||
        (int)$r['acked']+(int)$r['pending']+(int)$r['unknown']+(int)$r['rejected']!==(int)$r['total'])
        throw new RuntimeException('delivery_parts_aggregate_conflict');
    $aggregate=match(true) {
        (int)$r['acked']===(int)$r['total']=>'acknowledged',
        (int)$r['pending']>0=>'pending',
        (int)$r['unknown']>0=>'unknown',
        default=>'rejected'
    };
    $s=achbotProviderStatement($db,"UPDATE payment_delivery_intents SET state=?,last_result=?,
        acknowledged_at=CASE WHEN ?='acknowledged' THEN NOW() ELSE NULL END,
        dispatch_token=NULL,next_attempt_at=? WHERE id=? AND state='dispatching' AND dispatch_token=?",
        'ssssis',[$aggregate,$result,$aggregate,$r['due'],$id,$token]);
    $n=$s->affected_rows;$s->close();
    if ($n!==1) throw new RuntimeException('delivery_part_parent_finish_conflict');
    return ['state'=>$aggregate,'part_state'=>$state,'part_no'=>$part];
}
