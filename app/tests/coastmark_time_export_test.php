<?php
/** Hermetic v3 claim, receipt, ambiguity, and correction contract tests. */
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
        echo "FAIL {$checks} - {$name}\n";
    } else {
        echo "ok {$checks} - {$name}\n";
    }
}
function export_expect(string $name, string $class, callable $operation, string $message = ''): void
{
    try {
        $operation();
        export_check($name, false);
    } catch (Throwable $error) {
        export_check(
            $name,
            $error instanceof $class && ($message === '' || str_contains($error->getMessage(), $message)),
        );
    }
}
function export_config(array $overrides = []): array
{
    return array_replace([
        'claim_enabled' => true,
        'enabled' => true,
        'endpoint' => 'https://coastmark.example.test/api/integrations/safeharbor/time-entries',
        'status_endpoint' => 'https://coastmark.example.test/api/integrations/safeharbor/time-events/status',
        'service' => 'safeharbor-time',
        'secret' => str_repeat('s', 32),
        'tenant_slugs' => ['8west'],
        'client_keys' => ['milepost-customer:11111111-1111-4111-8111-111111111111'],
        'timeout_seconds' => 5,
    ], $overrides);
}
function export_ack(
    array $claim,
    string $action = 'created',
    int $eventId = 91,
    ?int $lineId = 71,
): string
{
    return json_encode([
        'ok' => true,
        'action' => $action,
        'event' => [
            'event_key' => $claim['event_key'],
            'payload_sha256' => $claim['payload_sha256'],
            'coastmark_event_id' => $eventId,
        ],
        'draft' => [
            'invoice_id' => 81,
            'invoice_line_id' => $action === 'manual_exception' ? null : $lineId,
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE tenants(id INTEGER PRIMARY KEY,slug TEXT NOT NULL UNIQUE)');
$pdo->exec('CREATE TABLE clients(id INTEGER PRIMARY KEY,tenant_id INTEGER NOT NULL,name TEXT NOT NULL)');
$pdo->exec('CREATE TABLE users(
  id INTEGER PRIMARY KEY,tenant_id INTEGER NOT NULL,role TEXT NOT NULL,is_active INTEGER NOT NULL)');
$pdo->exec('CREATE TABLE suite_customer_sync_bindings(
  id INTEGER PRIMARY KEY,tenant_id INTEGER NOT NULL,client_id INTEGER NOT NULL,
  customer_id TEXT NOT NULL,status TEXT NOT NULL)');
$pdo->exec('CREATE TABLE time_entries(
  id INTEGER PRIMARY KEY,tenant_id INTEGER NOT NULL,client_id INTEGER NOT NULL,ticket_id INTEGER NOT NULL,
  entry_key TEXT NOT NULL,source TEXT NOT NULL,worked_at TEXT NOT NULL,minutes INTEGER NOT NULL,
  note TEXT NOT NULL,billable INTEGER NOT NULL,approval_status TEXT NOT NULL,user_id INTEGER NOT NULL,
  reviewed_by_user_id INTEGER,reviewed_at TEXT)');
$pdo->exec('CREATE TABLE time_entry_approval_adjustments(
  id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER NOT NULL,time_entry_id INTEGER NOT NULL,
  adjustment_key TEXT NOT NULL,version_no INTEGER NOT NULL,effective_minutes INTEGER NOT NULL,
  effective_billable INTEGER NOT NULL,reason TEXT NOT NULL,actor_user_id INTEGER NOT NULL,
  created_at TEXT NOT NULL)');
$pdo->exec('CREATE TABLE coastmark_time_export_claims(
  id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER NOT NULL,time_entry_id INTEGER NOT NULL,
  source_version INTEGER NOT NULL,event_key TEXT NOT NULL,predecessor_claim_id INTEGER,
  payload_sha256 TEXT NOT NULL,payload_json TEXT NOT NULL,created_by_user_id INTEGER NOT NULL,
  created_at TEXT NOT NULL,UNIQUE(tenant_id,time_entry_id,source_version),UNIQUE(tenant_id,event_key))');
$pdo->exec('CREATE TABLE coastmark_time_export_receipts(
  id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER NOT NULL,claim_id INTEGER NOT NULL,
  operation_key TEXT NOT NULL,operation_kind TEXT NOT NULL,outcome TEXT NOT NULL,response_status INTEGER,
  response_sha256 TEXT,coastmark_event_id INTEGER,invoice_id INTEGER,invoice_line_id INTEGER,
  detail_code TEXT NOT NULL,created_at TEXT NOT NULL,UNIQUE(tenant_id,operation_key))');

$pdo->exec("INSERT INTO tenants VALUES(1,'8west'),(2,'other')");
$pdo->exec("INSERT INTO clients VALUES(11,1,'Lifestyle'),(12,1,'Master'),(21,2,'Other')");
$pdo->exec("INSERT INTO users VALUES
  (101,1,'tech',1),(102,1,'owner',1),(103,1,'admin',1),(104,1,'owner',0),(201,2,'owner',1)");
$pdo->exec("INSERT INTO suite_customer_sync_bindings VALUES
  (1,1,11,'11111111-1111-4111-8111-111111111111','active'),
  (2,1,12,'4ebaeefa-b101-47f8-ac76-e49ab309d272','active')");
$insert = $pdo->prepare('INSERT INTO time_entries VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
$insert->execute([
    501,1,11,901,'timer:approved:0001','timer','2026-08-26 20:00:00',30,
    '=private technician note',1,'approved',101,102,'2026-08-26 20:05:00',
]);
$insert->execute([
    505,1,12,905,'timer:master:00005','timer','2026-08-26 20:00:00',15,
    'master time',1,'approved',101,102,'2026-08-26 20:05:00',
]);
$insert->execute([
    502,1,11,902,'timer:inflight:0002','timer','2026-08-26 20:00:00',10,
    'in-flight proof',1,'approved',101,102,'2026-08-26 20:05:00',
]);
$insert->execute([
    503,1,11,903,'timer:false404:0003','timer','2026-08-26 20:00:00',20,
    'status proof',1,'approved',101,102,'2026-08-26 20:05:00',
]);
$insert->execute([
    504,1,11,904,'timer:wrongack:0004','timer','2026-08-26 20:00:00',20,
    'ack mismatch proof',1,'approved',101,102,'2026-08-26 20:05:00',
]);

$config = export_config();
export_expect(
    'dedicated export database identity cannot reuse the web runtime user',
    CoastmarkTimeExportValidationException::class,
    fn() => coastmark_time_export_database([
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'safeharbor',
        'charset' => 'utf8mb4',
        'user' => 'safeharbor',
    ], [
        'database_user' => 'safeharbor',
        'database_password' => str_repeat('p', 24),
    ]),
    'Dedicated Coastmark export database configuration is incomplete',
);
export_expect(
    'claim gate defaults closed before any database write',
    CoastmarkTimeExportValidationException::class,
    fn() => coastmark_time_export_claim(
        $pdo, '8west', 501, 'timer:approved:0001', 102,
        export_config(['claim_enabled' => false]),
    ),
    'claiming is disabled',
);
export_check('closed claim gate leaves zero durable claims',
    (int) $pdo->query('SELECT COUNT(*) FROM coastmark_time_export_claims')->fetchColumn() === 0);

$base = coastmark_time_export_claim(
    $pdo, '8west', 501, 'timer:approved:0001', 102, $config,
    'safeharbor-time:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
);
export_check('original approval is claimed once with exact v3 field order',
    !$base['replayed']
    && (int) $base['claim']['source_version'] === 0
    && array_keys($base['payload']) === COASTMARK_TIME_EXPORT_FIELDS
    && $base['payload']['event'] === 'safeharbor.time_entry.approved'
    && $base['payload']['predecessor_event_key'] === null);
export_check('claim excludes raw notes and hard-pins their digest',
    !in_array('=private technician note', $base['payload'], true)
    && $base['payload']['note_sha256'] === hash('sha256', '=private technician note'));
$baseReplay = coastmark_time_export_claim(
    $pdo, '8west', 501, 'timer:approved:0001', 103, $config,
    'safeharbor-time:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
);
export_check('exact claim replay is a no-op with the original event key',
    $baseReplay['replayed']
    && (int) $baseReplay['claim']['id'] === (int) $base['claim']['id']
    && $baseReplay['claim']['event_key'] === $base['claim']['event_key']
    && (int) $pdo->query('SELECT COUNT(*) FROM coastmark_time_export_claims')->fetchColumn() === 1);

export_expect(
    '8 West IT master is hard-blocked even when allowlisted',
    CoastmarkTimeExportValidationException::class,
    fn() => coastmark_time_export_claim(
        $pdo, '8west', 505, 'timer:master:00005', 102,
        export_config(['client_keys' => [
            'milepost-customer:4ebaeefa-b101-47f8-ac76-e49ab309d272',
        ]]),
    ),
    'never a billable Coastmark customer',
);

$pdo->exec("INSERT INTO time_entry_approval_adjustments
  (tenant_id,time_entry_id,adjustment_key,version_no,effective_minutes,effective_billable,
   reason,actor_user_id,created_at) VALUES
  (1,501,'adjustment.coastmark.0001',1,15,1,'Corrected duration',103,'2026-08-26 20:10:00')");
$pdo->exec("UPDATE suite_customer_sync_bindings SET status='inactive' WHERE tenant_id=1 AND client_id=11");
export_expect(
    'correction claims fail closed when the customer binding is disabled',
    CoastmarkTimeExportValidationException::class,
    fn() => coastmark_time_export_claim(
        $pdo, '8west', 501, 'timer:approved:0001', 102, $config,
        'safeharbor-time:cccccccccccccccccccccccccccccccc',
    ),
    'not active',
);
export_check('disabled customer binding leaves the claim chain unchanged',
    (int) $pdo->query('SELECT COUNT(*) FROM coastmark_time_export_claims')->fetchColumn() === 1);
$pdo->exec("UPDATE suite_customer_sync_bindings SET status='active' WHERE tenant_id=1 AND client_id=11");
$correction = coastmark_time_export_claim(
    $pdo, '8west', 501, 'timer:approved:0001', 102, $config,
    'safeharbor-time:cccccccccccccccccccccccccccccccc',
);
export_check('correction claim forms the exact next immutable chain link',
    !$correction['replayed']
    && $correction['payload']['event'] === 'safeharbor.time_entry.adjusted'
    && $correction['payload']['source_version'] === 1
    && $correction['payload']['predecessor_event_key'] === $base['claim']['event_key']
    && $correction['payload']['minutes'] === 15
    && $correction['payload']['adjusted_by_key'] === 'safeharbor-user:103'
    && $correction['payload']['adjustment_reason_sha256'] === hash('sha256', 'Corrected duration'));

$baseCreatedAck = json_decode(export_ack($base['claim']), true, flags: JSON_THROW_ON_ERROR);
$baseReplayAck = json_decode(export_ack($base['claim'], 'ignored'), true, flags: JSON_THROW_ON_ERROR);
$correctionAck = json_decode(
    export_ack($correction['claim'], 'corrected'),
    true,
    flags: JSON_THROW_ON_ERROR,
);
$correctionReplayAck = json_decode(
    export_ack($correction['claim'], 'ignored'),
    true,
    flags: JSON_THROW_ON_ERROR,
);
export_check('acknowledgements bind new, replay, and status actions to the claim version',
    coastmark_time_export_validate_ack($base['claim'], $baseCreatedAck, 201, false)['action'] === 'created'
    && coastmark_time_export_validate_ack($base['claim'], $baseReplayAck, 200, false)['action'] === 'ignored'
    && coastmark_time_export_validate_ack($base['claim'], $baseCreatedAck, 200, true)['action'] === 'created'
    && coastmark_time_export_validate_ack($correction['claim'], $correctionAck, 201, false)['action'] === 'corrected'
    && coastmark_time_export_validate_ack($correction['claim'], $correctionReplayAck, 200, false)['action'] === 'ignored'
    && coastmark_time_export_validate_ack($correction['claim'], $correctionAck, 200, true)['action'] === 'corrected');
export_expect(
    'an original claim cannot accept a correction-only manual exception',
    CoastmarkTimeExportAmbiguousException::class,
    fn() => coastmark_time_export_validate_ack(
        $base['claim'],
        json_decode(export_ack($base['claim'], 'manual_exception'), true, flags: JSON_THROW_ON_ERROR),
        201,
        false,
    ),
    'does not match',
);
export_expect(
    'a correction claim cannot accept an original created action',
    CoastmarkTimeExportAmbiguousException::class,
    fn() => coastmark_time_export_validate_ack(
        $correction['claim'],
        json_decode(export_ack($correction['claim'], 'created'), true, flags: JSON_THROW_ON_ERROR),
        201,
        false,
    ),
    'does not match',
);
export_expect(
    'a new-event response cannot call itself an exact replay',
    CoastmarkTimeExportAmbiguousException::class,
    fn() => coastmark_time_export_validate_ack(
        $correction['claim'],
        $correctionReplayAck,
        201,
        false,
    ),
    'does not match',
);
export_expect(
    'a replay response cannot call itself a newly created event',
    CoastmarkTimeExportAmbiguousException::class,
    fn() => coastmark_time_export_validate_ack(
        $base['claim'],
        $baseCreatedAck,
        200,
        false,
    ),
    'does not match',
);
export_expect(
    'an original approval replay must retain its draft line identifier',
    CoastmarkTimeExportAmbiguousException::class,
    fn() => coastmark_time_export_validate_ack(
        $base['claim'],
        json_decode(export_ack($base['claim'], 'ignored', 91, null), true, flags: JSON_THROW_ON_ERROR),
        200,
        false,
    ),
    'does not match',
);
export_expect(
    'status accepts only the exact recovered disposition at HTTP 200',
    CoastmarkTimeExportAmbiguousException::class,
    fn() => coastmark_time_export_validate_ack(
        $correction['claim'],
        $correctionReplayAck,
        200,
        true,
    ),
    'does not match',
);
export_expect(
    'status refuses a success body delivered with a new-event status code',
    CoastmarkTimeExportAmbiguousException::class,
    fn() => coastmark_time_export_validate_ack(
        $correction['claim'],
        $correctionAck,
        201,
        true,
    ),
    'does not match',
);
$wrongAckClaim = coastmark_time_export_claim(
    $pdo, '8west', 504, 'timer:wrongack:0004', 102, $config,
    'safeharbor-time:11111111111111111111111111111111',
);
export_expect(
    'a mismatched receiver disposition becomes recoverable ambiguity, not terminal success',
    CoastmarkTimeExportAmbiguousException::class,
    fn() => coastmark_time_export_send_claim(
        $pdo,
        (int) $wrongAckClaim['claim']['id'],
        $config,
        fn() => [
            'status' => 201,
            'body' => export_ack($wrongAckClaim['claim'], 'manual_exception'),
        ],
        1_777_777_776,
    ),
    'does not match',
);
export_check('mismatched receiver disposition is recorded as explicit ambiguous evidence',
    (string) $pdo->query('SELECT outcome FROM coastmark_time_export_receipts WHERE claim_id=' .
        (int) $wrongAckClaim['claim']['id'] . ' ORDER BY id DESC LIMIT 1')->fetchColumn() === 'ambiguous'
    && (string) $pdo->query('SELECT detail_code FROM coastmark_time_export_receipts WHERE claim_id=' .
        (int) $wrongAckClaim['claim']['id'] . ' ORDER BY id DESC LIMIT 1')->fetchColumn()
        === 'ack_did_not_match_claim');

$sendCalls = 0;
$send = coastmark_time_export_send_claim(
    $pdo,
    (int) $base['claim']['id'],
    $config,
    function (string $url, array $headers, string $body, int $timeout) use (&$sendCalls, $base): array {
        $sendCalls++;
        return ['status' => 201, 'body' => export_ack($base['claim'])];
    },
    1_777_777_777,
);
export_check('accepted base event records a durable acknowledgement only once',
    $send['outcome'] === 'accepted' && $send['coastmark_event_id'] === 91 && $sendCalls === 1);
$sendReplay = coastmark_time_export_send_claim(
    $pdo,
    (int) $base['claim']['id'],
    $config,
    function () use (&$sendCalls): array {
        $sendCalls++;
        return ['status' => 500, 'body' => 'must not run'];
    },
    1_777_777_778,
);
export_check('accepted claim replay makes no second network request',
    $sendReplay['outcome'] === 'accepted' && $sendCalls === 1);

$remoteCommittedAck = export_ack($correction['claim'], 'corrected', 92);
export_expect(
    'timeout after remote commit becomes ambiguous without automatic retry',
    CoastmarkTimeExportAmbiguousException::class,
    fn() => coastmark_time_export_send_claim(
        $pdo,
        (int) $correction['claim']['id'],
        $config,
        function () use ($remoteCommittedAck): array {
            // The remote acknowledgement exists, but the response is lost.
            throw new RuntimeException('simulated timeout after commit');
        },
        1_777_777_779,
    ),
    'Run status',
);
export_expect(
    'ambiguous claim cannot be explicitly resent before status',
    CoastmarkTimeExportAmbiguousException::class,
    fn() => coastmark_time_export_send_claim(
        $pdo, (int) $correction['claim']['id'], $config,
        fn() => ['status' => 500, 'body' => 'must not run'],
        1_777_777_780,
    ),
    'Run status',
);
export_expect(
    'status transport helper cannot bypass the serialization lock',
    LogicException::class,
    fn() => coastmark_time_export_status_claim_locked(
        $pdo,
        (int) $correction['claim']['id'],
        coastmark_time_export_connection($config),
        fn() => ['status' => 500, 'body' => 'must not run'],
        null,
    ),
    'does not own',
);
$statusCalls = 0;
$resolved = coastmark_time_export_status_claim(
    $pdo,
    (int) $correction['claim']['id'],
    $config,
    function (string $url, array $headers, string $body, int $timeout) use (
        &$statusCalls, $remoteCommittedAck,
    ): array {
        $statusCalls++;
        export_check('status uses its dedicated endpoint without event replay',
            str_ends_with($url, '/api/integrations/safeharbor/time-events/status')
            && str_contains($body, 'safeharbor.time_entry.status'));
        return ['status' => 200, 'body' => $remoteCommittedAck];
    },
    1_777_777_781,
);
export_check('status resolves timeout-after-commit to the immutable Coastmark event',
    $resolved['outcome'] === 'accepted'
    && $resolved['coastmark_event_id'] === 92
    && $statusCalls === 1);

$pdo->exec("INSERT INTO time_entry_approval_adjustments
  (tenant_id,time_entry_id,adjustment_key,version_no,effective_minutes,effective_billable,
   reason,actor_user_id,created_at) VALUES
  (1,501,'adjustment.coastmark.0002',2,0,0,'Remove from billing',102,'2026-08-26 20:15:00')");
$reversal = coastmark_time_export_claim(
    $pdo, '8west', 501, 'timer:approved:0001', 102, $config,
    'safeharbor-time:dddddddddddddddddddddddddddddddd',
);
export_check('nonbillable correction is represented as an explicit reversal event',
    $reversal['payload']['source_version'] === 2
    && $reversal['payload']['minutes'] === 0
    && $reversal['payload']['billable'] === false
    && $reversal['payload']['predecessor_event_key'] === $correction['claim']['event_key']);
$manual = coastmark_time_export_send_claim(
    $pdo,
    (int) $reversal['claim']['id'],
    $config,
    fn() => [
        'status' => 201,
        'body' => export_ack($reversal['claim'], 'manual_exception', 93),
    ],
    1_777_777_782,
);
export_check('posted-invoice response records manual exception with no draft line',
    $manual['outcome'] === 'manual_exception'
    && $manual['invoice_id'] === 81
    && $manual['invoice_line_id'] === null);

$pdo->exec("INSERT INTO time_entry_approval_adjustments
  (tenant_id,time_entry_id,adjustment_key,version_no,effective_minutes,effective_billable,
   reason,actor_user_id,created_at) VALUES
  (1,501,'adjustment.coastmark.0003',3,5,0,'Keep internal only',103,'2026-08-26 20:20:00')");
$manualReplayClaim = coastmark_time_export_claim(
    $pdo, '8west', 501, 'timer:approved:0001', 102, $config,
    'safeharbor-time:eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',
);
$manualReplay = coastmark_time_export_send_claim(
    $pdo,
    (int) $manualReplayClaim['claim']['id'],
    $config,
    fn() => [
        'status' => 200,
        'body' => export_ack($manualReplayClaim['claim'], 'ignored', 94, null),
    ],
    1_777_777_783,
);
export_check('exact replay of a manual exception accepts a null line id',
    $manualReplay['outcome'] === 'replayed'
    && $manualReplay['coastmark_event_id'] === 94
    && $manualReplay['invoice_line_id'] === null);

$inflight = coastmark_time_export_claim(
    $pdo, '8west', 502, 'timer:inflight:0002', 102, $config,
    'safeharbor-time:ffffffffffffffffffffffffffffffff',
);
coastmark_time_export_begin_operation($pdo, (int) $inflight['claim']['id'], 'dispatch');
export_expect(
    'status waits until a potentially in-flight dispatch has settled',
    CoastmarkTimeExportAmbiguousException::class,
    fn() => coastmark_time_export_status_claim(
        $pdo,
        (int) $inflight['claim']['id'],
        $config,
        fn() => throw new LogicException('Status transport must not run yet.'),
    ),
    'still be in flight',
);
$pdo->exec("UPDATE coastmark_time_export_receipts
  SET created_at='2000-01-01 00:00:00'
  WHERE claim_id=" . (int) $inflight['claim']['id'] . " AND outcome='dispatching'");
$nestedStatusTransportCalls = 0;
$serializedStatus = coastmark_time_export_status_claim(
    $pdo,
    (int) $inflight['claim']['id'],
    $config,
    function () use ($pdo, $inflight, $config, &$nestedStatusTransportCalls): array {
        $pdo->exec("UPDATE coastmark_time_export_receipts
          SET created_at='2000-01-01 00:00:00'
          WHERE claim_id=" . (int) $inflight['claim']['id'] . " AND outcome='checking'");
        export_expect(
            'a second status check cannot take over even when checking evidence looks stale',
            CoastmarkTimeExportAmbiguousException::class,
            fn() => coastmark_time_export_status_claim(
                $pdo,
                (int) $inflight['claim']['id'],
                $config,
                function () use (&$nestedStatusTransportCalls): array {
                    $nestedStatusTransportCalls++;
                    return ['status' => 500, 'body' => 'must not run'];
                },
            ),
            'already in flight',
        );
        return ['status' => 200, 'body' => export_ack($inflight['claim'], 'created', 96)];
    },
);
export_check('serialized status retains the first valid recovery acknowledgement',
    $serializedStatus['outcome'] === 'accepted'
    && $serializedStatus['coastmark_event_id'] === 96
    && $nestedStatusTransportCalls === 0
    && (int) $pdo->query("SELECT COUNT(*) FROM coastmark_time_export_receipts
         WHERE claim_id=" . (int) $inflight['claim']['id'] . " AND operation_kind='status_started'")
        ->fetchColumn() === 1
    && (string) $pdo->query("SELECT outcome FROM coastmark_time_export_receipts
         WHERE claim_id=" . (int) $inflight['claim']['id'] . ' ORDER BY id DESC LIMIT 1')
        ->fetchColumn() === 'accepted');

$absentClaim = coastmark_time_export_claim(
    $pdo, '8west', 503, 'timer:false404:0003', 102, $config,
    'safeharbor-time:0123456789abcdef0123456789abcdef',
);
export_expect(
    'an arbitrary 404 cannot unlock an explicit resend',
    CoastmarkTimeExportAmbiguousException::class,
    fn() => coastmark_time_export_status_claim(
        $pdo,
        (int) $absentClaim['claim']['id'],
        $config,
        fn() => ['status' => 404, 'body' => '<h1>route unavailable</h1>'],
    ),
    'did not prove',
);
$absent = coastmark_time_export_status_claim(
    $pdo,
    (int) $absentClaim['claim']['id'],
    $config,
    fn() => [
        'status' => 404,
        'body' => json_encode(['ok' => false, 'action' => 'absent'], JSON_THROW_ON_ERROR),
    ],
);
export_check('only the exact signed-status absence shape permits a later resend',
    $absent['outcome'] === 'absent');
$afterAbsent = coastmark_time_export_send_claim(
    $pdo,
    (int) $absentClaim['claim']['id'],
    $config,
    fn() => ['status' => 201, 'body' => export_ack($absentClaim['claim'], 'created', 95)],
);
export_check('an explicit send is possible after exact absence evidence',
    $afterAbsent['outcome'] === 'accepted' && $afterAbsent['coastmark_event_id'] === 95);

export_check('receipt history is append-only evidence for starts and outcomes',
    (int) $pdo->query('SELECT COUNT(*) FROM coastmark_time_export_receipts')->fetchColumn() >= 8
    && (int) $pdo->query("SELECT COUNT(*) FROM coastmark_time_export_receipts WHERE outcome='ambiguous'")->fetchColumn() === 3);

$migration = file_get_contents(__DIR__ . '/../db/migrations/021_coastmark_time_export_v3.sql');
$schema = file_get_contents(__DIR__ . '/../db/schema.sql');
$cli = file_get_contents(__DIR__ . '/../db/export_approved_time.php');
$preflightMarker = '-- CREATE TABLE IF NOT EXISTS is only a convenience for a fresh install.';
$migrationPreflightStart = is_string($migration) ? strpos($migration, $preflightMarker) : false;
$migrationPreflightEnd = is_string($migration)
    ? strpos($migration, '-- Fail closed while permanent triggers', (int) $migrationPreflightStart)
    : false;
$schemaPreflightStart = is_string($schema) ? strpos($schema, $preflightMarker) : false;
$schemaPreflightEnd = is_string($schema)
    ? strpos($schema, 'DROP TRIGGER IF EXISTS trg_cm_export_claim_before_insert', (int) $schemaPreflightStart)
    : false;
$migrationPreflight = $migrationPreflightStart !== false && $migrationPreflightEnd !== false
    ? substr($migration, $migrationPreflightStart, $migrationPreflightEnd - $migrationPreflightStart)
    : null;
$schemaPreflight = $schemaPreflightStart !== false && $schemaPreflightEnd !== false
    ? substr($schema, $schemaPreflightStart, $schemaPreflightEnd - $schemaPreflightStart)
    : null;
$schemaPreflight = is_string($schemaPreflight)
    ? str_replace('cm_export_schema_statement', 'cm_export_statement', $schemaPreflight)
    : null;
export_check('migration and canonical schema carry both tables and six immutable guards',
    is_string($migration) && is_string($schema)
    && str_contains($migration, 'CREATE TABLE IF NOT EXISTS coastmark_time_export_claims')
    && str_contains($migration, 'CREATE TABLE IF NOT EXISTS coastmark_time_export_receipts')
    && str_contains($migration, 'payload_json         LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL')
    && str_contains($migration, "parent_billable <> 1")
    && str_contains($migration, 'Export claims require an active matching customer binding')
    && str_contains($migration, "IS_USED_LOCK(CONCAT('safeharbor:cm-status:',NEW.claim_id))")
    && str_contains($migration, 'INTERVAL 35 SECOND')
    && str_contains($schema, '@cm_export_claim_install_lock_ddl = IF(')
    && str_contains($schema, '@cm_export_receipt_install_lock_ddl = IF(')
    && substr_count($schema, "'DO 0'") >= 2
    && substr_count($schema, 'CREATE TRIGGER trg_cm_export_claim_') === 3
    && substr_count($schema, 'CREATE TRIGGER trg_cm_export_receipt_') === 3);
export_check('migration and canonical schema share the exact financial-table preflight',
    is_string($migrationPreflight)
    && $migrationPreflight === $schemaPreflight
    && str_contains($migrationPreflight, '@cm_claim_table_ok')
    && str_contains($migrationPreflight, '@cm_receipt_columns_ok')
    && str_contains($migrationPreflight, '@cm_claim_indexes_ok')
    && str_contains($migrationPreflight, '@cm_receipt_fks_ok')
    && str_contains($migrationPreflight, '@cm_claim_checks_ok')
    && str_contains($migrationPreflight, 'migration_021_coastmark_export_preflight_failed'));
export_check('operator CLI has one-entry claim/send/status only and no batch or retry mode',
    is_string($cli)
    && str_contains($cli, "['claim', 'send', 'status', 'inspect-claim']")
    && str_contains($cli, 'coastmark_time_export_database')
    && str_contains($cli, '$exportDb')
    && !str_contains($cli, '--batch')
    && !str_contains($cli, '--retry'));

echo "Coastmark approved-time v3 export: {$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
