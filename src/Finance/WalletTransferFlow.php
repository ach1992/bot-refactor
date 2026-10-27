<?php
/** AChBot transfer workflow: actor-locked generation and durable replay cursor. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/WalletAdjustments.php';

function achbotWalletTransferSource($update, $actor, $chatId, $messageId, $bound, string $kind): ?array
{
    if (!is_object($update) || !is_int($update->update_id ?? null) || $update->update_id <= 0) return null;
    $keys = array_keys(get_object_vars($update)); sort($keys);
    if ($kind === 'callback') {
        if ($keys !== ['callback_query','update_id'] || !is_object($update->callback_query)) return null;
        $callback = $update->callback_query;
        $sender = $callback->from ?? null;
        $message = $callback->message ?? null;
        if (!is_string($callback->id ?? null) || $callback->id === '' ||
            ($callback->data ?? null) !== 'transferMyWallet' || $bound !== $callback->data) return null;
    } elseif ($kind === 'message') {
        if ($keys !== ['message','update_id']) return null;
        $message = $update->message;
        $sender = is_object($message) ? ($message->from ?? null) : null;
        if (!is_object($message) || !is_string($message->text ?? null) || $message->text !== $bound) return null;
    } else return null;
    $chat = is_object($message) ? ($message->chat ?? null) : null;
    if (!is_object($sender) || !is_object($message) || !is_object($chat) ||
        !is_int($sender->id ?? null) || $sender->id <= 0 || ($sender->is_bot ?? false) === true ||
        $sender->id !== (int)$actor || ($chat->type ?? null) !== 'private' ||
        ($chat->id ?? null) !== $sender->id || $chat->id !== (int)$chatId ||
        !is_int($message->message_id ?? null) || $message->message_id <= 0 ||
        $message->message_id !== (int)$messageId) return null;
    return ['actor_id'=>$sender->id, 'chat_id'=>$chat->id,
            'update_id'=>$update->update_id, 'message_id'=>$message->message_id];
}

function achbotWalletTransferStep(array $state): string
{
    if ($state['stage'] === 'recipient')
        return 'achbotTransferRecipient:' . $state['start_update_id'] . ':' . $state['start_message_floor'];
    if ($state['stage'] === 'amount')
        return 'achbotTransferAmount:' . $state['recipient_id'] . ':' .
            $state['selection_update_id'] . ':' . $state['selection_message_id'] . ':' .
            $state['start_update_id'] . ':' . $state['start_message_floor'];
    return 'none';
}

function achbotWalletTransferPending(string $step): ?array
{
    if (preg_match('/\AachbotTransferRecipient:([1-9][0-9]{0,18}):([1-9][0-9]{0,18})\z/D', $step, $m)) {
        $names = ['start_update_id','start_message_floor']; $stage = 'recipient';
    } elseif (preg_match('/\AachbotTransferAmount:([1-9][0-9]{0,18}):([1-9][0-9]{0,18}):([1-9][0-9]{0,18}):([1-9][0-9]{0,18}):([1-9][0-9]{0,18})\z/D', $step, $m)) {
        $names = ['recipient_id','selection_update_id','selection_message_id','start_update_id','start_message_floor'];
        $stage = 'amount';
    } else return null;
    $state = ['stage'=>$stage];
    foreach ($names as $i=>$name) {
        $value = filter_var($m[$i+1], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if ($value === false) return null;
        $state[$name] = $value;
    }
    if ($stage === 'amount' &&
        ($state['selection_update_id'] <= $state['start_update_id'] ||
         $state['selection_message_id'] <= $state['start_message_floor'])) return null;
    return $state;
}

function achbotWalletTransferFlowReady(mysqli $db): bool
{
    if (!achbotWalletAdjustmentsReady($db)) return false;
    try {
        $r = $db->query("SELECT ENGINE FROM information_schema.TABLES
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='setting'");
        if (!$r || strtoupper((string)($r->fetch_assoc()['ENGINE'] ?? '')) !== 'INNODB') return false;
        $r = $db->query("SELECT COLUMN_NAME,DATA_TYPE,COLUMN_TYPE,IS_NULLABLE,
            CHARACTER_MAXIMUM_LENGTH,EXTRA FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='setting'");
        if (!$r) return false;
        $cols = [];
        foreach ($r->fetch_all(MYSQLI_ASSOC) as $row) $cols[$row['COLUMN_NAME']]=$row;
        if (count($cols)!==3) return false;
        foreach (['id'=>'int','type'=>'varchar','value'=>'text'] as $name=>$type)
            if (!isset($cols[$name]) || $cols[$name]['DATA_TYPE']!==$type ||
                $cols[$name]['IS_NULLABLE']!=='NO') return false;
        if (str_contains($cols['id']['COLUMN_TYPE'],'unsigned') ||
            !str_contains($cols['id']['EXTRA'],'auto_increment') ||
            (int)$cols['type']['CHARACTER_MAXIMUM_LENGTH']!==500) return false;
        $r=$db->query("SELECT COLUMN_NAME FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='setting' AND INDEX_NAME='PRIMARY'
            ORDER BY SEQ_IN_INDEX");
        if (!$r || $r->fetch_all(MYSQLI_ASSOC)!==[['COLUMN_NAME'=>'id']]) return false;
        $r=$db->query("SELECT COUNT(*) n FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='setting' AND REFERENCED_TABLE_NAME IS NOT NULL");
        return $r && (int)$r->fetch_assoc()['n']===0;
    } catch (Throwable $e) {
        error_log('achbot_wallet_transfer_state_preflight_unavailable');
        return false;
    }
}

/** Caller holds the unique actor users row FOR UPDATE before reading/writing its cursor. */
function achbotWalletTransferRead(mysqli $db, array $actor): array
{
    $type='ACHBOT_WALLET_TRANSFER_' . (int)$actor['userid'];
    // setting.type is NOT unique/indexed. Discovery is nonlocking; only the
    // exact cursor PK is locked. Actor ownership serializes absent-row insert.
    $stmt=$db->prepare('SELECT id,value FROM setting WHERE type=? LIMIT 2');
    if (!$stmt) throw new RuntimeException('transfer_state_read_unavailable');
    $stmt->bind_param('s',$type);
    if (!$stmt->execute()) throw new RuntimeException('transfer_state_read_failed');
    $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
    if (count($rows)>1) throw new RuntimeException('transfer_state_ambiguous');
    if (!$rows) return ['id'=>null,'type'=>$type,'state'=>null];
    $id=(int)$rows[0]['id'];
    $stmt=$db->prepare('SELECT value FROM setting WHERE id=? AND type=? FOR UPDATE');
    if (!$stmt) throw new RuntimeException('transfer_state_read_unavailable');
    $stmt->bind_param('is',$id,$type);
    if (!$stmt->execute()) throw new RuntimeException('transfer_state_read_failed');
    $row=$stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$row) throw new RuntimeException('transfer_state_missing');
    $state=json_decode($row['value'],true,32,JSON_THROW_ON_ERROR);
    $required=['version','actor_id','actor_pk','start_update_id','start_message_id',
        'selection_update_id','selection_message_id','last_update_id','last_message_id','start_message_floor',
        'recipient_id','recipient_pk','stage'];
    if (!is_array($state) || count($state)!==count($required)) throw new RuntimeException('transfer_state_invalid');
    foreach ($required as $key) {
        if (!array_key_exists($key,$state)) throw new RuntimeException('transfer_state_invalid');
        if ($key!=='stage' && (!is_int($state[$key]) || $state[$key]<0))
            throw new RuntimeException('transfer_state_invalid');
    }
    if ($state['version']!==1 || $state['actor_id']!==(int)$actor['userid'] ||
        $state['actor_pk']!==(int)$actor['id'] ||
        $state['start_update_id']<=0 || $state['start_message_id']<=0 ||
        $state['start_message_floor']<$state['start_message_id'] ||
        !in_array($state['stage'],['recipient','amount','complete'],true))
        throw new RuntimeException('transfer_state_invalid');
    if ($state['stage']==='recipient') {
        if ($state['selection_update_id']!==0 || $state['selection_message_id']!==0 ||
            $state['recipient_id']!==0 || $state['recipient_pk']!==0 ||
            $state['last_update_id']!==$state['start_update_id'] ||
            $state['last_message_id']!==$state['start_message_floor'])
            throw new RuntimeException('transfer_state_invalid');
    } else {
        if ($state['recipient_id']<=0 || $state['recipient_pk']<=0 ||
            $state['selection_update_id']<=$state['start_update_id'] ||
            $state['selection_message_id']<=$state['start_message_floor'])
            throw new RuntimeException('transfer_state_invalid');
        if ($state['stage']==='amount' &&
            ($state['last_update_id']!==$state['selection_update_id'] ||
             $state['last_message_id']!==$state['selection_message_id']))
            throw new RuntimeException('transfer_state_invalid');
        if ($state['stage']==='complete' &&
            ($state['last_update_id']<=$state['selection_update_id'] ||
             $state['last_message_id']<=$state['selection_message_id']))
            throw new RuntimeException('transfer_state_invalid');
    }
    return ['id'=>$id,'type'=>$type,'state'=>$state];
}

function achbotWalletTransferWrite(mysqli $db, array $cursor, array $state): void
{
    $json=json_encode($state,JSON_THROW_ON_ERROR);
    $type=$cursor['type'];
    if ($cursor['id']===null) {
        $stmt=$db->prepare('INSERT INTO setting(type,value) VALUES (?,?)');
        if (!$stmt) throw new RuntimeException('transfer_state_write_unavailable');
        $stmt->bind_param('ss',$type,$json);
    } else {
        $id=$cursor['id'];
        $stmt=$db->prepare('UPDATE setting SET value=? WHERE id=? AND type=?');
        if (!$stmt) throw new RuntimeException('transfer_state_write_unavailable');
        $stmt->bind_param('sis',$json,$id,$type);
    }
    if (!$stmt->execute() || $stmt->affected_rows!==1)
        throw new RuntimeException('transfer_state_write_failed');
    $stmt->close();
}

function achbotWalletTransferAdvance(mysqli $db, array $source, string $action,
                                     int $recipient=0, string $expected=''): string
{
    $active=false; $commit=false;
    try {
        if (!achbotWalletTransferFlowReady($db) || !$db->begin_transaction())
            throw new RuntimeException('transfer_state_unavailable');
        $active=true; $actorId=$source['actor_id'];
        $stmt=$db->prepare('SELECT id,userid,wallet,step FROM users WHERE userid=? FOR UPDATE');
        $stmt->bind_param('i',$actorId);
        if (!$stmt->execute()) throw new RuntimeException('transfer_state_actor_read_failed');
        $actors=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
        if (count($actors)!==1) throw new RuntimeException('transfer_state_actor_ambiguous');
        $actor=$actors[0]; $cursor=achbotWalletTransferRead($db,$actor);
        $state=$cursor['state'];
        $actual=(string)$actor['step'];
        if ($action==='start') {
            if (($state && $source['update_id']<=$state['last_update_id']) ||
                (achbotWalletTransferPending($actual)!==null &&
                 (!$state || achbotWalletTransferStep($state)!==$actual))) {
                $db->rollback(); return 'stale';
            }
            if ((int)$actor['wallet']<=0) { $db->rollback(); return 'insufficient'; }
            // Clicking a still-valid old menu must not lower the known
            // private-message boundary from a completed/cancelled flow.
            $floor=max($source['message_id'], $state['last_message_id'] ?? 0);
            $state=['version'=>1,'actor_id'=>$actorId,'actor_pk'=>(int)$actor['id'],
                'start_update_id'=>$source['update_id'],'start_message_id'=>$source['message_id'],
                'start_message_floor'=>$floor,
                'selection_update_id'=>0,'selection_message_id'=>0,
                'last_update_id'=>$source['update_id'],'last_message_id'=>$floor,
                'recipient_id'=>0,'recipient_pk'=>0,'stage'=>'recipient'];
        } elseif ($action==='select') {
            if ($actual!==$expected || !$state || $state['stage']!=='recipient' ||
                achbotWalletTransferStep($state)!==$expected ||
                $source['update_id']<=$state['last_update_id'] ||
                $source['message_id']<=$state['last_message_id']) {
                $db->rollback(); return 'stale';
            }
            if ($recipient<=0 || $recipient===$actorId) { $db->rollback(); return 'invalid'; }
            $stmt=$db->prepare('SELECT id FROM users WHERE userid=?');
            $stmt->bind_param('i',$recipient);
            if (!$stmt->execute()) throw new RuntimeException('transfer_state_recipient_read_failed');
            $targets=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
            if (!$targets) { $db->rollback(); return 'missing'; }
            if (count($targets)!==1) throw new RuntimeException('transfer_state_recipient_ambiguous');
            $state['stage']='amount';
            $state['recipient_id']=$recipient; $state['recipient_pk']=(int)$targets[0]['id'];
            $state['selection_update_id']=$source['update_id'];
            $state['selection_message_id']=$source['message_id'];
            $state['last_update_id']=$source['update_id'];
            $state['last_message_id']=$source['message_id'];
        } else throw new RuntimeException('transfer_state_action_invalid');
        $next=achbotWalletTransferStep($state);
        achbotWalletTransferWrite($db,$cursor,$state);
        $pk=(int)$actor['id'];
        $stmt=$db->prepare('UPDATE users SET step=? WHERE id=? AND userid=? AND step=?');
        $stmt->bind_param('siis',$next,$pk,$actorId,$actual);
        if (!$stmt->execute() || $stmt->affected_rows!==1)
            throw new RuntimeException('transfer_state_claim_failed');
        $stmt->close();
        $commit=true;
        if (!$db->commit()) throw new RuntimeException('transfer_state_commit_unconfirmed');
        return 'ready';
    } catch (Throwable $e) {
        if ($active) { try { $db->rollback(); } catch (Throwable $ignored) {} }
        $known=['transfer_state_read_unavailable','transfer_state_read_failed',
            'transfer_state_ambiguous','transfer_state_missing','transfer_state_invalid',
            'transfer_state_write_unavailable','transfer_state_write_failed',
            'transfer_state_unavailable','transfer_state_actor_read_failed',
            'transfer_state_actor_ambiguous','transfer_state_recipient_read_failed',
            'transfer_state_recipient_ambiguous','transfer_state_action_invalid',
            'transfer_state_claim_failed','transfer_state_commit_unconfirmed'];
        $reason=in_array($e->getMessage(),$known,true)?$e->getMessage():'transfer_state_database_or_runtime_error';
        $sqlstate=$e instanceof mysqli_sql_exception?$e->getSqlState():'n/a';
        error_log('achbot_wallet_transfer_state_review action='.$action.
            ' update_id='.$source['update_id'].' actor_id='.$source['actor_id'].
            ' reason='.$reason.' sqlstate='.$sqlstate.' exception_class='.get_class($e).
            ' commit_attempted='.($commit?'yes':'no'));
        return 'review';
    }
}

/** Called inside the balance transaction AFTER both user locks are held. */
function achbotWalletTransferOwned(mysqli $db, array $sender, array $receiver,
                                   string $step, array $source): array
{
    $cursor=achbotWalletTransferRead($db,$sender); $state=$cursor['state'];
    if ($source['actor_id']!==(int)$sender['userid'] || !$state || $state['stage']!=='amount' || achbotWalletTransferStep($state)!==$step ||
        $state['recipient_id']!==(int)$receiver['userid'] ||
        $state['recipient_pk']!==(int)$receiver['id'] ||
        $source['update_id']<=$state['last_update_id'] ||
        $source['message_id']<=$state['last_message_id'])
        throw new RuntimeException('transfer_state_ownership_mismatch');
    return $cursor;
}

function achbotWalletTransferComplete(mysqli $db, array $cursor, array $source): void
{
    $state=$cursor['state']; $state['stage']='complete';
    $state['last_update_id']=$source['update_id'];
    $state['last_message_id']=$source['message_id'];
    achbotWalletTransferWrite($db,$cursor,$state);
}
