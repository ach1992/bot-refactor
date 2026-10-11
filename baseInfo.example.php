<?php
// Local-only secrets and deployment settings. Copy to baseInfo.php and fill in locally.
// Never commit baseInfo.php or production credentials.
error_reporting(0);
$botToken = 'REPLACE_WITH_TELEGRAM_BOT_TOKEN';
$dbUserName = 'REPLACE_WITH_DB_USER';
$dbPassword = 'REPLACE_WITH_DB_PASSWORD';
$dbName = 'REPLACE_WITH_EXISTING_DB_NAME';
$botUrl = 'https://example.invalid/your/bot';
$admin = 0;
$admins_list = [0];
$special_admins = [0];
$botUsername = 'REPLACE_WITH_BOT_USERNAME';
$hideConnectLinkForMarzban = false;
$showIndexes = [2, 4, 5, 6, 7, 10, 12, 16];
$hideConfigIfMany = false;
$linkIndices = [2, 4, 6, 14];
$inviteReward = 0;
$joinLink = 'https://t.me/REPLACE_WITH_PRIVATE_CHANNEL';
$USDTPrice = '0';
$USDTPriceagent = '0';
?>
