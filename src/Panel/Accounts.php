<?php
/** Current Marzban provider only. Legacy X-UI/Sanaei/Alireza never deployed and retired after V9. */
namespace AchBot\Panel\Accounts;
use stdClass;

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

function getMarzbanToken($server_id){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id = ?");
    $stmt->bind_param('i', $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Fail closed: neither unexpected provider records nor missing servers
    // may be probed using Marzban credentials/API as a silent fallback.
    if (!$server_info || ($server_info['type'] ?? '') !== 'marzban') {
        return (object) ['detail' => 'unsupported_panel'];
    }

    $panel_url = $server_info['panel_url'];
    $username = $server_info['username'];
    $password = $server_info['password'];
    
    $loginUrl = $panel_url .'/api/admin/token';
    $postFields = array(
        'username' => $username,
        'password' => $password
    );
    
    
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $loginUrl);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/x-www-form-urlencoded',
            'accept: application/json'
        ));
    $response = curl_exec($curl);
    if (curl_error($curl)) {
        return (object) ['success'=>false, 'detail'=>curl_error($curl)];
    }
    curl_close($curl);

    return json_decode($response);
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

function getMarzbanUser($server_id, $remark, $token = null, bool $strict = false){
    global $connection;
    
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];

    if($token == null) $token = getMarzbanToken($server_id);
    if(isset($token->detail)){return (object) ['success'=>false, 'msg'=>$token->detail];}
    
    $panel_url .= '/api/user/' . urlencode($remark);

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $panel_url);
    curl_setopt($curl, CURLOPT_HTTPGET, true);
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

function addMarzbanUser($server_id, $remark, $volume, $days, $plan_id, ?string $operationNote = null, ?int $fixedExpire = null){
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
    
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ?");
    $stmt->bind_param('i', $plan_id);
    $stmt->execute();
    $planInfo = json_decode($stmt->get_result()->fetch_assoc()['custom_sni'],true);
    $stmt->close();

    $token = getMarzbanToken($server_id);
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

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $panel_url . "/api/user");
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
    $userInfo = getMarzbanUserInfo($server_id, $remark);

    return (object) [
        'success'=>true,
        'sub_link'=> $userInfo->subscription_url,
        'vray_links' => $response->links
        ];
}

function editMarzbanConfig($server_id,$info){
    global $connection;

    $stmt=$connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i",$server_id);
    $stmt->execute();
    $serverInfo=$stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$serverInfo || ($serverInfo['type'] ?? '') !== 'marzban') {
        return (object)['success'=>false,'uncertain'=>false,'msg'=>'unsupported_panel'];
    }
    $token=getMarzbanToken($server_id);
    if (!is_object($token) || empty($token->access_token)) {
        return (object)['success'=>false,'uncertain'=>true,'msg'=>'marzban_auth_unavailable'];
    }

    $remark=(string)($info['remark'] ?? '');
    if ($remark==='') return (object)['success'=>false,'uncertain'=>false,'msg'=>'missing_account'];
    $current=getMarzbanUser($server_id,$remark,$token);
    if (!is_object($current) || (string)($current->username ?? '')!==$remark ||
        !isset($current->expire,$current->data_limit) ||
        !is_numeric($current->expire) || !is_numeric($current->data_limit) ||
        !isset($current->inbounds,$current->proxies)) {
        return (object)['success'=>false,'uncertain'=>true,'msg'=>'marzban_account_unverified'];
    }

    $expire=(int)$current->expire;
    $volume=(int)$current->data_limit;
    if (isset($info['plus_day'])) {
        $days=(int)$info['plus_day'];
        if ($days<=0 || $days>36500) return (object)['success'=>false,'uncertain'=>false,'msg'=>'invalid_days'];
        $expire=max($expire,time())+86400*$days;
    } elseif (isset($info['days'])) {
        $days=(int)$info['days'];
        if ($days<=0 || $days>36500) return (object)['success'=>false,'uncertain'=>false,'msg'=>'invalid_days'];
        $expire=time()+86400*$days;
    }

    if (isset($info['plus_volume'])) {
        $extra=(int)$info['plus_volume'];
        if ($extra<=0 || $extra>intdiv(PHP_INT_MAX,1073741824) ||
            $volume>PHP_INT_MAX-$extra*1073741824) {
            return (object)['success'=>false,'uncertain'=>false,'msg'=>'invalid_volume'];
        }
        $volume+=$extra*1073741824;
    } elseif (isset($info['volume'])) {
        $gigabytes=(int)$info['volume'];
        if ($gigabytes<0 || $gigabytes>intdiv(PHP_INT_MAX,1073741824)) {
            return (object)['success'=>false,'uncertain'=>false,'msg'=>'invalid_volume'];
        }
        $volume=$gigabytes*1073741824;
        $reset=resetMarzbanTraffic($server_id,$remark,$token);
        if (!is_object($reset) || empty($reset->success)) {
            return (object)['success'=>false,'uncertain'=>true,'msg'=>'marzban_reset_unverified'];
        }
    }

    $postFields=[
        'inbounds'=>$current->inbounds,
        'proxies'=>$current->proxies,
        'expire'=>$expire,
        'data_limit'=>$volume,
        'username'=>urlencode($remark),
        'note'=>$current->note ?? null,
        'data_limit_reset_strategy'=>$current->data_limit_reset_strategy ?? 'no_reset',
        'status'=>'active'
    ];
    $json=json_encode($postFields);
    if ($json===false) return (object)['success'=>false,'uncertain'=>true,'msg'=>'marzban_encode_failure'];
    $curl=curl_init();
    curl_setopt($curl,CURLOPT_URL,rtrim((string)$serverInfo['panel_url'],'/').'/api/user/'.rawurlencode($remark));
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
    // A lost response does not prove the PUT did not reach Marzban.
    if ($raw===false || $error!=='' || $http<200 || $http>=300) {
        return (object)['success'=>false,'uncertain'=>true,'msg'=>'marzban_put_unconfirmed'];
    }
    $reply=json_decode($raw);
    // Marzban PUT responds with a UserResponse. HTTP 2xx or an empty body
    // alone is not proof of the applied expiry/volume modification.
    if (!is_object($reply) || isset($reply->detail) ||
        (string)($reply->username ?? '')!==$remark ||
        !isset($reply->expire,$reply->data_limit) ||
        !is_numeric($reply->expire) || !is_numeric($reply->data_limit) ||
        (int)$reply->expire!==$expire || (int)$reply->data_limit!==$volume) {
        return (object)['success'=>false,'uncertain'=>true,'msg'=>'marzban_put_response_unverified'];
    }
    return (object)['success'=>true];
}

function resetMarzbanTraffic($server_id, $remark, $token){
    global $connection;
    $stmt=$connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param('i',$server_id);
    $stmt->execute();
    $server=$stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$server || ($server['type']??'')!=='marzban') {
        return (object)['success'=>false,'uncertain'=>false,'msg'=>'unsupported_panel'];
    }
    if ($token===null) $token=getMarzbanToken($server_id);
    if (!is_object($token) || empty($token->access_token)) {
        return (object)['success'=>false,'uncertain'=>true,'msg'=>'marzban_auth_unavailable'];
    }
    $curl=curl_init();
    curl_setopt($curl,CURLOPT_URL,rtrim((string)$server['panel_url'],'/').
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

