<?php
/**
 * AchBot presentation helpers. No bootstrap, database or network effects.
 * Legacy coercions, strings and edge behavior are intentional in V1.
 */
namespace AchBot\Presentation\Text;

function replacePlaceholders(array $mainValues, array $botState, $userId = '') {
    $placeholders = [
        'SUPPORTID'   => $botState['supportID']   ?? '',
        'LOCKCHANNEL' => $botState['lockChannel'] ?? '',
        'userid'      => (string)$userId,
    ];

    foreach ($mainValues as $key => $value) {
        if (is_string($value)) {

            foreach ($placeholders as $placeholder => $replacement) {
                $value = str_replace($placeholder, $replacement, $value);
            }

            $mainValues[$key] = $value;
        }
        elseif (is_array($value)) {
            $mainValues[$key] = replacePlaceholders($value, $botState, $userId);
        }
    }

    return $mainValues;
}

function supportUrl(array $botState, string $fallback = '') {
    $support = trim($botState['supportID'] ?? '');

    if ($support === '') return $fallback;
    if (preg_match('~^https?://t\.me/~i', $support)) {
        return $support;
    }
    return 'https://t.me/' . ltrim($support, '@');
}
