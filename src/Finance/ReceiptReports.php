<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/PaymentCredit.php';
require_once __DIR__.'/FollowupOutbox.php';
require_once __DIR__.'/ReceiptHelpers.php';
require_once __DIR__.'/ApiPaymentPresentation.php';

/** Preserve the actual registered hosted label, including the historical
 * NOWPayments callback spelling. A new method can specify its display label
 * in its source definition, not branch in the shared settlement engine. */
function achbotReceiptReportGateway(array $attempt): ?string {
    $def=achbotCreditMethodDefinition((string)$attempt['provider']);
    if (!$def || !isset($def['hosted'])) return null;
    return $def['hosted']['report_gateway']??(string)$attempt['provider'];
}

/** Original receipt copies have a distinct audience from unsolicited
 * financial reports. Current staff permission and exact message pair must
 * both hold. A late verified credit updates the original payment receipt. */
function achbotReceiptReportEditAllowed(mysqli $db,array $source,array $r): bool {
    $pay=$source['pay'];$a=$source['attempt'];$role=$r['role'];
    if (achbotReceiptReportGateway($a)!==null ||
        $r['kind']!=='edit' || (int)$r['message_id']<=0 ||
        !in_array($role,['receipt_primary','receipt_mirror'],true)) return false;
    $who=(int)$r['recipient'];$message=(int)$r['message_id'];
    if ((string)$who!==$r['recipient'] ||
        !achbotAdminStoredRecipientAllowed($db,$who,(int)($GLOBALS['admin']??0))) return false;
    $primary=$who===(int)$pay['chat_id'] && $message===(int)$pay['message_id'];
    if ($role==='receipt_primary') return $primary;
    if ($primary) return false;
    $copies=json_decode((string)($pay['admins_messages']??''),true);
    if (!is_array($copies)) return false;
    foreach ($copies as $copy)
        if ((int)($copy['chat_id']??0)===$who && (int)($copy['message_id']??0)===$message) return true;
    return false;
}

/** Keep the original manual/API/timer terminal keyboard. The actor is read
 * from the actual once-only credit journal, not a later callback. */
function achbotReceiptReportStageEditsLocked(mysqli $db,array $source,?int $payId,?int $serviceId,
    string $stage,string $fingerprint,string $subject,bool $walletOnly=false): void {
    $pay=$source['pay'];$a=$source['attempt'];$basis=$a['wallet_credit_basis'];
    if (achbotReceiptReportGateway($a)!==null) return;
    if ($basis==='bank_timer') {
        $keys=json_encode(['inline_keyboard'=>[[[
            'text'=>achbotMessage('manual_payment.automatic_receipts.text.2898f8f112'),
            'callback_data'=>'wizwizch'
        ]]]],JSON_THROW_ON_ERROR);
    } else {
        $display=$pay;$display['approved_by']=$source['entry']['actor'];
        $keys=achbotApiPaymentFinalKeyboard($display,
            achbotMessage('manual_payment.api_payment.auto_approved'),$basis==='api',['subject'=>$subject],$walletOnly);
    }
    $targets=[['chat_id'=>$pay['chat_id'],'message_id'=>$pay['message_id'],'role'=>'receipt_primary']];
    $copies=json_decode((string)($pay['admins_messages']??''),true);
    if (is_array($copies)) foreach ($copies as $copy)
        if (is_array($copy)) $targets[]=['role'=>'receipt_mirror']+$copy;
    $seen=[];$owner=(int)($GLOBALS['admin']??0);
    foreach ($targets as $target) {
        $chat=(int)($target['chat_id']??0);$message=(int)($target['message_id']??0);
        if ($chat<=0 || $message<=0 || isset($seen[$chat.':'.$message])) continue;
        $seen[$chat.':'.$message]=true;$complete=true;
        $allowed=achbotAdminStoredRecipientAllowed($db,$chat,$owner,true,$complete);
        if (!$complete) throw new RuntimeException('payment_credit_report_staff_unavailable');
        if (!$allowed) continue;
        achbotFollowupStoreLocked($db,$payId,$serviceId,$stage,$target['role'],'edit',
            (string)$chat,$message,$fingerprint,['text'=>$keys,'keys'=>null,'mode'=>null]);
    }
}

/** Only retained money is a Wallet top-up from the user's perspective.
 * When this actual receipt funds a service, its existing service-completion
 * presentation owns the result. Never issue a second "top-up" success. */
function achbotReceiptReportStageLocked(mysqli $db,int $payId,int $userId): void {
    $source=achbotReceiptCreditEvidenceLocked($db,$payId,$userId);
    $pay=$source['pay'];$a=$source['attempt'];$entry=$source['entry'];
    if ($a['wallet_funding_result']==='funded') return;
    $stage='wallet_credit';$fingerprint=$source['fingerprint'];
    $gateway=achbotReceiptReportGateway($a);$basis=$a['wallet_credit_basis'];
    $amount=number_format((int)$pay['price']);
    $support=['supportID'=>(string)($GLOBALS['supportID']??'')];
    $mode='HTML';
    if ($gateway!==null) {
        $text=achbotMessage('gateway.gateway_return.perform_payment_effect.message.a5b15e454e').$amount.
            achbotMessageHtml('gateway.gateway_return.perform_payment_effect.message.dd866497c7',$support);
    } elseif ($basis==='bank_timer') {
        $text=achbotMessage('manual_payment.automatic_receipts.message.38dd599e83').$amount.
            achbotMessage('manual_payment.automatic_receipts.message.6f42cf1050');
        $mode=null;
    } else {
        $text=achbotMessage('manual_payment.manual_receipts.message.f568baa065').$amount.
            achbotMessageHtml('manual_payment.manual_receipts.message.f6331b33af',$support);
    }
    achbotFollowupStoreLocked($db,$payId,null,$stage,'customer','send',(string)$userId,null,
        $fingerprint,['text'=>$text,'keys'=>null,'mode'=>$mode]);
    $owner=(int)($GLOBALS['admin']??0);
    if ($gateway!==null) {
        $report=achbotMessage('gateway.gateway_return.perform_payment_effect.message.a5b15e454e').$amount.
            achbotMessageHtml('gateway.gateway_return.perform_payment_effect.text.3908dcee07',
                ['user_id'=>$userId,'gateType'=>$gateway]);
        achbotFollowupStageAdminReportLocked($db,$payId,null,$stage,$fingerprint,$owner,
            (string)($GLOBALS['botState']['rewardChannel']??''),['text'=>$report,'keys'=>null,'mode'=>'HTML']);
    } elseif ($basis==='manual') {
        $report=achbotMessageHtml('manual_payment.manual_receipts.admin_messages.31d90c5e69',[
            'methodName'=>achbotManualLabel((string)$a['provider']),'userId'=>$userId]).
            number_format($entry['before']).achbotMessage('manual_payment.manual_receipts.text.c0780ce268').
            number_format($entry['after']).achbotMessageHtml('manual_payment.manual_receipts.text.89a4910ebe',
                ['hash_id'=>$pay['hash_id']]);
        // The original manual top-up notifies owner/staff, not the channel.
        achbotFollowupStageAdminReportLocked($db,$payId,null,$stage,$fingerprint,$owner,'',
            ['text'=>$report,'keys'=>null,'mode'=>'HTML']);
    }
    // API/timer success edits existing receipt copies; no new staff spam.
    achbotReceiptReportStageEditsLocked($db,$source,$payId,null,$stage,$fingerprint,
        payTypeToDescription('INCREASE_WALLET'),true);
}

function achbotReceiptReportValidateLocked(mysqli $db,array $pay,array $r): ?array {
    if (isset($r['service_operation_id']) || $r['stage']!=='wallet_credit') return null;
    $source=achbotReceiptCreditEvidenceLocked($db,(int)$pay['id'],(int)$pay['user_id']);
    if ($source['attempt']['wallet_funding_result']==='funded') return null;
    $gateway=achbotReceiptReportGateway($source['attempt']);$basis=$source['attempt']['wallet_credit_basis'];
    if ($r['role']==='customer') {
        if ($r['kind']!=='send' || $r['message_id']!==null ||
            $r['recipient']!==(string)(int)$pay['user_id']) return null;
    } elseif ($r['kind']==='edit') {
        if (!achbotReceiptReportEditAllowed($db,$source,$r)) return null;
    } else {
        if (($gateway===null && $basis!=='manual') ||
            ($gateway===null && $r['role']==='service_channel') ||
            !achbotFollowupAdminReportAllowed($db,$r)) return null;
    }
    return achbotFollowupDecodePacket($pay,$r,$source['fingerprint']);
}
