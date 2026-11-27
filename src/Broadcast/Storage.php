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
