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
// Directory and device links: the provider + customer UUID route, from the binding tenant's own slug.
// The customer UUID has hex letters, so its uppercase form is a genuinely different, noncanonical input.
$deviceCustomer = '5a5b5c5d-5e5f-4a5b-8c5d-5e5f5a5b5c5d';
operational_check('uppercase fixture differs from the canonical lowercase UUID', strtoupper($deviceCustomer) !== $deviceCustomer);
operational_check('device URL is the exact provider + UUID Milepost route',
    client_operational_device_url('8west', $deviceCustomer)
        === 'https://support.8westit.com/client.php?provider=8west&customer_id=' . $deviceCustomer);
foreach ([['8West', $deviceCustomer], ['8west&id=1', $deviceCustomer], ['', $deviceCustomer],
          ['8west', strtoupper($deviceCustomer)], ['8west', $deviceCustomer . '&id=1'],
          ['8west', '55555555-5555-1555-8555-555555555555']] as [$badProvider, $badCustomer]) {
    operational_check('device URL refuses a noncanonical provider or customer', client_operational_device_url($badProvider, $badCustomer) === null);
    operational_check('billing URL refuses a noncanonical provider or customer', client_operational_billing_url($badProvider, $badCustomer) === null);
}
// CUT2 (A227): Coastmark's fixed provider + lowercase UUIDv4 route, built only from validated values.
operational_check('billing URL is the exact fixed provider + UUID Coastmark route',
    client_operational_billing_url('8west', $deviceCustomer)
        === 'https://coastmark.8westit.com/clients?provider=8west&customer_id=' . $deviceCustomer
    && client_operational_billing_url('other-msp', '77777777-7777-4777-8777-777777777777')
        === 'https://coastmark.8westit.com/clients?provider=other-msp&customer_id=77777777-7777-4777-8777-777777777777'
    && client_operational_billing_url(str_repeat('m', 64), $deviceCustomer)
        === 'https://coastmark.8westit.com/clients?provider=' . str_repeat('m', 64) . '&customer_id=' . $deviceCustomer);
foreach ([['-8west', $deviceCustomer], [str_repeat('m', 65), $deviceCustomer], ["8west\n", $deviceCustomer],
          ['8west', $deviceCustomer . "\n"], ['8west', str_replace('-', '', $deviceCustomer)],
          ['8west', '5a5b5c5d-5e5f-4a5b-7c5d-5e5f5a5b5c5d'], ['8west', '']] as [$badProvider, $badCustomer]) {
    operational_check('billing URL refuses a malformed slug or customer UUID', client_operational_billing_url($badProvider, $badCustomer) === null);
}
$directoryPdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$directoryPdo->exec('CREATE TABLE tenants(id INTEGER PRIMARY KEY, slug TEXT);
CREATE TABLE clients(id INTEGER PRIMARY KEY, tenant_id INTEGER, name TEXT);
CREATE TABLE suite_customer_sync_bindings(id INTEGER PRIMARY KEY, tenant_id INTEGER, client_id INTEGER, customer_id TEXT, source_version INTEGER, display_name TEXT, status TEXT, last_event_id TEXT, last_occurred_at TEXT);
CREATE TABLE suite_customer_sync_events(id INTEGER PRIMARY KEY, binding_id INTEGER, tenant_id INTEGER, client_id INTEGER, customer_id TEXT, source_version INTEGER, display_name TEXT, status TEXT, event_id TEXT, occurred_at TEXT);
INSERT INTO tenants VALUES(1,"8west"),(2,"other-msp");
INSERT INTO clients VALUES(20,1,"Local A"),(21,1,"Unbound"),(22,2,"Other MSP client"),(23,1,"Drifted"),(24,1,"Inactive");');
$directoryBinding = $directoryPdo->prepare('INSERT INTO suite_customer_sync_bindings VALUES(?,?,?,?,?,?,?,?,?)');
$directoryEvent = $directoryPdo->prepare('INSERT INTO suite_customer_sync_events VALUES(?,?,?,?,?,?,?,?,?,?)');
$directoryRows = [
    [1, 1, 20, $deviceCustomer, 3, 'Shared A', 'active', '66666666-6666-4666-8666-666666666666'],
    [2, 2, 22, '77777777-7777-4777-8777-777777777777', 1, 'Other shared', 'active', '88888888-8888-4888-8888-888888888888'],
    [3, 1, 23, '99999999-9999-4999-8999-999999999999', 2, 'Drifted shared', 'active', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'],
    [4, 1, 24, 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 2, 'Inactive shared', 'inactive', 'cccccccc-cccc-4ccc-8ccc-cccccccccccc'],
];
foreach ($directoryRows as [$bindingId, $bindingTenant, $bindingClient, $bindingCustomer, $bindingVersion, $bindingName, $bindingStatus, $bindingEvent]) {
    $directoryBinding->execute([$bindingId, $bindingTenant, $bindingClient, $bindingCustomer, $bindingVersion, $bindingName,
        $bindingStatus, $bindingEvent, '2026-10-23 12:00:00']);
    $directoryEvent->execute([$bindingId, $bindingId, $bindingTenant, $bindingClient, $bindingCustomer,
        $bindingId === 3 ? $bindingVersion - 1 : $bindingVersion, $bindingName, $bindingStatus, $bindingEvent, '2026-10-23 12:00:00']);
}
$houseDirectory = client_operational_directory($directoryPdo, 1);
operational_check('directory maps only this tenant\'s bound clients by local id',
    array_keys($houseDirectory) === [20, 23, 24]);
operational_check('directory carries shared name, state and the provider-scoped device and billing routes',
    $houseDirectory[20]['available'] && $houseDirectory[20]['display_name'] === 'Shared A'
    && $houseDirectory[20]['status'] === 'active' && $houseDirectory[20]['source_version'] === 3
    && $houseDirectory[20]['device_url'] === client_operational_device_url('8west', $deviceCustomer)
    && $houseDirectory[20]['billing_url'] === 'https://coastmark.8westit.com/clients?provider=8west&customer_id=' . $deviceCustomer);
operational_check('a shared-inactive client keeps its own billing route to its retained accounting history',
    $houseDirectory[24]['available'] && $houseDirectory[24]['status'] === 'inactive'
    && $houseDirectory[24]['billing_url'] === client_operational_billing_url('8west', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'));
operational_check('directory keeps a drifted receipt bound but unavailable, with no links',
    $houseDirectory[23] === ['bound' => true, 'available' => false]);
operational_check('directory and single-client context agree exactly',
    client_operational_context($directoryPdo, 1, 20) === $houseDirectory[20]
    && client_operational_context($directoryPdo, 1, 24) === $houseDirectory[24]
    && client_operational_context($directoryPdo, 1, 21) === ['bound' => false, 'available' => false]);
operational_check('only a successful lookup with no binding makes a row unbound',
    client_operational_directory_entry($houseDirectory, 21) === ['bound' => false, 'available' => false]
    && client_operational_directory_entry($houseDirectory, 23) === ['bound' => true, 'available' => false]
    && client_operational_directory_entry($houseDirectory, 20) === $houseDirectory[20]);
// A48/A51: the canonical Support target, its fixed ID launcher hop and the provider + UUID resolver.
$supportTarget = '/client.php?provider=8west&customer_id=' . $deviceCustomer;
operational_check('the canonical Support target is exactly the provider + UUID local path',
    client_operational_support_target($supportTarget) === $supportTarget
    && client_operational_support_target('/client.php?provider=' . str_repeat('m', 64) . '&customer_id=' . $deviceCustomer) !== null);
operational_check('only a canonical target builds the fixed 8 West ID launcher hop',
    client_operational_support_launcher_url($supportTarget)
        === 'https://id.8westit.com/launch.php?product=safeharbor&return=' . rawurlencode($supportTarget)
    && client_operational_support_launcher_url('/tickets.php') === null && client_operational_support_launcher_url(null) === null);
foreach (['client.php?provider=8west&customer_id=' . $deviceCustomer, '/client.php?id=20',
    '/client.php?provider=8west&customer_id=' . strtoupper($deviceCustomer),
    '/client.php?provider=8West&customer_id=' . $deviceCustomer, '/client.php?provider=-8west&customer_id=' . $deviceCustomer,
    '/client.php?provider=' . str_repeat('m', 65) . '&customer_id=' . $deviceCustomer,
    '/client.php?customer_id=' . $deviceCustomer . '&provider=8west',
    '/client.php?provider=8west&provider=8west&customer_id=' . $deviceCustomer,
    '/client.php?provider=8west&customer_id=' . $deviceCustomer . '&id=20',
    '/client.php?id=20&provider=8west&customer_id=' . $deviceCustomer,
    '/client.php?provider=8west&customer_id=' . $deviceCustomer . '#top',
    '/client.php?provider=8west%26customer_id%3D' . $deviceCustomer,
    "/client.php?provider=8west&customer_id=$deviceCustomer\n",
    '/client.php?provider=8west&customer_id=55555555-5555-1555-8555-555555555555',
    'https://safeharbor.8westit.com/client.php?provider=8west&customer_id=' . $deviceCustomer, ['x'], 7] as $badTarget) {
    operational_check('a noncanonical Support target is refused', client_operational_support_target($badTarget) === null
        && client_operational_support_launcher_url($badTarget) === null);
}
$supportQuery = static fn(string $provider, string $customer): string => 'provider=' . $provider . '&customer_id=' . $customer;
operational_check('the Support route resolves the exact UUID to this tenant\'s one bound client, retained inactive history too',
    client_operational_support_client($directoryPdo, 1, $supportQuery('8west', $deviceCustomer)) === 20
    && client_operational_support_client($directoryPdo, 1, $supportQuery('8west', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb')) === 24
    && client_operational_support_client($directoryPdo, 2, $supportQuery('other-msp', '77777777-7777-4777-8777-777777777777')) === 22);
foreach ([
    'drifted receipt' => [1, $supportQuery('8west', '99999999-9999-4999-8999-999999999999')],
    'another tenant\'s UUID' => [1, $supportQuery('8west', '77777777-7777-4777-8777-777777777777')],
    'provider is not this tenant' => [1, $supportQuery('other-msp', $deviceCustomer)],
    'other tenant asserting the house' => [2, $supportQuery('8west', $deviceCustomer)],
    'unknown UUID' => [1, $supportQuery('8west', 'dddddddd-dddd-4ddd-8ddd-dddddddddddd')],
    'uppercase UUID' => [1, $supportQuery('8west', strtoupper($deviceCustomer))],
    'reordered' => [1, 'customer_id=' . $deviceCustomer . '&provider=8west'],
    'mixed id' => [1, $supportQuery('8west', $deviceCustomer) . '&id=20'],
    'leading id' => [1, 'id=20&' . $supportQuery('8west', $deviceCustomer)],
    'duplicate' => [1, 'provider=8west&provider=8west&customer_id=' . $deviceCustomer],
    'encoded' => [1, 'provider=8west&customer_id=' . str_replace('-', '%2D', $deviceCustomer)],
    'empty' => [1, ''],
    'no tenant' => [0, $supportQuery('8west', $deviceCustomer)],
] as $label => [$supportTenant, $query]) {
    operational_check("the Support route is the same miss for every refusal: $label",
        client_operational_support_client($directoryPdo, $supportTenant, $query) === null);
}
$directoryPdo->exec("INSERT INTO suite_customer_sync_bindings VALUES(9,1,21,'$deviceCustomer',3,'Shared A','active','66666666-6666-4666-8666-666666666666','2026-10-23 12:00:00')");
operational_check('an ambiguous UUID with two bindings resolves to nothing',
    client_operational_support_client($directoryPdo, 1, $supportQuery('8west', $deviceCustomer)) === null);
$directoryPdo->exec('DELETE FROM suite_customer_sync_bindings WHERE id=9');
$supportPage = (string)file_get_contents(__DIR__ . '/../public/client.php');
operational_check('the client page takes the Support route for any provider or customer_id parameter, after sign-in, with a 404 miss',
    preg_match('/\$user = require_login\(\);[\s\S]*\$supportTarget = isset\(\$_GET\[\'provider\'\]\) \|\| isset\(\$_GET\[\'customer_id\'\]\);/', $supportPage) === 1
    && str_contains($supportPage, "client_operational_support_client(db(), tenant_id(), (string)(\$_SERVER['QUERY_STRING'] ?? '')) ?? 0")
    && str_contains($supportPage, 'if ($supportTarget) http_response_code(404);')
    && str_contains($supportPage, "SELECT * FROM clients WHERE id = ? AND tenant_id = ?"));
$mspDirectory = client_operational_directory($directoryPdo, 2);
operational_check('another MSP tenant gets only its own clients and its own provider routes',
    array_keys($mspDirectory) === [22]
    && $mspDirectory[22]['device_url'] === client_operational_device_url('other-msp', '77777777-7777-4777-8777-777777777777')
    && $mspDirectory[22]['billing_url'] === client_operational_billing_url('other-msp', '77777777-7777-4777-8777-777777777777'));
operational_check('an unavailable tenant slug removes only the device and billing links, not the shared context',
    client_operational_context($pdo, 1, 10)['available'] && client_operational_context($pdo, 1, 10)['device_url'] === null
    && client_operational_context($pdo, 1, 10)['billing_url'] === null);
$directoryPdo->exec("UPDATE tenants SET slug='Other-MSP' WHERE id=2");
$malformedSlug = client_operational_context($directoryPdo, 2, 22);
operational_check('a malformed tenant slug builds neither link and keeps the shared context',
    $malformedSlug['available'] && $malformedSlug['display_name'] === 'Other shared'
    && $malformedSlug['device_url'] === null && $malformedSlug['billing_url'] === null);
$directoryPdo->exec("UPDATE tenants SET slug='other-msp' WHERE id=2");
// Client 20 is genuinely bound, but its receipt lookup fails (the receipt table is unreadable).
$failingPdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$failingPdo->exec('CREATE TABLE tenants(id INTEGER PRIMARY KEY, slug TEXT);
CREATE TABLE clients(id INTEGER PRIMARY KEY, tenant_id INTEGER, name TEXT);
CREATE TABLE suite_customer_sync_bindings(id INTEGER PRIMARY KEY, tenant_id INTEGER, client_id INTEGER, customer_id TEXT, source_version INTEGER, display_name TEXT, status TEXT, last_event_id TEXT, last_occurred_at TEXT);
INSERT INTO tenants VALUES(1,"8west");
INSERT INTO clients VALUES(20,1,"Local A"),(21,1,"Unbound");');
$failingPdo->prepare('INSERT INTO suite_customer_sync_bindings VALUES(?,?,?,?,?,?,?,?,?)')
    ->execute([1, 1, 20, $deviceCustomer, 3, 'Shared A', 'active', '66666666-6666-4666-8666-666666666666', '2026-10-23 12:00:00']);
$failedDirectory = client_operational_directory($failingPdo, 1);
operational_check('a failed directory lookup is unknown, not an empty map of unbound clients', $failedDirectory === null);
operational_check('a failed lookup never labels the actually bound client local, and fabricates no links',
    client_operational_directory_entry($failedDirectory, 20) === ['bound' => null, 'available' => false]
    && client_operational_directory_entry($failedDirectory, 21) === ['bound' => null, 'available' => false]
    && client_operational_context($failingPdo, 1, 20) === ['bound' => null, 'available' => false]);
$clientsPage = (string)file_get_contents(__DIR__ . '/../public/clients.php');
$clientPage = (string)file_get_contents(__DIR__ . '/../public/client.php');
operational_check('directory shows Support, active-only Devices, a linked or verified pending client\'s own Billing and the generic entry otherwise',
    str_contains($clientsPage, 'client_operational_directory(db(), $tenantId)')
    && str_contains($clientsPage, 'client_operational_directory_entry($shared, (int)$c[\'id\'])')
    && str_contains($clientsPage, '>Support</a>') && str_contains($clientsPage, 'Devices ↗')
    && str_contains($clientsPage, "\$op['status'] === 'active' && is_string(\$op['device_url'] ?? null)")
    && str_contains($clientsPage, "\$billingUrl = \$op['available'] && is_string(\$op['billing_url'] ?? null) ? \$op['billing_url'] : null;")
    && str_contains($clientsPage, '$pendingBilling = $provider !== null ? client_operational_billing_url($provider, (string)$customerId) : null;')
    && substr_count($clientsPage, 'href="https://coastmark.8westit.com/auth/suite"') === 2
    && !str_contains($clientsPage . $clientPage, 'coastmark.8westit.com/clients')
    && str_contains($clientsPage, 'class="crow" data-client-id=')
    && str_contains($clientPage, 'client_operational_context(db(), tenant_id(), $id)')
    && str_contains($clientPage, "\$billingUrl = \$operational['available'] && is_string(\$operational['billing_url'] ?? null)")
    && substr_count($clientPage, "h(\$billingUrl ?? 'https://coastmark.8westit.com/auth/suite')") === 2);
operational_check('Billing reads the receipt-agreed route only in that one assignment, with no active-state gate; only the helper builds it',
    substr_count($clientsPage, "['billing_url']") === 2 && substr_count($clientPage, "['billing_url']") === 2
    && substr_count($clientsPage, 'client_operational_billing_url(') === 1 && !str_contains($clientPage, 'client_operational_billing_url('));
operational_check('both pages read the request-current directory once and classify only through the helper',
    substr_count($clientsPage, 'client_operational_roster(db(), $user)') === 1
    && str_contains($clientsPage, 'client_operational_roster_classify($shared, client_operational_bound_customers(db(), $tenantId), $roster)')
    && substr_count($clientPage, 'client_operational_roster(db(), $user)') === 1
    && !preg_match('/INSERT|UPDATE|DELETE/', (string)preg_replace('/\A[\s\S]*?\$shared = client_operational_directory/', '', $clientsPage)));
operational_check('only a proven-unbound row or page says local; unknown and drifted say unavailable',
    preg_match("/elseif \\(\\\$op\\['bound'\\] !== false\\): \\?>\\s*<span class=\"crow-shared\">Shared details unavailable<\\/span>\\s*<\\?php else: \\?>\\s*<span class=\"crow-shared\">Local to Safeharbor/", $clientsPage) === 1
    && preg_match("/elseif \\(\\\$operational\\['bound'\\] !== false\\): \\?>\\s*<p class=\"client-shared\">Shared details are temporarily unavailable/", $clientPage) === 1);

// The request-current Milepost directory: principal, issuer, strict reply, classification, and no writes.
$rosterPdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$rosterPdo->exec('CREATE TABLE tenants(id INTEGER PRIMARY KEY, slug TEXT);
CREATE TABLE users(id INTEGER PRIMARY KEY, tenant_id INTEGER, suite_subject TEXT, is_active INTEGER);
CREATE TABLE suite_managed_providers(tenant_id INTEGER PRIMARY KEY, issuer_tenant_id INTEGER, provider_slug TEXT);
CREATE TABLE clients(id INTEGER PRIMARY KEY, tenant_id INTEGER, name TEXT);
CREATE TABLE suite_customer_sync_bindings(id INTEGER PRIMARY KEY, tenant_id INTEGER, client_id INTEGER, customer_id TEXT, source_version INTEGER, display_name TEXT, status TEXT, last_event_id TEXT, last_occurred_at TEXT);
CREATE TABLE suite_customer_sync_events(id INTEGER PRIMARY KEY, binding_id INTEGER, tenant_id INTEGER, client_id INTEGER, customer_id TEXT, source_version INTEGER, display_name TEXT, status TEXT, event_id TEXT, occurred_at TEXT);
INSERT INTO tenants VALUES(1,"8west"),(2,"other-msp"),(3,"quiet-msp");
INSERT INTO users VALUES(5,1,"t70u5",1),(6,1,NULL,1),(7,2,"t81u7",1),(8,1,"t70u8",0),(9,3,"t90u9",1);
INSERT INTO suite_managed_providers VALUES(2,81,"other-msp");
INSERT INTO clients VALUES(30,1,"Harbor"),(31,1,"Canary"),(32,1,"Inactive"),(33,1,"Removed"),(34,1,"Drifted"),(35,1,"Intake");');
$rosterUuid = static fn(int $n): string => "{$n}a{$n}b{$n}c{$n}d-{$n}e{$n}f-4a{$n}b-8c{$n}d-{$n}e{$n}f{$n}a{$n}b{$n}c{$n}d";
$rosterEvent = static fn(int $n): string => sprintf('e2000000-0000-4000-8000-%012d', $n);
$rosterBinding = $rosterPdo->prepare('INSERT INTO suite_customer_sync_bindings VALUES(?,?,?,?,?,?,?,?,?)');
$rosterReceipt = $rosterPdo->prepare('INSERT INTO suite_customer_sync_events VALUES(?,?,?,?,?,?,?,?,?,?)');
foreach ([[1, 30, 1, 2, 'Harbor old', 'inactive', 2], [2, 31, 2, 1, 'Canary', 'active', 1], [3, 32, 3, 1, 'Inactive', 'active', 1],
          [4, 33, 4, 1, 'Removed', 'active', 1], [5, 34, 6, 2, 'Drifted', 'active', 1]] as [$id, $client, $n, $version, $name, $status, $receiptVersion]) {
    $rosterBinding->execute([$id, 1, $client, $rosterUuid($n), $version, $name, $status, $rosterEvent($id), '2026-10-23 12:00:00']);
    $rosterReceipt->execute([$id, $id, 1, $client, $rosterUuid($n), $receiptVersion, $name, $status, $rosterEvent($id), '2026-10-23 12:00:00']);
}
$houseUser = ['id' => 5, 'tenant_id' => 1, 'suite_subject' => 't70u5', 'is_active' => 1];
$houseClaims = ['sub' => 't70u5', '8west:tenant' => '8west', '8west:tenant_id' => '70'];
$houseBindings = ['8west' => 'ewid-t70'];
$token = 'synthetic.suite.token';
$housePrincipal = ['user_id' => 5, 'tenant_id' => 1, 'subject' => 't70u5', 'provider' => '8west', 'issuer' => 70];
operational_check('the matching account, cookie subject and tenant make the roster principal',
    client_operational_roster_principal($rosterPdo, $houseUser, $houseClaims, $token, $houseBindings) === $housePrincipal);
foreach ([
    'a local-only account cannot borrow a browser cookie' => [['id' => 6, 'tenant_id' => 1, 'suite_subject' => null, 'is_active' => 1], $houseClaims, $token],
    'a cookie for another person is refused' => [$houseUser, ['sub' => 't70u8'] + $houseClaims, $token],
    'a cookie for another tenant is refused' => [$houseUser, ['8west:tenant' => 'other-msp'] + $houseClaims, $token],
    'an inactive account is refused on its current row' => [['id' => 8, 'tenant_id' => 1, 'suite_subject' => 't70u8', 'is_active' => 1], ['sub' => 't70u8'] + $houseClaims, $token],
    'a missing or malformed token is refused' => [$houseUser, $houseClaims, 'not-a-token'],
    'no verified claims means no principal' => [$houseUser, null, $token],
] as $label => [$candidateUser, $candidateClaims, $candidateToken]) {
    operational_check($label, client_operational_roster_principal($rosterPdo, $candidateUser, $candidateClaims, $candidateToken, $houseBindings) === null);
}
operational_check('the issuer agrees across the claim and the house mapping, or either alone',
    client_operational_roster_issuer($rosterPdo, 1, '8west', $houseClaims, $houseBindings) === 70
    && client_operational_roster_issuer($rosterPdo, 1, '8west', ['sub' => 't70u5'], $houseBindings) === 70
    && client_operational_roster_issuer($rosterPdo, 1, '8west', $houseClaims, null) === 70);
operational_check('a managed provider issuer comes from its own provider row and must agree with the claim',
    client_operational_roster_issuer($rosterPdo, 2, 'other-msp', ['8west:tenant_id' => '81'], null) === 81
    && client_operational_roster_issuer($rosterPdo, 2, 'other-msp', [], null) === 81
    && client_operational_roster_issuer($rosterPdo, 2, 'other-msp', ['8west:tenant_id' => '82'], null) === null
    && client_operational_roster_issuer($rosterPdo, 2, 'other-provider-slug', [], null) === null);
operational_check('no source, a conflicting mapping or a malformed claim is unknown, never a numeric coincidence',
    client_operational_roster_issuer($rosterPdo, 3, 'quiet-msp', [], ['quiet-msp' => 'ewid-t3']) === null
    && client_operational_roster_issuer($rosterPdo, 1, '8west', $houseClaims, ['8west' => 'ewid-t71']) === null
    && client_operational_roster_issuer($rosterPdo, 1, '8west', ['8west:tenant_id' => '070'], null) === null
    && client_operational_roster_issuer($rosterPdo, 1, '8west', ['8west:tenant_id' => 70.0], null) === null
    && client_operational_roster_principal($rosterPdo, ['id' => 9, 'tenant_id' => 3, 'suite_subject' => 't90u9', 'is_active' => 1],
        ['sub' => 't90u9', '8west:tenant' => 'quiet-msp'], $token, null) === null);

$rosterItems = [
    ['customer_id' => $rosterUuid(1), 'source_version' => 3, 'event_id' => $rosterEvent(11), 'display_name' => 'Harbor', 'status' => 'active', 'client_exists' => true],
    ['customer_id' => $rosterUuid(3), 'source_version' => 2, 'event_id' => $rosterEvent(13), 'display_name' => 'Inactive', 'status' => 'inactive', 'client_exists' => true],
    ['customer_id' => $rosterUuid(4), 'source_version' => 1, 'event_id' => $rosterEvent(14), 'display_name' => 'Removed', 'status' => 'active', 'client_exists' => false],
    ['customer_id' => $rosterUuid(5), 'source_version' => 1, 'event_id' => $rosterEvent(15), 'display_name' => 'Valley', 'status' => 'active', 'client_exists' => true],
    ['customer_id' => $rosterUuid(6), 'source_version' => 2, 'event_id' => $rosterEvent(16), 'display_name' => 'Drifted', 'status' => 'active', 'client_exists' => true],
];
$nonce = '0b0c0d0e-1f2a-4b3c-8d4e-5f6a7b8c9d0e';
$sentAt = 1_900_000_000;
$reply = static fn(array $changes = [], ?array $customers = null): string => json_encode(array_replace([
    'ok' => true, 'contract' => 'suite-client-directory-v1', 'actor_subject' => 't70u5', 'provider_tenant_id' => 44,
    'provider_tenant_slug' => '8west', 'issuer_suite_tenant_id' => 70, 'read_nonce' => $nonce, 'read_at' => $sentAt,
    'customers' => $customers ?? $rosterItems,
], $changes), JSON_UNESCAPED_SLASHES);
$decoded = client_operational_roster_decode($reply(), $housePrincipal, $nonce, $sentAt, $sentAt + 2);
operational_check('a complete exact reply decodes keyed by customer UUID',
    is_array($decoded) && $decoded['read_at'] === $sentAt && array_keys($decoded['customers']) === array_column($rosterItems, 'customer_id'));
operational_check('a verified empty directory is a real empty roster',
    client_operational_roster_decode($reply([], []), $housePrincipal, $nonce, $sentAt, $sentAt)['customers'] === []);
$escapedDuplicate = substr($reply(), 0, -1) . ',"\\u006fk":true}';
operational_check('the escaped duplicate-key fixture really spells the key with a JSON escape',
    str_ends_with($escapedDuplicate, ',"\\u006fk":true}'));
$singleItem = $reply([], [$rosterItems[0]]);
$objectRoster = str_replace('"customers":[', '"customers":{"0":', substr($singleItem, 0, -2)) . '}}';
$listItem = json_encode(array_replace(json_decode($singleItem, true), ['customers' => [array_values($rosterItems[0])]]),
    JSON_UNESCAPED_SLASHES);
operational_check('the raw JSON type fixtures are what they claim',
    str_ends_with($reply([], []), '"customers":[]}') && str_contains($objectRoster, '"customers":{"0":{')
    && str_ends_with($objectRoster, '}}}') && str_contains($listItem, '"customers":[["'));
$itemChange = static function (int $index, array $changes) use ($rosterItems): array {
    $items = $rosterItems; $items[$index] = array_replace($items[$index], $changes); return $items;
};
$withoutKey = $rosterItems; unset($withoutKey[0]['client_exists']);
$extraKey = $rosterItems; $extraKey[0]['tenant_id'] = 1;
foreach ([
    'ok false' => $reply(['ok' => false]),
    'another contract' => $reply(['contract' => 'suite-customer-metadata-v1']),
    'another subject' => $reply(['actor_subject' => 't70u8']),
    'another provider' => $reply(['provider_tenant_slug' => 'other-msp']),
    'another issuer' => $reply(['issuer_suite_tenant_id' => 71]),
    'a numeric-string issuer' => $reply(['issuer_suite_tenant_id' => '70']),
    'a zero provider tenant' => $reply(['provider_tenant_id' => 0]),
    'another nonce' => $reply(['read_nonce' => '1b0c0d0e-1f2a-4b3c-8d4e-5f6a7b8c9d0e']),
    'a string read_at' => $reply(['read_at' => '2030-03-17T17:46:40Z']),
    'read_at before the window' => $reply(['read_at' => $sentAt - 61]),
    'read_at after the window' => $reply(['read_at' => $sentAt + 2 + 61]),
    'a duplicate top-level key' => substr($reply(), 0, -1) . ',"ok":true}',
    'a duplicate item key' => str_replace('"display_name":"Harbor"', '"display_name":"Harbor","display_name":"Other"', $reply()),
    'an escaped duplicate key' => $escapedDuplicate,
    'customers as an empty JSON object' => str_replace('"customers":[]', '"customers":{}', $reply([], [])),
    'customers as a numeric-keyed JSON object' => $objectRoster,
    'a customer as a JSON array' => $listItem,
    'an extra top-level key' => $reply(['customer_count' => 5]),
    'an extra item key' => $reply([], $extraKey),
    'a missing item key' => $reply([], $withoutKey),
    'unsorted customers' => $reply([], array_reverse($rosterItems)),
    'duplicate customers' => $reply([], [$rosterItems[0], $rosterItems[0]]),
    'an uppercase customer UUID' => $reply([], $itemChange(0, ['customer_id' => strtoupper($rosterUuid(1))])),
    'version zero' => $reply([], $itemChange(0, ['source_version' => 0])),
    'version over the history cap' => $reply([], $itemChange(0, ['source_version' => 1025])),
    'a non-v4 event' => $reply([], $itemChange(0, ['event_id' => '11111111-1111-1111-8111-111111111111'])),
    'a control character in the name' => $reply([], $itemChange(0, ['display_name' => "Harbor\x07"])),
    'a padded name' => $reply([], $itemChange(0, ['display_name' => ' Harbor'])),
    'an unknown status' => $reply([], $itemChange(0, ['status' => 'removed'])),
    'a non-boolean client_exists' => $reply([], $itemChange(0, ['client_exists' => 1])),
    'more than 256 customers' => $reply([], array_map(static fn(int $i): array => ['customer_id' => sprintf('%08x-0000-4000-8000-000000000000', $i),
        'source_version' => 1, 'event_id' => $rosterEvent($i), 'display_name' => 'C' . $i, 'status' => 'active', 'client_exists' => true], range(1, 257))),
    'a truncated body' => substr($reply(), 0, -10),
    'an oversized body' => str_pad($reply(), 524289, ' '),
    'a JSON list' => '[' . $reply() . ']',
    'an empty body' => '',
] as $label => $badBody) {
    operational_check('the whole roster is unavailable for ' . $label,
        client_operational_roster_decode($badBody, $housePrincipal, $nonce, $sentAt, $sentAt + 2) === null);
}

$rosterTables = static function () use ($rosterPdo): string {
    $tables = [];
    foreach (['tenants', 'users', 'suite_managed_providers', 'clients', 'suite_customer_sync_bindings', 'suite_customer_sync_events'] as $table) {
        $tables[$table] = $rosterPdo->query('SELECT * FROM ' . $table . ' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
    }
    return hash('sha256', json_encode($tables, JSON_THROW_ON_ERROR));
};
$beforeRoster = $rosterTables();
$requests = [];
$serve = static function (int $status, ?callable $body = null, ?callable $during = null) use (&$requests): callable {
    return static function (string $sentToken, string $request) use ($status, $body, $during, &$requests): array {
        $requests[] = [$sentToken, $request];
        if ($during !== null) $during();
        $decodedRequest = json_decode($request, true, 4, JSON_THROW_ON_ERROR);
        return ['status' => $status, 'body' => $body === null ? '{"ok":false,"reason":"directory_unavailable"}' : $body($decodedRequest)];
    };
};
$currentReply = static fn(array $request, ?array $customers = null): string => json_encode([
    'ok' => true, 'contract' => 'suite-client-directory-v1', 'actor_subject' => 't70u5', 'provider_tenant_id' => 44,
    'provider_tenant_slug' => '8west', 'issuer_suite_tenant_id' => 70, 'read_nonce' => $request['read_nonce'],
    'read_at' => time(), 'customers' => $customers ?? $rosterItems,
], JSON_UNESCAPED_SLASHES);
$roster = client_operational_roster($rosterPdo, $houseUser, $serve(200, $currentReply), $houseClaims, $token, $houseBindings);
$sentRequest = json_decode($requests[0][1], true, 4, JSON_THROW_ON_ERROR);
operational_check('one exact request with this account\'s own token and a fresh v4 nonce',
    count($requests) === 1 && $requests[0][0] === $token
    && array_keys($sentRequest) === ['contract', 'action', 'expected_subject', 'read_nonce']
    && $sentRequest['contract'] === 'suite-client-directory-v1' && $sentRequest['action'] === 'read'
    && $sentRequest['expected_subject'] === 't70u5'
    && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $sentRequest['read_nonce']) === 1);
operational_check('a verified reply is available with every customer', $roster['available'] === true && count($roster['customers']) === 5);
$requests = [];
$second = client_operational_roster($rosterPdo, $houseUser, $serve(200, $currentReply), $houseClaims, $token, $houseBindings);
operational_check('each request uses its own nonce', $second['available'] === true
    && json_decode($requests[0][1], true)['read_nonce'] !== $sentRequest['read_nonce']);
foreach ([
    'a 503 refusal' => $serve(503),
    'a non-200 success body' => $serve(201, $currentReply),
    'a timeout or transport failure' => static function (): array { throw new RuntimeException('timed out'); },
    'a malformed transport result' => static fn(): array => ['status' => 200],
    'a subject that changes after the read' => $serve(200, $currentReply, static function () use ($rosterPdo): void {
        $rosterPdo->exec('UPDATE users SET suite_subject="t70u99" WHERE id=5');
    }),
] as $label => $transport) {
    operational_check('the roster is unavailable after ' . $label,
        client_operational_roster($rosterPdo, $houseUser, $transport, $houseClaims, $token, $houseBindings) === ['available' => false]);
    $rosterPdo->exec('UPDATE users SET suite_subject="t70u5" WHERE id=5');
}
operational_check('a provider slug that changes after the read is unavailable',
    client_operational_roster($rosterPdo, $houseUser, $serve(200, $currentReply, static function () use ($rosterPdo): void {
        $rosterPdo->exec('UPDATE tenants SET slug="8west-renamed" WHERE id=1');
    }), $houseClaims, $token, $houseBindings) === ['available' => false]);
$rosterPdo->exec('UPDATE tenants SET slug="8west" WHERE id=1');
$requests = [];
operational_check('no principal means no request at all',
    client_operational_roster($rosterPdo, ['id' => 6, 'tenant_id' => 1, 'suite_subject' => null, 'is_active' => 1],
        $serve(200, $currentReply), $houseClaims, $token, $houseBindings) === ['available' => false] && $requests === []);

$rosterDirectory = client_operational_directory($rosterPdo, 1);
$bound = client_operational_bound_customers($rosterPdo, 1);
$classified = client_operational_roster_classify($rosterDirectory, $bound, $roster);
operational_check('primary is a linked client the roster lists active and existing, even when its local receipt lags',
    $classified['state'] === 'verified' && array_keys($classified['primary']) === [30]
    && $classified['primary'][30]['source_version'] === 3 && $rosterDirectory[30]['status'] === 'inactive');
operational_check('a linked active canary absent from the roster, inactive, removed, drifted and local clients stay retained',
    !array_intersect(array_keys($classified['primary']), [31, 32, 33, 34, 35])
    && $rosterDirectory[31]['status'] === 'active' && $rosterDirectory[34] === ['bound' => true, 'available' => false]);
operational_check('an active Milepost business with no local binding is pending, never created; a drifted binding is not pending',
    array_keys($classified['pending']) === [$rosterUuid(5)] && $classified['pending'][$rosterUuid(5)]['display_name'] === 'Valley'
    && (int)$rosterPdo->query('SELECT COUNT(*) FROM clients')->fetchColumn() === 6);
operational_check('a verified empty roster classifies nothing as primary and differs from unavailable',
    client_operational_roster_classify($rosterDirectory, $bound, ['available' => true, 'read_at' => time(), 'customers' => []])
        === ['state' => 'empty', 'primary' => [], 'pending' => []]
    && client_operational_roster_classify($rosterDirectory, $bound, ['available' => false])
        === ['state' => 'unavailable', 'primary' => [], 'pending' => []]);
operational_check('a failed local lookup classifies nothing',
    client_operational_roster_classify(null, $bound, $roster)['state'] === 'unavailable'
    && client_operational_roster_classify($rosterDirectory, null, $roster)['state'] === 'unavailable');
$otherRoster = client_operational_roster($rosterPdo, ['id' => 7, 'tenant_id' => 2, 'suite_subject' => 't81u7', 'is_active' => 1],
    $serve(200, $currentReply), ['sub' => 't81u7', '8west:tenant' => 'other-msp', '8west:tenant_id' => '81'], $token, $houseBindings);
operational_check('another provider never accepts this provider\'s directory', $otherRoster === ['available' => false]);
operational_check('reading, decoding and classifying wrote nothing', $rosterTables() === $beforeRoster);

echo "PASS: $checks operational context/removal checks\n";
