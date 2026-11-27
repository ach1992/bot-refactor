<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/../../messages/loader.php';
require_once __DIR__.'/../Telegram/Presentation.php';
require_once __DIR__.'/../Telegram/Markup.php';
require_once __DIR__.'/../Subscription/Presentation.php';

function achbotTicketEscape($value): string {
    return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
}
function achbotTicketKeys(array $rows): string {
    return \AchBot\Telegram\Markup\inlineKeyboard($rows);
}
function achbotTicketInput(object $message): ?array {
    if (is_string($message->text??null) && $message->text!=='')
        return ['kind'=>'text','stored'=>$message->text,
            'html'=>\AchBot\Telegram\Presentation\incomingHtml($message->text,(array)($message->entities??[])),'photo'=>null];
    $photo=$message->photo??null;
    if (!is_array($photo) || !$photo) return null;
    $file=end($photo)->file_id??null;$caption=$message->caption??'';
    if (!is_string($file) || $file==='' || !is_string($caption)) return null;
    return ['kind'=>'photo','stored'=>json_encode(['file_id'=>$file,'caption'=>$caption],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
        'html'=>\AchBot\Telegram\Presentation\incomingHtml($caption,(array)($message->caption_entities??[])),'photo'=>$file];
}
/** Current text is literal even when it looks like historical photo JSON.
 * Old records have no kind marker; retain the original photo representation. */
function achbotTicketContent(array $message): array {
    $raw=(string)($message['text']??'');$kind=$message['content_kind']??null;
    $photo=$kind==='text'?null:json_decode($raw,true);
    if (is_array($photo) && is_string($photo['file_id']??null) && $photo['file_id']!=='' &&
        is_string($photo['caption']??null) && ($kind==='photo' || ($kind===null && count($photo)===2)))
        return ['text'=>$photo['caption'],'photo'=>$photo['file_id']];
    return ['text'=>$raw,'photo'=>null];
}
function achbotTicketReplyKeys(int $id,bool $staff): string {
    if ($staff) return achbotTicketKeys([[['text'=>achbotMessage('support.create_ticket.button.3b90dccc67'),'callback_data'=>'reply_'.$id]]]);
    return achbotTicketKeys([[
        ['text'=>achbotMessage('support.admin_reply.button.f8a34e79d9'),'callback_data'=>'replySupport_'.$id],
        ['text'=>achbotMessage('support.admin_reply.button.173875ae6b'),'callback_data'=>'closeTicket_c_'.$id]
    ]]);
}
function achbotTicketRateKeys(int $id): string {
    return achbotTicketKeys([
        [['text'=>achbotMessage('support.customer_conversation.user_all_tickets.button.67c753a13d'),'callback_data'=>"rate_{$id}_1"]],
        [['text'=>achbotMessage('support.customer_conversation.user_all_tickets.button.6f4e0b97a2'),'callback_data'=>"rate_{$id}_2"]],
        [['text'=>achbotMessage('support.customer_conversation.user_all_tickets.button.e26806a4af'),'callback_data'=>"rate_{$id}_3"]],
        [['text'=>achbotMessage('support.customer_conversation.user_all_tickets.button.3ed1f2e72a'),'callback_data'=>"rate_{$id}_4"]],
        [['text'=>achbotMessage('support.customer_conversation.user_all_tickets.button.3d61f01bbd'),'callback_data'=>"rate_{$id}_5"]]
    ]);
}
/** No truncation of actual customer content; only real Telegram wire limits.
 * A photo owns its first caption segment, with overflow sent as text. */
function achbotTicketPackets(string $body,?string $keys,?string $photo=null): array {
    $parts=\AchBot\Subscription\Presentation\messageParts($body,$photo===null?4096:1024);
    $out=[];
    foreach ($parts as $i=>$part) {
        $packet=['text'=>$part,'keys'=>$i===count($parts)-1?$keys:null,'mode'=>'HTML'];
        if ($photo!==null && $i===0) $packet['photo']=$photo;
        $out[]=$packet;
    }
    return $out;
}
function achbotTicketSendPackets(int $actor,array $packets): void {
    foreach ($packets as $p) try {
        if (isset($p['photo'])) sendPhoto($p['photo'],$p['text'],$p['keys'],'HTML',$actor);
        else sendMessage($p['text'],$p['keys'],'HTML',$actor);
    } catch (Throwable $e) {
        error_log('achbot_ticket_view_deferred actor='.$actor.' class='.get_class($e));
    }
}
function achbotTicketMessageBody(array $ticket,array $input,array $profile,bool $staff,bool $new): string {
    if ($staff) return '['.achbotTicketEscape($ticket['title']).'] <i>'.achbotTicketEscape($ticket['category'])."</i>\n\n".$input['html'];
    $actor=(int)$ticket['user_id'];
    $values=['from_id'=>$actor,'first_name'=>$profile['name']??'','username'=>$profile['username']??'','from_id_2'=>$actor,'ticketCat'=>$ticket['category']];
    if ($new) {
        $body=achbotMessageHtml('support.create_ticket.txt.e819afcdf4',$values).achbotTicketEscape($ticket['title']);
        return $body.($input['kind']==='text'?achbotMessage('support.create_ticket.text.fe45cc8381',['text'=>$input['html']]):
            achbotMessage('support.create_ticket.text.4479736037',['caption'=>$input['html']]));
    }
    $values['ticketTitle']=$ticket['title'];
    $body=achbotMessageHtml('support.customer_conversation.user_all_tickets.txt.54b5fecc08',$values);
    return $body.($input['kind']==='text'?achbotMessage('support.customer_conversation.user_all_tickets.text.a79a2fa69e',['text'=>$input['html']]):
        achbotMessage('support.customer_conversation.user_all_tickets.text.46d8580795',['caption'=>$input['html']]));
}
