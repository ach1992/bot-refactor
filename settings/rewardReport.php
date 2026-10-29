<?php
// Real scheduled worker, never an anonymous HTTP trigger.
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
require_once __DIR__ . '/../messages/loader.php'; // ACHBOT_TEXT_CATALOG


include_once __DIR__ . '/../baseInfo.php';
include_once __DIR__ . '/../config.php';

$sellState=$botState['sellState']=="off"?\achbotMessage('jobs.reward_report.text.c2dd5f586f'):\achbotMessage('jobs.reward_report.text.f28e680498');
$searchState=$botState['searchState']=="off"?\achbotMessage('jobs.reward_report.text.c2dd5f586f'):\achbotMessage('jobs.reward_report.text.f28e680498');
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
        
        // BOT_STATES must be updated from its locked persisted document:
        // this Cron request may have a stale in-memory $botState. Never
        // overwrite a concurrent administrator's gateway restriction.
        $nextRewardMessage = time() + ($rewaredTime * 60 * 60);
        if (setSettings('lastRewardMessage', $nextRewardMessage)) {
            $txt = \achbotMessage('jobs.reward_report.txt.1bceb49379', ['rewaredTime' => $rewaredTime, 'totalRewards' => $totalRewards, 'channelLock' => $channelLock]);
            sendMessage($txt, null, null, $rewaredChannel);
        } else {
            // Avoid a misleading success/sent state when persistence failed.
            error_log('achbot_reward_report_settings_update_deferred');
        }
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
require_once __DIR__.'/../src/Purchase/Core.php';
require_once __DIR__ . '/../src/Finance/GatewayReturn.php';
// A partially applied 003 migration may leave attempts without the child
// transaction ledger. Skip ONLY NOWPayments work until both are available;
// automatic receipts below must still run during schema repair.
if (achbotSharedPaymentTablesReady($connection)) {
    achbotNpReconcileBatch($connection, is_array($paymentKeys ?? null)?$paymentKeys:[], 3);
    // Send bounded operator notices for unresolved payments or stalled
    // invoice creation. No money/provisioning effects are retried here.
    achbotNpAlertOperator($connection, $admin ?? null, 3);
}

require __DIR__ . '/../src/Finance/AutomaticReceipts.php';
