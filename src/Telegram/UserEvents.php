<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/../Finance/OperationalReports.php';
require_once __DIR__.'/../Subscription/Presentation.php';

/** User-event generations are immutable original outbox references, not
 * message IDs. No new table, polling loop or dependency on Telegram ACK. */
function achbotUserEvent(string $reference,array $user,string $event,int $actor=0): array {
    return ['reference'=>$reference,'actor'=>$actor,
        'capability'=>$event==='welcome'?'operations':'agents',
        'user'=>(int)$user['userid'],'user_row_id'=>(int)$user['id'],'event'=>$event];
}
function achbotUserEventValid(array $event): bool {
    if (array_keys($event)!==['reference','actor','capability','user','user_row_id','event'] ||
        !is_int($event['user']) || $event['user']<=0 ||
        !is_int($event['user_row_id']) || $event['user_row_id']<=0 ||
        !is_int($event['actor']) || !is_string($event['reference'])) return false;
    $row=(string)$event['user_row_id'];
    return match($event['event']) {
        'welcome'=>$event['actor']===0 && $event['capability']==='operations' &&
            $event['reference']==='welcome:'.$row,
        'agency_request'=>$event['actor']===0 && $event['capability']==='agents' &&
            preg_match('/^agency-request:'.$row.':[1-9][0-9]*$/D',$event['reference'])===1,
        'agency_approved','agency_declined'=>$event['actor']>0 && $event['capability']==='agents' &&
            preg_match('/^agency-result:'.$row.':[1-9][0-9]*$/D',$event['reference'])===1,
        default=>false
    };
}
function achbotUserEventStageLocked(mysqli $db,array $event,string $stage,string $text,
    ?string $keys=null,string $mode='HTML'): void {
    achbotOperationalReportRequireReady($db);
    $parts=$mode==='HTML'?\AchBot\Subscription\Presentation\messageParts($text):[$text];
    if ($stage==='user_notice') {
        foreach ($parts as $i=>$part)
            achbotFollowupStoreLocked($db,null,null,$i===0?$stage:$stage.'_p'.$i,
                'customer','send',(string)$event['user'],null,achbotFollowupOperationalFingerprint($event),
                ['text'=>$part,'keys'=>$i===count($parts)-1?$keys:null,'mode'=>$mode],null,$event);
        return;
    }
    achbotFollowupStageAdminReportLocked($db,null,null,'operational_report',
        achbotFollowupOperationalFingerprint($event),(int)$GLOBALS['admin'],
        (string)($GLOBALS['botState']['rewardChannel']??''),['text'=>$parts[0],'keys'=>null,'mode'=>$mode],
        0,null,null,array_slice($parts,1),$event,$keys);
}
function achbotAgencyRequestReference(int $rowId,int $generation): string {
    return 'agency-request:'.$rowId.':'.$generation;
}
/** Call under the applicant's row lock for a state change. On dispatch the
 * same sealed original generation is used to retire stale controls. */
function achbotAgencyOriginalRequest(mysqli $db,array $user): ?array {
    $r=achbotProviderRow($db,"SELECT * FROM payment_followup_intents
        WHERE operational_ref LIKE ? AND stage='operational_report' AND role='service_admin'
        ORDER BY id DESC LIMIT 1",'s',['agency-request:'.(int)$user['id'].':%']);
    if (!$r) return null;
    $event=null;
    if (!achbotFollowupDecodePacket(null,$r,(string)$r['pay_fingerprint'],$event) ||
        ($event['event']??null)!=='agency_request' || $event['user']!==(int)$user['userid'] ||
        $event['user_row_id']!==(int)$user['id'])
        throw new RuntimeException('agency_original_request_invalid');
    return $event;
}
/** An event remains actual after a later role change. Only current applicant
 * controls and present-tense customer role notices depend on current state. */
function achbotUserEventValidateLocked(mysqli $db,array $r,array $event,array $packet): ?array {
    if (!achbotUserEventValid($event) || $r['kind']!=='send' || $r['message_id']!==null) return null;
    $s=achbotProviderStatement($db,'SELECT * FROM users FORCE INDEX (idx_users_userid_v1) WHERE userid=? LIMIT 2 LOCK IN SHARE MODE NOWAIT',
        'i',[$event['user']]);$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
    if (count($rows)!==1 || (int)$rows[0]['id']!==$event['user_row_id'])
        return achbotFollowupRequireRecipient(false)?$packet:null;
    $u=$rows[0];$customer=str_starts_with($r['stage'],'user_notice');
    if ($customer) {
        if ($r['role']!=='customer' || $r['recipient']!==(string)$event['user']) return null;
        if ($u['step']==='banned') return achbotFollowupRequireRecipient(false)?$packet:null;
        if ($event['event']==='welcome') {
            if ($u['first_start']!=='sent') return null;
        } else {
            $original=achbotAgencyOriginalRequest($db,$u);
            $ref=str_replace('agency-result:','agency-request:',$event['reference']);
            $expected=$event['event']==='agency_approved'?1:-1;
            if (($original['reference']??null)!==$ref || (int)$u['is_agent']!==$expected)
                return achbotFollowupRequireRecipient(false)?$packet:null;
        }
    } else {
        if (!achbotFollowupAdminReportAllowed($db,$r,0,$event['capability'])) return null;
        if ($event['event']==='agency_request') {
            $original=achbotAgencyOriginalRequest($db,$u);
            if ((int)$u['is_agent']!==2 || ($original['reference']??null)!==$event['reference'])
                $packet['keys']=null;
        }
    }
    return $packet;
}
/** First-start admission and both original recipient packets commit together.
 * first_start is a claim, not an assertion of successful Telegram delivery. */
function achbotUserWelcomeClaim(mysqli $db,int $userId,int $rowId,string $home,string $keys,
    string $report,string $reportKeys): string {
    try {
        achbotOperationalReportRequireReady($db);
        if (!$db->begin_transaction()) throw new RuntimeException('welcome_begin');
        $s=achbotProviderStatement($db,'SELECT * FROM users FORCE INDEX (idx_users_userid_v1) WHERE userid=? LIMIT 2 FOR UPDATE','i',[$userId]);
        $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
        if (count($rows)!==1 || (int)$rows[0]['id']!==$rowId || $rows[0]['step']==='banned')
            throw new DomainException('welcome_actor_changed');
        $u=$rows[0];
        if (!empty($u['first_start'])) {$db->rollback();return 'already';}
        $s=achbotProviderStatement($db,"UPDATE users SET first_start='sent' WHERE id=? AND userid=?",
            'ii',[$rowId,$userId]);$changed=$s->affected_rows;$s->close();
        if ($changed!==1) throw new RuntimeException('welcome_claim_unconfirmed');
        $event=achbotUserEvent('welcome:'.$rowId,$u,'welcome');
        achbotUserEventStageLocked($db,$event,'user_notice',$home,$keys);
        achbotUserEventStageLocked($db,$event,'operational_report',$report,$reportKeys);
        if (!$db->commit()) throw new RuntimeException('welcome_commit_unconfirmed');
        return 'claimed';
    } catch (Throwable $e) {
        try {$db->rollback();} catch (Throwable $ignored) {}
        error_log('achbot_welcome_claim_unconfirmed user='.$userId.' class='.get_class($e));
        return 'unavailable';
    }
}
