<?php
/** Service goals: real view helpers and read predicate, synthetic data and an injected clock. */
declare(strict_types=1);
function h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
require __DIR__ . '/../lib/service_goal_desk.php';
require __DIR__ . '/../lib/ticket_queue.php';

$now = strtotime('2026-11-11 18:00:00 UTC');
$at = static fn(int $seconds): string => gmdate('Y-m-d H:i:s', $now + $seconds);
$targets = static fn(int $minutes): array => array_map(static fn(string $p): array => ['priority' => $p, 'first_response_minutes' => $minutes], ['low', 'normal', 'high', 'urgent']);
$base = ['version_no' => 1, 'created_by_user_id' => null, 'created_by_name' => null, 'reason' => null,
    'effective_from' => '1970-01-01 00:00:00', 'state' => 'current'];
$history = ['policies' => [
    ['policy_key' => 'standard', 'display_name' => 'Standard', 'versions' => [array_merge($base, ['targets' => $targets(480)]),
        array_merge($base, ['version_no' => 2, 'state' => 'scheduled', 'effective_from' => '2026-12-01 18:00:00',
            'targets' => $targets(90), 'created_by_user_id' => 4, 'created_by_name' => 'Example <Owner>', 'reason' => 'A shorter <target>.'])]],
    ['policy_key' => 'premium', 'display_name' => 'Premium', 'versions' => [array_merge($base, ['targets' => $targets(120)])]],
]];
$ticket = ['id' => 1041, 'tenant_id' => 1, 'status' => 'open', 'created_at' => $at(-10000), 'sla_due_at' => $at(2400),
    'subject' => 'Front office printer is offline', 'client_name' => 'Example Studio', 'assignee_id' => null, 'assignee_name' => null,
    'first_response_at' => null, 'merged_into_id' => null, 'has_merged_sources' => 0];
$risk = ['total' => 3, 'rows' => [
    array_merge($ticket, ['id' => 1036, 'subject' => 'Computer cannot finish its backup', 'sla_due_at' => $at(-7200), 'assignee_id' => 4, 'assignee_name' => 'Example Technician']),
    $ticket,
    array_merge($ticket, ['id' => 1044, 'subject' => 'Review <script> on a very-long-computer-name-with-no-spaces-at-all', 'status' => 'in_progress', 'sla_due_at' => $at(6000), 'westy_owned' => true]),
]];
$html = service_goal_desk_html($history, null, $risk, 'America/Los_Angeles', $now);
$empty = ['policies' => array_map(static fn(array $p): array => array_replace($p, ['versions' => []]), $history['policies'])];
$fixtures = [
    'normal' => $html,
    'empty' => service_goal_desk_html($empty, null, ['total' => 0, 'rows' => []], 'UTC', $now),
    'unavailable' => service_goal_desk_html(null, 'Policy history is temporarily unavailable. No policy data was changed.', null, 'UTC', $now),
    'many' => service_goal_desk_html($history, null, ['total' => 63, 'rows' => array_fill(0, 10, $ticket)], 'UTC', $now),
];
if (in_array('--render', $argv, true)) { echo json_encode($fixtures, JSON_THROW_ON_ERROR); exit; }

$checks = 0;
$check = static function (bool $ok, string $name) use (&$checks): void {
    if (!$ok) throw new RuntimeException($name);
    $checks++;
};
$dom = static function (string $html): DOMXPath {
    $doc = new DOMDocument();
    $before = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8">' . $html);
    libxml_clear_errors(); libxml_use_internal_errors($before);
    return new DOMXPath($doc);
};
$x = $dom($html);
$texts = static function (DOMXPath $x, string $query): array {
    $out = []; foreach ($x->query($query) as $n) $out[] = trim($n->textContent); return $out;
};
$check($texts($x, '//table/tbody/tr/td') === ['8 h','8 h','8 h','8 h','2 h','2 h','2 h','2 h'], 'current targets only; scheduled targets do not leak into the current table');
$check(service_goal_target_short(90) === '1 h 30 min' && service_goal_target_short(1) === '1 min', 'configured durations never round or discard minutes');
$check($x->query('//details')->length === 1 && $x->query('//details[@open]')->length === 0, 'history is closed initially');
$check(count($texts($x, '//details/article')) === 3 && $x->query('//article[@data-policy-state="scheduled"]')->length === 1, 'all historical sets, including scheduled targets, remain available');
$check(!str_contains($html, '1970-01-01') && str_contains($html, 'In place since Safeharbor was set up.'), 'baseline has truthful human wording');
$check(str_contains($html, 'No approver or reason was recorded for the original targets.'), 'legacy attribution remains explicit');
$check($x->query('//script')->length === 0 && str_contains($html, 'Example &lt;Owner&gt;') && str_contains($html, '&lt;script&gt;'), 'publisher, reason and ticket text are escaped');
$check($texts($x, '//span[@class="goal-risk-owner"]') === ['Example Technician', 'Unassigned', 'Westy'], 'person, unassigned and service ownership are distinct');
$check($x->query('//a[@href="/?view=risk"]')->length === 1 && $x->query('//a[contains(@href, "&in=risk")]')->length === 3, 'queue and ticket links retain the risk context');
$check($x->query('//form|//button|//input')->length === 0, 'the presentation contains no write controls');
$check(str_contains($fixtures['empty'], 'Not set') && !str_contains($fixtures['empty'], '8 h'), 'no policy rows do not invent default targets');
$check(str_contains($fixtures['unavailable'], 'temporarily unavailable') && !str_contains($fixtures['unavailable'], 'At risk now <span>(0)'), 'failed reads never look empty');
$check(str_contains($fixtures['many'], 'Showing 10 of 63.'), 'a limited summary says how many remain in the queue');

// Exercise the actual prepared count with SQLite's equivalent UTC date expression. MySQL and HTTP
// also run in ticket_queue_http_test.php; this fixed clock pins the exact two-hour boundary.
class GoalDeskPdo extends PDO {
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return parent::prepare(str_replace('DATE_ADD(UTC_TIMESTAMP(), INTERVAL 7200 SECOND)', "datetime(UTC_TIMESTAMP(), '+7200 seconds')", $query), $options);
    }
}
$pdo = new GoalDeskPdo('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->sqliteCreateFunction('UTC_TIMESTAMP', static fn(): string => $at(0));
$pdo->exec('CREATE TABLE tickets (id INTEGER PRIMARY KEY, tenant_id INTEGER, status TEXT, created_at TEXT, sla_due_at TEXT, merged_into_id INTEGER)');
$pdo->exec('CREATE TABLE messages (ticket_id INTEGER, kind TEXT, created_at TEXT)');
$cases = [
    ['id'=>1, 'sla_due_at'=>$at(-60)], ['id'=>2, 'sla_due_at'=>$at(7199)], ['id'=>3, 'sla_due_at'=>$at(7200)],
    ['id'=>4, 'status'=>'waiting'], ['id'=>5, 'status'=>'resolved', 'resolved_at'=>$at(-30)],
    ['id'=>6, 'first_response_at'=>$at(-60)], ['id'=>7, 'first_response_at'=>$at(60)],
    ['id'=>8, 'first_response_at'=>$at(-20000)], ['id'=>9, 'sla_due_at'=>null],
    ['id'=>10, 'merged_into_id'=>11], ['id'=>11, 'has_merged_sources'=>1], ['id'=>12, 'tenant_id'=>2],
];
$insert = $pdo->prepare('INSERT INTO tickets VALUES (?,?,?,?,?,?)');
$message = $pdo->prepare("INSERT INTO messages VALUES (?, 'tech', ?)");
$expected = [];
foreach ($cases as $case) {
    $row = array_merge($ticket, $case);
    $insert->execute([$row['id'], $row['tenant_id'], $row['status'], $row['created_at'], $row['sla_due_at'], $row['merged_into_id']]);
    if ($row['first_response_at'] !== null) $message->execute([$row['id'], $row['first_response_at']]);
    $info = sla_info($row, $now);
    if ($row['tenant_id'] === 1 && $row['status'] !== 'resolved' && $row['sla_due_at'] !== null
        && in_array($info['state'], ['breached','at_risk'], true)) $expected[] = $row['id'];
}
$check($expected === [1,2,4,7,8], 'first-response rules include waiting, future and pre-open replies but exclude completed, missing and merged work');
$q = $pdo->prepare('SELECT t.id FROM tickets t WHERE t.tenant_id = ? AND (' . queue_view_sql('risk', 1) . ') ORDER BY t.id');
$q->execute([1]);
$check(array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN)) === $expected, 'risk SQL exactly matches the canonical lamp at the boundary');
$check(queue_risk_count($pdo, 1) === count($expected) && queue_risk_count($pdo, 2) === 1 && queue_risk_count($pdo, 3) === 0, 'prepared count isolates tenants, including an empty one');
$pdo->exec("INSERT INTO messages VALUES (1, 'tech', '2026-11-11 17:59:30')");
$check(queue_risk_count($pdo, 1) === 4, 'a first reply removes a ticket from the live count');

echo "Service goal desk: $checks checks passed.\n";
