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

/** Immutable facts common to funded, completed and resolved report revisions.
 * The actual original receipt, never a late Wallet-only credit, supplies
 * the approval actor and credit facts. This function performs no transport. */
function achbotServiceReportFundingLocked(mysqli $db,array $op,array $quote): array {
    $id=(int)$op['id'];$uid=(int)$op['userid'];
    $owner=achbotWalletLockOwnerLocked($db,$uid);
    achbotServiceVerifyFundingLocked($db,$op,$owner);
    if ($op['stage']==='awaiting_funds') throw new RuntimeException('service_report_unfunded');
    $receipt=achbotServiceFundingReceiptLocked($db,$op);
    $reference=$receipt===null?'svc_'.$id:'P'.$receipt['pay']['id'].' / '.$receipt['pay']['hash_id'];
    if ($op['command_kind']==='checkout') {
        require_once __DIR__.'/CheckoutAdmission.php';
        $original=achbotProviderRow($db,'SELECT * FROM pays WHERE checkout_operation_id=? FOR UPDATE','i',[$id]);
        if (!$original) throw new RuntimeException('checkout_original_command_missing');
        achbotCheckoutOriginalCommandLocked($db,$original);
        if ($receipt===null) $reference='P'.$original['id'].' / '.$original['hash_id'];
    }
    require_once __DIR__.'/WalletReports.php';
    $credit=$receipt===null?null:achbotWalletReportEntryLocked($db,$receipt['entry']['id']);
    $debit=$op['debit_entry_id']===null?null:achbotWalletReportEntryLocked($db,(int)$op['debit_entry_id']);
    $actor=$receipt===null?0:$receipt['entry']['actor'];
    $fingerprint=hash('sha256',achbotServiceJson([
        'id'=>$id,'owner'=>$uid,'owner_row'=>(int)$op['user_row_id'],'quote'=>$op['quote_sha256'],
        'receipt'=>$receipt['fingerprint']??null,'reference'=>$reference,
        'credit'=>$credit===null?null:achbotWalletReportFingerprint($credit),
        'debit'=>$debit===null?null:achbotWalletReportFingerprint($debit)
    ]));
    // The owner row is locked. Global ledger IDs can interleave other
    // accounts, so verify continuity on this exact owner's ledger only.
    // Equality of balances alone does not prove adjacent movements.
    $contiguous=$credit!==null && $debit!==null &&
        (int)$credit['id']<(int)$debit['id'] &&
        (int)$credit['user_row_id']===(int)$debit['user_row_id'] &&
        (int)$credit['userid']===(int)$debit['userid'] &&
        (int)$credit['balance_after']===(int)$debit['balance_before'] &&
        !achbotProviderRow($db,'SELECT id FROM wallet_entries
            WHERE userid=? AND user_row_id=? AND id>? AND id<? LIMIT 1',
            'iiii',[$uid,(int)$owner['id'],(int)$credit['id'],(int)$debit['id']]);
    return compact('reference','actor','receipt','credit','debit','fingerprint','contiguous');
}
/** Rendering is deliberately separate from the evidence readers used by
 * customer status and delivery validation. A copy error cannot falsify them. */
function achbotServiceReportFundingFacts(array $funding): string {
    $credit=$funding['credit'];$debit=$funding['debit'];$actor=$funding['actor'];
    $facts='';
    if ($credit!==null || $debit!==null)
        $facts.="\n\n".achbotMessage('operational_reports.service_wallet_title');
    $balance=static fn(array $first,array $last):string=>achbotMessageHtml('operational_reports.service_wallet_balance',[
        'before'=>number_format((int)$first['balance_before']),
        'after'=>number_format((int)$last['balance_after'])]);
    $contiguous=$funding['contiguous'];
    foreach ([$credit,$debit] as $entry) {
        if ($entry===null) continue;
        $delta=(int)$entry['delta_toman'];
        $facts.="\n".achbotMessageHtml($delta>0?'operational_reports.service_wallet_credit':'operational_reports.service_wallet_debit',[
            'entry'=>$entry['id'],'amount'=>number_format(abs($delta)),'date'=>achbotWalletReportDate($entry)]);
        if (!$contiguous) $facts.="\n".$balance($entry,$entry);
    }
    if ($contiguous) {
        $net=(int)$credit['delta_toman']+(int)$debit['delta_toman'];
        $facts.="\n".$balance($credit,$debit)."\n".achbotMessageHtml('operational_reports.service_wallet_net',[
            'net'=>($net>0?'+':'').number_format($net)]);
    }
    if ($actor>0) $facts.="\n".achbotMessageHtml('operational_reports.actor',['actor'=>$actor]);
    return $facts;
}
function achbotServiceReportContext(array $op,array $quote,array $funding,string $remark): string {
    $q=$quote['request'];$kind=$op['kind'];$receipt=$funding['receipt'];
    $method=$receipt===null?achbotMessage('automatic_gateway_review.wallet_provider'):
        achbotReceiptReportMethod($receipt['attempt']).' | '.achbotReceiptReportBasis($receipt['attempt']);
    return achbotMessageHtml('operational_reports.service_context',[
        'user'=>$op['userid'],'method'=>$method,'amount'=>number_format($q['price']),'remark'=>$remark,
        'server'=>$q['server_id'],'plan'=>$q['plan_id'],'reference'=>$funding['reference'],'service'=>$op['id']
    ])."\n".match($kind) {
        'BUY','RENEW'=>achbotMessageHtml('operational_reports.service_units',['volume'=>$q['volume']===0?
            achbotMessage('operational_reports.unlimited_volume'):achbotMessage('operational_reports.finite_volume',['volume'=>number_format($q['volume'])]),
            'days'=>number_format($q['days'])]),
        'ADD_TIME'=>achbotMessageHtml('operational_reports.service_added_days',['days'=>$q['days']]),
        'ADD_VOLUME'=>achbotMessageHtml('operational_reports.service_added_volume',['volume'=>$q['volume']])
    };
}
function achbotServiceStageFundingReportLocked(mysqli $db,array $op,array $quote,?array $receipt): void {
    $funding=achbotServiceReportFundingLocked($db,$op,$quote);
    if (($receipt['fingerprint']??null)!==($funding['receipt']['fingerprint']??null))
        throw new RuntimeException('service_report_funding_receipt_changed');
    $q=$quote['request'];$id=(int)$op['id'];
    $text=achbotMessage($q['price']===0?'operational_reports.service_zero_title':'operational_reports.funded_title')."\n\n".
        achbotServiceReportContext($op,$quote,$funding,$op['kind']==='BUY' && $q['quantity']>1?
            achbotMessage('bulk_reporting.bulk_subject'):$q['remark']).
        achbotServiceReportFundingFacts($funding)."\n".achbotMessage($q['price']===0?
            'operational_reports.service_zero_pending':'operational_reports.service_funding_pending');
    achbotFollowupStageAdminReportLocked($db,null,$id,'service_funded',$funding['fingerprint'],
        (int)($GLOBALS['admin']??0),(string)($GLOBALS['botState']['rewardChannel']??''),
        ['text'=>$text,'keys'=>null,'mode'=>'HTML'],$funding['actor']);
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
    $funding=achbotServiceReportFundingLocked($db,$op,$quote);
    $receipt=$funding['receipt'];
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
    $fingerprint=hash('sha256',achbotServiceJson(['funding'=>$funding['fingerprint'],'lines'=>$lines]));
    return compact('fingerprint','receipt','accounts','delivered','funding');
}

/** One report per operation/destination; completion and confirmed customer
 * delivery are revisions of that same report, not extra purchase notices.
 * Receipt markup remains an independently retained moderation record. */
function achbotServiceStageCompletionReportsLocked(mysqli $db,array $op,array $quote): void {
    if ($op['command_kind']==='automatic_renewal') return; // existing special copy
    $source=achbotServiceCompletionReportSourceLocked($db,$op,$quote);
    $q=$quote['request'];$kind=$op['kind'];$id=(int)$op['id'];
    $receipt=$source['receipt'];$accounts=$source['accounts'];$funding=$source['funding'];
    $remark=$kind!=='BUY'?$q['remark']:
        ($q['quantity']>1?achbotMessage('bulk_reporting.bulk_subject'):$accounts[0]['name']);
    $manual=$receipt!==null && !achbotReceiptIsHosted($receipt['attempt']);
    $text='<b>'.achbotMessage(match($kind) {
        'BUY'=>'operational_reports.service_buy',
        'RENEW'=>'operational_reports.service_renew',
        'ADD_TIME'=>'operational_reports.service_days',
        'ADD_VOLUME'=>'operational_reports.service_volume'
    })."</b>\n\n".achbotServiceReportContext($op,$quote,$funding,$remark);
    if ($kind!=='BUY' || $q['quantity']===1)
        $text.="\n".achbotMessageHtml('operational_reports.service_order',
            ['order'=>$kind==='BUY'?$accounts[0]['order_id']:$q['order_id']]);
    $text.=achbotServiceReportFundingFacts($funding);
    $pending="\n".achbotMessage('operational_reports.service_delivery_pending');
    $delivered="\n".achbotMessage('operational_reports.service_delivery_confirmed');
    $pages=[];
    if ($kind==='BUY' && $q['quantity']>1) {
        // Reserve actual status bytes once so both summary revisions have the
        // same continuation partition, with no duplicate account pages.
        $report=achbotBulkReportBuild($text,['requested'=>$q['quantity'],'confirmed'=>count($accounts),
            'complete'=>true,'accounts'=>$accounts,'reference'=>$funding['reference']],true,
            max(strlen($pending),strlen($delivered)));
        $text=$report['summary'];$pages=$report['pages'];
    }
    $owner=(int)($GLOBALS['admin']??0);$actor=$funding['actor'];
    $actorKeys=$manual && $actor>0?achbotMainMenuForActor($db,$actor,$owner,$GLOBALS['botState']??[]):null;
    $channel=(string)($GLOBALS['botState']['rewardChannel']??'');
    // Both versions are sealed in the local commit. The delivery version
    // is ineligible until the original customer parts all prove ACK.
    achbotFollowupStageAdminReportLocked($db,null,$id,'service_completed',$source['fingerprint'],
        $owner,$channel,['text'=>$text.$pending,'keys'=>null,'mode'=>'HTML'],$actor,null,$actorKeys,$pages);
    achbotFollowupStageAdminReportLocked($db,null,$id,'service_delivered',$source['fingerprint'],
        $owner,$channel,['text'=>$text.$delivered,'keys'=>null,'mode'=>'HTML'],$actor,null,$actorKeys);
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
    $funding=achbotServiceReportFundingLocked($db,$op,$quote);
    $fingerprint=hash('sha256',achbotServiceJson([
        'funding'=>$funding['fingerprint'],'refund'=>$op['refund_entry_id'],'resolution'=>$kind,
        'actor'=>$op['resolved_by'],'at'=>$op['resolved_at'],'cancelled'=>$count]));
    return ['fingerprint'=>$fingerprint,'amount'=>$amount,'retained'=>$retained,'cancelled'=>$count];
}
function achbotServiceStageResolutionReportsLocked(mysqli $db,array $op,array $quote,int $primary): void {
    $e=achbotServiceResolutionEvidenceLocked($db,$op,$quote);
    $funding=achbotServiceReportFundingLocked($db,$op,$quote);
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
    $content['text'].="\n".achbotServiceReportContext($op,$quote,$funding,$q['remark']).achbotServiceReportFundingFacts($funding);
    if ($op['kind']!=='BUY')
        $content['text'].="\n".achbotMessageHtml('operational_reports.resolution_subject',['remark'=>$q['remark'],'order'=>$q['order_id']]);
    if ($e['amount']>0) {
        require_once __DIR__.'/WalletReports.php';
        $content['text'].="\n".achbotWalletReportFacts(achbotWalletReportEntryLocked($db,(int)$op['refund_entry_id']));
    }
    $pages=[];
    if ($op['kind']==='BUY' && $e['retained']>0) {
        $s=achbotProviderStatement($db,'SELECT * FROM payment_delivery_intents WHERE service_operation_id=? ORDER BY ordinal FOR UPDATE','i',[$id]);
        $lines=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
        if (count($lines)!==$e['retained']) throw new RuntimeException('service_resolution_retained_lines_missing');
        $accounts=[];
        foreach ($lines as $i=>$d) {
            $body=achbotServiceDeliveryOriginalBody($op,$quote,$d,$i+1);
            $accounts[]=['ordinal'=>$i+1,'name'=>$body['binding']['remark'],'order_id'=>(int)$d['order_id']];
        }
        $report=achbotBulkReportBuild($content['text'],['requested'=>$q['quantity'],'confirmed'=>$e['retained'],
            'complete'=>false,'resolved'=>true,'accounts'=>$accounts,'reference'=>'svc_'.$id],true);
        $content['text']=$report['summary'];$pages=$report['pages'];
    }
    achbotFollowupStageAdminReportLocked($db,null,$id,$stage,$e['fingerprint'],$primary,
        (string)($GLOBALS['botState']['rewardChannel']??''),$content,(int)$op['resolved_by'],null,null,$pages,
        null,null,[$funding['actor']]);
}

function achbotServiceReportValidateLocked(mysqli $db,array $op,array $quote,array $r): ?array {
    if ($r['pay_row_id']!==null || (int)$r['service_operation_id']!==(int)$op['id']) return null;
    // Continuations are independently sealed admin report events; never
    // treat a continuation as a customer packet or receipt edit.
    $stage=(string)$r['stage'];
    if (preg_match('/\A(service_completed|service_resolution)_p[1-9][0-9]{0,9}\z/D',$stage,$match)) {
        if ($op['kind']!=='BUY' || !in_array($r['role'],['service_admin','report_actor','service_channel'],true) ||
            $r['kind']!=='send' || $r['message_id']!==null) return null;
        $stage=$match[1];
    }
    if ($stage==='service_resolution') {
        if ($r['kind']!=='send' || $r['message_id']!==null) return null;
        if ($r['role']==='service_customer') {
            if ((string)$r['recipient']!==(string)$op['userid']) return null;
        } elseif (!achbotFollowupAdminReportAllowed($db,$r,(int)$op['resolved_by'],'payments',
            [achbotServiceFundingReceiptLocked($db,$op)['entry']['actor']??0])) return null;
        $e=achbotServiceResolutionEvidenceLocked($db,$op,$quote);
        return achbotFollowupDecodePacket(null,$r,$e['fingerprint']);
    }
    if ($stage==='service_funded') {
        $funding=achbotServiceReportFundingLocked($db,$op,$quote);
        if (!achbotFollowupAdminReportAllowed($db,$r,$funding['actor'])) return null;
        return achbotFollowupDecodePacket(null,$r,$funding['fingerprint']);
    }
    if (in_array($stage,['service_completed','service_delivered'],true)) {
        if ($op['stage']!=='completed' || $op['resolution_kind']!==null ||
            $op['command_kind']==='automatic_renewal') return null;
        $requiresAck=$r['stage']==='service_delivered';
        $source=achbotServiceCompletionReportSourceLocked($db,$op,$quote,$requiresAck);
        if ($requiresAck && !$source['delivered']) throw new RuntimeException('service_delivery_not_ready');
        $receipt=$source['receipt'];
        // Previously staged completion edits remain executable only against
        // the original verified receipt target. New receipts use wallet_credit.
        if ($r['kind']==='edit') {
            if ($requiresAck || $receipt===null || !achbotReceiptReportEditAllowed($db,$receipt,$r)) return null;
        } elseif (!achbotFollowupAdminReportAllowed($db,$r,$source['funding']['actor'])) return null;
        return achbotFollowupDecodePacket(null,$r,$source['fingerprint']);
    }
    if ($r['kind']!=='send' || $r['message_id']!==null) return null;
    $recipient=(string)$r['recipient'];$owner=(int)($GLOBALS['admin']??0);
    if ($r['stage']==='auto_renewal_report') {
        if ($op['stage']!=='completed') return null;
        if ($r['role']==='service_admin') {
            if (!achbotFollowupGeneralRecipientAllowed($db,(int)$recipient,$owner)) return null;
        } elseif ($r['role']==='service_channel') {
            if (!achbotFollowupChannelAllowed($recipient)) return null;
        } else return null;
    } elseif ($r['stage']==='auto_renewal_low_wallet') {
        if ($r['role']!=='service_customer' || $recipient!==(string)(int)$op['userid']) return null;
    } else return null;
    $fingerprint=achbotServiceReportFingerprintLocked($db,$op,$quote,$r['stage']);
    return achbotFollowupDecodePacket(null,$r,$fingerprint);
}
