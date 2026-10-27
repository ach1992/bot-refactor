<?php
/** AChBot V8: source-pinned financial compatibility module. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

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
            ? "\n👤 شناسه کاربر: ".(int)$user_id
                ."\n💵 مبلغ سفارش: ".number_format((int)$price)." تومان"
                ."\n📦 تعداد اشتراک: ".max(1,(int)($payInfo['agent_count']??1))
            : '';
        try {
        
        if($payType == "INCREASE_WALLET"){
            // Wallet credit and the terminal claim already committed atomically.
            sendMessage("افزایش حساب شما با موفقیت تایید شد.\n✅ مبلغ " . number_format($price). " تومان به حساب شما اضافه شد.", null, null, $user_id);
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
                        sendMessage("⚠️ سفارش ثبت و سرویس ساخته شده، ولی تحویل مشخصات به کاربر قطعی نیست.\n"
                            ."شناسه پرداخت: ".(int)$rowId.$bankReviewContext
                            ."\nردیف‌های نیازمند بررسی: ".implode(', ',$unconfirmedDeliveries)
                            ."\nفقط تحویل اشتراک ثبت‌شده را بررسی کنید؛ سرویس جدید نسازید و بازپرداخت خودکار نکنید.",
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
                sendMessage("⚠️ پرداخت ثبت شده است، اما تکمیل ساخت اشتراک هنوز قطعی نیست. "
                    ."برای بررسی با پشتیبانی تماس بگیرید؛ خرید را تکرار نکنید.",
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
                sendMessage("✅ اشتراک $remark با موفقیت تمدید شد.",getMainKeys(),null,$user_id);
            } else {
                // Bank receipt and possible remote effect stay durably claimed.
                // No false success, second remote write or blind wallet refund.
                sendMessage("⚠️ نتیجهٔ تمدید هنوز قطعی نیست. پرداخت ثبت شده و سرویس نیازمند تطبیق است؛ پرداخت یا تمدید را تکرار نکنید.",null,null,$user_id);
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
            $volume = $res['volume'];
        
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
                sendMessage("✅ $volume روز به مدت اشتراک شما اضافه شد.",getMainKeys(),null,$user_id);
            } else {
                sendMessage("⚠️ نتیجهٔ افزایش روز هنوز قطعی نیست. پرداخت محفوظ است؛ درخواست را تکرار نکنید.",null,null,$user_id);
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
            $volume = $res['volume'];
            
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
                sendMessage("✅ $volume گیگ به حجم اشتراک شما اضافه شد.",getMainKeys(),null,$user_id);
            } else {
                sendMessage("⚠️ نتیجهٔ افزایش حجم هنوز قطعی نیست. پرداخت محفوظ است؛ درخواست را تکرار نکنید.",null,null,$user_id);
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
                sendMessage("✅ اشتراک $remark با موفقیت تمدید شد.",null,null,$user_id);
            } else {
                sendMessage("⚠️ نتیجهٔ تمدید هنوز قطعی نیست. وضعیت پرداخت محفوظ است؛ درخواست را تکرار نکنید.",null,null,$user_id);
        	}
        }
        
        $autoServiceLabel=($autoServiceCompleted??true)
            ? '✅ خودکار تأیید و اعمال شد'
            : '⚠️ در انتظار تطبیق سرویس';
        if (($autoServiceCompleted??true)===false) {
            sendMessage("⚠️ تأیید رسید ثبت شده، اما تغییر واقعی سرویس یا ثبت نهایی سفارش قطعی نیست.\n"
                ."شناسه پرداخت: ".(int)$rowId.$bankReviewContext."\nبدون تطبیق سرویس، تمدید یا بازپرداخت را تکرار نکنید.",
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
					$msgInfo['chat_id'] != $payInfo['chat_id'] // جلوگیری از تکرار برای ادمین اصلی
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
                    sendMessage('⚠️ پرداخت شما محفوظ است، اما نتیجهٔ ساخت اشتراک قطعی نیست. برای بررسی با پشتیبانی تماس بگیرید؛ پرداخت را تکرار نکنید.',null,null,$user_id);
                    sendMessage("⚠️ ساخت اشتراک با رسید بانکی نیازمند تطبیق است.\nشناسه پرداخت: ".(int)$rowId.$bankReviewContext."\nاز ساخت مجدد یا بازپرداخت خودکار تا بررسی شواهد خودداری کنید.",null,null,$admin);
                } catch (Throwable $noticeError) {
                    error_log('achbot_bank_buy_review_notice_failed pay_id='.(int)$rowId);
                }
            }
            // Post-COMMIT Telegram failure never repeats provisioning.
            // Any unknown remote or local outcome remains durably claimed.
    }
}
}
