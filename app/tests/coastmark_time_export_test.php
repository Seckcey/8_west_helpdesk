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
        'client_keys' => ['safeharbor-client:11'],
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
$pdo->exec("INSERT INTO tenants VALUES (1,'8west'),(2,'customer')");
$pdo->exec("INSERT INTO clients VALUES
    (11,1,'coastmark:acme'),
    (12,1,NULL),
    (21,2,'coastmark:other')");
$pdo->exec('INSERT INTO users VALUES (101,1),(102,1),(201,2)');
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

$payload = coastmark_time_export_payload(
    $pdo,
    '8west',
    501,
    'timer:approved:0001',
    export_config(),
);
export_check('payload has exact versioned field order', array_keys($payload) === COASTMARK_TIME_EXPORT_FIELDS);
export_check('payload identifies approved billable event',
    $payload['version'] === 1
    && $payload['event'] === 'safeharbor.time_entry.approved'
    && $payload['billable'] === true
    && $payload['approval_status'] === 'approved');
export_check('tenant and client facts are immutable deterministic Safeharbor keys',
    $payload['tenant_key'] === '8west' && $payload['client_key'] === 'safeharbor-client:11');
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
    'client key must be explicitly allowlisted',
    CoastmarkTimeExportValidationException::class,
    fn() => coastmark_time_export_payload(
        $pdo,
        '8west',
        503,
        'timer:no-source:003',
        export_config(['client_keys' => ['safeharbor-client:11']]),
    ),
    'not allowlisted',
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

$ack = static function (string $endpoint, array $headers, string $body, int $timeout) use ($payload): array {
    $hash = hash('sha256', $body);
    return [
        'status' => 201,
        'body' => json_encode([
            'ok' => true,
            'action' => 'created',
            'source' => ['tenant_key' => $payload['tenant_key'], 'entry_key' => $payload['entry_key']],
            'coastmark' => ['import_id' => 1, 'invoice_id' => 2, 'invoice_line_id' => 3],
            'payload_sha256' => $hash,
        ], JSON_THROW_ON_ERROR),
    ];
};
$created = coastmark_time_export_send($payload, export_config(), $ack, $timestamp);
export_check('201 acknowledgement returns only draft import identifiers',
    $created['action'] === 'created'
    && $created['import_id'] === 1
    && $created['invoice_id'] === 2
    && $created['invoice_line_id'] === 3);

$replay = static function (string $endpoint, array $headers, string $body, int $timeout) use ($payload): array {
    return [
        'status' => 200,
        'body' => json_encode([
            'ok' => true,
            'action' => 'ignored',
            'source' => ['tenant_key' => $payload['tenant_key'], 'entry_key' => $payload['entry_key']],
            'coastmark' => ['import_id' => 1, 'invoice_id' => 2, 'invoice_line_id' => 3],
            'payload_sha256' => hash('sha256', $body),
        ], JSON_THROW_ON_ERROR),
    ];
};
export_check('200 exact replay is a truthful ignored acknowledgement',
    coastmark_time_export_send($payload, export_config(), $replay, $timestamp)['action'] === 'ignored');

export_throws(
    'global feature gate blocks all network transport',
    CoastmarkTimeExportValidationException::class,
    fn() => coastmark_time_export_send($payload, export_config(['enabled' => false]), $ack, $timestamp),
    'disabled',
);
export_throws(
    'non-HTTPS endpoint is refused',
    CoastmarkTimeExportValidationException::class,
    fn() => coastmark_time_export_send(
        $payload,
        export_config(['endpoint' => 'http://coastmark.example/api/integrations/safeharbor/time-entries']),
        $ack,
        $timestamp,
    ),
    'HTTPS',
);
export_throws(
    'non-canonical HTTPS path is refused',
    CoastmarkTimeExportValidationException::class,
    fn() => coastmark_time_export_send(
        $payload,
        export_config(['endpoint' => 'https://coastmark.example/api/other']),
        $ack,
        $timestamp,
    ),
    'HTTPS',
);
export_throws(
    'non-canonical HTTPS port is refused',
    CoastmarkTimeExportValidationException::class,
    fn() => coastmark_time_export_send(
        $payload,
        export_config(['endpoint' => 'https://coastmark.example:8443/api/integrations/safeharbor/time-entries']),
        $ack,
        $timestamp,
    ),
    'HTTPS',
);
export_throws(
    'HTTP failure remains retryable only with same key',
    CoastmarkTimeExportTransportException::class,
    fn() => coastmark_time_export_send(
        $payload,
        export_config(),
        fn() => ['status' => 503, 'body' => '{}'],
        $timestamp,
    ),
    'same entry key',
);
export_throws(
    'mismatched source acknowledgement is refused',
    CoastmarkTimeExportTransportException::class,
    fn() => coastmark_time_export_send(
        $payload,
        export_config(),
        fn(string $endpoint, array $headers, string $body, int $timeout) => [
            'status' => 201,
            'body' => json_encode([
                'ok' => true,
                'action' => 'created',
                'source' => ['tenant_key' => 'wrong', 'entry_key' => $payload['entry_key']],
                'coastmark' => ['import_id' => 1, 'invoice_id' => 2, 'invoice_line_id' => 3],
                'payload_sha256' => hash('sha256', $body),
            ], JSON_THROW_ON_ERROR),
        ],
        $timestamp,
    ),
    'did not match',
);
export_throws(
    'oversized acknowledgement is refused',
    CoastmarkTimeExportTransportException::class,
    fn() => coastmark_time_export_send(
        $payload,
        export_config(),
        fn() => ['status' => 201, 'body' => str_repeat('x', 16_385)],
        $timestamp,
    ),
    'safe limit',
);

echo "Coastmark approved-time export: {$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
