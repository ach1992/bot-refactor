<?php
/** Native Marzban plan Host changes; never rewrite credentials or service limits. */
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}

/** Canonical set of protocol->inbound tags; reject incomplete plan configuration. */
function achbotMarzbanPlanInbounds(array $plan): array {
    try {
        $p=json_decode((string)($plan['custom_sni']??''),true,24,JSON_THROW_ON_ERROR);
        if (!is_array($p) || !is_array($p['inbounds']??null) || !$p['inbounds'] ||
            !is_array($p['proxies']??null)) throw new RuntimeException();
        $out=[];
        foreach ($p['inbounds'] as $protocol=>$tags) {
            if (!is_string($protocol) || !preg_match('/^[a-z0-9_-]{2,32}$/D',$protocol)
                || !is_array($tags) || !$tags) throw new RuntimeException();
            if (!array_key_exists($protocol,$p['proxies'])) throw new RuntimeException();
            foreach ($tags as $tag)
                if (!is_string($tag) || $tag==='' || strlen($tag)>160)
                    throw new RuntimeException();
            $out[$protocol]=array_values(array_unique($tags));
            sort($out[$protocol],SORT_STRING);
        }
        ksort($out,SORT_STRING);
        return $out;
    } catch (Throwable $e) {throw new RuntimeException('marzban_plan_hosts_invalid');}
}

/** Native responses may be objects/arrays; avoid protocol-order false negatives. */
function achbotMarzbanNativeHosts($inbounds): array {
    if (!is_object($inbounds) && !is_array($inbounds)) throw new RuntimeException('marzban_hosts_unverified');
    $result=[];
    foreach ((array)$inbounds as $protocol=>$tags) {
        if (!is_string($protocol) || !is_array($tags)) throw new RuntimeException('marzban_hosts_unverified');
        foreach ($tags as $tag) if (!is_string($tag)) throw new RuntimeException('marzban_hosts_unverified');
        $result[$protocol]=array_values(array_unique($tags));
        sort($result[$protocol],SORT_STRING);
    }
    ksort($result,SORT_STRING);
    return $result;
}

function achbotMarzbanNativeAccessSnapshot(object $account,string $username): array {
    if (($account->username??null)!==$username ||
        !isset($account->inbounds,$account->proxies,$account->status,
            $account->data_limit_reset_strategy,$account->created_at) ||
        !property_exists($account,'data_limit') ||
        !property_exists($account,'expire') ||
        !property_exists($account,'used_traffic') ||
        !is_int($account->used_traffic) || $account->used_traffic<0 ||
        isset($account->next_plan))
        throw new RuntimeException('marzban_account_unverified');
    return [
        'username'=>$username,'created_at'=>$account->created_at,
        'status'=>$account->status,'data_limit'=>$account->data_limit,
        'expire'=>$account->expire,'reset'=>$account->data_limit_reset_strategy,
        'used_traffic'=>$account->used_traffic,
        'note'=>$account->note??null,
        'proxies'=>json_encode($account->proxies,JSON_THROW_ON_ERROR),
        'inbounds'=>achbotMarzbanNativeHosts($account->inbounds)
    ];
}

/** Account-level lock and durable cross-provider receipt belong to caller. */
function achbotMarzbanApplyPlanHosts(int $serverId,string $username,array $wanted,
    ?callable $beforeWrite=null): array {
    global $connection;
    $dispatched=false;
    try {
        $s=$connection->prepare("SELECT panel_url,type FROM server_config WHERE id=?");
        $s->bind_param('i',$serverId);$s->execute();$row=$s->get_result()->fetch_assoc();$s->close();
        if (!$row || $row['type']!=='marzban')
            throw new RuntimeException('marzban_panel_invalid');
        $url=rtrim((string)$row['panel_url'],'/');
        $token=getMarzbanToken($serverId,$url);
        if (!is_object($token) || !is_string($token->access_token??null) || $token->access_token==='')
            throw new RuntimeException('marzban_auth_unavailable');
        $account=getMarzbanUser($serverId,$username,$token,true,$url);
        if (!is_object($account)) throw new RuntimeException('marzban_account_unverified');
        $before=achbotMarzbanNativeAccessSnapshot($account,$username);
        // Never alter proxy UUID/password, notes, expiry, traffic, status, or
        // queued future plans. A selected plan's protocols must already be
        // backed by the existing native account credentials.
        foreach ($wanted as $protocol=>$tags)
            if (!property_exists($account->proxies,$protocol))
                throw new RuntimeException('marzban_plan_protocol_requires_review');
        // A different priced plan may choose exactly the Hosts already
        // assigned to this verified native account. No Native write, and no
        // redundant catalog or second account read is needed in that case.
        // The initial authenticated strict GET still rejects missing/drifted
        // native identity, unsupported protocols and queued future plans.
        if ($before['inbounds']===$wanted)
            return ['success'=>true,'changed'=>false,'verified'=>true];
        $catalog=getMarzbanHosts($serverId);
        if (!is_object($catalog) || !is_array($catalog->inbounds??null))
            throw new RuntimeException('marzban_hosts_unavailable');
        $allowed=[];
        foreach ($catalog->inbounds as $i)
            if (is_object($i) && is_string($i->protocol??null) && is_string($i->tag??null))
                $allowed[$i->protocol][$i->tag]=true;
        foreach ($wanted as $protocol=>$tags) foreach ($tags as $tag)
            if (!isset($allowed[$protocol][$tag]))
                throw new RuntimeException('marzban_plan_hosts_removed');
        $read=static function() use ($connection,$serverId,$username,$token,$url): object {
            $s=$connection->prepare('SELECT panel_url,type FROM server_config WHERE id=?');
            $s->bind_param('i',$serverId);$s->execute();$row=$s->get_result()->fetch_assoc();$s->close();
            if (!$row || $row['type']!=='marzban' || rtrim((string)$row['panel_url'],'/')!==$url)
                throw new RuntimeException('marzban_target_changed');
            $fresh=getMarzbanUser($serverId,$username,$token,true,$url);
            if (!is_object($fresh)) throw new RuntimeException('marzban_readback_missing');
            return $fresh;
        };
        $fresh=achbotMarzbanNativeAccessSnapshot($read(),$username);
        if ($fresh['used_traffic']<$before['used_traffic'])
            throw new RuntimeException('marzban_usage_reduced_before_put');
        $freshUsage=$fresh['used_traffic'];
        unset($fresh['used_traffic']);
        $beforeWithoutUsage=$before;unset($beforeWithoutUsage['used_traffic']);
        if ($fresh!==$beforeWithoutUsage)
            throw new RuntimeException('marzban_before_access_changed');
        if ($beforeWrite!==null) $beforeWrite();
        $json=json_encode(['inbounds'=>$wanted],JSON_THROW_ON_ERROR);
        $curl=curl_init($url.'/api/user/'.rawurlencode($username));
        curl_setopt_array($curl,[
            CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>'PUT',
            CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_POSTFIELDS=>$json,
            CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token->access_token,
                'Accept: application/json','Content-Type: application/json']
        ]);
        $dispatched=true;
        $response=curl_exec($curl);
        $http=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);
        // A failed transfer has a non-empty standard cURL error string.
        // This also keeps the HTTP/transport failure contract consistent
        // with the same native adapter's existing checked cURL surfaces.
        $curlError=curl_error($curl);
        unset($curl); // PHP 8.5 deprecates curl_close(); release CurlHandle instead.
        if ($response===false || $curlError!=='' || $http<200 || $http>=300)
            throw new RuntimeException('marzban_plan_put_unverified');
        $ack=json_decode((string)$response,true,64);
        if (!is_array($ack) || ($ack['username']??null)!==$username)
            throw new RuntimeException('marzban_plan_ack_unverified');
        $after=achbotMarzbanNativeAccessSnapshot($read(),$username);
        if ($after['inbounds']!==$wanted) throw new RuntimeException('marzban_plan_hosts_unverified');
        if ($after['used_traffic']<$freshUsage)
            throw new RuntimeException('marzban_plan_usage_reduced');
        unset($after['inbounds'],$before['inbounds'],$after['used_traffic'],$before['used_traffic']);
        if ($after!==$before) throw new RuntimeException('marzban_plan_other_fields_changed');
        return ['success'=>true,'changed'=>true,'verified'=>true];
    } catch (Throwable $error) {
        return ['success'=>false,'changed'=>$dispatched,'verified'=>false,
            'reason'=>$error->getMessage()];
    }
}
