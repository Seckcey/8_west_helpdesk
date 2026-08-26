<?php
/**
 * Westy failure + flagged-answer reporting (2026-08-08).
 *
 * When Westy breaks, or a technician marks an answer unhelpful, a ticket opens
 * in 8 WEST IT'S OWN queue so our staff can fix Westy — never in a customer's.
 *
 * Four doors call in here and this is the only place that writes:
 *   - api/westy_feedback.php   session + CSRF, a technician's thumbs-DOWN
 *   - api/westy_thumbs_up.php  session + CSRF, a technician's thumbs-UP
 *   - westy_chat.php           in-process, Safeharbor's own failures
 *   - api/svc/westy.php        HMAC, Milepost + the Control Panel (their PRs)
 *
 * Only three of them can open a ticket. Thumbs-up writes one bounded usage row
 * and stops there — see westy_thumbs_up_record().
 *
 * Flood control is the whole game. The alert intake keys tickets on the
 * OCCURRENCE id (alert:231, alert:232, …), so one nagging memory warning
 * opened six tickets in a day. Here the key fingerprints the PROBLEM: a
 * normalised error class, or a normalised question — never a timestamp, never
 * a request id. Repeats update one ticket with a count and a last-seen.
 *
 * Reporting must never take Westy down. Every caller wraps these in try/catch
 * and this module raises nothing it can avoid; a broken reporter degrades to
 * the old behaviour (a swallowed failure), never to a 500 in the chat.
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/** Occurrence counts that earn a fresh line in the thread (and, at 25, a bump). */
const WESTY_REPORT_THRESHOLDS = [5, 25, 100];
const WESTY_REPORT_PRIORITY_AT = 25;

/** Apps allowed to report, and the client row each one lands in. */
const WESTY_REPORT_APPS = [
    'safeharbor'   => 'Safeharbor',
    'milepost'     => 'Milepost',
    'controlpanel' => 'Control Panel',
];

/* ── the destination ─────────────────────────────────────────────────────── */

/**
 * 8 West IT's own tenant, resolved EXPLICITLY by slug.
 *
 * tenant_id() returns 1 when there is no session (bootstrap.php), which today
 * happens to be the 8 West tenant — by accident, not by design, and only
 * because exactly one tenant exists. The moment a second one is created that
 * fallback would silently misroute internal tickets into a customer's queue.
 * So: resolve by slug, and if the row is missing, record NOTHING. Fail closed.
 */
function westy_report_tenant_id(): ?int
{
    // Only a SUCCESSFUL resolution is memoised. Caching the miss would pin the
    // process to "no destination" for the rest of its life, and would make the
    // fail-closed path untestable alongside everything else.
    static $id = null;
    if ($id !== null) return $id;
    $slug = (string)cfg('westy_report.tenant_slug', '8west');
    $q = db()->prepare('SELECT id FROM tenants WHERE slug = ? LIMIT 1');
    $q->execute([$slug]);
    $row = $q->fetch();
    if (!$row) {
        error_log('westy_report: no tenant with slug "' . $slug . '" — report dropped');
        return null;
    }
    $id = (int)$row['id'];
    return $id;
}

/**
 * The per-app catch-all client, created on demand — same pattern as
 * svc_intake_client_id(). One row per reporting app so "show me everything
 * wrong with Westy in Milepost" is one click.
 */
function westy_report_client_id(int $tenantId, string $app): int
{
    $name = 'Westy — ' . (WESTY_REPORT_APPS[$app] ?? ucfirst($app));
    $q = db()->prepare('SELECT id FROM clients WHERE tenant_id = ? AND name = ? LIMIT 1');
    $q->execute([$tenantId, $name]);
    if ($row = $q->fetch()) {
        return (int)$row['id'];
    }
    db()->prepare('INSERT INTO clients (tenant_id, name, domain, sla_tier, notes) VALUES (?,?,"","standard",?)')
        ->execute([
            $tenantId,
            $name,
            'Internal: Westy defects reported from ' . (WESTY_REPORT_APPS[$app] ?? $app)
            . '. These are 8 West IT\'s own tickets for fixing the assistant — never a customer\'s work.',
        ]);
    return (int)db()->lastInsertId();
}

/* ── scrubbing and normalising ───────────────────────────────────────────── */

/**
 * Strip anything that could carry a person's words or address out of provider
 * error text.
 *
 * ai.php builds its error strings from the PROVIDER's message, and some
 * providers echo part of the request back inside an error — so "failures carry
 * no chat text" is NOT automatic. The receiver scrubs even when the caller
 * claims it already did: the other two apps live in repos we do not control.
 */
function westy_scrub(string $s): string
{
    $s = utf8_clean($s);
    // Email addresses, wherever they appear.
    $s = (string)preg_replace('/[\w.+-]+@[\w-]+\.[\w.-]+/u', '[email]', $s);
    // Quoted spans long enough to be somebody's sentence rather than a token.
    $s = (string)preg_replace('/"[^"]{40,}"/u', '"…"', $s);
    $s = (string)preg_replace("/'[^']{40,}'/u", "'…'", $s);
    $s = trim((string)preg_replace('/\s+/u', ' ', $s));
    return mb_substr($s, 0, 500);
}

/**
 * Collapse an error message to its CLASS, so cosmetic variation is one problem.
 * "Timeout after 30s" and "timeout after 31 s" fingerprint identically, as do
 * two overload errors differing only in a request id.
 */
function westy_error_class(string $msg): string
{
    $s = mb_strtolower(utf8_clean($msg));
    $s = (string)preg_replace('/"[^"]*"/u', '…', $s);
    $s = (string)preg_replace("/'[^']*'/u", '…', $s);
    $s = (string)preg_replace('/\d+/u', '#', $s);
    $s = trim((string)preg_replace('/\s+/u', ' ', $s));
    return mb_substr($s, 0, 120);
}

/** Normalise a question so the same ask, typed differently, is one ticket. */
function westy_question_norm(string $q): string
{
    $s = mb_strtolower(utf8_clean($q));
    $s = (string)preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s);
    $s = trim((string)preg_replace('/\s+/u', ' ', $s));
    return mb_substr($s, 0, 300);
}

/**
 * The base key. Describes the problem and nothing about the moment it happened
 * — no timestamp, no request id, no occurrence number.
 * Budget: "westy:"(6) + app(<=12) + ":"(1) + kind(4) + ":"(1) + hash(16) = 40.
 */
function westy_fingerprint(array $p): string
{
    if ($p['event'] === 'failure') {
        $f = $p['failure'];
        $material = implode('|', [
            $p['app'], $p['surface'], $f['provider'], $f['model'],
            $f['error_class'], (string)$f['http_status'],
        ]);
        $kind = 'fail';
    } else {
        $material = implode('|', [$p['app'], westy_question_norm($p['flagged']['question'])]);
        $kind = 'flag';
    }
    return 'westy:' . $p['app'] . ':' . $kind . ':' . mb_substr(hash('sha256', $material), 0, 16);
}

/* ── payload validation ──────────────────────────────────────────────────── */

/**
 * Validate and canonicalise one report. Returns the normalised payload, or
 * ['error' => reason] for the caller to answer 422.
 *
 * Decision 2 is enforced HERE, not trusted to the caller: a failure event's
 * `flagged` block is dropped on the floor, so no chat text can ride in on a
 * failure no matter what another repo sends.
 */
function westy_report_normalize(array $p): array
{
    $event = (string)($p['event'] ?? '');
    if (!in_array($event, ['failure', 'flagged'], true)) {
        return ['error' => 'unknown event'];
    }
    $app = mb_strtolower(trim((string)($p['app'] ?? '')));
    if (!isset(WESTY_REPORT_APPS[$app])) {
        return ['error' => 'unknown app'];
    }
    $surface = mb_strtolower(trim((string)($p['surface'] ?? 'westy_chat')));
    $surface = (string)preg_replace('/[^a-z0-9_]+/', '_', $surface);
    $surface = mb_substr(trim($surface, '_'), 0, 32) ?: 'westy_chat';

    $rawWhen  = (string)($p['occurred_at'] ?? '');
    $occurred = $rawWhen === '' ? time() : strtotime($rawWhen);
    if ($occurred === false || abs(time() - $occurred) > 24 * 3600) {
        return ['error' => 'occurred_at out of range'];
    }

    $userRef = mb_substr((string)($p['user_ref'] ?? ''), 0, 32);
    $userRef = (string)preg_replace('/[^A-Za-z0-9_.:-]+/', '', $userRef);

    $out = [
        'event'       => $event,
        'app'         => $app,
        'surface'     => $surface,
        'occurred_at' => gmdate('Y-m-d H:i:s', $occurred),
        'user_ref'    => $userRef,
    ];

    if ($event === 'failure') {
        $f        = is_array($p['failure'] ?? null) ? $p['failure'] : [];
        $detail   = westy_scrub((string)($f['error_detail'] ?? ''));
        $rawClass = trim((string)($f['error_class'] ?? ''));
        $class    = westy_error_class($rawClass !== '' ? $rawClass : $detail);
        if ($class === '') {
            return ['error' => 'failure needs error_class or error_detail'];
        }
        $status = $f['http_status'] ?? 0;
        $out['failure'] = [
            'provider'     => mb_substr(westy_scrub((string)($f['provider'] ?? '')), 0, 32),
            'model'        => mb_substr(westy_scrub((string)($f['model'] ?? '')), 0, 64),
            'http_status'  => (is_numeric($status) && $status >= 0 && $status < 1000) ? (int)$status : 0,
            'error_class'  => $class,
            'error_detail' => $detail,
        ];
        return $out;   // any 'flagged' block is deliberately dropped here
    }

    $g        = is_array($p['flagged'] ?? null) ? $p['flagged'] : [];
    $question = trim(utf8_clean((string)($g['question'] ?? '')));
    $answer   = trim(utf8_clean((string)($g['answer'] ?? '')));
    if ($question === '' || $answer === '') {
        return ['error' => 'flagged needs question and answer'];
    }
    $out['flagged'] = [
        'question' => mb_substr($question, 0, 2000),
        'answer'   => mb_substr($answer, 0, 4000),
        'note'     => mb_substr(trim(utf8_clean((string)($g['note'] ?? ''))), 0, 500),
    ];
    return $out;
}

/* ── the handler ─────────────────────────────────────────────────────────── */

/**
 * Record one report. Returns:
 *   ['ok'=>true, 'action'=>'created|updated|ignored', 'ticket'=>?int]
 *   ['ok'=>false, 'error'=>reason]      — caller answers 422
 *
 * 'ignored' means the destination tenant is missing: fail closed, never guess.
 */
function westy_report_record(array $raw): array
{
    $p = westy_report_normalize($raw);
    if (isset($p['error'])) {
        return ['ok' => false, 'error' => $p['error']];
    }
    $tenantId = westy_report_tenant_id();
    if ($tenantId === null) {
        return ['ok' => true, 'action' => 'ignored', 'ticket' => null];
    }

    $kind = $p['event'] === 'failure' ? 'fail' : 'flag';
    $fp   = westy_fingerprint($p);

    // Newest generation for this problem. A generation is only ever started
    // when the previous one was RESOLVED (§5.3) — a fixed problem coming back
    // is news, not a repeat.
    $q = db()->prepare(
        'SELECT * FROM westy_reports WHERE tenant_id = ? AND fingerprint = ? ORDER BY generation DESC LIMIT 1'
    );
    $q->execute([$tenantId, $fp]);
    $report = $q->fetch() ?: null;

    $ticket = null;
    if ($report && !empty($report['ticket_id'])) {
        $tq = db()->prepare('SELECT id, status, priority FROM tickets WHERE id = ?');
        $tq->execute([(int)$report['ticket_id']]);
        $ticket = $tq->fetch() ?: null;
    }

    if ($report && $ticket && $ticket['status'] !== 'resolved') {
        return westy_report_bump($p, $report, $ticket);
    }
    $generation = $report ? ((int)$report['generation'] + 1) : 1;
    $previous   = ($report && $ticket) ? (int)$ticket['id'] : null;

    try {
        return westy_report_open($p, $tenantId, $fp, $kind, $generation, $previous);
    } catch (PDOException $e) {
        // A failure STORM is exactly when two reports for the same brand-new
        // problem race each other, so this is the likely case, not the exotic
        // one. The loser of the race hits the unique key on
        // (tenant_id, external_key) — re-read the winner's row and count this
        // occurrence against it instead of losing it to a 500.
        if (($e->errorInfo[1] ?? 0) !== 1062) {
            throw $e;
        }
        $q = db()->prepare('SELECT * FROM westy_reports WHERE tenant_id = ? AND fingerprint = ? ORDER BY generation DESC LIMIT 1');
        $q->execute([$tenantId, $fp]);
        $winner = $q->fetch() ?: null;
        if (!$winner || empty($winner['ticket_id'])) {
            throw $e;
        }
        $tq = db()->prepare('SELECT id, status, priority FROM tickets WHERE id = ?');
        $tq->execute([(int)$winner['ticket_id']]);
        $wTicket = $tq->fetch() ?: null;
        if (!$wTicket) {
            throw $e;
        }
        return westy_report_bump($p, $winner, $wTicket);
    }
}

/** First sighting of a problem (or its return after a close): a new ticket. */
function westy_report_open(array $p, int $tenantId, string $fp, string $kind, int $generation, ?int $previous): array
{
    $externalKey = $generation > 1 ? $fp . ':g' . $generation : $fp;
    $clientId    = westy_report_client_id($tenantId, $p['app']);
    $priority    = $kind === 'fail' ? 'normal' : 'low';
    $goal = service_goal_snapshot_for_new_ticket(
        db(),
        $tenantId,
        $clientId,
        $priority,
    );

    db()->prepare(
        'INSERT INTO tickets
            (tenant_id, client_id, contact_id, subject, priority, channel, external_key,
             sla_due_at, service_goal_target_id, created_at, updated_at)
         VALUES (?,?,NULL,?,?,"alert",?,?,?,?,?)'
    )->execute([
        $tenantId,
        $clientId,
        westy_report_subject($p),
        $priority,
        $externalKey,
        $goal['due_at'],
        $goal['target_id'],
        $goal['opened_at'],
        $goal['opened_at'],
    ]);
    $ticketId = (int)db()->lastInsertId();

    if ($previous !== null) {
        westy_report_line(
            $ticketId,
            'This problem was fixed and has come back. The previous ticket for it was #' . $previous
            . ', which is closed — this is occurrence run ' . $generation . '.'
        );
    }
    $messageId = westy_report_line($ticketId, westy_report_body($p, 1, $p['occurred_at'], $p['occurred_at']));

    db()->prepare(
        'INSERT INTO westy_reports
           (tenant_id, fingerprint, external_key, app, kind, generation, ticket_id, system_message_id,
            occurrences, first_seen_at, last_seen_at, detail_json)
         VALUES (?,?,?,?,?,?,?,?,1,?,?,?)'
    )->execute([
        $tenantId, $fp, $externalKey, $p['app'], $kind, $generation, $ticketId, $messageId,
        $p['occurred_at'], $p['occurred_at'], westy_report_detail_json($p),
    ]);

    return ['ok' => true, 'action' => 'created', 'ticket' => $ticketId];
}

/**
 * A repeat. One ticket, one line rewritten in place with the running count —
 * NOT a new line every time, which is how the alert intake drowns a thread.
 * A fresh line is appended only at 5, 25 and 100.
 */
function westy_report_bump(array $p, array $report, array $ticket): array
{
    $ticketId = (int)$ticket['id'];
    $count    = (int)$report['occurrences'] + 1;
    $firstAt  = (string)$report['first_seen_at'];
    $lastAt   = $p['occurred_at'];

    // A flagged answer keeps the FIRST example: the wording that got flagged
    // first is the one worth reading, and swapping it every repeat would make
    // the ticket unstable to review. A failure keeps the latest technical
    // envelope, which is all machine detail anyway.
    //
    // $kept — not $p — is what gets written back to detail_json below, or the
    // "first example" would only survive one repeat: occurrence 2 would store
    // occurrence 2's text and occurrence 3 would then restore THAT.
    $kept = $p['event'] === 'failure' ? $p : westy_report_restore_flag($p, $report);
    $body = westy_report_body($kept, $count, $firstAt, $lastAt);

    $messageId = (int)($report['system_message_id'] ?? 0);
    if ($messageId > 0) {
        db()->prepare('UPDATE messages SET body = ? WHERE id = ?')->execute([$body, $messageId]);
    } else {
        $messageId = westy_report_line($ticketId, $body);
    }

    if (in_array($count, WESTY_REPORT_THRESHOLDS, true)) {
        westy_report_line(
            $ticketId,
            'Still happening — ' . $count . ' occurrences now, most recently at ' . $lastAt . ' UTC.'
        );
    }
    if ($count >= WESTY_REPORT_PRIORITY_AT && in_array($ticket['priority'], ['low', 'normal'], true)) {
        db()->prepare('UPDATE tickets SET priority = "high" WHERE id = ?')->execute([$ticketId]);
    }

    db()->prepare(
        'UPDATE westy_reports SET occurrences = ?, last_seen_at = ?, system_message_id = ?, detail_json = ?
          WHERE id = ?'
    )->execute([$count, $lastAt, $messageId, westy_report_detail_json($kept), (int)$report['id']]);

    return ['ok' => true, 'action' => 'updated', 'ticket' => $ticketId];
}

/** Put the FIRST flagged question/answer back in front of the body builder. */
function westy_report_restore_flag(array $p, array $report): array
{
    $stored = json_decode((string)($report['detail_json'] ?? ''), true);
    if (is_array($stored) && !empty($stored['flagged']['question']) && !empty($stored['flagged']['answer'])) {
        $p['flagged'] = $stored['flagged'];
    }
    return $p;
}

/** The stored envelope for the report row (already scrubbed by normalize). */
function westy_report_detail_json(array $p): string
{
    $keep = ['app' => $p['app'], 'surface' => $p['surface'], 'user_ref' => $p['user_ref']];
    if ($p['event'] === 'failure') {
        $keep['failure'] = $p['failure'];
    } else {
        $keep['flagged'] = $p['flagged'];
    }
    return mb_substr((string)json_encode($keep, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0, 60000);
}

function westy_report_subject(array $p): string
{
    $label = WESTY_REPORT_APPS[$p['app']] ?? $p['app'];
    if ($p['event'] === 'failure') {
        return mb_substr('Westy failure — ' . mb_substr($p['failure']['error_class'], 0, 90) . ' (' . $label . ')', 0, 190);
    }
    $q = mb_substr(trim((string)preg_replace('/\s+/u', ' ', $p['flagged']['question'])), 0, 60);
    return mb_substr('Westy answer flagged — ' . $q . ' (' . $label . ')', 0, 190);
}

/** The one system line, rebuilt from scratch on every occurrence. */
function westy_report_body(array $p, int $count, string $firstAt, string $lastAt): string
{
    $label = WESTY_REPORT_APPS[$p['app']] ?? $p['app'];
    $who   = $p['user_ref'] !== '' ? 'user #' . $p['user_ref'] : 'not recorded';
    $lines = [];

    if ($p['event'] === 'failure') {
        $f = $p['failure'];
        $lines[] = 'Westy failed in ' . $label . ' (' . $p['surface'] . ').';
        $lines[] = '';
        $lines[] = 'provider: ' . ($f['provider'] !== '' ? $f['provider'] : '(not reported)');
        $lines[] = 'model: ' . ($f['model'] !== '' ? $f['model'] : '(not reported)');
        $lines[] = 'http status: ' . ($f['http_status'] > 0 ? (string)$f['http_status'] : '(none)');
        $lines[] = 'error class: ' . $f['error_class'];
        if ($f['error_detail'] !== '') {
            $lines[] = 'detail: ' . $f['error_detail'];
        }
    } else {
        $g = $p['flagged'];
        $lines[] = 'A technician marked a Westy answer as unhelpful in ' . $label . ' (' . $p['surface'] . ').';
        $lines[] = '';
        $lines[] = 'Question:';
        $lines[] = $g['question'];
        $lines[] = '';
        $lines[] = 'Answer:';
        $lines[] = $g['answer'];
        if ($g['note'] !== '') {
            $lines[] = '';
            $lines[] = 'What went wrong:';
            $lines[] = $g['note'];
        }
    }

    $lines[] = '';
    $lines[] = 'reported by: ' . $who;
    $lines[] = 'occurrences: ' . $count;
    $lines[] = 'first seen: ' . $firstAt . ' UTC';
    $lines[] = 'last seen: ' . $lastAt . ' UTC';
    if ($p['event'] === 'failure') {
        $lines[] = '';
        $lines[] = 'No chat text is recorded for failures.';
    }
    $lines[] = '';
    $lines[] = 'This line is kept up to date — the count and last-seen change in place rather than'
             . ' adding a message per occurrence.';

    return mb_substr(implode("\n", $lines), 0, 16000);
}

/** Provenance line. Author is Westy — svc_system_line() hardcodes 'Milepost'. */
function westy_report_line(int $ticketId, string $body): int
{
    db()->prepare('INSERT INTO messages (ticket_id, author_name, kind, body) VALUES (?,?,?,?)')
        ->execute([$ticketId, 'Westy', 'system', $body]);
    return (int)db()->lastInsertId();
}

/* ── thumbs up ───────────────────────────────────────────────────────────── */

/**
 * Record that a technician liked an answer. Opens NO ticket, and deliberately
 * so: tickets are for problems, and good news must never make somebody work.
 * The whole signal is one bounded `assistant_log` row.
 *
 * It also stores no question or answer text. A thumbs-down shows the tech the
 * exact words and waits for Send; a thumbs-up is one click with no review box,
 * so there is no moment at which anyone agreed to send those words anywhere.
 * What is kept instead is the SAME fingerprint the flag path uses, so "this
 * question class gets praised" and "this question class gets flagged" line up
 * against each other without a syllable of chat text being stored.
 *
 * Returns true if the row landed. Never throws: a lost compliment is not worth
 * a single broken chat.
 */
function westy_thumbs_up_record(int $userId, string $question, string $answer, string $app = 'safeharbor'): bool
{
    try {
        $app = mb_strtolower(trim($app));
        if (!isset(WESTY_REPORT_APPS[$app])) return false;
        $question = trim(utf8_clean($question));
        $answer   = trim(utf8_clean($answer));
        if ($question === '' || $answer === '') return false;

        $fp = westy_fingerprint(['event' => 'flagged', 'app' => $app, 'flagged' => ['question' => $question]]);
        $meta = 'fp=' . $fp . ' q_len=' . mb_strlen($question) . ' a_len=' . mb_strlen($answer);

        db()->prepare('INSERT INTO assistant_log (user_id, action, meta) VALUES (?,?,?)')
            ->execute([$userId, 'westy_thumbs_up', mb_substr($meta, 0, 255)]);
        return true;
    } catch (Throwable $e) {
        error_log('westy_thumbs_up_record: ' . $e->getMessage());
        return false;
    }
}

/* ── in-process helpers (Safeharbor's own Westy) ─────────────────────────── */

/**
 * Report a Safeharbor Westy failure. Never throws, never returns anything the
 * caller must handle: a broken reporter must not cost a technician their chat.
 *
 * A provider REFUSAL is not a failure — that is the safety layer working, and
 * ticketing it would file noise every time someone asks something odd. The
 * technician can still flag the refusal by hand.
 */
function westy_report_failure(array $result, string $surface, int $userId, string $errorClass = ''): void
{
    try {
        if (!empty($result['refusal'])) return;
        $c = function_exists('ai_config') ? ai_config() : [];
        westy_report_record([
            'event'    => 'failure',
            'app'      => 'safeharbor',
            'surface'  => $surface,
            'user_ref' => (string)$userId,
            'failure'  => [
                'provider'     => (string)($c['provider'] ?? ''),
                'model'        => (string)($c['model'] ?? ''),
                'http_status'  => 0,
                'error_class'  => $errorClass,
                'error_detail' => (string)($result['error'] ?? ''),
            ],
        ]);
    } catch (Throwable $e) {
        error_log('westy_report_failure: ' . $e->getMessage());
    }
}
