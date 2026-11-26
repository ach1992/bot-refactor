<?php
/** AChBot current payment route and shared receipt presentation. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../../messages/loader.php'; // ACHBOT_TEXT_CATALOG

require_once __DIR__ . '/../../Admin/Access.php';
if (!achbotAdminRequestAllowed(is_string($data??null)?$data:null,is_string($text??null)?$text:null,(string)($userInfo['step']??''))) {achbotAdminDeny();return;}


require_once __DIR__ . '/../PaymentLifecycle.php';
require_once __DIR__ . '/../../Purchase/AgentPricing.php';
require_once __DIR__ . '/../../Plans/Addons.php';

if(preg_match('/\AincreaseADay([1-9][0-9]{0,9})\z/D', $data, $match)){
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $orderInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$orderInfo || (int)$orderInfo['userid']!==(int)$from_id || !achbotSupportsOrder($connection, (int)$orderInfo['id'])) {
        alert(\achbotMessage('renewal.extensions.alert.d0779ad27d'), true);
        return;
    }

    try {
        if (achbotManagedServicePlan($connection,$orderInfo,'ADD_TIME',$botState)===null)
            throw new DomainException('managed_service_unavailable');
        achbotAddonSchema($connection);
        $addonContext=achbotAddonContext($connection,$orderInfo);
        if (!achbotOrderAddonEligibility($connection,$orderInfo,'day',$addonContext)['eligible']) {
            alert(\achbotMessage('payment.pre_payment.alert.fa55f5201f'),true);
            return;
        }
        $catalog=achbotAddonCatalog($connection,'day',$addonContext);
    } catch (Throwable $error) {
        alert(\achbotMessage('payment.pre_payment.alert.fa55f5201f'),true);
        return;
    }
    if(!$catalog){
        alert(\achbotMessage('renewal.extensions.alert.6732b29e55'));
        exit;
    }
    $keyboard = [];
    foreach ($catalog as $cat){
        if (achbotWholeServiceUnits($cat['volume'],achbotServiceDaysMax())===null) continue;
        $id = $cat['id'];
        $title = $cat['volume'];
        $price = $cat['price'];
        $price=achbotExistingServicePrice($userInfo,$botState,$orderInfo,(int)$price);
        if($price == 0) $price = \achbotMessage('renewal.extensions.price.dda7ff2974');
        else $price = (string)$price . \achbotMessage('renewal.extensions.text.2cc98f7f7c');
        $keyboard[] = [['text' => \achbotMessage('renewal.extensions.button.fbb9f61105', ['title' => $title, 'price' => $price]), 'callback_data' => "selectPlanDayIncrease{$match[1]}_$id"]];
    }
    $keyboard[] = [['text' => \achbotMessage('renewal.extensions.button.8405a4154f'), 'callback_data' => "orderDetails{$match[1]}"]];
    editText($message_id, \achbotMessage('renewal.extensions.message.c2bde2a3e0'), json_encode([
            'inline_keyboard' => $keyboard
        ]));
}
if(preg_match('/\AselectPlanDayIncrease(?<orderId>[1-9][0-9]{0,9})_(?<dayId>[1-9][0-9]{0,9})\z/D',$data,$match)){
    $data = str_replace('selectPlanDayIncrease','',$data);
    $pid = $match['dayId'];
    $stmt = $connection->prepare("SELECT * FROM `increase_day` WHERE `id` = ?");
    $stmt->bind_param("i", $pid);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $quotedUnits=$res?achbotWholeServiceUnits($res['volume'],achbotServiceDaysMax()):null;
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

    try {
        if (achbotManagedServicePlan($connection,$orderInfo,'ADD_TIME',$botState)===null)
            throw new DomainException('managed_service_unavailable');
        achbotAddonSchema($connection);
        $addonContext=achbotAddonContext($connection,$orderInfo);
        if (!achbotOrderAddonEligibility($connection,$orderInfo,'day',$addonContext)['eligible']) {
            alert(\achbotMessage('payment.pre_payment.alert.fa55f5201f'),true);
            return;
        }
        $res=achbotAddonOfferById($connection,'day',$addonContext,(int)$pid);
        if (!$res) throw new RuntimeException('addon_offer_not_applicable');
        $quotedUnits=$res['volume'];$planprice=$res['price'];
    } catch (Throwable $error) {
        alert(\achbotMessage('payment.pre_payment.alert.fa55f5201f'),true);
        return;
    }

    $planprice=achbotExistingServicePrice($userInfo,$botState,$orderInfo,(int)$planprice);

    
    $hash_id = RandomString();

	
    $time = time();
    $plan_id = $orderInfo['fileid'];
	$server_id = $orderInfo['server_id'];
	$order_id = $match['orderId'];

    $type = "INCREASE_DAY_$data";
    $addonQuote=achbotAddonQuote($orderInfo,$addonContext,'day',$res,(int)$planprice);
    $stmt=$connection->prepare("INSERT INTO pays (hash_id,user_id,type,plan_id,server_id,order_id,volume,day,price,request_date,state,addon_scope_snapshot)
        VALUES (?,?,?,?,?,?,'0',?,?,?,'pending',?)");
    $stmt->bind_param("sisiiiiiis",$hash_id,$from_id,$type,$plan_id,$server_id,$order_id,$quotedUnits,$planprice,$time,$addonQuote);
    $newDraftId=achbotPayUxInsertDraft($connection,(int)$from_id,$stmt,achbotAgencyQuotePolicy($userInfo,$botState,(int)$plan_id,(int)$server_id),true);
    $stmt->close();
    if ($newDraftId<=0) {
        alert(\achbotMessage('renewal.extensions.alert.ae271a2d1d'),true);
        return;
    }


    $free=(int)$planprice===0;
    require_once __DIR__.'/../../Purchase/CheckoutPresentation.php';
    $methods=$free?[]:array_keys(achbotCheckoutDraftPaymentOptions($connection,$newDraftId,(int)$from_id));
    $keyboard=achbotCheckoutPaymentKeyboard('day',$hash_id,(int)$planprice,$methods,$botUrl);
    $keyboard[] = [['text'=>\achbotMessage('legacy_buttons.cancel'), 'callback_data'=> "mainMenu"]];
    $sent=sendMessage($free?achbotMessage('renewal.free_confirmation.prompt'):\achbotMessage('renewal.extensions.message.48930749a9'),
        json_encode(['inline_keyboard'=>$keyboard]),'HTML',$from_id);
    require_once __DIR__ . '/../PaymentLifecycle.php';
    achbotPayUxTrackByHash($connection,(string)$hash_id,(int)$from_id,$sent);
}

if (is_string($data) && (str_contains($data,'WithManualUsdt') ? ($GLOBALS['achbotManualCryptoVerifiedDispatch'] ?? null) === $data : strlen($data) <= 64) && preg_match('/\ApayIncreaseDayWith(?:CartToCart|GiftCard|ManualUsdt)([A-Za-z0-9_-]{1,50})\z/D', $data, $match)) {

    $payInfo = dbFetchPayByHash($connection, $match[1]);
    if (!$payInfo) {
        prepareStartFallback();
        return '__ACHBOT_V8_GOTO_START__';
    }

    $method = str_starts_with($data, 'payIncreaseDayWithManualUsdt') ? (($GLOBALS['achbotManualCryptoChoice'] ?? '') === 'TRON' ? 'manual_trx' : 'manual_usdt') :
        (str_starts_with($data, 'payIncreaseDayWithGiftCard') ? 'gift_card' : 'bank_transfer');
    if (!achbotManualClaim($connection, $payInfo, $method, (int)$from_id,
                           $GLOBALS['achbotManualCryptoChoice'] ?? null,'day')) {
        alert(\achbotMessage('renewal.extensions.alert.1a885aaca4'), true);
        return;
    }

	$msg = buildCartToCartInfoMessage($paymentKeys, (int)$payInfo['price'], (int)$payInfo['user_id'], $method, (string)$payInfo['hash_id']);
    if ($msg === "") {
        alert(\achbotMessage('renewal.extensions.alert.07ee06b4bc'), true);
        return;
    }
    $keyboard = achbotManualInvoiceKeyboard($connection,$payInfo);

    $delivered = sendMessage($msg, $keyboard, "HTML");
    require_once __DIR__ . '/../PaymentLifecycle.php';
    achbotPayUxTrackByHash($connection,(string)$payInfo['hash_id'],(int)$from_id,$delivered);
    if (function_exists('achbotApiPaymentTrackInvoice')) achbotApiPaymentTrackInvoice($connection,(string)$payInfo['hash_id'],(int)$from_id,$delivered);
    if (is_object($delivered) && !empty($delivered->ok)) {
    setUser("payIncreaseDayWith" . (in_array($method, ['manual_usdt','manual_trx'], true) ? 'ManualUsdt' : ($method === 'gift_card' ? 'GiftCard' : 'CartToCart')) . $match[1]);
        delMessage();
    } else {
        // The pinned attempt may be reopened. Never erase the selection menu
        // if Telegram failed to deliver the replacement instructions.
        alert(\achbotMessage('renewal.extensions.alert.7a9c30e938'), true);
    }
    exit;
}

if (preg_match('/\Asend(?:IncreaseDay|IncreaseDayGift|IncreaseDayUsdt)Receipt(?<hashId>[A-Za-z0-9_-]{1,50})\z/D', $data, $match)) {

    $payInfo = dbFetchPayByHash($connection, $match['hashId']);
    if (!$payInfo) {
        prepareStartFallback();
        return '__ACHBOT_V8_GOTO_START__';
    }

    $method = strpos($data, 'UsdtReceipt') !== false ? achbotManualCryptoReceiptProviderByHash($connection, (string)$match['hashId']) :
        (strpos($data, 'GiftReceipt') !== false ? 'gift_card' : 'bank_transfer');
    if (!achbotManualReceiptAllowed($connection, $payInfo, $method, (int)$from_id,'day')) {
        alert(\achbotMessage('renewal.extensions.alert.d06bc731eb'), true);
        return;
    }
    $guardMsg = duplicateReceiptGuard($connection, $payInfo, time());
    if ($guardMsg) { alert($guardMsg, true); exit; }

    // Explicit user intent to send a receipt, not a claim of paid funds.
    // Serialize with the six-hour unstarted-invoice expiry sweep.
    require_once __DIR__ . '/../PaymentLifecycle.php';
    if (!achbotPayUxStartManualReceipt($connection,(int)$payInfo['id'],
                                      $method,(int)$from_id,'day')) {
        alert(\achbotMessage('renewal.extensions.alert.560f05f822'),true);
        return;
    }

    delMessage();
    setUser((in_array($method, ['manual_usdt','manual_trx'], true) ? "waitIncreaseDayUsdtReceipt" : ($method === 'gift_card' ? "waitIncreaseDayGiftReceipt" : "waitIncreaseDayReceipt")) . $match['hashId']);

    $keyboard = kbOnlyCancel($payInfo);
    $sent=sendMessage(achbotManualEvidencePrompt($connection,$payInfo,$method), $keyboard, "HTML");
    require_once __DIR__ . '/../PaymentLifecycle.php';
    achbotPayUxTrackByHash($connection,(string)$match['hashId'],(int)$from_id,$sent);
    exit;
}

if (preg_match('/\AcancelIncreaseDayReceipt(?<hashId>[A-Za-z0-9_-]{1,50})\z/D', $data, $match)) {
    handleCancelFlow( $removeKeyboard);
}

if (preg_match('/\Await(?:IncreaseDay|IncreaseDayGift|IncreaseDayUsdt)Receipt([A-Za-z0-9_-]{1,50})\z/D', $userInfo['step'], $match)) {

    $method = strpos($userInfo['step'], 'UsdtReceipt') !== false ? achbotManualCryptoReceiptProviderByHash($connection, (string)$match[1]) :
        (strpos($userInfo['step'], 'GiftReceipt') !== false ? 'gift_card' : 'bank_transfer');
    $receiptPay = dbFetchPayByHash($connection, $match[1]);
    if (!$receiptPay || !achbotManualReceiptAllowed($connection, $receiptPay, $method, (int)$from_id,'day')) {
        setUser();
        alert(\achbotMessage('renewal.extensions.alert.882596d0f1'), true);
        return;
    }
    $fileid = achbotManualEvidenceInput($update, $connection, $receiptPay, $method);
    if ($fileid === null) {
        $keyboard = kbOnlyCancel($receiptPay);
        sendMessage(achbotManualEvidencePrompt($connection,$receiptPay,$method,true), $keyboard, "HTML");
        exit;
    }


    $payInfoRes=$receiptPay;
    $original=achbotManualOriginalServiceQuote($connection,$receiptPay,(int)$from_id,'day');
    if (!$original) {alert(achbotMessage('renewal.extensions.alert.882596d0f1'),true);return;}
    $remark=$original['remark'];$price=$original['price'];$volume=$original['days'];

    $msg = str_replace(
        ['INCREASE', 'TYPE', "USER-ID", "USERNAME", "NAME", "PRICE", "REMARK"],
        [$volume, \achbotMessage('renewal.extensions.text.9699c34220'), $from_id, $username, $first_name, $price, $remark],
        achbotManualCaption($connection, $receiptPay, \achbotMessage('legacy_messages.increase_account_request_message'))
    );

    // The code is checked against this ORIGINAL pinned invoice before any
    // staff message or durable receipt lease. A definite mismatch stays here.
    $giftScreen=achbotManualGiftSubmissionPreflight($connection,$receiptPay,$method,$fileid);
    if (!$giftScreen['accepted']) {
        sendMessage(achbotManualGiftRetryMessage((string)$giftScreen['reason']),
            kbOnlyCancel($receiptPay), 'HTML');
        exit;
    }
    if(achbotManualTypedGift($connection,$receiptPay,$method) &&
        !achbotManualGiftReviewFits($msg,$fileid,$giftScreen)){
        sendMessage(achbotMessage('payment.api_payment.code_too_long'),null,'HTML');return;
    }
    $deliveryToken = achbotManualBeginEvidence($connection, $receiptPay, $method, (int)$from_id, $fileid,'day');
    if ($deliveryToken === null) {
        alert(\achbotMessage('renewal.extensions.alert.795c4bddb8'), true);
        return;
    }

    $adminKeyboard = buildAdminKeyboardWithStats(
        $connection,
        "approveIncreaseDay{$match[1]}",
        "declineAction_{$match[1]}",
        $match[1],
        (int)$payInfoRes['user_id']
    );

    if (sendReceiptToAdminsAndUpdatePay($connection, $fileid,
        $msg, $adminKeyboard, $admin, $match[1],
        $deliveryToken,$giftScreen)) {
        setUser();
        achbotManualAcknowledgeAndVerify($connection,$receiptPay,$method,\achbotMessage('legacy_buttons.start_bot'),$botUsername);
    } else {
        sendMessage(\achbotMessage('renewal.extensions.message.c346a0d62e'), null, 'HTML');
    }
    exit;
}

if (preg_match('/^approveIncreaseDay(.*)/', $data, $match) && achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'payments')) {
    require_once __DIR__.'/../CheckoutRoutes.php';
    achbotCheckoutApprovalCallback($connection,(string)$match[1],(int)$from_id,(int)$admin,'day');
    return;
}

if(is_string($data) && strlen($data) <= 64 && preg_match('/\ApayIncreaseDayWithWallet([A-Za-z0-9_-]{1,50})\z/D', $data, $match)){
    require_once __DIR__.'/../CheckoutRoutes.php';
    achbotCheckoutWalletCallback($connection,(string)$match[1],(int)$from_id,'day',$botState);
    return;
}

if (is_string($data) && preg_match('/^changePlan_([1-9][0-9]{0,9})$/D', $data, $match)) {
    require_once __DIR__.'/../../Subscription/AccountDetails.php';
    $orderId=(int)$match[1];
    try {
        $actor=\AchBot\Subscription\AccountDetails\currentOrderDetailActor();
        if ($actor===null || $orderId>2147483647) throw new RuntimeException('plan_actor_unavailable');
        $actorId=(int)$actor['userid'];
        $isAdmin=achbotAdminCanFor($connection,$actorId,(int)$admin,'orders');
        if (!$isAdmin && ($botState['changePlanState'] ?? 'on')!=='on')
            throw new RuntimeException('customer_plan_disabled');
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
            if (achbotPaidPlanForOrder($connection,$orderInfo,$planId)===null) continue;
            $buttons[]=[['text'=>$plan['title'],'callback_data'=>'confirmChangePlan_'.$orderId.'_'.$planId]];
        }
        $buttons[]=[['text'=>\achbotMessage('legacy_buttons.back_button'),'callback_data'=>'mainMenu']];
        if (count($buttons)===1) sendMessage(\achbotMessage('renewal.extensions.message.655234a584'), null, 'HTML');
        else sendMessage(\achbotMessage('renewal.extensions.message.143c7f81f1'),json_encode(['inline_keyboard'=>$buttons]),'HTML');
    } catch (Throwable $error) {
        error_log('achbot_plan_selection_unavailable order_id='.$orderId);
        alert(\achbotMessage('renewal.extensions.plan_selection_unavailable'),true);
    }
    return;
}

if (is_string($data) && preg_match('/^confirmChangePlan_([1-9][0-9]{0,9})_([1-9][0-9]{0,9})$/D', $data, $match)) {
    require_once __DIR__.'/../../Subscription/AccountDetails.php';
    $orderId=(int)$match[1];$newPlanId=(int)$match[2];
    require_once __DIR__.'/../../Subscription/PhysicalService.php';
    try {
        $actor=\AchBot\Subscription\AccountDetails\currentOrderDetailActor();
        if ($actor===null || $orderId>2147483647 || $newPlanId>2147483647)
            throw new RuntimeException('plan_actor_unavailable');
        $actorId=(int)$actor['userid'];
        $s=$connection->prepare('SELECT * FROM orders_list WHERE id=? AND status=1');
        $s->bind_param('i',$orderId);$s->execute();$orderInfo=$s->get_result()->fetch_assoc();$s->close();
        if (!$orderInfo) throw new RuntimeException('plan_order_unavailable');
        $isAdmin=achbotAdminCanFor($connection,$actorId,(int)$admin,'orders');
        if (!$isAdmin && ($botState['changePlanState'] ?? 'on')!=='on')
            throw new RuntimeException('customer_plan_disabled');
        if (!$isAdmin && (int)$orderInfo['userid']!==$actorId)
            throw new RuntimeException('plan_actor_denied');
        if (!achbotOrderMayUsePaidPlan($connection,$orderInfo))
            throw new RuntimeException('plan_order_unavailable');
        $plan=achbotPaidPlanForOrder($connection,$orderInfo,$newPlanId);
        if ($plan===null) throw new RuntimeException('plan_target_unavailable');
        $result=achbotPhysicalSelectPlan($connection,$orderId,$newPlanId,$actorId,$isAdmin);
        if (!$result['success']) throw new RuntimeException($result['reason']);
        $shared=(int)$result['shared_count'];
    } catch (Throwable $error) {
        $reason=(string)$error->getMessage();
        error_log('achbot_plan_selection_unverified order_id='.$orderId.
            ' reason='.(preg_match('/\A[a-z0-9_]{5,100}\z/D',$reason)?$reason:'internal_exception'));
        alert(\achbotMessage('renewal.extensions.plan_selection_review'),true);
        return;
    }
    achbotOperationalReportDispatch($connection,$result['report_ref']);
    editText($message_id,\achbotMessage('renewal.extensions.message.3067ddba76'));
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


    try {
        if (achbotManagedServicePlan($connection,$orderInfo,'ADD_VOLUME',$botState)===null)
            throw new DomainException('managed_service_unavailable');
        achbotAddonSchema($connection);
        $addonContext=achbotAddonContext($connection,$orderInfo);
        $eligibility=achbotOrderAddonEligibility($connection,$orderInfo,'volume',$addonContext);
        if (!$eligibility['eligible']) {
            alert(\achbotMessage($eligibility['reason']==='expired'
                ? 'renewal.extensions.alert.96762b5a42' : 'payment.pre_payment.alert.fa55f5201f'),true);
            return;
        }
        $catalog=achbotAddonCatalog($connection,'volume',$addonContext);
    } catch(Throwable $error) {
        alert(\achbotMessage('payment.pre_payment.alert.fa55f5201f'),true);
        return;
    }
    if (!$catalog) {
        alert(\achbotMessage('renewal.extensions.alert.e6c27b8039'));
        exit;
    }
    $keyboard = [];
    foreach ($catalog as $cat) {
        if ((int)$cat['price']<0 || achbotWholeServiceUnits($cat['volume'],achbotServiceVolumeMax())===null) continue;
        $id = $cat['id'];
        $title = $cat['volume'];
        $price = $cat['price'];
        $price=achbotExistingServicePrice($userInfo,$botState,$orderInfo,(int)$price);
        if ($price == 0)
            $price = \achbotMessage('renewal.extensions.price.dda7ff2974');
        else
            $price = (string)$price . \achbotMessage('renewal.extensions.text.2cc98f7f7c');

        $keyboard[] = [['text' => \achbotMessage('renewal.extensions.button.249377f2b7', ['title' => $title, 'price' => $price]), 'callback_data' => "increaseVolumePlan{$match[1]}_{$id}"]];
    }
    $keyboard[] = [['text' => \achbotMessage('renewal.extensions.button.8405a4154f'), 'callback_data' => "orderDetails{$match[1]}"]];
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

    try {
        if (achbotManagedServicePlan($connection,$orderInfo,'ADD_VOLUME',$botState)===null)
            throw new DomainException('managed_service_unavailable');
        achbotAddonSchema($connection);
        $addonContext=achbotAddonContext($connection,$orderInfo);
        $eligibility=achbotOrderAddonEligibility($connection,$orderInfo,'volume',$addonContext);
        if (!$eligibility['eligible']) {
            alert(\achbotMessage($eligibility['reason']==='expired'
                ? 'renewal.extensions.alert.f5aab191db' : 'payment.pre_payment.alert.fa55f5201f'),true);
            return;
        }
        $res=achbotAddonOfferById($connection,'volume',$addonContext,(int)$match['volumeId']);
        if (!$res) throw new RuntimeException('addon_offer_not_applicable');
        $planprice=$res['price'];$plangb=$res['volume'];
    } catch(Throwable $error) {
        alert(\achbotMessage('payment.pre_payment.alert.fa55f5201f'),true);
        return;
    }
 
    $planprice=achbotExistingServicePrice($userInfo,$botState,$orderInfo,(int)$planprice);

    $hash_id = RandomString();


    $time = time();
    $plan_id = $orderInfo['fileid'];
	$server_id = $orderInfo['server_id'];
	$order_id = $match['orderId'];
	
    $type = "INCREASE_VOLUME_$data";
    $addonQuote=achbotAddonQuote($orderInfo,$addonContext,'volume',$res,(int)$planprice);
    $stmt=$connection->prepare("INSERT INTO pays (hash_id,user_id,type,plan_id,server_id,order_id,volume,day,price,request_date,state,addon_scope_snapshot)
        VALUES (?,?,?,?,?,?,?,'0',?,?,'pending',?)");
    $stmt->bind_param("sisiiiiiis",$hash_id,$from_id,$type,$plan_id,$server_id,$order_id,$plangb,$planprice,$time,$addonQuote);
    $newDraftId=achbotPayUxInsertDraft($connection,(int)$from_id,$stmt,achbotAgencyQuotePolicy($userInfo,$botState,(int)$plan_id,(int)$server_id),true);
	$stmt->close();
	if ($newDraftId<=0) {
	    alert(\achbotMessage('renewal.extensions.alert.ae271a2d1d'),true);
	    return;
	}

    $free=(int)$planprice===0;
    require_once __DIR__.'/../../Purchase/CheckoutPresentation.php';
    $methods=$free?[]:array_keys(achbotCheckoutDraftPaymentOptions($connection,$newDraftId,(int)$from_id));
    $keyboard=achbotCheckoutPaymentKeyboard('volume',$hash_id,(int)$planprice,$methods,$botUrl);
    $keyboard[] = [['text'=>\achbotMessage('legacy_buttons.cancel'), 'callback_data'=> "mainMenu"]];
    $sent=sendMessage($free?achbotMessage('renewal.free_confirmation.prompt'):\achbotMessage('renewal.extensions.message.48930749a9'),
        json_encode(['inline_keyboard'=>$keyboard]),'HTML',$from_id);
    require_once __DIR__ . '/../PaymentLifecycle.php';
    achbotPayUxTrackByHash($connection,(string)$hash_id,(int)$from_id,$sent);
}

if (is_string($data) && (str_contains($data,'WithManualUsdt') ? ($GLOBALS['achbotManualCryptoVerifiedDispatch'] ?? null) === $data : strlen($data) <= 64) && preg_match('/\ApayIncreaseWith(?:CartToCart|GiftCard|ManualUsdt)([A-Za-z0-9_-]{1,50})\z/D', $data, $match)) {

    $payInfo = dbFetchPayByHash($connection, $match[1]);
    if (!$payInfo) {
        prepareStartFallback();
        return '__ACHBOT_V8_GOTO_START__';
    }

    $method = str_starts_with($data, 'payIncreaseWithManualUsdt') ? (($GLOBALS['achbotManualCryptoChoice'] ?? '') === 'TRON' ? 'manual_trx' : 'manual_usdt') :
        (str_starts_with($data, 'payIncreaseWithGiftCard') ? 'gift_card' : 'bank_transfer');
    if (!achbotManualClaim($connection, $payInfo, $method, (int)$from_id,
                           $GLOBALS['achbotManualCryptoChoice'] ?? null,'volume')) {
        alert(\achbotMessage('renewal.extensions.alert.1a885aaca4'), true);
        return;
    }

	$msg = buildCartToCartInfoMessage($paymentKeys, (int)$payInfo['price'], (int)$payInfo['user_id'], $method, (string)$payInfo['hash_id']);
    if ($msg === "") {
        alert(\achbotMessage('renewal.extensions.alert.07ee06b4bc'), true);
        return;
    }
    $keyboard = achbotManualInvoiceKeyboard($connection,$payInfo);

    $delivered = sendMessage($msg, $keyboard, "HTML");
    require_once __DIR__ . '/../PaymentLifecycle.php';
    achbotPayUxTrackByHash($connection,(string)$payInfo['hash_id'],(int)$from_id,$delivered);
    if (function_exists('achbotApiPaymentTrackInvoice')) achbotApiPaymentTrackInvoice($connection,(string)$payInfo['hash_id'],(int)$from_id,$delivered);
    if (is_object($delivered) && !empty($delivered->ok)) {
    setUser("payIncreaseWith" . (in_array($method, ['manual_usdt','manual_trx'], true) ? 'ManualUsdt' : ($method === 'gift_card' ? 'GiftCard' : 'CartToCart')) . $match[1]);
        delMessage();
    } else {
        // The pinned attempt may be reopened. Never erase the selection menu
        // if Telegram failed to deliver the replacement instructions.
        alert(\achbotMessage('renewal.extensions.alert.7a9c30e938'), true);
    }
    exit;
}

if (preg_match('/\Asend(?:IncreaseVolume|IncreaseVolumeGift|IncreaseVolumeUsdt)Receipt(?<hashId>[A-Za-z0-9_-]{1,50})\z/D', $data, $match)) {

    $payInfo = dbFetchPayByHash($connection, $match['hashId']);
    if (!$payInfo) {
        prepareStartFallback();
        return '__ACHBOT_V8_GOTO_START__';
    }

    $method = strpos($data, 'UsdtReceipt') !== false ? achbotManualCryptoReceiptProviderByHash($connection, (string)$match['hashId']) :
        (strpos($data, 'GiftReceipt') !== false ? 'gift_card' : 'bank_transfer');
    if (!achbotManualReceiptAllowed($connection, $payInfo, $method, (int)$from_id,'volume')) {
        alert(\achbotMessage('renewal.extensions.alert.d06bc731eb'), true);
        return;
    }
    $guardMsg = duplicateReceiptGuard($connection, $payInfo, time());
    if ($guardMsg) { alert($guardMsg, true); exit; }

    // Explicit user intent to send a receipt, not a claim of paid funds.
    // Serialize with the six-hour unstarted-invoice expiry sweep.
    require_once __DIR__ . '/../PaymentLifecycle.php';
    if (!achbotPayUxStartManualReceipt($connection,(int)$payInfo['id'],
                                      $method,(int)$from_id,'volume')) {
        alert(\achbotMessage('renewal.extensions.alert.560f05f822'),true);
        return;
    }

    delMessage();
    setUser((in_array($method, ['manual_usdt','manual_trx'], true) ? "waitIncreaseVolumeUsdtReceipt" : ($method === 'gift_card' ? "waitIncreaseVolumeGiftReceipt" : "waitIncreaseVolumeReceipt")) . $match['hashId']);

    $keyboard = kbOnlyCancel($payInfo);
    $sent=sendMessage(achbotManualEvidencePrompt($connection,$payInfo,$method), $keyboard, "HTML");
    require_once __DIR__ . '/../PaymentLifecycle.php';
    achbotPayUxTrackByHash($connection,(string)$match['hashId'],(int)$from_id,$sent);
    exit;
}

if (preg_match('/\AcancelIncreaseVolumeReceipt(?<hashId>[A-Za-z0-9_-]{1,50})\z/D', $data, $match)) {
    handleCancelFlow( $removeKeyboard);
}

if (preg_match('/\Await(?:IncreaseVolume|IncreaseVolumeGift|IncreaseVolumeUsdt)Receipt([A-Za-z0-9_-]{1,50})\z/D', $userInfo['step'], $match)) {

    $method = strpos($userInfo['step'], 'UsdtReceipt') !== false ? achbotManualCryptoReceiptProviderByHash($connection, (string)$match[1]) :
        (strpos($userInfo['step'], 'GiftReceipt') !== false ? 'gift_card' : 'bank_transfer');
    $receiptPay = dbFetchPayByHash($connection, $match[1]);
    if (!$receiptPay || !achbotManualReceiptAllowed($connection, $receiptPay, $method, (int)$from_id,'volume')) {
        setUser();
        alert(\achbotMessage('renewal.extensions.alert.882596d0f1'), true);
        return;
    }
    $fileid = achbotManualEvidenceInput($update, $connection, $receiptPay, $method);
    if ($fileid === null) {
        $keyboard = kbOnlyCancel($receiptPay);
        sendMessage(achbotManualEvidencePrompt($connection,$receiptPay,$method,true), $keyboard, "HTML");
        exit;
    }


    $payParam=$receiptPay;
    $original=achbotManualOriginalServiceQuote($connection,$receiptPay,(int)$from_id,'volume');
    if (!$original) {alert(achbotMessage('renewal.extensions.alert.882596d0f1'),true);return;}
    $remark=$original['remark'];$price=$original['price'];$volume=$original['volume'];

    $msg = str_replace(
        ['INCREASE', 'TYPE', "USER-ID", "USERNAME", "NAME", "PRICE", "REMARK"],
        [$volume, \achbotMessage('renewal.extensions.text.4123904480'), $from_id, $username, $first_name, $price, $remark],
        achbotManualCaption($connection, $receiptPay, \achbotMessage('legacy_messages.increase_account_request_message'))
    );

    // The code is checked against this ORIGINAL pinned invoice before any
    // staff message or durable receipt lease. A definite mismatch stays here.
    $giftScreen=achbotManualGiftSubmissionPreflight($connection,$receiptPay,$method,$fileid);
    if (!$giftScreen['accepted']) {
        sendMessage(achbotManualGiftRetryMessage((string)$giftScreen['reason']),
            kbOnlyCancel($receiptPay), 'HTML');
        exit;
    }
    if(achbotManualTypedGift($connection,$receiptPay,$method) &&
        !achbotManualGiftReviewFits($msg,$fileid,$giftScreen)){
        sendMessage(achbotMessage('payment.api_payment.code_too_long'),null,'HTML');return;
    }
    $deliveryToken = achbotManualBeginEvidence($connection, $receiptPay, $method, (int)$from_id, $fileid,'volume');
    if ($deliveryToken === null) {
        alert(\achbotMessage('renewal.extensions.alert.795c4bddb8'), true);
        return;
    }

    $adminKeyboard = buildAdminKeyboardWithStats(
        $connection,
        "approveIncreaseVolume{$match[1]}",
        "declineAction_{$match[1]}",
        $match[1],
        (int)$payParam['user_id']
    );

    if (sendReceiptToAdminsAndUpdatePay($connection, $fileid,
        $msg, $adminKeyboard, $admin, $match[1],
        $deliveryToken,$giftScreen)) {
        setUser();
        achbotManualAcknowledgeAndVerify($connection,$receiptPay,$method,\achbotMessage('legacy_buttons.start_bot'),$botUsername);
    } else {
        sendMessage(\achbotMessage('renewal.extensions.message.c346a0d62e'), null, 'HTML');
    }
    exit;
}

if (preg_match('/^approveIncreaseVolume(.*)/', $data, $match) && achbotAdminCanFor($connection,(int)$from_id,(int)$admin,'payments')) {
    require_once __DIR__.'/../CheckoutRoutes.php';
    achbotCheckoutApprovalCallback($connection,(string)$match[1],(int)$from_id,(int)$admin,'volume');
    return;
}

if(is_string($data) && strlen($data) <= 64 && preg_match('/\ApayIncreaseWithWallet([A-Za-z0-9_-]{1,50})\z/D', $data, $match)){
    require_once __DIR__.'/../CheckoutRoutes.php';
    achbotCheckoutWalletCallback($connection,(string)$match[1],(int)$from_id,'volume',$botState);
    return;
}
