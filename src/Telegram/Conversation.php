<?php
declare(strict_types=1);
namespace AchBot\Telegram\Conversation;
require_once __DIR__.'/Response.php';

/** A signed private update identifies the actual actor and conversation.
 * Never treat callback message text as a new human-authored message. */
function source($update,int $actor,bool $callback): ?object {
    if ($actor<=0 || !is_object($update)) return null;
    if ($callback) {
        if (isset($update->message)) return null;
        $frame=$update->callback_query??null;
        $message=$frame->message??null;$sender=$frame->from??null;
        if (!is_string($frame->data??null)) return null;
    } else {
        if (isset($update->callback_query)) return null;
        $message=$update->message??null;$sender=$message->from??null;
    }
    if (!is_object($message) || !is_object($sender) ||
        ($sender->is_bot??false)===true || ($sender->id??null)!==$actor ||
        ($message->chat->type??null)!=='private' ||
        ($message->chat->id??null)!==$actor ||
        !is_int($message->message_id??null) || $message->message_id<=0) return null;
    return $message;
}

/** Only a complete, exact acknowledged Telegram response identifies a
 * disposable new message. Missing response data is not proof of delivery. */
function acknowledgedMessage($response,int $actor): ?int {
    if ($actor<=0) return null;
    return \AchBot\Telegram\Response\acknowledgedMessageIdentity($response,(string)$actor)['message_id']??null;
}

/** Edit the exact caller-classified transient text screen. Unknown outcome,
 * no-op and rate-limit responses do not trigger another send. */
function replaceTransient(int $actor,int $message,string $text,string $keys): ?int {
    $result=\editText($message,$text,$keys,'HTML',$actor);
    if (\AchBot\Telegram\Response\isSuccessful($result) ||
        \AchBot\Telegram\Response\isEditNoop($result)) return $message;
    if (!canReplaceRejectedEdit($result)) return null;
    $replacement=\sendMessage($text,$keys,'HTML',$actor);
    $id=acknowledgedMessage($replacement,$actor);
    if ($id===null) return null;
    \delMessage($message,$actor);
    return $id;
}

/** Only target-specific rejection warrants a new transient screen.
 * A malformed text/markup, 429, or ambiguous transport cannot. */
function canReplaceRejectedEdit($response): bool {
    return \AchBot\Telegram\Response\editTargetFailure($response)!==null;
}
