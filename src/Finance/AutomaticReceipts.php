<?php
/** AChBot V8: source-pinned financial compatibility module. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG
require_once __DIR__.'/../Admin/Access.php';


if($botState['cartToCartAutoAcceptState']=="on"){
    $date = strtotime("-" . ($botState['cartToCartAutoAcceptTime']??10) . " minutes");
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `state` = 'have_sent' AND `request_date` <= ?");
    $stmt->bind_param('i', $date);
    $stmt->execute();
    $info = $stmt->get_result();
    $stmt->close();

    // A ledger/schema outage can also invalidate a live mysqli result while
    // fetching the next pays row (observed on PHP 8.1/mysqlnd). Stop this
    // auto-approval sweep without approving any unchecked receipt.
    while (true) {
        try {
            $payInfo = $info->fetch_assoc();
        } catch (Throwable $error) {
            error_log('achbot_bank_auto_scan_deferred');
            break;
        }
        if ($payInfo === null || $payInfo === false) break;
        if (!achbotAutoBankEligible($connection, $payInfo)) continue;
        if (!achbotSupportsPaymentSubject($connection, $payInfo)) {
            continue;
        }

        $time = time();
        $rowId = $payInfo['id'];
        $price = $payInfo['price'];
        $user_id = $payInfo['user_id'];
        $payType = $payInfo['type'];
        $deviceId = $payInfo['device_id'] ?? null;
        
        $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $userinfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        
        if($userinfo['is_agent'] == 1 && ($botState['cartToCartAutoAcceptType']??2) == 1) continue;
        elseif($userinfo['is_agent'] != 1 && ($botState['cartToCartAutoAcceptType']??2) == 0) continue;
        
        $agentBought = $payInfo['agent_bought'];
        
        // Re-lock the original pays row: stale scans cannot override a
        // concurrent administrator decision or repeat service effects.
        if (!achbotAutoBankClaim($connection, $payInfo)) continue;
        // One iteration must not inherit another customer's completion state.
        $autoServiceCompleted=null;
        // Operator-only context for a claimed but not yet fulfilled bank BUY_SUB.
        // Price is the stored order amount, never proof of bank settlement.
        $bankReviewContext=$payType==='BUY_SUB'
            ? \achbotMessage('manual_payment.automatic_receipts.text.ba58f1caaf').(int)$user_id
                .\achbotMessage('manual_payment.automatic_receipts.text.12646a1ad0').number_format((int)$price).\achbotMessage('manual_payment.automatic_receipts.text.2cc98f7f7c')
                .\achbotMessage('manual_payment.automatic_receipts.text.e1cad19d95').max(1,(int)($payInfo['agent_count']??1))
            : '';
        try {
        
        if($payType == "INCREASE_WALLET"){
            // Wallet credit and the terminal claim already committed atomically.
            sendMessage(\achbotMessage('manual_payment.automatic_receipts.message.38dd599e83') . number_format($price). \achbotMessage('manual_payment.automatic_receipts.message.6f42cf1050'), null, null, $user_id);
        }
        elseif($payType == "BUY_SUB"){
            // The bank receipt is paid evidence, not a completed subscription.
            // A durable processing claim must precede all external CREATE calls.
            $autoServiceCompleted=false;
            require_once __DIR__.'/ServiceEffects.php';
            require_once __DIR__.'/BankPurchase.php';
            $delivery=achbotAutoBankProvisionBuySub(
                $connection,$payInfo,$botState,$buttonValues,
                [],
                false,(string)$botUrl
            );
            $autoServiceCompleted=$delivery['committed'];
            if ($autoServiceCompleted) {
                // Telegram/QR is post-COMMIT only; failures never recreate
                // accounts, debit/credit wallets or change financial finality.
                $unconfirmedDeliveries=[];
                foreach ($delivery['deliveries'] as $index=>$packet) {
                    try {
                        $sent=sendQrFromLink($packet['qr'],$packet['caption'],
                            $packet['keys'],(string)$botUrl,(int)$packet['uid']);
                        if (!$sent) {
                            $unconfirmedDeliveries[]=$index+1;
                            error_log('achbot_bank_buy_delivery_unconfirmed pay_id='.(int)$rowId.' line='.($index+1));
                        }
                    } catch (Throwable $deliveryError) {
                        $unconfirmedDeliveries[]=$index+1;
                        error_log('achbot_bank_buy_delivery_error pay_id='.(int)$rowId.
                            ' line='.($index+1).' class='.get_class($deliveryError));
                    }
                }
                if ($unconfirmedDeliveries!==[]) {
                    // Fulfillment is already committed. Never retry CREATE just
                    // because a Telegram acknowledgment was lost.
                    try {
                        sendMessage(\achbotMessage('manual_payment.automatic_receipts.message.9b88994424')
                            .\achbotMessage('manual_payment.automatic_receipts.message.ba9287d8c1').(int)$rowId.$bankReviewContext
                            .\achbotMessage('manual_payment.automatic_receipts.text.d828bce37f').implode(', ',$unconfirmedDeliveries)
                            .\achbotMessage('manual_payment.automatic_receipts.text.38146cb4da'),
                            null,null,$admin);
                    } catch (Throwable $noticeError) {
                        error_log('achbot_bank_buy_delivery_notice_failed pay_id='.(int)$rowId);
                    }
                }
                // Preserve the legacy referral journey only after finality.
                // Any uncertain bonus outcome stays an operator matter; no
                // automatic replay of the paid service will be permitted.
                if (!empty($userinfo['refered_by'])) {
                    giveReferralReward($connection,(int)$user_id,
                        (int)$userinfo['refered_by'],$admin,$botState,null,$rowId);
                }
            } else {
                sendMessage(\achbotMessage('manual_payment.automatic_receipts.message.809aa9e256')
                    .\achbotMessage('manual_payment.automatic_receipts.message.c0b5e951d8'),
                    null,null,$user_id);
            }
        }
                elseif($payType == "RENEW_ACCOUNT"){
            $oid = $payInfo['order_id'];
            $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
            $stmt->bind_param("i", $oid);
            $stmt->execute();
            $order = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $fid = $order['fileid'];
            $remark = $order['remark'];
            $uuid = $order['uuid']??"0";
            $server_id = $order['server_id'];
            $inbound_id = $order['inbound_id'];
            $expire_date = $order['expire_date'];
            $expire_date = ($expire_date > $time) ? $expire_date : $time;
            
            $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ? AND `active` = 1");
            $stmt->bind_param("i", $fid);
            $stmt->execute();
            $respd = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $name = $respd['title'];
            $days = $respd['days'];
            $volume = $respd['volume'];
            $price = $payInfo['price'];
            
            $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
            $stmt->bind_param("i", $server_id);
            $stmt->execute();
            $server_info = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $serverType = $server_info['type'];
        
            if (!achbotAutoBankEditPreflight($connection,$payInfo,'renew',
                    (int)$server_id,(int)$oid,(string)$remark))
                throw new RuntimeException('auto_service_subject_drift');
            if(achbotSupportsPanel($serverType)){
                try {
                    $response = achbotApplyServiceEdit($connection,$server_id, ['pay_id'=>(int)$rowId, 'remark'=>$remark, 'days'=>$days, 'volume' => $volume]);
                } catch (Throwable $error) {
                    // A provider/SQL exception after dispatch is UNKNOWN,
                    // never authorization to claim completed or refund.
                    error_log('achbot_auto_service_dispatch_deferred pay_id='.(int)$rowId);
                    $response = (object)['success'=>false,'uncertain'=>true];
                }
            }else{
            throw new \UnexpectedValueException('unsupported_panel');
        }
            
            $verified=(is_object($response) && $response->success===true);
            $autoServiceCompleted=$verified && achbotAutoBankFinishEdit(
                $connection,$payInfo,$response,'renew',(int)$server_id,
                (int)$oid,(string)$remark,(int)$price,0,(int)$order['expire_date']);
            if ($autoServiceCompleted) {
                sendMessage(\achbotMessage('manual_payment.automatic_receipts.message.cca1075329', ['remark' => $remark]),getMainKeys(),null,$user_id);
            } else {
                // Bank receipt and possible remote effect stay durably claimed.
                // No false success, second remote write or blind wallet refund.
                sendMessage(\achbotMessage('manual_payment.automatic_receipts.message.ca1eaa01e6'),null,null,$user_id);
            }
        }
        elseif(preg_match('/^INCREASE_DAY_(\d+)_(\d+)/',$payType, $increaseInfo)){
            $orderId = $increaseInfo[1];
            
            $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
            $stmt->bind_param("i", $orderId);
            $stmt->execute();
            $orderInfo = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            $server_id = $orderInfo['server_id'];
            $inbound_id = $orderInfo['inbound_id'];
            $remark = $orderInfo['remark'];
            $uuid = $orderInfo['uuid']??"0";
            
            $planid = $increaseInfo[2];
        
            
            
            $stmt = $connection->prepare("SELECT * FROM `increase_day` WHERE `id` = ?");
            $stmt->bind_param("i", $planid);
            $stmt->execute();
            $res = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $price = $payInfo['price'];
            $volume = achbotPinnedAddonUnits($payInfo);
            if ($volume===null) throw new RuntimeException('service_quote_units_unverified');
        
            $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
            $stmt->bind_param("i", $server_id);
            $stmt->execute();
            $server_info = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $serverType = $server_info['type'];
        
            if (!achbotAutoBankEditPreflight($connection,$payInfo,'day',
                    (int)$server_id,(int)$orderId,(string)$remark,(int)$volume))
                throw new RuntimeException('auto_service_subject_drift');
            if(achbotSupportsPanel($serverType)){
                try {
                    $response = achbotApplyServiceEdit($connection,$server_id, ['pay_id'=>(int)$rowId, 'remark'=>$remark, 'plus_day'=>$volume]);
                } catch (Throwable $error) {
                    // A provider/SQL exception after dispatch is UNKNOWN,
                    // never authorization to claim completed or refund.
                    error_log('achbot_auto_service_dispatch_deferred pay_id='.(int)$rowId);
                    $response = (object)['success'=>false,'uncertain'=>true];
                }
            }else{
            throw new \UnexpectedValueException('unsupported_panel');
        }
            
            $verified=(is_object($response) && $response->success===true);
            $autoServiceCompleted=$verified && achbotAutoBankFinishEdit(
                $connection,$payInfo,$response,'day',(int)$server_id,
                (int)$orderId,(string)$remark,(int)$price,(int)$volume,
                (int)$orderInfo['expire_date']);
            if ($autoServiceCompleted) {
                sendMessage(\achbotMessage('manual_payment.automatic_receipts.message.7c7851027c', ['volume' => $volume]),getMainKeys(),null,$user_id);
            } else {
                sendMessage(\achbotMessage('manual_payment.automatic_receipts.message.6223bac2fc'),null,null,$user_id);
            }
        }
        elseif(preg_match('/^INCREASE_VOLUME_(\d+)_(\d+)/',$payType, $increaseInfo)){
            $orderId = $increaseInfo[1];
            
            $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
            $stmt->bind_param("i", $orderId);
            $stmt->execute();
            $orderInfo = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            $server_id = $orderInfo['server_id'];
            $inbound_id = $orderInfo['inbound_id'];
            $remark = $orderInfo['remark'];
            $uuid = $orderInfo['uuid']??"0";
            
            $planid = $increaseInfo[2];
            
            $stmt = $connection->prepare("SELECT * FROM `increase_plan` WHERE `id` = ?");
            $stmt->bind_param("i", $planid);
            $stmt->execute();
            $res = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $price = $payInfo['price'];
            $volume = achbotPinnedAddonUnits($payInfo);
            if ($volume===null) throw new RuntimeException('unsupported_service_units');
            
                $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
                $stmt->bind_param("i", $server_id);
                $stmt->execute();
                $server_info = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $serverType = $server_info['type'];
            
                if (!achbotAutoBankEditPreflight($connection,$payInfo,'volume',
                    (int)$server_id,(int)$orderId,(string)$remark,(int)$volume))
                throw new RuntimeException('auto_service_subject_drift');
            if(achbotSupportsPanel($serverType)){
                    try {
                    $response = achbotApplyServiceEdit($connection,$server_id, ['pay_id'=>(int)$rowId, 'remark'=>$remark, 'plus_volume'=>$volume]);
                } catch (Throwable $error) {
                    // A provider/SQL exception after dispatch is UNKNOWN,
                    // never authorization to claim completed or refund.
                    error_log('achbot_auto_service_dispatch_deferred pay_id='.(int)$rowId);
                    $response = (object)['success'=>false,'uncertain'=>true];
                }
                }else{
            throw new \UnexpectedValueException('unsupported_panel');
        }
                
            $verified=(is_object($response) && $response->success===true);
            $autoServiceCompleted=$verified && achbotAutoBankFinishEdit(
                $connection,$payInfo,$response,'volume',(int)$server_id,
                (int)$orderId,(string)$remark,(int)$price,(int)$volume,
                (int)$orderInfo['expire_date']);
            if ($autoServiceCompleted) {
                sendMessage(\achbotMessage('manual_payment.automatic_receipts.message.1660fa3e00', ['volume' => $volume]),getMainKeys(),null,$user_id);
            } else {
                sendMessage(\achbotMessage('manual_payment.automatic_receipts.message.530f1966b8'),null,null,$user_id);
            }
        }
        elseif($payType == "RENEW_SCONFIG"){
            $user_id = $user_id;
            $fid = $payInfo['plan_id']; 
        
            $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
            $stmt->bind_param("i", $fid);
            $stmt->execute();
            $file_detail = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            $volume = $file_detail['volume'];
            $days = $file_detail['days'];
            
            $price = $payInfo['price'];   
            $server_id = $file_detail['server_id'];
            $configInfo = json_decode((string)$payInfo['description'],true);
            $remark = (string)(is_array($configInfo)?($configInfo['remark']??''):'');
            if ($remark==='') throw new RuntimeException('auto_service_missing_account');
            
            // Payment account identity stays pinned. Panel dispatch is
            // delegated; a new panel must provide verified readback first.
            if (!achbotAutoBankEditPreflight($connection,$payInfo,'standard',
                    (int)$server_id,0,(string)$remark))
                throw new RuntimeException('auto_service_subject_drift');

            try {
                $response = achbotApplyServiceEdit($connection,$server_id,
                    ['pay_id'=>(int)$rowId, 'remark'=>$remark, 'days'=>$days, 'volume'=>$volume]);
            } catch (Throwable $error) {
                error_log('achbot_auto_service_dispatch_deferred pay_id='.(int)$rowId.
                    ' class='.get_class($error).' code='.(int)$error->getCode());
                $response = (object)['success'=>false,'uncertain'=>true];
        }
            
            $verified=(is_object($response) && $response->success===true);
            $autoServiceCompleted=$verified && achbotAutoBankFinishEdit(
                $connection,$payInfo,$response,'standard',(int)$server_id,
                0,(string)$remark,(int)$price);
            if ($autoServiceCompleted) {
                sendMessage(\achbotMessage('manual_payment.automatic_receipts.message.cca1075329', ['remark' => $remark]),null,null,$user_id);
            } else {
                sendMessage(\achbotMessage('manual_payment.automatic_receipts.message.16c2ec4018'),null,null,$user_id);
        	}
        }
        
        $autoServiceLabel=($autoServiceCompleted??true)
            ? \achbotMessage('manual_payment.automatic_receipts.text.2898f8f112')
            : \achbotMessage('manual_payment.automatic_receipts.text.0f42c8d853');
        if (($autoServiceCompleted??true)===false) {
            sendMessage(\achbotMessage('manual_payment.automatic_receipts.message.1b315333c8')
                .\achbotMessage('manual_payment.automatic_receipts.message.ba9287d8c1').(int)$rowId.$bankReviewContext.\achbotMessage('manual_payment.automatic_receipts.message.4624bd3d02'),
                null,null,$admin);
        }
        // دکمه مدیر نباید قبل از اعمال سرویس، موفقیت را اعلام کند.
        editKeys(json_encode(['inline_keyboard' => [[['text' => $autoServiceLabel, 'callback_data' => "wizwizch"]]]]), $payInfo['message_id'], $payInfo['chat_id']);

		// ✅ ویرایش پیام سایر ادمین ها (در صورت وجود)
		if (!empty($payInfo['admins_messages'])) {
		$adminMessages = json_decode($payInfo['admins_messages'], true);

			if (is_array($adminMessages)) {
			foreach ($adminMessages as $msgInfo) {
				if (
					isset($msgInfo['chat_id'], $msgInfo['message_id']) &&
					$msgInfo['chat_id'] != $payInfo['chat_id'] &&
					achbotAdminRecipientAllowed($connection,$msgInfo['chat_id'],(int)$admin) // جلوگیری از تکرار برای ادمین اصلی
				) 	{
					editKeys(json_encode(['inline_keyboard' => [[['text' => $autoServiceLabel, 'callback_data' => "wizwizch"]]]]),
                    $msgInfo['message_id'],
                    $msgInfo['chat_id']);
					}
				}
			}
		}

        } catch (Throwable $error) {
            $event=($autoServiceCompleted===true)
                ? 'achbot_auto_receipt_notice_failed'
                : 'achbot_auto_receipt_effect_review';
            error_log($event.' pay_id='.(int)$rowId.
                ' class='.get_class($error).' code='.(int)$error->getCode());
            // Only a paid BUY_SUB whose fulfillment was not committed needs
            // explicit customer/operator review, never a false refund notice.
            if ($payType==='BUY_SUB' && $autoServiceCompleted!==true) {
                try {
                    sendMessage(\achbotMessage('manual_payment.automatic_receipts.message.39254abe63'),null,null,$user_id);
                    sendMessage(\achbotMessage('manual_payment.automatic_receipts.message.8c2e6c4f2e').(int)$rowId.$bankReviewContext.\achbotMessage('manual_payment.automatic_receipts.message.52406798d0'),null,null,$admin);
                } catch (Throwable $noticeError) {
                    error_log('achbot_bank_buy_review_notice_failed pay_id='.(int)$rowId);
                }
            }
            // Post-COMMIT Telegram failure never repeats provisioning.
            // Any unknown remote or local outcome remains durably claimed.
    }
}
}
