<?php
/** Focused coverage for the one bounded `--scheduled` lifecycle mode (A82) and its wrapper child. */
declare(strict_types=1);

require_once __DIR__ . '/../lib/managed_customer_lifecycle.php';

$schedulerChecks = 0;
$schedulerFailures = 0;

function scheduler_check(bool $condition, string $message): void
{
    global $schedulerChecks, $schedulerFailures;
    $schedulerChecks++;
    if (!$condition) {
        $schedulerFailures++;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

function scheduler_uuid(int $n): string
{
    return sprintf('a1000000-3333-4333-8333-%012d', $n);
}

/**
 * Only the tables the scheduled claim reads. Each binding is (id, tenant, explicit-or-not by config,
 * admitted marker, status, inactive history, contained, restored).
 */
function scheduler_sqlite(array $bindings, bool $singleton = true, bool $identity034 = true): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    foreach ([
        'CREATE TABLE tenants (id INTEGER PRIMARY KEY, name TEXT NOT NULL, slug TEXT NOT NULL UNIQUE)',
        'CREATE TABLE suite_customer_sync_bindings (
            id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, customer_id TEXT NOT NULL UNIQUE,
            client_id INTEGER NOT NULL, source_version INTEGER NOT NULL, status TEXT NOT NULL,
            last_event_id TEXT NOT NULL, last_request_sha256 TEXT NOT NULL, last_admission TEXT NULL)',
        'CREATE TABLE suite_customer_sync_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, binding_id INTEGER NOT NULL,
            source_version INTEGER NOT NULL, status TEXT NOT NULL, admission TEXT NULL)',
        'CREATE TABLE managed_customer_lifecycle_receipts (
            id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id TEXT NOT NULL,
            source_version INTEGER NOT NULL, action TEXT NOT NULL)',
        'CREATE TABLE managed_customer_lifecycle_restore_receipts (
            id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id TEXT NOT NULL, source_version INTEGER NOT NULL'
            . ($identity034 ? ', id_proof_scope TEXT NULL)' : ')'),
        'CREATE TABLE managed_customer_lifecycle_progress (
            id INTEGER PRIMARY KEY CHECK (id = 1), last_binding_id INTEGER NOT NULL DEFAULT 0,
            updated_at TEXT NOT NULL)',
        "INSERT INTO tenants VALUES (1,'8 West IT','8west'),(2,'Provider One','provider-one')",
    ] as $sql) {
        $pdo->exec($sql);
    }
    if ($singleton) {
        $pdo->exec("INSERT INTO managed_customer_lifecycle_progress VALUES (1,0,'2026-10-23 00:00:00')");
    }
    $binding = $pdo->prepare('INSERT INTO suite_customer_sync_bindings VALUES (?,?,?,?,?,?,?,?,NULL)');
    $event = $pdo->prepare('INSERT INTO suite_customer_sync_events (tenant_id,binding_id,source_version,status,admission) VALUES (?,?,?,?,?)');
    foreach ($bindings as $row) {
        $row += ['tenant' => 1, 'admitted' => false, 'status' => 'inactive', 'history' => false,
            'contained' => false, 'restored' => false];
        $version = $row['status'] === 'active' && $row['history'] ? 3 : 2;
        $binding->execute([$row['id'], $row['tenant'], scheduler_uuid($row['id']), 100 + $row['id'], $version,
            $row['status'], sprintf('b1000000-3333-4333-8333-%012d', $row['id']), str_repeat('a', 64)]);
        $event->execute([$row['tenant'], $row['id'], 1, 'active', $row['admitted'] ? 'created' : null]);
        $event->execute([$row['tenant'], $row['id'], 2, $row['status'] === 'active' && $row['history'] ? 'inactive' : $row['status'], null]);
        if ($version === 3) $event->execute([$row['tenant'], $row['id'], 3, 'active', null]);
        if ($row['contained']) {
            $pdo->prepare("INSERT INTO managed_customer_lifecycle_receipts (customer_id,source_version,action) VALUES (?,?,'contained')")
                ->execute([scheduler_uuid($row['id']), $version]);
        }
        if ($row['restored']) {
            $pdo->prepare('INSERT INTO managed_customer_lifecycle_restore_receipts (customer_id,source_version) VALUES (?,?)')
                ->execute([scheduler_uuid($row['id']), $version]);
        }
    }
    return $pdo;
}

function scheduler_cursor(PDO $pdo): int
{
    return (int)$pdo->query('SELECT last_binding_id FROM managed_customer_lifecycle_progress WHERE id = 1')->fetchColumn();
}

// Union and dedup: the explicit scope and admitted lineages, only where containment or restoration is
// still needed; an unmarked customer outside the explicit scope is never claimed.
$config = ['customer_ids' => [scheduler_uuid(1), scheduler_uuid(2)]];
$pdo = scheduler_sqlite([
    ['id' => 1],                                         // explicit, needs containment
    ['id' => 2, 'admitted' => true],                     // explicit and admitted: one claim
    ['id' => 3],                                         // unmarked, not explicit: never
    ['id' => 4, 'admitted' => true, 'status' => 'active'],             // admitted, nothing to do
    ['id' => 5, 'admitted' => true, 'status' => 'active', 'history' => true], // needs restoration
    ['id' => 6, 'admitted' => true, 'contained' => true],              // already contained
    ['id' => 7, 'admitted' => true, 'status' => 'active', 'history' => true, 'restored' => true],
    ['id' => 8, 'admitted' => true, 'tenant' => 2],                    // other provider
]);
$claimed = [];
while (($candidate = managed_customer_lifecycle_scheduled_claim($pdo, $config, $claimed)) !== null) {
    $claimed[] = (int)$candidate['binding_id'];
}
scheduler_check($claimed === [1, 2, 5, 8] && scheduler_cursor($pdo) === 8,
    'the scheduled scope is not exactly the deduplicated explicit plus admitted work in binding order');
scheduler_check(managed_customer_lifecycle_scheduled_claim($pdo, $config)['binding_id'] === 1 && scheduler_cursor($pdo) === 1,
    'the next run did not wrap after the last committed claim');
scheduler_check(managed_customer_lifecycle_scheduled_claim($pdo, $config)['binding_id'] === 2,
    'the cursor did not resume after the committed claim');
$missing = scheduler_sqlite([['id' => 1]], false);
try {
    managed_customer_lifecycle_scheduled_claim($missing, $config);
    scheduler_check(false, 'a missing cursor singleton was accepted');
} catch (ManagedCustomerLifecycleGateException) {
    scheduler_check(true, 'a missing cursor singleton was accepted');
}

// The bounded loop with fakes: claim cap, transition cap, first claim, deadline, refusal, cursor failure.
$times = [0.0];
$clock = static function () use (&$times): float {
    return count($times) > 1 ? (float)array_shift($times) : (float)$times[0];
};
$listClaim = static function (array $ids, ?int $failAt = null): callable {
    $calls = 0;
    return static function (array $claimed) use ($ids, $failAt, &$calls): ?array {
        if (++$calls === $failAt) throw new RuntimeException('cursor commit unknown');
        foreach ($ids as $id) {
            if (!in_array($id, $claimed, true)) {
                return ['binding_id' => $id, 'customer_id' => scheduler_uuid($id), 'source_version' => 2, 'status' => 'inactive'];
            }
        }
        return null;
    };
};
$applied = static fn(array $candidate, callable $mayTransition, callable $remaining): array =>
    $mayTransition() ? ['action' => 'contained'] : ['action' => 'deferred'];
$run = managed_customer_lifecycle_scheduled_loop($listClaim(range(1, 30)), static fn(array $c, callable $m, callable $r): array => ['action' => 'skipped'], $clock);
scheduler_check($run['claims'] === 25 && $run['reserved'] === 0 && !array_key_exists('transitions', $run),
    'the 25-claim cap changed or the run still reports applied transitions');
$run = managed_customer_lifecycle_scheduled_loop($listClaim(range(1, 30)), $applied, $clock);
scheduler_check($run['claims'] === 10 && $run['reserved'] === 10
    && array_count_values(array_column($run['results'], 'action')) === ['contained' => 10],
    'the 10-reservation total cap changed');
// A141 counter truth: a reservation is an attempt. One reserved then refused still uses the budget.
$reserveThenRefuse = static function (array $candidate, callable $mayTransition, callable $remaining): array {
    $mayTransition();
    throw new ManagedCustomerLifecycleGateException('refused after its reservation');
};
$run = managed_customer_lifecycle_scheduled_loop($listClaim(range(1, 30)), $reserveThenRefuse, $clock);
scheduler_check($run['claims'] === 10 && $run['reserved'] === 10
    && array_count_values(array_column($run['results'], 'action')) === ['refused' => 10],
    'refused reserved attempts were not counted against the 10-attempt budget');
$times = [0.0, 31.0];
$run = managed_customer_lifecycle_scheduled_loop($listClaim(range(1, 30)), $applied, $clock);
scheduler_check($run['claims'] === 1 && $run['reserved'] === 0 && $run['results'][0]['action'] === 'deferred',
    'the first claim was skipped or a transition ran past the deadline');
$times = [0.0];
$refuseFirst = static function (array $candidate, callable $mayTransition, callable $remaining): array {
    if ($candidate['binding_id'] === 1) throw new ManagedCustomerLifecycleGateException('human hold');
    return ['action' => 'contained'];
};
$run = managed_customer_lifecycle_scheduled_loop($listClaim([1, 2]), $refuseFirst, $clock);
scheduler_check(array_column($run['results'], 'action') === ['refused', 'contained'] && !isset($run['stopped'])
    && $run['results'][0]['customer_id_sha256'] === hash('sha256', scheduler_uuid(1))
    && !str_contains(json_encode($run['results'], JSON_THROW_ON_ERROR), 'human hold'),
    'a refused prefix stopped the run or leaked its error text');
$acted = [];
$record = static function (array $candidate, callable $mayTransition, callable $remaining) use (&$acted): array {
    $acted[] = $candidate['binding_id'];
    return ['action' => 'contained'];
};
$run = managed_customer_lifecycle_scheduled_loop($listClaim([1, 2, 3], 2), $record, $clock);
scheduler_check(($run['stopped'] ?? null) === 'cursor_unavailable' && $acted === [1] && $run['claims'] === 1,
    'an unknown cursor commit led to an action');
$times = [0.0, 12.5];
$seen = null;
managed_customer_lifecycle_scheduled_loop($listClaim([1]), static function (array $c, callable $m, callable $remaining) use (&$seen): array {
    $seen = $remaining();
    return ['action' => 'skipped'];
}, $clock);
scheduler_check($seen === 17.5, 'actions do not receive the remaining run time');

// Fairness over a fixed N=100 with a refused prefix: every binding is claimed within ceil(100/25)
// healthy runs, and within N runs when each run is cut to one claim.
$hundred = scheduler_sqlite(array_map(static fn(int $id): array => ['id' => $id, 'admitted' => true], range(1, 100)));
$covered = [];
$refusePrefix = static function (array $candidate, callable $mayTransition, callable $remaining) use (&$covered): array {
    $covered[(int)$candidate['binding_id']] = true;
    if ($candidate['binding_id'] <= 30) throw new ManagedCustomerLifecycleGateException('refused prefix');
    return ['action' => 'skipped'];
};
for ($runIndex = 0; $runIndex < 4; $runIndex++) {
    $times = [0.0];
    managed_customer_lifecycle_scheduled_loop(
        static fn(array $claimed): ?array => managed_customer_lifecycle_scheduled_claim($hundred, ['customer_ids' => []], $claimed),
        $refusePrefix, $clock);
}
scheduler_check(count($covered) === 100, 'a refused prefix starved later bindings within four healthy runs');
$hundred->exec('UPDATE managed_customer_lifecycle_progress SET last_binding_id = 0');
$covered = [];
for ($runIndex = 0; $runIndex < 100; $runIndex++) {
    $times = [0.0, 31.0];
    managed_customer_lifecycle_scheduled_loop(
        static fn(array $claimed): ?array => managed_customer_lifecycle_scheduled_claim($hundred, ['customer_ids' => []], $claimed),
        $refusePrefix, $clock);
}
scheduler_check(count($covered) === 100, 'one claim per run did not cover every binding within N runs');

// The action: the actual actor, a scoped configuration, deferral before any call, and the
// remaining time bounding the ID request.
$actPdo = scheduler_sqlite([['id' => 1, 'admitted' => true, 'tenant' => 2]]);
$actConfig = managed_customer_lifecycle_config([
    'enabled' => true, 'restoration_enabled' => true, 'customer_ids' => [scheduler_uuid(9)],
    'tenant_actors' => ['8west' => 101], 'batch_size' => 1,
]);
$candidate = ['binding_id' => 1, 'tenant_id' => 2, 'client_id' => 101, 'customer_id' => scheduler_uuid(1),
    'source_version' => 3, 'status' => 'active', 'provider_tenant_slug' => 'provider-one',
    'last_event_id' => sprintf('b1000000-3333-4333-8333-%012d', 1), 'last_request_sha256' => str_repeat('a', 64)];
try {
    managed_customer_lifecycle_scheduled_act($actPdo, $actConfig, $candidate, static fn(): bool => true, static fn(): float => 20.0);
    scheduler_check(false, 'an admitted customer of a provider that is not admitted now was acted on');
} catch (ManagedCustomerLifecycleGateException) {
    scheduler_check(true, 'an admitted customer of a provider that is not admitted now was acted on');
}
$house = array_replace($candidate, ['tenant_id' => 1, 'provider_tenant_slug' => '8west']);
$fetched = [];
$fetch = static function (string $customerId, array $idConfig) use (&$fetched): array {
    $fetched[] = [$customerId, $idConfig['timeout_seconds']];
    throw new ManagedCustomerActivationGateException('fixture transport stops here');
};
// A88 routing: an admitted lineage outside the explicit scope uses only the identity-only proof.
$legacyFetched = [];
$legacyFetch = static function (string $customerId, array $idConfig) use (&$legacyFetched): array {
    $legacyFetched[] = [$customerId, $idConfig['timeout_seconds']];
    throw new ManagedCustomerActivationGateException('legacy fixture transport stops here');
};
$deferred = managed_customer_lifecycle_scheduled_act($actPdo, $actConfig, $house, static fn(): bool => true,
    static fn(): float => 0.4, [], ['timeout_seconds' => 10], $legacyFetch, $fetch);
scheduler_check($deferred['action'] === 'deferred' && $deferred['provenance'] === 'admitted' && $fetched === [],
    'a restoration with under one second left was attempted');
try {
    managed_customer_lifecycle_scheduled_act($actPdo, $actConfig, $house, static fn(): bool => true,
        static fn(): float => 6.7, [], ['timeout_seconds' => 10], $legacyFetch, $fetch);
} catch (ManagedCustomerActivationGateException) {
}
scheduler_check($fetched === [[scheduler_uuid(1), 6]], 'the ID request was not bounded by the remaining run time');
$capped = managed_customer_lifecycle_scheduled_act($actPdo, $actConfig, $house, static fn(): bool => false,
    static fn(): float => 20.0, [], ['timeout_seconds' => 10], $legacyFetch, $fetch);
scheduler_check($capped['action'] === 'deferred' && count($fetched) === 1, 'a capped run still made an ID request');
scheduler_check($legacyFetched === [], 'an admitted lineage requested the schema-2 contact proof');
$explicitHouse = array_replace($house, ['customer_id' => scheduler_uuid(9)]);
try {
    managed_customer_lifecycle_scheduled_act($actPdo, $actConfig, $explicitHouse, static fn(): bool => true,
        static fn(): float => 20.0, [], ['timeout_seconds' => 10], $legacyFetch, $fetch);
} catch (ManagedCustomerActivationGateException) {
}
scheduler_check($legacyFetched === [[scheduler_uuid(9), 10]] && count($fetched) === 1,
    'the explicit approved scope did not keep the full schema-2 restore or used the identity-only proof');
// A143: the scheduling deadline is rechecked after the proof fetch, on both routes, before any action.
foreach (['admitted' => $house, 'explicit' => $explicitHouse] as $route => $routeCandidate) {
    $late = 20.0;
    $lateFetch = static function (string $customerId, array $idConfig) use (&$late): array {
        $late = -0.5;
        return ['schema_version' => 3];
    };
    try {
        $lateResult = managed_customer_lifecycle_scheduled_act($actPdo, $actConfig, $routeCandidate, static fn(): bool => true,
            static function () use (&$late): float { return $late; }, [], ['timeout_seconds' => 10], $lateFetch, $lateFetch);
        scheduler_check($lateResult['action'] === 'deferred' && $lateResult['provenance'] === $route,
            "the {$route} route did not defer when the deadline passed during the proof fetch");
    } catch (Throwable $error) {
        scheduler_check(false, "the {$route} route acted after the deadline passed during the proof fetch ({$error->getMessage()})");
    }
}
$no034 = scheduler_sqlite([['id' => 1, 'admitted' => true]], true, false);
$reserved = 0;
try {
    managed_customer_lifecycle_scheduled_act($no034, $actConfig, $house, static function () use (&$reserved): bool {
        $reserved++;
        return true;
    }, static fn(): float => 20.0, [], ['timeout_seconds' => 10], $legacyFetch, $fetch);
    scheduler_check(false, 'an admitted restoration ran without migration 034');
} catch (ManagedCustomerLifecycleGateException) {
    scheduler_check($reserved === 0 && count($fetched) === 1 && $legacyFetched === [[scheduler_uuid(9), 10]],
        'a missing migration 034 reserved budget or made an ID request before refusing');
}

// Wrapper and CLI wiring: one --scheduled child after the reports, RC taken before logging, bounded
// TERM/KILL, report status still controlling; the no-argument explicit mode is unchanged.
$wrapper = (string)file_get_contents(__DIR__ . '/../cron/run_business_reports.sh');
$childAt = strpos($wrapper, '"$PHP_BIN" "$LIFECYCLE_RUNNER" --scheduled >"$lifecycle_output" 2>&1');
$statusAt = strpos($wrapper, 'lifecycle_status=$?');
$reportsAt = strpos($wrapper, '"$PHP_BIN" "$REPORT_RUNNER" >"$report_output" 2>&1');
$stageAt = strrpos($wrapper, "run_lifecycle_stage\nset -e");
scheduler_check($childAt !== false && $statusAt !== false && $reportsAt !== false && $stageAt !== false
    && $statusAt > $childAt && $statusAt - $childAt < 120 && $stageAt > $reportsAt
    && str_contains($wrapper, '"$TIMEOUT_BIN" --signal=TERM --kill-after=5 "$LIFECYCLE_SECONDS"')
    && str_contains($wrapper, 'readonly LIFECYCLE_SECONDS=45')
    && substr_count($wrapper, '--scheduled') === 1
    && str_ends_with(rtrim($wrapper), 'exit "$php_status"')
    && !str_contains($wrapper, '| "$LOGGER_BIN"'),
    'the wrapper lifecycle child is not one bounded, after-report, status-preserving --scheduled stage');
$cron = (string)file_get_contents(__DIR__ . '/../cron/managed_customer_lifecycle.php');
scheduler_check(str_contains($cron, "(\$argv[1] ?? null) === '--scheduled'")
    && str_contains($cron, 'managed_customer_lifecycle_scheduled_run(')
    && str_contains($cron, 'managed_customer_lifecycle_run('),
    'the lifecycle CLI lost its explicit mode or its one scheduled mode');

if ($schedulerFailures > 0) {
    fwrite(STDERR, "managed_customer_lifecycle_scheduler_test: {$schedulerFailures} failure(s) / {$schedulerChecks} checks\n");
    exit(1);
}
echo "managed_customer_lifecycle_scheduler_test: {$schedulerChecks} checks\n";
