<?php
/**
 * Disposable MySQL 8 proof for migration 020 and concurrent adjustment
 * versioning. Never point this suite at a persistent database server.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
if (getenv('SAFEHARBOR_TIME_ADJUSTMENT_TEST_DISPOSABLE_SERVER') !== '1') {
    fwrite(STDERR, "Refusing adjustment MySQL test without disposable-server acknowledgement.\n");
    exit(2);
}

$databaseBase = getenv('SAFEHARBOR_TIME_ADJUSTMENT_TEST_DB');
if (!is_string($databaseBase)
    || preg_match('/\Asafeharbor_time_adjustment_test(?:_[a-z0-9_]+)?\z/D', $databaseBase) !== 1
    || strlen($databaseBase) > 44
) {
    fwrite(STDERR, "Refusing destructive adjustment-test database base.\n");
    exit(2);
}
$host = getenv('SAFEHARBOR_TIME_ADJUSTMENT_TEST_HOST') ?: '127.0.0.1';
$portText = getenv('SAFEHARBOR_TIME_ADJUSTMENT_TEST_PORT') ?: '3306';
$operatorUser = getenv('SAFEHARBOR_TIME_ADJUSTMENT_TEST_USER') ?: 'root';
$operatorPass = getenv('SAFEHARBOR_TIME_ADJUSTMENT_TEST_PASS') ?: '';
if (!is_string($host) || trim($host) === ''
    || !is_string($portText) || !ctype_digit($portText)
    || (int) $portText < 1 || (int) $portText > 65535
    || !is_string($operatorUser) || $operatorUser === ''
) {
    fwrite(STDERR, "Refusing invalid adjustment-test connection settings.\n");
    exit(2);
}

$serverDsn = "mysql:host={$host};port={$portText};charset=utf8mb4";
$pdoOptions = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::ATTR_CASE => PDO::CASE_LOWER,
];

require_once __DIR__ . '/../lib/time_entry_adjustments.php';

// Two independent workers race from the same expected version. Their database
// target and scenario are bound to a random marker created by the parent.
if (getenv('SAFEHARBOR_TIME_ADJUSTMENT_RACE_WORKER') === '1') {
    $runId = getenv('SAFEHARBOR_TIME_ADJUSTMENT_RACE_RUN_ID');
    $token = getenv('SAFEHARBOR_TIME_ADJUSTMENT_RACE_TOKEN');
    $entryText = getenv('SAFEHARBOR_TIME_ADJUSTMENT_RACE_ENTRY');
    $scenario = getenv('SAFEHARBOR_TIME_ADJUSTMENT_RACE_SCENARIO');
    if (!is_string($runId) || preg_match('/\A[a-f0-9]{12}\z/D', $runId) !== 1
        || !is_string($token) || preg_match('/\A[a-f0-9]{64}\z/D', $token) !== 1
        || !is_string($entryText) || preg_match('/\A[1-9][0-9]{0,9}\z/D', $entryText) !== 1
        || !is_string($scenario)
        || !in_array($scenario, ['one', 'two', 'key-one', 'key-two'], true)
    ) {
        fwrite(STDERR, "Adjustment-race worker refused its target.\n");
        exit(2);
    }
    $workerDatabase = $databaseBase . '_' . $runId;
    $entryId = (int) $entryText;
    try {
        $worker = new PDO(
            $serverDsn . ';dbname=' . $workerDatabase,
            $operatorUser,
            $operatorPass,
            $pdoOptions,
        );
        $worker->exec("SET time_zone = '+00:00'");
        $worker->exec('SET SESSION innodb_lock_wait_timeout=15');
        if (!hash_equals($workerDatabase, (string) $worker->query('SELECT DATABASE()')->fetchColumn())) {
            throw new RuntimeException('Adjustment-race worker selected an unexpected database.');
        }
        $marker = $worker->prepare(
            'SELECT worker_token FROM time_adjustment_race_test_marker WHERE run_id = ?'
        );
        $marker->execute([$runId]);
        if (!hash_equals($token, (string) $marker->fetchColumn())) {
            throw new RuntimeException('Adjustment-race worker marker did not match.');
        }
        echo "ready\n";
        flush();
        $keyCollision = str_starts_with($scenario, 'key-');
        $actorId = $scenario === 'key-two' ? 102 : 101;
        $actorRole = $scenario === 'key-two' ? 'admin' : 'owner';
        $result = time_entry_adjustment_create($worker, 1, $actorId, $actorRole, [
            'entry_id' => $entryId,
            'adjustment_key' => $keyCollision
                ? 'adjustment.race.shared.0001'
                : 'adjustment.race.' . $scenario . '.0001',
            'expected_version' => 0,
            'effective_minutes' => $keyCollision ? 10 : ($scenario === 'one' ? 40 : 35),
            'effective_billable' => true,
            'reason' => match ($scenario) {
                'one' => 'First concurrent correction',
                'two' => 'Second concurrent correction',
                'key-one' => 'First parent claims shared key',
                'key-two' => 'Second parent claims shared key',
            },
        ]);
        echo json_encode([
            'status' => 201,
            'id' => $result['id'],
            'version' => $result['version'],
            'replayed' => $result['replayed'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        exit(0);
    } catch (TimeEntryConflictException $error) {
        echo json_encode([
            'status' => time_entry_http_status($error),
            'error' => 'stale_version',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        exit(0);
    } catch (Throwable $error) {
        fwrite(STDERR, 'Adjustment-race worker failed with ' . $error::class . PHP_EOL);
        exit(1);
    }
}

$runId = bin2hex(random_bytes(6));
$testDatabase = $databaseBase . '_' . $runId;
$quotedDatabase = '`' . str_replace('`', '``', $testDatabase) . '`';
$server = null;
$pdo = null;
$databaseCreated = false;
$temporaryUsers = [];
$checks = 0;
$failures = 0;
$fatalError = null;
$cleanupError = false;

function adjustment_mysql_check(string $name, bool $condition): void
{
    global $checks, $failures;
    $checks++;
    if ($condition) {
        echo "ok {$checks} - {$name}\n";
    } else {
        $failures++;
        echo "FAIL {$checks} - {$name}\n";
    }
}

function adjustment_mysql_expect(
    string $name,
    callable $operation,
    string $state = '',
    string $message = '',
): void {
    try {
        $operation();
        adjustment_mysql_check($name, false);
    } catch (Throwable $error) {
        adjustment_mysql_check(
            $name,
            ($state === '' || (string) $error->getCode() === $state)
                && ($message === '' || str_contains($error->getMessage(), $message)),
        );
    }
}

/** @return list<string> */
function adjustment_mysql_statements(string $sql): array
{
    $delimiter = ';';
    $buffer = '';
    $statements = [];
    foreach (preg_split('/\R/', $sql) ?: [] as $line) {
        if (trim($buffer) === '' && preg_match('/^\s*--/', $line) === 1) continue;
        if (preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $match)) {
            if (trim($buffer) !== '') throw new RuntimeException('DELIMITER changed with pending SQL');
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
    if (trim($buffer) !== '') throw new RuntimeException('Unterminated SQL statement');
    return $statements;
}

/** @return array<string, mixed>|null */
function adjustment_mysql_execute_file(PDO $pdo, string $path): ?array
{
    $sql = file_get_contents($path);
    if ($sql === false) throw new RuntimeException('Cannot read SQL fixture');
    $postflight = null;
    foreach (adjustment_mysql_statements($sql) as $statement) {
        $withoutComments = preg_replace('/\A(?:\s*--[^\n]*(?:\n|\z))+/', '', $statement) ?? $statement;
        if (preg_match('/^\s*SELECT\s+@time_adjustment_columns_exact\s+AS/is', $withoutComments) === 1) {
            $row = $pdo->query($statement)->fetch(PDO::FETCH_ASSOC);
            $postflight = is_array($row) ? $row : null;
        } elseif (preg_match('/^\s*SELECT\b/i', $withoutComments) === 1) {
            $result = $pdo->query($statement);
            $result->fetchAll();
            $result->closeCursor();
        } else {
            $pdo->exec($statement);
        }
    }
    return $postflight;
}

function adjustment_mysql_interrupt_at_swap(PDO $pdo, string $path): void
{
    $sql = file_get_contents($path);
    if ($sql === false) throw new RuntimeException('Cannot read migration fixture');
    $statements = adjustment_mysql_statements($sql);
    $stop = null;
    foreach ($statements as $index => $statement) {
        if (str_starts_with(trim($statement), 'CREATE TRIGGER trg_time_adjustments_before_insert')) {
            $stop = $index;
            break;
        }
    }
    if (!is_int($stop)) throw new RuntimeException('Cannot locate migration 020 trigger swap');
    foreach (array_slice($statements, 0, $stop) as $statement) {
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

/** Stop immediately after first-application CREATE TABLE commits. */
function adjustment_mysql_interrupt_after_table_create(PDO $pdo, string $path): void
{
    $sql = file_get_contents($path);
    if ($sql === false) throw new RuntimeException('Cannot read migration fixture');
    $found = false;
    foreach (adjustment_mysql_statements($sql) as $statement) {
        $withoutComments = preg_replace('/\A(?:\s*--[^\n]*(?:\n|\z))+/', '', $statement) ?? $statement;
        if (preg_match('/^\s*SELECT\b/i', $withoutComments) === 1) {
            $result = $pdo->query($statement);
            $result->fetchAll();
            $result->closeCursor();
        } else {
            $pdo->exec($statement);
        }
        if (preg_match(
            '/^\s*CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+time_entry_approval_adjustments\b/i',
            $withoutComments,
        ) === 1) {
            $found = true;
            break;
        }
    }
    if (!$found) throw new RuntimeException('Cannot locate migration 020 adjustment table creation');
}

function adjustment_mysql_seed(PDO $pdo): void
{
    $pdo->exec("INSERT INTO tenants(id,name,slug) VALUES
      (1,'Tenant One','one'),(2,'Tenant Two','two')");
    $pdo->exec("INSERT INTO clients(id,tenant_id,name) VALUES
      (11,1,'Client One'),(22,2,'Client Two')");
    $pdo->exec("INSERT INTO users
      (id,tenant_id,email,password_hash,full_name,initials,role,is_active) VALUES
      (101,1,'owner1@example.test','','Owner One','O1','owner',1),
      (102,1,'admin1@example.test','','Admin One','A1','admin',1),
      (103,1,'tech1@example.test','','Tech One','T1','tech',1),
      (104,1,'inactive@example.test','','Inactive Owner','IO','owner',0),
      (201,2,'owner2@example.test','','Owner Two','O2','owner',1)");
    $pdo->exec("INSERT INTO tickets
      (id,tenant_id,client_id,subject,status,priority,channel,sla_due_at,created_at,updated_at) VALUES
      (1001,1,11,'Ticket One','open','normal','phone','2026-09-01 00:00:00','2026-08-28 10:00:00','2026-08-28 10:00:00'),
      (2001,2,22,'Ticket Two','open','normal','phone','2026-09-01 00:00:00','2026-08-28 10:00:00','2026-08-28 10:00:00')");
}

/** @return array<string, mixed> */
function adjustment_mysql_create_time(
    PDO $pdo,
    int $tenantId,
    int $technicianId,
    int $ticketId,
    string $key,
    int $minutes,
    bool $billable,
    string $status = 'approved',
): array {
    $entry = time_entry_create($pdo, $tenantId, $technicianId, [
        'ticket_id' => $ticketId,
        'entry_key' => $key,
        'source' => 'suggestion',
        'worked_at' => '2026-08-28T12:00:00Z',
        'minutes' => $minutes,
        'note' => 'Adjustment fixture time',
        'billable' => $billable,
    ]);
    if ($status !== 'pending') {
        $entry = time_entry_review(
            $pdo,
            $tenantId,
            $tenantId === 1 ? 101 : 201,
            'owner',
            (int) $entry['id'],
            $status,
            $status === 'rejected' ? 'Rejected fixture time' : 'Approved fixture time',
        );
    }
    return $entry;
}

function adjustment_mysql_trigger_count(PDO $pdo, string $kind): int
{
    $names = $kind === 'permanent'
        ? "'trg_time_adjustments_before_insert','trg_time_adjustments_no_update','trg_time_adjustments_no_delete'"
        : "'trg_time_020_adjustment_insert_swap','trg_time_020_adjustment_update_swap','trg_time_020_adjustment_delete_swap'";
    return (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.triggers
          WHERE trigger_schema=DATABASE() AND trigger_name IN ({$names})"
    )->fetchColumn();
}

function adjustment_mysql_table_trigger_total(PDO $pdo): int
{
    return (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.triggers
          WHERE trigger_schema=DATABASE()
            AND event_object_table='time_entry_approval_adjustments'"
    )->fetchColumn();
}

function adjustment_mysql_normalize_definition(string $definition): string
{
    $definition = preg_replace('/^\s*--[^\n]*(?:\n|\z)/m', '', $definition) ?? $definition;
    $normalized = '';
    $inString = false;
    $length = strlen($definition);
    for ($index = 0; $index < $length; $index++) {
        $character = $definition[$index];
        if ($character === "'") {
            $normalized .= $character;
            if ($inString && $index + 1 < $length && $definition[$index + 1] === "'") {
                $normalized .= "'";
                $index++;
            } else {
                $inString = !$inString;
            }
            continue;
        }
        if ($inString) {
            $normalized .= $character;
            continue;
        }
        if ($character === '`' || preg_match('/\s/', $character) === 1) {
            continue;
        }
        $normalized .= strtolower($character);
    }
    return $normalized;
}

/** @return array<string,array{timing:string,event:string,body:string}> */
function adjustment_mysql_expected_permanent_triggers(string $path): array
{
    $sql = file_get_contents($path);
    if ($sql === false) throw new RuntimeException('Cannot read trigger source');
    $expectedNames = [
        'trg_time_adjustments_before_insert',
        'trg_time_adjustments_no_update',
        'trg_time_adjustments_no_delete',
    ];
    $expected = [];
    foreach (adjustment_mysql_statements($sql) as $statement) {
        $withoutComments = preg_replace('/\A(?:\s*--[^\n]*(?:\n|\z))+/', '', $statement) ?? $statement;
        if (preg_match(
            '/^\s*CREATE\s+TRIGGER\s+([A-Za-z0-9_]+)\s+(BEFORE|AFTER)\s+'
            . '(INSERT|UPDATE|DELETE)\s+ON\s+time_entry_approval_adjustments\s+'
            . 'FOR\s+EACH\s+ROW\s+(.+)\z/is',
            $withoutComments,
            $match,
        ) !== 1 || !in_array($match[1], $expectedNames, true)) {
            continue;
        }
        $expected[$match[1]] = [
            'timing' => strtoupper($match[2]),
            'event' => strtoupper($match[3]),
            'body' => adjustment_mysql_normalize_definition($match[4]),
        ];
    }
    ksort($expected);
    $actualNames = array_keys($expected);
    $sortedExpectedNames = $expectedNames;
    sort($sortedExpectedNames);
    if ($actualNames !== $sortedExpectedNames) {
        throw new RuntimeException('Expected permanent trigger source is incomplete');
    }
    return $expected;
}

function adjustment_mysql_verify_structure(
    PDO $pdo,
    string $label,
    string $expectedTriggerSource,
): void {
    $columns = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema=DATABASE() AND table_name='time_entry_approval_adjustments'"
    )->fetchColumn();

    $indexRows = $pdo->query(
        "SELECT index_name, MIN(non_unique) AS non_unique, MIN(index_type) AS index_type,
                GROUP_CONCAT(column_name ORDER BY seq_in_index) AS columns_exact,
                SUM(sub_part IS NOT NULL) AS prefix_count
           FROM information_schema.statistics
          WHERE table_schema=DATABASE() AND table_name='time_entry_approval_adjustments'
          GROUP BY index_name ORDER BY index_name"
    )->fetchAll();
    $indexes = [];
    foreach ($indexRows as $row) {
        $indexes[(string) $row['index_name']] = [
            (int) $row['non_unique'],
            (string) $row['index_type'],
            (string) $row['columns_exact'],
            (int) $row['prefix_count'],
        ];
    }
    $expectedIndexes = [
        'PRIMARY' => [0, 'BTREE', 'id', 0],
        'ix_time_adjustment_actor_created' => [1, 'BTREE', 'tenant_id,actor_user_id,created_at,id', 0],
        'ix_time_adjustment_entry_created' => [1, 'BTREE', 'tenant_id,time_entry_id,created_at,id', 0],
        'uq_time_adjustment_entry_version' => [0, 'BTREE', 'tenant_id,time_entry_id,version_no', 0],
        'uq_time_adjustment_tenant_key' => [0, 'BTREE', 'tenant_id,adjustment_key', 0],
    ];
    ksort($indexes);

    $fkRows = $pdo->query(
        "SELECT k.constraint_name, r.referenced_table_name, r.update_rule, r.delete_rule,
                GROUP_CONCAT(CONCAT(k.column_name,'=',k.referenced_column_name)
                             ORDER BY k.ordinal_position) AS columns_exact
           FROM information_schema.key_column_usage k
           JOIN information_schema.referential_constraints r
             ON r.constraint_schema=k.constraint_schema
            AND r.constraint_name=k.constraint_name AND r.table_name=k.table_name
          WHERE k.constraint_schema=DATABASE()
            AND k.table_name='time_entry_approval_adjustments'
            AND k.referenced_table_name IS NOT NULL
          GROUP BY k.constraint_name, r.referenced_table_name, r.update_rule, r.delete_rule
          ORDER BY k.constraint_name"
    )->fetchAll();
    $foreignKeys = [];
    foreach ($fkRows as $row) {
        $foreignKeys[(string) $row['constraint_name']] = [
            (string) $row['referenced_table_name'],
            (string) $row['columns_exact'],
            (string) $row['update_rule'],
            (string) $row['delete_rule'],
        ];
    }
    $expectedForeignKeys = [
        'fk_time_adjustment_actor' => ['users', 'tenant_id=tenant_id,actor_user_id=id', 'NO ACTION', 'NO ACTION'],
        'fk_time_adjustment_entry' => ['time_entries', 'tenant_id=tenant_id,time_entry_id=id', 'NO ACTION', 'NO ACTION'],
        'fk_time_adjustment_tenant' => ['tenants', 'tenant_id=id', 'NO ACTION', 'NO ACTION'],
    ];

    $checkRows = $pdo->query(
        "SELECT constraints_table.constraint_name, constraints_table.enforced,
                checks_table.check_clause
           FROM information_schema.table_constraints constraints_table
           JOIN information_schema.check_constraints checks_table
             ON checks_table.constraint_schema=constraints_table.constraint_schema
            AND checks_table.constraint_name=constraints_table.constraint_name
          WHERE constraints_table.constraint_schema=DATABASE()
            AND constraints_table.table_name='time_entry_approval_adjustments'
            AND constraints_table.constraint_type='CHECK'
          ORDER BY constraints_table.constraint_name"
    )->fetchAll();
    $checks = [];
    foreach ($checkRows as $row) {
        $clause = strtolower((string) $row['check_clause']);
        $clause = preg_replace('/[`\s()]+/', '', $clause) ?? $clause;
        $checks[(string) $row['constraint_name']] = [(string) $row['enforced'], $clause];
    }
    $expectedChecks = [
        'ck_time_adjustment_billable' => ['YES', ['effective_billablein0,1', 'effective_billablein1,0']],
        'ck_time_adjustment_minutes' => ['YES', ['effective_minutesbetween0and1440']],
        'ck_time_adjustment_reason' => ['YES', ['char_lengthtrimreasonbetween1and500']],
        'ck_time_adjustment_version' => ['YES', ['version_no>=1']],
        'ck_time_adjustment_zero_nonbillable' => ['YES', [
            'effective_minutes<>0oreffective_billable=0',
            'effective_billable=0oreffective_minutes<>0',
        ]],
    ];
    $checksMatch = array_keys($checks) === array_keys($expectedChecks);
    foreach ($expectedChecks as $name => [$enforced, $allowedClauses]) {
        $checksMatch = $checksMatch
            && ($checks[$name][0] ?? null) === $enforced
            && in_array($checks[$name][1] ?? null, $allowedClauses, true);
    }

    $triggerRows = $pdo->query(
        "SELECT trigger_name, action_timing, event_manipulation, action_orientation,
                action_statement
           FROM information_schema.triggers
          WHERE trigger_schema=DATABASE()
            AND event_object_table='time_entry_approval_adjustments'
          ORDER BY trigger_name"
    )->fetchAll();
    $triggers = [];
    foreach ($triggerRows as $row) {
        $triggers[(string) $row['trigger_name']] = [
            'timing' => (string) $row['action_timing'],
            'event' => (string) $row['event_manipulation'],
            'orientation' => (string) $row['action_orientation'],
            'body' => adjustment_mysql_normalize_definition((string) $row['action_statement']),
        ];
    }
    $expectedTriggers = adjustment_mysql_expected_permanent_triggers($expectedTriggerSource);
    $triggersMatch = array_keys($triggers) === array_keys($expectedTriggers);
    foreach ($expectedTriggers as $name => $expected) {
        $triggersMatch = $triggersMatch
            && ($triggers[$name]['timing'] ?? null) === $expected['timing']
            && ($triggers[$name]['event'] ?? null) === $expected['event']
            && ($triggers[$name]['orientation'] ?? null) === 'ROW'
            && ($triggers[$name]['body'] ?? null) === $expected['body'];
    }

    adjustment_mysql_check("{$label}: exact table indexes, foreign keys, and checks are present",
        $columns === 10 && $indexes === $expectedIndexes
        && $foreignKeys === $expectedForeignKeys && $checksMatch);
    adjustment_mysql_check("{$label}: exact permanent trigger events and bodies are present",
        $triggersMatch);
    adjustment_mysql_check("{$label}: no migration swap or install guard remains",
        adjustment_mysql_trigger_count($pdo, 'swap') === 0
        && !array_key_exists('ck_time_adjustment_install_lock', $checks));
}

/** @return array<string, mixed> */
function adjustment_mysql_start_worker(
    string $runId,
    string $token,
    int $entryId,
    string $scenario,
): array {
    $environment = getenv();
    if (!is_array($environment)) $environment = [];
    $environment['SAFEHARBOR_TIME_ADJUSTMENT_RACE_WORKER'] = '1';
    $environment['SAFEHARBOR_TIME_ADJUSTMENT_RACE_RUN_ID'] = $runId;
    $environment['SAFEHARBOR_TIME_ADJUSTMENT_RACE_TOKEN'] = $token;
    $environment['SAFEHARBOR_TIME_ADJUSTMENT_RACE_ENTRY'] = (string) $entryId;
    $environment['SAFEHARBOR_TIME_ADJUSTMENT_RACE_SCENARIO'] = $scenario;
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, __FILE__],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        __DIR__,
        $environment,
    );
    if (!is_resource($process)) throw new RuntimeException('Cannot start adjustment-race worker');
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

/** @param array<string, mixed> $worker @return array<string, mixed> */
function adjustment_mysql_finish_worker(array $worker): array
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

function adjustment_mysql_waiters(
    PDO $server,
    string $database,
    string $table,
    int $minimum,
): bool
{
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

try {
    $server = new PDO($serverDsn, $operatorUser, $operatorPass, $pdoOptions);
    $server->exec("CREATE DATABASE {$quotedDatabase} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $databaseCreated = true;
    $pdo = new PDO(
        $serverDsn . ';dbname=' . $testDatabase,
        $operatorUser,
        $operatorPass,
        $pdoOptions,
    );
    $pdo->exec("SET time_zone = '+00:00'");
    adjustment_mysql_check('fixture selected the exact random database',
        (string) $pdo->query('SELECT DATABASE()')->fetchColumn() === $testDatabase);

    $schemaPath = __DIR__ . '/../db/schema.sql';
    $migrationPath = __DIR__ . '/../db/migrations/020_time_approval_adjustments.sql';
    adjustment_mysql_execute_file($pdo, $schemaPath);
    adjustment_mysql_seed($pdo);
    adjustment_mysql_verify_structure($pdo, 'fresh schema', $schemaPath);

    // Execute the real Reports query against small fixtures in this disposable
    // database. Only table names change; all filtering and aggregation stay intact.
    $reportSource = file_get_contents(__DIR__ . '/../public/reports.php');
    if (!is_string($reportSource)
        || preg_match('/\$bc = db\(\)->prepare\(\s*"([^"]+)"\s*\)/s', $reportSource, $reportSql) !== 1
    ) throw new RuntimeException('Cannot locate client-time report query');
    $pdo->exec('CREATE TABLE report_fixture_clients (id INT PRIMARY KEY, tenant_id INT, name VARCHAR(80))');
    $pdo->exec('CREATE TABLE report_fixture_entries
      (id INT PRIMARY KEY, tenant_id INT, client_id INT, minutes INT, billable INT,
       approval_status VARCHAR(20), worked_at DATETIME)');
    $pdo->exec('CREATE TABLE report_fixture_adjustments
      (id INT PRIMARY KEY, tenant_id INT, time_entry_id INT, version_no INT,
       effective_billable INT, effective_minutes INT)');
    $pdo->exec("INSERT INTO report_fixture_clients VALUES (1,1,'One minute'),(2,1,'Two minutes'),
      (3,1,'Reversed'),(4,1,'Excluded'),(5,2,'Other tenant')");
    $pdo->exec("INSERT INTO report_fixture_entries VALUES
      (1,1,1,1,1,'approved',UTC_TIMESTAMP()),(2,1,2,2,1,'approved',UTC_TIMESTAMP()),
      (3,1,3,1,1,'approved',UTC_TIMESTAMP()),(4,1,4,60,1,'pending',UTC_TIMESTAMP()),
      (5,1,4,60,0,'approved',UTC_TIMESTAMP()),(6,2,5,60,1,'approved',UTC_TIMESTAMP()),
      (7,1,4,60,1,'approved',DATE_SUB(UTC_TIMESTAMP(),INTERVAL 31 DAY))");
    $pdo->exec('INSERT INTO report_fixture_adjustments VALUES (1,1,3,1,1,1),(2,1,3,2,0,0)');
    $report = $pdo->prepare(strtr($reportSql[1], [
        'time_entry_approval_adjustments' => 'report_fixture_adjustments',
        'time_entries' => 'report_fixture_entries', 'clients' => 'report_fixture_clients',
    ]));
    $report->execute([1]);
    $reportRows = $report->fetchAll();
    adjustment_mysql_check('client report retains one and two approved minutes in descending order',
        array_column($reportRows, 'name') === ['Two minutes', 'One minute', 'Reversed']
        && (int) $reportRows[0]['min_total'] === 2
        && (int) $reportRows[1]['min_total'] === 1);
    adjustment_mysql_check('client report retains original time after its latest complete reversal',
        count($reportRows) === 3 && (int) $reportRows[2]['min_total'] === 0
        && (int) $reportRows[2]['original_min_total'] === 1
        && (int) $reportRows[2]['adjusted_count'] === 1);
    adjustment_mysql_check('client report excludes pending, internal, expired and other-tenant time',
        !in_array('Excluded', array_column($reportRows, 'name'), true)
        && !in_array('Other tenant', array_column($reportRows, 'name'), true));
    $pdo->exec('DROP TABLE report_fixture_adjustments, report_fixture_entries, report_fixture_clients');

    // Manufacture one impossible legacy corruption only inside this disposable
    // fixture, then immediately restore every canonical trigger from schema.
    // Both the service and migration-020 trigger must still fail closed when
    // an `approved` label lacks real reviewer/timestamp evidence.
    $pdo->exec('DROP TRIGGER trg_time_entries_before_insert');
    $pdo->exec('DROP TRIGGER trg_time_entries_after_insert');
    $pdo->exec("INSERT INTO time_entries
      (id,tenant_id,client_id,entry_key,ticket_id,user_id,minutes,note,billable,
       source,worked_at,approval_status,reviewed_by_user_id,reviewed_at,review_note)
      VALUES
      (9001,1,11,'corrupt.approved.no-review',1001,103,10,'Corrupt fixture',1,
       'legacy','2026-08-28 11:00:00','approved',NULL,NULL,'')");
    adjustment_mysql_execute_file($pdo, $schemaPath);
    adjustment_mysql_expect('service refuses approved label without reviewer evidence',
        fn() => time_entry_adjustment_create($pdo, 1, 101, 'owner', [
            'entry_id' => 9001,
            'adjustment_key' => 'adjustment.corrupt.service',
            'expected_version' => 0,
            'effective_minutes' => 5,
            'effective_billable' => true,
            'reason' => 'Must not accept corrupt approval evidence',
        ]), '', 'review evidence');
    adjustment_mysql_expect('database trigger refuses approved label without reviewer evidence',
        fn() => $pdo->exec("INSERT INTO time_entry_approval_adjustments
          (tenant_id,time_entry_id,adjustment_key,version_no,effective_minutes,
           effective_billable,reason,actor_user_id)
          VALUES (1,9001,'adjustment.corrupt.direct.01',1,5,1,'Corrupt parent',101)"),
        '45000', 'review evidence');

    $approved = adjustment_mysql_create_time(
        $pdo, 1, 103, 1001, 'suggestion.adjustment.mysql.0001', 60, true,
    );
    $internal = adjustment_mysql_create_time(
        $pdo, 1, 103, 1001, 'suggestion.adjustment.mysql.0002', 30, false,
    );
    $pending = adjustment_mysql_create_time(
        $pdo, 1, 103, 1001, 'suggestion.adjustment.mysql.0003', 20, true, 'pending',
    );
    $rejected = adjustment_mysql_create_time(
        $pdo, 1, 103, 1001, 'suggestion.adjustment.mysql.0004', 20, true, 'rejected',
    );
    $otherTenant = adjustment_mysql_create_time(
        $pdo, 2, 201, 2001, 'suggestion.adjustment.mysql.0005', 25, true,
    );

    $parentBefore = $pdo->query(
        "SELECT * FROM time_entries WHERE tenant_id=1 AND id={$approved['id']}"
    )->fetch();
    $firstInput = [
        'entry_id' => (int) $approved['id'],
        'adjustment_key' => 'adjustment.mysql.base.0001',
        'expected_version' => 0,
        'effective_minutes' => 45,
        'effective_billable' => true,
        'reason' => 'Remove duplicated work from effective time',
    ];
    $first = time_entry_adjustment_create($pdo, 1, 101, 'owner', $firstInput);
    adjustment_mysql_check('owner appends version one with database-owned UTC evidence',
        $first['version'] === 1
        && $first['original_minutes'] === 60
        && $first['effective_minutes'] === 45
        && $first['actor_user_id'] === 101
        && preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/D', $first['created_at']) === 1);
    adjustment_mysql_check('adjustment leaves every approved parent field unchanged',
        $parentBefore === $pdo->query(
            "SELECT * FROM time_entries WHERE tenant_id=1 AND id={$approved['id']}"
        )->fetch());
    $replay = time_entry_adjustment_create($pdo, 1, 101, 'owner', $firstInput);
    adjustment_mysql_check('exact retry returns one immutable stored row',
        $replay['id'] === $first['id'] && $replay['replayed'] === true
        && (int) $pdo->query("SELECT COUNT(*) FROM time_entry_approval_adjustments
          WHERE tenant_id=1 AND time_entry_id={$approved['id']}")->fetchColumn() === 1);
    adjustment_mysql_expect('stale service version becomes a 409 conflict',
        fn() => time_entry_adjustment_create($pdo, 1, 101, 'owner', array_replace($firstInput, [
            'adjustment_key' => 'adjustment.mysql.stale.0001',
            'effective_minutes' => 30,
        ])), '', 'form was loaded');
    $restored = time_entry_adjustment_create($pdo, 1, 102, 'admin', array_replace($firstInput, [
        'adjustment_key' => 'adjustment.mysql.restore.0001',
        'expected_version' => 1,
        'effective_minutes' => 60,
        'reason' => 'Restore the verified original approved duration',
    ]));
    adjustment_mysql_check('admin may restore a later version up to the original',
        $restored['version'] === 2 && $restored['effective_minutes'] === 60);

    $raw = $pdo->prepare(
        'INSERT INTO time_entry_approval_adjustments
          (tenant_id,time_entry_id,adjustment_key,version_no,effective_minutes,
           effective_billable,reason,actor_user_id,created_at)
         VALUES (?,?,?,?,?,?,?,?,?)'
    );
    adjustment_mysql_expect('database refuses cross-tenant parent reach',
        fn() => $raw->execute([1,$otherTenant['id'],'adjustment.raw.cross.0001',1,10,1,'Cross tenant',101,'2000-01-01 00:00:00']),
        '45000', 'approved time');
    adjustment_mysql_expect('database refuses technician authority',
        fn() => $raw->execute([1,$internal['id'],'adjustment.raw.tech.00001',1,20,0,'Tech actor',103,'2000-01-01 00:00:00']),
        '45000', 'active owner or admin');
    adjustment_mysql_expect('database refuses inactive owner authority',
        fn() => $raw->execute([1,$internal['id'],'adjustment.raw.inactive.1',1,20,0,'Inactive actor',104,'2000-01-01 00:00:00']),
        '45000', 'active owner or admin');
    adjustment_mysql_expect('database refuses pending time',
        fn() => $raw->execute([1,$pending['id'],'adjustment.raw.pending.01',1,10,0,'Pending parent',101,'2000-01-01 00:00:00']),
        '45000', 'approved time');
    adjustment_mysql_expect('database refuses rejected time',
        fn() => $raw->execute([1,$rejected['id'],'adjustment.raw.rejected.1',1,10,0,'Rejected parent',101,'2000-01-01 00:00:00']),
        '45000', 'approved time');
    adjustment_mysql_expect('database refuses minutes above immutable original',
        fn() => $raw->execute([1,$internal['id'],'adjustment.raw.bounds.001',1,31,0,'Too many minutes',101,'2000-01-01 00:00:00']),
        '45000', 'exceed original');
    adjustment_mysql_expect('database refuses internal time becoming billable',
        fn() => $raw->execute([1,$internal['id'],'adjustment.raw.billable.1',1,20,1,'Wrong billing class',101,'2000-01-01 00:00:00']),
        '45000', 'cannot become billable');
    adjustment_mysql_expect('database refuses zero billable minutes',
        fn() => $raw->execute([1,$approved['id'],'adjustment.raw.zero.0001',3,0,1,'Zero billing',101,'2000-01-01 00:00:00']),
        '', 'Zero effective minutes');
    adjustment_mysql_expect('database refuses a skipped version',
        fn() => $raw->execute([1,$internal['id'],'adjustment.raw.version.01',2,20,0,'Skipped version',101,'2000-01-01 00:00:00']),
        '45000', 'current adjustment version');
    adjustment_mysql_expect('database refuses a nonconservative key',
        fn() => $raw->execute([1,$internal['id'],'adjustment raw spaces',1,20,0,'Bad key',101,'2000-01-01 00:00:00']),
        '45000', 'not conservative');
    adjustment_mysql_expect('database refuses a PHP-trim control-whitespace-only reason',
        fn() => $raw->execute([
            1,$internal['id'],'adjustment.raw.reason.0001',1,20,0,
            "\t\n\v\r\0 ",101,'2000-01-01 00:00:00',
        ]),
        '45000', 'reason is required');
    $raw->execute([
        1,$internal['id'],'adjustment.raw.good.0001',1,20,0,
        "\t \nTrim this reason\r\v\0 ",101,'2000-01-01 00:00:00',
    ]);
    $rawStored = $pdo->query("SELECT reason,created_at FROM time_entry_approval_adjustments
      WHERE tenant_id=1 AND adjustment_key='adjustment.raw.good.0001'")->fetch();
    adjustment_mysql_check('database trims reason and owns the UTC timestamp',
        ($rawStored['reason'] ?? null) === 'Trim this reason'
        && ($rawStored['created_at'] ?? null) !== '2000-01-01 00:00:00');
    adjustment_mysql_expect('adjustment rows cannot be updated',
        fn() => $pdo->exec("UPDATE time_entry_approval_adjustments SET reason='Changed'
          WHERE tenant_id=1 AND adjustment_key='adjustment.raw.good.0001'"),
        '45000', 'immutable');
    adjustment_mysql_expect('adjustment rows cannot be deleted',
        fn() => $pdo->exec("DELETE FROM time_entry_approval_adjustments
          WHERE tenant_id=1 AND adjustment_key='adjustment.raw.good.0001'"),
        '45000', 'cannot be deleted');

    // DML-only runtime identity can use the service, but cannot gain schema or
    // trigger authority and cannot bypass immutable guards directly.
    $runtimeUser = 'sh_ta_rt_' . $runId;
    $runtimePass = bin2hex(random_bytes(24));
    $temporaryUsers[] = $runtimeUser;
    $server->exec("CREATE USER '{$runtimeUser}'@'%' IDENTIFIED BY '{$runtimePass}'");
    $server->exec("GRANT SELECT,INSERT,UPDATE,DELETE ON `{$testDatabase}`.* TO '{$runtimeUser}'@'%'");
    $runtime = new PDO(
        $serverDsn . ';dbname=' . $testDatabase,
        $runtimeUser,
        $runtimePass,
        $pdoOptions,
    );
    $runtime->exec("SET time_zone = '+00:00'");
    adjustment_mysql_expect('DML-only identity sees the same pending-time guard',
        fn() => time_entry_adjustment_create($runtime, 1, 101, 'owner', [
            'entry_id' => (int) $pending['id'],
            'adjustment_key' => 'adjustment.runtime.pending',
            'expected_version' => 0,
            'effective_minutes' => 10,
            'effective_billable' => false,
            'reason' => 'This should not reach insertion',
        ]), '', 'Only approved time');

    // Continue the least-privilege proof on a fresh approved entry.
        $runtimeApproved = adjustment_mysql_create_time(
            $pdo, 1, 103, 1001, 'suggestion.adjustment.runtime.0001', 15, true,
        );
        $runtimeResult = time_entry_adjustment_create($runtime, 1, 101, 'owner', [
            'entry_id' => (int) $runtimeApproved['id'],
            'adjustment_key' => 'adjustment.runtime.good.01',
            'expected_version' => 0,
            'effective_minutes' => 10,
            'effective_billable' => true,
            'reason' => 'Least privilege service proof',
        ]);
        adjustment_mysql_check('DML-only identity can append through the guarded service',
            $runtimeResult['version'] === 1 && $runtimeResult['actor_user_id'] === 101);
        adjustment_mysql_expect('DML-only identity still cannot alter schema',
            fn() => $runtime->exec('ALTER TABLE time_entry_approval_adjustments ADD COLUMN forbidden INT'),
            '42000');
        adjustment_mysql_expect('DML-only identity cannot rewrite an adjustment',
            fn() => $runtime->exec("UPDATE time_entry_approval_adjustments SET reason='Changed'
              WHERE adjustment_key='adjustment.runtime.good.01'"),
            '45000', 'immutable');
        $migrationDenied = false;
        try {
            adjustment_mysql_execute_file($runtime, $migrationPath);
        } catch (PDOException $error) {
            $migrationDenied = in_array((int) ($error->errorInfo[1] ?? 0), [1142, 1143, 1227, 1419], true);
        }
        adjustment_mysql_check('DML-only identity cannot run the privileged migration', $migrationDenied);
        adjustment_mysql_check('failed DML-only migration leaves permanent guards intact',
            adjustment_mysql_trigger_count($pdo, 'permanent') === 3
            && adjustment_mysql_trigger_count($pdo, 'swap') === 0);
        $runtime = null;

        // Exact migration upgrade from the deployed pre-020 shape, followed by
        // replay over stored immutable history.
        $historyBefore = (int) $pdo->query('SELECT COUNT(*) FROM time_entries')->fetchColumn();
        $pdo->exec('DROP TRIGGER IF EXISTS trg_time_adjustments_before_insert');
        $pdo->exec('DROP TRIGGER IF EXISTS trg_time_adjustments_no_update');
        $pdo->exec('DROP TRIGGER IF EXISTS trg_time_adjustments_no_delete');
        $pdo->exec('DROP TABLE time_entry_approval_adjustments');

        // First application can stop immediately after CREATE TABLE commits,
        // before even the first swap trigger exists. The temporary enforced
        // table check must keep the empty table unable to acquire a row; exact
        // replay then installs permanent guards and removes that temporary lock.
        adjustment_mysql_interrupt_after_table_create($pdo, $migrationPath);
        $installLockExact = (int) $pdo->query(
            "SELECT COUNT(*)
               FROM information_schema.table_constraints constraints_table
               JOIN information_schema.check_constraints checks_table
                 ON checks_table.constraint_schema=constraints_table.constraint_schema
                AND checks_table.constraint_name=constraints_table.constraint_name
              WHERE constraints_table.constraint_schema=DATABASE()
                AND constraints_table.table_name='time_entry_approval_adjustments'
                AND constraints_table.constraint_type='CHECK'
                AND constraints_table.constraint_name='ck_time_adjustment_install_lock'
                AND constraints_table.enforced='YES'
                AND REPLACE(REPLACE(REPLACE(REPLACE(
                       LOWER(checks_table.check_clause),'`',''),' ',''),'(',''),')','')='0=1'"
        )->fetchColumn();
        adjustment_mysql_check('first-apply interruption leaves the exact enforced install lock',
            $installLockExact === 1 && adjustment_mysql_table_trigger_total($pdo) === 0);
        adjustment_mysql_expect('first-apply interruption blocks the first possible row',
            fn() => $pdo->exec("INSERT INTO time_entry_approval_adjustments
              (tenant_id,time_entry_id,adjustment_key,version_no,effective_minutes,
               effective_billable,reason,actor_user_id)
              VALUES (1,{$approved['id']},'adjustment.install.block.01',1,10,1,'Blocked',101)"),
            '', 'ck_time_adjustment_install_lock');
        adjustment_mysql_check('first-apply interruption preserves zero adjustment history',
            (int) $pdo->query('SELECT COUNT(*) FROM time_entry_approval_adjustments')->fetchColumn() === 0);

        $postflight = adjustment_mysql_execute_file($pdo, $migrationPath);
        adjustment_mysql_check('migration upgrade emits exact all-green postflight',
            is_array($postflight)
            && (int) ($postflight['columns_exact'] ?? 0) === 1
            && (int) ($postflight['indexes_exact'] ?? 0) === 5
            && (int) ($postflight['foreign_keys_exact'] ?? 0) === 3
            && (int) ($postflight['checks_exact'] ?? 0) === 5
            && (int) ($postflight['permanent_trigger_count'] ?? 0) === 3
            && (int) ($postflight['staging_trigger_count'] ?? -1) === 0
            && (int) ($postflight['trigger_total'] ?? 0) === 3);
        adjustment_mysql_verify_structure($pdo, 'migration upgrade', $migrationPath);
        adjustment_mysql_check('upgrade creates no adjustment and rewrites no parent history',
            (int) $pdo->query('SELECT COUNT(*) FROM time_entry_approval_adjustments')->fetchColumn() === 0
            && (int) $pdo->query('SELECT COUNT(*) FROM time_entries')->fetchColumn() === $historyBefore);
        $upgradeAdjustment = time_entry_adjustment_create($pdo, 1, 101, 'owner', [
            'entry_id' => (int) $approved['id'],
            'adjustment_key' => 'adjustment.upgrade.0001',
            'expected_version' => 0,
            'effective_minutes' => 50,
            'effective_billable' => true,
            'reason' => 'Upgrade replay preservation proof',
        ]);
        $upgradeSnapshot = $pdo->query("SELECT * FROM time_entry_approval_adjustments
          WHERE id={$upgradeAdjustment['id']}")->fetch();
        $replayPostflight = adjustment_mysql_execute_file($pdo, $migrationPath);
        adjustment_mysql_check('exact migration replay preserves adjustment bytes',
            is_array($replayPostflight)
            && $upgradeSnapshot === $pdo->query("SELECT * FROM time_entry_approval_adjustments
              WHERE id={$upgradeAdjustment['id']}")->fetch()
            && adjustment_mysql_trigger_count($pdo, 'permanent') === 3);

        $pdo->exec('ALTER TABLE time_entry_approval_adjustments
          ADD KEY ix_time_adjustment_forbidden_extra (tenant_id, effective_minutes)');
        adjustment_mysql_expect('extra index is rejected as a malformed exact structure',
            fn() => adjustment_mysql_execute_file($pdo, $migrationPath), '42S02');
        adjustment_mysql_check('malformed replay leaves all permanent guards in place',
            adjustment_mysql_trigger_count($pdo, 'permanent') === 3);
        $pdo->exec('ALTER TABLE time_entry_approval_adjustments
          DROP INDEX ix_time_adjustment_forbidden_extra');

        $pdo->exec('ALTER TABLE time_entry_approval_adjustments
          DROP CHECK ck_time_adjustment_minutes');
        $pdo->exec('ALTER TABLE time_entry_approval_adjustments
          ADD CONSTRAINT ck_time_adjustment_minutes CHECK (effective_minutes >= 0)');
        adjustment_mysql_expect('weakened same-name check is rejected before guard replacement',
            fn() => adjustment_mysql_execute_file($pdo, $migrationPath), '42S02');
        $pdo->exec('ALTER TABLE time_entry_approval_adjustments
          DROP CHECK ck_time_adjustment_minutes');
        $pdo->exec('ALTER TABLE time_entry_approval_adjustments
          ADD CONSTRAINT ck_time_adjustment_minutes CHECK (effective_minutes BETWEEN 0 AND 1440)');
        adjustment_mysql_execute_file($pdo, $migrationPath);

        $pdo->exec("CREATE TRIGGER trg_time_adjustment_unexpected_extra
          AFTER INSERT ON time_entry_approval_adjustments
          FOR EACH ROW SET @time_adjustment_unexpected_extra = 1");
        adjustment_mysql_expect('unexpected fourth table trigger is rejected before replacement',
            fn() => adjustment_mysql_execute_file($pdo, $migrationPath), '42S02');
        adjustment_mysql_check('extra-trigger refusal leaves canonical permanent guards untouched',
            adjustment_mysql_trigger_count($pdo, 'permanent') === 3
            && adjustment_mysql_table_trigger_total($pdo) === 4);
        $pdo->exec('DROP TRIGGER trg_time_adjustment_unexpected_extra');

        // A known swap name is not enough. A fake INSERT guard attached to
        // UPDATE with a no-op body must be detected before any permanent
        // trigger is dropped. The exact UPDATE/DELETE blockers created before
        // refusal also stay fail-closed until this fixture removes them.
        $pdo->exec("CREATE TRIGGER trg_time_020_adjustment_insert_swap
          BEFORE UPDATE ON time_entry_approval_adjustments
          FOR EACH ROW SET @fake_time_adjustment_guard = 'migration 020 trigger swap'");
        adjustment_mysql_expect('wrong-event no-op swap guard is refused before permanent drops',
            fn() => adjustment_mysql_execute_file($pdo, $migrationPath), '42S02');
        adjustment_mysql_check('malformed swap refusal preserves all permanent guards',
            adjustment_mysql_trigger_count($pdo, 'permanent') === 3);
        $pdo->exec('DROP TRIGGER IF EXISTS trg_time_020_adjustment_insert_swap');
        $pdo->exec('DROP TRIGGER IF EXISTS trg_time_020_adjustment_update_swap');
        $pdo->exec('DROP TRIGGER IF EXISTS trg_time_020_adjustment_delete_swap');

        // Interrupt immediately after all three permanent triggers are dropped.
        adjustment_mysql_interrupt_at_swap($pdo, $migrationPath);
        adjustment_mysql_check('interrupted replacement leaves three fail-closed swap guards',
            adjustment_mysql_trigger_count($pdo, 'permanent') === 0
            && adjustment_mysql_trigger_count($pdo, 'swap') === 3);
        adjustment_mysql_expect('interrupted replacement blocks inserts',
            fn() => $pdo->exec("INSERT INTO time_entry_approval_adjustments
              (tenant_id,time_entry_id,adjustment_key,version_no,effective_minutes,
               effective_billable,reason,actor_user_id)
              VALUES (1,{$internal['id']},'adjustment.swap.insert.01',1,10,0,'Blocked',101)"),
            '45000', 'migration 020 trigger swap');
        adjustment_mysql_expect('interrupted replacement blocks updates',
            fn() => $pdo->exec("UPDATE time_entry_approval_adjustments SET reason='Changed'
              WHERE id={$upgradeAdjustment['id']}"),
            '45000', 'migration 020 trigger swap');
        adjustment_mysql_expect('interrupted replacement blocks deletes',
            fn() => $pdo->exec("DELETE FROM time_entry_approval_adjustments
              WHERE id={$upgradeAdjustment['id']}"),
            '45000', 'migration 020 trigger swap');
        $recoveredPostflight = adjustment_mysql_execute_file($pdo, $migrationPath);
        adjustment_mysql_check('exact replay recovers interrupted replacement without history loss',
            is_array($recoveredPostflight)
            && adjustment_mysql_trigger_count($pdo, 'permanent') === 3
            && adjustment_mysql_trigger_count($pdo, 'swap') === 0
            && $upgradeSnapshot === $pdo->query("SELECT * FROM time_entry_approval_adjustments
              WHERE id={$upgradeAdjustment['id']}")->fetch());

        // Both separate PHP/MySQL sessions start behind one held parent lock.
        // The first queues there while holding the tenant namespace; the
        // second queues on that tenant. The winner appends v1; after both
        // locks release in order, the loser observes v1 and returns 409.
        $raceEntry = adjustment_mysql_create_time(
            $pdo, 1, 103, 1001, 'suggestion.adjustment.race.0001', 50, true,
        );
        $raceToken = bin2hex(random_bytes(32));
        $pdo->exec("CREATE TABLE time_adjustment_race_test_marker (
          run_id CHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          worker_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          PRIMARY KEY (run_id)
        ) ENGINE=InnoDB");
        $marker = $pdo->prepare(
            'INSERT INTO time_adjustment_race_test_marker (run_id,worker_token) VALUES (?,?)'
        );
        $marker->execute([$runId, $raceToken]);
        $locker = new PDO(
            $serverDsn . ';dbname=' . $testDatabase,
            $operatorUser,
            $operatorPass,
            $pdoOptions,
        );
        $locker->beginTransaction();
        $lock = $locker->prepare(
            'SELECT id FROM time_entries WHERE tenant_id=1 AND id=? FOR UPDATE'
        );
        $lock->execute([$raceEntry['id']]);
        if ((int) $lock->fetchColumn() !== (int) $raceEntry['id']) {
            throw new RuntimeException('Race parent lock target was not found');
        }
        $workerOne = adjustment_mysql_start_worker($runId, $raceToken, (int) $raceEntry['id'], 'one');
        $oneQueued = $workerOne['ready']
            && adjustment_mysql_waiters($server, $testDatabase, 'time_entries', 1);
        $workerTwo = adjustment_mysql_start_worker($runId, $raceToken, (int) $raceEntry['id'], 'two');
        $twoQueued = $workerTwo['ready']
            && adjustment_mysql_waiters($server, $testDatabase, 'tenants', 1);
        $locker->commit();
        $oneResult = adjustment_mysql_finish_worker($workerOne);
        $twoResult = adjustment_mysql_finish_worker($workerTwo);
        $payloads = [$oneResult['payload'], $twoResult['payload']];
        $statuses = array_map(
            fn($payload) => is_array($payload) ? (int) ($payload['status'] ?? 0) : 0,
            $payloads,
        );
        sort($statuses);
        adjustment_mysql_check('real adjustment workers queue on parent then tenant namespace locks',
            $oneQueued && $twoQueued
            && $oneResult['ready'] && $twoResult['ready']
            && !$oneResult['timed_out'] && !$twoResult['timed_out']
            && $oneResult['exit'] === 0 && $twoResult['exit'] === 0
            && trim($oneResult['stderr']) === '' && trim($twoResult['stderr']) === '');
        adjustment_mysql_check('one concurrent version wins and the stale contender gets 409',
            $statuses === [201, 409]
            && (int) $pdo->query("SELECT COUNT(*) FROM time_entry_approval_adjustments
              WHERE tenant_id=1 AND time_entry_id={$raceEntry['id']}")->fetchColumn() === 1
            && (int) $pdo->query("SELECT MAX(version_no) FROM time_entry_approval_adjustments
              WHERE tenant_id=1 AND time_entry_id={$raceEntry['id']}")->fetchColumn() === 1);

        // A different-parent collision uses the unique key as a second lock
        // target. Hold its missing-key gap until both workers (different
        // authorized actors, so the actor locks do not serialize them) wait.
        // The loser must current-read the winner and return deterministic 409,
        // never leak a duplicate-key PDO exception as a 500.
        $keyEntryOne = adjustment_mysql_create_time(
            $pdo, 1, 103, 1001, 'suggestion.adjustment.keyrace.0001', 20, true,
        );
        $keyEntryTwo = adjustment_mysql_create_time(
            $pdo, 1, 103, 1001, 'suggestion.adjustment.keyrace.0002', 20, true,
        );
        $keyLocker = new PDO(
            $serverDsn . ';dbname=' . $testDatabase,
            $operatorUser,
            $operatorPass,
            $pdoOptions,
        );
        $keyLocker->exec("SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ");
        $keyLocker->beginTransaction();
        $keyGap = $keyLocker->prepare(
            'SELECT adjustment_key FROM time_entry_approval_adjustments
              WHERE tenant_id=1 AND adjustment_key=? FOR UPDATE'
        );
        $keyGap->execute(['adjustment.race.shared.0001']);
        $keyWorkerOne = adjustment_mysql_start_worker(
            $runId, $raceToken, (int) $keyEntryOne['id'], 'key-one',
        );
        $keyOneQueued = $keyWorkerOne['ready']
            && adjustment_mysql_waiters(
                $server, $testDatabase, 'time_entry_approval_adjustments', 1,
            );
        $keyWorkerTwo = adjustment_mysql_start_worker(
            $runId, $raceToken, (int) $keyEntryTwo['id'], 'key-two',
        );
        $keyTwoQueued = $keyWorkerTwo['ready']
            && adjustment_mysql_waiters(
                $server, $testDatabase, 'tenants', 1,
            );
        $keyLocker->commit();
        $keyOneResult = adjustment_mysql_finish_worker($keyWorkerOne);
        $keyTwoResult = adjustment_mysql_finish_worker($keyWorkerTwo);
        $keyPayloads = [$keyOneResult['payload'], $keyTwoResult['payload']];
        $keyStatuses = array_map(
            fn($payload) => is_array($payload) ? (int) ($payload['status'] ?? 0) : 0,
            $keyPayloads,
        );
        sort($keyStatuses);
        adjustment_mysql_check('different-parent workers queue on the key then tenant namespace locks',
            $keyOneQueued && $keyTwoQueued
            && $keyOneResult['ready'] && $keyTwoResult['ready']
            && !$keyOneResult['timed_out'] && !$keyTwoResult['timed_out']
            && $keyOneResult['exit'] === 0 && $keyTwoResult['exit'] === 0
            && trim($keyOneResult['stderr']) === '' && trim($keyTwoResult['stderr']) === '');
        adjustment_mysql_check('shared key race yields one row plus deterministic 409 conflict',
            $keyStatuses === [201, 409]
            && (int) $pdo->query("SELECT COUNT(*) FROM time_entry_approval_adjustments
              WHERE tenant_id=1 AND adjustment_key='adjustment.race.shared.0001'")->fetchColumn() === 1);
} catch (Throwable $error) {
    $fatalError = $error;
} finally {
    if ($server instanceof PDO) {
        foreach ($temporaryUsers as $temporaryUser) {
            try {
                $server->exec("DROP USER IF EXISTS '{$temporaryUser}'@'%'");
            } catch (Throwable) {
                $cleanupError = true;
            }
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
    fwrite(STDERR, 'Adjustment MySQL fixture failed with ' . $fatalError::class
        . ': ' . $fatalError->getMessage() . PHP_EOL);
    $failures++;
}
if ($cleanupError) {
    fwrite(STDERR, "Adjustment MySQL fixture cleanup failed.\n");
    $failures++;
}

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
