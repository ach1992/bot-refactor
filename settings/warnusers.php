<?php

include_once __DIR__ . '/../baseInfo.php';
include_once __DIR__ . '/../config.php';

// A single expired-account cleanup must not abort reconciliation alerts for
// unrelated reserved/uncertain paid renewals in this same Cron invocation.
try {
    require __DIR__ . '/../src/Notifications/Warnings.php';
} catch (Throwable $error) {
    error_log('achbot_warning_cleanup_deferred');
} finally {
    // Financial renewal/reconciliation has priority over optional Telegram
    // edits. Preserve the existing four-worker, one-minute Cron schedule.
    try {
        require __DIR__ . '/../src/Finance/AutomaticRenewal.php';
    } finally {
        try {
            require_once __DIR__ . '/../src/Finance/PaymentLifecycle.php';
            achbotPayUxMaintain($connection);
        } catch (Throwable $ignored) {
            error_log('achbot_payment_lifecycle_deferred');
        }
    }
}
