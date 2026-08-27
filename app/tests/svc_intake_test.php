<?php
/**
 * Hermetic tests for the Phase 8.1 svc alert intake (Path B).
 *
 * CLI only. Runs against a dedicated scratch database — NEVER the live
 * one. The legacy local mode expects this database to exist for the app user:
 *   CREATE DATABASE safeharbor_test;
 *   GRANT ALL PRIVILEGES ON safeharbor_test.* TO 'safeharbor'@'localhost';
 * CI/operator mode requires SAFEHARBOR_SVC_TEST_DISPOSABLE_SERVER=1 and
 * creates then removes a random, allowlisted database on a loopback server.
 *
 * Run:  php app/tests/svc_intake_test.php        (from the app dir)
 * Exit: 0 = all green, 1 = failures.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../lib/svc_auth.php';
require_once __DIR__ . '/../lib/svc_intake.php';

// Point the shared connection + config at the scratch database BEFORE the
// first db() call, and give svc a self-contained test config.
$svcTestServer = null;
$svcTestDatabase = null;
$svcTestCreated = false;
$svcTestBase = getenv('SAFEHARBOR_SVC_TEST_DB');
if (is_string($svcTestBase) && $svcTestBase !== '') {
    if (getenv('SAFEHARBOR_SVC_TEST_DISPOSABLE_SERVER') !== '1'
        || preg_match('/\Asafeharbor_svc_test(?:_[a-z0-9_]+)?\z/D', $svcTestBase) !== 1
        || strlen($svcTestBase) > 44
    ) {
        fwrite(STDERR, "Refusing destructive svc test database base.\n");
        exit(2);
    }
    $svcTestHost = getenv('SAFEHARBOR_SVC_TEST_HOST') ?: '127.0.0.1';
    $svcTestPort = getenv('SAFEHARBOR_SVC_TEST_PORT') ?: '3306';
    if (!in_array($svcTestHost, ['127.0.0.1', 'localhost', '::1'], true)
        || preg_match('/\A[0-9]{1,5}\z/D', (string)$svcTestPort) !== 1
        || (int)$svcTestPort < 1 || (int)$svcTestPort > 65535
    ) {
        fwrite(STDERR, "Refusing non-loopback svc test server.\n");
        exit(2);
    }
    $svcTestUser = getenv('SAFEHARBOR_SVC_TEST_USER') ?: 'root';
    $svcTestPass = getenv('SAFEHARBOR_SVC_TEST_PASS') ?: '';
    $svcTestDatabase = $svcTestBase . '_' . bin2hex(random_bytes(6));
    $svcTestQuotedDatabase = '`' . str_replace('`', '``', $svcTestDatabase) . '`';
    $svcTestOptions = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $svcTestServer = new PDO(
        "mysql:host={$svcTestHost};port={$svcTestPort};charset=utf8mb4",
        $svcTestUser,
        $svcTestPass,
        $svcTestOptions,
    );
    $svcTestServer->exec(
        "CREATE DATABASE {$svcTestQuotedDatabase} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    );
    $svcTestCreated = true;
    register_shutdown_function(static function () use (
        &$svcTestCreated,
        $svcTestServer,
        $svcTestQuotedDatabase,
    ): void {
        if ($svcTestCreated && $svcTestServer instanceof PDO) {
            try {
                $svcTestServer->exec("DROP DATABASE IF EXISTS {$svcTestQuotedDatabase}");
            } catch (Throwable) {
                // The normal exit path reports cleanup failure. This is only
                // the emergency fallback for a fatal error.
            }
        }
    });
    $CONFIG['db'] = [
        'host' => $svcTestHost,
        'port' => (int)$svcTestPort,
        'name' => $svcTestDatabase,
        'user' => $svcTestUser,
        'pass' => $svcTestPass,
        'charset' => 'utf8mb4',
    ];
} else {
    $CONFIG['db']['name'] = 'safeharbor_test';
}
$CONFIG['svc'] = [
    'enabled' => false,
    'secrets' => [
        'milepost'   => 'TEST_SVC_HMAC_SECRET',
        'retiredsvc' => 'TEST_RETIRED_SVC_SECRET',
    ],
];

const TEST_SECRET = 'TEST_SVC_HMAC_SECRET';

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

/** @return list<string> */
function svc_test_sql_statements(string $sql): array
{
    $delimiter = ';';
    $buffer = '';
    $statements = [];
    foreach (preg_split('/\R/', $sql) ?: [] as $line) {
        if (preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $match) === 1) {
            $pending = array_filter(
                preg_split('/\R/', $buffer) ?: [],
                static fn(string $item): bool => trim($item) !== ''
                    && !str_starts_with(ltrim($item), '--'),
            );
            if ($pending !== []) throw new RuntimeException('DELIMITER changed with pending SQL.');
            $buffer = '';
            $delimiter = $match[1];
            continue;
        }
        $buffer .= $line . "\n";
        $trimmed = rtrim($buffer);
        if (!str_ends_with($trimmed, $delimiter)) continue;
        $statement = trim(substr($trimmed, 0, -strlen($delimiter)));
        if ($statement !== '') $statements[] = $statement;
        $buffer = '';
    }
    if (trim($buffer) !== '') throw new RuntimeException('Unterminated SQL statement.');
    return $statements;
}

function fresh_schema(): void
{
    global $svcTestCreated;
    $pdo = db();
    $tables = $pdo->query(
        'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()'
    )->fetchAll(PDO::FETCH_COLUMN);
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables as $t) {
        $pdo->exec('DROP TABLE IF EXISTS `' . $t . '`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    foreach (['schema.sql', 'migrations/002_svc_intake.sql'] as $f) {
        $sql = str_replace(
            ["\r\n", "\r"],
            "\n",
            (string)file_get_contents(__DIR__ . '/../db/' . $f),
        );
        foreach (svc_test_sql_statements($sql) as $stmt) {
            // Production migrations run as an operator. The scratch app user
            // cannot create triggers while binary logging is enforced.
            $withoutComments = preg_replace('/\A(?:\s*--[^\n]*(?:\n|\z))+/', '', $stmt) ?? $stmt;
            if (!$svcTestCreated
                && preg_match('/^\s*(?:DROP|CREATE)\s+TRIGGER\b/i', $withoutComments)
            ) continue;
            if (trim($stmt) !== '') {
                $pdo->exec($stmt);
            }
        }
    }
    // Migration 018's trigger-backed durability is proven by the dedicated
    // operator-grade MySQL suite. This legacy app-user fixture adds only the
    // column so it can exercise the handler without requiring TRIGGER.
    $pdo->exec(
        'ALTER TABLE tickets
           ADD COLUMN auto_close_eligible TINYINT(1) NOT NULL DEFAULT 0
           AFTER external_key'
    );
    $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Test MSP', 'test')");
    $pdo->exec("INSERT INTO clients (tenant_id, name, sla_tier) VALUES (1, 'Acme Dental', 'premium')");
    $pdo->exec("INSERT INTO svc_identities (tenant_id, service, display_name) VALUES (1, 'milepost', 'Milepost RMM')");
    $pdo->exec("INSERT INTO svc_identities (tenant_id, service, display_name, is_active) VALUES (1, 'retiredsvc', 'Retired Service', 0)");
}

function valid_payload(array $over = []): array
{
    return array_replace_recursive([
        'event'        => 'opened',
        'external_key' => 'alert:9001',
        'occurred_at'  => gmdate('c'),
        'client'       => ['name' => 'Acme Dental'],
        'endpoint'     => ['hostname' => 'ACME-DC01', 'display_name' => 'Acme DC (primary)'],
        'alert'        => [
            'rule_key'   => 'disk_free',
            'metric_key' => 'disk.percent_free',
            'instance'   => 'C:',
            'severity'   => 'critical',
            'message'    => 'C: free space 4.1% (threshold 10%)',
        ],
    ], $over);
}

function auth_for(array $p, string $service = 'milepost', ?int $ts = null, string $secret = TEST_SECRET): array
{
    $ts = $ts ?? time();
    $body = (string)json_encode($p);
    $sig = hash_hmac('sha256', $ts . "\n" . $body, $secret);
    return svc_authenticate($service, (string)$ts, $sig, $body);
}

function ticket_by_key(string $key): ?array
{
    $q = db()->prepare('SELECT * FROM tickets WHERE tenant_id = 1 AND external_key = ?');
    $q->execute([$key]);
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

function message_count(int $ticketId): int
{
    $q = db()->prepare('SELECT COUNT(*) FROM messages WHERE ticket_id = ?');
    $q->execute([$ticketId]);
    return (int)$q->fetchColumn();
}

function last_message(int $ticketId): string
{
    $q = db()->prepare('SELECT body FROM messages WHERE ticket_id = ? ORDER BY id DESC LIMIT 1');
    $q->execute([$ticketId]);
    return (string)$q->fetchColumn();
}

fresh_schema();

// --- kill switch ---------------------------------------------------------
check('svc_disabled_by_default', svc_enabled() === false);
$CONFIG['svc']['enabled'] = true;
check('svc_enabled_when_configured', svc_enabled() === true);

// --- authentication ------------------------------------------------------
check('auth_valid_signature', auth_for(valid_payload())['ok'] === true);
check('auth_rejects_bad_signature', svc_authenticate('milepost', (string)time(), str_repeat('0', 64), '{}')['code'] === 401);
check('auth_rejects_stale_timestamp', auth_for(valid_payload(), 'milepost', time() - 400)['code'] === 401);
check('auth_rejects_future_timestamp', auth_for(valid_payload(), 'milepost', time() + 400)['code'] === 401);
check('auth_rejects_unknown_service', auth_for(valid_payload(), 'nosuchsvc')['code'] === 401);
check('auth_rejects_inactive_service', auth_for(valid_payload(), 'retiredsvc', null, 'TEST_RETIRED_SVC_SECRET')['code'] === 401);
check('auth_rejects_missing_headers', svc_authenticate('', '', '', '{}')['code'] === 401);
check('alert_authority_accepts_only_milepost', svc_alert_service_authorized([
    'ok' => true,
    'service' => 'milepost',
]));
check('alert_authority_rejects_other_authenticated_service', !svc_alert_service_authorized([
    'ok' => true,
    'service' => 'coastmark-support',
]));
check('alert_authority_rejects_failed_auth', !svc_alert_service_authorized([
    'ok' => false,
    'service' => 'milepost',
]));

// --- open → create -------------------------------------------------------
// The ticket row carries automatic-close authority, so it must never commit
// without the opening evidence line. Force only that line to fail and prove
// the handler removes the entire partial creation before surfacing the error.
db()->exec(
    "ALTER TABLE messages
       ADD CONSTRAINT ck_svc_test_force_open_line_failure
       CHECK (LOCATE('force-open-line-failure', body) = 0)"
);
$forcedLineFailureSurfaced = false;
$forcedLineFailureLeftTransaction = false;
try {
    svc_alert_handle(valid_payload([
        'external_key' => 'alert:9010',
        'alert' => ['message' => 'force-open-line-failure'],
    ]));
} catch (Throwable) {
    $forcedLineFailureSurfaced = true;
    $forcedLineFailureLeftTransaction = db()->inTransaction();
} finally {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    db()->exec('ALTER TABLE messages DROP CHECK ck_svc_test_force_open_line_failure');
}
check('open_line_failure_surfaces', $forcedLineFailureSurfaced);
check('open_line_failure_closes_transaction', !$forcedLineFailureLeftTransaction);
check('open_line_failure_rolls_back_eligible_ticket', ticket_by_key('alert:9010') === null);

$r = svc_alert_handle(valid_payload());
check('open_creates_ticket', $r['ok'] === true && $r['action'] === 'created' && !empty($r['ticket']));
$t = ticket_by_key('alert:9001');
check('ticket_channel_is_alert', ($t['channel'] ?? '') === 'alert');
check('ticket_external_key_stored', ($t['external_key'] ?? '') === 'alert:9001');
check('ticket_critical_maps_urgent', ($t['priority'] ?? '') === 'urgent');
check('ticket_subject_format', ($t['subject'] ?? '') === '[critical] disk_free on ACME-DC01');
check('ticket_routed_to_named_client', (int)($t['client_id'] ?? 0) === 1);
check('ticket_status_open', ($t['status'] ?? '') === 'open');
check('new_machine_alert_is_auto_close_eligible', (int)($t['auto_close_eligible'] ?? 0) === 1);
$slaOk = $t && abs(strtotime($t['sla_due_at'] . ' UTC') - time() - 7200) < 300;
check('ticket_sla_premium_two_hours', $slaOk);
$initialTargetId = (int) ($t['service_goal_target_id'] ?? 0);
$initialDueAt = (string) ($t['sla_due_at'] ?? '');
$goal = $t ? goal_for_ticket((int) $t['id']) : null;
check('ticket_captures_premium_v1_urgent_target',
    $initialTargetId > 0
    && ($goal['policy_key'] ?? '') === 'premium'
    && (int) ($goal['version_no'] ?? 0) === 1
    && ($goal['priority'] ?? '') === 'urgent'
    && (int) ($goal['first_response_minutes'] ?? 0) === 120);
check('ticket_has_provenance_message', $t !== null && message_count((int)$t['id']) === 1
    && str_contains(last_message((int)$t['id']), 'Alert opened at source'));

// --- re-fire → update, never duplicate -----------------------------------
$r = svc_alert_handle(valid_payload(['alert' => ['severity' => 'warning', 'message' => 'C: free space 9.2%']]));
check('refire_updates_not_duplicates', $r['ok'] === true && $r['action'] === 'updated');
$q = db()->prepare('SELECT COUNT(*) FROM tickets WHERE tenant_id = 1 AND external_key = ?');
$q->execute(['alert:9001']);
check('refire_still_one_ticket', (int)$q->fetchColumn() === 1);
$t = ticket_by_key('alert:9001');
check('refire_does_not_overwrite_priority', ($t['priority'] ?? '') === 'urgent');
check('refire_does_not_overwrite_subject', ($t['subject'] ?? '') === '[critical] disk_free on ACME-DC01');
check('refire_keeps_untouched_eligibility', (int)($t['auto_close_eligible'] ?? 0) === 1);
check('refire_does_not_rebase_service_goal',
    (int) ($t['service_goal_target_id'] ?? 0) === $initialTargetId
    && ($t['sla_due_at'] ?? '') === $initialDueAt);
check('refire_appends_system_line', $t !== null && message_count((int)$t['id']) === 2
    && str_contains(last_message((int)$t['id']), 're-fired'));

// --- resolve: auto-close untouched tickets --------------------------------
$r = svc_alert_handle(valid_payload(['event' => 'resolved']));
check('resolve_known_key_updates', $r['ok'] === true && $r['action'] === 'updated');
$t = ticket_by_key('alert:9001');
check('resolve_autocloses_untouched_ticket', ($t['status'] ?? '') === 'resolved');
check('resolve_consumes_auto_close_eligibility', (int)($t['auto_close_eligible'] ?? 1) === 0);
$resAtOk = $t && !empty($t['resolved_at']) && abs(strtotime($t['resolved_at'] . ' UTC') - time()) < 300;
check('resolve_stamps_resolved_at', $resAtOk);
check('resolve_line_marks_auto_close', $t !== null && str_contains(last_message((int)$t['id']), 'Resolved at source')
    && str_contains(last_message((int)$t['id']), 'auto-closed'));

// --- resolve: replays and late re-fires never resurrect -------------------
$before = message_count((int)$t['id']);
$r = svc_alert_handle(valid_payload(['event' => 'resolved']));
check('resolve_replay_is_ignored', $r['ok'] === true && $r['action'] === 'ignored');
check('resolve_replay_adds_no_line', message_count((int)$t['id']) === $before);
$r = svc_alert_handle(valid_payload(['alert' => ['severity' => 'critical', 'message' => 'C: free space 2.0%']]));
check('refire_on_closed_ticket_updates', $r['ok'] === true && $r['action'] === 'updated');
$t2 = ticket_by_key('alert:9001');
check('refire_never_reopens_closed_ticket', ($t2['status'] ?? '') === 'resolved');
check('refire_on_closed_keeps_subject', ($t2['subject'] ?? '') === '[critical] disk_free on ACME-DC01');
check('refire_on_closed_appends_line', $t2 !== null && str_contains(last_message((int)$t2['id']), 'after close'));

// --- resolve: human-owned tickets stay with the human ----------------------
svc_alert_handle(valid_payload(['external_key' => 'alert:9006']));
db()->prepare("UPDATE tickets SET status = 'in_progress' WHERE external_key = 'alert:9006'")->execute();
$r = svc_alert_handle(valid_payload(['event' => 'resolved', 'external_key' => 'alert:9006']));
check('resolve_human_owned_updates', $r['ok'] === true && $r['action'] === 'updated');
$t3 = ticket_by_key('alert:9006');
check('resolve_human_owned_stays_open', ($t3['status'] ?? '') === 'in_progress');
check('resolve_human_owned_line_no_autoclose', $t3 !== null && str_contains(last_message((int)$t3['id']), 'Resolved at source')
    && !str_contains(last_message((int)$t3['id']), 'auto-closed'));

// Open is not proof of untouched. A human action can deliberately leave the
// visible status Open; the durable eligibility bit is the authority.
svc_alert_handle(valid_payload(['external_key' => 'alert:9007']));
db()->prepare("UPDATE tickets SET auto_close_eligible = 0, priority = 'high' WHERE external_key = 'alert:9007'")
    ->execute();
$r = svc_alert_handle(valid_payload([
    'event' => 'resolved',
    'external_key' => 'alert:9007',
]));
$t4 = ticket_by_key('alert:9007');
check('resolve_human_touched_open_ticket_updates', $r['ok'] === true && $r['action'] === 'updated');
check('resolve_human_touched_open_ticket_stays_open', ($t4['status'] ?? '') === 'open');
check('resolve_human_touched_open_ticket_keeps_priority', ($t4['priority'] ?? '') === 'high');
check('resolve_human_touched_line_has_no_autoclose', $t4 !== null
    && str_contains(last_message((int)$t4['id']), 'Resolved at source')
    && !str_contains(last_message((int)$t4['id']), 'auto-closed'));

// --- resolve: unknown keys stay harmless -----------------------------------
$r = svc_alert_handle(valid_payload(['event' => 'resolved', 'external_key' => 'alert:9002']));
check('resolve_unknown_key_ignored', $r['ok'] === true && $r['action'] === 'ignored');
check('resolve_unknown_creates_nothing', ticket_by_key('alert:9002') === null);

// --- client routing ------------------------------------------------------
$r = svc_alert_handle(valid_payload(['external_key' => 'alert:9003', 'client' => ['name' => 'No Such Business LLC']]));
check('unknown_client_creates_ticket', $r['ok'] === true && $r['action'] === 'created');
$t = ticket_by_key('alert:9003');
$q = db()->query("SELECT id FROM clients WHERE tenant_id = 1 AND name = 'Milepost Intake'");
$catchAll = (int)$q->fetchColumn();
check('unknown_client_uses_catch_all', $catchAll > 0 && (int)($t['client_id'] ?? 0) === $catchAll);
$catchAllGoal = $t ? goal_for_ticket((int) $t['id']) : null;
check('catch_all_ticket_captures_standard_v1',
    ($catchAllGoal['policy_key'] ?? '') === 'standard'
    && (int) ($catchAllGoal['version_no'] ?? 0) === 1
    && (int) ($catchAllGoal['first_response_minutes'] ?? 0) === 480);
svc_alert_handle(valid_payload(['external_key' => 'alert:9004', 'client' => ['name' => 'Another Mystery Inc']]));
$q = db()->query("SELECT COUNT(*) FROM clients WHERE tenant_id = 1 AND name = 'Milepost Intake'");
check('catch_all_created_exactly_once', (int)$q->fetchColumn() === 1);

// --- mapping + validation ------------------------------------------------
$r = svc_alert_handle(valid_payload(['external_key' => 'alert:9005', 'alert' => ['severity' => 'catastrophic']]));
check('unknown_severity_maps_normal', $r['ok'] === true && (ticket_by_key('alert:9005')['priority'] ?? '') === 'normal');
check('bad_event_rejected', svc_alert_handle(valid_payload(['event' => 'exploded']))['ok'] === false);
check('missing_external_key_rejected', svc_alert_handle(valid_payload(['external_key' => '']))['ok'] === false);
check('non_alert_external_key_rejected', svc_alert_handle(valid_payload(['external_key' => 'westy:9001']))['ok'] === false);
check('zero_alert_external_key_rejected', svc_alert_handle(valid_payload(['external_key' => 'alert:0']))['ok'] === false);
check('maximum_unsigned_alert_external_key_accepted', svc_alert_external_key_valid('alert:18446744073709551615'));
check('above_unsigned_alert_external_key_rejected', svc_alert_handle(valid_payload(['external_key' => 'alert:' . str_repeat('9', 20)]))['ok'] === false);
check('overlong_alert_external_key_rejected', svc_alert_handle(valid_payload(['external_key' => 'alert:' . str_repeat('9', 21)]))['ok'] === false);
check('ancient_occurred_at_rejected', svc_alert_handle(valid_payload(['occurred_at' => gmdate('c', time() - 48 * 3600)]))['ok'] === false);

// --- rate limit ----------------------------------------------------------
db()->prepare('DELETE FROM svc_rate_buckets')->execute();
$bucket = (int)floor(time() / 60);
db()->prepare('INSERT INTO svc_rate_buckets (service, bucket_minute, hits) VALUES (?,?,119)')
    ->execute(['milepost', $bucket]);
check('rate_allows_120th_request', auth_for(valid_payload())['ok'] === true);
$denied = auth_for(valid_payload());
check('rate_denies_121st_request', $denied['ok'] === false && $denied['code'] === 429);

if ($svcTestCreated && $svcTestServer instanceof PDO && is_string($svcTestDatabase)) {
    try {
        $svcTestServer->exec('DROP DATABASE IF EXISTS `'
            . str_replace('`', '``', $svcTestDatabase) . '`');
        $svcTestCreated = false;
        check('disposable_database_removed', (int)$svcTestServer->query(
            'SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='
            . $svcTestServer->quote($svcTestDatabase)
        )->fetchColumn() === 0);
    } catch (Throwable) {
        check('disposable_database_removed', false);
    }
}

echo "---\n{$checkCount} checks, {$failCount} failures\n";
exit($failCount > 0 ? 1 : 0);
