<?php
/**
 * #227: evidence-based original Wallet BUY_SUB finality / Marzban renewal
 * inspection. A proven CREATE may restore a missing LOCAL order; never
 * allocate capacity, issue Native CREATE/RESET/PUT, or debit Wallet again.
 * A still-unknown original Native operation remains in the shared inbox.
 */
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/PaidAccountRecovery.php';
require_once __DIR__.'/../Purchase/OrderWrites.php';
require_once __DIR__.'/RecoveryTransport.php';
require_once __DIR__.'/RecoveryInspection.php';
require_once __DIR__.'/DeliveryLedger.php';
require_once __DIR__.'/WalletAddonNotices.php';
require_once __DIR__.'/../Admin/Access.php';

/** Exact original local claim with no guess from another payment/order. */
function achbotWalletBuyOriginalSource(mysqli $db,int $payId,bool $locked=false): ?array {
    if ($payId<=0) return null;
    $suffix=$locked?' FOR UPDATE':'';
    $q=$db->prepare('SELECT * FROM pays WHERE id=?'.$suffix);
    $q->bind_param('i',$payId);$q->execute();
    $pay=$q->get_result()->fetch_assoc();$q->close();
    if (!$pay || $pay['type']!=='BUY_SUB' ||
        $pay['state']!=='processing_wallet' || (int)$pay['user_id']<=0 ||
        (int)$pay['server_id']<=0 || (int)$pay['plan_id']<=0 ||
        (int)$pay['price']<=0 || (int)($pay['agent_count']??0)<0 ||
        (string)($pay['payid']??'')!=='') return null;
    $count=max(1,(int)$pay['agent_count']);
    $q=$db->prepare("SELECT *,UNIX_TIMESTAMP(created_at) AS original_effect_at
        FROM service_effects WHERE pay_row_id=?
        AND effect_kind IN ('wallet_hold','create') ORDER BY ordinal,id".$suffix);
    $q->bind_param('i',$payId);$q->execute();
    $all=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
    $hold=null;$lines=[];
    foreach ($all as $row) {
        if ($row['effect_kind']==='wallet_hold') {
            if ($hold!==null || (int)$row['ordinal']!==0) return null;
            $hold=$row;
        } elseif ($row['effect_kind']==='create') {
            $ordinal=(int)$row['ordinal'];
            if ($ordinal<=0 || $ordinal>$count || isset($lines[$ordinal])) return null;
            $lines[$ordinal]=$row;
        }
    }
    if (!$hold || $hold['state']!=='consumed' ||
        (int)$hold['reserved_count']!==$count ||
        (int)$hold['target_server_id']!==(int)$pay['server_id'] ||
        (int)$hold['plan_id']!==(int)$pay['plan_id']) return null;
    $orderRows=[];
    for ($i=1;$i<=$count;$i++) {
        if (!isset($lines[$i])) continue;
        $e=$lines[$i];
        if ((int)$e['pay_row_id']!==$payId ||
            $e['effect_kind']!=='create' ||
            (int)$e['plan_id']!==(int)$pay['plan_id'] ||
            (int)$e['target_server_id']!==(int)$pay['server_id'] ||
            (int)$e['reserved_count']!==0 ||
            !hash_equals((string)$hold['target_key'],(string)$e['target_key']) ||
            !hash_equals((string)$hold['endpoint_sha256'],(string)$e['endpoint_sha256']) ||
            ($hold['target_group_id']??null)!==($e['target_group_id']??null) ||
            !in_array($e['state'],['reserved','dispatching','verified','local_recorded'],true))
            return null;
        if ($e['state']==='local_recorded') {
            $orderId=(int)$e['order_id'];
            if ($orderId<=0) return null;
            $q=$db->prepare('SELECT * FROM orders_list WHERE id=?'.$suffix);
            $q->bind_param('i',$orderId);$q->execute();
            $o=$q->get_result()->fetch_assoc();$q->close();
            if (!$o || (int)$o['userid']!==(int)$pay['user_id'] ||
                (int)$o['fileid']!==(int)$pay['plan_id'] ||
                (int)$o['server_id']!==(int)$pay['server_id'] ||
                (int)$o['inbound_id']!==0 || (int)$o['status']!==1 ||
                (string)$o['remark']!==(string)$e['remote_username'] ||
                (int)$o['amount']!==achbotAllocateOrderAmount(
                    (int)$pay['price'],$count,$i)) return null;
            $orderRows[$i]=$o;
        } elseif ($e['order_id']!==null) return null;
    }
    ksort($lines);
    $proof=hash('sha256',json_encode([
        achbotPaymentEvidencePayFingerprint($pay),
        $hold['id'],$hold['operation_id'],$hold['request_sha256'],
        $hold['reserved_count'],$hold['state'],
        array_map(static fn($e)=>[
            $e['id'],$e['ordinal'],$e['operation_id'],$e['request_sha256'],
            $e['state'],$e['order_id'],$e['target_key'],$e['endpoint_sha256'],
            $e['remote_username'],$e['requested_expire'],$e['requested_bytes'],
            $e['original_effect_at']],$lines),
        array_map(static fn($o)=>[
            $o['id'],$o['userid'],$o['fileid'],$o['server_id'],$o['remark'],
            $o['expire_date'],$o['token'],$o['uuid'],$o['link'],$o['amount'],
            $o['status']],$orderRows),
    ],JSON_THROW_ON_ERROR));
    return ['pay'=>$pay,'count'=>$count,'hold'=>$hold,'lines'=>$lines,
        'orders'=>$orderRows,'proof'=>$proof];
}

/** Source CREATE commits its original days as time()+86400*days. The
 * journal timestamp corroborates duration without a mutable plan reread.
 */
function achbotWalletOriginalBuyEntitlement(array $e): ?array {
    $created=(int)($e['original_effect_at']??0);
    $expiry=(int)($e['requested_expire']??0);
    $bytes=(int)($e['requested_bytes']??0);
    if ($created<=0 || $expiry<=$created || $bytes<=0 ||
        $bytes%1073741824!==0) return null;
    $seconds=$expiry-$created;
    $days=intdiv($seconds+43200,86400);
    if ($days<=0 || $days>36500 ||
        abs($seconds-$days*86400)>120) return null;
    return ['days'=>$days,'volume'=>intdiv($bytes,1073741824),
        'date'=>$expiry-$days*86400,'expiry'=>$expiry];
}

/** An equivalent existing local subscription is not ours to duplicate. */
function achbotWalletOriginalBuyExistingOrder(mysqli $db,array $e): bool {
    $q=$db->prepare('SELECT id FROM orders_list WHERE server_id=? AND remark=? LIMIT 2');
    $server=(int)$e['target_server_id'];$remark=(string)$e['remote_username'];
    $q->bind_param('is',$server,$remark);$q->execute();
    $rows=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
    return $rows!==[];
}

/** Native GET is advisory until every original row also passes local proof. */
function achbotWalletOriginalBuyInspect(mysqli $db,int $payId,
    int $actor,int $primary): array {
    if ($actor<=0 || $primary<=0 ||
        !achbotAdminCanFor($db,$actor,$primary,'payments'))
        return ['status'=>'denied'];
    // Inspection stays GET-only, but must not advertise a valid completion
    // token or inspect Native for a locally archived original payment.
    if (achbotGatewayOriginalArchived($db,$payId))
        return ['status'=>'archived','pay_id'=>$payId];
    $q=$db->prepare('SELECT state,type FROM pays WHERE id=?');
    $q->bind_param('i',$payId);$q->execute();
    $state=$q->get_result()->fetch_assoc();$q->close();
    if (($state['type']??null)==='BUY_SUB' &&
        ($state['state']??null)==='paid_with_wallet') return ['status'=>'already_paid'];
    try {
        achbotEffectRequireUniqueLine($db);
        $claim=achbotWalletBuyOriginalSource($db,$payId);
        if (!$claim) return ['status'=>'review','reason'=>'wallet_buy_original_claim_missing'];
        $lines=[];$packets=[];$ready=count($claim['lines'])===$claim['count'];
        foreach ($claim['lines'] as $ordinal=>$e) {
            $status='original_effect_not_linked';
            if (in_array((string)$e['state'],['reserved','dispatching','verified'],true)) {
                try {
                    $q=$db->prepare('SELECT c.type,c.panel_url,c.sub_links,
                        sp.protocol,sp.type AS net_type,sp.rahgozar,sp.custom_path,
                        sp.custom_port,sp.custom_sni
                        FROM server_config c JOIN server_plans sp ON sp.server_id=c.id
                        WHERE c.id=? AND sp.id=?');
                    $server=(int)$e['target_server_id'];$plan=(int)$e['plan_id'];
                    $q->bind_param('ii',$server,$plan);$q->execute();
                    $panel=$q->get_result()->fetch_assoc();$q->close();
                    if (!$panel || !in_array($panel['type'],['marzban','pasarguard'],true) ||
                        !achbotWalletOriginalBuyEntitlement($e) ||
                        achbotWalletOriginalBuyExistingOrder($db,$e))
                        throw new RuntimeException('wallet_buy_original_order_proof_missing');
                    if ($e['state']==='verified') {
                        if ($panel['type']==='marzban') {
                            [$native]=achbotContinueMarzbanPaidAccount(
                                $db,$payId,$ordinal,$e,null,false,0,0);
                        } else {
                            [$native]=achbotContinuePasarGuardPaidAccount(
                                $db,$payId,$ordinal,$e);
                        }
                        if (($native->verified??false)!==true)
                            throw new RuntimeException('wallet_buy_original_native_unverified');
                        $status='matching_original_unlinked_recoverable';
                        $packets[$ordinal]=['native'=>$native,'panel'=>$panel];
                    } elseif ($panel['type']==='pasarguard') {
                        // GET-only original provider/instance/operation evidence.
                        // Even a matching account cannot mutate local state
                        // until the actor-bound operator confirmation arrives.
                        $observed=achbotObservePasarGuardPaidAccount(
                            $db,$payId,$ordinal,$e);
                        $user=$observed['user'];
                        $status='matching_original_unlinked_recoverable';
                        $packets[$ordinal]=['native'=>(object)[
                            'success'=>true,'verified'=>true,
                            'username'=>(string)$e['remote_username']],
                            'panel'=>$panel,'native_identity'=>[
                                'id'=>$user['id'],'created_at'=>$user['created_at']]];
                    } elseif ($panel['type']==='marzban') {
                        // Observe a lost CREATE acknowledgement without
                        // changing the original reserved/dispatching effect.
                        // The staff confirmation alone may mark that exact
                        // effect verified, then create its missing local row.
                        $seen=null;
                        $observed=achbotRecoveryInspectLine($db,$payId,
                            $ordinal,static function($url,$user,$pass,$name) use (&$seen) {
                                $seen=achbotRecoveryStrictAccountGet($url,$user,$pass,$name);
                                return $seen;
                            },$e['state']==='reserved');
                        $sub=$seen instanceof stdClass
                            ?($seen->subscription_url??null):null;
                        $path=is_string($sub)?parse_url($sub,PHP_URL_PATH):null;
                        if (($observed['status']??null)==='matching_observation' &&
                            is_string($path) &&
                            preg_match('#^/sub/[a-zA-Z0-9_.~%/-]{1,512}$#D',$path)===1 &&
                            is_array($seen->links??null)) {
                            $status='matching_original_unlinked_recoverable';
                            $native=(object)['success'=>true,'verified'=>true,
                                'username'=>(string)$e['remote_username'],
                                'sub_link'=>$path,'vray_links'=>$seen->links];
                            $packets[$ordinal]=['native'=>$native,'panel'=>$panel];
                        } else {
                            $status='native_unlinked_'.($observed['status']??'unverified');
                        }
                    }
                } catch(Throwable $error) {
                    $status='native_unlinked_inspection_unavailable';
                    achbotDiagnosticEvent('achbot.finance.wallet_buy_unlinked_hold',[
                        'pay_id'=>$payId,'ordinal'=>$ordinal,
                        'reason'=>achbotFinanceDiagnosticReason($error)],'warning');
                }
            }
            if ($e['state']==='local_recorded' &&
                isset($claim['orders'][$ordinal])) {
                try {
                    $q=$db->prepare('SELECT c.type,c.panel_url,c.sub_links,
                        sp.protocol,sp.type AS net_type,sp.rahgozar,sp.custom_path,
                        sp.custom_port,sp.custom_sni
                        FROM server_config c JOIN server_plans sp ON sp.server_id=c.id
                        WHERE c.id=? AND sp.id=?');
                    $server=(int)$e['target_server_id'];$plan=(int)$e['plan_id'];
                    $q->bind_param('ii',$server,$plan);$q->execute();
                    $panel=$q->get_result()->fetch_assoc();$q->close();
                    if (!$panel || !in_array($panel['type'],['marzban','pasarguard'],true))
                        throw new RuntimeException('wallet_buy_original_panel_missing');
                    if ($panel['type']==='marzban') {
                        [$native]=achbotContinueMarzbanPaidAccount(
                            $db,$payId,$ordinal,$e,null,false,0,0);
                    } else {
                        [$native]=achbotContinuePasarGuardPaidAccount(
                            $db,$payId,$ordinal,$e);
                    }
                    if (($native->verified??false)!==true ||
                        (int)$e['requested_bytes']<=0 ||
                        (int)$e['requested_bytes']%1073741824!==0 ||
                        (int)$e['requested_expire']<=0)
                        throw new RuntimeException('wallet_buy_original_native_unknown');
                    $status='matching_original_linked';
                    $packets[$ordinal]=['native'=>$native,'panel'=>$panel];
                } catch(Throwable $error) {
                    $status='native_unverified';
                    achbotDiagnosticEvent('achbot.finance.wallet_buy_native_hold',[
                        'pay_id'=>$payId,'ordinal'=>$ordinal,
                        'effect_id'=>(int)$e['id'],
                        'reason'=>achbotFinanceDiagnosticReason($error),
                        'error_class'=>get_class($error),
                        'stage'=>'native_get'], 'warning');
                }
            }
            if (!in_array($status,['matching_original_linked',
                'matching_original_unlinked_recoverable'],true)) $ready=false;
            $lines[]=['ordinal'=>$ordinal,'effect_id'=>(int)$e['id'],
                'operation'=>(string)$e['operation_id'],
                'username'=>(string)$e['remote_username'],
                'order_id'=>(int)($e['order_id']??0),
                'server_id'=>(int)$e['target_server_id'],
                'expiry'=>(int)$e['requested_expire'],
                'bytes'=>(int)$e['requested_bytes'],
                'state'=>(string)$e['state'],'status'=>$status];
        }
        if (count($lines)!==$claim['count']) $ready=false;
        return ['status'=>$ready?'ready':'review',
            'reason'=>$ready?'original_native_and_local_recovery_proven':
                'native_or_original_order_unproven',
            'pay_id'=>$payId,'count'=>$claim['count'],
            'missing_orders'=>$claim['count']-count($claim['orders']),
            'lines'=>$lines,
            // An operator token for an unverified PG CREATE binds the actual
            // GET-observed incarnation. A replaced Native account with the
            // same username/operation marker cannot inherit a stale token.
            'proof'=>hash('sha256',$claim['proof'].'|'.json_encode(
                array_map(static fn($row)=>$row['native_identity']??null,
                    $packets),JSON_THROW_ON_ERROR)),
            // Private server-side packets only; never include in UI text/logs.
            '_prepared'=>$packets,'_claim'=>$claim];
    } catch(Throwable $error) {
        achbotDiagnosticEvent('achbot.finance.wallet_buy_inspection_deferred',[
            'pay_id'=>$payId,'reason'=>achbotFinanceDiagnosticReason($error)],'warning');
        return ['status'=>'review','reason'=>'wallet_buy_inspection_unavailable'];
    }
}

function achbotWalletOriginalBuyToken(array $inspection,int $actor): string {
    if ($actor<=0 || ($inspection['status']??'')!=='ready' ||
        !preg_match('/^[a-f0-9]{64}$/D',(string)($inspection['proof']??'')))
        throw new RuntimeException('wallet_buy_review_token_invalid');
    return substr(hash_hmac('sha256',
        'wallet-buy-original-finality-v1|'.$actor.'|'.(int)$inspection['pay_id'].'|'.
        $inspection['proof'],achbotPaymentEvidenceKey()),0,20);
}

/** Original LOCAL record recovery, payment finality and first-send
 * obligations share one SQL COMMIT. Never CREATE, debit or reserve again.
 */
function achbotWalletOriginalBuyComplete(mysqli $db,int $payId,int $actor,
    int $primary,string $token): array {
    if ($payId<=0 || $actor<=0 || $primary<=0 ||
        preg_match('/^[a-f0-9]{20}$/D',$token)!==1)
        return ['status'=>'review','reason'=>'invalid_confirmation'];
    $lease=achbotOriginalDispatchLeaseBegin($db,$payId);
    if ($lease!=='owned') return ['status'=>'review','reason'=>'payment_busy'];
    $started=false;$stage='native_reinspection';
    try {
        // The same original-payment session lease synchronizes this check
        // with Archive, BEFORE any verified provider/effect journal write.
        if (achbotGatewayOriginalArchived($db,$payId))
            return ['status'=>'archived','pay_id'=>$payId];
        $inspection=achbotWalletOriginalBuyInspect($db,$payId,$actor,$primary);
        if (($inspection['status']??null)==='already_paid')
            return ['status'=>'already_paid'];
        if (($inspection['status']??null)!=='ready' ||
            !hash_equals(achbotWalletOriginalBuyToken($inspection,$actor),$token))
            return ['status'=>'review','reason'=>'original_proof_changed'];
        if (!achbotWalletAddonNoticesReady($db))
            return ['status'=>'review','reason'=>'original_delivery_schema_missing'];
        // Original CREATE completed remotely but its local verified mark
        // may have been interrupted. Only AFTER this actor-bound confirmation
        // do we authenticate/GET again and commit an exact verification mark.
        // No CREATE/RESET/PUT, capacity or financial mutation occurs here.
        $originalClaim=$inspection['_claim'];
        foreach ($originalClaim['lines'] as $ordinal=>$effect) {
            if (in_array($effect['state'],['reserved','dispatching'],true)) {
                $stage='original_native_verification';
                $panel=$inspection['_prepared'][$ordinal]['panel']??null;
                if (!$panel) throw new RuntimeException('wallet_buy_original_native_unverified');
                if ($panel['type']==='marzban') {
                    achbotContinueMarzbanPaidAccount($db,$payId,$ordinal,$effect,
                        null,$effect['state']==='reserved',$actor,$primary);
                } elseif ($panel['type']==='pasarguard') {
                    $identity=$inspection['_prepared'][$ordinal]['native_identity']??null;
                    if (!is_array($identity))
                        throw new RuntimeException('wallet_buy_original_native_identity_missing');
                    achbotContinuePasarGuardPaidAccount($db,$payId,$ordinal,$effect,
                        $actor,$primary,$identity);
                } else throw new RuntimeException('wallet_buy_original_native_unverified');
            }
        }
        $inspection=achbotWalletOriginalBuyInspect($db,$payId,$actor,$primary);
        if (($inspection['status']??null)!=='ready')
            return ['status'=>'review','reason'=>'original_verification_changed'];
        $stage='original_first_send_render';
        require_once __DIR__.'/../Subscription/Delivery.php';
        $claim=$inspection['_claim'];$pay=$claim['pay'];$count=$claim['count'];
        $packets=[];$localOrders=[];
        for ($i=1;$i<=$count;$i++) {
            $e=$claim['lines'][$i];$o=$claim['orders'][$i]??null;
            $item=$inspection['_prepared'][$i];$panel=$item['panel'];
            $original=achbotWalletOriginalBuyEntitlement($e);
            if (!$original) throw new RuntimeException('wallet_buy_original_quote_unproven');
            $created=$o?(int)$o['date']:$original['date'];
            $expiry=$o?(int)$o['expire_date']:$original['expiry'];
            $seconds=$expiry-$created;
            if ($created<=0 || $seconds<=0 || $seconds%86400!==0 ||
                abs($expiry-(int)$e['requested_expire'])>60 ||
                $seconds>36500*86400 ||
                intdiv($seconds,86400)!==$original['days'])
                throw new RuntimeException('wallet_buy_original_order_entitlement_changed');
            $days=intdiv($seconds,86400);$volume=$original['volume'];
            [$token,$link,$nativeId,$caption,$keys,$qr]=buildLinksAndSendQr(
                $panel['type'],
                ['panel_url'=>$panel['panel_url'],'sub_links'=>$panel['sub_links']],
                $GLOBALS['botState']??[],(string)($GLOBALS['botUrl']??''),
                (int)$e['target_server_id'],(string)($o['uuid']??''),
                (string)$panel['protocol'],(string)$e['remote_username'],
                0,(string)$panel['net_type'],0,(int)$panel['rahgozar'],
                (string)$panel['custom_path'],(string)$panel['custom_port'],
                (string)$panel['custom_sni'],$item['native'],
                (int)$pay['user_id'],$volume,$days,[],false,'',null,false);
            if (!is_string($caption) || trim($caption)==='' ||
                !is_string($keys) || !is_string($qr) ||
                ($panel['type']==='marzban' && !$nativeId))
                throw new RuntimeException('wallet_buy_original_delivery_packet_missing');
            $packets[$i]=['kind'=>'qr','uid'=>(int)$pay['user_id'],
                'qr'=>$qr,'caption'=>$caption,'keys'=>$keys,
                'bot_url'=>(string)($GLOBALS['botUrl']??'')];
            if (!$o) {
                // Original Marzban subscription token comes from authenticated
                // GET. PasarGuard's random historical local UUID is unknown:
                // never invent it. Native ownership lives in the effect.
                if ($e['state']!=='verified' ||
                    ($panel['type']==='marzban' &&
                        (!is_string($token) || $token==='')))
                    throw new RuntimeException('wallet_buy_original_record_identity_missing');
                $localOrders[$i]=['token'=>(string)$token,
                    'uuid'=>$panel['type']==='marzban'?(string)$nativeId:'',
                    'link'=>(string)$link,'date'=>$created,'expiry'=>$expiry];
            }
        }
        $stage='sql_original_claim_lock';
        if (!$db->begin_transaction())
            throw new RuntimeException('wallet_buy_original_finality_begin');
        $started=true;
        $current=achbotWalletBuyOriginalSource($db,$payId,true);
        if (!$current || !hash_equals($claim['proof'],$current['proof']) ||
            count($current['lines'])!==$count ||
            count($current['orders'])!==count($claim['orders']) ||
            !achbotAdminCanLocked($db,$actor,$primary,'payments'))
            throw new RuntimeException('wallet_buy_original_finality_stale');
        achbotEffectAssertNotArchivedLocked($db,$payId,false);
        $q=$db->prepare('SELECT id FROM payment_attempts WHERE pay_row_id=? FOR UPDATE');
        $q->bind_param('i',$payId);$q->execute();
        $previous=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
        if ($previous!==[])
            throw new RuntimeException('wallet_buy_original_attempt_exists');
        $q=$db->prepare('SELECT id FROM payment_delivery_intents WHERE pay_row_id=? FOR UPDATE');
        $q->bind_param('i',$payId);$q->execute();
        $prior=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
        if ($prior!==[])
            throw new RuntimeException('wallet_buy_original_delivery_exists');
        $q=$db->prepare('SELECT c.type,c.panel_url,i.panel_group_id
            FROM server_config c JOIN server_info i ON i.id=c.id
            WHERE c.id=? FOR UPDATE');
        $serverId=(int)$pay['server_id'];
        $q->bind_param('i',$serverId);$q->execute();
        $livePanel=$q->get_result()->fetch_assoc();$q->close();
        if (!$livePanel || !in_array($livePanel['type'],['marzban','pasarguard'],true) ||
            !hash_equals((string)$claim['hold']['endpoint_sha256'],
                hash('sha256',rtrim((string)$livePanel['panel_url'],'/'))) ||
            ($claim['hold']['target_group_id']??null)!==
                ($livePanel['panel_group_id']??null))
            throw new RuntimeException('wallet_buy_original_panel_repointed');
        $stage='sql_original_local_reconciliation';
        foreach ($localOrders as $i=>$record) {
            $effect=$claim['lines'][$i];
            if (achbotWalletOriginalBuyExistingOrder($db,$effect))
                throw new RuntimeException('wallet_buy_original_existing_order_conflict');
            $orderId=insertOrder($db,(int)$pay['user_id'],$record['token'],
                (int)$pay['plan_id'],(int)$pay['server_id'],0,
                (string)$effect['remote_username'],$record['uuid'],
                $record['expiry'],$record['link'],
                achbotAllocateOrderAmount((int)$pay['price'],$count,$i),
                $record['date'],(int)($pay['agent_bought']??0),true);
            achbotLinkPaidAccountEffect($db,(int)$effect['id'],$orderId);
        }
        $restored=achbotWalletBuyOriginalSource($db,$payId,true);
        if (!$restored || count($restored['lines'])!==$count ||
            count($restored['orders'])!==$count)
            throw new RuntimeException('wallet_buy_original_local_record_incomplete');
        $stage='sql_first_send_ledger';
        $sealed=achbotPaymentEvidenceProtect('wallet-buy-original-notice:'.$payId,[
            'version'=>1,'pay_id'=>$payId,
            'pay_sha256'=>achbotPaymentEvidencePayFingerprint($pay),
            'original_proof'=>$claim['proof'],
            'original_operations'=>array_values(array_map(
                static fn($e)=>(string)$e['operation_id'],$claim['lines'])),
            'historical_debit_independently_audited'=>false,
            'purpose'=>'original_wallet_purchase_reconciled_notice']);
        $provider='wallet';$hash=(string)$pay['hash_id'];
        $quote=sprintf('%d.00000000',(int)$pay['price']);
        $currency='toman';$stage='completed';$providerStatus='wallet_debited';
        $evidenceState='verified';$amount=(int)$pay['price'];
        $q=$db->prepare('INSERT INTO payment_attempts
            (pay_row_id,provider,order_hash,quote_amount,quote_currency,
            local_amount_toman,stage,provider_status,
            evidence_fulfillment_state,evidence_fulfillment_payload)
            VALUES (?,?,?,?,?,?,?,?,?,?)');
        $q->bind_param('issssissss',$payId,$provider,$hash,$quote,$currency,
            $amount,$stage,$providerStatus,$evidenceState,$sealed);
        if (!$q->execute() || $q->affected_rows!==1)
            throw new RuntimeException('wallet_buy_original_attempt_insert');
        $attemptId=(int)$db->insert_id;$q->close();
        $q=$db->prepare('SELECT * FROM payment_attempts WHERE id=? FOR UPDATE');
        $q->bind_param('i',$attemptId);$q->execute();
        $attempt=$q->get_result()->fetch_assoc();$q->close();
        if (!$attempt || (int)$attempt['pay_row_id']!==$payId)
            throw new RuntimeException('wallet_buy_original_attempt_readback');
        foreach ($restored['lines'] as $i=>$e)
            achbotDeliveryInsertWalletBuyLocked($db,$pay,$attempt,$e,
                $restored['hold'],$i,(int)$restored['orders'][$i]['id'],$packets[$i]);
        achbotWalletOriginalBuyMarkAdminReportLocked($db,$restored);
        $q=$db->prepare("UPDATE pays SET state='paid_with_wallet'
            WHERE id=? AND user_id=? AND type='BUY_SUB' AND state='processing_wallet'");
        $uid=(int)$pay['user_id'];$q->bind_param('ii',$payId,$uid);
        if (!$q->execute() || $q->affected_rows!==1)
            throw new RuntimeException('wallet_buy_original_pay_finality_unconfirmed');
        $q->close();
        $stage='sql_commit_ack';
        if (!$db->commit()) throw new RuntimeException('wallet_buy_original_commit_unknown');
        $started=false;
        achbotDiagnosticEvent('achbot.finance.wallet_buy_original_finalized',[
            'pay_id'=>$payId,'actor_id'=>$actor,'count'=>$count,
            'delivery'=>'original_first_send_pending'],'info');
        return ['status'=>'settled_delivery_pending','count'=>$count];
    } catch(Throwable $error) {
        if ($started) try {$db->rollback();} catch(Throwable $ignored) {}
        $message=(string)$error->getMessage();
        $reason=preg_match('/^[a-z][a-z0-9_]{2,100}$/D',$message)===1
            ?$message:achbotFinanceDiagnosticReason($error);
        achbotDiagnosticEvent('achbot.finance.wallet_buy_original_finality_deferred',[
            'pay_id'=>$payId,'actor_id'=>$actor,'stage'=>$stage,
            'error_class'=>get_class($error),'reason'=>$reason],'warning');
        // A lost SQL COMMIT acknowledgement is not proof of rollback. When
        // the original finality is already durable, report completion.
        try {
            $q=$db->prepare('SELECT state FROM pays WHERE id=?');
            $q->bind_param('i',$payId);$q->execute();
            $state=$q->get_result()->fetch_assoc();$q->close();
            if (($state['state']??null)==='paid_with_wallet')
                return ['status'=>'already_paid'];
        } catch(Throwable $ignored) {}
        return ['status'=>'review','reason'=>'original_finality_unverified'];
    } finally {
        achbotOriginalDispatchLeaseEnd($db,$payId,$lease);
    }
}

/** Marzban renewal has NO original absolute mutation journal. Observe the
 * current authenticated account for the operator, but never treat it as
 * evidence of the original additive renewal/quote or repeat the mutation.
 */
function achbotWalletOriginalMarzbanInspect(mysqli $db,int $payId,
    int $actor,int $primary): array {
    if ($actor<=0 || $primary<=0 ||
        !achbotAdminCanFor($db,$actor,$primary,'payments'))
        return ['status'=>'denied'];
    $q=$db->prepare("SELECT p.*,s.days AS current_plan_days,
        s.volume AS current_plan_volume,s.server_id AS plan_server,
        c.type AS panel_type,c.panel_url,c.username AS panel_user,
        c.password AS panel_password
        FROM pays p LEFT JOIN server_plans s ON s.id=p.plan_id
        LEFT JOIN server_config c ON c.id=p.server_id
        WHERE p.id=?");
    $q->bind_param('i',$payId);$q->execute();
    $row=$q->get_result()->fetch_assoc();$q->close();
    if (!$row || $row['type']!=='RENEW_SCONFIG' ||
        $row['state']!=='processing_wallet') return ['status'=>'review',
            'reason'=>'original_renewal_claim_not_pending'];
    $raw=json_decode((string)($row['description']??''),true);
    $original=is_array($raw)?$raw:[];
    $name=(string)($original['remark']??'');
    $endpoint=(string)($row['panel_url']??'');
    $targetValid=(int)($row['server_id']??0)>0 &&
        (int)$row['server_id']===(int)($original['server_id']??0) &&
        (int)$row['server_id']===(int)($row['plan_server']??0) &&
        ($original['marzban']??null)===true &&
        $row['panel_type']==='marzban' &&
        preg_match('/^[a-zA-Z0-9_-]{1,100}$/D',$name)===1 &&
        achbotRecoveryEndpoint($endpoint)!==null;
    $status='not_verified';$observed=null;
    if ($targetValid) {
        $remote=achbotRecoveryStrictAccountGet($endpoint,(string)$row['panel_user'],
            (string)$row['panel_password'],$name);
        if (is_object($remote) && (string)($remote->username??'')===$name) {
            $status='current_native_observed_not_original_proof';
            $observed=['status'=>(string)($remote->status??''),
                'expire'=>achbotRecoveryExactInt($remote->expire??null),
                'bytes'=>achbotRecoveryExactInt($remote->data_limit??null)];
        }
    }
    return ['status'=>'review','reason'=>$targetValid?
        'original_marzban_mutation_unprovable':'original_marzban_target_changed',
        'pay_id'=>$payId,'order_id'=>(int)($row['order_id']??0),
        'price'=>(int)($row['price']??0),
        'original_days'=>(int)($row['day']??0),
        'original_volume'=>(int)($row['volume']??0),
        'plan_id'=>(int)$row['plan_id'],'server_id'=>(int)$row['server_id'],
        'remark'=>$name,'endpoint_sha256'=>$endpoint!==''?hash('sha256',rtrim($endpoint,'/')):null,
        'current_plan_days'=>(int)($row['current_plan_days']??0),
        'current_plan_volume'=>(int)($row['current_plan_volume']??0),
        'observation'=>$status,'current_native'=>$observed,
        'original_entitlement_snapshot'=>false,'local_finality_allowed'=>false];
}
