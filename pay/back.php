<?php

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
