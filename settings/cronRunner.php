<?php
/**
 * AChBot CLI Cron coordinator.
 *
 * Optional post-V9 replacement for the FIVE EXISTING aaPanel command lines.
 * Do not enable this while those commands are still scheduled: that would run
 * every worker twice. Keep the aaPanel one-minute cadence. This entrypoint
 * owns its per-installation nonblocking flock (aaPanel's saved flock flag
 * must NOT be presumed to enforce it).
 *
 * Child scripts are independent PHP CLI processes, not includes. This preserves
 * exit, mutable global state and PHP interpreter isolation. The inherited CWD
 * matches an ordinary shell invocation from the same Cron execution context.
 * No database connection or external scheduler is created here. A private
 * system lock file holds the single-flight lease for the full five-job run.
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

// A per-installation kernel flock covers the ENTIRE five-worker batch.
// The lock remains held while any child is running; it is released by the OS
// when the coordinator terminates. Never unlink it: unlink/recreate would let
// two processes hold different inodes for the same named lock.
//
// The default /run/lock is outside the served app tree. Operators running
// Cron as a non-root account may set ACHBOT_CRON_LOCK_DIR to an EXISTING,
// writable, protected directory outside the webroot. It is never auto-created.
// Using the canonical install directory avoids cross-tenant lock collisions.
$installDirectory = realpath(dirname(__DIR__));
$requestedLockDirectory = getenv('ACHBOT_CRON_LOCK_DIR') ?: '/run/lock';
$lockDirectory = realpath($requestedLockDirectory);
$directoryStat = ($lockDirectory !== false) ? @stat($lockDirectory) : false;
if (
    $installDirectory === false
    || $lockDirectory === false
    // Reject a lock directory inside the installed (web-served) application.
    || $lockDirectory === $installDirectory
    || str_starts_with($lockDirectory, $installDirectory . DIRECTORY_SEPARATOR)
    || !is_dir($lockDirectory)
    || !is_writable($lockDirectory)
    || $directoryStat === false
    // A globally writable directory must use sticky-bit semantics.
    || (($directoryStat['mode'] & 0002) !== 0
        && ($directoryStat['mode'] & 01000) === 0)
) {
    fwrite(STDERR, "AChBot Cron: secure lock directory unavailable; no tasks started\n");
    exit(78);
}
$lockFile = $lockDirectory . DIRECTORY_SEPARATOR . 'achbot-cron-'
    . substr(hash('sha256', $installDirectory), 0, 24) . '.lock';

// Do not truncate the lock file or relax its permissions. A lock file in a
// sticky directory must belong to this execution account to avoid symlink
// replacement, cross-user lock contention and accidental public exposure.
$oldUmask = umask(0077);
$lockHandle = @fopen($lockFile, 'c');
umask($oldUmask);
if ($lockHandle === false) {
    fwrite(STDERR, "AChBot Cron: lock file could not be opened; no tasks started\n");
    exit(78);
}
$fileStat = @fstat($lockHandle);
$pathStat = @lstat($lockFile);
if (
    $fileStat === false
    || $pathStat === false
    || $fileStat['dev'] !== $pathStat['dev']
    || $fileStat['ino'] !== $pathStat['ino']
    || ($pathStat['mode'] & 0170000) !== 0100000
    || $fileStat['nlink'] !== 1
    || ($fileStat['mode'] & 0077) !== 0
    || (function_exists('posix_geteuid') && $fileStat['uid'] !== posix_geteuid())
    || !function_exists('flock')
) {
    fclose($lockHandle);
    fwrite(STDERR, "AChBot Cron: unsafe or unsupported lock file; no tasks started\n");
    exit(78);
}
$wouldBlock = 0;
if (!@flock($lockHandle, LOCK_EX | LOCK_NB, $wouldBlock)) {
    fclose($lockHandle);
    if ($wouldBlock) {
        fwrite(STDERR, "AChBot Cron: previous invocation active; skipping this tick\n");
        exit(0);
    }
    fwrite(STDERR, "AChBot Cron: could not acquire lock; no tasks started\n");
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
    // Explicit fd 3 keeps the SAME open-file-description lock in each child.
    // If the parent is killed mid-worker, that worker retains the lock until
    // its process exits; do not release it while financial effects may run.
    try {
        $process = proc_open(
            [PHP_BINARY, $file],
            [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR,
             3 => $lockHandle],
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
