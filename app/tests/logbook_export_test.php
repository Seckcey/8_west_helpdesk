<?php
declare(strict_types=1);

require_once __DIR__.'/../lib/logbook_export.php';
$checks = 0;
function check(bool $ok, string $name): void
{
    global $checks;
    if (!$ok) { throw new RuntimeException($name); }
    $checks++;
}
function refuses(callable $operation, string $name): void
{
    try { $operation(); } catch (RuntimeException|InvalidArgumentException) { check(true, $name); return; }
    check(false, $name);
}
$customer = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$other = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
$settings = logbook_export_settings([
    'secret' => str_repeat('a', 64), 'tenant_id' => 1, 'suite_tenant_id' => 19,
    'customer_ids' => [$customer],
]);
$body = '{"schema_version":1,"kind":"solved_tickets","cursor":0,"limit":200}';
$request = logbook_export_request($body);
$headers = ['secret' => $settings['secret'], 'timestamp' => '1800000000', 'nonce' => $other];
$base = 'POST'."\n".LOGBOOK_EXPORT_PATH."\n1800000000\n".$other."\n".hash('sha256', $body);
$headers['signature'] = hash_hmac('sha256', $base, $settings['secret']);
check(logbook_export_authenticated($settings, $headers, $body, 1800000000), 'existing Logbook signature accepted');
check(!logbook_export_authenticated($settings, $headers, $body.' ', 1800000000), 'changed raw body refused');
check(!logbook_export_authenticated($settings, $headers, $body, 1800000301), 'stale request refused');
check(!logbook_export_authenticated($settings, array_replace($headers, ['nonce' => $customer]), $body, 1800000000), 'nonce is signed');
check(!logbook_export_authenticated($settings, array_replace($headers, ['secret' => str_repeat('b', 64)]), $body, 1800000000), 'wrong service key refused');
refuses(fn() => logbook_export_settings(array_replace($settings, ['customer_ids' => ['not-a-customer-id']])), 'malformed customer mapping refused');
refuses(fn() => logbook_export_settings(array_replace($settings, ['tenant_id' => 0])), 'missing scope refused');
refuses(fn() => logbook_export_settings(array_replace($settings, ['customer_ids' => []])), 'empty scope refused');
foreach (['{"schema_version":1,"kind":"solved_tickets","cursor":0,"limit":201}',
    '{"schema_version":1,"kind":"solved_tickets","cursor":-1,"limit":200}',
    '{"schema_version":1,"kind":"solved_tickets","cursor":0,"limit":200,"tenant_id":2}',
    '{"schema_version":1,"kind":"all_tickets","cursor":0,"limit":200}', '[]', str_repeat('x', 16385)] as $bad) {
    refuses(fn() => logbook_export_request($bad), 'invalid request refused');
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE tenants (id INTEGER PRIMARY KEY, slug TEXT);
CREATE TABLE svc_identities (tenant_id INTEGER, service TEXT, is_active INTEGER);
CREATE TABLE clients (id INTEGER PRIMARY KEY, tenant_id INTEGER);
CREATE TABLE suite_customer_sync_bindings (tenant_id INTEGER, client_id INTEGER, customer_id TEXT, status TEXT);
CREATE TABLE tickets (id INTEGER PRIMARY KEY, tenant_id INTEGER, client_id INTEGER, subject TEXT, status TEXT, resolved_at TEXT, merged_into_id INTEGER);
CREATE TABLE messages (id INTEGER PRIMARY KEY, ticket_id INTEGER, kind TEXT, body TEXT, created_at TEXT);
INSERT INTO tenants VALUES (1,'our-workspace'),(2,'other-workspace');
INSERT INTO svc_identities VALUES (1,'logbook-export',1),(2,'logbook-export',1);
INSERT INTO clients VALUES (1,1),(2,1),(3,2);
INSERT INTO suite_customer_sync_bindings VALUES (1,1,'$customer','active'),(1,2,'$other','active'),(2,3,'$customer','active');");
$ticket = $pdo->prepare('INSERT INTO tickets VALUES (?,?,?,?,?,?,?)');
$message = $pdo->prepare('INSERT INTO messages VALUES (?,?,?,?,?)');
foreach ([[1,1,1,'Allowed'],[2,1,2,'Other customer'],[3,2,3,'Other tenant'],[4,1,1,'Internal only'],[5,1,1,'Merged'],[6,1,1,'Unresolved'],[7,1,1,'Second allowed']] as [$id,$tenant,$client,$title]) {
    $ticket->execute([$id,$tenant,$client,$title,$id===6?'open':'resolved','2026-09-05 08:00:00',$id===5?1:null]);
    $message->execute([$id,$id,$id===4?'note':'tech','Exact public reply '.$id,'2026-09-05 07:30:00']);
}
$message->execute([20,1,'tech','Latest public resolution candidate','2026-09-05 07:59:00']);
$message->execute([21,1,'note','Internal password should never be exported','2026-09-05 07:59:30']);
$message->execute([22,1,'tech','After resolution should never be exported','2026-09-05 08:01:00']);
$message->execute([23,1,'client','Customer words should never be exported','2026-09-05 07:59:45']);
$message->execute([24,1,'tech','Older message inserted later','2026-09-05 07:45:00']);
$pdo->exec('PRAGMA query_only=ON');
$page = logbook_export_page($pdo, $settings, $request);
check($page['suite_tenant_id'] === 19 && $page['tenant_slug'] === 'our-workspace', 'stable suite identity independent of local id');
check(array_column($page['items'], 'ticket_id') === ['1','7'], 'only approved resolved unmerged customer tickets exported');
check($page['items'][0]['resolution'] === 'Latest public resolution candidate', 'chronologically latest public technician reply quoted');
check($page['items'][0]['confidence'] === 'low', 'source inference remains low confidence');
check($page['items'][0]['resolved_at'] === '2026-09-05T08:00:00Z', 'resolution UTC timestamp retained');
check(!str_contains(json_encode($page), 'Internal password'), 'internal notes excluded');
check($page['next_cursor'] === null, 'last page terminates');
$first = logbook_export_page($pdo, $settings, array_replace($request,['limit'=>1]));
$second = logbook_export_page($pdo, $settings, array_replace($request,['limit'=>1,'cursor'=>$first['next_cursor']]));
check($first['next_cursor'] === 1 && array_column($second['items'],'ticket_id') === ['7'] && $second['next_cursor'] === null, 'keyset pagination has no duplicate');
$pdo->exec('PRAGMA query_only=OFF');
$pdo->exec("UPDATE suite_customer_sync_bindings SET status='inactive' WHERE tenant_id=1 AND client_id=1");
check(logbook_export_page($pdo,$settings,$request)['items'] === [], 'inactive customer excluded');
$pdo->exec("UPDATE svc_identities SET is_active=0 WHERE tenant_id=1");
refuses(fn() => logbook_export_page($pdo,$settings,$request), 'inactive service identity refused');
echo "PASS $checks Logbook export checks; all content reads ran with SQLite query_only enabled.\n";
