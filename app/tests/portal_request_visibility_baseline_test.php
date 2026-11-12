<?php
/** Frozen before item 40: run unchanged against main and the paged-list implementation. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/../lib/portal_data.php';
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE clients (id INTEGER, tenant_id INTEGER, name TEXT);
CREATE TABLE tickets (id INTEGER PRIMARY KEY, tenant_id INTEGER, client_id INTEGER, subject TEXT,
 status TEXT, priority TEXT, created_at TEXT, updated_at TEXT, resolved_at TEXT, sla_due_at TEXT, merged_into_id INTEGER);
CREATE TABLE messages (id INTEGER PRIMARY KEY, ticket_id INTEGER, kind TEXT, body TEXT, created_at TEXT);
CREATE TABLE portal_ticket_requesters (ticket_id INTEGER, tenant_id INTEGER, client_id INTEGER, subject TEXT, identity_tenant_slug TEXT);');
$pdo->exec("INSERT INTO clients VALUES (11,1,'Example client'),(12,1,'Second client'),(21,2,'Other provider client')");
$insert = $pdo->prepare("INSERT INTO tickets VALUES (?, ?, ?, ?, ?, 'normal', '2026-01-01 00:00:00', '2026-01-01 00:00:00', NULL, NULL, ?)");
foreach ([[1,1,11,'Own request','open',null],[2,1,11,'Another member','open',null],
    [3,1,12,'Other client','open',null],[4,2,21,'Other provider','open',null],
    [5,1,11,'Unattributed request','open',null],[6,1,11,'Merged source','open',7],
    [7,1,11,'Merged destination','open',null],[8,1,11,'Own waiting request','waiting',null],
    [9,1,11,'Other identity issuer','open',null]] as $row) $insert->execute($row);
$pdo->exec("INSERT INTO portal_ticket_requesters VALUES
 (1,1,11,'t9u1','example-client'),(2,1,11,'t9u2','example-client'),
 (3,1,12,'t9u1','example-client'),(4,2,21,'t9u1','example-client'),
 (6,1,11,'t9u1','example-client'),(7,1,11,'t9u1','example-client'),
 (8,1,11,'t9u1','example-client'),(9,1,11,'t9u1','different-issuer')");
$pdo->exec("INSERT INTO messages VALUES (1,1,'client','PUBLIC BODY CANARY','2026-01-01 00:00:00')");
$checks = 0;
$check = static function (string $name, bool $ok) use (&$checks): void {
    $checks++;
    if (!$ok) throw new RuntimeException($name);
    echo "ok {$checks} - {$name}\n";
};
$actor = ['tenant_id'=>1,'client_id'=>11,'subject'=>'t9u1','identity_tenant_slug'=>'example-client'];
foreach (['client_owner','client_admin','client_staff','client_viewer'] as $role) {
    $identity = $actor + ['role'=>$role];
    $admin = in_array($role, ['client_owner','client_admin'], true);
    $before = portal_ticket_summary($pdo, 1, 11, 100, $identity);
    $check($role.' exact visible IDs preserve tenant, client, requester, issuer and both merge exclusions',
        array_map('intval', array_column($before['tickets'], 'id')) === ($admin ? [8,9,5,2,1] : [8,1]));
    $check($role.' counts cover only the same visible requests',
        $before['counts'] === ['open'=>$admin ? 4 : 1,'in_progress'=>0,'waiting'=>1,'resolved'=>0]);
    $pdo->exec("INSERT INTO messages(ticket_id,kind,body,created_at) VALUES (1,'note','PRIVATE NOTE CANARY','2030-01-01 00:00:00'),(1,'system','SYSTEM CANARY','2031-01-01 00:00:00')");
    $pdo->exec("UPDATE tickets SET updated_at='2031-01-01 00:00:00' WHERE id=1");
    $after = portal_ticket_summary($pdo, 1, 11, 100, $identity);
    $check($role.' internal note/system activity never changes IDs, ordering or counts',
        array_column($before['tickets'],'id') === array_column($after['tickets'],'id') && $before['counts'] === $after['counts']);
    $own = array_values(array_filter($after['tickets'], static fn(array $row): bool => (int)$row['id'] === 1))[0];
    $check($role.' latest public activity is unchanged', $own['last_message_kind'] === 'client' && $own['last_message_at'] === '2026-01-01 00:00:00');
    $check($role.' no message text is projected', !str_contains(json_encode($after, JSON_THROW_ON_ERROR), 'CANARY'));
}
foreach ([null, $actor + ['role'=>'tech'], ['client_id'=>12] + $actor + ['role'=>'client_owner']] as $identity) {
    try { portal_ticket_summary($pdo, 1, 11, 50, $identity); $refused = false; }
    catch (PortalDataForbiddenException) { $refused = true; }
    $check('missing or mismatched identity never widens visibility', $refused);
}
$delegated = $actor + ['role'=>'admin','customer_access'=>['role'=>'client_staff']];
$check('delegated member remains requester-only', array_map('intval', array_column(portal_ticket_summary($pdo, 1, 11, 100, $delegated)['tickets'],'id')) === [8,1]);
echo "{$checks} frozen visibility checks passed\n";
