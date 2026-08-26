<?php
/** MySQL integration coverage for inbound-email service-goal snapshots. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../lib/intake.php';

$CONFIG['db']['name'] = 'safeharbor_test';

$failures = 0;
$checks = 0;

function check(string $name, bool $condition): void
{
    global $failures, $checks;
    $checks++;
    if ($condition) {
        echo "ok {$checks} - {$name}\n";
        return;
    }
    $failures++;
    echo "FAIL {$checks} - {$name}\n";
}

function execute_sql_file(string $path, bool $skipPrivilegedTriggers = false): void
{
    $sql = (string) file_get_contents($path);
    foreach (explode(";\n", $sql) as $statement) {
        if ($skipPrivilegedTriggers && preg_match('/\b(?:DROP|CREATE)\s+TRIGGER\b/i', $statement)) {
            continue;
        }
        if (trim($statement) !== '') {
            if (preg_match('/^\s*SELECT\b/i', $statement)) {
                $result = db()->query($statement);
                $result->fetchAll();
                $result->closeCursor();
            } else {
                db()->exec($statement);
            }
        }
    }
}

function fresh_schema(): void
{
    $pdo = db();
    $tables = $pdo->query(
        'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()'
    )->fetchAll(PDO::FETCH_COLUMN);
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables as $table) {
        $pdo->exec('DROP TABLE IF EXISTS ' . $table);
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

    foreach (['schema.sql', 'migrations/001_mail_queue.sql'] as $file) {
        execute_sql_file(__DIR__ . '/../db/' . $file, true);
    }

    $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Test MSP', 'test')");
    $pdo->exec(
        "INSERT INTO clients (id, tenant_id, name, domain, sla_tier)
         VALUES (1, 1, 'Premium Client', 'premium.example', 'premium')"
    );
    $pdo->exec(
        "INSERT INTO contacts (id, client_id, name, email)
         VALUES (1, 1, 'Ada', 'ada@premium.example')"
    );
}

function ticket(int $id): array
{
    $query = db()->prepare(
        'SELECT ticket.*, policy.policy_key, policy.version_no,
                target.priority AS target_priority,
                target.first_response_minutes
           FROM tickets ticket
           JOIN service_goal_policy_targets target
             ON target.tenant_id = ticket.tenant_id
            AND target.id = ticket.service_goal_target_id
           JOIN service_goal_policy_versions policy
             ON policy.tenant_id = ticket.tenant_id
            AND policy.id = target.policy_version_id
          WHERE ticket.id = ?'
    );
    $query->execute([$id]);
    return $query->fetch() ?: [];
}

function separate_connection(): PDO
{
    global $CONFIG;
    $db = $CONFIG['db'];
    $port = ! empty($db['port']) ? ';port=' . $db['port'] : '';
    $pdo = new PDO(
        'mysql:host=' . $db['host'] . $port . ';dbname=safeharbor_test;charset=' . $db['charset'],
        $db['user'],
        $db['pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ],
    );
    $pdo->exec("SET time_zone = '+00:00'");
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');

    return $pdo;
}

fresh_schema();

// Recreate the real upgrade shape from Phase 1: an existing ticket with no
// version pointer, followed by none of migration 010's tables or ticket DDL.
db()->exec(
    "INSERT INTO tickets
        (id, tenant_id, client_id, contact_id, subject, status, priority, channel,
         sla_due_at, created_at, updated_at)
     VALUES
        (50, 1, 1, 1, 'Historical ticket', 'resolved', 'normal', 'email',
         '2026-08-25 20:00:00', '2026-08-25 12:00:00', '2026-08-25 13:00:00')"
);
db()->exec('ALTER TABLE tickets DROP FOREIGN KEY fk_tickets_service_goal_target');
db()->exec('ALTER TABLE tickets DROP INDEX ix_tickets_service_goal_target');
db()->exec('ALTER TABLE tickets DROP COLUMN service_goal_target_id');
db()->exec('DROP TABLE service_goal_policy_targets');
db()->exec('DROP TABLE service_goal_policy_versions');

// The app test identity cannot create triggers on a binary-logged server;
// those four operator-owned statements are exercised separately with root.
// Everything else runs through the same migration file here, then replays.
execute_sql_file(__DIR__ . '/../db/migrations/010_service_goal_policies.sql', true);
check('migration upgrades the Phase 1 schema with baseline policy rows',
    (int) db()->query('SELECT COUNT(*) FROM service_goal_policy_versions')->fetchColumn() === 2
    && (int) db()->query('SELECT COUNT(*) FROM service_goal_policy_targets')->fetchColumn() === 8);
check('migration leaves historical tickets explicitly unversioned',
    db()->query('SELECT service_goal_target_id FROM tickets WHERE id = 50')->fetchColumn() === null);

// A default target ID would silently invent a snapshot for callers that omit
// the new column. Treat that malformed partial state as incompatible.
db()->exec('ALTER TABLE tickets ALTER COLUMN service_goal_target_id SET DEFAULT 1');
$wrongColumnRejected = false;
try {
    execute_sql_file(__DIR__ . '/../db/migrations/010_service_goal_policies.sql', true);
} catch (PDOException $error) {
    $wrongColumnRejected = (int) ($error->errorInfo[1] ?? 0) === 1060;
}
check('migration rejects a ticket-pointer column with a non-NULL default', $wrongColumnRejected);
db()->exec('ALTER TABLE tickets ALTER COLUMN service_goal_target_id DROP DEFAULT');

// A same-named UNIQUE index is not a valid replay state: it would allow only
// one ticket per tenant/target. The guard must reject it rather than treating
// its column list alone as exact.
db()->exec('ALTER TABLE tickets DROP FOREIGN KEY fk_tickets_service_goal_target');
db()->exec('ALTER TABLE tickets DROP INDEX ix_tickets_service_goal_target');
db()->exec(
    'ALTER TABLE tickets ADD UNIQUE KEY ix_tickets_service_goal_target
        (tenant_id, service_goal_target_id)'
);
$wrongIndexRejected = false;
try {
    execute_sql_file(__DIR__ . '/../db/migrations/010_service_goal_policies.sql', true);
} catch (PDOException $error) {
    $wrongIndexRejected = (int) ($error->errorInfo[1] ?? 0) === 1061;
}
check('migration rejects a same-named unique ticket-pointer index', $wrongIndexRejected);
db()->exec('ALTER TABLE tickets DROP INDEX ix_tickets_service_goal_target');
execute_sql_file(__DIR__ . '/../db/migrations/010_service_goal_policies.sql', true);

check('migration postflight sees the exact ticket pointer column', (int) db()->query(
    "SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = DATABASE()
        AND table_name = 'tickets'
        AND column_name = 'service_goal_target_id'
        AND column_type = 'int unsigned'
        AND is_nullable = 'YES'
        AND column_default IS NULL
        AND extra = ''"
)->fetchColumn() === 1);
check('migration postflight sees the exact non-unique ticket pointer index', (string) db()->query(
    "SELECT CONCAT(
              GROUP_CONCAT(column_name ORDER BY seq_in_index),
              '|', MIN(non_unique),
              '|', MIN(index_type),
              '|', SUM(sub_part IS NULL)
            )
       FROM information_schema.statistics
      WHERE table_schema = DATABASE()
        AND table_name = 'tickets'
        AND index_name = 'ix_tickets_service_goal_target'"
)->fetchColumn() === 'tenant_id,service_goal_target_id|1|BTREE|2');
check('migration postflight sees the exact tenant-scoped foreign key', (string) db()->query(
    "SELECT GROUP_CONCAT(
              CONCAT(column_name, '=', referenced_column_name)
              ORDER BY ordinal_position
            )
       FROM information_schema.key_column_usage
      WHERE constraint_schema = DATABASE()
        AND table_name = 'tickets'
        AND constraint_name = 'fk_tickets_service_goal_target'
        AND referenced_table_name = 'service_goal_policy_targets'"
)->fetchColumn() === 'tenant_id=tenant_id,service_goal_target_id=id');
execute_sql_file(__DIR__ . '/../db/migrations/010_service_goal_policies.sql', true);
check('migration replay does not duplicate baseline policy rows',
    (int) db()->query('SELECT COUNT(*) FROM service_goal_policy_versions')->fetchColumn() === 2
    && (int) db()->query('SELECT COUNT(*) FROM service_goal_policy_targets')->fetchColumn() === 8);

// Reproduce the lazy-provisioning race from a transaction whose consistent
// snapshot predates the winning connection's inserts. Recovery must use a
// current read or MySQL REPEATABLE READ cannot see the winner.
db()->exec("INSERT INTO tenants (id, name, slug) VALUES (2, 'Race Tenant', 'race')");
db()->exec(
    "INSERT INTO clients (id, tenant_id, name, domain, sla_tier)
     VALUES (2, 2, 'Race Client', 'race.example', 'premium')"
);
$lagging = separate_connection();
$isolation = strtoupper(str_replace('-', ' ', (string) $lagging->query(
    'SELECT @@transaction_isolation'
)->fetchColumn()));
check('race connection uses repeatable-read isolation', $isolation === 'REPEATABLE READ');
$raceResolved = false;
try {
    $lagging->beginTransaction();
    $lagging->query(
        "SELECT id FROM service_goal_policy_versions
          WHERE tenant_id = 2 AND policy_key = 'premium' AND version_no = 1"
    )->fetchColumn();
    service_goal_ensure_default_policies(db(), 2);
    $raceGoal = service_goal_snapshot_for_new_ticket(
        $lagging,
        2,
        2,
        'normal',
        '2026-08-25 12:00:00',
    );
    $raceResolved = ($raceGoal['policy_key'] ?? '') === 'premium'
        && (int) ($raceGoal['first_response_minutes'] ?? 0) === 120;
    $lagging->commit();
} catch (Throwable) {
    if ($lagging->inTransaction()) {
        $lagging->rollBack();
    }
}
check('duplicate-key recovery sees the concurrent policy winner', $raceResolved);
$result = intake_message(
    'ada@premium.example',
    'Ada',
    'Printer stopped',
    'The front printer stopped after an update.',
);
check('new email creates a ticket', str_starts_with($result, 'created:#'));
$ticketId = (int) substr($result, strlen('created:#'));
$first = ticket($ticketId);
check('email captures premium v1 normal target',
    ($first['policy_key'] ?? '') === 'premium'
    && (int) ($first['version_no'] ?? 0) === 1
    && ($first['target_priority'] ?? '') === 'normal'
    && (int) ($first['first_response_minutes'] ?? 0) === 120);
check('email stores one exact two-hour deadline',
    strtotime((string) $first['sla_due_at'] . ' UTC')
        - strtotime((string) $first['created_at'] . ' UTC') === 7200);
$targetId = (int) ($first['service_goal_target_id'] ?? 0);
$dueAt = (string) ($first['sla_due_at'] ?? '');

db()->exec("UPDATE clients SET sla_tier = 'standard' WHERE id = 1");
db()->prepare("UPDATE tickets SET status = 'waiting', priority = 'urgent' WHERE id = ?")
    ->execute([$ticketId]);
$reply = intake_message(
    'ada@premium.example',
    'Ada',
    'Re: [#' . $ticketId . '] Printer stopped',
    'It is still happening.',
);
check('threaded reply appends to the existing ticket', $reply === 'appended:#' . $ticketId);
$reopened = ticket($ticketId);
check('reply reopens without rebasing the captured goal',
    ($reopened['status'] ?? '') === 'open'
    && (int) ($reopened['service_goal_target_id'] ?? 0) === $targetId
    && ($reopened['sla_due_at'] ?? '') === $dueAt);

$secondResult = intake_message(
    'ada@premium.example',
    'Ada',
    'Scanner stopped',
    'A separate issue after the plan change.',
);
$secondId = (int) substr($secondResult, strlen('created:#'));
$second = ticket($secondId);
check('a later ticket uses the client current policy key',
    ($second['policy_key'] ?? '') === 'standard'
    && (int) ($second['first_response_minutes'] ?? 0) === 480);
check('the later standard ticket gets an exact eight-hour deadline',
    strtotime((string) $second['sla_due_at'] . ' UTC')
        - strtotime((string) $second['created_at'] . ' UTC') === 28800);
check('both acknowledgements were queued', (int) db()->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn() === 2);

echo "---\n{$checks} checks, {$failures} failures\n";
exit($failures > 0 ? 1 : 0);
