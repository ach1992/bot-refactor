<?php
/** Authenticated customer callbacks and genuine staff typed-code review. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/../ApiPayments.php';
$apiCallback=[];$apiStep=[];
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
if(preg_match('/^api(Check|Gift)_([A-Za-z0-9_-]{1,50})$/D',(string)($data??''),$apiCallback)) {
    $c=achbotApiPaymentContext($connection,$apiCallback[2],(int)$from_id);
    if(!$c || (int)($chat_id??0)!==(int)$from_id){alert(achbotMessage('payment.api_payment.denied'),true);return;}
    answerCallbackQuery($callbackId);
    if(achbotApiPaymentTerminal($c['pay']) || $c['pay']['state']==='have_sent') {
        achbotApiPaymentSyncInvoice($connection,$c);return;
    }
    [$send]=achbotApiPaymentReceiptPrefixes($c['pay']);
    if($c['attempt']['provider']==='gift_card'){$send=str_replace('CartReceipt','GiftReceipt',$send);if($send==='sendIncreaseWalletReceipt')$send='sendIncreaseWalletGiftReceipt';}
    $data=$send.$apiCallback[2];return 1;
}
if(preg_match('/^apiGiftCode_([A-Za-z0-9_-]{1,50})$/D',(string)($userInfo['step']??''),$apiStep)) {
    $navigation=in_array((string)($text??''),array_filter([$buttonValues['cancel']??null,$buttonValues['back_to_main']??null,$buttonValues['back_button']??null],static fn($v)=>is_string($v)&&$v!==''),true) || preg_match('/^\/[Ss]tart(?:@[A-Za-z0-9_]+)?(?:\s|$)/D',(string)($text??''));
    if((string)($data??'')!=='' || $navigation){setUser();$userInfo['step']='none';if($navigation&&!preg_match('/^\/[Ss]tart/',(string)($text??'')))$text='/start';return 1;}
    $c=achbotApiPaymentContext($connection,$apiStep[1],(int)$from_id);
    if(!$c || $c['attempt']['provider']!=='gift_card' || !achbotManualReceiptAllowed($connection,$c['pay'],'gift_card',(int)$from_id)){setUser();sendMessage(achbotMessage('payment.api_payment.denied'));return;}
    [$send]=achbotApiPaymentReceiptPrefixes($c['pay']);$send=str_replace('CartReceipt','GiftReceipt',$send);if($send==='sendIncreaseWalletReceipt')$send='sendIncreaseWalletGiftReceipt';
    $userInfo['step']='wait'.substr($send,4).$apiStep[1];
    setUser($userInfo['step']);return 1;
}
return 1;
