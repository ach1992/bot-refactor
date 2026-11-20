<?php
/**
 * Inspect executable PHP catalog calls without loading application code.
 * Input: JSON object {path: source}. Comments are masked before downstream
 * source-producer tracing; quoted text in comments/strings is never a call.
 */
$files = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($files)) throw new InvalidArgumentException('source_map_required');
$result = [];
foreach ($files as $path => $source) {
    if (!is_string($path) || !is_string($source))
        throw new InvalidArgumentException('source_map_invalid');
    $tokens = token_get_all($source);
    $clean = '';
    foreach ($tokens as $token) {
        $kind = is_array($token) ? $token[0] : null;
        $text = is_array($token) ? $token[1] : $token;
        $clean .= $kind === T_COMMENT || $kind === T_DOC_COMMENT
            ? preg_replace('/[^\r\n]/', ' ', $text) : $text;
    }
    $literal = [];
    $dynamic = [];
    $variables = [];
    $legacyMain = [];
    $legacyButtons = [];
    foreach ($tokens as $i => $token) {
        if (is_array($token) && $token[0] === T_VARIABLE &&
            in_array($token[1], ['$mainValues', '$buttonValues'], true)) {
            $index = $i + 1;
            while (isset($tokens[$index]) && is_array($tokens[$index]) &&
                   in_array($tokens[$index][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))
                $index++;
            if (($tokens[$index] ?? null) === '[') {
                $index++;
                while (isset($tokens[$index]) && is_array($tokens[$index]) &&
                       in_array($tokens[$index][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))
                    $index++;
                $part = $tokens[$index] ?? null;
                if (is_array($part) && $part[0] === T_CONSTANT_ENCAPSED_STRING &&
                    preg_match('/^[\x27\x22]([A-Za-z0-9_]+)[\x27\x22]$/D', $part[1], $match)) {
                    $end = $index + 1;
                    while (isset($tokens[$end]) && is_array($tokens[$end]) &&
                           in_array($tokens[$end][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))
                        $end++;
                    if (($tokens[$end] ?? null) === ']') {
                        if ($token[1] === '$mainValues') $legacyMain[$match[1]] = true;
                        else $legacyButtons[$match[1]] = true;
                    }
                }
            }
        }
        if (!is_array($token) ||
            !in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true) ||
            !preg_match('~^\\\\?achbotMessage(?:Html)?$~D', $token[1])) continue;
        $open = $i + 1;
        while (isset($tokens[$open]) && is_array($tokens[$open]) &&
               in_array($tokens[$open][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))
            $open++;
        if (($tokens[$open] ?? null) !== '(') continue;
        $stack = ['('];
        $argument = [];
        for ($j = $open + 1; isset($tokens[$j]); $j++) {
            $part = $tokens[$j];
            $word = is_array($part) ? $part[1] : $part;
            if ($word === ',' && count($stack) === 1) break;
            if ($word === '(' || $word === '{' || $word === '[') $stack[] = $word;
            if ($word === ')' || $word === '}' || $word === ']') {
                $opening = array_pop($stack);
                if (($word === ')' && $opening !== '(') ||
                    ($word === '}' && $opening !== '{') ||
                    ($word === ']' && $opening !== '['))
                    throw new RuntimeException('unbalanced_php_catalog_call:' . $path);
                if (!$stack) break;
            }
            $argument[] = ['value' => $word, 'kind' => is_array($part) ? $part[0] : null,
                           'in_index' => in_array('[', $stack, true)];
        }
        $expression = trim(implode('', array_column($argument, 'value')));
        if (preg_match('/^\$[A-Za-z_][A-Za-z0-9_]*$/D', $expression))
            $variables[$expression] = true;
        foreach ($argument as $n => $part) {
            if ($part['kind'] !== T_CONSTANT_ENCAPSED_STRING || $part['in_index']) continue;
            if (!preg_match('/^[\x27\x22]([a-z][a-z0-9_]*\.[A-Za-z0-9_.]+)[\x27\x22]$/D',
                            $part['value'], $match)) continue;
            $next = $n + 1;
            while (isset($argument[$next]) &&
                   ($argument[$next]['kind'] === T_WHITESPACE ||
                    $argument[$next]['kind'] === T_COMMENT ||
                    $argument[$next]['kind'] === T_DOC_COMMENT)) $next++;
            if (($argument[$next]['value'] ?? '') === '.')
                $dynamic[$match[1]] = true;
            else
                $literal[$match[1]] = true;
        }
    }
    $result[$path] = ['literal' => array_keys($literal), 'dynamic' => array_keys($dynamic),
                      'variables' => array_keys($variables),
                      'main_indexes' => array_keys($legacyMain),
                      'button_indexes' => array_keys($legacyButtons), 'clean' => $clean];
}
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
