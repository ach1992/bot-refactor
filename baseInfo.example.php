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
$botUsername = 'REPLACE_WITH_BOT_USERNAME';
$joinLink = 'https://t.me/REPLACE_WITH_PRIVATE_CHANNEL';

// Optional presentation policy, isolated per bot. No visible change by default.
// Use valid Custom Emoji IDs only after checking bot eligibility and Telegram.
$achbotTelegramPresentation = [
    'premium_emoji_enabled' => false,
    'premium_emoji_ids' => [],  // e.g. '✅' => 'VALID_CUSTOM_EMOJI_ID'
    'button_styles_enabled' => false,
    'button_styles' => [
        'callback_data' => [],  // e.g. 'buySubscription' => 'primary'
        'reply_text' => [],     // exact reply labels; never changes their text
    ],
];
?>
