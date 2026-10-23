<?php
/**
 * CLI-only synthetic renderer for the real Clients directory and client page.
 *
 *   php app/tests/client_shared_directory_ui_test.php
 *
 * It evaluates the actual app/public/clients.php and client.php sources and the actual page chrome
 * lifted from lib/render.php, against an in-memory SQLite copy of the tables those pages read and a
 * synthetic Milepost directory transport. The page sources are used whole except for their require
 * lines and ONE substitution that hands the directory read its synthetic transport. No bootstrap,
 * configuration, network, login, Milepost, Coastmark or production data is touched, and nothing is
 * written anywhere except the HTML snapshots in CUSTOMER_UI_CAPTURE_DIR for
 * tools/shots/client-shared-directory.test.mjs. This is not signed-in, provider or customer acceptance.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
error_reporting(E_ALL);
set_error_handler(static function (int $level, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $level, $file, $line);
});

require_once __DIR__ . '/../lib/client_operational_context.php';
require_once __DIR__ . '/../lib/suite_preferences.php';
require_once __DIR__ . '/../lib/settings_context.php';
require_once __DIR__ . '/../lib/westy.php';

$checks = 0;
function directory_ui_check(bool $ok, string $label): void
{
    global $checks;
    $checks++;
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
}

/** Lift one top-level function's source out of render.php by brace matching (no bootstrap needed). */
function directory_ui_lift(string $source, string $name): string
{
    $at = strpos($source, "function $name(");
    if ($at === false) throw new RuntimeException("render.php no longer defines $name");
    $open = strpos($source, '{', $at);
    $depth = 0;
    for ($i = $open, $n = strlen($source); $i < $n; $i++) {
        if ($source[$i] === '{') $depth++;
        elseif ($source[$i] === '}') { $depth--; if ($depth === 0) break; }
    }
    return substr($source, $at, $i - $at + 1);
}

// Synthetic stand-ins for bootstrap/auth only. Same escaping contract as lib/bootstrap.php.
if (!function_exists('h')) {
    function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
function enforce_https(): void {}
function require_login(): array { return $GLOBALS['fixtureUser']; }
function tenant_id(): int { return (int)$GLOBALS['fixtureUser']['tenant_id']; }
function csrf_token(): string { return 'synthetic-csrf'; }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="synthetic-csrf">'; }
function csrf_check(): void { throw new RuntimeException('The directory fixture renders GET requests only.'); }
function cfg(string $key, mixed $default = null): mixed { return $GLOBALS['fixtureConfig'][$key] ?? $default; }
function suite_sso_claims(): ?array { return $GLOBALS['fixtureClaims']; }
function palette_data(): array { return []; }
function westy_workflow_ticket_owners(PDO $pdo, int $tenantId, array $rows): array { return $rows; }
function ticket_row(array $t, bool $linkWrap = true): string { throw new RuntimeException('The directory fixture has no tickets.'); }

/** The pages' SQL, with SQLite spellings for the few MySQL-only expressions client.php uses. */
final class DirectoryFixturePdo extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $query = str_replace(
            ['FIELD(t.priority, "urgent","high","normal","low")', 'TIMESTAMPDIFF(MINUTE, t.created_at, x.first_tech)', '"resolved"', '"tech"'],
            ["CASE t.priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'normal' THEN 3 WHEN 'low' THEN 4 ELSE 5 END",
             '((julianday(x.first_tech) - julianday(t.created_at)) * 1440)', "'resolved'", "'tech'"],
            $query,
        );
        return parent::prepare($query, $options);
    }
}
function db(): DirectoryFixturePdo
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new DirectoryFixturePdo('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

$render = (string)file_get_contents(__DIR__ . '/../lib/render.php');
$chrome = '';
foreach (['suite_chrome_drawer', 'suite_chrome_account_menu', 'page_top', 'page_bottom'] as $function) {
    $chrome .= directory_ui_lift($render, $function) . "\n";
}
// The lifted code resolves __DIR__ relative to THIS file, so point its require at lib/ explicitly.
eval(str_replace("__DIR__ . '/suite_apps.php'", var_export(__DIR__ . '/../lib/suite_apps.php', true), $chrome));

$uuid = static fn(int $n): string => "{$n}a{$n}b{$n}c{$n}d-{$n}e{$n}f-4a{$n}b-8c{$n}d-{$n}e{$n}f{$n}a{$n}b{$n}c{$n}d";
$event = static fn(int $n): string => sprintf('e1000000-0000-4000-8000-%012d', $n);
// CUT2 (A227): Coastmark's client-scoped Billing route, and its generic entry for everything else.
$scopedBilling = static fn(int $n): string => 'https://coastmark.8westit.com/clients?provider=8west&customer_id=' . $uuid($n);
$genericBilling = 'https://coastmark.8westit.com/auth/suite';
db()->exec("CREATE TABLE tenants(id INTEGER PRIMARY KEY, slug TEXT);
CREATE TABLE users(id INTEGER PRIMARY KEY, tenant_id INTEGER, suite_subject TEXT, is_active INTEGER, full_name TEXT, initials TEXT, color TEXT);
CREATE TABLE suite_managed_providers(tenant_id INTEGER PRIMARY KEY, issuer_tenant_id INTEGER, provider_slug TEXT);
CREATE TABLE clients(id INTEGER PRIMARY KEY, tenant_id INTEGER, name TEXT, domain TEXT, health TEXT, sla_tier TEXT);
CREATE TABLE contacts(id INTEGER PRIMARY KEY, client_id INTEGER, name TEXT, email TEXT);
CREATE TABLE tickets(id INTEGER PRIMARY KEY, tenant_id INTEGER, client_id INTEGER, status TEXT, priority TEXT, sla_due_at TEXT, created_at TEXT, assignee_id INTEGER, merged_into_id INTEGER);
CREATE TABLE messages(id INTEGER PRIMARY KEY, ticket_id INTEGER, kind TEXT, created_at TEXT);
CREATE TABLE suite_customer_sync_bindings(id INTEGER PRIMARY KEY, tenant_id INTEGER, client_id INTEGER, customer_id TEXT, source_version INTEGER, display_name TEXT, status TEXT, last_event_id TEXT, last_occurred_at TEXT);
CREATE TABLE suite_customer_sync_events(id INTEGER PRIMARY KEY, binding_id INTEGER, tenant_id INTEGER, client_id INTEGER, customer_id TEXT, source_version INTEGER, display_name TEXT, status TEXT, event_id TEXT, occurred_at TEXT);
INSERT INTO tenants VALUES(1,'8west'),(2,'other-msp');
INSERT INTO users VALUES(5,1,'t70u5',1,'Synthetic Technician','ST','#2D8CFF'),(9,2,'t81u9',1,'Other Technician','OT','#2D8CFF');
INSERT INTO clients VALUES
 (30,1,'Harbor Dental','harbordental.test','good','premium'),
 (31,1,'Beta Canary','canary.test','good','standard'),
 (32,1,'Copper Kettle','copperkettle.test','watch','standard'),
 (33,1,'Email Intake','intake.test','good','standard'),
 (34,1,'Northwind Partners','northwind.test','good','standard'),
 (36,1,'Lagged Labs','laggedlabs.test','good','standard'),
 (37,1,'Drifted Co','drifted.test','good','standard'),
 (38,1,'Removed Rentals','removedrentals.test','good','standard'),
 (40,2,'Other MSP Client','othermsp.test','good','standard');
INSERT INTO contacts VALUES(1,30,'Dana Front Desk','dana@harbordental.test');");
$binding = db()->prepare('INSERT INTO suite_customer_sync_bindings VALUES(?,?,?,?,?,?,?,?,?)');
$receipt = db()->prepare('INSERT INTO suite_customer_sync_events VALUES(?,?,?,?,?,?,?,?,?,?)');
foreach ([
    // binding, tenant, client, customer, version, name, status, receipt version (a drifted receipt disagrees)
    [1, 1, 30, $uuid(1), 3, 'Harbor Dental Group', 'active', 3],
    [2, 1, 31, $uuid(2), 1, 'Beta Canary', 'active', 1],
    [3, 1, 32, $uuid(3), 2, 'Copper Kettle', 'inactive', 2],
    [4, 1, 36, $uuid(4), 1, 'Lagged Labs', 'inactive', 1],
    [5, 1, 37, $uuid(6), 2, 'Drifted Co', 'active', 1],
    [6, 1, 38, $uuid(7), 1, 'Removed Rentals', 'active', 1],
    [7, 2, 40, $uuid(8), 1, 'Other MSP Client', 'active', 1],
] as [$id, $tenant, $client, $customer, $version, $name, $status, $receiptVersion]) {
    $binding->execute([$id, $tenant, $client, $customer, $version, $name, $status, $event($id), '2026-10-23 12:00:00']);
    $receipt->execute([$id, $id, $tenant, $client, $customer, $receiptVersion, $name, $status, $event($id), '2026-10-23 12:00:00']);
}

/** The Milepost directory items for the house provider: byte-sorted, exact A14 item keys. */
$houseRoster = [
    ['customer_id' => $uuid(1), 'source_version' => 3, 'event_id' => $event(11), 'display_name' => 'Harbor Dental Group', 'status' => 'active', 'client_exists' => true],
    ['customer_id' => $uuid(3), 'source_version' => 2, 'event_id' => $event(13), 'display_name' => 'Copper Kettle', 'status' => 'inactive', 'client_exists' => true],
    ['customer_id' => $uuid(4), 'source_version' => 2, 'event_id' => $event(14), 'display_name' => 'Lagged Labs', 'status' => 'active', 'client_exists' => true],
    ['customer_id' => $uuid(5), 'source_version' => 1, 'event_id' => $event(15), 'display_name' => 'Valley Veterinary', 'status' => 'active', 'client_exists' => true],
    ['customer_id' => $uuid(6), 'source_version' => 2, 'event_id' => $event(16), 'display_name' => 'Drifted Co', 'status' => 'active', 'client_exists' => true],
    ['customer_id' => $uuid(7), 'source_version' => 1, 'event_id' => $event(17), 'display_name' => 'Removed Rentals', 'status' => 'active', 'client_exists' => false],
];
/** @return callable(string,string):array{status:int,body:string} */
function directory_ui_transport(string $mode, array $customers): callable
{
    return static function (string $token, string $body) use ($mode, $customers): array {
        $request = json_decode($body, true, 4, JSON_THROW_ON_ERROR);
        directory_ui_check($token === 'synthetic.suite.token' && array_keys($request) === ['contract', 'action', 'expected_subject', 'read_nonce']
            && $request['contract'] === 'suite-client-directory-v1' && $request['expected_subject'] === 't70u5',
            'the directory read sends only the exact request and this account\'s own token');
        if ($mode === 'unavailable') return ['status' => 503, 'body' => '{"ok":false,"reason":"directory_unavailable"}'];
        return ['status' => 200, 'body' => json_encode([
            'ok' => true, 'contract' => 'suite-client-directory-v1', 'actor_subject' => 't70u5', 'provider_tenant_id' => 44,
            'provider_tenant_slug' => $mode === 'other-provider' ? 'other-msp' : '8west', 'issuer_suite_tenant_id' => 70,
            'read_nonce' => $request['read_nonce'], 'read_at' => time(), 'customers' => $customers,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)];
    };
}

/** Evaluate one actual page source in its own scope and return its HTML. */
function directory_ui_page(string $page, array $get): string
{
    $source = (string)file_get_contents(__DIR__ . '/../public/' . $page);
    // Same whole-page source, substituting only its require lines and the directory transport.
    $source = preg_replace('/^require_once .*;\R/m', '', $source);
    $source = str_replace('client_operational_roster(db(), $user)',
        'client_operational_roster(db(), $user, $GLOBALS[\'fixtureTransport\'])', $source, $count);
    directory_ui_check($count === 1, $page . ' reads the directory through exactly one call');
    $_GET = $get;
    $_POST = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['QUERY_STRING'] = http_build_query($get, '', '&', PHP_QUERY_RFC3986);
    ob_start();
    try {
        eval(substr($source, strlen('<?php')));
        return (string)ob_get_contents();
    } finally {
        ob_end_clean();
    }
}

function directory_ui_tables(): string
{
    $tables = [];
    foreach (['tenants', 'users', 'suite_managed_providers', 'clients', 'contacts', 'tickets', 'messages',
        'suite_customer_sync_bindings', 'suite_customer_sync_events'] as $table) {
        $tables[$table] = db()->query('SELECT * FROM ' . $table . ' ORDER BY 1')->fetchAll();
    }
    return hash('sha256', json_encode($tables, JSON_THROW_ON_ERROR));
}

$GLOBALS['fixtureUser'] = ['id' => 5, 'tenant_id' => 1, 'role' => 'admin', 'is_active' => 1, 'suite_subject' => 't70u5',
    'full_name' => 'Synthetic Technician', 'email' => 'synthetic@example.test', 'initials' => 'ST', 'color' => '#2D8CFF'];
$GLOBALS['fixtureConfig'] = ['suite.cookie_name' => 'ewid_token', 'id_report_contacts.tenant_bindings' => ['8west' => 'ewid-t70']];
$GLOBALS['fixtureClaims'] = ['sub' => 't70u5', '8west:tenant' => '8west', '8west:tenant_id' => '70'];
$_COOKIE = ['ewid_token' => 'synthetic.suite.token'];
$out = getenv('CUSTOMER_UI_CAPTURE_DIR');
$before = directory_ui_tables();

foreach (['light', 'dark'] as $theme) {
    $_SESSION = ['suite_theme' => $theme, 'suite_products' => ['safeharbor', 'milepost', 'coastmark'],
        'suite_name' => 'Synthetic Technician', 'suite_email' => 'synthetic@example.test'];
    foreach (['directory' => $houseRoster, 'empty' => [], 'unavailable' => [], 'other-provider' => $houseRoster] as $case => $customers) {
        $GLOBALS['fixtureTransport'] = directory_ui_transport($case, $customers);
        $html = directory_ui_page('clients.php', []);
        $classified = in_array($case, ['directory', 'empty'], true);
        directory_ui_check(str_contains($html, '<title>Clients · Safeharbor</title>')
            && str_contains($html, 'var t=' . json_encode($theme) . ';') && str_contains($html, 'class="w365-cluster"'),
            "$case $theme renders the actual page chrome and theme");
        directory_ui_check(str_contains($html, '>Businesses</h2>') === $classified
            && str_contains($html, '>Retained and local</h2>') === $classified,
            "$case $theme classifies only against a verified directory");
        directory_ui_check(str_contains($html, 'The Milepost client directory is unavailable right now') === !$classified
            && str_contains($html, 'Milepost currently lists no shared businesses') === ($case === 'empty'),
            "$case $theme states unavailable and verified-empty differently");
        directory_ui_check(str_contains($html, 'Valley Veterinary') === ($case === 'directory')
            && !str_contains($html, 'Other MSP Client') && !str_contains($html, $uuid(8)),
            "$case $theme shows only this provider, and pending Valley only from a verified directory");
        // Each rendered row's own Billing targets, keyed by local client id (or "pending").
        $billingTargets = [];
        foreach (array_slice(explode('<div class="crow-row"', $html), 1) as $row) {
            preg_match('/data-client-id="(\d+)"/', $row, $rowClient);
            preg_match_all('/<a class="btn-chip" href="([^"]*)"[^>]*>Billing ↗<\/a>/u', $row, $rowBilling);
            $billingTargets[$rowClient[1] ?? 'pending'] = array_map(
                static fn(string $href): string => htmlspecialchars_decode($href, ENT_QUOTES), $rowBilling[1]);
        }
        $expectedBilling = [30 => [$scopedBilling(1)], 31 => [$scopedBilling(2)], 32 => [$scopedBilling(3)],
            33 => [$genericBilling], 34 => [$genericBilling], 36 => [$scopedBilling(4)], 37 => [$genericBilling],
            38 => [$scopedBilling(7)]] + ($case === 'directory' ? ['pending' => [$scopedBilling(5)]] : []);
        ksort($billingTargets, SORT_STRING);
        ksort($expectedBilling, SORT_STRING);
        directory_ui_check($billingTargets === $expectedBilling
            && str_contains($html, 'href="https://coastmark.8westit.com/clients?provider=8west&amp;customer_id=' . $uuid(1) . '"')
            && substr_count($html, 'title="Opens this client in Coastmark">Billing ↗</a>') === ($case === 'directory' ? 6 : 5)
            && substr_count($html, 'class="btn-chip" href="https://coastmark.8westit.com/auth/suite" target="_blank" rel="noopener" title="Opens Coastmark; choose the client there">Billing ↗</a>') === 3,
            "$case $theme gives each linked client (inactive and removed history included) and a verified pending business its own Billing route, and local and drifted rows the generic entry");
        if (is_string($out) && is_dir($out)) file_put_contents("$out/clients-$case-$theme.html", $html);
    }
    $GLOBALS['fixtureTransport'] = directory_ui_transport('directory', $houseRoster);
    foreach ([30 => 'business', 31 => 'retained'] as $clientId => $case) {
        $html = directory_ui_page('client.php', ['id' => (string)$clientId]);
        directory_ui_check(str_contains($html, $clientId === 30 ? '<title>Harbor Dental · Safeharbor</title>' : '<title>Beta Canary · Safeharbor</title>')
            && str_contains($html, $clientId === 30 ? 'Milepost directory now:' : 'Not in Milepost’s current client directory'),
            "client $clientId $theme shows its current directory entry or retained history");
        directory_ui_check(substr_count($html, 'href="' . h($scopedBilling($clientId === 30 ? 1 : 2)) . '"') === 2
            && str_contains($html, '>View this client in Coastmark ↗</a>') && str_contains($html, '>Review this client’s invoices in Coastmark ↗</a>')
            && str_contains($html, 'Milepost and Coastmark check your access before showing this client.')
            && !str_contains($html, 'class="link" href="https://coastmark.8westit.com/auth/suite"'),
            "client $clientId $theme Balance and Suite open this client's own Coastmark route");
        if (is_string($out) && is_dir($out)) file_put_contents("$out/client-$case-$theme.html", $html);
    }
    // Local and drifted clients keep Coastmark's generic entry and wording.
    foreach ([33 => 'Email Intake', 37 => 'Drifted Co'] as $clientId => $name) {
        $html = directory_ui_page('client.php', ['id' => (string)$clientId]);
        directory_ui_check(str_contains($html, '<title>' . $name . ' · Safeharbor</title>')
            && substr_count($html, 'class="link" href="https://coastmark.8westit.com/auth/suite"') === 2
            && str_contains($html, '>View in Coastmark ↗</a>') && str_contains($html, '>Review invoices in Coastmark ↗</a>')
            && str_contains($html, 'Open the app, then select the client.')
            && !str_contains($html, 'coastmark.8westit.com/clients'),
            "client $clientId $theme keeps Coastmark's generic Billing entry");
    }
    // A48: the canonical provider + UUID Support target renders the same bound client page, retained
    // inactive history included. (A miss ends the page with exit, so misses are unit-tested instead.)
    foreach ([[1, 'Harbor Dental', 30], [3, 'Copper Kettle', 32]] as [$n, $name, $clientId]) {
        $customer = $uuid($n);
        $html = directory_ui_page('client.php', ['provider' => '8west', 'customer_id' => $customer]);
        directory_ui_check(client_operational_support_client(db(), 1, 'provider=8west&customer_id=' . $customer) === $clientId
            && str_contains($html, '<title>' . $name . ' · Safeharbor</title>') && !str_contains($html, 'Unknown client.')
            && substr_count($html, 'href="' . h($scopedBilling($n)) . '"') === 2
            && !str_contains($html, 'class="link" href="https://coastmark.8westit.com/auth/suite"')
            && str_contains($html, $n === 1 ? 'Milepost and Coastmark check your access before showing this client.'
                : 'Coastmark checks your access before showing this client. In Milepost, select the client.'),
            "the Support target for $name renders that bound client's page with its own Billing route ($theme)");
    }
}
directory_ui_check(directory_ui_tables() === $before, 'rendering the directory and client pages wrote nothing');
echo "PASS: $checks client shared directory renderer checks\n";
