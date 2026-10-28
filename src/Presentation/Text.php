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


/**
 * Display-only allowance classification. Callers explicitly opt in to a known
 * zero/NULL unlimited sentinel; remaining quota uses the default finite zero.
 * This helper never authorizes account creation, charging or provider writes.
 */
function serviceAllowanceState($value,bool $zeroMeansUnlimited=false,
    bool $nullMeansUnlimited=false): array {
    $unknown=['state'=>'unknown','display'=>''];
    if ($value===null) return $nullMeansUnlimited?['state'=>'unlimited','display'=>'']:$unknown;
    if (!is_int($value) && !is_float($value) && !is_string($value)) return $unknown;
    if (is_float($value) && !is_finite($value)) return $unknown;
    $raw=(string)$value;
    if (strlen($raw)>32 || preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D',$raw)!==1 ||
        !is_finite((float)$raw)) return $unknown;
    $display=str_contains($raw,'.')?rtrim(rtrim($raw,'0'),'.'):$raw;
    if ($display==='0' && $zeroMeansUnlimited) return ['state'=>'unlimited','display'=>''];
    return ['state'=>'finite','display'=>$display];
}

/** A recorded expiry has its own explicit sentinel, never epoch/negative coercion. */
function serviceExpiryLabel($value,callable $format,string $unlimited,string $unknown,
    bool $zeroMeansUnlimited=false,bool $nullMeansUnlimited=false): string {
    if ($value===null) return $nullMeansUnlimited?$unlimited:$unknown;
    if (!is_int($value) && !is_string($value)) return $unknown;
    $raw=(string)$value;
    if (strlen($raw)>10 || preg_match('/^(?:0|[1-9][0-9]*)$/D',$raw)!==1 ||
        (int)$raw>2147483647) return $unknown;
    if ($raw==='0') return $zeroMeansUnlimited?$unlimited:$unknown;
    try {
        $formatted=$format((int)$raw);
        return is_string($formatted) && $formatted!==''?$formatted:$unknown;
    } catch (\Throwable $error) {return $unknown;}
}
