<?php
/** AChBot V7: immutable V6.1 purchase helper definitions. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG

require_once __DIR__.'/../Panel/ProviderRuntime.php';
require_once __DIR__.'/../Plans/Addons.php';

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
    function achbotSupportsMarzbanPlan($connection, $planId, $requestedInboundId = 0, $requiredServerId = null, bool $lock = false, bool $allowInactive = false)
    {
        $planId = (int)$planId;
        $stmt = $connection->prepare(
            "SELECT p.`inbound_id`, p.`server_id`, p.`active`, c.`type`
             FROM `server_plans` p
             INNER JOIN `server_config` c ON c.`id` = p.`server_id`
             WHERE p.`id` = ? LIMIT 1".($lock ? ' FOR UPDATE' : '')
        );
        $stmt->bind_param("i", $planId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $planId > 0 && (int)$requestedInboundId === 0 &&
            $row && ($allowInactive || (int)$row['active']===1) && $row['type'] === 'marzban' &&
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
        // Panel Group is the administrator's declaration of real physical-panel
        // identity. Assigning it is configuration only: existing orders,
        // plans, invoices, provider effects and unfinished journals never
        // restrict this editor and are not mutated by it. The downstream
        // operations must independently recheck their own pinned identities.
        $groupStmt=$connection->prepare(
            'SELECT id FROM server_info WHERE id=? FOR UPDATE');
        $groupStmt->bind_param('i',$serverId);$groupStmt->execute();
        $groupRow=$groupStmt->get_result()->fetch_assoc();$groupStmt->close();
        if (!$groupRow) throw new \RuntimeException('group_target_unavailable');
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
// Physical panel membership is administrator-declared, provider-neutral.
// Keep the original setting.type and nullable column to preserve all installed
// group IDs and pre-existing Marzban API compatibility without a data migration.
if (!function_exists('achbotPanelGroups')) {
    function achbotPanelGroups($db): array { return achbotMarzbanPanelGroups($db); }
    function achbotPanelGroupValid($name): bool { return achbotMarzbanGroupValid($name); }
    function achbotCreatePanelGroup($db,$name): ?int { return achbotCreateMarzbanPanelGroup($db,$name); }
    function achbotDeletePanelGroup($db,int $id): bool { return achbotDeleteMarzbanPanelGroup($db,$id); }
    function achbotPanelGroup($db,int $serverId): ?array {
        if ($serverId<=0) return null;
        $s=$db->prepare("SELECT g.id,g.value AS name FROM server_info i
            JOIN server_config c ON c.id=i.id
            JOIN setting g ON g.id=i.panel_group_id AND g.type='ACHBOT_MARZBAN_PANEL_GROUP'
            WHERE i.id=? AND c.type IN ('marzban','pasarguard') LIMIT 1");
        $s->bind_param('i',$serverId);$s->execute();
        $row=$s->get_result()->fetch_assoc();$s->close();
        return $row ? ['id'=>(int)$row['id'],'name'=>(string)$row['name']] : null;
    }
    function achbotSetPanelGroup($db,int $serverId,int $groupId): void {
        $s=$db->prepare("SELECT c.type FROM server_config c
            JOIN server_info i ON i.id=c.id WHERE c.id=? FOR UPDATE");
        $s->bind_param('i',$serverId);$s->execute();$row=$s->get_result()->fetch_assoc();$s->close();
        if (!$row || !in_array($row['type'],['marzban','pasarguard'],true))
            throw new \RuntimeException('panel_group_server_unavailable');
        achbotSetMarzbanGroup($db,$serverId,$groupId);
    }
    function achbotSamePanelGroup($db,int $sourceId,int $targetId): bool {
        if ($sourceId<1 || $targetId<1 || $sourceId===$targetId) return false;
        $a=achbotPanelGroup($db,$sourceId);
        $b=achbotPanelGroup($db,$targetId);
        return $a!==null && $b!==null && $a['id']===$b['id'];
    }
}

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
    function achbotReassignMarzbanLogicalServer($connection,$orderId,$targetId,$actorId,$adminAllowed=false) {
        return achbotReassignLogicalServer($connection,(int)$orderId,(int)$targetId,(int)$actorId,(bool)$adminAllowed);
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
function achbotOrderAddonEligibility($connection,array $order,string $kind,?array $nativeContext=null): array {
    $out=['eligible'=>false,'reason'=>'unknown'];
    if (!($connection instanceof mysqli) || !in_array($kind,['day','volume'],true) ||
        (int)($order['id']??0)<=0 || (int)($order['server_id']??0)<=0 ||
        !is_string($order['remark']??null) || $order['remark']==='') return $out;
    require_once __DIR__.'/../Panel/ServiceLifecycle.php';
    if (!achbotServerCommerceAvailable($connection,(int)$order['server_id'],false))
        return ['eligible'=>false,'reason'=>'server_renewal_disabled'];
    $native=$nativeContext===null?achbotServiceObserve((int)$order['server_id'],$order['remark']):($nativeContext['observation']??[]);
    if (!$native['verified'] || !in_array($native['status'],['active','disabled','expired','limited'],true)) return $out;
    if ($native['expire']===null) return ['eligible'=>false,'reason'=>'unlimited_time'];
    // Existing day extensions may revive an expired finite account. Volume
    // extensions preserve its date and therefore require a still-valid date.
    if ($kind==='volume' && $native['expire']<=time()) return ['eligible'=>false,'reason'=>'expired'];
    if ($kind==='volume' && $native['quota']===null) return ['eligible'=>false,'reason'=>'unlimited_volume'];
    return ['eligible'=>true,'reason'=>''];
}
}

/**
 * A new quote cannot claim a shared physical account while a Native plan
 * change has a durable, not-yet-committed journal, including when another
 * owner is the selected source. Called after order validation/locking.
 *
 * The 017 table is optional only when truly absent. An invalid installed
 * journal or unreadable old_rows is NOT proof of eligibility.
 */
if (!function_exists('achbotServicePaymentJournalClear')) {
    function achbotServicePaymentJournalClear(mysqli $db,int $orderId,bool $lock=false): bool {
        if ($orderId<=0) return false;
        try {
            $q=$db->query("SELECT TABLE_TYPE,ENGINE FROM information_schema.TABLES
                WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='service_plan_changes'");
            if (!($q instanceof mysqli_result)) return false;
            $row=$q->fetch_assoc();$count=$q->num_rows;$q->free();
            if ($row===null) return $count===0;
            if ($count!==1 || $row['TABLE_TYPE']!=='BASE TABLE' ||
                strcasecmp((string)$row['ENGINE'],'InnoDB')!==0) return false;
            $q=$db->query("SELECT source_order_id,intent_json
                FROM service_plan_changes WHERE state<>'committed' ORDER BY id".
                ($lock?' FOR UPDATE':''));
            if (!($q instanceof mysqli_result)) return false;
            while ($pending=$q->fetch_assoc()) {
                if ((int)$pending['source_order_id']===$orderId) { $q->free();return false; }
                $intent=json_decode((string)$pending['intent_json'],true);
                if (!is_array($intent) || !isset($intent['old_rows']) ||
                    !is_array($intent['old_rows']) || count($intent['old_rows'])>10000) {
                    $q->free();return false;
                }
                foreach ($intent['old_rows'] as $peer) {
                    if (!is_array($peer) || (int)($peer['id']??0)<=0) {
                        $q->free();return false;
                    }
                    if ((int)$peer['id']===$orderId) { $q->free();return false; }
                }
            }
            $q->free();return true;
        } catch (Throwable $error) { return false; }
    }
}

if (!function_exists('achbotSupportsPaymentSubject')) {
    function achbotSupportsPaymentSubject($connection, $pay, bool $lock = false)
    {
        if (!is_array($pay)) return false;
        $type = (string)($pay['type'] ?? '');
        // Supersession terminates only the ORIGINAL service-bound invoice.
        // Even a correct old plan/server no longer makes it payable.
        if (($pay['state']??null)==='service_superseded') return false;
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
            $oid=(int)($pay['order_id']??0);
            return $pid > 0 && achbotSupportsOrder(
                $connection, $oid, $pid, $uid > 0 ? $uid : null, $lock
            ) && achbotServicePaymentJournalClear($connection,$oid,$lock);
        }
        if (preg_match('/^INCREASE_(DAY|VOLUME)_(\d+)_(\d+)$/', $type, $match)) {
            $oid = (int)$match[2];
            $addon = (int)$match[3];
            if ($addon <= 0 || !achbotSupportsOrder(
                $connection, $oid, $pid > 0 ? $pid : null, $uid > 0 ? $uid : null, $lock
            )) return false;
            if (!empty($pay['order_id']) && (int)$pay['order_id'] !== $oid) return false;
            if (!achbotServicePaymentJournalClear($connection,$oid,$lock)) return false;
            if (achbotPinnedAddonUnits($pay)===null) return false;
            $stmt=$connection->prepare('SELECT id,server_id,remark,userid FROM orders_list WHERE id=?'.($lock?' FOR UPDATE':''));
            $stmt->bind_param('i',$oid);$stmt->execute();$order=$stmt->get_result()->fetch_assoc();$stmt->close();
            if (!$order) return false;
            try {
                if (!achbotAddonSchema($connection))
                    return achbotOrderAddonEligibility($connection,$order,strtolower($match[1]))['eligible'];
                $context=achbotAddonContext($connection,$order);
                return achbotOrderAddonEligibility($connection,$order,strtolower($match[1]),$context)['eligible'] &&
                    achbotAddonPayEligible($connection,$pay,$order,$context);
            } catch (Throwable $error) {return false;}
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
 * Process-safe implementation of the archived auto-port contract. It must be
 * available to all current checkout and nonpaid routes, not just historical
 * buy_helpers.php. Random mode does not persist a counter; auto mode uses a
 * single file lock around the complete read/increment/write transaction.
 */
if (!function_exists('getNextPortAndIncrease')) {
    function getNextPortAndIncrease(string $tempFilePath,string $portType): array {
        if ($portType!=='auto') return [random_int(1111,65000),0];
        if ($tempFilePath==='') throw new RuntimeException('auto_port_path_missing');
        $handle=@fopen($tempFilePath,'c+');
        if ($handle===false) throw new RuntimeException('auto_port_store_unavailable');
        try {
            if (!flock($handle,LOCK_EX))
                throw new RuntimeException('auto_port_lock_unavailable');
            rewind($handle);
            $saved=stream_get_contents($handle,128);
            if ($saved===false) throw new RuntimeException('auto_port_read_unavailable');
            $port=random_int(1111,65000);$last=0;
            if (trim($saved)!=='') {
                if (!preg_match('/^([0-9]{4,5})-([0-9]{1,18})$/D',trim($saved),$m) ||
                    (int)$m[1]<1111 || (int)$m[1]>65000 ||
                    (int)$m[2]>=PHP_INT_MAX-1)
                    throw new RuntimeException('auto_port_state_invalid');
                $port=(int)$m[1]+1;$last=(int)$m[2]+1;
                if ($port>65000) $port=1111;
            }
            $written=$port.'-'.$last;
            if (!rewind($handle) || !ftruncate($handle,0) ||
                fwrite($handle,$written)!==strlen($written) || !fflush($handle))
                throw new RuntimeException('auto_port_write_unconfirmed');
            return [$port,$last];
        } finally {
            flock($handle,LOCK_UN);fclose($handle);
        }
    }
}

/**
 * Sole current CREATE facade for a previously reserved, pinned Marzban 007
 * effect. Historical collision retries are unsafe after a durable dispatch.
 * The PG provider uses its own 007 adapter and never reaches this branch.
 */
if (!function_exists('createServerAccount')) {
    function createServerAccount($serverType,$serverId,$inboundId,$planId,
        $remark,$volume,$days,$uniqid,$protocol,$port,$expireMicrodate,
        $netType,$rahgozar,$limitIp,?string $operationNote=null,
        ?int $fixedExpire=null,?array $effectTarget=null): array {
        if ($serverType!=='marzban' || (int)$inboundId!==0)
            return [(object)['success'=>false,'msg'=>'unsupported_panel'],
                (string)$remark,(int)$port];
        if ($operationNote===null || $operationNote==='' ||
            $fixedExpire===null || $effectTarget===null ||
            preg_match('/^[a-f0-9]{32}$/D',$operationNote)!==1)
            throw new RuntimeException('native_create_pinned_target_required');
        $response=addMarzbanUser((int)$serverId,(string)$remark,(int)$volume,
            (int)$days,(int)$planId,'achbot:v1:'.$operationNote,$fixedExpire,$effectTarget);
        return [$response,(string)$remark,(int)$port];
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
        // Marzban's persisted username allows at most 32 ASCII bytes.
        // Hash BOTH the authenticated update and line, with no fixed quantity
        // limit and no different username for a previously dispatched line.
        // The validated buildRemark() base can contain fresh randomness.
        // Durable identities depend ONLY on the authenticated update/line.
        return 'mbz_bulk_'.substr(hash('sha256',$sourceRef.':'.$ordinal),0,23);
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
