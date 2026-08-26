<?php
/**
 * Safeharbor demo seed — wipes and reseeds the 8 West IT tenant.
 * CLI only:  php db/seed.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit('CLI only.');

require_once __DIR__ . '/../lib/bootstrap.php';

// This script TRUNCATEs tenants, policies, users, clients, contacts, tickets,
// messages, time_entries and their events. It ships to production with every release, and until now "CLI
// only" was the entire guard — so one mistyped command on the wrong host wiped
// the desk, silently and completely.
//
// demo_mode is absent from every config that already exists, so this fails
// closed everywhere until a host deliberately opts in.
if (! cfg('demo_mode', false)) {
    fwrite(STDERR, "Refusing to seed: demo_mode is not enabled on this host.\n"
        . "Seeding wipes every tenant, user, client, ticket and message in the database.\n"
        . "If this really is a sandbox, set 'demo_mode' => true in config/config.php.\n");
    exit(1);
}

$demoPassword = 'harbor'; // demo credential — shown on the login page in demo_mode

$pdo = db();
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ([
    'time_entry_events',
    'time_entries',
    'messages',
    'tickets',
    'contacts',
    'clients',
    'users',
    'service_goal_policy_targets',
    'service_goal_policy_versions',
    'tenants',
] as $t) {
    $pdo->exec("TRUNCATE TABLE $t");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

// Tenant ----------------------------------------------------------------
$pdo->exec("INSERT INTO tenants (id, name, slug, plan) VALUES (1, '8 West IT, LLC', '8west', 'suite')");
service_goal_ensure_default_policies($pdo, 1);

// Users -----------------------------------------------------------------
$hash = password_hash($demoPassword, PASSWORD_DEFAULT);
$users = [
    [1, 'frankie@8westit.com', 'Frankie Gonzalez', 'FG', '#2D8CFF', 'owner'],
    [2, 'ana@8westit.com',     'Ana Ruiz',         'AR', '#7DDCFF', 'tech'],
    [3, 'marcus@8westit.com',  'Marcus Tran',      'MT', '#F6C95B', 'tech'],
];
$u = $pdo->prepare('INSERT INTO users (id, tenant_id, email, password_hash, full_name, initials, color, role) VALUES (?,1,?,?,?,?,?,?)');
foreach ($users as [$id, $email, $name, $ini, $color, $role]) {
    $u->execute([$id, $email, $hash, $name, $ini, $color, $role]);
}

// Clients + contacts -----------------------------------------------------
$c = $pdo->prepare('INSERT INTO clients (id, tenant_id, name, domain, sla_tier, health) VALUES (?,1,?,?,?,?)');
$clients = [
    [1, 'Harbor Dental Group',   'harbordental.example',    'premium',  'good'],
    [2, 'Bluefin Realty',        'bluefinrealty.example',   'standard', 'watch'],
    [3, 'Copper Kettle Bakery',  'copperkettle.example',    'standard', 'good'],
    [4, 'Northwind Legal',       'northwindlegal.example',  'premium',  'good'],
    [5, 'Driftwood Marina',      'driftwoodmarina.example', 'standard', 'good'],
];
foreach ($clients as $row) $c->execute($row);

$k = $pdo->prepare('INSERT INTO contacts (id, client_id, name, email) VALUES (?,?,?,?)');
$contacts = [
    [1, 1, 'Dr. Priya Nair',  'pnair@harbordental.example'],
    [2, 1, 'Sam Whitfield',   'frontdesk@harbordental.example'],
    [3, 2, 'Dana Cole',       'dana@bluefinrealty.example'],
    [4, 3, 'Jo March',        'jo@copperkettle.example'],
    [5, 4, 'Alex Reyes',      'areyes@northwindlegal.example'],
    [6, 5, 'Riley Booth',     'riley@driftwoodmarina.example'],
];
foreach ($contacts as $row) $k->execute($row);

// Tickets ---------------------------------------------------------------
// Handcrafted demo history is normalized to the captured policy window.
$t = $pdo->prepare(
    'INSERT INTO tickets
        (id, tenant_id, client_id, contact_id, subject, status, priority, assignee_id, channel,
         sla_due_at, service_goal_target_id, created_at, updated_at, resolved_at)
     VALUES (?,1,?,?,?,?,?,?,?,?,?,?,?,?)'
);
$now = time();
$h = static fn(float $hrs): string => gmdate('Y-m-d H:i:s', (int)($now + $hrs * 3600));
$tickets = [
    [101, 1, 2, "Front desk PC won't print claim forms after update", 'open',        'urgent', null, 'email',  $h(+0.6),  $h(-1.2), $h(-0.4),  null],
    [102, 2, 3, 'Shared mailbox not syncing on two agent laptops',    'in_progress', 'high',   2,    'portal', $h(+1.5),  $h(-5),   $h(-1),    null],
    [103, 4, 5, 'MFA prompt loop for one attorney after phone swap',  'waiting',     'normal', 1,    'phone',  $h(+4),    $h(-8),   $h(-3),    null],
    [104, 3, 4, 'POS tablet loses Wi-Fi every morning around opening','open',        'high',   null, 'alert',  $h(+2),    $h(-2),   $h(-2),    null],
    [105, 5, 6, 'Add fuel dock camera to the office viewing PC',      'open',        'low',    3,    'portal', $h(+22),   $h(-26),  $h(-26),   null],
    [106, 1, 1, 'New hygienist starts Monday — accounts & operatory PC access', 'in_progress', 'normal', 1, 'portal', $h(+28), $h(-20), $h(-1), null],
    [107, 2, 3, "OneDrive 'processing changes' stuck on broker desktop", 'open',     'normal', null, 'email',  $h(+5),    $h(-3),   $h(-3),    null],
    [108, 4, 5, 'Quarterly phishing refresher — schedule for the firm', 'waiting',   'low',    2,    'email',  $h(+46),   $h(-50),  $h(-24),   null],
    [109, 3, 4, 'Recipe SharePoint library permissions cleanup',      'resolved',    'normal', 3,    'portal', $h(-10),   $h(-70),  $h(-10),   $h(-10)],
    [110, 5, 6, 'Marina office PC slow after hours — check scheduled tasks', 'open', 'normal', null, 'alert',  $h(+1.2),  $h(-6),   $h(-6),    null],
    [111, 1, 2, 'Operatory 3 imaging software license expires Friday', 'in_progress','high',   2,    'portal', $h(+30),   $h(-30),  $h(-2),    null],
    [112, 2, 3, 'Wi-Fi dead zone in the back conference room',        'open',        'low',    null, 'email',  $h(+15),   $h(-9),   $h(-9),    null],
    [113, 4, 5, 'Server backup alert: repository 87% full',           'open',        'urgent', 1,    'alert',  $h(+0.9),  $h(-1.5), $h(-1.5),  null],
    [114, 5, 6, 'Seasonal staff accounts — disable until April',      'resolved',    'low',    3,    'email',  $h(-40),   $h(-90),  $h(-40),   $h(-40)],
];
foreach ($tickets as $row) {
    $goal = service_goal_snapshot_for_new_ticket(
        $pdo,
        1,
        (int) $row[1],
        (string) $row[5],
        (string) $row[9],
    );
    $t->execute([
        $row[0],
        $row[1],
        $row[2],
        $row[3],
        $row[4],
        $row[5],
        $row[6],
        $row[7],
        $goal['due_at'],
        $goal['target_id'],
        $row[9],
        $row[10],
        $row[11],
    ]);
}

// Messages ---------------------------------------------------------------
$m = $pdo->prepare('INSERT INTO messages (ticket_id, author_name, kind, body, created_at) VALUES (?,?,?,?,?)');
$messages = [
    [101, 'Sam Whitfield', 'client', "Hi — since this morning's Windows update the front desk PC refuses to print claim forms. Regular documents print fine. Patients are checking out in 20 minutes, help!", $h(-1.2)],
    [101, 'Safeharbor', 'system', 'Ticket created from email · Response target: Premium v1 · due in 2 hours', $h(-1.18)],
    [102, 'Dana Cole', 'client', 'Listings@ is syncing on my desktop but not on the two new agent laptops. Both are on M365 Business Standard.', $h(-5)],
    [102, 'Ana Ruiz', 'tech', "Reproduced — automapping didn't apply. Adding both users explicitly and re-initializing Outlook profiles. I'll confirm within the hour.", $h(-2.5)],
    [102, 'Ana Ruiz', 'note', 'Internal: if this recurs, script it — third time this quarter for Bluefin.', $h(-1)],
    [103, 'Frankie Gonzalez', 'tech', 'Reset the registration and re-enrolled the new device. Waiting on Alex to confirm sign-in works from court Wi-Fi tomorrow.', $h(-3)],
    [109, 'Marcus Tran', 'tech', "Permissions trimmed to the two managers + read for staff. Sent the summary — marking resolved. Holler if anything's missing.", $h(-10)],
];
foreach ($messages as $row) $m->execute($row);

// Time entries ------------------------------------------------------------
// Use the same approval-grade service as production. Two explicit approvals
// keep Reports useful in the demo while the first row stays pending so the
// owner review queue has a real fixture.
$entries = [
    [102, 2, 35, 'Mailbox automapping fix + profile rebuild', $h(-2)],
    [106, 1, 20, 'Hygienist account provisioning',            $h(-1)],
    [109, 3, 45, 'SharePoint permission audit',               $h(-11)],
];
$seedTime = [];
foreach ($entries as $index => $row) {
    $workedAt = gmdate('Y-m-d\TH:i:s\Z', strtotime($row[4] . ' UTC'));
    $seedTime[] = time_entry_create($pdo, 1, (int)$row[1], [
        'ticket_id' => (int)$row[0],
        'entry_key' => 'seed:time:entry:' . (int)$row[0] . ':' . (int)$row[1],
        'source' => 'suggestion',
        'worked_at' => $workedAt,
        'minutes' => (int)$row[2],
        'note' => (string)$row[3],
        'billable' => true,
    ]);
}
time_entry_review($pdo, 1, 1, 'owner', (int)$seedTime[1]['id'], 'approved', 'Approved demo fixture.');
time_entry_review($pdo, 1, 1, 'owner', (int)$seedTime[2]['id'], 'approved', 'Approved demo fixture.');

echo "Seeded: 1 tenant, " . count($users) . " users, " . count($clients) . " clients, "
   . count($tickets) . " tickets, " . count($messages) . " messages, "
   . count($entries) . " time entries.\n";
echo "Demo sign-in: frankie@8westit.com / {$demoPassword}\n";
