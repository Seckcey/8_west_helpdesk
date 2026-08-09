<?php
/**
 * Hermetic tests for Westy failure + flagged-answer reporting, and for the
 * thumbs-up signal that must never become a ticket.
 *
 * CLI only. Runs against a dedicated scratch database — NEVER the live one:
 *   CREATE DATABASE safeharbor_test;
 *   GRANT ALL PRIVILEGES ON safeharbor_test.* TO 'safeharbor'@'localhost';
 *
 * Run:  php app/tests/westy_report_test.php
 * Exit: 0 = all green, 1 = failures.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../lib/westy_report.php';

// Point the shared connection at the scratch database BEFORE the first db().
$CONFIG['db']['name'] = 'safeharbor_test';

$failCount = 0;
$checkCount = 0;

function check(string $name, bool $cond): void
{
    global $failCount, $checkCount;
    $checkCount++;
    if ($cond) {
        echo "ok {$checkCount} - {$name}\n";
    } else {
        $failCount++;
        echo "FAIL {$checkCount} - {$name}\n";
    }
}

function fresh_schema(bool $withTenant = true): void
{
    $pdo = db();
    $tables = $pdo->query(
        'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()'
    )->fetchAll(PDO::FETCH_COLUMN);
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables as $t) {
        $pdo->exec('DROP TABLE IF EXISTS `' . $t . '`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    foreach (['schema.sql', 'migrations/002_svc_intake.sql', 'migrations/008_westy_reports.sql'] as $f) {
        $sql = (string)file_get_contents(__DIR__ . '/../db/' . $f);
        foreach (explode(";\n", $sql) as $stmt) {
            if (trim($stmt) !== '') {
                $pdo->exec($stmt);
            }
        }
    }
    if ($withTenant) {
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, '8 West IT, LLC', '8west')");
    }
    // A customer tenant that must NEVER receive a Westy ticket.
    $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (9, 'Acme Dental', 'acme')");
}

function fail_payload(array $over = []): array
{
    return array_replace_recursive([
        'event'       => 'failure',
        'app'         => 'safeharbor',
        'surface'     => 'westy_chat',
        'occurred_at' => gmdate('c'),
        'user_ref'    => '3',
        'failure'     => [
            'provider'     => 'anthropic',
            'model'        => 'claude-opus-4-8',
            'http_status'  => 529,
            'error_class'  => '',
            'error_detail' => 'AI provider error: overloaded (request req_10231)',
        ],
    ], $over);
}

function flag_payload(array $over = []): array
{
    return array_replace_recursive([
        'event'       => 'flagged',
        'app'         => 'safeharbor',
        'surface'     => 'westy_chat',
        'occurred_at' => gmdate('c'),
        'user_ref'    => '2',
        'flagged'     => [
            'question' => 'How do I merge two tickets?',
            'answer'   => 'Open the Tools menu and choose Combine.',
            'note'     => 'There is no Tools menu.',
        ],
    ], $over);
}

function report_row(string $fingerprintLike): ?array
{
    $q = db()->prepare('SELECT * FROM westy_reports WHERE external_key LIKE ? ORDER BY generation DESC LIMIT 1');
    $q->execute([$fingerprintLike]);
    return $q->fetch() ?: null;
}

function ticket(int $id): ?array
{
    $q = db()->prepare('SELECT * FROM tickets WHERE id = ?');
    $q->execute([$id]);
    return $q->fetch() ?: null;
}

function messages(int $ticketId): array
{
    $q = db()->prepare('SELECT * FROM messages WHERE ticket_id = ? ORDER BY id');
    $q->execute([$ticketId]);
    return $q->fetchAll();
}

function client_name(int $clientId): string
{
    $q = db()->prepare('SELECT name FROM clients WHERE id = ?');
    $q->execute([$clientId]);
    return (string)$q->fetchColumn();
}

function ticket_count(): int
{
    return (int)db()->query('SELECT COUNT(*) FROM tickets')->fetchColumn();
}

/* ── 10. missing destination tenant: record nothing, never throw ──────────
   Runs FIRST, while no '8west' tenant exists, so the fail-closed path is
   exercised for real rather than simulated. */
fresh_schema(false);
$r = westy_report_record(fail_payload());
check('missing_tenant_is_ignored_not_thrown', $r['ok'] === true && $r['action'] === 'ignored' && $r['ticket'] === null);
check('missing_tenant_creates_no_ticket', ticket_count() === 0);

/* ── from here on the destination exists ─────────────────────────────────── */
fresh_schema(true);

/* ── 11. error_class normalisation ───────────────────────────────────────── */
check('class_collapses_digits', westy_error_class('Timeout after 30s') === westy_error_class('Timeout after 31s'));
check('class_collapses_quotes', westy_error_class('bad field "who am i"') === westy_error_class('bad field "what is this"'));
check('class_is_case_insensitive', westy_error_class('OVERLOADED') === westy_error_class('overloaded'));
check('class_distinguishes_real_difference', westy_error_class('overloaded') !== westy_error_class('rate limited'));
check('class_is_bounded', mb_strlen(westy_error_class(str_repeat('x', 500))) <= 120);

/* ── 9. scrubbing ────────────────────────────────────────────────────────── */
check('scrub_removes_email', !str_contains(westy_scrub('failed for ana@8westit.com today'), '@8westit.com'));
check('scrub_keeps_shape', str_contains(westy_scrub('failed for ana@8westit.com today'), '[email]'));
$long = 'provider said "' . str_repeat('the client asked about payroll ', 5) . '"';
check('scrub_removes_long_quoted_span', !str_contains(westy_scrub($long), 'payroll'));
check('scrub_keeps_short_quotes', str_contains(westy_scrub('field "id" missing'), '"id"'));
check('scrub_is_bounded', mb_strlen(westy_scrub(str_repeat('y', 900))) <= 500);

/* ── 1. first failure creates one ticket, right tenant, right client ─────── */
$r = westy_report_record(fail_payload());
check('failure_creates_ticket', $r['ok'] === true && $r['action'] === 'created' && !empty($r['ticket']));
$t = ticket((int)$r['ticket']);
check('failure_lands_in_8west_tenant', (int)($t['tenant_id'] ?? 0) === 1);
check('failure_never_lands_in_customer_tenant', (int)($t['tenant_id'] ?? 0) !== 9);
check('failure_client_is_westy_safeharbor', client_name((int)$t['client_id']) === 'Westy — Safeharbor');
check('failure_channel_is_alert', ($t['channel'] ?? '') === 'alert');
check('failure_priority_normal', ($t['priority'] ?? '') === 'normal');
check('failure_subject_names_westy', str_starts_with((string)($t['subject'] ?? ''), 'Westy failure — '));
check('failure_external_key_is_fingerprint', str_starts_with((string)($t['external_key'] ?? ''), 'westy:safeharbor:fail:'));
check('failure_key_within_column', mb_strlen((string)($t['external_key'] ?? '')) <= 64);
$m = messages((int)$r['ticket']);
check('failure_has_one_system_line', count($m) === 1 && $m[0]['kind'] === 'system');
check('failure_line_authored_by_westy', ($m[0]['author_name'] ?? '') === 'Westy');
check('failure_line_has_provider', str_contains($m[0]['body'], 'provider: anthropic'));
check('failure_line_says_no_chat_text', str_contains($m[0]['body'], 'No chat text is recorded'));
check('failure_detail_is_scrubbed_of_request_id', !str_contains($m[0]['body'], 'error class: ai provider error: overloaded (request req_10231)'));
$firstTicket = (int)$r['ticket'];

/* ── 2. same problem, different id and time: updates, never duplicates ───── */
$r2 = westy_report_record(fail_payload(['failure' => ['error_detail' => 'AI provider error: overloaded (request req_99999)']]));
check('repeat_updates_same_ticket', $r2['ok'] === true && $r2['action'] === 'updated' && (int)$r2['ticket'] === $firstTicket);
check('repeat_creates_no_second_ticket', ticket_count() === 1);
$m = messages($firstTicket);
check('repeat_rewrites_line_not_appends', count($m) === 1);
check('repeat_line_shows_count_two', str_contains($m[0]['body'], 'occurrences: 2'));
$row = report_row('westy:safeharbor:fail:%');
check('repeat_row_counts_two', (int)($row['occurrences'] ?? 0) === 2);
check('repeat_row_keeps_generation_one', (int)($row['generation'] ?? 0) === 1);

/* A genuinely different error is a different problem. */
$r3 = westy_report_record(fail_payload(['failure' => ['error_detail' => 'Could not reach the AI provider (connection timed out).']]));
check('different_error_opens_second_ticket', $r3['action'] === 'created' && (int)$r3['ticket'] !== $firstTicket);
check('different_error_now_two_tickets', ticket_count() === 2);

/* ── 3. thresholds: a line at 5, a line + priority bump at 25 ─────────────── */
for ($i = 3; $i <= 5; $i++) {
    westy_report_record(fail_payload());
}
$m = messages($firstTicket);
check('threshold_five_appends_one_line', count($m) === 2);
check('threshold_five_line_text', str_contains($m[1]['body'], '5 occurrences'));
check('threshold_five_no_priority_bump', (ticket($firstTicket)['priority'] ?? '') === 'normal');

for ($i = 6; $i <= 25; $i++) {
    westy_report_record(fail_payload());
}
$m = messages($firstTicket);
check('threshold_twentyfive_appends_second_line', count($m) === 3);
check('threshold_twentyfive_bumps_priority', (ticket($firstTicket)['priority'] ?? '') === 'high');
check('threshold_line_count_stays_bounded', count($m) === 3 && str_contains($m[0]['body'], 'occurrences: 25'));

westy_report_record(fail_payload());
check('non_threshold_adds_no_line', count(messages($firstTicket)) === 3);

/* ── 4. a resolved problem coming back opens generation 2, linked ─────────── */
db()->prepare('UPDATE tickets SET status = "resolved", resolved_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$firstTicket]);
$r4 = westy_report_record(fail_payload());
check('return_after_resolve_opens_new_ticket', $r4['action'] === 'created' && (int)$r4['ticket'] !== $firstTicket);
check('return_never_reopens_closed_ticket', (ticket($firstTicket)['status'] ?? '') === 'resolved');
$gen2 = ticket((int)$r4['ticket']);
check('return_key_has_generation_suffix', str_ends_with((string)($gen2['external_key'] ?? ''), ':g2'));
check('return_key_within_column', mb_strlen((string)($gen2['external_key'] ?? '')) <= 64);
$m = messages((int)$r4['ticket']);
check('return_links_back_to_previous', str_contains($m[0]['body'], '#' . $firstTicket));
check('return_counter_restarts', str_contains($m[1]['body'], 'occurrences: 1'));
$row = report_row('westy:safeharbor:fail:%:g2');
check('return_row_is_generation_two', (int)($row['generation'] ?? 0) === 2);

/* ── 5. refusals report nothing ──────────────────────────────────────────── */
fresh_schema(true);
westy_report_failure(['ok' => false, 'refusal' => true, 'error' => 'The assistant declined this request.'], 'westy_chat', 3);
check('refusal_creates_no_ticket', ticket_count() === 0);
westy_report_failure(['ok' => false, 'error' => 'AI provider error: overloaded'], 'westy_chat', 3);
check('real_failure_still_reports', ticket_count() === 1);

/* in-process helper must swallow its own faults, never raise into the chat */
$threw = false;
try {
    westy_report_failure(['ok' => false, 'error' => str_repeat('z', 5000)], 'westy_chat', 3);
} catch (Throwable $e) {
    $threw = true;
}
check('in_process_helper_never_throws', $threw === false);

/* ── 6/7. thumbs-down: creates a flag ticket, two techs make one ticket ───── */
fresh_schema(true);
$f1 = westy_report_record(flag_payload());
check('flag_creates_ticket', $f1['ok'] === true && $f1['action'] === 'created');
$t = ticket((int)$f1['ticket']);
check('flag_priority_low', ($t['priority'] ?? '') === 'low');
check('flag_subject_names_flagged', str_starts_with((string)($t['subject'] ?? ''), 'Westy answer flagged — '));
check('flag_key_is_flag_kind', str_contains((string)($t['external_key'] ?? ''), ':flag:'));
$body = messages((int)$f1['ticket'])[0]['body'];
check('flag_carries_question', str_contains($body, 'How do I merge two tickets?'));
check('flag_carries_answer', str_contains($body, 'Open the Tools menu and choose Combine.'));
check('flag_carries_note', str_contains($body, 'There is no Tools menu.'));

/* Same question, different wording and a different tech → one ticket. */
$f2 = westy_report_record(flag_payload([
    'user_ref' => '2',
    'flagged'  => ['question' => 'how do i MERGE two tickets', 'answer' => 'Totally different answer.', 'note' => ''],
]));
check('same_question_one_ticket', $f2['action'] === 'updated' && (int)$f2['ticket'] === (int)$f1['ticket']);
check('same_question_no_second_ticket', ticket_count() === 1);
$body = messages((int)$f1['ticket'])[0]['body'];
check('flag_keeps_first_example', str_contains($body, 'Open the Tools menu and choose Combine.'));
check('flag_updates_count', str_contains($body, 'occurrences: 2'));

/* The first example must survive MORE than one repeat — occurrence 2 must not
 * become the stored example that occurrence 3 then restores. */
westy_report_record(flag_payload([
    'flagged' => ['question' => 'How do I merge TWO tickets???', 'answer' => 'Third answer entirely.', 'note' => ''],
]));
$body = messages((int)$f1['ticket'])[0]['body'];
check('flag_keeps_first_example_after_three', str_contains($body, 'Open the Tools menu and choose Combine.'));
check('flag_never_shows_later_example', !str_contains($body, 'Third answer entirely.'));
check('flag_counts_three', str_contains($body, 'occurrences: 3'));

/* Losing a race on a brand-new problem counts the occurrence, never 500s.
 * Simulated by inserting the winner's ticket + report row behind our back,
 * exactly as a concurrent process would. */
$racedQuestion = 'Why did the SLA lamp turn red?';
$racedFp = westy_fingerprint(['event' => 'flagged', 'app' => 'safeharbor', 'flagged' => ['question' => $racedQuestion]]);
db()->prepare(
    'INSERT INTO tickets (tenant_id, client_id, subject, priority, channel, external_key, sla_due_at)
     VALUES (1, ?, "raced", "low", "alert", ?, UTC_TIMESTAMP())'
)->execute([westy_report_client_id(1, 'safeharbor'), $racedFp]);
$racedTicket = (int)db()->lastInsertId();
db()->prepare(
    'INSERT INTO westy_reports (tenant_id, fingerprint, external_key, app, kind, generation, ticket_id, occurrences)
     VALUES (1, ?, ?, "safeharbor", "flag", 1, ?, 1)'
)->execute([$racedFp, $racedFp, $racedTicket]);
$before = ticket_count();
$raced = westy_report_record(flag_payload(['flagged' => ['question' => $racedQuestion, 'answer' => 'Some answer.', 'note' => '']]));
check('race_loser_updates_winner', $raced['ok'] === true && $raced['action'] === 'updated' && (int)$raced['ticket'] === $racedTicket);
check('race_loser_creates_no_duplicate', ticket_count() === $before);

/* A different question is a different ticket. */
$before = ticket_count();
$f3 = westy_report_record(flag_payload(['flagged' => ['question' => 'How do I start the timer?', 'answer' => 'Press q.', 'note' => '']]));
check('different_question_new_ticket', $f3['action'] === 'created' && (int)$f3['ticket'] !== (int)$f1['ticket']);
check('different_question_adds_exactly_one', ticket_count() === $before + 1);

/* ── 8. a failure event carrying chat text stores none of it ─────────────── */
fresh_schema(true);
$r = westy_report_record(fail_payload([
    'flagged' => ['question' => 'SECRET PAYROLL QUESTION', 'answer' => 'SECRET ANSWER', 'note' => 'SECRET NOTE'],
]));
$body = messages((int)$r['ticket'])[0]['body'];
check('failure_ignores_flagged_block', !str_contains($body, 'SECRET'));
$row = report_row('westy:safeharbor:fail:%');
check('failure_row_stores_no_chat_text', !str_contains((string)($row['detail_json'] ?? ''), 'SECRET'));

/* ── validation ──────────────────────────────────────────────────────────── */
check('rejects_unknown_event', (westy_report_record(['event' => 'nope', 'app' => 'safeharbor'])['error'] ?? '') === 'unknown event');
check('rejects_unknown_app', (westy_report_record(fail_payload(['app' => 'evilcorp']))['error'] ?? '') === 'unknown app');
check('rejects_stale_occurred_at', str_contains((string)(westy_report_record(fail_payload(['occurred_at' => gmdate('c', time() - 90000)]))['error'] ?? ''), 'occurred_at'));
check('rejects_future_occurred_at', str_contains((string)(westy_report_record(fail_payload(['occurred_at' => gmdate('c', time() + 90000)]))['error'] ?? ''), 'occurred_at'));
check('rejects_failure_without_error', str_contains((string)(westy_report_record(fail_payload(['failure' => ['error_class' => '', 'error_detail' => '']]))['error'] ?? ''), 'error_class'));
check('rejects_flag_without_answer', str_contains((string)(westy_report_record(flag_payload(['flagged' => ['question' => 'q', 'answer' => '']]))['error'] ?? ''), 'question and answer'));

/* other apps are accepted and routed to their own client */
$mp = westy_report_record(fail_payload(['app' => 'milepost']));
check('milepost_app_accepted', $mp['ok'] === true && $mp['action'] === 'created');
check('milepost_gets_own_client', client_name((int)ticket((int)$mp['ticket'])['client_id']) === 'Westy — Milepost');
$cp = westy_report_record(fail_payload(['app' => 'controlpanel']));
check('controlpanel_gets_own_client', client_name((int)ticket((int)$cp['ticket'])['client_id']) === 'Westy — Control Panel');
check('same_error_different_app_is_separate', (int)$mp['ticket'] !== (int)$cp['ticket']);

/* surface is part of the fingerprint */
$s1 = westy_report_record(fail_payload(['app' => 'milepost', 'surface' => 'westy_drafts']));
check('different_surface_is_separate_problem', $s1['action'] === 'created' && (int)$s1['ticket'] !== (int)$mp['ticket']);

/* ── thumbs UP: a signal, and deliberately never a ticket ─────────────────
   The check that matters most here is the one proving nothing was filed.
   Tickets are for problems; if praise could open one, the button would be a
   way for a kind technician to make somebody work. */
fresh_schema(true);
db()->exec("INSERT INTO users (id, tenant_id, email, password_hash, full_name)
            VALUES (7, 1, 'tech@8westit.com', 'x', 'Ana Tech')");

$goodQ = 'How do I merge two tickets?';
$goodA = 'Open the ticket and press m.';
check('thumbs_up_records', westy_thumbs_up_record(7, $goodQ, $goodA) === true);
check('thumbs_up_creates_no_ticket', ticket_count() === 0);
check('thumbs_up_creates_no_report_row', (int)db()->query('SELECT COUNT(*) FROM westy_reports')->fetchColumn() === 0);
check('thumbs_up_creates_no_client', (int)db()->query('SELECT COUNT(*) FROM clients')->fetchColumn() === 0);
check('thumbs_up_creates_no_message', (int)db()->query('SELECT COUNT(*) FROM messages')->fetchColumn() === 0);

$rows = db()->query("SELECT * FROM assistant_log WHERE action = 'westy_thumbs_up' ORDER BY id")->fetchAll();
check('thumbs_up_writes_one_log_row', count($rows) === 1);
check('thumbs_up_log_names_the_user', (int)($rows[0]['user_id'] ?? 0) === 7);
check('thumbs_up_meta_within_column', mb_strlen((string)($rows[0]['meta'] ?? '')) <= 255);
check('thumbs_up_stores_no_question_text', !str_contains((string)$rows[0]['meta'], 'merge two tickets'));
check('thumbs_up_stores_no_answer_text', !str_contains((string)$rows[0]['meta'], 'press m'));

/* Praise and complaint about the same question must fingerprint identically,
   or the two signals could never be read against each other. */
$fp = westy_fingerprint(['event' => 'flagged', 'app' => 'safeharbor', 'flagged' => ['question' => $goodQ]]);
check('thumbs_up_carries_the_flag_fingerprint', str_contains((string)$rows[0]['meta'], 'fp=' . $fp));

/* And wording differences collapse exactly the way the flag path collapses
   them — the same ask, typed twice, is the same signal. */
westy_thumbs_up_record(7, 'how do i MERGE two tickets???', 'A different good answer.');
$rows = db()->query("SELECT * FROM assistant_log WHERE action = 'westy_thumbs_up' ORDER BY id")->fetchAll();
check('thumbs_up_same_question_same_fingerprint', str_contains((string)($rows[1]['meta'] ?? ''), 'fp=' . $fp));
check('thumbs_up_still_no_ticket_after_two', ticket_count() === 0);

/* A thumbs-DOWN on that same question still opens its ticket as before — the
   new button must not have disarmed the old one. */
$d = westy_report_record(flag_payload(['flagged' => ['question' => $goodQ, 'answer' => $goodA, 'note' => '']]));
check('thumbs_down_still_opens_ticket', $d['action'] === 'created' && ticket_count() === 1);
check('thumbs_down_ticket_shares_the_fingerprint', str_contains((string)(ticket((int)$d['ticket'])['external_key'] ?? ''), $fp));

/* The longest pair the endpoint will pass through still fits the column. */
westy_thumbs_up_record(7, str_repeat('q', 2000), str_repeat('a', 4000));
$long = (string)db()->query("SELECT meta FROM assistant_log WHERE action = 'westy_thumbs_up' ORDER BY id DESC LIMIT 1")->fetchColumn();
check('thumbs_up_long_pair_stays_bounded', mb_strlen($long) <= 255);

/* Nothing to record: no row, no fuss. */
$before = (int)db()->query('SELECT COUNT(*) FROM assistant_log')->fetchColumn();
check('thumbs_up_needs_a_question', westy_thumbs_up_record(7, '', $goodA) === false);
check('thumbs_up_needs_an_answer', westy_thumbs_up_record(7, $goodQ, '') === false);
check('thumbs_up_rejects_unknown_app', westy_thumbs_up_record(7, $goodQ, $goodA, 'evilcorp') === false);
check('thumbs_up_rejected_writes_nothing', (int)db()->query('SELECT COUNT(*) FROM assistant_log')->fetchColumn() === $before);

/* A database that refuses the row (here: no such user) must degrade to a
   swallowed false, never raise into a technician's chat. */
$threw = false;
$refused = null;
try {
    $refused = westy_thumbs_up_record(999, 'Will this explode?', 'No.');
} catch (Throwable $e) {
    $threw = true;
}
check('thumbs_up_never_throws_on_db_error', $threw === false);
check('thumbs_up_reports_the_refusal', $refused === false);

echo "\n{$checkCount} checks, {$failCount} failures\n";
exit($failCount === 0 ? 0 : 1);
