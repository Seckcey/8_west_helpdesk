<?php
/** Hermetic contract, metric, archive, scheduling, and delivery coverage. */
declare(strict_types=1);

require_once __DIR__ . '/../lib/business_reports.php';

$checks = 0;
$failures = 0;

function report_check(string $name, bool $condition): void
{
    global $checks, $failures;
    $checks++;
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "FAIL {$checks}: {$name}\n");
    }
}

/** @param class-string<Throwable> $expected */
function report_throws(string $name, string $expected, callable $operation, string $fragment = ''): void
{
    try {
        $operation();
        report_check($name, false);
    } catch (Throwable $error) {
        report_check(
            $name,
            $error instanceof $expected
                && ($fragment === '' || str_contains($error->getMessage(), $fragment)),
        );
    }
}

/** @return array<string,mixed> */
function report_config(array $changes = []): array
{
    return array_replace([
        'generation_enabled' => true,
        'delivery_enabled' => true,
        'canary_only' => true,
        'tenant_slugs' => ['one'],
        'client_keys' => ['safeharbor-client:11'],
        'recipient_emails' => ['reports@example.test'],
        'lease_seconds' => 120,
    ], $changes);
}

/** @return array<string,mixed> */
function report_archive_fixture(PDO $pdo, int $scheduleId, string $scheduleKey, string $periodStart): array
{
    $scheduleQuery = $pdo->prepare(
        "SELECT s.*, t.slug AS tenant_slug, c.name AS client_name,
                d.definition_key, d.version_no AS definition_version_no,
                d.report_type, d.contract_sha256
           FROM business_report_schedule_versions s
           JOIN tenants t ON t.id = s.tenant_id
           JOIN clients c ON c.tenant_id = s.tenant_id AND c.id = s.client_id
           JOIN business_report_definition_versions d
             ON d.tenant_id = s.tenant_id AND d.id = s.definition_version_id
          WHERE s.id = ?"
    );
    $scheduleQuery->execute([$scheduleId]);
    $schedule = $scheduleQuery->fetch(PDO::FETCH_ASSOC);
    if (!is_array($schedule)) throw new RuntimeException('fixture schedule missing');
    $start = new DateTimeImmutable($periodStart, new DateTimeZone('UTC'));
    $end = $start->modify('+7 days');
    $metrics = [
        'schema_version' => 1,
        'report_type' => BUSINESS_REPORT_TYPE,
        'definition' => [
            'key' => BUSINESS_REPORT_DEFINITION_KEY,
            'version' => 1,
            'sha256' => business_report_contract_sha256(),
        ],
        'source' => [
            'tenant_key' => (string)$schedule['tenant_slug'],
            'client_key' => 'safeharbor-client:' . (int)$schedule['client_id'],
            'client_name' => (string)$schedule['client_name'],
        ],
        'period' => [
            'start_utc' => $start->format('Y-m-d\TH:i:s\Z'),
            'end_utc_exclusive' => $end->format('Y-m-d\TH:i:s\Z'),
            'schedule_timezone' => (string)$schedule['schedule_timezone'],
        ],
        'generated_at' => '2026-08-26T12:00:00Z',
        'tickets' => ['opened' => 0, 'resolved' => 0, 'merged_histories_excluded_from_response_metrics' => 0],
        'first_response' => ['answered' => 0, 'average_minutes' => null],
        'service_goal' => [
            'eligible_versioned' => 0, 'legacy_unversioned_excluded' => 0,
            'decided' => 0, 'met' => 0, 'attainment_percent' => null, 'undecided' => 0,
        ],
        'approved_billable_time' => [
            'minutes' => 0,
            'classification' => 'operational_approval_evidence_not_financial_status',
        ],
        'csat' => ['surveys_sent' => 0, 'responses_received_by_generated_at' => 0, 'average_score_out_of_3' => null],
        'delivery_truth' => 'A provider acceptance is submission evidence, not inbox delivery proof.',
    ];
    $text = business_report_text($metrics);
    $json = business_report_metrics_json($metrics);
    $hash = business_report_content_sha256_from_json($json, $text);
    $insert = $pdo->prepare(
        'INSERT INTO business_report_archives
            (tenant_id,client_id,schedule_key,schedule_version_id,definition_version_id,
             period_start,period_end,generated_at,metrics_json,report_text,content_sha256)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)'
    );
    $insert->execute([
        (int)$schedule['tenant_id'], (int)$schedule['client_id'], $scheduleKey,
        $scheduleId, (int)$schedule['definition_version_id'],
        $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'),
        '2026-08-26 12:00:00', $json, $text, $hash,
    ]);
    $archiveId = (int)$pdo->lastInsertId();
    $delivery = $pdo->prepare(
        "INSERT INTO business_report_deliveries
            (tenant_id,archive_id,schedule_version_id,recipient_email,status)
         VALUES (?,?,?,?,'pending')"
    );
    $delivery->execute([
        (int)$schedule['tenant_id'], $archiveId, $scheduleId, (string)$schedule['recipient_email'],
    ]);
    $archive = $pdo->query("SELECT * FROM business_report_archives WHERE id = {$archiveId}")->fetch(PDO::FETCH_ASSOC);
    return is_array($archive) ? $archive : [];
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');
$schema = [
    'CREATE TABLE tenants (id INTEGER PRIMARY KEY, name TEXT NOT NULL, slug TEXT NOT NULL UNIQUE)',
    'CREATE TABLE users (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, role TEXT NOT NULL, is_active INTEGER NOT NULL)',
    'CREATE TABLE clients (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name TEXT NOT NULL, UNIQUE(tenant_id,id))',
    'CREATE TABLE business_report_definition_versions (
        id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
        definition_key TEXT NOT NULL, version_no INTEGER NOT NULL, report_type TEXT NOT NULL,
        contract_json TEXT NOT NULL, contract_sha256 TEXT NOT NULL,
        created_by_user_id INTEGER NOT NULL, reason TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
    'CREATE TABLE business_report_id_tenant_bindings (
        tenant_id INTEGER PRIMARY KEY, id_tenant_key TEXT NOT NULL UNIQUE,
        id_tenant_slug TEXT NOT NULL, created_by_user_id INTEGER NOT NULL,
        reason TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(tenant_id,id_tenant_key))',
    'CREATE TABLE business_report_id_contact_snapshots (
        id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
        schedule_version_id INTEGER NOT NULL, id_tenant_key TEXT NOT NULL,
        contact_version INTEGER NOT NULL, recipient_email TEXT NOT NULL,
        response_generated_at TEXT NOT NULL, request_nonce_sha256 TEXT NOT NULL,
        response_sha256 TEXT NOT NULL, created_by_user_id INTEGER NOT NULL,
        reason TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(tenant_id,id), UNIQUE(tenant_id,schedule_version_id))',
    'CREATE TABLE business_report_archives (
        id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, client_id INTEGER NOT NULL,
        schedule_key TEXT NOT NULL, schedule_version_id INTEGER NOT NULL,
        definition_version_id INTEGER NOT NULL, period_start TEXT NOT NULL, period_end TEXT NOT NULL,
        generated_at TEXT NOT NULL, metrics_json TEXT NOT NULL, report_text TEXT NOT NULL,
        content_sha256 TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(tenant_id,schedule_key,period_start,period_end), UNIQUE(tenant_id,id))',
    'CREATE TABLE business_report_deliveries (
        id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, archive_id INTEGER NOT NULL,
        schedule_version_id INTEGER NOT NULL, recipient_email TEXT NOT NULL, status TEXT NOT NULL,
        lease_token_hash TEXT NULL, lease_expires_at TEXT NULL, last_attempt_at TEXT NULL,
        submitted_at TEXT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(tenant_id,archive_id), UNIQUE(tenant_id,id))',
    'CREATE TABLE business_report_delivery_attempts (
        id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, delivery_id INTEGER NOT NULL,
        attempt_key TEXT NOT NULL, provider TEXT NOT NULL, status TEXT NOT NULL,
        started_at TEXT NOT NULL, completed_at TEXT NULL, provider_http INTEGER NULL,
        outcome_code TEXT NULL, UNIQUE(tenant_id,delivery_id), UNIQUE(tenant_id,attempt_key))',
    'CREATE TABLE tickets (
        id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, client_id INTEGER NOT NULL,
        subject TEXT NOT NULL, status TEXT NOT NULL, sla_due_at TEXT NOT NULL,
        service_goal_target_id INTEGER NULL, merged_into_id INTEGER NULL,
        created_at TEXT NOT NULL, resolved_at TEXT NULL)',
    'CREATE TABLE messages (
        id INTEGER PRIMARY KEY, ticket_id INTEGER NOT NULL, kind TEXT NOT NULL,
        body TEXT NOT NULL, created_at TEXT NOT NULL)',
    'CREATE TABLE time_entries (
        id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, client_id INTEGER NOT NULL,
        minutes INTEGER NOT NULL, note TEXT NOT NULL, billable INTEGER NOT NULL,
        approval_status TEXT NOT NULL, worked_at TEXT NOT NULL, reviewed_at TEXT NULL)',
    'CREATE TABLE csat (
        id INTEGER PRIMARY KEY, ticket_id INTEGER NOT NULL, score INTEGER NULL,
        comment TEXT NOT NULL, created_at TEXT NOT NULL, responded_at TEXT NULL)',
];
foreach ($schema as $statement) $pdo->exec($statement);

$pdo->exec("INSERT INTO tenants VALUES (1,'Tenant One','one'),(2,'Tenant Two','two')");
$pdo->exec("INSERT INTO clients VALUES (11,1,'Client One'),(12,1,'Client Twelve'),(22,2,'Client Two')");
$pdo->exec("INSERT INTO users VALUES
    (101,1,'owner',1),(102,1,'admin',1),(103,1,'tech',1),(104,1,'admin',0),(201,2,'owner',1)");

report_check('default configuration is fully inert', business_report_config([]) === [
    'generation_enabled' => false,
    'delivery_enabled' => false,
    'canary_only' => true,
    'tenant_slugs' => [],
    'client_keys' => [],
    'recipient_emails' => [],
    'lease_seconds' => 120,
]);
report_throws(
    'duplicate allowlist values fail closed',
    BusinessReportValidationException::class,
    fn() => business_report_config(report_config(['tenant_slugs' => ['one', 'one']])),
    'duplicate',
);
report_throws(
    'tenant allowlist values must be exact slugs',
    BusinessReportValidationException::class,
    fn() => business_report_config(report_config(['tenant_slugs' => ['Tenant One']])),
    'slug',
);
report_throws(
    'client allowlist values must be stable Safeharbor keys',
    BusinessReportValidationException::class,
    fn() => business_report_config(report_config(['client_keys' => ['Client One']])),
    'client key',
);
report_throws(
    'only owner or admin can publish a definition',
    BusinessReportGateException::class,
    fn() => business_report_publish_definition($pdo, 'one', 103, 'not allowed'),
    'owner or admin',
);
$definition = business_report_publish_definition($pdo, 'one', 101, 'initial contract');
report_check('definition v1 publishes exact immutable bytes',
    $definition['action'] === 'created'
    && (int)$definition['definition']['version_no'] === 1
    && hash_equals(
        (string)$definition['definition']['contract_sha256'],
        hash('sha256', (string)$definition['definition']['contract_json']),
    ));
report_check('definition publication is idempotent',
    business_report_publish_definition($pdo, 'one', 102, 'same contract')['action'] === 'ignored');

$prepared = business_report_prepare_schedule(
    $pdo, 'one', 'client-one-weekly', 11, (int)$definition['definition']['id'],
    'reports@example.test', 'UTC', 3, '09:00:00', true, 101, 'prepare canary',
);
report_check('new schedules start disabled',
    $prepared['action'] === 'prepared'
    && $prepared['schedule']['status'] === 'disabled'
    && (int)$prepared['schedule']['version_no'] === 1);

$idContact = [
    'tenant_key' => 'ewid-t1',
    'tenant_slug' => 'one',
    'contact_version' => 7,
    'recipient_email' => 'id-reports@example.test',
    'generated_at' => '2026-08-27T12:00:00Z',
    'generated_at_db' => '2026-08-27 12:00:00',
    'request_nonce_sha256' => str_repeat('a', 64),
    'response_sha256' => str_repeat('b', 64),
];
$idPrepared = business_report_prepare_schedule_from_id(
    $pdo, 'one', 'id-client-weekly', 11, (int)$definition['definition']['id'],
    $idContact, 'UTC', 3, '09:00:00', true, 101, 'ID-backed canary',
);
report_check(
    'ID-backed prepare atomically pins the disabled schedule and contact evidence',
    $idPrepared['action'] === 'prepared'
        && (int)$idPrepared['schedule']['version_no'] === 1
        && $idPrepared['schedule']['status'] === 'disabled'
        && (string)$idPrepared['id_contact']['id_tenant_key'] === 'ewid-t1'
        && (int)$idPrepared['id_contact']['contact_version'] === 7
        && (int)$pdo->query('SELECT COUNT(*) FROM business_report_id_tenant_bindings')->fetchColumn() === 1
        && (int)$pdo->query('SELECT COUNT(*) FROM business_report_id_contact_snapshots')->fetchColumn() === 1,
);
$replayContact = array_replace($idContact, [
    'request_nonce_sha256' => str_repeat('c', 64),
    'response_sha256' => str_repeat('d', 64),
]);
$idReplay = business_report_prepare_schedule_from_id(
    $pdo, 'one', 'id-client-weekly', 11, (int)$definition['definition']['id'],
    $replayContact, 'UTC', 3, '09:00:00', true, 101, 'exact replay',
);
report_check(
    'exact ID-backed prepare replay is ignored without duplicating evidence',
    $idReplay['action'] === 'ignored'
        && (int)$idReplay['schedule']['id'] === (int)$idPrepared['schedule']['id']
        && (int)$pdo->query('SELECT COUNT(*) FROM business_report_id_contact_snapshots')->fetchColumn() === 1,
);
report_throws(
    'one ID contact version cannot name two recipients',
    BusinessReportConflictException::class,
    fn() => business_report_prepare_schedule_from_id(
        $pdo, 'one', 'id-client-weekly', 11, (int)$definition['definition']['id'],
        array_replace($idContact, ['recipient_email' => 'changed@example.test']),
        'UTC', 3, '10:00:00', true, 101, 'conflicting snapshot',
    ),
    'different recipient',
);
$newIdContact = array_replace($idContact, [
    'contact_version' => 8,
    'recipient_email' => 'new-id-reports@example.test',
    'request_nonce_sha256' => str_repeat('e', 64),
    'response_sha256' => str_repeat('f', 64),
]);
$idPreparedV2 = business_report_prepare_schedule_from_id(
    $pdo, 'one', 'id-client-weekly', 11, (int)$definition['definition']['id'],
    $newIdContact, 'UTC', 3, '10:00:00', true, 102, 'contact version eight',
);
report_check(
    'a newer ID contact appends a new disabled schedule and evidence version',
    $idPreparedV2['action'] === 'prepared'
        && (int)$idPreparedV2['schedule']['version_no'] === 2
        && (int)$idPreparedV2['id_contact']['contact_version'] === 8
        && (int)$pdo->query('SELECT COUNT(*) FROM business_report_id_contact_snapshots')->fetchColumn() === 2,
);
report_throws(
    'ID contact evidence cannot roll back to an older version',
    BusinessReportConflictException::class,
    fn() => business_report_prepare_schedule_from_id(
        $pdo, 'one', 'id-client-weekly', 11, (int)$definition['definition']['id'],
        $idContact, 'UTC', 3, '11:00:00', true, 101, 'version rollback',
    ),
    'backward',
);
report_throws(
    'a local tenant cannot be rebound to another ID tenant key',
    BusinessReportConflictException::class,
    fn() => business_report_prepare_schedule_from_id(
        $pdo, 'one', 'other-id-weekly', 11, (int)$definition['definition']['id'],
        array_replace($newIdContact, ['tenant_key' => 'ewid-t2']),
        'UTC', 3, '09:00:00', true, 101, 'wrong binding',
    ),
    'different 8 West ID tenant',
);
report_throws(
    'schedule enable requires exact configuration allowlists',
    BusinessReportGateException::class,
    fn() => business_report_transition_schedule(
        $pdo, 'one', 'client-one-weekly', 1, 'active', 101, 'bad enable', report_config(['recipient_emails' => []]),
    ),
    'allowlisted',
);
$enabled = business_report_transition_schedule(
    $pdo, 'one', 'client-one-weekly', 1, 'active', 101, 'enable canary', report_config(),
);
report_check('enable appends a new active version',
    $enabled['action'] === 'enabled'
    && (int)$enabled['schedule']['version_no'] === 2
    && $enabled['schedule']['status'] === 'active');
report_throws(
    'active schedule must be disabled before reconfiguration',
    BusinessReportConflictException::class,
    fn() => business_report_prepare_schedule(
        $pdo, 'one', 'client-one-weekly', 11, (int)$definition['definition']['id'],
        'reports@example.test', 'UTC', 3, '10:00:00', true, 101, 'bad reconfigure',
    ),
    'Disable',
);

// Make the active version old enough to exercise two-period catch-up.
$pdo->exec("UPDATE business_report_schedule_versions
              SET created_at='2026-08-19 00:00:00' WHERE id=" . (int)$enabled['schedule']['id']);
$ticket = $pdo->prepare('INSERT INTO tickets VALUES (?,?,?,?,?,?,?,?,?,?)');
$ticket->execute([100,1,11,'private ticket subject A','open','2026-08-10 11:00:00',1,null,'2026-08-10 10:00:00',null]);
$ticket->execute([101,1,11,'private ticket subject B','open','2026-08-11 11:00:00',1,null,'2026-08-11 10:00:00',null]);
$ticket->execute([102,1,11,'legacy private subject','open','2026-08-12 11:00:00',null,null,'2026-08-12 10:00:00',null]);
$ticket->execute([103,1,11,'merged private source','open','2026-08-13 11:00:00',1,104,'2026-08-13 10:00:00',null]);
$ticket->execute([104,1,11,'merged private survivor','open','2026-08-13 11:00:00',1,null,'2026-08-13 09:00:00',null]);
$ticket->execute([105,1,11,'resolved private subject','resolved','2026-08-01 11:00:00',1,null,'2026-08-01 09:00:00','2026-08-14 12:00:00']);
$ticket->execute([106,1,11,'clock anomaly private subject','open','2026-08-15 11:00:00',1,null,'2026-08-15 10:00:00',null]);
$ticket->execute([200,2,22,'other tenant secret','open','2026-08-10 11:00:00',1,null,'2026-08-10 10:00:00',null]);
$message = $pdo->prepare('INSERT INTO messages VALUES (?,?,?,?,?)');
$message->execute([1,100,'tech','private response body','2026-08-10 10:30:00']);
$message->execute([2,101,'tech','future private response','2026-08-27 10:30:00']);
$message->execute([3,200,'tech','other tenant response','2026-08-10 10:10:00']);
$message->execute([4,106,'tech','invalid pre-created response','2026-08-15 09:00:00']);
$message->execute([5,106,'tech','valid response after anomaly','2026-08-15 10:45:00']);
$time = $pdo->prepare('INSERT INTO time_entries VALUES (?,?,?,?,?,?,?,?,?)');
$time->execute([1,1,11,60,'private approved note',1,'approved','2026-08-12 10:00:00','2026-08-12 11:00:00']);
$time->execute([2,1,11,15,'private pending note',1,'pending','2026-08-12 10:00:00',null]);
$time->execute([3,1,11,30,'private unbillable note',0,'approved','2026-08-12 10:00:00','2026-08-12 11:00:00']);
$time->execute([4,1,11,45,'private late approval',1,'approved','2026-08-12 10:00:00','2026-08-27 11:00:00']);
$time->execute([5,2,22,999,'other tenant note',1,'approved','2026-08-12 10:00:00','2026-08-12 11:00:00']);
$pdo->exec("INSERT INTO csat VALUES
    (1,100,3,'private praise','2026-08-14 10:00:00','2026-08-15 10:00:00'),
    (2,101,NULL,'','2026-08-14 11:00:00',NULL),
    (3,200,1,'other tenant','2026-08-14 10:00:00','2026-08-15 10:00:00')");

$now = strtotime('2026-08-26 12:00:00 UTC');
$dryRun = business_report_generate($pdo, 'one', 'client-one-weekly', report_config(), $now, true, false);
report_check('dry run writes no archive or delivery',
    $dryRun['action'] === 'dry_run'
    && (int)$pdo->query('SELECT COUNT(*) FROM business_report_archives')->fetchColumn() === 0
    && (int)$pdo->query('SELECT COUNT(*) FROM business_report_deliveries')->fetchColumn() === 0);
report_check('report window is exact start-inclusive end-exclusive UTC',
    $dryRun['archive']['period_start'] === '2026-08-10 00:00:00'
    && $dryRun['archive']['period_end'] === '2026-08-17 00:00:00');
report_check('ticket and first-response metrics exclude merge histories and future responses',
    $dryRun['metrics']['tickets']['opened'] === 5
    && $dryRun['metrics']['tickets']['resolved'] === 1
    && $dryRun['metrics']['tickets']['merged_histories_excluded_from_response_metrics'] === 2
    && $dryRun['metrics']['first_response']['answered'] === 2
    && $dryRun['metrics']['first_response']['average_minutes'] === 38);
report_check('versioned SLA denominator and legacy exclusion are truthful',
    $dryRun['metrics']['service_goal'] === [
        'eligible_versioned' => 3,
        'legacy_unversioned_excluded' => 1,
        'decided' => 3,
        'met' => 2,
        'attainment_percent' => 67,
        'undecided' => 0,
    ]);
report_check('only reviewed approved billable operational time is counted',
    $dryRun['metrics']['approved_billable_time']['minutes'] === 60
    && $dryRun['metrics']['approved_billable_time']['classification']
        === 'operational_approval_evidence_not_financial_status');
report_check('CSAT is tenant/client scoped and bounded by generated time',
    $dryRun['metrics']['csat']['surveys_sent'] === 2
    && $dryRun['metrics']['csat']['responses_received_by_generated_at'] === 1
    && $dryRun['metrics']['csat']['average_score_out_of_3'] === 3.0);
report_check('report text states denominator and financial/delivery limits',
    str_contains($dryRun['text'], 'tickets eligible: 3')
    && str_contains($dryRun['text'], 'Legacy tickets without a versioned goal excluded: 1')
    && str_contains($dryRun['text'], 'not a statement of export or invoice status')
    && str_contains($dryRun['text'], 'does not prove inbox delivery'));
foreach (['private ticket', 'private response', 'private approved', 'private praise', 'other tenant'] as $secret) {
    report_check("report excludes sensitive fixture text: {$secret}", !str_contains($dryRun['text'], $secret));
}

$generated = business_report_generate($pdo, 'one', 'client-one-weekly', report_config(), $now, false, true);
$archiveId = (int)$generated['archive']['id'];
$reloaded = $pdo->query("SELECT * FROM business_report_archives WHERE id={$archiveId}")->fetch(PDO::FETCH_ASSOC);
report_check('archive reload preserves exact JSON bytes and content hash',
    is_array($reloaded)
    && business_report_archived_content($reloaded)['metrics'] === $generated['metrics']
    && hash_equals(
        (string)$reloaded['content_sha256'],
        business_report_content_sha256_from_json((string)$reloaded['metrics_json'], (string)$reloaded['report_text']),
    ));
report_check('archive creates exactly one pending tracked delivery',
    (int)$pdo->query("SELECT COUNT(*) FROM business_report_deliveries WHERE archive_id={$archiveId} AND status='pending'")->fetchColumn() === 1);

$second = business_report_generate($pdo, 'one', 'client-one-weekly', report_config(), $now, false, true);
report_check('missed periods catch up oldest first without skipping',
    $second['archive']['period_start'] === '2026-08-17 00:00:00'
    && $second['archive']['period_end'] === '2026-08-24 00:00:00');
report_throws(
    'catch-up stops when the next period is not due',
    BusinessReportGateException::class,
    fn() => business_report_generate($pdo, 'one', 'client-one-weekly', report_config(), $now, false, true),
    'not due',
);

$pdo->exec("UPDATE clients SET name='Renamed Current Client' WHERE id=11");
$transportCalls = 0;
$capturedSubject = '';
$submitted = business_report_deliver(
    $pdo,
    $archiveId,
    report_config(),
    function (string $to, string $subject, string $body) use (&$transportCalls, &$capturedSubject): array {
        $transportCalls++;
        $capturedSubject = $subject;
        return ['outcome' => 'submitted', 'provider_http' => 202, 'outcome_code' => 'graph_accepted'];
    },
    $now,
);
report_check('exact Graph acceptance is submitted but never called delivered',
    $submitted['status'] === 'submitted'
    && $transportCalls === 1
    && str_contains($capturedSubject, 'Client One')
    && !str_contains($capturedSubject, 'Renamed Current Client'));
$ignored = business_report_deliver(
    $pdo,
    $archiveId,
    report_config(),
    function () use (&$transportCalls): array {
        $transportCalls++;
        return ['outcome' => 'submitted', 'provider_http' => 202, 'outcome_code' => 'graph_accepted'];
    },
    $now,
);
report_check('terminal delivery is never retried', $ignored['action'] === 'ignored' && $transportCalls === 1);

// Restore the archived client name for new artifact fixtures.
$pdo->exec("UPDATE clients SET name='Client One' WHERE id=11");
$badHttpArchive = report_archive_fixture($pdo, (int)$enabled['schedule']['id'], 'client-one-weekly', '2026-07-06 00:00:00');
$badHttp = business_report_deliver(
    $pdo, (int)$badHttpArchive['id'], report_config(),
    fn(): array => ['outcome' => 'submitted', 'provider_http' => 200, 'outcome_code' => 'graph_accepted'],
    $now,
);
report_check('HTTP 200 cannot be promoted to submitted', $badHttp['status'] === 'uncertain');
$throwArchive = report_archive_fixture($pdo, (int)$enabled['schedule']['id'], 'client-one-weekly', '2026-06-29 00:00:00');
$thrown = business_report_deliver(
    $pdo, (int)$throwArchive['id'], report_config(),
    static function (): array { throw new RuntimeException('ambiguous transport'); },
    $now,
);
report_check('transport exception becomes terminal uncertain', $thrown['status'] === 'uncertain');
$invalidArchive = report_archive_fixture($pdo, (int)$enabled['schedule']['id'], 'client-one-weekly', '2026-06-22 00:00:00');
$invalid = business_report_deliver($pdo, (int)$invalidArchive['id'], report_config(), fn(): string => 'bad', $now);
report_check('malformed transport result becomes terminal uncertain', $invalid['status'] === 'uncertain');

$tampered = report_archive_fixture($pdo, (int)$enabled['schedule']['id'], 'client-one-weekly', '2026-06-15 00:00:00');
$pdo->exec("UPDATE business_report_archives SET report_text='tampered' WHERE id=" . (int)$tampered['id']);
$tamperCalls = 0;
report_throws(
    'archive tampering is refused before transport',
    BusinessReportConflictException::class,
    fn() => business_report_deliver(
        $pdo, (int)$tampered['id'], report_config(),
        function () use (&$tamperCalls): array {
            $tamperCalls++;
            return ['outcome' => 'submitted', 'provider_http' => 202, 'outcome_code' => 'graph_accepted'];
        },
        $now,
    ),
    'hash',
);
report_check('tampered archive made no transport call', $tamperCalls === 0);

$expired = report_archive_fixture($pdo, (int)$enabled['schedule']['id'], 'client-one-weekly', '2026-06-08 00:00:00');
$expiredDelivery = $pdo->query(
    'SELECT * FROM business_report_deliveries WHERE archive_id=' . (int)$expired['id']
)->fetch(PDO::FETCH_ASSOC);
$pdo->prepare(
    "UPDATE business_report_deliveries
        SET status='sending',lease_token_hash=?,lease_expires_at='2026-08-26 11:00:00',last_attempt_at='2026-08-26 10:58:00'
      WHERE id=?"
)->execute([hash('sha256', 'expired'), (int)$expiredDelivery['id']]);
$pdo->prepare(
    "INSERT INTO business_report_delivery_attempts
        (tenant_id,delivery_id,attempt_key,provider,status,started_at)
     VALUES (?,?,?,'microsoft_graph','started','2026-08-26 10:58:00')"
)->execute([1, (int)$expiredDelivery['id'], hash('sha256', 'attempt')]);
$expiredCalls = 0;
$recovered = business_report_deliver(
    $pdo, (int)$expired['id'], report_config(),
    function () use (&$expiredCalls): array {
        $expiredCalls++;
        return ['outcome' => 'submitted', 'provider_http' => 202, 'outcome_code' => 'graph_accepted'];
    },
    $now,
);
report_check('expired send lease becomes uncertain without another transport call',
    $recovered['action'] === 'recovered_uncertain' && $expiredCalls === 0);

$revoked = report_archive_fixture($pdo, (int)$enabled['schedule']['id'], 'client-one-weekly', '2026-06-01 00:00:00');
$revokedExpired = report_archive_fixture($pdo, (int)$enabled['schedule']['id'], 'client-one-weekly', '2026-05-25 00:00:00');
$revokedExpiredDelivery = $pdo->query(
    'SELECT * FROM business_report_deliveries WHERE archive_id=' . (int)$revokedExpired['id']
)->fetch(PDO::FETCH_ASSOC);
$pdo->prepare(
    "UPDATE business_report_deliveries
        SET status='sending',lease_token_hash=?,lease_expires_at='2026-08-26 11:00:00',last_attempt_at='2026-08-26 10:58:00'
      WHERE id=?"
)->execute([hash('sha256', 'revoked-expired'), (int)$revokedExpiredDelivery['id']]);
$pdo->prepare(
    "INSERT INTO business_report_delivery_attempts
        (tenant_id,delivery_id,attempt_key,provider,status,started_at)
     VALUES (?,?,?,'microsoft_graph','started','2026-08-26 10:58:00')"
)->execute([1, (int)$revokedExpiredDelivery['id'], hash('sha256', 'revoked-expired-attempt')]);
$disabled = business_report_transition_schedule(
    $pdo, 'one', 'client-one-weekly', 2, 'disabled', 101, 'revoke pending delivery', report_config(),
);
$revokedEnumeration = business_report_pending_archive_ids(
    $pdo,
    $now,
    BUSINESS_REPORT_MAX_DUE_SCHEDULES,
    report_config(['tenant_slugs' => [], 'client_keys' => [], 'recipient_emails' => []]),
);
report_check(
    'scheduler omits revoked pending rows but retains expired send-boundary recovery',
    $revokedEnumeration === [(int)$revokedExpired['id']],
);
$revokedExpiredCalls = 0;
$revokedRecovered = business_report_deliver(
    $pdo,
    (int)$revokedExpired['id'],
    report_config(['tenant_slugs' => [], 'client_keys' => [], 'recipient_emails' => []]),
    function () use (&$revokedExpiredCalls): array {
        $revokedExpiredCalls++;
        return ['outcome' => 'submitted', 'provider_http' => 202, 'outcome_code' => 'graph_accepted'];
    },
    $now,
);
report_check(
    'expired send boundary becomes uncertain despite later revocation or allowlist removal',
    $revokedRecovered['action'] === 'recovered_uncertain' && $revokedExpiredCalls === 0,
);
$revokedCalls = 0;
report_throws(
    'later schedule disable revokes pending delivery before send boundary',
    BusinessReportGateException::class,
    fn() => business_report_deliver(
        $pdo, (int)$revoked['id'], report_config(),
        function () use (&$revokedCalls): array {
            $revokedCalls++;
            return ['outcome' => 'submitted', 'provider_http' => 202, 'outcome_code' => 'graph_accepted'];
        },
        $now,
    ),
    'no longer',
);
report_check('revoked delivery made no transport call', $revokedCalls === 0);
report_throws(
    'schedule keys cannot be retargeted to another client or definition',
    BusinessReportConflictException::class,
    fn() => business_report_prepare_schedule(
        $pdo, 'one', 'client-one-weekly', 12, (int)$definition['definition']['id'],
        'reports@example.test', 'UTC', 3, '09:00:00', true, 101, 'forbidden retarget',
    ),
    'cannot change',
);
report_throws(
    'schedule keys cannot change reporting timezone',
    BusinessReportConflictException::class,
    fn() => business_report_prepare_schedule(
        $pdo, 'one', 'client-one-weekly', 11, (int)$definition['definition']['id'],
        'reports@example.test', 'America/Los_Angeles', 3, '09:00:00', true, 101, 'forbidden timezone move',
    ),
    'cannot change',
);

// Starvation guard: 101 due active schedules, only the last recipient allowed.
for ($i = 1; $i <= 101; $i++) {
    $key = sprintf('starve-%03d', $i);
    $recipient = $i === 101 ? 'allowed@example.test' : sprintf('blocked%03d@example.test', $i);
    $insert = $pdo->prepare(
        "INSERT INTO business_report_schedule_versions
            (tenant_id,schedule_key,version_no,definition_version_id,client_id,recipient_email,
             schedule_timezone,delivery_weekday,delivery_local_time,canary,status,
             created_by_user_id,reason,created_at)
         VALUES (1,?,1,?,11,?,'UTC',3,'09:00:00',1,'active',101,'fixture','2026-08-19 00:00:00')"
    );
    $insert->execute([$key, (int)$definition['definition']['id'], $recipient]);
}
$starvationConfig = report_config(['recipient_emails' => ['allowed@example.test']]);
$due = business_report_due_schedule_keys($pdo, $now, 1, $starvationConfig);
report_check('more than one hundred blocked schedules cannot starve an allowed due schedule',
    $due === [['tenant_slug' => 'one', 'schedule_key' => 'starve-101']]);

$source = file_get_contents(__DIR__ . '/../lib/business_reports.php') ?: '';
foreach (['t.subject', 'm.body', 'time_entries.note', 'review_note', 'contacts ', 'attachments '] as $forbidden) {
    report_check("report query source excludes {$forbidden}", !str_contains($source, $forbidden));
}

$cronSource = file_get_contents(__DIR__ . '/../cron/business_reports.php') ?: '';
report_check(
    'scheduled runner refreshes its clock for enumeration and each operation',
    !str_contains($cronSource, '$now = time()')
        && substr_count($cronSource, 'time()') >= 4,
);

$deleteTables = [];
$appRoot = dirname(__DIR__);
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appRoot));
foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') continue;
    $path = str_replace('\\', '/', $file->getPathname());
    if (str_contains($path, '/tests/') || str_ends_with($path, '/db/seed.php')) continue;
    $contents = file_get_contents($file->getPathname()) ?: '';
    if (preg_match_all('/\bDELETE\s+(?:FROM\s+|[a-z_][a-z0-9_]*\s+FROM\s+)`?([a-z_]+)`?/i', $contents, $matches) > 0) {
        foreach ($matches[1] as $table) $deleteTables[] = strtolower($table);
    }
}
$deleteTables = array_values(array_unique($deleteTables));
sort($deleteTables);
report_check('runtime DELETE grant allowlist matches every production delete path exactly', $deleteTables === [
    'canned_responses',
    'clients',
    'contacts',
    'email_threads',
    'messages',
    'svc_rate_buckets',
    'svc_support_rate',
    'tickets',
]);

if ($failures > 0) {
    fwrite(STDERR, "{$failures} of {$checks} business report checks failed.\n");
    exit(1);
}
echo "Business reports: {$checks}/{$checks} passed.\n";
