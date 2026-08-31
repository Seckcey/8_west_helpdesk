<?php
/** Hermetic contract/auth/idempotency tests for Milepost customer sync. */
declare(strict_types=1);

$SYNC_TEST_CONFIG = [
    'suite_customer_sync' => [
        'enabled' => true,
        'tenant_slugs' => ['8west'],
        'hmac_secret' => '0123456789abcdef0123456789abcdef',
    ],
];

function cfg(string $key, mixed $default = null): mixed
{
    global $SYNC_TEST_CONFIG;
    $value = $SYNC_TEST_CONFIG;
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

require_once __DIR__ . '/../lib/suite_customer_sync.php';

$checks = 0;
$failures = 0;

function sync_check(string $name, bool $condition): void
{
    global $checks, $failures;
    $checks++;
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "FAIL {$checks}: {$name}\n");
    }
}

/** @param class-string<Throwable> $expected */
function sync_throws(
    string $name,
    string $expected,
    callable $operation,
    string $message = '',
    ?int $expectedVersion = null,
): void {
    try {
        $operation();
        sync_check($name, false);
    } catch (Throwable $error) {
        $ok = $error instanceof $expected
            && ($message === '' || $error->getMessage() === $message);
        if ($expectedVersion !== null) {
            $ok = $ok
                && $error instanceof SuiteCustomerSyncConflictException
                && $error->expectedSourceVersion === $expectedVersion;
        }
        sync_check($name, $ok);
    }
}

/** @return array<string,mixed> */
function sync_payload(array $replace = []): array
{
    return array_replace([
        'schema_version' => 1,
        'tenant_slug' => '8west',
        'customer_id' => '11111111-1111-4111-8111-111111111111',
        'source_version' => 1,
        'display_name' => '8 West IT',
        'status' => 'active',
        'event_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        'occurred_at' => '2026-08-26T12:00:00Z',
    ], $replace);
}

/** @return array{raw:string,payload:array<string,mixed>,sha:string} */
function sync_request(array $replace = [], int $now = 1787760000): array
{
    $raw = (string)json_encode(sync_payload($replace), JSON_UNESCAPED_SLASHES);
    return [
        'raw' => $raw,
        'payload' => suite_customer_sync_decode($raw, $now),
        'sha' => hash('sha256', $raw),
    ];
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec('CREATE TABLE tenants (id INTEGER PRIMARY KEY, name TEXT NOT NULL, slug TEXT NOT NULL UNIQUE)');
$pdo->exec('CREATE TABLE svc_identities (
    id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
    service TEXT NOT NULL, display_name TEXT NOT NULL, is_active INTEGER NOT NULL DEFAULT 1,
    last_seen_at TEXT NULL, UNIQUE(tenant_id,service))');
$pdo->exec('CREATE TABLE clients (
    id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
    name TEXT NOT NULL, domain TEXT NOT NULL DEFAULT \'\', source_key TEXT NULL,
    sla_tier TEXT NOT NULL DEFAULT \'standard\', health TEXT NOT NULL DEFAULT \'good\',
    notes TEXT NULL, UNIQUE(tenant_id,id))');
$pdo->exec('CREATE TABLE tickets (
    id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
    client_id INTEGER NOT NULL, subject TEXT NOT NULL,
    FOREIGN KEY(tenant_id,client_id) REFERENCES clients(tenant_id,id))');
$pdo->exec('CREATE TABLE time_entries (
    id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
    client_id INTEGER NOT NULL, ticket_id INTEGER NOT NULL,
    FOREIGN KEY(tenant_id,client_id) REFERENCES clients(tenant_id,id),
    FOREIGN KEY(ticket_id) REFERENCES tickets(id))');
$pdo->exec('CREATE TABLE suite_customer_sync_bindings (
    id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
    customer_id TEXT NOT NULL UNIQUE, client_id INTEGER NOT NULL,
    source_version INTEGER NOT NULL, display_name TEXT NOT NULL, status TEXT NOT NULL,
    last_event_id TEXT NOT NULL UNIQUE, last_occurred_at TEXT NOT NULL,
    last_request_sha256 TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(tenant_id,client_id), UNIQUE(tenant_id,id),
    FOREIGN KEY(tenant_id,client_id) REFERENCES clients(tenant_id,id))');
$pdo->exec('CREATE TABLE suite_customer_sync_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
    event_id TEXT NOT NULL UNIQUE, binding_id INTEGER NOT NULL,
    customer_id TEXT NOT NULL, client_id INTEGER NOT NULL, source_version INTEGER NOT NULL,
    display_name TEXT NOT NULL, status TEXT NOT NULL, occurred_at TEXT NOT NULL,
    request_sha256 TEXT NOT NULL, received_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(tenant_id,customer_id,source_version),
    FOREIGN KEY(tenant_id,binding_id) REFERENCES suite_customer_sync_bindings(tenant_id,id),
    FOREIGN KEY(tenant_id,client_id) REFERENCES clients(tenant_id,id))');
$pdo->exec("CREATE TRIGGER sync_event_exact BEFORE INSERT ON suite_customer_sync_events
    WHEN NOT EXISTS (
      SELECT 1 FROM suite_customer_sync_bindings binding
      JOIN clients client ON client.tenant_id=binding.tenant_id AND client.id=binding.client_id
      WHERE binding.tenant_id=NEW.tenant_id AND binding.id=NEW.binding_id
        AND binding.customer_id=NEW.customer_id AND binding.client_id=NEW.client_id
        AND binding.source_version=NEW.source_version
        AND binding.display_name=NEW.display_name AND binding.status=NEW.status
        AND binding.last_event_id=NEW.event_id
        AND binding.last_occurred_at=NEW.occurred_at
        AND binding.last_request_sha256=NEW.request_sha256
        AND (NEW.status='inactive' OR client.name=NEW.display_name)
    )
    BEGIN SELECT RAISE(ABORT, 'receipt mismatch'); END");
$pdo->exec("CREATE TRIGGER sync_binding_initial BEFORE INSERT ON suite_customer_sync_bindings
    WHEN NEW.source_version <> 1 OR NEW.status <> 'active'
    BEGIN SELECT RAISE(ABORT, 'initial binding invalid'); END");
$pdo->exec("CREATE TRIGGER sync_binding_advance BEFORE UPDATE ON suite_customer_sync_bindings
    WHEN NEW.id <> OLD.id OR NEW.tenant_id <> OLD.tenant_id
      OR NEW.customer_id <> OLD.customer_id OR NEW.client_id <> OLD.client_id
      OR NEW.source_version <> OLD.source_version + 1
      OR NEW.last_event_id = OLD.last_event_id
      OR NEW.last_request_sha256 = OLD.last_request_sha256
      OR NEW.last_occurred_at < OLD.last_occurred_at
    BEGIN SELECT RAISE(ABORT, 'binding advance invalid'); END");
$pdo->exec("CREATE TRIGGER sync_binding_receipt_insert AFTER INSERT ON suite_customer_sync_bindings
    BEGIN
      INSERT INTO suite_customer_sync_events
        (tenant_id,event_id,binding_id,customer_id,client_id,source_version,
         display_name,status,occurred_at,request_sha256,received_at)
      VALUES
        (NEW.tenant_id,NEW.last_event_id,NEW.id,NEW.customer_id,NEW.client_id,
         NEW.source_version,NEW.display_name,NEW.status,NEW.last_occurred_at,
         NEW.last_request_sha256,NEW.updated_at);
    END");
$pdo->exec("CREATE TRIGGER sync_binding_receipt_update AFTER UPDATE ON suite_customer_sync_bindings
    BEGIN
      INSERT INTO suite_customer_sync_events
        (tenant_id,event_id,binding_id,customer_id,client_id,source_version,
         display_name,status,occurred_at,request_sha256,received_at)
      VALUES
        (NEW.tenant_id,NEW.last_event_id,NEW.id,NEW.customer_id,NEW.client_id,
         NEW.source_version,NEW.display_name,NEW.status,NEW.last_occurred_at,
         NEW.last_request_sha256,CURRENT_TIMESTAMP);
    END");
$pdo->exec("CREATE TRIGGER sync_binding_no_delete BEFORE DELETE ON suite_customer_sync_bindings
    BEGIN SELECT RAISE(ABORT, 'bindings immutable'); END");
$pdo->exec("CREATE TRIGGER sync_events_no_update BEFORE UPDATE ON suite_customer_sync_events
    BEGIN SELECT RAISE(ABORT, 'events immutable'); END");
$pdo->exec("CREATE TRIGGER sync_events_no_delete BEFORE DELETE ON suite_customer_sync_events
    BEGIN SELECT RAISE(ABORT, 'events immutable'); END");
$pdo->exec("INSERT INTO tenants(id,name,slug) VALUES
    (1,'8 West IT, LLC','8west'),(2,'Other MSP','other')");
$pdo->exec("INSERT INTO svc_identities(tenant_id,service,display_name) VALUES
    (1,'milepost-customers','Milepost customer registry'),
    (2,'milepost-customers','Milepost customer registry')");

$endpointSource = (string)file_get_contents(__DIR__ . '/../public/api/svc/customers.php');
sync_check('receiver route checks its independent gate before request method or body',
    strpos($endpointSource, 'if (!suite_customer_sync_enabled())')
        < strpos($endpointSource, "REQUEST_METHOD")
    && strpos($endpointSource, "REQUEST_METHOD")
        < strpos($endpointSource, "php://input"));
sync_check('tenant slug resolves only an exact persisted tenant',
    suite_customer_sync_resolve_tenant($pdo, '8west') === ['id' => 1, 'slug' => '8west']
    && suite_customer_sync_resolve_tenant($pdo, '8WEST') === null
    && suite_customer_sync_resolve_tenant($pdo, 'missing') === null);

// Strict v1 payload.
$valid = sync_request();
sync_check('strict payload decodes', $valid['payload']['tenant_slug'] === '8west');
sync_throws('extra key rejected', SuiteCustomerSyncValidationException::class,
    fn() => suite_customer_sync_decode((string)json_encode(sync_payload(['extra' => 1])), 1787760000));
sync_throws('missing key rejected', SuiteCustomerSyncValidationException::class,
    function (): void {
        $payload = sync_payload(); unset($payload['status']);
        suite_customer_sync_decode((string)json_encode($payload), 1787760000);
    });
sync_throws('duplicate top-level key rejected', SuiteCustomerSyncValidationException::class,
    fn() => suite_customer_sync_decode(
        '{"schema_version":1,"schema_version":1,"tenant_slug":"8west",'
        . '"customer_id":"11111111-1111-4111-8111-111111111111",'
        . '"source_version":1,"display_name":"8 West IT","status":"active",'
        . '"event_id":"aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa",'
        . '"occurred_at":"2026-08-26T12:00:00Z"}',
        1787760000,
    ), 'duplicate_payload_key');
sync_throws('string schema version rejected', SuiteCustomerSyncValidationException::class,
    fn() => sync_request(['schema_version' => '1']));
sync_throws('uppercase customer uuid rejected', SuiteCustomerSyncValidationException::class,
    fn() => sync_request(['customer_id' => 'AAAAAAAA-AAAA-4AAA-8AAA-AAAAAAAAAAAA']));
sync_throws('non-v4 event uuid rejected', SuiteCustomerSyncValidationException::class,
    fn() => sync_request(['event_id' => 'aaaaaaaa-aaaa-1aaa-8aaa-aaaaaaaaaaaa']));
sync_throws('zero source version rejected', SuiteCustomerSyncValidationException::class,
    fn() => sync_request(['source_version' => 0]));
sync_throws('trimmed display name required', SuiteCustomerSyncValidationException::class,
    fn() => sync_request(['display_name' => ' 8 West IT']));
sync_throws('control byte in name rejected', SuiteCustomerSyncValidationException::class,
    fn() => sync_request(['display_name' => "8 West\nIT"]));
sync_throws('Unicode C1 control character in name rejected', SuiteCustomerSyncValidationException::class,
    fn() => sync_request(['display_name' => "8 West\u{0085}IT"]));
sync_throws('Unicode right-to-left override in name rejected', SuiteCustomerSyncValidationException::class,
    fn() => sync_request(['display_name' => "8 West\u{202E}IT"]), 'display_name_invalid');
sync_throws('Unicode directional isolate in name rejected', SuiteCustomerSyncValidationException::class,
    fn() => sync_request(['display_name' => "8 West\u{2068}IT\u{2069}"]), 'display_name_invalid');
sync_check(
    'ordinary international customer name remains accepted',
    sync_request(['display_name' => 'Café München 東京'])['payload']['display_name']
        === 'Café München 東京',
);
sync_throws('unknown status rejected', SuiteCustomerSyncValidationException::class,
    fn() => sync_request(['status' => 'deleted']));
sync_throws('noncanonical event time rejected', SuiteCustomerSyncValidationException::class,
    fn() => sync_request(['occurred_at' => '2026-08-26T12:00:00+00:00']));
sync_throws('event time outside MySQL durable range rejected', SuiteCustomerSyncValidationException::class,
    fn() => sync_request(['occurred_at' => '0999-12-31T23:59:59Z']));
sync_throws('future event time rejected', SuiteCustomerSyncValidationException::class,
    fn() => sync_request(['occurred_at' => '2030-01-01T00:00:00Z'], 1787760000));
sync_check('old durable event time accepted', sync_request(['occurred_at' => '2020-01-01T00:00:00Z'])['payload']['occurred_at'] === '2020-01-01T00:00:00Z');

// Destination-specific authentication.
$now = 1787760000;
$timestamp = (string)$now;
$signature = hash_hmac(
    'sha256',
    SUITE_CUSTOMER_SYNC_SIGNATURE_CONTEXT . "\n{$timestamp}\n" . $valid['raw'],
    (string)cfg('suite_customer_sync.hmac_secret'),
);
$savedSyncConfig = $SYNC_TEST_CONFIG['suite_customer_sync'];
$SYNC_TEST_CONFIG['suite_customer_sync']['enabled'] = false;
sync_check('independent receiver gate fails closed', suite_customer_sync_authenticate(
    $pdo, 1, '8west', SUITE_CUSTOMER_SYNC_SERVICE, $timestamp, $signature, $valid['raw'], $now
)['ok'] === false);
$SYNC_TEST_CONFIG['suite_customer_sync']['enabled'] = 1;
sync_check('receiver gate requires the exact boolean true', suite_customer_sync_authenticate(
    $pdo, 1, '8west', SUITE_CUSTOMER_SYNC_SERVICE, $timestamp, $signature, $valid['raw'], $now
)['ok'] === false);
$SYNC_TEST_CONFIG['suite_customer_sync'] = $savedSyncConfig;
sync_check('exact destination signature accepted', suite_customer_sync_authenticate(
    $pdo, 1, '8west', SUITE_CUSTOMER_SYNC_SERVICE, $timestamp, $signature, $valid['raw'], $now
)['ok'] === true);
sync_check('authentication stamps exact identity only',
    (string)$pdo->query("SELECT last_seen_at FROM svc_identities WHERE tenant_id=1 AND service='milepost-customers'")->fetchColumn() !== '');
sync_check('wrong job identity rejected', suite_customer_sync_authenticate(
    $pdo, 1, '8west', 'milepost', $timestamp, $signature, $valid['raw'], $now
)['ok'] === false);
sync_check('tenant id and signed slug must resolve to the same destination', suite_customer_sync_authenticate(
    $pdo, 2, '8west', SUITE_CUSTOMER_SYNC_SERVICE, $timestamp, $signature, $valid['raw'], $now
)['ok'] === false);
sync_check('tampered body rejected by signature', suite_customer_sync_authenticate(
    $pdo, 1, '8west', SUITE_CUSTOMER_SYNC_SERVICE, $timestamp, $signature, $valid['raw'] . ' ', $now
)['ok'] === false);
sync_check('uppercase signature rejected', suite_customer_sync_authenticate(
    $pdo, 1, '8west', SUITE_CUSTOMER_SYNC_SERVICE, $timestamp, strtoupper($signature), $valid['raw'], $now
)['ok'] === false);
sync_check('noncanonical timestamp rejected', suite_customer_sync_authenticate(
    $pdo, 1, '8west', SUITE_CUSTOMER_SYNC_SERVICE, '0' . $timestamp, $signature, $valid['raw'], $now
)['ok'] === false);
 $staleTimestamp = (string)($now - 301);
$staleSignature = hash_hmac(
    'sha256',
    SUITE_CUSTOMER_SYNC_SIGNATURE_CONTEXT . "\n{$staleTimestamp}\n" . $valid['raw'],
    (string)cfg('suite_customer_sync.hmac_secret'),
);
sync_check('correctly signed stale auth timestamp rejected', suite_customer_sync_authenticate(
    $pdo, 1, '8west', SUITE_CUSTOMER_SYNC_SERVICE, $staleTimestamp, $staleSignature, $valid['raw'], $now
)['ok'] === false);
$futureTimestamp = (string)($now + 301);
$futureSignature = hash_hmac(
    'sha256',
    SUITE_CUSTOMER_SYNC_SIGNATURE_CONTEXT . "\n{$futureTimestamp}\n" . $valid['raw'],
    (string)cfg('suite_customer_sync.hmac_secret'),
);
sync_check('correctly signed future auth timestamp rejected', suite_customer_sync_authenticate(
    $pdo, 1, '8west', SUITE_CUSTOMER_SYNC_SERVICE, $futureTimestamp, $futureSignature, $valid['raw'], $now
)['ok'] === false);
sync_check('non-allowlisted destination rejected', suite_customer_sync_authenticate(
    $pdo, 2, 'other', SUITE_CUSTOMER_SYNC_SERVICE, $timestamp, $signature, $valid['raw'], $now
)['ok'] === false);
$pdo->exec("UPDATE svc_identities SET is_active=0 WHERE tenant_id=1 AND service='milepost-customers'");
sync_check('inactive exact service identity rejected', suite_customer_sync_authenticate(
    $pdo, 1, '8west', SUITE_CUSTOMER_SYNC_SERVICE, $timestamp, $signature, $valid['raw'], $now
)['ok'] === false);
$pdo->exec("UPDATE svc_identities SET is_active=1 WHERE tenant_id=1 AND service='milepost-customers'");

// Create + exact replay.
$created = suite_customer_sync_receive($pdo, $valid['payload'], $valid['sha']);
sync_check('create success body is exact frozen shape', array_keys($created) === [
    'ok','event_id','customer_id','source_version','status',
] && $created['source_version'] === 1 && $created['status'] === 'active');
$binding = $pdo->query('SELECT * FROM suite_customer_sync_bindings')->fetch();
$clientId = (int)$binding['client_id'];
$client = $pdo->query("SELECT * FROM clients WHERE id={$clientId}")->fetch();
sync_check('new customer creates one standard client',
    $client['name'] === '8 West IT' && $client['domain'] === ''
    && $client['sla_tier'] === 'standard' && $client['health'] === 'good');
sync_check('customer binding never uses support source_key', $client['source_key'] === null);
sync_check('create writes one immutable receipt',
    (int)$pdo->query('SELECT COUNT(*) FROM suite_customer_sync_events')->fetchColumn() === 1);
$replayed = suite_customer_sync_receive($pdo, $valid['payload'], $valid['sha']);
sync_check('same event replay returns identical body', $replayed === $created);
sync_check('same event replay creates nothing',
    (int)$pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn() === 1
    && (int)$pdo->query('SELECT COUNT(*) FROM suite_customer_sync_events')->fetchColumn() === 1);

sync_throws('same event id with changed facts is refused', SuiteCustomerSyncConflictException::class,
    function () use ($pdo): void {
        $request = sync_request(['display_name' => 'Tampered Name']);
        suite_customer_sync_receive($pdo, $request['payload'], $request['sha']);
    }, 'event_id_conflict');
sync_throws('different event with stale same version is refused', SuiteCustomerSyncConflictException::class,
    function () use ($pdo): void {
        $request = sync_request(['event_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb']);
        suite_customer_sync_receive($pdo, $request['payload'], $request['sha']);
    }, 'source_version_conflict', 2);
sync_throws('future source-version gap is refused', SuiteCustomerSyncConflictException::class,
    function () use ($pdo): void {
        $request = sync_request([
            'source_version' => 3,
            'event_id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
        ]);
        suite_customer_sync_receive($pdo, $request['payload'], $request['sha']);
    }, 'source_version_conflict', 2);

// Rename preserves the exact client and its dependent history.
$pdo->exec("INSERT INTO tickets(tenant_id,client_id,subject) VALUES(1,{$clientId},'Keep me')");
$ticketId = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO time_entries(tenant_id,client_id,ticket_id) VALUES(1,{$clientId},{$ticketId})");
$renamedRequest = sync_request([
    'source_version' => 2,
    'display_name' => '8 West IT, LLC',
    'event_id' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
    'occurred_at' => '2026-08-26T12:01:00Z',
]);
$renamed = suite_customer_sync_receive($pdo, $renamedRequest['payload'], $renamedRequest['sha']);
sync_check('rename advances exact source version', $renamed['source_version'] === 2);
sync_check('rename keeps stable Safeharbor client id',
    (int)$pdo->query('SELECT client_id FROM suite_customer_sync_bindings')->fetchColumn() === $clientId);
sync_check('rename updates only the client name',
    $pdo->query("SELECT name FROM clients WHERE id={$clientId}")->fetchColumn() === '8 West IT, LLC'
    && $pdo->query("SELECT source_key IS NULL FROM clients WHERE id={$clientId}")->fetchColumn() == 1);
sync_check('rename preserves ticket and time history',
    (int)$pdo->query('SELECT COUNT(*) FROM tickets')->fetchColumn() === 1
    && (int)$pdo->query('SELECT COUNT(*) FROM time_entries')->fetchColumn() === 1);

sync_throws('regressing business event time is refused', SuiteCustomerSyncConflictException::class,
    function () use ($pdo): void {
        $request = sync_request([
            'source_version' => 3,
            'event_id' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
            'occurred_at' => '2026-08-26T11:59:59Z',
        ]);
        suite_customer_sync_receive($pdo, $request['payload'], $request['sha']);
    }, 'event_time_conflict');

$inactiveRequest = sync_request([
    'source_version' => 3,
    'display_name' => 'Milepost Retired Name',
    'status' => 'inactive',
    'event_id' => 'ffffffff-ffff-4fff-8fff-ffffffffffff',
    'occurred_at' => '2026-08-26T12:02:00Z',
]);
$inactive = suite_customer_sync_receive($pdo, $inactiveRequest['payload'], $inactiveRequest['sha']);
sync_check('deactivate records inactive status', $inactive['status'] === 'inactive'
    && $pdo->query('SELECT status FROM suite_customer_sync_bindings')->fetchColumn() === 'inactive');
sync_check('deactivate preserves current client name, ticket, time, and SLA-owned columns',
    (int)$pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn() === 1
    && $pdo->query("SELECT name FROM clients WHERE id={$clientId}")->fetchColumn() === '8 West IT, LLC'
    && (int)$pdo->query('SELECT COUNT(*) FROM tickets')->fetchColumn() === 1
    && (int)$pdo->query('SELECT COUNT(*) FROM time_entries')->fetchColumn() === 1
    && $pdo->query("SELECT sla_tier FROM clients WHERE id={$clientId}")->fetchColumn() === 'standard');
sync_check('every accepted version has exactly one receipt',
    (int)$pdo->query('SELECT COUNT(*) FROM suite_customer_sync_events')->fetchColumn() === 3);
sync_check('inactive receipt preserves the exact source display name without applying it to the client',
    $pdo->query("SELECT display_name FROM suite_customer_sync_events WHERE source_version=3")->fetchColumn()
        === 'Milepost Retired Name');

$reactivateRequest = sync_request([
    'source_version' => 4,
    'display_name' => '8 West IT Reactivated',
    'status' => 'active',
    'event_id' => '99999999-9999-4999-8999-999999999999',
    'occurred_at' => '2026-08-26T12:03:00Z',
]);
$reactivated = suite_customer_sync_receive(
    $pdo, $reactivateRequest['payload'], $reactivateRequest['sha'],
);
sync_check('later active version reactivates and renames the same retained client',
    $reactivated['status'] === 'active'
    && (int)$pdo->query('SELECT client_id FROM suite_customer_sync_bindings')->fetchColumn() === $clientId
    && $pdo->query("SELECT name FROM clients WHERE id={$clientId}")->fetchColumn()
        === '8 West IT Reactivated'
    && (int)$pdo->query('SELECT COUNT(*) FROM suite_customer_sync_events')->fetchColumn() === 4);

sync_throws('customer UUID cannot move across tenants', SuiteCustomerSyncConflictException::class,
    function () use ($pdo): void {
        $request = sync_request([
            'tenant_slug' => 'other',
            'source_version' => 5,
            'status' => 'active',
            'event_id' => '12345678-1234-4234-8234-123456789abc',
            'occurred_at' => '2026-08-26T12:04:00Z',
        ]);
        suite_customer_sync_receive($pdo, $request['payload'], $request['sha']);
    }, 'customer_tenant_conflict');

sync_throws('binding deletion is database-denied', PDOException::class,
    fn() => $pdo->exec('DELETE FROM suite_customer_sync_bindings'));
sync_throws('receipt update is database-denied', PDOException::class,
    fn() => $pdo->exec("UPDATE suite_customer_sync_events SET status='active' WHERE id=1"));
sync_throws('receipt deletion is database-denied', PDOException::class,
    fn() => $pdo->exec('DELETE FROM suite_customer_sync_events WHERE id=1'));

echo "{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
