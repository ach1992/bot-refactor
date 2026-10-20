<?php
/** AChBot V7: immutable V6.1 purchase helper definitions. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

if (!function_exists('insertOrder')) {
    function insertOrder(
        $connection,
        $uid,
        $token,
        $fid,
        $server_id,
        $inbound_id,
        $remark,
        $uniqid,
        $protocol,
        $expire_date,
        $vray_link,
        $amount,
        $date,
        $rahgozar,
        $agent_bought,
        $requireConfirmed = false
    ) {
        $stmt = $connection->prepare("INSERT INTO `orders_list`  
            (`userid`, `token`, `transid`, `fileid`, `server_id`, `inbound_id`, `remark`, `uuid`, `protocol`, `expire_date`, `link`, `amount`, `status`, `date`, `notif`, `rahgozar`, `agent_bought`) 
            VALUES (?, ?, '', ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, 0, ?, ?);");
        $stmt->bind_param(
            "ssiiisssisiiii",
            $uid,
            $token,
            $fid,
            $server_id,
            $inbound_id,
            $remark,
            $uniqid,
            $protocol,
            $expire_date,
            $vray_link,
            $amount,
            $date,
            $rahgozar,
            $agent_bought
        );
        $saved=$stmt->execute();
        $inserted=$stmt->affected_rows;
        $stmt->close();
        if ($requireConfirmed && ($saved!==true || $inserted!==1)) {
            throw new RuntimeException('order_insert_unconfirmed');
        }
    }
}

if (!function_exists('decreaseServerCapacity')) {
    function decreaseServerCapacity($connection, $server_id, $inbound_id, $plan_id, $count = 1, $requireConfirmed = false)
    {
        $count      = (int)$count;
        $server_id  = (int)$server_id;
        $inbound_id = (int)$inbound_id;
        $plan_id    = (int)$plan_id;

        if ($count <= 0) {
            return;
        }

        if ($inbound_id === 0) {
            $stmt = $connection->prepare(
                "UPDATE `server_info` SET `ucount` = `ucount` - ? WHERE `id` = ?"
            );
            $stmt->bind_param("ii", $count, $server_id);
            $saved=$stmt->execute();
            $changed=$stmt->affected_rows;
            $stmt->close();
            if ($requireConfirmed && ($saved!==true || $changed!==1))
                throw new RuntimeException('server_capacity_update_unconfirmed');
        } else {
            $stmt = $connection->prepare(
                "UPDATE `server_plans` SET `acount` = `acount` - ? WHERE `id` = ?"
            );
            $stmt->bind_param("ii", $count, $plan_id);
            $saved=$stmt->execute();
            $changed=$stmt->affected_rows;
            $stmt->close();
            if ($requireConfirmed && ($saved!==true || $changed!==1))
                throw new RuntimeException('plan_capacity_update_unconfirmed');
        }
    }
}

if (!function_exists('ensureAccountCreationSucceeded')) {
    function ensureAccountCreationSucceeded($response, $serverInfo, $inbound_id, $admin, $throwOnError = false)
    {
        $hasError = false;
        $errorCode = '';
        $userMessage = '';
        $adminMessage = '';

        if (is_null($response)) {
            $hasError    = true;
            $errorCode   = 'server_connection_null';
            $userMessage = '❌ اتصال به سرور برقرار نیست. لطفا پشتیبانی را در جریان بگذارید.';

        } elseif ($response === "inbound not Found") {
            $hasError    = true;
            $errorCode   = 'inbound_not_found';
            $userMessage = "❌ سطر (inbound) با آیدی $inbound_id تو این سرور وجود ندارد، لطفا پشتیبانی را در جریان بگذارید.";

        } else {
            $success = false;
            $msg     = '';

            if (is_object($response)) {
                $success = !empty($response->success);
                $msg     = $response->msg ?? '';
            } elseif (is_array($response)) {
                $success = !empty($response['success']);
                $msg     = $response['msg'] ?? '';
            }

            if (!$success) {
                $hasError    = true;
                $errorCode   = 'server_response_failed';
                $userMessage = '❌ خطایی در ساخت/بروزرسانی اکانت رخ داد، لطفا پشتیبانی را در جریان بگذارید.';

                $title = $serverInfo['title'] ?? 'نامشخص';
                $adminMessage = "خطای سرور {$title}:\n\n" . ($msg ?: 'بدون پیام خطا');
            }
        }

        if (!$hasError) {
            return;
        }

        if ($throwOnError) {
            $fullMessage = $errorCode . ($adminMessage ? (' | ' . $adminMessage) : '');
            throw new Exception($fullMessage ?: $errorCode);
        }

        if (!empty($userMessage)) {
            alert($userMessage);
        }

        if (!empty($adminMessage) && !empty($admin)) {
            sendMessage($adminMessage, null, null, $admin);
            // sendToAllAdmins(...)
        }

        exit;
    }
}

