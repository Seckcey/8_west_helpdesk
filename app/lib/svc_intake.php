<?php
/**
 * Milepost alert → ticket intake (Phase 8.1, Path B).
 *
 * One direction: events are received and recorded — never acknowledged
 * back to Milepost, never remediated, never written to the Milepost DB.
 * Idempotent on (tenant_id, external_key): re-fires update the existing
 * ticket and append a system line; they never create duplicates.
 * Auto-close (founder rule, tightened 2026-08-27): a source resolve closes
 * only a machine-created ticket whose one-use auto_close_eligible capability
 * is still present. Any human/customer message, technician time, merge, or
 * ticket mutation permanently consumes that capability. Replays never
 * re-open, and re-fires add evidence without overwriting human ticket fields.
 * Mirrors lib/intake.php conventions (catch-all client, captured service goal,
 * kind='system' provenance lines) without touching the email pipeline.
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const SVC_SEVERITY_PRIORITY = [
    'info'     => 'low',
    'warning'  => 'normal',
    'critical' => 'urgent',
];

/** The only signed service identity allowed to create telemetry tickets. */
const SVC_ALERT_SERVICE = 'milepost';
const SVC_ALERT_ID_MAX = '18446744073709551615'; // Milepost BIGINT UNSIGNED

/** Authentication is shared by svc endpoints; alert authority is not. */
function svc_alert_service_authorized(array $auth): bool
{
    return ($auth['ok'] ?? false) === true
        && hash_equals(SVC_ALERT_SERVICE, (string)($auth['service'] ?? ''));
}

/** Exact Milepost alerts.id namespace, including the unsigned 64-bit ceiling. */
function svc_alert_external_key_valid(string $externalKey): bool
{
    if (preg_match('/\Aalert:([1-9][0-9]{0,19})\z/D', $externalKey, $match) !== 1) {
        return false;
    }
    $sourceId = $match[1];
    return strlen($sourceId) < 20 || strcmp($sourceId, SVC_ALERT_ID_MAX) <= 0;
}

/**
 * Handle one verified alert payload. Returns:
 *   ['ok'=>true, 'action'=>'created|updated|ignored', 'ticket'=>?int]
 *   ['ok'=>false, 'error'=>reason]            — caller answers 422
 */
function svc_alert_handle(array $p): array
{
    $event = (string)($p['event'] ?? '');
    if (!in_array($event, ['opened', 'resolved'], true)) {
        return ['ok' => false, 'error' => 'unknown event'];
    }
    $extKey = trim((string)($p['external_key'] ?? ''));
    if (!svc_alert_external_key_valid($extKey)) {
        return ['ok' => false, 'error' => 'external_key invalid'];
    }
    $occurred = strtotime((string)($p['occurred_at'] ?? ''));
    if ($occurred === false || abs(time() - $occurred) > 24 * 3600) {
        return ['ok' => false, 'error' => 'occurred_at out of range'];
    }
    $occurredAt = gmdate('Y-m-d H:i:s', $occurred);

    $tq = db()->prepare('SELECT * FROM tickets WHERE tenant_id = ? AND external_key = ?');
    $tq->execute([tenant_id(), $extKey]);
    $ticket = $tq->fetch() ?: null;

    if ($event === 'resolved') {
        // Resolve raced ahead of open is not an error — the emitter must
        // not retry it forever.
        if (!$ticket) {
            return ['ok' => true, 'action' => 'ignored', 'ticket' => null];
        }
        // A replayed resolve on an already-closed ticket adds nothing.
        if ($ticket['status'] === 'resolved') {
            return ['ok' => true, 'action' => 'ignored', 'ticket' => (int)$ticket['id']];
        }
        // The guarded transition consumes eligibility and writes recovery
        // evidence atomically. A concurrent human mutation clears the bit,
        // so it cannot be closed by this path afterwards.
        if (ticket_try_machine_auto_close(
            db(),
            tenant_id(),
            (int)$ticket['id'],
            $occurredAt,
        )) {
            return ['ok' => true, 'action' => 'updated', 'ticket' => (int)$ticket['id']];
        }

        // A concurrent recovery may have won after our first read. Do not add
        // a duplicate line in that case.
        $state = db()->prepare('SELECT status FROM tickets WHERE id = ? AND tenant_id = ?');
        $state->execute([(int)$ticket['id'], tenant_id()]);
        if ($state->fetchColumn() === 'resolved') {
            return ['ok' => true, 'action' => 'ignored', 'ticket' => (int)$ticket['id']];
        }

        // Ineligible means a person owns the outcome even if the visible
        // status is still Open. Recovery remains useful evidence, not an
        // autonomous status change.
        svc_system_line((int)$ticket['id'], 'Resolved at source at ' . $occurredAt . ' UTC.');
        return ['ok' => true, 'action' => 'updated', 'ticket' => (int)$ticket['id']];
    }

    // event = opened
    $clientName = (string)($p['client']['name'] ?? '');
    $endpoint   = is_array($p['endpoint'] ?? null) ? $p['endpoint'] : [];
    $alert      = is_array($p['alert'] ?? null) ? $p['alert'] : [];
    $hostname   = mb_substr((string)($endpoint['hostname'] ?? ''), 0, 128);
    $severity   = mb_strtolower((string)($alert['severity'] ?? ''));
    $priority   = SVC_SEVERITY_PRIORITY[$severity] ?? 'normal';
    $ruleKey    = mb_substr((string)($alert['rule_key'] ?? ''), 0, 64) ?: 'alert';
    $sevTag     = $severity !== '' ? "[{$severity}] " : '';
    $subject    = mb_substr($sevTag . $ruleKey . ' on ' . ($hostname !== '' ? $hostname : 'endpoint'), 0, 190);

    if (!$ticket) {
        $clientId = svc_resolve_client_id($clientName);
        $goal = service_goal_snapshot_for_new_ticket(
            db(),
            tenant_id(),
            $clientId,
            $priority,
        );
        db()->prepare(
            'INSERT INTO tickets
                (tenant_id, client_id, contact_id, subject, priority, channel, external_key,
                 auto_close_eligible, sla_due_at, service_goal_target_id, created_at, updated_at)
             VALUES (?,?,NULL,?,?,"alert",?,1,?,?,?,?)'
        )->execute([
            tenant_id(),
            $clientId,
            $subject,
            $priority,
            $extKey,
            $goal['due_at'],
            $goal['target_id'],
            $goal['opened_at'],
            $goal['opened_at'],
        ]);
        $tid = (int)db()->lastInsertId();
        svc_system_line($tid, svc_alert_detail($p, $occurredAt, 'Alert opened at source'));
        return ['ok' => true, 'action' => 'created', 'ticket' => $tid];
    }

    // Re-fire: append provenance, never duplicate and never rewrite ticket
    // fields. The initial alert owns the initial subject/priority; after that,
    // a human owns triage. A true new open arrives under a new external_key.
    if ($ticket['status'] === 'resolved') {
        svc_system_line((int)$ticket['id'], svc_alert_detail($p, $occurredAt, 'Alert re-fired at source after close'));
        return ['ok' => true, 'action' => 'updated', 'ticket' => (int)$ticket['id']];
    }
    svc_system_line((int)$ticket['id'], svc_alert_detail($p, $occurredAt, 'Alert re-fired at source'));
    return ['ok' => true, 'action' => 'updated', 'ticket' => (int)$ticket['id']];
}
/** Multi-line provenance body stored as the ticket's system message. */
function svc_alert_detail(array $p, string $occurredAt, string $headline): string
{
    $endpoint = is_array($p['endpoint'] ?? null) ? $p['endpoint'] : [];
    $alert    = is_array($p['alert'] ?? null) ? $p['alert'] : [];
    $instance = (string)($alert['instance'] ?? '');
    $lines = [
        $headline . ' at ' . $occurredAt . ' UTC.',
        'rule: ' . (string)($alert['rule_key'] ?? ''),
        'metric: ' . (string)($alert['metric_key'] ?? '') . ($instance !== '' ? ' (' . $instance . ')' : ''),
        'severity: ' . (string)($alert['severity'] ?? ''),
        'endpoint: ' . trim((string)($endpoint['display_name'] ?? '') . ' / ' . (string)($endpoint['hostname'] ?? ''), ' /'),
        'client: ' . (string)($p['client']['name'] ?? ''),
        'message: ' . (string)($alert['message'] ?? ''),
    ];
    return mb_substr(implode("\n", $lines), 0, 8000);
}

function svc_system_line(int $ticketId, string $body): void
{
    db()->prepare('INSERT INTO messages (ticket_id, author_name, kind, body) VALUES (?,?,?,?)')
        ->execute([$ticketId, 'Milepost', 'system', $body]);
}

/**
 * Route to a client by exact (case-insensitive) name within the tenant.
 * No match → the "Milepost Intake" catch-all. Never auto-creates a real
 * client — misnamed sources are triaged by humans, like the email path.
 */
function svc_resolve_client_id(string $name): int
{
    $name = trim($name);
    if ($name !== '') {
        $q = db()->prepare('SELECT id FROM clients WHERE tenant_id = ? AND LOWER(name) = LOWER(?) LIMIT 1');
        $q->execute([tenant_id(), $name]);
        if ($row = $q->fetch()) {
            return (int)$row['id'];
        }
    }
    return svc_intake_client_id();
}

/** The catch-all client for unmatched alert sources (created once). */
function svc_intake_client_id(): int
{
    $q = db()->prepare('SELECT id FROM clients WHERE tenant_id = ? AND name = "Milepost Intake"');
    $q->execute([tenant_id()]);
    if ($row = $q->fetch()) {
        return (int)$row['id'];
    }
    db()->prepare('INSERT INTO clients (tenant_id, name, domain, sla_tier, notes) VALUES (?,?,"","standard",?)')
        ->execute([
            tenant_id(),
            'Milepost Intake',
            'Catch-all for Milepost alerts whose client name did not match a Safeharbor client. Reassign the ticket to the right client once the names line up.',
        ]);
    return (int)db()->lastInsertId();
}
