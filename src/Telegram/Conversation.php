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

/** A Telegram callback does not by itself establish that its data still
 * exists on the bot's message. Recover unknown registrations only when
 * the actual bot identity and the exact original keyboard prove it. */
function verifiedCallback($update,int $actor,int $message): bool {
    $source=source($update,$actor,true);
    $botId=explode(':',(string)($GLOBALS['botToken']??''),2)[0];
    if (!$source || $source->message_id!==$message || preg_match('/\A[0-9]+\z/D',$botId)!==1 ||
        ($source->from->is_bot??false)!==true || (string)($source->from->id??'')!==$botId) return false;
    $action=$update->callback_query->data;
    foreach ($source->reply_markup->inline_keyboard??[] as $buttons) foreach ($buttons as $button)
        if (($button->callback_data??null)===$action) return true;
    return false;
}

/** Recognize the existing explicit Home/shortcut actions before an ordinary
 * configuration editor can consume them as a credential or field value. */
function homeRequested($update,int $actor): bool {
    $callback=isset($update->callback_query);
    $message=source($update,$actor,$callback);
    if (!$message) return false;
    if ($callback) {
        $data=$update->callback_query->data;
        if (in_array($data,['mainMenu','navHome','evidenceHome'],true) ||
            preg_match('/^ph_[A-Za-z0-9_-]{1,50}$/D',$data)) return true;
    } else {
        $text=$message->text??null;
        if (is_string($text) && (preg_match('~^/[Ss]tart(?:@[A-Za-z0-9_]{1,32})?(?:\s|$)~D',$text) ||
            $text===\achbotMessage('legacy_buttons.back_to_main'))) return true;
    }
    require_once __DIR__.'/UserProfile.php';
    return \achbotCustomerShortcut($update,$actor)!==null;
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
    if (\AchBot\Telegram\Response\messageDeliveryOutcome($result,(string)$actor,$message)['result']==='acknowledged') return $message;
    if (!canReplaceRejectedEdit($result)) return null;
    $replacement=\sendMessage($text,$keys,'HTML',$actor);
    $id=acknowledgedMessage($replacement,$actor);
    if ($id===null) return null;
    try {\delMessage($message,$actor);} catch (\Throwable $ignored) {}
    return $id;
}

/** Only target-specific rejection warrants a new transient screen.
 * A malformed text/markup, 429, or ambiguous transport cannot. */
function canReplaceRejectedEdit($response): bool {
    return \AchBot\Telegram\Response\editTargetFailure($response)!==null;
}

function referralSettingEditor(string $step): ?array {
    if (preg_match('/^(editInviteAmount|editInviteBannerText)_([1-9][0-9]*)$/D',$step,$m)!==1) return null;
    return ['action'=>$m[1],'source'=>(int)$m[2]];
}
/** The editor generation records the authenticated opening update and its
 * local admission time. A delayed older update may see the NEW actor row,
 * so a row CAS alone does not establish input ownership. */
function editorOpeningGeneration(array $cached): ?array {
    if (!referralSettingEditor((string)($cached['step']??''))) return null;
    if (preg_match('/^([1-9][0-9]*):([1-9][0-9]*)$/D',(string)($cached['temp']??''),$m)!==1)
        return null;
    return ['update'=>(int)$m[1],'opened_at'=>(int)$m[2]];
}
function updateAfterOpening(array $generation,$updateId,$eventTime): bool {
    if (!is_int($updateId) || $updateId<=0) return false;
    if ($updateId>$generation['update']) return true;
    // Telegram may restart update IDs after a week without updates; a queued
    // opening update can be delivered up to 24 hours late. A lower ID can
    // therefore be new after that minimum interval, not an editor expiry.
    // For typed input use Telegram's own message date, not processing time.
    // https://core.telegram.org/bots/api#update
    return is_int($eventTime) && $eventTime >= $generation['opened_at'] + (7-1)*86400;
}
/**
 * Only generation-bearing editors require a newer authenticated event.
 * Other editors retain their existing ownership rules; a malformed
 * generation can still be abandoned but cannot authorize a referral save.
 */
function editorAcceptsUpdate(array $row,int $actor,$update): bool {
    $generation=editorOpeningGeneration($row);
    if ($generation===null) return true;
    $callback=isset($update->callback_query);
    $message=source($update,$actor,$callback);
    return $message!==null &&
        updateAfterOpening($generation,$update->update_id??null,$callback?time():($message->date??null));
}

/** A dedicated route which has already authenticated its callback source
 * may use the same generation decision while holding its own actor lock. */
function editorAcceptsSequence(array $row,$updateId,$eventTime): bool {
    $generation=editorOpeningGeneration($row);
    return $generation===null || updateAfterOpening($generation,$updateId,$eventTime);
}

/** Shared editor ownership for ordinary local draft transitions. The actor
 * row is also the serialization boundary of stronger financial/Native
 * editors. Their protected states remain owned by their dedicated routes. */
function lockEditor(\mysqli $db,int $actor,array $cached): array {
    if ($actor<=0 || (int)($cached['id']??0)<=0 ||
        (int)($cached['userid']??0)!==$actor || !is_string($cached['step']??null))
        throw new \DomainException('conversation_actor_unverified');
    require_once __DIR__.'/../Panel/ProviderStorage.php';
    $s=\achbotProviderStatement($db,'SELECT * FROM users FORCE INDEX (idx_users_userid_v1) WHERE userid=? LIMIT 2 FOR UPDATE','i',[$actor]);
    $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
    $row=$rows[0]??[];
    if (count($rows)!==1 || (int)$row['id']!==(int)$cached['id'] ||
        $row['step']!==$cached['step'] ||
        (array_key_exists('temp',$cached) && $row['temp']!==$cached['temp']) ||
        $row['step']==='banned' || str_starts_with($row['step'],'achbotDirectGift') ||
        str_starts_with($row['step'],'achbotExempt:'))
        throw new \DomainException('conversation_editor_changed');
    return $row;
}
function writeEditorLocked(\mysqli $db,array $row,string $step,?string $temp,$update=null): void {
    if (!editorAcceptsUpdate($row,(int)$row['userid'],$update))
        throw new \DomainException('conversation_editor_update_stale');
    if ($row['step']===$step && $row['temp']===$temp) return;
    $s=\achbotProviderStatement($db,
        'UPDATE users SET step=?,temp=? WHERE id=? AND userid=? AND BINARY step=BINARY ?',
        'ssiis',[$step,$temp,(int)$row['id'],(int)$row['userid'],$row['step']]);
    $changed=$s->affected_rows;$s->close();
    if ($changed!==1) throw new \RuntimeException('conversation_editor_write_unconfirmed');
}
/** No Telegram request within this transaction. No old-message probe, no
 * durable journal for disposable UI. Return the consumed row on success. */
function transitionEditor(\mysqli $db,int $actor,array $cached,string $next='none',?string $temp=null,?callable $mutate=null,$update=null): ?array {
    $started=false;
    try {
        $status=$db->query('SELECT @@in_transaction AS active');
        if (!$status || (int)($status->fetch_assoc()['active']??1)!==0) return null;
        if (!$db->begin_transaction()) throw new \RuntimeException('conversation_begin');
        $started=true;
        $row=lockEditor($db,$actor,$cached);
        if (!editorAcceptsUpdate($row,$actor,$update)) throw new \DomainException('conversation_editor_update_stale');
        if ($mutate!==null) $mutate($db,$row);
        writeEditorLocked($db,$row,$next,$temp,$update);
        if (!$db->commit()) throw new \RuntimeException('conversation_commit');
        $started=false;
        return $row;
    } catch (\Throwable $e) {
        if ($started) try {$db->rollback();} catch (\Throwable $ignored) {}
        if (!($e instanceof \DomainException))
            error_log('achbot_conversation_transition_unconfirmed actor='.$actor.' class='.get_class($e));
        return null;
    }
}

/** Completion feedback is a new message, not an edit: Telegram only accepts
 * ReplyKeyboardRemove on a send. The next inline menu remains independent.
 * Never send this for validation failures or unrelated asynchronous notices. */
function removeReplyKeyboard(string $text,?int $actor=null): void {
    try {\sendMessage($text,json_encode(['remove_keyboard'=>true],JSON_THROW_ON_ERROR),'HTML',$actor);}
    catch (\Throwable $e) {error_log('achbot_reply_keyboard_cleanup_deferred class='.get_class($e));}
}

/** A caller with a real typed input or private button may transition a
 * disposable editor. It must not accidentally use a callback's old text. */
function transitionFromUpdate(\mysqli $db,int $actor,array $cached,$update,
    string $next='none',?string $temp=null): ?array {
    if (!source($update,$actor,isset($update->callback_query))) return null;
    return transitionEditor($db,$actor,$cached,$next,$temp,update:$update);
}
