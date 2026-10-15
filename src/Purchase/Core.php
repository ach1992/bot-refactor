<?php
/** AChBot V7: immutable V6.1 purchase helper definitions. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
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
            alert('❌ پلن مورد نظر یافت نشد.');
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
    function achbotSupportsMarzbanPlan($connection, $planId, $requestedInboundId = 0, $requiredServerId = null)
    {
        $planId = (int)$planId;
        $stmt = $connection->prepare(
            "SELECT p.`inbound_id`, p.`server_id`, c.`type`
             FROM `server_plans` p
             INNER JOIN `server_config` c ON c.`id` = p.`server_id`
             WHERE p.`id` = ? LIMIT 1"
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
// All consumers use the same current provider and original 23-table schema.
if (!function_exists('achbotSupportsMarzbanAccount')) {
    function achbotSupportsMarzbanAccount($type, $inboundId)
    {
        return $type === 'marzban' && (int)$inboundId === 0;
    }
}

// Logical server entries can share one real Marzban API. Do not confuse
// their separate prices/plans with separate provider account stores.
// These helpers reuse existing setting rows; original 23-table DDL is frozen.
if (!function_exists('achbotMarzbanGroupValid')) {
    function achbotMarzbanGroupValid($group)
    {
        return is_string($group) && preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,39}$/D', $group) === 1;
    }
}

if (!function_exists('achbotMarzbanGroupKey')) {
    function achbotMarzbanGroupKey($serverId)
    {
        return 'ACHBOT_MARZBAN_PANEL_GROUP_' . (int)$serverId;
    }
}

if (!function_exists('achbotMarzbanGroup')) {
    function achbotMarzbanGroup($connection, $serverId)
    {
        if ((int)$serverId <= 0) return null;
        $key = achbotMarzbanGroupKey($serverId);
        $stmt = $connection->prepare("SELECT `value` FROM `setting` WHERE `type` = ? LIMIT 2");
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $result = $stmt->get_result();
        $count = $result->num_rows;
        $row = $result->fetch_assoc();
        $stmt->close();
        if ($count > 1) return null; // ambiguous configuration: fail closed
        if (!$row) return ''; // legacy untagged server, match exact endpoint
        $group = (string)$row['value'];
        return achbotMarzbanGroupValid($group) ? $group : null;
    }
}

if (!function_exists('achbotSetMarzbanGroup')) {
    function achbotSetMarzbanGroup($connection, $serverId, $group)
    {
        // Creation/edit callers own a transaction; untag/deletion cleanup
        // is also idempotent when invoked by an existing legacy handler.
        if ((int)$serverId <= 0 || ($group !== '' && !achbotMarzbanGroupValid($group))) {
            throw new \InvalidArgumentException('invalid_marzban_panel_group');
        }
        $key = achbotMarzbanGroupKey($serverId);
        $stmt = $connection->prepare("DELETE FROM `setting` WHERE `type` = ?");
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $stmt->close();
        if ($group !== '') {
            $stmt = $connection->prepare("INSERT INTO `setting` (`type`, `value`) VALUES (?, ?)");
            $stmt->bind_param('ss', $key, $group);
            $stmt->execute();
            $stmt->close();
        }
    }
}

if (!function_exists('achbotSameMarzbanPanel')) {
    function achbotSameMarzbanPanel($connection, $sourceId, $targetId)
    {
        $sourceId = (int)$sourceId;
        $targetId = (int)$targetId;
        if ($sourceId <= 0 || $targetId <= 0) return false;
        $stmt = $connection->prepare(
            "SELECT `id`, `type`, `panel_url`, `username`
             FROM `server_config` WHERE `id` IN (?, ?)"
        );
        $stmt->bind_param('ii', $sourceId, $targetId);
        $stmt->execute();
        $rows = [];
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $rows[(int)$row['id']] = $row;
        }
        $stmt->close();
        if (!isset($rows[$sourceId], $rows[$targetId]) ||
            $rows[$sourceId]['type'] !== 'marzban' ||
            $rows[$targetId]['type'] !== 'marzban') return false;

        // This version permits logical reassignment only for the same
        // configured API endpoint and admin identity. Matching text tags
        // alone cannot prove two distinct URLs point to one live panel.
        $endpoint = static function ($raw) {
            $url = parse_url(trim((string)$raw));
            if (!is_array($url) || !isset($url['scheme'], $url['host']) ||
                !in_array(strtolower($url['scheme']), ['http', 'https'], true) ||
                isset($url['user']) || isset($url['pass']) ||
                isset($url['query']) || isset($url['fragment'])) return null;
            return strtolower($url['scheme']) . '://' . strtolower($url['host']) .
                (isset($url['port']) ? ':' . (int)$url['port'] : '') .
                rtrim((string)($url['path'] ?? ''), '/');
        };
        $a = $endpoint($rows[$sourceId]['panel_url']);
        $b = $endpoint($rows[$targetId]['panel_url']);
        if ($a === null || $a === '' || $a !== $b ||
            trim((string)$rows[$sourceId]['username']) === '' ||
            $rows[$sourceId]['username'] !== $rows[$targetId]['username']) return false;

        $sourceGroup = achbotMarzbanGroup($connection, $sourceId);
        $targetGroup = achbotMarzbanGroup($connection, $targetId);
        if ($sourceGroup === null || $targetGroup === null) return false;
        // No tag is required for old entries with the same verified endpoint.
        // Explicitly conflicting labels isolate logical server offerings.
        return $sourceGroup === '' || $targetGroup === '' ||
            $sourceGroup === $targetGroup;
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
    function achbotSupportsMarzbanOrder($connection, $orderId, $requiredPlanId = null, $requiredUser = null)
    {
        if ((int)$orderId <= 0) return false;
        $stmt = $connection->prepare(
            "SELECT o.`userid`, o.`fileid`, o.`server_id`, o.`inbound_id`,
                    c.`type`, p.`server_id` AS plan_server,
                    p.`inbound_id` AS plan_inbound
             FROM `orders_list` o
             LEFT JOIN `server_config` c ON c.`id` = o.`server_id`
             LEFT JOIN `server_plans` p ON p.`id` = o.`fileid`
             WHERE o.`id` = ? LIMIT 1"
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
                 (int)$order['plan_server'], (int)$order['server_id']));
    }
}

if (!function_exists('achbotSupportsPaymentSubject')) {
    function achbotSupportsPaymentSubject($connection, $pay)
    {
        if (!is_array($pay)) return false;
        $type = (string)($pay['type'] ?? '');
        // Wallet top-ups are deliberately not tied to VPN providers.
        if ($type === 'INCREASE_WALLET') return true;
        $pid = (int)($pay['plan_id'] ?? 0);
        $uid = (int)($pay['user_id'] ?? 0);
        if ($type === 'BUY_SUB') {
            return $pid > 0 && achbotSupportsMarzbanPlan(
                $connection, $pid, 0, $pay['server_id'] ?? null
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
                achbotSupportsMarzbanPlan($connection, $pid, 0, $sid);
        }
        if ($type === 'RENEW_ACCOUNT') {
            return $pid > 0 && achbotSupportsMarzbanOrder(
                $connection, (int)($pay['order_id'] ?? 0), $pid, $uid > 0 ? $uid : null
            );
        }
        if (preg_match('/^INCREASE_(DAY|VOLUME)_(\d+)_(\d+)$/', $type, $match)) {
            $oid = (int)$match[2];
            $addon = (int)$match[3];
            if ($addon <= 0 || !achbotSupportsMarzbanOrder(
                $connection, $oid, $pid > 0 ? $pid : null, $uid > 0 ? $uid : null
            )) return false;
            if (!empty($pay['order_id']) && (int)$pay['order_id'] !== $oid) return false;
            $table = $match[1] === 'DAY' ? 'increase_day' : 'increase_plan';
            $stmt = $connection->prepare("SELECT `id` FROM `$table` WHERE `id` = ? LIMIT 1");
            $stmt->bind_param('i', $addon);
            $stmt->execute();
            $exists = (bool)$stmt->get_result()->fetch_assoc();
            $stmt->close();
            return $exists;
        }
        return false;
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
            alert('❌ اطلاعات سرور یا تنظیمات آن یافت نشد.');
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
        $limitip
    ) {
        // This provider seam is explicit: do not reinterpret unknown/retired
        // X-UI or inbound-based plan records as Marzban. Never send an API
        // request to a legacy panel or create a phantom paid account.
        if (!achbotSupportsPanel($serverType) || (int)$inbound_id !== 0) {
            return [(object)['success' => false, 'msg' => 'unsupported_panel'], $remark, $port];
        }

        $response = addMarzbanUser($server_id, $remark, $volume, $days, $fid);
        if (is_object($response) && !$response->success
            && isset($response->msg) && $response->msg === 'User already exists') {
            $remark .= rand(1111, 99999);
            $response = addMarzbanUser($server_id, $remark, $volume, $days, $fid);
        }
        return [$response, $remark, $port];
    }
}
