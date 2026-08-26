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
        'waypoint-support'  => 'TEST_WAYPOINT_SECRET',
        'milepost'          => 'TEST_SVC_HMAC_SECRET',
    ],
];
$CONFIG['support_intake'] = [
    'tenant_slug'        => '8west',
    'sources' => [
        'coastmark-support' => ['source' => 'coastmark', 'label' => 'Coastmark'],
        'waypoint-support'  => ['source' => 'waypoint',  'label' => 'Waypoint'],
    ],
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
            if (preg_match('/\b(?:DROP|CREATE)\s+TRIGGER\b/i', $stmt)) continue;
            if (trim($stmt) !== '') {
                $pdo->exec($stmt);
            }
        }
    }
    if ($withTenant) {
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, '8 West IT, LLC', '8west')");
    } else {
        // Tenant 1 exists but is SOMEBODY ELSE. Two reasons this row is here:
        // svc_identities has a foreign key to tenants, so the identities below
        // cannot be inserted without it — and more importantly this is the
        // dangerous shape the slug lookup exists to defeat. tenant_id() falls
        // back to 1 when there is no session, so a handler resolving by that
        // fallback would drop a partner's support request straight into this
        // customer's queue. Resolving by slug must find nothing instead.
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Someone Else Ltd', 'someone-else')");
    }
    // A customer tenant that must NEVER receive a partner's support request.
    $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (9, 'Acme Dental', 'acme')");
    // Deliberately NOT waypoint-support: §"Waypoint is a SECOND producer"
    // registers it itself, so that section proves registration works rather
    // than inheriting it from the fixture. Adding it here is a duplicate key.
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

function goal_for_ticket(int $ticketId): ?array
{
    $q = db()->prepare(
        'SELECT policy.policy_key, policy.version_no, target.priority,
                target.first_response_minutes
           FROM tickets ticket
           JOIN service_goal_policy_targets target
             ON target.tenant_id = ticket.tenant_id
            AND target.id = ticket.service_goal_target_id
           JOIN service_goal_policy_versions policy
             ON policy.tenant_id = ticket.tenant_id
            AND policy.id = target.policy_version_id
          WHERE ticket.id = ?'
    );
    $q->execute([$ticketId]);
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
$initialTargetId = (int) ($t['service_goal_target_id'] ?? 0);
$initialDueAt = (string) ($t['sla_due_at'] ?? '');
$goal = goal_for_ticket($tid);
check('ticket_captures_standard_v1_normal_target',
    $initialTargetId > 0
    && ($goal['policy_key'] ?? '') === 'standard'
    && (int) ($goal['version_no'] ?? 0) === 1
    && ($goal['priority'] ?? '') === 'normal'
    && (int) ($goal['first_response_minutes'] ?? 0) === 480);

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
$retriedOriginal = ticket($tid);
check('retry_preserves_the_original_service_goal',
    (int) ($retriedOriginal['service_goal_target_id'] ?? 0) === $initialTargetId
    && ($retriedOriginal['sla_due_at'] ?? '') === $initialDueAt);

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

/* ── 10. more than one app ───────────────────────────────────────────────
   Every 8 West app is meant to raise support through Safeharbor, so the
   caller list is config, not code, and two apps must not tread on each
   other even when their tenants share a slug. */

// Coastmark dropped the slug out of the key (2026-08-09): the slug travels in
// tenant.slug and the reference is just a prefix and a uuid. Both shapes work.
$uuidKey = support_record(req(['external_key' => 'cmk:7f3a9c1d4b0e2a68']), 'coastmark-support');
check('prefix_and_uuid_key_accepted', $uuidKey['ok'] === true && $uuidKey['action'] === 'created');

$wp = support_record(req([
    'external_key' => 'wyp:1c4e8b2f9d0a3e57',
    'tenant'       => ['slug' => 'acme-msp', 'display_name' => 'Acme MSP'],
]), 'waypoint-support');
check('waypoint_may_file', $wp['ok'] === true && $wp['action'] === 'created');
$wpClient = client_row((int)ticket((int)$wp['ticket'])['client_id']);
check('waypoint_client_keyed_separately', ($wpClient['source_key'] ?? '') === 'waypoint:acme-msp');
check('waypoint_client_labelled_waypoint', ($wpClient['name'] ?? '') === 'Acme MSP (Waypoint)');
check('same_slug_two_apps_two_client_rows', (int)$wpClient['id'] !== $clientId);
check('waypoint_provenance_names_waypoint',
    str_contains(messages((int)$wp['ticket'])[1]['body'], 'through Waypoint'));

// The list is config-driven: an app named only there is let in, and one
// removed from it is refused even though its secret still exists.
$CONFIG['support_intake']['sources']['ledger-support'] = ['source' => 'ledger', 'label' => 'Ledger'];
check('config_can_add_an_app', support_source_for('ledger-support') !== null);
unset($CONFIG['support_intake']['sources']['ledger-support']);
check('config_can_remove_an_app', support_source_for('ledger-support') === null);
$CONFIG['support_intake']['sources']['broken-support'] = ['source' => 'NOT A SOURCE', 'label' => 'Broken'];
check('malformed_config_entry_skipped', support_source_for('broken-support') === null);
check('malformed_entry_leaves_others_working', support_source_for('coastmark-support') !== null);
unset($CONFIG['support_intake']['sources']['broken-support']);

// A slug so long that '{prefix}:{slug}' would not fit the routing column is
// refused outright — truncating it would merge two firms onto one client row.
// Today's prefixes are short enough that no legal slug can trip this, so the
// guard is exercised with a deliberately long one: it exists for the app we
// have not registered yet, which is the only way it could ever bite.
$CONFIG['support_intake']['sources']['longprefix-support'] =
    ['source' => str_repeat('a', 24), 'label' => 'Long Prefix'];
$tooLong = support_record(req([
    'external_key' => 'lng:aaaa1111bbbb2222',
    'tenant'       => ['slug' => str_repeat('x', 48), 'display_name' => 'Long Slug Ltd'],
]), 'longprefix-support');
check('overlong_routing_key_refused', $tooLong['ok'] === false && !isset($tooLong['code']));
check('overlong_routing_key_created_nothing', support_ticket_by_key(1, 'lng:aaaa1111bbbb2222') === null);

// The same app with a slug that DOES fit is fine — the guard is about length,
// not about the app.
$fits = support_record(req([
    'external_key' => 'lng:bbbb2222cccc3333',
    'tenant'       => ['slug' => str_repeat('x', 39), 'display_name' => 'Just Fits Ltd'],
]), 'longprefix-support');
check('routing_key_at_the_limit_accepted', $fits['ok'] === true && $fits['action'] === 'created');
unset($CONFIG['support_intake']['sources']['longprefix-support']);

// ── what the config falls back to ────────────────────────────────────────
// This is not academic: PRODUCTION HAS NO 'support_intake' BLOCK AT ALL. It
// runs on the built-in defaults, so if an absent key stopped meaning
// "defaults", deploying this would take Coastmark and Waypoint off the air.
$savedSources = $CONFIG['support_intake']['sources'];
unset($CONFIG['support_intake']['sources']);
check('absent_config_falls_back_to_defaults', support_source_for('coastmark-support') !== null);
check('absent_config_keeps_waypoint_too', support_source_for('waypoint-support') !== null);
check('absent_config_still_refuses_the_alert_identity', support_source_for('milepost') === null);

$savedBlock = $CONFIG['support_intake'];
unset($CONFIG['support_intake']);
check('no_support_intake_block_at_all_still_works', support_source_for('coastmark-support') !== null);
$CONFIG['support_intake'] = $savedBlock;

// An EMPTY list is obeyed rather than treated as absent. "I listed nobody"
// has to mean nobody, or the setting would be lying to whoever set it.
$CONFIG['support_intake']['sources'] = [];
check('empty_list_means_nobody_not_defaults', support_source_for('coastmark-support') === null);
$refusedByEmptyList = support_record(req(['external_key' => 'cmk:acme-msp:9001']), 'coastmark-support');
check('empty_list_refuses_a_real_request', $refusedByEmptyList['ok'] === false);
check('empty_list_files_nothing', support_ticket_by_key(1, 'cmk:acme-msp:9001') === null);

$CONFIG['support_intake']['sources'] = $savedSources;
check('restoring_the_list_lets_them_back_in', support_source_for('coastmark-support') !== null);

/* ── 11. per-tenant rate cap ─────────────────────────────────────────────── */
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

// Nor may one app's flood block another's, even for the same firm.
$wpDuring = support_record(req([
    'external_key' => 'wyp:5a6b7c8d9e0f1234',
    'tenant'       => ['slug' => 'acme-msp', 'display_name' => 'Acme MSP'],
]), 'waypoint-support');
check('other_app_unaffected_by_the_cap', $wpDuring['ok'] === true && $wpDuring['action'] === 'created');

// A retry of a stored submission must not spend the budget it already spent.
db()->prepare('DELETE FROM svc_support_rate')->execute();
db()->prepare('INSERT INTO svc_support_rate (source, tenant_slug, window_kind, bucket, hits) VALUES (?,?,?,?,?)')
    ->execute(['coastmark', 'acme-msp', 'minute', (int)floor(time() / 60), 20]);
$replay = support_record(req(['external_key' => 'cmk:acme-msp:0001']), 'coastmark-support');
check('retry_ignored_even_when_over_rate', $replay['ok'] === true && $replay['action'] === 'ignored');

/* ── Waypoint: a SECOND producer, not a variant of the first ───────────────
 * Waypoint is a standalone product outside 8 West IT 365 with its own
 * customers. It must be accepted on its own identity, routed to its own
 * client rows, and must not be able to act as Coastmark (or vice versa).
 * These are the checks that would have caught it 401-ing on day one. */
fresh_schema(true);
$CONFIG['svc']['secrets']['waypoint-support'] = 'TEST_WAYPOINT_SECRET';
db()->exec("INSERT INTO svc_identities (tenant_id, service, display_name) VALUES (1, 'waypoint-support', 'Waypoint Support')");

check('waypoint_is_a_known_source', support_source_for('waypoint-support') !== null);
check('waypoint_auth_valid_signature', auth_for(req(), 'waypoint-support', null, 'TEST_WAYPOINT_SECRET')['ok'] === true);

$w = support_record(req([
    'external_key' => 'wyp:9f2c4e11',
    'tenant'       => ['slug' => 'harbor-co', 'display_name' => 'Harbor Co'],
    'requester'    => ['name' => 'Ines Vega', 'email' => 'ines@harborco.example'],
    'subject'      => 'Cannot export the run sheet',
    'body'         => 'Export button spins forever on the run sheet page.',
]), 'waypoint-support');
check('waypoint_request_creates_ticket', $w['ok'] === true && $w['action'] === 'created' && !empty($w['ticket']));
$wt = ticket((int)$w['ticket']);
check('waypoint_ticket_lands_in_8west_tenant', (int)($wt['tenant_id'] ?? 0) === 1);
check('waypoint_ticket_keeps_its_external_key', ($wt['external_key'] ?? '') === 'wyp:9f2c4e11');
$waypointGoal = goal_for_ticket((int) $w['ticket']);
check('waypoint_ticket_captures_standard_v1',
    ($waypointGoal['policy_key'] ?? '') === 'standard'
    && (int) ($waypointGoal['version_no'] ?? 0) === 1);
$wc = client_row((int)$wt['client_id']);
check('waypoint_client_keyed_on_waypoint_source', ($wc['source_key'] ?? '') === 'waypoint:harbor-co');
check('waypoint_client_labelled_waypoint', ($wc['name'] ?? '') === 'Harbor Co (Waypoint)');
check('waypoint_provenance_names_waypoint', str_contains(messages((int)$w['ticket'])[1]['body'], 'through Waypoint'));

/* The same customer slug in both products must NOT share a client row —
 * otherwise one product's tickets would silently file under the other. */
$c2 = support_record(req([
    'external_key' => 'cmk:harbor-co:0001',
    'tenant'       => ['slug' => 'harbor-co', 'display_name' => 'Harbor Co'],
]), 'coastmark-support');
check('same_slug_other_product_is_a_separate_client',
    (int)ticket((int)$c2['ticket'])['client_id'] !== (int)$wt['client_id']);
check('coastmark_client_keyed_on_coastmark_source',
    (client_row((int)ticket((int)$c2['ticket'])['client_id'])['source_key'] ?? '') === 'coastmark:harbor-co');

/* Neither product may sign for the other, and the allow-list still refuses
 * everyone else. A shared key would have made these indistinguishable. */
check('waypoint_secret_cannot_sign_as_coastmark',
    auth_for(req(), 'coastmark-support', null, 'TEST_WAYPOINT_SECRET')['code'] === 401);
check('coastmark_secret_cannot_sign_as_waypoint',
    auth_for(req(), 'waypoint-support', null, 'TEST_SUPPORT_SECRET')['code'] === 401);
check('alert_identity_still_refused_for_support', support_source_for('milepost') === null);
check('unknown_identity_still_refused', support_source_for('nosuchsvc-support') === null);
$bad = support_record(req(['external_key' => 'wyp:deadbeef']), 'nosuchsvc-support');
check('unknown_identity_files_nothing', $bad['ok'] === false && ($bad['code'] ?? 0) === 401);

/* Per-tenant rate budget is per PRODUCT, so a noisy Coastmark tenant cannot
 * throttle the same customer's Waypoint requests. */
db()->prepare('DELETE FROM svc_support_rate')->execute();
db()->prepare('INSERT INTO svc_support_rate (source, tenant_slug, window_kind, bucket, hits) VALUES (?,?,?,?,?)')
    ->execute(['coastmark', 'harbor-co', 'minute', (int)floor(time() / 60), 20]);
$stillOk = support_record(req([
    'external_key' => 'wyp:aa11bb22',
    'tenant'       => ['slug' => 'harbor-co', 'display_name' => 'Harbor Co'],
]), 'waypoint-support');
check('waypoint_budget_separate_from_coastmark', $stillOk['ok'] === true && $stillOk['action'] === 'created');

echo "---\n{$checkCount} checks, {$failCount} failures\n";
exit($failCount > 0 ? 1 : 0);
