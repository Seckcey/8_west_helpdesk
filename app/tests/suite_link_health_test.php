<?php
/** Safeharbor link health reads both bindings, flags gaps, prints no secret and writes nothing. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(2);
require_once __DIR__ . '/../lib/suite_link_health.php';
$checks = 0;
function health_check(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL ' . $name); $checks++; }
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE suite_customer_sync_bindings (id INTEGER PRIMARY KEY, tenant_id INT, customer_id TEXT, client_id INT,
    source_version INT, display_name TEXT, status TEXT, last_event_id TEXT, last_occurred_at TEXT, last_request_sha256 TEXT)');
$pdo->exec('CREATE TABLE customer_portal_bindings (id INTEGER PRIMARY KEY, identity_tenant_slug TEXT, tenant_id INT, client_id INT,
    status TEXT, prepared_by_user_id INT, last_changed_by_user_id INT, status_reason TEXT)');
$secret = str_repeat('ab', 32);
$pdo->exec("INSERT INTO suite_customer_sync_bindings VALUES
    (1,1,'00000000-0000-4000-8000-000000000001',10,1,'RPM','active','e1','2026-10-03','$secret'),
    (2,1,'00000000-0000-4000-8000-000000000002',11,2,'Nexgen','active','e2','2026-10-03','$secret'),
    (3,1,'00000000-0000-4000-8000-000000000003',12,3,'Gone Co','inactive','e3','2026-10-03','$secret')");
$pdo->exec("INSERT INTO customer_portal_bindings VALUES
    (1,'customer-aaaa',1,10,'active',1,1,'reason with owner@example.test'),
    (2,'customer-bbbb',1,12,'active',1,1,'x'),
    (3,'hand-made',1,30,'disabled',1,1,'x')");
$before = serialize($pdo->query('SELECT * FROM customer_portal_bindings')->fetchAll());
$rows = suite_link_health($pdo);
$byClient = array_column($rows, null, 'client_id');
$json = json_encode($rows, JSON_THROW_ON_ERROR);
health_check(count($rows) === 4, 'every synced client and every unsynced portal binding is listed');
health_check($byClient[10]['state'] === 'linked' && $byClient[10]['identity_tenant_slug'] === 'customer-aaaa', 'a synced client with an active portal binding is linked');
health_check($byClient[11]['state'] === 'incomplete' && $byClient[11]['portal_binding'] === 'missing', 'a synced client without a portal binding is flagged');
health_check($byClient[12]['state'] === 'incomplete' && $byClient[12]['sync_status'] === 'inactive', 'an inactive customer is not reported as linked');
health_check($byClient[30]['sync_status'] === 'missing' && $byClient[30]['portal_binding'] === 'disabled', 'a hand-made binding without a synced client is flagged');
health_check(!str_contains($json, '@') && !str_contains($json, $secret) && !str_contains($json, 'reason'), 'no email, request hash or reason is printed');
health_check(serialize($pdo->query('SELECT * FROM customer_portal_bindings')->fetchAll()) === $before, 'the check writes nothing');
echo "PASS $checks suite link health assertions\n";
