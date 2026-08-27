<?php
/** Hermetic publication, digest, rollback, and client-tier authorization tests. */
declare(strict_types=1);

require_once __DIR__ . '/../lib/service_goal_policy_admin.php';

$checks = 0;
$failures = 0;

function goal_admin_check(string $name, bool $condition): void
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
function goal_admin_throws(string $name, string $expected, callable $operation): void
{
    try {
        $operation();
        goal_admin_check($name, false);
    } catch (Throwable $error) {
        goal_admin_check($name, $error instanceof $expected);
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec('CREATE TABLE tenants (
    id INTEGER PRIMARY KEY, name TEXT NOT NULL, slug TEXT NOT NULL UNIQUE
)');
$pdo->exec('CREATE TABLE users (
    id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, full_name TEXT NOT NULL,
    role TEXT NOT NULL, is_active INTEGER NOT NULL,
    UNIQUE (tenant_id, id), FOREIGN KEY (tenant_id) REFERENCES tenants (id)
)');
$pdo->exec('CREATE TABLE clients (
    id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, sla_tier TEXT NOT NULL,
    UNIQUE (tenant_id, id), FOREIGN KEY (tenant_id) REFERENCES tenants (id)
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
    created_by_user_id INTEGER NULL,
    reason TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (tenant_id, policy_key, version_no),
    UNIQUE (tenant_id, id),
    FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    FOREIGN KEY (tenant_id, created_by_user_id) REFERENCES users (tenant_id, id)
)');
$pdo->exec('CREATE TABLE service_goal_policy_targets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    policy_version_id INTEGER NOT NULL,
    priority TEXT NOT NULL,
    first_response_minutes INTEGER NOT NULL CHECK (first_response_minutes BETWEEN 1 AND 525600),
    resolution_minutes INTEGER NULL CHECK (resolution_minutes IS NULL),
    UNIQUE (tenant_id, policy_version_id, priority),
    UNIQUE (tenant_id, id),
    FOREIGN KEY (tenant_id, policy_version_id)
        REFERENCES service_goal_policy_versions (tenant_id, id)
)');
$pdo->exec('CREATE TABLE tickets (
    id INTEGER PRIMARY KEY,
    tenant_id INTEGER NOT NULL,
    client_id INTEGER NOT NULL,
    priority TEXT NOT NULL,
    status TEXT NOT NULL,
    sla_due_at TEXT NOT NULL,
    service_goal_target_id INTEGER NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (tenant_id, service_goal_target_id)
        REFERENCES service_goal_policy_targets (tenant_id, id)
)');

$pdo->exec("INSERT INTO tenants (id,name,slug) VALUES
    (1,'Tenant One','one'),(2,'Tenant Two','two')");
$pdo->exec("INSERT INTO users (id,tenant_id,full_name,role,is_active) VALUES
    (101,1,'Owner One','owner',1),
    (102,1,'Admin One','admin',1),
    (103,1,'Tech One','tech',1),
    (104,1,'Inactive Admin','admin',0),
    (201,2,'Owner Two','owner',1)");
$pdo->exec("INSERT INTO clients (id,tenant_id,sla_tier) VALUES
    (11,1,'premium'),(22,2,'premium')");

service_goal_ensure_default_policies($pdo, 1);
$baseline = service_goal_policy_inspect($pdo, 'one', 'premium');
goal_admin_check('inspect shows the exact lazy v1 baseline without writing',
    $baseline['latest_version'] === 1
    && count($baseline['versions']) === 1
    && count($baseline['versions'][0]['targets']) === 4
    && $baseline['versions'][0]['created_by_user_id'] === null
    && $baseline['versions'][0]['reason'] === null);

$targets = ['low' => 180, 'normal' => 120, 'high' => 60, 'urgent' => 30];
$beforePlanVersions = (int) $pdo->query(
    "SELECT COUNT(*) FROM service_goal_policy_versions WHERE tenant_id=1 AND policy_key='premium'",
)->fetchColumn();
$plan = service_goal_policy_plan(
    $pdo, 'one', 'premium', 1, '2099-01-01T00:00:00Z',
    $targets, 101, 'Reviewed premium response targets',
);
goal_admin_check('plan is canonical and performs no write',
    strlen($plan['plan_sha256']) === 64
    && hash('sha256', $plan['canonical_json']) === $plan['plan_sha256']
    && (int) $pdo->query(
        "SELECT COUNT(*) FROM service_goal_policy_versions WHERE tenant_id=1 AND policy_key='premium'",
    )->fetchColumn() === $beforePlanVersions);
$repeatPlan = service_goal_policy_plan(
    $pdo, 'one', 'premium', 1, '2099-01-01T00:00:00Z',
    $targets, 101, 'Reviewed premium response targets',
);
goal_admin_check('the same exact facts produce the same digest',
    hash_equals($plan['plan_sha256'], $repeatPlan['plan_sha256'])
    && $plan['canonical_json'] === $repeatPlan['canonical_json']);

goal_admin_throws('backdating is refused before publication', ServiceGoalPolicyValidationException::class,
    fn() => service_goal_policy_plan(
        $pdo, 'one', 'premium', 1, '2000-01-01T00:00:00Z',
        $targets, 101, 'Backdated',
    ));
goal_admin_throws('noncanonical UTC input is refused', ServiceGoalPolicyValidationException::class,
    fn() => service_goal_policy_plan(
        $pdo, 'one', 'premium', 1, '2099-01-01 00:00:00',
        $targets, 101, 'Bad timestamp',
    ));
goal_admin_throws('a missing priority is refused', ServiceGoalPolicyValidationException::class,
    fn() => service_goal_policy_plan(
        $pdo, 'one', 'premium', 1, '2099-01-01T00:00:00Z',
        array_slice($targets, 0, 3, true), 101, 'Incomplete',
    ));
goal_admin_throws('a zero target is refused', ServiceGoalPolicyValidationException::class,
    fn() => service_goal_policy_plan(
        $pdo, 'one', 'premium', 1, '2099-01-01T00:00:00Z',
        [...$targets, 'urgent' => 0], 101, 'Zero',
    ));
goal_admin_check('the one-year operational target ceiling is accepted exactly',
    service_goal_policy_targets([
        'low' => SERVICE_GOAL_POLICY_MAX_RESPONSE_MINUTES,
        'normal' => 1,
        'high' => 1,
        'urgent' => 1,
    ])['low'] === SERVICE_GOAL_POLICY_MAX_RESPONSE_MINUTES);
goal_admin_throws('one minute above the operational ceiling is refused', ServiceGoalPolicyValidationException::class,
    fn() => service_goal_policy_targets([
        'low' => SERVICE_GOAL_POLICY_MAX_RESPONSE_MINUTES + 1,
        'normal' => 1,
        'high' => 1,
        'urgent' => 1,
    ]));
goal_admin_throws('a technician cannot plan publication', ServiceGoalPolicyGateException::class,
    fn() => service_goal_policy_plan(
        $pdo, 'one', 'premium', 1, '2099-01-01T00:00:00Z',
        $targets, 103, 'Technician attempt',
    ));
goal_admin_throws('an inactive admin cannot plan publication', ServiceGoalPolicyGateException::class,
    fn() => service_goal_policy_plan(
        $pdo, 'one', 'premium', 1, '2099-01-01T00:00:00Z',
        $targets, 104, 'Inactive attempt',
    ));
goal_admin_throws('a cross-tenant owner cannot plan publication', ServiceGoalPolicyGateException::class,
    fn() => service_goal_policy_plan(
        $pdo, 'one', 'premium', 1, '2099-01-01T00:00:00Z',
        $targets, 201, 'Cross tenant attempt',
    ));
goal_admin_throws('a changed plan digest rolls the transaction back', ServiceGoalPolicyConflictException::class,
    fn() => service_goal_policy_publish(
        $pdo, 'one', 'premium', 1, '2099-01-01T00:00:00Z',
        $targets, 101, 'Reviewed premium response targets', str_repeat('0', 64),
    ));
goal_admin_check('digest mismatch leaves no partial version or targets',
    (int) $pdo->query(
        "SELECT COUNT(*) FROM service_goal_policy_versions WHERE tenant_id=1 AND policy_key='premium'",
    )->fetchColumn() === 1
    && (int) $pdo->query('SELECT COUNT(*) FROM service_goal_policy_targets WHERE tenant_id=1')->fetchColumn() === 8);

$published = service_goal_policy_publish(
    $pdo, 'one', 'premium', 1, '2099-01-01T00:00:00Z',
    $targets, 101, 'Reviewed premium response targets', $plan['plan_sha256'],
);
goal_admin_check('owner publishes one attributed version with exact four targets',
    $published['action'] === 'published'
    && (int) $published['policy']['version_no'] === 2
    && (int) $published['policy']['created_by_user_id'] === 101
    && $published['policy']['reason'] === 'Reviewed premium response targets'
    && count($published['policy']['targets']) === 4);
goal_admin_throws('the reviewed plan becomes stale after one winner', ServiceGoalPolicyConflictException::class,
    fn() => service_goal_policy_publish(
        $pdo, 'one', 'premium', 1, '2099-01-01T00:00:00Z',
        $targets, 101, 'Reviewed premium response targets', $plan['plan_sha256'],
    ));

$beforeBoundary = service_goal_snapshot_for_new_ticket($pdo, 1, 11, 'urgent', '2098-12-31 23:59:59');
$atBoundary = service_goal_snapshot_for_new_ticket($pdo, 1, 11, 'urgent', '2099-01-01 00:00:00');
goal_admin_check('new tickets switch versions only at the future boundary',
    (int) $beforeBoundary['version_no'] === 1
    && (int) $atBoundary['version_no'] === 2
    && (int) $atBoundary['first_response_minutes'] === 30);
$pdo->prepare(
    'INSERT INTO tickets
        (id,tenant_id,client_id,priority,status,sla_due_at,service_goal_target_id,created_at)
     VALUES (1,1,11,\'urgent\',\'open\',?,?,?)',
)->execute([$atBoundary['due_at'], $atBoundary['target_id'], $atBoundary['opened_at']]);
$pdo->exec("UPDATE clients SET sla_tier='standard' WHERE id=11");
$pdo->exec("UPDATE tickets SET priority='low',status='waiting' WHERE id=1");
$frozen = $pdo->query('SELECT service_goal_target_id,sla_due_at FROM tickets WHERE id=1')->fetch();
goal_admin_check('tier and ticket edits never rebase the captured target',
    (int) $frozen['service_goal_target_id'] === (int) $atBoundary['target_id']
    && $frozen['sla_due_at'] === $atBoundary['due_at']);

$v3Plan = service_goal_policy_plan(
    $pdo, 'one', 'premium', 2, '2100-01-01T00:00:00Z',
    $targets, 102, 'Rollback probe',
);
$pdo->exec("CREATE TRIGGER fail_urgent_target BEFORE INSERT ON service_goal_policy_targets
    WHEN NEW.priority='urgent' BEGIN SELECT RAISE(ABORT,'forced target failure'); END");
goal_admin_throws('a target failure aborts the whole publication transaction', PDOException::class,
    fn() => service_goal_policy_publish(
        $pdo, 'one', 'premium', 2, '2100-01-01T00:00:00Z',
        $targets, 102, 'Rollback probe', $v3Plan['plan_sha256'],
    ));
$pdo->exec('DROP TRIGGER fail_urgent_target');
goal_admin_check('failed publication leaves neither version nor partial targets',
    (int) $pdo->query(
        "SELECT COUNT(*) FROM service_goal_policy_versions WHERE tenant_id=1 AND policy_key='premium'",
    )->fetchColumn() === 2
    && (int) $pdo->query(
        'SELECT COUNT(*) FROM service_goal_policy_targets WHERE policy_version_id NOT IN (SELECT id FROM service_goal_policy_versions)',
    )->fetchColumn() === 0);

$ceilingTargets = array_fill_keys(
    SERVICE_GOAL_PRIORITIES,
    SERVICE_GOAL_POLICY_MAX_RESPONSE_MINUTES,
);
$ceilingPlan = service_goal_policy_plan(
    $pdo, 'one', 'standard', 1, '2099-06-01T00:00:00Z',
    $ceilingTargets, 102, 'Response ceiling due-date probe',
);
service_goal_policy_publish(
    $pdo, 'one', 'standard', 1, '2099-06-01T00:00:00Z',
    $ceilingTargets, 102, 'Response ceiling due-date probe', $ceilingPlan['plan_sha256'],
);
$ceilingSnapshot = service_goal_snapshot_for_new_ticket(
    $pdo,
    1,
    11,
    'urgent',
    '2099-06-01 00:00:00',
);
goal_admin_check('the exact response ceiling produces a valid bounded ticket due date',
    $ceilingSnapshot['first_response_minutes'] === SERVICE_GOAL_POLICY_MAX_RESPONSE_MINUTES
    && $ceilingSnapshot['due_at'] === '2100-06-01 00:00:00');

$owner = ['role' => 'owner', 'is_active' => 1];
$admin = ['role' => 'admin', 'is_active' => 1];
$tech = ['role' => 'tech', 'is_active' => 1];
goal_admin_check('owners and admins may choose a supported client tier',
    service_goal_policy_client_tier($owner, 'premium') === 'premium'
    && service_goal_policy_client_tier($admin, 'standard', 'premium') === 'standard');
goal_admin_check('a technician cannot select premium for a new client',
    service_goal_policy_client_tier($tech, 'premium') === 'standard');
goal_admin_check('a technician cannot change an existing client tier',
    service_goal_policy_client_tier($tech, 'standard', 'premium') === 'premium');
goal_admin_check('an inactive admin cannot change a client tier',
    service_goal_policy_client_tier(['role' => 'admin', 'is_active' => 0], 'premium') === 'standard');

$newClientSource = (string) file_get_contents(__DIR__ . '/../public/client_new.php');
$editClientSource = (string) file_get_contents(__DIR__ . '/../public/client_edit.php');
$cliSource = (string) file_get_contents(__DIR__ . '/../db/manage_service_goals.php');
goal_admin_check('client pages retain login and CSRF while using the tier boundary',
    str_contains($newClientSource, 'require_login()')
    && str_contains($newClientSource, 'csrf_check()')
    && str_contains($newClientSource, 'service_goal_policy_client_tier(')
    && str_contains($editClientSource, 'require_login()')
    && str_contains($editClientSource, 'csrf_check()')
    && str_contains($editClientSource, 'service_goal_policy_client_tier('));
goal_admin_check('technician client edits omit the protected tier column entirely',
    str_contains(
        $editClientSource,
        'UPDATE clients SET name = ?, domain = ?, health = ?, notes = ?',
    )
    && substr_count(
        $editClientSource,
        'UPDATE clients SET name = ?, domain = ?, sla_tier = ?, health = ?, notes = ?',
    ) === 1);
goal_admin_check('publication locks the exact actor during its transaction recheck',
    str_contains($editClientSource, '$canManageServiceTier')
    && str_contains(
        (string) file_get_contents(__DIR__ . '/../lib/service_goal_policy_admin.php'),
        'service_goal_policy_actor($pdo, $tenant[\'id\'], $actorUserId, $forUpdate)',
    ));
goal_admin_check('the operator CLI is explicit and never uses session tenant fallback',
    str_contains($cliSource, "['inspect', 'plan', 'publish']")
    && str_contains($cliSource, 'tenant-slug')
    && str_contains($cliSource, 'plan-sha256')
    && ! str_contains($cliSource, 'tenant_id()'));

fwrite(STDOUT, "service_goal_policy_admin_test: {$checks} checks, {$failures} failures\n");
exit($failures === 0 ? 0 : 1);
