<?php
/** AChBot V6.1 isolated PHP process: no real database, Telegram or payment flow. */
$case = $argv[1] ?? '';
$root = $argv[2] ?? '';
$sourceRoot = $argv[3] ?? '';
if (!$case || !$root || !$sourceRoot || getenv('ACHBOT_PRIVATE_TEST') !== '1') exit(2);

// A purpose-built ZIP shim for test environments without the native extension.
// Official CI must also execute all success cases with REAL ZipArchive enabled.
if (!class_exists('ZipArchive') && getenv('ACHBOT_TEST_ZIP_SHIM') === '1') {
    require_once __DIR__ . '/zip_shim.php';
}

$module = getenv('ACHBOT_TEST_MODULE_FILE') ?: $sourceRoot . '/src/Backup/Database.php';
require_once $module;

final class FakeResult {
    public $num_rows;
    private $rows;
    public function __construct(array $rows) { $this->rows = $rows; $this->num_rows = count($rows); }
    public function fetch_assoc() { return array_shift($this->rows) ?: null; }
}
final class FakeConnection {
    public $nextDue;
    public $writes = [];
    public $queryLog = [];
    public function __construct($nextDue) { $this->nextDue = $nextDue; }
    public function prepare($sql) { $this->queryLog[] = $sql; return new FakeStmt($this, $sql); }
}
final class FakeStmt {
    private $db;
    private $sql;
    private $bound;
    public function __construct($db, $sql) { $this->db = $db; $this->sql = $sql; }
    public function bind_param($type, &$value) { $this->bound = &$value; return true; }
    public function execute() {
        if (strpos($this->sql, 'LAST_BACKUP_TIME') !== false &&
            (strpos($this->sql, 'UPDATE') !== false || strpos($this->sql, 'INSERT') !== false)) {
            $this->db->nextDue = (int)$this->bound;
            $this->db->writes[] = $this->db->nextDue;
        }
        return true;
    }
    public function get_result() {
        if (strpos($this->sql, 'LAST_BACKUP_TIME') !== false) {
            return new FakeResult($this->db->nextDue === null ? [] : [['value' => $this->db->nextDue]]);
        }
        return new FakeResult([['count' => 7]]);
    }
    public function close() {}
}

$initial = $case === 'schedule.future' ? time() + 3600 : time() - 60;
$connection = new FakeConnection($case === 'schedule.missing' ? null : $initial);
$delivered = 0;
$sender = static function ($file, $caption, $recipient) use (&$delivered, $case, $root) {
    $delivered++;
    if (!is_file($file) || filesize($file) < 100 || substr(file_get_contents($file, false, null, 0, 2), 0, 2) !== 'PK') {
        throw new RuntimeException('invalid_archive');
    }
    $actual = realpath($file);
    if (strpos($actual, realpath($root) . DIRECTORY_SEPARATOR) === 0) throw new RuntimeException('web_exposure');
    $dir = dirname($actual);
    if ((fileperms($dir) & 0777) !== 0700 || (fileperms($file) & 0777) !== 0600) throw new RuntimeException('weak_permissions');
    if (strpos($caption, '📦 Bot Database Backup') === false || $recipient !== 'synthetic-channel') {
        throw new RuntimeException('telegram_contract_changed');
    }
    return $case !== 'send.telegram_failure';
};

if (strpos($case, 'schedule.') === 0) {
    $attempts = 0;
    $success = $case !== 'schedule.failure';
    $mockBackup = function ($id) use (&$attempts, $success) {
        if ($id !== 'synthetic-channel') throw new RuntimeException('incorrect_recipient');
        $attempts++;
        return $success;
    };
    $lock = null;
    if ($case === 'schedule.locked') {
        $lockPath = \AchBot\Backup\Database\privateLockPath($root);
        $lock = fopen($lockPath, 'c+');
        flock($lock, LOCK_EX | LOCK_NB);
    }
    $result = \AchBot\Backup\Database\schedule($connection, 'synthetic-channel', $mockBackup, $root);
    if ($case === 'schedule.failure') {
        $second = \AchBot\Backup\Database\schedule($connection, 'synthetic-channel', $mockBackup, $root);
        if ($second !== false) throw new RuntimeException('failed_backup_retry_not_throttled');
    }
    if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
    echo json_encode([
        'result' => $result,
        'attempts' => $attempts,
        'db_writes' => count($connection->writes),
        'timestamp_advanced' => $connection->nextDue > time(),
        'queries' => count($connection->queryLog),
    ], JSON_UNESCAPED_UNICODE), "\n";
    exit;
}

$channel = $case === 'send.no_recipient' ? '' : 'synthetic-channel';
$result = \AchBot\Backup\Database\send(
    $connection, 'synthetic_user', 'synthetic_password_!#"\'', 'synthetic_db',
    $channel, $sender, $root
);
echo json_encode([
    'result' => $result,
    'delivered' => $delivered,
    'sql_count_queries' => count($connection->queryLog),
    'private_workspace_residual' => glob(\AchBot\Backup\Database\privateTempBase($root) . '/achbot-backup-[0-9a-f]*') ? count(glob(\AchBot\Backup\Database\privateTempBase($root) . '/achbot-backup-[0-9a-f]*')) : 0,
], JSON_UNESCAPED_UNICODE), "\n";
