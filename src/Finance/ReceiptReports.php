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

/** Method is distinct from approval authority. A hosted exception can
 * be human-approved; a bank receipt can be confirmed by API or timer. */
function achbotReceiptReportMethod(array $attempt): string {
    $method=(string)$attempt['provider'];
    return achbotReceiptReportGateway($attempt)??(in_array($method,['bank_transfer','gift_card','manual_usdt','manual_trx'],true)?
        achbotManualLabel($method):$method);
}
function achbotReceiptReportBasis(array $attempt): string {
    return achbotMessage(match($attempt['wallet_credit_basis']) {
        'manual'=>'operational_reports.basis_manual',
        'api'=>'operational_reports.basis_api',
        'bank_timer'=>'operational_reports.basis_timer',
        default=>'operational_reports.basis_hosted'
    });
}

/** Report every final receipt credit, including a credit immediately used
 * by its original service. Customer top-up copy is only for retained money.
 * The sealed financial report is independent from the later Native result. */
function achbotReceiptReportStageLocked(mysqli $db,int $payId,int $userId,string $originalState): void {
    require_once __DIR__.'/WalletReports.php';
    $source=achbotReceiptCreditEvidenceLocked($db,$payId,$userId);
    $pay=$source['pay'];$a=$source['attempt'];$entry=$source['entry'];
    $funded=$a['wallet_funding_result']==='funded';
    $stage='wallet_credit';$fingerprint=$source['fingerprint'];
    $gateway=achbotReceiptReportGateway($a);$basis=$a['wallet_credit_basis'];
    $amount=number_format((int)$pay['price']);
    if (!$funded) {
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
        achbotReceiptReportStageEditsLocked($db,$source,$payId,null,$stage,$fingerprint,
            payTypeToDescription('INCREASE_WALLET'),true);
    }
    $wallet=achbotWalletReportEntryLocked($db,$entry['id']);
    $result=$a['wallet_funding_result'];
    if ($result==='request_invalidated' && $originalState==='service_superseded') $result='service_superseded';
    $orderId=(int)$pay['order_id'];
    if ($a['service_operation_id']!==null) {
        $op=achbotProviderRow($db,'SELECT subject_order_id FROM service_operations WHERE id=?','i',[(int)$a['service_operation_id']]);
        $orderId=(int)($op['subject_order_id']??$orderId);
    }
    $report=achbotMessage($funded?'operational_reports.funded_title':'operational_reports.credit_title')."\n\n".
        achbotWalletReportFacts($wallet)."\n".
        achbotMessageHtml('operational_reports.payment_context',[
            'method'=>achbotReceiptReportMethod($a),'basis'=>achbotReceiptReportBasis($a),
            'payment'=>'P'.$pay['id'].' / '.$pay['hash_id'],'service'=>$a['service_operation_id']===null?'-':'svc_'.$a['service_operation_id'],
            'order'=>$orderId?:'-',
            'result'=>achbotReceiptFundingResultLabel($result)
        ]);
    if ($entry['actor']>0)
        $report.="\n".achbotMessageHtml('operational_reports.actor',['actor'=>$entry['actor']]);
    achbotFollowupStageAdminReportLocked($db,$payId,null,$stage,$fingerprint,
        (int)($GLOBALS['admin']??0),(string)($GLOBALS['botState']['rewardChannel']??''),
        ['text'=>$report,'keys'=>null,'mode'=>'HTML'],$entry['actor']);
}
function achbotReceiptFundingResultLabel(string $result): string {
    return achbotMessage(match($result) {
        'funded'=>'operational_reports.funded',
        'wallet_only'=>'operational_reports.wallet_only',
        'already_funded'=>'operational_reports.already_funded',
        'request_invalidated'=>'operational_reports.request_invalidated',
        'service_superseded'=>'operational_reports.service_superseded',
        'capacity_unavailable'=>'operational_reports.capacity_unavailable',
        'insufficient_funds'=>'operational_reports.insufficient_funds',
    });
}

function achbotReceiptReportValidateLocked(mysqli $db,array $pay,array $r): ?array {
    if (isset($r['service_operation_id']) || $r['stage']!=='wallet_credit') return null;
    $source=achbotReceiptCreditEvidenceLocked($db,(int)$pay['id'],(int)$pay['user_id']);
    $funded=$source['attempt']['wallet_funding_result']==='funded';
    if ($r['role']==='customer') {
        if ($funded || $r['kind']!=='send' || $r['message_id']!==null ||
            $r['recipient']!==(string)(int)$pay['user_id']) return null;
    } elseif ($r['kind']==='edit') {
        if ($funded || !achbotReceiptReportEditAllowed($db,$source,$r)) return null;
    } elseif (!achbotFollowupAdminReportAllowed($db,$r,$source['entry']['actor'])) return null;
    return achbotFollowupDecodePacket($pay,$r,$source['fingerprint']);
}
