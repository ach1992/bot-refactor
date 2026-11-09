<?php
/**
 * Authenticated read-only native service-effect inspection used by core recovery.
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
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG

require_once __DIR__.'/ServiceEffects.php';

require_once __DIR__.'/PaymentLifecycle.php';
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
        !in_array($e['pay_state'],['processing','paid','processing_wallet'],true) ||
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
