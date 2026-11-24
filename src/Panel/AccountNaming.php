<?php
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

/**
 * One provider-bound username contract for customer draft admission,
 * pre-debit verification and the final paid/nonpaid effect dispatcher.
 * Byte-length is intentional: Marzban usernames are ASCII identifiers.
 */
function achbotEffectValidUsername(string $username,?string $provider=null): bool {
    // Keep the existing general/PasarGuard admission contract. Marzban's
    // actual Native /api/user username column rejects more than 32 bytes.
    $max=$provider==='marzban'?32:100;
    return strlen($username)>=1 && strlen($username)<=$max &&
        preg_match('/^[a-zA-Z0-9_-]+$/D',$username)===1;
}

/**
 * Preserve the configured account-name shape (server-user-digits or
 * server-digits), not an opaque provider-specific alias. Bulk digits are
 * source/ordinal-stable so a fresh random buildRemark() result cannot alter
 * a pending operation. The 007 journal, not this formatter, is replay truth.
 *
 * For long generated names only the server prefix is shortened to fit
 * Marzban's 32 ASCII bytes (PasarGuard: 100). Explicit single names stay
 * exact; bulk descriptions remain visible with source-distinct suffixes.
 */
function achbotStyledAccountRemark(string $base,string $provider,string $source,
    int $ordinal,int $count,string $mode,bool $explicit=false,
    bool $adminBulk=false): string {
    if ($source==='' || $ordinal<=0 || $count<=0 || $ordinal>$count)
        throw new InvalidArgumentException('account_name_source_invalid');
    $max=$provider==='marzban'?32:100;
    if (!in_array($provider,['marzban','pasarguard'],true))
        throw new InvalidArgumentException('account_name_provider_invalid');

    if ($explicit && $count===1) {
        // A customer's deliberately chosen single-account name is exact.
        $name=$base;
    } elseif ($explicit || ($mode==='manual' && $adminBulk)) {
        // Retain the selected human-readable name/plan description while
        // separating independent bulk orders and their line identities.
        if (preg_match('/^[A-Za-z0-9_-]+$/D',$base)!==1)
            throw new InvalidArgumentException('account_name_manual_invalid');
        $token=achbotAccountNumericToken($source,0);
        $suffix='_'.$token.'_'.$ordinal;
        $room=$max-strlen($suffix);
        if ($room<1) throw new InvalidArgumentException('account_name_too_long');
        $name=substr($base,0,$room).$suffix;
    } elseif ($provider==='pasarguard' && !$adminBulk && $count===1) {
        // Preserve the unmodified generated single-account name on PasarGuard.
        $name=$base;
    } else {
        // Both providers must bind NEW bulk generated names to immutable
        // source/ordinal. They differ only in their actual username ceiling.
        if (preg_match('/^(.+)-([0-9]{4,5})$/D',$base,$matches)!==1 ||
            preg_match('/^[A-Za-z0-9_-]+$/D',$matches[1])!==1)
            throw new InvalidArgumentException('account_name_generated_invalid');
        $numeric=($adminBulk || $count>1)
            ? achbotAccountNumericToken($source,$ordinal) : $matches[2];
        if ($mode==='digits') {
            $room=$max-strlen($numeric)-1;
            if ($room<1) throw new InvalidArgumentException('account_name_too_long');
            $name=substr($matches[1],0,$room).'-'.$numeric;
        } else {
            if (preg_match('/^(.+)-([1-9][0-9]*)$/D',$matches[1],$parts)!==1)
                throw new InvalidArgumentException('account_name_generated_invalid');
            $tail='-'.$parts[2].'-'.$numeric;
            $room=$max-strlen($tail);
            if ($room<1) throw new InvalidArgumentException('account_name_too_long');
            $name=substr($parts[1],0,$room).$tail;
        }
    }
    if (strlen($name)>$max ||
        preg_match('/^[A-Za-z0-9_-]+$/D',$name)!==1)
        throw new InvalidArgumentException('account_name_provider_invalid');
    return $name;
}

/** Five visible digits; adjacent lines of the same source cannot collide. */
function achbotAccountNumericToken(string $source,int $ordinal): string {
    // One source-derived starting point, then sequential line offsets.
    // The existing unique Native-target/effect index still fences collisions
    // with other sources before any remote CREATE.
    $start=(int)(hexdec(substr(hash('sha256',$source),0,11))%90000);
    return (string)(10000+(($start+$ordinal)%90000));
}

