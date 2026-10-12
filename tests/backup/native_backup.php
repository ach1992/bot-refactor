<?php
/** Real isolated MariaDB -> genuine mysqldump -> ZIP -> simulated Telegram. */
if (getenv('ACHBOT_PRIVATE_TEST') !== '1' || !isset($argv[1], $argv[2], $argv[3])) exit(2);
$root = $argv[1]; $dbname = $argv[2]; $copy = $argv[3];
$dumpUser = getenv('ACHBOT_TEST_DUMP_USER');
$dumpPassword = getenv('ACHBOT_TEST_DUMP_PASSWORD');
if (!class_exists('ZipArchive') && getenv('ACHBOT_TEST_ZIP_SHIM') === '1') {
    require_once __DIR__ . '/zip_shim.php';
}
require_once $root . '/src/Backup/Database.php';
$socket = getenv('MYSQL_UNIX_PORT');
$connection = new mysqli('localhost', 'root', '', $dbname, 0, $socket);
if ($connection->connect_errno) throw new RuntimeException('Synthetic DB unreachable');
$connection->set_charset('utf8mb4');
$delivered=0;
$sender = static function ($file, $caption, $channel) use (&$delivered, $copy, $root) {
    if ($channel !== 'synthetic-test-channel') throw new RuntimeException('Wrong channel');
    if (strpos(realpath($file), realpath($root) . DIRECTORY_SEPARATOR) === 0) throw new RuntimeException('Exposed SQL');
    if (strpos($caption, '📦 Bot Database Backup') === false) throw new RuntimeException('Caption mismatch');
    if (!copy($file, $copy)) throw new RuntimeException('Fixture ZIP copy failed');
    $delivered++;
    return true;
};
$ok=\AchBot\Backup\Database\send($connection,$dumpUser,$dumpPassword,$dbname,'synthetic-test-channel',$sender,$root);
echo json_encode(['ok'=>$ok,'delivered'=>$delivered,'zip_bytes'=>is_file($copy)?filesize($copy):0]),"\n";
$connection->close();
