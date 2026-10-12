<?php
/** Independent holder process for the AChBot Cron flock test, disposable only. */
if (getenv('ACHBOT_PRIVATE_TEST') !== '1' ||
    !isset($argv[1], $argv[2])) exit(2);
require_once $argv[2] . '/src/Backup/Database.php';
$lockPath = \AchBot\Backup\Database\privateLockPath($argv[1]);
$oldUmask = umask(0077);
try {
    $descriptor = fopen($lockPath, 'c+');
} finally {
    umask($oldUmask);
}
if (!$descriptor || !flock($descriptor, LOCK_EX | LOCK_NB)) exit(3);
echo "LOCK_HELD\n";
fflush(STDOUT);
fgets(STDIN); // Release only after the orchestrator checks process B.
flock($descriptor, LOCK_UN);
fclose($descriptor);
