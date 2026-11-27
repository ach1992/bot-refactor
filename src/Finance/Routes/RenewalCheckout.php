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

if (preg_match('/\ArenewAccountAsk([1-9][0-9]{0,9})\z/D', $data, $match)) {
    $oid = (int)$match[1];
    $order=achbotProviderRow($connection,'SELECT * FROM orders_list WHERE id=? AND userid=?','ii',[$oid,(int)$from_id]);
    if (!$order || !achbotSupportsOrder($connection,$oid,(int)$order['fileid'],(int)$from_id) ||
        achbotManagedServicePlan($connection,$order,'RENEW',$botState)===null) {
        alert(achbotMessage('renewal.renewal_checkout.alert.6aa152a253'),true);
        return;
    }

    $keyboard = json_encode([
        'inline_keyboard' => [
            [
                ['text' => \achbotMessage('renewal.renewal_checkout.button.faa57a99b5'), 'callback_data' => "renewAccount{$oid}"]
            ],
            [
                ['text' => \achbotMessage('labels.dismiss'), 'callback_data' => "mySubscriptions"]
            ]
        ]
    ]);

    sendMessage(\achbotMessage('renewal.renewal_checkout.message.7159ade06f'), $keyboard, 'HTML');
    exit();
}

if (
    preg_match('/\ArenewAccount([1-9][0-9]{0,9})\z/D', $data, $match) &&
    $text != \achbotMessage('legacy_buttons.cancel')
) {

    // اضافه کردن بررسی برای ستون fileid
    $oid = $match[1];

    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ? AND userid=?");
    $stmt->bind_param("ii", $oid,$from_id);
    $stmt->execute();
    $order = $stmt->get_result();
    $stmt->close();

    if($order->num_rows == 0){
        delMessage();
        sendMessage(\achbotMessage('legacy_messages.config_not_found'), getMainKeys(), 'HTML');
        exit();
    }

    $order = $order->fetch_assoc();
    $fid = $order['fileid'];
    if (!achbotSupportsOrder($connection, (int)$oid, (int)$fid, (int)$order['userid'])) {
        alert(\achbotMessage('renewal.renewal_checkout.alert.6aa152a253'), true);
        return;
    }
    // بررسی اگر fileid برابر 0 باشد
    if($fid == 0){
        sendMessage(\achbotMessage('renewal.renewal_checkout.message.4c2448e6fa'), null, 'HTML');
        exit();
    }
	
	$serverId = $order['server_id'];

    $respd=achbotManagedServicePlan($connection,$order,'RENEW',$botState);
    if ($respd===null) {
        alert(achbotMessage('renewal.renewal_checkout.alert.6aa152a253'),true);
        return;
    }
    delMessage();
    $price = $respd['price'];
    $price=achbotExistingServicePrice($userInfo,$botState,$order,(int)$price);
        $hash_id = RandomString();

        
        $time = time();
		$stmt = $connection->prepare("INSERT INTO `pays` (`hash_id`, `user_id`, `type`, `plan_id`, `server_id`, `volume`, `day`, `price`, `request_date`, `state`, `order_id`)
									VALUES (?, ?, 'RENEW_ACCOUNT', ?, ?, '0', '0', ?, ?, 'pending', ?)");
		$stmt->bind_param("siiiiii", $hash_id, $from_id, $fid, $serverId, $price, $time, $oid);
        $rowId=achbotPayUxInsertDraft($connection,(int)$from_id,$stmt,achbotAgencyQuotePolicy($userInfo,$botState,(int)$fid,(int)$serverId),false,$respd,$botState);
        $stmt->close();
        if ($rowId<=0) {
            alert(\achbotMessage('purchase.catalogue.alert.201380d8bf'),true);
            return;
        }
    require_once __DIR__.'/../../Purchase/CheckoutPresentation.php';
    if (!achbotRenderNewCheckoutDraft($connection,$rowId,(int)$from_id,$botState,$botUrl))
        alert(achbotMessage('purchase.catalogue.alert.201380d8bf'),true);
    return;
}
