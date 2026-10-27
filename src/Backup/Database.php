<?php
/**
 * AChBot automatic database-backup boundary.
 * Dump and archive are private, short-lived files outside the application tree.
 * No real backup is attempted until the existing scheduled rewardReport.php calls it.
 */
namespace AchBot\Backup\Database;

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

function reportFailure($reason, $code = null) {
    // Deliberately omit SQL, credentials, command lines, channels, paths and stderr.
    error_log('[AChBot backup] ' . $reason . ($code === null ? '' : ' status=' . (int)$code));
}

function dumpBinary() {
    $override = getenv('ACHBOT_MYSQLDUMP_BIN');
    if ($override !== false && $override !== '') {
        if ($override[0] === '/' && is_file($override) && is_executable($override)) return $override;
        reportFailure('dump_binary_override_invalid');
        return null;
    }

    $candidates = [
        '/www/server/mysql/bin/mysqldump',
        '/www/server/mysql/bin/mariadb-dump',
        '/usr/bin/mysqldump',
        '/usr/bin/mariadb-dump',
        '/usr/local/bin/mysqldump',
        '/usr/local/bin/mariadb-dump',
        '/usr/local/mysql/bin/mysqldump',
    ];
    foreach (explode(PATH_SEPARATOR, getenv('PATH') ?: '') as $directory) {
        if ($directory !== '' && $directory[0] === '/') {
            $candidates[] = rtrim($directory, '/') . '/mysqldump';
            $candidates[] = rtrim($directory, '/') . '/mariadb-dump';
        }
    }
    foreach (array_unique($candidates) as $candidate) {
        if (is_file($candidate) && is_executable($candidate)) return $candidate;
    }
    reportFailure('dump_binary_missing');
    return null;
}

function privateTempBase($applicationRoot) {
    $root = realpath($applicationRoot);
    if (!$root) throw new \RuntimeException('application_root_missing');
    foreach (array_unique([sys_get_temp_dir(), '/var/tmp', '/tmp']) as $candidate) {
        $base = realpath($candidate);
        if (!$base || !is_dir($base) || !is_writable($base)) continue;
        if ($base === $root || strpos($base, $root . DIRECTORY_SEPARATOR) === 0) continue;
        return $base;
    }
    throw new \RuntimeException('private_temp_unavailable');
}

function quotedOption($value) {
    if (!is_string($value) || strpos($value, "\0") !== false ||
        strpos($value, "\r") !== false || strpos($value, "\n") !== false) {
        throw new \RuntimeException('dump_option_invalid');
    }
    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
}

function safeRemove($path) {
    if (is_string($path) && is_file($path)) @unlink($path);
}

/**
 * Bounded-memory integrity gate for the plain mysqldump format we invoke.
 *
 * A zero exit status and nonzero byte count are not enough: e.g. a wrapper
 * can return a large error banner or end a dump early and still exit 0.
 * Check MySQL/MariaDB header, schema DDL and final completion marker before
 * any ZIP or Telegram delivery. This is NOT a full SQL parser/restore proof.
 */
function completeSqlDump($path) {
    $file = @fopen($path, 'rb');
    if (!$file) return false;
    try {
        $header = fread($file, 8192);
        if (!is_string($header) ||
            !preg_match('/(?:^|\n)-- (?:MariaDB|MySQL) dump [^\r\n]{1,240}(?:\r?\n|$)/', $header) ||
            !preg_match('/(?:^|\n)-- Host: [^\r\n]{1,240}Database: [^\r\n]{1,240}/', $header)) {
            return false;
        }

        if (fseek($file, 0, SEEK_SET) !== 0) return false;
        $tableComment = false;
        $createTable = false;
        $overlap = '';
        while (!feof($file)) {
            $chunk = fread($file, 65536);
            if ($chunk === false || ($chunk === '' && !feof($file))) return false;
            $scan = $overlap . $chunk;
            if (strpos($scan, '-- Table structure for table `') !== false) $tableComment = true;
            if (strpos($scan, 'CREATE TABLE `') !== false) $createTable = true;
            // Small overlap permits markers split across chunk boundaries.
            $overlap = substr($scan, -128);
        }
        if (!$tableComment || !$createTable) return false;

        clearstatcache(true, $path);
        $bytes = filesize($path);
        if ($bytes === false || $bytes < 1000 ||
            fseek($file, -min($bytes, 8192), SEEK_END) !== 0) return false;
        $tail = stream_get_contents($file);
        // Both stock mysql/mariadb dump clients append this default trailer.
        // MariaDB uses a space-padded hour (e.g. " 4") before 10:00;
        // accept both formats but require a valid time and EOF-anchored marker.
        return is_string($tail) &&
            preg_match('/(?:^|\r?\n)-- Dump completed on \\d{4}-\\d{2}-\\d{2} (?:[01]\\d|2[0-3]| ?[0-9]):[0-5]\\d:[0-5]\\d[ \\t]*(?:\\r?\\n)*\\z/D', $tail) === 1;
    } finally {
        fclose($file);
    }
}

function send($connection, $dbUserName, $dbPassword, $dbName, $channelId, callable $deliver, $applicationRoot) {
    if ($channelId === null || trim((string)$channelId) === '') {
        reportFailure('recipient_missing');
        return false;
    }
    $binary = dumpBinary();
    if (!$binary) return false;
    if (!function_exists('exec')) { reportFailure('exec_disabled'); return false; }
    if (!class_exists('ZipArchive')) { reportFailure('zip_extension_missing'); return false; }
    if (!is_string($dbName) || $dbName === '' || $dbName[0] === '-' ||
        strpos($dbName, "\0") !== false || strpos($dbName, "\n") !== false) {
        reportFailure('database_name_invalid');
        return false;
    }

    $previousUmask = umask(0077);
    $work = null;
    try {
        $base = privateTempBase($applicationRoot);
        $work = $base . '/achbot-backup-' . bin2hex(random_bytes(12));
        if (!mkdir($work, 0700)) throw new \RuntimeException('private_directory_create_failed');

        $conf = $work . '/client.cnf';
        $sql = $work . '/bot_backup.sql';
        $zipPath = $work . '/bot_backup.zip';
        $errors = $work . '/dump.stderr';

        $configuration = "[client]\nuser=" . quotedOption((string)$dbUserName) .
            "\npassword=" . quotedOption((string)$dbPassword) . "\nhost=localhost\n";
        if (file_put_contents($conf, $configuration, LOCK_EX) !== strlen($configuration) ||
            !chmod($conf, 0600)) throw new \RuntimeException('credentials_file_create_failed');
        unset($configuration);

        // --defaults-extra-file must be the FIRST command option. Password
        // stays in a 0600 file, not the shell command or process arguments.
        $command = escapeshellarg($binary) .
            ' --defaults-extra-file=' . escapeshellarg($conf) .
            ' --default-character-set=utf8mb4 --routines --events --triggers' .
            ' --host=localhost';
        $socket = getenv('ACHBOT_MYSQLDUMP_SOCKET');
        if ($socket !== false && $socket !== '') {
            if ($socket[0] !== '/' || !file_exists($socket)) {
                reportFailure('dump_socket_override_invalid');
                return false;
            }
            $command .= ' --socket=' . escapeshellarg($socket);
        }
        $command .= ' -- ' . escapeshellarg($dbName) .
            ' > ' . escapeshellarg($sql) . ' 2> ' . escapeshellarg($errors);
        $output = [];
        $status = 0;
        exec($command, $output, $status);
        unset($command, $output);
        safeRemove($conf);
        clearstatcache(true, $sql);
        if ($status !== 0) {
            $details = is_file($errors) ? (string)file_get_contents($errors, false, null, 0, 4096) : '';
            $reason = 'dump_failed';
            if (stripos($details, 'Access denied') !== false) $reason = 'dump_authentication_failed';
            elseif (stripos($details, 'connect') !== false) $reason = 'dump_connection_failed';
            elseif (stripos($details, 'unknown option') !== false || stripos($details, 'unknown variable') !== false) $reason = 'dump_option_rejected';
            reportFailure($reason, $status);
            return false;
        }
        if (!is_file($sql) || filesize($sql) < 1000) {
            reportFailure('dump_too_small');
            return false;
        }
        if (!completeSqlDump($sql)) {
            reportFailure('dump_invalid_or_incomplete');
            return false;
        }

        $stmt = $connection->prepare('SELECT COUNT(*) as count FROM `users`');
        $stmt->execute();
        $userCount = $stmt->get_result()->fetch_assoc()['count'] ?? 0;
        $stmt->close();
        $stmt = $connection->prepare('SELECT COUNT(*) as count FROM `orders_list`');
        $stmt->execute();
        $orderCount = $stmt->get_result()->fetch_assoc()['count'] ?? 0;
        $stmt->close();

        date_default_timezone_set('Asia/Tehran');
        $now = date('Y-m-d H:i:s');
        $caption = "\n📦 Bot Database Backup\n\n⏰ Time: $now\n👤 Users: $userCount\n📋 Subscriptions: $orderCount\n";

        $archive = new \ZipArchive();
        if ($archive->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            reportFailure('zip_open_failed');
            return false;
        }
        $added = $archive->addFile($sql, 'bot_backup.sql');
        $closed = $archive->close();
        if (!$added || !$closed || !is_file($zipPath) || filesize($zipPath) === 0) {
            reportFailure('zip_build_failed');
            return false;
        }
        // Never report success on a failed Telegram response.
        if (!$deliver($zipPath, $caption, $channelId)) {
            reportFailure('telegram_upload_failed');
            return false;
        }
        return true;
    } catch (\Throwable $error) {
        // Do not leak exception messages: DB clients/transport may include secrets.
        reportFailure('backup_exception_' . get_class($error));
        return false;
    } finally {
        if ($work !== null) {
            foreach (['client.cnf', 'bot_backup.sql', 'bot_backup.zip', 'dump.stderr'] as $name) {
                safeRemove($work . '/' . $name);
            }
            if (is_dir($work)) @rmdir($work);
        }
        umask($previousUmask);
    }
}

function privateLockPath($applicationRoot) {
    $base = privateTempBase($applicationRoot);
    $directory = $base . '/achbot-backup-state-' . hash('sha256', realpath($applicationRoot));
    if (is_link($directory)) throw new \RuntimeException('backup_lock_dir_symlink');
    if (!is_dir($directory)) {
        $oldUmask = umask(0077);
        try {
            if (!@mkdir($directory, 0700)) throw new \RuntimeException('backup_lock_dir_unavailable');
        } finally {
            umask($oldUmask);
        }
    }
    clearstatcache(true, $directory);
    if (is_link($directory) || !is_dir($directory) ||
        (fileperms($directory) & 0777) !== 0700 ||
        (function_exists('posix_geteuid') && fileowner($directory) !== posix_geteuid())) {
        throw new \RuntimeException('backup_lock_dir_not_private');
    }
    return $directory . '/scheduler.lock';
}

function schedule($connection, $channelId, callable $sendBackup, $applicationRoot) {
    try {
        $lockFile = privateLockPath($applicationRoot);
        if (is_link($lockFile) || (file_exists($lockFile) && !is_file($lockFile))) {
            reportFailure('backup_lock_invalid');
            return false;
        }
        $previousUmask = umask(0077);
        try {
            $lock = @fopen($lockFile, 'c+');
        } finally {
            umask($previousUmask);
        }
        if (!$lock) { reportFailure('backup_lock_unavailable'); return false; }
        try {
            if (!@flock($lock, LOCK_EX | LOCK_NB)) return false;
            if (!@chmod($lockFile, 0600)) {
                reportFailure('backup_lock_permissions_failed');
                return false;
            }
            // Failed attempts must not move the DB's next-due timestamp. Use a
            // small private retry cooldown so four Cron wrappers do not hammer
            // a broken database/client/Telegram every scheduling tick.
            rewind($lock);
            $retryAt = (int)trim((string)stream_get_contents($lock, 32));
            // Read/check/update the due time INSIDE the per-installation lock.
            $stmt = $connection->prepare("SELECT `value` FROM `setting` WHERE `type` = 'LAST_BACKUP_TIME'");
            $stmt->execute();
            $result = $stmt->get_result();
            $hasTimestamp = $result->num_rows > 0;
            $lastBackupTime = $hasTimestamp ? (int)$result->fetch_assoc()['value'] : 0;
            $stmt->close();
            if (time() < $lastBackupTime) return true; // No backup due.
            if (time() < $retryAt) return false; // Recoverable cooldown.
            if (!$sendBackup($channelId)) {
                rewind($lock);
                ftruncate($lock, 0);
                fwrite($lock, (string)(time() + 60));
                fflush($lock);
                return false;
            }
            rewind($lock);
            ftruncate($lock, 0);
            fflush($lock);

            $newTime = time() + 600; // Next *due* time, only after verified send.
            if ($hasTimestamp) {
                $stmt = $connection->prepare("UPDATE `setting` SET `value` = ? WHERE `type` = 'LAST_BACKUP_TIME'");
            } else {
                $stmt = $connection->prepare("INSERT INTO `setting` (`type`, `value`) VALUES ('LAST_BACKUP_TIME', ?)");
            }
            $stmt->bind_param('s', $newTime);
            $stmt->execute();
            $stmt->close();
            return true;
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    } catch (\Throwable $error) {
        reportFailure('schedule_exception_' . get_class($error));
        return false;
    }
}
