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
                                   ?callable $exactAccountGet=null,
                                   bool $existingOnly=false,
                                   bool $allowLinked=false): array {
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
        if ($existingOnly) throw new RuntimeException('paid_resume_original_line_missing');
        // The original per-pay-line UNIQUE and committed whole-count hold
        // still enforce one CREATE. A missing line is not a replay.
        return achbotCreatePaidAccount($db,$payId,$ordinal,$request);
    }
    $server=(int)($request['server_id']??0);
    $volume=(int)($request['volume']??0);
    $days=(int)($request['days']??0);
    // Only Owner-private, existing-only original BUY_SUB recovery may observe
    // a pre-linked original. Ordinary manual/bank/API provisioning never uses
    // this permission and no absent effect can fall through to CREATE.
    $linked=$existingOnly && $allowLinked &&
        in_array((string)$e['pay_state'],['processing_wallet','paid'],true) &&
        $e['state']==='local_recorded' && $e['order_id']!==null &&
        (int)$e['order_id']>0;
    if ($e['pay_type']!=='BUY_SUB' ||
        !in_array((string)$e['pay_state'],['processing','paid','processing_wallet'],true) ||
        !($linked || (in_array((string)$e['state'],['dispatching','verified'],true) &&
            $e['order_id']===null)) ||
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
            // Approve is NOT a resend authorization. An unknown Telegram ACK
            // might mean the packet was already delivered. Only the existing
            // owner-private DeliveryRecovery warning + confirmation can issue
            // a new attempt for this same sealed packet.
            $out[$ordinal]=$state;
        }
    }
    return $out;
}

/**
 * Issue #117: Owner-reconciled, already-dispatched wallet BUY_SUB recovery.
 * Only a COMPLETE original CREATE manifest is admitted, including original
 * individually linked orders. No new native CREATE, wallet debit, capacity
 * hold, merchant settlement or speculative delivery.
 */

/** A linked original order is provenance, not an invitation to replace it. */
function achbotOriginalBuyLinkedOrder(
    mysqli $db,array $pay,array $effect,int $ordinal,int $count,bool $lock
): ?array {
    $orderId=(int)($effect['order_id']??0);
    if ($orderId<=0) return null;
    $q=$db->prepare('SELECT * FROM orders_list WHERE id=?'.($lock?' FOR UPDATE':''));
    $q->bind_param('i',$orderId);$q->execute();
    $order=$q->get_result()->fetch_assoc();$q->close();
    if (!$order || (int)$order['userid']!==(int)$pay['user_id'] ||
        (int)$order['fileid']!==(int)$pay['plan_id'] ||
        (int)$order['server_id']!==(int)$effect['target_server_id'] ||
        (int)$order['inbound_id']!==0 ||
        (string)$order['remark']!==(string)$effect['remote_username'] ||
        (int)$order['amount']!==achbotAllocateOrderAmount((int)$pay['price'],$count,$ordinal) ||
        (int)$order['status']!==1 ||
        (int)$order['agent_bought']!==(int)($pay['agent_bought']??0) ||
        (int)$order['expire_date']<=0 ||
        !is_string($order['token']) ||
        !is_string($order['uuid']) || $order['uuid']==='' ||
        !is_string($order['link']) || $order['link']==='' ||
        (string)$order['autoRenew']!=='off') return null;
    if ($pay['panel_type']==='pasarguard') {
        // ProviderStorage is intentionally lazy-loaded by ordinary PG dispatch.
        // Wallet recovery inspects linked orders BEFORE any provider GET.
        achbotPgLoad();
        // The 012 provider incarnation must be the same linked effect and
        // original operation marker, not a coincidentally named subscription.
        $account=achbotProviderAccountByOrder($db,$orderId,$lock);
        if (!$account || (int)$account['effect_id']!==(int)$effect['id'] ||
            $account['order_id']!==$orderId ||
            $account['remote_username']!==$effect['remote_username'] ||
            $account['operation_marker']!=='achbot:v1:'.$effect['operation_id'] ||
            $account['state']!=='linked') return null;
        try {
            // An original order still needs to be manageable through its
            // CURRENT logical server. Historic 012 evidence alone does not
            // prove the server still points at the original physical panel.
            // The final snapshot first locks server_config in its pay join,
            // so this same check is stable across the terminal transaction.
            $panel=achbotPgServerPanel($db,(int)$order['server_id']);
            // Use an explicit current/locking read of this exact config;
            // a preexisting REPEATABLE READ view must not conceal a
            // connection change seen after the final payment row lock.
            $q=$db->prepare('SELECT provider_connection_id,provider_instance_key,panel_url
                FROM server_config WHERE id=?'.($lock?' FOR UPDATE':''));
            $serverId=(int)$order['server_id'];
            $q->bind_param('i',$serverId);$q->execute();
            $current=$q->get_result()->fetch_assoc();$q->close();
            if (!$current ||
                (int)$panel['id']!==(int)$account['panel_id'] ||
                $panel['instance_key']!==$account['instance_key'] ||
                !hash_equals((string)$account['endpoint_sha256'],
                    hash('sha256',rtrim((string)$panel['api_url'],'/'))) ||
                (int)$current['provider_connection_id']!==(int)$account['panel_id'] ||
                $current['provider_instance_key']!==$account['instance_key'] ||
                !hash_equals((string)$account['endpoint_sha256'],
                    hash('sha256',rtrim((string)$current['panel_url'],'/'))))
                return null;
            // A pending or malformed native mutation cannot be treated as
            // stable original delivery evidence.
            achbotPgMutationHistory($account);
        } catch (Throwable $ignored) {return null;}
    }
    return $order;
}

/** Read-only admission. Treat uncertainty as ineligible, not as absence. */
function achbotWalletBuyRecoverySnapshot(mysqli $db,int $payId,bool $lock=false): ?array {
    require_once __DIR__.'/DeliveryLedger.php';
    require_once __DIR__.'/RecoveryInspection.php';
    require_once __DIR__.'/../Purchase/OrderWrites.php';
    if ($payId<=0 || !achbotDeliverySchemaReady($db)) return null;
    achbotEffectRequireUniqueLine($db);
    $suffix=$lock?' FOR UPDATE':'';
    $q=$db->prepare('SELECT p.*,s.server_id AS plan_server,s.inbound_id AS plan_inbound,'.
        'c.type AS panel_type FROM pays p JOIN server_plans s ON s.id=p.plan_id '.
        'JOIN server_config c ON c.id=p.server_id WHERE p.id=?'.$suffix);
    $q->bind_param('i',$payId);$q->execute();$pay=$q->get_result()->fetch_assoc();$q->close();
    if (!$pay || $pay['type']!=='BUY_SUB' || $pay['state']!=='processing_wallet' ||
        (int)$pay['user_id']<=0 || (int)$pay['price']<=0 ||
        (int)$pay['plan_id']<=0 || (int)$pay['server_id']<=0 ||
        (int)$pay['agent_count']<0 ||
        (int)$pay['plan_server']!==(int)$pay['server_id'] ||
        (int)$pay['plan_inbound']!==0 ||
        !in_array((string)$pay['panel_type'],['marzban','pasarguard'],true) ||
        (string)($pay['payid']??'')!=='') return null;
    $count=max(1,(int)$pay['agent_count']);
    // Wallet buyers were directly debited in the original wallet claim. An
    // existing provider attempt means this is NOT that original funding path.
    $q=$db->prepare('SELECT id FROM payment_attempts WHERE pay_row_id=? LIMIT 1'.$suffix);
    $q->bind_param('i',$payId);$q->execute();$attempt=$q->get_result()->fetch_assoc();$q->close();
    if ($attempt) return null;
    $q=$db->prepare('SELECT * FROM service_effects WHERE pay_row_id=? '.
        'ORDER BY ordinal,effect_kind'.$suffix);
    $q->bind_param('i',$payId);$q->execute();$effects=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
    if (count($effects)!==$count+1) return null;
    $hold=$effects[0];
    if ($hold['effect_kind']!=='wallet_hold' || (int)$hold['ordinal']!==0 ||
        $hold['state']!=='consumed' || (int)$hold['reserved_count']!==$count ||
        (int)$hold['target_server_id']!==(int)$pay['server_id'] ||
        (int)$hold['plan_id']!==(int)$pay['plan_id'] ||
        $hold['order_id']!==null || (int)$hold['requested_expire']!==0 ||
        (int)$hold['requested_bytes']!==0) return null;
    $lines=[];$seenOrders=[];
    foreach (array_slice($effects,1) as $index=>$e) {
        $ordinal=$index+1;
        $bytes=achbotRecoveryExactInt($e['requested_bytes']);
        $linked=$e['state']==='local_recorded' && $e['order_id']!==null &&
            (int)$e['order_id']>0;
        if ($e['effect_kind']!=='create' || (int)$e['ordinal']!==$ordinal ||
            !($linked || (in_array($e['state'],['dispatching','verified'],true) &&
                $e['order_id']===null)) ||
            (int)$e['reserved_count']!==0 ||
            (int)$e['target_server_id']!==(int)$pay['server_id'] ||
            (int)$e['plan_id']!==(int)$pay['plan_id'] ||
            $e['target_group_id']!==$hold['target_group_id'] ||
            (string)$e['target_key']!==(string)$hold['target_key'] ||
            (string)$e['endpoint_sha256']!==(string)$hold['endpoint_sha256'] ||
            !achbotEffectValidUsername((string)$e['remote_username']) ||
            preg_match('/^[a-f0-9]{32}$/D',(string)$e['operation_id'])!==1 ||
            (int)$e['requested_expire']<=time() || $bytes===null ||
            $bytes<=0 || $bytes%1073741824!==0 ||
            !is_string($e['request_sha256']) || strlen($e['request_sha256'])!==64)
            return null;
        if ($linked) {
            $orderId=(int)$e['order_id'];
            if (isset($seenOrders[$orderId])) return null;
            $seenOrders[$orderId]=true;
            $order=achbotOriginalBuyLinkedOrder($db,$pay,$e,$ordinal,$count,$lock);
            if ($order===null) return null;
            $e['_original_order']=$order;
        }
        $lines[$ordinal]=$e;
    }
    // The physical target is a provider-verified identity checked independently
    // by each original-effect GET; mutable display settings are not proof.
    return ['pay'=>$pay,'hold'=>$hold,'lines'=>$lines,'count'=>$count];
}

/** Immutable linked-order and PasarGuard 012 mutation generation shared by
 * original Wallet and hosted delivery recovery. Unlinked lines stay null. */
function achbotOriginalBuyLinkedOrderPins(array $snapshot): array {
    $orderKeys=['id','userid','fileid','server_id','inbound_id','remark',
        'amount','status','agent_bought','expire_date','token','uuid','link',
        'autoRenew','provider_effect_id','provider_remote_user_id',
        'provider_remote_created_at'];
    $pins=[];
    foreach ($snapshot['lines'] as $ordinal=>$line) {
        $order=$line['_original_order']??null;
        if ($order===null) {$pins[$ordinal]=null;continue;}
        $pins[$ordinal]=[];
        foreach ($orderKeys as $key)
            $pins[$ordinal][$key]=(string)($order[$key]??'');
        if ($snapshot['pay']['panel_type']==='pasarguard') {
            $pins[$ordinal]['provider_mutation']=[
                'state'=>$line['provider_state'],
                'key'=>$line['provider_mutation_key'],
                'snapshot'=>$line['provider_mutation_snapshot'],
                'updated_at'=>$line['provider_evidence_updated_at']
            ];
        }
    }
    return $pins;
}

/** Compare only immutable fields: native readback may verify an effect. */
function achbotWalletBuyRecoveryPin(array $snapshot): string {
    $pay=$snapshot['pay'];$hold=$snapshot['hold'];
    $keys=['id','effect_kind','ordinal','operation_id','reserved_count',
        'target_server_id','target_group_id','target_key','endpoint_sha256',
        'plan_id','remote_username','requested_expire','requested_bytes','request_sha256','order_id'];
    $pick=static function(array $row) use($keys): array {
        $out=[];foreach($keys as $key) $out[$key]=(string)($row[$key]??'');return $out;
    };
    return hash('sha256',json_encode([
        'payment'=>achbotPaymentEvidencePayFingerprint($pay),
        'provider'=>(string)$pay['panel_type'],'count'=>$snapshot['count'],
        'hold'=>$pick($hold),
        'lines'=>array_map($pick,$snapshot['lines']),
        'linked_orders'=>achbotOriginalBuyLinkedOrderPins($snapshot)
    ],JSON_THROW_ON_ERROR));
}

function achbotWalletBuyRecoverable(mysqli $db,int $payId): bool {
    try {return achbotWalletBuyRecoverySnapshot($db,$payId)!==null;}
    catch (Throwable $ignored) {return false;}
}

/**
 * Owner-only caller must authenticate before invoking. Both provider paths
 * read back the ORIGINAL marker; never call CREATE for an absent ordinal.
 */
function achbotResumeWalletBuy(
    mysqli $db,int $payId,array $botState,array $buttonValues,string $botUrl,
    ?callable $marzbanRead=null
): array {
    achbotEffectRequireAutocommit($db);
    $first=achbotWalletBuyRecoverySnapshot($db,$payId);
    if ($first===null) throw new RuntimeException('wallet_recovery_manifest_unavailable');
    $pay=$first['pay'];$count=$first['count'];$original=achbotWalletBuyRecoveryPin($first);
    $owner=(int)$pay['user_id'];$server=(int)$pay['server_id'];
    [$serverInfo,$serverConfig]=getServerInfoAndConfig($db,$server);
    if ((string)($serverConfig['type']??'')!==(string)$pay['panel_type'])
        throw new RuntimeException('wallet_recovery_provider_changed');
    $lines=[];
    foreach ($first['lines'] as $ordinal=>$effect) {
        $volume=intdiv((int)$effect['requested_bytes'],1073741824);
        $issued=strtotime((string)$effect['created_at']);
        if ($issued===false || $issued<=0) throw new RuntimeException('wallet_recovery_issue_time_invalid');
        $days=max(1,(int)round(((int)$effect['requested_expire']-$issued)/86400));
        // The complete original manifest was checked BEFORE every call.
        // existingOnly forbids the helper's new-CREATE fallback even if an
        // unusual concurrent ledger deletion occurs after this snapshot.
        [$remote,$name,$port,$effectId]=achbotContinuePaidAccount($db,$payId,$ordinal,[
            'user_id'=>$owner,'server_type'=>(string)$pay['panel_type'],
            'server_id'=>$server,'inbound_id'=>0,'plan_id'=>(int)$pay['plan_id'],
            'remark'=>(string)$effect['remote_username'],'volume'=>$volume,
            'days'=>$days,'uniqid'=>'','protocol'=>'','port'=>0,
            'expire_microdate'=>0,'net_type'=>'','rahgozar'=>0,'limitip'=>0
        ],$marzbanRead,true,true);
        if (!is_object($remote) || ($remote->success??false)!==true ||
            $name!==$effect['remote_username'] || $effectId!==(int)$effect['id'])
            throw new RuntimeException('wallet_recovery_original_not_proven');
        [$token,$links,$uuid,$caption,$keys,$qr]=buildLinksAndSendQr(
            (string)$pay['panel_type'],$serverConfig,$botState,$botUrl,
            $server,'','',$name,$port,'',0,0,'','','',
            $remote,$owner,$volume,$days,$buttonValues,[],false,'',null,false);
        if (!is_string($token) || ($pay['panel_type']==='marzban' && $token==='') ||
            !is_string($links) || $links==='' || !is_string($caption) ||
            trim($caption)==='' || !is_string($qr) || !is_string($keys))
            throw new RuntimeException('wallet_recovery_packet_unavailable');
        $lines[$ordinal]=['effect_id'=>$effectId,'token'=>$token,'links'=>$links,
            'uuid'=>$uuid?:$token,'caption'=>$caption,'keys'=>$keys,'qr'=>$qr,
            'remark'=>$name];
    }

    $started=false;
    try {
        if (!$db->begin_transaction()) throw new RuntimeException('wallet_recovery_finish_begin');
        $started=true;
        $after=achbotWalletBuyRecoverySnapshot($db,$payId,true);
        if (!$after || !hash_equals($original,achbotWalletBuyRecoveryPin($after)))
            throw new RuntimeException('wallet_recovery_identity_changed');
        foreach ($after['lines'] as $ordinal=>$effect) {
            $expected=$effect['order_id']===null?'verified':'local_recorded';
            if ($effect['state']!==$expected ||
                (int)($lines[$ordinal]['effect_id']??0)!==(int)$effect['id'])
                throw new RuntimeException('wallet_recovery_effect_not_verified');
        }
        $q=$db->prepare("INSERT INTO payment_attempts
            (pay_row_id,provider,order_hash,quote_amount,quote_currency,
             local_amount_toman,stage,provider_status)
            VALUES (?,'wallet',?,?,'toman',?,'completed','wallet_debited')");
        $hash=(string)$pay['hash_id'];$amount=(int)$pay['price'];
        $q->bind_param('isii',$payId,$hash,$amount,$amount);
        if (!$q->execute() || $q->affected_rows!==1)
            throw new RuntimeException('wallet_recovery_local_attempt_unconfirmed');
        $q->close();
        $q=$db->prepare('SELECT * FROM payment_attempts WHERE pay_row_id=? FOR UPDATE');
        $q->bind_param('i',$payId);$q->execute();$attempt=$q->get_result()->fetch_assoc();$q->close();
        if (!$attempt || $attempt['provider']!=='wallet')
            throw new RuntimeException('wallet_recovery_local_attempt_invalid');
        foreach ($after['lines'] as $ordinal=>$effect) {
            $line=$lines[$ordinal];
            if ($effect['order_id']!==null) {
                // Original checkout already committed this exact order. Never
                // insert, reprice, relink or modify its native incarnation.
                $orderId=(int)$effect['order_id'];
            } else {
                $orderId=insertOrder($db,$owner,(string)$line['token'],(int)$pay['plan_id'],
                    $server,0,(string)$line['remark'],(string)$line['uuid'],
                    (int)$effect['requested_expire'],(string)$line['links'],
                    achbotAllocateOrderAmount($amount,$count,$ordinal),time(),
                    (int)($pay['agent_bought']??0),true);
                achbotLinkPaidAccountEffect($db,(int)$effect['id'],$orderId);
            }
            achbotDeliveryInsertWalletBuyLocked($db,$pay,$attempt,$effect,$first['hold'],
                $ordinal,$orderId,['uid'=>$owner,'qr'=>$line['qr'],
                'caption'=>$line['caption'],'keys'=>$line['keys'],'bot_url'=>$botUrl]);
        }
        $q=$db->prepare("UPDATE pays SET state='paid_with_wallet'
            WHERE id=? AND user_id=? AND type='BUY_SUB' AND state='processing_wallet'");
        $q->bind_param('ii',$payId,$owner);
        if (!$q->execute() || $q->affected_rows!==1)
            throw new RuntimeException('wallet_recovery_finish_state_unknown');
        $q->close();
        if (!$db->commit()) throw new RuntimeException('wallet_recovery_finish_commit_unknown');
        $started=false;
    } catch (Throwable $error) {
        if ($started) try {$db->rollback();} catch(Throwable $ignored) {}
        throw $error;
    }
    // No Telegram before the WHOLE original order and sealed packets COMMIT.
    // A concurrent, unknown or acknowledged packet is never retried.
    // If the post-commit dispatch query fails, preserve confirmed order finality:
    // Owner can inspect/send only still-PENDING packets via Delivery Recovery.
    $delivery=[];
    try {$delivery=achbotDeliveryDispatchPending($db,$payId);}
    catch (Throwable $error) {
        error_log('achbot_wallet_recovery_delivery_deferred pay_id='.$payId.
            ' class='.get_class($error));
    }
    return ['committed'=>true,'delivery'=>$delivery,'count'=>$count];
}

/**
 * A hosted BUY_SUB can be paid and its ORIGINAL native orders committed while
 * its PHP return handler crashes before it closes 003 or durably seals customer
 * delivery. Recovery only closes a COMPLETE, already-linked original manifest.
 * No merchant verification, native CREATE, order insertion or financial effect.
 */
function achbotHostedLinkedBuySnapshot(mysqli $db,int $payId,bool $lock=false): ?array {
    require_once __DIR__.'/DeliveryLedger.php';
    require_once __DIR__.'/RecoveryInspection.php';
    require_once __DIR__.'/ReviewDecisions.php';
    require_once __DIR__.'/../Purchase/OrderWrites.php';
    // Without the already-approved 019 journal we cannot rule out a prior
    // Owner attestation. Never relabel an operator-fulfilled business outcome
    // as merchant-verified delivery or overwrite the operator provenance.
    if ($payId<=0 || !achbotDeliverySchemaReady($db) ||
        !achbotNpDecisionSchemaReady($db)) return null;
    achbotEffectRequireUniqueLine($db);
    $suffix=$lock?' FOR UPDATE':'';
    $q=$db->prepare('SELECT p.*,s.server_id AS plan_server,s.inbound_id AS plan_inbound,'.
        'c.type AS panel_type FROM pays p JOIN server_plans s ON s.id=p.plan_id '.
        'JOIN server_config c ON c.id=p.server_id '.
        'JOIN server_info i ON i.id=p.server_id WHERE p.id=?'.$suffix);
    $q->bind_param('i',$payId);$q->execute();$pay=$q->get_result()->fetch_assoc();$q->close();
    if (!$pay || $pay['type']!=='BUY_SUB' || $pay['state']!=='paid' ||
        (int)$pay['user_id']<=0 || (int)$pay['price']<=0 ||
        (int)$pay['plan_id']<=0 || (int)$pay['server_id']<=0 ||
        (int)$pay['agent_count']<0 ||
        (int)$pay['plan_server']!==(int)$pay['server_id'] ||
        (int)$pay['plan_inbound']!==0 ||
        !in_array((string)$pay['panel_type'],['marzban','pasarguard'],true) ||
        !is_string($pay['payid']) || $pay['payid']==='') return null;
    $count=max(1,(int)$pay['agent_count']);
    // Only an EXISTING original hosted paid claim may be reconciled.
    // An invoice, human assertion or a newly returned browser status is
    // never itself proof of merchant settlement.
    $q=$db->prepare('SELECT * FROM payment_attempts WHERE pay_row_id=?'.$suffix);
    $q->bind_param('i',$payId);$q->execute();$attempt=$q->get_result()->fetch_assoc();$q->close();
    if (!$attempt ||
        !in_array((string)$attempt['provider'],['nextpay','zarinpal'],true) ||
        (string)$attempt['stage']!=='review' ||
        (string)$attempt['provider_status']!=='fulfillment_started' ||
        (string)$attempt['order_hash']!==(string)$pay['hash_id'] ||
        (string)$attempt['provider_reference']!==(string)$pay['payid'] ||
        (string)$attempt['quote_currency']!=='toman' ||
        (string)$attempt['quote_amount']!==sprintf('%d.00000000',(int)$pay['price']) ||
        (int)$attempt['local_amount_toman']!==(int)$pay['price'] ||
        $attempt['payment_id']!==null ||
        ($attempt['evidence_profile']??null)!==null) return null;
    $q=$db->prepare('SELECT id FROM payment_review_decisions WHERE pay_row_id=? LIMIT 1'.$suffix);
    $q->bind_param('i',$payId);$q->execute();$operator=$q->get_result()->fetch_assoc();$q->close();
    if ($operator) return null;
    // A previously sealed packet is never rebuilt/reissued.
    $q=$db->prepare('SELECT id FROM payment_delivery_intents WHERE pay_row_id=? LIMIT 1'.$suffix);
    $q->bind_param('i',$payId);$q->execute();$existing=$q->get_result()->fetch_assoc();$q->close();
    if ($existing) return null;
    $q=$db->prepare('SELECT * FROM service_effects WHERE pay_row_id=? '.
        'ORDER BY ordinal,effect_kind'.$suffix);
    $q->bind_param('i',$payId);$q->execute();$effects=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
    if (count($effects)!==$count) return null;
    $first=$effects[0];$lines=[];$orders=[];
    foreach ($effects as $index=>$e) {
        $ordinal=$index+1;
        $bytes=achbotRecoveryExactInt($e['requested_bytes']);
        if ($e['effect_kind']!=='create' || (int)$e['ordinal']!==$ordinal ||
            $e['state']!=='local_recorded' || (int)($e['order_id']??0)<=0 ||
            (int)$e['reserved_count']!==($ordinal===1?$count:0) ||
            (int)$e['target_server_id']!==(int)$pay['server_id'] ||
            (int)$e['plan_id']!==(int)$pay['plan_id'] ||
            $e['target_group_id']!==$first['target_group_id'] ||
            $e['target_key']!==$first['target_key'] ||
            $e['endpoint_sha256']!==$first['endpoint_sha256'] ||
            !achbotEffectValidUsername((string)$e['remote_username']) ||
            preg_match('/^[a-f0-9]{32}$/D',(string)$e['operation_id'])!==1 ||
            (int)$e['requested_expire']<=time() || $bytes===null ||
            $bytes<=0 || $bytes%1073741824!==0 ||
            !is_string($e['request_sha256']) || strlen($e['request_sha256'])!==64 ||
            isset($orders[(int)$e['order_id']])) return null;
        if ($pay['panel_type']==='marzban') {
            // The original Marzban native GET validates its target at
            // readback; revalidate the CURRENT panel URL/group and full 007
            // source fingerprint again during finalization. The initial
            // pay/plan/config/info FOR UPDATE protects this target read.
            $target=achbotRecoveryTarget($db,$payId,$ordinal);
            if (!achbotRecoveryTargetValid($target,$payId,$ordinal)) return null;
        }
        $order=achbotOriginalBuyLinkedOrder($db,$pay,$e,$ordinal,$count,$lock);
        if ($order===null) return null;
        $orders[(int)$e['order_id']]=true;
        $e['_original_order']=$order;
        $lines[$ordinal]=$e;
    }
    return ['pay'=>$pay,'attempt'=>$attempt,'lines'=>$lines,'count'=>$count];
}

function achbotHostedLinkedBuyPin(array $snapshot): string {
    $keys=['id','effect_kind','ordinal','operation_id','reserved_count',
        'target_server_id','target_group_id','target_key','endpoint_sha256',
        'plan_id','remote_username','requested_expire','requested_bytes',
        'request_sha256','order_id'];
    $effects=[];
    foreach ($snapshot['lines'] as $ordinal=>$e) {
        $effects[$ordinal]=[];
        foreach ($keys as $key) $effects[$ordinal][$key]=(string)($e[$key]??'');
    }
    $a=$snapshot['attempt'];
    return hash('sha256',json_encode([
        'pay'=>achbotPaymentEvidencePayFingerprint($snapshot['pay']),
        'state'=>$snapshot['pay']['state'],
        'panel_type'=>$snapshot['pay']['panel_type'],
        'payid'=>(string)$snapshot['pay']['payid'],
        'attempt'=>achbotPaymentEvidenceQuoteFingerprint($a),
        'attempt_stage'=>(string)$a['stage'],
        'attempt_status'=>(string)$a['provider_status'],
        'count'=>$snapshot['count'],'effects'=>$effects,
        'orders'=>achbotOriginalBuyLinkedOrderPins($snapshot)
    ],JSON_THROW_ON_ERROR));
}
function achbotHostedLinkedBuyRecoverable(mysqli $db,int $payId): bool {
    try {return achbotHostedLinkedBuySnapshot($db,$payId)!==null;}
    catch (Throwable $ignored) {return false;}
}

/** Existing Owner-only technical recovery route authenticates BEFORE entry.
 * Rebuild immutable 018 packets from authenticated native GET, then close
 * the held original 003 claim in the SAME SQL commit. */
function achbotResumeHostedLinkedBuy(
    mysqli $db,int $payId,array $botState,array $buttonValues,string $botUrl,
    ?callable $marzbanRead=null
): array {
    achbotEffectRequireAutocommit($db);
    $before=achbotHostedLinkedBuySnapshot($db,$payId);
    if (!$before) throw new RuntimeException('hosted_recovery_manifest_unavailable');
    $pay=$before['pay'];$count=$before['count'];
    $pin=achbotHostedLinkedBuyPin($before);
    $owner=(int)$pay['user_id'];$server=(int)$pay['server_id'];
    [$serverInfo,$serverConfig]=getServerInfoAndConfig($db,$server);
    if ((string)($serverConfig['type']??'')!==(string)$pay['panel_type'])
        throw new RuntimeException('hosted_recovery_provider_changed');
    $lines=[];
    foreach ($before['lines'] as $ordinal=>$effect) {
        $volume=intdiv((int)$effect['requested_bytes'],1073741824);
        $issued=strtotime((string)$effect['created_at']);
        if ($issued===false || $issued<=0) throw new RuntimeException('hosted_recovery_issue_time_invalid');
        $days=max(1,(int)round(((int)$effect['requested_expire']-$issued)/86400));
        [$remote,$name,$port,$effectId]=achbotContinuePaidAccount($db,$payId,$ordinal,[
            'user_id'=>$owner,'server_type'=>(string)$pay['panel_type'],
            'server_id'=>$server,'inbound_id'=>0,'plan_id'=>(int)$pay['plan_id'],
            'remark'=>(string)$effect['remote_username'],'volume'=>$volume,
            'days'=>$days,'uniqid'=>'','protocol'=>'','port'=>0,
            'expire_microdate'=>0,'net_type'=>'','rahgozar'=>0,'limitip'=>0
        ],$marzbanRead,true,true);
        if (!is_object($remote) || ($remote->success??false)!==true ||
            $name!==$effect['remote_username'] || $effectId!==(int)$effect['id'])
            throw new RuntimeException('hosted_recovery_original_not_proven');
        [$token,$links,$uuid,$caption,$keys,$qr]=buildLinksAndSendQr(
            (string)$pay['panel_type'],$serverConfig,$botState,$botUrl,
            $server,'','',$name,$port,'',0,0,'','','',
            $remote,$owner,$volume,$days,$buttonValues,[],false,'',null,false);
        if (!is_string($token) || ($pay['panel_type']==='marzban' && $token==='') ||
            !is_string($links) || $links==='' || !is_string($caption) ||
            trim($caption)==='' || !is_string($qr) || !is_string($keys))
            throw new RuntimeException('hosted_recovery_packet_unavailable');
        $lines[$ordinal]=['effect_id'=>$effectId,'caption'=>$caption,
            'keys'=>$keys,'qr'=>$qr];
    }
    $started=false;
    try {
        if (!$db->begin_transaction()) throw new RuntimeException('hosted_recovery_finish_begin');
        $started=true;
        $after=achbotHostedLinkedBuySnapshot($db,$payId,true);
        if (!$after || !hash_equals($pin,achbotHostedLinkedBuyPin($after)))
            throw new RuntimeException('hosted_recovery_identity_changed');
        foreach ($after['lines'] as $ordinal=>$effect) {
            if ($effect['state']!=='local_recorded' ||
                (int)$lines[$ordinal]['effect_id']!==(int)$effect['id'])
                throw new RuntimeException('hosted_recovery_original_order_changed');
            $line=$lines[$ordinal];
            achbotDeliveryInsertHostedBuyLocked($db,$after['pay'],$after['attempt'],
                $effect,$ordinal,(int)$effect['order_id'],[
                'uid'=>$owner,'qr'=>$line['qr'],'caption'=>$line['caption'],
                'keys'=>$line['keys'],'bot_url'=>$botUrl]);
        }
        // No financial re-claim or provider status invention: 'delivered'
        // here is the existing 003 *service fulfillment* status, not a
        // merchant-paid assertion or a Telegram acknowledgement.
        $q=$db->prepare("UPDATE payment_attempts SET stage='done',
            provider_status='delivered' WHERE id=? AND pay_row_id=?
            AND stage='review' AND provider_status='fulfillment_started'");
        $aid=(int)$after['attempt']['id'];
        $q->bind_param('ii',$aid,$payId);$ok=$q->execute() && $q->affected_rows===1;$q->close();
        if (!$ok || !$db->commit()) throw new RuntimeException('hosted_recovery_finish_commit_unknown');
        $started=false;
    } catch (Throwable $error) {
        if ($started) try {$db->rollback();}catch(Throwable $ignored){}
        throw $error;
    }
    $delivery=[];
    try {$delivery=achbotDeliveryDispatchPending($db,$payId);}
    catch (Throwable $error) {
        error_log('achbot_hosted_recovery_delivery_deferred pay_id='.$payId.
            ' class='.get_class($error));
    }
    return ['committed'=>true,'delivery'=>$delivery,'count'=>$count];
}
