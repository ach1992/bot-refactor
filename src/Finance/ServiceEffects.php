<?php
/**
 * Issue #26: one durable identity for each paid Marzban account creation.
 *
 * This file does not perform financial settlement, migration, retries or
 * operator reconciliation. A stored dispatch is never repeated blindly.
 * Call ONLY outside an open SQL transaction, after a durable payment claim.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

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
function achbotEffectRecordManualCapacityLocked(mysqli $db,array $pay,int $count): void {
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
        ($pay['state']??'')!=='have_sent')
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
        (int)$target['inbound_id']!==0 || $target['type']!=='marzban' ||
        ($target['panel_group_id']!==null && $target['valid_group']===null) ||
        !is_string($target['panel_url']) || $target['panel_url']==='')
        throw new RuntimeException('manual_hold_target_invalid');
    $group=$target['panel_group_id']===null?null:(int)$target['panel_group_id'];
    $endpoint=hash('sha256',rtrim($target['panel_url'],'/'));
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
        $planId<=0 || $inboundId!==0 || ($p['server_type']??'')!=='marzban' ||
        $volume<=0 || $days<=0 || $days>36500 ||
        $volume>intdiv(PHP_INT_MAX,1073741824) ||
        $remark==='' || strlen($remark)>100 || !preg_match('/^[a-zA-Z0-9_-]+$/D',$remark)) {
        throw new RuntimeException('effect_invalid_account_request');
    }

    // Keep the source claim and unique line-item reservation in a SHORT local
    // transaction. Its pay-row lock prevents a concurrent terminal payment
    // change between validation and reservation. Never hold it across HTTP.
    if ($db->begin_transaction()!==true) {
        throw new RuntimeException('effect_reserve_begin_failed');
    }
    try {
        // These values are owned by the committed payment claim, not callback text.
        $stmt=$db->prepare("SELECT p.user_id,p.plan_id,p.server_id AS paid_server,p.type,p.state,p.approved_by,
            p.agent_count AS raw_count,
            CASE WHEN p.agent_count>0 THEN p.agent_count ELSE 1 END AS requested_count,
            s.server_id AS plan_server,s.inbound_id AS plan_inbound,
            c.type AS panel_type,i.panel_group_id,c.panel_url,
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
            !in_array($claim['state'],['processing','paid'],true) ||
            (int)$claim['user_id']!==(int)($p['user_id']??0) ||
            (int)$claim['plan_id']!==$planId ||
            // The immutable paid-target anchor is independent of the mutable
            // plan->server mapping, which may change after checkout preflight.
            (int)$claim['paid_server']!==$serverId ||
            (int)$claim['paid_server']!==(int)$claim['plan_server'] ||
            (int)$claim['plan_server']!==$serverId ||
            (int)$claim['plan_inbound']!==$inboundId ||
            $claim['panel_type']!=='marzban' ||
            ($claim['panel_group_id']!==null && $claim['valid_group']===null) ||
            !is_string($claim['panel_url']) || $claim['panel_url']==='' ||
            // 0/null is the documented historical one-account default.
            // A negative persisted count is NOT an authorized single account.
            (int)($claim['raw_count']??0)<0 ||
            $ordinal>(int)$claim['requested_count']) {
            throw new RuntimeException('effect_payment_identity_mismatch');
        }

        $group=$claim['panel_group_id']===null?null:(int)$claim['panel_group_id'];
        // Pin a concrete provider endpoint in addition to the logical group.
        // A group alone does not prove which physical panel received POST.
        $endpoint=hash('sha256',rtrim($claim['panel_url'],'/'));
        $targetKey=$group===null?'server:'.$serverId:'group:'.$group;
        $expiry=time()+86400*$days;
        $bytes=$volume*1073741824;
        $operation=bin2hex(random_bytes(16));
        $fingerprint=hash('sha256',json_encode([
            $payId,$ordinal,$planId,$serverId,$group,$targetKey,$endpoint,$remark,$expiry,$bytes
        ],JSON_THROW_ON_ERROR));
        // The UNIQUE pay/line key is the database backstop. Serializing the
        // short reservation on pays additionally rejects an existing effect even
        // on an operator-misconfigured database missing that unique index.
        // Remote idempotency and inventory are distinct resources. The FIRST
        // paid 007 row must durably OWN all purchased slots, even if CREATE #2
        // later times out. Other paid ordinals prove the same hold without
        // taking another slot. Pays-row lock serializes these operations.
        $requestedCount=(int)$claim['requested_count'];
        if ($requestedCount<=0) throw new RuntimeException('effect_invalid_reserved_count');
        $first=$db->prepare("SELECT ordinal,reserved_count FROM service_effects
            WHERE pay_row_id=? AND effect_kind='create'
            ORDER BY ordinal LIMIT 1 FOR UPDATE");
        if (!$first) throw new RuntimeException('effect_capacity_owner_prepare_failed');
        $first->bind_param('i',$payId);
        if (!$first->execute()) throw new RuntimeException('effect_capacity_owner_read_failed');
        $prior=$first->get_result()->fetch_assoc();$first->close();
        $reserveSlots=0;
        $manual=$claim['state']==='processing' &&
            (int)($claim['approved_by']??0)>0;
        $holdStmt=$db->prepare("SELECT id,state,reserved_count,target_server_id,
            target_group_id,endpoint_sha256,plan_id
            FROM service_effects WHERE pay_row_id=?
                AND effect_kind='manual_hold' AND ordinal=0 FOR UPDATE");
        if (!$holdStmt) throw new RuntimeException('effect_manual_hold_prepare_failed');
        $holdStmt->bind_param('i',$payId);
        if (!$holdStmt->execute()) throw new RuntimeException('effect_manual_hold_read_failed');
        $holds=$holdStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $holdStmt->close();
        if (count($holds)>1 || ($manual !== (count($holds)===1)))
            throw new RuntimeException('effect_manual_hold_missing_or_unexpected');
        $hold=$holds[0]??null;
        if ($hold && ((int)$hold['reserved_count']!==$requestedCount ||
            (int)$hold['target_server_id']!==$serverId ||
            ($hold['target_group_id']===null?null:(int)$hold['target_group_id'])!==$group ||
            $hold['endpoint_sha256']!==$endpoint ||
            (int)$hold['plan_id']!==$planId))
            throw new RuntimeException('effect_manual_capacity_ownership_mismatch');
        if ($ordinal===1) {
            if ($prior!==null) throw new RuntimeException('effect_capacity_already_reserved');
            if ($manual) {
                if ($hold['state']!=='reserved')
                    throw new RuntimeException('effect_manual_hold_already_consumed');
                $consume=$db->prepare("UPDATE service_effects
                    SET state='consumed' WHERE id=? AND state='reserved'");
                if (!$consume) throw new RuntimeException('effect_manual_hold_consume_prepare');
                $holdId=(int)$hold['id'];
                $consume->bind_param('i',$holdId);
                if (!$consume->execute() || $consume->affected_rows!==1)
                    throw new RuntimeException('effect_manual_hold_consume_unconfirmed');
                $consume->close();
            } else {
                // Hosted paid claims take the SAME InnoDB server counter.
                // Full quantity and first 007 effect commit atomically.
                $capacity=$db->prepare("UPDATE server_info SET ucount=ucount-?
                    WHERE id=? AND active=1 AND state=1 AND ucount>=?");
                if (!$capacity) throw new RuntimeException('effect_capacity_prepare_failed');
                $capacity->bind_param('iii',$requestedCount,$serverId,$requestedCount);
                if (!$capacity->execute() || $capacity->affected_rows!==1)
                    throw new RuntimeException('effect_capacity_insufficient_or_unconfirmed');
                $capacity->close();
                $reserveSlots=$requestedCount;
            }
            // Manual claim owns the slot count in its manual_hold row.
            // Do not duplicate that same ownership in the create row.
        } else {
            if ($manual && $hold['state']!=='consumed')
                throw new RuntimeException('effect_manual_hold_not_consumed');
            $expectedCreateHold=$manual?0:$requestedCount;
            if (!$prior || (int)$prior['ordinal']!==1 ||
                (int)$prior['reserved_count']!==$expectedCreateHold)
                throw new RuntimeException('effect_capacity_origin_unverified');
            // Do not skip over an unconfirmed previous CREATE. A failed or
            // uncertain earlier ordinal is operator-review only.
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
        if ($db->commit()!==true) {
            throw new RuntimeException('effect_reserve_commit_unconfirmed');
        }
    } catch (Throwable $reservationError) {
        try { $db->rollback(); } catch (Throwable $ignored) {}
        // A failed COMMIT acknowledgement may still have persisted a
        // dispatching row. Do not send any remote request from this path.
        throw $reservationError;
    }

    // Anything after this point may have reached Marzban, even if the reply
    // is lost. No SQL rollback or failed GET may unlock another CREATE.
    try {
        // A route reconfigured since the reservation must not be used.
        $check=$db->prepare("SELECT i.panel_group_id,c.type,c.panel_url
            FROM server_info i JOIN server_config c ON c.id=i.id
            WHERE i.id=? LIMIT 1");
        if (!$check) throw new RuntimeException('effect_target_check_prepare_failed');
        $check->bind_param('i',$serverId);
        if (!$check->execute()) throw new RuntimeException('effect_target_check_failed');
        $target=$check->get_result()->fetch_assoc();$check->close();
        if (!$target || $target['type']!=='marzban' ||
            ($target['panel_group_id']===null?null:(int)$target['panel_group_id'])!==$group ||
            hash('sha256',rtrim((string)$target['panel_url'],'/'))!==$endpoint) {
            throw new RuntimeException('effect_target_changed_pre_post');
        }

        [$response,$actualRemark,$port]=createServerAccount(
            'marzban',$serverId,0,$planId,$remark,$volume,$days,
            (string)($p['uniqid']??''),(string)($p['protocol']??''),
            (int)($p['port']??0),(int)($p['expire_microdate']??0),
            (string)($p['net_type']??''),(int)($p['rahgozar']??0),
            (int)($p['limitip']??0),$operation,$expiry
        );
        if (!is_object($response) || ($response->success??false)!==true ||
            $actualRemark!==$remark) {
            throw new RuntimeException('effect_remote_create_unconfirmed');
        }
        $remote=getMarzbanUser($serverId,$remark,null,true);
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
        // The target might have been reconfigured during the remote call.
        $stmt=$db->prepare("UPDATE service_effects e
           INNER JOIN server_info s ON s.id=e.target_server_id
           INNER JOIN server_config c ON c.id=s.id
           SET e.state='verified',e.verified_at=NOW()
           WHERE e.id=? AND e.state='dispatching' AND e.order_id IS NULL
             AND c.type='marzban' AND s.panel_group_id <=> e.target_group_id
             AND SHA2(TRIM(TRAILING '/' FROM c.panel_url),256)=e.endpoint_sha256");
        if (!$stmt) throw new RuntimeException('effect_verification_prepare_failed');
        $stmt->bind_param('i',$effectId);
        if (!$stmt->execute() || $stmt->affected_rows!==1)
            throw new RuntimeException('effect_verification_unconfirmed');
        $stmt->close();
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
    if ($effectId<=0 || $orderId<=0) throw new RuntimeException('effect_order_id_unconfirmed');
    $stmt=$db->prepare("UPDATE service_effects e
      INNER JOIN pays p ON p.id=e.pay_row_id
      INNER JOIN orders_list o ON o.id=?
      INNER JOIN server_info i ON i.id=e.target_server_id
      INNER JOIN server_config c ON c.id=i.id
      LEFT JOIN setting g ON g.id=i.panel_group_id
        AND g.type='ACHBOT_MARZBAN_PANEL_GROUP'
      SET e.order_id=o.id,e.state='local_recorded'
      WHERE e.id=? AND e.state='verified' AND e.order_id IS NULL
        AND o.userid=p.user_id AND o.fileid=e.plan_id
        AND p.server_id=e.target_server_id
        AND o.server_id=e.target_server_id AND o.inbound_id=0
        AND o.remark=e.remote_username
        AND c.type='marzban'
        AND i.panel_group_id <=> e.target_group_id
        AND (i.panel_group_id IS NULL OR g.id IS NOT NULL)
        AND SHA2(TRIM(TRAILING '/' FROM c.panel_url),256)=e.endpoint_sha256");
    if (!$stmt) throw new RuntimeException('effect_link_prepare_failed');
    $stmt->bind_param('ii',$orderId,$effectId);
    if (!$stmt->execute() || $stmt->affected_rows!==1)
        throw new RuntimeException('effect_link_unconfirmed');
    $stmt->close();
}
