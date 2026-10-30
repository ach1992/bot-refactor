<?php
require_once __DIR__.'/../Admin/Access.php';
/** AChBot V6: original Telegram account/subscription routes; keep caller scope. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG


//Added by ACh

if (preg_match('/^addSubscriptionToCustomer([1-9][0-9]*)$/D',(string)$data,$match)) {
    $serverId=(int)$match[1];$actor=(int)$from_id;
    if (!function_exists('achbotExternalImportFrame') || !achbotExternalImportFrame($update,$actor,(string)$data)) return;
    $tempData=json_decode((string)($userInfo['temp']??''),true)?:[];
    if ((int)($tempData['server_id']??0)!==$serverId || empty($tempData['import_url'])) {
        alert(\achbotMessage('subscription.manual_accounts.import_unverified'),true);return;
    }
    try {
        if (!$connection->begin_transaction()) throw new RuntimeException('import_select_begin');
        $q=$connection->prepare('SELECT id,userid,isAdmin,step,temp FROM users WHERE userid=? LIMIT 2 FOR UPDATE');
        $q->bind_param('i',$actor);$q->execute();$rows=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
        if (count($rows)!==1 || $rows[0]['step']==='banned' || $rows[0]['temp']!==($userInfo['temp']??null) ||
            ($actor!==(int)$admin && (int)$rows[0]['isAdmin']!==1) ||
            !achbotAdminCanLocked($connection,$actor,(int)$admin,'operations')) {
            $connection->rollback();alert(\achbotMessage('subscription.manual_accounts.alert.03aea25611'),true);return;
        }
        $tempData['awaiting_userid_for_add']=true;$tempData['selected_server_id']=$serverId;$tempData['addToCustomerClicked']=true;
        $tempJson=json_encode($tempData,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$id=(int)$rows[0]['id'];
        $q=$connection->prepare("UPDATE users SET temp=?,step='awaiting_userid_input' WHERE id=? AND userid=?");
        $q->bind_param('sii',$tempJson,$id,$actor);$q->execute();$q->close();
        if (!$connection->commit()) throw new RuntimeException('import_select_commit_unknown');
    } catch (Throwable $error) {
        try {$connection->rollback();} catch (Throwable $ignored) {}
        error_log('achbot_import_customer_selection_deferred');return;
    }
    $msg = \achbotMessage('subscription.manual_accounts.msg.a88437a461');
    $keyboard = [
        [['text' => $buttonValues['back_to_main'], 'callback_data' => 'mainMenu']]
    ];

    sendMessage($msg, json_encode(['inline_keyboard' => $keyboard]), "html", $from_id);
    exit();
}

$tempData = json_decode($userInfo['temp'], true) ?: [];

/**
 * Consume the legacy admin user-id selection atomically on the exact actor PK.
 * Cached config.php userInfo is only a routing hint; neither step nor temp
 * may be overwritten after a newer direct gift (or another legacy wizard)
 * has acquired this user row. No schema change or provider operation.
 */
function achbotManualAccountConsumeSelection(mysqli $db,int $actorId,
        int $primary,array $cached,string $newTemp): bool {
    if ($actorId<=0 || ($cached['step']??null)!=='awaiting_userid_input' ||
        !isset($cached['id'],$cached['userid']) ||
        (int)$cached['userid']!==$actorId ||
        !filter_var((string)$cached['id'],FILTER_VALIDATE_INT,
            ['options'=>['min_range'=>1,'max_range'=>2147483647]]))
        return false;
    $id=(int)$cached['id'];
    $stmt=$db->prepare('SELECT id FROM users WHERE userid=? LIMIT 2');
    if (!$stmt) throw new RuntimeException('manual_account_pk_prepare');
    $stmt->bind_param('i',$actorId);
    $ok=$stmt->execute();$ids=$ok?$stmt->get_result()->fetch_all(MYSQLI_ASSOC):[];
    $stmt->close();
    if (!$ok || count($ids)!==1 || (int)$ids[0]['id']!==$id)
        throw new RuntimeException('manual_account_actor_ambiguous');
    if (!$db->begin_transaction())
        throw new RuntimeException('manual_account_begin_failed');
    try {
        $stmt=$db->prepare('SELECT id,userid,step,temp,isAdmin
                            FROM users WHERE id=? FOR UPDATE');
        if (!$stmt) throw new RuntimeException('manual_account_lock_prepare');
        $stmt->bind_param('i',$id);
        $ok=$stmt->execute();
        $current=$ok?$stmt->get_result()->fetch_assoc():null;
        $stmt->close();
        if (!$ok) throw new RuntimeException('manual_account_lock_failed');
        if (!$current || (int)$current['userid']!==$actorId ||
            $current['step']!=='awaiting_userid_input' ||
            $current['temp']!==($cached['temp']??null) ||
            ($actorId!==$primary && (int)$current['isAdmin']!==1) ||
            !achbotAdminCanLocked($db,$actorId,$primary,'operations')) {
            $db->rollback();
            return false;
        }
        // A post-lock identity lookup closes the nonunique userid gap.
        $stmt=$db->prepare('SELECT id FROM users WHERE userid=? LIMIT 2');
        if (!$stmt) throw new RuntimeException('manual_account_identity_prepare');
        $stmt->bind_param('i',$actorId);
        $ok=$stmt->execute();
        $ids=$ok?$stmt->get_result()->fetch_all(MYSQLI_ASSOC):[];
        $stmt->close();
        if (!$ok || count($ids)!==1 || (int)$ids[0]['id']!==$id)
            throw new RuntimeException('manual_account_identity_drift');
        $next='';
        $expected='awaiting_userid_input';
        $expectedTemp=$current['temp'];
        $stmt=$db->prepare('UPDATE users SET temp=?,step=?
             WHERE id=? AND userid=? AND step=? AND temp <=> ?');
        if (!$stmt) throw new RuntimeException('manual_account_cas_prepare');
        $stmt->bind_param('ssiiss',$newTemp,$next,$id,$actorId,$expected,$expectedTemp);
        $ok=$stmt->execute();$affected=$stmt->affected_rows;$stmt->close();
        if (!$ok || $affected!==1)
            throw new RuntimeException('manual_account_cas_unconfirmed');
        if (!$db->commit())
            throw new RuntimeException('manual_account_commit_unknown');
        return true;
    } catch (Throwable $e) {
        try {$db->rollback();} catch (Throwable $ignored) {}
        throw $e;
    }
}

if ($userInfo['step'] == "awaiting_userid_input" && $text !== $buttonValues['cancel']) {
    // Never treat old bot text embedded in a callback as a newly authored
    // numeric step, even when the current actor is still an administrator.
    try {
        require_once __DIR__.'/GiftBatch.php';
        achbotGiftTelegramSource($update,$from_id,$chat_id,$message_id,$text);
    } catch (Throwable $e) {
        error_log('manual_account_source_unconfirmed');
        exit();
    }
    $actor=(int)$from_id;
    $q=$connection->prepare('SELECT id,isAdmin,step,temp FROM users WHERE userid=? LIMIT 2');
    $q->bind_param('i',$actor);$q->execute();$actors=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
    if (count($actors)!==1 || $actors[0]['step']!=='awaiting_userid_input' ||
        $actors[0]['temp']!==($userInfo['temp']??null) ||
        ($actor!==(int)$admin && (int)$actors[0]['isAdmin']!==1)) return;
    $customUserId=preg_match('/^[1-9][0-9]{0,18}$/D',(string)$text)===1?
        filter_var($text,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]):false;
    $targets=[];
    if ($customUserId!==false) {
        $q=$connection->prepare('SELECT id FROM users WHERE userid=? LIMIT 2');
        $q->bind_param('i',$customUserId);$q->execute();$targets=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
    }
    if (count($targets)!==1) {
        sendMessage(\achbotMessage('subscription.manual_accounts.customer_not_found'),$cancelKey,'html');
        return;
    }
    $tempData = json_decode($userInfo['temp'], true) ?: [];
    $tempData['custom_userid'] = $customUserId;
    unset($tempData['awaiting_userid_for_add']);
    try {
        $tempJson = json_encode($tempData,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $confirmed = achbotManualAccountConsumeSelection(
            $connection,(int)$from_id,(int)$admin,$userInfo,$tempJson);
        if (!$confirmed) {
            error_log('manual_account_stale_step_or_role');
            exit();
        }
    } catch (Throwable $e) {
        // Lost COMMIT acknowledgments are uncertain, NOT proof of failure.
        error_log('manual_account_selection_commit_unconfirmed');
        exit();
    }

    $serverId = intval($tempData['selected_server_id'] ?? 0);
    $msg = \achbotMessage('subscription.manual_accounts.manual_account_consume_selection.msg.86a1eb982f', ['customUserId' => $customUserId]);
    $keyboard = [
        [['text' => \achbotMessage('subscription.manual_accounts.manual_account_consume_selection.button.0c983c9f0f'), 'callback_data' => "addToOrders" . $serverId]],
        [['text' => \achbotMessage('subscription.manual_accounts.manual_account_consume_selection.button.11b0188fcb'), 'callback_data' => "searchUsersConfig"]],
        [['text' => $buttonValues['back_to_main'], 'callback_data' => "mainMenu"]]
    ];
    sendMessage($msg, json_encode(['inline_keyboard' => $keyboard]), "html", $from_id);
    exit();
}

function detectServerIdFromRemark($connection, $remark, $defaultServerId = 0) {
    $remark = mb_strtolower(trim((string)$remark));
    if ($remark === '') {
        return $defaultServerId;
    }

    $stmt = $connection->prepare("
        SELECT `id`, `remark`
        FROM `server_info`
        WHERE `active` = 1
        ORDER BY CHAR_LENGTH(`remark`) DESC, `id` ASC
    ");
    $stmt->execute();
    $result = $stmt->get_result();

    $firstActiveServerId = 0;

    while ($row = $result->fetch_assoc()) {
        $serverId = (int)$row['id'];
        $prefixRaw = trim((string)$row['remark']);

        if ($firstActiveServerId === 0) {
            $firstActiveServerId = $serverId;
        }

        if ($prefixRaw === '') {
            continue;
        }

        $prefixes = preg_split('/[\s,|]+/u', $prefixRaw, -1, PREG_SPLIT_NO_EMPTY);

        foreach ($prefixes as $prefix) {
            $prefix = mb_strtolower(trim($prefix));
            if ($prefix === '') {
                continue;
            }

            if (mb_substr($remark, 0, mb_strlen($prefix)) === $prefix) {
                $stmt->close();
                return $serverId;
            }
        }
    }

    $stmt->close();

    if ($firstActiveServerId > 0) {
        return $firstActiveServerId;
    }

    return $defaultServerId;
}


if (preg_match('/^addToOrders([1-9][0-9]*)$/D',(string)$data,$match)) {
    $actor=(int)$from_id;$serverId=(int)$match[1];$begun=false;$committed=false;
    try {
        if (!function_exists('achbotExternalImportFrame') ||
            !achbotExternalImportFrame($update,$actor,(string)$data)) return;
        $tempData=json_decode((string)($userInfo['temp']??''),true)?:[];
        $remark=trim((string)($tempData['remark']??''));
        $input=(string)($tempData['import_url']??'');
        if ($remark==='' || $input==='' || (int)($tempData['server_id']??0)!==$serverId ||
            (isset($tempData['selected_server_id']) && (int)$tempData['selected_server_id']!==$serverId))
            throw new RuntimeException('import_identity_missing');
        $role=$connection->prepare('SELECT id,userid,isAdmin,step,temp FROM users WHERE userid=? LIMIT 2');
        $role->bind_param('i',$actor);$role->execute();$actors=$role->get_result()->fetch_all(MYSQLI_ASSOC);$role->close();
        if (count($actors)!==1 || $actors[0]['step']==='banned' ||
            $actors[0]['temp']!==($userInfo['temp']??null)) throw new RuntimeException('import_stale');
        $adminActor=achbotAdminCan('operations',$actor);
        $finalUserId=isset($tempData['custom_userid'])?(int)$tempData['custom_userid']:$actor;
        if ($finalUserId<=0 || ($finalUserId!==$actor && !$adminActor)) throw new RuntimeException('import_actor');
        $q=$connection->prepare("SELECT c.* FROM server_config c JOIN server_info i ON i.id=c.id WHERE c.id=? AND i.active=1 AND c.type IN (".achbotProviderSqlTypes().")");
        $q->bind_param('i',$serverId);$q->execute();$server=$q->get_result()->fetch_assoc();$q->close();
        if (!$server) throw new RuntimeException('import_provider_identity');
        $remote=achbotProviderExistingUser($connection,$serverId,$input,$remark);
        $proof=is_object($remote) && ($remote->username??null)===$remark?achbotExternalImportProof($server,$remote,$input):null;
        if (!$proof) throw new RuntimeException('import_proof');
        // Exact token/endpoint proof is rechecked at import; prefixes and default
        // server IDs are never identity, and native expiry is never invented.
        if (!$connection->begin_transaction()) throw new RuntimeException('import_begin');$begun=true;
        $q=$connection->prepare('SELECT id,userid,isAdmin,step,temp FROM users WHERE id=? FOR UPDATE');
        $id=(int)$actors[0]['id'];$q->bind_param('i',$id);$q->execute();$current=$q->get_result()->fetch_assoc();$q->close();
        if (!$current || (int)$current['userid']!==$actor || $current['step']!==$actors[0]['step'] ||
            $current['temp']!==$actors[0]['temp'] || ($finalUserId!==$actor &&
             !achbotAdminCanLocked($connection,$actor,(int)$admin,'operations')))
            throw new RuntimeException('import_stale');
        $q=$connection->prepare('SELECT id FROM users WHERE userid=? LIMIT 2 FOR UPDATE');
        $q->bind_param('i',$finalUserId);$q->execute();$targetCount=$q->get_result()->num_rows;$q->close();
        if ($targetCount!==1) throw new RuntimeException('import_target_missing');
        $q=$connection->prepare("SELECT c.* FROM server_config c JOIN server_info i ON i.id=c.id WHERE c.id=? AND i.active=1 AND c.type IN (".achbotProviderSqlTypes().") FOR UPDATE");
        $q->bind_param('i',$serverId);$q->execute();$currentServer=$q->get_result()->fetch_assoc();$q->close();
        if (!$currentServer || $currentServer['type']!==$server['type'] || $currentServer['panel_url']!==$server['panel_url'] ||
            $currentServer['username']!==$server['username'] || $currentServer['password']!==$server['password'] ||
            (string)($currentServer['sub_links']??'')!==(string)($server['sub_links']??'')) throw new RuntimeException('import_server_changed');
        $q=$connection->prepare('SELECT id FROM orders_list WHERE server_id=? AND remark=? AND userid=? LIMIT 1 FOR UPDATE');
        $q->bind_param('isi',$serverId,$remark,$finalUserId);$q->execute();$exists=$q->get_result()->num_rows>0;$q->close();
        if ($exists) throw new RuntimeException('import_already_attached');
        $q=$connection->prepare('SELECT token FROM add_link WHERE added_by=? AND remark=? AND BINARY token=BINARY ? LIMIT 1 FOR UPDATE');
        $token=$proof['token'];$q->bind_param('iss',$actor,$remark,$token);$q->execute();$credential=$q->get_result()->fetch_assoc();$q->close();
        if (!$credential) throw new RuntimeException('import_credential_missing');
        $uuid='';
        if (is_object($remote->proxies??null)) {
            foreach (['vmess'=>'id','vless'=>'id','trojan'=>'password'] as $protocol=>$key)
                if (is_string($remote->proxies->{$protocol}->{$key}??null)) {$uuid=$remote->proxies->{$protocol}->{$key};break;}
        }
        $links=[];
        foreach (is_array($remote->links??null)?$remote->links:[] as $uri) if (is_string($uri)) $links[]=$uri;
        $link=json_encode($links,JSON_THROW_ON_ERROR);$expiry=$proof['expiry']??0;$now=time();
        // Existing services have no paid plan or monetary credit attached.
        $q=$connection->prepare("INSERT INTO orders_list(userid,token,transid,fileid,server_id,inbound_id,remark,uuid,expire_date,link,amount,status,date,notif,agent_bought,autoRenew) VALUES(?,?,'',0,?,0,?,?,?,?,0,1,?,0,0,'off')");
        $user=(string)$finalUserId;
        $q->bind_param('ssissisi',$user,$token,$serverId,$remark,$uuid,$expiry,$link,$now);
        if (!$q->execute() || $q->affected_rows!==1) throw new RuntimeException('import_insert');
        $orderId=(int)$q->insert_id;$q->close();
        achbotProviderAdoptExistingLocked($connection,$serverId,$remote,$proof,$finalUserId,$orderId,$actor,(int)$admin);
        $q=$connection->prepare("UPDATE users SET temp=NULL,step='none' WHERE id=? AND userid=?");
        $q->bind_param('ii',$id,$actor);$q->execute();$q->close();
        try {if (!$connection->commit()) throw new RuntimeException('import_commit_unknown');}
        catch (Throwable $error) {throw new RuntimeException('import_commit_unknown');}
        $begun=false;$committed=true;
        alert(\achbotMessage('subscription.manual_accounts.detect_server_id_from_remark.alert.ecf2df32d7'));
        $msg=\achbotMessage('subscription.manual_accounts.import_completed',[
            'remark'=>htmlspecialchars($remark,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')]);
        $keys=json_encode(['inline_keyboard'=>[[
            ['text'=>$buttonValues['my_subscriptions'],'callback_data'=>'mySubscriptions'],
            ['text'=>$buttonValues['back_to_main'],'callback_data'=>'mainMenu']
        ]]]);
        sendMessage($msg,$keys,'HTML',$finalUserId);
        if ($adminActor) {
            $keys=json_encode(['inline_keyboard'=>[[['text'=>\achbotMessage('subscription.manual_accounts.detect_server_id_from_remark.button.48f23552b7'),'callback_data'=>'quickUserOrderDetails_'.$orderId]]]]);
            $adminMessage=\achbotMessage('subscription.manual_accounts.detect_server_id_from_remark.admin_message.7e7a367cc0',[
                'finalUserId'=>$finalUserId,'first_name'=>htmlspecialchars((string)$first_name,ENT_QUOTES,'UTF-8'),
                'username'=>htmlspecialchars((string)$username,ENT_QUOTES,'UTF-8'),'remark'=>htmlspecialchars($remark,ENT_QUOTES,'UTF-8')]);
            achbotNotifyAdmins('sendMessage', ['text'=>$adminMessage,'reply_markup'=>$keys,'parse_mode'=>'HTML'], ['report_channel'=>null]);
        }
    } catch (Throwable $error) {
        if ($begun) {try {$connection->rollback();} catch (Throwable $ignored) {}}
        $reason=$error instanceof RuntimeException && in_array($error->getMessage(),['import_identity_missing','import_stale','import_actor','import_provider_identity','import_proof','import_begin','import_target_missing','import_server_changed','import_already_attached','import_credential_missing','import_insert','import_commit_unknown'],true)?$error->getMessage():'import_unavailable';
        error_log('achbot_existing_subscription_import_deferred reason='.$reason.' class='.get_class($error).' line='.$error->getLine());
        if ($committed) return;
        if ($error instanceof RuntimeException && $error->getMessage()==='import_commit_unknown')
            sendMessage(\achbotMessage('subscription.manual_accounts.import_under_review'));
        else alert(\achbotMessage('subscription.manual_accounts.import_unverified'),true);
    }
    return;
}
