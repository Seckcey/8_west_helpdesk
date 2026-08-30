<?php
/** MySQL 8 integration coverage for migration 013 and report state guards. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);

require_once __DIR__ . '/../lib/business_reports.php';
require_once __DIR__ . '/../lib/time_entry_adjustments.php';

$database = getenv('SAFEHARBOR_REPORT_TEST_DB') ?: 'safeharbor_report_test';
if (preg_match('/\Asafeharbor_report_test(?:_[a-z0-9_]+)?\z/', $database) !== 1) {
    fwrite(STDERR, "Refusing destructive test database: {$database}\n");
    exit(2);
}
$host = getenv('SAFEHARBOR_REPORT_TEST_HOST') ?: '127.0.0.1';
$port = getenv('SAFEHARBOR_REPORT_TEST_PORT') ?: '3306';
$user = getenv('SAFEHARBOR_REPORT_TEST_USER') ?: 'root';
$pass = getenv('SAFEHARBOR_REPORT_TEST_PASS') ?: '';
$serverDsn = "mysql:host={$host};port={$port};charset=utf8mb4";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

// Independent report and adjustment sessions are bound to the exact scratch
// database by a random marker created by the parent process.
if (getenv('SAFEHARBOR_REPORT_RACE_WORKER') === '1') {
    $raceToken = getenv('SAFEHARBOR_REPORT_RACE_TOKEN');
    $raceMode = getenv('SAFEHARBOR_REPORT_RACE_MODE');
    $raceSchedule = getenv('SAFEHARBOR_REPORT_RACE_SCHEDULE');
    $raceEntryText = getenv('SAFEHARBOR_REPORT_RACE_ENTRY');
    $raceNowText = getenv('SAFEHARBOR_REPORT_RACE_NOW');
    $raceRequireDue = getenv('SAFEHARBOR_REPORT_RACE_REQUIRE_DUE');
    if (!is_string($raceToken) || preg_match('/\A[a-f0-9]{64}\z/D', $raceToken) !== 1
        || !is_string($raceMode) || !in_array($raceMode, ['report', 'adjustment'], true)
        || !is_string($raceSchedule)
        || !in_array($raceSchedule, [
            'weekly-race-report',
            'weekly-race-adjustment',
            'weekly-race-v2-report',
            'weekly-race-v2-adjustment',
        ], true)
        || !is_string($raceEntryText) || preg_match('/\A[1-9][0-9]{0,9}\z/D', $raceEntryText) !== 1
        || !is_string($raceNowText) || preg_match('/\A[1-9][0-9]{0,10}\z/D', $raceNowText) !== 1
        || !is_string($raceRequireDue) || !in_array($raceRequireDue, ['0', '1'], true)
    ) {
        fwrite(STDERR, "Business-report race worker refused its target.\n");
        exit(2);
    }

    try {
        $worker = new PDO($serverDsn . ";dbname={$database}", $user, $pass, $options);
        $worker->exec("SET time_zone = '+00:00'");
        $worker->exec('SET SESSION innodb_lock_wait_timeout=15');
        if (!hash_equals($database, (string) $worker->query('SELECT DATABASE()')->fetchColumn())) {
            throw new RuntimeException('Business-report race worker selected an unexpected database.');
        }
        $marker = $worker->query('SELECT worker_token FROM report_race_test_marker WHERE id=1');
        if (!hash_equals($raceToken, (string) $marker->fetchColumn())) {
            throw new RuntimeException('Business-report race worker marker did not match.');
        }
        // Pin only the v2 competitors to the same database second. The durable
        // v2 adjustment-id cutoff, not clock precision, must preserve order.
        // V1 keeps its original live database clock so the legacy race proof
        // continues to verify that a waiting report cannot be backdated.
        if (str_starts_with($raceSchedule, 'weekly-race-v2-')) {
            $worker->exec('SET timestamp = ' . (int) $raceNowText);
        }

        echo "ready\n";
        fflush(STDOUT);
        if ($raceMode === 'report') {
            try {
                $result = business_report_generate(
                    $worker,
                    'one',
                    $raceSchedule,
                    report_mysql_config(),
                    (int) $raceNowText,
                    false,
                    $raceRequireDue === '1',
                );
                echo json_encode([
                    'status' => 201,
                    'action' => $result['action'],
                    'archive_id' => (int) $result['archive']['id'],
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
            } catch (BusinessReportConflictException $error) {
                if (!str_contains($error->getMessage(), 'cannot archive adjusted approved time')) {
                    throw $error;
                }
                echo json_encode([
                    'status' => 409,
                    'error' => 'adjusted_v1',
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
            }
            exit(0);
        }

        try {
            $result = time_entry_adjustment_create($worker, 1, 101, 'owner', [
                'entry_id' => (int) $raceEntryText,
                'adjustment_key' => 'adjustment.report-race.' . $raceSchedule . '.0001',
                'expected_version' => 0,
                'effective_minutes' => 20,
                'effective_billable' => true,
                'reason' => 'Concurrent report serialization proof',
            ]);
            echo json_encode([
                'status' => 201,
                'id' => (int) $result['id'],
                'version' => (int) $result['version'],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        } catch (TimeEntryConflictException $error) {
            echo json_encode([
                'status' => time_entry_http_status($error),
                'error' => 'stale_version',
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        }
        exit(0);
    } catch (Throwable $error) {
        fwrite(STDERR, 'Business-report race worker failed with ' . $error::class . PHP_EOL);
        exit(1);
    }
}

try {
    $server = new PDO($serverDsn, $user, $pass, $options);
    $quotedDatabase = '`' . str_replace('`', '``', $database) . '`';
    $server->exec("CREATE DATABASE IF NOT EXISTS {$quotedDatabase} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo = new PDO($serverDsn . ";dbname={$database}", $user, $pass, $options);
    $pdo->exec("SET time_zone = '+00:00'");
} catch (Throwable $error) {
    fwrite(STDERR, 'Business report MySQL fixture unavailable: ' . $error->getMessage() . "\n");
    exit(2);
}

$checks = 0;
$failures = 0;

function report_mysql_check(string $name, bool $condition): void
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
function report_mysql_throws(
    string $name,
    string $expected,
    callable $operation,
    string $fragment = '',
): void
{
    try {
        $operation();
        report_mysql_check($name, false);
    } catch (Throwable $error) {
        report_mysql_check(
            $name,
            $error instanceof $expected
                && ($fragment === '' || str_contains($error->getMessage(), $fragment)),
        );
    }
}

/** @return list<string> */
function report_mysql_statements(string $sql): array
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

function report_mysql_execute_file(PDO $pdo, string $path): void
{
    $sql = file_get_contents($path);
    if (!is_string($sql)) throw new RuntimeException("Cannot read {$path}");
    foreach (report_mysql_statements($sql) as $statement) {
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

function report_mysql_reset(PDO $pdo): void
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

/** @return array<string,mixed> */
function report_mysql_config(): array
{
    return [
        'generation_enabled' => true,
        'delivery_enabled' => true,
        'canary_only' => true,
        'schedule_keys' => [
            'weekly-one',
            'weekly-runtime',
            'weekly-v2',
            'weekly-race-report',
            'weekly-race-adjustment',
            'weekly-race-v2-report',
            'weekly-race-v2-adjustment',
        ],
        'tenant_slugs' => ['one'],
        'client_keys' => [
            'safeharbor-client:11',
            'safeharbor-client:13',
            'safeharbor-client:14',
            'safeharbor-client:15',
        ],
        'recipient_emails' => ['reports@example.test'],
        'lease_seconds' => 120,
    ];
}

/** @return array<string,mixed> */
function report_mysql_start_race_worker(
    string $token,
    string $mode,
    string $scheduleKey,
    int $entryId,
    int $now,
    bool $requireDue,
): array {
    $environment = getenv();
    if (!is_array($environment)) $environment = [];
    $environment['SAFEHARBOR_REPORT_RACE_WORKER'] = '1';
    $environment['SAFEHARBOR_REPORT_RACE_TOKEN'] = $token;
    $environment['SAFEHARBOR_REPORT_RACE_MODE'] = $mode;
    $environment['SAFEHARBOR_REPORT_RACE_SCHEDULE'] = $scheduleKey;
    $environment['SAFEHARBOR_REPORT_RACE_ENTRY'] = (string) $entryId;
    $environment['SAFEHARBOR_REPORT_RACE_NOW'] = (string) $now;
    $environment['SAFEHARBOR_REPORT_RACE_REQUIRE_DUE'] = $requireDue ? '1' : '0';
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, __FILE__],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        __DIR__,
        $environment,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start business-report race worker.');
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $output = '';
    $errors = '';
    $ready = false;
    $deadline = microtime(true) + 5.0;
    do {
        $output .= (string) stream_get_contents($pipes[1]);
        $errors .= (string) stream_get_contents($pipes[2]);
        $ready = preg_match('/(?:\A|\R)ready\R/', $output) === 1;
        $status = proc_get_status($process);
        if ($ready || !($status['running'] ?? false)) break;
        usleep(20000);
    } while (microtime(true) < $deadline);
    return compact('process', 'pipes', 'output', 'errors', 'ready');
}

/** @param array<string,mixed> $worker @return array<string,mixed> */
function report_mysql_finish_race_worker(array $worker): array
{
    $process = $worker['process'];
    $pipes = $worker['pipes'];
    $output = (string) $worker['output'];
    $errors = (string) $worker['errors'];
    $timedOut = false;
    $lastExit = -1;
    $deadline = microtime(true) + 15.0;
    while (true) {
        $output .= (string) stream_get_contents($pipes[1]);
        $errors .= (string) stream_get_contents($pipes[2]);
        $status = proc_get_status($process);
        if (!($status['running'] ?? false)) {
            $lastExit = (int) ($status['exitcode'] ?? -1);
            break;
        }
        if (microtime(true) >= $deadline) {
            $timedOut = true;
            proc_terminate($process);
            break;
        }
        usleep(20000);
    }
    $output .= (string) stream_get_contents($pipes[1]);
    $errors .= (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $closedExit = proc_close($process);
    $exit = $closedExit >= 0 ? $closedExit : $lastExit;
    $lines = preg_split('/\R/', trim($output)) ?: [];
    $payload = json_decode((string) end($lines), true);
    return [
        'ready' => (bool) $worker['ready'],
        'timed_out' => $timedOut,
        'exit' => $exit,
        'stderr' => $errors,
        'payload' => is_array($payload) ? $payload : null,
    ];
}

function report_mysql_waiters(
    PDO $server,
    string $database,
    string $table,
    int $minimum,
): bool {
    $query = $server->prepare(
        "SELECT COUNT(DISTINCT waits.REQUESTING_THREAD_ID)
           FROM performance_schema.data_lock_waits waits
           JOIN performance_schema.data_locks requested
             ON requested.ENGINE = waits.ENGINE
            AND requested.ENGINE_LOCK_ID = waits.REQUESTING_ENGINE_LOCK_ID
          WHERE requested.OBJECT_SCHEMA = ?
            AND requested.OBJECT_NAME = ?
            AND requested.LOCK_STATUS = 'WAITING'"
    );
    $deadline = microtime(true) + 5.0;
    do {
        $query->execute([$database, $table]);
        if ((int) $query->fetchColumn() >= $minimum) return true;
        usleep(20000);
    } while (microtime(true) < $deadline);
    return false;
}

/** @return array<string,mixed> */
function report_mysql_create_approved_time(
    PDO $pdo,
    int $ticketId,
    string $entryKey,
    string $workedAt,
    int $minutes,
): array {
    $created = time_entry_create($pdo, 1, 103, [
        'ticket_id' => $ticketId,
        'entry_key' => $entryKey,
        'source' => 'suggestion',
        'worked_at' => $workedAt,
        'minutes' => $minutes,
        'note' => 'Business-report serialization fixture',
        'billable' => true,
    ]);
    return time_entry_review(
        $pdo,
        1,
        101,
        'owner',
        (int) $created['id'],
        'approved',
        'Approved for report serialization fixture',
    );
}

$schedulerLockSql = "SELECT GET_LOCK('safeharbor_business_reports_v1', 0)";
$schedulerReleaseSql = "SELECT RELEASE_LOCK('safeharbor_business_reports_v1')";
$firstLock = $pdo->query($schedulerLockSql)->fetchColumn();
$secondLockWhileHeld = $server->query($schedulerLockSql)->fetchColumn();
$firstRelease = $pdo->query($schedulerReleaseSql)->fetchColumn();
$secondLockAfterRelease = $server->query($schedulerLockSql)->fetchColumn();
$secondRelease = $server->query($schedulerReleaseSql)->fetchColumn();
business_report_advisory_lock_release($firstRelease);
business_report_advisory_lock_release($secondRelease);
report_mysql_check(
    'real MySQL sessions distinguish scheduler contention and verified release',
    business_report_advisory_lock_state($firstLock) === 'acquired'
        && business_report_advisory_lock_state($secondLockWhileHeld) === 'contended'
        && business_report_advisory_lock_state($secondLockAfterRelease) === 'acquired',
);

report_mysql_reset($pdo);
report_mysql_execute_file($pdo, __DIR__ . '/../db/schema.sql');
report_mysql_check('fresh schema creates ten report tables including immutable contact scope',
    (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema=DATABASE() AND table_name LIKE 'business_report_%'"
    )->fetchColumn() === 10);
report_mysql_execute_file($pdo, __DIR__ . '/../db/migrations/013_business_reports.sql');
report_mysql_execute_file($pdo, __DIR__ . '/../db/migrations/019_client_report_contact_evidence.sql');
report_mysql_check('migrations 013 and 019 replay over exact fresh-schema objects', true);
report_mysql_check('report tables retain exact per-table column counts',
    $pdo->query(
        "SELECT CONCAT(table_name,':',COUNT(*)) shape
           FROM information_schema.columns
          WHERE table_schema=DATABASE() AND table_name LIKE 'business_report_%'
          GROUP BY table_name ORDER BY table_name"
    )->fetchAll(PDO::FETCH_COLUMN) === [
        'business_report_archives:13',
        'business_report_contact_scope_bindings:6',
        'business_report_definition_versions:10',
        'business_report_deliveries:12',
        'business_report_delivery_attempts:10',
        'business_report_id_client_bindings:7',
        'business_report_id_client_contact_snapshots:13',
        'business_report_id_contact_snapshots:12',
        'business_report_id_tenant_bindings:6',
        'business_report_schedule_versions:15',
    ]);
report_mysql_check('all twenty-four report triggers are installed',
    (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.triggers
          WHERE trigger_schema=DATABASE() AND trigger_name LIKE 'trg_business_report_%'"
    )->fetchColumn() === 24);
report_mysql_check('all report relationships are tenant-scoped',
    (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.table_constraints
          WHERE constraint_schema=DATABASE() AND constraint_type='FOREIGN KEY'
            AND table_name LIKE 'business_report_%'"
    )->fetchColumn() === 31);
report_mysql_check('exact JSON bytes use LONGTEXT rather than native JSON normalization',
    $pdo->query(
        "SELECT CONCAT(table_name,':',column_type) FROM information_schema.columns
          WHERE table_schema=DATABASE()
            AND ((table_name='business_report_definition_versions' AND column_name='contract_json')
              OR (table_name='business_report_archives' AND column_name='metrics_json'))
          ORDER BY table_name"
    )->fetchAll(PDO::FETCH_COLUMN) === [
        'business_report_archives:longtext',
        'business_report_definition_versions:longtext',
    ]);

$pdo->exec("INSERT INTO tenants (id,name,slug) VALUES (1,'Tenant One','one'),(2,'Tenant Two','two')");
$pdo->exec("INSERT INTO clients (id,tenant_id,name) VALUES
    (11,1,'Client One'),(12,1,'Client Twelve'),(13,1,'Client Thirteen'),
    (14,1,'Client Fourteen'),(15,1,'Client Fifteen'),(22,2,'Client Two')");
$pdo->exec("INSERT INTO users
    (id,tenant_id,email,password_hash,full_name,initials,role,is_active) VALUES
    (101,1,'owner1@example.test','','Owner One','O1','owner',1),
    (102,1,'admin1@example.test','','Admin One','A1','admin',1),
    (103,1,'tech1@example.test','','Tech One','T1','tech',1),
    (104,1,'inactive1@example.test','','Inactive Admin','IA','admin',0),
    (201,2,'owner2@example.test','','Owner Two','O2','owner',1)");

$contract = business_report_contract_json();
$contractHash = business_report_contract_sha256();
$directDefinition = $pdo->prepare(
    'INSERT INTO business_report_definition_versions
        (tenant_id,definition_key,version_no,report_type,contract_json,contract_sha256,created_by_user_id,reason)
     VALUES (?,?,?,?,?,?,?,?)'
);
report_mysql_throws('database rejects technician definition actor', PDOException::class,
    fn() => $directDefinition->execute([1,'bad-tech',1,BUSINESS_REPORT_TYPE,$contract,$contractHash,103,'bad']));
report_mysql_throws('database rejects inactive admin definition actor', PDOException::class,
    fn() => $directDefinition->execute([1,'bad-inactive',1,BUSINESS_REPORT_TYPE,$contract,$contractHash,104,'bad']));
$definition = business_report_publish_definition($pdo, 'one', 101, 'initial definition');
report_mysql_check('owner publishes definition through runtime library', $definition['action'] === 'created');
report_mysql_throws('definition bytes are immutable in database', PDOException::class,
    fn() => $pdo->exec("UPDATE business_report_definition_versions SET reason='changed' WHERE id=" . (int)$definition['definition']['id']));
report_mysql_throws('definition rows cannot be deleted', PDOException::class,
    fn() => $pdo->exec('DELETE FROM business_report_definition_versions WHERE id=' . (int)$definition['definition']['id']));

$prepared = business_report_prepare_schedule(
    $pdo, 'one', 'weekly-one', 11, (int)$definition['definition']['id'],
    'reports@example.test', 'UTC', 7, '23:59:59', true, 101, 'prepare',
);
report_mysql_throws(
    'real MySQL enable refuses an unallowlisted schedule key',
    BusinessReportGateException::class,
    fn() => business_report_transition_schedule(
        $pdo,
        'one',
        'weekly-one',
        1,
        'active',
        102,
        'blocked enable',
        array_replace(report_mysql_config(), ['schedule_keys' => []]),
    ),
    'allowlisted',
);
$enabled = business_report_transition_schedule(
    $pdo, 'one', 'weekly-one', 1, 'active', 102, 'enable', report_mysql_config(),
);
report_mysql_check('schedule lifecycle is append-only disabled then active',
    (int)$prepared['schedule']['version_no'] === 1
    && (int)$enabled['schedule']['version_no'] === 2
    && $enabled['schedule']['status'] === 'active');
report_mysql_throws('schedule rows cannot be updated', PDOException::class,
    fn() => $pdo->exec("UPDATE business_report_schedule_versions SET reason='changed' WHERE id=" . (int)$enabled['schedule']['id']));
report_mysql_throws('direct schedule retarget is rejected by database', PDOException::class,
    fn() => $pdo->exec("INSERT INTO business_report_schedule_versions
        (tenant_id,schedule_key,version_no,definition_version_id,client_id,recipient_email,
         schedule_timezone,delivery_weekday,delivery_local_time,canary,status,created_by_user_id,reason)
        VALUES (1,'weekly-one',3," . (int)$definition['definition']['id'] . ",12,'reports@example.test',
         'UTC',7,'23:59:59',1,'disabled',101,'retarget')"));

$schedule = business_report_active_schedule($pdo, 'one', 'weekly-one');
$window = business_report_next_window($pdo, $schedule);
$testNow = time() + (14 * 86400);
$start = new DateTimeImmutable($window['period_start'], new DateTimeZone('UTC'));
$created = $start->modify('+1 day')->format('Y-m-d H:i:s');
$due = $start->modify('+1 day +1 hour')->format('Y-m-d H:i:s');
$response = $start->modify('+1 day +30 minutes')->format('Y-m-d H:i:s');
$pdo->prepare("INSERT INTO tickets
    (id,tenant_id,client_id,subject,status,priority,channel,sla_due_at,service_goal_target_id,created_at,updated_at)
    VALUES (1001,1,11,'private subject','open','normal','phone',?,NULL,?,?)")
    ->execute([$due,$created,$created]);
$pdo->prepare("INSERT INTO messages (ticket_id,author_name,kind,body,created_at)
    VALUES (1001,'Tech','tech','private body',?)")->execute([$response]);
$generated = business_report_generate($pdo, 'one', 'weekly-one', report_mysql_config(), $testNow, false, true);
$archiveId = (int)$generated['archive']['id'];
$archive = $pdo->query("SELECT * FROM business_report_archives WHERE id={$archiveId}")->fetch(PDO::FETCH_ASSOC);
report_mysql_check('native MySQL archive preserves exact bytes and a future test clock',
    is_array($archive)
    && business_report_archived_content($archive)['metrics'] === $generated['metrics']
    && (string) $archive['generated_at'] === gmdate('Y-m-d H:i:s', $testNow));
report_mysql_throws('database rejects archive text tampering', PDOException::class,
    fn() => $pdo->exec("UPDATE business_report_archives SET report_text='tampered' WHERE id={$archiveId}"));
report_mysql_throws('database rejects archive deletion', PDOException::class,
    fn() => $pdo->exec("DELETE FROM business_report_archives WHERE id={$archiveId}"));

$transportCalls = 0;
$delivery = business_report_deliver(
    $pdo,
    $archiveId,
    report_mysql_config(),
    function () use (&$transportCalls): array {
        $transportCalls++;
        return ['outcome' => 'submitted', 'provider_http' => 202, 'outcome_code' => 'graph_accepted'];
    },
    $testNow,
);
report_mysql_check('database permits only exact submitted transition evidence',
    $delivery['status'] === 'submitted' && $transportCalls === 1
    && (int)$pdo->query("SELECT COUNT(*) FROM business_report_delivery_attempts
                          WHERE status='submitted' AND provider_http=202 AND outcome_code='graph_accepted'")->fetchColumn() === 1);
$rejectedGenerated = business_report_generate(
    $pdo, 'one', 'weekly-one', report_mysql_config(), $testNow, false, true,
);
$rejectedArchiveId = (int)$rejectedGenerated['archive']['id'];
$rejectedDelivery = business_report_deliver(
    $pdo,
    $rejectedArchiveId,
    report_mysql_config(),
    fn(): array => [
        'outcome' => 'uncertain',
        'provider_http' => 403,
        'outcome_code' => 'graph_send_rejected',
    ],
    $testNow,
);
report_mysql_check('MySQL preserves definite Graph rejection as safe terminal evidence',
    $rejectedDelivery['status'] === 'uncertain'
    && (int)$pdo->query(
        "SELECT COUNT(*)
           FROM business_report_delivery_attempts a
           JOIN business_report_deliveries d
             ON d.tenant_id=a.tenant_id AND d.id=a.delivery_id
          WHERE d.archive_id={$rejectedArchiveId}
            AND a.status='uncertain'
            AND a.provider_http=403
            AND a.outcome_code='graph_send_rejected'"
    )->fetchColumn() === 1);
report_mysql_throws('terminal delivery cannot be rewritten', PDOException::class,
    fn() => $pdo->exec("UPDATE business_report_deliveries SET status='uncertain' WHERE archive_id={$archiveId}"));
report_mysql_throws('delivery evidence cannot be deleted', PDOException::class,
    fn() => $pdo->exec("DELETE FROM business_report_deliveries WHERE archive_id={$archiveId}"));

// Publish the reviewed v2 bytes after v1 and prove the correction-aware
// aggregate through native MySQL. Controlled session timestamps let the same
// immutable history be viewed at two exact report cutoffs.
$definitionV2 = business_report_publish_definition(
    $pdo,
    'one',
    101,
    'correction-aware definition',
    BUSINESS_REPORT_CONTRACT_VERSION_V2,
);
report_mysql_check(
    'native MySQL preserves immutable v1 and appends distinct v2 definition bytes',
    (int) $definitionV2['definition']['version_no'] === 2
        && business_report_definition_supported($definition['definition'])
        && business_report_definition_supported($definitionV2['definition'])
        && (int) $pdo->query(
            "SELECT COUNT(*) FROM business_report_definition_versions
              WHERE tenant_id=1 AND definition_key='weekly-client-service-summary'",
        )->fetchColumn() === 2,
);
$v2Prepared = business_report_prepare_schedule(
    $pdo,
    'one',
    'weekly-v2',
    13,
    (int) $definitionV2['definition']['id'],
    'reports@example.test',
    'UTC',
    7,
    '23:59:59',
    true,
    101,
    'prepare v2 fixture',
);
business_report_transition_schedule(
    $pdo,
    'one',
    'weekly-v2',
    (int) $v2Prepared['schedule']['version_no'],
    'active',
    101,
    'enable v2 fixture',
    report_mysql_config(),
);
$v2Schedule = business_report_active_schedule($pdo, 'one', 'weekly-v2');
$v2Window = business_report_next_window($pdo, $v2Schedule);
$v2WorkedAt = (new DateTimeImmutable(
    $v2Window['period_start'],
    new DateTimeZone('UTC'),
))->modify('+2 days')->format('Y-m-d\TH:i:s\Z');
$v2WorkedAtDb = str_replace(['T', 'Z'], [' ', ''], $v2WorkedAt);
$pdo->prepare("INSERT INTO tickets
    (id,tenant_id,client_id,subject,status,priority,channel,sla_due_at,
     service_goal_target_id,created_at,updated_at)
    VALUES
      (1002,1,12,'Other client fixture','open','normal','phone',?,NULL,?,?),
      (1003,1,13,'V2 isolated client fixture','open','normal','phone',?,NULL,?,?)")
    ->execute([
        $v2WorkedAtDb, $v2WorkedAtDb, $v2WorkedAtDb,
        $v2WorkedAtDb, $v2WorkedAtDb, $v2WorkedAtDb,
    ]);
$v2Clock = time();
$pdo->exec('SET timestamp = ' . $v2Clock);
$v2EntryA = report_mysql_create_approved_time(
    $pdo,
    1003,
    'suggestion.report-v2.entry-a.0001',
    $v2WorkedAt,
    60,
);
$v2EntryB = report_mysql_create_approved_time(
    $pdo,
    1003,
    'suggestion.report-v2.entry-b.0001',
    $v2WorkedAt,
    30,
);
$v2EntryC = report_mysql_create_approved_time(
    $pdo,
    1003,
    'suggestion.report-v2.entry-c.0001',
    $v2WorkedAt,
    20,
);
$otherClientEntry = report_mysql_create_approved_time(
    $pdo,
    1002,
    'suggestion.report-v2.other-client.0001',
    $v2WorkedAt,
    999,
);
$pdo->exec('SET timestamp = ' . ($v2Clock + 10));
time_entry_adjustment_create($pdo, 1, 101, 'owner', [
    'entry_id' => (int) $v2EntryA['id'],
    'adjustment_key' => 'adjustment.report-v2.entry-a.0001',
    'expected_version' => 0,
    'effective_minutes' => 50,
    'effective_billable' => true,
    'reason' => 'First private v2 fixture reason',
]);
time_entry_adjustment_create($pdo, 1, 101, 'owner', [
    'entry_id' => (int) $v2EntryC['id'],
    'adjustment_key' => 'adjustment.report-v2.entry-c.0001',
    'expected_version' => 0,
    'effective_minutes' => 20,
    'effective_billable' => false,
    'reason' => 'Private billable removal reason',
]);
time_entry_adjustment_create($pdo, 1, 101, 'owner', [
    'entry_id' => (int) $otherClientEntry['id'],
    'adjustment_key' => 'adjustment.report-v2.other-client.0001',
    'expected_version' => 0,
    'effective_minutes' => 1,
    'effective_billable' => true,
    'reason' => 'Other client reason',
]);
$v2FirstAdjustmentIdCutoff = business_report_adjustment_id_cutoff($pdo, 1);
$pdo->exec('SET timestamp = ' . ($v2Clock + 20));
time_entry_adjustment_create($pdo, 1, 102, 'admin', [
    'entry_id' => (int) $v2EntryA['id'],
    'adjustment_key' => 'adjustment.report-v2.entry-a.0002',
    'expected_version' => 1,
    'effective_minutes' => 40,
    'effective_billable' => true,
    'reason' => 'Second private v2 fixture reason',
]);
$v2SecondAdjustmentIdCutoff = business_report_adjustment_id_cutoff($pdo, 1);
$v2AtFirstSlip = business_report_metrics(
    $pdo,
    $v2Schedule,
    $v2Window['period_start'],
    $v2Window['period_end'],
    gmdate('Y-m-d H:i:s', $v2Clock + 15),
    $v2FirstAdjustmentIdCutoff,
);
$v2AtSecondSlip = business_report_metrics(
    $pdo,
    $v2Schedule,
    $v2Window['period_start'],
    $v2Window['period_end'],
    gmdate('Y-m-d H:i:s', $v2Clock + 30),
    $v2SecondAdjustmentIdCutoff,
);
report_mysql_check(
    'native MySQL v2 honors as-of cutoff client scope and one-row-per-entry totals',
    $v2AtFirstSlip['approved_billable_time']['adjustment_id_cutoff']
            === $v2FirstAdjustmentIdCutoff
        && $v2AtFirstSlip['approved_billable_time']['minutes'] === 80
        && $v2AtFirstSlip['approved_billable_time']['original_approved_billable_minutes'] === 110
        && $v2AtFirstSlip['approved_billable_time']['net_adjustment_minutes'] === -30
        && $v2AtFirstSlip['approved_billable_time']['entries_with_adjustments_applied'] === 2
        && $v2AtFirstSlip['approved_billable_time']['adjustment_slips_applied'] === 2
        && $v2AtSecondSlip['approved_billable_time']['adjustment_id_cutoff']
            === $v2SecondAdjustmentIdCutoff
        && $v2AtSecondSlip['approved_billable_time']['minutes'] === 70
        && $v2AtSecondSlip['approved_billable_time']['net_adjustment_minutes'] === -40
        && $v2AtSecondSlip['approved_billable_time']['entries_with_adjustments_applied'] === 2
        && $v2AtSecondSlip['approved_billable_time']['adjustment_slips_applied'] === 3,
);
$v2Generated = business_report_generate(
    $pdo,
    'one',
    'weekly-v2',
    report_mysql_config(),
    $testNow,
    false,
    true,
);
$v2Archive = $pdo->query(
    'SELECT * FROM business_report_archives WHERE id=' . (int) $v2Generated['archive']['id'],
)->fetch(PDO::FETCH_ASSOC);
report_mysql_check(
    'native MySQL archives v2 adjustment totals without exposing reasons',
    is_array($v2Archive)
        && $v2Generated['metrics']['approved_billable_time']['minutes'] === 70
        && business_report_archived_content($v2Archive)['metrics'] === $v2Generated['metrics']
        && !str_contains((string) $v2Archive['report_text'], 'private v2 fixture reason')
        && !str_contains((string) $v2Archive['metrics_json'], 'private v2 fixture reason'),
);
$forgedPeriodStart = (new DateTimeImmutable(
    $v2Window['period_start'],
    new DateTimeZone('UTC'),
))->modify('-14 days')->format('Y-m-d H:i:s');
$forgedPeriodEnd = (new DateTimeImmutable(
    $v2Window['period_end'],
    new DateTimeZone('UTC'),
))->modify('-14 days')->format('Y-m-d H:i:s');
$forgedGeneratedAt = gmdate('Y-m-d H:i:s', $v2Clock + 30);
$forgedV2Metrics = $v2AtSecondSlip;
$forgedV2Metrics['period']['start_utc'] = str_replace(' ', 'T', $forgedPeriodStart) . 'Z';
$forgedV2Metrics['period']['end_utc_exclusive'] = str_replace(' ', 'T', $forgedPeriodEnd) . 'Z';
$forgedV2Metrics['generated_at'] = str_replace(' ', 'T', $forgedGeneratedAt) . 'Z';
$forgedV2MetricsJson = business_report_metrics_json($forgedV2Metrics);
$forgedV2Text = "Private adjustment reason: secret\nInvoice has been posted\n";
$forgedV2Hash = business_report_content_sha256_from_json($forgedV2MetricsJson, $forgedV2Text);
$forgedArchiveInsert = $pdo->prepare(
    'INSERT INTO business_report_archives
        (tenant_id,client_id,schedule_key,schedule_version_id,definition_version_id,
         period_start,period_end,generated_at,metrics_json,report_text,content_sha256)
     VALUES (?,?,?,?,?,?,?,?,?,?,?)'
);
$forgedArchiveInsert->execute([
    1,
    13,
    'weekly-v2',
    (int) $v2Schedule['id'],
    (int) $v2Schedule['definition_version_id'],
    $forgedPeriodStart,
    $forgedPeriodEnd,
    $forgedGeneratedAt,
    $forgedV2MetricsJson,
    $forgedV2Text,
    $forgedV2Hash,
]);
$forgedArchiveId = (int) $pdo->lastInsertId();
$forgedDeliveryInsert = $pdo->prepare(
    "INSERT INTO business_report_deliveries
        (tenant_id,archive_id,schedule_version_id,recipient_email,status)
     VALUES (1,?,?,?,'pending')"
);
$forgedDeliveryInsert->execute([
    $forgedArchiveId,
    (int) $v2Schedule['id'],
    'reports@example.test',
]);
$forgedTransportCalls = 0;
$forgedDelivery = function () use (
    $pdo,
    $forgedArchiveId,
    $testNow,
    &$forgedTransportCalls,
): array {
    return business_report_deliver(
        $pdo,
        $forgedArchiveId,
        report_mysql_config(),
        function () use (&$forgedTransportCalls): array {
            $forgedTransportCalls++;
            return ['outcome' => 'submitted', 'provider_http' => 202, 'outcome_code' => 'graph_accepted'];
        },
        $testNow,
    );
};
report_mysql_throws(
    'native MySQL self-hashed noncanonical archive is refused before transport',
    BusinessReportConflictException::class,
    $forgedDelivery,
    'not canonical',
);
report_mysql_check(
    'native MySQL private and financial forged text never reaches transport',
    $forgedTransportCalls === 0,
);

$mysqlAdversarialMetricKinds = [
    'newline text injection',
    'unknown nested adjustment_reason',
    'unknown secret and financial fields',
    'integer string type confusion',
    'out-of-range integer',
    'Unicode display-direction control',
    'duplicate JSON member names',
    'hidden raw JSON whitespace bytes',
];
foreach ($mysqlAdversarialMetricKinds as $index => $kind) {
    $daysBefore = 21 + ($index * 7);
    $periodStart = (new DateTimeImmutable(
        $v2Window['period_start'],
        new DateTimeZone('UTC'),
    ))->modify("-{$daysBefore} days")->format('Y-m-d H:i:s');
    $periodEnd = (new DateTimeImmutable(
        $v2Window['period_end'],
        new DateTimeZone('UTC'),
    ))->modify("-{$daysBefore} days")->format('Y-m-d H:i:s');
    $generatedAt = gmdate('Y-m-d H:i:s', $v2Clock + 30);
    $metrics = $v2AtSecondSlip;
    $metrics['period']['start_utc'] = str_replace(' ', 'T', $periodStart) . 'Z';
    $metrics['period']['end_utc_exclusive'] = str_replace(' ', 'T', $periodEnd) . 'Z';
    $metrics['generated_at'] = str_replace(' ', 'T', $generatedAt) . 'Z';
    $text = business_report_text($metrics);

    if ($kind === 'newline text injection') {
        $opened = (string) $metrics['tickets']['opened'];
        $metrics['tickets']['opened'] = $opened . "\nInvoice has been posted";
        $text = str_replace(
            "Tickets opened: {$opened}",
            'Tickets opened: ' . $metrics['tickets']['opened'],
            $text,
        );
    } elseif ($kind === 'unknown nested adjustment_reason') {
        $metrics['approved_billable_time']['adjustment_reason'] =
            'private native MySQL reason';
    } elseif ($kind === 'unknown secret and financial fields') {
        $metrics['invoice_status'] = 'posted';
        $metrics['graph_client_secret'] = 'must never be archived';
    } elseif ($kind === 'integer string type confusion') {
        $metrics['tickets']['resolved'] = (string) $metrics['tickets']['resolved'];
    } elseif ($kind === 'out-of-range integer') {
        $oldOpened = (string) $metrics['tickets']['opened'];
        $metrics['tickets']['opened'] = BUSINESS_REPORT_ARCHIVE_MAX_COUNT + 1;
        $text = str_replace(
            "Tickets opened: {$oldOpened}",
            'Tickets opened: ' . $metrics['tickets']['opened'],
            $text,
        );
    } elseif ($kind === 'Unicode display-direction control') {
        $opened = (string) $metrics['tickets']['opened'];
        $metrics['tickets']['opened'] = $opened . "\u{202E}hidden";
        $text = str_replace(
            "Tickets opened: {$opened}",
            'Tickets opened: ' . $metrics['tickets']['opened'],
            $text,
        );
    }

    $metricsJson = business_report_metrics_json($metrics);
    if ($kind === 'duplicate JSON member names') {
        $duplicateCount = 0;
        $metricsJson = preg_replace(
            '/\A\{"schema_version":1,/',
            '{"schema_version":1,"schema_version":1,',
            $metricsJson,
            1,
            $duplicateCount,
        );
        if (!is_string($metricsJson) || $duplicateCount !== 1) {
            throw new RuntimeException('Native MySQL duplicate JSON fixture was not exact.');
        }
    } elseif ($kind === 'hidden raw JSON whitespace bytes') {
        $metricsJson .= " \t\r\n";
    }
    $hash = business_report_content_sha256_from_json($metricsJson, $text);
    $forgedArchiveInsert->execute([
        1,
        13,
        'weekly-v2',
        (int) $v2Schedule['id'],
        (int) $v2Schedule['definition_version_id'],
        $periodStart,
        $periodEnd,
        $generatedAt,
        $metricsJson,
        $text,
        $hash,
    ]);
    $archiveId = (int) $pdo->lastInsertId();
    $archive = $pdo->query(
        'SELECT * FROM business_report_archives WHERE id=' . $archiveId,
    )->fetch(PDO::FETCH_ASSOC);
    report_mysql_throws(
        "native MySQL direct-insert reload refuses {$kind}",
        BusinessReportConflictException::class,
        fn() => business_report_archived_content(is_array($archive) ? $archive : []),
        'metric schema',
    );

    $forgedDeliveryInsert->execute([
        $archiveId,
        (int) $v2Schedule['id'],
        'reports@example.test',
    ]);
    $transportCalls = 0;
    $attemptsBefore = (int) $pdo->query(
        'SELECT COUNT(*) FROM business_report_delivery_attempts',
    )->fetchColumn();
    report_mysql_throws(
        "native MySQL direct-insert delivery refuses {$kind}",
        BusinessReportConflictException::class,
        fn() => business_report_deliver(
            $pdo,
            $archiveId,
            report_mysql_config(),
            function () use (&$transportCalls): array {
                $transportCalls++;
                return [
                    'outcome' => 'submitted',
                    'provider_http' => 202,
                    'outcome_code' => 'graph_accepted',
                ];
            },
            $testNow,
        ),
        'metric schema',
    );
    report_mysql_check(
        "native MySQL {$kind} makes zero transport or delivery-attempt calls",
        $transportCalls === 0
            && (int) $pdo->query(
                'SELECT COUNT(*) FROM business_report_delivery_attempts',
            )->fetchColumn() === $attemptsBefore
            && (string) $pdo->query(
                'SELECT status FROM business_report_deliveries WHERE archive_id=' . $archiveId,
            )->fetchColumn() === 'pending',
    );
}
$pdo->exec('SET timestamp = 0');

// The app-like identity gets reads plus only the inserts/transition updates the
// report runtime needs. It receives no DELETE or DDL privilege.
$runtimeUser = 'report_runtime_ci';
$runtimePass = 'safeharbor-report-ci-only';
$escapedDb = str_replace('`', '``', $database);
$server->exec("DROP USER IF EXISTS '{$runtimeUser}'@'%'");
$server->exec("CREATE USER '{$runtimeUser}'@'%' IDENTIFIED BY '{$runtimePass}'");
$server->exec("GRANT SELECT ON `{$escapedDb}`.* TO '{$runtimeUser}'@'%'");
foreach (['business_report_definition_versions','business_report_schedule_versions','business_report_archives'] as $table) {
    $server->exec("GRANT INSERT ON `{$escapedDb}`.`{$table}` TO '{$runtimeUser}'@'%'");
}
$server->exec("GRANT INSERT, UPDATE ON `{$escapedDb}`.`business_report_contact_scope_bindings` TO '{$runtimeUser}'@'%'");
$server->exec("GRANT UPDATE ON `{$escapedDb}`.`business_report_schedule_versions` TO '{$runtimeUser}'@'%'");
foreach (['business_report_deliveries','business_report_delivery_attempts'] as $table) {
    $server->exec("GRANT INSERT, UPDATE ON `{$escapedDb}`.`{$table}` TO '{$runtimeUser}'@'%'");
}
$runtime = new PDO($serverDsn . ";dbname={$database}", $runtimeUser, $runtimePass, $options);
$runtime->exec("SET time_zone = '+00:00'");
$grantText = implode("\n", $runtime->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN));
report_mysql_check('runtime grants contain no delete or DDL authority',
    !str_contains($grantText, 'DELETE')
    && !str_contains($grantText, 'DROP')
    && !str_contains($grantText, 'ALTER')
    && !str_contains($grantText, 'TRIGGER'));
report_mysql_throws('runtime cannot delete a report definition', PDOException::class,
    fn() => $runtime->exec('DELETE FROM business_report_definition_versions WHERE id=' . (int)$definition['definition']['id']));
report_mysql_throws('runtime cannot directly update an immutable schedule', PDOException::class,
    fn() => $runtime->exec("UPDATE business_report_schedule_versions SET reason='bad' WHERE id=" . (int)$enabled['schedule']['id']));
$runtimePrepared = business_report_prepare_schedule(
    $runtime, 'one', 'weekly-runtime', 11, (int)$definition['definition']['id'],
    'reports@example.test', 'UTC', 7, '23:59:59', true, 101, 'runtime prepare',
);
$runtimeEnabled = business_report_transition_schedule(
    $runtime, 'one', 'weekly-runtime', (int)$runtimePrepared['schedule']['version_no'],
    'active', 101, 'runtime enable', report_mysql_config(),
);
$runtimeGenerated = business_report_generate(
    $runtime, 'one', 'weekly-runtime', report_mysql_config(), $testNow, false, true,
);
$runtimeDelivered = business_report_deliver(
    $runtime,
    (int)$runtimeGenerated['archive']['id'],
    report_mysql_config(),
    fn(): array => ['outcome' => 'submitted', 'provider_http' => 202, 'outcome_code' => 'graph_accepted'],
    $testNow,
);
report_mysql_check('least-privilege runtime can append and finalize the controlled report lifecycle',
    $runtimeEnabled['schedule']['status'] === 'active'
    && $runtimeGenerated['action'] === 'created'
    && $runtimeDelivered['status'] === 'submitted');
$server->exec("DROP USER IF EXISTS '{$runtimeUser}'@'%'");

// Prove the tenant row is the first shared serialization point between a
// persisted report and an append-only approval adjustment. Two schedules keep
// the opposite lock-order outcomes independent.
$raceFixtures = [];
foreach ([
    'weekly-race-report' => [1101, 'suggestion.report-race.report.0001'],
    'weekly-race-adjustment' => [1102, 'suggestion.report-race.adjustment.0001'],
] as $raceScheduleKey => [$raceTicketId, $raceEntryKey]) {
    $racePrepared = business_report_prepare_schedule(
        $pdo,
        'one',
        $raceScheduleKey,
        11,
        (int) $definition['definition']['id'],
        'reports@example.test',
        'UTC',
        7,
        '23:59:59',
        true,
        101,
        'Prepare report-adjustment serialization fixture',
    );
    business_report_transition_schedule(
        $pdo,
        'one',
        $raceScheduleKey,
        (int) $racePrepared['schedule']['version_no'],
        'active',
        101,
        'Enable report-adjustment serialization fixture',
        report_mysql_config(),
    );
    $raceSchedule = business_report_active_schedule($pdo, 'one', $raceScheduleKey);
    $raceWindow = business_report_next_window($pdo, $raceSchedule);
    $raceWorkedAtInstant = (new DateTimeImmutable(
        $raceWindow['period_start'],
        new DateTimeZone('UTC'),
    ))->modify('+2 days');
    $raceWorkedAt = $raceWorkedAtInstant->format('Y-m-d\TH:i:s\Z');
    $raceDatabaseAt = $raceWorkedAtInstant->format('Y-m-d H:i:s');
    $pdo->prepare("INSERT INTO tickets
        (id,tenant_id,client_id,subject,status,priority,channel,sla_due_at,
         service_goal_target_id,created_at,updated_at)
        VALUES (?,1,11,'Report serialization fixture','open','normal','phone',?,NULL,?,?)")
        ->execute([$raceTicketId, $raceDatabaseAt, $raceDatabaseAt, $raceDatabaseAt]);
    $raceEntry = report_mysql_create_approved_time(
        $pdo,
        $raceTicketId,
        $raceEntryKey,
        $raceWorkedAt,
        30,
    );
    $raceFixtures[$raceScheduleKey] = [
        'schedule_id' => (int) $raceSchedule['id'],
        'entry_id' => (int) $raceEntry['id'],
        'period_end' => $raceWindow['period_end'],
    ];
}

// Two isolated v2 clients prove that the monotonic cutoff records the lock
// winner even when the report and adjustment share the exact database second.
$raceV2Fixtures = [];
foreach ([
    'weekly-race-v2-report' => [1103, 14, 'suggestion.report-race.v2-report.0001'],
    'weekly-race-v2-adjustment' => [1104, 15, 'suggestion.report-race.v2-adjustment.0001'],
] as $raceScheduleKey => [$raceTicketId, $raceClientId, $raceEntryKey]) {
    $racePrepared = business_report_prepare_schedule(
        $pdo,
        'one',
        $raceScheduleKey,
        $raceClientId,
        (int) $definitionV2['definition']['id'],
        'reports@example.test',
        'UTC',
        7,
        '23:59:59',
        true,
        101,
        'Prepare v2 report-adjustment serialization fixture',
    );
    business_report_transition_schedule(
        $pdo,
        'one',
        $raceScheduleKey,
        (int) $racePrepared['schedule']['version_no'],
        'active',
        101,
        'Enable v2 report-adjustment serialization fixture',
        report_mysql_config(),
    );
    $raceSchedule = business_report_active_schedule($pdo, 'one', $raceScheduleKey);
    $raceWindow = business_report_next_window($pdo, $raceSchedule);
    $raceWorkedAtInstant = (new DateTimeImmutable(
        $raceWindow['period_start'],
        new DateTimeZone('UTC'),
    ))->modify('+2 days');
    $raceWorkedAt = $raceWorkedAtInstant->format('Y-m-d\TH:i:s\Z');
    $raceDatabaseAt = $raceWorkedAtInstant->format('Y-m-d H:i:s');
    $pdo->prepare("INSERT INTO tickets
        (id,tenant_id,client_id,subject,status,priority,channel,sla_due_at,
         service_goal_target_id,created_at,updated_at)
        VALUES (?,1,?,'V2 report serialization fixture','open','normal','phone',?,NULL,?,?)")
        ->execute([
            $raceTicketId,
            $raceClientId,
            $raceDatabaseAt,
            $raceDatabaseAt,
            $raceDatabaseAt,
        ]);
    $raceEntry = report_mysql_create_approved_time(
        $pdo,
        $raceTicketId,
        $raceEntryKey,
        $raceWorkedAt,
        30,
    );
    $raceV2Fixtures[$raceScheduleKey] = [
        'schedule_id' => (int) $raceSchedule['id'],
        'entry_id' => (int) $raceEntry['id'],
        'period_start' => $raceWindow['period_start'],
        'period_end' => $raceWindow['period_end'],
    ];
}

$raceToken = bin2hex(random_bytes(32));
$pdo->exec("CREATE TABLE report_race_test_marker (
    id TINYINT UNSIGNED NOT NULL,
    worker_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT ck_report_race_marker_id CHECK (id=1)
) ENGINE=InnoDB");
$raceMarker = $pdo->prepare(
    'INSERT INTO report_race_test_marker (id,worker_token) VALUES (1,?)'
);
$raceMarker->execute([$raceToken]);

// Report-first: hold the schedule row so the report pauses only after taking
// the tenant row. The adjustment must then wait on that tenant, not slip in
// ahead of the report's adjustment guard.
$reportFirstFixture = $raceFixtures['weekly-race-report'];
$scheduleHolder = new PDO($serverDsn . ";dbname={$database}", $user, $pass, $options);
$scheduleHolder->exec("SET time_zone = '+00:00'");
$scheduleHolder->beginTransaction();
$heldSchedule = $scheduleHolder->prepare(
    'SELECT id FROM business_report_schedule_versions WHERE tenant_id=1 AND id=? FOR UPDATE'
);
$heldSchedule->execute([$reportFirstFixture['schedule_id']]);
if ((int) $heldSchedule->fetchColumn() !== $reportFirstFixture['schedule_id']) {
    throw new RuntimeException('Report-first schedule lock target was not found.');
}
$reportFirstWorker = report_mysql_start_race_worker(
    $raceToken,
    'report',
    'weekly-race-report',
    $reportFirstFixture['entry_id'],
    $testNow,
    true,
);
$reportQueuedOnSchedule = $reportFirstWorker['ready']
    && report_mysql_waiters($server, $database, 'business_report_schedule_versions', 1);
$adjustmentAfterReportWorker = report_mysql_start_race_worker(
    $raceToken,
    'adjustment',
    'weekly-race-report',
    $reportFirstFixture['entry_id'],
    $testNow,
    true,
);
$adjustmentQueuedOnTenant = $adjustmentAfterReportWorker['ready']
    && report_mysql_waiters($server, $database, 'tenants', 1);
$adjustmentAbsentBeforeReportCommit = (int) $pdo->query(
    'SELECT COUNT(*) FROM time_entry_approval_adjustments WHERE tenant_id=1'
        . ' AND time_entry_id=' . $reportFirstFixture['entry_id']
)->fetchColumn() === 0;
$scheduleHolder->commit();
$reportFirstResult = report_mysql_finish_race_worker($reportFirstWorker);
$adjustmentAfterReportResult = report_mysql_finish_race_worker($adjustmentAfterReportWorker);
report_mysql_check('report-first worker holds tenant before waiting on its schedule row',
    $reportQueuedOnSchedule
    && $adjustmentQueuedOnTenant
    && $adjustmentAbsentBeforeReportCommit
    && $reportFirstResult['ready']
    && $adjustmentAfterReportResult['ready']
    && !$reportFirstResult['timed_out']
    && !$adjustmentAfterReportResult['timed_out']
    && $reportFirstResult['exit'] === 0
    && $adjustmentAfterReportResult['exit'] === 0
    && trim($reportFirstResult['stderr']) === ''
    && trim($adjustmentAfterReportResult['stderr']) === '');
report_mysql_check('report-first race archives once before the later adjustment is appended',
    (int) ($reportFirstResult['payload']['status'] ?? 0) === 201
    && ($reportFirstResult['payload']['action'] ?? null) === 'created'
    && (int) ($adjustmentAfterReportResult['payload']['status'] ?? 0) === 201
    && (int) $pdo->query("SELECT COUNT(*) FROM business_report_archives
          WHERE tenant_id=1 AND schedule_key='weekly-race-report'")->fetchColumn() === 1
    && (int) $pdo->query("SELECT COUNT(*) FROM time_entry_approval_adjustments
          WHERE tenant_id=1 AND time_entry_id={$reportFirstFixture['entry_id']}")->fetchColumn() === 1);

// Adjustment-first: hold the tenant, start a no-due report with a deliberately
// old requested cutoff, and only then insert the slip. The report must wait at
// the tenant row, choose DB UTC after that wait instead of backdating, see the
// committed slip, and refuse definition v1.
$adjustmentFirstFixture = $raceFixtures['weekly-race-adjustment'];
$adjustmentHolder = new PDO($serverDsn . ";dbname={$database}", $user, $pass, $options);
$adjustmentHolder->exec("SET time_zone = '+00:00'");
$adjustmentHolder->beginTransaction();
$heldTenant = $adjustmentHolder->query('SELECT id FROM tenants WHERE id=1 FOR UPDATE');
if ((int) $heldTenant->fetchColumn() !== 1) {
    throw new RuntimeException('Adjustment-first tenant lock target was not found.');
}
$olderRaceCutoff = strtotime($adjustmentFirstFixture['period_end'] . ' UTC');
if ($olderRaceCutoff === false) {
    throw new RuntimeException('Adjustment-first old cutoff is invalid.');
}
$adjustmentFirstReportWorker = report_mysql_start_race_worker(
    $raceToken,
    'report',
    'weekly-race-adjustment',
    $adjustmentFirstFixture['entry_id'],
    $olderRaceCutoff,
    false,
);
$reportQueuedOnTenant = $adjustmentFirstReportWorker['ready']
    && report_mysql_waiters($server, $database, 'tenants', 1);
$adjustmentAbsentWhenReportStarted = (int) $pdo->query(
    "SELECT COUNT(*) FROM time_entry_approval_adjustments
      WHERE tenant_id=1 AND time_entry_id={$adjustmentFirstFixture['entry_id']}"
)->fetchColumn() === 0;
time_entry_adjustment_create($adjustmentHolder, 1, 101, 'owner', [
    'entry_id' => $adjustmentFirstFixture['entry_id'],
    'adjustment_key' => 'adjustment.report-race.adjustment.0001',
    'expected_version' => 0,
    'effective_minutes' => 20,
    'effective_billable' => true,
    'reason' => 'Adjustment wins report serialization proof',
]);
$adjustmentHolder->commit();
$adjustmentFirstReportResult = report_mysql_finish_race_worker($adjustmentFirstReportWorker);
report_mysql_check('adjustment-first report worker waits on the shared tenant lock',
    $reportQueuedOnTenant
    && $adjustmentAbsentWhenReportStarted
    && $adjustmentFirstReportResult['ready']
    && !$adjustmentFirstReportResult['timed_out']
    && $adjustmentFirstReportResult['exit'] === 0
    && trim($adjustmentFirstReportResult['stderr']) === '');
report_mysql_check('adjustment-first race makes definition v1 refuse the archive',
    (int) ($adjustmentFirstReportResult['payload']['status'] ?? 0) === 409
    && ($adjustmentFirstReportResult['payload']['error'] ?? null) === 'adjusted_v1'
    && (int) $pdo->query("SELECT COUNT(*) FROM business_report_archives
          WHERE tenant_id=1 AND schedule_key='weekly-race-adjustment'")->fetchColumn() === 0
    && (int) $pdo->query("SELECT COUNT(*) FROM time_entry_approval_adjustments
          WHERE tenant_id=1 AND time_entry_id={$adjustmentFirstFixture['entry_id']}")->fetchColumn() === 1);

// V2 report-first at one fixed database second: the report captures its
// monotonic prefix before the waiting adjustment receives a higher id.
$v2ReportFirstFixture = $raceV2Fixtures['weekly-race-v2-report'];
$v2ScheduleHolder = new PDO($serverDsn . ";dbname={$database}", $user, $pass, $options);
$v2ScheduleHolder->exec("SET time_zone = '+00:00'");
$v2ScheduleHolder->beginTransaction();
$v2HeldSchedule = $v2ScheduleHolder->prepare(
    'SELECT id FROM business_report_schedule_versions WHERE tenant_id=1 AND id=? FOR UPDATE'
);
$v2HeldSchedule->execute([$v2ReportFirstFixture['schedule_id']]);
if ((int) $v2HeldSchedule->fetchColumn() !== $v2ReportFirstFixture['schedule_id']) {
    throw new RuntimeException('V2 report-first schedule lock target was not found.');
}
$v2ReportFirstWorker = report_mysql_start_race_worker(
    $raceToken,
    'report',
    'weekly-race-v2-report',
    $v2ReportFirstFixture['entry_id'],
    $testNow,
    true,
);
$v2ReportQueuedOnSchedule = $v2ReportFirstWorker['ready']
    && report_mysql_waiters($server, $database, 'business_report_schedule_versions', 1);
$v2AdjustmentAfterReportWorker = report_mysql_start_race_worker(
    $raceToken,
    'adjustment',
    'weekly-race-v2-report',
    $v2ReportFirstFixture['entry_id'],
    $testNow,
    true,
);
$v2AdjustmentQueuedOnTenant = $v2AdjustmentAfterReportWorker['ready']
    && report_mysql_waiters($server, $database, 'tenants', 1);
$v2ScheduleHolder->commit();
$v2ReportFirstResult = report_mysql_finish_race_worker($v2ReportFirstWorker);
$v2AdjustmentAfterReportResult = report_mysql_finish_race_worker($v2AdjustmentAfterReportWorker);
report_mysql_check(
    'v2 report-first workers serialize at the tenant inside one database second',
    $v2ReportQueuedOnSchedule
        && $v2AdjustmentQueuedOnTenant
        && $v2ReportFirstResult['ready']
        && $v2AdjustmentAfterReportResult['ready']
        && !$v2ReportFirstResult['timed_out']
        && !$v2AdjustmentAfterReportResult['timed_out']
        && $v2ReportFirstResult['exit'] === 0
        && $v2AdjustmentAfterReportResult['exit'] === 0
        && trim($v2ReportFirstResult['stderr']) === ''
        && trim($v2AdjustmentAfterReportResult['stderr']) === ''
        && (int) ($v2ReportFirstResult['payload']['status'] ?? 0) === 201
        && (int) ($v2AdjustmentAfterReportResult['payload']['status'] ?? 0) === 201,
);
$v2ReportFirstArchiveId = (int) ($v2ReportFirstResult['payload']['archive_id'] ?? 0);
$v2ReportFirstArchive = $pdo->query(
    "SELECT * FROM business_report_archives WHERE tenant_id=1 AND id={$v2ReportFirstArchiveId}"
)->fetch(PDO::FETCH_ASSOC);
$v2ReportFirstContent = is_array($v2ReportFirstArchive)
    ? business_report_archived_content($v2ReportFirstArchive)
    : ['metrics' => []];
$v2ReportFirstMetrics = $v2ReportFirstContent['metrics'];
$v2ReportFirstAdjustment = $pdo->query(
    'SELECT id,created_at FROM time_entry_approval_adjustments WHERE tenant_id=1'
        . ' AND time_entry_id=' . $v2ReportFirstFixture['entry_id']
)->fetch(PDO::FETCH_ASSOC);
report_mysql_check(
    'v2 report-first archive excludes the same-second later adjustment by durable id cutoff',
    is_array($v2ReportFirstArchive)
        && is_array($v2ReportFirstAdjustment)
        && (string) $v2ReportFirstArchive['generated_at'] === (string) $v2ReportFirstAdjustment['created_at']
        && (int) ($v2ReportFirstMetrics['approved_billable_time']['adjustment_id_cutoff'] ?? -1)
            < (int) $v2ReportFirstAdjustment['id']
        && (int) ($v2ReportFirstMetrics['approved_billable_time']['minutes'] ?? -1) === 30
        && (int) ($v2ReportFirstMetrics['approved_billable_time']['entries_with_adjustments_applied'] ?? -1) === 0,
);
$v2ReportFirstSchedule = business_report_active_schedule($pdo, 'one', 'weekly-race-v2-report');
$v2ReportFirstRecomputed = business_report_metrics(
    $pdo,
    $v2ReportFirstSchedule,
    $v2ReportFirstFixture['period_start'],
    $v2ReportFirstFixture['period_end'],
    (string) $v2ReportFirstArchive['generated_at'],
    (int) $v2ReportFirstMetrics['approved_billable_time']['adjustment_id_cutoff'],
);
report_mysql_check(
    'v2 report-first archived cutoff reproduces exact metrics after the later slip exists',
    $v2ReportFirstRecomputed === $v2ReportFirstMetrics,
);

// V2 adjustment-first at that same fixed second: the report waits, then its
// captured prefix includes the committed adjustment even though timestamps tie.
$v2AdjustmentFirstFixture = $raceV2Fixtures['weekly-race-v2-adjustment'];
$v2AdjustmentHolder = new PDO($serverDsn . ";dbname={$database}", $user, $pass, $options);
$v2AdjustmentHolder->exec("SET time_zone = '+00:00'");
$v2AdjustmentHolder->exec('SET timestamp = ' . $testNow);
$v2AdjustmentHolder->beginTransaction();
$v2HeldTenant = $v2AdjustmentHolder->query('SELECT id FROM tenants WHERE id=1 FOR UPDATE');
if ((int) $v2HeldTenant->fetchColumn() !== 1) {
    throw new RuntimeException('V2 adjustment-first tenant lock target was not found.');
}
$v2AdjustmentFirstReportWorker = report_mysql_start_race_worker(
    $raceToken,
    'report',
    'weekly-race-v2-adjustment',
    $v2AdjustmentFirstFixture['entry_id'],
    $testNow,
    true,
);
$v2ReportQueuedOnTenant = $v2AdjustmentFirstReportWorker['ready']
    && report_mysql_waiters($server, $database, 'tenants', 1);
$v2AdjustmentFirst = time_entry_adjustment_create($v2AdjustmentHolder, 1, 101, 'owner', [
    'entry_id' => $v2AdjustmentFirstFixture['entry_id'],
    'adjustment_key' => 'adjustment.report-race.v2-adjustment.0001',
    'expected_version' => 0,
    'effective_minutes' => 20,
    'effective_billable' => true,
    'reason' => 'V2 adjustment wins same-second serialization proof',
]);
$v2AdjustmentHolder->commit();
$v2AdjustmentFirstReportResult = report_mysql_finish_race_worker($v2AdjustmentFirstReportWorker);
report_mysql_check(
    'v2 adjustment-first report waits and completes inside the same database second',
    $v2ReportQueuedOnTenant
        && $v2AdjustmentFirstReportResult['ready']
        && !$v2AdjustmentFirstReportResult['timed_out']
        && $v2AdjustmentFirstReportResult['exit'] === 0
        && trim($v2AdjustmentFirstReportResult['stderr']) === ''
        && (int) ($v2AdjustmentFirstReportResult['payload']['status'] ?? 0) === 201,
);
$v2AdjustmentFirstArchiveId = (int) ($v2AdjustmentFirstReportResult['payload']['archive_id'] ?? 0);
$v2AdjustmentFirstArchive = $pdo->query(
    "SELECT * FROM business_report_archives WHERE tenant_id=1 AND id={$v2AdjustmentFirstArchiveId}"
)->fetch(PDO::FETCH_ASSOC);
$v2AdjustmentFirstContent = is_array($v2AdjustmentFirstArchive)
    ? business_report_archived_content($v2AdjustmentFirstArchive)
    : ['metrics' => []];
$v2AdjustmentFirstMetrics = $v2AdjustmentFirstContent['metrics'];
$v2AdjustmentFirstRow = $pdo->query(
    'SELECT id,created_at FROM time_entry_approval_adjustments WHERE tenant_id=1'
        . ' AND time_entry_id=' . $v2AdjustmentFirstFixture['entry_id']
)->fetch(PDO::FETCH_ASSOC);
report_mysql_check(
    'v2 adjustment-first archive includes the same-second winner inside its durable id cutoff',
    is_array($v2AdjustmentFirstArchive)
        && is_array($v2AdjustmentFirstRow)
        && (int) $v2AdjustmentFirst['id'] === (int) $v2AdjustmentFirstRow['id']
        && (string) $v2AdjustmentFirstArchive['generated_at'] === (string) $v2AdjustmentFirstRow['created_at']
        && (int) ($v2AdjustmentFirstMetrics['approved_billable_time']['adjustment_id_cutoff'] ?? -1)
            >= (int) $v2AdjustmentFirstRow['id']
        && (int) ($v2AdjustmentFirstMetrics['approved_billable_time']['minutes'] ?? -1) === 20
        && (int) ($v2AdjustmentFirstMetrics['approved_billable_time']['entries_with_adjustments_applied'] ?? -1) === 1,
);
$v2AdjustmentFirstSchedule = business_report_active_schedule(
    $pdo,
    'one',
    'weekly-race-v2-adjustment',
);
$v2AdjustmentFirstRecomputed = business_report_metrics(
    $pdo,
    $v2AdjustmentFirstSchedule,
    $v2AdjustmentFirstFixture['period_start'],
    $v2AdjustmentFirstFixture['period_end'],
    (string) $v2AdjustmentFirstArchive['generated_at'],
    (int) $v2AdjustmentFirstMetrics['approved_billable_time']['adjustment_id_cutoff'],
);
report_mysql_check(
    'v2 adjustment-first archived cutoff reproduces exact included-adjustment metrics',
    $v2AdjustmentFirstRecomputed === $v2AdjustmentFirstMetrics,
);

$countsBeforeReplay = $pdo->query(
    "SELECT CONCAT(
      (SELECT COUNT(*) FROM business_report_definition_versions),':',
      (SELECT COUNT(*) FROM business_report_schedule_versions),':',
      (SELECT COUNT(*) FROM business_report_archives),':',
      (SELECT COUNT(*) FROM business_report_deliveries),':',
      (SELECT COUNT(*) FROM business_report_delivery_attempts))"
)->fetchColumn();
report_mysql_execute_file($pdo, __DIR__ . '/../db/migrations/013_business_reports.sql');
report_mysql_execute_file($pdo, __DIR__ . '/../db/migrations/019_client_report_contact_evidence.sql');
$countsAfterReplay = $pdo->query(
    "SELECT CONCAT(
      (SELECT COUNT(*) FROM business_report_definition_versions),':',
      (SELECT COUNT(*) FROM business_report_schedule_versions),':',
      (SELECT COUNT(*) FROM business_report_archives),':',
      (SELECT COUNT(*) FROM business_report_deliveries),':',
      (SELECT COUNT(*) FROM business_report_delivery_attempts))"
)->fetchColumn();
report_mysql_check('migration replay preserves all report history', $countsAfterReplay === $countsBeforeReplay);

if ($failures > 0) {
    fwrite(STDERR, "{$failures} of {$checks} MySQL business report checks failed.\n");
    exit(1);
}
echo "Business report MySQL: {$checks}/{$checks} passed.\n";
