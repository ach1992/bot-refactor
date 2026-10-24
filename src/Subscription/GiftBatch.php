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

    function achbotGiftNewBatch(mysqli $db,int $serverId,int $volume,int $days): int {
        if ($serverId<=0 || $volume<0 || $days<0 ||
            ($volume===0 && $days===0) ||
            ($volume>0 && $volume%1024!==0))
            throw new RuntimeException('gift_new_batch_invalid_units');
        // This table, the receipt and both provider identity tables must
        // retain transactional behavior; otherwise no positive enqueue ACK.
        $engines=$db->query(
            "SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN
             ('gift_list','setting','server_config','server_info')");
        if (!$engines || $engines->num_rows!==4)
            throw new RuntimeException('gift_new_batch_engine_unverified');
        foreach ($engines->fetch_all(MYSQLI_ASSOC) as $entry) {
            if (strtoupper((string)$entry['ENGINE'])!=='INNODB')
                throw new RuntimeException('gift_new_batch_nontransactional');
        }
        $engines->free();
        if (!$db->begin_transaction())
            throw new RuntimeException('gift_new_batch_transaction');
        try {
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
            if ($db->commit()!==true)
                throw new RuntimeException('gift_enqueue_commit_unconfirmed');
            return $id;
        } catch (Throwable $e) {
            try { $db->rollback(); } catch (Throwable $ignored) {}
            throw $e;
        }
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
