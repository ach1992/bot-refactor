<?php
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

/**
 * One provider-bound username contract for customer draft admission,
 * pre-debit verification and the final paid/nonpaid effect dispatcher.
 * Byte-length is intentional: Native usernames are ASCII identifiers.
 */
function achbotEffectValidUsername(string $username,?string $provider=null): bool {
    return ($provider===null || $provider==='pasarguard') &&
        strlen($username)>=3 && strlen($username)<=100 &&
        preg_match('/^[a-zA-Z0-9_-]+$/D',$username)===1 &&
        preg_match('/[-_]{2}/',$username)!==1;
}

/**
 * Preserve the configured account-name shape (server-user-digits or
 * server-digits), not an opaque provider-specific alias. Bulk preferred digits are
 * source/ordinal-stable. The committed effect journal, not this formatter,
 * is replay truth.
 *
 * For long generated names only the server prefix is shortened to fit
 * the Native username limit. Explicit single names stay
 * exact; bulk descriptions remain visible with source-distinct suffixes.
 */
function achbotStyledAccountRemark(string $base,string $provider,string $source,
    int $ordinal,int $count,string $mode,bool $explicit=false,
    bool $adminBulk=false): string {
    if ($source==='' || $ordinal<=0 || $count<=0 || $ordinal>$count)
        throw new InvalidArgumentException('account_name_source_invalid');
    $max=100;
    if ($provider!=='pasarguard')
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
        $name=rtrim(substr($base,0,$room),'_-').$suffix;
    } elseif (!$adminBulk && $count===1) {
        // Preserve the visible shape; shorten only a long generated prefix.
        $name=$base;
        if (strlen($base)>$max) {
            $pattern=$mode==='digits'?'/^(.+)(-[0-9]{5})$/D':'/^(.+)(-[1-9][0-9]*-[0-9]{5})$/D';
            if (preg_match($pattern,$base,$parts)!==1 || strlen($parts[2])>=$max)
                throw new InvalidArgumentException('account_name_generated_invalid');
            $name=rtrim(substr($parts[1],0,$max-strlen($parts[2])),'_-').$parts[2];
        }
    } else {
        // New bulk generated names bind to the immutable source and ordinal.
        if (preg_match('/^(.+)-([0-9]{4,5})$/D',$base,$matches)!==1 ||
            preg_match('/^[A-Za-z0-9_-]+$/D',$matches[1])!==1)
            throw new InvalidArgumentException('account_name_generated_invalid');
        $numeric=($adminBulk || $count>1)
            ? achbotAccountNumericToken($source,$ordinal) : $matches[2];
        if ($mode==='digits') {
            $room=$max-strlen($numeric)-1;
            if ($room<1) throw new InvalidArgumentException('account_name_too_long');
            $name=rtrim(substr($matches[1],0,$room),'_-').'-'.$numeric;
        } else {
            if (preg_match('/^(.+)-([1-9][0-9]*)$/D',$matches[1],$parts)!==1)
                throw new InvalidArgumentException('account_name_generated_invalid');
            $tail='-'.$parts[2].'-'.$numeric;
            $room=$max-strlen($tail);
            if ($room<1) throw new InvalidArgumentException('account_name_too_long');
            $name=rtrim(substr($parts[1],0,$room),'_-').$tail;
        }
    }
    if (!achbotEffectValidUsername($name,$provider))
        throw new InvalidArgumentException('account_name_provider_invalid');
    return $name;
}

/** Five visible preferred digits; the physical name allocator fences other claims. */
function achbotAccountNumericToken(string $source,int $ordinal): string {
    // One source-derived starting point, then sequential line offsets.
    // Allocation resolves collisions before the original effect is committed.
    $start=(int)(hexdec(substr(hash('sha256',$source),0,11))%90000);
    return (string)(10000+(($start+$ordinal)%90000));
}

/**
 * Allocate a locally available name ONLY for a new, not-yet-journaled CREATE.
 * Keep the approved five-digit name shape and exact explicitly chosen single
 * names. A committed effect is never renamed, even after uncertain HTTP.
 * The physical-target unique index remains the atomic concurrency fence.
 */
function achbotAvailableAccountRemarkLocked(mysqli $db,string $targetKey,string $preferred,
    bool $explicitSingle=false,bool $manualBulk=false): string {
    require_once __DIR__.'/ProviderStorage.php';
    if ((int)achbotProviderRow($db,'SELECT @@in_transaction AS tx')['tx']!==1)
        throw new LogicException('service_account_name_requires_transaction');
    $used=achbotProviderRow($db,'SELECT id FROM service_effects
        WHERE target_key=? AND active_username=? FOR UPDATE','ss',[$targetKey,$preferred]);
    if ($used===null) return $preferred;
    if ($explicitSingle) throw new RuntimeException('service_account_name_conflict');
    $pattern=$manualBulk
        ? '/\A(.+_)([1-9][0-9]{4})(_[1-9][0-9]*)\z/D'
        : '/\A(.+-)([1-9][0-9]{4})()\z/D';
    if (preg_match($pattern,$preferred,$parts)!==1)
        throw new RuntimeException('service_account_name_conflict');
    [$unused,$prefix,$digits,$suffix]=$parts;
    // On collision, read the occupied names in this actual finite namespace
    // once (not up to 90,000 separate SQL round trips). Current locking read
    // avoids a stale snapshot after waiting for another reservation.
    $s=achbotProviderStatement($db,'SELECT active_username FROM service_effects
        WHERE target_key=? AND active_username BETWEEN ? AND ? FOR UPDATE',
        'sss',[$targetKey,$prefix.'10000'.$suffix,$prefix.'99999'.$suffix]);
    $occupied=[];
    try {
        $r=$s->get_result();
        while ($row=$r->fetch_assoc()) {
            $name=$row['active_username'];
            if (strlen($name)===strlen($preferred) && str_starts_with($name,$prefix) &&
                str_ends_with($name,$suffix)) {
                $token=substr($name,strlen($prefix),5);
                if (preg_match('/\A[1-9][0-9]{4}\z/D',$token)===1) $occupied[(int)$token]=true;
            }
        }
    } finally {$s->close();}
    for ($offset=1;$offset<90000;$offset++) {
        $token=10000+(((int)$digits-10000+$offset)%90000);
        if (!isset($occupied[$token])) return $prefix.$token.$suffix;
    }
    throw new RuntimeException('service_account_name_space_exhausted');
}
