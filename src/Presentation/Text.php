<?php
/**
 * AchBot presentation helpers. No bootstrap, database or network effects.
 * Legacy coercions, strings and edge behavior are intentional in V1.
 */
namespace AchBot\Presentation\Text;

/** Format data at its text sink; never use this for native URL/button values. */
function destinationText($value,?string $parseMode=null): string {
    $text=(string)$value;
    if (strtolower($parseMode??'')==='html')
        return htmlspecialchars($text,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    if (strtolower($parseMode??'')==='markdown')
        return strtr($text,['_'=>'\\_','*'=>'\\*','`'=>'\\`','['=>'\\[']);
    return $text;
}
/** Three-argument legacy callers retain their historical sequential behavior.
 * Current presentation callers supply exact template modes and substitute once. */
function replacePlaceholders(array $mainValues, array $botState, $userId = '', ?array $parseModes = null) {
    $placeholders = [
        'SUPPORTID'   => $botState['supportID']   ?? '',
        'LOCKCHANNEL' => $botState['lockChannel'] ?? '',
        'userid'      => (string)$userId,
    ];

    foreach ($mainValues as $key => $value) {
        if (is_string($value)) {

            if ($parseModes===null) {
                foreach ($placeholders as $placeholder => $replacement)
                    $value = str_replace($placeholder, $replacement, $value);
            } else {
                $mode=$parseModes[$key]??null;
                $replacements=[];
                foreach ($placeholders as $placeholder=>$replacement)
                    $replacements[$placeholder]=destinationText($replacement,$mode);
                // A URL containing another placeholder word is still literal data.
                $value=strtr($value,$replacements);
            }
            $mainValues[$key] = $value;
        }
        elseif (is_array($value)) {
            $mainValues[$key] = replacePlaceholders($value, $botState, $userId, $parseModes);
        }
    }

    return $mainValues;
}

/** A display destination is never fetched; reject credentials and unsafe URL schemes. */
function destinationUrl($value): string {
    if (!is_string($value)) return '';
    $value=trim($value);
    if ($value==='' || strlen($value)>1000 || preg_match('/[\\x00-\\x20\\x7f]/',$value)) return '';
    if (preg_match('/^@?([A-Za-z][A-Za-z0-9_]{0,63})$/D',$value,$m)) return 'https://t.me/'.$m[1];
    if (str_starts_with(strtolower($value),'t.me/')) $value='https://'.$value;
    $parts=parse_url($value);
    if (!$parts || !in_array(strtolower($parts['scheme']??''),['http','https'],true) ||
        empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) ||
        filter_var($value,FILTER_VALIDATE_URL)===false) return '';
    return $value;
}
function supportUrl(array $botState, string $fallback = '') {
    return destinationUrl($botState['supportID']??'') ?: $fallback;
}
function configuredDestination(array $botState,string $field,bool $supportFallback=false): string {
    return destinationUrl($botState[$field]??'') ?: ($supportFallback?supportUrl($botState):'');
}

/** Membership keeps its chat ID; a separate public invite destination is display-only. */
function lockChannelJoinUrl(array $botState,string $legacyJoinLink=''): string {
    $explicit=destinationUrl($botState['lockChannelJoinUrl']??'');
    if ($explicit!=='') return $explicit;
    $channel=is_string($botState['lockChannel']??null)?trim($botState['lockChannel']):'';
    if (preg_match('/^\+[A-Za-z0-9_-]+$/D',$channel)) return 'https://t.me/'.$channel;
    // Numeric IDs identify membership chats and are never public usernames.
    $public=preg_match('/^-?[0-9]+$/D',$channel)?'':destinationUrl($channel);
    return $public!==''?$public:destinationUrl($legacyJoinLink);
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
