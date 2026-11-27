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
require_once __DIR__.'/PaymentLifecycle.php';
require_once __DIR__.'/../Panel/AccountNaming.php';
require_once __DIR__.'/../Subscription/ServerDisplaySettings.php';

/**
 * Operator diagnostic for an UNKNOWN native effect. Do not serialize arbitrary
 * provider or SQL exception messages: those can contain credentials or URLs.
 * First trusted cause wins; the outer no-replay wrapper is not the cause.
 */
function achbotEffectSafeFailure(Throwable $error): array {
    $allowed=[
        'effect_target_changed_pre_post','effect_remote_create_unconfirmed',
        'effect_remote_readback_unverified','effect_verification_commit_unknown',
        'effect_verification_unconfirmed','effect_credentials_changed_post',
        'effect_subscription_unverified','effect_get_unconfirmed',
        'effect_get_invalid','effect_create_response_unconfirmed',
        'effect_provider_changed_pre_dispatch','effect_target_snapshot_required',
        'effect_requires_operator_reconciliation','effect_order_id_unconfirmed',
        'effect_link_unconfirmed','pg_create_requires_review',
        'pg_create_readback_unconfirmed','pg_create_identity_unconfirmed',
        'pg_create_entitlement_unconfirmed','pg_create_status_invalid',
        'pg_create_unconfirmed','pg_timeout','pg_transport_unavailable',
        'pg_auth_denied','pg_permission_denied','pg_not_found','pg_conflict',
        'pg_rate_limited','pg_vendor_unavailable','pg_request_rejected',
        'pg_dispatch_target_changed','pg_verify_begin','pg_verify_commit_unknown',
        'pg_effect_verification_unconfirmed','pg_credentials_changed',
        'pg_create_invalid','pg_groups_required'
    ];
    $fallback='runtime_error';$depth=0;$source='runtime';
    for ($current=$error;$current!==null && $depth<8;$current=$current->getPrevious()) {
        $depth++;
        if ($current instanceof mysqli_sql_exception) {
            $state=$current->getSqlState();
            return ['reason'=>preg_match('/^[A-Z0-9]{5}$/D',$state)
                ?'database_error_'.$state:'database_error',
                'class'=>'mysqli_sql_exception','chain_depth'=>$depth];
        }
        $reason=$current->getMessage();
        if (in_array($reason,$allowed,true) &&
            !in_array($reason,['effect_requires_operator_reconciliation','pg_create_requires_review'],true)) {
            return ['reason'=>$reason,'class'=>'runtime_exception','chain_depth'=>$depth];
        }
        if ($current instanceof TypeError || $current instanceof ErrorException)
            {$fallback='php_error';$source='php_error';}
    }
    return ['reason'=>$fallback,'class'=>$source,'chain_depth'=>$depth];
}

function achbotEffectOperatorDiagnostic(
    Throwable $error,int $payId,int $effectId,int $ordinal,string $stage
): string {
    $stages=['preflight','remote_create','remote_readback','verify_local','unknown'];
    if (!in_array($stage,$stages,true)) $stage='unknown';
    $d=achbotEffectSafeFailure($error);
    return 'achbot_paid_effect_needs_reconciliation pay_id='.max(0,$payId).
        ' effect_id='.max(0,$effectId).' ordinal='.max(0,$ordinal).
        ' stage='.$stage.' reason='.$d['reason'].
        ' cause='.$d['class'].' depth='.$d['chain_depth'];
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
        'uq_effect_source_line'=>['source_kind','source_ref','ordinal'],
        'uq_effect_target_username'=>['target_key','active_username'],
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

function achbotAdminBulkAccountRemark(string $base,string $sourceRef,
    int $ordinal,int $count,string $provider,string $mode): string {
    if (preg_match('/^bulk:[1-9][0-9]*:[1-9][0-9]*$/D',$sourceRef)!==1)
        throw new InvalidArgumentException('bulk_account_source_invalid');
    return achbotStyledAccountRemark($base,$provider,$sourceRef,$ordinal,
        $count,$mode,false,true);
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
    require_once __DIR__.'/OperationalReports.php';
    achbotOperationalReportRequireReady($db);
    require_once __DIR__.'/ServiceDelivery.php';
    if (!achbotServiceDeliverySchemaReady($db)) throw new RuntimeException('delivery_schema_unavailable');
    achbotEffectRequireAutocommit($db);
    achbotEffectRequireUniqueLine($db);
    $serverId=(int)($p['server_id']??0);
    $planId=(int)($p['plan_id']??0);
    $remark=(string)($p['remark']??'');
    $volume=achbotWholeServiceUnits($p['volume']??null,achbotServiceVolumeMax());
    $days=achbotWholeServiceUnits($p['days']??null,achbotServiceDaysMax());
    if (!in_array($kind,['free_trial','admin_bulk'],true) ||
        !preg_match('/^(trial|trial_admin|bulk):[1-9][0-9]*(?::[1-9][0-9]*)?$/D',$sourceRef) ||
        $actor<=0 || $ordinal<1 || $count<1 || $ordinal>$count ||
        ($kind==='free_trial' && $count!==1) ||
        $serverId<=0 || $planId<=0 || !achbotSupportsProviderType($p['server_type']??'') ||
        $volume===null || $days===null ||
        !achbotEffectValidUsername($remark,(string)($p['server_type']??''))) {
        throw new RuntimeException('nonpaid_effect_invalid_request');
    }
    $primary=(int)($GLOBALS['admin']??0);
    $isPrimaryAdmin=$primary>0 && $actor===$primary;
    if ($kind==='free_trial') {
        $sourceValid=$isPrimaryAdmin
            ? preg_match('/\Atrial_admin:'.$actor.':[1-9][0-9]*\z/D',$sourceRef)===1
            : $sourceRef==='trial:'.$actor;
    } else $sourceValid=preg_match('/\Abulk:'.$actor.':[1-9][0-9]*\z/D',$sourceRef)===1;
    if (!$sourceValid) throw new RuntimeException('nonpaid_effect_source_binding_invalid');
    achbotRequireServerDisplay($db,$serverId,(string)$p['server_type']);
    if ($db->begin_transaction()!==true)
        throw new RuntimeException('nonpaid_effect_begin');
    try {
        $user=$db->prepare('SELECT freetrial,isAdmin,step FROM users FORCE INDEX (idx_users_userid_v1) WHERE userid=? FOR UPDATE');
        if (!$user) throw new RuntimeException('nonpaid_effect_user_prepare');
        $user->bind_param('i',$actor);
        if (!$user->execute()) throw new RuntimeException('nonpaid_effect_user_read');
        $owners=$user->get_result()->fetch_all(MYSQLI_ASSOC);$user->close();
        if (count($owners)!==1)
            throw new RuntimeException('nonpaid_effect_owner_ambiguous');
        require_once __DIR__.'/../Admin/Access.php';
        require_once __DIR__.'/PaymentLifecycle.php';
        if ($owners[0]['step']==='banned' ||
            ($kind==='admin_bulk' && !achbotAdminCanLocked($db,$actor,$primary,'operations')))
            throw new DomainException('nonpaid_effect_admin_required');
        if ($kind==='free_trial') {
            $state=achbotPayUxCurrentStatesLocked($db);
            if (($state['testAccount']??'off')!=='on')
                throw new DomainException('nonpaid_trial_disabled');
            if (!$isPrimaryAdmin && in_array($owners[0]['freetrial'],['reserved','used'],true))
                throw new DomainException('nonpaid_effect_trial_already_claimed');
        }

        achbotEffectLockTargetRows($db,$serverId,$planId);
        $plan=$db->prepare("SELECT s.server_id,s.price,s.days,s.volume,cat.active AS category_active,cat.parent AS category_parent,c.type,c.panel_url
            FROM server_plans s
            JOIN server_info i ON i.id=s.server_id
            JOIN server_categories cat ON cat.id=s.catid
            JOIN server_config c ON c.id=i.id
            WHERE s.id=? AND s.active=1 AND i.active=1 AND i.state=1
            LIMIT 1 FOR UPDATE");
        if (!$plan) throw new RuntimeException('nonpaid_effect_plan_prepare');
        $plan->bind_param('i',$planId);
        if (!$plan->execute()) throw new RuntimeException('nonpaid_effect_plan_read');
        $locked=$plan->get_result()->fetch_assoc();$plan->close();
        if (!$locked || (int)$locked['server_id']!==$serverId ||
            $locked['type']!==$p['server_type'] ||
            !achbotSupportsProviderType($locked['type']) ||
            (string)$locked['panel_url']==='')
            throw new RuntimeException('nonpaid_effect_target_changed');
        if ((int)$locked['category_active']!==1 || (int)$locked['category_parent']!==0 ||
            ($kind==='free_trial' && ((int)$locked['price']!==0 ||
                (float)$locked['days']!==(float)$days || (float)$locked['volume']!==(float)$volume)))
            throw new DomainException('nonpaid_offering_denied');
        $targetIdentity=achbotEffectTargetIdentity($db,$serverId,$planId,$locked);
        $group=$targetIdentity['group'];$endpoint=$targetIdentity['endpoint'];
        $credentials=$targetIdentity['credentials'];$targetKey=$targetIdentity['key'];
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
        $mode=(string)($p['naming_mode']??'manual');
        $remark=achbotAvailableAccountRemarkLocked($db,$targetKey,$targetIdentity['connection_id'],$remark,
            $kind==='free_trial' && $mode==='manual',$kind==='admin_bulk' && $mode==='manual');
        if (!achbotEffectValidUsername($remark,$targetIdentity['provider']))
            throw new RuntimeException('nonpaid_effect_invalid_request');
        $operation=bin2hex(random_bytes(16));
        $now=time();
        if ($days>achbotServiceDaysMax($now)) throw new RuntimeException('nonpaid_effect_invalid_request');
        $expiry=$now+86400*$days;
        $bytes=$volume*1073741824;
        $fingerprint=hash('sha256',json_encode([
            $kind,$sourceRef,$actor,$ordinal,$count,$planId,$serverId,
            $group,$endpoint,$remark,$expiry,$bytes
        ],JSON_THROW_ON_ERROR));

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
