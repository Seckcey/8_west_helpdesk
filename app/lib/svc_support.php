<?php
/**
 * Human support intake from our other products (2026-08-09).
 *
 * A tenant admin inside Coastmark clicks "Get help", types a question, and it
 * arrives here as a signed service request. One click = one ticket in 8 West
 * IT's own queue, filed under a client row for their organisation, with their
 * words stored exactly as they typed them.
 *
 * NOT suite-only: Waypoint (added 2026-08-09) is a standalone product outside
 * 8 West IT 365 with its own customers. Producers are listed in
 * SUPPORT_SOURCES below and need no relationship to the suite beyond a
 * registered service identity — do not assume a caller here is a suite app,
 * and do not reach for the suite SSO contract to reason about one.
 *
 * This is deliberately NOT api/svc/alerts.php and NOT api/svc/westy.php:
 *
 *   - alerts.php is shaped for machines. It maps severities to priorities and
 *     auto-closes tickets when the source says the problem cleared. Nobody is
 *     going to send us a "resolved" event for a person's question.
 *   - westy.php collapses reports onto a fingerprint of the PROBLEM and
 *     re-scrubs every stored string. Pointed at support requests it would
 *     merge two admins' questions into one ticket and blank out anything in
 *     quotes. That behaviour is right for defect reports and wrong here.
 *
 * So: no dedupe (each submission is its own ticket, idempotent only on the
 * caller's external_key), no scrubbing (subject and body stored verbatim
 * inside length caps), and channel='portal' rather than 'alert' — a person
 * typed this.
 *
 * The reply path is our existing email. The ticket carries a contact row, so
 * a tech's reply is emailed out with a [#id] subject tag and the requester's
 * answer threads back onto the same ticket through lib/intake.php. The calling
 * product builds no second inbox and we never call back into it.
 *
 * The client row name appends " ({label})" to the display name the caller
 * sends. Both emitter teams confirmed against their real tenant data that they
 * send the BARE company name, so rows read "Acme MSP (Coastmark)". Do not add
 * a de-duplication guard: the only doubled name ever seen came from a canary
 * whose own display name contained the product name (2026-08-09).
 *
 * Contract: docs/coastmark-support-intake-contract.md
 * Current status of this and every other moving part: docs/where-things-stand.md
 */
declare(strict_types=1);

require_once __DIR__ . '/svc_intake.php';   // svc_client_sla_hours() + bootstrap
require_once __DIR__ . '/intake.php';       // intake_is_auto_mail() + mail_queue()

/**
 * Producers allowed to post a support request, keyed by service identity.
 *
 * The membership check matters: svc_authenticate() accepts ANY registered
 * identity with a valid signature, so without this the Milepost alert emitter
 * could open support tickets. An identity gets one job.
 *
 * One entry per PRODUCT, never one shared entry for several. Waypoint is a
 * standalone product with its own customers, not a flavour of Coastmark, so it
 * signs with its own identity and its own secret: a single key common to both
 * would make either app a way to post as the other, and would mean revoking
 * one revokes both. `source` is what lands in clients.source_key, so it also
 * keeps the two products' client rows apart for a customer using both.
 */
const SUPPORT_SOURCES = [
    'coastmark-support' => ['source' => 'coastmark', 'label' => 'Coastmark'],
    'waypoint-support'  => ['source' => 'waypoint',  'label' => 'Waypoint'],
];

/** Subject cap 160 of the column's 190 — headroom for merge/reply markers. */
const SUPPORT_SUBJECT_MAX = 160;
/** Body cap matches the email intake's, so both front doors store alike. */
const SUPPORT_BODY_MAX = 8000;

/** Fallbacks for the per-tenant caps (config: support_intake.*). */
const SUPPORT_RATE_PER_MIN = 20;
const SUPPORT_RATE_PER_DAY = 100;

/**
 * Own kill switch on top of the shared one. `svc.enabled` is TRUE in
 * production and has been since the Milepost emitter shipped, so a new
 * endpoint riding on it alone would go live the moment it deployed.
 */
function support_enabled(): bool
{
    return svc_enabled() && (bool)cfg('svc.support_enabled', false);
}

/** The producer behind a service identity, or null if it may not post here. */
function support_source_for(string $service): ?array
{
    return SUPPORT_SOURCES[$service] ?? null;
}

/**
 * 8 West IT's own tenant, resolved EXPLICITLY by slug — same rule and same
 * reason as westy_report_tenant_id(). tenant_id() falls back to 1 when there
 * is no session, which is the 8 West tenant today by accident rather than by
 * design; the day a second tenant exists that fallback would drop a partner's
 * support request into a customer's queue. Missing row → record nothing.
 */
function support_tenant_id(): ?int
{
    // Only a SUCCESSFUL resolution is memoised: caching the miss would pin the
    // process to "no destination" for the rest of its life.
    static $id = null;
    if ($id !== null) return $id;
    $slug = (string)cfg('support_intake.tenant_slug', '8west');
    $q = db()->prepare('SELECT id FROM tenants WHERE slug = ? LIMIT 1');
    $q->execute([$slug]);
    $row = $q->fetch();
    if (!$row) {
        error_log('support_intake: no tenant with slug "' . $slug . '" — request dropped');
        return null;
    }
    $id = (int)$row['id'];
    return $id;
}

/* ── routing ─────────────────────────────────────────────────────────────── */

/**
 * The client row for one Coastmark tenant, created on first sight.
 *
 * Keyed on clients.source_key ('coastmark:acme-msp'), never on the display
 * name — so "Acme MSP has 3 open requests" holds even after a tech renames
 * the row. The name is set once and never rewritten from the wire afterwards:
 * whatever our staff call this client is our business, not the caller's.
 */
function support_client_id(int $tenantId, array $src, string $slug, string $display): int
{
    $sourceKey = mb_substr($src['source'] . ':' . $slug, 0, 64);
    $found = support_client_by_key($tenantId, $sourceKey);
    if ($found !== null) return $found;

    $name = mb_substr($display . ' (' . $src['label'] . ')', 0, 128);
    try {
        db()->prepare(
            'INSERT INTO clients (tenant_id, name, domain, source_key, sla_tier, notes) VALUES (?,?,"",?,"standard",?)'
        )->execute([
            $tenantId,
            $name,
            $sourceKey,
            'Support requests raised from inside ' . $src['label'] . ' by the team at ' . $display
            . '. Routing follows the key "' . $sourceKey . '" — renaming this client is safe.',
        ]);
    } catch (PDOException $e) {
        // Two first-ever requests from the same tenant can race. The loser
        // hits uq_clients_tenant_source; read the winner's row instead.
        if (($e->errorInfo[1] ?? 0) !== 1062) throw $e;
        $found = support_client_by_key($tenantId, $sourceKey);
        if ($found === null) throw $e;
        return $found;
    }
    return (int)db()->lastInsertId();
}

function support_client_by_key(int $tenantId, string $sourceKey): ?int
{
    $q = db()->prepare('SELECT id FROM clients WHERE tenant_id = ? AND source_key = ? LIMIT 1');
    $q->execute([$tenantId, $sourceKey]);
    $row = $q->fetch();
    return $row ? (int)$row['id'] : null;
}

/**
 * The requester as a contact under that client row. This is what makes the
 * reply path work — ticket.php only emails a reply when the ticket has a
 * contact with an address.
 */
function support_contact_id(int $clientId, string $email, string $name): int
{
    $q = db()->prepare('SELECT id FROM contacts WHERE client_id = ? AND email = ? LIMIT 1');
    $q->execute([$clientId, $email]);
    if ($row = $q->fetch()) {
        return (int)$row['id'];
    }
    db()->prepare('INSERT INTO contacts (client_id, name, email) VALUES (?,?,?)')
        ->execute([$clientId, $name, $email]);
    return (int)db()->lastInsertId();
}

/**
 * Per-tenant cap, counted on OUR side.
 *
 * The existing 120/min in svc_auth.php is per service identity, and one
 * identity carries every Coastmark tenant — so on its own it lets a single
 * noisy MSP starve the rest. Coastmark throttling their own UI is welcome but
 * is a different job (stopping a double-click); this one is ours to enforce.
 */
function support_rate_check(string $source, string $slug): bool
{
    $minuteBucket = (int)floor(time() / 60);
    $dayBucket    = (int)floor(time() / 86400);
    $windows = [];
    if (($perMin = (int)cfg('support_intake.per_tenant_per_min', SUPPORT_RATE_PER_MIN)) > 0) {
        $windows[] = ['minute', $minuteBucket, $perMin];
    }
    if (($perDay = (int)cfg('support_intake.per_tenant_per_day', SUPPORT_RATE_PER_DAY)) > 0) {
        $windows[] = ['day', $dayBucket, $perDay];
    }

    $ok = true;
    foreach ($windows as [$kind, $bucket, $limit]) {
        db()->prepare(
            'INSERT INTO svc_support_rate (source, tenant_slug, window_kind, bucket, hits) VALUES (?,?,?,?,1)
             ON DUPLICATE KEY UPDATE hits = hits + 1'
        )->execute([$source, $slug, $kind, $bucket]);
        $q = db()->prepare(
            'SELECT hits FROM svc_support_rate WHERE source = ? AND tenant_slug = ? AND window_kind = ? AND bucket = ?'
        );
        $q->execute([$source, $slug, $kind, $bucket]);
        if ((int)$q->fetchColumn() > $limit) $ok = false;
    }

    // Opportunistic cleanup of spent windows — keeps the table tiny.
    db()->prepare('DELETE FROM svc_support_rate WHERE window_kind = "minute" AND bucket < ?')
        ->execute([$minuteBucket - 10]);
    db()->prepare('DELETE FROM svc_support_rate WHERE window_kind = "day" AND bucket < ?')
        ->execute([$dayBucket - 2]);

    return $ok;
}

/* ── payload validation ──────────────────────────────────────────────────── */

/**
 * Squash a value that must live on ONE line: subjects, names, page labels.
 * Control characters go first — the subject is reused as an email Subject
 * header, and a stray newline in a header is a header-injection hole.
 */
function support_one_line(string $s): string
{
    $s = utf8_clean($s);
    $s = (string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s);
    return trim((string)preg_replace('/\s+/u', ' ', $s));
}

/**
 * Validate and canonicalise one request. Returns the normalised payload, or
 * ['error' => reason] for the caller to answer 422.
 *
 * What this deliberately does NOT do: scrub, redact, normalise or fingerprint
 * the human text. Subject and body are the request; mangling them is the one
 * failure this endpoint exists to avoid. utf8_clean() and the control-char
 * strip are byte hygiene, not content edits.
 */
function support_normalize(array $p): array
{
    if ((string)($p['event'] ?? '') !== 'support_request') {
        return ['error' => 'unknown event'];
    }

    // Idempotency key. Fits tickets.external_key (64) and stays in a charset
    // that cannot surprise a LIKE, a URL or a log line.
    $key = trim((string)($p['external_key'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9:._-]{1,64}$/', $key)) {
        return ['error' => 'external_key invalid'];
    }

    $rawWhen  = (string)($p['occurred_at'] ?? '');
    $occurred = $rawWhen === '' ? time() : strtotime($rawWhen);
    if ($occurred === false || abs(time() - $occurred) > 24 * 3600) {
        return ['error' => 'occurred_at out of range'];
    }

    $t    = is_array($p['tenant'] ?? null) ? $p['tenant'] : [];
    $slug = mb_strtolower(trim((string)($t['slug'] ?? '')));
    if (!preg_match('/^[a-z0-9][a-z0-9-]{0,47}$/', $slug)) {
        return ['error' => 'tenant.slug invalid'];
    }
    $display = mb_substr(support_one_line((string)($t['display_name'] ?? '')), 0, 110);
    if ($display === '') $display = $slug;

    $r     = is_array($p['requester'] ?? null) ? $p['requester'] : [];
    $email = mb_strtolower(trim((string)($r['email'] ?? '')));
    // Required, not optional: without a reply-to there is nobody to answer,
    // and the whole point of using our email path is that the answer lands.
    if ($email === '' || mb_strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['error' => 'requester.email invalid'];
    }
    $name = mb_substr(support_one_line((string)($r['name'] ?? '')), 0, 128);
    if ($name === '') $name = ucfirst((string)strtok($email, '@'));

    $subject = mb_substr(support_one_line((string)($p['subject'] ?? '')), 0, SUPPORT_SUBJECT_MAX);
    if ($subject === '') return ['error' => 'subject required'];

    // Verbatim, minus control characters that are not newline or tab.
    $body = utf8_clean((string)($p['body'] ?? ''));
    $body = trim((string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', $body));
    $body = mb_substr($body, 0, SUPPORT_BODY_MAX);
    if ($body === '') return ['error' => 'body required'];

    $c = is_array($p['context'] ?? null) ? $p['context'] : [];

    return [
        'external_key'    => $key,
        'occurred_at'     => gmdate('Y-m-d H:i:s', $occurred),
        'tenant_slug'     => $slug,
        'tenant_name'     => $display,
        'requester_email' => $email,
        'requester_name'  => $name,
        'subject'         => $subject,
        'body'            => $body,
        'page'            => mb_substr(support_one_line((string)($c['page'] ?? '')), 0, 190),
        'app_version'     => mb_substr(support_one_line((string)($c['app_version'] ?? '')), 0, 32),
    ];
}

/* ── the handler ─────────────────────────────────────────────────────────── */

/**
 * Record one support request. Returns:
 *   ['ok'=>true, 'action'=>'created|ignored', 'ticket'=>?int]
 *   ['ok'=>false, 'error'=>reason, 'code'=>?int]   — 422 unless code says else
 *
 * 'ignored' means one of two harmless things: the caller retried a submission
 * we already stored, or 8 West's tenant row is missing and we fail closed
 * rather than guess a destination. Neither is worth a retry.
 */
function support_record(array $raw, string $service): array
{
    $src = support_source_for($service);
    if ($src === null) {
        return ['ok' => false, 'code' => 401, 'error' => 'unauthorized'];
    }

    $p = support_normalize($raw);
    if (isset($p['error'])) {
        return ['ok' => false, 'error' => $p['error']];
    }

    $tenantId = support_tenant_id();
    if ($tenantId === null) {
        return ['ok' => true, 'action' => 'ignored', 'ticket' => null];
    }

    // Idempotency before rate limiting, on purpose: a network retry of a
    // request we already have must not spend the caller's budget.
    $existing = support_ticket_by_key($tenantId, $p['external_key']);
    if ($existing !== null) {
        return ['ok' => true, 'action' => 'ignored', 'ticket' => $existing];
    }

    if (!support_rate_check($src['source'], $p['tenant_slug'])) {
        return ['ok' => false, 'code' => 429, 'error' => 'rate limited'];
    }

    try {
        return support_open($p, $tenantId, $src);
    } catch (PDOException $e) {
        // Two deliveries of the same submission racing each other: the loser
        // hits uq_tickets_tenant_extkey. Report the winner rather than 500 and
        // invite a third attempt.
        if (($e->errorInfo[1] ?? 0) !== 1062) throw $e;
        $winner = support_ticket_by_key($tenantId, $p['external_key']);
        if ($winner === null) throw $e;
        return ['ok' => true, 'action' => 'ignored', 'ticket' => $winner];
    }
}

function support_ticket_by_key(int $tenantId, string $key): ?int
{
    $q = db()->prepare('SELECT id FROM tickets WHERE tenant_id = ? AND external_key = ? LIMIT 1');
    $q->execute([$tenantId, $key]);
    $row = $q->fetch();
    return $row ? (int)$row['id'] : null;
}

/** One request, one ticket: the person's words, then our provenance line. */
function support_open(array $p, int $tenantId, array $src): array
{
    $clientId  = support_client_id($tenantId, $src, $p['tenant_slug'], $p['tenant_name']);
    $contactId = support_contact_id($clientId, $p['requester_email'], $p['requester_name']);
    $hours     = svc_client_sla_hours($clientId);

    db()->prepare(
        'INSERT INTO tickets (tenant_id, client_id, contact_id, subject, priority, channel, external_key, sla_due_at)
         VALUES (?,?,?,?,"normal","portal",?,DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? HOUR))'
    )->execute([$tenantId, $clientId, $contactId, $p['subject'], $p['external_key'], $hours]);
    $ticketId = (int)db()->lastInsertId();

    // kind='client' — this is the requester talking, not a machine event, and
    // it should read in the thread exactly like an emailed request does.
    db()->prepare('INSERT INTO messages (ticket_id, author_name, kind, body) VALUES (?,?,"client",?)')
        ->execute([$ticketId, $p['requester_name'], $p['body']]);

    db()->prepare('INSERT INTO messages (ticket_id, author_name, kind, body) VALUES (?,?,"system",?)')
        ->execute([$ticketId, $src['label'], support_provenance($p, $src, $hours)]);

    support_ack($ticketId, $p, $hours);

    return ['ok' => true, 'action' => 'created', 'ticket' => $ticketId];
}

/** Where this came from, in words a tech can act on without asking anyone. */
function support_provenance(array $p, array $src, int $hours): string
{
    $lines = [
        'Support request from ' . $p['tenant_name'] . ', sent through ' . $src['label'] . '.',
        '',
        'from: ' . $p['requester_name'] . ' <' . $p['requester_email'] . '>',
        'organisation: ' . $p['tenant_name'] . ' (' . $p['tenant_slug'] . ')',
        'raised at: ' . $p['occurred_at'] . ' UTC',
    ];
    if ($p['page'] !== '')        $lines[] = 'where in the app: ' . $p['page'];
    if ($p['app_version'] !== '') $lines[] = 'app version: ' . $p['app_version'];
    $lines[] = 'reference: ' . $p['external_key'];
    $lines[] = '';
    $lines[] = 'Reply on this ticket and it is emailed to them; their answer comes back to this'
             . ' thread. SLA response due in ' . $hours . ' hours.';

    return mb_substr(implode("\n", $lines), 0, 8000);
}

/**
 * The acknowledgement, which is also what makes threading work: it puts the
 * [#id] token in the requester's mailbox so a plain reply finds its way home.
 *
 * Never fatal. The ticket is the thing that matters; a mail failure here would
 * otherwise 500, the caller would retry, and the retry would only be ignored
 * as a duplicate anyway.
 */
function support_ack(int $ticketId, array $p, int $hours): void
{
    try {
        if (!cfg('support_intake.ack_email', true)) return;
        // Mail-loop protection is law (house rules): never write to a machine.
        if (intake_is_auto_mail($p['requester_email'], '')) return;
        mail_queue(
            $p['requester_email'],
            '[#' . $ticketId . '] ' . $p['subject'],
            "Hi {$p['requester_name']},\n\n"
            . "We've got it — ticket #{$ticketId} is open with 8 West IT and a tech will reply"
            . " within {$hours} hours.\n\n"
            . "Reply to this email any time to add to the thread (keep [#{$ticketId}] in the subject).\n"
            . "— Safeharbor by 8 West IT",
            $ticketId
        );
    } catch (Throwable $e) {
        error_log('support_ack: ' . $e->getMessage());
    }
}
