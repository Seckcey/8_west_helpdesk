<?php
/** Hermetic first-response service-goal coverage; no server config required. */
declare(strict_types=1);

require_once __DIR__ . '/../lib/service_goals.php';

$failures = 0;
$checks = 0;

function goal_check(string $name, bool $condition): void
{
    global $failures, $checks;
    $checks++;
    if (! $condition) {
        $failures++;
        fwrite(STDERR, "FAIL: {$name}\n");
    }
}

$now = strtotime('2026-08-25 12:00:00 UTC');
$base = [
    'status' => 'open',
    'sla_due_at' => '2026-08-25 15:00:00',
    'first_response_at' => null,
];

goal_check('future response target is healthy', sla_info($base, $now)['state'] === 'healthy');
goal_check('near response target is at risk', sla_info([
    ...$base,
    'sla_due_at' => '2026-08-25 13:30:00',
], $now)['state'] === 'at_risk');
goal_check('elapsed unanswered target is breached', sla_info([
    ...$base,
    'sla_due_at' => '2026-08-25 11:00:00',
], $now) === ['state' => 'breached', 'label' => '1h 00m over']);
goal_check('target boundary is reported without inventing elapsed time', sla_info([
    ...$base,
    'sla_due_at' => '2026-08-25 12:00:00',
], $now) === ['state' => 'breached', 'label' => '0m over']);
goal_check('first response exactly at target is met', sla_info([
    ...$base,
    'first_response_at' => '2026-08-25 15:00:00',
], $now)['state'] === 'met');
goal_check('resolved ticket uses its first response, not resolution time', sla_info([
    ...$base,
    'status' => 'resolved',
    'first_response_at' => '2026-08-25 14:00:00',
    'resolved_at' => '2026-08-27 12:00:00',
], $now) === ['state' => 'met', 'label' => 'Response met']);
goal_check('late first response remains breached after resolution', sla_info([
    ...$base,
    'status' => 'resolved',
    'first_response_at' => '2026-08-25 16:15:00',
], $now) === ['state' => 'breached', 'label' => 'Response 1h 15m late']);
goal_check('resolved ticket without a technician response is not called met', sla_info([
    ...$base,
    'status' => 'resolved',
], $now) === ['state' => 'breached', 'label' => 'No response']);
goal_check('missing target fails visibly', sla_info([
    ...$base,
    'sla_due_at' => '',
], $now) === ['state' => 'breached', 'label' => 'Target missing']);
goal_check('merged source has a neutral unmeasured lamp', sla_info([
    ...$base,
    'merged_into_id' => 9,
], $now) === ['state' => 'excluded', 'label' => 'Merged history']);
goal_check('merge survivor has a neutral unmeasured lamp', sla_info([
    ...$base,
    'has_merged_sources' => 1,
], $now) === ['state' => 'excluded', 'label' => 'Merged history']);

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec('CREATE TABLE tenants (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL
)');
$pdo->exec('CREATE TABLE clients (
    id INTEGER PRIMARY KEY,
    tenant_id INTEGER NOT NULL,
    sla_tier TEXT NOT NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants (id)
)');
$pdo->exec('CREATE TABLE service_goal_policy_versions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    policy_key TEXT NOT NULL,
    version_no INTEGER NOT NULL,
    display_name TEXT NOT NULL,
    effective_from TEXT NOT NULL,
    clock_mode TEXT NOT NULL,
    time_zone TEXT NOT NULL,
    pause_mode TEXT NOT NULL,
    UNIQUE (tenant_id, policy_key, version_no),
    UNIQUE (tenant_id, id),
    FOREIGN KEY (tenant_id) REFERENCES tenants (id)
)');
$pdo->exec('CREATE TABLE service_goal_policy_targets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    policy_version_id INTEGER NOT NULL,
    priority TEXT NOT NULL,
    first_response_minutes INTEGER NOT NULL,
    resolution_minutes INTEGER NULL,
    UNIQUE (tenant_id, policy_version_id, priority),
    UNIQUE (tenant_id, id),
    FOREIGN KEY (tenant_id, policy_version_id)
        REFERENCES service_goal_policy_versions (tenant_id, id)
)');
$pdo->exec('CREATE TABLE tickets (
    id INTEGER PRIMARY KEY,
    tenant_id INTEGER NOT NULL,
    client_id INTEGER NULL,
    status TEXT NOT NULL,
    priority TEXT NOT NULL DEFAULT "normal",
    sla_due_at TEXT NOT NULL,
    service_goal_target_id INTEGER NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NULL,
    merged_into_id INTEGER NULL,
    FOREIGN KEY (tenant_id, service_goal_target_id)
        REFERENCES service_goal_policy_targets (tenant_id, id)
)');
$pdo->exec('CREATE TABLE messages (
    id INTEGER PRIMARY KEY,
    ticket_id INTEGER NOT NULL,
    kind TEXT NOT NULL,
    created_at TEXT NOT NULL
)');

$pdo->exec("INSERT INTO tenants (id, name) VALUES (1, 'Tenant One'), (2, 'Tenant Two')");
$pdo->exec("INSERT INTO clients (id, tenant_id, sla_tier) VALUES
    (100, 1, 'premium'),
    (101, 1, 'standard'),
    (200, 2, 'premium')");

$premiumV1 = service_goal_snapshot_for_new_ticket(
    $pdo,
    1,
    100,
    'urgent',
    '2026-08-25 12:00:00',
);
goal_check('premium v1 captures its exact target',
    $premiumV1['version_no'] === 1
    && $premiumV1['priority'] === 'urgent'
    && $premiumV1['first_response_minutes'] === 120
    && $premiumV1['opened_at'] === '2026-08-25 12:00:00'
    && $premiumV1['due_at'] === '2026-08-25 14:00:00');
goal_check('lazy defaults create two versions per tenant',
    (int) $pdo->query('SELECT COUNT(*) FROM service_goal_policy_versions WHERE tenant_id = 1')->fetchColumn() === 2);
goal_check('lazy defaults create all eight priority targets',
    (int) $pdo->query('SELECT COUNT(*) FROM service_goal_policy_targets WHERE tenant_id = 1')->fetchColumn() === 8);

service_goal_ensure_default_policies($pdo, 1);
goal_check('lazy default creation is idempotent',
    (int) $pdo->query('SELECT COUNT(*) FROM service_goal_policy_targets WHERE tenant_id = 1')->fetchColumn() === 8);

$standardV1 = service_goal_snapshot_for_new_ticket(
    $pdo,
    1,
    101,
    'low',
    '2026-08-25 12:00:00',
);
goal_check('standard v1 preserves the eight-hour window',
    $standardV1['first_response_minutes'] === 480
    && $standardV1['due_at'] === '2026-08-25 20:00:00');

$policyInsert = $pdo->prepare(
    'INSERT INTO service_goal_policy_versions
        (tenant_id, policy_key, version_no, display_name, effective_from, clock_mode, time_zone, pause_mode)
     VALUES (?,?,?,?,?,?,?,?)'
);
$targetInsert = $pdo->prepare(
    'INSERT INTO service_goal_policy_targets
        (tenant_id, policy_version_id, priority, first_response_minutes, resolution_minutes)
     VALUES (?,?,?,?,NULL)'
);
$policyInsert->execute([1, 'premium', 2, 'Premium', '2026-09-01 00:00:00', 'elapsed', 'UTC', 'none']);
$premiumV2Id = (int) $pdo->lastInsertId();
foreach (SERVICE_GOAL_PRIORITIES as $priority) {
    $targetInsert->execute([1, $premiumV2Id, $priority, 90]);
}
$beforeV2 = service_goal_snapshot_for_new_ticket($pdo, 1, 100, 'urgent', '2026-08-31 23:59:59');
$afterV2 = service_goal_snapshot_for_new_ticket($pdo, 1, 100, 'urgent', '2026-09-01 00:00:00');
goal_check('a future version is excluded before its effective boundary', $beforeV2['version_no'] === 1);
goal_check('the newest effective version wins at the boundary',
    $afterV2['version_no'] === 2
    && $afterV2['first_response_minutes'] === 90
    && $afterV2['due_at'] === '2026-09-01 01:30:00');

$policyInsert->execute([1, 'premium', 3, 'Premium', '2026-10-01 00:00:00', 'elapsed', 'UTC', 'none']);
$missingTargetFailed = false;
try {
    service_goal_snapshot_for_new_ticket($pdo, 1, 100, 'urgent', '2026-10-01 00:00:00');
} catch (RuntimeException $error) {
    $missingTargetFailed = str_contains($error->getMessage(), 'no target');
}
goal_check('an incomplete effective version fails instead of falling back', $missingTargetFailed);

$policyInsert->execute([1, 'premium', 4, 'Premium', '2026-11-01 00:00:00', 'business_hours', 'UTC', 'none']);
$unsupportedClockFailed = false;
try {
    service_goal_snapshot_for_new_ticket($pdo, 1, 100, 'urgent', '2026-11-01 00:00:00');
} catch (UnexpectedValueException $error) {
    $unsupportedClockFailed = str_contains($error->getMessage(), 'clock mode');
}
goal_check('unsupported business clocks fail visibly', $unsupportedClockFailed);

$policyInsert->execute([1, 'premium', 5, 'Premium', '2026-12-01 00:00:00', 'elapsed', 'UTC', 'waiting']);
$unsupportedPauseFailed = false;
try {
    service_goal_snapshot_for_new_ticket($pdo, 1, 100, 'urgent', '2026-12-01 00:00:00');
} catch (UnexpectedValueException $error) {
    $unsupportedPauseFailed = str_contains($error->getMessage(), 'pause mode');
}
goal_check('unsupported pause rules fail visibly', $unsupportedPauseFailed);

$pdo->prepare(
    'INSERT INTO tickets
        (id, tenant_id, client_id, status, priority, sla_due_at, service_goal_target_id, created_at)
     VALUES (50,1,100,"open","urgent",?,?,?)'
)->execute([$afterV2['due_at'], $afterV2['target_id'], $afterV2['opened_at']]);
$pdo->exec("UPDATE clients SET sla_tier = 'standard' WHERE id = 100");
$pdo->exec("UPDATE tickets SET priority = 'low', status = 'waiting' WHERE id = 50");
$frozen = $pdo->query('SELECT service_goal_target_id, sla_due_at FROM tickets WHERE id = 50')->fetch();
goal_check('tier, priority, and waiting changes do not rebase a ticket',
    (int) $frozen['service_goal_target_id'] === $afterV2['target_id']
    && $frozen['sla_due_at'] === $afterV2['due_at']);

$tenantTwo = service_goal_snapshot_for_new_ticket($pdo, 2, 200, 'normal', '2026-08-25 12:00:00');
goal_check('a later tenant receives isolated defaults on first use',
    $tenantTwo['tenant_id'] === 2
    && (int) $pdo->query('SELECT COUNT(*) FROM service_goal_policy_versions WHERE tenant_id = 2')->fetchColumn() === 2);
$crossTenantLookupFailed = false;
try {
    service_goal_snapshot_for_new_ticket($pdo, 1, 200, 'normal', '2026-08-25 12:00:00');
} catch (RuntimeException $error) {
    $crossTenantLookupFailed = str_contains($error->getMessage(), 'client');
}
goal_check('a client cannot be resolved through another tenant', $crossTenantLookupFailed);
$crossTenantForeignKeyFailed = false;
try {
    $pdo->prepare(
        'INSERT INTO tickets
            (id, tenant_id, client_id, status, priority, sla_due_at, service_goal_target_id, created_at)
         VALUES (51,1,101,"open","normal",?,?,?)'
    )->execute([$tenantTwo['due_at'], $tenantTwo['target_id'], $tenantTwo['opened_at']]);
} catch (PDOException) {
    $crossTenantForeignKeyFailed = true;
}
goal_check('the database rejects a cross-tenant target snapshot', $crossTenantForeignKeyFailed);
goal_check('captured labels name the immutable version and window', service_goal_ticket_policy_label([
    'service_goal_target_id' => $afterV2['target_id'],
    'service_goal_policy_name' => $afterV2['display_name'],
    'service_goal_version_no' => $afterV2['version_no'],
    'service_goal_clock_mode' => $afterV2['clock_mode'],
    'service_goal_priority' => $afterV2['priority'],
    'service_goal_response_minutes' => $afterV2['first_response_minutes'],
]) === 'Premium v2 · urgent target · 1 hour 30 minutes response · elapsed time');
goal_check('legacy labels do not invent a historical tier or version', service_goal_ticket_policy_label([
    'service_goal_target_id' => null,
    'sla_tier' => 'standard',
]) === 'Legacy response target · policy version unavailable');

$ticket = $pdo->prepare('INSERT INTO tickets (id, tenant_id, status, sla_due_at, created_at, merged_into_id) VALUES (?,?,?,?,?,?)');
$message = $pdo->prepare('INSERT INTO messages (ticket_id, kind, created_at) VALUES (?,?,?)');
$ticket->execute([1, 1, 'in_progress', '2026-08-25 11:00:00', '2026-08-24 10:00:00', null]);
$message->execute([1, 'tech', '2026-08-25 10:30:00']); // met
$ticket->execute([2, 1, 'in_progress', '2026-08-25 10:00:00', '2026-08-24 10:00:00', null]);
$message->execute([2, 'note', '2026-08-25 09:00:00']); // notes are not responses
$message->execute([2, 'tech', '2026-08-25 10:30:00']); // missed
$ticket->execute([3, 1, 'resolved', '2026-08-25 15:00:00', '2026-08-24 10:00:00', null]); // no response: missed
$ticket->execute([4, 1, 'open', '2026-08-25 11:00:00', '2026-08-24 10:00:00', null]); // elapsed: missed
$ticket->execute([5, 1, 'open', '2026-08-25 15:00:00', '2026-08-24 10:00:00', null]); // pending: excluded
$ticket->execute([6, 1, 'resolved', '2026-08-01 11:00:00', '2026-07-20 10:00:00', null]); // outside period
$message->execute([6, 'tech', '2026-08-01 10:00:00']);
$ticket->execute([7, 2, 'resolved', '2026-08-25 11:00:00', '2026-08-24 10:00:00', null]);
$message->execute([7, 'tech', '2026-08-25 10:00:00']); // another tenant
$ticket->execute([8, 1, 'resolved', '2026-08-25 11:00:00', '2026-08-24 10:00:00', 9]); // merged source
$ticket->execute([9, 1, 'resolved', '2026-08-25 11:00:00', '2026-08-24 10:00:00', null]); // merge survivor
$message->execute([9, 'tech', '2026-08-25 10:00:00']); // provenance was destroyed by merge
$ticket->execute([10, 2, 'resolved', '2026-08-25 11:00:00', '2026-08-24 10:00:00', 1]); // corrupt cross-tenant pointer
goal_check('historical tickets remain explicitly unversioned',
    $pdo->query('SELECT service_goal_target_id FROM tickets WHERE id = 1')->fetchColumn() === null);

$attainment = service_goal_response_attainment($pdo, 1, 30, $now);
goal_check('attainment counts only decided in-period tenant outcomes', $attainment['n'] === 4);
goal_check('attainment counts first response met, not resolution time', $attainment['met'] === 1);
goal_check('attainment percentage is derived from decided outcomes', $attainment['pct'] === 25);
goal_check('merged source and survivor stay out of attainment', $attainment['n'] === 4);
goal_check('another tenant cannot suppress an outcome through a merge pointer', $attainment['met'] === 1);

$ticketSource = (string) file_get_contents(__DIR__ . '/../public/ticket.php');
$appJsSource = (string) file_get_contents(__DIR__ . '/../public/assets/js/app.js');
goal_check('ticket detail exposes a response-lamp repaint target', str_contains($ticketSource, 'data-sla'));
goal_check('ticket actions repaint the detail response lamp', str_contains($appJsSource, '$$("[data-sla]", scope)'));
goal_check('ticket detail renders the captured policy label',
    str_contains($ticketSource, 'service_goal_ticket_policy_label'));

$creatorPaths = [
    'manual' => __DIR__ . '/../public/ticket_new.php',
    'email' => __DIR__ . '/../lib/intake.php',
    'alert' => __DIR__ . '/../lib/svc_intake.php',
    'support' => __DIR__ . '/../lib/svc_support.php',
    'westy' => __DIR__ . '/../lib/westy_report.php',
];
foreach ($creatorPaths as $creator => $path) {
    $source = (string) file_get_contents($path);
    goal_check($creator . ' creator resolves exactly one new-ticket snapshot',
        substr_count($source, 'service_goal_snapshot_for_new_ticket(') === 1);
    goal_check($creator . ' creator persists the target version',
        str_contains($source, 'service_goal_target_id'));
    goal_check($creator . ' creator persists the captured open time',
        str_contains($source, 'created_at'));
}
$manualSource = (string) file_get_contents($creatorPaths['manual']);
goal_check('manual initial technician text still counts as first response',
    str_contains($manualSource, '\'tech\', $body')
    && str_contains($manualSource, 'Initial technician response'));
$schemaSource = (string) file_get_contents(__DIR__ . '/../db/schema.sql');
$migrationSource = (string) file_get_contents(__DIR__ . '/../db/migrations/010_service_goal_policies.sql');
$immutableTriggers = [
    'trg_goal_policy_versions_no_update' => ['UPDATE', 'service_goal_policy_versions'],
    'trg_goal_policy_versions_no_delete' => ['DELETE', 'service_goal_policy_versions'],
    'trg_goal_policy_targets_no_update' => ['UPDATE', 'service_goal_policy_targets'],
    'trg_goal_policy_targets_no_delete' => ['DELETE', 'service_goal_policy_targets'],
];
$schemaHasImmutableTriggers = true;
$migrationHasImmutableTriggers = true;
foreach ($immutableTriggers as $trigger => [$event, $table]) {
    $pattern = '/CREATE\s+TRIGGER\s+' . preg_quote($trigger, '/')
        . '\s+BEFORE\s+' . $event . '\s+ON\s+' . preg_quote($table, '/')
        . "\s+FOR\s+EACH\s+ROW\s+SIGNAL\s+SQLSTATE\s+'45000'/is";
    $schemaHasImmutableTriggers = $schemaHasImmutableTriggers
        && preg_match_all($pattern, $schemaSource) === 1;
    $migrationHasImmutableTriggers = $migrationHasImmutableTriggers
        && preg_match_all($pattern, $migrationSource) === 1;
}
goal_check('fresh schema enforces insert-only policy history', $schemaHasImmutableTriggers);
goal_check('migration enforces insert-only policy history', $migrationHasImmutableTriggers);
$schemaPreflight = strpos($schemaSource, 'CREATE TRIGGER trg_goal_policy_privilege_preflight');
$schemaFirstGuardDrop = strpos($schemaSource, 'DROP TRIGGER IF EXISTS trg_goal_policy_versions_no_update');
$migrationPreflight = strpos($migrationSource, 'CREATE TRIGGER trg_goal_policy_privilege_preflight');
$migrationFirstGuardDrop = strpos($migrationSource, 'DROP TRIGGER IF EXISTS trg_goal_policy_versions_no_update');
goal_check('trigger privilege is proven before immutable guards are replaced',
    $schemaPreflight !== false
    && $schemaFirstGuardDrop !== false
    && $schemaPreflight < $schemaFirstGuardDrop
    && $migrationPreflight !== false
    && $migrationFirstGuardDrop !== false
    && $migrationPreflight < $migrationFirstGuardDrop);

fwrite(STDOUT, "service_goals_test: {$checks} checks, {$failures} failures\n");
exit($failures === 0 ? 0 : 1);
