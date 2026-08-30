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

function lifecycle_candidate(PDO $pdo): array
{
    $rows = managed_customer_lifecycle_candidates($pdo, lifecycle_config());
    if (count($rows) !== 1) throw new RuntimeException('lifecycle candidate fixture failed');
    return $rows[0];
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

$runRestorePdo = lifecycle_sqlite('active', true, true);
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
lifecycle_check(
    count($runRestoreResults) === 1
        && ($runRestoreResults[0]['action'] ?? null) === 'restored'
        && managed_customer_operational($runRestorePdo, 1, 11),
    'bounded worker did not contain then restored-only release an active-after-inactive customer',
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
