<?php
/**
 * Optional, presentation-only Telegram decoration.
 *
 * The existing message catalog, keyboard builders and callback contracts stay
 * authoritative. Without an explicit per-instance opt-in this layer is an
 * identity transform, including wire-level payloads and return values.
 */
namespace AchBot\Telegram\Presentation;

/** Read optional local-only instance policy from baseInfo.php. */
function policy(): array {
    $value = $GLOBALS['achbotTelegramPresentation'] ?? null;
    return is_array($value) ? $value : [];
}

/** Never infer permission to use custom emoji from a user's Premium status. */
function emojiIds(array $policy): array {
    if (($policy['premium_emoji_enabled'] ?? false) !== true ||
        !is_array($policy['premium_emoji_ids'] ?? null)) return [];
    $result = [];
    foreach ($policy['premium_emoji_ids'] as $emoji => $id) {
        if (!is_string($emoji) || $emoji === '' || preg_match('//u', $emoji) !== 1 ||
            !is_string($id) || preg_match('/\A[1-9][0-9]{0,19}\z/D', $id) !== 1) continue;
        // A mapping key must be one complete Unicode grapheme, not a substring
        // of another emoji (e.g. a ZWJ sequence or variation selector).
        if (preg_match('/\A\X\z/u', $emoji) !== 1) continue;
        $result[$emoji] = $id;
    }
    return $result;
}

/** Custom emoji must not alter HTML attributes, URLs or literal code spans. */
function htmlEmoji(string $html, array $ids): string {
    if (!$ids || preg_match('//u', $html) !== 1) return $html;
    // Split at complete quoted HTML tags and named/numeric entities. Do not
    // decode or re-encode the author's markup, whitespace or copyable data.
    $tokens = preg_split(
        '~(<(?:[^\x22\x27<>]|\x22[^\x22]*\x22|\x27[^\x27]*\x27)+>|&(?:#[0-9]+|#x[0-9a-fA-F]+|[A-Za-z][A-Za-z0-9]+);)~u',
        $html, -1, PREG_SPLIT_DELIM_CAPTURE
    );
    if (!is_array($tokens)) return $html;
    $out = ''; $protected = [];
    foreach ($tokens as $token) {
        if ($token !== '' && $token[0] === '<' && str_ends_with($token, '>')) {
            if (preg_match('~^<(/?)([a-zA-Z][a-zA-Z0-9-]*)\b~', $token, $tag)) {
                $name = strtolower($tag[2]);
                if (in_array($name, ['a','code','pre','tg-emoji'], true)) {
                    if ($tag[1] === '/') {
                        // Remove the most recent matching protected span.
                        for ($i = count($protected)-1; $i >= 0; $i--) {
                            if ($protected[$i] === $name) {
                                array_splice($protected, $i, 1);
                                break;
                            }
                        }
                    } elseif (!str_ends_with($token, '/>')) $protected[] = $name;
                }
            }
            $out .= $token;
            continue;
        }
        if ($token !== '' && $token[0] === '&' && str_ends_with($token, ';')) {
            $out .= $token;
            continue;
        }
        if ($protected || $token === '') { $out .= $token; continue; }
        $replaced = preg_replace_callback('/\X/u', static function (array $match) use ($ids): string {
            $emoji = $match[0];
            return isset($ids[$emoji])
                ? '<tg-emoji emoji-id="'.$ids[$emoji].'">'.$emoji.'</tg-emoji>'
                : $emoji;
        }, $token);
        $out .= $replaced ?? $token;
    }
    return $out;
}

/**
 * Treat an unformatted Telegram text as literal input when opting in to
 * premium emoji. Markdown/MarkdownV2 cannot safely be auto-converted here:
 * their formatting must be migrated and regression-tested at the producer.
 */
function decoratedText(array $payload, string $field, array $ids): array {
    if (!$ids || !isset($payload[$field]) || !is_string($payload[$field]) ||
        preg_match('//u', $payload[$field]) !== 1) return $payload;
    if (array_key_exists($field === 'text' ? 'entities' : 'caption_entities', $payload))
        return $payload;
    $mode = $payload['parse_mode'] ?? null;
    if ($mode !== null && strcasecmp((string)$mode, 'HTML') !== 0) return $payload;
    $original = $payload[$field];
    $html = $mode === null
        ? htmlspecialchars($original, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        : $original;
    $decorated = htmlEmoji($html, $ids);
    if ($decorated === $html) return $payload;
    $payload[$field] = $decorated;
    $payload['parse_mode'] = 'HTML';
    return $payload;
}

function validButtonStyle($candidate): ?string {
    return is_string($candidate) && in_array($candidate, ['primary','success','danger'], true)
        ? $candidate : null;
}

/**
 * Only Inline keyboards can have their decorative emoji prefix removed
 * without changing the text returned by a ReplyKeyboard press. Reply buttons
 * retain their original text and can independently receive a color style.
 */
function decoratedMarkup($markup, array $policy, array $ids) {
    $stylesEnabled = ($policy['button_styles_enabled'] ?? false) === true;
    if ((!$ids && !$stylesEnabled) || (!is_array($markup) && !is_string($markup))) return $markup;
    $data = is_string($markup) ? json_decode($markup, true) : $markup;
    if (!is_array($data)) return $markup;
    $kind = isset($data['inline_keyboard']) && is_array($data['inline_keyboard'])
        ? 'inline_keyboard'
        : (isset($data['keyboard']) && is_array($data['keyboard']) ? 'keyboard' : null);
    if ($kind === null) return $markup;
    $stylePolicy = is_array($policy['button_styles'] ?? null) ? $policy['button_styles'] : [];
    $lookup = $kind === 'inline_keyboard' ? 'callback_data' : 'reply_text';
    $configured = is_array($stylePolicy[$lookup] ?? null) ? $stylePolicy[$lookup] : [];
    $changed = false;
    foreach ($data[$kind] as &$row) {
        if (!is_array($row)) continue;
        foreach ($row as &$button) {
            if (!is_array($button) || !isset($button['text']) || !is_string($button['text'])) continue;
            if ($stylesEnabled && !isset($button['style'])) {
                $value = $kind === 'inline_keyboard'
                    ? ($button['callback_data'] ?? null) : $button['text'];
                $style = is_string($value)
                    ? validButtonStyle($configured[$value] ?? null) : null;
                if ($style !== null) { $button['style'] = $style; $changed = true; }
            }
            if ($kind !== 'inline_keyboard' || !$ids || isset($button['icon_custom_emoji_id']) ||
                preg_match('/\A\X/u', $button['text'], $found) !== 1 ||
                !isset($ids[$found[0]])) continue;
            $rest = ltrim(substr($button['text'], strlen($found[0])), ' ');
            // Never leave an empty label or change a callback/url/other action.
            if ($rest === '') continue;
            $button['text'] = $rest;
            $button['icon_custom_emoji_id'] = $ids[$found[0]];
            $changed = true;
        }
        unset($button);
    }
    unset($row);
    if (!$changed) return $markup;
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

/** Central capture for both form-encoded and multipart Bot API requests. */
function prepare(string $method, array $payload): array {
    $original = $payload;
    $options = policy();
    $ids = emojiIds($options);
    if (!$ids && ($options['button_styles_enabled'] ?? false) !== true) return $payload;

    $field = match ($method) {
        'sendMessage','editMessageText' => 'text',
        'sendPhoto','sendDocument','sendVideo','sendAudio','sendVoice',
        'sendAnimation','editMessageCaption' => 'caption',
        default => null
    };
    if ($field !== null) $payload = decoratedText($payload, $field, $ids);

    if (isset($payload['reply_markup']) && in_array($method, [
        'sendMessage','editMessageText','sendPhoto','sendDocument','sendVideo',
        'sendAudio','sendVoice','sendAnimation','editMessageCaption',
        'editMessageReplyMarkup'
    ], true)) {
        $payload['reply_markup'] = decoratedMarkup($payload['reply_markup'], $options, $ids);
    }
    return $payload;
}

/** Retry only when Telegram explicitly rejected our presentation extension. */
function rejectedDecoration($result): bool {
    $data = is_object($result) ? (array)$result : $result;
    if (!is_array($data) || ($data['ok'] ?? null) !== false ||
        (int)($data['error_code'] ?? 0) !== 400) return false;
    $description = strtolower((string)($data['description'] ?? ''));
    return str_contains($description, 'custom emoji') ||
        str_contains($description, 'custom_emoji') ||
        str_contains($description, 'button style') ||
        str_contains($description, 'button_style') ||
        str_contains($description, "can't parse entities");
}

/**
 * One known rejected decorated request can be sent as the original payload.
 * Never retry a network timeout, unknown response, rate-limit, or 5xx where
 * the original financial/customer delivery may already have been accepted.
 */
function dispatch(string $method, array $payload, callable $send) {
    $decorated = prepare($method, $payload);
    $result = $send($decorated);
    if ($decorated !== $payload && rejectedDecoration($result)) return $send($payload);
    return $result;
}
