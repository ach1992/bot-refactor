<?php
/**
 * Issue #26: one durable identity for each paid service account creation.
 *
 * This file does not perform financial settlement, migration, retries or
 * operator reconciliation. A stored dispatch is never repeated blindly.
 * Call ONLY outside an open SQL transaction, after a durable payment claim.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

require_once __DIR__.'/../Panel/ProviderRuntime.php';
require_once __DIR__.'/../Subscription/ServerDisplaySettings.php';

/** Fail closed if the caller would roll back the pre-dispatch marker. */
function achbotEffectRequireAutocommit(mysqli $db): void {
    $res=$db->query('SELECT @@autocommit AS ac, @@in_transaction AS tx');
    if (!$res || !($state=$res->fetch_assoc()) ||
        (int)$state['ac']!==1 || (int)$state['tx']!==0) {
        throw new RuntimeException('effect_requires_autocommit');
    }
}

/** Schema integrity is a prerequisite, never an optional optimization. */
function achbotEffectRequireUniqueLine(mysqli $db): void {
    foreach ([
        'uq_effect_pay_line'=>['pay_row_id','effect_kind','ordinal'],
        'uq_effect_source_line'=>['source_kind','source_ref','ordinal'],
        'uq_effect_target_username'=>['target_key','remote_username'],
        'uq_effect_operation'=>['operation_id'],
        'uq_effect_order'=>['order_id']
    ] as $name=>$expected) {
        $nameSql="'".$db->real_escape_string($name)."'";
        $index=$db->query("SHOW INDEX FROM service_effects WHERE Key_name=$nameSql");
        if (!$index) throw new RuntimeException('effect_unique_index_unavailable');
        $columns=[];
        while ($row=$index->fetch_assoc()) {
            if ((int)$row['Non_unique']!==0)
                throw new RuntimeException('effect_unique_index_invalid');
            $columns[(int)$row['Seq_in_index']]=$row['Column_name'];
        }
        ksort($columns);
        if (array_values($columns)!==$expected)
            throw new RuntimeException('effect_unique_index_invalid');
    }
    // Installing the old 007 without a durable full-quantity ownership field
    // is not equivalent to the current CREATE contract. Abort before POST;
    // never infer an existing payment's held capacity from its remote effects.
    $fields=$db->query("SHOW COLUMNS FROM service_effects LIKE 'reserved_count'");
    if (!$fields || $fields->num_rows!==1)
        throw new RuntimeException('effect_capacity_schema_missing');
    $shape=$fields->fetch_assoc();$fields->free();
    if (!preg_match('/^int(?:\(\d+\))? unsigned$/i',(string)($shape['Type']??'')) ||
        ($shape['Null']??'')!=='NO')
        throw new RuntimeException('effect_capacity_schema_invalid');
}

/**
 * Manual-standard already atomically deducted full capacity under its locked
 * have_sent -> processing payment claim. Journal THAT exact hold in the SAME
 * transaction, rather than deducting it a second time in ordinal 1.
 * An uncertain COMMIT never authorizes a remote CREATE or hold restoration.
 */
function achbotEffectRecordManualCapacityLocked(mysqli $db,array $pay,int $count,?array $apiProof=null): void {
    achbotEffectRequireUniqueLine($db);
    $tx=$db->query('SELECT @@in_transaction AS tx');
    if (!$tx || (int)($tx->fetch_assoc()['tx']??0)!==1)
        throw new RuntimeException('manual_hold_requires_payment_transaction');
    $payId=(int)($pay['id']??0);
    $planId=(int)($pay['plan_id']??0);
    $serverId=(int)($pay['server_id']??0);
    $expectedCount=(int)($pay['agent_count']??0);
    if ($expectedCount===0) $expectedCount=1;
    if ($payId<=0 || $planId<=0 || $serverId<=0 || $count<=0 ||
        $expectedCount!==$count || ($pay['type']??'')!=='BUY_SUB' ||
        (($pay['state']??'')!=='have_sent' && !($apiProof!==null &&
            ($pay['state']??'')==='pending' && function_exists('achbotPaymentEvidenceValidateProofLocked') &&
            achbotPaymentEvidenceValidateProofLocked($db,$pay,$apiProof))))
        throw new RuntimeException('manual_hold_payment_mismatch');
    $stmt=$db->prepare("SELECT i.panel_group_id,c.panel_url,c.type,
         s.server_id,s.inbound_id,g.id AS valid_group
         FROM server_plans s
         JOIN server_info i ON i.id=s.server_id
         JOIN server_config c ON c.id=i.id
         LEFT JOIN setting g ON g.id=i.panel_group_id
             AND g.type='ACHBOT_MARZBAN_PANEL_GROUP'
         WHERE s.id=? AND s.active=1 LIMIT 1");
    if (!$stmt) throw new RuntimeException('manual_hold_target_prepare_failed');
    $stmt->bind_param('i',$planId);
    if (!$stmt->execute()) throw new RuntimeException('manual_hold_target_read_failed');
    $target=$stmt->get_result()->fetch_assoc();$stmt->close();
    if (!$target || (int)$target['server_id']!==$serverId ||
        (int)$target['inbound_id']!==0 || !achbotSupportsAccount($target['type'],0) ||
        ($target['type']==='marzban' && $target['panel_group_id']!==null && $target['valid_group']===null) ||
        !is_string($target['panel_url']) || $target['panel_url']==='')
        throw new RuntimeException('manual_hold_target_invalid');
    $holdTarget=achbotEffectTargetIdentity($db,$serverId,$planId,$payId,$target);
    $group=$holdTarget['group'];$endpoint=$holdTarget['endpoint'];
    $markerKey='manual_hold:'.$payId;
    $markerRemark='manual_hold_'.$payId;
    $operation=bin2hex(random_bytes(16));
    $fingerprint=hash('sha256',json_encode([
        'manual_hold',$payId,$planId,$serverId,$group,$endpoint,$count
    ],JSON_THROW_ON_ERROR));
    $stmt=$db->prepare("INSERT INTO service_effects
        (pay_row_id,ordinal,operation_id,effect_kind,target_server_id,target_group_id,
         target_key,endpoint_sha256,plan_id,remote_username,requested_expire,
         requested_bytes,request_sha256,reserved_count,state)
        VALUES (?,0,?,'manual_hold',?,?,?,?,?,?,0,0,?,?,'reserved')");
    if (!$stmt) throw new RuntimeException('manual_hold_insert_prepare_failed');
    $stmt->bind_param('isiississi',$payId,$operation,$serverId,$group,
        $markerKey,$endpoint,$planId,$markerRemark,$fingerprint,$count);
    if (!$stmt->execute() || $stmt->affected_rows!==1)
        throw new RuntimeException('manual_hold_insert_unconfirmed');
    $stmt->close();
}

/**
 * One provider-bound username contract for customer draft admission,
 * pre-debit verification and the final paid/nonpaid effect dispatcher.
 * Byte-length is intentional: Marzban usernames are ASCII identifiers.
 */
function achbotEffectValidUsername(string $username): bool {
    return strlen($username)>=1 && strlen($username)<=100 &&
        preg_match('/^[a-zA-Z0-9_-]+$/D',$username)===1;
}

/** Reserve ONCE before outbound POST; never derive outcome from account counts. */
function achbotCreatePaidAccount(mysqli $db, int $payId, int $ordinal, array $p): array {
    achbotEffectRequireAutocommit($db);
    achbotEffectRequireUniqueLine($db);
    $serverId=(int)($p['server_id']??0);
    $planId=(int)($p['plan_id']??0);
    $inboundId=(int)($p['inbound_id']??-1);
    $remark=(string)($p['remark']??'');
    $volume=(int)($p['volume']??0);
    $days=(int)($p['days']??0);
    if ($payId<=0 || $ordinal<=0 || $serverId<=0 ||
        $planId<=0 || $inboundId!==0 || !achbotSupportsAccount($p['server_type']??'',0) ||
        $volume<=0 || $days<=0 || $days>36500 ||
        $volume>intdiv(PHP_INT_MAX,1073741824) ||
        !achbotEffectValidUsername($remark)) {
        throw new RuntimeException('effect_invalid_account_request');
    }

    achbotRequireServerDisplay($db,$serverId,(string)$p['server_type']);

    // Keep the source claim and unique line-item reservation in a SHORT local
    // transaction. Its pay-row lock prevents a concurrent terminal payment
    // change between validation and reservation. Never hold it across HTTP.
    if ($db->begin_transaction()!==true) {
        throw new RuntimeException('effect_reserve_begin_failed');
    }
    try {
        // These values are owned by the committed payment claim, not callback text.
        achbotEffectLockTargetRows($db,$serverId,$planId,$payId);
        $stmt=$db->prepare("SELECT p.user_id,p.plan_id,p.server_id AS paid_server,p.type,p.state,p.approved_by,
            p.agent_count AS raw_count,
            CASE WHEN p.agent_count>0 THEN p.agent_count ELSE 1 END AS requested_count,
            s.server_id AS plan_server,s.inbound_id AS plan_inbound,
            c.type AS panel_type,i.panel_group_id,c.panel_url,
            c.username AS panel_username,c.password AS panel_password,
            g.id AS valid_group
            FROM pays p
            JOIN server_plans s ON s.id=p.plan_id
            JOIN server_info i ON i.id=s.server_id
            JOIN server_config c ON c.id=i.id
            LEFT JOIN setting g ON g.id=i.panel_group_id
                AND g.type='ACHBOT_MARZBAN_PANEL_GROUP'
            WHERE p.id=? LIMIT 1 FOR UPDATE");
        if (!$stmt) throw new RuntimeException('effect_claim_query_unavailable');
        $stmt->bind_param('i',$payId);
        if (!$stmt->execute()) throw new RuntimeException('effect_claim_query_failed');
        $claim=$stmt->get_result()->fetch_assoc();$stmt->close();
        if (!$claim || $claim['type']!=='BUY_SUB' ||
            !in_array($claim['state'],['processing','paid','processing_wallet'],true) ||
            (int)$claim['user_id']!==(int)($p['user_id']??0) ||
            (int)$claim['plan_id']!==$planId ||
            // The immutable paid-target anchor is independent of the mutable
            // plan->server mapping, which may change after checkout preflight.
            (int)$claim['paid_server']!==$serverId ||
            (int)$claim['paid_server']!==(int)$claim['plan_server'] ||
            (int)$claim['plan_server']!==$serverId ||
            (int)$claim['plan_inbound']!==$inboundId ||
            !achbotSupportsAccount($claim['panel_type'],0) ||
            ($claim['panel_type']==='marzban' && $claim['panel_group_id']!==null && $claim['valid_group']===null) ||
            !is_string($claim['panel_url']) || $claim['panel_url']==='' ||
            // 0/null is the documented historical one-account default.
            // A negative persisted count is NOT an authorized single account.
            (int)($claim['raw_count']??0)<0 ||
            $ordinal>(int)$claim['requested_count']) {
            throw new RuntimeException('effect_payment_identity_mismatch');
        }

        $targetIdentity=achbotEffectTargetIdentity($db,$serverId,$planId,$payId,$claim);
        $group=$targetIdentity['group'];$endpoint=$targetIdentity['endpoint'];
        $credentials=$targetIdentity['credentials'];$targetKey=$targetIdentity['key'];
        $expiry=time()+86400*$days;
        $bytes=$volume*1073741824;
        $operation=bin2hex(random_bytes(16));
        $fingerprint=hash('sha256',json_encode([
            $payId,$ordinal,$planId,$serverId,$group,$targetKey,$endpoint,$remark,$expiry,$bytes
        ],JSON_THROW_ON_ERROR));
        // The UNIQUE pay/line key is the database backstop. Serializing the
        // short reservation on pays additionally rejects an existing effect even
        // on an operator-misconfigured database missing that unique index.
        // Exactly ONE payment-scoped source of owned server inventory:
        // (a) wallet debit claim with a wallet_hold row,
        // (b) manual receipt claim with a manual_hold row, or
        // (c) hosted settlement with full guarded UPDATE on CREATE ordinal 1.
        // All share the same InnoDB server_info counter; never subtract
        // again for a pre-reserved payment, or release after an unknown POST.
        $requestedCount=(int)$claim['requested_count'];
        if ($requestedCount<=0)
            throw new RuntimeException('effect_invalid_reserved_count');
        $first=$db->prepare("SELECT ordinal,reserved_count FROM service_effects
            WHERE pay_row_id=? AND effect_kind='create'
            ORDER BY ordinal LIMIT 1 FOR UPDATE");
        if (!$first) throw new RuntimeException('effect_capacity_owner_prepare_failed');
        $first->bind_param('i',$payId);
        if (!$first->execute()) throw new RuntimeException('effect_capacity_owner_read_failed');
        $prior=$first->get_result()->fetch_assoc();$first->close();

        $wallet=$claim['state']==='processing_wallet';
        $manual=$claim['state']==='processing' &&
            ((int)($claim['approved_by']??0)>0 ||
             (function_exists('achbotApiPaymentProcessingProofLocked') &&
              achbotApiPaymentProcessingProofLocked($db,$payId)));
        if ($wallet && $manual)
            throw new RuntimeException('effect_capacity_ownership_ambiguous');
        $requiredKind=$wallet?'wallet_hold':($manual?'manual_hold':null);
        $holdStmt=$db->prepare("SELECT id,effect_kind,state,reserved_count,
            target_server_id,target_group_id,target_key,endpoint_sha256,plan_id
            FROM service_effects WHERE pay_row_id=? AND ordinal=0
            AND effect_kind IN ('wallet_hold','manual_hold') FOR UPDATE");
        if (!$holdStmt) throw new RuntimeException('effect_capacity_hold_prepare_failed');
        $holdStmt->bind_param('i',$payId);
        if (!$holdStmt->execute()) throw new RuntimeException('effect_capacity_hold_read_failed');
        $holds=$holdStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $holdStmt->close();
        if ($requiredKind==='wallet_hold' &&
            (count($holds)!==1 || $holds[0]['effect_kind']!==$requiredKind))
            throw new RuntimeException('effect_wallet_hold_missing');
        if ($requiredKind==='manual_hold' &&
            (count($holds)!==1 || $holds[0]['effect_kind']!==$requiredKind))
            throw new RuntimeException('effect_manual_hold_missing_or_unexpected');
        if ($requiredKind===null && count($holds)!==0)
            throw new RuntimeException('effect_capacity_hold_unexpected');
        $hold=$holds[0]??null;
        if ($hold) {
            $valid=(int)$hold['reserved_count']===$requestedCount &&
                (int)$hold['target_server_id']===$serverId &&
                ($hold['target_group_id']===null?null:(int)$hold['target_group_id'])===$group &&
                $hold['endpoint_sha256']===$endpoint &&
                (int)$hold['plan_id']===$planId;
            if ($wallet && $hold['target_key']!==$targetKey) $valid=false;
            if (!$valid) {
                throw new RuntimeException($wallet?
                    'effect_wallet_hold_target_changed':
                    'effect_manual_capacity_ownership_mismatch');
            }
        }
        $reserveSlots=0;
        if ($ordinal===1) {
            if ($prior!==null) throw new RuntimeException('effect_capacity_already_reserved');
            if ($hold!==null) {
                if ($hold['state']!=='reserved')
                    throw new RuntimeException($wallet?
                        'effect_wallet_hold_already_used':
                        'effect_manual_hold_already_consumed');
                $consume=$db->prepare("UPDATE service_effects
                    SET state='consumed' WHERE id=? AND state='reserved'");
                if (!$consume) throw new RuntimeException('effect_capacity_hold_consume_prepare');
                $holdId=(int)$hold['id'];
                $consume->bind_param('i',$holdId);
                if (!$consume->execute() || $consume->affected_rows!==1)
                    throw new RuntimeException('effect_capacity_hold_consume_unconfirmed');
                $consume->close();
                // Full count is already owned by ordinal-0 hold row.
            } else {
                $capacity=$db->prepare("UPDATE server_info SET ucount=ucount-?
                    WHERE id=? AND active=1 AND state=1 AND ucount>=?");
                if (!$capacity) throw new RuntimeException('effect_capacity_prepare_failed');
                $capacity->bind_param('iii',$requestedCount,$serverId,$requestedCount);
                if (!$capacity->execute() || $capacity->affected_rows!==1)
                    throw new RuntimeException('effect_capacity_insufficient_or_unconfirmed');
                $capacity->close();
                $reserveSlots=$requestedCount;
            }
        } else {
            if ($hold!==null && $hold['state']!=='consumed')
                throw new RuntimeException('effect_capacity_hold_not_consumed');
            $expectedCreateHold=$hold===null?$requestedCount:0;
            if (!$prior || (int)$prior['ordinal']!==1 ||
                (int)$prior['reserved_count']!==$expectedCreateHold)
                throw new RuntimeException('effect_capacity_origin_unverified');
            $previousOrdinal=$ordinal-1;
            $previous=$db->prepare("SELECT state FROM service_effects
                WHERE pay_row_id=? AND effect_kind='create' AND ordinal=?
                LIMIT 1 FOR UPDATE");
            if (!$previous) throw new RuntimeException('effect_previous_prepare_failed');
            $previous->bind_param('ii',$payId,$previousOrdinal);
            if (!$previous->execute()) throw new RuntimeException('effect_previous_read_failed');
            $rows=$previous->get_result()->fetch_all(MYSQLI_ASSOC);
            $previous->close();
            if (count($rows)!==1 ||
                !in_array($rows[0]['state'],['verified','local_recorded'],true))
                throw new RuntimeException('effect_previous_line_unconfirmed');
        }

        $existing=$db->prepare("SELECT id FROM service_effects
            WHERE pay_row_id=? AND effect_kind='create' AND ordinal=?
            LIMIT 1 FOR UPDATE");
        if (!$existing) throw new RuntimeException('effect_existing_query_unavailable');
        $existing->bind_param('ii',$payId,$ordinal);
        if (!$existing->execute()) throw new RuntimeException('effect_existing_query_failed');
        $seen=$existing->get_result()->num_rows;
        $existing->close();
        if ($seen!==0) throw new RuntimeException('effect_already_reserved');

        $stmt=$db->prepare("INSERT INTO service_effects
            (pay_row_id,ordinal,operation_id,effect_kind,target_server_id,target_group_id,
             target_key,endpoint_sha256,plan_id,remote_username,requested_expire,requested_bytes,request_sha256,reserved_count,state)
            VALUES (?,?,?,'create',?,?,?,?,?,?,?,?,?,?,'dispatching')");
        if (!$stmt) throw new RuntimeException('effect_reserve_prepare_failed');
        $stmt->bind_param('iisiissisiisi',$payId,$ordinal,$operation,$serverId,$group,
            $targetKey,$endpoint,$planId,$remark,$expiry,$bytes,$fingerprint,$reserveSlots);
        if (!$stmt->execute() || $stmt->affected_rows!==1)
            throw new RuntimeException('effect_reserve_unconfirmed');
        $effectId=(int)$stmt->insert_id;$stmt->close();
        if ($effectId<=0) throw new RuntimeException('effect_reserve_id_missing');
        achbotProviderReserveEffect($db,$effectId,$targetIdentity,$remark,$operation,$expiry,$bytes);
        if ($db->commit()!==true) {
            throw new RuntimeException('effect_reserve_commit_unconfirmed');
        }
    } catch (Throwable $reservationError) {
        try { $db->rollback(); } catch (Throwable $ignored) {}
        // A failed COMMIT acknowledgement may still have persisted a
        // dispatching row. Do not send any remote request from this path.
        throw $reservationError;
    }

    return achbotEffectDispatchReservedAccount(
        $db,$effectId,$serverId,$group,$endpoint,$remark,
        $volume,$days,$planId,$operation,$expiry,$bytes,$p,$credentials);
}

/**
 * Lock shared local targets in one order before a joined effect statement.
 * The caller owns the transaction; this function never starts/commits one.
 * Payment/plan ownership precedes config -> info. No HTTP occurs here.
 */
function achbotEffectLockTargetRows(
    mysqli $db,int $serverId,?int $planId=null,?int $payId=null
): void {
    $status=$db->query('SELECT @@in_transaction AS active_transaction');
    if (!$status || (int)($status->fetch_assoc()['active_transaction']??0)!==1)
        throw new RuntimeException('effect_target_lock_requires_transaction');
    if ($serverId<=0 || ($planId!==null && $planId<=0) || ($payId!==null && $payId<=0))
        throw new RuntimeException('effect_target_lock_invalid');
    if ($payId!==null) {
        $stmt=$db->prepare('SELECT id FROM pays WHERE id=? FOR UPDATE');
        if (!$stmt) throw new RuntimeException('effect_target_payment_prepare');
        $stmt->bind_param('i',$payId);
        if (!$stmt->execute() || $stmt->get_result()->num_rows!==1)
            throw new RuntimeException('effect_target_payment_missing');
        $stmt->close();
    }
    if ($planId!==null) {
        $stmt=$db->prepare('SELECT server_id FROM server_plans WHERE id=? FOR UPDATE');
        if (!$stmt) throw new RuntimeException('effect_target_plan_prepare');
        $stmt->bind_param('i',$planId);
        if (!$stmt->execute()) throw new RuntimeException('effect_target_plan_read');
        $plan=$stmt->get_result()->fetch_assoc();$stmt->close();
        if (!$plan || (int)$plan['server_id']!==$serverId)
            throw new RuntimeException('effect_target_plan_changed');
    }
    foreach (['server_config','server_info'] as $table) {
        // Fixed table allowlist; never request-derived SQL identifiers.
        $stmt=$db->prepare('SELECT id FROM '.$table.' WHERE id=? FOR UPDATE');
        if (!$stmt) throw new RuntimeException('effect_target_server_prepare');
        $stmt->bind_param('i',$serverId);
        if (!$stmt->execute() || $stmt->get_result()->num_rows!==1)
            throw new RuntimeException('effect_target_server_missing');
        $stmt->close();
    }
}

/** Discover immutable effect routing, then lock its exact current local target. */
function achbotEffectLockLinkRows(mysqli $db,int $effectId,bool $paid): array {
    $stmt=$db->prepare('SELECT target_server_id,pay_row_id FROM service_effects WHERE id=?');
    if (!$stmt) throw new RuntimeException('effect_link_target_prepare');
    $stmt->bind_param('i',$effectId);
    if (!$stmt->execute()) throw new RuntimeException('effect_link_target_read');
    $target=$stmt->get_result()->fetch_assoc();$stmt->close();
    $serverId=(int)($target['target_server_id']??0);
    $payId=$target && $target['pay_row_id']!==null?(int)$target['pay_row_id']:null;
    if (!$target || $serverId<=0 || ($paid?($payId===null || $payId<=0):$payId!==null))
        throw new RuntimeException('effect_link_target_invalid');
    achbotEffectLockTargetRows($db,$serverId,null,$payId);
    return [$serverId,$payId];
}

/** Persist verified CREATE without reversing the capacity claimant lock order.
 * No HTTP occurs here. A failed/unknown COMMIT remains review-only.
 */
function achbotVerifyMarzbanCreatedEffect(
    mysqli $db,int $effectId,int $serverId,?int $group,
    string $endpoint,string $operation,array $pinnedTarget
): void {
    $transaction=false;
    try {
        $status=$db->query('SELECT @@in_transaction AS active_transaction');
        if (!$status || (int)($status->fetch_assoc()['active_transaction']??1)!==0)
            throw new RuntimeException('effect_verification_requires_autocommit');
        if (!$db->begin_transaction()) throw new RuntimeException('effect_verification_begin_failed');
        $transaction=true;
        // Claimants already lock config via the plan preflight, then info for
        // capacity. Explicit PK locks prevent an UPDATE JOIN from taking the
        // inverse info -> config order after the remote CREATE has succeeded.
        $stmt=$db->prepare('SELECT type,panel_url,username,password FROM server_config WHERE id=? FOR UPDATE');
        if (!$stmt) throw new RuntimeException('effect_verification_config_prepare');
        $stmt->bind_param('i',$serverId);
        if (!$stmt->execute()) throw new RuntimeException('effect_verification_config_read');
        $config=$stmt->get_result()->fetch_assoc();$stmt->close();
        $stmt=$db->prepare('SELECT panel_group_id FROM server_info WHERE id=? FOR UPDATE');
        if (!$stmt) throw new RuntimeException('effect_verification_server_prepare');
        $stmt->bind_param('i',$serverId);
        if (!$stmt->execute()) throw new RuntimeException('effect_verification_server_read');
        $server=$stmt->get_result()->fetch_assoc();$stmt->close();
        if (!$config || !$server || $config['type']!=='marzban' ||
            rtrim((string)$config['panel_url'],'/')!==$pinnedTarget['panel_url'] ||
            (string)$config['username']!==$pinnedTarget['username'] ||
            (string)$config['password']!==$pinnedTarget['password'] ||
            ($server['panel_group_id']===null?null:(int)$server['panel_group_id'])!==$group)
            throw new RuntimeException('effect_credentials_changed_post');
        $stmt=$db->prepare("UPDATE service_effects SET state='verified',verified_at=NOW()
            WHERE id=? AND state='dispatching' AND order_id IS NULL
              AND target_server_id=? AND target_group_id <=> ?
              AND endpoint_sha256=? AND operation_id=?");
        if (!$stmt) throw new RuntimeException('effect_verification_prepare_failed');
        $stmt->bind_param('iiiss',$effectId,$serverId,$group,$endpoint,$operation);
        if (!$stmt->execute() || $stmt->affected_rows!==1)
            throw new RuntimeException('effect_verification_unconfirmed');
        $stmt->close();
        if (!$db->commit()) throw new RuntimeException('effect_verification_commit_unknown');
        $transaction=false;
    } catch (Throwable $error) {
        if ($transaction) {try {$db->rollback();} catch (Throwable $ignored) {}}
        // Rollback acknowledgement cannot disprove an unknown COMMIT.
        // Never repeat CREATE, compensate money, or claim confirmed rollback.
        throw $error;
    }
}

/** Shared remote dispatch/readback for paid and authenticated non-paid claims. */
function achbotEffectDispatchReservedAccount(
    mysqli $db, int $effectId, int $serverId, ?int $group,
    string $endpoint, string $remark, int $volume, int $days,
    int $planId, string $operation, int $expiry, int $bytes, array $p,
    string $credentials
): array {
    return achbotProviderDispatchReservedAccount($db,$effectId,$serverId,$group,
        $endpoint,$remark,$volume,$days,$planId,$operation,$expiry,$bytes,$p,$credentials);
}

/** Marzban adapter implementation; shared financial ordering remains above. */
function achbotMarzbanEffectDispatchReservedAccount(
    mysqli $db, int $effectId, int $serverId, ?int $group,
    string $endpoint, string $remark, int $volume, int $days,
    int $planId, string $operation, int $expiry, int $bytes, array $p,
    string $credentials
): array {
    // Anything after this point may have reached Marzban, even if the reply
    // is lost. No SQL rollback or failed GET may unlock another CREATE.
    try {
        // A route reconfigured since the reservation must not be used.
        $check=$db->prepare("SELECT i.panel_group_id,c.type,c.panel_url,c.username,c.password
            FROM server_info i JOIN server_config c ON c.id=i.id
            WHERE i.id=? LIMIT 1");
        if (!$check) throw new RuntimeException('effect_target_check_prepare_failed');
        $check->bind_param('i',$serverId);
        if (!$check->execute()) throw new RuntimeException('effect_target_check_failed');
        $target=$check->get_result()->fetch_assoc();$check->close();
        if (!$target || ($target['type']??'')!=='marzban' ||
            ($target['panel_group_id']===null?null:(int)$target['panel_group_id'])!==$group ||
            hash('sha256',rtrim((string)$target['panel_url'],'/'))!==$endpoint ||
            hash('sha256',json_encode([
                (string)$target['username'],(string)$target['password']
            ],JSON_THROW_ON_ERROR))!==$credentials) {
            throw new RuntimeException('effect_target_changed_pre_post');
        }

        $pinnedTarget=[
            'panel_url'=>rtrim((string)$target['panel_url'],'/'),
            'username'=>(string)$target['username'],
            'password'=>(string)$target['password'],
            'panel_group_id'=>$group
        ];
        [$response,$actualRemark,$port]=createServerAccount(
            'marzban',$serverId,0,$planId,$remark,$volume,$days,
            (string)($p['uniqid']??''),(string)($p['protocol']??''),
            (int)($p['port']??0),(int)($p['expire_microdate']??0),
            (string)($p['net_type']??''),(int)($p['rahgozar']??0),
            (int)($p['limitip']??0),$operation,$expiry,$pinnedTarget
        );
        if (!is_object($response) || ($response->success??false)!==true ||
            $actualRemark!==$remark) {
            throw new RuntimeException('effect_remote_create_unconfirmed');
        }
        $remote=getMarzbanUser($serverId,$remark,null,true,
            $pinnedTarget['panel_url'],$pinnedTarget);
        // Marzban must prove exact integer seconds and bytes. PHP's (int)
        // conversion truncates fractional JSON numbers and is not evidence
        // that a paid account actually has the promised limit.
        $exactNonnegative=static function($value): ?int {
            if (!is_int($value) &&
                (!is_string($value) ||
                 preg_match('/^(0|[1-9][0-9]*)$/D',$value)!==1)) return null;
            $parsed=filter_var($value,FILTER_VALIDATE_INT,
                               ['options'=>['min_range'=>0]]);
            return $parsed===false?null:$parsed;
        };
        $note='achbot:v1:'.$operation;
        if (!is_object($remote) ||
            (string)($remote->username??'')!==$remark ||
            (string)($remote->note??'')!==$note ||
            !isset($remote->expire,$remote->data_limit) ||
            $exactNonnegative($remote->expire)!==$expiry ||
            $exactNonnegative($remote->data_limit)!==$bytes) {
            throw new RuntimeException('effect_remote_readback_unverified');
        }
        achbotVerifyMarzbanCreatedEffect($db,$effectId,$serverId,$group,
            $endpoint,$operation,$pinnedTarget);
        return [$response,$remark,$port,$effectId];
    } catch (Throwable $e) {
        // The original payment route owns the operator alert. The effect ID
        // and dispatching marker remain durably queryable by pay_row_id;
        // emitting a second log here would duplicate its alert semantics.
        throw new RuntimeException('effect_requires_operator_reconciliation',0,$e);
    }
}

/** Join exact order identity inside the caller's local order transaction. */
function achbotLinkPaidAccountEffect(mysqli $db, int $effectId, int $orderId): void {
    achbotProviderLinkEffect($db,$effectId,$orderId,true);
}

function achbotMarzbanLinkPaidAccountEffect(mysqli $db, int $effectId, int $orderId): void {
    if ($effectId<=0 || $orderId<=0) throw new RuntimeException('effect_order_id_unconfirmed');
    [$lockedServerId,$lockedPayId]=achbotEffectLockLinkRows($db,$effectId,true);
    $stmt=$db->prepare("UPDATE service_effects e
      INNER JOIN pays p ON p.id=e.pay_row_id
      INNER JOIN orders_list o ON o.id=?
      INNER JOIN server_info i ON i.id=e.target_server_id
      INNER JOIN server_config c ON c.id=i.id
      LEFT JOIN setting g ON g.id=i.panel_group_id
        AND g.type='ACHBOT_MARZBAN_PANEL_GROUP'
      SET e.order_id=o.id,e.state='local_recorded'
      WHERE e.id=? AND e.target_server_id=? AND e.pay_row_id=?
        AND e.state='verified' AND e.order_id IS NULL
        AND o.userid=p.user_id AND o.fileid=e.plan_id
        AND p.server_id=e.target_server_id
        AND o.server_id=e.target_server_id AND o.inbound_id=0
        AND o.remark=e.remote_username
        AND c.type='marzban'
        AND i.panel_group_id <=> e.target_group_id
        AND (i.panel_group_id IS NULL OR g.id IS NOT NULL)
        AND SHA2(TRIM(TRAILING '/' FROM c.panel_url),256)=e.endpoint_sha256");
    if (!$stmt) throw new RuntimeException('effect_link_prepare_failed');
    $stmt->bind_param('iiii',$orderId,$effectId,$lockedServerId,$lockedPayId);
    if (!$stmt->execute() || $stmt->affected_rows!==1)
        throw new RuntimeException('effect_link_unconfirmed');
    $stmt->close();
}

/**
 * Source-backed identity from an authentic Telegram delivery envelope, not
 * a cached inline keyboard, source text or a random regenerated operation.
 * An administrator may request independent new bulk batches on new updates.
 */
function achbotNonPaymentSourceRef(
    object $update, int $actor, string $kind, string $callbackOrText,
    bool $isPrimaryAdmin = false
): string {
    if ($actor<=0 || !in_array($kind,['free_trial','admin_bulk'],true) ||
        !is_int($update->update_id??null) || $update->update_id<=0)
        throw new RuntimeException('nonpaid_source_update_unavailable');
    $event=$kind==='free_trial' ? ($update->callback_query??null)
                                 : ($update->message??null);
    if (!is_object($event) || !is_object($event->from??null) ||
        !is_object($event->message??$event) ||
        (int)($event->from->id??0)!==$actor ||
        (string)($event->data??$event->text??'')!==$callbackOrText)
        throw new RuntimeException('nonpaid_source_actor_mismatch');
    $message=$event->message??$event;
    if (!is_object($message->chat??null) ||
        (string)($message->chat->type??'')!=='private' ||
        (int)($message->chat->id??0)!==$actor ||
        (int)($message->message_id??0)<=0)
        throw new RuntimeException('nonpaid_source_private_chat_required');

    $id=(int)$update->update_id;
    return $kind==='free_trial' && !$isPrimaryAdmin
        ? 'trial:'.$actor
        : ($kind==='free_trial'?'trial_admin:':'bulk:').$actor.':'.$id;
}

/**
 * One durable external CREATE claim for source-authenticated free trials or
 * administrator bulk operations. Shares the EXACT 007 remote dispatcher and
 * whole-quantity capacity boundary with paid BUY_SUB; no synthetic pays rows.
 */
function achbotCreateNonPaymentAccount(
    mysqli $db, string $kind, string $sourceRef, int $actor,
    int $ordinal, int $count, array $p
): array {
    achbotEffectRequireAutocommit($db);
    achbotEffectRequireUniqueLine($db);
    $serverId=(int)($p['server_id']??0);
    $planId=(int)($p['plan_id']??0);
    $remark=(string)($p['remark']??'');
    $volume=(int)($p['volume']??0);
    $days=(int)($p['days']??0);
    if (!in_array($kind,['free_trial','admin_bulk'],true) ||
        !preg_match('/^(trial|trial_admin|bulk):[1-9][0-9]*(?::[1-9][0-9]*)?$/D',$sourceRef) ||
        $actor<=0 || $ordinal<1 || $count<1 || $ordinal>$count ||
        ($kind==='free_trial' && $count!==1) ||
        $serverId<=0 || $planId<=0 || !achbotSupportsAccount($p['server_type']??'',0) ||
        (int)($p['inbound_id']??-1)!==0 ||
        $volume<=0 || $days<=0 || $days>36500 ||
        $volume>intdiv(PHP_INT_MAX,1073741824) ||
        !achbotEffectValidUsername($remark)) {
        throw new RuntimeException('nonpaid_effect_invalid_request');
    }
    if (($kind==='free_trial' && !str_starts_with($sourceRef,'trial:') &&
         !str_starts_with($sourceRef,'trial_admin:')) ||
        ($kind==='admin_bulk' && !str_starts_with($sourceRef,'bulk:')) ||
        !str_starts_with($sourceRef,explode(':',$sourceRef)[0].':'.$actor)) {
        throw new RuntimeException('nonpaid_effect_source_binding_invalid');
    }
    achbotRequireServerDisplay($db,$serverId,(string)$p['server_type']);
    if ($db->begin_transaction()!==true)
        throw new RuntimeException('nonpaid_effect_begin');
    try {
        $user=$db->prepare('SELECT freetrial,isAdmin FROM users WHERE userid=? FOR UPDATE');
        if (!$user) throw new RuntimeException('nonpaid_effect_user_prepare');
        $user->bind_param('i',$actor);
        if (!$user->execute()) throw new RuntimeException('nonpaid_effect_user_read');
        $owners=$user->get_result()->fetch_all(MYSQLI_ASSOC);$user->close();
        if (count($owners)!==1)
            throw new RuntimeException('nonpaid_effect_owner_ambiguous');
        $isPrimaryAdmin=!empty($p['is_primary_admin']);
        if ($kind==='admin_bulk' && !$isPrimaryAdmin &&
            (int)$owners[0]['isAdmin']!==1)
            throw new RuntimeException('nonpaid_effect_admin_required');
        if ($kind==='free_trial' && str_starts_with($sourceRef,'trial:') &&
            in_array($owners[0]['freetrial'],['reserved','used'],true))
            throw new RuntimeException('nonpaid_effect_trial_already_claimed');

        achbotEffectLockTargetRows($db,$serverId,$planId);
        $plan=$db->prepare("SELECT s.server_id,s.inbound_id,c.type,i.panel_group_id,
            c.panel_url,c.username AS panel_username,
            c.password AS panel_password,g.id AS valid_group
            FROM server_plans s
            JOIN server_info i ON i.id=s.server_id
            JOIN server_config c ON c.id=i.id
            LEFT JOIN setting g ON g.id=i.panel_group_id
                AND g.type='ACHBOT_MARZBAN_PANEL_GROUP'
            WHERE s.id=? AND s.active=1 AND i.active=1 AND i.state=1
            LIMIT 1 FOR UPDATE");
        if (!$plan) throw new RuntimeException('nonpaid_effect_plan_prepare');
        $plan->bind_param('i',$planId);
        if (!$plan->execute()) throw new RuntimeException('nonpaid_effect_plan_read');
        $locked=$plan->get_result()->fetch_assoc();$plan->close();
        if (!$locked || (int)$locked['server_id']!==$serverId ||
            (int)$locked['inbound_id']!==0 || !achbotSupportsAccount($locked['type'],0) ||
            ($locked['type']==='marzban' && $locked['panel_group_id']!==null && $locked['valid_group']===null) ||
            (string)$locked['panel_url']==='')
            throw new RuntimeException('nonpaid_effect_target_changed');
        $targetIdentity=achbotEffectTargetIdentity($db,$serverId,$planId,null,$locked);
        $group=$targetIdentity['group'];$endpoint=$targetIdentity['endpoint'];
        $credentials=$targetIdentity['credentials'];$targetKey=$targetIdentity['key'];
        $operation=bin2hex(random_bytes(16));
        $expiry=time()+86400*$days;
        $bytes=$volume*1073741824;
        $fingerprint=hash('sha256',json_encode([
            $kind,$sourceRef,$actor,$ordinal,$count,$planId,$serverId,
            $group,$endpoint,$remark,$expiry,$bytes
        ],JSON_THROW_ON_ERROR));

        $existing=$db->prepare("SELECT ordinal,state,reserved_count,
            plan_id,target_server_id
            FROM service_effects WHERE source_kind=? AND source_ref=?
            ORDER BY ordinal FOR UPDATE");
        if (!$existing) throw new RuntimeException('nonpaid_effect_existing_prepare');
        $existing->bind_param('ss',$kind,$sourceRef);
        if (!$existing->execute()) throw new RuntimeException('nonpaid_effect_existing_read');
        $rows=$existing->get_result()->fetch_all(MYSQLI_ASSOC);$existing->close();
        if (count($rows)!==$ordinal-1)
            throw new RuntimeException('nonpaid_effect_already_reserved_or_gap');
        $reserved=0;
        if ($ordinal===1) {
            $reserve=$db->prepare('UPDATE server_info SET ucount=ucount-?
                WHERE id=? AND active=1 AND state=1 AND ucount>=?');
            if (!$reserve) throw new RuntimeException('nonpaid_effect_capacity_prepare');
            $reserve->bind_param('iii',$count,$serverId,$count);
            if (!$reserve->execute() || $reserve->affected_rows!==1)
                throw new RuntimeException('nonpaid_effect_capacity_insufficient');
            $reserve->close();
            $reserved=$count;
            if ($kind==='free_trial' && str_starts_with($sourceRef,'trial:')) {
                $trial=$db->prepare("UPDATE users SET freetrial='reserved'
                    WHERE userid=? AND (freetrial IS NULL OR freetrial NOT IN ('reserved','used'))");
                if (!$trial) throw new RuntimeException('nonpaid_trial_prepare');
                $trial->bind_param('i',$actor);
                if (!$trial->execute() || $trial->affected_rows!==1)
                    throw new RuntimeException('nonpaid_trial_reserve_unconfirmed');
                $trial->close();
            }
        } else {
            if ((int)$rows[0]['ordinal']!==1 ||
                (int)$rows[0]['reserved_count']!==$count ||
                (int)$rows[0]['plan_id']!==$planId ||
                (int)$rows[0]['target_server_id']!==$serverId ||
                $rows[$ordinal-2]['state']!=='verified' &&
                $rows[$ordinal-2]['state']!=='local_recorded')
                throw new RuntimeException('nonpaid_effect_capacity_origin_invalid');
        }
        $stmt=$db->prepare("INSERT INTO service_effects
            (source_kind,source_ref,actor_userid,ordinal,operation_id,
             effect_kind,target_server_id,target_group_id,target_key,
             endpoint_sha256,plan_id,remote_username,requested_expire,
             requested_bytes,request_sha256,reserved_count,state)
            VALUES (?,?,?,?,?,'create',?,?,?,?,?,?,?,?,?,?,'dispatching')");
        if (!$stmt) throw new RuntimeException('nonpaid_effect_insert_prepare');
        $types='ssiisiissisiisi';
        $stmt->bind_param($types,$kind,$sourceRef,$actor,$ordinal,$operation,
            $serverId,$group,$targetKey,$endpoint,$planId,$remark,
            $expiry,$bytes,$fingerprint,$reserved);
        if (!$stmt->execute() || $stmt->affected_rows!==1)
            throw new RuntimeException('nonpaid_effect_insert_unconfirmed');
        $effectId=(int)$stmt->insert_id;$stmt->close();
        if ($effectId<=0) throw new RuntimeException('nonpaid_effect_id_missing');
        achbotProviderReserveEffect($db,$effectId,$targetIdentity,$remark,$operation,$expiry,$bytes);
        if ($db->commit()!==true)
            throw new RuntimeException('nonpaid_effect_commit_unconfirmed');
    } catch (Throwable $e) {
        try {$db->rollback();} catch (Throwable $ignored) {}
        throw $e;
    }
    return achbotEffectDispatchReservedAccount(
        $db,$effectId,$serverId,$group,$endpoint,$remark,
        $volume,$days,$planId,$operation,$expiry,$bytes,$p,$credentials);
}

/** Bind the exact verified source/account to its local order in a short txn. */
function achbotLinkNonPaymentAccountEffect(mysqli $db, int $effectId, int $orderId): void {
    achbotProviderLinkEffect($db,$effectId,$orderId,false);
}

function achbotMarzbanLinkNonPaymentAccountEffect(mysqli $db, int $effectId, int $orderId): void {
    if ($effectId<=0 || $orderId<=0) throw new RuntimeException('nonpaid_effect_order_invalid');
    [$lockedServerId]=achbotEffectLockLinkRows($db,$effectId,false);
    $stmt=$db->prepare("UPDATE service_effects e
        INNER JOIN orders_list o ON o.id=?
        INNER JOIN server_info i ON i.id=e.target_server_id
        INNER JOIN server_config c ON c.id=i.id
        LEFT JOIN setting g ON g.id=i.panel_group_id
            AND g.type='ACHBOT_MARZBAN_PANEL_GROUP'
        SET e.order_id=o.id,e.state='local_recorded'
        WHERE e.id=? AND e.target_server_id=? AND e.pay_row_id IS NULL
          AND e.source_kind IN ('free_trial','admin_bulk')
          AND e.state='verified' AND e.order_id IS NULL
          AND o.userid=e.actor_userid AND o.fileid=e.plan_id
          AND o.server_id=e.target_server_id AND o.inbound_id=0
          AND o.remark=e.remote_username
          AND c.type='marzban' AND i.panel_group_id <=> e.target_group_id
          AND (i.panel_group_id IS NULL OR g.id IS NOT NULL)
          AND SHA2(TRIM(TRAILING '/' FROM c.panel_url),256)=e.endpoint_sha256");
    if (!$stmt) throw new RuntimeException('nonpaid_effect_link_prepare');
    $stmt->bind_param('iii',$orderId,$effectId,$lockedServerId);
    if (!$stmt->execute() || $stmt->affected_rows!==1)
        throw new RuntimeException('nonpaid_effect_link_unconfirmed');
    $stmt->close();
}

/**
 * Reserve a BUY_SUB's whole physical capacity atomically WITH its customer
 * wallet debit and processing_wallet claim, while the caller owns one
 * transaction. This is a source-local 007 "wallet_hold", NOT a remote CREATE.
 * Without this marker a post-debit competing buyer could consume the last
 * server slot and leave an avoidable paid/no-capacity limbo.
 */
function achbotEffectReserveWalletCapacityLocked(mysqli $db, array $pay): void {
    achbotEffectRequireUniqueLine($db);
    $payId=(int)($pay['id']??0);
    $planId=(int)($pay['plan_id']??0);
    $serverId=(int)($pay['server_id']??0);
    $count=max(1,(int)($pay['agent_count']??0));
    if ($payId<=0 || $planId<=0 || $serverId<=0 ||
        ($pay['type']??'')!=='BUY_SUB')
        throw new RuntimeException('wallet_hold_invalid_payment');
    $tx=$db->query('SELECT @@in_transaction AS tx');
    if (!$tx || (int)($tx->fetch_assoc()['tx']??0)!==1)
        throw new RuntimeException('wallet_hold_requires_transaction');

    achbotEffectLockTargetRows($db,$serverId,$planId,$payId);
    $stmt=$db->prepare("SELECT p.id,p.user_id,p.type,p.state,
        s.server_id,s.inbound_id,s.active,i.panel_group_id,
        c.type AS panel_type,c.panel_url,g.id AS valid_group
        FROM pays p
        JOIN server_plans s ON s.id=p.plan_id
        JOIN server_info i ON i.id=s.server_id
        JOIN server_config c ON c.id=i.id
        LEFT JOIN setting g ON g.id=i.panel_group_id
            AND g.type='ACHBOT_MARZBAN_PANEL_GROUP'
        WHERE p.id=? FOR UPDATE");
    if (!$stmt) throw new RuntimeException('wallet_hold_plan_prepare');
    $stmt->bind_param('i',$payId);
    if (!$stmt->execute()) throw new RuntimeException('wallet_hold_plan_read');
    $row=$stmt->get_result()->fetch_assoc();$stmt->close();
    if (!$row || (int)$row['user_id']!==(int)$pay['user_id'] ||
        $row['type']!=='BUY_SUB' || $row['state']!=='processing_wallet' ||
        (int)$row['server_id']!==$serverId ||
        (int)$row['inbound_id']!==0 || (int)$row['active']!==1 ||
        !achbotSupportsAccount($row['panel_type'],0) ||
        ($row['panel_type']==='marzban' && $row['panel_group_id']!==null && $row['valid_group']===null) ||
        (string)$row['panel_url']==='')
        throw new RuntimeException('wallet_hold_plan_changed');

    $check=$db->prepare("SELECT id FROM service_effects
        WHERE pay_row_id=? AND effect_kind='wallet_hold' AND ordinal=0
        FOR UPDATE");
    if (!$check) throw new RuntimeException('wallet_hold_existing_prepare');
    $check->bind_param('i',$payId);
    if (!$check->execute()) throw new RuntimeException('wallet_hold_existing_read');
    $exists=$check->get_result()->fetch_all(MYSQLI_ASSOC);$check->close();
    if ($exists) throw new RuntimeException('wallet_hold_duplicate_claim');

    $capacity=$db->prepare("UPDATE server_info SET ucount=ucount-?
        WHERE id=? AND active=1 AND state=1 AND ucount>=?");
    if (!$capacity) throw new RuntimeException('wallet_hold_capacity_prepare');
    $capacity->bind_param('iii',$count,$serverId,$count);
    if (!$capacity->execute() || $capacity->affected_rows!==1)
        throw new RuntimeException('wallet_hold_capacity_unconfirmed');
    $capacity->close();

    $holdTarget=achbotEffectTargetIdentity($db,$serverId,$planId,$payId,$row);
    $group=$holdTarget['group'];$key=$holdTarget['key'];$endpoint=$holdTarget['endpoint'];
    $operation=bin2hex(random_bytes(16));
    $remark='#wallet_hold_'.$payId;  // impossible Marzban username, not remote
    $expiry=0;$bytes=0;
    $fingerprint=hash('sha256',json_encode([
        'wallet_hold',$payId,$count,$planId,$serverId,$group,$endpoint
    ],JSON_THROW_ON_ERROR));
    $stmt=$db->prepare("INSERT INTO service_effects
        (pay_row_id,ordinal,operation_id,effect_kind,target_server_id,
         target_group_id,target_key,endpoint_sha256,plan_id,
         remote_username,requested_expire,requested_bytes,request_sha256,
         reserved_count,state)
        VALUES (?,0,?,'wallet_hold',?,?,?,?,?,?,?,?,?,?,'reserved')");
    if (!$stmt) throw new RuntimeException('wallet_hold_insert_prepare');
    $stmt->bind_param('isiissisiisi',$payId,$operation,$serverId,$group,
        $key,$endpoint,$planId,$remark,$expiry,$bytes,$fingerprint,$count);
    if (!$stmt->execute() || $stmt->affected_rows!==1)
        throw new RuntimeException('wallet_hold_insert_unconfirmed');
    $stmt->close();
}
