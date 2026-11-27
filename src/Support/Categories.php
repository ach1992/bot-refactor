<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/Tickets.php';

function achbotTicketCategories(mysqli $db): array {
    $s=achbotProviderStatement($db,"SELECT id,value FROM setting WHERE BINARY type=BINARY 'TICKETS_CATEGORY' ORDER BY id");
    $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
    return $rows;
}
function achbotTicketCategoryKeys(mysqli $db): string {
    $rows=[[['text'=>achbotMessage('support.categories.tickets_category.button.59ca629220'),'callback_data'=>'wizwizch'],
        ['text'=>achbotMessage('support.categories.tickets_category.button.0fc3aac224'),'callback_data'=>'wizwizch']]];
    $categories=achbotTicketCategories($db);
    foreach ($categories as $category) $rows[]=[
        ['text'=>achbotMessage('support.categories.tickets_category.button.2b2966a54e'),'callback_data'=>'delTicketCat_'.$category['id']],
        ['text'=>$category['value'],'callback_data'=>'wizwizch']];
    if (!$categories) $rows[]=[['text'=>achbotMessage('support.categories.tickets_category.button.7f9f2e3157'),'callback_data'=>'wizwizch']];
    $rows[]=[['text'=>achbotMessage('support.categories.tickets_category.button.ee4edcb19e'),'callback_data'=>'addTicketCategory']];
    $rows[]=[['text'=>achbotMessage('legacy_buttons.back_button'),'callback_data'=>'ticketsList']];
    return achbotTicketKeys($rows);
}
function achbotTicketCategoryRoute(mysqli $db,int $actor,object $source,array $cached,?string $data,string $cancelKey,string $removeKeyboard): bool {
    $step=(string)($cached['step']??'');
    if (!achbotAdminCanFor($db,$actor,(int)$GLOBALS['admin'],'operations')) {achbotAdminDeny();return true;}
    $cid=$source->message_id;
    if ($data==='ticketsList' || $data==='ticketsCategory') {
        if (achbotTicketEditor($step)) {
            if (!achbotTicketLeaveStep($db,$actor,$cached)) return true;
            sendMessage(achbotMessage('legacy_messages.operation_canceled'),$removeKeyboard,'HTML',$actor);
        }
        if ($data==='ticketsList') {
            $keys=achbotTicketKeys([
                [['text'=>achbotMessage('support.categories.tickets_list.button.33d6fa9470'),'callback_data'=>'openTickets'],
                 ['text'=>achbotMessage('support.categories.tickets_list.button.732c0fd101'),'callback_data'=>'newTickets']],
                [['text'=>achbotMessage('support.categories.tickets_list.button.c77cd22d1c'),'callback_data'=>'allTickets'],
                 ['text'=>achbotMessage('support.categories.tickets_list.button.0e6cb76a8c'),'callback_data'=>'ticketsCategory']],
                [['text'=>achbotMessage('legacy_buttons.back_button'),'callback_data'=>'managePanel']]]);
            \AchBot\Telegram\Conversation\replaceTransient($actor,$cid,achbotMessage('support.categories.tickets_list.message.41905ef3f3'),$keys);
        } else {
            \AchBot\Telegram\Conversation\replaceTransient($actor,$cid,achbotMessage('support.categories.tickets_list.button.0e6cb76a8c'),achbotTicketCategoryKeys($db));
        }
        return true;
    }
    if ($data!==null && preg_match('/^delTicketCat_([1-9][0-9]*)$/D',$data,$m)) {
        if (!achbotDeleteContentSetting($db,$actor,(int)$GLOBALS['admin'],(int)$m[1],'ticket')) return true;
        alert(achbotMessage('support.categories.add_ticket_category.alert.e1e7245408'));
        \AchBot\Telegram\Conversation\replaceTransient($actor,$cid,achbotMessage('support.categories.tickets_list.button.0e6cb76a8c'),achbotTicketCategoryKeys($db));
        return true;
    }
    if ($data==='addTicketCategory') {
        if (achbotTicketBeginStep($db,$actor,$cached,'addTicketCategory',0,$cid)) {
            sendMessage(achbotMessage('support.categories.add_ticket_category.message.f17638132c'),$cancelKey,'HTML',$actor);
            try {delMessage($cid,$actor);} catch(Throwable $ignored){}
        }
        return true;
    }
    if ($data!==null || !preg_match('/^addTicketCategory_([1-9][0-9]*)$/D',$step,$m) || $cid<=(int)$m[1]) return true;
    $title=$source->text??null;
    if (!is_string($title) || trim($title)==='' || mb_strlen($title,'UTF-8')>1000) {
        sendMessage(achbotMessage('support.create_ticket.message.19602d80df'),null,'HTML',$actor);return true;
    }
    try {
        if (!$db->begin_transaction()) throw new RuntimeException('ticket_category_begin');
        $row=achbotTicketActorLocked($db,$actor,$cached);
        if (!achbotAdminCanLocked($db,$actor,(int)$GLOBALS['admin'],'operations')) throw new DomainException('ticket_category_denied');
        $s=achbotProviderStatement($db,"INSERT INTO setting(type,value) VALUES('TICKETS_CATEGORY',?)",'s',[$title]);$s->close();
        achbotTicketWriteStep($db,$row,'none',null);
        if (!$db->commit()) throw new RuntimeException('ticket_category_commit');
    } catch(Throwable $e) {
        try {$db->rollback();}catch(Throwable $ignored){}
        sendMessage(achbotMessage('support.action_unconfirmed'),null,'HTML',$actor);return true;
    }
    sendMessage(achbotMessage('legacy_messages.saved_successfuly'),$removeKeyboard,'HTML',$actor);
    sendMessage(achbotMessage('support.categories.tickets_list.button.0e6cb76a8c'),achbotTicketCategoryKeys($db),'HTML',$actor);
    return true;
}
