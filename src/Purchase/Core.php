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
        // حالت بدون inbound (کاربر مستقل)
        if ((int)$inbound_id === 0) {
            if ($serverType === "marzban") {
                $response = addMarzbanUser($server_id, $remark, $volume, $days, $fid);

                if (is_object($response) && !$response->success && isset($response->msg) && $response->msg == "User already exists") {
                    $remark .= rand(1111, 99999);
                    $response = addMarzbanUser($server_id, $remark, $volume, $days, $fid);
                }
            } else {
                $response = addUser(
                    $server_id,
                    $uniqid,
                    $protocol,
                    $port,
                    $expire_microdate,
                    $remark,
                    $volume,
                    $netType,
                    'none',
                    $rahgozar,
                    $fid
                );

                if (is_object($response) && !$response->success && isset($response->msg)) {

                    if (strstr($response->msg, "Duplicate email")) {
                        $remark .= RandomString();
                    } elseif (strstr($response->msg, "Port already exists")) {
                        $port = rand(1111, 65000);
                    }

                    $response = addUser(
                        $server_id,
                        $uniqid,
                        $protocol,
                        $port,
                        $expire_microdate,
                        $remark,
                        $volume,
                        $netType,
                        'none',
                        $rahgozar,
                        $fid
                    );
                }
            }
        }
        // حالت با inbound
        else {
            $response = addInboundAccount(
                $server_id,
                $uniqid,
                $inbound_id,
                $expire_microdate,
                $remark,
                $volume,
                $limitip,
                null,
                $fid
            );

            if (is_object($response) && !$response->success && isset($response->msg) && strstr($response->msg, "Duplicate email")) {
                $remark .= RandomString();
                $response = addInboundAccount(
                    $server_id,
                    $uniqid,
                    $inbound_id,
                    $expire_microdate,
                    $remark,
                    $volume,
                    $limitip,
                    null,
                    $fid
                );
            }
        }

        return [$response, $remark, $port];
    }
}

