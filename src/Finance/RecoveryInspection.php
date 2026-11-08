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
/** Display-only proof of a safe original API continuation; settlement
 * repeats all immutable evidence and native-effect checks under its locks.
 * A processing service belongs to technical recovery, not financial review.
 */
function achbotRecoveryApiCanResume(mysqli $db,array $pay): bool {
    $provider=(string)($pay['attempt']['provider']??'');
    $state=(string)($pay['state']??'');
    $id=(int)($pay['id']??0);
    if ($id<=0 || !in_array($provider,['bank_transfer','gift_card'],true) ||
        !in_array($state,['pending','have_sent','processing'],true) ||
        ($state==='processing' && (int)($pay['approved_by']??-1)!==0)) return false;
    $proof=achbotPaymentEvidenceGetProof($db,$id);
    if (($proof['mode']??null)!=='settle') return false;
    if ($state!=='processing' || (string)($pay['type']??'')==='BUY_SUB') return true;
    $attempt=achbotApiPaymentAttempt($db,$id);
    return in_array((string)($attempt['evidence_fulfillment_state']??''),
        ['reserved','verified'],true);
}

/** Payment recording and fulfillment are independent, conservative facts. */
function achbotRecoveryEvidenceText(array $pay, ?array $attempt): string {
    $state=(string)($pay['state']??$pay['payment_state']??'');
    $stage=(string)($attempt['stage']??'');
    $status=(string)($attempt['provider_status']??'');
    $terminal=in_array($state,['paid','approved','auto_cart_approved','paid_with_wallet'],true);
    $funds=$terminal ? \achbotMessage('payment_review.recovery_inspection.recovery_evidence_text.text.b02573f9d0')
        : ($state==='processing_wallet' ? \achbotMessage('payment_review.recovery_inspection.recovery_evidence_text.text.7651d424f1')
        : ($state==='have_sent' ? \achbotMessage('payment_review.recovery_inspection.recovery_evidence_text.text.fbc388c114')
        : \achbotMessage('payment_review.recovery_inspection.recovery_evidence_text.text.b7b90e0fc2')));
    $effect=$stage==='done'
        ? ($status==='compensated' ? \achbotMessage('payment_review.recovery_inspection.recovery_evidence_text.text.49cdc62e8b')
           : \achbotMessage('payment_review.recovery_inspection.recovery_evidence_text.text.cd957f37f7'))
        : ($terminal && $attempt===null ? \achbotMessage('payment_review.recovery_inspection.recovery_evidence_text.text.e0191bbaea')
           : \achbotMessage('payment_review.recovery_inspection.recovery_evidence_text.text.97ebe49e23'));
    return \achbotMessage('payment_review.recovery_inspection.recovery_evidence_text.label.440766e324').$funds.\achbotMessage('payment_review.recovery_inspection.recovery_evidence_text.text.7fc33173f7').$effect."\n";
}

/**
 * Bounded per-payment ordinal page. Counts are LOCAL journal inventory only;
 * never use them to infer remote success, absence, settlement or capacity.
 */
function achbotRecoveryCase(mysqli $db, int $payId, int $afterOrdinal=0): ?array {
    if ($payId<=0 || $afterOrdinal<0)
        throw new InvalidArgumentException('recovery_invalid_subject');
    achbotEffectRequireUniqueLine($db);
    $stmt=$db->prepare("SELECT id,hash_id,user_id,type,price,agent_count,state,server_id,
            plan_id FROM pays
        WHERE id=? AND type='BUY_SUB' AND state IN ('processing','paid','processing_wallet')
        LIMIT 1");
    if (!$stmt) throw new RuntimeException('recovery_pay_prepare_failed');
    $stmt->bind_param('i',$payId);
    if (!$stmt->execute()) throw new RuntimeException('recovery_pay_read_failed');
    $pay=$stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$pay) return null;

    // Do not guess funds from a processing/paid label or a bank screenshot.
    $stmt=$db->prepare("SELECT provider,stage,provider_status FROM payment_attempts
        WHERE pay_row_id=? LIMIT 1");
    if (!$stmt) throw new RuntimeException('recovery_method_prepare_failed');
    $stmt->bind_param('i',$payId);
    if (!$stmt->execute()) throw new RuntimeException('recovery_method_read_failed');
    $method=$stmt->get_result()->fetch_assoc();
    $stmt->close();

    // A BUY_SUB wallet debit and all server slots may be durably held
    // before ordinal 1 exists. Show that escrow for owner-only diagnosis
    // rather than mistaking zero CREATE rows for an unpaid order.
    $walletHold=null;
    if ($pay['state']==='processing_wallet') {
        $hold=$db->prepare("SELECT state,reserved_count FROM service_effects
            WHERE pay_row_id=? AND effect_kind='wallet_hold' AND ordinal=0
            LIMIT 1");
        if (!$hold) throw new RuntimeException('recovery_wallet_hold_prepare');
        $hold->bind_param('i',$payId);
        if (!$hold->execute()) throw new RuntimeException('recovery_wallet_hold_read');
        $walletHold=$hold->get_result()->fetch_assoc();$hold->close();
    }
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
        'hash_id'=>(string)$pay['hash_id'],
        'evidence_text'=>achbotRecoveryEvidenceText($pay,$method?:null),
        'historical_evidence_unavailable'=>$recorded===0 && $pay['state']==='paid',
        'amount'=>(int)$pay['price'],'requested_accounts'=>$requested,
        'recorded_accounts'=>$recorded,'journal_complete'=>$complete,
        'journal_assurance'=>'current_mutable_payment_count_only',
        'payment_state'=>(string)$pay['state'],
        'method'=>$method ? (string)$method['provider'] :
            ($walletHold!==null?'wallet_held':'unproven'),
        'method_stage'=>$method ? (string)$method['stage'] :
            ($walletHold!==null?'operator_reconciliation':'unproven'),
        'wallet_hold_state'=>$walletHold===null?'not_recorded':(string)$walletHold['state'],
        'wallet_capacity_reserved'=>$walletHold===null?0:(int)$walletHold['reserved_count'],
        'lines'=>array_slice($items,0,12),
        'next_ordinal'=>count($items)>12 ? (int)$items[11]['ordinal'] : 0,
        'after_ordinal'=>$afterOrdinal,
        'read_only'=>true,
    ];
}

/** Inspect the current exact target identity without holding locks across HTTP. */
function achbotRecoveryTarget(mysqli $db, int $payId, int $ordinal,
                              string $kind='create'): ?array {
    if (!in_array($kind,['create','replacement'],true)) return null;
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
        WHERE e.pay_row_id=? AND e.ordinal=? AND e.effect_kind=?
        LIMIT 1");
    if (!$stmt) throw new RuntimeException('recovery_target_prepare_failed');
    $stmt->bind_param('iis',$payId,$ordinal,$kind);
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
                                   callable $getExactAccount,string $kind='create'): array {
    $result=['status'=>'unknown','pay_id'=>$payId,'ordinal'=>$ordinal,
             'remote_gets'=>0,'read_only'=>true,'settlement_proven'=>false];
    if ($payId<=0 || $ordinal<=0) return $result;
    achbotEffectRequireUniqueLine($db);
    $first=achbotRecoveryTarget($db,$payId,$ordinal,$kind);
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
    $last=achbotRecoveryTarget($db,$payId,$ordinal,$kind);
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


/**
 * Owner-private PasarGuard CREATE observation. A committed original effect and
 * account provenance are prerequisites for even one GET. This never verifies,
 * attaches, provisions, refunds or dispatches a customer message.
 */
function achbotRecoveryInspectPasarGuardLine(
    mysqli $db,int $payId,int $ordinal,?callable $readAccount=null
): array {
    $result=['status'=>'unknown','pay_id'=>$payId,'ordinal'=>$ordinal,
        'remote_gets'=>0,'read_only'=>true,'settlement_proven'=>false];
    if ($payId<=0 || $ordinal<=0) return $result;
    achbotEffectRequireUniqueLine($db);

    // One local identity contract for both sides of the network observation.
    // Do not compare to current plan Groups: original entitlement is pinned.
    $local=static function() use ($db,$payId,$ordinal): ?array {
        $q=$db->prepare("SELECT e.*,p.type AS pay_type,p.state AS pay_state,
            p.user_id AS paid_user,p.plan_id AS paid_plan,
            p.server_id AS paid_server,p.agent_count,p.price,
            s.server_id AS plan_server,s.inbound_id AS plan_inbound,
            c.type AS panel_type
            FROM service_effects e JOIN pays p ON p.id=e.pay_row_id
            JOIN server_plans s ON s.id=p.plan_id
            JOIN server_config c ON c.id=e.target_server_id
            WHERE e.pay_row_id=? AND e.effect_kind='create'
              AND e.ordinal=? LIMIT 1");
        $q->bind_param('ii',$payId,$ordinal);
        $q->execute();$e=$q->get_result()->fetch_assoc();$q->close();
        if (!$e || $e['pay_type']!=='BUY_SUB' ||
            !in_array($e['pay_state'],['processing','paid','processing_wallet'],true) ||
            !in_array($e['state'],['dispatching','verified','local_recorded'],true) ||
            $e['panel_type']!=='pasarguard' || (int)$e['paid_user']<=0 ||
            (int)$e['paid_plan']<=0 || (int)$e['price']<0 ||
            (int)$e['agent_count']<0 || $ordinal>max(1,(int)$e['agent_count']) ||
            (int)$e['paid_server']<=0 ||
            (int)$e['paid_server']!==(int)$e['target_server_id'] ||
            (int)$e['plan_server']!==(int)$e['target_server_id'] ||
            (int)$e['plan_inbound']!==0 ||
            (int)$e['plan_id']!==(int)$e['paid_plan'] ||
            $e['target_group_id']!==null ||
            preg_match('/^[a-f0-9]{32}$/D',(string)$e['operation_id'])!==1 ||
            !achbotEffectValidUsername((string)$e['remote_username']) ||
            (int)$e['requested_expire']<=0 ||
            ($bytes=achbotRecoveryExactInt($e['requested_bytes']))===null ||
            $bytes===0 || $bytes%1073741824!==0)
            return null;
        try {
            achbotPgLoad();
            $snap=achbotProviderCanonicalSnapshot(json_decode(
                (string)$e['provider_snapshot'],true,64,JSON_THROW_ON_ERROR));
            $target='pg:'.$snap['instance_key'];
            if ($e['target_key']!==$target ||
                !hash_equals((string)$e['endpoint_sha256'],$snap['endpoint_sha256']))
                return null;
            $fingerprint=hash('sha256',json_encode([
                $payId,$ordinal,(int)$e['plan_id'],(int)$e['target_server_id'],
                null,$target,$snap['endpoint_sha256'],(string)$e['remote_username'],
                (int)$e['requested_expire'],$bytes
            ],JSON_THROW_ON_ERROR));
            if (!hash_equals((string)$e['request_sha256'],$fingerprint)) return null;
            $account=achbotProviderAccountByEffect($db,(int)$e['id']);
            if (!$account || (int)$account['effect_id']!==(int)$e['id'] ||
                $account['operation_marker']!=='achbot:v1:'.$e['operation_id'] ||
                $account['remote_username']!==$e['remote_username'] ||
                $account['order_id']!==($e['order_id']===null?null:(int)$e['order_id']) ||
                !in_array($account['state'],['reserved','dispatching','verified','linked'],true))
                return null;
            $entitlement=json_decode((string)$account['entitlement_snapshot'],
                true,64,JSON_THROW_ON_ERROR);
            if (!is_array($entitlement) || ($entitlement['data_limit']??null)!==$bytes ||
                ($entitlement['expire']??null)!==(int)$e['requested_expire'] ||
                ($entitlement['group_ids']??null)!==$snap['group_ids'] ||
                ($entitlement['status']??null)!=='active')
                return null;
            $panel=achbotProviderPanelForSnapshot($db,$snap);
            $serverPanel=achbotPgServerPanel($db,(int)$e['target_server_id']);
            foreach (['id','instance_key','api_url','api_key','version'] as $key)
                if ($panel[$key]!==$serverPanel[$key]) return null;
            if ($account['remote_user_id']!==null &&
                !achbotPgIdentityValid(achbotProviderAccountIdentity($db,$account)))
                return null;
            return ['effect'=>$e,'account'=>$account,
                'snapshot'=>$snap,'panel'=>$panel];
        } catch (Throwable $ignored) {return null;}
    };

    $first=$local();
    if ($first===null) {
        $result['status']='target_or_identity_changed';
        return $result;
    }
    $result['effect_id']=(int)$first['effect']['id'];
    $result['username']=(string)$first['effect']['remote_username'];
    $account=$first['account'];$panel=$first['panel'];
    try {
        $result['remote_gets']=1;
        if ($readAccount!==null) {
            // Tests may substitute exactly this read-only native operation.
            $read=$readAccount($panel,$account);
        } elseif ($account['remote_user_id']!==null) {
            $read=achbotPgRead($panel,achbotProviderAccountIdentity($db,$account));
        } else {
            $name=rawurlencode((string)$account['remote_username']);
            $read=achbotPgUserResult(achbotPgRequest(
                $panel,'GET','/api/user/by-username/'.$name));
        }
    } catch (Throwable $ignored) {
        $read=null;
    }
    // Revalidate the *same* original local claim even if GET failed.
    $last=$local();
    if ($last===null || $last!==$first) {
        $result['status']='changed_during_inspection';
        return $result;
    }
    if (!is_array($read) || ($read['success']??false)!==true ||
        !is_array($read['user']??null)) {
        $result['status']='remote_unavailable';
        return $result;
    }
    $user=$read['user'];
    $result['status']=(
        ($user['username']??null)===$account['remote_username'] &&
        ($user['note']??null)===$account['operation_marker'] &&
        ($user['data_limit']??null)===(int)$first['effect']['requested_bytes'] &&
        ($user['expire']??null)===(int)$first['effect']['requested_expire'] &&
        ($user['group_ids']??null)===$first['snapshot']['group_ids'] &&
        ($user['status']??null)==='active' && achbotPgManaged($user) &&
        ($account['remote_user_id']===null ||
            achbotPgIdentityMatches($user,achbotProviderAccountIdentity($db,$account)))
    )?'matching_observation':'remote_mismatch';
    return $result;
}

/** Route an original paid CREATE Check to its own native read-only adapter. */
function achbotRecoveryInspectPaidLine(
    mysqli $db,int $payId,int $ordinal,callable $marzbanRead
): array {
    if ($payId<=0 || $ordinal<=0)
        return ['status'=>'unknown','pay_id'=>$payId,'ordinal'=>$ordinal,
            'remote_gets'=>0,'read_only'=>true,'settlement_proven'=>false];
    $q=$db->prepare("SELECT c.type FROM service_effects e
        JOIN server_config c ON c.id=e.target_server_id
        WHERE e.pay_row_id=? AND e.effect_kind='create' AND e.ordinal=? LIMIT 1");
    $q->bind_param('ii',$payId,$ordinal);
    $q->execute();$row=$q->get_result()->fetch_assoc();$q->close();
    if (($row['type']??null)==='pasarguard')
        return achbotRecoveryInspectPasarGuardLine($db,$payId,$ordinal);
    if (($row['type']??null)==='marzban')
        return achbotRecoveryInspectLine($db,$payId,$ordinal,$marzbanRead);
    return ['status'=>'target_or_identity_changed','pay_id'=>$payId,
        'ordinal'=>$ordinal,'remote_gets'=>0,'read_only'=>true,
        'settlement_proven'=>false];
}
