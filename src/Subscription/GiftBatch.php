<?php
require_once __DIR__.'/../Admin/Access.php';
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
require_once __DIR__.'/../Panel/ProviderRuntime.php';

/** Current customer references are attachments to one native service, not gifts. */
function achbotGiftOrder(mysqli $db,int $id,bool $lock=false): array {
    $stmt=$db->prepare('SELECT id,userid,server_id,inbound_id,fileid,
        remark,uuid,status,expire_date FROM orders_list WHERE id=?'.($lock?' FOR UPDATE':''));
    if (!$stmt) throw new RuntimeException('gift_order_prepare');
    $stmt->bind_param('i',$id);
    $ok=$stmt->execute();$row=$ok?$stmt->get_result()->fetch_assoc():null;$stmt->close();
    if (!$row) throw new RuntimeException('gift_order_missing');
    // Legacy userid is VARCHAR: never let numeric coercion redirect a notice.
    $rawUserid=$row['userid']??null;
    if (!((is_int($rawUserid) && $rawUserid>0) ||
        (is_string($rawUserid) && preg_match('/\A[1-9][0-9]*\z/D',$rawUserid)===1 &&
         filter_var($rawUserid,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])!==false)))
        throw new RuntimeException('gift_recipient_identity_invalid');
    $order=[];
    foreach (['id','userid','server_id','inbound_id','fileid','status','expire_date'] as $key)
        $order[$key]=(int)$row[$key];
    foreach (['remark','uuid'] as $key) $order[$key]=$row[$key];
    if (min($order['id'],$order['userid'],$order['server_id'])<=0 ||
        $order['fileid']<0 || $order['status']!==1 || $order['inbound_id']!==0 ||
        $order['expire_date']<0 || $order['expire_date']>2147483647 ||
        !is_string($order['remark']) || $order['remark']==='' ||
        !($order['uuid']===null || is_string($order['uuid'])))
        throw new RuntimeException('gift_order_ineligible');
    return $order;
}

function achbotGiftReferenceOrigin(mysqli $db,array $order): ?array {
    require_once __DIR__.'/../Purchase/OrderWrites.php';
    $origin=achbotOrderImportOrigin($db,$order);
    $provider=achbotProviderType($db,$order['server_id']);
    if (($order['fileid']===0 && $origin===null) ||
        ($origin!==null && $origin['provider']!==$provider))
        throw new RuntimeException('gift_import_origin_unverified');
    return $origin;
}

/** Lock every reference PK before an anchor, journal or provider identity row.
 * The cohort may contain older aliases than the selected logical anchor.
 */
function achbotGiftLockReferences(mysqli $db,array $expected): void {
    $ordered=[];foreach ($expected as $order) {
        $id=$order['id']??null;
        if (!is_int($id) || $id<=0 || isset($ordered[$id]))
            throw new RuntimeException('gift_reference_lock_identity_invalid');
        $ordered[$id]=$order;
    }
    ksort($ordered,SORT_NUMERIC);
    foreach ($ordered as $order)
        if (achbotGiftOrder($db,$order['id'],true)!==$order)
            throw new RuntimeException('gift_reference_changed_after_claim');
}

/** Keep the existing complete PG validation without its unindexed origin lock.
 * Origin is immutable adoption evidence written with the new order. Mutable
 * order, connection and native effect identities are fenced by CURRENT PK
 * reads; a stale consistent-read context fails closed instead of replacing it.
 */
function achbotGiftPgOrderContext(mysqli $db,int $orderId,bool $lock=false): array {
    if (!$lock) return achbotPgOrderContext($db,$orderId);
    achbotPgLoad();
    $order=achbotProviderRow($db,'SELECT * FROM orders_list WHERE id=? FOR UPDATE','i',[$orderId]);
    if (!$order || $order['provider_effect_id']===null)
        throw new RuntimeException('gift_pg_reference_missing');
    $server=achbotProviderRow($db,'SELECT * FROM server_config WHERE id=? LOCK IN SHARE MODE',
        'i',[(int)$order['server_id']]);
    $account=achbotProviderAccountRow(achbotProviderRow($db,
        'SELECT * FROM service_effects WHERE id=? LOCK IN SHARE MODE',
        'i',[achbotProviderPositiveInt($order['provider_effect_id'])]));
    if (!$server || !$account || ($server['type']??null)!=='pasarguard')
        throw new RuntimeException('gift_pg_identity_changed');
    $context=achbotPgOrderContext($db,$orderId);
    foreach (['id','userid','server_id','inbound_id','fileid','remark','uuid','status',
              'expire_date','provider_effect_id','provider_remote_user_id',
              'provider_remote_created_at'] as $key)
        if (($context['order'][$key]??null)!==($order[$key]??null))
            throw new RuntimeException('gift_pg_current_order_changed');
    foreach (['id','effect_id','order_id','provider','panel_id','instance_key',
              'endpoint_sha256','remote_user_id','remote_created_at','remote_username',
              'operation_marker','state','entitlement_snapshot'] as $key)
        if (($context['account'][$key]??null)!==($account[$key]??null))
            throw new RuntimeException('gift_pg_current_account_changed');
    $panel=$context['panel'];
    if ((int)$server['provider_connection_id']!==$panel['id'] ||
        $server['provider_instance_key']!==$panel['instance_key'] ||
        rtrim($server['panel_url'],'/')!==rtrim($panel['api_url'],'/') ||
        $server['password']!==$panel['api_key'] ||
        $server['provider_version']!==$panel['version'] ||
        (int)$server['provider_enabled']!==$panel['enabled'] ||
        $context['identity']!==achbotProviderAccountIdentity($db,$account,true))
        throw new RuntimeException('gift_pg_current_identity_changed');
    return $context;
}

/** No count cap and no broad FOR UPDATE scan on unindexed customer/name fields. */
function achbotGiftReferences(mysqli $db,int $serverId,string $remark,bool $lock=false,bool $aliases=true): array {
    $stmt=$db->prepare('SELECT id,server_id FROM orders_list
        WHERE BINARY remark=BINARY ? AND inbound_id=0 AND status=1 ORDER BY id');
    if (!$stmt) throw new RuntimeException('gift_references_prepare');
    $stmt->bind_param('s',$remark);
    $ok=$stmt->execute();$ids=$ok?$stmt->get_result()->fetch_all(MYSQLI_ASSOC):null;$stmt->close();
    if ($ids===null) throw new RuntimeException('gift_references_unreadable');
    $provider=achbotProviderType($db,$serverId);$orders=[];$identity=null;$originIdentity=null;
    if (!in_array($provider,['marzban','pasarguard'],true))
        throw new RuntimeException('gift_provider_unsupported');
    foreach ($ids as $id) {
        $sid=(int)$id['server_id'];
        if ($sid!==$serverId && (!$aliases || !achbotSameProviderPanel($db,$serverId,$sid))) continue;
        $order=achbotGiftOrder($db,(int)$id['id']);
        if ($order['server_id']!==$sid || $order['remark']!==$remark)
            throw new RuntimeException('gift_reference_changed');
        $orders[]=$order;
    }
    if ($lock) achbotGiftLockReferences($db,$orders);
    foreach ($orders as $order) {
        $origin=achbotGiftReferenceOrigin($db,$order);
        if ($provider==='pasarguard') {
            $ctx=achbotGiftPgOrderContext($db,$order['id'],$lock);
            $candidate=[$ctx['account']['id'],$ctx['identity']];
        } else {
            $candidate=[$order['remark']];
            if ($origin!==null) {
                if ($originIdentity!==null && $originIdentity!==$origin['native_identity'])
                    throw new RuntimeException('gift_native_identity_conflict');
                $originIdentity=$origin['native_identity'];
            }
        }
        if ($identity!==null && $identity!==$candidate)
            throw new RuntimeException('gift_native_identity_conflict');
        $identity=$candidate;
    }
    return $orders;
}

function achbotGiftReferenceProof(array $orders): array {
    $recipients=[];$ids=[];
    foreach ($orders as $order) {$ids[]=$order['id'];$recipients[$order['userid']]=true;}
    $recipients=array_keys($recipients);sort($recipients,SORT_NUMERIC);
    return ['reference_ids'=>$ids,'recipient_userids'=>$recipients,
        'references_sha256'=>hash('sha256',json_encode($orders,JSON_THROW_ON_ERROR))];
}

function achbotGiftValidateReferences(mysqli $db,array $expected,bool $lock=false): void {
    if ($lock) achbotGiftLockReferences($db,$expected);
    $identity=null;$originIdentity=null;
    foreach ($expected as $order) {
        if (achbotGiftOrder($db,$order['id'],$lock)!==$order)
            throw new RuntimeException('gift_reference_changed_after_claim');
        $origin=achbotGiftReferenceOrigin($db,$order);
        if (achbotProviderType($db,$order['server_id'])==='pasarguard') {
            $ctx=achbotGiftPgOrderContext($db,$order['id'],$lock);
            $candidate=[$ctx['account']['id'],$ctx['identity']];
        } else {
            $candidate=[$order['remark']];
            if ($origin!==null) {
                if ($originIdentity!==null && $originIdentity!==$origin['native_identity'])
                    throw new RuntimeException('gift_native_identity_conflict');
                $originIdentity=$origin['native_identity'];
            }
        }
        if ($identity!==null && $identity!==$candidate)
            throw new RuntimeException('gift_native_identity_conflict');
        $identity=$candidate;
    }
}

/** SQL-only identity digest pins the provider incarnation independently of local plan/cache. */
function achbotGiftPinNativeIdentity(mysqli $db,array $orders,array $target): array {
    if (($target['provider']??'marzban')!=='marzban') return $target;
    $identity=null;
    foreach ($orders as $order) {
        $origin=achbotGiftReferenceOrigin($db,$order);
        if ($origin===null) continue;
        if ($identity!==null && $identity!==$origin['native_identity'])
            throw new RuntimeException('gift_native_identity_conflict');
        $identity=$origin['native_identity'];
    }
    if ($identity!==null) $target['marzban_native_identity']=$identity;
    return $target;
}

function achbotGiftIdentityHash(mysqli $db,array $orders): string {
    $proof=[];$targets=[];
    foreach ($orders as $order) {
        $origin=achbotGiftReferenceOrigin($db,$order);
        $type=achbotProviderType($db,$order['server_id']);
        $identity=$origin['native_identity']??null;
        if ($type==='pasarguard') {
            $ctx=achbotPgOrderContext($db,$order['id']);
            $identity=[$ctx['account']['id'],$ctx['panel']['id'],$ctx['panel']['instance_key'],$ctx['identity']];
        }
        $sid=$order['server_id'];
        if (!isset($targets[$sid]))
            $targets[$sid]=achbotGiftPublicTarget(achbotGiftPhysicalTarget($db,$sid));
        // A frozen alias must still point at the claimed physical connection.
        $proof[]=[$order['id'],$type,$identity,$targets[$sid]];
    }
    return hash('sha256',json_encode($proof,JSON_THROW_ON_ERROR));
}

function achbotGiftCompletionReport(string $reference,string $remark,int $days,int $volume,
    array $references,array $notices): bool {
    try {
        $ack=achbotNotifyAdmins('sendMessage',['text'=>\achbotMessage('gifts.completed_report',[
            'reference'=>htmlspecialchars($reference,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'),
            'remark'=>htmlspecialchars($remark,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'),
            'days'=>$days,'volume_mb'=>$volume,'reference_count'=>count($references),
            'recipient_count'=>count(achbotGiftReferenceProof($references)['recipient_userids']),
            'notice_unconfirmed_count'=>count($notices['unconfirmed'])
        ]),'parse_mode'=>'HTML'],['event'=>'general']);
        if (!achbotAdminFanoutAcknowledged($ack))
            throw new RuntimeException('gift_report_partial_acknowledgement');
        return true;
    } catch (Throwable $error) {
        error_log('achbot_gift_completion_report_unconfirmed reference='.$reference);
        return false;
    }
}

function achbotGiftDeliverNotices(mysqli $db,int $journalId,string $reference,
    array $orders,string $remark,int $volume,int $days): array {
    $notices=['acknowledged'=>[],'unconfirmed'=>achbotGiftReferenceProof($orders)['recipient_userids'],'send_exceptions'=>[]];
    try {$notices=achbotGiftNotifyCustomers($db,$orders,$remark,achbotGiftCustomerText($volume,$days));}
    catch (Throwable $error) {error_log('achbot_gift_customer_text_unconfirmed source_id='.$journalId);}
    $notices['report_acknowledged']=achbotGiftCompletionReport($reference,$remark,$days,$volume,$orders,$notices);
    achbotGiftRecordNotices($db,$journalId,$notices);
    return $notices;
}


function achbotGiftCustomerText(int $volume,int $days): string {
    $text='';
    if ($volume>0) $text.=\achbotMessage('jobs.gift2all.gift_text.8f714dee13',['volume'=>$volume]);
    if ($days>0) $text.=($text===''?'':\achbotMessage('jobs.gift2all.text.1a51e219ec')).
        \achbotMessage('jobs.gift2all.text.b70363d685',['day'=>$days]);
    return $text;
}

/** Best effort notice projection, separate from the already committed native outcome. */
function achbotGiftRecordNotices(mysqli $db,int $id,array $notices): void {
    try {
        $stmt=$db->prepare('SELECT value FROM setting WHERE id=?');
        if (!$stmt) throw new RuntimeException('gift_notice_journal_prepare');
        $stmt->bind_param('i',$id);$ok=$stmt->execute();
        $row=$ok?$stmt->get_result()->fetch_assoc():null;$stmt->close();
        $record=$row?json_decode($row['value'],true):null;
        if (!is_array($record) || ($record['state']??null)!=='completed' ||
            array_key_exists('customer_notices',$record))
            throw new RuntimeException('gift_notice_journal_changed');
        $record['customer_notices']=$notices;$record['notices_recorded_at']=gmdate('c');
        $json=json_encode($record,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        $stmt=$db->prepare('UPDATE setting SET value=? WHERE id=? AND BINARY value=BINARY ?');
        if (!$stmt) throw new RuntimeException('gift_notice_journal_update_prepare');
        $stmt->bind_param('sis',$json,$id,$row['value']);
        $ok=$stmt->execute();$changed=$stmt->affected_rows;$stmt->close();
        if (!$ok || $changed!==1) throw new RuntimeException('gift_notice_journal_unconfirmed');
    } catch (Throwable $error) {
        error_log('achbot_gift_notice_journal_unconfirmed source_id='.$id);
    }
}

/** Only lifecycle warning state is local. Native expiry/quota and purchase facts stay intact. */
function achbotGiftFinishReferences(mysqli $db,array $expected,bool $resetWarning): void {
    achbotGiftValidateReferences($db,$expected,true);
    foreach ($expected as $order) {
        $current=achbotGiftOrder($db,$order['id'],true);
        if ($current!==$order) throw new RuntimeException('gift_reference_changed_after_effect');
        if ($resetWarning) {
            $stmt=$db->prepare('UPDATE orders_list SET notif=0 WHERE id=? AND status=1');
            if (!$stmt) throw new RuntimeException('gift_warning_reset_prepare');
            $stmt->bind_param('i',$order['id']);$ok=$stmt->execute();$stmt->close();
            if (!$ok) throw new RuntimeException('gift_warning_reset_failed');
        }
    }
}

/** A failed/lost Telegram acknowledgement never reopens native gift admission. */
function achbotGiftNotifyCustomers(mysqli $db,array $orders,string $remark,string $giftText): array {
    $sent=[];$failed=[];$sendExceptions=[];
    foreach ($orders as $order) {
        $uid=$order['userid'];
        if (isset($sent[$uid]) || isset($failed[$uid])) continue;
        try {
            if (achbotGiftOrder($db,$order['id'])!==$order)
                throw new RuntimeException('gift_notice_reference_changed');
            $stmt=$db->prepare('SELECT id FROM users WHERE BINARY userid=BINARY ? LIMIT 2');
            if (!$stmt) throw new RuntimeException('gift_notice_customer_prepare');
            $uidText=(string)$uid;
            $stmt->bind_param('s',$uidText);$ok=$stmt->execute();
            $rows=$ok?$stmt->get_result()->fetch_all(MYSQLI_ASSOC):[];$stmt->close();
            if (count($rows)!==1) throw new RuntimeException('gift_notice_customer_ambiguous');
            $customerMessage=\achbotMessage('jobs.gift2all.message.1bf0942a3d',[
                'remark'=>htmlspecialchars($remark,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'),'giftText'=>$giftText]);
            try {$response=sendMessage($customerMessage,null,'HTML',$uid);}
            catch (Throwable $sendError) {
                $sendExceptions[$uid]=true;
                throw $sendError;
            }
            if (!is_object($response) || ($response->ok??false)!==true)
                throw new RuntimeException('gift_notice_unacknowledged');
            $sent[$uid]=true;
        } catch (Throwable $error) {
            $failed[$uid]=true;
            error_log('achbot_gift_customer_notice_unconfirmed order_id='.$order['id'].' userid='.$uid);
        }
    }
    return ['acknowledged'=>array_keys($sent),'unconfirmed'=>array_keys($failed),
        'send_exceptions'=>array_keys($sendExceptions)];
}
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
        if ($row && ($row['type']??null)==='pasarguard') {
            $panel=achbotPgServerPanel($db,$serverId);
            return ['provider'=>'pasarguard','panel_id'=>$panel['id'],'instance_key'=>$panel['instance_key'],
                'panel_url'=>$panel['api_url'],'credential_hash'=>hash('sha256',$panel['api_key']),
                'group_id'=>null,'has_group_column'=>false];
        }
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
        if (($target['provider']??'')==='pasarguard') return array_intersect_key($target,array_flip([
            'provider','panel_id','instance_key','panel_url','credential_hash','group_id','has_group_column']));
        return [
            'panel_url'=>$target['panel_url'],
            'credential_hash'=>$target['credential_hash'],
            'group_id'=>$target['group_id'],
            'has_group_column'=>$target['has_group_column'],
        ]+(isset($target['marzban_native_identity'])?
            ['marzban_native_identity'=>$target['marzban_native_identity']]:[]);
    }

    function achbotGiftTargetMatches(mysqli $db,int $serverId,array $original): bool {
        try {
            $current=achbotGiftPhysicalTarget($db,$serverId);
            $expected=achbotGiftPublicTarget($original);
            unset($expected['marzban_native_identity']);
            $actual=achbotGiftPublicTarget($current);
            if (($expected['provider']??'')==='pasarguard') return $expected===$actual;
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
     * Enqueue provenance, source claim and the consumed administrator step
     * commit atomically with gift_list. A replay never touches newer steps.
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
                ($actor!==$primaryAdminId && (int)$actors[0]['isAdmin']!==1) ||
                !achbotAdminCanLocked($db,$actor,$primaryAdminId,'operations'))
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

            // This step authorized exactly ONE new enqueue. Consume it
            // under the ALREADY-HELD actor row lock before COMMIT, not
            // later in a Telegram/UI handler. Otherwise another valid
            // update_id can reuse the same step, or a late replay could
            // erase the next generation with identical step text.
            // users.userid is indexed but NOT UNIQUE in the frozen schema;
            // the actor's verified users.id PRIMARY KEY is authoritative.
            $actorRowId=(int)$actors[0]['id'];
            if ($actorRowId<=0)
                throw new RuntimeException('gift_step_actor_id_invalid');
            $stmt=$db->prepare(
                "UPDATE users SET step='none' WHERE id=? AND userid=? AND step=?");
            if (!$stmt) throw new RuntimeException('gift_step_consume_prepare');
            $stmt->bind_param('iis',$actorRowId,$actor,$expectedStep);
            $ok=$stmt->execute();$changed=$stmt->affected_rows;$stmt->close();
            if (!$ok || $changed!==1)
                throw new RuntimeException('gift_step_consume_unconfirmed');

            if ($db->commit()!==true)
                throw new RuntimeException('gift_enqueue_commit_unconfirmed');
            return ['id'=>$id,'created'=>true];
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
