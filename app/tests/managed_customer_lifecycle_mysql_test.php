<?php
/** Disposable-MySQL migration, interruption, rollback, and worker-race tests. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);

require_once __DIR__ . '/../lib/managed_customer_lifecycle.php';
require_once __DIR__ . '/../lib/ticket_clients.php';

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

/** @param list<int> $expected */
function lifecycle_mysql_ticket_choices(PDO $pdo, array $expected, string $stage): void
{
    $clients = ticket_new_clients($pdo, 1);
    $ids = array_map(static fn(array $row): int => (int)$row['id'], $clients);
    sort($ids);
    sort($expected);
    $canonical = [];
    foreach ($pdo->query('SELECT id FROM clients WHERE tenant_id=1')->fetchAll() as $row) {
        if (managed_customer_operational($pdo, 1, (int)$row['id'])) $canonical[] = (int)$row['id'];
    }
    sort($canonical);
    lifecycle_mysql_check($ids === $expected && $ids === $canonical,
        $stage . ': bulk ticket choices match exact canonical eligibility');
    $contacts = ticket_new_contacts($pdo, 1, $clients);
    $contactClients = array_map(static fn(array $row): int => (int)$row['client_id'], $contacts);
    sort($contactClients);
    lifecycle_mysql_check($contactClients === $expected,
        $stage . ': contacts belong only to eligible clients in this tenant');
}

/** @param class-string<Throwable> $class */
function lifecycle_mysql_ticket_refuses(string $class, callable $operation, string $message): void
{
    try {
        $operation();
        lifecycle_mysql_check(false, $message);
    } catch (Throwable $error) {
        lifecycle_mysql_check($error instanceof $class, $message . ' (' . $error::class . ')');
    }
}

if (($argv[1] ?? '') === 'ticket-sync-race-child') {
    try {
        $pdo = lifecycle_mysql_connection((string)$argv[2]);
        $bindingId = (int)$argv[3];
        $clientId = $bindingId === 501 ? 11 : 12;
        $pdo->exec('SET SESSION innodb_lock_wait_timeout=5');
        $pdo->beginTransaction();
        echo "ready\n";
        flush();
        // Same producer order as suite_customer_sync_receive.
        $pdo->query('SELECT id FROM tenants WHERE id=1 FOR UPDATE')->fetchAll();
        $pdo->query('SELECT id FROM suite_customer_sync_bindings WHERE id=' . $bindingId . ' FOR UPDATE')->fetchAll();
        $pdo->query('SELECT id FROM clients WHERE id=' . $clientId . ' AND tenant_id=1 FOR UPDATE')->fetchAll();
        echo "locked\n";
        flush();
        if (($argv[4] ?? '') === 'delay') usleep(250_000);
        $eventId = $bindingId === 501
            ? '77777777-7777-4777-8777-777777777777'
            : '88888888-8888-4888-8888-888888888888';
        lifecycle_mysql_mark_inactive($pdo, $bindingId, $eventId, hash('sha256', $eventId));
        $pdo->commit();
        exit(0);
    } catch (Throwable $error) {
        fwrite(STDERR, $error::class . ':' . $error->getMessage() . "\n");
        exit(1);
    }
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
    // Existing partner-intake schema is required by the staff ticket picker.
    // Install before case transactions: MySQL DDL would implicitly commit them.
    lifecycle_mysql_execute_file($pdo, __DIR__ . '/../db/migrations/009_support_intake.sql');
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
        (11,1,'Managed One'),(12,1,'Managed Two'),(13,1,'Legacy Local')");
    $pdo->exec("INSERT INTO tenants (id,name,slug) VALUES (2,'Other Provider','other-provider')");
    $pdo->exec("INSERT INTO clients (id,tenant_id,name) VALUES (21,2,'Other Tenant Client')");
    $pdo->exec("INSERT INTO contacts (id,client_id,name,email) VALUES
        (901,11,'Managed One Contact','one@example.test'),
        (902,12,'Managed Two Contact','two@example.test'),
        (903,13,'Legacy Contact','legacy@example.test'),
        (904,21,'Other Tenant Contact','other@example.test')");
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
    lifecycle_mysql_ticket_choices($pdo, [11,12,13], 'active and legacy');
    // Routing buckets remain operational, but are not manual customer choices.
    // This cohort is separate from canonical lifecycle parity assertions below.
    $emailIntakeNote = 'Catch-all for tickets emailed by unknown senders. Reassign to the right client and it will learn their domain next time.';
    $milepostIntakeNote = 'Catch-all for Milepost alerts whose client name did not match a Safeharbor client. Reassign the ticket to the right client once the names line up.';
    $intakeInsert = $pdo->prepare('INSERT INTO clients (id,tenant_id,name,domain,notes,source_key) VALUES (?,1,?,?,?,?)');
    foreach ([
        [31, 'Email Intake', '', $emailIntakeNote, null],
        [32, 'Milepost Intake', '', $milepostIntakeNote, null],
        [33, 'Email Intake', '', null, null],
        [34, 'Milepost Intake', '', 'Ordinary local customer', null],
        [35, 'Email Intake', 'customer.example.test', $emailIntakeNote, null],
        [36, 'Renamed Local', '', $emailIntakeNote, null],
        [37, 'email intake', '', $emailIntakeNote, null],
        [38, 'Milepost Intake', '', $milepostIntakeNote, 'coastmark:ordinary-customer'],
        [39, 'Email Intake', '', 'Ordinary before form submission', null],
    ] as $intakeFixture) $intakeInsert->execute($intakeFixture);
    $pdo->exec("INSERT INTO contacts (id,client_id,name,email) VALUES
        (931,31,'Email routing contact','email-routing@example.test'),
        (932,32,'Alert routing contact','alert-routing@example.test')");
    $pdo->exec("INSERT INTO tickets (tenant_id,client_id,contact_id,subject,sla_due_at) VALUES
        (1,31,931,'Retained intake history',UTC_TIMESTAMP()+INTERVAL 1 HOUR)");
    $intakeHistoryId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO messages (ticket_id,author_name,kind,body) VALUES (?,'Tech','tech','Retained intake message')")
        ->execute([$intakeHistoryId]);
    $managedOriginal = $pdo->query('SELECT name,domain,notes FROM clients WHERE id=11')->fetch(PDO::FETCH_ASSOC);
    $pdo->prepare('UPDATE clients SET name=?,domain=?,notes=? WHERE id=11')
        ->execute(['Email Intake', '', $emailIntakeNote]);
    try {
        $manualChoices = ticket_new_clients($pdo, 1);
        $manualIds = array_map(static fn(array $row): int => (int)$row['id'], $manualChoices);
        sort($manualIds);
        lifecycle_mysql_check($manualIds === [11,12,13,33,34,35,36,37,38,39],
            'manual choices exclude only exact unbound intake signatures, preserving managed and ordinary variants');
        lifecycle_mysql_check(array_reduce($manualChoices,
            static fn(bool $valid, array $row): bool => $valid && count($row) === 3
                && isset($row['id'], $row['name'], $row['sla_tier']), true),
            'manual choices expose only the original id name and tier projection');
        $manualContacts = array_column(ticket_new_contacts($pdo, 1, $manualChoices), 'id');
        sort($manualContacts);
        lifecycle_mysql_check($manualContacts === [901,902,903],
            'manual contacts exclude routing bucket contacts and other tenants');
        lifecycle_mysql_check(managed_customer_operational($pdo, 1, 31)
            && managed_customer_operational($pdo, 1, 32),
            'both hidden intake records remain operational for ordinary routing');
        foreach ([31,32] as $intakeId) {
            $pdo->beginTransaction();
            try {
                lifecycle_mysql_ticket_refuses(TicketClientUnavailableException::class,
                    static function () use ($pdo, $intakeId): void {
                        ticket_new_client_for_create($pdo, 1, $intakeId);
                        $pdo->prepare("INSERT INTO tickets (tenant_id,client_id,subject,sla_due_at)
                            VALUES (1,?,'Unexpected manual intake ticket',UTC_TIMESTAMP()+INTERVAL 1 HOUR)")
                            ->execute([$intakeId]);
                        $pdo->prepare("INSERT INTO messages (ticket_id,author_name,kind,body) VALUES (?,'Tech','tech','Unexpected manual message')")
                            ->execute([(int)$pdo->lastInsertId()]);
                    }, 'direct intake POST refuses before ticket and message writes');
                lifecycle_mysql_check((int)$pdo->query('SELECT COUNT(*) FROM tickets')->fetchColumn() === 1
                    && (int)$pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn() === 1,
                    'refused intake POST retains historical counts before rollback');
            } finally {
                $pdo->rollBack();
            }
        }
        foreach ([11,13,33,34,35,36,37,38] as $ordinaryId) {
            $pdo->beginTransaction();
            try {
                lifecycle_mysql_check((int)ticket_new_client_for_create($pdo, 1, $ordinaryId)['id'] === $ordinaryId,
                    'managed or ordinary local variant remains available to locked POST: ' . $ordinaryId);
            } finally {
                $pdo->rollBack();
            }
        }
        // The form above included client39; its purpose changes before a fresh POST.
        $pdo->prepare('UPDATE clients SET notes=? WHERE id=39')->execute([$emailIntakeNote]);
        $pdo->beginTransaction();
        try {
            lifecycle_mysql_ticket_refuses(TicketClientUnavailableException::class,
                static fn() => ticket_new_client_for_create($pdo, 1, 39),
                'stale form cannot select a record that now has the exact intake purpose');
        } finally {
            $pdo->rollBack();
        }
        $pdo->prepare('UPDATE clients SET notes=? WHERE id=39')->execute(['Changed purpose description']);
        $pdo->beginTransaction();
        try {
            lifecycle_mysql_check((int)ticket_new_client_for_create($pdo, 1, 39)['id'] === 39,
                'changed purpose signature is conservatively treated as an ordinary local client');
        } finally {
            $pdo->rollBack();
        }
        lifecycle_mysql_check((int)$pdo->query('SELECT COUNT(*) FROM clients WHERE id IN (31,32)')->fetchColumn() === 2
            && (int)$pdo->query('SELECT COUNT(*) FROM contacts WHERE id IN (931,932)')->fetchColumn() === 2
            && (int)$pdo->query('SELECT COUNT(*) FROM tickets')->fetchColumn() === 1
            && (int)$pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn() === 1,
            'manual eligibility checks preserve routing clients contacts and ticket/message history');
    } finally {
        $pdo->prepare('DELETE FROM messages WHERE ticket_id=?')->execute([$intakeHistoryId]);
        $pdo->prepare('DELETE FROM tickets WHERE id=?')->execute([$intakeHistoryId]);
        $pdo->exec('DELETE FROM contacts WHERE id IN (931,932)');
        $pdo->exec('DELETE FROM clients WHERE tenant_id=1 AND id BETWEEN 31 AND 39');
        $pdo->prepare('UPDATE clients SET name=?,domain=?,notes=? WHERE id=11')
            ->execute([$managedOriginal['name'], $managedOriginal['domain'], $managedOriginal['notes']]);
    }
    lifecycle_mysql_ticket_choices($pdo, [11,12,13], 'intake cohort removed');
    $pdo->exec("INSERT INTO tickets (tenant_id,client_id,contact_id,subject,sla_due_at) VALUES
        (1,11,901,'Retained historical ticket',UTC_TIMESTAMP()+INTERVAL 1 HOUR)");
    $historyTicketId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO messages (ticket_id,author_name,kind,body) VALUES (?,'Tech','tech','Retained history')")
        ->execute([$historyTicketId]);
    lifecycle_mysql_mark_inactive(
        $pdo, 501, 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', str_repeat('b', 64),
    );
    lifecycle_mysql_check(
        !managed_customer_operational($pdo, 1, 11),
        'committed inactive source event closes the runtime boundary before worker execution',
    );
    lifecycle_mysql_ticket_choices($pdo, [12,13], 'inactive before containment worker');
    $pdo->beginTransaction();
    lifecycle_mysql_ticket_refuses(TicketClientUnavailableException::class,
        static fn() => ticket_new_client_for_create($pdo, 1, 11),
        'stale ticket selection refuses a retained inactive client before writes');
    $pdo->rollBack();
    lifecycle_mysql_check(
        (int)$pdo->query('SELECT COUNT(*) FROM clients WHERE id=11')->fetchColumn() === 1
        && (int)$pdo->query('SELECT COUNT(*) FROM contacts WHERE id=901')->fetchColumn() === 1
        && (int)$pdo->query('SELECT COUNT(*) FROM tickets')->fetchColumn() === 1
        && (int)$pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn() === 1,
        'refused new ticket preserves retained client contact ticket and message');
    $pdo->beginTransaction();
    lifecycle_mysql_ticket_refuses(TicketClientUnavailableException::class,
        static fn() => ticket_new_client_for_create($pdo, 1, 21),
        'new-ticket submission rejects another tenant client');
    lifecycle_mysql_ticket_refuses(TicketClientUnavailableException::class,
        static fn() => ticket_new_client_for_create($pdo, 1, 999),
        'new-ticket submission rejects missing client');
    lifecycle_mysql_check(ticket_new_client_for_create($pdo, 1, 13)['id'] === 13,
        'unbound local client remains eligible in a ticket transaction');
    $pdo->rollBack();
    lifecycle_mysql_ticket_refuses(LogicException::class,
        static fn() => ticket_new_client_for_create($pdo, 1, 13),
        'ticket eligibility cannot release its locks before a ticket transaction');

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
    lifecycle_mysql_ticket_choices($pdo, [12,13], 'active after inactive without restoration');
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
    lifecycle_mysql_ticket_choices($pdo, [11,12,13], 'exact current restoration');
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

    $producer = proc_open([PHP_BINARY, __FILE__, 'ticket-sync-race-child', $database, '501', 'delay'],
        [1=>['pipe','w'],2=>['pipe','w']], $producerPipes);
    if (!is_resource($producer)) throw new RuntimeException('Cannot start ticket sync race.');
    $producerReady = trim((string)fgets($producerPipes[1]));
    $producerLocked = trim((string)fgets($producerPipes[1]));
    $pdo->beginTransaction();
    lifecycle_mysql_ticket_refuses(TicketClientUnavailableException::class,
        static fn() => ticket_new_client_for_create($pdo, 1, 11),
        'producer-first race: ticket waits then observes committed inactive state');
    $pdo->rollBack();
    stream_get_contents($producerPipes[1]);
    $producerError = stream_get_contents($producerPipes[2]);
    fclose($producerPipes[1]); fclose($producerPipes[2]);
    lifecycle_mysql_check(proc_close($producer) === 0 && $producerReady === 'ready'
        && $producerLocked === 'locked' && $producerError === '',
        'producer-first race completes without lock-order deadlock');
    lifecycle_mysql_mark_active($pdo, 501, '99999999-9999-4999-8999-999999999999', str_repeat('9', 64));
    lifecycle_mysql_ticket_choices($pdo, [12,13], 'old restoration cannot revive a later source version');
    $pdo->beginTransaction();
    lifecycle_mysql_ticket_refuses(TicketClientUnavailableException::class,
        static fn() => ticket_new_client_for_create($pdo, 1, 11),
        'POST canonical check also rejects stale restoration receipt');
    $pdo->rollBack();

    // In the reverse ordering, the ticket belongs to the active lifetime and
    // the inactive event waits for its complete ticket/message commit.
    service_goal_ensure_default_policies($pdo, 1);
    $pdo->beginTransaction();
    ticket_new_client_for_create($pdo, 1, 12);
    $producer = proc_open([PHP_BINARY, __FILE__, 'ticket-sync-race-child', $database, '502'],
        [1=>['pipe','w'],2=>['pipe','w']], $producerPipes);
    if (!is_resource($producer)) throw new RuntimeException('Cannot start reverse ticket sync race.');
    $producerReady = trim((string)fgets($producerPipes[1]));
    usleep(100_000);
    lifecycle_mysql_check($producerReady === 'ready' && proc_get_status($producer)['running'],
        'ticket-first race: inactive writer waits for the ticket tenant lock');
    $goal = service_goal_snapshot_for_new_ticket($pdo, 1, 12, 'normal');
    $pdo->prepare('INSERT INTO tickets (tenant_id,client_id,contact_id,subject,service_goal_target_id,sla_due_at)
        VALUES (1,12,902,?, ?, ?)')->execute(['Eligible ticket before inactive', $goal['target_id'], $goal['due_at']]);
    $activeTicketId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO messages (ticket_id,author_name,kind,body) VALUES (?,'Tech','tech','Eligible response')")
        ->execute([$activeTicketId]);
    $pdo->commit();
    stream_get_contents($producerPipes[1]);
    $producerError = stream_get_contents($producerPipes[2]);
    fclose($producerPipes[1]); fclose($producerPipes[2]);
    lifecycle_mysql_check(proc_close($producer) === 0 && $producerError === ''
        && (int)$pdo->query('SELECT COUNT(*) FROM tickets')->fetchColumn() === 2
        && (int)$pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn() === 2,
        'ticket-first race commits eligible ticket/message and retains both after inactive');
    lifecycle_mysql_ticket_choices($pdo, [13], 'both managed clients contained');

    $pdo->exec("UPDATE users SET suite_subject='t9u101' WHERE id=101");
    $pdo->exec("INSERT INTO suite_managed_providers
        (tenant_id,issuer_tenant_id,provider_slug,owner_user_id,owner_subject,lease_until)
        VALUES (1,9,'provider-one',101,'t9u101',UTC_TIMESTAMP()-INTERVAL 1 MINUTE)");
    lifecycle_mysql_ticket_choices($pdo, [], 'enrolled provider unavailable');
    $pdo->beginTransaction();
    lifecycle_mysql_ticket_refuses(TicketClientUnavailableException::class,
        static fn() => ticket_new_client_for_create($pdo, 1, 13),
        'POST honors the existing enrolled-provider availability boundary');
    $pdo->rollBack();
    $pdo->exec('UPDATE suite_managed_providers SET lease_until=UTC_TIMESTAMP()+INTERVAL 1 MINUTE WHERE tenant_id=1');
    lifecycle_mysql_ticket_choices($pdo, [13], 'enrolled provider available again');

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
