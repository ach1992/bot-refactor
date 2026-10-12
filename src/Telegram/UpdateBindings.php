<?php
/** Legacy update bindings: execute in the including scope, in the original order. */
if(isset($update->message)){
    $from_id    = $update->message->from->id;
    $text       = $update->message->text ?? null;
    $caption    = $update->message->caption ?? null;
    $chat_id    = $update->message->chat->id;
    $message_id = $update->message->message_id;

    $first_name = htmlspecialchars($update->message->from->first_name ?? '');
    $last_name  = htmlspecialchars($update->message->from->last_name ?? '');
    $username   = $update->message->from->username ?? " ندارد ";

    $forward_from_name = null;
    $forward_from_id   = null;
    $reply_text        = null;

    if(isset($update->message->reply_to_message)){
        $reply_text = $update->message->reply_to_message->text ?? null;
        $forward_from_name = $update->message->reply_to_message->forward_sender_name ?? null;
        $forward_from_id   = $update->message->reply_to_message->forward_from->id ?? null;
    }
}
if(isset($update->callback_query)){
    $callbackId = $update->callback_query->id;
    $data = $update->callback_query->data;
    $text = $update->callback_query->message->text;
    $message_id = $update->callback_query->message->message_id;
    $chat_id = $update->callback_query->message->chat->id;
    $chat_type = $update->callback_query->message->chat->type;
    $username = htmlspecialchars($update->callback_query->from->username)?? " ندارد ";
    $from_id = $update->callback_query->from->id;
    $first_name = htmlspecialchars($update->callback_query->from->first_name);
    $markup = json_decode(json_encode($update->callback_query->message->reply_markup->inline_keyboard),true);
}
