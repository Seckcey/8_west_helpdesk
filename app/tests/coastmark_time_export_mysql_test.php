<?php
/** Disposable MySQL 8 proof for v3 migration, locking, replay, and grants. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
if (getenv('SAFEHARBOR_COASTMARK_V3_TEST_DISPOSABLE_SERVER') !== '1') {
    fwrite(STDERR, "Refusing Coastmark v3 MySQL test without disposable-server acknowledgement.\n");
    exit(2);
}
$databaseBase = getenv('SAFEHARBOR_COASTMARK_V3_TEST_DB');
if (!is_string($databaseBase)
    || preg_match('/\Asafeharbor_coastmark_v3_test(?:_[a-z0-9_]+)?\z/D', $databaseBase) !== 1
    || strlen($databaseBase) > 44
) {
    fwrite(STDERR, "Refusing destructive Coastmark v3 test database base.\n");
    exit(2);
}
$host = getenv('SAFEHARBOR_COASTMARK_V3_TEST_HOST') ?: '127.0.0.1';
$port = getenv('SAFEHARBOR_COASTMARK_V3_TEST_PORT') ?: '3306';
$user = getenv('SAFEHARBOR_COASTMARK_V3_TEST_USER') ?: 'root';
$pass = getenv('SAFEHARBOR_COASTMARK_V3_TEST_PASS') ?: '';
if (!is_string($host) || trim($host) === '' || !ctype_digit((string) $port)
    || (int) $port < 1 || (int) $port > 65535 || !is_string($user) || $user === ''
) {
    fwrite(STDERR, "Invalid Coastmark v3 test connection.\n");
    exit(2);
}

require_once __DIR__ . '/../lib/coastmark_time_export.php';
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];
$serverDsn = "mysql:host={$host};port={$port};charset=utf8mb4";

/** @return array<string,mixed> */
function cm_v3_config(): array
{
    return [
        'claim_enabled' => true,
        'enabled' => true,
        'endpoint' => 'https://coastmark.example.test/api/integrations/safeharbor/time-entries',
        'status_endpoint' => 'https://coastmark.example.test/api/integrations/safeharbor/time-events/status',
        'service' => 'safeharbor-time',
        'secret' => str_repeat('s', 32),
        'tenant_slugs' => ['8west'],
        'client_keys' => ['milepost-customer:11111111-1111-4111-8111-111111111111'],
        'timeout_seconds' => 5,
    ];
}

// Real independent workers race through the same tenant namespace.
if (getenv('SAFEHARBOR_COASTMARK_V3_RACE_WORKER') === '1') {
    $database = getenv('SAFEHARBOR_COASTMARK_V3_RACE_DB');
    $entry = getenv('SAFEHARBOR_COASTMARK_V3_RACE_ENTRY');
    $suffix = getenv('SAFEHARBOR_COASTMARK_V3_RACE_SUFFIX');
    if (!is_string($database)
        || preg_match('/\Asafeharbor_coastmark_v3_test_[0-9a-f]{12}\z/D', $database) !== 1
        || !is_string($entry) || !ctype_digit($entry)
        || !is_string($suffix) || !in_array($suffix, ['a', 'b', 'c'], true)
    ) {
        fwrite(STDERR, "Race worker target refused.\n");
        exit(2);
    }
    $pdo = new PDO($serverDsn . ';dbname=' . $database, $user, $pass, $options);
    $pdo->exec("SET time_zone='+00:00'");
    $pdo->exec('SET SESSION innodb_lock_wait_timeout=15');
    echo "ready\n";
    flush();
    $result = coastmark_time_export_claim(
        $pdo,
        '8west',
        (int) $entry,
        'timer:race:0000001',
        102,
        cm_v3_config(),
        'safeharbor-time:' . str_repeat($suffix, 32),
    );
    echo json_encode([
        'claim_id' => (int) $result['claim']['id'],
        'replayed' => $result['replayed'],
        'event_key' => $result['claim']['event_key'],
    ], JSON_THROW_ON_ERROR) . "\n";
    exit(0);
}

$checks = 0;
$failures = 0;
function cm_v3_check(string $name, bool $condition): void
{
    global $checks, $failures;
    $checks++;
    if ($condition) echo "ok {$checks} - {$name}\n";
    else {
        $failures++;
        echo "FAIL {$checks} - {$name}\n";
    }
}
function cm_v3_expect(string $name, callable $operation, string $message = ''): void
{
    try {
        $operation();
        cm_v3_check($name, false);
    } catch (Throwable $error) {
        $matched = $message === ''
            || str_contains(strtolower($error->getMessage()), strtolower($message));
        cm_v3_check($name, $matched);
        if (!$matched) {
            fwrite(STDERR, "# expected refusal containing {$message}; got {$error->getMessage()}\n");
        }
    }
}
/** @return list<string> */
function cm_v3_statements(string $sql): array
{
    $delimiter = ';';
    $buffer = '';
    $statements = [];
    foreach (preg_split('/\R/', $sql) ?: [] as $line) {
        if (trim($buffer) === '' && preg_match('/^\s*--/', $line) === 1) continue;
        if (preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $match)) {
            if (trim($buffer) !== '') throw new RuntimeException('Delimiter changed with pending SQL.');
            $delimiter = $match[1];
            continue;
        }
        $buffer .= $line . "\n";
        $trimmed = rtrim($buffer);
        if (!str_ends_with($trimmed, $delimiter)) continue;
        $statement = trim(substr($trimmed, 0, -strlen($delimiter)));
        if ($statement !== '') $statements[] = $statement;
        $buffer = '';
    }
    if (trim($buffer) !== '') throw new RuntimeException('Unterminated SQL statement.');
    return $statements;
}
function cm_v3_apply(PDO $pdo, string $path): void
{
    $sql = file_get_contents($path);
    if (!is_string($sql)) throw new RuntimeException('Cannot read migration.');
    foreach (cm_v3_statements($sql) as $statement) {
        $plain = preg_replace('/\A(?:\s*--[^\n]*(?:\n|\z))+/', '', $statement) ?? $statement;
        if (preg_match('/^\s*SELECT\b/i', $plain) === 1) {
            $result = $pdo->query($statement);
            $result->fetchAll();
            $result->closeCursor();
        } else {
            $pdo->exec($statement);
        }
    }
}
function cm_v3_install_swap_guards(PDO $pdo, string $path): void
{
    $sql = file_get_contents($path);
    if (!is_string($sql)) throw new RuntimeException('Cannot read migration.');
    $installed = 0;
    foreach (cm_v3_statements($sql) as $statement) {
        $plain = preg_replace('/\A(?:\s*--[^\n]*(?:\n|\z))+/', '', $statement) ?? $statement;
        if (preg_match(
            '/^\s*CREATE\s+TRIGGER\s+IF\s+NOT\s+EXISTS\s+'
                . 'trg_cm_(?:claim|receipt)_021_(?:insert|update|delete)_swap\b/i',
            $plain,
        ) !== 1) {
            continue;
        }
        $pdo->exec($statement);
        $installed++;
    }
    if ($installed !== 6) throw new RuntimeException('Did not find all six migration swap guards.');
}
function cm_v3_trigger_statement(string $path, string $triggerName): string
{
    $sql = file_get_contents($path);
    if (!is_string($sql)) throw new RuntimeException('Cannot read migration.');
    foreach (cm_v3_statements($sql) as $statement) {
        $plain = preg_replace('/\A(?:\s*--[^\n]*(?:\n|\z))+/', '', $statement) ?? $statement;
        if (preg_match(
            '/^\s*CREATE\s+TRIGGER(?:\s+IF\s+NOT\s+EXISTS)?\s+'
                . preg_quote($triggerName, '/') . '\b/i',
            $plain,
        ) === 1) {
            return $statement;
        }
    }
    throw new RuntimeException('Canonical trigger statement not found: ' . $triggerName);
}
function cm_v3_guard_snapshot(PDO $pdo): string
{
    return (string) $pdo->query(
        "SELECT COALESCE(GROUP_CONCAT(
            CONCAT(trigger_name,':',SHA2(action_statement,256))
            ORDER BY trigger_name SEPARATOR ','
         ),'')
           FROM information_schema.triggers
          WHERE trigger_schema=DATABASE()
            AND event_object_table IN
                ('coastmark_time_export_claims','coastmark_time_export_receipts')"
    )->fetchColumn();
}
function cm_v3_migration_lock_name(PDO $pdo): string
{
    $database = $pdo->query('SELECT DATABASE()')->fetchColumn();
    if (!is_string($database) || $database === '') {
        throw new RuntimeException('Migration lock test requires a selected database.');
    }
    return 'safeharbor:m021:' . substr(hash('sha256', $database), 0, 48);
}
/** @return array{process:resource,pipes:array<int,resource>} */
function cm_v3_worker(string $database, int $entry, string $suffix): array
{
    $env = [
        'SAFEHARBOR_COASTMARK_V3_TEST_DISPOSABLE_SERVER' => '1',
        'SAFEHARBOR_COASTMARK_V3_TEST_DB' => (string) getenv('SAFEHARBOR_COASTMARK_V3_TEST_DB'),
        'SAFEHARBOR_COASTMARK_V3_TEST_HOST' => (string) getenv('SAFEHARBOR_COASTMARK_V3_TEST_HOST'),
        'SAFEHARBOR_COASTMARK_V3_TEST_PORT' => (string) getenv('SAFEHARBOR_COASTMARK_V3_TEST_PORT'),
        'SAFEHARBOR_COASTMARK_V3_TEST_USER' => (string) getenv('SAFEHARBOR_COASTMARK_V3_TEST_USER'),
        'SAFEHARBOR_COASTMARK_V3_TEST_PASS' => (string) getenv('SAFEHARBOR_COASTMARK_V3_TEST_PASS'),
        'SAFEHARBOR_COASTMARK_V3_RACE_WORKER' => '1',
        'SAFEHARBOR_COASTMARK_V3_RACE_DB' => $database,
        'SAFEHARBOR_COASTMARK_V3_RACE_ENTRY' => (string) $entry,
        'SAFEHARBOR_COASTMARK_V3_RACE_SUFFIX' => $suffix,
    ];
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, __FILE__],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        __DIR__,
        $env,
    );
    if (!is_resource($process)) throw new RuntimeException('Cannot start race worker.');
    fclose($pipes[0]);
    $ready = fgets($pipes[1]);
    if (trim((string) $ready) !== 'ready') throw new RuntimeException('Race worker did not become ready.');
    return ['process' => $process, 'pipes' => $pipes];
}
/** @return array{exit:int,payload:?array,stderr:string} */
function cm_v3_finish(array $worker): array
{
    $stdout = stream_get_contents($worker['pipes'][1]);
    $stderr = stream_get_contents($worker['pipes'][2]);
    fclose($worker['pipes'][1]);
    fclose($worker['pipes'][2]);
    $exit = proc_close($worker['process']);
    $payload = json_decode(trim((string) $stdout), true);
    return ['exit' => $exit, 'payload' => is_array($payload) ? $payload : null, 'stderr' => $stderr];
}

$runId = bin2hex(random_bytes(6));
$database = $databaseBase . '_' . $runId;
$quotedDatabase = '`' . $database . '`';
$foreignDatabase = $database . '_x';
$quotedForeignDatabase = '`' . $foreignDatabase . '`';
$runtimeUser = 'sh_cm_v3_' . $runId;
$runtimePass = bin2hex(random_bytes(24));
$server = null;
$pdo = null;
$created = false;
$foreignCreated = false;
$runtimeCreated = false;
$fatal = null;
try {
    $server = new PDO($serverDsn, $user, $pass, $options);
    $server->exec("CREATE DATABASE {$quotedDatabase} CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
    $created = true;
    $pdo = new PDO($serverDsn . ';dbname=' . $database, $user, $pass, $options);
    $pdo->exec("SET time_zone='+00:00'");
    $pdo->exec('CREATE TABLE tenants(id INT UNSIGNED PRIMARY KEY,slug VARCHAR(64) NOT NULL UNIQUE) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE clients(
      id INT UNSIGNED PRIMARY KEY,tenant_id INT UNSIGNED NOT NULL,name VARCHAR(190) NOT NULL,
      UNIQUE KEY uq_clients_tenant_id(tenant_id,id)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE users(
      id INT UNSIGNED PRIMARY KEY,tenant_id INT UNSIGNED NOT NULL,role VARCHAR(32) NOT NULL,
      is_active TINYINT NOT NULL,UNIQUE KEY uq_users_tenant_id(tenant_id,id)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE suite_customer_sync_bindings(
      id BIGINT UNSIGNED PRIMARY KEY,tenant_id INT UNSIGNED NOT NULL,client_id INT UNSIGNED NOT NULL,
      customer_id CHAR(36) NOT NULL,status VARCHAR(16) NOT NULL,
      UNIQUE KEY uq_binding_client(tenant_id,client_id)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE time_entries(
      id INT UNSIGNED PRIMARY KEY,tenant_id INT UNSIGNED NOT NULL,client_id INT UNSIGNED NOT NULL,
      ticket_id INT UNSIGNED NOT NULL,entry_key VARCHAR(64) NOT NULL,source VARCHAR(24) NOT NULL,
      worked_at DATETIME NOT NULL,minutes INT UNSIGNED NOT NULL,note TEXT NOT NULL,billable TINYINT NOT NULL,
      approval_status VARCHAR(16) NOT NULL,user_id INT UNSIGNED NOT NULL,reviewed_by_user_id INT UNSIGNED,
      reviewed_at DATETIME,UNIQUE KEY uq_time_entries_tenant_id(tenant_id,id)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE time_entry_approval_adjustments(
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,tenant_id INT UNSIGNED NOT NULL,
      time_entry_id INT UNSIGNED NOT NULL,adjustment_key VARCHAR(64) NOT NULL,version_no INT UNSIGNED NOT NULL,
      effective_minutes INT UNSIGNED NOT NULL,effective_billable TINYINT NOT NULL,reason VARCHAR(500) NOT NULL,
      actor_user_id INT UNSIGNED NOT NULL,created_at DATETIME NOT NULL,
      UNIQUE KEY uq_adjustment_version(tenant_id,time_entry_id,version_no)) ENGINE=InnoDB');
    $pdo->exec("INSERT INTO tenants VALUES(1,'8west')");
    $pdo->exec("INSERT INTO clients VALUES(11,1,'Lifestyle')");
    $pdo->exec("INSERT INTO users VALUES(101,1,'tech',1),(102,1,'owner',1),(103,1,'admin',1)");
    $pdo->exec("INSERT INTO suite_customer_sync_bindings VALUES
      (1,1,11,'11111111-1111-4111-8111-111111111111','active')");
    $pdo->exec("INSERT INTO time_entries VALUES
      (501,1,11,901,'timer:mysql:000001','timer','2026-08-26 20:00:00',30,'note',1,
       'approved',101,102,'2026-08-26 20:05:00'),
      (502,1,11,902,'timer:race:0000001','timer','2026-08-26 20:00:00',45,'race',1,
       'approved',101,102,'2026-08-26 20:05:00'),
      (503,1,11,903,'timer:race:0000001','timer','2026-08-26 20:00:00',25,'binding race',1,
       'approved',101,102,'2026-08-26 20:05:00'),
      (504,1,11,904,'timer:internal:0004','timer','2026-08-26 20:00:00',20,'internal',0,
       'approved',101,102,'2026-08-26 20:05:00')");

    $migration = __DIR__ . '/../db/migrations/021_coastmark_time_export_v3.sql';

    // A same-named unrelated table must survive untouched. The failed attempt
    // may create the missing exact claims reference, producing the meaningful
    // one-table interruption state that the following retry must recover.
    $pdo->exec('CREATE TABLE safeharbor_m021_reference_receipts(
      id INT PRIMARY KEY, sentinel VARCHAR(32) NOT NULL
    ) ENGINE=InnoDB');
    $pdo->exec("INSERT INTO safeharbor_m021_reference_receipts VALUES(7,'unrelated-preserve')");
    cm_v3_expect(
        'migration refuses an unowned populated same-name reference table',
        fn() => cm_v3_apply($pdo, $migration),
        'migration_021_reference_owner_failed',
    );
    cm_v3_check('unowned same-name reference bytes are preserved after refusal',
        $pdo->query('SELECT sentinel FROM safeharbor_m021_reference_receipts WHERE id=7')
            ->fetchColumn() === 'unrelated-preserve');
    $pdo->exec('DROP TABLE safeharbor_m021_reference_receipts');

    $pdo->exec("CREATE TABLE safeharbor_m021_reference_receipts(
      id INT PRIMARY KEY, sentinel VARCHAR(32) NOT NULL
    ) ENGINE=InnoDB COMMENT='safeharbor:migration:021:reference:receipts:v1'");
    $pdo->exec("INSERT INTO safeharbor_m021_reference_receipts VALUES(8,'owned-nonempty')");
    cm_v3_expect(
        'migration refuses an owned but nonempty reference table',
        fn() => cm_v3_apply($pdo, $migration),
        'migration_021_reference_rows_not_empty',
    );
    cm_v3_check('owned nonempty reference evidence is preserved after refusal',
        $pdo->query('SELECT sentinel FROM safeharbor_m021_reference_receipts WHERE id=8')
            ->fetchColumn() === 'owned-nonempty');
    $pdo->exec('DROP TABLE safeharbor_m021_reference_receipts');

    cm_v3_apply($pdo, $migration);
    $migrationLockName = cm_v3_migration_lock_name($pdo);
    $migrationConnectionId = (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
    cm_v3_check('fresh migration recovers one exact owned leftover and leaves only six permanent triggers',
        (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema=DATABASE() AND table_name LIKE 'coastmark_time_export_%'")->fetchColumn() === 2
        && (int) $pdo->query("SELECT COUNT(*) FROM information_schema.triggers
          WHERE trigger_schema=DATABASE() AND trigger_name LIKE 'trg_cm_export_%'")->fetchColumn() === 6
        && (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema=DATABASE()
            AND table_name LIKE 'safeharbor_m021_reference_%'")->fetchColumn() === 0
        && (int) $pdo->query("SELECT IS_USED_LOCK(" . $pdo->quote($migrationLockName) . ") <=> {$migrationConnectionId}")
            ->fetchColumn() === 0);
    $guardSnapshot = cm_v3_guard_snapshot($pdo);
    cm_v3_apply($pdo, $migration);
    $replayedGuardSnapshot = cm_v3_guard_snapshot($pdo);
    cm_v3_check('binary raw ACTION_STATEMENT hashes stay stable across exact replay',
        $replayedGuardSnapshot === $guardSnapshot);
    cm_v3_check('canonical migration replays after both install locks are removed',
        (int) $pdo->query("SELECT COUNT(*)
          FROM information_schema.table_constraints
          WHERE constraint_schema=DATABASE()
            AND table_name IN
                ('coastmark_time_export_claims','coastmark_time_export_receipts')
            AND constraint_name IN
                ('ck_cm_export_claim_install_lock','ck_cm_export_receipt_install_lock')")
            ->fetchColumn() === 0);

    $pdo->exec('ALTER TABLE coastmark_time_export_claims DROP INDEX uq_cm_export_claim_event');
    cm_v3_expect('migration refuses a claim table with weakened event uniqueness',
        fn() => cm_v3_apply($pdo, $migration));
    cm_v3_check('index drift is refused before any permanent guard is replaced',
        cm_v3_guard_snapshot($pdo) === $guardSnapshot);
    $pdo->exec('ALTER TABLE safeharbor_m021_reference_claims
        DROP INDEX uq_cm_export_claim_event');
    cm_v3_expect(
        'migration refuses coordinated live and owner-marked reference index drift',
        fn() => cm_v3_apply($pdo, $migration),
        'migration_021_reference_source_shape_failed',
    );
    cm_v3_check('coordinated drift refusal preserves both reference evidence tables',
        (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema=DATABASE()
            AND table_name IN
                ('safeharbor_m021_reference_claims','safeharbor_m021_reference_receipts')")
            ->fetchColumn() === 2
        && cm_v3_guard_snapshot($pdo) === $guardSnapshot);
    $pdo->exec('ALTER TABLE safeharbor_m021_reference_claims
        ADD UNIQUE KEY uq_cm_export_claim_event (tenant_id,event_key)');
    cm_v3_check('failed migration retains one owned lock and exact empty reference evidence',
        (int) $pdo->query("SELECT IS_USED_LOCK(" . $pdo->quote($migrationLockName) . ") <=> CONNECTION_ID()")
            ->fetchColumn() === 1
        && (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema=DATABASE()
            AND table_name IN
                ('safeharbor_m021_reference_claims','safeharbor_m021_reference_receipts')
            AND table_comment LIKE 'safeharbor:migration:021:reference:%:v1'")->fetchColumn() === 2
        && (int) $pdo->query("SELECT COUNT(*) FROM information_schema.triggers
          WHERE trigger_schema=DATABASE()
            AND event_object_table IN
                ('safeharbor_m021_reference_claims','safeharbor_m021_reference_receipts')")->fetchColumn() === 12);

    $pdo->exec('DROP TRIGGER trg_cm_ref_021_receipt_no_delete');
    cm_v3_expect('exact owned partial reference-trigger state is restored before later drift refusal',
        fn() => cm_v3_apply($pdo, $migration));
    cm_v3_check('same-connection failure retry does not reenter its advisory lock',
        (int) $pdo->query("SELECT IS_USED_LOCK(" . $pdo->quote($migrationLockName) . ") <=> CONNECTION_ID()")
            ->fetchColumn() === 1
        && (int) $pdo->query("SELECT COUNT(*) FROM information_schema.triggers
          WHERE trigger_schema=DATABASE()
            AND event_object_table IN
                ('safeharbor_m021_reference_claims','safeharbor_m021_reference_receipts')")->fetchColumn() === 12);

    $pdo->exec('CREATE TABLE cm_v3_reference_dependent(
      tenant_id INT UNSIGNED NOT NULL, claim_id BIGINT UNSIGNED NOT NULL,
      CONSTRAINT fk_cm_v3_unexpected_reference FOREIGN KEY (tenant_id,claim_id)
        REFERENCES safeharbor_m021_reference_claims(tenant_id,id)
    ) ENGINE=InnoDB');
    cm_v3_expect(
        'migration refuses an unexpected dependent of its owned empty reference',
        fn() => cm_v3_apply($pdo, $migration),
        'migration_021_reference_dependency_failed',
    );
    cm_v3_check('unexpected dependent and referenced evidence survive refusal',
        (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema=DATABASE()
            AND table_name IN
                ('cm_v3_reference_dependent','safeharbor_m021_reference_claims')")->fetchColumn() === 2);
    $pdo->exec('DROP TABLE cm_v3_reference_dependent');

    $pdo->exec('CREATE PROCEDURE cm_v3_reference_reader()
      READS SQL DATA SELECT COUNT(*) FROM safeharbor_m021_reference_claims');
    cm_v3_expect(
        'migration refuses a stored routine that references owned cleanup evidence',
        fn() => cm_v3_apply($pdo, $migration),
        'migration_021_reference_dependency_failed',
    );
    cm_v3_check('unexpected stored routine survives cleanup refusal',
        (int) $pdo->query("SELECT COUNT(*) FROM information_schema.routines
          WHERE routine_schema=DATABASE()
            AND routine_name='cm_v3_reference_reader'")->fetchColumn() === 1);
    $pdo->exec('DROP PROCEDURE cm_v3_reference_reader');

    $migrationContender = new PDO($serverDsn . ';dbname=' . $database, $user, $pass, $options);
    cm_v3_expect(
        'a concurrent migration runner cannot enter while failed owner retains the lock',
        fn() => cm_v3_apply($migrationContender, $migration),
        'migration_021_advisory_lock_failed',
    );
    cm_v3_expect('a concurrent reference write is blocked during failed migration evidence retention',
        fn() => $migrationContender->exec("INSERT INTO safeharbor_m021_reference_claims
          (tenant_id,time_entry_id,source_version,event_key,predecessor_claim_id,
           payload_sha256,payload_json,created_by_user_id)
          VALUES (1,501,0,'safeharbor-time:99999999999999999999999999999999',NULL,'"
            . str_repeat('9', 64)
            . "','{\"client_key\":\"milepost-customer:11111111-1111-4111-8111-111111111111\"}',102)"));
    $pdo->exec('ALTER TABLE coastmark_time_export_claims
        ADD UNIQUE KEY uq_cm_export_claim_event (tenant_id,event_key)');
    cm_v3_apply($pdo, $migration);
    cm_v3_check('same-connection exact repair releases its single migration lock after postflight',
        (int) $pdo->query("SELECT IS_USED_LOCK(" . $pdo->quote($migrationLockName) . ") <=> CONNECTION_ID()")
            ->fetchColumn() === 0);

    $pdo->exec('ALTER TABLE coastmark_time_export_receipts
        MODIFY response_status INT UNSIGNED NULL');
    $failedRunner = new PDO($serverDsn . ';dbname=' . $database, $user, $pass, $options);
    cm_v3_expect('migration refuses a receipt table with the wrong column type',
        fn() => cm_v3_apply($failedRunner, $migration));
    $failedRunnerId = (int) $failedRunner->query('SELECT CONNECTION_ID()')->fetchColumn();
    cm_v3_check('column drift is refused before any permanent guard is replaced',
        cm_v3_guard_snapshot($pdo) === $guardSnapshot);
    cm_v3_check('failed dedicated runner retains the connection-scoped migration lock',
        (int) $pdo->query("SELECT IS_USED_LOCK(" . $pdo->quote($migrationLockName) . ")")
            ->fetchColumn() === $failedRunnerId);
    $failedRunner = null;
    gc_collect_cycles();
    $closeProbe = new PDO($serverDsn . ';dbname=' . $database, $user, $pass, $options);
    $closeProbeLock = $closeProbe->prepare('SELECT GET_LOCK(?,0)');
    $closeProbeLock->execute([$migrationLockName]);
    cm_v3_check('closing an aborted dedicated runner releases its retained migration lock',
        (int) $closeProbeLock->fetchColumn() === 1);
    $closeProbeUnlock = $closeProbe->prepare('SELECT RELEASE_LOCK(?)');
    $closeProbeUnlock->execute([$migrationLockName]);
    $closeProbeUnlock->fetchColumn();
    $pdo->exec('ALTER TABLE coastmark_time_export_receipts
        MODIFY response_status SMALLINT UNSIGNED NULL');

    cm_v3_install_swap_guards($pdo, $migration);
    $pdo->exec('DROP TRIGGER trg_cm_ref_021_claim_insert_swap');
    $pdo->exec('CREATE TRIGGER trg_cm_ref_021_claim_insert_swap
        BEFORE INSERT ON safeharbor_m021_reference_claims FOR EACH ROW
        SET @cm_v3_weak_swap = 1');
    $pdo->exec('DROP TRIGGER trg_cm_claim_021_insert_swap');
    $pdo->exec('CREATE TRIGGER trg_cm_claim_021_insert_swap
        BEFORE INSERT ON coastmark_time_export_claims FOR EACH ROW
        SET @cm_v3_weak_swap = 1');
    cm_v3_expect(
        'migration refuses matching weak reference and live swap trigger bodies',
        fn() => cm_v3_apply($pdo, $migration),
        'migration_021_initial_triggers_failed',
    );
    $referenceSwapBody = (string) $pdo->query("SELECT action_statement
        FROM information_schema.triggers
        WHERE trigger_schema=DATABASE()
          AND trigger_name='trg_cm_ref_021_claim_insert_swap'")->fetchColumn();
    $liveWeakSwapBody = (string) $pdo->query("SELECT action_statement
        FROM information_schema.triggers
        WHERE trigger_schema=DATABASE()
          AND trigger_name='trg_cm_claim_021_insert_swap'")->fetchColumn();
    cm_v3_check('retry rebuilds the reference body from source but preserves the refused live weak swap',
        str_contains($referenceSwapBody, 'migration 021 claim trigger swap')
        && str_contains($liveWeakSwapBody, '@cm_v3_weak_swap'));
    $pdo->exec('DROP TRIGGER trg_cm_claim_021_insert_swap');
    cm_v3_install_swap_guards($pdo, $migration);
    cm_v3_apply($pdo, $migration);
    cm_v3_check('exact weak-swap repair restores the six canonical permanent guards',
        cm_v3_guard_snapshot($pdo) === $guardSnapshot);

    $pdo->exec("ALTER TABLE coastmark_time_export_receipts
        ALTER COLUMN operation_kind SET DEFAULT 'dispatch_started'");
    cm_v3_expect('migration refuses a receipt column with an extra default',
        fn() => cm_v3_apply($pdo, $migration));
    cm_v3_check('default drift is refused before any permanent guard is replaced',
        cm_v3_guard_snapshot($pdo) === $guardSnapshot);
    $pdo->exec('ALTER TABLE coastmark_time_export_receipts
        ALTER COLUMN operation_kind DROP DEFAULT');

    $pdo->exec('ALTER TABLE coastmark_time_export_claims
        DROP CHECK ck_cm_export_claim_hash');
    $pdo->exec("ALTER TABLE coastmark_time_export_claims
        ADD CONSTRAINT ck_cm_export_claim_hash
        CHECK (payload_sha256 REGEXP '^[0-9A-F]{64}$')");
    $pdo->exec('ALTER TABLE safeharbor_m021_reference_claims
        DROP CHECK rc21_claim_hash');
    $pdo->exec("ALTER TABLE safeharbor_m021_reference_claims
        ADD CONSTRAINT rc21_claim_hash
        CHECK (payload_sha256 REGEXP '^[0-9A-F]{64}$')");
    cm_v3_expect(
        'migration refuses coordinated case-only binary check-literal drift',
        fn() => cm_v3_apply($pdo, $migration),
        'migration_021_reference_source_shape_failed',
    );
    cm_v3_check('case-only source drift refusal preserves permanent live guards',
        cm_v3_guard_snapshot($pdo) === $guardSnapshot);
    $pdo->exec('ALTER TABLE coastmark_time_export_claims
        DROP CHECK ck_cm_export_claim_hash');
    $pdo->exec("ALTER TABLE coastmark_time_export_claims
        ADD CONSTRAINT ck_cm_export_claim_hash
        CHECK (payload_sha256 REGEXP '^[0-9a-f]{64}$')");
    $pdo->exec('ALTER TABLE safeharbor_m021_reference_claims
        DROP CHECK rc21_claim_hash');
    $pdo->exec("ALTER TABLE safeharbor_m021_reference_claims
        ADD CONSTRAINT rc21_claim_hash
        CHECK (payload_sha256 REGEXP '^[0-9a-f]{64}$')");

    $pdo->exec('ALTER TABLE coastmark_time_export_receipts
        DROP CHECK ck_cm_export_receipt_detail');
    cm_v3_expect('migration refuses a receipt table with a missing validation check',
        fn() => cm_v3_apply($pdo, $migration));
    cm_v3_check('check drift is refused before any permanent guard is replaced',
        cm_v3_guard_snapshot($pdo) === $guardSnapshot);
    $pdo->exec("ALTER TABLE coastmark_time_export_receipts
        ADD CONSTRAINT ck_cm_export_receipt_detail
        CHECK (detail_code REGEXP '^[a-z][a-z0-9_]{2,63}$')");

    $pdo->exec('ALTER TABLE coastmark_time_export_receipts
        DROP CHECK ck_cm_export_receipt_detail');
    $pdo->exec("ALTER TABLE coastmark_time_export_receipts
        ADD CONSTRAINT ck_cm_export_receipt_detail
        CHECK ((detail_code REGEXP '^[a-z][a-z0-9_]{2,63}$') OR 1=1)");
    cm_v3_expect('migration refuses a weakened check with the canonical constraint name',
        fn() => cm_v3_apply($pdo, $migration));
    cm_v3_check('same-name permissive check drift is refused before guard replacement',
        cm_v3_guard_snapshot($pdo) === $guardSnapshot);
    $pdo->exec('ALTER TABLE coastmark_time_export_receipts
        DROP CHECK ck_cm_export_receipt_detail');
    $pdo->exec("ALTER TABLE coastmark_time_export_receipts
        ADD CONSTRAINT ck_cm_export_receipt_detail
        CHECK (detail_code REGEXP '^[a-z][a-z0-9_]{2,63}$')");

    $pdo->exec('ALTER TABLE coastmark_time_export_receipts
        DROP FOREIGN KEY fk_cm_export_receipt_claim');
    cm_v3_expect('migration refuses a receipt table with a missing claim foreign key',
        fn() => cm_v3_apply($pdo, $migration));
    cm_v3_check('foreign-key drift is refused before any permanent guard is replaced',
        cm_v3_guard_snapshot($pdo) === $guardSnapshot);
    $pdo->exec('ALTER TABLE coastmark_time_export_receipts
        ADD CONSTRAINT fk_cm_export_receipt_claim FOREIGN KEY (tenant_id,claim_id)
        REFERENCES coastmark_time_export_claims (tenant_id,id)');

    $server->exec("CREATE DATABASE {$quotedForeignDatabase}
        CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
    $foreignCreated = true;
    $server->exec("CREATE TABLE {$quotedForeignDatabase}.tenants
        (id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB");
    $server->exec("INSERT INTO {$quotedForeignDatabase}.tenants VALUES (1)");
    $pdo->exec('ALTER TABLE coastmark_time_export_receipts
        DROP FOREIGN KEY fk_cm_export_receipt_tenant');
    $pdo->exec("ALTER TABLE coastmark_time_export_receipts
        ADD CONSTRAINT fk_cm_export_receipt_tenant FOREIGN KEY (tenant_id)
        REFERENCES {$quotedForeignDatabase}.tenants (id)");
    cm_v3_expect('migration refuses a same-named foreign key into another schema',
        fn() => cm_v3_apply($pdo, $migration));
    cm_v3_check('referenced-schema drift is refused before permanent guard replacement',
        cm_v3_guard_snapshot($pdo) === $guardSnapshot);
    $pdo->exec('ALTER TABLE coastmark_time_export_receipts
        DROP FOREIGN KEY fk_cm_export_receipt_tenant');
    $pdo->exec('ALTER TABLE coastmark_time_export_receipts
        ADD CONSTRAINT fk_cm_export_receipt_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants (id)');
    $server->exec("DROP DATABASE {$quotedForeignDatabase}");
    $foreignCreated = false;

    $pdo->exec('ALTER TABLE coastmark_time_export_receipts
        DROP INDEX uq_cm_export_receipt_operation');
    $pdo->exec('ALTER TABLE coastmark_time_export_receipts
        ADD UNIQUE KEY uq_cm_export_receipt_operation (tenant_id,operation_key(32))');
    cm_v3_expect('migration refuses a same-named prefix index',
        fn() => cm_v3_apply($pdo, $migration));
    cm_v3_check('prefix-index drift is refused before permanent guard replacement',
        cm_v3_guard_snapshot($pdo) === $guardSnapshot);
    $pdo->exec('ALTER TABLE coastmark_time_export_receipts
        DROP INDEX uq_cm_export_receipt_operation');
    $pdo->exec('ALTER TABLE coastmark_time_export_receipts
        ADD UNIQUE KEY uq_cm_export_receipt_operation (tenant_id,operation_key)');

    $pdo->exec('ALTER TABLE coastmark_time_export_receipts
        DROP INDEX ix_cm_export_receipt_outcome');
    $pdo->exec('ALTER TABLE coastmark_time_export_receipts
        ADD KEY ix_cm_export_receipt_outcome (tenant_id,created_at,outcome,id)');
    cm_v3_expect('migration refuses a same-named index with reordered columns',
        fn() => cm_v3_apply($pdo, $migration));
    cm_v3_check('index-order drift is refused before permanent guard replacement',
        cm_v3_guard_snapshot($pdo) === $guardSnapshot);
    $pdo->exec('ALTER TABLE coastmark_time_export_receipts
        DROP INDEX ix_cm_export_receipt_outcome');
    $pdo->exec('ALTER TABLE coastmark_time_export_receipts
        ADD KEY ix_cm_export_receipt_outcome (tenant_id,outcome,created_at,id)');

    $pdo->exec('ALTER TABLE coastmark_time_export_receipts
        DROP INDEX ix_cm_export_receipt_outcome');
    $pdo->exec('ALTER TABLE coastmark_time_export_receipts
        ADD FULLTEXT KEY ix_cm_export_receipt_outcome (detail_code)');
    cm_v3_expect('migration refuses a same-named index with the wrong index type',
        fn() => cm_v3_apply($pdo, $migration));
    cm_v3_check('index-type drift is refused before permanent guard replacement',
        cm_v3_guard_snapshot($pdo) === $guardSnapshot);
    $pdo->exec('ALTER TABLE coastmark_time_export_receipts
        DROP INDEX ix_cm_export_receipt_outcome');
    $pdo->exec('ALTER TABLE coastmark_time_export_receipts
        ADD KEY ix_cm_export_receipt_outcome (tenant_id,outcome,created_at,id)');

    cm_v3_apply($pdo, $migration);
    cm_v3_check('exactly repaired schema replays and retains the same canonical guards',
        cm_v3_guard_snapshot($pdo) === $guardSnapshot);

    cm_v3_install_swap_guards($pdo, $migration);
    $pdo->exec('DROP TRIGGER trg_cm_export_claim_before_insert');
    $canonicalClaimInsert = cm_v3_trigger_statement(
        $migration,
        'trg_cm_export_claim_before_insert',
    );
    $caseDriftClaimInsert = str_replace(
        "'$.client_key'",
        "'$.CLIENT_KEY'",
        $canonicalClaimInsert,
        $caseReplacementCount,
    );
    if ($caseReplacementCount !== 1) {
        throw new RuntimeException('Case-only JSON path drift fixture was not exact.');
    }
    $pdo->exec($caseDriftClaimInsert);
    $caseDriftSnapshot = cm_v3_guard_snapshot($pdo);
    cm_v3_expect('migration refuses case-only JSON path drift in a reserved trigger body',
        fn() => cm_v3_apply($pdo, $migration));
    cm_v3_check('case-only body drift is refused before any guard replacement',
        cm_v3_guard_snapshot($pdo) === $caseDriftSnapshot);
    $pdo->exec('DROP TRIGGER trg_cm_export_claim_before_insert');
    $pdo->exec($canonicalClaimInsert);
    cm_v3_apply($pdo, $migration);
    cm_v3_check('exact body repair recovers from the case-only drift refusal',
        cm_v3_guard_snapshot($pdo) === $guardSnapshot);

    $pdo->exec('CREATE TABLE cm_v3_reserved_trigger_host(id INT PRIMARY KEY) ENGINE=InnoDB');
    cm_v3_install_swap_guards($pdo, $migration);
    $pdo->exec('DROP TRIGGER trg_cm_export_claim_no_delete');
    $canonicalClaimDelete = cm_v3_trigger_statement(
        $migration,
        'trg_cm_export_claim_no_delete',
    );
    $wrongTableClaimDelete = str_replace(
        'BEFORE DELETE ON coastmark_time_export_claims',
        'BEFORE DELETE ON cm_v3_reserved_trigger_host',
        $canonicalClaimDelete,
        $wrongTableReplacementCount,
    );
    if ($wrongTableReplacementCount !== 1) {
        throw new RuntimeException('Wrong-table reserved-name fixture was not exact.');
    }
    $pdo->exec($wrongTableClaimDelete);
    $wrongTableSnapshot = cm_v3_guard_snapshot($pdo);
    cm_v3_expect('migration refuses a reserved trigger name attached to the wrong table',
        fn() => cm_v3_apply($pdo, $migration));
    cm_v3_check('wrong-table refusal leaves every target-table guard byte unchanged',
        cm_v3_guard_snapshot($pdo) === $wrongTableSnapshot);
    cm_v3_check('wrong-table reserved trigger is not deleted as collateral',
        (int) $pdo->query("SELECT COUNT(*) FROM information_schema.triggers
          WHERE trigger_schema=DATABASE()
            AND trigger_name='trg_cm_export_claim_no_delete'
            AND event_object_table='cm_v3_reserved_trigger_host'")->fetchColumn() === 1);
    $pdo->exec('DROP TRIGGER trg_cm_export_claim_no_delete');
    $pdo->exec($canonicalClaimDelete);
    cm_v3_apply($pdo, $migration);
    $pdo->exec('DROP TABLE cm_v3_reserved_trigger_host');
    cm_v3_check('exact table repair recovers from the wrong-table refusal',
        cm_v3_guard_snapshot($pdo) === $guardSnapshot);

    cm_v3_install_swap_guards($pdo, $migration);
    $pdo->exec('DROP TRIGGER trg_cm_export_claim_before_insert');
    $pdo->exec('CREATE TRIGGER cm_v3_unexpected_claim_guard
        BEFORE INSERT ON coastmark_time_export_claims FOR EACH ROW
        SET @cm_v3_unexpected_claim_guard = 1');
    $partialReplaySnapshot = cm_v3_guard_snapshot($pdo);
    cm_v3_expect('partial replay refuses an unexpected trigger while all swap guards survive',
        fn() => cm_v3_apply($pdo, $migration));
    cm_v3_check('failed partial replay leaves every surviving guard byte unchanged',
        cm_v3_guard_snapshot($pdo) === $partialReplaySnapshot);
    cm_v3_expect('surviving claim swap blocks writes after partial replay refusal',
        fn() => $pdo->exec("INSERT INTO coastmark_time_export_claims
          (tenant_id,time_entry_id,source_version,event_key,predecessor_claim_id,
           payload_sha256,payload_json,created_by_user_id)
          VALUES (1,501,0,'safeharbor-time:77777777777777777777777777777777',NULL,'"
            . str_repeat('7', 64)
            . "','{\"client_key\":\"milepost-customer:11111111-1111-4111-8111-111111111111\"}',102)"),
        'migration 021 claim trigger swap');
    $pdo->exec('DROP TRIGGER cm_v3_unexpected_claim_guard');
    cm_v3_apply($pdo, $migration);
    cm_v3_check('exact repaired partial replay restores only the six canonical permanent guards',
        cm_v3_guard_snapshot($pdo) === $guardSnapshot
        && (int) $pdo->query("SELECT COUNT(*) FROM information_schema.triggers
          WHERE trigger_schema=DATABASE()
            AND event_object_table IN
                ('coastmark_time_export_claims','coastmark_time_export_receipts')")->fetchColumn() === 6);

    $claim = coastmark_time_export_claim(
        $pdo, '8west', 501, 'timer:mysql:000001', 102, cm_v3_config(),
        'safeharbor-time:eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',
    );
    $snapshot = $pdo->query('SELECT * FROM coastmark_time_export_claims WHERE id=' . (int) $claim['claim']['id'])->fetch();
    cm_v3_apply($pdo, $migration);
    cm_v3_check('exact migration replay preserves existing claim bytes and canonical guards',
        $snapshot === $pdo->query('SELECT * FROM coastmark_time_export_claims WHERE id=' . (int) $claim['claim']['id'])->fetch()
        && (int) $pdo->query("SELECT COUNT(*) FROM information_schema.triggers
          WHERE trigger_schema=DATABASE() AND trigger_name LIKE 'trg_cm_export_%'")->fetchColumn() === 6);
    cm_v3_expect('database trigger blocks claim update',
        fn() => $pdo->exec("UPDATE coastmark_time_export_claims SET payload_sha256='" . str_repeat('0', 64) . "'"),
        'immutable');
    cm_v3_expect('database trigger blocks claim deletion',
        fn() => $pdo->exec('DELETE FROM coastmark_time_export_claims'), 'cannot be deleted');
    cm_v3_expect('database trigger refuses a claim for approved internal time',
        fn() => $pdo->exec("INSERT INTO coastmark_time_export_claims
          (tenant_id,time_entry_id,source_version,event_key,predecessor_claim_id,
           payload_sha256,payload_json,created_by_user_id)
          VALUES (1,504,0,'safeharbor-time:ffffffffffffffffffffffffffffffff',NULL,'"
            . str_repeat('f', 64) . "','{}',102)"),
        'approved billable reviewed time');

    $locker = new PDO($serverDsn . ';dbname=' . $database, $user, $pass, $options);
    $locker->beginTransaction();
    $locker->query('SELECT id FROM tenants WHERE id=1 FOR UPDATE')->fetchColumn();
    $workerA = cm_v3_worker($database, 502, 'a');
    $workerB = cm_v3_worker($database, 502, 'b');
    usleep(250_000);
    $locker->commit();
    $resultA = cm_v3_finish($workerA);
    $resultB = cm_v3_finish($workerB);
    $payloads = [$resultA['payload'], $resultB['payload']];
    $ids = array_map(fn($value) => is_array($value) ? ($value['claim_id'] ?? null) : null, $payloads);
    $replays = array_map(fn($value) => is_array($value) ? ($value['replayed'] ?? null) : null, $payloads);
    sort($replays);
    cm_v3_check('two real workers serialize and converge on one immutable claim',
        $resultA['exit'] === 0 && $resultB['exit'] === 0
        && trim($resultA['stderr']) === '' && trim($resultB['stderr']) === ''
        && $ids[0] === $ids[1] && $replays === [false, true]
        && (int) $pdo->query('SELECT COUNT(*) FROM coastmark_time_export_claims WHERE time_entry_id=502')->fetchColumn() === 1);
    $raceClaimId = (int) $pdo->query(
        'SELECT id FROM coastmark_time_export_claims WHERE time_entry_id=502',
    )->fetchColumn();
    $statusLockName = 'safeharbor:cm-status:' . $raceClaimId;
    $statusLock = $pdo->prepare('SELECT GET_LOCK(?,0)');
    $statusLock->execute([$statusLockName]);
    cm_v3_check('status owner acquires the cross-process MySQL advisory lock',
        (int) $statusLock->fetchColumn() === 1);
    $pdo->exec("INSERT INTO coastmark_time_export_receipts
      (tenant_id,claim_id,operation_key,operation_kind,outcome,detail_code)
      VALUES (1,{$raceClaimId},'safeharbor-op:11111111111111111111111111111111',
              'status_started','checking','mysql_status_serialization')");
    $statusContender = new PDO($serverDsn . ';dbname=' . $database, $user, $pass, $options);
    $contenderLock = $statusContender->prepare('SELECT GET_LOCK(?,0)');
    $contenderLock->execute([$statusLockName]);
    cm_v3_check('a second MySQL connection cannot overlap the active status check',
        (int) $contenderLock->fetchColumn() === 0);
    cm_v3_expect('database trigger rejects a second concurrent status check',
        fn() => $pdo->exec("INSERT INTO coastmark_time_export_receipts
          (tenant_id,claim_id,operation_key,operation_kind,outcome,detail_code)
          VALUES (1,{$raceClaimId},'safeharbor-op:22222222222222222222222222222222',
                  'status_started','checking','mysql_status_overlap')"),
        'status transition is not permitted');
    $statusUnlock = $pdo->prepare('SELECT RELEASE_LOCK(?)');
    $statusUnlock->execute([$statusLockName]);
    $statusUnlock->fetchColumn();

    // The worker's normal read sees the previously committed active binding.
    // The DEFINER trigger then waits on this update and must reject the claim
    // after the deactivation commits, closing the read-to-insert race without
    // granting UPDATE or locking-read privileges to the runtime identity.
    $bindingLocker = new PDO($serverDsn . ';dbname=' . $database, $user, $pass, $options);
    $bindingLocker->beginTransaction();
    $bindingLocker->exec("UPDATE suite_customer_sync_bindings
      SET status='inactive' WHERE tenant_id=1 AND client_id=11");
    $bindingWorker = cm_v3_worker($database, 503, 'c');
    usleep(250_000);
    $bindingLocker->commit();
    $bindingRace = cm_v3_finish($bindingWorker);
    cm_v3_check('claim trigger closes an active-customer-binding deactivation race',
        $bindingRace['exit'] !== 0
        && str_contains($bindingRace['stderr'], 'active matching customer binding')
        && (int) $pdo->query('SELECT COUNT(*) FROM coastmark_time_export_claims WHERE time_entry_id=503')
            ->fetchColumn() === 0);
    $pdo->exec("UPDATE suite_customer_sync_bindings
      SET status='active' WHERE tenant_id=1 AND client_id=11");

    $server->exec("CREATE USER '{$runtimeUser}'@'%' IDENTIFIED BY '{$runtimePass}'");
    $runtimeCreated = true;
    foreach (['tenants','clients','users','suite_customer_sync_bindings','time_entries','time_entry_approval_adjustments'] as $table) {
        $server->exec("GRANT SELECT ON {$quotedDatabase}.{$table} TO '{$runtimeUser}'@'%'");
    }
    $server->exec("GRANT SELECT,INSERT ON {$quotedDatabase}.coastmark_time_export_claims TO '{$runtimeUser}'@'%'");
    $server->exec("GRANT SELECT,INSERT ON {$quotedDatabase}.coastmark_time_export_receipts TO '{$runtimeUser}'@'%'");
    $runtime = new PDO($serverDsn . ';dbname=' . $database, $runtimeUser, $runtimePass, $options);
    $runtimeReplay = coastmark_time_export_claim(
        $runtime, '8west', 501, 'timer:mysql:000001', 102, cm_v3_config(),
    );
    cm_v3_check('least-privilege runtime can read and exactly replay claims', $runtimeReplay['replayed']);
    $runtimeClaim = coastmark_time_export_claim(
        $runtime, '8west', 503, 'timer:race:0000001', 102, cm_v3_config(),
        'safeharbor-time:dddddddddddddddddddddddddddddddd',
    );
    cm_v3_check('least-privilege runtime creates a brand-new claim through DEFINER guards',
        !$runtimeClaim['replayed']
        && (int) $runtimeClaim['claim']['time_entry_id'] === 503
        && (int) $runtime->query('SELECT COUNT(*) FROM coastmark_time_export_claims WHERE time_entry_id=503')
            ->fetchColumn() === 1);
    $runtimeAck = coastmark_time_export_send_claim(
        $runtime,
        (int) $runtimeClaim['claim']['id'],
        cm_v3_config(),
        fn() => [
            'status' => 201,
            'body' => json_encode([
                'ok' => true,
                'action' => 'created',
                'event' => [
                    'event_key' => $runtimeClaim['claim']['event_key'],
                    'payload_sha256' => $runtimeClaim['claim']['payload_sha256'],
                    'coastmark_event_id' => 1,
                ],
                'draft' => ['invoice_id' => 1, 'invoice_line_id' => 1],
            ], JSON_THROW_ON_ERROR),
        ],
        1_777_777_777,
    );
    cm_v3_check('least-privilege runtime can append dispatch receipts',
        $runtimeAck['outcome'] === 'accepted'
        && (int) $runtime->query('SELECT COUNT(*) FROM coastmark_time_export_receipts WHERE claim_id=' .
            (int) $runtimeClaim['claim']['id'])
            ->fetchColumn() === 2);
    $runtimeStatusTransportCalls = 0;
    $runtimeStatus = coastmark_time_export_status_claim(
        $runtime,
        (int) $runtimeClaim['claim']['id'],
        cm_v3_config(),
        function () use (&$runtimeStatusTransportCalls): array {
            $runtimeStatusTransportCalls++;
            return ['status' => 500, 'body' => 'must not run'];
        },
    );
    cm_v3_check('least-privilege runtime can serialize and replay terminal status',
        $runtimeStatus['outcome'] === 'accepted' && $runtimeStatusTransportCalls === 0);
    cm_v3_expect('least-privilege runtime cannot update claims',
        fn() => $runtime->exec("UPDATE coastmark_time_export_claims SET payload_sha256='"
            . str_repeat('0', 64) . "'"),
        'denied');
    cm_v3_expect('least-privilege runtime cannot delete receipts',
        fn() => $runtime->exec('DELETE FROM coastmark_time_export_receipts'),
        'denied');
    cm_v3_expect('least-privilege runtime cannot execute migration DDL',
        fn() => $runtime->exec('CREATE TABLE forbidden_runtime_ddl(id INT)'),
        'denied');
} catch (Throwable $error) {
    $fatal = $error;
} finally {
    if ($server instanceof PDO) {
        if ($runtimeCreated) {
            try { $server->exec("DROP USER IF EXISTS '{$runtimeUser}'@'%'"); } catch (Throwable) {}
        }
        if ($created) {
            try { $server->exec("DROP DATABASE IF EXISTS {$quotedDatabase}"); } catch (Throwable) {}
        }
        if ($foreignCreated) {
            try { $server->exec("DROP DATABASE IF EXISTS {$quotedForeignDatabase}"); } catch (Throwable) {}
        }
    }
}
if ($fatal instanceof Throwable) {
    fwrite(STDERR, 'Coastmark v3 MySQL fixture failed: ' . $fatal::class . ': ' . $fatal->getMessage() . "\n");
    $failures++;
}
echo "Coastmark approved-time v3 MySQL: {$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
