<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/FollowupOutbox.php';
require_once __DIR__.'/ServiceDelivery.php';
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
    $complete=false;$staff=achbotAdminRecipientCandidates($db,$owner,$complete);
    if (!$complete) throw new RuntimeException('service_report_staff_unavailable');
    $targets=[(string)$owner=>true];
    foreach ($staff as $uid) {
        $complete=true;
        if (achbotAdminNotificationAllowed($db,$uid,$owner,'general',$complete))
            $targets[(string)(int)$uid]=true;
        if (!$complete) throw new RuntimeException('service_report_permission_unavailable');
    }
    foreach ($targets as $recipient=>$_)
        achbotFollowupStoreLocked($db,null,$id,$stage,'service_admin','send',(string)$recipient,null,$fingerprint,$content);
    if ($channel!=='' && !isset($targets[$channel]))
        achbotFollowupStoreLocked($db,null,$id,$stage,'service_channel','send',$channel,null,$fingerprint,$content);
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

function achbotServiceReportValidateLocked(mysqli $db,array $op,array $quote,array $r): ?array {
    if ($r['pay_row_id']!==null || (int)$r['service_operation_id']!==(int)$op['id'] ||
        $r['kind']!=='send' || $r['message_id']!==null) return null;
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
