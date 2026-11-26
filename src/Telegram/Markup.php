<?php
/**
 * AchBot presentation helpers. No bootstrap, database or network effects.
 * Legacy coercions, strings and edge behavior are intentional in V1.
 */
namespace AchBot\Telegram\Markup;
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG


function normalize($key){
    if ($key === null) return null;
    if (is_array($key)) return json_encode($key, JSON_UNESCAPED_UNICODE);
    return $key;
}

function inlineKeyboard(array $rows): string {
    return json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE);
}
