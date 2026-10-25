<?php
/**
 * Source-only Post-V9 bulk gift batch identity.
 *
 * A provenance record is committed in the SAME transaction as the new
 * gift_list row. Unmarked legacy jobs are never assumed to be untouched:
 * the original Cron could have applied a remote edit before offset changed.
 *
 * Physical destination identity is an original URL + credentials fingerprint
 * + optional panel group identity. Secret values are used only transiently
 * for authenticated requests, and never written to setting rows or logs.
 */
if (!function_exists('achbotGiftPhysicalTarget')) {
    function achbotGiftPhysicalTarget(mysqli $db, int $serverId,
                                      bool $lock=false): array {
        if ($serverId<=0) throw new RuntimeException('gift_invalid_server');
        $suffix=$lock?' FOR UPDATE':'';
        $stmt=$db->prepare(
            'SELECT type,panel_url,username,password FROM server_config WHERE id=?'.$suffix);
        if (!$stmt) throw new RuntimeException('gift_target_prepare');
        $stmt->bind_param('i',$serverId);
        $ok=$stmt->execute();
        $row=$ok?$stmt->get_result()->fetch_assoc():null;
        $stmt->close();
        if (!$row || ($row['type']??null)!=='marzban')
            throw new RuntimeException('gift_target_unsupported');
        $url=rtrim((string)($row['panel_url']??''),'/');
        $urlParts=parse_url($url);
        if (!is_array($urlParts) ||
            !in_array($urlParts['scheme']??null,['http','https'],true) ||
            empty($urlParts['host']) ||
            (isset($urlParts['user']) || isset($urlParts['pass'])) ||
            isset($urlParts['query']) || isset($urlParts['fragment']) ||
            $url==='') throw new RuntimeException('gift_target_url_invalid');
        $username=(string)($row['username']??'');
        $password=(string)($row['password']??'');
        if ($username==='' || $password==='')
            throw new RuntimeException('gift_target_credentials_missing');

        // Legacy schema has no panel_group_id column; an installed 001
        // migration may add it. Mirror the CREATE/edit transport semantics.
        $columns=$db->query("SHOW COLUMNS FROM server_info LIKE 'panel_group_id'");
        if (!$columns) throw new RuntimeException('gift_group_schema_unverified');
        $hasGroup=$columns->num_rows===1;
        $columns->free();
        $group=null;
        if (!$hasGroup) {
            $stmt=$db->prepare('SELECT id FROM server_info WHERE id=?'.$suffix);
            if (!$stmt) throw new RuntimeException('gift_server_inventory_prepare');
            $stmt->bind_param('i',$serverId);
            $ok=$stmt->execute();
            $inventory=$ok?$stmt->get_result()->fetch_assoc():null;
            $stmt->close();
            if (!$inventory) throw new RuntimeException('gift_server_inventory_missing');
        }
        if ($hasGroup) {
            $stmt=$db->prepare('SELECT panel_group_id FROM server_info WHERE id=?'.$suffix);
            if (!$stmt) throw new RuntimeException('gift_group_prepare');
            $stmt->bind_param('i',$serverId);
            $ok=$stmt->execute();
            $record=$ok?$stmt->get_result()->fetch_assoc():null;
            $stmt->close();
            if (!$record) throw new RuntimeException('gift_group_missing');
            if ($record['panel_group_id']!==null) {
                $group=(int)$record['panel_group_id'];
                if ($group<=0) throw new RuntimeException('gift_group_invalid');
                $stmt=$db->prepare(
                    "SELECT id FROM setting WHERE id=? AND type='ACHBOT_MARZBAN_PANEL_GROUP'");
                if (!$stmt) throw new RuntimeException('gift_group_identity_prepare');
                $stmt->bind_param('i',$group);
                $ok=$stmt->execute();
                $present=$ok?$stmt->get_result()->fetch_assoc():null;
                $stmt->close();
                if (!$present) throw new RuntimeException('gift_group_identity_missing');
            }
        }
        $credentialHash=hash('sha256',json_encode(
            [$username,$password],JSON_THROW_ON_ERROR));
        return [
            'panel_url'=>$url,'username'=>$username,'password'=>$password,
            'credential_hash'=>$credentialHash,'group_id'=>$group,
            'has_group_column'=>$hasGroup
        ];
    }

    function achbotGiftPublicTarget(array $target): array {
        return [
            'panel_url'=>$target['panel_url'],
            'credential_hash'=>$target['credential_hash'],
            'group_id'=>$target['group_id'],
            'has_group_column'=>$target['has_group_column'],
        ];
    }

    function achbotGiftTargetMatches(mysqli $db,int $serverId,array $original): bool {
        try {
            $current=achbotGiftPhysicalTarget($db,$serverId);
            $expected=achbotGiftPublicTarget($original);
            $actual=achbotGiftPublicTarget($current);
            return is_string($expected['credential_hash']??null) &&
                hash_equals($expected['credential_hash'],$actual['credential_hash']) &&
                $expected['panel_url']===$actual['panel_url'] &&
                $expected['group_id']===$actual['group_id'] &&
                $expected['has_group_column']===$actual['has_group_column'];
        } catch (Throwable $e) {
            return false;
        }
    }

    function achbotGiftProvenanceType(int $id): string {
        return 'ACHBOT_GIFT_ENQUEUE_'.$id;
    }

    /**
     * Accept only a NEW, privately authored Telegram message. The shared
     * UpdateBindings.php $text binding also represents OLD bot text for
     * callbacks, so a text-step value alone is never trusted.
     */
    function achbotGiftTelegramSource(
        $update, $actor, $chatId, $messageId, $boundText
    ): array {
        if (!is_object($update) || !is_int($update->update_id??null) ||
            $update->update_id<=0)
            throw new RuntimeException('gift_source_update_invalid');
        $keys=array_keys(get_object_vars($update));
        sort($keys);
        if ($keys!==['message','update_id'] || !is_object($update->message))
            throw new RuntimeException('gift_source_fresh_message_required');
        $message=$update->message;
        $sender=$message->from??null;
        $chat=$message->chat??null;
        if (!is_object($sender) || !is_object($chat) ||
            !is_int($sender->id??null) || $sender->id<=0 ||
            ($sender->is_bot??false)===true ||
            !is_int($chat->id??null) ||
            ($chat->type??null)!=='private' ||
            $chat->id!==$sender->id ||
            !is_int($message->message_id??null) ||
            $message->message_id<=0 ||
            !is_string($message->text??null) ||
            !is_string($boundText) ||
            $message->text!==$boundText ||
            $sender->id!==(int)$actor ||
            $chat->id!==(int)$chatId ||
            $message->message_id!==(int)$messageId)
            throw new RuntimeException('gift_source_envelope_mismatch');
        return [
            'kind'=>'telegram_bulk_gift_v1',
            'update_id'=>$update->update_id,
            'actor_id'=>$sender->id,
            'chat_id'=>$chat->id,
            'message_id'=>$message->message_id,
            'text_sha256'=>hash('sha256',$message->text)
        ];
    }

    /** One claim per Telegram update ID for this bot, not per job or actor.
     * The persisted source record additionally binds actor/chat/message;
     * a forged actor or changed message with the same ID is a conflict.
     */
    function achbotGiftSourceType(array $source): string {
        if (array_keys($source)!==[
            'kind','update_id','actor_id','chat_id','message_id','text_sha256'
        ] || ($source['kind']??null)!=='telegram_bulk_gift_v1' ||
            !is_int($source['update_id']??null) || $source['update_id']<=0 ||
            !is_int($source['actor_id']??null) || $source['actor_id']<=0 ||
            !is_int($source['chat_id']??null) ||
            $source['chat_id']!==$source['actor_id'] ||
            !is_int($source['message_id']??null) || $source['message_id']<=0 ||
            !is_string($source['text_sha256']??null) ||
            preg_match('/^[a-f0-9]{64}$/D',$source['text_sha256'])!==1)
            throw new RuntimeException('gift_source_identity_invalid');
        return 'ACHBOT_GIFT_SOURCE_'.hash('sha256',json_encode([
            $source['kind'],$source['update_id']
        ],JSON_THROW_ON_ERROR));
    }

    /**
     * A user row is lockable by its genuine id PK even though users.userid
     * is NOT UNIQUE in the original 23-table schema. Reject ambiguous users.
     *
     * Keep the source claim forever after batch completion. On repeated
     * webhook delivery, return the previous batch id (including when the
     * batch itself was already deleted by successful Cron completion).
     * Enqueue provenance and source claim commit atomically with gift_list.
     */
    function achbotGiftNewBatch(
        mysqli $db,int $serverId,int $volume,int $days,
        array $source,int $primaryAdminId,string $expectedStep
    ): array {
        $sourceType=achbotGiftSourceType($source);
        $actor=$source['actor_id'];
        if ($serverId<=0 || $volume<0 || $days<0 ||
            ($volume===0 && $days===0) ||
            ($volume>0 && $volume%1024!==0) ||
            $primaryAdminId<=0 ||
            $expectedStep!=='giftServerVolume'.$serverId.'_'.$days)
            throw new RuntimeException('gift_new_batch_invalid_request');

        // This table, the receipt, the physical destination and the actor
        // ownership lock all require real InnoDB transaction guarantees.
        $engines=$db->query(
            "SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN
             ('gift_list','setting','server_config','server_info','users')");
        if (!$engines || $engines->num_rows!==5)
            throw new RuntimeException('gift_new_batch_engine_unverified');
        foreach ($engines->fetch_all(MYSQLI_ASSOC) as $entry) {
            if (strtoupper((string)$entry['ENGINE'])!=='INNODB')
                throw new RuntimeException('gift_new_batch_nontransactional');
        }
        $engines->free();
        if (!$db->begin_transaction())
            throw new RuntimeException('gift_new_batch_transaction');
        try {
            // The primary admin's EXISTING users row is the global
            // serialization owner for all gift-source claims. A per-actor
            // lock alone would not serialize forged cross-actor claims for
            // the same globally unique Telegram update_id, while
            // setting.type is not UNIQUE. Both ids are checked for
            // exactly one actual row before using any privilege or step.
            $ownerStmt=$db->prepare(
                'SELECT id,userid,isAdmin,step FROM users WHERE userid=? LIMIT 2 FOR UPDATE');
            if (!$ownerStmt) throw new RuntimeException('gift_owner_lock_prepare');
            $ownerStmt->bind_param('i',$primaryAdminId);
            $ok=$ownerStmt->execute();
            $owners=$ok?$ownerStmt->get_result()->fetch_all(MYSQLI_ASSOC):[];
            $ownerStmt->close();
            if (count($owners)!==1 ||
                (int)$owners[0]['userid']!==$primaryAdminId)
                throw new RuntimeException('gift_primary_owner_missing_or_ambiguous');
            $actors=$owners;
            if ($actor!==$primaryAdminId) {
                $actorStmt=$db->prepare(
                    'SELECT id,userid,isAdmin,step FROM users WHERE userid=? LIMIT 2 FOR UPDATE');
                if (!$actorStmt) throw new RuntimeException('gift_actor_lock_prepare');
                $actorStmt->bind_param('i',$actor);
                $ok=$actorStmt->execute();
                $actors=$ok?$actorStmt->get_result()->fetch_all(MYSQLI_ASSOC):[];
                $actorStmt->close();
            }
            if (count($actors)!==1 || (int)$actors[0]['userid']!==$actor ||
                ($actor!==$primaryAdminId && (int)$actors[0]['isAdmin']!==1))
                throw new RuntimeException('gift_source_actor_unauthorized_or_ambiguous');
            $claim=$db->prepare('SELECT id,value FROM setting WHERE type=? LIMIT 2');
            if (!$claim) throw new RuntimeException('gift_source_claim_prepare');
            $claim->bind_param('s',$sourceType);
            $ok=$claim->execute();
            $claims=$ok?$claim->get_result()->fetch_all(MYSQLI_ASSOC):[];
            $claim->close();
            if (!$ok || count($claims)>1)
                throw new RuntimeException('gift_source_claim_ambiguous');
            if (count($claims)===1) {
                $prior=json_decode((string)$claims[0]['value'],true);
                if (!is_array($prior) || ($prior['version']??null)!==1 ||
                    ($prior['source_type']??null)!==$sourceType ||
                    ($prior['source']??null)!==$source ||
                    ($prior['server_id']??null)!==$serverId ||
                    ($prior['volume_mb']??null)!==$volume ||
                    ($prior['days']??null)!==$days ||
                    ($prior['step']??null)!==$expectedStep ||
                    !is_int($prior['gift_id']??null) || $prior['gift_id']<=0)
                    throw new RuntimeException('gift_source_claim_conflict');
                if ($db->commit()!==true)
                    throw new RuntimeException('gift_source_replay_commit_unconfirmed');
                return ['id'=>$prior['gift_id'],'created'=>false];
            }
            // A new update must still be in the legitimate administrator's
            // active final text step at the *transactional* source-of-truth.
            if ($actors[0]['step']!==$expectedStep)
                throw new RuntimeException('gift_source_admin_step_changed');

            $target=achbotGiftPhysicalTarget($db,$serverId,true);
            $stmt=$db->prepare(
                'INSERT INTO gift_list (server_id,volume,day,`offset`) VALUES (?,?,?,0)');
            if (!$stmt) throw new RuntimeException('gift_new_batch_prepare');
            $stmt->bind_param('iii',$serverId,$volume,$days);
            $ok=$stmt->execute();$id=(int)$stmt->insert_id;$stmt->close();
            if (!$ok || $id<=0) throw new RuntimeException('gift_new_batch_insert');

            $record=[
                'version'=>2,'source'=>'achbot_post_v9_gift_enqueue',
                'gift_id'=>$id,'server_id'=>$serverId,
                'volume_mb'=>$volume,'days'=>$days,
                'target'=>achbotGiftPublicTarget($target),
                'enqueue_source_type'=>$sourceType,
                'nonce'=>bin2hex(random_bytes(16)),
                'created_at'=>gmdate('c')
            ];
            $encoded=json_encode($record,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            $type=achbotGiftProvenanceType($id);
            $stmt=$db->prepare('INSERT INTO setting (type,value) VALUES (?,?)');
            if (!$stmt) throw new RuntimeException('gift_provenance_prepare');
            $stmt->bind_param('ss',$type,$encoded);
            $ok=$stmt->execute();$receiptId=(int)$stmt->insert_id;$stmt->close();
            if (!$ok || $receiptId<=0)
                throw new RuntimeException('gift_provenance_insert');

            $claimRecord=[
                'version'=>1,'source_type'=>$sourceType,'source'=>$source,
                'server_id'=>$serverId,'volume_mb'=>$volume,'days'=>$days,
                'step'=>$expectedStep,'gift_id'=>$id,'created_at'=>gmdate('c')
            ];
            $claimJson=json_encode($claimRecord,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            $stmt=$db->prepare('INSERT INTO setting (type,value) VALUES (?,?)');
            if (!$stmt) throw new RuntimeException('gift_source_claim_insert_prepare');
            $stmt->bind_param('ss',$sourceType,$claimJson);
            $ok=$stmt->execute();$sourceId=(int)$stmt->insert_id;$stmt->close();
            if (!$ok || $sourceId<=0)
                throw new RuntimeException('gift_source_claim_insert_failed');

            if ($db->commit()!==true)
                throw new RuntimeException('gift_enqueue_commit_unconfirmed');
            return ['id'=>$id,'created'=>true];
        } catch (Throwable $e) {
            try { $db->rollback(); } catch (Throwable $ignored) {}
            throw $e;
        }
    }

    /** Presentation-only CAS: do not wipe a newer administrator step. */
    function achbotGiftDismissEnqueueStep(
        mysqli $db,int $actor,string $expectedStep
    ): bool {
        // This is UI-state presentation after the enqueue COMMIT; the
        // original users.userid is not UNIQUE. Resolve exactly one genuine
        // owner row and CAS only its UNIQUE id, never UPDATE by userid alone.
        $query=$db->prepare('SELECT id FROM users WHERE userid=? LIMIT 2');
        if (!$query) throw new RuntimeException('gift_step_owner_prepare');
        $query->bind_param('i',$actor);
        $ok=$query->execute();
        $owners=$ok?$query->get_result()->fetch_all(MYSQLI_ASSOC):[];
        $query->close();
        if (count($owners)!==1)
            throw new RuntimeException('gift_step_owner_ambiguous');
        $rowId=(int)$owners[0]['id'];
        $stmt=$db->prepare(
            "UPDATE users SET step='none' WHERE id=? AND userid=? AND step=?");
        if (!$stmt) throw new RuntimeException('gift_step_clear_prepare');
        $stmt->bind_param('iis',$rowId,$actor,$expectedStep);
        $ok=$stmt->execute();
        $changed=$stmt->affected_rows;
        $stmt->close();
        if (!$ok || $changed>1)
            throw new RuntimeException('gift_step_clear_unconfirmed');
        return $changed===1;
    }

    function achbotGiftReadProvenance(mysqli $db,array $gift): ?array {
        $id=(int)$gift['id'];
        if ($id<=0) return null;
        $type=achbotGiftProvenanceType($id);
        $stmt=$db->prepare('SELECT id,value FROM setting WHERE type=? LIMIT 2');
        if (!$stmt) return null;
        $stmt->bind_param('s',$type);
        $ok=$stmt->execute();
        $rows=$ok?$stmt->get_result()->fetch_all(MYSQLI_ASSOC):[];
        $stmt->close();
        if (count($rows)!==1) return null;
        $value=json_decode((string)$rows[0]['value'],true);
        $target=$value['target']??null;
        if (!is_array($value) ||
            ($value['version']??null)!==2 ||
            ($value['source']??null)!=='achbot_post_v9_gift_enqueue' ||
            ($value['gift_id']??null)!==$id ||
            ($value['server_id']??null)!==(int)$gift['server_id'] ||
            ($value['volume_mb']??null)!==(int)$gift['volume'] ||
            ($value['days']??null)!==(int)$gift['day'] ||
            !is_string($value['nonce']??null) ||
            preg_match('/^[a-f0-9]{32}$/D',$value['nonce'])!==1 ||
            !is_array($target) ||
            !is_string($target['panel_url']??null) ||
            !is_string($target['credential_hash']??null) ||
            preg_match('/^[a-f0-9]{64}$/D',$target['credential_hash'])!==1 ||
            !array_key_exists('group_id',$target) ||
            !is_bool($target['has_group_column']??null) ||
            ($target['group_id']!==null &&
             (!is_int($target['group_id']) || $target['group_id']<=0)))
            return null;
        return $value;
    }

    function achbotGiftResolveTarget(mysqli $db,int $serverId,array $receipt): ?array {
        try {
            $current=achbotGiftPhysicalTarget($db,$serverId);
            if (!achbotGiftTargetMatches($db,$serverId,$receipt['target']))
                return null;
            return $current; // Raw credentials stay transient; never journal.
        } catch (Throwable $e) {
            return null;
        }
    }
}
