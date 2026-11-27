<?php
/** Shared current broadcast audience and acknowledged-message storage. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

/** A failed SQL read is not an empty recipient set. No implicit retry of a write. */
function achbotBroadcastQuery(mysqli $db,string $sql,array $params=[]): mysqli_result|bool {
    $result=$db->execute_query($sql,$params);
    if ($result===false) throw new RuntimeException('broadcast_database_failed',$db->errno);
    return $result;
}

/** One current eligible-audience predicate for dispatch and progress. */
function achbotBroadcastAudienceSql(string $group): string {
    $scope=match($group) {
        'has'=>' AND EXISTS (SELECT 1 FROM orders_list o WHERE o.userid=u.userid)',
        'none'=>' AND NOT EXISTS (SELECT 1 FROM orders_list o WHERE o.userid=u.userid)',
        'agents'=>' AND u.is_agent=1',
        default=>'',
    };
    return "(u.step IS NULL OR u.step NOT IN ('Bot_Blocked','banned'))".$scope;
}

/** Count remaining unclaimed eligible rows, not recipients lacking an ACK. */
function achbotBroadcastAudienceCount(mysqli $db,string $group,int $after=0): int {
    $where=achbotBroadcastAudienceSql($group);
    $result=achbotBroadcastQuery($db,"SELECT COUNT(*) AS c FROM users u
        WHERE u.id>? AND $where",[$after]);
    $row=$result->fetch_assoc();
    $result->free();
    if (!$row) throw new RuntimeException('broadcast_audience_count_missing');
    return (int)$row['c'];
}

/** The unique (broadcast_id,userid) ledger records successful message IDs. */
function achbotBroadcastAckCount(mysqli $db,int $id): int {
    $result=achbotBroadcastQuery($db,"SELECT COUNT(*) AS c FROM bc_messages
        WHERE broadcast_id=? AND message_id>0",[$id]);
    $row=$result->fetch_assoc();
    $result->free();
    if (!$row) throw new RuntimeException('broadcast_ack_count_missing');
    return (int)$row['c'];
}
function achbotBroadcastStateText(mysqli $db,int $id): string {
    $r=achbotBroadcastQuery($db,'SELECT state FROM send_list WHERE id=?',[$id]);
    $row=$r->fetch_assoc();$r->free();
    if (!$row) return achbotMessage('broadcast.routes.state_missing');
    $state=(int)$row['state'];
    $text=match($state) {
        0=>achbotMessage('broadcast.routes.state_draft'),
        1=>achbotMessage('broadcast.routes.broadcast_start.message.b56da4fdde'),
        2=>achbotMessage('broadcast.routes.state_sending'),
        3=>achbotMessage('broadcast.routes.state_finished'),
        4=>achbotMessage('broadcast.routes.broadcast_start.message.372ee64c90'),
        default=>achbotMessage('broadcast.routes.state_missing')
    };
    if (in_array($state,[1,2,3,4],true))
        $text.="\n".achbotMessageHtml('broadcast.routes.state_ack_count',['count'=>achbotBroadcastAckCount($db,$id)]);
    return $text;
}

/**
 * Admit a single management job against the completed broadcast. Every
 * action uses the parent row as the admission lock. For edits, consume the
 * current editor and update the intended display text in that same commit.
 * Nothing is dispatched before commit, and a failed claim leaves no job.
 */
function achbotBroadcastQueueAction(mysqli $db,int $actor,int $primary,int $id,string $action,
    ?array $cached=null,$update=null,?string $input=null,?string $html=null): ?array {
    require_once __DIR__.'/../Admin/Access.php';
    require_once __DIR__.'/../Telegram/Conversation.php';
    $edit=$action==='edit';
    if ($id<=0 || !in_array($action,['pin','unpin','delete','edit'],true) ||
        !\AchBot\Telegram\Conversation\source($update,$actor,!$edit) ||
        ($edit && (($cached['step']??null)!=='bc_edit_'.$id || $input===null || trim($input)==='' || $html===null))) return null;
    $begun=false;
    try {
        $tx=achbotBroadcastQuery($db,'SELECT @@in_transaction AS active');
        $active=(int)$tx->fetch_assoc()['active'];$tx->free();
        if ($active!==0) return null;
        if (!$db->begin_transaction()) throw new RuntimeException('broadcast_action_begin');
        $begun=true;
        $editor=$edit?\AchBot\Telegram\Conversation\lockEditor($db,$actor,$cached):null;
        if ($editor!==null && (\AchBot\Telegram\Conversation\editorOpeningGeneration($editor)===null ||
            !\AchBot\Telegram\Conversation\editorAcceptsUpdate($editor,$actor,$update)))
            throw new DomainException('broadcast_editor_update_stale');
        if (!achbotAdminCanLocked($db,$actor,$primary,'operations'))
            throw new DomainException('broadcast_action_unauthorized');
        $q=achbotBroadcastQuery($db,'SELECT type,state FROM send_list WHERE id=? FOR UPDATE',[$id]);
        $row=$q->fetch_assoc();$q->free();
        $status='created';$job=0;
        if (!$row || (int)$row['state']!==3) $status='missing';
        elseif ($edit && $row['type']==='forwardall') $status='forwarded';
        elseif ($edit && mb_strlen($input,'UTF-8')>($row['type']==='text'?4096:1024)) $status='too_long';
        else {
            if ($action==='pin' || $action==='unpin') {
                $q=achbotBroadcastQuery($db,'SELECT COUNT(*) AS total, SUM(deleted=1) AS removed FROM bc_messages WHERE broadcast_id=?',[$id]);
                $counts=$q->fetch_assoc();$q->free();
                if ((int)$counts['total']>0 && (int)$counts['removed']>=(int)$counts['total']) $status='deleted';
            }
            if ($status==='created') {
                $q=achbotBroadcastQuery($db,'SELECT id FROM bc_jobs WHERE broadcast_id=? AND action=? AND state IN (1,2) LIMIT 1 FOR UPDATE',[$id,$action]);
                $existing=$q->fetch_assoc();$q->free();
                if ($existing) $status='active';
            }
            if ($status==='created') {
                achbotBroadcastQuery($db,'INSERT INTO bc_jobs (broadcast_id,action,new_text,new_html,`offset`,state,created_at,updated_at)
                    VALUES (?,?,?,?,0,1,NOW(),NOW())',[$id,$action,$edit?$input:null,$edit?$html:null]);
                $job=(int)$db->insert_id;
                if ($job<=0 || $db->affected_rows!==1) throw new RuntimeException('broadcast_action_insert_unconfirmed');
                if ($edit)
                    achbotBroadcastQuery($db,'UPDATE send_list SET text=?,text_html=? WHERE id=?',[$input,$html,$id]);
            }
        }
        if ($editor!==null && $status!=='too_long')
            \AchBot\Telegram\Conversation\writeEditorLocked($db,$editor,'none',null,$update);
        if (!$db->commit()) throw new RuntimeException('broadcast_action_commit_unconfirmed');
        $begun=false;
        return ['status'=>$status,'job'=>$job];
    } catch (Throwable $error) {
        if ($begun) try {$db->rollback();} catch (Throwable $ignored) {}
        if (!($error instanceof DomainException))
            error_log('achbot_broadcast_action_unconfirmed broadcast_id='.$id.' class='.get_class($error));
        return null;
    }
}

function achbotBroadcastRecordAck(mysqli $db,int $id,int $uid,int $messageId): void {
    if ($id<=0 || $uid<=0 || $messageId<=0)
        throw new InvalidArgumentException('broadcast_ack_identity_invalid');
    achbotBroadcastQuery($db,"INSERT INTO bc_messages(broadcast_id,userid,message_id)
        VALUES (?,?,?) ON DUPLICATE KEY UPDATE message_id=VALUES(message_id),
        deleted=0,deleted_at=NULL",[$id,$uid,$messageId]);
}


/** Only explicit blocked-user evidence may mark the ordinary user step.
 * Preserve protected in-progress admin workflows and intentional bans. */
function achbotBroadcastMarkBlocked(mysqli $db,int $uid): void {
    achbotBroadcastQuery($db,"UPDATE users SET step='Bot_Blocked'
        WHERE userid=? AND (step IS NULL OR
            (step NOT IN ('banned','Bot_Blocked')
            AND step NOT LIKE 'achbotDirectGift%' AND step NOT LIKE 'achbotExempt:%'))",[$uid]);
}
