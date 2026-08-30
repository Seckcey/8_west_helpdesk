<?php
/** Hermetic contract, metric, archive, scheduling, and delivery coverage. */
declare(strict_types=1);

require_once __DIR__ . '/../lib/business_reports.php';
$reportCfgValues = [];
if (!function_exists('cfg')) {
    function cfg(string $key, mixed $default = null): mixed
    {
        global $reportCfgValues;
        return array_key_exists($key, $reportCfgValues) ? $reportCfgValues[$key] : $default;
    }
}
require_once __DIR__ . '/../lib/mailer.php';

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

/** Digest every fixture table so a read-only operation cannot hide a write. */
function report_database_digest(PDO $pdo): string
{
    $tables = $pdo->query(
        "SELECT name FROM sqlite_master
          WHERE type='table' AND name NOT LIKE 'sqlite_%'
          ORDER BY name"
    )->fetchAll(PDO::FETCH_COLUMN);
    $state = [];
    foreach ($tables as $table) {
        if (!is_string($table) || preg_match('/\A[a-z_][a-z0-9_]*\z/D', $table) !== 1) {
            throw new RuntimeException('Unexpected fixture table name.');
        }
        $state[$table] = $pdo->query('SELECT * FROM "' . $table . '" ORDER BY rowid')->fetchAll(PDO::FETCH_ASSOC);
    }
    return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
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

/**
 * @param list<array<string,mixed>> $responses
 * @param list<array{url:string,headers:array,payload:string,timeout:int}> $requests
 */
function report_graph_http_fixture(array $responses, array &$requests): callable
{
    $position = 0;
    return static function (
        string $url,
        array $headers,
        string $payload,
        int $timeout,
    ) use ($responses, &$requests, &$position): array {
        $requests[] = compact('url', 'headers', 'payload', 'timeout');
        if (!array_key_exists($position, $responses)) throw new RuntimeException('Unexpected Graph request.');
        return $responses[$position++];
    };
}

/** @return array<string,mixed> */
function report_config(array $changes = []): array
{
    return array_replace([
        'generation_enabled' => true,
        'delivery_enabled' => true,
        'canary_only' => true,
        'graph_sender' => 'weekly-reports@example.test',
        'schedule_keys' => ['client-one-weekly'],
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
    'CREATE TABLE suite_customer_sync_bindings (
        tenant_id INTEGER NOT NULL, client_id INTEGER NOT NULL,
        customer_id TEXT NOT NULL UNIQUE, status TEXT NOT NULL,
        UNIQUE(tenant_id,client_id))',
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
    'CREATE TABLE business_report_contact_scope_bindings (
        tenant_id INTEGER NOT NULL, schedule_key TEXT NOT NULL,
        contact_scope TEXT NOT NULL, created_by_user_id INTEGER NOT NULL,
        reason TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY(tenant_id,schedule_key))',
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
    'CREATE TABLE time_entry_approval_adjustments (
        id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, time_entry_id INTEGER NOT NULL,
        adjustment_key TEXT NOT NULL, version_no INTEGER NOT NULL,
        effective_minutes INTEGER NOT NULL, effective_billable INTEGER NOT NULL,
        reason TEXT NOT NULL, actor_user_id INTEGER NOT NULL, created_at TEXT NOT NULL)',
    'CREATE TABLE csat (
        id INTEGER PRIMARY KEY, ticket_id INTEGER NOT NULL, score INTEGER NULL,
        comment TEXT NOT NULL, created_at TEXT NOT NULL, responded_at TEXT NULL)',
];
foreach ($schema as $statement) $pdo->exec($statement);

$pdo->exec("INSERT INTO tenants VALUES (1,'Tenant One','one'),(2,'Tenant Two','two')");
$pdo->exec("INSERT INTO clients VALUES
    (11,1,'Client One'),(12,1,'Client Twelve'),(13,1,'Client Thirteen'),
    (14,1,'Client Fourteen'),(22,2,'Client Two')");
$pdo->exec("INSERT INTO suite_customer_sync_bindings VALUES
    (1,12,'01234567-89ab-4def-8abc-0123456789ab','active'),
    (1,13,'4ebaeefa-b101-47f8-ac76-e49ab309d272','active')");
$pdo->exec("INSERT INTO users VALUES
    (101,1,'owner',1),(102,1,'admin',1),(103,1,'tech',1),(104,1,'admin',0),(201,2,'owner',1)");

report_check('default configuration is fully inert', business_report_config([]) === [
    'generation_enabled' => false,
    'delivery_enabled' => false,
    'canary_only' => true,
    'graph_sender' => null,
    'schedule_keys' => [],
    'tenant_slugs' => [],
    'client_keys' => [],
    'recipient_emails' => [],
    'lease_seconds' => 120,
]);

$graphConfig = [
    'tenant_id' => 'fixture-tenant',
    'client_id' => 'fixture-client',
    'client_secret' => 'fixture-client-secret',
    'sender' => 'sender@example.test',
];
$reportGraphConfig = business_report_delivery_graph_config(
    business_report_config(report_config()),
    $graphConfig,
);
report_check(
    'business reports replace only the in-process Graph sender',
    $reportGraphConfig['tenant_id'] === $graphConfig['tenant_id']
        && $reportGraphConfig['client_id'] === $graphConfig['client_id']
        && $reportGraphConfig['client_secret'] === $graphConfig['client_secret']
        && $reportGraphConfig['sender'] === 'weekly-reports@example.test'
        && $graphConfig['sender'] === 'sender@example.test',
);
$graphWithoutOrdinarySender = $graphConfig;
$graphWithoutOrdinarySender['sender'] = '';
$reportGraphWithoutOrdinarySender = business_report_delivery_graph_config(
    business_report_config(report_config()),
    $graphWithoutOrdinarySender,
);
report_check(
    'business reports do not depend on the ordinary help-desk Graph sender',
    $reportGraphWithoutOrdinarySender['tenant_id'] === $graphConfig['tenant_id']
        && $reportGraphWithoutOrdinarySender['client_id'] === $graphConfig['client_id']
        && $reportGraphWithoutOrdinarySender['client_secret'] === $graphConfig['client_secret']
        && $reportGraphWithoutOrdinarySender['sender'] === 'weekly-reports@example.test'
        && $graphWithoutOrdinarySender['sender'] === '',
);
report_throws(
    'business reports never fall back to the ordinary help-desk Graph sender',
    BusinessReportGateException::class,
    fn() => business_report_delivery_graph_config(business_report_config([]), $graphConfig),
    'dedicated',
);
report_throws(
    'business report delivery refuses missing Graph credentials before sending',
    BusinessReportGateException::class,
    fn() => business_report_delivery_graph_config(
        business_report_config(report_config()),
        null,
    ),
    'credentials',
);
$graphMissingCredential = $graphWithoutOrdinarySender;
$graphMissingCredential['client_secret'] = '';
report_throws(
    'business report delivery refuses an incomplete Graph credential set',
    BusinessReportGateException::class,
    fn() => business_report_delivery_graph_config(
        business_report_config(report_config()),
        $graphMissingCredential,
    ),
    'credentials',
);
report_throws(
    'business report sender must be normalized lowercase email',
    BusinessReportValidationException::class,
    fn() => business_report_config(report_config([
        'graph_sender' => 'Weekly-Reports@example.test',
    ])),
    'normalized lowercase',
);
$graphRequests = [];
$graphAccepted = mailer_send_graph_result(
    $graphConfig,
    'recipient@example.test',
    'Fixture subject',
    'Fixture body',
    report_graph_http_fixture([
        ['http' => 200, 'body' => '{"access_token":"fixture-access-token","expires_in":3600}'],
        ['http' => 202, 'body' => 'provider-private-body'],
    ], $graphRequests),
);
report_check('Graph 202 produces only bounded accepted evidence',
    $graphAccepted === ['outcome' => 'submitted', 'provider_http' => 202, 'outcome_code' => 'graph_accepted']
    && count($graphRequests) === 2);
report_check('Graph accepted evidence contains no message, address, token, credential, or provider body',
    !str_contains(json_encode($graphAccepted, JSON_THROW_ON_ERROR), 'recipient@example.test')
    && !str_contains(json_encode($graphAccepted, JSON_THROW_ON_ERROR), 'fixture-access-token')
    && !str_contains(json_encode($graphAccepted, JSON_THROW_ON_ERROR), 'fixture-client-secret')
    && !str_contains(json_encode($graphAccepted, JSON_THROW_ON_ERROR), 'provider-private-body'));

$graphRequests = [];
$graphRejected = mailer_send_graph_result(
    $graphConfig,
    'recipient@example.test',
    'Fixture subject',
    'Fixture body',
    report_graph_http_fixture([
        ['http' => 200, 'body' => '{"access_token":"fixture-access-token","expires_in":3600}'],
        ['http' => 403, 'body' => 'private rejection body'],
    ], $graphRequests),
);
report_check('definite Graph send rejection keeps safe HTTP and category only',
    $graphRejected === ['outcome' => 'uncertain', 'provider_http' => 403, 'outcome_code' => 'graph_send_rejected']
    && !str_contains(json_encode($graphRejected, JSON_THROW_ON_ERROR), 'private rejection body'));

$graphRequests = [];
$tokenRejected = mailer_send_graph_result(
    $graphConfig,
    'recipient@example.test',
    'Fixture subject',
    'Fixture body',
    report_graph_http_fixture([
        ['http' => 401, 'body' => '{"error_description":"private credential detail"}'],
    ], $graphRequests),
);
report_check('definite Graph token rejection keeps safe HTTP and category only',
    $tokenRejected === ['outcome' => 'uncertain', 'provider_http' => 401, 'outcome_code' => 'graph_token_rejected']
    && count($graphRequests) === 1
    && !str_contains(json_encode($tokenRejected, JSON_THROW_ON_ERROR), 'private credential detail'));

$graphRequests = [];
$tokenInvalid = mailer_send_graph_result(
    $graphConfig,
    'recipient@example.test',
    'Fixture subject',
    'Fixture body',
    report_graph_http_fixture([
        ['http' => 200, 'body' => '{"access_token":[],"detail":"private token response"}'],
    ], $graphRequests),
);
report_check('HTTP 200 token response without a token is bounded invalid evidence',
    $tokenInvalid === ['outcome' => 'uncertain', 'provider_http' => 200, 'outcome_code' => 'graph_token_invalid_response']
    && !str_contains(json_encode($tokenInvalid, JSON_THROW_ON_ERROR), 'private token response'));

$graphRequests = [];
$graphNetwork = mailer_send_graph_result(
    $graphConfig,
    'recipient@example.test',
    'Fixture subject',
    'Fixture body',
    report_graph_http_fixture([
        ['error' => 'raw network exception text'],
    ], $graphRequests),
);
report_check('Graph network failure keeps HTTP unknown and drops raw error text',
    $graphNetwork === ['outcome' => 'uncertain', 'provider_http' => null, 'outcome_code' => 'graph_token_transport_error']
    && !str_contains(json_encode($graphNetwork, JSON_THROW_ON_ERROR), 'raw network exception text'));

$graphRequests = [];
$graphSendNetwork = mailer_send_graph_result(
    $graphConfig,
    'recipient@example.test',
    'Fixture subject',
    'Fixture body',
    report_graph_http_fixture([
        ['http' => 200, 'body' => '{"access_token":"fixture-access-token","expires_in":3600}'],
        ['error' => 'raw send exception text'],
    ], $graphRequests),
);
report_check('Graph send network failure keeps HTTP unknown and drops raw error text',
    $graphSendNetwork === ['outcome' => 'uncertain', 'provider_http' => null, 'outcome_code' => 'graph_send_transport_error']
    && !str_contains(json_encode($graphSendNetwork, JSON_THROW_ON_ERROR), 'raw send exception text'));

$graphRequests = [];
$graphUnknown = mailer_send_graph_result(
    $graphConfig,
    'recipient@example.test',
    'Fixture subject',
    'Fixture body',
    report_graph_http_fixture([
        ['http' => 200, 'body' => '{"access_token":"fixture-access-token","expires_in":3600}'],
        ['http' => 0, 'body' => 'private unknown body'],
    ], $graphRequests),
);
report_check('unknown Graph send response keeps HTTP NULL and a safe category',
    $graphUnknown === ['outcome' => 'uncertain', 'provider_http' => null, 'outcome_code' => 'graph_send_unknown_response']
    && !str_contains(json_encode($graphUnknown, JSON_THROW_ON_ERROR), 'private unknown body'));

$graphRequests = [];
$graphInvalidPayload = mailer_send_graph_result(
    $graphConfig,
    'recipient@example.test',
    "Invalid \xB1 subject",
    'Fixture body',
    report_graph_http_fixture([
        ['http' => 200, 'body' => '{"access_token":"fixture-access-token","expires_in":3600}'],
    ], $graphRequests),
);
report_check('local Graph payload failure stores no message bytes or invented HTTP status',
    $graphInvalidPayload === ['outcome' => 'uncertain', 'provider_http' => null, 'outcome_code' => 'graph_payload_invalid']
    && count($graphRequests) === 1);

$legacyError = null;
$graphRequests = [];
$legacyAccepted = mailer_send_graph(
    $graphConfig,
    'recipient@example.test',
    'Fixture subject',
    'Fixture body',
    $legacyError,
    report_graph_http_fixture([
        ['http' => 401, 'body' => '{"error_description":"private credential detail"}'],
    ], $graphRequests),
);
report_check('legacy mail_queue Graph contract stays boolean with a sanitized error',
    $legacyAccepted === false
    && $legacyError === 'Microsoft Graph token request was rejected (HTTP 401).'
    && !str_contains($legacyError, 'private credential detail'));

report_check(
    'advisory lock helper distinguishes acquired and contended states',
    business_report_advisory_lock_state(1) === 'acquired'
        && business_report_advisory_lock_state('1') === 'acquired'
        && business_report_advisory_lock_state(0) === 'contended'
        && business_report_advisory_lock_state('0') === 'contended',
);
foreach ([null, false, true, '', 'unexpected'] as $invalidLockState) {
    report_throws(
        'advisory lock helper fails on operational or malformed state',
        BusinessReportGateException::class,
        fn() => business_report_advisory_lock_state($invalidLockState),
        'lock failed',
    );
}
business_report_advisory_lock_release(1);
business_report_advisory_lock_release('1');
foreach ([null, false, 0, '0', 'unexpected'] as $invalidReleaseState) {
    report_throws(
        'advisory lock release helper fails on unsuccessful state',
        BusinessReportGateException::class,
        fn() => business_report_advisory_lock_release($invalidReleaseState),
        'release failed',
    );
}

report_throws(
    'duplicate allowlist values fail closed',
    BusinessReportValidationException::class,
    fn() => business_report_config(report_config(['tenant_slugs' => ['one', 'one']])),
    'duplicate',
);
report_throws(
    'duplicate schedule allowlist values fail closed',
    BusinessReportValidationException::class,
    fn() => business_report_config(report_config([
        'schedule_keys' => ['client-one-weekly', 'client-one-weekly'],
    ])),
    'duplicate',
);
report_throws(
    'schedule allowlist values must be exact schedule keys',
    BusinessReportValidationException::class,
    fn() => business_report_config(report_config(['schedule_keys' => ['Client Weekly']])),
    'schedule key',
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
    && business_report_contract_sha256(BUSINESS_REPORT_CONTRACT_VERSION_V1)
        === '04a3293766fadb0f665e20c68703d3378a71feba828b9f82c4e73181dbb3afd5'
    && hash_equals(
        (string)$definition['definition']['contract_sha256'],
        hash('sha256', (string)$definition['definition']['contract_json']),
    ));
report_check('definition publication is idempotent',
    business_report_publish_definition($pdo, 'one', 102, 'same contract')['action'] === 'ignored');

// A new ordinal carrying copied v1 bytes is not a supported v2 definition.
// Both schedule preparation and active reads must fail closed before metrics.
$copiedDefinition = $pdo->prepare(
    'INSERT INTO business_report_definition_versions
        (tenant_id,definition_key,version_no,report_type,contract_json,
         contract_sha256,created_by_user_id,reason)
     SELECT tenant_id,definition_key,2,report_type,contract_json,
            contract_sha256,created_by_user_id,?
       FROM business_report_definition_versions
      WHERE tenant_id=1 AND id=?'
);
$copiedDefinition->execute(['unsupported copied v1 bytes', (int)$definition['definition']['id']]);
$copiedDefinitionId = (int)$pdo->lastInsertId();
report_throws(
    'schedule preparation refuses a copied contract under unsupported definition v2',
    BusinessReportGateException::class,
    fn() => business_report_prepare_schedule(
        $pdo, 'one', 'unsupported-definition-v2', 11, $copiedDefinitionId,
        'reports@example.test', 'UTC', 3, '09:00:00', true, 101, 'must refuse',
    ),
    'supported report definition',
);
$unsupportedActive = $pdo->prepare(
    'INSERT INTO business_report_schedule_versions
        (tenant_id,schedule_key,version_no,definition_version_id,client_id,
         recipient_email,schedule_timezone,delivery_weekday,delivery_local_time,
         canary,status,created_by_user_id,reason)
     VALUES (1,?,1,?,11,?,\'UTC\',3,\'09:00:00\',1,\'active\',101,?)'
);
$unsupportedActive->execute([
    'unsupported-definition-v2',
    $copiedDefinitionId,
    'reports@example.test',
    'direct fixture must fail closed',
]);
report_throws(
    'active schedule read refuses a copied contract under unsupported definition v2',
    BusinessReportGateException::class,
    fn() => business_report_active_schedule($pdo, 'one', 'unsupported-definition-v2'),
    'unsupported definition',
);
$pdo->exec("DELETE FROM business_report_schedule_versions
  WHERE tenant_id=1 AND schedule_key='unsupported-definition-v2'");
$pdo->exec("DELETE FROM business_report_definition_versions
  WHERE tenant_id=1 AND id={$copiedDefinitionId}");

report_throws(
    'definition v2 cannot skip an absent v1 predecessor',
    BusinessReportConflictException::class,
    fn() => business_report_publish_definition($pdo, 'two', 201, 'out of order v2', 2),
    'exact version order',
);
$definitionV2 = business_report_publish_definition(
    $pdo,
    'one',
    101,
    'correction-aware contract',
    BUSINESS_REPORT_CONTRACT_VERSION_V2,
);
report_check(
    'definition v2 publishes distinct reviewed bytes after immutable v1',
    $definitionV2['action'] === 'created'
        && (int) $definitionV2['definition']['version_no'] === 2
        && business_report_contract_sha256(BUSINESS_REPORT_CONTRACT_VERSION_V2)
            === '012b07fa3c82832044c0aaf23e4e4cd62e99b09e31d85288e3e0959741cc3d70'
        && !hash_equals(
            (string) $definition['definition']['contract_sha256'],
            (string) $definitionV2['definition']['contract_sha256'],
        )
        && business_report_definition_supported($definition['definition'])
        && business_report_definition_supported($definitionV2['definition']),
);
report_check(
    'definition v2 publication is idempotent without replacing v1',
    business_report_publish_definition($pdo, 'one', 102, 'same v2', 2)['action'] === 'ignored'
        && (int) $pdo->query(
            "SELECT COUNT(*) FROM business_report_definition_versions
              WHERE tenant_id=1 AND definition_key='weekly-client-service-summary'",
        )->fetchColumn() === 2,
);
report_throws(
    'unknown definition version is refused before publication',
    BusinessReportValidationException::class,
    fn() => business_report_publish_definition($pdo, 'one', 101, 'bad v3', 3),
    'unsupported',
);

$prepared = business_report_prepare_schedule(
    $pdo, 'one', 'client-one-weekly', 11, (int)$definition['definition']['id'],
    'reports@example.test', 'UTC', 3, '09:00:00', true, 101, 'prepare canary',
);
report_check('new schedules start disabled',
    $prepared['action'] === 'prepared'
    && $prepared['schedule']['status'] === 'disabled'
    && (int)$prepared['schedule']['version_no'] === 1);
$manualScope = business_report_contact_scope_for_key($pdo, 1, 'client-one-weekly');
report_check(
    'a manual schedule pins one immutable manual contact scope',
    is_array($manualScope)
        && $manualScope['scope'] === BUSINESS_REPORT_CONTACT_SCOPE_MANUAL
        && $manualScope['evidence'] === null
        && (int)$pdo->query(
            "SELECT COUNT(*) FROM business_report_contact_scope_bindings
              WHERE tenant_id=1 AND schedule_key='client-one-weekly' AND contact_scope='MANUAL'"
        )->fetchColumn() === 1,
);
$pdo->exec(
    "DELETE FROM business_report_contact_scope_bindings
      WHERE tenant_id=1 AND schedule_key='client-one-weekly'"
);
report_throws(
    'enable fails closed when the logical contact-scope registry is missing',
    BusinessReportGateException::class,
    fn() => business_report_transition_schedule(
        $pdo,
        'one',
        'client-one-weekly',
        1,
        'active',
        101,
        'missing scope refusal',
        report_config(),
    ),
    'contact-scope registry',
);
$restoreManualScope = $pdo->prepare(
    'INSERT INTO business_report_contact_scope_bindings
        (tenant_id,schedule_key,contact_scope,created_by_user_id,reason)
     VALUES (1,?,?,?,?)'
);
$restoreManualScope->execute([
    'client-one-weekly',
    BUSINESS_REPORT_CONTACT_SCOPE_MANUAL,
    101,
    'restore hermetic scope fixture',
]);

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
$idEnabled = business_report_transition_schedule(
    $pdo,
    'one',
    'id-client-weekly',
    2,
    'active',
    101,
    'enable inherited tenant scope',
    report_config([
        'schedule_keys' => ['id-client-weekly'],
        'recipient_emails' => ['new-id-reports@example.test'],
    ]),
);
$idDisabled = business_report_transition_schedule(
    $pdo,
    'one',
    'id-client-weekly',
    3,
    'disabled',
    101,
    'disable inherited tenant scope',
    report_config(),
);
$tenantInheritedScope = business_report_contact_scope_for_key($pdo, 1, 'id-client-weekly');
report_check(
    'enable and disable versions inherit the latest tenant ID evidence',
    (int)$idEnabled['schedule']['version_no'] === 3
        && (int)$idDisabled['schedule']['version_no'] === 4
        && is_array($tenantInheritedScope)
        && $tenantInheritedScope['scope'] === BUSINESS_REPORT_CONTACT_SCOPE_TENANT
        && (int)($tenantInheritedScope['evidence']['contact_version'] ?? 0) === 8,
);
$tenantHistoryBeforeScopeBypass = (int)$pdo->query(
    "SELECT COUNT(*) FROM business_report_schedule_versions
      WHERE tenant_id=1 AND schedule_key='id-client-weekly'"
)->fetchColumn();
report_throws(
    'tenant ID history cannot be changed to manual after enable and disable',
    BusinessReportConflictException::class,
    fn() => business_report_prepare_schedule(
        $pdo, 'one', 'id-client-weekly', 11, (int)$definition['definition']['id'],
        'new-id-reports@example.test', 'UTC', 3, '11:00:00', true, 101, 'manual scope bypass',
    ),
    'contact scope',
);
report_check(
    'refused tenant-to-manual bypass leaves schedule history unchanged',
    (int)$pdo->query(
        "SELECT COUNT(*) FROM business_report_schedule_versions
          WHERE tenant_id=1 AND schedule_key='id-client-weekly'"
    )->fetchColumn() === $tenantHistoryBeforeScopeBypass,
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

$clientIdContact = [
    'tenant_key' => 'ewid-t4',
    'tenant_slug' => 'customer-one',
    'contact_version' => 1,
    'recipient_email' => 'customer-admin@example.test',
    'generated_at' => '2026-08-28T12:00:00Z',
    'generated_at_db' => '2026-08-28 12:00:00',
    'request_nonce_sha256' => str_repeat('1', 64),
    'response_sha256' => str_repeat('2', 64),
];
$clientIdPrepared = business_report_prepare_client_schedule_from_id(
    $pdo, 'one', 'client-scoped-id-weekly', 11, (int)$definition['definition']['id'],
    $clientIdContact, 'UTC', 3, '09:00:00', true, 101, 'client ID canary',
);
report_check(
    'client-scoped ID prepare pins the exact client and authenticated ID tenant',
    $clientIdPrepared['action'] === 'prepared'
        && (int)$clientIdPrepared['id_contact']['client_id'] === 11
        && (string)$clientIdPrepared['id_contact']['id_tenant_key'] === 'ewid-t4'
        && (string)$clientIdPrepared['id_contact']['recipient_email'] === 'customer-admin@example.test'
        && (int)$pdo->query('SELECT COUNT(*) FROM business_report_id_client_bindings')->fetchColumn() === 1
        && (int)$pdo->query('SELECT COUNT(*) FROM business_report_id_client_contact_snapshots')->fetchColumn() === 1,
);
$legacyRefreshTarget = business_report_legacy_client_refresh_target(
    $pdo,
    'one',
    'client-scoped-id-weekly',
    11,
    (int)$definition['definition']['id'],
    101,
);
report_check(
    'legacy local-id contact lane can refresh only its exact existing client schedule',
    (int)$legacyRefreshTarget['tenant_id'] === 1
        && (int)$legacyRefreshTarget['client_id'] === 11,
);
report_throws(
    'legacy local-id contact lane cannot create a new schedule history',
    BusinessReportGateException::class,
    fn() => business_report_legacy_client_refresh_target(
        $pdo,
        'one',
        'new-local-id-bypass',
        11,
        (int)$definition['definition']['id'],
        101,
    ),
    'stable customer onboarding',
);
report_throws(
    'legacy local-id contact lane cannot enter tenant-scoped schedule history',
    BusinessReportGateException::class,
    fn() => business_report_legacy_client_refresh_target(
        $pdo,
        'one',
        'id-client-weekly',
        11,
        (int)$definition['definition']['id'],
        101,
    ),
    'client-scoped',
);
report_throws(
    'manual address cannot create a new schedule for a Milepost-managed customer',
    BusinessReportGateException::class,
    fn() => business_report_prepare_schedule(
        $pdo,
        'one',
        'managed-manual-bypass',
        12,
        (int)$definition['definition']['id'],
        'manual@example.test',
        'UTC',
        3,
        '09:00:00',
        true,
        101,
        'manual managed-customer bypass',
    ),
    'stable customer onboarding',
);
report_throws(
    'tenant contact lane cannot create a new non-master managed-customer schedule',
    BusinessReportGateException::class,
    fn() => business_report_prepare_schedule_from_id(
        $pdo,
        'one',
        'managed-tenant-bypass',
        12,
        (int)$definition['definition']['id'],
        $newIdContact,
        'UTC',
        3,
        '09:00:00',
        true,
        101,
        'tenant managed-customer bypass',
    ),
    'stable customer onboarding',
);
$masterTenantPrepared = business_report_prepare_schedule_from_id(
    $pdo,
    'one',
    'master-tenant-weekly',
    13,
    (int)$definition['definition']['id'],
    $newIdContact,
    'UTC',
    3,
    '09:00:00',
    true,
    101,
    '8 West IT tenant-scope report',
);
report_check(
    '8 West IT master record keeps the tenant-scoped weekly-report lane',
    $masterTenantPrepared['action'] === 'prepared'
        && $masterTenantPrepared['schedule']['status'] === 'disabled'
        && (int)$masterTenantPrepared['schedule']['client_id'] === 13,
);
report_check(
    'managed-customer bypass refusals leave no schedule history',
    (int)$pdo->query(
        "SELECT COUNT(*) FROM business_report_schedule_versions
          WHERE schedule_key IN ('managed-manual-bypass','managed-tenant-bypass')"
    )->fetchColumn() === 0,
);

$managedCustomerId = '01234567-89ab-4def-8abc-0123456789ab';
$managedCustomerContact = array_replace($clientIdContact, [
    'tenant_key' => 'ewid-t50',
    'tenant_slug' => 'managed-customer',
    'recipient_email' => 'managed-admin@example.test',
    'request_nonce_sha256' => str_repeat('7', 64),
    'response_sha256' => str_repeat('8', 64),
]);
$managedPlanBefore = report_database_digest($pdo);
$managedPlan = business_report_plan_customer_schedule_from_id(
    $pdo,
    'one',
    'managed-customer-id-weekly',
    12,
    (int)$definition['definition']['id'],
    $managedCustomerId,
    $managedCustomerContact,
    'UTC',
    3,
    '09:00:00',
    true,
    101,
    'stable customer ID plan',
);
report_check(
    'managed-customer plan proves the exact disabled version without exposing its address',
    $managedPlan['action'] === 'planned'
        && $managedPlan['schedule']['id'] === null
        && (int)$managedPlan['schedule']['version_no'] === 1
        && $managedPlan['schedule']['status'] === 'disabled'
        && (int)$managedPlan['schedule']['client_id'] === 12
        && $managedPlan['schedule']['schedule_timezone'] === 'UTC'
        && (int)$managedPlan['schedule']['delivery_weekday'] === 3
        && $managedPlan['schedule']['delivery_local_time'] === '09:00:00'
        && hash_equals(
            hash('sha256', 'managed-admin@example.test'),
            (string)$managedPlan['schedule']['recipient_sha256'],
        )
        && !array_key_exists('recipient_email', $managedPlan['schedule'])
        && !array_key_exists('recipient_email', $managedPlan['id_contact']),
);
report_check(
    'managed-customer plan performs zero database mutations',
    hash_equals($managedPlanBefore, report_database_digest($pdo)),
);
report_throws(
    'managed-customer plan refuses a different permanent UUID without writing',
    BusinessReportGateException::class,
    fn() => business_report_plan_customer_schedule_from_id(
        $pdo,
        'one',
        'managed-customer-wrong-plan',
        12,
        (int)$definition['definition']['id'],
        '11234567-89ab-4def-8abc-0123456789ab',
        $managedCustomerContact,
        'UTC',
        3,
        '09:00:00',
        true,
        101,
        'wrong customer plan refusal',
    ),
    'binding changed',
);
report_check(
    'refused managed-customer plan performs zero database mutations',
    hash_equals($managedPlanBefore, report_database_digest($pdo)),
);
$managedPrepared = business_report_prepare_customer_schedule_from_id(
    $pdo,
    'one',
    'managed-customer-id-weekly',
    12,
    (int)$definition['definition']['id'],
    $managedCustomerId,
    $managedCustomerContact,
    'UTC',
    3,
    '09:00:00',
    true,
    101,
    'stable customer ID canary',
);
report_check(
    'managed-customer prepare locks the active Milepost UUID through evidence commit',
    $managedPrepared['action'] === 'prepared'
        && (int)$managedPrepared['schedule']['client_id'] === 12
        && (int)$managedPrepared['id_contact']['client_id'] === 12
        && (string)$managedPrepared['id_contact']['id_tenant_key'] === 'ewid-t50',
);
$managedReplayPlanBefore = report_database_digest($pdo);
$managedReplayPlan = business_report_plan_customer_schedule_from_id(
    $pdo,
    'one',
    'managed-customer-id-weekly',
    12,
    (int)$definition['definition']['id'],
    $managedCustomerId,
    array_replace($managedCustomerContact, [
        'request_nonce_sha256' => str_repeat('9', 64),
        'response_sha256' => str_repeat('a', 64),
    ]),
    'UTC',
    3,
    '09:00:00',
    true,
    101,
    'stable customer replay plan',
);
report_check(
    'managed-customer plan recognizes an exact prepared replay without writing',
    $managedReplayPlan['action'] === 'ignored'
        && (int)$managedReplayPlan['schedule']['id'] === (int)$managedPrepared['schedule']['id']
        && (int)$managedReplayPlan['schedule']['version_no'] === 1
        && hash_equals($managedReplayPlanBefore, report_database_digest($pdo)),
);
$managedContactUpdatePlan = business_report_plan_customer_schedule_from_id(
    $pdo,
    'one',
    'managed-customer-id-weekly',
    12,
    (int)$definition['definition']['id'],
    $managedCustomerId,
    array_replace($managedCustomerContact, [
        'contact_version' => 2,
        'request_nonce_sha256' => str_repeat('b', 64),
        'response_sha256' => str_repeat('c', 64),
    ]),
    'UTC',
    3,
    '09:00:00',
    true,
    101,
    'stable customer update plan',
);
report_check(
    'new ID contact version plans the next disabled schedule without writing',
    $managedContactUpdatePlan['action'] === 'planned'
        && $managedContactUpdatePlan['schedule']['id'] === null
        && (int)$managedContactUpdatePlan['schedule']['version_no'] === 2
        && hash_equals($managedReplayPlanBefore, report_database_digest($pdo)),
);
report_throws(
    'same ID contact version cannot plan a different recipient',
    BusinessReportConflictException::class,
    fn() => business_report_plan_customer_schedule_from_id(
        $pdo,
        'one',
        'managed-customer-id-weekly',
        12,
        (int)$definition['definition']['id'],
        $managedCustomerId,
        array_replace($managedCustomerContact, ['recipient_email' => 'changed@example.test']),
        'UTC',
        3,
        '09:00:00',
        true,
        101,
        'same version recipient conflict',
    ),
    'same 8 West ID report-contact version',
);
report_check(
    'conflicting ID contact plan performs zero database mutations',
    hash_equals($managedReplayPlanBefore, report_database_digest($pdo)),
);
$managedReplay = business_report_prepare_customer_schedule_from_id(
    $pdo,
    'one',
    'managed-customer-id-weekly',
    12,
    (int)$definition['definition']['id'],
    $managedCustomerId,
    array_replace($managedCustomerContact, [
        'request_nonce_sha256' => str_repeat('9', 64),
        'response_sha256' => str_repeat('a', 64),
    ]),
    'UTC',
    3,
    '09:00:00',
    true,
    101,
    'stable customer exact replay',
);
report_check(
    'managed-customer exact replay keeps one disabled schedule and one snapshot',
    $managedReplay['action'] === 'ignored'
        && (int)$managedReplay['schedule']['id'] === (int)$managedPrepared['schedule']['id'],
);
$pdo->exec("UPDATE suite_customer_sync_bindings SET status='inactive' WHERE client_id=12");
$inactivePlanBefore = report_database_digest($pdo);
report_throws(
    'managed-customer plan refuses an inactivated source binding without writing',
    BusinessReportGateException::class,
    fn() => business_report_plan_customer_schedule_from_id(
        $pdo,
        'one',
        'managed-customer-id-weekly',
        12,
        (int)$definition['definition']['id'],
        $managedCustomerId,
        $managedCustomerContact,
        'UTC',
        3,
        '09:00:00',
        true,
        101,
        'inactive customer plan refusal',
    ),
    'binding changed',
);
report_check(
    'inactive managed-customer plan performs zero database mutations',
    hash_equals($inactivePlanBefore, report_database_digest($pdo)),
);
report_throws(
    'managed-customer preparation refuses an inactivated source binding before writing',
    BusinessReportGateException::class,
    fn() => business_report_prepare_customer_schedule_from_id(
        $pdo,
        'one',
        'managed-customer-inactive',
        12,
        (int)$definition['definition']['id'],
        $managedCustomerId,
        $managedCustomerContact,
        'UTC',
        3,
        '09:00:00',
        true,
        101,
        'inactive customer refusal',
    ),
    'binding changed',
);
report_check(
    'inactive customer refusal leaves no schedule scope or version',
    (int)$pdo->query(
        "SELECT COUNT(*) FROM business_report_contact_scope_bindings
          WHERE schedule_key='managed-customer-inactive'"
    )->fetchColumn() === 0
        && (int)$pdo->query(
            "SELECT COUNT(*) FROM business_report_schedule_versions
              WHERE schedule_key='managed-customer-inactive'"
        )->fetchColumn() === 0,
);
$pdo->exec("UPDATE suite_customer_sync_bindings SET status='active' WHERE client_id=12");
report_throws(
    'managed-customer preparation refuses a different permanent UUID',
    BusinessReportGateException::class,
    fn() => business_report_prepare_customer_schedule_from_id(
        $pdo,
        'one',
        'managed-customer-wrong-id',
        12,
        (int)$definition['definition']['id'],
        '11234567-89ab-4def-8abc-0123456789ab',
        $managedCustomerContact,
        'UTC',
        3,
        '09:00:00',
        true,
        101,
        'wrong customer refusal',
    ),
    'binding changed',
);
report_throws(
    '8 West IT master UUID cannot enter a customer-scoped report schedule',
    BusinessReportValidationException::class,
    fn() => business_report_prepare_customer_schedule_from_id(
        $pdo,
        'one',
        'managed-customer-master',
        12,
        (int)$definition['definition']['id'],
        BUSINESS_REPORT_MASTER_CUSTOMER_ID,
        $managedCustomerContact,
        'UTC',
        3,
        '09:00:00',
        true,
        101,
        'master customer refusal',
    ),
    'non-master',
);

$clientIdReplay = business_report_prepare_client_schedule_from_id(
    $pdo, 'one', 'client-scoped-id-weekly', 11, (int)$definition['definition']['id'],
    array_replace($clientIdContact, [
        'request_nonce_sha256' => str_repeat('3', 64),
        'response_sha256' => str_repeat('4', 64),
    ]),
    'UTC', 3, '09:00:00', true, 101, 'exact client replay',
);
report_check(
    'exact client-scoped prepare replay is idempotent',
    $clientIdReplay['action'] === 'ignored'
        && (int)$clientIdReplay['schedule']['id'] === (int)$clientIdPrepared['schedule']['id']
        && (int)$pdo->query(
            'SELECT COUNT(*) FROM business_report_id_client_contact_snapshots WHERE client_id=11'
        )->fetchColumn() === 1,
);
report_throws(
    'client-scoped contact version cannot name another recipient',
    BusinessReportConflictException::class,
    fn() => business_report_prepare_client_schedule_from_id(
        $pdo, 'one', 'client-scoped-id-weekly', 11, (int)$definition['definition']['id'],
        array_replace($clientIdContact, ['recipient_email' => 'other@example.test']),
        'UTC', 3, '10:00:00', true, 101, 'client conflict',
    ),
    'different recipient',
);
$clientIdV2 = business_report_prepare_client_schedule_from_id(
    $pdo, 'one', 'client-scoped-id-weekly', 11, (int)$definition['definition']['id'],
    array_replace($clientIdContact, [
        'contact_version' => 2,
        'recipient_email' => 'new-customer-admin@example.test',
        'request_nonce_sha256' => str_repeat('5', 64),
        'response_sha256' => str_repeat('6', 64),
    ]),
    'UTC', 3, '10:00:00', true, 102, 'client contact v2',
);
report_check(
    'new client contact version appends disabled schedule and evidence',
    (int)$clientIdV2['schedule']['version_no'] === 2
        && (int)$clientIdV2['id_contact']['contact_version'] === 2
        && (int)$pdo->query(
            'SELECT COUNT(*) FROM business_report_id_client_contact_snapshots WHERE client_id=11'
        )->fetchColumn() === 2,
);
$clientIdEnabled = business_report_transition_schedule(
    $pdo,
    'one',
    'client-scoped-id-weekly',
    2,
    'active',
    101,
    'enable inherited client scope',
    report_config([
        'schedule_keys' => ['client-scoped-id-weekly'],
        'recipient_emails' => ['new-customer-admin@example.test'],
    ]),
);
$clientIdDisabled = business_report_transition_schedule(
    $pdo,
    'one',
    'client-scoped-id-weekly',
    3,
    'disabled',
    101,
    'disable inherited client scope',
    report_config(),
);
$clientInheritedScope = business_report_contact_scope_for_key(
    $pdo,
    1,
    'client-scoped-id-weekly',
);
report_check(
    'enable and disable versions inherit the latest client ID evidence',
    (int)$clientIdEnabled['schedule']['version_no'] === 3
        && (int)$clientIdDisabled['schedule']['version_no'] === 4
        && is_array($clientInheritedScope)
        && $clientInheritedScope['scope'] === BUSINESS_REPORT_CONTACT_SCOPE_CLIENT
        && (int)($clientInheritedScope['evidence']['contact_version'] ?? 0) === 2,
);
$tenantRuntimePrepared = business_report_prepare_schedule_from_id(
    $pdo, 'one', 'tenant-runtime-drift', 11, (int)$definition['definition']['id'],
    array_replace($newIdContact, [
        'request_nonce_sha256' => str_repeat('b', 64),
        'response_sha256' => str_repeat('c', 64),
    ]),
    'UTC', 3, '10:00:00', true, 101, 'tenant runtime drift fixture',
);
$tenantRuntimeActive = business_report_transition_schedule(
    $pdo, 'one', 'tenant-runtime-drift', 1, 'active', 101, 'enable valid tenant runtime fixture',
    report_config([
        'schedule_keys' => ['tenant-runtime-drift'],
        'recipient_emails' => ['new-id-reports@example.test'],
    ]),
);
$pdo->exec(
    "UPDATE business_report_schedule_versions
        SET recipient_email='tenant-runtime-substituted@example.test'
      WHERE id=" . (int)$tenantRuntimeActive['schedule']['id']
);
report_throws(
    'active tenant schedule read fails closed when its recipient differs from newest evidence',
    BusinessReportGateException::class,
    fn() => business_report_active_schedule($pdo, 'one', 'tenant-runtime-drift'),
    'latest immutable ID contact evidence',
);

$clientRuntimeContact = array_replace($clientIdContact, [
    'contact_version' => 2,
    'recipient_email' => 'new-customer-admin@example.test',
    'request_nonce_sha256' => str_repeat('7', 64),
    'response_sha256' => str_repeat('8', 64),
]);
$clientRuntimePrepared = business_report_prepare_client_schedule_from_id(
    $pdo, 'one', 'client-runtime-drift', 11, (int)$definition['definition']['id'],
    $clientRuntimeContact, 'UTC', 3, '10:00:00', true, 101, 'client runtime drift fixture',
);
$clientRuntimeActive = business_report_transition_schedule(
    $pdo, 'one', 'client-runtime-drift', 1, 'active', 101, 'enable valid client runtime fixture',
    report_config([
        'schedule_keys' => ['client-runtime-drift'],
        'recipient_emails' => ['new-customer-admin@example.test'],
    ]),
);
$pdo->exec(
    'UPDATE business_report_schedule_versions SET client_id=12 WHERE id='
    . (int)$clientRuntimeActive['schedule']['id']
);
report_throws(
    'active client schedule read fails closed when its client differs from newest evidence',
    BusinessReportGateException::class,
    fn() => business_report_active_schedule($pdo, 'one', 'client-runtime-drift'),
    'latest immutable ID contact evidence',
);
$driftedDue = business_report_due_schedule_keys(
    $pdo,
    time() + (21 * 86400),
    10,
    report_config([
        'schedule_keys' => ['tenant-runtime-drift', 'client-runtime-drift'],
        'client_keys' => ['safeharbor-client:11', 'safeharbor-client:12'],
        'recipient_emails' => [
            'tenant-runtime-substituted@example.test',
            'new-customer-admin@example.test',
        ],
    ]),
);
report_check(
    'due inventory omits active tenant and client schedules whose targets drift from ID evidence',
    $driftedDue === [],
);
$scopeBypassScheduleCount = (int)$pdo->query(
    'SELECT COUNT(*) FROM business_report_schedule_versions'
)->fetchColumn();
foreach ([
    'manual-to-tenant' => static fn() => business_report_prepare_schedule_from_id(
        $pdo, 'one', 'client-one-weekly', 11, (int)$definition['definition']['id'],
        $newIdContact, 'UTC', 3, '11:00:00', true, 101, 'manual to tenant bypass',
    ),
    'manual-to-client' => static fn() => business_report_prepare_client_schedule_from_id(
        $pdo, 'one', 'client-one-weekly', 11, (int)$definition['definition']['id'],
        $clientIdContact, 'UTC', 3, '11:00:00', true, 101, 'manual to client bypass',
    ),
    'tenant-to-client' => static fn() => business_report_prepare_client_schedule_from_id(
        $pdo, 'one', 'id-client-weekly', 11, (int)$definition['definition']['id'],
        $clientIdContact, 'UTC', 3, '11:00:00', true, 101, 'tenant to client bypass',
    ),
    'client-to-tenant' => static fn() => business_report_prepare_schedule_from_id(
        $pdo, 'one', 'client-scoped-id-weekly', 11, (int)$definition['definition']['id'],
        $newIdContact, 'UTC', 3, '11:00:00', true, 101, 'client to tenant bypass',
    ),
    'client-to-manual' => static fn() => business_report_prepare_schedule(
        $pdo, 'one', 'client-scoped-id-weekly', 11, (int)$definition['definition']['id'],
        'new-customer-admin@example.test', 'UTC', 3, '11:00:00', true, 101, 'client to manual bypass',
    ),
] as $scopeBypassName => $scopeBypass) {
    report_throws(
        "{$scopeBypassName} contact scope bypass is refused",
        BusinessReportConflictException::class,
        $scopeBypass,
        'contact scope',
    );
}
report_check(
    'all refused contact-scope bypasses leave schedule history unchanged',
    (int)$pdo->query('SELECT COUNT(*) FROM business_report_schedule_versions')->fetchColumn()
        === $scopeBypassScheduleCount,
);
report_throws(
    'client-scoped contact version cannot move backward',
    BusinessReportConflictException::class,
    fn() => business_report_prepare_client_schedule_from_id(
        $pdo, 'one', 'client-scoped-id-weekly', 11, (int)$definition['definition']['id'],
        $clientIdContact, 'UTC', 3, '11:00:00', true, 101, 'client rollback',
    ),
    'backward',
);
report_throws(
    'one client cannot be rebound to a different ID tenant',
    BusinessReportConflictException::class,
    fn() => business_report_prepare_client_schedule_from_id(
        $pdo, 'one', 'other-client-key', 11, (int)$definition['definition']['id'],
        array_replace($clientIdContact, ['tenant_key' => 'ewid-t5']),
        'UTC', 3, '09:00:00', true, 101, 'client rebind',
    ),
    'different 8 West ID tenant',
);
report_throws(
    'one ID tenant cannot be reused by another Safeharbor client',
    BusinessReportConflictException::class,
    fn() => business_report_prepare_client_schedule_from_id(
        $pdo, 'one', 'client-fourteen-key', 14, (int)$definition['definition']['id'],
        $clientIdContact, 'UTC', 3, '09:00:00', true, 101, 'ID tenant reuse',
    ),
    'different Safeharbor client',
);
report_throws(
    'client-scoped prepare cannot reach a client in another provider tenant',
    BusinessReportGateException::class,
    fn() => business_report_prepare_client_schedule_from_id(
        $pdo, 'one', 'cross-tenant-client', 22, (int)$definition['definition']['id'],
        array_replace($clientIdContact, ['tenant_key' => 'ewid-t6']),
        'UTC', 3, '09:00:00', true, 101, 'cross tenant client',
    ),
    'exact tenant',
);
$mixedScopeSchedule = business_report_prepare_schedule(
    $pdo, 'one', 'mixed-scope-fixture', 11, (int)$definition['definition']['id'],
    'mixed@example.test', 'UTC', 3, '09:00:00', true, 101, 'isolated mixed scope fixture',
);
$mixedScopeScheduleId = (int)$mixedScopeSchedule['schedule']['id'];
$pdo->exec("INSERT INTO business_report_id_contact_snapshots
    (tenant_id,schedule_version_id,id_tenant_key,contact_version,recipient_email,
     response_generated_at,request_nonce_sha256,response_sha256,created_by_user_id,reason)
    VALUES (1,{$mixedScopeScheduleId},'ewid-t1',9,'mixed@example.test',
     '2026-08-28 12:00:00','" . str_repeat('7', 64) . "','" . str_repeat('8', 64) . "',
     101,'deliberately mixed tenant lane')");
$pdo->exec("INSERT INTO business_report_id_client_contact_snapshots
    (tenant_id,client_id,schedule_version_id,id_tenant_key,contact_version,recipient_email,
     response_generated_at,request_nonce_sha256,response_sha256,created_by_user_id,reason)
    VALUES (1,11,{$mixedScopeScheduleId},'ewid-t4',3,'mixed@example.test',
     '2026-08-28 12:00:00','" . str_repeat('9', 64) . "','" . str_repeat('a', 64) . "',
     101,'deliberately mixed client lane')");
report_throws(
    'a deliberately mixed history fails closed instead of preferring one ID lane',
    BusinessReportGateException::class,
    fn() => business_report_contact_scope_for_key($pdo, 1, 'mixed-scope-fixture'),
    'manual report contact scope',
);
report_throws(
    'schedule enable requires the exact schedule key allowlist',
    BusinessReportGateException::class,
    fn() => business_report_transition_schedule(
        $pdo, 'one', 'client-one-weekly', 1, 'active', 101, 'bad schedule key',
        report_config(['schedule_keys' => []]),
    ),
    'allowlisted',
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
$clientNameUpdate = $pdo->prepare('UPDATE clients SET name=? WHERE tenant_id=1 AND id=11');
$clientNameUpdate->execute(["Client \u{202E}One"]);
report_throws(
    'generation refuses a display-direction control in the customer name',
    BusinessReportConflictException::class,
    fn() => business_report_generate(
        $pdo,
        'one',
        'client-one-weekly',
        report_config(),
        $now,
        true,
        false,
    ),
    'metric schema',
);
report_check(
    'display-direction generation refusal archives and delivers nothing',
    (int) $pdo->query('SELECT COUNT(*) FROM business_report_archives')->fetchColumn() === 0
        && (int) $pdo->query('SELECT COUNT(*) FROM business_report_deliveries')->fetchColumn() === 0,
);
$clientNameUpdate->execute(['Café München 東京']);
$internationalNameDryRun = business_report_generate(
    $pdo,
    'one',
    'client-one-weekly',
    report_config(),
    $now,
    true,
    false,
);
report_check(
    'generation preserves an ordinary international customer name',
    $internationalNameDryRun['metrics']['source']['client_name'] === 'Café München 東京'
        && str_contains($internationalNameDryRun['text'], 'Client: Café München 東京'),
);
$clientNameUpdate->execute(['Client One']);
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
report_throws(
    'generation requires the exact schedule key allowlist',
    BusinessReportGateException::class,
    fn() => business_report_generate(
        $pdo,
        'one',
        'client-one-weekly',
        report_config(['schedule_keys' => []]),
        $now,
        false,
        true,
    ),
    'allowlisted',
);
report_check(
    'schedule key generation refusal writes no archive',
    (int)$pdo->query('SELECT COUNT(*) FROM business_report_archives')->fetchColumn() === 0,
);

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

// Definition v1 promised the original approved-time model. It may honor the
// generated-at cutoff and tenant/client scope, but it must never silently
// reinterpret an applicable append-only adjustment.
$time->execute([6,1,11,20,'future-adjusted note',1,'approved','2026-08-12 12:00:00','2026-08-12 13:00:00']);
$time->execute([7,1,11,10,'applicable-adjusted note',1,'approved','2026-08-12 14:00:00','2026-08-12 15:00:00']);
$pdo->exec("INSERT INTO time_entry_approval_adjustments VALUES
    (1,1,6,'adjustment:future',1,5,1,'Created after report cutoff',101,'2026-08-27 00:00:00'),
    (2,2,5,'adjustment:other-tenant',1,1,1,'Other tenant only',201,'2026-08-25 00:00:00')");
$adjustmentSchedule = business_report_active_schedule($pdo, 'one', 'client-one-weekly');
$cutoffMetrics = business_report_metrics(
    $pdo,
    $adjustmentSchedule,
    '2026-08-10 00:00:00',
    '2026-08-17 00:00:00',
    '2026-08-26 12:00:00',
);
report_check(
    'definition v1 adjustment guard honors generated-at cutoff and tenant isolation',
    $cutoffMetrics['approved_billable_time']['minutes'] === 90,
);
$pdo->exec("INSERT INTO time_entry_approval_adjustments VALUES
    (3,1,7,'adjustment:applicable',1,5,1,'Applicable before report cutoff',101,'2026-08-25 00:00:00')");
report_throws(
    'definition v1 refuses applicable adjusted approved time',
    BusinessReportConflictException::class,
    fn() => business_report_metrics(
        $pdo,
        $adjustmentSchedule,
        '2026-08-10 00:00:00',
        '2026-08-17 00:00:00',
        '2026-08-26 12:00:00',
    ),
    'definition v1',
);
$unchangedArchivedContent = business_report_archived_content($reloaded);
report_check(
    'later adjustments do not rewrite an existing archive',
    $unchangedArchivedContent['metrics'] === $generated['metrics']
        && hash_equals((string)$reloaded['content_sha256'], (string)$generated['archive']['content_sha256']),
);
$unknownV1Metrics = $generated['metrics'];
$unknownV1Metrics['approved_billable_time']['adjustment_reason'] = 'not part of v1';
$unknownV1Archive = $reloaded;
$unknownV1Archive['metrics_json'] = business_report_metrics_json($unknownV1Metrics);
$unknownV1Archive['content_sha256'] = business_report_content_sha256_from_json(
    $unknownV1Archive['metrics_json'],
    (string) $unknownV1Archive['report_text'],
);
report_throws(
    'definition v1 archive reload refuses an unknown nested private field',
    BusinessReportConflictException::class,
    fn() => business_report_archived_content($unknownV1Archive),
    'metric schema',
);
report_check(
    'definition v1 valid archived JSON and report bytes remain exact',
    (string) $reloaded['metrics_json'] === business_report_metrics_json($generated['metrics'])
        && (string) $reloaded['report_text'] === $generated['text']
        && business_report_archived_content($reloaded) === $unchangedArchivedContent,
);
$duplicateV1Archive = report_archive_fixture(
    $pdo,
    (int) $enabled['schedule']['id'],
    'client-one-weekly',
    '2026-07-13 00:00:00',
);
$duplicateV1JsonCount = 0;
$duplicateV1Json = preg_replace(
    '/\A\{"schema_version":1,/',
    '{"schema_version":1,"schema_version":1,',
    (string) $duplicateV1Archive['metrics_json'],
    1,
    $duplicateV1JsonCount,
);
if (!is_string($duplicateV1Json) || $duplicateV1JsonCount !== 1) {
    throw new RuntimeException('Duplicate v1 JSON fixture could not be built exactly.');
}
$duplicateV1Hash = business_report_content_sha256_from_json(
    $duplicateV1Json,
    (string) $duplicateV1Archive['report_text'],
);
$pdo->prepare(
    'UPDATE business_report_archives SET metrics_json=?,content_sha256=? WHERE id=?',
)->execute([
    $duplicateV1Json,
    $duplicateV1Hash,
    (int) $duplicateV1Archive['id'],
]);
$duplicateV1Archive['metrics_json'] = $duplicateV1Json;
$duplicateV1Archive['content_sha256'] = $duplicateV1Hash;
report_throws(
    'definition v1 reload refuses duplicate JSON member names',
    BusinessReportConflictException::class,
    fn() => business_report_archived_content($duplicateV1Archive),
    'metric schema',
);
$duplicateV1TransportCalls = 0;
$duplicateV1AttemptsBefore = (int) $pdo->query(
    'SELECT COUNT(*) FROM business_report_delivery_attempts',
)->fetchColumn();
report_throws(
    'definition v1 delivery refuses duplicate JSON member names',
    BusinessReportConflictException::class,
    fn() => business_report_deliver(
        $pdo,
        (int) $duplicateV1Archive['id'],
        report_config(),
        function () use (&$duplicateV1TransportCalls): array {
            $duplicateV1TransportCalls++;
            return [
                'outcome' => 'submitted',
                'provider_http' => 202,
                'outcome_code' => 'graph_accepted',
            ];
        },
        $now,
    ),
    'metric schema',
);
report_check(
    'definition v1 duplicate JSON makes zero transport or delivery-attempt calls',
    $duplicateV1TransportCalls === 0
        && (int) $pdo->query(
            'SELECT COUNT(*) FROM business_report_delivery_attempts',
        )->fetchColumn() === $duplicateV1AttemptsBefore
        && (string) $pdo->query(
            'SELECT status FROM business_report_deliveries WHERE archive_id='
            . (int) $duplicateV1Archive['id'],
        )->fetchColumn() === 'pending',
);

// Definition v2 uses one effective row per approved entry. Multiple immutable
// slips are evidence, not additional time, and only slips inside both the
// durable adjustment-id prefix and generated-at cutoff may affect the total.
$time->execute([8,1,12,500,'other client adjusted note',1,'approved','2026-08-12 15:00:00','2026-08-12 16:00:00']);
$time->execute([9,1,11,40,'multi-adjusted private note',1,'approved','2026-08-12 16:00:00','2026-08-12 17:00:00']);
$pdo->exec("INSERT INTO time_entry_approval_adjustments VALUES
    (4,1,9,'adjustment:multi-one',1,30,1,'First private reason',101,'2026-08-24 00:00:00'),
    (5,1,9,'adjustment:multi-two',2,20,1,'Second private reason',102,'2026-08-25 00:00:00'),
    (6,1,8,'adjustment:other-client',1,1,1,'Other client reason',101,'2026-08-25 00:00:00'),
    (7,1,7,'adjustment:after-cutoff',2,0,0,'After cutoff reason',102,'2026-08-26 13:00:00')");
$v2Config = report_config([
    'schedule_keys' => ['client-one-weekly', 'client-one-weekly-v2'],
]);
$preparedV2 = business_report_prepare_schedule(
    $pdo,
    'one',
    'client-one-weekly-v2',
    11,
    (int) $definitionV2['definition']['id'],
    'reports@example.test',
    'UTC',
    3,
    '09:00:00',
    true,
    101,
    'prepare correction-aware schedule',
);
$enabledV2 = business_report_transition_schedule(
    $pdo,
    'one',
    'client-one-weekly-v2',
    (int) $preparedV2['schedule']['version_no'],
    'active',
    101,
    'enable correction-aware schedule',
    $v2Config,
);
$pdo->exec("UPDATE business_report_schedule_versions
              SET created_at='2026-08-19 00:00:00' WHERE id=" . (int) $enabledV2['schedule']['id']);
$v2Schedule = business_report_active_schedule($pdo, 'one', 'client-one-weekly-v2');
$v2CutoffMetrics = business_report_metrics(
    $pdo,
    $v2Schedule,
    '2026-08-10 00:00:00',
    '2026-08-17 00:00:00',
    '2026-08-26 12:00:00',
    6,
);
report_check(
    'definition v2 selects the latest slip inside the captured prefix and counts every approved entry once',
    $v2CutoffMetrics['schema_version'] === 1
        && $v2CutoffMetrics['approved_billable_time'] === [
            'adjustment_id_cutoff' => 6,
            'minutes' => 105,
            'original_approved_billable_minutes' => 130,
            'net_adjustment_minutes' => -25,
            'entries_with_adjustments_applied' => 2,
            'adjustment_slips_applied' => 3,
            'calculation' => 'each_approved_entry_once_using_latest_adjustment_at_id_cutoff_and_generated_at_else_original',
            'classification' => 'operational_approval_evidence_not_financial_status',
        ],
);
$v2LaterMetrics = business_report_metrics(
    $pdo,
    $v2Schedule,
    '2026-08-10 00:00:00',
    '2026-08-17 00:00:00',
    '2026-08-26 14:00:00',
    7,
);
report_check(
    'definition v2 durable cutoff advances to the later zeroing slip without double counting',
    $v2LaterMetrics['approved_billable_time']['minutes'] === 100
        && $v2LaterMetrics['approved_billable_time']['adjustment_id_cutoff'] === 7
        && $v2LaterMetrics['approved_billable_time']['original_approved_billable_minutes'] === 130
        && $v2LaterMetrics['approved_billable_time']['net_adjustment_minutes'] === -30
        && $v2LaterMetrics['approved_billable_time']['entries_with_adjustments_applied'] === 2
        && $v2LaterMetrics['approved_billable_time']['adjustment_slips_applied'] === 4,
);
$v2Text = business_report_text($v2CutoffMetrics);
report_check(
    'definition v2 text clearly presents correction totals without reasons or financial claims',
    str_contains($v2Text, 'after adjustments: 105 minutes (1.75 hours)')
        && str_contains($v2Text, 'Original approved billable operational time: 130 minutes')
        && str_contains($v2Text, 'Billable time net adjustment: -25 minutes')
        && str_contains($v2Text, 'Approved-time entries adjusted: 2')
        && str_contains($v2Text, 'Append-only adjustment slips applied: 3')
        && str_contains($v2Text, 'Each approved time entry is counted once')
        && !str_contains($v2Text, 'private reason')
        && !str_contains($v2Text, '$')
        && str_contains($v2Text, 'not a statement of export or invoice status'),
);
$v2Generated = business_report_generate(
    $pdo,
    'one',
    'client-one-weekly-v2',
    $v2Config,
    $now,
    false,
    true,
);
$v2Archive = $pdo->query(
    'SELECT * FROM business_report_archives WHERE id=' . (int) $v2Generated['archive']['id'],
)->fetch(PDO::FETCH_ASSOC);
report_check(
    'definition v2 archives an adjusted period with immutable exact content',
    is_array($v2Archive)
        && $v2Generated['action'] === 'created'
        && $v2Generated['metrics']['approved_billable_time']['minutes'] === 105
        && business_report_archived_content($v2Archive)['metrics'] === $v2Generated['metrics'],
);
$invalidV2Metrics = $v2Generated['metrics'];
$invalidV2Metrics['approved_billable_time']['net_adjustment_minutes'] = 999;
$invalidV2Archive = $v2Archive;
$invalidV2Archive['metrics_json'] = business_report_metrics_json($invalidV2Metrics);
$invalidV2Archive['content_sha256'] = business_report_content_sha256_from_json(
    $invalidV2Archive['metrics_json'],
    (string) $invalidV2Archive['report_text'],
);
report_throws(
    'archive reload refuses an internally inconsistent v2 correction summary',
    BusinessReportConflictException::class,
    fn() => business_report_archived_content($invalidV2Archive),
    'v2 adjustment summary',
);
$canonicalV2Text = (string) $v2Archive['report_text'];
$canonicalV2Hash = (string) $v2Archive['content_sha256'];
$forgedV2Text = "Private adjustment reason: secret\nInvoice has been posted\n";
$forgedV2Hash = business_report_content_sha256_from_json(
    (string) $v2Archive['metrics_json'],
    $forgedV2Text,
);
$forgeV2 = $pdo->prepare(
    'UPDATE business_report_archives SET report_text=?,content_sha256=? WHERE id=?'
);
$forgeV2->execute([$forgedV2Text, $forgedV2Hash, (int) $v2Archive['id']]);
$forgedV2TransportCalls = 0;
$forgedV2Delivery = function () use (
    $pdo,
    $v2Archive,
    $v2Config,
    $now,
    &$forgedV2TransportCalls,
): array {
    return business_report_deliver(
        $pdo,
        (int) $v2Archive['id'],
        $v2Config,
        function () use (&$forgedV2TransportCalls): array {
            $forgedV2TransportCalls++;
            return ['outcome' => 'submitted', 'provider_http' => 202, 'outcome_code' => 'graph_accepted'];
        },
        $now,
    );
};
report_throws(
    'self-hashed noncanonical v2 archive is refused before transport',
    BusinessReportConflictException::class,
    $forgedV2Delivery,
    'not canonical',
);
report_check(
    'noncanonical private and financial text never reaches transport',
    $forgedV2TransportCalls === 0,
);
$forgeV2->execute([$canonicalV2Text, $canonicalV2Hash, (int) $v2Archive['id']]);

$newlineMetrics = $v2Generated['metrics'];
$newlineClientName = (string) $newlineMetrics['source']['client_name']
    . "\nInvoice has been posted";
$newlineMetrics['source']['client_name'] = $newlineClientName;
$newlineText = str_replace(
    'Client: ' . $v2Generated['metrics']['source']['client_name'],
    'Client: ' . $newlineClientName,
    $canonicalV2Text,
);
report_throws(
    'renderer refuses newline-bearing metrics before producing report text',
    BusinessReportConflictException::class,
    fn() => business_report_text($newlineMetrics),
    'metric schema',
);
$unknownAdjustmentMetrics = $v2Generated['metrics'];
$unknownAdjustmentMetrics['approved_billable_time']['adjustment_reason'] =
    'private correction reason';
$unknownFinancialMetrics = $v2Generated['metrics'];
$unknownFinancialMetrics['invoice_status'] = 'posted';
$unknownFinancialMetrics['graph_client_secret'] = 'must never be archived';
$typeConfusedMetrics = $v2Generated['metrics'];
$typeConfusedMetrics['tickets']['resolved'] =
    (string) $typeConfusedMetrics['tickets']['resolved'];
$outOfRangeMetrics = $v2Generated['metrics'];
$outOfRangeMetrics['tickets']['opened'] = BUSINESS_REPORT_ARCHIVE_MAX_COUNT + 1;
$outOfRangeText = str_replace(
    'Tickets opened: ' . $v2Generated['metrics']['tickets']['opened'],
    'Tickets opened: ' . $outOfRangeMetrics['tickets']['opened'],
    $canonicalV2Text,
);
$nonfiniteMetricsJsonCount = 0;
$nonfiniteMetricsJson = preg_replace_callback(
    '/("average_score_out_of_3":)(?:null|-?[0-9]+(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?)/',
    static fn(array $match): string => $match[1] . '1e309',
    (string) $v2Archive['metrics_json'],
    1,
    $nonfiniteMetricsJsonCount,
);
if (!is_string($nonfiniteMetricsJson) || $nonfiniteMetricsJsonCount !== 1) {
    throw new RuntimeException('Nonfinite archive fixture could not be built exactly.');
}
$nonfiniteText = preg_replace(
    '/^CSAT average: .*$/m',
    'CSAT average: INF / 3',
    $canonicalV2Text,
    1,
);
if (!is_string($nonfiniteText)) {
    throw new RuntimeException('Nonfinite archive text fixture could not be built exactly.');
}
$formatControlMetrics = $v2Generated['metrics'];
$formatControlClientName = (string) $formatControlMetrics['source']['client_name']
    . "\u{202E}hidden";
$formatControlMetrics['source']['client_name'] = $formatControlClientName;
$formatControlText = str_replace(
    'Client: ' . $v2Generated['metrics']['source']['client_name'],
    'Client: ' . $formatControlClientName,
    $canonicalV2Text,
);
$duplicateV2JsonCount = 0;
$duplicateV2Json = preg_replace(
    '/\A\{"schema_version":1,/',
    '{"schema_version":1,"schema_version":1,',
    (string) $v2Archive['metrics_json'],
    1,
    $duplicateV2JsonCount,
);
if (!is_string($duplicateV2Json) || $duplicateV2JsonCount !== 1) {
    throw new RuntimeException('Duplicate v2 JSON fixture could not be built exactly.');
}
$hiddenRawV2Json = (string) $v2Archive['metrics_json'] . " \t\r\n";
$reorderedTopLevelMetrics = $v2Generated['metrics'];
$schemaVersionForReorder = $reorderedTopLevelMetrics['schema_version'];
unset($reorderedTopLevelMetrics['schema_version']);
$reorderedTopLevelMetrics['schema_version'] = $schemaVersionForReorder;
$reorderedNestedMetrics = $v2Generated['metrics'];
$tenantKeyForReorder = $reorderedNestedMetrics['source']['tenant_key'];
unset($reorderedNestedMetrics['source']['tenant_key']);
$reorderedNestedMetrics['source']['tenant_key'] = $tenantKeyForReorder;
$adversarialV2Archives = [
    'newline client text injection' => [
        business_report_metrics_json($newlineMetrics),
        $newlineText,
    ],
    'unknown nested adjustment_reason' => [
        business_report_metrics_json($unknownAdjustmentMetrics),
        $canonicalV2Text,
    ],
    'unknown secret and financial fields' => [
        business_report_metrics_json($unknownFinancialMetrics),
        $canonicalV2Text,
    ],
    'integer string type confusion' => [
        business_report_metrics_json($typeConfusedMetrics),
        $canonicalV2Text,
    ],
    'out-of-range integer' => [
        business_report_metrics_json($outOfRangeMetrics),
        $outOfRangeText,
    ],
    'nonfinite JSON number' => [
        $nonfiniteMetricsJson,
        $nonfiniteText,
    ],
    'Unicode display-direction control' => [
        business_report_metrics_json($formatControlMetrics),
        $formatControlText,
    ],
    'duplicate JSON member names' => [
        $duplicateV2Json,
        $canonicalV2Text,
    ],
    'hidden raw JSON whitespace bytes' => [
        $hiddenRawV2Json,
        $canonicalV2Text,
    ],
    'reordered top-level JSON members' => [
        business_report_metrics_json($reorderedTopLevelMetrics),
        $canonicalV2Text,
    ],
    'reordered nested JSON members' => [
        business_report_metrics_json($reorderedNestedMetrics),
        $canonicalV2Text,
    ],
];
$forgeV2Metrics = $pdo->prepare(
    'UPDATE business_report_archives
        SET metrics_json=?,report_text=?,content_sha256=? WHERE id=?'
);
foreach ($adversarialV2Archives as $name => [$forgedMetricsJson, $forgedText]) {
    $forgedHash = business_report_content_sha256_from_json($forgedMetricsJson, $forgedText);
    $forgedArchive = $v2Archive;
    $forgedArchive['metrics_json'] = $forgedMetricsJson;
    $forgedArchive['report_text'] = $forgedText;
    $forgedArchive['content_sha256'] = $forgedHash;
    report_throws(
        "archive reload refuses {$name}",
        BusinessReportConflictException::class,
        fn() => business_report_archived_content($forgedArchive),
        'metric schema',
    );

    $forgeV2Metrics->execute([
        $forgedMetricsJson,
        $forgedText,
        $forgedHash,
        (int) $v2Archive['id'],
    ]);
    $schemaTransportCalls = 0;
    $attemptsBeforeSchemaRefusal = (int) $pdo->query(
        'SELECT COUNT(*) FROM business_report_delivery_attempts',
    )->fetchColumn();
    report_throws(
        "delivery refuses {$name} before transport",
        BusinessReportConflictException::class,
        fn() => business_report_deliver(
            $pdo,
            (int) $v2Archive['id'],
            $v2Config,
            function () use (&$schemaTransportCalls): array {
                $schemaTransportCalls++;
                return [
                    'outcome' => 'submitted',
                    'provider_http' => 202,
                    'outcome_code' => 'graph_accepted',
                ];
            },
            $now,
        ),
        'metric schema',
    );
    report_check(
        "{$name} makes zero transport or delivery-attempt calls",
        $schemaTransportCalls === 0
            && (int) $pdo->query(
                'SELECT COUNT(*) FROM business_report_delivery_attempts',
            )->fetchColumn() === $attemptsBeforeSchemaRefusal
            && (string) $pdo->query(
                'SELECT status FROM business_report_deliveries WHERE archive_id='
                . (int) $v2Archive['id'],
            )->fetchColumn() === 'pending',
    );
}
$forgeV2Metrics->execute([
    (string) $v2Archive['metrics_json'],
    $canonicalV2Text,
    $canonicalV2Hash,
    (int) $v2Archive['id'],
]);
business_report_transition_schedule(
    $pdo,
    'one',
    'client-one-weekly-v2',
    (int) $enabledV2['schedule']['version_no'],
    'disabled',
    101,
    'stop v2 fixture',
);

report_throws(
    'missing dedicated report sender is refused before the send boundary',
    BusinessReportGateException::class,
    fn() => business_report_deliver(
        $pdo,
        $archiveId,
        report_config(['graph_sender' => '']),
        null,
        $now,
    ),
    'dedicated',
);
report_check(
    'report sender refusal creates no attempt and leaves delivery pending',
    (int)$pdo->query('SELECT COUNT(*) FROM business_report_delivery_attempts')->fetchColumn() === 0
        && (string)$pdo->query(
            "SELECT status FROM business_report_deliveries WHERE archive_id={$archiveId}",
        )->fetchColumn() === 'pending',
);

$reportCfgValues['mail.graph'] = [
    'tenant_id' => 'fixture-tenant',
    'client_id' => '',
    'client_secret' => 'fixture-client-secret',
    // Prove this is not the deciding field for report delivery.
    'sender' => '',
];
report_throws(
    'incomplete Graph credentials are refused before the delivery lease',
    BusinessReportGateException::class,
    fn() => business_report_deliver(
        $pdo,
        $archiveId,
        report_config(),
        null,
        $now,
    ),
    'credentials',
);
unset($reportCfgValues['mail.graph']);
report_check(
    'credential refusal creates no attempt and leaves delivery pending',
    (int)$pdo->query('SELECT COUNT(*) FROM business_report_delivery_attempts')->fetchColumn() === 0
        && (string)$pdo->query(
            "SELECT status FROM business_report_deliveries WHERE archive_id={$archiveId}",
        )->fetchColumn() === 'pending',
);

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
$scheduleGateCalls = 0;
report_throws(
    'delivery requires the exact schedule key allowlist before the send boundary',
    BusinessReportGateException::class,
    function () use ($pdo, $archiveId, $now, &$scheduleGateCalls): void {
        business_report_deliver(
            $pdo,
            $archiveId,
            report_config(['schedule_keys' => []]),
            function () use (&$scheduleGateCalls): array {
                $scheduleGateCalls++;
                return ['outcome' => 'submitted', 'provider_http' => 202, 'outcome_code' => 'graph_accepted'];
            },
            $now,
        );
    },
    'allowlisted',
);
report_check(
    'schedule key delivery refusal makes no transport call and keeps the delivery pending',
    $scheduleGateCalls === 0
        && (string)$pdo->query(
            "SELECT status FROM business_report_deliveries WHERE archive_id={$archiveId}",
        )->fetchColumn() === 'pending',
);
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
$rejectedArchive = report_archive_fixture($pdo, (int)$enabled['schedule']['id'], 'client-one-weekly', '2026-07-05 00:00:00');
$rejected = business_report_deliver(
    $pdo, (int)$rejectedArchive['id'], report_config(),
    fn(): array => ['outcome' => 'uncertain', 'provider_http' => 403, 'outcome_code' => 'graph_send_rejected'],
    $now,
);
$rejectedAttempt = $pdo->query(
    'SELECT provider_http,outcome_code FROM business_report_delivery_attempts WHERE delivery_id='
    . (int)$pdo->query(
        'SELECT id FROM business_report_deliveries WHERE archive_id=' . (int)$rejectedArchive['id'],
    )->fetchColumn(),
)->fetch(PDO::FETCH_ASSOC);
report_check('definite Graph rejection persists only safe status and category',
    $rejected['status'] === 'uncertain'
    && is_array($rejectedAttempt)
    && (int)$rejectedAttempt['provider_http'] === 403
    && $rejectedAttempt['outcome_code'] === 'graph_send_rejected');
$untrustedArchive = report_archive_fixture($pdo, (int)$enabled['schedule']['id'], 'client-one-weekly', '2026-07-04 00:00:00');
$untrusted = business_report_deliver(
    $pdo, (int)$untrustedArchive['id'], report_config(),
    fn(): array => ['outcome' => 'uncertain', 'provider_http' => 418, 'outcome_code' => 'private_secret'],
    $now,
);
$untrustedAttempt = $pdo->query(
    'SELECT provider_http,outcome_code FROM business_report_delivery_attempts WHERE delivery_id='
    . (int)$pdo->query(
        'SELECT id FROM business_report_deliveries WHERE archive_id=' . (int)$untrustedArchive['id'],
    )->fetchColumn(),
)->fetch(PDO::FETCH_ASSOC);
report_check('unapproved transport evidence is replaced instead of persisted',
    $untrusted['status'] === 'uncertain'
    && is_array($untrustedAttempt)
    && $untrustedAttempt['provider_http'] === null
    && $untrustedAttempt['outcome_code'] === 'invalid_transport_outcome');
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

$markExpiredSendBoundary = static function (
    array $archive,
    string $token,
) use ($pdo): int {
    $deliveryId = (int) $pdo->query(
        'SELECT id FROM business_report_deliveries WHERE archive_id=' . (int) $archive['id'],
    )->fetchColumn();
    $pdo->prepare(
        "UPDATE business_report_deliveries
            SET status='sending',lease_token_hash=?,lease_expires_at='2026-08-26 11:00:00',
                last_attempt_at='2026-08-26 10:58:00'
          WHERE id=?",
    )->execute([hash('sha256', $token), $deliveryId]);
    $pdo->prepare(
        "INSERT INTO business_report_delivery_attempts
            (tenant_id,delivery_id,attempt_key,provider,status,started_at)
         VALUES (?,?,?,'microsoft_graph','started','2026-08-26 10:58:00')",
    )->execute([1, $deliveryId, hash('sha256', $token . ':attempt')]);
    return $deliveryId;
};

$legacyInvalidExpired = report_archive_fixture(
    $pdo,
    (int) $enabled['schedule']['id'],
    'client-one-weekly',
    '2026-05-18 00:00:00',
);
$legacyInvalidMetrics = json_decode(
    (string) $legacyInvalidExpired['metrics_json'],
    true,
    512,
    JSON_THROW_ON_ERROR,
);
$legacyInvalidMetrics['approved_billable_time']['legacy_private_field'] = 'old schema';
$legacyInvalidJson = business_report_metrics_json($legacyInvalidMetrics);
$legacyInvalidHash = business_report_content_sha256_from_json(
    $legacyInvalidJson,
    (string) $legacyInvalidExpired['report_text'],
);
$pdo->prepare(
    'UPDATE business_report_archives SET metrics_json=?,content_sha256=? WHERE id=?',
)->execute([
    $legacyInvalidJson,
    $legacyInvalidHash,
    (int) $legacyInvalidExpired['id'],
]);
$legacyInvalidDeliveryId = $markExpiredSendBoundary(
    $legacyInvalidExpired,
    'legacy-invalid-expired',
);
$legacyInvalidRecoveryCalls = 0;
$legacyInvalidRecovered = business_report_deliver(
    $pdo,
    (int) $legacyInvalidExpired['id'],
    report_config(),
    function () use (&$legacyInvalidRecoveryCalls): array {
        $legacyInvalidRecoveryCalls++;
        return [
            'outcome' => 'submitted',
            'provider_http' => 202,
            'outcome_code' => 'graph_accepted',
        ];
    },
    $now,
);
$legacyInvalidDelivery = $pdo->query(
    'SELECT status FROM business_report_deliveries WHERE id=' . $legacyInvalidDeliveryId,
)->fetchColumn();
$legacyInvalidAttempt = $pdo->query(
    'SELECT status,outcome_code FROM business_report_delivery_attempts WHERE delivery_id='
    . $legacyInvalidDeliveryId,
)->fetch(PDO::FETCH_ASSOC);
report_check(
    'expired crossed-boundary legacy-invalid archive becomes terminal uncertain',
    $legacyInvalidRecovered['action'] === 'recovered_uncertain'
        && $legacyInvalidRecoveryCalls === 0
        && $legacyInvalidDelivery === 'uncertain'
        && is_array($legacyInvalidAttempt)
        && $legacyInvalidAttempt['status'] === 'uncertain'
        && $legacyInvalidAttempt['outcome_code'] === 'lease_expired_after_send_boundary',
);

$unverifiableExpired = report_archive_fixture(
    $pdo,
    (int) $enabled['schedule']['id'],
    'client-one-weekly',
    '2026-05-11 00:00:00',
);
$pdo->prepare('UPDATE business_report_archives SET report_text=? WHERE id=?')->execute([
    'unverifiable raw bytes',
    (int) $unverifiableExpired['id'],
]);
$unverifiableDeliveryId = $markExpiredSendBoundary($unverifiableExpired, 'bad-hash-expired');
$unverifiableCalls = 0;
report_throws(
    'expired crossed boundary with an unverifiable hash fails closed',
    BusinessReportConflictException::class,
    fn() => business_report_deliver(
        $pdo,
        (int) $unverifiableExpired['id'],
        report_config(),
        function () use (&$unverifiableCalls): array {
            $unverifiableCalls++;
            return [
                'outcome' => 'submitted',
                'provider_http' => 202,
                'outcome_code' => 'graph_accepted',
            ];
        },
        $now,
    ),
    'hash',
);
report_check(
    'unverifiable crossed boundary remains untouched with zero transport',
    $unverifiableCalls === 0
        && (string) $pdo->query(
            'SELECT status FROM business_report_deliveries WHERE id=' . $unverifiableDeliveryId,
        )->fetchColumn() === 'sending'
        && (string) $pdo->query(
            'SELECT status FROM business_report_delivery_attempts WHERE delivery_id='
            . $unverifiableDeliveryId,
        )->fetchColumn() === 'started',
);
$unverifiableCleanup = $pdo->query(
    'SELECT * FROM business_report_deliveries WHERE id=' . $unverifiableDeliveryId,
)->fetch(PDO::FETCH_ASSOC);
if (is_array($unverifiableCleanup)) {
    business_report_recover_expired_delivery($pdo, $unverifiableCleanup, gmdate('Y-m-d H:i:s', $now));
}

$wrongScopeExpired = report_archive_fixture(
    $pdo,
    (int) $enabled['schedule']['id'],
    'client-one-weekly',
    '2026-05-04 00:00:00',
);
$wrongScopeMetrics = json_decode(
    (string) $wrongScopeExpired['metrics_json'],
    true,
    512,
    JSON_THROW_ON_ERROR,
);
$wrongScopeMetrics['source']['client_key'] = 'safeharbor-client:12';
$wrongScopeJson = business_report_metrics_json($wrongScopeMetrics);
$wrongScopeHash = business_report_content_sha256_from_json(
    $wrongScopeJson,
    (string) $wrongScopeExpired['report_text'],
);
$pdo->prepare(
    'UPDATE business_report_archives SET metrics_json=?,content_sha256=? WHERE id=?',
)->execute([$wrongScopeJson, $wrongScopeHash, (int) $wrongScopeExpired['id']]);
$wrongScopeDeliveryId = $markExpiredSendBoundary($wrongScopeExpired, 'wrong-scope-expired');
$wrongScopeCalls = 0;
report_throws(
    'expired crossed boundary with unverifiable source scope fails closed',
    BusinessReportConflictException::class,
    fn() => business_report_deliver(
        $pdo,
        (int) $wrongScopeExpired['id'],
        report_config(),
        function () use (&$wrongScopeCalls): array {
            $wrongScopeCalls++;
            return [
                'outcome' => 'submitted',
                'provider_http' => 202,
                'outcome_code' => 'graph_accepted',
            ];
        },
        $now,
    ),
    'source',
);
report_check(
    'wrong-scope crossed boundary remains untouched with zero transport',
    $wrongScopeCalls === 0
        && (string) $pdo->query(
            'SELECT status FROM business_report_deliveries WHERE id=' . $wrongScopeDeliveryId,
        )->fetchColumn() === 'sending'
        && (string) $pdo->query(
            'SELECT status FROM business_report_delivery_attempts WHERE delivery_id='
            . $wrongScopeDeliveryId,
        )->fetchColumn() === 'started',
);
$wrongScopeCleanup = $pdo->query(
    'SELECT * FROM business_report_deliveries WHERE id=' . $wrongScopeDeliveryId,
)->fetch(PDO::FETCH_ASSOC);
if (is_array($wrongScopeCleanup)) {
    business_report_recover_expired_delivery($pdo, $wrongScopeCleanup, gmdate('Y-m-d H:i:s', $now));
}

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
    report_config([
        'schedule_keys' => [], 'tenant_slugs' => [],
        'client_keys' => [], 'recipient_emails' => [],
    ]),
);
report_check(
    'scheduler omits revoked pending rows but retains expired send-boundary recovery',
    $revokedEnumeration === [(int)$revokedExpired['id']],
);
$revokedExpiredCalls = 0;
$revokedRecovered = business_report_deliver(
    $pdo,
    (int)$revokedExpired['id'],
    report_config([
        'schedule_keys' => [], 'tenant_slugs' => [],
        'client_keys' => [], 'recipient_emails' => [],
    ]),
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

// Starvation guard: 101 due active schedules, only the last key and recipient allowed.
$starvationScopeInsert = $pdo->prepare(
    'INSERT INTO business_report_contact_scope_bindings
        (tenant_id,schedule_key,contact_scope,created_by_user_id,reason)
     VALUES (1,?,?,101,?)'
);
for ($i = 1; $i <= 101; $i++) {
    $key = sprintf('starve-%03d', $i);
    $recipient = $i === 101 ? 'allowed@example.test' : sprintf('blocked%03d@example.test', $i);
    $starvationScopeInsert->execute([
        $key,
        BUSINESS_REPORT_CONTACT_SCOPE_MANUAL,
        'starvation inventory fixture',
    ]);
    $insert = $pdo->prepare(
        "INSERT INTO business_report_schedule_versions
            (tenant_id,schedule_key,version_no,definition_version_id,client_id,recipient_email,
             schedule_timezone,delivery_weekday,delivery_local_time,canary,status,
             created_by_user_id,reason,created_at)
         VALUES (1,?,1,?,11,?,'UTC',3,'09:00:00',1,'active',101,'fixture','2026-08-19 00:00:00')"
    );
    $insert->execute([$key, (int)$definition['definition']['id'], $recipient]);
}
$starvationConfig = report_config([
    'schedule_keys' => ['starve-101'],
    'recipient_emails' => ['allowed@example.test'],
]);
$due = business_report_due_schedule_keys($pdo, $now, 1, $starvationConfig);
report_check('more than one hundred blocked schedules cannot starve an allowed due schedule',
    $due === [['tenant_slug' => 'one', 'schedule_key' => 'starve-101']]);

$source = file_get_contents(__DIR__ . '/../lib/business_reports.php') ?: '';
foreach (['t.subject', 'm.body', 'time_entries.note', 'review_note', 'contacts ', 'attachments '] as $forbidden) {
    report_check("report query source excludes {$forbidden}", !str_contains($source, $forbidden));
}
$managerSource = file_get_contents(__DIR__ . '/../db/manage_business_reports.php') ?: '';
report_check(
    'operator publication requires an explicit supported definition version',
    str_contains($managerSource, '--definition-version=1|2')
        && str_contains(
            $managerSource,
            "report_cli_expect(\$options, ['tenant-slug', 'definition-version', 'actor-user-id', 'reason'])",
        )
        && str_contains($managerSource, "in_array(\$options['definition-version'], ['1', '2'], true)"),
);

$planStart = strpos($source, 'function business_report_plan_customer_schedule_from_id(');
$prepareStart = strpos($source, 'function business_report_prepare_schedule(');
$planSource = is_int($planStart) && is_int($prepareStart) && $prepareStart > $planStart
    ? substr($source, $planStart, $prepareStart - $planStart)
    : '';
report_check(
    'managed-customer planning source contains no database mutation or delivery boundary',
    $planSource !== ''
        && preg_match('/\b(?:INSERT|UPDATE|DELETE|REPLACE)\b/i', $planSource) !== 1
        && !str_contains($planSource, 'beginTransaction')
        && !str_contains($planSource, '->commit(')
        && !str_contains($planSource, '->rollBack(')
        && !str_contains($planSource, 'business_report_prepare_')
        && !str_contains($planSource, 'business_report_generate(')
        && !str_contains($planSource, 'business_report_deliver(')
        && !str_contains($planSource, 'mailer_')
        && !str_contains($planSource, 'graph_'),
);

$cronSource = file_get_contents(__DIR__ . '/../cron/business_reports.php') ?: '';
report_check(
    'scheduled runner refreshes its clock for enumeration and each operation',
    !str_contains($cronSource, '$now = time()')
        && substr_count($cronSource, 'time()') >= 4,
);
report_check(
    'scheduled runner retains the database advisory lock',
    str_contains($cronSource, "GET_LOCK('safeharbor_business_reports_v1', 0)")
        && str_contains($cronSource, "RELEASE_LOCK('safeharbor_business_reports_v1')"),
);
report_check(
    'scheduled runner distinguishes overlap from advisory-lock failure',
    str_contains($cronSource, 'business_report_advisory_lock_state($lock)')
        && str_contains($cronSource, "\$lockState === 'contended'")
        && str_contains($cronSource, 'Business report advisory lock failed.')
        && str_contains($cronSource, 'business_report_advisory_lock_release($released)')
        && str_contains($cronSource, 'Business report advisory lock release failed.'),
);

$schedulerWrapper = file_get_contents(__DIR__ . '/../cron/run_business_reports.sh') ?: '';
$schedulerTemplate = file_get_contents(__DIR__ . '/../../deploy/safeharbor-business-reports.cron') ?: '';
$schedulerManager = file_get_contents(__DIR__ . '/../../deploy/manage-business-report-scheduler.sh') ?: '';
$artifactHasher = file_get_contents(__DIR__ . '/../../deploy/hash-safeharbor-app-artifact.sh') ?: '';
$deploySource = file_get_contents(__DIR__ . '/../../deploy/deploy.sh') ?: '';
$remoteDeployer = file_get_contents(__DIR__ . '/../../deploy/remote-install-safeharbor-app.sh') ?: '';
$schedulerBehaviorTest = file_get_contents(__DIR__ . '/business_report_scheduler_linux_test.sh') ?: '';
report_check(
    'scheduler template has one exact www-data wrapper invocation',
    substr_count(
        $schedulerTemplate,
        '*/5 * * * * www-data /usr/bin/bash /srv/8west/apps/safeharbor/current/cron/run_business_reports.sh',
    ) === 1
        && !str_contains($schedulerTemplate, 'business_reports.php'),
);
report_check(
    'scheduler wrapper pins runtime deployment metadata and holds the shared deploy lock',
    str_contains($schedulerWrapper, "readonly EXPECTED_USER='www-data'")
        && str_contains($schedulerWrapper, "readonly PHP_BIN='/usr/bin/php'")
        && str_contains($schedulerWrapper, '[[ "$(/usr/bin/id -un)" == "$EXPECTED_USER" ]]')
        && str_contains($schedulerWrapper, '[[ "$(pwd -P)" == "$APP_ROOT" ]]')
        && str_contains($schedulerWrapper, "readonly DEPLOY_LOCK=\"\$DEPLOY_LOCK_DIR/business-reports-deploy.lock\"")
        && str_contains($schedulerWrapper, '/usr/bin/flock --shared --nonblock 9')
        && str_contains($schedulerWrapper, "require_metadata \"\$PROTECTED_CONFIG\" \"\$DEPLOY_OWNER\" www-data 640"),
);
report_check(
    'scheduler wrapper preserves PHP failure status even when its final logger call fails',
    str_contains($schedulerWrapper, 'mktemp /tmp/safeharbor-business-reports.')
        && str_contains($schedulerWrapper, '"$PHP_BIN" "$REPORT_RUNNER" >"$report_output" 2>&1')
        && str_contains($schedulerWrapper, '<"$report_output"')
        && !str_contains($schedulerWrapper, '| "$LOGGER_BIN"')
        && str_contains($schedulerWrapper, '--tag "$LOG_TAG" --priority user.notice')
        && str_contains($schedulerWrapper, '--tag "$LOG_TAG" --priority user.err')
        && str_contains($schedulerWrapper, 'unable to log runner status')
        && str_contains($schedulerWrapper, 'If both sides fail, preserve that primary')
        && str_contains($schedulerWrapper, 'exit "$php_status"'),
);
report_check(
    'scheduler operations install disabled from a root-only full-artifact bundle',
    str_contains($schedulerManager, 'readonly CRON_ACTIVE="$ROOT_PREFIX/etc/cron.d/safeharbor-business-reports"')
        && str_contains($schedulerManager, 'readonly CRON_DISABLED="$ROOT_PREFIX/etc/cron.d/safeharbor-business-reports.disabled"')
        && str_contains($schedulerManager, '/usr/bin/install -o root -g root -m 0644 -- "$CRON_SOURCE" "$CRON_DISABLED"')
        && str_contains($schedulerManager, "verify_metadata \"\$SCRIPT_DIR\" root root 700")
        && str_contains($schedulerManager, 'hasher_sha256,source_artifact_sha256,deployed_artifact_sha256,release_marker_sha256')
        && str_contains($schedulerManager, 'safeharbor-business-report-scheduler-bundle-v2')
        && str_contains($schedulerManager, "reviewed-cron-source-has-unexpected-line")
        && str_contains($schedulerManager, "reviewed-cron-source-schedule-count-invalid"),
);
report_check(
    'artifact hasher binds every deployed file and directory except protected config',
    str_contains($artifactHasher, "! -path \"\$APP_ROOT/config/config.php\"")
        && str_contains($artifactHasher, "printf 'D\\0%s\\0'")
        && str_contains($artifactHasher, "printf 'F\\0%s\\0%s\\0'")
        && str_contains($artifactHasher, '/usr/bin/sort -z')
        && !str_contains($artifactHasher, 'cat "$APP_ROOT/config/config.php"'),
);
report_check(
    'scheduler activation binds exact artifact config sender and canary tuple evidence',
    str_contains($schedulerManager, "safeharbor-business-report-scheduler-activation-v2")
        && str_contains($schedulerManager, "'reports@8westit.com'")
        && str_contains($schedulerManager, 'protected_config_sha256')
        && str_contains($schedulerManager, 'deployed_artifact_sha256')
        && str_contains($schedulerManager, 'release_marker_sha256')
        && str_contains($schedulerManager, 'bundle_manifest_sha256')
        && str_contains($schedulerManager, '--activation-evidence')
        && str_contains($schedulerManager, '--expect-tenant-slug')
        && str_contains($schedulerManager, '--expect-client-id')
        && str_contains($schedulerManager, '--expect-schedule-key')
        && str_contains($schedulerManager, '--expect-recipient'),
);
report_check(
    'scheduler operations verify exact content and remove only the disabled live-control file',
    str_contains($schedulerManager, '/usr/bin/cmp -s -- "$CRON_SOURCE" "$target"')
        && substr_count($schedulerManager, '/usr/bin/unlink -- "$CRON_DISABLED"') === 1
        && !preg_match('/(?:^|[\s\/])rm(?:\s|$)/m', $schedulerManager)
        && !str_contains($schedulerManager, '--recursive'),
);
report_check(
    'scheduler emergency disable always quarantines active and activation evidence',
    str_contains($schedulerManager, "quarantine_exact_path \"\$CRON_ACTIVE\" 'cron-active'")
        && str_contains($schedulerManager, "quarantine_exact_path \"\$ACTIVATION_RECORD\" 'activation-record'")
        && str_contains($schedulerManager, 'SCHEDULER_IN_FLIGHT=not-stopped-check-deploy-lock')
        && str_contains($schedulerManager, 'deliberately does not kill or wait'),
);
report_check(
    'scheduler operations hash but never parse print or execute protected config',
    !str_contains($schedulerWrapper, 'source "$PROTECTED_CONFIG"')
        && !str_contains($schedulerManager, 'source "$PROTECTED_CONFIG"')
        && !str_contains($schedulerManager, '/usr/bin/php "$REPORT_RUNNER"')
        && str_contains($schedulerManager, '/usr/bin/php -l "$REPORT_RUNNER"')
        && str_contains($schedulerManager, 'config_hash="$(sha256_file "$PROTECTED_CONFIG")"')
        && !str_contains($schedulerManager, '/usr/bin/curl')
        && !str_contains($schedulerManager, '/usr/bin/wget')
        && !str_contains($schedulerManager, '/usr/bin/mail')
        && !str_contains($schedulerManager, '/usr/sbin/sendmail'),
);
report_check(
    'normal application deploy is serialized and records a clean full artifact without activating cron',
    str_contains($deploySource, 'remote-install-safeharbor-app.sh')
        && str_contains($deploySource, 'require_clean_release')
        && str_contains($deploySource, 'archive --format=tar "$RELEASE_SHA" app brand')
        && str_contains($deploySource, '-C "$RELEASE_STAGING"')
        && str_contains($deploySource, '--expect-source-artifact-sha256')
        && str_contains($remoteDeployer, 'active-report-scheduler-present')
        && str_contains($remoteDeployer, 'legacy-report-runner-in-flight')
        && str_contains($remoteDeployer, '/usr/bin/flock --exclusive --nonblock 9')
        && str_contains($remoteDeployer, 'staged-source-artifact-digest-mismatch')
        && str_contains($remoteDeployer, 'source-artifact-digest-mismatch')
        && str_contains($remoteDeployer, 'current-app-artifact.manifest')
        && str_contains($remoteDeployer, 'SAFEHARBOR_DEPLOY=installed-under-exclusive-report-lock')
        && !str_contains($deploySource, 'manage-business-report-scheduler.sh')
        && !str_contains($remoteDeployer, '/usr/bin/install -o root -g root -m 0644 -- "$CRON_SOURCE"'),
);
report_check(
    'CI executes Linux scheduler lifecycle artifact logger and deploy-quiescence tests',
    str_contains($schedulerBehaviorTest, 'both-path emergency disable always removes active cron')
        && str_contains($schedulerBehaviorTest, 'runtime-owned deployment file is rejected')
        && str_contains($schedulerBehaviorTest, 'final logger failure preserves the PHP runner status')
        && str_contains($schedulerBehaviorTest, 'simultaneous PHP and main logger failure preserves PHP status')
        && str_contains($schedulerBehaviorTest, 'early-closing logger cannot contaminate PHP status')
        && str_contains($schedulerBehaviorTest, 'mid-stream logger failure cannot contaminate PHP status')
        && str_contains($schedulerBehaviorTest, 'preflight rejects loaded-library content drift')
        && str_contains($schedulerBehaviorTest, 'old activation evidence cannot reactivate after locked deployment')
        && str_contains($schedulerBehaviorTest, 'deploy refuses while a report run still holds the shared lock'),
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
