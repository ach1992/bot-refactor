<?php
/** AChBot V7: immutable V6.1 purchase helper definitions. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG

require_once __DIR__.'/../Panel/ProviderRuntime.php';

/**
 * Compatibility hook called before new payment-draft INSERTs. It MUST remain
 * non-destructive: the successor INSERT/COMMIT can still fail and some callers
 * already hold a MariaDB transaction. Shared PaymentLifecycle expiry later
 * transitions only unclaimed old drafts and preserves the original pays ID.
 */
if (!function_exists('achbotRemoveUnclaimedPendingPayments')) {
    function achbotRemoveUnclaimedPendingPayments(mysqli $db, int $userId,
                                                   string $type): void {
        if ($userId<=0 || !in_array($type,[
            'BUY_SUB','RENEW_SCONFIG','RENEW_ACCOUNT',
            'INCREASE_DAY','INCREASE_VOLUME','INCREASE_WALLET'
        ],true)) throw new InvalidArgumentException('unsupported_payment_draft_cleanup');
        // Called BEFORE creating the replacement. Do not delete, expire or
        // touch the old draft yet: an insert or COMMIT can still fail, and a
        // wallet top-up caller already owns an InnoDB transaction here.
        // The one-minute lifecycle worker + guarded URL/callback checks expire
        // only a genuinely old unclaimed draft once a successor commits.
        // Existing signature remains for backward-compatible callsites.
        return;
    }
}

if (!function_exists('getPlanDetails')) {
    function getPlanDetails($connection, $planId)
    {
        $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
        $stmt->bind_param("i", $planId);
        $stmt->execute();
        $plan = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$plan) {
            alert(\achbotMessage('purchase.core.get_plan_details.alert.93f191e066'));
            exit;
        }

        $days   = (int)$plan['days'];
        $vol    = (int)$plan['volume'];
        $price  = isset($plan['price']) ? (int)$plan['price'] : 0;
        $date   = time();
        $expire_microdate = floor(microtime(true) * 1000) + (864000 * $days * 100);
        $expire_date      = $date + (86400 * $days);

        return [
            'plan'             => $plan,
            'days'             => $days,
            'volume'           => $vol,
            'price'            => $price,
            'date'             => $date,
            'expire_microdate' => $expire_microdate,
            'expire_date'      => $expire_date,
        ];
    }
}

if (!function_exists('ensureServerCapacity')) {
    function ensureServerCapacity($connection, $server_id, $inbound_id, $acount, $mainValues)
    {
        if ((int)$acount === 0 && (int)$inbound_id !== 0) {
            alert($mainValues['out_of_connection_capacity']);
            exit;
        }

        $server_info = null;

        if ((int)$inbound_id === 0) {
            $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
            $stmt->bind_param("i", $server_id);
            $stmt->execute();
            $server_info = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$server_info || (int)$server_info['ucount'] <= 0) {
                alert($mainValues['out_of_server_capacity']);
                exit;
            }
        }
        return $server_info;
    }
}

// Shared read-only preflight for current purchase/manual approval/wallet.
// Type + inbound are validated as one provider contract before settlement.
if (!function_exists('achbotSupportsMarzbanPlan')) {
    function achbotSupportsMarzbanPlan($connection, $planId, $requestedInboundId = 0, $requiredServerId = null, bool $lock = false)
    {
        $planId = (int)$planId;
        $stmt = $connection->prepare(
            "SELECT p.`inbound_id`, p.`server_id`, c.`type`
             FROM `server_plans` p
             INNER JOIN `server_config` c ON c.`id` = p.`server_id`
             WHERE p.`id` = ? AND p.active=1 LIMIT 1".($lock ? ' FOR UPDATE' : '')
        );
        $stmt->bind_param("i", $planId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $planId > 0 && (int)$requestedInboundId === 0 &&
            $row && $row['type'] === 'marzban' &&
            (int)$row['inbound_id'] === 0 &&
            ($requiredServerId === null || (int)$requiredServerId === 0 ||
             (int)$requiredServerId === (int)$row['server_id']);
    }
}

// Post-V9: read-only order and payment-subject validation at *entrance*.
// All consumers use the same current provider and 23-table schema; R4 adds
// only one separately migrated nullable server_info column.
if (!function_exists('achbotSupportsMarzbanAccount')) {
    function achbotSupportsMarzbanAccount($type, $inboundId)
    {
        return $type === 'marzban' && (int)$inboundId === 0;
    }
}

// R4: physical panel is explicitly identified by an admin-managed group.
// Different API URLs and admin users can access the SAME backend.
// Only a nullable server_info.panel_group_id column is added by separate SQL
// migration; historical V9 DDL remains frozen.
if (!function_exists('achbotMarzbanGroupValid')) {
    function achbotMarzbanGroupValid($name) {
        $name = is_string($name) ? $name : '';
        // Reject ALL line breaks, including trailing ones before trimming.
        // 60 Unicode characters fit setting.value TEXT, including emoji.
        if (preg_match('/\R/u', $name) !== 0) return false;
        $name = trim($name);
        return $name !== '' && preg_match('/\A.{1,60}\z/u', $name) === 1;
    }
}
if (!function_exists('achbotMarzbanPanelGroups')) {
    function achbotMarzbanPanelGroups($connection) {
        $stmt = $connection->prepare(
            "SELECT id, value AS name FROM setting WHERE type = 'ACHBOT_MARZBAN_PANEL_GROUP' ORDER BY id"
        );
        $stmt->execute();
        $result = $stmt->get_result();
        $groups = [];
        while ($row = $result->fetch_assoc()) {
            $groups[] = ['id'=>(int)$row['id'], 'name'=>(string)$row['name']];
        }
        $stmt->close();
        return $groups;
    }
}
if (!function_exists('achbotCreateMarzbanPanelGroup')) {
    function achbotCreateMarzbanPanelGroup($connection, $name) {
        if (!achbotMarzbanGroupValid($name)) return null;
        $name = trim((string)$name);
        // No extra schema/index is authorized. MariaDB's connection-scoped
        // advisory lock serializes ALL group-name checks and inserts.
        // This helper owns its transaction: release the lock only AFTER
        // commit/rollback so another creator cannot observe uncommitted data.
        $lockName = 'achbot_post_v9_marzban_group_create';
        $stmt = $connection->prepare('SELECT GET_LOCK(?, 10) AS acquired');
        $stmt->bind_param('s', $lockName);
        $stmt->execute();
        $acquired = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ((int)($acquired['acquired'] ?? 0) !== 1) {
            throw new \RuntimeException('panel_group_create_lock_unavailable');
        }
        try {
            $connection->begin_transaction();
            try {
                $stmt = $connection->prepare(
                    "SELECT id FROM setting WHERE type = 'ACHBOT_MARZBAN_PANEL_GROUP' AND value = ? LIMIT 1"
                );
                $stmt->bind_param('s', $name);
                $stmt->execute();
                $exists = $stmt->get_result()->num_rows > 0;
                $stmt->close();
                if ($exists) {
                    $connection->rollback();
                    return null;
                }
                $stmt = $connection->prepare(
                    "INSERT INTO setting (type,value) VALUES ('ACHBOT_MARZBAN_PANEL_GROUP', ?)"
                );
                $stmt->bind_param('s', $name);
                $stmt->execute();
                $id = (int)$stmt->insert_id;
                $stmt->close();
                if ($id <= 0) throw new \RuntimeException('panel_group_insert_failed');
                $connection->commit();
                return $id;
            } catch (\Throwable $e) {
                $connection->rollback();
                throw $e;
            }
        } finally {
            $stmt = $connection->prepare('SELECT RELEASE_LOCK(?)');
            $stmt->bind_param('s', $lockName);
            $stmt->execute();
            $stmt->close();
        }
    }
}
if (!function_exists('achbotMarzbanGroup')) {
    function achbotMarzbanGroup($connection, $serverId) {
        if ((int)$serverId <= 0) return null;
        $stmt = $connection->prepare(
            "SELECT g.id, g.value AS name
             FROM server_info s INNER JOIN server_config c ON c.id = s.id
             INNER JOIN setting g ON g.id = s.panel_group_id
                     AND g.type = 'ACHBOT_MARZBAN_PANEL_GROUP'
             WHERE s.id = ? AND c.type = 'marzban' LIMIT 1"
        );
        $serverId = (int)$serverId;
        $stmt->bind_param('i', $serverId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? ['id'=>(int)$row['id'], 'name'=>(string)$row['name']] : null;
    }
}
if (!function_exists('achbotSetMarzbanGroup')) {
    function achbotSetMarzbanGroup($connection, $serverId, $groupId) {
        // Caller must own SQL transaction and server_config row lock.
        $serverId = (int)$serverId;
        $groupId = ($groupId === null || (int)$groupId === 0) ? null : (int)$groupId;
        if ($serverId <= 0 || ($groupId !== null && $groupId <= 0)) {
            throw new \InvalidArgumentException('invalid_panel_group_assignment');
        }
        if ($groupId !== null) {
            $stmt = $connection->prepare(
                "SELECT id FROM setting WHERE id = ? AND type = 'ACHBOT_MARZBAN_PANEL_GROUP' FOR UPDATE"
            );
            $stmt->bind_param('i', $groupId);
            $stmt->execute();
            $exists = $stmt->get_result()->num_rows === 1;
            $stmt->close();
            if (!$exists) throw new \RuntimeException('unknown_panel_group');
        }
        $stmt = $connection->prepare(
            "SELECT o.server_id AS order_server, p.server_id AS plan_server,
                    os.panel_group_id AS order_group, ps.panel_group_id AS plan_group
             FROM orders_list o
             INNER JOIN server_plans p ON p.id = o.fileid
             INNER JOIN server_info os ON os.id = o.server_id
             INNER JOIN server_info ps ON ps.id = p.server_id
             WHERE o.status = 1 AND (o.server_id = ? OR p.server_id = ?)
                   AND o.server_id != p.server_id"
        );
        $stmt->bind_param('ii', $serverId, $serverId);
        $stmt->execute();
        $affected = $stmt->get_result();
        while ($row = $affected->fetch_assoc()) {
            $orderGroup = (int)$row['order_server'] === $serverId
                ? $groupId : ($row['order_group'] === null ? null : (int)$row['order_group']);
            $planGroup = (int)$row['plan_server'] === $serverId
                ? $groupId : ($row['plan_group'] === null ? null : (int)$row['plan_group']);
            if ($orderGroup === null || $planGroup === null || $orderGroup !== $planGroup) {
                $stmt->close();
                throw new \RuntimeException('group_change_would_strand_order');
            }
        }
        $stmt->close();
        $stmt = $connection->prepare("UPDATE server_info SET panel_group_id = ? WHERE id = ?");
        $stmt->bind_param('ii', $groupId, $serverId);
        $stmt->execute();
        $stmt->close();
    }
}
if (!function_exists('achbotDeleteMarzbanPanelGroup')) {
    function achbotDeleteMarzbanPanelGroup($connection, $groupId) {
        $groupId = (int)$groupId;
        if ($groupId <= 0) return false;
        $stmt = $connection->prepare(
            "DELETE FROM setting WHERE id = ?
               AND type = 'ACHBOT_MARZBAN_PANEL_GROUP'
               AND NOT EXISTS (SELECT 1 FROM server_info WHERE panel_group_id = ?)"
        );
        $stmt->bind_param('ii', $groupId, $groupId);
        $stmt->execute();
        $success = $stmt->affected_rows === 1;
        $stmt->close();
        return $success;
    }
}
if (!function_exists('achbotSameMarzbanPanel')) {
    function achbotSameMarzbanPanel($connection, $sourceId, $targetId, bool $lock = false) {
        $sourceId = (int)$sourceId;
        $targetId = (int)$targetId;
        if ($sourceId <= 0 || $targetId <= 0 || $sourceId === $targetId) return false;
        // ONLY explicit valid shared group membership is authoritative;
        // no assumptions from URL, username, an empty group or panel type.
        $stmt = $connection->prepare(
            "SELECT a.id FROM server_info a
             INNER JOIN server_config ac ON ac.id = a.id AND ac.type = 'marzban'
             INNER JOIN server_info b ON b.id = ?
             INNER JOIN server_config bc ON bc.id = b.id AND bc.type = 'marzban'
             INNER JOIN setting g ON g.id = a.panel_group_id
                  AND g.type = 'ACHBOT_MARZBAN_PANEL_GROUP'
             WHERE a.id = ? AND a.panel_group_id IS NOT NULL
                   AND a.panel_group_id = b.panel_group_id LIMIT 1".($lock ? ' FOR UPDATE' : '')
        );
        $stmt->bind_param('ii', $targetId, $sourceId);
        $stmt->execute();
        $same = $stmt->get_result()->num_rows === 1;
        $stmt->close();
        return $same;
    }
}

// Panel grouping is a permission to remap the bot's logical server label,
// not an authorization to modify or migrate the actual Marzban account.
if (!function_exists('achbotMarzbanRelocationTargets')) {
    function achbotMarzbanRelocationTargets($connection, $sourceId)
    {
        $stmt = $connection->prepare(
            "SELECT s.`id`, s.`title` FROM `server_info` s
             INNER JOIN `server_config` c ON c.`id` = s.`id`
             WHERE s.`active` = 1 AND s.`state` = 1 AND s.`ucount` > 0
               AND c.`type` = 'marzban' AND s.`id` != ? ORDER BY s.`id`"
        );
        $sourceId = (int)$sourceId;
        $stmt->bind_param('i', $sourceId);
        $stmt->execute();
        $res = $stmt->get_result();
        $targets = [];
        while ($server = $res->fetch_assoc()) {
            if (achbotSameMarzbanPanel($connection, $sourceId, (int)$server['id'])) {
                $targets[] = $server;
            }
        }
        $stmt->close();
        return $targets;
    }
}

if (!function_exists('achbotReassignMarzbanLogicalServer')) {
    function achbotReassignMarzbanLogicalServer($connection, $orderId, $targetId, $actorId, $adminAllowed = false)
    {
        $orderId = (int)$orderId;
        $targetId = (int)$targetId;
        if ($orderId <= 0 || $targetId <= 0) return null;
        $connection->begin_transaction();
        try {
            $stmt = $connection->prepare(
                "SELECT `id`, `userid`, `server_id`, `inbound_id`, `remark`
                 FROM `orders_list` WHERE `id` = ? AND `status` = 1 FOR UPDATE"
            );
            $stmt->bind_param('i', $orderId);
            $stmt->execute();
            $order = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$order || (!$adminAllowed && (int)$order['userid'] !== (int)$actorId) ||
                (int)$order['inbound_id'] !== 0 ||
                (int)$order['server_id'] === $targetId) {
                $connection->rollback();
                return null;
            }

            $oldId = (int)$order['server_id'];
            // Serialize an in-flight reassignment with admin panel/group edits
            // and concurrent reassignments. Always lock panel IDs in order.
            $stmt = $connection->prepare(
                "SELECT `id` FROM `server_config`
                 WHERE `id` IN (?, ?) ORDER BY `id` FOR UPDATE"
            );
            $stmt->bind_param('ii', $oldId, $targetId);
            $stmt->execute();
            $count = $stmt->get_result()->num_rows;
            $stmt->close();
            if ($count !== 2 ||
                !achbotSupportsMarzbanOrder($connection, $orderId) ||
                !achbotSameMarzbanPanel($connection, $oldId, $targetId)) {
                $connection->rollback();
                return null;
            }

            $stmt = $connection->prepare(
                "SELECT s.`title` FROM `server_info` s
                 INNER JOIN `server_config` c ON c.`id` = s.`id`
                 WHERE s.`id` = ? AND s.`active` = 1 AND s.`state` = 1
                   AND s.`ucount` > 0 AND c.`type` = 'marzban' FOR UPDATE"
            );
            $stmt->bind_param('i', $targetId);
            $stmt->execute();
            $server = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$server) {
                $connection->rollback();
                return null;
            }

            // Preserve V9's DB-only logical reassignment. No capacity,
            // fileid, money, token, remote panel, proxy or link mutation.
            $stmt = $connection->prepare(
                "UPDATE `orders_list` SET `server_id` = ? WHERE `id` = ? AND `server_id` = ?"
            );
            $stmt->bind_param('iii', $targetId, $orderId, $oldId);
            $stmt->execute();
            $changed = $stmt->affected_rows === 1;
            $stmt->close();
            if (!$changed) {
                $connection->rollback();
                return null;
            }
            $connection->commit();
            return ['id'=>$orderId, 'userid'=>$order['userid'],
                    'remark'=>$order['remark'], 'title'=>$server['title'],
                    'source_id'=>$oldId, 'target_id'=>$targetId];
        } catch (\Throwable $e) {
            $connection->rollback();
            return null;
        }
    }
}

if (!function_exists('achbotSupportsMarzbanOrder')) {
    function achbotSupportsMarzbanOrder($connection, $orderId, $requiredPlanId = null, $requiredUser = null, bool $lock = false)
    {
        if ((int)$orderId <= 0) return false;
        $stmt = $connection->prepare(
            "SELECT o.`userid`, o.`fileid`, o.`server_id`, o.`inbound_id`,
                    c.`type`, p.`server_id` AS plan_server,
                    p.`inbound_id` AS plan_inbound
             FROM `orders_list` o
             LEFT JOIN `server_config` c ON c.`id` = o.`server_id`
             LEFT JOIN `server_plans` p ON p.`id` = o.`fileid`
             WHERE o.`id` = ? LIMIT 1".($lock ? ' FOR UPDATE' : '')
        );
        $orderId = (int)$orderId;
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$order || !achbotSupportsMarzbanAccount($order['type'], $order['inbound_id'])) {
            return false;
        }
        if ($requiredUser !== null && (int)$order['userid'] !== (int)$requiredUser) return false;
        if ($requiredPlanId !== null && (int)$order['fileid'] !== (int)$requiredPlanId) return false;
        // Existing manually provisioned accounts may have fileid=0. For
        // financial renewal a real matching plan is explicitly required.
        if ((int)$order['fileid'] === 0) return $requiredPlanId === null;
        return $order['plan_server'] !== null &&
            (int)$order['plan_inbound'] === 0 &&
            ((int)$order['plan_server'] === (int)$order['server_id'] ||
             achbotSameMarzbanPanel($connection,
                 (int)$order['plan_server'], (int)$order['server_id'], $lock));
    }
}

/** Fresh native baseline shared by add-on selection and pre-payment checks. */
if (!function_exists('achbotOrderAddonEligibility')) {
function achbotOrderAddonEligibility($connection,array $order,string $kind): array {
    $out=['eligible'=>false,'reason'=>'unknown'];
    if (!($connection instanceof mysqli) || !in_array($kind,['day','volume'],true) ||
        (int)($order['id']??0)<=0 || (int)($order['server_id']??0)<=0 ||
        !is_string($order['remark']??null) || $order['remark']==='') return $out;
    require_once __DIR__.'/../Panel/ServiceLifecycle.php';
    $native=achbotServiceObserve((int)$order['server_id'],$order['remark']);
    if (!$native['verified'] || !in_array($native['status'],['active','disabled','expired','limited'],true)) return $out;
    if ($native['expire']===null) return ['eligible'=>false,'reason'=>'unlimited_time'];
    // Existing day extensions may revive an expired finite account. Volume
    // extensions preserve its date and therefore require a still-valid date.
    if ($kind==='volume' && $native['expire']<=time()) return ['eligible'=>false,'reason'=>'expired'];
    if ($kind==='volume' && $native['quota']===null) return ['eligible'=>false,'reason'=>'unlimited_volume'];
    return ['eligible'=>true,'reason'=>''];
}
}

if (!function_exists('achbotSupportsPaymentSubject')) {
    function achbotSupportsPaymentSubject($connection, $pay, bool $lock = false)
    {
        if (!is_array($pay)) return false;
        $type = (string)($pay['type'] ?? '');
        // Wallet top-ups are deliberately not tied to VPN providers.
        if ($type === 'INCREASE_WALLET') return true;
        if (!achbotProviderQuoteEligible($connection,$pay,$lock)) return false;
        $pid = (int)($pay['plan_id'] ?? 0);
        $uid = (int)($pay['user_id'] ?? 0);
        if ($type === 'BUY_SUB') {
            return $pid > 0 && achbotSupportsPlan(
                $connection, $pid, 0, $pay['server_id'] ?? null, $lock
            );
        }
        if ($type === 'RENEW_SCONFIG') {
            // External-account renewal is not an orders_list renewal. Its
            // saved Marzban identity must match the selected plan server;
            // a stale X-UI-shaped receipt or another server's remark must
            // never be treated as the current Marzban account.
            $source = json_decode((string)($pay['description'] ?? ''), true);
            $sid = (int)($pay['server_id'] ?? 0);
            return $pid > 0 && $uid > 0 && $sid > 0 &&
                (int)($pay['volume'] ?? -1) === 0 &&
                is_array($source) && ($source['marzban'] ?? null) === true &&
                !empty($source['remark']) &&
                (int)($source['server_id'] ?? 0) === $sid &&
                achbotSupportsMarzbanPlan($connection, $pid, 0, $sid, $lock);
        }
        if ($type === 'RENEW_ACCOUNT') {
            return $pid > 0 && achbotSupportsOrder(
                $connection, (int)($pay['order_id'] ?? 0), $pid, $uid > 0 ? $uid : null, $lock
            );
        }
        if (preg_match('/^INCREASE_(DAY|VOLUME)_(\d+)_(\d+)$/', $type, $match)) {
            $oid = (int)$match[2];
            $addon = (int)$match[3];
            if ($addon <= 0 || !achbotSupportsOrder(
                $connection, $oid, $pid > 0 ? $pid : null, $uid > 0 ? $uid : null, $lock
            )) return false;
            if (!empty($pay['order_id']) && (int)$pay['order_id'] !== $oid) return false;
            if (achbotPinnedAddonUnits($pay)===null) return false;
            $stmt=$connection->prepare('SELECT id,server_id,remark FROM orders_list WHERE id=?'.($lock?' FOR UPDATE':''));
            $stmt->bind_param('i',$oid);$stmt->execute();$order=$stmt->get_result()->fetch_assoc();$stmt->close();
            return $order && achbotOrderAddonEligibility($connection,$order,strtolower($match[1]))['eligible'];
        }
        return false;
    }
}

/**
 * Provider-independent paid service modification boundary.
 *
 * Payments (wallet, automatic bank receipt, hosted gateways and operator
 * evidence) must call THIS function rather than Marzban's transport directly.
 * Each provider adapter must prove its own effect and return a normalized
 * verified absolute result; a plain HTTP acknowledgement is NOT success.
 *
 * Adding a panel (for example Pasarguard) extends only the adapter dispatch
 * and its verified account semantics. It does not grant that panel support
 * until its readback/effect tests pass.
 */
if (!function_exists('achbotApplyServiceEdit')) {
    function achbotApplyServiceEdit($db, $serverId, array $change,
                                   ?array $giftTarget=null): object {
        $serverId=(int)$serverId;
        if (!($db instanceof mysqli) || $serverId<=0 ||
            !isset($change['remark']) ||
            !is_string($change['remark']) || $change['remark']==='')
            return (object)['success'=>false,'uncertain'=>false,
                'msg'=>'service_edit_invalid_request'];

        if ((int)($change['pay_id']??0)>0 && !achbotServiceEditMatchesPay($db,(int)$change['pay_id'],$change))
            return (object)['success'=>false,'uncertain'=>true,'msg'=>'service_quote_units_unverified'];
        $stmt=$db->prepare('SELECT type FROM server_config WHERE id=?');
        if (!$stmt) return (object)['success'=>false,'uncertain'=>true,
                                   'msg'=>'service_adapter_lookup_unavailable'];
        $stmt->bind_param('i',$serverId);
        if (!$stmt->execute()) {
            $stmt->close();
            return (object)['success'=>false,'uncertain'=>true,
                            'msg'=>'service_adapter_lookup_unavailable'];
        }
        $panel=$stmt->get_result()->fetch_assoc();
        $stmt->close();
        $type=(string)($panel['type']??'');
        if ($giftTarget!==null) {
            require_once __DIR__ . '/../Subscription/GiftBatch.php';
            if (!achbotSupportsAccount($type,0) ||
                !achbotGiftTargetMatches($db,$serverId,$giftTarget))
                return (object)['success'=>false,'uncertain'=>true,
                                'msg'=>'gift_service_target_changed'];
        }
        // The transport-specific code only exists inside its own adapter.
        // No speculative fallback or implicit behavior for future panels.
        $outcome=achbotProviderServiceEdit($db,$type,$serverId,$change,$giftTarget);
        if (!is_object($outcome) && !is_array($outcome))
            return (object)['success'=>false,'uncertain'=>true,
                            'msg'=>'service_edit_invalid_outcome'];
        $outcome=is_array($outcome)?(object)$outcome:$outcome;
        if (($outcome->success??false)!==true) return $outcome;
        // Native Marzban preserves literal zero expiry on a quota-only gift.
        // Admit that exact verified observation only for the already pinned
        // gift transport; other edits and financial callers retain the guard.
        $zeroExpiryQuotaGift=$giftTarget!==null && $type==='marzban' &&
            array_diff(array_keys($change),['remark','plus_volume','operation_key'])===[] &&
            is_int($change['plus_volume']??null) && $change['plus_volume']>0 &&
            ($outcome->verified??false)===true && ($outcome->uncertain??false)===false &&
            ($outcome->before_expire??null)===0 && ($outcome->applied_expire??null)===0 &&
            is_int($outcome->applied_bytes??null) && $outcome->applied_bytes>0;
        // Every adapter must expose a verified, absolute remote readback.
        if (($outcome->verified??false)!==true ||
            !isset($outcome->applied_expire,$outcome->applied_bytes) ||
            !is_numeric($outcome->applied_expire) ||
            !is_numeric($outcome->applied_bytes) ||
            ((int)$outcome->applied_expire<=0 && !$zeroExpiryQuotaGift) ||
            (int)$outcome->applied_bytes<0)
            return (object)['success'=>false,'uncertain'=>true,
                            'msg'=>'service_edit_unverified_outcome'];
        return $outcome;
    }
}

if (!function_exists('getServerInfoAndConfig')) {
    function getServerInfoAndConfig($connection, $server_id)
    {
        $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $serverInfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id`=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $serverConfig = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$serverInfo || !$serverConfig) {
            alert(\achbotMessage('purchase.core.get_server_info_and_config.alert.c0552cb2f5'));
            exit;
        }

        return [$serverInfo, $serverConfig];
    }
}

if (!function_exists('buildRemark')) {
    function buildRemark($botState, $srv_remark, $description, $userId, $forcedRemark = null)
    {
        if ($forcedRemark !== null && $forcedRemark !== '') {
            return $forcedRemark;
        }

        $remarkMode = $botState['remark'] ?? '';

        if ($remarkMode === "digits") {
            $rnd = rand(10000, 99999);
            return "{$srv_remark}-{$rnd}";
        } elseif ($remarkMode === "manual") {
            return $description;
        } else {
            $rnd = rand(1111, 99999);
            return "{$srv_remark}-{$userId}-{$rnd}";
        }
    }
}

/**
 * Independent authenticated admin updates must map to distinct, stable
 * Marzban account identities even when manual remark is unchanged.
 *
 * The readable administrator/plan prefix survives; a source-bound suffix
 * makes the actual remote username repeatable for a specific update + line.
 * Never derive a fresh name when reconciling an uncertain journal dispatch.
 */
if (!function_exists('achbotAdminBulkAccountRemark')) {
    function achbotAdminBulkAccountRemark(
        string $base, string $sourceRef, int $ordinal
    ): string {
        if ($ordinal<1 ||
            preg_match('/^bulk:[1-9][0-9]*:[1-9][0-9]*$/D',$sourceRef)!==1 ||
            preg_match('/^[a-zA-Z0-9_-]+$/D',$base)!==1)
            throw new InvalidArgumentException('bulk_account_remark_invalid');
        $suffix='_b'.substr(hash('sha256',$sourceRef),0,20).'_'.$ordinal;
        $prefix=substr($base,0,100-strlen($suffix));
        if ($prefix==='' || !preg_match('/^[a-zA-Z0-9_-]+$/D',$prefix))
            throw new InvalidArgumentException('bulk_account_remark_invalid');
        return $prefix.$suffix;
    }
}

if (!function_exists('getNextPortAndIncrease')) {
    function getNextPortAndIncrease($tempFilePath, $portType)
    {
        $port     = rand(1111, 65000);
        $last_num = 0;

        if (file_exists($tempFilePath)) {
            $savedinfo = file_get_contents($tempFilePath);
            $parts = explode('-', trim($savedinfo));

            if (count($parts) === 2) {
                $basePort = (int)$parts[0];
                $baseLast = (int)$parts[1];

                $port     = $basePort + 1;
                $last_num = $baseLast + 1;
            }
        }

        if ($portType === "auto") {
            @file_put_contents($tempFilePath, $port . '-' . $last_num);
        } else {
            $port = rand(1111, 65000);
        }

        return [$port, $last_num];
    }
}

if (!function_exists('createServerAccount')) {
    function createServerAccount(
        $serverType,
        $server_id,
        $inbound_id,
        $fid,
        $remark,
        $volume,
        $days,
        $uniqid,
        $protocol,
        $port,
        $expire_microdate,
        $netType,
        $rahgozar,
        $limitip,
        ?string $effectOperationId = null,
        ?int $effectExpire = null,
        ?array $effectTarget = null
    ) {
        // This provider seam is explicit: do not reinterpret unknown/retired
        // X-UI or inbound-based plan records as Marzban. Never send an API
        // request to a legacy panel or create a phantom paid account.
        if (!achbotSupportsPanel($serverType) || (int)$inbound_id !== 0) {
            return [(object)['success' => false, 'msg' => 'unsupported_panel'], $remark, $port];
        }

        if ($serverType!=='marzban') {
            return [(object)['success'=>false,'uncertain'=>false,'msg'=>'pg_durable_effect_required'],$remark,$port];
        }
        $note = $effectOperationId === null ? null : 'achbot:v1:'.$effectOperationId;
        $response = addMarzbanUser($server_id, $remark, $volume, $days, $fid, $note, $effectExpire, $effectTarget);
        // A durable effect can NEVER change its remote username and retry.
        if ($effectOperationId === null && is_object($response) && !$response->success
            && isset($response->msg) && $response->msg === 'User already exists') {
            $remark .= rand(1111, 99999);
            $response = addMarzbanUser($server_id, $remark, $volume, $days, $fid);
        }
        return [$response, $remark, $port];
    }
}

// Only bounded, internally defined reasons may be forwarded to Telegram
// operators. Never send provider response bodies or Throwable::getMessage().
if (!function_exists('achbotFinanceDiagnosticReason')) {
    function achbotFinanceDiagnosticReason(Throwable $error): string
    {
        if ($error instanceof mysqli_sql_exception) {
            $state = (string)$error->getSqlState();
            return preg_match('/^[A-Z0-9]{5}$/D', $state)
                ? 'database_error_' . $state : 'database_error';
        }
        $message = $error->getMessage();
        $allowed = [
            'unsupported_panel', 'server_config_missing', 'order_not_found',
            'increase_day_plan_not_found', 'increase_plan_not_found',
            'invalid_increase_type', 'invalid_response_type',
            'server_connection_null', 'server_null_response',
            'provider_rejected',
        ];
        return in_array($message, $allowed, true) ? $message : 'runtime_error';
    }
}


// One canonical purchase bootstrap for webhook, payment return and scheduled consumers.
require_once __DIR__.'/../Subscription/Delivery.php';
require_once __DIR__.'/OrderWrites.php';
require_once __DIR__.'/../Finance/ReferralReward.php';
require_once __DIR__.'/../Finance/RenewalHelper.php';
require_once __DIR__.'/../Finance/WalletSettlement.php';
require_once __DIR__.'/AgentPricing.php';
require_once __DIR__.'/../Finance/ReceiptHelpers.php';
