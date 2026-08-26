<?php
/**
 * MySQL integration coverage for migration 012 and customer-portal isolation.
 *
 * Destructive only inside a database named safeharbor_portal_test*.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);

require_once __DIR__ . '/../lib/portal_data.php';

$database = getenv('SAFEHARBOR_PORTAL_TEST_DB') ?: 'safeharbor_portal_test';
if (preg_match('/\Asafeharbor_portal_test(?:_[a-z0-9_]+)?\z/', $database) !== 1) {
    fwrite(STDERR, "Refusing destructive test database: {$database}\n");
    exit(2);
}
$host = getenv('SAFEHARBOR_PORTAL_TEST_HOST') ?: '127.0.0.1';
$port = getenv('SAFEHARBOR_PORTAL_TEST_PORT') ?: '3306';
$user = getenv('SAFEHARBOR_PORTAL_TEST_USER') ?: 'root';
$pass = getenv('SAFEHARBOR_PORTAL_TEST_PASS') ?: '';

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ],
    );
    $pdo->exec("SET time_zone = '+00:00'");
} catch (Throwable $error) {
    fwrite(STDERR, 'Portal MySQL fixture unavailable: ' . $error->getMessage() . PHP_EOL);
    exit(2);
}

$checks = 0;
$failures = 0;

function portal_mysql_check(string $name, bool $condition): void
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

function portal_mysql_expect(string $name, string $class, callable $operation): void
{
    try {
        $operation();
    } catch (Throwable $error) {
        portal_mysql_check($name, $error instanceof $class);
        return;
    }
    portal_mysql_check($name, false);
}

/** @return list<string> */
function portal_sql_statements(string $sql): array
{
    $delimiter = ';';
    $buffer = '';
    $statements = [];
    foreach (preg_split('/\R/', $sql) ?: [] as $line) {
        if (preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $match) === 1) {
            if (trim($buffer) !== '') throw new RuntimeException('DELIMITER changed with pending SQL.');
            $delimiter = $match[1];
            continue;
        }
        $buffer .= $line . "\n";
        $trimmed = rtrim($buffer);
        if (! str_ends_with($trimmed, $delimiter)) continue;
        $statement = trim(substr($trimmed, 0, -strlen($delimiter)));
        if ($statement !== '') $statements[] = $statement;
        $buffer = '';
    }
    if (trim($buffer) !== '') throw new RuntimeException('Unterminated SQL statement.');
    return $statements;
}

function portal_execute_sql_file(PDO $pdo, string $path): void
{
    $sql = file_get_contents($path);
    if (! is_string($sql)) throw new RuntimeException("Cannot read {$path}");
    foreach (portal_sql_statements($sql) as $statement) {
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

function portal_reset_database(PDO $pdo): void
{
    $tables = $pdo->query(
        'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()'
    )->fetchAll(PDO::FETCH_COLUMN);
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables as $table) {
        $escaped = str_replace('`', '``', (string)$table);
        $pdo->exec("DROP TABLE IF EXISTS `{$escaped}`");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
}

function portal_event_count(PDO $pdo, int $bindingId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM customer_portal_binding_events WHERE binding_id = ?');
    $stmt->execute([$bindingId]);
    return (int)$stmt->fetchColumn();
}

portal_reset_database($pdo);
portal_execute_sql_file($pdo, __DIR__ . '/../db/schema.sql');
portal_mysql_check('fresh schema creates portal tables',
    (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema=DATABASE()
            AND table_name IN ('customer_portal_bindings','customer_portal_binding_events')"
    )->fetchColumn() === 2);
portal_mysql_check('fresh schema creates no customer mapping',
    (int)$pdo->query('SELECT COUNT(*) FROM customer_portal_bindings')->fetchColumn() === 0);

$migration = __DIR__ . '/../db/migrations/012_customer_portal.sql';
portal_execute_sql_file($pdo, $migration);
portal_mysql_check('migration replays over fresh-schema objects', true);

$pdo->exec("INSERT INTO tenants (id,name,slug) VALUES
    (1,'Provider One','provider-one'),
    (2,'Provider Two','provider-two')");
$pdo->exec("INSERT INTO clients (id,tenant_id,name) VALUES
    (11,1,'Acme Company'),
    (12,1,'Beta Company'),
    (13,1,'Gamma Company'),
    (21,2,'Other Provider Company')");
$pdo->exec("INSERT INTO users
    (id,tenant_id,email,password_hash,full_name,initials,role,is_active) VALUES
    (101,1,'owner1@example.test','','Owner One','O1','owner',1),
    (102,1,'admin1@example.test','','Admin One','A1','admin',1),
    (103,1,'tech1@example.test','','Tech One','T1','tech',1),
    (104,1,'inactive1@example.test','','Inactive Admin','IA','admin',0),
    (201,2,'owner2@example.test','','Owner Two','O2','owner',1)");
$pdo->exec("INSERT INTO tickets
    (id,tenant_id,client_id,subject,status,priority,channel,sla_due_at,merged_into_id,created_at,updated_at,resolved_at) VALUES
    (1001,1,11,'Acme open','open','high','email','2026-08-27 00:00:00',NULL,'2026-08-25 10:00:00','2026-08-26 10:00:00',NULL),
    (1002,1,11,'Acme resolved','resolved','normal','phone','2026-08-27 00:00:00',NULL,'2026-08-24 10:00:00','2026-08-25 10:00:00','2026-08-25 10:00:00'),
    (1003,1,11,'Merged stub','resolved','low','email','2026-08-27 00:00:00',1001,'2026-08-23 10:00:00','2026-08-24 10:00:00','2026-08-24 10:00:00'),
    (1201,1,12,'Beta private','open','urgent','email','2026-08-27 00:00:00',NULL,'2026-08-25 10:00:00','2026-08-26 11:00:00',NULL),
    (2101,2,21,'Other provider private','open','urgent','email','2026-08-27 00:00:00',NULL,'2026-08-25 10:00:00','2026-08-26 12:00:00',NULL)");

portal_mysql_expect('cross-provider client cannot be targeted', PortalDataForbiddenException::class,
    fn() => portal_mapping_target($pdo, 1, 21, 101));
portal_mysql_expect('technician cannot prepare a customer mapping', PortalDataForbiddenException::class,
    fn() => portal_mapping_target($pdo, 1, 12, 103));
portal_mysql_expect('inactive admin cannot prepare a customer mapping', PortalDataForbiddenException::class,
    fn() => portal_mapping_target($pdo, 1, 12, 104));

portal_mysql_expect('database refuses reserved identity slug even on direct insert', PDOException::class,
    fn() => $pdo->exec("INSERT INTO customer_portal_bindings
        (identity_tenant_slug,tenant_id,client_id,status,prepared_by_user_id,last_changed_by_user_id,status_reason)
        VALUES ('8west',1,12,'disabled',101,101,'should fail')"));
portal_mysql_expect('database refuses a binding prepared active', PDOException::class,
    fn() => $pdo->exec("INSERT INTO customer_portal_bindings
        (identity_tenant_slug,tenant_id,client_id,status,prepared_by_user_id,last_changed_by_user_id,status_reason)
        VALUES ('active-too-early',1,12,'active',101,101,'should fail')"));
portal_mysql_expect('database refuses a technician actor on direct insert', PDOException::class,
    fn() => $pdo->exec("INSERT INTO customer_portal_bindings
        (identity_tenant_slug,tenant_id,client_id,status,prepared_by_user_id,last_changed_by_user_id,status_reason)
        VALUES ('tech-actor',1,12,'disabled',103,103,'should fail')"));

$binding = portal_prepare_binding($pdo, 'acme-id', 1, 11, 101, 'Prepared for explicit customer review.');
$bindingId = (int)$binding['id'];
portal_mysql_check('prepare writes a disabled exact binding',
    $binding['status'] === 'disabled'
    && (int)$binding['tenant_id'] === 1
    && (int)$binding['client_id'] === 11);
portal_mysql_check('prepare writes one immutable audit event', portal_event_count($pdo, $bindingId) === 1);
portal_mysql_check('disabled binding cannot establish a portal session',
    portal_active_binding_by_identity($pdo, 'acme-id') === null);
portal_mysql_expect('duplicate identity slug is refused', PortalDataConflictException::class,
    fn() => portal_prepare_binding($pdo, 'acme-id', 1, 12, 101, 'Duplicate slug.'));
portal_mysql_expect('duplicate provider tenant/client is refused', PortalDataConflictException::class,
    fn() => portal_prepare_binding($pdo, 'different-id', 1, 11, 101, 'Duplicate client.'));
portal_mysql_expect('inspection fails if any expected boundary fact differs', PortalDataNotFoundException::class,
    fn() => portal_inspect_binding($pdo, $bindingId, 'acme-id', 1, 12));

$active = portal_transition_binding(
    $pdo, $bindingId, 'acme-id', 1, 11, 102, 'active', 'Enabled after exact inspection.'
);
portal_mysql_check('admin may explicitly enable inspected binding',
    $active['status'] === 'active' && (int)$active['last_changed_by_user_id'] === 102);
portal_mysql_check('enable writes the second audit event', portal_event_count($pdo, $bindingId) === 2);
$resolved = portal_active_binding_by_identity($pdo, 'acme-id');
portal_mysql_check('active identity lookup resolves one explicit provider boundary',
    is_array($resolved) && (int)$resolved['tenant_id'] === 1 && (int)$resolved['client_id'] === 11);
portal_mysql_check('active request recheck binds slug, tenant, client, and binding',
    portal_active_binding_recheck($pdo, $bindingId, 'acme-id', 1, 11) !== null
    && portal_active_binding_recheck($pdo, $bindingId, 'acme-id', 2, 11) === null
    && portal_active_binding_recheck($pdo, $bindingId, 'acme-id', 1, 12) === null);

$summary = portal_ticket_summary($pdo, 1, 11, 50);
$ticketIds = array_map(static fn(array $row): int => (int)$row['id'], $summary['tickets']);
sort($ticketIds);
portal_mysql_check('ticket summary returns only mapped tenant/client non-merged rows',
    $ticketIds === [1001, 1002]);
portal_mysql_check('ticket counts remain inside mapped tenant/client',
    $summary['counts'] === ['open' => 1, 'in_progress' => 0, 'waiting' => 0, 'resolved' => 1]);
portal_mysql_check('ticket summary field list has no message/contact/assignee/billing facts',
    array_keys($summary['tickets'][0]) === [
        'id', 'subject', 'status', 'priority', 'created_at', 'updated_at', 'resolved_at',
    ]);

$sameActive = portal_transition_binding(
    $pdo, $bindingId, 'acme-id', 1, 11, 102, 'active', 'Idempotent retry.'
);
portal_mysql_check('same-state retry is idempotent and adds no audit fiction',
    $sameActive['status'] === 'active' && portal_event_count($pdo, $bindingId) === 2);

$disabled = portal_transition_binding(
    $pdo, $bindingId, 'acme-id', 1, 11, 101, 'disabled', 'Customer access revoked.'
);
portal_mysql_check('owner may explicitly disable mapping',
    $disabled['status'] === 'disabled' && portal_event_count($pdo, $bindingId) === 3);
portal_mysql_check('disabled mapping is refused on the next active recheck',
    portal_active_binding_recheck($pdo, $bindingId, 'acme-id', 1, 11) === null);

portal_transition_binding(
    $pdo, $bindingId, 'acme-id', 1, 11, 101, 'active', 'Re-enabled after a new exact review.'
);
portal_mysql_check('re-enable preserves complete lifecycle history', portal_event_count($pdo, $bindingId) === 4);

portal_mysql_expect('binding identity/provider facts are immutable', PDOException::class,
    fn() => $pdo->exec("UPDATE customer_portal_bindings SET identity_tenant_slug='changed' WHERE id={$bindingId}"));
portal_mysql_expect('binding deletion is refused', PDOException::class,
    fn() => $pdo->exec("DELETE FROM customer_portal_bindings WHERE id={$bindingId}"));
$eventId = (int)$pdo->query(
    "SELECT id FROM customer_portal_binding_events WHERE binding_id={$bindingId} ORDER BY id LIMIT 1"
)->fetchColumn();
portal_mysql_expect('audit event update is refused', PDOException::class,
    fn() => $pdo->exec("UPDATE customer_portal_binding_events SET reason='rewrite' WHERE id={$eventId}"));
portal_mysql_expect('audit event deletion is refused', PDOException::class,
    fn() => $pdo->exec("DELETE FROM customer_portal_binding_events WHERE id={$eventId}"));
portal_mysql_expect('client deletion cannot orphan binding history', PDOException::class,
    fn() => $pdo->exec('DELETE FROM clients WHERE tenant_id=1 AND id=11'));

$beforeReplayEvents = portal_event_count($pdo, $bindingId);
portal_execute_sql_file($pdo, $migration);
portal_mysql_check('migration replay preserves mappings and event history',
    portal_event_count($pdo, $bindingId) === $beforeReplayEvents);
portal_mysql_check('migration replay restores exactly seven lifecycle guards',
    (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.triggers
          WHERE trigger_schema=DATABASE() AND trigger_name LIKE 'trg_customer_portal_%'"
    )->fetchColumn() === 7);
portal_mysql_check('migration replay leaves active binding operational',
    portal_active_binding_recheck($pdo, $bindingId, 'acme-id', 1, 11) !== null);

$pdo->exec(
    'ALTER TABLE customer_portal_bindings ADD UNIQUE KEY uq_unreviewed_portal_status (status)'
);
portal_mysql_expect('migration refuses an unreviewed extra unique index', PDOException::class,
    fn() => portal_execute_sql_file($pdo, $migration));
portal_mysql_check('extra-index replay fails before dropping lifecycle guards',
    (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.triggers
          WHERE trigger_schema=DATABASE() AND trigger_name LIKE 'trg_customer_portal_%'"
    )->fetchColumn() === 7);
$pdo->exec('ALTER TABLE customer_portal_bindings DROP INDEX uq_unreviewed_portal_status');

$pdo->exec(
    'ALTER TABLE customer_portal_binding_events DROP FOREIGN KEY fk_customer_portal_event_actor'
);
portal_mysql_expect('migration refuses a malformed same-named foreign-key shape', PDOException::class,
    fn() => portal_execute_sql_file($pdo, $migration));
portal_mysql_check('malformed replay fails before dropping lifecycle guards',
    (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.triggers
          WHERE trigger_schema=DATABASE() AND trigger_name LIKE 'trg_customer_portal_%'"
    )->fetchColumn() === 7);

echo "Portal MySQL: {$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
