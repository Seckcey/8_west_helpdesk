<?php
/**
 * Disposable-MySQL coverage for migration 022, atomic replay, and a real
 * competing-worker activation race.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);

require_once __DIR__ . '/../lib/managed_customer_activation.php';

$activationMysqlChecks = 0;
$activationMysqlFailures = 0;

function activation_mysql_check(bool $condition, string $message): void
{
    global $activationMysqlChecks, $activationMysqlFailures;
    $activationMysqlChecks++;
    if ($condition) {
        echo "ok {$activationMysqlChecks} - {$message}\n";
        return;
    }
    $activationMysqlFailures++;
    echo "FAIL {$activationMysqlChecks} - {$message}\n";
}

/** @param class-string<Throwable> $class */
function activation_mysql_refuses(string $class, callable $operation, string $message): void
{
    try {
        $operation();
        activation_mysql_check(false, $message);
    } catch (Throwable $error) {
        activation_mysql_check($error instanceof $class || $error instanceof PDOException, $message);
    }
}

/** @return list<string> */
function activation_mysql_statements(string $sql): array
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

function activation_mysql_execute_sql(PDO $pdo, string $sql): void
{
    foreach (activation_mysql_statements($sql) as $statement) {
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

function activation_mysql_execute_file(PDO $pdo, string $path): void
{
    $sql = file_get_contents($path);
    if (!is_string($sql)) throw new RuntimeException("Cannot read {$path}");
    activation_mysql_execute_sql($pdo, $sql);
}

/** Exact current canonical schema with only the additive migration-022 block removed. */
function activation_mysql_pre022_schema(): string
{
    $sql = file_get_contents(__DIR__ . '/../db/schema.sql');
    if (!is_string($sql)) throw new RuntimeException('Cannot read canonical schema.');
    $start = strpos(
        $sql,
        '-- Atomic managed-customer portal/report activation receipts (migration 022)',
    );
    $end = $start === false
        ? false
        : strpos($sql, '-- Versioned service-goal policies', $start);
    if ($start === false || $end === false || $end <= $start) {
        throw new RuntimeException('Cannot isolate the exact pre-022 schema fixture.');
    }
    return substr($sql, 0, $start) . substr($sql, $end);
}

function activation_mysql_connection(string $database): PDO
{
    $host = getenv('SAFEHARBOR_ACTIVATION_TEST_HOST') ?: '127.0.0.1';
    $port = getenv('SAFEHARBOR_ACTIVATION_TEST_PORT') ?: '3306';
    $user = getenv('SAFEHARBOR_ACTIVATION_TEST_USER') ?: 'root';
    $pass = getenv('SAFEHARBOR_ACTIVATION_TEST_PASS') ?: '';
    $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
    if ($database !== '') $dsn .= ';dbname=' . $database;
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}

/** @return array<string,mixed> */
function activation_mysql_config(string $customerId): array
{
    return [
        'enabled' => true,
        'canary_only' => true,
        'customer_ids' => [$customerId],
        'tenant_actors' => ['provider-one' => 101],
        'batch_size' => 5,
        'schedule_timezone' => 'America/Los_Angeles',
        'delivery_weekday' => 3,
        'delivery_local_time' => '09:00:00',
    ];
}

/** @return array<string,mixed> */
function activation_mysql_evidence(string $customerId, string $tenantKey, string $tenantSlug): array
{
    return [
        'schema_version' => 2,
        'customer_id' => $customerId,
        'source_version' => 1,
        'customer_receipt_id' => hash('sha256', 'receipt:' . $customerId),
        'tenant_key' => $tenantKey,
        'tenant_slug' => $tenantSlug,
        'contact_version' => 1,
        'recipient_email' => $tenantSlug . '@example.test',
        'generated_at_db' => '2026-08-30 16:00:00',
        'request_nonce_sha256' => str_repeat('a', 64),
        'response_sha256' => str_repeat('b', 64),
    ];
}

/** @return array<string,mixed> */
function activation_mysql_report_config(string $customerId, int $clientId, string $recipient): array
{
    return [
        'generation_enabled' => false,
        'delivery_enabled' => false,
        'canary_only' => true,
        'graph_sender' => '',
        'schedule_keys' => ['managed-weekly:' . $customerId],
        'tenant_slugs' => ['provider-one'],
        'client_keys' => ['safeharbor-client:' . $clientId],
        'recipient_emails' => [$recipient],
        'lease_seconds' => 120,
    ];
}

/** @return array<string,mixed> */
function activation_mysql_candidate(PDO $pdo, string $customerId): array
{
    $rows = managed_customer_activation_candidates($pdo, activation_mysql_config($customerId));
    if (count($rows) !== 1) throw new RuntimeException('MySQL activation candidate missing.');
    return $rows[0];
}

if (($argv[1] ?? '') === 'race-child') {
    $database = (string)($argv[2] ?? '');
    $delay = (string)($argv[3] ?? '0') === '1';
    $customerId = '11111111-1111-4111-8111-111111111111';
    try {
        $pdo = activation_mysql_connection($database);
        $result = managed_customer_activation_apply(
            $pdo,
            activation_mysql_candidate($pdo, $customerId),
            activation_mysql_evidence($customerId, 'ewid-t91', 'managed-one'),
            101,
            activation_mysql_config($customerId),
            activation_mysql_report_config($customerId, 11, 'managed-one@example.test'),
            $delay ? static function (string $stage): void {
                if ($stage === 'after_portal') usleep(700_000);
            } : null,
        );
        echo json_encode($result, JSON_THROW_ON_ERROR) . "\n";
        exit(0);
    } catch (Throwable $error) {
        fwrite(STDERR, $error::class . ':' . $error->getMessage() . "\n");
        exit(1);
    }
}

if (getenv('SAFEHARBOR_ACTIVATION_TEST_DISPOSABLE_SERVER') !== '1') {
    fwrite(STDERR, "Set SAFEHARBOR_ACTIVATION_TEST_DISPOSABLE_SERVER=1 for a disposable MySQL server.\n");
    exit(2);
}
$base = getenv('SAFEHARBOR_ACTIVATION_TEST_DB') ?: 'safeharbor_activation_test';
if (preg_match('/\Asafeharbor_activation_test(?:_[a-z0-9_]+)?\z/D', $base) !== 1) {
    fwrite(STDERR, "Refusing destructive test database base.\n");
    exit(2);
}
$database = $base . '_' . bin2hex(random_bytes(4));
$admin = activation_mysql_connection('');
$quotedDatabase = '`' . str_replace('`', '``', $database) . '`';
$admin->exec("CREATE DATABASE {$quotedDatabase} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

try {
    $pdo = activation_mysql_connection($database);
    activation_mysql_execute_sql($pdo, activation_mysql_pre022_schema());
    activation_mysql_check(
        (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema=DATABASE()
            AND table_name='managed_customer_activation_receipts'")->fetchColumn() === 0,
        'pre-022 fixture has no activation receipt table or guards',
    );
    $migration = __DIR__ . '/../db/migrations/022_managed_customer_activation.sql';
    try {
        activation_mysql_execute_file($pdo, $migration);
    } catch (Throwable $error) {
        $diagnostic = $pdo->query(
            'SELECT @mc_activation_table_ok AS table_ok,
                    @mc_activation_columns_ok AS columns_ok,
                    @mc_activation_indexes_ok AS indexes_ok,
                    @mc_activation_fks_ok AS fks_ok,
                    @mc_activation_checks_ok AS checks_ok,
                    @mc_activation_swaps_ok AS swaps_ok,
                    @mc_activation_permanent_ok AS permanent_ok'
        )->fetch(PDO::FETCH_ASSOC);
        throw new RuntimeException(
            'Migration 022 preflight diagnostic: '
            . json_encode($diagnostic, JSON_THROW_ON_ERROR),
            0,
            $error,
        );
    }
    $pdo->exec('ALTER TABLE managed_customer_activation_receipts
        ADD CONSTRAINT ck_mc_activation_unexpected_drift CHECK (1 = 1) ENFORCED');
    activation_mysql_refuses(
        PDOException::class,
        static fn() => activation_mysql_execute_file($pdo, $migration),
        'migration replay refuses an unexpected extra check before trigger replacement',
    );
    activation_mysql_check(
        (int)$pdo->query("SELECT COUNT(*) FROM information_schema.triggers
          WHERE trigger_schema=DATABASE()
            AND event_object_table='managed_customer_activation_receipts'
            AND trigger_name IN ('trg_mc_activation_before_insert',
                                 'trg_mc_activation_no_update',
                                 'trg_mc_activation_no_delete')")->fetchColumn() === 3
        && (int)$pdo->query("SELECT COUNT(*) FROM information_schema.triggers
          WHERE trigger_schema=DATABASE()
            AND trigger_name LIKE 'trg_mc_activation_swap_%'")->fetchColumn() === 0,
        'failed drift preflight leaves permanent guards intact and installs no swaps',
    );
    $pdo->exec('ALTER TABLE managed_customer_activation_receipts
        DROP CHECK ck_mc_activation_unexpected_drift');
    activation_mysql_execute_file($pdo, $migration);
    activation_mysql_check(
        (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema=DATABASE()
            AND table_name='managed_customer_activation_receipts'")->fetchColumn() === 1,
        'migration 022 creates and replays the receipt table',
    );
    activation_mysql_check(
        (int)$pdo->query("SELECT COUNT(*) FROM information_schema.triggers
          WHERE trigger_schema=DATABASE()
            AND trigger_name IN ('trg_mc_activation_before_insert',
                                 'trg_mc_activation_no_update',
                                 'trg_mc_activation_no_delete')")->fetchColumn() === 3,
        'migration 022 leaves all permanent receipt guards',
    );
    activation_mysql_check(
        (int)$pdo->query("SELECT COUNT(*) FROM information_schema.triggers
          WHERE trigger_schema=DATABASE()
            AND trigger_name LIKE 'trg_mc_activation_swap_%'")->fetchColumn() === 0,
        'migration 022 removes temporary blockers only after verification',
    );
    activation_mysql_check(
        (int)$pdo->query("SELECT COUNT(*) FROM information_schema.table_constraints
          WHERE constraint_schema=DATABASE()
            AND table_name='managed_customer_activation_receipts'
            AND constraint_name='ck_mc_activation_install_lock'")->fetchColumn() === 0,
        'migration 022 removes the fresh-install lock only after permanent guards verify',
    );

    // Simulate interruption after a fail-closed blocker was installed and one
    // permanent body was removed. No application write may pass, and exact
    // replay must restore all permanent guards before removing the blocker.
    $pdo->exec('CREATE TRIGGER trg_mc_activation_swap_insert
        BEFORE INSERT ON managed_customer_activation_receipts FOR EACH ROW
        SIGNAL SQLSTATE \'45000\'
          SET MESSAGE_TEXT = \'managed customer activation migration is incomplete\'');
    $pdo->exec('DROP TRIGGER trg_mc_activation_before_insert');
    activation_mysql_refuses(
        PDOException::class,
        static fn() => $pdo->exec("INSERT INTO managed_customer_activation_receipts
            (tenant_id,client_id,source_binding_id,customer_id,source_version,
             customer_receipt_id,id_tenant_key,identity_tenant_slug,contact_version,
             portal_binding_id,schedule_key,prepared_schedule_version_id,
             active_schedule_version_id,actor_user_id,id_response_sha256,
             recipient_sha256,evidence_sha256)
            VALUES (1,1,1,'11111111-1111-4111-8111-111111111111',1,
                    '" . str_repeat('0', 64) . "','ewid-t1','blocked',1,1,
                    'managed-weekly:11111111-1111-4111-8111-111111111111',
                    1,2,1,'" . str_repeat('1', 64) . "','" . str_repeat('2', 64)
                    . "','" . str_repeat('3', 64) . "')"),
        'interrupted migration blocker refuses receipt insert',
    );
    activation_mysql_execute_file($pdo, $migration);
    activation_mysql_check(
        (int)$pdo->query("SELECT COUNT(*) FROM information_schema.triggers
          WHERE trigger_schema=DATABASE()
            AND trigger_name IN ('trg_mc_activation_before_insert',
                                 'trg_mc_activation_no_update',
                                 'trg_mc_activation_no_delete')")->fetchColumn() === 3
        && (int)$pdo->query("SELECT COUNT(*) FROM information_schema.triggers
          WHERE trigger_schema=DATABASE()
            AND trigger_name LIKE 'trg_mc_activation_swap_%'")->fetchColumn() === 0,
        'migration replay recovers interrupted permanent guards exactly',
    );

    $pdo->exec("INSERT INTO tenants (id,name,slug) VALUES
        (1,'Provider One','provider-one'),(2,'Provider Two','provider-two')");
    $pdo->exec("INSERT INTO clients (id,tenant_id,name) VALUES
        (11,1,'Managed One'),(12,1,'Managed Two'),(21,2,'Cross Tenant')");
    $pdo->exec("INSERT INTO users
        (id,tenant_id,email,password_hash,full_name,initials,role,is_active) VALUES
        (101,1,'owner@example.test','','Owner','O','owner',1),
        (102,1,'tech@example.test','','Tech','T','tech',1),
        (201,2,'owner2@example.test','','Owner Two','O2','owner',1)");
    $pdo->exec("INSERT INTO suite_customer_sync_bindings
        (id,tenant_id,customer_id,client_id,source_version,display_name,status,
         last_event_id,last_occurred_at,last_request_sha256) VALUES
        (501,1,'11111111-1111-4111-8111-111111111111',11,1,'Managed One','active',
         'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',UTC_TIMESTAMP(),'" . str_repeat('1', 64) . "'),
        (502,1,'22222222-2222-4222-8222-222222222222',12,1,'Managed Two','active',
         'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',UTC_TIMESTAMP(),'" . str_repeat('2', 64) . "')");
    $definition = $pdo->prepare(
        'INSERT INTO business_report_definition_versions
            (tenant_id,definition_key,version_no,report_type,contract_json,
             contract_sha256,created_by_user_id,reason) VALUES (?,?,?,?,?,?,?,?)'
    );
    $definition->execute([
        1, BUSINESS_REPORT_DEFINITION_KEY, 1, BUSINESS_REPORT_TYPE,
        business_report_contract_json(1), business_report_contract_sha256(1), 101, 'v1',
    ]);
    $definition->execute([
        1, BUSINESS_REPORT_DEFINITION_KEY, 2, BUSINESS_REPORT_TYPE,
        business_report_contract_json(2), business_report_contract_sha256(2), 101, 'v2',
    ]);

    $commandA = [PHP_BINARY, __FILE__, 'race-child', $database, '1'];
    $commandB = [PHP_BINARY, __FILE__, 'race-child', $database, '0'];
    $pipesA = [];
    $pipesB = [];
    $processA = proc_open($commandA, [1 => ['pipe','w'], 2 => ['pipe','w']], $pipesA);
    usleep(100_000);
    $processB = proc_open($commandB, [1 => ['pipe','w'], 2 => ['pipe','w']], $pipesB);
    if (!is_resource($processA) || !is_resource($processB)) {
        throw new RuntimeException('Could not start activation race workers.');
    }
    $stdoutA = stream_get_contents($pipesA[1]);
    $stderrA = stream_get_contents($pipesA[2]);
    $stdoutB = stream_get_contents($pipesB[1]);
    $stderrB = stream_get_contents($pipesB[2]);
    fclose($pipesA[1]); fclose($pipesA[2]);
    fclose($pipesB[1]); fclose($pipesB[2]);
    $exitA = proc_close($processA);
    $exitB = proc_close($processB);
    $raceA = json_decode(trim((string)$stdoutA), true);
    $raceB = json_decode(trim((string)$stdoutB), true);
    $actions = is_array($raceA) && is_array($raceB)
        ? [(string)($raceA['action'] ?? ''), (string)($raceB['action'] ?? '')]
        : [];
    sort($actions);
    activation_mysql_check(
        $exitA === 0 && $exitB === 0 && $actions === ['activated','replayed'],
        'two real workers serialize to one activation and one replay'
            . (($stderrA . $stderrB) === '' ? '' : ' (worker error)'),
    );
    activation_mysql_check(
        (int)$pdo->query('SELECT COUNT(*) FROM managed_customer_activation_receipts')->fetchColumn() === 1
        && (int)$pdo->query("SELECT COUNT(*) FROM customer_portal_bindings WHERE client_id=11")->fetchColumn() === 1
        && (int)$pdo->query("SELECT COUNT(*) FROM business_report_schedule_versions WHERE client_id=11")->fetchColumn() === 2,
        'concurrent workers leave one receipt, one portal binding, and one schedule pair',
    );

    activation_mysql_refuses(
        PDOException::class,
        static fn() => $pdo->exec("UPDATE managed_customer_activation_receipts SET contact_version=2"),
        'database guard rejects receipt update',
    );
    activation_mysql_refuses(
        PDOException::class,
        static fn() => $pdo->exec('DELETE FROM managed_customer_activation_receipts'),
        'database guard rejects receipt delete',
    );

    $customerTwo = '22222222-2222-4222-8222-222222222222';
    $candidateTwo = activation_mysql_candidate($pdo, $customerTwo);
    $configTwo = activation_mysql_config($customerTwo);
    $evidenceTwo = activation_mysql_evidence($customerTwo, 'ewid-t92', 'managed-two');
    $reportTwo = activation_mysql_report_config($customerTwo, 12, 'managed-two@example.test');
    activation_mysql_refuses(
        RuntimeException::class,
        static fn() => managed_customer_activation_apply(
            $pdo,
            $candidateTwo,
            $evidenceTwo,
            101,
            $configTwo,
            $reportTwo,
            static function (string $stage): void {
                if ($stage === 'after_schedule_prepared') throw new RuntimeException('crash');
            },
        ),
        'injected MySQL partial crash is observed',
    );
    activation_mysql_check(
        (int)$pdo->query("SELECT COUNT(*) FROM customer_portal_bindings WHERE client_id=12")->fetchColumn() === 0
        && (int)$pdo->query("SELECT COUNT(*) FROM business_report_schedule_versions WHERE client_id=12")->fetchColumn() === 0
        && (int)$pdo->query("SELECT COUNT(*) FROM managed_customer_activation_receipts WHERE client_id=12")->fetchColumn() === 0,
        'MySQL partial crash rolls back every activation write',
    );
    $afterCrash = managed_customer_activation_apply(
        $pdo, $candidateTwo, $evidenceTwo, 101, $configTwo, $reportTwo,
    );
    activation_mysql_check(
        ($afterCrash['action'] ?? null) === 'activated',
        'MySQL replay after partial crash activates exactly once',
    );

    if ($activationMysqlFailures > 0) {
        fwrite(STDERR, "managed_customer_activation_mysql_test: {$activationMysqlFailures} failure(s) / {$activationMysqlChecks} checks\n");
        exit(1);
    }
    echo "managed_customer_activation_mysql_test: {$activationMysqlChecks} checks\n";
} finally {
    $admin->exec("DROP DATABASE IF EXISTS {$quotedDatabase}");
}
