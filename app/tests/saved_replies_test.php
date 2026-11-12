<?php
/** Execute the actual saved-reply controller against disposable in-memory SQLite rows. No network. */
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

final class SavedReplyRedirect extends RuntimeException {}
final class SavedReplyCsrf extends RuntimeException {}
function require_login(): array { return $GLOBALS['replyUser']; }
function enforce_https(): void {}
function tenant_id(): int { return (int)$GLOBALS['replyUser']['tenant_id']; }
function csrf_token(): string { return 'synthetic-csrf'; }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="synthetic-csrf">'; }
function csrf_check(): void { if (($_POST['csrf'] ?? '') !== csrf_token()) throw new SavedReplyCsrf(); }
function cfg(string $key, mixed $default = null): mixed { return $default; }
function suite_sso_claims(): ?array { return null; }
function palette_data(): array { return []; }
if (!function_exists('h')) {
    function h(?string $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
}
function db(): PDO { return $GLOBALS['replyDb']; }

function reply_lift(string $source, string $name): string
{
    $at = strpos($source, "function $name(");
    if ($at === false) throw new RuntimeException("Missing $name");
    $open = strpos($source, '{', $at);
    $depth = 0;
    for ($i = $open, $n = strlen($source); $i < $n; $i++) {
        if ($source[$i] === '{') $depth++;
        elseif ($source[$i] === '}' && --$depth === 0) break;
    }
    return substr($source, $at, $i - $at + 1);
}
$render = (string)file_get_contents(__DIR__ . '/../lib/render.php');
eval(reply_lift((string)file_get_contents(__DIR__ . '/../lib/bootstrap.php'), 'utf8_clean'));
$chrome = '';
foreach (['suite_chrome_drawer', 'suite_chrome_account_menu', 'page_top', 'page_bottom'] as $function) $chrome .= reply_lift($render, $function) . "\n";
foreach (['suite_apps.php', 'service_goal_policy_admin.php', 'ticket_queue.php'] as $library) {
    $chrome = str_replace("__DIR__ . '/$library'", var_export(__DIR__ . '/../lib/' . $library, true), $chrome);
}
eval($chrome);

function reply_reset(string $role = 'tech'): void
{
    $GLOBALS['replyUser'] = ['id' => 7, 'tenant_id' => 1, 'role' => $role, 'is_active' => 1,
        'suite_subject' => 'synthetic-user', 'full_name' => 'Example Teammate', 'email' => 'teammate@example.test',
        'initials' => 'ET', 'color' => '#1463E0'];
    $_SESSION = ['suite_theme' => 'light', 'suite_products' => ['safeharbor'], 'suite_name' => 'Example Teammate'];
    $GLOBALS['replyDb'] = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    db()->exec('CREATE TABLE tenants(id INTEGER PRIMARY KEY, slug TEXT);
        CREATE TABLE users(id INTEGER PRIMARY KEY, tenant_id INTEGER, full_name TEXT);
        CREATE TABLE canned_responses(id INTEGER PRIMARY KEY, tenant_id INTEGER, title TEXT, body TEXT, created_by INTEGER);
        CREATE TABLE tickets(id INTEGER PRIMARY KEY, tenant_id INTEGER, status TEXT, sla_due_at TEXT, created_at TEXT, merged_into_id INTEGER, assignee_id INTEGER);
        CREATE TABLE messages(id INTEGER PRIMARY KEY, ticket_id INTEGER, kind TEXT, created_at TEXT);
        INSERT INTO tenants VALUES(1,"example");
        INSERT INTO users VALUES(7,1,"Example Teammate"),(8,1,"Another Teammate"),(9,2,"Outside Teammate");');
    $insert = db()->prepare('INSERT INTO canned_responses VALUES(?,?,?,?,?)');
    foreach ([
        [11, 1, 'Connection check', "Hi {contact.first_name},\nPlease check the cable at {client.name}.\nTicket #{ticket.id}\n{tech.first_name}", 7],
        [12, 1, 'Printer steps', "Restart the printer.\nThen try the print job again.", 8],
        [13, 2, 'Foreign reply', 'Outside body', 9],
        [14, 1, 'Quoted <title>', '</script><img src=x onerror="window.replyInjected=1"> & "quotes"', 7],
    ] as $row) $insert->execute($row);
}

function reply_page(array $get = [], ?array $post = null): array
{
    $_GET = $get; $_POST = $post ?? [];
    $_SERVER['REQUEST_METHOD'] = $post === null ? 'GET' : 'POST';
    $_SERVER['QUERY_STRING'] = http_build_query($get);
    $source = (string)file_get_contents(__DIR__ . '/../public/snippets.php');
    $source = preg_replace('/^require_once .*;\R/m', '', $source);
    // Preserve every statement and predicate, replacing only the controller's process exit.
    $source = str_replace("header('Location: /snippets.php');\n            exit;", 'throw new SavedReplyRedirect();', str_replace("\r\n", "\n", $source));
    $source = str_replace("header('Location: /snippets.php');\n        exit;", 'throw new SavedReplyRedirect();', $source);
    $source = str_replace("header('Location: /snippets.php');\n                exit;", 'throw new SavedReplyRedirect();', $source);
    ob_start();
    try { eval(substr($source, 5)); return ['html' => (string)ob_get_contents(), 'result' => 'render']; }
    catch (SavedReplyRedirect) { return ['html' => '', 'result' => 'redirect']; }
    catch (SavedReplyCsrf) { return ['html' => '', 'result' => 'csrf']; }
    finally { ob_end_clean(); }
}
function reply_row(int $id): array|false
{
    $statement = db()->prepare('SELECT * FROM canned_responses WHERE id=?'); $statement->execute([$id]); return $statement->fetch();
}
$checks = 0;
function reply_check(bool $ok, string $message): void
{
    $GLOBALS['checks']++;
    if (!$ok) throw new RuntimeException($message);
}
function reply_xpath(string $html): DOMXPath
{
    $doc = new DOMDocument(); libxml_use_internal_errors(true); $doc->loadHTML('<?xml encoding="UTF-8">' . $html); libxml_clear_errors(); return new DOMXPath($doc);
}

$edit = ['csrf' => 'synthetic-csrf', 'action' => 'edit', 'snippet_id' => 11, 'title' => 'Updated title', 'body' => 'Updated body', 'tenant_id' => 2, 'created_by' => 99, 'role' => 'owner'];
foreach (['tech', 'viewer', 'admin', 'owner'] as $role) {
    foreach ([11, 12, 13, 999, 0] as $id) {
        reply_reset($role); $before = reply_row($id);
        $result = reply_page([], array_replace($edit, ['snippet_id' => $id]));
        $allowed = $id === 11 || ($id === 12 && in_array($role, ['admin', 'owner'], true));
        $after = reply_row($id);
        reply_check($allowed ? $result['result'] === 'redirect' : $result['result'] === 'render' && str_contains($result['html'], 'That saved reply is not available to edit.'), "$role/$id reports the same availability as GET edit");
        reply_check($allowed ? $after['title'] === 'Updated title' && $after['body'] === 'Updated body' : $after === $before, "$role/$id write boundary");
        if ($allowed) reply_check($after['created_by'] === $before['created_by'] && $after['tenant_id'] === 1, "$role/$id preserves author and workspace");
        reply_check((int)db()->query('SELECT COUNT(*) FROM canned_responses')->fetchColumn() === 4, "$role/$id does not add a reply");
    }
    reply_reset($role); $html = reply_page()['html']; $xp = reply_xpath($html);
    reply_check($xp->query('//*[@data-reply-edit="11"]')->length === 1, "$role can edit own");
    reply_check($xp->query('//*[@data-reply-edit="12"]')->length === (in_array($role, ['owner', 'admin'], true) ? 1 : 0), "$role other-author UI matches write");
    reply_check(!str_contains($html, 'Outside body') && !str_contains($html, 'Foreign reply'), "$role foreign reply never rendered");
    reply_check(str_contains($html, 'Printer steps'), "$role can still view team replies");
}
foreach (['tech', 'viewer', 'admin', 'owner'] as $role) {
    reply_reset($role); $unchanged = reply_row(11);
    $result = reply_page([], array_replace($edit, ['title' => $unchanged['title'], 'body' => $unchanged['body']]));
    reply_check($result['result'] === 'redirect' && reply_row(11) === $unchanged, "$role unchanged save succeeds");
    $opened = reply_page(['edit' => 11]);
    reply_check(reply_xpath($opened['html'])->query('//*[@id="saved-reply-editor"][@hidden]')->length === 0, "$role initially opens available reply");
    db()->exec('DELETE FROM canned_responses WHERE id=11');
    $result = reply_page([], $edit);
    reply_check($result['result'] === 'render' && str_contains($result['html'], 'That saved reply is not available to edit.'), "$role concurrent deletion shows existing availability message");
    reply_check(reply_row(11) === false, "$role edit never recreates deleted reply");
}
reply_reset(); $before = reply_row(11);
foreach (['', 'wrong'] as $csrf) {
    reply_check(reply_page([], array_replace($edit, ['csrf' => $csrf]))['result'] === 'csrf', 'Edit requires CSRF');
    reply_check(reply_row(11) === $before, 'Bad CSRF cannot write');
}
foreach ([['title' => ''], ['body' => ''], ['title' => str_repeat('x', 81)]] as $bad) {
    $result = reply_page([], array_replace($edit, $bad));
    reply_check($result['result'] === 'render' && str_contains($result['html'], 'role="alert"'), 'Invalid edit renders an error');
    reply_check(reply_row(11) === $before, 'Invalid edit cannot write');
    reply_check(reply_xpath($result['html'])->query('//*[@id="saved-reply-editor"][@hidden]')->length === 0, 'Invalid edit keeps editor open');
}
reply_page([], array_replace($edit, ['title' => ' Résumé ', 'body' => " Keep café\nline two " ]));
reply_check(reply_row(11)['title'] === 'Résumé' && reply_row(11)['body'] === "Keep café\nline two", 'Edit trims and keeps Unicode/newlines');

// Existing add/delete behavior remains exercised through the same controller.
foreach (['tech', 'admin', 'owner'] as $role) {
    reply_reset($role);
    reply_check(reply_page([], ['csrf' => csrf_token(), 'action' => 'add', 'title' => 'New reply', 'body' => 'New text'])['result'] === 'redirect', 'Existing add works');
    $added = db()->query("SELECT * FROM canned_responses WHERE title='New reply'")->fetch();
    reply_check($added['tenant_id'] === 1 && $added['created_by'] === 7, 'Add keeps signed-in workspace/author');
    foreach ([11, 12, 13] as $id) {
        $before = reply_row($id); reply_page([], ['csrf' => csrf_token(), 'action' => 'delete', 'snippet_id' => $id]);
        $allowed = $id === 11 || ($id === 12 && $role !== 'tech');
        reply_check($allowed ? reply_row($id) === false : reply_row($id) === $before, 'Existing delete boundary preserved');
    }
}

$out = getenv('SAVED_REPLIES_CAPTURE_DIR');
foreach (['library', 'new', 'edit', 'error', 'empty', 'tech', 'forbidden', 'preview-library', 'preview-edit'] as $state) {
    reply_reset($state === 'tech' || $state === 'forbidden' ? 'tech' : 'admin');
    if ($state === 'empty') db()->exec('DELETE FROM canned_responses WHERE tenant_id=1');
    if (str_starts_with($state, 'preview-')) db()->exec('DELETE FROM canned_responses WHERE id=14');
    $get = in_array($state, ['edit', 'preview-edit'], true) ? ['edit' => 11] : ($state === 'new' ? ['new' => 1] : ($state === 'forbidden' ? ['edit' => 12] : []));
    $html = reply_page($get, $state === 'error' ? array_replace($edit, ['title' => '', 'body' => 'Unsaved draft']) : null)['html'];
    $xp = reply_xpath($html);
    reply_check($xp->query('//*[@id="saved-replies-data"]')->length === 1, "$state has one safe JSON island");
    reply_check($xp->query('//img[@src="x"]')->length === 0, "$state stored content cannot inject markup");
    if ($state === 'forbidden') reply_check($xp->query('//*[@id="saved-reply-editor"][@hidden]')->length === 1, 'Foreign-author GET does not open edit');
    if ($state === 'error') reply_check(str_contains($html, 'Unsaved draft'), 'Invalid edit preserves submitted text');
    if ($state === 'edit') reply_check($xp->query('//input[@name="snippet_id"][@value="11"]')->length >= 1, 'Direct edit link selects intended reply');
    if (is_string($out) && is_dir($out)) file_put_contents("$out/$state.html", $html);
}
echo "PASS: $checks saved reply controller checks\n";
