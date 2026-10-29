<?php
/** AChBot V8: source-pinned financial compatibility module. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../../messages/loader.php'; // ACHBOT_TEXT_CATALOG

require_once __DIR__ . '/../../Admin/Access.php';
if (!achbotAdminRequestAllowed(is_string($data??null)?$data:null,is_string($text??null)?$text:null,(string)($userInfo['step']??''))) {achbotAdminDeny();return;}


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
            if ($issue['target']!==0) $line.=\achbotMessage('manual_payment.manual_approval.manual_standard_report_delivery.chat.127f57d934').(int)$issue['target'];
            $lines[]=$line;
        }
        if (count($issues)>24) $lines[]=\achbotMessage('manual_payment.manual_approval.manual_standard_report_delivery.additional_targets.83d6318b6e').(count($issues)-24);
        try {
            achbotManualStandardRequireTelegramResult(
                sendMessage(\achbotMessage('manual_payment.manual_approval.manual_standard_report_delivery.message.7c3a3ec221')
                    .$hashId."\n".implode("\n",$lines).
                    \achbotMessage('manual_payment.manual_approval.manual_standard_report_delivery.message.be6b530354'),
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
    achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'payments')
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
            alert(\achbotMessage('manual_payment.manual_approval.manual_standard_report_delivery.alert.9ca98e8676'));
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
            // The same transaction must persist WHO owns those full-count
            // slots. Paid CREATE consumes this 007 marker, never decrements
            // server_info a second time for an already claimed receipt.
            achbotEffectRecordManualCapacityLocked(
                $connection,$payInfo,$reservedAccountCount);
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
        alert(\achbotMessage('manual_payment.manual_approval.manual_standard_report_delivery.alert.fd978f1d4d'));
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
                sendMessage(\achbotMessage('manual_payment.manual_approval.manual_standard_report_delivery.message.4194effbee', ['hash_id' => $hash_id]), null, null, $admin);
                return;
            }

            achbotManualStandardNotice(
                static fn()=>sendMessage(
                    str_replace(["REMARK","VOLUME","DAYS"],[$remark,$volume,$days],
                        $mainValues['renewed_config_to_user']),getMainKeys(),null,null),
                'renewal_customer',$payId,$deliveryIssues,0,(int)$from_id);
            achbotManualStandardNotice(
                static fn()=>sendMessage(\achbotMessage('manual_payment.automatic_receipts.message.cca1075329', ['remark' => $remark]),null,null,$uid),
                'renewal_user_notice',$payId,$deliveryIssues,0,$uid);

            $keys = json_encode([
                'inline_keyboard' => [
                    [['text' => \achbotMessage('manual_payment.manual_approval.manual_standard_report_delivery.button.139c829462', ['from_id' => $from_id]), 'callback_data' => "wizwizch"]],
                    [['text' => \achbotMessage('manual_payment.manual_approval.manual_standard_report_delivery.remark.4348a3fd9b', ['remark' => $remark]),                'callback_data' => "noop"]],
                    [['text' => \achbotMessage('manual_payment.manual_approval.manual_standard_report_delivery.hash_id.2899228604', ['hash_id' => $hash_id]),              'callback_data' => "noop_$hash_id"]],
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
                    static fn()=>sendMessage(\achbotMessage('manual_payment.manual_approval.manual_standard_report_delivery.message.60d43de4f9', ['hash_id' => $hash_id]),null,null,$admin),
                    'renewal_buttons_operator_alert',$payId,$deliveryIssues,0,$admin);
            }

            $adminMessages = json_decode($payInfo['admins_messages'], true);
            if (is_array($adminMessages)) {
                foreach ($adminMessages as $msgInfo) {
                    if (isset($msgInfo['chat_id'], $msgInfo['message_id']) && $msgInfo['chat_id'] != $payInfo['chat_id'] &&
                        achbotAdminRecipientAllowed($connection,$msgInfo['chat_id'],(int)$admin,'payments')) {
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
                [],
                false,
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
                'expire_date'  => $expire_date,
                'vray_link'    => $vray_link,
                'amount'       => achbotAllocateOrderAmount((int)$price,$accountCount,$i),
                'date'         => $date,
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
            sendMessage(\achbotMessage('manual_payment.manual_approval.manual_standard_report_delivery.message.5eb93029eb', ['hash_id' => $hash_id]), null, null, $admin);
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
                    $o['expire_date'],
                    $o['vray_link'],
                    $o['amount'],
                    $o['date'],
                    $o['agent_bought'],
                    true
                );
                achbotLinkPaidAccountEffect($connection,(int)$o['effect_id'],(int)$orderId);
            }

            // The verified per-payment 007 journal owns the full capacity
            // before CREATE; neither manual nor hosted finisher deducts it again.

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
            sendMessage(\achbotMessage('manual_payment.manual_approval.manual_standard_report_delivery.message.6f4a274426', ['hash_id' => $hash_id]),
                        null, null, $admin);
            return;
        }

        // Register the payment-keyed 008 award after the local committed
        // order and before any fallible Telegram/QR presentation.
        $followupStage='purchase_referral_journal';
        $user_detail=achbotReferralAfterCompletedPurchase(
            $connection,(int)$uid,$payId,(int)$admin,$botState);

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
                [['text' => \achbotMessage('manual_payment.manual_approval.manual_standard_report_delivery.button.139c829462', ['from_id' => $from_id]), 'callback_data' => "wizwizch"]],
                [['text' => \achbotMessage('manual_payment.manual_approval.manual_standard_report_delivery.last_remark_for_admin_msg.2b262756e6', ['lastRemarkForAdminMsg' => $lastRemarkForAdminMsg]),  'callback_data' => "noop"]],
                [['text' => \achbotMessage('manual_payment.manual_approval.manual_standard_report_delivery.hash_id.2899228604', ['hash_id' => $hash_id]),               'callback_data' => "noop_$hash_id"]],
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
                static fn()=>sendMessage(\achbotMessage('manual_payment.manual_approval.manual_standard_report_delivery.message.76fb0b9a90', ['hash_id' => $hash_id]),null,null,$admin),
                'purchase_buttons_operator_alert',$payId,$deliveryIssues,0,$admin);
        }

        $adminMessages = json_decode($payInfo['admins_messages'], true);
        if (is_array($adminMessages)) {
            foreach ($adminMessages as $msgInfo) {
                if (isset($msgInfo['chat_id'], $msgInfo['message_id']) && $msgInfo['chat_id'] != $payInfo['chat_id'] &&
                    achbotAdminRecipientAllowed($connection,$msgInfo['chat_id'],(int)$admin,'payments')) {
                    achbotManualStandardNotice(
                        static fn()=>editKeys($keys,$msgInfo['message_id'],$msgInfo['chat_id']),
                        'purchase_additional_operator_buttons',$payId,$deliveryIssues,0,
                        (int)$msgInfo['chat_id']);
                }
            }
        }

        // Invitee announcement is presentation only; do not repeat any
        // financial reward. Keep every independent target failure visible.
        if (!empty($user_detail['refered_by']) && $admin!=$from_id) {
            $uname=$user_detail['name'];
            $user_name=$user_detail['username'];
            $keys2=json_encode(['inline_keyboard'=>[[
                ['text'=>\achbotMessage('manual_payment.manual_approval.manual_standard_report_delivery.button.e401f3d5a2'),'callback_data'=>"wizwizch"]
            ]]]);
            $msg=str_replace(
                ['USER-ID','USERNAME','NAME','PRICE','REMARK','HASHID'],
                [$uid,$user_name,$uname,$price,$lastRemarkForAdminMsg,$hash_id],
                $mainValues['invite_buy_new_account']
            );
            achbotManualStandardNotice(
                static fn()=>sendMessage($msg,$keys2,'html',$admin),
                'referral_purchase_operator_notice',$payId,$deliveryIssues,0,(int)$admin);
            $rewardChannel=$botState['rewardChannel']??null;
            if ($rewardChannel) {
                achbotManualStandardNotice(
                    static fn()=>sendMessage($msg,$keys2,'html',$rewardChannel),
                    'referral_purchase_channel_notice',$payId,$deliveryIssues,0,
                    (int)$rewardChannel);
            }
        }
        achbotManualStandardReportDelivery(
            $payId,$hash_id,$deliveryIssues,(int)$admin);

    } catch (Throwable $e) {
        // An error in Telegram or post-commit referral work cannot undo a
        // confirmed local order/renewal. Keep its error signal distinct from
        // an uncertain remote effect or transaction commit.
        if ($serviceCommitted) {
            error_log('achbot_manual_standard_postcommit_followup_needs_review pay_id='.$payId.
                ' stage='.$followupStage.' exception='.get_class($e).' code='.(int)$e->getCode());
            try {
                $accountContext=$followupOrdinal>0
                    ?\achbotMessage('manual_payment.manual_approval.manual_standard_report_delivery.text.1b02ee00d1').$followupOrdinal:'';
                $rewardContext=($followupStage==='referral_reward' && isset($inviterId))
                    ?\achbotMessage('manual_payment.manual_approval.manual_standard_report_delivery.text.29a5fe19ed').(int)$inviterId:'';
                $phaseText=match($followupStage) {
                    'renewal_customer_notice'=>\achbotMessage('manual_payment.manual_approval.manual_standard_report_delivery.text.33f0297acd'),
                    'renewal_operator_buttons'=>\achbotMessage('manual_payment.manual_approval.manual_standard_report_delivery.text.b24a7f8dca'),
                    'purchase_account_delivery'=>\achbotMessage('manual_payment.manual_approval.manual_standard_report_delivery.text.f91dbdba95'),
                    'purchase_operator_notice'=>\achbotMessage('manual_payment.manual_approval.manual_standard_report_delivery.text.dccef59f47'),
                    'purchase_operator_buttons'=>\achbotMessage('manual_payment.manual_approval.manual_standard_report_delivery.text.3345bdc147'),
                    'referral_reward'=>\achbotMessage('manual_payment.manual_approval.manual_standard_report_delivery.text.9e7605c2cd'),
                    default=>\achbotMessage('manual_payment.manual_approval.manual_standard_report_delivery.text.1b5f3e9a80'),
                };
                achbotManualStandardRequireTelegramResult(
                    sendMessage(\achbotMessage('manual_payment.manual_approval.manual_standard_report_delivery.message.797f106a46')
                        .$phaseText.\achbotMessage('manual_payment.manual_approval.manual_standard_report_delivery.message.67ca045c30', ['hash_id' => $hash_id])
                        .$accountContext.$rewardContext.
                        \achbotMessage('manual_payment.manual_approval.manual_standard_report_delivery.text.c46767b99f'),
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
            sendMessage(\achbotMessage('manual_payment.manual_approval.manual_standard_report_delivery.message.79749fa46e', ['hash_id' => $hash_id]),
                        null, null, $admin);
        } catch (Throwable $notifyError) {
            error_log('achbot_manual_standard_review_notice_failed pay_id='.$payId.
                ' exception='.get_class($notifyError).' code='.(int)$notifyError->getCode());
        }
        return;
    }
}

