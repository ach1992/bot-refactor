<?php
/**
 * AChBot CLI Cron coordinator.
 *
 * Optional post-V9 replacement for the FIVE EXISTING aaPanel command lines.
 * Do not enable this while those commands are still scheduled: that would run
 * every worker twice. Keep the aaPanel one-minute cadence and job-level flock.
 *
 * Child scripts are independent PHP CLI processes, not includes. This preserves
 * exit, mutable global state and PHP interpreter isolation. The inherited CWD
 * matches an ordinary shell invocation from the same Cron execution context.
 * No new database connections, state files, locks, services or scheduling are
 * created by this coordinator itself; each existing child keeps its effects.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) {
    return; // Never start scheduled tasks merely because another CLI file includes us.
}

if ($argc !== 1) {
    fwrite(STDERR, "AChBot Cron: unexpected CLI arguments; no tasks started\n");
    exit(64);
}

if (!function_exists('proc_open') || !is_file(PHP_BINARY) || !is_executable(PHP_BINARY)) {
    fwrite(STDERR, "AChBot Cron: PHP subprocess capability unavailable; no tasks started\n");
    exit(78);
}

// Exact observed one-minute aaPanel Chili order, 2026-10-17.
// Names/paths remain stable for independently callable legacy entrypoints.
$jobs = [
    'tronChecker.php',
    'gift2all.php',
    'messagewizwiz.php',
    'rewardReport.php',
    'warnusers.php',
];

$failures = 0;
foreach ($jobs as $job) {
    $file = __DIR__ . DIRECTORY_SEPARATOR . $job;
    if (!is_file($file) || is_link($file)) {
        fwrite(STDERR, "AChBot Cron: missing or invalid worker: {$job}\n");
        ++$failures;
        continue;
    }

    // Use argv array: no shell expansion, quoting ambiguity or command injection.
    // Preserve original worker stdout/stderr in the existing aaPanel Cron log.
    // Inherit the scheduler's working directory; do NOT chdir to the bot root.
    try {
        $process = proc_open(
            [PHP_BINARY, $file],
            [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR],
            $pipes
        );
    } catch (Throwable $exception) {
        // Do not log child exceptions, private values, commands or environment.
        $process = false;
    }

    if (!is_resource($process)) {
        fwrite(STDERR, "AChBot Cron: could not start worker: {$job}\n");
        ++$failures;
        continue;
    }

    $status = proc_close($process);
    if ($status !== 0) {
        fwrite(STDERR, "AChBot Cron: worker {$job} exited with status {$status}\n");
        ++$failures;
    }
}

exit($failures === 0 ? 0 : 1);
