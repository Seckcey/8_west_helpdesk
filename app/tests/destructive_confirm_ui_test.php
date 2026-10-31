<?php
/**
 * CLI-only synthetic renderer for the four staff forms that ask before they remove something (SH-33):
 * Remove contact (client.php), Delete client (client_edit.php), Remove demo data (clients.php) and
 * Delete saved reply (snippets.php).
 *
 *   php app/tests/destructive_confirm_ui_test.php
 *
 * It evaluates the actual page sources and the actual page chrome lifted from lib/render.php against
 * an in-memory SQLite copy of the tables those pages read. The client and contact names carry
 * apostrophes, quotes, a backslash and script-shaped text on purpose: an inbound email's sender name
 * becomes a contact name. Nothing is written anywhere except the HTML snapshots in
 * CONFIRM_UI_CAPTURE_DIR for tools/shots/destructive-confirm.test.mjs, which presses the buttons.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
error_reporting(E_ALL);
set_error_handler(static function (int $level, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $level, $file, $line);
});

require_once __DIR__ . '/../lib/client_operational_context.php';
require_once __DIR__ . '/../lib/service_goal_policy_admin.php';
require_once __DIR__ . '/../lib/suite_preferences.php';
require_once __DIR__ . '/../lib/settings_context.php';
require_once __DIR__ . '/../lib/westy.php';

function confirm_ui_check(bool $ok, string $label): void
{
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
}

/** Lift one top-level function's source out of render.php by brace matching (no bootstrap needed). */
function confirm_ui_lift(string $source, string $name): string
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
function csrf_check(): void { throw new RuntimeException('The confirm fixture renders GET requests only.'); }
function cfg(string $key, mixed $default = null): mixed { return $default; }
function suite_sso_claims(): ?array { return null; }
function palette_data(): array { return []; }
function westy_workflow_ticket_owners(PDO $pdo, int $tenantId, array $rows): array { return $rows; }
function ticket_row(array $t, bool $linkWrap = true): string { throw new RuntimeException('The confirm fixture has no tickets.'); }

/** The pages' SQL, with SQLite spellings for the few MySQL-only expressions client.php uses. */
final class ConfirmFixturePdo extends PDO
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
function db(): ConfirmFixturePdo
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new ConfirmFixturePdo('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

$render = (string)file_get_contents(__DIR__ . '/../lib/render.php');
$chrome = '';
foreach (['suite_chrome_drawer', 'suite_chrome_account_menu', 'page_top', 'page_bottom'] as $function) {
    $chrome .= confirm_ui_lift($render, $function) . "\n";
}
// The lifted code resolves __DIR__ relative to THIS file, so point its require at lib/ explicitly.
eval(str_replace("__DIR__ . '/suite_apps.php'", var_export(__DIR__ . '/../lib/suite_apps.php', true), $chrome));

db()->exec("CREATE TABLE tenants(id INTEGER PRIMARY KEY, slug TEXT);
CREATE TABLE users(id INTEGER PRIMARY KEY, tenant_id INTEGER, suite_subject TEXT, is_active INTEGER, full_name TEXT, initials TEXT, color TEXT);
CREATE TABLE suite_managed_providers(tenant_id INTEGER PRIMARY KEY, issuer_tenant_id INTEGER, provider_slug TEXT);
CREATE TABLE clients(id INTEGER PRIMARY KEY, tenant_id INTEGER, name TEXT, domain TEXT, health TEXT, sla_tier TEXT, notes TEXT);
CREATE TABLE contacts(id INTEGER PRIMARY KEY, client_id INTEGER, name TEXT, email TEXT);
CREATE TABLE tickets(id INTEGER PRIMARY KEY, tenant_id INTEGER, client_id INTEGER, status TEXT, priority TEXT, sla_due_at TEXT, created_at TEXT, assignee_id INTEGER, merged_into_id INTEGER);
CREATE TABLE messages(id INTEGER PRIMARY KEY, ticket_id INTEGER, kind TEXT, created_at TEXT);
CREATE TABLE suite_customer_sync_bindings(id INTEGER PRIMARY KEY, tenant_id INTEGER, client_id INTEGER, customer_id TEXT, source_version INTEGER, display_name TEXT, status TEXT, last_event_id TEXT, last_occurred_at TEXT);
CREATE TABLE suite_customer_sync_events(id INTEGER PRIMARY KEY, binding_id INTEGER, tenant_id INTEGER, client_id INTEGER, customer_id TEXT, source_version INTEGER, display_name TEXT, status TEXT, event_id TEXT, occurred_at TEXT);
CREATE TABLE canned_responses(id INTEGER PRIMARY KEY, tenant_id INTEGER, title TEXT, body TEXT, created_by INTEGER);
INSERT INTO tenants VALUES(1,'8west');
INSERT INTO users VALUES(5,1,'t70u5',1,'Synthetic Technician','ST','#2D8CFF');
INSERT INTO clients VALUES(50,1,'O''Neil''s Dental','oneils.test','good','standard',''),
 (51,1,'Harbor Dental','harbordental.example','good','standard','');
INSERT INTO canned_responses VALUES(71,1,'Printer reset','Turn it off and on again.',5);");
$contacts = db()->prepare('INSERT INTO contacts VALUES(?,?,?,?)');
foreach ([[61, "Pat O'Brien"], [62, "Sam'+(window.sh33=1)+'"], [63, 'Jo "JJ" Lee \\ Ops']] as [$id, $name]) {
    $contacts->execute([$id, 50, $name, "contact$id@oneils.test"]);
}

/** Evaluate one actual page source in its own scope and return its HTML. */
function confirm_ui_page(string $page, array $get): string
{
    $source = (string)file_get_contents(__DIR__ . '/../public/' . $page);
    $source = preg_replace('/^require_once .*;\R/m', '', $source);
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

$GLOBALS['fixtureUser'] = ['id' => 5, 'tenant_id' => 1, 'role' => 'admin', 'is_active' => 1, 'suite_subject' => 't70u5',
    'full_name' => 'Synthetic Technician', 'email' => 'synthetic@example.test', 'initials' => 'ST', 'color' => '#2D8CFF'];
$_SESSION = ['suite_theme' => 'light', 'suite_products' => ['safeharbor', 'milepost', 'coastmark'],
    'suite_name' => 'Synthetic Technician', 'suite_email' => 'synthetic@example.test'];
$out = getenv('CONFIRM_UI_CAPTURE_DIR');
foreach ([
    ['client.php', ['id' => '50'], 'client', "<title>O&#039;Neil&#039;s Dental · Safeharbor</title>", 3],
    ['client_edit.php', ['id' => '50'], 'client-edit', "<title>Edit O&#039;Neil&#039;s Dental · Safeharbor</title>", 1],
    ['clients.php', [], 'clients', '<title>Clients · Safeharbor</title>', 1],
    ['snippets.php', [], 'snippets', '<title>Saved replies · Safeharbor</title>', 1],
] as [$page, $get, $name, $title, $forms]) {
    $html = confirm_ui_page($page, $get);
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8">' . $html);
    libxml_clear_errors();
    $posting = (new DOMXPath($doc))->query('//form[@method="post"][.//button[@type="submit"][contains(@class,"btn-link") or contains(@class,"btn-danger")]]');
    confirm_ui_check(str_contains($html, $title) && $posting->length >= $forms, "$page renders with its removal form");
    if (is_string($out) && is_dir($out)) file_put_contents("$out/$name.html", $html);
}
echo "PASS: rendered the four removal forms\n";
