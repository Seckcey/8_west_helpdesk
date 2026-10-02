<?php
/** Hermetic customer-portal archive scope, verification, and rendering tests. */
declare(strict_types=1);

function portal_csrf_token(): string
{
    return str_repeat('c', 64);
}

function portal_action_nonce(string $purpose, ?int $now = null): string
{
    return hash('sha256', $purpose . ':' . ($now ?? 1));
}

require_once __DIR__ . '/../lib/business_reports.php';
require_once __DIR__ . '/../lib/portal_data.php';
require_once __DIR__ . '/../lib/portal_render.php';

$checks = 0;
$failures = 0;

function portal_report_check(string $name, bool $condition): void
{
    global $checks, $failures;
    $checks++;
    if ($condition) {
        echo "ok {$checks} - {$name}\n";
        return;
    }
    $failures++;
    echo "FAIL {$checks} - {$name}\n";
}

/** @param class-string<Throwable> $expected */
function portal_report_expect(string $name, string $expected, callable $operation): void
{
    try {
        $operation();
    } catch (Throwable $error) {
        portal_report_check($name, $error instanceof $expected);
        return;
    }
    portal_report_check($name, false);
}

function portal_report_digest(PDO $pdo): string
{
    $tables = $pdo->query(
        "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
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

/** @return array<string,mixed> */
function portal_report_metrics_fixture(
    string $tenantSlug,
    int $clientId,
    string $clientName,
    string $start,
    string $end,
): array {
    return [
        'schema_version' => 1,
        'report_type' => BUSINESS_REPORT_TYPE,
        'definition' => [
            'key' => BUSINESS_REPORT_DEFINITION_KEY,
            'version' => 1,
            'sha256' => business_report_contract_sha256(),
        ],
        'source' => [
            'tenant_key' => $tenantSlug,
            'client_key' => 'safeharbor-client:' . $clientId,
            'client_name' => $clientName,
        ],
        'period' => [
            'start_utc' => str_replace(' ', 'T', $start) . 'Z',
            'end_utc_exclusive' => str_replace(' ', 'T', $end) . 'Z',
            'schedule_timezone' => 'America/Los_Angeles',
        ],
        'generated_at' => '2026-08-29T03:27:01Z',
        'tickets' => [
            'opened' => 2,
            'resolved' => 1,
            'merged_histories_excluded_from_response_metrics' => 0,
        ],
        'first_response' => ['answered' => 2, 'average_minutes' => 45],
        'service_goal' => [
            'eligible_versioned' => 2,
            'legacy_unversioned_excluded' => 0,
            'decided' => 2,
            'met' => 2,
            'attainment_percent' => 100,
            'undecided' => 0,
        ],
        'approved_billable_time' => [
            'minutes' => 90,
            'classification' => 'operational_approval_evidence_not_financial_status',
        ],
        'csat' => [
            'surveys_sent' => 1,
            'responses_received_by_generated_at' => 1,
            'average_score_out_of_3' => 3.0,
        ],
        'delivery_truth' => 'A provider acceptance is submission evidence, not inbox delivery proof.',
    ];
}

function portal_report_insert_archive(
    PDO $pdo,
    int $tenantId,
    int $clientId,
    int $definitionId,
    string $tenantSlug,
    string $clientName,
    string $scheduleKey,
    string $start,
    string $end,
): int {
    $metrics = portal_report_metrics_fixture($tenantSlug, $clientId, $clientName, $start, $end);
    $json = business_report_metrics_json($metrics);
    $text = business_report_text($metrics);
    $scheduleVersionId = ($tenantId * 1000) + $clientId;
    $schedule = $pdo->prepare(
        'INSERT INTO business_report_schedule_versions
            (id,tenant_id,schedule_key,client_id,definition_version_id,schedule_timezone)
         VALUES (?,?,?,?,?,?)'
    );
    $schedule->execute([
        $scheduleVersionId,
        $tenantId,
        $scheduleKey,
        $clientId,
        $definitionId,
        'America/Los_Angeles',
    ]);
    $insert = $pdo->prepare(
        'INSERT INTO business_report_archives
            (tenant_id,client_id,schedule_key,schedule_version_id,definition_version_id,
             period_start,period_end,generated_at,metrics_json,report_text,content_sha256)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)'
    );
    $insert->execute([
        $tenantId,
        $clientId,
        $scheduleKey,
        $scheduleVersionId,
        $definitionId,
        $start,
        $end,
        '2026-08-29 03:27:01',
        $json,
        $text,
        business_report_content_sha256_from_json($json, $text),
    ]);
    return (int)$pdo->lastInsertId();
}

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('CREATE TABLE tenants (id INTEGER PRIMARY KEY, name TEXT NOT NULL, slug TEXT NOT NULL UNIQUE)');
$pdo->exec('CREATE TABLE clients (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name TEXT NOT NULL, UNIQUE(tenant_id,id))');
$pdo->exec('CREATE TABLE business_report_definition_versions (
    id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, definition_key TEXT NOT NULL,
    version_no INTEGER NOT NULL, report_type TEXT NOT NULL, contract_json TEXT NOT NULL,
    contract_sha256 TEXT NOT NULL, UNIQUE(tenant_id,id))');
$pdo->exec('CREATE TABLE business_report_schedule_versions (
    id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, schedule_key TEXT NOT NULL,
    client_id INTEGER NOT NULL, definition_version_id INTEGER NOT NULL,
    schedule_timezone TEXT NOT NULL, UNIQUE(tenant_id,id))');
$pdo->exec('CREATE TABLE business_report_archives (
    id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, client_id INTEGER NOT NULL,
    schedule_key TEXT NOT NULL, schedule_version_id INTEGER NOT NULL, definition_version_id INTEGER NOT NULL,
    period_start TEXT NOT NULL, period_end TEXT NOT NULL, generated_at TEXT NOT NULL,
    metrics_json TEXT NOT NULL, report_text TEXT NOT NULL, content_sha256 TEXT NOT NULL)');
$pdo->exec("INSERT INTO tenants (id,name,slug) VALUES (1,'Provider One','one'),(2,'Provider Two','two')");
$pdo->exec("INSERT INTO clients (id,tenant_id,name) VALUES
    (11,1,'Acme <script>alert(1)</script>'),(12,1,'Other Customer'),(21,2,'Other Provider Customer')");
$definition = $pdo->prepare(
    'INSERT INTO business_report_definition_versions
        (id,tenant_id,definition_key,version_no,report_type,contract_json,contract_sha256)
     VALUES (?,?,?,?,?,?,?)'
);
foreach ([[101, 1], [201, 2]] as [$definitionId, $tenantId]) {
    $definition->execute([
        $definitionId,
        $tenantId,
        BUSINESS_REPORT_DEFINITION_KEY,
        1,
        BUSINESS_REPORT_TYPE,
        business_report_contract_json(),
        business_report_contract_sha256(),
    ]);
}

$ownArchiveId = portal_report_insert_archive(
    $pdo, 1, 11, 101, 'one', 'Acme <script>alert(1)</script>', 'acme-weekly',
    '2026-08-17 07:00:00', '2026-08-24 07:00:00',
);
$otherClientArchiveId = portal_report_insert_archive(
    $pdo, 1, 12, 101, 'one', 'Other Customer', 'other-weekly',
    '2026-08-17 07:00:00', '2026-08-24 07:00:00',
);
$otherTenantArchiveId = portal_report_insert_archive(
    $pdo, 2, 21, 201, 'two', 'Other Provider Customer', 'provider-two-weekly',
    '2026-08-17 07:00:00', '2026-08-24 07:00:00',
);

$beforeRead = portal_report_digest($pdo);
$archives = portal_report_archives($pdo, 1, 11, 24);
portal_report_check('archive list returns only the exact signed-in tenant and customer',
    count($archives) === 1 && (int)$archives[0]['id'] === $ownArchiveId);
portal_report_check('archive list verifies and exposes only aggregate customer metrics',
    (int)$archives[0]['metrics']['tickets']['opened'] === 2
    && (int)$archives[0]['metrics']['approved_billable_time']['minutes'] === 90
    && !array_key_exists('recipient_email', $archives[0]));
$archive = portal_report_archive($pdo, 1, 11, $ownArchiveId);
portal_report_check('archive detail preserves exact canonical text and hash',
    hash_equals(
        (string)$archive['content_sha256'],
        business_report_content_sha256($archive['metrics'], (string)$archive['text']),
    ));
portal_report_check('portal archive reads do not mutate report evidence',
    hash_equals($beforeRead, portal_report_digest($pdo)));
portal_report_expect('same provider cannot read another customer archive', PortalDataNotFoundException::class,
    fn() => portal_report_archive($pdo, 1, 11, $otherClientArchiveId));
portal_report_expect('different provider cannot read another tenant archive', PortalDataNotFoundException::class,
    fn() => portal_report_archive($pdo, 1, 11, $otherTenantArchiveId));
$pdo->exec("UPDATE business_report_schedule_versions
              SET schedule_timezone='UTC' WHERE id=1011");
portal_report_expect(
    'archive timezone that differs from its immutable schedule fails closed',
    PortalDataConflictException::class,
    fn() => portal_report_archive($pdo, 1, 11, $ownArchiveId),
);
$pdo->exec("UPDATE business_report_schedule_versions
              SET schedule_timezone='America/Los_Angeles' WHERE id=1011");

$context = [
    'identity' => ['display_name' => 'Customer User', 'role' => 'client_viewer'],
    'binding' => ['client_name' => 'Acme <script>alert(1)</script>'],
];
ob_start();
portal_render_reports($context, $archives);
$listHtml = (string)ob_get_clean();
portal_report_check('archive list escapes the customer name and has only sign-out and private chat forms',
    !str_contains($listHtml, '<script>alert(1)</script>')
    && str_contains($listHtml, 'Acme &lt;script&gt;alert(1)&lt;/script&gt;')
    && substr_count($listHtml, '<form')===2
    && str_contains($listHtml, 'action="/portal/logout.php"')
    && str_contains($listHtml, 'id="portal-chat-form"'));
ob_start();
portal_render_report($context, $archive);
$detailHtml = (string)ob_get_clean();
portal_report_check('archive detail turns raw weekly text into readable service facts',
    str_contains($detailHtml, 'Tickets opened')
    && str_contains($detailHtml, 'Tickets resolved')
    && str_contains($detailHtml, 'Avg. first response')
    && str_contains($detailHtml, 'Approved operational time')
    && str_contains($detailHtml, 'Read the exact archived summary'));
portal_report_check('archive detail escapes exact archived text and omits private pipeline facts',
    !str_contains($detailHtml, '<script>alert(1)</script>')
    && str_contains($detailHtml, 'Acme &lt;script&gt;alert(1)&lt;/script&gt;')
    && !str_contains($detailHtml, 'recipient_email')
    && !str_contains($detailHtml, 'delivery_attempt')
    && substr_count($detailHtml, '<form')===2
    && !str_contains($detailHtml, 'href="/portal/new.php"')
    && str_contains($detailHtml, 'id="portal-chat-form"'));

$portalDataSource = file_get_contents(__DIR__ . '/../lib/portal_data.php');
$routeSource = file_get_contents(__DIR__ . '/../public/portal/reports.php');
portal_report_check('portal report query binds exact tenant and customer and never reads delivery or technician tables',
    is_string($portalDataSource)
    && substr_count($portalDataSource, 'a.tenant_id = :tenant_id') >= 2
    && substr_count($portalDataSource, 'a.client_id = :client_id') >= 2
    && !str_contains(portal_report_archive_select_sql(), 'business_report_deliver')
    && !str_contains(portal_report_archive_select_sql(), 'time_entries')
    && !str_contains(portal_report_archive_select_sql(), 'messages'));
portal_report_check('portal report route is dark-first, GET-only, and has no mutation surface',
    is_string($routeSource)
    && strpos($routeSource, 'if (! portal_enabled())') < strpos($routeSource, "REQUEST_METHOD")
    && str_contains($routeSource, "!== 'GET'")
    && !str_contains($routeSource, "=== 'POST'")
    && !str_contains($routeSource, 'csrf')
    && !preg_match('/\b(?:INSERT|UPDATE|DELETE|REPLACE)\b/i', $routeSource));

$pdo->prepare('UPDATE business_report_archives SET report_text = report_text || ? WHERE id = ?')
    ->execute(["\nTAMPERED", $ownArchiveId]);
portal_report_expect('changed archived bytes fail closed before rendering', PortalDataConflictException::class,
    fn() => portal_report_archive($pdo, 1, 11, $ownArchiveId));

echo "Portal report archives: {$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
