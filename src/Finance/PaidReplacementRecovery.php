<?php
/**
 * Issue #117: explicit staff-attested, one-time replacement of an uncertain
 * ORIGINAL paid Hosted account. The original 007 CREATE is immutable; a new
 * effect_kind='replacement' operation has its OWN committed 007 identity.
 * This module never treats 404/timeout as proof of remote absence and never
 * retries the original or a previously dispatched replacement POST.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/PaidAccountRecovery.php';
require_once __DIR__.'/../Admin/Access.php';

function achbotHostedReplacementSchemaReady(mysqli $db): bool {
    try {
        $q=$db->query('SELECT replaces_effect_id,reconciliation_reason,reconciliation_fingerprint,'.
            'reconciliation_observed_at,actor_userid FROM service_effects LIMIT 0');
        if (!$q) return false;
        $q->free();
        $q=$db->query("SHOW TABLE STATUS WHERE Name='service_effects'");
        if (!$q || strcasecmp((string)($q->fetch_assoc()['Engine']??''),'InnoDB')!==0) return false;
        $q=$db->query('SHOW INDEX FROM service_effects');$keys=[];
        while ($q && ($row=$q->fetch_assoc())) {
            if ((int)$row['Non_unique']===0 && $row['Sub_part']===null)
                $keys[$row['Key_name']][(int)$row['Seq_in_index']]=$row['Column_name'];
        }
        foreach (['uq_effect_pay_line'=>['pay_row_id','effect_kind','ordinal'],
                  'uq_effect_one_replacement'=>['replaces_effect_id']] as $name=>$columns) {
            if (!isset($keys[$name])) return false;
            ksort($keys[$name]);
            if (array_values($keys[$name])!==$columns) return false;
        }
        return true;
    } catch(Throwable $ignored) {return false;}
}

/** SQL evidence for an existing paid claim, including ORIGINAL rows even when
 * they are invalid or noncontiguous. The current target and payment are
 * strictly verified, never inferred from orphaned native accounts.
 */
function achbotHostedReconciliationSnapshot(mysqli $db,int $payId,bool $lock=false): ?array {
    if (!achbotHostedReplacementSchemaReady($db)) return null;
    $fund=achbotHostedOriginalFundingSnapshot($db,$payId,$lock);
    if (!$fund) return null;
    $pay=$fund['pay'];$count=$fund['count'];$suffix=$lock?' FOR UPDATE':'';
    $q=$db->prepare('SELECT * FROM service_effects WHERE pay_row_id=? ORDER BY ordinal,effect_kind'.$suffix);
    $q->bind_param('i',$payId);$q->execute();
    $rows=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
    $original=[];$replacement=[];
    foreach ($rows as $row) {
        $ordinal=(int)$row['ordinal'];$kind=(string)$row['effect_kind'];
        if ($ordinal<1 || $ordinal>$count ||
            !in_array($kind,['create','replacement'],true)) return null;
        if ($kind==='create') {
            if (isset($original[$ordinal])) return null;
            $original[$ordinal]=$row;
        } else {
            if (isset($replacement[$ordinal])) return null;
            $replacement[$ordinal]=$row;
        }
    }
    $anchor=$original[1]??null;
    if ($anchor!==null && ((int)$anchor['reserved_count']!==$count ||
        (int)$anchor['target_server_id']!==(int)$pay['server_id'] ||
        (int)$anchor['plan_id']!==(int)$pay['plan_id'])) return null;
    // No original first capacity owner: only a new *first* replacement may
    // acquire the entire unreserved count. Other rows cannot claim capacity.
    if ($anchor===null) {
        foreach ($original as $row) if ((int)$row['reserved_count']!==0) return null;
    }
    $first=$anchor??($replacement[1]??null);
    $lines=[];$seenOrders=[];
    for ($ordinal=1;$ordinal<=$count;$ordinal++) {
        $o=$original[$ordinal]??null;$r=$replacement[$ordinal]??null;
        if ($o!==null && !in_array((string)$o['state'],['dispatching','verified','local_recorded'],true)) return null;
        if ($o!==null && $o['order_id']!==null && $r!==null) return null;
        if ($r!==null) {
            if (!in_array((string)$r['state'],['dispatching','verified','local_recorded'],true) ||
                (int)($r['actor_userid']??0)<=0 ||
                !in_array((string)$r['reconciliation_reason'],
                    ['original_missing','original_unavailable','original_mismatch','original_invalid'],true) ||
                preg_match('/^[a-f0-9]{64}$/D',(string)$r['reconciliation_fingerprint'])!==1 ||
                !is_string($r['reconciliation_observed_at']) ||
                (int)$r['target_server_id']!==(int)$pay['server_id'] ||
                (int)$r['plan_id']!==(int)$pay['plan_id'] ||
                (int)$r['reserved_count']!==(int)($ordinal===1 && $anchor===null?$count:0) ||
                ($o===null ? $r['replaces_effect_id']!==null :
                    (int)$r['replaces_effect_id']!==(int)$o['id'])) return null;
        }
        $effective=$r??$o;
        if ($effective===null) {$lines[$ordinal]=null;continue;}
        $bytes=achbotRecoveryExactInt($effective['requested_bytes']);
        if (!achbotEffectValidUsername((string)$effective['remote_username']) ||
            preg_match('/^[a-f0-9]{32}$/D',(string)$effective['operation_id'])!==1 ||
            (int)$effective['requested_expire']<=time() || $bytes===null || $bytes<=0 ||
            $bytes%1073741824!==0 ||
            preg_match('/^[a-f0-9]{64}$/D',(string)$effective['request_sha256'])!==1 ||
            (int)$effective['target_server_id']!==(int)$pay['server_id'] ||
            (int)$effective['plan_id']!==(int)$pay['plan_id'] ||
            ($first!==null && ($effective['target_group_id']!==$first['target_group_id'] ||
                $effective['target_key']!==$first['target_key'] ||
                $effective['endpoint_sha256']!==$first['endpoint_sha256']))) {
            // An invalid original is reviewable but cannot be used as native
            // service evidence. Its independent replacement may still be valid.
            if ($r!==null || $o===null) return null;
            $lines[$ordinal]=null;
            continue;
        }
        if ((string)$effective['effect_kind']==='create' &&
            (int)$effective['reserved_count']!==($ordinal===1?$count:0)) {
            $lines[$ordinal]=null;continue;
        }
        if ($effective['state']==='local_recorded' && $effective['order_id']!==null) {
            $orderId=(int)$effective['order_id'];
            if ($orderId<=0 || isset($seenOrders[$orderId])) return null;
            $order=achbotOriginalBuyLinkedOrder($db,$pay,$effective,$ordinal,$count,$lock);
            if ($order===null) return null;
            $effective['_original_order']=$order;
            $seenOrders[$orderId]=true;
        } elseif ($effective['order_id']!==null) return null;
        // The original provider binding must still be usable. For invalid
        // originals no remotely guessed replacement identity is adopted.
        if ($pay['panel_type']==='marzban') {
            $t=achbotRecoveryTarget($db,$payId,$ordinal,(string)$effective['effect_kind']);
            if (!achbotRecoveryTargetValid($t,$payId,$ordinal)) {
                if ($r!==null) return null;
                $lines[$ordinal]=null;continue;
            }
        } else {
            try {
                achbotPgLoad();
                $account=achbotProviderAccountByEffect($db,(int)$effective['id']);
                $native=achbotPgEffect($db,(int)$effective['id']);
                $panel=$native && is_array($native['provider_snapshot']??null)
                    ?achbotProviderPanelForSnapshot($db,$native['provider_snapshot']):null;
                $current=achbotPgServerPanel($db,(int)$pay['server_id']);
                if (!$account || !$native || !$panel ||
                    $account['remote_username']!==$effective['remote_username'] ||
                    $account['operation_marker']!=='achbot:v1:'.$effective['operation_id'] ||
                    (int)$panel['id']!==(int)$current['id'] ||
                    $panel['instance_key']!==$current['instance_key'] ||
                    !hash_equals((string)$account['endpoint_sha256'],
                        hash('sha256',rtrim((string)$current['api_url'],'/'))))
                    throw new RuntimeException('reconciliation_pg_account_identity_unavailable');
            } catch(Throwable $ignored) {
                if ($r!==null) return null;
                $lines[$ordinal]=null;continue;
            }
        }
        $lines[$ordinal]=$effective;
    }
    return $fund+['original'=>$original,'replacement'=>$replacement,
        'lines'=>$lines,'history'=>$rows,'count'=>$count];
}

/** Only original/future replacement identity, not transient GET state. */
function achbotHostedReconciliationPin(array $snapshot): string {
    $rows=[];
    foreach ($snapshot['history'] as $row) {
        $copy=[];
        foreach (['id','effect_kind','ordinal','operation_id','reserved_count',
            'order_id','target_server_id','target_group_id','target_key',
            'endpoint_sha256','plan_id','remote_username','requested_expire',
            'requested_bytes','request_sha256','replaces_effect_id',
            'reconciliation_reason','reconciliation_fingerprint',
            'actor_userid','provider_snapshot','provider_mutation_key',
            'provider_mutation_snapshot'] as $key) $copy[$key]=(string)($row[$key]??'');
        $rows[]=$copy;
    }
    return hash('sha256',json_encode([
        achbotPaymentEvidencePayFingerprint($snapshot['pay']),
        achbotPaymentEvidenceQuoteFingerprint($snapshot['attempt']),
        $snapshot['count'],$rows,achbotOriginalBuyLinkedOrderPins($snapshot)
    ],JSON_THROW_ON_ERROR));
}

/** A genuinely missing or unusable original line requires a distinct human
 * decision. An active, authenticated matching original cannot be replaced.
 */
function achbotHostedReplacementEvidence(mysqli $db,int $payId,int $ordinal,
    ?callable $marzbanRead=null): ?array {
    $snap=achbotHostedReconciliationSnapshot($db,$payId);
    if (!$snap || $ordinal<1 || $ordinal>$snap['count'] ||
        isset($snap['replacement'][$ordinal])) return null;
    $original=$snap['original'][$ordinal]??null;
    if ($original && $original['order_id']!==null) return null;
    if ($ordinal>1 && !isset($snap['original'][1]) &&
        !isset($snap['replacement'][1])) return null;
    // A missing first capacity record is special: do not guess that another
    // existing native account is an original, even when usernames coincide.
    $reason='original_missing';$status='missing';
    if ($original!==null) {
        if ($snap['lines'][$ordinal]===null) {
            $reason='original_invalid';$status='invalid';
        } else {
            require_once __DIR__.'/RecoveryTransport.php';
            $reader=$marzbanRead??'achbotRecoveryStrictAccountGet';
            try {$check=achbotRecoveryInspectPaidLine($db,$payId,$ordinal,$reader);}
            catch(Throwable $ignored) {$check=['status'=>'remote_unavailable'];}
            $status=(string)($check['status']??'remote_unavailable');
            if ($status==='matching_observation') return null;
            $reason=$status==='remote_mismatch'?'original_mismatch':'original_unavailable';
        }
    }
    $after=achbotHostedReconciliationSnapshot($db,$payId);
    if (!$after || !hash_equals(achbotHostedReconciliationPin($snap),
        achbotHostedReconciliationPin($after))) return null;
    return ['snapshot'=>$after,'reason'=>$reason,'status'=>$status,
        'stamp'=>achbotHostedReconciliationPin($after)];
}

function achbotHostedReplacementToken(int $actor,int $payId,int $ordinal,
    int $minute,string $stamp,string $reason): string {
    return substr(hash_hmac('sha256','paid-replacement:v1|'.$actor.'|'.$payId.'|'.
        $ordinal.'|'.$minute.'|'.$stamp.'|'.$reason,achbotPaymentEvidenceKey()),0,16);
}
function achbotHostedReplacementChallenge(int $actor,int $payId,int $ordinal,
    array $evidence): string {
    $minute=intdiv(time(),60);
    return $minute.'_'.achbotHostedReplacementToken($actor,$payId,$ordinal,
        $minute,$evidence['stamp'],$evidence['reason']);
}
function achbotHostedReplacementChallengeValid(int $actor,int $payId,int $ordinal,
    string $challenge,array $evidence): bool {
    if ($actor<=0 || preg_match('/^([1-9][0-9]{0,10})_([a-f0-9]{16})$/D',$challenge,$m)!==1) return false;
    $minute=(int)$m[1];$now=intdiv(time(),60);
    return $minute<=$now && $minute>=$now-2 &&
        hash_equals(achbotHostedReplacementToken($actor,$payId,$ordinal,
            $minute,$evidence['stamp'],$evidence['reason']),$m[2]);
}

/** A signed explicit confirmation is valid only for the just-observed
 * payment/original history; a second staff actor cannot borrow the button.
 * SQL row locks and unique replacement keys are the final race authority.
 */
function achbotHostedCreateReconciledReplacement(
    mysqli $db,int $payId,int $ordinal,int $actor,int $primary,string $challenge,
    ?callable $marzbanRead=null
): array {
    require_once __DIR__.'/../Purchase/Core.php';
    achbotEffectRequireAutocommit($db);
    if ($actor<=0 || $primary<=0 ||
        !achbotAdminCanFor($db,$actor,$primary,'payments'))
        throw new RuntimeException('replacement_actor_not_permitted');
    $evidence=achbotHostedReplacementEvidence($db,$payId,$ordinal,$marzbanRead);
    if (!$evidence || !achbotHostedReplacementChallengeValid($actor,$payId,$ordinal,
            $challenge,$evidence))
        throw new RuntimeException('replacement_evidence_expired_or_invalid');
    $snapshot=$evidence['snapshot'];$pay=$snapshot['pay'];
    $server=(int)$pay['server_id'];$planId=(int)$pay['plan_id'];
    $original=$snapshot['original'][$ordinal]??null;
    // Preserve the paid entitlement (including a custom day/volume pair).
    // Requiring agreement with an existing original journal prevents a later
    // plan edit from silently substituting a different paid subscription.
    $plan=getPlanDetails($db,$planId);$detail=$plan['plan']??null;
    $custom=(int)($pay['volume']??0)!==0 || (int)($pay['day']??0)!==0;
    $volume=$custom?(int)$pay['volume']:(int)($detail['volume']??0);
    $days=$custom?(int)$pay['day']:(int)($detail['days']??0);
    if (!is_array($detail) || (int)($detail['server_id']??0)!==$server ||
        (int)($detail['inbound_id']??-1)!==0 || $volume<=0 ||
        $days<=0 || $days>36500 ||
        $volume>intdiv(PHP_INT_MAX,1073741824))
        throw new RuntimeException('replacement_plan_invalid');
    foreach ($snapshot['original'] as $row) {
        $bytes=achbotRecoveryExactInt($row['requested_bytes']);
        if ($bytes!==null && $bytes>0 &&
            $bytes%1073741824===0 &&
            $bytes!==$volume*1073741824)
            throw new RuntimeException('replacement_original_entitlement_changed');
    }
    // One original payment, no second merchant settlement/wallet debit.
    // The 007 reservation and optional capacity decrement both COMMIT before
    // ANY remote POST. An unknown COMMIT never triggers an outbound request.
    $effectId=0;$op='';$endpoint='';$targetKey='';$group=null;$credentials='';
    $name='hosted_repl_'.$payId.'_'.$ordinal;
    if (!achbotEffectValidUsername($name))
        throw new RuntimeException('replacement_username_invalid');
    $expiry=time()+$days*86400;$bytes=$volume*1073741824;
    $request=['user_id'=>(int)$pay['user_id'],
        'server_type'=>(string)$pay['panel_type'],
        'server_id'=>$server,'inbound_id'=>0,'plan_id'=>$planId,
        'remark'=>$name,'volume'=>$volume,'days'=>$days,
        'uniqid'=>generateRandomString(42,(string)($detail['protocol']??'')),
        'protocol'=>(string)($detail['protocol']??''),'port'=>0,
        'expire_microdate'=>0,'net_type'=>(string)($detail['type']??'normal'),
        'rahgozar'=>(int)($detail['rahgozar']??0),
        'limitip'=>(int)($detail['limitip']??0)];
    $started=false;
    try {
        if (!$db->begin_transaction()) throw new RuntimeException('replacement_reserve_begin');
        $started=true;
        achbotEffectLockTargetRows($db,$server,$planId,$payId);
        if (!achbotAdminCanLocked($db,$actor,$primary,'payments'))
            throw new RuntimeException('replacement_staff_permission_revoked');
        $locked=achbotHostedReconciliationSnapshot($db,$payId,true);
        if (!$locked ||
            !hash_equals($evidence['stamp'],achbotHostedReconciliationPin($locked)) ||
            isset($locked['replacement'][$ordinal]) ||
            (($locked['original'][$ordinal]['id']??null)!==($original['id']??null)))
            throw new RuntimeException('replacement_original_evidence_changed');
        $fund=$locked['pay'];$count=$locked['count'];
        $q=$db->prepare('SELECT i.panel_group_id,c.type AS panel_type,c.panel_url,'.
            'c.username AS panel_username,c.password AS panel_password,'.
            's.server_id AS plan_server,s.inbound_id AS plan_inbound,'.
            'g.id AS valid_group FROM server_info i '.
            'JOIN server_config c ON c.id=i.id JOIN server_plans s ON s.id=? '.
            'LEFT JOIN setting g ON g.id=i.panel_group_id '.
            "AND g.type='ACHBOT_MARZBAN_PANEL_GROUP' WHERE i.id=? FOR UPDATE");
        $q->bind_param('ii',$planId,$server);$q->execute();
        $targetRow=$q->get_result()->fetch_assoc();$q->close();
        if (!$targetRow || (int)$targetRow['plan_server']!==$server ||
            (int)$targetRow['plan_inbound']!==0 ||
            $targetRow['panel_type']!==$fund['panel_type'] ||
            ($fund['panel_type']==='marzban' &&
                $targetRow['panel_group_id']!==null && $targetRow['valid_group']===null))
            throw new RuntimeException('replacement_target_changed');
        $target=achbotEffectTargetIdentity($db,$server,$planId,$payId,$targetRow);
        $group=$target['group'];$endpoint=$target['endpoint'];
        $targetKey=$target['key'];$credentials=$target['credentials'];
        // A first original CREATE already consumed all purchase inventory.
        // Else the FIRST replacement owns the entire count, exactly once.
        $capacityOriginal=$locked['original'][1]??null;
        $firstReplacement=$locked['replacement'][1]??null;
        if ($capacityOriginal!==null || $firstReplacement!==null) {
            $anchor=$capacityOriginal??$firstReplacement;
            if ((int)$anchor['reserved_count']!==$count ||
                (int)$anchor['target_server_id']!==$server ||
                (int)$anchor['plan_id']!==$planId ||
                ($anchor['target_group_id']===null?null:(int)$anchor['target_group_id'])!==$group ||
                !hash_equals((string)$anchor['endpoint_sha256'],$endpoint) ||
                (string)$anchor['target_key']!==$targetKey)
                throw new RuntimeException('replacement_capacity_owner_changed');
            $reserve=0;
        } else {
            if ($ordinal!==1)
                throw new RuntimeException('replacement_first_capacity_unavailable');
            $reserve=$count;
            $q=$db->prepare('UPDATE server_info SET ucount=ucount-? '.
                'WHERE id=? AND active=1 AND state=1 AND ucount>=?');
            $q->bind_param('iii',$count,$server,$count);
            $ok=$q->execute() && $q->affected_rows===1;$q->close();
            if (!$ok) throw new RuntimeException('replacement_capacity_insufficient');
        }
        $op=bin2hex(random_bytes(16));
        $fingerprint=hash('sha256',json_encode([
            $payId,$ordinal,$planId,$server,$group,$targetKey,$endpoint,
            $name,$expiry,$bytes],JSON_THROW_ON_ERROR));
        $reconciliation=hash('sha256',json_encode([
            'paid-replacement:v1',$evidence['stamp'],$evidence['reason'],
            $actor,$payId,$ordinal,$original['id']??null,
            $count,$targetKey,$endpoint,$bytes,$days
        ],JSON_THROW_ON_ERROR));
        $origId=$original===null?null:(int)$original['id'];
        $reason=$evidence['reason'];
        $q=$db->prepare("INSERT INTO service_effects
            (pay_row_id,ordinal,operation_id,effect_kind,target_server_id,target_group_id,
             target_key,endpoint_sha256,plan_id,remote_username,requested_expire,
             requested_bytes,request_sha256,reserved_count,state,actor_userid,
             replaces_effect_id,reconciliation_reason,reconciliation_fingerprint,
             reconciliation_observed_at)
            VALUES (?,?,?,'replacement',?,?,?,?,?,?,?,?,?,?,'dispatching',?,?,?,?,UTC_TIMESTAMP())");
        $q->bind_param('iisiissisiisiiiss',$payId,$ordinal,$op,$server,$group,
            $targetKey,$endpoint,$planId,$name,$expiry,$bytes,$fingerprint,
            $reserve,$actor,$origId,$reason,$reconciliation);
        if (!$q->execute() || $q->affected_rows!==1)
            throw new RuntimeException('replacement_reservation_unconfirmed');
        $effectId=(int)$q->insert_id;$q->close();
        if ($effectId<=0) throw new RuntimeException('replacement_reservation_id_missing');
        achbotProviderReserveEffect($db,$effectId,$target,$name,$op,$expiry,$bytes);
        if (!$db->commit()) throw new RuntimeException('replacement_reserve_commit_unknown');
        $started=false;
    } catch(Throwable $error) {
        if ($started) try {$db->rollback();}catch(Throwable $ignored){}
        throw $error;
    }
    [$remote,$remark,$port,$id]=achbotEffectDispatchReservedAccount(
        $db,$effectId,$server,$group,$endpoint,$name,$volume,$days,
        $planId,$op,$expiry,$bytes,$request,$credentials);
    if (!is_object($remote) || ($remote->success??false)!==true ||
        $remark!==$name || (int)$id!==$effectId)
        throw new RuntimeException('replacement_dispatch_needs_review');
    return ['effect_id'=>$effectId,'ordinal'=>$ordinal,'count'=>$snapshot['count'],
        'reason'=>$evidence['reason']];
}

function achbotHostedHasReplacement(mysqli $db,int $payId): bool {
    if ($payId<=0 || !achbotHostedReplacementSchemaReady($db)) return false;
    $q=$db->prepare("SELECT id FROM service_effects WHERE pay_row_id=?
        AND effect_kind='replacement' LIMIT 1");
    $q->bind_param('i',$payId);$q->execute();
    $exists=$q->get_result()->num_rows===1;$q->close();
    return $exists;
}
function achbotHostedReconciledBuyRecoverable(mysqli $db,int $payId): bool {
    try {
        if (!achbotHostedHasReplacement($db,$payId)) return false;
        $snap=achbotHostedReconciliationSnapshot($db,$payId);
        return $snap && count(array_filter($snap['lines'],
            static fn($e): bool => is_array($e)))===$snap['count'];
    } catch(Throwable $ignored) {return false;}
}
/** Preview only the first unresolved original ordinal: never guess the
 * existence of a remote account, suppress original history or auto-create.
 */
function achbotHostedReplacementReviewOrdinal(mysqli $db,int $payId): ?int {
    try {
        $s=achbotHostedReconciliationSnapshot($db,$payId);
        if (!$s) return null;
        foreach ($s['lines'] as $ordinal=>$line) {
            if (isset($s['replacement'][$ordinal])) continue;
            if (isset($s['original'][$ordinal]) &&
                $s['original'][$ordinal]['order_id']!==null) continue;
            if ($ordinal>1 && !isset($s['original'][1]) &&
                !isset($s['replacement'][1])) continue;
            return $ordinal;
        }
    } catch(Throwable $ignored) {}
    return null;
}
