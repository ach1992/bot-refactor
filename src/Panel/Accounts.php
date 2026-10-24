<?php
/** Current Marzban provider only. Legacy X-UI/Sanaei/Alireza never deployed and retired after V9. */
namespace AchBot\Panel\Accounts;
use stdClass;

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

function getMarzbanToken($server_id, ?string $expectedPanelUrl = null,
                        ?array $effectTarget = null){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id = ?");
    $stmt->bind_param('i', $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Strict effect auth is bound to the endpoint and credentials captured
    // at reservation. A later config lookup cannot substitute another tenant.
    if ($effectTarget!==null &&
        ($expectedPanelUrl===null || !$server_info ||
         ($server_info['type']??'')!=='marzban' ||
         rtrim((string)($server_info['panel_url']??''),'/')!==$expectedPanelUrl ||
         $expectedPanelUrl!==($effectTarget['panel_url']??null) ||
         (string)($server_info['username']??'')!==($effectTarget['username']??null) ||
         (string)($server_info['password']??'')!==($effectTarget['password']??null)))
        return (object)['detail'=>'effect_credentials_target_changed'];

    // Fail closed: neither unexpected provider records nor missing servers
    // may be probed using Marzban credentials/API as a silent fallback.
    if (!$server_info || ($server_info['type'] ?? '') !== 'marzban') {
        return (object) ['detail' => 'unsupported_panel'];
    }

    $panel_url = rtrim((string)$server_info['panel_url'],'/');
    if ($expectedPanelUrl!==null &&
        $panel_url!==rtrim($expectedPanelUrl,'/')) {
        return (object)['detail'=>'marzban_target_changed'];
    }
    $username = $server_info['username'];
    $password = $server_info['password'];
    
    $loginUrl = $panel_url .'/api/admin/token';
    $postFields = array(
        'username' => $username,
        'password' => $password
    );
    
    
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $loginUrl);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false); // Never redirect credential-bearing login.
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($curl, CURLOPT_TIMEOUT, 3);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/x-www-form-urlencoded',
            'accept: application/json'
        ));
    $response = curl_exec($curl);
    $curlError=curl_error($curl);
    $http=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);
    curl_close($curl);
    if ($curlError!=='' || $response===false)
        return (object)['success'=>false,'detail'=>'marzban_auth_transport_unavailable'];
    if ($effectTarget!==null && $http!==200)
        return (object)['success'=>false,'detail'=>'effect_auth_unconfirmed'];
    $token=json_decode((string)$response);
    if ($effectTarget!==null &&
        (!is_object($token) || !is_string($token->access_token??null) ||
         $token->access_token===''))
        return (object)['success'=>false,'detail'=>'effect_auth_invalid'];
    return $token;
}

function getMarzbanJson($server_id, $token = null){
    global $connection;
    
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];

    if($token == null) $token = getMarzbanToken($server_id);
    if(isset($token->detail)){return (object) ['success'=>false, 'msg'=>$token->detail];}
    $panel_url .= '/api/users';
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $panel_url);
    curl_setopt($curl, CURLOPT_HTTPGET, true);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
        'Accept: application/json',
        'Authorization: Bearer ' . $token->access_token
    ));

    $response = json_decode(curl_exec($curl));
    curl_close($curl);

    return $response;
}

function getMarzbanUserInfo($server_id, $remark){
    global $connection;
    
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];

    $configInfo = array();
    $curl = curl_init();
    for($i = 0; $i <= 10; $i++){
        $info = getMarzbanUser($server_id, $remark);
		$subLink = "/sub/" . (explode("/sub/", $info->subscription_url)[1]);
		$info->subscription_url = $subLink;
        curl_setopt($curl, CURLOPT_URL, $panel_url . $info->subscription_url);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
        $response = curl_exec($curl);
        if($response && !curl_error($curl)){
            $configInfo = $info;
            break;
        }
		if($i == 10) $configInfo = $info;
    }
    curl_close($curl);

    return (object) $configInfo;
}

function getMarzbanUser($server_id, $remark, $token = null, bool $strict = false,
                        ?string $expectedPanelUrl = null, ?array $effectTarget = null){
    global $connection;
    
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($effectTarget!==null &&
        ($expectedPanelUrl===null || !$server_info ||
         ($server_info['type']??'')!=='marzban' ||
         rtrim((string)($server_info['panel_url']??''),'/')!==$expectedPanelUrl ||
         $expectedPanelUrl!==($effectTarget['panel_url']??null) ||
         (string)($server_info['username']??'')!==($effectTarget['username']??null) ||
         (string)($server_info['password']??'')!==($effectTarget['password']??null)))
        return (object)['success'=>false,'uncertain'=>true,
                        'msg'=>'effect_credentials_target_changed'];

    // Exact edit readbacks must never use a reconfigured physical target
    // with credentials issued for the initial endpoint.
    if ($expectedPanelUrl!==null &&
        (!$server_info || ($server_info['type']??'')!=='marzban' ||
         rtrim((string)$server_info['panel_url'],'/')!==rtrim($expectedPanelUrl,'/'))) {
        return (object)['success'=>false,'uncertain'=>true,'msg'=>'marzban_target_changed'];
    }
    $panel_url = $expectedPanelUrl===null?
        (string)$server_info['panel_url']:rtrim($expectedPanelUrl,'/');

    if($token == null) $token = getMarzbanToken($server_id,$expectedPanelUrl,$effectTarget);
    if(isset($token->detail)){return (object) ['success'=>false, 'msg'=>$token->detail];}
    
    $panel_url .= '/api/user/' . urlencode($remark);

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $panel_url);
    curl_setopt($curl, CURLOPT_HTTPGET, true);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
        'Accept: application/json',
        'Authorization: Bearer ' . $token->access_token
    ));

    if ($strict) {
        curl_setopt($curl,CURLOPT_CONNECTTIMEOUT,5);
        curl_setopt($curl,CURLOPT_TIMEOUT,20);
        curl_setopt($curl,CURLOPT_SSL_VERIFYPEER,true);
        curl_setopt($curl,CURLOPT_SSL_VERIFYHOST,2);
    }
    $raw=curl_exec($curl);
    $http=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);
    curl_close($curl);
    // In an effect readback, HTTP 404, transport failure and invalid JSON
    // are UNKNOWN evidence, not proof that CREATE was never applied.
    if ($strict && ($raw===false || $http!==200))
        return (object)['success'=>false,'uncertain'=>true,'msg'=>'effect_get_unconfirmed'];
    $response=json_decode((string)$raw);
    if ($strict && (!is_object($response) || isset($response->detail)))
        return (object)['success'=>false,'uncertain'=>true,'msg'=>'effect_get_invalid'];
    return $response;
}

function getMarzbanHosts($server_id){
    global $connection;
    
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];

    $token = getMarzbanToken($server_id);
    if(isset($token->detail)){return (object) ['success'=>false, 'msg'=>$token->detail];}

    $panel_url .= '/api/core/config';

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $panel_url);
    curl_setopt($curl, CURLOPT_HTTPGET, true);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
        'Accept: application/json',
        'Authorization: Bearer ' . $token->access_token
    ));

    $response = json_decode(curl_exec($curl));
    
    curl_close($curl);
    return $response;
}

function addMarzbanUser($server_id, $remark, $volume, $days, $plan_id,
                        ?string $operationNote = null, ?int $fixedExpire = null,
                        ?array $effectTarget = null){
    global $connection;
    
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // A durable 007 CREATE must never silently fall back to logical-server
    // routing or reselect credentials after the reserved endpoint was pinned.
    if ($operationNote!==null && $effectTarget===null)
        return (object)['success'=>false,'msg'=>'effect_target_snapshot_required'];
    if ($effectTarget!==null &&
        (!$server_info || ($server_info['type']??'')!=='marzban' ||
         rtrim((string)($server_info['panel_url']??''),'/')!==($effectTarget['panel_url']??null) ||
         (string)($server_info['username']??'')!==($effectTarget['username']??null) ||
         (string)($server_info['password']??'')!==($effectTarget['password']??null)))
        return (object)['success'=>false,'msg'=>'effect_credentials_target_changed'];

    $panel_url=$effectTarget===null
        ? (string)$server_info['panel_url'] : (string)$effectTarget['panel_url'];
    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];
    $serverType = $server_info['type'];
    
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ?");
    $stmt->bind_param('i', $plan_id);
    $stmt->execute();
    $planInfo = json_decode($stmt->get_result()->fetch_assoc()['custom_sni'],true);
    $stmt->close();

    $token = getMarzbanToken($server_id,$effectTarget['panel_url']??null,$effectTarget);
    if ($effectTarget!==null &&
        (!is_object($token) || !is_string($token->access_token??null) ||
         $token->access_token===''))
        return (object)['success'=>false,'msg'=>'effect_auth_unconfirmed'];
    if(isset($token->detail)){return (object) ['success'=>false, 'msg'=>$token->detail];}
	$dataproxy = array(
			"vmess" => new stdClass(),
			"vless" => new stdClass(),
			"trojan" => new stdClass(),
			"shadowsocks" => new stdClass()
    );
    $postFields = array(
        "inbounds" => $planInfo['inbounds'],
        "proxies" =>  $dataproxy,
        "expire" => $fixedExpire ?? (time() + (86400 * $days)),
        "data_limit" => $volume * 1073741824,
        "username" => urlencode($remark)
    );
    if ($operationNote !== null) {
        // The original operation key is pinned before the POST. Marzban's
        // readback must return this marker, not merely a matching username.
        if (!preg_match('/^achbot:v1:[a-f0-9]{32}$/D', $operationNote)) {
            return (object)['success'=>false, 'msg'=>'invalid_effect_note'];
        }
        $postFields['note']=$operationNote;
    }

    if ($effectTarget!==null) {
        // Check alias, group and credentials at the last reversible boundary.
        $check=$connection->prepare("SELECT c.type,c.panel_url,c.username,c.password,
            i.panel_group_id FROM server_info i JOIN server_config c ON c.id=i.id
            WHERE i.id=? LIMIT 1");
        if (!$check) return (object)['success'=>false,'msg'=>'effect_target_check_unavailable'];
        $check->bind_param('i',$server_id);
        $ok=$check->execute();
        $latest=$ok?$check->get_result()->fetch_assoc():null;$check->close();
        if (!$latest || $latest['type']!=='marzban' ||
            rtrim((string)$latest['panel_url'],'/')!==$effectTarget['panel_url'] ||
            (string)$latest['username']!==$effectTarget['username'] ||
            (string)$latest['password']!==$effectTarget['password'] ||
            ($latest['panel_group_id']===null?null:(int)$latest['panel_group_id'])!==$effectTarget['panel_group_id'])
            return (object)['success'=>false,'msg'=>'effect_target_changed_before_post'];
    }

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $panel_url . "/api/user");
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
        'Accept: application/json',
        'Authorization: Bearer ' .  $token->access_token,
        'Content-Type: application/json'
    ));
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($postFields));
    if ($operationNote !== null) {
        curl_setopt($curl,CURLOPT_CONNECTTIMEOUT,5);
        curl_setopt($curl,CURLOPT_TIMEOUT,20);
        curl_setopt($curl,CURLOPT_SSL_VERIFYPEER,true);
        curl_setopt($curl,CURLOPT_SSL_VERIFYHOST,2);
    }
    $raw=curl_exec($curl);
    $http=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);
    curl_close($curl);
    $response=json_decode((string)$raw);
    if ($operationNote !== null && ($raw===false || $http!==200 || !is_object($response) ||
                                     isset($response->detail) || !isset($response->links))) {
        // Never claim non-success/409 means the POST had no remote effect.
        return (object)['success'=>false,'msg'=>'effect_create_response_unconfirmed'];
    }
    if (!is_object($response) || isset($response->detail) || !isset($response->links)) {
        $detail=is_object($response)?($response->detail??''):'';
        return (object)['success'=>false,'msg'=>is_object($detail)?implode("-", (array)$detail):$detail];
    }
    if ($effectTarget!==null) {
        // Never call the legacy mutable-target subscription resolver after
        // CREATE. Use the pinned authenticated readback instead.
        $userInfo=getMarzbanUser($server_id,$remark,$token,true,
            $effectTarget['panel_url'],$effectTarget);
        $subscription=(string)($userInfo->subscription_url??'');
        $subParts=explode('/sub/',$subscription,2);
        if (!is_object($userInfo) ||
            (string)($userInfo->username??'')!==$remark ||
            count($subParts)!==2 || $subParts[1]==='')
            return (object)['success'=>false,'msg'=>'effect_subscription_unverified'];
        $relativeSub='/sub/'.$subParts[1];
    } else {
        $userInfo=getMarzbanUserInfo($server_id,$remark);
        $relativeSub=$userInfo->subscription_url??'';
    }

    return (object) [
        'success'=>true,
        'sub_link'=>$relativeSub,
        'vray_links'=>$response->links
        ];
}

function editMarzbanConfig($server_id,$info){
    global $connection;

    $stmt=$connection->prepare("SELECT type,panel_url FROM server_config WHERE id=?");
    $stmt->bind_param("i",$server_id);
    $stmt->execute();
    $serverInfo=$stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$serverInfo || ($serverInfo['type']??'')!=='marzban') {
        return (object)['success'=>false,'uncertain'=>false,'msg'=>'unsupported_panel'];
    }
    $remark=(string)($info['remark']??'');
    if ($remark==='') return (object)['success'=>false,'uncertain'=>false,'msg'=>'missing_account'];
    $panelUrl=rtrim((string)$serverInfo['panel_url'],'/');
    if ($panelUrl==='') return (object)['success'=>false,'uncertain'=>true,'msg'=>'marzban_target_unknown'];

    // Post-V9 physical panel grouping can map different URLs to the SAME
    // account store. Prefer the configured shared group to the endpoint
    // for bot-originated account-level serialization, but preserve legacy
    // read compatibility when the optional 001 column has not been installed.
    $hasGroupColumn=false;
    $groupId=null;
    try {
        $columns=$connection->query("SHOW COLUMNS FROM server_info LIKE 'panel_group_id'");
        if (!$columns) throw new \RuntimeException('group_schema_query_failed');
        $hasGroupColumn=$columns->num_rows===1;
        $columns->free();
        if ($hasGroupColumn) {
            $groupStmt=$connection->prepare("SELECT panel_group_id FROM server_info WHERE id=?");
            if (!$groupStmt) throw new \RuntimeException('group_target_query_failed');
            $groupStmt->bind_param('i',$server_id);
            $ok=$groupStmt->execute();
            $groupRow=$ok?$groupStmt->get_result()->fetch_assoc():null;
            $groupStmt->close();
            if (!$groupRow) throw new \RuntimeException('group_target_unavailable');
            if ($groupRow['panel_group_id']!==null) {
                $groupId=(int)$groupRow['panel_group_id'];
                if ($groupId<=0) throw new \RuntimeException('group_target_invalid');
                $groupStmt=$connection->prepare("SELECT id FROM setting
                    WHERE id=? AND type='ACHBOT_MARZBAN_PANEL_GROUP'");
                if (!$groupStmt) throw new \RuntimeException('group_identity_query_failed');
                $groupStmt->bind_param('i',$groupId);
                $ok=$groupStmt->execute();
                $valid=$ok?$groupStmt->get_result()->fetch_assoc():null;
                $groupStmt->close();
                if (!$valid) throw new \RuntimeException('group_identity_unverified');
            }
        }
    } catch (\Throwable $error) {
        return (object)['success'=>false,'uncertain'=>true,
                        'msg'=>'marzban_group_identity_unverified'];
    }
    $scope=$groupId===null?'endpoint:'.$panelUrl:'group:'.$groupId;
    // A bounded advisory lock is released even if Marzban times out.
    $lockName='achbot-edit-'.substr(hash('sha256',$scope."\n".$remark),0,40);
    $lock=$connection->prepare("SELECT GET_LOCK(?,3) AS acquired");
    if (!$lock) return (object)['success'=>false,'uncertain'=>true,'msg'=>'marzban_edit_lock_unavailable'];
    $lock->bind_param('s',$lockName);
    $acquired=$lock->execute() ? $lock->get_result()->fetch_assoc() : null;
    $lock->close();
    if (!$acquired || (int)$acquired['acquired']!==1) {
        return (object)['success'=>false,'uncertain'=>true,'msg'=>'marzban_edit_busy'];
    }

    try {
        $token=getMarzbanToken($server_id,$panelUrl);
        if (!is_object($token) || empty($token->access_token))
            return (object)['success'=>false,'uncertain'=>true,'msg'=>'marzban_auth_unavailable'];

        $isPinned=function() use ($connection,$server_id,$panelUrl,$groupId,$hasGroupColumn): bool {
            $check=$connection->prepare("SELECT type,panel_url FROM server_config WHERE id=?");
            if (!$check) return false;
            $check->bind_param('i',$server_id);
            $ok=$check->execute();
            $record=$ok?$check->get_result()->fetch_assoc():null;
            $check->close();
            if (!$record || ($record['type']??'')!=='marzban' ||
                rtrim((string)$record['panel_url'],'/')!==$panelUrl) return false;
            if (!$hasGroupColumn) return true;
            $stmt=$connection->prepare("SELECT panel_group_id FROM server_info WHERE id=?");
            if (!$stmt) return false;
            $stmt->bind_param('i',$server_id);
            $ok=$stmt->execute();
            $group=$ok?$stmt->get_result()->fetch_assoc():null;
            $stmt->close();
            return (bool)$group &&
                ($group['panel_group_id']===null?null:(int)$group['panel_group_id'])===$groupId;
        };
        if (!$isPinned())
            return (object)['success'=>false,'uncertain'=>true,'msg'=>'marzban_target_changed'];

        // A fractional/scientific/overflowed remote JSON number must not
        // silently truncate to the expected integer quota or expiry.
        // Marzban's service limits are integral bytes and Unix seconds.
        $remoteInteger=static function($value): ?int {
            if (!is_int($value) &&
                (!is_string($value) ||
                 preg_match('/^(0|[1-9][0-9]*)$/D',$value)!==1)) return null;
            $parsed=filter_var($value,FILTER_VALIDATE_INT,
                               ['options'=>['min_range'=>0]]);
            return $parsed===false?null:$parsed;
        };
        // Marzban stores unlimited quota as NULL and may return it as JSON
        // null even when the requested absolute byte limit was zero.
        // Only an explicitly present null is unlimited: missing fields and
        // malformed finite values remain unverified.
        $remoteQuota=static function(?object $account) use ($remoteInteger): ?int {
            if ($account===null || !property_exists($account,'data_limit'))
                return null;
            return $account->data_limit===null
                ? 0 : $remoteInteger($account->data_limit);
        };
        $current=getMarzbanUser($server_id,$remark,$token,true,$panelUrl);
        if (!is_object($current) || (string)($current->username??'')!==$remark ||
            !isset($current->expire) ||
            $remoteInteger($current->expire)===null ||
            $remoteQuota($current)===null ||
            !isset($current->inbounds,$current->proxies,
                    $current->status,$current->data_limit_reset_strategy) ||
            !is_string($current->status) ||
            !is_string($current->data_limit_reset_strategy) ||
            $current->data_limit_reset_strategy==='') {
            return (object)['success'=>false,'uncertain'=>true,'msg'=>'marzban_account_unverified'];
        }
        // Some supported Marzban versions discard a queued next_plan on
        // ANY UserModify PUT, and on traffic RESET. Until provider-specific
        // atomic preservation is established, do not touch a service with
        // an independently scheduled future plan. Payment remains reviewable.
        if (isset($current->next_plan))
            return (object)['success'=>false,'uncertain'=>true,
                            'msg'=>'marzban_next_plan_requires_review'];
        // Preserve the intended renewal of a naturally expired or
        // quota-limited subscription. Never lift an administrator's disabled
        // or on_hold restriction as a side-effect of service edits.
        $canReactivate=($current->status==='expired' &&
            (isset($info['days']) || isset($info['plus_day']))) ||
            ($current->status==='limited' &&
            (isset($info['volume']) || isset($info['plus_volume'])));
        if ($current->status!=='active' && !$canReactivate)
            return (object)['success'=>false,'uncertain'=>true,
                            'msg'=>'marzban_account_status_restricted'];

        // A retryable HTTP response does not grant permission to overwrite a
        // foreign status/configuration change. Even though the actual PUT
        // sends only paid limits, compare the full observed status/config
        // baseline before any corrective PUT. Changed array order fails shut.
        $configuration=static function(?object $account): ?array {
            if ($account===null || !isset($account->status,
                $account->inbounds,$account->proxies,
                $account->data_limit_reset_strategy) ||
                !is_string($account->status) ||
                !is_string($account->data_limit_reset_strategy) ||
                !(is_array($account->inbounds)||is_object($account->inbounds)) ||
                !(is_array($account->proxies)||is_object($account->proxies))) return null;
            $inbounds=json_encode($account->inbounds);
            $proxies=json_encode($account->proxies);
            if ($inbounds===false || $proxies===false) return null;
            return [$account->status,$inbounds,$proxies,
                $account->data_limit_reset_strategy,$account->note??null];
        };
        $baseline=$configuration($current);
        if ($baseline===null)
            return (object)['success'=>false,'uncertain'=>true,
                            'msg'=>'marzban_account_unverified'];
        $targetConfiguration=$baseline;
        $targetConfiguration[0]='active';

        // Paid quote tables may contain floating-point days/GB. Never
        // truncate them to fewer units and falsely announce full service.
        // A precision-safe fractional accounting contract needs a separate
        // accepted migration; unsupported fractions stop before PUT/reset.
        $wholeUnits=static function($raw,int $max,bool $allowZero=false): ?int {
            if (!(is_int($raw)||is_float($raw)||is_string($raw)) ||
                !is_numeric($raw)) return null;
            $number=(float)$raw;
            if (!is_finite($number) || $number!==floor($number) ||
                $number>$max || $number<($allowZero?0:1)) return null;
            return (int)$number;
        };
        $oldExpire=$remoteInteger($current->expire);
        $oldVolume=$remoteQuota($current);
        // The current orders_list.expire_date is a SIGNED INT (32-bit).
        // Even a valid remote 64-bit timestamp cannot be marked locally
        // complete if it exceeds the published original schema contract.
        $maxLocalExpire=2147483647;
        if ($oldExpire>$maxLocalExpire)
            return (object)['success'=>false,'uncertain'=>false,'msg'=>'invalid_local_expiry'];
        $expire=$oldExpire;
        $volume=$oldVolume;
        if (isset($info['plus_day'])) {
            $days=$wholeUnits($info['plus_day'],36500);
            if ($days===null || $days<=0 || $days>36500 || $days>intdiv(PHP_INT_MAX,86400) ||
                max($expire,time())>PHP_INT_MAX-$days*86400)
                return (object)['success'=>false,'uncertain'=>false,'msg'=>'invalid_days'];
            $expire=max($expire,time())+86400*$days;
        } elseif (isset($info['days'])) {
            $days=$wholeUnits($info['days'],36500);
            if ($days===null || $days<=0 || $days>36500 || time()>PHP_INT_MAX-$days*86400)
                return (object)['success'=>false,'uncertain'=>false,'msg'=>'invalid_days'];
            $expire=time()+86400*$days;
        }
        if ($expire>$maxLocalExpire)
            return (object)['success'=>false,'uncertain'=>false,'msg'=>'invalid_local_expiry'];

        // Recheck the same physical account immediately before each first
        // side effect: Marzban's modify/reset endpoints can clear next_plan
        // even when the request omits that field. This narrows the external
        // operator race; Marzban offers no atomic If-Match/CAS for this API.
        $preEffect=function(bool $afterReset=false) use (
            $isPinned,$server_id,$remark,$token,$panelUrl,$remoteInteger,
            $oldExpire,$oldVolume,$remoteQuota,$configuration,$baseline,
            $targetConfiguration,$canReactivate): ?string {
            if (!$isPinned()) return 'marzban_target_changed';
            $fresh=getMarzbanUser($server_id,$remark,$token,true,$panelUrl);
            if (!$isPinned() || !is_object($fresh) ||
                (string)($fresh->username??'')!==$remark ||
                !isset($fresh->expire) ||
                $remoteInteger($fresh->expire)!==$oldExpire ||
                $remoteQuota($fresh)!==$oldVolume)
                return 'marzban_pre_effect_unverified';
            if (isset($fresh->next_plan))
                return 'marzban_next_plan_requires_review';
            $metadata=$configuration($fresh);
            if ($metadata!==$baseline &&
                (!$afterReset || !$canReactivate ||
                 $metadata!==$targetConfiguration))
                return 'marzban_pre_effect_subject_changed';
            if ($afterReset &&
                (!property_exists($fresh,'used_traffic') ||
                 $remoteInteger($fresh->used_traffic)!==0))
                return 'marzban_reset_readback_unverified';
            return null;
        };

        if (isset($info['plus_volume'])) {
            // Adding quota to an already unlimited subscription would
            // accidentally DOWNGRADE it to a finite quota. Do not silently
            // fulfill a paid increment with less service than before.
            if ($oldVolume===0)
                return (object)['success'=>false,'uncertain'=>false,
                                'msg'=>'marzban_unlimited_volume_extension'];
            $extra=$wholeUnits($info['plus_volume'],intdiv(PHP_INT_MAX,1073741824));
            if ($extra===null || $extra<=0 || $extra>intdiv(PHP_INT_MAX,1073741824) ||
                $volume>PHP_INT_MAX-$extra*1073741824)
                return (object)['success'=>false,'uncertain'=>false,'msg'=>'invalid_volume'];
            $volume+=$extra*1073741824;
        } elseif (isset($info['volume'])) {
            $gigabytes=$wholeUnits($info['volume'],intdiv(PHP_INT_MAX,1073741824),true);
            if ($gigabytes===null || $gigabytes<0 || $gigabytes>intdiv(PHP_INT_MAX,1073741824))
                return (object)['success'=>false,'uncertain'=>false,'msg'=>'invalid_volume'];
            $volume=$gigabytes*1073741824;
            $beforeReset=$preEffect();
            if ($beforeReset!==null)
                return (object)['success'=>false,'uncertain'=>true,'msg'=>$beforeReset];
            $reset=resetMarzbanTraffic($server_id,$remark,$token,$panelUrl);
            if (!is_object($reset) || empty($reset->success))
                return (object)['success'=>false,'uncertain'=>true,'msg'=>'marzban_reset_unverified'];
            // A reset has already been attempted and must never be blindly
            // repeated. Require an authenticated observation before PUT.
            if (!$isPinned())
                return (object)['success'=>false,'uncertain'=>true,'msg'=>'marzban_target_changed'];
            $afterReset=getMarzbanUser($server_id,$remark,$token,true,$panelUrl);
            if (!$isPinned() || !is_object($afterReset) ||
                ($afterReset->username??'')!==$remark ||
                !property_exists($afterReset,'used_traffic') ||
                !isset($afterReset->expire) ||
                $remoteInteger($afterReset->used_traffic)!==0 ||
                $remoteInteger($afterReset->expire)!==$oldExpire ||
                $remoteQuota($afterReset)!==$oldVolume ||
                ($configuration($afterReset)!==$baseline &&
                 (!$canReactivate ||
                  $configuration($afterReset)!==$targetConfiguration))) {
                // Partial, missing, fractional or invalid readback is UNKNOWN.
                // Never issue another RESET to resolve an uncertain result.
                return (object)['success'=>false,'uncertain'=>true,'msg'=>'marzban_reset_readback_unverified'];
            }
        }
        $beforePut=$preEffect(isset($info['volume']));
        if ($beforePut!==null)
            return (object)['success'=>false,'uncertain'=>true,'msg'=>$beforePut];

        // Marzban's UserModify endpoint preserves omitted fields. The
        // request must contain ONLY the absolute service limits purchased
        // in this operation; a quota-only edit may not overwrite an
        // independent expiry update (and vice versa). No stale full remote
        // snapshot, status, credentials, proxies or notes is sent.
        // Natural expired/limited reactivation is verified by strict GET.
        $postFields=[];
        if (isset($info['days']) || isset($info['plus_day']))
            $postFields['expire']=$expire;
        if (isset($info['volume']) || isset($info['plus_volume']))
            $postFields['data_limit']=$volume;
        if (!$postFields)
            return (object)['success'=>false,'uncertain'=>false,
                            'msg'=>'service_edit_missing_change'];
        $json=json_encode($postFields);
        if ($json===false)
            return (object)['success'=>false,'uncertain'=>true,'msg'=>'marzban_encode_failure'];

        $put=function() use ($panelUrl,$remark,$token,$json,$expire,$volume,$remoteInteger,$remoteQuota): array {
            $curl=curl_init();
            curl_setopt($curl,CURLOPT_URL,$panelUrl.'/api/user/'.rawurlencode($remark));
            curl_setopt($curl,CURLOPT_RETURNTRANSFER,true);
            curl_setopt($curl,CURLOPT_CUSTOMREQUEST,'PUT');
            curl_setopt($curl,CURLOPT_CONNECTTIMEOUT,5);
            curl_setopt($curl,CURLOPT_TIMEOUT,20);
            curl_setopt($curl,CURLOPT_SSL_VERIFYPEER,true);
            curl_setopt($curl,CURLOPT_SSL_VERIFYHOST,2);
            curl_setopt($curl,CURLOPT_POSTFIELDS,$json);
            curl_setopt($curl,CURLOPT_HTTPHEADER,[
                'Accept: application/json',
                'Authorization: Bearer '.$token->access_token,
                'Content-Type: application/json'
            ]);
            $raw=curl_exec($curl);
            $error=curl_error($curl);
            $http=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);
            curl_close($curl);
            // The provider's reply is useful evidence, but never enough to
            // mark a paid renewal/extension as delivered on its own.
            $retryable=($raw===false || $error!=='' ||
                        in_array($http,[408,500,502,503,504],true));
            if ($raw===false || $error!=='' || $http<200 || $http>=300)
                return ['valid'=>false,'retryable'=>$retryable];
            $reply=json_decode((string)$raw);
            $valid=is_object($reply) && !isset($reply->detail) &&
                (string)($reply->username??'')===$remark &&
                isset($reply->expire) &&
                $remoteInteger($reply->expire)===$expire &&
                $remoteQuota($reply)===$volume;
            // A malformed 2xx body is not proof of remote failure or success.
            // If exact GET still shows the original state, one identical
            // absolute PUT is safe; never replay a relative increment.
            return ['valid'=>$valid,'retryable'=>!$valid];
        };
        $read=function() use ($server_id,$remark,$token,$isPinned,$remoteInteger,$remoteQuota,$panelUrl): ?object {
            if (!$isPinned()) return null;
            $observed=getMarzbanUser($server_id,$remark,$token,true,$panelUrl);
            if (!$isPinned() || !is_object($observed) ||
                (string)($observed->username??'')!==$remark ||
                !isset($observed->expire) ||
                $remoteInteger($observed->expire)===null ||
                $remoteQuota($observed)===null)
                return null;
            return $observed;
        };
        $matches=function(?object $observed,int $wantExpire,int $wantVolume,
                          bool $requested=false)
            use ($remoteInteger,$remoteQuota,$configuration,$baseline,$targetConfiguration): bool {
            return $observed!==null &&
                $remoteInteger($observed->expire)===$wantExpire &&
                $remoteQuota($observed)===$wantVolume &&
                $configuration($observed)===($requested?$targetConfiguration:$baseline);
        };

        $firstReply=$put();
        $observed=$read();
        if ($matches($observed,$expire,$volume,true) &&
            (string)($observed->status??'')==='active') {
            return (object)['success'=>true,'verified'=>true,
                'applied_expire'=>$expire,'applied_bytes'=>$volume,
                'reconciled'=>!$firstReply['valid'],'repaired'=>false];
        }
        // A fully observed ORIGINAL state permits one corrective absolute
        // PUT with exactly the same target values. No second relative delta,
        // no second traffic reset, and no retry on unknown/foreign state.
        if ($matches($observed,$oldExpire,$oldVolume) &&
            ($firstReply['valid'] || $firstReply['retryable'])) {
            $put();
            $verified=$read();
            if ($matches($verified,$expire,$volume,true) &&
                (string)($verified->status??'')==='active') {
                return (object)['success'=>true,'verified'=>true,
                    'applied_expire'=>$expire,'applied_bytes'=>$volume,
                    'reconciled'=>true,'repaired'=>true];
            }
        }
        return (object)['success'=>false,'uncertain'=>true,
            'msg'=>$observed===null?'marzban_readback_unavailable':'marzban_edit_unverified'];
    } finally {
        try {
            $unlock=$connection->prepare('SELECT RELEASE_LOCK(?) AS released');
            if ($unlock) {
                $unlock->bind_param('s',$lockName);
                $unlock->execute();
                $unlock->close();
            }
        } catch (\Throwable $ignored) {
            // Locks are connection-bound; failed unlock cannot turn a
            // remote success into failure or authorize a second update.
        }
    }
}

function resetMarzbanTraffic($server_id, $remark, $token, ?string $pinnedPanelUrl=null){
    global $connection;
    $stmt=$connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param('i',$server_id);
    $stmt->execute();
    $server=$stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$server || ($server['type']??'')!=='marzban') {
        return (object)['success'=>false,'uncertain'=>false,'msg'=>'unsupported_panel'];
    }
    // When invoked by the verified edit coordinator, no second lookup may
    // silently retarget the reset to a different physical panel.
    if ($pinnedPanelUrl!==null &&
        rtrim((string)$server['panel_url'],'/')!==rtrim($pinnedPanelUrl,'/')) {
        return (object)['success'=>false,'uncertain'=>true,'msg'=>'marzban_target_changed'];
    }
    if ($token===null) $token=getMarzbanToken($server_id,$pinnedPanelUrl);
    if (!is_object($token) || empty($token->access_token)) {
        return (object)['success'=>false,'uncertain'=>true,'msg'=>'marzban_auth_unavailable'];
    }
    $curl=curl_init();
    curl_setopt($curl,CURLOPT_URL,rtrim($pinnedPanelUrl===null?
                               (string)$server['panel_url']:$pinnedPanelUrl,'/').
                               '/api/user/'.rawurlencode($remark).'/reset');
    curl_setopt($curl,CURLOPT_POST,true);
    curl_setopt($curl,CURLOPT_RETURNTRANSFER,true);
    curl_setopt($curl,CURLOPT_CONNECTTIMEOUT,5);
    curl_setopt($curl,CURLOPT_TIMEOUT,20);
    curl_setopt($curl,CURLOPT_SSL_VERIFYPEER,true);
    curl_setopt($curl,CURLOPT_SSL_VERIFYHOST,2);
    curl_setopt($curl,CURLOPT_HTTPHEADER,[
        'Accept: application/json',
        'Authorization: Bearer '.$token->access_token
    ]);
    $raw=curl_exec($curl);
    $error=curl_error($curl);
    $http=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);
    curl_close($curl);
    if ($raw===false || $error!=='' || $http!==200) {
        return (object)['success'=>false,'uncertain'=>true,'msg'=>'marzban_reset_unconfirmed'];
    }
    $reply=json_decode($raw);
    if (!is_object($reply) || (string)($reply->username??'')!==(string)$remark ||
        isset($reply->detail)) {
        return (object)['success'=>false,'uncertain'=>true,'msg'=>'marzban_reset_response_unverified'];
    }
    return (object)['success'=>true];
}

function renewMarzbanUUID($server_id,$remark){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];
    $token = getMarzbanToken($server_id);
    if(isset($token->detail)){return (object) ['success'=>false, 'msg'=>$token->detail];}
    $panel_url .= '/api/user/' . $remark .'/revoke_sub';

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $panel_url);
    curl_setopt($curl, CURLOPT_POST , true);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
        'Accept: application/json',
        'Authorization: Bearer ' .  $token->access_token
    ));

    $response = json_decode(curl_exec($curl));
    curl_close($curl);
    if(isset($response->detail)){
		$detail = $response->detail;
        return (object) ['success'=>false, 'msg' => is_object($detail)?implode("-", (array) $detail):$detail];
    }
    $response = getMarzbanUserInfo($server_id, $remark);
    return $response;
}


function deleteMarzban($server_id,$remark){
    global $connection;
    // Never treat an empty/failed cURL response or an HTTP 4xx/5xx as
    // proof that a destructive remote DELETE was completed.
    $stmt=$connection->prepare("SELECT panel_url,type FROM server_config WHERE id=?");
    $stmt->bind_param('i',$server_id);
    $stmt->execute();
    $server=$stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$server || ($server['type']??'')!=='marzban' ||
        !is_string($remark) || $remark==='') {
        return (object)['success'=>false,'uncertain'=>false,
                        'msg'=>'unsupported_remote_delete'];
    }
    $token=getMarzbanToken($server_id);
    if (!is_object($token) || empty($token->access_token)) {
        return (object)['success'=>false,'uncertain'=>true,
                        'msg'=>'remote_delete_auth_unconfirmed'];
    }
    $curl=curl_init();
    curl_setopt($curl,CURLOPT_URL,rtrim((string)$server['panel_url'],'/').
                '/api/user/'.rawurlencode($remark));
    curl_setopt($curl,CURLOPT_CUSTOMREQUEST,'DELETE');
    curl_setopt($curl,CURLOPT_RETURNTRANSFER,true);
    curl_setopt($curl,CURLOPT_CONNECTTIMEOUT,5);
    curl_setopt($curl,CURLOPT_TIMEOUT,20);
    curl_setopt($curl,CURLOPT_SSL_VERIFYPEER,true);
    curl_setopt($curl,CURLOPT_SSL_VERIFYHOST,2);
    curl_setopt($curl,CURLOPT_HTTPHEADER,[
        'Accept: application/json',
        'Authorization: Bearer '.$token->access_token
    ]);
    $raw=curl_exec($curl);
    $error=curl_error($curl);
    $http=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);
    curl_close($curl);
    if ($raw===false || $error!=='' || $http<200 || $http>=300) {
        return (object)['success'=>false,'uncertain'=>true,
                        'msg'=>'remote_delete_response_unconfirmed'];
    }
    // Pinned Gozargah/Marzban DELETE /api/user/{username} returns HTTP 200
    // {"detail":"User successfully deleted"} after deleting the user.
    // Ref: app/routers/user.py @ 7f396db3e703d71a28060bc9ce4a532ec64cb1f4.
    // A generic JSON object, different detail, or undocumented empty 204
    // response is NOT proof of removal on the supported provider contract.
    $response=json_decode((string)$raw);
    if ($http===200 && is_object($response) &&
        get_object_vars($response)===['detail'=>'User successfully deleted']) {
        return (object)['success'=>true];
    }
    return (object)['success'=>false,'uncertain'=>true,
                    'msg'=>'remote_delete_body_unconfirmed'];
}

function changeMarzbanState($server_id,$remark){
    global $connection;
    
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];
    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];
    $serverType = $server_info['type'];
    
    $token = getMarzbanToken($server_id);
    if(isset($token->detail)){return (object) ['success'=>false, 'msg'=>$token->detail];}
    $configInfo = getMarzbanUser($server_id, $remark, $token);

    $panel_url .=  '/api/user/'. $remark;

    $postFields = array(
        "inbounds" => $configInfo->inbounds,
        "proxies" => $configInfo->proxies,
        "expire" => $configInfo->expire,
        "data_limit" => $configInfo->data_limit,
        "username" => urlencode($remark),
        "note" => $configInfo->note,
        "data_limit_reset_strategy"=> $configInfo->data_limit_reset_strategy,
        "status" => $configInfo->status == "active"?"disabled":"active"
    );


    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $panel_url);
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
        'Accept: application/json',
        'Authorization: Bearer ' .  $token->access_token,
        'Content-Type: application/json'
    ));
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($postFields));

    $response = json_decode(curl_exec($curl));
    curl_close($curl);

    if(isset($response->detail)){
		$detail = $response->detail;
        return (object) ['success'=>false, 'msg' => is_object($detail)?implode("-", (array) $detail):$detail];
    }
    return (object) ['success'=>true];
}

