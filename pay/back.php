<?php

// NOWPayments IPN must bypass Telegram update bootstrap: the callback has
// no Telegram from_id, and its 3-second delivery window must stay bounded.
if (isset($_GET['nowpayment_ipn'])) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        exit;
    }
    require_once __DIR__ . '/../baseInfo.php';
    $connection = new mysqli('localhost',$dbUserName,$dbPassword,$dbName);
    if ($connection->connect_error) {
        http_response_code(503);
        exit;
    }
    $connection->set_charset('utf8mb4');
    require_once __DIR__ . '/../src/Finance/GatewayClient.php';
    require_once __DIR__ . '/../src/Finance/GatewayReturn.php';
    exit;
}

include_once __DIR__ . '/../baseInfo.php';
include_once __DIR__ . '/../config.php';
include_once __DIR__ . '/../buy_helpers.php';

$stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'PAYMENT_KEYS'");
$stmt->execute();
$paymentKeys = $stmt->get_result()->fetch_assoc()['value'] ?? null;
if (!is_null($paymentKeys)) $paymentKeys = json_decode($paymentKeys, true);
else $paymentKeys = array();
$stmt->close();

require __DIR__ . '/../src/Finance/GatewayReturn.php';
