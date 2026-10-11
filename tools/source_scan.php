<?php
/** Tokenize PHP without executing application code. Used by V0 inventory/tests. */
declare(strict_types=1);

function achbotTokens(string $source): array
{
    $result = [];
    $offset = 0;
    $line = 1;
    foreach (token_get_all($source, TOKEN_PARSE) as $token) {
        $kind = is_array($token) ? token_name($token[0]) : $token;
        $text = is_array($token) ? $token[1] : $token;
        $result[] = [$kind, $text, $line, $offset];
        $offset += strlen($text);
        $line += substr_count($text, "\n");
    }
    return $result;
}

function achbotSignificant(array $token): bool
{
    return !in_array($token[0], ['T_WHITESPACE', 'T_COMMENT', 'T_DOC_COMMENT'], true);
}

function achbotBodyEnd(array $tokens, int $open): int
{
    $depth = 0;
    for ($j = $open, $count = count($tokens); $j < $count; $j++) {
        if (in_array($tokens[$j][0], ['{', 'T_CURLY_OPEN', 'T_DOLLAR_OPEN_CURLY_BRACES'], true)) {
            $depth++;
        } elseif ($tokens[$j][0] === '}') {
            if (--$depth === 0) return $j;
        }
    }
    throw new RuntimeException('Unbalanced body at token ' . $open);
}

function achbotFunctions(string $source, ?array $tokens = null): array
{
    $tokens ??= achbotTokens($source);
    $functions = [];
    for ($i = 0, $count = count($tokens); $i < $count; $i++) {
        if ($tokens[$i][0] !== 'T_FUNCTION') continue;
        $j = $i + 1;
        while ($j < $count && (!achbotSignificant($tokens[$j]) || str_contains($tokens[$j][0], 'AMPERSAND') || $tokens[$j][0] === '&')) $j++;
        if (($tokens[$j][0] ?? null) !== 'T_STRING') continue; // Anonymous function.
        $name = $tokens[$j][1];
        $open = $j;
        while ($open < $count && !in_array($tokens[$open][0], ['{', ';'], true)) $open++;
        if ($open >= $count || $tokens[$open][0] === ';') continue;
        $end = achbotBodyEnd($tokens, $open);
        $startOffset = $tokens[$i][3];
        $length = $tokens[$end][3] + strlen($tokens[$end][1]) - $startOffset;
        $functions[] = [
            'name' => $name, 'line' => $tokens[$i][2], 'end_line' => $tokens[$end][2],
            'start_token' => $i, 'end_token' => $end, 'body_token' => $open,
            'offset' => $startOffset, 'length' => $length,
            'signature' => trim(substr($source, $startOffset, $tokens[$open][3] - $startOffset)),
            'code_sha256' => hash('sha256', substr($source, $startOffset, $length)),
        ];
    }
    return $functions;
}

function achbotExtractFunction(string $path, string $name): string
{
    $source = file_get_contents($path);
    if ($source === false) throw new RuntimeException('Cannot read source');
    $found = array_values(array_filter(achbotFunctions($source), fn(array $f): bool => $f['name'] === $name));
    if (count($found) !== 1) throw new RuntimeException('Function not unique: ' . $name);
    return substr($source, $found[0]['offset'], $found[0]['length']);
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    $result = [];
    foreach (array_slice($argv, 1) as $path) {
        if (!is_file($path) || !str_ends_with($path, '.php')) throw new InvalidArgumentException('Expected PHP file');
        $source = file_get_contents($path);
        $tokens = achbotTokens($source);
        $result[$path] = ['tokens' => $tokens, 'functions' => achbotFunctions($source, $tokens)];
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}
