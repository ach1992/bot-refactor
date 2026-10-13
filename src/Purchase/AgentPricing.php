<?php
/** AChBot V7: immutable V6.1 purchase helper definitions. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

if (!function_exists('calculateAgentPrice')) {
    function calculateAgentPrice($userInfo, $botState, $planId, $serverId, $buyType, $basePrice)
    {
        $price           = (int)$basePrice;
        $agentBought     = false;
        $discountPercent = 0;

        if ($buyType === 'one' || $buyType === 'much') {
            $agentBought = true;
            $discountsRaw = $userInfo['discount_percent'] ?? '{}';
            $discounts    = json_decode($discountsRaw, true);
            if (!is_array($discounts)) {
                $discounts = [];
            }

            $normal = isset($discounts['normal']) ? (int)$discounts['normal'] : 0;

            if (($botState['agencyPlanDiscount'] ?? '') === "on") {
                if (isset($discounts['plans'][$planId])) {
                    $discountPercent = (int)$discounts['plans'][$planId];
                } else {
                    $discountPercent = $normal;
                }
            } else {
                if (isset($discounts['servers'][$serverId])) {
                    $discountPercent = (int)$discounts['servers'][$serverId];
                } else {
                    $discountPercent = $normal;
                }
            }

            if ($discountPercent > 0) {
                $price -= floor($price * $discountPercent / 100);
            }
        }

        return [$price, $agentBought, $discountPercent];
    }
}

