<?php
/** Hermetic contract tests for the operator-controlled Coastmark sender. */
declare(strict_types=1);

require_once __DIR__ . '/../lib/coastmark_time_export.php';

$checks = 0;
$failures = 0;

function export_check(string $name, bool $condition): void
{
    global $checks, $failures;
    $checks++;
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "FAIL {$checks}: {$name}\n");
    }
}

/** @param class-string<Throwable> $expected */
function export_throws(string $name, string $expected, callable $operation, string $fragment = ''): void
{
    try {
        $operation();
        export_check($name, false);
    } catch (Throwable $error) {
        export_check(
            $name,
            $error instanceof $expected
                && ($fragment === '' || str_contains($error->getMessage(), $fragment)),
        );
    }
}

/** @return array<string, mixed> */
function export_config(array $changes = []): array
{
    return array_replace([
        'enabled' => true,
        'endpoint' => 'https://coastmark.example/api/integrations/safeharbor/time-entries',
        'service' => 'safeharbor-time',
        'secret' => str_repeat('s', 32),
        'tenant_slugs' => ['8west'],
        'client_keys' => ['milepost-customer:11111111-1111-4111-8111-111111111111'],
        'timeout_seconds' => 15,
    ], $changes);
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE tenants (id INTEGER PRIMARY KEY, slug TEXT NOT NULL UNIQUE)');
$pdo->exec('CREATE TABLE clients (
    id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, source_key TEXT NULL
)');
$pdo->exec('CREATE TABLE users (
    id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL
)');
$pdo->exec('CREATE TABLE suite_customer_sync_bindings (
    id INTEGER PRIMARY KEY,
    tenant_id INTEGER NOT NULL,
    customer_id TEXT NOT NULL UNIQUE,
    client_id INTEGER NOT NULL,
    status TEXT NOT NULL
)');
$pdo->exec('CREATE TABLE time_entries (
    id INTEGER PRIMARY KEY,
    tenant_id INTEGER NOT NULL,
    client_id INTEGER NOT NULL,
    ticket_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    entry_key TEXT NOT NULL,
    source TEXT NOT NULL,
    worked_at TEXT NOT NULL,
    minutes INTEGER NOT NULL,
    note TEXT NOT NULL,
    billable INTEGER NOT NULL,
    approval_status TEXT NOT NULL,
    reviewed_by_user_id INTEGER NULL,
    reviewed_at TEXT NULL
)');
$pdo->exec('CREATE TABLE time_entry_approval_adjustments (
    id INTEGER PRIMARY KEY,
    tenant_id INTEGER NOT NULL,
    time_entry_id INTEGER NOT NULL,
    adjustment_key TEXT NOT NULL,
    version_no INTEGER NOT NULL,
    effective_minutes INTEGER NOT NULL,
    effective_billable INTEGER NOT NULL,
    reason TEXT NOT NULL,
    actor_user_id INTEGER NOT NULL,
    created_at TEXT NOT NULL
)');
$pdo->exec("INSERT INTO tenants VALUES (1,'8west'),(2,'customer')");
$pdo->exec("INSERT INTO clients VALUES
    (11,1,'coastmark:acme'),
    (12,1,NULL),
    (13,1,NULL),
    (14,1,NULL),
    (21,2,'coastmark:other')");
$pdo->exec('INSERT INTO users VALUES (101,1),(102,1),(201,2)');
$pdo->exec("INSERT INTO suite_customer_sync_bindings VALUES
    (1,1,'11111111-1111-4111-8111-111111111111',11,'active'),
    (2,1,'4ebaeefa-b101-47f8-ac76-e49ab309d272',13,'active'),
    (3,1,'33333333-3333-4333-8333-333333333333',14,'inactive'),
    (4,2,'22222222-2222-4222-8222-222222222222',21,'active')");
$insert = $pdo->prepare('INSERT INTO time_entries VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
$insert->execute([
    501, 1, 11, 901, 101, 'timer:approved:0001', 'timer',
    '2026-08-26 20:00:00', 30, '=private technician note', 1,
    'approved', 102, '2026-08-26 20:05:00',
]);
$insert->execute([
    502, 1, 11, 902, 101, 'timer:pending:00002', 'timer',
    '2026-08-26 20:00:00', 15, 'pending note', 1,
    'pending', null, null,
]);
$insert->execute([
    503, 1, 12, 903, 101, 'timer:no-source:003', 'timer',
    '2026-08-26 20:00:00', 15, 'no source key', 1,
    'approved', 102, '2026-08-26 20:05:00',
]);
$insert->execute([
    504, 2, 21, 904, 201, 'timer:other:000004', 'timer',
    '2026-08-26 20:00:00', 15, 'other tenant', 1,
    'approved', 201, '2026-08-26 20:05:00',
]);
$insert->execute([
    505, 1, 13, 905, 101, 'timer:master:00005', 'timer',
    '2026-08-26 20:00:00', 15, 'master tenant work', 1,
    'approved', 102, '2026-08-26 20:05:00',
]);
$insert->execute([
    506, 1, 14, 906, 101, 'timer:inactive:006', 'timer',
    '2026-08-26 20:00:00', 15, 'inactive customer work', 1,
    'approved', 102, '2026-08-26 20:05:00',
]);
$insert->execute([
    507, 1, 11, 907, 101, 'timer:adjusted:0007', 'timer',
    '2026-08-26 20:00:00', 30, 'later corrected', 1,
    'approved', 102, '2026-08-26 20:05:00',
]);
$pdo->exec("INSERT INTO time_entry_approval_adjustments VALUES
    (1,1,507,'adjustment.coastmark.fixture.0001',1,15,1,
     'Corrected approved duration',102,'2026-08-26 20:10:00')");

$payload = coastmark_time_export_payload(
    $pdo,
    '8west',
    501,
    'timer:approved:0001',
    export_config(),
);
export_check('payload has exact versioned field order', array_keys($payload) === COASTMARK_TIME_EXPORT_FIELDS);
export_check('payload identifies approved billable event',
    $payload['version'] === COASTMARK_TIME_EXPORT_VERSION
    && $payload['event'] === 'safeharbor.time_entry.approved'
    && $payload['billable'] === true
    && $payload['approval_status'] === 'approved');
export_check('tenant and client facts use the stable Milepost customer binding',
    $payload['tenant_key'] === '8west'
    && $payload['client_key'] === 'milepost-customer:11111111-1111-4111-8111-111111111111');
export_check('time and source facts are exact',
    $payload['entry_key'] === 'timer:approved:0001'
    && $payload['entry_id'] === 501
    && $payload['ticket_id'] === 901
    && $payload['entry_source'] === 'timer'
    && $payload['minutes'] === 30
    && $payload['worked_at'] === '2026-08-26T20:00:00Z'
    && $payload['approved_at'] === '2026-08-26T20:05:00Z');
export_check('people are non-PII Safeharbor-local keys',
    $payload['technician_key'] === 'safeharbor-user:101'
    && $payload['reviewer_key'] === 'safeharbor-user:102');
export_check('raw note is excluded and only its digest crosses the seam',
    !in_array('=private technician note', $payload, true)
    && $payload['note_sha256'] === hash('sha256', '=private technician note'));

export_throws(
    'adjusted approved time is refused until Coastmark owns a reversal contract',
    CoastmarkTimeExportValidationException::class,
    fn() => coastmark_time_export_payload(
        $pdo,
        '8west',
        507,
        'timer:adjusted:0007',
        export_config(),
    ),
    'Adjusted approved time',
);

export_throws(
    'pending time is refused',
    CoastmarkTimeExportValidationException::class,
    fn() => coastmark_time_export_payload($pdo, '8west', 502, 'timer:pending:00002', export_config()),
    'approved billable',
);
export_throws(
    'entry key must match the explicit operator fact',
    CoastmarkTimeExportValidationException::class,
    fn() => coastmark_time_export_payload($pdo, '8west', 501, 'timer:different:0001', export_config()),
    'explicit tenant, id, and entry key',
);
export_throws(
    'tenant allowlist fails closed',
    CoastmarkTimeExportValidationException::class,
    fn() => coastmark_time_export_payload(
        $pdo,
        '8west',
        501,
        'timer:approved:0001',
        export_config(['tenant_slugs' => []]),
    ),
    'not allowlisted',
);
export_throws(
    'client allowlist fails closed',
    CoastmarkTimeExportValidationException::class,
    fn() => coastmark_time_export_payload(
        $pdo,
        '8west',
        501,
        'timer:approved:0001',
        export_config(['client_keys' => []]),
    ),
    'not allowlisted',
);
export_throws(
    'a client without a Milepost binding is refused',
    CoastmarkTimeExportValidationException::class,
    fn() => coastmark_time_export_payload(
        $pdo,
        '8west',
        503,
        'timer:no-source:003',
        export_config(),
    ),
    'no Milepost customer binding',
);
export_throws(
    'wipe-sensitive local client keys cannot satisfy the allowlist',
    CoastmarkTimeExportValidationException::class,
    fn() => coastmark_time_export_payload(
        $pdo,
        '8west',
        501,
        'timer:approved:0001',
        export_config(['client_keys' => ['safeharbor-client:11']]),
    ),
    'not allowlisted',
);
export_throws(
    '8 West IT master is refused even when an operator allowlists it',
    CoastmarkTimeExportValidationException::class,
    fn() => coastmark_time_export_payload(
        $pdo,
        '8west',
        505,
        'timer:master:00005',
        export_config(['client_keys' => [
            'milepost-customer:' . COASTMARK_TIME_EXPORT_MASTER_CUSTOMER_ID,
        ]]),
    ),
    'never a billable Coastmark customer',
);
export_throws(
    'inactive Milepost customer binding is refused',
    CoastmarkTimeExportValidationException::class,
    fn() => coastmark_time_export_payload(
        $pdo,
        '8west',
        506,
        'timer:inactive:006',
        export_config(['client_keys' => [
            'milepost-customer:33333333-3333-4333-8333-333333333333',
        ]]),
    ),
    'active Milepost customer binding',
);
export_throws(
    'cross-tenant entry cannot be selected through an allowlisted tenant',
    CoastmarkTimeExportValidationException::class,
    fn() => coastmark_time_export_payload($pdo, '8west', 504, 'timer:other:000004', export_config()),
    'explicit tenant, id, and entry key',
);
export_throws(
    'duplicate allowlist values are rejected',
    CoastmarkTimeExportValidationException::class,
    fn() => coastmark_time_export_payload(
        $pdo,
        '8west',
        501,
        'timer:approved:0001',
        export_config(['tenant_slugs' => ['8west', '8west']]),
    ),
    'duplicate',
);

$timestamp = 1_777_777_777;
$request = coastmark_time_export_request($payload, 'safeharbor-time', str_repeat('s', 32), $timestamp);
$expectedSignature = hash_hmac('sha256', $timestamp . "\n" . $request['body'], str_repeat('s', 32));
export_check('request uses exact raw-body HMAC contract',
    in_array('X-8W-Timestamp: ' . $timestamp, $request['headers'], true)
    && in_array('X-8W-Signature: ' . $expectedSignature, $request['headers'], true));
export_check('semantic payload digest matches fixed-order JSON',
    $request['payload_sha256'] === hash('sha256', $request['body']));
export_throws(
    'short secret is refused before transport',
    CoastmarkTimeExportValidationException::class,
    fn() => coastmark_time_export_request($payload, 'safeharbor-time', 'short', $timestamp),
    'not configured',
);
$notApproved = $payload;
$notApproved['approval_status'] = 'pending';
export_throws(
    'manually constructed non-approved payload is refused before signing',
    CoastmarkTimeExportValidationException::class,
    fn() => coastmark_time_export_request($notApproved, 'safeharbor-time', str_repeat('s', 32), $timestamp),
    'approval contract',
);
$legacyContract = $payload;
$legacyContract['version'] = 1;
export_throws(
    'legacy local-id contract is refused before signing',
    CoastmarkTimeExportValidationException::class,
    fn() => coastmark_time_export_request($legacyContract, 'safeharbor-time', str_repeat('s', 32), $timestamp),
    'approval contract',
);
$masterPayload = $payload;
$masterPayload['client_key'] = 'milepost-customer:' . COASTMARK_TIME_EXPORT_MASTER_CUSTOMER_ID;
export_throws(
    'manually constructed master payload is refused before signing',
    CoastmarkTimeExportValidationException::class,
    fn() => coastmark_time_export_request($masterPayload, 'safeharbor-time', str_repeat('s', 32), $timestamp),
    'never a billable Coastmark customer',
);

$transportCalls = 0;
$transportSpy = static function () use (&$transportCalls): array {
    $transportCalls++;
    return ['status' => 201, 'body' => '{}'];
};
export_throws(
    'v2 send is retired even when every old configuration gate is enabled',
    CoastmarkTimeExportValidationException::class,
    fn() => coastmark_time_export_send($payload, export_config(), $transportSpy, $timestamp),
    'receipt/reversal-aware v3',
);
export_check(
    'retired v2 stops before signing or network transport',
    $transportCalls === 0 && !function_exists('coastmark_time_export_curl'),
);

echo "Coastmark approved-time export: {$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
