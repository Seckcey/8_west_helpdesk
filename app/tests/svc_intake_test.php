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
    $files = ['schema.sql', 'migrations/002_svc_intake.sql', 'migrations/009_support_intake.sql'];
    if ($svcTestCreated) $files[] = 'migrations/018_ticket_auto_close_eligibility.sql';
    foreach ($files as $f) {
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
            if (preg_match('/^\s*SELECT\b/i', $withoutComments)) {
                $pdo->query($stmt)->closeCursor();
            } elseif (trim($stmt) !== '') {
                $pdo->exec($stmt);
            }
        }
    }
    // CI uses the real 018 insert guard. The legacy app user lacks TRIGGER.
    if (!$svcTestCreated) {
        $pdo->exec(
            'ALTER TABLE tickets ADD COLUMN auto_close_eligible TINYINT(1) NOT NULL DEFAULT 0 AFTER external_key'
        );
    }
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

function svc_test_client(int $tenantId, string $name, string $tier = 'standard'): int
{
    db()->prepare('INSERT INTO clients (tenant_id, name, sla_tier) VALUES (?,?,?)')->execute([$tenantId, $name, $tier]);
    return (int)db()->lastInsertId();
}

/** Use the real binding transitions. Only the legacy triggerless fixture supplies its own history. */
function svc_test_binding(int $tenantId, int $clientId, string $name, string $customerId, array $statuses): void
{
    global $svcTestCreated;
    static $eventNumber = 0;
    foreach ($statuses as $index => $status) {
        $eventId = sprintf('10000000-0000-4000-8000-%012x', ++$eventNumber);
        $digest = hash('sha256', $eventId);
        if ($index === 0) {
            db()->prepare(
                'INSERT INTO suite_customer_sync_bindings
                    (tenant_id, client_id, customer_id, display_name, source_version, status,
                     last_event_id, last_request_sha256, last_occurred_at)
                 VALUES (?,?,?,?,1,?,?,?,UTC_TIMESTAMP())'
            )->execute([$tenantId, $clientId, $customerId, $name, $status, $eventId, $digest]);
        } else {
            db()->prepare(
                'UPDATE suite_customer_sync_bindings
                    SET source_version = ?, status = ?, last_event_id = ?, last_request_sha256 = ?, last_occurred_at = UTC_TIMESTAMP()
                  WHERE tenant_id = ? AND customer_id = ?'
            )->execute([$index + 1, $status, $eventId, $digest, $tenantId, $customerId]);
        }
        if (!$svcTestCreated) {
            db()->prepare(
                'INSERT INTO suite_customer_sync_events
                    (tenant_id, event_id, binding_id, customer_id, client_id, source_version, display_name, status, occurred_at, request_sha256)
                 SELECT tenant_id, last_event_id, id, customer_id, client_id, source_version, display_name, status, last_occurred_at, last_request_sha256
                   FROM suite_customer_sync_bindings WHERE tenant_id = ? AND customer_id = ?'
            )->execute([$tenantId, $customerId]);
        }
    }
}

function svc_test_lines(string $body, string $prefix): array
{
    return array_values(array_filter(explode("\n", $body), static fn(string $line): bool => str_starts_with($line, $prefix)));
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
$namesakeId = svc_test_client(1, 'Milepost Intake');
$namesakeUuid = '00000000-0000-4000-8000-000000000011';
svc_test_binding(1, $namesakeId, 'Milepost Intake', $namesakeUuid, ['active']);
$r = svc_alert_handle(valid_payload(['external_key' => 'alert:9003', 'client' => ['name' => 'No Such Business LLC']]));
check('unknown_client_creates_ticket', $r['ok'] === true && $r['action'] === 'created');
$t = ticket_by_key('alert:9003');
$q = db()->prepare("SELECT id FROM clients WHERE tenant_id = ? AND name = 'Milepost Intake' AND id <> ?");
$q->execute([1, $namesakeId]);
$catchAll = (int)$q->fetchColumn();
check('unknown_client_uses_catch_all', $catchAll > 0 && (int)($t['client_id'] ?? 0) === $catchAll);
$catchAllGoal = $t ? goal_for_ticket((int) $t['id']) : null;
check('catch_all_ticket_captures_standard_v1',
    ($catchAllGoal['policy_key'] ?? '') === 'standard'
    && (int) ($catchAllGoal['version_no'] ?? 0) === 1
    && (int) ($catchAllGoal['first_response_minutes'] ?? 0) === 480);
svc_alert_handle(valid_payload(['external_key' => 'alert:9004', 'client' => ['name' => 'Another Mystery Inc']]));
$q = db()->prepare("SELECT COUNT(*) FROM clients WHERE tenant_id = ? AND name = 'Milepost Intake' AND id <> ?");
$q->execute([1, $namesakeId]);
check('catch_all_created_exactly_once', (int)$q->fetchColumn() === 1);

$boundUuid = '3f2b8c1e-5a4d-4e6f-9b7a-1c2d3e4f5a6b';
$inactiveUuid = '00000000-0000-4000-8000-000000000012';
$heldUuid = '00000000-0000-4000-8000-000000000013';
$foreignUuid = '00000000-0000-4000-8000-000000000014';
$unknownUuid = '00000000-0000-4000-8000-000000000015';
$boundClient = svc_test_client(1, 'Acme Dental'); // Same name, different ID and SLA from the first client.
svc_test_binding(1, $boundClient, 'Acme Dental', $boundUuid, ['active']);
svc_test_binding(1, svc_test_client(1, 'Inactive'), 'Inactive', $inactiveUuid, ['active', 'inactive']);
svc_test_binding(1, svc_test_client(1, 'Held'), 'Held', $heldUuid, ['active', 'inactive', 'active']);
db()->prepare('INSERT INTO tenants (id, name, slug) VALUES (?,?,?)')->execute([2, 'Other MSP', 'other-msp']);
svc_test_binding(2, svc_test_client(2, 'Acme Dental'), 'Acme Dental', $foreignUuid, ['active']);
svc_test_client(1, 'Harbor Dental'); // The vectors use this name but their ID belongs to $boundClient.
$unboundClient = svc_test_client(1, 'Unbound Client');

$noName = 'Routing: sent to Milepost Intake because no Safeharbor client has the name Milepost sent.';
$malformed = 'Routing: sent to Milepost Intake because the customer id from Milepost was not a valid id.';
$notFound = 'Routing: sent to Milepost Intake because Safeharbor has no customer with the id Milepost sent.';
$inactive = 'Routing: sent to Milepost Intake because that customer is not active in Safeharbor.';
$notOperational = 'Routing: sent to Milepost Intake because that customer is not currently serviceable in Safeharbor.';
$cases = [
    'id beats a different name' => [['name' => 'Harbor Dental', 'customer_id' => $boundUuid], $boundClient, null],
    'id wins between namesakes' => [['name' => 'Acme Dental', 'customer_id' => $boundUuid], $boundClient, null],
    'id of managed intake namesake' => [['customer_id' => $namesakeUuid], $namesakeId, null],
    'inactive binding' => [['name' => 'Acme Dental', 'customer_id' => $inactiveUuid], $catchAll, $inactive],
    'active with unrestored inactive history' => [['name' => 'Acme Dental', 'customer_id' => $heldUuid], $catchAll, $notOperational],
    'unknown id' => [['name' => 'Acme Dental', 'customer_id' => $unknownUuid], $catchAll, $notFound],
    'binding only in tenant 2' => [['name' => 'Acme Dental', 'customer_id' => $foreignUuid], $catchAll, $notFound],
    'null id' => [['name' => 'acme dental', 'customer_id' => null], 1, null],
    'absent id' => [['name' => ' ACME DENTAL '], 1, null],
    'unnamed client' => [['name' => ''], $catchAll, $noName],
    'null id and unmatched name' => [['name' => 'Missing', 'customer_id' => null], $catchAll, $noName],
];
foreach (['', false, 2, [], ['id' => $boundUuid], strtoupper($boundUuid), $boundUuid . "\n",
    '00000000-0000-1000-8000-000000000011', '00000000-0000-4000-7000-000000000011'] as $index => $badId) {
    $cases["malformed id {$index}"] = [['name' => 'Acme Dental', 'customer_id' => $badId], $catchAll, $malformed];
}
$keyNumber = 10000;
foreach ($cases as $label => [$client, $expectedClient, $reasonLine]) {
    $key = 'alert:' . ++$keyNumber;
    $p = valid_payload(['external_key' => $key]);
    $p['client'] = $client;
    $r = svc_alert_handle($p);
    $t = ticket_by_key($key);
    $body = last_message((int)$r['ticket']);
    check("routing: {$label}", $r['action'] === 'created' && (int)$t['client_id'] === $expectedClient
        && svc_test_lines($body, 'Routing:') === ($reasonLine === null ? [] : [$reasonLine]));
    if (is_string($client['customer_id'] ?? null) && $client['customer_id'] !== '') {
        check("raw customer id omitted: {$label}", !str_contains($body, $client['customer_id']));
    }
}

// The catch-all stays the catch-all after a person edits it. A second "Milepost Intake" would
// split the unmatched alerts between two clients.
db()->prepare('UPDATE clients SET notes = ?, domain = ? WHERE tenant_id = ? AND id = ?')
    ->execute(['Edited by a technician.', 'intake.example.test', 1, $catchAll]);
$key = 'alert:10900';
$r = svc_alert_handle(valid_payload(['external_key' => $key, 'client' => ['name' => 'Still Missing']]));
$t = ticket_by_key($key);
$q = db()->prepare("SELECT COUNT(*) FROM clients WHERE tenant_id = ? AND name = 'Milepost Intake'");
$q->execute([1]);
check('edited catch-all is reused, not duplicated', $r['action'] === 'created'
    && (int)$t['client_id'] === $catchAll && (int)$q->fetchColumn() === 2); // the catch-all and the bound namesake
// A client with that name that came from a source record is a real client, even when unbound.
$sourced = svc_test_client(1, 'Milepost Intake');
db()->prepare('UPDATE clients SET source_key = ? WHERE tenant_id = ? AND id = ?')->execute(['synthetic:intake-namesake', 1, $sourced]);
db()->prepare('UPDATE clients SET name = ? WHERE tenant_id = ? AND id = ?')->execute(['Milepost Intake (retired)', 1, $catchAll]);
$key = 'alert:10901';
$r = svc_alert_handle(valid_payload(['external_key' => $key, 'client' => ['name' => 'Still Missing']]));
$t = ticket_by_key($key);
$replacement = (int)$t['client_id'];
check('bound and sourced namesakes are never the catch-all', $r['action'] === 'created'
    && !in_array($replacement, [$namesakeId, $sourced, $catchAll], true));
// Put the original back and move the two extras out of the way. Nothing is deleted.
$rename = db()->prepare('UPDATE clients SET name = ? WHERE tenant_id = ? AND id = ?');
$rename->execute(['Synthetic sourced namesake', 1, $sourced]);
$rename->execute(['Synthetic replacement catch-all', 1, $replacement]);
db()->prepare('UPDATE clients SET name = ?, notes = ?, domain = ? WHERE tenant_id = ? AND id = ?')
    ->execute(['Milepost Intake', TICKET_MILEPOST_INTAKE_NOTES, '', 1, $catchAll]);

$key = 'alert:11001';
$opened = valid_payload(['external_key' => $key, 'client' => ['customer_id' => $boundUuid]]);
$r = svc_alert_handle($opened);
$original = ticket_by_key($key);
$goal = goal_for_ticket((int)$r['ticket']);
check('ID-routed ticket takes the bound client service goal', (int)$original['client_id'] === $boundClient
    && $goal['policy_key'] === 'standard' && (int)$goal['first_response_minutes'] === 480);
foreach (['opened', 'resolved', 'opened'] as $event) {
    $changed = valid_payload(['external_key' => $key, 'event' => $event, 'client' => ['customer_id' => $namesakeUuid],
        'alert' => ['severity' => 'info', 'rule_key' => 'different']]);
    $r = svc_alert_handle($changed);
    $t = ticket_by_key($key);
    check("{$event} with different ID preserves ticket and captured goal", $r['action'] === 'updated'
        && (int)$r['ticket'] === (int)$original['id'] && $t['client_id'] === $original['client_id']
        && $t['subject'] === $original['subject'] && $t['priority'] === $original['priority']
        && $t['service_goal_target_id'] === $original['service_goal_target_id'] && $t['sla_due_at'] === $original['sla_due_at']
        && svc_test_lines(last_message((int)$r['ticket']), 'Routing:') === []);
}

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

$ticketIds = [];
foreach (['alert', 'netalert', 'netrule'] as $prefix) {
    $key = $prefix . ':1';
    $r = svc_alert_handle(valid_payload(['external_key' => $key]));
    $t = ticket_by_key($key);
    check("{$key} creates its own alert ticket", $r['action'] === 'created' && $t['channel'] === 'alert'
        && (int)$t['auto_close_eligible'] === ($prefix === 'alert' ? 1 : 0));
    $ticketIds[] = (int)$r['ticket'];
    $r = svc_alert_handle(valid_payload(['external_key' => $key, 'event' => 'resolved']));
    $t = ticket_by_key($key);
    check("{$key} recovery respects migration 018", $r['action'] === 'updated'
        && $t['status'] === ($prefix === 'alert' ? 'resolved' : 'open')
        && str_starts_with(last_message((int)$r['ticket']), 'Resolved at source at ')
        && str_contains(last_message((int)$r['ticket']), 'auto-closed') === ($prefix === 'alert'));
    check("{$prefix} accepts maximum unsigned id", svc_alert_external_key_valid($prefix . ':18446744073709551615'));
    check("{$prefix} rejects unsigned overflow", !svc_alert_external_key_valid($prefix . ':18446744073709551616'));
}
check('same source number across three namespaces creates three tickets', count(array_unique($ticketIds)) === 3);
foreach (['netalert:0', 'netrule:01', 'net:1', 'alert:1x', 'netalert:-1', 'netrule:1.0'] as $badKey) {
    check("reject invalid key {$badKey}", svc_alert_handle(valid_payload(['external_key' => $badKey]))['ok'] === false);
}

$offlineLine = "Triage: 5 of 6 computers for this client stopped reporting at about the same time. Check the site's power and internet first.";
$rebootJob = 'Triage: this computer went quiet within an hour of a restart sent from Milepost.';
$patchJob = 'Triage: this computer went quiet within an hour of an update install sent from Milepost.';
$agentJob = 'Triage: this computer went quiet within an hour of a Milepost agent update.';
$pending = 'Triage: a restart was pending when this computer last reported.';
$never = 'Triage: this computer has never reported to Milepost.';
$counts = ['code' => 'client_devices_offline', 'offline' => 5, 'total' => 6];
$after = ['code' => 'after_job', 'kind' => 'reboot'];
$wasPending = ['code' => 'reboot_was_pending'];
$noContact = ['code' => 'never_checked_in'];
$triageCases = [
    'canonical order' => [[$noContact, $after, $wasPending, $counts], [$offlineLine, $rebootJob, $pending, $never]],
    'patch install' => [[['kind' => 'patch_install', 'code' => 'after_job']], [$patchJob]],
    'agent update' => [[['code' => 'after_job', 'kind' => 'agent_update']], [$agentJob]],
    'unknown and non-object entries' => [[null, 'untrusted-text', ['code' => 'other'], $wasPending], [$pending]],
    'nested and extra fields' => [[['code' => ['nested']], $counts + ['extra' => ['nested']], $noContact + ['extra' => true], $after + ['extra' => 1]], []],
    'missing fields' => [[['code' => 'after_job'], ['code' => 'client_devices_offline', 'offline' => 2]], []],
    'strict JSON types' => [[array_replace($counts, ['offline' => '5']), array_replace($counts, ['total' => 6.0]),
        array_replace($counts, ['offline' => true]), array_replace($after, ['kind' => ['reboot']])], []],
    'range and enum' => [[array_replace($counts, ['offline' => 1]), array_replace($counts, ['total' => 4]),
        array_replace($counts, ['total' => 10000]), array_replace($after, ['kind' => 'unknown'])], []],
    'first valid duplicate wins' => [[['code' => 'after_job'], $after, array_replace($after, ['kind' => 'agent_update']), $noContact], [$rebootJob, $never]],
    'fifth position discarded after invalid and duplicate' => [[null, $wasPending, $wasPending, $after, $noContact], [$rebootJob, $pending]],
    'object instead of list' => [['other' => $wasPending], []],
    'JSON object with a numeric key' => [(object)[$wasPending], []],
    'scalar instead of list' => ['untrusted-text', []],
];
foreach ([2, 9999] as $n) {
    $triageCases["inclusive boundary {$n}"] = [[['code' => 'client_devices_offline', 'offline' => $n, 'total' => $n]],
        ["Triage: {$n} of {$n} computers for this client stopped reporting at about the same time. Check the site's power and internet first."]];
}
foreach ($triageCases as $label => [$findings, $expected]) {
    $key = 'alert:' . ++$keyNumber;
    $p = valid_payload(['external_key' => $key, 'alert' => ['rule_key' => 'offline', 'message' => 'Last contact', 'triage' => $findings]]);
    $payload = svc_alert_decode(json_encode($p, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    $r = svc_alert_handle($payload);
    $body = last_message((int)$r['ticket']);
    check("triage: {$label}", $r['action'] === 'created' && svc_test_lines($body, 'Triage:') === $expected
        && str_ends_with($body, implode("\n", ['message: Last contact', ...$expected])));
}

$key = 'alert:12001';
$p = valid_payload(['external_key' => $key, 'alert' => ['triage' => [$noContact]]]);
$r = svc_alert_handle($p);
check('other rule ignores triage', svc_test_lines(last_message((int)$r['ticket']), 'Triage:') === []);
$p['event'] = 'resolved';
$p['alert']['rule_key'] = 'offline';
$r = svc_alert_handle($p);
check('resolved ignores triage', svc_test_lines(last_message((int)$r['ticket']), 'Triage:') === []);
$p = valid_payload(['external_key' => 'alert:12002', 'client' => ['customer_id' => $unknownUuid],
    'alert' => ['rule_key' => 'offline', 'message' => str_repeat('éx', 4100), 'triage' => [$noContact]]]);
$r = svc_alert_handle($p);
$body = last_message((int)$r['ticket']);
check('long message retains complete triage and routing within the character cap', mb_strlen($body) === 8000
    && str_ends_with($body, "\n{$never}\n{$notFound}"));
$r = svc_alert_handle($p);
check('offline re-fire renders findings and omits the opening route reason',
    $r['action'] === 'updated' && svc_test_lines(last_message((int)$r['ticket']), 'Triage:') === [$never]
    && svc_test_lines(last_message((int)$r['ticket']), 'Routing:') === []);

require_once __DIR__ . '/../lib/ticket_page.php';
$p = valid_payload(['external_key' => 'alert:12003', 'alert' => ['rule_key' => 'offline',
    'message' => 'Device <Offline>', 'triage' => [$wasPending, $noContact]]]);
$r = svc_alert_handle($p);
$staffHtml = ticket_message(['kind' => 'system', 'author_name' => 'Milepost', 'created_at' => gmdate('Y-m-d H:i:s'),
    'body' => last_message((int)$r['ticket'])], '', 'UTC');
check('staff sees escaped message and distinct triage lines', str_contains($staffHtml,
    "message: Device &lt;Offline&gt;<br>\n{$pending}<br>\n{$never}"));

// Run the sender's exact payloads, in lifecycle order. Only the 24-hour timestamp changes.
$vectors = json_decode(file_get_contents(__DIR__ . '/../../docs/contracts/svc-alert-vectors.json'), true, 512, JSON_THROW_ON_ERROR);
$expectedVectors = [
    'opened_with_customer_id' => ['created', $boundClient, []],
    'opened_without_customer_id' => ['created', $unboundClient, []],
    'opened_offline_with_triage' => ['created', $boundClient, [$offlineLine, $patchJob, $pending]],
    'resolved_with_customer_id' => ['updated', $boundClient, []],
];
check('all shared vectors have receiver expectations', array_column($vectors['vectors'], 'name') === array_keys($expectedVectors));
foreach ($vectors['vectors'] as $vector) {
    $p = $vector['payload'];
    $p['occurred_at'] = gmdate('c');
    $r = svc_alert_handle($p);
    $t = ticket_by_key($p['external_key']);
    $body = last_message((int)$r['ticket']);
    [$action, $expectedClient, $expectedLines] = $expectedVectors[$vector['name']];
    $when = gmdate('Y-m-d H:i:s', strtotime($p['occurred_at']));
    $expectedTail = implode("\n", ['message: ' . $p['alert']['message'], ...$expectedLines]);
    $rendered = $p['event'] === 'opened' ? str_ends_with($body, $expectedTail)
        : $body === "Resolved at source at {$when} UTC. Ticket auto-closed." && $t['status'] === 'resolved';
    check('shared vector ' . $vector['name'], $r['ok'] && $r['action'] === $action
        && (int)$r['ticket'] === (int)$t['id'] && (int)$t['client_id'] === $expectedClient
        && svc_test_lines($body, 'Routing:') === [] && svc_test_lines($body, 'Triage:') === $expectedLines && $rendered);
}

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
