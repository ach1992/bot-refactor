<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/FollowupOutbox.php';
require_once __DIR__.'/../Panel/ProviderStorage.php';

/** The event is committed with the actual local effect, not inferred from a
 * future (possibly deleted or changed) subscription. Only the sealed original
 * packet may be sent; no financial or Native mutation is performed here. */
function achbotOperationalReportRequireReady(mysqli $db): void {
    if ((int)($GLOBALS['admin']??0)<=0 || !achbotFollowupSchemaReady($db))
        throw new RuntimeException('followup_operational_source_unavailable');
}

function achbotOperationalReportStageLocked(mysqli $db,string $reference,int $user,
    int $actor,string $capability,string $text,?string $staffKeys=null,array $pages=[],string $stage='operational_report'): void {
    $event=['reference'=>$reference,'actor'=>$actor,'capability'=>$capability,'user'=>$user];
    achbotOperationalReportRequireReady($db);
    if (!achbotFollowupOperationalEventValid($event))
        throw new RuntimeException('followup_operational_source_unavailable');
    $owner=(int)($GLOBALS['admin']??0);
    // The business finalizer owns original actor authorization. A role
    // change after Native success must not prevent local finality. Delivery
    // revalidates the original recipient's actual current permission.
    achbotFollowupStageAdminReportLocked($db,null,null,$stage,
        achbotFollowupOperationalFingerprint($event),$owner,
        (string)($GLOBALS['botState']['rewardChannel']??''),['text'=>$text,'keys'=>null,'mode'=>'HTML'],
        $actor,null,null,$pages,$event,$staffKeys);
}
/** An original gift text is a distinct customer delivery, never an admin copy.
 * Seal the verified local attachment with the event; no new table or worker. */
function achbotOperationalGiftCustomerStageLocked(mysqli $db,string $reference,int $actor,array $order,string $text): void {
    $event=['reference'=>$reference,'actor'=>$actor,'capability'=>'operations',
        'user'=>$order['userid'],'order_id'=>$order['id'],
        'order_sha256'=>hash('sha256',json_encode($order,JSON_THROW_ON_ERROR))];
    achbotOperationalReportRequireReady($db);
    achbotFollowupStoreLocked($db,null,null,'operational_customer_notice','customer','send',
        (string)$event['user'],null,achbotFollowupOperationalFingerprint($event),
        ['text'=>$text,'keys'=>null,'mode'=>'HTML'],null,$event);
}
function achbotOperationalGiftCustomerAllowedLocked(mysqli $db,array $r,array $event): bool {
    if ($r['role']!=='customer' || $r['kind']!=='send' || $r['message_id']!==null ||
        $r['recipient']!==(string)$event['user']) return false;
    require_once __DIR__.'/../Subscription/GiftBatch.php';
    try {$order=achbotGiftOrder($db,$event['order_id'],true);}
    catch (Throwable $error) {
        if (in_array($error->getMessage(),['gift_order_missing','gift_order_ineligible','gift_recipient_identity_invalid'],true))
            return achbotFollowupRequireRecipient(false);
        throw $error;
    }
    if ($order['userid']!==$event['user'] ||
        !hash_equals($event['order_sha256'],hash('sha256',json_encode($order,JSON_THROW_ON_ERROR))))
        return achbotFollowupRequireRecipient(false);
    $s=achbotProviderStatement($db,'SELECT id FROM users WHERE BINARY userid=BINARY ? LIMIT 2 LOCK IN SHARE MODE NOWAIT',
        's',[(string)$event['user']]);
    $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
    return achbotFollowupRequireRecipient(count($rows)===1);
}
function achbotOperationalReportValidateLocked(mysqli $db,array $r): ?array {
    $event=null;
    $packet=achbotFollowupDecodePacket(null,$r,(string)$r['pay_fingerprint'],$event);
    if ($packet===null || $event===null) return null;
    if (isset($event['user_row_id'])) {
        require_once __DIR__.'/../Telegram/UserEvents.php';
        return achbotUserEventValidateLocked($db,$r,$event,$packet);
    }
    if (isset($event['ticket_id'])) {
        require_once __DIR__.'/../Support/Notices.php';
        return achbotTicketNoticeValidate($db,$r,$event,$packet);
    }
    $allowed=$r['stage']==='operational_customer_notice'
        ?achbotOperationalGiftCustomerAllowedLocked($db,$r,$event)
        :achbotFollowupAdminReportAllowed($db,$r,$event['actor'],$event['capability']);
    return $allowed?$packet:null;
}
function achbotOperationalReportLock(mysqli $db,string $reference): string {
    return achbotDatabaseLockName($db,'operational:'.$reference);
}
/** A successful effect does not depend on immediate Telegram delivery.
 * Pending/rejected/unknown have exactly the same worker and attempt rules
 * as money reports. Never retry a service operation from here. */
function achbotOperationalReportDispatch(mysqli $db,string $reference,?float $budgetSeconds=null): bool {
    try {
        achbotPaymentEvidenceRequireAutocommit($db);
        $s=achbotProviderStatement($db,"SELECT id,state FROM payment_followup_intents
            WHERE operational_ref=? ORDER BY id",'s',[$reference]);
        $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
        if (!$rows) return false;
        $complete=true;$deadline=$budgetSeconds===null?INF:hrtime(true)/1000000000+$budgetSeconds;
        foreach ($rows as $r) {
            if (hrtime(true)/1000000000>=$deadline) return false;
            $status=$r['state']==='pending'?achbotFollowupDispatchOne($db,(int)$r['id']):$r['state'];
            if (!in_array($status,['acknowledged','skipped'],true)) $complete=false;
        }
        return $complete;
    } catch (Throwable $e) {
        error_log('achbot_operational_report_dispatch_deferred source='.$reference.' class='.get_class($e));
        return false;
    }
}

/** A deleted order cannot be queried later. Capture its actual committed
 * identity and presentation while the deletion transaction still owns it. */
function achbotDeletionReportStageLocked(mysqli $db,array $order,string $mode,int $actor): void {
    $uid=(int)$order['userid'];$id=(int)$order['id'];$remark=$order['remark'];
    if ($uid<=0 || $id<=0 || !in_array($mode,['customer','admin','local','expired'],true) ||
        (in_array($mode,['admin','local'],true)?$actor<=0:$actor!==0))
        throw new RuntimeException('followup_deletion_source_invalid');
    $reference=($mode==='local'?'local-delete':'delete').':'.$id;
    if ($mode==='local') {
        $text=achbotMessageHtml('subscription.order_management.show_order_management_panel.admin_message.c7c4748cee',[
            'userid'=>$uid,'remark'=>$remark,'from_id'=>$actor]);
    } elseif ($mode==='admin') {
        $text=achbotMessageHtml('subscription.account_deletion.no_dont_delete.admin_message.53224aee55',[
            'userId'=>$uid,'remark'=>$remark])."\n".achbotMessageHtml('operational_reports.actor',['actor'=>$actor]);
    } elseif ($mode==='expired') {
        $text=achbotMessageHtml('notifications.warnings.channel_message.5e59a4a33e',[
            'from_id'=>$uid,'remark'=>$remark]);
    } else {
        require_once __DIR__.'/../Presentation/Text.php';
        $user=achbotProviderRow($db,'SELECT name,username FROM users WHERE userid=?','i',[$uid]);
        $plan=achbotProviderRow($db,'SELECT days,volume FROM server_plans WHERE id=?','i',[(int)$order['fileid']]);
        $volumeState=\AchBot\Presentation\Text\serviceAllowanceState($plan['volume']??null,true);
        $daysState=\AchBot\Presentation\Text\serviceAllowanceState($plan['days']??null,true);
        $volume=match($volumeState['state']) {
            'finite'=>$volumeState['display'].achbotMessage('subscription.presentation.format_traffic.text.2acc4ef207'),
            'unlimited'=>achbotMessage('subscription.account_deletion.no_dont_delete.text.5e6ee5eaa1'),
            default=>achbotMessage('payment.pre_payment.text.f07a81a062')
        };
        $days=match($daysState['state']) {
            'finite'=>$daysState['display'].achbotMessage('payment_settings.gateway_rule_admin.gateway_admin_page.text.69ff4a27e5'),
            'unlimited'=>achbotMessage('subscription.account_deletion.no_dont_delete.text.5e6ee5eaa1'),
            default=>achbotMessage('payment.pre_payment.text.f07a81a062')
        };
        $text=achbotMessageHtml('subscription.account_deletion.report',[
            'from_id'=>$uid,'first_name'=>$user['name']??'---','username'=>$user['username']??'',
            'remark'=>$remark,'volumeDisplay'=>$volume,'daysDisplay'=>$days]);
    }
    $text.="\n".achbotMessageHtml('operational_reports.service_order',['order'=>$id]);
    achbotOperationalReportStageLocked($db,$reference,$uid,$actor,'orders',$text);
}

/** Compact a completed batch's still-unsent reports while holding its
 * source lease. Any failure preserves the per-account committed evidence.
 * Never remove an attempted message or silently change its audience. */
function achbotOperationalReportCompactBatch(mysqli $db,string $reference,int $actor,
    string $text,array $pages): void {
    require_once __DIR__.'/../Services/Execution.php';
    if (!achbotServiceExecutionOwnsLock($db,achbotOperationalReportLock($db,$reference)))
        throw new RuntimeException('followup_bulk_lease_missing');
    if (!$db->begin_transaction()) throw new RuntimeException('followup_bulk_begin');
    try {
        $s=achbotProviderStatement($db,"SELECT *
            FROM payment_followup_intents WHERE operational_ref=? ORDER BY id FOR UPDATE",'s',[$reference]);
        $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
        $audiences=[];
        foreach ($rows as $row) {
            if ($row['state']!=='pending' || (int)$row['attempt_count']!==0 ||
                !preg_match('/^operational_report_p[1-9][0-9]*$/D',$row['stage']))
                throw new RuntimeException('followup_bulk_already_attempted');
            $original=null;
            $packet=achbotFollowupDecodePacket(null,$row,(string)$row['pay_fingerprint'],$original);
            if ($packet===null || $original===null || $original['reference']!==$reference || $original['actor']!==$actor)
                throw new RuntimeException('followup_bulk_original_invalid');
            $audiences[$row['stage']][]=$row['role'].':'.$row['recipient'];
        }
        foreach ($audiences as &$targets) {sort($targets);}
        unset($targets);
        $s=achbotProviderStatement($db,'DELETE FROM payment_followup_intents WHERE operational_ref=?','s',[$reference]);$s->close();
        achbotOperationalReportStageLocked($db,$reference,$actor,$actor,'operations',$text,null,$pages);
        $s=achbotProviderStatement($db,"SELECT role,recipient FROM payment_followup_intents WHERE operational_ref=? AND stage='operational_report'",'s',[$reference]);
        $targets=[];
        foreach ($s->get_result()->fetch_all(MYSQLI_ASSOC) as $row) $targets[]=$row['role'].':'.$row['recipient'];
        $s->close();sort($targets);
        foreach ($audiences as $prior) if ($prior!==$targets)
            throw new RuntimeException('followup_bulk_audience_changed');
        if (!$db->commit()) throw new RuntimeException('followup_bulk_commit_unknown');
    } catch (Throwable $e) {
        try {$db->rollback();} catch (Throwable $ignored) {}
        throw $e;
    }
}
