<?php
/** Hermetic contract for managed-customer containment and restoration latch. */
declare(strict_types=1);

require_once __DIR__ . '/../lib/managed_customer_lifecycle.php';

$lifecycleChecks = 0;
$lifecycleFailures = 0;

function lifecycle_check(bool $condition, string $message): void
{
    global $lifecycleChecks, $lifecycleFailures;
    $lifecycleChecks++;
    if (!$condition) {
        $lifecycleFailures++;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

/** @param class-string<Throwable> $class */
function lifecycle_refuses(string $class, callable $operation, string $message): void
{
    try {
        $operation();
        lifecycle_check(false, $message);
    } catch (Throwable $error) {
        lifecycle_check($error instanceof $class, $message);
    }
}

/** @return array<string,mixed> */
function lifecycle_config(array $changes = []): array
{
    return array_replace([
        'enabled' => true,
        'restoration_enabled' => false,
        'customer_ids' => ['11111111-1111-4111-8111-111111111111'],
        'tenant_actors' => ['provider-one' => 101],
        'batch_size' => 5,
    ], $changes);
}

/** @return array<string,mixed> */
function lifecycle_base_evidence(): array
{
    return [
        'schema_version' => 2,
        'customer_id' => '11111111-1111-4111-8111-111111111111',
        'source_version' => 3,
        'customer_receipt_id' => str_repeat('1', 64),
        'customer_event_id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
        'customer_status' => 'active',
        'lifecycle_version' => 1,
        'lifecycle_transition_id' => 9,
        'lifecycle_action' => 'restored',
        'lifecycle_evidence_sha256' => str_repeat('4', 64),
        'identity_tenant_status' => 'active',
        'identity_oauth_session_version' => 5,
        'lifecycle_owned' => false,
        'tenant_key' => 'ewid-t91',
        'tenant_slug' => 'managed-one',
        'contact_version' => 5,
        'recipient_email' => 'reports@managed-one.example',
        'generated_at_db' => '2026-08-30 17:00:00',
        'request_nonce_sha256' => str_repeat('2', 64),
        'response_sha256' => str_repeat('3', 64),
    ];
}

/** @return array<string,mixed> */
function lifecycle_report_config(): array
{
    return [
        'generation_enabled' => false,
        'delivery_enabled' => false,
        'canary_only' => true,
        'graph_sender' => '',
        'schedule_keys' => [
            managed_customer_activation_schedule_key('11111111-1111-4111-8111-111111111111'),
        ],
        'tenant_slugs' => ['provider-one'],
        'client_keys' => ['safeharbor-client:11'],
        'recipient_emails' => ['reports@managed-one.example'],
        'lease_seconds' => 120,
    ];
}

function lifecycle_sqlite(string $currentStatus = 'inactive', bool $portalActive = true, bool $scheduleActive = true): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys=ON');
    foreach ([
        'CREATE TABLE tenants (id INTEGER PRIMARY KEY, name TEXT NOT NULL, slug TEXT NOT NULL UNIQUE)',
        'CREATE TABLE clients (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name TEXT NOT NULL,
            UNIQUE(tenant_id,id))',
        'CREATE TABLE users (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, role TEXT NOT NULL,
            is_active INTEGER NOT NULL, UNIQUE(tenant_id,id))',
        'CREATE TABLE suite_customer_sync_bindings (
            id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, customer_id TEXT NOT NULL UNIQUE,
            client_id INTEGER NOT NULL, source_version INTEGER NOT NULL, display_name TEXT NOT NULL,
            status TEXT NOT NULL, last_event_id TEXT NOT NULL, last_request_sha256 TEXT NOT NULL,
            UNIQUE(tenant_id,client_id), UNIQUE(tenant_id,id))',
        'CREATE TABLE suite_customer_sync_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
            event_id TEXT NOT NULL UNIQUE, binding_id INTEGER NOT NULL, customer_id TEXT NOT NULL,
            client_id INTEGER NOT NULL, source_version INTEGER NOT NULL, display_name TEXT NOT NULL,
            status TEXT NOT NULL, occurred_at TEXT NOT NULL, request_sha256 TEXT NOT NULL,
            received_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(tenant_id,id))',
        'CREATE TABLE customer_portal_bindings (
            id INTEGER PRIMARY KEY AUTOINCREMENT, identity_tenant_slug TEXT NOT NULL UNIQUE,
            tenant_id INTEGER NOT NULL, client_id INTEGER NOT NULL, status TEXT NOT NULL,
            prepared_by_user_id INTEGER NOT NULL, prepared_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_changed_by_user_id INTEGER NOT NULL,
            status_changed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            status_reason TEXT NOT NULL, UNIQUE(tenant_id,client_id), UNIQUE(tenant_id,client_id,id))',
        'CREATE TABLE customer_portal_binding_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
            client_id INTEGER NOT NULL, binding_id INTEGER NOT NULL, actor_user_id INTEGER NOT NULL,
            event_kind TEXT NOT NULL, from_status TEXT NULL, to_status TEXT NOT NULL,
            reason TEXT NOT NULL, snapshot_json TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)',
        "CREATE TRIGGER lifecycle_portal_update AFTER UPDATE OF status ON customer_portal_bindings
         BEGIN
           INSERT INTO customer_portal_binding_events
             (tenant_id,client_id,binding_id,actor_user_id,event_kind,from_status,to_status,
              reason,snapshot_json)
           VALUES
             (NEW.tenant_id,NEW.client_id,NEW.id,NEW.last_changed_by_user_id,
              CASE WHEN NEW.status='active' THEN 'enabled' ELSE 'disabled' END,
              OLD.status,NEW.status,NEW.status_reason,
              json_object('binding_id',NEW.id,'status',NEW.status,'reason',NEW.status_reason));
         END",
        'CREATE TABLE business_report_schedule_versions (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
            schedule_key TEXT NOT NULL, version_no INTEGER NOT NULL,
            definition_version_id INTEGER NOT NULL, client_id INTEGER NOT NULL,
            recipient_email TEXT NOT NULL, schedule_timezone TEXT NOT NULL,
            delivery_weekday INTEGER NOT NULL, delivery_local_time TEXT NOT NULL,
            canary INTEGER NOT NULL, status TEXT NOT NULL, created_by_user_id INTEGER NOT NULL,
            reason TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(tenant_id,schedule_key,version_no), UNIQUE(tenant_id,id))',
        'CREATE TABLE business_report_id_client_bindings (
            tenant_id INTEGER NOT NULL, client_id INTEGER NOT NULL,
            id_tenant_key TEXT NOT NULL, id_tenant_slug TEXT NOT NULL,
            created_by_user_id INTEGER NOT NULL, reason TEXT NOT NULL,
            PRIMARY KEY(tenant_id,client_id), UNIQUE(id_tenant_key))',
        'CREATE TABLE business_report_contact_scope_bindings (
            tenant_id INTEGER NOT NULL, schedule_key TEXT NOT NULL,
            contact_scope TEXT NOT NULL, created_by_user_id INTEGER NOT NULL,
            reason TEXT NOT NULL, PRIMARY KEY(tenant_id,schedule_key))',
        'CREATE TABLE business_report_id_contact_snapshots (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
            schedule_version_id INTEGER NOT NULL)',
        'CREATE TABLE business_report_id_client_contact_snapshots (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
            client_id INTEGER NOT NULL, schedule_version_id INTEGER NOT NULL,
            id_tenant_key TEXT NOT NULL, contact_version INTEGER NOT NULL,
            recipient_email TEXT NOT NULL, response_generated_at TEXT NOT NULL,
            request_nonce_sha256 TEXT NOT NULL, response_sha256 TEXT NOT NULL,
            created_by_user_id INTEGER NOT NULL, reason TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(tenant_id,schedule_version_id))',
        'CREATE TABLE managed_customer_lifecycle_receipts (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
            client_id INTEGER NOT NULL, source_binding_id INTEGER NOT NULL,
            customer_id TEXT NOT NULL, source_event_receipt_id INTEGER NOT NULL,
            source_event_id TEXT NOT NULL, source_version INTEGER NOT NULL,
            source_status TEXT NOT NULL, action TEXT NOT NULL,
            portal_binding_id INTEGER NULL, portal_was_active INTEGER NOT NULL,
            portal_before_event_id INTEGER NULL, portal_state_event_id INTEGER NULL,
            portal_disabled_event_id INTEGER NULL, portal_state_sha256 TEXT NULL,
            schedule_key TEXT NOT NULL, schedule_was_active INTEGER NOT NULL,
            schedule_active_version_id INTEGER NULL, schedule_state_version_id INTEGER NULL,
            schedule_disabled_version_id INTEGER NULL, schedule_state_sha256 TEXT NULL,
            actor_user_id INTEGER NOT NULL, source_request_sha256 TEXT NOT NULL,
            evidence_sha256 TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(customer_id,source_version,action), UNIQUE(source_event_receipt_id,action))',
        'CREATE TABLE managed_customer_lifecycle_restore_receipts (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
            client_id INTEGER NOT NULL, source_binding_id INTEGER NOT NULL,
            customer_id TEXT NOT NULL, source_event_receipt_id INTEGER NOT NULL,
            source_event_id TEXT NOT NULL, source_version INTEGER NOT NULL,
            source_request_sha256 TEXT NOT NULL, id_customer_receipt_id TEXT NOT NULL,
            id_customer_status TEXT NOT NULL, id_lifecycle_version INTEGER NOT NULL,
            id_lifecycle_transition_id INTEGER NOT NULL, id_lifecycle_action TEXT NOT NULL,
            id_lifecycle_evidence_sha256 TEXT NOT NULL,
            id_identity_tenant_status TEXT NOT NULL, id_oauth_session_version INTEGER NOT NULL,
            id_lifecycle_owned INTEGER NOT NULL, id_tenant_key TEXT NOT NULL,
            identity_tenant_slug TEXT NOT NULL, contact_version INTEGER NOT NULL,
            recipient_sha256 TEXT NOT NULL, id_response_generated_at TEXT NOT NULL,
            id_request_nonce_sha256 TEXT NOT NULL, id_response_sha256 TEXT NOT NULL,
            portal_owner_receipt_id INTEGER NULL, portal_binding_id INTEGER NULL,
            portal_before_event_id INTEGER NULL, portal_active_event_id INTEGER NULL,
            portal_restored INTEGER NOT NULL, portal_state_sha256 TEXT NULL,
            schedule_owner_receipt_id INTEGER NULL, schedule_key TEXT NOT NULL,
            schedule_before_version_id INTEGER NULL, schedule_prepared_version_id INTEGER NULL,
            contact_snapshot_id INTEGER NULL, schedule_active_version_id INTEGER NULL,
            schedule_restored INTEGER NOT NULL, schedule_state_sha256 TEXT NULL,
            actor_user_id INTEGER NOT NULL, evidence_sha256 TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(customer_id,source_version), UNIQUE(source_event_receipt_id))',
        'CREATE TABLE tickets (id INTEGER PRIMARY KEY, marker TEXT NOT NULL)',
        'CREATE TABLE time_entries (id INTEGER PRIMARY KEY, marker TEXT NOT NULL)',
        'CREATE TABLE coastmark_sentinel (id INTEGER PRIMARY KEY, marker TEXT NOT NULL)',
        'CREATE TABLE endpoint_control_sentinel (id INTEGER PRIMARY KEY, marker TEXT NOT NULL)',
        'CREATE TABLE ai_write_sentinel (id INTEGER PRIMARY KEY, marker TEXT NOT NULL)',
    ] as $sql) {
        $pdo->exec($sql);
    }
    $pdo->exec("INSERT INTO tenants VALUES (1,'Provider One','provider-one')");
    $pdo->exec("INSERT INTO clients VALUES (11,1,'Managed One'),(12,1,'Legacy One')");
    $pdo->exec("INSERT INTO users VALUES (101,1,'owner',1),(102,1,'tech',1)");

    $events = [
        [1, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 1, 'active', str_repeat('a', 64)],
        [2, 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 2, 'inactive', str_repeat('b', 64)],
    ];
    if ($currentStatus === 'active') {
        $events[] = [3, 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 3, 'active', str_repeat('c', 64)];
    }
    $eventInsert = $pdo->prepare(
        'INSERT INTO suite_customer_sync_events
            (id,event_id,tenant_id,binding_id,customer_id,client_id,source_version,
             display_name,status,occurred_at,request_sha256)
         VALUES (?,?,1,501,?,11,?,\'Managed One\',?,\'2026-08-30 16:00:00\',?)'
    );
    foreach ($events as [$id, $eventId, $version, $status, $hash]) {
        $eventInsert->execute([
            $id, $eventId, '11111111-1111-4111-8111-111111111111', $version, $status, $hash,
        ]);
    }
    $current = $events[array_key_last($events)];
    $binding = $pdo->prepare(
        'INSERT INTO suite_customer_sync_bindings
            (id,tenant_id,customer_id,client_id,source_version,display_name,status,
             last_event_id,last_request_sha256)
         VALUES (501,1,?,11,?,\'Managed One\',?,?,?)'
    );
    $binding->execute([
        '11111111-1111-4111-8111-111111111111',
        $current[2], $current[3], $current[1], $current[4],
    ]);

    $portalStatus = $portalActive ? 'active' : 'disabled';
    $portal = $pdo->prepare(
        'INSERT INTO customer_portal_bindings
            (id,identity_tenant_slug,tenant_id,client_id,status,prepared_by_user_id,
             last_changed_by_user_id,status_reason)
         VALUES (601,\'managed-one\',1,11,?,101,101,\'fixture\')'
    );
    $portal->execute([$portalStatus]);
    $pdo->exec("INSERT INTO customer_portal_binding_events
        (id,tenant_id,client_id,binding_id,actor_user_id,event_kind,from_status,to_status,
         reason,snapshot_json) VALUES
        (1,1,11,601,101,'prepared',NULL,'disabled','fixture','{\"status\":\"disabled\"}')");
    if ($portalActive) {
        $pdo->exec("INSERT INTO customer_portal_binding_events
            (id,tenant_id,client_id,binding_id,actor_user_id,event_kind,from_status,to_status,
             reason,snapshot_json) VALUES
            (2,1,11,601,101,'enabled','disabled','active','fixture','{\"status\":\"active\"}')");
    }

    $scheduleKey = managed_customer_activation_schedule_key('11111111-1111-4111-8111-111111111111');
    $schedule = $pdo->prepare(
        'INSERT INTO business_report_schedule_versions
            (id,tenant_id,schedule_key,version_no,definition_version_id,client_id,
             recipient_email,schedule_timezone,delivery_weekday,delivery_local_time,
             canary,status,created_by_user_id,reason)
         VALUES (?,?,?,?,701,11,\'reports@managed-one.example\',\'America/Los_Angeles\',
                 3,\'09:00:00\',1,?,101,\'fixture\')'
    );
    $schedule->execute([801, 1, $scheduleKey, 1, 'disabled']);
    if ($scheduleActive) $schedule->execute([802, 1, $scheduleKey, 2, 'active']);
    $pdo->exec("INSERT INTO business_report_id_client_bindings
        VALUES (1,11,'ewid-t91','managed-one',101,'fixture')");
    $scope = $pdo->prepare(
        'INSERT INTO business_report_contact_scope_bindings VALUES (1,?,\'CLIENT\',101,\'fixture\')'
    );
    $scope->execute([$scheduleKey]);
    $pdo->exec("INSERT INTO business_report_id_client_contact_snapshots
        (id,tenant_id,client_id,schedule_version_id,id_tenant_key,contact_version,
         recipient_email,response_generated_at,request_nonce_sha256,response_sha256,
         created_by_user_id,reason)
        VALUES (901,1,11,801,'ewid-t91',4,'reports@managed-one.example',
                '2026-08-30 16:00:00','" . str_repeat('8', 64) . "','" . str_repeat('9', 64) . "',
                101,'fixture')");
    foreach (['tickets','time_entries','coastmark_sentinel','endpoint_control_sentinel','ai_write_sentinel'] as $table) {
        $pdo->exec("INSERT INTO {$table} VALUES (1,'unchanged')");
    }
    return $pdo;
}

/**
 * The 034 shape: the nullable report-map carrier and contact facts, the identity metadata and anchor
 * columns, the 033 immutable event admission (birth `created`), and one current managed provider for
 * provider-one with issuer 44 whose owner is the allowlisted actor.
 */
function lifecycle_identity_sqlite(string $currentStatus = 'active', bool $portalActive = true, bool $scheduleActive = true): PDO
{
    $pdo = lifecycle_sqlite($currentStatus, $portalActive, $scheduleActive);
    foreach ([
        'DROP TABLE managed_customer_lifecycle_restore_receipts',
        'CREATE TABLE managed_customer_lifecycle_restore_receipts (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
            client_id INTEGER NOT NULL, source_binding_id INTEGER NOT NULL,
            customer_id TEXT NOT NULL, source_event_receipt_id INTEGER NOT NULL,
            source_event_id TEXT NOT NULL, source_version INTEGER NOT NULL,
            source_request_sha256 TEXT NOT NULL, id_customer_receipt_id TEXT NOT NULL,
            id_customer_status TEXT NOT NULL, id_lifecycle_version INTEGER NOT NULL,
            id_lifecycle_transition_id INTEGER NOT NULL, id_lifecycle_action TEXT NOT NULL,
            id_lifecycle_evidence_sha256 TEXT NOT NULL,
            id_identity_tenant_status TEXT NOT NULL, id_oauth_session_version INTEGER NOT NULL,
            id_lifecycle_owned INTEGER NOT NULL, id_tenant_key TEXT NULL,
            identity_tenant_slug TEXT NOT NULL, contact_version INTEGER NULL,
            recipient_sha256 TEXT NULL, id_response_generated_at TEXT NOT NULL,
            id_request_nonce_sha256 TEXT NOT NULL, id_response_sha256 TEXT NOT NULL,
            portal_owner_receipt_id INTEGER NULL, portal_binding_id INTEGER NULL,
            portal_before_event_id INTEGER NULL, portal_active_event_id INTEGER NULL,
            portal_restored INTEGER NOT NULL, portal_state_sha256 TEXT NULL,
            schedule_owner_receipt_id INTEGER NULL, schedule_key TEXT NOT NULL,
            schedule_before_version_id INTEGER NULL, schedule_prepared_version_id INTEGER NULL,
            contact_snapshot_id INTEGER NULL, schedule_active_version_id INTEGER NULL,
            schedule_restored INTEGER NOT NULL, schedule_state_sha256 TEXT NULL,
            actor_user_id INTEGER NOT NULL, evidence_sha256 TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            id_proof_scope TEXT NULL, id_initial_receipt_id TEXT NULL, id_provider_tenant_slug TEXT NULL,
            id_issuer_suite_tenant_id INTEGER NULL, id_projection_binding_sha256 TEXT NULL,
            id_projection_tenant_key TEXT NULL, id_restore_anchor_source_version INTEGER NULL,
            id_restore_anchor_receipt_id TEXT NULL, id_restore_anchor_event_id TEXT NULL,
            id_restore_anchor_transition_id INTEGER NULL, id_restore_anchor_evidence_sha256 TEXT NULL,
            UNIQUE(customer_id,source_version), UNIQUE(source_event_receipt_id))',
        'ALTER TABLE suite_customer_sync_events ADD COLUMN admission TEXT NULL',
        "UPDATE suite_customer_sync_events SET admission='created' WHERE source_version=1",
        'ALTER TABLE users ADD COLUMN suite_subject TEXT NULL',
        "UPDATE users SET suite_subject='owner-subject-101' WHERE id=101",
        'CREATE TABLE suite_managed_providers (
            tenant_id INTEGER PRIMARY KEY, issuer_tenant_id INTEGER NOT NULL, provider_slug TEXT NOT NULL UNIQUE,
            owner_user_id INTEGER NOT NULL, owner_subject TEXT NOT NULL, lease_until TEXT NOT NULL)',
        "INSERT INTO suite_managed_providers VALUES (1,44,'provider-one',101,'owner-subject-101','2999-01-01 00:00:00')",
    ] as $sql) {
        $pdo->exec($sql);
    }
    return $pdo;
}

/**
 * A decoded schema-3 identity-only proof (managed_customer_id_identity_decode_response() shape). A
 * current `restored` proof is its own anchor in all five facts (A155); an `observed_active` one
 * carries the actual earlier restored v3 anchor.
 */
function lifecycle_identity_proof(
    int $version = 3,
    string $eventId = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
    string $action = 'restored',
): array {
    $current = [
        'source_version' => $version,
        'receipt_id' => str_repeat('1', 64),
        'event_id' => $eventId,
        'transition_id' => 9 + $version,
        'evidence_sha256' => str_repeat('4', 64),
    ];
    return [
        'schema_version' => 3,
        'proof_scope' => 'identity_lifecycle_only',
        'customer_id' => '11111111-1111-4111-8111-111111111111',
        'provider_tenant_slug' => 'provider-one',
        'source_version' => $version,
        'customer_receipt_id' => str_repeat('1', 64),
        'customer_event_id' => $eventId,
        'initial_receipt_id' => str_repeat('e', 64),
        'customer_status' => 'active',
        'lifecycle_version' => 1,
        'lifecycle_transition_id' => 9 + $version,
        'lifecycle_action' => $action,
        'lifecycle_evidence_sha256' => str_repeat('4', 64),
        'identity_tenant_status' => 'active',
        'identity_oauth_session_version' => 5,
        'lifecycle_owned' => false,
        'tenant_key' => 'ewid-t91',
        'tenant_slug' => 'managed-one',
        'issuer_suite_tenant_id' => 44,
        'projection_binding_sha256' => str_repeat('f', 64),
        'restoration_anchor' => $action === 'restored' ? $current : [
            'source_version' => 3,
            'receipt_id' => str_repeat('a', 64),
            'event_id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
            'transition_id' => 12,
            'evidence_sha256' => str_repeat('d', 64),
        ],
        'generated_at_db' => '2026-08-30 17:00:00',
        'request_nonce_sha256' => str_repeat('2', 64),
        'response_sha256' => str_repeat('3', 64),
    ];
}

function lifecycle_restore_count(PDO $pdo): int
{
    return (int)$pdo->query('SELECT COUNT(*) FROM managed_customer_lifecycle_restore_receipts')->fetchColumn();
}

function lifecycle_candidate(PDO $pdo): array
{
    $rows = managed_customer_lifecycle_candidates($pdo, lifecycle_config());
    if (count($rows) !== 1) throw new RuntimeException('lifecycle candidate fixture failed');
    return $rows[0];
}

/** Every row a restoration could write, for byte and row-count comparison. */
function lifecycle_surface_rows(PDO $pdo): array
{
    $rows = [];
    foreach ([
        'customer_portal_bindings', 'customer_portal_binding_events',
        'business_report_schedule_versions', 'business_report_id_client_contact_snapshots',
        'managed_customer_lifecycle_receipts',
    ] as $table) {
        $rows[$table] = $pdo->query("SELECT * FROM {$table} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    }
    return $rows;
}

/** Append the next immutable source event and advance the binding to it. */
function lifecycle_sqlite_advance(PDO $pdo, int $version, string $eventId, string $status, string $hash): void
{
    $pdo->prepare(
        "INSERT INTO suite_customer_sync_events
            (event_id,tenant_id,binding_id,customer_id,client_id,source_version,
             display_name,status,occurred_at,request_sha256)
         VALUES (?,1,501,'11111111-1111-4111-8111-111111111111',11,?,'Managed One',?,
                 '2026-08-30 16:00:00',?)"
    )->execute([$eventId, $version, $status, $hash]);
    $update = $pdo->prepare(
        'UPDATE suite_customer_sync_bindings
            SET source_version=?, status=?, last_event_id=?, last_request_sha256=?
          WHERE id=501 AND source_version=?'
    );
    $update->execute([$version, $status, $eventId, $hash, $version - 1]);
    if ($update->rowCount() !== 1) throw new RuntimeException('source advance fixture failed');
}

/** @return array<string,mixed> */
function lifecycle_evidence_for(int $version, string $eventId): array
{
    $evidence = lifecycle_base_evidence();
    $evidence['source_version'] = $version;
    $evidence['customer_event_id'] = $eventId;
    return $evidence;
}

/** Insert a digest-valid containment receipt for the current source event with forged surface facts. */
function lifecycle_forge_owner(PDO $pdo, array $surface): void
{
    $event = $pdo->query(
        'SELECT id, event_id, source_version, request_sha256 FROM suite_customer_sync_events
          ORDER BY id DESC LIMIT 1'
    )->fetch(PDO::FETCH_ASSOC);
    $facts = array_replace([
        'tenant_id' => 1, 'client_id' => 11, 'source_binding_id' => 501,
        'customer_id' => '11111111-1111-4111-8111-111111111111',
        'source_event_receipt_id' => (int)$event['id'], 'source_event_id' => (string)$event['event_id'],
        'source_version' => (int)$event['source_version'], 'source_status' => 'active',
        'action' => 'reactivation_blocked', 'portal_binding_id' => 601, 'portal_was_active' => 0,
        'portal_before_event_id' => null, 'portal_state_event_id' => null,
        'portal_disabled_event_id' => null, 'portal_state_sha256' => null,
        'schedule_key' => managed_customer_activation_schedule_key('11111111-1111-4111-8111-111111111111'),
        'schedule_was_active' => 0, 'schedule_active_version_id' => null,
        'schedule_state_version_id' => null, 'schedule_disabled_version_id' => null,
        'schedule_state_sha256' => null, 'actor_user_id' => 101,
        'source_request_sha256' => (string)$event['request_sha256'],
    ], $surface);
    $pdo->prepare(
        'INSERT INTO managed_customer_lifecycle_receipts (' . implode(',', array_keys($facts))
        . ',evidence_sha256) VALUES (' . implode(',', array_fill(0, count($facts) + 1, '?')) . ')'
    )->execute([...array_values($facts), managed_customer_lifecycle_receipt_sha256($facts)]);
}

$defaults = managed_customer_lifecycle_config([]);
lifecycle_check($defaults['enabled'] === false, 'lifecycle is not default-off');
lifecycle_check($defaults['restoration_enabled'] === false, 'restoration is not default-off');
lifecycle_refuses(
    ManagedCustomerLifecycleValidationException::class,
    static fn() => managed_customer_lifecycle_config([
        'restoration_enabled' => true,
    ]),
    'restoration could be enabled without the containment gate and exact allowlists',
);
lifecycle_check(
    managed_customer_lifecycle_config(lifecycle_config(['restoration_enabled' => true]))['restoration_enabled'],
    'explicit restoration gate could not be enabled with exact containment allowlists',
);
$tooManyCustomers = [];
for ($index = 1; $index <= MANAGED_CUSTOMER_LIFECYCLE_MAX_CUSTOMERS + 1; $index++) {
    $tooManyCustomers[] = sprintf('%08x-0000-4000-8000-%012x', $index, $index);
}
lifecycle_refuses(
    ManagedCustomerLifecycleValidationException::class,
    static fn() => managed_customer_lifecycle_config([
        'customer_ids' => $tooManyCustomers,
    ]),
    'customer scan allowlist exceeded its hard bound',
);
lifecycle_refuses(
    ManagedCustomerLifecycleValidationException::class,
    static fn() => managed_customer_lifecycle_config([
        'batch_size' => MANAGED_CUSTOMER_LIFECYCLE_MAX_BATCH + 1,
    ]),
    'completed-work batch exceeded its hard bound',
);
lifecycle_check(
    managed_customer_lifecycle_base_evidence(lifecycle_base_evidence())['schema_version'] === 2,
    'current schema-2 base evidence is not compatibility parsed',
);
lifecycle_check(
    managed_customer_lifecycle_restore_evidence(lifecycle_base_evidence())['lifecycle_action'] === 'restored',
    'exact restored schema-2 evidence was not accepted by the isolated restore parser',
);
$observedEvidence = lifecycle_base_evidence();
$observedEvidence['lifecycle_action'] = 'observed_active';
lifecycle_refuses(
    ManagedCustomerLifecycleGateException::class,
    static fn() => managed_customer_lifecycle_restore_evidence($observedEvidence),
    'observed-active evidence cleared a post-inactive latch',
);

$pdo = lifecycle_sqlite();
lifecycle_check(!managed_customer_operational($pdo, 1, 11), 'inactive managed client stayed operational');
lifecycle_check(managed_customer_operational($pdo, 1, 12), 'legacy client behavior changed');
lifecycle_check(
    portal_active_binding_by_identity($pdo, 'managed-one') === null,
    'inactive managed portal admitted a new session',
);
lifecycle_check(
    portal_active_binding_recheck($pdo, 601, 'managed-one', 1, 11) === null,
    'inactive managed portal preserved a current session recheck',
);
$sentinels = [];
foreach (['tickets','time_entries','coastmark_sentinel','endpoint_control_sentinel','ai_write_sentinel'] as $table) {
    $sentinels[$table] = $pdo->query("SELECT * FROM {$table}")->fetchAll(PDO::FETCH_ASSOC);
}
$candidate = lifecycle_candidate($pdo);
$result = managed_customer_lifecycle_apply($pdo, $candidate, 101, lifecycle_config());
lifecycle_check($result['action'] === 'contained', 'inactive source was not contained');
lifecycle_check(
    (string)$pdo->query('SELECT status FROM customer_portal_bindings WHERE id=601')->fetchColumn() === 'disabled',
    'active portal was not physically disabled',
);
lifecycle_check(
    (string)$pdo->query("SELECT status FROM business_report_schedule_versions
        ORDER BY version_no DESC LIMIT 1")->fetchColumn() === 'disabled'
        && (int)$pdo->query('SELECT COUNT(*) FROM business_report_schedule_versions')->fetchColumn() === 3,
    'active report schedule did not get one appended disabled version',
);
$receipt = $pdo->query('SELECT * FROM managed_customer_lifecycle_receipts')->fetch(PDO::FETCH_ASSOC);
lifecycle_check(
    is_array($receipt)
        && (int)$receipt['portal_was_active'] === 1
        && (int)$receipt['schedule_was_active'] === 1
        && (int)$receipt['portal_before_event_id'] === 2
        && (int)$receipt['portal_disabled_event_id'] === 3
        && (int)$receipt['schedule_active_version_id'] === 802
        && (int)$receipt['schedule_disabled_version_id'] === 803
        && managed_customer_lifecycle_receipt_valid($receipt),
    'containment receipt did not pin exact source portal schedule actor and hashes',
);
foreach ($sentinels as $table => $before) {
    lifecycle_check(
        $pdo->query("SELECT * FROM {$table}")->fetchAll(PDO::FETCH_ASSOC) === $before,
        "containment changed forbidden {$table} state",
    );
}
$replay = managed_customer_lifecycle_apply($pdo, $candidate, 101, lifecycle_config());
lifecycle_check(
    $replay['action'] === 'replayed'
        && (int)$pdo->query('SELECT COUNT(*) FROM managed_customer_lifecycle_receipts')->fetchColumn() === 1
        && (int)$pdo->query('SELECT COUNT(*) FROM business_report_schedule_versions')->fetchColumn() === 3,
    'lost acknowledgement replay duplicated containment state',
);

$faultPdo = lifecycle_sqlite();
$faultCandidate = lifecycle_candidate($faultPdo);
lifecycle_refuses(
    RuntimeException::class,
    static fn() => managed_customer_lifecycle_apply(
        $faultPdo,
        $faultCandidate,
        101,
        lifecycle_config(),
        static function (string $point): void {
            if ($point === 'after_schedule') throw new RuntimeException('fixture interruption');
        },
    ),
    'interruption after surface changes did not fail',
);
lifecycle_check(
    (string)$faultPdo->query('SELECT status FROM customer_portal_bindings')->fetchColumn() === 'active'
        && (int)$faultPdo->query('SELECT COUNT(*) FROM business_report_schedule_versions')->fetchColumn() === 2
        && (int)$faultPdo->query('SELECT COUNT(*) FROM managed_customer_lifecycle_receipts')->fetchColumn() === 0,
    'interruption left partial portal schedule or receipt state',
);

$humanPdo = lifecycle_sqlite('inactive', false, false);
$humanResult = managed_customer_lifecycle_apply(
    $humanPdo,
    lifecycle_candidate($humanPdo),
    101,
    lifecycle_config(),
);
$humanReceipt = $humanPdo->query('SELECT * FROM managed_customer_lifecycle_receipts')->fetch(PDO::FETCH_ASSOC);
lifecycle_check(
    $humanResult['action'] === 'contained'
        && (int)$humanReceipt['portal_was_active'] === 0
        && (int)$humanReceipt['schedule_was_active'] === 0
        && $humanReceipt['portal_disabled_event_id'] === null
        && $humanReceipt['schedule_disabled_version_id'] === null
        && (int)$humanPdo->query('SELECT COUNT(*) FROM business_report_schedule_versions')->fetchColumn() === 1,
    'pre-existing human disable was claimed or overwritten by containment',
);

$reactivationPdo = lifecycle_sqlite('active', true, true);
lifecycle_check(
    !managed_customer_operational($reactivationPdo, 1, 11),
    'later active source event cleared the inactive latch without ID restoration evidence',
);
$reactivationResult = managed_customer_lifecycle_apply(
    $reactivationPdo,
    lifecycle_candidate($reactivationPdo),
    101,
    lifecycle_config(),
);
lifecycle_check(
    $reactivationResult['action'] === 'reactivation_blocked'
        && (string)$reactivationPdo->query('SELECT status FROM customer_portal_bindings')->fetchColumn() === 'disabled'
        && (string)$reactivationPdo->query("SELECT status FROM business_report_schedule_versions
            ORDER BY version_no DESC LIMIT 1")->fetchColumn() === 'disabled',
    'post-inactive source activation did not stay physically blocked',
);

$restoreCandidate = lifecycle_candidate($reactivationPdo);
$restoreResult = managed_customer_lifecycle_restore(
    $reactivationPdo,
    $restoreCandidate,
    lifecycle_base_evidence(),
    101,
    lifecycle_config(['restoration_enabled' => true]),
    lifecycle_report_config(),
);
$restoreReceipt = $reactivationPdo->query(
    'SELECT * FROM managed_customer_lifecycle_restore_receipts'
)->fetch(PDO::FETCH_ASSOC);
lifecycle_check(
    $restoreResult['action'] === 'restored'
        && $restoreResult['portal_restored'] === 1
        && $restoreResult['report_schedule_restored'] === 1
        && is_array($restoreReceipt)
        && managed_customer_lifecycle_restore_receipt_valid($restoreReceipt)
        && (string)$restoreReceipt['source_event_id'] === 'cccccccc-cccc-4ccc-8ccc-cccccccccccc'
        && (string)$restoreReceipt['id_lifecycle_action'] === 'restored'
        && (int)$restoreReceipt['portal_owner_receipt_id'] > 0
        && (int)$restoreReceipt['schedule_owner_receipt_id'] > 0
        && (int)$restoreReceipt['portal_active_event_id'] === 4
        && (int)$restoreReceipt['schedule_prepared_version_id'] === 804
        && (int)$restoreReceipt['schedule_active_version_id'] === 805
        && (int)$restoreReceipt['contact_snapshot_id'] > 0,
    'restoration did not bind the exact source ID portal schedule contact actor and hashes',
);
lifecycle_check(
    managed_customer_operational($reactivationPdo, 1, 11)
        && portal_active_binding_by_identity($reactivationPdo, 'managed-one') !== null
        && (string)$reactivationPdo->query(
            'SELECT status FROM business_report_schedule_versions ORDER BY version_no DESC LIMIT 1'
        )->fetchColumn() === 'active'
        && (int)$reactivationPdo->query(
            'SELECT COUNT(*) FROM business_report_id_client_contact_snapshots'
        )->fetchColumn() === 2,
    'exact restored receipt did not clear the latch with a fresh contact snapshot and active surfaces',
);
$freshReplayEvidence = lifecycle_base_evidence();
$freshReplayEvidence['generated_at_db'] = '2026-08-30 17:00:01';
$freshReplayEvidence['request_nonce_sha256'] = str_repeat('5', 64);
$freshReplayEvidence['response_sha256'] = str_repeat('6', 64);
$restoreReplay = managed_customer_lifecycle_restore(
    $reactivationPdo,
    $restoreCandidate,
    $freshReplayEvidence,
    101,
    lifecycle_config(['restoration_enabled' => true]),
    lifecycle_report_config(),
);
lifecycle_check(
    $restoreReplay['action'] === 'restore_replayed'
        && (int)$reactivationPdo->query(
            'SELECT COUNT(*) FROM managed_customer_lifecycle_restore_receipts'
        )->fetchColumn() === 1
        && (int)$reactivationPdo->query(
            'SELECT COUNT(*) FROM business_report_schedule_versions'
        )->fetchColumn() === 5,
    'lost acknowledgement replay duplicated restoration surfaces or evidence',
);

$wrongEventPdo = lifecycle_sqlite('active', true, true);
$wrongEventCandidate = lifecycle_candidate($wrongEventPdo);
managed_customer_lifecycle_apply(
    $wrongEventPdo,
    $wrongEventCandidate,
    101,
    lifecycle_config(),
);
$wrongEventEvidence = lifecycle_base_evidence();
$wrongEventEvidence['customer_event_id'] = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
lifecycle_refuses(
    ManagedCustomerLifecycleGateException::class,
    static fn() => managed_customer_lifecycle_restore(
        $wrongEventPdo,
        $wrongEventCandidate,
        $wrongEventEvidence,
        101,
        lifecycle_config(['restoration_enabled' => true]),
        lifecycle_report_config(),
    ),
    'signed evidence for a different source event cleared the inactive latch',
);
lifecycle_check(
    !managed_customer_operational($wrongEventPdo, 1, 11)
        && (int)$wrongEventPdo->query(
            'SELECT COUNT(*) FROM managed_customer_lifecycle_restore_receipts'
        )->fetchColumn() === 0,
    'source-event mismatch left restoration evidence or an open boundary',
);

$restoreFaultPdo = lifecycle_sqlite('active', true, true);
$restoreFaultCandidate = lifecycle_candidate($restoreFaultPdo);
managed_customer_lifecycle_apply(
    $restoreFaultPdo,
    $restoreFaultCandidate,
    101,
    lifecycle_config(),
);
lifecycle_refuses(
    RuntimeException::class,
    static fn() => managed_customer_lifecycle_restore(
        $restoreFaultPdo,
        $restoreFaultCandidate,
        lifecycle_base_evidence(),
        101,
        lifecycle_config(['restoration_enabled' => true]),
        lifecycle_report_config(),
        static function (string $point): void {
            if ($point === 'after_restore_contact') {
                throw new RuntimeException('fixture restoration interruption');
            }
        },
    ),
    'interruption after fresh contact evidence did not fail',
);
lifecycle_check(
    !managed_customer_operational($restoreFaultPdo, 1, 11)
        && (string)$restoreFaultPdo->query(
            'SELECT status FROM customer_portal_bindings'
        )->fetchColumn() === 'disabled'
        && (int)$restoreFaultPdo->query(
            'SELECT COUNT(*) FROM business_report_schedule_versions'
        )->fetchColumn() === 3
        && (int)$restoreFaultPdo->query(
            'SELECT COUNT(*) FROM business_report_id_client_contact_snapshots'
        )->fetchColumn() === 1
        && (int)$restoreFaultPdo->query(
            'SELECT COUNT(*) FROM managed_customer_lifecycle_restore_receipts'
        )->fetchColumn() === 0,
    'restoration interruption leaked an active surface, snapshot, or receipt',
);

$humanHoldPdo = lifecycle_sqlite('active', true, true);
$humanHoldCandidate = lifecycle_candidate($humanHoldPdo);
managed_customer_lifecycle_apply(
    $humanHoldPdo,
    $humanHoldCandidate,
    101,
    lifecycle_config(),
);
$humanHoldPdo->exec(
    "UPDATE customer_portal_bindings
        SET status='active',status_reason='human review',last_changed_by_user_id=101 WHERE id=601"
);
$humanHoldPdo->exec(
    "UPDATE customer_portal_bindings
        SET status='disabled',status_reason='human hold',last_changed_by_user_id=101 WHERE id=601"
);
$humanHoldScheduleKey = managed_customer_activation_schedule_key(
    '11111111-1111-4111-8111-111111111111'
);
$humanHoldSchedule = $humanHoldPdo->prepare(
    "INSERT INTO business_report_schedule_versions
        (tenant_id,schedule_key,version_no,definition_version_id,client_id,recipient_email,
         schedule_timezone,delivery_weekday,delivery_local_time,canary,status,
         created_by_user_id,reason)
     VALUES (1,?,4,701,11,'reports@managed-one.example','America/Los_Angeles',
             3,'09:00:00',1,'active',101,'human review'),
            (1,?,5,701,11,'reports@managed-one.example','America/Los_Angeles',
             3,'09:00:00',1,'disabled',101,'human hold')"
);
$humanHoldSchedule->execute([$humanHoldScheduleKey, $humanHoldScheduleKey]);
$humanHoldRestore = managed_customer_lifecycle_restore(
    $humanHoldPdo,
    $humanHoldCandidate,
    lifecycle_base_evidence(),
    101,
    lifecycle_config(['restoration_enabled' => true]),
    lifecycle_report_config(),
);
lifecycle_check(
    $humanHoldRestore['portal_restored'] === 0
        && $humanHoldRestore['report_schedule_restored'] === 0
        && managed_customer_operational($humanHoldPdo, 1, 11)
        && (string)$humanHoldPdo->query(
            'SELECT status FROM customer_portal_bindings'
        )->fetchColumn() === 'disabled'
        && (string)$humanHoldPdo->query(
            'SELECT status FROM business_report_schedule_versions ORDER BY version_no DESC LIMIT 1'
        )->fetchColumn() === 'disabled',
    'a later human disable/hold was overwritten or prevented an otherwise exact source release',
);

// Exact current v3 for a customer whose portal and schedule were never contained:
// the worker restores first and preserves both active surfaces without a write.
$runRestorePdo = lifecycle_sqlite('active', true, true);
$runRestoreBefore = lifecycle_surface_rows($runRestorePdo);
$runRestoreResults = managed_customer_lifecycle_run(
    $runRestorePdo,
    lifecycle_config(['restoration_enabled' => true]),
    lifecycle_report_config(),
    [],
    static function (string $customerId, array $config): array {
        if ($customerId !== '11111111-1111-4111-8111-111111111111' || $config !== []) {
            throw new RuntimeException('restore fetch boundary changed');
        }
        return lifecycle_base_evidence();
    },
);
$runRestoreReceipt = $runRestorePdo->query(
    'SELECT * FROM managed_customer_lifecycle_restore_receipts'
)->fetch(PDO::FETCH_ASSOC);
$runRestoreSchedule = $runRestorePdo->query(
    'SELECT * FROM business_report_schedule_versions WHERE id=802'
)->fetch(PDO::FETCH_ASSOC);
lifecycle_check(
    count($runRestoreResults) === 1
        && ($runRestoreResults[0]['action'] ?? null) === 'restored'
        && ($runRestoreResults[0]['portal_restored'] ?? null) === 0
        && ($runRestoreResults[0]['report_schedule_restored'] ?? null) === 0
        && managed_customer_operational($runRestorePdo, 1, 11),
    'bounded worker did not restore-first an already-active, never-contained customer',
);
lifecycle_check(
    lifecycle_surface_rows($runRestorePdo) === $runRestoreBefore,
    'preserved-active restoration wrote a portal, audit, schedule, contact or containment row',
);
lifecycle_check(
    is_array($runRestoreReceipt)
        && managed_customer_lifecycle_restore_receipt_valid($runRestoreReceipt)
        && (string)$runRestoreReceipt['source_event_id'] === 'cccccccc-cccc-4ccc-8ccc-cccccccccccc'
        && (int)$runRestoreReceipt['portal_restored'] === 0
        && $runRestoreReceipt['portal_owner_receipt_id'] === null
        && $runRestoreReceipt['portal_active_event_id'] === null
        && (int)$runRestoreReceipt['portal_before_event_id'] === 2
        && (string)$runRestoreReceipt['portal_state_sha256'] === hash('sha256', '{"status":"active"}')
        && (int)$runRestoreReceipt['schedule_restored'] === 0
        && $runRestoreReceipt['schedule_owner_receipt_id'] === null
        && $runRestoreReceipt['schedule_prepared_version_id'] === null
        && $runRestoreReceipt['contact_snapshot_id'] === null
        && $runRestoreReceipt['schedule_active_version_id'] === null
        && (int)$runRestoreReceipt['schedule_before_version_id'] === 802
        && is_array($runRestoreSchedule)
        && (string)$runRestoreReceipt['schedule_state_sha256']
            === managed_customer_lifecycle_schedule_sha256($runRestoreSchedule),
    'preserved-active receipt did not pin the exact latest audit, version and hashes with no owner or transition',
);

$runBlockedPdo = lifecycle_sqlite('active', true, true);
$runBlockedResults = managed_customer_lifecycle_run($runBlockedPdo, lifecycle_config());
lifecycle_check(
    count($runBlockedResults) === 1
        && ($runBlockedResults[0]['action'] ?? null) === 'reactivation_blocked'
        && (string)$runBlockedPdo->query('SELECT status FROM customer_portal_bindings')->fetchColumn() === 'disabled'
        && (string)$runBlockedPdo->query(
            'SELECT status FROM business_report_schedule_versions ORDER BY version_no DESC LIMIT 1'
        )->fetchColumn() === 'disabled'
        && (int)$runBlockedPdo->query(
            'SELECT COUNT(*) FROM managed_customer_lifecycle_restore_receipts'
        )->fetchColumn() === 0
        && !managed_customer_operational($runBlockedPdo, 1, 11),
    'with restoration disabled the worker did not keep an active-after-inactive customer contained',
);

$runRefusedPdo = lifecycle_sqlite('active', true, true);
$runRefusedBefore = lifecycle_surface_rows($runRefusedPdo);
$runRefusedResults = managed_customer_lifecycle_run(
    $runRefusedPdo,
    lifecycle_config(['restoration_enabled' => true]),
    lifecycle_report_config(),
    [],
    static fn(string $customerId, array $config): array => $observedEvidence,
);
lifecycle_check(
    count($runRefusedResults) === 1
        && ($runRefusedResults[0]['action'] ?? null) === 'refused'
        && ($runRefusedResults[0]['error_class'] ?? null) === ManagedCustomerLifecycleGateException::class
        && lifecycle_surface_rows($runRefusedPdo) === $runRefusedBefore
        && (int)$runRefusedPdo->query(
            'SELECT COUNT(*) FROM managed_customer_lifecycle_restore_receipts'
        )->fetchColumn() === 0
        && !managed_customer_operational($runRefusedPdo, 1, 11),
    'observed-active evidence through the worker cleared the latch or touched a surface',
);

// A receipt that claims to own the current active state is ambiguous, never authority.
$forgedPortalPdo = lifecycle_sqlite('active', true, true);
lifecycle_forge_owner($forgedPortalPdo, [
    'portal_was_active' => 1, 'portal_before_event_id' => 2, 'portal_state_event_id' => 2,
    'portal_disabled_event_id' => 2, 'portal_state_sha256' => hash('sha256', '{"status":"active"}'),
]);
$forgedPortalBefore = lifecycle_surface_rows($forgedPortalPdo);
lifecycle_refuses(
    ManagedCustomerLifecycleConflictException::class,
    static fn() => managed_customer_lifecycle_restore(
        $forgedPortalPdo,
        lifecycle_candidate($forgedPortalPdo),
        lifecycle_base_evidence(),
        101,
        lifecycle_config(['restoration_enabled' => true]),
        lifecycle_report_config(),
    ),
    'forged current ownership of an active portal was accepted',
);
$forgedSchedulePdo = lifecycle_sqlite('active', true, true);
lifecycle_forge_owner($forgedSchedulePdo, [
    'schedule_was_active' => 1, 'schedule_active_version_id' => 802,
    'schedule_state_version_id' => 802, 'schedule_disabled_version_id' => 802,
    'schedule_state_sha256' => managed_customer_lifecycle_schedule_sha256(
        $forgedSchedulePdo->query('SELECT * FROM business_report_schedule_versions WHERE id=802')
            ->fetch(PDO::FETCH_ASSOC),
    ),
]);
$forgedScheduleBefore = lifecycle_surface_rows($forgedSchedulePdo);
lifecycle_refuses(
    ManagedCustomerLifecycleConflictException::class,
    static fn() => managed_customer_lifecycle_restore(
        $forgedSchedulePdo,
        lifecycle_candidate($forgedSchedulePdo),
        lifecycle_base_evidence(),
        101,
        lifecycle_config(['restoration_enabled' => true]),
        lifecycle_report_config(),
    ),
    'forged current ownership of an active report schedule was accepted',
);
lifecycle_check(
    lifecycle_surface_rows($forgedPortalPdo) === $forgedPortalBefore
        && lifecycle_surface_rows($forgedSchedulePdo) === $forgedScheduleBefore
        && (int)$forgedPortalPdo->query('SELECT COUNT(*) FROM managed_customer_lifecycle_restore_receipts')->fetchColumn() === 0
        && (int)$forgedSchedulePdo->query('SELECT COUNT(*) FROM managed_customer_lifecycle_restore_receipts')->fetchColumn() === 0
        && !managed_customer_operational($forgedPortalPdo, 1, 11)
        && !managed_customer_operational($forgedSchedulePdo, 1, 11),
    'ambiguous ownership refusal wrote a surface or receipt or cleared the latch',
);

$preserveFaultPdo = lifecycle_sqlite('active', true, true);
$preserveFaultBefore = lifecycle_surface_rows($preserveFaultPdo);
$preserveFaultReached = false;
$preserveFault = static function (string $point) use (&$preserveFaultReached): void {
    if ($point === 'before_restore_receipt') {
        $preserveFaultReached = true;
        throw new RuntimeException('fixture preserved-active interruption');
    }
};
lifecycle_refuses(
    RuntimeException::class,
    static fn() => managed_customer_lifecycle_restore(
        $preserveFaultPdo,
        lifecycle_candidate($preserveFaultPdo),
        lifecycle_base_evidence(),
        101,
        lifecycle_config(['restoration_enabled' => true]),
        lifecycle_report_config(),
        $preserveFault,
    ),
    'interruption before the preserved-active receipt did not fail',
);
lifecycle_check(
    $preserveFaultReached
        && lifecycle_surface_rows($preserveFaultPdo) === $preserveFaultBefore
        && (int)$preserveFaultPdo->query(
            'SELECT COUNT(*) FROM managed_customer_lifecycle_restore_receipts'
        )->fetchColumn() === 0
        && !managed_customer_operational($preserveFaultPdo, 1, 11),
    'preserved-active interruption left a receipt or a write, or cleared the latch',
);

// A legitimate owned cycle, then a later current active source version:
// contain v2, restore the owned surfaces at v3, then v4 preserves them.
$cyclePdo = lifecycle_sqlite('inactive', true, true);
managed_customer_lifecycle_apply($cyclePdo, lifecycle_candidate($cyclePdo), 101, lifecycle_config());
lifecycle_sqlite_advance($cyclePdo, 3, 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'active', str_repeat('c', 64));
$cycleOwned = managed_customer_lifecycle_run(
    $cyclePdo,
    lifecycle_config(['restoration_enabled' => true]),
    lifecycle_report_config(),
    [],
    static fn(string $customerId, array $config): array
        => lifecycle_evidence_for(3, 'cccccccc-cccc-4ccc-8ccc-cccccccccccc'),
);
lifecycle_check(
    ($cycleOwned[0]['action'] ?? null) === 'restored'
        && ($cycleOwned[0]['portal_restored'] ?? null) === 1
        && ($cycleOwned[0]['report_schedule_restored'] ?? null) === 1
        && (int)$cyclePdo->query('SELECT COUNT(*) FROM managed_customer_lifecycle_receipts')->fetchColumn() === 1
        && managed_customer_operational($cyclePdo, 1, 11),
    'owned-disabled restoration without a v3 containment receipt changed',
);
lifecycle_sqlite_advance($cyclePdo, 4, 'dddddddd-dddd-4ddd-8ddd-dddddddddddd', 'active', str_repeat('d', 64));
lifecycle_check(
    !managed_customer_operational($cyclePdo, 1, 11),
    'a later active source version reused the earlier restoration receipt',
);
$cycleCandidate = lifecycle_candidate($cyclePdo);
$cycleBefore = lifecycle_surface_rows($cyclePdo);
$wrongCustomerEvidence = lifecycle_evidence_for(4, 'dddddddd-dddd-4ddd-8ddd-dddddddddddd');
$wrongCustomerEvidence['customer_id'] = '22222222-2222-4222-8222-222222222222';
foreach ([
    'stale v3 evidence cleared the v4 latch' => lifecycle_evidence_for(3, 'cccccccc-cccc-4ccc-8ccc-cccccccccccc'),
    'evidence for another event cleared the v4 latch' => lifecycle_evidence_for(4, 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee'),
    'evidence for another customer UUID cleared the v4 latch' => $wrongCustomerEvidence,
] as $label => $badEvidence) {
    lifecycle_refuses(
        ManagedCustomerLifecycleGateException::class,
        static fn() => managed_customer_lifecycle_restore(
            $cyclePdo,
            $cycleCandidate,
            $badEvidence,
            101,
            lifecycle_config(['restoration_enabled' => true]),
            lifecycle_report_config(),
        ),
        $label,
    );
}
lifecycle_check(
    lifecycle_surface_rows($cyclePdo) === $cycleBefore
        && (int)$cyclePdo->query('SELECT COUNT(*) FROM managed_customer_lifecycle_restore_receipts')->fetchColumn() === 1
        && !managed_customer_operational($cyclePdo, 1, 11),
    'refused v4 evidence wrote a surface or receipt or cleared the latch',
);
$cycleResults = managed_customer_lifecycle_run(
    $cyclePdo,
    lifecycle_config(['restoration_enabled' => true]),
    lifecycle_report_config(),
    [],
    static fn(string $customerId, array $config): array
        => lifecycle_evidence_for(4, 'dddddddd-dddd-4ddd-8ddd-dddddddddddd'),
);
$cycleReceipt = $cyclePdo->query(
    'SELECT * FROM managed_customer_lifecycle_restore_receipts WHERE source_version=4'
)->fetch(PDO::FETCH_ASSOC);
lifecycle_check(
    ($cycleResults[0]['action'] ?? null) === 'restored'
        && ($cycleResults[0]['portal_restored'] ?? null) === 0
        && ($cycleResults[0]['report_schedule_restored'] ?? null) === 0
        && lifecycle_surface_rows($cyclePdo) === $cycleBefore
        && is_array($cycleReceipt)
        && managed_customer_lifecycle_restore_receipt_valid($cycleReceipt)
        && $cycleReceipt['portal_owner_receipt_id'] === null
        && $cycleReceipt['schedule_owner_receipt_id'] === null
        && (int)$cycleReceipt['portal_before_event_id'] === 4
        && (int)$cycleReceipt['schedule_before_version_id'] === 805
        && (int)$cyclePdo->query('SELECT COUNT(*) FROM managed_customer_lifecycle_restore_receipts')->fetchColumn() === 2
        && managed_customer_operational($cyclePdo, 1, 11),
    'a later exact restore over the earlier owned cycle did not preserve active surfaces with zero writes',
);
$cycleReplayEvidence = lifecycle_evidence_for(4, 'dddddddd-dddd-4ddd-8ddd-dddddddddddd');
$cycleReplayEvidence['request_nonce_sha256'] = str_repeat('7', 64);
$cycleReplayEvidence['response_sha256'] = str_repeat('8', 64);
$cycleReplay = managed_customer_lifecycle_restore(
    $cyclePdo,
    $cycleCandidate,
    $cycleReplayEvidence,
    101,
    lifecycle_config(['restoration_enabled' => true]),
    lifecycle_report_config(),
);
lifecycle_check(
    $cycleReplay['action'] === 'restore_replayed'
        && $cycleReplay['evidence_sha256'] === (string)$cycleReceipt['evidence_sha256']
        && lifecycle_surface_rows($cyclePdo) === $cycleBefore
        && (int)$cyclePdo->query('SELECT COUNT(*) FROM managed_customer_lifecycle_restore_receipts')->fetchColumn() === 2,
    'preserved-active replay wrote again or changed the committed receipt',
);

lifecycle_refuses(
    ManagedCustomerLifecycleGateException::class,
    static fn() => managed_customer_lifecycle_write(
        $reactivationPdo,
        "UPDATE tickets SET marker='changed' WHERE id=1",
        [],
    ),
    'exact write-table allowlist permitted a ticket mutation',
);

$starvationPdo = lifecycle_sqlite();
$blockedCustomer = '00000000-0000-4000-8000-000000000001';
$blockedEvent = '00000000-0000-4000-8000-000000000002';
$starvationPdo->exec("INSERT INTO tenants VALUES (2,'Provider Two','provider-two')");
$starvationPdo->exec("INSERT INTO clients VALUES (21,2,'Blocked First')");
$blockedBinding = $starvationPdo->prepare(
    "INSERT INTO suite_customer_sync_bindings
        (id,tenant_id,customer_id,client_id,source_version,display_name,status,
         last_event_id,last_request_sha256)
     VALUES (401,2,?,21,1,'Blocked First','inactive',?,?)"
);
$blockedBinding->execute([$blockedCustomer, $blockedEvent, str_repeat('d', 64)]);
$blockedReceipt = $starvationPdo->prepare(
    "INSERT INTO suite_customer_sync_events
        (event_id,tenant_id,binding_id,customer_id,client_id,source_version,
         display_name,status,occurred_at,request_sha256)
     VALUES (?,2,401,?,21,1,'Blocked First','inactive','2026-08-30 16:00:00',?)"
);
$blockedReceipt->execute([$blockedEvent, $blockedCustomer, str_repeat('d', 64)]);
$starvationResults = managed_customer_lifecycle_run(
    $starvationPdo,
    lifecycle_config([
        'customer_ids' => [
            $blockedCustomer,
            '11111111-1111-4111-8111-111111111111',
        ],
        'batch_size' => 1,
    ]),
);
lifecycle_check(
    count($starvationResults) === 2
        && ($starvationResults[0]['action'] ?? null) === 'refused'
        && ($starvationResults[1]['action'] ?? null) === 'contained'
        && (string)$starvationPdo->query(
            'SELECT status FROM customer_portal_bindings WHERE tenant_id=1 AND client_id=11'
        )->fetchColumn() === 'disabled',
    'an earlier refused customer starved a later allowlisted containment',
);

// Schema-3 identity-only restoration (A88/A95/A97) on the 034 shape. It clears the identity latch,
// never writes a report/contact/schedule row and never borrows the legacy contact digest.
$identityConfig = lifecycle_config(['restoration_enabled' => true]);
lifecycle_check(
    !managed_customer_lifecycle_identity_restore_available(lifecycle_sqlite())
        && managed_customer_lifecycle_identity_restore_available(lifecycle_identity_sqlite()),
    'the migration 034 capability check is not exact',
);
lifecycle_check(
    managed_customer_lifecycle_house_issuer(['8west' => 'ewid-t70']) === 70
        && managed_customer_lifecycle_house_issuer(['8west' => 'ewid-t0']) === null
        && managed_customer_lifecycle_house_issuer(['8west' => 70]) === null
        && managed_customer_lifecycle_house_issuer(['other' => 'ewid-t70']) === null
        && managed_customer_lifecycle_house_issuer(null) === null,
    'the house issuer is not exactly the nonsecret 8west slug mapping',
);

// Direct current restored v3 (Lifestyle-like) with preserved active portal and report: zero writes.
$identityPdo = lifecycle_identity_sqlite();
$identityBefore = lifecycle_surface_rows($identityPdo);
$identityCandidate = lifecycle_candidate($identityPdo);
$identityResult = managed_customer_lifecycle_identity_restore(
    $identityPdo, $identityCandidate, lifecycle_identity_proof(), 101, $identityConfig,
);
$identityReceipt = $identityPdo->query('SELECT * FROM managed_customer_lifecycle_restore_receipts')->fetch(PDO::FETCH_ASSOC);
$identitySchedule = $identityPdo->query('SELECT * FROM business_report_schedule_versions WHERE id=802')->fetch(PDO::FETCH_ASSOC);
lifecycle_check(
    $identityResult['action'] === 'restored'
        && $identityResult['proof_scope'] === 'identity_lifecycle_only'
        && $identityResult['portal_restored'] === 0
        && $identityResult['report_schedule_restored'] === 0
        && lifecycle_surface_rows($identityPdo) === $identityBefore
        && managed_customer_operational($identityPdo, 1, 11),
    'an exact identity-only proof did not clear the latch with zero surface writes',
);
lifecycle_check(
    is_array($identityReceipt)
        && managed_customer_lifecycle_identity_restore_receipt_valid($identityReceipt)
        && !managed_customer_lifecycle_restore_receipt_valid($identityReceipt)
        && (string)$identityReceipt['id_proof_scope'] === 'identity_lifecycle_only'
        && $identityReceipt['id_tenant_key'] === null
        && $identityReceipt['contact_version'] === null
        && $identityReceipt['recipient_sha256'] === null
        && (string)$identityReceipt['id_projection_tenant_key'] === 'ewid-t91'
        && (string)$identityReceipt['id_provider_tenant_slug'] === 'provider-one'
        && (int)$identityReceipt['id_issuer_suite_tenant_id'] === 44
        && (string)$identityReceipt['id_initial_receipt_id'] === str_repeat('e', 64)
        && (int)$identityReceipt['id_restore_anchor_source_version'] === 3
        && (string)$identityReceipt['id_restore_anchor_event_id'] === 'cccccccc-cccc-4ccc-8ccc-cccccccccccc'
        && (int)$identityReceipt['schedule_before_version_id'] === 802
        && (string)$identityReceipt['schedule_state_sha256'] === managed_customer_lifecycle_schedule_sha256($identitySchedule)
        && (int)$identityReceipt['schedule_restored'] === 0
        && $identityReceipt['schedule_owner_receipt_id'] === null
        && $identityReceipt['schedule_prepared_version_id'] === null
        && $identityReceipt['contact_snapshot_id'] === null
        && $identityReceipt['schedule_active_version_id'] === null,
    'the identity-only receipt did not record the exact signed identity, anchor and actual schedule state without contact facts',
);
lifecycle_refuses(
    ManagedCustomerLifecycleValidationException::class,
    static fn() => managed_customer_lifecycle_identity_restore_receipt_sha256(
        array_replace($identityReceipt, ['id_projection_tenant_key' => null]),
    ),
    'a NULL identity fact produced an identity-only digest',
);
$identityReplayProof = lifecycle_identity_proof();
$identityReplayProof['request_nonce_sha256'] = str_repeat('5', 64);
$identityReplayProof['response_sha256'] = str_repeat('6', 64);
$identityReplay = managed_customer_lifecycle_identity_restore(
    $identityPdo, $identityCandidate, $identityReplayProof, 101, $identityConfig,
);
lifecycle_check(
    $identityReplay['action'] === 'restore_replayed'
        && $identityReplay['evidence_sha256'] === (string)$identityReceipt['evidence_sha256']
        && lifecycle_restore_count($identityPdo) === 1
        && lifecycle_surface_rows($identityPdo) === $identityBefore,
    'identity-only replay wrote again or changed the committed receipt',
);
$changedReplay = lifecycle_identity_proof();
$changedReplay['projection_binding_sha256'] = str_repeat('0', 64);
lifecycle_refuses(
    ManagedCustomerLifecycleConflictException::class,
    static fn() => managed_customer_lifecycle_identity_restore(
        $identityPdo, $identityCandidate, $changedReplay, 101, $identityConfig,
    ),
    'a changed signed projection replayed as the committed identity-only restore',
);
lifecycle_refuses(
    ManagedCustomerLifecycleConflictException::class,
    static fn() => managed_customer_lifecycle_restore(
        $identityPdo, $identityCandidate, lifecycle_base_evidence(), 101, $identityConfig, lifecycle_report_config(),
    ),
    'a schema-2 contact restore replayed over a committed identity-only receipt',
);
lifecycle_refuses(
    ManagedCustomerLifecycleValidationException::class,
    static fn() => managed_customer_lifecycle_identity_restore(
        lifecycle_identity_sqlite(), $identityCandidate, lifecycle_base_evidence(), 101, $identityConfig,
    ),
    'schema-2 contact evidence was accepted as an identity-only proof',
);

// The queued restore→rename before the first SH tick (A92/A95): current v4 observed_active with the
// actual restored anchor v3 clears the latch; a later active rename v5 reuses the same anchor.
$queuedPdo = lifecycle_identity_sqlite();
lifecycle_sqlite_advance($queuedPdo, 4, 'dddddddd-dddd-4ddd-8ddd-dddddddddddd', 'active', str_repeat('d', 64));
$queuedBefore = lifecycle_surface_rows($queuedPdo);
$queued = managed_customer_lifecycle_identity_restore(
    $queuedPdo, lifecycle_candidate($queuedPdo),
    lifecycle_identity_proof(4, 'dddddddd-dddd-4ddd-8ddd-dddddddddddd', 'observed_active'), 101, $identityConfig,
);
$queuedReceipt = $queuedPdo->query('SELECT * FROM managed_customer_lifecycle_restore_receipts')->fetch(PDO::FETCH_ASSOC);
lifecycle_check(
    $queued['action'] === 'restored'
        && (int)$queuedReceipt['source_version'] === 4
        && (string)$queuedReceipt['id_lifecycle_action'] === 'observed_active'
        && (int)$queuedReceipt['id_restore_anchor_source_version'] === 3
        && lifecycle_surface_rows($queuedPdo) === $queuedBefore
        && managed_customer_operational($queuedPdo, 1, 11),
    'a queued restore→rename with the actual restored anchor did not clear the latch with zero writes',
);
lifecycle_sqlite_advance($queuedPdo, 5, 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', 'active', str_repeat('e', 64));
$renamedAgain = managed_customer_lifecycle_identity_restore(
    $queuedPdo, lifecycle_candidate($queuedPdo),
    lifecycle_identity_proof(5, 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', 'observed_active'), 101, $identityConfig,
);
lifecycle_check(
    $renamedAgain['action'] === 'restored' && lifecycle_restore_count($queuedPdo) === 2
        && lifecycle_surface_rows($queuedPdo) === $queuedBefore
        && managed_customer_operational($queuedPdo, 1, 11),
    'a later active rename with the same anchor wrote a surface or did not clear its own latch',
);

// Missing, forged, foreign, gapped and stale proofs refuse with no write and the latch kept.
$refusePdo = lifecycle_identity_sqlite();
lifecycle_sqlite_advance($refusePdo, 4, 'dddddddd-dddd-4ddd-8ddd-dddddddddddd', 'active', str_repeat('d', 64));
$refuseCandidate = lifecycle_candidate($refusePdo);
$refuseBefore = lifecycle_surface_rows($refusePdo);
$v4 = static fn(): array => lifecycle_identity_proof(4, 'dddddddd-dddd-4ddd-8ddd-dddddddddddd', 'observed_active');
$noAnchor = $v4();
$noAnchor['restoration_anchor'] = null;
$forgedAnchor = $v4();
$forgedAnchor['restoration_anchor']['event_id'] = 'ffffffff-ffff-4fff-8fff-ffffffffffff';
$inactiveAnchor = $v4();
$inactiveAnchor['restoration_anchor'] = array_replace($inactiveAnchor['restoration_anchor'],
    ['source_version' => 2, 'event_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb']);
$laterAnchor = $v4();
$laterAnchor['restoration_anchor'] = array_replace($laterAnchor['restoration_anchor'],
    ['source_version' => 4, 'event_id' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd']);
$restoredOlder = lifecycle_identity_proof(4, 'dddddddd-dddd-4ddd-8ddd-dddddddddddd', 'restored');
$restoredOlder['restoration_anchor'] = $v4()['restoration_anchor'];
// A155: an observed_active anchor must be strictly earlier than the current transition too.
$sameTransition = $v4();
$sameTransition['restoration_anchor']['transition_id'] = 13;
$foreignProvider = $v4();
$foreignProvider['provider_tenant_slug'] = 'provider-two';
$foreignIssuer = $v4();
$foreignIssuer['issuer_suite_tenant_id'] = 45;
foreach ([
    'observed_active alone with no anchor cleared a post-inactive latch' => $noAnchor,
    'a forged anchor event cleared the latch' => $forgedAnchor,
    'an anchor at the inactive version cleared the latch' => $inactiveAnchor,
    'an anchor that is not directly after the latest inactive version cleared the latch' => $laterAnchor,
    'a current restored action with an older anchor cleared the latch' => $restoredOlder,
    'an observed_active anchor at the current transition cleared the latch' => $sameTransition,
    'another provider proof cleared the latch' => $foreignProvider,
    'another issuer proof cleared the latch' => $foreignIssuer,
    'a stale v3 proof cleared the v4 latch' => lifecycle_identity_proof(),
] as $label => $badProof) {
    lifecycle_refuses(
        ManagedCustomerLifecycleGateException::class,
        static fn() => managed_customer_lifecycle_identity_restore(
            $refusePdo, $refuseCandidate, $badProof, 101, $identityConfig,
        ),
        $label,
    );
}
lifecycle_check(
    lifecycle_surface_rows($refusePdo) === $refuseBefore && lifecycle_restore_count($refusePdo) === 0
        && !managed_customer_operational($refusePdo, 1, 11),
    'a refused identity-only proof wrote a surface or receipt or cleared the latch',
);
// A155: a direct current restore names the same signed event in all five anchor facts.
$consistencyPdo = lifecycle_identity_sqlite();
$consistencyBefore = lifecycle_surface_rows($consistencyPdo);
foreach (['receipt_id' => str_repeat('a', 64), 'transition_id' => 11, 'evidence_sha256' => str_repeat('d', 64)] as $field => $other) {
    $mismatched = lifecycle_identity_proof();
    $mismatched['restoration_anchor'][$field] = $other;
    lifecycle_refuses(
        ManagedCustomerLifecycleGateException::class,
        static fn() => managed_customer_lifecycle_identity_restore(
            $consistencyPdo, $identityCandidate, $mismatched, 101, $identityConfig,
        ),
        "a direct restored anchor whose {$field} is not the current signed fact was accepted",
    );
}
lifecycle_check(
    lifecycle_surface_rows($consistencyPdo) === $consistencyBefore && lifecycle_restore_count($consistencyPdo) === 0,
    'an inconsistent direct anchor wrote a surface or receipt',
);
$newerPdo = lifecycle_identity_sqlite();
lifecycle_sqlite_advance($newerPdo, 4, 'dddddddd-dddd-4ddd-8ddd-dddddddddddd', 'inactive', str_repeat('d', 64));
lifecycle_sqlite_advance($newerPdo, 5, 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', 'active', str_repeat('e', 64));
lifecycle_refuses(
    ManagedCustomerLifecycleGateException::class,
    static fn() => managed_customer_lifecycle_identity_restore(
        $newerPdo, lifecycle_candidate($newerPdo),
        lifecycle_identity_proof(5, 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', 'observed_active'), 101, $identityConfig,
    ),
    'a newer inactive version did not invalidate the older anchor',
);
$newerDirect = lifecycle_identity_proof(5, 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', 'restored');
$newerDirect['restoration_anchor'] = array_replace($newerDirect['restoration_anchor'],
    ['source_version' => 5, 'event_id' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee']);
lifecycle_check(
    managed_customer_lifecycle_identity_restore(
        $newerPdo, lifecycle_candidate($newerPdo), $newerDirect, 101, $identityConfig,
    )['action'] === 'restored' && managed_customer_operational($newerPdo, 1, 11),
    'the actual new direct restored anchor after the newer inactive version was refused',
);
$gapPdo = lifecycle_identity_sqlite();
$gapPdo->exec('UPDATE suite_customer_sync_events SET source_version=9 WHERE source_version=1');
lifecycle_refuses(
    ManagedCustomerLifecycleConflictException::class,
    static fn() => managed_customer_lifecycle_identity_restore(
        $gapPdo, $identityCandidate, lifecycle_identity_proof(), 101, $identityConfig,
    ),
    'a gapped local history was accepted for an identity-only restore',
);
// A151: an approved legacy lineage (Lifestyle-shaped: frozen receipts with no admission tag, no local
// report-ID map) restores by the original approved scope alone, with zero report, contact, schedule
// or person writes and no retagging. The same unmarked history outside the approved scope refuses,
// directly or through a scheduled action that must not fabricate scope around the claimed UUID.
$legacyPdo = lifecycle_identity_sqlite();
$legacyPdo->exec('UPDATE suite_customer_sync_events SET admission=NULL');
$legacyPdo->exec('DELETE FROM business_report_id_client_bindings');
$legacyState = static fn(PDO $pdo): array => [
    lifecycle_surface_rows($pdo),
    $pdo->query('SELECT * FROM users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
    $pdo->query('SELECT * FROM business_report_id_client_bindings')->fetchAll(PDO::FETCH_ASSOC),
    $pdo->query('SELECT id, admission FROM suite_customer_sync_events ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
];
$legacyBefore = $legacyState($legacyPdo);
$legacyRestore = managed_customer_lifecycle_identity_restore(
    $legacyPdo, $identityCandidate, lifecycle_identity_proof(), 101, $identityConfig,
);
$legacyReceipt = $legacyPdo->query('SELECT * FROM managed_customer_lifecycle_restore_receipts')->fetch(PDO::FETCH_ASSOC);
lifecycle_check(
    $legacyRestore['action'] === 'restored' && $legacyRestore['portal_restored'] === 0
        && $legacyRestore['report_schedule_restored'] === 0
        && $legacyState($legacyPdo) === $legacyBefore
        && is_array($legacyReceipt) && managed_customer_lifecycle_identity_restore_receipt_valid($legacyReceipt)
        && managed_customer_operational($legacyPdo, 1, 11),
    'an approved unmarked legacy lineage without a report map was not restored identity-only with zero writes',
);
$unapprovedPdo = lifecycle_identity_sqlite();
$unapprovedPdo->exec('UPDATE suite_customer_sync_events SET admission=NULL');
$otherScope = lifecycle_config(['restoration_enabled' => true, 'customer_ids' => ['22222222-2222-4222-8222-222222222222']]);
lifecycle_refuses(
    ManagedCustomerLifecycleGateException::class,
    static fn() => managed_customer_lifecycle_identity_restore(
        $unapprovedPdo, $identityCandidate, lifecycle_identity_proof(), 101, $otherScope,
    ),
    'an unmarked lineage outside the approved scope used the identity-only restore',
);
lifecycle_refuses(
    ManagedCustomerLifecycleGateException::class,
    static fn() => managed_customer_lifecycle_scheduled_act(
        $unapprovedPdo, managed_customer_lifecycle_config($otherScope), $identityCandidate,
        static fn(): bool => true, static fn(): float => 20.0, [],
        static fn(string $customerId, array $idConfig): array => lifecycle_identity_proof(),
    ),
    'the scheduled action fabricated approved scope around an unmarked claimed UUID',
);
$withdrawnPdo = lifecycle_identity_sqlite();
$withdrawnPdo->exec("UPDATE suite_managed_providers SET lease_until='2000-01-01 00:00:00'");
lifecycle_refuses(
    ManagedCustomerLifecycleGateException::class,
    static fn() => managed_customer_lifecycle_identity_restore(
        $withdrawnPdo, $identityCandidate, lifecycle_identity_proof(), 101, $identityConfig,
    ),
    'a withdrawn provider restored by identity-only proof',
);
lifecycle_check(
    lifecycle_restore_count($gapPdo) === 0 && lifecycle_restore_count($unapprovedPdo) === 0
        && lifecycle_restore_count($withdrawnPdo) === 0,
    'a refused identity-only authority check left a receipt',
);
$houseProof = lifecycle_identity_proof();
$houseProof['provider_tenant_slug'] = '8west';
lifecycle_refuses(
    ManagedCustomerLifecycleGateException::class,
    static fn() => managed_customer_lifecycle_identity_restore(
        lifecycle_identity_sqlite(), array_replace($identityCandidate, ['provider_tenant_slug' => '8west']), $houseProof, 101,
        lifecycle_config(['restoration_enabled' => true, 'tenant_actors' => ['8west' => 101]]), ['8west' => 'ewid-t45'],
    ),
    'a house proof whose issuer differs from the nonsecret slug mapping was accepted',
);

// An existing report-ID map must match the signed key; an absent map uses the signed projection key.
$mapPdo = lifecycle_identity_sqlite();
$otherKey = lifecycle_identity_proof();
$otherKey['tenant_key'] = 'ewid-t92';
lifecycle_refuses(
    ManagedCustomerLifecycleConflictException::class,
    static fn() => managed_customer_lifecycle_identity_restore(
        $mapPdo, $identityCandidate, $otherKey, 101, $identityConfig,
    ),
    'an existing report-ID map that differs from the signed key was ignored',
);
$mapPdo->exec('DELETE FROM business_report_id_client_bindings');
$absentMap = managed_customer_lifecycle_identity_restore($mapPdo, $identityCandidate, $otherKey, 101, $identityConfig);
lifecycle_check(
    $absentMap['action'] === 'restored'
        && (string)$mapPdo->query('SELECT id_projection_tenant_key FROM managed_customer_lifecycle_restore_receipts')->fetchColumn() === 'ewid-t92'
        && (int)$mapPdo->query('SELECT COUNT(*) FROM business_report_id_client_bindings')->fetchColumn() === 0,
    'an absent report-ID map was invented or blocked the signed identity-only restore',
);

// An owned-disabled report never blocks identity and is never released; the owned portal is restored.
$ownedPdo = lifecycle_identity_sqlite('inactive', true, true);
managed_customer_lifecycle_apply($ownedPdo, lifecycle_candidate($ownedPdo), 101, lifecycle_config());
lifecycle_sqlite_advance($ownedPdo, 3, 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'active', str_repeat('c', 64));
$ownedReports = [
    $ownedPdo->query('SELECT * FROM business_report_schedule_versions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
    $ownedPdo->query('SELECT * FROM business_report_id_client_contact_snapshots ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
];
$deferredBefore = lifecycle_surface_rows($ownedPdo);
$guardStages = [];
// A159: an ordinary outer closure captures $guardStages BY REFERENCE; an arrow function would hand the
// inner guard a private by-value copy and leave this observation empty.
lifecycle_refuses(
    RuntimeException::class,
    static function () use ($ownedPdo, $identityConfig, &$guardStages) {
        return managed_customer_lifecycle_identity_restore(
            $ownedPdo, lifecycle_candidate($ownedPdo), lifecycle_identity_proof(), 101, $identityConfig, null, null,
            static function (string $stage) use (&$guardStages): void {
                $guardStages[] = $stage;
                if ($stage === 'write') throw new RuntimeException('fixture deadline before the first write');
            },
        );
    },
    'the write-stage guard did not stop the identity-only restore',
);
$writeStageFailures = array_keys(array_filter([
    'guard stages were ' . json_encode($guardStages) => $guardStages !== ['authority', 'write'],
    'surface rows changed' => lifecycle_surface_rows($ownedPdo) !== $deferredBefore,
    'a restore receipt was written' => lifecycle_restore_count($ownedPdo) !== 0,
    'the customer became operational' => managed_customer_operational($ownedPdo, 1, 11),
]));
lifecycle_check(
    $writeStageFailures === [],
    'a stop at the write-stage guard wrote a portal, schedule or receipt'
        . ($writeStageFailures === [] ? '' : ' [' . implode('; ', $writeStageFailures) . ']'),
);
$owned = managed_customer_lifecycle_identity_restore(
    $ownedPdo, lifecycle_candidate($ownedPdo), lifecycle_identity_proof(), 101, $identityConfig,
);
$ownedReceipt = $ownedPdo->query('SELECT * FROM managed_customer_lifecycle_restore_receipts')->fetch(PDO::FETCH_ASSOC);
lifecycle_check(
    $owned['portal_restored'] === 1 && $owned['report_schedule_restored'] === 0
        && (string)$ownedPdo->query('SELECT status FROM customer_portal_bindings WHERE id=601')->fetchColumn() === 'active'
        && $ownedReports === [
            $ownedPdo->query('SELECT * FROM business_report_schedule_versions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
            $ownedPdo->query('SELECT * FROM business_report_id_client_contact_snapshots ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        ]
        && (int)$ownedReceipt['schedule_before_version_id'] === 803
        && $ownedReceipt['schedule_owner_receipt_id'] === null
        && (int)$ownedReceipt['portal_owner_receipt_id'] > 0
        && managed_customer_lifecycle_identity_restore_receipt_valid($ownedReceipt)
        && managed_customer_operational($ownedPdo, 1, 11),
    'identity-only restore was blocked by, or released, an owned-disabled report, or did not restore the owned portal',
);

// A141/A143 at action through the scheduled seam: a containment whose deadline has passed at the
// write stage defers with zero writes; an admitted provider withdrawn after the scheduled lookup
// refuses under the action locks; a current one contains as its owner.
$deadlinePdo = lifecycle_sqlite();
$deadlineBefore = lifecycle_surface_rows($deadlinePdo);
$deadlineResult = managed_customer_lifecycle_scheduled_act(
    $deadlinePdo, managed_customer_lifecycle_config(lifecycle_config()), lifecycle_candidate($deadlinePdo),
    static fn(): bool => true, static fn(): float => -1.0,
);
lifecycle_check(
    $deadlineResult['action'] === 'deferred' && $deadlineResult['provenance'] === 'explicit'
        && lifecycle_surface_rows($deadlinePdo) === $deadlineBefore,
    'a scheduled containment past its deadline at the write stage wrote a surface or receipt',
);
$admittedOnly = managed_customer_lifecycle_config(lifecycle_config(['customer_ids' => ['22222222-2222-4222-8222-222222222222']]));
$withdrawPdo = lifecycle_identity_sqlite('inactive', true, true);
$withdrawBefore = lifecycle_surface_rows($withdrawPdo);
lifecycle_refuses(
    ManagedCustomerLifecycleGateException::class,
    static fn() => managed_customer_lifecycle_scheduled_act(
        $withdrawPdo, $admittedOnly, lifecycle_candidate($withdrawPdo),
        static function () use ($withdrawPdo): bool {
            $withdrawPdo->exec("UPDATE suite_managed_providers SET lease_until='2000-01-01 00:00:00'");
            return true;
        },
        static fn(): float => 20.0,
    ),
    'an admitted provider withdrawn after the scheduled lookup was acted on',
);
lifecycle_check(lifecycle_surface_rows($withdrawPdo) === $withdrawBefore,
    'a refused admitted containment wrote a surface or receipt');
$currentPdo = lifecycle_identity_sqlite('inactive', true, true);
$currentResult = managed_customer_lifecycle_scheduled_act(
    $currentPdo, $admittedOnly, lifecycle_candidate($currentPdo), static fn(): bool => true, static fn(): float => 20.0,
);
lifecycle_check(
    $currentResult['action'] === 'contained' && $currentResult['provenance'] === 'admitted'
        && (int)$currentPdo->query('SELECT actor_user_id FROM managed_customer_lifecycle_receipts')->fetchColumn() === 101,
    'a current admitted provider lineage was not contained as its owner',
);
$unadmittedContain = lifecycle_identity_sqlite('inactive', true, true);
$unadmittedContain->exec('UPDATE suite_customer_sync_events SET admission=NULL');
lifecycle_refuses(
    ManagedCustomerLifecycleGateException::class,
    static fn() => managed_customer_lifecycle_scheduled_act(
        $unadmittedContain, $admittedOnly, lifecycle_candidate($unadmittedContain),
        static fn(): bool => true, static fn(): float => 20.0,
    ),
    'a scheduled admitted containment ran for a binding with no immutable admission',
);

$statusSource = file_get_contents(__DIR__ . '/../lib/managed_customer_status.php') ?: '';
$reportSource = file_get_contents(__DIR__ . '/../lib/business_reports.php') ?: '';
lifecycle_check(
    str_contains($statusSource, "inactive_event.status = 'inactive'")
        && substr_count($reportSource, 'business_report_assert_managed_customer_operational(') >= 6
        && str_contains($reportSource, 'final boundary before bytes leave Safeharbor')
        && str_contains($reportSource, "managed_customer_operational"),
    'portal/report immediate generation selection claim and pre-send boundaries are not wired',
);

if ($lifecycleFailures > 0) {
    fwrite(STDERR, "{$lifecycleFailures} of {$lifecycleChecks} managed-customer lifecycle checks failed.\n");
    exit(1);
}
echo "Managed-customer lifecycle: {$lifecycleChecks}/{$lifecycleChecks} passed.\n";
