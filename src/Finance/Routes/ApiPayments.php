<?php
/** Authenticated customer callbacks and genuine staff typed-code review. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/../ApiPayments.php';
$apiCallback=[];$apiStep=[];
if(preg_match('/^apiReview_([A-Za-z0-9_-]{1,50})$/D',(string)($data??''),$apiCallback)) {
    if((int)($chat_id??0)!==(int)$from_id || !achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'payments')) {alert(achbotMessage('payment.api_payment.denied'),true);return;}
    $c=achbotApiPaymentContext($connection,$apiCallback[1]);
    if(!$c || !achbotPaymentEvidenceHasTypedReviewLocked($connection,$c['pay'])) {alert(achbotMessage('payment.api_payment.denied'),true);return;}
    $code=achbotPaymentEvidenceReadGiftCode($connection,(int)$c['pay']['id']);
    if($code===null){alert(achbotMessage('payment.api_payment.unavailable'),true);return;}
    sendMessage(achbotMessage('payment.api_payment.code_received')."\n<code>".htmlspecialchars($code,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</code>',achbotApiPaymentReviewKeyboard($c['pay']),'HTML',(int)$from_id);
    return;
}
if(preg_match('/^api(Check|Gift)_([A-Za-z0-9_-]{1,50})$/D',(string)($data??''),$apiCallback)) {
    $c=achbotApiPaymentContext($connection,$apiCallback[2],(int)$from_id);
    if(!$c || (int)($chat_id??0)!==(int)$from_id) {alert(achbotMessage('payment.api_payment.denied'),true);return;}
    if(achbotApiPaymentTerminal($c['pay'])){alert(achbotMessage('payment.api_payment.confirmed'));return;}
    if($apiCallback[1]==='Gift' && $c['attempt']['provider']==='gift_card') {
        if(!empty($c['attempt']['evidence_code']) && ($c['attempt']['evidence_state']??'')!=='invalid_code') {
            $status=achbotApiPaymentCheck($connection,(int)$c['pay']['id']);$key=match($status){'confirmed','settled'=>'confirmed','review'=>'review','unavailable'=>'unavailable',default=>'pending'};alert(achbotMessage('payment.api_payment.'.$key));return;
        }
        setUser('apiGiftCode_'.$apiCallback[2]);sendMessage(achbotMessage('payment.api_payment.code_prompt'),achbotApiPaymentKeyboard($c),'HTML',(int)$from_id);return;
    }
    if($apiCallback[1]!=='Check'||$c['attempt']['provider']!=='bank_transfer'){alert(achbotMessage('payment.api_payment.denied'),true);return;}
    $status=achbotApiPaymentCheck($connection,(int)$c['pay']['id']);
    alert(achbotMessage('payment.api_payment.'.(in_array($status,['confirmed','settled'],true)?'confirmed':($status==='review'?'review':'bank_pending'))));return;
}
if(preg_match('/^apiGiftCode_([A-Za-z0-9_-]{1,50})$/D',(string)($userInfo['step']??''),$apiStep)) {
    if(($text??'')===($buttonValues['cancel']??null) || str_starts_with((string)($data??''),'cancel')) {setUser();return 1;}
    $c=achbotApiPaymentContext($connection,$apiStep[1],(int)$from_id);
    if(!$c||$c['attempt']['provider']!=='gift_card'||!in_array($c['pay']['state'],['pending','have_sent'],true)){setUser();sendMessage(achbotMessage('payment.api_payment.denied'));return;}
    $code=trim((string)($text??''));
    $saved=achbotPaymentEvidenceSaveGiftCode($connection,(int)$c['pay']['id'],(int)$from_id,$code);
    if(!in_array($saved['status']??'',['saved','same'],true)) {sendMessage(achbotMessage('payment.api_payment.'.(($saved['status']??'')==='locked'?'locked_code':'single_code')));return;}
    $status=achbotApiPaymentCheck($connection,(int)$c['pay']['id']);
    $key=match($status){'confirmed','settled'=>'confirmed','used'=>'used','revoked'=>'revoked','expired'=>'expired','mismatch'=>'mismatch','review'=>'review','unavailable'=>'unavailable',default=>'pending'};
    if(!in_array($status,['used','revoked','expired','mismatch'],true))setUser();
    sendMessage(achbotMessage('payment.api_payment.'.$key),achbotApiPaymentKeyboard(achbotApiPaymentContext($connection,$apiStep[1],(int)$from_id)??$c),'HTML',(int)$from_id);return;
}
return 1;
