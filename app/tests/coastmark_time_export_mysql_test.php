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
        || !is_string($suffix) || !in_array($suffix, ['a', 'b'], true)
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
        cm_v3_check($name, $message === '' || str_contains($error->getMessage(), $message));
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
$runtimeUser = 'sh_cm_v3_' . $runId;
$runtimePass = bin2hex(random_bytes(24));
$server = null;
$pdo = null;
$created = false;
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
      customer_id CHAR(36) NOT NULL,status VARCHAR(16) NOT NULL) ENGINE=InnoDB');
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
      (504,1,11,904,'timer:internal:0004','timer','2026-08-26 20:00:00',20,'internal',0,
       'approved',101,102,'2026-08-26 20:05:00')");

    $migration = __DIR__ . '/../db/migrations/021_coastmark_time_export_v3.sql';
    cm_v3_apply($pdo, $migration);
    cm_v3_check('fresh migration creates two empty tables and six permanent triggers',
        (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema=DATABASE() AND table_name LIKE 'coastmark_time_export_%'")->fetchColumn() === 2
        && (int) $pdo->query("SELECT COUNT(*) FROM information_schema.triggers
          WHERE trigger_schema=DATABASE() AND trigger_name LIKE 'trg_cm_export_%'")->fetchColumn() === 6);

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

    $server->exec("CREATE USER '{$runtimeUser}'@'%' IDENTIFIED BY '{$runtimePass}'");
    $runtimeCreated = true;
    foreach (['tenants','clients','users','suite_customer_sync_bindings','time_entries','time_entry_approval_adjustments'] as $table) {
        $server->exec("GRANT SELECT ON {$quotedDatabase}.{$table} TO '{$runtimeUser}'@'%'");
    }
    $server->exec("GRANT SELECT,INSERT ON {$quotedDatabase}.coastmark_time_export_claims TO '{$runtimeUser}'@'%'");
    $server->exec("GRANT SELECT,INSERT ON {$quotedDatabase}.coastmark_time_export_receipts TO '{$runtimeUser}'@'%'");
    $runtime = new PDO($serverDsn . ';dbname=' . $database, $runtimeUser, $runtimePass, $options);
    $runtimeClaim = coastmark_time_export_claim(
        $runtime, '8west', 501, 'timer:mysql:000001', 102, cm_v3_config(),
    );
    cm_v3_check('least-privilege runtime can read and exactly replay claims', $runtimeClaim['replayed']);
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
        && (int) $runtime->query('SELECT COUNT(*) FROM coastmark_time_export_receipts')
            ->fetchColumn() === 2);
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
    }
}
if ($fatal instanceof Throwable) {
    fwrite(STDERR, 'Coastmark v3 MySQL fixture failed: ' . $fatal::class . ': ' . $fatal->getMessage() . "\n");
    $failures++;
}
echo "Coastmark approved-time v3 MySQL: {$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
