<?php
/** Disposable-MySQL migration (024, 032, 034), interruption, rollback, and worker-race tests. */
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

/** The refusal must carry this exact guard or gate message. */
function lifecycle_mysql_refuses_with(string $needle, callable $operation, string $message): void
{
    try {
        $operation();
        lifecycle_mysql_check(false, $message);
    } catch (Throwable $error) {
        lifecycle_mysql_check(str_contains($error->getMessage(), $needle),
            $message . ' (' . $error::class . ')');
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
function lifecycle_mysql_report_config(
    string $customerId,
    int $clientId,
    string $email,
    string $provider = 'provider-one',
): array {
    return [
        'generation_enabled' => false, 'delivery_enabled' => false, 'canary_only' => true,
        'graph_sender' => '', 'schedule_keys' => ['managed-weekly-v3:' . $customerId],
        'tenant_slugs' => [$provider], 'client_keys' => ['safeharbor-client:' . $clientId],
        'recipient_emails' => [$email], 'lease_seconds' => 120,
    ];
}

/** Lifecycle allowlist for the separate preserved-active provider tenant. */
function lifecycle_mysql_three_config(string $customerId, bool $restore = false): array
{
    return array_replace(lifecycle_mysql_config($customerId), [
        'restoration_enabled' => $restore,
        'tenant_actors' => ['provider-three' => 301],
    ]);
}

/** @return array<string,mixed> */
function lifecycle_mysql_candidate(PDO $pdo, string $customerId): array
{
    $rows = managed_customer_lifecycle_candidates($pdo, lifecycle_mysql_config($customerId));
    if (count($rows) !== 1) throw new RuntimeException('MySQL lifecycle candidate missing.');
    return $rows[0];
}

function lifecycle_mysql_seed_active(
    PDO $pdo,
    string $customerId,
    int $clientId,
    int $bindingId,
    string $eventId,
    string $key,
    string $slug,
    int $tenantId = 1,
    string $provider = 'provider-one',
    int $actorId = 101,
    ?string $admission = null,
): void {
    $insert = $pdo->prepare(
        'INSERT INTO suite_customer_sync_bindings
            (id,tenant_id,customer_id,client_id,source_version,display_name,status,
             last_event_id,last_occurred_at,last_request_sha256,last_admission)
         VALUES (?,?,?,?,1,?,\'active\',?,UTC_TIMESTAMP(),?,?)'
    );
    $insert->execute([$bindingId, $tenantId, $customerId, $clientId, ucwords(str_replace('-', ' ', $slug)), $eventId,
        str_repeat((string)($clientId % 10), 64), $admission]);
    $activationConfig = array_replace(
        lifecycle_mysql_activation_config($customerId),
        ['tenant_actors' => [$provider => $actorId]],
    );
    $candidate = managed_customer_activation_candidates($pdo, $activationConfig)[0] ?? null;
    if (!is_array($candidate)) throw new RuntimeException('Activation seed candidate missing.');
    managed_customer_activation_apply(
        $pdo,
        $candidate,
        lifecycle_mysql_activation_evidence($customerId, $key, $slug, $eventId),
        $actorId,
        $activationConfig,
        lifecycle_mysql_report_config($customerId, $clientId, $slug . '@example.test', $provider),
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

/** A later active source version (for example a rename) after an active one. */
function lifecycle_mysql_advance_active(PDO $pdo, int $bindingId, string $eventId, string $hash): void
{
    $update = $pdo->prepare(
        "UPDATE suite_customer_sync_bindings
            SET source_version=source_version+1, last_event_id=?,
                last_occurred_at=UTC_TIMESTAMP(), last_request_sha256=?
          WHERE id=? AND status='active'"
    );
    $update->execute([$eventId, $hash, $bindingId]);
    if ($update->rowCount() !== 1) throw new RuntimeException('Active advance fixture failed.');
}

/**
 * Every portal, report, contact and containment row one client's restoration
 * could write, for byte and row-count comparison.
 *
 * @return array<string,list<array<string,mixed>>>
 */
function lifecycle_mysql_surface_rows(PDO $pdo, int $tenantId, int $clientId, string $scheduleKey): array
{
    $queries = [
        'portal' => 'SELECT * FROM customer_portal_bindings WHERE tenant_id=? AND client_id=? ORDER BY id',
        'portal_events' => 'SELECT * FROM customer_portal_binding_events WHERE tenant_id=? AND client_id=? ORDER BY id',
        'schedule' => 'SELECT * FROM business_report_schedule_versions WHERE tenant_id=? AND client_id=? ORDER BY id',
        'contacts' => 'SELECT * FROM business_report_id_client_contact_snapshots WHERE tenant_id=? AND client_id=? ORDER BY id',
        'report_archives' => 'SELECT * FROM business_report_archives WHERE tenant_id=? AND client_id=? ORDER BY id',
        'containment' => 'SELECT * FROM managed_customer_lifecycle_receipts WHERE tenant_id=? AND client_id=? ORDER BY id',
    ];
    $rows = [];
    foreach ($queries as $name => $sql) {
        $statement = $pdo->prepare($sql);
        $statement->execute([$tenantId, $clientId]);
        $rows[$name] = $statement->fetchAll(PDO::FETCH_ASSOC);
    }
    $deliveries = $pdo->prepare(
        'SELECT delivery.* FROM business_report_deliveries delivery
           JOIN business_report_schedule_versions schedule
             ON schedule.tenant_id=delivery.tenant_id AND schedule.id=delivery.schedule_version_id
          WHERE delivery.tenant_id=? AND BINARY schedule.schedule_key=BINARY ? ORDER BY delivery.id'
    );
    $deliveries->execute([$tenantId, $scheduleKey]);
    $rows['report_deliveries'] = $deliveries->fetchAll(PDO::FETCH_ASSOC);
    return $rows;
}

/**
 * The exact preserved-active restore facts for one client's current state, as the
 * runtime would compute them, for direct guard checks.
 *
 * @param array<string,mixed> $evidence
 * @return array<string,mixed>
 */
function lifecycle_mysql_preserved_facts(PDO $pdo, int $tenantId, int $clientId, int $bindingId, array $evidence, int $actorId): array
{
    $customerId = (string)$evidence['customer_id'];
    $scheduleKey = managed_customer_activation_schedule_key($customerId);
    $source = $pdo->prepare(
        'SELECT receipt.id, receipt.event_id, receipt.source_version, receipt.request_sha256
           FROM suite_customer_sync_bindings binding
           JOIN suite_customer_sync_events receipt
             ON receipt.tenant_id=binding.tenant_id AND receipt.binding_id=binding.id
            AND receipt.event_id=binding.last_event_id
          WHERE binding.tenant_id=? AND binding.id=?'
    );
    $source->execute([$tenantId, $bindingId]);
    $current = $source->fetch(PDO::FETCH_ASSOC);
    $portal = $pdo->prepare('SELECT id FROM customer_portal_bindings WHERE tenant_id=? AND client_id=?');
    $portal->execute([$tenantId, $clientId]);
    $portalId = (int)$portal->fetchColumn();
    $event = $pdo->prepare(
        'SELECT id, snapshot_json FROM customer_portal_binding_events
          WHERE tenant_id=? AND client_id=? AND binding_id=? ORDER BY id DESC LIMIT 1'
    );
    $event->execute([$tenantId, $clientId, $portalId]);
    $latestEvent = $event->fetch(PDO::FETCH_ASSOC);
    $schedule = $pdo->prepare(
        'SELECT * FROM business_report_schedule_versions
          WHERE tenant_id=? AND schedule_key=? ORDER BY version_no DESC LIMIT 1'
    );
    $schedule->execute([$tenantId, $scheduleKey]);
    $latestSchedule = $schedule->fetch(PDO::FETCH_ASSOC);
    if (!is_array($current) || $portalId < 1 || !is_array($latestEvent) || !is_array($latestSchedule)) {
        throw new RuntimeException('Preserved-active facts fixture is incomplete.');
    }
    return [
        'tenant_id' => $tenantId, 'client_id' => $clientId, 'source_binding_id' => $bindingId,
        'customer_id' => $customerId,
        'source_event_receipt_id' => (int)$current['id'],
        'source_event_id' => (string)$current['event_id'],
        'source_version' => (int)$current['source_version'],
        'source_request_sha256' => (string)$current['request_sha256'],
        'id_customer_receipt_id' => (string)$evidence['customer_receipt_id'],
        'id_customer_status' => (string)$evidence['customer_status'],
        'id_lifecycle_version' => (int)$evidence['lifecycle_version'],
        'id_lifecycle_transition_id' => (int)$evidence['lifecycle_transition_id'],
        'id_lifecycle_action' => (string)$evidence['lifecycle_action'],
        'id_lifecycle_evidence_sha256' => (string)$evidence['lifecycle_evidence_sha256'],
        'id_identity_tenant_status' => (string)$evidence['identity_tenant_status'],
        'id_oauth_session_version' => (int)$evidence['identity_oauth_session_version'],
        'id_lifecycle_owned' => 0,
        'id_tenant_key' => (string)$evidence['tenant_key'],
        'identity_tenant_slug' => (string)$evidence['tenant_slug'],
        'contact_version' => (int)$evidence['contact_version'],
        'recipient_sha256' => hash('sha256', (string)$evidence['recipient_email']),
        'id_response_generated_at' => (string)$evidence['generated_at_db'],
        'id_request_nonce_sha256' => (string)$evidence['request_nonce_sha256'],
        'id_response_sha256' => (string)$evidence['response_sha256'],
        'portal_owner_receipt_id' => null,
        'portal_binding_id' => $portalId,
        'portal_before_event_id' => (int)$latestEvent['id'],
        'portal_active_event_id' => null,
        'portal_restored' => 0,
        'portal_state_sha256' => hash('sha256', (string)$latestEvent['snapshot_json']),
        'schedule_owner_receipt_id' => null,
        'schedule_key' => $scheduleKey,
        'schedule_before_version_id' => (int)$latestSchedule['id'],
        'schedule_prepared_version_id' => null,
        'contact_snapshot_id' => null,
        'schedule_active_version_id' => null,
        'schedule_restored' => 0,
        'schedule_state_sha256' => managed_customer_lifecycle_schedule_sha256($latestSchedule),
        'actor_user_id' => $actorId,
    ];
}

/** @param array<string,mixed> $facts */
function lifecycle_mysql_insert_restore_receipt(PDO $pdo, array $facts): void
{
    $pdo->prepare(
        'INSERT INTO managed_customer_lifecycle_restore_receipts (' . implode(',', array_keys($facts))
        . ',evidence_sha256) VALUES (' . implode(',', array_fill(0, count($facts) + 1, '?')) . ')'
    )->execute([...array_values($facts), managed_customer_lifecycle_restore_receipt_sha256($facts)]);
}

/**
 * A decoded schema-3 identity-only proof (managed_customer_id_identity_decode_response() shape).
 *
 * @return array<string,mixed>
 */
function lifecycle_mysql_identity_proof(
    string $customerId,
    int $version,
    string $eventId,
    string $action,
    int $anchorVersion,
    string $anchorEvent,
    string $key,
    string $slug,
    string $provider = 'provider-one',
    int $issuer = 9,
): array {
    return [
        'schema_version' => 3, 'proof_scope' => 'identity_lifecycle_only', 'customer_id' => $customerId,
        'provider_tenant_slug' => $provider, 'source_version' => $version,
        'customer_receipt_id' => hash('sha256', 'identity-receipt:' . $customerId . ':' . $version),
        'customer_event_id' => $eventId, 'initial_receipt_id' => hash('sha256', 'receipt:' . $customerId),
        'customer_status' => 'active', 'lifecycle_version' => 1, 'lifecycle_transition_id' => $version + 30,
        'lifecycle_action' => $action,
        'lifecycle_evidence_sha256' => hash('sha256', 'identity-lifecycle:' . $customerId . ':' . $version),
        'identity_tenant_status' => 'active', 'identity_oauth_session_version' => $version + 40,
        'lifecycle_owned' => false, 'tenant_key' => $key, 'tenant_slug' => $slug,
        'issuer_suite_tenant_id' => $issuer,
        'projection_binding_sha256' => hash('sha256', 'projection:' . $customerId),
        'restoration_anchor' => [
            'source_version' => $anchorVersion,
            'receipt_id' => hash('sha256', 'anchor-receipt:' . $customerId . ':' . $anchorVersion),
            'event_id' => $anchorEvent,
            'transition_id' => $anchorVersion + 30,
            'evidence_sha256' => hash('sha256', 'anchor-lifecycle:' . $customerId . ':' . $anchorVersion),
        ],
        'generated_at_db' => '2026-08-30 18:00:00',
        'request_nonce_sha256' => hash('sha256', 'identity-nonce:' . $customerId . ':' . $version),
        'response_sha256' => hash('sha256', 'identity-response:' . $customerId . ':' . $version),
    ];
}

/**
 * The exact identity-only (034) facts for one client's current state, with the portal and schedule
 * recorded as held at their latest state (restored 0), for direct guard checks. The report-ID carrier,
 * contact facts and schedule write columns are omitted, so they insert as NULL.
 *
 * @param array<string,mixed> $proof
 * @return array<string,mixed>
 */
function lifecycle_mysql_identity_facts(PDO $pdo, int $tenantId, int $clientId, int $bindingId, array $proof, int $actorId): array
{
    $facts = lifecycle_mysql_preserved_facts($pdo, $tenantId, $clientId, $bindingId,
        $proof + ['contact_version' => 1, 'recipient_email' => 'unused@example.test'], $actorId);
    unset($facts['id_tenant_key'], $facts['contact_version'], $facts['recipient_sha256'],
        $facts['schedule_owner_receipt_id'], $facts['schedule_prepared_version_id'],
        $facts['contact_snapshot_id'], $facts['schedule_active_version_id']);
    $anchor = $proof['restoration_anchor'];
    return $facts + [
        'id_proof_scope' => 'identity_lifecycle_only',
        'id_initial_receipt_id' => (string)$proof['initial_receipt_id'],
        'id_provider_tenant_slug' => (string)$proof['provider_tenant_slug'],
        'id_issuer_suite_tenant_id' => (int)$proof['issuer_suite_tenant_id'],
        'id_projection_binding_sha256' => (string)$proof['projection_binding_sha256'],
        'id_projection_tenant_key' => (string)$proof['tenant_key'],
        'id_restore_anchor_source_version' => (int)$anchor['source_version'],
        'id_restore_anchor_receipt_id' => (string)$anchor['receipt_id'],
        'id_restore_anchor_event_id' => (string)$anchor['event_id'],
        'id_restore_anchor_transition_id' => (int)$anchor['transition_id'],
        'id_restore_anchor_evidence_sha256' => (string)$anchor['evidence_sha256'],
    ];
}

/** @param array<string,mixed> $facts */
function lifecycle_mysql_insert_identity_receipt(PDO $pdo, array $facts, ?string $digest = null): void
{
    $pdo->prepare(
        'INSERT INTO managed_customer_lifecycle_restore_receipts (' . implode(',', array_keys($facts))
        . ',evidence_sha256) VALUES (' . implode(',', array_fill(0, count($facts) + 1, '?')) . ')'
    )->execute([...array_values($facts), $digest ?? managed_customer_lifecycle_identity_restore_receipt_sha256($facts)]);
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

// A141: a concurrent provider withdrawal. It holds the provider row's exclusive lock, says so, waits,
// then commits the expired lease.
if (($argv[1] ?? '') === 'provider-withdraw-child') {
    try {
        $pdo = lifecycle_mysql_connection((string)$argv[2]);
        $pdo->beginTransaction();
        $pdo->exec('UPDATE suite_managed_providers SET lease_until=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE tenant_id=1');
        echo "locked\n";
        flush();
        usleep(1_000_000);
        $pdo->commit();
        exit(0);
    } catch (Throwable $error) {
        fwrite(STDERR, $error::class . ':' . $error->getMessage() . "\n");
        exit(1);
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

if (($argv[1] ?? '') === 'preserved-race-child') {
    $database = (string)($argv[2] ?? '');
    $delay = (string)($argv[3] ?? '0') === '1';
    $customerId = '44444444-4444-4444-8444-444444444444';
    try {
        $pdo = lifecycle_mysql_connection($database);
        $evidence = lifecycle_mysql_restore_evidence(
            $customerId,
            4,
            '42000000-0000-4000-8000-000000000004',
            'ewid-t94',
            'preserved-two',
        );
        if (!$delay) {
            $evidence['generated_at_db'] = '2026-08-30 17:00:01';
            $evidence['request_nonce_sha256'] = hash('sha256', 'second-preserved-nonce');
            $evidence['response_sha256'] = hash('sha256', 'second-preserved-response');
        }
        $result = managed_customer_lifecycle_restore(
            $pdo,
            lifecycle_mysql_candidate($pdo, $customerId),
            $evidence,
            301,
            lifecycle_mysql_three_config($customerId, true),
            lifecycle_mysql_report_config($customerId, 42, 'preserved-two@example.test', 'provider-three'),
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

    // Forward migration 032 and preserved-active restoration. A separate provider
    // tenant keeps the tenant-1 ticket-choice cohort above unchanged.
    $migration032 = __DIR__ . '/../db/migrations/032_managed_customer_preserved_active_restore.sql';
    $restoreTriggers = static fn(): array => $pdo->query(
        "SELECT trigger_name, action_statement FROM information_schema.triggers
          WHERE trigger_schema=DATABASE()
            AND event_object_table='managed_customer_lifecycle_restore_receipts'
          ORDER BY trigger_name"
    )->fetchAll(PDO::FETCH_KEY_PAIR);
    $restoreGuardsAre = static function (string $validatorMarker) use ($restoreTriggers): bool {
        $triggers = $restoreTriggers();
        return array_keys($triggers) === [
                'trg_mc_restore_before_insert', 'trg_mc_restore_no_delete', 'trg_mc_restore_no_update',
            ]
            && str_contains((string)$triggers['trg_mc_restore_before_insert'], $validatorMarker)
            && str_contains((string)$triggers['trg_mc_restore_no_update'],
                'safeharbor-managed-customer-lifecycle-restore-immutable-v1')
            && str_contains((string)$triggers['trg_mc_restore_no_delete'],
                'safeharbor-managed-customer-lifecycle-restore-immutable-v1');
    };
    $v1Validator = 'safeharbor-managed-customer-lifecycle-restore-receipt-v1';
    $v2Validator = 'safeharbor-managed-customer-lifecycle-restore-receipt-v2';
    // The exact guard 032 installs; an interrupted run leaves precisely this definition.
    $swapGuardBody = "BEGIN\n  SIGNAL SQLSTATE '45000'\n"
        . "    SET MESSAGE_TEXT='Managed-customer preserved-active restoration migration is incomplete';\nEND";
    $swapGuardSql = "CREATE TRIGGER trg_mc_restore_preserve_swap_insert\n"
        . "BEFORE INSERT ON managed_customer_lifecycle_restore_receipts\nFOR EACH ROW\n" . $swapGuardBody;
    $restoreCount = static function (int $clientId) use ($pdo): int {
        $count = $pdo->prepare(
            'SELECT COUNT(*) FROM managed_customer_lifecycle_restore_receipts WHERE tenant_id=3 AND client_id=?'
        );
        $count->execute([$clientId]);
        return (int)$count->fetchColumn();
    };

    $pdo->exec("INSERT INTO tenants (id,name,slug) VALUES (3,'Provider Three','provider-three')");
    $pdo->exec("INSERT INTO clients (id,tenant_id,name) VALUES (41,3,'Preserved One'),(42,3,'Preserved Two')");
    $pdo->exec("INSERT INTO users
        (id,tenant_id,email,password_hash,full_name,initials,role,is_active) VALUES
        (301,3,'owner-three@example.test','','Owner Three','O','owner',1)");
    $definitionThree = $pdo->prepare(
        'INSERT INTO business_report_definition_versions
            (tenant_id,definition_key,version_no,report_type,contract_json,
             contract_sha256,created_by_user_id,reason) VALUES (3,?,?,?,?,?,301,?)'
    );
    foreach ([1,2,3] as $version) {
        $definitionThree->execute([
            BUSINESS_REPORT_DEFINITION_KEY, $version, BUSINESS_REPORT_TYPE,
            business_report_contract_json($version), business_report_contract_sha256($version),
            'v' . $version,
        ]);
    }

    // One: a Lifestyle-shaped customer whose active portal and schedule were never
    // contained (inactive v2 arrived while it was off the lifecycle allowlist).
    $preservedOne = '33333333-3333-4333-8333-333333333333';
    $oneEvent = static fn(int $version): string => sprintf('31000000-0000-4000-8000-%012d', $version);
    $oneKey = managed_customer_activation_schedule_key($preservedOne);
    $oneReport = lifecycle_mysql_report_config($preservedOne, 41, 'preserved-one@example.test', 'provider-three');
    lifecycle_mysql_seed_active(
        $pdo, $preservedOne, 41, 541, $oneEvent(1), 'ewid-t93', 'preserved-one', 3, 'provider-three', 301,
    );
    lifecycle_mysql_mark_inactive($pdo, 541, $oneEvent(2), hash('sha256', $oneEvent(2)));
    lifecycle_mysql_mark_active($pdo, 541, $oneEvent(3), hash('sha256', $oneEvent(3)));
    $oneCandidate = lifecycle_mysql_candidate($pdo, $preservedOne);
    $oneEvidence = lifecycle_mysql_restore_evidence($preservedOne, 3, $oneEvent(3), 'ewid-t93', 'preserved-one');
    $oneBefore = lifecycle_mysql_surface_rows($pdo, 3, 41, $oneKey);
    lifecycle_mysql_check(
        !managed_customer_operational($pdo, 3, 41)
            && (string)$pdo->query('SELECT status FROM customer_portal_bindings WHERE tenant_id=3 AND client_id=41')->fetchColumn() === 'active'
            && (string)$pdo->query("SELECT status FROM business_report_schedule_versions
                WHERE tenant_id=3 AND client_id=41 ORDER BY version_no DESC LIMIT 1")->fetchColumn() === 'active'
            && $oneBefore['containment'] === [],
        'never-contained exact current v3 starts latched with active portal and schedule',
    );
    lifecycle_mysql_check($restoreGuardsAre($v1Validator), 'restore guards are still the 024 v1 set before 032');
    lifecycle_mysql_refuses_with(
        'Restore portal transition or preserved hold is not exact',
        static fn() => managed_customer_lifecycle_restore(
            $runtime, $oneCandidate, $oneEvidence, 301,
            lifecycle_mysql_three_config($preservedOne, true), $oneReport,
        ),
        'the 024 validator refuses a preserved-active receipt from the new runtime',
    );
    lifecycle_mysql_check(
        lifecycle_mysql_surface_rows($pdo, 3, 41, $oneKey) === $oneBefore
            && $restoreCount(41) === 0 && !managed_customer_operational($pdo, 3, 41),
        'new code before 032 writes nothing and keeps the latch',
    );

    lifecycle_mysql_refuses(
        PDOException::class,
        static fn() => lifecycle_mysql_execute_file($runtime, $migration032),
        'DML-only lifecycle identity cannot apply privileged migration 032',
    );
    lifecycle_mysql_check($restoreGuardsAre($v1Validator), 'failed DML-only 032 leaves the 024 guards unchanged');
    lifecycle_mysql_execute_file($pdo, $migration032);
    lifecycle_mysql_execute_file($pdo, $migration032);
    lifecycle_mysql_check(
        $restoreGuardsAre($v2Validator),
        'migration 032 applies and replays to the v2 validator and both immutability guards, with no swap guard',
    );

    // A same-name swap guard is trusted only by its exact fail-closed definition. One that merely carries
    // the marker, fires at the wrong time, or sits on another table is refused before the validator is
    // dropped, so restore inserts never run unguarded.
    $completedGuards = $restoreTriggers();
    foreach ([
        'a marker-only body' => "CREATE TRIGGER trg_mc_restore_preserve_swap_insert BEFORE INSERT
            ON managed_customer_lifecycle_restore_receipts FOR EACH ROW
            SET @mc_swap_marker='Managed-customer preserved-active restoration migration is incomplete'",
        'the exact body after insert' => "CREATE TRIGGER trg_mc_restore_preserve_swap_insert\n"
            . "AFTER INSERT ON managed_customer_lifecycle_restore_receipts\nFOR EACH ROW\n" . $swapGuardBody,
        'the exact body on another table' => "CREATE TRIGGER trg_mc_restore_preserve_swap_insert\n"
            . "BEFORE INSERT ON managed_customer_lifecycle_receipts\nFOR EACH ROW\n" . $swapGuardBody,
    ] as $label => $wrongSwapSql) {
        $pdo->exec($wrongSwapSql);
        foreach ([false, true] as $validatorDropped) {
            if ($validatorDropped) {
                $validatorSql = (string)$pdo->query('SHOW CREATE TRIGGER trg_mc_restore_before_insert')
                    ->fetch(PDO::FETCH_ASSOC)['SQL Original Statement'];
                $pdo->exec('DROP TRIGGER trg_mc_restore_before_insert');
            }
            $wrongState = $restoreTriggers();
            lifecycle_mysql_refuses(
                PDOException::class,
                static fn() => lifecycle_mysql_execute_file($pdo, $migration032),
                'migration 032 refuses a swap guard with ' . $label
                    . ($validatorDropped ? ' after the validator is gone' : ''),
            );
            lifecycle_mysql_check(
                $restoreTriggers() === $wrongState,
                'a refused 032 run with ' . $label . ' changes no restore-receipt trigger',
            );
            if ($validatorDropped) $pdo->exec($validatorSql);
        }
        $pdo->exec('DROP TRIGGER trg_mc_restore_preserve_swap_insert');
        lifecycle_mysql_check($restoreTriggers() === $completedGuards, 'wrong swap guard fixture ' . $label . ' removed');
    }
    // Interrupted after the new validator but before the swap guard is dropped: exact replay converges.
    $pdo->exec($swapGuardSql);
    lifecycle_mysql_execute_file($pdo, $migration032);
    lifecycle_mysql_check($restoreGuardsAre($v2Validator), 'exact 032 replay converges with the v2 validator and the swap guard');

    $pdo->exec($swapGuardSql);
    $pdo->exec('DROP TRIGGER trg_mc_restore_before_insert');
    lifecycle_mysql_refuses_with(
        'preserved-active restoration migration is incomplete',
        static fn() => managed_customer_lifecycle_restore(
            $runtime, $oneCandidate, $oneEvidence, 301,
            lifecycle_mysql_three_config($preservedOne, true), $oneReport,
        ),
        'an interrupted 032 swap refuses an otherwise exact restoration',
    );
    lifecycle_mysql_check(
        array_keys($restoreTriggers()) === [
            'trg_mc_restore_no_delete', 'trg_mc_restore_no_update', 'trg_mc_restore_preserve_swap_insert',
        ]
            && lifecycle_mysql_surface_rows($pdo, 3, 41, $oneKey) === $oneBefore && $restoreCount(41) === 0,
        'the interrupted swap keeps update/delete immutability and writes nothing',
    );
    lifecycle_mysql_execute_file($pdo, $migration032);
    lifecycle_mysql_check($restoreGuardsAre($v2Validator), 'exact 032 replay converges after an interrupted swap');

    $pdo->exec('DROP TRIGGER trg_mc_restore_before_insert');
    lifecycle_mysql_refuses(
        PDOException::class,
        static fn() => lifecycle_mysql_execute_file($pdo, $migration032),
        'migration 032 refuses a missing validator without its swap guard',
    );
    lifecycle_mysql_check(
        array_keys($restoreTriggers()) === ['trg_mc_restore_no_delete', 'trg_mc_restore_no_update'],
        'the refused 032 preflight changes nothing',
    );
    $pdo->exec($swapGuardSql);
    lifecycle_mysql_execute_file($pdo, $migration032);
    lifecycle_mysql_check($restoreGuardsAre($v2Validator), '032 converges from its own interrupted state');

    $oneResults = managed_customer_lifecycle_run(
        $runtime,
        lifecycle_mysql_three_config($preservedOne, true),
        $oneReport,
        [],
        static fn(string $customerId, array $config): array => $oneEvidence,
    );
    $oneReceipt = $pdo->query(
        'SELECT * FROM managed_customer_lifecycle_restore_receipts WHERE tenant_id=3 AND client_id=41'
    )->fetchAll(PDO::FETCH_ASSOC);
    $oneFacts = lifecycle_mysql_preserved_facts($pdo, 3, 41, 541, $oneEvidence, 301);
    lifecycle_mysql_check(
        count($oneResults) === 1
            && ($oneResults[0]['action'] ?? null) === 'restored'
            && ($oneResults[0]['portal_restored'] ?? null) === 0
            && ($oneResults[0]['report_schedule_restored'] ?? null) === 0
            && count($oneReceipt) === 1
            && (int)$oneReceipt[0]['portal_restored'] === 0
            && (int)$oneReceipt[0]['schedule_restored'] === 0
            && $oneReceipt[0]['portal_owner_receipt_id'] === null
            && $oneReceipt[0]['portal_active_event_id'] === null
            && $oneReceipt[0]['schedule_owner_receipt_id'] === null
            && $oneReceipt[0]['schedule_prepared_version_id'] === null
            && $oneReceipt[0]['contact_snapshot_id'] === null
            && $oneReceipt[0]['schedule_active_version_id'] === null
            && (int)$oneReceipt[0]['portal_before_event_id'] === $oneFacts['portal_before_event_id']
            && (string)$oneReceipt[0]['portal_state_sha256'] === $oneFacts['portal_state_sha256']
            && (int)$oneReceipt[0]['schedule_before_version_id'] === $oneFacts['schedule_before_version_id']
            && (string)$oneReceipt[0]['schedule_state_sha256'] === $oneFacts['schedule_state_sha256']
            && (string)$oneReceipt[0]['evidence_sha256'] === managed_customer_lifecycle_restore_receipt_sha256($oneFacts),
        'exact current v3 records restored=0 against the locked latest audit, version and hashes with no owner or transition',
    );
    lifecycle_mysql_check(
        lifecycle_mysql_surface_rows($pdo, 3, 41, $oneKey) === $oneBefore
            && managed_customer_operational($pdo, 3, 41),
        'restore-first preserves the portal, events, schedule, contacts, reports and containment rows byte for byte and clears the latch',
    );
    $oneReplayEvidence = $oneEvidence;
    $oneReplayEvidence['generated_at_db'] = '2026-08-30 17:00:01';
    $oneReplayEvidence['request_nonce_sha256'] = hash('sha256', 'preserved-one-replay-nonce');
    $oneReplayEvidence['response_sha256'] = hash('sha256', 'preserved-one-replay-response');
    $oneReplay = managed_customer_lifecycle_restore(
        $runtime, $oneCandidate, $oneReplayEvidence, 301,
        lifecycle_mysql_three_config($preservedOne, true), $oneReport,
    );
    lifecycle_mysql_check(
        ($oneReplay['action'] ?? null) === 'restore_replayed'
            && ($oneReplay['evidence_sha256'] ?? null) === (string)$oneReceipt[0]['evidence_sha256']
            && $restoreCount(41) === 1
            && lifecycle_mysql_surface_rows($pdo, 3, 41, $oneKey) === $oneBefore,
        'a lost-ack preserved-active replay writes nothing and returns the committed receipt',
    );

    // Three: preserved-active never compares or rewrites the report contact. ID's current recipient differs
    // from the active schedule's, even at the same contact version, and nothing is refused or written.
    $preservedThree = '55555555-5555-4555-8555-555555555555';
    $threeEvent = static fn(int $version): string => sprintf('53000000-0000-4000-8000-%012d', $version);
    $threeKey = managed_customer_activation_schedule_key($preservedThree);
    $pdo->exec("INSERT INTO clients (id,tenant_id,name) VALUES (43,3,'Preserved Three')");
    $threeReport = lifecycle_mysql_report_config($preservedThree, 43, 'preserved-three@example.test', 'provider-three');
    lifecycle_mysql_seed_active(
        $pdo, $preservedThree, 43, 543, $threeEvent(1), 'ewid-t95', 'preserved-three', 3, 'provider-three', 301,
    );
    lifecycle_mysql_mark_inactive($pdo, 543, $threeEvent(2), hash('sha256', $threeEvent(2)));
    lifecycle_mysql_mark_active($pdo, 543, $threeEvent(3), hash('sha256', $threeEvent(3)));
    $threeEvidence = lifecycle_mysql_restore_evidence($preservedThree, 3, $threeEvent(3), 'ewid-t95', 'preserved-three');
    $threeEvidence['contact_version'] = 1;
    $threeEvidence['recipient_email'] = 'changed-three@example.test';
    $threeBefore = lifecycle_mysql_surface_rows($pdo, 3, 43, $threeKey);
    $threeResult = managed_customer_lifecycle_restore(
        $runtime, lifecycle_mysql_candidate($pdo, $preservedThree), $threeEvidence, 301,
        lifecycle_mysql_three_config($preservedThree, true), $threeReport,
    );
    $threeReceipt = $pdo->query(
        'SELECT recipient_sha256, contact_version, schedule_restored, contact_snapshot_id
           FROM managed_customer_lifecycle_restore_receipts WHERE tenant_id=3 AND client_id=43'
    )->fetchAll(PDO::FETCH_ASSOC);
    lifecycle_mysql_check(
        ($threeResult['action'] ?? null) === 'restored'
            && ($threeResult['report_schedule_restored'] ?? null) === 0
            && count($threeReceipt) === 1 && $threeReceipt[0]['contact_snapshot_id'] === null
            && (string)$threeReceipt[0]['recipient_sha256'] === hash('sha256', 'changed-three@example.test')
            && lifecycle_mysql_surface_rows($pdo, 3, 43, $threeKey) === $threeBefore
            && (string)$pdo->query("SELECT recipient_email FROM business_report_schedule_versions
                WHERE tenant_id=3 AND client_id=43 ORDER BY version_no DESC LIMIT 1")->fetchColumn() === 'preserved-three@example.test'
            && managed_customer_operational($pdo, 3, 43),
        'a differing ID contact on a preserved-active schedule is recorded as evidence, never refused, and changes no recipient, schedule or contact byte',
    );

    // Two: contain v2, restore the owned surfaces at v3, then a later active v4
    // re-latches and is restored over the earlier cycle with zero surface writes.
    $preservedTwo = '44444444-4444-4444-8444-444444444444';
    $twoEvent = static fn(int $version): string => sprintf('42000000-0000-4000-8000-%012d', $version);
    $twoKey = managed_customer_activation_schedule_key($preservedTwo);
    $twoReport = lifecycle_mysql_report_config($preservedTwo, 42, 'preserved-two@example.test', 'provider-three');
    lifecycle_mysql_seed_active(
        $pdo, $preservedTwo, 42, 542, $twoEvent(1), 'ewid-t94', 'preserved-two', 3, 'provider-three', 301,
    );
    lifecycle_mysql_mark_inactive($pdo, 542, $twoEvent(2), hash('sha256', $twoEvent(2)));
    $twoContained = managed_customer_lifecycle_apply(
        $runtime, lifecycle_mysql_candidate($pdo, $preservedTwo), 301, lifecycle_mysql_three_config($preservedTwo),
    );
    lifecycle_mysql_mark_active($pdo, 542, $twoEvent(3), hash('sha256', $twoEvent(3)));
    $twoOwned = managed_customer_lifecycle_run(
        $runtime,
        lifecycle_mysql_three_config($preservedTwo, true),
        $twoReport,
        [],
        static fn(string $customerId, array $config): array
            => lifecycle_mysql_restore_evidence($preservedTwo, 3, $twoEvent(3), 'ewid-t94', 'preserved-two'),
    );
    $twoContainment = $pdo->query(
        'SELECT * FROM managed_customer_lifecycle_receipts WHERE tenant_id=3 AND client_id=42'
    )->fetchAll(PDO::FETCH_ASSOC);
    lifecycle_mysql_check(
        ($twoContained['action'] ?? null) === 'contained'
            && ($twoOwned[0]['action'] ?? null) === 'restored'
            && ($twoOwned[0]['portal_restored'] ?? null) === 1
            && ($twoOwned[0]['report_schedule_restored'] ?? null) === 1
            && count($twoContainment) === 1
            && (string)$twoContainment[0]['action'] === 'contained'
            && managed_customer_operational($pdo, 3, 42),
        'owned-disabled restoration under 032 reopens exactly the contained surfaces without a second containment',
    );
    lifecycle_mysql_advance_active($pdo, 542, $twoEvent(4), hash('sha256', $twoEvent(4)));
    lifecycle_mysql_check(!managed_customer_operational($pdo, 3, 42), 'a later active v4 re-latches the customer');
    $twoCandidate = lifecycle_mysql_candidate($pdo, $preservedTwo);
    $twoEvidence = lifecycle_mysql_restore_evidence($preservedTwo, 4, $twoEvent(4), 'ewid-t94', 'preserved-two');
    $twoBefore = lifecycle_mysql_surface_rows($pdo, 3, 42, $twoKey);
    $twoObserved = $twoEvidence;
    $twoObserved['lifecycle_action'] = 'observed_active';
    $twoWrongCustomer = $twoEvidence;
    $twoWrongCustomer['customer_id'] = $preservedOne;
    foreach ([
        'stale v3 evidence cannot clear the v4 latch'
            => lifecycle_mysql_restore_evidence($preservedTwo, 3, $twoEvent(3), 'ewid-t94', 'preserved-two'),
        'evidence for another event cannot clear the v4 latch'
            => lifecycle_mysql_restore_evidence($preservedTwo, 4, $twoEvent(5), 'ewid-t94', 'preserved-two'),
        'evidence for another customer UUID cannot clear the v4 latch' => $twoWrongCustomer,
        'observed-active evidence cannot clear the v4 latch' => $twoObserved,
    ] as $label => $badEvidence) {
        lifecycle_mysql_refuses(
            ManagedCustomerLifecycleGateException::class,
            static fn() => managed_customer_lifecycle_restore(
                $runtime, $twoCandidate, $badEvidence, 301,
                lifecycle_mysql_three_config($preservedTwo, true), $twoReport,
            ),
            $label,
        );
    }
    lifecycle_mysql_refuses(
        RuntimeException::class,
        static fn() => managed_customer_lifecycle_restore(
            $runtime, $twoCandidate, $twoEvidence, 301,
            lifecycle_mysql_three_config($preservedTwo, true), $twoReport,
            static function (string $stage): void {
                if ($stage === 'before_restore_receipt') throw new RuntimeException('fixture preserved crash');
            },
        ),
        'injected preserved-active interruption is observed',
    );
    lifecycle_mysql_check(
        lifecycle_mysql_surface_rows($pdo, 3, 42, $twoKey) === $twoBefore
            && $restoreCount(42) === 1 && !managed_customer_operational($pdo, 3, 42),
        'refused or interrupted v4 restoration writes nothing and keeps the latch',
    );

    // Direct guard checks against the exact v4 facts: the exact facts pass; a
    // consumed owner receipt or a stale audit/version is never preserved-active authority.
    $twoFacts = lifecycle_mysql_preserved_facts($pdo, 3, 42, 542, $twoEvidence, 301);
    $pdo->beginTransaction();
    try {
        lifecycle_mysql_insert_restore_receipt($pdo, $twoFacts);
        lifecycle_mysql_check(true, 'the 032 validator accepts the exact preserved-active facts');
    } catch (Throwable $error) {
        lifecycle_mysql_check(false, 'the 032 validator accepts the exact preserved-active facts (' . $error->getMessage() . ')');
    } finally {
        $pdo->rollBack();
    }
    $twoDisabledEvent = $pdo->prepare('SELECT id, snapshot_json FROM customer_portal_binding_events WHERE id=?');
    $twoDisabledEvent->execute([(int)$twoContainment[0]['portal_disabled_event_id']]);
    $twoStaleEvent = $twoDisabledEvent->fetch(PDO::FETCH_ASSOC);
    $twoActivationSchedule = $pdo->prepare(
        "SELECT * FROM business_report_schedule_versions
          WHERE tenant_id=3 AND schedule_key=? AND status='active' ORDER BY version_no LIMIT 1"
    );
    $twoActivationSchedule->execute([$twoKey]);
    $twoStaleSchedule = $twoActivationSchedule->fetch(PDO::FETCH_ASSOC);
    foreach ([
        ['Restore portal transition or preserved hold is not exact',
            ['portal_owner_receipt_id' => (int)$twoContainment[0]['id']],
            'a consumed containment owner cannot back a preserved-active portal'],
        ['Restore portal transition or preserved hold is not exact',
            ['portal_before_event_id' => (int)$twoStaleEvent['id'],
             'portal_state_sha256' => hash('sha256', (string)$twoStaleEvent['snapshot_json'])],
            'a stale portal audit cannot back a preserved-active portal'],
        ['Restore schedule transition or preserved hold is not exact',
            ['schedule_owner_receipt_id' => (int)$twoContainment[0]['id']],
            'a consumed containment owner cannot back a preserved-active schedule'],
        ['Restore schedule transition or preserved hold is not exact',
            ['schedule_before_version_id' => (int)$twoStaleSchedule['id'],
             'schedule_state_sha256' => managed_customer_lifecycle_schedule_sha256($twoStaleSchedule)],
            'a stale active schedule version cannot back a preserved-active schedule'],
    ] as [$needle, $forged, $label]) {
        lifecycle_mysql_refuses_with(
            $needle,
            static fn() => lifecycle_mysql_insert_restore_receipt($pdo, array_replace($twoFacts, $forged)),
            $label,
        );
    }
    lifecycle_mysql_check(
        $restoreCount(42) === 1 && lifecycle_mysql_surface_rows($pdo, 3, 42, $twoKey) === $twoBefore,
        'forged preserved-active receipts leave no row',
    );

    $preservedCommandA = [PHP_BINARY, __FILE__, 'preserved-race-child', $database, '1'];
    $preservedCommandB = [PHP_BINARY, __FILE__, 'preserved-race-child', $database, '0'];
    $preservedPipesA = []; $preservedPipesB = [];
    $preservedProcessA = proc_open($preservedCommandA, [1=>['pipe','w'],2=>['pipe','w']], $preservedPipesA);
    usleep(100_000);
    $preservedProcessB = proc_open($preservedCommandB, [1=>['pipe','w'],2=>['pipe','w']], $preservedPipesB);
    if (!is_resource($preservedProcessA) || !is_resource($preservedProcessB)) {
        throw new RuntimeException('Could not start preserved-active restoration race workers.');
    }
    $preservedStdoutA = stream_get_contents($preservedPipesA[1]);
    $preservedStderrA = stream_get_contents($preservedPipesA[2]);
    $preservedStdoutB = stream_get_contents($preservedPipesB[1]);
    $preservedStderrB = stream_get_contents($preservedPipesB[2]);
    fclose($preservedPipesA[1]); fclose($preservedPipesA[2]);
    fclose($preservedPipesB[1]); fclose($preservedPipesB[2]);
    $preservedExitA = proc_close($preservedProcessA);
    $preservedExitB = proc_close($preservedProcessB);
    $preservedRaceA = json_decode(trim((string)$preservedStdoutA), true);
    $preservedRaceB = json_decode(trim((string)$preservedStdoutB), true);
    $preservedActions = is_array($preservedRaceA) && is_array($preservedRaceB)
        ? [(string)($preservedRaceA['action'] ?? ''), (string)($preservedRaceB['action'] ?? '')]
        : [];
    sort($preservedActions);
    lifecycle_mysql_check(
        $preservedExitA === 0 && $preservedExitB === 0
            && $preservedActions === ['restore_replayed', 'restored'],
        'two real workers over the earlier owned cycle serialize to one preserved-active receipt and one replay'
            . (($preservedStderrA . $preservedStderrB) === ''
                ? ''
                : ' (worker error: ' . trim($preservedStderrA . $preservedStderrB) . ')'),
    );
    $twoReceipt = $pdo->prepare(
        'SELECT * FROM managed_customer_lifecycle_restore_receipts
          WHERE tenant_id=3 AND client_id=42 AND source_version=4'
    );
    $twoReceipt->execute();
    $twoReceiptRow = $twoReceipt->fetch(PDO::FETCH_ASSOC);
    lifecycle_mysql_check(
        is_array($twoReceiptRow)
            && (int)$twoReceiptRow['portal_restored'] === 0 && (int)$twoReceiptRow['schedule_restored'] === 0
            && $twoReceiptRow['portal_owner_receipt_id'] === null
            && $twoReceiptRow['schedule_owner_receipt_id'] === null
            && $restoreCount(42) === 2
            && lifecycle_mysql_surface_rows($pdo, 3, 42, $twoKey) === $twoBefore
            && managed_customer_operational($pdo, 3, 42),
        'the later exact restore keeps the earlier receipts and makes zero portal, report, contact or schedule writes',
    );

    // Human holds stay held under 032: contain v4 of One, then a person re-enables
    // and holds the portal and holds the schedule before exact restored v5 evidence.
    lifecycle_mysql_mark_inactive($pdo, 541, $oneEvent(4), hash('sha256', $oneEvent(4)));
    $oneContained = managed_customer_lifecycle_apply(
        $runtime, lifecycle_mysql_candidate($pdo, $preservedOne), 301, lifecycle_mysql_three_config($preservedOne),
    );
    lifecycle_mysql_mark_active($pdo, 541, $oneEvent(5), hash('sha256', $oneEvent(5)));
    $pdo->exec("UPDATE customer_portal_bindings
        SET status='active', status_reason='human review', last_changed_by_user_id=301
      WHERE tenant_id=3 AND client_id=41");
    $pdo->exec("UPDATE customer_portal_bindings
        SET status='disabled', status_reason='human hold', last_changed_by_user_id=301
      WHERE tenant_id=3 AND client_id=41");
    $oneLatestSchedule = $pdo->prepare(
        'SELECT * FROM business_report_schedule_versions
          WHERE tenant_id=3 AND schedule_key=? ORDER BY version_no DESC LIMIT 1'
    );
    $oneLatestSchedule->execute([$oneKey]);
    $oneHeldFrom = $oneLatestSchedule->fetch(PDO::FETCH_ASSOC);
    $pdo->prepare(
        "INSERT INTO business_report_schedule_versions
            (tenant_id,schedule_key,version_no,definition_version_id,client_id,recipient_email,
             schedule_timezone,delivery_weekday,delivery_local_time,canary,status,
             created_by_user_id,reason)
         VALUES (3,?,?,?,41,?,?,?,?,?,'disabled',301,'human hold')"
    )->execute([
        $oneKey, (int)$oneHeldFrom['version_no'] + 1, (int)$oneHeldFrom['definition_version_id'],
        (string)$oneHeldFrom['recipient_email'], (string)$oneHeldFrom['schedule_timezone'],
        (int)$oneHeldFrom['delivery_weekday'], (string)$oneHeldFrom['delivery_local_time'],
        (int)$oneHeldFrom['canary'],
    ]);
    $oneHeldBefore = lifecycle_mysql_surface_rows($pdo, 3, 41, $oneKey);
    $oneHeld = managed_customer_lifecycle_run(
        $runtime,
        lifecycle_mysql_three_config($preservedOne, true),
        $oneReport,
        [],
        static fn(string $customerId, array $config): array
            => lifecycle_mysql_restore_evidence($preservedOne, 5, $oneEvent(5), 'ewid-t93', 'preserved-one'),
    );
    $oneHeldReceipt = $pdo->query(
        'SELECT * FROM managed_customer_lifecycle_restore_receipts
          WHERE tenant_id=3 AND client_id=41 AND source_version=5'
    )->fetch(PDO::FETCH_ASSOC);
    lifecycle_mysql_check(
        ($oneContained['action'] ?? null) === 'contained'
            && ($oneHeld[0]['action'] ?? null) === 'restored'
            && ($oneHeld[0]['portal_restored'] ?? null) === 0
            && ($oneHeld[0]['report_schedule_restored'] ?? null) === 0
            && is_array($oneHeldReceipt)
            && (int)$oneHeldReceipt['portal_owner_receipt_id'] > 0
            && (int)$oneHeldReceipt['schedule_owner_receipt_id'] > 0
            && lifecycle_mysql_surface_rows($pdo, 3, 41, $oneKey) === $oneHeldBefore
            && (string)$pdo->query('SELECT status FROM customer_portal_bindings WHERE tenant_id=3 AND client_id=41')->fetchColumn() === 'disabled'
            && managed_customer_operational($pdo, 3, 41),
        'human-held portal and schedule stay held under 032 while the exact source latch clears',
    );

    // A82 `--scheduled` through the narrow runtime: the explicit scope plus an admitted provider
    // lineage, one committed cursor claim before each action, the admitted owner as actor, a
    // concurrent claim held off by the singleton lock, and a withdrawn provider refused then resumed.
    // The narrow runtime gains only UPDATE on the scheduling row (the shared runtime has table DML).
    $admin->exec("GRANT UPDATE ON {$quotedDatabase}.`managed_customer_lifecycle_progress` TO '{$runtimeUser}'@'%'");
    $pdo->exec('UPDATE suite_managed_providers SET lease_until=UTC_TIMESTAMP()+INTERVAL 4 MINUTE WHERE tenant_id=1');
    $scheduledCustomer = static fn(int $n): string => sprintf('a7000000-4444-4444-8444-%012d', $n);
    $pdo->exec("INSERT INTO clients (id,tenant_id,name) VALUES
        (71,1,'Scheduled Admitted'),(72,3,'Scheduled Explicit'),(73,1,'Scheduled Unmarked'),(74,1,'Scheduled Later')");
    $scheduledBinding = $pdo->prepare(
        "INSERT INTO suite_customer_sync_bindings
            (id,tenant_id,customer_id,client_id,source_version,display_name,status,
             last_event_id,last_occurred_at,last_request_sha256,last_admission)
         VALUES (?,?,?,?,1,?,'active',?,UTC_TIMESTAMP()-INTERVAL 1 HOUR,?,?)"
    );
    foreach ([[71, 1, 'Scheduled Admitted', 'created'], [72, 3, 'Scheduled Explicit', null],
        [73, 1, 'Scheduled Unmarked', null]] as [$id, $tenantId, $name, $tag]) {
        $scheduledBinding->execute([$id, $tenantId, $scheduledCustomer($id), $id, $name,
            sprintf('b7000000-4444-4444-8444-%012d', $id), str_repeat(dechex($id % 16), 64), $tag]);
        lifecycle_mysql_mark_inactive($pdo, $id, sprintf('c7000000-4444-4444-8444-%012d', $id), str_repeat(dechex(($id + 1) % 16), 64));
    }
    $scheduledConfig = [
        'enabled' => true, 'restoration_enabled' => false, 'customer_ids' => [$scheduledCustomer(72)],
        'tenant_actors' => ['provider-three' => 301], 'batch_size' => 5,
    ];
    $containedFor = static function (int $id) use ($pdo, $scheduledCustomer): array {
        $receipt = $pdo->prepare("SELECT actor_user_id FROM managed_customer_lifecycle_receipts WHERE customer_id=? AND action='contained'");
        $receipt->execute([$scheduledCustomer($id)]);
        return $receipt->fetchAll(PDO::FETCH_COLUMN);
    };
    $cursor = static fn(): int => (int)$pdo->query('SELECT last_binding_id FROM managed_customer_lifecycle_progress WHERE id=1')->fetchColumn();
    $firstClaim = managed_customer_lifecycle_scheduled_claim($runtime, managed_customer_lifecycle_config($scheduledConfig));
    lifecycle_mysql_check((int)($firstClaim['binding_id'] ?? 0) === 71 && $cursor() === 71 && $containedFor(71) === [],
        'a scheduled claim commits the cursor before any action');
    $runtimeTwo = lifecycle_mysql_connection($database, $runtimeUser, $runtimePass);
    $runtimeTwo->exec('SET SESSION innodb_lock_wait_timeout = 1');
    $pdo->beginTransaction();
    $pdo->query('SELECT last_binding_id FROM managed_customer_lifecycle_progress WHERE id=1 FOR UPDATE')->fetchAll();
    $blocked = managed_customer_lifecycle_scheduled_run($runtimeTwo, $scheduledConfig);
    $pdo->rollBack();
    lifecycle_mysql_check(($blocked['stopped'] ?? null) === 'cursor_unavailable' && $blocked['claims'] === 0
        && $cursor() === 71 && $containedFor(72) === [],
        'a concurrent scheduled run waiting on the cursor lock takes no action');
    $runtimeTwo = null;
    $scheduled = managed_customer_lifecycle_scheduled_run($runtime, $scheduledConfig);
    lifecycle_mysql_check(!isset($scheduled['stopped']) && $scheduled['claims'] === 2 && $scheduled['reserved'] === 2
        && array_column($scheduled['results'], 'provenance') === ['explicit', 'admitted']
        && array_column($scheduled['results'], 'action') === ['contained', 'contained']
        && $containedFor(72) === [301] && $containedFor(71) === [101] && $containedFor(73) === []
        && $cursor() === 71,
        'the scheduled run resumes after the committed claim, wraps, and acts as each exact actor');
    lifecycle_mysql_check(managed_customer_lifecycle_scheduled_run($runtime, $scheduledConfig)['claims'] === 0,
        'contained scheduled customers are not claimed again');
    $scheduledBinding->execute([74, 1, $scheduledCustomer(74), 74, 'Scheduled Later',
        sprintf('b7000000-4444-4444-8444-%012d', 74), str_repeat('e', 64), 'created']);
    lifecycle_mysql_mark_inactive($pdo, 74, sprintf('c7000000-4444-4444-8444-%012d', 74), str_repeat('f', 64));
    $pdo->exec('UPDATE suite_managed_providers SET lease_until=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE tenant_id=1');
    $withdrawn = managed_customer_lifecycle_scheduled_run($runtime, $scheduledConfig);
    $pdo->exec('UPDATE suite_managed_providers SET lease_until=UTC_TIMESTAMP()+INTERVAL 4 MINUTE WHERE tenant_id=1');
    $renewed = managed_customer_lifecycle_scheduled_run($runtime, $scheduledConfig);
    lifecycle_mysql_check(array_column($withdrawn['results'], 'action') === ['refused'] && $cursor() === 74
        && array_column($renewed['results'], 'action') === ['contained'] && $containedFor(74) === [101],
        'a withdrawn provider refuses its admitted customer and renewal resumes it');
    lifecycle_mysql_refuses_with('denied', static fn() => $runtime->exec("UPDATE suite_customer_sync_bindings SET last_admission='restored' WHERE id=71"),
        'the narrow runtime cannot write the admission carrier');

    // Forward migration 034 and the schema-3 identity-only restore (A88/A95/A97). Identity One is an
    // admitted provider-one lineage whose portal and report were contained at v2; v3 restored and a
    // v4 rename then arrive before the next SH tick, so the current proof is observed_active with the
    // actual restored anchor v3.
    $migration034 = __DIR__ . '/../db/migrations/034_managed_customer_identity_lifecycle_restore.sql';
    $v3Validator = 'safeharbor-managed-customer-lifecycle-restore-receipt-v3';
    $identitySwapBody = "BEGIN\n  SIGNAL SQLSTATE '45000'\n"
        . "    SET MESSAGE_TEXT='Managed-customer identity-only restoration migration is incomplete';\nEND";
    $identitySwapSql = "CREATE TRIGGER trg_mc_restore_identity_swap_insert\n"
        . "BEFORE INSERT ON managed_customer_lifecycle_restore_receipts\nFOR EACH ROW\n" . $identitySwapBody;
    $identityOne = '66666666-6666-4666-8666-666666666666';
    $identityEvent = static fn(int $version): string => sprintf('66000000-0000-4000-8000-%012d', $version);
    $identityKey = managed_customer_activation_schedule_key($identityOne);
    $pdo->exec('UPDATE suite_managed_providers SET lease_until=UTC_TIMESTAMP()+INTERVAL 4 MINUTE WHERE tenant_id=1');
    $pdo->exec("INSERT INTO clients (id,tenant_id,name) VALUES (61,1,'Identity One')");
    lifecycle_mysql_seed_active(
        $pdo, $identityOne, 61, 561, $identityEvent(1), 'ewid-t96', 'identity-one', 1, 'provider-one', 101, 'created',
    );
    lifecycle_mysql_mark_inactive($pdo, 561, $identityEvent(2), hash('sha256', $identityEvent(2)));
    $identityContained = managed_customer_lifecycle_apply(
        $runtime, lifecycle_mysql_candidate($pdo, $identityOne), 101, lifecycle_mysql_config($identityOne),
    );
    lifecycle_mysql_mark_active($pdo, 561, $identityEvent(3), hash('sha256', $identityEvent(3)));
    lifecycle_mysql_advance_active($pdo, 561, $identityEvent(4), hash('sha256', $identityEvent(4)));
    $identityProof = lifecycle_mysql_identity_proof(
        $identityOne, 4, $identityEvent(4), 'observed_active', 3, $identityEvent(3), 'ewid-t96', 'identity-one',
    );
    $identityBefore = lifecycle_mysql_surface_rows($pdo, 1, 61, $identityKey);
    $identityCandidate = lifecycle_mysql_candidate($pdo, $identityOne);
    $identityCount = static function () use ($pdo): int {
        return (int)$pdo->query('SELECT COUNT(*) FROM managed_customer_lifecycle_restore_receipts WHERE tenant_id=1 AND client_id=61')
            ->fetchColumn();
    };
    $identityConfig = array_replace($scheduledConfig, ['restoration_enabled' => true]);
    $legacyCalls = 0;
    $identityCalls = [];
    $legacyFetch = static function () use (&$legacyCalls): array {
        $legacyCalls++;
        throw new ManagedCustomerActivationGateException('the schema-2 contact proof is not this route');
    };
    $identityFetch = static function (string $customerId, array $idConfig) use (&$identityCalls, $identityProof): array {
        $identityCalls[] = $customerId;
        return $identityProof;
    };
    lifecycle_mysql_check(
        ($identityContained['action'] ?? null) === 'contained'
            && (int)$pdo->query("SELECT COUNT(*) FROM suite_customer_sync_events
                WHERE binding_id=561 AND admission='created' AND source_version=1")->fetchColumn() === 1
            && !managed_customer_operational($pdo, 1, 61)
            && !managed_customer_lifecycle_identity_restore_available($runtime),
        'the admitted v4 lineage starts contained and latched before 034',
    );
    $before034 = managed_customer_lifecycle_scheduled_run(
        $runtime, $identityConfig, [], ['timeout_seconds' => 10], $legacyFetch, null, $identityFetch,
    );
    lifecycle_mysql_check(
        array_column($before034['results'], 'action') === ['refused'] && $identityCalls === [] && $legacyCalls === 0
            && $identityCount() === 0 && lifecycle_mysql_surface_rows($pdo, 1, 61, $identityKey) === $identityBefore,
        'before 034 an admitted restoration refuses before any ID request or write and never falls back to schema 2',
    );

    lifecycle_mysql_refuses(
        PDOException::class,
        static fn() => lifecycle_mysql_execute_file($runtime, $migration034),
        'DML-only lifecycle identity cannot apply privileged migration 034',
    );
    lifecycle_mysql_check($restoreGuardsAre($v2Validator), 'failed DML-only 034 leaves the 032 guards unchanged');
    $legacyColumns = 'id,tenant_id,client_id,source_binding_id,customer_id,source_event_receipt_id,source_event_id,
        source_version,source_request_sha256,id_customer_receipt_id,id_customer_status,id_lifecycle_version,
        id_lifecycle_transition_id,id_lifecycle_action,id_lifecycle_evidence_sha256,id_identity_tenant_status,
        id_oauth_session_version,id_lifecycle_owned,id_tenant_key,identity_tenant_slug,contact_version,
        recipient_sha256,id_response_generated_at,id_request_nonce_sha256,id_response_sha256,
        portal_owner_receipt_id,portal_binding_id,portal_before_event_id,portal_active_event_id,portal_restored,
        portal_state_sha256,schedule_owner_receipt_id,schedule_key,schedule_before_version_id,
        schedule_prepared_version_id,contact_snapshot_id,schedule_active_version_id,schedule_restored,
        schedule_state_sha256,actor_user_id,evidence_sha256,created_at';
    $legacyRows = $pdo->query("SELECT {$legacyColumns} FROM managed_customer_lifecycle_restore_receipts ORDER BY id")
        ->fetchAll(PDO::FETCH_ASSOC);
    lifecycle_mysql_execute_file($pdo, $migration034);
    lifecycle_mysql_execute_file($pdo, $migration034);
    $newColumnsNull = (int)$pdo->query('SELECT COUNT(*) FROM managed_customer_lifecycle_restore_receipts
        WHERE id_proof_scope IS NOT NULL OR id_initial_receipt_id IS NOT NULL OR id_provider_tenant_slug IS NOT NULL
           OR id_issuer_suite_tenant_id IS NOT NULL OR id_projection_binding_sha256 IS NOT NULL
           OR id_projection_tenant_key IS NOT NULL OR id_restore_anchor_source_version IS NOT NULL
           OR id_restore_anchor_receipt_id IS NOT NULL OR id_restore_anchor_event_id IS NOT NULL
           OR id_restore_anchor_transition_id IS NOT NULL OR id_restore_anchor_evidence_sha256 IS NOT NULL')->fetchColumn();
    $legacyValid = true;
    foreach ($pdo->query('SELECT * FROM managed_customer_lifecycle_restore_receipts ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $legacyRow) {
        $legacyValid = $legacyValid && managed_customer_lifecycle_restore_receipt_valid($legacyRow);
    }
    lifecycle_mysql_check(
        $restoreGuardsAre($v3Validator)
            && (int)$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE()
                AND table_name='managed_customer_lifecycle_restore_receipts'")->fetchColumn() === 53
            && $legacyRows !== []
            && $pdo->query("SELECT {$legacyColumns} FROM managed_customer_lifecycle_restore_receipts ORDER BY id")
                ->fetchAll(PDO::FETCH_ASSOC) === $legacyRows
            && $newColumnsNull === 0 && $legacyValid
            && managed_customer_lifecycle_identity_restore_available($runtime),
        'migration 034 applies and replays to the v3 validator, keeps every legacy row byte-exact and digest-valid, and adds only NULL new facts',
    );

    // The 034 swap guard is trusted only by its exact definition, as in 032.
    $identityGuards = $restoreTriggers();
    foreach ([
        'a marker-only body' => "CREATE TRIGGER trg_mc_restore_identity_swap_insert BEFORE INSERT
            ON managed_customer_lifecycle_restore_receipts FOR EACH ROW
            SET @mc_swap_marker='Managed-customer identity-only restoration migration is incomplete'",
        'the exact body after insert' => "CREATE TRIGGER trg_mc_restore_identity_swap_insert\n"
            . "AFTER INSERT ON managed_customer_lifecycle_restore_receipts\nFOR EACH ROW\n" . $identitySwapBody,
        'the exact body on another table' => "CREATE TRIGGER trg_mc_restore_identity_swap_insert\n"
            . "BEFORE INSERT ON managed_customer_lifecycle_receipts\nFOR EACH ROW\n" . $identitySwapBody,
    ] as $label => $wrongSwapSql) {
        $pdo->exec($wrongSwapSql);
        foreach ([false, true] as $validatorDropped) {
            if ($validatorDropped) {
                $validatorSql = (string)$pdo->query('SHOW CREATE TRIGGER trg_mc_restore_before_insert')
                    ->fetch(PDO::FETCH_ASSOC)['SQL Original Statement'];
                $pdo->exec('DROP TRIGGER trg_mc_restore_before_insert');
            }
            $wrongState = $restoreTriggers();
            lifecycle_mysql_refuses(
                PDOException::class,
                static fn() => lifecycle_mysql_execute_file($pdo, $migration034),
                'migration 034 refuses a swap guard with ' . $label . ($validatorDropped ? ' after the validator is gone' : ''),
            );
            lifecycle_mysql_check($restoreTriggers() === $wrongState,
                'a refused 034 run with ' . $label . ' changes no restore-receipt trigger');
            if ($validatorDropped) $pdo->exec($validatorSql);
        }
        $pdo->exec('DROP TRIGGER trg_mc_restore_identity_swap_insert');
        lifecycle_mysql_check($restoreTriggers() === $identityGuards, 'wrong 034 swap guard fixture ' . $label . ' removed');
    }
    $pdo->exec($identitySwapSql);
    lifecycle_mysql_execute_file($pdo, $migration034);
    lifecycle_mysql_check($restoreGuardsAre($v3Validator), 'exact 034 replay converges with the v3 validator and the swap guard');
    $pdo->exec($identitySwapSql);
    $pdo->exec('DROP TRIGGER trg_mc_restore_before_insert');
    lifecycle_mysql_refuses_with(
        'identity-only restoration migration is incomplete',
        static fn() => managed_customer_lifecycle_identity_restore(
            $runtime, $identityCandidate, $identityProof, 101, lifecycle_mysql_restore_config($identityOne),
        ),
        'an interrupted 034 swap refuses an otherwise exact identity-only restoration',
    );
    lifecycle_mysql_check(
        array_keys($restoreTriggers()) === [
            'trg_mc_restore_identity_swap_insert', 'trg_mc_restore_no_delete', 'trg_mc_restore_no_update',
        ]
            && lifecycle_mysql_surface_rows($pdo, 1, 61, $identityKey) === $identityBefore && $identityCount() === 0,
        'the interrupted 034 swap keeps update/delete immutability and writes nothing',
    );
    lifecycle_mysql_execute_file($pdo, $migration034);
    lifecycle_mysql_check($restoreGuardsAre($v3Validator), 'exact 034 replay converges after an interrupted swap');
    $pdo->exec('DROP TRIGGER trg_mc_restore_before_insert');
    lifecycle_mysql_refuses(
        PDOException::class,
        static fn() => lifecycle_mysql_execute_file($pdo, $migration034),
        'migration 034 refuses a missing validator without its swap guard',
    );
    lifecycle_mysql_check(
        array_keys($restoreTriggers()) === ['trg_mc_restore_no_delete', 'trg_mc_restore_no_update'],
        'the refused 034 preflight changes nothing',
    );
    $pdo->exec($identitySwapSql);
    lifecycle_mysql_execute_file($pdo, $migration034);
    lifecycle_mysql_check($restoreGuardsAre($v3Validator), '034 converges from its own interrupted state');

    // Direct guard checks against the exact v4 identity facts (portal and report held, restored 0): the
    // exact facts and digest pass; every NULL, carrier, contact, anchor, provider, issuer, map and digest
    // drift refuses. The BEFORE INSERT validator fires before the CHECK constraints, so a drift it
    // inspects refuses with its message; a NULL drift keeps the exact digest because PHP refuses to
    // compute one; a drift only the CHECK sees keeps a recomputed digest so the CHECK is what refuses.
    $identityFacts = lifecycle_mysql_identity_facts($pdo, 1, 61, 561, $identityProof, 101);
    $identityDigest = managed_customer_lifecycle_identity_restore_receipt_sha256($identityFacts);
    $pdo->beginTransaction();
    try {
        lifecycle_mysql_insert_identity_receipt($pdo, $identityFacts);
        lifecycle_mysql_check(true, 'the 034 validator accepts the exact identity-only facts and PHP digest');
    } catch (Throwable $error) {
        lifecycle_mysql_check(false, 'the 034 validator accepts the exact identity-only facts and PHP digest (' . $error->getMessage() . ')');
    } finally {
        $pdo->rollBack();
    }
    $legacyShaped = array_diff_key($identityFacts, array_flip([
        'id_proof_scope', 'id_initial_receipt_id', 'id_provider_tenant_slug', 'id_issuer_suite_tenant_id',
        'id_projection_binding_sha256', 'id_projection_tenant_key', 'id_restore_anchor_source_version',
        'id_restore_anchor_receipt_id', 'id_restore_anchor_event_id', 'id_restore_anchor_transition_id',
        'id_restore_anchor_evidence_sha256',
    ])) + ['id_tenant_key' => 'ewid-t96', 'contact_version' => 1, 'recipient_sha256' => str_repeat('a', 64),
        'schedule_owner_receipt_id' => null, 'schedule_prepared_version_id' => null, 'contact_snapshot_id' => null,
        'schedule_active_version_id' => null];
    foreach ([
        ['ck_mc_restore_proof_scope', ['id_tenant_key' => 'ewid-t96'], false, 'an identity row with the report-map carrier'],
        ['ck_mc_restore_proof_scope', ['contact_version' => 1], false, 'an identity row with a contact version'],
        ['ck_mc_restore_proof_scope', ['recipient_sha256' => str_repeat('a', 64)], false, 'an identity row with a recipient hash'],
        ['anchor does not match local history', ['id_restore_anchor_event_id' => null], true, 'an identity row with a NULL anchor fact'],
        ['provider or issuer is not exact', ['id_issuer_suite_tenant_id' => null], true, 'an identity row with a NULL issuer'],
        ['requires exact active restored-only ID evidence', ['id_proof_scope' => null], true,
            'observed_active identity metadata under the legacy NULL scope'],
        ['anchor does not match local history', ['id_restore_anchor_source_version' => 5], false, 'an anchor after the current version'],
        ['anchor does not match local history', ['id_restore_anchor_event_id' => $identityEvent(9)], false, 'a forged anchor event'],
        ['anchor does not match local history', ['id_restore_anchor_source_version' => 4,
            'id_restore_anchor_event_id' => $identityEvent(4)], false, 'an anchor that is not directly after the latest inactive version'],
        ['anchor does not match local history', ['id_lifecycle_action' => 'restored'], false, 'a current restored action with an older anchor'],
        ['provider or issuer is not exact', ['id_provider_tenant_slug' => 'provider-three'], false, 'another provider slug'],
        ['provider or issuer is not exact', ['id_issuer_suite_tenant_id' => 10], false, 'another issuer'],
        ['ID mapping is not exact', ['id_projection_tenant_key' => 'ewid-t97'], false, 'a signed key that differs from the existing map'],
    ] as [$needle, $forged, $keepDigest, $label]) {
        lifecycle_mysql_refuses_with(
            $needle,
            static fn() => lifecycle_mysql_insert_identity_receipt($pdo, array_replace($identityFacts, $forged),
                $keepDigest ? $identityDigest : null),
            'the 034 validator refuses ' . $label,
        );
    }
    lifecycle_mysql_refuses_with(
        'digest does not match exact facts',
        static fn() => lifecycle_mysql_insert_identity_receipt($pdo, $identityFacts,
            managed_customer_lifecycle_restore_receipt_sha256($legacyShaped)),
        'the 034 validator refuses the legacy digest domain for an identity row',
    );
    lifecycle_mysql_refuses_with(
        'requires exact active restored-only ID evidence',
        static fn() => lifecycle_mysql_insert_restore_receipt($pdo, $legacyShaped),
        'the NULL-safe 034 scope test refuses observed_active under the legacy NULL scope',
    );
    $legacyRestored = array_replace($legacyShaped, ['id_lifecycle_action' => 'restored']);
    lifecycle_mysql_refuses_with(
        'ck_mc_restore_proof_scope',
        static fn() => lifecycle_mysql_insert_restore_receipt($pdo, $legacyRestored + ['id_initial_receipt_id' => str_repeat('e', 64)]),
        'the 034 CHECK refuses identity metadata on an otherwise exact legacy row',
    );
    lifecycle_mysql_refuses_with(
        'digest does not match exact facts',
        static fn() => lifecycle_mysql_insert_restore_receipt($pdo, array_replace($legacyRestored, ['contact_version' => null])),
        'the NULL-safe 034 digest comparison refuses a legacy row whose SQL digest is NULL',
    );
    lifecycle_mysql_refuses_with(
        'ID mapping is not exact',
        static fn() => lifecycle_mysql_insert_restore_receipt($pdo, array_replace($legacyRestored, ['id_tenant_key' => null])),
        'the 034 validator refuses a legacy row with a NULL report-map carrier',
    );
    lifecycle_mysql_check(
        $identityCount() === 0 && lifecycle_mysql_surface_rows($pdo, 1, 61, $identityKey) === $identityBefore,
        'refused identity-only guard checks leave no row',
    );

    // A141: current authority at action. The provider is withdrawn while the proof is fetched; the
    // action rechecks the current provider under its own locks and refuses, writing nothing.
    $renewProvider = static fn() => $pdo->exec(
        'UPDATE suite_managed_providers SET lease_until=UTC_TIMESTAMP()+INTERVAL 4 MINUTE WHERE tenant_id=1'
    );
    $withdrawFetch = static function (string $customerId, array $idConfig) use ($pdo, $identityProof): array {
        $pdo->exec('UPDATE suite_managed_providers SET lease_until=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE tenant_id=1');
        return $identityProof;
    };
    $withdrawnDuringFetch = managed_customer_lifecycle_scheduled_run(
        $runtime, $identityConfig, [], ['timeout_seconds' => 10], $legacyFetch, null, $withdrawFetch,
    );
    $renewProvider();
    lifecycle_mysql_check(
        array_column($withdrawnDuringFetch['results'], 'action') === ['refused']
            && ($withdrawnDuringFetch['results'][0]['error_class'] ?? null) === ManagedCustomerLifecycleGateException::class
            && $identityCount() === 0 && lifecycle_mysql_surface_rows($pdo, 1, 61, $identityKey) === $identityBefore
            && !managed_customer_operational($pdo, 1, 61),
        'a provider withdrawn during the proof fetch is refused under the action locks with no write',
    );
    // The same withdrawal racing the action's shared lock: a concurrent writer holds the provider row
    // when the action reaches its recheck, so the action waits and then sees the committed withdrawal.
    $withdrawPipes = [];
    $withdrawChild = proc_open([PHP_BINARY, __FILE__, 'provider-withdraw-child', $database],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $withdrawPipes);
    if (!is_resource($withdrawChild)) throw new RuntimeException('Cannot start the provider withdrawal race.');
    $lockedFetch = static function (string $customerId, array $idConfig) use ($withdrawPipes, $identityProof): array {
        if (trim((string)fgets($withdrawPipes[1])) !== 'locked') {
            throw new RuntimeException('The provider withdrawal race did not take its lock.');
        }
        return $identityProof;
    };
    $raceStarted = microtime(true);
    $withdrawnRace = managed_customer_lifecycle_scheduled_run(
        $runtime, $identityConfig, [], ['timeout_seconds' => 10], $legacyFetch, null, $lockedFetch,
    );
    $raceWaited = microtime(true) - $raceStarted;
    stream_get_contents($withdrawPipes[1]);
    $withdrawError = stream_get_contents($withdrawPipes[2]);
    fclose($withdrawPipes[1]); fclose($withdrawPipes[2]);
    $withdrawExit = proc_close($withdrawChild);
    $renewProvider();
    lifecycle_mysql_check(
        $withdrawExit === 0 && $withdrawError === ''
            && array_column($withdrawnRace['results'], 'action') === ['refused']
            && ($withdrawnRace['results'][0]['error_class'] ?? null) === ManagedCustomerLifecycleGateException::class
            && $raceWaited >= 0.5
            && $identityCount() === 0 && lifecycle_mysql_surface_rows($pdo, 1, 61, $identityKey) === $identityBefore,
        'a withdrawal committed while the action waits on the provider lock is refused with no write'
            . ($withdrawError === '' ? '' : ' (child: ' . trim($withdrawError) . ')'),
    );
    // A143: the scheduling deadline passes during the proof fetch; the action defers with zero writes.
    $lateClock = 0.0;
    $lateFetch = static function (string $customerId, array $idConfig) use (&$lateClock, $identityProof): array {
        $lateClock = 31.0;
        return $identityProof;
    };
    $lateRun = managed_customer_lifecycle_scheduled_run(
        $runtime, $identityConfig, [], ['timeout_seconds' => 10], $legacyFetch,
        static function () use (&$lateClock): float { return $lateClock; }, $lateFetch,
    );
    lifecycle_mysql_check(
        array_column($lateRun['results'], 'action') === ['deferred'] && $lateRun['reserved'] === 1
            && $identityCount() === 0 && lifecycle_mysql_surface_rows($pdo, 1, 61, $identityKey) === $identityBefore
            && !managed_customer_operational($pdo, 1, 61),
        'a deadline that passes during the proof fetch defers with zero portal, report or restore-receipt writes',
    );

    // The scheduled admitted route after 034: identity-only, never schema 2. The owned portal reopens;
    // the owned-disabled report is recorded and never released.
    $identityRun = managed_customer_lifecycle_scheduled_run(
        $runtime, $identityConfig, [], ['timeout_seconds' => 10], $legacyFetch, null, $identityFetch,
    );
    $identityRows = $pdo->query('SELECT * FROM managed_customer_lifecycle_restore_receipts WHERE tenant_id=1 AND client_id=61')
        ->fetchAll(PDO::FETCH_ASSOC);
    $identityAfter = lifecycle_mysql_surface_rows($pdo, 1, 61, $identityKey);
    lifecycle_mysql_check(
        array_column($identityRun['results'], 'action') === ['restored']
            && array_column($identityRun['results'], 'provenance') === ['admitted']
            && ($identityRun['results'][0]['proof_scope'] ?? null) === 'identity_lifecycle_only'
            && ($identityRun['results'][0]['portal_restored'] ?? null) === 1
            && ($identityRun['results'][0]['report_schedule_restored'] ?? null) === 0
            && $identityCalls === [$identityOne] && $legacyCalls === 0
            && count($identityRows) === 1
            && managed_customer_lifecycle_identity_restore_receipt_valid($identityRows[0])
            && $identityRows[0]['id_tenant_key'] === null && $identityRows[0]['contact_version'] === null
            && (string)$identityRows[0]['id_lifecycle_action'] === 'observed_active'
            && (int)$identityRows[0]['id_restore_anchor_source_version'] === 3
            && (int)$identityRows[0]['schedule_restored'] === 0
            && $identityAfter['schedule'] === $identityBefore['schedule']
            && $identityAfter['contacts'] === $identityBefore['contacts']
            && $identityAfter['containment'] === $identityBefore['containment']
            && (string)$pdo->query('SELECT status FROM customer_portal_bindings WHERE tenant_id=1 AND client_id=61')->fetchColumn() === 'active'
            && managed_customer_operational($pdo, 1, 61),
        'the scheduled admitted identity-only restore clears the latch, reopens the owned portal and writes no report, contact or schedule row',
    );
    $identityReplayProof = $identityProof;
    $identityReplayProof['request_nonce_sha256'] = hash('sha256', 'identity-replay-nonce');
    $identityReplayProof['response_sha256'] = hash('sha256', 'identity-replay-response');
    $identityReplay = managed_customer_lifecycle_identity_restore(
        $runtime, $identityCandidate, $identityReplayProof, 101, lifecycle_mysql_restore_config($identityOne),
    );
    lifecycle_mysql_check(
        ($identityReplay['action'] ?? null) === 'restore_replayed'
            && ($identityReplay['evidence_sha256'] ?? null) === (string)$identityRows[0]['evidence_sha256']
            && $identityCount() === 1
            && lifecycle_mysql_surface_rows($pdo, 1, 61, $identityKey) === $identityAfter,
        'a lost-ack identity-only replay writes nothing and returns the committed receipt',
    );

    // The legacy contact restore still runs unchanged under the v3 validator.
    lifecycle_mysql_mark_inactive($pdo, 541, $oneEvent(6), hash('sha256', $oneEvent(6)));
    managed_customer_lifecycle_apply(
        $runtime, lifecycle_mysql_candidate($pdo, $preservedOne), 301, lifecycle_mysql_three_config($preservedOne),
    );
    lifecycle_mysql_mark_active($pdo, 541, $oneEvent(7), hash('sha256', $oneEvent(7)));
    $oneLegacy = managed_customer_lifecycle_restore(
        $runtime, lifecycle_mysql_candidate($pdo, $preservedOne),
        lifecycle_mysql_restore_evidence($preservedOne, 7, $oneEvent(7), 'ewid-t93', 'preserved-one'), 301,
        lifecycle_mysql_three_config($preservedOne, true), $oneReport,
    );
    $oneLegacyRow = $pdo->query('SELECT * FROM managed_customer_lifecycle_restore_receipts
        WHERE tenant_id=3 AND client_id=41 AND source_version=7')->fetch(PDO::FETCH_ASSOC);
    lifecycle_mysql_check(
        ($oneLegacy['action'] ?? null) === 'restored' && is_array($oneLegacyRow)
            && $oneLegacyRow['id_proof_scope'] === null && (string)$oneLegacyRow['id_tenant_key'] === 'ewid-t93'
            && managed_customer_lifecycle_restore_receipt_valid($oneLegacyRow)
            && managed_customer_operational($pdo, 3, 41),
        'the legacy schema-2 contact restore still writes its unchanged receipt and digest under the v3 validator',
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
        'fresh schema and replayed migrations 024 + 032 + 034 have exact table check index FK and trigger parity'
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
