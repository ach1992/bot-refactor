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
            (int)$order['plan_server'] === (int)$order['server_id'] &&
            (int)$order['plan_inbound'] === 0;
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
