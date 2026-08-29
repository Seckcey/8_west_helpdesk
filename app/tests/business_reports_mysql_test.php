<?php
/** MySQL 8 integration coverage for migration 013 and report state guards. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);

require_once __DIR__ . '/../lib/business_reports.php';

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
        'schedule_keys' => ['weekly-one', 'weekly-runtime'],
        'tenant_slugs' => ['one'],
        'client_keys' => ['safeharbor-client:11'],
        'recipient_emails' => ['reports@example.test'],
        'lease_seconds' => 120,
    ];
}

report_mysql_reset($pdo);
report_mysql_execute_file($pdo, __DIR__ . '/../db/schema.sql');
report_mysql_check('fresh schema creates nine report tables including tenant and client ID evidence',
    (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema=DATABASE() AND table_name LIKE 'business_report_%'"
    )->fetchColumn() === 9);
report_mysql_execute_file($pdo, __DIR__ . '/../db/migrations/013_business_reports.sql');
report_mysql_check('migration 013 replays over exact fresh-schema objects', true);
report_mysql_check('report tables retain exact per-table column counts',
    $pdo->query(
        "SELECT CONCAT(table_name,':',COUNT(*)) shape
           FROM information_schema.columns
          WHERE table_schema=DATABASE() AND table_name LIKE 'business_report_%'
          GROUP BY table_name ORDER BY table_name"
    )->fetchAll(PDO::FETCH_COLUMN) === [
        'business_report_archives:13',
        'business_report_definition_versions:10',
        'business_report_deliveries:12',
        'business_report_delivery_attempts:10',
        'business_report_id_client_bindings:7',
        'business_report_id_client_contact_snapshots:13',
        'business_report_id_contact_snapshots:12',
        'business_report_id_tenant_bindings:6',
        'business_report_schedule_versions:15',
    ]);
report_mysql_check('all twenty-one report triggers are installed',
    (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.triggers
          WHERE trigger_schema=DATABASE() AND trigger_name LIKE 'trg_business_report_%'"
    )->fetchColumn() === 21);
report_mysql_check('all report relationships are tenant-scoped',
    (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.table_constraints
          WHERE constraint_schema=DATABASE() AND constraint_type='FOREIGN KEY'
            AND table_name LIKE 'business_report_%'"
    )->fetchColumn() === 29);
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
    (11,1,'Client One'),(12,1,'Client Twelve'),(22,2,'Client Two')");
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
report_mysql_check('native MySQL create-reload verifies exact archive bytes',
    is_array($archive)
    && business_report_archived_content($archive)['metrics'] === $generated['metrics']);
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
report_mysql_throws('terminal delivery cannot be rewritten', PDOException::class,
    fn() => $pdo->exec("UPDATE business_report_deliveries SET status='uncertain' WHERE archive_id={$archiveId}"));
report_mysql_throws('delivery evidence cannot be deleted', PDOException::class,
    fn() => $pdo->exec("DELETE FROM business_report_deliveries WHERE archive_id={$archiveId}"));

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

$countsBeforeReplay = $pdo->query(
    "SELECT CONCAT(
      (SELECT COUNT(*) FROM business_report_definition_versions),':',
      (SELECT COUNT(*) FROM business_report_schedule_versions),':',
      (SELECT COUNT(*) FROM business_report_archives),':',
      (SELECT COUNT(*) FROM business_report_deliveries),':',
      (SELECT COUNT(*) FROM business_report_delivery_attempts))"
)->fetchColumn();
report_mysql_execute_file($pdo, __DIR__ . '/../db/migrations/013_business_reports.sql');
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
