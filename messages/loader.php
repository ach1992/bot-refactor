<?php
/**
 * AChBot copy loader for the two audience files. Safe for isolated modules.
 * Templates never evaluate PHP or implicitly escape existing display values.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

/** Internal merged view; the only editable prose sources are user.php/admin.php. */
function achbotMessageCatalog(): array {
    static $catalog = null;
    if ($catalog !== null) return $catalog;
    $loaded = [];
    foreach (['user', 'admin'] as $audience) {
        $groups = require __DIR__ . '/fa/' . $audience . '.php';
        if (!is_array($groups)) throw new UnexpectedValueException('message_catalog_invalid');
        foreach ($groups as $topic => $entries) {
            if (!is_string($topic) || !preg_match('/\\A[a-z][a-z0-9_]*\\z/D', $topic) || !is_array($entries)) {
                throw new UnexpectedValueException('message_topic_invalid');
            }
            if (!array_key_exists($topic, $loaded)) $loaded[$topic] = [];
            foreach ($entries as $key => $entry) {
                if (!is_string($key) || array_key_exists($key, $loaded[$topic])) {
                    throw new UnexpectedValueException('message_key_duplicate_or_invalid');
                }
                $loaded[$topic][$key] = $entry;
            }
        }
    }
    $catalog = $loaded;
    return $catalog;
}

/** Logical topics remain stable independently of the two-file editing layout. */
function achbotMessageTopics(): array {
    return array_keys(achbotMessageCatalog());
}

function achbotMessages(string $topic): array {
    if (!preg_match('/\\A[a-z][a-z0-9_]*\\z/D', $topic)) {
        throw new InvalidArgumentException('message_topic_invalid');
    }
    $catalog = achbotMessageCatalog();
    if (!array_key_exists($topic, $catalog)) throw new OutOfBoundsException('message_topic_unknown:' . $topic);
    return $catalog[$topic];
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
