<?php
/**
 * AChBot #26 — owner-only, read-only paid BUY_SUB effect inspection.
 *
 * This source does not send Telegram, charge/refund, mutate payment/order/
 * service_effects, issue remote CREATE/PUT/DELETE, or decide financial policy.
 * An authenticated GET match is only a point-in-time corroboration, never
 * universal idempotence, proof of merchant funds or permission to replay.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__.'/ServiceEffects.php';

/** Bounded newest-to-oldest, keyset-paginated pending BUY_SUB cases. */
function achbotRecoveryPendingCases(mysqli $db, int $before=0): array {
    if ($before<0) throw new InvalidArgumentException('recovery_invalid_cursor');
    $stmt=$db->prepare("SELECT id,user_id,price,agent_count,state
        FROM pays WHERE type='BUY_SUB' AND state IN ('processing','paid')
          AND (?=0 OR id<?)
        ORDER BY id DESC LIMIT 13");
    if (!$stmt) throw new RuntimeException('recovery_list_prepare_failed');
    $stmt->bind_param('ii',$before,$before);
    if (!$stmt->execute()) throw new RuntimeException('recovery_list_failed');
    $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * Bounded per-payment ordinal page. Counts are LOCAL journal inventory only;
 * never use them to infer remote success, absence, settlement or capacity.
 */
function achbotRecoveryCase(mysqli $db, int $payId, int $afterOrdinal=0): ?array {
    if ($payId<=0 || $afterOrdinal<0)
        throw new InvalidArgumentException('recovery_invalid_subject');
    achbotEffectRequireUniqueLine($db);
    $stmt=$db->prepare("SELECT id,user_id,price,agent_count,state,server_id,
            plan_id FROM pays
        WHERE id=? AND type='BUY_SUB' AND state IN ('processing','paid')
        LIMIT 1");
    if (!$stmt) throw new RuntimeException('recovery_pay_prepare_failed');
    $stmt->bind_param('i',$payId);
    if (!$stmt->execute()) throw new RuntimeException('recovery_pay_read_failed');
    $pay=$stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$pay) return null;

    // Do not guess funds from a processing/paid label or a bank screenshot.
    $stmt=$db->prepare("SELECT provider,stage FROM payment_attempts
        WHERE pay_row_id=? LIMIT 1");
    if (!$stmt) throw new RuntimeException('recovery_method_prepare_failed');
    $stmt->bind_param('i',$payId);
    if (!$stmt->execute()) throw new RuntimeException('recovery_method_read_failed');
    $method=$stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Count the whole local journal, not just the displayed page. Unique
    // ordinal keys plus [1..expected] bounds are needed for completeness.
    $stmt=$db->prepare("SELECT COUNT(*) AS recorded,MIN(ordinal) AS first_ordinal,
            MAX(ordinal) AS last_ordinal FROM service_effects
        WHERE pay_row_id=? AND effect_kind='create'");
    if (!$stmt) throw new RuntimeException('recovery_journal_prepare_failed');
    $stmt->bind_param('i',$payId);
    if (!$stmt->execute()) throw new RuntimeException('recovery_journal_read_failed');
    $journal=$stmt->get_result()->fetch_assoc();
    $stmt->close();
    $requested=(int)$pay['agent_count']>0?(int)$pay['agent_count']:1;
    $recorded=(int)$journal['recorded'];
    $complete=$recorded===$requested &&
        (int)$journal['first_ordinal']===1 &&
        (int)$journal['last_ordinal']===$requested;

    $stmt=$db->prepare("SELECT e.id,e.ordinal,e.state,e.order_id,e.target_server_id,
            e.remote_username,e.operation_id,e.requested_expire,e.requested_bytes,
            e.plan_id,o.id AS live_order_id,o.userid AS order_user,
            o.fileid AS order_plan,o.server_id AS order_server,
            o.inbound_id AS order_inbound,o.remark AS order_username
        FROM service_effects e LEFT JOIN orders_list o ON o.id=e.order_id
        WHERE e.pay_row_id=? AND e.effect_kind='create' AND e.ordinal>?
        ORDER BY e.ordinal LIMIT 13");
    if (!$stmt) throw new RuntimeException('recovery_effect_prepare_failed');
    $stmt->bind_param('ii',$payId,$afterOrdinal);
    if (!$stmt->execute()) throw new RuntimeException('recovery_effect_read_failed');
    $lines=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $items=[];
    foreach ($lines as $line) {
        $local='not_recorded';
        if ($line['order_id']!==null) {
            $local=$line['live_order_id']===null?'historical_order_missing':'linked';
            if ($line['live_order_id']!==null &&
                ((int)$line['order_user']!==(int)$pay['user_id'] ||
                 (int)$line['order_plan']!==(int)$line['plan_id'] ||
                 (int)$line['order_server']!==(int)$line['target_server_id'] ||
                 (int)$line['order_inbound']!==0 ||
                 (string)$line['order_username']!==(string)$line['remote_username']))
                $local='linked_mismatch';
        }
        $items[]=[
            'ordinal'=>(int)$line['ordinal'],'state'=>(string)$line['state'],
            'effect_id'=>(int)$line['id'],'order_id'=>$line['order_id']===null
                ?null:(int)$line['order_id'],
            'local_evidence'=>$local,
            'operation_id'=>(string)$line['operation_id'],
            'requested_expire'=>(string)$line['requested_expire'],
            'requested_bytes'=>(string)$line['requested_bytes'],
            'username'=>(string)$line['remote_username'],
        ];
    }
    return [
        'pay_id'=>(int)$pay['id'],'user_id'=>(int)$pay['user_id'],
        'amount'=>(int)$pay['price'],'requested_accounts'=>$requested,
        'recorded_accounts'=>$recorded,'journal_complete'=>$complete,
        'journal_assurance'=>'current_mutable_payment_count_only',
        'payment_state'=>(string)$pay['state'],
        'method'=>$method ? (string)$method['provider'] : 'unproven',
        'method_stage'=>$method ? (string)$method['stage'] : 'unproven',
        'lines'=>array_slice($items,0,12),
        'next_ordinal'=>count($items)>12 ? (int)$items[11]['ordinal'] : 0,
        'after_ordinal'=>$afterOrdinal,
        'read_only'=>true,
    ];
}

/** Inspect the current exact target identity without holding locks across HTTP. */
function achbotRecoveryTarget(mysqli $db, int $payId, int $ordinal): ?array {
    $stmt=$db->prepare("SELECT e.*,p.state AS pay_state,p.type AS pay_type,
            p.user_id,p.plan_id AS paid_plan,p.server_id AS paid_server,
            p.price,p.agent_count,sp.server_id AS plan_server,
            sp.inbound_id AS plan_inbound,s.panel_group_id,
            c.type AS panel_type,c.panel_url,
            c.username AS panel_auth_user,c.password AS panel_auth_password,
            g.id AS valid_group
        FROM service_effects e
        JOIN pays p ON p.id=e.pay_row_id
        LEFT JOIN server_plans sp ON sp.id=p.plan_id
        LEFT JOIN server_info s ON s.id=e.target_server_id
        LEFT JOIN server_config c ON c.id=e.target_server_id
        LEFT JOIN setting g ON g.id=s.panel_group_id
            AND g.type='ACHBOT_MARZBAN_PANEL_GROUP'
        WHERE e.pay_row_id=? AND e.ordinal=? AND e.effect_kind='create'
        LIMIT 1");
    if (!$stmt) throw new RuntimeException('recovery_target_prepare_failed');
    $stmt->bind_param('ii',$payId,$ordinal);
    if (!$stmt->execute()) throw new RuntimeException('recovery_target_read_failed');
    $row=$stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row?:null;
}

/** Must be a pinned original paid target, not a guessed panel/server. */
function achbotRecoveryTargetValid(?array $e, int $payId, int $ordinal): bool {
    if (!$e || (int)$e['pay_row_id']!==$payId ||
        (int)$e['ordinal']!==$ordinal ||
        $e['pay_type']!=='BUY_SUB' ||
        !in_array($e['pay_state'],['processing','paid'],true) ||
        !in_array($e['state'],['dispatching','verified','local_recorded'],true) ||
        (int)$e['user_id']<=0 ||
        (int)$e['paid_plan']<=0 ||
        (int)$e['price']<0 ||
        (int)$e['agent_count']<0 ||
        $ordinal>max(1,(int)$e['agent_count']) ||
        $e['plan_inbound']===null || (int)$e['plan_inbound']!==0 ||
        (int)$e['target_server_id']<=0 ||
        (int)$e['target_server_id']!==(int)$e['paid_server'] ||
        (int)$e['target_server_id']!==(int)$e['plan_server'] ||
        (int)$e['plan_id']!==(int)$e['paid_plan'] ||
        $e['panel_type']!=='marzban' ||
        !is_string($e['panel_url']) || $e['panel_url']==='' ||
        !is_string($e['panel_auth_user']) || $e['panel_auth_user']==='' ||
        !is_string($e['panel_auth_password']) || $e['panel_auth_password']==='' ||
        !is_string($e['remote_username']) ||
        preg_match('/^[a-zA-Z0-9_-]{1,100}$/D',$e['remote_username'])!==1 ||
        !is_string($e['operation_id']) ||
        preg_match('/^[a-f0-9]{32}$/D',$e['operation_id'])!==1 ||
        (int)$e['requested_expire']<=0 ||
        achbotRecoveryExactInt($e['requested_bytes'])===null ||
        achbotRecoveryExactInt($e['requested_bytes'])===0 ||
        achbotRecoveryExactInt($e['requested_bytes'])%1073741824!==0)
        return false;
    $group=$e['target_group_id']===null?null:(int)$e['target_group_id'];
    $actualGroup=$e['panel_group_id']===null?null:(int)$e['panel_group_id'];
    if ($group!==$actualGroup || ($group!==null && $e['valid_group']===null))
        return false;
    $endpoint=hash('sha256',rtrim($e['panel_url'],'/'));
    $targetKey=$group===null?'server:'.(int)$e['target_server_id']:'group:'.$group;
    if (!hash_equals((string)$e['endpoint_sha256'],$endpoint) ||
        (string)$e['target_key']!==$targetKey) return false;
    $fingerprint=hash('sha256',json_encode([
        $payId,$ordinal,(int)$e['plan_id'],(int)$e['target_server_id'],
        $group,$targetKey,$endpoint,(string)$e['remote_username'],
        (int)$e['requested_expire'],(int)$e['requested_bytes']
    ],JSON_THROW_ON_ERROR));
    return hash_equals((string)$e['request_sha256'],$fingerprint);
}

/** Exact integer, never PHP's truncating (int) of remote JSON floats. */
function achbotRecoveryExactInt($value): ?int {
    if (!is_int($value) &&
        (!is_string($value) || preg_match('/^(0|[1-9][0-9]*)$/D',$value)!==1))
        return null;
    $parsed=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
    return $parsed===false?null:$parsed;
}

/**
 * Exactly one optional remote account GET, with a second local identity read.
 * Caller MUST independently authorize the owner-private invocation.
 * No result permits automatic repair, refund, replay or terminal transition.
 */
function achbotRecoveryInspectLine(mysqli $db, int $payId, int $ordinal,
                                   callable $getExactAccount): array {
    $result=['status'=>'unknown','pay_id'=>$payId,'ordinal'=>$ordinal,
             'remote_gets'=>0,'read_only'=>true,'settlement_proven'=>false];
    if ($payId<=0 || $ordinal<=0) return $result;
    achbotEffectRequireUniqueLine($db);
    $first=achbotRecoveryTarget($db,$payId,$ordinal);
    if (!achbotRecoveryTargetValid($first,$payId,$ordinal)) {
        $result['status']='target_or_identity_changed';
        return $result;
    }
    $result['effect_id']=(int)$first['id'];
    $result['username']=(string)$first['remote_username'];
    try {
        $result['remote_gets']=1;
        // Pass the same immutable in-memory target/credentials to BOTH
        // authentication and account GET. No server-ID lookup in transport.
        $remote=$getExactAccount((string)$first['panel_url'],
                                 (string)$first['panel_auth_user'],
                                 (string)$first['panel_auth_password'],
                                 (string)$first['remote_username']);
    } catch (Throwable $ignored) {
        $result['status']='remote_unavailable';
        return $result;
    }
    // An HTTP 404, transport failure or missing account is never permission to
    // repeat CREATE. The pinned adapter authenticates and requires HTTP 200.
    if (!is_object($remote) || (isset($remote->success) && $remote->success===false)) {
        $result['status']='remote_unavailable';
        return $result;
    }
    $last=achbotRecoveryTarget($db,$payId,$ordinal);
    if (!achbotRecoveryTargetValid($last,$payId,$ordinal) ||
        $first!==$last) {
        $result['status']='changed_during_inspection';
        return $result;
    }
    $expire=isset($remote->expire)?achbotRecoveryExactInt($remote->expire):null;
    $bytes=isset($remote->data_limit)?achbotRecoveryExactInt($remote->data_limit):null;
    $result['status']=(
        (string)($remote->username??'')===(string)$first['remote_username'] &&
        (string)($remote->note??'')==='achbot:v1:'.$first['operation_id'] &&
        $expire===(int)$first['requested_expire'] &&
        $bytes===(int)$first['requested_bytes'] &&
        (string)($remote->status??'')==='active'
    )?'matching_observation':'remote_mismatch';
    return $result;
}
