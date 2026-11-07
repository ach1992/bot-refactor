<?php
/**
 * Issue #117: continue a previously claimed paid CREATE line by exact native
 * observation. A previously dispatched operation is NEVER sent a second POST.
 * Local verification can advance only from matching immutable provider proof.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }
require_once __DIR__.'/ServiceEffects.php';

/** Native CREATE replay gate shared by bank/API/manual fulfillment. */
function achbotContinuePaidAccount(mysqli $db,int $payId,int $ordinal,array $request,
                                   ?callable $exactAccountGet=null): array {
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
        // The original per-pay-line UNIQUE and committed whole-count hold
        // still enforce one CREATE. A missing line is not a replay.
        return achbotCreatePaidAccount($db,$payId,$ordinal,$request);
    }
    $server=(int)($request['server_id']??0);
    $volume=(int)($request['volume']??0);
    $days=(int)($request['days']??0);
    if ($e['pay_type']!=='BUY_SUB' ||
        !in_array((string)$e['pay_state'],['processing','paid','processing_wallet'],true) ||
        !in_array((string)$e['state'],['dispatching','verified'],true) ||
        $e['order_id']!==null || (int)$e['paid_user']!==(int)($request['user_id']??0) ||
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
    if ($e['panel_type']==='marzban')
        return achbotContinueMarzbanPaidAccount($db,$payId,$ordinal,$e,$exactAccountGet);
    if ($e['panel_type']==='pasarguard')
        return achbotContinuePasarGuardPaidAccount($db,$payId,$ordinal,$e);
    throw new RuntimeException('paid_resume_provider_unsupported');
}

function achbotContinueMarzbanPaidAccount(mysqli $db,int $payId,int $ordinal,array $e,
                                         ?callable $exactAccountGet=null): array {
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
        });
    if ($observed['status']!=='matching_observation' || !is_object($remote) ||
        !is_string($remote->subscription_url??null) ||
        !is_array($remote->links??null))
        throw new RuntimeException('paid_resume_native_not_proven');
    $sub=(string)$remote->subscription_url;
    $path=parse_url($sub,PHP_URL_PATH);
    // Only the native subscription path is accepted, not an arbitrary URL.
    if (!is_string($path) || preg_match('#^/sub/[a-zA-Z0-9_.~%/-]{1,512}$#D',$path)!==1)
        throw new RuntimeException('paid_resume_subscription_unverified');
    if ($e['state']==='dispatching') {
        $pin=achbotRecoveryTarget($db,$payId,$ordinal);
        if (!achbotRecoveryTargetValid($pin,$payId,$ordinal) ||
            (int)$pin['id']!==(int)$e['id'] ||
            (string)$pin['operation_id']!==(string)$e['operation_id'])
            throw new RuntimeException('paid_resume_target_changed');
        achbotVerifyMarzbanCreatedEffect($db,(int)$e['id'],(int)$e['target_server_id'],
            $e['target_group_id']===null?null:(int)$e['target_group_id'],
            (string)$e['endpoint_sha256'],(string)$e['operation_id'],[
                'panel_url'=>rtrim((string)$pin['panel_url'],'/'),
                'username'=>(string)$pin['panel_auth_user'],
                'password'=>(string)$pin['panel_auth_password'],
                'panel_group_id'=>$pin['panel_group_id']===null?null:(int)$pin['panel_group_id']]);
    }
    $reply=(object)['success'=>true,'verified'=>true,
        'username'=>(string)$e['remote_username'],
        'sub_link'=>$path,'vray_links'=>$remote->links];
    return [$reply,(string)$e['remote_username'],0,(int)$e['id']];
}

function achbotContinuePasarGuardPaidAccount(mysqli $db,int $payId,int $ordinal,array $e): array {
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
    if (!$account || $account['operation_marker']!=='achbot:v1:'.$e['operation_id'] ||
        $account['remote_username']!==$e['remote_username'] ||
        $account['order_id']!==null)
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
    if ($e['state']==='dispatching') {
        $begun=false;
        try {
            if (!$db->begin_transaction()) throw new RuntimeException('paid_resume_verify_begin');
            $begun=true;
            $q=$db->prepare('SELECT id,state,request_sha256,provider_state,provider_remote_user_id FROM service_effects WHERE id=? FOR UPDATE');
            $q->bind_param('i',$effectId);$q->execute();$locked=$q->get_result()->fetch_assoc();$q->close();
            if (!$locked || $locked['state']!=='dispatching' ||
                $locked['request_sha256']!==$e['request_sha256'] ||
                $locked['provider_state']!==$e['provider_state'] ||
                $locked['provider_remote_user_id']!==null)
                throw new RuntimeException('paid_resume_verify_stale');
            achbotProviderVerifyAccount($db,$effectId,$user);
            $q=$db->prepare("UPDATE service_effects SET state='verified',verified_at=NOW() WHERE id=? AND state='dispatching' AND order_id IS NULL");
            $q->bind_param('i',$effectId);$ok=$q->execute() && $q->affected_rows===1;$q->close();
            if (!$ok || !$db->commit()) throw new RuntimeException('paid_resume_verify_unknown');
            $begun=false;
        } catch (Throwable $error) {
            if ($begun) try {$db->rollback();} catch(Throwable $ignored) {}
            throw $error;
        }
    }
    $bound=achbotProviderAccountByEffect($db,$effectId);
    if (!$bound || $bound['state']!=='verified')
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
        elseif ($state==='unknown' && $actor===(int)($GLOBALS['admin']??0)) {
            // This explicit second press is the Owner's authority to resend
            // the SAME sealed account details, never to create another one.
            $row=achbotDeliveryInspectOne($db,$payId,$ordinal);
            $token=$row?achbotDeliveryChallenge($row,$actor):'';
            $out[$ordinal]=achbotDeliveryDispatch($db,$payId,$ordinal,$actor,$token);
        } else $out[$ordinal]=$state;
    }
    return $out;
}
