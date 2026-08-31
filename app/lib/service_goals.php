<?php
/**
 * Service-goal calculations that do not depend on the web request.
 *
 * Safeharbor's existing `sla_due_at` column is a first-response deadline. It
 * is not a resolution deadline and it does not yet apply business calendars.
 * Keeping that meaning explicit here prevents queue lamps and reports from
 * silently measuring two different things while versioned policies are built.
 */
declare(strict_types=1);

const SERVICE_GOAL_PRIORITIES = ['low', 'normal', 'high', 'urgent'];
const SERVICE_GOAL_DEFAULT_POLICIES = [
    'standard' => ['display_name' => 'Standard', 'first_response_minutes' => 480],
    'premium' => ['display_name' => 'Premium', 'first_response_minutes' => 120],
];

/** Parse a database UTC datetime without inheriting the host timezone. */
function service_goal_timestamp(mixed $value): ?int
{
    if (! is_string($value) || trim($value) === '') {
        return null;
    }

    $timestamp = strtotime(trim($value) . ' UTC');

    return $timestamp === false ? null : $timestamp;
}

/** Human-sized elapsed time for the compact response-target lamp. */
function service_goal_duration_label(int $seconds): string
{
    $minutes = (int) ceil(abs($seconds) / 60);
    if ($minutes < 60) {
        return $minutes . 'm';
    }

    return intdiv($minutes, 60) . 'h '
        . str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT) . 'm';
}

/** Customer-facing wording for a configured response window. */
function service_goal_window_label(int $minutes): string
{
    $minutes = max(1, $minutes);
    $hours = intdiv($minutes, 60);
    $remainder = $minutes % 60;
    $parts = [];
    if ($hours > 0) {
        $parts[] = $hours . ' ' . ($hours === 1 ? 'hour' : 'hours');
    }
    if ($remainder > 0) {
        $parts[] = $remainder . ' ' . ($remainder === 1 ? 'minute' : 'minutes');
    }

    return implode(' ', $parts);
}

/**
 * Lazily provision the two baseline v1 policies for a tenant.
 *
 * Migration 010 seeds tenants that exist at migration time. Suite SSO can
 * provision tenants later, so ticket creation repeats these inserts
 * idempotently instead of coupling policy ownership to the identity module.
 */
function service_goal_ensure_default_policies(PDO $pdo, int $tenantId): void
{
    if ($tenantId <= 0) {
        throw new InvalidArgumentException('A valid tenant is required for service goals.');
    }

    $policyFind = $pdo->prepare(
        'SELECT id
           FROM service_goal_policy_versions
          WHERE tenant_id = ? AND policy_key = ? AND version_no = 1'
    );
    $targetFind = $pdo->prepare(
        'SELECT id
           FROM service_goal_policy_targets
          WHERE tenant_id = ? AND policy_version_id = ? AND priority = ?'
    );

    foreach (SERVICE_GOAL_DEFAULT_POLICIES as $policyKey => $definition) {
        $policyFind->execute([$tenantId, $policyKey]);
        $policyVersionId = (int) ($policyFind->fetchColumn() ?: 0);
        if ($policyVersionId <= 0) {
            try {
                $pdo->prepare(
                    'INSERT INTO service_goal_policy_versions
                        (tenant_id, policy_key, version_no, display_name, effective_from, clock_mode, time_zone, pause_mode)
                     VALUES (?, ?, 1, ?, ?, ?, ?, ?)'
                )->execute([
                    $tenantId,
                    $policyKey,
                    $definition['display_name'],
                    '1970-01-01 00:00:00',
                    'elapsed',
                    'UTC',
                    'none',
                ]);
            } catch (PDOException $e) {
                if (! service_goal_is_duplicate_key($e)) {
                    throw $e;
                }
            }
            $policyVersionId = service_goal_current_id(
                $pdo,
                'SELECT id
                   FROM service_goal_policy_versions
                  WHERE tenant_id = ? AND policy_key = ? AND version_no = 1',
                [$tenantId, $policyKey],
            );
        }
        if ($policyVersionId <= 0) {
            throw new RuntimeException('Could not provision the default service-goal policy.');
        }

        foreach (SERVICE_GOAL_PRIORITIES as $priority) {
            $targetFind->execute([$tenantId, $policyVersionId, $priority]);
            if ((int) ($targetFind->fetchColumn() ?: 0) > 0) {
                continue;
            }
            try {
                $pdo->prepare(
                    'INSERT INTO service_goal_policy_targets
                        (tenant_id, policy_version_id, priority, first_response_minutes, resolution_minutes)
                     VALUES (?, ?, ?, ?, NULL)'
                )->execute([
                    $tenantId,
                    $policyVersionId,
                    $priority,
                    $definition['first_response_minutes'],
                ]);
            } catch (PDOException $e) {
                if (! service_goal_is_duplicate_key($e)) {
                    throw $e;
                }
                $targetId = service_goal_current_id(
                    $pdo,
                    'SELECT id
                       FROM service_goal_policy_targets
                      WHERE tenant_id = ? AND policy_version_id = ? AND priority = ?',
                    [$tenantId, $policyVersionId, $priority],
                );
                if ($targetId <= 0) {
                    throw $e;
                }
            }
        }
    }
}

/**
 * Read the winner of a duplicate-key race through MySQL's current view.
 *
 * An ordinary SELECT inside a REPEATABLE READ transaction can retain the
 * snapshot from before the competing insert committed. A locking read sees
 * the committed row instead. SQLite is used by the hermetic contract test and
 * already observes the current connection state without MySQL's suffix.
 */
function service_goal_current_id(PDO $pdo, string $sql, array $params): int
{
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'mysql' && $pdo->inTransaction()) {
        $sql .= ' FOR SHARE';
    }

    $query = $pdo->prepare($sql);
    $query->execute($params);

    return (int) ($query->fetchColumn() ?: 0);
}

/** Refuse to hide anything except the expected unique-key race. */
function service_goal_is_duplicate_key(PDOException $error): bool
{
    $driverCode = (int) ($error->errorInfo[1] ?? 0);
    if ($driverCode === 1062) {
        return true;
    }

    return $driverCode === 19
        && str_contains(strtolower($error->getMessage()), 'unique constraint');
}

/**
 * Resolve the exact immutable target a new ticket must capture.
 *
 * Selection is tenant + the client's stable policy key + ticket priority.
 * The newest effective version wins. A version with an unsupported clock,
 * pause rule, or missing priority target fails visibly instead of silently
 * falling back to the older 2h/8h convention.
 *
 * @return array{
 *   id:int,tenant_id:int,policy_version_id:int,policy_key:string,
 *   version_no:int,display_name:string,effective_from:string,
 *   clock_mode:string,time_zone:string,pause_mode:string,priority:string,
 *   first_response_minutes:int,resolution_minutes:int|null
 * }
 */
function service_goal_target_for_client(
    PDO $pdo,
    int $tenantId,
    int $clientId,
    string $priority,
    ?string $effectiveAtUtc = null,
): array {
    if ($tenantId <= 0 || $clientId <= 0) {
        throw new InvalidArgumentException('A tenant and client are required for service goals.');
    }
    if (! in_array($priority, SERVICE_GOAL_PRIORITIES, true)) {
        throw new InvalidArgumentException('Unknown service-goal priority.');
    }

    $clientQuery = $pdo->prepare(
        'SELECT sla_tier FROM clients WHERE id = ? AND tenant_id = ?'
    );
    $clientQuery->execute([$clientId, $tenantId]);
    $policyKey = (string) ($clientQuery->fetchColumn() ?: '');
    if (! isset(SERVICE_GOAL_DEFAULT_POLICIES[$policyKey])) {
        throw new RuntimeException('The client has no supported service-goal policy key.');
    }

    service_goal_ensure_default_policies($pdo, $tenantId);

    $effectiveClause = 'effective_from <= UTC_TIMESTAMP()';
    $params = [$tenantId, $policyKey];
    if ($effectiveAtUtc !== null) {
        $effectiveTimestamp = service_goal_timestamp($effectiveAtUtc);
        if ($effectiveTimestamp === null) {
            throw new InvalidArgumentException('The policy effective time is invalid.');
        }
        $effectiveClause = 'effective_from <= ?';
        $params[] = gmdate('Y-m-d H:i:s', $effectiveTimestamp);
    }

    $currentRead = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
        && $pdo->inTransaction()
        ? ' FOR SHARE'
        : '';
    $policyQuery = $pdo->prepare(
        "SELECT id, tenant_id, policy_key, version_no, display_name,
                effective_from, clock_mode, time_zone, pause_mode
           FROM service_goal_policy_versions
          WHERE tenant_id = ? AND policy_key = ? AND {$effectiveClause}
          ORDER BY effective_from DESC, version_no DESC
          LIMIT 1{$currentRead}"
    );
    $policyQuery->execute($params);
    $policy = $policyQuery->fetch(PDO::FETCH_ASSOC);
    if (! is_array($policy)) {
        throw new RuntimeException('No service-goal policy is effective for this ticket.');
    }
    if (($policy['clock_mode'] ?? '') !== 'elapsed') {
        throw new UnexpectedValueException('This service-goal clock mode is not implemented.');
    }
    if (($policy['pause_mode'] ?? '') !== 'none') {
        throw new UnexpectedValueException('This service-goal pause mode is not implemented.');
    }

    $targetQuery = $pdo->prepare(
        'SELECT id, tenant_id, policy_version_id, priority,
                first_response_minutes, resolution_minutes
           FROM service_goal_policy_targets
          WHERE tenant_id = ? AND policy_version_id = ? AND priority = ?'
        . $currentRead
    );
    $targetQuery->execute([$tenantId, (int) $policy['id'], $priority]);
    $target = $targetQuery->fetch(PDO::FETCH_ASSOC);
    if (! is_array($target)) {
        throw new RuntimeException('The effective service-goal policy has no target for this priority.');
    }

    $responseMinutes = (int) ($target['first_response_minutes'] ?? 0);
    if ($responseMinutes <= 0) {
        throw new UnexpectedValueException('The first-response target must be greater than zero.');
    }

    return [
        'id' => (int) $target['id'],
        'tenant_id' => (int) $target['tenant_id'],
        'policy_version_id' => (int) $policy['id'],
        'policy_key' => (string) $policy['policy_key'],
        'version_no' => (int) $policy['version_no'],
        'display_name' => (string) $policy['display_name'],
        'effective_from' => (string) $policy['effective_from'],
        'clock_mode' => (string) $policy['clock_mode'],
        'time_zone' => (string) $policy['time_zone'],
        'pause_mode' => (string) $policy['pause_mode'],
        'priority' => (string) $target['priority'],
        'first_response_minutes' => $responseMinutes,
        'resolution_minutes' => $target['resolution_minutes'] === null
            ? null
            : (int) $target['resolution_minutes'],
    ];
}

/**
 * Freeze one new ticket's open time, exact target row, and response deadline.
 *
 * @return array{
 *   target_id:int,opened_at:string,due_at:string,first_response_minutes:int,
 *   id:int,tenant_id:int,policy_version_id:int,policy_key:string,
 *   version_no:int,display_name:string,effective_from:string,
 *   clock_mode:string,time_zone:string,pause_mode:string,priority:string,
 *   resolution_minutes:int|null
 * }
 */
function service_goal_snapshot_for_new_ticket(
    PDO $pdo,
    int $tenantId,
    int $clientId,
    string $priority,
    ?string $openedAtUtc = null,
): array {
    if ($openedAtUtc === null) {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $nowSql = $driver === 'sqlite' ? "SELECT datetime('now')" : 'SELECT UTC_TIMESTAMP()';
        $openedAtUtc = (string) $pdo->query($nowSql)->fetchColumn();
    }

    $openedAtTimestamp = service_goal_timestamp($openedAtUtc);
    if ($openedAtTimestamp === null) {
        throw new RuntimeException('Could not capture the ticket service-goal start time.');
    }
    $openedAtUtc = gmdate('Y-m-d H:i:s', $openedAtTimestamp);
    $target = service_goal_target_for_client(
        $pdo,
        $tenantId,
        $clientId,
        $priority,
        $openedAtUtc,
    );
    $dueAtUtc = gmdate(
        'Y-m-d H:i:s',
        $openedAtTimestamp + ($target['first_response_minutes'] * 60),
    );

    return [
        ...$target,
        'target_id' => $target['id'],
        'opened_at' => $openedAtUtc,
        'due_at' => $dueAtUtc,
    ];
}

/** Exact captured policy label, with an honest fallback for legacy tickets. */
function service_goal_ticket_policy_label(array $ticket): string
{
    if (empty($ticket['service_goal_target_id'])) {
        return 'Legacy response target · policy version unavailable';
    }
    if (
        empty($ticket['service_goal_policy_name'])
        || empty($ticket['service_goal_version_no'])
        || empty($ticket['service_goal_priority'])
        || (int) ($ticket['service_goal_response_minutes'] ?? 0) <= 0
    ) {
        return 'Captured policy unavailable';
    }

    $clock = ($ticket['service_goal_clock_mode'] ?? '') === 'elapsed'
        ? 'elapsed time'
        : str_replace('_', ' ', (string) ($ticket['service_goal_clock_mode'] ?? 'unknown clock'));

    return (string) $ticket['service_goal_policy_name']
        . ' v' . (int) $ticket['service_goal_version_no']
        . ' · ' . (string) $ticket['service_goal_priority'] . ' target'
        . ' · ' . service_goal_window_label((int) ($ticket['service_goal_response_minutes'] ?? 0))
        . ' response · ' . $clock;
}

/**
 * Return the first technician response only when it belongs to the ticket's
 * real lifetime and has happened by the caller's as-of clock.
 *
 * Stored message timestamps can be imported, moved, or malformed. Treating a
 * pre-open or future timestamp as a completed response would make a live lamp
 * and an attainment report claim work that had not actually happened.
 */
function service_goal_valid_first_response_timestamp(array $ticket, int $asOf): ?int
{
    $createdAt = service_goal_timestamp($ticket['created_at'] ?? null);
    $firstResponseAt = service_goal_timestamp($ticket['first_response_at'] ?? null);
    if ($createdAt === null
        || $firstResponseAt === null
        || $firstResponseAt < $createdAt
        || $firstResponseAt > $asOf
    ) {
        return null;
    }

    return $firstResponseAt;
}

/** Return a resolution only when it belongs to the ticket and exists as of the caller's clock. */
function service_goal_valid_resolution_timestamp(array $ticket, int $asOf): ?int
{
    $createdAt = service_goal_timestamp($ticket['created_at'] ?? null);
    $resolvedAt = service_goal_timestamp($ticket['resolved_at'] ?? null);
    if ($createdAt === null
        || $resolvedAt === null
        || $resolvedAt < $createdAt
        || $resolvedAt > $asOf
    ) {
        return null;
    }

    return $resolvedAt;
}

/**
 * Decide a ticket's current first-response outcome.
 *
 * true  = first technician response met the target
 * false = response was late, the target elapsed, or the ticket closed without
 *         a technician response
 * null  = the response target is pending or cannot be attributed after a merge
 */
function service_goal_response_outcome(array $ticket, ?int $now = null): ?bool
{
    if (service_goal_merge_excluded($ticket)) {
        return null;
    }

    $now ??= time();
    $dueAt = service_goal_timestamp($ticket['sla_due_at'] ?? null);
    if ($dueAt === null) {
        return false;
    }

    $firstResponseAt = service_goal_valid_first_response_timestamp($ticket, $now);
    if ($firstResponseAt !== null) {
        return $firstResponseAt <= $dueAt;
    }

    if (($ticket['status'] ?? '') === 'resolved'
        && service_goal_valid_resolution_timestamp($ticket, $now) !== null
    ) {
        return false;
    }

    return $now >= $dueAt ? false : null;
}

/**
 * A merge moves every message to the survivor, so neither side retains enough
 * provenance to attribute a first response to its original ticket reliably.
 */
function service_goal_merge_excluded(array $ticket): bool
{
    return ! empty($ticket['merged_into_id'])
        || (int) ($ticket['has_merged_sources'] ?? 0) > 0;
}

/**
 * Response-target state for queue and ticket lamps.
 *
 * @return array{state:string,label:string}
 */
function sla_info(array $ticket, ?int $now = null): array
{
    if (service_goal_merge_excluded($ticket)) {
        return ['state' => 'excluded', 'label' => 'Merged history'];
    }

    $now ??= time();
    $dueAt = service_goal_timestamp($ticket['sla_due_at'] ?? null);
    $firstResponseAt = service_goal_valid_first_response_timestamp($ticket, $now);
    $resolvedAt = service_goal_valid_resolution_timestamp($ticket, $now);
    $outcome = service_goal_response_outcome($ticket, $now);

    if ($outcome === true) {
        return ['state' => 'met', 'label' => 'Response met'];
    }

    if ($firstResponseAt !== null && $dueAt !== null) {
        return [
            'state' => 'breached',
            'label' => 'Response ' . service_goal_duration_label($firstResponseAt - $dueAt) . ' late',
        ];
    }

    if (($ticket['status'] ?? '') === 'resolved' && $resolvedAt !== null) {
        return ['state' => 'breached', 'label' => 'No response'];
    }

    if ($dueAt === null) {
        return ['state' => 'breached', 'label' => 'Target missing'];
    }

    $remaining = $dueAt - $now;
    $label = service_goal_duration_label($remaining);
    if ($remaining <= 0) {
        return ['state' => 'breached', 'label' => $label . ' over'];
    }
    if ($remaining < 2 * 3600) {
        return ['state' => 'at_risk', 'label' => $label . ' left'];
    }

    return ['state' => 'healthy', 'label' => $label . ' left'];
}

/**
 * Aggregate decided first-response outcomes for a tenant and rolling period.
 * Future open targets stay out of the denominator until they are answered,
 * closed, or elapsed. Merged sources and survivors are excluded because the
 * current merge operation moves messages without retaining response
 * provenance. The SQL intentionally works in both MySQL and SQLite so the
 * production query has a hermetic CI regression test.
 *
 * @return array{n:int,met:int,pct:int|null}
 */
function service_goal_response_attainment(
    PDO $pdo,
    int $tenantId,
    int $days = 30,
    ?int $now = null,
): array {
    $days = max(1, min(365, $days));
    $now ??= time();
    $nowUtc = gmdate('Y-m-d H:i:s', $now);
    $cutoffUtc = gmdate('Y-m-d H:i:s', $now - ($days * 86400));

    $query = $pdo->prepare(
        "SELECT COUNT(*) AS n,
                COALESCE(SUM(CASE
                    WHEN first_response_at IS NOT NULL AND first_response_at <= sla_due_at THEN 1
                    ELSE 0
                END), 0) AS met
           FROM (
                SELECT t.id,
                       t.status,
                       t.created_at,
                       t.resolved_at,
                       t.sla_due_at,
                       t.merged_into_id,
                       EXISTS (
                           SELECT 1
                             FROM tickets merged_source
                            WHERE merged_source.tenant_id = t.tenant_id
                              AND merged_source.merged_into_id = t.id
                       ) AS has_merged_sources,
                       MIN(m.created_at) AS first_response_at
                  FROM tickets t
                  LEFT JOIN messages m
                    ON m.ticket_id = t.id
                   AND m.kind = 'tech'
                   AND m.created_at >= t.created_at
                   AND m.created_at <= ?
                 WHERE t.tenant_id = ? AND t.created_at >= ?
                 GROUP BY t.id, t.status, t.created_at, t.resolved_at,
                          t.sla_due_at, t.merged_into_id
           ) outcomes
          WHERE merged_into_id IS NULL
            AND has_merged_sources = 0
            AND (first_response_at IS NOT NULL
                 OR (status = 'resolved' AND resolved_at >= created_at AND resolved_at <= ?)
                 OR sla_due_at <= ?)"
    );
    $query->execute([$nowUtc, $tenantId, $cutoffUtc, $nowUtc, $nowUtc]);
    $row = $query->fetch(PDO::FETCH_ASSOC) ?: [];
    $count = (int) ($row['n'] ?? 0);
    $met = (int) ($row['met'] ?? 0);

    return [
        'n' => $count,
        'met' => $met,
        'pct' => $count > 0 ? (int) round(100 * $met / $count) : null,
    ];
}
