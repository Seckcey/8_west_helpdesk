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
require_once __DIR__ . '/../lib/client_page.php';
require_once __DIR__ . '/../lib/service_goal_policy_admin.php';

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
/** The real row is drawn and tested with lib/render.php. Here it only shows which tickets the page lists, in what order, and that it asks for rows without the client's name. */
function ticket_row(array $t, bool $linkWrap = true, string $from = '', bool $withClient = true): string
{
    return '<a class="trow" href="/ticket.php?id=' . (int)$t['id'] . h($from) . '" data-with-client="' . ($withClient ? '1' : '0') . '">#' . (int)$t['id'] . ' ' . h($t['subject']) . '</a>';
}

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
foreach (['suite_apps.php', 'service_goal_policy_admin.php', 'ticket_queue.php'] as $library) {
    $chrome = str_replace("__DIR__ . '/" . $library . "'", var_export(__DIR__ . '/../lib/' . $library, true), $chrome);
}
eval($chrome);
eval(directory_ui_lift((string)file_get_contents(__DIR__ . '/../lib/bootstrap.php'), 'rel_time'));
// Ages in the one compact form the queue, Clients and the first-response tag share.
directory_ui_check(rel_time(gmdate('Y-m-d H:i:s', time() - 20 * 60 - 5)) === '20 min'
    && rel_time(gmdate('Y-m-d H:i:s', time() - 3 * 3600 - 60)) === '3 h'
    && rel_time(gmdate('Y-m-d H:i:s', time() - 5 * 86400 - 60)) === '5 d', 'an age reads "20 min", "3 h" or "5 d"');

$uuid = static fn(int $n): string => "{$n}a{$n}b{$n}c{$n}d-{$n}e{$n}f-4a{$n}b-8c{$n}d-{$n}e{$n}f{$n}a{$n}b{$n}c{$n}d";
$event = static fn(int $n): string => sprintf('e1000000-0000-4000-8000-%012d', $n);
// CUT2 (A227): Coastmark's client-scoped Billing route, and its generic entry for everything else.
$scopedBilling = static fn(int $n): string => 'https://coastmark.8westit.com/clients?provider=8west&customer_id=' . $uuid($n);
$genericBilling = 'https://coastmark.8westit.com/auth/suite';
db()->exec("CREATE TABLE tenants(id INTEGER PRIMARY KEY, slug TEXT);
CREATE TABLE users(id INTEGER PRIMARY KEY, tenant_id INTEGER, suite_subject TEXT, is_active INTEGER, full_name TEXT, initials TEXT, color TEXT);
CREATE TABLE suite_managed_providers(tenant_id INTEGER PRIMARY KEY, issuer_tenant_id INTEGER, provider_slug TEXT);
CREATE TABLE clients(id INTEGER PRIMARY KEY, tenant_id INTEGER, name TEXT, domain TEXT, health TEXT, sla_tier TEXT, notes TEXT);
CREATE TABLE contacts(id INTEGER PRIMARY KEY, client_id INTEGER, name TEXT, email TEXT);
CREATE TABLE tickets(id INTEGER PRIMARY KEY, tenant_id INTEGER, client_id INTEGER, status TEXT, priority TEXT, sla_due_at TEXT, created_at TEXT, assignee_id INTEGER, merged_into_id INTEGER, contact_id INTEGER, subject TEXT, channel TEXT, resolved_at TEXT);
CREATE TABLE messages(id INTEGER PRIMARY KEY, ticket_id INTEGER, kind TEXT, created_at TEXT, author_name TEXT);
CREATE TABLE time_entries(id INTEGER PRIMARY KEY, tenant_id INTEGER, client_id INTEGER, minutes INTEGER, billable INTEGER, approval_status TEXT, worked_at TEXT);
CREATE TABLE time_entry_approval_adjustments(id INTEGER PRIMARY KEY, tenant_id INTEGER, time_entry_id INTEGER, version_no INTEGER, effective_minutes INTEGER, effective_billable INTEGER);
CREATE TABLE suite_customer_sync_bindings(id INTEGER PRIMARY KEY, tenant_id INTEGER, client_id INTEGER, customer_id TEXT, source_version INTEGER, display_name TEXT, status TEXT, last_event_id TEXT, last_occurred_at TEXT);
CREATE TABLE suite_customer_sync_events(id INTEGER PRIMARY KEY, binding_id INTEGER, tenant_id INTEGER, client_id INTEGER, customer_id TEXT, source_version INTEGER, display_name TEXT, status TEXT, event_id TEXT, occurred_at TEXT);
INSERT INTO tenants VALUES(1,'8west'),(2,'other-msp');
INSERT INTO users VALUES(5,1,'t70u5',1,'Synthetic Technician','ST','#2D8CFF'),(9,2,'t81u9',1,'Other Technician','OT','#2D8CFF');
INSERT INTO clients(id, tenant_id, name, domain, health, sla_tier) VALUES
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
// Harbor Dental's whole picture: a note, three open tickets, twenty-seven resolved ones, two first
// replies inside ninety days and one outside, and time in every state. One ticket and one time entry
// carry this client's number under another tenant; neither may be counted or listed.
$ago = static fn(int $minutes): string => gmdate('Y-m-d H:i:s', time() - $minutes * 60);
db()->prepare('UPDATE clients SET notes = ? WHERE id = 30')->execute(["Call the office line before 9.\n<b>Front desk</b> closes at 5."]);
$ticket = db()->prepare('INSERT INTO tickets(id, tenant_id, client_id, status, priority, sla_due_at, created_at, subject, channel, resolved_at) VALUES(?,?,?,?,?,?,?,?,?,?)');
foreach ([
    [101, 1, 30, 'open', 'normal', $ago(-600), $ago(120), 'Front desk PC will not print', 'phone', null],
    [102, 1, 30, 'in_progress', 'urgent', $ago(-60), $ago(300), 'Server room is hot', 'email', null],
    [103, 1, 30, 'waiting', 'high', $ago(-900), $ago(4000), 'New hygienist starts Monday', 'portal', null],
    [104, 2, 30, 'open', 'urgent', $ago(-60), $ago(6000), 'Another tenant, same client number', 'email', null],
    [105, 1, 31, 'open', 'normal', $ago(-60), $ago(60), 'Another client of this tenant', 'email', null],
    [199, 1, 30, 'resolved', 'normal', $ago(200000), $ago(200000), 'Answered long ago', 'email', $ago(190000)],
] as $row) $ticket->execute($row);
for ($n = 1; $n <= 26; $n++) {
    $ticket->execute([200 + $n, 1, 30, 'resolved', 'normal', $ago(5000), $ago(6000), "Resolved ticket $n", 'email', $ago($n * 60)]);
}
$message = db()->prepare('INSERT INTO messages(ticket_id, kind, created_at, author_name) VALUES(?,?,?,?)');
foreach ([
    [101, 'client', $ago(120), 'Dana Front Desk'], [101, 'tech', $ago(90), 'Synthetic Technician'],      // 30 minutes
    [102, 'client', $ago(300), 'Dana Front Desk'], [102, 'tech', $ago(246), 'Synthetic Technician'],     // 54 minutes
    [199, 'tech', $ago(199999), 'Synthetic Technician'],                                                 // older than ninety days
    [104, 'tech', $ago(1), 'Other Technician'], [105, 'tech', $ago(55), 'Synthetic Technician'],         // not this tenant's, not this client's
] as $row) $message->execute($row);
db()->exec("INSERT INTO time_entries VALUES
 (1,1,30,200,1,'approved','{$ago(1000)}'),   -- approved, then changed twice: counts as 120
 (2,1,30,30,1,'approved','{$ago(2000)}'),    -- approved as logged
 (3,1,30,60,1,'pending','{$ago(100)}'),      -- not approved yet
 (4,1,30,40,0,'approved','{$ago(100)}'),     -- not billable
 (5,1,30,25,1,'approved','{$ago(50000)}'),   -- older than thirty days
 (6,2,30,500,1,'approved','{$ago(100)}'),    -- another tenant
 (7,1,31,70,1,'approved','{$ago(100)}'),     -- another client
 (8,1,30,90,1,'approved','{$ago(100)}');     -- approved, then changed to not billable
INSERT INTO time_entry_approval_adjustments VALUES(1,1,1,1,180,1),(2,1,1,2,120,1),(3,1,8,1,90,0);");
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
function directory_ui_page(string $page, array $get, bool $readsDirectory = true): string
{
    $source = (string)file_get_contents(__DIR__ . '/../public/' . $page);
    // Same whole-page source, substituting only its require lines and the directory transport.
    $source = preg_replace('/^require_once .*;\R/m', '', $source);
    $source = str_replace('client_operational_roster(db(), $user)',
        'client_operational_roster(db(), $user, $GLOBALS[\'fixtureTransport\'])', $source, $count);
    directory_ui_check($count === ($readsDirectory ? 1 : 0), $page . ($readsDirectory ? ' reads the directory through exactly one call' : ' does not read the directory'));
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
    foreach (['tenants', 'users', 'suite_managed_providers', 'clients', 'contacts', 'tickets', 'messages', 'time_entries',
        'time_entry_approval_adjustments', 'suite_customer_sync_bindings', 'suite_customer_sync_events'] as $table) {
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
        directory_ui_check(substr_count($html, 'data-client-list') === 1
            && !str_contains($html, '>Businesses</h2>') && !str_contains($html, '>Retained and local</h2>'),
            "$case $theme presents one client list");
        directory_ui_check(str_contains($html, "Milepost's client list can't be read right now.") === !$classified
            && str_contains($html, 'Milepost currently lists no shared clients') === ($case === 'empty'),
            "$case $theme states unavailable and verified-empty differently");
        directory_ui_check(str_contains($html, 'Valley Veterinary') === ($case === 'directory')
            && !str_contains($html, 'Other MSP Client') && !str_contains($html, $uuid(8)),
            "$case $theme shows only this provider, and pending Valley only from a verified directory");
        // Each rendered row's own Billing targets, keyed by local client id (or "pending").
        $billingTargets = [];
        foreach (array_slice(explode('<div class="crow-row"', $html), 1) as $row) {
            preg_match('/data-client-id="(\d+)"/', $row, $rowClient);
            preg_match_all('/<a class="btn-chip" href="([^"]*)"[^>]*>Open in Coastmark<\/a>/u', $row, $rowBilling);
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
            && substr_count($html, 'title="Opens this client in Coastmark">Open in Coastmark</a>') === ($case === 'directory' ? 6 : 5)
            && substr_count($html, 'class="btn-chip" href="https://coastmark.8westit.com/auth/suite" target="_blank" rel="noopener" title="Opens Coastmark; choose the client there">Open in Coastmark</a>') === 3,
            "$case $theme gives each linked client (inactive and removed history included) and a verified pending business its own Billing route, and local and drifted rows the generic entry");
        // One word for each thing: clients are clients, and a plan is said in words.
        $list = new DOMDocument();
        libxml_use_internal_errors(true);
        $list->loadHTML('<?xml encoding="utf-8">' . $html);
        libxml_clear_errors();
        $listX = new DOMXPath($list);
        $client = $listX->query('//a[@data-client-id="30"]')->item(0);
        $oldest = db()->query('SELECT created_at FROM tickets WHERE id=103')->fetchColumn();
        directory_ui_check(trim($listX->query('.//span[@class="crow-open"]/strong', $client)->item(0)->textContent) === '3'
            && $listX->query('.//time', $client)->item(0)->getAttribute('datetime') === gmdate('c', strtotime($oldest . ' UTC')),
            "$case $theme excludes the foreign tenant from the open count and oldest open");
        directory_ui_check($listX->query('//a[@data-client-id="32"]//time')->length === 0,
            "$case $theme has no oldest-open age for a client without open tickets");
        directory_ui_check($listX->query('//span[@class="crow-link-state"]')->length === 2
            && $listX->query('//a[@data-client-id="37"]//span[@class="crow-link-state"]')->length === 0,
            "$case $theme labels known unbound rows only; unknown is never Not linked");
        directory_ui_check($listX->query('//div[contains(@class,"page-clients")]//a[normalize-space(.)="Support"]')->length === 0,
            "$case $theme removes the duplicate Support destination");
        directory_ui_check($listX->query('//a[@data-client-id="32"]//span[contains(@class,"crow-health")][normalize-space(.)="Needs attention"]')->length === 1
            && $listX->query('//a[@data-client-id="30"]//span[contains(@class,"crow-health")]')->length === 0,
            "$case $theme watched client has a visible text marker and healthy client does not");
        $plans = [];
        foreach ($listX->query('//a[@data-client-id]') as $row) {
            $plans[(int)$row->getAttribute('data-client-id')] = trim((string)$listX->query('.//span[contains(concat(" ", normalize-space(@class), " "), " tier ")]', $row)->item(0)?->textContent);
        }
        directory_ui_check(preg_match('/^\d+ clients$/', trim((string)$listX->query('//p[@class="page-sub"]')->item(0)?->textContent)) === 1
            && ($plans[30] ?? '') === 'Premium plan' && ($plans[33] ?? '') === 'Standard plan' && array_diff($plans, ['Premium plan', 'Standard plan']) === []
            && !str_contains($html, 'accounts under') && preg_match('/>\s*(premium|standard)\s*</', $html) === 0,
            "$case $theme counts clients as clients and says each plan in words");
        if (is_string($out) && is_dir($out)) file_put_contents("$out/clients-$case-$theme.html", $html);
    }
    db()->beginTransaction();
    try {
        db()->exec('DELETE FROM suite_customer_sync_bindings');
        $unlinked = directory_ui_page('clients.php', []);
        directory_ui_check(!str_contains($unlinked, 'class="crow-link-state"'), "no Not linked tags when no client has a verified link ($theme)");
    } finally { db()->rollBack(); }
    $GLOBALS['fixtureTransport'] = directory_ui_transport('directory', $houseRoster);
    // The page's own markup, without the chrome around it.
    $body = static fn(string $html): string => substr($html, (int)strpos($html, '<div class="page page-client">'));
    $xpath = static function (string $html): DOMXPath {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8">' . $html);
        libxml_clear_errors();
        return new DOMXPath($doc);
    };
    $texts = static fn(DOMXPath $x, string $query): array => array_map(
        static fn(DOMNode $node): string => trim((string)preg_replace('/\s+/u', ' ', $node->textContent)), iterator_to_array($x->query($query)));
    $attrs = static fn(DOMXPath $x, string $query): array => array_map(static fn(DOMNode $node): string => $node->nodeValue ?? '', iterator_to_array($x->query($query)));
    $save = static function (string $name, string $html) use ($out, $theme): void {
        if (is_string($out) && is_dir($out)) file_put_contents("$out/$name-$theme.html", $html);
    };
    foreach ([30 => 'business', 31 => 'retained'] as $clientId => $case) {
        $html = directory_ui_page('client.php', ['id' => (string)$clientId]);
        directory_ui_check(str_contains($html, $clientId === 30 ? '<title>Harbor Dental · Safeharbor</title>' : '<title>Beta Canary · Safeharbor</title>')
            && str_contains($html, $clientId === 30 ? 'Milepost directory now:' : 'Not in Milepost’s current client directory'),
            "client $clientId $theme shows its current directory entry or retained history");
        directory_ui_check(substr_count($html, 'href="' . h($scopedBilling($clientId === 30 ? 1 : 2)) . '"') === 1
            && str_contains($html, '>Open this client in Coastmark ↗</a>') && str_contains($html, '>Open this client in Milepost ↗</a>')
            && str_contains($html, 'Milepost and Coastmark check your access before showing this client.')
            && !str_contains($html, 'class="link" href="https://coastmark.8westit.com/auth/suite"'),
            "client $clientId $theme Computers and Invoices open this client's own Milepost and Coastmark pages");
        directory_ui_check(str_contains($html, 'Linked to Milepost as <strong>' . ($clientId === 30 ? 'Harbor Dental Group' : 'Beta Canary') . '</strong>')
            && substr_count($body($html), 'Source version last received: ' . ($clientId === 30 ? 3 : 1) . '.') === 1
            && substr_count($body($html), 'Source version in Milepost’s directory: 3.') === ($clientId === 30 ? 1 : 0)
            && preg_match('/source version/i', (string)preg_replace('~<details class="client-link-details">.*?</details>~s', '', $body($html))) === 0
            && preg_match('/\sstyle="/', $body($html)) === 0,
            "client $clientId $theme says what it is linked to in words, keeps version numbers under Details, and has no inline style");
        $save("client-$case", $html);
    }

    // Harbor Dental's whole picture: who they are, the note, the numbers, their tickets and contacts.
    $x = $xpath(directory_ui_page('client.php', ['id' => '30']));
    directory_ui_check($texts($x, '//div[@class="client-title"]/*') === ['Harbor Dental', 'Premium plan', 'harbordental.test']
        && $texts($x, '//div[@class="client-actions"]/a') === ['New ticket', 'Edit']
        && $attrs($x, '//div[@class="client-actions"]/a/@href') === ['/ticket_new.php?client=30', '/client_edit.php?id=30']
        && $x->query('//a[@data-new-ticket][@href="/ticket_new.php?client=30"]')->length === 1,
        "the head names the client, its plan and domain, then New ticket and Edit ($theme)");
    directory_ui_check($texts($x, '//p[@class="client-note"]') === ['Note Call the office line before 9. <b>Front desk</b> closes at 5.']
        && $x->query('//p[@class="client-note"]/br')->length === 1 && $x->query('//p[@class="client-note"]//b')->length === 0,
        "the note is shown under the head, line breaks kept, as text and never markup ($theme)");
    directory_ui_check($texts($x, '//div[contains(@class,"client-stats")]//div[@class="stat-k"]') === ['Open tickets', 'First reply, 90 days', 'Billable time, 30 days']
        && $texts($x, '//div[contains(@class,"client-stats")]//div[@class="stat-v"]') === ['3', '42 min', '2 h 30 min']
        && $texts($x, '//div[contains(@class,"client-stats")]//div[@class="stat-hint"]') === ['Average over 2 tickets', 'Approved'],
        "every tile is a number: this tenant's open tickets for this client, its first replies inside ninety days, its approved billable time inside thirty ($theme)");
    directory_ui_check($texts($x, '//nav[contains(@class,"client-filters")]/a') === ['Open3', 'Resolved27']
        && $attrs($x, '//nav[contains(@class,"client-filters")]/a/@href') === ['/client.php?id=30', '/client.php?id=30&show=resolved']
        && $attrs($x, '//nav[contains(@class,"client-filters")]/a[@aria-current="page"]/@href') === ['/client.php?id=30']
        && $attrs($x, '//a[@class="trow"]/@href') === ['/ticket.php?id=102', '/ticket.php?id=103', '/ticket.php?id=101']
        && $attrs($x, '//a[@class="trow"]/@data-with-client') === ['0', '0', '0']
        && $x->query('//nav[@class="pager"]')->length === 0,
        "Open lists this client's open tickets, the most pressing first, in rows without the client's name ($theme)");
    directory_ui_check($texts($x, '//div[contains(@class,"contact-row")]//div[@class="rail-list-name"]') === ['Dana Front Desk']
        && $attrs($x, '//div[contains(@class,"contact-row")]//details/summary/@aria-label') === ['More for Dana Front Desk']
        && $attrs($x, '//div[contains(@class,"contact-row")]//details/form[@method="post"][@action="/client.php?id=30"]/@data-confirm') === ['Remove Dana Front Desk?']
        && $texts($x, '//div[contains(@class,"contact-row")]//details/form/button[@type="submit"]') === ['Remove']
        && $attrs($x, '//details[contains(@class,"contact-add")]/form[@action="/client.php?id=30"]//input[@name]/@name') === ['csrf', 'action', 'name', 'email']
        && $x->query('//details[contains(@class,"contact-add")]/form//input[@name="name"][@required]')->length === 1
        && $x->query('//details[contains(@class,"contact-add")]/form//input[@name="email"][@required]')->length === 0,
        "a contact's Remove sits behind More and asks first; Add a contact takes a name and an optional address ($theme)");

    $resolved = directory_ui_page('client.php', ['id' => '30', 'show' => 'resolved']);
    $x = $xpath($resolved);
    $rows = $attrs($x, '//a[@class="trow"]/@href');
    directory_ui_check(count($rows) === 25 && $rows[0] === '/ticket.php?id=201' && $rows[24] === '/ticket.php?id=225'
        && $attrs($x, '//nav[contains(@class,"client-filters")]/a[@aria-current="page"]/@href') === ['/client.php?id=30&show=resolved']
        && $texts($x, '//nav[@class="pager"]/span[@class="pager-count"]') === ['Showing 1 to 25 of 27']
        && $attrs($x, '//nav[@class="pager"]/a/@href') === ['/client.php?id=30&show=resolved&page=2']
        && $texts($x, '//nav[@class="pager"]/span[@aria-disabled="true"]') === ['Previous'],
        "Resolved lists the newest twenty-five, with Next and no Previous ($theme)");
    $save('client-business-resolved', $resolved);
    foreach (['2', '99'] as $asked) {
        $last = directory_ui_page('client.php', ['id' => '30', 'show' => 'resolved', 'page' => $asked]);
        $x = $xpath($last);
        directory_ui_check($attrs($x, '//a[@class="trow"]/@href') === ['/ticket.php?id=226', '/ticket.php?id=199']
            && $texts($x, '//nav[@class="pager"]/span[@class="pager-count"]') === ['Showing 26 to 27 of 27']
            && $attrs($x, '//nav[@class="pager"]/a/@href') === ['/client.php?id=30&show=resolved']
            && $texts($x, '//nav[@class="pager"]/span[@aria-disabled="true"]') === ['Next'],
            "page $asked of Resolved is the last page, with Previous and no Next ($theme)");
        if ($asked === '2') $save('client-business-resolved2', $last);
    }
    foreach ([['show' => 'resolved', 'page' => 'abc'], ['show' => 'resolved', 'page' => '0'], ['show' => 'resolved', 'page' => '-1']] as $odd) {
        directory_ui_check($attrs($xpath(directory_ui_page('client.php', ['id' => '30'] + $odd)), '//a[@class="trow"]/@href')[0] === '/ticket.php?id=201',
            'a page that is not a whole number from 1 is page 1');
    }
    directory_ui_check($attrs($xpath(directory_ui_page('client.php', ['id' => '30', 'show' => 'everything', 'page' => '2'])), '//a[@class="trow"]/@href')
        === ['/ticket.php?id=102', '/ticket.php?id=103', '/ticket.php?id=101'], 'an unknown list is Open');

    // Local and drifted clients keep Coastmark's generic entry and wording.
    foreach ([33 => 'Email Intake', 37 => 'Drifted Co'] as $clientId => $name) {
        $html = directory_ui_page('client.php', ['id' => (string)$clientId]);
        directory_ui_check(str_contains($html, '<title>' . $name . ' · Safeharbor</title>')
            && substr_count($html, 'class="link" href="https://coastmark.8westit.com/auth/suite"') === 1
            && str_contains($html, '>Open Coastmark ↗</a>') && str_contains($html, '>Open Milepost ↗</a>')
            && str_contains($html, 'Open the app, then select the client.')
            && !str_contains($html, 'coastmark.8westit.com/clients'),
            "client $clientId $theme keeps Coastmark's generic Billing entry");
        directory_ui_check(str_contains($html, $clientId === 33 ? 'Not linked to Milepost. Shared clients are created in Milepost.'
                : 'Shared details are temporarily unavailable. The details kept here are unaffected.')
            && !str_contains($html, 'Linked to Milepost as') && !str_contains($html, 'client-link-details'),
            "client $clientId $theme says it is local, or that its shared details cannot be read, and claims no link");
        if ($clientId === 33) {
            $x = $xpath($html);
            directory_ui_check($texts($x, '//div[contains(@class,"client-stats")]//div[@class="stat-k"]') === ['Open tickets', 'Billable time, 30 days']
                && $texts($x, '//div[contains(@class,"client-stats")]//div[@class="stat-v"]') === ['0', '0 min']
                && $texts($x, '//div[@class="empty"]/p') === ['No open tickets for Email Intake.']
                && $attrs($x, '//div[@class="empty"]/a/@href') === ['/ticket_new.php?client=33']
                && $texts($x, '//nav[contains(@class,"client-filters")]/a') === ['Open0', 'Resolved0']
                && $texts($x, '//div[@class="client-title"]/*') === ['Email Intake', 'Standard plan', 'intake.test']
                && str_contains($html, 'No contacts yet.') && $x->query('//p[@class="client-note"]')->length === 0,
                "a client with nothing yet has no first-reply tile and no note, and says there are no tickets or contacts ($theme)");
            $save('client-local', $html);
        }
    }
    // A48: the canonical provider + UUID Support target renders the same bound client page, retained
    // inactive history included. (A miss ends the page with exit, so misses are unit-tested instead.)
    foreach ([[1, 'Harbor Dental', 30], [3, 'Copper Kettle', 32]] as [$n, $name, $clientId]) {
        $customer = $uuid($n);
        $html = directory_ui_page('client.php', ['provider' => '8west', 'customer_id' => $customer]);
        directory_ui_check(client_operational_support_client(db(), 1, 'provider=8west&customer_id=' . $customer) === $clientId
            && str_contains($html, '<title>' . $name . ' · Safeharbor</title>') && !str_contains($html, 'Unknown client.')
            && substr_count($html, 'href="' . h($scopedBilling($n)) . '"') === 1
            && !str_contains($html, 'class="link" href="https://coastmark.8westit.com/auth/suite"')
            && str_contains($html, $n === 1 ? 'Milepost and Coastmark check your access before showing this client.'
                : 'Coastmark checks your access before showing this client. In Milepost, select the client.'),
            "the Support target for $name renders that bound client's page with its own Billing route ($theme)");
        if ($n === 3) {
            // Copper Kettle is being watched, and inactive in Milepost: its computers open Milepost's own entry.
            directory_ui_check($texts($xpath($html), '//div[@class="client-title"]/*') === ['Copper Kettle', 'Standard plan', 'Needs attention', 'copperkettle.test']
                && str_contains($html, '>Open Milepost ↗</a>') && str_contains($html, '>Open this client in Coastmark ↗</a>')
                && str_contains($html, '<span class="crow-shared-inactive">inactive</span>'),
                "a watched client says Needs attention in words, and an inactive one keeps Milepost's general entry ($theme)");
            $save('client-watch', $html);
        }
    }
}
// New client and Edit client: "Plan" with capitalised choices, and health in the client page's words.
$formPage = static function (string $page, array $get): DOMXPath {
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8">' . directory_ui_page($page, $get, false));
    libxml_clear_errors();
    return new DOMXPath($doc);
};
$words = static fn(DOMXPath $x, string $query): array => array_map(
    static fn(DOMNode $node): string => trim((string)preg_replace('/\s+/u', ' ', $node->textContent)), iterator_to_array($x->query($query)));
$x = $formPage('client_edit.php', ['id' => '32']);
directory_ui_check($words($x, '//label[select[@name="sla_tier"]]/text()[normalize-space()]') === ['Plan']
    && $words($x, '//select[@name="sla_tier"]/option') === ['Standard', 'Premium']
    && $words($x, '//select[@name="sla_tier"]/option/@value') === ['standard', 'premium']
    && $words($x, '//select[@name="sla_tier"]/option[@selected]') === ['Standard']
    && $words($x, '//select[@name="health"]/option') === ['Good', 'Needs attention']
    && $words($x, '//select[@name="health"]/option/@value') === ['good', 'watch']
    && $words($x, '//select[@name="health"]/option[@selected]') === ['Needs attention'],
    'Edit client asks for the Plan and the health in words, and still sends the stored values');
$x = $formPage('client_new.php', []);
directory_ui_check($words($x, '//label[select[@name="sla_tier"]]/text()[normalize-space()]') === ['Plan']
    && $words($x, '//select[@name="sla_tier"]/option') === ['Standard', 'Premium']
    && $words($x, '//select[@name="sla_tier"]/option/@value') === ['standard', 'premium'], 'New client asks for the Plan in words');
// A technician cannot change the plan, and is told so in the same word.
$GLOBALS['fixtureUser']['role'] = 'tech';
$x = $formPage('client_edit.php', ['id' => '30']);
directory_ui_check($x->query('//select[@name="sla_tier"]')->length === 0
    && $words($x, '//div[contains(@class,"field")][span="Plan"]/strong') === ['Premium']
    && $words($x, '//div[contains(@class,"field")][span="Plan"]/span[contains(@class,"rail-note")]') === ['Only owners and admins can change the plan.'],
    'a technician sees the plan in words and who can change it');
$x = $formPage('client_new.php', []);
directory_ui_check($x->query('//select[@name="sla_tier"]')->length === 0
    && $words($x, '//div[contains(@class,"field")][span="Plan"]/strong') === ['Standard']
    && $words($x, '//div[contains(@class,"field")][span="Plan"]/span[contains(@class,"rail-note")]') === ['Only owners and admins can choose the plan.'],
    'a technician adding a client is told the plan and who can choose it');
$GLOBALS['fixtureUser']['role'] = 'admin';
directory_ui_check(directory_ui_tables() === $before, 'rendering the directory and client pages wrote nothing');
echo "PASS: $checks client shared directory renderer checks\n";
