<?php
/** Disposable-MySQL migration, interruption, rollback, and worker-race tests. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);

require_once __DIR__ . '/../lib/managed_customer_lifecycle.php';

$lifecycleMysqlChecks = 0;
$lifecycleMysqlFailures = 0;

function lifecycle_mysql_check(bool $condition, string $message): void
{
    global $lifecycleMysqlChecks, $lifecycleMysqlFailures;
    $lifecycleMysqlChecks++;
    if ($condition) {
        echo "ok {$lifecycleMysqlChecks} - {$message}\n";
        return;
    }
    $lifecycleMysqlFailures++;
    echo "FAIL {$lifecycleMysqlChecks} - {$message}\n";
}

/** @param class-string<Throwable> $class */
function lifecycle_mysql_refuses(string $class, callable $operation, string $message): void
{
    try {
        $operation();
        lifecycle_mysql_check(false, $message);
    } catch (Throwable $error) {
        lifecycle_mysql_check($error instanceof $class || $error instanceof PDOException, $message);
    }
}

/** @return list<string> */
function lifecycle_mysql_statements(string $sql): array
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

function lifecycle_mysql_execute_sql(PDO $pdo, string $sql): void
{
    foreach (lifecycle_mysql_statements($sql) as $statement) {
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

function lifecycle_mysql_execute_file(PDO $pdo, string $path): void
{
    $sql = file_get_contents($path);
    if (!is_string($sql)) throw new RuntimeException("Cannot read {$path}");
    lifecycle_mysql_execute_sql($pdo, $sql);
}

/** @return array<string,list<array<string,mixed>>> */
function lifecycle_mysql_schema_contract(PDO $pdo): array
{
    $queries = [
        'columns' => "SELECT table_name,ordinal_position,column_name,column_type,is_nullable,
                             character_set_name,collation_name,column_default,extra
                        FROM information_schema.columns
                       WHERE table_schema=DATABASE()
                         AND table_name IN ('managed_customer_lifecycle_receipts',
                                            'managed_customer_lifecycle_restore_receipts')
                       ORDER BY table_name,ordinal_position",
        'indexes' => "SELECT table_name,index_name,non_unique,seq_in_index,column_name,sub_part
                        FROM information_schema.statistics
                       WHERE table_schema=DATABASE()
                         AND table_name IN ('managed_customer_lifecycle_receipts',
                                            'managed_customer_lifecycle_restore_receipts')
                       ORDER BY table_name,index_name,seq_in_index",
        'foreign_keys' => "SELECT table_name,constraint_name,ordinal_position,column_name,
                                  referenced_table_name,referenced_column_name
                             FROM information_schema.key_column_usage
                            WHERE table_schema=DATABASE()
                              AND table_name IN ('managed_customer_lifecycle_receipts',
                                                 'managed_customer_lifecycle_restore_receipts')
                              AND referenced_table_name IS NOT NULL
                            ORDER BY table_name,constraint_name,ordinal_position",
        'checks' => "SELECT constraints_table.table_name,
                            constraints_table.constraint_name,checks_table.check_clause,
                            constraints_table.enforced
                       FROM information_schema.check_constraints checks_table
                       JOIN information_schema.table_constraints constraints_table
                         ON constraints_table.constraint_schema=checks_table.constraint_schema
                        AND constraints_table.constraint_name=checks_table.constraint_name
                      WHERE constraints_table.constraint_schema=DATABASE()
                        AND constraints_table.table_name IN ('managed_customer_lifecycle_receipts',
                                                             'managed_customer_lifecycle_restore_receipts')
                      ORDER BY constraints_table.table_name,constraints_table.constraint_name",
        'triggers' => "SELECT event_object_table,trigger_name,event_manipulation,
                              action_timing,action_statement
                         FROM information_schema.triggers
                        WHERE trigger_schema=DATABASE()
                          AND event_object_table IN ('managed_customer_lifecycle_receipts',
                                                     'managed_customer_lifecycle_restore_receipts')
                        ORDER BY event_object_table,trigger_name",
    ];
    $contract = [];
    foreach ($queries as $key => $sql) {
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) throw new RuntimeException('Schema contract query failed.');
        foreach ($rows as &$row) {
            foreach ($row as $column => $value) {
                if ($value !== null
                    && in_array(strtolower((string)$column), ['check_clause','action_statement'], true)
                ) {
                    $row[$column] = preg_replace('/\s+/', '', strtolower(str_replace('`', '', (string)$value)));
                }
            }
        }
        unset($row);
        $contract[$key] = $rows;
    }
    return $contract;
}

/** @param array<string,list<array<string,mixed>>> $left
 *  @param array<string,list<array<string,mixed>>> $right
 */
function lifecycle_mysql_first_contract_difference(array $left, array $right): string
{
    foreach (array_unique([...array_keys($left), ...array_keys($right)]) as $section) {
        $leftRows = $left[$section] ?? [];
        $rightRows = $right[$section] ?? [];
        $rowCount = max(count($leftRows), count($rightRows));
        for ($index = 0; $index < $rowCount; $index++) {
            if (($leftRows[$index] ?? null) === ($rightRows[$index] ?? null)) continue;
            return $section . '[' . $index . '] replayed='
                . json_encode($leftRows[$index] ?? null, JSON_THROW_ON_ERROR)
                . ' fresh='
                . json_encode($rightRows[$index] ?? null, JSON_THROW_ON_ERROR);
        }
    }
    return 'none';
}

function lifecycle_mysql_pre024_schema(): string
{
    $sql = file_get_contents(__DIR__ . '/../db/schema.sql');
    if (!is_string($sql)) throw new RuntimeException('Cannot read canonical schema.');
    $start = strpos($sql, '-- Managed-customer lifecycle containment receipts (migration 024)');
    $end = $start === false ? false : strpos($sql, '-- Versioned service-goal policies', $start);
    if ($start === false || $end === false || $end <= $start) {
        throw new RuntimeException('Cannot isolate exact pre-024 schema fixture.');
    }
    return substr($sql, 0, $start) . substr($sql, $end);
}

function lifecycle_mysql_connection(string $database, ?string $user = null, ?string $pass = null): PDO
{
    $host = getenv('SAFEHARBOR_LIFECYCLE_TEST_HOST') ?: '127.0.0.1';
    $port = getenv('SAFEHARBOR_LIFECYCLE_TEST_PORT') ?: '3306';
    $user ??= getenv('SAFEHARBOR_LIFECYCLE_TEST_USER') ?: 'root';
    $pass ??= getenv('SAFEHARBOR_LIFECYCLE_TEST_PASS') ?: '';
    $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
    if ($database !== '') $dsn .= ';dbname=' . $database;
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET time_zone='+00:00'");
    return $pdo;
}

/** @return array<string,mixed> */
function lifecycle_mysql_config(string $customerId): array
{
    return [
        'enabled' => true,
        'restoration_enabled' => false,
        'customer_ids' => [$customerId],
        'tenant_actors' => ['provider-one' => 101],
        'batch_size' => 5,
    ];
}

/** @return array<string,mixed> */
function lifecycle_mysql_restore_config(string $customerId): array
{
    return array_replace(
        lifecycle_mysql_config($customerId),
        ['restoration_enabled' => true],
    );
}

/** @return array<string,mixed> */
function lifecycle_mysql_activation_config(string $customerId): array
{
    return [
        'enabled' => true, 'canary_only' => true, 'customer_ids' => [$customerId],
        'tenant_actors' => ['provider-one' => 101], 'batch_size' => 5,
        'schedule_timezone' => 'America/Los_Angeles', 'delivery_weekday' => 3,
        'delivery_local_time' => '09:00:00',
    ];
}

/** @return array<string,mixed> */
function lifecycle_mysql_activation_evidence(
    string $customerId,
    string $tenantKey,
    string $slug,
    string $eventId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
): array
{
    return [
        'schema_version' => 2, 'customer_id' => $customerId, 'source_version' => 1,
        'customer_receipt_id' => hash('sha256', 'receipt:' . $customerId),
        'customer_event_id' => $eventId,
        'customer_status' => 'active', 'lifecycle_version' => 1,
        'lifecycle_transition_id' => 1, 'lifecycle_action' => 'observed_active',
        'lifecycle_evidence_sha256' => hash('sha256', 'lifecycle:' . $customerId),
        'identity_tenant_status' => 'active', 'identity_oauth_session_version' => 1,
        'lifecycle_owned' => false, 'tenant_key' => $tenantKey, 'tenant_slug' => $slug,
        'contact_version' => 1, 'recipient_email' => $slug . '@example.test',
        'generated_at_db' => '2026-08-30 16:00:00',
        'request_nonce_sha256' => str_repeat('a', 64),
        'response_sha256' => hash('sha256', 'response:' . $customerId),
    ];
}

/** @return array<string,mixed> */
function lifecycle_mysql_restore_evidence(
    string $customerId,
    int $sourceVersion,
    string $eventId,
    string $tenantKey,
    string $slug,
): array {
    $evidence = lifecycle_mysql_activation_evidence($customerId, $tenantKey, $slug);
    $evidence['source_version'] = $sourceVersion;
    $evidence['customer_event_id'] = $eventId;
    $evidence['customer_receipt_id'] = hash('sha256', 'restored-receipt:' . $customerId . ':' . $sourceVersion);
    $evidence['lifecycle_transition_id'] = $sourceVersion + 10;
    $evidence['lifecycle_action'] = 'restored';
    $evidence['lifecycle_evidence_sha256'] = hash(
        'sha256',
        'restored-lifecycle:' . $customerId . ':' . $sourceVersion,
    );
    $evidence['identity_oauth_session_version'] = $sourceVersion + 20;
    $evidence['contact_version'] = 2;
    $evidence['generated_at_db'] = '2026-08-30 17:00:00';
    $evidence['request_nonce_sha256'] = hash('sha256', 'restore-nonce:' . $customerId);
    $evidence['response_sha256'] = hash('sha256', 'restore-response:' . $customerId);
    return $evidence;
}

/** @return array<string,mixed> */
function lifecycle_mysql_report_config(string $customerId, int $clientId, string $email): array
{
    return [
        'generation_enabled' => false, 'delivery_enabled' => false, 'canary_only' => true,
        'graph_sender' => '', 'schedule_keys' => ['managed-weekly-v3:' . $customerId],
        'tenant_slugs' => ['provider-one'], 'client_keys' => ['safeharbor-client:' . $clientId],
        'recipient_emails' => [$email], 'lease_seconds' => 120,
    ];
}

/** @return array<string,mixed> */
function lifecycle_mysql_candidate(PDO $pdo, string $customerId): array
{
    $rows = managed_customer_lifecycle_candidates($pdo, lifecycle_mysql_config($customerId));
    if (count($rows) !== 1) throw new RuntimeException('MySQL lifecycle candidate missing.');
    return $rows[0];
}

function lifecycle_mysql_seed_active(PDO $pdo, string $customerId, int $clientId, int $bindingId, string $eventId, string $key, string $slug): void
{
    $insert = $pdo->prepare(
        'INSERT INTO suite_customer_sync_bindings
            (id,tenant_id,customer_id,client_id,source_version,display_name,status,
             last_event_id,last_occurred_at,last_request_sha256)
         VALUES (?,1,?,?,1,?,\'active\',?,UTC_TIMESTAMP(),?)'
    );
    $insert->execute([$bindingId, $customerId, $clientId, ucwords(str_replace('-', ' ', $slug)), $eventId, str_repeat((string)($clientId % 10), 64)]);
    $candidate = managed_customer_activation_candidates(
        $pdo,
        lifecycle_mysql_activation_config($customerId),
    )[0] ?? null;
    if (!is_array($candidate)) throw new RuntimeException('Activation seed candidate missing.');
    managed_customer_activation_apply(
        $pdo,
        $candidate,
        lifecycle_mysql_activation_evidence($customerId, $key, $slug, $eventId),
        101,
        lifecycle_mysql_activation_config($customerId),
        lifecycle_mysql_report_config($customerId, $clientId, $slug . '@example.test'),
    );
}

function lifecycle_mysql_mark_inactive(PDO $pdo, int $bindingId, string $eventId, string $hash): void
{
    $update = $pdo->prepare(
        "UPDATE suite_customer_sync_bindings
            SET source_version=source_version+1, status='inactive', last_event_id=?,
                last_occurred_at=UTC_TIMESTAMP(), last_request_sha256=?
          WHERE id=? AND status='active'"
    );
    $update->execute([$eventId, $hash, $bindingId]);
    if ($update->rowCount() !== 1) throw new RuntimeException('Inactive transition fixture failed.');
}

function lifecycle_mysql_mark_active(PDO $pdo, int $bindingId, string $eventId, string $hash): void
{
    $update = $pdo->prepare(
        "UPDATE suite_customer_sync_bindings
            SET source_version=source_version+1, status='active', last_event_id=?,
                last_occurred_at=UTC_TIMESTAMP(), last_request_sha256=?
          WHERE id=? AND status='inactive'"
    );
    $update->execute([$eventId, $hash, $bindingId]);
    if ($update->rowCount() !== 1) throw new RuntimeException('Active transition fixture failed.');
}

if (($argv[1] ?? '') === 'race-child') {
    $database = (string)($argv[2] ?? '');
    $delay = (string)($argv[3] ?? '0') === '1';
    $customerId = '11111111-1111-4111-8111-111111111111';
    try {
        $pdo = lifecycle_mysql_connection($database);
        $result = managed_customer_lifecycle_apply(
            $pdo,
            lifecycle_mysql_candidate($pdo, $customerId),
            101,
            lifecycle_mysql_config($customerId),
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

if (($argv[1] ?? '') === 'restore-race-child') {
    $database = (string)($argv[2] ?? '');
    $delay = (string)($argv[3] ?? '0') === '1';
    $customerId = '11111111-1111-4111-8111-111111111111';
    $eventId = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';
    try {
        $pdo = lifecycle_mysql_connection($database);
        $evidence = lifecycle_mysql_restore_evidence(
            $customerId,
            3,
            $eventId,
            'ewid-t91',
            'managed-one',
        );
        if (!$delay) {
            $evidence['generated_at_db'] = '2026-08-30 17:00:01';
            $evidence['request_nonce_sha256'] = hash('sha256', 'second-restore-nonce');
            $evidence['response_sha256'] = hash('sha256', 'second-restore-response');
        }
        $result = managed_customer_lifecycle_restore(
            $pdo,
            lifecycle_mysql_candidate($pdo, $customerId),
            $evidence,
            101,
            lifecycle_mysql_restore_config($customerId),
            lifecycle_mysql_report_config($customerId, 11, 'managed-one@example.test'),
            $delay ? static function (string $stage): void {
                if ($stage === 'after_restore_portal') usleep(700_000);
            } : null,
        );
        echo json_encode($result, JSON_THROW_ON_ERROR) . "\n";
        exit(0);
    } catch (Throwable $error) {
        fwrite(STDERR, $error::class . ':' . $error->getMessage() . "\n");
        exit(1);
    }
}

if (getenv('SAFEHARBOR_LIFECYCLE_TEST_DISPOSABLE_SERVER') !== '1') {
    fwrite(STDERR, "Set SAFEHARBOR_LIFECYCLE_TEST_DISPOSABLE_SERVER=1 for disposable MySQL.\n");
    exit(2);
}
$base = getenv('SAFEHARBOR_LIFECYCLE_TEST_DB') ?: 'safeharbor_lifecycle_test';
if (preg_match('/\Asafeharbor_lifecycle_test(?:_[a-z0-9_]+)?\z/D', $base) !== 1) {
    fwrite(STDERR, "Refusing destructive test database base.\n");
    exit(2);
}
$database = $base . '_' . bin2hex(random_bytes(4));
$freshDatabase = $base . '_fresh_' . bin2hex(random_bytes(4));
$runtimeUser = 'shlc_' . bin2hex(random_bytes(4));
$runtimePass = 'disposable-lifecycle-test-only';
$runtimeCreated = false;
$admin = lifecycle_mysql_connection('');
$quotedDatabase = '`' . str_replace('`', '``', $database) . '`';
$quotedFreshDatabase = '`' . str_replace('`', '``', $freshDatabase) . '`';
$admin->exec("CREATE DATABASE {$quotedDatabase} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

try {
    $pdo = lifecycle_mysql_connection($database);
    lifecycle_mysql_execute_sql($pdo, lifecycle_mysql_pre024_schema());
    lifecycle_mysql_check(
        (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema=DATABASE()
            AND table_name IN ('managed_customer_lifecycle_receipts',
                               'managed_customer_lifecycle_restore_receipts')")->fetchColumn() === 0,
        'pre-024 fixture has no lifecycle or restore receipt table',
    );
    $migration = __DIR__ . '/../db/migrations/024_managed_customer_lifecycle.sql';
    lifecycle_mysql_execute_file($pdo, $migration);
    lifecycle_mysql_execute_file($pdo, $migration);
    lifecycle_mysql_check(
        (int)$pdo->query("SELECT COUNT(*) FROM information_schema.triggers
          WHERE trigger_schema=DATABASE()
            AND event_object_table IN ('managed_customer_lifecycle_receipts',
                                       'managed_customer_lifecycle_restore_receipts')
            AND trigger_name IN ('trg_mc_lifecycle_before_insert','trg_mc_lifecycle_no_update',
                                 'trg_mc_lifecycle_no_delete','trg_mc_restore_before_insert',
                                 'trg_mc_restore_no_update','trg_mc_restore_no_delete')")->fetchColumn() === 6
        && (int)$pdo->query("SELECT COUNT(*) FROM information_schema.triggers
          WHERE trigger_schema=DATABASE()
            AND trigger_name IN ('trg_mc_lifecycle_swap_insert','trg_mc_lifecycle_swap_update','trg_mc_lifecycle_swap_delete')")->fetchColumn() === 0
        && (int)$pdo->query("SELECT COUNT(*) FROM information_schema.table_constraints
          WHERE constraint_schema=DATABASE() AND table_name='managed_customer_lifecycle_receipts'
            AND constraint_name='ck_mc_lifecycle_install_lock'")->fetchColumn() === 0,
        'migration 024 replays with six permanent guards and no swap or install blocker',
    );

    $pdo->exec('ALTER TABLE managed_customer_lifecycle_receipts
        DROP FOREIGN KEY fk_mc_lifecycle_actor');
    $pdo->exec('ALTER TABLE managed_customer_lifecycle_receipts
        ADD CONSTRAINT fk_mc_lifecycle_actor
        FOREIGN KEY (tenant_id,actor_user_id) REFERENCES users (tenant_id,id)
        ON DELETE CASCADE');
    lifecycle_mysql_refuses(
        PDOException::class,
        static fn() => lifecycle_mysql_execute_file($pdo, $migration),
        'migration refuses a same-name lifecycle foreign key with cascading deletion',
    );
    lifecycle_mysql_check(
        (int)$pdo->query("SELECT COUNT(*) FROM information_schema.triggers
          WHERE trigger_schema=DATABASE()
            AND event_object_table='managed_customer_lifecycle_receipts'")->fetchColumn() === 3,
        'foreign-key preflight refusal leaves permanent guards unchanged',
    );
    $pdo->exec('ALTER TABLE managed_customer_lifecycle_receipts
        DROP FOREIGN KEY fk_mc_lifecycle_actor');
    $pdo->exec('ALTER TABLE managed_customer_lifecycle_receipts
        ADD CONSTRAINT fk_mc_lifecycle_actor
        FOREIGN KEY (tenant_id,actor_user_id) REFERENCES users (tenant_id,id)');

    $admin->exec("CREATE USER '{$runtimeUser}'@'%' IDENTIFIED BY '{$runtimePass}'");
    $runtimeCreated = true;
    $admin->exec("GRANT SELECT ON {$quotedDatabase}.* TO '{$runtimeUser}'@'%'");
    $admin->exec("GRANT UPDATE ON {$quotedDatabase}.`customer_portal_bindings` TO '{$runtimeUser}'@'%'");
    $admin->exec("GRANT INSERT ON {$quotedDatabase}.`business_report_schedule_versions` TO '{$runtimeUser}'@'%'");
    $admin->exec("GRANT INSERT ON {$quotedDatabase}.`business_report_id_client_contact_snapshots` TO '{$runtimeUser}'@'%'");
    $admin->exec("GRANT INSERT ON {$quotedDatabase}.`managed_customer_lifecycle_receipts` TO '{$runtimeUser}'@'%'");
    $admin->exec("GRANT INSERT ON {$quotedDatabase}.`managed_customer_lifecycle_restore_receipts` TO '{$runtimeUser}'@'%'");
    $runtime = lifecycle_mysql_connection($database, $runtimeUser, $runtimePass);
    lifecycle_mysql_refuses(
        PDOException::class,
        static fn() => lifecycle_mysql_execute_file($runtime, $migration),
        'DML-only lifecycle identity cannot replay privileged migration 024',
    );
    lifecycle_mysql_check(
        (int)$pdo->query("SELECT COUNT(*) FROM information_schema.triggers
          WHERE trigger_schema=DATABASE() AND event_object_table='managed_customer_lifecycle_receipts'")->fetchColumn() === 3,
        'failed DML-only migration leaves all lifecycle guards installed',
    );
    lifecycle_mysql_refuses(
        PDOException::class,
        static fn() => $runtime->exec('UPDATE tickets SET id=id WHERE id=-1'),
        'runtime database grants refuse a write outside the lifecycle table allowlist',
    );

    $pdo->exec("CREATE TRIGGER trg_mc_lifecycle_swap_insert
        BEFORE INSERT ON managed_customer_lifecycle_receipts FOR EACH ROW
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Managed-customer lifecycle migration is incomplete'");
    $pdo->exec("CREATE TRIGGER trg_mc_lifecycle_swap_update
        BEFORE UPDATE ON managed_customer_lifecycle_receipts FOR EACH ROW
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Managed-customer lifecycle migration is incomplete'");
    $pdo->exec("CREATE TRIGGER trg_mc_lifecycle_swap_delete
        BEFORE DELETE ON managed_customer_lifecycle_receipts FOR EACH ROW
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Managed-customer lifecycle migration is incomplete'");
    $pdo->exec('DROP TRIGGER trg_mc_lifecycle_before_insert');
    $pdo->exec('DROP TRIGGER trg_mc_lifecycle_no_update');
    $pdo->exec('DROP TRIGGER trg_mc_lifecycle_no_delete');
    lifecycle_mysql_refuses(
        PDOException::class,
        static fn() => $pdo->exec("INSERT INTO managed_customer_lifecycle_receipts
            (tenant_id,client_id,source_binding_id,customer_id,source_event_receipt_id,
             source_event_id,source_version,source_status,action,portal_was_active,
             schedule_key,schedule_was_active,actor_user_id,source_request_sha256,evidence_sha256)
            VALUES (1,1,1,'11111111-1111-4111-8111-111111111111',1,
                    'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',1,'inactive','contained',0,
                    'managed-weekly-v3:11111111-1111-4111-8111-111111111111',0,1,
                    '" . str_repeat('1', 64) . "','" . str_repeat('2', 64) . "')"),
        'interrupted migration blocker refuses lifecycle inserts',
    );
    lifecycle_mysql_execute_file($pdo, $migration);
    lifecycle_mysql_check(
        (int)$pdo->query("SELECT COUNT(*) FROM information_schema.triggers
          WHERE trigger_schema=DATABASE() AND event_object_table='managed_customer_lifecycle_receipts'")->fetchColumn() === 3
        && (int)$pdo->query("SELECT COUNT(*) FROM information_schema.triggers
          WHERE trigger_schema=DATABASE()
            AND trigger_name IN ('trg_mc_lifecycle_swap_insert','trg_mc_lifecycle_swap_update','trg_mc_lifecycle_swap_delete')")->fetchColumn() === 0,
        'exact replay restores interrupted permanent guards',
    );

    $pdo->exec("CREATE TRIGGER trg_mc_restore_swap_insert
        BEFORE INSERT ON managed_customer_lifecycle_restore_receipts FOR EACH ROW
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Managed-customer restoration migration is incomplete'");
    $pdo->exec("CREATE TRIGGER trg_mc_restore_swap_update
        BEFORE UPDATE ON managed_customer_lifecycle_restore_receipts FOR EACH ROW
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Managed-customer restoration migration is incomplete'");
    $pdo->exec("CREATE TRIGGER trg_mc_restore_swap_delete
        BEFORE DELETE ON managed_customer_lifecycle_restore_receipts FOR EACH ROW
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Managed-customer restoration migration is incomplete'");
    $pdo->exec('DROP TRIGGER trg_mc_restore_before_insert');
    $pdo->exec('DROP TRIGGER trg_mc_restore_no_update');
    $pdo->exec('DROP TRIGGER trg_mc_restore_no_delete');
    lifecycle_mysql_refuses(
        PDOException::class,
        static fn() => $pdo->exec(
            'INSERT INTO managed_customer_lifecycle_restore_receipts (tenant_id) VALUES (1)'
        ),
        'interrupted restoration migration blocker refuses receipt inserts',
    );
    lifecycle_mysql_execute_file($pdo, $migration);
    lifecycle_mysql_check(
        (int)$pdo->query("SELECT COUNT(*) FROM information_schema.triggers
          WHERE trigger_schema=DATABASE()
            AND event_object_table='managed_customer_lifecycle_restore_receipts'")->fetchColumn() === 3
        && (int)$pdo->query("SELECT COUNT(*) FROM information_schema.triggers
          WHERE trigger_schema=DATABASE()
            AND trigger_name IN ('trg_mc_restore_swap_insert','trg_mc_restore_swap_update',
                                 'trg_mc_restore_swap_delete')")->fetchColumn() === 0,
        'exact replay restores interrupted restoration guards',
    );

    $pdo->exec("INSERT INTO tenants (id,name,slug) VALUES (1,'Provider One','provider-one')");
    $pdo->exec("INSERT INTO clients (id,tenant_id,name) VALUES
        (11,1,'Managed One'),(12,1,'Managed Two')");
    $pdo->exec("INSERT INTO users
        (id,tenant_id,email,password_hash,full_name,initials,role,is_active) VALUES
        (101,1,'owner@example.test','','Owner','O','owner',1),
        (102,1,'tech@example.test','','Tech','T','tech',1)");
    $definition = $pdo->prepare(
        'INSERT INTO business_report_definition_versions
            (tenant_id,definition_key,version_no,report_type,contract_json,
             contract_sha256,created_by_user_id,reason) VALUES (?,?,?,?,?,?,101,?)'
    );
    foreach ([1,2,3] as $version) {
        $definition->execute([
            1, BUSINESS_REPORT_DEFINITION_KEY, $version, BUSINESS_REPORT_TYPE,
            business_report_contract_json($version), business_report_contract_sha256($version),
            'v' . $version,
        ]);
    }

    $customerOne = '11111111-1111-4111-8111-111111111111';
    lifecycle_mysql_seed_active(
        $pdo, $customerOne, 11, 501, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'ewid-t91', 'managed-one',
    );
    lifecycle_mysql_mark_inactive(
        $pdo, 501, 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', str_repeat('b', 64),
    );
    lifecycle_mysql_check(
        !managed_customer_operational($pdo, 1, 11),
        'committed inactive source event closes the runtime boundary before worker execution',
    );

    $commandA = [PHP_BINARY, __FILE__, 'race-child', $database, '1'];
    $commandB = [PHP_BINARY, __FILE__, 'race-child', $database, '0'];
    $pipesA = []; $pipesB = [];
    $processA = proc_open($commandA, [1=>['pipe','w'],2=>['pipe','w']], $pipesA);
    usleep(100_000);
    $processB = proc_open($commandB, [1=>['pipe','w'],2=>['pipe','w']], $pipesB);
    if (!is_resource($processA) || !is_resource($processB)) {
        throw new RuntimeException('Could not start lifecycle race workers.');
    }
    $stdoutA = stream_get_contents($pipesA[1]); $stderrA = stream_get_contents($pipesA[2]);
    $stdoutB = stream_get_contents($pipesB[1]); $stderrB = stream_get_contents($pipesB[2]);
    fclose($pipesA[1]); fclose($pipesA[2]); fclose($pipesB[1]); fclose($pipesB[2]);
    $exitA = proc_close($processA); $exitB = proc_close($processB);
    $raceA = json_decode(trim((string)$stdoutA), true);
    $raceB = json_decode(trim((string)$stdoutB), true);
    $actions = is_array($raceA) && is_array($raceB)
        ? [(string)($raceA['action'] ?? ''), (string)($raceB['action'] ?? '')]
        : [];
    sort($actions);
    lifecycle_mysql_check(
        $exitA===0 && $exitB===0 && $actions===['contained','replayed'],
        'two real workers serialize to one containment and one lost-ack replay'
            . (($stderrA.$stderrB)==='' ? '' : ' (worker error: ' . trim($stderrA.$stderrB) . ')'),
    );
    lifecycle_mysql_check(
        (int)$pdo->query('SELECT COUNT(*) FROM managed_customer_lifecycle_receipts')->fetchColumn()===1
        && (string)$pdo->query('SELECT status FROM customer_portal_bindings WHERE client_id=11')->fetchColumn()==='disabled'
        && (string)$pdo->query("SELECT status FROM business_report_schedule_versions
            WHERE client_id=11 ORDER BY version_no DESC LIMIT 1")->fetchColumn()==='disabled',
        'race leaves one immutable receipt and both surfaces disabled',
    );

    lifecycle_mysql_refuses(
        PDOException::class,
        static fn() => $pdo->exec("UPDATE managed_customer_lifecycle_receipts SET action='reactivation_blocked'"),
        'database guard rejects lifecycle receipt update',
    );
    lifecycle_mysql_refuses(
        PDOException::class,
        static fn() => $pdo->exec('DELETE FROM managed_customer_lifecycle_receipts'),
        'database guard rejects lifecycle receipt delete',
    );

    lifecycle_mysql_mark_active(
        $pdo,
        501,
        'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
        str_repeat('e', 64),
    );
    lifecycle_mysql_check(
        !managed_customer_operational($pdo, 1, 11),
        'a later active source event remains latched closed before restored ID evidence',
    );
    $blockedActive = managed_customer_lifecycle_apply(
        $runtime,
        lifecycle_mysql_candidate($pdo, $customerOne),
        101,
        lifecycle_mysql_config($customerOne),
    );
    lifecycle_mysql_check(
        ($blockedActive['action'] ?? null)==='reactivation_blocked'
            && !managed_customer_operational($pdo, 1, 11),
        'active-after-inactive source is physically and logically blocked before restore',
    );

    $restoreCommandA = [PHP_BINARY, __FILE__, 'restore-race-child', $database, '1'];
    $restoreCommandB = [PHP_BINARY, __FILE__, 'restore-race-child', $database, '0'];
    $restorePipesA = []; $restorePipesB = [];
    $restoreProcessA = proc_open(
        $restoreCommandA,
        [1=>['pipe','w'],2=>['pipe','w']],
        $restorePipesA,
    );
    usleep(100_000);
    $restoreProcessB = proc_open(
        $restoreCommandB,
        [1=>['pipe','w'],2=>['pipe','w']],
        $restorePipesB,
    );
    if (!is_resource($restoreProcessA) || !is_resource($restoreProcessB)) {
        throw new RuntimeException('Could not start lifecycle restoration race workers.');
    }
    $restoreStdoutA = stream_get_contents($restorePipesA[1]);
    $restoreStderrA = stream_get_contents($restorePipesA[2]);
    $restoreStdoutB = stream_get_contents($restorePipesB[1]);
    $restoreStderrB = stream_get_contents($restorePipesB[2]);
    fclose($restorePipesA[1]); fclose($restorePipesA[2]);
    fclose($restorePipesB[1]); fclose($restorePipesB[2]);
    $restoreExitA = proc_close($restoreProcessA);
    $restoreExitB = proc_close($restoreProcessB);
    $restoreRaceA = json_decode(trim((string)$restoreStdoutA), true);
    $restoreRaceB = json_decode(trim((string)$restoreStdoutB), true);
    $restoreActions = is_array($restoreRaceA) && is_array($restoreRaceB)
        ? [
            (string)($restoreRaceA['action'] ?? ''),
            (string)($restoreRaceB['action'] ?? ''),
        ]
        : [];
    sort($restoreActions);
    lifecycle_mysql_check(
        $restoreExitA===0 && $restoreExitB===0
            && $restoreActions===['restore_replayed','restored'],
        'two real restore workers serialize to one restored receipt and one lost-ack replay'
            . (($restoreStderrA.$restoreStderrB)===''
                ? ''
                : ' (worker error: ' . trim($restoreStderrA.$restoreStderrB) . ')'),
    );
    lifecycle_mysql_check(
        managed_customer_operational($pdo, 1, 11)
            && (int)$pdo->query(
                'SELECT COUNT(*) FROM managed_customer_lifecycle_restore_receipts WHERE client_id=11'
            )->fetchColumn()===1
            && (string)$pdo->query(
                'SELECT status FROM customer_portal_bindings WHERE client_id=11'
            )->fetchColumn()==='active'
            && (string)$pdo->query("SELECT status FROM business_report_schedule_versions
                WHERE client_id=11 ORDER BY version_no DESC LIMIT 1")->fetchColumn()==='active'
            && (int)$pdo->query(
                'SELECT COUNT(*) FROM business_report_id_client_contact_snapshots WHERE client_id=11'
            )->fetchColumn()===2,
        'restored-only receipt reopens exact owned surfaces with one fresh contact snapshot',
    );
    lifecycle_mysql_refuses(
        PDOException::class,
        static fn() => $pdo->exec(
            'UPDATE managed_customer_lifecycle_restore_receipts SET portal_restored=0'
        ),
        'database guard rejects restore receipt update',
    );
    lifecycle_mysql_refuses(
        PDOException::class,
        static fn() => $pdo->exec('DELETE FROM managed_customer_lifecycle_restore_receipts'),
        'database guard rejects restore receipt delete',
    );

    $customerTwo = '22222222-2222-4222-8222-222222222222';
    lifecycle_mysql_seed_active(
        $pdo, $customerTwo, 12, 502, 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'ewid-t92', 'managed-two',
    );
    lifecycle_mysql_mark_inactive(
        $pdo, 502, 'dddddddd-dddd-4ddd-8ddd-dddddddddddd', str_repeat('d', 64),
    );
    $candidateTwo = lifecycle_mysql_candidate($pdo, $customerTwo);
    lifecycle_mysql_refuses(
        RuntimeException::class,
        static fn() => managed_customer_lifecycle_apply(
            $pdo,
            $candidateTwo,
            101,
            lifecycle_mysql_config($customerTwo),
            static function (string $stage): void {
                if ($stage==='after_schedule') throw new RuntimeException('fixture crash');
            },
        ),
        'injected MySQL interruption is observed',
    );
    lifecycle_mysql_check(
        (string)$pdo->query('SELECT status FROM customer_portal_bindings WHERE client_id=12')->fetchColumn()==='active'
        && (string)$pdo->query("SELECT status FROM business_report_schedule_versions
            WHERE client_id=12 ORDER BY version_no DESC LIMIT 1")->fetchColumn()==='active'
        && (int)$pdo->query('SELECT COUNT(*) FROM managed_customer_lifecycle_receipts WHERE client_id=12')->fetchColumn()===0,
        'interruption rolls back portal schedule and receipt together',
    );
    $afterCrash = managed_customer_lifecycle_apply(
        $runtime, $candidateTwo, 101, lifecycle_mysql_config($customerTwo),
    );
    lifecycle_mysql_check(
        ($afterCrash['action'] ?? null)==='contained'
        && (int)$pdo->query(
            'SELECT COUNT(*) FROM managed_customer_lifecycle_receipts WHERE client_id=12'
        )->fetchColumn() === 1,
        'least-privilege runtime exactly replays containment after interruption',
    );

    lifecycle_mysql_mark_active(
        $pdo,
        502,
        'ffffffff-ffff-4fff-8fff-ffffffffffff',
        str_repeat('f', 64),
    );
    $customerTwoActive = lifecycle_mysql_candidate($pdo, $customerTwo);
    managed_customer_lifecycle_apply(
        $runtime,
        $customerTwoActive,
        101,
        lifecycle_mysql_config($customerTwo),
    );
    lifecycle_mysql_refuses(
        RuntimeException::class,
        static fn() => managed_customer_lifecycle_restore(
            $runtime,
            $customerTwoActive,
            lifecycle_mysql_restore_evidence(
                $customerTwo,
                3,
                'ffffffff-ffff-4fff-8fff-ffffffffffff',
                'ewid-t92',
                'managed-two',
            ),
            101,
            lifecycle_mysql_restore_config($customerTwo),
            lifecycle_mysql_report_config($customerTwo, 12, 'managed-two@example.test'),
            static function (string $stage): void {
                if ($stage==='after_restore_contact') {
                    throw new RuntimeException('fixture restore crash');
                }
            },
        ),
        'injected MySQL restoration interruption is observed',
    );
    lifecycle_mysql_check(
        !managed_customer_operational($pdo, 1, 12)
            && (string)$pdo->query(
                'SELECT status FROM customer_portal_bindings WHERE client_id=12'
            )->fetchColumn()==='disabled'
            && (string)$pdo->query("SELECT status FROM business_report_schedule_versions
                WHERE client_id=12 ORDER BY version_no DESC LIMIT 1")->fetchColumn()==='disabled'
            && (int)$pdo->query(
                'SELECT COUNT(*) FROM business_report_id_client_contact_snapshots WHERE client_id=12'
            )->fetchColumn()===1
            && (int)$pdo->query(
                'SELECT COUNT(*) FROM managed_customer_lifecycle_restore_receipts WHERE client_id=12'
            )->fetchColumn()===0,
        'restoration interruption rolls back portal schedule contact and receipt together',
    );
    $afterRestoreCrash = managed_customer_lifecycle_restore(
        $runtime,
        $customerTwoActive,
        lifecycle_mysql_restore_evidence(
            $customerTwo,
            3,
            'ffffffff-ffff-4fff-8fff-ffffffffffff',
            'ewid-t92',
            'managed-two',
        ),
        101,
        lifecycle_mysql_restore_config($customerTwo),
        lifecycle_mysql_report_config($customerTwo, 12, 'managed-two@example.test'),
    );
    lifecycle_mysql_check(
        ($afterRestoreCrash['action'] ?? null)==='restored'
            && managed_customer_operational($pdo, 1, 12)
            && (int)$pdo->query(
                'SELECT COUNT(*) FROM managed_customer_lifecycle_restore_receipts WHERE client_id=12'
            )->fetchColumn()===1,
        'least-privilege runtime exactly restores after interruption',
    );

    $admin->exec("CREATE DATABASE {$quotedFreshDatabase} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $fresh = lifecycle_mysql_connection($freshDatabase);
    lifecycle_mysql_execute_file($fresh, __DIR__ . '/../db/schema.sql');
    $replayedContract = lifecycle_mysql_schema_contract($pdo);
    $freshContract = lifecycle_mysql_schema_contract($fresh);
    $parityDifference = lifecycle_mysql_first_contract_difference(
        $replayedContract,
        $freshContract,
    );
    lifecycle_mysql_check(
        $replayedContract === $freshContract,
        'fresh schema and replayed migration 024 have exact table check index FK and trigger parity'
            . ($parityDifference === 'none' ? '' : ' (' . $parityDifference . ')'),
    );

    if ($lifecycleMysqlFailures>0) {
        fwrite(STDERR, "managed_customer_lifecycle_mysql_test: {$lifecycleMysqlFailures} failure(s) / {$lifecycleMysqlChecks} checks\n");
        exit(1);
    }
    echo "managed_customer_lifecycle_mysql_test: {$lifecycleMysqlChecks} checks\n";
} finally {
    if ($runtimeCreated) {
        try {
            $admin->exec("DROP USER IF EXISTS '{$runtimeUser}'@'%'");
        } catch (Throwable) {
            // The disposable database cleanup must still run.
        }
    }
    $admin->exec("DROP DATABASE IF EXISTS {$quotedFreshDatabase}");
    $admin->exec("DROP DATABASE IF EXISTS {$quotedDatabase}");
}
