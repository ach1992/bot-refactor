<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/FollowupOutbox.php';
require_once __DIR__.'/ServiceDelivery.php';
require_once __DIR__.'/ReceiptReports.php';
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
        ['user_id'=>(string)$op['userid']]+$values),'keys'=>null,'mode'=>'HTML'];
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
function achbotServiceCompletionReportSourceLocked(mysqli $db,array $op,array $quote): array {
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
    $lines=[];$accounts=[];
    foreach ($rows as $i=>$d) {
        $ordinal=$i+1;$effect=$d['effect_id']===null?null:(int)$d['effect_id'];$order=(int)$d['order_id'];
        if ((int)$d['ordinal']!==$ordinal || (int)$d['user_id']!==$uid ||
            (int)$d['server_id']!==$q['server_id'] || $order<=0 ||
            ($buy?($effect===null || $effect<=0):($effect!==null || $order!==$q['order_id'])) ||
            $d['pay_row_id']!==null || $d['attempt_id']!==null ||
            $d['pay_type']!==null || $d['pay_fingerprint']!==null ||
            !hash_equals($d['payload_sha256'],hash('sha256',$d['sealed_payload'])))
            throw new RuntimeException('service_report_original_line_conflict');
        $body=achbotPaymentEvidenceUnprotect(achbotServiceDeliveryPurpose(
            $id,$ordinal,$effect??0,$order),$d['sealed_payload']);
        $b=$body['binding']??null;$packet=$body['packet']??null;
        if (!is_array($b) || !is_array($packet) ||
            ($b['v']??null)!==2 || ($b['service_id']??null)!==$id ||
            ($b['quote_sha256']??null)!==$op['quote_sha256'] ||
            ($b['ordinal']??null)!==$ordinal || ($b['user_id']??null)!==$uid ||
            !array_key_exists('effect_id',$b) || $b['effect_id']!==$effect ||
            ($b['order_id']??null)!==$order || ($b['server_id']??null)!==$q['server_id'] ||
            !array_key_exists('native_operation',$b) || $b['native_operation']!==$d['native_operation'] ||
            !array_key_exists('native_target_key',$b) || $b['native_target_key']!==$d['native_target_key'] ||
            !hash_equals($d['source_digest'],hash('sha256',achbotServiceJson($b))) ||
            !($buy?achbotServiceBuyPacketValid($packet,$uid):achbotServiceTextPacketValid($packet,$uid)))
            throw new RuntimeException('service_report_original_binding_conflict');
        if ($buy) {
            if (!is_string($b['remark']??null) || $b['remark']==='' ||
                $b['native_target_key']!==$quote['target']['physical'])
                throw new RuntimeException('service_report_original_account_conflict');
            $accounts[]=['ordinal'=>$ordinal,'name'=>$b['remark'],'order_id'=>$order];
        }
        // Digests, never the confidential customer packet itself.
        $lines[]=[(int)$d['id'],$d['source_digest'],$d['payload_sha256']];
    }
    $fingerprint=hash('sha256',achbotServiceJson([
        'service_id'=>$id,'userid'=>$uid,'owner_row'=>(int)$op['user_row_id'],
        'quote'=>$op['quote_sha256'],'debit'=>$op['debit_entry_id']===null?null:(int)$op['debit_entry_id'],
        'receipt'=>$receipt===null?null:$receipt['fingerprint'],'lines'=>$lines]));
    return ['fingerprint'=>$fingerprint,'receipt'=>$receipt,'accounts'=>$accounts];
}

/** Existing Wallet/hosted reports from one source-neutral completion. The
 * original manual receipt cards are edited rather than inventing a new
 * unsolicited report for an API/timer approval. */
function achbotServiceStageCompletionReportsLocked(mysqli $db,array $op,array $quote): void {
    if ($op['command_kind']==='automatic_renewal') return; // existing special copy
    $source=achbotServiceCompletionReportSourceLocked($db,$op,$quote);
    $q=$quote['request'];$kind=$op['kind'];$id=(int)$op['id'];$uid=(int)$op['userid'];
    $receipt=$source['receipt'];$accounts=$source['accounts'];$stage='service_completed';
    $reference=$receipt===null?'svc_'.$id:(string)$receipt['pay']['hash_id'];
    $remark=$kind!=='BUY'?$q['remark']:
        ($q['quantity']>1?achbotMessage('bulk_reporting.bulk_subject'):$accounts[0]['name']);
    $gateway=$receipt===null?null:achbotReceiptReportGateway($receipt['attempt']);
    if ($receipt!==null && $gateway===null) {
        $subject=match($kind) {
            'ADD_TIME'=>achbotMessage('manual_receipt_final.day_subject',['remark'=>$remark,'units'=>$q['days']]),
            'ADD_VOLUME'=>achbotMessage('manual_receipt_final.volume_subject',['remark'=>$remark,'units'=>$q['volume']]),
            default=>$remark
        };
        achbotReceiptReportStageEditsLocked($db,$receipt,null,$id,$stage,$source['fingerprint'],$subject);
        return;
    }
    $u=achbotProviderRow($db,'SELECT name,username FROM users WHERE id=? AND userid=? FOR UPDATE',
        'ii',[(int)$op['user_row_id'],$uid]);
    if (!$u) throw new RuntimeException('service_report_buyer_missing');
    $values=['user_id'=>$uid,'first_name'=>(string)$u['name'],'username'=>(string)$u['username'],
        'amount'=>$q['price'],'remark'=>$remark];
    if ($gateway!==null) {
        $text=match($kind) {
            'BUY'=>achbotMessageHtml('gateway.gateway_return.perform_payment_effect.msg.8205ac68fa',$values+
                ['gateType'=>$gateway,'volume'=>$q['volume'],'days'=>$q['days']]),
            'RENEW'=>achbotMessageHtml('gateway.gateway_return.perform_payment_effect.msg.c281132242',$values+
                ['gateType'=>$gateway,'volume'=>$q['volume'],'days'=>$q['days']]),
            'ADD_TIME'=>achbotMessageHtml('gateway.gateway_return.perform_payment_effect.msg.3337e72606',$values+
                ['gateType'=>$gateway,'plusDays'=>$q['days']]),
            'ADD_VOLUME'=>achbotMessageHtml('gateway.gateway_return.perform_payment_effect.msg.05d5688689',$values+
                ['gateType'=>$gateway,'plusVolume'=>$q['volume']]),
        };
        $label=achbotMessage('gateway.gateway_return.perform_payment_effect.button.b9d052f74c',['gateType'=>$gateway]);
        $refLabel=achbotMessage('gateway.gateway_return.perform_payment_effect.hash_id.2899228604',['hash_id'=>$reference]);
    } else {
        if (in_array($kind,['BUY','RENEW'],true)) {
            $template=$kind==='RENEW'?'renew_account_request_message':
                ($q['variant']==='custom'?'buy_custom_account_request':'buy_new_account_request');
            $esc=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
            $text=strtr(achbotMessage('legacy_messages.'.$template),[
                'USER-ID'=>(string)$uid,'NAME'=>$esc($u['name']),'USERNAME'=>$esc($u['username']),
                'PRICE'=>(string)$q['price'],'REMARK'=>$esc($remark),'VOLUME'=>(string)$q['volume'],
                'DAYS'=>(string)$q['days'],'SERVERNAME'=>'#'.$q['server_id'],
                'TYPE'=>achbotMessage('payment.payment_lifecycle.pay_ux_audience_methods.text.6295c35212')]);
        } else {
            $text=achbotMessageHtml($kind==='ADD_TIME'?'renewal.extensions.msg.bfc1b46322':
                'renewal.extensions.msg.0e12e9ce3d',[
                'from_id'=>$uid,'first_name'=>$u['name'],'username'=>$u['username'],
                'remark'=>$remark,'volume'=>$kind==='ADD_TIME'?$q['days']:$q['volume'],'price'=>$q['price']]);
        }
        $label=achbotMessage(match($kind) {
            'BUY'=>'payment.checkout.button.e401f3d5a2','RENEW'=>'renewal.renewal_checkout.button.7deb81ef97',
            'ADD_TIME'=>'renewal.extensions.button.aba3054570','ADD_VOLUME'=>'renewal.extensions.button.9a3cb8176f'});
        $refLabel=achbotMessage('renewal.extensions.hash_id.2899228604',['hash_id'=>$reference]);
    }
    if ($kind==='BUY' && $q['quantity']>1)
        $text=achbotBulkReportAppend($text,['requested'=>$q['quantity'],'confirmed'=>count($accounts),
            'complete'=>true,'accounts'=>$accounts,'reference'=>$reference],true);
    // Only the real receipt hash or actual service ID, never a fake pays row.
    $keys=inlineKeyboard([[['text'=>$label,'callback_data'=>'wizwizch']],
        [['text'=>$refLabel,'callback_data'=>'noop_'.$reference]]]);
    achbotFollowupStageAdminReportLocked($db,null,$id,$stage,$source['fingerprint'],
        (int)($GLOBALS['admin']??0),(string)($GLOBALS['botState']['rewardChannel']??''),
        ['text'=>$text,'keys'=>$keys,'mode'=>'HTML']);
}

function achbotServiceReportValidateLocked(mysqli $db,array $op,array $quote,array $r): ?array {
    if ($r['pay_row_id']!==null || (int)$r['service_operation_id']!==(int)$op['id']) return null;
    if ($r['stage']==='service_completed') {
        if ($op['stage']!=='completed' || $op['command_kind']==='automatic_renewal') return null;
        $source=achbotServiceCompletionReportSourceLocked($db,$op,$quote);
        $receipt=$source['receipt'];
        $manual=$receipt!==null && achbotReceiptReportGateway($receipt['attempt'])===null;
        if ($manual?!achbotReceiptReportEditAllowed($db,$receipt,$r):
            !achbotFollowupAdminReportAllowed($db,$r)) return null;
        return achbotFollowupDecodePacket(null,$r,$source['fingerprint']);
    }
    if ($r['kind']!=='send' || $r['message_id']!==null) return null;
    $recipient=(string)$r['recipient'];$owner=(int)($GLOBALS['admin']??0);
    if ($r['stage']==='auto_renewal_report') {
        if ($op['stage']!=='completed') return null;
        if ($r['role']==='service_admin') {
            if ($owner<=0 || !achbotAdminNotificationAllowed($db,(int)$recipient,$owner,'general')) return null;
        } elseif ($r['role']==='service_channel') {
            $channel=(string)($GLOBALS['botState']['rewardChannel']??'');
            if ($channel==='' || $channel!==$recipient) return null;
        } else return null;
    } elseif ($r['stage']==='auto_renewal_low_wallet') {
        if ($r['role']!=='service_customer' || $recipient!==(string)(int)$op['userid']) return null;
    } else return null;
    $fingerprint=achbotServiceReportFingerprintLocked($db,$op,$quote,$r['stage']);
    return achbotFollowupDecodePacket(null,$r,$fingerprint);
}
