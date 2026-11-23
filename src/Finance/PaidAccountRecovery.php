<?php
/**
 * Continue the ORIGINAL paid CREATE line. Before an authorized re-dispatch,
 * observe matching Native proof; a rearmed, still-reserved original effect is
 * itself recoverable without allocating a second local effect or capacity.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }
require_once __DIR__.'/ServiceEffects.php';

/** Native CREATE replay gate shared by bank/API/manual fulfillment. */
function achbotContinuePaidAccount(mysqli $db,int $payId,int $ordinal,array $request,
                                   ?callable $exactAccountGet=null,
                                   int $actor=0,int $primary=0): array {
    achbotEffectRequireAutocommit($db);
    achbotEffectRequireUniqueLine($db);
    if ($payId<=0 || $ordinal<=0) throw new RuntimeException('paid_resume_invalid_line');
    $q=$db->prepare("SELECT e.*,p.type AS pay_type,p.state AS pay_state,
        p.user_id AS paid_user,p.plan_id AS paid_plan,p.server_id AS paid_server,
        p.agent_count,p.approved_by,c.type AS panel_type,
        s.server_id AS plan_server,s.inbound_id AS plan_inbound
        FROM service_effects e JOIN pays p ON p.id=e.pay_row_id
        JOIN server_plans s ON s.id=p.plan_id
        JOIN server_config c ON c.id=e.target_server_id
        WHERE e.pay_row_id=? AND e.effect_kind='create' AND e.ordinal=? LIMIT 1");
    $q->bind_param('ii',$payId,$ordinal);$q->execute();
    $e=$q->get_result()->fetch_assoc();$q->close();
    if ($e===null) {
        // Recovery callers that require an already committed native operation
        // must NEVER fall through to a fresh CREATE after concurrent deletion.
        // The original per-pay-line UNIQUE and committed whole-count hold
        // still enforce one CREATE. A missing line is not a replay.
        return achbotCreatePaidAccount($db,$payId,$ordinal,$request);
    }
    $server=(int)($request['server_id']??0);
    $volume=(int)($request['volume']??0);
    $days=(int)($request['days']??0);
    if ($e['pay_type']!=='BUY_SUB' ||
        !in_array((string)$e['pay_state'],['processing','paid','processing_wallet'],true) ||
        !in_array((string)$e['state'],$actor>0
            ?['reserved','dispatching','verified']:['dispatching','verified'],true) ||
        $e['order_id']!==null ||
        (int)$e['paid_user']!==(int)($request['user_id']??0) ||
        (int)$e['paid_plan']!==(int)($request['plan_id']??0) ||
        (int)$e['plan_id']!==(int)$e['paid_plan'] ||
        (int)$e['target_server_id']!==$server || (int)$e['paid_server']!==$server ||
        (int)$e['plan_server']!==$server || (int)$e['plan_inbound']!==0 ||
        (int)($request['inbound_id']??-1)!==0 ||
        (string)$e['panel_type']!==(string)($request['server_type']??'') ||
        $volume<=0 || $days<=0 || $days>36500 ||
        $volume>intdiv(PHP_INT_MAX,1073741824) ||
        (int)$e['requested_bytes']!==$volume*1073741824 ||
        (int)$e['requested_expire']<=0 ||
        (int)$e['agent_count']<0 || $ordinal>max(1,(int)$e['agent_count']))
        throw new RuntimeException('paid_resume_original_effect_changed');
    // A rearmed original remains read-first: the first CREATE may already
    // have succeeded despite its lost acknowledgement. A Native observation
    // can complete the SAME effect without another POST. Admission of this
    // staff-only reserved path requires its original payment worker lease.
    if ($e['state']==='reserved')
        achbotPaidRecoveryAssertReservedStaff($db,$payId,$ordinal,$e,$actor,$primary);
    return achbotProviderRecoverCreate($db,$payId,$ordinal,$e,$request,
        $actor,$primary,$exactAccountGet);
}

/** The reserved rearm state is never open to unattended or lease-free reads
 * that might subsequently commit a Native reconciliation. Verify current
 * staff authority and Archive under the same original payment/effect locks.
 */
function achbotPaidRecoveryAssertReservedStaff(mysqli $db,int $payId,int $ordinal,
    array $effect,int $actor,int $primary): void {
    require_once __DIR__.'/../Admin/Access.php';
    require_once __DIR__.'/GatewayClient.php';
    if ($actor<=0 || $primary<=0)
        throw new RuntimeException('paid_create_recovery_invalid');
    $name=achbotHostedWorkerLockName($db,$payId);
    $q=$db->prepare('SELECT IS_USED_LOCK(?) AS holder,CONNECTION_ID() AS me');
    $q->bind_param('s',$name);$q->execute();
    $lease=$q->get_result()->fetch_assoc();$q->close();
    if (!$lease || $lease['holder']===null ||
        (int)$lease['holder']!==(int)$lease['me'])
        throw new RuntimeException('paid_create_recovery_worker_busy');
    $begun=false;
    try {
        if (!$db->begin_transaction())
            throw new RuntimeException('paid_create_recovery_begin');
        $begun=true;
        achbotEffectAssertRecoveryPaymentLocked($db,$payId,$actor,$primary);
        $q=$db->prepare('SELECT pay_row_id,ordinal,state,operation_id,request_sha256,
            remote_username,order_id FROM service_effects WHERE id=? FOR UPDATE');
        $id=(int)$effect['id'];$q->bind_param('i',$id);$q->execute();
        $current=$q->get_result()->fetch_assoc();$q->close();
        if (!$current || (int)$current['pay_row_id']!==$payId ||
            (int)$current['ordinal']!==$ordinal ||
            $current['state']!=='reserved' ||
            $current['operation_id']!==$effect['operation_id'] ||
            $current['request_sha256']!==$effect['request_sha256'] ||
            $current['remote_username']!==$effect['remote_username'] ||
            $current['order_id']!==null)
            throw new RuntimeException('paid_create_recovery_effect_changed');
        if (!$db->commit()) throw new RuntimeException('paid_create_recovery_commit_unknown');
        $begun=false;
    } catch (Throwable $error) {
        if ($begun) try {$db->rollback();} catch (Throwable $ignored) {}
        throw $error;
    }
}

/**
 * Re-arm only a genuinely unfinished original CREATE, under the existing
 * payment session lease and payment/effect locks. Reuse its operation ID,
 * username, entitlement and capacity reservation. The staff decision is not
 * a second payment and never allocates a second local effect.
 */
function achbotRetryOriginalPaidCreate(mysqli $db,int $payId,int $ordinal,
    array $effect,array $request,int $actor,int $primary): array {
    require_once __DIR__.'/../Admin/Access.php';
    require_once __DIR__.'/GatewayClient.php';
    if ($actor<=0 || $primary<=0 || $payId<=0 || $ordinal<=0)
        throw new RuntimeException('paid_create_recovery_invalid');
    $name=achbotHostedWorkerLockName($db,$payId);
    $lock=$db->prepare('SELECT IS_USED_LOCK(?) AS holder,CONNECTION_ID() AS me');
    $lock->bind_param('s',$name);$lock->execute();
    $lease=$lock->get_result()->fetch_assoc();$lock->close();
    if (!$lease || $lease['holder']===null || (int)$lease['holder']!==(int)$lease['me'])
        throw new RuntimeException('paid_create_recovery_worker_busy');

    $started=false;
    try {
        if (!$db->begin_transaction()) throw new RuntimeException('paid_create_recovery_begin');
        $started=true;
        $q=$db->prepare('SELECT * FROM pays WHERE id=? FOR UPDATE');
        $q->bind_param('i',$payId);$q->execute();$pay=$q->get_result()->fetch_assoc();$q->close();
        if (!$pay || $pay['type']!=='BUY_SUB' ||
            !in_array($pay['state'],['processing','paid','processing_wallet'],true) ||
            !achbotAdminCanLocked($db,$actor,$primary,'payments'))
            throw new RuntimeException('paid_create_recovery_payment_changed');
        achbotEffectAssertNotArchivedLocked($db,$payId,$pay['state']==='processing');
        $q=$db->prepare('SELECT * FROM service_effects WHERE id=? FOR UPDATE');
        $id=(int)$effect['id'];$q->bind_param('i',$id);$q->execute();
        $row=$q->get_result()->fetch_assoc();$q->close();
        if (!$row || (int)$row['pay_row_id']!==$payId ||
            (int)$row['ordinal']!==$ordinal || $row['effect_kind']!=='create' ||
            !in_array((string)$row['state'],['dispatching','reserved'],true) ||
            (string)$row['state']!==(string)$effect['state'] ||
            $row['order_id']!==null ||
            $row['request_sha256']!==$effect['request_sha256'] ||
            $row['operation_id']!==$effect['operation_id'] ||
            $row['remote_username']!==$effect['remote_username'] ||
            (int)$row['requested_bytes']!==(int)$effect['requested_bytes'] ||
            (int)$row['requested_expire']!==(int)$effect['requested_expire'] ||
            (($row['provider_remote_user_id']??null)!==null) ||
            (($row['provider_reference_id']??null)!==null &&
             ($row['provider_state']??null)!==
                 ($row['state']==='reserved'?'reserved':'dispatching')))
            throw new RuntimeException('paid_create_recovery_effect_changed');
        $server=(int)$row['target_server_id'];$plan=(int)$row['plan_id'];
        if ($server!==(int)$pay['server_id'] || $plan!==(int)$pay['plan_id'] ||
            $server!==(int)($request['server_id']??0) ||
            $plan!==(int)($request['plan_id']??0))
            throw new RuntimeException('paid_create_recovery_target_changed');
        $q=$db->prepare('SELECT c.type,c.panel_url,c.username AS panel_username,
            c.password AS panel_password,i.panel_group_id
            FROM server_config c JOIN server_info i ON i.id=c.id WHERE c.id=?');
        $q->bind_param('i',$server);$q->execute();$panel=$q->get_result()->fetch_assoc();$q->close();
        if (!$panel || $panel['type']!==(string)$effect['panel_type'])
            throw new RuntimeException('paid_create_recovery_provider_changed');
        $target=achbotEffectTargetIdentity($db,$server,$plan,$payId,
            $panel+['panel_type'=>$panel['type']]);
        if ((string)$target['endpoint']!==(string)$row['endpoint_sha256'] ||
            (string)$target['key']!==(string)$row['target_key'] ||
            $target['group']!==($row['target_group_id']===null
                ?null:(int)$row['target_group_id']))
            throw new RuntimeException('paid_create_recovery_native_target_changed');
        // 007 is a shared paid effect; 012 Native storage is optional and
        // owned solely by its registered adapter, not by this coordinator.
        if ($row['state']==='dispatching') {
            $q=$db->prepare("UPDATE service_effects SET state='reserved'
                WHERE id=? AND state='dispatching' AND order_id IS NULL");
            $q->bind_param('i',$id);
            if (!$q->execute() || $q->affected_rows!==1)
                throw new RuntimeException('paid_create_recovery_claim_failed');
            $q->close();
        }
        // Even a previously reserved original must reprove its provider-
        // owned reservation, not bypass the adapter's account identity.
        achbotProviderRecoverRearm($db,$row+['panel_type'=>$effect['panel_type']]);
        if (!$db->commit()) throw new RuntimeException('paid_create_recovery_commit_unknown');
        $started=false;
    } catch (Throwable $error) {
        if ($started) try {$db->rollback();} catch(Throwable $ignored) {}
        throw $error;
    }
    error_log('achbot_operator_create_redrive pay_id='.$payId.' ordinal='.$ordinal.
        ' actor='.$actor.' effect_id='.(int)$effect['id']);
    $volume=intdiv((int)$effect['requested_bytes'],1073741824);
    $days=(int)($request['days']??0);
    return achbotEffectDispatchReservedAccount($db,(int)$effect['id'],
        (int)$effect['target_server_id'],
        $effect['target_group_id']===null?null:(int)$effect['target_group_id'],
        (string)$effect['endpoint_sha256'],(string)$effect['remote_username'],
        $volume,$days,(int)$effect['plan_id'],(string)$effect['operation_id'],
        (int)$effect['requested_expire'],(int)$effect['requested_bytes'],
        $request,$target['credentials']);
}

function achbotContinueMarzbanPaidAccount(mysqli $db,int $payId,int $ordinal,array $e,
                                         ?callable $exactAccountGet=null,
                                         bool $allowReserved=false,
                                         int $actor=0,int $primary=0): array {
    require_once __DIR__.'/RecoveryInspection.php';
    // This helper is also called directly from manual Approval.php, without
    // entering the operator read-only route that normally loads transport.
    require_once __DIR__.'/RecoveryTransport.php';
    // A strict pinned login+GET and second local read are performed before
    // any local state transition, with no SQL lock during network traffic.
    $remote=null;
    $reader=$exactAccountGet??'achbotRecoveryStrictAccountGet';
    $observed=achbotRecoveryInspectLine($db,$payId,$ordinal,
        static function(string $url,string $user,string $pass,string $name) use (&$remote,$reader): ?object {
            $remote=$reader($url,$user,$pass,$name);
            return $remote;
        },$allowReserved);
    // Existing foreign/mismatched Native identities must never authorize a
    // second POST, even when the original operation's ACK was lost.
    if ($observed['status']==='remote_mismatch')
        throw new RuntimeException('paid_resume_native_mismatch');
    if ($observed['status']!=='matching_observation' || !is_object($remote))
        throw new RuntimeException($observed['status']==='remote_unavailable'
            ?'paid_resume_native_not_proven':'paid_resume_target_changed');
    if (!is_string($remote->subscription_url??null) ||
        !is_array($remote->links??null))
        throw new RuntimeException('paid_resume_subscription_unverified');
    $sub=(string)$remote->subscription_url;
    $path=parse_url($sub,PHP_URL_PATH);
    // Only the native subscription path is accepted, not an arbitrary URL.
    if (!is_string($path) || preg_match('#^/sub/[a-zA-Z0-9_.~%/-]{1,512}$#D',$path)!==1)
        throw new RuntimeException('paid_resume_subscription_unverified');
    if (in_array($e['state'],['reserved','dispatching'],true)) {
        $pin=achbotRecoveryTarget($db,$payId,$ordinal);
        if (!achbotRecoveryTargetValid($pin,$payId,$ordinal,$allowReserved) ||
            (int)$pin['id']!==(int)$e['id'] ||
            (string)$pin['operation_id']!==(string)$e['operation_id'])
            throw new RuntimeException('paid_resume_target_changed');
        achbotVerifyMarzbanCreatedEffect($db,(int)$e['id'],(int)$e['target_server_id'],
            $e['target_group_id']===null?null:(int)$e['target_group_id'],
            (string)$e['endpoint_sha256'],(string)$e['operation_id'],[
                'panel_url'=>rtrim((string)$pin['panel_url'],'/'),
                'username'=>(string)$pin['panel_auth_user'],
                'password'=>(string)$pin['panel_auth_password'],
                'panel_group_id'=>$pin['panel_group_id']===null?null:(int)$pin['panel_group_id']],
            (string)$e['state'],$payId,$actor,$primary);
    }
    $reply=(object)['success'=>true,'verified'=>true,
        'username'=>(string)$e['remote_username'],
        'sub_link'=>$path,'vray_links'=>$remote->links];
    return [$reply,(string)$e['remote_username'],0,(int)$e['id']];
}

/** Read-only, exact-original PasarGuard CREATE observation. This must never
 * mark a provider effect verified or dispatch a Native CREATE. The original
 * payment / effect / provider-instance identity is checked before and after GET.
 */
function achbotObservePasarGuardPaidAccount(mysqli $db,int $payId,int $ordinal,
    array $e): array {
    $effectId=(int)$e['id'];
    $current=achbotPgEffect($db,$effectId);
    if (!$current || (int)$current['pay_row_id']!==$payId ||
        (int)$current['ordinal']!==$ordinal || $current['state']!==$e['state'] ||
        $current['request_sha256']!==$e['request_sha256'] ||
        $current['provider_state']!=$e['provider_state'] ||
        $current['provider_snapshot']===null)
        throw new RuntimeException('paid_resume_target_changed');
    $snapshot=$current['provider_snapshot'];
    $panel=achbotProviderPanelForSnapshot($db,$snapshot);
    $account=achbotProviderAccountByEffect($db,$effectId);
    $linked=$e['state']==='local_recorded';
    if (!$account || $account['operation_marker']!=='achbot:v1:'.$e['operation_id'] ||
        $account['remote_username']!==$e['remote_username'] ||
        $account['order_id']!==($linked?(int)$e['order_id']:null))
        throw new RuntimeException('paid_resume_account_identity_invalid');
    if ($account['remote_user_id']!==null) {
        $identity=achbotProviderAccountIdentity($db,$account);
        $read=achbotPgRead($panel,$identity);
    } else {
        // PasarGuard provides a read-only by-username lookup. This is an
        // observation of a prior POST, never permission for another POST.
        $name=rawurlencode((string)$e['remote_username']);
        $read=achbotPgUserResult(achbotPgRequest($panel,'GET','/api/user/by-username/'.$name));
    }
    if (($read['success']??false)!==true) throw new RuntimeException('paid_resume_native_unavailable');
    $user=$read['user'];
    if (($user['username']??null)!==$e['remote_username'] ||
        ($user['note']??null)!=='achbot:v1:'.$e['operation_id'] ||
        ($user['data_limit']??null)!==(int)$e['requested_bytes'] ||
        ($user['expire']??null)!==(int)$e['requested_expire'] ||
        ($user['group_ids']??null)!==($snapshot['group_ids']??null) ||
        ($user['status']??null)!=='active' || !achbotPgManaged($user))
        throw new RuntimeException('paid_resume_native_mismatch');
    $after=achbotPgEffect($db,$effectId);
    if (!$after || $after!==$current ||
        !achbotProviderPanelForSnapshot($db,$snapshot))
        throw new RuntimeException('paid_resume_target_changed');
    // A by-username lookup of an unverified CREATE still has to return a
    // real Native incarnation (id + created_at), not just a matching name.
    if (!is_int($user['id']??null) || $user['id']<=0 ||
        !is_string($user['created_at']??null) ||
        ($user['created_at']??'')!==achbotPgDate($user['created_at']))
        throw new RuntimeException('paid_resume_native_identity_invalid');
    return ['current'=>$current,'snapshot'=>$snapshot,'panel'=>$panel,
        'account'=>$account,'linked'=>$linked,'user'=>$user];
}

/** Reuse the strict read-only observation, then persist ONLY original evidence
 * after the caller has obtained any required worker lease/staff confirmation.
 */
function achbotContinuePasarGuardPaidAccount(mysqli $db,int $payId,int $ordinal,array $e,
    int $actor=0,int $primary=0,?array $expectedNativeIdentity=null): array {
    $observation=achbotObservePasarGuardPaidAccount($db,$payId,$ordinal,$e);
    $effectId=(int)$e['id'];
    $current=$observation['current'];$snapshot=$observation['snapshot'];
    $panel=$observation['panel'];$account=$observation['account'];
    $linked=$observation['linked'];$user=$observation['user'];
    if ($expectedNativeIdentity!==null &&
        ($expectedNativeIdentity!==['id'=>$user['id'],
            'created_at'=>$user['created_at']]))
        throw new RuntimeException('paid_resume_native_incarnation_changed');
    if (in_array($e['state'],['reserved','dispatching'],true)) {
        $begun=false;
        try {
            if (!$db->begin_transaction()) throw new RuntimeException('paid_resume_verify_begin');
            $begun=true;
            // Dispatching is not a bypass: a stale operator confirmation
            // must lock and recheck Archive before recording Native evidence
            // just as a never-dispatched reserved original does.
            achbotEffectAssertRecoveryPaymentLocked($db,$payId,$actor,$primary);
            $q=$db->prepare('SELECT id,state,request_sha256,provider_state,provider_remote_user_id FROM service_effects WHERE id=? FOR UPDATE');
            $q->bind_param('i',$effectId);$q->execute();$locked=$q->get_result()->fetch_assoc();$q->close();
            if (!$locked || $locked['state']!==$e['state'] ||
                $locked['request_sha256']!==$e['request_sha256'] ||
                $locked['provider_state']!==$e['provider_state'] ||
                $locked['provider_remote_user_id']!==null)
                throw new RuntimeException('paid_resume_verify_stale');
            achbotProviderVerifyAccount($db,$effectId,$user);
            $q=$db->prepare("UPDATE service_effects SET state='verified',verified_at=NOW()
                WHERE id=? AND state=? AND order_id IS NULL");
            $state=(string)$e['state'];$q->bind_param('is',$effectId,$state);
            $ok=$q->execute() && $q->affected_rows===1;$q->close();
            if (!$ok || !$db->commit()) throw new RuntimeException('paid_resume_verify_unknown');
            $begun=false;
        } catch (Throwable $error) {
            if ($begun) try {$db->rollback();} catch(Throwable $ignored) {}
            throw $error;
        }
    }
    $bound=achbotProviderAccountByEffect($db,$effectId);
    if (!$bound || $bound['state']!==($linked?'linked':'verified') ||
        $bound['order_id']!==($linked?(int)$e['order_id']:null))
        throw new RuntimeException('paid_resume_verify_missing');
    $links=achbotPgLinks($panel,achbotProviderAccountIdentity($db,$bound));
    if (($links['success']??false)!==true)
        throw new RuntimeException('paid_resume_links_unavailable');
    $user['links']=$links['links'];
    $user['subscription_url']=$links['subscription_url'];
    $user['provider']='pasarguard';$user['success']=true;$user['verified']=true;
    return [(object)$user,(string)$e['remote_username'],0,$effectId];
}

/** Already approved is a delivery-only retry: never settle or CREATE again. */
function achbotResumePaidPurchaseDelivery(mysqli $db,int $payId,int $actor): array {
    require_once __DIR__.'/DeliveryLedger.php';
    if (!achbotDeliverySchemaReady($db) || $payId<=0 || $actor<=0)
        throw new RuntimeException('paid_delivery_resume_unavailable');
    $q=$db->prepare('SELECT id,state,type,user_id,approved_by,agent_count FROM pays WHERE id=?');
    $q->bind_param('i',$payId);$q->execute();$pay=$q->get_result()->fetch_assoc();$q->close();
    if (!$pay || $pay['state']!=='approved' || $pay['type']!=='BUY_SUB' ||
        (int)$pay['approved_by']<=0 || (int)$pay['user_id']<=0 || (int)$pay['agent_count']<0)
        throw new RuntimeException('paid_delivery_resume_not_final');
    $count=max(1,(int)$pay['agent_count']);
    $q=$db->prepare('SELECT d.ordinal,d.state,e.state AS effect_state,e.order_id,e.remote_username
        FROM payment_delivery_intents d JOIN service_effects e ON e.id=d.effect_id
        WHERE d.pay_row_id=? ORDER BY d.ordinal');
    $q->bind_param('i',$payId);$q->execute();$lines=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
    if (count($lines)!==$count || $count<=0) throw new RuntimeException('paid_delivery_resume_manifest_incomplete');
    foreach ($lines as $i=>$line) {
        if ((int)$line['ordinal']!==$i+1 || $line['effect_state']!=='local_recorded' ||
            (int)$line['order_id']<=0)
            throw new RuntimeException('paid_delivery_resume_order_unverified');
    }
    $out=[];
    foreach ($lines as $line) {
        $ordinal=(int)$line['ordinal'];$state=(string)$line['state'];
        if ($state==='pending') $out[$ordinal]=achbotDeliveryDispatch($db,$payId,$ordinal);
        else {
            // An UNKNOWN Telegram acknowledgement is not a resend license.
            // Preserve the sealed delivery attempt for operator diagnosis.
            $out[$ordinal]=$state;
        }
    }
    return $out;
}
