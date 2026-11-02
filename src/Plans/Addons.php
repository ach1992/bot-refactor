<?php
/** AChBot current-only scoped extension catalog; requires optional migration 015. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {http_response_code(404);exit;}

/** A missing migration preserves legacy global offers; a partially applied one fails closed. */
function achbotAddonSchema(mysqli $db): bool {
    $required=['increase_day'=>['server_id','group_ids','scope_hash'],
        'increase_plan'=>['server_id','group_ids','scope_hash'],
        'pays'=>['addon_scope_snapshot']];
    $q=$db->query("SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('increase_day','increase_plan','pays')
        AND COLUMN_NAME IN ('server_id','group_ids','scope_hash','addon_scope_snapshot')");
    if (!$q) throw new RuntimeException('addon_schema_unavailable');
    $seen=[];while($row=$q->fetch_assoc()) $seen[$row['TABLE_NAME']][$row['COLUMN_NAME']]=true;
    $q->free();$count=0;
    foreach($required as $table=>$columns) foreach($columns as $col) {
        if (!empty($seen[$table][$col])) $count++;
    }
    if ($count===0) return false;
    if ($count!==7) throw new RuntimeException('addon_schema_partial');
    // Missing/altered DB uniqueness is not a usable migration: two concurrent
    // admins could insert competing prices for the same quantity and scope.
    $requiredIndexes=['increase_day'=>'uq_addon_day_units_scope',
        'increase_plan'=>'uq_addon_volume_units_scope'];
    $q=$db->query("SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,
        COLUMN_NAME,SUB_PART,INDEX_TYPE FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('increase_day','increase_plan')
        AND INDEX_NAME IN ('uq_addon_day_units_scope','uq_addon_volume_units_scope')");
    if (!$q) throw new RuntimeException('addon_schema_unavailable');
    $indexes=[];
    while ($row=$q->fetch_assoc())
        $indexes[$row['TABLE_NAME']][$row['INDEX_NAME']][(int)$row['SEQ_IN_INDEX']]=$row;
    $q->free();
    foreach ($requiredIndexes as $table=>$name) {
        $parts=$indexes[$table][$name]??[];
        ksort($parts,SORT_NUMERIC);
        if (array_keys($parts)!==[1,2]) throw new RuntimeException('addon_schema_incompatible');
        foreach (['volume','scope_hash'] as $i=>$column) {
            $part=$parts[$i+1];
            if ($part['COLUMN_NAME']!==$column || (int)$part['NON_UNIQUE']!==0 ||
                $part['SUB_PART']!==null || strtoupper((string)$part['INDEX_TYPE'])!=='BTREE')
                throw new RuntimeException('addon_schema_incompatible');
        }
    }
    $q=$db->query("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('increase_day','increase_plan','pays')");
    if (!$q) throw new RuntimeException('addon_schema_unavailable');
    $engines=[];while($row=$q->fetch_assoc()) $engines[$row['TABLE_NAME']]=$row['ENGINE'];
    $q->free();
    foreach (['increase_day','increase_plan','pays'] as $table)
        if (strcasecmp((string)($engines[$table]??''),'InnoDB')!==0)
            throw new RuntimeException('addon_schema_incompatible');
    return true;
}
function achbotAddonTable(string $kind): string {
    return match($kind) {'day'=>'increase_day','volume'=>'increase_plan',
        default=>throw new InvalidArgumentException('addon_kind_invalid')};
}
/** Compact, content-bound revision for the row the administrator actually saw.
 * Rechecks all pricing/scope fields before any old inline-button write.
 * A 20-hex token fits Telegram's 64-byte callback_data with the longest route.
 */
function achbotAddonOfferRevision(string $kind,array $row): string {
    achbotAddonTable($kind);
    if (!isset($row['id'],$row['volume'],$row['price']) || (int)$row['id']<=0)
        throw new RuntimeException('addon_admin_offer_invalid');
    $scope=achbotAddonScopeRow($row,true);
    return substr(hash('sha256',json_encode([$kind,(int)$row['id'],
        (string)$row['volume'],(string)$row['price'],$scope['scope_hash']],
        JSON_THROW_ON_ERROR)),0,20);
}
function achbotAddonOfferCallback(string $prefix,string $kind,array $row): string {
    $callback=$prefix.(int)$row['id'];
    if (array_key_exists('scope_hash',$row))
        $callback.='_'.achbotAddonOfferRevision($kind,$row);
    if (strlen($callback)>64) throw new RuntimeException('addon_admin_callback_too_long');
    return $callback;
}
function achbotAddonGroups(array $groups,bool $requireNonempty=false): array {
    require_once __DIR__.'/../Panel/PasarGuard.php';
    $groups=achbotPgGroupsCanonical($groups);
    if ($requireNonempty && !$groups) throw new RuntimeException('addon_group_scope_empty');
    return $groups;
}
/** Hash is an unambiguous, unique natural key for a particular scope. */
function achbotAddonScopeHash(?int $serverId,?array $groups): string {
    if ($serverId===null && $groups===null) return hash('sha256','G');
    if ($serverId===null || $serverId<=0) throw new RuntimeException('addon_scope_invalid');
    if ($groups===null) return hash('sha256','S:'.$serverId);
    return hash('sha256','P:'.$serverId.':'.implode(',',achbotAddonGroups($groups,true)));
}
function achbotAddonScopeRow(array $row,bool $migrated): array {
    $sid=$migrated?$row['server_id']:null;
    $json=$migrated?$row['group_ids']:null;
    if ($sid!==null && (!ctype_digit((string)$sid) || (int)$sid<=0))
        throw new RuntimeException('addon_scope_server_invalid');
    $sid=$sid===null?null:(int)$sid;
    if ($json!==null) {
        if ($sid===null || !is_string($json))
            throw new RuntimeException('addon_scope_groups_invalid');
        try {$groups=json_decode($json,true,64,JSON_THROW_ON_ERROR);}
        catch(Throwable $error) {throw new RuntimeException('addon_scope_groups_invalid');}
        if (!is_array($groups) || !array_is_list($groups) ||
            $groups!==achbotAddonGroups($groups,true) ||
            json_encode($groups,JSON_THROW_ON_ERROR)!==$json)
            throw new RuntimeException('addon_scope_groups_invalid');
    } else $groups=null;
    $hash=achbotAddonScopeHash($sid,$groups);
    if ($migrated && (!is_string($row['scope_hash']) ||
        !hash_equals($hash,$row['scope_hash'])))
        throw new RuntimeException('addon_scope_hash_changed');
    return ['server_id'=>$sid,'groups'=>$groups,'scope_hash'=>$hash];
}
/** Fetching every candidate also detects duplicated/conflicting offers; no lowest-price selection. */
function achbotAddonCatalog(mysqli $db,string $kind,array $context,?array $rows=null): array {
    $table=achbotAddonTable($kind);
    $migrated=achbotAddonSchema($db);
    if ($rows===null) {
        $q=$db->prepare("SELECT * FROM ".$table." ORDER BY id");
        if (!$q || !$q->execute()) throw new RuntimeException('addon_catalog_unavailable');
        $rows=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
    }
    $found=[];$verifiedPgGroups=null;
    foreach($rows as $r) {
        $units=achbotWholeServiceUnits($r['volume'],$kind==='day'?36500:achbotServiceVolumeMax());
        if ($units===null || !is_numeric($r['price']) || (int)$r['price']<0 ||
            (int)$r['price']>2147483647) continue;
        $scope=achbotAddonScopeRow($r,$migrated);
        $rank=0;
        if ($scope['server_id']===null) $rank=1;
        elseif ($scope['server_id']!==$context['server_id']) continue;
        elseif ($scope['groups']===null) $rank=2;
        elseif ($context['provider']==='pasarguard' && $scope['groups']===$context['groups']) {
            if ($verifiedPgGroups===null) {
                if (is_array($context['pg_groups_checked']??null)) {
                    $verifiedPgGroups=$context['pg_groups_checked'];
                } else {
                    if (!is_array($context['pg_panel']??null))
                        throw new RuntimeException('addon_native_group_catalog_unavailable');
                    // The same provider-authorized Group set governs scope
                    // creation, catalog visibility, quote and settlement.
                    $check=achbotPgCheck($context['pg_panel']);
                    if (($check['success']??false)!==true)
                        throw new RuntimeException('addon_native_group_catalog_unverified');
                    $verifiedPgGroups=achbotPgEligibleGroupIds($check);
                }
            }
            if (array_diff($scope['groups'],$verifiedPgGroups)) continue;
            $rank=3;
        } else continue;
        $offer=['id'=>(int)$r['id'],'volume'=>$units,'price'=>(int)$r['price'],
            'scope_hash'=>$scope['scope_hash'],'scope_server_id'=>$scope['server_id'],
            'scope_groups'=>$scope['groups']];
        if (!isset($found[$units]) || $rank>$found[$units]['rank'])
            $found[$units]=['rank'=>$rank,'offer'=>$offer];
        elseif ($rank===$found[$units]['rank'])
            throw new RuntimeException('addon_catalog_ambiguous');
    }
    ksort($found,SORT_NUMERIC);
    return array_values(array_map(static fn(array $item)=>$item['offer'],$found));
}
/** Native account groups must be verified against this local order's pinned identity. */
function achbotAddonContext(mysqli $db,array $order): array {
    $sid=(int)($order['server_id']??0);$oid=(int)($order['id']??0);
    $remark=$order['remark']??null;
    if ($sid<=0 || $oid<=0 || !is_string($remark) || $remark==='') throw new RuntimeException('addon_order_invalid');
    $q=$db->prepare("SELECT c.type FROM server_config c JOIN server_info i ON i.id=c.id WHERE c.id=?");
    $q->bind_param('i',$sid);$q->execute();$server=$q->get_result()->fetch_assoc();$q->close();
    $provider=$server['type']??null;
    if ($provider==='pasarguard') {
        require_once __DIR__.'/../Panel/ProviderRuntime.php';
        require_once __DIR__.'/../Panel/ServiceLifecycle.php';
        $ctx=achbotPgOrderContext($db,$oid);
        if ((int)$ctx['order']['server_id']!==$sid ||
            $ctx['order']['remark']!==$remark ||
            (int)$ctx['order']['userid']!==(int)$order['userid'])
            throw new RuntimeException('addon_order_identity_changed');
        $read=achbotPgRead($ctx['panel'],$ctx['identity']);
        if (($read['success']??false)!==true) throw new RuntimeException('addon_native_unverified');
        $groups=achbotAddonGroups($read['user']['group_ids']);
        // A native account whose current Groups exceed this credential's
        // effective access cannot be safely extended, even via a less
        // specific server/global fallback. Validate once per owned account,
        // then reuse the authoritative set while resolving its offer list.
        $access=achbotPgCheck($ctx['panel']);
        if (($access['success']??false)!==true)
            throw new RuntimeException('addon_native_group_authority_unverified');
        $eligible=achbotPgEligibleGroupIds($access);
        if (array_diff($groups,$eligible))
            throw new RuntimeException('addon_native_group_access_changed');
        $native=achbotServiceObservation((object)$read['user'],$remark);
        $identity=hash('sha256',json_encode($ctx['identity'],JSON_THROW_ON_ERROR));
    } elseif ($provider==='marzban') {
        require_once __DIR__.'/../Panel/ServiceLifecycle.php';
        $native=achbotServiceObserve($sid,$remark);
        $groups=[];
        $identity=null;
    } else throw new RuntimeException('addon_provider_unsupported');
    if (($native['verified']??false)!==true) throw new RuntimeException('addon_native_unverified');
    return ['server_id'=>$sid,'order_id'=>$oid,'remark'=>$remark,
        'provider'=>$provider,'groups'=>$groups,'identity'=>$identity,
        'pg_panel'=>$provider==='pasarguard'?$ctx['panel']:null,
        'pg_groups_checked'=>$provider==='pasarguard'?$eligible:null,
        'observation'=>$native];
}
function achbotAddonOfferById(mysqli $db,string $kind,array $context,int $offerId): ?array {
    if ($offerId<=0) return null;
    foreach(achbotAddonCatalog($db,$kind,$context) as $offer)
        if ($offer['id']===$offerId) return $offer;
    return null;
}
/** Issued quote pins scope + native Group-set and selected offer; price never recomputed. */
function achbotAddonQuote(array $order,array $context,string $kind,array $offer,int $finalPrice): string {
    if ($finalPrice<0 || $finalPrice>2147483647 ||
        $context['server_id']!==(int)$order['server_id'] ||
        $context['order_id']!==(int)$order['id']) throw new RuntimeException('addon_quote_invalid');
    return json_encode(['version'=>1,'kind'=>$kind,'offer_id'=>$offer['id'],
        'order_id'=>(int)$order['id'],'server_id'=>$context['server_id'],
        'provider'=>$context['provider'],'remark'=>$context['remark'],
        'native_identity'=>$context['identity'],'native_groups'=>$context['groups'],
        'scope_hash'=>$offer['scope_hash'],'units'=>$offer['volume'],
        'base_price'=>$offer['price'],'final_price'=>$finalPrice],
        JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
}
/** Local quote and catalog checks: no provider HTTP; safe inside a locked SQL claim. */
function achbotAddonPayLocal(mysqli $db,array $pay,array $order): bool {
    $type=(string)($pay['type']??'');
    if (preg_match('/^INCREASE_(DAY|VOLUME)_([1-9][0-9]*)_([1-9][0-9]*)$/D',$type,$parts)!==1)
        return false;
    $kind=strtolower($parts[1]);$offerId=(int)$parts[3];
    if ((int)$parts[2]!==(int)$order['id'] ||
        (int)($pay['order_id']??0)!==(int)$order['id'] ||
        (int)($pay['server_id']??0)!==(int)$order['server_id']) return false;
    $units=achbotPinnedAddonUnits($pay);if ($units===null) return false;
    $migrated=achbotAddonSchema($db);
    $snapshot=$pay['addon_scope_snapshot']??null;
    if ($snapshot===null) {
        // Pre-015 invoices are historical global offers; retain immutable
        // amount/units and disallow conversion into a scoped later offer.
        $table=achbotAddonTable($kind);
        $s=$db->prepare("SELECT * FROM ".$table." WHERE id=?");
        $s->bind_param('i',$offerId);$s->execute();
        $row=$s->get_result()->fetch_assoc();$s->close();
        if (!$row) return false;
        $scope=achbotAddonScopeRow($row,$migrated);
        return $scope['server_id']===null &&
            achbotWholeServiceUnits($row['volume'],$kind==='day'?36500:achbotServiceVolumeMax())===$units;
    }
    if (!$migrated || !is_string($snapshot) || strlen($snapshot)>4096) return false;
    try {$s=json_decode($snapshot,true,32,JSON_THROW_ON_ERROR);}
    catch(Throwable $error){return false;}
    if (!is_array($s) || ($s['version']??null)!==1 ||
        ($s['kind']??null)!==$kind || ($s['offer_id']??null)!==$offerId ||
        ($s['order_id']??null)!==(int)$order['id'] ||
        ($s['server_id']??null)!==(int)$order['server_id'] ||
        ($s['remark']??null)!==($order['remark']??null) ||
        ($s['units']??null)!==$units ||
        ($s['final_price']??null)!==(int)$pay['price'] ||
        !is_int($s['base_price']??null) ||
        !is_string($s['scope_hash']??null) ||
        !in_array($s['provider']??null,['marzban','pasarguard'],true) ||
        !is_array($s['native_groups']??null) ||
        $s['native_groups']!==achbotAddonGroups($s['native_groups']) ||
        ($s['provider']==='marzban' && ($s['native_groups']!==[] || ($s['native_identity']??null)!==null)) ||
        ($s['provider']==='pasarguard' && !is_string($s['native_identity']??null)))
        return false;
    $row=$db->prepare("SELECT * FROM ".achbotAddonTable($kind)." WHERE id=?");
    $row->bind_param('i',$offerId);$row->execute();$offer=$row->get_result()->fetch_assoc();$row->close();
    if (!$offer || achbotWholeServiceUnits($offer['volume'],$kind==='day'?36500:achbotServiceVolumeMax())!==$units)
        return false;
    $scope=achbotAddonScopeRow($offer,true);
    return hash_equals($scope['scope_hash'],$s['scope_hash']);
}
/** Complete verification requires native readback and highest-specificity selection. */
function achbotAddonPayEligible(mysqli $db,array $pay,array $order,?array $context=null): bool {
    try {
        if (!achbotAddonPayLocal($db,$pay,$order)) return false;
        $context=$context??achbotAddonContext($db,$order);
        $snapshot=$pay['addon_scope_snapshot']??null;
        if ($snapshot===null) return true; // Older globally quoted payment, no new price.
        $s=json_decode($snapshot,true,32,JSON_THROW_ON_ERROR);
        if ($s['provider']!==$context['provider'] ||
            $s['remark']!==$context['remark'] ||
            $s['server_id']!==$context['server_id'] ||
            $s['native_groups']!==$context['groups'] ||
            $s['native_identity']!==$context['identity']) return false;
        $kind=strtolower(explode('_',(string)$pay['type'])[1]);
        $offer=achbotAddonOfferById($db,$kind,$context,(int)$s['offer_id']);
        return $offer!==null && hash_equals($offer['scope_hash'],$s['scope_hash']);
    } catch(Throwable $error){return false;}
}
/** Extra issuance fence: an admin edit between menu display and COMMIT must
 * never create a new quote at the stale, unpinned menu price.
 */
function achbotAddonDraftCurrent(mysqli $db,int $payId): bool {
    try {
        if (!achbotAddonSchema($db)) return true; // Legacy DB only supports legacy global catalog.
        $q=$db->prepare('SELECT * FROM pays WHERE id=?');
        $q->bind_param('i',$payId);$q->execute();$pay=$q->get_result()->fetch_assoc();$q->close();
        if (!$pay || preg_match('/^INCREASE_(DAY|VOLUME)_([1-9][0-9]*)_([1-9][0-9]*)$/D',
            (string)$pay['type'],$m)!==1 || !is_string($pay['addon_scope_snapshot']??null))
            return false;
        $oid=(int)$m[2];$q=$db->prepare('SELECT * FROM orders_list WHERE id=?');
        $q->bind_param('i',$oid);$q->execute();$order=$q->get_result()->fetch_assoc();$q->close();
        if (!$order || (int)($order['userid']??0)!==(int)$pay['user_id'] ||
            !achbotAddonPayLocal($db,$pay,$order)) return false;
        $kind=strtolower($m[1]);$snap=json_decode($pay['addon_scope_snapshot'],true,32,JSON_THROW_ON_ERROR);
        $type=$db->prepare('SELECT type FROM server_config WHERE id=? LOCK IN SHARE MODE');
        $sid=(int)$pay['server_id'];$type->bind_param('i',$sid);$type->execute();
        $server=$type->get_result()->fetch_assoc();$type->close();
        if (!$server || $server['type']!==$snap['provider']) return false;
        $context=['provider'=>$snap['provider'],'server_id'=>$sid,'groups'=>$snap['native_groups'],
            'pg_groups_checked'=>$snap['native_groups']];
        $table=achbotAddonTable($kind);
        $units=(int)$snap['units'];
        // Current locking read of the entire quantity range protects the
        // selected scope and the precedence against a concurrent admin
        // insert, delete, re-scope or price edit through COMMIT. A plain
        // transaction-snapshot SELECT would be stale under REPEATABLE READ.
        $q=$db->prepare("SELECT * FROM ".$table." WHERE volume=? ORDER BY id FOR UPDATE");
        $q->bind_param('i',$units);$q->execute();
        $rows=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
        $offers=achbotAddonCatalog($db,$kind,$context,$rows);
        if (count($offers)!==1) return false;
        $chosen=$offers[0];
        return $chosen['id']===(int)$m[3] &&
            hash_equals($chosen['scope_hash'],$snap['scope_hash']) &&
            $chosen['volume']===$units &&
            $chosen['price']===$snap['base_price'];
    } catch(Throwable $error) {return false;}
}
