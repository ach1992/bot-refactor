<?php
/** Authorized staff typed-code review. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/../ApiPayments.php';
$apiCallback=[];
if(preg_match('/^apiReview_([A-Za-z0-9_-]{1,50})$/D',(string)($data??''),$apiCallback)) {
    if((int)($chat_id??0)!==(int)$from_id || !achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'payments')) {alert(achbotMessage('payment.api_payment.denied'),true);return;}
    $c=achbotApiPaymentContext($connection,$apiCallback[1]);
    if(!$c || !achbotPaymentEvidenceHasTypedReviewLocked($connection,$c['pay'])) {alert(achbotMessage('payment.api_payment.denied'),true);return;}
    answerCallbackQuery($callbackId,achbotMessage('payment.api_payment.pending'));
    $code=achbotPaymentEvidenceReadGiftCode($connection,(int)$c['pay']['id']);
    if($code===null){sendMessage(achbotMessage('payment.api_payment.unavailable'),null,'HTML',(int)$from_id);return;}
    sendMessage(achbotMessage('payment.api_payment.code_received')."\n<code>".htmlspecialchars($code,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</code>',achbotApiPaymentReviewKeyboard($c['pay']),'HTML',(int)$from_id);
    return;
}
return 1;
