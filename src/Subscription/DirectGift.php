<?php
/**
 * Direct, single-account administrator gifts (post-V9).
 *
 * Source-only; no tenant migration. The original setting.type is NOT unique.
 * Every new source is serialized on the unique primary administrator users.id;
 * every recipient marker is serialized on orders_list.id PRIMARY KEY.
 * Both lock owners and the durable journal MUST commit before remote effects.
 */
require_once __DIR__.'/GiftBatch.php';

function achbotDirectGiftInt($raw,int $min,int $max): ?int {
    if (!is_string($raw) || preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$raw)!==1)
        return null;
    $value=filter_var($raw,FILTER_VALIDATE_INT,
        ['options'=>['min_range'=>$min,'max_range'=>$max]]);
    return $value===false?null:$value;
}

function achbotDirectGiftWebhook($update,int $actor,int $chatId,int $messageId,
                                  $bound,string $kind): array {
    // The JSON actor id alone is forgeable. This privileged route requires
    // Telegram's configured secret_token header, not only a private-chat flag.
    $secret=$GLOBALS['telegramWebhookSecret']??null;
    $received=$_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']??null;
    if (!is_string($secret) || strlen($secret)<16 || strlen($secret)>256 ||
        preg_match('/\A[A-Za-z0-9_-]+\z/D',$secret)!==1 ||
        !is_string($received) || !hash_equals($secret,$received))
        throw new RuntimeException('direct_gift_webhook_unauthenticated');
    if ($kind==='message') {
        $source=achbotGiftTelegramSource(
            $update,$actor,$chatId,$messageId,$bound);
        $source['kind']='telegram_direct_gift_v1';
        return $source;
    }
    if ($kind!=='callback' || !is_object($update) ||
        !is_int($update->update_id??null) || $update->update_id<=0)
        throw new RuntimeException('direct_gift_callback_invalid');
    $keys=array_keys(get_object_vars($update));sort($keys);
    if ($keys!==['callback_query','update_id'] ||
        !is_object($update->callback_query??null))
        throw new RuntimeException('direct_gift_callback_invalid');
    $callback=$update->callback_query;
    $sender=$callback->from??null;
    $message=$callback->message??null;
    $chat=$message->chat??null;
    if (!is_object($sender) || !is_object($message) || !is_object($chat) ||
        !is_int($sender->id??null) || $sender->id<=0 ||
        ($sender->is_bot??false)===true ||
        !is_int($chat->id??null) || $chat->id!==$sender->id ||
        ($chat->type??null)!=='private' ||
        !is_int($message->message_id??null) || $message->message_id<=0 ||
        !is_string($callback->data??null) ||
        $callback->data!==$bound ||
        $sender->id!==$actor || $chat->id!==$chatId ||
        $message->message_id!==$messageId)
        throw new RuntimeException('direct_gift_callback_identity_invalid');
    return [
        'kind'=>'telegram_direct_gift_callback_v1',
        'update_id'=>$update->update_id,'actor_id'=>$actor,
        'chat_id'=>$chatId,'message_id'=>$messageId,
        'data_sha256'=>hash('sha256',$bound)
    ];
}

function achbotDirectGiftSourceType(int $updateId,string $kind='source'): string {
    if ($updateId<=0 || !in_array($kind,['source','start'],true))
        throw new RuntimeException('direct_gift_source_invalid');
    return 'ACHBOT_DIRECT_GIFT_'.strtoupper($kind).'_'.
        hash('sha256',(string)$updateId);
}

function achbotDirectGiftEngines(mysqli $db): void {
    $tables=['users','orders_list','setting','server_config','server_info'];
    $check=$db->query(
        "SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES
          WHERE TABLE_SCHEMA=DATABASE()
            AND TABLE_NAME IN ('users','orders_list','setting','server_config','server_info')");
    if (!$check || $check->num_rows!==count($tables))
        throw new RuntimeException('direct_gift_engine_unknown');
    foreach ($check->fetch_all(MYSQLI_ASSOC) as $row)
        if (strtoupper((string)$row['ENGINE'])!=='INNODB')
            throw new RuntimeException('direct_gift_nontransactional');
    $check->free();
    $keys=$db->query(
        "SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA=DATABASE() AND INDEX_NAME='PRIMARY'
            AND TABLE_NAME IN ('users','orders_list','setting')");
    if (!$keys) throw new RuntimeException('direct_gift_pk_unknown');
    $found=$keys->fetch_all(MYSQLI_ASSOC);$keys->free();
    usort($found,static fn($a,$b)=>strcmp($a['TABLE_NAME'],$b['TABLE_NAME']));
    if ($found!==[
        ['TABLE_NAME'=>'orders_list','COLUMN_NAME'=>'id'],
        ['TABLE_NAME'=>'setting','COLUMN_NAME'=>'id'],
        ['TABLE_NAME'=>'users','COLUMN_NAME'=>'id']
    ]) throw new RuntimeException('direct_gift_pk_not_unique');
}

/** Resolve unindexed userid to unique PKs BEFORE starting a transaction.
 * A consistent read inside the transaction, even before a later FOR UPDATE,
 * would pin a stale REPEATABLE READ snapshot and hide another owner's claim.
 */
function achbotDirectGiftActorPks(mysqli $db,int $actor,int $primary): array {
    if ($actor<=0 || $primary<=0)
        throw new RuntimeException('direct_gift_actor_invalid');
    $ids=[];
    foreach (array_unique([$primary,$actor]) as $userid) {
        $probe=$db->prepare('SELECT id FROM users WHERE userid=? LIMIT 2');
        if (!$probe) throw new RuntimeException('direct_gift_actor_prepare');
        $probe->bind_param('i',$userid);
        $ok=$probe->execute();
        $found=$ok?$probe->get_result()->fetch_all(MYSQLI_ASSOC):[];
        $probe->close();
        if (!$ok || count($found)!==1 || (int)$found[0]['id']<=0)
            throw new RuntimeException('direct_gift_actor_ambiguous');
        $ids[$userid]=(int)$found[0]['id'];
    }
    return $ids;
}

/** FIRST transactional read must lock the primary-admin PK. No nonlocking
 * consistent read may precede that lock in any direct-gift transaction.
 * Verify the pre-resolved identities again after locking, on a fresh view.
 */
function achbotDirectGiftActorLocked(mysqli $db,int $actor,int $primary,array $pks): array {
    if ($actor<=0 || $primary<=0 || !isset($pks[$actor],$pks[$primary]))
        throw new RuntimeException('direct_gift_actor_invalid');
    $fetch=static function(int $id) use ($db,$pks): array {
        $pk=$pks[$id];
        if (!is_int($pk) || $pk<=0)
            throw new RuntimeException('direct_gift_actor_invalid');
        $stmt=$db->prepare('SELECT id,userid,isAdmin,step FROM users
                            WHERE id=? AND userid=? FOR UPDATE');
        if (!$stmt) throw new RuntimeException('direct_gift_actor_pk_prepare');
        $stmt->bind_param('ii',$pk,$id);
        $ok=$stmt->execute();
        $row=$ok?$stmt->get_result()->fetch_assoc():null;
        $stmt->close();
        if (!$row || (int)$row['userid']!==$id || (int)$row['id']!==$pk)
            throw new RuntimeException('direct_gift_actor_changed');
        return $row;
    };
    // Serialize all source and physical-account claims through this unique PK.
    $owner=$fetch($primary);
    $row=$actor===$primary?$owner:$fetch($actor);
    // The first nonlocking reads occur *after* the owner lock. This snapshot
    // sees the preceding serialized claimant's committed account marker.
    foreach (array_unique([$primary,$actor]) as $userid) {
        $probe=$db->prepare('SELECT id FROM users WHERE userid=? LIMIT 2');
        if (!$probe) throw new RuntimeException('direct_gift_actor_prepare');
        $probe->bind_param('i',$userid);
        $ok=$probe->execute();
        $found=$ok?$probe->get_result()->fetch_all(MYSQLI_ASSOC):[];
        $probe->close();
        if (!$ok || count($found)!==1 || (int)$found[0]['id']!==$pks[$userid])
            throw new RuntimeException('direct_gift_actor_ambiguous');
    }
    if ($actor!==$primary && (int)$row['isAdmin']!==1)
        throw new RuntimeException('direct_gift_admin_revoked');
    return $row;
}

function achbotDirectGiftSetStepLocked(mysqli $db,array $actorRow,
                                        string $expected,string $next): void {
    if (strlen($next)>950) throw new RuntimeException('direct_gift_step_too_long');
    $id=(int)$actorRow['id'];$actor=(int)$actorRow['userid'];
    $stmt=$db->prepare('UPDATE users SET step=? WHERE id=? AND userid=? AND step=?');
    if (!$stmt) throw new RuntimeException('direct_gift_step_prepare');
    $stmt->bind_param('siis',$next,$id,$actor,$expected);
    $ok=$stmt->execute();$affected=$stmt->affected_rows;$stmt->close();
    if (!$ok || $affected!==1)
        throw new RuntimeException('direct_gift_step_stale');
}

function achbotDirectGiftRows(mysqli $db,string $type,bool $lock=false): array {
    // type is NOT indexed. FOR UPDATE on a type scan can lock unrelated
    // financial settings. Find id without a locking scan, then lock ONLY
    // the unique PK row when writing; the primary admin owner serializes
    // all direct-gift inserts for this type.
    $stmt=$db->prepare('SELECT id,value FROM setting WHERE type=? LIMIT 2');
    if (!$stmt) throw new RuntimeException('direct_gift_setting_prepare');
    $stmt->bind_param('s',$type);
    $ok=$stmt->execute();
    $rows=$ok?$stmt->get_result()->fetch_all(MYSQLI_ASSOC):[];
    $stmt->close();
    if (!$ok || count($rows)>1)
        throw new RuntimeException('direct_gift_setting_ambiguous');
    if (!$lock || !$rows) return $rows;
    $id=(int)$rows[0]['id'];
    $stmt=$db->prepare('SELECT id,value FROM setting WHERE id=? AND type=? FOR UPDATE');
    if (!$stmt) throw new RuntimeException('direct_gift_setting_pk_prepare');
    $stmt->bind_param('is',$id,$type);
    $ok=$stmt->execute();
    $current=$ok?$stmt->get_result()->fetch_assoc():null;
    $stmt->close();
    if (!$current || (int)$current['id']!==$id)
        throw new RuntimeException('direct_gift_setting_pk_changed');
    return [$current];
}
function achbotDirectGiftWrite(mysqli $db,string $type,array $value,?int $id=null): int {
    $json=json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    if ($id===null) {
        $stmt=$db->prepare('INSERT INTO setting (type,value) VALUES (?,?)');
        if (!$stmt) throw new RuntimeException('direct_gift_insert_prepare');
        $stmt->bind_param('ss',$type,$json);
        $ok=$stmt->execute();$id=$stmt->insert_id;$stmt->close();
        if (!$ok || $id<=0) throw new RuntimeException('direct_gift_insert_failed');
        return $id;
    }
    $stmt=$db->prepare('UPDATE setting SET value=? WHERE id=? AND type=?');
    if (!$stmt) throw new RuntimeException('direct_gift_update_prepare');
    $stmt->bind_param('sis',$json,$id,$type);
    $ok=$stmt->execute();$changed=$stmt->affected_rows;$stmt->close();
    if (!$ok || $changed!==1) throw new RuntimeException('direct_gift_update_failed');
    return $id;
}

function achbotDirectGiftToken(array $data): string {
    $raw=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    return rtrim(strtr(base64_encode($raw),'+/','-_'),'=');
}
function achbotDirectGiftDecode(string $token): array {
    if (strlen($token)>900 || preg_match('/\A[A-Za-z0-9_-]+\z/D',$token)!==1)
        throw new RuntimeException('direct_gift_token_invalid');
    $raw=base64_decode(strtr($token,'-_','+/'),true);
    if ($raw===false) throw new RuntimeException('direct_gift_token_invalid');
    $data=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    if (!is_array($data) ||
        !is_int($data['actor_id']??null) || $data['actor_id']<=0 ||
        !is_int($data['server_id']??null) || $data['server_id']<=0 ||
        !is_int($data['last_update_id']??null) || $data['last_update_id']<=0 ||
        !is_int($data['last_message_id']??null) || $data['last_message_id']<=0 ||
        !is_string($data['nonce']??null) ||
        preg_match('/\A[a-f0-9]{32}\z/D',$data['nonce'])!==1 ||
        !is_array($data['order']??null))
        throw new RuntimeException('direct_gift_token_malformed');
    return $data;
}

function achbotDirectGiftStepUpdate(string $step): ?int {
    if (preg_match('/\AachbotDirectGiftRemark:[1-9][0-9]*:[a-f0-9]{32}:([1-9][0-9]*)\z/D',$step,$m)) {
        $last=achbotDirectGiftInt($m[1],1,PHP_INT_MAX);
        if ($last===null) throw new RuntimeException('direct_gift_step_invalid');
        return $last;
    }
    if (preg_match('/\AachbotDirectGift(?:Day|Volume):([A-Za-z0-9_-]+)(?::[0-9]+)?\z/D',$step,$m))
        return achbotDirectGiftDecode($m[1])['last_update_id'];
    if (str_starts_with($step,'achbotDirectGift'))
        throw new RuntimeException('direct_gift_step_invalid');
    return null;
}

function achbotDirectGiftOrder(mysqli $db,int $id,bool $lock): array {
    $stmt=$db->prepare('SELECT id,userid,server_id,inbound_id,fileid,
                  remark,uuid,status,expire_date FROM orders_list WHERE id=?'.
                  ($lock?' FOR UPDATE':''));
    if (!$stmt) throw new RuntimeException('direct_gift_order_prepare');
    $stmt->bind_param('i',$id);
    $ok=$stmt->execute();
    $row=$ok?$stmt->get_result()->fetch_assoc():null;$stmt->close();
    if (!$row) throw new RuntimeException('direct_gift_order_missing');
    $order=[];
    foreach (['id','userid','server_id','inbound_id','fileid','status','expire_date'] as $k)
        $order[$k]=(int)$row[$k];
    foreach (['remark','uuid'] as $k) $order[$k]=$row[$k];
    if ($order['id']<=0 || $order['userid']<=0 || $order['server_id']<=0 ||
        $order['fileid']<=0 || $order['status']!==1 ||
        $order['inbound_id']!==0 || $order['expire_date']<0 ||
        $order['expire_date']>2147483647 ||
        !is_string($order['remark']) || $order['remark']==='')
        throw new RuntimeException('direct_gift_order_ineligible');
    return $order;
}

function achbotDirectGiftStart(mysqli $db,array $source,int $serverId,int $primary): bool {
    if ($serverId<=0 || $serverId>2147483647 ||
        ($source['kind']??'')!=='telegram_direct_gift_callback_v1')
        throw new RuntimeException('direct_gift_start_invalid');
    achbotDirectGiftEngines($db);
    $actorPks=achbotDirectGiftActorPks($db,$source['actor_id'],$primary);
    if (!$db->begin_transaction()) throw new RuntimeException('direct_gift_begin_failed');
    try {
        $actor=achbotDirectGiftActorLocked($db,$source['actor_id'],$primary,$actorPks);
        $lastUpdate=achbotDirectGiftStepUpdate((string)$actor['step']);
        if ($lastUpdate!==null && $source['update_id']<=$lastUpdate)
            throw new RuntimeException('direct_gift_stale_start');
        $stmt=$db->prepare("SELECT i.id FROM server_info i
            JOIN server_config c ON c.id=i.id
            WHERE i.id=? AND i.active=1 AND i.state=1 AND c.type='marzban'");
        if (!$stmt) throw new RuntimeException('direct_gift_server_prepare');
        $stmt->bind_param('i',$serverId);
        $ok=$stmt->execute();$server=$ok?$stmt->get_result()->fetch_assoc():null;
        $stmt->close();
        if (!$server) throw new RuntimeException('direct_gift_server_ineligible');
        $type=achbotDirectGiftSourceType($source['update_id'],'start');
        if (achbotDirectGiftRows($db,$type)) {
            if (!$db->commit()) throw new RuntimeException('direct_gift_replay_commit_unknown');
            return false;
        }
        $nonce=bin2hex(random_bytes(16));
        $step='achbotDirectGiftRemark:'.$serverId.':'.$nonce.':'.$source['update_id'];
        achbotDirectGiftWrite($db,$type,[
            'version'=>1,'source'=>$source,'nonce'=>$nonce,
            'actor_id'=>$source['actor_id'],'server_id'=>$serverId,
            'created_at'=>gmdate('c')]);
        achbotDirectGiftSetStepLocked($db,$actor,(string)$actor['step'],$step);
        if (!$db->commit()) throw new RuntimeException('direct_gift_start_commit_unknown');
        return true;
    } catch (Throwable $e) {
        try {$db->rollback();} catch (Throwable $ignored) {}
        throw $e;
    }
}

function achbotDirectGiftAdvance(mysqli $db,array $source,int $primary,
                                  string $step,string $text): string {
    achbotDirectGiftEngines($db);
    $actorPks=achbotDirectGiftActorPks($db,$source['actor_id'],$primary);
    if (!$db->begin_transaction()) throw new RuntimeException('direct_gift_begin_failed');
    try {
        $actor=achbotDirectGiftActorLocked($db,$source['actor_id'],$primary,$actorPks);
        if ($actor['step']!==$step)
            throw new RuntimeException('direct_gift_step_stale');
        if (preg_match('/\AachbotDirectGiftRemark:([1-9][0-9]*):([a-f0-9]{32}):([1-9][0-9]*)\z/D',$step,$m)) {
            $sid=achbotDirectGiftInt($m[1],1,2147483647);
            $previous=achbotDirectGiftInt($m[3],1,PHP_INT_MAX);
            if ($sid===null || $previous===null || $source['update_id']<=$previous)
                throw new RuntimeException('direct_gift_old_update');
            $remark=trim($text);
            if ($remark==='' || strlen($remark)>100)
                throw new InvalidArgumentException('direct_gift_remark_invalid');
            $stmt=$db->prepare('SELECT id FROM orders_list WHERE server_id=? AND remark=?
                LIMIT 2 FOR UPDATE');
            if (!$stmt) throw new RuntimeException('direct_gift_order_lookup_prepare');
            $stmt->bind_param('is',$sid,$remark);
            $ok=$stmt->execute();
            $matches=$ok?$stmt->get_result()->fetch_all(MYSQLI_ASSOC):[];
            $stmt->close();
            if (count($matches)!==1)
                throw new RuntimeException('direct_gift_order_ambiguous');
            $order=achbotDirectGiftOrder($db,(int)$matches[0]['id'],true);
            if ($order['server_id']!==$sid || $order['remark']!==$remark)
                throw new RuntimeException('direct_gift_order_identity_mismatch');
            $token=achbotDirectGiftToken([
                'actor_id'=>$source['actor_id'],'server_id'=>$sid,
                'nonce'=>$m[2],'last_update_id'=>$source['update_id'],
                'last_message_id'=>$source['message_id'],'order'=>$order
            ]);
            $next='achbotDirectGiftDay:'.$token;
        } elseif (preg_match('/\AachbotDirectGiftDay:([A-Za-z0-9_-]+)\z/D',$step,$m)) {
            $payload=achbotDirectGiftDecode($m[1]);
            if ($payload['actor_id']!==$source['actor_id'] ||
                $source['update_id']<=$payload['last_update_id'] ||
                $source['message_id']<=$payload['last_message_id'])
                throw new RuntimeException('direct_gift_old_update');
            $days=achbotDirectGiftInt($text,0,36500);
            if ($days===null) throw new InvalidArgumentException('direct_gift_days_invalid');
            $payload['last_update_id']=$source['update_id'];
            $payload['last_message_id']=$source['message_id'];
            $next='achbotDirectGiftVolume:'.achbotDirectGiftToken($payload).':'.$days;
        } else throw new RuntimeException('direct_gift_step_invalid');
        achbotDirectGiftSetStepLocked($db,$actor,$step,$next);
        if (!$db->commit()) throw new RuntimeException('direct_gift_advance_commit_unknown');
        return $next;
    } catch (Throwable $e) {
        try {$db->rollback();} catch (Throwable $ignored) {}
        throw $e;
    }
}

function achbotDirectGiftCancel(mysqli $db,array $source,int $primary,string $step): void {
    achbotDirectGiftEngines($db);
    $actorPks=achbotDirectGiftActorPks($db,$source['actor_id'],$primary);
    if (!$db->begin_transaction()) throw new RuntimeException('direct_gift_begin_failed');
    try {
        $actor=achbotDirectGiftActorLocked($db,$source['actor_id'],$primary,$actorPks);
        if ($actor['step']!==$step)
            throw new RuntimeException('direct_gift_step_stale');
        // CANCEL must advance the same authenticated source order as a normal step.
        $lastUpdate=achbotDirectGiftStepUpdate($step);
        if ($lastUpdate===null || ($source['kind']??null)!=='telegram_direct_gift_v1' ||
            !is_int($source['update_id']??null) ||
            $source['update_id']<=$lastUpdate)
            throw new RuntimeException('direct_gift_old_cancel');
        if (preg_match('/\AachbotDirectGift(?:Day|Volume):([A-Za-z0-9_-]+)(?::[0-9]+)?\z/D',$step,$match)) {
            $payload=achbotDirectGiftDecode($match[1]);
            if ($payload['actor_id']!==$source['actor_id'] ||
                !is_int($source['message_id']??null) ||
                $source['message_id']<=$payload['last_message_id'])
                throw new RuntimeException('direct_gift_old_cancel');
        }
        achbotDirectGiftSetStepLocked($db,$actor,$step,'none');
        if (!$db->commit()) throw new RuntimeException('direct_gift_cancel_commit_unknown');
    } catch (Throwable $e) {
        try {$db->rollback();} catch (Throwable $ignored) {}
        throw $e;
    }
}

/**
 * The global legacy CANCEL handler runs AFTER the dedicated direct-gift
 * router. Its config.php userInfo is only a cached routing hint.
 *
 * Serialize the fallback on the exact actor PK and consume only the exact
 * non-direct step loaded at request start. A concurrent direct-gift start
 * updates that same PK, so a stale legacy CANCEL cannot clear a newer nonce.
 * No Telegram gift effect, legacy cleanup, or success notice is allowed when
 * the cached state is obsolete. Do NOT use unguarded setUser() on this path.
 */
function achbotDirectGiftGuardLegacyCancel(mysqli $db,int $actorId,$cached): bool {
    if ($actorId<=0 || !is_array($cached) ||
        !is_string($cached['step']??null) ||
        ($cached['step']==='banned' ||
         str_starts_with($cached['step'],'achbotDirectGift') ||
         str_starts_with($cached['step'],'achbotExempt:')) ||
        !isset($cached['id'],$cached['userid']))
        return false;
    $id=achbotDirectGiftInt((string)$cached['id'],1,2147483647);
    $cachedActor=achbotDirectGiftInt((string)$cached['userid'],1,PHP_INT_MAX);
    if ($id===null || $cachedActor!==$actorId) return false;
    $expected=$cached['step'];
    if (!$db->begin_transaction())
        throw new RuntimeException('legacy_cancel_begin_failed');
    try {
        $stmt=$db->prepare('SELECT id,userid,step FROM users
                            WHERE id=? AND userid=? FOR UPDATE');
        if (!$stmt) throw new RuntimeException('legacy_cancel_lock_prepare');
        $stmt->bind_param('ii',$id,$actorId);
        $ok=$stmt->execute();
        $current=$ok?$stmt->get_result()->fetch_assoc():null;
        $stmt->close();
        if (!$ok) throw new RuntimeException('legacy_cancel_lock_failed');
        if (!$current || (int)$current['id']!==$id ||
            (int)$current['userid']!==$actorId ||
            !is_string($current['step']??null) ||
            $current['step']!==$expected ||
            $current['step']==='banned' ||
            str_starts_with($current['step'],'achbotDirectGift') ||
            str_starts_with($current['step'],'achbotExempt:')) {
            $db->rollback();
            return false;
        }
        // userid has no unique index in the historical schema. Recheck it
        // without locking an unindexed scan or touching financial settings.
        $stmt=$db->prepare('SELECT id FROM users WHERE userid=? LIMIT 2');
        if (!$stmt) throw new RuntimeException('legacy_cancel_identity_prepare');
        $stmt->bind_param('i',$actorId);
        $ok=$stmt->execute();
        $found=$ok?$stmt->get_result()->fetch_all(MYSQLI_ASSOC):[];
        $stmt->close();
        if (!$ok || count($found)!==1 || (int)$found[0]['id']!==$id)
            throw new RuntimeException('legacy_cancel_actor_ambiguous');
        if ($expected!=='none') {
            $next='none';
            $stmt=$db->prepare('UPDATE users SET step=?
                WHERE id=? AND userid=? AND step=?');
            if (!$stmt) throw new RuntimeException('legacy_cancel_cas_prepare');
            $stmt->bind_param('siis',$next,$id,$actorId,$expected);
            $ok=$stmt->execute();$changed=$stmt->affected_rows;$stmt->close();
            if (!$ok || $changed!==1)
                throw new RuntimeException('legacy_cancel_cas_unconfirmed');
        }
        if (!$db->commit())
            throw new RuntimeException('legacy_cancel_commit_unknown');
        return true;
    } catch (Throwable $e) {
        try {$db->rollback();} catch (Throwable $ignored) {}
        throw $e;
    }
}

function achbotDirectGiftClaim(mysqli $db,array $source,int $primary,
                                string $step,string $text): array {
    if (!preg_match('/\AachbotDirectGiftVolume:([A-Za-z0-9_-]+):([0-9]+)\z/D',$step,$m))
        throw new RuntimeException('direct_gift_claim_step_invalid');
    $payload=achbotDirectGiftDecode($m[1]);
    $days=achbotDirectGiftInt($m[2],0,36500);
    $volume=achbotDirectGiftInt($text,0,2147483647);
    if ($days===null || $volume===null || ($days===0 && $volume===0) ||
        ($volume>0 && $volume%1024!==0))
        throw new InvalidArgumentException('direct_gift_units_invalid');
    if ($payload['actor_id']!==$source['actor_id'] ||
        $source['update_id']<=$payload['last_update_id'] ||
        $source['message_id']<=$payload['last_message_id'])
        throw new RuntimeException('direct_gift_old_update');
    $expected=$payload['order'];
    if (!is_int($expected['id']??null) || $expected['id']<=0)
        throw new RuntimeException('direct_gift_order_missing');
    achbotDirectGiftEngines($db);
    $actorPks=achbotDirectGiftActorPks($db,$source['actor_id'],$primary);
    if (!$db->begin_transaction()) throw new RuntimeException('direct_gift_begin_failed');
    try {
        $actor=achbotDirectGiftActorLocked($db,$source['actor_id'],$primary,$actorPks);
        $type=achbotDirectGiftSourceType($source['update_id']);
        $prior=achbotDirectGiftRows($db,$type,true);
        if ($prior) {
            $existing=json_decode((string)$prior[0]['value'],true);
            // Source type is derived only from update_id; an existing type
            // is not proof that the original Telegram actor, wizard and
            // pinned account claim agree. Fail closed on provenance conflict.
            if (!is_array($existing) ||
                ($existing['version']??null)!==1 ||
                ($existing['kind']??null)!=='achbot_direct_gift_v1' ||
                !in_array($existing['state']??null,['pending','review','completed'],true) ||
                ($existing['source']??null)!==$source ||
                ($existing['actor_id']??null)!==$source['actor_id'] ||
                ($existing['step_sha256']??null)!==hash('sha256',$step) ||
                ($existing['nonce']??null)!==$payload['nonce'] ||
                ($existing['order']??null)!==$expected ||
                ($existing['days']??null)!==$days ||
                ($existing['volume_mb']??null)!==$volume ||
                !is_array($existing['target']??null))
                throw new RuntimeException('direct_gift_duplicate_source_conflict');
            if (!$db->commit()) throw new RuntimeException('direct_gift_replay_commit_unknown');
            return ['claimed'=>false,'reason'=>'duplicate','id'=>(int)$prior[0]['id']];
        }
        if ($actor['step']!==$step)
            throw new RuntimeException('direct_gift_step_stale');
        $order=achbotDirectGiftOrder($db,$expected['id'],true);
        if ($order!==$expected || $order['server_id']!==$payload['server_id'])
            throw new RuntimeException('direct_gift_order_changed');
        // A physical account can appear in multiple local order rows. Use
        // the shared panel-group ID when explicitly configured; otherwise
        // the exact endpoint identity. Serialize all claims on the primary
        // administrator row before checking this non-UNIQUE setting.type.
        $target=achbotGiftPhysicalTarget($db,$order['server_id'],true);
        $publicTarget=achbotGiftPublicTarget($target);
        $accountScope=$publicTarget['group_id']===null
            ? 'endpoint:'.$publicTarget['panel_url']
            : 'group:'.$publicTarget['group_id'];
        $markerType='ACHBOT_DIRECT_GIFT_ACCOUNT_'.hash('sha256',
            json_encode([$accountScope,$order['remark']],JSON_THROW_ON_ERROR));
        $previous=achbotDirectGiftRows($db,$markerType,true);
        if ($previous) {
            $marker=json_decode((string)$previous[0]['value'],true);
            if (!is_array($marker) || ($marker['version']??null)!==1 ||
                !is_int($marker['order_id']??null) || $marker['order_id']<=0 ||
                !is_int($marker['source_id']??null) || $marker['source_id']<=0 ||
                !is_string($marker['source_type']??null))
                throw new RuntimeException('direct_gift_order_marker_invalid');
            if (($marker['state']??null)!=='completed') {
                // Rejecting a held account also consumes THIS pending wizard
                // step atomically. Otherwise an operator remains trapped in
                // the final input step after the first clear hold notice.
                achbotDirectGiftSetStepLocked($db,$actor,$step,'none');
                if (!$db->commit()) throw new RuntimeException('direct_gift_hold_commit_unknown');
                return ['claimed'=>false,'reason'=>'hold',
                        'id'=>(int)($marker['source_id']??0)];
            }
        }
        $record=[
            'version'=>1,'kind'=>'achbot_direct_gift_v1','state'=>'pending',
            'source'=>$source,'actor_id'=>$source['actor_id'],
            'step_sha256'=>hash('sha256',$step),
            'nonce'=>$payload['nonce'],'order'=>$order,
            'days'=>$days,'volume_mb'=>$volume,
            'target'=>$publicTarget,
            'created_at'=>gmdate('c')
        ];
        // Persistent one-source hold, created BEFORE possible provider PUT.
        $sourceId=achbotDirectGiftWrite($db,$type,$record);
        $marker=['version'=>1,'state'=>'pending','order_id'=>$order['id'],
                 'source_id'=>$sourceId,'source_type'=>$type];
        achbotDirectGiftWrite($db,$markerType,$marker,
                             $previous?(int)$previous[0]['id']:null);
        achbotDirectGiftSetStepLocked($db,$actor,$step,'none');
        if (!$db->commit()) throw new RuntimeException('direct_gift_claim_commit_unknown');
        return [
            'claimed'=>true,'id'=>$sourceId,'type'=>$type,
            'marker_type'=>$markerType,'order'=>$order,
            'target'=>$publicTarget,
            'days'=>$days,'volume_mb'=>$volume
        ];
    } catch (Throwable $e) {
        try {$db->rollback();} catch (Throwable $ignored) {}
        throw $e;
    }
}

function achbotDirectGiftJournalLocked(mysqli $db,array $claim): array {
    $source=achbotDirectGiftRows($db,$claim['type'],true);
    $marker=achbotDirectGiftRows($db,$claim['marker_type'],true);
    if (count($source)!==1 || (int)$source[0]['id']!==$claim['id'] ||
        count($marker)!==1)
        throw new RuntimeException('direct_gift_journal_missing');
    $record=json_decode((string)$source[0]['value'],true);
    $mark=json_decode((string)$marker[0]['value'],true);
    if (!is_array($record) || !is_array($mark) ||
        ($record['version']??null)!==1 ||
        ($record['state']??null)!=='pending' ||
        ($record['order']??null)!==$claim['order'] ||
        ($record['target']??null)!==$claim['target'] ||
        ($record['days']??null)!==$claim['days'] ||
        ($record['volume_mb']??null)!==$claim['volume_mb'] ||
        ($mark['state']??null)!=='pending' ||
        ($mark['source_id']??null)!==$claim['id'] ||
        ($mark['source_type']??null)!==$claim['type'] ||
        ($mark['order_id']??null)!==$claim['order']['id'])
        throw new RuntimeException('direct_gift_journal_conflict');
    return [$record,$mark,(int)$marker[0]['id']];
}

/** A short, fresh and exactly pinned pre-effect check. Never hold a SQL
 * transaction across a network call. Remote adapter additionally rechecks
 * the pinned physical endpoint before PUT and verifies absolute readback. */
function achbotDirectGiftPreEffect(mysqli $db,array $claim,
                                   int $actorId,int $primary): void {
    achbotDirectGiftEngines($db);
    $actorPks=achbotDirectGiftActorPks($db,$actorId,$primary);
    if (!$db->begin_transaction())
        throw new RuntimeException('direct_gift_preflight_begin_failed');
    try {
        achbotDirectGiftActorLocked($db,$actorId,$primary,$actorPks);
        $order=achbotDirectGiftOrder($db,$claim['order']['id'],true);
        if ($order!==$claim['order'])
            throw new RuntimeException('direct_gift_preflight_order_changed');
        achbotDirectGiftJournalLocked($db,$claim);
        if (!achbotGiftTargetMatches($db,$order['server_id'],$claim['target']))
            throw new RuntimeException('direct_gift_preflight_target_changed');
        if (!$db->commit()) throw new RuntimeException('direct_gift_preflight_commit_unknown');
    } catch (Throwable $e) {
        try {$db->rollback();} catch (Throwable $ignored) {}
        throw $e;
    }
}

function achbotDirectGiftComplete(mysqli $db,array $claim,object $result): void {
    if (($result->success??false)!==true ||
        ($result->verified??false)!==true ||
        ($result->uncertain??false)===true ||
        !is_int($result->applied_expire??null) ||
        $result->applied_expire<=0 || $result->applied_expire>2147483647 ||
        !is_int($result->applied_bytes??null) ||
        $result->applied_bytes<0)
        throw new RuntimeException('direct_gift_result_unverified');
    $old=$claim['order']['expire_date'];$new=$result->applied_expire;
    if ($new<$old || ($claim['days']>0 && $new===$old))
        throw new RuntimeException('direct_gift_expiry_did_not_increase');
    achbotDirectGiftEngines($db);
    if (!$db->begin_transaction()) throw new RuntimeException('direct_gift_begin_failed');
    try {
        $order=achbotDirectGiftOrder($db,$claim['order']['id'],true);
        if ($order!==$claim['order'] ||
            !achbotGiftTargetMatches($db,$order['server_id'],$claim['target']))
            throw new RuntimeException('direct_gift_local_identity_changed');
        [$record,$marker,$markerId]=achbotDirectGiftJournalLocked($db,$claim);
        if ($new!==$old) {
            $stmt=$db->prepare('UPDATE orders_list SET expire_date=?,notif=0
                WHERE id=? AND userid=? AND server_id=? AND inbound_id=?
                  AND fileid=? AND remark=? AND uuid <=> ? AND status=1 AND expire_date=?');
            if (!$stmt) throw new RuntimeException('direct_gift_local_update_prepare');
            $o=$claim['order'];
            $stmt->bind_param('iiiiiissi',$new,$o['id'],$o['userid'],
                $o['server_id'],$o['inbound_id'],$o['fileid'],
                $o['remark'],$o['uuid'],$old);
            $ok=$stmt->execute();$changed=$stmt->affected_rows;$stmt->close();
            if (!$ok || $changed!==1)
                throw new RuntimeException('direct_gift_local_update_unconfirmed');
        }
        $record['state']='completed';
        $record['applied_expire']=$new;
        $record['applied_bytes']=$result->applied_bytes;
        $record['completed_at']=gmdate('c');
        $marker['state']='completed';
        achbotDirectGiftWrite($db,$claim['type'],$record,$claim['id']);
        achbotDirectGiftWrite($db,$claim['marker_type'],$marker,$markerId);
        if (!$db->commit()) throw new RuntimeException('direct_gift_finish_commit_unknown');
    } catch (Throwable $e) {
        try {$db->rollback();} catch (Throwable $ignored) {}
        throw $e;
    }
}

/** Best effort: even if this fails, the pre-effect PENDING hold is permanent. */
function achbotDirectGiftReview(mysqli $db,array $claim,string $reason): void {
    if (preg_match('/\A[a-z0-9_]{1,80}\z/D',$reason)!==1)
        $reason='direct_gift_unverified';
    try {
        if (!$db->begin_transaction()) return;
        [$record,$marker,$markerId]=achbotDirectGiftJournalLocked($db,$claim);
        $record['state']='review';$record['review_reason']=$reason;
        $record['review_at']=gmdate('c');$marker['state']='review';
        achbotDirectGiftWrite($db,$claim['type'],$record,$claim['id']);
        achbotDirectGiftWrite($db,$claim['marker_type'],$marker,$markerId);
        $db->commit();
    } catch (Throwable $e) {
        try {$db->rollback();} catch (Throwable $ignored) {}
        error_log('direct_gift_review_record_unconfirmed');
    }
}
