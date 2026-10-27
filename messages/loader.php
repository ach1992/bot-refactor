<?php
/**
 * AChBot bot-facing copy. Safe to load from bootstrap or an isolated module.
 * Templates never evaluate PHP or implicitly escape existing display values.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

function achbotMessages(string $topic): array {
    static $topics = [];
    if (!preg_match('/\A[a-z][a-z0-9_]*\z/D', $topic)) {
        throw new InvalidArgumentException('message_topic_invalid');
    }
    if (!array_key_exists($topic, $topics)) {
        $path = __DIR__ . '/fa/' . $topic . '.php';
        if (!is_file($path)) throw new OutOfBoundsException('message_topic_unknown:' . $topic);
        $entries = require $path;
        if (!is_array($entries)) throw new UnexpectedValueException('message_topic_invalid:' . $topic);
        $topics[$topic] = $entries;
    }
    return $topics[$topic];
}

/**
 * Explicit, one-pass substitutions. Call sites retain their existing escaping
 * and dynamic expressions; replacement values cannot introduce new slots.
 * Missing/extra slots fail as internal programming errors, never silent text.
 */
function achbotMessage(string $key, array $values = []): string {
    $parts = explode('.', $key, 2);
    if (count($parts) !== 2) throw new InvalidArgumentException('message_key_invalid');
    $entries = achbotMessages($parts[0]);
    if (!array_key_exists($parts[1], $entries)) throw new OutOfBoundsException('message_key_unknown:' . $key);
    $entry = $entries[$parts[1]];
    $text = is_array($entry) ? $entry['text'] : $entry;
    $expected = is_array($entry) ? $entry['parameters'] : [];
    if (!is_string($text) || !is_array($expected)) throw new UnexpectedValueException('message_entry_invalid:' . $key);
    if (count($values) !== count($expected) || array_diff($expected, array_keys($values))) {
        throw new InvalidArgumentException('message_parameters_mismatch:' . $key);
    }
    $replace = [];
    foreach ($expected as $name) $replace['{' . $name . '}'] = (string)$values[$name];
    return $replace ? strtr($text, $replace) : $text;
}

/** For new HTML callers whose explicit parameters are entirely untrusted. */
function achbotMessageHtml(string $key, array $values = []): string {
    foreach ($values as $name => $value) {
        $values[$name] = htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
    return achbotMessage($key, $values);
}
