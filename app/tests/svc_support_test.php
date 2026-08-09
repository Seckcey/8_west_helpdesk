<?php
/**
 * Hermetic tests for the Coastmark support intake (api/svc/support.php).
 *
 * CLI only. Runs against a dedicated scratch database — NEVER the live one:
 *   CREATE DATABASE safeharbor_test;
 *   GRANT ALL PRIVILEGES ON safeharbor_test.* TO 'safeharbor'@'localhost';
 *
 * Run:  php app/tests/svc_support_test.php
 * Exit: 0 = all green, 1 = failures.
 *
 * The tests that matter most are the ones proving this is NOT westy.php:
 * text survives verbatim, and two requests never collapse into one ticket.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../lib/svc_auth.php';
require_once __DIR__ . '/../lib/svc_support.php';

// Point the shared connection + config at the scratch database BEFORE the
// first db() call, and give svc a self-contained test config.
$CONFIG['db']['name'] = 'safeharbor_test';
$CONFIG['svc'] = [
    'enabled'         => true,
    'support_enabled' => true,
    'secrets'         => [
        'coastmark-support' => 'TEST_SUPPORT_SECRET',
        'milepost'          => 'TEST_SVC_HMAC_SECRET',
    ],
];
$CONFIG['support_intake'] = [
    'tenant_slug'        => '8west',
    'per_tenant_per_min' => 20,
    'per_tenant_per_day' => 100,
    'ack_email'          => true,
];

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
    // schema.sql carries no svc_* objects (see AGENTS.md) — 002 and 009 do.
    foreach ([
        'schema.sql',
        'migrations/001_mail_queue.sql',
        'migrations/002_svc_intake.sql',
        'migrations/009_support_intake.sql',
    ] as $f) {
        $sql = (string)file_get_contents(__DIR__ . '/../db/' . $f);
        foreach (explode(";\n", $sql) as $stmt) {
            if (trim($stmt) !== '') {
                $pdo->exec($stmt);
            }
        }
    }
    // A customer tenant that must NEVER receive a partner's support request.
    $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (9, 'Acme Dental', 'acme')");
    if (!$withTenant) {
        return;   // no 8 West row, and no identities to hang off it
    }
    $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, '8 West IT, LLC', '8west')");
    $pdo->exec("INSERT INTO svc_identities (tenant_id, service, display_name) VALUES (1, 'coastmark-support', 'Coastmark Support')");
    $pdo->exec("INSERT INTO svc_identities (tenant_id, service, display_name) VALUES (1, 'milepost', 'Milepost RMM')");
}

function req(array $over = []): array
{
    return array_replace_recursive([
        'event'        => 'support_request',
        'external_key' => 'cmk:acme-msp:0001',
        'occurred_at'  => gmdate('c'),
        'tenant'       => ['slug' => 'acme-msp', 'display_name' => 'Acme MSP'],
        'requester'    => ['name' => 'Ada Lovelace', 'email' => 'ada@acmemsp.example'],
        'subject'      => 'Invoice sync stopped overnight',
        'body'         => "Our sync to the ledger stopped at 2am.\n\nThe last invoice through was #4471.",
        'context'      => ['page' => 'Billing → Invoices', 'app_version' => '3.4.1'],
    ], $over);
}

function auth_for(array $p, string $service = 'coastmark-support', ?int $ts = null, string $secret = 'TEST_SUPPORT_SECRET'): array
{
    $ts = $ts ?? time();
    $body = (string)json_encode($p);
    $sig = hash_hmac('sha256', $ts . "\n" . $body, $secret);
    return svc_authenticate($service, (string)$ts, $sig, $body);
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

function ticket_count(): int
{
    return (int)db()->query('SELECT COUNT(*) FROM tickets')->fetchColumn();
}

function client_row(int $id): array
{
    $q = db()->prepare('SELECT * FROM clients WHERE id = ?');
    $q->execute([$id]);
    return $q->fetch() ?: [];
}

function queued_mail(int $ticketId): array
{
    $q = db()->prepare('SELECT * FROM mail_queue WHERE ticket_id = ? ORDER BY id');
    $q->execute([$ticketId]);
    return $q->fetchAll();
}

/* ── 1. destination missing: record nothing, never throw ──────────────────
   Runs FIRST, while no '8west' tenant exists, so the fail-closed path is
   exercised for real rather than simulated. */
fresh_schema(false);
$r = support_record(req(), 'coastmark-support');
check('missing_tenant_is_ignored_not_thrown', $r['ok'] === true && $r['action'] === 'ignored' && $r['ticket'] === null);
check('missing_tenant_creates_no_ticket', ticket_count() === 0);

/* ── from here on the destination exists ─────────────────────────────────── */
fresh_schema(true);

/* ── 2. kill switch ──────────────────────────────────────────────────────── */
$CONFIG['svc']['support_enabled'] = false;
check('support_off_when_own_flag_off', support_enabled() === false);
$CONFIG['svc']['support_enabled'] = true;
$CONFIG['svc']['enabled'] = false;
check('support_off_when_shared_flag_off', support_enabled() === false);
$CONFIG['svc']['enabled'] = true;
check('support_on_when_both_flags_on', support_enabled() === true);

/* ── 3. authentication and the job check ─────────────────────────────────── */
check('auth_valid_signature', auth_for(req())['ok'] === true);
check('auth_rejects_bad_signature', svc_authenticate('coastmark-support', (string)time(), str_repeat('0', 64), '{}')['code'] === 401);
check('auth_rejects_stale_timestamp', auth_for(req(), 'coastmark-support', time() - 400)['code'] === 401);
check('auth_rejects_unknown_service', auth_for(req(), 'nosuchsvc')['code'] === 401);
check('support_source_known_for_coastmark', support_source_for('coastmark-support') !== null);
check('support_source_refuses_alert_identity', support_source_for('milepost') === null);
$r = support_record(req(), 'milepost');
check('alert_identity_cannot_file_support', $r['ok'] === false && ($r['code'] ?? 0) === 401);
check('refused_identity_created_no_ticket', ticket_count() === 0);

/* ── 4. one request → one ticket, in 8 West's own queue ──────────────────── */
$r = support_record(req(), 'coastmark-support');
check('request_creates_ticket', $r['ok'] === true && $r['action'] === 'created' && !empty($r['ticket']));
$tid = (int)$r['ticket'];
$t = ticket($tid);
check('ticket_lands_in_8west_tenant', (int)($t['tenant_id'] ?? 0) === 1);
check('ticket_channel_is_portal', ($t['channel'] ?? '') === 'portal');
check('ticket_priority_is_normal', ($t['priority'] ?? '') === 'normal');
check('ticket_status_open', ($t['status'] ?? '') === 'open');
check('ticket_external_key_stored', ($t['external_key'] ?? '') === 'cmk:acme-msp:0001');
check('ticket_subject_verbatim', ($t['subject'] ?? '') === 'Invoice sync stopped overnight');
$slaOk = $t && abs(strtotime($t['sla_due_at'] . ' UTC') - time() - 8 * 3600) < 300;
check('ticket_sla_standard_eight_hours', $slaOk);

/* ── 5. the client row, keyed on the slug ────────────────────────────────── */
$clientId = (int)$t['client_id'];
$c = client_row($clientId);
check('client_row_keyed_on_source_key', ($c['source_key'] ?? '') === 'coastmark:acme-msp');
check('client_row_named_for_the_msp', ($c['name'] ?? '') === 'Acme MSP (Coastmark)');
check('client_row_not_given_a_mail_domain', ($c['domain'] ?? 'x') === '');

$r2 = support_record(req(['external_key' => 'cmk:acme-msp:0002']), 'coastmark-support');
check('second_request_same_tenant_reuses_client', (int)ticket((int)$r2['ticket'])['client_id'] === $clientId);
$q = db()->query("SELECT COUNT(*) FROM clients WHERE source_key = 'coastmark:acme-msp'");
check('client_row_created_exactly_once', (int)$q->fetchColumn() === 1);

// A tech renames the client row. Routing follows the key, not the name.
db()->prepare('UPDATE clients SET name = ? WHERE id = ?')->execute(['Acme MSP (partner)', $clientId]);
$r3 = support_record(req(['external_key' => 'cmk:acme-msp:0003']), 'coastmark-support');
check('rename_does_not_fork_the_queue', (int)ticket((int)$r3['ticket'])['client_id'] === $clientId);
check('rename_survives_a_later_request', client_row($clientId)['name'] === 'Acme MSP (partner)');

// A different Coastmark tenant is a different client row.
$r4 = support_record(req([
    'external_key' => 'cmk:beta-it:0001',
    'tenant'       => ['slug' => 'beta-it', 'display_name' => 'Beta IT'],
]), 'coastmark-support');
check('other_tenant_gets_its_own_client', (int)ticket((int)$r4['ticket'])['client_id'] !== $clientId);

/* ── 6. the requester's words, stored verbatim ───────────────────────────── */
$m = messages($tid);
check('two_messages_request_then_provenance', count($m) === 2);
check('request_is_a_client_message', ($m[0]['kind'] ?? '') === 'client');
check('request_authored_by_the_requester', ($m[0]['author_name'] ?? '') === 'Ada Lovelace');
check('body_stored_verbatim_with_newlines',
    ($m[0]['body'] ?? '') === "Our sync to the ledger stopped at 2am.\n\nThe last invoice through was #4471.");
check('provenance_is_a_system_line', ($m[1]['kind'] ?? '') === 'system');
check('provenance_names_the_source', str_contains($m[1]['body'] ?? '', 'Coastmark'));
check('provenance_carries_reply_to', str_contains($m[1]['body'] ?? '', 'ada@acmemsp.example'));
check('provenance_carries_page', str_contains($m[1]['body'] ?? '', 'Billing → Invoices'));
check('provenance_carries_reference', str_contains($m[1]['body'] ?? '', 'cmk:acme-msp:0001'));

// The Westy scrubber would have destroyed all three of these. Prove it did not.
$hostile = support_record(req([
    'external_key' => 'cmk:acme-msp:0010',
    'subject'      => 'Bounced mail from billing',
    'body'         => 'Our client wrote "the invoice total is wrong and the tax line is missing entirely" '
                    . 'and their address is jo@clientco.example — can you look?',
]), 'coastmark-support');
$hm = messages((int)$hostile['ticket']);
check('email_address_in_body_survives', str_contains($hm[0]['body'], 'jo@clientco.example'));
check('long_quoted_span_survives', str_contains($hm[0]['body'], '"the invoice total is wrong and the tax line is missing entirely"'));

/* ── 7. no dedupe — the whole point ──────────────────────────────────────── */
$before = ticket_count();
$sameWords = ['subject' => 'Invoice sync stopped overnight', 'body' => 'Our sync to the ledger stopped at 2am.'];
support_record(req($sameWords + ['external_key' => 'cmk:acme-msp:0021']), 'coastmark-support');
support_record(req($sameWords + ['external_key' => 'cmk:acme-msp:0022']), 'coastmark-support');
check('identical_text_makes_two_tickets', ticket_count() === $before + 2);

// A retried delivery of ONE submission does not.
$again = support_record(req(['external_key' => 'cmk:acme-msp:0021'] + $sameWords), 'coastmark-support');
check('retry_of_same_key_is_ignored', $again['ok'] === true && $again['action'] === 'ignored');
check('retry_returns_the_original_ticket', !empty($again['ticket']));
check('retry_creates_no_second_ticket', ticket_count() === $before + 2);

/* ── 8. the reply path ───────────────────────────────────────────────────── */
$contactId = (int)(ticket($tid)['contact_id'] ?? 0);
check('ticket_has_a_contact', $contactId > 0);
$kq = db()->prepare('SELECT * FROM contacts WHERE id = ?');
$kq->execute([$contactId]);
$contact = $kq->fetch() ?: [];
check('contact_carries_reply_to_address', ($contact['email'] ?? '') === 'ada@acmemsp.example');
check('contact_sits_under_the_msp_client', (int)($contact['client_id'] ?? 0) === $clientId);
$kq = db()->prepare('SELECT COUNT(*) FROM contacts WHERE client_id = ? AND email = ?');
$kq->execute([$clientId, 'ada@acmemsp.example']);
check('contact_reused_not_duplicated', (int)$kq->fetchColumn() === 1);

$mail = queued_mail($tid);
check('acknowledgement_queued', count($mail) === 1);
check('acknowledgement_goes_to_requester', ($mail[0]['to_addr'] ?? '') === 'ada@acmemsp.example');
check('acknowledgement_carries_thread_token', str_starts_with((string)($mail[0]['subject'] ?? ''), '[#' . $tid . '] '));

// Never write to a machine address (mail-loop protection is law).
$auto = support_record(req([
    'external_key' => 'cmk:acme-msp:0030',
    'requester'    => ['name' => 'Robot', 'email' => 'no-reply@acmemsp.example'],
]), 'coastmark-support');
check('auto_mail_sender_still_gets_a_ticket', $auto['action'] === 'created');
check('auto_mail_sender_gets_no_acknowledgement', count(queued_mail((int)$auto['ticket'])) === 0);

/* ── 9. validation ───────────────────────────────────────────────────────── */
check('bad_event_rejected', support_record(req(['event' => 'exploded']), 'coastmark-support')['ok'] === false);
check('missing_external_key_rejected', support_record(req(['external_key' => '']), 'coastmark-support')['ok'] === false);
check('oversized_external_key_rejected', support_record(req(['external_key' => str_repeat('k', 65)]), 'coastmark-support')['ok'] === false);
check('bad_slug_rejected', support_record(req(['external_key' => 'cmk:x:1', 'tenant' => ['slug' => 'Not A Slug']]), 'coastmark-support')['ok'] === false);
check('bad_email_rejected', support_record(req(['external_key' => 'cmk:x:2', 'requester' => ['email' => 'nope']]), 'coastmark-support')['ok'] === false);
check('missing_subject_rejected', support_record(req(['external_key' => 'cmk:x:3', 'subject' => '   ']), 'coastmark-support')['ok'] === false);
check('missing_body_rejected', support_record(req(['external_key' => 'cmk:x:4', 'body' => '']), 'coastmark-support')['ok'] === false);
check('ancient_occurred_at_rejected', support_record(req(['external_key' => 'cmk:x:5', 'occurred_at' => gmdate('c', time() - 48 * 3600)]), 'coastmark-support')['ok'] === false);
$beforeBad = ticket_count();
support_record(req(['external_key' => 'cmk:x:6', 'subject' => '']), 'coastmark-support');
check('rejected_payload_creates_nothing', ticket_count() === $beforeBad);

// A newline in the subject would become a mail header injection downstream.
$inj = support_record(req([
    'external_key' => 'cmk:acme-msp:0040',
    'subject'      => "Help please\nBcc: someone@elsewhere.example",
]), 'coastmark-support');
$injSubject = (string)ticket((int)$inj['ticket'])['subject'];
check('subject_newlines_flattened', !str_contains($injSubject, "\n") && str_contains($injSubject, 'Bcc'));

// Caps: long text is cut, not refused.
$long = support_record(req([
    'external_key' => 'cmk:acme-msp:0041',
    'subject'      => str_repeat('s', 400),
    'body'         => str_repeat('b', 9000),
]), 'coastmark-support');
$longTicket = ticket((int)$long['ticket']);
check('subject_capped_at_160', mb_strlen((string)$longTicket['subject']) === SUPPORT_SUBJECT_MAX);
check('body_capped_at_8000', mb_strlen(messages((int)$long['ticket'])[0]['body']) === SUPPORT_BODY_MAX);

// occurred_at is optional; omitting it means now.
$noWhen = req(['external_key' => 'cmk:acme-msp:0042']);
unset($noWhen['occurred_at']);
check('missing_occurred_at_defaults_to_now', support_record($noWhen, 'coastmark-support')['action'] === 'created');

/* ── 10. per-tenant rate cap ─────────────────────────────────────────────── */
db()->prepare('DELETE FROM svc_support_rate')->execute();
db()->prepare('INSERT INTO svc_support_rate (source, tenant_slug, window_kind, bucket, hits) VALUES (?,?,?,?,?)')
    ->execute(['coastmark', 'acme-msp', 'minute', (int)floor(time() / 60), 19]);
$ok20 = support_record(req(['external_key' => 'cmk:acme-msp:0050']), 'coastmark-support');
check('rate_allows_20th_request_this_minute', $ok20['ok'] === true && $ok20['action'] === 'created');
$denied = support_record(req(['external_key' => 'cmk:acme-msp:0051']), 'coastmark-support');
check('rate_denies_21st_request', $denied['ok'] === false && ($denied['code'] ?? 0) === 429);
check('rate_denied_request_creates_nothing', support_ticket_by_key(1, 'cmk:acme-msp:0051') === null);

// One noisy tenant must not lock the others out — that is why the cap is
// per tenant rather than only per service identity.
$other = support_record(req([
    'external_key' => 'cmk:beta-it:0050',
    'tenant'       => ['slug' => 'beta-it', 'display_name' => 'Beta IT'],
]), 'coastmark-support');
check('other_tenant_unaffected_by_the_cap', $other['ok'] === true && $other['action'] === 'created');

// A retry of a stored submission must not spend the budget it already spent.
db()->prepare('DELETE FROM svc_support_rate')->execute();
db()->prepare('INSERT INTO svc_support_rate (source, tenant_slug, window_kind, bucket, hits) VALUES (?,?,?,?,?)')
    ->execute(['coastmark', 'acme-msp', 'minute', (int)floor(time() / 60), 20]);
$replay = support_record(req(['external_key' => 'cmk:acme-msp:0001']), 'coastmark-support');
check('retry_ignored_even_when_over_rate', $replay['ok'] === true && $replay['action'] === 'ignored');

echo "---\n{$checkCount} checks, {$failCount} failures\n";
exit($failCount > 0 ? 1 : 0);
