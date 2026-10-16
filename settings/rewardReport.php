<?php

include_once __DIR__ . '/../baseInfo.php';
include_once __DIR__ . '/../config.php';

$sellState=$botState['sellState']=="off"?"خاموش ❌":"روشن ✅";
$searchState=$botState['searchState']=="off"?"خاموش ❌":"روشن ✅";
$rewaredTime = ($botState['rewaredTime']??0);
$rewaredChannel = $botState['rewardChannel'];

if($rewaredTime>0 && $rewaredChannel != null){
    $lastTime = $botState['lastRewardMessage']??0;
    if(time() > $lastTime){
        $time = time() - ($rewaredTime * 60 * 60);
        
		$stmt = $connection->prepare("SELECT SUM(price) as total FROM `pays` WHERE `request_date` > ? AND (`state` = 'paid' OR `state` = 'approved' OR `state` = 'auto_cart_approved')");
        $stmt->bind_param("i", $time);
        $stmt->execute();
        $totalRewards = number_format($stmt->get_result()->fetch_assoc()['total']);
        $stmt->close();
        
        $botState['lastRewardMessage']=time() + ($rewaredTime * 60 * 60);
        
        $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'BOT_STATES'");
        $stmt->execute();
        $isExists = $stmt->get_result();
        $stmt->close();
		
        if($isExists->num_rows>0) $query = "UPDATE `setting` SET `value` = ? WHERE `type` = 'BOT_STATES'";
        else $query = "INSERT INTO `setting` (`type`, `value`) VALUES ('BOT_STATES', ?)";
        $newData = json_encode($botState);
        
        $stmt = $connection->prepare($query);
        $stmt->bind_param("s", $newData);
        $stmt->execute();
        $stmt->close();

        $txt = "
🔰 فروش من در $rewaredTime ساعت گذشته

💰 مبلغ: $totalRewards تومان

☑️ $channelLock

";
        sendMessage($txt, null, null, $rewaredChannel);
    }
}

// AChBot backup: source-only compatibility for aaPanel Cron without host PATH changes.
// The original public Cron filename and all financial/reward logic below are preserved.
require_once __DIR__ . '/../src/Backup/Database.php';
\AchBot\Backup\Database\schedule(
    $connection, $rewaredChannel, 'sendDatabaseBackup', dirname(__DIR__)
);


// Existing operator-managed Cron runner also reconciles signed NOWPayments
// status notifications. It is not a public anonymous payment trigger.
if (!defined('ACHBOT_NP_RECONCILE_ONLY')) define('ACHBOT_NP_RECONCILE_ONLY',true);
// Existing browser gateway always loads these QR/subscription helpers. Cron
// must load the same source before processing BUY_SUB or RENEW_ACCOUNT.
require_once __DIR__ . '/../buy_helpers.php';
require_once __DIR__ . '/../src/Finance/GatewayReturn.php';
$npTable=$connection->query("SHOW TABLES LIKE 'achbot_nowpayments_attempts'");
if ($npTable && $npTable->num_rows === 1) {
    achbotNpReconcileBatch($connection, is_array($paymentKeys ?? null)?$paymentKeys:[], 3);
}

require __DIR__ . '/../src/Finance/AutomaticReceipts.php';
