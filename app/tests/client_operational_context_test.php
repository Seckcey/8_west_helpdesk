<?php
/** Existing-style SQLite checks for shared receipt context and transactional local removal. */
declare(strict_types=1);
require_once __DIR__ . '/../lib/client_operational_context.php';
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys=ON');
$pdo->exec('CREATE TABLE tenants(id INTEGER PRIMARY KEY);
CREATE TABLE clients(id INTEGER PRIMARY KEY, tenant_id INTEGER, name TEXT);
CREATE TABLE contacts(id INTEGER PRIMARY KEY, client_id INTEGER REFERENCES clients(id));
CREATE TABLE tickets(id INTEGER PRIMARY KEY, client_id INTEGER REFERENCES clients(id));
CREATE TABLE suite_customer_sync_bindings(id INTEGER PRIMARY KEY, tenant_id INTEGER, client_id INTEGER REFERENCES clients(id), customer_id TEXT, source_version INTEGER, display_name TEXT, status TEXT, last_event_id TEXT, last_occurred_at TEXT);
CREATE TABLE suite_customer_sync_events(id INTEGER PRIMARY KEY, binding_id INTEGER, tenant_id INTEGER, client_id INTEGER, customer_id TEXT, source_version INTEGER, display_name TEXT, status TEXT, event_id TEXT, occurred_at TEXT);
INSERT INTO tenants VALUES(1),(2);
INSERT INTO clients VALUES(10,1,"Local override"),(11,1,"Local client"),(12,2,"Other tenant"),(13,1,"Late binding"),(14,1,"FK refusal");
INSERT INTO contacts VALUES(1,10),(2,11),(3,12),(4,13),(5,14);');
$customer = '11111111-1111-4111-8111-111111111111';
$event = '22222222-2222-4222-8222-222222222222';
$insert = $pdo->prepare('INSERT INTO suite_customer_sync_bindings VALUES(?,?,?,?,?,?,?,?,?)');
$insert->execute([1,1,10,$customer,2,'Shared name','inactive',$event,'2026-10-11 12:00:00']);
$pdo->prepare('INSERT INTO suite_customer_sync_events VALUES(?,?,?,?,?,?,?,?,?,?)')
    ->execute([1,1,1,10,$customer,2,'Shared name','inactive',$event,'2026-10-11 12:00:00']);
$checks = 0;
function operational_check(string $label, bool $ok): void
{
    global $checks;
    $checks++;
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
}
$context = client_operational_context($pdo, 1, 10);
operational_check('shared name comes from receipt, not local override', $context['available'] && $context['display_name'] === 'Shared name');
operational_check('inactive last-synced state is retained', $context['status'] === 'inactive' && $context['source_version'] === 2);
operational_check('closed canonical edit URL', $context['edit_url'] === 'https://support.8westit.com/suite_customer_metadata.php?customer_id='.$customer.'&expected_version=2');
operational_check('URL rejects forged query content', client_operational_edit_url($customer.'&return=https://example.com', 2) === null);
operational_check('URL rejects noncanonical UUID', client_operational_edit_url('ABCDEFAB-1111-4111-8111-111111111111', 2) === null);
operational_check('URL bounds version', client_operational_edit_url($customer, 1025) === null && client_operational_edit_url($customer, 0) === null);
operational_check('cross-tenant binding is not projected', client_operational_context($pdo, 2, 10)['bound'] === false);
$pdo->exec('UPDATE suite_customer_sync_events SET tenant_id=2 WHERE id=1');
operational_check('mismatched receipt preserves bound distinction', client_operational_context($pdo, 1, 10) === ['bound'=>true,'available'=>false]);
$pdo->exec('UPDATE suite_customer_sync_events SET tenant_id=1, occurred_at="2026-10-11 11:59:00" WHERE id=1');
operational_check('old receipt is not accepted as current binding', !client_operational_context($pdo, 1, 10)['available']);
$pdo->exec('UPDATE suite_customer_sync_events SET occurred_at="2026-10-11 12:00:00" WHERE id=1');
operational_check('forged zero-ticket delete of bound client refused', client_operational_delete_local($pdo, 1, 10) === 'bound');
operational_check('bound contacts/name/history intact', (int)$pdo->query('SELECT COUNT(*) FROM contacts WHERE client_id=10')->fetchColumn() === 1
    && $pdo->query('SELECT name FROM clients WHERE id=10')->fetchColumn() === 'Local override'
    && (int)$pdo->query('SELECT COUNT(*) FROM suite_customer_sync_events')->fetchColumn() === 1);
// The form saw no binding; one arrived before POST. The deletion re-reads under the tenant lock.
$insert->execute([2,1,13,'33333333-3333-4333-8333-333333333333',1,'Late binding','active','44444444-4444-4444-8444-444444444444','2026-10-11 12:00:00']);
operational_check('stale form cannot remove newly bound contacts', client_operational_delete_local($pdo, 1, 13) === 'bound'
    && (int)$pdo->query('SELECT COUNT(*) FROM contacts WHERE client_id=13')->fetchColumn() === 1);
$pdo->exec('INSERT INTO tickets VALUES(1,11)');
operational_check('fresh ticket count refuses stale zero-ticket form', client_operational_delete_local($pdo, 1, 11) === 'tickets');
operational_check('other tenant contacts retained', client_operational_delete_local($pdo, 1, 12) === 'missing'
    && (int)$pdo->query('SELECT COUNT(*) FROM contacts WHERE client_id=12')->fetchColumn() === 1);
$pdo->exec('DELETE FROM tickets WHERE id=1');
operational_check('unbound local removal still works', client_operational_delete_local($pdo, 1, 11) === 'deleted'
    && (int)$pdo->query('SELECT COUNT(*) FROM contacts WHERE client_id=11')->fetchColumn() === 0);
$pdo->exec("CREATE TRIGGER refuse_client BEFORE DELETE ON clients WHEN OLD.id=14 BEGIN SELECT RAISE(ABORT,'protected client'); END");
try { client_operational_delete_local($pdo, 1, 14); operational_check('must report refusal', false); }
catch (PDOException) {}
operational_check('client deletion failure rolls back contact removal', !$pdo->inTransaction()
    && (int)$pdo->query('SELECT COUNT(*) FROM contacts WHERE client_id=14')->fetchColumn() === 1
    && (int)$pdo->query('SELECT COUNT(*) FROM clients WHERE id=14')->fetchColumn() === 1);
echo "PASS: $checks operational context/removal checks\n";
