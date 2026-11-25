<?php
/** AChBot current provider dispatch. Native identities never use legacy UUID/inbound fields. */
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) { http_response_code(404); exit; }
require_once __DIR__.'/../Diagnostics/EventLog.php';
require_once __DIR__.'/ProviderImport.php';
require_once __DIR__.'/PasarGuardServiceTarget.php';
require_once __DIR__.'/PasarGuardServiceExecution.php';
require_once __DIR__.'/PasarGuardServiceEdit.php';
require_once __DIR__.'/PasarGuardServiceResolution.php';

function achbotProviderType(mysqli $db,int $serverId): string {
    $s=$db->prepare('SELECT type FROM server_config WHERE id=?');
    if (!$s) return '';
    $s->bind_param('i',$serverId);$ok=$s->execute();
    $r=$ok?$s->get_result()->fetch_assoc():null;$s->close();
    return (string)($r['type']??'');
}
/** state=1: sales + renewals, state=2: existing renewals only, state=0: neither.
 * A disabled server retains native read capability for existing order details.
 * A nonlocking observation must not invert the existing Native CREATE
 * server_config-first lock order. Final effect/capacity admission still
 * rechecks current state under its own durable transaction.
 */
function achbotServerCommerceAvailable(mysqli $db,int $serverId,bool $newPurchase,bool $lock=false): bool {
    if ($serverId<=0) return false;
    try {
        $s=$db->prepare('SELECT i.active,i.state,c.type FROM server_info i JOIN server_config c ON c.id=i.id
            WHERE i.id=?');
        $s->bind_param('i',$serverId);$s->execute();$row=$s->get_result()->fetch_assoc();$s->close();
        return $row && achbotSupportsProviderType($row['type']) && (int)$row['active']===1 &&
            ($newPurchase ? (int)$row['state']===1 : in_array((int)$row['state'],[1,2],true));
    } catch (Throwable $error) {return false;}
}

function achbotSupportsAccount($type,$inbound): bool {
    return achbotSupportsProviderType($type) && (int)$inbound===0;
}
function achbotSupportsPlan($db,$planId,$inbound=0,$serverId=null,bool $lock=false,bool $allowInactive=false): bool {
    if (!($db instanceof mysqli) || (int)$planId<=0 || (int)$inbound!==0) return false;
    try {
        $s=$db->prepare('SELECT c.type FROM server_plans p JOIN server_config c ON c.id=p.server_id WHERE p.id=?');
        $s->bind_param('i',$planId);$s->execute();$row=$s->get_result()->fetch_assoc();$s->close();
        $adapter=achbotProviderDefinition($row['type']??null);
        return $adapter!==null && ($adapter['plan'])($db,$planId,$inbound,$serverId,$lock,$allowInactive)===true;
    } catch (Throwable $error) {return false;}
}
function achbotPasarGuardSupportsPlan($db,$planId,$inbound=0,$serverId=null,bool $lock=false,bool $allowInactive=false): bool {
    if ((int)$planId<=0 || (int)$inbound!==0) return false;
    try {
        $s=$db->prepare('SELECT p.server_id,p.inbound_id,p.active,c.type FROM server_plans p JOIN server_config c ON c.id=p.server_id WHERE p.id=?'.($lock?' FOR UPDATE':''));
        $s->bind_param('i',$planId);$s->execute();$r=$s->get_result()->fetch_assoc();$s->close();
        if (!$r || (!$allowInactive && (int)$r['active']!==1) ||
            $r['type']!=='pasarguard' || (int)$r['inbound_id']!==0 ||
            ($serverId!==null && (int)$serverId!==0 && (int)$serverId!==(int)$r['server_id'])) return false;
        achbotPgPlanSnapshot($db,(int)$r['server_id'],(int)$planId,$lock);
        return true;
    } catch (Throwable $e) { return false; }
}
/**
 * Read-only order eligibility, deliberately independent of mutation admission.
 *
 * An unfinished Native effect is allowed to be OBSERVED by its exact
 * existing order identity (including during a mutating fence). It may not
 * authorize new payment, plan change, deletion, addon, gift or reset.
 * Never substitute a username-only lookup for PasarGuard's pinned account.
 */
function achbotSupportsOrderObservation(mysqli $db,int $orderId,?int $userId=null): bool {
    if ($orderId<=0 || ($userId!==null && $userId<=0)) return false;
    try {
        $s=$db->prepare('SELECT o.id,o.userid,o.status,o.inbound_id,o.server_id,c.type
            FROM orders_list o JOIN server_config c ON c.id=o.server_id
            WHERE o.id=? LIMIT 1');
        $s->bind_param('i',$orderId);$s->execute();
        $order=$s->get_result()->fetch_assoc();$s->close();
        if (!$order || (int)$order['status']!==1 ||
            !achbotSupportsAccount($order['type'],$order['inbound_id']) ||
            ($userId!==null && (int)$order['userid']!==$userId))
            return false;
        if ($order['type']==='pasarguard') {
            $ctx=achbotPgObserveOrderContext($db,$orderId);
            return (int)$ctx['order']['id']===$orderId &&
                (int)$ctx['order']['userid']===(int)$order['userid'] &&
                (int)$ctx['order']['server_id']===(int)$order['server_id'];
        }
        return false;
    } catch (Throwable $error) {return false;}
}

function achbotSupportsOrder($db,$orderId,$planId=null,$userId=null,bool $lock=false): bool {
    if (!($db instanceof mysqli) || (int)$orderId<=0) return false;
    try {
        $s=$db->prepare('SELECT c.type FROM orders_list o JOIN server_config c ON c.id=o.server_id WHERE o.id=?');
        $s->bind_param('i',$orderId);$s->execute();$row=$s->get_result()->fetch_assoc();$s->close();
        $adapter=achbotProviderDefinition($row['type']??null);
        return $adapter!==null && ($adapter['order'])($db,$orderId,$planId,$userId,$lock)===true;
    } catch (Throwable $error) {return false;}
}
function achbotPasarGuardSupportsOrder($db,$orderId,$planId=null,$userId=null,bool $lock=false): bool {
    try {
        $s=$db->prepare('SELECT o.*,c.type,p.server_id AS plan_server,p.inbound_id AS plan_inbound FROM orders_list o JOIN server_config c ON c.id=o.server_id LEFT JOIN server_plans p ON p.id=o.fileid WHERE o.id=?'.($lock?' FOR UPDATE':''));
        $s->bind_param('i',$orderId);$s->execute();$r=$s->get_result()->fetch_assoc();$s->close();
        if (!$r || $r['type']!=='pasarguard' || (int)$r['inbound_id']!==0 ||
            ($planId!==null && (int)$r['fileid']!==(int)$planId) ||
            ($userId!==null && (int)$r['userid']!==(int)$userId)) return false;
        achbotPgOrderContext($db,(int)$orderId,$lock);
        return (int)$r['fileid']===0 ? $planId===null :
            (int)$r['plan_inbound']===0 && achbotSameProviderPanel($db,(int)$r['server_id'],(int)$r['plan_server']);
    } catch (Throwable $e) { return false; }
}
function achbotSameProviderPanel(mysqli $db,int $a,int $b): bool {
    try {
        $type=achbotProviderType($db,$a);
        if ($type!==achbotProviderType($db,$b)) return false;
        $adapter=achbotProviderDefinition($type);
        return $adapter!==null && ($adapter['same_panel'])($db,$a,$b)===true;
    } catch (Throwable $error) {return false;}
}
function achbotPasarGuardSamePanelAdapter(mysqli $db,int $a,int $b): bool {
    try {
        $pa=achbotPgServerPanel($db,$a);$pb=achbotPgServerPanel($db,$b);
        return $pa['id']===$pb['id'] && $pa['instance_key']===$pb['instance_key'] && $pa['api_url']===$pb['api_url'];
    } catch (Throwable $error) {return false;}
}
function achbotProviderFailure(string $reason,bool $uncertain=true): object {
    return (object)['success'=>false,'uncertain'=>$uncertain,'msg'=>$reason];
}
function achbotProviderUser($serverId,$remark,bool $withLinks=true): object {
    global $connection;
    $adapter=achbotProviderDefinition(achbotProviderType($connection,(int)$serverId));
    if ($adapter===null) return achbotProviderFailure('unsupported_provider',false);
    return ($adapter['user'])($serverId,$remark,$withLinks);
}
function achbotPasarGuardUserAdapter($serverId,$remark,bool $withLinks=true): object {
    global $connection;
    try {
        // Rendering and independent subscription observations are GET-only.
        // A wallet/native edit may have a durable mutating fence after a
        // reset/PUT failure; it must block other edits, not hide the LIVE
        // account from its owner. Never authorize a mutation from this context.
        $ctx=achbotPgRemarkContext($connection,(int)$serverId,(string)$remark,
            false,true);
        $links=$withLinks?achbotPgLinks($ctx['panel'],$ctx['identity']):null;
        // A successful links proof already contains the final exact-identity read.
        $r=$withLinks && ($links['success']??false)===true?$links:
            achbotPgRead($ctx['panel'],$ctx['identity']);
        if (($r['success']??false)!==true) return achbotProviderFailure($r['reason']??'pg_read_unverified');
        $u=$r['user'];
        $u['provider']='pasarguard';$u['provider_account_id']=$ctx['account']['id'];
        if ($withLinks) $u['links']=($links['success']??false)===true?($links['links']??[]):[];
        return (object)$u;
    } catch (Throwable $e) { return achbotProviderFailure('pg_account_identity_unverified'); }
}
/** One request-local observation for a page. It never authorizes a financial or remote effect. */
function achbotProviderRenderPacket(mysqli $db,int $serverId,string $username): object {
    try {
        $type=achbotProviderType($db,$serverId);$adapter=achbotProviderDefinition($type);
        if ($adapter===null) throw new RuntimeException('render_provider_unavailable');
        $remote=($adapter['user'])($serverId,$username,true,true);
        require_once __DIR__.'/ServiceLifecycle.php';
        if (!is_object($remote) || !achbotServiceObservation($remote,$username)['verified'])
            throw new RuntimeException('render_observation_unverified');
        $remote->provider_render_proof=['provider'=>$type,'server_id'=>$serverId,'username'=>$username];
        return $remote;
    } catch (Throwable $error) {return achbotProviderFailure('render_observation_unverified',false);}
}
function achbotProviderRenderProofValid(object $remote,array $order,string $type): bool {
    require_once __DIR__.'/ServiceLifecycle.php';
    $username=(string)($order['remark']??'');
    return ($remote->provider_render_proof??null)===['provider'=>$type,
        'server_id'=>(int)($order['server_id']??0),'username'=>$username] &&
        achbotServiceObservation($remote,$username)['verified'];
}
function achbotProviderUserInfo($serverId,$remark): object {
    global $connection;
    $adapter=achbotProviderDefinition(achbotProviderType($connection,(int)$serverId));
    return $adapter===null?achbotProviderFailure('unsupported_provider',false):
        ($adapter['user_info'])($serverId,$remark);
}
function achbotPasarGuardUserInfoAdapter($serverId,$remark): object {
    return achbotPasarGuardUserAdapter($serverId,$remark);
}
/**
 * Private in-memory deletion target. Never persist or log these credentials.
 * Scheduled consumers capture it before reading the remote account; adapters
 * must refuse a changed target instead of silently reading a new endpoint.
 */
function achbotProviderDeletionTarget(mysqli $db,int $serverId,string $remark): array {
    $s=$db->prepare('SELECT type,panel_url,username,password FROM server_config WHERE id=?');
    $s->bind_param('i',$serverId);$s->execute();$row=$s->get_result()->fetch_assoc();$s->close();
    if (!$row || $serverId<=0 || $remark==='') throw new RuntimeException('delete_target_unverified');
    $adapter=achbotProviderDefinition($row['type']);
    if ($adapter===null) throw new RuntimeException('delete_target_unsupported');
    return ($adapter['deletion_target'])($db,$serverId,$remark,$row);
}
function achbotPasarGuardDeletionTargetAdapter(mysqli $db,int $serverId,string $remark,array $row): array {
    return achbotPgDeletionContextTarget(achbotPgRemarkContext($db,$serverId,$remark),$serverId,$remark);
}
function achbotPgDeletionContextTarget(array $ctx,int $serverId,string $remark): array {
    return ['provider'=>'pasarguard','server_id'=>$serverId,'remark'=>$remark,
        'panel_id'=>(int)$ctx['panel']['id'],'instance_key'=>$ctx['panel']['instance_key'],
        'endpoint_sha256'=>hash('sha256',rtrim($ctx['panel']['api_url'],'/')),
        'credentials_sha256'=>hash('sha256',$ctx['panel']['api_key']),
        'provider_account_id'=>(int)$ctx['account']['id'],'identity'=>$ctx['identity']];
}
function achbotProviderDeletionTargetMatches(mysqli $db,int $serverId,string $remark,array $expected): bool {
    try {
        return hash_equals(json_encode($expected,JSON_THROW_ON_ERROR),
            json_encode(achbotProviderDeletionTarget($db,$serverId,$remark),JSON_THROW_ON_ERROR));
    } catch (Throwable $error) {return false;}
}

/** Read-only admission before a caller commits its own local effect fence. */
function achbotProviderActionEligible(mysqli $db,int $serverId,string $remark,string $action,?array $expectedTarget=null): bool {
    try {
        $adapter=achbotProviderDefinition(achbotProviderType($db,$serverId));
        if ($adapter===null || $serverId<=0 || $remark==='' ||
            ($expectedTarget!==null && ($action!=='delete' ||
             !achbotProviderDeletionTargetMatches($db,$serverId,$remark,$expectedTarget)))) return false;
        if (($adapter['action_preflight'])($db,$serverId,$remark,$action,$expectedTarget)!==true) return false;
        return $expectedTarget===null || achbotProviderDeletionTargetMatches($db,$serverId,$remark,$expectedTarget);
    } catch (Throwable $error) {return false;}
}
/** Shared by outer read-only admission and the native adapter before its claim. */
function achbotPgActionPreflight(mysqli $db,int $serverId,string $remark,string $action,?array $expectedTarget=null): array {
    if (!in_array($action,['delete','revoke','reset','toggle'],true)) throw new RuntimeException('unsupported_action');
    $ctx=achbotPgRemarkContext($db,$serverId,$remark,true);
    $key=$action==='delete'?'delete:'.(int)$ctx['account']['id']:achbotPgOperationKey($action);
    achbotPgMutationAdmission($ctx['account'],$key);
    if ($expectedTarget!==null && $expectedTarget!==achbotPgDeletionContextTarget($ctx,$serverId,$remark))
        throw new RuntimeException('delete_target_changed');
    $preflight=achbotPgRead($ctx['panel'],$ctx['identity']);
    if (($preflight['success']??false)!==true) throw new RuntimeException('pg_action_read_unverified');
    if (!achbotPgManaged($preflight['user'])) throw new RuntimeException('pg_native_schedule_conflict');
    if ($action==='toggle' && !in_array($preflight['user']['status'],['active','disabled'],true))
        throw new RuntimeException('pg_status_restricted');
    return ['context'=>$ctx,'key'=>$key];
}
function achbotPasarGuardActionPreflightAdapter(mysqli $db,int $serverId,string $remark,string $action,?array $expectedTarget=null): bool {
    achbotPgActionPreflight($db,$serverId,$remark,$action,$expectedTarget);return true;
}

function achbotProviderAction($serverId,$remark,string $action,?array $expectedTarget=null): object {
    global $connection;
    $provider=achbotProviderType($connection,(int)$serverId);
    $adapter=achbotProviderDefinition($provider);
    if ($adapter===null) return achbotProviderFailure('unsupported_provider',false);
    if ($expectedTarget!==null && ($action!=='delete' ||
        !achbotProviderDeletionTargetMatches($connection,(int)$serverId,(string)$remark,$expectedTarget)))
        return achbotProviderFailure('delete_target_changed');
    $result=($adapter['action'])($serverId,$remark,$action,$expectedTarget);
    achbotDiagnosticEvent('achbot.native.account_action',[
        'server_id'=>(int)$serverId,'action'=>$action,
        'provider'=>$provider,
        'result'=>($result->success??false)?'verified':'review',
        'reason'=>(string)($result->msg??$result->reason??'none')],
        ($result->success??false)?'info':'warning');
    return $result;
}

/** Canonical JSON keeps native option order from becoming a false configuration change. */
function achbotProviderCanonicalValue($value) {
    if (is_object($value)) {$value=(array)$value;ksort($value,SORT_STRING);
        foreach ($value as $k=>$v) $value[$k]=achbotProviderCanonicalValue($v);return $value;}
    if (is_array($value)) {if (!array_is_list($value)) ksort($value,SORT_STRING);
        foreach ($value as $k=>$v) $value[$k]=achbotProviderCanonicalValue($v);}
    return $value;
}
function achbotPasarGuardActionAdapter($serverId,$remark,string $action,?array $expectedTarget=null): object {
    global $connection;
    try {
        $admission=achbotPgActionPreflight($connection,(int)$serverId,(string)$remark,$action,$expectedTarget);
        $ctx=$admission['context'];$key=$admission['key'];
        $panel=$ctx['panel'];$identity=$ctx['identity'];
        $intent=achbotPgBeginMutation($connection,$ctx,$key,$action,['phase'=>'claimed']);
        $read=achbotPgRead($panel,$identity);
        if (($read['success']??false)!==true) throw new RuntimeException('pg_action_read_unverified');
        $before=$read['user'];
        $expected=match($action) {
            'delete'=>['absent'=>true,'http'=>404],
            'reset'=>['used_traffic'=>0],
            'revoke'=>['proxy_identity_must_change'=>true,'before_proxy_sha256'=>hash('sha256',json_encode($before['proxy_settings'],JSON_THROW_ON_ERROR))],
            'toggle'=>['status'=>$before['status']==='active'?'disabled':'active']
        };
        if ($action==='toggle' && !in_array($before['status'],['active','disabled'],true)) throw new RuntimeException('pg_status_restricted');
        $intent=achbotPgPersistMutationIntent($connection,$ctx,$intent,[
            'before_status'=>$before['status'],'expected'=>$expected]);
        $r=match($action) {
            'delete'=>achbotPgDelete($panel,$identity),'revoke'=>achbotPgRevoke($panel,$identity),
            'reset'=>achbotPgReset($panel,$identity),'toggle'=>achbotPgModify($panel,$identity,$expected)
        };
        if (($r['success']??false)!==true) return achbotProviderFailure($r['reason']??'pg_action_unverified',(bool)($r['uncertain']??true));
        achbotPgFinishMutation($connection,$ctx,$intent,$r,$action==='delete'?'deleted':'linked');
        if ($action==='delete') return (object)['success'=>true,'verified'=>true];
        $user=achbotProviderUser($serverId,$remark);
        if (!isset($user->username)) return achbotProviderFailure('pg_action_delivery_unverified');
        return (object)array_merge((array)$user,['success'=>true,'verified'=>true]);
    } catch (Throwable $e) { return achbotProviderFailure('pg_action_requires_review'); }
}

/**
 * Atomically claim the original DELETE inside the caller's order-fence
 * transaction. The capacity policy belongs to this ORIGINAL operation, not
 * whichever owner/staff actor eventually confirms its finalization.
 */
function achbotPgClaimOriginalDelete(mysqli $db,array $order,array $target,
    bool $restoreCapacity): void {
    $orderId=(int)$order['id'];$serverId=(int)$order['server_id'];
    $remark=(string)$order['remark'];
    $ctx=achbotPgOrderContext($db,$orderId,true);
    if ((int)$order['status']!==1 ||
        achbotPgDeletionContextTarget($ctx,$serverId,$remark)!==$target)
        throw new RuntimeException('pg_delete_original_target_changed');
    // A second local owner of this physical Native account must never lose
    // their account when only one order was authorized for deletion.
    $effectId=(int)$ctx['account']['effect_id'];
    $peers=$db->prepare('SELECT id FROM orders_list WHERE provider_effect_id=? AND id<>? LIMIT 1 FOR UPDATE');
    $peers->bind_param('ii',$effectId,$orderId);
    $peers->execute();$other=$peers->get_result()->fetch_assoc();$peers->close();
    if ($other) throw new RuntimeException('pg_delete_shared_account_reference');
    // Prove the original capacity disposition before the irreversible
    // Native request, not only after the remote account has gone.
    if ($restoreCapacity && $ctx['account']['source_kind']==='service_operation') achbotOrderCapacityServerLocked($db,$ctx['order']);
    $key='delete:'.(int)$ctx['account']['id'];
    achbotPgBeginMutation($db,$ctx,$key,'delete',['phase'=>'claimed'],true,$restoreCapacity);
}

/**
 * Resume ONLY the originally claimed deletion, under the caller's physical
 * account lease. This includes crashes before dispatch and lost responses.
 * The original order fence and Native identity are reused; no new key or
 * account can be claimed here.
 */
function achbotPgResumeDelete(mysqli $db,int $orderId,int $serverId,string $remark,
    bool $requireEnded=false): object {
    try {
        if ($orderId<=0 || $serverId<=0 || $remark==='')
            throw new RuntimeException('pg_delete_recovery_invalid');
        $ctx=achbotPgOrderContext($db,$orderId,false,false,true);
        $order=$ctx['order'];$account=$ctx['account'];
        if ((int)$order['server_id']!==$serverId || $order['remark']!==$remark ||
            (int)$order['status']!==0 || $order['autoRenew']!=='off' ||
            !in_array($account['state'],['mutating','deleted'],true))
            throw new RuntimeException('pg_delete_recovery_order_changed');
        require_once __DIR__.'/ProviderRecovery.php';
        $key='delete:'.(int)$account['id'];
        $saved=json_decode((string)$account['mutation_snapshot'],true,64,JSON_THROW_ON_ERROR);
        // For new purchased accounts the deletion claim pins its own local
        // subject, not necessarily the originating BUY order. OrderContext
        // has independently verified the original reference or import proof.
        // Preserve the historical recovery contract for old purchase rows.
        if ($account['source_kind']==='service_operation') {
            if (($saved['delete_subject']??null)!==[
                'order_id'=>$orderId,'userid'=>(int)$order['userid'],'server_id'=>$serverId])
                throw new RuntimeException('pg_delete_recovery_origin_changed');
        } elseif (!achbotPgRecoveryOrderOriginMatches($db,$ctx,$orderId))
            throw new RuntimeException('pg_delete_recovery_origin_changed');
        // A committed claim has no top-level phase until the original
        // outbound intent is durably persisted; never infer dispatch from it.
        $phase=$saved['phase']??null;
        if ($phase===null && ($saved['changes']??null)===['phase'=>'claimed'])
            $phase='claimed';
        if (!is_array($saved) || ($saved['version']??null)!==1 ||
            ($saved['key']??null)!==$key || $account['mutation_key']!==$key ||
            ($saved['action']??null)!=='delete' || ($saved['identity']??null)!==$ctx['identity'] ||
            !in_array($phase,['claimed','dispatching'],true) ||
            ($phase==='claimed' &&
                ($saved['changes']??null)!==['phase'=>'claimed']) ||
            ($phase==='dispatching' &&
                ($saved['changes']['expected']??null)!==['absent'=>true,'http'=>404]) ||
            !in_array($key,$saved['consumed_keys']??[],true) ||
            ($account['state']==='deleted' && ($saved['result']??null)!=='verified'))
            throw new RuntimeException('pg_delete_recovery_intent_changed');
        // Another local reference must never be silently erased by this retry.
        $s=$db->prepare('SELECT id FROM orders_list WHERE provider_effect_id=? AND id<>? LIMIT 1');
        $effectId=(int)$account['effect_id'];$s->bind_param('ii',$effectId,$orderId);
        $s->execute();$peer=$s->get_result()->fetch_assoc();$s->close();
        if ($peer) throw new RuntimeException('pg_delete_recovery_shared_reference');
        // Version-1 Native journals already persist the original claim.
        // No schema migration or separate per-order state is necessary.
        // Old, unpinned claims are safe only when original order provenance
        // proves that NO capacity slot was ever reserved. If a slot might
        // exist, do not guess whether that prior DELETE came from Cron or UI.
        if (array_key_exists('delete_restore_capacity',$saved)) {
            if (!is_bool($saved['delete_restore_capacity']))
                throw new RuntimeException('pg_delete_recovery_capacity_policy_invalid');
            $restoreCapacity=$saved['delete_restore_capacity'];
        } else {
            if (achbotOrderRestoresCapacity($db,$order))
                throw new RuntimeException('pg_delete_recovery_capacity_policy_unknown');
            $restoreCapacity=false;
        }
        $read=achbotPgRead($ctx['panel'],$ctx['identity']);
        $absent=($read['success']??false)!==true &&
            ($read['http']??0)===404 && ($read['reason']??'')==='pg_not_found';
        if (!$absent) {
            if (($read['success']??false)!==true) {
                $reason=(string)($read['reason']??'');
                // A temporary GET failure is retryable; failed identity,
                // authorization and protocol validation are NOT retry advice.
                if (!in_array($reason,['pg_timeout','pg_transport_unavailable',
                    'pg_vendor_unavailable','pg_rate_limited'],true))
                    $reason='pg_delete_recovery_read_unverified';
                throw new RuntimeException($reason);
            }
            if ($account['state']==='deleted')
                throw new RuntimeException('pg_delete_recovery_remote_reappeared');
            if ($requireEnded) {
                // Cron's final verified Native read must still be ended.
                // The public provider-user adapter rejects mutating accounts,
                // so use this pinned by-ID GET instead of that adapter here.
                require_once __DIR__.'/ServiceLifecycle.php';
                $observation=achbotServiceObservation((object)$read['user'],$remark);
                if (!achbotServiceLifecycle($observation,time())['ended'])
                    throw new RuntimeException('pg_delete_cron_observation_changed');
            }
            // A locally claimed but never-dispatched original operation may
            // safely progress its SAME Native key to the outbound stage.
            if ($phase==='claimed') {
                if (!achbotPgManaged($read['user']))
                    throw new RuntimeException('pg_delete_recovery_native_unmanaged');
                $saved=achbotPgPersistMutationIntent($db,$ctx,$saved,[
                    'before_status'=>$read['user']['status'],
                    'expected'=>['absent'=>true,'http'=>404]]);
            }
            // The ORIGINAL identity is still present. GET/identity recheck and
            // a DELETE by pinned Native ID make an explicit retry safe.
            $result=achbotPgDelete($ctx['panel'],$ctx['identity']);
            if (($result['success']??false)!==true) {
                $reason=(string)($result['reason']??'');
                if (preg_match('/^pg_[a-z0-9_]{2,85}$/D',$reason)!==1)
                    $reason='pg_delete_recovery_remote_unverified';
                // An HTTP 204 followed by a verified 200 means the original
                // account remains: a later explicit retry may advance it.
                // A 401/403 or malformed read is NOT a recoverability proof.
                if ($reason==='pg_delete_absence_unconfirmed' &&
                    ($result['http']??0)!==200)
                    $reason='pg_delete_recovery_read_unverified';
                throw new RuntimeException($reason);
            }
        }
        if ($account['state']==='mutating') {
            try {achbotPgFinishMutation($db,$ctx,$saved,['success'=>true],'deleted');}
            catch (Throwable $error) {
                // Native is now independently confirmed absent. A failed SQL
                // terminal write can be resumed without a second Native DELETE.
                throw new RuntimeException('pg_delete_recovery_native_finality_deferred',0,$error);
            }
        }
        achbotDiagnosticEvent('achbot.native.delete_resumed',[
            'order_id'=>$orderId,'server_id'=>$serverId,
            'outcome'=>$absent?'verified_absent':'verified_repeated_delete'],'info');
        return (object)['success'=>true,'verified'=>true,'reconciled'=>true,
            'delete_restore_capacity'=>$restoreCapacity];
    } catch (Throwable $error) {
        $reason=(string)$error->getMessage();
        if (preg_match('/^pg_[a-z0-9_]{2,85}$/D',$reason)!==1)
            $reason='pg_delete_recovery_internal';
        achbotDiagnosticEvent('achbot.native.delete_recovery_deferred',[
            'order_id'=>$orderId,'server_id'=>$serverId,
            'reason'=>$reason,'class'=>get_class($error)],'warning');
        return achbotProviderFailure($reason);
    }
}

function achbotProviderDelete($serverId,$remark,?array $expectedTarget=null): object { return achbotProviderAction($serverId,$remark,'delete',$expectedTarget); }
function achbotProviderRevoke($serverId,$remark): object { return achbotProviderAction($serverId,$remark,'revoke'); }
function achbotProviderToggle($serverId,$remark): object { return achbotProviderAction($serverId,$remark,'toggle'); }

function achbotPgLoad(): void {
    require_once __DIR__.'/PasarGuard.php';
    require_once __DIR__.'/ProviderStorage.php';
}
function achbotPgServerPanel(mysqli $db,int $serverId,bool $lock=false): array {
    achbotPgLoad();
    $r=achbotProviderRow($db,'SELECT c.provider_connection_id,c.type,c.panel_url
        FROM server_info i JOIN server_config c ON c.id=i.id WHERE i.id=?'.($lock?' FOR UPDATE':''),'i',[$serverId]);
    if (!$r || $r['type']!=='pasarguard') throw new RuntimeException('pg_server_identity_missing');
    $p=achbotProviderPanel($db,(int)$r['provider_connection_id'],$lock);
    if (rtrim($r['panel_url'],'/')!==rtrim($p['api_url'],'/')) throw new RuntimeException('pg_endpoint_changed');
    return $p;
}
function achbotPgPlanSnapshot(mysqli $db,int $serverId,int $planId,bool $lock=false): array {
    achbotPgLoad();achbotPgServerPanel($db,$serverId);
    return achbotProviderSnapshot($db,$serverId,$planId);
}
function achbotPgOrderContext(mysqli $db,int $orderId,bool $lock=false,
    bool $recoverDispatchedPlan=false,bool $recoverDeletion=false): array {
    achbotPgLoad();
    $s=$db->prepare('SELECT o.*,c.type AS provider_type FROM orders_list o JOIN server_config c ON c.id=o.server_id WHERE o.id=?'.($lock?' FOR UPDATE':''));
    $s->bind_param('i',$orderId);$s->execute();$o=$s->get_result()->fetch_assoc();$s->close();
    if (!$o || $o['provider_type']!=='pasarguard') throw new RuntimeException('pg_order_identity_changed');
    $a=achbotProviderAccountByOrder($db,$orderId,$lock);
    // Only an explicit administrator GET-only plan recovery may inspect the
    // existing, durably fenced 'mutating' provider identity. All regular
    // callers still reject it and may not begin another Native effect.
    $allowed=$recoverDeletion?['mutating','deleted']:
        ($recoverDispatchedPlan?['verified','linked','mutating']:['verified','linked']);
    if (!$a || !in_array($a['state'],$allowed,true) || (int)$a['remote_user_id']<=0 || empty($a['remote_created_at'])) throw new RuntimeException('pg_account_not_verified');
    if ($o['remark']!==$a['remote_username']) throw new RuntimeException('pg_order_identity_changed');
    $panel=achbotPgServerPanel($db,(int)$o['server_id']);
    if ((int)$panel['id']!==(int)$a['panel_id'] || $panel['instance_key']!==$a['instance_key'] || hash('sha256',rtrim($panel['api_url'],'/'))!==$a['endpoint_sha256']) throw new RuntimeException('pg_account_panel_changed');
    return ['order_id'=>$orderId,'order'=>$o,'order_server_id'=>(int)$o['server_id'],'account'=>$a,'panel'=>$panel,'identity'=>achbotProviderAccountIdentity($db,$a,$lock)];
}
/** Exact pinned account identity for GET-only pages/links, even if an
 * unfinished operation holds a Native mutation fence. This function MUST
 * NOT be used as admission for an effect or a financial state transition.
 */
function achbotPgObserveOrderContext(mysqli $db,int $orderId): array {
    $ctx=achbotPgOrderContext($db,$orderId,false,true);
    if ((int)$ctx['order']['status']!==1)
        throw new RuntimeException('pg_observation_inactive_order');
    return $ctx;
}
function achbotPgRemarkContext(mysqli $db,int $serverId,string $remark,
    bool $lock=false,bool $observationOnly=false): array {
    // Only the provider read adapter can explicitly request an observation
    // of a fenced mutating account. Never expose this option to the
    // mutation/dispatch/deletion callers or to a FOR UPDATE lock path.
    if ($observationOnly && $lock)
        throw new RuntimeException('pg_live_observation_must_be_readonly');
    // Only active local references are usable. A retired order may still
    // carry the same username from an earlier, deleted Native incarnation.
    // Multiple active references must resolve to one exact physical identity.
    $s=$db->prepare('SELECT id FROM orders_list WHERE server_id=? AND status=1 AND BINARY remark=BINARY ? ORDER BY id'.($lock?' LOCK IN SHARE MODE':''));
    $s->bind_param('is',$serverId,$remark);$s->execute();$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
    if (!$rows) throw new RuntimeException('pg_local_account_ambiguous');
    $context=null;
    foreach ($rows as $row) {
        $candidate=$observationOnly
            ?achbotPgObserveOrderContext($db,(int)$row['id'])
            :achbotPgOrderContext($db,(int)$row['id'],$lock);
        if ($context!==null && ($candidate['account']['id']!==$context['account']['id'] ||
            $candidate['identity']!==$context['identity'])) throw new RuntimeException('pg_local_account_ambiguous');
        $context=$candidate;
    }
    return $context;
}
function achbotPgPinPayment(mysqli $db,int $payId): void {
    $s=$db->prepare('SELECT * FROM pays WHERE id=?');$s->bind_param('i',$payId);$s->execute();$p=$s->get_result()->fetch_assoc();$s->close();
    if (!$p || achbotProviderType($db,(int)$p['server_id'])!=='pasarguard') return;
    achbotPgLoad();
    if ($p['type']==='BUY_SUB') {
        $snapshot=achbotProviderSnapshot($db,(int)$p['server_id'],(int)$p['plan_id']);
    } else {
        $oid=(int)($p['order_id']??0);
        if ($oid<=0 && preg_match('/^INCREASE_(?:DAY|VOLUME)_([1-9][0-9]*)_/',(string)$p['type'],$m)) $oid=(int)$m[1];
        $ctx=achbotPgOrderContext($db,$oid);
        $entitlement=json_decode($ctx['account']['entitlement_snapshot'],true,64,JSON_THROW_ON_ERROR);
        $groups=$entitlement['group_ids']??null;
        $mutation=json_decode((string)($ctx['account']['mutation_snapshot']??''),true);
        if (($mutation['result']??'')==='verified') $groups=$mutation['current_group_ids']??$groups;
        $snapshot=['provider'=>'pasarguard','panel_id'=>(int)$ctx['panel']['id'],
            'instance_key'=>$ctx['panel']['instance_key'],'endpoint_sha256'=>$ctx['account']['endpoint_sha256'],
            'group_ids'=>$groups,'group_policy'=>'preserve_current','provider_account_id'=>(int)$ctx['account']['id'],
            'remote_user_id'=>(int)$ctx['account']['remote_user_id'],
            'remote_created_at'=>$ctx['account']['remote_created_at'],'operation_marker'=>$ctx['account']['operation_marker']];
        $snapshot=array_merge($snapshot,$ctx['identity']);
    }
    $json=json_encode($snapshot,JSON_THROW_ON_ERROR);
    $s=$db->prepare('UPDATE pays SET provider_snapshot=? WHERE id=? AND provider_snapshot IS NULL');
    $s->bind_param('si',$json,$payId);
    if (!$s->execute() || $s->affected_rows!==1) throw new RuntimeException('pg_quote_pin_unconfirmed');$s->close();
}
function achbotEffectTargetIdentity(mysqli $db,int $serverId,?int $planId,array $row): array {
    $type=$row['panel_type']??$row['type']??null;
    $adapter=achbotProviderDefinition($type);
    if ($adapter===null) throw new RuntimeException('unsupported_effect_provider');
    $target=($adapter['target'])($db,$serverId,$planId,$row);
    if (!is_array($target) || !array_key_exists('group',$target) ||
        ($target['group']!==null && (!is_int($target['group']) || $target['group']<=0)) ||
        !is_string($target['endpoint']??null) || !preg_match('/^[a-f0-9]{64}$/D',$target['endpoint']) ||
        !is_string($target['credentials']??null) || !preg_match('/^[a-f0-9]{64}$/D',$target['credentials']) ||
        !is_string($target['key']??null) || $target['key']==='')
        throw new RuntimeException('effect_adapter_identity_invalid');
    $target['provider']=$type;
    return $target;
}
function achbotPasarGuardEffectTargetAdapter(mysqli $db,int $serverId,?int $planId,array $row): array {
    $snap=achbotPgPlanSnapshot($db,$serverId,(int)$planId);
    $panel=achbotProviderPanelForSnapshot($db,$snap);
    return ['group'=>null,'endpoint'=>$snap['endpoint_sha256'],'credentials'=>hash('sha256',$panel['api_key']),'key'=>'pg:'.$snap['instance_key'],'snapshot'=>$snap];
}
function achbotPgReserveEffect(mysqli $db,int $effectId,array $target,string $remark,string $operation,int $expire,int $bytes): void {
    if (!isset($target['snapshot'])) return;
    if ($expire<=0 || $expire>2147483647) throw new RuntimeException('pg_create_local_expiry_overflow');
    $snapshot=$target['snapshot'];$json=json_encode($snapshot,JSON_THROW_ON_ERROR);
    $s=$db->prepare('UPDATE service_effects SET provider_snapshot=? WHERE id=? AND provider_snapshot IS NULL');$s->bind_param('si',$json,$effectId);
    if (!$s->execute() || $s->affected_rows!==1) throw new RuntimeException('pg_effect_snapshot_unconfirmed');$s->close();
    $reservation=achbotProviderReserveAccount($db,$effectId,$snapshot,$remark,'achbot:v1:'.$operation,['data_limit'=>$bytes,'expire'=>$expire,'group_ids'=>$snapshot['group_ids'],'status'=>'active']);
    if (($reservation['newly_reserved']??false)!==true) throw new RuntimeException('pg_account_already_reserved');
}

/** Read the immutable effect snapshot without requiring provider storage for old providers. */
function achbotPgEffect(mysqli $db,int $effectId): ?array {
    $s=$db->prepare('SELECT e.*,c.type AS panel_type FROM service_effects e JOIN server_config c ON c.id=e.target_server_id WHERE e.id=?');
    $s->bind_param('i',$effectId);$s->execute();$e=$s->get_result()->fetch_assoc();$s->close();
    if (!$e || $e['panel_type']!=='pasarguard') return null;
    achbotPgLoad();
    $s=$db->prepare('SELECT provider_snapshot FROM service_effects WHERE id=?');
    $s->bind_param('i',$effectId);$s->execute();$r=$s->get_result()->fetch_assoc();$s->close();
    $e['provider_snapshot']=json_decode((string)($r['provider_snapshot']??''),true,64,JSON_THROW_ON_ERROR);
    return $e;
}
function achbotPgDispatchReserved(mysqli $db,int $effectId,int $serverId,string $remark,
    string $operation,int $expiry,int $bytes,array $p,string $credentials): array {
    $e=achbotPgEffect($db,$effectId);
    if (!$e || $e['state']!=='dispatching' || $e['remote_username']!==$remark ||
        $e['operation_id']!==$operation || (int)$e['requested_expire']!==$expiry ||
        (int)$e['requested_bytes']!==$bytes) throw new RuntimeException('pg_dispatch_reservation_changed');
    $snap=$e['provider_snapshot'];$panel=achbotProviderPanelForSnapshot($db,$snap);
    $current=achbotPgServerPanel($db,$serverId);
    if ($current['id']!==$panel['id'] || $current['instance_key']!==$panel['instance_key'] ||
        !hash_equals($credentials,hash('sha256',$panel['api_key']))) throw new RuntimeException('pg_dispatch_target_changed');
    $a=achbotProviderAccountByEffect($db,$effectId);
    if (!$a || $a['state']!=='reserved' || $a['remote_user_id']!==null)
        throw new RuntimeException('pg_dispatch_already_started');
    // This single guarded, committed transition is the dispatch authority.
    $s=$db->prepare("UPDATE service_effects SET provider_state='dispatching',provider_evidence_updated_at=UTC_TIMESTAMP(),updated_at=updated_at WHERE provider_reference_id=? AND provider_state='reserved' AND provider_remote_user_id IS NULL");
    $aid=(int)$a['id'];$s->bind_param('i',$aid);
    if (!$s->execute() || $s->affected_rows!==1) throw new RuntimeException('pg_dispatch_claim_unconfirmed');$s->close();
    $r=achbotPgCreate($panel,['username'=>$remark,'data_limit'=>$bytes,'expire'=>$expiry,
        'group_ids'=>$snap['group_ids'],'operation_marker'=>'achbot:v1:'.$operation]);
    if (($r['success']??false)!==true) {
        // The adapter returns a bounded machine reason, not response text.
        // A failed POST response may still have created the native account.
        $reason=(string)($r['reason']??'');
        // The native adapter marks validation rejection as not dispatched;
        // an uncertain remote POST remains remote_create regardless of HTTP.
        $preflight=($r['uncertain']??null)===false &&
            in_array($reason,['pg_create_invalid','pg_groups_required'],true);
        $known=['pg_create_unconfirmed','pg_create_readback_unconfirmed',
            'pg_create_entitlement_unconfirmed','pg_create_identity_unconfirmed',
            'pg_create_invalid','pg_groups_required',
            'pg_timeout','pg_transport_unavailable','pg_auth_denied',
            'pg_permission_denied','pg_conflict','pg_request_rejected',
            'pg_rate_limited','pg_vendor_unavailable','pg_create_status_invalid'];
        if (!in_array($reason,$known,true)) $reason='pg_create_unconfirmed';
        if (function_exists('achbotEffectOperatorDiagnostic'))
            error_log(achbotEffectOperatorDiagnostic(
                new RuntimeException('pg_create_requires_review',0,new RuntimeException($reason)),
                (int)($e['pay_row_id']??0),$effectId,(int)($e['ordinal']??0),
                $preflight?'preflight':'remote_create'));
        throw new RuntimeException('pg_create_requires_review');
    }
    try {
        // A native account may already exist when beginning local verification
        // fails. Capture this boundary with the same immutable effect identity.
        if (!$db->begin_transaction()) throw new RuntimeException('pg_verify_begin');
        $after=achbotProviderPanelForSnapshot($db,$snap);
        if (!hash_equals($credentials,hash('sha256',$after['api_key']))) throw new RuntimeException('pg_credentials_changed');
        achbotProviderVerifyAccount($db,$effectId,$r['user']);
        $s=$db->prepare("UPDATE service_effects SET state='verified',verified_at=NOW() WHERE id=? AND state='dispatching' AND order_id IS NULL");
        $s->bind_param('i',$effectId);
        if (!$s->execute() || $s->affected_rows!==1) throw new RuntimeException('pg_effect_verification_unconfirmed');$s->close();
        if (!$db->commit()) throw new RuntimeException('pg_verify_commit_unknown');
    } catch (Throwable $error) {
        try{$db->rollback();}catch(Throwable $ignored){}
        if (function_exists('achbotEffectOperatorDiagnostic'))
            error_log(achbotEffectOperatorDiagnostic($error,
                (int)($e['pay_row_id']??0),$effectId,(int)($e['ordinal']??0),'verify_local'));
        throw $error;
    }
    $identity=achbotProviderAccountIdentity($db,achbotProviderAccountByEffect($db,$effectId));
    $links=achbotPgLinks($panel,$identity);
    $user=$r['user'];$user['links']=($links['success']??false)===true?($links['links']??[]):[];
    $user['provider']='pasarguard';$user['success']=true;$user['verified']=true;
    return [(object)$user,$remark,(int)($p['port']??0),$effectId];
}
function achbotPgLinkEffect(mysqli $db,int $effectId,int $orderId): bool {
    $e=achbotPgEffect($db,$effectId);
    if ($e===null) return false;
    if ($e['state']!=='verified' || $e['order_id']!==null) throw new RuntimeException('pg_effect_not_linkable');
    $s=$db->prepare('SELECT o.*,p.user_id AS paid_user FROM orders_list o LEFT JOIN pays p ON p.id=? WHERE o.id=? FOR UPDATE');
    $pay=(int)($e['pay_row_id']??0);$s->bind_param('ii',$pay,$orderId);$s->execute();$o=$s->get_result()->fetch_assoc();$s->close();
    $owner=$pay>0?(int)($o['paid_user']??0):(int)$e['actor_userid'];
    if (!$o || (int)$o['userid']!==$owner || (int)$o['fileid']!==(int)$e['plan_id'] ||
        (int)$o['server_id']!==(int)$e['target_server_id'] || (int)$o['inbound_id']!==0 ||
        (int)$o['expire_date']!==(int)$e['requested_expire'] ||
        $o['remark']!==$e['remote_username']) throw new RuntimeException('pg_effect_order_mismatch');
    achbotProviderPanelForSnapshot($db,$e['provider_snapshot']);
    $s=$db->prepare("UPDATE service_effects SET order_id=?,state='local_recorded' WHERE id=? AND state='verified' AND order_id IS NULL");
    $s->bind_param('ii',$orderId,$effectId);
    if (!$s->execute() || $s->affected_rows!==1) throw new RuntimeException('pg_effect_link_unconfirmed');$s->close();
    // Both the effect and its order identity are committed by the caller transaction.
    achbotProviderLinkAccount($db,$effectId,$orderId);
    return true;
}
function achbotPgValidMutationKey($key): bool {
    return is_string($key) && preg_match('/^[a-zA-Z0-9:_-]{1,100}$/D',$key)===1;
}
function achbotPgOperationKey(string $action,array $change=[]): string {
    if (achbotPgValidMutationKey($change['operation_key']??null)) return $change['operation_key'];
    $update=$GLOBALS['update']??null;
    if (is_object($update) && is_int($update->update_id??null) && $update->update_id>0)
        return 'telegram:'.$update->update_id.':'.$action;
    throw new RuntimeException('pg_mutation_source_missing');
}
/** Decode the existing replay authority without repairing malformed history. */
function achbotPgMutationHistory(array $account): array {
    if (($account['state']??null)!=='linked') throw new RuntimeException('pg_account_mutation_requires_review');
    if (!array_key_exists('mutation_key',$account) || !array_key_exists('mutation_snapshot',$account))
        throw new RuntimeException('pg_mutation_history_invalid');
    $lastKey=$account['mutation_key'];$snapshot=$account['mutation_snapshot'];$consumed=[];
    if ($lastKey!==null || $snapshot!==null) {
        if (!achbotPgValidMutationKey($lastKey) || !is_string($snapshot))
            throw new RuntimeException('pg_mutation_history_invalid');
        try {$decoded=json_decode($snapshot,false,64,JSON_THROW_ON_ERROR);}
        catch (Throwable $error) {throw new RuntimeException('pg_mutation_history_invalid');}
        if (!($decoded instanceof stdClass)) throw new RuntimeException('pg_mutation_history_invalid');
        $prior=(array)$decoded;
        if (($prior['version']??null)!==1 ||
            ($prior['key']??null)!==$lastKey || !is_array($prior['consumed_keys']??null) ||
            !array_is_list($prior['consumed_keys']) || count($prior['consumed_keys'])<1)
            throw new RuntimeException('pg_mutation_history_invalid');
        $seen=[];
        foreach ($prior['consumed_keys'] as $consumedKey) {
            if (!achbotPgValidMutationKey($consumedKey) || isset($seen[$consumedKey]))
                throw new RuntimeException('pg_mutation_history_invalid');
            $seen[$consumedKey]=true;
        }
        if (!isset($seen[$lastKey])) throw new RuntimeException('pg_mutation_history_invalid');
        $consumed=$prior['consumed_keys'];
    }
    return ['last_key'=>$lastKey,'consumed_keys'=>$consumed];
}
/** The same admission check runs before HTTP and again under the exclusive claim lock. */
function achbotPgMutationAdmission(array $account,string $key): array {
    if (!achbotPgValidMutationKey($key)) throw new RuntimeException('pg_mutation_source_invalid');
    $history=achbotPgMutationHistory($account);$consumed=$history['consumed_keys'];
    if ($history['last_key']===$key || in_array($key,$consumed,true))
        throw new RuntimeException('pg_account_mutation_requires_review');
    return $consumed;
}

/** One durable account fence spans reset+modify and survives lost responses. */
function achbotPgBeginMutation(mysqli $db,array $ctx,string $key,string $action,array $changes,
    bool $useExistingTransaction=false,?bool $deleteRestoreCapacity=null): array {
    if (!achbotPgValidMutationKey($key)) throw new RuntimeException('pg_mutation_source_invalid');
    if ($deleteRestoreCapacity!==null &&
        ($action!=='delete' || $changes!==['phase'=>'claimed'] || !$useExistingTransaction))
        throw new RuntimeException('pg_delete_capacity_claim_invalid');
    $tx=$db->query('SELECT @@in_transaction AS tx');
    $active=$tx?(int)($tx->fetch_assoc()['tx']??-1):-1;
    if ($active!==($useExistingTransaction?1:0))
        throw new RuntimeException($useExistingTransaction?
            'pg_mutation_transaction_missing':'pg_mutation_requires_autocommit');
    if (!$useExistingTransaction && !$db->begin_transaction())
        throw new RuntimeException('pg_mutation_begin');
    try {
        $id=(int)$ctx['account']['id'];
        $s=$db->prepare('SELECT provider_state AS state,provider_mutation_key AS mutation_key,provider_mutation_snapshot AS mutation_snapshot FROM service_effects WHERE provider_reference_id=? FOR UPDATE');
        $s->bind_param('i',$id);$s->execute();$a=$s->get_result()->fetch_assoc();$s->close();
        if (!$a) throw new RuntimeException('pg_account_mutation_requires_review');
        $consumed=achbotPgMutationAdmission($a,$key);
        $consumed[]=$key;
        $intent=['version'=>1,'key'=>$key,'consumed_keys'=>$consumed,'action'=>$action,'changes'=>$changes,'identity'=>$ctx['identity'],
            'requested_at'=>gmdate('c')];
        if ($deleteRestoreCapacity!==null) {
            $intent['delete_restore_capacity']=$deleteRestoreCapacity;
            if ($ctx['account']['source_kind']==='service_operation')
                $intent['delete_subject']=[
                    'order_id'=>(int)$ctx['order_id'],'userid'=>(int)$ctx['order']['userid'],
                    'server_id'=>(int)$ctx['order_server_id']];
        }
        $json=json_encode($intent,JSON_THROW_ON_ERROR);
        $s=$db->prepare("UPDATE service_effects SET provider_state='mutating',provider_mutation_key=?,provider_mutation_snapshot=?,provider_evidence_updated_at=UTC_TIMESTAMP(),updated_at=updated_at WHERE provider_reference_id=? AND provider_state='linked'");
        $s->bind_param('ssi',$key,$json,$id);
        if (!$s->execute() || $s->affected_rows!==1) throw new RuntimeException('pg_mutation_claim_unconfirmed');$s->close();
        if (!$useExistingTransaction && !$db->commit())
            throw new RuntimeException('pg_mutation_commit_unknown');
        return $intent;
    } catch (Throwable $error) {
        if (!$useExistingTransaction) {try{$db->rollback();}catch(Throwable $ignored){}}
        throw $error;
    }
}
function achbotPgPersistMutationIntent(mysqli $db,array $ctx,array $intent,array $changes): array {
    $intent['changes']=$changes;$intent['phase']='dispatching';
    $json=json_encode($intent,JSON_THROW_ON_ERROR);$id=(int)$ctx['account']['id'];$key=$intent['key'];
    $s=$db->prepare("UPDATE service_effects SET provider_mutation_snapshot=?,provider_evidence_updated_at=UTC_TIMESTAMP(),updated_at=updated_at WHERE provider_reference_id=? AND provider_state='mutating' AND provider_mutation_key=?");
    $s->bind_param('sis',$json,$id,$key);
    if (!$s->execute() || $s->affected_rows!==1) throw new RuntimeException('pg_mutation_intent_unconfirmed');$s->close();
    return $intent;
}
function achbotPgFinishMutation(mysqli $db,array $ctx,array $intent,array $result,string $state='linked'): void {
    if (($result['success']??false)!==true) throw new RuntimeException('pg_mutation_requires_review');
    $current=achbotPgServerPanel($db,(int)$ctx['order_server_id']);
    if ($current['id']!==$ctx['panel']['id'] || $current['instance_key']!==$ctx['panel']['instance_key'] ||
        $current['api_url']!==$ctx['panel']['api_url'] || !hash_equals($current['api_key'],$ctx['panel']['api_key']))
        throw new RuntimeException('pg_mutation_target_changed');
    $id=(int)$ctx['account']['id'];$key=$intent['key'];
    $intent['verified_at']=gmdate('c');$intent['result']='verified';
    if (isset($result['user']['group_ids'])) $intent['current_group_ids']=$result['user']['group_ids'];
    $json=json_encode($intent,JSON_THROW_ON_ERROR);
    $s=$db->prepare("UPDATE service_effects SET provider_state=?,provider_mutation_snapshot=?,provider_evidence_updated_at=UTC_TIMESTAMP(),updated_at=updated_at WHERE provider_reference_id=? AND provider_state='mutating' AND provider_mutation_key=?");
    $s->bind_param('ssis',$state,$json,$id,$key);
    if (!$s->execute() || $s->affected_rows!==1) throw new RuntimeException('pg_mutation_finish_unknown');$s->close();
}
/** Existing shared accounting stores whole days/GiB; never round a quote. */
function achbotWholeServiceUnits($raw,int $max,bool $allowZero=false): ?int {
    if (!(is_int($raw)||is_float($raw)||is_string($raw)) || !is_numeric($raw)) return null;
    $number=(float)$raw;
    if (!is_finite($number) || $number!==floor($number) ||
        $number<($allowZero?0:1) || $number>$max) return null;
    return (int)$number;
}
/** orders_list.expire_date is a signed INT Unix timestamp. A duration
 * is bounded by the actual anchor, not a separate purchase/renewal cap. */
function achbotServiceDaysMax(?int $anchor=null): int {
    $anchor=max(0,$anchor??time());
    return $anchor>=2147483647?0:intdiv(2147483647-$anchor,86400);
}
function achbotServiceVolumeMax(): int {return min(2147483647,intdiv(PHP_INT_MAX,1073741824));}
/** Catalog FLOAT columns must preserve the requested whole integer exactly. */
function achbotCatalogServiceUnits($raw,int $max): ?int {
    $units=achbotWholeServiceUnits($raw,$max);
    return $units!==null && unpack('G',pack('G',(float)$units))[1]===(float)$units?$units:null;
}
function achbotPinnedAddonUnits(array $pay): ?int {
    if (preg_match('/^INCREASE_(DAY|VOLUME)_([1-9][0-9]*)_([1-9][0-9]*)$/D',(string)($pay['type']??''),$m)!==1) return null;
    $field=$m[1]==='DAY'?'day':'volume';$other=$field==='day'?'volume':'day';
    if (achbotWholeServiceUnits($pay[$other]??null,2147483647,true)!==0) return null;
    return achbotWholeServiceUnits($pay[$field]??null,$field==='day'?achbotServiceDaysMax(0):achbotServiceVolumeMax());
}
/** Pure validation runs before an account claim or provider GET. */
function achbotPgEditInputUnits(array $change): array {
    $units=[];
    foreach (['days','plus_day','volume','plus_volume'] as $field) {
        if (!array_key_exists($field,$change)) continue;
        $days=in_array($field,['days','plus_day'],true);
        $value=achbotWholeServiceUnits($change[$field],$days?achbotServiceDaysMax(0):achbotServiceVolumeMax(),$field==='volume');
        if ($value===null) throw new RuntimeException('pg_edit_units_invalid');
        $units[$field]=$value;
    }
    if (!$units || isset($units['days'],$units['plus_day']) || isset($units['volume'],$units['plus_volume']))
        throw new RuntimeException('pg_edit_units_invalid');
    return $units;
}
/** Pure absolute entitlement calculation; provider Groups are never replaced on renewal. */
function achbotPgEditChanges(array $user,array $change,?int $clock=null): array {
    $now=$clock??time();$expire=$user['expire'];$bytes=$user['data_limit'];
    $units=achbotPgEditInputUnits($change);
    // A full positive-days replacement establishes a finite new expiry even
    // when the current native account has unlimited time. Extensions still
    // require an existing finite expiry; malformed/zero expiry is not NULL.
    if (($expire===null && !isset($units['days'])) ||
        ($expire!==null && (!is_int($expire) || $expire<=0)) ||
        !is_int($bytes) || $bytes<0)
        throw new RuntimeException('pg_entitlement_not_finite');
    $out=[];
    if (isset($change['days'])) $out['expire']=$now+86400*$units['days'];
    elseif (isset($change['plus_day'])) $out['expire']=max($expire,$now)+86400*$units['plus_day'];
    else $out['expire']=$expire;
    if ($out['expire']>2147483647) throw new RuntimeException('pg_edit_expire_overflow');
    if (isset($change['volume'])) $out['data_limit']=1073741824*$units['volume'];
    elseif (isset($change['plus_volume'])) {
        if ($bytes===0) throw new RuntimeException('pg_unlimited_volume_extension');
        $extra=1073741824*$units['plus_volume'];
        if ($bytes>PHP_INT_MAX-$extra) throw new RuntimeException('pg_edit_volume_overflow');
        $out['data_limit']=$bytes+$extra;
    } else $out['data_limit']=$bytes;
    if ($user['status']==='disabled') $out['status']='disabled';
    elseif (in_array($user['status'],['expired','limited'],true)) $out['status']='active';
    return $out;
}
function achbotPgServiceEdit(mysqli $db,int $serverId,array $change,?array $giftTarget=null): object {
    $referenceOrder=(int)($change['order_id']??0);
    $lease=null;$transaction=false;
    try {
        // This adapter is only the existing free gift workflow. All paid
        // BUY/RENEW/ADD operations use Services/Execution.php.
        if ($giftTarget===null || $referenceOrder<=0 ||
            array_diff(array_keys($change),['remark','order_id','plus_day','plus_volume','operation_key']) ||
            !achbotPgValidMutationKey($change['operation_key']??null))
            throw new RuntimeException('pg_gift_request_invalid');
        $requestUnits=achbotPgEditInputUnits($change);
        $ctx=achbotPgOrderContext($db,$referenceOrder);
        if ($ctx['order_server_id']!==$serverId || $ctx['order']['remark']!==$change['remark'] ||
            !achbotGiftTargetMatches($db,$serverId,$giftTarget))
            throw new RuntimeException('pg_gift_target_changed');
        require_once __DIR__.'/../Subscription/PhysicalService.php';
        require_once __DIR__.'/../Services/Ownership.php';
        $name=achbotPhysicalLockName($db,$ctx['order']);
        achbotPhysicalAcquireLock($db,$name);$lease=$name;
        $key=$change['operation_key'];
        achbotPgMutationAdmission($ctx['account'],$key);
        $preflight=achbotPgRead($ctx['panel'],$ctx['identity']);
        if (($preflight['success']??false)!==true || !achbotPgManaged($preflight['user']))
            throw new RuntimeException('pg_gift_preflight_unverified');
        if (!$db->begin_transaction()) throw new RuntimeException('pg_gift_begin');
        $transaction=true;
        $peers=achbotPhysicalActiveOrders($db,$ctx['order'],true);
        if (!achbotServiceSubjectWorkSettledLocked($db,$peers) ||
            achbotServicePlanChangePendingLocked($db,$lease))
            throw new RuntimeException('pg_gift_financial_work_pending');
        $current=achbotPgOrderContext($db,$referenceOrder,true);
        if ($current['identity']!==$ctx['identity'] ||
            $current['order_server_id']!==$serverId ||
            !achbotGiftTargetMatches($db,$serverId,$giftTarget))
            throw new RuntimeException('pg_gift_target_changed');
        $ctx=$current;
        $intent=achbotPgBeginMutation($db,$ctx,$key,'edit',
            ['phase'=>'claimed','requested_units'=>$requestUnits],true);
        if (!$db->commit()) throw new RuntimeException('pg_gift_claim_commit_unknown');
        $transaction=false;
        $read=achbotPgRead($ctx['panel'],$ctx['identity']);
        if (($read['success']??false)!==true) throw new RuntimeException('pg_edit_read_unverified');
        $absolute=achbotPgEditChanges($read['user'],$change);
        $intent=achbotPgPersistMutationIntent($db,$ctx,$intent,[
            'requested_units'=>$requestUnits,'order_expire_before'=>(int)$ctx['order']['expire_date'],
            'absolute'=>$absolute,'reset_usage'=>false,
            'before'=>array_intersect_key($read['user'],array_flip(['data_limit','expire','status','group_ids','used_traffic']))]);
        $r=achbotPgModify($ctx['panel'],$ctx['identity'],$absolute);
        if (($r['success']??false)!==true || ($r['user']['group_ids']??null)!==$read['user']['group_ids'])
            throw new RuntimeException('pg_edit_readback_requires_review');
        achbotPgFinishMutation($db,$ctx,$intent,$r);
        return (object)['success'=>true,'verified'=>true,
            'before_expire'=>$read['user']['expire'],'applied_expire'=>$r['user']['expire'],
            'applied_bytes'=>$r['user']['data_limit']];
    } catch (Throwable $error) {
        if ($transaction) {try {$db->rollback();} catch (Throwable $ignored) {}}
        $reason=(string)$error->getMessage();
        if (preg_match('/\A(?:pg|provider|gift)_[a-z0-9_]{2,90}\z/D',$reason)!==1)
            $reason='internal_exception';
        achbotDiagnosticEvent('achbot.native.gift_deferred',[
            'order_id'=>$referenceOrder,'server_id'=>$serverId,'reason'=>$reason],'warning');
        return achbotProviderFailure('pg_edit_requires_review');
    } finally {if ($lease!==null) achbotPhysicalReleaseLock($db,$lease);}
}
/** One explicit physical-group admission rule for both supported providers. */
function achbotProviderRelocationTargets(mysqli $db,int $sourceId): array {
    require_once __DIR__.'/../Subscription/PhysicalService.php';
    try {
        $source=achbotPanelGroup($db,$sourceId);
        $type=achbotProviderType($db,$sourceId);
        if ($source===null || !achbotSupportsAccount($type,0)) return [];
        $gid=(int)$source['id'];
        $s=$db->prepare('SELECT i.id,i.title FROM server_info i JOIN server_config c ON c.id=i.id
            WHERE i.panel_group_id=? AND i.id<>? AND c.type=?
              AND i.active=1 AND i.state=1 AND i.ucount>0 ORDER BY i.id');
        $s->bind_param('iis',$gid,$sourceId,$type);$s->execute();
        $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
        // PasarGuard physical account evidence is tied to the existing
        // provider_connection_id / instance. A manually assigned group does
        // not rebind that evidence to an unrelated connection.
        return array_values(array_filter($rows,static fn($row)=>
            achbotSameProviderPanel($db,$sourceId,(int)$row['id'])));
    } catch (Throwable $error) {return [];}
}

function achbotReassignLogicalServer(mysqli $db,int $orderId,int $targetId,int $actor,bool $adminAllowed=false): ?array {
    require_once __DIR__.'/../Subscription/PhysicalService.php';
    // A single transaction moves every ACTIVE reference to this exact
    // physical subscription. Native access does not change here: it changes
    // only after the admin selects a new destination-server plan.
    if (!$adminAllowed || min($orderId,$targetId,$actor)<=0) return null;
    require_once __DIR__.'/../Admin/Access.php';
    $started=false;$commitAttempted=false;$lease=null;
    try {
        $pre=$db->prepare('SELECT * FROM orders_list WHERE id=? AND status=1');
        $pre->bind_param('i',$orderId);$pre->execute();
        $initial=$pre->get_result()->fetch_assoc();$pre->close();
        if (!$initial) throw new RuntimeException('physical_transfer_unavailable');
        $lease=achbotPhysicalLockName($db,$initial);
        achbotPhysicalAcquireLock($db,$lease);
        if (!$db->begin_transaction()) throw new RuntimeException('physical_transfer_begin');
        $started=true;
        $s=$db->prepare('SELECT id,userid,isAdmin,step FROM users WHERE userid=? LIMIT 2 FOR UPDATE');
        $s->bind_param('i',$actor);$s->execute();$users=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
        if (count($users)!==1 || $users[0]['step']==='banned' ||
            !achbotAdminCanLocked($db,$actor,(int)($GLOBALS['admin']??0),'orders'))
            throw new RuntimeException('physical_transfer_denied');
        $s=$db->prepare('SELECT * FROM orders_list WHERE id=? AND status=1 FOR UPDATE');
        $s->bind_param('i',$orderId);$s->execute();$order=$s->get_result()->fetch_assoc();$s->close();
        if (!$order || (int)$order['server_id']===$targetId ||
            achbotPhysicalLockName($db,$order)!==$lease)
            throw new RuntimeException('physical_transfer_unavailable');
        $old=(int)$order['server_id'];
        $s=$db->prepare('SELECT id FROM server_config WHERE id IN (?,?) ORDER BY id FOR UPDATE');
        $s->bind_param('ii',$old,$targetId);$s->execute();$found=$s->get_result()->num_rows;$s->close();
        if ($found!==2 || !achbotSamePanelGroup($db,$old,$targetId) ||
            achbotProviderType($db,$old)!==achbotProviderType($db,$targetId) ||
            !achbotSameProviderPanel($db,$old,$targetId))
            throw new RuntimeException('physical_transfer_group_invalid');
        $eligible=achbotProviderRelocationTargets($db,$old);
        $target=null;foreach ($eligible as $candidate) if ((int)$candidate['id']===$targetId) $target=$candidate;
        if ($target===null) throw new RuntimeException('physical_transfer_target_unavailable');
        $all=achbotPhysicalActiveOrders($db,$order,true);
        // A plan PUT with unknown local/native completion cannot be bypassed
        // through a transfer. Ignore completed audit receipts only.
        achbotServiceRetirePreparedPlansLocked($db,$lease);
        if (achbotServicePlanChangePendingLocked($db,$lease))
            throw new RuntimeException('physical_transfer_pending_plan_review');
        // The local reassignment itself is the verified service-change commit.
        // Retire eligible original-service quotes in THIS SQL transaction,
        // never during a tentative preflight or outside the rollback boundary.
        achbotPhysicalNoPendingPayments($db,$all,true,true);
        foreach ($all as $peer) {
            if (!achbotSamePanelGroup($db,(int)$peer['server_id'],$targetId) ||
                !achbotSameProviderPanel($db,(int)$peer['server_id'],$targetId))
                throw new RuntimeException('physical_transfer_reference_group_conflict');
        }
        $count=achbotPhysicalUpdateOrders($db,$all,$targetId,0,true);
        // The random event ID identifies this actual committed reassignment.
        // A later move back to the same server is a different real event.
        $reportRef='server:'.$orderId.':'.bin2hex(random_bytes(16));
        achbotPhysicalStageServiceChangeLocked($db,'server',$reportRef,$order+[
            'title'=>$target['title'],'source_id'=>$old,'target_id'=>$targetId],$actor,true);
        $commitAttempted=true;
        if (!$db->commit()) throw new RuntimeException('physical_transfer_commit_unknown');
        $started=false;
        error_log('achbot_shared_transfer_committed order_id='.$orderId.
            ' target_id='.$targetId.' active_refs='.$count);
        achbotPhysicalHideRetiredReceiptActions($db,$all);
        return ['id'=>$orderId,'userid'=>$order['userid'],'remark'=>$order['remark'],
            'title'=>$target['title'],'source_id'=>$old,'target_id'=>$targetId,
            'provider'=>achbotProviderType($db,$targetId),'shared_count'=>$count,'report_ref'=>$reportRef];
    } catch (Throwable $error) {
        if ($started) {try{$db->rollback();}catch(Throwable $ignored){}}
        $reason=(string)$error->getMessage();
        // Invalid or replayed callbacks are ordinary expected denial. Do not
        // flood the error log or reveal private panel/user details for them.
        $expected=['physical_transfer_unavailable','physical_transfer_denied',
            'physical_transfer_group_invalid','physical_transfer_target_unavailable',
            'physical_transfer_reference_group_conflict',
            'physical_transfer_pending_plan_review',
            'physical_pending_financial_effect','physical_operation_busy'];
        if ($commitAttempted || !in_array($reason,$expected,true))
            error_log('achbot_shared_transfer_'.($commitAttempted?'requires_review':'rejected').
                ' order_id='.$orderId.' code='.$reason);
        return null;
    } finally {
        if ($lease!==null) achbotPhysicalReleaseLock($db,$lease);
    }
}

/** PG gifts operate only on the bot's exact owned identities; never a vendor username search. */
function achbotProviderGiftUsers(mysqli $db,int $serverId,array $target): object {
    if (!achbotSupportsAccount(achbotProviderType($db,$serverId),0)) return achbotProviderFailure('unsupported_provider',false);
    try {
        if (!achbotGiftTargetMatches($db,$serverId,$target)) throw new RuntimeException('pg_gift_target_changed');
        $s=$db->prepare("SELECT id,remark FROM orders_list WHERE server_id=? AND status=1 ORDER BY id");
        $s->bind_param('i',$serverId);$s->execute();$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
        $contexts=[];
        foreach ($rows as $row) {
            $ctx=achbotPgOrderContext($db,(int)$row['id']);
            if ($ctx['account']['state']!=='linked') throw new RuntimeException('pg_gift_roster_account_requires_review');
            $contexts[$ctx['account']['id']]=$ctx;
        }
        $users=[];foreach ($contexts as $ctx) {
            $r=achbotPgRead($ctx['panel'],$ctx['identity']);
            if (($r['success']??false)!==true) throw new RuntimeException('pg_gift_list_unverified');
            $users[]=(object)$r['user'];
        }
        return (object)['users'=>$users,'total'=>count($users)];
    } catch (Throwable $error) {return achbotProviderFailure('pg_gift_list_requires_review');}
}

/** The same native presentation policy is used for details and both QR routes. */
function achbotOrderPresentation(mysqli $db,array $order,array $server,array $state,?object $provenRemote=null): array {
    require_once __DIR__.'/../Subscription/Presentation.php';
    $type=(string)($server['type']??'');$native=['links'=>[],'subscription_url'=>'','backup_urls'=>[]];
    if ($type==='pasarguard') {
        try {
            if ($provenRemote!==null) {
                if (!achbotProviderRenderProofValid($provenRemote,$order,$type))
                    throw new RuntimeException('pg_presentation_packet_unverified');
                $native['subscription_url']=(string)$provenRemote->subscription_url;
                $native['links']=is_array($provenRemote->links??null)?$provenRemote->links:[];
            } else {
                $ctx=achbotPgObserveOrderContext($db,(int)$order['id']);
                $links=achbotPgLinks($ctx['panel'],$ctx['identity']);
                $r=($links['success']??false)===true?$links:achbotPgRead($ctx['panel'],$ctx['identity']);
                if (($r['success']??false)!==true) throw new RuntimeException('pg_presentation_read_unverified');
                $native['subscription_url']=$r['user']['subscription_url'];
                if (($links['success']??false)===true) $native['links']=$links['links'];
            }
        } catch (Throwable $error) {}
        $native['backup_urls']=\AchBot\Subscription\Presentation\subscriptionAliases(
            $native['subscription_url'],$server['sub_links']??'');
    }
    return buildSubscriptionPresentation($type,$native,$state,[],'view',(int)$order['server_id']);
}

/**
 * Closed, source-owned provider contract. Adding a native adapter registers all
 * real operations here; an unknown or partial implementation is never eligible
 * for a sale, wallet reservation or remote effect. No runtime plugin loading.
 */
function achbotProviderRequiredOperations(): array {
    return ['plan','order','same_panel','user','user_info','deletion_target','action_preflight','action',
        'edit','pin_payment','target','reserve','dispatch','link'];
}
function achbotProviderRegistry(): array {
    return [
        'pasarguard'=>[
            'label'=>'admin_settings.management.pasarguard',
            'plan'=>'achbotPasarGuardSupportsPlan','order'=>'achbotPasarGuardSupportsOrder',
            'same_panel'=>'achbotPasarGuardSamePanelAdapter','user'=>'achbotPasarGuardUserAdapter',
            'user_info'=>'achbotPasarGuardUserInfoAdapter','deletion_target'=>'achbotPasarGuardDeletionTargetAdapter',
            'action_preflight'=>'achbotPasarGuardActionPreflightAdapter',
            'action'=>'achbotPasarGuardActionAdapter','edit'=>'achbotPasarGuardServiceEditAdapter',

            'pin_payment'=>'achbotPgPinPayment',
            'target'=>'achbotPasarGuardEffectTargetAdapter','reserve'=>'achbotPasarGuardReserveEffectAdapter',
            'dispatch'=>'achbotPasarGuardDispatchEffectAdapter','link'=>'achbotPasarGuardLinkEffectAdapter',
            'import_lookup'=>'achbotPasarGuardExistingUser','import_adopt'=>'achbotPasarGuardAdoptExistingLocked',
            'service_target'=>'achbotPasarGuardServiceTargetLocked',
            'service_checkout_pin'=>'achbotPasarGuardCheckoutPinMatches',
            'service_account_lock'=>'achbotPgServiceAccountLock',
            'service_resolution_lock'=>'achbotPgServiceResolutionLock',
            'service_resolution_check'=>'achbotPgServiceResolutionCheck',
            'service_resolution_release'=>'achbotPgServiceResolutionRelease',
            'service_auto_observe'=>'achbotPgServiceAutoObserve',
            'service_auto_reserve'=>'achbotPgServiceAutoReserveLocked',
            'service_execution'=>['BUY'=>['prepare'=>'achbotPasarGuardBuyPrepareLocked',
                'perform'=>'achbotPasarGuardBuyPerform','apply'=>'achbotPasarGuardBuyApplyLocked']]
                +array_fill_keys(['RENEW','ADD_VOLUME','ADD_TIME'],[
                    'prepare'=>'achbotPgServiceEditPrepareLocked','perform'=>'achbotPgServiceEditPerform',
                    'apply'=>'achbotPgServiceEditApplyLocked']),
            'service_finalization'=>['BUY'=>['prepare'=>'achbotPasarGuardBuyFinalizePrepareLocked',
                'perform'=>'achbotPasarGuardBuyFinalizePerform','apply'=>'achbotPasarGuardBuyFinalizeApplyLocked']]
                +array_fill_keys(['RENEW','ADD_VOLUME','ADD_TIME'],[
                    'prepare'=>'achbotPgServiceEditFinalizePrepareLocked','perform'=>'achbotServiceEditFinalizePerform',
                    'apply'=>'achbotPgServiceEditFinalizeApplyLocked'])
        ]
    ];
}
function achbotProviderRegistryEntryValid($type,$adapter): bool {
    if (!is_string($type) || preg_match('/^[a-z][a-z0-9_]{0,23}$/D',$type)!==1 ||
        !is_array($adapter) || !is_string($adapter['label']??null) ||
        preg_match('/^[a-z][a-z0-9_.]+$/D',$adapter['label'])!==1) return false;
    $required=achbotProviderRequiredOperations();
    if (array_diff(array_keys($adapter),array_merge(['label','import_lookup','import_adopt','service_target','service_checkout_pin','service_execution','service_finalization','service_account_lock','service_auto_observe','service_auto_reserve','service_resolution_lock','service_resolution_check','service_resolution_release'],$required))) return false;
    foreach ($required as $operation)
        if (!is_string($adapter[$operation]??null) || !is_callable($adapter[$operation]))
            return false;
    // A registered paid service kind must have both execution and finalization.
    foreach (['service_target','service_checkout_pin','service_account_lock','service_auto_observe','service_auto_reserve','service_resolution_lock','service_resolution_check','service_resolution_release'] as $operation)
        if (array_key_exists($operation,$adapter) &&
            (!is_string($adapter[$operation]) || !is_callable($adapter[$operation]))) return false;
    if (isset($adapter['service_auto_observe'])!==isset($adapter['service_auto_reserve']) ||
        (isset($adapter['service_auto_observe']) && !isset($adapter['service_account_lock'],
            $adapter['service_execution']['RENEW'],$adapter['service_finalization']['RENEW']))) return false;
    foreach (['service_execution','service_finalization'] as $phase) if (array_key_exists($phase,$adapter)) {
        if (!is_array($adapter[$phase]) || !$adapter[$phase]) return false;
        foreach ($adapter[$phase] as $kind=>$callbacks) {
            if (!in_array($kind,['BUY','RENEW','ADD_VOLUME','ADD_TIME'],true) ||
                !is_array($callbacks) || count($callbacks)!==3) return false;
            foreach (['prepare','perform','apply'] as $callback)
                if (!is_string($callbacks[$callback]??null) || !is_callable($callbacks[$callback])) return false;
        }
    }
    $execution=array_keys($adapter['service_execution']??[]);
    $finalization=array_keys($adapter['service_finalization']??[]);
    sort($execution);sort($finalization);
    if ($execution!==$finalization || ($execution && !isset($adapter['service_target']))) return false;
    $lookup=array_key_exists('import_lookup',$adapter);$adopt=array_key_exists('import_adopt',$adapter);
    if ($lookup!==$adopt) return false;
    if ($lookup) foreach (['import_lookup','import_adopt'] as $operation)
        if (!is_string($adapter[$operation]) || !is_callable($adapter[$operation])) return false;
    return true;
}
function achbotProviderRegistryValid(array $registry): bool {
    if (!$registry) return false;
    foreach ($registry as $type=>$adapter) if (!achbotProviderRegistryEntryValid($type,$adapter)) return false;
    return true;
}
function achbotProviderDefinition($type): ?array {
    if (!is_string($type)) return null;
    $adapter=achbotProviderRegistry()[$type]??null;
    return achbotProviderRegistryEntryValid($type,$adapter)?$adapter:null;
}
function achbotSupportsProviderType($type): bool {
    return achbotProviderDefinition($type)!==null;
}
function achbotProviderTypes(): array {
    $types=[];
    foreach (achbotProviderRegistry() as $type=>$adapter)
        if (achbotProviderRegistryEntryValid($type,$adapter)) $types[]=$type;
    return $types;
}
/** SQL contains only validated source-owned keys, never a callback or customer value. */
function achbotProviderSqlTypes(): string {
    $types=achbotProviderTypes();
    if (!$types) throw new RuntimeException('provider_registry_empty');
    return "'".implode("','",$types)."'";
}
function achbotProviderLabel($type): string {
    $adapter=achbotProviderDefinition($type);
    return $adapter===null?'':achbotMessage($adapter['label']);
}
function achbotProviderServiceEdit(mysqli $db,string $type,int $serverId,array $change,?array $giftTarget=null) {
    $adapter=achbotProviderDefinition($type);
    return $adapter===null?achbotProviderFailure('unsupported_service_adapter',false):
        ($adapter['edit'])($db,$serverId,$change,$giftTarget);
}
function achbotPasarGuardServiceEditAdapter(mysqli $db,int $serverId,array $change,?array $giftTarget=null) {
    return achbotPgServiceEdit($db,$serverId,$change,$giftTarget);
}
function achbotProviderPinPayment(mysqli $db,int $payId): void {
    $s=$db->prepare('SELECT type,server_id FROM pays WHERE id=?');
    $s->bind_param('i',$payId);$s->execute();$pay=$s->get_result()->fetch_assoc();$s->close();
    if (!$pay) throw new RuntimeException('provider_payment_missing');
    if ($pay['type']==='INCREASE_WALLET') return;
    $adapter=achbotProviderDefinition(achbotProviderType($db,(int)$pay['server_id']));
    if ($adapter===null) throw new RuntimeException('unsupported_payment_provider');
    ($adapter['pin_payment'])($db,$payId);
}
/**
 * Resolve the service named by the payment, rather than a redundant server
 * hint. Read-only subject checks may omit server_id; a present hint must
 * still identify the exact order/plan server and is never silently replaced.
 */
function achbotProviderPaymentSubjectServer(mysqli $db,array $pay,bool $lock=false): ?int {
    $type=(string)($pay['type']??'');
    $pid=(int)($pay['plan_id']??0);
    $uid=(int)($pay['user_id']??0);
    if ($type==='RENEW_ACCOUNT' ||
        preg_match('/^INCREASE_(DAY|VOLUME)_([1-9][0-9]*)_([1-9][0-9]*)$/D',$type,$match)) {
        $oid=$type==='RENEW_ACCOUNT'?(int)($pay['order_id']??0):(int)$match[2];
        if ($oid<=0 || ($type==='RENEW_ACCOUNT' && $pid<=0) ||
            (!empty($pay['order_id']) && (int)$pay['order_id']!==$oid)) return null;
        $s=$db->prepare('SELECT server_id,fileid,userid FROM orders_list WHERE id=?'.($lock?' FOR UPDATE':''));
        $s->bind_param('i',$oid);$s->execute();$subject=$s->get_result()->fetch_assoc();$s->close();
        if (!$subject || ($pid>0 && (int)$subject['fileid']!==$pid) ||
            ($uid>0 && (int)$subject['userid']!==$uid)) return null;
    } elseif ($type==='BUY_SUB') {
        if ($pid<=0) return null;
        $s=$db->prepare('SELECT server_id FROM server_plans WHERE id=?'.($lock?' FOR UPDATE':''));
        $s->bind_param('i',$pid);$s->execute();$subject=$s->get_result()->fetch_assoc();$s->close();
        if (!$subject) return null;
    } else return null;
    $sid=(int)$subject['server_id'];
    if ($sid<=0) return null;
    if (array_key_exists('server_id',$pay) &&
        ((!is_int($pay['server_id']) && !is_string($pay['server_id'])) ||
         (string)$pay['server_id']!==(string)$sid)) return null;
    return $sid;
}
function achbotProviderReserveEffect(mysqli $db,int $effectId,array $target,string $remark,string $operation,int $expire,int $bytes): void {
    $adapter=achbotProviderDefinition($target['provider']??null);
    if ($adapter===null) throw new RuntimeException('unsupported_effect_provider');
    ($adapter['reserve'])($db,$effectId,$target,$remark,$operation,$expire,$bytes);
}
function achbotPasarGuardReserveEffectAdapter(mysqli $db,int $effectId,array $target,string $remark,string $operation,int $expire,int $bytes): void {
    if (($target['snapshot']['provider']??null)!=='pasarguard') throw new RuntimeException('pg_effect_identity_invalid');
    achbotPgReserveEffect($db,$effectId,$target,$remark,$operation,$expire,$bytes);
}
function achbotProviderDispatchReservedAccount(
    mysqli $db,int $effectId,int $serverId,?int $group,string $endpoint,string $remark,
    int $volume,int $days,int $planId,string $operation,int $expiry,int $bytes,array $p,string $credentials
): array {
    $type=achbotProviderType($db,$serverId);
    // The common reservation captured this provider before COMMIT. A later
    // edit must not select another adapter for the already-owned operation.
    if (array_key_exists('server_type',$p) &&
        (!is_string($p['server_type']) || $p['server_type']!==$type))
        throw new RuntimeException('effect_provider_changed_pre_dispatch');
    $adapter=achbotProviderDefinition($type);
    if ($adapter===null) throw new RuntimeException('unsupported_effect_provider');
    // This one short admission is AFTER reservation, BEFORE any outbound
    // CREATE, for both Marzban and PasarGuard. No lock spans provider HTTP.
    // Only source-owned non-payment creates use this adapter entry point.
    $q=$db->prepare('SELECT pay_row_id,source_kind,state FROM service_effects WHERE id=?');
    if (!$q) throw new RuntimeException('effect_dispatch_missing');
    $q->bind_param('i',$effectId);$q->execute();$effect=$q->get_result()->fetch_assoc();$q->close();
    if (!$effect || $effect['pay_row_id']!==null ||
        !in_array($effect['source_kind'],['free_trial','admin_bulk'],true) || $effect['state']!=='dispatching')
        throw new RuntimeException('nonpaid_effect_dispatch_invalid');
    return ($adapter['dispatch'])($db,$effectId,$serverId,$group,$endpoint,$remark,$volume,$days,$planId,$operation,$expiry,$bytes,$p,$credentials);
}
function achbotPasarGuardDispatchEffectAdapter(
    mysqli $db,int $effectId,int $serverId,?int $group,string $endpoint,string $remark,
    int $volume,int $days,int $planId,string $operation,int $expiry,int $bytes,array $p,string $credentials
): array {
    return achbotPgDispatchReserved($db,$effectId,$serverId,$remark,$operation,$expiry,$bytes,$p,$credentials);
}
function achbotProviderLinkEffect(mysqli $db,int $effectId,int $orderId,bool $paid): void {
    $s=$db->prepare('SELECT c.type FROM service_effects e JOIN server_config c ON c.id=e.target_server_id WHERE e.id=?');
    $s->bind_param('i',$effectId);$s->execute();$row=$s->get_result()->fetch_assoc();$s->close();
    $adapter=achbotProviderDefinition($row['type']??null);
    if ($adapter===null) throw new RuntimeException('unsupported_effect_provider');
    ($adapter['link'])($db,$effectId,$orderId,$paid);
}
function achbotPasarGuardLinkEffectAdapter(mysqli $db,int $effectId,int $orderId,bool $paid): void {
    if (!achbotPgLinkEffect($db,$effectId,$orderId)) throw new RuntimeException('pg_effect_link_unverified');
}

require_once __DIR__.'/ProviderRecovery.php';
