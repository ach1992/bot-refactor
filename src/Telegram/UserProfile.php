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
