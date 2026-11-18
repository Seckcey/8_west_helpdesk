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
require_once __DIR__ . '/suite_customer_sync.php';
require_once __DIR__ . '/ticket_clients.php';

const SVC_SEVERITY_PRIORITY = [
    'info'     => 'low',
    'warning'  => 'normal',
    'critical' => 'urgent',
];

/** The only signed service identity allowed to create telemetry tickets. */
const SVC_ALERT_SERVICE = 'milepost';
const SVC_ALERT_ID_MAX = '18446744073709551615'; // Milepost BIGINT UNSIGNED
const SVC_ALERT_DETAIL_LIMIT = 8000;
const SVC_ALERT_ROUTING_LINES = [
    'customer_id_malformed' => 'Routing: sent to Milepost Intake because the customer id from Milepost was not a valid id.',
    'customer_binding_not_found' => 'Routing: sent to Milepost Intake because Safeharbor has no customer with the id Milepost sent.',
    'customer_binding_inactive' => 'Routing: sent to Milepost Intake because that customer is not active in Safeharbor.',
    'customer_not_operational' => 'Routing: sent to Milepost Intake because that customer is not currently serviceable in Safeharbor.',
    'client_name_not_matched' => 'Routing: sent to Milepost Intake because no Safeharbor client has the name Milepost sent.',
];

/** Authentication is shared by svc endpoints; alert authority is not. */
function svc_alert_service_authorized(array $auth): bool
{
    return ($auth['ok'] ?? false) === true
        && hash_equals(SVC_ALERT_SERVICE, (string)($auth['service'] ?? ''));
}

/** Each Milepost source table has its own key space and the same unsigned 64-bit ceiling. */
function svc_alert_external_key_valid(string $externalKey): bool
{
    if (preg_match('/\A(?:alert|netalert|netrule):([1-9][0-9]{0,19})\z/D', $externalKey, $match) !== 1) {
        return false;
    }
    $sourceId = $match[1];
    return strlen($sourceId) < 20 || strcmp($sourceId, SVC_ALERT_ID_MAX) <= 0;
}

/** Associative JSON decoding alone would turn a triage object with keys "0", "1" into a list. */
function svc_alert_decode(string $body): ?array
{
    $payload = json_decode($body, true);
    if (!is_array($payload)) return null;
    if (is_array($payload['alert']['triage'] ?? null) && !is_array(json_decode($body)->alert->triage ?? null)) {
        unset($payload['alert']['triage']);
    }
    return $payload;
}

/**
 * Handle one verified alert payload. Returns:
 *   ['ok'=>true, 'action'=>'created|updated|ignored', 'ticket'=>?int]
 *   ['ok'=>false, 'error'=>reason]            — caller answers 422
 */
function svc_alert_handle(array $p): array
{
    $p = utf8_clean_input($p);
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
    if ($ticket) {
        return svc_alert_refire($ticket, $p, $occurredAt);
    }

    $pdo = db();
    if ($pdo->inTransaction()) {
        throw new RuntimeException('Alert ticket creation requires transaction ownership.');
    }
    $tenantId = tenant_id();
    $pdo->beginTransaction();
    try {
        // Customer sync and Westy both lock the tenant first. Serialize first-use
        // creation of the unbound intake record (client names are not unique).
        $tenant = $pdo->prepare('SELECT id FROM tenants WHERE id = ? FOR UPDATE');
        $tenant->execute([$tenantId]);
        if ($tenant->fetchColumn() === false) {
            throw new RuntimeException('Alert tenant unavailable.');
        }
        // A parallel opener may have committed while we waited for that lock.
        $tq->execute([$tenantId, $extKey]);
        if ($ticket = $tq->fetch()) {
            $result = svc_alert_refire($ticket, $p, $occurredAt);
            $pdo->commit();
            return $result;
        }

        $client = is_array($p['client'] ?? null) ? $p['client'] : [];
        [$clientId, $reason] = svc_resolve_client_id($pdo, $tenantId, $client);
        $hostname = mb_substr((string)($p['endpoint']['hostname'] ?? ''), 0, 128);
        $severity = mb_strtolower((string)($p['alert']['severity'] ?? ''));
        $priority = SVC_SEVERITY_PRIORITY[$severity] ?? 'normal';
        $ruleKey = mb_substr((string)($p['alert']['rule_key'] ?? ''), 0, 64) ?: 'alert';
        $sevTag = $severity !== '' ? "[{$severity}] " : '';
        $subject = mb_substr($sevTag . $ruleKey . ' on ' . ($hostname !== '' ? $hostname : 'endpoint'), 0, 190);
        $goal = service_goal_snapshot_for_new_ticket($pdo, $tenantId, $clientId, $priority);
        // Migration 018 grants automatic close only in the original alert: namespace.
        $autoCloseEligible = str_starts_with($extKey, 'alert:') ? 1 : 0;
        $pdo->prepare(
            'INSERT INTO tickets
                (tenant_id, client_id, contact_id, subject, priority, channel, external_key,
                 auto_close_eligible, sla_due_at, service_goal_target_id, created_at, updated_at)
             VALUES (?,?,NULL,?,?,"alert",?,?,?,?,?,?)'
        )->execute([
            $tenantId, $clientId, $subject, $priority, $extKey, $autoCloseEligible,
            $goal['due_at'], $goal['target_id'], $goal['opened_at'], $goal['opened_at'],
        ]);
        $tid = (int)$pdo->lastInsertId();
        svc_system_line($tid, svc_alert_detail($p, $occurredAt, 'Alert opened at source', $reason));
        $pdo->commit();
        return ['ok' => true, 'action' => 'created', 'ticket' => $tid];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

/** A re-fire appends evidence without rerouting or changing any ticket fields. */
function svc_alert_refire(array $ticket, array $p, string $occurredAt): array
{
    $headline = $ticket['status'] === 'resolved' ? 'Alert re-fired at source after close' : 'Alert re-fired at source';
    svc_system_line((int)$ticket['id'], svc_alert_detail($p, $occurredAt, $headline));
    return ['ok' => true, 'action' => 'updated', 'ticket' => (int)$ticket['id']];
}

/** Multi-line provenance body stored as the ticket's system message. */
function svc_alert_detail(array $p, string $occurredAt, string $headline, ?string $reason = null): string
{
    $endpoint = is_array($p['endpoint'] ?? null) ? $p['endpoint'] : [];
    $alert    = is_array($p['alert'] ?? null) ? $p['alert'] : [];
    $instance = (string)($alert['instance'] ?? '');
    $header = implode("\n", [
        $headline . ' at ' . $occurredAt . ' UTC.',
        'rule: ' . (string)($alert['rule_key'] ?? ''),
        'metric: ' . (string)($alert['metric_key'] ?? '') . ($instance !== '' ? ' (' . $instance . ')' : ''),
        'severity: ' . (string)($alert['severity'] ?? ''),
        'endpoint: ' . trim((string)($endpoint['display_name'] ?? '') . ' / ' . (string)($endpoint['hostname'] ?? ''), ' /'),
        'client: ' . (string)($p['client']['name'] ?? ''),
    ]);
    $message = (string)($alert['message'] ?? '');
    $lines = ($p['event'] ?? null) === 'opened' && ($alert['rule_key'] ?? null) === 'offline'
        ? svc_alert_triage_lines($alert['triage'] ?? null) : [];
    if ($reason !== null) $lines[] = SVC_ALERT_ROUTING_LINES[$reason];
    $suffix = '';
    if ($lines !== []) {
        $suffix = "\n" . implode("\n", $lines);
        // Keep the fixed evidence and the message label even when the sender fills the body.
        $budget = SVC_ALERT_DETAIL_LIMIT - mb_strlen("\nmessage: " . $suffix);
        $header = mb_substr($header, 0, $budget);
        $message = mb_substr($message, 0, $budget - mb_strlen($header));
    }
    return mb_substr($header . "\nmessage: " . $message . $suffix, 0, SVC_ALERT_DETAIL_LIMIT);
}

/** Keep only complete findings in the first four wire slots. Emit in contract order. */
function svc_alert_triage_lines(mixed $triage): array
{
    if (!is_array($triage) || !array_is_list($triage)) return [];
    $lines = ['client_devices_offline' => null, 'after_job' => null, 'reboot_was_pending' => null, 'never_checked_in' => null];
    foreach (array_slice($triage, 0, 4) as $finding) {
        if (!is_array($finding)) continue;
        $code = $finding['code'] ?? null;
        if (!is_string($code) || !array_key_exists($code, $lines) || $lines[$code] !== null) continue;
        switch ($code) {
            case 'client_devices_offline':
                $offline = $finding['offline'] ?? null;
                $total = $finding['total'] ?? null;
                if (count($finding) === 3 && is_int($offline) && is_int($total)
                    && $offline >= 2 && $offline <= $total && $total <= 9999) {
                    $lines[$code] = "Triage: {$offline} of {$total} computers for this client stopped reporting at about the same time. Check the site's power and internet first.";
                }
                break;
            case 'after_job':
                if (count($finding) === 2) {
                    $lines[$code] = match ($finding['kind'] ?? null) {
                        'reboot' => 'Triage: this computer went quiet within an hour of a restart sent from Milepost.',
                        'patch_install' => 'Triage: this computer went quiet within an hour of an update install sent from Milepost.',
                        'agent_update' => 'Triage: this computer went quiet within an hour of a Milepost agent update.',
                        default => null,
                    };
                }
                break;
            case 'reboot_was_pending':
                if (count($finding) === 1) $lines[$code] = 'Triage: a restart was pending when this computer last reported.';
                break;
            case 'never_checked_in':
                if (count($finding) === 1) $lines[$code] = 'Triage: this computer has never reported to Milepost.';
                break;
        }
    }
    return array_values(array_filter($lines));
}

function svc_system_line(int $ticketId, string $body): void
{
    db()->prepare('INSERT INTO messages (ticket_id, author_name, kind, body) VALUES (?,?,?,?)')
        ->execute([$ticketId, 'Milepost', 'system', $body]);
}

/**
 * Called inside the ticket transaction with the tenant locked. Only a missing
 * or null ID allows legacy name routing.
 * @return array{int, ?string} Client ID and the reason for a fallback, if any.
 */
function svc_resolve_client_id(PDO $pdo, int $tenantId, array $client): array
{
    $customerId = $client['customer_id'] ?? null;
    if ($customerId === null) {
        $name = trim((string)($client['name'] ?? ''));
        if ($name !== '') {
            $q = $pdo->prepare('SELECT id FROM clients WHERE tenant_id = ? AND LOWER(name) = LOWER(?) ORDER BY id LIMIT 1');
            $q->execute([$tenantId, $name]);
            $id = $q->fetchColumn();
            if ($id !== false) return [(int)$id, null];
        }
        $reason = 'client_name_not_matched';
    } elseif (!is_string($customerId) || preg_match(SUITE_CUSTOMER_SYNC_UUID_V4, $customerId) !== 1) {
        $reason = 'customer_id_malformed';
    } else {
        $q = $pdo->prepare('SELECT client_id, status FROM suite_customer_sync_bindings WHERE tenant_id = ? AND customer_id = ? FOR SHARE');
        $q->execute([$tenantId, $customerId]);
        $binding = $q->fetch();
        if (!$binding) {
            $reason = 'customer_binding_not_found';
        } elseif ($binding['status'] !== 'active') {
            $reason = 'customer_binding_inactive';
        } elseif (!managed_customer_operational($pdo, $tenantId, (int)$binding['client_id'], true)) {
            $reason = 'customer_not_operational';
        } else {
            return [(int)$binding['client_id'], null];
        }
    }
    return [svc_intake_client_id($pdo, $tenantId), $reason];
}

/**
 * The catch-all client for unmatched alert sources (created once).
 *
 * It is the oldest client named "Milepost Intake" that is not tied to a customer record: no
 * customer binding and no source key. A managed customer that happens to carry that name is
 * never used. The catch-all stays the catch-all after someone edits its notes or domain;
 * matching on those would create a second one on the next unmatched alert and split the queue.
 * The caller holds the tenant lock, so two alerts cannot both create it.
 */
function svc_intake_client_id(PDO $pdo, int $tenantId): int
{
    $q = $pdo->prepare(
        'SELECT c.id
           FROM clients c
          WHERE c.tenant_id = ? AND c.name = ? AND c.source_key IS NULL
            AND NOT EXISTS (SELECT 1 FROM suite_customer_sync_bindings b
                             WHERE b.tenant_id = c.tenant_id AND b.client_id = c.id)
          ORDER BY c.id LIMIT 1'
    );
    $q->execute([$tenantId, 'Milepost Intake']);
    $id = $q->fetchColumn();
    if ($id !== false) return (int)$id;
    $pdo->prepare('INSERT INTO clients (tenant_id, name, domain, sla_tier, notes) VALUES (?,?,"","standard",?)')
        ->execute([
            $tenantId,
            'Milepost Intake',
            TICKET_MILEPOST_INTAKE_NOTES,
        ]);
    return (int)$pdo->lastInsertId();
}
