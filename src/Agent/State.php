<?php
/** Current agency request and decision routes. The original application
 * generation, state change and notifications share the existing journal. */
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/../../messages/loader.php';
require_once __DIR__.'/../Telegram/Conversation.php';
require_once __DIR__.'/../Telegram/UserEvents.php';
require_once __DIR__.'/../Admin/Access.php';

function achbotAgencyUser(mysqli $db,int $uid,bool $lock=false): ?array {
    $s=achbotProviderStatement($db,'SELECT * FROM users WHERE userid=? LIMIT 2'.($lock?' FOR UPDATE':''),'i',[$uid]);
    $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
    return count($rows)===1?$rows[0]:null;
}
function achbotAgencyApplicant(mysqli $db,int $uid,bool $lock=false): ?array {
    $u=achbotAgencyUser($db,$uid,$lock);
    return $u && $u['step']!=='banned'?$u:null;
}
function achbotAgencyMatches(mysqli $db,array $user,int $generation): bool {
    if ((int)$user['is_agent']!==2) return false;
    $event=achbotAgencyOriginalRequest($db,$user);
    return ($event['reference']??null)===achbotAgencyRequestReference((int)$user['id'],$generation);
}
/** A legacy pending applicant without an original current report may
 * explicitly request again to receive actionable current controls. There is
 * no automatic replay of old applications or prior staff sends. */
function achbotAgencyRequest(mysqli $db,int $uid,int $generation,string $name,string $username): array {
    try {
        if ($generation<=0) throw new DomainException('agency_update_invalid');
        achbotOperationalReportRequireReady($db);
        if (!$db->begin_transaction()) throw new RuntimeException('agency_begin');
        $u=achbotAgencyApplicant($db,$uid,true);
        if (!$u) throw new DomainException('agency_applicant_missing');
        $state=(int)$u['is_agent'];
        if (!in_array($state,[0,2],true)) {$db->rollback();return ['state'=>$state];}
        if ($state===2 && achbotAgencyOriginalRequest($db,$u)!==null) {
            $db->rollback();return ['state'=>2];
        }
        $ref=achbotAgencyRequestReference((int)$u['id'],$generation);
        if (achbotProviderRow($db,'SELECT id FROM payment_followup_intents WHERE operational_ref=? LIMIT 1','s',[$ref])) {
            $db->rollback();return ['state'=>'stale'];
        }
        if ($state===0) {
            $s=achbotProviderStatement($db,'UPDATE users SET is_agent=2 WHERE id=? AND is_agent=0','i',[(int)$u['id']]);
            $n=$s->affected_rows;$s->close();
            if ($n!==1) throw new RuntimeException('agency_request_unconfirmed');
        }
        $event=achbotUserEvent($ref,$u,'agency_request');
        $keys=json_encode(['inline_keyboard'=>[[
            ['text'=>achbotMessage('legacy_buttons.approve'),'callback_data'=>'agencyApprove'.$uid.'_'.$generation],
            ['text'=>achbotMessage('legacy_buttons.decline'),'callback_data'=>'agencyDecline'.$uid.'_'.$generation]
        ]]],JSON_THROW_ON_ERROR);
        $msg=strtr(achbotMessage('legacy_messages.request_agency_message'),[
            'USERNAME'=>$username,'NAME'=>$name,'USERID'=>(string)$uid]);
        achbotUserEventStageLocked($db,$event,'operational_report',$msg,$keys);
        if (!$db->commit()) throw new RuntimeException('agency_request_commit');
        return ['state'=>'claimed','ref'=>$ref];
    } catch(Throwable $e) {
        try {$db->rollback();}catch(Throwable $ignored){}
        error_log('achbot_agency_request_unconfirmed user='.$uid.' class='.get_class($e));
        return ['state'=>'unavailable'];
    }
}
/** Recheck the exact actor editor and original pending application while
 * locked. Only a definitely rolled-back deadlock can be attempted once
 * again; no Telegram side effect occurs until commit. */
function achbotAgencyDecision(mysqli $db,int $actor,array $cached,int $uid,int $generation,
    ?int $discount,string $notice,bool $consume): array {
    for ($attempt=0;$attempt<2;$attempt++) {
        try {
            achbotOperationalReportRequireReady($db);
            if (!$db->begin_transaction()) throw new RuntimeException('agency_decision_begin');
            $staff=\AchBot\Telegram\Conversation\lockEditor($db,$actor,$cached);
            if (!achbotAdminCanLocked($db,$actor,(int)$GLOBALS['admin'],'agents'))
                throw new DomainException('agency_permission_changed');
            $u=achbotAgencyApplicant($db,$uid,true);
            if (!$u || !achbotAgencyMatches($db,$u,$generation)) {
                if ($consume) \AchBot\Telegram\Conversation\writeEditorLocked($db,$staff,'none',null);
                if (!$db->commit()) throw new RuntimeException('agency_stale_commit');
                return ['state'=>'stale'];
            }
            if ($discount!==null) {
                if ($discount<0 || $discount>100) throw new DomainException('agency_discount_invalid');
                $s=achbotProviderStatement($db,'UPDATE users SET is_agent=1,discount_percent=?,agent_date=? WHERE id=? AND is_agent=2',
                    'sii',[json_encode(['normal'=>(string)$discount],JSON_THROW_ON_ERROR),time(),(int)$u['id']]);
                $kind='agency_approved';
            } else {
                $s=achbotProviderStatement($db,'UPDATE users SET is_agent=-1 WHERE id=? AND is_agent=2','i',[(int)$u['id']]);
                $kind='agency_declined';
            }
            $n=$s->affected_rows;$s->close();
            if ($n!==1) throw new RuntimeException('agency_decision_unconfirmed');
            $ref='agency-result:'.(int)$u['id'].':'.$generation;
            achbotUserEventStageLocked($db,achbotUserEvent($ref,$u,$kind,$actor),'user_notice',$notice,null,'plain');
            if ($consume) \AchBot\Telegram\Conversation\writeEditorLocked($db,$staff,'none',null);
            if (!$db->commit()) throw new RuntimeException('agency_decision_commit');
            return ['state'=>'claimed','ref'=>$ref];
        } catch(Throwable $e) {
            try {$db->rollback();}catch(Throwable $ignored){}
            $errno=$e instanceof AchbotProviderStorageException?$e->sqlErrno:
                ($e instanceof mysqli_sql_exception?(int)$e->getCode():0);
            if ($errno===1213 && $attempt===0) continue;
            error_log('achbot_agency_decision_unconfirmed user='.$uid.' actor='.$actor.' class='.get_class($e));
            return ['state'=>'unavailable'];
        }
    }
    return ['state'=>'unavailable'];
}
function achbotAgencyPositive(string $value): ?int {
    $n=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    return $n===false?null:$n;
}

/** Explicit removal/release applies only to the state displayed in that
 * list. It never cancels an unrelated pending application. */
function achbotAgencyReset(mysqli $db,int $actor,int $uid,int $expected): bool {
    if (!in_array($expected,[-1,1],true) || $uid<=0) return false;
    try {
        if (!$db->begin_transaction()) return false;
        if (!achbotAdminCanLocked($db,$actor,(int)$GLOBALS['admin'],'agents'))
            throw new DomainException('agency_permission_changed');
        $u=achbotAgencyUser($db,$uid,true);
        if (!$u || (int)$u['is_agent']!==$expected) {$db->rollback();return false;}
        $s=achbotProviderStatement($db,'UPDATE users SET is_agent=0 WHERE id=? AND is_agent=?','ii',[(int)$u['id'],$expected]);
        $n=$s->affected_rows;$s->close();
        if ($n!==1 || !$db->commit()) throw new RuntimeException('agency_reset_unconfirmed');
        return true;
    } catch(Throwable $e) {
        try {$db->rollback();}catch(Throwable $ignored){}
        error_log('achbot_agency_reset_unconfirmed user='.$uid.' class='.get_class($e));
        return false;
    }
}
/** Read the actual current discount document under the target row lock.
 * Independent plan/server edits must not overwrite one another. */
function achbotAgencyDiscountWrite(mysqli $db,int $actor,int $uid,string $scope,string $key,?int $percent,?array $editor=null): ?array {
    if (!in_array($scope,['normal','servers','plans'],true) ||
        ($scope==='normal'?($key!=='' || $percent===null):achbotAgencyPositive($key)===null) ||
        ($percent!==null && ($percent<0 || $percent>100))) return null;
    try {
        if (!$db->begin_transaction()) return null;
        $owned=$editor===null?null:\AchBot\Telegram\Conversation\lockEditor($db,$actor,$editor);
        if (!achbotAdminCanLocked($db,$actor,(int)$GLOBALS['admin'],'agents'))
            throw new DomainException('agency_permission_changed');
        $u=achbotAgencyUser($db,$uid,true);
        if (!$u || ($scope!=='normal' && (int)$u['is_agent']!==1))
            throw new DomainException('agency_target_changed');
        if ($scope!=='normal' && $percent!==null) {
            $table=$scope==='plans'?'server_plans':'server_info';
            if (!achbotProviderRow($db,'SELECT id FROM '.$table.' WHERE id=?','i',[(int)$key]))
                throw new DomainException('agency_discount_target_missing');
        }
        $doc=json_decode((string)$u['discount_percent'],true);
        if ($doc===null && trim((string)$u['discount_percent'])!=='')
            throw new DomainException('agency_discount_document_invalid');
        if ($doc===null) $doc=[];
        if (!is_array($doc)) throw new DomainException('agency_discount_document_invalid');
        if ($scope==='normal') $doc['normal']=(string)$percent;
        else {
            if (isset($doc[$scope]) && !is_array($doc[$scope])) throw new DomainException('agency_discount_scope_invalid');
            if ($percent===null) unset($doc[$scope][$key]);
            else $doc[$scope][$key]=(string)$percent;
        }
        $doc+=['normal'=>0,'servers'=>[],'plans'=>[]];
        $encoded=json_encode($doc,JSON_THROW_ON_ERROR);
        $s=achbotProviderStatement($db,'UPDATE users SET discount_percent=? WHERE id=?','si',[$encoded,(int)$u['id']]);
        $s->close();
        if ($owned!==null) \AchBot\Telegram\Conversation\writeEditorLocked($db,$owned,'none',null);
        if (!$db->commit()) throw new RuntimeException('agency_discount_commit');
        return $u;
    } catch(Throwable $e) {
        try {$db->rollback();}catch(Throwable $ignored){}
        error_log('achbot_agency_discount_unconfirmed user='.$uid.' class='.get_class($e));
        return null;
    }
}
