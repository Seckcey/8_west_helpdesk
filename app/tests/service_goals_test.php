<?php
/** Hermetic first-response service-goal coverage; no server config required. */
declare(strict_types=1);

require_once __DIR__ . '/../lib/service_goals.php';

$failures = 0;
$checks = 0;

function goal_check(string $name, bool $condition): void
{
    global $failures, $checks;
    $checks++;
    if (! $condition) {
        $failures++;
        fwrite(STDERR, "FAIL: {$name}\n");
    }
}

$now = strtotime('2026-08-25 12:00:00 UTC');
$base = [
    'status' => 'open',
    'sla_due_at' => '2026-08-25 15:00:00',
    'first_response_at' => null,
];

goal_check('future response target is healthy', sla_info($base, $now)['state'] === 'healthy');
goal_check('near response target is at risk', sla_info([
    ...$base,
    'sla_due_at' => '2026-08-25 13:30:00',
], $now)['state'] === 'at_risk');
goal_check('elapsed unanswered target is breached', sla_info([
    ...$base,
    'sla_due_at' => '2026-08-25 11:00:00',
], $now) === ['state' => 'breached', 'label' => '1h 00m over']);
goal_check('target boundary is reported without inventing elapsed time', sla_info([
    ...$base,
    'sla_due_at' => '2026-08-25 12:00:00',
], $now) === ['state' => 'breached', 'label' => '0m over']);
goal_check('first response exactly at target is met', sla_info([
    ...$base,
    'first_response_at' => '2026-08-25 15:00:00',
], $now)['state'] === 'met');
goal_check('resolved ticket uses its first response, not resolution time', sla_info([
    ...$base,
    'status' => 'resolved',
    'first_response_at' => '2026-08-25 14:00:00',
    'resolved_at' => '2026-08-27 12:00:00',
], $now) === ['state' => 'met', 'label' => 'Response met']);
goal_check('late first response remains breached after resolution', sla_info([
    ...$base,
    'status' => 'resolved',
    'first_response_at' => '2026-08-25 16:15:00',
], $now) === ['state' => 'breached', 'label' => 'Response 1h 15m late']);
goal_check('resolved ticket without a technician response is not called met', sla_info([
    ...$base,
    'status' => 'resolved',
], $now) === ['state' => 'breached', 'label' => 'No response']);
goal_check('missing target fails visibly', sla_info([
    ...$base,
    'sla_due_at' => '',
], $now) === ['state' => 'breached', 'label' => 'Target missing']);
goal_check('merged source has a neutral unmeasured lamp', sla_info([
    ...$base,
    'merged_into_id' => 9,
], $now) === ['state' => 'excluded', 'label' => 'Merged history']);
goal_check('merge survivor has a neutral unmeasured lamp', sla_info([
    ...$base,
    'has_merged_sources' => 1,
], $now) === ['state' => 'excluded', 'label' => 'Merged history']);

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE tickets (
    id INTEGER PRIMARY KEY,
    tenant_id INTEGER NOT NULL,
    status TEXT NOT NULL,
    sla_due_at TEXT NOT NULL,
    created_at TEXT NOT NULL,
    merged_into_id INTEGER NULL
)');
$pdo->exec('CREATE TABLE messages (
    id INTEGER PRIMARY KEY,
    ticket_id INTEGER NOT NULL,
    kind TEXT NOT NULL,
    created_at TEXT NOT NULL
)');

$ticket = $pdo->prepare('INSERT INTO tickets (id, tenant_id, status, sla_due_at, created_at, merged_into_id) VALUES (?,?,?,?,?,?)');
$message = $pdo->prepare('INSERT INTO messages (ticket_id, kind, created_at) VALUES (?,?,?)');
$ticket->execute([1, 1, 'in_progress', '2026-08-25 11:00:00', '2026-08-24 10:00:00', null]);
$message->execute([1, 'tech', '2026-08-25 10:30:00']); // met
$ticket->execute([2, 1, 'in_progress', '2026-08-25 10:00:00', '2026-08-24 10:00:00', null]);
$message->execute([2, 'note', '2026-08-25 09:00:00']); // notes are not responses
$message->execute([2, 'tech', '2026-08-25 10:30:00']); // missed
$ticket->execute([3, 1, 'resolved', '2026-08-25 15:00:00', '2026-08-24 10:00:00', null]); // no response: missed
$ticket->execute([4, 1, 'open', '2026-08-25 11:00:00', '2026-08-24 10:00:00', null]); // elapsed: missed
$ticket->execute([5, 1, 'open', '2026-08-25 15:00:00', '2026-08-24 10:00:00', null]); // pending: excluded
$ticket->execute([6, 1, 'resolved', '2026-08-01 11:00:00', '2026-07-20 10:00:00', null]); // outside period
$message->execute([6, 'tech', '2026-08-01 10:00:00']);
$ticket->execute([7, 2, 'resolved', '2026-08-25 11:00:00', '2026-08-24 10:00:00', null]);
$message->execute([7, 'tech', '2026-08-25 10:00:00']); // another tenant
$ticket->execute([8, 1, 'resolved', '2026-08-25 11:00:00', '2026-08-24 10:00:00', 9]); // merged source
$ticket->execute([9, 1, 'resolved', '2026-08-25 11:00:00', '2026-08-24 10:00:00', null]); // merge survivor
$message->execute([9, 'tech', '2026-08-25 10:00:00']); // provenance was destroyed by merge
$ticket->execute([10, 2, 'resolved', '2026-08-25 11:00:00', '2026-08-24 10:00:00', 1]); // corrupt cross-tenant pointer

$attainment = service_goal_response_attainment($pdo, 1, 30, $now);
goal_check('attainment counts only decided in-period tenant outcomes', $attainment['n'] === 4);
goal_check('attainment counts first response met, not resolution time', $attainment['met'] === 1);
goal_check('attainment percentage is derived from decided outcomes', $attainment['pct'] === 25);
goal_check('merged source and survivor stay out of attainment', $attainment['n'] === 4);
goal_check('another tenant cannot suppress an outcome through a merge pointer', $attainment['met'] === 1);

$ticketSource = (string) file_get_contents(__DIR__ . '/../public/ticket.php');
$appJsSource = (string) file_get_contents(__DIR__ . '/../public/assets/js/app.js');
goal_check('ticket detail exposes a response-lamp repaint target', str_contains($ticketSource, 'data-sla'));
goal_check('ticket actions repaint the detail response lamp', str_contains($appJsSource, '$$("[data-sla]", scope)'));

fwrite(STDOUT, "service_goals_test: {$checks} checks, {$failures} failures\n");
exit($failures === 0 ? 0 : 1);
