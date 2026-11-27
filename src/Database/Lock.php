<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}

/** MariaDB named locks are server-wide. Use the actual selected database,
 * not a configured name or a local primary key alone. The length-prefixed
 * database name separates the two inputs without delimiter ambiguity.
 */
function achbotDatabaseLockName(mysqli $db,string $resource): string {
    $result=$db->query('SELECT DATABASE() AS schema_name');
    if (!($result instanceof mysqli_result)) throw new RuntimeException('database_lock_identity_unavailable');
    $schema=$result->fetch_assoc()['schema_name']??null;
    $result->free();
    if (!is_string($schema) || $schema==='' || $resource==='')
        throw new RuntimeException('database_lock_identity_unavailable');
    return 'achbot-'.substr(hash('sha256',strlen($schema).':'.$schema.$resource),0,56);
}

/** The persisted plan journal key identifies the local account, not a
 * server-global advisory lock. Do not rewrite immutable journal evidence.
 */
function achbotPhysicalSessionLockName(mysqli $db,string $journalKey): string {
    if ($journalKey==='') throw new RuntimeException('physical_lock_identity_unavailable');
    return achbotDatabaseLockName($db,'physical:'.$journalKey);
}
