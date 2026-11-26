<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/FollowupOutbox.php';
require_once __DIR__.'/../Panel/ProviderStorage.php';

/** The immutable Wallet entry is the reporting source, never today's balance. */
function achbotWalletReportEntryLocked(mysqli $db,int $id): array {
    $e=achbotProviderRow($db,'SELECT * FROM wallet_entries WHERE id=? FOR UPDATE','i',[$id]);
    if (!$e || (int)$e['id']!==$id || $id<=0 || (int)$e['userid']<=0 ||
        (int)$e['user_row_id']<=0 || (int)$e['delta_toman']===0 ||
        (int)$e['balance_after']-(int)$e['balance_before']!==(int)$e['delta_toman'])
        throw new RuntimeException('wallet_report_entry_invalid');
    return $e;
}
function achbotWalletReportDate(array $e): string {
    return (new DateTimeImmutable((string)$e['created_at'],new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone('Asia/Tehran'))->format('Y-m-d H:i:s');
}
function achbotWalletReportFacts(array $e): string {
    return achbotMessageHtml('operational_reports.wallet_facts',[
        'user'=>$e['userid'],'amount'=>number_format(abs((int)$e['delta_toman'])),
        'before'=>number_format((int)$e['balance_before']),'after'=>number_format((int)$e['balance_after']),
        'entry'=>$e['id'],'date'=>achbotWalletReportDate($e)
    ]);
}
function achbotWalletReportFingerprint(array $e): string {
    return hash('sha256',json_encode([
        (int)$e['id'],(string)$e['source_kind'],(string)$e['source_ref'],(int)$e['leg'],
        (int)$e['user_row_id'],(int)$e['userid'],(int)$e['delta_toman'],
        (int)$e['balance_before'],(int)$e['balance_after'],(int)$e['actor_userid'],(string)$e['created_at']
    ],JSON_THROW_ON_ERROR));
}

/** Standalone Wallet movements use the same outbox, attempts and review
 * lifecycle as receipt/service reports. A transfer sender is NOT an admin. */
function achbotWalletReportContextLocked(mysqli $db,array $e): array {
    $actor=0;$extra='';$pair=null;$delta=(int)$e['delta_toman'];
    switch ($e['source_kind']) {
        case 'admin_adjustment':
            if ((int)$e['leg']!==1 || (int)$e['actor_userid']<=0)
                throw new RuntimeException('wallet_report_adjustment_invalid');
            $actor=(int)$e['actor_userid'];
            $label=achbotMessage('operational_reports.source_adjustment');
            break;
        case 'transfer':
            if ((int)$e['leg']!==2 || $delta<=0 || (int)$e['actor_userid']<=0 ||
                (int)$e['actor_userid']===(int)$e['userid'])
                throw new RuntimeException('wallet_report_transfer_invalid');
            $d=achbotProviderRow($db,"SELECT * FROM wallet_entries WHERE source_kind='transfer' AND source_ref=? AND leg=1 FOR UPDATE",'s',[$e['source_ref']]);
            if (!$d || (int)$d['userid']!==(int)$e['actor_userid'] ||
                (int)$d['actor_userid']!==(int)$e['actor_userid'] || (int)$d['delta_toman']!==-$delta ||
                (int)$d['balance_after']-(int)$d['balance_before']!==-$delta)
                throw new RuntimeException('wallet_report_transfer_pair_invalid');
            $pair=achbotWalletReportFingerprint($d);
            $label=achbotMessage('operational_reports.source_transfer');
            $extra=achbotMessageHtml('operational_reports.transfer_sender',[
                'sender'=>$d['userid'],'before'=>number_format((int)$d['balance_before']),
                'after'=>number_format((int)$d['balance_after'])]);
            break;
        case 'onboarding_referral':
            if ($delta<=0 || (int)$e['leg']!==0 || (int)$e['actor_userid']!==0 ||
                !preg_match('/^([1-9][0-9]*):[1-9][0-9]*$/D',$e['source_ref'],$m))
                throw new RuntimeException('wallet_report_referral_invalid');
            $label=achbotMessage('operational_reports.source_onboarding');
            $extra=achbotMessageHtml('operational_reports.invited',['user'=>$m[1]]);
            break;
        default: throw new RuntimeException('wallet_report_source_invalid');
    }
    return ['actor'=>$actor,'label'=>$label,'extra'=>$extra,
        'fingerprint'=>hash('sha256',achbotWalletReportFingerprint($e).':'.($pair??''))];
}
function achbotWalletReportStageLocked(mysqli $db,int $id,int $owner): void {
    $e=achbotWalletReportEntryLocked($db,$id);$context=achbotWalletReportContextLocked($db,$e);
    $text=achbotMessage((int)$e['delta_toman']>0?'operational_reports.credit_title':'operational_reports.debit_title').
        "\n\n".$context['label']."\n".achbotWalletReportFacts($e)."\n".
        achbotMessageHtml('operational_reports.source_reference',['reference'=>$e['source_ref']]);
    if ($context['extra']!=='') $text.="\n".$context['extra'];
    if ($context['actor']>0)
        $text.="\n".achbotMessageHtml('operational_reports.actor',['actor'=>$context['actor']]);
    achbotFollowupStageAdminReportLocked($db,null,null,'wallet_movement',$context['fingerprint'],
        $owner,(string)($GLOBALS['botState']['rewardChannel']??''),['text'=>$text,'keys'=>null,'mode'=>'HTML'],
        $context['actor'],$id);
}
function achbotWalletReportValidateLocked(mysqli $db,array $e,array $r): ?array {
    if ($r['stage']!=='wallet_movement' || $r['pay_row_id']!==null || $r['service_operation_id']!==null ||
        (int)$r['wallet_entry_id']!==(int)$e['id']) return null;
    $context=achbotWalletReportContextLocked($db,$e);
    if (!achbotFollowupAdminReportAllowed($db,$r,$context['actor'],'operations')) return null;
    return achbotFollowupDecodePacket(null,$r,$context['fingerprint']);
}
