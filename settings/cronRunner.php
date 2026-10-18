<?php
/**
 * AChBot CLI Cron coordinator.
 *
 * Optional post-V9 replacement for the remaining four aaPanel scheduled jobs.
 * Do not enable this while those commands are still scheduled: that would run
 * every worker twice. Keep the aaPanel one-minute cadence. This entrypoint
 * owns its per-installation nonblocking flock (aaPanel's saved flock flag
 * must NOT be presumed to enforce it).
 *
 * Child scripts are independent PHP CLI processes, not includes. This preserves
 * exit, mutable global state and PHP interpreter isolation. The inherited CWD
 * matches an ordinary shell invocation from the same Cron execution context.
 * No database connection or external scheduler is created here. A private
 * system lock file holds the single-flight lease for the full four-job run.
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

// A per-installation flock covers the ENTIRE four-worker batch. Never unlink
// the lock: replacing its inode would let overlapping invocations run at once.
//
// Pre-provision this private directory OUTSIDE the served site (mode 0700,
// owned by the account executing Cron). It is not created at runtime. The
// stock /run/lock is shared/writable, so it cannot safely hold this lock.
$installDirectory = realpath(dirname(__DIR__));
$requestedLockDirectory = getenv('ACHBOT_CRON_LOCK_DIR') ?: '/var/lib/achbot/cron-locks';
$lockDirectory = realpath($requestedLockDirectory);
$effectiveUid = function_exists('posix_geteuid') ? posix_geteuid() : false;
$directoryStat = ($lockDirectory !== false) ? @lstat($lockDirectory) : false;
$trusted = (
    $installDirectory !== false
    && $lockDirectory !== false
    // Use an absolute canonical configured path: do not follow a preempted
    // directory alias in a shared ancestor into an unintended lock location.
    && rtrim($requestedLockDirectory, DIRECTORY_SEPARATOR) === $lockDirectory
    && $effectiveUid !== false
    && $directoryStat !== false
    && is_dir($lockDirectory)
    && is_writable($lockDirectory)
    && $lockDirectory !== $installDirectory
    && !str_starts_with($lockDirectory, $installDirectory . DIRECTORY_SEPARATOR)
    && $directoryStat['uid'] === $effectiveUid
    && ($directoryStat['mode'] & 0170000) === 0040000
    && ($directoryStat['mode'] & 0700) === 0700
    && ($directoryStat['mode'] & 0077) === 0
);

// Verify every canonical ancestor: only root or the Cron user may own it.
// An ancestor writable by others is trusted only if it is root-owned and
// sticky (e.g. /tmp used by isolated tests). The final lock directory is
// ALWAYS private, even when its parent is root-owned and sticky.
if ($trusted) {
    $parent = $lockDirectory;
    while (true) {
        $entry = @lstat($parent);
        if (
            $entry === false
            || ($entry['mode'] & 0170000) !== 0040000
            || ($entry['uid'] !== 0 && $entry['uid'] !== $effectiveUid)
            || (
                ($entry['mode'] & 0022) !== 0
                && !($entry['uid'] === 0 && ($entry['mode'] & 01000) !== 0)
            )
        ) {
            $trusted = false;
            break;
        }
        if ($parent === DIRECTORY_SEPARATOR) {
            break;
        }
        $parent = dirname($parent);
    }
}
if (!$trusted || !function_exists('flock')) {
    fwrite(STDERR, "AChBot Cron: secure lock directory unavailable; no tasks started\n");
    exit(78);
}
$lockFile = $lockDirectory . DIRECTORY_SEPARATOR . 'achbot-cron-'
    . substr(hash('sha256', $installDirectory), 0, 24) . '.lock';

// Never open an unvalidated special inode. A first open with "xn" creates
// atomically (O_CREAT | O_EXCL: existing/dangling symlinks are NOT followed).
// Reuse opens only an already validated regular file in the private directory,
// with "rn": read-only, no creation, and O_NONBLOCK even if the inode changes.
// A bounded exclusive-create race falls through to the existing-file path.
$lockHandle = false;
$existing = @lstat($lockFile);
if ($existing === false) {
    $oldUmask = umask(0077);
    $lockHandle = @fopen($lockFile, 'xn');
    umask($oldUmask);
}
if ($lockHandle === false) {
    clearstatcache(true, $lockFile);
    $existing = @lstat($lockFile);
    if (
        $existing === false
        || ($existing['mode'] & 0170000) !== 0100000
        || $existing['uid'] !== $effectiveUid
        || ($existing['mode'] & 0077) !== 0
        || $existing['nlink'] !== 1
    ) {
        fwrite(STDERR, "AChBot Cron: unsafe lock file; no tasks started\n");
        exit(78);
    }
    $lockHandle = @fopen($lockFile, 'rn');
}
if ($lockHandle === false) {
    fwrite(STDERR, "AChBot Cron: lock file could not be opened; no tasks started\n");
    exit(78);
}
clearstatcache(true, $lockFile);
$fileStat = @fstat($lockHandle);
$pathStat = @lstat($lockFile);
if (
    $fileStat === false
    || $pathStat === false
    || $fileStat['dev'] !== $pathStat['dev']
    || $fileStat['ino'] !== $pathStat['ino']
    || ($fileStat['mode'] & 0170000) !== 0100000
    || ($pathStat['mode'] & 0170000) !== 0100000
    || $fileStat['nlink'] !== 1
    || ($fileStat['mode'] & 0077) !== 0
    || $fileStat['uid'] !== $effectiveUid
) {
    fclose($lockHandle);
    fwrite(STDERR, "AChBot Cron: unsafe or changed lock file; no tasks started\n");
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
