<?php
/** AChBot V8: source-pinned financial compatibility module. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

if (
    preg_match('/^accept([A-Za-z0-9_-]{1,50})$/D', (string)$data, $match) &&
    $text != $buttonValues['cancel'] &&
    ($from_id == $admin || ($userInfo['isAdmin'] ?? false) == true)
) {
    require_once __DIR__ . '/../ServiceEffects.php';
    $hash_id = $match[1];
    $payInfo = null;
    $claimOpen = false;

    // Standard-plan approval requires reviewed receipt proof under a pay-row
    // lock. Commit the no-replay claim before any remote Marzban operation.
    try {
        if ($connection->begin_transaction() !== true)
            throw new RuntimeException('manual_standard_claim_begin_failed');
        $claimOpen = true;
        $stmt = $connection->prepare('SELECT * FROM pays WHERE hash_id=? LIMIT 1 FOR UPDATE');
        if (!$stmt) throw new RuntimeException('manual_standard_claim_prepare_failed');
        $stmt->bind_param('s', $hash_id);
        if (!$stmt->execute()) throw new RuntimeException('manual_standard_claim_select_failed');
        $payInfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $receiptSubject = ($payInfo['type'] ?? null) === 'RENEW_SCONFIG'
            ? 'standard_renewal' : 'standard';
        if (!$payInfo || !achbotSupportsPaymentSubject($connection, $payInfo) ||
            !achbotManualServiceApprovalAllowedLocked($connection, $payInfo, $receiptSubject)) {
            $connection->rollback();
            $claimOpen = false;
            alert('⛔️ این درخواست قابل تأیید نیست؛ وضعیت رسید، روش پرداخت یا نوع سفارش را بررسی کنید.');
            return;
        }
        $payId = (int)$payInfo['id'];
        $actor = (int)$from_id;
        $stmt = $connection->prepare(
            "UPDATE pays SET state='processing',approved_by=?
             WHERE id=? AND state='have_sent' AND (payid IS NULL OR payid='')"
        );
        if (!$stmt) throw new RuntimeException('manual_standard_claim_update_prepare_failed');
        $stmt->bind_param('ii', $actor, $payId);
        if (!$stmt->execute()) throw new RuntimeException('manual_standard_claim_update_failed');
        $claimed = $stmt->affected_rows;
        $stmt->close();
        if ($claimed !== 1 || $connection->commit() !== true)
            throw new RuntimeException('manual_standard_claim_commit_unconfirmed');
        $claimOpen = false;
    } catch (Throwable $error) {
        if ($claimOpen) {
            try { $connection->rollback(); } catch (Throwable $ignored) {}
        }
        error_log('achbot_manual_standard_claim_needs_review pay_id='.(int)($payInfo['id']??0). ' exception='.get_class($error).' code='.(int)$error->getCode());
        alert('⚠️ نتیجهٔ ثبت تأیید قطعی نیست. ابتدا وضعیت سفارش را بررسی کنید؛ تأیید را تکرار نکنید.');
        return;
    }

    $serviceCommitted = false;
    $followupStage = 'pre_commit';
    try {
        setUser();
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

            ensureAccountCreationSucceeded($response, $serverInfo, $inbound_id, $admin, true);

            if ($connection->begin_transaction() !== true)
                throw new RuntimeException('manual_standard_finish_begin_failed');
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
                    null,
                    true
                );

                $stmt = $connection->prepare(
                    "UPDATE pays SET state='approved' WHERE id=? AND state='processing' AND approved_by=?"
                );
                if (!$stmt) throw new RuntimeException('manual_standard_finish_prepare_failed');
                $stmt->bind_param('ii', $payId, $actor);
                if (!$stmt->execute() || $stmt->affected_rows !== 1)
                    throw new RuntimeException('manual_standard_finish_failed');
                $stmt->close();
                if ($connection->commit() !== true)
                    throw new RuntimeException('manual_standard_finish_commit_unconfirmed');
                $serviceCommitted = true;
                $followupStage = 'renewal_customer_notice';
            } catch (Throwable $e) {
                try { $connection->rollback(); } catch (Throwable $ignored) {}
                error_log('achbot_manual_standard_remote_finish_review pay_id='.$payId.' subject=renewal exception='.get_class($e).' code='.(int)$e->getCode());
                sendMessage("⚠️ نتیجهٔ ثبت تمدید قطعی نیست و نیازمند بررسی است.\n🆔 $hash_id\nقبل از هر تأیید دوباره، سرویس و پرداخت را تطبیق دهید.", null, null, $admin);
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

            $followupStage = 'renewal_operator_buttons';
            try {
                editKeys($keys, $payInfo['message_id'], $payInfo['chat_id']);
            } catch (Throwable $e) {
                error_log('achbot_manual_standard_buttons_update_failed pay_id='.$payId.
                    ' subject=renewal exception='.get_class($e).' code='.(int)$e->getCode());
                sendMessage("✅ تمدید ثبت شده است، اما به‌روزرسانی دکمه‌های پیام مدیر تأیید نشد.\n🆔 $hash_id\nوضعیت پیام را بررسی کنید؛ تمدید را دوباره تأیید نکنید.", null, null, $admin);
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
            if ($accountCount>1 && (($botState['remark']??'')==='manual')) {
                $remark .= '_'.$i;
            }
            $lastRemarkForAdminMsg = $remark;

            [$response, $remark, $port, $effectId] = achbotCreatePaidAccount(
                $connection, $payId, $i, [
                    'user_id'=>$uid,'server_type'=>$serverType,'server_id'=>$server_id,
                    'inbound_id'=>$inbound_id,'plan_id'=>$fid,
                    'remark'=>$remark,'volume'=>$volume,'days'=>$days,
                    'uniqid'=>$uniqid,'protocol'=>$protocol,'port'=>$port,
                    'expire_microdate'=>$expire_microdate,'net_type'=>$netType,
                    'rahgozar'=>$rahgozar,'limitip'=>$limitip
                ]
            );

            ensureAccountCreationSucceeded($response, $serverInfo, $inbound_id, $admin, true);

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
                'effect_id'    => $effectId,
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
            error_log('achbot_manual_standard_empty_remote_result pay_id='.$payId);
            sendMessage("⚠️ نتیجهٔ ساخت اکانت‌ها قطعی نیست و نیازمند بررسی است.\n🆔 $hash_id\nپرداخت را دوباره تأیید نکنید.", null, null, $admin);
            return;
        }

        if ($connection->begin_transaction() !== true)
            throw new RuntimeException('manual_standard_finish_begin_failed');
        try {
            foreach ($toInsertOrders as $o) {
                $orderId = insertOrder(
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
                    $o['agent_bought'],
                    true
                );
                achbotLinkPaidAccountEffect($connection,(int)$o['effect_id'],(int)$orderId);
            }

            // Full capacity is already held by the first 007 effect journal
            // reservation before Marzban CREATE. Never deduct it twice.

            $stmt = $connection->prepare(
                "UPDATE pays SET state='approved' WHERE id=? AND state='processing' AND approved_by=?"
            );
            if (!$stmt) throw new RuntimeException('manual_standard_finish_prepare_failed');
            $stmt->bind_param('ii', $payId, $actor);
            if (!$stmt->execute() || $stmt->affected_rows !== 1)
                throw new RuntimeException('manual_standard_finish_failed');
            $stmt->close();
            if ($connection->commit() !== true)
                throw new RuntimeException('manual_standard_finish_commit_unconfirmed');
            $serviceCommitted = true;
            $followupStage = 'purchase_account_delivery';
        } catch (Throwable $e) {
            try { $connection->rollback(); } catch (Throwable $ignored) {}
            // One or more remote accounts may exist without local orders.
            error_log('achbot_manual_standard_remote_finish_review pay_id='.$payId.' subject=purchase exception='.get_class($e).' code='.(int)$e->getCode());
            sendMessage("⚠️ نتیجهٔ ثبت سفارش پس از عملیات سرویس قطعی نیست.\n🆔 $hash_id\nبرای تطبیق اکانت‌ها و پرداخت بررسی دستی لازم است؛ دوباره تأیید نکنید.",
                        null, null, $admin);
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

        $followupStage = 'purchase_operator_notice';
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

        $followupStage = 'purchase_operator_buttons';
        try {
            editKeys($keys, $payInfo['message_id'], $payInfo['chat_id']);
        } catch (Throwable $e) {
            error_log('achbot_manual_standard_buttons_update_failed pay_id='.$payId.
                ' subject=purchase exception='.get_class($e).' code='.(int)$e->getCode());
            sendMessage("✅ سفارش ثبت شده است، اما به‌روزرسانی دکمه‌های پیام مدیر تأیید نشد.\n🆔 $hash_id\nوضعیت پیام را بررسی کنید؛ سفارش را دوباره تأیید نکنید.", null, null, $admin);
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

            $followupStage = 'referral_reward';
            giveReferralReward($connection, $user_detail['userid'], $inviterId, $admin, $botState, null, $payId);

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

    } catch (Throwable $e) {
        // An error in Telegram or post-commit referral work cannot undo a
        // confirmed local order/renewal. Keep its error signal distinct from
        // an uncertain remote effect or transaction commit.
        if ($serviceCommitted) {
            error_log('achbot_manual_standard_postcommit_followup_needs_review pay_id='.$payId.
                ' stage='.$followupStage.' exception='.get_class($e).' code='.(int)$e->getCode());
            try {
                sendMessage("✅ سفارش ثبت شده و عملیات سرویس در سیستم تأیید شده است، اما اطلاع‌رسانی یا پاداش پس از ثبت ممکن است ناقص باشد.\n🆔 $hash_id\nوضعیت پیام مشتری و پاداش را بررسی کنید؛ سفارش را دوباره تأیید نکنید.",
                            null, null, $admin);
            } catch (Throwable $notifyError) {
                error_log('achbot_manual_standard_postcommit_notice_failed pay_id='.$payId.
                    ' exception='.get_class($notifyError).' code='.(int)$notifyError->getCode());
            }
            return;
        }
        // Remote effects and SQL are not one transaction. Never re-arm a claim.
        error_log('achbot_manual_standard_effect_needs_review pay_id='.$payId.' exception='.get_class($e).' code='.(int)$e->getCode());
        try {
            sendMessage("⚠️ نتیجهٔ تکمیل سفارش هنوز قطعی نیست.\n🆔 $hash_id\nابتدا پرداخت و اکانت‌های سرویس را بررسی کنید؛ تأیید را تکرار نکنید.",
                        null, null, $admin);
        } catch (Throwable $notifyError) {
            error_log('achbot_manual_standard_review_notice_failed pay_id='.$payId.
                ' exception='.get_class($notifyError).' code='.(int)$notifyError->getCode());
        }
        return;
    }
}

