<?php
/** AChBot V6: original Telegram account/subscription routes; keep caller scope. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG



/** A subscription credential is proved by native token AND an explicit configured origin. */
function achbotExternalSubscriptionParts(string $url): ?array {
    $p=parse_url(trim($url));
    if (!$p || !in_array($p['scheme']??'',['http','https'],true) || empty($p['host']) ||
        isset($p['user']) || isset($p['pass']) || preg_match('/[\x00-\x20\x7f]/',$url)) return null;
    $path=$p['path']??'';
    if (!preg_match('~^(.*)/([^/]+)/?$~D',$path,$m)) return null;
    $token=rawurldecode($m[2]);
    if ($token==='' || strlen($token)>255 || preg_match('~[\x00-\x20\x7f/?#]~',$token)) return null;
    $port=(int)($p['port']??($p['scheme']==='https'?443:80));
    return ['origin'=>strtolower($p['scheme']).'://'.strtolower($p['host']).':'.$port,
        'prefix'=>$m[1],'token'=>$token];
}
function achbotExternalImportProof(array $server,object $remote,string $input): ?array {
    if (!is_string($remote->username??null)) return null;
    require_once __DIR__.'/../Panel/ServiceLifecycle.php';
    $observation=achbotServiceObservation($remote,$remote->username);
    $supplied=achbotExternalSubscriptionParts($input);
    if (!$observation['verified'] || !$supplied) return null;
    // Optional provider adapters supply an already verified native bearer
    // proof. Rotating native tokens must never be compared to a newly issued URL.
    $proof=$remote->provider_import_proof??null;
    if (is_array($proof)) {
        if (($proof['verified']??false)!==true ||
            ($proof['provider']??null)!==($server['type']??null) ||
            (int)($proof['server_id']??0)!==(int)($server['id']??0) ||
            !is_string($proof['input_sha256']??null) ||
            !hash_equals($proof['input_sha256'],hash('sha256',$input)) ||
            !is_string($proof['token']??null) ||
            !hash_equals($proof['token'],$supplied['token']) ||
            !array_key_exists('expiry',$proof) || $proof['expiry']!==$observation['expire'] ||
            ($proof['status']??null)!==$observation['status']) return null;
        $proof['observation']=$observation;
        return $proof;
    }
    if (($server['type']??null)!=='marzban' || !is_string($remote->subscription_url??null)) return null;
    $native=$remote->subscription_url;
    if (str_starts_with($native,'/')) $native=rtrim((string)$server['panel_url'],'/').$native;
    $known=achbotExternalSubscriptionParts($native);
    if (!$known || !hash_equals($known['token'],$supplied['token'])) return null;
    $allowed=[[$known['origin'],$known['prefix']]];
    foreach (array_merge([(string)$server['panel_url']],array_filter(array_map('trim',explode(',',(string)($server['sub_links']??''))))) as $base) {
        $parts=achbotExternalSubscriptionParts(rtrim($base,'/').'/sub/'.rawurlencode($known['token']));
        if ($parts) $allowed[]=[$parts['origin'],$parts['prefix']];
    }
    if (!in_array([$supplied['origin'],$supplied['prefix']],$allowed,true)) return null;
    return ['token'=>$known['token'],'expiry'=>$observation['expire'],
        'status'=>$observation['status'],'subscription_url'=>$native,'observation'=>$observation];
}
function achbotExternalImportFrame($update,int $actor,string $callback): bool {
    $q=$update->callback_query??null;$m=$q->message??null;
    return is_object($update) && is_int($update->update_id??null) && $update->update_id>0 &&
        is_object($q) && ($q->data??null)===$callback &&
        (int)($q->from->id??0)===$actor && is_object($m) &&
        ($m->chat->type??null)==='private' && (int)($m->chat->id??0)===$actor;
}

/** Persist only the fresh actor's preview; a later wizard/cancel wins. */
function achbotExternalImportStorePreview(mysqli $db,int $actor,array $cached,array $preview,string $token): bool {
    if (($cached['step']??null)!=='showAccount') return false;
    if (!$db->begin_transaction()) throw new RuntimeException('import_preview_begin');
    try {
        $q=$db->prepare('SELECT id,userid,step,temp FROM users WHERE userid=? LIMIT 2 FOR UPDATE');
        $q->bind_param('i',$actor);$q->execute();$rows=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
        if (count($rows)!==1 || $rows[0]['step']!=='showAccount' ||
            $rows[0]['temp']!==($cached['temp']??null)) {$db->rollback();return false;}
        $remark=$preview['remark'];$created=date('Y-m-d H:i:s');
        $q=$db->prepare('INSERT INTO add_link(remark,token,added_by,created_at) VALUES(?,?,?,?)');
        $q->bind_param('ssis',$remark,$token,$actor,$created);$q->execute();$q->close();
        $id=(int)$rows[0]['id'];$temp=json_encode($preview,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $q=$db->prepare("UPDATE users SET step='none',temp=? WHERE id=? AND userid=?");
        $q->bind_param('sii',$temp,$id,$actor);$q->execute();$q->close();
        if (!$db->commit()) throw new RuntimeException('import_preview_commit_unknown');
        return true;
    } catch (Throwable $error) {
        try {$db->rollback();} catch (Throwable $ignored) {}
        throw $error;
    }
}

// BEGIN Edit by ACh
if ($data==='showUUIDLeft') {
    $actor=(int)$from_id;
    if (!achbotExternalImportFrame($update,$actor,$data)) return;
    try {
        if (!$connection->begin_transaction()) throw new RuntimeException('import_entry_begin');
        $q=$connection->prepare('SELECT id,step,temp,isAdmin FROM users WHERE userid=? LIMIT 2 FOR UPDATE');
        $q->bind_param('i',$actor);$q->execute();$rows=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
        if (count($rows)!==1 || $rows[0]['step']==='banned' ||
            $rows[0]['step']!==($userInfo['step']??null) || $rows[0]['temp']!==($userInfo['temp']??null) ||
            str_starts_with((string)$rows[0]['step'],'achbotDirectGift') ||
            str_starts_with((string)$rows[0]['step'],'achbotExempt:') ||
            (($botState['searchState']??'off')!=='on' && $actor!==(int)$admin && (int)$rows[0]['isAdmin']!==1)) {
            $connection->rollback();return;
        }
        $id=(int)$rows[0]['id'];
        $q=$connection->prepare("UPDATE users SET step='showAccount',temp=NULL WHERE id=? AND userid=?");
        $q->bind_param('ii',$id,$actor);$q->execute();$q->close();
        if (!$connection->commit()) throw new RuntimeException('import_entry_commit_unknown');
    } catch (Throwable $error) {
        try {$connection->rollback();} catch (Throwable $ignored) {}
        error_log('achbot_existing_subscription_entry_deferred');return;
    }
    delMessage();
    sendMessage($mainValues['send_config_uuid'], $cancelKey);
    return;
}

function extractUsernameFromLink($text) {
    $text = trim($text);

    $parts=achbotExternalSubscriptionParts($text);
    if (!$parts) return null;
    $token=$parts['token'];
    $token = strtr($token, '-_', '+/');
    $token = preg_replace('/\s+/', '', $token);

    for ($cut = 0; $cut <= 4; $cut++) {
        $candidate = $cut > 0 ? substr($token, 0, -$cut) : $token;
        if ($candidate === '') {
            continue;
        }

        $mod = strlen($candidate) % 4;

        if ($mod == 1) {
            continue;
        }

        if ($mod > 0) {
            $candidate .= str_repeat('=', 4 - $mod);
        }

        $decoded = base64_decode($candidate, true);
        if ($decoded === false || $decoded === '') {
            continue;
        }

        if (strpos($decoded, ',') !== false) {
            $parts = explode(',', $decoded, 2);
            $username = trim($parts[0]);

            if ($username !== '') {
                return $username;
            }
        }

        // fallback
        $decoded = preg_replace('/[^\x20-\x7E]/', '', $decoded);
        if ($decoded !== '' && strpos($decoded, ',') !== false) {
            $parts = explode(',', $decoded, 2);
            $username = trim($parts[0]);

            if ($username !== '') {
                return $username;
            }
        }
    }

    return null;
}

if ($userInfo['step'] == "showAccount" && $text != $buttonValues['cancel']) {
    try {require_once __DIR__.'/GiftBatch.php';achbotGiftTelegramSource($update,$from_id,$chat_id,$message_id,$text);}
    catch (Throwable $error) {return;}
    date_default_timezone_set('Asia/Tehran');

    $originalLink = trim($text);
    $username = extractUsernameFromLink($originalLink);
    $parts=achbotExternalSubscriptionParts($originalLink);
    if (!$parts) {
        sendMessage(\achbotMessage('subscription.external_accounts.extract_username_from_link.message.02e023c2c2'), $cancelKey);
        return;
    }
    sendMessage(\achbotMessage('subscription.external_accounts.extract_username_from_link.message.4c80cbd8b8'), $removeKeyboard);
    $stmt = $connection->prepare("SELECT c.* FROM server_config c JOIN server_info i ON i.id=c.id WHERE i.active=1 AND c.type IN (".achbotProviderSqlTypes().") ORDER BY c.id");
    $stmt->execute();$serversList=$stmt->get_result();$stmt->close();
    $matches=[];
    while ($row=$serversList->fetch_assoc()) {
        try {
            $currentServerId=(int)$row['id'];
            $userData=achbotProviderExistingUser($connection,$currentServerId,$originalLink,$username);
            $proof=achbotExternalImportProof($row,$userData,$originalLink);
            if ($proof!==null) {
                // The backend may prove multiple logical entries are one
                // physical account. Distinct or unproved targets stay ambiguous.
                $identity=is_string($proof['identity_key']??null)?$proof['identity_key']:'server:'.$currentServerId;
                $matches[$identity]??=['server'=>$currentServerId,'remote'=>$userData,'proof'=>$proof,'type'=>$row['type']];
            }
        } catch (Throwable $error) {
            error_log('achbot_existing_subscription_lookup_deferred');
        }
    }
    $found=count($matches)===1;$config=null;$serverId=0;
    if ($found) {
        $match=array_values($matches)[0];$serverId=$match['server'];$config=$match['remote'];$proof=$match['proof'];
    }
    if (!$found || empty($config)) {
        sendMessage(\achbotMessage('subscription.manual_accounts.import_unverified'), $cancelKey);
        exit();
    }

    $remark=$config->username;
    $token=$proof['token'];$observation=$proof['observation'];
    require_once __DIR__.'/AccountDetails.php';
    $state=\AchBot\Subscription\AccountDetails\getOrderStatusText(
        $observation+['found'=>true,'uncertain'=>false],$match['type'],$buttonValues);
    $unlimited=\achbotMessage('subscription.account_deletion.no_dont_delete.text.5e6ee5eaa1');
    $total=$observation['quota']===null?$unlimited:sumerize($observation['quota']);
    $totalUsed=sumerize($observation['used']);
    $leftMb=$observation['remaining']===null?$unlimited:sumerize($observation['remaining']);
    $expire=$observation['expire'];
    $expiryTime=$expire===null?$unlimited:jdate('Y-m-d H:i:s',$expire,'','Asia/Tehran','en');
    $expiryDay=$expire===null?$unlimited:max(0,(int)floor(($expire-time())/86400));
    $configLocation=[
        'remark'=>$remark,'username'=>$remark,'server_id'=>$serverId,
        'import_url'=>$originalLink,'provider'=>$match['type']
    ];

    $keyboard = [
        [
            ['text' => $state ?? " ", 'callback_data' => "wizwizch"],
            ['text' => \achbotMessage('subscription.external_accounts.extract_username_from_link.button.e2fee6e8d7'), 'callback_data' => "wizwizch"],
        ],
        [
            ['text' => $remark ?? " ", 'callback_data' => "wizwizch"],
            ['text' => \achbotMessage('subscription.external_accounts.extract_username_from_link.button.da22eb1607'), 'callback_data' => "wizwizch"],
        ],
        [
            ['text' => $totalUsed ?? " ", 'callback_data' => "wizwizch"],
            ['text' => \achbotMessage('subscription.external_accounts.extract_username_from_link.button.ba58ec87db'), 'callback_data' => "wizwizch"],
        ],
        [
            ['text' => $total ?? " ", 'callback_data' => "wizwizch"],
            ['text' => \achbotMessage('subscription.external_accounts.extract_username_from_link.button.4ab7460a09'), 'callback_data' => "wizwizch"],
        ],
        [
            ['text' => $leftMb ?? " ", 'callback_data' => "wizwizch"],
            ['text' => \achbotMessage('subscription.external_accounts.extract_username_from_link.button.ae4f062fc5'), 'callback_data' => "wizwizch"],
        ],
        [
            ['text' => $expiryTime ?? " ", 'callback_data' => "wizwizch"],
            ['text' => \achbotMessage('subscription.external_accounts.extract_username_from_link.button.b7fed20dcb'), 'callback_data' => "wizwizch"],
        ],
        [
            ['text' => $expiryDay ?? " ", 'callback_data' => "wizwizch"],
            ['text' => \achbotMessage('subscription.external_accounts.extract_username_from_link.button.f5f1dcdabf'), 'callback_data' => "wizwizch"],
        ],
    ];

    /*
    // دکمه های مربوط به تمدید یا بروزرسانی لینک
    if ($botState['renewAccountState'] == "on") {
        $keyboard[] = [
            ['text' => $buttonValues['renew_config'], 'callback_data' => "sConfigRenew" . $serverId],
        ];
    } elseif ($botState['updateConfigLinkState'] == "on") {
        $keyboard[] = [
            ['text' => $buttonValues['update_config_connection'], 'callback_data' => "sConfigUpdate" . $serverId],
        ];
    }
    */

    if ($from_id == $admin || $userInfo['isAdmin'] == true) {
        if (empty($userInfo['addToCustomerClicked']) || $userInfo['addToCustomerClicked'] != true) {
            $keyboard[] = [['text' => \achbotMessage('subscription.external_accounts.extract_username_from_link.button.8ce785e9a5'), 'callback_data' => "addSubscriptionToCustomer" . $serverId]];
        }
    } else {
        $keyboard[] = [['text' => \achbotMessage('subscription.external_accounts.extract_username_from_link.button.387c334ec1'), 'callback_data' => "addToOrders" . $serverId]];
    }

    $keyboard[] = [['text' => \achbotMessage('subscription.external_accounts.extract_username_from_link.button.a4ebab2a7b'), 'callback_data' => "mainMenu"]];
    $keys = json_encode(['inline_keyboard' => $keyboard]);

    try {
        if (!achbotExternalImportStorePreview($connection,(int)$from_id,$userInfo,$configLocation,$token)) return;
    } catch (Throwable $error) {
        error_log('achbot_existing_subscription_preview_deferred');
        sendMessage(\achbotMessage('subscription.manual_accounts.import_under_review'));
        return;
    }
    $userInfo['step']='none';$userInfo['temp']=json_encode($configLocation,JSON_UNESCAPED_UNICODE);

    sendMessage(\achbotMessage('subscription.external_accounts.extract_username_from_link.message.b2f5ea228c'), $keys, "HTML");
}

