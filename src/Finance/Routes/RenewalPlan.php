<?php
/** AChBot V8: source-pinned financial compatibility module. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../../messages/loader.php'; // ACHBOT_TEXT_CATALOG


require_once __DIR__ . '/../PaymentLifecycle.php';

if(preg_match('/sConfigRenewPlan(\d+)_(\d+)/',$data, $match) && ($botState['sellState']=="on" ||$from_id ==$admin) && $text != $buttonValues['cancel']){
    $id = $match[1];
	$inbound_id = $match[2];
    if (!achbotSupportsPlan($connection, (int)$id, (int)$inbound_id)) {
        alert(\achbotMessage('renewal.renewal_checkout.alert.6aa152a253'), true);
        return;
    }



    if(empty($userInfo['temp'])){delMessage(); exit();}

    $configInfo = json_decode($userInfo['temp'], true);
    // A stale plan callback can point at a different Marzban host. Bind
    // the external account to the verified origin rather than trusting the
    // plan id or the user-supplied callback alone.
    $accountServer = (int)($configInfo['server_id'] ?? 0);
    if (!is_array($configInfo) || ($configInfo['marzban'] ?? null) !== true ||
        empty($configInfo['remark']) || $accountServer <= 0 ||
        !achbotSupportsPlan($connection, (int)$id, 0, $accountServer)) {
        alert(\achbotMessage('renewal.renewal_plan.alert.b1e40557e8'), true);
        return;
    }
    $uuid = $configInfo['uuid'] ?? '';
    $remark = $configInfo['remark'];

    alert($mainValues['receving_information']);
    delMessage();
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=? and `active`=1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $respd = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `id`=?");
    $stmt->bind_param("i", $respd['catid']);
    $stmt->execute();
    $catname = $stmt->get_result()->fetch_assoc()['title'];
    $stmt->close();

    $name = $catname." ".$respd['title'];
    $desc = $respd['descr'];
	$sid = $respd['server_id'];
	$keyboard = array();
    $price =  $respd['price'];
    $token = base64_encode("{$from_id}.{$id}");

    $hash_id = RandomString();
    achbotRemoveUnclaimedPendingPayments($connection,$from_id,'RENEW_SCONFIG');

    setUser('', 'temp');
    $description = json_encode(["uuid"=>$uuid, "remark"=>$remark,
                                "server_id"=>$accountServer, "marzban"=>true],488);
    $time = time();
    $stmt = $connection->prepare("INSERT INTO `pays` (`hash_id`, `description`, `user_id`, `type`, `plan_id`, `server_id`, `volume`, `day`, `price`, `request_date`, `state`)
                                VALUES (?, ?, ?, 'RENEW_SCONFIG', ?, ?, ?, '0', ?, ?, 'pending')");
    $stmt->bind_param("ssiiiiii", $hash_id, $description, $from_id, $id, $accountServer, $inbound_id, $price, $time);
    $rowId=achbotPayUxInsertDraft($connection,(int)$from_id,$stmt);
    $stmt->close();
    if ($rowId<=0) {
        alert(\achbotMessage('renewal.renewal_checkout.alert.201380d8bf'),true);
        return;
    }


    if(achbotManualEnabled('bank_transfer') && achbotPayUxAudienceAllowed($connection,'bank_transfer',(int)$from_id)) $keyboard[] = [['text' => $buttonValues['cart_to_cart'],  'callback_data' => "payBuyWithCartToCart$hash_id"]];
    if(achbotManualEnabled('gift_card') && achbotPayUxAudienceAllowed($connection,'gift_card',(int)$from_id)) $keyboard[] = [['text' => $buttonValues['gift_card'], 'callback_data' => "payBuyWithGiftCard$hash_id"]];
    if ((achbotManualCryptoEnabled() && (achbotPayUxAudienceAllowed($connection,'manual_usdt',(int)$from_id) || achbotPayUxAudienceAllowed($connection,'manual_trx',(int)$from_id)))) $keyboard[] = [['text' => \achbotMessage('renewal.extensions.button.e729457ba2'), 'callback_data' => "mcm_$hash_id"]];
    if(($botState['nowPaymentOther'] == "on" && achbotPayUxAudienceAllowed($connection,'nowpayments',(int)$from_id))) $keyboard[] = [['text' => $buttonValues['now_payment_gateway'],  'url' => $botUrl . "pay/?nowpayment&hash_id=" . $hash_id]];
    if(($botState['zarinpal'] == "on" && achbotPayUxAudienceAllowed($connection,'zarinpal',(int)$from_id))) $keyboard[] = [['text' => $buttonValues['zarinpal_gateway'],  'url' => $botUrl . "pay/?zarinpal&hash_id=" . $hash_id]];
    if(($botState['nextpay'] == "on" && achbotPayUxAudienceAllowed($connection,'nextpay',(int)$from_id))) $keyboard[] = [['text' => $buttonValues['nextpay_gateway'],  'url' => $botUrl . "pay/?nextpay&hash_id=" . $hash_id]];
    if(($botState['walletState'] == "on" && achbotPayUxAudienceAllowed($connection,'wallet',(int)$from_id))) $keyboard[] = [['text' => $buttonValues['pay_with_wallet'],  'callback_data' => "payBuyWithWallet$hash_id"]];

	$keyboard[] = [['text' => $buttonValues['back_to_main'], 'callback_data' => "mainMenu"]];
    $sent=sendMessage(str_replace(['PLAN-NAME', 'PRICE', 'DESCRIPTION'], [$name, $price, $desc], $mainValues['buy_subscription_detail']), json_encode(['inline_keyboard'=>$keyboard]), "HTML");
    require_once __DIR__ . '/../PaymentLifecycle.php';
    achbotPayUxTrackByHash($connection,(string)$hash_id,(int)$from_id,$sent);
}

