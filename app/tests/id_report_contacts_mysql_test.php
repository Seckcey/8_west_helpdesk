<?php
/** Standalone MySQL 8 proof for migration 017 and ID contact evidence guards. */
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

if (getenv('SAFEHARBOR_ID_REPORT_TEST_WORKER') === '1') {
    id_mysql_snapshot_worker();
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
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    try {
        $pdo->exec(
            'DROP TABLE IF EXISTS
                business_report_id_contact_snapshots,
                business_report_id_tenant_bindings,
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

$pdo->exec("INSERT INTO tenants (id,name,slug) VALUES (1,'Tenant One','one'),(2,'Tenant Two','two')");
$pdo->exec("INSERT INTO clients (id,tenant_id,name) VALUES (11,1,'Client One'),(22,2,'Client Two')");
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
        && (int)$pdo->query('SELECT COUNT(*) FROM business_report_id_tenant_bindings')->fetchColumn() === 1
        && (int)$pdo->query('SELECT COUNT(*) FROM business_report_id_contact_snapshots')->fetchColumn() === 1,
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
        && (int)$pdo->query('SELECT COUNT(*) FROM business_report_id_contact_snapshots')->fetchColumn() === 1,
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
        )->fetchColumn() === 0,
    'snapshot failure rolls back both the disabled schedule and first tenant binding',
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
      (SELECT COUNT(*) FROM business_report_id_tenant_bindings), ':',
      (SELECT COUNT(*) FROM business_report_id_contact_snapshots))"
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
      (SELECT COUNT(*) FROM business_report_id_tenant_bindings), ':',
      (SELECT COUNT(*) FROM business_report_id_contact_snapshots))"
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

$escapedDatabase = str_replace('`', '``', $database);
$server->exec("CREATE USER '{$runtimeUser}'@'%' IDENTIFIED BY '{$runtimePass}'");
$runtimeUserCreated = true;
$server->exec("GRANT SELECT ON `{$escapedDatabase}`.* TO '{$runtimeUser}'@'%'");
$server->exec(
    "GRANT INSERT, UPDATE ON `{$escapedDatabase}`.`business_report_schedule_versions`
     TO '{$runtimeUser}'@'%'"
);
foreach (['business_report_id_tenant_bindings', 'business_report_id_contact_snapshots'] as $table) {
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

$raceScheduleA = business_report_prepare_schedule(
    $pdo, 'one', 'id-race-a', 11, (int)$definition['definition']['id'],
    'race-a@example.test', 'UTC', 1, '09:00:00', true, 101, 'race schedule a',
);
$raceScheduleB = business_report_prepare_schedule(
    $pdo, 'one', 'id-race-b', 11, (int)$definition['definition']['id'],
    'race-b@example.test', 'UTC', 1, '09:00:00', true, 101, 'race schedule b',
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
    'SAFEHARBOR_ID_REPORT_TEST_SCHEDULE_ID' => (string)$raceScheduleB['schedule']['id'],
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
    (int)$raceScheduleA['schedule']['id'],
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
