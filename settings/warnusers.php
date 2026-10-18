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
    require __DIR__ . '/../src/Finance/AutomaticRenewal.php';
}
