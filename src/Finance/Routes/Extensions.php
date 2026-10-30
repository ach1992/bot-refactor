<?php
/** AChBot V8: source-pinned financial compatibility module. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../../messages/loader.php'; // ACHBOT_TEXT_CATALOG

require_once __DIR__ . '/../../Admin/Access.php';
if (!achbotAdminRequestAllowed(is_string($data??null)?$data:null,is_string($text??null)?$text:null,(string)($userInfo['step']??''))) {achbotAdminDeny();return;}


require_once __DIR__ . '/../PaymentLifecycle.php';

if(preg_match('/increaseADay(.*)/', $data, $match)){
    $stmt = $connection->prepare("SELECT * FROM `increase_day`");
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $orderInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$orderInfo || (int)$orderInfo['userid']!==(int)$from_id || !achbotSupportsOrder($connection, (int)$orderInfo['id'])) {
        alert(\achbotMessage('renewal.extensions.alert.d0779ad27d'), true);
        return;
    }

    if (!achbotOrderAddonEligibility($connection,$orderInfo,'day')['eligible']) {
        alert(\achbotMessage('payment.pre_payment.alert.fa55f5201f'),true);
        return;
    }

    $agentBought = $orderInfo['agent_bought'];

    if($res->num_rows == 0){
        alert(\achbotMessage('renewal.extensions.alert.6732b29e55'));
        exit;
    }
    $keyboard = [];
    while ($cat = $res->fetch_assoc()){
        if (achbotWholeServiceUnits($cat['volume'],36500)===null) continue;
        $id = $cat['id'];
        $title = $cat['volume'];
        $price = $cat['price'];
        if($agentBought == true){
            $discounts = json_decode($userInfo['discount_percent'],true);
            if($botState['agencyPlanDiscount']=="on") $discount = $discounts['plans'][$orderInfo['fileid']]?? $discounts['normal'];
            else $discount = $discounts['servers'][$orderInfo['server_id']]?? $discounts['normal'];
            $price -= floor($price * $discount / 100);
        }
        if($price == 0) $price = \achbotMessage('renewal.extensions.price.dda7ff2974');
        else $price = number_format($price) . \achbotMessage('renewal.extensions.text.2cc98f7f7c');
        $keyboard[] = ['text' => \achbotMessage('renewal.extensions.button.fbb9f61105', ['title' => $title, 'price' => $price]), 'callback_data' => "selectPlanDayIncrease{$match[1]}_$id"];
    }
    $keyboard = array_chunk($keyboard,2);
    $keyboard[] = [['text' => $buttonValues['back_to_main'], 'callback_data' => "mainMenu"]];
    editText($message_id, \achbotMessage('renewal.extensions.message.c2bde2a3e0'), json_encode([
            'inline_keyboard' => $keyboard
        ]));
}
if(preg_match('/selectPlanDayIncrease(?<orderId>.+)_(?<dayId>.+)/',$data,$match)){
    $data = str_replace('selectPlanDayIncrease','',$data);
    $pid = $match['dayId'];
    $stmt = $connection->prepare("SELECT * FROM `increase_day` WHERE `id` = ?");
    $stmt->bind_param("i", $pid);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $quotedUnits=$res?achbotWholeServiceUnits($res['volume'],36500):null;
    if ($quotedUnits===null) {alert(\achbotMessage('renewal.extensions.alert.584426a8a8'),true);return;}
    $planprice = $res['price'];

    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $match['orderId']);
    $stmt->execute();
    $orderInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$orderInfo || (int)$orderInfo['userid']!==(int)$from_id || !achbotSupportsOrder($connection, (int)$orderInfo['id'])) {
        alert(\achbotMessage('renewal.extensions.alert.d0779ad27d'), true);
        return;
    }

    if (!achbotOrderAddonEligibility($connection,$orderInfo,'day')['eligible']) {
        alert(\achbotMessage('payment.pre_payment.alert.fa55f5201f'),true);
        return;
    }

    $agentBought = $orderInfo['agent_bought'];

    if($agentBought == true){
        $discounts = json_decode($userInfo['discount_percent'],true);
        if($botState['agencyPlanDiscount']=="on") $discount = $discounts['plans'][$orderInfo['fileid']]?? $discounts['normal'];
        else $discount = $discounts['servers'][$orderInfo['server_id']]?? $discounts['normal'];

        $planprice -= floor($planprice * $discount / 100);
    }

    
    $hash_id = RandomString();
    achbotRemoveUnclaimedPendingPayments($connection,$from_id,'INCREASE_DAY');
	
    $time = time();
    $plan_id = $orderInfo['fileid'];
	$server_id = $orderInfo['server_id'];
	$order_id = $match['orderId'];

	$stmt = $connection->prepare("INSERT INTO `pays` (`hash_id`, `user_id`, `type`, `plan_id`, `server_id`, `order_id`, `volume`, `day`, `price`, `request_date`, `state`)
								VALUES (?, ?, ?, ?, ?, ?, '0', ?, ?, ?, 'pending')");
    $type = "INCREASE_DAY_$data";
	$stmt->bind_param("sisiiiiii", $hash_id, $from_id, $type, $plan_id, $server_id, $order_id, $quotedUnits, $planprice, $time);
    $newDraftId=achbotPayUxInsertDraft($connection,(int)$from_id,$stmt);
    $stmt->close();
    if ($newDraftId<=0) {
        alert(\achbotMessage('renewal.extensions.alert.ae271a2d1d'),true);
        return;
    }


    $keyboard = array();
    if(achbotManualEnabled('bank_transfer') && achbotPayUxAudienceAllowed($connection,'bank_transfer',(int)$from_id)) $keyboard[] = [['text' => $buttonValues['cart_to_cart'],  'callback_data' => "payIncreaseDayWithCartToCart$hash_id"]];
    if(achbotManualEnabled('gift_card') && achbotPayUxAudienceAllowed($connection,'gift_card',(int)$from_id)) $keyboard[] = [['text' => $buttonValues['gift_card'], 'callback_data' => "payIncreaseDayWithGiftCard$hash_id"]];
    if (achbotManualCryptoEnabled((int)$from_id) && (achbotPayUxAudienceAllowed($connection,'manual_usdt',(int)$from_id) || achbotPayUxAudienceAllowed($connection,'manual_trx',(int)$from_id))) $keyboard[] = [['text' => \achbotMessage('renewal.extensions.button.e729457ba2'), 'callback_data' => "mcm_$hash_id"]];
    if(($botState['nowPaymentOther'] == "on" && achbotPayUxAudienceAllowed($connection,'nowpayments',(int)$from_id) && achbotUsdtRateForUser($connection,(int)$from_id)!==null)) $keyboard[] = [['text' => $buttonValues['now_payment_gateway'],  'url' => $botUrl . "pay/checkout.php?nowpayment&hash_id=" . $hash_id]];
    if(($botState['zarinpal'] == "on" && achbotPayUxAudienceAllowed($connection,'zarinpal',(int)$from_id))) $keyboard[] = [['text' => $buttonValues['zarinpal_gateway'],  'url' => $botUrl . "pay/checkout.php?zarinpal&hash_id=" . $hash_id]];
    if(($botState['nextpay'] == "on" && achbotPayUxAudienceAllowed($connection,'nextpay',(int)$from_id))) $keyboard[] = [['text' => $buttonValues['nextpay_gateway'],  'url' => $botUrl . "pay/checkout.php?nextpay&hash_id=" . $hash_id]];
    if(($botState['walletState'] == "on" && achbotPayUxAudienceAllowed($connection,'wallet',(int)$from_id))) $keyboard[] = [['text' => $buttonValues['pay_with_wallet'],  'callback_data' => "payIncreaseDayWithWallet$hash_id"]];

    $keyboard[] = [['text'=>$buttonValues['cancel'], 'callback_data'=> "mainMenu"]];
    $sent=sendMessage(\achbotMessage('renewal.extensions.message.48930749a9'),
        json_encode(['inline_keyboard'=>$keyboard]),'HTML',$from_id);
    require_once __DIR__ . '/../PaymentLifecycle.php';
    achbotPayUxTrackByHash($connection,(string)$hash_id,(int)$from_id,$sent);
}

if (is_string($data) && (strlen($data) <= 64 || ($GLOBALS['achbotManualCryptoVerifiedDispatch'] ?? null) === $data) && preg_match('/\ApayIncreaseDayWith(?:CartToCart|GiftCard|ManualUsdt)([A-Za-z0-9_-]{1,50})\z/D', $data, $match)) {

    $payInfo = dbFetchPayByHash($connection, $match[1]);
    if (!$payInfo) {
        prepareStartFallback();
        delMessage();
        return '__ACHBOT_V8_GOTO_START__';
    }

    $method = str_starts_with($data, 'payIncreaseDayWithManualUsdt') ? (($GLOBALS['achbotManualCryptoChoice'] ?? '') === 'TRON' ? 'manual_trx' : 'manual_usdt') :
        (str_starts_with($data, 'payIncreaseDayWithGiftCard') ? 'gift_card' : 'bank_transfer');
    if (!achbotManualClaim($connection, $payInfo, $method, (int)$from_id,
                           $GLOBALS['achbotManualCryptoChoice'] ?? null)) {
        alert(\achbotMessage('renewal.extensions.alert.1a885aaca4'), true);
        return;
    }

	$msg = buildCartToCartInfoMessage($paymentKeys, $mainValues, (int)$payInfo['price'], (int)$payInfo['user_id'], $method, (string)$payInfo['hash_id']);
    if ($msg === "") {
        alert(\achbotMessage('renewal.extensions.alert.07ee06b4bc'), true);
        return;
    }
    $keyboard = kbSendReceiptAndCancel(in_array($method, ['manual_usdt','manual_trx'], true) ? "sendIncreaseDayUsdtReceipt" : ($method === 'gift_card' ? "sendIncreaseDayGiftReceipt" : "sendIncreaseDayReceipt"), "cancelIncreaseDayReceipt", $match[1], $method);

    $delivered = sendMessage($msg, $keyboard, "HTML");
    require_once __DIR__ . '/../PaymentLifecycle.php';
    achbotPayUxTrackByHash($connection,(string)$payInfo['hash_id'],(int)$from_id,$delivered);
    if (function_exists('achbotApiPaymentTrackInvoice')) achbotApiPaymentTrackInvoice($connection,(string)$payInfo['hash_id'],(int)$from_id,$delivered);
    if (is_object($delivered) && !empty($delivered->ok)) {
    setUser(function_exists('achbotApiPaymentInitialStep') ? achbotApiPaymentInitialStep($connection,(string)$match[1],"payIncreaseDayWith" . (in_array($method, ['manual_usdt','manual_trx'], true) ? 'ManualUsdt' : ($method === 'gift_card' ? 'GiftCard' : 'CartToCart')) . $match[1]) : "payIncreaseDayWith" . (in_array($method, ['manual_usdt','manual_trx'], true) ? 'ManualUsdt' : ($method === 'gift_card' ? 'GiftCard' : 'CartToCart')) . $match[1]);
        delMessage();
    } else {
        // The pinned attempt may be reopened. Never erase the selection menu
        // if Telegram failed to deliver the replacement instructions.
        alert(\achbotMessage('renewal.extensions.alert.7a9c30e938'), true);
    }
    exit;
}

if (preg_match('/send(?:IncreaseDay|IncreaseDayGift|IncreaseDayUsdt)Receipt(?<hashId>.*)/', $data, $match)) {

    $payInfo = dbFetchPayByHash($connection, $match['hashId']);
    if (!$payInfo) {
        prepareStartFallback();
        delMessage();
        return '__ACHBOT_V8_GOTO_START__';
    }

    $method = strpos($data, 'UsdtReceipt') !== false ? achbotManualCryptoReceiptProviderByHash($connection, (string)$match['hashId']) :
        (strpos($data, 'GiftReceipt') !== false ? 'gift_card' : 'bank_transfer');
    if (!achbotManualReceiptAllowed($connection, $payInfo, $method, (int)$from_id)) {
        alert(\achbotMessage('renewal.extensions.alert.d06bc731eb'), true);
        return;
    }
    $guardMsg = duplicateReceiptGuard($connection, $payInfo, time());
    if ($guardMsg) { alert($guardMsg, true); exit; }

    // Explicit user intent to send a receipt, not a claim of paid funds.
    // Serialize with the six-hour unstarted-invoice expiry sweep.
    require_once __DIR__ . '/../PaymentLifecycle.php';
    if (!achbotPayUxStartManualReceipt($connection,(int)$payInfo['id'],
                                      $method,(int)$from_id)) {
        alert(\achbotMessage('renewal.extensions.alert.560f05f822'),true);
        return;
    }

    delMessage();
    setUser((in_array($method, ['manual_usdt','manual_trx'], true) ? "waitIncreaseDayUsdtReceipt" : ($method === 'gift_card' ? "waitIncreaseDayGiftReceipt" : "waitIncreaseDayReceipt")) . $match['hashId']);

    $keyboard = kbOnlyCancel("cancelIncreaseDayReceipt", $match['hashId']);
    $sent=sendMessage(achbotManualEvidencePrompt($mainValues,$connection,$payInfo,$method), $keyboard, "HTML");
    require_once __DIR__ . '/../PaymentLifecycle.php';
    achbotPayUxTrackByHash($connection,(string)$match['hashId'],(int)$from_id,$sent);
    exit;
}

if (preg_match('/cancelIncreaseDayReceipt(?<hashId>.*)/', $data, $match)) {
    handleCancelFlow($mainValues, $removeKeyboard);
}

if (preg_match('/wait(?:IncreaseDay|IncreaseDayGift|IncreaseDayUsdt)Receipt(.*)/', $userInfo['step'], $match)) {

    $method = strpos($userInfo['step'], 'UsdtReceipt') !== false ? achbotManualCryptoReceiptProviderByHash($connection, (string)$match[1]) :
        (strpos($userInfo['step'], 'GiftReceipt') !== false ? 'gift_card' : 'bank_transfer');
    $receiptPay = dbFetchPayByHash($connection, $match[1]);
    if (!$receiptPay || !achbotManualReceiptAllowed($connection, $receiptPay, $method, (int)$from_id)) {
        setUser();
        alert(\achbotMessage('renewal.extensions.alert.882596d0f1'), true);
        return;
    }
    $fileid = achbotManualEvidenceInput($update, $connection, $receiptPay, $method);
    if (!$fileid) {
        $keyboard = kbOnlyCancel("cancelIncreaseDayReceipt", $match[1]);
        sendMessage(achbotManualEvidencePrompt($mainValues,$connection,$receiptPay,$method,true), $keyboard, "HTML");
        exit;
    }

    $deliveryToken = achbotManualBeginEvidence($connection, $receiptPay, $method, (int)$from_id, $fileid);
    if ($deliveryToken === null) {
        alert(\achbotMessage('renewal.extensions.alert.795c4bddb8'), true);
        return;
    }

    $payInfoRes = dbFetchPayByHash($connection, $match[1]);
    if (!$payInfoRes) {
        prepareStartFallback();
        delMessage();
        return '__ACHBOT_V8_GOTO_START__';
    }

    $payType = $payInfoRes['type'];

    preg_match('/^INCREASE_DAY_(\d+)_(\d+)/', $payType, $increaseInfo);
    $orderId = (int)$increaseInfo[1];
    $planid  = (int)$increaseInfo[2];

    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $orderInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$orderInfo || !achbotSupportsOrder($connection, (int)$orderInfo['id'])) {
        alert(\achbotMessage('renewal.extensions.alert.d0779ad27d'), true);
        return;
    }


    $remark = $orderInfo['remark'];

    $stmt = $connection->prepare("SELECT * FROM `increase_day` WHERE `id` = ?");
    $stmt->bind_param("i", $planid);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $price  = $payInfoRes['price'];
    $volume = achbotPinnedAddonUnits($payInfoRes);



    $msg = str_replace(
        ['INCREASE', 'TYPE', "USER-ID", "USERNAME", "NAME", "PRICE", "REMARK"],
        [$volume, \achbotMessage('renewal.extensions.text.9699c34220'), $from_id, $username, $first_name, $price, $remark],
        achbotManualCaption($connection, $receiptPay, $mainValues['increase_account_request_message'])
    );

    $adminKeyboard = buildAdminKeyboardWithStats(
        $connection,
        $buttonValues,
        "approveIncreaseDay{$match[1]}",
        "declineAction_{$match[1]}",
        $match[1],
        (int)$payInfoRes['user_id']
    );

    if (sendReceiptToAdminsAndUpdatePay($connection, $fileid,
        $msg, $adminKeyboard, $admin, $match[1],
        $deliveryToken)) {
        setUser();
        sendWithStartButton(achbotManualAcknowledgement($receiptPay, $method), $buttonValues['start_bot'], $botUsername, 'mainMenu', 'HTML', (string)$receiptPay['hash_id']);
    } else {
        sendMessage(\achbotMessage('renewal.extensions.message.c346a0d62e'));
    }
    exit;
}

if (preg_match('/^approveIncreaseDay(.*)/', $data, $match) && achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'payments')) {
    $apiManualSeen=function_exists('achbotApiPaymentManualAddonPreflight') ? achbotApiPaymentManualAddonPreflight($connection,(string)$match[1]) : null;
    $connection->begin_transaction();
    $localCommitted=false;
    try {
        $hash_id = $match[1];

        $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? FOR UPDATE");
        $stmt->bind_param("s", $hash_id);
        $stmt->execute();
        $payParam = $stmt->get_result()->fetch_assoc();
        if (!(function_exists('achbotApiPaymentManualAddonAllowedLocked') ? achbotApiPaymentManualAddonAllowedLocked($connection,$payParam,$apiManualSeen) : achbotSupportsPaymentSubject($connection, $payParam, true))) {
            $connection->rollback();
            alert(\achbotMessage('renewal.extensions.alert.135aa8877f'), true);
            return;
        }

        $stmt->close();

        if (!achbotManualServiceApprovalAllowedLocked($connection, $payParam, 'day')) {
            $connection->rollback();
            alert(\achbotMessage('renewal.extensions.alert.7a08f2df0b'), true);
            return;
        }

        $payType = $payParam['type'];

        if (!preg_match('/^INCREASE_DAY_(\d+)_(\d+)/', $payType, $increaseInfo)) {
            throw new Exception("invalid_increase_type");
        }
        $orderId = intval($increaseInfo[1]);
        $planid  = intval($increaseInfo[2]);
        $uid     = intval($payParam['user_id']);

        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
        $stmt->bind_param("i", $orderId);
        $stmt->execute();
        $orderInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$orderInfo || !achbotSupportsOrder($connection, (int)$orderInfo['id'])) {
        throw new RuntimeException('day_extension_order_ineligible');
    }


        if (!$orderInfo) {
            throw new Exception("order_not_found");
        }

        $server_id  = intval($orderInfo['server_id']);
        $inbound_id = intval($orderInfo['inbound_id']);
        $remark     = $orderInfo['remark'];
        $uuid       = $orderInfo['uuid'] ?? "0";

        $quotedUnits=achbotPinnedAddonUnits($payParam);
        if ($quotedUnits===null) throw new RuntimeException('service_quote_units_unverified');
        $price  = intval($payParam['price']);
        $volume = $quotedUnits;
        if ($volume===null) throw new RuntimeException('unsupported_service_units');

        $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $server_info = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$server_info) {
            throw new Exception("server_config_missing");
        }

        $serverType = $server_info['type'];

        if (!achbotSupportsPanel($serverType))
            throw new UnexpectedValueException('unsupported_panel');
        // Operator receipt approval is financial ownership, not a remote
        // effect claim. Commit a unique nonterminal claim BEFORE the PUT.
        // An ambiguous crash/SQL reply can leave review pending, never a
        // second automatic remote request on this same receipt.
        if ((int)$payParam['price']!==$price ||
            (int)$payParam['user_id']!==$uid ||
            (string)$payParam['type']!=="INCREASE_DAY_{$orderId}_{$planid}")
            throw new RuntimeException('day_extension_claim_subject_changed');
        $payId=(int)$payParam['id'];
        $payType=(string)$payParam['type'];
        $stmt=$connection->prepare("UPDATE pays SET state='processing'
            WHERE id=? AND user_id=? AND hash_id=? AND type=?
              AND price=? AND state='have_sent'");
        $stmt->bind_param('iissi',$payId,$uid,$hash_id,$payType,$price);
        $claimed=$stmt->execute() && $stmt->affected_rows===1;
        $stmt->close();
        if (!$claimed || $connection->commit()!==true)
            throw new RuntimeException('day_extension_claim_unconfirmed');

        unset($markup[count($markup)-1]);

        if (achbotSupportsPanel($serverType)) {
            $response = achbotApplyServiceEdit($connection,$server_id, ['pay_id'=>(int)$payId, 'remark' => $remark, 'plus_day' => $volume]);
        } else {
            throw new \UnexpectedValueException('unsupported_panel');
        }

        if (is_null($response)) {
            throw new Exception("server_connection_null");
        }
        if (!is_object($response) && !is_array($response)) {
            throw new Exception("invalid_response_type");
        }
        $success = (is_object($response) && !empty($response->success))
                || (is_array($response)  && !empty($response['success']));
        if (!$success) {
            throw new Exception("provider_rejected");
        }

        if (!is_object($response) || ($response->verified??false)!==true)
            throw new RuntimeException('day_extension_provider_result_unverified');
        if ($connection->begin_transaction()!==true)
            throw new RuntimeException('day_extension_finalization_begin_failed');
        $stmt=$connection->prepare("SELECT id,state,user_id,hash_id,type,price
            FROM pays WHERE id=? FOR UPDATE");
        $stmt->bind_param('i',$payId);
        $ok=$stmt->execute();
        $locked=$ok?$stmt->get_result()->fetch_assoc():null;
        $stmt->close();
        if (!$locked || $locked['state']!=='processing' ||
            (int)$locked['user_id']!==$uid ||
            (int)$locked['price']!==$price ||
            (string)$locked['hash_id']!==$hash_id ||
            (string)$locked['type']!==$payType)
            throw new RuntimeException('day_extension_claim_stale');
        achbotStoreVerifiedServiceExpiryLocked($connection,$orderInfo,$response);
        $timeNow=time();
        $stmt=$connection->prepare("INSERT INTO increase_order
            (userid,server_id,inbound_id,remark,amount,date,day)
            VALUES (?,?,?,?,?,?,?)");
        $stmt->bind_param('iiisiii',$uid,$server_id,$inbound_id,
                          $remark,$price,$timeNow,$volume);
        $saved=$stmt->execute() && $stmt->affected_rows===1;
        $stmt->close();
        if (!$saved) throw new RuntimeException('day_extension_audit_unconfirmed');
        $stmt=$connection->prepare("UPDATE pays SET state='approved',approved_by=?
            WHERE id=? AND user_id=? AND hash_id=? AND state='processing'");
        $stmt->bind_param('iiis',$from_id,$payId,$uid,$hash_id);
        $saved=$stmt->execute() && $stmt->affected_rows===1;
        $stmt->close();
        if (!$saved || $connection->commit()!==true)
            throw new RuntimeException('day_extension_finish_unconfirmed');
        $localCommitted=true;

        $keys = json_encode([
            'inline_keyboard' => [
                [['text' => \achbotMessage('renewal.extensions.button.139c829462', ['from_id' => $from_id]), 'callback_data' => "wizwizch"]],
                [['text' => \achbotMessage('renewal.extensions.hash_id.2899228604', ['hash_id' => $hash_id]), 'callback_data' => "noop_$hash_id"]]
            ]
        ], 488);

        // ویرایش پیام ادمین اصلی
        editKeys($keys, $payParam['message_id'], $payParam['chat_id']);

        // ویرایش پیام سایر ادمین‌ها
        $adminMessages = json_decode($payParam['admins_messages'], true);
        if (is_array($adminMessages)) {
            foreach ($adminMessages as $msgInfo) {
                if (isset($msgInfo['chat_id'], $msgInfo['message_id']) && $msgInfo['chat_id'] != $payParam['chat_id'] &&
                    achbotAdminStoredRecipientAllowed($connection,$msgInfo['chat_id'],(int)$admin)) {
                    editKeys($keys, $msgInfo['message_id'], $msgInfo['chat_id']);
                }
            }
        }

        sendMessage(\achbotMessage('renewal.extensions.message.24dea9b122', ['volume' => $volume, 'remark' => $remark]), null, "HTML", $uid);
        return;

    } catch (Throwable $e) {
        if ($localCommitted) {
            error_log('achbot_manual_day_notice_unconfirmed pay_id='.(int)($payId??0));
            return;
        }
        try {$connection->rollback();} catch (Throwable $ignored) {}

        // پیام فنی برای ادمین اقدام‌کننده
        $err = \achbotMessage('renewal.extensions.err.28163d77c1')
             . \achbotMessage('renewal.extensions.hash__i_d_match_1.3abb834966', ['match_1' => $match[1]])
             . \achbotMessage('renewal.extensions.admin_from_id.748897de00', ['from_id' => $from_id])
             . \achbotMessage('renewal.extensions.external_effect_unverified_verify_before.10e2ccf8a3') . achbotFinanceDiagnosticReason($e);
        achbotNotifyAdmins('sendMessage', ['text'=>$err], ['report_channel'=>null]);

        alert(\achbotMessage('renewal.extensions.alert.ba92f03500'), true);
        return;
    }
}

if(is_string($data) && strlen($data) <= 64 && preg_match('/\ApayIncreaseDayWithWallet([A-Za-z0-9_-]{1,50})\z/D', $data, $match)){
    $connection->begin_transaction();
    $localCommitted=false;
    try {
        // Customer-wallet issuance shares the same owner-first lock as drafts
        // and manual/hosted claims. Never lock pays before users.userid.
        achbotPayUxLockDraftOwner($connection,(int)$from_id);
        $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? FOR UPDATE");
        $stmt->bind_param("s", $match[1]);
        $stmt->execute();
        $payRes = $stmt->get_result();
        $stmt->close();

        if($payRes->num_rows == 0){
            $connection->rollback();
            $text = "/start";
            $data = "";
            delMessage();
            return '__ACHBOT_V8_GOTO_START__';
        }

        $payParam = $payRes->fetch_assoc();
        // Same guarded claim policy as purchase wallet callbacks, while
        // this exact pays row is already locked in the current transaction.
        if (!achbotWalletDirectAllowedLocked($connection, $payParam, (int)$from_id)) {
            $connection->rollback();
            alert(\achbotMessage('renewal.extensions.alert.9b6f1fa91f'), true);
            return;
        }

        if (!achbotSupportsPaymentSubject($connection, $payParam, true)) {
            $connection->rollback();
            alert(\achbotMessage('renewal.extensions.alert.31d13e9866'), true);
            return;
        }

        $hash_id  = $payParam['hash_id'];

        if ($payParam['state'] === "paid_with_wallet") {
            $connection->rollback();
            return;
        }

        $payType = $payParam['type'];
        if (!preg_match('/^INCREASE_DAY_(\d+)_(\d+)/', $payType, $increaseInfo)) {
            throw new Exception("invalid_increase_type");
        }
        $orderId = intval($increaseInfo[1]);
        $planid  = intval($increaseInfo[2]);
        $uid     = intval($payParam['user_id'] ?? $from_id);

        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
        $stmt->bind_param("i", $orderId);
        $stmt->execute();
        $orderInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$orderInfo || !achbotSupportsOrder($connection, (int)$orderInfo['id'])) {
        throw new RuntimeException('wallet_day_order_ineligible');
    }


        if (!$orderInfo){
            throw new Exception("order_not_found");
        }

        $server_id  = intval($orderInfo['server_id']);
        $inbound_id = intval($orderInfo['inbound_id']);
        $remark     = $orderInfo['remark'];
        $uuid       = $orderInfo['uuid'] ?? "0";

        $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id` = ?");
        $stmt->bind_param('i', $server_id);
        $stmt->execute();
        $serverConfig = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if(!$serverConfig){
            throw new Exception("server_config_missing");
        }
        $serverType = $serverConfig['type'];

        $quotedUnits=achbotPinnedAddonUnits($payParam);
        if ($quotedUnits===null) throw new RuntimeException('service_quote_units_unverified');
        $price  = intval($payParam['price']);
        $volume = $quotedUnits;
        if ($volume===null) throw new RuntimeException('unsupported_service_units');

        $stmt = $connection->prepare("SELECT id,wallet FROM users
            WHERE userid=? FOR UPDATE");
        $stmt->bind_param('i',$uid);
        if (!$stmt->execute()) throw new RuntimeException('wallet_day_owner_read_failed');
        $owners=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if (count($owners)!==1)
            throw new RuntimeException('wallet_day_owner_ambiguous');
        $uRow=$owners[0];
        $userwallet=(int)$uRow['wallet'];
        if($userwallet < $price) {
            $needamount = $price - $userwallet;

            $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'low_wallet' WHERE `hash_id` = ?");
            $stmt->bind_param("s", $hash_id);
            $stmt->execute();
            $stmt->close();

            $connection->commit();

            alert(\achbotMessage('renewal.extensions.alert.39c72d8044') . number_format($userwallet) . \achbotMessage('renewal.extensions.alert.71a2507661') . number_format($needamount) . \achbotMessage('renewal.extensions.text.2cc98f7f7c'), true);
            return;
        }

        if (!achbotSupportsPanel($serverType))
            throw new UnexpectedValueException('unsupported_panel');
        // Reserve the debit and unique payment ownership together BEFORE
        // dispatch. An unknown remote/local result remains processing_wallet
        // for operator review; callback replay cannot debit or dispatch again.
        $payId=(int)$payParam['id'];
        $payType=(string)$payParam['type'];
        if ((int)$payParam['price']!==$price ||
            (int)$payParam['user_id']!==$uid ||
            $payType!=="INCREASE_DAY_{$orderId}_{$planid}")
            throw new RuntimeException('wallet_day_claim_subject_changed');
        $ownerPk=(int)$uRow['id'];
        $stmt=$connection->prepare("UPDATE users SET wallet=wallet-?
            WHERE id=? AND userid=? AND wallet>=?");
        $stmt->bind_param('iiii',$price,$ownerPk,$uid,$price);
        $reserved=$stmt->execute() && $stmt->affected_rows===1;
        $stmt->close();
        if (!$reserved) throw new RuntimeException('wallet_day_reservation_unconfirmed');
        $payState=(string)$payParam['state'];
        $stmt=$connection->prepare("UPDATE pays SET state='processing_wallet'
            WHERE id=? AND user_id=? AND hash_id=? AND type=? AND price=?
              AND state=?");
        $stmt->bind_param('iissis',$payId,$uid,$hash_id,$payType,
                          $price,$payState);
        $claimed=$stmt->execute() && $stmt->affected_rows===1;
        $stmt->close();
        if (!$claimed || $connection->commit()!==true)
            throw new RuntimeException('wallet_day_claim_unconfirmed');

        if (achbotSupportsPanel($serverType)){
            $response = achbotApplyServiceEdit($connection,$server_id, ['pay_id'=>(int)$payId, 'remark'=>$remark, 'plus_day'=>$volume]);
        } else {
            throw new \UnexpectedValueException('unsupported_panel');
        }

        if (is_null($response)) {
            throw new Exception("server_connection_null");
        }
        if (!is_object($response) && !is_array($response)) {
            throw new Exception("invalid_response_type");
        }
        $success = (is_object($response) && !empty($response->success))
                || (is_array($response)  && !empty($response['success']));
        if (!$success) {
            throw new Exception("provider_rejected");
        }

        if (!is_object($response) || ($response->verified??false)!==true)
            throw new RuntimeException('wallet_day_provider_result_unverified');
        if ($connection->begin_transaction()!==true)
            throw new RuntimeException('wallet_day_finish_begin_failed');
        $stmt=$connection->prepare("SELECT id,state,user_id,hash_id,type,price
            FROM pays WHERE id=? FOR UPDATE");
        $stmt->bind_param('i',$payId);
        $ok=$stmt->execute();
        $locked=$ok?$stmt->get_result()->fetch_assoc():null;
        $stmt->close();
        if (!$locked || $locked['state']!=='processing_wallet' ||
            (int)$locked['user_id']!==$uid ||
            (int)$locked['price']!==$price ||
            (string)$locked['hash_id']!==$hash_id ||
            (string)$locked['type']!==$payType)
            throw new RuntimeException('wallet_day_claim_stale');
        achbotStoreVerifiedServiceExpiryLocked($connection,$orderInfo,$response);
        $timeNow=time();
        $stmt=$connection->prepare("INSERT INTO increase_order
            (userid,server_id,inbound_id,remark,amount,date,day)
            VALUES (?,?,?,?,?,?,?)");
        $stmt->bind_param('iiisiii',$uid,$server_id,$inbound_id,
                          $remark,$price,$timeNow,$volume);
        $saved=$stmt->execute() && $stmt->affected_rows===1;
        $stmt->close();
        if (!$saved) throw new RuntimeException('wallet_day_audit_unconfirmed');
        $stmt=$connection->prepare("UPDATE pays SET state='paid_with_wallet'
            WHERE id=? AND user_id=? AND hash_id=? AND state='processing_wallet'");
        $stmt->bind_param('iis',$payId,$uid,$hash_id);
        $saved=$stmt->execute() && $stmt->affected_rows===1;
        $stmt->close();
        if (!$saved || $connection->commit()!==true)
            throw new RuntimeException('wallet_day_finish_unconfirmed');
        $localCommitted=true;

        editText($message_id, \achbotMessage('renewal.extensions.message.cce260f97e', ['volume' => $volume]), getMainKeys());

        $keys = json_encode(['inline_keyboard'=>[
            [
                ['text'=>\achbotMessage('renewal.extensions.button.aba3054570'),'callback_data'=>"wizwizch"]
            ],
            [
                ['text'=>\achbotMessage('renewal.extensions.hash_id.2899228604', ['hash_id' => $hash_id]), 'callback_data'=>"noop_$hash_id"]
            ]
        ]]);

        $msg = \achbotMessage('renewal.extensions.msg.bfc1b46322', ['from_id' => $from_id, 'first_name' => $first_name, 'username' => $username, 'remark' => $remark, 'volume' => $volume, 'price' => $price]);
        achbotNotifyAdmins('sendMessage', ['text'=>$msg, 'reply_markup'=>$keys, 'parse_mode'=>'HTML']);

        return;

    } catch (Throwable $e) {
        if ($localCommitted) {
            error_log('achbot_wallet_day_notice_unconfirmed pay_id='.(int)($payId??0));
            return;
        }
        try {$connection->rollback();} catch (Throwable $ignored) {}

        // پیام فنی برای ادمین
        $adminErr = \achbotMessage('renewal.extensions.admin_err.70cf66359f')
                  . \achbotMessage('renewal.extensions.hash__i_d_match_1.3abb834966', ['match_1' => $match[1]])
                  . \achbotMessage('renewal.extensions.user_from_id.45aa9ea684', ['from_id' => $from_id])
                  . \achbotMessage('renewal.extensions.external_effect_unverified_verify_before.10e2ccf8a3').achbotFinanceDiagnosticReason($e);
        achbotNotifyAdmins('sendMessage', ['text'=>$adminErr], ['report_channel'=>null]);

        sendMessage(\achbotMessage('renewal.extensions.message.ba8adc7121'), null, null, $uid);

        return;
    }
}

if (is_string($data) && preg_match('/^changePlan_([1-9][0-9]{0,9})$/D', $data, $match)) {
    require_once __DIR__.'/../../Subscription/AccountDetails.php';
    $orderId=(int)$match[1];
    try {
        $actor=\AchBot\Subscription\AccountDetails\currentOrderDetailActor();
        if ($actor===null || $orderId>2147483647) throw new RuntimeException('plan_actor_unavailable');
        $actorId=(int)$actor['userid'];
        $isAdmin=$actorId===(int)$admin || (int)$actor['isAdmin']===1;
        $stmt=$connection->prepare('SELECT * FROM orders_list WHERE id=? AND status=1');
        $stmt->bind_param('i',$orderId);$stmt->execute();
        $orderInfo=$stmt->get_result()->fetch_assoc();$stmt->close();
        if (!$orderInfo || (!$isAdmin && (int)$orderInfo['userid']!==$actorId) ||
            !achbotSupportsOrder($connection,$orderId,null,(int)$orderInfo['userid']))
            throw new RuntimeException('plan_order_unavailable');
        if (!achbotOrderMayUsePaidPlan($connection,$orderInfo)) {
            alert(\achbotMessage('renewal.extensions.alert.65fc393064'),true);return;
        }
        $serverId=(int)$orderInfo['server_id'];
        $stmt=$connection->prepare('SELECT id,title FROM server_plans WHERE server_id=? AND active=1 AND price>0 AND inbound_id=0 ORDER BY id');
        $stmt->bind_param('i',$serverId);$stmt->execute();$plans=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
        $buttons=[];
        foreach ($plans as $plan) {
            $planId=(int)$plan['id'];
            if (\AchBot\Subscription\AccountDetails\paidPlanForOrder($connection,$orderInfo,$planId)===null) continue;
            $buttons[]=[['text'=>$plan['title'],'callback_data'=>'confirmChangePlan_'.$orderId.'_'.$planId]];
        }
        $buttons[]=[['text'=>$buttonValues['back_button'],'callback_data'=>'mainMenu']];
        if (count($buttons)===1) sendMessage(\achbotMessage('renewal.extensions.message.655234a584'));
        else sendMessage(\achbotMessage('renewal.extensions.message.143c7f81f1'),json_encode(['inline_keyboard'=>$buttons]),'HTML');
    } catch (Throwable $error) {
        error_log('achbot_plan_selection_unavailable order_id='.$orderId);
        alert(\achbotMessage('renewal.extensions.plan_selection_unavailable'),true);
    }
    return;
}

if (is_string($data) && preg_match('/^confirmChangePlan_([1-9][0-9]{0,9})_([1-9][0-9]{0,9})$/D', $data, $match)) {
    require_once __DIR__.'/../../Subscription/AccountDetails.php';
    $orderId=(int)$match[1];$newPlanId=(int)$match[2];$begun=false;$commitAttempted=false;
    try {
        $actor=\AchBot\Subscription\AccountDetails\currentOrderDetailActor();
        if ($actor===null || $orderId>2147483647 || $newPlanId>2147483647)
            throw new RuntimeException('plan_actor_unavailable');
        $actorId=(int)$actor['userid'];$actorPk=(int)$actor['id'];
        if (!$connection->begin_transaction()) throw new RuntimeException('plan_begin_failed');
        $begun=true;
        $stmt=$connection->prepare('SELECT * FROM orders_list WHERE id=? AND status=1 FOR UPDATE');
        $stmt->bind_param('i',$orderId);$stmt->execute();
        $orderInfo=$stmt->get_result()->fetch_assoc();$stmt->close();
        $stmt=$connection->prepare('SELECT id,userid,isAdmin,step FROM users WHERE id=? AND userid=? FOR UPDATE');
        $stmt->bind_param('ii',$actorPk,$actorId);$stmt->execute();$freshActor=$stmt->get_result()->fetch_assoc();$stmt->close();
        $isAdmin=$freshActor!==null && ($actorId===(int)$admin || (int)$freshActor['isAdmin']===1);
        if (!$freshActor || $freshActor['step']==='banned' || !$orderInfo ||
            (!$isAdmin && (int)$orderInfo['userid']!==$actorId) ||
            !achbotSupportsOrder($connection,$orderId,null,(int)$orderInfo['userid'],true))
            throw new RuntimeException('plan_order_unavailable');
        if (!achbotOrderMayUsePaidPlan($connection,$orderInfo,true)) {
            $connection->rollback();$begun=false;
            alert(\achbotMessage('renewal.extensions.alert.65fc393064'),true);return;
        }
        $plan=\AchBot\Subscription\AccountDetails\paidPlanForOrder($connection,$orderInfo,$newPlanId,true);
        if ($plan===null) throw new RuntimeException('plan_target_unavailable');
        if ((int)$orderInfo['fileid']===$newPlanId) {
            $connection->rollback();$begun=false;
            sendMessage(\achbotMessage('renewal.extensions.message.dee2da49f3'));return;
        }
        // Choosing a future renewal product changes only local fileid. It is
        // not a purchase, remote entitlement change, debit or paid receipt.
        $oldPlan=(int)$orderInfo['fileid'];$serverId=(int)$orderInfo['server_id'];$ownerId=(int)$orderInfo['userid'];
        $stmt=$connection->prepare('UPDATE orders_list SET fileid=? WHERE id=? AND userid=? AND server_id=? AND fileid=? AND status=1');
        $stmt->bind_param('iiiii',$newPlanId,$orderId,$ownerId,$serverId,$oldPlan);
        if (!$stmt->execute() || $stmt->affected_rows!==1) {$stmt->close();throw new RuntimeException('plan_update_unconfirmed');}
        $stmt->close();$commitAttempted=true;
        if (!$connection->commit()) throw new RuntimeException('plan_commit_unknown');
        $begun=false;
    } catch (Throwable $error) {
        if ($begun) {try {$connection->rollback();}catch(Throwable $ignored) {}}
        error_log('achbot_plan_selection_unverified order_id='.$orderId);
        alert($commitAttempted?\achbotMessage('renewal.extensions.plan_selection_review'):
            \achbotMessage('renewal.extensions.plan_selection_unavailable'),true);
        return;
    }
    // Failed notifications cannot change a committed product selection.
    editText($message_id,\achbotMessage('renewal.extensions.message.3067ddba76'));
    $escape=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $channelMessage=\achbotMessage('renewal.extensions.channel_message.f9e3ed8281',[
        'orderInfo_userid'=>$orderInfo['userid'],'first_name'=>$escape($first_name),
        'username'=>$escape($username),'orderInfo_remark'=>$escape($orderInfo['remark']),
        'planTitle'=>$escape($plan['title']),'newPlanId'=>$newPlanId]);
    achbotNotifyAdmins('sendMessage', ['text'=>$channelMessage,'parse_mode'=>'HTML']);
    return;
}
if (preg_match('/^increaseAVolume([1-9][0-9]{0,9})$/D', $data, $match)) {
    // بررسی تاریخ انقضا
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $orderInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$orderInfo || (int)$orderInfo['userid']!==(int)$from_id ||
        !achbotSupportsOrder($connection, (int)$orderInfo['id'])) {
        alert(\achbotMessage('renewal.extensions.alert.d0779ad27d'), true);
        return;
    }


    $agentBought = (bool)$orderInfo['agent_bought'];
    $eligibility=achbotOrderAddonEligibility($connection,$orderInfo,'volume');
    if (!$eligibility['eligible']) {
        alert(\achbotMessage($eligibility['reason']==='expired'
            ? 'renewal.extensions.alert.96762b5a42' : 'payment.pre_payment.alert.fa55f5201f'),true);
        return;
    }

    $stmt = $connection->prepare("SELECT * FROM `increase_plan`");
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    if ($res->num_rows == 0) {
        alert(\achbotMessage('renewal.extensions.alert.e6c27b8039'));
        exit;
    }
    $keyboard = [];
    while ($cat = $res->fetch_assoc()) {
        if ((int)$cat['price']<0 || achbotWholeServiceUnits($cat['volume'],achbotServiceVolumeMax())===null) continue;
        $id = $cat['id'];
        $title = $cat['volume'];
        $price = $cat['price'];
        if ($agentBought == true) {
            $discounts = json_decode($userInfo['discount_percent'], true);
            if ($botState['agencyPlanDiscount'] == "on") 
                $discount = $discounts['plans'][$orderInfo['fileid']] ?? $discounts['normal'];
            else 
                $discount = $discounts['servers'][$orderInfo['server_id']] ?? $discounts['normal'];
            $price -= floor($price * $discount / 100);
        }
        if ($price == 0) 
            $price = \achbotMessage('renewal.extensions.price.dda7ff2974');
        else 
            $price = number_format($price) .  \achbotMessage('renewal.extensions.text.2cc98f7f7c');
        
        $keyboard[] = ['text' => \achbotMessage('renewal.extensions.button.249377f2b7', ['title' => $title, 'price' => $price]), 'callback_data' => "increaseVolumePlan{$match[1]}_{$id}"];
    }
    $keyboard = array_chunk($keyboard, 2);
    $keyboard[] = [['text' => \achbotMessage('renewal.extensions.button.8405a4154f'), 'callback_data' => "mainMenu"]];
    $res = editText($message_id, \achbotMessage('renewal.extensions.message.ab954c63e6'), json_encode([
        'inline_keyboard' => $keyboard
    ]));
}

if(preg_match('/^increaseVolumePlan(?<orderId>[1-9][0-9]{0,9})_(?<volumeId>[1-9][0-9]{0,9})$/D',$data,$match)){
    $data = str_replace('increaseVolumePlan','',$data);
    $stmt = $connection->prepare("SELECT * FROM `increase_plan` WHERE `id` = ?");
    $stmt->bind_param("i", $match['volumeId']);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$res || (int)$res['price']<0 || achbotWholeServiceUnits($res['volume'],achbotServiceVolumeMax())===null) {
        alert(\achbotMessage('renewal.extensions.alert.6173c35986'),true);
        return;
    }
    $planprice = $res['price'];
    $plangb = achbotWholeServiceUnits($res['volume'],achbotServiceVolumeMax());

    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $match['orderId']);
    $stmt->execute();
    $orderInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$orderInfo || (int)$orderInfo['userid']!==(int)$from_id ||
        !achbotSupportsOrder($connection, (int)$orderInfo['id'])) {
        alert(\achbotMessage('renewal.extensions.alert.d0779ad27d'), true);
        return;
    }

    $eligibility=achbotOrderAddonEligibility($connection,$orderInfo,'volume');
    if (!$eligibility['eligible']) {
        alert(\achbotMessage($eligibility['reason']==='expired'
            ? 'renewal.extensions.alert.f5aab191db' : 'payment.pre_payment.alert.fa55f5201f'),true);
        return;
    }
    $agentBought = $orderInfo['agent_bought'];
 
    if($agentBought == true){
        $discounts = json_decode($userInfo['discount_percent'],true);
        if($botState['agencyPlanDiscount']=="on") $discount = $discounts['plans'][$orderInfo['fileid']]?? $discounts['normal'];
        else $discount = $discounts['servers'][$orderInfo['server_id']]?? $discounts['normal'];
        
        $planprice -= floor($planprice * $discount / 100);
    }

    $hash_id = RandomString();
    achbotRemoveUnclaimedPendingPayments($connection,$from_id,'INCREASE_VOLUME');

    $time = time();
    $plan_id = $orderInfo['fileid'];
	$server_id = $orderInfo['server_id'];
	$order_id = $match['orderId'];
	
	$stmt = $connection->prepare("INSERT INTO `pays` (`hash_id`, `user_id`, `type`, `plan_id`, `server_id`, `order_id`, `volume`, `day`, `price`, `request_date`, `state`)
								VALUES (?, ?, ?, ?, ?, ?, ?, '0', ?, ?, 'pending')");
	$type = "INCREASE_VOLUME_$data";
	$stmt->bind_param("sisiiiiii", $hash_id, $from_id, $type, $plan_id, $server_id, $order_id, $plangb, $planprice, $time);
	$newDraftId=achbotPayUxInsertDraft($connection,(int)$from_id,$stmt);
	$stmt->close();
	if ($newDraftId<=0) {
	    alert(\achbotMessage('renewal.extensions.alert.ae271a2d1d'),true);
	    return;
	}

    $keyboard = array();

    if($planprice == 0) $planprice = \achbotMessage('renewal.extensions.planprice.97e0b2bd8a');
    else $planprice = " " . number_format($planprice) . \achbotMessage('renewal.extensions.text.2cc98f7f7c');

    
    if(achbotManualEnabled('bank_transfer') && achbotPayUxAudienceAllowed($connection,'bank_transfer',(int)$from_id)) $keyboard[] = [['text' => $buttonValues['cart_to_cart'] . $planprice,  'callback_data' => "payIncreaseWithCartToCart$hash_id"]];
    if(achbotManualEnabled('gift_card') && achbotPayUxAudienceAllowed($connection,'gift_card',(int)$from_id)) $keyboard[] = [['text' => $buttonValues['gift_card'], 'callback_data' => "payIncreaseWithGiftCard$hash_id"]];
    if (achbotManualCryptoEnabled((int)$from_id) && (achbotPayUxAudienceAllowed($connection,'manual_usdt',(int)$from_id) || achbotPayUxAudienceAllowed($connection,'manual_trx',(int)$from_id))) $keyboard[] = [['text' => \achbotMessage('renewal.extensions.button.e729457ba2'), 'callback_data' => "mcm_$hash_id"]];
    if(($botState['nowPaymentOther'] == "on" && achbotPayUxAudienceAllowed($connection,'nowpayments',(int)$from_id) && achbotUsdtRateForUser($connection,(int)$from_id)!==null)) $keyboard[] = [['text' => $buttonValues['now_payment_gateway'],  'url' => $botUrl . "pay/checkout.php?nowpayment&hash_id=" . $hash_id]];
    if(($botState['zarinpal'] == "on" && achbotPayUxAudienceAllowed($connection,'zarinpal',(int)$from_id))) $keyboard[] = [['text' => $buttonValues['zarinpal_gateway'],  'url' => $botUrl . "pay/checkout.php?zarinpal&hash_id=" . $hash_id]];
    if(($botState['nextpay'] == "on" && achbotPayUxAudienceAllowed($connection,'nextpay',(int)$from_id))) $keyboard[] = [['text' => $buttonValues['nextpay_gateway'],  'url' => $botUrl . "pay/checkout.php?nextpay&hash_id=" . $hash_id]];
    if(($botState['walletState'] == "on" && achbotPayUxAudienceAllowed($connection,'wallet',(int)$from_id))) $keyboard[] = [['text' => \achbotMessage('renewal.extensions.button.5adbd64a32') . $planprice,  'callback_data' => "payIncreaseWithWallet$hash_id"]];

    $keyboard[] = [['text'=>$buttonValues['cancel'], 'callback_data'=> "mainMenu"]];
    $sent=sendMessage(\achbotMessage('renewal.extensions.message.48930749a9'),
        json_encode(['inline_keyboard'=>$keyboard]),'HTML',$from_id);
    require_once __DIR__ . '/../PaymentLifecycle.php';
    achbotPayUxTrackByHash($connection,(string)$hash_id,(int)$from_id,$sent);
}

if (is_string($data) && (strlen($data) <= 64 || ($GLOBALS['achbotManualCryptoVerifiedDispatch'] ?? null) === $data) && preg_match('/\ApayIncreaseWith(?:CartToCart|GiftCard|ManualUsdt)([A-Za-z0-9_-]{1,50})\z/D', $data, $match)) {

    $payInfo = dbFetchPayByHash($connection, $match[1]);
    if (!$payInfo) {
        prepareStartFallback();
        delMessage();
        return '__ACHBOT_V8_GOTO_START__';
    }

    $method = str_starts_with($data, 'payIncreaseWithManualUsdt') ? (($GLOBALS['achbotManualCryptoChoice'] ?? '') === 'TRON' ? 'manual_trx' : 'manual_usdt') :
        (str_starts_with($data, 'payIncreaseWithGiftCard') ? 'gift_card' : 'bank_transfer');
    if (!achbotManualClaim($connection, $payInfo, $method, (int)$from_id,
                           $GLOBALS['achbotManualCryptoChoice'] ?? null)) {
        alert(\achbotMessage('renewal.extensions.alert.1a885aaca4'), true);
        return;
    }

	$msg = buildCartToCartInfoMessage($paymentKeys, $mainValues, (int)$payInfo['price'], (int)$payInfo['user_id'], $method, (string)$payInfo['hash_id']);
    if ($msg === "") {
        alert(\achbotMessage('renewal.extensions.alert.07ee06b4bc'), true);
        return;
    }
    $keyboard = kbSendReceiptAndCancel(in_array($method, ['manual_usdt','manual_trx'], true) ? "sendIncreaseVolumeUsdtReceipt" : ($method === 'gift_card' ? "sendIncreaseVolumeGiftReceipt" : "sendIncreaseVolumeReceipt"), "cancelIncreaseVolumeReceipt", $match[1], $method);

    $delivered = sendMessage($msg, $keyboard, "HTML");
    require_once __DIR__ . '/../PaymentLifecycle.php';
    achbotPayUxTrackByHash($connection,(string)$payInfo['hash_id'],(int)$from_id,$delivered);
    if (function_exists('achbotApiPaymentTrackInvoice')) achbotApiPaymentTrackInvoice($connection,(string)$payInfo['hash_id'],(int)$from_id,$delivered);
    if (is_object($delivered) && !empty($delivered->ok)) {
    setUser(function_exists('achbotApiPaymentInitialStep') ? achbotApiPaymentInitialStep($connection,(string)$match[1],"payIncreaseWith" . (in_array($method, ['manual_usdt','manual_trx'], true) ? 'ManualUsdt' : ($method === 'gift_card' ? 'GiftCard' : 'CartToCart')) . $match[1]) : "payIncreaseWith" . (in_array($method, ['manual_usdt','manual_trx'], true) ? 'ManualUsdt' : ($method === 'gift_card' ? 'GiftCard' : 'CartToCart')) . $match[1]);
        delMessage();
    } else {
        // The pinned attempt may be reopened. Never erase the selection menu
        // if Telegram failed to deliver the replacement instructions.
        alert(\achbotMessage('renewal.extensions.alert.7a9c30e938'), true);
    }
    exit;
}

if (preg_match('/send(?:IncreaseVolume|IncreaseVolumeGift|IncreaseVolumeUsdt)Receipt(?<hashId>.*)/', $data, $match)) {

    $payInfo = dbFetchPayByHash($connection, $match['hashId']);
    if (!$payInfo) {
        prepareStartFallback();
        delMessage();
        return '__ACHBOT_V8_GOTO_START__';
    }

    $method = strpos($data, 'UsdtReceipt') !== false ? achbotManualCryptoReceiptProviderByHash($connection, (string)$match['hashId']) :
        (strpos($data, 'GiftReceipt') !== false ? 'gift_card' : 'bank_transfer');
    if (!achbotManualReceiptAllowed($connection, $payInfo, $method, (int)$from_id)) {
        alert(\achbotMessage('renewal.extensions.alert.d06bc731eb'), true);
        return;
    }
    $guardMsg = duplicateReceiptGuard($connection, $payInfo, time());
    if ($guardMsg) { alert($guardMsg, true); exit; }

    // Explicit user intent to send a receipt, not a claim of paid funds.
    // Serialize with the six-hour unstarted-invoice expiry sweep.
    require_once __DIR__ . '/../PaymentLifecycle.php';
    if (!achbotPayUxStartManualReceipt($connection,(int)$payInfo['id'],
                                      $method,(int)$from_id)) {
        alert(\achbotMessage('renewal.extensions.alert.560f05f822'),true);
        return;
    }

    delMessage();
    setUser((in_array($method, ['manual_usdt','manual_trx'], true) ? "waitIncreaseVolumeUsdtReceipt" : ($method === 'gift_card' ? "waitIncreaseVolumeGiftReceipt" : "waitIncreaseVolumeReceipt")) . $match['hashId']);

    $keyboard = kbOnlyCancel("cancelIncreaseVolumeReceipt", $match['hashId']);
    $sent=sendMessage(achbotManualEvidencePrompt($mainValues,$connection,$payInfo,$method), $keyboard, "HTML");
    require_once __DIR__ . '/../PaymentLifecycle.php';
    achbotPayUxTrackByHash($connection,(string)$match['hashId'],(int)$from_id,$sent);
    exit;
}

if (preg_match('/cancelIncreaseVolumeReceipt(?<hashId>.*)/', $data, $match)) {
    handleCancelFlow($mainValues, $removeKeyboard);
}

if (preg_match('/wait(?:IncreaseVolume|IncreaseVolumeGift|IncreaseVolumeUsdt)Receipt(.*)/', $userInfo['step'], $match)) {

    $method = strpos($userInfo['step'], 'UsdtReceipt') !== false ? achbotManualCryptoReceiptProviderByHash($connection, (string)$match[1]) :
        (strpos($userInfo['step'], 'GiftReceipt') !== false ? 'gift_card' : 'bank_transfer');
    $receiptPay = dbFetchPayByHash($connection, $match[1]);
    if (!$receiptPay || !achbotManualReceiptAllowed($connection, $receiptPay, $method, (int)$from_id)) {
        setUser();
        alert(\achbotMessage('renewal.extensions.alert.882596d0f1'), true);
        return;
    }
    $fileid = achbotManualEvidenceInput($update, $connection, $receiptPay, $method);
    if (!$fileid) {
        $keyboard = kbOnlyCancel("cancelIncreaseVolumeReceipt", $match[1]);
        sendMessage(achbotManualEvidencePrompt($mainValues,$connection,$receiptPay,$method,true), $keyboard, "HTML");
        exit;
    }

    $deliveryToken = achbotManualBeginEvidence($connection, $receiptPay, $method, (int)$from_id, $fileid);
    if ($deliveryToken === null) {
        alert(\achbotMessage('renewal.extensions.alert.795c4bddb8'), true);
        return;
    }

    $payParam = dbFetchPayByHash($connection, $match[1]);
    if (!$payParam) {
        prepareStartFallback();
        delMessage();
        return '__ACHBOT_V8_GOTO_START__';
    }

    $payType = $payParam['type'];

    preg_match('/^INCREASE_VOLUME_(\d+)_(\d+)/', $payType, $increaseInfo);
    $orderId = (int)$increaseInfo[1];
    $planid  = (int)$increaseInfo[2];

    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $orderInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$orderInfo || !achbotSupportsOrder($connection, (int)$orderInfo['id'])) {
        alert(\achbotMessage('renewal.extensions.alert.d0779ad27d'), true);
        return;
    }


    $remark = $orderInfo['remark'];

    $stmt = $connection->prepare("SELECT * FROM `increase_plan` WHERE `id` = ?");
    $stmt->bind_param("i", $planid);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $price  = $payParam['price'];
    $volume = achbotPinnedAddonUnits($payParam);



    $msg = str_replace(
        ['INCREASE', 'TYPE', "USER-ID", "USERNAME", "NAME", "PRICE", "REMARK"],
        [$volume, \achbotMessage('renewal.extensions.text.4123904480'), $from_id, $username, $first_name, $price, $remark],
        achbotManualCaption($connection, $receiptPay, $mainValues['increase_account_request_message'])
    );

    $adminKeyboard = buildAdminKeyboardWithStats(
        $connection,
        $buttonValues,
        "approveIncreaseVolume{$match[1]}",
        "declineAction_{$match[1]}",
        $match[1],
        (int)$payParam['user_id']
    );

    if (sendReceiptToAdminsAndUpdatePay($connection, $fileid,
        $msg, $adminKeyboard, $admin, $match[1],
        $deliveryToken)) {
        setUser();
        sendWithStartButton(achbotManualAcknowledgement($receiptPay, $method), $buttonValues['start_bot'], $botUsername, 'mainMenu', 'HTML', (string)$receiptPay['hash_id']);
    } else {
        sendMessage(\achbotMessage('renewal.extensions.message.c346a0d62e'));
    }
    exit;
}

if (preg_match('/^approveIncreaseVolume(.*)/', $data, $match) && achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'payments')) {
    $apiManualSeen=function_exists('achbotApiPaymentManualAddonPreflight') ? achbotApiPaymentManualAddonPreflight($connection,(string)$match[1]) : null;
    $connection->begin_transaction();
    $localCommitted=false;
    try {
        $hash_id = $match[1];

        $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? FOR UPDATE");
        $stmt->bind_param("s", $hash_id);
        $stmt->execute();
        $payParam = $stmt->get_result()->fetch_assoc();
        if (!(function_exists('achbotApiPaymentManualAddonAllowedLocked') ? achbotApiPaymentManualAddonAllowedLocked($connection,$payParam,$apiManualSeen) : achbotSupportsPaymentSubject($connection, $payParam, true))) {
            $connection->rollback();
            alert(\achbotMessage('renewal.extensions.alert.135aa8877f'), true);
            return;
        }

        $stmt->close();

        if (!achbotManualServiceApprovalAllowedLocked($connection, $payParam, 'volume')) {
            $connection->rollback();
            alert(\achbotMessage('renewal.extensions.alert.733308c9fe'), true);
            return;
        }

        $payType = $payParam['type'];

        if (!preg_match('/^INCREASE_VOLUME_(\d+)_(\d+)/', $payType, $increaseInfo)) {
            throw new Exception("invalid_increase_type");
        }
        $orderId = intval($increaseInfo[1]);
        $planid  = intval($increaseInfo[2]);
        $uid     = intval($payParam['user_id']);

        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
        $stmt->bind_param("i", $orderId);
        $stmt->execute();
        $orderInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$orderInfo || !achbotSupportsOrder($connection, (int)$orderInfo['id'])) {
        throw new RuntimeException('volume_extension_order_ineligible');
    }


        if (!$orderInfo) {
            throw new Exception("order_not_found");
        }

        $server_id  = intval($orderInfo['server_id']);
        $inbound_id = intval($orderInfo['inbound_id']);
        $remark     = $orderInfo['remark'];
        $uuid       = $orderInfo['uuid'] ?? "0";

        $quotedUnits=achbotPinnedAddonUnits($payParam);
        if ($quotedUnits===null) throw new RuntimeException('service_quote_units_unverified');
        $price  = intval($payParam['price']);
        $volume = $quotedUnits;
        if ($volume===null) throw new RuntimeException('unsupported_service_units');

        $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $server_info = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$server_info) {
            throw new Exception("server_config_missing");
        }

        $serverType = $server_info['type'];

        if (!achbotSupportsPanel($serverType))
            throw new UnexpectedValueException('unsupported_panel');
        // Operator receipt approval is financial ownership, not a remote
        // effect claim. Commit a unique nonterminal claim BEFORE the PUT.
        // An ambiguous crash/SQL reply can leave review pending, never a
        // second automatic remote request on this same receipt.
        if ((int)$payParam['price']!==$price ||
            (int)$payParam['user_id']!==$uid ||
            (string)$payParam['type']!=="INCREASE_VOLUME_{$orderId}_{$planid}")
            throw new RuntimeException('volume_extension_claim_subject_changed');
        $payId=(int)$payParam['id'];
        $payType=(string)$payParam['type'];
        $stmt=$connection->prepare("UPDATE pays SET state='processing'
            WHERE id=? AND user_id=? AND hash_id=? AND type=?
              AND price=? AND state='have_sent'");
        $stmt->bind_param('iissi',$payId,$uid,$hash_id,$payType,$price);
        $claimed=$stmt->execute() && $stmt->affected_rows===1;
        $stmt->close();
        if (!$claimed || $connection->commit()!==true)
            throw new RuntimeException('volume_extension_claim_unconfirmed');

        unset($markup[count($markup)-1]);

        if (achbotSupportsPanel($serverType)) {
            $response = achbotApplyServiceEdit($connection,$server_id, ['pay_id'=>(int)$payId, 'remark' => $remark, 'plus_volume' => $volume]);
        } else {
            throw new \UnexpectedValueException('unsupported_panel');
        }

        if (is_null($response)) {
            throw new Exception("server_connection_null");
        }
        if (!is_object($response) && !is_array($response)) {
            throw new Exception("invalid_response_type");
        }
        $success = (is_object($response) && !empty($response->success))
                || (is_array($response)  && !empty($response['success']));
        if (!$success) {
            throw new Exception("provider_rejected");
        }

        if (!is_object($response) || ($response->verified??false)!==true)
            throw new RuntimeException('volume_extension_provider_result_unverified');
        if ($connection->begin_transaction()!==true)
            throw new RuntimeException('volume_extension_finalization_begin_failed');
        $stmt=$connection->prepare("SELECT id,state,user_id,hash_id,type,price
            FROM pays WHERE id=? FOR UPDATE");
        $stmt->bind_param('i',$payId);
        $ok=$stmt->execute();
        $locked=$ok?$stmt->get_result()->fetch_assoc():null;
        $stmt->close();
        if (!$locked || $locked['state']!=='processing' ||
            (int)$locked['user_id']!==$uid ||
            (int)$locked['price']!==$price ||
            (string)$locked['hash_id']!==$hash_id ||
            (string)$locked['type']!==$payType)
            throw new RuntimeException('volume_extension_claim_stale');
        achbotStoreVerifiedServiceExpiryLocked($connection,$orderInfo,$response);
        $timeNow=time();
        $stmt=$connection->prepare("INSERT INTO increase_order
            (userid,server_id,inbound_id,remark,amount,date,volume)
            VALUES (?,?,?,?,?,?,?)");
        $stmt->bind_param('iiisiii',$uid,$server_id,$inbound_id,
                          $remark,$price,$timeNow,$volume);
        $saved=$stmt->execute() && $stmt->affected_rows===1;
        $stmt->close();
        if (!$saved) throw new RuntimeException('volume_extension_audit_unconfirmed');
        $stmt=$connection->prepare("UPDATE pays SET state='approved',approved_by=?
            WHERE id=? AND user_id=? AND hash_id=? AND state='processing'");
        $stmt->bind_param('iiis',$from_id,$payId,$uid,$hash_id);
        $saved=$stmt->execute() && $stmt->affected_rows===1;
        $stmt->close();
        if (!$saved || $connection->commit()!==true)
            throw new RuntimeException('volume_extension_finish_unconfirmed');
        $localCommitted=true;

        $keys = json_encode([
            'inline_keyboard' => [
                [['text' => \achbotMessage('renewal.extensions.button.139c829462', ['from_id' => $from_id]), 'callback_data' => "wizwizch"]],
                [['text' => \achbotMessage('renewal.extensions.hash_id.2899228604', ['hash_id' => $hash_id]), 'callback_data' => "noop_$hash_id"]]
            ]
        ], 488);

        // ویرایش پیام ادمین اصلی
        editKeys($keys, $payParam['message_id'], $payParam['chat_id']);

        // ویرایش پیام سایر ادمین‌ها
        $adminMessages = json_decode($payParam['admins_messages'], true);
        if (is_array($adminMessages)) {
            foreach ($adminMessages as $msgInfo) {
                if (isset($msgInfo['chat_id'], $msgInfo['message_id']) && $msgInfo['chat_id'] != $payParam['chat_id'] &&
                    achbotAdminStoredRecipientAllowed($connection,$msgInfo['chat_id'],(int)$admin)) {
                    editKeys($keys, $msgInfo['message_id'], $msgInfo['chat_id']);
                }
            }
        }

        sendMessage(\achbotMessage('renewal.extensions.message.d5b6a4e6e7', ['volume' => $volume, 'remark' => $remark]), null, "HTML", $uid);
        return;

    } catch (Throwable $e) {
        if ($localCommitted) {
            error_log('achbot_manual_day_notice_unconfirmed pay_id='.(int)($payId??0));
            return;
        }
        try {$connection->rollback();} catch (Throwable $ignored) {}

        // پیام فنی برای ادمین اقدام‌کننده
        $err = \achbotMessage('renewal.extensions.err.322385c9dd')
             . \achbotMessage('renewal.extensions.hash__i_d_match_1.3abb834966', ['match_1' => $match[1]])
             . \achbotMessage('renewal.extensions.admin_from_id.748897de00', ['from_id' => $from_id])
             . \achbotMessage('renewal.extensions.external_effect_unverified_verify_before.10e2ccf8a3') . achbotFinanceDiagnosticReason($e);
        achbotNotifyAdmins('sendMessage', ['text'=>$err], ['report_channel'=>null]);

        alert(\achbotMessage('renewal.extensions.alert.36f9c089de'), true);
        return;
    }
}

if(is_string($data) && strlen($data) <= 64 && preg_match('/\ApayIncreaseWithWallet([A-Za-z0-9_-]{1,50})\z/D', $data, $match)){
    $connection->begin_transaction();
    $localCommitted=false;
    try {
        // Customer-wallet issuance shares the same owner-first lock as drafts
        // and manual/hosted claims. Never lock pays before users.userid.
        achbotPayUxLockDraftOwner($connection,(int)$from_id);
        $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? FOR UPDATE");
        $stmt->bind_param("s", $match[1]);
        $stmt->execute();
        $payRes = $stmt->get_result();
        $stmt->close();

        if($payRes->num_rows == 0){
            $connection->rollback();
            $text = "/start";
            $data = "";
            delMessage();
            return '__ACHBOT_V8_GOTO_START__';
        }

        $payParam = $payRes->fetch_assoc();
        // Same guarded claim policy as purchase wallet callbacks, while
        // this exact pays row is already locked in the current transaction.
        if (!achbotWalletDirectAllowedLocked($connection, $payParam, (int)$from_id)) {
            $connection->rollback();
            alert(\achbotMessage('renewal.extensions.alert.9b6f1fa91f'), true);
            return;
        }

        if (!achbotSupportsPaymentSubject($connection, $payParam, true)) {
            $connection->rollback();
            alert(\achbotMessage('renewal.extensions.alert.31d13e9866'), true);
            return;
        }

        $hash_id  = $payParam['hash_id'];

        if ($payParam['state'] === "paid_with_wallet") {
            $connection->rollback();
            return;
        }

        $payType = $payParam['type'];
        if (!preg_match('/^INCREASE_VOLUME_(\d+)_(\d+)/', $payType, $increaseInfo)) {
            throw new Exception("invalid_increase_type");
        }
        $orderId = intval($increaseInfo[1]);
        $planid  = intval($increaseInfo[2]);
        $uid     = intval($payParam['user_id'] ?? $from_id);

        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
        $stmt->bind_param("i", $orderId);
        $stmt->execute();
        $orderInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$orderInfo || !achbotSupportsOrder($connection, (int)$orderInfo['id'])) {
        throw new RuntimeException('wallet_day_order_ineligible');
    }


        if (!$orderInfo){
            throw new Exception("order_not_found");
        }

        $server_id  = intval($orderInfo['server_id']);
        $inbound_id = intval($orderInfo['inbound_id']);
        $remark     = $orderInfo['remark'];
        $uuid       = $orderInfo['uuid'] ?? "0";

        $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id` = ?");
        $stmt->bind_param('i', $server_id);
        $stmt->execute();
        $serverConfig = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if(!$serverConfig){
            throw new Exception("server_config_missing");
        }
        $serverType = $serverConfig['type'];

        $quotedUnits=achbotPinnedAddonUnits($payParam);
        if ($quotedUnits===null) throw new RuntimeException('service_quote_units_unverified');
        $price  = intval($payParam['price']);
        $volume = $quotedUnits;
        if ($volume===null) throw new RuntimeException('unsupported_service_units');

        $stmt = $connection->prepare("SELECT id,wallet FROM users
            WHERE userid=? FOR UPDATE");
        $stmt->bind_param('i',$uid);
        if (!$stmt->execute()) throw new RuntimeException('wallet_day_owner_read_failed');
        $owners=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if (count($owners)!==1)
            throw new RuntimeException('wallet_day_owner_ambiguous');
        $uRow=$owners[0];
        $userwallet=(int)$uRow['wallet'];
        if($userwallet < $price) {
            $needamount = $price - $userwallet;

            $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'low_wallet' WHERE `hash_id` = ?");
            $stmt->bind_param("s", $hash_id);
            $stmt->execute();
            $stmt->close();

            $connection->commit();

            alert(\achbotMessage('renewal.extensions.alert.39c72d8044') . number_format($userwallet) . \achbotMessage('renewal.extensions.alert.71a2507661') . number_format($needamount) . \achbotMessage('renewal.extensions.text.2cc98f7f7c'), true);
            return;
        }

        if (!achbotSupportsPanel($serverType))
            throw new UnexpectedValueException('unsupported_panel');
        // Reserve the debit and unique payment ownership together BEFORE
        // dispatch. An unknown remote/local result remains processing_wallet
        // for operator review; callback replay cannot debit or dispatch again.
        $payId=(int)$payParam['id'];
        $payType=(string)$payParam['type'];
        if ((int)$payParam['price']!==$price ||
            (int)$payParam['user_id']!==$uid ||
            $payType!=="INCREASE_VOLUME_{$orderId}_{$planid}")
            throw new RuntimeException('wallet_day_claim_subject_changed');
        $ownerPk=(int)$uRow['id'];
        $stmt=$connection->prepare("UPDATE users SET wallet=wallet-?
            WHERE id=? AND userid=? AND wallet>=?");
        $stmt->bind_param('iiii',$price,$ownerPk,$uid,$price);
        $reserved=$stmt->execute() && $stmt->affected_rows===1;
        $stmt->close();
        if (!$reserved) throw new RuntimeException('wallet_day_reservation_unconfirmed');
        $payState=(string)$payParam['state'];
        $stmt=$connection->prepare("UPDATE pays SET state='processing_wallet'
            WHERE id=? AND user_id=? AND hash_id=? AND type=? AND price=?
              AND state=?");
        $stmt->bind_param('iissis',$payId,$uid,$hash_id,$payType,
                          $price,$payState);
        $claimed=$stmt->execute() && $stmt->affected_rows===1;
        $stmt->close();
        if (!$claimed || $connection->commit()!==true)
            throw new RuntimeException('wallet_day_claim_unconfirmed');

        if (achbotSupportsPanel($serverType)){
            $response = achbotApplyServiceEdit($connection,$server_id, ['pay_id'=>(int)$payId, 'remark'=>$remark, 'plus_volume'=>$volume]);
        } else {
            throw new \UnexpectedValueException('unsupported_panel');
        }

        if (is_null($response)) {
            throw new Exception("server_connection_null");
        }
        if (!is_object($response) && !is_array($response)) {
            throw new Exception("invalid_response_type");
        }
        $success = (is_object($response) && !empty($response->success))
                || (is_array($response)  && !empty($response['success']));
        if (!$success) {
            throw new Exception("provider_rejected");
        }

        if (!is_object($response) || ($response->verified??false)!==true)
            throw new RuntimeException('wallet_day_provider_result_unverified');
        if ($connection->begin_transaction()!==true)
            throw new RuntimeException('wallet_day_finish_begin_failed');
        $stmt=$connection->prepare("SELECT id,state,user_id,hash_id,type,price
            FROM pays WHERE id=? FOR UPDATE");
        $stmt->bind_param('i',$payId);
        $ok=$stmt->execute();
        $locked=$ok?$stmt->get_result()->fetch_assoc():null;
        $stmt->close();
        if (!$locked || $locked['state']!=='processing_wallet' ||
            (int)$locked['user_id']!==$uid ||
            (int)$locked['price']!==$price ||
            (string)$locked['hash_id']!==$hash_id ||
            (string)$locked['type']!==$payType)
            throw new RuntimeException('wallet_day_claim_stale');
        achbotStoreVerifiedServiceExpiryLocked($connection,$orderInfo,$response);
        $timeNow=time();
        $stmt=$connection->prepare("INSERT INTO increase_order
            (userid,server_id,inbound_id,remark,amount,date,volume)
            VALUES (?,?,?,?,?,?,?)");
        $stmt->bind_param('iiisiii',$uid,$server_id,$inbound_id,
                          $remark,$price,$timeNow,$volume);
        $saved=$stmt->execute() && $stmt->affected_rows===1;
        $stmt->close();
        if (!$saved) throw new RuntimeException('wallet_day_audit_unconfirmed');
        $stmt=$connection->prepare("UPDATE pays SET state='paid_with_wallet'
            WHERE id=? AND user_id=? AND hash_id=? AND state='processing_wallet'");
        $stmt->bind_param('iis',$payId,$uid,$hash_id);
        $saved=$stmt->execute() && $stmt->affected_rows===1;
        $stmt->close();
        if (!$saved || $connection->commit()!==true)
            throw new RuntimeException('wallet_day_finish_unconfirmed');
        $localCommitted=true;

        editText($message_id, \achbotMessage('renewal.extensions.message.2d27ba4eea', ['volume' => $volume]), getMainKeys());

        $keys = json_encode(['inline_keyboard'=>[
            [
                ['text'=>\achbotMessage('renewal.extensions.button.9a3cb8176f'),'callback_data'=>"wizwizch"]
            ],
            [
                ['text'=>\achbotMessage('renewal.extensions.hash_id.2899228604', ['hash_id' => $hash_id]), 'callback_data'=>"noop_$hash_id"]
            ]
        ]]);

        $msg = \achbotMessage('renewal.extensions.msg.0e12e9ce3d', ['from_id' => $from_id, 'first_name' => $first_name, 'username' => $username, 'remark' => $remark, 'volume' => $volume, 'price' => $price]);
        achbotNotifyAdmins('sendMessage', ['text'=>$msg, 'reply_markup'=>$keys, 'parse_mode'=>'HTML']);

        return;

    } catch (Throwable $e) {
        if ($localCommitted) {
            error_log('achbot_wallet_day_notice_unconfirmed pay_id='.(int)($payId??0));
            return;
        }
        try {$connection->rollback();} catch (Throwable $ignored) {}

        // پیام فنی برای ادمین
        $adminErr = \achbotMessage('renewal.extensions.admin_err.128069a4b4')
                  . \achbotMessage('renewal.extensions.hash__i_d_match_1.3abb834966', ['match_1' => $match[1]])
                  . \achbotMessage('renewal.extensions.user_from_id.45aa9ea684', ['from_id' => $from_id])
                  . \achbotMessage('renewal.extensions.external_effect_unverified_verify_before.10e2ccf8a3').achbotFinanceDiagnosticReason($e);
        achbotNotifyAdmins('sendMessage', ['text'=>$adminErr], ['report_channel'=>null]);

        sendMessage(\achbotMessage('renewal.extensions.message.ba8adc7121'), null, null, $uid);

        return;
    }
}
