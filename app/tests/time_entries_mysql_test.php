<?php
/**
 * MySQL integration coverage for migration 011 and approval-grade time.
 *
 * Run only against a disposable MySQL server. The supplied database value is
 * a safe base; this test appends a random run id, creates that exact new
 * database, proves the active connection selected it, and drops it in finally:
 *
 *   SAFEHARBOR_TIME_TEST_DISPOSABLE_SERVER=1 \
 *   SAFEHARBOR_TIME_TEST_DB=safeharbor_time_test \
 *   SAFEHARBOR_TIME_TEST_HOST=127.0.0.1 \
 *   SAFEHARBOR_TIME_TEST_USER=root \
 *   SAFEHARBOR_TIME_TEST_PASS=... php tests/time_entries_mysql_test.php
 *
 * The configured identity must be able to create/drop databases, create/drop
 * users, grant schema privileges, and create triggers. All 97 checks, including
 * the underprivileged migration and least-privilege runtime proofs, are required.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$disposableServer = getenv('SAFEHARBOR_TIME_TEST_DISPOSABLE_SERVER');
if ($disposableServer !== '1') {
    fwrite(STDERR, "Refusing MySQL test without explicit disposable-server acknowledgement.\n");
    exit(2);
}

$databaseBase = getenv('SAFEHARBOR_TIME_TEST_DB');
if (!is_string($databaseBase)
    || preg_match('/\Asafeharbor_time_test(?:_[a-z0-9_]+)?\z/', $databaseBase) !== 1
    || strlen($databaseBase) > 48
) {
    fwrite(STDERR, "Refusing destructive test database base.\n");
    exit(2);
}
$TIME_MYSQL_RUN_ID = bin2hex(random_bytes(6));
$testDatabase = $databaseBase . '_' . $TIME_MYSQL_RUN_ID;
$host = getenv('SAFEHARBOR_TIME_TEST_HOST') ?: '127.0.0.1';
$portText = getenv('SAFEHARBOR_TIME_TEST_PORT') ?: '3306';
$user = getenv('SAFEHARBOR_TIME_TEST_USER') ?: 'root';
$pass = getenv('SAFEHARBOR_TIME_TEST_PASS') ?: '';
if (!is_string($host) || trim($host) === ''
    || !is_string($portText) || !ctype_digit($portText)
    || (int) $portText < 1 || (int) $portText > 65535
    || !is_string($user) || $user === ''
) {
    fwrite(STDERR, "Refusing invalid MySQL fixture connection settings.\n");
    exit(2);
}

$serverDsn = "mysql:host={$host};port={$portText};charset=utf8mb4";
$pdoOptions = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];
$quotedDatabase = '`' . str_replace('`', '``', $testDatabase) . '`';
$server = null;
$pdo = null;
$databaseCreated = false;
try {
    $server = new PDO($serverDsn, $user, $pass, $pdoOptions);
    $server->exec(
        "CREATE DATABASE {$quotedDatabase} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    );
    $databaseCreated = true;
    $pdo = new PDO($serverDsn . ";dbname={$testDatabase}", $user, $pass, $pdoOptions);
    $pdo->exec("SET time_zone = '+00:00'");
} catch (Throwable $error) {
    if ($databaseCreated && $server instanceof PDO) {
        try {
            $server->exec("DROP DATABASE IF EXISTS {$quotedDatabase}");
        } catch (Throwable) {
            // The nonzero fixture refusal below remains authoritative.
        }
    }
    fwrite(STDERR, 'Time-entry MySQL fixture unavailable: ' . $error::class . PHP_EOL);
    exit(2);
}

$TIME_MYSQL_CONNECTION = [
    'dsn' => $serverDsn . ";dbname={$testDatabase}",
    'user' => $user,
    'pass' => $pass,
    'options' => $pdoOptions,
];

$checks = 0;
$failures = 0;

function check(string $name, bool $condition): void
{
    global $checks, $failures;
    $checks++;
    if ($condition) {
        echo "ok {$checks} - {$name}\n";
        return;
    }
    $failures++;
    echo "FAIL {$checks} - {$name}\n";
}

/** @return list<string> */
function sql_statements(string $sql): array
{
    $delimiter = ';';
    $buffer = '';
    $statements = [];

    foreach (preg_split('/\R/', $sql) ?: [] as $line) {
        if (preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $match)) {
            if (trim($buffer) !== '') {
                throw new RuntimeException('DELIMITER changed with a pending SQL statement');
            }
            $delimiter = $match[1];
            continue;
        }

        $buffer .= $line . "\n";
        $trimmed = rtrim($buffer);
        if (!str_ends_with($trimmed, $delimiter)) {
            continue;
        }

        $statement = trim(substr($trimmed, 0, -strlen($delimiter)));
        if ($statement !== '') {
            $statements[] = $statement;
        }
        $buffer = '';
    }

    if (trim($buffer) !== '') {
        throw new RuntimeException('Unterminated SQL statement');
    }
    return $statements;
}

function execute_sql_file(PDO $pdo, string $path, ?callable $beforeStatement = null): void
{
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException("Cannot read {$path}");
    }
    foreach (sql_statements($sql) as $statement) {
        if ($beforeStatement !== null) {
            $beforeStatement($statement);
        }
        $withoutComments = preg_replace('/\A(?:\s*--[^\n]*(?:\n|\z))+/', '', $statement) ?? $statement;
        if (preg_match('/^\s*SELECT\b/i', $withoutComments)) {
            $result = $pdo->query($statement);
            $result->fetchAll();
            $result->closeCursor();
        } else {
            $pdo->exec($statement);
        }
    }
}

function parallel_connection(): PDO
{
    global $TIME_MYSQL_CONNECTION;
    $connection = new PDO(
        $TIME_MYSQL_CONNECTION['dsn'],
        $TIME_MYSQL_CONNECTION['user'],
        $TIME_MYSQL_CONNECTION['pass'],
        $TIME_MYSQL_CONNECTION['options'],
    );
    $connection->exec("SET time_zone = '+00:00'");
    return $connection;
}

function reset_database(PDO $pdo): void
{
    $tables = $pdo->query(
        'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()'
    )->fetchAll(PDO::FETCH_COLUMN);
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables as $table) {
        $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', (string) $table) . '`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
}

function create_legacy_schema(PDO $pdo): void
{
    $statements = [
        "CREATE TABLE tenants (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          name VARCHAR(128) NOT NULL,
          slug VARCHAR(64) NOT NULL,
          PRIMARY KEY (id), UNIQUE KEY uq_tenants_slug (slug)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE users (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          tenant_id INT UNSIGNED NOT NULL,
          email VARCHAR(190) NOT NULL,
          password_hash VARCHAR(255) NOT NULL DEFAULT '',
          full_name VARCHAR(128) NOT NULL,
          initials VARCHAR(4) NOT NULL DEFAULT '',
          color CHAR(7) NOT NULL DEFAULT '#2D8CFF',
          role ENUM('owner','admin','tech') NOT NULL DEFAULT 'tech',
          is_active TINYINT(1) NOT NULL DEFAULT 1,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY uq_users_tenant_email (tenant_id,email),
          KEY ix_users_tenant (tenant_id),
          CONSTRAINT fk_users_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE clients (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          tenant_id INT UNSIGNED NOT NULL,
          name VARCHAR(128) NOT NULL,
          PRIMARY KEY (id),
          KEY ix_clients_tenant (tenant_id),
          CONSTRAINT fk_clients_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE tickets (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          tenant_id INT UNSIGNED NOT NULL,
          client_id INT UNSIGNED NOT NULL,
          subject VARCHAR(190) NOT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY ix_tickets_tenant (tenant_id),
          KEY ix_tickets_client (client_id),
          CONSTRAINT fk_tickets_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
          CONSTRAINT fk_tickets_client FOREIGN KEY (client_id) REFERENCES clients(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE time_entries (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          ticket_id INT UNSIGNED NOT NULL,
          user_id INT UNSIGNED NOT NULL,
          minutes INT UNSIGNED NOT NULL,
          note VARCHAR(255) NOT NULL DEFAULT '',
          billable TINYINT(1) NOT NULL DEFAULT 1,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY ix_time_ticket (ticket_id),
          KEY ix_time_user_created (user_id, created_at),
          CONSTRAINT fk_time_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id),
          CONSTRAINT fk_time_user FOREIGN KEY (user_id) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }
}

function seed_ownership_fixture(PDO $pdo): void
{
    $pdo->exec("INSERT INTO tenants (id,name,slug) VALUES
        (1,'Tenant One','one'),(2,'Tenant Two','two')");
    $pdo->exec("INSERT INTO clients (id,tenant_id,name) VALUES
        (11,1,'Client One'),(22,2,'Client Two')");
    $pdo->exec("INSERT INTO users
        (id,tenant_id,email,password_hash,full_name,initials,role,is_active) VALUES
        (101,1,'owner1@example.test','','Owner One','O1','owner',1),
        (102,1,'admin1@example.test','','Admin One','A1','admin',1),
        (103,1,'tech1@example.test','','Tech One','T1','tech',1),
        (104,1,'inactive1@example.test','','Inactive Admin','IA','admin',0),
        (201,2,'owner2@example.test','','Owner Two','O2','owner',1)");

    $ticketColumns = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema=DATABASE() AND table_name='tickets' AND column_name='sla_due_at'"
    )->fetchColumn();
    if ($ticketColumns === 1) {
        $pdo->exec("INSERT INTO tickets
          (id,tenant_id,client_id,subject,status,priority,channel,sla_due_at,created_at,updated_at) VALUES
          (1001,1,11,'Ticket One','open','normal','phone','2026-08-27 00:00:00','2026-08-26 10:00:00','2026-08-26 10:00:00'),
          (2001,2,22,'Ticket Two','open','normal','phone','2026-08-27 00:00:00','2026-08-26 10:00:00','2026-08-26 10:00:00')");
    } else {
        $pdo->exec("INSERT INTO tickets (id,tenant_id,client_id,subject,created_at) VALUES
          (1001,1,11,'Ticket One','2026-08-26 10:00:00'),
          (2001,2,22,'Ticket Two','2026-08-26 10:00:00')");
    }
}

/** @return array<string,mixed> */
function time_entry(PDO $pdo, int $id): array
{
    $query = $pdo->prepare('SELECT * FROM time_entries WHERE id = ?');
    $query->execute([$id]);
    return $query->fetch() ?: [];
}

function expect_pdo(string $name, callable $operation, array $driverCodes = [], string $sqlState = ''): void
{
    try {
        $operation();
        check($name, false);
    } catch (PDOException $error) {
        $driverCode = (int) ($error->errorInfo[1] ?? 0);
        $stateMatches = $sqlState === '' || $error->getCode() === $sqlState;
        $driverMatches = $driverCodes === [] || in_array($driverCode, $driverCodes, true);
        check($name, $stateMatches && $driverMatches);
    }
}

function trigger_count(PDO $pdo): int
{
    return (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.triggers
          WHERE trigger_schema=DATABASE() AND trigger_name IN (
            'trg_time_entries_before_insert','trg_time_entries_after_insert',
            'trg_time_entries_before_update','trg_time_entries_after_update',
            'trg_time_entries_no_delete','trg_time_entry_events_no_update',
            'trg_time_entry_events_no_delete')"
    )->fetchColumn();
}

function staging_trigger_count(PDO $pdo): int
{
    return (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.triggers
          WHERE trigger_schema=DATABASE() AND trigger_name IN (
            'trg_time_entry_insert_compatibility_staging',
            'trg_time_entry_update_guard_staging',
            'trg_time_entry_delete_guard_staging')"
    )->fetchColumn();
}

function verify_exact_structure(PDO $pdo, string $label): void
{
    $columnCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema=DATABASE() AND table_name='time_entries'
            AND column_name IN (
              'tenant_id','client_id','entry_key','source','worked_at','started_at',
              'ended_at','approval_status','reviewed_by_user_id','reviewed_at','review_note')"
    )->fetchColumn();
    check("{$label}: all approval columns exist", $columnCount === 11);
    $ownershipExact = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema=DATABASE() AND table_name='time_entries'
            AND ((column_name='tenant_id' AND column_type='int unsigned'
                  AND is_nullable='NO' AND column_default IS NULL)
              OR (column_name='client_id' AND column_type='int unsigned'
                  AND is_nullable='NO' AND column_default IS NULL)
              OR (column_name='entry_key' AND column_type='varchar(64)'
                  AND is_nullable='NO' AND column_default IS NULL))"
    )->fetchColumn();
    check("{$label}: ownership/key columns are exact", $ownershipExact === 3);
    $enumExact = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema=DATABASE() AND table_name='time_entries'
            AND ((column_name='source'
                  AND column_type=\"enum('timer','reply','suggestion','legacy')\"
                  AND is_nullable='NO' AND column_default='legacy')
              OR (column_name='approval_status'
                  AND column_type=\"enum('pending','approved','rejected')\"
                  AND is_nullable='NO' AND column_default='pending'))"
    )->fetchColumn();
    check("{$label}: source/status enums are exact", $enumExact === 2);
    $timingReviewExact = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema=DATABASE() AND table_name='time_entries'
            AND ((column_name='worked_at' AND column_type='datetime'
                  AND is_nullable='NO' AND UPPER(column_default)='CURRENT_TIMESTAMP'
                  AND extra='DEFAULT_GENERATED')
              OR (column_name='started_at' AND column_type='datetime'
                  AND is_nullable='YES' AND column_default IS NULL AND extra='')
              OR (column_name='ended_at' AND column_type='datetime'
                  AND is_nullable='YES' AND column_default IS NULL AND extra='')
              OR (column_name='reviewed_by_user_id' AND column_type='int unsigned'
                  AND is_nullable='YES' AND column_default IS NULL AND extra='')
              OR (column_name='reviewed_at' AND column_type='datetime'
                  AND is_nullable='YES' AND column_default IS NULL AND extra='')
              OR (column_name='review_note' AND column_type='varchar(500)'
                  AND is_nullable='NO' AND column_default='' AND extra=''))"
    )->fetchColumn();
    check("{$label}: timing/review columns are exact", $timingReviewExact === 6);

    $indexRows = $pdo->query(
        "SELECT index_name,MIN(non_unique) AS non_unique,MIN(index_type) AS index_type,
                GROUP_CONCAT(column_name ORDER BY seq_in_index) AS columns_csv,
                SUM(sub_part IS NOT NULL) AS prefix_parts
           FROM information_schema.statistics
          WHERE table_schema=DATABASE() AND table_name='time_entries'
            AND index_name IN (
              'uq_time_entries_tenant_key','uq_time_entries_tenant_id',
              'ix_time_entries_ticket','ix_time_entries_user_worked',
              'ix_time_entries_client_status','ix_time_entries_approval_queue',
              'ix_time_entries_reviewer')
          GROUP BY index_name"
    )->fetchAll();
    $expectedIndexes = [
        'uq_time_entries_tenant_key' => [0, 'tenant_id,entry_key'],
        'uq_time_entries_tenant_id' => [0, 'tenant_id,id'],
        'ix_time_entries_ticket' => [1, 'tenant_id,ticket_id'],
        'ix_time_entries_user_worked' => [1, 'tenant_id,user_id,worked_at'],
        'ix_time_entries_client_status' => [1, 'tenant_id,client_id,approval_status,worked_at'],
        'ix_time_entries_approval_queue' => [1, 'tenant_id,approval_status,worked_at,id'],
        'ix_time_entries_reviewer' => [1, 'tenant_id,reviewed_by_user_id,reviewed_at'],
    ];
    $indexesExact = count($indexRows) === count($expectedIndexes);
    foreach ($indexRows as $row) {
        $expected = $expectedIndexes[(string) $row['index_name']] ?? null;
        $indexesExact = $indexesExact
            && $expected !== null
            && (int) $row['non_unique'] === $expected[0]
            && (string) $row['index_type'] === 'BTREE'
            && (string) $row['columns_csv'] === $expected[1]
            && (int) $row['prefix_parts'] === 0;
    }
    check("{$label}: seven named indexes have exact signatures", $indexesExact);

    $foreignKeyRows = $pdo->query(
        "SELECT constraint_name,MIN(referenced_table_name) AS referenced_table_name,
                GROUP_CONCAT(CONCAT(column_name,'=',referenced_column_name)
                             ORDER BY ordinal_position) AS columns_csv
           FROM information_schema.key_column_usage
          WHERE constraint_schema=DATABASE() AND table_name='time_entries'
            AND constraint_name IN (
              'fk_time_entries_tenant','fk_time_entries_ticket_tenant',
              'fk_time_entries_client_tenant','fk_time_entries_user_tenant',
              'fk_time_entries_reviewer_tenant')
          GROUP BY constraint_name"
    )->fetchAll();
    $expectedForeignKeys = [
        'fk_time_entries_tenant' => ['tenants', 'tenant_id=id'],
        'fk_time_entries_ticket_tenant' => ['tickets', 'tenant_id=tenant_id,ticket_id=id'],
        'fk_time_entries_client_tenant' => ['clients', 'tenant_id=tenant_id,client_id=id'],
        'fk_time_entries_user_tenant' => ['users', 'tenant_id=tenant_id,user_id=id'],
        'fk_time_entries_reviewer_tenant' => ['users', 'tenant_id=tenant_id,reviewed_by_user_id=id'],
    ];
    $foreignKeysExact = count($foreignKeyRows) === count($expectedForeignKeys);
    foreach ($foreignKeyRows as $row) {
        $expected = $expectedForeignKeys[(string) $row['constraint_name']] ?? null;
        $foreignKeysExact = $foreignKeysExact
            && $expected !== null
            && (string) $row['referenced_table_name'] === $expected[0]
            && (string) $row['columns_csv'] === $expected[1];
    }
    check("{$label}: five tenant-scoped foreign keys have exact mappings", $foreignKeysExact);

    $checkClause = (string) $pdo->query(
        "SELECT check_clause FROM information_schema.check_constraints
          WHERE constraint_schema=DATABASE() AND constraint_name='ck_time_entries_minutes'"
    )->fetchColumn();
    check("{$label}: minute bound check is exact",
        preg_replace('/[` ()]/', '', strtolower($checkClause)) === 'minutesbetween1and1440');
    $billableClause = (string) $pdo->query(
        "SELECT check_clause FROM information_schema.check_constraints
          WHERE constraint_schema=DATABASE() AND constraint_name='ck_time_entries_billable'"
    )->fetchColumn();
    check("{$label}: billable check is exact",
        preg_replace('/[` ()]/', '', strtolower($billableClause)) === 'billablein0,1');

    $eventColumnsExact = (int) $pdo->query(
        "SELECT COUNT(*)=10
             AND SUM(column_name='id' AND column_type='bigint unsigned' AND is_nullable='NO'
                     AND column_default IS NULL AND extra='auto_increment')=1
             AND SUM(column_name='tenant_id' AND column_type='int unsigned' AND is_nullable='NO')=1
             AND SUM(column_name='time_entry_id' AND column_type='int unsigned' AND is_nullable='NO')=1
             AND SUM(column_name='actor_user_id' AND column_type='int unsigned' AND is_nullable='NO')=1
             AND SUM(column_name='event_kind'
                     AND column_type=\"enum('logged','approved','rejected')\" AND is_nullable='NO')=1
             AND SUM(column_name='from_status'
                     AND column_type=\"enum('pending','approved','rejected')\" AND is_nullable='YES')=1
             AND SUM(column_name='to_status'
                     AND column_type=\"enum('pending','approved','rejected')\" AND is_nullable='NO')=1
             AND SUM(column_name='reason' AND column_type='varchar(500)'
                     AND is_nullable='NO' AND column_default='')=1
             AND SUM(column_name='snapshot_json' AND column_type='json' AND is_nullable='NO')=1
             AND SUM(column_name='created_at' AND column_type='datetime'
                     AND is_nullable='NO' AND UPPER(column_default)='CURRENT_TIMESTAMP')=1
           FROM information_schema.columns
          WHERE table_schema=DATABASE() AND table_name='time_entry_events'"
    )->fetchColumn();
    check("{$label}: event table columns have exact signatures", $eventColumnsExact === 1);

    $eventIndexesExact = (int) $pdo->query(
        "SELECT COUNT(*)=4 AND SUM(
             (index_name='PRIMARY' AND non_unique=0 AND columns_csv='id')
          OR (index_name='uq_time_entry_events_entry_kind' AND non_unique=0
              AND columns_csv='tenant_id,time_entry_id,event_kind')
          OR (index_name='ix_time_entry_events_entry' AND non_unique=1
              AND columns_csv='tenant_id,time_entry_id,id')
          OR (index_name='ix_time_entry_events_actor_created' AND non_unique=1
              AND columns_csv='tenant_id,actor_user_id,created_at')
         )=4
           FROM (
             SELECT index_name,MIN(non_unique) AS non_unique,
                    GROUP_CONCAT(column_name ORDER BY seq_in_index) AS columns_csv,
                    MIN(index_type) AS index_type,
                    SUM(sub_part IS NOT NULL) AS prefix_parts
               FROM information_schema.statistics
              WHERE table_schema=DATABASE() AND table_name='time_entry_events'
              GROUP BY index_name
             HAVING index_type='BTREE' AND prefix_parts=0
           ) event_indexes"
    )->fetchColumn();
    check("{$label}: event indexes have exact signatures", $eventIndexesExact === 1);

    $eventForeignKeysExact = (int) $pdo->query(
        "SELECT COUNT(*)=3 AND SUM(
             (constraint_name='fk_time_entry_events_tenant'
              AND referenced_table_name='tenants' AND columns_csv='tenant_id=id')
          OR (constraint_name='fk_time_entry_events_entry'
              AND referenced_table_name='time_entries'
              AND columns_csv='tenant_id=tenant_id,time_entry_id=id')
          OR (constraint_name='fk_time_entry_events_actor'
              AND referenced_table_name='users'
              AND columns_csv='tenant_id=tenant_id,actor_user_id=id')
         )=3
           FROM (
             SELECT constraint_name,MIN(referenced_table_name) AS referenced_table_name,
                    GROUP_CONCAT(CONCAT(column_name,'=',referenced_column_name)
                                 ORDER BY ordinal_position) AS columns_csv
               FROM information_schema.key_column_usage
              WHERE constraint_schema=DATABASE() AND table_name='time_entry_events'
                AND referenced_table_name IS NOT NULL
              GROUP BY constraint_name
           ) event_fks"
    )->fetchColumn();
    check("{$label}: event foreign keys have exact mappings", $eventForeignKeysExact === 1);

    $triggerRows = $pdo->query(
        "SELECT trigger_name,event_manipulation,action_timing,event_object_table
           FROM information_schema.triggers
          WHERE trigger_schema=DATABASE() AND trigger_name IN (
            'trg_time_entries_before_insert','trg_time_entries_after_insert',
            'trg_time_entries_before_update','trg_time_entries_after_update',
            'trg_time_entries_no_delete','trg_time_entry_events_no_update',
            'trg_time_entry_events_no_delete')"
    )->fetchAll();
    $expectedTriggers = [
        'trg_time_entries_before_insert' => ['INSERT','BEFORE','time_entries'],
        'trg_time_entries_after_insert' => ['INSERT','AFTER','time_entries'],
        'trg_time_entries_before_update' => ['UPDATE','BEFORE','time_entries'],
        'trg_time_entries_after_update' => ['UPDATE','AFTER','time_entries'],
        'trg_time_entries_no_delete' => ['DELETE','BEFORE','time_entries'],
        'trg_time_entry_events_no_update' => ['UPDATE','BEFORE','time_entry_events'],
        'trg_time_entry_events_no_delete' => ['DELETE','BEFORE','time_entry_events'],
    ];
    $triggersExact = count($triggerRows) === count($expectedTriggers);
    foreach ($triggerRows as $row) {
        $expected = $expectedTriggers[(string) $row['trigger_name']] ?? null;
        $triggersExact = $triggersExact
            && $expected !== null
            && [(string) $row['event_manipulation'], (string) $row['action_timing'],
                (string) $row['event_object_table']] === $expected;
    }
    check("{$label}: seven permanent triggers have exact timing/table signatures", $triggersExact);
    $staging = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.triggers
          WHERE trigger_schema=DATABASE() AND trigger_name LIKE '%staging%'"
    )->fetchColumn();
    check("{$label}: no staging trigger remains", $staging === 0);
}

$preflightUser = null;
$runtimeUser = null;
$underprivileged = null;
$runtime = null;
$fatalError = null;
$cleanupError = false;

try {
$selectedDatabase = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
check('fixture connection selected the exact random database',
    hash_equals($testDatabase, $selectedDatabase));
if (!hash_equals($testDatabase, $selectedDatabase)) {
    throw new RuntimeException('Fixture connection selected an unexpected database.');
}

$pdo->setAttribute(PDO::ATTR_CASE, PDO::CASE_LOWER);
$pdo->exec("SET SESSION sql_mode='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
$migrationPath = __DIR__ . '/../db/migrations/011_time_entry_approvals.sql';

// Canonical fresh install first: schema.sql must carry the same final contract.
reset_database($pdo);
execute_sql_file($pdo, __DIR__ . '/../db/schema.sql');
seed_ownership_fixture($pdo);
verify_exact_structure($pdo, 'fresh schema');

$pdo->exec("INSERT INTO time_entries
    (ticket_id,user_id,minutes,note,billable,created_at)
  VALUES (1001,103,25,'Old writer on fresh schema',1,'2026-08-26 10:25:00')");
$freshOldWriterId = (int) $pdo->lastInsertId();
$freshOldWriter = time_entry($pdo, $freshOldWriterId);
check('fresh schema accepts the deployed five-column writer',
    (int) ($freshOldWriter['tenant_id'] ?? 0) === 1
    && (int) ($freshOldWriter['client_id'] ?? 0) === 11
    && str_starts_with((string) ($freshOldWriter['entry_key'] ?? ''), 'legacy:')
    && ($freshOldWriter['approval_status'] ?? '') === 'pending');
check('fresh-schema write emits one logged event',
    (int) $pdo->query("SELECT COUNT(*) FROM time_entry_events WHERE time_entry_id={$freshOldWriterId}")->fetchColumn() === 1);

// Simulate a process dying after all staging guards are installed but before
// the first backfill. A retry on a fresh session must preserve those exact
// guards at every install boundary: old INSERT remains compatible while old
// merge-style UPDATE and DELETE stay continuously blocked.
reset_database($pdo);
create_legacy_schema($pdo);
seed_ownership_fixture($pdo);
$pdo->exec("INSERT INTO time_entries
  (id,ticket_id,user_id,minutes,note,billable,created_at) VALUES
  (1,1001,103,30,'Interrupted historical one',1,'2026-08-20 12:00:00'),
  (2,2001,201,45,'Interrupted historical two',0,'2026-08-21 13:00:00')");

$intentionalInterruption = false;
try {
    execute_sql_file($pdo, $migrationPath, function (string $statement): void {
        if (str_contains($statement, 'UPDATE time_entries time_entry')) {
            throw new UnexpectedValueException('intentional migration interruption');
        }
    });
} catch (UnexpectedValueException $error) {
    $intentionalInterruption = $error->getMessage() === 'intentional migration interruption';
}
check('interrupted migration leaves all three exact staging guards installed',
    $intentionalInterruption
    && staging_trigger_count($pdo) === 3
    && trigger_count($pdo) === 0);

// Use a new connection to model a new migration process. The old migration
// session remains irrelevant; its bypass cannot cross this connection boundary.
$pdo = parallel_connection();
$pdo->setAttribute(PDO::ATTR_CASE, PDO::CASE_LOWER);
$pdo->exec("SET SESSION sql_mode='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
check('interrupted retry starts without the prior migration session bypass',
    (int) $pdo->query('SELECT @safeharbor_time_migration IS NULL')->fetchColumn() === 1);

$retryInstallProbes = 0;
$parallelRetry = parallel_connection();
execute_sql_file($pdo, $migrationPath, function (string $statement) use (
    $parallelRetry,
    &$retryInstallProbes
): void {
    if (!preg_match(
        '/CREATE\s+TRIGGER\s+IF\s+NOT\s+EXISTS\s+trg_time_entry_(?:insert_compatibility|update_guard|delete_guard)_staging/i',
        $statement
    )) {
        return;
    }

    $retryInstallProbes++;
    $entryId = 10 + $retryInstallProbes;
    $parallelRetry->exec("INSERT INTO time_entries
      (id,ticket_id,user_id,minutes,note,billable,created_at)
      VALUES ({$entryId},1001,103,15,'Interrupted retry writer',1,'2026-08-22 14:00:00')");
    expect_pdo("interrupted retry boundary {$retryInstallProbes} blocks old merge-style rewrite",
        fn() => $parallelRetry->exec('UPDATE time_entries SET ticket_id=2001 WHERE id=1'), [], '45000');
    expect_pdo("interrupted retry boundary {$retryInstallProbes} blocks old deletion",
        fn() => $parallelRetry->exec('DELETE FROM time_entries WHERE id=2'), [], '45000');
});
$parallelRetry = null;
check('interrupted retry probes all three preserved staging installs', $retryInstallProbes === 3);
check('interrupted retry keeps the old deployed INSERT compatible at every boundary',
    (int) $pdo->query("SELECT COUNT(*) FROM time_entries
      WHERE id IN (11,12,13) AND tenant_id=1 AND client_id=11
        AND source='legacy' AND approval_status='pending'")->fetchColumn() === 3);
check('interrupted retry finishes with permanent guards only and clears its bypass',
    trigger_count($pdo) === 7
    && staging_trigger_count($pdo) === 0
    && (int) $pdo->query('SELECT @safeharbor_time_migration IS NULL')->fetchColumn() === 1);

// Genuine old-schema upgrade, including deterministic historical backfill.
reset_database($pdo);
create_legacy_schema($pdo);
seed_ownership_fixture($pdo);
$pdo->exec("INSERT INTO time_entries
  (id,ticket_id,user_id,minutes,note,billable,created_at) VALUES
  (1,1001,103,30,'Historical one',1,'2026-08-20 12:00:00'),
  (2,2001,201,45,'Historical two',0,'2026-08-21 13:00:00')");

$liveGapInsertRan = false;
$notNullStagingInsertRan = false;
$migrationGuardRan = false;
$parallel = parallel_connection();
execute_sql_file($pdo, $migrationPath, function (string $statement) use (
    $pdo,
    $parallel,
    &$liveGapInsertRan,
    &$notNullStagingInsertRan,
    &$migrationGuardRan
): void {
    if (!$liveGapInsertRan
        && str_contains($statement, 'CREATE TRIGGER IF NOT EXISTS trg_time_entry_insert_compatibility_staging')) {
        // This is the last instant before the staging compatibility trigger is
        // installed: all nullable columns exist, but the first backfill has not
        // started. It models an old deployed writer racing the migration.
        $pdo->exec("INSERT INTO time_entries
          (id,ticket_id,user_id,minutes,note,billable,created_at)
          VALUES (3,1001,103,12,'Writer during migration',1,'2026-08-22 14:00:00')");
        $liveGapInsertRan = true;
    }

    if (!$migrationGuardRan
        && str_contains($statement, 'UPDATE time_entries time_entry')) {
        // This is a genuinely separate old-application connection after all
        // three staging guards are active and immediately before backfill.
        // INSERT remains compatible; merge-style UPDATE and DELETE cannot
        // race the snapshot. The migration connection alone may update.
        $parallel->exec("INSERT INTO time_entries
          (id,ticket_id,user_id,minutes,note,billable,created_at)
          VALUES (5,1001,103,14,'Concurrent old writer',1,'2026-08-22 14:30:00')");
        expect_pdo('migration gap blocks old merge-style time rewrite',
            fn() => $parallel->exec('UPDATE time_entries SET ticket_id=2001 WHERE id=1'), [], '45000');
        expect_pdo('migration gap blocks old time deletion',
            fn() => $parallel->exec('DELETE FROM time_entries WHERE id=2'), [], '45000');
        $pdo->exec('UPDATE time_entries SET note=note WHERE id=1');
        $migrationGuardRan = true;
    }

    if (!$notNullStagingInsertRan
        && str_contains($statement, 'DROP TRIGGER IF EXISTS trg_time_entries_before_insert')) {
        // Required columns are now NOT NULL, but the staging trigger is the
        // only insert guard. Prove the old raw writer still succeeds here too.
        $pdo->exec("INSERT INTO time_entries
          (id,ticket_id,user_id,minutes,note,billable,created_at)
          VALUES (4,1001,103,18,'Writer under staging guard',1,'2026-08-23 15:00:00')");
        $notNullStagingInsertRan = true;
    }
});
$parallel = null;
verify_exact_structure($pdo, 'migrated schema');

$legacy = time_entry($pdo, 1);
check('legacy row snapshots ticket tenant/client deterministically',
    (int) ($legacy['tenant_id'] ?? 0) === 1
    && (int) ($legacy['client_id'] ?? 0) === 11);
check('legacy row is pending and never inferred approved',
    ($legacy['entry_key'] ?? '') === 'legacy:1'
    && ($legacy['source'] ?? '') === 'legacy'
    && ($legacy['worked_at'] ?? '') === '2026-08-20 12:00:00'
    && ($legacy['approval_status'] ?? '') === 'pending'
    && ($legacy['reviewed_by_user_id'] ?? null) === null
    && ($legacy['reviewed_at'] ?? null) === null
    && ($legacy['review_note'] ?? null) === '');
check('nullable-column migration gap accepted the old deployed writer',
    $liveGapInsertRan
    && (time_entry($pdo, 3)['entry_key'] ?? '') === 'legacy:3'
    && (time_entry($pdo, 3)['approval_status'] ?? '') === 'pending');
check('guarded migration gap accepts old inserts and controlled backfill only',
    $migrationGuardRan
    && (int) (time_entry($pdo, 5)['tenant_id'] ?? 0) === 1
    && (int) (time_entry($pdo, 5)['client_id'] ?? 0) === 11
    && str_starts_with((string) (time_entry($pdo, 5)['entry_key'] ?? ''), 'legacy:')
    && (time_entry($pdo, 5)['approval_status'] ?? '') === 'pending');
check('migration session bypass is cleared after permanent guards install',
    (int)$pdo->query('SELECT @safeharbor_time_migration IS NULL')->fetchColumn() === 1);
check('staging trigger accepts old writer after final NOT NULL enforcement',
    $notNullStagingInsertRan
    && (int) (time_entry($pdo, 4)['tenant_id'] ?? 0) === 1
    && (int) (time_entry($pdo, 4)['client_id'] ?? 0) === 11
    && str_starts_with((string) (time_entry($pdo, 4)['entry_key'] ?? ''), 'legacy:')
    && (time_entry($pdo, 4)['approval_status'] ?? '') === 'pending');
check('legacy backfill emits one original-time log event per row',
    (int) $pdo->query("SELECT COUNT(*) FROM time_entry_events WHERE event_kind='logged'")->fetchColumn() === 5
    && $pdo->query("SELECT created_at FROM time_entry_events WHERE time_entry_id=1")->fetchColumn()
        === '2026-08-20 12:00:00');

// A real structural replay must be silent and idempotent.
execute_sql_file($pdo, $migrationPath);
check('migration structural replay keeps one event per legacy row',
    (int) $pdo->query("SELECT COUNT(*) FROM time_entry_events WHERE event_kind='logged'")->fetchColumn() === 5);
check('migration structural replay leaves all guards installed', trigger_count($pdo) === 7);

// Old deployed writers remain valid after the final NOT NULL contract.
$pdo->exec("INSERT INTO time_entries
    (ticket_id,user_id,minutes,note,billable)
  VALUES (1001,103,20,'Old writer after migration',1)");
$compatId = (int) $pdo->lastInsertId();
$compat = time_entry($pdo, $compatId);
check('migration compatibility trigger derives ownership/key/defaults',
    (int) ($compat['tenant_id'] ?? 0) === 1
    && (int) ($compat['client_id'] ?? 0) === 11
    && str_starts_with((string) ($compat['entry_key'] ?? ''), 'legacy:')
    && ($compat['source'] ?? '') === 'legacy'
    && ($compat['approval_status'] ?? '') === 'pending');
check('compatibility insert emits one log event',
    (int) $pdo->query("SELECT COUNT(*) FROM time_entry_events WHERE time_entry_id={$compatId}")->fetchColumn() === 1);

// Idempotency and tenant isolation.
$insert = $pdo->prepare("INSERT INTO time_entries
  (tenant_id,client_id,entry_key,ticket_id,user_id,minutes,note,billable,source,worked_at)
  VALUES (?,?,?,?,?,?,?,?,?,?)");
$insert->execute([1,11,'reply:key-1',1001,103,60,'Reply work',1,'reply','2026-08-26 11:00:00']);
expect_pdo('duplicate entry key is rejected within tenant',
    fn() => $insert->execute([1,11,'reply:key-1',1001,103,60,'Duplicate',1,'reply','2026-08-26 11:00:00']),
    [1062], '23000');
$insert->execute([2,22,'reply:key-1',2001,201,10,'Same key other tenant',0,'reply','2026-08-26 11:10:00']);
check('same entry key is allowed in a different tenant', (int) $pdo->lastInsertId() > 0);
expect_pdo('explicit cross-tenant ticket snapshot is rejected by trigger',
    fn() => $insert->execute([2,22,'bad-tenant',1001,103,10,'Bad',1,'reply','2026-08-26 11:00:00']),
    [], '45000');
expect_pdo('cross-tenant technician is rejected by composite foreign key',
    fn() => $insert->execute([1,11,'bad-user',1001,201,10,'Bad',1,'reply','2026-08-26 11:00:00']),
    [1452], '23000');

expect_pdo('zero minutes is rejected by database check',
    fn() => $insert->execute([1,11,'bad-zero',1001,103,0,'Bad',1,'reply','2026-08-26 11:00:00']),
    [3819]);
expect_pdo('more than one day is rejected by database check',
    fn() => $insert->execute([1,11,'bad-large',1001,103,1441,'Bad',1,'reply','2026-08-26 11:00:00']),
    [3819]);
$insert->execute([1,11,'bound-one',1001,103,1,'Minimum',1,'reply','2026-08-26 11:00:00']);
$insert->execute([1,11,'bound-day',1001,103,1440,'Maximum',1,'reply','2026-08-26 11:00:00']);
check('one and 1440 minute bounds are accepted', true);
expect_pdo('billable value outside zero or one is rejected',
    fn() => $insert->execute([1,11,'bad-billable',1001,103,10,'Bad',2,'reply','2026-08-26 11:00:00']),
    [3819]);

$intervalInsert = $pdo->prepare("INSERT INTO time_entries
  (entry_key,ticket_id,user_id,minutes,note,billable,source,worked_at,started_at,ended_at)
  VALUES (?,?,?,?,?,?,?,?,?,?)");
expect_pdo('half-open work interval is rejected',
    fn() => $intervalInsert->execute(['bad-interval',1001,103,10,'Bad',1,'timer',
        '2026-08-26 11:00:00','2026-08-26 10:00:00',null]), [], '45000');
expect_pdo('timer without start/end evidence is rejected',
    fn() => $insert->execute([1,11,'timer-no-evidence',1001,103,60,'Bad',1,'timer','2026-08-26 10:30:00']),
    [], '45000');
expect_pdo('suggestion cannot claim timer evidence',
    fn() => $intervalInsert->execute(['suggestion-fake-evidence',1001,103,60,'Bad',1,'suggestion',
        '2026-08-26 10:30:00','2026-08-26 10:00:00','2026-08-26 11:00:00']), [], '45000');
expect_pdo('worked_at outside measured interval is rejected',
    fn() => $intervalInsert->execute(['timer-worked-outside',1001,103,60,'Bad',1,'timer',
        '2026-08-26 12:00:00','2026-08-26 10:00:00','2026-08-26 11:00:00']), [], '45000');
expect_pdo('measured interval over 24 hours is rejected',
    fn() => $intervalInsert->execute(['timer-too-long',1001,103,1440,'Bad',1,'timer',
        '2026-08-27 10:00:00','2026-08-26 10:00:00','2026-08-27 11:00:00']), [], '45000');
expect_pdo('minutes mismatching measured interval are rejected',
    fn() => $intervalInsert->execute(['timer-minute-mismatch',1001,103,30,'Bad',1,'timer',
        '2026-08-26 10:30:00','2026-08-26 10:00:00','2026-08-26 11:00:00']), [], '45000');
$intervalInsert->execute(['timer-valid',1001,103,60,'Measured timer',1,'timer',
    '2026-08-26 10:30:00','2026-08-26 10:00:00','2026-08-26 11:00:00']);
$timerId = (int) $pdo->lastInsertId();
check('valid measured timer evidence is accepted', $timerId > 0);
$insert->execute([1,11,'suggestion-no-evidence',1001,103,10,'Suggested',0,'suggestion','2026-08-26 11:00:00']);
check('suggestion without fake measurement evidence is accepted', (int) $pdo->lastInsertId() > 0);

// Review authority is a DB invariant, not only an API role check.
$review = $pdo->prepare("UPDATE time_entries
  SET approval_status=?, reviewed_by_user_id=?, reviewed_at=?, review_note=? WHERE id=?");
expect_pdo('active technician cannot approve time',
    fn() => $review->execute(['approved',103,'2000-01-01 00:00:00','',$timerId]), [], '45000');
expect_pdo('inactive admin cannot approve time',
    fn() => $review->execute(['approved',104,'2000-01-01 00:00:00','',$timerId]), [], '45000');
expect_pdo('other-tenant owner cannot approve time',
    fn() => $review->execute(['approved',201,'2000-01-01 00:00:00','',$timerId]), [], '45000');

$beforeReview = gmdate('Y-m-d H:i:s', time() - 2);
$review->execute(['approved',102,'2000-01-01 00:00:00','  checked  ',$timerId]);
$approved = time_entry($pdo, $timerId);
check('active admin can approve pending time',
    ($approved['approval_status'] ?? '') === 'approved'
    && (int) ($approved['reviewed_by_user_id'] ?? 0) === 102
    && ($approved['review_note'] ?? '') === 'checked');
check('database overwrites caller review time with UTC now',
    ($approved['reviewed_at'] ?? '') >= $beforeReview
    && ($approved['reviewed_at'] ?? '') !== '2000-01-01 00:00:00');
check('approval emits one immutable snapshot event',
    (int) $pdo->query("SELECT COUNT(*) FROM time_entry_events
      WHERE time_entry_id={$timerId} AND event_kind='approved'")->fetchColumn() === 1
    && (string) $pdo->query("SELECT JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.approval_status'))
      FROM time_entry_events WHERE time_entry_id={$timerId} AND event_kind='approved'")->fetchColumn() === 'approved');

expect_pdo('approved entry cannot transition again',
    fn() => $review->execute(['rejected',102,null,'Changed',$timerId]), [], '45000');
expect_pdo('time-entry factual minutes are immutable',
    fn() => $pdo->exec("UPDATE time_entries SET minutes=61 WHERE id={$timerId}"), [], '45000');
expect_pdo('time entries cannot be deleted',
    fn() => $pdo->exec("DELETE FROM time_entries WHERE id={$timerId}"), [], '45000');

$insert->execute([1,11,'reject-key',1001,103,15,'Reject me',1,'reply','2026-08-26 12:00:00']);
$rejectId = (int) $pdo->lastInsertId();
expect_pdo('rejection without reason is refused',
    fn() => $review->execute(['rejected',101,null,'   ',$rejectId]), [], '45000');
$review->execute(['rejected',101,null,'Not customer work',$rejectId]);
check('owner rejection records mandatory reason',
    (time_entry($pdo, $rejectId)['review_note'] ?? '') === 'Not customer work'
    && (string) $pdo->query("SELECT reason FROM time_entry_events
      WHERE time_entry_id={$rejectId} AND event_kind='rejected'")->fetchColumn() === 'Not customer work');

$eventId = (int) $pdo->query("SELECT id FROM time_entry_events WHERE time_entry_id={$timerId} LIMIT 1")->fetchColumn();
expect_pdo('event snapshots cannot be updated',
    fn() => $pdo->exec("UPDATE time_entry_events SET reason='tamper' WHERE id={$eventId}"), [], '45000');
expect_pdo('event snapshots cannot be deleted',
    fn() => $pdo->exec("DELETE FROM time_entry_events WHERE id={$eventId}"), [], '45000');

// A malformed same-named partial object must fail visibly, not be accepted as
// an idempotent replay. Restore it after observing the duplicate-column error.
$pdo->exec("ALTER TABLE time_entries ALTER COLUMN review_note SET DEFAULT 'wrong'");
expect_pdo('migration rejects malformed same-named column on replay',
    fn() => execute_sql_file($pdo, $migrationPath), [1060], '42S21');
$pdo->exec("ALTER TABLE time_entries ALTER COLUMN review_note SET DEFAULT ''");
check('malformed replay did not remove immutable triggers', trigger_count($pdo) === 7);

// Interrupted-replay names are not enough: every preserved staging trigger
// must have the exact migration body. A malformed partial trigger fails before
// any permanent guard is removed.
$malformedStagingTriggers = [
    'insert' => "CREATE TRIGGER trg_time_entry_insert_compatibility_staging
      BEFORE INSERT ON time_entries FOR EACH ROW SET @malformed_time_guard=1",
    'update' => "CREATE TRIGGER trg_time_entry_update_guard_staging
      BEFORE UPDATE ON time_entries FOR EACH ROW SET @malformed_time_guard=1",
    'delete' => "CREATE TRIGGER trg_time_entry_delete_guard_staging
      BEFORE DELETE ON time_entries FOR EACH ROW SET @malformed_time_guard=1",
];
foreach ($malformedStagingTriggers as $kind => $triggerSql) {
    $pdo->exec($triggerSql);
    expect_pdo("migration rejects malformed {$kind} staging trigger on replay",
        fn() => execute_sql_file($pdo, $migrationPath), [1109,1146], '42S02');
    $pdo->exec('DROP TRIGGER IF EXISTS trg_time_entry_' . ($kind === 'insert'
        ? 'insert_compatibility'
        : $kind . '_guard') . '_staging');
}
check('malformed staging replays leave all permanent guards installed',
    trigger_count($pdo) === 7 && staging_trigger_count($pdo) === 0);

// Practical underprivileged replay proof. This runs when the operator can
// create a temporary database user (the production migration operator can).
$currentUser = (string) $pdo->query('SELECT CURRENT_USER()')->fetchColumn();
$preflightUser = 'sh_time_pf_' . $TIME_MYSQL_RUN_ID;
$preflightPass = bin2hex(random_bytes(24));
$underprivilegedProven = false;
$underprivilegedError = '';
try {
    $server->exec("DROP USER IF EXISTS '{$preflightUser}'@'%'");
    $server->exec("CREATE USER '{$preflightUser}'@'%' IDENTIFIED BY '{$preflightPass}'");
    $server->exec("GRANT SELECT,INSERT,UPDATE,DELETE,CREATE,DROP,ALTER,INDEX,REFERENCES
        ON `{$testDatabase}`.* TO '{$preflightUser}'@'%'");

    $underprivileged = new PDO(
        $serverDsn . ';dbname=' . $testDatabase,
        $preflightUser,
        $preflightPass,
        $pdoOptions,
    );
    try {
        execute_sql_file($underprivileged, $migrationPath);
    } catch (PDOException $error) {
        $driverCode = (int) ($error->errorInfo[1] ?? 0);
        $underprivilegedError = $error->getCode() . '/' . $driverCode;
        $underprivilegedProven = in_array($driverCode, [1142, 1143, 1227, 1419], true);
    }
    $underprivileged = null;
} catch (PDOException $error) {
    echo "# underprivileged preflight setup unavailable for {$currentUser}: {$error->getCode()}\n";
} finally {
    try {
        $server->exec("DROP USER IF EXISTS '{$preflightUser}'@'%'");
    } catch (PDOException) {
        // The explicit check below records whether this practical proof ran.
    }
}
if (!$underprivilegedProven && $underprivilegedError !== '') {
    echo "# underprivileged replay error was {$underprivilegedError}\n";
}
check('underprivileged replay fails at trigger privilege preflight', $underprivilegedProven);
check('underprivileged replay leaves all seven guards installed', trigger_count($pdo) === 7);

// Model the actual runtime database identity: ordinary DML only, with no DDL
// or TRIGGER privilege. Trigger-defined audit writes still execute, approved
// review DML works, and immutable/delete guards remain authoritative.
$runtimeUser = 'sh_time_rt_' . $TIME_MYSQL_RUN_ID;
$runtimePass = bin2hex(random_bytes(24));
$runtimeProven = false;
try {
    $server->exec("DROP USER IF EXISTS '{$runtimeUser}'@'%'");
    $server->exec("CREATE USER '{$runtimeUser}'@'%' IDENTIFIED BY '{$runtimePass}'");
    $server->exec("GRANT SELECT,INSERT,UPDATE,DELETE
        ON `{$testDatabase}`.* TO '{$runtimeUser}'@'%'");

    $runtime = new PDO(
        $serverDsn . ';dbname=' . $testDatabase,
        $runtimeUser,
        $runtimePass,
        $pdoOptions,
    );
    $runtime->exec("SET time_zone = '+00:00'");
    $runtime->exec("INSERT INTO time_entries
      (ticket_id,user_id,minutes,note,billable)
      VALUES (1001,103,22,'Least-privilege runtime writer',1)");
    $runtimeEntryId = (int) $runtime->lastInsertId();
    check('least-privilege runtime account can insert and read guarded time',
        (int) $runtime->query("SELECT COUNT(*) FROM time_entries
          WHERE id={$runtimeEntryId} AND tenant_id=1 AND client_id=11
            AND approval_status='pending'")->fetchColumn() === 1);

    $runtimeReview = $runtime->prepare("UPDATE time_entries
      SET approval_status='approved',reviewed_by_user_id=101,
          reviewed_at='2000-01-01 00:00:00',review_note='Runtime approval proof'
      WHERE id=?");
    $runtimeReview->execute([$runtimeEntryId]);
    check('least-privilege runtime account can perform an allowed guarded review',
        (string) $runtime->query("SELECT approval_status FROM time_entries
          WHERE id={$runtimeEntryId}")->fetchColumn() === 'approved');
    expect_pdo('least-privilege runtime delete remains blocked by immutable guard',
        fn() => $runtime->exec("DELETE FROM time_entries WHERE id={$runtimeEntryId}"), [], '45000');
    expect_pdo('least-privilege runtime account cannot truncate time entries',
        fn() => $runtime->exec('TRUNCATE TABLE time_entries'), [1142], '42000');
    expect_pdo('least-privilege runtime account cannot drop a time-entry trigger',
        fn() => $runtime->exec('DROP TRIGGER trg_time_entries_no_delete'), [1142,1227], '42000');
    expect_pdo('least-privilege runtime account cannot run migration DDL',
        fn() => $runtime->exec('ALTER TABLE time_entries ADD COLUMN runtime_forbidden INT NULL'),
        [1142], '42000');
    $runtimeProven = true;
    $runtime = null;
} catch (PDOException $error) {
    echo "# least-privilege runtime proof unavailable for {$currentUser}: {$error->getCode()}\n";
} finally {
    try {
        $runtime = null;
        $server->exec("DROP USER IF EXISTS '{$runtimeUser}'@'%'");
    } catch (PDOException) {
        // The explicit check below records whether this practical proof ran.
    }
}
check('least-privilege runtime proof executed with all guards retained',
    $runtimeProven && trigger_count($pdo) === 7);

} catch (Throwable $error) {
    $fatalError = $error;
} finally {
    $underprivileged = null;
    $runtime = null;
    $pdo = null;

    if ($server instanceof PDO) {
        foreach ([$preflightUser, $runtimeUser] as $temporaryUser) {
            if (!is_string($temporaryUser) || $temporaryUser === '') continue;
            try {
                $server->exec("DROP USER IF EXISTS '{$temporaryUser}'@'%'");
            } catch (Throwable) {
                $cleanupError = true;
            }
        }
        if ($databaseCreated) {
            try {
                $server->exec("DROP DATABASE IF EXISTS {$quotedDatabase}");
            } catch (Throwable) {
                $cleanupError = true;
            }
        }
    }
}

if ($fatalError instanceof Throwable) {
    fwrite(STDERR, 'Time-entry MySQL test execution failed: ' . $fatalError::class . PHP_EOL);
    exit(1);
}
if ($cleanupError) {
    fwrite(STDERR, "Time-entry MySQL fixture cleanup failed.\n");
    exit(1);
}

echo "---\n{$checks} checks, {$failures} failures\n";
exit($failures > 0 ? 1 : 0);
