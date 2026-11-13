<?php
/** AChBot: refresh the authenticated actor's existing profile on /start only. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }

/**
 * Keep the existing users.name HTML-encoded storage convention. Read the raw
 * Telegram actor once, encode once, and never infer identity from cached text,
 * callbacks, forwarded users or getChat. Missing username preserves its column.
 */
function achbotRefreshUserProfile(mysqli $db, $update, int $actor): bool {
    if (!function_exists('achbotWebhookIngressAuthenticated') ||
        !achbotWebhookIngressAuthenticated() || $actor <= 0 ||
        !is_object($update) || !is_object($update->message ?? null) ||
        isset($update->callback_query)) return false;
    $message = $update->message;
    $sender = $message->from ?? null;
    $chat = $message->chat ?? null;
    if (!is_object($sender) || !is_object($chat) ||
        !is_int($sender->id ?? null) || $sender->id !== $actor ||
        ($sender->is_bot ?? false) === true ||
        ($chat->type ?? null) !== 'private' || ($chat->id ?? null) !== $actor ||
        !is_string($message->text ?? null) ||
        preg_match('/\A\/start(?:@[A-Za-z0-9_]+)?(?:\s.*)?\z/iuD', $message->text) !== 1 ||
        !is_string($sender->first_name ?? null) ||
        !mb_check_encoding($sender->first_name, 'UTF-8')) return false;
    $last = is_string($sender->last_name ?? null) ? $sender->last_name : '';
    if (!mb_check_encoding($last, 'UTF-8')) return false;
    $raw = trim(mb_substr($sender->first_name . ' ' . $last, 0, 129, 'UTF-8'));
    if ($raw === '') return false;
    // Fit the original varchar(100) without splitting an HTML entity.
    do {
        $name = htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if (mb_strlen($name, 'UTF-8') <= 100) break;
        $raw = mb_substr($raw, 0, -1, 'UTF-8');
    } while ($raw !== '');
    if ($raw === '') return false;
    $username = $sender->username ?? null;
    $hasUsername = is_string($username) &&
        preg_match('/\A[A-Za-z0-9_]{1,100}\z/D', $username) === 1;
    try {
        $s = $db->prepare('SELECT id FROM users WHERE userid=? LIMIT 2');
        $s->bind_param('i', $actor);
        if (!$s->execute()) return false;
        $rows = $s->get_result()->fetch_all(MYSQLI_ASSOC); $s->close();
        if (count($rows) !== 1) return false;
        $pk = (int)$rows[0]['id'];
        if ($hasUsername) {
            $s = $db->prepare('UPDATE users SET name=?,username=? WHERE id=? AND userid=?');
            $s->bind_param('ssii', $name, $username, $pk, $actor);
        } else {
            $s = $db->prepare('UPDATE users SET name=? WHERE id=? AND userid=?');
            $s->bind_param('sii', $name, $pk, $actor);
        }
        $saved = $s->execute(); $s->close();
        return $saved;
    } catch (Throwable $ignored) { return false; }
}


/**
 * Resolve one Telegram actor on the first authenticated /start, including the
 * haveJoined callback. Registration happens only AFTER the forced-join gate.
 * A per-(database,actor) MariaDB advisory lock serializes concurrent starts;
 * the user row is the durable one-time referral/reward boundary.
 *
 * Nothing here calls Telegram, providers or external services.
 */
function achbotRegisterStartUser(
    mysqli $db, $update, int $actor, int $inviter, int $inviteReward, string $databaseName
): ?array {
    if ($actor <= 0 || !is_object($update) || !function_exists('achbotWebhookIngressAuthenticated') ||
        !achbotWebhookIngressAuthenticated()) return null;

    $message = $update->message ?? null;
    $callback = $update->callback_query ?? null;
    if (is_object($message) && !is_object($callback)) {
        $sender = $message->from ?? null;
        $chat = $message->chat ?? null;
        if (!is_string($message->text ?? null) ||
            preg_match('/\\A\\/[Ss]tart(?:@[A-Za-z0-9_]{1,32})?(?:\\s|$)/D', $message->text) !== 1)
            return null;
    } elseif (is_object($callback) && !is_object($message)) {
        $sender = $callback->from ?? null;
        $chat = $callback->message->chat ?? null;
        if (!is_string($callback->data ?? null) ||
            preg_match('/\\AhaveJoined(?:\\/start(?: [1-9][0-9]{0,18})?)?\\z/D', $callback->data) !== 1)
            return null;
    } else return null;

    if (!is_object($sender) || !is_object($chat) ||
        !is_int($sender->id ?? null) || $sender->id !== $actor ||
        ($sender->is_bot ?? false) === true ||
        ($chat->type ?? null) !== 'private' || ($chat->id ?? null) !== $actor ||
        !is_string($sender->first_name ?? null) ||
        !mb_check_encoding($sender->first_name, 'UTF-8')) return null;

    $raw = trim($sender->first_name);
    if ($raw === '') return null;
    do {
        $name = htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if (mb_strlen($name, 'UTF-8') <= 100) break;
        $raw = mb_substr($raw, 0, -1, 'UTF-8');
    } while ($raw !== '');
    if ($raw === '') return null;
    $username = is_string($sender->username ?? null) &&
        preg_match('/\\A[A-Za-z0-9_]{1,100}\\z/D', $sender->username) === 1
        ? $sender->username : ' ';

    // GET_LOCK is session-scoped, not transactional. Keep the name <=64 bytes.
    $lockName = 'achbot_start_' . substr(hash('sha256', $databaseName . ':' . $actor), 0, 40);
    $ownsLock = false;
    $begun = false;
    $stage = 'lock';
    try {
        $stmt = $db->prepare('SELECT GET_LOCK(?,5) AS acquired');
        $stmt->bind_param('s', $lockName);
        $ok = $stmt->execute();
        $ownsLock = $ok && (int)($stmt->get_result()->fetch_assoc()['acquired'] ?? 0) === 1;
        $stmt->close();
        if (!$ownsLock) throw new RuntimeException('lock_unavailable');

        $stage = 'identity';
        $stmt = $db->prepare('SELECT * FROM users WHERE userid=? LIMIT 2');
        $stmt->bind_param('i', $actor);
        if (!$stmt->execute()) throw new RuntimeException('identity_read_failed');
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if (count($rows) === 1) return ['user'=>$rows[0], 'created'=>false, 'reward'=>null];
        if ($rows) throw new RuntimeException('identity_ambiguous');

        $stage = 'begin';
        if (!$db->begin_transaction()) throw new RuntimeException('begin_failed');
        $begun = true;
        $reward = null;
        $referrer = null;
        if ($inviter > 0 && $inviter !== $actor) {
            $stage = 'inviter';
            $stmt = $db->prepare('SELECT id,wallet FROM users WHERE userid=? LIMIT 2 FOR UPDATE');
            $stmt->bind_param('i', $inviter);
            if (!$stmt->execute()) throw new RuntimeException('inviter_read_failed');
            $refs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            if (count($refs) === 1) $referrer = $refs[0];
        }

        $stage = 'insert';
        $date = time();
        if ($referrer !== null) {
            $step = 'referedBy' . $inviter;
            $stmt = $db->prepare(
                'INSERT INTO users (userid,name,username,refcode,wallet,date,refered_by,step) '
                . 'VALUES (?,?,?,0,0,?,?,?)'
            );
            $stmt->bind_param('issiis', $actor, $name, $username, $date, $inviter, $step);
        } else {
            $stmt = $db->prepare(
                'INSERT INTO users (userid,name,username,refcode,wallet,date) VALUES (?,?,?,0,0,?)'
            );
            $stmt->bind_param('issi', $actor, $name, $username, $date);
        }
        if (!$stmt->execute() || $stmt->affected_rows !== 1)
            throw new RuntimeException('insert_failed');
        $stmt->close();

        if ($referrer !== null && $inviteReward > 0) {
            $stage = 'reward';
            $old = (int)$referrer['wallet'];
            // Wallet debt is a valid signed balance. A positive invitation
            // credit may reduce that debt; only signed INT overflow is invalid.
            if ($inviteReward > 2147483647 - $old)
                throw new RuntimeException('reward_out_of_range');
            $new = $old + $inviteReward;
            $referrerId = (int)$referrer['id'];
            $stmt = $db->prepare('UPDATE users SET wallet=? WHERE id=? AND wallet=?');
            $stmt->bind_param('iii', $new, $referrerId, $old);
            if (!$stmt->execute() || $stmt->affected_rows !== 1)
                throw new RuntimeException('reward_write_failed');
            $stmt->close();
            $reward = ['inviter'=>$inviter, 'old_wallet'=>$old,
                       'new_wallet'=>$new, 'amount'=>$inviteReward];
        }

        $stage = 'readback';
        $stmt = $db->prepare('SELECT * FROM users WHERE userid=? LIMIT 2');
        $stmt->bind_param('i', $actor);
        if (!$stmt->execute()) throw new RuntimeException('readback_failed');
        $createdRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if (count($createdRows) !== 1) throw new RuntimeException('identity_collision');
        $stage = 'commit';
        if (!$db->commit()) throw new RuntimeException('commit_unverified');
        $begun = false;
        return ['user'=>$createdRows[0], 'created'=>true, 'reward'=>$reward];
    } catch (Throwable $ignored) {
        if ($begun) {
            try { $db->rollback(); } catch (Throwable $unused) {}
        }
        error_log('achbot_start_registration_unverified actor=' . $actor
            . ' stage=' . $stage . ' class=' . get_class($ignored)
            . ' code=' . (int)$ignored->getCode());
        return null;
    } finally {
        if ($ownsLock) {
            try {
                $stmt = $db->prepare('SELECT RELEASE_LOCK(?) AS released');
                $stmt->bind_param('s', $lockName);
                $stmt->execute();
                $stmt->close();
            } catch (Throwable $unused) {}
        }
    }
}
