<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/QueueAndHistory.php';
require_once __DIR__.'/Categories.php';

function achbotSupportRoute(mysqli $db,int $actor,$update,array $cached,string $cancelKey,string $removeKeyboard): bool {
    $data=$update->callback_query->data??null;
    $step=(string)($cached['step']??'');
    $text=$update->message->text??null;
    $callback=is_string($data) && preg_match('/^(?:supportSection$|usersNewTicket$|usersOpenTickets$|userAllTickets$|openTickets$|newTickets$|allTickets$|ticketsList$|ticketsCategory$|addTicketCategory$|delTicketCat_|supportCat|replySupport_|reply_|moreTicket_|closeTicket_|latestMsg_|rate_)/D',$data);
    $typed=preg_match('/^(?:newTicket|sendTicket|sendMsg|answer)_([1-9][0-9]*)_([1-9][0-9]*)$/D',$step,$stepMatch);
    $categoryEditor=preg_match('/^addTicketCategory_[1-9][0-9]*$/D',$step)===1;
    $cancel=achbotTicketEditor($step) && $text===achbotMessage('legacy_buttons.cancel') && !isset($update->callback_query);
    $attachment=is_string($text) && preg_match('/^\/dlPic([1-9][0-9]*)$/D',$text,$picture);
    if (!$callback && !$attachment && !$cancel && ((!$typed && !$categoryEditor) || isset($update->callback_query) ||
        $text===achbotMessage('legacy_buttons.cancel') || $text===achbotMessage('legacy_buttons.back_to_main') ||
        (is_string($text) && preg_match('~^/[Ss]tart(?:\s|$)~D',$text)))) return false;
    $source=\AchBot\Telegram\Conversation\source($update,$actor,(bool)$callback);
    if (!$source || ($callback && ($source->from->is_bot??false)!==true)) {
        achbotAdminDeny();return true;
    }
    $cid=$source->message_id;
    try {
        if ($cancel) {
            if (achbotTicketLeaveStep($db,$actor,$cached)) {
                sendMessage(achbotMessage('legacy_messages.operation_canceled'),$removeKeyboard,'HTML',$actor);
                sendMessage(achbotCurrentMessage('reached_main_menu'),getMainKeys(),'HTML',$actor);
            }
            return true;
        }
        if ($attachment) {achbotTicketAttachment($db,$actor,(int)$picture[1]);return true;}
        if (($callback && (in_array($data,['ticketsList','ticketsCategory','addTicketCategory'],true) || str_starts_with($data,'delTicketCat_'))) ||
            ($categoryEditor && !$callback))
            return achbotTicketCategoryRoute($db,$actor,$source,$cached,$data,$cancelKey,$removeKeyboard,$update);
        if ($callback) {
            if (in_array($data,['supportSection','usersNewTicket','usersOpenTickets','userAllTickets','openTickets','newTickets','allTickets'],true)) {
                if (in_array($data,['openTickets','newTickets','allTickets'],true) &&
                    !achbotAdminCanFor($db,$actor,(int)$GLOBALS['admin'],'operations')) {achbotAdminDeny();return true;}
                if (achbotTicketEditor($step)) {
                    if (!achbotTicketLeaveStep($db,$actor,$cached)) return true;
                    sendMessage(achbotMessage('legacy_messages.operation_canceled'),$removeKeyboard,'HTML',$actor);
                }
            }
            if ($data==='supportSection') {
                $keys=achbotTicketKeys([
                    [['text'=>achbotMessage('support.entry.support_section.button.47b4e6ab96'),'callback_data'=>'usersNewTicket']],
                    [['text'=>achbotMessage('support.entry.support_section.button.a203213e37'),'callback_data'=>'usersOpenTickets'],
                     ['text'=>achbotMessage('support.entry.support_section.button.b15cb57126'),'callback_data'=>'userAllTickets']],
                    [['text'=>achbotMessage('legacy_buttons.back_button'),'callback_data'=>'navHome']]
                ]);
                \AchBot\Telegram\Conversation\replaceTransient($actor,$cid,achbotMessage('support.entry.support_section.message.7089955c27'),$keys);
            } elseif ($data==='usersNewTicket') {
                $categories=achbotTicketCategories($db);
                if (!$categories) {alert(achbotMessage('support.entry.users_new_ticket.alert.46a8c85d10'));return true;}
                $buttons=[];
                foreach ($categories as $category) $buttons[]=['text'=>$category['value'],'callback_data'=>'supportCat'.$category['id']];
                $rows=array_chunk($buttons,2);$rows[]=[['text'=>achbotMessage('legacy_buttons.back_button'),'callback_data'=>'navHome']];
                \AchBot\Telegram\Conversation\replaceTransient($actor,$cid,achbotMessage('support.entry.users_new_ticket.message.adca0ba3c2'),achbotTicketKeys($rows));
            } elseif (preg_match('/^supportCat([1-9][0-9]*)$/D',$data,$m)) {
                if (!achbotTicketBeginStep($db,$actor,$cached,'newTicket',(int)$m[1],$cid,$update)) {alert(achbotMessage('support.entry.users_new_ticket.alert.46a8c85d10'));return true;}
                sendMessage(achbotMessage('legacy_messages.enter_ticket_title'),$cancelKey,'HTML',$actor);
                try {delMessage($cid,$actor);} catch(Throwable $ignored){}
            } elseif (preg_match('/^(replySupport|reply)_([1-9][0-9]*)$/D',$data,$m)) {
                $staff=$m[1]==='reply';
                $ticket=achbotTicketRow($db,$actor,(int)$m[2],$staff);
                if (!$ticket) {achbotAdminDeny();return true;}
                if (!achbotTicketBeginStep($db,$actor,$cached,$staff?'answer':'sendMsg',(int)$m[2],$cid,$update)) {
                    alert(achbotMessage('support.queue_and_history.all_tickets.button.b1eee8adee'));return true;
                }
                // Source is a retained ticket record, not a disposable prompt.
                sendMessage($staff?achbotMessage('support.admin_reply.message.8da2c2756b'):achbotMessage('support.customer_conversation.user_all_tickets.message.13e7a1cdbb'),$cancelKey,'HTML',$actor);
            } elseif (isset(['usersOpenTickets'=>1,'userAllTickets'=>1,'openTickets'=>1,'newTickets'=>1,'allTickets'=>1][$data])) {
                $staff=in_array($data,['openTickets','newTickets','allTickets'],true);
                if ($staff && !achbotAdminCanFor($db,$actor,(int)$GLOBALS['admin'],'operations')) {achbotAdminDeny();return true;}
                $type=match($data){'usersOpenTickets','openTickets'=>'2','newTickets'=>'0',default=>'all'};
                achbotTicketList($db,$actor,$staff,$type,0);
            } elseif (preg_match('/^moreTicket_([cs])_(2|0|all)_([0-9]+)$/D',$data,$m)) {
                $staff=$m[1]==='s';
                if ($staff && !achbotAdminCanFor($db,$actor,(int)$GLOBALS['admin'],'operations')) {achbotAdminDeny();return true;}
                achbotTicketList($db,$actor,$staff,$m[2],(int)$m[3],$cid);
            } elseif (preg_match('/^latestMsg_([cs])_([1-9][0-9]*)$/D',$data,$m)) {
                achbotTicketHistory($db,$actor,(int)$m[2],$m[1]==='s');
            } elseif (preg_match('/^closeTicket_([cs])_([1-9][0-9]*)$/D',$data,$m)) {
                $staff=$m[1]==='s';$id=(int)$m[2];
                if (!achbotTicketRow($db,$actor,$id,$staff)) {achbotAdminDeny();return true;}
                $result=achbotTicketClose($db,$actor,$id,$staff,$cached);
                if ($result===null) {alert(achbotMessage('support.action_unconfirmed'));return true;}
                if ($result['editor_closed'])
                    \AchBot\Telegram\Conversation\removeReplyKeyboard(achbotMessage('support.queue_and_history.all_tickets.button.b1eee8adee'),$actor);
                if ($result['ref']===null) return true;
                try {editKeys($staff?achbotTicketKeys([[['text'=>achbotMessage('support.queue_and_history.all_tickets.button.b1eee8adee'),'callback_data'=>'wizwizch']]]):null,$cid,$actor);} catch(Throwable $ignored){}
                achbotOperationalReportDispatch($db,$result['ref'],8.0);
            } elseif (preg_match('/^rate_([1-9][0-9]*)_([1-5])$/D',$data,$m)) {
                if (!achbotTicketRow($db,$actor,(int)$m[1],false)) {achbotAdminDeny();return true;}
                $ref=achbotTicketRate($db,$actor,(int)$m[1],(int)$m[2],(int)($update->update_id??0),$cached);
                if ($ref!==null) {
                    try {editKeys(null,$cid,$actor);} catch(Throwable $ignored){}
                    alert(achbotMessage('support.customer_conversation.user_all_tickets.message.1d456424c3'));
                    achbotOperationalReportDispatch($db,$ref,8.0);
                }
            } else achbotAdminDeny();
            return true;
        }
        if ($cid<=(int)$stepMatch[2]) return true;
        $kind=substr($step,0,strpos($step,'_'));$id=(int)$stepMatch[1];
        if ($kind==='newTicket') {
            if (achbotTicketAcceptTitle($db,$actor,$cached,$id,$source))
                sendMessage(achbotMessage('legacy_messages.enter_ticket_description'),null,'HTML',$actor);
            else sendMessage(achbotMessage('support.create_ticket.message.19602d80df'),null,'HTML',$actor);
            return true;
        }
        $result=achbotTicketSubmit($db,$actor,$cached,$kind,$id,$source,achbotTicketInput($source));
        if ($result===null) {sendMessage(achbotMessage('support.action_unconfirmed'),null,'HTML',$actor);return true;}
        if ($result['state']==='closed') {
            \AchBot\Telegram\Conversation\removeReplyKeyboard(achbotMessage('support.queue_and_history.all_tickets.button.b1eee8adee'),$actor);
            return true;
        }
        if ($result['state']==='invalid') {sendMessage(achbotMessage('support.create_ticket.message.19602d80df'),null,'HTML',$actor);return true;}
        $ref=$result['ref'];
        // The reply-keyboard removal and inline Home menu are separate
        // Telegram payloads. Acknowledge durable registration, not delivery.
        try {
            sendMessage(achbotMessage('support.create_ticket.message.5c9c3dda00'),$removeKeyboard,'HTML',$actor);
            if ($kind!=='answer') sendMessage(achbotMessage('support.create_ticket.message.b059640ccc'),getMainKeys(),'HTML',$actor);
        } finally {achbotOperationalReportDispatch($db,$ref,8.0);}
        return true;
    } catch(Throwable $e) {
        error_log('achbot_ticket_route_deferred actor='.$actor.' class='.get_class($e));
        return true;
    }
}
