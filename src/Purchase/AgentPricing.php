<?php
/** Shared monetary arithmetic and agency discount policy. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

/** Exact floor(amount * percent / 100), without an overflowing product. */
function achbotPercentageAmount(int $amount,int $percent): int {
    if ($amount<0 || $percent<0 || $percent>100)
        throw new InvalidArgumentException('price_percentage_invalid');
    return intdiv($amount,100)*$percent+intdiv(($amount%100)*$percent,100);
}

/** Resolve the configured plan/server override, then the default.
 * NULL means malformed, not permission for a free purchase. */
function achbotAgencyDiscountPercent(array $user,array $state,int $planId,int $serverId): ?int {
    $raw=$user['discount_percent']??'';
    if (!is_string($raw)) return null;
    $raw=trim($raw);
    $discounts=$raw===''?[]:json_decode($raw,true);
    if (!is_array($discounts)) return null;
    $scope=($state['agencyPlanDiscount']??'off')==='on'?'plans':'servers';
    $id=$scope==='plans'?$planId:$serverId;
    $overrides=$discounts[$scope]??[];
    if (!is_array($overrides)) return null;
    $percent=$overrides[$id]??$discounts['normal']??0;
    if (!(is_int($percent) || (is_string($percent) && preg_match('/^[0-9]{1,3}$/D',$percent))) ||
        (int)$percent<0 || (int)$percent>100) return null;
    return (int)$percent;
}

function achbotAgencyActive(array $user,array $state): bool {
    return (int)($user['is_agent']??0)===1 && ($state['agencyState']??'off')==='on';
}

function achbotAgencyQuotePolicy(array $user,array $state,int $planId,int $serverId): array {
    $active=achbotAgencyActive($user,$state);
    return ['active'=>$active,'percent'=>$active?achbotAgencyDiscountPercent($user,$state,$planId,$serverId):0];
}

/** Agency pricing follows current entitlement, not the purchase route
 * or the original subscription's administrative classification. */
function achbotAgencyPrice(array $user,array $state,int $planId,int $serverId,int $basePrice): array {
    $policy=achbotAgencyQuotePolicy($user,$state,$planId,$serverId);
    return [$basePrice<0?$basePrice:$basePrice-achbotPercentageAmount($basePrice,$policy['percent']??0),
        $policy['active'],$policy['percent']];
}

function achbotExistingServicePrice(array $user,array $state,array $order,int $basePrice): int {
    return achbotAgencyPrice($user,$state,(int)$order['fileid'],(int)$order['server_id'],$basePrice)[0];
}
