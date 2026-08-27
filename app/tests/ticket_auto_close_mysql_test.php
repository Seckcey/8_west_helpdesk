<?php
/** Operator-grade MySQL 8 proof for migration 018 and recovery ownership. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);

require_once __DIR__ . '/../lib/ticket_lifecycle.php';

if (getenv('SAFEHARBOR_AUTO_CLOSE_TEST_DISPOSABLE_SERVER') !== '1') {
    fwrite(STDERR, "Refusing MySQL test without explicit disposable-server acknowledgement.\n");
    exit(2);
}

$databaseBase = getenv('SAFEHARBOR_AUTO_CLOSE_TEST_DB');
if (!is_string($databaseBase)
    || preg_match('/\Asafeharbor_auto_close_test(?:_[a-z0-9_]+)?\z/D', $databaseBase) !== 1
    || strlen($databaseBase) > 44
) {
    fwrite(STDERR, "Refusing destructive test database base.\n");
    exit(2);
}

$host = getenv('SAFEHARBOR_AUTO_CLOSE_TEST_HOST') ?: '127.0.0.1';
$port = getenv('SAFEHARBOR_AUTO_CLOSE_TEST_PORT') ?: '3306';
if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true)
    || preg_match('/\A[0-9]{1,5}\z/D', (string)$port) !== 1
    || (int)$port < 1 || (int)$port > 65535
) {
    fwrite(STDERR, "Refusing a non-loopback or invalid disposable MySQL endpoint.\n");
    exit(2);
}

$user = getenv('SAFEHARBOR_AUTO_CLOSE_TEST_USER') ?: 'root';
$pass = getenv('SAFEHARBOR_AUTO_CLOSE_TEST_PASS') ?: '';
$database = $databaseBase . '_' . bin2hex(random_bytes(6));
$quotedDatabase = '`' . str_replace('`', '``', $database) . '`';
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

$checks = 0;
$failures = 0;

function auto_close_check(bool $condition, string $message): void
{
    global $checks, $failures;
    $checks++;
    if ($condition) {
        echo "ok {$checks} - {$message}\n";
        return;
    }
    $failures++;
    echo "FAIL {$checks} - {$message}\n";
}

function auto_close_signal(callable $operation, string $fragment, string $message): void
{
    try {
        $operation();
        auto_close_check(false, $message);
    } catch (PDOException $error) {
        $state = is_array($error->errorInfo) ? (string)($error->errorInfo[0] ?? '') : '';
        auto_close_check(
            $state === '45000'
                && str_contains(strtolower($error->getMessage()), strtolower($fragment)),
            $message,
        );
    } catch (Throwable) {
        auto_close_check(false, $message);
    }
}

/** @return list<string> */
function auto_close_statements(string $sql): array
{
    $delimiter = ';';
    $buffer = '';
    $statements = [];
    foreach (preg_split('/\R/', $sql) ?: [] as $line) {
        if (preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $match) === 1) {
            $pending = array_filter(
                preg_split('/\R/', $buffer) ?: [],
                static fn(string $item): bool => trim($item) !== ''
                    && !str_starts_with(ltrim($item), '--'),
            );
            if ($pending !== []) throw new RuntimeException('DELIMITER changed with pending SQL.');
            $buffer = '';
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

function auto_close_execute_file(PDO $pdo, string $path): void
{
    $sql = file_get_contents($path);
    if (!is_string($sql)) throw new RuntimeException("Cannot read {$path}");
    foreach (auto_close_statements($sql) as $statement) {
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

function auto_close_insert_ticket(PDO $pdo, int $id, string $key, int $eligible = 1): void
{
    $insert = $pdo->prepare(
        "INSERT INTO tickets
            (id,tenant_id,client_id,contact_id,subject,status,priority,assignee_id,
             channel,external_key,auto_close_eligible,sla_due_at,resurface_at,
             merged_into_id,created_at,updated_at,resolved_at)
         VALUES (?,1,1,NULL,?,'open','urgent',NULL,'alert',?,?,
                 '2026-08-27 20:00:00',NULL,NULL,
                 '2026-08-27 12:00:00','2026-08-27 12:00:00',NULL)"
    );
    $insert->execute([$id, 'Machine alert ' . $id, $key, $eligible]);
    $line = $pdo->prepare(
        "INSERT INTO messages(ticket_id,author_name,kind,body)
         VALUES (?,'Milepost','system','Alert opened at source at 2026-08-27 12:00:00 UTC.')"
    );
    $line->execute([$id]);
}

$server = null;
$pdo = null;
$created = false;
$runError = null;
$cleanupFailures = [];

try {
    $server = new PDO(
        "mysql:host={$host};port={$port};charset=utf8mb4",
        $user,
        $pass,
        $options,
    );
    $server->exec("CREATE DATABASE {$quotedDatabase} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $created = true;
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $user,
        $pass,
        $options,
    );
    $pdo->exec("SET time_zone = '+00:00'");
    auto_close_check(
        (string)$pdo->query('SELECT DATABASE()')->fetchColumn() === $database,
        'active connection is pinned to the random disposable database',
    );

    // Exact pre-018 surface used by the migration and its guards. No broader
    // application schema is needed for this destructive fixture.
    $pdo->exec(
        "CREATE TABLE tickets (
           id INT UNSIGNED NOT NULL AUTO_INCREMENT,
           tenant_id INT UNSIGNED NOT NULL,
           client_id INT UNSIGNED NOT NULL,
           contact_id INT UNSIGNED NULL,
           subject VARCHAR(190) NOT NULL,
           status ENUM('open','in_progress','waiting','resolved') NOT NULL DEFAULT 'open',
           priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
           assignee_id INT UNSIGNED NULL,
           channel ENUM('email','portal','alert','phone') NOT NULL DEFAULT 'email',
           external_key VARCHAR(64) NULL,
           sla_due_at DATETIME NOT NULL,
           resurface_at DATETIME NULL,
           merged_into_id INT UNSIGNED NULL,
           created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
           updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
           resolved_at DATETIME NULL,
           PRIMARY KEY (id),
           UNIQUE KEY uq_tickets_tenant_extkey (tenant_id,external_key)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "CREATE TABLE messages (
           id INT UNSIGNED NOT NULL AUTO_INCREMENT,
           ticket_id INT UNSIGNED NOT NULL,
           author_name VARCHAR(128) NOT NULL,
           kind ENUM('client','tech','note','system') NOT NULL DEFAULT 'tech',
           body TEXT NOT NULL,
           created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
           PRIMARY KEY (id), KEY ix_messages_ticket(ticket_id)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "CREATE TABLE time_entries (
           id INT UNSIGNED NOT NULL AUTO_INCREMENT,
           tenant_id INT UNSIGNED NOT NULL,
           ticket_id INT UNSIGNED NOT NULL,
           PRIMARY KEY (id)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $ticket = $pdo->prepare(
        "INSERT INTO tickets
          (id,tenant_id,client_id,contact_id,subject,status,priority,assignee_id,
           channel,external_key,sla_due_at,resurface_at,merged_into_id,
           created_at,updated_at,resolved_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
    );
    $base = [1, 1, null, 'Alert', 'open', 'urgent', null, 'alert', 'alert:1',
        '2026-08-27 20:00:00', null, null,
        '2026-08-27 12:00:00', '2026-08-27 12:00:00', null];
    foreach ([1, 2, 3, 4, 5, 8, 10, 11, 12, 13] as $id) {
        $row = $base;
        array_unshift($row, $id);
        $row[4] = 'Alert ' . $id;
        $row[9] = 'alert:' . $id;
        if ($id === 4) $row[7] = 77; // currently assigned
        if ($id === 5) $row[14] = '2026-08-27 12:05:00'; // row changed/refired
        if ($id === 10) $row[11] = '2026-08-28 12:00:00'; // waiting leash evidence
        if ($id === 11) { $row[5] = 'resolved'; $row[15] = '2026-08-27 12:10:00'; }
        if ($id === 12) $row[3] = 55; // human contact
        if ($id === 13) $row[9] = 'alert:99999999999999999999'; // above BIGINT UNSIGNED
        $ticket->execute($row);
    }
    $ticket->execute([
        6,1,1,null,'Email ticket','open','normal',null,'email',null,
        '2026-08-27 20:00:00',null,null,
        '2026-08-27 12:00:00','2026-08-27 12:00:00',null,
    ]);
    $ticket->execute([
        7,1,1,null,'Invalid alert key','open','normal',null,'alert','westy:7',
        '2026-08-27 20:00:00',null,null,
        '2026-08-27 12:00:00','2026-08-27 12:00:00',null,
    ]);
    $ticket->execute([
        9,1,1,null,'Merged source','resolved','normal',null,'email',null,
        '2026-08-27 20:00:00',null,8,
        '2026-08-27 12:00:00','2026-08-27 12:00:00','2026-08-27 12:10:00',
    ]);

    $machineLine = $pdo->prepare(
        "INSERT INTO messages(ticket_id,author_name,kind,body)
         VALUES (?,'Milepost','system','Alert opened at source at 2026-08-27 12:00:00 UTC.')"
    );
    foreach ([1,2,3,4,5,8,10,11,12,13] as $id) $machineLine->execute([$id]);
    $pdo->exec("INSERT INTO messages(ticket_id,author_name,kind,body) VALUES (2,'Tech','note','looked at it')");
    $pdo->exec("INSERT INTO messages(ticket_id,author_name,kind,body) VALUES (5,'Milepost','system','Alert re-fired at source')");
    $pdo->exec('INSERT INTO time_entries(tenant_id,ticket_id) VALUES (1,3)');

    $migration = __DIR__ . '/../db/migrations/018_ticket_auto_close_eligibility.sql';
    auto_close_execute_file($pdo, $migration);

    auto_close_check(
        array_map(
            'intval',
            $pdo->query('SELECT id FROM tickets WHERE auto_close_eligible=1 ORDER BY id')
                ->fetchAll(PDO::FETCH_COLUMN),
        ) === [1],
        'first migration backfills only the database-proven untouched alert',
    );
    auto_close_check(
        (string)$pdo->query('SELECT updated_at FROM tickets WHERE id=1')->fetchColumn()
            === '2026-08-27 12:00:00',
        'backfill preserves the ticket operational timestamp',
    );
    auto_close_check(
        (int)$pdo->query(
            "SELECT COUNT(*) FROM information_schema.triggers
              WHERE trigger_schema=DATABASE()
                AND trigger_name IN (
                  'trg_tickets_auto_close_before_insert',
                  'trg_tickets_auto_close_before_update',
                  'trg_messages_auto_close_after_insert',
                  'trg_messages_auto_close_after_update',
                  'trg_messages_auto_close_after_delete',
                  'trg_time_entries_auto_close_after_insert')"
        )->fetchColumn() === 6,
        'all six permanent database guards are installed',
    );

    auto_close_check(
        ticket_try_machine_auto_close($pdo, 1, 1, '2026-08-27 12:30:00'),
        'real recovery can close the one eligible untouched ticket',
    );
    auto_close_check(
        $pdo->query("SELECT CONCAT(status,':',auto_close_eligible) FROM tickets WHERE id=1")
            ->fetchColumn() === 'resolved:0',
        'automatic closure consumes eligibility permanently',
    );
    auto_close_check(
        (int)$pdo->query(
            "SELECT COUNT(*) FROM messages WHERE ticket_id=1
              AND kind='system' AND author_name='Milepost'
              AND body LIKE '%Ticket auto-closed.'"
        )->fetchColumn() === 1,
        'automatic closure stores exact recovery evidence atomically',
    );

    auto_close_insert_ticket($pdo, 20, 'alert:20');
    $pdo->exec("INSERT INTO messages(ticket_id,author_name,kind,body) VALUES (20,'Tech','note','human note')");
    auto_close_check(
        (int)$pdo->query('SELECT auto_close_eligible FROM tickets WHERE id=20')->fetchColumn() === 0,
        'a technician note permanently clears eligibility',
    );

    auto_close_insert_ticket($pdo, 21, 'alert:21');
    $pdo->exec('INSERT INTO time_entries(tenant_id,ticket_id) VALUES (1,21)');
    auto_close_check(
        (int)$pdo->query('SELECT auto_close_eligible FROM tickets WHERE id=21')->fetchColumn() === 0,
        'logged technician time permanently clears eligibility',
    );

    auto_close_insert_ticket($pdo, 22, 'alert:22');
    $pdo->exec("UPDATE tickets SET priority='high' WHERE id=22");
    auto_close_check(
        (int)$pdo->query('SELECT auto_close_eligible FROM tickets WHERE id=22')->fetchColumn() === 0,
        'a priority mutation permanently clears eligibility',
    );

    auto_close_insert_ticket($pdo, 23, 'alert:23');
    $pdo->exec("UPDATE messages SET body='reviewed provenance' WHERE ticket_id=23");
    auto_close_check(
        (int)$pdo->query('SELECT auto_close_eligible FROM tickets WHERE id=23')->fetchColumn() === 0,
        'editing existing message evidence permanently clears eligibility',
    );

    auto_close_insert_ticket($pdo, 25, 'alert:25');
    $pdo->exec('DELETE FROM messages WHERE ticket_id=25');
    auto_close_check(
        (int)$pdo->query('SELECT auto_close_eligible FROM tickets WHERE id=25')->fetchColumn() === 0,
        'deleting machine evidence permanently clears eligibility',
    );

    auto_close_insert_ticket($pdo, 24, 'alert:24');
    $pdo->exec("UPDATE tickets SET assignee_id=88 WHERE id=24");
    auto_close_check(
        (int)$pdo->query('SELECT auto_close_eligible FROM tickets WHERE id=24')->fetchColumn() === 0,
        'assignment permanently clears eligibility',
    );

    auto_close_signal(
        fn() => $pdo->exec(
            "INSERT INTO tickets
              (tenant_id,client_id,subject,status,priority,channel,external_key,
               auto_close_eligible,sla_due_at,created_at,updated_at)
             VALUES (1,1,'Wrong origin','open','normal','email','alert:30',1,
                     '2026-08-27 20:00:00','2026-08-27 12:00:00','2026-08-27 12:00:00')"
        ),
        'reserved for untouched Milepost alerts',
        'database refuses eligibility on a non-telemetry ticket',
    );
    auto_close_signal(
        fn() => $pdo->exec(
            "INSERT INTO tickets
              (tenant_id,client_id,subject,status,priority,channel,external_key,
               auto_close_eligible,sla_due_at,created_at,updated_at)
             VALUES (1,1,'Out of range','open','normal','alert',
                     'alert:99999999999999999999',1,
                     '2026-08-27 20:00:00','2026-08-27 12:00:00','2026-08-27 12:00:00')"
        ),
        'reserved for untouched Milepost alerts',
        'database refuses an alert id above the Milepost unsigned ceiling',
    );
    auto_close_signal(
        fn() => $pdo->exec('UPDATE tickets SET auto_close_eligible=1 WHERE id=22'),
        'cannot be restored',
        'cleared eligibility cannot be restored',
    );
    auto_close_check(
        ticket_try_machine_auto_close($pdo, 1, 22, '2026-08-27 12:31:00') === false
            && $pdo->query('SELECT status FROM tickets WHERE id=22')->fetchColumn() === 'open',
        'machine recovery cannot close a human-touched Open ticket',
    );
    auto_close_signal(
        fn() => $pdo->exec(
            "INSERT INTO messages(ticket_id,author_name,kind,body)
             VALUES (22,'Milepost','system',
                     'Resolved at source at 2026-08-27 12:31:00 UTC. Ticket auto-closed.')"
        ),
        'ineligible ticket cannot receive',
        'pre-018 rollback path is blocked before it can auto-close an ineligible ticket',
    );
    $pdo->exec("UPDATE tickets SET status='resolved',resolved_at='2026-08-27 12:32:00' WHERE id=22");
    auto_close_check(
        $pdo->query('SELECT status FROM tickets WHERE id=22')->fetchColumn() === 'resolved',
        'human/manual closure remains available',
    );

    // Replaying after human work must never run the historical backfill again.
    auto_close_execute_file($pdo, $migration);
    auto_close_check(
        (int)$pdo->query('SELECT COUNT(*) FROM tickets WHERE auto_close_eligible=1')->fetchColumn() === 0,
        'migration replay never restores a consumed capability',
    );

    $pdo->exec('ALTER TABLE tickets ALTER COLUMN auto_close_eligible SET DEFAULT 1');
    auto_close_signal(
        fn() => auto_close_execute_file($pdo, $migration),
        'unexpected shape',
        'replay refuses a weakened default instead of accepting partial state',
    );
    auto_close_check(
        (int)$pdo->query(
            "SELECT COUNT(*) FROM information_schema.triggers
              WHERE trigger_schema=DATABASE()
                AND trigger_name IN (
                  'trg_tickets_auto_close_before_insert',
                  'trg_tickets_auto_close_before_update',
                  'trg_messages_auto_close_after_insert',
                  'trg_messages_auto_close_after_update',
                  'trg_messages_auto_close_after_delete',
                  'trg_time_entries_auto_close_after_insert')"
        )->fetchColumn() === 6,
        'failed replay leaves all permanent guards installed',
    );
    $pdo->exec('ALTER TABLE tickets ALTER COLUMN auto_close_eligible SET DEFAULT 0');
    auto_close_execute_file($pdo, $migration);
    auto_close_check(true, 'corrected exact-state replay succeeds');
} catch (Throwable $error) {
    $runError = $error;
} finally {
    $pdo = null;
    if ($created && $server instanceof PDO) {
        try {
            $server->exec("DROP DATABASE IF EXISTS {$quotedDatabase}");
            if ((int)$server->query(
                'SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='
                . $server->quote($database)
            )->fetchColumn() !== 0) {
                $cleanupFailures[] = 'database cleanup postflight failed';
            }
        } catch (Throwable) {
            $cleanupFailures[] = 'database cleanup failed';
        }
    }
}

if ($runError instanceof Throwable) {
    $detail = $runError instanceof PDOException && is_array($runError->errorInfo)
        ? ' SQLSTATE ' . (string)($runError->errorInfo[0] ?? 'unknown')
          . ' driver ' . (string)($runError->errorInfo[1] ?? 'unknown')
        : '';
    fwrite(STDERR, 'Auto-close MySQL fixture aborted: ' . get_class($runError)
        . $detail . ' line ' . $runError->getLine() . "\n");
    exit(1);
}

auto_close_check($cleanupFailures === [], 'outer finally removes the disposable database');
if ($failures > 0) {
    fwrite(STDERR, "{$failures} of {$checks} auto-close MySQL checks failed.\n");
    exit(1);
}
echo "Ticket auto-close MySQL: {$checks}/{$checks} passed.\n";
