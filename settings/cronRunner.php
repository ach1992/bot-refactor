<?php
/**
 * AChBot CLI Cron coordinator.
 *
 * Executes the four current jobs in isolated CLI processes, including admitted
 * canonical dispatch modes for broadcast and service maintenance.
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

$workerMode=null;
if ($argc===2 && in_array($argv[1],['--job=broadcast','--job=services'],true))
    $workerMode=substr($argv[1],6);
elseif ($argc!==1) {
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
// Missing directories are provisioned by the top-level CLI coordinator only.
// Keep locks outside the installation and preserve every existing inode/mode.
// The stock /run/lock is shared/writable and is not a private lock directory.
$installDirectory = realpath(dirname(__DIR__));
$requestedLockDirectory = getenv('ACHBOT_CRON_LOCK_DIR') ?: '/var/lib/achbot/cron-locks';
$effectiveUid = function_exists('posix_geteuid') ? posix_geteuid() : false;
$normalizedLockDirectory = rtrim($requestedLockDirectory, DIRECTORY_SEPARATOR);
$lockDirectory = false;
$trusted = false;
$cronLockDirectoryFailure = 'invalid_absolute_path';
$cronLockFailurePath = $requestedLockDirectory;
$cronLockDirectoryTrusted = static function ($entry, bool $private = false) use ($effectiveUid): bool {
    if (!is_array($entry) || ($entry['mode'] & 0170000) !== 0040000)
        return false; // lstat rejects symlinks as well as non-directories.
    if ($private)
        return $entry['uid'] === $effectiveUid
            && ($entry['mode'] & 0700) === 0700
            && ($entry['mode'] & 0077) === 0;
    return ($entry['uid'] === 0 || $entry['uid'] === $effectiveUid)
        && (($entry['mode'] & 0022) === 0
            || ($entry['uid'] === 0 && ($entry['mode'] & 01000) !== 0));
};
if ($effectiveUid === false) {
    $cronLockDirectoryFailure = 'effective_uid_unavailable';
} elseif (!function_exists('flock')) {
    $cronLockDirectoryFailure = 'flock_unavailable';
} elseif ($installDirectory !== false
    && str_starts_with($normalizedLockDirectory, DIRECTORY_SEPARATOR)
    && !preg_match('~//|(?:^|/)\.{1,2}(?:/|$)|[\x00-\x1f\x7f]~D', $normalizedLockDirectory)
    && $normalizedLockDirectory !== $installDirectory
    && !str_starts_with($normalizedLockDirectory, $installDirectory . DIRECTORY_SEPARATOR)
) {
    clearstatcache(true, DIRECTORY_SEPARATOR);
    $trusted = $cronLockDirectoryTrusted(@lstat(DIRECTORY_SEPARATOR));
    $cronLockPreparationPath = '';
    foreach (explode(DIRECTORY_SEPARATOR, substr($normalizedLockDirectory, 1)) as $cronLockPreparationPart) {
        if (!$trusted) break;
        $cronLockPreparationPath .= DIRECTORY_SEPARATOR . $cronLockPreparationPart;
        $cronLockFailurePath = $cronLockPreparationPath;
        clearstatcache(true, $cronLockPreparationPath);
        $entry = @lstat($cronLockPreparationPath);
        if ($entry === false) {
            if ($workerMode !== null) {
                $trusted = false;
                $cronLockDirectoryFailure = 'child_cannot_create_directory';
                break;
            }
            if (!function_exists('mkdir')) {
                $trusted = false;
                $cronLockDirectoryFailure = 'mkdir_unavailable';
                break;
            }
            // Validate one parent at a time, never recursive mkdir through an
            // unchecked existing directory. Concurrent first runs may win mkdir.
            $oldUmask = umask(0077);
            try {
                @mkdir($cronLockPreparationPath, 0700);
            } finally {
                umask($oldUmask);
            }
            clearstatcache(true, $cronLockPreparationPath);
            $entry = @lstat($cronLockPreparationPath);
            if ($entry === false) {
                $trusted = false;
                $cronLockDirectoryFailure = 'cannot_create_missing_directory';
                break;
            }
        }
        $private = $cronLockPreparationPath === $normalizedLockDirectory;
        $trusted = $cronLockDirectoryTrusted($entry, $private);
        $cronLockDirectoryFailure = $private ? 'unsafe_private_directory' : 'unsafe_ancestor';
    }
    if ($trusted) {
        clearstatcache();
        $lockDirectory = realpath($normalizedLockDirectory);
        $trusted = $lockDirectory === $normalizedLockDirectory && is_writable($lockDirectory);
        $cronLockDirectoryFailure = 'noncanonical_or_unwritable_directory';
        // Revalidate the entire chain after creation, before opening a lease.
        $parent = $normalizedLockDirectory;
        while ($trusted) {
            clearstatcache(true, $parent);
            $trusted = $cronLockDirectoryTrusted(@lstat($parent), $parent === $normalizedLockDirectory);
            if (!$trusted) {
                $cronLockFailurePath = $parent;
                $cronLockDirectoryFailure = 'directory_changed_during_preparation';
                break;
            }
            if ($parent === DIRECTORY_SEPARATOR) break;
            $parent = dirname($parent);
        }
    }
}
if (!$trusted) {
    $safePath = json_encode($cronLockFailurePath, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    fwrite(STDERR, "AChBot Cron: secure lock directory unavailable (reason={$cronLockDirectoryFailure}, path={$safePath}); no tasks started\n");
    exit(78);
}
$lockFile = $lockDirectory . DIRECTORY_SEPARATOR . 'achbot-cron-'
    . substr(hash('sha256', $installDirectory), 0, 24) . '.lock';

// Never open an unvalidated special inode. A first open with "xn" creates
// atomically (O_CREAT | O_EXCL: existing/dangling symlinks are NOT followed).
// Reuse opens only an already validated regular file in the private directory,
// with "rn": read-only, no creation, and O_NONBLOCK even if the inode changes.
// A bounded exclusive-create race falls through to the existing-file path.

// Child jobs may only use the SAME trusted open-file-description lease passed
// on fd 3 by the coordinator. An independently opened file is not admission.
// Linux fdinfo reports locks attached to this descriptor's file description;
// reading it never acquires/converts/unlocks the parent's flock.
if ($workerMode!==null) {
    $lease=@fopen('php://fd/3','r');
    $leaseStat=is_resource($lease)?@fstat($lease):false;
    $pathStat=@lstat($lockFile);
    $info=function_exists('file_get_contents')?@file_get_contents('/proc/self/fdinfo/3'):false;
    $matched=is_string($info) && preg_match(
        '/^lock:\s+[0-9]+:\s+FLOCK\s+ADVISORY\s+WRITE\s+-?[0-9]+\s+[0-9a-fA-F]+:[0-9a-fA-F]+:([0-9]+)\s+0\s+EOF\s*$/mD',
        $info,$leaseMatch)===1;
    if (!$leaseStat || !$pathStat ||
        $leaseStat['dev']!==$pathStat['dev'] || $leaseStat['ino']!==$pathStat['ino'] ||
        ($leaseStat['mode']&0170000)!==0100000 || ($pathStat['mode']&0170000)!==0100000 ||
        $leaseStat['uid']!==$effectiveUid || ($leaseStat['mode']&0077)!==0 ||
        $leaseStat['nlink']!==1 || !$matched ||
        (string)$leaseMatch[1]!==(string)$leaseStat['ino']) {
        fwrite(STDERR,"AChBot Cron: trusted inherited worker lease unavailable; no tasks started\n");
        exit(78);
    }
    // No LOCK_UN here or in a child finally block. Closing one inherited
    // reference must leave the coordinator (or an orphan child) holding it.
    if ($workerMode==='broadcast') {

include_once __DIR__ . '/../baseInfo.php';
include_once __DIR__ . '/../config.php';
include_once __DIR__ . '/jdf.php';

@ignore_user_abort(true);
@set_time_limit(0);

require __DIR__ . '/../src/Jobs/Checkpoint.php';

$inlineStartKey = json_encode([
    'inline_keyboard' => [
        [[
            'text' => $buttonValues['start_bot'],
            'url'  => "https://t.me/$botUsername?start=mainMenu"
        ]]
    ]
], JSON_UNESCAPED_UNICODE);

require __DIR__ . '/../src/Jobs/MessageActions.php';


require __DIR__ . '/../src/Jobs/BroadcastSend.php';
        exit(0);
    }
    if ($workerMode==='services') {


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
            require_once __DIR__ . '/../src/Finance/ApiPayments.php';
            try {achbotApiPaymentMaintain($connection);} finally {achbotPayUxMaintain($connection);}
        } catch (Throwable $ignored) {
            error_log('achbot_payment_lifecycle_deferred');
        }
        // Issue #117: crash-after-COMMIT first delivery only.  Keep this
        // separate from provider/financial maintenance, so one unavailable
        // subsystem cannot suppress an independently sealed customer packet.
        try {
            require_once __DIR__ . '/../src/Finance/DeliveryLedger.php';
            achbotDeliveryMaintainPending($connection);
        } catch (Throwable $error) {
            error_log('achbot_pending_delivery_maintenance_deferred class='.get_class($error));
        }
    }
}
        exit(0);
    }
    exit(64);
}

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


// These are the same four business jobs in the observed Chili order.
// Relocated compositions run as admitted modes of this actual coordinator;
// no old-filename wrapper or replacement thin worker alias is retained.
$jobs=[
    ['name'=>'gift2all.php','file'=>__DIR__.'/gift2all.php','arguments'=>[]],
    ['name'=>'broadcast','file'=>__FILE__,'arguments'=>['--job=broadcast']],
    ['name'=>'rewardReport.php','file'=>__DIR__.'/rewardReport.php','arguments'=>[]],
    ['name'=>'services','file'=>__FILE__,'arguments'=>['--job=services']],
];

$failures=0;
foreach ($jobs as $job) {
    $file=$job['file'];
    if (!is_file($file) || is_link($file)) {
        fwrite(STDERR,"AChBot Cron: missing or invalid worker: {$job['name']}\n");
        ++$failures;continue;
    }
    // Preserve argv execution, CWD/environment and independent PHP processes.
    // Inherited fd 3 keeps the SAME lease through parent death; never unlink it.
    try {
        $process=proc_open(
            array_merge([PHP_BINARY,$file],$job['arguments']),
            [0=>['file','/dev/null','r'],1=>STDOUT,2=>STDERR,3=>$lockHandle],
            $pipes
        );
    } catch (Throwable $exception) {$process=false;}
    if (!is_resource($process)) {
        fwrite(STDERR,"AChBot Cron: could not start worker: {$job['name']}\n");
        ++$failures;continue;
    }
    $status=proc_close($process);
    if ($status!==0) {
        fwrite(STDERR,"AChBot Cron: worker {$job['name']} exited with status {$status}\n");
        ++$failures;
    }
}
exit($failures===0?0:1);
