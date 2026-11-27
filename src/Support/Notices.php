<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/Presentation.php';
require_once __DIR__.'/../Finance/OperationalReports.php';

/** Immutable retained ticket identity plus, for messages, the actual source row.
 * Close/rate reports bind the stable ticket; their original action and copy
 * are sealed at commit rather than reconstructed from a mutable rating. */
function achbotTicketFingerprint(array $ticket,?array $message=null): string {
    $source=[(int)$ticket['id'],(int)$ticket['user_id'],(int)$ticket['create_date'],
        (string)$ticket['title'],(string)$ticket['category']];
    if ($message!==null) $source[]=[(int)$message['id'],(int)$message['chat_id'],(int)$message['sent_date'],
        $message['msg_type'],$message['text'],(int)$message['source_chat_id'],(int)$message['source_message_id'],$message['content_kind']];
    return hash('sha256',json_encode($source,JSON_THROW_ON_ERROR));
}

/** Record the existing private support audience. No payment/report channel
 * receives conversation content. Preferences never grant action authority. */
function achbotTicketStageLocked(mysqli $db,array $ticket,int $actor,string $ref,array $packets,bool $staff,?array $message=null): void {
    $owner=(int)($GLOBALS['admin']??0);
    $event=['reference'=>$ref,'actor'=>$actor,'capability'=>'operations','user'=>(int)$ticket['user_id'],
        'ticket_id'=>(int)$ticket['id'],'ticket_sha256'=>achbotTicketFingerprint($ticket,$message)];
    $targets=[];
    if ($staff) {
        $targets[$owner]='service_admin';$complete=false;
        $staffIds=achbotAdminRecipientCandidates($db,$owner,$complete,true);
        if (!$complete) throw new RuntimeException('ticket_audience_unavailable');
        foreach ($staffIds as $recipient) {
            $complete=true;
            if (achbotAdminNotificationAllowed($db,$recipient,$owner,'general',$complete,true))
                $targets[(int)$recipient]='service_admin';
            if (!$complete) throw new RuntimeException('ticket_audience_unavailable');
        }
    } else $targets[(int)$ticket['user_id']]='customer';
    foreach ($targets as $recipient=>$role) foreach ($packets as $i=>$packet) {
        $stage=$i===0?'ticket_notice':'ticket_notice_p'.$i;
        achbotFollowupStoreLocked($db,null,null,$stage,$role,'send',(string)$recipient,null,
            achbotFollowupOperationalFingerprint($event),$packet,null,$event);
    }
}
/** Current authority and exact original source are separate from recipient ACK.
 * Already-closed ticket messages remain history; their late controls are inert. */
function achbotTicketNoticeValidate(mysqli $db,array $r,array $event,array $packet): ?array {
    $id=$event['ticket_id'];$actor=$event['actor'];$user=$event['user'];$ref=$event['reference'];
    $ticket=achbotProviderRow($db,'SELECT * FROM chats WHERE id=?','i',[$id]);
    if (!$ticket || (int)$ticket['user_id']!==$user) return null;
    if ($r['kind']!=='send' || $r['message_id']!==null) return null;
    $message=null;$toStaff=false;
    if (preg_match('/^ticket:'.$id.':message:([1-9][0-9]*)$/D',$ref,$m)) {
        $message=achbotProviderRow($db,'SELECT * FROM chats_info WHERE id=? AND chat_id=?','ii',[(int)$m[1],$id]);
        if (!$message || (int)$message['source_chat_id']!==$actor ||
            !in_array($message['msg_type'],['USER','ADMIN'],true)) return null;
        $toStaff=$message['msg_type']==='USER';
        if ($toStaff && $actor!==$user) return null;
        $content=achbotTicketContent($message);
        if (isset($packet['photo']) && $packet['photo']!==$content['photo']) return null;
        if ((int)$ticket['state']===2) $packet['keys']=null;
    } elseif ($ref==='ticket:'.$id.':close') {
        if ((int)$ticket['state']!==2 || isset($packet['photo'])) return null;
        $toStaff=$r['role']==='service_admin';
        if ($toStaff && $actor!==$user) return null;
        if (!$toStaff && (int)$ticket['rate']>0) $packet['keys']=null;
    } else {
        if ($actor!==$user || (int)$ticket['state']!==2 || isset($packet['photo'])) return null;
        $toStaff=true;
    }
    if (!hash_equals($event['ticket_sha256'],achbotTicketFingerprint($ticket,$message))) return null;
    if ($toStaff) {
        if ($r['role']!=='service_admin' || (string)(int)$r['recipient']!==$r['recipient']) return null;
        if (!achbotFollowupGeneralRecipientAllowed($db,(int)$r['recipient'],(int)($GLOBALS['admin']??0))) return null;
    } elseif ($r['role']!=='customer' || $r['recipient']!==(string)$user) return null;
    return $packet;
}
