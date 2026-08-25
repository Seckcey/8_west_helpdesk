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

    $dueAt = service_goal_timestamp($ticket['sla_due_at'] ?? null);
    if ($dueAt === null) {
        return false;
    }

    $firstResponseAt = service_goal_timestamp($ticket['first_response_at'] ?? null);
    if ($firstResponseAt !== null) {
        return $firstResponseAt <= $dueAt;
    }

    if (($ticket['status'] ?? '') === 'resolved') {
        return false;
    }

    return ($now ?? time()) >= $dueAt ? false : null;
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
    $firstResponseAt = service_goal_timestamp($ticket['first_response_at'] ?? null);
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

    if (($ticket['status'] ?? '') === 'resolved') {
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
                  LEFT JOIN messages m ON m.ticket_id = t.id AND m.kind = 'tech'
                 WHERE t.tenant_id = ? AND t.created_at >= ?
                 GROUP BY t.id, t.status, t.sla_due_at, t.merged_into_id
           ) outcomes
          WHERE merged_into_id IS NULL
            AND has_merged_sources = 0
            AND (first_response_at IS NOT NULL OR status = 'resolved' OR sla_due_at <= ?)"
    );
    $query->execute([$tenantId, $cutoffUtc, $nowUtc]);
    $row = $query->fetch(PDO::FETCH_ASSOC) ?: [];
    $count = (int) ($row['n'] ?? 0);
    $met = (int) ($row['met'] ?? 0);

    return [
        'n' => $count,
        'met' => $met,
        'pct' => $count > 0 ? (int) round(100 * $met / $count) : null,
    ];
}
