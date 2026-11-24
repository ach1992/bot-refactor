<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/FollowupOutbox.php';
require_once __DIR__.'/ServiceDelivery.php';
require_once __DIR__.'/ReceiptReports.php';
require_once __DIR__.'/../Presentation/MainMenu.php';
require_once __DIR__.'/../Purchase/BulkReport.php';
require_once __DIR__.'/../Services/EditFinalization.php';
require_once __DIR__.'/../../messages/loader.php';

/** One original automatic renewal quote and debit supply both audiences.
 * An unfunded, actually refused renewal may use the same frozen quote
 * only for its low-wallet notice; it never becomes a paid service. */
function achbotServiceAutoRenewNoticeValues(mysqli $db,array $op,array $quote,bool $refused=false): array {
    $q=$quote['request'];$auto=$q['policy']['automatic_renewal']??null;
    if (!is_array($auto) || $op['kind']!=='RENEW' ||
        !is_string($auto['cycle']??null) ||
        !is_string($auto['reference']??null) ||
        !preg_match('/^rn_[a-f0-9]{32}$/D',$auto['reference']) ||
        !is_int($auto['wallet_before']??null) || !is_int($auto['wallet_after']??null) ||
        $auto['wallet_before']-$q['price']!==$auto['wallet_after'] ||
        ($refused?($op['command_kind']!=='automatic_renewal_refusal' ||
            $op['command_ref']!==$auto['cycle'].':'.$auto['reference'] ||
            $op['stage']!=='cancelled' || $op['failure_code']!=='wallet_balance_insufficient' ||
            $q['price']<=0 || $auto['wallet_before']>=$q['price'] ||
            $op['debit_entry_id']!==null || (int)$op['reserved_count']!==0):
            ($op['command_kind']!=='automatic_renewal' ||
            $auto['cycle']!==$op['command_ref'] ||
            !in_array($op['stage'],['applied','completed'],true))))
        throw new RuntimeException('service_auto_notice_evidence');
    if (!$refused && $q['price']>0) {
        $entry=achbotProviderRow($db,'SELECT user_row_id,userid,source_kind,source_ref,leg,delta_toman,balance_before,balance_after FROM wallet_entries WHERE id=?',
            'i',[(int)$op['debit_entry_id']]);
        if (!$entry || (int)$entry['user_row_id']!==(int)$op['user_row_id'] ||
            (int)$entry['userid']!==(int)$op['userid'] || $entry['source_kind']!=='service_debit' ||
            $entry['source_ref']!==(string)$op['id'] || (int)$entry['leg']!==0 ||
            (int)$entry['delta_toman']!==-$q['price'] ||
            (int)$entry['balance_before']!==$auto['wallet_before'] ||
            (int)$entry['balance_after']!==$auto['wallet_after'])
            throw new RuntimeException('service_auto_notice_debit_conflict');
    } elseif ($op['debit_entry_id']!==null) throw new RuntimeException('service_auto_notice_unexpected_debit');
    if ($refused) return ['remark'=>$q['remark'],'amount'=>number_format($q['price']),
        'wallet'=>number_format($auto['wallet_before']),
        'shortfall'=>number_format($q['price']-$auto['wallet_before'])];
    return ['remark'=>$q['remark'],'amount'=>number_format($q['price']),
        'wallet_before'=>number_format($auto['wallet_before']),'wallet_after'=>number_format($auto['wallet_after']),
        'days'=>number_format($q['days']),
        'volume'=>$q['volume']===0?achbotMessage('renewal.automatic_renewal.unlimited_volume'):
            achbotMessage('renewal.automatic_renewal.finite_volume',['volume'=>number_format($q['volume'])]),
        'payment_ref'=>$auto['reference']];
}

/** Financial/service evidence is original and immutable, not the latest
 * order or panel state. No second source of money or service truth. */
function achbotServiceReportFingerprintLocked(mysqli $db,array $op,array $quote,string $stage): string {
    if ($stage==='auto_renewal_report') {
        achbotServiceAutoRenewNoticeValues($db,$op,$quote);
        $m=achbotServiceMutationRow($db,(int)$op['id'],true);
        if (!$m) throw new RuntimeException('service_auto_report_mutation_missing');
        $binding=achbotServiceEditDeliveryBindingLocked($db,$op,$quote,$m);
    } elseif ($stage==='auto_renewal_low_wallet') {
        achbotServiceAutoRenewNoticeValues($db,$op,$quote,true);
        $binding=['service_id'=>(int)$op['id'],'user_row_id'=>(int)$op['user_row_id'],
            'userid'=>(int)$op['userid'],'quote_sha256'=>$op['quote_sha256'],
            'stage'=>$op['stage'],'failure_code'=>$op['failure_code']];
    } else throw new RuntimeException('service_report_stage_invalid');
    return hash('sha256',achbotServiceJson(['stage'=>$stage,'binding'=>$binding]));
}

function achbotServiceAutoRenewStageReportsLocked(mysqli $db,array $op,array $quote,int $owner,string $channel): void {
    if ($owner<=0) throw new RuntimeException('service_report_owner_missing');
    $stage='auto_renewal_report';$id=(int)$op['id'];
    $fingerprint=achbotServiceReportFingerprintLocked($db,$op,$quote,$stage);
    $values=achbotServiceAutoRenewNoticeValues($db,$op,$quote);
    $content=['text'=>achbotMessageHtml('renewal.automatic_renewal.success_report',
        ['user_id'=>(string)$op['userid']]+$values)."\n".achbotMessageHtml('operational_reports.service_order',['order'=>$quote['request']['order_id']])."\n".achbotMessageHtml('operational_reports.source_reference',['reference'=>'svc_'.$id]),'keys'=>null,'mode'=>'HTML'];
    achbotFollowupStageAdminReportLocked($db,null,$id,$stage,$fingerprint,$owner,$channel,$content);
}

function achbotServiceAutoRenewStageLowWalletLocked(mysqli $db,array $op,array $quote): void {
    $stage='auto_renewal_low_wallet';
    $fingerprint=achbotServiceReportFingerprintLocked($db,$op,$quote,$stage);
    $values=achbotServiceAutoRenewNoticeValues($db,$op,$quote,true);
    $keys=json_encode(['inline_keyboard'=>[[
        ['text'=>achbotMessage('legacy_buttons.my_subscriptions'),'callback_data'=>'mySubscriptions'],
        ['text'=>achbotMessage('legacy_buttons.sharj'),'callback_data'=>'increaseMyWallet']
    ]]],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $content=['text'=>achbotMessageHtml('renewal.automatic_renewal.low_wallet',$values),'keys'=>$keys,'mode'=>'HTML'];
    achbotFollowupStoreLocked($db,null,(int)$op['id'],$stage,'service_customer','send',
        (string)(int)$op['userid'],null,$fingerprint,$content);
}

/** The original sealed 018 packets were constructed atomically with local
 * order/audit finality. Their immutable bindings prove the original result
 * even after a legitimate later plan change, deletion or delivery attempt.
 * This report never exposes config/link/token bytes or implies Telegram ACK. */
function achbotServiceCompletionReportSourceLocked(mysqli $db,array $op,array $quote,bool $verifyDelivery=false): array {
    $id=(int)$op['id'];$uid=(int)$op['userid'];$q=$quote['request'];
    $buy=$op['kind']==='BUY';$count=$buy?$q['quantity']:1;
    if (!in_array($op['stage'],['applied','completed'],true) ||
        achbotServiceFundLocked($db,$uid,$id)!=='already_funded')
        throw new RuntimeException('service_report_completion_unconfirmed');
    $receipt=achbotServiceFundingReceiptLocked($db,$op);
    $s=achbotProviderStatement($db,'SELECT * FROM payment_delivery_intents
        WHERE service_operation_id=? ORDER BY ordinal FOR UPDATE','i',[$id]);
    $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
    if (count($rows)!==$count) throw new RuntimeException('service_report_original_lines_missing');
    $lines=[];$accounts=[];$delivered=$verifyDelivery;
    foreach ($rows as $i=>$d) {
        $ordinal=$i+1;
        $body=achbotServiceDeliveryOriginalBody($op,$quote,$d,$ordinal);
        if ($buy) $accounts[]=['ordinal'=>$ordinal,'name'=>$body['binding']['remark'],
            'order_id'=>(int)$d['order_id']];
        if ($verifyDelivery && !achbotServiceDeliveryAcknowledgedLocked($db,$d,$body))
            $delivered=false;
        // Digests, never the confidential customer packet itself.
        $lines[]=[(int)$d['id'],$d['source_digest'],$d['payload_sha256']];
    }
    $fingerprint=hash('sha256',achbotServiceJson([
        'service_id'=>$id,'userid'=>$uid,'owner_row'=>(int)$op['user_row_id'],
        'quote'=>$op['quote_sha256'],'debit'=>$op['debit_entry_id']===null?null:(int)$op['debit_entry_id'],
        'receipt'=>$receipt===null?null:$receipt['fingerprint'],'lines'=>$lines]));
    return ['fingerprint'=>$fingerprint,'receipt'=>$receipt,'accounts'=>$accounts,'delivered'=>$delivered];
}

/** All funding methods share one locally completed service report.
 * Receipt edits and confirmed customer delivery are distinct events. */
function achbotServiceStageCompletionReportsLocked(mysqli $db,array $op,array $quote): void {
    if ($op['command_kind']==='automatic_renewal') return; // existing special copy
    $source=achbotServiceCompletionReportSourceLocked($db,$op,$quote);
    $q=$quote['request'];$kind=$op['kind'];$id=(int)$op['id'];$uid=(int)$op['userid'];
    $receipt=$source['receipt'];$accounts=$source['accounts'];$stage='service_completed';
    $reference=$receipt===null?'svc_'.$id:'P'.$receipt['pay']['id'].' / '.$receipt['pay']['hash_id'];
    if ($op['command_kind']==='checkout') {
        require_once __DIR__.'/CheckoutAdmission.php';
        $original=achbotProviderRow($db,'SELECT * FROM pays WHERE checkout_operation_id=? FOR UPDATE','i',[$id]);
        if (!$original) throw new RuntimeException('checkout_original_command_missing');
        achbotCheckoutOriginalCommandLocked($db,$original);
        $reference='P'.$original['id'].' / '.$original['hash_id'];
    }
    $remark=$kind!=='BUY'?$q['remark']:
        ($q['quantity']>1?achbotMessage('bulk_reporting.bulk_subject'):$accounts[0]['name']);
    $hosted=$receipt!==null && achbotReceiptIsHosted($receipt['attempt']);
    if ($receipt!==null && !$hosted) {
        $subject=match($kind) {
            'ADD_TIME'=>achbotMessage('manual_receipt_final.day_subject',['remark'=>$remark,'units'=>$q['days']]),
            'ADD_VOLUME'=>achbotMessage('manual_receipt_final.volume_subject',['remark'=>$remark,'units'=>$q['volume']]),
            default=>$remark
        };
        achbotReceiptReportStageEditsLocked($db,$receipt,null,$id,$stage,$source['fingerprint'],$subject);
        achbotServiceStageManualNoticesLocked($db,$op,$quote,$source,$remark);
    }
    $method=$receipt===null?achbotMessage('automatic_gateway_review.wallet_provider'):
        achbotReceiptReportMethod($receipt['attempt']).' | '.achbotReceiptReportBasis($receipt['attempt']);
    $text=achbotMessage(match($kind) {
        'BUY'=>'operational_reports.service_buy',
        'RENEW'=>'operational_reports.service_renew',
        'ADD_TIME'=>'operational_reports.service_days',
        'ADD_VOLUME'=>'operational_reports.service_volume'
    })."\n\n".achbotMessageHtml('operational_reports.service_context',[
        'user'=>$uid,'method'=>$method,'amount'=>number_format($q['price']),'remark'=>$remark,
        'server'=>$q['server_id'],'plan'=>$q['plan_id'],'reference'=>$reference,'service'=>$id
    ])."\n".match($kind) {
        'BUY','RENEW'=>achbotMessageHtml('operational_reports.service_units',['volume'=>$q['volume']===0?achbotMessage('operational_reports.unlimited_volume'):achbotMessage('operational_reports.finite_volume',['volume'=>number_format($q['volume'])]),'days'=>number_format($q['days'])]),
        'ADD_TIME'=>achbotMessageHtml('operational_reports.service_added_days',['days'=>$q['days']]),
        'ADD_VOLUME'=>achbotMessageHtml('operational_reports.service_added_volume',['volume'=>$q['volume']])
    };
    if ($kind!=='BUY' || $q['quantity']===1)
        $text.="\n".achbotMessageHtml('operational_reports.service_order',
            ['order'=>$kind==='BUY'?$accounts[0]['order_id']:$q['order_id']]);
    if ($op['debit_entry_id']!==null) {
        require_once __DIR__.'/WalletReports.php';
        $debit=achbotWalletReportEntryLocked($db,(int)$op['debit_entry_id']);
        $text.="\n".achbotMessageHtml('operational_reports.service_wallet',[
            'entry'=>$debit['id'],'before'=>number_format((int)$debit['balance_before']),
            'after'=>number_format((int)$debit['balance_after'])]);
    }
    $actor=$receipt===null?0:$receipt['entry']['actor'];
    if ($actor>0) $text.="\n".achbotMessageHtml('operational_reports.actor',['actor'=>$actor]);
    if ($kind==='BUY' && $q['quantity']>1)
        $text=achbotBulkReportAppend($text,['requested'=>$q['quantity'],'confirmed'=>count($accounts),
            'complete'=>true,'accounts'=>$accounts,'reference'=>$reference],true);
    achbotFollowupStageAdminReportLocked($db,null,$id,$stage,$source['fingerprint'],
        (int)($GLOBALS['admin']??0),(string)($GLOBALS['botState']['rewardChannel']??''),
        ['text'=>$text,'keys'=>null,'mode'=>'HTML'],$actor);
}

/** The original human approval has its existing success notice. A BUY
 * message that says "sent" is staged with local completion but cannot
 * dispatch until every original customer message has acknowledged. A
 * renewal message asserts completed service, not Telegram delivery. */
function achbotServiceManualNoticeActor(array $source): ?int {
    $receipt=$source['receipt'];
    if ($receipt===null || $receipt['attempt']['wallet_credit_basis']!=='manual' ||
        achbotReceiptIsHosted($receipt['attempt'])) return null;
    $actor=$receipt['entry']['actor'];
    return $actor>0?$actor:null;
}
function achbotServiceStageManualNoticesLocked(mysqli $db,array $op,array $quote,array $source,string $remark): void {
    $actor=achbotServiceManualNoticeActor($source);$kind=$op['kind'];
    if ($actor===null || !in_array($kind,['BUY','RENEW'],true)) return;
    $q=$quote['request'];$id=(int)$op['id'];$owner=(int)($GLOBALS['admin']??0);
    $hash=(string)$source['receipt']['pay']['hash_id'];
    $stage=$kind==='BUY'?'service_delivered':'service_completed';
    $notice=strtr(achbotMessage($kind==='BUY'?'legacy_messages.sent_config_to_user':
        'legacy_messages.renewed_config_to_user'),[
        'USERID'=>(string)$op['userid'],'REMARK'=>$remark,'VOLUME'=>(string)$q['volume'],
        'DAYS'=>(string)$q['days'],'PRICE'=>(string)$q['price'],'HASHID'=>$hash]);
    $bulk=$kind==='BUY' && $q['quantity']>1;
    if ($bulk) $notice=achbotBulkReportAppend($notice,['requested'=>$q['quantity'],
        'confirmed'=>count($source['accounts']),'complete'=>true,'accounts'=>$source['accounts'],
        'reference'=>'#'.(int)$source['receipt']['pay']['id']]);
    $keys=achbotMainMenuForActor($db,$actor,$owner,$GLOBALS['botState']??[]);
    if ($keys===null) achbotDiagnosticEvent('achbot.delivery.actor_menu_unavailable',[
        'service_operation_id'=>$id,'actor_id'=>$actor,'reason'=>'service_report_actor_profile_missing'],'warning');
    $content=['text'=>$notice,'keys'=>$keys,'mode'=>null];
    achbotFollowupStoreLocked($db,null,$id,$stage,'operator','send',(string)$actor,null,
        $source['fingerprint'],$content);
    if (!$bulk) return;
    $content['keys']=null;
    if ($owner!==$actor)
        achbotFollowupStoreLocked($db,null,$id,$stage,'purchase_owner','send',(string)$owner,null,
            $source['fingerprint'],$content);
    $channel=achbotFollowupReportChannel((string)($GLOBALS['botState']['rewardChannel']??''),$stage,null,$id);
    if ($channel!=='' && $channel!==(string)$actor && $channel!==(string)$owner)
        achbotFollowupStoreLocked($db,null,$id,$stage,'purchase_channel','send',$channel,null,
            $source['fingerprint'],$content);
}

function achbotServiceManualNoticeAllowedLocked(mysqli $db,array $op,array $quote,array $source,array $r): bool {
    $actor=achbotServiceManualNoticeActor($source);$owner=(int)($GLOBALS['admin']??0);
    if ($actor===null || $owner<=0 || $r['kind']!=='send' || $r['message_id']!==null) return false;
    $buy=$op['kind']==='BUY';$recipient=(string)$r['recipient'];
    if ($r['stage']!==($buy?'service_delivered':'service_completed') ||
        !in_array($op['kind'],['BUY','RENEW'],true)) return false;
    if ($r['role']==='operator')
        return $recipient===(string)$actor && achbotAdminCanLocked($db,$actor,$owner,'payments');
    if (!$buy || $quote['request']['quantity']<=1) return false;
    if ($r['role']==='purchase_owner') return $owner!==$actor && $recipient===(string)$owner;
    if ($r['role']==='purchase_channel') {
        $channel=achbotFollowupReportChannel((string)($GLOBALS['botState']['rewardChannel']??''));
        return $channel!=='' && $recipient===$channel && $channel!==(string)$actor &&
            $channel!==(string)$owner;
    }
    return false;
}

/** A terminal operator decision is not a provider success assertion. The
 * original debit and compensating credit are independently verifiable. */
function achbotServiceResolutionEvidenceLocked(mysqli $db,array $op,array $quote): array {
    $id=(int)$op['id'];$q=$quote['request'];$uid=(int)$op['userid'];
    $kind=$op['resolution_kind'];$count=(int)$op['cancelled_count'];
    $retained=$op['kind']==='BUY'?$q['quantity']-$count:0;
    if (!in_array($kind,['closed','refunded'],true) || (int)$op['resolved_by']<=0 ||
        !$op['resolved_at'] || !in_array($op['stage'],['completed','cancelled'],true) ||
        ($kind==='closed' && ($count!==0 || $op['kind']==='BUY')) ||
        ($kind==='refunded' && ($count<1 || $count>$q['quantity'])) ||
        ($op['stage']==='completed')!==($op['kind']==='BUY' && $retained>0))
        throw new RuntimeException('service_resolution_evidence_invalid');
    $owner=achbotWalletLockOwnerLocked($db,$uid);
    achbotServiceVerifyFundingLocked($db,$op,$owner);
    $amount=$kind==='closed'?0:($op['kind']==='BUY'?
        $q['price']-intdiv($q['price'],$q['quantity'])*$retained-min($retained,$q['price']%$q['quantity']):$q['price']);
    if ($amount>0) {
        $entry=achbotWalletFindEntryLocked($db,$owner['id'],$uid,'service_refund',(string)$id,0,$amount);
        if (!$entry || $entry['id']!==(int)$op['refund_entry_id'] || $entry['actor']!==(int)$op['resolved_by'])
            throw new RuntimeException('service_resolution_refund_evidence');
    } elseif ($op['refund_entry_id']!==null) throw new RuntimeException('service_resolution_unexpected_credit');
    $fingerprint=hash('sha256',achbotServiceJson([
        'id'=>$id,'owner'=>$uid,'owner_row'=>(int)$op['user_row_id'],'quote'=>$op['quote_sha256'],
        'debit'=>$op['debit_entry_id'],'refund'=>$op['refund_entry_id'],'resolution'=>$kind,
        'actor'=>$op['resolved_by'],'at'=>$op['resolved_at'],'cancelled'=>$count]));
    return ['fingerprint'=>$fingerprint,'amount'=>$amount,'retained'=>$retained,'cancelled'=>$count];
}
function achbotServiceStageResolutionReportsLocked(mysqli $db,array $op,array $quote,int $primary): void {
    $e=achbotServiceResolutionEvidenceLocked($db,$op,$quote);
    $stage='service_resolution';$id=(int)$op['id'];
    $values=['id'=>$id,'amount'=>number_format($e['amount']),'retained'=>$e['retained'],'cancelled'=>$e['cancelled']];
    $text=$op['resolution_kind']==='closed'?
        achbotMessageHtml('automatic_gateway_review.resolution_closed_notice',['id'=>$id]):
        ($op['kind']==='BUY'?
            achbotMessageHtml('automatic_gateway_review.resolution_buy_notice',$values):
            achbotMessageHtml('automatic_gateway_review.resolution_refunded_notice',
                ['id'=>$id,'amount'=>$values['amount']]));
    $content=['text'=>$text,'keys'=>null,'mode'=>'HTML'];
    achbotFollowupStoreLocked($db,null,$id,$stage,'service_customer','send',
        (string)$op['userid'],null,$e['fingerprint'],$content);
    $content['text'].="\n".achbotMessageHtml('automatic_gateway_review.resolution_admin_context',[
        'user'=>$op['userid'],'actor'=>$op['resolved_by']]);
    $q=$quote['request'];
    $content['text'].="\n".achbotMessageHtml('operational_reports.source_reference',['reference'=>'svc_'.$id]);
    if ($op['kind']!=='BUY')
        $content['text'].="\n".achbotMessageHtml('operational_reports.resolution_subject',['remark'=>$q['remark'],'order'=>$q['order_id']]);
    if ($e['amount']>0) {
        require_once __DIR__.'/WalletReports.php';
        $content['text'].="\n".achbotWalletReportFacts(achbotWalletReportEntryLocked($db,(int)$op['refund_entry_id']));
    }
    if ($op['kind']==='BUY' && $e['retained']>0) {
        $s=achbotProviderStatement($db,'SELECT * FROM payment_delivery_intents WHERE service_operation_id=? ORDER BY ordinal FOR UPDATE','i',[$id]);
        $lines=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
        if (count($lines)!==$e['retained']) throw new RuntimeException('service_resolution_retained_lines_missing');
        $accounts=[];
        foreach ($lines as $i=>$d) {
            $body=achbotServiceDeliveryOriginalBody($op,$quote,$d,$i+1);
            $accounts[]=['ordinal'=>$i+1,'name'=>$body['binding']['remark'],'order_id'=>(int)$d['order_id']];
        }
        $content['text']=achbotBulkReportAppend($content['text'],['requested'=>$q['quantity'],'confirmed'=>$e['retained'],
            'complete'=>false,'resolved'=>true,'accounts'=>$accounts,'reference'=>'svc_'.$id],true);
    }
    achbotFollowupStageAdminReportLocked($db,null,$id,$stage,$e['fingerprint'],$primary,
        (string)($GLOBALS['botState']['rewardChannel']??''),$content,(int)$op['resolved_by']);
}

function achbotServiceReportValidateLocked(mysqli $db,array $op,array $quote,array $r): ?array {
    if ($r['pay_row_id']!==null || (int)$r['service_operation_id']!==(int)$op['id']) return null;
    if ($r['stage']==='service_resolution') {
        if ($r['kind']!=='send' || $r['message_id']!==null) return null;
        if ($r['role']==='service_customer') {
            if ((string)$r['recipient']!==(string)$op['userid']) return null;
        } elseif (!achbotFollowupAdminReportAllowed($db,$r,(int)$op['resolved_by'])) return null;
        $e=achbotServiceResolutionEvidenceLocked($db,$op,$quote);
        return achbotFollowupDecodePacket(null,$r,$e['fingerprint']);
    }
    if (in_array($r['stage'],['service_completed','service_delivered'],true)) {
        if ($op['stage']!=='completed' || $op['command_kind']==='automatic_renewal') return null;
        $requiresAck=$r['stage']==='service_delivered';
        $source=achbotServiceCompletionReportSourceLocked($db,$op,$quote,$requiresAck);
        $receipt=$source['receipt'];
        $manual=$receipt!==null && !achbotReceiptIsHosted($receipt['attempt']);
        if (in_array($r['role'],['operator','purchase_owner','purchase_channel'],true)) {
            if (!$manual || !achbotServiceManualNoticeAllowedLocked($db,$op,$quote,$source,$r)) return null;
            if ($requiresAck && !$source['delivered'])
                throw new RuntimeException('service_delivery_not_ready');
        } elseif ($requiresAck) return null;
        elseif ($r['kind']==='edit') {
            if (!$manual || !achbotReceiptReportEditAllowed($db,$receipt,$r)) return null;
        } elseif (!achbotFollowupAdminReportAllowed($db,$r,$receipt===null?0:$receipt['entry']['actor'])) return null;
        return achbotFollowupDecodePacket(null,$r,$source['fingerprint']);
    }
    if ($r['kind']!=='send' || $r['message_id']!==null) return null;
    $recipient=(string)$r['recipient'];$owner=(int)($GLOBALS['admin']??0);
    if ($r['stage']==='auto_renewal_report') {
        if ($op['stage']!=='completed') return null;
        if ($r['role']==='service_admin') {
            if ($owner<=0 || !achbotAdminNotificationAllowed($db,(int)$recipient,$owner,'general')) return null;
        } elseif ($r['role']==='service_channel') {
            $channel=achbotFollowupReportChannel((string)($GLOBALS['botState']['rewardChannel']??''));
            if ($channel==='' || $channel!==$recipient) return null;
        } else return null;
    } elseif ($r['stage']==='auto_renewal_low_wallet') {
        if ($r['role']!=='service_customer' || $recipient!==(string)(int)$op['userid']) return null;
    } else return null;
    $fingerprint=achbotServiceReportFingerprintLocked($db,$op,$quote,$r['stage']);
    return achbotFollowupDecodePacket(null,$r,$fingerprint);
}
