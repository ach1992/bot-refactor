<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/Notices.php';
require_once __DIR__.'/../Telegram/Conversation.php';

function achbotTicketRow(mysqli $db,int $actor,int $id,bool $staff,bool $lock=false): ?array {
    if ($staff && !($lock?achbotAdminCanLocked($db,$actor,(int)$GLOBALS['admin'],'operations'):
        achbotAdminCanFor($db,$actor,(int)$GLOBALS['admin'],'operations'))) return null;
    $ticket=achbotProviderRow($db,'SELECT * FROM chats WHERE id=?'.($lock?' FOR UPDATE':''),'i',[$id]);
    return $ticket && ($staff || (int)$ticket['user_id']===$actor)?$ticket:null;
}
function achbotTicketCategory(mysqli $db,int $id,bool $lock=false): ?array {
    return achbotProviderRow($db,"SELECT id,value FROM setting WHERE id=? AND BINARY type=BINARY 'TICKETS_CATEGORY'".($lock?' FOR UPDATE':''),'i',[$id]);
}
function achbotTicketEditor(string $step): bool {
    return preg_match('/^(?:(?:newTicket|sendTicket|sendMsg|answer)_[1-9][0-9]*_[1-9][0-9]*|addTicketCategory_[1-9][0-9]*)$/D',$step)===1;
}
function achbotTicketLeaveStep(mysqli $db,int $actor,array $cached): bool {
    if (!achbotTicketEditor((string)($cached['step']??''))) return true;
    return \AchBot\Telegram\Conversation\transitionEditor($db,$actor,$cached)!==null;
}
/** Navigation-only CAS. No dependence on existence/deletion of old prompt. */
function achbotTicketBeginStep(mysqli $db,int $actor,array $cached,string $kind,int $id,int $source,$update): bool {
    try {
        if (!$db->begin_transaction()) return false;
        $row=\AchBot\Telegram\Conversation\lockEditor($db,$actor,$cached);
        if ($kind==='addTicketCategory') {
            if (!achbotAdminCanLocked($db,$actor,(int)$GLOBALS['admin'],'operations')) throw new DomainException('ticket_category_denied');
        } elseif ($kind==='newTicket') {
            if (!achbotTicketCategory($db,$id,true)) throw new DomainException('ticket_category_missing');
        } else {
            $ticket=achbotTicketRow($db,$actor,$id,$kind==='answer',true);
            if (!$ticket || (int)$ticket['state']===2) throw new DomainException('ticket_closed');
        }
        \AchBot\Telegram\Conversation\writeEditorLocked($db,$row,$kind.($kind==='addTicketCategory'?'':'_'.$id).'_'.$source,null,$update);
        if (!$db->commit()) throw new RuntimeException('ticket_step_commit');
        return true;
    } catch (Throwable $e) {try {$db->rollback();}catch(Throwable $ignored){} return false;}
}
function achbotTicketAcceptTitle(mysqli $db,int $actor,array $cached,int $category,object $message): bool {
    $title=$message->text??null;
    if (!is_string($title) || trim($title)==='' || mb_strlen($title,'UTF-8')>1000) return false;
    try {
        if (!$db->begin_transaction()) return false;
        $row=\AchBot\Telegram\Conversation\lockEditor($db,$actor,$cached);
        if (!achbotTicketCategory($db,$category,true)) throw new DomainException('ticket_category_missing');
        \AchBot\Telegram\Conversation\writeEditorLocked($db,$row,'sendTicket_'.$category.'_'.$message->message_id,$title);
        if (!$db->commit()) throw new RuntimeException('ticket_title_commit');
        return true;
    } catch(Throwable $e) {try {$db->rollback();}catch(Throwable $ignored){} return false;}
}
/** Match only an existing reply editor for this exact ticket. */
function achbotTicketReplyEditor(array $row,int $id): bool {
    return preg_match('/^(?:sendMsg|answer)_'.$id.'_[1-9][0-9]*$/D',$row['step'])===1;
}
function achbotTicketMessageReference(int $ticket,int $message): string {return 'ticket:'.$ticket.':message:'.$message;}

/** Ticket/message, state, consumed editor and recipient packets commit together.
 * Telegram calls are never inside this transaction. The original incoming
 * chat/message pair prevents replay even after entering a different wizard. */
function achbotTicketSubmit(mysqli $db,int $actor,array $cached,string $kind,int $id,object $source,?array $input): ?array {
    $staff=$kind==='answer';$new=$kind==='sendTicket';$mid=$source->message_id;
    try {
        achbotPaymentEvidenceRequireAutocommit($db);
        if (!$db->begin_transaction()) throw new RuntimeException('ticket_begin');
        $row=\AchBot\Telegram\Conversation\lockEditor($db,$actor,$cached);
        // A definitive closure retires only this exact current reply editor,
        // including when another actor closed the ticket. A missing/failed
        // lookup is not evidence of closure.
        if (!$new) {
            $ticket=achbotTicketRow($db,$actor,$id,$staff,true);
            if (!$ticket) throw new DomainException('ticket_unavailable');
            if ((int)$ticket['state']===2) {
                if (!achbotTicketReplyEditor($row,$id)) throw new DomainException('ticket_editor_changed');
                \AchBot\Telegram\Conversation\writeEditorLocked($db,$row,'none',null);
                if (!$db->commit()) throw new RuntimeException('ticket_closed_editor_commit');
                return ['state'=>'closed'];
            }
        }
        if ($input===null) {$db->rollback();return ['state'=>'invalid'];}
        achbotOperationalReportRequireReady($db);
        if (achbotProviderRow($db,'SELECT id FROM chats_info WHERE source_chat_id=? AND source_message_id=?','ii',[$actor,$mid]))
            throw new DomainException('ticket_message_replayed');
        if ($new) {
            $category=achbotTicketCategory($db,$id,true);
            $title=$row['temp'];
            if (!$category || !is_string($title) || trim($title)==='' || mb_strlen($title,'UTF-8')>1000 ||
                mb_strlen((string)$category['value'],'UTF-8')>1000) throw new DomainException('ticket_draft_invalid');
            $s=achbotProviderStatement($db,'INSERT INTO chats(user_id,create_date,title,category,state,rate) VALUES(?,?,?,?,0,0)',
                'iiss',[$actor,time(),$title,$category['value']]);
            $id=$s->insert_id;$s->close();
            $ticket=achbotTicketRow($db,$actor,$id,false,true);
            if (!$ticket) throw new RuntimeException('ticket_create_unconfirmed');
        }
        $s=achbotProviderStatement($db,'INSERT INTO chats_info(chat_id,sent_date,msg_type,text,source_chat_id,source_message_id,content_kind) VALUES(?,?,?,?,?,?,?)',
            'iissiis',[$id,time(),$staff?'ADMIN':'USER',$input['stored'],$actor,$mid,$input['kind']]);
        $msgId=$s->insert_id;$s->close();
        $message=achbotProviderRow($db,'SELECT * FROM chats_info WHERE id=?','i',[$msgId]);
        $s=achbotProviderStatement($db,'UPDATE chats SET state=? WHERE id=? AND state<>2','ii',[$staff?1:0,$id]);$s->close();
        $body=achbotTicketMessageBody($ticket,$input,$row,$staff,$new);
        $ref=achbotTicketMessageReference($id,$msgId);
        achbotTicketStageLocked($db,$ticket,$actor,$ref,
            achbotTicketPackets($body,achbotTicketReplyKeys($id,!$staff),$input['photo']),!$staff,$message);
        \AchBot\Telegram\Conversation\writeEditorLocked($db,$row,'none',null);
        if (!$db->commit()) throw new RuntimeException('ticket_commit_unknown');
        return ['state'=>'submitted','ref'=>$ref];
    } catch (Throwable $e) {
        try {$db->rollback();}catch(Throwable $ignored){}
        error_log('achbot_ticket_submit_unconfirmed actor='.$actor.' class='.get_class($e));
        return null;
    }
}

/** Closure is a single transition under the same ticket row lock as replies.
 * Replayed close does not send duplicate rating prompts. */
function achbotTicketClose(mysqli $db,int $actor,int $id,bool $staff,array $cached): ?array {
    try {
        achbotPaymentEvidenceRequireAutocommit($db);
        if (!$db->begin_transaction()) throw new RuntimeException('ticket_begin');
        $row=\AchBot\Telegram\Conversation\lockEditor($db,$actor,$cached);
        $ticket=achbotTicketRow($db,$actor,$id,$staff,true);
        if (!$ticket) throw new DomainException('ticket_unavailable');
        $retire=achbotTicketReplyEditor($row,$id);
        if ($retire) \AchBot\Telegram\Conversation\writeEditorLocked($db,$row,'none',null);
        if ((int)$ticket['state']===2) {
            if (!$db->commit()) throw new RuntimeException('ticket_closed_editor_commit');
            return ['ref'=>null,'editor_closed'=>$retire];
        }
        achbotOperationalReportRequireReady($db);
        $s=achbotProviderStatement($db,'UPDATE chats SET state=2 WHERE id=? AND state<>2','i',[$id]);
        $n=$s->affected_rows;$s->close();
        if ($n!==1) throw new RuntimeException('ticket_close_conflict');
        $ref='ticket:'.$id.':close';
        $body=($staff?'['.achbotTicketEscape($ticket['title']).'] <i>'.achbotTicketEscape($ticket['category'])."</i>\n\n":
            achbotMessageHtml('support.customer_conversation.user_all_tickets.title_category.f58d7d9818',['title'=>$ticket['title'],'category'=>$ticket['category']])).
            achbotMessage('support.customer_conversation.user_all_tickets.text.54487cb6cb');
        achbotTicketStageLocked($db,$ticket,$actor,$ref,achbotTicketPackets($body,achbotTicketRateKeys($id)),false);
        if (!$staff) {
            $keys=achbotTicketKeys([
                [['text'=>(string)$actor,'callback_data'=>'wizwizch'],['text'=>achbotMessage('labels.telegram_numeric_id'),'callback_data'=>'wizwizch']],
                [['text'=>(string)$row['name'],'callback_data'=>'wizwizch'],['text'=>achbotMessage('labels.customer_name'),'callback_data'=>'wizwizch']],
                [['text'=>(string)$ticket['title'],'callback_data'=>'wizwizch'],['text'=>achbotMessage('support.customer_conversation.user_all_tickets.button.74190f4bb0'),'callback_data'=>'wizwizch']],
                [['text'=>(string)$ticket['category'],'callback_data'=>'wizwizch'],['text'=>achbotMessage('support.categories.tickets_category.button.0fc3aac224'),'callback_data'=>'wizwizch']]
            ]);
            achbotTicketStageLocked($db,$ticket,$actor,$ref,achbotTicketPackets(achbotMessage('support.customer_conversation.user_all_tickets.message.2baf15e3c0'),$keys),true);
        }
        if (!$db->commit()) throw new RuntimeException('ticket_close_commit_unknown');
        return ['ref'=>$ref,'editor_closed'=>$retire];
    } catch(Throwable $e) {try {$db->rollback();}catch(Throwable $ignored){}return null;}
}
/** Keep existing ability to change a rating from a new click, never allow
 * the same Telegram update to create another notice. */
function achbotTicketRate(mysqli $db,int $actor,int $id,int $rate,int $updateId,array $cached): ?string {
    if ($rate<1 || $rate>5 || $updateId<=0) return null;
    $ref='ticket:'.$id.':rate:'.$updateId;
    try {
        achbotPaymentEvidenceRequireAutocommit($db);achbotOperationalReportRequireReady($db);
        if (!$db->begin_transaction()) throw new RuntimeException('ticket_begin');
        $row=\AchBot\Telegram\Conversation\lockEditor($db,$actor,$cached);
        $ticket=achbotTicketRow($db,$actor,$id,false,true);
        if (!$ticket || (int)$ticket['state']!==2 ||
            achbotProviderRow($db,'SELECT id FROM payment_followup_intents WHERE operational_ref=? LIMIT 1','s',[$ref]))
            throw new DomainException('ticket_rate_stale');
        $s=achbotProviderStatement($db,'UPDATE chats SET rate=? WHERE id=? AND state=2','ii',[$rate,$id]);$s->close();
        $body=achbotMessageHtml('support.customer_conversation.user_all_tickets.text.497743bb13',[
            'from_id'=>$actor,'first_name'=>$row['name'],'username'=>$row['username'],'title'=>$ticket['title'],'category'=>$ticket['category'],'rate'=>$rate]);
        $keys=achbotTicketKeys([[['text'=>achbotMessage('support.customer_conversation.user_all_tickets.button.105e0409bc'),'callback_data'=>'wizwizch']]]);
        achbotTicketStageLocked($db,$ticket,$actor,$ref,achbotTicketPackets($body,$keys),true);
        if (!$db->commit()) throw new RuntimeException('ticket_rate_commit_unknown');
        return $ref;
    } catch(Throwable $e) {try {$db->rollback();}catch(Throwable $ignored){}return null;}
}
