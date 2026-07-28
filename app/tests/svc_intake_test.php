<?php
/**
 * Hermetic tests for the Phase 8.1 svc alert intake (Path B).
 *
 * CLI only. Runs against a dedicated scratch database — NEVER the live
 * one. The scratch db must exist and be owned by the app MySQL user:
 *   CREATE DATABASE safeharbor_test;
 *   GRANT ALL PRIVILEGES ON safeharbor_test.* TO 'safeharbor'@'localhost';
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
$CONFIG['db']['name'] = 'safeharbor_test';
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

function fresh_schema(): void
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
    foreach (['schema.sql', 'migrations/002_svc_intake.sql'] as $f) {
        $sql = (string)file_get_contents(__DIR__ . '/../db/' . $f);
        foreach (explode(";\n", $sql) as $stmt) {
            if (trim($stmt) !== '') {
                $pdo->exec($stmt);
            }
        }
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

// --- open → create -------------------------------------------------------
$r = svc_alert_handle(valid_payload());
check('open_creates_ticket', $r['ok'] === true && $r['action'] === 'created' && !empty($r['ticket']));
$t = ticket_by_key('alert:9001');
check('ticket_channel_is_alert', ($t['channel'] ?? '') === 'alert');
check('ticket_external_key_stored', ($t['external_key'] ?? '') === 'alert:9001');
check('ticket_critical_maps_urgent', ($t['priority'] ?? '') === 'urgent');
check('ticket_subject_format', ($t['subject'] ?? '') === '[critical] disk_free on ACME-DC01');
check('ticket_routed_to_named_client', (int)($t['client_id'] ?? 0) === 1);
check('ticket_status_open', ($t['status'] ?? '') === 'open');
$slaOk = $t && abs(strtotime($t['sla_due_at'] . ' UTC') - time() - 7200) < 300;
check('ticket_sla_premium_two_hours', $slaOk);
check('ticket_has_provenance_message', $t !== null && message_count((int)$t['id']) === 1
    && str_contains(last_message((int)$t['id']), 'Alert opened at source'));

// --- re-fire → update, never duplicate -----------------------------------
$r = svc_alert_handle(valid_payload(['alert' => ['severity' => 'warning', 'message' => 'C: free space 9.2%']]));
check('refire_updates_not_duplicates', $r['ok'] === true && $r['action'] === 'updated');
$q = db()->prepare('SELECT COUNT(*) FROM tickets WHERE tenant_id = 1 AND external_key = ?');
$q->execute(['alert:9001']);
check('refire_still_one_ticket', (int)$q->fetchColumn() === 1);
$t = ticket_by_key('alert:9001');
check('refire_refreshes_priority', ($t['priority'] ?? '') === 'normal');
check('refire_refreshes_subject', ($t['subject'] ?? '') === '[warning] disk_free on ACME-DC01');
check('refire_appends_system_line', $t !== null && message_count((int)$t['id']) === 2
    && str_contains(last_message((int)$t['id']), 're-fired'));

// --- resolve -------------------------------------------------------------
$r = svc_alert_handle(valid_payload(['event' => 'resolved']));
check('resolve_known_key_updates', $r['ok'] === true && $r['action'] === 'updated');
$t = ticket_by_key('alert:9001');
check('resolve_never_closes_ticket', ($t['status'] ?? '') === 'open');
check('resolve_appends_system_line', $t !== null && str_contains(last_message((int)$t['id']), 'Resolved at source'));
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
svc_alert_handle(valid_payload(['external_key' => 'alert:9004', 'client' => ['name' => 'Another Mystery Inc']]));
$q = db()->query("SELECT COUNT(*) FROM clients WHERE tenant_id = 1 AND name = 'Milepost Intake'");
check('catch_all_created_exactly_once', (int)$q->fetchColumn() === 1);

// --- mapping + validation ------------------------------------------------
$r = svc_alert_handle(valid_payload(['external_key' => 'alert:9005', 'alert' => ['severity' => 'catastrophic']]));
check('unknown_severity_maps_normal', $r['ok'] === true && (ticket_by_key('alert:9005')['priority'] ?? '') === 'normal');
check('bad_event_rejected', svc_alert_handle(valid_payload(['event' => 'exploded']))['ok'] === false);
check('missing_external_key_rejected', svc_alert_handle(valid_payload(['external_key' => '']))['ok'] === false);
check('ancient_occurred_at_rejected', svc_alert_handle(valid_payload(['occurred_at' => gmdate('c', time() - 48 * 3600)]))['ok'] === false);

// --- rate limit ----------------------------------------------------------
db()->prepare('DELETE FROM svc_rate_buckets')->execute();
$bucket = (int)floor(time() / 60);
db()->prepare('INSERT INTO svc_rate_buckets (service, bucket_minute, hits) VALUES (?,?,119)')
    ->execute(['milepost', $bucket]);
check('rate_allows_120th_request', auth_for(valid_payload())['ok'] === true);
$denied = auth_for(valid_payload());
check('rate_denies_121st_request', $denied['ok'] === false && $denied['code'] === 429);

echo "---\n{$checkCount} checks, {$failCount} failures\n";
exit($failCount > 0 ? 1 : 0);
