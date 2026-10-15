<?php
/** AChBot V8: source-pinned financial compatibility module. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

if (
    preg_match('/accept(.*)/', $data, $match) &&
    $text != $buttonValues['cancel'] &&
    ($from_id == $admin || ($userInfo['isAdmin'] ?? false) == true)
) {
    setUser();
    $hash_id = trim($match[1]);

    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? LIMIT 1");
    $stmt->bind_param("s", $hash_id);
    $stmt->execute();
    $payInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$payInfo) {
        alert("⛔️ اطلاعات پرداخت پیدا نشد.");
        return;
    }

    if ($payInfo['state'] === 'expired_have_sent') {
        alert("⛔️ این درخواست منقضی شده است.");
        return;
    }
    if ($payInfo['state'] === 'approved' || $payInfo['state'] === 'declined') {
        alert("⛔️ این درخواست قبلاً بررسی شده است.");
        return;
    }

    if ($payInfo['state'] !== 'have_sent') {
        alert("⛔️ این درخواست در وضعیت قابل تایید نیست: " . $payInfo['state']);
        return;
    }

    // A previously sent manual receipt could reference a retired panel.
    // Reject before the claim-to-processing write; leave it reviewable and
    // do not call any obsolete provider or mutate an order.
    if (!achbotSupportsPaymentSubject($connection, $payInfo)) {
        alert("⛔️ پنل این پلن دیگر پشتیبانی نمی‌شود؛ درخواست تأیید نشد.", true);
        return;
    }

    $stmt = $connection->prepare("
        UPDATE `pays`
        SET `state`='processing', `approved_by`=?
        WHERE `hash_id`=? AND `state`='have_sent'
        LIMIT 1
    ");
    $stmt->bind_param("is", $from_id, $hash_id);
    $stmt->execute();

    if ($stmt->affected_rows !== 1) {
        $stmt->close();
        alert("⛔️ این درخواست هم‌اکنون در حال پردازش است.");
        return;
    }
    $stmt->close();

    try {
        $uid     = (int)$payInfo['user_id'];
        $fid     = (int)$payInfo['plan_id'];
        $price   = (int)$payInfo['price'];
        $payType = (string)$payInfo['type'];

        if ($payType === "RENEW_SCONFIG") {

            $planDetails = getPlanDetails($connection, $fid);
            $plan        = $planDetails['plan'];

            $days   = $planDetails['days'];
            $volume = $planDetails['volume'];

            $server_id  = (int)$plan['server_id'];
            $inbound_id = (int)$payInfo['volume'];

            $configInfo = json_decode($payInfo['description'], true) ?: [];
            $uuid       = $configInfo['uuid']   ?? '';
            $remark     = $configInfo['remark'] ?? '';
            $isMarzban  = !empty($configInfo['marzban']);

            [$serverInfo, $serverConfig] = getServerInfoAndConfig($connection, $server_id);

            $response = renewConfigOnServer(
                $connection,
                $server_id,
                $inbound_id,
                $uuid,
                $remark,
                $days,
                $volume,
                $isMarzban
            );

            ensureAccountCreationSucceeded($response, $serverInfo, $inbound_id, $admin);

            $connection->begin_transaction();
            try {
                createIncreaseOrder(
                    $connection,
                    $uid,
                    $server_id,
                    $inbound_id,
                    $remark,
                    $price,
                    $time,
                    null,
                    null
                );

                $stmt = $connection->prepare("UPDATE `pays` SET `state`='approved', `approved_by`=? WHERE `hash_id`=? LIMIT 1");
                $stmt->bind_param("is", $from_id, $hash_id);
                $stmt->execute();
                $stmt->close();

                $connection->commit();
            } catch (Exception $e) {
                $connection->rollback();

                $stmt = $connection->prepare("UPDATE `pays` SET `state`='have_sent' WHERE `hash_id`=? LIMIT 1");
                $stmt->bind_param("s", $hash_id);
                $stmt->execute();
                $stmt->close();

                sendMessage("⚠️ تمدید انجام شد اما ثبت دیتابیس خطا خورد.\n🆔 $hash_id\nError: ".$e->getMessage(), null, null, $admin);
                return;
            }

            sendMessage(
                str_replace(["REMARK","VOLUME","DAYS"], [$remark,$volume,$days], $mainValues['renewed_config_to_user']),
                getMainKeys(),
                null,
                null
            );
            sendMessage("✅ اشتراک $remark با موفقیت تمدید شد.", null, null, $uid);

            $keys = json_encode([
                'inline_keyboard' => [
                    [['text' => "✅ تایید شد توسط $from_id", 'callback_data' => "wizwizch"]],
                    [['text' => "🔄 $remark",                'callback_data' => "noop"]],
                    [['text' => "🆔 $hash_id",              'callback_data' => "noop_$hash_id"]],
                ]
            ], 488);

            try { editKeys($keys, $payInfo['message_id'], $payInfo['chat_id']); } catch (Exception $e) {
                sendMessage("⚠️ editKeys failed\n🆔 $hash_id\nError: ".$e->getMessage(), null, null, $admin);
            }

            $adminMessages = json_decode($payInfo['admins_messages'], true);
            if (is_array($adminMessages)) {
                foreach ($adminMessages as $msgInfo) {
                    if (isset($msgInfo['chat_id'], $msgInfo['message_id']) && $msgInfo['chat_id'] != $payInfo['chat_id']) {
                        try { editKeys($keys, $msgInfo['message_id'], $msgInfo['chat_id']); } catch (Exception $e) {}
                    }
                }
            }

            return;
        }

        $accountCount = (int)($payInfo['agent_count'] ?? 1);
        $accountCount = max(1, $accountCount);
        $eachPrice    = ($accountCount > 0) ? ($price / $accountCount) : $price;

        $planDetails = getPlanDetails($connection, $fid);
        $plan        = $planDetails['plan'];

        $days             = $planDetails['days'];
        $volume           = $planDetails['volume'];
        $date             = $planDetails['date'];
        $expire_microdate = $planDetails['expire_microdate'];
        $expire_date      = $planDetails['expire_date'];

        $protocol   = $plan['protocol'];
        $server_id  = (int)$plan['server_id'];
        $netType    = $plan['type'];
        $acount     = (int)$plan['acount'];
        $inbound_id = (int)$plan['inbound_id'];
        $limitip    = $plan['limitip'];
        $rahgozar   = $plan['rahgozar'];
        $customPath = $plan['custom_path'];
        $customPort = $plan['custom_port'];
        $customSni  = $plan['custom_sni'];

        ensureServerCapacity($connection, $server_id, $inbound_id, $acount, $mainValues);

        [$serverInfo, $serverConfig] = getServerInfoAndConfig($connection, $server_id);
        $srv_remark  = $serverInfo['remark'];
        $serverType  = $serverConfig['type'];
        $portType    = $serverConfig['port_type'];

        alert($mainValues['sending_config_to_user']);

        $agent_bought = (int)($payInfo['agent_bought'] ?? 0);

        $pendingUserQrs = [];
        $toInsertOrders = [];
        $lastRemarkForAdminMsg = '';

        for ($i = 1; $i <= $accountCount; $i++) {

            $uniqid = generateRandomString(42, $protocol);
            [$port] = getNextPortAndIncrease('settings/temp.txt', $portType);

            $remark = buildRemark($botState, $srv_remark, $payInfo['description'], $uid);
            $lastRemarkForAdminMsg = $remark;

            [$response, $remark, $port] = createServerAccount(
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
            );

            ensureAccountCreationSucceeded($response, $serverInfo, $inbound_id, $admin);

            [$token, $vray_link, $uniqid2, $acc_text, $userKeys, $qrLink] = buildLinksAndSendQr(
                $serverType,
                $serverConfig,
                $botState,
                $botUrl,
                $server_id,
                $uniqid,
                $protocol,
                $remark,
                $port,
                $netType,
                $inbound_id,
                $rahgozar,
                $customPath,
                $customPort,
                $customSni,
                $response,
                $uid,
                $volume,
                $days,
                $buttonValues,
                $linkIndices,
                $hideConfigIfMany,
                '',
                null,
                false
            );

            $toInsertOrders[] = [
                'uid'          => $uid,
                'token'        => $token,
                'fid'          => $fid,
                'server_id'    => $server_id,
                'inbound_id'   => $inbound_id,
                'remark'       => $remark,
                'uniqid'       => $uniqid2 ?: $uniqid,
                'protocol'     => $protocol,
                'expire_date'  => $expire_date,
                'vray_link'    => $vray_link,
                'amount'       => $eachPrice,
                'date'         => $date,
                'rahgozar'     => $rahgozar,
                'agent_bought' => $agent_bought,
            ];

            $pendingUserQrs[] = [
                'uid'      => $uid,
                'qrLink'   => $qrLink,
                'acc_text' => $acc_text,
                'keys'     => $userKeys,
            ];
        }

        if (count($toInsertOrders) === 0) {
            $stmt = $connection->prepare("UPDATE `pays` SET `state`='have_sent' WHERE `hash_id`=? LIMIT 1");
            $stmt->bind_param("s", $hash_id);
            $stmt->execute();
            $stmt->close();

            sendMessage("⚠️ هیچ اکانتی ساخته نشد؛ پرداخت به حالت have_sent برگشت.\n🆔 $hash_id", null, null, $admin);
            return;
        }

        $connection->begin_transaction();
        try {
            foreach ($toInsertOrders as $o) {
                insertOrder(
                    $connection,
                    $o['uid'],
                    $o['token'],
                    $o['fid'],
                    $o['server_id'],
                    $o['inbound_id'],
                    $o['remark'],
                    $o['uniqid'],
                    $o['protocol'],
                    $o['expire_date'],
                    $o['vray_link'],
                    $o['amount'],
                    $o['date'],
                    $o['rahgozar'],
                    $o['agent_bought']
                );
            }

            decreaseServerCapacity($connection, $server_id, $inbound_id, $fid, $accountCount);

            $stmt = $connection->prepare("UPDATE `pays` SET `state`='approved', `approved_by`=? WHERE `hash_id`=? LIMIT 1");
            $stmt->bind_param("is", $from_id, $hash_id);
            $stmt->execute();
            $stmt->close();

            $connection->commit();
        } catch (Exception $e) {
            $connection->rollback();

            $stmt = $connection->prepare("UPDATE `pays` SET `state`='have_sent' WHERE `hash_id`=? LIMIT 1");
            $stmt->bind_param("s", $hash_id);
            $stmt->execute();
            $stmt->close();

            sendMessage(
                "⚠️ خطا در ثبت سفارش/کاهش ظرفیت؛ پرداخت به حالت have_sent برگشت تا دوباره تایید شود.\n🆔 $hash_id\nError: " . $e->getMessage(),
                null,
                null,
                $admin
            );
            return;
        }

        foreach ($pendingUserQrs as $pq) {
            sendQrFromLink(
                $pq['qrLink'],
                $pq['acc_text'],
                $pq['keys'],
                $botUrl,
                (int)$pq['uid'],
                '',
                null
            );
        }

        sendMessage(
            str_replace(
                ["USERID", "REMARK", "VOLUME", "DAYS", "PRICE", "HASHID"],
                [$uid, $lastRemarkForAdminMsg, $volume, $days, $price, $hash_id],
                $mainValues['sent_config_to_user']
            ),
            getMainKeys()
        );

        $keys = json_encode([
            'inline_keyboard' => [
                [['text' => "✅ تایید شد توسط $from_id", 'callback_data' => "wizwizch"]],
                [['text' => "🔄 $lastRemarkForAdminMsg",  'callback_data' => "noop"]],
                [['text' => "🆔 $hash_id",               'callback_data' => "noop_$hash_id"]],
            ]
        ], 488);

        try { editKeys($keys, $payInfo['message_id'], $payInfo['chat_id']); } catch (Exception $e) {
            sendMessage("⚠️ editKeys failed\n🆔 $hash_id\nError: ".$e->getMessage(), null, null, $admin);
        }

        $adminMessages = json_decode($payInfo['admins_messages'], true);
        if (is_array($adminMessages)) {
            foreach ($adminMessages as $msgInfo) {
                if (isset($msgInfo['chat_id'], $msgInfo['message_id']) && $msgInfo['chat_id'] != $payInfo['chat_id']) {
                    try { editKeys($keys, $msgInfo['message_id'], $msgInfo['chat_id']); } catch (Exception $e) {}
                }
            }
        }

        $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid`=?");
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $user_detail = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!empty($user_detail['refered_by'])) {
            $inviterId = (int)$user_detail['refered_by'];

            giveReferralReward($connection, $user_detail['userid'], $inviterId, $admin, $botState);

            if ($admin != $from_id) {
                $uname     = $user_detail['name'];
                $user_name = $user_detail['username'];

                $keys2 = json_encode([
                    'inline_keyboard' => [
                        [
                            ['text' => "🛍 خرید اشتراک جدید", 'callback_data' => "wizwizch"]
                        ],
                    ]
                ]);

                $msg = str_replace(
                    ['USER-ID', 'USERNAME', 'NAME', 'PRICE', 'REMARK', 'HASHID'],
                    [$uid, $user_name, $uname, $price, $lastRemarkForAdminMsg, $hash_id],
                    $mainValues['invite_buy_new_account']
                );

                sendMessage($msg, $keys2, "html", $admin);

                $rewardChannel = $botState['rewardChannel'] ?? null;
                if (!empty($rewardChannel)) {
                    sendMessage($msg, $keys2, "html", $rewardChannel);
                }
            }
        }

    } catch (Exception $e) {
        try {
            $stmt = $connection->prepare("UPDATE `pays` SET `state`='have_sent' WHERE `hash_id`=? AND `state`='processing' LIMIT 1");
            $stmt->bind_param("s", $hash_id);
            $stmt->execute();
            $stmt->close();
        } catch (Exception $ignored) {}

        sendMessage("⚠️ خطا در تایید\n🆔 $hash_id\nError: ".$e->getMessage(), null, null, $admin);
        return;
    }
}

