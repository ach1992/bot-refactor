<?php
/** AChBot V8: source-pinned financial compatibility module. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

// Telegram may return ok=false or null without throwing an exception.
if (!function_exists('achbotManualStandardRequireTelegramResult')) {
    function achbotManualStandardRequireTelegramResult($response, string $phase): void {
        if ($response===true ||
            (is_object($response) && ($response->ok??false)===true) ||
            (is_array($response) && ($response['ok']??false)===true))
            return;
        throw new RuntimeException('manual_standard_telegram_'.$phase.'_unconfirmed');
    }
}

/**
 * Post-COMMIT notification targets are independent. Record the exact
 * bounded target identity, continue later account/admin deliveries and
 * never replay financial or remote effects on Telegram failure.
 */
if (!function_exists('achbotManualStandardNotice')) {
    function achbotManualStandardNotice(callable $action, string $stage,
                                      int $payId, array &$issues,
                                      int $ordinal = 0, int $target = 0): bool {
        try {
            achbotManualStandardRequireTelegramResult($action(),$stage);
            return true;
        } catch (Throwable $error) {
            $issues[]=['stage'=>$stage,'ordinal'=>$ordinal,'target'=>$target];
            error_log('achbot_manual_standard_delivery_needs_review pay_id='.$payId.
                ' stage='.$stage.' ordinal='.$ordinal.' target='.$target.
                ' exception='.get_class($error));
            return false;
        }
    }
}

if (!function_exists('achbotManualStandardReportDelivery')) {
    function achbotManualStandardReportDelivery(int $payId,string $hashId,
                                                array $issues,int $admin): void {
        if (!$issues) return;
        $lines=[];
        foreach (array_slice($issues,0,24) as $issue) {
            $line=(string)$issue['stage'];
            if ($issue['ordinal']>0) $line.=' #'.(int)$issue['ordinal'];
            if ($issue['target']!==0) $line.=' chat:'.(int)$issue['target'];
            $lines[]=$line;
        }
        if (count($issues)>24) $lines[]='additional_targets:'.(count($issues)-24);
        try {
            achbotManualStandardRequireTelegramResult(
                sendMessage("✅ سفارش/سرویس ثبت شده است؛ اما اطلاع‌رسانی تعدادی از حساب‌ها یا مدیران نامطمئن است.\n🆔 "
                    .$hashId."\n".implode("\n",$lines).
                    "\nحساب‌ها و پیام‌ها را تطبیق دهید؛ عملیات سرویس/مالی را تکرار نکنید.",
                    null,null,$admin),
                'delivery_summary');
        } catch (Throwable $error) {
            error_log('achbot_manual_standard_delivery_summary_failed pay_id='.$payId.
                ' count='.count($issues).' exception='.get_class($error));
        }
    }
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
    $reservedAccountCount=null;
    $followupOrdinal=0;

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
        if (!$payInfo || !achbotSupportsPaymentSubject($connection, $payInfo, true) ||
            !achbotManualServiceApprovalAllowedLocked($connection, $payInfo, $receiptSubject)) {
            $connection->rollback();
            $claimOpen = false;
            alert('⛔️ این درخواست قابل تأیید نیست؛ وضعیت رسید، روش پرداخت یا نوع سفارش را بررسی کنید.');
            return;
        }
        $payId = (int)$payInfo['id'];
        $actor = (int)$from_id;
        if (($payInfo['type']??null)==='BUY_SUB') {
            // The 007 journal is mandatory for current paid CREATE. Probe
            // its exact unique identity constraints while the pay row is
            // locked, BEFORE reserving capacity or committing processing.
            // A missing/partial migration must leave the original receipt
            // reviewable without creating an unfulfillable held claim.
            achbotEffectRequireUniqueLine($connection);
            // Capacity and the no-replay payment claim MUST commit together,
            // before any remote CREATE, across competing buyer payments.
            $reservedAccountCount=achbotManualStandardReserveCapacityLocked(
                $connection,$payInfo);
        }
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
    $deliveryIssues=[];
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

            // Use only the original persisted payment destination. Never
            // rebind an approved request from mutable current plan mapping.
            $server_id=(int)$payInfo['server_id'];
            $inbound_id=(int)$payInfo['volume'];
            if ($server_id<=0 || $inbound_id!==0 ||
                (int)$plan['server_id']!==$server_id ||
                (int)$plan['inbound_id']!==0 ||
                !achbotSupportsPaymentSubject($connection,$payInfo,true))
                throw new RuntimeException('manual_standard_renewal_target_changed');

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

            achbotManualStandardNotice(
                static fn()=>sendMessage(
                    str_replace(["REMARK","VOLUME","DAYS"],[$remark,$volume,$days],
                        $mainValues['renewed_config_to_user']),getMainKeys(),null,null),
                'renewal_customer',$payId,$deliveryIssues,0,(int)$from_id);
            achbotManualStandardNotice(
                static fn()=>sendMessage("✅ اشتراک $remark با موفقیت تمدید شد.",null,null,$uid),
                'renewal_user_notice',$payId,$deliveryIssues,0,$uid);

            $keys = json_encode([
                'inline_keyboard' => [
                    [['text' => "✅ تایید شد توسط $from_id", 'callback_data' => "wizwizch"]],
                    [['text' => "🔄 $remark",                'callback_data' => "noop"]],
                    [['text' => "🆔 $hash_id",              'callback_data' => "noop_$hash_id"]],
                ]
            ], 488);

            $followupStage = 'renewal_operator_buttons';
            try {
                achbotManualStandardRequireTelegramResult(
                    editKeys($keys, $payInfo['message_id'], $payInfo['chat_id']),
                    'renewal_operator_buttons');
            } catch (Throwable $e) {
                error_log('achbot_manual_standard_buttons_update_failed pay_id='.$payId.
                    ' subject=renewal exception='.get_class($e).' code='.(int)$e->getCode());
                $deliveryIssues[]=['stage'=>'renewal_operator_buttons',
                    'ordinal'=>0,'target'=>(int)$payInfo['chat_id']];
                achbotManualStandardNotice(
                    static fn()=>sendMessage("✅ تمدید ثبت شده است، اما به‌روزرسانی دکمه‌های پیام مدیر تأیید نشد.\n🆔 $hash_id\nوضعیت پیام را بررسی کنید؛ تمدید را دوباره تأیید نکنید.",null,null,$admin),
                    'renewal_buttons_operator_alert',$payId,$deliveryIssues,0,$admin);
            }

            $adminMessages = json_decode($payInfo['admins_messages'], true);
            if (is_array($adminMessages)) {
                foreach ($adminMessages as $msgInfo) {
                    if (isset($msgInfo['chat_id'], $msgInfo['message_id']) && $msgInfo['chat_id'] != $payInfo['chat_id']) {
                        achbotManualStandardNotice(
                            static fn()=>editKeys($keys,$msgInfo['message_id'],$msgInfo['chat_id']),
                            'renewal_additional_operator_buttons',$payId,$deliveryIssues,0,
                            (int)$msgInfo['chat_id']);
                    }
                }
            }

            achbotManualStandardReportDelivery($payId,$hash_id,$deliveryIssues,(int)$admin);
            return;
        }

        $accountCount=$reservedAccountCount;
        if (!is_int($accountCount) || $accountCount<=0)
            throw new RuntimeException('manual_standard_capacity_claim_missing');
        $eachPrice    = ($accountCount > 0) ? ($price / $accountCount) : $price;

        $planDetails = getPlanDetails($connection, $fid);
        $plan        = $planDetails['plan'];

        $days             = $planDetails['days'];
        $volume           = $planDetails['volume'];
        $date             = $planDetails['date'];
        $expire_microdate = $planDetails['expire_microdate'];
        $expire_date      = $planDetails['expire_date'];

        $protocol   = $plan['protocol'];
        $server_id  = (int)$payInfo['server_id'];
        $netType    = $plan['type'];
        $acount     = (int)$plan['acount'];
        $inbound_id = (int)$plan['inbound_id'];
        $limitip    = $plan['limitip'];
        $rahgozar   = $plan['rahgozar'];
        $customPath = $plan['custom_path'];
        $customPort = $plan['custom_port'];
        $customSni  = $plan['custom_sni'];

        // The entire requested count was reserved atomically at payment claim.
        // Refuse plan drift; never redirect work or charge capacity twice.
        if ($server_id<=0 || (int)$plan['server_id']!==$server_id ||
            $inbound_id!==0 || !achbotSupportsPaymentSubject($connection,$payInfo,true))
            throw new RuntimeException('manual_standard_purchase_target_changed');

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

            // Already reserved before CREATE. Do NOT decrement again.

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

        // Financially owed reward is independent from QR and message delivery.
        // Attempt it first; a later Telegram outage cannot skip the credit.
        try {
            $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid`=?");
            $stmt->bind_param("i", $uid);
            $stmt->execute();
            $user_detail = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!empty($user_detail['refered_by'])) {
                $inviterId = (int)$user_detail['refered_by'];

                $followupStage = 'referral_reward';
                if (giveReferralReward($connection, $user_detail['userid'],
                                       $inviterId, $admin, $botState)!==true)
                    throw new RuntimeException('manual_standard_referral_unconfirmed');

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

                    achbotManualStandardNotice(
                        static fn()=>sendMessage($msg,$keys2,"html",$admin),
                        'referral_admin_message',$payId,$deliveryIssues,0,$admin);

                    $rewardChannel = $botState['rewardChannel'] ?? null;
                    if (!empty($rewardChannel)) {
                        achbotManualStandardNotice(
                            static fn()=>sendMessage($msg,$keys2,"html",$rewardChannel),
                            'referral_channel_message',$payId,$deliveryIssues,0,(int)$rewardChannel);
                    }
                }
            }
        } catch (Throwable $error) {
            $deliveryIssues[]=['stage'=>'referral_reward',
                'ordinal'=>0,'target'=>(int)($inviterId??0)];
            error_log('achbot_manual_standard_referral_needs_review pay_id='.$payId.
                ' buyer='.$uid.' inviter='.(int)($inviterId??0).
                ' exception='.get_class($error));
        }

        foreach ($pendingUserQrs as $index => $pq) {
            $followupOrdinal=$index+1;
            achbotManualStandardNotice(
                static fn()=>sendQrFromLink(
                    $pq['qrLink'],$pq['acc_text'],$pq['keys'],$botUrl,
                    (int)$pq['uid'],'',null),
                'purchase_account_qr',$payId,$deliveryIssues,$followupOrdinal,(int)$pq['uid']);
        }
        $followupOrdinal=0;

        $followupStage = 'purchase_operator_notice';
        achbotManualStandardNotice(
            static fn()=>sendMessage(str_replace(
                ["USERID","REMARK","VOLUME","DAYS","PRICE","HASHID"],
                [$uid,$lastRemarkForAdminMsg,$volume,$days,$price,$hash_id],
                $mainValues['sent_config_to_user']),getMainKeys()),
            'purchase_operator_notice',$payId,$deliveryIssues,0,(int)$from_id);

        $keys = json_encode([
            'inline_keyboard' => [
                [['text' => "✅ تایید شد توسط $from_id", 'callback_data' => "wizwizch"]],
                [['text' => "🔄 $lastRemarkForAdminMsg",  'callback_data' => "noop"]],
                [['text' => "🆔 $hash_id",               'callback_data' => "noop_$hash_id"]],
            ]
        ], 488);

        $followupStage = 'purchase_operator_buttons';
        try {
            achbotManualStandardRequireTelegramResult(
                editKeys($keys, $payInfo['message_id'], $payInfo['chat_id']),
                'purchase_operator_buttons');
        } catch (Throwable $e) {
            error_log('achbot_manual_standard_buttons_update_failed pay_id='.$payId.
                ' subject=purchase exception='.get_class($e).' code='.(int)$e->getCode());
            $deliveryIssues[]=['stage'=>'purchase_operator_buttons',
                'ordinal'=>0,'target'=>(int)$payInfo['chat_id']];
            achbotManualStandardNotice(
                static fn()=>sendMessage("✅ سفارش ثبت شده است، اما به‌روزرسانی دکمه‌های پیام مدیر تأیید نشد.\n🆔 $hash_id\nوضعیت پیام را بررسی کنید؛ سفارش را دوباره تأیید نکنید.",null,null,$admin),
                'purchase_buttons_operator_alert',$payId,$deliveryIssues,0,$admin);
        }

        $adminMessages = json_decode($payInfo['admins_messages'], true);
        if (is_array($adminMessages)) {
            foreach ($adminMessages as $msgInfo) {
                if (isset($msgInfo['chat_id'], $msgInfo['message_id']) && $msgInfo['chat_id'] != $payInfo['chat_id']) {
                    achbotManualStandardNotice(
                        static fn()=>editKeys($keys,$msgInfo['message_id'],$msgInfo['chat_id']),
                        'purchase_additional_operator_buttons',$payId,$deliveryIssues,0,
                        (int)$msgInfo['chat_id']);
                }
            }
        }

        achbotManualStandardReportDelivery($payId,$hash_id,$deliveryIssues,(int)$admin);

    } catch (Throwable $e) {
        // An error in Telegram or post-commit referral work cannot undo a
        // confirmed local order/renewal. Keep its error signal distinct from
        // an uncertain remote effect or transaction commit.
        if ($serviceCommitted) {
            error_log('achbot_manual_standard_postcommit_followup_needs_review pay_id='.$payId.
                ' stage='.$followupStage.' exception='.get_class($e).' code='.(int)$e->getCode());
            try {
                $accountContext=$followupOrdinal>0
                    ?"\n🔢 شماره حساب: ".$followupOrdinal:'';
                $rewardContext=($followupStage==='referral_reward' && isset($inviterId))
                    ?"\n🎁 شناسه معرف: ".(int)$inviterId:'';
                $phaseText=match($followupStage) {
                    'renewal_customer_notice'=>'پیام تمدید مشتری',
                    'renewal_operator_buttons'=>'دکمه‌های تأیید مدیر',
                    'purchase_account_delivery'=>'ارسال اطلاعات سرویس مشتری',
                    'purchase_operator_notice'=>'پیام مدیر',
                    'purchase_operator_buttons'=>'دکمه‌های مدیر',
                    'referral_reward'=>'اعتبار یا پیام پاداش معرف',
                    default=>'اطلاع‌رسانی تکمیلی',
                };
                achbotManualStandardRequireTelegramResult(
                    sendMessage("✅ سفارش ثبت شده و عملیات سرویس در سیستم تأیید شده است، اما اطلاع‌رسانی یا پاداش در مرحلهٔ "
                        .$phaseText." ناقص/نامطمئن است.\n🆔 $hash_id"
                        .$accountContext.$rewardContext.
                        "\nارسال پیام یا اعتبار معرف را دستی تطبیق دهید؛ سرویس و اعتبار را دوباره اعمال نکنید.",
                        null,null,$admin),
                    'postcommit_operator_alert');
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

