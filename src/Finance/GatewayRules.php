<?php
/**
 * AChBot per-gateway eligibility rules. No DDL or external provider effects.
 * Only terminal local pays states count. pays.request_date is REQUEST time.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG

function achbotGatewayRuleCategories(): array {
    return array_diff_key(achbotPayUxAudienceModes(),['off'=>true]);
}
function achbotGatewayRuleMetrics(): array {
    return [
        'buy'=>\achbotMessage('payment_settings.gateway_rules.gateway_rule_metrics.text.7021d6f38e'),'renew'=>\achbotMessage('payment_settings.gateway_rules.gateway_rule_metrics.text.3225fab3cd'),'volume'=>\achbotMessage('payment_settings.gateway_rules.gateway_rule_metrics.text.4bbbd988ad'),
        'time'=>\achbotMessage('payment_settings.gateway_rules.gateway_rule_metrics.text.070602773a'),'topup'=>\achbotMessage('payment_settings.gateway_rules.gateway_rule_metrics.text.991ae08d64')
    ];
}
function achbotGatewayRuleBlank(string $category): array {
    $metrics=[];
    foreach (achbotGatewayRuleMetrics() as $key=>$label) {
        $metrics[$key]=['count'=>0,'days'=>0,'direction'=>'older'];
    }
    return [
        'category'=>$category,'enabled'=>true,'registration_age_days'=>0,
        'metrics'=>$metrics
    ];
}
/** Valid, nonnegative JSON integers. Days must be safe for seconds arithmetic. */
function achbotGatewayRuleInteger($number,bool $days=false): bool {
    return is_int($number) && $number>=0 &&
        (!$days || $number<=intdiv(PHP_INT_MAX,86400));
}
function achbotGatewayRuleValidate(array $rule): bool {
    if (array_keys($rule)!==['category','enabled','registration_age_days','metrics'] ||
        !is_string($rule['category']) ||
        !array_key_exists($rule['category'],achbotGatewayRuleCategories()) ||
        !is_bool($rule['enabled']) ||
        !achbotGatewayRuleInteger($rule['registration_age_days'],true) ||
        !is_array($rule['metrics']) ||
        array_keys($rule['metrics'])!==array_keys(achbotGatewayRuleMetrics()))
        return false;
    foreach ($rule['metrics'] as $metric) {
        if (!is_array($metric) ||
            array_keys($metric)!==['count','days','direction'] ||
            !achbotGatewayRuleInteger($metric['count']) ||
            !achbotGatewayRuleInteger($metric['days'],true) ||
            !in_array($metric['direction'],['older','recent'],true)) return false;
    }
    return true;
}
/**
 * null denotes malformed document; method omission denotes legacy fallback.
 * Method presence with [] is deliberately deny-all, never fallback to legacy.
 */
function achbotGatewayRulesDecode($raw): ?array {
    if (!is_array($raw) || array_keys($raw)!==['version','methods'] ||
        $raw['version']!==1 || !is_array($raw['methods'])) return null;
    foreach ($raw['methods'] as $method=>$entries) {
        if (!is_string($method) ||
            !array_key_exists($method,achbotPayUxAudienceMethods()) ||
            !is_array($entries) || !array_is_list($entries) ||
            count($entries)>count(achbotGatewayRuleCategories())) return null;
        $seen=[];
        foreach ($entries as $rule) {
            if (!is_array($rule) || !achbotGatewayRuleValidate($rule) ||
                isset($seen[$rule['category']])) return null;
            $seen[$rule['category']]=true;
        }
    }
    return $raw;
}
/** Return a precise per-user count, never a guessed success count. */
function achbotGatewayRuleHistoryCount(mysqli $db,int $userId,string $metric,
                                       int $days,string $direction,
                                       bool $final,int $now): ?int {
    $predicates=[
        'buy'=>"type='BUY_SUB'",
        'renew'=>"type IN ('RENEW_ACCOUNT','RENEW_SCONFIG')",
        'volume'=>"type LIKE 'INCREASE!_VOLUME!_%' ESCAPE '!'",
        'time'=>"type LIKE 'INCREASE!_DAY!_%' ESCAPE '!'",
        'topup'=>"type='INCREASE_WALLET'"
    ];
    if (!isset($predicates[$metric]) || $userId<=0) return null;
    // An all-history count is a property of confirmed transactions, NOT
    // their legacy request timestamps. Only a positive-day window depends
    // on request_date. This also preserves buyers/non_buyers classification.
    $timeSql='';
    if ($days>0) {
        $boundary=$now-$days*86400;
        $timeSql=' AND request_date>0 AND request_date<=? AND request_date'.
            ($direction==='recent'?'>=?':'<=?');
    }
    $sql="SELECT COUNT(*) AS n FROM pays".
        ($final?" FORCE INDEX (idx_pays_user_v1)":"").
        " WHERE user_id=? AND state IN ('paid','approved',
        'auto_cart_approved','paid_with_wallet') AND ".$predicates[$metric].
        $timeSql.($final?' FOR UPDATE':'');
    $stmt=$db->prepare($sql);
    if (!$stmt) throw new RuntimeException('rule_history_prepare');
    if ($days>0) {
        $stmt->bind_param('iii',$userId,$now,$boundary);
    } else $stmt->bind_param('i',$userId);
    if (!$stmt->execute()) throw new RuntimeException('rule_history_read');
    $record=$stmt->get_result()->fetch_assoc();$stmt->close();
    $value=$record['n']??null;
    if (!is_int($value) && (!is_string($value) || !preg_match('/\A[0-9]+\z/D',$value)))
        return null;
    $parsed=filter_var((string)$value,FILTER_VALIDATE_INT,
        ['options'=>['min_range'=>0]]);
    return $parsed===false?null:$parsed;
}
function achbotGatewayRuleMatchesUser(mysqli $db,int $userId,array $rule,
                                      array $user,bool $final,int $now): bool {
    $category=$rule['category'];
    if (!$rule['enabled']) return false;
    if ($rule['registration_age_days']>0) {
        $raw=(string)($user['date']??'');
        if (!preg_match('/\A[1-9][0-9]*\z/D',$raw)) return false;
        $date=filter_var($raw,FILTER_VALIDATE_INT,
            ['options'=>['min_range'=>1,'max_range'=>$now]]);
        if ($date===false || $now-$date<$rule['registration_age_days']*86400)
            return false;
    }
    if ($category==='agents' && (int)$user['is_agent']!==1) return false;
    if ($category==='non_agents' && (int)$user['is_agent']===1) return false;
    if ($category==='has_active_order' || $category==='no_active_order') {
        $sql='SELECT id FROM orders_list'.
            ($final?' FORCE INDEX (idx_orders_userid_v1)':'').
            ' WHERE userid=? AND status=1 AND expire_date>? LIMIT 1'.
            ($final?' FOR UPDATE':'');
        $stmt=$db->prepare($sql);
        if (!$stmt) throw new RuntimeException('rule_order_prepare');
        $stmt->bind_param('ii',$userId,$now);
        if (!$stmt->execute()) throw new RuntimeException('rule_order_read');
        $has=$stmt->get_result()->fetch_assoc()!==null;$stmt->close();
        if ($has!==($category==='has_active_order')) return false;
    }
    if ($category==='buyers' || $category==='non_buyers') {
        $purchased=achbotGatewayRuleHistoryCount(
            $db,$userId,'buy',0,'older',$final,$now);
        if ($purchased===null ||
            ($purchased>0)!==($category==='buyers')) return false;
    }
    foreach ($rule['metrics'] as $key=>$value) {
        if ($value['count']===0 && $value['days']===0) continue;
        $found=achbotGatewayRuleHistoryCount($db,$userId,$key,
            $value['days'],$value['direction'],$final,$now);
        $required=$value['count']===0?1:$value['count'];
        if ($found===null || $found<$required) return false;
    }
    return true;
}
/** Check a fully validated method policy using one unique current user. */
function achbotGatewayRulesAllow(mysqli $db,int $userId,array $rules,
                                 bool $final): bool {
    if (!$rules || $userId<=0) return false;
    $stmt=$db->prepare('SELECT id,is_agent,date FROM users'.
        ($final?' FORCE INDEX (idx_users_userid_v1)':'').
        ' WHERE userid=? LIMIT 2'.($final?' FOR UPDATE':''));
    if (!$stmt) throw new RuntimeException('rule_user_prepare');
    $stmt->bind_param('i',$userId);
    if (!$stmt->execute()) throw new RuntimeException('rule_user_read');
    $people=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
    if (count($people)!==1) return false;
    $now=time();
    foreach ($rules as $rule) {
        if (achbotGatewayRuleMatchesUser($db,$userId,$rule,$people[0],$final,$now))
            return true;
    }
    return false;
}
