<?php
// Real scheduled worker, never an anonymous HTTP trigger.
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
require_once __DIR__ . '/../messages/loader.php'; // ACHBOT_TEXT_CATALOG


include_once __DIR__ . '/../baseInfo.php';
include_once __DIR__ . '/../config.php';

$rewaredTime = ($botState['rewaredTime']??0);
$rewaredChannel = $botState['rewardChannel']??'';

if($rewaredTime>0){
    $lastTime = $botState['lastRewardMessage']??0;
    if(time() > $lastTime){
        $time = time() - ($rewaredTime * 60 * 60);
        
        require_once __DIR__.'/../src/Finance/ServiceHistory.php';
        $totalRewards=number_format(achbotServiceSalesTotal($connection,$time+1));

        // BOT_STATES must be updated from its locked persisted document:
        // this Cron request may have a stale in-memory $botState. Never
        // overwrite a concurrent administrator's gateway restriction.
        $nextRewardMessage = time() + ($rewaredTime * 60 * 60);
        if (setSettings('lastRewardMessage', $nextRewardMessage)) {
            $txt = \achbotMessage('jobs.reward_report.txt.1bceb49379', ['rewaredTime' => $rewaredTime, 'totalRewards' => $totalRewards, 'channelLock' => $channelLock]);
            achbotNotifyAdmins('sendMessage', ['text'=>$txt], ['report_channel'=>$rewaredChannel]);
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


// Authenticated merchant reconciliation does not execute services itself.
require_once __DIR__.'/../src/Finance/CheckoutAdmission.php';
require_once __DIR__.'/../src/Finance/HostedReceipt.php';
achbotCheckoutRequireSchema($connection);
achbotHostedReceiptMaintain($connection,3);

require __DIR__ . '/../src/Finance/AutomaticReceipts.php';
