<?php
/** AChBot V8: source-pinned financial compatibility module. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

if(preg_match('/^haveDiscount(.+?)_(.*)/',$data,$match)){
    delMessage();
    sendMessage($mainValues['insert_discount_code'],$cancelKey);
    if($match[1] == "Custom") setUser('discountCustomPlanDay' . $match[2]);
    elseif($match[1] == "SelectPlan") setUser('discountSelectPlan' . $match[2]);
    elseif($match[1] == "Renew") setUser('discountRenew' . $match[2]);
}
