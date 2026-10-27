<?php
require_once __DIR__ . '/../messages/loader.php'; // ACHBOT_TEXT_CATALOG

/**
 * Legacy bulk gift worker, source-only remediation for PR #66 R1.
 *
 * A negative gift_list.offset is a durable, fail-closed operator hold:
 *   held recipient index = -offset - 1.
 * Its exact recipient identity and requested units are in a setting row with
 * type ACHBOT_GIFT_HOLD_<gift id>. Never auto-restart a held external edit.
 * No schema changes and no unattended provider reconciliation are performed.
 */
include_once __DIR__ . '/../baseInfo.php';
include_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/Subscription/GiftBatch.php';

$stmt=$connection->prepare('SELECT * FROM gift_list ORDER BY id LIMIT 1');
if (!$stmt || !$stmt->execute()) return;
$gift=$stmt->get_result()->fetch_assoc();$stmt->close();
if (!$gift) return;

$rowId=(int)$gift['id'];
$serverId=(int)$gift['server_id'];
$volume=(int)$gift['volume'];
$day=(int)$gift['day'];
$offset=(int)$gift['offset'];
$holdType='ACHBOT_GIFT_HOLD_'.$rowId;
$rosterType='ACHBOT_GIFT_ROSTER_'.$rowId;
$partType='ACHBOT_GIFT_ROSTER_PART_'.$rowId;
$rosterId=0;
$rosterMeta=null;
$rosterNames=[];
$receipt=achbotGiftReadProvenance($connection,$gift);
$target=null; // Ephemeral credentials, never journaled.

// Even if an operator manually resets the offset, an unresolved hold record
// prevents a second external edit. Only a separately audited reconciliation
// can safely clear both the hold and its corresponding cursor.
$check=$connection->prepare('SELECT id FROM setting WHERE type=? LIMIT 1');
if (!$check) return;
$check->bind_param('s',$holdType);
if (!$check->execute()) { $check->close(); return; }
$unresolved=(bool)$check->get_result()->fetch_assoc();
$check->close();
if ($offset<0 || $unresolved) return;

// Atomic gift/snapshot/hold transitions depend on InnoDB. Do not make
// provider calls on an installed tenant whose original tables were converted
// to a nontransactional engine.
$engines=$connection->query(
    "SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES
     WHERE TABLE_SCHEMA=DATABASE()
       AND TABLE_NAME IN ('gift_list','setting','orders_list')"
);
if (!$engines || $engines->num_rows!==3) {
    sendMessage(\achbotMessage('jobs.gift2all.message.0addd1b9d4'),
                null,null,$admin);
    return;
}
foreach ($engines->fetch_all(MYSQLI_ASSOC) as $table) {
    if (strtoupper((string)$table['ENGINE'])!=='INNODB') {
        sendMessage(\achbotMessage('jobs.gift2all.message.53c655af49'),
                    null,null,$admin);
        return;
    }
}
$engines->free();

$notifyHold=static function(string $remark,int $index,string $reason) use (
    $admin,$rowId,$serverId,$holdType
): void {
    $who=$remark===''?\achbotMessage('jobs.gift2all.invalid_job_parameters.084a81c66e'):$remark;
    // Exact row/index/user are operator-only. Never announce unverified
    // provider effects to the customer or expose provider credentials.
    sendMessage(
        \achbotMessage('jobs.gift2all.message.be772b3ac0', ['rowId' => $rowId, 'serverId' => $serverId]).
        \achbotMessage('jobs.gift2all.message.90aec44bc4', ['index' => $index, 'who' => $who, 'reason' => $reason]).
        \achbotMessage('jobs.gift2all.message.d0b68f4613', ['holdType' => $holdType]),
        null,null,$admin
    );
};


/**
 * R1-A: Freeze initial batch membership by Marzban username, not numeric
 * position in a later /api/users response. Store a compact durable manifest
 * plus bounded chunks in original setting rows (TEXT <= 65535 bytes).
 *
 * The original setting.type is NOT unique; gift_list.id is. Serialize initial
 * creation, cursor updates and final deletion through its InnoDB row lock.
 * A started legacy job without a roster cannot be safely reconstructed.
 */
$freezeRoster=static function(array $members) use (
    $connection,$rowId,$serverId,$volume,$day,$rosterType,$partType,$offset,$receipt
): array {
    if (!$connection->begin_transaction())
        throw new RuntimeException('gift_roster_begin_failed');
    try {
        $job=$connection->prepare('SELECT server_id,volume,day,`offset` FROM gift_list WHERE id=? FOR UPDATE');
        if (!$job) throw new RuntimeException('gift_roster_job_prepare');
        $job->bind_param('i',$rowId);
        $ok=$job->execute();$live=$ok?$job->get_result()->fetch_assoc():null;
        $job->close();
        if (!$live || (int)$live['server_id']!==$serverId ||
            (int)$live['volume']!==$volume || (int)$live['day']!==$day ||
            (int)$live['offset']!==$offset)
            throw new RuntimeException('gift_roster_job_changed');

        $stmt=$connection->prepare('SELECT id,value FROM setting WHERE type=? LIMIT 2');
        if (!$stmt) throw new RuntimeException('gift_roster_lookup_prepare');
        $stmt->bind_param('s',$rosterType);
        $ok=$stmt->execute();$existing=$ok?$stmt->get_result()->fetch_all(MYSQLI_ASSOC):null;
        $stmt->close();
        if ($existing===null || count($existing)>1)
            throw new RuntimeException('gift_roster_ambiguous');

        if (!$existing) {
            // Pre-upgrade continuation: the earlier completed usernames are
            // unknowable. A new list MUST NOT be used for its old offset.
            if ($receipt===null || $offset!==0)
                throw new RuntimeException('gift_legacy_roster_missing');
            $chunks=[];$block=[];$bytes=2;
            $store=static function(array $names) use ($connection,$partType,&$chunks): void {
                if (!$names) return;
                $payload=json_encode($names,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
                if (strlen($payload)>32000)
                    throw new RuntimeException('gift_roster_part_too_large');
                $stmt=$connection->prepare('INSERT INTO setting (type,value) VALUES (?,?)');
                if (!$stmt) throw new RuntimeException('gift_roster_part_prepare');
                $stmt->bind_param('ss',$partType,$payload);
                $ok=$stmt->execute();$id=(int)$stmt->insert_id;$stmt->close();
                if (!$ok || $id<=0) throw new RuntimeException('gift_roster_part_write_failed');
                $chunks[]=['id'=>$id,'count'=>count($names),'hash'=>hash('sha256',$payload)];
            };
            foreach ($members as $name) {
                $length=strlen(json_encode($name,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))+1;
                if ($length>32000) throw new RuntimeException('gift_roster_member_too_large');
                if ($block && $bytes+$length>32000) {
                    $store($block);$block=[];$bytes=2;
                }
                $block[]=$name;$bytes+=$length;
            }
            $store($block);
            $meta=[
                'version'=>1,'gift_id'=>$rowId,'server_id'=>$serverId,
                'volume_mb'=>$volume,'days'=>$day,
                'membership'=>'first_successful_cron_fetch','cursor'=>0,
                'count'=>count($members),'chunks'=>$chunks,
                'target'=>$receipt['target'],'created_at'=>gmdate('c')
            ];
            $payload=json_encode($meta,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            if (strlen($payload)>60000)
                throw new RuntimeException('gift_roster_manifest_too_large');
            $stmt=$connection->prepare('INSERT INTO setting (type,value) VALUES (?,?)');
            if (!$stmt) throw new RuntimeException('gift_roster_manifest_prepare');
            $stmt->bind_param('ss',$rosterType,$payload);
            $ok=$stmt->execute();$id=(int)$stmt->insert_id;$stmt->close();
            if (!$ok || $id<=0) throw new RuntimeException('gift_roster_manifest_failed');
        } else {
            $id=(int)$existing[0]['id'];
            $meta=json_decode((string)$existing[0]['value'],true);
        }
        if (!is_array($meta) || $receipt===null ||
            ($meta['target']??null)!==$receipt['target'] ||
            ($meta['version']??null)!==1 ||
            ($meta['gift_id']??null)!==$rowId ||
            ($meta['server_id']??null)!==$serverId ||
            ($meta['volume_mb']??null)!==$volume ||
            ($meta['days']??null)!==$day ||
            !is_int($meta['cursor']??null) || $meta['cursor']!==$offset ||
            !is_int($meta['count']??null) || $meta['count']<0 ||
            $meta['count']>=2147483647 ||
            !is_array($meta['chunks']??null))
            throw new RuntimeException('gift_roster_manifest_invalid');

        $frozen=[];$seen=[];
        foreach ($meta['chunks'] as $chunk) {
            if (!is_array($chunk) || !is_int($chunk['id']??null) ||
                $chunk['id']<=0 || !is_int($chunk['count']??null) ||
                $chunk['count']<=0 || !is_string($chunk['hash']??null))
                throw new RuntimeException('gift_roster_chunk_metadata_invalid');
            $partId=$chunk['id'];
            $stmt=$connection->prepare('SELECT type,value FROM setting WHERE id=?');
            if (!$stmt) throw new RuntimeException('gift_roster_chunk_read_prepare');
            $stmt->bind_param('i',$partId);
            $ok=$stmt->execute();$part=$ok?$stmt->get_result()->fetch_assoc():null;
            $stmt->close();
            if (!$part || $part['type']!==$partType ||
                !hash_equals($chunk['hash'],hash('sha256',(string)$part['value'])))
                throw new RuntimeException('gift_roster_chunk_modified');
            $names=json_decode((string)$part['value'],true);
            if (!is_array($names) || !array_is_list($names) ||
                count($names)!==$chunk['count'])
                throw new RuntimeException('gift_roster_chunk_invalid');
            foreach ($names as $name) {
                if (!is_string($name) || $name==='' || strlen($name)>400 ||
                    isset($seen[$name]))
                    throw new RuntimeException('gift_roster_member_invalid');
                $seen[$name]=true;$frozen[]=$name;
            }
        }
        if (count($frozen)!==$meta['count'])
            throw new RuntimeException('gift_roster_count_mismatch');
        if ($connection->commit()!==true)
            throw new RuntimeException('gift_roster_commit_unknown');
        return [$id,$meta,$frozen];
    } catch (Throwable $e) {
        try { $connection->rollback(); } catch (Throwable $ignored) {}
        throw $e;
    }
};

$reserve=static function(int $index,string $remark,string $reason) use (
    $connection,$rowId,$serverId,$volume,$day,$holdType,$rosterType,&$rosterId,$receipt
): array {
    if ($index<0 || $index>=2147483647)
        throw new RuntimeException('gift_cursor_out_of_range');
    $held=-$index-1;
    $details=json_encode([
        'gift_id'=>$rowId,'server_id'=>$serverId,'recipient_index'=>$index,
        'username'=>$remark,'volume_mb'=>$volume,'days'=>$day,
        'stage'=>'before_external_effect','reason'=>$reason,
        'created_at'=>gmdate('c')
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    if (!$connection->begin_transaction())
        throw new RuntimeException('gift_hold_begin_failed');
    try {
        // The gift row (unique PK) serializes all Cron workers. This remains
        // safe even though setting.type lacks a UNIQUE index.
        $job=$connection->prepare('SELECT `offset` FROM gift_list WHERE id=? FOR UPDATE');
        if (!$job) throw new RuntimeException('gift_reserve_job_prepare');
        $job->bind_param('i',$rowId);
        $ok=$job->execute();$live=$ok?$job->get_result()->fetch_assoc():null;
        $job->close();
        if (!$live || (int)$live['offset']!==$index)
            throw new RuntimeException('gift_reserve_cursor_changed');
        if ($reason==='pending_service_edit') {
            $latest=achbotGiftReadProvenance($connection,[
                'id'=>$rowId,'server_id'=>$serverId,'volume'=>$volume,'day'=>$day
            ]);
            if ($receipt===null || $latest===null || $latest!==$receipt ||
                !achbotGiftTargetMatches($connection,$serverId,$receipt['target']))
                throw new RuntimeException('gift_reserve_target_or_provenance_changed');
            if ($rosterId<=0)
                throw new RuntimeException('gift_reserve_roster_missing');
            $state=$connection->prepare('SELECT value FROM setting WHERE id=? AND type=? FOR UPDATE');
            if (!$state) throw new RuntimeException('gift_reserve_roster_prepare');
            $state->bind_param('is',$rosterId,$rosterType);
            $ok=$state->execute();$entry=$ok?$state->get_result()->fetch_assoc():null;
            $state->close();
            $roster=$entry?json_decode((string)$entry['value'],true):null;
            if (!is_array($roster) || ($roster['cursor']??null)!==$index)
                throw new RuntimeException('gift_reserve_roster_changed');
        }
        $check=$connection->prepare('SELECT id FROM setting WHERE type=? LIMIT 1');
        if (!$check) throw new RuntimeException('gift_hold_check_prepare');
        $check->bind_param('s',$holdType);
        $ok=$check->execute();$existing=$ok?$check->get_result()->fetch_assoc():true;
        $check->close();
        if ($existing) throw new RuntimeException('gift_hold_already_exists');

        $stmt=$connection->prepare('UPDATE gift_list SET `offset`=? WHERE id=? AND `offset`=?');
        if (!$stmt) throw new RuntimeException('gift_hold_prepare');
        $stmt->bind_param('iii',$held,$rowId,$index);
        $ok=$stmt->execute();$count=$stmt->affected_rows;$stmt->close();
        if (!$ok || $count!==1) throw new RuntimeException('gift_hold_cursor_changed');

        $stmt=$connection->prepare('INSERT INTO setting (type,value) VALUES (?,?)');
        if (!$stmt) throw new RuntimeException('gift_hold_journal_prepare');
        $stmt->bind_param('ss',$holdType,$details);
        $ok=$stmt->execute();$holdId=(int)$stmt->insert_id;$stmt->close();
        if (!$ok || $holdId<=0) throw new RuntimeException('gift_hold_journal_failed');
        if ($connection->commit()!==true)
            throw new RuntimeException('gift_hold_commit_unconfirmed');
        return [$held,$holdId];
    } catch (Throwable $e) {
        try { $connection->rollback(); } catch (Throwable $ignored) {}
        // An uncertain COMMIT may have made this hold durable. No external
        // request is permitted if the hold acknowledgement was not confirmed.
        throw $e;
    }
};

$finish=static function(
    int $before,?int $holdId,int $next,?array $order,?int $absoluteExpire
) use ($connection,$rowId,$serverId,$holdType,$rosterType,&$rosterId,$receipt): void {
    if ($rosterId<=0) throw new RuntimeException('gift_roster_missing_at_finish');
    $index=$before<0 ? -$before-1 : $before;
    if ($next!==$index+1) throw new RuntimeException('gift_finish_bad_progress');
    if (!$connection->begin_transaction())
        throw new RuntimeException('gift_finish_begin_failed');
    try {
        $job=$connection->prepare('SELECT `offset` FROM gift_list WHERE id=? FOR UPDATE');
        if (!$job) throw new RuntimeException('gift_finish_job_prepare');
        $job->bind_param('i',$rowId);
        $ok=$job->execute();$live=$ok?$job->get_result()->fetch_assoc():null;
        $job->close();
        if (!$live || (int)$live['offset']!==$before)
            throw new RuntimeException('gift_finish_job_changed');
        $state=$connection->prepare('SELECT value FROM setting WHERE id=? AND type=? FOR UPDATE');
        if (!$state) throw new RuntimeException('gift_finish_roster_prepare');
        $state->bind_param('is',$rosterId,$rosterType);
        $ok=$state->execute();$entry=$ok?$state->get_result()->fetch_assoc():null;
        $state->close();
        $roster=$entry?json_decode((string)$entry['value'],true):null;
        if (!is_array($roster) || $receipt===null ||
            ($roster['target']??null)!==$receipt['target'] ||
            ($roster['cursor']??null)!==$index ||
            !is_int($roster['count']??null) || $next>$roster['count'])
            throw new RuntimeException('gift_finish_roster_changed');

        // Volume-only gifts have no expiry UPDATE, but their original local
        // account/order identity must still exist at completion. Do not emit
        // success for a deleted/reassigned order after the remote increment.
        if ($order!==null && $absoluteExpire===null) {
            $stmt=$connection->prepare(
                'SELECT id FROM orders_list WHERE id=? AND userid=? AND server_id=? AND remark=? AND expire_date=? FOR UPDATE');
            if (!$stmt) throw new RuntimeException('gift_volume_identity_prepare');
            $oid=(int)$order['id'];$uid=(int)$order['userid'];
            $originalExpire=(int)$order['expire_date'];
            $remark=(string)$order['remark'];
            $stmt->bind_param('iiisi',$oid,$uid,$serverId,$remark,$originalExpire);
            $ok=$stmt->execute();$existing=$ok?$stmt->get_result()->fetch_all(MYSQLI_ASSOC):null;
            $stmt->close();
            if ($existing===null || count($existing)!==1)
                throw new RuntimeException('gift_volume_local_identity_unconfirmed');
        }
        if ($order!==null && $absoluteExpire!==null) {
            if ($absoluteExpire<=0 || $absoluteExpire>2147483647)
                throw new RuntimeException('gift_expiry_unrepresentable');
            $stmt=$connection->prepare(
                'UPDATE orders_list SET expire_date=? WHERE id=? AND userid=? AND server_id=? AND remark=? AND expire_date=?');
            if (!$stmt) throw new RuntimeException('gift_local_update_prepare');
            $id=(int)$order['id'];$uid=(int)$order['userid'];
            $remark=(string)$order['remark'];
            $beforeExpire=(int)$order['expire_date'];
            $stmt->bind_param('iiiisi',$absoluteExpire,$id,$uid,$serverId,$remark,$beforeExpire);
            $ok=$stmt->execute();$count=$stmt->affected_rows;$stmt->close();
            if (!$ok || $count!==1)
                throw new RuntimeException('gift_local_update_unconfirmed');
        }

        $stmt=$connection->prepare('UPDATE gift_list SET `offset`=? WHERE id=? AND `offset`=?');
        if (!$stmt) throw new RuntimeException('gift_finish_cursor_prepare');
        $stmt->bind_param('iii',$next,$rowId,$before);
        $ok=$stmt->execute();$count=$stmt->affected_rows;$stmt->close();
        if (!$ok || $count!==1)
            throw new RuntimeException('gift_finish_cursor_unconfirmed');

        // Durable cursor CAS is transactional with the local order and gift
        // progress. Manual offset reset alone cannot repeat completed members.
        $roster['cursor']=$next;
        $encoded=json_encode($roster,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        $stmt=$connection->prepare('UPDATE setting SET value=? WHERE id=? AND type=?');
        if (!$stmt) throw new RuntimeException('gift_finish_roster_update_prepare');
        $stmt->bind_param('sis',$encoded,$rosterId,$rosterType);
        $ok=$stmt->execute();$count=$stmt->affected_rows;$stmt->close();
        if (!$ok || $count!==1)
            throw new RuntimeException('gift_finish_roster_update_unconfirmed');

        if ($holdId!==null) {
            $stmt=$connection->prepare('DELETE FROM setting WHERE id=? AND type=?');
            if (!$stmt) throw new RuntimeException('gift_finish_hold_prepare');
            $stmt->bind_param('is',$holdId,$holdType);
            $ok=$stmt->execute();$count=$stmt->affected_rows;$stmt->close();
            if (!$ok || $count!==1)
                throw new RuntimeException('gift_finish_hold_unconfirmed');
        }
        if ($connection->commit()!==true)
            throw new RuntimeException('gift_finish_commit_unconfirmed');
    } catch (Throwable $e) {
        try { $connection->rollback(); } catch (Throwable $ignored) {}
        throw $e;
    }
};

$closeRoster=static function(int $id,array $meta,int $count) use (
    $connection,$rowId,$rosterType,$partType,$holdType
): bool {
    if (!$connection->begin_transaction()) return false;
    try {
        $job=$connection->prepare('SELECT `offset` FROM gift_list WHERE id=? FOR UPDATE');
        if (!$job) throw new RuntimeException('gift_close_job_prepare');
        $job->bind_param('i',$rowId);
        $ok=$job->execute();$live=$ok?$job->get_result()->fetch_assoc():null;
        $job->close();
        if (!$live || (int)$live['offset']!==$count)
            throw new RuntimeException('gift_close_cursor_changed');
        $stmt=$connection->prepare('SELECT value FROM setting WHERE id=? AND type=? FOR UPDATE');
        if (!$stmt) throw new RuntimeException('gift_close_roster_prepare');
        $stmt->bind_param('is',$id,$rosterType);
        $ok=$stmt->execute();$entry=$ok?$stmt->get_result()->fetch_assoc():null;
        $stmt->close();
        $record=$entry?json_decode((string)$entry['value'],true):null;
        if (!is_array($record) || ($record['cursor']??null)!==$count ||
            ($record['count']??null)!==$count ||
            ($record['chunks']??null)!==$meta['chunks'])
            throw new RuntimeException('gift_close_roster_changed');
        $stmt=$connection->prepare('SELECT id FROM setting WHERE type=? LIMIT 1');
        if (!$stmt) throw new RuntimeException('gift_close_hold_prepare');
        $stmt->bind_param('s',$holdType);
        $ok=$stmt->execute();$hold=$ok?$stmt->get_result()->fetch_assoc():true;
        $stmt->close();
        if ($hold) throw new RuntimeException('gift_close_active_hold');
        foreach ($meta['chunks'] as $part) {
            $stmt=$connection->prepare('DELETE FROM setting WHERE id=? AND type=?');
            if (!$stmt) throw new RuntimeException('gift_close_part_prepare');
            $partId=$part['id'];
            $stmt->bind_param('is',$partId,$partType);
            $ok=$stmt->execute();$affected=$stmt->affected_rows;$stmt->close();
            if (!$ok || $affected!==1)
                throw new RuntimeException('gift_close_part_unconfirmed');
        }
        $stmt=$connection->prepare('DELETE FROM setting WHERE id=? AND type=?');
        if (!$stmt) throw new RuntimeException('gift_close_manifest_prepare');
        $stmt->bind_param('is',$id,$rosterType);
        $ok=$stmt->execute();$affected=$stmt->affected_rows;$stmt->close();
        if (!$ok || $affected!==1)
            throw new RuntimeException('gift_close_manifest_unconfirmed');
        $stmt=$connection->prepare('DELETE FROM gift_list WHERE id=? AND `offset`=?');
        if (!$stmt) throw new RuntimeException('gift_close_job_delete_prepare');
        $stmt->bind_param('ii',$rowId,$count);
        $ok=$stmt->execute();$affected=$stmt->affected_rows;$stmt->close();
        if (!$ok || $affected!==1)
            throw new RuntimeException('gift_close_job_unconfirmed');
        if ($connection->commit()!==true)
            throw new RuntimeException('gift_close_commit_unknown');
        return true;
    } catch (Throwable $e) {
        try { $connection->rollback(); } catch (Throwable $ignored) {}
        return false;
    }
};

$stmt=$connection->prepare('SELECT title FROM server_info WHERE id=?');
if (!$stmt) return;
$stmt->bind_param('i',$serverId);
if (!$stmt->execute()) { $stmt->close(); return; }
$serverName=(string)($stmt->get_result()->fetch_assoc()['title']??'');
$stmt->close();

$stmt=$connection->prepare('SELECT type FROM server_config WHERE id=?');
if (!$stmt) return;
$stmt->bind_param('i',$serverId);
if (!$stmt->execute()) { $stmt->close(); return; }
$serverType=(string)($stmt->get_result()->fetch_assoc()['type']??'');
$stmt->close();

if (!achbotSupportsPanel($serverType)) {
    sendMessage(\achbotMessage('jobs.gift2all.message.5a6e527b3b'),null,null,$admin);
    return;
}

if ($volume<0 || $day<0 || ($volume===0 && $day===0) ||
    ($volume>0 && $volume%1024!==0)) {
    try {
        $reserve($offset,'','gift_units_unsupported');
        $notifyHold('',$offset,'gift_units_unsupported');
    } catch (Throwable $e) {
        sendMessage(\achbotMessage('jobs.gift2all.message.3582d30e0f'),null,null,$admin);
    }
    return;
}

$change=['remark'=>''];
if ($volume>0) $change['plus_volume']=intdiv($volume,1024);
if ($day>0) $change['plus_day']=$day;

// Old workers could apply first gift before the signed offset changed.
if ($receipt===null) {
    try {
        $reserve($offset,'','legacy_batch_missing_enqueued_provenance');
        $notifyHold('',$offset,'legacy_batch_missing_enqueued_provenance');
    } catch (Throwable $ignored) {
        sendMessage(\achbotMessage('jobs.gift2all.message.8f73c792e1'),null,null,$admin);
    }
    return;
}
$target=achbotGiftResolveTarget($connection,$serverId,$receipt);
if ($target===null) {
    try {
        $reserve($offset,'','gift_physical_target_changed');
        $notifyHold('',$offset,'gift_physical_target_changed');
    } catch (Throwable $ignored) {
        sendMessage(\achbotMessage('jobs.gift2all.message.4a086d03d8'),null,null,$admin);
    }
    return;
}
$listed=achbotProviderGiftUsers($connection,$serverId,$target);
if (!is_object($listed) || !is_array($listed->users??null) ||
    !array_is_list($listed->users) ||
    !property_exists($listed,'total') ||
    !is_int($listed->total) || $listed->total<0 ||
    $listed->total!==count($listed->users)) {
    // A network/format fault alone is retryable before any edit, but an
    // actual physical-identity drift during login or list GET must be held
    // for explicit operator reconciliation, not silently retried.
    if (!achbotGiftTargetMatches($connection,$serverId,$receipt['target'])) {
        try {
            $reserve($offset,'','gift_physical_target_changed_during_list');
            $notifyHold('',$offset,'gift_physical_target_changed_during_list');
        } catch (Throwable $ignored) {
            sendMessage(\achbotMessage('jobs.gift2all.message.3316d71705'),
                        null,null,$admin);
        }
    } else {
        sendMessage(\achbotMessage('jobs.gift2all.message.8fdb87d977'),
                    null,null,$admin);
    }
    return;
}
// Check the entire fresh API result for duplicate/malformed identities.
// A moved index is irrelevant; a missing snapshotted username is NOT.
$liveMembers=[];$liveNames=[];$ambiguous=false;
foreach ($listed->users as $user) {
    $name=is_object($user)?($user->username??null):null;
    if (!is_string($name) || $name==='' || strlen($name)>400 ||
        array_key_exists($name,$liveMembers)) {
        $ambiguous=true;break;
    }
    $liveMembers[$name]=$user;
    $liveNames[]=$name;
}
if ($ambiguous) {
    try {
        $reserve($offset,'','provider_membership_ambiguous');
        $notifyHold('',$offset,'provider_membership_ambiguous');
    } catch (Throwable $e) {
        sendMessage(\achbotMessage('jobs.gift2all.message.ee040491ba'),null,null,$admin);
    }
    return;
}
if (!achbotGiftTargetMatches($connection,$serverId,$receipt['target'])) {
    try {
        $reserve($offset,'','gift_physical_target_changed_after_list');
        $notifyHold('',$offset,'gift_physical_target_changed_after_list');
    } catch (Throwable $ignored) {
        sendMessage(\achbotMessage('jobs.gift2all.message.f575c8d1dc'),null,null,$admin);
    }
    return;
}
try {
    [$rosterId,$rosterMeta,$rosterNames]=$freezeRoster($liveNames);
} catch (Throwable $e) {
    // A pre-upgrade partially completed job cannot reconstruct its original
    // membership. Hold it, rather than silently replay or skip a user.
    try {
        $reserve($offset,'','roster_snapshot_requires_reconciliation');
        $notifyHold('',$offset,'roster_snapshot_requires_reconciliation');
    } catch (Throwable $ignored) {
        sendMessage(\achbotMessage('jobs.gift2all.message.b184e2ae4a'),null,null,$admin);
    }
    return;
}
if ($offset===0) {
    sendMessage(\achbotMessage('jobs.gift2all.message.e9658f3341', ['serverName' => $serverName]),null,null,$admin);
    sendToAllAdmins('sendMessage',['text'=>\achbotMessage('jobs.gift2all.message.e9658f3341', ['serverName' => $serverName]),
        'reply_markup'=>null,'parse_mode'=>null]);
}

// One exact frozen username per position, irrespective of fresh list order.
// Late arrivals are OUT of the batch. Missing original recipients require
// reconciliation, not auto-substitution or silently advancing a cursor.
$processedThisTick=false;
foreach ($rosterNames as $index=>$remark) {
    if ($index<$offset) continue;
    $processedThisTick=true;
    if (!isset($liveMembers[$remark])) {
        try {
            $reserve($index,$remark,'snapshot_recipient_missing');
            $notifyHold($remark,$index,'snapshot_recipient_missing');
        } catch (Throwable $e) {
            sendMessage(\achbotMessage('jobs.gift2all.message.9014385dca'),null,null,$admin);
        }
        return;
    }
    $user=$liveMembers[$remark];
    $eligible=is_numeric($user->data_limit??null) &&
        is_numeric($user->expire??null) &&
        (float)$user->data_limit>0 && (float)$user->expire>0 &&
        ($user->status??null)==='active';
    if (!$eligible) {
        $next=$index+1;
        try {
            $finish($index,null,$next,null,null);
        } catch (Throwable $e) {
            sendMessage(\achbotMessage('jobs.gift2all.message.5f467e3e2c'),null,null,$admin);
            return;
        }
        $offset=$next;
        continue;
    }

    $orderStmt=$connection->prepare(
        'SELECT id,userid,remark,expire_date FROM orders_list WHERE server_id=? AND inbound_id=0 AND remark=? ORDER BY id LIMIT 2');
    if (!$orderStmt) return;
    $orderStmt->bind_param('is',$serverId,$remark);
    $ok=$orderStmt->execute();
    $orders=$ok?$orderStmt->get_result()->fetch_all(MYSQLI_ASSOC):null;
    $orderStmt->close();
    if ($orders===null || count($orders)>1) {
        sendMessage(\achbotMessage('jobs.gift2all.message.f4b87a5740'),null,null,$admin);
        return;
    }
    $order=$orders[0]??null;

    try {
        [$held,$holdId]=$reserve($index,$remark,'pending_service_edit');
    } catch (Throwable $e) {
        // No outbound edit without a confirmed durable review hold.
        sendMessage(\achbotMessage('jobs.gift2all.message.65625b8a59'),null,null,$admin);
        return;
    }

    try {
        $change['remark']=$remark;
        $change['operation_key']='gift:'.(int)$holdId;
        $result=achbotApplyServiceEdit($connection,$serverId,$change,$target);
        $verified=is_object($result) &&
            ($result->success??false)===true &&
            ($result->verified??false)===true &&
            ($result->uncertain??false)!==true &&
            is_int($result->applied_expire??null) &&
            is_int($result->applied_bytes??null) &&
            $result->applied_expire>0 && $result->applied_bytes>=0;
        if (!$verified) {
            if (is_object($result) &&
                ($result->success??null)===false &&
                ($result->uncertain??null)===false) {
                // A validated deterministic refusal has no remote effect.
                $finish($held,$holdId,$index+1,null,null);
                sendMessage(\achbotMessage('jobs.gift2all.message.1ea4ee3d49', ['index' => $index]),
                            null,null,$admin);
                $offset=$index+1;
                continue;
            }
            throw new RuntimeException('gift_remote_effect_unverified');
        }

        $absoluteExpire=$day>0 ? $result->applied_expire : null;
        // Absolute verified readback, not an unchecked local +days guess.
        $finish($held,$holdId,$index+1,$order,$absoluteExpire);
        $offset=$index+1;

        if ($order!==null) {
            $giftText='';
            if ($volume>0) $giftText.=\achbotMessage('jobs.gift2all.gift_text.8f714dee13', ['volume' => $volume]);
            if ($day>0) $giftText.=($giftText===''?'':\achbotMessage('jobs.gift2all.text.1a51e219ec')).\achbotMessage('jobs.gift2all.text.b70363d685', ['day' => $day]);
            // Telegram delivery can fail after a confirmed provider effect
            // and local COMMIT. It cannot turn a successful gift into a
            // pending external-effect hold or authorize another edit.
            try {
                $notice=sendMessage(\achbotMessage('jobs.gift2all.message.1bf0942a3d', ['remark' => $remark, 'giftText' => $giftText]),
                    null,null,(int)$order['userid']);
                if (!is_object($notice) || ($notice->ok??false)!==true)
                    sendMessage(\achbotMessage('jobs.gift2all.message.07c38a54a9').
                        \achbotMessage('jobs.gift2all.message.9b87258e16', ['rowId' => $rowId, 'remark' => $remark]),null,null,$admin);
            } catch (Throwable $notificationError) {
                try {
                    sendMessage(\achbotMessage('jobs.gift2all.message.4ba0cee07a').
                        \achbotMessage('jobs.gift2all.message.9b87258e16', ['rowId' => $rowId, 'remark' => $remark]),null,null,$admin);
                } catch (Throwable $ignored) {}
            }
        }
    } catch (Throwable $e) {
        // The negative cursor and exact-recipient hold remain persisted.
        // Never retry an unknown external effect during the next Cron tick.
        $notifyHold($remark,$index,'gift_requires_operator_reconciliation');
        return;
    }
}
if (!$processedThisTick && $offset===count($rosterNames)) {
    if ($closeRoster($rosterId,$rosterMeta,count($rosterNames))) {
        sendMessage(\achbotMessage('jobs.gift2all.message.e6f582671d', ['serverName' => $serverName]),
                    null,null,$admin);
        sendToAllAdmins('sendMessage',['text'=>\achbotMessage('jobs.gift2all.message.e6f582671d', ['serverName' => $serverName]),
            'reply_markup'=>null,'parse_mode'=>null]);
    } else {
        sendMessage(\achbotMessage('jobs.gift2all.message.4d3e24cb7c'),
                    null,null,$admin);
    }
}
