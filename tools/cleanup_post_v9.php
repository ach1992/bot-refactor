<?php
/** Optional AChBot 010 empty-table cleanup. CLI only; never loaded by runtime. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function cleanupReject(string $reason): void { throw new RuntimeException($reason); }
function cleanupJson(string $path, bool $private = false): array {
    if (is_link($path) || !is_file($path)) cleanupReject('regular_json_file_required');
    if ($private && ((fileperms($path) & 0077) !== 0 ||
        fileowner($path) !== posix_geteuid())) cleanupReject('private_file_owner_mode_required');
    $value = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($value)) cleanupReject('json_object_required');
    return $value;
}
function cleanupDdl(string $ddl): string {
    // Ignore only the empty table's allocation counter, never column/index shape.
    return preg_replace('/\s+/', ' ', trim(preg_replace('/ AUTO_INCREMENT=[0-9]+(?= |$)/', '', $ddl)));
}
function cleanupRows(mysqli $db, string $query): array {
    return $db->query($query)->fetch_all(MYSQLI_ASSOC);
}
function cleanupInspect(mysqli $db, array $config, string $table, array $manifest): array {
    $grants = implode("\n", array_map(fn($row) => (string)array_values($row)[0],
        cleanupRows($db, 'SHOW GRANTS FOR CURRENT_USER')));
    $privileges = [];
    foreach (explode("\n", $grants) as $grant) {
        if (preg_match('/^GRANT (.*?) ON \*\.\* TO /', $grant, $m)) {
            $privileges = array_merge($privileges, explode(', ', $m[1]));
        }
    }
    if (!in_array('ALL PRIVILEGES', $privileges, true) &&
        array_diff(['SELECT', 'SHOW VIEW', 'TRIGGER', 'EVENT'], $privileges)) {
        cleanupReject('global_dependency_metadata_visibility_required');
    }
    $schema = $db->real_escape_string($config['database']);
    $target = $db->real_escape_string($table);
    $rows = cleanupRows($db, "SELECT TABLE_TYPE FROM information_schema.TABLES
        WHERE TABLE_SCHEMA='$schema' AND TABLE_NAME='$target'");
    if (count($rows) !== 1 || $rows[0]['TABLE_TYPE'] !== 'BASE TABLE')
        cleanupReject('exact_base_table_required');
    $ddl = array_values(cleanupRows($db, "SHOW CREATE TABLE \x60$table\x60")[0])[1];
    $hash = hash('sha256', cleanupDdl($ddl));
    if ($hash !== $manifest['tables'][$table]['schema_sha256'])
        cleanupReject('original_schema_fingerprint_required');
    if (cleanupRows($db, "SELECT 1 FROM \x60$table\x60 LIMIT 1"))
        cleanupReject('nonempty_table_preserved');

    $foreign = cleanupRows($db, "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
        WHERE (TABLE_SCHEMA='$schema' AND TABLE_NAME='$target' AND REFERENCED_TABLE_NAME IS NOT NULL)
        OR (REFERENCED_TABLE_SCHEMA='$schema' AND REFERENCED_TABLE_NAME='$target')");
    if ($foreign) cleanupReject('foreign_key_dependency_preserved');
    // Dynamic SQL and transitive view/routine dependencies are not reliably
    // derivable by text search. Refuse all non-system programmable objects.
    $system = "'mysql','sys','information_schema','performance_schema'";
    foreach ([
        "SELECT 1 FROM information_schema.VIEWS WHERE TABLE_SCHEMA NOT IN ($system) LIMIT 1",
        "SELECT 1 FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA NOT IN ($system) LIMIT 1",
        "SELECT 1 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA NOT IN ($system) LIMIT 1",
        "SELECT 1 FROM information_schema.EVENTS WHERE EVENT_SCHEMA NOT IN ($system) LIMIT 1",
    ] as $query) {
        if (cleanupRows($db, $query)) cleanupReject('programmable_dependency_unproven');
    }
    $server = cleanupRows($db, 'SELECT @@hostname AS host, @@server_id AS id, @@datadir AS datadir,
        @@version AS version, DATABASE() AS db')[0];
    $identity = hash('sha256', json_encode([$config['socket'], $server], JSON_THROW_ON_ERROR));
    return ['operation'=>'plan', 'table'=>$table, 'database'=>$config['database'],
        'identity_sha256'=>$identity, 'schema_sha256'=>$hash, 'empty'=>true,
        'dependencies'=>'none-under-strict-metadata-policy', 'ddl'=>$ddl,
        'operator_disposition_required'=>true, 'applied'=>false];
}

try {
    $args = getopt('', ['config:', 'table:', 'apply', 'disposition:', 'restore-ddl:']);
    if (!isset($args['config'], $args['table'])) cleanupReject('config_and_exact_table_required');
    $manifest = cleanupJson(__DIR__.'/../migrations/post-v9/010_cleanup_manifest.json');
    $table = $args['table'];
    if (!is_string($table) || !in_array($table, ['admins','black_list','servers'], true) || !array_key_exists($table, $manifest['tables']))
        cleanupReject('table_not_allowlisted');
    $config = cleanupJson($args['config'], true);
    foreach (['socket','database','user','password'] as $key)
        if (!isset($config[$key]) || !is_string($config[$key])) cleanupReject('invalid_connection_config');
    if (!preg_match('/\A[A-Za-z0-9_]+\z/D', $config['database']) ||
        in_array($config['database'], ['mysql','sys','information_schema','performance_schema'], true))
        cleanupReject('invalid_target_database');
    if (realpath($config['socket']) !== $config['socket'] || filetype($config['socket']) !== 'socket')
        cleanupReject('canonical_local_socket_required');
    $db = new mysqli('localhost', $config['user'], $config['password'],
        $config['database'], 0, $config['socket']);
    $db->set_charset('utf8mb4');
    $db->query('SET SESSION lock_wait_timeout=5');
    $db->query('SET SESSION sql_quote_show_create=1');
    $result = cleanupInspect($db, $config, $table, $manifest);
    if (isset($args['apply'])) {
        if (!isset($args['disposition'], $args['restore-ddl']))
            cleanupReject('explicit_disposition_and_restore_path_required');
        $approval = cleanupJson($args['disposition'], true);
        foreach (['table','database','identity_sha256','schema_sha256'] as $key)
            if (($approval[$key] ?? null) !== $result[$key]) cleanupReject('target_disposition_mismatch');
        if (($approval['external_consumers'] ?? null) !== 'none-confirmed' ||
            ($approval['retention'] ?? null) !== 'empty-no-retention' ||
            ($approval['maintenance'] ?? null) !== 'exclusive-no-writers-or-ddl' ||
            !is_string($approval['operator'] ?? null) || trim($approval['operator']) === '' ||
            !is_string($approval['approval_reference'] ?? null) || trim($approval['approval_reference']) === '')
            cleanupReject('operator_external_retention_maintenance_disposition_required');

        $db->query("LOCK TABLES \x60$table\x60 WRITE");
        try {
            // Recheck on the SAME locked connection immediately before DROP.
            $locked = cleanupInspect($db, $config, $table, $manifest);
            foreach (['identity_sha256','schema_sha256'] as $key)
                if ($locked[$key] !== $result[$key]) cleanupReject('target_changed');
            $restore = $args['restore-ddl'];
            if (!is_string($restore) || !str_starts_with($restore, '/') ||
                realpath(dirname($restore)) !== dirname($restore))
                cleanupReject('canonical_restore_parent_required');
            $previous = umask(0077);
            try { $file = @fopen($restore, 'x'); } finally { umask($previous); }
            if ($file === false) cleanupReject('new_private_restore_file_required');
            try {
                $sql = '-- AChBot 010 empty table only; restore into the separately verified target.'.
                    "\n".$locked['ddl'].";\n";
                if (fwrite($file, $sql) !== strlen($sql) || !fflush($file) ||
                    (function_exists('fsync') && !fsync($file))) cleanupReject('restore_write_failed');
            } finally { fclose($file); }
            $db->query("DROP TABLE \x60$table\x60");
            $result['operation'] = 'apply';
            $result['applied'] = true;
            $result['restore_ddl_sha256'] = hash_file('sha256', $restore);
        } finally { $db->query('UNLOCK TABLES'); }
    }
    unset($result['ddl']);
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
    // Never expose server exceptions, credentials, SQL, grants or row content.
    $safe = $error instanceof RuntimeException && !($error instanceof mysqli_sql_exception)
        && preg_match('/\A[a-z_]+\z/D', $error->getMessage()) ? $error->getMessage() : 'cleanup_unconfirmed';
    echo json_encode(['applied'=>false, 'refused'=>$safe])."\n";
    exit(2);
}
