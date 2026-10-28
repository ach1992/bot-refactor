<?php
/** AChBot provider-neutral, read-only service observations and lifecycle policy. */
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}

/** Null/zero are unlimited; malformed/negative values never authorize a mutation. */
function achbotServiceNonnegative($value): ?int {
    if (is_int($value)) return $value>=0?$value:null;
    if (is_string($value) && preg_match('/^(?:0|[1-9][0-9]*)$/D',$value)===1 &&
        strlen($value)<=strlen((string)PHP_INT_MAX) &&
        (strlen($value)<strlen((string)PHP_INT_MAX) || strcmp($value,(string)PHP_INT_MAX)<=0))
        return (int)$value;
    return null;
}
function achbotServiceObservation($remote,string $username): array {
    $invalid=['verified'=>false,'status'=>'unknown','enabled'=>false,'expire'=>null,
        'quota'=>null,'used'=>null,'remaining'=>null];
    if ($username==='' || !is_object($remote) || !is_string($remote->username??null) ||
        $remote->username!==$username || !in_array($remote->status??null,
            ['active','disabled','expired','limited','on_hold'],true) ||
        !property_exists($remote,'expire') || !property_exists($remote,'data_limit') ||
        !property_exists($remote,'used_traffic')) return $invalid;
    $used=achbotServiceNonnegative($remote->used_traffic);
    $quota=$remote->data_limit===null?0:achbotServiceNonnegative($remote->data_limit);
    $expiry=$remote->expire===null?0:achbotServiceNonnegative($remote->expire);
    if ($used===null || $quota===null || $expiry===null) return $invalid;
    return ['verified'=>true,'status'=>$remote->status,'enabled'=>$remote->status==='active',
        'expire'=>$expiry===0?null:$expiry,'quota'=>$quota===0?null:$quota,
        'used'=>$used,'remaining'=>$quota===0?null:max(0,$quota-$used)];
}
function achbotServiceObserve(int $serverId,string $username): array {
    if ($serverId<=0 || $username==='') return achbotServiceObservation(null,$username);
    try {$remote=achbotProviderUser($serverId,$username,false);}
    catch (Throwable $error) {$remote=null;}
    return achbotServiceObservation($remote,$username);
}
/** A deletion deadline must be a canonical timestamp after this order existed. */
function achbotServiceGraceValid(array $order): bool {
    $created=achbotServiceNonnegative($order['date']??null);
    $deadline=achbotServiceNonnegative($order['notif']??null);
    return $created!==null && $created>0 && $deadline!==null && $deadline>$created;
}

/** Existing product thresholds, independent of panel implementation. */
function achbotServiceLifecycle(array $observation,int $now): array {
    $out=['warning'=>'','renew'=>false,'ended'=>false];
    if (($observation['verified']??false)!==true ||
        !in_array($observation['status']??null,['active','expired','limited'],true)) return $out;
    $expiry=$observation['expire']??null;$remaining=$observation['remaining']??null;
    if (!array_key_exists('expire',$observation) || !array_key_exists('remaining',$observation) ||
        ($expiry!==null && (!is_int($expiry) || $expiry<=0)) ||
        ($remaining!==null && (!is_int($remaining) || $remaining<0))) return $out;
    $endedTime=$expiry!==null && $expiry<=$now;
    $endedQuota=$remaining!==null && $remaining===0;
    $out['ended']=$endedTime || $endedQuota;
    if ($expiry!==null && $expiry<$now+129600) $out['warning']='time';
    elseif ($remaining!==null && $remaining<0.5*1073741824) $out['warning']='quota';
    $out['renew']=($expiry!==null && $expiry<$now+7200) ||
        ($remaining!==null && $remaining<0.2*1073741824);
    return $out;
}
