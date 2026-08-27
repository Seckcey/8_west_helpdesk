<?php
/**
 * MySQL 8 proof for migration 016, append-only rejected-time corrections, and
 * transaction-race-safe measured-interval overlap prevention.
 *
 * The supplied database value is only a safe base. This harness appends a
 * random suffix, creates that exact database, proves it selected the new
 * database, and drops it in finally. The server acknowledgement is mandatory.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);

$disposableServer = getenv('SAFEHARBOR_TIME_TEST_DISPOSABLE_SERVER');
if ($disposableServer !== '1') {
    fwrite(STDERR, "Refusing MySQL correction test without disposable-server acknowledgement.\n");
    exit(2);
}

$databaseBase = getenv('SAFEHARBOR_TIME_TEST_DB');
if (!is_string($databaseBase)
    || preg_match('/\Asafeharbor_time_test(?:_[a-z0-9_]+)?\z/', $databaseBase) !== 1
    || strlen($databaseBase) > 40
) {
    fwrite(STDERR, "Refusing destructive correction-test database base.\n");
    exit(2);
}

$host = getenv('SAFEHARBOR_TIME_TEST_HOST') ?: '127.0.0.1';
$portText = getenv('SAFEHARBOR_TIME_TEST_PORT') ?: '3306';
$operatorUser = getenv('SAFEHARBOR_TIME_TEST_USER') ?: 'root';
$operatorPass = getenv('SAFEHARBOR_TIME_TEST_PASS') ?: '';
if (!is_string($host) || trim($host) === ''
    || !is_string($portText) || !ctype_digit($portText)
    || (int)$portText < 1 || (int)$portText > 65535
    || !is_string($operatorUser) || $operatorUser === ''
) {
    fwrite(STDERR, "Refusing invalid correction-test connection settings.\n");
    exit(2);
}

$serverDsn = "mysql:host={$host};port={$portText};charset=utf8mb4";
$pdoOptions = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

// A separate PHP process is used for the losing half of the real race. It
// connects only to the exact random database created by the parent harness.
if (getenv('SAFEHARBOR_TIME_CORRECTION_RACE_WORKER') === '1') {
    $workerDatabase = getenv('SAFEHARBOR_TIME_CORRECTION_WORKER_DB');
    if (!is_string($workerDatabase)
        || preg_match('/\Asafeharbor_time_test(?:_[a-z0-9_]+)?_[a-f0-9]{12}\z/', $workerDatabase) !== 1
    ) {
        fwrite(STDERR, "Race worker refused its database.\n");
        exit(2);
    }
    try {
        $worker = new PDO(
            $serverDsn . ';dbname=' . $workerDatabase,
            $operatorUser,
            $operatorPass,
            $pdoOptions,
        );
        $worker->exec("SET time_zone = '+00:00'");
        echo "ready\n";
        flush();
        $worker->exec("INSERT INTO time_entries
          (entry_key,ticket_id,user_id,minutes,note,billable,source,
           worked_at,started_at,ended_at)
          VALUES
          ('timer.mysql.race.child',1001,103,15,'Race child',1,'timer',
           '2026-08-25 18:45:00','2026-08-25 18:30:00','2026-08-25 18:45:00')");
        echo "unexpected-success\n";
        exit(3);
    } catch (PDOException $error) {
        if ($error->getCode() === '45000'
            && str_contains($error->getMessage(), 'Measured time overlaps existing pending or approved time')
        ) {
            echo "overlap-conflict\n";
            exit(0);
        }
        fwrite(STDERR, 'Race worker failed with ' . $error::class . PHP_EOL);
        exit(1);
    }
}

$runId = bin2hex(random_bytes(6));
$testDatabase = $databaseBase . '_correction_' . $runId;
$quotedDatabase = '`' . str_replace('`', '``', $testDatabase) . '`';
$server = null;
$pdo = null;
$databaseCreated = false;
$cleanupError = false;
$fatalError = null;
$temporaryUsers = [];
$checks = 0;
$failures = 0;

function correction_check(string $name, bool $condition): void
{
    global $checks, $failures;
    $checks++;
    if ($condition) {
        echo "ok {$checks} - {$name}\n";
    } else {
        $failures++;
        echo "FAIL {$checks} - {$name}\n";
    }
}

function correction_expect(
    string $name,
    callable $operation,
    string $sqlState = '',
    string $messageFragment = '',
): void {
    try {
        $operation();
        correction_check($name, false);
    } catch (Throwable $error) {
        correction_check(
            $name,
            ($sqlState === '' || $error->getCode() === $sqlState)
                && ($messageFragment === '' || str_contains($error->getMessage(), $messageFragment)),
        );
    }
}

/** @return list<string> */
function correction_sql_statements(string $sql): array
{
    $delimiter = ';';
    $buffer = '';
    $statements = [];
    foreach (preg_split('/\R/', $sql) ?: [] as $line) {
        if (trim($buffer) === '' && preg_match('/^\s*--/', $line) === 1) {
            continue;
        }
        if (preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $match)) {
            if (trim($buffer) !== '') throw new RuntimeException('DELIMITER changed with pending SQL');
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
    if (trim($buffer) !== '') throw new RuntimeException('Unterminated SQL statement');
    return $statements;
}

function correction_execute_sql_file(PDO $pdo, string $path): void
{
    $sql = file_get_contents($path);
    if ($sql === false) throw new RuntimeException('Cannot read SQL fixture');
    foreach (correction_sql_statements($sql) as $statement) {
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

/** @return array<string, mixed> */
function correction_execute_sql_file_with_postflight(PDO $pdo, string $path): array
{
    $sql = file_get_contents($path);
    if ($sql === false) throw new RuntimeException('Cannot read SQL fixture');
    $postflight = null;
    foreach (correction_sql_statements($sql) as $statement) {
        $withoutComments = preg_replace('/\A(?:\s*--[^\n]*(?:\n|\z))+/', '', $statement) ?? $statement;
        if (preg_match(
            '/^\s*SELECT\s+@time_correction_column_exact\s+AS\s+correction_column_exact/is',
            $withoutComments,
        ) === 1) {
            $row = $pdo->query($statement)->fetch(PDO::FETCH_ASSOC);
            $postflight = is_array($row) ? $row : null;
        } elseif (preg_match('/^\s*SELECT\b/i', $withoutComments) === 1) {
            $result = $pdo->query($statement);
            $result->fetchAll();
            $result->closeCursor();
        } else {
            $pdo->exec($statement);
        }
    }
    if (!is_array($postflight)) throw new RuntimeException('Migration 016 postflight row was not emitted');
    return $postflight;
}

/** Execute migration 016 only through the seven permanent-trigger drops. */
function correction_interrupt_migration_at_guard_swap(PDO $pdo, string $path): void
{
    $sql = file_get_contents($path);
    if ($sql === false) throw new RuntimeException('Cannot read SQL fixture');
    $statements = correction_sql_statements($sql);
    $replacementIndex = null;
    foreach ($statements as $index => $statement) {
        if (str_starts_with(trim($statement), 'CREATE TRIGGER trg_time_entries_before_insert')) {
            $replacementIndex = $index;
            break;
        }
    }
    if (!is_int($replacementIndex)) throw new RuntimeException('Cannot locate migration 016 guard swap');
    foreach (array_slice($statements, 0, $replacementIndex) as $statement) {
        $withoutComments = preg_replace('/\A(?:\s*--[^\n]*(?:\n|\z))+/', '', $statement) ?? $statement;
        if (preg_match('/^\s*SELECT\b/i', $withoutComments) === 1) {
            $result = $pdo->query($statement);
            $result->fetchAll();
            $result->closeCursor();
        } else {
            $pdo->exec($statement);
        }
    }
}

/** Execute migration 016 only through the five auxiliary-trigger drops. */
function correction_interrupt_migration_at_aux_guard_swap(PDO $pdo, string $path): void
{
    $sql = file_get_contents($path);
    if ($sql === false) throw new RuntimeException('Cannot read SQL fixture');
    $statements = correction_sql_statements($sql);
    $replacementIndex = null;
    foreach ($statements as $index => $statement) {
        if (str_starts_with(trim($statement), 'CREATE TRIGGER trg_time_interval_guards_no_update')) {
            $replacementIndex = $index;
            break;
        }
    }
    if (!is_int($replacementIndex)) throw new RuntimeException('Cannot locate migration 016 auxiliary guard swap');
    foreach (array_slice($statements, 0, $replacementIndex) as $statement) {
        $withoutComments = preg_replace('/\A(?:\s*--[^\n]*(?:\n|\z))+/', '', $statement) ?? $statement;
        if (preg_match('/^\s*SELECT\b/i', $withoutComments) === 1) {
            $result = $pdo->query($statement);
            $result->fetchAll();
            $result->closeCursor();
        } else {
            $pdo->exec($statement);
        }
    }
}

function correction_reset_database(PDO $pdo): void
{
    $tables = $pdo->query(
        'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()'
    )->fetchAll(PDO::FETCH_COLUMN);
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables as $table) {
        $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', (string)$table) . '`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
}

function correction_create_legacy_schema(PDO $pdo): void
{
    foreach ([
        "CREATE TABLE tenants (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,name VARCHAR(128) NOT NULL,
          slug VARCHAR(64) NOT NULL,PRIMARY KEY(id),UNIQUE KEY uq_tenants_slug(slug)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE users (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,tenant_id INT UNSIGNED NOT NULL,
          email VARCHAR(190) NOT NULL,password_hash VARCHAR(255) NOT NULL DEFAULT '',
          full_name VARCHAR(128) NOT NULL,initials VARCHAR(4) NOT NULL DEFAULT '',
          color CHAR(7) NOT NULL DEFAULT '#2D8CFF',
          role ENUM('owner','admin','tech') NOT NULL DEFAULT 'tech',
          is_active TINYINT(1) NOT NULL DEFAULT 1,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY(id),UNIQUE KEY uq_users_tenant_email(tenant_id,email),
          KEY ix_users_tenant(tenant_id),
          CONSTRAINT fk_users_tenant FOREIGN KEY(tenant_id) REFERENCES tenants(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE clients (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,tenant_id INT UNSIGNED NOT NULL,
          name VARCHAR(128) NOT NULL,PRIMARY KEY(id),KEY ix_clients_tenant(tenant_id),
          CONSTRAINT fk_clients_tenant FOREIGN KEY(tenant_id) REFERENCES tenants(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE tickets (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,tenant_id INT UNSIGNED NOT NULL,
          client_id INT UNSIGNED NOT NULL,subject VARCHAR(190) NOT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(id),
          KEY ix_tickets_tenant(tenant_id),KEY ix_tickets_client(client_id),
          CONSTRAINT fk_tickets_tenant FOREIGN KEY(tenant_id) REFERENCES tenants(id),
          CONSTRAINT fk_tickets_client FOREIGN KEY(client_id) REFERENCES clients(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE time_entries (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,ticket_id INT UNSIGNED NOT NULL,
          user_id INT UNSIGNED NOT NULL,minutes INT UNSIGNED NOT NULL,
          note VARCHAR(255) NOT NULL DEFAULT '',billable TINYINT(1) NOT NULL DEFAULT 1,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(id),
          KEY ix_time_ticket(ticket_id),KEY ix_time_user_created(user_id,created_at),
          CONSTRAINT fk_time_ticket FOREIGN KEY(ticket_id) REFERENCES tickets(id),
          CONSTRAINT fk_time_user FOREIGN KEY(user_id) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ] as $statement) {
        $pdo->exec($statement);
    }
}

function correction_seed(PDO $pdo): void
{
    $pdo->exec("INSERT INTO tenants(id,name,slug) VALUES
      (1,'Tenant One','one'),(2,'Tenant Two','two')");
    $pdo->exec("INSERT INTO clients(id,tenant_id,name) VALUES
      (11,1,'Client One'),(12,1,'Client Other'),(22,2,'Client Two')");
    $pdo->exec("INSERT INTO users
      (id,tenant_id,email,password_hash,full_name,initials,role,is_active) VALUES
      (101,1,'owner1@example.test','','Owner One','O1','owner',1),
      (102,1,'admin1@example.test','','Admin One','A1','admin',1),
      (103,1,'tech1@example.test','','Tech One','T1','tech',1),
      (105,1,'tech2@example.test','','Tech Two','T2','tech',1),
      (201,2,'owner2@example.test','','Owner Two','O2','owner',1)");
    $hasFullTicket = (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema=DATABASE() AND table_name='tickets' AND column_name='sla_due_at'"
    )->fetchColumn() === 1;
    if ($hasFullTicket) {
        $pdo->exec("INSERT INTO tickets
          (id,tenant_id,client_id,subject,status,priority,channel,sla_due_at,created_at,updated_at) VALUES
          (1001,1,11,'Ticket One','open','normal','phone','2026-08-27 00:00:00','2026-08-26 10:00:00','2026-08-26 10:00:00'),
          (1002,1,12,'Ticket Other','open','normal','phone','2026-08-27 00:00:00','2026-08-26 10:00:00','2026-08-26 10:00:00'),
          (2001,2,22,'Ticket Two','open','normal','phone','2026-08-27 00:00:00','2026-08-26 10:00:00','2026-08-26 10:00:00')");
    } else {
        $pdo->exec("INSERT INTO tickets(id,tenant_id,client_id,subject,created_at) VALUES
          (1001,1,11,'Ticket One','2026-08-26 10:00:00'),
          (1002,1,12,'Ticket Other','2026-08-26 10:00:00'),
          (2001,2,22,'Ticket Two','2026-08-26 10:00:00')");
    }
}

function correction_trigger_count(PDO $pdo): int
{
    return (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.triggers
          WHERE trigger_schema=DATABASE() AND trigger_name IN (
            'trg_time_entries_before_insert','trg_time_entries_after_insert',
            'trg_time_entries_before_update','trg_time_entries_after_update',
            'trg_time_entries_no_delete','trg_time_entry_events_no_update',
            'trg_time_entry_events_no_delete','trg_time_interval_guards_no_update',
            'trg_time_interval_guards_no_delete','trg_time_measured_before_insert',
            'trg_time_measured_before_update','trg_time_measured_no_delete')"
    )->fetchColumn();
}

function correction_verify_structure(PDO $pdo, string $label): void
{
    $correctionColumn = (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema=DATABASE() AND table_name='time_entries'
            AND column_name='corrects_time_entry_id' AND column_type='int unsigned'
            AND is_nullable='YES' AND column_default IS NULL"
    )->fetchColumn();
    correction_check("{$label}: correction column is exact", $correctionColumn === 1);
    $tables = (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema=DATABASE() AND table_name IN
            ('time_entry_interval_guards','time_entry_measured_intervals')
            AND engine='InnoDB'"
    )->fetchColumn();
    correction_check("{$label}: both InnoDB guard tables exist", $tables === 2);
    $unique = (string)$pdo->query(
        "SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index)
           FROM information_schema.statistics
          WHERE table_schema=DATABASE() AND table_name='time_entries'
            AND index_name='uq_time_entries_one_correction' AND non_unique=0"
    )->fetchColumn();
    correction_check("{$label}: one replacement per rejected row is unique", $unique === 'tenant_id,corrects_time_entry_id');
    $overlapIndex = (string)$pdo->query(
        "SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index)
           FROM information_schema.statistics
          WHERE table_schema=DATABASE() AND table_name='time_entry_measured_intervals'
            AND index_name='ix_time_measured_overlap'"
    )->fetchColumn();
    correction_check("{$label}: overlap registry index is exact",
        $overlapIndex === 'tenant_id,user_id,approval_status,started_at,ended_at,time_entry_id');
    correction_check("{$label}: all twelve permanent guards are installed",
        correction_trigger_count($pdo) === 12);
    correction_check("{$label}: no staging trigger remains",
        (int)$pdo->query("SELECT COUNT(*) FROM information_schema.triggers
          WHERE trigger_schema=DATABASE() AND trigger_name LIKE 'trg_time_overlap_stage_%'")->fetchColumn() === 0);
}

require_once __DIR__ . '/../lib/time_entries.php';

try {
    $server = new PDO($serverDsn, $operatorUser, $operatorPass, $pdoOptions);
    $server->exec("CREATE DATABASE {$quotedDatabase} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $databaseCreated = true;
    $pdo = new PDO($serverDsn . ';dbname=' . $testDatabase, $operatorUser, $operatorPass, $pdoOptions);
    $pdo->exec("SET time_zone = '+00:00'");
    correction_check('fixture selected the exact random database',
        (string)$pdo->query('SELECT DATABASE()')->fetchColumn() === $testDatabase);

    $schemaPath = __DIR__ . '/../db/schema.sql';
    $migration011 = __DIR__ . '/../db/migrations/011_time_entry_approvals.sql';
    $migration016 = __DIR__ . '/../db/migrations/016_time_corrections_overlap.sql';

    // Fresh-install contract and service behavior.
    correction_execute_sql_file($pdo, $schemaPath);
    correction_seed($pdo);
    correction_verify_structure($pdo, 'fresh schema');

    $baseFacts = [
        'ticket_id' => 1001,
        'entry_key' => 'timer.mysql.base.0001',
        'source' => 'timer',
        'worked_at' => '2026-08-25T11:00:00Z',
        'started_at' => '2026-08-25T10:00:00Z',
        'ended_at' => '2026-08-25T11:00:00Z',
        'minutes' => 60,
        'note' => 'Measured base',
        'billable' => true,
    ];
    $base = time_entry_create($pdo, 1, 103, $baseFacts);
    correction_check('measured insert creates one pending registry row and UTC-day guard',
        (int)$pdo->query("SELECT COUNT(*) FROM time_entry_measured_intervals
          WHERE tenant_id=1 AND time_entry_id={$base['id']} AND approval_status='pending'")->fetchColumn() === 1
        && (int)$pdo->query("SELECT COUNT(*) FROM time_entry_interval_guards
          WHERE tenant_id=1 AND user_id=103 AND guard_date='2026-08-25'")->fetchColumn() === 1);
    correction_expect(
        'same-technician measured overlap is rejected as a 409 service conflict',
        fn() => time_entry_create($pdo, 1, 103, array_replace($baseFacts, [
            'entry_key' => 'timer.mysql.overlap.0001',
            'worked_at' => '2026-08-25T10:45:00Z',
            'started_at' => '2026-08-25T10:30:00Z',
            'ended_at' => '2026-08-25T10:45:00Z',
            'minutes' => 15,
        ])),
        '',
        'overlaps another pending or approved',
    );
    $adjacent = time_entry_create($pdo, 1, 103, array_replace($baseFacts, [
        'entry_key' => 'timer.mysql.adjacent.0001',
        'worked_at' => '2026-08-25T12:00:00Z',
        'started_at' => '2026-08-25T11:00:00Z',
        'ended_at' => '2026-08-25T12:00:00Z',
        'note' => 'Adjacent work',
    ]));
    correction_check('half-open adjacency is allowed', $adjacent['id'] > $base['id']);
    $otherTech = time_entry_create($pdo, 1, 105, array_replace($baseFacts, [
        'entry_key' => 'timer.mysql.other-tech.0001',
        'note' => 'Other technician same clock',
    ]));
    $otherTenant = time_entry_create($pdo, 2, 201, array_replace($baseFacts, [
        'ticket_id' => 2001,
        'entry_key' => 'timer.mysql.other-tenant.0001',
        'note' => 'Other tenant same clock',
    ]));
    correction_check('overlap scope is isolated by technician and tenant',
        $otherTech['tenant_id'] === 1 && $otherTenant['tenant_id'] === 2);

    $rejected = time_entry_review($pdo, 1, 101, 'owner', $base['id'], 'rejected', 'Fix the note');
    correction_check('rejection updates the measured registry atomically',
        (string)$pdo->query("SELECT approval_status FROM time_entry_measured_intervals
          WHERE tenant_id=1 AND time_entry_id={$base['id']}")->fetchColumn() === 'rejected');
    $originalSnapshot = $pdo->query("SELECT ticket_id,client_id,user_id,entry_key,source,
      worked_at,started_at,ended_at,minutes,note,billable,approval_status,
      reviewed_by_user_id,reviewed_at,review_note,created_at
      FROM time_entries WHERE id={$base['id']}")->fetch();
    $replacementInput = [
        'entry_key' => 'correction.mysql.base.0001',
        'minutes' => 60,
        'note' => 'Corrected measured note',
        'billable' => true,
    ];
    $replacement = time_entry_correct($pdo, 1, 103, $rejected['id'], $replacementInput);
    correction_check('rejected interval can be replaced by one new pending row',
        $replacement['approval_status'] === 'pending'
        && $replacement['corrects_time_entry_id'] === $rejected['id']
        && $replacement['started_at'] === $rejected['started_at']
        && $replacement['ended_at'] === $rejected['ended_at']);
    correction_check('replacement leaves original facts and review history byte-identical',
        $originalSnapshot === $pdo->query("SELECT ticket_id,client_id,user_id,entry_key,source,
          worked_at,started_at,ended_at,minutes,note,billable,approval_status,
          reviewed_by_user_id,reviewed_at,review_note,created_at
          FROM time_entries WHERE id={$base['id']}")->fetch()
        && (int)$pdo->query("SELECT COUNT(*) FROM time_entry_events
          WHERE tenant_id=1 AND time_entry_id={$base['id']}")->fetchColumn() === 2);
    $replacementReplay = time_entry_correct($pdo, 1, 103, $rejected['id'], $replacementInput);
    correction_check('lost-response correction retry returns the same row',
        $replacementReplay['id'] === $replacement['id'] && $replacementReplay['replayed'] === true);
    correction_expect(
        'database refuses a sibling correction before it can become a second row',
        fn() => $pdo->exec("INSERT INTO time_entries
          (entry_key,ticket_id,user_id,minutes,note,billable,source,worked_at,
           started_at,ended_at,corrects_time_entry_id)
          VALUES ('correction.mysql.sibling',1001,103,60,'Sibling',1,'timer',
           '2026-08-25 11:00:00','2026-08-25 10:00:00','2026-08-25 11:00:00',{$base['id']})"),
    );
    correction_expect(
        'database refuses a cross-technician correction',
        fn() => $pdo->exec("INSERT INTO time_entries
          (entry_key,ticket_id,user_id,minutes,note,billable,source,worked_at,
           started_at,ended_at,corrects_time_entry_id)
          VALUES ('correction.mysql.wrong-tech',1001,105,60,'Wrong',1,'timer',
           '2026-08-25 11:00:00','2026-08-25 10:00:00','2026-08-25 11:00:00',{$base['id']})"),
        '45000',
        'technician must match',
    );
    correction_expect(
        'database refuses a correction moved to another ticket and client',
        fn() => $pdo->exec("INSERT INTO time_entries
          (entry_key,ticket_id,user_id,minutes,note,billable,source,worked_at,
           started_at,ended_at,corrects_time_entry_id)
          VALUES ('correction.mysql.wrong-ticket',1002,103,60,'Wrong',1,'timer',
           '2026-08-25 11:00:00','2026-08-25 10:00:00','2026-08-25 11:00:00',{$base['id']})"),
        '45000',
        'ticket and client must match',
    );
    $approvedReplacement = time_entry_review(
        $pdo, 1, 101, 'owner', $replacement['id'], 'approved', 'Correction checked'
    );
    correction_check('an owner can approve a technician correction',
        $approvedReplacement['approval_status'] === 'approved');
    $ownerEntry = time_entry_create($pdo, 1, 101, [
        'ticket_id' => 1001,
        'entry_key' => 'suggestion.mysql.owner-self.0001',
        'source' => 'suggestion',
        'worked_at' => '2026-08-25T20:00:00Z',
        'minutes' => 15,
        'note' => 'Owner handled the follow-up',
        'billable' => false,
    ]);
    $ownerApproved = time_entry_review(
        $pdo, 1, 101, 'owner', $ownerEntry['id'], 'approved', 'Owner verified'
    );
    correction_check('one-person MSP owner can approve their own time',
        $ownerApproved['user_id'] === 101
        && $ownerApproved['reviewed_by_user_id'] === 101
        && $ownerApproved['approval_status'] === 'approved');
    correction_expect(
        'approved correction blocks overlapping measured time',
        fn() => time_entry_create($pdo, 1, 103, array_replace($baseFacts, [
            'entry_key' => 'timer.mysql.after-correction.0001',
            'worked_at' => '2026-08-25T10:40:00Z',
            'started_at' => '2026-08-25T10:20:00Z',
            'ended_at' => '2026-08-25T10:40:00Z',
            'minutes' => 20,
        ])),
        '',
        'overlaps another pending or approved',
    );
    correction_expect('guard rows cannot be deleted',
        fn() => $pdo->exec("DELETE FROM time_entry_interval_guards
          WHERE tenant_id=1 AND user_id=103 AND guard_date='2026-08-25'"),
        '45000', 'cannot be deleted');
    correction_expect('measured registry facts cannot be rewritten',
        fn() => $pdo->exec("UPDATE time_entry_measured_intervals
          SET started_at='2026-08-25 09:00:00'
          WHERE tenant_id=1 AND time_entry_id={$replacement['id']}"),
        '45000', 'facts are immutable');
    correction_expect('correction pointer is an immutable fact',
        fn() => $pdo->exec("UPDATE time_entries SET corrects_time_entry_id=NULL
          WHERE id={$replacement['id']}"),
        '45000', 'facts are immutable');

    // Real two-connection race: the child starts while the parent holds the
    // UTC-day guard. It must still be running until the parent commits, then
    // wake, see the committed registry row, and lose with the overlap signal.
    $raceParent = new PDO(
        $serverDsn . ';dbname=' . $testDatabase,
        $operatorUser,
        $operatorPass,
        $pdoOptions,
    );
    $raceParent->exec("SET time_zone = '+00:00'");
    $raceParent->beginTransaction();
    $raceParent->exec("INSERT INTO time_entries
      (entry_key,ticket_id,user_id,minutes,note,billable,source,worked_at,started_at,ended_at)
      VALUES ('timer.mysql.race.parent',1001,103,60,'Race parent',1,'timer',
       '2026-08-25 19:00:00','2026-08-25 18:00:00','2026-08-25 19:00:00')");
    $raceEnv = getenv();
    if (!is_array($raceEnv)) $raceEnv = [];
    $raceEnv['SAFEHARBOR_TIME_CORRECTION_RACE_WORKER'] = '1';
    $raceEnv['SAFEHARBOR_TIME_CORRECTION_WORKER_DB'] = $testDatabase;
    $pipes = [];
    $raceProcess = proc_open(
        [PHP_BINARY, __FILE__],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        __DIR__,
        $raceEnv,
    );
    if (!is_resource($raceProcess)) throw new RuntimeException('Cannot start race worker');
    fclose($pipes[0]);
    $workerReady = trim((string)fgets($pipes[1]));
    usleep(500000);
    $workerBlocked = (bool)(proc_get_status($raceProcess)['running'] ?? false);
    correction_check('concurrent overlap waits on the database UTC-day guard',
        $workerReady === 'ready' && $workerBlocked);
    $raceParent->commit();
    $workerOut = stream_get_contents($pipes[1]);
    $workerErr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $workerExit = proc_close($raceProcess);
    correction_check('after the winner commits, the racing insert loses instead of double-booking',
        $workerExit === 0
        && str_contains((string)$workerOut, 'overlap-conflict')
        && trim((string)$workerErr) === ''
        && (int)$pdo->query("SELECT COUNT(*) FROM time_entries
          WHERE entry_key IN ('timer.mysql.race.parent','timer.mysql.race.child')")->fetchColumn() === 1);

    // Upgrade path from the deployed migration-011 contract.
    correction_reset_database($pdo);
    correction_create_legacy_schema($pdo);
    correction_seed($pdo);
    correction_execute_sql_file($pdo, $migration011);
    $pdo->exec("INSERT INTO time_entries
      (entry_key,ticket_id,user_id,minutes,note,billable,source,worked_at,started_at,ended_at)
      VALUES ('timer.mysql.upgrade.0001',1001,103,30,'Pre-016 timer',1,'timer',
       '2026-08-24 10:30:00','2026-08-24 10:00:00','2026-08-24 10:30:00')");
    $upgradeId = (int)$pdo->lastInsertId();
    $upgradePostflight = correction_execute_sql_file_with_postflight($pdo, $migration016);
    correction_check('first migration emits a truthful all-green operator postflight',
        (int)($upgradePostflight['correction_column_exact'] ?? 0) === 1
        && (int)($upgradePostflight['correction_index_exact'] ?? 0) === 1
        && (int)($upgradePostflight['correction_fk_exact'] ?? 0) === 1
        && (int)($upgradePostflight['auxiliary_structure_exact'] ?? 0) === 1
        && (int)($upgradePostflight['permanent_trigger_count'] ?? 0) === 12
        && (int)($upgradePostflight['staging_trigger_count'] ?? -1) === 0
        && (int)($upgradePostflight['measured_parent_count'] ?? 0) === 1
        && (int)($upgradePostflight['measured_registry_count'] ?? 0) === 1);
    correction_verify_structure($pdo, 'migration upgrade');
    correction_check('migration mirrors existing measured facts without rewriting them',
        (int)$pdo->query("SELECT COUNT(*) FROM time_entry_measured_intervals
          WHERE tenant_id=1 AND time_entry_id={$upgradeId} AND user_id=103
            AND started_at='2026-08-24 10:00:00' AND ended_at='2026-08-24 10:30:00'
            AND approval_status='pending'")->fetchColumn() === 1);
    $preReplayFacts = $pdo->query("SELECT * FROM time_entries WHERE id={$upgradeId}")->fetch();
    correction_execute_sql_file($pdo, $migration016);
    correction_check('migration replay is idempotent and keeps one registry row',
        $preReplayFacts === $pdo->query("SELECT * FROM time_entries WHERE id={$upgradeId}")->fetch()
        && (int)$pdo->query("SELECT COUNT(*) FROM time_entry_measured_intervals
          WHERE tenant_id=1 AND time_entry_id={$upgradeId}")->fetchColumn() === 1
        && correction_trigger_count($pdo) === 12);

    $pdo->exec('ALTER TABLE time_entry_measured_intervals
      ADD KEY ix_time_measured_fk_hold (tenant_id,user_id)');
    $pdo->exec('ALTER TABLE time_entry_measured_intervals DROP INDEX ix_time_measured_overlap');
    $pdo->exec('ALTER TABLE time_entry_measured_intervals
      ADD KEY ix_time_measured_overlap (tenant_id,user_id)');
    correction_expect(
        'malformed same-named overlap index fails before guards are replaced',
        fn() => correction_execute_sql_file($pdo, $migration016),
        '42S02',
    );
    correction_check('malformed replay leaves all permanent guards installed',
        correction_trigger_count($pdo) === 12);
    $pdo->exec('ALTER TABLE time_entry_measured_intervals DROP INDEX ix_time_measured_overlap');
    $pdo->exec('ALTER TABLE time_entry_measured_intervals ADD KEY ix_time_measured_overlap
      (tenant_id,user_id,approval_status,started_at,ended_at,time_entry_id)');
    $pdo->exec('ALTER TABLE time_entry_measured_intervals DROP INDEX ix_time_measured_fk_hold');

    $underprivilegedUser = 'sh_tc_pf_' . $runId;
    $underprivilegedPass = bin2hex(random_bytes(24));
    $temporaryUsers[] = $underprivilegedUser;
    $server->exec("CREATE USER '{$underprivilegedUser}'@'%' IDENTIFIED BY '{$underprivilegedPass}'");
    $server->exec("GRANT SELECT,INSERT,UPDATE,DELETE,CREATE,DROP,ALTER,INDEX,REFERENCES
      ON `{$testDatabase}`.* TO '{$underprivilegedUser}'@'%'");
    $underprivileged = new PDO(
        $serverDsn . ';dbname=' . $testDatabase,
        $underprivilegedUser,
        $underprivilegedPass,
        $pdoOptions,
    );
    $triggerPrivilegeDenied = false;
    try {
        correction_execute_sql_file($underprivileged, $migration016);
    } catch (PDOException $error) {
        $driverCode = (int)($error->errorInfo[1] ?? 0);
        $triggerPrivilegeDenied = in_array($driverCode, [1142, 1143, 1227, 1419], true);
    }
    correction_check('underprivileged replay fails specifically on trigger authority',
        $triggerPrivilegeDenied);
    correction_check('underprivileged replay cannot weaken the twelve guards',
        correction_trigger_count($pdo) === 12);
    $underprivileged = null;

    // Stop a real first upgrade immediately after all seven canonical guards
    // have been dropped. Five separate swap guards must fail closed until a
    // complete replay restores the permanent definitions.
    correction_reset_database($pdo);
    correction_create_legacy_schema($pdo);
    correction_seed($pdo);
    correction_execute_sql_file($pdo, $migration011);
    $pdo->exec("INSERT INTO time_entries
      (entry_key,ticket_id,user_id,minutes,note,billable,source,worked_at)
      VALUES ('suggestion.mysql.swap.0001',1001,103,15,'Swap proof',0,'suggestion',
       '2026-08-24 11:00:00')");
    $swapEntryId = (int)$pdo->lastInsertId();
    correction_interrupt_migration_at_guard_swap($pdo, $migration016);
    $swapGuards = (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.triggers
          WHERE trigger_schema=DATABASE() AND trigger_name IN (
            'trg_time_016_insert_swap_guard','trg_time_016_update_swap_guard',
            'trg_time_016_delete_swap_guard','trg_time_016_event_update_swap_guard',
            'trg_time_016_event_delete_swap_guard')
          AND action_statement LIKE '%migration 016 trigger swap%'"
    )->fetchColumn();
    correction_check('interrupted trigger replacement leaves all five fail-closed swap guards',
        $swapGuards === 5 && correction_trigger_count($pdo) === 5);
    correction_expect('interrupted swap blocks time inserts',
        fn() => $pdo->exec("INSERT INTO time_entries
          (entry_key,ticket_id,user_id,minutes,note,billable,source,worked_at)
          VALUES ('suggestion.mysql.swap.blocked',1001,103,15,'Blocked',0,'suggestion',
           '2026-08-24 11:30:00')"),
        '45000', 'migration 016 trigger swap');
    correction_expect('interrupted swap blocks time fact updates',
        fn() => $pdo->exec("UPDATE time_entries SET note='Changed' WHERE id={$swapEntryId}"),
        '45000', 'migration 016 trigger swap');
    correction_expect('interrupted swap blocks time deletion',
        fn() => $pdo->exec("DELETE FROM time_entries WHERE id={$swapEntryId}"),
        '45000', 'migration 016 trigger swap');
    correction_expect('interrupted swap blocks audit rewrites',
        fn() => $pdo->exec("UPDATE time_entry_events SET reason='Changed'
          WHERE time_entry_id={$swapEntryId}"),
        '45000', 'migration 016 trigger swap');
    correction_expect('interrupted swap blocks audit deletion',
        fn() => $pdo->exec("DELETE FROM time_entry_events WHERE time_entry_id={$swapEntryId}"),
        '45000', 'migration 016 trigger swap');
    $recoveryPostflight = correction_execute_sql_file_with_postflight($pdo, $migration016);
    correction_check('exact replay recovers an interrupted guard swap without changing history',
        (int)($recoveryPostflight['permanent_trigger_count'] ?? 0) === 12
        && (int)($recoveryPostflight['staging_trigger_count'] ?? -1) === 0
        && correction_trigger_count($pdo) === 12
        && (string)$pdo->query("SELECT note FROM time_entries WHERE id={$swapEntryId}")->fetchColumn()
            === 'Swap proof'
        && (int)$pdo->query("SELECT COUNT(*) FROM time_entry_events
          WHERE time_entry_id={$swapEntryId} AND event_kind='logged'")->fetchColumn() === 1);

    // Replaying 016 over an already-migrated database also replaces five
    // auxiliary-table guards. Stop immediately after those DROPs and prove
    // direct registry mutations and parent writes still fail closed.
    correction_reset_database($pdo);
    correction_execute_sql_file($pdo, $schemaPath);
    correction_seed($pdo);
    $auxBase = time_entry_create($pdo, 1, 103, [
        'ticket_id' => 1001,
        'entry_key' => 'timer.mysql.aux-swap.0001',
        'source' => 'timer',
        'worked_at' => '2026-08-25T11:00:00Z',
        'started_at' => '2026-08-25T10:00:00Z',
        'ended_at' => '2026-08-25T11:00:00Z',
        'minutes' => 60,
        'note' => 'Auxiliary swap proof',
        'billable' => true,
    ]);
    correction_interrupt_migration_at_aux_guard_swap($pdo, $migration016);
    $auxSwapGuards = (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.triggers
          WHERE trigger_schema=DATABASE() AND trigger_name IN (
            'trg_time_016_guard_update_aux_swap','trg_time_016_guard_delete_aux_swap',
            'trg_time_016_measured_insert_aux_swap','trg_time_016_measured_update_aux_swap',
            'trg_time_016_measured_delete_aux_swap')
          AND action_statement LIKE '%migration 016 auxiliary trigger swap%'"
    )->fetchColumn();
    correction_check('interrupted auxiliary replacement leaves all five fail-closed guards',
        $auxSwapGuards === 5 && correction_trigger_count($pdo) === 7);
    correction_expect('interrupted auxiliary swap blocks guard rewrites',
        fn() => $pdo->exec("UPDATE time_entry_interval_guards SET guard_date='2026-08-24'
          WHERE tenant_id=1 AND user_id=103 AND guard_date='2026-08-25'"),
        '45000', 'migration 016 auxiliary trigger swap');
    correction_expect('interrupted auxiliary swap blocks guard deletion',
        fn() => $pdo->exec("DELETE FROM time_entry_interval_guards
          WHERE tenant_id=1 AND user_id=103 AND guard_date='2026-08-25'"),
        '45000', 'migration 016 auxiliary trigger swap');
    correction_expect('interrupted auxiliary swap blocks measured inserts',
        fn() => $pdo->exec("INSERT INTO time_entry_measured_intervals
          (tenant_id,time_entry_id,user_id,started_at,ended_at,approval_status)
          VALUES (1,{$auxBase['id']},103,'2026-08-25 10:00:00','2026-08-25 11:00:00','pending')"),
        '45000', 'migration 016 auxiliary trigger swap');
    correction_expect('interrupted auxiliary swap blocks measured rewrites',
        fn() => $pdo->exec("UPDATE time_entry_measured_intervals SET approval_status='rejected'
          WHERE tenant_id=1 AND time_entry_id={$auxBase['id']}"),
        '45000', 'migration 016 auxiliary trigger swap');
    correction_expect('interrupted auxiliary swap blocks measured deletion',
        fn() => $pdo->exec("DELETE FROM time_entry_measured_intervals
          WHERE tenant_id=1 AND time_entry_id={$auxBase['id']}"),
        '45000', 'migration 016 auxiliary trigger swap');
    correction_expect('parent measured insert rolls back while auxiliary swap is incomplete',
        fn() => time_entry_create($pdo, 1, 103, [
            'ticket_id' => 1001,
            'entry_key' => 'timer.mysql.aux-swap.blocked',
            'source' => 'timer',
            'worked_at' => '2026-08-25T12:00:00Z',
            'started_at' => '2026-08-25T11:00:00Z',
            'ended_at' => '2026-08-25T12:00:00Z',
            'minutes' => 60,
            'note' => 'Must roll back',
            'billable' => true,
        ]),
        '45000', 'migration 016 auxiliary trigger swap');
    $auxRecoveryPostflight = correction_execute_sql_file_with_postflight($pdo, $migration016);
    correction_check('exact replay recovers an interrupted auxiliary swap without changing facts',
        (int)($auxRecoveryPostflight['permanent_trigger_count'] ?? 0) === 12
        && (int)($auxRecoveryPostflight['staging_trigger_count'] ?? -1) === 0
        && correction_trigger_count($pdo) === 12
        && (int)$pdo->query("SELECT COUNT(*) FROM time_entries")->fetchColumn() === 1
        && (int)$pdo->query("SELECT COUNT(*) FROM time_entry_measured_intervals
          WHERE tenant_id=1 AND time_entry_id={$auxBase['id']}")->fetchColumn() === 1);
} catch (Throwable $error) {
    $fatalError = $error;
} finally {
    $pdo = null;
    if ($server instanceof PDO) {
        foreach ($temporaryUsers as $temporaryUser) {
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
    $safeFailure = preg_replace('/[^\x20-\x7E]/', ' ', $fatalError->getMessage()) ?? 'unknown error';
    fwrite(
        STDERR,
        'Time-correction MySQL test failed: ' . $fatalError::class
            . '/' . $fatalError->getCode() . ' ' . $safeFailure . PHP_EOL,
    );
    exit(1);
}
if ($cleanupError) {
    fwrite(STDERR, "Time-correction MySQL cleanup failed.\n");
    exit(1);
}

echo "---\n{$checks} checks, {$failures} failures\n";
exit($failures > 0 ? 1 : 0);
