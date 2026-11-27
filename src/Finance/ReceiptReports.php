<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/PaymentCredit.php';
require_once __DIR__.'/FollowupOutbox.php';
require_once __DIR__.'/ReceiptHelpers.php';
require_once __DIR__.'/ApiPaymentPresentation.php';
require_once __DIR__.'/ReceiptNotices.php';

/** Presentation follows registered method capability, not provider names. */
function achbotReceiptIsHosted(array $attempt): bool {
    return achbotCreditMethodHas((string)$attempt['provider'],'hosted');
}

/** The retained receipt describes the original financial decision. The
 * separate logical service report owns later Native completion/delivery.
 * Both early and late receipt ACKs use the same stable terminal markup. */
function achbotReceiptCreditMarkupLocked(mysqli $db,array $source): string {
    $pay=$source['pay'];$a=$source['attempt'];
    $display=$pay;$display['approved_by']=$source['entry']['actor'];
    $funded=$a['wallet_funding_result']==='funded';
    $subject=payTypeToDescription('INCREASE_WALLET');
    if ($funded) {
        require_once __DIR__.'/../Services/Funding.php';
        [$op,$quote]=achbotServiceReadLocked($db,(int)$pay['user_id'],(int)$a['service_operation_id']);
        $q=$quote['request'];
        $remark=(string)($q['remark']??'');
        if ($remark==='') $remark=payTypeToDescription((string)$pay['type']);
        $subject=match($op['kind']) {
            'BUY'=>$q['quantity']>1?achbotMessage('bulk_reporting.bulk_subject'):$remark,
            'ADD_TIME'=>achbotMessage('manual_receipt_final.day_subject',['remark'=>$remark,'units'=>$q['days']]),
            'ADD_VOLUME'=>achbotMessage('manual_receipt_final.volume_subject',['remark'=>$remark,'units'=>$q['volume']]),
            default=>$remark
        };
    }
    return achbotApiPaymentFinalKeyboard($display,achbotMessage('manual_payment.api_payment.auto_approved'),
        $a['wallet_credit_basis']==='api',['subject'=>$subject],!$funded);
}
function achbotReceiptRejectedMarkup(array $pay): string {
    return inlineKeyboard([
        [['text'=>achbotMessage('manual_payment.manual_receipts.button.51fdc91d69',
            ['from_id'=>(int)$pay['approved_by']]),'callback_data'=>'wizwizch']],
        [['text'=>achbotMessage('manual_payment.manual_approval.manual_standard_report_delivery.hash_id.2899228604',
            ['hash_id'=>$pay['hash_id']]),'callback_data'=>'noop_'.$pay['hash_id']]]
    ]);
}
function achbotReceiptTerminalMarkupLocked(mysqli $db,array $pay,array $a): ?string {
    if ($pay['state']==='paid') {
        $source=achbotReceiptCreditEvidenceLocked($db,(int)$pay['id'],(int)$pay['user_id']);
        return achbotReceiptCreditMarkupLocked($db,$source);
    }
    if ($pay['state']==='declined' && $a['stage']==='done' &&
        $a['provider_status']==='operator_rejected' && (int)$pay['approved_by']>0)
        return achbotReceiptRejectedMarkup($pay);
    return null;
}
function achbotReceiptReportEditAllowed(mysqli $db,array $source,array $r): bool {
    return !achbotReceiptIsHosted($source['attempt']) && $r['kind']==='edit' &&
        in_array($r['role'],['receipt_primary','receipt_mirror'],true) &&
        achbotReceiptTargetAllowed($db,$source['pay'],$r) &&
        achbotFollowupStaffAllowed($db,(int)$r['recipient'],(int)($GLOBALS['admin']??0));
}
/** One exact edit for every proven receipt ACK, including ACKs arriving
 * after the decision. Do not hold a recipient lock across transport. */
function achbotReceiptStageEditsLocked(mysqli $db,array $pay,string $stage,string $fingerprint,
    string $keys,?array $targets=null): void {
    $owner=(int)($GLOBALS['admin']??0);
    foreach ($targets??achbotReceiptMessageTargets($db,$pay,true) as $target) {
        $chat=(int)$target['chat_id'];$message=(int)$target['message_id'];$role=$target['role'];
        if ($chat<=0 || $message<=0) continue;
        $key=achbotFollowupIntentKey($stage,$role,'edit',(string)$chat,$message);
        $existing=achbotProviderRow($db,'SELECT pay_fingerprint FROM payment_followup_intents
            WHERE pay_row_id=? AND intent_key=?','is',[(int)$pay['id'],$key]);
        if ($existing) {
            if (!hash_equals($existing['pay_fingerprint'],$fingerprint))
                throw new RuntimeException('receipt_terminal_intent_conflict');
            continue;
        }
        // The exact proven original target is the staging input; current
        // role authorization is independently enforced by dispatch.
        achbotFollowupStoreLocked($db,(int)$pay['id'],null,$stage,$role,'edit',
            (string)$chat,$message,$fingerprint,['text'=>$keys,'keys'=>null,'mode'=>null]);
    }
}
function achbotReceiptStageTerminalEditsLocked(mysqli $db,array $pay,array $a,?array $targets=null): void {
    if (achbotReceiptIsHosted($a)) return;
    if ($pay['state']==='paid') {
        $source=achbotReceiptCreditEvidenceLocked($db,(int)$pay['id'],(int)$pay['user_id']);
        achbotReceiptStageEditsLocked($db,$pay,'wallet_credit',$source['fingerprint'],
            achbotReceiptCreditMarkupLocked($db,$source),$targets);
    } elseif ($pay['state']==='declined' && $a['stage']==='done' &&
        $a['provider_status']==='operator_rejected' && (int)$pay['approved_by']>0) {
        achbotReceiptStageEditsLocked($db,$pay,'manual_reject',achbotPaymentEvidencePayFingerprint($pay),
            achbotReceiptRejectedMarkup($pay),$targets);
    }
}

/** Method is distinct from approval authority. A hosted exception can
 * be human-approved; a bank receipt can be confirmed by API or staff. */
function achbotReceiptReportMethod(array $attempt): string {
    return achbotCreditMethodLabel((string)$attempt['provider']);
}
function achbotReceiptReportBasis(array $attempt): string {
    return achbotMessage(match($attempt['wallet_credit_basis']) {
        'manual'=>'operational_reports.basis_manual',
        'api'=>'operational_reports.basis_api',
        default=>'operational_reports.basis_hosted'
    });
}

/** A funded receipt joins its original service report. Only money retained
 * in Wallet has an independent credit report and customer top-up notice. */
function achbotReceiptReportStageLocked(mysqli $db,int $payId,int $userId,string $originalState): void {
    require_once __DIR__.'/WalletReports.php';
    $source=achbotReceiptCreditEvidenceLocked($db,$payId,$userId);
    $pay=$source['pay'];$a=$source['attempt'];$entry=$source['entry'];
    $funded=$a['wallet_funding_result']==='funded';
    $stage='wallet_credit';$fingerprint=$source['fingerprint'];
    if (!achbotReceiptIsHosted($a))
        achbotReceiptStageEditsLocked($db,$pay,$stage,$fingerprint,achbotReceiptCreditMarkupLocked($db,$source));
    if ($funded) {
        require_once __DIR__.'/ServiceReports.php';
        [$op,$quote]=achbotServiceReadLocked($db,$userId,(int)$a['service_operation_id']);
        achbotServiceStageFundingReportLocked($db,$op,$quote,$source);
        return;
    }
    $hosted=achbotReceiptIsHosted($a);
    $amount=number_format((int)$pay['price']);
    $support=['supportID'=>(string)($GLOBALS['supportID']??'')];
    $mode='HTML';
    if ($hosted) {
        $text=achbotMessage('gateway.gateway_return.perform_payment_effect.message.a5b15e454e').$amount.
            achbotMessageHtml('gateway.gateway_return.perform_payment_effect.message.dd866497c7',$support);
    } else {
        $text=achbotMessage('manual_payment.manual_receipts.message.f568baa065').$amount.
            achbotMessageHtml('manual_payment.manual_receipts.message.f6331b33af',$support);
    }
    achbotFollowupStoreLocked($db,$payId,null,$stage,'customer','send',(string)$userId,null,
        $fingerprint,['text'=>$text,'keys'=>null,'mode'=>$mode]);
    $wallet=achbotWalletReportEntryLocked($db,$entry['id']);
    $result=$a['wallet_funding_result'];
    if ($result==='request_invalidated' && $originalState==='service_superseded') $result='service_superseded';
    $orderId=(int)$pay['order_id'];
    if ($a['service_operation_id']!==null) {
        $op=achbotProviderRow($db,'SELECT subject_order_id FROM service_operations WHERE id=?','i',[(int)$a['service_operation_id']]);
        $orderId=(int)($op['subject_order_id']??$orderId);
    }
    $report=achbotMessage('operational_reports.credit_title')."\n\n".
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
    if ($source['attempt']['wallet_funding_result']==='funded' && $r['kind']!=='edit') return null;
    if ($r['role']==='customer') {
        if ($r['kind']!=='send' || $r['message_id']!==null ||
            $r['recipient']!==(string)(int)$pay['user_id']) return null;
    } elseif ($r['kind']==='edit') {
        if (!achbotReceiptReportEditAllowed($db,$source,$r)) return null;
    } elseif (!achbotFollowupAdminReportAllowed($db,$r,$source['entry']['actor'])) return null;
    return achbotFollowupDecodePacket($pay,$r,$source['fingerprint']);
}
