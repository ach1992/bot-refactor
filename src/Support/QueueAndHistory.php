<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/Tickets.php';

/** Same query and renderer for first and subsequent pages. Read only the
 * requested page and its lookahead; historical rows without a body remain visible. */
function achbotTicketPage(mysqli $db,int $actor,bool $staff,string $type,int $offset): array {
    if ($staff && !achbotAdminCanFor($db,$actor,(int)$GLOBALS['admin'],'operations')) return [];
    $where=match($type) {'2'=>'c.state<>2','0'=>'c.state=0','all'=>'1=1',default=>'1=0'};
    $types=$staff?'i':'ii';$args=$staff?[$offset]:[$actor,$offset];
    if (!$staff) $where.=' AND c.user_id=?';
    $s=achbotProviderStatement($db,"SELECT c.*,u.name AS customer_name,
        m.id AS last_id,m.msg_type,m.text,m.content_kind FROM chats c
        LEFT JOIN users u ON u.userid=c.user_id
        LEFT JOIN chats_info m ON m.id=(SELECT x.id FROM chats_info x WHERE x.chat_id=c.id ORDER BY x.sent_date DESC,x.id DESC LIMIT 1)
        WHERE ".$where." ORDER BY c.state ASC,c.create_date DESC,c.id DESC LIMIT 6 OFFSET ?",$types,$args);
    $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
    return $rows;
}
function achbotTicketListKeys(array $ticket,bool $staff): string {
    $id=(int)$ticket['id'];$scope=$staff?'s':'c';$rows=[];
    if ((int)$ticket['state']!==2) $rows[]=[
        ['text'=>($staff?achbotMessage('support.queue_and_history.all_tickets.button.12e9251ccb'):achbotMessage('support.admin_reply.button.173875ae6b')),'callback_data'=>'closeTicket_'.$scope.'_'.$id],
        ['text'=>($staff?achbotMessage('support.create_ticket.button.3b90dccc67'):achbotMessage('support.admin_reply.button.f8a34e79d9')),'callback_data'=>($staff?'reply_':'replySupport_').$id]
    ];
    $rows[]=[['text'=>($staff?achbotMessage('support.queue_and_history.all_tickets.button.ce4f25e31b'):achbotMessage('support.customer_conversation.user_all_tickets.button.2f9a0fbfd0')),'callback_data'=>'latestMsg_'.$scope.'_'.$id]];
    return achbotTicketKeys($rows);
}
function achbotTicketAuthor($type): string {
    return $type==='ADMIN'?achbotMessage('support.customer_conversation.user_all_tickets.text.13ad0eef28'):
        achbotMessage('support.customer_conversation.user_all_tickets.text.5bf4b36161');
}
function achbotTicketListRender(int $actor,array $ticket,bool $staff): void {
    $content=achbotTicketContent($ticket);$sent=$ticket['last_id']===null?'':achbotTicketAuthor($ticket['msg_type']);
    if ($staff) {
        $rate=(int)$ticket['state']===2?achbotMessage('support.queue_and_history.all_tickets.rate.c5509cf4dc').$ticket['rate']:'';
        $body=achbotMessageHtml('support.queue_and_history.all_tickets.message.abf3f55cac',[
            'admin'=>$ticket['user_id'],'username'=>$ticket['customer_name']??'','category'=>$ticket['category'],
            'rate'=>$rate,'title'=>$ticket['title'],'sentType'=>$sent,'lastmsg'=>$content['text']]);
    } elseif ($content['photo']!==null) {
        $body=achbotMessageHtml('support.customer_conversation.user_all_tickets.txt.d10f40bde9',[
            'title'=>$ticket['title'],'category'=>$ticket['category'],'sentType'=>$sent,'caption'=>$content['text']]);
    } else $body=achbotMessageHtml('support.customer_conversation.user_all_tickets.message.dfae2b8b30',[
        'title'=>$ticket['title'],'category'=>$ticket['category'],'sentType'=>$sent,'lastmsg'=>$content['text']]);
    achbotTicketSendPackets($actor,achbotTicketPackets($body,achbotTicketListKeys($ticket,$staff),$content['photo']));
}
function achbotTicketList(mysqli $db,int $actor,bool $staff,string $type,int $offset,?int $moreMessage=null): void {
    $rows=achbotTicketPage($db,$actor,$staff,$type,$offset);
    if ($moreMessage!==null) {
        // Only this positively identified pagination prompt is disposable.
        // No loading placeholder and no cleanup dependency on the next page.
        try {delMessage($moreMessage,$actor);} catch(Throwable $ignored){}
    }
    if (!$rows) {
        alert(($staff?achbotMessage('support.queue_and_history.all_tickets.alert.5974314756'):achbotMessage('support.customer_conversation.user_all_tickets.alert.69716a6209')));
        return;
    }
    foreach (array_slice($rows,0,5) as $row) achbotTicketListRender($actor,$row,$staff);
    if (count($rows)>5) sendMessage(achbotMessage('support.customer_conversation.user_all_tickets.text.7555aab9b2'),
        achbotTicketKeys([[['text'=>achbotMessage('support.customer_conversation.user_all_tickets.button.29582a35e0'),
            'callback_data'=>'moreTicket_'.($staff?'s':'c').'_'.$type.'_'.($offset+5)]]]),'HTML',$actor);
}
function achbotTicketHistory(mysqli $db,int $actor,int $id,bool $staff): void {
    if (!achbotTicketRow($db,$actor,$id,$staff)) {achbotAdminDeny();return;}
    $s=achbotProviderStatement($db,'SELECT * FROM chats_info WHERE chat_id=? ORDER BY sent_date DESC,id DESC LIMIT 10','i',[$id]);
    $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
    if (!$rows) {alert(achbotMessage('support.history_empty'));return;}
    $body='';
    foreach ($rows as $row) {
        $content=achbotTicketContent($row);
        $text=$content['photo']===null?$content['text']:achbotMessage('support.queue_and_history.all_tickets.text.61cf036058').$row['id'];
        $body.='<i>['.achbotTicketAuthor($row['msg_type'])."]</i>\n".achbotTicketEscape($text)."\n\n";
    }
    achbotTicketSendPackets($actor,achbotTicketPackets($body,null));
}
function achbotTicketAttachment(mysqli $db,int $actor,int $id): void {
    $row=achbotProviderRow($db,'SELECT * FROM chats_info WHERE id=?','i',[$id]);
    if (!$row || !achbotAdminTicketAllowed($db,$actor,(int)$GLOBALS['admin'],(int)$row['chat_id'])) {
        achbotAdminDeny();return;
    }
    $content=achbotTicketContent($row);
    if ($content['photo']!==null) {
        // A historical photo may have an empty caption.
        if ($content['text']==='') sendPhoto($content['photo'],'',null,'HTML',$actor);
        else achbotTicketSendPackets($actor,achbotTicketPackets(achbotTicketEscape($content['text']),null,$content['photo']));
    }
}
