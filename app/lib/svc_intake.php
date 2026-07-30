<?php
/**
 * Milepost alert → ticket intake (Phase 8.1, Path B).
 *
 * One direction: events are received and recorded — never acknowledged
 * back to Milepost, never remediated, never written to the Milepost DB.
 * Idempotent on (tenant_id, external_key): re-fires update the existing
 * ticket and append a system line; they never create duplicates.
 * Auto-close (founder rule, 2026-07-30): a source resolve closes a ticket
 * no human has touched (status still 'open'). Once a tech moves the ticket
 * (in_progress / waiting), ownership is human — the resolve lands as a
 * system line and the human still closes it. Replays never re-open.
 * Mirrors lib/intake.php conventions (catch-all client, SLA by tier,
 * kind='system' provenance lines) without touching the email pipeline.
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const SVC_SEVERITY_PRIORITY = [
    'info'     => 'low',
    'warning'  => 'normal',
    'critical' => 'urgent',
];

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
    $extKey = mb_substr(trim((string)($p['external_key'] ?? '')), 0, 64);
    if ($extKey === '') {
        return ['ok' => false, 'error' => 'external_key required'];
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
        // Auto-close a ticket no human has touched. Once a tech moves it
        // (in_progress / waiting), ownership is human: the source truth
        // lands as a system line and the human still closes it.
        if ($ticket['status'] === 'open') {
            svc_system_line((int)$ticket['id'], 'Resolved at source at ' . $occurredAt . ' UTC. Ticket auto-closed.');
            db()->prepare('UPDATE tickets SET status = "resolved", resolved_at = ? WHERE id = ?')
                ->execute([$occurredAt, (int)$ticket['id']]);
            return ['ok' => true, 'action' => 'updated', 'ticket' => (int)$ticket['id']];
        }
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
        $hours    = svc_client_sla_hours($clientId);
        db()->prepare(
            'INSERT INTO tickets (tenant_id, client_id, contact_id, subject, priority, channel, external_key, sla_due_at)
             VALUES (?,?,NULL,?,?,"alert",?,DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? HOUR))'
        )->execute([tenant_id(), $clientId, $subject, $priority, $extKey, $hours]);
        $tid = (int)db()->lastInsertId();
        svc_system_line($tid, svc_alert_detail($p, $occurredAt, 'Alert opened at source'));
        return ['ok' => true, 'action' => 'created', 'ticket' => $tid];
    }

    // Re-fire: refresh severity/subject, append provenance, never duplicate.
    // A re-fire landing after an auto-close is out-of-order noise (a true
    // new open arrives under a new external_key) — record it, stay closed.
    if ($ticket['status'] === 'resolved') {
        svc_system_line((int)$ticket['id'], svc_alert_detail($p, $occurredAt, 'Alert re-fired at source after close'));
        return ['ok' => true, 'action' => 'updated', 'ticket' => (int)$ticket['id']];
    }
    db()->prepare('UPDATE tickets SET priority = ?, subject = ? WHERE id = ?')
        ->execute([$priority, $subject, (int)$ticket['id']]);
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

/** SLA response hours by the matched client's tier (existing convention). */
function svc_client_sla_hours(int $clientId): int
{
    $q = db()->prepare('SELECT sla_tier FROM clients WHERE id = ?');
    $q->execute([$clientId]);
    return (($q->fetch()['sla_tier'] ?? 'standard') === 'premium') ? 2 : 8;
}
