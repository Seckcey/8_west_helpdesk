<?php
/** Standalone MySQL 8 proof for migrations 017/019 and ID contact evidence guards. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);

require_once __DIR__ . '/../lib/business_reports.php';

/** Isolated second connection used only by the deterministic lock race below. */
function id_mysql_snapshot_worker(): void
{
    $host = getenv('SAFEHARBOR_ID_REPORT_TEST_HOST');
    $port = getenv('SAFEHARBOR_ID_REPORT_TEST_PORT');
    $database = getenv('SAFEHARBOR_ID_REPORT_TEST_DB_EXACT');
    $user = getenv('SAFEHARBOR_ID_REPORT_TEST_RUNTIME_USER');
    $pass = getenv('SAFEHARBOR_ID_REPORT_TEST_RUNTIME_PASS');
    $scheduleId = getenv('SAFEHARBOR_ID_REPORT_TEST_SCHEDULE_ID');
    $readyFile = getenv('SAFEHARBOR_ID_REPORT_TEST_READY_FILE');
    $readyDirectory = is_string($readyFile) ? realpath(dirname($readyFile)) : false;
    $temporaryDirectory = realpath(sys_get_temp_dir());
    $inputRefusal = null;
    foreach ([
        'HOST' => !is_string($host) || !in_array($host, ['127.0.0.1', 'localhost', '::1'], true),
        'PORT' => !is_string($port) || preg_match('/\A[0-9]{1,5}\z/D', $port) !== 1
            || (int)$port < 1 || (int)$port > 65535,
        'DATABASE' => !is_string($database)
            || preg_match('/\Asafeharbor_id_report_test(?:_[a-z0-9_]+)?_[0-9a-f]{12}\z/D', (string)$database) !== 1,
        'USER' => !is_string($user)
            || preg_match('/\Aid_report_[0-9a-f]{12}\z/D', (string)$user) !== 1,
        'PASSWORD' => !is_string($pass)
            || preg_match('/\A[0-9a-f]{48}\z/D', (string)$pass) !== 1,
        'SCHEDULE' => !is_string($scheduleId)
            || preg_match('/\A[1-9][0-9]{0,9}\z/D', (string)$scheduleId) !== 1,
        'READY_NAME' => !is_string($readyFile)
            || preg_match(
                '/\Asafeharbor-id-report-race-[0-9a-f]{12}\.ready\z/D',
                basename((string)$readyFile),
            ) !== 1,
        'READY_DIRECTORY' => !is_string($readyDirectory)
            || !is_string($temporaryDirectory)
            || strcasecmp((string)$readyDirectory, (string)$temporaryDirectory) !== 0,
    ] as $inputName => $refused) {
        if ($refused) {
            $inputRefusal = $inputName;
            break;
        }
    }
    if (is_string($inputRefusal)) {
        fwrite(STDERR, "WORKER_INPUT_REFUSED_{$inputRefusal}\n");
        exit(2);
    }
    register_shutdown_function(static function () use ($readyFile): void {
        if (is_file($readyFile)) @unlink($readyFile);
    });

    $pdo = null;
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
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = 5');
        $pdo->beginTransaction();
        if (file_put_contents($readyFile, 'ready', LOCK_EX) !== 5) {
            throw new RuntimeException('Cannot create race readiness marker.');
        }
        fwrite(STDOUT, "READY\n");
        fflush(STDOUT);
        $insert = $pdo->prepare(
            'INSERT INTO business_report_id_contact_snapshots
                (tenant_id, schedule_version_id, id_tenant_key,
                 contact_version, recipient_email, response_generated_at,
                 request_nonce_sha256, response_sha256,
                 created_by_user_id, reason)
             VALUES (1, ?, ?, 3, ?, ?, ?, ?, 101, ?)'
        );
        $insert->execute([
            (int)$scheduleId,
            'ewid-t1',
            'race-b@example.test',
            '2026-08-27 12:00:00',
            str_repeat('b', 64),
            str_repeat('c', 64),
            'two-connection conflict candidate',
        ]);
        $pdo->commit();
        fwrite(STDOUT, "COMMITTED\n");
        exit(0);
    } catch (PDOException $error) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
        $sqlState = is_array($error->errorInfo) ? (string)($error->errorInfo[0] ?? '') : '';
        if ($sqlState === '45000'
            && str_contains(
                strtolower($error->getMessage()),
                'contact version cannot name different recipients',
            )
        ) {
            fwrite(STDOUT, "CONFLICT\n");
            exit(0);
        }
        fwrite(STDERR, "WORKER_DATABASE_REJECTED\n");
        exit(1);
    } catch (Throwable) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
        fwrite(STDERR, "WORKER_FAILED\n");
        exit(1);
    }
}

/** Isolated second connection proving cross-table ID-key serialization. */
function id_mysql_binding_worker(): void
{
    $host = getenv('SAFEHARBOR_ID_REPORT_TEST_HOST');
    $port = getenv('SAFEHARBOR_ID_REPORT_TEST_PORT');
    $database = getenv('SAFEHARBOR_ID_REPORT_TEST_DB_EXACT');
    $user = getenv('SAFEHARBOR_ID_REPORT_TEST_RUNTIME_USER');
    $pass = getenv('SAFEHARBOR_ID_REPORT_TEST_RUNTIME_PASS');
    $readyFile = getenv('SAFEHARBOR_ID_REPORT_TEST_READY_FILE');
    $readyDirectory = is_string($readyFile) ? realpath(dirname($readyFile)) : false;
    $temporaryDirectory = realpath(sys_get_temp_dir());
    if (!is_string($host) || !in_array($host, ['127.0.0.1', 'localhost', '::1'], true)
        || !is_string($port) || preg_match('/\A[0-9]{1,5}\z/D', $port) !== 1
        || (int)$port < 1 || (int)$port > 65535
        || !is_string($database)
        || preg_match('/\Asafeharbor_id_report_test(?:_[a-z0-9_]+)?_[0-9a-f]{12}\z/D', $database) !== 1
        || !is_string($user) || preg_match('/\Aid_report_[0-9a-f]{12}\z/D', $user) !== 1
        || !is_string($pass) || preg_match('/\A[0-9a-f]{48}\z/D', $pass) !== 1
        || !is_string($readyFile)
        || preg_match('/\Asafeharbor-id-binding-race-[0-9a-f]{12}\.ready\z/D', basename($readyFile)) !== 1
        || !is_string($readyDirectory) || !is_string($temporaryDirectory)
        || strcasecmp($readyDirectory, $temporaryDirectory) !== 0
    ) {
        fwrite(STDERR, "BINDING_WORKER_INPUT_REFUSED\n");
        exit(2);
    }
    register_shutdown_function(static function () use ($readyFile): void {
        if (is_file($readyFile)) @unlink($readyFile);
    });

    $pdo = null;
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
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = 5');
        $pdo->beginTransaction();
        if (file_put_contents($readyFile, 'ready', LOCK_EX) !== 5) {
            throw new RuntimeException('Cannot create binding-race readiness marker.');
        }
        fwrite(STDOUT, "READY\n");
        fflush(STDOUT);
        $pdo->exec("INSERT INTO business_report_id_tenant_bindings
            (tenant_id,id_tenant_key,id_tenant_slug,created_by_user_id,reason)
            VALUES (2,'ewid-t7','two',201,'concurrent tenant claimant')");
        $pdo->commit();
        fwrite(STDOUT, "COMMITTED\n");
        exit(0);
    } catch (PDOException $error) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
        $sqlState = is_array($error->errorInfo) ? (string)($error->errorInfo[0] ?? '') : '';
        if ($sqlState === '45000'
            && str_contains(strtolower($error->getMessage()), 'already bound at client scope')
        ) {
            fwrite(STDOUT, "CONFLICT\n");
            exit(0);
        }
        fwrite(STDERR, "BINDING_WORKER_DATABASE_REJECTED\n");
        exit(1);
    } catch (Throwable) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
        fwrite(STDERR, "BINDING_WORKER_FAILED\n");
        exit(1);
    }
}

if (getenv('SAFEHARBOR_ID_REPORT_TEST_WORKER') === '1') {
    id_mysql_snapshot_worker();
}
if (getenv('SAFEHARBOR_ID_REPORT_TEST_BINDING_WORKER') === '1') {
    id_mysql_binding_worker();
}

$disposableServer = getenv('SAFEHARBOR_ID_REPORT_TEST_DISPOSABLE_SERVER');
if ($disposableServer !== '1') {
    fwrite(STDERR, "Refusing MySQL test without explicit disposable-server acknowledgement.\n");
    exit(2);
}
$databaseBase = getenv('SAFEHARBOR_ID_REPORT_TEST_DB');
if (!is_string($databaseBase)
    || preg_match('/\Asafeharbor_id_report_test(?:_[a-z0-9_]+)?\z/D', $databaseBase) !== 1
    || strlen($databaseBase) > 44
) {
    fwrite(STDERR, "Refusing destructive test database base.\n");
    exit(2);
}
$runId = bin2hex(random_bytes(6));
$database = $databaseBase . '_' . $runId;
$host = getenv('SAFEHARBOR_ID_REPORT_TEST_HOST') ?: '127.0.0.1';
$port = getenv('SAFEHARBOR_ID_REPORT_TEST_PORT') ?: '3306';
if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true)
    || preg_match('/\A[0-9]{1,5}\z/D', (string)$port) !== 1
    || (int)$port < 1
    || (int)$port > 65535
) {
    fwrite(STDERR, "Refusing a non-loopback or invalid disposable MySQL endpoint.\n");
    exit(2);
}
$user = getenv('SAFEHARBOR_ID_REPORT_TEST_USER') ?: 'root';
$pass = getenv('SAFEHARBOR_ID_REPORT_TEST_PASS') ?: '';
$serverDsn = "mysql:host={$host};port={$port};charset=utf8mb4";
$pdoOptions = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $server = new PDO($serverDsn, $user, $pass, $pdoOptions);
} catch (Throwable $error) {
    $databaseCode = $error instanceof PDOException && is_array($error->errorInfo)
        ? ' (SQLSTATE ' . (string)($error->errorInfo[0] ?? 'unknown')
            . ', driver ' . (string)($error->errorInfo[1] ?? 'unknown') . ')'
        : '';
    fwrite(STDERR, "ID report-contact MySQL fixture unavailable{$databaseCode}.\n");
    exit(2);
}

$idMysqlChecks = 0;
$idMysqlFailures = 0;

function id_mysql_check(bool $condition, string $message): void
{
    global $idMysqlChecks, $idMysqlFailures;
    $idMysqlChecks++;
    if ($condition) {
        echo "ok {$idMysqlChecks} - {$message}\n";
        return;
    }
    $idMysqlFailures++;
    echo "FAIL {$idMysqlChecks} - {$message}\n";
}

/** @param class-string<Throwable> $expected */
function id_mysql_throws(string $expected, callable $operation, string $message): void
{
    try {
        $operation();
        id_mysql_check(false, $message);
    } catch (Throwable $error) {
        id_mysql_check($error instanceof $expected, $message);
    }
}

function id_mysql_rejects_with_signal(
    callable $operation,
    string $expectedFragment,
    string $message,
): void {
    try {
        $operation();
        id_mysql_check(false, $message);
    } catch (PDOException $error) {
        $sqlState = is_array($error->errorInfo) ? (string)($error->errorInfo[0] ?? '') : '';
        id_mysql_check(
            $sqlState === '45000'
                && str_contains(strtolower($error->getMessage()), strtolower($expectedFragment)),
            $message,
        );
    } catch (Throwable) {
        id_mysql_check(false, $message);
    }
}

/** @return list<string> */
function id_mysql_statements(string $sql): array
{
    $delimiter = ';';
    $buffer = '';
    $statements = [];
    foreach (preg_split('/\R/', $sql) ?: [] as $line) {
        if (preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $match) === 1) {
            $pendingLines = preg_split('/\R/', $buffer) ?: [];
            $pendingSql = array_filter(
                $pendingLines,
                static fn(string $pending): bool => trim($pending) !== ''
                    && !str_starts_with(ltrim($pending), '--'),
            );
            if ($pendingSql !== []) throw new RuntimeException('DELIMITER changed with pending SQL.');
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

function id_mysql_execute_statement(PDO $pdo, string $statement): void
{
    $withoutComments = preg_replace('/\A(?:\s*--[^\n]*(?:\n|\z))+/', '', $statement) ?? $statement;
    if (preg_match('/^\s*SELECT\b/i', $withoutComments) === 1) {
        $result = $pdo->query($statement);
        $result->fetchAll();
        $result->closeCursor();
        return;
    }
    $pdo->exec($statement);
}

function id_mysql_execute_file(PDO $pdo, string $path): void
{
    $sql = file_get_contents($path);
    if (!is_string($sql)) throw new RuntimeException("Cannot read {$path}");
    foreach (id_mysql_statements($sql) as $statement) {
        id_mysql_execute_statement($pdo, $statement);
    }
}

function id_mysql_execute_until(PDO $pdo, string $path, string $lastStatementFragment): void
{
    $sql = file_get_contents($path);
    if (!is_string($sql)) throw new RuntimeException("Cannot read {$path}");
    foreach (id_mysql_statements($sql) as $statement) {
        $withoutComments = preg_replace('/\A(?:\s*--[^\n]*(?:\n|\z))+/', '', $statement) ?? $statement;
        id_mysql_execute_statement($pdo, $statement);
        if (str_contains($withoutComments, $lastStatementFragment)) return;
    }
    throw new RuntimeException('Requested migration interruption point was not found.');
}

/** @return array<string,mixed> */
function id_mysql_snapshot(int $version, string $email, string $byte): array
{
    return [
        'tenant_key' => 'ewid-t1',
        'tenant_slug' => 'one',
        'contact_version' => $version,
        'recipient_email' => $email,
        'generated_at' => '2026-08-27T12:00:00Z',
        'generated_at_db' => '2026-08-27 12:00:00',
        'request_nonce_sha256' => str_repeat($byte, 64),
        'response_sha256' => str_repeat(dechex((hexdec($byte) + 1) % 16), 64),
    ];
}

/** @return array<string,mixed> */
function id_mysql_client_snapshot(int $version, string $email, string $byte): array
{
    return array_replace(id_mysql_snapshot($version, $email, $byte), [
        'tenant_key' => 'ewid-t4',
        'tenant_slug' => 'customer-one',
    ]);
}

/** @return array<string,mixed> */
function id_mysql_report_config(string $scheduleKey, string $recipient): array
{
    return [
        'generation_enabled' => false,
        'delivery_enabled' => false,
        'canary_only' => true,
        'schedule_keys' => [$scheduleKey],
        'tenant_slugs' => ['one'],
        'client_keys' => ['safeharbor-client:11'],
        'recipient_emails' => [$recipient],
        'lease_seconds' => 120,
    ];
}

/** @return array<string,mixed> */
function id_mysql_prepare_unpinned_tenant_schedule(
    PDO $pdo,
    int $definitionId,
    string $scheduleKey,
    string $recipient,
): array {
    $pdo->beginTransaction();
    try {
        $scope = $pdo->prepare(
            'INSERT INTO business_report_contact_scope_bindings
                (tenant_id, schedule_key, contact_scope, created_by_user_id, reason)
             VALUES (1, ?, ?, 101, ?)'
        );
        $scope->execute([
            $scheduleKey,
            BUSINESS_REPORT_CONTACT_SCOPE_TENANT,
            'two-connection tenant-scope fixture',
        ]);

        $schedule = $pdo->prepare(
            "INSERT INTO business_report_schedule_versions
                (tenant_id, schedule_key, version_no, definition_version_id,
                 client_id, recipient_email, schedule_timezone,
                 delivery_weekday, delivery_local_time, canary, status,
                 created_by_user_id, reason)
             VALUES (1, ?, 1, ?, 11, ?, 'UTC', 1, '09:00:00', 1,
                     'disabled', 101, 'two-connection tenant-scope fixture')"
        );
        $schedule->execute([$scheduleKey, $definitionId, $recipient]);
        $scheduleId = (int)$pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }

    $select = $pdo->prepare(
        'SELECT * FROM business_report_schedule_versions WHERE id=?'
    );
    $select->execute([$scheduleId]);
    $row = $select->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new RuntimeException('Tenant-scope race fixture schedule was not stored.');
    }
    return $row;
}

/** @return array<string,string> */
function id_mysql_contact_scope_trigger_contract(PDO $pdo): array
{
    $names = [
        'trg_business_report_contact_scope_before_insert',
        'trg_business_report_contact_scope_no_update',
        'trg_business_report_contact_scope_no_delete',
        'trg_business_report_schedules_before_insert',
        'trg_business_report_id_binding_before_insert',
        'trg_business_report_id_snapshot_before_insert',
        'trg_br_id_client_binding_before_insert',
        'trg_br_id_client_binding_no_update',
        'trg_br_id_client_binding_no_delete',
        'trg_br_id_client_snapshot_before_insert',
        'trg_br_id_client_snapshot_no_update',
        'trg_br_id_client_snapshot_no_delete',
    ];
    $placeholders = implode(',', array_fill(0, count($names), '?'));
    $query = $pdo->prepare(
        "SELECT trigger_name, event_object_table, event_manipulation,
                action_timing, action_statement
           FROM information_schema.triggers
          WHERE trigger_schema=DATABASE() AND trigger_name IN ({$placeholders})"
    );
    $query->execute($names);
    $contract = [];
    foreach ($query->fetchAll(PDO::FETCH_NUM) as $row) {
        $statement = preg_replace('/\s+/', ' ', trim((string)$row[4]));
        $contract[(string)$row[0]] = implode('|', [
            (string)$row[1],
            (string)$row[2],
            (string)$row[3],
            (string)$statement,
        ]);
    }
    ksort($contract, SORT_STRING);
    return $contract;
}

/**
 * @param array<string,string> $workerEnvironment
 * @param array<string,mixed> $mainSnapshot
 * @return array{blocked:bool,exit_code:int,stdout:list<string>,stderr_empty:bool}
 */
function id_mysql_run_snapshot_race(
    PDO $runtime,
    int $mainScheduleId,
    array $mainSnapshot,
    array $workerEnvironment,
): array {
    $readyFile = $workerEnvironment['SAFEHARBOR_ID_REPORT_TEST_READY_FILE'] ?? '';
    if (!is_string($readyFile) || is_file($readyFile)) {
        throw new RuntimeException('Snapshot race readiness marker is unsafe.');
    }
    $process = null;
    $pipes = [];
    $mainCommitted = false;
    try {
        $runtime->beginTransaction();
        $insert = $runtime->prepare(
            'INSERT INTO business_report_id_contact_snapshots
                (tenant_id, schedule_version_id, id_tenant_key,
                 contact_version, recipient_email, response_generated_at,
                 request_nonce_sha256, response_sha256,
                 created_by_user_id, reason)
             VALUES (1, ?, ?, ?, ?, ?, ?, ?, 101, ?)'
        );
        $insert->execute([
            $mainScheduleId,
            $mainSnapshot['tenant_key'],
            $mainSnapshot['contact_version'],
            $mainSnapshot['recipient_email'],
            $mainSnapshot['generated_at_db'],
            $mainSnapshot['request_nonce_sha256'],
            $mainSnapshot['response_sha256'],
            'two-connection serialization winner',
        ]);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open([PHP_BINARY, __FILE__], $descriptors, $pipes, null, $workerEnvironment);
        if (!is_resource($process)) throw new RuntimeException('Cannot start snapshot race worker.');
        fclose($pipes[0]);
        unset($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $readyDeadline = microtime(true) + 5.0;
        $status = proc_get_status($process);
        while (!is_file($readyFile) && microtime(true) < $readyDeadline) {
            $status = proc_get_status($process);
            if (!$status['running']) break;
            usleep(20_000);
        }
        if (!is_file($readyFile)) {
            $workerMarker = trim(
                (string)stream_get_contents($pipes[1]) . ' ' . (string)stream_get_contents($pipes[2])
            );
            $workerMarker = substr(
                preg_replace('/[^A-Z0-9_ ]/', '?', strtoupper($workerMarker)) ?? '',
                0,
                80,
            );
            throw new RuntimeException(
                'Snapshot race worker did not become ready: ' . ($workerMarker ?: 'NO_MARKER'),
            );
        }

        // READY is emitted immediately before the worker's insert. Keeping the
        // binding lock briefly proves that the second process cannot finish.
        usleep(250_000);
        $status = proc_get_status($process);
        $blocked = (bool)$status['running'];
        if (!$blocked) throw new RuntimeException('Snapshot race worker did not block on the binding row.');

        $runtime->commit();
        $mainCommitted = true;

        $exitDeadline = microtime(true) + 6.0;
        while ($status['running'] && microtime(true) < $exitDeadline) {
            usleep(20_000);
            $status = proc_get_status($process);
        }
        if ($status['running']) throw new RuntimeException('Snapshot race worker timed out.');
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        $exitCode = (int)$status['exitcode'];
        fclose($pipes[1]);
        fclose($pipes[2]);
        $pipes = [];
        $closedExitCode = proc_close($process);
        $process = null;
        if ($exitCode < 0) $exitCode = $closedExitCode;

        return [
            'blocked' => $blocked,
            'exit_code' => $exitCode,
            'stdout' => preg_split('/\R/', trim($stdout)) ?: [],
            'stderr_empty' => trim($stderr) === '',
        ];
    } finally {
        if (is_resource($process)) {
            $status = proc_get_status($process);
            if ($status['running']) proc_terminate($process);
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) fclose($pipe);
            }
            proc_close($process);
        }
        if (!$mainCommitted && $runtime->inTransaction()) $runtime->rollBack();
        if (is_file($readyFile)) @unlink($readyFile);
    }
}

/**
 * @param array<string,string> $workerEnvironment
 * @return array{blocked:bool,exit_code:int,stdout:list<string>,stderr_empty:bool}
 */
function id_mysql_run_binding_race(PDO $runtime, array $workerEnvironment): array
{
    $readyFile = $workerEnvironment['SAFEHARBOR_ID_REPORT_TEST_READY_FILE'] ?? '';
    if (!is_string($readyFile) || is_file($readyFile)) {
        throw new RuntimeException('Binding race readiness marker is unsafe.');
    }
    $process = null;
    $pipes = [];
    $mainCommitted = false;
    try {
        $runtime->beginTransaction();
        $runtime->exec("INSERT INTO business_report_id_client_bindings
            (tenant_id,client_id,id_tenant_key,id_tenant_slug,created_by_user_id,reason)
            VALUES (1,13,'ewid-t7','customer-thirteen',101,'concurrent client claimant')");

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open([PHP_BINARY, __FILE__], $descriptors, $pipes, null, $workerEnvironment);
        if (!is_resource($process)) throw new RuntimeException('Cannot start binding race worker.');
        fclose($pipes[0]);
        unset($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $readyDeadline = microtime(true) + 5.0;
        $status = proc_get_status($process);
        while (!is_file($readyFile) && microtime(true) < $readyDeadline) {
            $status = proc_get_status($process);
            if (!$status['running']) break;
            usleep(20_000);
        }
        if (!is_file($readyFile)) {
            throw new RuntimeException('Binding race worker did not become ready.');
        }
        usleep(250_000);
        $status = proc_get_status($process);
        $blocked = (bool)$status['running'];
        if (!$blocked) throw new RuntimeException('Binding race worker did not block on the client claim.');

        $runtime->commit();
        $mainCommitted = true;
        $exitDeadline = microtime(true) + 6.0;
        while ($status['running'] && microtime(true) < $exitDeadline) {
            usleep(20_000);
            $status = proc_get_status($process);
        }
        if ($status['running']) throw new RuntimeException('Binding race worker timed out.');
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        $exitCode = (int)$status['exitcode'];
        fclose($pipes[1]);
        fclose($pipes[2]);
        $pipes = [];
        $closedExitCode = proc_close($process);
        $process = null;
        if ($exitCode < 0) $exitCode = $closedExitCode;
        return [
            'blocked' => $blocked,
            'exit_code' => $exitCode,
            'stdout' => preg_split('/\R/', trim($stdout)) ?: [],
            'stderr_empty' => trim($stderr) === '',
        ];
    } finally {
        if (is_resource($process)) {
            $status = proc_get_status($process);
            if ($status['running']) proc_terminate($process);
            foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
            proc_close($process);
        }
        if (!$mainCommitted && $runtime->inTransaction()) $runtime->rollBack();
        if (is_file($readyFile)) @unlink($readyFile);
    }
}

$quotedDatabase = '`' . str_replace('`', '``', $database) . '`';
$runtimeUser = 'id_report_' . $runId;
$runtimePass = bin2hex(random_bytes(24));
$databaseCreated = false;
$runtimeUserCreated = false;
$pdo = null;
$runtime = null;
$runError = null;
$cleanupFailures = [];

try {
    $server->exec(
        "CREATE DATABASE {$quotedDatabase} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    );
    $databaseCreated = true;
    $pdo = new PDO($serverDsn . ";dbname={$database}", $user, $pass, $pdoOptions);
    $pdo->exec("SET time_zone = '+00:00'");
    id_mysql_check(
        (string)$pdo->query('SELECT DATABASE()')->fetchColumn() === $database,
        'active connection is pinned to the random disposable database',
    );

    // Load core dependencies, then create the report subsystem from the
    // byte-frozen production migration 013 before applying candidate 017.
    // This catches first-creation DDL failures that current schema.sql would
    // otherwise mask with pre-existing candidate objects.
    $migration013 = __DIR__ . '/../db/migrations/013_business_reports.sql';
    $migration013Bytes = file_get_contents($migration013);
    $migration013Canonical = is_string($migration013Bytes)
        ? str_replace("\r\n", "\n", $migration013Bytes)
        : null;
    $migration013Blob = is_string($migration013Canonical)
        && !str_contains($migration013Canonical, "\r")
        ? sha1('blob ' . strlen($migration013Canonical) . "\0" . $migration013Canonical)
        : null;
    id_mysql_check(
        $migration013Blob === 'e6d8e9d0d0393281d03911ea10b595c9aa3e514f',
        'migration 013 tracked blob remains exact while migration 017 stays isolated',
    );
    id_mysql_execute_file($pdo, __DIR__ . '/../db/schema.sql');
    $freshContactScopeTriggerContract = id_mysql_contact_scope_trigger_contract($pdo);
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    try {
        $pdo->exec(
            'DROP TABLE IF EXISTS
                business_report_id_client_contact_snapshots,
                business_report_id_client_bindings,
                business_report_id_contact_snapshots,
                business_report_id_tenant_bindings,
                business_report_contact_scope_bindings,
                business_report_delivery_attempts,
                business_report_deliveries,
                business_report_archives,
                business_report_schedule_versions,
                business_report_definition_versions'
        );
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
    id_mysql_execute_file($pdo, $migration013);
    id_mysql_check(
        (int)$pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema=DATABASE() AND table_name LIKE 'business_report_%'"
        )->fetchColumn() === 5
            && (int)$pdo->query(
                "SELECT COUNT(*) FROM information_schema.triggers
                  WHERE trigger_schema=DATABASE()
                    AND trigger_name LIKE 'trg_business_report_%'"
            )->fetchColumn() === 15,
        'true migration-013 baseline has five report tables and fifteen guards',
    );
    id_mysql_execute_file($pdo, __DIR__ . '/../db/migrations/017_id_report_contact_evidence.sql');

    // Prove the first installation inherits the original contact source even
    // when the latest logical version is an enable/disable transition with no
    // duplicate contact evidence.
    $pdo->exec("INSERT INTO tenants (id,name,slug) VALUES
        (97,'Backfill Tenant','backfill-tenant'),
        (98,'Backfill Manual','backfill-manual')");
    $pdo->exec("INSERT INTO clients (id,tenant_id,name) VALUES
        (97,97,'Backfill Tenant Client'),
        (98,98,'Backfill Manual Client')");
    $pdo->exec("INSERT INTO users
        (id,tenant_id,email,password_hash,full_name,initials,role,is_active) VALUES
        (970,97,'owner97@example.test','','Owner Ninety Seven','97','owner',1),
        (980,98,'owner98@example.test','','Owner Ninety Eight','98','owner',1)");
    $backfillTenantDefinition = business_report_publish_definition(
        $pdo,
        'backfill-tenant',
        970,
        'tenant-scope backfill definition',
    );
    $backfillManualDefinition = business_report_publish_definition(
        $pdo,
        'backfill-manual',
        980,
        'manual-scope backfill definition',
    );
    $pdo->exec("INSERT INTO business_report_id_tenant_bindings
        (tenant_id,id_tenant_key,id_tenant_slug,created_by_user_id,reason)
        VALUES (97,'ewid-t97','backfill-tenant',970,'tenant-scope backfill binding')");
    $backfillScheduleInsert = $pdo->prepare(
        "INSERT INTO business_report_schedule_versions
            (tenant_id,schedule_key,version_no,definition_version_id,client_id,
             recipient_email,schedule_timezone,delivery_weekday,delivery_local_time,
             canary,status,created_by_user_id,reason)
         VALUES (?,?,?,?,? ,?,'UTC',1,'09:00:00',1,?,?,?)"
    );
    $backfillScheduleInsert->execute([
        97,
        'backfill-tenant',
        1,
        (int)$backfillTenantDefinition['definition']['id'],
        97,
        'backfill-tenant@example.test',
        'disabled',
        970,
        'tenant-scope version one',
    ]);
    $backfillTenantScheduleId = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO business_report_id_contact_snapshots
        (tenant_id,schedule_version_id,id_tenant_key,contact_version,recipient_email,
         response_generated_at,request_nonce_sha256,response_sha256,created_by_user_id,reason)
        VALUES (97,{$backfillTenantScheduleId},'ewid-t97',1,'backfill-tenant@example.test',
         '2026-08-28 12:00:00','" . str_repeat('1', 64) . "','" . str_repeat('2', 64) . "',
         970,'tenant-scope version-one evidence')");
    foreach ([[2, 'active'], [3, 'disabled']] as [$versionNo, $status]) {
        $backfillScheduleInsert->execute([
            97,
            'backfill-tenant',
            $versionNo,
            (int)$backfillTenantDefinition['definition']['id'],
            97,
            'backfill-tenant@example.test',
            $status,
            970,
            "tenant-scope {$status} transition",
        ]);
    }
    $backfillScheduleInsert->execute([
        98,
        'backfill-manual',
        1,
        (int)$backfillManualDefinition['definition']['id'],
        98,
        'backfill-manual@example.test',
        'disabled',
        980,
        'manual-scope version one',
    ]);
    id_mysql_execute_file($pdo, __DIR__ . '/../db/migrations/019_client_report_contact_evidence.sql');
    $backfilledTenantScope = business_report_contact_scope_for_key(
        $pdo,
        97,
        'backfill-tenant',
    );
    $backfilledManualScope = business_report_contact_scope_for_key(
        $pdo,
        98,
        'backfill-manual',
    );
    id_mysql_check(
        is_array($backfilledTenantScope)
            && $backfilledTenantScope['scope'] === BUSINESS_REPORT_CONTACT_SCOPE_TENANT
            && (int)($backfilledTenantScope['evidence']['contact_version'] ?? 0) === 1
            && is_array($backfilledManualScope)
            && $backfilledManualScope['scope'] === BUSINESS_REPORT_CONTACT_SCOPE_MANUAL
            && $backfilledManualScope['evidence'] === null
            && $pdo->query(
                "SELECT CONCAT(schedule_key, ':', contact_scope)
                   FROM business_report_contact_scope_bindings
                  WHERE tenant_id IN (97,98) ORDER BY tenant_id"
            )->fetchAll(PDO::FETCH_COLUMN) === [
                'backfill-tenant:TENANT',
                'backfill-manual:MANUAL',
            ],
        'migration 019 backfills one original scope across complete transitioned schedule history',
    );
    id_mysql_check(
        count($freshContactScopeTriggerContract) === 12
            && id_mysql_contact_scope_trigger_contract($pdo) === $freshContactScopeTriggerContract,
        'fresh schema and migrations install the exact same twelve scope enforcement triggers',
    );

id_mysql_check(
    $pdo->query(
        "SELECT CONCAT(table_name, ':', COUNT(*))
           FROM information_schema.columns
          WHERE table_schema=DATABASE()
            AND table_name IN ('business_report_id_tenant_bindings','business_report_id_contact_snapshots')
          GROUP BY table_name ORDER BY table_name"
    )->fetchAll(PDO::FETCH_COLUMN) === [
        'business_report_id_contact_snapshots:12',
        'business_report_id_tenant_bindings:6',
    ],
    'fresh schema and migration 017 have exact table parity',
);
id_mysql_check(
    (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.triggers
          WHERE trigger_schema=DATABASE()
            AND trigger_name LIKE 'trg_business_report_id_%'"
    )->fetchColumn() === 6,
    'all six immutable binding and snapshot guards are installed',
);
id_mysql_check(
    (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.statistics
          WHERE table_schema=DATABASE()
            AND table_name IN ('business_report_id_tenant_bindings','business_report_id_contact_snapshots')"
    )->fetchColumn() === 19
        && (int)$pdo->query(
            "SELECT COUNT(*) FROM information_schema.table_constraints
              WHERE constraint_schema=DATABASE()
                AND table_name IN ('business_report_id_tenant_bindings','business_report_id_contact_snapshots')
                AND constraint_type='FOREIGN KEY'"
        )->fetchColumn() === 6
        && (int)$pdo->query(
            "SELECT COUNT(*) FROM information_schema.table_constraints
              WHERE constraint_schema=DATABASE()
                AND table_name IN ('business_report_id_tenant_bindings','business_report_id_contact_snapshots')
                AND constraint_type='CHECK'"
        )->fetchColumn() === 6,
    'fresh schema and replay retain exact index, foreign-key, and check counts',
);
id_mysql_check(
    $pdo->query(
        "SELECT CONCAT(table_name, ':', COUNT(*))
           FROM information_schema.columns
          WHERE table_schema=DATABASE()
            AND table_name IN (
              'business_report_id_client_bindings',
              'business_report_id_client_contact_snapshots')
          GROUP BY table_name ORDER BY table_name"
    )->fetchAll(PDO::FETCH_COLUMN) === [
        'business_report_id_client_bindings:7',
        'business_report_id_client_contact_snapshots:13',
    ],
    'fresh schema and migration 019 have exact client evidence table parity',
);
id_mysql_check(
    (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.triggers
          WHERE trigger_schema=DATABASE()
            AND trigger_name LIKE 'trg_br_id_client_%'"
    )->fetchColumn() === 6,
    'all six immutable client binding and snapshot guards are installed',
);
id_mysql_check(
    (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.statistics
          WHERE table_schema=DATABASE()
            AND table_name IN (
              'business_report_id_client_bindings',
              'business_report_id_client_contact_snapshots')"
    )->fetchColumn() === 22
        && (int)$pdo->query(
            "SELECT COUNT(*) FROM information_schema.table_constraints
              WHERE constraint_schema=DATABASE()
                AND table_name IN (
                  'business_report_id_client_bindings',
                  'business_report_id_client_contact_snapshots')
                AND constraint_type='FOREIGN KEY'"
        )->fetchColumn() === 8
        && (int)$pdo->query(
            "SELECT COUNT(*) FROM information_schema.table_constraints
              WHERE constraint_schema=DATABASE()
                AND table_name IN (
                  'business_report_id_client_bindings',
                  'business_report_id_client_contact_snapshots')
                AND constraint_type='CHECK'"
        )->fetchColumn() === 6,
    'client evidence schema has exact index, foreign-key, and check counts',
);
id_mysql_check(
    $pdo->query(
        "SELECT CONCAT(table_name, ':', COUNT(*))
           FROM information_schema.columns
          WHERE table_schema=DATABASE()
            AND table_name='business_report_contact_scope_bindings'
          GROUP BY table_name"
    )->fetchColumn() === 'business_report_contact_scope_bindings:6'
        && (int)$pdo->query(
            "SELECT COUNT(*) FROM information_schema.triggers
              WHERE trigger_schema=DATABASE()
                AND trigger_name LIKE 'trg_business_report_contact_scope_%'"
        )->fetchColumn() === 3,
    'fresh schema and migration 019 have exact immutable contact-scope parity',
);

$pdo->exec(
    'ALTER TABLE business_report_id_tenant_bindings
       DROP INDEX uq_business_report_id_binding_key,
       ADD KEY uq_business_report_id_binding_key (id_tenant_key)'
);
id_mysql_throws(
    PDOException::class,
    fn() => id_mysql_execute_file(
        $pdo,
        __DIR__ . '/../db/migrations/017_id_report_contact_evidence.sql',
    ),
    'migration replay refuses a same-arity index with weaker uniqueness',
);
$pdo->exec(
    'ALTER TABLE business_report_id_tenant_bindings
       DROP INDEX uq_business_report_id_binding_key,
       ADD UNIQUE KEY uq_business_report_id_binding_key (id_tenant_key)'
);
$pdo->exec(
    'ALTER TABLE business_report_id_tenant_bindings
       ALTER INDEX ix_business_report_id_binding_actor INVISIBLE'
);
id_mysql_throws(
    PDOException::class,
    fn() => id_mysql_execute_file(
        $pdo,
        __DIR__ . '/../db/migrations/017_id_report_contact_evidence.sql',
    ),
    'migration replay refuses an invisible required index',
);
$pdo->exec(
    'ALTER TABLE business_report_id_tenant_bindings
       ALTER INDEX ix_business_report_id_binding_actor VISIBLE'
);

$pdo->exec(
    'ALTER TABLE business_report_id_contact_snapshots
       DROP FOREIGN KEY fk_business_report_id_snapshot_tenant'
);
$pdo->exec(
    'ALTER TABLE business_report_id_contact_snapshots
       ADD CONSTRAINT fk_business_report_id_snapshot_tenant
         FOREIGN KEY (tenant_id) REFERENCES clients (id)'
);
id_mysql_throws(
    PDOException::class,
    fn() => id_mysql_execute_file(
        $pdo,
        __DIR__ . '/../db/migrations/017_id_report_contact_evidence.sql',
    ),
    'migration replay refuses a foreign key with a wrong reference target',
);
$pdo->exec(
    'ALTER TABLE business_report_id_contact_snapshots
       DROP FOREIGN KEY fk_business_report_id_snapshot_tenant'
);
$pdo->exec(
    'ALTER TABLE business_report_id_contact_snapshots
       ADD CONSTRAINT fk_business_report_id_snapshot_tenant
         FOREIGN KEY (tenant_id) REFERENCES tenants (id)'
);

$pdo->exec(
    'ALTER TABLE business_report_id_contact_snapshots
       ALTER CHECK ck_business_report_id_snapshot_version NOT ENFORCED'
);
id_mysql_throws(
    PDOException::class,
    fn() => id_mysql_execute_file(
        $pdo,
        __DIR__ . '/../db/migrations/017_id_report_contact_evidence.sql',
    ),
    'migration replay refuses a disabled check constraint',
);
$pdo->exec(
    'ALTER TABLE business_report_id_contact_snapshots
       ALTER CHECK ck_business_report_id_snapshot_version ENFORCED'
);
$pdo->exec(
    'ALTER TABLE business_report_id_contact_snapshots
       DROP CHECK ck_business_report_id_snapshot_version'
);
$pdo->exec(
    'ALTER TABLE business_report_id_contact_snapshots
       ADD CONSTRAINT ck_business_report_id_snapshot_version CHECK (contact_version >= 0)'
);
id_mysql_throws(
    PDOException::class,
    fn() => id_mysql_execute_file(
        $pdo,
        __DIR__ . '/../db/migrations/017_id_report_contact_evidence.sql',
    ),
    'migration replay refuses a weakened check definition',
);
$pdo->exec(
    'ALTER TABLE business_report_id_contact_snapshots
       DROP CHECK ck_business_report_id_snapshot_version'
);
$pdo->exec(
    'ALTER TABLE business_report_id_contact_snapshots
       ADD CONSTRAINT ck_business_report_id_snapshot_version CHECK (contact_version >= 1)'
);
id_mysql_execute_file($pdo, __DIR__ . '/../db/migrations/017_id_report_contact_evidence.sql');
id_mysql_check(
    (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.triggers
          WHERE trigger_schema=DATABASE()
            AND trigger_name LIKE 'trg_business_report_id_%'"
    )->fetchColumn() === 6,
    'exact shape restoration replays with six permanent guards and no swaps',
);

$migration019 = __DIR__ . '/../db/migrations/019_client_report_contact_evidence.sql';
$pdo->exec(
    'ALTER TABLE business_report_id_client_bindings
       DROP INDEX uq_br_id_client_binding_key,
       ADD KEY uq_br_id_client_binding_key (id_tenant_key)'
);
id_mysql_throws(
    PDOException::class,
    fn() => id_mysql_execute_file($pdo, $migration019),
    'migration 019 replay refuses a weakened client-binding unique index',
);
$pdo->exec(
    'ALTER TABLE business_report_id_client_bindings
       DROP INDEX uq_br_id_client_binding_key,
       ADD UNIQUE KEY uq_br_id_client_binding_key (id_tenant_key)'
);
$pdo->exec(
    'ALTER TABLE business_report_id_client_bindings
       ALTER INDEX ix_br_id_client_binding_actor INVISIBLE'
);
id_mysql_throws(
    PDOException::class,
    fn() => id_mysql_execute_file($pdo, $migration019),
    'migration 019 replay refuses an invisible client-binding index',
);
$pdo->exec(
    'ALTER TABLE business_report_id_client_bindings
       ALTER INDEX ix_br_id_client_binding_actor VISIBLE'
);
$pdo->exec(
    'ALTER TABLE business_report_id_client_contact_snapshots
       DROP FOREIGN KEY fk_br_id_client_snapshot_tenant'
);
$pdo->exec(
    'ALTER TABLE business_report_id_client_contact_snapshots
       ADD CONSTRAINT fk_br_id_client_snapshot_tenant
         FOREIGN KEY (tenant_id) REFERENCES clients (id)'
);
id_mysql_throws(
    PDOException::class,
    fn() => id_mysql_execute_file($pdo, $migration019),
    'migration 019 replay refuses a client snapshot foreign key with the wrong target',
);
$pdo->exec(
    'ALTER TABLE business_report_id_client_contact_snapshots
       DROP FOREIGN KEY fk_br_id_client_snapshot_tenant'
);
$pdo->exec(
    'ALTER TABLE business_report_id_client_contact_snapshots
       ADD CONSTRAINT fk_br_id_client_snapshot_tenant
         FOREIGN KEY (tenant_id) REFERENCES tenants (id)'
);
$pdo->exec(
    'ALTER TABLE business_report_id_client_contact_snapshots
       ALTER CHECK ck_br_id_client_snapshot_version NOT ENFORCED'
);
id_mysql_throws(
    PDOException::class,
    fn() => id_mysql_execute_file($pdo, $migration019),
    'migration 019 replay refuses a disabled client snapshot check',
);
$pdo->exec(
    'ALTER TABLE business_report_id_client_contact_snapshots
       ALTER CHECK ck_br_id_client_snapshot_version ENFORCED'
);
$pdo->exec(
    'ALTER TABLE business_report_id_client_contact_snapshots
       DROP CHECK ck_br_id_client_snapshot_version'
);
$pdo->exec(
    'ALTER TABLE business_report_id_client_contact_snapshots
       ADD CONSTRAINT ck_br_id_client_snapshot_version CHECK (contact_version >= 0)'
);
id_mysql_throws(
    PDOException::class,
    fn() => id_mysql_execute_file($pdo, $migration019),
    'migration 019 replay refuses a weakened client contact-version check',
);
$pdo->exec(
    'ALTER TABLE business_report_id_client_contact_snapshots
       DROP CHECK ck_br_id_client_snapshot_version'
);
$pdo->exec(
    'ALTER TABLE business_report_id_client_contact_snapshots
       ADD CONSTRAINT ck_br_id_client_snapshot_version CHECK (contact_version >= 1)'
);
$pdo->exec(
    'ALTER TABLE business_report_contact_scope_bindings
       ALTER INDEX ix_business_report_contact_scope_actor INVISIBLE'
);
id_mysql_throws(
    PDOException::class,
    fn() => id_mysql_execute_file($pdo, $migration019),
    'migration 019 replay refuses an invisible contact-scope index',
);
$pdo->exec(
    'ALTER TABLE business_report_contact_scope_bindings
       ALTER INDEX ix_business_report_contact_scope_actor VISIBLE'
);
id_mysql_execute_file($pdo, $migration019);
id_mysql_check(
    (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.triggers
          WHERE trigger_schema=DATABASE()
            AND trigger_name LIKE 'trg_br_id_client_%'"
    )->fetchColumn() === 6
        && (int)$pdo->query(
            "SELECT COUNT(*) FROM information_schema.triggers
              WHERE trigger_schema=DATABASE()
                AND trigger_name LIKE 'trg_business_report_contact_scope_%'"
        )->fetchColumn() === 3,
    'exact migration-019 shape restoration replays with client and scope guards',
);

$pdo->exec("INSERT INTO tenants (id,name,slug) VALUES (1,'Tenant One','one'),(2,'Tenant Two','two')");
$pdo->exec("INSERT INTO clients (id,tenant_id,name) VALUES
    (11,1,'Client One'),(12,1,'Client Twelve'),(13,1,'Client Thirteen'),(22,2,'Client Two')");
$pdo->exec("INSERT INTO users
    (id,tenant_id,email,password_hash,full_name,initials,role,is_active) VALUES
    (101,1,'owner1@example.test','','Owner One','O1','owner',1),
    (102,1,'admin1@example.test','','Admin One','A1','admin',1),
    (103,1,'tech1@example.test','','Tech One','T1','tech',1),
    (201,2,'owner2@example.test','','Owner Two','O2','owner',1)");
$definition = business_report_publish_definition($pdo, 'one', 101, 'ID report definition');

$prepared = business_report_prepare_schedule_from_id(
    $pdo, 'one', 'id-weekly', 11, (int)$definition['definition']['id'],
    id_mysql_snapshot(1, 'admin@example.test', '1'),
    'UTC', 1, '09:00:00', true, 101, 'ID-backed prepare',
);
id_mysql_check(
    $prepared['action'] === 'prepared'
        && $prepared['schedule']['status'] === 'disabled'
        && (int)$prepared['id_contact']['contact_version'] === 1
        && (int)$pdo->query(
            'SELECT COUNT(*) FROM business_report_id_tenant_bindings WHERE tenant_id=1'
        )->fetchColumn() === 1
        && (int)$pdo->query(
            'SELECT COUNT(*) FROM business_report_id_contact_snapshots WHERE tenant_id=1'
        )->fetchColumn() === 1,
    'runtime atomically creates one immutable binding and snapshot',
);
$replay = business_report_prepare_schedule_from_id(
    $pdo, 'one', 'id-weekly', 11, (int)$definition['definition']['id'],
    id_mysql_snapshot(1, 'admin@example.test', '3'),
    'UTC', 1, '09:00:00', true, 101, 'exact replay',
);
id_mysql_check(
    $replay['action'] === 'ignored'
        && (int)$replay['schedule']['id'] === (int)$prepared['schedule']['id']
        && (int)$pdo->query(
            'SELECT COUNT(*) FROM business_report_id_contact_snapshots WHERE tenant_id=1'
        )->fetchColumn() === 1,
    'exact prepare replay is idempotent even with a fresh request nonce',
);
id_mysql_throws(
    BusinessReportConflictException::class,
    fn() => business_report_prepare_schedule_from_id(
        $pdo, 'one', 'id-weekly', 11, (int)$definition['definition']['id'],
        id_mysql_snapshot(1, 'changed@example.test', '4'),
        'UTC', 1, '10:00:00', true, 101, 'conflicting same version',
    ),
    'same contact version with another address is refused',
);
$newer = business_report_prepare_schedule_from_id(
    $pdo, 'one', 'id-weekly', 11, (int)$definition['definition']['id'],
    id_mysql_snapshot(2, 'new-admin@example.test', '5'),
    'UTC', 1, '10:00:00', true, 102, 'version two',
);
id_mysql_check(
    (int)$newer['schedule']['version_no'] === 2
        && (int)$newer['id_contact']['contact_version'] === 2,
    'a newer ID contact creates the next disabled schedule version',
);
$tenantScopeEnabled = business_report_transition_schedule(
    $pdo, 'one', 'id-weekly', 2, 'active', 101, 'enable inherited tenant scope',
    id_mysql_report_config('id-weekly', 'new-admin@example.test'),
);
$tenantScopeDisabled = business_report_transition_schedule(
    $pdo, 'one', 'id-weekly', 3, 'disabled', 101, 'disable inherited tenant scope',
    id_mysql_report_config('id-weekly', 'new-admin@example.test'),
);
$tenantScope = business_report_contact_scope_for_key($pdo, 1, 'id-weekly');
id_mysql_check(
    (int)$tenantScopeEnabled['schedule']['version_no'] === 3
        && (int)$tenantScopeDisabled['schedule']['version_no'] === 4
        && is_array($tenantScope)
        && $tenantScope['scope'] === BUSINESS_REPORT_CONTACT_SCOPE_TENANT
        && (int)($tenantScope['evidence']['contact_version'] ?? 0) === 2,
    'tenant contact scope and latest evidence survive enable and disable versions',
);
id_mysql_throws(
    BusinessReportConflictException::class,
    fn() => business_report_prepare_schedule(
        $pdo, 'one', 'id-weekly', 11, (int)$definition['definition']['id'],
        'new-admin@example.test', 'UTC', 1, '11:00:00', true, 101, 'manual bypass',
    ),
    'tenant ID schedule history cannot be changed to manual',
);

$definitionTwo = business_report_publish_definition($pdo, 'two', 201, 'Tenant two definition');
$pdo->exec('DROP TRIGGER IF EXISTS test_id_snapshot_atomic_failure');
$pdo->exec(
    "CREATE TRIGGER test_id_snapshot_atomic_failure
     BEFORE INSERT ON business_report_id_contact_snapshots
     FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='test snapshot failure'"
);
$atomicFailure = false;
try {
    $tenantTwoSnapshot = array_replace(id_mysql_snapshot(1, 'two-admin@example.test', 'a'), [
        'tenant_key' => 'ewid-t2',
        'tenant_slug' => 'two',
    ]);
    business_report_prepare_schedule_from_id(
        $pdo, 'two', 'atomic-weekly', 22, (int)$definitionTwo['definition']['id'],
        $tenantTwoSnapshot, 'UTC', 1, '09:00:00', true, 201, 'atomic failure proof',
    );
} catch (Throwable $error) {
    $atomicFailure = $error instanceof PDOException;
} finally {
    $pdo->exec('DROP TRIGGER IF EXISTS test_id_snapshot_atomic_failure');
}
id_mysql_check(
    $atomicFailure
        && (int)$pdo->query(
            "SELECT COUNT(*) FROM business_report_schedule_versions
              WHERE tenant_id=2 AND schedule_key='atomic-weekly'"
        )->fetchColumn() === 0
        && (int)$pdo->query(
            "SELECT COUNT(*) FROM business_report_id_tenant_bindings WHERE tenant_id=2"
        )->fetchColumn() === 0
        && (int)$pdo->query(
            "SELECT COUNT(*) FROM business_report_contact_scope_bindings
              WHERE tenant_id=2 AND schedule_key='atomic-weekly'"
        )->fetchColumn() === 0,
    'snapshot failure rolls back scope, disabled schedule, and first tenant binding',
);
id_mysql_throws(
    BusinessReportConflictException::class,
    fn() => business_report_prepare_schedule_from_id(
        $pdo, 'one', 'id-weekly', 11, (int)$definition['definition']['id'],
        id_mysql_snapshot(1, 'admin@example.test', '7'),
        'UTC', 1, '11:00:00', true, 101, 'rollback',
    ),
    'contact version rollback is refused by the runtime',
);

$bindingId = 1;
$snapshotId = (int)$prepared['id_contact']['id'];
id_mysql_throws(
    PDOException::class,
    fn() => $pdo->exec("UPDATE business_report_id_tenant_bindings SET reason='changed' WHERE tenant_id={$bindingId}"),
    'database refuses binding updates',
);
id_mysql_throws(
    PDOException::class,
    fn() => $pdo->exec("DELETE FROM business_report_id_tenant_bindings WHERE tenant_id={$bindingId}"),
    'database refuses binding deletes',
);
id_mysql_throws(
    PDOException::class,
    fn() => $pdo->exec("UPDATE business_report_id_contact_snapshots SET reason='changed' WHERE id={$snapshotId}"),
    'database refuses snapshot updates',
);
id_mysql_throws(
    PDOException::class,
    fn() => $pdo->exec("DELETE FROM business_report_id_contact_snapshots WHERE id={$snapshotId}"),
    'database refuses snapshot deletes',
);
id_mysql_throws(
    PDOException::class,
    fn() => $pdo->exec("INSERT INTO business_report_id_tenant_bindings
        (tenant_id,id_tenant_key,id_tenant_slug,created_by_user_id,reason)
        VALUES (2,'ewid-t2','one',201,'wrong slug')"),
    'database refuses a binding whose stable ID slug does not match the local tenant',
);
id_mysql_throws(
    PDOException::class,
    fn() => $pdo->exec("INSERT INTO business_report_id_tenant_bindings
        (tenant_id,id_tenant_key,id_tenant_slug,created_by_user_id,reason)
        VALUES (2,'ewid-t4294967296','two',201,'out of range key')"),
    'database refuses an ID tenant key outside the producer contract range',
);

$clientPrepared = business_report_prepare_client_schedule_from_id(
    $pdo, 'one', 'client-id-weekly', 11, (int)$definition['definition']['id'],
    id_mysql_client_snapshot(1, 'customer-admin@example.test', '1'),
    'UTC', 1, '09:00:00', true, 101, 'client-scoped ID prepare',
);
id_mysql_check(
    $clientPrepared['action'] === 'prepared'
        && (int)$clientPrepared['schedule']['version_no'] === 1
        && (int)$clientPrepared['id_contact']['client_id'] === 11
        && (string)$clientPrepared['id_contact']['id_tenant_key'] === 'ewid-t4'
        && (int)$pdo->query('SELECT COUNT(*) FROM business_report_id_client_bindings')->fetchColumn() === 1
        && (int)$pdo->query('SELECT COUNT(*) FROM business_report_id_client_contact_snapshots')->fetchColumn() === 1,
    'client runtime atomically creates one exact binding, disabled schedule, and snapshot',
);
$clientReplay = business_report_prepare_client_schedule_from_id(
    $pdo, 'one', 'client-id-weekly', 11, (int)$definition['definition']['id'],
    id_mysql_client_snapshot(1, 'customer-admin@example.test', '3'),
    'UTC', 1, '09:00:00', true, 101, 'client exact replay',
);
id_mysql_check(
    $clientReplay['action'] === 'ignored'
        && (int)$clientReplay['schedule']['id'] === (int)$clientPrepared['schedule']['id']
        && (int)$pdo->query('SELECT COUNT(*) FROM business_report_id_client_contact_snapshots')->fetchColumn() === 1,
    'exact client prepare replay is idempotent with a fresh request nonce',
);
id_mysql_throws(
    BusinessReportConflictException::class,
    fn() => business_report_prepare_client_schedule_from_id(
        $pdo, 'one', 'client-id-weekly', 11, (int)$definition['definition']['id'],
        id_mysql_client_snapshot(1, 'changed-customer@example.test', '4'),
        'UTC', 1, '10:00:00', true, 101, 'client same-version conflict',
    ),
    'client same contact version with another address is refused',
);
$clientNewer = business_report_prepare_client_schedule_from_id(
    $pdo, 'one', 'client-id-weekly', 11, (int)$definition['definition']['id'],
    id_mysql_client_snapshot(2, 'new-customer-admin@example.test', '5'),
    'UTC', 1, '10:00:00', true, 102, 'client version two',
);
id_mysql_check(
    (int)$clientNewer['schedule']['version_no'] === 2
        && (int)$clientNewer['id_contact']['contact_version'] === 2,
    'newer client contact creates the next disabled schedule version',
);
$clientScopeEnabled = business_report_transition_schedule(
    $pdo, 'one', 'client-id-weekly', 2, 'active', 101, 'enable inherited client scope',
    id_mysql_report_config('client-id-weekly', 'new-customer-admin@example.test'),
);
$clientScopeDisabled = business_report_transition_schedule(
    $pdo, 'one', 'client-id-weekly', 3, 'disabled', 101, 'disable inherited client scope',
    id_mysql_report_config('client-id-weekly', 'new-customer-admin@example.test'),
);
$clientScope = business_report_contact_scope_for_key($pdo, 1, 'client-id-weekly');
id_mysql_check(
    (int)$clientScopeEnabled['schedule']['version_no'] === 3
        && (int)$clientScopeDisabled['schedule']['version_no'] === 4
        && is_array($clientScope)
        && $clientScope['scope'] === BUSINESS_REPORT_CONTACT_SCOPE_CLIENT
        && (int)($clientScope['evidence']['contact_version'] ?? 0) === 2,
    'client contact scope and latest evidence survive enable and disable versions',
);
$scopeSwitchRows = (int)$pdo->query(
    "SELECT COUNT(*) FROM business_report_schedule_versions
      WHERE schedule_key IN ('id-weekly','client-id-weekly')"
)->fetchColumn();
id_mysql_throws(
    BusinessReportConflictException::class,
    fn() => business_report_prepare_client_schedule_from_id(
        $pdo, 'one', 'id-weekly', 11, (int)$definition['definition']['id'],
        id_mysql_client_snapshot(2, 'new-customer-admin@example.test', '6'),
        'UTC', 1, '11:00:00', true, 101, 'tenant to client bypass',
    ),
    'tenant ID history cannot be changed to client ID after transitions',
);
id_mysql_throws(
    BusinessReportConflictException::class,
    fn() => business_report_prepare_schedule_from_id(
        $pdo, 'one', 'client-id-weekly', 11, (int)$definition['definition']['id'],
        id_mysql_snapshot(2, 'new-admin@example.test', '6'),
        'UTC', 1, '11:00:00', true, 101, 'client to tenant bypass',
    ),
    'client ID history cannot be changed to tenant ID after transitions',
);
id_mysql_throws(
    BusinessReportConflictException::class,
    fn() => business_report_prepare_schedule(
        $pdo, 'one', 'client-id-weekly', 11, (int)$definition['definition']['id'],
        'new-customer-admin@example.test', 'UTC', 1, '11:00:00', true, 101, 'client to manual bypass',
    ),
    'client ID history cannot be changed to manual',
);
id_mysql_check(
    (int)$pdo->query(
        "SELECT COUNT(*) FROM business_report_schedule_versions
          WHERE schedule_key IN ('id-weekly','client-id-weekly')"
    )->fetchColumn() === $scopeSwitchRows,
    'refused scope switches leave both logical histories unchanged',
);
id_mysql_throws(
    BusinessReportConflictException::class,
    fn() => business_report_prepare_client_schedule_from_id(
        $pdo, 'one', 'client-id-weekly', 11, (int)$definition['definition']['id'],
        id_mysql_client_snapshot(1, 'customer-admin@example.test', '7'),
        'UTC', 1, '11:00:00', true, 101, 'client rollback',
    ),
    'client contact version rollback is refused',
);
id_mysql_throws(
    BusinessReportConflictException::class,
    fn() => business_report_prepare_client_schedule_from_id(
        $pdo, 'one', 'client-id-rebind', 11, (int)$definition['definition']['id'],
        array_replace(id_mysql_client_snapshot(2, 'new-customer-admin@example.test', '8'), [
            'tenant_key' => 'ewid-t5',
            'tenant_slug' => 'other-customer',
        ]),
        'UTC', 1, '09:00:00', true, 101, 'client rebind',
    ),
    'one Safeharbor client cannot be rebound to another ID tenant',
);
id_mysql_throws(
    BusinessReportConflictException::class,
    fn() => business_report_prepare_client_schedule_from_id(
        $pdo, 'one', 'client-id-reuse', 12, (int)$definition['definition']['id'],
        id_mysql_client_snapshot(2, 'new-customer-admin@example.test', '9'),
        'UTC', 1, '09:00:00', true, 101, 'client key reuse',
    ),
    'one ID tenant cannot be bound to two Safeharbor clients',
);
id_mysql_throws(
    BusinessReportGateException::class,
    fn() => business_report_prepare_client_schedule_from_id(
        $pdo, 'one', 'client-cross-tenant', 22, (int)$definition['definition']['id'],
        array_replace(id_mysql_client_snapshot(1, 'two@example.test', 'a'), [
            'tenant_key' => 'ewid-t6',
            'tenant_slug' => 'customer-two',
        ]),
        'UTC', 1, '09:00:00', true, 101, 'cross-tenant client',
    ),
    'client prepare cannot reach another provider tenant',
);

$pdo->exec('DROP TRIGGER IF EXISTS test_id_client_snapshot_atomic_failure');
$pdo->exec(
    "CREATE TRIGGER test_id_client_snapshot_atomic_failure
     BEFORE INSERT ON business_report_id_client_contact_snapshots
     FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='test client snapshot failure'"
);
$clientAtomicFailure = false;
try {
    business_report_prepare_client_schedule_from_id(
        $pdo, 'one', 'client-atomic-weekly', 12, (int)$definition['definition']['id'],
        array_replace(id_mysql_client_snapshot(1, 'twelve@example.test', 'c'), [
            'tenant_key' => 'ewid-t5',
            'tenant_slug' => 'customer-twelve',
        ]),
        'UTC', 1, '09:00:00', true, 101, 'client atomic failure proof',
    );
} catch (Throwable $error) {
    $clientAtomicFailure = $error instanceof PDOException;
} finally {
    $pdo->exec('DROP TRIGGER IF EXISTS test_id_client_snapshot_atomic_failure');
}
id_mysql_check(
    $clientAtomicFailure
        && (int)$pdo->query(
            "SELECT COUNT(*) FROM business_report_schedule_versions
              WHERE tenant_id=1 AND schedule_key='client-atomic-weekly'"
        )->fetchColumn() === 0
        && (int)$pdo->query(
            "SELECT COUNT(*) FROM business_report_id_client_bindings
              WHERE tenant_id=1 AND client_id=12"
        )->fetchColumn() === 0
        && (int)$pdo->query(
            "SELECT COUNT(*) FROM business_report_contact_scope_bindings
              WHERE tenant_id=1 AND schedule_key='client-atomic-weekly'"
        )->fetchColumn() === 0,
    'client snapshot failure rolls back scope, disabled schedule, and first binding',
);

$clientBindingId = 11;
$clientSnapshotId = (int)$clientPrepared['id_contact']['id'];
id_mysql_throws(
    PDOException::class,
    fn() => $pdo->exec(
        "UPDATE business_report_id_client_bindings SET reason='changed'
          WHERE tenant_id=1 AND client_id={$clientBindingId}"
    ),
    'database refuses client binding updates',
);
id_mysql_throws(
    PDOException::class,
    fn() => $pdo->exec(
        "DELETE FROM business_report_id_client_bindings
          WHERE tenant_id=1 AND client_id={$clientBindingId}"
    ),
    'database refuses client binding deletes',
);
id_mysql_throws(
    PDOException::class,
    fn() => $pdo->exec(
        "UPDATE business_report_id_client_contact_snapshots SET reason='changed'
          WHERE id={$clientSnapshotId}"
    ),
    'database refuses client snapshot updates',
);
id_mysql_throws(
    PDOException::class,
    fn() => $pdo->exec(
        "DELETE FROM business_report_id_client_contact_snapshots WHERE id={$clientSnapshotId}"
    ),
    'database refuses client snapshot deletes',
);
$manualScopeSchedule = business_report_prepare_schedule(
    $pdo, 'one', 'manual-scope-db', 11, (int)$definition['definition']['id'],
    'manual-scope@example.test', 'UTC', 1, '09:00:00', true, 101, 'manual scope database proof',
);
$manualScopeScheduleId = (int)$manualScopeSchedule['schedule']['id'];
id_mysql_rejects_with_signal(
    fn() => $pdo->exec(
        "UPDATE business_report_contact_scope_bindings SET reason='changed'
          WHERE tenant_id=1 AND schedule_key='manual-scope-db'"
    ),
    'contact scopes are immutable',
    'database refuses contact-scope updates',
);
id_mysql_rejects_with_signal(
    fn() => $pdo->exec(
        "DELETE FROM business_report_contact_scope_bindings
          WHERE tenant_id=1 AND schedule_key='manual-scope-db'"
    ),
    'contact scopes are immutable',
    'database refuses contact-scope deletes',
);
id_mysql_rejects_with_signal(
    fn() => $pdo->exec("INSERT INTO business_report_schedule_versions
        (tenant_id,schedule_key,version_no,definition_version_id,client_id,recipient_email,
         schedule_timezone,delivery_weekday,delivery_local_time,canary,status,created_by_user_id,reason)
        VALUES (1,'scope-less-bypass',1," . (int)$definition['definition']['id'] . ",11,
         'manual-scope@example.test','UTC',1,'09:00:00',1,'disabled',101,'scope-less bypass')"),
    'requires its immutable contact scope',
    'database refuses a schedule inserted without a scope registry row',
);
id_mysql_rejects_with_signal(
    fn() => $pdo->exec("INSERT INTO business_report_id_contact_snapshots
        (tenant_id,schedule_version_id,id_tenant_key,contact_version,recipient_email,
         response_generated_at,request_nonce_sha256,response_sha256,created_by_user_id,reason)
        VALUES (1,{$manualScopeScheduleId},'ewid-t1',3,'manual-scope@example.test',
         '2026-08-28 12:00:00','" . str_repeat('1', 64) . "','" . str_repeat('2', 64) . "',
         101,'manual to tenant bypass')"),
    'immutable contact scope is not tenant ID',
    'database refuses direct manual-to-tenant evidence bypass',
);
id_mysql_rejects_with_signal(
    fn() => $pdo->exec("INSERT INTO business_report_id_client_contact_snapshots
        (tenant_id,client_id,schedule_version_id,id_tenant_key,contact_version,recipient_email,
         response_generated_at,request_nonce_sha256,response_sha256,created_by_user_id,reason)
        VALUES (1,11,{$manualScopeScheduleId},'ewid-t4',3,'manual-scope@example.test',
         '2026-08-28 12:00:00','" . str_repeat('3', 64) . "','" . str_repeat('4', 64) . "',
         101,'manual to client bypass')"),
    'immutable contact scope is not client ID',
    'database refuses direct manual-to-client evidence bypass',
);
$tenantScopeScheduleId = (int)$tenantScopeDisabled['schedule']['id'];
$clientScopeScheduleId = (int)$clientScopeDisabled['schedule']['id'];
id_mysql_rejects_with_signal(
    fn() => $pdo->exec("INSERT INTO business_report_id_client_contact_snapshots
        (tenant_id,client_id,schedule_version_id,id_tenant_key,contact_version,recipient_email,
         response_generated_at,request_nonce_sha256,response_sha256,created_by_user_id,reason)
        VALUES (1,11,{$tenantScopeScheduleId},'ewid-t4',3,'new-admin@example.test',
         '2026-08-28 12:00:00','" . str_repeat('5', 64) . "','" . str_repeat('6', 64) . "',
         101,'tenant to client bypass')"),
    'immutable contact scope is not client ID',
    'database refuses direct tenant-to-client evidence after transitions',
);
id_mysql_rejects_with_signal(
    fn() => $pdo->exec("INSERT INTO business_report_id_contact_snapshots
        (tenant_id,schedule_version_id,id_tenant_key,contact_version,recipient_email,
         response_generated_at,request_nonce_sha256,response_sha256,created_by_user_id,reason)
        VALUES (1,{$clientScopeScheduleId},'ewid-t1',3,'new-customer-admin@example.test',
         '2026-08-28 12:00:00','" . str_repeat('7', 64) . "','" . str_repeat('8', 64) . "',
         101,'client to tenant bypass')"),
    'immutable contact scope is not tenant ID',
    'database refuses direct client-to-tenant evidence after transitions',
);
id_mysql_throws(
    PDOException::class,
    fn() => $pdo->exec("INSERT INTO business_report_id_client_bindings
        (tenant_id,client_id,id_tenant_key,id_tenant_slug,created_by_user_id,reason)
        VALUES (1,12,'ewid-t1','tenant-scope-reuse',101,'tenant scope reuse')"),
    'database refuses reuse of an ID tenant already bound at tenant scope',
);
id_mysql_throws(
    PDOException::class,
    fn() => $pdo->exec("INSERT INTO business_report_id_client_bindings
        (tenant_id,client_id,id_tenant_key,id_tenant_slug,created_by_user_id,reason)
        VALUES (1,12,'ewid-t5','customer-twelve',201,'wrong tenant actor')"),
    'database refuses a client binding by an actor outside the provider tenant',
);
$pdo->exec("INSERT INTO business_report_id_client_bindings
    (tenant_id,client_id,id_tenant_key,id_tenant_slug,created_by_user_id,reason)
    VALUES (1,12,'ewid-t5','customer-twelve',101,'reverse-order client winner')");
id_mysql_rejects_with_signal(
    fn() => $pdo->exec("INSERT INTO business_report_id_tenant_bindings
        (tenant_id,id_tenant_key,id_tenant_slug,created_by_user_id,reason)
        VALUES (2,'ewid-t5','two',201,'reverse-order tenant reuse')"),
    'already bound at client scope',
    'database symmetrically refuses tenant binding after a client binding owns the ID key',
);

$replayGuardSchedule = business_report_prepare_schedule(
    $pdo,
    'one',
    'id-replay-guard',
    11,
    (int)$definition['definition']['id'],
    'replay-guard@example.test',
    'UTC',
    1,
    '09:00:00',
    true,
    101,
    'fail-closed replay proof',
);
$countsBeforeReplay = (string)$pdo->query(
    "SELECT CONCAT(
      (SELECT COUNT(*) FROM business_report_id_tenant_bindings WHERE tenant_id=1), ':',
      (SELECT COUNT(*) FROM business_report_id_contact_snapshots WHERE tenant_id=1))"
)->fetchColumn();
id_mysql_execute_until(
    $pdo,
    __DIR__ . '/../db/migrations/017_id_report_contact_evidence.sql',
    'DROP TRIGGER IF EXISTS trg_business_report_id_snapshot_no_delete',
);
id_mysql_check(
    (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.triggers
          WHERE trigger_schema=DATABASE()
            AND trigger_name IN (
              'trg_business_report_id_binding_before_insert',
              'trg_business_report_id_binding_no_update',
              'trg_business_report_id_binding_no_delete',
              'trg_business_report_id_snapshot_before_insert',
              'trg_business_report_id_snapshot_no_update',
              'trg_business_report_id_snapshot_no_delete')"
    )->fetchColumn() === 0
        && (int)$pdo->query(
            "SELECT COUNT(*) FROM information_schema.triggers
              WHERE trigger_schema=DATABASE()
                AND trigger_name LIKE 'trg_business_report_id_%_swap_%'"
        )->fetchColumn() === 6,
    'interrupted replay leaves all six fail-closed swap guards installed',
);
$swapSignal = 'migration 017 report-contact replay is in progress';
id_mysql_rejects_with_signal(
    fn() => $pdo->exec("INSERT INTO business_report_id_tenant_bindings
        (tenant_id,id_tenant_key,id_tenant_slug,created_by_user_id,reason)
        VALUES (2,'ewid-t2','two',201,'blocked replay insert')"),
    $swapSignal,
    'interrupted replay blocks binding inserts',
);
id_mysql_rejects_with_signal(
    fn() => $pdo->exec(
        "UPDATE business_report_id_tenant_bindings SET reason='blocked' WHERE tenant_id=1"
    ),
    $swapSignal,
    'interrupted replay blocks binding updates',
);
id_mysql_rejects_with_signal(
    fn() => $pdo->exec('DELETE FROM business_report_id_tenant_bindings WHERE tenant_id=1'),
    $swapSignal,
    'interrupted replay blocks binding deletes',
);
$replayGuardScheduleId = (int)$replayGuardSchedule['schedule']['id'];
id_mysql_rejects_with_signal(
    fn() => $pdo->exec("INSERT INTO business_report_id_contact_snapshots
        (tenant_id,schedule_version_id,id_tenant_key,contact_version,recipient_email,
         response_generated_at,request_nonce_sha256,response_sha256,created_by_user_id,reason)
        VALUES (1,{$replayGuardScheduleId},'ewid-t1',3,'replay-guard@example.test',
         '2026-08-27 12:00:00','" . str_repeat('d', 64) . "','" . str_repeat('e', 64) . "',
         101,'blocked replay insert')"),
    $swapSignal,
    'interrupted replay blocks snapshot inserts',
);
id_mysql_rejects_with_signal(
    fn() => $pdo->exec(
        "UPDATE business_report_id_contact_snapshots SET reason='blocked' WHERE id={$snapshotId}"
    ),
    $swapSignal,
    'interrupted replay blocks snapshot updates',
);
id_mysql_rejects_with_signal(
    fn() => $pdo->exec("DELETE FROM business_report_id_contact_snapshots WHERE id={$snapshotId}"),
    $swapSignal,
    'interrupted replay blocks snapshot deletes',
);

id_mysql_execute_file($pdo, __DIR__ . '/../db/migrations/017_id_report_contact_evidence.sql');
$countsAfterReplay = (string)$pdo->query(
    "SELECT CONCAT(
      (SELECT COUNT(*) FROM business_report_id_tenant_bindings WHERE tenant_id=1), ':',
      (SELECT COUNT(*) FROM business_report_id_contact_snapshots WHERE tenant_id=1))"
)->fetchColumn();
id_mysql_check(
    $countsBeforeReplay === $countsAfterReplay
        && $countsAfterReplay === '1:2'
        && (int)$pdo->query(
            "SELECT COUNT(*) FROM information_schema.triggers
              WHERE trigger_schema=DATABASE()
                AND trigger_name LIKE 'trg_business_report_id_%'"
        )->fetchColumn() === 6
        && (int)$pdo->query(
            "SELECT COUNT(*) FROM information_schema.triggers
              WHERE trigger_schema=DATABASE()
                AND trigger_name LIKE 'trg_business_report_id_%_swap_%'"
        )->fetchColumn() === 0,
    'exact retry restores permanent guards, removes swaps, and preserves every evidence row',
);

$clientReplayGuardSchedule = business_report_prepare_schedule(
    $pdo,
    'one',
    'client-id-replay-guard',
    11,
    (int)$definition['definition']['id'],
    'client-replay-guard@example.test',
    'UTC',
    1,
    '09:00:00',
    true,
    101,
    'client fail-closed replay proof',
);
$clientCountsBeforeReplay = (string)$pdo->query(
    "SELECT CONCAT(
      (SELECT COUNT(*) FROM business_report_id_client_bindings), ':',
      (SELECT COUNT(*) FROM business_report_id_client_contact_snapshots))"
)->fetchColumn();
id_mysql_execute_until(
    $pdo,
    $migration019,
    'DROP TRIGGER IF EXISTS trg_br_id_client_snapshot_no_delete',
);
id_mysql_check(
    (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.triggers
          WHERE trigger_schema=DATABASE()
            AND trigger_name IN (
              'trg_business_report_contact_scope_before_insert',
              'trg_business_report_contact_scope_no_update',
              'trg_business_report_contact_scope_no_delete',
              'trg_business_report_schedules_before_insert',
              'trg_business_report_id_binding_before_insert',
              'trg_business_report_id_snapshot_before_insert',
              'trg_br_id_client_binding_before_insert',
              'trg_br_id_client_binding_no_update',
              'trg_br_id_client_binding_no_delete',
              'trg_br_id_client_snapshot_before_insert',
              'trg_br_id_client_snapshot_no_update',
              'trg_br_id_client_snapshot_no_delete')"
    )->fetchColumn() === 0
        && (int)$pdo->query(
            "SELECT COUNT(*) FROM information_schema.triggers
              WHERE trigger_schema=DATABASE()
                AND trigger_name IN (
                  'trg_br_contact_scope_swap_insert',
                  'trg_br_contact_scope_swap_update',
                  'trg_br_contact_scope_swap_delete',
                  'trg_br_schedule_scope_swap_insert',
                  'trg_br_id_tenant_binding_swap_insert',
                  'trg_br_id_tenant_snapshot_swap_insert',
                  'trg_br_id_client_binding_swap_insert',
                  'trg_br_id_client_binding_swap_update',
                  'trg_br_id_client_binding_swap_delete',
                  'trg_br_id_client_snapshot_swap_insert',
                  'trg_br_id_client_snapshot_swap_update',
                  'trg_br_id_client_snapshot_swap_delete')"
        )->fetchColumn() === 12,
    'interrupted migration-019 replay leaves all twelve fail-closed swap guards installed',
);
$clientSwapSignal = 'migration 019 client report-contact replay is in progress';
id_mysql_rejects_with_signal(
    fn() => $pdo->exec("INSERT INTO business_report_contact_scope_bindings
        (tenant_id,schedule_key,contact_scope,created_by_user_id,reason)
        VALUES (1,'blocked-scope','MANUAL',101,'blocked scope insert')"),
    $clientSwapSignal,
    'interrupted migration-019 replay blocks scope inserts',
);
id_mysql_rejects_with_signal(
    fn() => $pdo->exec(
        "UPDATE business_report_contact_scope_bindings SET reason='blocked'
          WHERE tenant_id=1 AND schedule_key='manual-scope-db'"
    ),
    $clientSwapSignal,
    'interrupted migration-019 replay blocks scope updates',
);
id_mysql_rejects_with_signal(
    fn() => $pdo->exec(
        "DELETE FROM business_report_contact_scope_bindings
          WHERE tenant_id=1 AND schedule_key='manual-scope-db'"
    ),
    $clientSwapSignal,
    'interrupted migration-019 replay blocks scope deletes',
);
id_mysql_rejects_with_signal(
    fn() => $pdo->exec("INSERT INTO business_report_schedule_versions
        (tenant_id,schedule_key,version_no,definition_version_id,client_id,recipient_email,
         schedule_timezone,delivery_weekday,delivery_local_time,canary,status,created_by_user_id,reason)
        VALUES (1,'blocked-schedule',1," . (int)$definition['definition']['id'] . ",11,
         'blocked@example.test','UTC',1,'09:00:00',1,'disabled',101,'blocked schedule')"),
    $clientSwapSignal,
    'interrupted migration-019 replay blocks schedule inserts',
);
id_mysql_rejects_with_signal(
    fn() => $pdo->exec("INSERT INTO business_report_id_tenant_bindings
        (tenant_id,id_tenant_key,id_tenant_slug,created_by_user_id,reason)
        VALUES (2,'ewid-t6','two',201,'blocked tenant binding')"),
    $clientSwapSignal,
    'interrupted migration-019 replay blocks tenant-binding inserts',
);
$tenantReplayGuardScheduleId = (int)$tenantScopeDisabled['schedule']['id'];
id_mysql_rejects_with_signal(
    fn() => $pdo->exec("INSERT INTO business_report_id_contact_snapshots
        (tenant_id,schedule_version_id,id_tenant_key,contact_version,recipient_email,
         response_generated_at,request_nonce_sha256,response_sha256,created_by_user_id,reason)
        VALUES (1,{$tenantReplayGuardScheduleId},'ewid-t1',3,'new-admin@example.test',
         '2026-08-28 12:00:00','" . str_repeat('c', 64) . "','" . str_repeat('d', 64) . "',
         101,'blocked tenant snapshot')"),
    $clientSwapSignal,
    'interrupted migration-019 replay blocks tenant-snapshot inserts',
);
id_mysql_rejects_with_signal(
    fn() => $pdo->exec("INSERT INTO business_report_id_client_bindings
        (tenant_id,client_id,id_tenant_key,id_tenant_slug,created_by_user_id,reason)
        VALUES (1,12,'ewid-t5','customer-twelve',101,'blocked insert')"),
    $clientSwapSignal,
    'interrupted migration-019 replay blocks client binding inserts',
);
id_mysql_rejects_with_signal(
    fn() => $pdo->exec(
        "UPDATE business_report_id_client_bindings SET reason='blocked'
          WHERE tenant_id=1 AND client_id=11"
    ),
    $clientSwapSignal,
    'interrupted migration-019 replay blocks client binding updates',
);
id_mysql_rejects_with_signal(
    fn() => $pdo->exec(
        'DELETE FROM business_report_id_client_bindings WHERE tenant_id=1 AND client_id=11'
    ),
    $clientSwapSignal,
    'interrupted migration-019 replay blocks client binding deletes',
);
$clientReplayGuardScheduleId = (int)$clientReplayGuardSchedule['schedule']['id'];
id_mysql_rejects_with_signal(
    fn() => $pdo->exec("INSERT INTO business_report_id_client_contact_snapshots
        (tenant_id,client_id,schedule_version_id,id_tenant_key,contact_version,
         recipient_email,response_generated_at,request_nonce_sha256,response_sha256,
         created_by_user_id,reason)
        VALUES (1,11,{$clientReplayGuardScheduleId},'ewid-t4',3,
         'client-replay-guard@example.test','2026-08-28 12:00:00',
         '" . str_repeat('a', 64) . "','" . str_repeat('b', 64) . "',101,'blocked insert')"),
    $clientSwapSignal,
    'interrupted migration-019 replay blocks client snapshot inserts',
);
id_mysql_rejects_with_signal(
    fn() => $pdo->exec(
        "UPDATE business_report_id_client_contact_snapshots SET reason='blocked'
          WHERE id={$clientSnapshotId}"
    ),
    $clientSwapSignal,
    'interrupted migration-019 replay blocks client snapshot updates',
);
id_mysql_rejects_with_signal(
    fn() => $pdo->exec(
        "DELETE FROM business_report_id_client_contact_snapshots WHERE id={$clientSnapshotId}"
    ),
    $clientSwapSignal,
    'interrupted migration-019 replay blocks client snapshot deletes',
);
id_mysql_execute_file($pdo, $migration019);
$clientCountsAfterReplay = (string)$pdo->query(
    "SELECT CONCAT(
      (SELECT COUNT(*) FROM business_report_id_client_bindings), ':',
      (SELECT COUNT(*) FROM business_report_id_client_contact_snapshots))"
)->fetchColumn();
id_mysql_check(
    $clientCountsBeforeReplay === $clientCountsAfterReplay
        && $clientCountsAfterReplay === '2:2'
        && (int)$pdo->query(
            "SELECT COUNT(*) FROM information_schema.triggers
              WHERE trigger_schema=DATABASE()
                AND trigger_name LIKE 'trg_br_id_client_%'"
        )->fetchColumn() === 6
        && (int)$pdo->query(
            "SELECT COUNT(*) FROM information_schema.triggers
              WHERE trigger_schema=DATABASE()
                AND trigger_name LIKE '%_swap_%'"
        )->fetchColumn() === 0
        && (int)$pdo->query(
            "SELECT COUNT(*) FROM information_schema.triggers
              WHERE trigger_schema=DATABASE()
                AND trigger_name IN (
                  'trg_business_report_contact_scope_before_insert',
                  'trg_business_report_contact_scope_no_update',
                  'trg_business_report_contact_scope_no_delete',
                  'trg_business_report_schedules_before_insert',
                  'trg_business_report_id_binding_before_insert',
                  'trg_business_report_id_snapshot_before_insert')"
        )->fetchColumn() === 6,
    'exact migration-019 retry restores scope, tenant, and client guards and preserves evidence',
);

$escapedDatabase = str_replace('`', '``', $database);
$server->exec("CREATE USER '{$runtimeUser}'@'%' IDENTIFIED BY '{$runtimePass}'");
$runtimeUserCreated = true;
$server->exec("GRANT SELECT ON `{$escapedDatabase}`.* TO '{$runtimeUser}'@'%'");
$server->exec(
    "GRANT INSERT, UPDATE ON `{$escapedDatabase}`.`business_report_schedule_versions`
     TO '{$runtimeUser}'@'%'"
);
foreach ([
    'business_report_contact_scope_bindings',
    'business_report_id_tenant_bindings',
    'business_report_id_contact_snapshots',
    'business_report_id_client_bindings',
    'business_report_id_client_contact_snapshots',
] as $table) {
    // UPDATE is required by MySQL's locking reads. Permanent triggers still
    // make both evidence tables append-only.
    $server->exec("GRANT INSERT, UPDATE ON `{$escapedDatabase}`.`{$table}` TO '{$runtimeUser}'@'%'");
}
$runtime = new PDO($serverDsn . ";dbname={$database}", $runtimeUser, $runtimePass, $pdoOptions);
$runtime->exec("SET time_zone = '+00:00'");
$grants = implode("\n", $runtime->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN));
id_mysql_check(
    !str_contains($grants, 'DELETE')
        && !str_contains($grants, 'ALTER')
        && !str_contains($grants, 'TRIGGER'),
    'report-contact runtime has locking-read authority but no delete or DDL authority',
);
$runtimePrepared = business_report_prepare_schedule_from_id(
    $runtime, 'one', 'id-runtime-weekly', 11, (int)$definition['definition']['id'],
    id_mysql_snapshot(2, 'new-admin@example.test', '9'),
    'UTC', 1, '09:00:00', true, 101, 'least privilege prepare',
);
id_mysql_check(
    $runtimePrepared['action'] === 'prepared'
        && (int)$runtimePrepared['id_contact']['contact_version'] === 2,
    'append-only runtime identity can prepare and pin an ID-backed schedule',
);
id_mysql_throws(
    PDOException::class,
    fn() => $runtime->exec(
        'UPDATE business_report_id_contact_snapshots SET reason=reason WHERE id='
        . (int)$runtimePrepared['id_contact']['id']
    ),
    'append-only runtime identity cannot update evidence',
);
id_mysql_throws(
    PDOException::class,
    fn() => $runtime->exec('DELETE FROM business_report_id_contact_snapshots WHERE id=' . (int)$runtimePrepared['id_contact']['id']),
    'append-only runtime identity cannot delete evidence',
);
$runtimeClientPrepared = business_report_prepare_client_schedule_from_id(
    $runtime, 'one', 'client-id-runtime-weekly', 11, (int)$definition['definition']['id'],
    id_mysql_client_snapshot(2, 'new-customer-admin@example.test', 'b'),
    'UTC', 1, '09:00:00', true, 101, 'least privilege client prepare',
);
id_mysql_check(
    $runtimeClientPrepared['action'] === 'prepared'
        && (int)$runtimeClientPrepared['id_contact']['client_id'] === 11
        && (int)$runtimeClientPrepared['id_contact']['contact_version'] === 2,
    'append-only runtime identity can pin a client-scoped ID schedule',
);
id_mysql_throws(
    PDOException::class,
    fn() => $runtime->exec(
        'UPDATE business_report_id_client_contact_snapshots SET reason=reason WHERE id='
        . (int)$runtimeClientPrepared['id_contact']['id']
    ),
    'append-only runtime identity cannot update client evidence',
);
id_mysql_throws(
    PDOException::class,
    fn() => $runtime->exec(
        'DELETE FROM business_report_id_client_contact_snapshots WHERE id='
        . (int)$runtimeClientPrepared['id_contact']['id']
    ),
    'append-only runtime identity cannot delete client evidence',
);

$bindingRaceReadyFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
    . DIRECTORY_SEPARATOR . 'safeharbor-id-binding-race-' . $runId . '.ready';
$bindingWorkerEnvironment = [
    'SAFEHARBOR_ID_REPORT_TEST_BINDING_WORKER' => '1',
    'SAFEHARBOR_ID_REPORT_TEST_HOST' => $host,
    'SAFEHARBOR_ID_REPORT_TEST_PORT' => (string)$port,
    'SAFEHARBOR_ID_REPORT_TEST_DB_EXACT' => $database,
    'SAFEHARBOR_ID_REPORT_TEST_RUNTIME_USER' => $runtimeUser,
    'SAFEHARBOR_ID_REPORT_TEST_RUNTIME_PASS' => $runtimePass,
    'SAFEHARBOR_ID_REPORT_TEST_READY_FILE' => $bindingRaceReadyFile,
];
foreach (['PATH', 'SystemRoot', 'WINDIR', 'TEMP', 'TMP', 'TMPDIR'] as $environmentName) {
    $environmentValue = getenv($environmentName);
    if (is_string($environmentValue) && $environmentValue !== '') {
        $bindingWorkerEnvironment[$environmentName] = $environmentValue;
    }
}
$bindingRace = id_mysql_run_binding_race($runtime, $bindingWorkerEnvironment);
id_mysql_check(
    $bindingRace['blocked'],
    'tenant claimant blocks while the concurrent client binding owns the ID-key lock',
);
id_mysql_check(
    $bindingRace['exit_code'] === 0
        && $bindingRace['stdout'] === ['READY', 'CONFLICT']
        && $bindingRace['stderr_empty'],
    'blocked tenant claimant exits only through the symmetric client-scope conflict',
);
id_mysql_check(
    $pdo->query(
        "SELECT CONCAT(
          (SELECT COUNT(*) FROM business_report_id_client_bindings WHERE id_tenant_key='ewid-t7'), ':',
          (SELECT COUNT(*) FROM business_report_id_tenant_bindings WHERE id_tenant_key='ewid-t7'))"
    )->fetchColumn() === '1:0',
    'concurrent cross-table claim commits only the client-scope winner',
);

$raceScheduleA = id_mysql_prepare_unpinned_tenant_schedule(
    $runtime, (int)$definition['definition']['id'], 'id-race-a', 'race-a@example.test',
);
$raceScheduleB = id_mysql_prepare_unpinned_tenant_schedule(
    $runtime, (int)$definition['definition']['id'], 'id-race-b', 'race-b@example.test',
);
$raceReadyFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
    . DIRECTORY_SEPARATOR . 'safeharbor-id-report-race-' . $runId . '.ready';
$workerEnvironment = [
    'SAFEHARBOR_ID_REPORT_TEST_WORKER' => '1',
    'SAFEHARBOR_ID_REPORT_TEST_HOST' => $host,
    'SAFEHARBOR_ID_REPORT_TEST_PORT' => (string)$port,
    'SAFEHARBOR_ID_REPORT_TEST_DB_EXACT' => $database,
    'SAFEHARBOR_ID_REPORT_TEST_RUNTIME_USER' => $runtimeUser,
    'SAFEHARBOR_ID_REPORT_TEST_RUNTIME_PASS' => $runtimePass,
    'SAFEHARBOR_ID_REPORT_TEST_SCHEDULE_ID' => (string)$raceScheduleB['id'],
    'SAFEHARBOR_ID_REPORT_TEST_READY_FILE' => $raceReadyFile,
];
foreach (['PATH', 'SystemRoot', 'WINDIR', 'TEMP', 'TMP', 'TMPDIR'] as $environmentName) {
    $environmentValue = getenv($environmentName);
    if (is_string($environmentValue) && $environmentValue !== '') {
        $workerEnvironment[$environmentName] = $environmentValue;
    }
}
$race = id_mysql_run_snapshot_race(
    $runtime,
    (int)$raceScheduleA['id'],
    id_mysql_snapshot(3, 'race-a@example.test', 'a'),
    $workerEnvironment,
);
$raceRecipients = $pdo->query(
    "SELECT recipient_email FROM business_report_id_contact_snapshots
      WHERE tenant_id=1 AND id_tenant_key='ewid-t1' AND contact_version=3
      ORDER BY id"
)->fetchAll(PDO::FETCH_COLUMN);
id_mysql_check(
    $race['blocked'],
    'second MySQL process remains blocked while the first holds the binding row lock',
);
id_mysql_check(
    $race['exit_code'] === 0
        && $race['stdout'] === ['READY', 'CONFLICT']
        && $race['stderr_empty'],
    'blocked worker exits only through the exact same-version conflict signal',
);
id_mysql_check(
    $raceRecipients === ['race-a@example.test'],
    'two-connection race commits only the serialized winner recipient',
);
} catch (Throwable $error) {
    $runError = $error;
} finally {
    $runtime = null;
    if ($runtimeUserCreated) {
        try {
            $server->exec("DROP USER IF EXISTS '{$runtimeUser}'@'%'");
            if ((int)$server->query(
                "SELECT COUNT(*) FROM mysql.user
                  WHERE user=" . $server->quote($runtimeUser) . " AND host='%'"
            )->fetchColumn() !== 0) {
                $cleanupFailures[] = 'runtime-user cleanup postflight failed';
            }
        } catch (Throwable) {
            $cleanupFailures[] = 'runtime-user cleanup failed';
        }
    }
    $pdo = null;
    if ($databaseCreated) {
        try {
            $server->exec("DROP DATABASE IF EXISTS {$quotedDatabase}");
            if ((int)$server->query(
                "SELECT COUNT(*) FROM information_schema.schemata
                  WHERE schema_name=" . $server->quote($database)
            )->fetchColumn() !== 0) {
                $cleanupFailures[] = 'database cleanup postflight failed';
            }
        } catch (Throwable) {
            $cleanupFailures[] = 'database cleanup failed';
        }
    }
}

if ($runError instanceof Throwable) {
    $databaseCode = '';
    if ($runError instanceof PDOException && is_array($runError->errorInfo)) {
        $databaseCode = ', SQLSTATE ' . (string)($runError->errorInfo[0] ?? 'unknown')
            . ', driver ' . (string)($runError->errorInfo[1] ?? 'unknown');
    }
    $harnessDetail = $runError instanceof RuntimeException
        ? ': ' . substr(
            preg_replace('/[^A-Za-z0-9_ .:-]/', '?', $runError->getMessage()) ?? '',
            0,
            160,
        )
        : '';
    fwrite(
        STDERR,
        'ID report-contact MySQL fixture aborted unexpectedly ('
        . get_class($runError) . $databaseCode . ', line ' . $runError->getLine()
        . ')' . $harnessDetail . ".\n",
    );
    exit(1);
}
id_mysql_check(
    $cleanupFailures === [],
    'outer finally removes the random runtime identity and disposable database',
);
if ($idMysqlFailures > 0) {
    fwrite(STDERR, "{$idMysqlFailures} of {$idMysqlChecks} ID report-contact MySQL checks failed.\n");
    exit(1);
}
echo "ID report-contact MySQL: {$idMysqlChecks}/{$idMysqlChecks} passed.\n";
