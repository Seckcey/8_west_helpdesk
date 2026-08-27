<?php
/**
 * Disposable MySQL 8 coverage for migration 015 and customer provisioning.
 *
 * Requires an explicit disposable-server acknowledgement and creates a
 * random per-run database plus account. Both are removed in finally.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);

$SYNC_MYSQL_CONFIG = [
    'suite_customer_sync' => [
        'enabled' => true,
        'tenant_slugs' => ['8west'],
        'hmac_secret' => bin2hex(random_bytes(32)),
    ],
];

function cfg(string $key, mixed $default = null): mixed
{
    global $SYNC_MYSQL_CONFIG;
    $value = $SYNC_MYSQL_CONFIG;
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) return $default;
        $value = $value[$part];
    }
    return $value;
}

require_once __DIR__ . '/../lib/suite_customer_sync.php';

$disposableServer = getenv('SAFEHARBOR_CUSTOMER_SYNC_TEST_DISPOSABLE_SERVER');
if ($disposableServer !== '1') {
    fwrite(STDERR, "Refusing MySQL test without explicit disposable-server acknowledgement.\n");
    exit(2);
}
$databaseBase = getenv('SAFEHARBOR_CUSTOMER_SYNC_TEST_DB') ?: 'safeharbor_customer_sync_test';
if (preg_match('/\Asafeharbor_customer_sync_test(?:_[a-z0-9_]+)?\z/', $databaseBase) !== 1
    || strlen($databaseBase) > 48
) {
    fwrite(STDERR, "Refusing destructive test database base.\n");
    exit(2);
}
$runId = bin2hex(random_bytes(6));
$database = $databaseBase . '_' . $runId;
$host = getenv('SAFEHARBOR_CUSTOMER_SYNC_TEST_HOST') ?: '127.0.0.1';
$port = getenv('SAFEHARBOR_CUSTOMER_SYNC_TEST_PORT') ?: '3306';
$user = getenv('SAFEHARBOR_CUSTOMER_SYNC_TEST_USER') ?: 'root';
$pass = getenv('SAFEHARBOR_CUSTOMER_SYNC_TEST_PASS') ?: '';
$serverDsn = "mysql:host={$host};port={$port};charset=utf8mb4";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

$checks = 0;
$failures = 0;

function sync_mysql_check(string $name, bool $condition): void
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

/** @param class-string<Throwable> $expected */
function sync_mysql_throws(
    string $name,
    string $expected,
    callable $operation,
    string $message = '',
    ?int $expectedVersion = null,
): void {
    try {
        $operation();
        sync_mysql_check($name, false);
    } catch (Throwable $error) {
        $ok = $error instanceof $expected
            && ($message === '' || $error->getMessage() === $message);
        if ($expectedVersion !== null) {
            $ok = $ok
                && $error instanceof SuiteCustomerSyncConflictException
                && $error->expectedSourceVersion === $expectedVersion;
        }
        sync_mysql_check($name, $ok);
    }
}

function sync_mysql_throws_containing(
    string $name,
    string $expected,
    callable $operation,
    string $messageFragment,
): void {
    try {
        $operation();
        sync_mysql_check($name, false);
    } catch (Throwable $error) {
        sync_mysql_check(
            $name,
            $error instanceof $expected
                && str_contains($error->getMessage(), $messageFragment),
        );
    }
}

/** @return list<string> */
function sync_mysql_statements(string $sql): array
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
        if (!str_ends_with($trimmed, $delimiter)) continue;
        $statement = trim(substr($trimmed, 0, -strlen($delimiter)));
        if ($statement !== '') $statements[] = $statement;
        $buffer = '';
    }
    if (trim($buffer) !== '') throw new RuntimeException('Unterminated SQL statement.');
    return $statements;
}

function sync_mysql_execute_file(PDO $pdo, string $path): void
{
    $sql = file_get_contents($path);
    if (!is_string($sql)) throw new RuntimeException("Cannot read {$path}");
    foreach (sync_mysql_statements($sql) as $statement) {
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

/** @return list<string> */
function sync_mysql_trigger_fingerprints(PDO $pdo): array
{
    return $pdo->query(
        "SELECT CONCAT(
           trigger_name, ':', event_object_table, ':', event_manipulation, ':',
           action_timing, ':', action_orientation, ':',
           SHA2(REGEXP_REPLACE(REPLACE(LOWER(action_statement), '`', ''), '[[:space:]]+', ''), 256)
         )
           FROM information_schema.triggers
          WHERE trigger_schema=DATABASE()
            AND event_object_table IN (
              'suite_customer_sync_bindings','suite_customer_sync_events'
            )
          ORDER BY BINARY trigger_name"
    )->fetchAll(PDO::FETCH_COLUMN);
}

function sync_mysql_reset(PDO $pdo): void
{
    $tables = $pdo->query(
        'SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE()'
    )->fetchAll(PDO::FETCH_COLUMN);
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables as $table) {
        $escaped = str_replace('`', '``', (string)$table);
        $pdo->exec("DROP TABLE IF EXISTS `{$escaped}`");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
}

/** @param array<string,mixed> $replace @return array{raw:string,payload:array<string,mixed>,sha:string} */
function sync_mysql_request(array $replace = []): array
{
    $raw = (string)json_encode(array_replace([
        'schema_version' => 1,
        'tenant_slug' => '8west',
        'customer_id' => '11111111-1111-4111-8111-111111111111',
        'source_version' => 1,
        'display_name' => '8 West IT',
        'status' => 'active',
        'event_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        'occurred_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 86400),
    ], $replace), JSON_UNESCAPED_SLASHES);
    return [
        'raw' => $raw,
        'payload' => suite_customer_sync_decode($raw),
        'sha' => hash('sha256', $raw),
    ];
}

$server = null;
$pdo = null;
$runtime = null;
$runtimeUser = 'sh_sync_' . $runId;
$runtimePass = bin2hex(random_bytes(32));
$quotedDatabase = '`' . str_replace('`', '``', $database) . '`';
$databaseCreated = false;
$fixtureReady = false;
$fatalError = null;
$cleanupError = false;
$expectedTriggerFingerprints = [
    'trg_suite_customer_sync_binding_after_insert:suite_customer_sync_bindings:INSERT:AFTER:ROW:'
        . 'd7beb89b42e72a3d812ceeb041bf7db74cc4ac9eeece0891e21a7ba2455bee58',
    'trg_suite_customer_sync_binding_after_update:suite_customer_sync_bindings:UPDATE:AFTER:ROW:'
        . 'd7beb89b42e72a3d812ceeb041bf7db74cc4ac9eeece0891e21a7ba2455bee58',
    'trg_suite_customer_sync_binding_before_insert:suite_customer_sync_bindings:INSERT:BEFORE:ROW:'
        . 'e93e150a312a423c697992d05ed66aedb0a9367f5a5bf4c0551633cd02742c11',
    'trg_suite_customer_sync_binding_before_update:suite_customer_sync_bindings:UPDATE:BEFORE:ROW:'
        . 'a85adf370b47eb04eac035dbb87dbc8b944eff7697b28dc5783c21ad0e146727',
    'trg_suite_customer_sync_binding_no_delete:suite_customer_sync_bindings:DELETE:BEFORE:ROW:'
        . '08641adecd96e76139f1a0137efbf9a6488be28f03aa783eddef3d63b59f9956',
    'trg_suite_customer_sync_event_before_insert:suite_customer_sync_events:INSERT:BEFORE:ROW:'
        . '7c0d73a6146ead07feb1a811df0aec9f18e05f205a60fe59c959519c93652266',
    'trg_suite_customer_sync_events_no_delete:suite_customer_sync_events:DELETE:BEFORE:ROW:'
        . '4b42bdd7850f953ab63813a72c2943eee8fc83b2f951cc064f87717ddcbf8013',
    'trg_suite_customer_sync_events_no_update:suite_customer_sync_events:UPDATE:BEFORE:ROW:'
        . '4b42bdd7850f953ab63813a72c2943eee8fc83b2f951cc064f87717ddcbf8013',
];

try {
$server = new PDO($serverDsn, $user, $pass, $options);
$server->exec("CREATE DATABASE {$quotedDatabase} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$databaseCreated = true;
$pdo = new PDO($serverDsn . ";dbname={$database}", $user, $pass, $options);
$pdo->exec("SET time_zone = '+00:00'");
$fixtureReady = true;

sync_mysql_reset($pdo);
sync_mysql_execute_file($pdo, __DIR__ . '/../db/schema.sql');
sync_mysql_check('fresh schema creates both customer-sync tables',
    (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema=DATABASE()
            AND table_name IN ('suite_customer_sync_bindings','suite_customer_sync_events')
            AND engine='InnoDB'"
    )->fetchColumn() === 2);
sync_mysql_check('fresh schema installs all eight lifecycle guards',
    (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.triggers
          WHERE trigger_schema=DATABASE() AND trigger_name LIKE 'trg_suite_customer_sync_%'
            AND trigger_name <> 'trg_suite_customer_sync_privilege_preflight'"
    )->fetchColumn() === 8);
sync_mysql_check('fresh schema installs exact full trigger fingerprints',
    sync_mysql_trigger_fingerprints($pdo) === $expectedTriggerFingerprints);
// Run the same important inactive-name/receipt boundary through the canonical
// fresh schema before rebuilding it through migration 015.
$pdo->exec("INSERT INTO tenants(id,name,slug) VALUES(901,'Fresh Schema MSP','fresh-schema')");
$pdo->exec("INSERT INTO clients(id,tenant_id,name) VALUES(901,901,'Fresh Active Name')");
$pdo->exec("INSERT INTO suite_customer_sync_bindings
    (tenant_id,customer_id,client_id,source_version,display_name,status,
     last_event_id,last_occurred_at,last_request_sha256)
    VALUES(901,'90111111-1111-4111-8111-111111111111',901,1,
     'Fresh Active Name','active','901aaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
     DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 HOUR),'" . str_repeat('a', 64) . "')");
$pdo->exec("UPDATE suite_customer_sync_bindings
    SET source_version=2,display_name='Fresh Retired Source Name',status='inactive',
        last_event_id='901bbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
        last_occurred_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR),
        last_request_sha256='" . str_repeat('b', 64) . "'
    WHERE tenant_id=901 AND client_id=901");
sync_mysql_check('fresh schema records changed-name inactive receipt without renaming retained client',
    $pdo->query("SELECT name FROM clients WHERE id=901")->fetchColumn() === 'Fresh Active Name'
    && $pdo->query("SELECT display_name FROM suite_customer_sync_events
                     WHERE tenant_id=901 AND source_version=2")->fetchColumn()
        === 'Fresh Retired Source Name'
    && (int)$pdo->query("SELECT COUNT(*) FROM suite_customer_sync_events WHERE tenant_id=901")->fetchColumn() === 2);

// Recreate the feature through its upgrade artifact, proving migration 015
// independently from the fresh-schema path.
$triggers = $pdo->query(
    "SELECT trigger_name FROM information_schema.triggers
      WHERE trigger_schema=DATABASE() AND trigger_name LIKE 'trg_suite_customer_sync_%'"
)->fetchAll(PDO::FETCH_COLUMN);
foreach ($triggers as $trigger) {
    $escaped = str_replace('`', '``', (string)$trigger);
    $pdo->exec("DROP TRIGGER `{$escaped}`");
}
$pdo->exec('DROP TABLE suite_customer_sync_events');
$pdo->exec('DROP TABLE suite_customer_sync_bindings');
$pdo->exec('DELETE FROM clients WHERE id=901 AND tenant_id=901');
$pdo->exec('DELETE FROM tenants WHERE id=901');
sync_mysql_execute_file($pdo, __DIR__ . '/../db/migrations/015_suite_customer_sync.sql');
sync_mysql_check('migration 015 creates exact table column counts',
    $pdo->query(
        "SELECT CONCAT(table_name,':',COUNT(*))
           FROM information_schema.columns
          WHERE table_schema=DATABASE()
            AND table_name IN ('suite_customer_sync_bindings','suite_customer_sync_events')
          GROUP BY table_name ORDER BY table_name"
    )->fetchAll(PDO::FETCH_COLUMN) === [
        'suite_customer_sync_bindings:12',
        'suite_customer_sync_events:12',
    ]);
sync_mysql_check('migration 015 creates eleven exact named indexes',
    (int)$pdo->query(
        "SELECT COUNT(DISTINCT CONCAT(table_name,':',index_name))
           FROM information_schema.statistics
          WHERE table_schema=DATABASE()
            AND table_name IN ('suite_customer_sync_bindings','suite_customer_sync_events')"
    )->fetchColumn() === 11);
sync_mysql_check('migration 015 enforces ten checks and five tenant-scoped foreign keys',
    (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.table_constraints
          WHERE constraint_schema=DATABASE()
            AND table_name IN ('suite_customer_sync_bindings','suite_customer_sync_events')
            AND constraint_type='CHECK' AND enforced='YES'"
    )->fetchColumn() === 10
    && (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.referential_constraints
          WHERE constraint_schema=DATABASE()
            AND table_name IN ('suite_customer_sync_bindings','suite_customer_sync_events')
            AND update_rule='NO ACTION' AND delete_rule='NO ACTION'"
    )->fetchColumn() === 5);
sync_mysql_check('migration 015 installs all eight exact trigger roles',
    $pdo->query(
        "SELECT CONCAT(trigger_name,':',action_timing,':',event_manipulation,':',event_object_table)
           FROM information_schema.triggers
          WHERE trigger_schema=DATABASE() AND trigger_name LIKE 'trg_suite_customer_sync_%'
          ORDER BY BINARY trigger_name"
    )->fetchAll(PDO::FETCH_COLUMN) === [
        'trg_suite_customer_sync_binding_after_insert:AFTER:INSERT:suite_customer_sync_bindings',
        'trg_suite_customer_sync_binding_after_update:AFTER:UPDATE:suite_customer_sync_bindings',
        'trg_suite_customer_sync_binding_before_insert:BEFORE:INSERT:suite_customer_sync_bindings',
        'trg_suite_customer_sync_binding_before_update:BEFORE:UPDATE:suite_customer_sync_bindings',
        'trg_suite_customer_sync_binding_no_delete:BEFORE:DELETE:suite_customer_sync_bindings',
        'trg_suite_customer_sync_event_before_insert:BEFORE:INSERT:suite_customer_sync_events',
        'trg_suite_customer_sync_events_no_delete:BEFORE:DELETE:suite_customer_sync_events',
        'trg_suite_customer_sync_events_no_update:BEFORE:UPDATE:suite_customer_sync_events',
    ]);
sync_mysql_check('migration 015 installs exact full trigger fingerprints',
    sync_mysql_trigger_fingerprints($pdo) === $expectedTriggerFingerprints);

// schema.sql intentionally excludes the shared svc and support-intake
// migration objects. Apply them exactly as a fresh installation must.
sync_mysql_execute_file($pdo, __DIR__ . '/../db/migrations/002_svc_intake.sql');
sync_mysql_execute_file($pdo, __DIR__ . '/../db/migrations/009_support_intake.sql');
$pdo->exec("INSERT INTO tenants(id,name,slug) VALUES
    (1,'8 West IT, LLC','8west'),(2,'Other MSP','other')");
$pdo->exec("INSERT INTO users
    (id,tenant_id,email,password_hash,full_name,initials,role,is_active) VALUES
    (101,1,'owner@example.test','','Owner','OW','owner',1)");
$pdo->exec("INSERT INTO svc_identities(tenant_id,service,display_name,is_active) VALUES
    (1,'milepost-customers','Milepost customer registry',1),
    (2,'milepost-customers','Milepost customer registry',1)");

// Model the production-shaped shared endpoint account: broad table DML, but
// no DDL or TRIGGER privilege. Database guards, not an artificially narrow
// test grant, must protect customer-sync identity and history.
$escapedDb = str_replace('`', '``', $database);
$server->exec("CREATE USER '{$runtimeUser}'@'%' IDENTIFIED BY '{$runtimePass}'");
$server->exec("GRANT SELECT, INSERT, UPDATE, DELETE ON `{$escapedDb}`.* TO '{$runtimeUser}'@'%'");
$runtime = new PDO($serverDsn . ";dbname={$database}", $runtimeUser, $runtimePass, $options);
$runtime->exec("SET time_zone = '+00:00'");
$grantText = implode("\n", $runtime->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN));
sync_mysql_check('production-shaped runtime has broad DML but no DDL or trigger authority',
    str_contains($grantText, 'SELECT')
    && str_contains($grantText, 'INSERT')
    && str_contains($grantText, 'UPDATE')
    && str_contains($grantText, 'DELETE')
    && !str_contains($grantText, 'DROP')
    && !str_contains($grantText, 'ALTER')
    && !str_contains($grantText, 'CREATE')
    && !str_contains($grantText, 'TRIGGER'));
sync_mysql_throws_containing(
    'broad-DML runtime cannot manufacture an event receipt',
    PDOException::class,
    fn() => $runtime->exec("INSERT INTO suite_customer_sync_events
        (tenant_id,event_id,binding_id,customer_id,client_id,source_version,
         display_name,status,occurred_at,request_sha256)
        VALUES(1,'99999999-9999-4999-8999-999999999999',1,
         '99999999-9999-4999-8999-999999999998',1,1,'Bad','active',
         UTC_TIMESTAMP(),'" . str_repeat('9', 64) . "')"),
    'Customer sync receipt must match the exact binding and client',
);

$create = sync_mysql_request();
$timestamp = (string)time();
$signature = hash_hmac(
    'sha256',
    SUITE_CUSTOMER_SYNC_SIGNATURE_CONTEXT . "\n{$timestamp}\n" . $create['raw'],
    (string)cfg('suite_customer_sync.hmac_secret'),
);
sync_mysql_check('runtime accepts the exact destination HMAC identity',
    suite_customer_sync_authenticate(
        $runtime, 1, '8west', SUITE_CUSTOMER_SYNC_SERVICE,
        $timestamp, $signature, $create['raw'], (int)$timestamp,
    )['ok'] === true);

$created = suite_customer_sync_receive($runtime, $create['payload'], $create['sha']);
sync_mysql_check('native create returns the exact five-field success receipt',
    array_keys($created) === ['ok','event_id','customer_id','source_version','status']
    && $created === [
        'ok' => true,
        'event_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        'customer_id' => '11111111-1111-4111-8111-111111111111',
        'source_version' => 1,
        'status' => 'active',
    ]);
$binding = $pdo->query('SELECT * FROM suite_customer_sync_bindings')->fetch(PDO::FETCH_ASSOC);
$clientId = (int)$binding['client_id'];
sync_mysql_check('create makes one stable default Safeharbor client without source_key',
    (int)$pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn() === 1
    && $pdo->query("SELECT name FROM clients WHERE id={$clientId}")->fetchColumn() === '8 West IT'
    && (int)$pdo->query("SELECT source_key IS NULL FROM clients WHERE id={$clientId}")->fetchColumn() === 1
    && $pdo->query("SELECT sla_tier FROM clients WHERE id={$clientId}")->fetchColumn() === 'standard');
$replayed = suite_customer_sync_receive($runtime, $create['payload'], $create['sha']);
sync_mysql_check('exact event replay is byte-shape idempotent',
    $replayed === $created
    && (int)$pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn() === 1
    && (int)$pdo->query('SELECT COUNT(*) FROM suite_customer_sync_events')->fetchColumn() === 1);

sync_mysql_throws('same event id with changed facts is rejected', SuiteCustomerSyncConflictException::class,
    function () use ($runtime): void {
        $request = sync_mysql_request(['display_name' => 'Tampered']);
        suite_customer_sync_receive($runtime, $request['payload'], $request['sha']);
    }, 'event_id_conflict');
sync_mysql_throws('different event with stale same version is rejected with expected next version',
    SuiteCustomerSyncConflictException::class,
    function () use ($runtime): void {
        $request = sync_mysql_request(['event_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb']);
        suite_customer_sync_receive($runtime, $request['payload'], $request['sha']);
    }, 'source_version_conflict', 2);
sync_mysql_throws('gapped source version is rejected with expected next version',
    SuiteCustomerSyncConflictException::class,
    function () use ($runtime): void {
        $request = sync_mysql_request([
            'source_version' => 3,
            'event_id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
        ]);
        suite_customer_sync_receive($runtime, $request['payload'], $request['sha']);
    }, 'source_version_conflict', 2);

// Add Safeharbor-owned facts and dependent history before rename/deactivate.
$pdo->exec("UPDATE clients SET domain='8westit.com',source_key='coastmark:preserve-me',
    sla_tier='premium',health='watch',notes='Safeharbor owned' WHERE id={$clientId}");
$dueAt = gmdate('Y-m-d H:i:s', time() + 7200);
$ticketInsert = $pdo->prepare(
    'INSERT INTO tickets(tenant_id,client_id,subject,sla_due_at) VALUES(1,?,?,?)'
);
$ticketInsert->execute([$clientId, 'Preserve this ticket', $dueAt]);
$ticketId = (int)$pdo->lastInsertId();
$timeInsert = $pdo->prepare(
    "INSERT INTO time_entries
        (tenant_id,client_id,entry_key,ticket_id,user_id,minutes,note,billable,source,worked_at)
     VALUES(1,?,'sync-preserve-time',?,101,15,'Preserve this time',1,'legacy',UTC_TIMESTAMP())"
);
$timeInsert->execute([$clientId, $ticketId]);

$rename = sync_mysql_request([
    'source_version' => 2,
    'display_name' => '8 West IT, LLC',
    'event_id' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
    'occurred_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 3600),
]);
$renamed = suite_customer_sync_receive($runtime, $rename['payload'], $rename['sha']);
$client = $pdo->query("SELECT * FROM clients WHERE id={$clientId}")->fetch(PDO::FETCH_ASSOC);
sync_mysql_check('rename advances the binding but preserves every Safeharbor-owned client fact',
    $renamed['source_version'] === 2
    && $client['name'] === '8 West IT, LLC'
    && $client['domain'] === '8westit.com'
    && $client['source_key'] === 'coastmark:preserve-me'
    && $client['sla_tier'] === 'premium'
    && $client['health'] === 'watch'
    && $client['notes'] === 'Safeharbor owned');

$inactiveRequest = sync_mysql_request([
    'source_version' => 3,
    'display_name' => 'Milepost Retired Name',
    'status' => 'inactive',
    'event_id' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
    'occurred_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 60),
]);
$inactive = suite_customer_sync_receive(
    $runtime, $inactiveRequest['payload'], $inactiveRequest['sha'],
);
sync_mysql_check('changed-name deactivate retains current client name, ticket, time, SLA, and history',
    $inactive['status'] === 'inactive'
    && (int)$pdo->query("SELECT COUNT(*) FROM clients WHERE id={$clientId}")->fetchColumn() === 1
    && $pdo->query("SELECT name FROM clients WHERE id={$clientId}")->fetchColumn() === '8 West IT, LLC'
    && $pdo->query("SELECT display_name FROM suite_customer_sync_events WHERE source_version=3")->fetchColumn()
        === 'Milepost Retired Name'
    && (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE id={$ticketId} AND sla_due_at=" . $pdo->quote($dueAt))->fetchColumn() === 1
    && (int)$pdo->query("SELECT COUNT(*) FROM time_entries WHERE ticket_id={$ticketId}")->fetchColumn() === 1
    && (int)$pdo->query('SELECT COUNT(*) FROM suite_customer_sync_events')->fetchColumn() === 3);
sync_mysql_check('old version replay remains identical after newer versions',
    suite_customer_sync_receive($runtime, $create['payload'], $create['sha']) === $created);

$reactivateRequest = sync_mysql_request([
    'source_version' => 4,
    'display_name' => '8 West IT Reactivated',
    'status' => 'active',
    'event_id' => '99999999-9999-4999-8999-999999999999',
    'occurred_at' => gmdate('Y-m-d\TH:i:s\Z', time()),
]);
$reactivated = suite_customer_sync_receive(
    $runtime, $reactivateRequest['payload'], $reactivateRequest['sha'],
);
sync_mysql_check('later active version reactivates and renames the same client with history intact',
    $reactivated['status'] === 'active'
    && $pdo->query("SELECT name FROM clients WHERE id={$clientId}")->fetchColumn()
        === '8 West IT Reactivated'
    && (int)$pdo->query('SELECT client_id FROM suite_customer_sync_bindings')->fetchColumn() === $clientId
    && (int)$pdo->query('SELECT COUNT(*) FROM suite_customer_sync_events')->fetchColumn() === 4
    && (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE id={$ticketId}")->fetchColumn() === 1
    && (int)$pdo->query("SELECT COUNT(*) FROM time_entries WHERE ticket_id={$ticketId}")->fetchColumn() === 1);

sync_mysql_throws('a source customer cannot move across Safeharbor tenants',
    SuiteCustomerSyncConflictException::class,
    function () use ($runtime): void {
        $request = sync_mysql_request([
            'tenant_slug' => 'other',
            'source_version' => 5,
            'event_id' => 'ffffffff-ffff-4fff-8fff-ffffffffffff',
            'occurred_at' => gmdate('Y-m-d\TH:i:s\Z', time()),
        ]);
        suite_customer_sync_receive($runtime, $request['payload'], $request['sha']);
    }, 'customer_tenant_conflict');

sync_mysql_throws_containing(
    'broad-DML runtime cannot forge a later receipt',
    PDOException::class,
    fn() => $runtime->exec("INSERT INTO suite_customer_sync_events
        (tenant_id,event_id,binding_id,customer_id,client_id,source_version,
         display_name,status,occurred_at,request_sha256)
        VALUES(1,'88888888-8888-4888-8888-888888888888'," . (int)$binding['id'] . ",
         '11111111-1111-4111-8111-111111111111',{$clientId},5,
         'Forged','active',UTC_TIMESTAMP(),'" . str_repeat('8', 64) . "')"),
    'Customer sync receipt must match the exact binding and client',
);
sync_mysql_throws_containing(
    'broad-DML runtime cannot move a stable source binding',
    PDOException::class,
    fn() => $runtime->exec("UPDATE suite_customer_sync_bindings
        SET customer_id='22222222-2222-4222-8222-222222222222',source_version=5,
            last_event_id='77777777-7777-4777-8777-777777777777',
            last_request_sha256='" . str_repeat('7', 64) . "'
        WHERE id=" . (int)$binding['id']),
    'Customer sync binding identity is immutable',
);
sync_mysql_throws_containing(
    'broad-DML runtime cannot delete a binding',
    PDOException::class,
    fn() => $runtime->exec('DELETE FROM suite_customer_sync_bindings'),
    'Customer sync bindings cannot be deleted',
);
sync_mysql_throws_containing(
    'broad-DML runtime cannot rewrite receipt history',
    PDOException::class,
    fn() => $runtime->exec("UPDATE suite_customer_sync_events SET status='active' WHERE id=1"),
    'Customer sync event receipts are immutable',
);
sync_mysql_throws_containing(
    'broad-DML runtime cannot delete receipt history',
    PDOException::class,
    fn() => $runtime->exec('DELETE FROM suite_customer_sync_events WHERE id=1'),
    'Customer sync event receipts are immutable',
);
sync_mysql_throws_containing(
    'broad-DML runtime cannot write a gapped binding version',
    PDOException::class,
    fn() => $runtime->exec("UPDATE suite_customer_sync_bindings
        SET source_version=6,last_event_id='12345678-1234-4234-8234-123456789abc',
            last_request_sha256='" . str_repeat('1', 64) . "'
        WHERE id=" . (int)$binding['id']),
    'Customer sync source versions must be sequential',
);
sync_mysql_throws('broad-DML runtime cannot orphan customer or operational history', PDOException::class,
    fn() => $runtime->exec("DELETE FROM clients WHERE id={$clientId}"));
sync_mysql_throws('broad-DML runtime has no DDL authority', PDOException::class,
    fn() => $runtime->exec('ALTER TABLE suite_customer_sync_events ADD COLUMN forged INT NULL'));

$historyBeforeReplay = $pdo->query(
    "SELECT CONCAT(
      (SELECT COUNT(*) FROM clients),':',
      (SELECT COUNT(*) FROM suite_customer_sync_bindings),':',
      (SELECT COUNT(*) FROM suite_customer_sync_events),':',
      (SELECT COUNT(*) FROM tickets),':',
      (SELECT COUNT(*) FROM time_entries))"
)->fetchColumn();
sync_mysql_execute_file($pdo, __DIR__ . '/../db/migrations/015_suite_customer_sync.sql');
$historyAfterReplay = $pdo->query(
    "SELECT CONCAT(
      (SELECT COUNT(*) FROM clients),':',
      (SELECT COUNT(*) FROM suite_customer_sync_bindings),':',
      (SELECT COUNT(*) FROM suite_customer_sync_events),':',
      (SELECT COUNT(*) FROM tickets),':',
      (SELECT COUNT(*) FROM time_entries))"
)->fetchColumn();
sync_mysql_check('migration replay preserves all customer and operational history',
    $historyAfterReplay === $historyBeforeReplay);
sync_mysql_check('migration replay restores exactly eight lifecycle guards',
    (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.triggers
          WHERE trigger_schema=DATABASE() AND trigger_name LIKE 'trg_suite_customer_sync_%'"
    )->fetchColumn() === 8);

$pdo->exec("ALTER TABLE suite_customer_sync_bindings
    DROP CHECK ck_suite_customer_sync_version,
    ADD CONSTRAINT ck_suite_customer_sync_version CHECK (1 = 1) ENFORCED");
sync_mysql_throws('migration refuses a same-name fake binding check before replacing guards',
    PDOException::class,
    fn() => sync_mysql_execute_file($pdo, __DIR__ . '/../db/migrations/015_suite_customer_sync.sql'));
sync_mysql_check('fake binding-check refusal leaves all eight lifecycle guards installed',
    (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.triggers
          WHERE trigger_schema=DATABASE() AND trigger_name LIKE 'trg_suite_customer_sync_%'"
    )->fetchColumn() === 8);
$pdo->exec("ALTER TABLE suite_customer_sync_bindings
    DROP CHECK ck_suite_customer_sync_version,
    ADD CONSTRAINT ck_suite_customer_sync_version CHECK (source_version >= 1) ENFORCED");
sync_mysql_execute_file($pdo, __DIR__ . '/../db/migrations/015_suite_customer_sync.sql');

$pdo->exec("ALTER TABLE suite_customer_sync_events
    DROP CHECK ck_suite_customer_sync_receipt_version,
    ADD CONSTRAINT ck_suite_customer_sync_receipt_version CHECK (1 = 1) ENFORCED");
sync_mysql_throws('migration refuses a same-name fake receipt check before replacing guards',
    PDOException::class,
    fn() => sync_mysql_execute_file($pdo, __DIR__ . '/../db/migrations/015_suite_customer_sync.sql'));
sync_mysql_check('fake receipt-check refusal leaves all eight lifecycle guards installed',
    (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.triggers
          WHERE trigger_schema=DATABASE() AND trigger_name LIKE 'trg_suite_customer_sync_%'"
    )->fetchColumn() === 8);
$pdo->exec("ALTER TABLE suite_customer_sync_events
    DROP CHECK ck_suite_customer_sync_receipt_version,
    ADD CONSTRAINT ck_suite_customer_sync_receipt_version CHECK (source_version >= 1) ENFORCED");
sync_mysql_execute_file($pdo, __DIR__ . '/../db/migrations/015_suite_customer_sync.sql');

$pdo->exec("ALTER TABLE suite_customer_sync_events
    MODIFY received_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)");
sync_mysql_throws('migration refuses critical timestamp-column fingerprint drift',
    PDOException::class,
    fn() => sync_mysql_execute_file($pdo, __DIR__ . '/../db/migrations/015_suite_customer_sync.sql'));
sync_mysql_check('column-fingerprint refusal leaves all eight lifecycle guards installed',
    (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.triggers
          WHERE trigger_schema=DATABASE() AND trigger_name LIKE 'trg_suite_customer_sync_%'"
    )->fetchColumn() === 8);
$pdo->exec("ALTER TABLE suite_customer_sync_events
    MODIFY received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
sync_mysql_execute_file($pdo, __DIR__ . '/../db/migrations/015_suite_customer_sync.sql');

$pdo->exec('DROP TRIGGER trg_suite_customer_sync_events_no_update');
$pdo->exec("CREATE TRIGGER trg_suite_customer_sync_events_no_update
    BEFORE UPDATE ON suite_customer_sync_events
    FOR EACH ROW
    BEGIN
      IF 1 = 0 THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'Customer sync event receipts are immutable';
      END IF;
    END");
sync_mysql_throws(
    'migration refuses a phrase-preserving no-op trigger before replacing guards',
    PDOException::class,
    fn() => sync_mysql_execute_file($pdo, __DIR__ . '/../db/migrations/015_suite_customer_sync.sql'),
);
$weakenedTrigger = $pdo->query(
    "SELECT CONCAT(
       LOWER(action_statement) LIKE '%event receipts are immutable%', ':',
       SHA2(REGEXP_REPLACE(REPLACE(LOWER(action_statement), '`', ''), '[[:space:]]+', ''), 256)
         <> '4b42bdd7850f953ab63813a72c2943eee8fc83b2f951cc064f87717ddcbf8013'
     )
       FROM information_schema.triggers
      WHERE trigger_schema=DATABASE()
        AND trigger_name='trg_suite_customer_sync_events_no_update'"
)->fetchColumn();
sync_mysql_check('trigger drift refusal preserves all eight objects and exposes the weakened body',
    $weakenedTrigger === '1:1'
    && (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.triggers
          WHERE trigger_schema=DATABASE() AND trigger_name LIKE 'trg_suite_customer_sync_%'"
    )->fetchColumn() === 8);
$pdo->exec('DROP TRIGGER trg_suite_customer_sync_events_no_update');
$pdo->exec("CREATE TRIGGER trg_suite_customer_sync_events_no_update
    BEFORE UPDATE ON suite_customer_sync_events
    FOR EACH ROW
    BEGIN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Customer sync event receipts are immutable';
    END");
sync_mysql_execute_file($pdo, __DIR__ . '/../db/migrations/015_suite_customer_sync.sql');

$pdo->exec("ALTER TABLE suite_customer_sync_events
    ADD CONSTRAINT fk_unreviewed_sync_client
      FOREIGN KEY (tenant_id,client_id) REFERENCES clients(tenant_id,id)");
sync_mysql_check('unexpected foreign-key drift adds a sixth FK without changing index shape',
    (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.table_constraints
          WHERE constraint_schema=DATABASE()
            AND table_name IN ('suite_customer_sync_bindings','suite_customer_sync_events')
            AND constraint_type='FOREIGN KEY'"
    )->fetchColumn() === 6
    && (int)$pdo->query(
        "SELECT COUNT(DISTINCT CONCAT(table_name,':',index_name))
           FROM information_schema.statistics
          WHERE table_schema=DATABASE()
            AND table_name IN ('suite_customer_sync_bindings','suite_customer_sync_events')"
    )->fetchColumn() === 11);
sync_mysql_throws('migration refuses an unexpected sixth foreign key before replacing guards',
    PDOException::class,
    fn() => sync_mysql_execute_file($pdo, __DIR__ . '/../db/migrations/015_suite_customer_sync.sql'));
sync_mysql_check('foreign-key drift refusal leaves all eight lifecycle guards installed',
    (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.triggers
          WHERE trigger_schema=DATABASE() AND trigger_name LIKE 'trg_suite_customer_sync_%'"
    )->fetchColumn() === 8);
$pdo->exec("ALTER TABLE suite_customer_sync_events
    DROP FOREIGN KEY fk_unreviewed_sync_client");
sync_mysql_execute_file($pdo, __DIR__ . '/../db/migrations/015_suite_customer_sync.sql');

$pdo->exec('ALTER TABLE suite_customer_sync_bindings ADD KEY ix_unreviewed_sync_status (status)');
sync_mysql_throws('migration refuses unreviewed index drift before replacing guards', PDOException::class,
    fn() => sync_mysql_execute_file($pdo, __DIR__ . '/../db/migrations/015_suite_customer_sync.sql'));
sync_mysql_check('drift refusal leaves all eight lifecycle guards installed',
    (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.triggers
          WHERE trigger_schema=DATABASE() AND trigger_name LIKE 'trg_suite_customer_sync_%'"
    )->fetchColumn() === 8);
$pdo->exec('ALTER TABLE suite_customer_sync_bindings DROP INDEX ix_unreviewed_sync_status');

sync_mysql_throws('DML-only runtime cannot execute migration 015', PDOException::class,
    fn() => sync_mysql_execute_file($runtime, __DIR__ . '/../db/migrations/015_suite_customer_sync.sql'));
sync_mysql_check('under-privileged migration refusal leaves lifecycle guards installed',
    (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.triggers
          WHERE trigger_schema=DATABASE() AND trigger_name LIKE 'trg_suite_customer_sync_%'"
    )->fetchColumn() === 8);

} catch (Throwable $error) {
    $fatalError = $error;
} finally {
    $runtime = null;
    $pdo = null;
    if ($server instanceof PDO) {
        try {
            $server->exec("DROP USER IF EXISTS '{$runtimeUser}'@'%'");
        } catch (Throwable) {
            $cleanupError = true;
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
    $kind = $fixtureReady ? 'test execution failed' : 'fixture unavailable';
    fwrite(STDERR, "Customer-sync MySQL {$kind}: " . $fatalError::class . "\n");
    exit($fixtureReady ? 1 : 2);
}
if ($cleanupError) {
    fwrite(STDERR, "Customer-sync MySQL cleanup failed.\n");
    exit(1);
}
if ($failures > 0) {
    fwrite(STDERR, "{$failures} of {$checks} customer-sync MySQL checks failed.\n");
    exit(1);
}
echo "Customer-sync MySQL: {$checks}/{$checks} passed.\n";
