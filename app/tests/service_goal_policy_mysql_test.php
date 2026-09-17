<?php
/** Disposable MySQL 8 coverage for migration 014 and publication guards. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);

require_once __DIR__ . '/../lib/service_goal_policy_admin.php';

$database = getenv('SAFEHARBOR_SERVICE_GOAL_TEST_DB') ?: 'safeharbor_service_goal_test';
if (preg_match('/\Asafeharbor_service_goal_test(?:_[a-z0-9_]+)?\z/', $database) !== 1) {
    fwrite(STDERR, "Refusing destructive test database: {$database}\n");
    exit(2);
}
$host = getenv('SAFEHARBOR_SERVICE_GOAL_TEST_HOST') ?: '127.0.0.1';
$port = getenv('SAFEHARBOR_SERVICE_GOAL_TEST_PORT') ?: '3306';
$user = getenv('SAFEHARBOR_SERVICE_GOAL_TEST_USER') ?: 'root';
$pass = getenv('SAFEHARBOR_SERVICE_GOAL_TEST_PASS') ?: '';
$serverDsn = "mysql:host={$host};port={$port};charset=utf8mb4";
$pdoOptions = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $server = new PDO($serverDsn, $user, $pass, $pdoOptions);
    $quotedDatabase = '`' . str_replace('`', '``', $database) . '`';
    // Race children connect to the parent-created fixture. Repeating schema DDL
    // waits behind the parent transaction once cross-table email guards are present.
    if (($argv[1] ?? '') !== 'worker') {
        $server->exec("CREATE DATABASE IF NOT EXISTS {$quotedDatabase} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }
    $pdo = new PDO($serverDsn . ";dbname={$database}", $user, $pass, $pdoOptions);
    $pdo->exec("SET time_zone = '+00:00'");
} catch (Throwable $error) {
    fwrite(STDERR, 'Service-goal MySQL fixture unavailable: ' . $error->getMessage() . "\n");
    exit(2);
}

$checks = 0;
$failures = 0;

function goal_mysql_check(string $name, bool $condition): void
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
function goal_mysql_throws(string $name, string $expected, callable $operation): void
{
    try {
        $operation();
        goal_mysql_check($name, false);
    } catch (Throwable $error) {
        goal_mysql_check($name, $error instanceof $expected);
    }
}

/** @return list<string> */
function goal_mysql_statements(string $sql): array
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

function goal_mysql_execute_file(PDO $pdo, string $path): void
{
    $sql = file_get_contents($path);
    if (! is_string($sql)) throw new RuntimeException("Cannot read {$path}");
    foreach (goal_mysql_statements($sql) as $statement) {
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

function goal_mysql_reset(PDO $pdo): void
{
    $tables = $pdo->query(
        'SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE()',
    )->fetchAll(PDO::FETCH_COLUMN);
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables as $table) {
        $escaped = str_replace('`', '``', (string) $table);
        $pdo->exec("DROP TABLE IF EXISTS `{$escaped}`");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
}

function goal_mysql_future_iso(int $days): string
{
    return gmdate('Y-m-d\TH:i:s\Z', time() + ($days * 86400));
}

function goal_mysql_future_database(int $days): string
{
    return gmdate('Y-m-d H:i:s', time() + ($days * 86400));
}

/** @param array<string,mixed> $payload */
function goal_mysql_run_worker(PDO $pdo, array $payload): never
{
    $connectionId = (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
    echo "READY={$connectionId}\n";
    flush();

    try {
        $kind = (string) ($payload['kind'] ?? '');
        if ($kind === 'publish') {
            $targets = $payload['targets'] ?? null;
            if (! is_array($targets)) throw new InvalidArgumentException('Worker targets are missing.');
            service_goal_policy_publish(
                $pdo,
                (string) $payload['tenant_slug'],
                (string) $payload['policy_key'],
                (int) $payload['expected_current_version'],
                (string) $payload['effective_at_utc'],
                $targets,
                (int) $payload['actor_user_id'],
                (string) $payload['reason'],
                (string) $payload['plan_sha256'],
            );
            $result = 'published';
        } elseif ($kind === 'deactivate') {
            $update = $pdo->prepare(
                'UPDATE users SET is_active = 0 WHERE tenant_id = ? AND id = ?',
            );
            $update->execute([(int) $payload['tenant_id'], (int) $payload['actor_user_id']]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Worker did not deactivate exactly one actor.');
            }
            $result = 'deactivated';
        } else {
            throw new InvalidArgumentException('Worker kind is unsupported.');
        }
    } catch (ServiceGoalPolicyConflictException) {
        $result = 'conflict';
    } catch (ServiceGoalPolicyGateException) {
        $result = 'gate';
    } catch (Throwable $error) {
        fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n");
        $result = 'error';
    }

    echo "RESULT={$result}\n";
    flush();
    exit($result === 'error' ? 3 : 0);
}

/** @param array<string,mixed> $payload
 *  @return array{process:resource,pipes:array<int,resource>,stdout:string,stderr:string}
 */
function goal_mysql_spawn_worker(array $payload): array
{
    $encoded = base64_encode(json_encode($payload, JSON_THROW_ON_ERROR));
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, __FILE__, 'worker', $encoded],
        $descriptors,
        $pipes,
        null,
        null,
        ['bypass_shell' => true],
    );
    if (! is_resource($process)) throw new RuntimeException('Could not start MySQL race worker.');
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    return ['process' => $process, 'pipes' => $pipes, 'stdout' => '', 'stderr' => ''];
}

/** @param array{process:resource,pipes:array<int,resource>,stdout:string,stderr:string} $worker */
function goal_mysql_read_worker(array &$worker): void
{
    $stdout = stream_get_contents($worker['pipes'][1]);
    $stderr = stream_get_contents($worker['pipes'][2]);
    if (is_string($stdout)) $worker['stdout'] .= $stdout;
    if (is_string($stderr)) $worker['stderr'] .= $stderr;
}

/** @param array{process:resource,pipes:array<int,resource>,stdout:string,stderr:string} $worker */
function goal_mysql_wait_worker_ready(array &$worker, float $timeoutSeconds = 10.0): int
{
    $deadline = microtime(true) + $timeoutSeconds;
    do {
        goal_mysql_read_worker($worker);
        if (preg_match('/^READY=(\d+)$/m', $worker['stdout'], $match) === 1) {
            return (int) $match[1];
        }
        $status = proc_get_status($worker['process']);
        if (! ($status['running'] ?? false)) break;
        usleep(10000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException(
        'MySQL race worker did not become ready. ' . trim($worker['stderr']),
    );
}

/** @param list<int> $threadIds */
function goal_mysql_wait_transactions(
    PDO $pdo,
    array $threadIds,
    int $expectedCount,
    ?string $state = null,
    ?string $queryFragment = null,
    float $timeoutSeconds = 10.0,
): bool {
    if ($threadIds === []) return false;
    $placeholders = implode(',', array_fill(0, count($threadIds), '?'));
    $query = $pdo->prepare(
        "SELECT trx_mysql_thread_id, trx_state, trx_query
           FROM information_schema.innodb_trx
          WHERE trx_mysql_thread_id IN ({$placeholders})",
    );
    $deadline = microtime(true) + $timeoutSeconds;
    do {
        $query->execute($threadIds);
        $matching = 0;
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $transaction) {
            if ($state !== null && (string) $transaction['trx_state'] !== $state) continue;
            if ($queryFragment !== null
                && ! str_contains(
                    strtolower((string) ($transaction['trx_query'] ?? '')),
                    strtolower($queryFragment),
                )
            ) continue;
            $matching++;
        }
        $query->closeCursor();
        if ($matching === $expectedCount) return true;
        usleep(10000);
    } while (microtime(true) < $deadline);
    return false;
}

function goal_mysql_wait_data_lock_edge(
    PDO $pdo,
    int $requestingThreadId,
    int $blockingThreadId,
    float $timeoutSeconds = 10.0,
): bool {
    $query = $pdo->prepare(
        "SELECT EXISTS(
           SELECT 1
           FROM performance_schema.data_lock_waits lock_wait
           JOIN performance_schema.threads requesting_thread
             ON requesting_thread.thread_id = lock_wait.requesting_thread_id
           JOIN performance_schema.threads blocking_thread
             ON blocking_thread.thread_id = lock_wait.blocking_thread_id
          WHERE requesting_thread.processlist_id = ?
            AND blocking_thread.processlist_id = ?
        )",
    );
    $deadline = microtime(true) + $timeoutSeconds;
    do {
        $query->execute([$requestingThreadId, $blockingThreadId]);
        $waiting = (int) $query->fetchColumn() === 1;
        $query->closeCursor();
        if ($waiting) return true;
        usleep(10000);
    } while (microtime(true) < $deadline);
    return false;
}

function goal_mysql_wait_process_state(
    PDO $pdo,
    int $threadId,
    string $stateFragment,
    float $timeoutSeconds = 10.0,
): bool {
    $query = $pdo->prepare(
        'SELECT state FROM information_schema.processlist WHERE id = ?',
    );
    $deadline = microtime(true) + $timeoutSeconds;
    do {
        $query->execute([$threadId]);
        $state = strtolower((string) ($query->fetchColumn() ?: ''));
        $query->closeCursor();
        if (str_contains($state, strtolower($stateFragment))) return true;
        usleep(10000);
    } while (microtime(true) < $deadline);
    return false;
}

/** @param array{process:resource,pipes:array<int,resource>,stdout:string,stderr:string} $worker
 *  @return array{result:string,stdout:string,stderr:string}
 */
function goal_mysql_collect_worker(array &$worker, float $timeoutSeconds = 15.0): array
{
    $deadline = microtime(true) + $timeoutSeconds;
    do {
        goal_mysql_read_worker($worker);
        $status = proc_get_status($worker['process']);
        if (! ($status['running'] ?? false)) break;
        usleep(10000);
    } while (microtime(true) < $deadline);
    $status = proc_get_status($worker['process']);
    if ($status['running'] ?? false) {
        proc_terminate($worker['process']);
        throw new RuntimeException('MySQL race worker timed out.');
    }
    goal_mysql_read_worker($worker);
    fclose($worker['pipes'][1]);
    fclose($worker['pipes'][2]);
    proc_close($worker['process']);
    $result = preg_match('/^RESULT=([a-z]+)$/m', $worker['stdout'], $match) === 1
        ? $match[1]
        : 'missing';
    return ['result' => $result, 'stdout' => $worker['stdout'], 'stderr' => $worker['stderr']];
}

if (($argv[1] ?? '') === 'worker') {
    $decoded = base64_decode((string) ($argv[2] ?? ''), true);
    try {
        $payload = is_string($decoded)
            ? json_decode($decoded, true, 512, JSON_THROW_ON_ERROR)
            : null;
    } catch (JsonException) {
        $payload = null;
    }
    if (! is_array($payload)) {
        fwrite(STDERR, "Invalid worker payload.\n");
        exit(3);
    }
    goal_mysql_run_worker($pdo, $payload);
}

$migration010 = __DIR__ . '/../db/migrations/010_service_goal_policies.sql';
$migration014 = __DIR__ . '/../db/migrations/014_service_goal_policy_publication.sql';

// Actual upgrade shape: minimal pre-010 tables, migration 010, then 014.
goal_mysql_reset($pdo);
$pdo->exec("CREATE TABLE tenants (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(128) NOT NULL,
  slug VARCHAR(64) NOT NULL,
  PRIMARY KEY(id), UNIQUE KEY uq_tenants_slug(slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  full_name VARCHAR(128) NOT NULL,
  role ENUM('owner','admin','tech') NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY(id), UNIQUE KEY uq_users_tenant_id(tenant_id,id),
  CONSTRAINT fk_users_tenant FOREIGN KEY(tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE tickets (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  sla_due_at DATETIME NOT NULL,
  PRIMARY KEY(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("INSERT INTO tenants (id,name,slug) VALUES (1,'Legacy Tenant','legacy')");
goal_mysql_execute_file($pdo, $migration010);
goal_mysql_execute_file($pdo, $migration014);
goal_mysql_check('migration 014 upgrades migration-010 policy tables',
    (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema=DATABASE() AND table_name='service_goal_policy_versions'
            AND column_name IN ('created_by_user_id','reason')",
    )->fetchColumn() === 2);
goal_mysql_check('legacy v1 baselines remain truthfully unattributed',
    (int) $pdo->query(
        'SELECT COUNT(*) FROM service_goal_policy_versions
          WHERE version_no=1 AND created_by_user_id IS NULL AND reason IS NULL',
    )->fetchColumn() === 2
    && (int) $pdo->query('SELECT COUNT(*) FROM service_goal_policy_targets')->fetchColumn() === 8);
goal_mysql_execute_file($pdo, $migration014);
goal_mysql_check('migration 014 replays over the exact upgraded shape', true);

// Fresh schema contains the same final shape and the migration remains replayable.
goal_mysql_reset($pdo);
goal_mysql_execute_file($pdo, __DIR__ . '/../db/schema.sql');
goal_mysql_execute_file($pdo, $migration014);
goal_mysql_check('fresh schema and migration replay expose exact publication columns',
    $pdo->query(
        "SELECT CONCAT(column_name,':',column_type,':',is_nullable)
           FROM information_schema.columns
          WHERE table_schema=DATABASE() AND table_name='service_goal_policy_versions'
            AND column_name IN ('created_by_user_id','reason') ORDER BY ordinal_position",
    )->fetchAll(PDO::FETCH_COLUMN) === [
        'created_by_user_id:int unsigned:YES',
        'reason:varchar(500):YES',
    ]);
goal_mysql_check('fresh schema has exact actor index and composite foreign key',
    (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.statistics
          WHERE table_schema=DATABASE() AND table_name='service_goal_policy_versions'
            AND index_name='ix_goal_policy_actor'",
    )->fetchColumn() === 2
    && $pdo->query(
        "SELECT CONCAT(
                  GROUP_CONCAT(CONCAT(key_column.column_name, '=', key_column.referenced_column_name)
                               ORDER BY key_column.ordinal_position),
                  ':', MIN(referential_constraint.match_option),
                  ':', MIN(referential_constraint.update_rule),
                  ':', MIN(referential_constraint.delete_rule))
           FROM information_schema.key_column_usage key_column
           JOIN information_schema.referential_constraints referential_constraint
             ON referential_constraint.constraint_schema=key_column.constraint_schema
            AND referential_constraint.constraint_name=key_column.constraint_name
            AND referential_constraint.table_name=key_column.table_name
          WHERE key_column.constraint_schema=DATABASE()
            AND key_column.table_name='service_goal_policy_versions'
            AND key_column.constraint_name='fk_goal_policy_actor'
            AND key_column.referenced_table_name='users'
            AND referential_constraint.referenced_table_name='users'
            AND referential_constraint.unique_constraint_schema=DATABASE()
          GROUP BY key_column.constraint_name",
    )->fetchColumn() === 'tenant_id=tenant_id,created_by_user_id=id:NONE:NO ACTION:NO ACTION');
goal_mysql_check('fresh schema has exact enforced publication check tables, names, and expressions',
    $pdo->query(
        "SELECT CONCAT(table_constraint.table_name, ':', table_constraint.constraint_name, ':',
                       table_constraint.enforced, ':',
                       REGEXP_REPLACE(REPLACE(LOWER(check_constraint.check_clause), '`', ''),
                                      '[[:space:]()]', ''))
           FROM information_schema.table_constraints table_constraint
           JOIN information_schema.check_constraints check_constraint
             ON check_constraint.constraint_schema=table_constraint.constraint_schema
            AND check_constraint.constraint_name=table_constraint.constraint_name
          WHERE table_constraint.constraint_schema=DATABASE()
            AND table_constraint.constraint_name IN (
              'ck_goal_policy_version_positive','ck_goal_policy_attribution_pair',
              'ck_goal_target_response_range','ck_goal_target_resolution_null')
          ORDER BY table_constraint.constraint_name",
    )->fetchAll(PDO::FETCH_COLUMN) === [
        'service_goal_policy_versions:ck_goal_policy_attribution_pair:YES:'
            . 'created_by_user_idisnullandreasonisnullorcreated_by_user_idisnotnullandreasonisnotnull'
            . 'andchar_lengthtrimreasonbetween1and500',
        'service_goal_policy_versions:ck_goal_policy_version_positive:YES:version_no>=1',
        'service_goal_policy_targets:ck_goal_target_resolution_null:YES:resolution_minutesisnull',
        'service_goal_policy_targets:ck_goal_target_response_range:YES:first_response_minutesbetween1and525600',
    ]);
goal_mysql_check('fresh schema has exact trigger tables, events, shape, and normalized bodies',
    $pdo->query(
        "SELECT CONCAT(trigger_name, ':', event_object_table, ':', event_manipulation, ':',
                       action_timing, ':', action_orientation, ':',
                       SHA2(REGEXP_REPLACE(REPLACE(LOWER(action_statement), '`', ''),
                                           '[[:space:]]+', ''), 256))
           FROM information_schema.triggers
          WHERE trigger_schema=DATABASE()
            AND trigger_name IN ('trg_goal_policy_versions_before_insert','trg_goal_policy_targets_before_insert',
              'trg_goal_policy_versions_no_update','trg_goal_policy_versions_no_delete',
              'trg_goal_policy_targets_no_update','trg_goal_policy_targets_no_delete')
          ORDER BY trigger_name",
    )->fetchAll(PDO::FETCH_COLUMN) === [
        'trg_goal_policy_targets_before_insert:service_goal_policy_targets:INSERT:BEFORE:ROW:'
            . '4d1b65eacf962c59c155ba0b753761bbe106bc43389c985bc2e562417e243eff',
        'trg_goal_policy_targets_no_delete:service_goal_policy_targets:DELETE:BEFORE:ROW:'
            . '77beb4a9887c08e97bd12be06351a937b009c6dee5b425fd57d7f3508a3cecf1',
        'trg_goal_policy_targets_no_update:service_goal_policy_targets:UPDATE:BEFORE:ROW:'
            . '77beb4a9887c08e97bd12be06351a937b009c6dee5b425fd57d7f3508a3cecf1',
        'trg_goal_policy_versions_before_insert:service_goal_policy_versions:INSERT:BEFORE:ROW:'
            . 'e30f260752bba425fe598b373a77e51a09512009ff51e2544f8c792ff8447765',
        'trg_goal_policy_versions_no_delete:service_goal_policy_versions:DELETE:BEFORE:ROW:'
            . '0551525ab9fe377a68f2c8e5d84ea4661f667e7a677889678caa840d4b5dbfa0',
        'trg_goal_policy_versions_no_update:service_goal_policy_versions:UPDATE:BEFORE:ROW:'
            . '0551525ab9fe377a68f2c8e5d84ea4661f667e7a677889678caa840d4b5dbfa0',
    ]);

// Same-name constraint or trigger drift must be refused, not mistaken for
// the guarded shape. Each adversarial probe is repaired before continuing.
$pdo->exec('ALTER TABLE service_goal_policy_targets DROP CHECK ck_goal_target_response_range');
$pdo->exec(
    'ALTER TABLE service_goal_policy_targets ADD CONSTRAINT ck_goal_target_response_range '
    . 'CHECK (first_response_minutes >= 0)',
);
goal_mysql_throws('migration refuses a same-name weakened response-range check', PDOException::class,
    fn() => goal_mysql_execute_file($pdo, $migration014));
$pdo->exec('ALTER TABLE service_goal_policy_targets DROP CHECK ck_goal_target_response_range');
$pdo->exec(
    'ALTER TABLE service_goal_policy_targets ADD CONSTRAINT ck_goal_target_response_range '
    . 'CHECK (first_response_minutes BETWEEN 1 AND 525600) ENFORCED',
);

$pdo->exec(
    'ALTER TABLE service_goal_policy_targets ALTER CHECK ck_goal_target_resolution_null NOT ENFORCED',
);
goal_mysql_throws('migration refuses an exact-expression check that is not enforced', PDOException::class,
    fn() => goal_mysql_execute_file($pdo, $migration014));
$pdo->exec(
    'ALTER TABLE service_goal_policy_targets ALTER CHECK ck_goal_target_resolution_null ENFORCED',
);

$pdo->exec('ALTER TABLE service_goal_policy_versions DROP FOREIGN KEY fk_goal_policy_actor');
$pdo->exec(
    'ALTER TABLE service_goal_policy_versions ADD CONSTRAINT fk_goal_policy_actor '
    . 'FOREIGN KEY (tenant_id, created_by_user_id) REFERENCES users (tenant_id, id) '
    . 'ON UPDATE RESTRICT ON DELETE RESTRICT',
);
goal_mysql_check('adversarial actor foreign key keeps its same name and noncanonical actions',
    $pdo->query(
        "SELECT CONCAT(update_rule, ':', delete_rule)
           FROM information_schema.referential_constraints
          WHERE constraint_schema=DATABASE()
            AND table_name='service_goal_policy_versions'
            AND constraint_name='fk_goal_policy_actor'",
    )->fetchColumn() === 'RESTRICT:RESTRICT');
try {
    goal_mysql_execute_file($pdo, $migration014);
    goal_mysql_check('migration refuses same-name noncanonical actor-FK actions at the FK sentinel', false);
} catch (Throwable $error) {
    goal_mysql_check(
        'migration refuses same-name noncanonical actor-FK actions at the FK sentinel',
        $error instanceof PDOException
            && (int) $pdo->query('SELECT @goal_fk_exact')->fetchColumn() === 0,
    );
}
goal_mysql_check('FK refusal leaves the noncanonical fixture and enforced attribution check unchanged',
    $pdo->query(
        "SELECT CONCAT(update_rule, ':', delete_rule)
           FROM information_schema.referential_constraints
          WHERE constraint_schema=DATABASE()
            AND table_name='service_goal_policy_versions'
            AND constraint_name='fk_goal_policy_actor'",
    )->fetchColumn() === 'RESTRICT:RESTRICT'
    && $pdo->query(
        "SELECT enforced FROM information_schema.table_constraints
          WHERE constraint_schema=DATABASE()
            AND table_name='service_goal_policy_versions'
            AND constraint_name='ck_goal_policy_attribution_pair'",
    )->fetchColumn() === 'YES');
$pdo->exec('ALTER TABLE service_goal_policy_versions DROP FOREIGN KEY fk_goal_policy_actor');
$pdo->exec(
    'ALTER TABLE service_goal_policy_versions ADD CONSTRAINT fk_goal_policy_actor '
    . 'FOREIGN KEY (tenant_id, created_by_user_id) REFERENCES users (tenant_id, id) '
    . 'ON UPDATE NO ACTION ON DELETE NO ACTION',
);

$pdo->exec('DROP TRIGGER trg_goal_policy_targets_no_update');
$pdo->exec(
    'CREATE TRIGGER trg_goal_policy_targets_no_update '
    . 'BEFORE UPDATE ON service_goal_policy_targets FOR EACH ROW SET @goal_test_noop = 1',
);
goal_mysql_throws('migration refuses a same-name no-op immutable guard', PDOException::class,
    fn() => goal_mysql_execute_file($pdo, $migration014));
$pdo->exec('DROP TRIGGER trg_goal_policy_targets_no_update');
$pdo->exec(
    "CREATE TRIGGER trg_goal_policy_targets_no_update
     BEFORE UPDATE ON service_goal_policy_targets FOR EACH ROW
     SIGNAL SQLSTATE '45000'
       SET MESSAGE_TEXT = 'Service-goal policy targets are immutable'",
);

$pdo->exec('DROP TRIGGER trg_goal_policy_targets_before_insert');
$pdo->exec(
    'CREATE TRIGGER trg_goal_policy_targets_before_insert '
    . 'BEFORE INSERT ON service_goal_policy_targets FOR EACH ROW SET @goal_test_noop = 1',
);
goal_mysql_throws('migration refuses a same-name no-op publication insert guard', PDOException::class,
    fn() => goal_mysql_execute_file($pdo, $migration014));
$pdo->exec('DROP TRIGGER trg_goal_policy_targets_before_insert');
$pdo->exec('DROP TRIGGER trg_goal_policy_versions_before_insert');
goal_mysql_execute_file($pdo, $migration014);
goal_mysql_check('exact migration replay repairs the deliberately removed insert guard',
    (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.triggers
          WHERE trigger_schema=DATABASE()
            AND trigger_name='trg_goal_policy_targets_before_insert'
            AND event_object_table='service_goal_policy_targets'
            AND event_manipulation='INSERT' AND action_timing='BEFORE'
            AND action_orientation='ROW'
            AND SHA2(REGEXP_REPLACE(REPLACE(LOWER(action_statement), '`', ''),
                                    '[[:space:]]+', ''), 256)
                = '4d1b65eacf962c59c155ba0b753761bbe106bc43389c985bc2e562417e243eff'",
    )->fetchColumn() === 1);

$pdo->exec("INSERT INTO tenants (id,name,slug) VALUES
    (1,'Tenant One','one'),(2,'Tenant Two','two')");
$pdo->exec("INSERT INTO users
    (id,tenant_id,email,password_hash,full_name,initials,role,is_active) VALUES
    (101,1,'owner1@example.test','','Owner One','O1','owner',1),
    (102,1,'admin1@example.test','','Admin One','A1','admin',1),
    (103,1,'tech1@example.test','','Tech One','T1','tech',1),
    (104,1,'inactive1@example.test','','Inactive Admin','IA','admin',0),
    (201,2,'owner2@example.test','','Owner Two','O2','owner',1)");
$pdo->exec("INSERT INTO clients (id,tenant_id,name,sla_tier) VALUES
    (11,1,'Client One','premium'),(22,2,'Client Two','premium')");

service_goal_ensure_default_policies($pdo, 1);
goal_mysql_check('permanent insert guards allow only exact unattributed lazy v1 baselines',
    (int) $pdo->query(
        'SELECT COUNT(*) FROM service_goal_policy_versions
          WHERE tenant_id=1 AND version_no=1 AND created_by_user_id IS NULL AND reason IS NULL',
    )->fetchColumn() === 2
    && (int) $pdo->query(
        'SELECT COUNT(*) FROM service_goal_policy_targets WHERE tenant_id=1',
    )->fetchColumn() === 8);

service_goal_ensure_default_policies($pdo, 2);
$historyCountsBefore = [
    (int) $pdo->query('SELECT COUNT(*) FROM service_goal_policy_versions')->fetchColumn(),
    (int) $pdo->query('SELECT COUNT(*) FROM service_goal_policy_targets')->fetchColumn(),
];
$mysqlHistory = service_goal_policy_history($pdo, 1, '2026-08-27 12:00:00');
$mysqlOtherHistory = service_goal_policy_history($pdo, 2, '2026-08-27 12:00:00');
goal_mysql_check('MySQL history reader keeps both tenants and their targets isolated',
    $mysqlHistory['tenant']['id'] === 1
    && $mysqlOtherHistory['tenant']['id'] === 2
    && array_column($mysqlHistory['policies'], 'policy_key') === ['standard', 'premium']
    && array_reduce(
        [...$mysqlHistory['policies'][0]['versions'][0]['targets'], ...$mysqlHistory['policies'][1]['versions'][0]['targets']],
        static fn(bool $exact, array $target): bool => $exact && (int) $target['tenant_id'] === 1,
        true,
    )
    && array_reduce(
        [...$mysqlOtherHistory['policies'][0]['versions'][0]['targets'], ...$mysqlOtherHistory['policies'][1]['versions'][0]['targets']],
        static fn(bool $exact, array $target): bool => $exact && (int) $target['tenant_id'] === 2,
        true,
    ));
goal_mysql_check('MySQL history reader performs no lazy or attribution write',
    $historyCountsBefore === [
        (int) $pdo->query('SELECT COUNT(*) FROM service_goal_policy_versions')->fetchColumn(),
        (int) $pdo->query('SELECT COUNT(*) FROM service_goal_policy_targets')->fetchColumn(),
    ]);

$directPolicy = $pdo->prepare(
    'INSERT INTO service_goal_policy_versions
        (tenant_id,policy_key,version_no,display_name,effective_from,
         clock_mode,time_zone,pause_mode,created_by_user_id,reason)
     VALUES (?,?,?,?,?,?,?,?,?,?)',
);
$futureDatabase = goal_mysql_future_database(30);
goal_mysql_throws('database rejects a technician publication actor', PDOException::class,
    fn() => $directPolicy->execute([1,'premium',2,'Premium',$futureDatabase,'elapsed','UTC','none',103,'tech']));
goal_mysql_throws('database rejects an inactive admin publication actor', PDOException::class,
    fn() => $directPolicy->execute([1,'premium',2,'Premium',$futureDatabase,'elapsed','UTC','none',104,'inactive']));
goal_mysql_throws('database rejects a cross-tenant owner publication actor', PDOException::class,
    fn() => $directPolicy->execute([1,'premium',2,'Premium',$futureDatabase,'elapsed','UTC','none',201,'cross']));
goal_mysql_throws('database rejects skipped policy versions', PDOException::class,
    fn() => $directPolicy->execute([1,'premium',3,'Premium',$futureDatabase,'elapsed','UTC','none',101,'skip']));
goal_mysql_throws('database rejects business-hours publication', PDOException::class,
    fn() => $directPolicy->execute([1,'premium',2,'Premium',$futureDatabase,'business_hours','UTC','none',101,'unsupported']));
goal_mysql_throws('database rejects noncanonical policy key or display case', PDOException::class,
    fn() => $directPolicy->execute([1,'Premium',2,'premium',$futureDatabase,'elapsed','UTC','none',101,'wrong case']));
goal_mysql_throws('database rejects noncanonical UTC case', PDOException::class,
    fn() => $directPolicy->execute([1,'premium',2,'Premium',$futureDatabase,'elapsed','utc','none',101,'wrong timezone case']));
goal_mysql_throws('database rejects waiting-pause publication', PDOException::class,
    fn() => $directPolicy->execute([1,'premium',2,'Premium',$futureDatabase,'elapsed','UTC','waiting',101,'unsupported']));
goal_mysql_throws('database rejects backdated publication', PDOException::class,
    fn() => $directPolicy->execute([1,'premium',2,'Premium','2000-01-01 00:00:00','elapsed','UTC','none',101,'backdated']));

$pdo->beginTransaction();
try {
    $directPolicy->execute([1,'standard',2,'Standard',$futureDatabase,'elapsed','UTC','none',101,'target guard probe']);
    $probeVersion = (int) $pdo->lastInsertId();
    goal_mysql_throws('database rejects a zero first-response target', PDOException::class,
        fn() => $pdo->prepare(
            'INSERT INTO service_goal_policy_targets
                (tenant_id,policy_version_id,priority,first_response_minutes,resolution_minutes)
             VALUES (1,?,\'low\',0,NULL)',
        )->execute([$probeVersion]));
    $pdo->prepare(
        'INSERT INTO service_goal_policy_targets
            (tenant_id,policy_version_id,priority,first_response_minutes,resolution_minutes)
         VALUES (1,?,\'low\',525600,NULL)',
    )->execute([$probeVersion]);
    goal_mysql_check('database accepts the exact one-year response-minute ceiling', true);
    goal_mysql_throws('database rejects a response target above the safe due-date ceiling', PDOException::class,
        fn() => $pdo->prepare(
            'INSERT INTO service_goal_policy_targets
                (tenant_id,policy_version_id,priority,first_response_minutes,resolution_minutes)
             VALUES (1,?,\'normal\',525601,NULL)',
        )->execute([$probeVersion]));
    goal_mysql_throws('database rejects a resolution target', PDOException::class,
        fn() => $pdo->prepare(
             'INSERT INTO service_goal_policy_targets
                (tenant_id,policy_version_id,priority,first_response_minutes,resolution_minutes)
             VALUES (1,?,\'high\',60,120)',
        )->execute([$probeVersion]));
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}

$targets = ['low' => 180, 'normal' => 120, 'high' => 60, 'urgent' => 30];
$futureIso = goal_mysql_future_iso(30);
$plan = service_goal_policy_plan(
    $pdo, 'one', 'premium', 1, $futureIso,
    $targets, 101, 'MySQL publication acceptance',
);
goal_mysql_check('MySQL plan performs no write and carries exact fixed semantics',
    (int) $pdo->query(
        "SELECT COUNT(*) FROM service_goal_policy_versions
          WHERE tenant_id=1 AND policy_key='premium'",
    )->fetchColumn() === 1
    && $plan['plan']['policy']['clock_mode'] === 'elapsed'
    && $plan['plan']['policy']['time_zone'] === 'UTC'
    && $plan['plan']['policy']['pause_mode'] === 'none'
    && count($plan['plan']['policy']['targets']) === 4);
goal_mysql_throws('digest mismatch leaves MySQL unchanged', ServiceGoalPolicyConflictException::class,
    fn() => service_goal_policy_publish(
        $pdo, 'one', 'premium', 1, $futureIso,
        $targets, 101, 'MySQL publication acceptance', str_repeat('0', 64),
    ));
goal_mysql_check('digest refusal leaves only premium v1',
    (int) $pdo->query(
        "SELECT COUNT(*) FROM service_goal_policy_versions
          WHERE tenant_id=1 AND policy_key='premium'",
    )->fetchColumn() === 1);

$published = service_goal_policy_publish(
    $pdo, 'one', 'premium', 1, $futureIso,
    $targets, 101, 'MySQL publication acceptance', $plan['plan_sha256'],
);
$publishedId = (int) $published['policy']['id'];
goal_mysql_check('MySQL publishes and re-reads one attributed version plus four targets',
    (int) $published['policy']['version_no'] === 2
    && (int) $published['policy']['created_by_user_id'] === 101
    && $published['policy']['reason'] === 'MySQL publication acceptance'
    && count($published['policy']['targets']) === 4
    && (int) $pdo->query(
        "SELECT COUNT(*) FROM service_goal_policy_targets WHERE policy_version_id={$publishedId}",
    )->fetchColumn() === 4);
goal_mysql_throws('published policy facts are immutable', PDOException::class,
    fn() => $pdo->exec(
        "UPDATE service_goal_policy_versions SET reason='changed' WHERE id={$publishedId}",
    ));
goal_mysql_throws('published policy rows cannot be deleted', PDOException::class,
    fn() => $pdo->exec("DELETE FROM service_goal_policy_versions WHERE id={$publishedId}"));
$publishedTargetId = (int) $published['policy']['targets'][0]['id'];
goal_mysql_throws('published target facts are immutable', PDOException::class,
    fn() => $pdo->exec(
        "UPDATE service_goal_policy_targets SET first_response_minutes=1 WHERE id={$publishedTargetId}",
    ));
goal_mysql_throws('published target rows cannot be deleted', PDOException::class,
    fn() => $pdo->exec("DELETE FROM service_goal_policy_targets WHERE id={$publishedTargetId}"));

goal_mysql_throws('a stale publisher loses after the first commit', ServiceGoalPolicyConflictException::class,
    fn() => service_goal_policy_publish(
        $pdo, 'one', 'premium', 1, $futureIso,
        $targets, 101, 'MySQL publication acceptance', $plan['plan_sha256'],
    ));
goal_mysql_check('stale refusal creates no duplicate version',
    (int) $pdo->query(
        "SELECT COUNT(*) FROM service_goal_policy_versions
          WHERE tenant_id=1 AND policy_key='premium'",
    )->fetchColumn() === 2);

$rollbackIso = goal_mysql_future_iso(60);
$rollbackPlan = service_goal_policy_plan(
    $pdo, 'one', 'standard', 1, $rollbackIso,
    $targets, 102, 'Forced rollback probe',
);
$pdo->exec("CREATE TRIGGER trg_goal_test_fail_urgent
    BEFORE INSERT ON service_goal_policy_targets FOR EACH ROW
    BEGIN
      IF NEW.priority='urgent' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='forced target failure';
      END IF;
    END");
try {
    goal_mysql_throws('a target failure aborts the full MySQL publication', PDOException::class,
        fn() => service_goal_policy_publish(
            $pdo, 'one', 'standard', 1, $rollbackIso,
            $targets, 102, 'Forced rollback probe', $rollbackPlan['plan_sha256'],
        ));
} finally {
    $pdo->exec('DROP TRIGGER IF EXISTS trg_goal_test_fail_urgent');
}
goal_mysql_check('rollback leaves no standard v2 or partial targets',
    (int) $pdo->query(
        "SELECT COUNT(*) FROM service_goal_policy_versions
          WHERE tenant_id=1 AND policy_key='standard'",
    )->fetchColumn() === 1
    && (int) $pdo->query(
        "SELECT COUNT(*) FROM service_goal_policy_targets target
          JOIN service_goal_policy_versions policy ON policy.id=target.policy_version_id
         WHERE policy.tenant_id=1 AND policy.policy_key='standard'",
    )->fetchColumn() === 4);

// Hold the tenant lock while two independent PHP processes enter publication.
// Both transactions must overlap in InnoDB LOCK WAIT before release; then one
// exact reviewed v3 plan wins and the other refuses observed version drift.
$winnerIso = goal_mysql_future_iso(90);
$winnerTargets = ['low' => 150, 'normal' => 90, 'high' => 45, 'urgent' => 20];
$winnerPlan = service_goal_policy_plan(
    $pdo, 'one', 'premium', 2, $winnerIso,
    $winnerTargets,
    102, 'Second publisher winner',
);
$winnerPayload = [
    'kind' => 'publish',
    'tenant_slug' => 'one',
    'policy_key' => 'premium',
    'expected_current_version' => 2,
    'effective_at_utc' => $winnerIso,
    'targets' => $winnerTargets,
    'actor_user_id' => 102,
    'reason' => 'Second publisher winner',
    'plan_sha256' => $winnerPlan['plan_sha256'],
];
$tenantBarrier = new PDO($serverDsn . ";dbname={$database}", $user, $pass, $pdoOptions);
$tenantBarrier->exec("SET time_zone = '+00:00'");
$tenantBarrier->beginTransaction();
$tenantBarrier->query('SELECT id FROM tenants WHERE id=1 FOR UPDATE')->fetchColumn();
$winnerWorkerOne = goal_mysql_spawn_worker($winnerPayload);
$winnerWorkerTwo = goal_mysql_spawn_worker($winnerPayload);
try {
    $winnerThreadOne = goal_mysql_wait_worker_ready($winnerWorkerOne);
    $winnerThreadTwo = goal_mysql_wait_worker_ready($winnerWorkerTwo);
    goal_mysql_check('both reviewed publishers overlap while waiting on the same tenant lock',
        goal_mysql_wait_transactions(
            $pdo,
            [$winnerThreadOne, $winnerThreadTwo],
            2,
            'LOCK WAIT',
        ));
} finally {
    if ($tenantBarrier->inTransaction()) $tenantBarrier->commit();
}
$winnerResultOne = goal_mysql_collect_worker($winnerWorkerOne);
$winnerResultTwo = goal_mysql_collect_worker($winnerWorkerTwo);
$winnerResults = [$winnerResultOne['result'], $winnerResultTwo['result']];
sort($winnerResults);
goal_mysql_check('overlapping reviewed publishers produce exactly one winner and one conflict',
    $winnerResults === ['conflict', 'published']
    && $winnerResultOne['stderr'] === ''
    && $winnerResultTwo['stderr'] === '');
goal_mysql_check('overlapping publication has one v3 winner and no v4',
    (int) $pdo->query(
        "SELECT COUNT(*) FROM service_goal_policy_versions
          WHERE tenant_id=1 AND policy_key='premium' AND version_no=3",
    )->fetchColumn() === 1
    && (int) $pdo->query(
        "SELECT COUNT(*) FROM service_goal_policy_versions
          WHERE tenant_id=1 AND policy_key='premium' AND version_no=4",
    )->fetchColumn() === 0);

// Demotion wins first: the publisher blocks on the actor row, then re-reads
// the committed inactive state under FOR UPDATE and refuses without writing.
$demotionIso = goal_mysql_future_iso(120);
$demotionTargets = ['low' => 240, 'normal' => 150, 'high' => 75, 'urgent' => 25];
$demotionPlan = service_goal_policy_plan(
    $pdo, 'one', 'standard', 1, $demotionIso,
    $demotionTargets, 102, 'Actor demotion-first probe',
);
$demoter = new PDO($serverDsn . ";dbname={$database}", $user, $pass, $pdoOptions);
$demoter->exec("SET time_zone = '+00:00'");
$demoterThread = (int) $demoter->query('SELECT CONNECTION_ID()')->fetchColumn();
$demoter->beginTransaction();
$demoter->exec('UPDATE users SET is_active=0 WHERE tenant_id=1 AND id=102');
$demotionWorker = goal_mysql_spawn_worker([
    'kind' => 'publish',
    'tenant_slug' => 'one',
    'policy_key' => 'standard',
    'expected_current_version' => 1,
    'effective_at_utc' => $demotionIso,
    'targets' => $demotionTargets,
    'actor_user_id' => 102,
    'reason' => 'Actor demotion-first probe',
    'plan_sha256' => $demotionPlan['plan_sha256'],
]);
try {
    $demotionThread = goal_mysql_wait_worker_ready($demotionWorker);
    goal_mysql_check('publisher overlaps and waits behind an uncommitted actor demotion',
        goal_mysql_wait_data_lock_edge($pdo, $demotionThread, $demoterThread));
} finally {
    if ($demoter->inTransaction()) $demoter->commit();
}
$demotionResult = goal_mysql_collect_worker($demotionWorker);
goal_mysql_check('demotion-first race re-reads inactive actor and refuses all publication writes',
    $demotionResult['result'] === 'gate'
    && $demotionResult['stderr'] === ''
    && (int) $pdo->query(
        "SELECT COUNT(*) FROM service_goal_policy_versions
          WHERE tenant_id=1 AND policy_key='standard' AND version_no=2",
    )->fetchColumn() === 0
    && (int) $pdo->query('SELECT is_active FROM users WHERE id=102')->fetchColumn() === 0);
$pdo->exec('UPDATE users SET is_active=1 WHERE tenant_id=1 AND id=102');

// Publisher wins first: a test-only trigger waits on a parent-held named lock
// after the app has locked the actor. A simultaneous deactivation must enter
// LOCK WAIT and can commit only after the attributed publication has committed.
$publisherFirstIso = goal_mysql_future_iso(150);
$publisherFirstTargets = ['low' => 210, 'normal' => 135, 'high' => 65, 'urgent' => 22];
$publisherFirstPlan = service_goal_policy_plan(
    $pdo, 'one', 'standard', 1, $publisherFirstIso,
    $publisherFirstTargets, 102, 'Actor publisher-first probe',
);
$gateName = 'goal_actor_' . substr(hash('sha256', $database), 0, 16);
$quotedGateName = $pdo->quote($gateName);
$publicationGate = new PDO($serverDsn . ";dbname={$database}", $user, $pass, $pdoOptions);
$publicationGate->exec("SET time_zone = '+00:00'");
if ((int) $publicationGate->query(
    "SELECT GET_LOCK({$quotedGateName}, 0)",
)->fetchColumn() !== 1) {
    throw new RuntimeException('Could not acquire the actor-race publication gate.');
}
$pdo->exec("CREATE TRIGGER trg_goal_test_hold_publication
    BEFORE INSERT ON service_goal_policy_versions FOR EACH ROW
    BEGIN
      IF NEW.reason='Actor publisher-first probe' THEN
        SET @goal_actor_test_lock = GET_LOCK({$quotedGateName}, 15);
        IF @goal_actor_test_lock <> 1 THEN
          SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='actor race gate timed out';
        END IF;
        DO RELEASE_LOCK({$quotedGateName});
      END IF;
    END");
$publisherFirstWorker = goal_mysql_spawn_worker([
    'kind' => 'publish',
    'tenant_slug' => 'one',
    'policy_key' => 'standard',
    'expected_current_version' => 1,
    'effective_at_utc' => $publisherFirstIso,
    'targets' => $publisherFirstTargets,
    'actor_user_id' => 102,
    'reason' => 'Actor publisher-first probe',
    'plan_sha256' => $publisherFirstPlan['plan_sha256'],
]);
$publisherFirstThread = goal_mysql_wait_worker_ready($publisherFirstWorker);
try {
    goal_mysql_check('publisher reaches its held insert while retaining the actor lock',
        goal_mysql_wait_process_state($pdo, $publisherFirstThread, 'user lock'));
    $lateDemoterWorker = goal_mysql_spawn_worker([
        'kind' => 'deactivate',
        'tenant_id' => 1,
        'actor_user_id' => 102,
    ]);
    $lateDemoterThread = goal_mysql_wait_worker_ready($lateDemoterWorker);
    goal_mysql_check('actor deactivation cannot commit ahead of the in-flight publisher',
        goal_mysql_wait_data_lock_edge(
            $pdo,
            $lateDemoterThread,
            $publisherFirstThread,
        ));
    if ((int) $publicationGate->query(
        "SELECT RELEASE_LOCK({$quotedGateName})",
    )->fetchColumn() !== 1) {
        throw new RuntimeException('Could not release the actor-race publication gate.');
    }
    $publisherFirstResult = goal_mysql_collect_worker($publisherFirstWorker);
    $lateDemoterResult = goal_mysql_collect_worker($lateDemoterWorker);
} finally {
    $publicationGate->query("SELECT RELEASE_LOCK({$quotedGateName})")->fetchColumn();
    $pdo->exec('DROP TRIGGER IF EXISTS trg_goal_test_hold_publication');
}
goal_mysql_check('publisher-first race commits attribution before the queued deactivation',
    $publisherFirstResult['result'] === 'published'
    && $lateDemoterResult['result'] === 'deactivated'
    && $publisherFirstResult['stderr'] === ''
    && $lateDemoterResult['stderr'] === ''
    && (int) $pdo->query(
        "SELECT COUNT(*) FROM service_goal_policy_versions
          WHERE tenant_id=1 AND policy_key='standard' AND version_no=2
            AND created_by_user_id=102 AND reason='Actor publisher-first probe'",
    )->fetchColumn() === 1
    && (int) $pdo->query('SELECT is_active FROM users WHERE id=102')->fetchColumn() === 0);
$pdo->exec('UPDATE users SET is_active=1 WHERE tenant_id=1 AND id=102');

$effectiveDatabase = service_goal_policy_effective_at($futureIso)['database'];
$beforeDatabase = gmdate(
    'Y-m-d H:i:s',
    service_goal_timestamp($effectiveDatabase) - 1,
);
$beforeBoundary = service_goal_snapshot_for_new_ticket($pdo, 1, 11, 'urgent', $beforeDatabase);
$atBoundary = service_goal_snapshot_for_new_ticket($pdo, 1, 11, 'urgent', $effectiveDatabase);
goal_mysql_check('runtime selects v2 only at its exact effective boundary',
    (int) $beforeBoundary['version_no'] === 1
    && (int) $atBoundary['version_no'] === 2
    && (int) $atBoundary['first_response_minutes'] === 30);
$ticket = $pdo->prepare(
    "INSERT INTO tickets
        (tenant_id,client_id,subject,status,priority,channel,sla_due_at,
         service_goal_target_id,created_at,updated_at)
     VALUES (1,11,'snapshot','open','urgent','phone',?,?,?,?)",
);
$ticket->execute([
    $atBoundary['due_at'], $atBoundary['target_id'],
    $atBoundary['opened_at'], $atBoundary['opened_at'],
]);
$ticketId = (int) $pdo->lastInsertId();
$pdo->exec("UPDATE clients SET sla_tier='standard' WHERE id=11");
$pdo->exec("UPDATE tickets SET priority='low',status='waiting' WHERE id={$ticketId}");
$snapshot = $pdo->query(
    "SELECT service_goal_target_id,sla_due_at FROM tickets WHERE id={$ticketId}",
)->fetch(PDO::FETCH_ASSOC);
goal_mysql_check('client tier, priority, and waiting changes do not rebase tickets',
    (int) $snapshot['service_goal_target_id'] === (int) $atBoundary['target_id']
    && $snapshot['sla_due_at'] === $atBoundary['due_at']);

// A DML-only identity can use guarded rows but cannot replay trigger DDL.
$runtimeUser = 'safeharbor_goal_runtime_test';
$quotedRuntime = $server->quote($runtimeUser);
$server->exec("DROP USER IF EXISTS {$quotedRuntime}@'%' ");
$server->exec("CREATE USER {$quotedRuntime}@'%' IDENTIFIED BY 'disposable-test-only'");
$server->exec("GRANT SELECT,INSERT,UPDATE,DELETE ON {$quotedDatabase}.* TO {$quotedRuntime}@'%'");
try {
    $runtime = new PDO(
        $serverDsn . ";dbname={$database}",
        $runtimeUser,
        'disposable-test-only',
        $pdoOptions,
    );
    $runtime->exec("SET time_zone = '+00:00'");
    goal_mysql_throws('DML-only runtime identity cannot replay migration 014', PDOException::class,
        fn() => goal_mysql_execute_file($runtime, $migration014));
} finally {
    $server->exec("DROP USER IF EXISTS {$quotedRuntime}@'%'");
}
goal_mysql_check('failed runtime replay leaves all six permanent guards installed',
    (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.triggers
          WHERE trigger_schema=DATABASE()
            AND trigger_name IN ('trg_goal_policy_versions_before_insert','trg_goal_policy_targets_before_insert',
              'trg_goal_policy_versions_no_update','trg_goal_policy_versions_no_delete',
              'trg_goal_policy_targets_no_update','trg_goal_policy_targets_no_delete')",
    )->fetchColumn() === 6);

fwrite(STDOUT, "service_goal_policy_mysql_test: {$checks} checks, {$failures} failures\n");
exit($failures === 0 ? 0 : 1);
