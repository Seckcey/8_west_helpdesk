<?php
/** Hermetic contract for atomic managed-customer activation. */
declare(strict_types=1);

require_once __DIR__ . '/../lib/managed_customer_activation.php';

$activationChecks = 0;
$activationFailures = 0;

function activation_check(bool $condition, string $message): void
{
    global $activationChecks, $activationFailures;
    $activationChecks++;
    if (!$condition) {
        $activationFailures++;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

/** @param class-string<Throwable> $class */
function activation_refuses(string $class, callable $operation, string $message): void
{
    try {
        $operation();
        activation_check(false, $message);
    } catch (Throwable $error) {
        activation_check($error instanceof $class, $message);
    }
}

function activation_sqlite(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    foreach ([
        'CREATE TABLE tenants (id INTEGER PRIMARY KEY, name TEXT NOT NULL, slug TEXT NOT NULL UNIQUE)',
        'CREATE TABLE clients (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name TEXT NOT NULL,
            UNIQUE(tenant_id,id))',
        'CREATE TABLE users (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, role TEXT NOT NULL,
            is_active INTEGER NOT NULL, UNIQUE(tenant_id,id))',
        'CREATE TABLE suite_customer_sync_bindings (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
            customer_id TEXT NOT NULL UNIQUE, client_id INTEGER NOT NULL,
            source_version INTEGER NOT NULL, display_name TEXT NOT NULL,
            status TEXT NOT NULL, UNIQUE(tenant_id,client_id), UNIQUE(tenant_id,id))',
        'CREATE TABLE customer_portal_bindings (
            id INTEGER PRIMARY KEY AUTOINCREMENT, identity_tenant_slug TEXT NOT NULL UNIQUE,
            tenant_id INTEGER NOT NULL, client_id INTEGER NOT NULL, status TEXT NOT NULL,
            prepared_by_user_id INTEGER NOT NULL, prepared_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_changed_by_user_id INTEGER NOT NULL,
            status_changed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            status_reason TEXT NOT NULL, UNIQUE(tenant_id,client_id), UNIQUE(tenant_id,client_id,id))',
        'CREATE TABLE business_report_definition_versions (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
            definition_key TEXT NOT NULL, version_no INTEGER NOT NULL,
            report_type TEXT NOT NULL, contract_json TEXT NOT NULL,
            contract_sha256 TEXT NOT NULL, created_by_user_id INTEGER NOT NULL,
            reason TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(tenant_id,definition_key,version_no), UNIQUE(tenant_id,id))',
        'CREATE TABLE business_report_schedule_versions (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
            schedule_key TEXT NOT NULL, version_no INTEGER NOT NULL,
            definition_version_id INTEGER NOT NULL, client_id INTEGER NOT NULL,
            recipient_email TEXT NOT NULL, schedule_timezone TEXT NOT NULL,
            delivery_weekday INTEGER NOT NULL, delivery_local_time TEXT NOT NULL,
            canary INTEGER NOT NULL, status TEXT NOT NULL, created_by_user_id INTEGER NOT NULL,
            reason TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(tenant_id,schedule_key,version_no), UNIQUE(tenant_id,id))',
        'CREATE TABLE business_report_contact_scope_bindings (
            tenant_id INTEGER NOT NULL, schedule_key TEXT NOT NULL, contact_scope TEXT NOT NULL,
            created_by_user_id INTEGER NOT NULL, reason TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(tenant_id,schedule_key))',
        'CREATE TABLE business_report_id_tenant_bindings (
            tenant_id INTEGER PRIMARY KEY, id_tenant_key TEXT NOT NULL UNIQUE,
            id_tenant_slug TEXT NOT NULL, created_by_user_id INTEGER NOT NULL,
            reason TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)',
        'CREATE TABLE business_report_id_contact_snapshots (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
            schedule_version_id INTEGER NOT NULL, id_tenant_key TEXT NOT NULL,
            contact_version INTEGER NOT NULL, recipient_email TEXT NOT NULL,
            response_generated_at TEXT NOT NULL, request_nonce_sha256 TEXT NOT NULL,
            response_sha256 TEXT NOT NULL, created_by_user_id INTEGER NOT NULL,
            reason TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)',
        'CREATE TABLE business_report_id_client_bindings (
            tenant_id INTEGER NOT NULL, client_id INTEGER NOT NULL,
            id_tenant_key TEXT NOT NULL UNIQUE, id_tenant_slug TEXT NOT NULL,
            created_by_user_id INTEGER NOT NULL, reason TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(tenant_id,client_id), UNIQUE(tenant_id,client_id,id_tenant_key))',
        'CREATE TABLE business_report_id_client_contact_snapshots (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
            client_id INTEGER NOT NULL, schedule_version_id INTEGER NOT NULL,
            id_tenant_key TEXT NOT NULL, contact_version INTEGER NOT NULL,
            recipient_email TEXT NOT NULL, response_generated_at TEXT NOT NULL,
            request_nonce_sha256 TEXT NOT NULL, response_sha256 TEXT NOT NULL,
            created_by_user_id INTEGER NOT NULL, reason TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(tenant_id,schedule_version_id))',
        'CREATE TABLE managed_customer_activation_receipts (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
            client_id INTEGER NOT NULL, source_binding_id INTEGER NOT NULL,
            customer_id TEXT NOT NULL UNIQUE, source_version INTEGER NOT NULL,
            customer_receipt_id TEXT NOT NULL, id_tenant_key TEXT NOT NULL,
            identity_tenant_slug TEXT NOT NULL, contact_version INTEGER NOT NULL,
            portal_binding_id INTEGER NOT NULL, schedule_key TEXT NOT NULL,
            prepared_schedule_version_id INTEGER NOT NULL,
            active_schedule_version_id INTEGER NOT NULL, actor_user_id INTEGER NOT NULL,
            id_response_sha256 TEXT NOT NULL, recipient_sha256 TEXT NOT NULL,
            evidence_sha256 TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(tenant_id,client_id), UNIQUE(tenant_id,schedule_key))',
        'CREATE TABLE tickets (id INTEGER PRIMARY KEY, marker TEXT NOT NULL)',
        'CREATE TABLE time_entries (id INTEGER PRIMARY KEY, marker TEXT NOT NULL)',
        'CREATE TABLE coastmark_export_sentinel (id INTEGER PRIMARY KEY, marker TEXT NOT NULL)',
        'CREATE TABLE endpoint_control_sentinel (id INTEGER PRIMARY KEY, marker TEXT NOT NULL)',
        'CREATE TABLE ai_write_sentinel (id INTEGER PRIMARY KEY, marker TEXT NOT NULL)',
    ] as $sql) {
        $pdo->exec($sql);
    }
    $pdo->exec("INSERT INTO tenants (id,name,slug) VALUES
        (1,'Provider One','provider-one'),(2,'Provider Two','provider-two')");
    $pdo->exec("INSERT INTO clients (id,tenant_id,name) VALUES
        (11,1,'Managed One'),(12,1,'Other Client'),(21,2,'Cross Tenant')");
    $pdo->exec("INSERT INTO users (id,tenant_id,role,is_active) VALUES
        (101,1,'owner',1),(102,1,'admin',1),(103,1,'tech',1),(201,2,'owner',1)");
    $pdo->exec("INSERT INTO suite_customer_sync_bindings
        (id,tenant_id,customer_id,client_id,source_version,display_name,status) VALUES
        (501,1,'11111111-1111-4111-8111-111111111111',11,1,'Managed One','active')");
    $definition = $pdo->prepare(
        'INSERT INTO business_report_definition_versions
            (id,tenant_id,definition_key,version_no,report_type,contract_json,
             contract_sha256,created_by_user_id,reason) VALUES (?,?,?,?,?,?,?,?,?)'
    );
    $definition->execute([
        701, 1, BUSINESS_REPORT_DEFINITION_KEY, 1, BUSINESS_REPORT_TYPE,
        business_report_contract_json(1), business_report_contract_sha256(1), 101, 'v1',
    ]);
    $definition->execute([
        702, 1, BUSINESS_REPORT_DEFINITION_KEY, 2, BUSINESS_REPORT_TYPE,
        business_report_contract_json(2), business_report_contract_sha256(2), 101, 'v2',
    ]);
    $definition->execute([
        703, 1, BUSINESS_REPORT_DEFINITION_KEY, 3, BUSINESS_REPORT_TYPE,
        business_report_contract_json(3), business_report_contract_sha256(3), 101, 'v3',
    ]);
    foreach (['tickets','time_entries','coastmark_export_sentinel',
              'endpoint_control_sentinel','ai_write_sentinel'] as $table) {
        $pdo->exec("INSERT INTO {$table} (id,marker) VALUES (1,'unchanged')");
    }
    return $pdo;
}

/** @return array<string,mixed> */
function activation_config(array $changes = []): array
{
    return array_replace([
        'enabled' => true,
        'canary_only' => true,
        'customer_ids' => ['11111111-1111-4111-8111-111111111111'],
        'tenant_actors' => ['provider-one' => 101],
        'batch_size' => 5,
        'schedule_timezone' => 'America/Los_Angeles',
        'delivery_weekday' => 3,
        'delivery_local_time' => '09:00:00',
    ], $changes);
}

/** @return array<string,mixed> */
function activation_evidence(array $changes = []): array
{
    return array_replace([
        'schema_version' => 2,
        'customer_id' => '11111111-1111-4111-8111-111111111111',
        'source_version' => 1,
        'customer_receipt_id' => str_repeat('9', 64),
        'tenant_key' => 'ewid-t91',
        'tenant_slug' => 'managed-one',
        'contact_version' => 3,
        'recipient_email' => 'reports@managed-one.example',
        'generated_at_db' => '2026-08-30 16:00:00',
        'request_nonce_sha256' => str_repeat('a', 64),
        'response_sha256' => str_repeat('b', 64),
    ], $changes);
}

/** @return array<string,mixed> */
function activation_report_config(): array
{
    return [
        'generation_enabled' => false,
        'delivery_enabled' => false,
        'canary_only' => true,
        'graph_sender' => '',
        'schedule_keys' => ['managed-weekly-v3:11111111-1111-4111-8111-111111111111'],
        'tenant_slugs' => ['provider-one'],
        'client_keys' => ['safeharbor-client:11'],
        'recipient_emails' => ['reports@managed-one.example'],
        'lease_seconds' => 120,
    ];
}

/** @return array<string,mixed> */
function activation_candidate(PDO $pdo): array
{
    $rows = managed_customer_activation_candidates($pdo, activation_config());
    if (count($rows) !== 1) throw new RuntimeException('candidate fixture failed');
    return $rows[0];
}

$defaults = managed_customer_activation_config([]);
activation_check($defaults['enabled'] === false, 'sample-less activation is not default-off');
activation_check($defaults['canary_only'] === true, 'default activation is not canary-only');
activation_check(
    managed_customer_activation_schedule_key('11111111-1111-4111-8111-111111111111')
        === 'managed-weekly-v3:11111111-1111-4111-8111-111111111111',
    'deterministic schedule key changed',
);
activation_refuses(
    ManagedCustomerActivationValidationException::class,
    static fn() => managed_customer_activation_schedule_key(BUSINESS_REPORT_MASTER_CUSTOMER_ID),
    'master customer UUID was accepted',
);
activation_refuses(
    ManagedCustomerActivationValidationException::class,
    static fn() => managed_customer_activation_config([
        'enabled' => true, 'canary_only' => false,
        'customer_ids' => ['11111111-1111-4111-8111-111111111111'],
        'tenant_actors' => ['provider-one' => 101],
    ]),
    'enabled non-canary activation was accepted',
);
activation_refuses(
    ManagedCustomerActivationValidationException::class,
    static fn() => managed_customer_activation_evidence(
        activation_evidence(['source_version' => 0]),
    ),
    'invalid projected source version was accepted',
);
activation_refuses(
    ManagedCustomerActivationValidationException::class,
    static fn() => managed_customer_activation_evidence(
        activation_evidence(['tenant_slug' => '8west']),
    ),
    'reserved master portal slug was accepted for a managed customer',
);

$guardPdo = activation_sqlite();
activation_refuses(
    ManagedCustomerActivationGateException::class,
    static fn() => managed_customer_activation_write(
        $guardPdo,
        "UPDATE tickets SET marker = 'changed' WHERE id = 1",
        [],
    ),
    'ticket write escaped the exact write-table allowlist',
);

$queuePdo = activation_sqlite();
$queuePdo->exec("INSERT INTO clients (id,tenant_id,name) VALUES
    (13,1,'Managed Three'),(14,1,'Managed Four')");
$queuePdo->exec("INSERT INTO suite_customer_sync_bindings
    (id,tenant_id,customer_id,client_id,source_version,display_name,status) VALUES
    (502,1,'22222222-2222-4222-8222-222222222222',12,1,'Managed Two','active'),
    (503,1,'33333333-3333-4333-8333-333333333333',13,1,'Managed Three','active'),
    (504,1,'44444444-4444-4444-8444-444444444444',14,1,'Managed Four','active')");
$queueConfig = activation_config([
    'customer_ids' => [
        '11111111-1111-4111-8111-111111111111',
        '22222222-2222-4222-8222-222222222222',
        '33333333-3333-4333-8333-333333333333',
        '44444444-4444-4444-8444-444444444444',
    ],
    'batch_size' => 2,
]);
$firstBatch = managed_customer_activation_candidates($queuePdo, $queueConfig);
activation_check(
    array_column($firstBatch, 'customer_id') === [
        '11111111-1111-4111-8111-111111111111',
        '22222222-2222-4222-8222-222222222222',
    ],
    'activation queue did not select the deterministic first batch',
);
$queueReceipt = $queuePdo->prepare(
    'INSERT INTO managed_customer_activation_receipts
        (tenant_id,client_id,source_binding_id,customer_id,source_version,
         customer_receipt_id,id_tenant_key,identity_tenant_slug,contact_version,
         portal_binding_id,schedule_key,prepared_schedule_version_id,
         active_schedule_version_id,actor_user_id,id_response_sha256,
         recipient_sha256,evidence_sha256)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
);
foreach ($firstBatch as $index => $candidate) {
    $queueReceipt->execute([
        1, (int)$candidate['client_id'], (int)$candidate['binding_id'],
        (string)$candidate['customer_id'], 1, str_repeat((string)($index + 1), 64),
        'ewid-t' . (91 + $index), 'managed-' . ($index + 1), 1,
        900 + $index, managed_customer_activation_schedule_key((string)$candidate['customer_id']),
        700 + ($index * 2), 701 + ($index * 2), 101,
        str_repeat('a', 64), str_repeat('b', 64), str_repeat('c', 64),
    ]);
}
$secondBatch = managed_customer_activation_candidates($queuePdo, $queueConfig);
activation_check(
    array_column($secondBatch, 'customer_id') === [
        '33333333-3333-4333-8333-333333333333',
        '44444444-4444-4444-8444-444444444444',
    ],
    'completed first-batch customers starved later activation candidates',
);

$pdo = activation_sqlite();
$sentinelBefore = [];
foreach (['tickets','time_entries','coastmark_export_sentinel',
          'endpoint_control_sentinel','ai_write_sentinel'] as $table) {
    $sentinelBefore[$table] = $pdo->query("SELECT * FROM {$table}")->fetchAll(PDO::FETCH_ASSOC);
}
$initialCandidate = activation_candidate($pdo);
$result = managed_customer_activation_apply(
    $pdo,
    $initialCandidate,
    activation_evidence(),
    101,
    activation_config(),
    activation_report_config(),
);
activation_check($result['action'] === 'activated', 'exact customer was not activated');
activation_check(
    (int)$pdo->query("SELECT COUNT(*) FROM customer_portal_bindings WHERE status='active'")->fetchColumn() === 1,
    'portal binding was not reconciled active',
);
activation_check(
    (int)$pdo->query('SELECT COUNT(*) FROM business_report_schedule_versions')->fetchColumn() === 2,
    'activation did not create exactly disabled then active schedule versions',
);
activation_check(
    (int)$pdo->query("SELECT COUNT(*) FROM business_report_schedule_versions
        WHERE status='active' AND schedule_timezone='America/Los_Angeles'
          AND delivery_weekday=3 AND delivery_local_time='09:00:00' AND canary=1")->fetchColumn() === 1,
    'weekly schedule default is not Wednesday 09:00 Pacific canary',
);
activation_check(
    (int)$pdo->query('SELECT COUNT(*) FROM managed_customer_activation_receipts')->fetchColumn() === 1,
    'durable activation receipt was not recorded',
);
activation_check(
    managed_customer_activation_candidates($pdo, activation_config()) === [],
    'completed activation remained in the worker candidate queue',
);
foreach ($sentinelBefore as $table => $rows) {
    activation_check(
        $pdo->query("SELECT * FROM {$table}")->fetchAll(PDO::FETCH_ASSOC) === $rows,
        "activation changed forbidden {$table} state",
    );
}

$replayEvidence = activation_evidence([
    'generated_at_db' => '2026-08-30 16:00:01',
    'request_nonce_sha256' => str_repeat('c', 64),
    'response_sha256' => str_repeat('d', 64),
]);
$replay = managed_customer_activation_apply(
    $pdo,
    $initialCandidate,
    $replayEvidence,
    101,
    activation_config(),
    activation_report_config(),
);
activation_check($replay['action'] === 'replayed', 'fresh authenticated exact replay was not idempotent');
activation_check(
    (int)$pdo->query('SELECT COUNT(*) FROM managed_customer_activation_receipts')->fetchColumn() === 1
        && (int)$pdo->query('SELECT COUNT(*) FROM business_report_schedule_versions')->fetchColumn() === 2,
    'exact replay duplicated receipt or schedule history',
);
activation_refuses(
    ManagedCustomerActivationConflictException::class,
    static fn() => managed_customer_activation_apply(
        $pdo,
        $initialCandidate,
        activation_evidence(['customer_receipt_id' => str_repeat('8', 64)]),
        101,
        activation_config(),
        activation_report_config(),
    ),
    'replay accepted a different ID customer receipt for the same source version',
);

$crashPdo = activation_sqlite();
activation_refuses(
    RuntimeException::class,
    static fn() => managed_customer_activation_apply(
        $crashPdo,
        activation_candidate($crashPdo),
        activation_evidence(),
        101,
        activation_config(),
        activation_report_config(),
        static function (string $stage): void {
            if ($stage === 'after_schedule_prepared') throw new RuntimeException('injected crash');
        },
    ),
    'injected partial crash did not escape',
);
activation_check(
    (int)$crashPdo->query('SELECT COUNT(*) FROM customer_portal_bindings')->fetchColumn() === 0
        && (int)$crashPdo->query('SELECT COUNT(*) FROM business_report_schedule_versions')->fetchColumn() === 0
        && (int)$crashPdo->query('SELECT COUNT(*) FROM managed_customer_activation_receipts')->fetchColumn() === 0,
    'partial crash committed portal, schedule, or receipt state',
);
$afterCrash = managed_customer_activation_apply(
    $crashPdo,
    activation_candidate($crashPdo),
    activation_evidence(),
    101,
    activation_config(),
    activation_report_config(),
);
activation_check($afterCrash['action'] === 'activated', 'crash replay did not complete activation');

$inactivePdo = activation_sqlite();
$inactiveCandidate = activation_candidate($inactivePdo);
$inactivePdo->exec("UPDATE suite_customer_sync_bindings SET status='inactive' WHERE id=501");
activation_refuses(
    ManagedCustomerActivationGateException::class,
    static fn() => managed_customer_activation_apply(
        $inactivePdo,
        $inactiveCandidate,
        activation_evidence(),
        101,
        activation_config(),
        activation_report_config(),
    ),
    'an inactive Milepost binding passed the under-lock recheck',
);

$conflictPdo = activation_sqlite();
$conflictPdo->exec("INSERT INTO customer_portal_bindings
    (identity_tenant_slug,tenant_id,client_id,status,prepared_by_user_id,
     last_changed_by_user_id,status_reason)
    VALUES ('different-identity',1,11,'active',101,101,'conflict')");
activation_refuses(
    ManagedCustomerActivationConflictException::class,
    static fn() => managed_customer_activation_apply(
        $conflictPdo,
        activation_candidate($conflictPdo),
        activation_evidence(),
        101,
        activation_config(),
        activation_report_config(),
    ),
    'conflicting portal binding was overwritten',
);

$crossTenantPdo = activation_sqlite();
$crossTenantPdo->exec("INSERT INTO business_report_id_client_bindings
    (tenant_id,client_id,id_tenant_key,id_tenant_slug,created_by_user_id,reason)
    VALUES (2,21,'ewid-t91','managed-one',201,'cross-tenant owner')");
activation_refuses(
    ManagedCustomerActivationConflictException::class,
    static fn() => managed_customer_activation_apply(
        $crossTenantPdo,
        activation_candidate($crossTenantPdo),
        activation_evidence(),
        101,
        activation_config(),
        activation_report_config(),
    ),
    'cross-tenant ID evidence was accepted',
);

$stalePdo = activation_sqlite();
$stalePdo->exec("INSERT INTO business_report_id_client_bindings
    (tenant_id,client_id,id_tenant_key,id_tenant_slug,created_by_user_id,reason)
    VALUES (1,11,'ewid-t91','managed-one',101,'existing')");
$stalePdo->exec("INSERT INTO business_report_schedule_versions
    (id,tenant_id,schedule_key,version_no,definition_version_id,client_id,
     recipient_email,schedule_timezone,delivery_weekday,delivery_local_time,
     canary,status,created_by_user_id,reason)
    VALUES (801,1,'prior-weekly',1,702,11,'newer@managed-one.example',
            'America/Los_Angeles',3,'09:00:00',1,'disabled',101,'prior')");
$stalePdo->exec("INSERT INTO business_report_id_client_contact_snapshots
    (tenant_id,client_id,schedule_version_id,id_tenant_key,contact_version,
     recipient_email,response_generated_at,request_nonce_sha256,response_sha256,
     created_by_user_id,reason)
    VALUES (1,11,801,'ewid-t91',4,'newer@managed-one.example','2026-08-30 15:00:00',
            '" . str_repeat('e', 64) . "','" . str_repeat('f', 64) . "',101,'prior')");
activation_refuses(
    ManagedCustomerActivationConflictException::class,
    static fn() => managed_customer_activation_apply(
        $stalePdo,
        activation_candidate($stalePdo),
        activation_evidence(),
        101,
        activation_config(),
        activation_report_config(),
    ),
    'stale contact version was accepted',
);

$source = (string)file_get_contents(__DIR__ . '/../lib/managed_customer_activation.php');
$cron = (string)file_get_contents(__DIR__ . '/../cron/managed_customer_activation.php');
$migration = (string)file_get_contents(__DIR__ . '/../db/migrations/022_managed_customer_activation.sql');
foreach (['tickets','time_entries','coastmark_time','mail_queue','westy_reports'] as $forbiddenTable) {
    activation_check(
        preg_match('/\b(?:INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+' . preg_quote($forbiddenTable, '/') . '\b/i', $source) !== 1,
        "worker contains forbidden {$forbiddenTable} DML",
    );
}
activation_check(!str_contains($cron, 'mailer'), 'activation cron loads a mail sender');
activation_check(!str_contains($cron, 'business_report_generate'), 'activation cron generates reports');
activation_check(!str_contains($cron, 'business_report_deliver'), 'activation cron delivers reports');
$receiptStart = strpos($migration, 'CREATE TABLE IF NOT EXISTS managed_customer_activation_receipts');
$receiptEnd = $receiptStart === false ? false : strpos($migration, ') ENGINE=InnoDB', $receiptStart);
$receiptTable = $receiptStart === false || $receiptEnd === false
    ? ''
    : substr($migration, $receiptStart, $receiptEnd - $receiptStart);
activation_check(
    str_contains($migration, 'managed customer activation receipts are immutable')
        && str_contains($migration, 'ck_mc_activation_install_lock CHECK (0 = 1) ENFORCED')
        && str_contains($migration, 'DROP CHECK ck_mc_activation_install_lock')
        && $receiptTable !== ''
        && !str_contains($receiptTable, 'recipient_email'),
    'activation receipt migration is mutable or stores raw recipient data',
);

if ($activationFailures > 0) {
    fwrite(STDERR, "managed_customer_activation_test: {$activationFailures} failure(s) / {$activationChecks} checks\n");
    exit(1);
}
echo "managed_customer_activation_test: {$activationChecks} checks\n";
